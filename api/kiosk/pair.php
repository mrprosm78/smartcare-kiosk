<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$kioskCode   = trim((string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? ''));
$pairingCode = trim((string)($input['pairing_code'] ?? ''));

if ($kioskCode === '' || $kioskCode !== (string)setting($pdo, 'kiosk_code', '')) {
    json_response(['ok'=>false,'error'=>'kiosk_not_authorized'], 403);
}

if ($pairingCode === '' || $pairingCode !== (string)setting($pdo, 'pairing_code', '')) {
    json_response(['ok'=>false,'error'=>'invalid_pairing_code'], 403);
}

if (setting($pdo, 'is_paired', '0') === '1') {
    json_response(['ok'=>false,'error'=>'already_paired'], 403);
}

$token = 'dev_' . bin2hex(random_bytes(16));

$pdo->prepare("UPDATE settings SET value='1', updated_at=NOW() WHERE `key`='is_paired'")->execute();
$pdo->prepare("UPDATE settings SET value=?, updated_at=NOW() WHERE `key`='paired_device_token'")->execute([$token]);

json_response([
    'ok' => true,
    'device_token' => $token
]);
