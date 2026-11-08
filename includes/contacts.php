<?php
/**
 * Project Exodia — Contacts & User Discovery Service
 * 
 * Provides database operations for user discovery and contact management:
 *  - Searching registered users with duplicate and self exclusion
 *  - Adding contacts with validation and constraint enforcement
 *  - Removing contacts with ownership authorization
 *  - Retrieving contact lists with user profiles
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/validator.php';

/**
 * Searches registered users by username substring.
 *
 * Excludes the currently authenticated user.
 * Returns safe profile fields (id, username, created_at, is_contact).
 *
 * @param PDO $pdo Active PDO connection
 * @param int $currentUserId User ID to exclude
 * @param string $query Username search query
 * @param int $limit Maximum results to return (capped at 50)
 * @return array List of matching users
 */
function search_users(PDO $pdo, int $currentUserId, string $query, int $limit = 20): array {
    $trimmed = trim($query);
    if ($trimmed === '') {
        return [];
    }

    $limit = max(1, min(50, $limit));

    // Wildcard query matching anywhere in username
    $likePattern = '%' . $trimmed . '%';

    $sql = '
        SELECT 
            u.id,
            u.username,
            u.created_at,
            (c.id IS NOT NULL) AS is_contact
        FROM users u
        LEFT JOIN contacts c 
            ON c.user_id = :current_user_id 
            AND c.contact_id = u.id
        WHERE u.id != :exclude_user_id
          AND u.username LIKE :search_pattern
        ORDER BY u.username ASC
        LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
    $stmt->bindValue(':exclude_user_id', $currentUserId, PDO::PARAM_INT);
    $stmt->bindValue(':search_pattern', $likePattern, PDO::PARAM_STR);
    $stmt->execute();

    $results = [];
    while ($row = $stmt->fetch()) {
        $results[] = [
            'id'         => (int)$row['id'],
            'username'   => $row['username'],
            'created_at' => $row['created_at'],
            'is_contact' => (bool)$row['is_contact'],
        ];
    }

    return $results;
}

/**
 * Adds a registered user to the current user's contact list.
 *
 * Enforces business logic:
 *  - User cannot add themselves
 *  - Target contact must exist in the database
 *  - Duplicate contacts are rejected safely
 *
 * @param PDO $pdo Active PDO connection
 * @param int $userId Authenticated user ID
 * @param int $contactId Target user ID to add
 * @return array ['success' => bool, 'message' => string, 'code' => string]
 */
function add_contact(PDO $pdo, int $userId, int $contactId): array {
    if ($userId <= 0 || $contactId <= 0) {
        return ['success' => false, 'message' => 'Invalid user identifier.', 'code' => 'INVALID_ID'];
    }

    if ($userId === $contactId) {
        return ['success' => false, 'message' => 'You cannot add yourself as a contact.', 'code' => 'SELF_CONTACT'];
    }

    // Verify target user exists
    $targetStmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
    $targetStmt->bindValue(':id', $contactId, PDO::PARAM_INT);
    $targetStmt->execute();
    $targetUser = $targetStmt->fetch();

    if (!$targetUser) {
        return ['success' => false, 'message' => 'The requested user was not found.', 'code' => 'NOT_FOUND'];
    }

    // Check if relationship already exists
    $checkStmt = $pdo->prepare('SELECT id FROM contacts WHERE user_id = :user_id AND contact_id = :contact_id LIMIT 1');
    $checkStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $checkStmt->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
    $checkStmt->execute();

    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'This user is already in your contacts.', 'code' => 'DUPLICATE_CONTACT'];
    }

    try {
        $insertStmt = $pdo->prepare('INSERT INTO contacts (user_id, contact_id) VALUES (:user_id, :contact_id)');
        $insertStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $insertStmt->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $insertStmt->execute();

        return [
            'success' => true,
            'message' => 'Contact added successfully.',
            'code'    => 'OK',
            'contact' => [
                'id'       => $contactId,
                'username' => $targetUser['username'],
            ],
        ];
    } catch (PDOException $e) {
        // Handle race conditions or unique constraint violations
        if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
            return ['success' => false, 'message' => 'This user is already in your contacts.', 'code' => 'DUPLICATE_CONTACT'];
        }
        error_log('Add contact database error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add contact due to a database error.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Removes a contact from the current user's contact list.
 *
 * Ensures the authenticated user owns the contact record being removed.
 *
 * @param PDO $pdo Active PDO connection
 * @param int $userId Authenticated user ID
 * @param int $contactId Target user ID to remove
 * @return array ['success' => bool, 'message' => string, 'code' => string]
 */
function remove_contact(PDO $pdo, int $userId, int $contactId): array {
    if ($userId <= 0 || $contactId <= 0) {
        return ['success' => false, 'message' => 'Invalid user identifier.', 'code' => 'INVALID_ID'];
    }

    $deleteStmt = $pdo->prepare('DELETE FROM contacts WHERE user_id = :user_id AND contact_id = :contact_id');
    $deleteStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $deleteStmt->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'Contact not found or already removed.', 'code' => 'NOT_FOUND'];
    }

    return ['success' => true, 'message' => 'Contact removed successfully.', 'code' => 'OK'];
}

/**
 * Retrieves the list of all contacts for a given user.
 *
 * @param PDO $pdo Active PDO connection
 * @param int $userId Authenticated user ID
 * @return array List of contacts with user details
 */
function get_user_contacts(PDO $pdo, int $userId): array {
    $sql = '
        SELECT 
            c.id AS relationship_id,
            c.created_at AS added_at,
            u.id AS user_id,
            u.username,
            u.email,
            u.created_at AS member_since
        FROM contacts c
        JOIN users u ON u.id = c.contact_id
        WHERE c.user_id = :user_id
        ORDER BY u.username ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $contacts = [];
    while ($row = $stmt->fetch()) {
        $contacts[] = [
            'relationship_id' => (int)$row['relationship_id'],
            'user_id'         => (int)$row['user_id'],
            'username'        => $row['username'],
            'email'           => $row['email'],
            'member_since'    => $row['member_since'],
            'added_at'        => $row['added_at'],
        ];
    }

    return $contacts;
}

/**
 * Checks whether a specific user is in another user's contact list.
 *
 * @param PDO $pdo Active PDO connection
 * @param int $userId Current user ID
 * @param int $contactId Candidate contact ID
 * @return bool True if contact exists, false otherwise
 */
function is_contact(PDO $pdo, int $userId, int $contactId): bool {
    $stmt = $pdo->prepare('SELECT id FROM contacts WHERE user_id = :user_id AND contact_id = :contact_id LIMIT 1');
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool)$stmt->fetch();
}
