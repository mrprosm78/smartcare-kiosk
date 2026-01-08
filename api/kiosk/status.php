<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

/**
 * status.php
 * - Returns SAFE settings only (no secrets)
 * - Used by kiosk.html to tune runtime behaviour
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

json_response([
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
    // - Set ui_reload_enabled=1 and bump ui_version to force reload
    'ui_reload_enabled'  => s_bool($pdo, 'ui_reload_enabled', false),
    'ui_reload_check_ms' => s_int($pdo, 'ui_reload_check_ms', 60000),
    'ui_version'         => trim(s($pdo, 'ui_version', '1')),

    // diagnostics (safe)
    'debug_mode' => s_bool($pdo, 'debug_mode', false),
]);
