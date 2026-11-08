<?php
/**
 * Project Exodia — Secure Logout Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';

start_secure_session();

// If POST request, verify CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        die('Invalid security token during logout.');
    }
}

// 1. Unset all session variables
$_SESSION = [];

// 2. Invalidate session cookie in browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Destroy session on server
session_destroy();

// 4. Redirect to login page with logged_out notice
header('Location: login.php?notice=logged_out', true, 303);
exit;
