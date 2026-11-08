<?php
/**
 * Project Exodia — Master Test Runner
 *
 * Runs all project test suites in sequence:
 *  1. Phase 1: Authentication & Security (15 tests)
 *  2. Phase 2: User Discovery (7 tests)
 *  3. Phase 2: Contact Management (10 tests)
 *  4. Phase 2: Security & Functional (17 tests)
 *  5. Phase 3: Messaging Service (17 tests)
 *  6. Phase 3: End-to-End Direct Messaging (20 tests)
 *  7. Phase 4: Full User Journey (15 steps)
 */

declare(strict_types=1);

echo "\n";
echo "========================================================================\n";
echo "   PROJECT EXODIA — MASTER AUTOMATED TEST EXECUTION SUITE              \n";
echo "   LAMP Stack Native Verification (PHP 8.3 / MySQL 8.0 / PDO)          \n";
echo "========================================================================\n";

$suites = [
    'Phase 1 — Authentication & Security' => __DIR__ . '/test_auth.php',
    'Phase 2 — User Discovery Service'    => __DIR__ . '/test_discovery.php',
    'Phase 2 — Contact Management'        => __DIR__ . '/test_contacts_mgmt.php',
    'Phase 2 — Security & Functionality'  => __DIR__ . '/test_phase2_security.php',
    'Phase 3 — Messaging Service'         => __DIR__ . '/test_messaging.php',
    'Phase 3 — End-to-End Messaging'      => __DIR__ . '/test_messaging_e2e.php',
    'Phase 4 — Master User Journey'       => __DIR__ . '/test_full_journey.php',
    'Cloudflare Tunnel & Remote Messaging' => __DIR__ . '/test_remote_messaging.php',
];

$allPassed = true;
$totalSuites = count($suites);
$passedSuites = 0;

foreach ($suites as $name => $path) {
    echo "\n>>> Running Suite: {$name}...\n";
    $output = [];
    $returnVar = 0;
    exec("php " . escapeshellarg($path) . " 2>&1", $output, $returnVar);

    echo implode("\n", $output) . "\n";

    if ($returnVar === 0) {
        $passedSuites++;
    } else {
        $allPassed = false;
        echo "✖ FAILED: {$name}\n";
    }
}

echo "\n========================================================================\n";
echo " MASTER TEST SUMMARY: {$passedSuites} / {$totalSuites} Test Suites Passed\n";
echo "========================================================================\n";

if ($allPassed) {
    echo "✔ ALL PROJECT EXODIA PHASES (1, 2, 3, 4) VERIFIED AND CERTIFIED OPERATIONAL.\n\n";
    exit(0);
} else {
    echo "✖ ONE OR MORE SUITES ENCOUNTERED AN ERROR.\n\n";
    exit(1);
}
