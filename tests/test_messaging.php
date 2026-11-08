<?php
/**
 * Project Exodia — Phase 3 Messaging Service Test Suite
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/messaging.php';

$pdo = get_db_connection();

echo "\n======================================================\n";
echo " Project Exodia — Test Suite: Messaging Service\n";
echo "======================================================\n\n";

$p = 0; $t = 0;
function chk(string $n, bool $c, string $d = ''): void {
    global $p, $t; $t++;
    if ($c) { $p++; echo "  [PASS] #{$t}: {$n}\n"; }
    else { echo "  [FAIL] #{$t}: {$n} - {$d}\n"; }
}

// Setup test users
$ts = time() . '_' . mt_rand(100,999);
$st = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$st->execute([':u'=>"msg_a_{$ts}",':e'=>"msg_a_{$ts}@test.local",':p'=>password_hash('Test1234',PASSWORD_DEFAULT)]);
$a = (int)$pdo->lastInsertId();
$st->execute([':u'=>"msg_b_{$ts}",':e'=>"msg_b_{$ts}@test.local",':p'=>password_hash('Test1234',PASSWORD_DEFAULT)]);
$b = (int)$pdo->lastInsertId();
$st->execute([':u'=>"msg_c_{$ts}",':e'=>"msg_c_{$ts}@test.local",':p'=>password_hash('Test1234',PASSWORD_DEFAULT)]);
$c = (int)$pdo->lastInsertId();

// T1: Create conversation
$r = create_conversation($pdo, $a, $b);
chk("Create conversation between two users", $r['success'] && $r['created'] === true);
$convId = $r['conversation_id'] ?? 0;

// T2: Duplicate conversation prevention
$r2 = create_conversation($pdo, $a, $b);
chk("Duplicate conversation returns existing", $r2['success'] && $r2['created'] === false && $r2['conversation_id'] === $convId);

// T3: Self conversation blocked
$r3 = create_conversation($pdo, $a, $a);
chk("Self-conversation is blocked", !$r3['success'] && $r3['code'] === 'SELF_CONVERSATION');

// T4: Non-existent user
$r4 = create_conversation($pdo, $a, 99999999);
chk("Conversation with non-existent user blocked", !$r4['success'] && $r4['code'] === 'NOT_FOUND');

// T5: Participant check
chk("User A is participant", is_conversation_participant($pdo, $convId, $a));
chk("User B is participant", is_conversation_participant($pdo, $convId, $b));
chk("User C is NOT participant", !is_conversation_participant($pdo, $convId, $c));

// T8: Send message
$sm = send_message($pdo, $convId, $a, 'Hello from A!');
chk("Send message succeeds", $sm['success'] && $sm['message_id'] > 0);

// T9: Empty message blocked
$em = send_message($pdo, $convId, $a, '   ');
chk("Empty message blocked", !$em['success'] && $em['code'] === 'EMPTY_MESSAGE');

// T10: Unauthorized sender blocked
$um = send_message($pdo, $convId, $c, 'Intruder');
chk("Unauthorized sender blocked", !$um['success'] && $um['code'] === 'UNAUTHORIZED');

// T11: Send reply from B
$sm2 = send_message($pdo, $convId, $b, 'Reply from B!');
chk("Reply from B succeeds", $sm2['success']);

// T12: Retrieve history
$msgs = get_messages($pdo, $convId);
chk("History returns both messages in order", count($msgs) === 2 && $msgs[0]['body'] === 'Hello from A!' && $msgs[1]['body'] === 'Reply from B!');

// T13: Polling with after_id
$newMsgs = get_messages($pdo, $convId, $sm['message_id']);
chk("Polling after_id returns only newer messages", count($newMsgs) === 1 && $newMsgs[0]['body'] === 'Reply from B!');

// T14: Conversation list
$list = get_user_conversations($pdo, $a);
$found = false;
foreach ($list as $cv) { if ($cv['conversation_id'] === $convId) { $found = true; break; } }
chk("Conversation list includes active conversation with preview", $found);

// T15: Too long message
$longMsg = send_message($pdo, $convId, $a, str_repeat('x', 5001));
chk("Message exceeding 5000 chars blocked", !$longMsg['success'] && $longMsg['code'] === 'TOO_LONG');

// T16: SQL injection in message body handled safely
$sqli = send_message($pdo, $convId, $a, "'; DROP TABLE messages; --");
chk("SQL injection in message body is safely stored as literal text", $sqli['success']);
$check = get_messages($pdo, $convId, $sm2['message_id']);
$safe = false;
foreach ($check as $cm) { if (str_contains($cm['body'], "DROP TABLE")) { $safe = true; } }
chk("Injected SQL text stored literally without execution", $safe);

// Cleanup
$pdo->prepare('DELETE FROM users WHERE id IN (?,?,?)')->execute([$a, $b, $c]);

echo "\n======================================================\n";
echo " Summary: {$p} / {$t} messaging tests passed.\n";
echo "======================================================\n";
if ($p !== $t) exit(1);
