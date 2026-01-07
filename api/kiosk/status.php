<?php
require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

json_response([
    'ok' => true,
    'paired' => setting($pdo,'is_paired','0') === '1',
    'pairing_version' => (int)setting($pdo,'pairing_version','1')
]);
