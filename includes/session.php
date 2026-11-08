<?php
/**
 * Project Exodia — Secure Session Manager
 * 
 * Configures hardened session parameters:
 *  - HttpOnly cookies: Blocks JavaScript access (XSS mitigation).
 *  - SameSite=Lax: Prevents cross-site cookie transmission on third-party links (CSRF mitigation).
 *  - Strict Mode: Rejects uninitialized session IDs.
 *  - Dynamic Secure flag: Auto-enabled when accessed over HTTPS directly or via trusted loopback
 *    proxies (e.g. Cloudflare Tunnel), and safely disabled for local HTTP development.
 *  - Baseline security headers (nosniff, SAMEORIGIN).
 */

declare(strict_types=1);

/**
 * Detect whether the current connection is secure (HTTPS).
 * 
 * Supports:
 *  - Native HTTPS connections
 *  - Verified local reverse proxies (e.g. Cloudflare Tunnel / loopback proxy)
 *    Strictly validates that proxy forwarding headers (CF-Visitor, X-Forwarded-Proto)
 *    originate from trusted loopback addresses (127.0.0.1 or ::1) to prevent header spoofing.
 */
function is_https_connection(): bool {
    // 1. Direct native HTTPS
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    // 2. Verified Reverse Proxy / Cloudflare Tunnel
    // Cloudflare Tunnel ingress forwards to localhost loopback
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLoopback = ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1');
    if ($isLoopback) {
        // Cloudflare Visitor JSON header
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (is_array($visitor) && isset($visitor['scheme']) && strtolower((string)$visitor['scheme']) === 'https') {
                return true;
            }
        }
        // Standard Reverse Proxy HTTPS header
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PORT']) && (int)$_SERVER['HTTP_X_FORWARDED_PORT'] === 443) {
            return true;
        }
    }

    return false;
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $configFile = dirname(__DIR__) . '/config/config.php';
    $configSecure = false;
    if (file_exists($configFile)) {
        $config = require $configFile;
        $configSecure = (bool)($config['app']['session_secure'] ?? false);
    }

    // Auto-detect HTTPS via verified proxy or direct connection, or honor explicit config
    $isSecure = $configSecure || is_https_connection();

    if (!headers_sent()) {
        // Set secure cookie params before session_start
        session_set_cookie_params([
            'lifetime' => 0,          // Expire when browser closes
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,  // True for HTTPS / Cloudflare Tunnel, false for local HTTP
            'httponly' => true,       // Inaccessible to client JS (prevents cookie theft via XSS)
            'samesite' => 'Lax',      // Strict/Lax prevents CSRF
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        // Security headers for web delivery
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
    }

    @session_start();
}
