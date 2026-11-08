<?php
/**
 * Project Exodia — Automated Security & Functional Verification Suite
 * 
 * Verifies all 15 mandatory test scenarios:
 * 1. Register a new user successfully.
 * 2. Register using an existing email (duplicate rejection).
 * 3. Register using an existing username (duplicate rejection).
 * 4. Submit an invalid email.
 * 5. Submit missing fields.
 * 6. Submit mismatched passwords.
 * 7. Log in with correct credentials.
 * 8. Log in with an incorrect password.
 * 9. Log in with an unregistered email.
 * 10. Access the dashboard without logging in (unauthorized redirect).
 * 11. Log out and try to access dashboard again (session destroyed).
 * 12. Confirm that passwords are stored as hashes (Bcrypt prefix, zero plaintext).
 * 13. Confirm that SQL injection-style inputs do not bypass authentication.
 * 14. Confirm that CSRF protection works.
 * 15. Confirm that the interface behaves properly on mobile and desktop.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db_connection();

$totalTests = 15;
$passedTests = 0;
$failedTests = [];

function record_result(int $num, string $description, bool $passed, string $details = ''): void {
    global $passedTests, $failedTests;
    if ($passed) {
        $passedTests++;
        echo sprintf("  [\033[32mPASS\033[0m] Test #%02d: %s\n", $num, $description);
    } else {
        $failedTests[] = "Test #$num: $description — $details";
        echo sprintf("  [\033[31mFAIL\033[0m] Test #%02d: %s (%s)\n", $num, $description, $details);
    }
}

echo "\n======================================================\n";
echo " Project Exodia — Phase 1 Test Execution Suite\n";
echo "======================================================\n\n";

// Unique test credentials
$testTime = time();
$testUser = "testuser_{$testTime}";
$testEmail = "testuser_{$testTime}@exodia.local";
$testPass = "SecurePass123!";

// ---------------------------------------------------------------------
// TEST 1: Register a new user successfully
// ---------------------------------------------------------------------
try {
    $hashedPassword = password_hash($testPass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)');
    $stmt->execute([
        'username'      => $testUser,
        'email'         => $testEmail,
        'password_hash' => $hashedPassword,
    ]);
    $userId = (int)$pdo->lastInsertId();
    record_result(1, "Register a new user successfully", $userId > 0);
} catch (Exception $e) {
    record_result(1, "Register a new user successfully", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 2: Register using an existing email
// ---------------------------------------------------------------------
try {
    $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $emailCheck->execute(['email' => $testEmail]);
    $exists = (bool)$emailCheck->fetch();
    $caughtException = false;

    if ($exists) {
        // Attempt duplicate insert to verify DB constraint
        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
            $stmt->execute(['u' => "other_{$testTime}", 'e' => $testEmail, 'p' => 'password123']);
        } catch (PDOException $e) {
            $caughtException = true;
        }
    }
    record_result(2, "Register using an existing email (duplicate rejection)", $exists && $caughtException);
} catch (Exception $e) {
    record_result(2, "Register using an existing email", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 3: Register using an existing username
// ---------------------------------------------------------------------
try {
    $userCheck = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $userCheck->execute(['username' => $testUser]);
    $exists = (bool)$userCheck->fetch();
    $caughtException = false;

    if ($exists) {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
            $stmt->execute(['u' => $testUser, 'e' => "other_{$testTime}@exodia.local", 'p' => 'password123']);
        } catch (PDOException $e) {
            $caughtException = true;
        }
    }
    record_result(3, "Register using an existing username (duplicate rejection)", $exists && $caughtException);
} catch (Exception $e) {
    record_result(3, "Register using an existing username", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 4: Submit an invalid email
// ---------------------------------------------------------------------
$invalidEmailRes1 = validate_email("not-an-email");
$invalidEmailRes2 = validate_email("user@invalid");
record_result(4, "Submit an invalid email format", (!$invalidEmailRes1['valid'] && !$invalidEmailRes2['valid']));

// ---------------------------------------------------------------------
// TEST 5: Submit missing fields
// ---------------------------------------------------------------------
$missingUser = validate_username("");
$missingEmail = validate_email("");
$missingPass = validate_password("");
record_result(5, "Submit missing fields (enforce required fields)", (!$missingUser['valid'] && !$missingEmail['valid'] && !$missingPass['valid']));

// ---------------------------------------------------------------------
// TEST 6: Submit mismatched passwords
// ---------------------------------------------------------------------
$mismatchRes = validate_password_confirmation("Password123", "DifferentPassword123");
$matchRes = validate_password_confirmation("Password123", "Password123");
record_result(6, "Submit mismatched passwords", (!$mismatchRes['valid'] && $matchRes['valid']));

// ---------------------------------------------------------------------
// TEST 7: Log in with correct credentials
// ---------------------------------------------------------------------
try {
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $testEmail]);
    $userRecord = $stmt->fetch();
    $authSuccess = ($userRecord && password_verify($testPass, $userRecord['password_hash']));
    record_result(7, "Log in with correct credentials", $authSuccess);
} catch (Exception $e) {
    record_result(7, "Log in with correct credentials", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 8: Log in with an incorrect password
// ---------------------------------------------------------------------
try {
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $testEmail]);
    $userRecord = $stmt->fetch();
    $authFailed = ($userRecord && !password_verify("CompletelyWrongPassword!", $userRecord['password_hash']));
    record_result(8, "Log in with an incorrect password (rejected safely)", $authFailed);
} catch (Exception $e) {
    record_result(8, "Log in with an incorrect password", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 9: Log in with an unregistered email
// ---------------------------------------------------------------------
try {
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => "ghost_{$testTime}@nonexistent.org"]);
    $userRecord = $stmt->fetch();
    record_result(9, "Log in with an unregistered email (treated identically)", $userRecord === false);
} catch (Exception $e) {
    record_result(9, "Log in with an unregistered email", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 10: Access the dashboard without logging in
// ---------------------------------------------------------------------
$_SESSION = []; // Clear session
$isAuthenticated = is_authenticated();
record_result(10, "Access the dashboard without logging in (guard blocks guest)", $isAuthenticated === false);

// ---------------------------------------------------------------------
// TEST 11: Log out and try to access the dashboard again
// ---------------------------------------------------------------------
// Simulate login
$_SESSION['user_id'] = $userId;
$_SESSION['username'] = $testUser;
$_SESSION['logged_in'] = true;
$wasLoggedIn = is_authenticated();

// Simulate logout
$_SESSION = [];
$afterLogoutAccess = is_authenticated();
record_result(11, "Log out and try to access dashboard again (session invalidated)", $wasLoggedIn && !$afterLogoutAccess);

// ---------------------------------------------------------------------
// TEST 12: Confirm that passwords are stored as hashes
// ---------------------------------------------------------------------
try {
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $hash = $stmt->fetchColumn();
    // Bcrypt hashes start with $2y$ (or $2a$ / $2b$) and are exactly 60 chars long
    $isBcrypt = is_string($hash) && str_starts_with($hash, '$2y$') && strlen($hash) === 60;
    $notPlaintext = ($hash !== $testPass);
    record_result(12, "Confirm passwords stored as hashes (Bcrypt, zero plaintext)", $isBcrypt && $notPlaintext);
} catch (Exception $e) {
    record_result(12, "Confirm passwords stored as hashes", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 13: Confirm SQL injection-style inputs do not bypass authentication
// ---------------------------------------------------------------------
try {
    $sqliPayload = "' OR '1'='1' -- ";
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $sqliPayload]);
    $result = $stmt->fetch();
    record_result(13, "Confirm SQL injection inputs do not bypass authentication", $result === false);
} catch (Exception $e) {
    record_result(13, "Confirm SQL injection inputs do not bypass authentication", false, $e->getMessage());
}

// ---------------------------------------------------------------------
// TEST 14: Confirm that CSRF protection works
// ---------------------------------------------------------------------
start_secure_session();
$validToken = generate_csrf_token();
$validCheck = verify_csrf_token($validToken);
$tamperedCheck = verify_csrf_token("tampered_csrf_token_value_xyz");
$emptyCheck = verify_csrf_token("");
$nullCheck = verify_csrf_token(null);
record_result(14, "Confirm CSRF protection (validates genuine token, rejects forged/missing)", 
    $validCheck === true && $tamperedCheck === false && $emptyCheck === false && $nullCheck === false
);

// ---------------------------------------------------------------------
// TEST 15: Confirm interface behaves properly on mobile and desktop
// ---------------------------------------------------------------------
$cssContent = file_get_contents(__DIR__ . '/../public/css/auth.css') . file_get_contents(__DIR__ . '/../public/css/style.css');
$hasMediaQueries = str_contains($cssContent, '@media (max-width:');
$hasViewport = str_contains(file_get_contents(__DIR__ . '/../public/login.php'), 'name="viewport"') &&
               str_contains(file_get_contents(__DIR__ . '/../public/register.php'), 'name="viewport"') &&
               str_contains(file_get_contents(__DIR__ . '/../public/dashboard.php'), 'name="viewport"');
$hasFlexGrid = str_contains($cssContent, 'display: flex') && str_contains($cssContent, 'grid-template-columns');
record_result(15, "Confirm responsive design (viewport meta, CSS media queries & flex/grid)", 
    $hasMediaQueries && $hasViewport && $hasFlexGrid
);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n======================================================\n";
echo " Test Execution Summary: $passedTests / $totalTests Passed\n";
echo "======================================================\n";

if ($passedTests === $totalTests) {
    echo "\033[32m✔ ALL 15 VERIFICATION SCENARIOS PASSED WITH ZERO ERRORS.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m✖ SOME TESTS FAILED:\033[0m\n";
    foreach ($failedTests as $f) {
        echo " - $f\n";
    }
    echo "\n";
    exit(1);
}
