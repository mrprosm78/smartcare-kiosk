<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// headers
$kioskCode  = (string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? '');

// payload
$pin = trim((string)($input['pin'] ?? ''));

// request meta
$ipAddress  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

function log_kiosk_event(
    PDO $pdo,
    ?string $kioskCode,
    ?int $pairingVersion,
    ?string $deviceTokenHash,
    ?string $ip,
    ?string $ua,
    ?int $employeeId,
    string $eventType,
    string $result,
    ?string $errorCode = null,
    ?string $message = null,
    ?array $meta = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO kiosk_event_log
            (occurred_at, kiosk_code, pairing_version, device_token_hash, ip_address, user_agent,
             employee_id, event_type, result, error_code, message, meta_json)
            VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null;

        $stmt->execute([
            $kioskCode ?: null,
            $pairingVersion ?: null,
            $deviceTokenHash ?: null,
            $ip ?: null,
            $ua ?: null,
            $employeeId ?: null,
            $eventType,
            $result,
            $errorCode,
            $message,
            $metaJson
        ]);
    } catch (Throwable $e) {
        // never break revoke because logging failed
    }
}

try {
    // kiosk auth
    $expectedKiosk = (string)setting($pdo, 'kiosk_code', '');
    if ($expectedKiosk === '' || $kioskCode !== $expectedKiosk) {
        log_kiosk_event($pdo, $kioskCode, null, null, $ipAddress, $userAgent, null, 'revoke', 'fail', 'kiosk_not_authorized');
        json_response(['ok' => false, 'error' => 'kiosk_not_authorized'], 403);
    }

    // manager PIN (use pairing_code by default, or create a dedicated setting if you want)
    $managerPin = (string)setting($pdo, 'pairing_code', '5850'); // you can switch to 'manager_pin' later
    if ($pin === '' || !hash_equals($managerPin, $pin)) {
        $currentVersion = (int)setting($pdo, 'pairing_version', '1');
        log_kiosk_event($pdo, $kioskCode, $currentVersion, null, $ipAddress, $userAgent, null, 'revoke', 'fail', 'invalid_manager_pin');
        json_response(['ok' => false, 'error' => 'invalid_manager_pin'], 401);
    }

    // revoke
    $currentVersion = (int)setting($pdo, 'pairing_version', '1');
    $newVersion = $currentVersion + 1;

    setting_set($pdo, 'is_paired', '0');
    setting_set($pdo, 'paired_device_token_hash', '');
    // legacy clean-up
    try { $pdo->prepare("DELETE FROM kiosk_settings WHERE `key`='paired_device_token'")->execute(); } catch (Throwable $e) {}
    $stmt = setting_set($pdo, 'pairing_version', (string)((string)$newVersion));

    log_kiosk_event(
        $pdo,
        $kioskCode,
        $newVersion,
        null,
        $ipAddress,
        $userAgent,
        null,
        'revoke',
        'success',
        null,
        'Kiosk pairing revoked',
        ['old_version' => $currentVersion, 'new_version' => $newVersion]
    );

    json_response([
        'ok' => true,
        'status' => 'revoked',
        'pairing_version' => $newVersion
    ]);

} catch (Throwable $e) {
    log_kiosk_event($pdo, $kioskCode, null, null, $ipAddress, $userAgent, null, 'revoke', 'fail', 'server_error', $e->getMessage());
    json_response(['ok' => false, 'error' => 'server_error'], 500);
}
