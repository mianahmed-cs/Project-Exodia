<?php
/**
 * Project Exodia — User Discovery API Endpoint
 * 
 * Searches registered users by username.
 * Requires active authenticated session.
 * Excludes currently logged-in user and safely returns public profile information.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/contacts.php';

// Enforce authentication
$currentUser = require_auth_api();
$currentUserId = (int)$currentUser['id'];

// Retrieve and sanitize search query
$query = isset($_GET['q']) ? trim((string)$_GET['q']) : (isset($_GET['query']) ? trim((string)$_GET['query']) : '');
$limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;

if ($query === '') {
    echo json_encode([
        'success' => true,
        'query'   => '',
        'count'   => 0,
        'users'   => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Length check (max 30 chars for usernames)
if (mb_strlen($query) > 50) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Search query is too long (maximum 50 characters).',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = get_db_connection();
    $users = search_users($pdo, $currentUserId, $query, $limit);

    // Sanitize user output values for XSS safety
    $safeUsers = array_map(function ($u) {
        return [
            'id'         => (int)$u['id'],
            'username'   => htmlspecialchars($u['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'created_at' => $u['created_at'],
            'is_contact' => (bool)$u['is_contact'],
        ];
    }, $users);

    echo json_encode([
        'success' => true,
        'query'   => htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'count'   => count($safeUsers),
        'users'   => $safeUsers,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('User search API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'An error occurred while searching for users.',
    ], JSON_UNESCAPED_SLASHES);
}
