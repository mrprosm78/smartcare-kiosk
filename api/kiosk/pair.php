<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// headers
$kioskCode  = (string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? '');

// payload
$pairingCodeIn = trim((string)($input['pairing_code'] ?? ''));

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
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        // never block pairing due to logging
    }
}

try {
    // Basic kiosk authorization (must match stored kiosk_code)
    $expectedKiosk = (string)setting($pdo, 'kiosk_code', '');
    if ($expectedKiosk === '' || $kioskCode !== $expectedKiosk) {
        log_kiosk_event($pdo, $kioskCode, null, null, $ipAddress, $userAgent, null, 'pair', 'fail', 'kiosk_not_authorized');
        json_response(['ok' => false, 'error' => 'kiosk_not_authorized'], 403);
    }

    $currentVersion = (int)setting($pdo, 'pairing_version', '1');

    // Validate pairing code
    $expectedPair = (string)setting($pdo, 'pairing_code', '');
    if ($pairingCodeIn === '' || $expectedPair === '' || !hash_equals($expectedPair, $pairingCodeIn)) {
        log_kiosk_event($pdo, $kioskCode, $currentVersion, null, $ipAddress, $userAgent, null, 'pair', 'fail', 'invalid_pairing_code');
        json_response(['ok' => false, 'error' => 'invalid_pairing_code'], 401);
    }

    // Generate new device token
    $deviceToken = bin2hex(random_bytes(16)); // 32 chars
    $deviceHash  = hash('sha256', $deviceToken);

    // Mark paired
    $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES ('is_paired','1')")->execute();
    $stmt = $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES ('paired_device_token', ?)");
    $stmt->execute([$deviceToken]);

    // Optionally: keep pairing_version as-is (only changes when revoked manually)
    // (no change here)

    log_kiosk_event(
        $pdo,
        $kioskCode,
        $currentVersion,
        $deviceHash,
        $ipAddress,
        $userAgent,
        null,
        'pair',
        'success',
        null,
        'Device paired successfully'
    );

    json_response([
        'ok' => true,
        'device_token' => $deviceToken,
        'pairing_version' => $currentVersion
    ]);

} catch (Throwable $e) {
    log_kiosk_event($pdo, $kioskCode, null, null, $ipAddress, $userAgent, null, 'pair', 'fail', 'server_error', $e->getMessage());
    json_response(['ok' => false, 'error' => 'server_error'], 500);
}
