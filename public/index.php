<?php
/**
 * Project Exodia — Entry Router
 * 
 * Directs authenticated operatives to the dashboard and guests to the login gateway.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

start_secure_session();

if (is_authenticated()) {
    header('Location: dashboard.php', true, 302);
} else {
    header('Location: login.php', true, 302);
}
exit;
