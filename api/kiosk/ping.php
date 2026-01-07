<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

try {
    $pdo->query("SELECT 1");
    json_response([
        'ok' => true,
        'server_time' => gmdate('c')
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'db_error'
    ], 500);
}
