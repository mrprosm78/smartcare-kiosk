<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

/**
 * ping.php
 * - Lightweight heartbeat endpoint
 * - If kiosk headers are provided, updates kiosk_devices last_seen state
 *
 * Expected headers (optional):
 *   X-Kiosk-Code, X-Device-Token, X-Pairing-Version
 */

function get_header_value(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];

    if (function_exists('getallheaders')) {
        $h = getallheaders();
        foreach ($h as $k => $v) {
            if (strcasecmp((string)$k, $name) === 0) return (string)$v;
        }
    }
    return '';
}

function upsert_device(PDO $pdo, array $row): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO kiosk_devices
                (kiosk_code, device_token_hash, pairing_version, last_seen_at, last_seen_kind, last_authorised, last_error_code, last_ip, last_user_agent)
            VALUES
                (:kiosk_code, :device_token_hash, :pairing_version, NOW(), :kind, :authorised, :error_code, :ip, :ua)
            ON DUPLICATE KEY UPDATE
                device_token_hash = VALUES(device_token_hash),
                pairing_version   = VALUES(pairing_version),
                last_seen_at      = NOW(),
                last_seen_kind    = VALUES(last_seen_kind),
                last_authorised   = VALUES(last_authorised),
                last_error_code   = VALUES(last_error_code),
                last_ip           = VALUES(last_ip),
                last_user_agent   = VALUES(last_user_agent)
        ");
        $stmt->execute($row);
    } catch (Throwable $e) {
        // never break ping
    }
}

try {
    $pdo->query('SELECT 1');

    $serverTime = gmdate('c');

    // Optional heartbeat update
    $kioskCodeHdr = trim(get_header_value('X-Kiosk-Code'));
    $deviceTok    = trim(get_header_value('X-Device-Token'));
    $versionHdr   = (int)trim(get_header_value('X-Pairing-Version'));

    $storedKioskCode = (string)setting($pdo, 'kiosk_code', '');

    $authorised = false;
    $errorCode  = null;
    $tokHash    = ($deviceTok !== '') ? hash('sha256', $deviceTok) : null;

    if ($kioskCodeHdr !== '' && $kioskCodeHdr === $storedKioskCode) {
        $paired = (bool)setting_bool($pdo, 'is_paired', false);
        $currentVersion = (int)setting_int($pdo, 'pairing_version', 1);

        if (!$paired) {
            $errorCode = 'kiosk_not_paired';
        } elseif ($versionHdr !== 0 && $versionHdr !== $currentVersion) {
            $errorCode = 'device_revoked';
        } elseif ($deviceTok === '') {
            $errorCode = 'device_not_authorized';
        } else {
            $expectedHash = (string)setting($pdo, 'paired_device_token_hash', '');
            if ($expectedHash !== '' && hash_equals($expectedHash, $tokHash ?? '')) {
                $authorised = true;
            } else {
                $errorCode = 'device_not_authorized';
            }
        }

        upsert_device($pdo, [
            'kiosk_code'       => $kioskCodeHdr,
            'device_token_hash'=> $tokHash,
            'pairing_version'  => $versionHdr ?: null,
            'kind'            => 'ping',
            'authorised'      => $authorised ? 1 : 0,
            'error_code'      => $errorCode,
            'ip'              => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'ua'              => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
    }

    json_response([
        'ok' => true,
        'server_time' => $serverTime,
        'authorised' => $authorised,
        'error' => $errorCode
    ]);

} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => 'db_error'
    ], 500);
}
