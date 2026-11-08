<?php
/**
 * Project Exodia — Database Connection Factory (PDO)
 * 
 * Provides a secure, singleton PDO database connection instance.
 * Configured with:
 *  - Native prepared statements (ATTR_EMULATE_PREPARES => false)
 *  - Strict exception error handling (ATTR_ERRMODE => ERRMODE_EXCEPTION)
 *  - Associative array fetch mode (ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC)
 */

declare(strict_types=1);

function get_db_connection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException("Database configuration file not found. Please create config/config.php from config/config.example.php.");
    }

    $config = require $configFile;
    $dbConfig = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['database'],
        $dbConfig['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        return $pdo;
    } catch (PDOException $e) {
        // Log the actual error internally; do not reveal sensitive database details to clients
        error_log('Database Connection Error: ' . $e->getMessage());
        throw new RuntimeException('A database connection error occurred. Please verify your database configuration.');
    }
}
