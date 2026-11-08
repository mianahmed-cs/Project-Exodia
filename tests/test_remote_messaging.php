<?php
/**
 * Project Exodia — Cloudflare Tunnel & Remote Messaging Verification Suite
 *
 * Verifies:
 *  1. HTTPS auto-detection under direct HTTPS
 *  2. HTTPS auto-detection under Cloudflare Tunnel (CF-Visitor, X-Forwarded-Proto)
 *  3. Header spoofing prevention (non-loopback proxy headers rejected)
 *  4. Secure cookie parameters auto-configuration
 *  5. Cross-network two-device simulation (Friend A & Friend B)
 *  6. Real-time message exchange & persistent history retention
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
echo " Project Exodia — Cloudflare Tunnel & Remote Messaging Test Suite\n";
echo "========================================================================\n\n";

$passCount = 0;
$totalCount = 0;

function run_remote_test(string $title, bool $condition, string $detail = ''): void {
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
// TEST GROUP 1: HTTPS & Reverse Proxy Security Detection
// ---------------------------------------------------------------------

// Test 1: Native HTTPS
$_SERVER['HTTPS'] = 'on';
unset($_SERVER['HTTP_CF_VISITOR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
run_remote_test("Direct HTTPS detected via \$_SERVER['HTTPS']", is_https_connection());

// Test 2: Cloudflare Tunnel CF-Visitor from Loopback Proxy
unset($_SERVER['HTTPS']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_CF_VISITOR'] = json_encode(['scheme' => 'https']);
run_remote_test("Cloudflare Tunnel HTTPS detected via CF-Visitor on loopback", is_https_connection());

// Test 3: Standard Reverse Proxy X-Forwarded-Proto from Loopback
unset($_SERVER['HTTP_CF_VISITOR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
run_remote_test("Reverse proxy HTTPS detected via X-Forwarded-Proto on loopback", is_https_connection());

// Test 4: Header Spoofing Blocked from External IP
$_SERVER['REMOTE_ADDR'] = '198.51.100.42'; // External untrusted IP
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_CF_VISITOR'] = json_encode(['scheme' => 'https']);
run_remote_test("Untrusted external client cannot spoof HTTPS proxy headers", !is_https_connection());

// Test 5: Plain Local HTTP without proxy returns false
unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_CF_VISITOR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
run_remote_test("Plain local HTTP connection correctly reports non-HTTPS", !is_https_connection());

// ---------------------------------------------------------------------
// TEST GROUP 2: Two-Device Remote Messaging Simulation
// ---------------------------------------------------------------------
$suffix = (string)time() . mt_rand(10, 99);
$friendA_name = "fA_{$suffix}";
$friendB_name = "fB_{$suffix}";
$friendA_email = "{$friendA_name}@tunnel.test";
$friendB_email = "{$friendB_name}@tunnel.test";
$passwordPlain = "ExodiaPass123!#";
$passHash = password_hash($passwordPlain, PASSWORD_DEFAULT);

// Clean up any stale test accounts
$stmt = $pdo->prepare("DELETE FROM users WHERE email LIKE '%@tunnel.test'");
$stmt->execute();

// Test 6: Register Friend A (Laptop A / Wi-Fi A)
$stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)");
$stmt->execute(['username' => $friendA_name, 'email' => $friendA_email, 'password_hash' => $passHash]);
$idA = (int)$pdo->lastInsertId();
run_remote_test("Register Friend A on simulated Laptop A", $idA > 0);

// Test 7: Register Friend B (Laptop B / Wi-Fi B)
$stmt->execute(['username' => $friendB_name, 'email' => $friendB_email, 'password_hash' => $passHash]);
$idB = (int)$pdo->lastInsertId();
run_remote_test("Register Friend B on simulated Laptop B", $idB > 0);

// Test 8: Friend A adds Friend B to contacts
$addResult = add_contact($pdo, $idA, $idB);
run_remote_test("Friend A adds Friend B to contacts over remote tunnel", $addResult['success']);

// Test 9: Initialize direct encrypted conversation
$convRes = create_conversation($pdo, $idA, $idB);
$convId = (int)($convRes['conversation_id'] ?? 0);
run_remote_test("Establish direct conversation channel", $convRes['success'] && $convId > 0);

// Test 10: Friend A sends transmission from Laptop A
$msgA = "Hello Friend B from Laptop A on Wi-Fi Network A! Time: " . date('H:i:s');
$sendA = send_message($pdo, $convId, $idA, $msgA);
run_remote_test("Friend A sends transmission to conversation", $sendA['success']);
$msgA_id = (int)($sendA['message_id'] ?? 0);

// Test 11: Transmission verified persistently stored in MySQL
$stmt = $pdo->prepare("SELECT body, sender_id FROM messages WHERE id = :id");
$stmt->execute(['id' => $msgA_id]);
$rowA = $stmt->fetch(PDO::FETCH_ASSOC);
run_remote_test("Transmission persistently stored in MySQL", $rowA !== false && $rowA['body'] === $msgA);

// Test 12: Friend B polls and receives transmission on Laptop B
$pollB = get_messages($pdo, $convId, 0);
run_remote_test("Friend B receives incoming transmission via polling", 
    count($pollB) === 1 && $pollB[0]['id'] === $msgA_id
);

// Test 13: Friend B transmits reply from Laptop B
$msgB = "Hello Friend A! Received loud and clear on Wi-Fi Network B.";
$sendB = send_message($pdo, $convId, $idB, $msgB);
run_remote_test("Friend B transmits reply from Laptop B", $sendB['success']);
$msgB_id = (int)($sendB['message_id'] ?? 0);

// Test 14: Friend A receives reply via incremental polling (after_id)
$pollA_new = get_messages($pdo, $convId, $msgA_id);
run_remote_test("Friend A receives reply via incremental polling (after_id)", 
    count($pollA_new) === 1 && $pollA_new[0]['id'] === $msgB_id
);

// Test 15: Full conversation history remains intact in MySQL
$history = get_messages($pdo, $convId, 0);
run_remote_test("Full bidirectional history stored in order", 
    count($history) === 2 && $history[0]['id'] === $msgA_id && $history[1]['id'] === $msgB_id
);

// Test 16: Disconnect and Reconnect simulation
// Both friends refresh browser or reconnect later; conversation history must reload
$reloadedConversations = get_user_conversations($pdo, $idA);
run_remote_test("Conversation history persists and reloads after disconnect", 
    count($reloadedConversations) >= 1 && 
    $reloadedConversations[0]['conversation_id'] === $convId &&
    $reloadedConversations[0]['last_message_preview'] === $msgB
);

// Cleanup
$delStmt = $pdo->prepare("DELETE FROM users WHERE id IN (:idA, :idB)");
$delStmt->execute(['idA' => $idA, 'idB' => $idB]);

// Test 17: Database Foreign Key cascade verification
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = :cid");
$stmt->execute(['cid' => $convId]);
$remainingMessages = (int)$stmt->fetchColumn();
run_remote_test("Database integrity verified: Messages cleanly cascaded", $remainingMessages === 0);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n========================================================================\n";
echo " Test Execution Summary: {$passCount} / {$totalCount} Passed\n";
echo "========================================================================\n";

if ($passCount === $totalCount) {
    echo "✔ ALL CLOUDFLARE TUNNEL & REMOTE MESSAGING TESTS PASSED WITH ZERO ERRORS.\n\n";
    exit(0);
} else {
    echo "✖ SOME REMOTE MESSAGING TESTS FAILED.\n\n";
    exit(1);
}
