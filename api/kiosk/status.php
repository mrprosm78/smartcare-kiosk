<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

/**
 * status.php (SECURED)
 * - Returns SAFE settings only (no secrets)
 * - Adds `authorised` flag (based on X-Kiosk-Token header vs paired_device_token_hash)
 * - Only returns open_shifts when authorised + ui_show_open_shifts enabled
 */

function s(PDO $pdo, string $key, string $default = ''): string {
    return (string) setting($pdo, $key, $default);
}
function s_int(PDO $pdo, string $key, int $default): int {
    $v = trim(s($pdo, $key, (string)$default));
    return (is_numeric($v) ? (int)$v : $default);
}
function s_bool(PDO $pdo, string $key, bool $default): bool {
    $v = strtolower(trim(s($pdo, $key, $default ? '1' : '0')));
    return in_array($v, ['1','true','yes','on'], true);
}

/** Get request header (works across Apache/nginx/FastCGI) */
function get_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];

    // Fallback for some environments
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        foreach ($h as $k => $v) {
            if (strcasecmp((string)$k, $name) === 0) return (string)$v;
        }
    }
    return '';
}

/** Check if a column exists (supports optional 'nickname' column). */
function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        return ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

/** Fetch staff with open shifts (clocked in, not clocked out). */
function fetch_open_shifts(PDO $pdo, int $limit): array {
    $limit = max(1, min($limit, 50));
    $hasNickname = column_exists($pdo, 'kiosk_employees', 'nickname');

    $sql = $hasNickname
        ? "
            SELECT
                s.id            AS shift_id,
                s.employee_id   AS employee_id,
                s.clock_in_at   AS clock_in_at,
                e.first_name    AS first_name,
                e.last_name     AS last_name,
                e.nickname      AS nickname
            FROM kiosk_shifts s
            INNER JOIN kiosk_employees e ON e.id = s.employee_id
            WHERE s.is_closed = 0
              AND s.clock_out_at IS NULL
              AND e.is_active = 1
            ORDER BY s.clock_in_at DESC
            LIMIT {$limit}
        "
        : "
            SELECT
                s.id            AS shift_id,
                s.employee_id   AS employee_id,
                s.clock_in_at   AS clock_in_at,
                e.first_name    AS first_name,
                e.last_name     AS last_name
            FROM kiosk_shifts s
            INNER JOIN kiosk_employees e ON e.id = s.employee_id
            WHERE s.is_closed = 0
              AND s.clock_out_at IS NULL
              AND e.is_active = 1
            ORDER BY s.clock_in_at DESC
            LIMIT {$limit}
        ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $first = trim((string)($r['first_name'] ?? ''));
        $last  = trim((string)($r['last_name'] ?? ''));
        $nick  = trim((string)($r['nickname'] ?? ''));

        $primary = $nick !== '' ? $nick : ($first !== '' ? $first : 'Staff');
        $initial = ($last !== '') ? (mb_substr($last, 0, 1) . '.') : '';

        $out[] = [
            // Keeping these fields to avoid breaking any existing UI that expects them.
            // If you want stricter privacy, we can remove shift_id/employee_id later.
            'shift_id'    => (int)($r['shift_id'] ?? 0),
            'employee_id' => (int)($r['employee_id'] ?? 0),

            'clock_in_at' => (string)($r['clock_in_at'] ?? ''),
            'label'       => trim($primary . ' ' . $initial),
        ];
    }
    return $out;
}

function upsert_device(PDO $pdo, array $row): void {
    try {
        $stmt = $pdo->prepare(
            "
            INSERT INTO kiosk_devices
                (kiosk_code, device_token_hash, pairing_version, last_seen_at, last_seen_kind, last_authorised, last_error_code, last_ip, last_user_agent)
            VALUES
                (:kiosk_code, :device_token_hash, :pairing_version, UTC_TIMESTAMP(), :kind, :authorised, :error_code, :ip, :ua)
            ON DUPLICATE KEY UPDATE
                device_token_hash = VALUES(device_token_hash),
                pairing_version   = VALUES(pairing_version),
                last_seen_at      = UTC_TIMESTAMP(),
                last_seen_kind    = VALUES(last_seen_kind),
                last_authorised   = VALUES(last_authorised),
                last_error_code   = VALUES(last_error_code),
                last_ip           = VALUES(last_ip),
                last_user_agent   = VALUES(last_user_agent)
            "
        );
        $stmt->execute($row);
    } catch (Throwable $e) {
        // never break status
    }
}

/** Determine pairing + authorisation */
$storedHash = trim(s($pdo, 'paired_device_token_hash', ''));
$paired     = ($storedHash !== '');

$tokenHeader = trim(get_header('X-Kiosk-Token'));
$authorised  = false;

if ($paired && $tokenHeader !== '') {
    $givenHash = hash('sha256', $tokenHeader);
    $authorised = hash_equals($storedHash, $givenHash);
}

$kioskCode = trim(s($pdo, 'kiosk_code', ''));
$pairingVersion = s_int($pdo, 'pairing_version', 1);
$tokHash = ($tokenHeader !== '') ? hash('sha256', $tokenHeader) : null;

// record heartbeat
if ($kioskCode !== '') {
    $err = null;
    if (!$paired) {
        $err = 'kiosk_not_paired';
    } elseif ($tokenHeader === '') {
        $err = 'device_not_authorized';
    } elseif (!$authorised) {
        $err = 'device_not_authorized';
    }

    upsert_device($pdo, [
        'kiosk_code'        => $kioskCode,
        'device_token_hash' => $tokHash,
        'pairing_version'   => $pairingVersion,
        'kind'             => 'status',
        'authorised'       => $authorised ? 1 : 0,
        'error_code'       => $err,
        'ip'               => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua'               => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
}

// Settings
$uiShowOpen = s_bool($pdo, 'ui_show_open_shifts', false);
$openCount  = s_int($pdo, 'ui_open_shifts_count', 6);
$uiOpenShiftsShowTime = s_bool($pdo, 'ui_open_shifts_show_time', true);

$payload = [
    'ok' => true,

    // pairing state (safe)
    'paired'          => $paired,
    'authorised'      => $authorised,
    'pairing_version' => s_int($pdo, 'pairing_version', 1),

    // pairing mode (safe)
    // pairing_mode is controlled by DB admin; kiosk can only pair when this is enabled.
    // pairing_mode_until is optional (DATETIME). If present and in the past, pairing is effectively off.
    'pairing_mode'       => s_bool($pdo, 'pairing_mode', false),
    'pairing_mode_until' => trim(s($pdo, 'pairing_mode_until', '')),

    // kiosk policy (safe)
    'pin_length' => s_int($pdo, 'pin_length', 4),

    // sync tuning (safe)
    'ping_interval_ms'     => s_int($pdo, 'ping_interval_ms', 60000),
    'sync_interval_ms'     => s_int($pdo, 'sync_interval_ms', 30000),
    'sync_cooldown_ms'     => s_int($pdo, 'sync_cooldown_ms', 8000),
    'sync_batch_size'      => s_int($pdo, 'sync_batch_size', 20),
    'max_sync_attempts'    => s_int($pdo, 'max_sync_attempts', 10),
    'sync_backoff_base_ms' => s_int($pdo, 'sync_backoff_base_ms', 2000),
    'sync_backoff_cap_ms'  => s_int($pdo, 'sync_backoff_cap_ms', 300000),

    // offline storage security
    'offline_allow_unencrypted_pin' => s_bool($pdo, 'offline_allow_unencrypted_pin', false),

    // optional UI behaviour (safe)
    'ui_thank_ms'   => s_int($pdo, 'ui_thank_ms', 1500),
    'ui_show_clock' => s_bool($pdo, 'ui_show_clock', true),

    // server-driven kiosk auto-reload
    'ui_reload_enabled'  => s_bool($pdo, 'ui_reload_enabled', false),
    'ui_reload_check_ms' => s_int($pdo, 'ui_reload_check_ms', 60000),
    'ui_version'         => trim(s($pdo, 'ui_version', '1')),
    'ui_reload_token'    => trim(s($pdo, 'ui_reload_token', '0')),


    // UI text (safe)
    'ui_text' => [
        'kiosk_title'          => trim(s($pdo, 'ui_text.kiosk_title', 'Clock Kiosk')),
        'kiosk_subtitle'       => trim(s($pdo, 'ui_text.kiosk_subtitle', 'Kiosk Mode')),
        'employee_notice'      => trim(s($pdo, 'ui_text.employee_notice', 'Enter your PIN on the next screen.')),
        'not_paired_message'   => trim(s($pdo, 'ui_text.not_paired_message', 'This device is not paired.')),
        'not_authorised_message' => trim(s($pdo, 'ui_text.not_authorised_message', 'This device is not authorised.')),
    ],

    // open-shifts list controls (safe toggle only)
    'ui_show_open_shifts'  => $uiShowOpen,
    'ui_open_shifts_count' => $openCount,
    'ui_open_shifts_show_time' => $uiOpenShiftsShowTime,

    // diagnostics (safe)
    'debug_mode' => s_bool($pdo, 'debug_mode', false),
];

// ✅ Only include staff/open shifts if this request is AUTHORISED
if ($authorised && $uiShowOpen) {
    $payload['open_shifts'] = fetch_open_shifts($pdo, $openCount);
} else {
    $payload['open_shifts'] = [];
}

json_response($payload);