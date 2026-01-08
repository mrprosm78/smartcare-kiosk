<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$kioskCode   = trim((string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? ''));
$pairingCode = trim((string)($input['pairing_code'] ?? ''));

// Safety: must exist in DB
$expectedKiosk   = (string) setting($pdo, 'kiosk_code', '');
$expectedPairing = (string) setting($pdo, 'pairing_code', '');

// Basic checks
if ($expectedKiosk === '' || $expectedPairing === '') {
    json_response(['ok' => false, 'error' => 'missing_settings'], 500);
}

if (!hash_equals($expectedKiosk, $kioskCode)) {
    json_response(['ok' => false, 'error' => 'kiosk_not_authorized'], 403);
}

if ($pairingCode === '' || !hash_equals($expectedPairing, $pairingCode)) {
    json_response(['ok' => false, 'error' => 'invalid_pairing_code'], 403);
}

if (setting($pdo, 'is_paired', '0') === '1') {
    json_response(['ok' => false, 'error' => 'already_paired'], 403);
}

// Generate new device token
$token = 'dev_' . bin2hex(random_bytes(16));

// Pairing version (keep as-is)
$version = (int) setting($pdo, 'pairing_version', '1');

// UPSERT helper (works even if key row was deleted)
$upsert = $pdo->prepare("
    INSERT INTO settings (`key`, `value`, `updated_at`)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE
      `value` = VALUES(`value`),
      `updated_at` = VALUES(`updated_at`)
");

// Save pairing state
$upsert->execute(['is_paired', '1']);
$upsert->execute(['paired_device_token', $token]);

json_response([
    'ok' => true,
    'device_token' => $token,
    'pairing_version' => $version
]);
