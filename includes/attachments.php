<?php
/**
 * Project Exodia — Secure Attachment Management Service
 *
 * Handles:
 *  - Strict server-side file validation (extensions, size, finfo MIME detection)
 *  - Cryptographically random filename generation outside web root
 *  - Atomicity: clean rollback and file unlinking on failure
 *  - Access control: verified conversation membership, Delete for Me & Delete for Everyone
 *  - Format allowlist:
 *      Images: JPEG/JPG, PNG, WebP, GIF (Max 10 MB)
 *      Documents: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT (Max 25 MB)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/messaging.php';

const ALLOWED_IMAGES = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];

const ALLOWED_DOCUMENTS = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt'  => 'text/plain',
];

const MAX_IMAGE_SIZE = 10485760;    // 10 MB
const MAX_DOCUMENT_SIZE = 26214400; // 25 MB

/**
 * Get the private attachments storage directory.
 */
function get_attachments_storage_dir(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'attachments';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

/**
 * Validate an uploaded file structure.
 *
 * @param array $file $_FILES['attachment'] structure
 * @return array ['valid' => bool, 'error' => string, 'file_type' => string, 'mime' => string, 'ext' => string, 'filename' => string]
 */
function validate_uploaded_attachment(array $file): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['valid' => false, 'error' => 'Invalid upload parameters.'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['valid' => false, 'error' => 'File exceeds the maximum upload file size configured on the server.'];
        case UPLOAD_ERR_PARTIAL:
            return ['valid' => false, 'error' => 'File was only partially uploaded. Please try again.'];
        case UPLOAD_ERR_NO_FILE:
            return ['valid' => false, 'error' => 'No file was provided for upload.'];
        default:
            return ['valid' => false, 'error' => 'Failed to receive file upload (error code: ' . $file['error'] . ').'];
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !file_exists($tmpPath)) {
        return ['valid' => false, 'error' => 'Temporary upload file missing.'];
    }

    $fileSize = (int)($file['size'] ?? 0);
    if ($fileSize <= 0) {
        return ['valid' => false, 'error' => 'Uploaded file is empty (0 bytes).'];
    }

    $rawFilename = (string)($file['name'] ?? 'attachment');
    $ext = strtolower(pathinfo($rawFilename, PATHINFO_EXTENSION));

    // Determine category
    $isImage = isset(ALLOWED_IMAGES[$ext]);
    $isDoc = isset(ALLOWED_DOCUMENTS[$ext]);

    if (!$isImage && !$isDoc) {
        return [
            'valid' => false,
            'error' => 'Unsupported file format (. ' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') . '). Supported: JPG, PNG, WebP, GIF, PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT.',
        ];
    }

    $fileType = $isImage ? 'image' : 'document';
    $maxSize = $isImage ? MAX_IMAGE_SIZE : MAX_DOCUMENT_SIZE;

    if ($fileSize > $maxSize) {
        $limitMb = $isImage ? '10 MB' : '25 MB';
        return ['valid' => false, 'error' => "File exceeds the {$limitMb} limit for {$fileType}s."];
    }

    // Inspect real MIME type via finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($tmpPath);

    if ($isImage) {
        $expectedMime = ALLOWED_IMAGES[$ext];
        if ($detectedMime !== $expectedMime) {
            return ['valid' => false, 'error' => 'File contents do not match image format headers.'];
        }

        // Verify valid image dimensions and integrity
        $imgInfo = @getimagesize($tmpPath);
        if ($imgInfo === false || $imgInfo[0] <= 0 || $imgInfo[1] <= 0) {
            return ['valid' => false, 'error' => 'Image file is corrupt or invalid.'];
        }

        // Protect against decompressed pixel flood attacks (> 100M pixels)
        if (($imgInfo[0] * $imgInfo[1]) > 100000000) {
            return ['valid' => false, 'error' => 'Image dimensions exceed allowed resolution limits.'];
        }
    } else {
        // Document MIME validation
        $validDoc = false;
        if ($ext === 'pdf' && $detectedMime === 'application/pdf') {
            $validDoc = true;
        } elseif ($ext === 'txt' && (strpos($detectedMime, 'text/') === 0 || $detectedMime === 'application/csv')) {
            $validDoc = true;
        } elseif (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
            // Modern Office formats are ZIP packages containing XML
            if ($detectedMime === ALLOWED_DOCUMENTS[$ext] || $detectedMime === 'application/zip' || $detectedMime === 'application/x-zip') {
                $validDoc = true;
            }
        } elseif (in_array($ext, ['doc', 'xls', 'ppt'], true)) {
            // Legacy OLE compound document formats
            if ($detectedMime === ALLOWED_DOCUMENTS[$ext] || $detectedMime === 'application/x-ole-storage' || $detectedMime === 'application/octet-stream') {
                $validDoc = true;
            }
        }

        if (!$validDoc) {
            return ['valid' => false, 'error' => 'File contents do not match document format requirements.'];
        }
    }

    // Sanitize original filename (remove directory separators or null bytes)
    $cleanFilename = preg_replace('/[^\w\.\-\s]/u', '_', basename($rawFilename));
    if (mb_strlen($cleanFilename) > 200) {
        $cleanFilename = mb_substr($cleanFilename, 0, 190) . '.' . $ext;
    }

    return [
        'valid'     => true,
        'file_type' => $fileType,
        'mime'      => $detectedMime,
        'ext'       => $ext,
        'size'      => $fileSize,
        'filename'  => $cleanFilename,
    ];
}

/**
 * Save an attachment and its associated conversation message atomically.
 */
function save_attachment_message(
    PDO $pdo,
    int $conversationId,
    int $userId,
    array $file,
    string $caption = '',
    ?int $replyToId = null
): array {
    if (!is_conversation_participant($pdo, $conversationId, $userId)) {
        return ['success' => false, 'error' => 'You are not a participant of this conversation.', 'code' => 'UNAUTHORIZED'];
    }

    $val = validate_uploaded_attachment($file);
    if (!$val['valid']) {
        return ['success' => false, 'error' => $val['error'], 'code' => 'INVALID_FILE'];
    }

    $storageDir = get_attachments_storage_dir();
    $randomKey = bin2hex(random_bytes(16));
    $storageFilename = $randomKey . '.' . $val['ext'];
    $destPath = $storageDir . DIRECTORY_SEPARATOR . $storageFilename;
    $relPath = 'storage/attachments/' . $storageFilename;

    $tmpPath = $file['tmp_name'];
    $moved = false;

    if (is_uploaded_file($tmpPath)) {
        $moved = move_uploaded_file($tmpPath, $destPath);
    } else {
        // Fallback for automated CLI testing environments
        $moved = copy($tmpPath, $destPath);
    }

    if (!$moved) {
        return ['success' => false, 'error' => 'Failed to write uploaded file to private storage.', 'code' => 'WRITE_FAILED'];
    }
    chmod($destPath, 0640);

    // Validate reply_to_message_id if provided
    $replyIdParam = null;
    if ($replyToId !== null && $replyToId > 0) {
        $checkReply = $pdo->prepare('SELECT id, conversation_id, is_deleted_for_everyone FROM messages WHERE id = :mid LIMIT 1');
        $checkReply->bindValue(':mid', $replyToId, PDO::PARAM_INT);
        $checkReply->execute();
        $targetMsg = $checkReply->fetch(PDO::FETCH_ASSOC);

        if (!$targetMsg || (int)$targetMsg['conversation_id'] !== $conversationId) {
            @unlink($destPath);
            return ['success' => false, 'error' => 'Referenced message does not exist in this conversation.', 'code' => 'INVALID_REPLY'];
        }

        if ((int)$targetMsg['is_deleted_for_everyone'] === 1) {
            @unlink($destPath);
            return ['success' => false, 'error' => 'Cannot reply to a deleted message.', 'code' => 'REPLY_TO_DELETED'];
        }

        $replyIdParam = $replyToId;
    }

    $caption = trim($caption);
    if (mb_strlen($caption) > 5000) {
        $caption = mb_substr($caption, 0, 5000);
    }

    $pdo->beginTransaction();
    try {
        // 1. Insert message record
        $insertMsg = $pdo->prepare('
            INSERT INTO messages (conversation_id, sender_id, body, reply_to_message_id)
            VALUES (:cid, :sid, :body, :reply_id)
        ');
        $insertMsg->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $insertMsg->bindValue(':sid', $userId, PDO::PARAM_INT);
        $insertMsg->bindValue(':body', $caption, PDO::PARAM_STR);
        if ($replyIdParam !== null) {
            $insertMsg->bindValue(':reply_id', $replyIdParam, PDO::PARAM_INT);
        } else {
            $insertMsg->bindValue(':reply_id', null, PDO::PARAM_NULL);
        }
        $insertMsg->execute();
        $messageId = (int)$pdo->lastInsertId();

        // 2. Insert attachment record
        $insertAtt = $pdo->prepare('
            INSERT INTO attachments (
                message_id, conversation_id, user_id,
                original_filename, storage_filename, file_path,
                mime_type, file_size, file_type
            ) VALUES (
                :mid, :cid, :uid,
                :orig, :store, :path,
                :mime, :size, :ftype
            )
        ');
        $insertAtt->execute([
            ':mid'   => $messageId,
            ':cid'   => $conversationId,
            ':uid'   => $userId,
            ':orig'  => $val['filename'],
            ':store' => $storageFilename,
            ':path'  => $relPath,
            ':mime'  => $val['mime'],
            ':size'  => $val['size'],
            ':ftype' => $val['file_type'],
        ]);
        $attachmentId = (int)$pdo->lastInsertId();

        // 3. Touch conversation updated_at
        $touchConv = $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = :cid');
        $touchConv->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $touchConv->execute();

        // 4. Activity event log
        $evPayload = json_encode([
            'has_attachment'      => true,
            'attachment_id'       => $attachmentId,
            'file_type'           => $val['file_type'],
            'original_filename'   => $val['filename'],
            'file_size'           => $val['size'],
            'reply_to_message_id' => $replyIdParam,
        ]);
        $ev = $pdo->prepare('
            INSERT INTO conversation_events (conversation_id, event_type, message_id, actor_id, payload)
            VALUES (:cid, "message_created", :mid, :aid, :payload)
        ');
        $ev->execute([
            ':cid'     => $conversationId,
            ':mid'     => $messageId,
            ':aid'     => $userId,
            ':payload' => $evPayload,
        ]);

        $pdo->commit();

        return [
            'success'         => true,
            'message_id'      => $messageId,
            'attachment_id'   => $attachmentId,
            'conversation_id' => $conversationId,
            'file_type'       => $val['file_type'],
            'filename'        => $val['filename'],
            'file_size'       => $val['size'],
            'download_url'    => "api/attachment.php?id={$attachmentId}",
            'preview_url'     => "api/attachment.php?id={$attachmentId}&preview=1",
            'message'         => 'Attachment sent successfully.',
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($destPath);
        error_log('Attachment save error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to save attachment metadata.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Retrieve verified attachment metadata and verify authorization for user.
 *
 * @param PDO $pdo Active PDO connection
 * @param int $attachmentId Target attachment ID
 * @param int $userId Requesting user ID
 * @return array|null Attachment data with absolute path, or null/error array if unauthorized
 */
function get_attachment_for_user(PDO $pdo, int $attachmentId, int $userId): ?array {
    if ($attachmentId <= 0 || $userId <= 0) {
        return null;
    }

    $sql = '
        SELECT 
            a.id, a.message_id, a.conversation_id, a.user_id,
            a.original_filename, a.storage_filename, a.file_path,
            a.mime_type, a.file_size, a.file_type, a.created_at,
            m.is_deleted_for_everyone,
            uhm.id AS is_hidden_for_me
        FROM attachments a
        JOIN conversation_participants cp ON cp.conversation_id = a.conversation_id AND cp.user_id = :uid
        JOIN messages m ON m.id = a.message_id
        LEFT JOIN user_hidden_messages uhm ON uhm.message_id = a.message_id AND uhm.user_id = :uid2
        WHERE a.id = :aid
        LIMIT 1
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':aid', $attachmentId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    // Check Delete for Me
    if ($row['is_hidden_for_me'] !== null) {
        return ['error' => 'HIDDEN_FOR_ME'];
    }

    // Check Delete for Everyone
    if ((int)$row['is_deleted_for_everyone'] === 1) {
        return ['error' => 'DELETED_FOR_EVERYONE'];
    }

    $projectRoot = dirname(__DIR__);
    $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . $row['file_path'];

    // Strict path verification to prevent path traversal
    $storageDir = realpath(get_attachments_storage_dir());
    $resolvedPath = realpath($absolutePath);

    if (!$resolvedPath || strpos($resolvedPath, $storageDir) !== 0 || !file_exists($resolvedPath)) {
        return ['error' => 'FILE_NOT_FOUND'];
    }

    $row['absolute_path'] = $resolvedPath;
    return $row;
}

/**
 * Fetch attachments associated with a list of message IDs.
 */
function get_attachments_for_messages(PDO $pdo, array $messageIds): array {
    if (empty($messageIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $sql = "
        SELECT id, message_id, original_filename, file_size, file_type, mime_type
        FROM attachments
        WHERE message_id IN ({$placeholders})
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($messageIds));

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['message_id'];
        $aid = (int)$row['id'];
        $result[$mid] = [
            'id'                => $aid,
            'original_filename' => $row['original_filename'],
            'file_size'         => (int)$row['file_size'],
            'file_type'         => $row['file_type'],
            'mime_type'         => $row['mime_type'],
            'download_url'      => "api/attachment.php?id={$aid}",
            'preview_url'       => "api/attachment.php?id={$aid}&preview=1",
        ];
    }
    return $result;
}
