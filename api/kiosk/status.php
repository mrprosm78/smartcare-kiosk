<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

function clamp_int(int $v, int $min, int $max): int {
    return max($min, min($max, $v));
}

$pinLen   = clamp_int(s_int($pdo, 'pin_length', 4), 2, 8);

$pingMs   = clamp_int(s_int($pdo, 'ping_interval_ms', 60000), 5000, 600000);
$syncMs   = clamp_int(s_int($pdo, 'sync_interval_ms', 30000), 5000, 600000);
$coolMs   = clamp_int(s_int($pdo, 'sync_cooldown_ms', 8000), 0, 600000);

$batch    = clamp_int(s_int($pdo, 'sync_batch_size', 20), 1, 100);
$attempts = clamp_int(s_int($pdo, 'max_sync_attempts', 10), 1, 50);

$backBase = clamp_int(s_int($pdo, 'sync_backoff_base_ms', 2000), 250, 60000);
$backCap  = clamp_int(s_int($pdo, 'sync_backoff_cap_ms', 300000), $backBase, 3600000);

$thankMs  = clamp_int(s_int($pdo, 'ui_thank_ms', 1500), 500, 10000);

json_response([
    'ok' => true,

    // pairing state (safe)
    'paired' => s_bool($pdo, 'is_paired', false),
    'pairing_version' => s_int($pdo, 'pairing_version', 1),

    // kiosk policy (safe)
    'pin_length' => $pinLen,

    // sync tuning (safe)
    'ping_interval_ms'     => $pingMs,
    'sync_interval_ms'     => $syncMs,
    'sync_cooldown_ms'     => $coolMs,
    'sync_batch_size'      => $batch,
    'max_sync_attempts'    => $attempts,
    'sync_backoff_base_ms' => $backBase,
    'sync_backoff_cap_ms'  => $backCap,

    // optional UI behaviour (safe)
    'ui_thank_ms'   => $thankMs,
    'ui_show_clock' => s_bool($pdo, 'ui_show_clock', true),

    // diagnostics (safe)
    'debug_mode' => s_bool($pdo, 'debug_mode', false),
    'kiosk_client_version' => s($pdo, 'kiosk_client_version', '1'),
    'kiosk_force_reload'   => s_bool($pdo, 'kiosk_force_reload', false),
]);
