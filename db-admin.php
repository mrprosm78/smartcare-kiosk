<?php
declare(strict_types=1);

/**
 * SmartCare Kiosk DB Admin
 * - Install/repair schema + seed default settings
 * - Reset: drop all tables + recreate + seed
 *
 * SECURITY: Delete this file after use.
 */

const RESET_PIN = '5850'; // required for reset

require __DIR__ . '/db.php'; // must define $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "db.php must define \$pdo as a PDO connection.";
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function exec_all(PDO $pdo, array $sqlList): void {
    foreach ($sqlList as $sql) {
        $pdo->exec($sql);
    }
}

function create_schema(PDO $pdo): void {
    $schema = [

"CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

"CREATE TABLE IF NOT EXISTS `employees` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_employee_code` (`employee_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

"CREATE TABLE IF NOT EXISTS `shifts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `clock_in_at` datetime NOT NULL,
  `clock_out_at` datetime DEFAULT NULL,
  `duration_minutes` int(10) UNSIGNED DEFAULT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift_employee_open` (`employee_id`,`is_closed`),
  KEY `idx_shift_clockin` (`employee_id`,`clock_in_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

"CREATE TABLE IF NOT EXISTS `punch_events` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_uuid` char(36) NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `action` enum('IN','OUT') NOT NULL,
  `device_time` datetime NOT NULL,
  `received_at` datetime NOT NULL,
  `effective_time` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `result_status` varchar(20) NOT NULL DEFAULT 'processed',
  `error_code` varchar(50) DEFAULT NULL,
  `shift_id` bigint(20) DEFAULT NULL,
  `kiosk_code` varchar(50) DEFAULT NULL,
  `device_token_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_event_uuid` (`event_uuid`),
  KEY `idx_punch_employee_time` (`employee_id`,`effective_time`),
  KEY `idx_punch_shift` (`shift_id`),
  KEY `idx_punch_kiosk_time` (`kiosk_code`,`effective_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

"CREATE TABLE IF NOT EXISTS `kiosk_event_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  `kiosk_code` varchar(50) DEFAULT NULL,
  `pairing_version` int(10) UNSIGNED DEFAULT NULL,
  `device_token_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `result` varchar(20) NOT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  PRIMARY KEY (`id`),
  KEY `idx_kelog_time` (`occurred_at`),
  KEY `idx_kelog_kiosk_time` (`kiosk_code`,`occurred_at`),
  KEY `idx_kelog_employee_time` (`employee_id`,`occurred_at`),
  KEY `idx_kelog_type_time` (`event_type`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

"CREATE TABLE IF NOT EXISTS `kiosk_health_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `kiosk_code` varchar(50) DEFAULT NULL,
  `pairing_version` int(10) UNSIGNED DEFAULT NULL,
  `device_token_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `is_online` tinyint(1) NOT NULL DEFAULT 1,
  `ping_ok` tinyint(1) NOT NULL DEFAULT 1,
  `queue_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_error_code` varchar(50) DEFAULT NULL,
  `ui_version` varchar(50) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  PRIMARY KEY (`id`),
  KEY `idx_khlog_time` (`reported_at`),
  KEY `idx_khlog_kiosk_time` (`kiosk_code`,`reported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
    ];

    exec_all($pdo, $schema);
}

function seed_defaults(PDO $pdo, array $overrides = []): void {
    $defaults = [
        'allow_plain_pin' => '1',
        'debug_mode' => '0',
        'is_paired' => '0',

        // kiosk identity
        'kiosk_code' => $overrides['kiosk_code'] ?? 'KIOSK-1',

        // pairing
        'pairing_code' => $overrides['pairing_code'] ?? '5850',
        'pairing_version' => '1',
        'paired_device_token' => '',

        // tuning
        'pin_length' => '4',
        'min_seconds_between_punches' => '5',
        'max_shift_minutes' => '960',
        'max_sync_attempts' => '10',

        'ping_interval_ms' => '60000',
        'sync_interval_ms' => '30000',
        'sync_cooldown_ms' => '8000',
        'sync_batch_size' => '20',
        'sync_backoff_base_ms' => '2000',
        'sync_backoff_cap_ms' => '300000',

        // UI
        'ui_thank_ms' => '3000',
        'ui_show_clock' => '1',
        'ui_reload_enabled' => '0',
        'ui_reload_check_ms' => '60000',
        'ui_version' => '1',

        // optional / legacy flags (keep if you use them)
        'kiosk_client_version' => '2',
        'kiosk_force_reload' => '0',
    ];

    // merge overrides (only keys we know)
    foreach ($overrides as $k => $v) {
        if (array_key_exists($k, $defaults)) {
            $defaults[$k] = (string)$v;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO settings (`key`,`value`) VALUES (?,?)
        ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=CURRENT_TIMESTAMP()
    ");

    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

function drop_all(PDO $pdo): void {
    $tables = ['punch_events','shifts','employees','kiosk_event_log','kiosk_health_log','settings'];

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

// -------------------- controller --------------------
$action = (string)($_GET['action'] ?? '');
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? $action);

    $kioskCode = trim((string)($_POST['kiosk_code'] ?? 'KIOSK-1'));
    $pairingCode = trim((string)($_POST['pairing_code'] ?? '5850'));

    try {
        if ($action === 'install') {
            create_schema($pdo);
            seed_defaults($pdo, ['kiosk_code'=>$kioskCode, 'pairing_code'=>$pairingCode]);
            $msg = "✅ Installed/Repaired schema + seeded default settings (ui_thank_ms = 3000).";
        } elseif ($action === 'reset') {
            $pin = (string)($_POST['reset_pin'] ?? '');
            if ($pin !== RESET_PIN) {
                throw new RuntimeException("Reset PIN incorrect.");
            }
            drop_all($pdo);
            create_schema($pdo);
            seed_defaults($pdo, ['kiosk_code'=>$kioskCode, 'pairing_code'=>$pairingCode]);
            $msg = "✅ RESET complete: dropped & recreated all tables + seeded default settings.";
        } else {
            $err = "Unknown action.";
        }
    } catch (Throwable $e) {
        $err = "❌ " . $e->getMessage();
    }
}

// status info
$exists = [
    'settings' => table_exists($pdo, 'settings'),
    'employees' => table_exists($pdo, 'employees'),
    'shifts' => table_exists($pdo, 'shifts'),
    'punch_events' => table_exists($pdo, 'punch_events'),
    'kiosk_event_log' => table_exists($pdo, 'kiosk_event_log'),
    'kiosk_health_log' => table_exists($pdo, 'kiosk_health_log'),
];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Kiosk DB Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:900px;margin:24px auto;padding:0 16px}
    .box{border:1px solid #ddd;border-radius:12px;padding:16px;margin:12px 0}
    .ok{background:#ecfdf5;border-color:#10b981}
    .err{background:#fef2f2;border-color:#ef4444}
    label{display:block;margin:8px 0 4px}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:10px}
    button{padding:10px 14px;border:0;border-radius:10px;cursor:pointer}
    .btn{background:#111827;color:#fff}
    .btn2{background:#ef4444;color:#fff}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
    small{color:#6b7280}
    ul{margin:8px 0 0 18px}
  </style>
</head>
<body>
  <h1>Kiosk DB Admin</h1>
  <p><b>IMPORTANT:</b> Delete this file after use.</p>

  <?php if ($msg): ?>
    <div class="box ok"><?php echo h($msg); ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="box err"><?php echo h($err); ?></div>
  <?php endif; ?>

  <div class="box">
    <h3>Current table status</h3>
    <ul>
      <?php foreach ($exists as $t => $yes): ?>
        <li><?php echo h($t); ?>: <b><?php echo $yes ? 'YES' : 'NO'; ?></b></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="box">
    <h3>Install / Repair (safe)</h3>
    <form method="post">
      <input type="hidden" name="action" value="install">
      <label>Kiosk Code (settings.kiosk_code)</label>
      <input name="kiosk_code" value="KIOSK-1">
      <label>Manager Pairing Code (settings.pairing_code)</label>
      <input name="pairing_code" value="5850">
      <p><small>Seeds defaults including <code>ui_thank_ms=3000</code> and <code>is_paired=0</code>.</small></p>
      <button class="btn" type="submit">Install / Repair</button>
    </form>
  </div>

  <div class="box">
    <h3>RESET (danger)</h3>
    <form method="post" onsubmit="return confirm('This will DELETE ALL DATA and recreate tables. Continue?')">
      <input type="hidden" name="action" value="reset">
      <label>Reset PIN (required)</label>
      <input name="reset_pin" placeholder="Enter 5850">
      <label>Kiosk Code (settings.kiosk_code)</label>
      <input name="kiosk_code" value="KIOSK-1">
      <label>Manager Pairing Code (settings.pairing_code)</label>
      <input name="pairing_code" value="5850">
      <p><small>Drops all tables, recreates, seeds defaults.</small></p>
      <button class="btn2" type="submit">RESET Database</button>
    </form>
  </div>

</body>
</html>
