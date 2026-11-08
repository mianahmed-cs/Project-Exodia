<?php
/**
 * Project Exodia — Messages API Endpoint
 *
 * GET:  Retrieve messages for a conversation (requires conversation_id, optional after_id for polling)
 * POST: Send a message (requires conversation_id, body, CSRF token)
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
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        $afterId = (int)($_GET['after_id'] ?? 0);

        if ($conversationId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'conversation_id is required.']);
            exit;
        }

        // Authorization check
        if (!is_conversation_participant($pdo, $conversationId, $currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You are not a participant of this conversation.']);
            exit;
        }

        $messages = get_messages($pdo, $conversationId, $afterId);
        $safe = array_map(function ($m) {
            return [
                'id'              => $m['id'],
                'sender_id'       => $m['sender_id'],
                'sender_username' => htmlspecialchars($m['sender_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'body'            => htmlspecialchars($m['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'created_at'      => $m['created_at'],
            ];
        }, $messages);

        echo json_encode(['success' => true, 'messages' => $safe], JSON_UNESCAPED_SLASHES);
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

        $conversationId = (int)($data['conversation_id'] ?? 0);
        $body = (string)($data['body'] ?? '');

        if ($conversationId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'conversation_id is required.']);
            exit;
        }

        $result = send_message($pdo, $conversationId, $currentUserId, $body);
        if (!$result['success']) {
            $code = ($result['code'] ?? '') === 'UNAUTHORIZED' ? 403 : 400;
            http_response_code($code);
        }
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
} catch (Throwable $e) {
    error_log('Messages API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
