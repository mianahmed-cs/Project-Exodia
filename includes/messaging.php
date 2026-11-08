<?php
/**
 * Project Exodia — Messaging & Conversation Service
 *
 * Core business logic for the direct messaging system:
 *  - Creating one-to-one conversations (with duplicate prevention)
 *  - Retrieving user conversation lists with previews
 *  - Sending messages with validation and authorization
 *  - Retrieving conversation history
 *  - Polling for new messages since a given ID
 *  - Authorization checks for conversation access
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Find an existing 1-to-1 conversation between two users, or return null.
 */
function find_direct_conversation(PDO $pdo, int $userA, int $userB): ?int {
    $sql = '
        SELECT cp1.conversation_id
        FROM conversation_participants cp1
        JOIN conversation_participants cp2
          ON cp1.conversation_id = cp2.conversation_id
        WHERE cp1.user_id = :userA
          AND cp2.user_id = :userB
        LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':userA', $userA, PDO::PARAM_INT);
    $stmt->bindValue(':userB', $userB, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ? (int)$row['conversation_id'] : null;
}

/**
 * Create a new 1-to-1 conversation between two users.
 * Returns existing conversation if one already exists.
 */
function create_conversation(PDO $pdo, int $currentUserId, int $otherUserId): array {
    if ($currentUserId <= 0 || $otherUserId <= 0) {
        return ['success' => false, 'error' => 'Invalid user identifier.', 'code' => 'INVALID_ID'];
    }
    if ($currentUserId === $otherUserId) {
        return ['success' => false, 'error' => 'Cannot start a conversation with yourself.', 'code' => 'SELF_CONVERSATION'];
    }

    // Verify the other user exists
    $checkUser = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
    $checkUser->bindValue(':id', $otherUserId, PDO::PARAM_INT);
    $checkUser->execute();
    $otherUser = $checkUser->fetch();
    if (!$otherUser) {
        return ['success' => false, 'error' => 'User not found.', 'code' => 'NOT_FOUND'];
    }

    // Check for existing conversation
    $existingId = find_direct_conversation($pdo, $currentUserId, $otherUserId);
    if ($existingId !== null) {
        return [
            'success'         => true,
            'conversation_id' => $existingId,
            'created'         => false,
            'message'         => 'Existing conversation found.',
        ];
    }

    // Create new conversation atomically
    $pdo->beginTransaction();
    try {
        $insertConv = $pdo->prepare('INSERT INTO conversations () VALUES ()');
        $insertConv->execute();
        $convId = (int)$pdo->lastInsertId();

        $insertPart = $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id) VALUES (:cid, :uid)');
        $insertPart->bindValue(':cid', $convId, PDO::PARAM_INT);
        $insertPart->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
        $insertPart->execute();

        $insertPart->bindValue(':cid', $convId, PDO::PARAM_INT);
        $insertPart->bindValue(':uid', $otherUserId, PDO::PARAM_INT);
        $insertPart->execute();

        $pdo->commit();

        return [
            'success'         => true,
            'conversation_id' => $convId,
            'created'         => true,
            'message'         => 'Conversation created.',
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Create conversation error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to create conversation.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Check if a user is a participant of a specific conversation.
 */
function is_conversation_participant(PDO $pdo, int $conversationId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT id FROM conversation_participants WHERE conversation_id = :cid AND user_id = :uid LIMIT 1');
    $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool)$stmt->fetch();
}

/**
 * Get the list of conversations for a user, ordered by most recent activity.
 * Includes the other participant's info and the last message preview.
 */
function get_user_conversations(PDO $pdo, int $userId): array {
    $sql = '
        SELECT
            c.id AS conversation_id,
            c.updated_at,
            u.id AS other_user_id,
            u.username AS other_username,
            m.body AS last_message_body,
            m.created_at AS last_message_time,
            m.sender_id AS last_message_sender_id
        FROM conversations c
        JOIN conversation_participants cp1 ON cp1.conversation_id = c.id AND cp1.user_id = :uid
        JOIN conversation_participants cp2 ON cp2.conversation_id = c.id AND cp2.user_id != :uid2
        JOIN users u ON u.id = cp2.user_id
        LEFT JOIN messages m ON m.id = (
            SELECT m2.id FROM messages m2
            WHERE m2.conversation_id = c.id
            ORDER BY m2.created_at DESC
            LIMIT 1
        )
        ORDER BY COALESCE(m.created_at, c.updated_at) DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $conversations = [];
    while ($row = $stmt->fetch()) {
        $preview = $row['last_message_body'];
        if ($preview !== null && mb_strlen($preview) > 80) {
            $preview = mb_substr($preview, 0, 80) . '…';
        }
        $conversations[] = [
            'conversation_id'       => (int)$row['conversation_id'],
            'other_user_id'         => (int)$row['other_user_id'],
            'other_username'        => $row['other_username'],
            'last_message_preview'  => $preview,
            'last_message_time'     => $row['last_message_time'],
            'last_message_sender_id'=> $row['last_message_sender_id'] ? (int)$row['last_message_sender_id'] : null,
            'updated_at'            => $row['updated_at'],
        ];
    }

    return $conversations;
}

/**
 * Send a message in a conversation.
 */
function send_message(PDO $pdo, int $conversationId, int $senderId, string $body): array {
    $body = trim($body);
    if ($body === '') {
        return ['success' => false, 'error' => 'Message cannot be empty.', 'code' => 'EMPTY_MESSAGE'];
    }
    if (mb_strlen($body) > 5000) {
        return ['success' => false, 'error' => 'Message is too long (max 5000 characters).', 'code' => 'TOO_LONG'];
    }

    // Verify sender is a participant
    if (!is_conversation_participant($pdo, $conversationId, $senderId)) {
        return ['success' => false, 'error' => 'You are not a participant of this conversation.', 'code' => 'UNAUTHORIZED'];
    }

    try {
        $insertMsg = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (:cid, :sid, :body)');
        $insertMsg->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $insertMsg->bindValue(':sid', $senderId, PDO::PARAM_INT);
        $insertMsg->bindValue(':body', $body, PDO::PARAM_STR);
        $insertMsg->execute();
        $messageId = (int)$pdo->lastInsertId();

        // Touch conversation updated_at
        $touchConv = $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = :cid');
        $touchConv->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $touchConv->execute();

        return [
            'success'    => true,
            'message_id' => $messageId,
            'message'    => 'Message sent.',
        ];
    } catch (Throwable $e) {
        error_log('Send message error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to send message.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Get message history for a conversation, optionally after a specific message ID.
 */
function get_messages(PDO $pdo, int $conversationId, int $afterId = 0, int $limit = 100): array {
    $limit = max(1, min(200, $limit));

    if ($afterId > 0) {
        $sql = '
            SELECT m.id, m.sender_id, m.body, m.created_at, u.username AS sender_username
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = :cid AND m.id > :after_id
            ORDER BY m.created_at ASC
            LIMIT ' . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    } else {
        $sql = '
            SELECT m.id, m.sender_id, m.body, m.created_at, u.username AS sender_username
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = :cid
            ORDER BY m.created_at ASC
            LIMIT ' . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
    }

    $stmt->execute();

    $messages = [];
    while ($row = $stmt->fetch()) {
        $messages[] = [
            'id'              => (int)$row['id'],
            'sender_id'       => (int)$row['sender_id'],
            'sender_username' => $row['sender_username'],
            'body'            => $row['body'],
            'created_at'      => $row['created_at'],
        ];
    }
    return $messages;
}
