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
  // Seed kiosk_settings with values + metadata (future manager-editable filtering)
  $defs = [
    // ---------------------------
    // Identity + Pairing
    // ---------------------------
    [
      'key' => 'kiosk_code',
      'value' => 'KIOSK-1',
      'group' => 'identity',
      'label' => 'Kiosk Code',
      'description' => 'Short identifier for this kiosk (used in logs and API headers).',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 10,
      'secret' => 0,
    ],
    [
      'key' => 'is_paired',
      'value' => '0',
      'group' => 'pairing',
      'label' => 'Is Paired',
      'description' => '0/1 flag used by UI to show paired status. Pairing sets this to 1; revoke clears it.',
      'type' => 'bool',
      'editable_by' => 'none',
      'sort' => 20,
      'secret' => 0,
    ],
    [
      'key' => 'paired_device_token_hash',
      'value' => '',
      'group' => 'pairing',
      'label' => 'Paired Device Token Hash',
      'description' => 'SHA-256 hash of the paired device token. Only the paired device can authorise requests.',
      'type' => 'secret',
      'editable_by' => 'none',
      'sort' => 30,
      'secret' => 1,
    ],
    // legacy (kept for older installs; cleaned automatically by endpoints)
    [
      'key' => 'paired_device_token',
      'value' => '',
      'group' => 'pairing',
      'label' => 'Legacy Paired Device Token',
      'description' => 'Legacy token storage. Not used for auth; kept for backward compatibility and deleted automatically when possible.',
      'type' => 'secret',
      'editable_by' => 'none',
      'sort' => 31,
      'secret' => 1,
    ],
    [
      'key' => 'pairing_version',
      'value' => '1',
      'group' => 'pairing',
      'label' => 'Pairing Version',
      'description' => 'Bumps whenever pairing is revoked/reset; helps clients detect pairing changes.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 40,
      'secret' => 0,
    ],
    [
      'key' => 'pairing_code',
      'value' => '5850',
      'group' => 'pairing',
      'label' => 'Pairing Passcode',
      'description' => 'Passcode required to pair a device (only works if pairing_mode is enabled).',
      'type' => 'secret',
      'editable_by' => 'superadmin',
      'sort' => 50,
      'secret' => 1,
    ],
    [
      'key' => 'pairing_mode',
      'value' => '0',
      'group' => 'pairing',
      'label' => 'Pairing Mode Enabled',
      'description' => 'When 0, /api/kiosk/pair.php rejects pairing even if the passcode is known.',
      'type' => 'bool',
      'editable_by' => 'superadmin',
      'sort' => 60,
      'secret' => 0,
    ],
    [
      'key' => 'pairing_mode_until',
      'value' => '',
      'group' => 'pairing',
      'label' => 'Pairing Mode Until',
      'description' => 'Optional UTC datetime. If set and expired, pairing is auto-disabled.',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 70,
      'secret' => 0,
    ],

    // ---------------------------
    // PIN + Security
    // ---------------------------
    [
      'key' => 'pin_length',
      'value' => '4',
      'group' => 'security',
      'label' => 'PIN Length',
      'description' => 'Number of digits required for staff PINs.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 110,
      'secret' => 0,
    ],
    [
      'key' => 'allow_plain_pin',
      'value' => '1',
      'group' => 'security',
      'label' => 'Allow Plain PIN',
      'description' => 'If 1, allows plain PIN entries in kiosk_employees (for simple installs). Prefer hashed PINs in production.',
      'type' => 'bool',
      'editable_by' => 'superadmin',
      'sort' => 120,
      'secret' => 0,
    ],
    [
      'key' => 'min_seconds_between_punches',
      'value' => '20',
      'group' => 'security',
      'label' => 'Min Seconds Between Punches',
      'description' => 'Rate limit per employee to prevent double-taps and accidental repeated punches.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 130,
      'secret' => 0,
    ],
    [
      'key' => 'auth_fail_window_sec',
      'value' => '300',
      'group' => 'security',
      'label' => 'Auth Fail Window (seconds)',
      'description' => 'Time window to count failed PIN attempts.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 140,
      'secret' => 0,
    ],
    [
      'key' => 'auth_fail_max',
      'value' => '5',
      'group' => 'security',
      'label' => 'Max Auth Failures',
      'description' => 'Max failed PIN attempts within auth_fail_window_sec before returning 429.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 150,
      'secret' => 0,
    ],
    [
      'key' => 'auth_lockout_sec',
      'value' => '300',
      'group' => 'security',
      'label' => 'Lockout (seconds)',
      'description' => 'Reserved for future UI lockout timer. Server currently returns 429 on too many attempts.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 160,
      'secret' => 0,
    ],
    [
      'key' => 'pair_fail_window_sec',
      'value' => '600',
      'group' => 'security',
      'label' => 'Pair Fail Window (seconds)',
      'description' => 'Time window to count failed pairing attempts.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 170,
      'secret' => 0,
    ],
    [
      'key' => 'pair_fail_max',
      'value' => '5',
      'group' => 'security',
      'label' => 'Max Pair Failures',
      'description' => 'Max failed pairing attempts within pair_fail_window_sec before returning 429.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 180,
      'secret' => 0,
    ],

    // ---------------------------
    // Limits
    // ---------------------------
    [
      'key' => 'max_shift_minutes',
      'value' => '960',
      'group' => 'limits',
      'label' => 'Max Shift Minutes',
      'description' => 'Maximum shift length used for future auto-close logic and validation.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 210,
      'secret' => 0,
    ],

    // ---------------------------
    // UI Behaviour
    // ---------------------------
    [
      'key' => 'ui_version',
      'value' => '1',
      'group' => 'ui',
      'label' => 'UI Version',
      'description' => 'Cache-busting version for CSS/JS. Bump to force clients to reload assets.',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 310,
      'secret' => 0,
    ],
    [
      'key' => 'ui_thank_ms',
      'value' => '3000',
      'group' => 'ui',
      'label' => 'Thank You Screen (ms)',
      'description' => 'How long to show the success screen after a punch.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 320,
      'secret' => 0,
    ],
    [
      'key' => 'ui_show_clock',
      'value' => '1',
      'group' => 'ui',
      'label' => 'Show Clock',
      'description' => 'If 1, kiosk shows the current time and date panel.',
      'type' => 'bool',
      'editable_by' => 'manager',
      'sort' => 325,
      'secret' => 0,
    ],
    [
      'key' => 'ui_show_open_shifts',
      'value' => '1',
      'group' => 'ui',
      'label' => 'Show Open Shifts Panel',
      'description' => 'If 1 and authorised, the kiosk can display currently clocked-in staff.',
      'type' => 'bool',
      'editable_by' => 'manager',
      'sort' => 330,
      'secret' => 0,
    ],
    [
      'key' => 'ui_open_shifts_count',
      'value' => '6',
      'group' => 'ui',
      'label' => 'Open Shifts Count',
      'description' => 'How many open shifts to return/display in the kiosk UI.',
      'type' => 'int',
      'editable_by' => 'manager',
      'sort' => 340,
      'secret' => 0,
    ],
    [
      'key' => 'ui_open_shifts_show_time',
      'value' => '1',
      'group' => 'ui',
      'label' => 'Open Shifts: Show Time & Duration',
      'description' => 'If 1, the open shifts panel shows clock-in time and elapsed duration. If 0, it only shows the name/status.',
      'type' => 'bool',
      'editable_by' => 'manager',
      'sort' => 345,
      'secret' => 0,
    ],
    [
      'key' => 'ui_reload_enabled',
      'value' => '0',
      'group' => 'ui',
      'label' => 'UI Auto Reload Enabled',
      'description' => 'If 1, kiosk periodically checks for ui_version changes and reloads.',
      'type' => 'bool',
      'editable_by' => 'superadmin',
      'sort' => 350,
      'secret' => 0,
    ],
    [
      'key' => 'ui_reload_check_ms',
      'value' => '60000',
      'group' => 'ui',
      'label' => 'UI Reload Check (ms)',
      'description' => 'How often the kiosk checks for ui_version changes when ui_reload_enabled=1.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 360,
      'secret' => 0,
    ],

    [
      'key' => 'ui_reload_token',
      'value' => '0',
      'group' => 'ui',
      'label' => 'UI Reload Token',
      'description' => 'Change this value to force the kiosk to reload (even if ui_version is unchanged). Used as an operational "refresh now" switch.',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 365,
      'secret' => 0,
    ],

    // ---------------------------
    // Sync / Telemetry
    // ---------------------------
    [
      'key' => 'ping_interval_ms',
      'value' => '60000',
      'group' => 'health',
      'label' => 'Ping Interval (ms)',
      'description' => 'How often the client pings /api/kiosk/ping.php for health telemetry.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 410,
      'secret' => 0,
    ],
    [
      'key' => 'device_offline_after_sec',
      'value' => '300',
      'group' => 'health',
      'label' => 'Device Offline After (sec)',
      'description' => 'Consider the kiosk device offline if no authorised ping/status is received within this many seconds.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 415,
      'secret' => 0,
    ],
    [
      'key' => 'sync_interval_ms',
      'value' => '30000',
      'group' => 'sync',
      'label' => 'Sync Interval (ms)',
      'description' => 'How often the client attempts background sync (if enabled in JS).',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 420,
      'secret' => 0,
    ],
    [
      'key' => 'sync_cooldown_ms',
      'value' => '8000',
      'group' => 'sync',
      'label' => 'Sync Cooldown (ms)',
      'description' => 'Cooldown between sync attempts after an error.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 430,
      'secret' => 0,
    ],
    [
      'key' => 'sync_batch_size',
      'value' => '20',
      'group' => 'sync',
      'label' => 'Sync Batch Size',
      'description' => 'Max number of queued records to send per sync batch.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 440,
      'secret' => 0,
    ],
    [
      'key' => 'max_sync_attempts',
      'value' => '10',
      'group' => 'sync',
      'label' => 'Max Sync Attempts',
      'description' => 'Max attempts before giving up on a queued record (client-side).',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 450,
      'secret' => 0,
    ],
    [
      'key' => 'sync_backoff_base_ms',
      'value' => '2000',
      'group' => 'sync',
      'label' => 'Sync Backoff Base (ms)',
      'description' => 'Base backoff used for exponential retry delay (client-side).',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 460,
      'secret' => 0,
    ],
    [
      'key' => 'sync_backoff_cap_ms',
      'value' => '300000',
      'group' => 'sync',
      'label' => 'Sync Backoff Cap (ms)',
      'description' => 'Maximum backoff delay cap (client-side).',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 470,
      'secret' => 0,
    ],

    // ---------------------------
    // Offline time handling
    // ---------------------------
    [
      'key' => 'offline_max_backdate_minutes',
      'value' => '2880',
      'group' => 'sync',
      'label' => 'Offline Max Backdate (minutes)',
      'description' => 'When syncing offline punches, accept device_time up to this many minutes in the past (will clamp beyond this).',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 465,
      'secret' => 0,
    ],
    [
      'key' => 'offline_max_future_seconds',
      'value' => '120',
      'group' => 'sync',
      'label' => 'Offline Max Future (seconds)',
      'description' => 'When syncing offline punches, allow device_time to be up to this many seconds in the future (will clamp beyond this).',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 466,
      'secret' => 0,
    ],
     [
      'key' => 'offline_time_mismatch_log_sec',
      'value' => '300',
      'group' => 'sync',
      'label' => 'Time Mismatch Log Threshold (sec)',
      'description' => 'Log a time_mismatch event when device time differs from server time by more than this many seconds.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 467,
      'secret' => 0,
    ],

    // ---------------------------
    // Diagnostics
    // ---------------------------
    [
      'key' => 'debug_mode',
      'value' => '0',
      'group' => 'debug',
      'label' => 'Debug Mode',
      'description' => 'If 1, some endpoints may return extra debug details.',
      'type' => 'bool',
      'editable_by' => 'superadmin',
      'sort' => 510,
      'secret' => 0,
    ],

    // ---------------------------


    // ---------------------------
    // Offline storage security
    // ---------------------------
    [
      'key' => 'offline_allow_unencrypted_pin',
      'value' => '1',
      'group' => 'sync',
      'label' => 'Allow Unencrypted Offline PIN',
      'description' => 'If 1, allow storing PIN in plaintext in the local offline queue when WebCrypto encryption is unavailable. Use only on trusted/locked-down devices.',
      'type' => 'bool',
      'editable_by' => 'superadmin',
      'sort' => 468,
      'secret' => 0,
    ],
    // UI Text (move hardcoded copy here)
    // ---------------------------
    [
      'key' => 'ui_text.kiosk_title',
      'value' => 'Clock Kiosk',
      'group' => 'ui_text',
      'label' => 'Kiosk Title',
      'description' => 'Main title text displayed on the kiosk screen.',
      'type' => 'string',
      'editable_by' => 'manager',
      'sort' => 610,
      'secret' => 0,
    ],
    [
      'key' => 'ui_text.kiosk_subtitle',
      'value' => 'Clock in / Clock out',
      'group' => 'ui_text',
      'label' => 'Kiosk Subtitle',
      'description' => 'Small subtitle displayed under the kiosk title.',
      'type' => 'string',
      'editable_by' => 'manager',
      'sort' => 620,
      'secret' => 0,
    ],
    [
      'key' => 'ui_text.employee_notice',
      'value' => 'Please clock in at the start of your shift and clock out when you finish.',
      'group' => 'ui_text',
      'label' => 'Employee Notice',
      'description' => 'Notice text shown to staff on the kiosk screen.',
      'type' => 'string',
      'editable_by' => 'manager',
      'sort' => 630,
      'secret' => 0,
    ],
    [
      'key' => 'ui_text.not_paired_message',
      'value' => 'This device is not paired. Please contact admin.',
      'group' => 'ui_text',
      'label' => 'Not Paired Message',
      'description' => 'Message shown when kiosk is not paired.',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 640,
      'secret' => 0,
    ],
    [
      'key' => 'ui_text.not_authorised_message',
      'value' => 'This device is not authorised.',
      'group' => 'ui_text',
      'label' => 'Not Authorised Message',
      'description' => 'Message shown when kiosk token is missing/invalid.',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 650,
      'secret' => 0,
    ],
  ];

  $sql = "
    INSERT INTO kiosk_settings
      (`key`, `value`, `group_name`, `label`, `description`, `type`, `editable_by`, `sort_order`, `is_secret`)
    VALUES
      (:k, :v, :g, :l, :d, :t, :e, :s, :sec)
    ON DUPLICATE KEY UPDATE
      `value`       = VALUES(`value`),
      `group_name`  = VALUES(`group_name`),
      `label`       = VALUES(`label`),
      `description` = VALUES(`description`),
      `type`        = VALUES(`type`),
      `editable_by` = VALUES(`editable_by`),
      `sort_order`  = VALUES(`sort_order`),
      `is_secret`   = VALUES(`is_secret`),
      `updated_at`  = UTC_TIMESTAMP
  ";

  $stmt = $pdo->prepare($sql);

  foreach ($defs as $d) {
    $stmt->execute([
      ':k'   => (string)$d['key'],
      ':v'   => (string)$d['value'],
      ':g'   => (string)$d['group'],
      ':l'   => (string)$d['label'],
      ':d'   => (string)$d['description'],
      ':t'   => (string)$d['type'],
      ':e'   => (string)$d['editable_by'],
      ':s'   => (int)$d['sort'],
      ':sec' => (int)$d['secret'],
    ]);
  }
}

function create_tables(PDO $pdo): void {

  // SETTINGS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_settings (
      `key` VARCHAR(150) PRIMARY KEY,
      `value` TEXT NOT NULL,
      `group_name` VARCHAR(50) NOT NULL DEFAULT 'general',
      `label` VARCHAR(150) NULL,
      `description` TEXT NULL,
      `type` VARCHAR(20) NOT NULL DEFAULT 'string',
      `editable_by` VARCHAR(20) NOT NULL DEFAULT 'superadmin',
      `sort_order` INT NOT NULL DEFAULT 0,
      `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_group (group_name),
      KEY idx_editable (editable_by),
      KEY idx_sort (sort_order)
    ) ENGINE=InnoDB;
  ");


  // DEVICES (heartbeat / last seen)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_devices (
      kiosk_code VARCHAR(50) PRIMARY KEY,
      device_token_hash CHAR(64) NULL,
      pairing_version INT NULL,
      last_seen_at DATETIME NOT NULL,
      last_seen_kind VARCHAR(20) NULL,
      last_authorised TINYINT(1) NOT NULL DEFAULT 0,
      last_error_code VARCHAR(50) NULL,
      last_ip VARCHAR(45) NULL,
      last_user_agent VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_seen (last_seen_at),
      KEY idx_auth (last_authorised)
    ) ENGINE=InnoDB;
  ");

  // EMPLOYEES (NEW: nickname)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employees (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_code VARCHAR(50) UNIQUE,
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      nickname VARCHAR(100) NULL,
      pin_hash VARCHAR(255),
      pin_updated_at DATETIME NULL,
      archived_at DATETIME NULL,
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_active (is_active)
    ) ENGINE=InnoDB;
  ");

  // SHIFTS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_shifts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_id INT UNSIGNED,
      clock_in_at DATETIME NOT NULL,
      clock_out_at DATETIME NULL,
      duration_minutes INT NULL,
      is_closed TINYINT(1) DEFAULT 0,
      close_reason VARCHAR(50) NULL,
      is_autoclosed TINYINT(1) NOT NULL DEFAULT 0,
      approved_at DATETIME NULL,
      approved_by VARCHAR(50) NULL,
      approval_note VARCHAR(255) NULL,
      last_modified_reason VARCHAR(50) NULL,
      created_source VARCHAR(20) NULL,
      updated_source VARCHAR(20) NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_open (employee_id, is_closed),
      KEY idx_clock_in (clock_in_at)
    ) ENGINE=InnoDB;
  ");

  // PUNCH EVENTS (kept employee_id NOT NULL as per your current approach)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_punch_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_uuid CHAR(36) UNIQUE,
      employee_id INT UNSIGNED,
      action ENUM('IN','OUT'),
      device_time DATETIME,
      received_at DATETIME,
      effective_time DATETIME,
      result_status VARCHAR(20),
      source VARCHAR(20) NULL,
      was_offline TINYINT(1) NOT NULL DEFAULT 0,
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
    'kiosk_devices',
    'kiosk_health_log',
    'kiosk_event_log',
    'kiosk_punch_events',
    'kiosk_shifts',
    'kiosk_employees',
    'kiosk_settings'
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
