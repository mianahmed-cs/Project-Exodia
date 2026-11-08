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
        'url'            => 'http://localhost:8000',
        'env'            => 'development', // 'development' or 'production'
        'session_secure' => false,         // Set to true when serving over HTTPS
    ],
];
