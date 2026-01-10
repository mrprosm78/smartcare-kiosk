<?php
// https://zapsite.co.uk/kiosk-dev/setup.php?action=install
// https://zapsite.co.uk/kiosk-dev/setup.php?action=reset&pin=5850
declare(strict_types=1);

/**
 * SmartCare Kiosk – Setup / Reset
 * Place this file at: /kiosk-dev/setup.php  (or whatever your folder is)
 * db.php should be in same folder (as per your current require)
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

const RESET_PIN = '5850';

// ✅ db.php in same folder (keep as you have it)
require __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('Database connection not available ($pdo missing)');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function seed_settings(PDO $pdo): void {
  $settings = [
    // Core pairing + kiosk identity
    'kiosk_code' => 'KIOSK-1',
    'is_paired' => '0',
    'paired_device_token_hash' => '',
    // legacy (kept for older installs; will be cleaned automatically)
    'paired_device_token' => '',
    'pairing_version' => '1',
    'pairing_code' => '5850',

    // Pairing gate (DB admin-controlled)
    // If pairing_mode is 0, /api/kiosk/pair.php rejects pairing even if manager PIN is known.
    // Optional: set pairing_mode_until to a DATETIME to auto-expire pairing (e.g. NOW()+INTERVAL 10 MINUTE)
    'pairing_mode' => '0',
    'pairing_mode_until' => '',

    // PIN
    'pin_length' => '4',
    'allow_plain_pin' => '1',

    // Punch policy
    'min_seconds_between_punches' => '20',
    'max_shift_minutes' => '960',

    // UI
    'ui_thank_ms' => '3000',
    'ui_version' => '1',
    'ui_reload_enabled' => '0',
    'ui_reload_check_ms' => '60000',

    // NEW: Open shifts panel (Currently on shift)
    'ui_show_open_shifts' => '1',     // toggle (0/1)
    'ui_open_shifts_count' => '6',    // how many to show

    // NEW: brute force / lockout controls (used by punch.php logging)
    'auth_fail_window_sec' => '300',  // 5 min window
    'auth_fail_max' => '5',           // max failures in window
    'auth_lockout_sec' => '300',      // (reserved) future use; current UI returns 429 only

    // Pairing brute-force protection (applies to /api/kiosk/pair.php)
    'pair_fail_window_sec' => '600',  // 10 min window
    'pair_fail_max' => '5',           // max failed pairing attempts in window
    // Sync tuning
    'ping_interval_ms' => '60000',
    'sync_interval_ms' => '30000',
    'sync_cooldown_ms' => '8000',
    'sync_batch_size' => '20',
    'max_sync_attempts' => '10',
    'sync_backoff_base_ms' => '2000',
    'sync_backoff_cap_ms' => '300000',

    // Optional diagnostics
    'debug_mode' => '0',
  ];

  $stmt = $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES (?,?)");
  foreach ($settings as $k => $v) {
    $stmt->execute([$k, $v]);
  }
}

function create_tables(PDO $pdo): void {

  // SETTINGS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
      `key` VARCHAR(100) PRIMARY KEY,
      `value` TEXT NOT NULL,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  // EMPLOYEES (NEW: nickname)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS employees (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_code VARCHAR(50) UNIQUE,
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      nickname VARCHAR(100) NULL,
      pin_hash VARCHAR(255),
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_active (is_active)
    ) ENGINE=InnoDB;
  ");

  // SHIFTS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS shifts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_id INT UNSIGNED,
      clock_in_at DATETIME NOT NULL,
      clock_out_at DATETIME NULL,
      duration_minutes INT NULL,
      is_closed TINYINT(1) DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_open (employee_id, is_closed),
      KEY idx_clock_in (clock_in_at)
    ) ENGINE=InnoDB;
  ");

  // PUNCH EVENTS (kept employee_id NOT NULL as per your current approach)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS punch_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_uuid CHAR(36) UNIQUE,
      employee_id INT UNSIGNED,
      action ENUM('IN','OUT'),
      device_time DATETIME,
      received_at DATETIME,
      effective_time DATETIME,
      result_status VARCHAR(20),
      error_code VARCHAR(50),
      shift_id BIGINT UNSIGNED NULL,
      kiosk_code VARCHAR(50),
      device_token_hash CHAR(64),
      ip_address VARCHAR(45),
      user_agent VARCHAR(255),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY idx_employee_time (employee_id, effective_time),
      KEY idx_shift (shift_id),
      KEY idx_result (result_status),
      KEY idx_kiosk (kiosk_code)
    ) ENGINE=InnoDB;
  ");

  // NEW: KIOSK EVENT LOG (used for invalid PIN + auth events + pairing events)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_event_log (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      kiosk_code VARCHAR(50) NULL,
      pairing_version INT NULL,
      device_token_hash CHAR(64) NULL,
      ip_address VARCHAR(45) NULL,
      user_agent VARCHAR(255) NULL,
      employee_id INT UNSIGNED NULL,
      event_type VARCHAR(50) NOT NULL,
      result VARCHAR(20) NOT NULL,
      error_code VARCHAR(50) NULL,
      message VARCHAR(255) NULL,
      meta_json JSON NULL,
      KEY idx_time (occurred_at),
      KEY idx_ip_time (ip_address, occurred_at),
      KEY idx_device_time (device_token_hash, occurred_at),
      KEY idx_event (event_type, result),
      KEY idx_employee (employee_id)
    ) ENGINE=InnoDB;
  ");

  // NEW: KIOSK HEALTH LOG (optional, for device heartbeat)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_health_log (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      kiosk_code VARCHAR(50) NULL,
      pairing_version INT NULL,
      device_token_hash CHAR(64) NULL,
      online TINYINT(1) DEFAULT 1,
      queue_size INT DEFAULT 0,
      last_error_code VARCHAR(50) NULL,
      ui_version VARCHAR(20) NULL,
      meta_json JSON NULL,
      KEY idx_time (recorded_at),
      KEY idx_device_time (device_token_hash, recorded_at)
    ) ENGINE=InnoDB;
  ");
}

function drop_all(PDO $pdo): void {
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
  foreach ([
    'kiosk_health_log',
    'kiosk_event_log',
    'punch_events',
    'shifts',
    'employees',
    'settings'
  ] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS `$t`");
  }
  $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

$action = $_GET['action'] ?? '';

try {
  if ($action === 'install') {
    create_tables($pdo);
    seed_settings($pdo);
    exit("✅ Install / repair completed");
  }

  if ($action === 'reset') {
    if (($_GET['pin'] ?? '') !== RESET_PIN) {
      http_response_code(403);
      exit("❌ Invalid reset PIN");
    }
    drop_all($pdo);
    create_tables($pdo);
    seed_settings($pdo);
    exit("🔥 Database reset completed");
  }

  echo '<h3>SmartCare Kiosk – Setup</h3>
  <ul>
    <li><a href="?action=install">Install / Repair</a></li>
    <li><a href="?action=reset&pin=5850" onclick="return confirm(\'RESET DATABASE?\')">Reset (PIN required)</a></li>
  </ul>';

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>ERROR:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
}
