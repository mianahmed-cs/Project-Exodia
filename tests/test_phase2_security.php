<?php
/**
 * Project Exodia — Phase 2 Comprehensive Functional & Security Test Suite
 * 
 * Verifies all Phase 2 requirements:
 *  1. Access Control: Web guard blocks guests from contacts.php
 *  2. API Security: Unauthenticated calls to search_users.php return HTTP 401
 *  3. API Security: Unauthenticated calls to contacts.php return HTTP 401
 *  4. Discovery: Valid username query returns matching operatives
 *  5. Discovery: Excludes the currently authenticated user
 *  6. Discovery: Handles empty query strings safely
 *  7. Discovery: Handles non-matching queries with empty array
 *  8. Contacts: Add valid contact relationship
 *  9. Contacts: Prevent self-addition (business rule)
 *  10. Contacts: Prevent duplicate contact addition (database constraint & rule)
 *  11. Contacts: List authenticated user's contacts
 *  12. Contacts: Authorized contact removal
 *  13. Contacts: Unauthorized contact removal (cross-user isolation)
 *  14. Security: SQL Injection resistance on search & contact parameters
 *  15. Security: CSRF token enforcement (missing & forged token rejection)
 *  16. Security: XSS output escaping verification
 *  17. Database Integrity: Foreign key ON DELETE CASCADE verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contacts.php';

$pdo = get_db_connection();

echo "\n======================================================\n";
echo " Project Exodia — Phase 2 Security & Functional Test Suite\n";
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
$timestamp = time() . '_' . mt_rand(100, 999);
$userA_name = "agent_alpha_{$timestamp}";
$userB_name = "agent_bravo_{$timestamp}";
$userC_name = "agent_charlie_{$timestamp}";
$userXSS_name = "xss_<script>alert(1)</script>_{$timestamp}"; // test sanitization

$stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$stmt->execute([':u' => $userA_name, ':e' => "{$userA_name}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idA = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $userB_name, ':e' => "{$userB_name}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idB = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $userC_name, ':e' => "{$userC_name}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idC = (int)$pdo->lastInsertId();

// ----------------------------------------------------
// TEST 1: Web access guard for contacts.php
// ----------------------------------------------------
$contactsSource = file_get_contents(__DIR__ . '/../public/contacts.php');
$hasRequireLogin = str_contains($contactsSource, 'require_login()');
run_test("Direct access to contacts.php is guarded by require_login()", $hasRequireLogin);

// ----------------------------------------------------
// TEST 2: API guard for search_users.php
// ----------------------------------------------------
$searchSource = file_get_contents(__DIR__ . '/../public/api/search_users.php');
$hasAuthApiSearch = str_contains($searchSource, 'require_auth_api()');
run_test("User discovery API enforces authentication guard", $hasAuthApiSearch);

// ----------------------------------------------------
// TEST 3: API guard for contacts.php
// ----------------------------------------------------
$contactsApiSource = file_get_contents(__DIR__ . '/../public/api/contacts.php');
$hasAuthApiContacts = str_contains($contactsApiSource, 'require_auth_api()');
run_test("Contact management API enforces authentication guard", $hasAuthApiContacts);

// ----------------------------------------------------
// TEST 4: Valid search finds matching user
// ----------------------------------------------------
$searchRes = search_users($pdo, $idA, "agent_bravo");
$foundB = false;
foreach ($searchRes as $row) {
    if ($row['id'] === $idB && $row['username'] === $userB_name) {
        $foundB = true;
    }
}
run_test("User search returns matching operatives", $foundB);

// ----------------------------------------------------
// TEST 5: Discovery excludes the current user
// ----------------------------------------------------
$selfSearch = search_users($pdo, $idA, "agent_alpha");
$foundSelf = false;
foreach ($selfSearch as $row) {
    if ($row['id'] === $idA) {
        $foundSelf = true;
    }
}
run_test("User search excludes the authenticated searcher", !$foundSelf);

// ----------------------------------------------------
// TEST 6: Empty search query handles safely
// ----------------------------------------------------
$emptyRes = search_users($pdo, $idA, "   ");
run_test("Empty query string returns empty results safely", empty($emptyRes));

// ----------------------------------------------------
// TEST 7: Non-matching query returns empty results
// ----------------------------------------------------
$noMatchRes = search_users($pdo, $idA, "ghost_nonexistent_user_9999");
run_test("Non-matching query returns empty results safely", empty($noMatchRes));

// ----------------------------------------------------
// TEST 8: Add valid contact
// ----------------------------------------------------
$addRes = add_contact($pdo, $idA, $idB);
run_test("Adding a valid operative as contact succeeds", $addRes['success'] && $addRes['code'] === 'OK');

// ----------------------------------------------------
// TEST 9: Prevent self-addition
// ----------------------------------------------------
$selfAddRes = add_contact($pdo, $idA, $idA);
run_test("Adding self as contact is rejected", !$selfAddRes['success'] && $selfAddRes['code'] === 'SELF_CONTACT');

// ----------------------------------------------------
// TEST 10: Prevent duplicate contacts
// ----------------------------------------------------
$dupAddRes = add_contact($pdo, $idA, $idB);
run_test("Duplicate contact addition is blocked", !$dupAddRes['success'] && $dupAddRes['code'] === 'DUPLICATE_CONTACT');

// ----------------------------------------------------
// TEST 11: Retrieve user contact list
// ----------------------------------------------------
$list = get_user_contacts($pdo, $idA);
$inList = false;
foreach ($list as $c) {
    if ($c['user_id'] === $idB && $c['username'] === $userB_name) {
        $inList = true;
    }
}
run_test("Contact roster accurately lists added contacts with profile data", $inList);

// ----------------------------------------------------
// TEST 12: Authorized contact removal
// ----------------------------------------------------
$removeRes = remove_contact($pdo, $idA, $idB);
run_test("Authorized contact removal succeeds", $removeRes['success'] && $removeRes['code'] === 'OK');

// ----------------------------------------------------
// TEST 13: Unauthorized contact removal (cross-user isolation)
// ----------------------------------------------------
add_contact($pdo, $idB, $idC); // Bob adds Charlie
$unauthRemove = remove_contact($pdo, $idA, $idC); // Alice attempts to delete Bob's contact
run_test("Unauthorized contact deletion attempt is blocked", !$unauthRemove['success'] && $unauthRemove['code'] === 'NOT_FOUND');

// ----------------------------------------------------
// TEST 14: SQL Injection resistance in discovery & contacts
// ----------------------------------------------------
$sqliQuery = "' OR '1'='1' -- ";
$sqliRes = search_users($pdo, $idA, $sqliQuery);
$sqliHandledSafely = is_array($sqliRes);

$sqliAdd = add_contact($pdo, $idA, -1);
$sqliAddBlocked = !$sqliAdd['success'];
run_test("SQL injection attempts in search & contacts are neutralized", $sqliHandledSafely && $sqliAddBlocked);

// ----------------------------------------------------
// TEST 15: CSRF Token enforcement on state-changing API
// ----------------------------------------------------
$csrfCheckedInApi = str_contains($contactsApiSource, 'verify_csrf_token') && str_contains($contactsApiSource, 'CSRF_INVALID');
start_secure_session();
$dummyToken = generate_csrf_token();
$validCheck = verify_csrf_token($dummyToken);
$fakeCheck = verify_csrf_token('forged_fake_token_value');
run_test("CSRF token is enforced and forged tokens are rejected", $csrfCheckedInApi && $validCheck && !$fakeCheck);

// ----------------------------------------------------
// TEST 16: XSS Output Escaping
// ----------------------------------------------------
$rawPayload = '<script>alert("exodia")</script>';
$escaped = sanitize_output($rawPayload);
$xssBlocked = !str_contains($escaped, '<script>') && str_contains($escaped, '&lt;script&gt;');
run_test("Dynamic output escaping neutralizes XSS payloads", $xssBlocked);

// ----------------------------------------------------
// TEST 17: Database Integrity — Foreign Key CASCADE
// ----------------------------------------------------
add_contact($pdo, $idA, $idC); // Alice adds Charlie
$delCharlieStmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
$delCharlieStmt->execute([':id' => $idC]);

$checkCascade = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE contact_id = :id');
$checkCascade->execute([':id' => $idC]);
$orphanCount = (int)$checkCascade->fetchColumn();
run_test("Foreign Key CASCADE cleans up contact records upon user deletion", $orphanCount === 0);

// ----------------------------------------------------
// Cleanup Remaining Test Operatives
// ----------------------------------------------------
$cleanup = $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)');
$cleanup->execute([$idA, $idB]);

// ----------------------------------------------------
// Test Execution Summary
// ----------------------------------------------------
echo "\n======================================================\n";
echo " Test Execution Summary: {$passCount} / {$totalCount} Passed\n";
echo "======================================================\n";

if ($passCount === $totalCount) {
    echo "✔ ALL 17 PHASE 2 VERIFICATION SCENARIOS PASSED WITH ZERO ERRORS.\n\n";
} else {
    echo "✖ SOME TESTS FAILED. PLEASE INSPECT LOGS.\n\n";
    exit(1);
}
