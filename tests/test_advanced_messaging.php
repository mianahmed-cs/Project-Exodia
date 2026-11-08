<?php
/**
 * Project Exodia — Advanced Messaging Verification Suite
 *
 * Verifies:
 *  1. Delete for Me: Hides for current user, remains visible for recipient, persists in MySQL.
 *  2. Delete for Everyone: Sender only, recipient forbidden, replaces body with "This message was deleted.", masks API output.
 *  3. Quoted Replies: Links to original message in same conversation, includes preview & author, blocks replying to deleted messages.
 *  4. Emoji Reactions: Allowed 6 emojis (👍 ❤️ 😂 😮 😢 😡), single reaction per user per message, toggle off, change emoji, blocks deleted messages.
 *  5. Real-Time Event Sync: Monotonic conversation_events table captures message creations, deletions, and reactions for incremental polling.
 *  6. Conversation Preview: Respects hidden and deleted messages in sidebar roster.
 *  7. Authorization & Security: Rejects non-participants, prevents tampering, cascades foreign keys cleanly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contacts.php';
require_once __DIR__ . '/../includes/messaging.php';

$pdo = get_db_connection();

echo "\n========================================================================\n";
echo " Project Exodia — Advanced Messaging Test Suite (Delete, Reply, React)\n";
echo "========================================================================\n\n";

$passCount = 0;
$totalCount = 0;

function run_adv_test(string $title, bool $condition, string $detail = ''): void {
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
$userA_name = "adv_a_{$ts}";
$userB_name = "adv_b_{$ts}";
$userC_name = "adv_c_{$ts}";
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
run_adv_test("Setup conversation between User A and User B", $convId > 0);

// ---------------------------------------------------------------------
// FEATURE 1: Delete Messages (For Me & For Everyone)
// ---------------------------------------------------------------------

// Send Message 1
$msg1Res = send_message($pdo, $convId, $idA, "Message 1 from User A");
$msg1Id = (int)$msg1Res['message_id'];
run_adv_test("User A sends Message 1", $msg1Id > 0);

// Send Message 2
$msg2Res = send_message($pdo, $convId, $idA, "Message 2 from User A for Everyone");
$msg2Id = (int)$msg2Res['message_id'];
run_adv_test("User A sends Message 2", $msg2Id > 0);

// Test Delete for Me: User A deletes Message 1 for Me
$delMeRes = delete_message_for_me($pdo, $msg1Id, $idA);
run_adv_test("User A executes Delete for Me on Message 1", $delMeRes['success']);

// Verify User A does NOT see Message 1 in history
$historyA = get_messages($pdo, $convId, 0, 100, $idA);
$m1InA = array_filter($historyA, fn($m) => $m['id'] === $msg1Id);
run_adv_test("Message 1 is hidden from User A's history retrieval", count($m1InA) === 0);

// Verify User B STILL sees Message 1 in history
$historyB = get_messages($pdo, $convId, 0, 100, $idB);
$m1InB = array_values(array_filter($historyB, fn($m) => $m['id'] === $msg1Id));
run_adv_test("Message 1 remains visible to User B with original content", 
    count($m1InB) === 1 && $m1InB[0]['body'] === "Message 1 from User A"
);

// Test Delete for Everyone: Recipient (User B) cannot delete sender's message for everyone
$badDelRes = delete_message_for_everyone($pdo, $msg2Id, $idB);
run_adv_test("Recipient User B blocked from deleting User A's message for everyone", 
    !$badDelRes['success'] && $badDelRes['code'] === 'FORBIDDEN'
);

// Test Delete for Everyone: Original Sender (User A) deletes Message 2 for everyone
$delEvRes = delete_message_for_everyone($pdo, $msg2Id, $idA);
run_adv_test("Sender User A successfully deletes Message 2 for everyone", $delEvRes['success']);

// Verify both User A and User B receive placeholder "This message was deleted."
$historyA2 = get_messages($pdo, $convId, 0, 100, $idA);
$m2InA = array_values(array_filter($historyA2, fn($m) => $m['id'] === $msg2Id));
$historyB2 = get_messages($pdo, $convId, 0, 100, $idB);
$m2InB = array_values(array_filter($historyB2, fn($m) => $m['id'] === $msg2Id));

run_adv_test("Both users receive 'This message was deleted.' placeholder for Message 2",
    count($m2InA) === 1 && $m2InA[0]['body'] === 'This message was deleted.' &&
    count($m2InB) === 1 && $m2InB[0]['body'] === 'This message was deleted.' &&
    $m2InA[0]['is_deleted_for_everyone'] === true &&
    $m2InB[0]['is_deleted_for_everyone'] === true
);

// Verify database body column was purged to prevent any data exposure
$dbCheck = $pdo->prepare("SELECT body, is_deleted_for_everyone FROM messages WHERE id = :id");
$dbCheck->execute([':id' => $msg2Id]);
$dbRow = $dbCheck->fetch(PDO::FETCH_ASSOC);
run_adv_test("Database message body purged on delete for everyone", 
    $dbRow['body'] === '' && (int)$dbRow['is_deleted_for_everyone'] === 1
);

// ---------------------------------------------------------------------
// FEATURE 2: Quoted Replies
// ---------------------------------------------------------------------

// Send Message 3: Normal message from User B
$msg3Res = send_message($pdo, $convId, $idB, "Are you available tomorrow?");
$msg3Id = (int)$msg3Res['message_id'];
run_adv_test("User B sends message 3 ('Are you available tomorrow?')", $msg3Id > 0);

// User A replies to Message 3
$replyRes = send_message($pdo, $convId, $idA, "Yes, I will be there!", $msg3Id);
$replyId = (int)$replyRes['message_id'];
run_adv_test("User A replies quoting Message 3", $replyRes['success'] && $replyId > 0);

// Verify retrieved reply includes original sender and body preview
$historyAfterReply = get_messages($pdo, $convId, 0, 100, $idA);
$replyInHistory = array_values(array_filter($historyAfterReply, fn($m) => $m['id'] === $replyId));
run_adv_test("Quoted reply includes original author and message preview", 
    count($replyInHistory) === 1 &&
    $replyInHistory[0]['reply_to'] !== null &&
    $replyInHistory[0]['reply_to']['id'] === $msg3Id &&
    $replyInHistory[0]['reply_to']['sender_username'] === $userB_name &&
    $replyInHistory[0]['reply_to']['body'] === "Are you available tomorrow?"
);

// Attempt to reply to a deleted message (Message 2) -> Must be blocked
$badReplyDeleted = send_message($pdo, $convId, $idB, "Trying to reply to deleted message", $msg2Id);
run_adv_test("Replying to a message deleted for everyone is rejected", 
    !$badReplyDeleted['success'] && $badReplyDeleted['code'] === 'REPLY_TO_DELETED'
);

// Attempt to reply to a non-existent or foreign conversation message -> Must be blocked
$badReplyForeign = send_message($pdo, $convId, $idB, "Trying to reply to foreign message", 99999999);
run_adv_test("Replying to an invalid message ID is rejected", 
    !$badReplyForeign['success'] && $badReplyForeign['code'] === 'INVALID_REPLY'
);

// ---------------------------------------------------------------------
// FEATURE 3: Emoji Reactions
// ---------------------------------------------------------------------

// User A reacts with ❤️ on Message 3
$reactA = toggle_message_reaction($pdo, $msg3Id, $idA, '❤️');
run_adv_test("User A adds ❤️ reaction to Message 3", 
    $reactA['success'] && $reactA['action'] === 'added' && $reactA['user_reaction'] === '❤️'
);

// Verify reaction shows up in history for both users
$checkReactionsA = get_messages($pdo, $convId, 0, 100, $idA);
$m3A = array_values(array_filter($checkReactionsA, fn($m) => $m['id'] === $msg3Id))[0];
$checkReactionsB = get_messages($pdo, $convId, 0, 100, $idB);
$m3B = array_values(array_filter($checkReactionsB, fn($m) => $m['id'] === $msg3Id))[0];

run_adv_test("Reaction visible to both users with correct counts and has_reacted flags",
    count($m3A['reactions']) === 1 &&
    $m3A['reactions'][0]['emoji'] === '❤️' &&
    $m3A['reactions'][0]['count'] === 1 &&
    $m3A['reactions'][0]['has_reacted'] === true &&
    count($m3B['reactions']) === 1 &&
    $m3B['reactions'][0]['has_reacted'] === false
);

// User B reacts with 👍 on Message 3
$reactB = toggle_message_reaction($pdo, $msg3Id, $idB, '👍');
run_adv_test("User B adds 👍 reaction to Message 3", $reactB['success']);

// User A changes reaction from ❤️ to 😂
$changeA = toggle_message_reaction($pdo, $msg3Id, $idA, '😂');
run_adv_test("User A changes reaction from ❤️ to 😂", 
    $changeA['success'] && $changeA['action'] === 'updated' && $changeA['user_reaction'] === '😂'
);

// User A toggles off 😂 by clicking 😂 again
$toggleOffA = toggle_message_reaction($pdo, $msg3Id, $idA, '😂');
run_adv_test("User A toggles off reaction by selecting same emoji again", 
    $toggleOffA['success'] && $toggleOffA['action'] === 'removed' && $toggleOffA['user_reaction'] === null
);

// Attempt unsupported emoji -> Must be blocked
$badEmoji = toggle_message_reaction($pdo, $msg3Id, $idA, '🚀');
run_adv_test("Unsupported emoji reaction rejected server-side", 
    !$badEmoji['success'] && $badEmoji['code'] === 'INVALID_EMOJI'
);

// Attempt reaction on message deleted for everyone -> Must be blocked
$badReactDeleted = toggle_message_reaction($pdo, $msg2Id, $idA, '❤️');
run_adv_test("Reaction on deleted message rejected", 
    !$badReactDeleted['success'] && $badReactDeleted['code'] === 'MESSAGE_DELETED'
);

// ---------------------------------------------------------------------
// FEATURE 4: Real-time Event Feed
// ---------------------------------------------------------------------
$events = get_conversation_events($pdo, $convId, 0, $idA);
run_adv_test("Activity events feed records message deletions, reactions, and creations",
    count($events) >= 4
);

// Verify event types exist in log
$eventTypes = array_column($events, 'event_type');
run_adv_test("Event feed contains message_created, message_deleted, and reaction_changed events",
    in_array('message_created', $eventTypes, true) &&
    in_array('message_deleted', $eventTypes, true) &&
    in_array('reaction_changed', $eventTypes, true)
);

// ---------------------------------------------------------------------
// FEATURE 5: Conversation Preview Behavior
// ---------------------------------------------------------------------
$convListA = get_user_conversations($pdo, $idA);
$convItemA = array_values(array_filter($convListA, fn($c) => $c['conversation_id'] === $convId))[0];
run_adv_test("Conversation preview reflects latest unhidden active message", 
    $convItemA['last_message_preview'] === "Yes, I will be there!"
);

// ---------------------------------------------------------------------
// FEATURE 6: Unauthorized Operative Isolation
// ---------------------------------------------------------------------
$unauthDelete = delete_message_for_me($pdo, $msg3Id, $idC);
run_adv_test("Unauthorized User C blocked from manipulating Message 3", !$unauthDelete['success']);

$unauthReact = toggle_message_reaction($pdo, $msg3Id, $idC, '👍');
run_adv_test("Unauthorized User C blocked from reacting to Message 3", !$unauthReact['success']);

// ---------------------------------------------------------------------
// Cleanup & Foreign Key Cascade Verification
// ---------------------------------------------------------------------
$delStmt = $pdo->prepare("DELETE FROM users WHERE id IN (:a, :b, :c)");
$delStmt->execute([':a' => $idA, ':b' => $idB, ':c' => $idC]);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = :cid");
$stmt->execute([':cid' => $convId]);
$remMsgs = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM user_hidden_messages WHERE message_id IN (:m1, :m2, :m3, :rep)");
$stmt->execute([':m1' => $msg1Id, ':m2' => $msg2Id, ':m3' => $msg3Id, ':rep' => $replyId]);
$remHidden = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM message_reactions WHERE message_id IN (:m1, :m2, :m3, :rep)");
$stmt->execute([':m1' => $msg1Id, ':m2' => $msg2Id, ':m3' => $msg3Id, ':rep' => $replyId]);
$remReactions = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM conversation_events WHERE conversation_id = :cid");
$stmt->execute([':cid' => $convId]);
$remEvents = (int)$stmt->fetchColumn();

run_adv_test("Foreign key CASCADE removes all messages, reactions, events, and hidden records cleanly",
    $remMsgs === 0 && $remHidden === 0 && $remReactions === 0 && $remEvents === 0
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n========================================================================\n";
echo " Advanced Messaging Execution Summary: {$passCount} / {$totalCount} Passed\n";
echo "========================================================================\n";

if ($passCount === $totalCount) {
    echo "✔ ALL ADVANCED MESSAGING SCENARIOS PASSED WITH ZERO ERRORS.\n\n";
    exit(0);
} else {
    echo "✖ SOME ADVANCED MESSAGING TESTS FAILED.\n\n";
    exit(1);
}
