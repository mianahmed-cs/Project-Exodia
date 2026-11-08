<?php
/**
 * Project Exodia — Messages API Endpoint
 *
 * GET:  Retrieve messages & activity events (requires conversation_id, optional after_id & after_event_id)
 * POST: Actions supported:
 *        - 'send': Send direct message (supports optional reply_to_message_id)
 *        - 'delete_for_me': Hide message for current user
 *        - 'delete_for_everyone': Mask message for all participants (original sender only)
 *        - 'react': Toggle emoji reaction (👍, ❤️, 😂, 😮, 😢, 😡)
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
        $afterEventId = isset($_GET['after_event_id']) ? (int)$_GET['after_event_id'] : null;

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

        $messages = get_messages($pdo, $conversationId, $afterId, 100, $currentUserId);
        $safe = array_map(function ($m) {
            $isDeleted = !empty($m['is_deleted_for_everyone']);
            
            $replyToSafe = null;
            if (!empty($m['reply_to'])) {
                $r = $m['reply_to'];
                $rDeleted = !empty($r['is_deleted_for_everyone']);
                $replyToSafe = [
                    'id'                      => (int)$r['id'],
                    'sender_id'               => (int)$r['sender_id'],
                    'sender_username'         => htmlspecialchars($r['sender_username'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'body'                    => $rDeleted ? 'This message was deleted.' : htmlspecialchars($r['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'is_deleted_for_everyone' => $rDeleted,
                ];
            }

            $attSafe = null;
            if (!empty($m['attachment']) && !$isDeleted) {
                $a = $m['attachment'];
                $attSafe = [
                    'id'                => (int)$a['id'],
                    'original_filename' => htmlspecialchars($a['original_filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'file_size'         => (int)$a['file_size'],
                    'file_type'         => $a['file_type'],
                    'mime_type'         => $a['mime_type'],
                    'download_url'      => $a['download_url'],
                    'preview_url'       => $a['preview_url'],
                ];
            }

            return [
                'id'                      => $m['id'],
                'sender_id'               => $m['sender_id'],
                'sender_username'         => htmlspecialchars($m['sender_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'body'                    => $isDeleted ? 'This message was deleted.' : htmlspecialchars($m['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'is_deleted_for_everyone' => $isDeleted,
                'deleted_at'              => $m['deleted_at'] ?? null,
                'reply_to'                => $replyToSafe,
                'reactions'               => $m['reactions'] ?? [],
                'attachment'              => $attSafe,
                'created_at'              => $m['created_at'],
            ];
        }, $messages);

        $response = [
            'success'  => true,
            'messages' => $safe,
        ];

        // If client is tracking events incrementally
        if ($afterEventId !== null) {
            $events = get_conversation_events($pdo, $conversationId, $afterEventId, $currentUserId);
            $response['events'] = $events;
            $maxEvId = $afterEventId;
            foreach ($events as $ev) {
                if ($ev['id'] > $maxEvId) {
                    $maxEvId = $ev['id'];
                }
            }
            $response['last_event_id'] = $maxEvId;
        } else {
            $response['last_event_id'] = get_max_event_id($pdo, $conversationId);
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES);
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

        $action = (string)($data['action'] ?? 'send');

        // Action 1: Delete For Me
        if ($action === 'delete_for_me') {
            $messageId = (int)($data['message_id'] ?? 0);
            $result = delete_message_for_me($pdo, $messageId, $currentUserId);
            if (!$result['success']) {
                $code = ($result['code'] ?? '') === 'NOT_FOUND' ? 404 : 400;
                http_response_code($code);
            }
            echo json_encode($result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Action 2: Delete For Everyone
        if ($action === 'delete_for_everyone') {
            $messageId = (int)($data['message_id'] ?? 0);
            $result = delete_message_for_everyone($pdo, $messageId, $currentUserId);
            if (!$result['success']) {
                $code = ($result['code'] ?? '') === 'FORBIDDEN' ? 403 : (($result['code'] ?? '') === 'NOT_FOUND' ? 404 : 400);
                http_response_code($code);
            }
            echo json_encode($result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Action 3: Emoji Reaction Toggle
        if ($action === 'react') {
            $messageId = (int)($data['message_id'] ?? 0);
            $emoji = (string)($data['emoji'] ?? '');
            $result = toggle_message_reaction($pdo, $messageId, $currentUserId, $emoji);
            if (!$result['success']) {
                $code = ($result['code'] ?? '') === 'NOT_FOUND' ? 404 : 400;
                http_response_code($code);
            }
            echo json_encode($result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Action 4: File & Photo Upload Message
        if (isset($_FILES['attachment']) || isset($_FILES['file']) || $action === 'upload_attachment') {
            $file = $_FILES['attachment'] ?? $_FILES['file'] ?? null;
            if (!$file) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No attachment file provided.']);
                exit;
            }

            $conversationId = (int)($data['conversation_id'] ?? 0);
            $caption = (string)($data['body'] ?? $data['caption'] ?? '');
            $replyToId = isset($data['reply_to_message_id']) && (int)$data['reply_to_message_id'] > 0
                ? (int)$data['reply_to_message_id']
                : null;

            if ($conversationId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'conversation_id is required.']);
                exit;
            }

            $result = save_attachment_message($pdo, $conversationId, $currentUserId, $file, $caption, $replyToId);
            if (!$result['success']) {
                $code = ($result['code'] ?? '') === 'UNAUTHORIZED' ? 403 : 400;
                http_response_code($code);
            }
            echo json_encode($result, JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Action 5: Standard Message Send (with optional Quoted Reply)
        $conversationId = (int)($data['conversation_id'] ?? 0);
        $body = (string)($data['body'] ?? '');
        $replyToId = isset($data['reply_to_message_id']) && (int)$data['reply_to_message_id'] > 0
            ? (int)$data['reply_to_message_id']
            : null;

        if ($conversationId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'conversation_id is required.']);
            exit;
        }

        $result = send_message($pdo, $conversationId, $currentUserId, $body, $replyToId);
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
