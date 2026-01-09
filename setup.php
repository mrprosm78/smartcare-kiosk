<?php
//https://zapsite.co.uk/kiosk-dev/setup.php?action=install
//https://zapsite.co.uk/kiosk-dev/setup.php?action=reset&pin=
declare(strict_types=1);

/**
 * SmartCare Kiosk – Setup / Reset
 * Place this file at: /kiosk-office/setup.php
 * db.php is in site root: /db.php
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

const RESET_PIN = '5850';

// ✅ db.php is one folder ABOVE kiosk-dev
require __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('Database connection not available ($pdo missing)');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function seed_settings(PDO $pdo): void {
  $settings = [
    'kiosk_code' => 'KIOSK-1',
    'is_paired' => '0',
    'paired_device_token' => '',
    'pairing_version' => '1',
    'pairing_code' => '5850',

    'pin_length' => '4',
    'allow_plain_pin' => '1',

    'min_seconds_between_punches' => '20',
    'max_shift_minutes' => '960',

    'ui_thank_ms' => '3000',
    'ui_version' => '1',
    'ui_reload_enabled' => '0',
    'ui_reload_check_ms' => '60000',

    'ping_interval_ms' => '60000',
    'sync_interval_ms' => '30000',
    'sync_cooldown_ms' => '8000',
    'sync_batch_size' => '20',
    'max_sync_attempts' => '10',
    'sync_backoff_base_ms' => '2000',
    'sync_backoff_cap_ms' => '300000',
  ];

  $stmt = $pdo->prepare("REPLACE INTO settings (`key`,`value`) VALUES (?,?)");
  foreach ($settings as $k => $v) $stmt->execute([$k, $v]);
}

function create_tables(PDO $pdo): void {

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
      `key` VARCHAR(100) PRIMARY KEY,
      `value` TEXT NOT NULL,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS employees (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_code VARCHAR(50) UNIQUE,
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      pin_hash VARCHAR(255),
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

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
      KEY idx_open (employee_id, is_closed)
    ) ENGINE=InnoDB;
  ");

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
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");
}

function drop_all(PDO $pdo): void {
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
  foreach (['punch_events','shifts','employees','settings'] as $t) {
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
