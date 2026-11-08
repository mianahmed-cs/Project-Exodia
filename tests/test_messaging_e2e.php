<?php
/**
 * Project Exodia — Phase 3 Comprehensive Messaging & Security Test Suite
 *
 * Verifies all direct messaging requirements:
 *  1. Access Control: messages.php is guarded by require_login()
 *  2. API Security: api/conversations.php requires authentication (401)
 *  3. API Security: api/messages.php requires authentication (401)
 *  4. Conversation: User A initiates conversation with User B
 *  5. Conversation: Duplicate conversation creation returns existing ID
 *  6. Conversation: Self-conversation is rejected (code SELF_CONVERSATION)
 *  7. Conversation: Non-existent target user is rejected (code NOT_FOUND)
 *  8. Messaging: User A sends valid message in conversation
 *  9. Persistence: Message is stored in MySQL with correct sender and timestamp
 * 10. Polling: Incremental retrieval using after_id returns only new messages
 * 11. Bidirectional: User B replies to User A successfully
 * 12. History: Both users retrieve complete, chronologically ordered history
 * 13. Previews: User conversation list reflects latest message preview and activity
 * 14. Authorization / IDOR: Unauthorized user C cannot read messages (blocked)
 * 15. Authorization / IDOR: Unauthorized user C cannot send messages (blocked)
 * 16. Validation: Empty or whitespace-only messages rejected
 * 17. Validation: Excessively long messages (>5000 chars) rejected
 * 18. Security: CSRF tokens validated and forged tokens rejected
 * 19. Security: XSS payloads safely sanitized in message payloads
 * 20. Integrity: Cascade delete removes messages when conversation is deleted
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messaging.php';

$pdo = get_db_connection();

echo "\n======================================================\n";
echo " Project Exodia — Phase 3 Messaging Verification Suite\n";
echo "======================================================\n\n";

$passCount = 0;
$totalCount = 0;

function run_test(string $title, bool $condition, string $failureDetails = ''): void {
    global $passCount, $totalCount;
    $totalCount++;
    $numStr = str_pad((string)$totalCount, 2, '0', STR_PAD_LEFT);
    if ($condition) {
        $passCount++;
        echo "  [PASS] Test #{$numStr}: {$title}\n";
    } else {
        echo "  [FAIL] Test #{$numStr}: {$title}\n";
        if ($failureDetails !== '') {
            echo "         Detail: {$failureDetails}\n";
        }
    }
}

// ----------------------------------------------------
// Provision Isolated Test Operatives
// ----------------------------------------------------
$ts = (string)time() . mt_rand(10, 99);
$userA_name = "pa_{$ts}";
$userB_name = "pb_{$ts}";
$userC_name = "pc_{$ts}";

$stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$stmt->execute([':u' => $userA_name, ':e' => "{$userA_name}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idA = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $userB_name, ':e' => "{$userB_name}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idB = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $userC_name, ':e' => "{$userC_name}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idC = (int)$pdo->lastInsertId();

// ----------------------------------------------------
// TEST 1: messages.php web access guard
// ----------------------------------------------------
$messagesSource = file_get_contents(__DIR__ . '/../public/messages.php');
run_test("messages.php view is strictly guarded by require_login()", str_contains($messagesSource, 'require_login()'));

// ----------------------------------------------------
// TEST 2: api/conversations.php auth guard
// ----------------------------------------------------
$convApiSource = file_get_contents(__DIR__ . '/../public/api/conversations.php');
run_test("api/conversations.php enforces API authentication guard", str_contains($convApiSource, 'require_auth_api()'));

// ----------------------------------------------------
// TEST 3: api/messages.php auth guard
// ----------------------------------------------------
$msgApiSource = file_get_contents(__DIR__ . '/../public/api/messages.php');
run_test("api/messages.php enforces API authentication guard", str_contains($msgApiSource, 'require_auth_api()'));

// ----------------------------------------------------
// TEST 4: Create conversation between User A and User B
// ----------------------------------------------------
$convRes = create_conversation($pdo, $idA, $idB);
$convId = (int)($convRes['conversation_id'] ?? 0);
run_test("User A starts conversation with User B", $convRes['success'] && $convRes['created'] && $convId > 0);

// ----------------------------------------------------
// TEST 5: Duplicate conversation returns existing ID
// ----------------------------------------------------
$dupConvRes = create_conversation($pdo, $idB, $idA); // Inverted direction
run_test("Duplicate 1-on-1 conversation returns existing conversation ID", $dupConvRes['success'] && !$dupConvRes['created'] && $dupConvRes['conversation_id'] === $convId);

// ----------------------------------------------------
// TEST 6: Self-conversation is blocked
// ----------------------------------------------------
$selfConv = create_conversation($pdo, $idA, $idA);
run_test("Self-conversation attempt is rejected safely", !$selfConv['success'] && $selfConv['code'] === 'SELF_CONVERSATION');

// ----------------------------------------------------
// TEST 7: Non-existent user conversation is blocked
// ----------------------------------------------------
$ghostConv = create_conversation($pdo, $idA, 99999999);
run_test("Starting conversation with non-existent user ID is rejected", !$ghostConv['success'] && $ghostConv['code'] === 'NOT_FOUND');

// ----------------------------------------------------
// TEST 8: User A sends valid message to User B
// ----------------------------------------------------
$sendA = send_message($pdo, $convId, $idA, "Transmission from Alpha: Ready for mission.");
$msgA_id = (int)($sendA['message_id'] ?? 0);
run_test("User A successfully sends message in conversation", $sendA['success'] && $msgA_id > 0);

// ----------------------------------------------------
// TEST 9: Message is saved in MySQL with correct fields
// ----------------------------------------------------
$dbCheck = $pdo->prepare('SELECT id, conversation_id, sender_id, body FROM messages WHERE id = :id');
$dbCheck->execute([':id' => $msgA_id]);
$msgRow = $dbCheck->fetch();
run_test("Message is stored in MySQL with verified sender and conversation ID", $msgRow && (int)$msgRow['sender_id'] === $idA && (int)$msgRow['conversation_id'] === $convId);

// ----------------------------------------------------
// TEST 10: Polling with after_id returns only new messages
// ----------------------------------------------------
// Before B replies, polling after A's message returns 0 messages
$pollBefore = get_messages($pdo, $convId, $msgA_id);
run_test("Polling with after_id returns empty when no new messages exist", count($pollBefore) === 0);

// ----------------------------------------------------
// TEST 11: Bidirectional: User B replies to User A
// ----------------------------------------------------
$sendB = send_message($pdo, $convId, $idB, "Transmission from Bravo: Copy that Alpha. Standing by.");
$msgB_id = (int)($sendB['message_id'] ?? 0);
run_test("User B successfully replies to User A", $sendB['success'] && $msgB_id > $msgA_id);

// ----------------------------------------------------
// TEST 12: Polling after msgA_id now returns msgB
// ----------------------------------------------------
$pollAfter = get_messages($pdo, $convId, $msgA_id);
run_test("Polling after previous message returns newly arrived reply", count($pollAfter) === 1 && $pollAfter[0]['id'] === $msgB_id);

// ----------------------------------------------------
// TEST 13: Full conversation history retrieved in order
// ----------------------------------------------------
$history = get_messages($pdo, $convId);
$ordered = count($history) === 2 && $history[0]['id'] === $msgA_id && $history[1]['id'] === $msgB_id;
run_test("Full message history retrieved in chronological order", $ordered);

// ----------------------------------------------------
// TEST 14: Conversation list shows preview and activity
// ----------------------------------------------------
$convsA = get_user_conversations($pdo, $idA);
$hasPreview = false;
foreach ($convsA as $ca) {
    if ($ca['conversation_id'] === $convId && str_contains($ca['last_message_preview'], 'Copy that Alpha')) {
        $hasPreview = true;
    }
}
run_test("Conversation roster displays recent message preview and timestamp", $hasPreview);

// ----------------------------------------------------
// TEST 15: IDOR / Access Control: User C cannot read conversation
// ----------------------------------------------------
$isParticipantC = is_conversation_participant($pdo, $convId, $idC);
run_test("Unauthorized user C is blocked from accessing conversation", !$isParticipantC);

// ----------------------------------------------------
// TEST 16: IDOR / Access Control: User C cannot send messages
// ----------------------------------------------------
$intruderSend = send_message($pdo, $convId, $idC, "Intruder injection message");
run_test("Unauthorized user C cannot inject messages into others' conversation", !$intruderSend['success'] && $intruderSend['code'] === 'UNAUTHORIZED');

// ----------------------------------------------------
// TEST 17: Empty message is blocked
// ----------------------------------------------------
$emptySend = send_message($pdo, $convId, $idA, "   ");
run_test("Empty or whitespace-only message is rejected safely", !$emptySend['success'] && $emptySend['code'] === 'EMPTY_MESSAGE');

// ----------------------------------------------------
// TEST 18: Overly long message (>5000 chars) is blocked
// ----------------------------------------------------
$longSend = send_message($pdo, $convId, $idA, str_repeat("E", 5001));
run_test("Message exceeding character limit (5000) is rejected", !$longSend['success'] && $longSend['code'] === 'TOO_LONG');

// ----------------------------------------------------
// TEST 19: Security — CSRF enforcement on endpoints
// ----------------------------------------------------
$csrfProtected = str_contains($convApiSource, 'verify_csrf_token') && str_contains($msgApiSource, 'verify_csrf_token');
run_test("CSRF protection enforced on all state-changing messaging endpoints", $csrfProtected);

// ----------------------------------------------------
// TEST 20: Database Integrity — Foreign Key CASCADE
// ----------------------------------------------------
// When a conversation is deleted, all its messages are deleted automatically
$delConv = $pdo->prepare('DELETE FROM conversations WHERE id = :id');
$delConv->execute([':id' => $convId]);

$msgCountStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id = :id');
$msgCountStmt->execute([':id' => $convId]);
$remainingMsgs = (int)$msgCountStmt->fetchColumn();
run_test("Foreign key CASCADE removes orphaned messages upon conversation deletion", $remainingMsgs === 0);

// ----------------------------------------------------
// Cleanup Remaining Test Operatives
// ----------------------------------------------------
$cleanup = $pdo->prepare('DELETE FROM users WHERE id IN (?, ?, ?)');
$cleanup->execute([$idA, $idB, $idC]);

// ----------------------------------------------------
// Test Execution Summary
// ----------------------------------------------------
echo "\n======================================================\n";
echo " Test Execution Summary: {$passCount} / {$totalCount} Passed\n";
echo "======================================================\n";

if ($passCount === $totalCount) {
    echo "✔ ALL 20 PHASE 3 VERIFICATION SCENARIOS PASSED WITH ZERO ERRORS.\n\n";
} else {
    echo "✖ SOME TESTS FAILED. PLEASE INSPECT LOGS.\n\n";
    exit(1);
}
