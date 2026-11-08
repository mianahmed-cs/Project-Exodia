<?php
/**
 * Project Exodia — Authentication Guard & Session Helper
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';

function is_authenticated(): bool {
    start_secure_session();
    return !empty($_SESSION['user_id']) && !empty($_SESSION['logged_in']);
}

function require_login(): void {
    if (!is_authenticated()) {
        header('Location: login.php?error=unauthorized');
        exit;
    }
}

function require_guest(): void {
    if (is_authenticated()) {
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Enforces authentication for JSON API endpoints.
 * Returns the current authenticated user array or exits with HTTP 401.
 */
function require_auth_api(): array {
    if (!is_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Authentication required. Please sign in.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    return current_auth_user() ?? [];
}

function current_auth_user(): ?array {
    if (!is_authenticated()) {
        return null;
    }
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'email'    => $_SESSION['email'] ?? '',
    ];
}
