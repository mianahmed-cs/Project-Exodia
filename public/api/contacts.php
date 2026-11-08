<?php
/**
 * Project Exodia — Contact Management API Endpoint
 * 
 * Manages user contacts:
 *  - GET: Retrieve authenticated user's contact list
 *  - POST (action=add): Add a user as contact (CSRF protected)
 *  - POST (action=remove): Remove a contact (CSRF protected)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/contacts.php';

// Enforce authentication
$currentUser = require_auth_api();
$currentUserId = (int)$currentUser['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = get_db_connection();

    // ----------------------------------------------------
    // GET: Retrieve Contacts List
    // ----------------------------------------------------
    if ($method === 'GET') {
        $contacts = get_user_contacts($pdo, $currentUserId);

        $safeContacts = array_map(function ($c) {
            return [
                'relationship_id' => (int)$c['relationship_id'],
                'user_id'         => (int)$c['user_id'],
                'username'        => htmlspecialchars($c['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'email'           => htmlspecialchars($c['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'member_since'    => $c['member_since'],
                'added_at'        => $c['added_at'],
            ];
        }, $contacts);

        echo json_encode([
            'success'  => true,
            'count'    => count($safeContacts),
            'contacts' => $safeContacts,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ----------------------------------------------------
    // POST: State-changing operations (Add / Remove)
    // ----------------------------------------------------
    if ($method === 'POST') {
        // Parse input from POST or JSON body
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);

        $csrfToken = $_POST['csrf_token'] 
            ?? $jsonData['csrf_token'] 
            ?? $_SERVER['HTTP_X_CSRF_TOKEN'] 
            ?? '';

        if (!verify_csrf_token($csrfToken)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid or missing CSRF token.',
                'code'    => 'CSRF_INVALID',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $action = trim((string)($_POST['action'] ?? $jsonData['action'] ?? ''));
        $contactId = (int)($_POST['contact_id'] ?? $jsonData['contact_id'] ?? 0);

        if ($contactId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'A valid numeric contact_id is required.',
                'code'    => 'INVALID_CONTACT_ID',
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Action 1: Add Contact
        if ($action === 'add') {
            $result = add_contact($pdo, $currentUserId, $contactId);
            if (!$result['success']) {
                $status = ($result['code'] === 'NOT_FOUND') ? 404 : 400;
                http_response_code($status);
            }
            echo json_encode($result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Action 2: Remove Contact
        if ($action === 'remove') {
            $result = remove_contact($pdo, $currentUserId, $contactId);
            if (!$result['success']) {
                $status = ($result['code'] === 'NOT_FOUND') ? 404 : 400;
                http_response_code($status);
            }
            echo json_encode($result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Unknown action
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Unknown action specified. Use "add" or "remove".',
            'code'    => 'UNKNOWN_ACTION',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Method not allowed
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'HTTP method not allowed.',
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('Contacts API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'An unexpected server error occurred.',
    ], JSON_UNESCAPED_SLASHES);
}
