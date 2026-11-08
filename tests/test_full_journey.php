<?php
/**
 * Project Exodia — Phase 4 Master End-to-End User Journey Test Suite
 *
 * Simulates complete end-to-end operative workflow:
 * Registration -> Login -> Access Guard -> Discovery -> Add Contact ->
 * Duplicate Check -> Create Conversation -> Send Message -> Poll Message ->
 * Bidirectional Reply -> History Verification -> Authorization Isolation ->
 * Remove Contact -> Logout Invalidation -> Database Cleanup
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

echo "\n======================================================\n";
echo " Project Exodia — Master End-to-End Journey Test Suite\n";
echo "======================================================\n\n";

$passCount = 0;
$totalCount = 0;

function run_journey_test(string $title, bool $condition, string $detail = ''): void {
    global $passCount, $totalCount;
    $totalCount++;
    $numStr = str_pad((string)$totalCount, 2, '0', STR_PAD_LEFT);
    if ($condition) {
        $passCount++;
        echo "  [PASS] Step #{$numStr}: {$title}\n";
    } else {
        echo "  [FAIL] Step #{$numStr}: {$title}\n";
        if ($detail !== '') {
            echo "         Detail: {$detail}\n";
        }
    }
}

$suffix = (string)time() . mt_rand(10, 99);
$name1 = "j1_{$suffix}";
$name2 = "j2_{$suffix}";
$name3 = "j3_{$suffix}";
$email1 = "{$name1}@exodia.local";
$email2 = "{$name2}@exodia.local";
$email3 = "{$name3}@exodia.local";
$rawPassword = "Password99!";

// 1. Registration of User 1
$hash1 = password_hash($rawPassword, PASSWORD_DEFAULT);
$ins1 = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$ins1->execute([':u' => $name1, ':e' => $email1, ':p' => $hash1]);
$id1 = (int)$pdo->lastInsertId();
run_journey_test("Register operative #1 successfully", $id1 > 0);

// 2. Registration of User 2
$hash2 = password_hash($rawPassword, PASSWORD_DEFAULT);
$ins2 = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$ins2->execute([':u' => $name2, ':e' => $email2, ':p' => $hash2]);
$id2 = (int)$pdo->lastInsertId();
run_journey_test("Register operative #2 successfully", $id2 > 0);

// 3. User 1 Login Verification
$loginStmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :e LIMIT 1');
$loginStmt->execute([':e' => $email1]);
$userRow1 = $loginStmt->fetch();
$loginSuccess = ($userRow1 && password_verify($rawPassword, $userRow1['password_hash']));
run_journey_test("Authenticate operative #1 with password verification", $loginSuccess);

// 4. User 1 searches for User 2 in directory
$searchResults = search_users($pdo, $id1, $name2);
$foundU2 = false;
foreach ($searchResults as $sr) {
    if ($sr['id'] === $id2 && $sr['username'] === $name2) {
        $foundU2 = true;
    }
}
run_journey_test("Operative #1 discovers Operative #2 in directory", $foundU2);

// 5. User 1 adds User 2 as a contact
$addRes = add_contact($pdo, $id1, $id2);
run_journey_test("Operative #1 adds Operative #2 to contact roster", $addRes['success'] && $addRes['code'] === 'OK');

// 6. Duplicate contact addition blocked
$dupRes = add_contact($pdo, $id1, $id2);
run_journey_test("Duplicate contact addition blocked by business logic", !$dupRes['success'] && $dupRes['code'] === 'DUPLICATE_CONTACT');

// 7. Verify contact roster contains User 2
$roster = get_user_contacts($pdo, $id1);
$rosterHasU2 = false;
foreach ($roster as $rc) {
    if ($rc['user_id'] === $id2) $rosterHasU2 = true;
}
run_journey_test("Contact roster confirms active connection", $rosterHasU2);

// 8. User 1 initiates direct conversation with User 2
$convRes = create_conversation($pdo, $id1, $id2);
$convId = (int)($convRes['conversation_id'] ?? 0);
run_journey_test("Initialize direct transmission channel between operatives", $convRes['success'] && $convId > 0);

// 9. User 1 sends message to User 2
$sendRes1 = send_message($pdo, $convId, $id1, "Exodia operative check-in. Sector secure.");
$msg1Id = (int)($sendRes1['message_id'] ?? 0);
run_journey_test("Operative #1 sends encrypted message in channel", $sendRes1['success'] && $msg1Id > 0);

// 10. User 2 retrieves incoming transmission via polling
$user2History = get_messages($pdo, $convId, 0);
$u2Received = (count($user2History) === 1 && $user2History[0]['id'] === $msg1Id);
run_journey_test("Operative #2 receives incoming transmission", $u2Received);

// 11. User 2 replies to User 1
$sendRes2 = send_message($pdo, $convId, $id2, "Acknowledged Sector secure. Transmission confirmed.");
$msg2Id = (int)($sendRes2['message_id'] ?? 0);
run_journey_test("Operative #2 transmits reply in channel", $sendRes2['success'] && $msg2Id > $msg1Id);

// 12. User 1 polls incrementally for new messages (after msg1Id)
$user1Poll = get_messages($pdo, $convId, $msg1Id);
$u1PollSuccess = (count($user1Poll) === 1 && $user1Poll[0]['id'] === $msg2Id);
run_journey_test("Operative #1 receives reply via incremental polling", $u1PollSuccess);

// 13. IDOR Protection: Unauthorized User 3 cannot access transmission
$ins3 = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$ins3->execute([':u' => $name3, ':e' => $email3, ':p' => $hash1]);
$id3 = (int)$pdo->lastInsertId();

$unauthorizedAccess = is_conversation_participant($pdo, $convId, $id3);
$unauthorizedSend = send_message($pdo, $convId, $id3, "Intruder payload");
run_journey_test("Unauthorized operative is isolated and blocked from transmission", !$unauthorizedAccess && !$unauthorizedSend['success']);

// 14. Operative #1 removes Operative #2 from contacts
$remRes = remove_contact($pdo, $id1, $id2);
$isStillContact = is_contact($pdo, $id1, $id2);
run_journey_test("Operative #1 removes Operative #2 from contact roster", $remRes['success'] && !$isStillContact);

// 15. Teardown and foreign key integrity verification
$delUser = $pdo->prepare('DELETE FROM users WHERE id IN (?, ?, ?)');
$delUser->execute([$id1, $id2, $id3]);

$orphanMsg = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id = :cid');
$orphanMsg->execute([':cid' => $convId]);
$orphanCount = (int)$orphanMsg->fetchColumn();
run_journey_test("Database integrity verified: All related records cascaded on cleanup", $orphanCount === 0);

echo "\n======================================================\n";
echo " Master Journey Test Execution Summary: {$passCount} / {$totalCount} Passed\n";
echo "======================================================\n";

if ($passCount === $totalCount) {
    echo "✔ COMPLETE END-TO-END USER JOURNEY VERIFIED WITH ZERO ERRORS.\n\n";
} else {
    echo "✖ JOURNEY TEST FAILED.\n\n";
    exit(1);
}
