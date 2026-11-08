<?php
/**
 * Project Exodia — Secure Session Manager
 * 
 * Configures hardened session parameters:
 *  - HttpOnly cookies: Blocks JavaScript access (XSS mitigation).
 *  - SameSite=Lax: Prevents cross-site cookie transmission on third-party links (CSRF mitigation).
 *  - Strict Mode: Rejects uninitialized session IDs.
 *  - Configurable Secure flag: Disabled for local HTTP development, enabled for production HTTPS.
 */

declare(strict_types=1);

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $configFile = dirname(__DIR__) . '/config/config.php';
    $isSecure = false;
    if (file_exists($configFile)) {
        $config = require $configFile;
        $isSecure = (bool)($config['app']['session_secure'] ?? false);
    }

    if (!headers_sent()) {
        // Set secure cookie params before session_start
        session_set_cookie_params([
            'lifetime' => 0,          // Expire when browser closes
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,  // True for HTTPS, false for local HTTP
            'httponly' => true,       // Inaccessible to client JS (prevents cookie theft via XSS)
            'samesite' => 'Lax',      // Strict/Lax prevents CSRF
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
    }

    @session_start();
}
