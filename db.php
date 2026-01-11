<?php
// db.php

$host = 'localhost';
$db   = 'timesheet';
$user = 'root';
$pass = ''; // rotate later
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Production-grade time handling:
    // Keep ALL timestamps in UTC at the DB session level to avoid BST/DST drift.
    // (DATETIME has no timezone; this makes SQL time functions consistent.)
    $pdo->exec("SET time_zone = '+00:00'");

    // Optional: enforce sane SQL modes (comment out if your host restricts this)
    // $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_connection_failed'
    ]);
    exit;
}
