<?php
/**
 * Project Exodia — Milestone 2.3 Contact Management Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contacts.php';

$pdo = get_db_connection();

echo "========================================================\n";
echo " Project Exodia — Test Suite: Contact Management (Milestone 2.3)\n";
echo "========================================================\n\n";

$testsPassed = 0;
$testsTotal = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $testsPassed, $testsTotal;
    $testsTotal++;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] #{$testsTotal}: {$name}\n";
    } else {
        echo " [FAIL] #{$testsTotal}: {$name} - {$detail}\n";
    }
}

// 1. Provision 3 temporary test users
$time = time();
$uA = "mgmt_alice_{$time}";
$uB = "mgmt_bob_{$time}";
$uC = "mgmt_charlie_{$time}";

$stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$stmt->execute([':u' => $uA, ':e' => "{$uA}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idA = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $uB, ':e' => "{$uB}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idB = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $uC, ':e' => "{$uC}@exodia.local", ':p' => password_hash('Pass1234', PASSWORD_DEFAULT)]);
$idC = (int)$pdo->lastInsertId();

// Test 1: Add valid contact
$addRes = add_contact($pdo, $idA, $idB);
assert_test("Add valid contact succeeds", $addRes['success'] && $addRes['code'] === 'OK');

// Test 2: Verify is_contact returns true
assert_test("is_contact detects active relationship", is_contact($pdo, $idA, $idB));

// Test 3: Prevent adding self
$selfRes = add_contact($pdo, $idA, $idA);
assert_test("Adding self is blocked", !$selfRes['success'] && $selfRes['code'] === 'SELF_CONTACT');

// Test 4: Prevent duplicate contact
$dupRes = add_contact($pdo, $idA, $idB);
assert_test("Duplicate contact addition is blocked", !$dupRes['success'] && $dupRes['code'] === 'DUPLICATE_CONTACT');

// Test 5: Prevent adding non-existent user ID
$nonExistentRes = add_contact($pdo, $idA, 99999999);
assert_test("Adding non-existent user returns NOT_FOUND", !$nonExistentRes['success'] && $nonExistentRes['code'] === 'NOT_FOUND');

// Test 6: Retrieve user contact list
$contactsList = get_user_contacts($pdo, $idA);
$hasBob = false;
foreach ($contactsList as $item) {
    if ($item['user_id'] === $idB && $item['username'] === $uB) {
        $hasBob = true;
    }
}
assert_test("Contact list contains added contact with user profile", count($contactsList) === 1 && $hasBob);

// Test 7: Unauthorized deletion attempt (Alice cannot delete Bob's contacts)
// First give Bob Charlie as a contact
add_contact($pdo, $idB, $idC);
$unauthDelete = remove_contact($pdo, $idA, $idC); // Alice attempts to delete Bob's contact Charlie
assert_test("User cannot remove a contact they do not own", !$unauthDelete['success'] && $unauthDelete['code'] === 'NOT_FOUND');

// Test 8: Authorized remove contact
$remRes = remove_contact($pdo, $idA, $idB);
assert_test("Authorized contact removal succeeds", $remRes['success'] && $remRes['code'] === 'OK');

// Test 9: Verify relationship is gone
assert_test("Relationship verified deleted after removal", !is_contact($pdo, $idA, $idB));

// Test 10: Cascade delete integrity check
// Re-add contact, then delete user record, verify foreign key cascade cleans up
add_contact($pdo, $idA, $idC);
$delUserStmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
$delUserStmt->execute([':id' => $idC]);

$stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE contact_id = :id');
$stmtCheck->execute([':id' => $idC]);
$cascadeCount = (int)$stmtCheck->fetchColumn();
assert_test("Foreign key CASCADE removes orphaned contact rows upon user deletion", $cascadeCount === 0);

// Cleanup remaining test users
$cleanup = $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)');
$cleanup->execute([$idA, $idB]);

echo "\n--------------------------------------------------------\n";
echo "Summary: {$testsPassed} / {$testsTotal} contact management tests passed.\n";
echo "--------------------------------------------------------\n";

if ($testsPassed !== $testsTotal) {
    exit(1);
}
