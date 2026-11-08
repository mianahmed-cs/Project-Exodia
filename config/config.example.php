<?php
/**
 * Project Exodia — Configuration Template
 * 
 * Copy this file to `config.php` and fill in your local database credentials.
 * NEVER commit `config.php` to Git or public repositories.
 */

declare(strict_types=1);

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'project_exodia',
        'username' => 'exodia_user',
        'password' => 'YOUR_DB_PASSWORD_HERE',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'           => 'Project Exodia',
        'url'            => 'http://localhost/exodia',
        'env'            => 'development', // 'development' or 'production'
        // Session security: When false, includes/session.php automatically detects HTTPS
        // connections (directly or via verified Cloudflare Tunnel / loopback reverse proxies)
        // and enables the Secure cookie flag automatically without breaking local HTTP.
        'session_secure' => false,
    ],
    'storage' => [
        // Private directory for uploaded photos and documents (strictly outside Apache public web root)
        'attachments_dir' => dirname(__DIR__) . '/storage/attachments',
    ],
];
