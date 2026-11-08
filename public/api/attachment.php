<?php
/**
 * Project Exodia — Protected Attachment Download & Preview Endpoint
 *
 * Enforces:
 *  - Authenticated session verification
 *  - Conversation membership check
 *  - Delete for Me / Delete for Everyone access control
 *  - Strict path traversal prevention (database identifier resolution only)
 *  - Defensive security headers (CSP, nosniff, cache control)
 *  - Safe Content-Disposition (inline for permitted image previews, attachment for downloads)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attachments.php';

// Authenticate user
if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$currentUser = get_logged_in_user();
$currentUserId = (int)$currentUser['id'];

$attachmentId = (int)($_GET['id'] ?? 0);
$isPreview = isset($_GET['preview']) && $_GET['preview'] === '1';

if ($attachmentId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Valid attachment ID is required.']);
    exit;
}

try {
    $pdo = get_db_connection();
    $att = get_attachment_for_user($pdo, $attachmentId, $currentUserId);

    if ($att === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => 'Attachment not found or access denied.']);
        exit;
    }

    if (isset($att['error'])) {
        if ($att['error'] === 'DELETED_FOR_EVERYONE') {
            http_response_code(410);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'This attachment was deleted.']);
            exit;
        }

        if ($att['error'] === 'HIDDEN_FOR_ME' || $att['error'] === 'FILE_NOT_FOUND') {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Attachment not found.']);
            exit;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    $filePath = $att['absolute_path'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => 'File missing from storage.']);
        exit;
    }

    // Defensive Security Headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age=86400, no-transform');

    $mime = $att['mime_type'];
    $isImage = ($att['file_type'] === 'image');
    $filename = preg_replace('/[^\w\.\-\s]/u', '_', $att['original_filename']);

    // Images can be viewed inline if preview requested; otherwise forced download
    if ($isPreview && $isImage) {
        header("Content-Type: {$mime}");
        header('Content-Disposition: inline; filename="' . addcslashes($filename, '"') . '"');
    } else {
        header("Content-Type: {$mime}");
        header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"') . '"');
    }

    header('Content-Length: ' . (string)filesize($filePath));

    // Clear output buffer and stream file
    if (ob_get_level()) {
        ob_end_clean();
    }
    readfile($filePath);
    exit;

} catch (Throwable $e) {
    error_log('Attachment serve error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Failed to serve attachment.']);
    exit;
}
