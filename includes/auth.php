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
