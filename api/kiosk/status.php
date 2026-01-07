<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$isPaired = (setting($pdo, 'is_paired', '0') === '1');

json_response([
    'ok' => true,
    'paired' => $isPaired
]);
