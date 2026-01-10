<?php
// db.php

$host = 'sdb-67.hosting.stackcp.net';
$db   = 'kiosk01-35303437df66';
$user = 'kiosk01-35303437df66';
$pass = 'oa|?Cy|Zo%zm'; // rotate later
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_connection_failed'
    ]);
    exit;
}
