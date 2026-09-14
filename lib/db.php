<?php
/**
 * PDO connection helper. Single shared connection per request.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $c = config_get();
    $dsn = "mysql:host={$c['DB_HOST']};dbname={$c['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Keep NOW()/UTC_TIMESTAMP() consistent with PHP's gmdate() (UTC).
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
