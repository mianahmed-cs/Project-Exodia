<?php
/**
 * Project Exodia — Milestone 2.2 User Discovery Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/contacts.php';

$pdo = get_db_connection();

echo "========================================================\n";
echo " Project Exodia — Test Suite: User Discovery (Milestone 2.2)\n";
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

// 1. Setup two test users for discovery
$time = time();
$u1 = "disc_alice_{$time}";
$u2 = "disc_bob_{$time}";
$u3 = "disc_charlie_{$time}";

$stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
$stmt->execute([':u' => $u1, ':e' => "{$u1}@exodia.local", ':p' => password_hash('Secret123', PASSWORD_DEFAULT)]);
$id1 = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $u2, ':e' => "{$u2}@exodia.local", ':p' => password_hash('Secret123', PASSWORD_DEFAULT)]);
$id2 = (int)$pdo->lastInsertId();

$stmt->execute([':u' => $u3, ':e' => "{$u3}@exodia.local", ':p' => password_hash('Secret123', PASSWORD_DEFAULT)]);
$id3 = (int)$pdo->lastInsertId();

// Test 1: Search returns matching user
$results = search_users($pdo, $id1, "disc_bob");
assert_test("Search finds matching user", count($results) >= 1 && $results[0]['username'] === $u2);

// Test 2: Search excludes currently logged-in user
$selfSearch = search_users($pdo, $id1, "disc_alice");
$foundSelf = false;
foreach ($selfSearch as $r) {
    if ($r['id'] === $id1) {
        $foundSelf = true;
        break;
    }
}
assert_test("Search excludes current user ID", !$foundSelf);

// Test 3: Search with empty query returns empty array
$emptyRes = search_users($pdo, $id1, "   ");
assert_test("Empty query returns empty array", empty($emptyRes));

// Test 4: Search with non-matching query returns empty array
$noMatch = search_users($pdo, $id1, "nonexistent_xyz_9999");
assert_test("Non-matching query returns empty results", empty($noMatch));

// Test 5: SQL injection attempt in search query handled safely as literal
$sqliRes = search_users($pdo, $id1, "' OR '1'='1' -- ");
assert_test("SQL injection query handled safely as literal without error", is_array($sqliRes));

// Test 6: Ensure sensitive fields (password_hash) are never returned
$checkFields = search_users($pdo, $id1, "disc_");
$safeFields = true;
foreach ($checkFields as $cf) {
    if (isset($cf['password_hash']) || isset($cf['password'])) {
        $safeFields = false;
    }
}
assert_test("Search results never leak password hash or credentials", $safeFields);

// Test 7: Result limit enforcement
$limitedRes = search_users($pdo, $id1, "disc_", 1);
assert_test("Result limit is respected", count($limitedRes) === 1);

// Clean up test users
$delStmt = $pdo->prepare('DELETE FROM users WHERE id IN (?, ?, ?)');
$delStmt->execute([$id1, $id2, $id3]);

echo "\n--------------------------------------------------------\n";
echo "Summary: {$testsPassed} / {$testsTotal} discovery tests passed.\n";
echo "--------------------------------------------------------\n";

if ($testsPassed !== $testsTotal) {
    exit(1);
}
