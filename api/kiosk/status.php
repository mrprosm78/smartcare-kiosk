<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

/**
 * status.php
 * - Returns SAFE settings only (no secrets)
 * - Used by kiosk.html to tune runtime behaviour
 * - Optional: returns list of staff currently clocked-in (open shifts)
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

/**
 * Check if a column exists (used to safely support optional 'nickname' column).
 */
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
        // If information_schema is restricted for some reason, fail safe.
        return false;
    }
}

/**
 * Fetch staff with open shifts (clocked in, not clocked out).
 * Returns most recent clock-ins first.
 */
function fetch_open_shifts(PDO $pdo, int $limit): array {
    $limit = max(1, min($limit, 50));

    $hasNickname = column_exists($pdo, 'employees', 'nickname');

    if ($hasNickname) {
        $sql = "
            SELECT
                s.id            AS shift_id,
                s.employee_id   AS employee_id,
                s.clock_in_at   AS clock_in_at,
                e.first_name    AS first_name,
                e.last_name     AS last_name,
                e.nickname      AS nickname
            FROM shifts s
            INNER JOIN employees e ON e.id = s.employee_id
            WHERE s.is_closed = 0
              AND s.clock_out_at IS NULL
              AND e.is_active = 1
            ORDER BY s.clock_in_at DESC
            LIMIT {$limit}
        ";
    } else {
        $sql = "
            SELECT
                s.id            AS shift_id,
                s.employee_id   AS employee_id,
                s.clock_in_at   AS clock_in_at,
                e.first_name    AS first_name,
                e.last_name     AS last_name
            FROM shifts s
            INNER JOIN employees e ON e.id = s.employee_id
            WHERE s.is_closed = 0
              AND s.clock_out_at IS NULL
              AND e.is_active = 1
            ORDER BY s.clock_in_at DESC
            LIMIT {$limit}
        ";
    }

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Build a safe display label (privacy-friendly default).
    $out = [];
    foreach ($rows as $r) {
        $first = trim((string)($r['first_name'] ?? ''));
        $last  = trim((string)($r['last_name'] ?? ''));
        $nick  = trim((string)($r['nickname'] ?? ''));

        // Prefer nickname if present, otherwise first name.
        $primary = $nick !== '' ? $nick : ($first !== '' ? $first : 'Staff');

        // Add last initial if available (e.g., "Sarah B.")
        $initial = ($last !== '') ? (mb_substr($last, 0, 1) . '.') : '';

        $out[] = [
            'shift_id'    => (int)$r['shift_id'],
            'employee_id' => (int)$r['employee_id'],
            'clock_in_at' => (string)$r['clock_in_at'],
            'label'       => trim($primary . ' ' . $initial),
        ];
    }

    return $out;
}

// Settings
$uiShowOpen = s_bool($pdo, 'ui_show_open_shifts', false);
$openCount  = s_int($pdo, 'ui_open_shifts_count', 6);

$payload = [
    'ok' => true,

    // pairing state (safe)
    'paired' => s_bool($pdo, 'is_paired', false),
    'pairing_version' => s_int($pdo, 'pairing_version', 1),

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

    // optional UI behaviour (safe)
    'ui_thank_ms'   => s_int($pdo, 'ui_thank_ms', 1500),
    'ui_show_clock' => s_bool($pdo, 'ui_show_clock', true),

    // optional: server-driven kiosk auto-reload
    'ui_reload_enabled'  => s_bool($pdo, 'ui_reload_enabled', false),
    'ui_reload_check_ms' => s_int($pdo, 'ui_reload_check_ms', 60000),
    'ui_version'         => trim(s($pdo, 'ui_version', '1')),

    // NEW: open-shifts list controls
    'ui_show_open_shifts' => $uiShowOpen,
    'ui_open_shifts_count'=> $openCount,

    // diagnostics (safe)
    'debug_mode' => s_bool($pdo, 'debug_mode', false),
];

// NEW: include open shifts list only when enabled
if ($uiShowOpen) {
    $payload['open_shifts'] = fetch_open_shifts($pdo, $openCount);
} else {
    $payload['open_shifts'] = [];
}

json_response($payload);
