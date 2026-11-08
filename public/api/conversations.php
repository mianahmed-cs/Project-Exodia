<?php
/**
 * Project Exodia — Conversations API Endpoint
 *
 * GET:  List authenticated user's conversations with previews
 * POST: Create a new conversation (action=create, requires user_id + CSRF)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/validator.php';
require_once __DIR__ . '/../../includes/messaging.php';

$currentUser = require_auth_api();
$currentUserId = (int)$currentUser['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = get_db_connection();

    if ($method === 'GET') {
        $conversations = get_user_conversations($pdo, $currentUserId);
        $safe = array_map(function ($c) {
            return [
                'conversation_id'        => $c['conversation_id'],
                'other_user_id'          => $c['other_user_id'],
                'other_username'         => htmlspecialchars($c['other_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'last_message_preview'   => $c['last_message_preview'] !== null
                    ? htmlspecialchars($c['last_message_preview'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : null,
                'last_message_time'      => $c['last_message_time'],
                'last_message_sender_id' => $c['last_message_sender_id'],
                'updated_at'             => $c['updated_at'],
            ];
        }, $conversations);

        echo json_encode(['success' => true, 'conversations' => $safe], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? $_POST;

        $csrf = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verify_csrf_token($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
            exit;
        }

        $action = trim((string)($data['action'] ?? ''));
        if ($action === 'create') {
            $otherUserId = (int)($data['user_id'] ?? 0);
            if ($otherUserId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'A valid user_id is required.']);
                exit;
            }
            $result = create_conversation($pdo, $currentUserId, $otherUserId);
            if (!$result['success']) {
                http_response_code($result['code'] === 'NOT_FOUND' ? 404 : 400);
            }
            echo json_encode($result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action. Use "create".']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
} catch (Throwable $e) {
    error_log('Conversations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
