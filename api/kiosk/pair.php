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


/**
 * Rate limit pairing attempts (prevents brute-force of manager/pairing PIN).
 * - Counts recent failed /pair attempts from kiosk_event_log
 * - Scoped by IP (and kiosk_code if provided)
 * - Returns 429 with Retry-After when limit is hit
 *
 * Settings (in `settings` table):
 * - pair_fail_window_sec (default 300)
 * - pair_fail_max        (default 5)
 * Falls back to auth_fail_window_sec/auth_fail_max if pair_* are not present.
 */
function sc_pairing_rate_limited(PDO $pdo, string $ip, string $kioskCode): array {
    $windowSec = (int)setting($pdo, 'pair_fail_window_sec', setting($pdo, 'auth_fail_window_sec', '300'));
    $maxFails  = (int)setting($pdo, 'pair_fail_max', setting($pdo, 'auth_fail_max', '5'));

    if ($windowSec < 30) { $windowSec = 30; }
    if ($maxFails  < 1)  { $maxFails  = 1; }

    if ($ip === '') {
        return ['limited' => false, 'window_sec' => $windowSec, 'max' => $maxFails];
    }

    $sql = "
        SELECT
            COUNT(*) AS c,
            UNIX_TIMESTAMP(MAX(occurred_at)) AS last_ts
        FROM kiosk_event_log
        WHERE event_type='pair'
          AND result='fail'
          AND ip_address = ?
          AND occurred_at >= (NOW() - INTERVAL {$windowSec} SECOND)
    ";
    $params = [$ip];

    if ($kioskCode !== '') {
        $sql .= " AND kiosk_code = ? ";
        $params[] = $kioskCode;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'last_ts' => null];

    $fails = (int)($row['c'] ?? 0);
    $lastTs = (int)($row['last_ts'] ?? 0);

    if ($fails < $maxFails) {
        return [
            'limited' => false,
            'remaining' => max(0, $maxFails - $fails),
            'window_sec' => $windowSec,
            'max' => $maxFails
        ];
    }

    $now = time();
    $retryAfter = $windowSec;
    if ($lastTs > 0) {
        $elapsed = max(0, $now - $lastTs);
        $retryAfter = max(1, $windowSec - $elapsed);
    }

    return [
        'limited' => true,
        'retry_after' => $retryAfter,
        'window_sec' => $windowSec,
        'max' => $maxFails
    ];
}

try {
    // ✅ Pairing mode gate (controlled by DB admin)
    // If pairing_mode is OFF, pairing must be rejected even if manager PIN is known.
    // Optional: pairing_mode_until (DATETIME) can enforce auto-expiry.
    $pairingModeRaw = strtolower(trim((string)setting($pdo, 'pairing_mode', '0')));
    $pairingModeOn  = in_array($pairingModeRaw, ['1','true','yes','on'], true);

    $pairingUntil = trim((string)setting($pdo, 'pairing_mode_until', ''));
    if ($pairingUntil !== '') {
        $ts = strtotime($pairingUntil);
        if ($ts !== false && time() > $ts) {
            // expired → auto-lock
            try {
                $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES ('pairing_mode','0')")->execute();
                $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES ('pairing_mode_until','')")->execute();
            } catch (Throwable $e) {
                // ignore
            }
            $pairingModeOn = false;
        }
    }

    if (!$pairingModeOn) {
        log_kiosk_event(
            $pdo,
            $kioskCode,
            null,
            null,
            $ipAddress,
            $userAgent,
            null,
            'pair',
            'fail',
            'pairing_mode_off',
            'Pairing mode is disabled'
        );
        json_response(['ok' => false, 'error' => 'pairing_mode_off'], 403);
    }

    // Basic kiosk authorization (must match stored kiosk_code)
    $expectedKiosk = (string)setting($pdo, 'kiosk_code', '');
    if ($expectedKiosk === '' || $kioskCode !== $expectedKiosk) {
        log_kiosk_event($pdo, $kioskCode, null, null, $ipAddress, $userAgent, null, 'pair', 'fail', 'kiosk_not_authorized');
        json_response(['ok' => false, 'error' => 'kiosk_not_authorized'], 403);
    }

    $currentVersion = (int)setting($pdo, 'pairing_version', '1');

    
    // ✅ Rate limit pairing attempts (brute-force protection)
    $rl = sc_pairing_rate_limited($pdo, $ipAddress, $kioskCode);
    if (!empty($rl['limited'])) {
        log_kiosk_event(
            $pdo,
            $kioskCode,
            (int)$currentVersion,
            null,
            $ipAddress,
            $userAgent,
            null,
            'pair',
            'fail',
            'rate_limited',
            'Too many pairing attempts',
            ['retry_after' => (int)($rl['retry_after'] ?? 0), 'window_sec' => (int)($rl['window_sec'] ?? 0), 'max' => (int)($rl['max'] ?? 0)]
        );
        header('Retry-After: ' . (string)($rl['retry_after'] ?? 60));
        json_response(['ok' => false, 'error' => 'rate_limited', 'retry_after' => (int)($rl['retry_after'] ?? 60)], 429);
    }

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
    $stmt = $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES ('paired_device_token_hash', ?)");
    $stmt->execute([$deviceHash]);

    // ✅ Auto-lock pairing mode after a successful pairing
    // (DB admin can enable it again when needed)
    try {
        $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES ('pairing_mode','0')")->execute();
        $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES ('pairing_mode_until','')")->execute();
    } catch (Throwable $e) {
        // ignore
    }

    // Legacy clean-up: remove plaintext token if present
    try { $pdo->prepare("DELETE FROM settings WHERE `key`='paired_device_token'")->execute(); } catch (Throwable $e) {}

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
