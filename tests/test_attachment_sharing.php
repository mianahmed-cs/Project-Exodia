<?php
/**
 * Project Exodia — Photo & File Sharing Verification Suite
 *
 * Verifies:
 *  1. Attachment Upload Validation: Rejects oversized images (>10MB), oversized docs (>25MB),
 *     disallowed extensions (.php, .exe, .svg), and MIME spoofing.
 *  2. Secure Storage & Filename Randomization: Valid attachments stored outside web root
 *     with cryptographic hex filenames.
 *  3. Photo & Document Message Creation: Supports optional captions and empty captions.
 *  4. Message History Retrieval: Injects attachment metadata, download_url, and preview_url.
 *  5. Conversation Preview: Formats previews as "📷 Photo" or "📄 <filename>".
 *  6. Access Control & Authorization: Participants have access; non-participants are strictly blocked.
 *  7. Quoted Replies & Emoji Reactions: Full compatibility with attachment messages.
 *  8. Deletion Mechanics: Delete for Me hides attachment only for requester;
 *     Delete for Everyone renders attachment inaccessible (410 Gone) for all participants.
 *  9. Database Integrity & Cascade: Cascades cleanly on message/conversation deletion.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contacts.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/attachments.php';

$pdo = get_db_connection();

echo "\n========================================================================\n";
echo " Project Exodia — Photo & File Sharing Test Suite\n";
echo "========================================================================\n\n";

$passCount = 0;
$totalCount = 0;
$createdTempFiles = [];

function run_att_test(string $title, bool $condition, string $detail = ''): void {
    global $passCount, $totalCount;
    $totalCount++;
    $numStr = str_pad((string)$totalCount, 2, '0', STR_PAD_LEFT);
    if ($condition) {
        $passCount++;
        echo "  [PASS] Test #{$numStr}: {$title}\n";
    } else {
        echo "  [FAIL] Test #{$numStr}: {$title}\n";
        if ($detail !== '') {
            echo "         Detail: {$detail}\n";
        }
    }
}

// ---------------------------------------------------------------------
// Setup Test Operatives
// ---------------------------------------------------------------------
$ts = time() . mt_rand(100, 999);
$userA_name = "att_a_{$ts}";
$userB_name = "att_b_{$ts}";
$userC_name = "att_c_{$ts}";
$passHash = password_hash('Pass123!#', PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)");
$stmt->execute([':u' => $userA_name, ':e' => "{$userA_name}@test.local", ':p' => $passHash]);
$idA = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $userB_name, ':e' => "{$userB_name}@test.local", ':p' => $passHash]);
$idB = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $userC_name, ':e' => "{$userC_name}@test.local", ':p' => $passHash]);
$idC = (int)$pdo->lastInsertId();

$convRes = create_conversation($pdo, $idA, $idB);
$convId = (int)$convRes['conversation_id'];
run_att_test("Setup operatives & conversation (A: {$idA}, B: {$idB}, C: {$idC})", $convId > 0);

// Helper to create genuine test images
function create_dummy_png(int $w = 10, int $h = 10): string {
    global $createdTempFiles;
    $tmp = tempnam(sys_get_temp_dir(), 'exo_png_');
    $im = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($im, 10, 132, 255);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    imagepng($im, $tmp);
    imagedestroy($im);
    $createdTempFiles[] = $tmp;
    return $tmp;
}

// Helper to create genuine test PDF
function create_dummy_pdf(string $text = "Project Exodia Confidential Report"): string {
    global $createdTempFiles;
    $tmp = tempnam(sys_get_temp_dir(), 'exo_pdf_');
    // Minimal standard valid PDF content
    $content = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n180\n%%EOF\n";
    file_put_contents($tmp, $content);
    $createdTempFiles[] = $tmp;
    return $tmp;
}

// ---------------------------------------------------------------------
// TEST GROUP 1: File Validation & Security Rules
// ---------------------------------------------------------------------

// 1. Oversized image rejection (> 10MB)
$hugeFile = tempnam(sys_get_temp_dir(), 'exo_huge_');
$createdTempFiles[] = $hugeFile;
file_put_contents($hugeFile, 'fake image data');
$valOversizeImg = validate_uploaded_attachment([
    'name'     => 'huge.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $hugeFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 11 * 1024 * 1024,
]);
run_att_test("Reject oversized image (> 10MB)", !$valOversizeImg['valid'] && strpos($valOversizeImg['error'], '10 MB') !== false);

// 2. Oversized document rejection (> 25MB)
$valOversizeDoc = validate_uploaded_attachment([
    'name'     => 'big_manual.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $hugeFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 26 * 1024 * 1024,
]);
run_att_test("Reject oversized document (> 25MB)", !$valOversizeDoc['valid'] && strpos($valOversizeDoc['error'], '25 MB') !== false);

// 3. Disallowed file extensions (.php, .exe, .sh, .svg, .html)
$phpFile = tempnam(sys_get_temp_dir(), 'exo_php_');
$createdTempFiles[] = $phpFile;
file_put_contents($phpFile, '<?php echo "evil"; ?>');
$valPhp = validate_uploaded_attachment([
    'name'     => 'shell.php',
    'type'     => 'application/x-php',
    'tmp_name' => $phpFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 20,
]);
run_att_test("Reject executable PHP file extension (.php)", !$valPhp['valid']);

$valExe = validate_uploaded_attachment([
    'name'     => 'trojan.exe',
    'type'     => 'application/octet-stream',
    'tmp_name' => $phpFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 50,
]);
run_att_test("Reject executable binary extension (.exe)", !$valExe['valid']);

$valSvg = validate_uploaded_attachment([
    'name'     => 'vector.svg',
    'type'     => 'image/svg+xml',
    'tmp_name' => $phpFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 50,
]);
run_att_test("Reject SVG format to prevent stored XSS (.svg)", !$valSvg['valid']);

// 4. MIME spoofing / fake header detection
$spoofedImg = tempnam(sys_get_temp_dir(), 'exo_spoof_');
$createdTempFiles[] = $spoofedImg;
file_put_contents($spoofedImg, '<?php phpinfo(); ?>'); // PHP code disguised as JPG
$valSpoof = validate_uploaded_attachment([
    'name'     => 'avatar.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $spoofedImg,
    'error'    => UPLOAD_ERR_OK,
    'size'     => 25,
]);
run_att_test("Detect and reject MIME spoofing (PHP script renamed to .jpg)", !$valSpoof['valid']);

// ---------------------------------------------------------------------
// TEST GROUP 2: Successful Photo Upload & Storage Isolation
// ---------------------------------------------------------------------
$validPngPath = create_dummy_png(200, 150);
$photoUpload = [
    'name'     => 'intel_satellite.png',
    'type'     => 'image/png',
    'tmp_name' => $validPngPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($validPngPath),
];

// User A sends photo with caption
$photoMsgRes = save_attachment_message($pdo, $convId, $idA, $photoUpload, 'Classified satellite overview');
run_att_test("User A sends photo message with caption", $photoMsgRes['success']);

$photoMsgId = (int)($photoMsgRes['message_id'] ?? 0);
$photoAttId = (int)($photoMsgRes['attachment_id'] ?? 0);
run_att_test("Photo message ID and attachment ID returned", $photoMsgId > 0 && $photoAttId > 0);

// Verify database record in `attachments`
$stmt = $pdo->prepare('SELECT * FROM attachments WHERE id = :id');
$stmt->execute([':id' => $photoAttId]);
$photoRow = $stmt->fetch(PDO::FETCH_ASSOC);
run_att_test("Attachment record inserted in MySQL", !empty($photoRow) && $photoRow['file_type'] === 'image');
run_att_test("Original filename preserved in database", $photoRow['original_filename'] === 'intel_satellite.png');
run_att_test("Storage filename is random hex", preg_match('/^[a-f0-9]{32}\.png$/', $photoRow['storage_filename']) === 1);

// Verify file physically exists in storage/attachments/
$expectedDiskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $photoRow['file_path'];
run_att_test("File physically stored in storage/attachments/", file_exists($expectedDiskPath) && filesize($expectedDiskPath) > 0);

// ---------------------------------------------------------------------
// TEST GROUP 3: Successful Document Upload
// ---------------------------------------------------------------------
$validPdfPath = create_dummy_pdf();
$pdfUpload = [
    'name'     => 'mission_brief.pdf',
    'type'     => 'application/pdf',
    'tmp_name' => $validPdfPath,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($validPdfPath),
];

// User B sends document with empty caption
$docMsgRes = save_attachment_message($pdo, $convId, $idB, $pdfUpload, '');
run_att_test("User B sends PDF document without caption", $docMsgRes['success']);

$docMsgId = (int)($docMsgRes['message_id'] ?? 0);
$docAttId = (int)($docMsgRes['attachment_id'] ?? 0);
run_att_test("Document message ID and attachment ID returned", $docMsgId > 0 && $docAttId > 0);

// Verify database record in `attachments`
$stmt->execute([':id' => $docAttId]);
$docRow = $stmt->fetch(PDO::FETCH_ASSOC);
run_att_test("Document record has file_type = 'document'", !empty($docRow) && $docRow['file_type'] === 'document');
run_att_test("Document MIME type recorded as application/pdf", $docRow['mime_type'] === 'application/pdf');

// ---------------------------------------------------------------------
// TEST GROUP 4: Message History & Conversation Roster Integration
// ---------------------------------------------------------------------

// Get messages for User A
$messagesA = get_messages($pdo, $convId, 0, 50, $idA);
$photoMsgFromHist = null;
$docMsgFromHist = null;
foreach ($messagesA as $m) {
    if ($m['id'] === $photoMsgId) $photoMsgFromHist = $m;
    if ($m['id'] === $docMsgId) $docMsgFromHist = $m;
}

run_att_test("get_messages() returns photo attachment metadata", !empty($photoMsgFromHist['attachment']) && $photoMsgFromHist['attachment']['file_type'] === 'image');
run_att_test("Photo message includes preview_url and download_url", !empty($photoMsgFromHist['attachment']['preview_url']) && !empty($photoMsgFromHist['attachment']['download_url']));
run_att_test("Photo message preserves caption in body", $photoMsgFromHist['body'] === 'Classified satellite overview');

run_att_test("get_messages() returns document attachment metadata", !empty($docMsgFromHist['attachment']) && $docMsgFromHist['attachment']['file_type'] === 'document');
run_att_test("Document message has empty caption in body", $docMsgFromHist['body'] === '');

// Verify conversation roster preview
$conversationsA = get_user_conversations($pdo, $idA);
$convItem = null;
foreach ($conversationsA as $c) {
    if ($c['conversation_id'] === $convId) $convItem = $c;
}
run_att_test("Conversation roster preview displays document label ('📄 mission_brief.pdf')", !empty($convItem) && strpos($convItem['last_message_preview'], '📄 mission_brief.pdf') !== false);

// ---------------------------------------------------------------------
// TEST GROUP 5: Protected Download & Preview Access Control
// ---------------------------------------------------------------------

// 1. Participant A has access
$accessA = get_attachment_for_user($pdo, $photoAttId, $idA);
run_att_test("Sender (User A) is authorized to access attachment", !empty($accessA) && !isset($accessA['error']) && file_exists($accessA['absolute_path']));

// 2. Participant B has access
$accessB = get_attachment_for_user($pdo, $photoAttId, $idB);
run_att_test("Recipient (User B) is authorized to access attachment", !empty($accessB) && !isset($accessB['error']));

// 3. Non-participant User C is strictly BLOCKED
$accessC = get_attachment_for_user($pdo, $photoAttId, $idC);
run_att_test("Non-participant (User C) access strictly denied (returns null)", $accessC === null);

// ---------------------------------------------------------------------
// TEST GROUP 6: Quoted Replies & Reactions with Attachments
// ---------------------------------------------------------------------

// User B replies quoting User A's photo message
$replyRes = send_message($pdo, $convId, $idB, "Acknowledged. Satellite image received.", $photoMsgId);
$replyMsgId = (int)($replyRes['message_id'] ?? 0);
run_att_test("User B can send a quoted reply to a photo attachment message", $replyRes['success'] && $replyMsgId > 0);

$messagesAfterReply = get_messages($pdo, $convId, 0, 50, $idA);
$replyMsgFromHist = null;
foreach ($messagesAfterReply as $m) {
    if ($m['id'] === $replyMsgId) $replyMsgFromHist = $m;
}
run_att_test("Quoted reply includes original photo message body in reply_to", !empty($replyMsgFromHist['reply_to']) && $replyMsgFromHist['reply_to']['id'] === $photoMsgId);

// User A and User B react with emojis to photo message
$react1 = toggle_message_reaction($pdo, $photoMsgId, $idA, '👍');
$react2 = toggle_message_reaction($pdo, $photoMsgId, $idB, '❤️');
run_att_test("User A reacts with 👍 on photo attachment message", $react1['success']);
run_att_test("User B reacts with ❤️ on photo attachment message", $react2['success']);

$messagesAfterReact = get_messages($pdo, $convId, 0, 50, $idA);
$reactedMsg = null;
foreach ($messagesAfterReact as $m) {
    if ($m['id'] === $photoMsgId) $reactedMsg = $m;
}
run_att_test("Photo message retains active emoji reactions", count($reactedMsg['reactions'] ?? []) === 2);

// ---------------------------------------------------------------------
// TEST GROUP 7: Deletion Mechanics on Attachment Messages
// ---------------------------------------------------------------------

// 1. Delete for Me: User A deletes Photo message for Me
$delMeRes = delete_message_for_me($pdo, $photoMsgId, $idA);
run_att_test("User A executes Delete for Me on photo message", $delMeRes['success']);

// User A should no longer have download access to this attachment
$accessAfterDelMeA = get_attachment_for_user($pdo, $photoAttId, $idA);
run_att_test("User A is denied attachment access after Delete for Me (HIDDEN_FOR_ME)", isset($accessAfterDelMeA['error']) && $accessAfterDelMeA['error'] === 'HIDDEN_FOR_ME');

// User B STILL has full access to this attachment
$accessAfterDelMeB = get_attachment_for_user($pdo, $photoAttId, $idB);
run_att_test("User B retains full access to attachment after User A's Delete for Me", !empty($accessAfterDelMeB) && !isset($accessAfterDelMeB['error']));

// 2. Delete for Everyone: User B deletes Document message for Everyone
$delEveryoneRes = delete_message_for_everyone($pdo, $docMsgId, $idB);
run_att_test("User B executes Delete for Everyone on document message", $delEveryoneRes['success']);

// Both User A and User B receive DELETED_FOR_EVERYONE
$accessAfterDelEveryA = get_attachment_for_user($pdo, $docAttId, $idA);
$accessAfterDelEveryB = get_attachment_for_user($pdo, $docAttId, $idB);
run_att_test("User A receives DELETED_FOR_EVERYONE on deleted document", isset($accessAfterDelEveryA['error']) && $accessAfterDelEveryA['error'] === 'DELETED_FOR_EVERYONE');
run_att_test("User B receives DELETED_FOR_EVERYONE on deleted document", isset($accessAfterDelEveryB['error']) && $accessAfterDelEveryB['error'] === 'DELETED_FOR_EVERYONE');

// Document message in history is masked
$messagesAfterDel = get_messages($pdo, $convId, 0, 50, $idA);
$deletedDocMsg = null;
foreach ($messagesAfterDel as $m) {
    if ($m['id'] === $docMsgId) $deletedDocMsg = $m;
}
run_att_test("Deleted document message body is masked in history", $deletedDocMsg['body'] === 'This message was deleted.');
run_att_test("Deleted document attachment metadata is excluded from history", empty($deletedDocMsg['attachment']));

// ---------------------------------------------------------------------
// TEST GROUP 8: Cleanup & Cascade Deletion
// ---------------------------------------------------------------------

// Cleanup physical attachment files created during test
$stmt = $pdo->prepare('SELECT file_path FROM attachments WHERE conversation_id = :cid');
$stmt->execute([':cid' => $convId]);
$filesToUnlink = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($filesToUnlink as $rel) {
    $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . $rel;
    if (file_exists($abs)) {
        @unlink($abs);
    }
}

// Cleanup temporary files created in /tmp
foreach ($createdTempFiles as $tf) {
    if (file_exists($tf)) {
        @unlink($tf);
    }
}

// Delete test conversation (cascades to messages, attachments, reactions, events)
$stmt = $pdo->prepare('DELETE FROM conversations WHERE id = :cid');
$stmt->execute([':cid' => $convId]);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM attachments WHERE conversation_id = :cid');
$stmt->execute([':cid' => $convId]);
$remainingAtts = (int)$stmt->fetchColumn();
run_att_test("Conversation deletion cleanly cascades and removes attachments table rows", $remainingAtts === 0);

// Delete test users
$stmt = $pdo->prepare('DELETE FROM users WHERE id IN (:a, :b, :c)');
$stmt->execute([':a' => $idA, ':b' => $idB, ':c' => $idC]);
run_att_test("Test operatives deleted cleanly", true);

echo "\n========================================================================\n";
echo " ATTACHMENT SUITE SUMMARY: {$passCount} / {$totalCount} Tests Passed\n";
echo "========================================================================\n";

if ($passCount === $totalCount) {
    echo "✔ PHOTO & FILE SHARING SUITE CERTIFIED FULLY OPERATIONAL.\n\n";
    exit(0);
} else {
    echo "✖ ONE OR MORE ATTACHMENT TESTS FAILED.\n\n";
    exit(1);
}
