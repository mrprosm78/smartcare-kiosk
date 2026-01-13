<?php
// https://zapsite.co.uk/kiosk-dev/setup.php?action=install
// https://zapsite.co.uk/kiosk-dev/setup.php?action=reset&pin=4321
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const RESET_PIN = '4321';

// db.php must define $pdo (PDO instance)
require __DIR__ . '/db.php';<?php
// https://zapsite.co.uk/kiosk-dev/setup.php?action=install
// https://zapsite.co.uk/kiosk-dev/setup.php?action=reset&pin=4321
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const RESET_PIN = '4321';

// db.php must define $pdo (PDO instance)
require __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('Database connection not available ($pdo missing)');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * PART A — Helpers (safe to keep at top)
 */
if (!function_exists('add_column_if_missing')) {
  function add_column_if_missing(PDO $pdo, string $table, string $column, string $ddl): void {
    $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $table, ':c' => $column]);
    $exists = (int)$st->fetchColumn() > 0;
    if ($exists) return;

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddl}");
  }
}

/**
 * PART B — Seeds
 */
function seed_employee_categories(PDO $pdo): void {
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_employee_categories")->fetchColumn();
    if ($count > 0) return;
  } catch (Throwable $e) {
    return;
  }

  $defaults = [
    ['Carer', 'carer', 10],
    ['Senior Carer', 'senior-carer', 20],
    ['Nurse', 'nurse', 30],
    ['Kitchen', 'kitchen', 40],
    ['Housekeeping', 'housekeeping', 50],
    ['Maintenance', 'maintenance', 60],
    ['Admin', 'admin', 70],
    ['Agency', 'agency', 80],
  ];

  $stmt = $pdo->prepare("INSERT INTO kiosk_employee_categories (name, slug, sort_order, is_active) VALUES (?,?,?,1)");
  foreach ($defaults as $d) {
    $stmt->execute([$d[0], $d[1], $d[2]]);
  }
}

function seed_settings(PDO $pdo): void {
  $defs = [
    // ===========================
    // Identity + Pairing
    // ===========================
    [
      'key' => 'kiosk_code', 'value' => 'KIOSK-1', 'group' => 'identity',
      'label' => 'Kiosk Code', 'description' => 'Short identifier for this kiosk (used in logs and API headers).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 10, 'secret' => 0,
    ],
    [
      'key' => 'is_paired', 'value' => '0', 'group' => 'pairing',
      'label' => 'Is Paired', 'description' => '0/1 flag used by UI to show paired status. Pairing sets this to 1; revoke clears it.',
      'type' => 'bool', 'editable_by' => 'none', 'sort' => 20, 'secret' => 0,
    ],
    [
      'key' => 'paired_device_token_hash', 'value' => '', 'group' => 'pairing',
      'label' => 'Paired Device Token Hash', 'description' => 'SHA-256 hash of the paired device token. Only the paired device can authorise requests.',
      'type' => 'secret', 'editable_by' => 'none', 'sort' => 30, 'secret' => 1,
    ],
    [
      'key' => 'paired_device_token', 'value' => '', 'group' => 'pairing',
      'label' => 'Legacy Paired Device Token', 'description' => 'Legacy token storage. Not used for auth; kept for backward compatibility.',
      'type' => 'secret', 'editable_by' => 'none', 'sort' => 31, 'secret' => 1,
    ],
    [
      'key' => 'pairing_version', 'value' => '1', 'group' => 'pairing',
      'label' => 'Pairing Version', 'description' => 'Bumps whenever pairing is revoked/reset; helps clients detect pairing changes.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 40, 'secret' => 0,
    ],
    [
      'key' => 'pairing_code', 'value' => '4321', 'group' => 'pairing',
      'label' => 'Pairing Passcode', 'description' => 'Passcode required to pair a device (only works if pairing_mode is enabled).',
      'type' => 'secret', 'editable_by' => 'superadmin', 'sort' => 50, 'secret' => 1,
    ],
    [
      'key' => 'pairing_mode', 'value' => '1', 'group' => 'pairing',
      'label' => 'Pairing Mode Enabled', 'description' => 'When 0, /api/kiosk/pair.php rejects pairing even if the passcode is known.',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 60, 'secret' => 0,
    ],
    [
      'key' => 'pairing_mode_until', 'value' => '', 'group' => 'pairing',
      'label' => 'Pairing Mode Until', 'description' => 'Optional UTC datetime. If set and expired, pairing is auto-disabled.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 70, 'secret' => 0,
    ],

    // ===========================
    // Admin Portal
    // ===========================
    [
      'key' => 'admin_ui_version', 'value' => '1', 'group' => 'admin',
      'label' => 'Admin UI Version', 'description' => 'Change this to force admin pages to reload assets (cache-busting).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 80, 'secret' => 0,
    ],
    [
      'key' => 'admin_pairing_version', 'value' => '1', 'group' => 'admin',
      'label' => 'Admin Pairing Version', 'description' => 'Bump this to revoke all admin trusted devices.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 90, 'secret' => 0,
    ],
    [
      'key' => 'admin_pairing_code', 'value' => '4321', 'group' => 'admin',
      'label' => 'Admin Pairing Passcode', 'description' => 'Passcode required to authorise a device for /admin (only works if admin_pairing_mode is enabled).',
      'type' => 'secret', 'editable_by' => 'superadmin', 'sort' => 100, 'secret' => 1,
    ],
    [
      'key' => 'admin_pairing_mode', 'value' => '0', 'group' => 'admin',
      'label' => 'Admin Pairing Mode Enabled', 'description' => 'When 0, /admin/pair.php rejects pairing even if the passcode is known.',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 110, 'secret' => 0,
    ],
    [
      'key' => 'admin_pairing_mode_until', 'value' => '', 'group' => 'admin',
      'label' => 'Admin Pairing Mode Until', 'description' => 'Optional UTC datetime. If set and expired, admin pairing is auto-disabled.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 120, 'secret' => 0,
    ],

    // ===========================
    // Rounding
    // ===========================
    [
      'key' => 'rounding_enabled', 'value' => '1', 'group' => 'rounding',
      'label' => 'Rounding Enabled', 'description' => 'If 1, admin/payroll views can calculate rounded times for payroll without changing originals.',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 10, 'secret' => 0,
    ],
    [
      'key' => 'round_increment_minutes', 'value' => '15', 'group' => 'rounding',
      'label' => 'Rounding Increment Minutes', 'description' => 'Snap time to this minute grid (e.g., 15 => 00,15,30,45).',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 20, 'secret' => 0,
    ],
    [
      'key' => 'round_grace_minutes', 'value' => '5', 'group' => 'rounding',
      'label' => 'Rounding Grace Minutes', 'description' => 'Only snap when within this many minutes of boundary.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 30, 'secret' => 0,
    ],

    // ===========================
    // Security
    // ===========================
    [
      'key' => 'pin_length', 'value' => '4', 'group' => 'security',
      'label' => 'PIN Length', 'description' => 'Number of digits required for staff PINs.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 110, 'secret' => 0,
    ],
    [
      'key' => 'allow_plain_pin', 'value' => '1', 'group' => 'security',
      'label' => 'Allow Plain PIN', 'description' => 'If 1, allows plain PIN entries in kiosk_employees (simple installs). Prefer hashed in production.',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 120, 'secret' => 0,
    ],
    [
      'key' => 'min_seconds_between_punches', 'value' => '5', 'group' => 'security',
      'label' => 'Min Seconds Between Punches', 'description' => 'Rate limit per employee to prevent double taps.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 130, 'secret' => 0,
    ],
    [
      'key' => 'auth_fail_window_sec', 'value' => '300', 'group' => 'security',
      'label' => 'Auth Fail Window (seconds)', 'description' => 'Time window to count failed PIN attempts.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 140, 'secret' => 0,
    ],
    [
      'key' => 'auth_fail_max', 'value' => '5', 'group' => 'security',
      'label' => 'Max Auth Failures', 'description' => 'Max failures within window before returning 429.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 150, 'secret' => 0,
    ],
    [
      'key' => 'pair_fail_window_sec', 'value' => '600', 'group' => 'security',
      'label' => 'Pair Fail Window (seconds)', 'description' => 'Time window to count failed pairing attempts.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 170, 'secret' => 0,
    ],
    [
      'key' => 'pair_fail_max', 'value' => '5', 'group' => 'security',
      'label' => 'Max Pair Failures', 'description' => 'Max failures within window before returning 429.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 180, 'secret' => 0,
    ],

    // ===========================
    // Limits
    // ===========================
    [
      'key' => 'max_shift_minutes', 'value' => '960', 'group' => 'limits',
      'label' => 'Max Shift Minutes', 'description' => 'Maximum shift length for validation/autoclose (future).',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 210, 'secret' => 0,
    ],

    // ===========================
    // UI Behaviour
    // ===========================
    [
      'key' => 'ui_version', 'value' => '1', 'group' => 'ui',
      'label' => 'UI Version', 'description' => 'Cache-busting version for CSS/JS.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 310, 'secret' => 0,
    ],
    [
      'key' => 'ui_thank_ms', 'value' => '3000', 'group' => 'ui',
      'label' => 'Thank You Screen (ms)', 'description' => 'How long to show success screen after a punch.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 320, 'secret' => 0,
    ],
    [
      'key' => 'ui_show_clock', 'value' => '1', 'group' => 'ui',
      'label' => 'Show Clock', 'description' => 'If 1, kiosk shows current time/date panel.',
      'type' => 'bool', 'editable_by' => 'manager', 'sort' => 325, 'secret' => 0,
    ],
    [
      'key' => 'ui_show_open_shifts', 'value' => '0', 'group' => 'ui',
      'label' => 'Show Open Shifts', 'description' => 'If 1, kiosk can display currently clocked-in staff.',
      'type' => 'bool', 'editable_by' => 'manager', 'sort' => 330, 'secret' => 0,
    ],
    [
      'key' => 'ui_open_shifts_count', 'value' => '6', 'group' => 'ui',
      'label' => 'Open Shifts Count', 'description' => 'How many open shifts to show.',
      'type' => 'int', 'editable_by' => 'manager', 'sort' => 340, 'secret' => 0,
    ],
    [
      'key' => 'ui_open_shifts_show_time', 'value' => '1', 'group' => 'ui',
      'label' => 'Open Shifts Show Time', 'description' => 'If 1, panel shows clock-in time and duration.',
      'type' => 'bool', 'editable_by' => 'manager', 'sort' => 345, 'secret' => 0,
    ],
    [
      'key' => 'ui_reload_enabled', 'value' => '0', 'group' => 'ui',
      'label' => 'UI Auto Reload Enabled', 'description' => 'If 1, kiosk checks ui_version changes and reloads.',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 350, 'secret' => 0,
    ],
    [
      'key' => 'ui_reload_check_ms', 'value' => '60000', 'group' => 'ui',
      'label' => 'UI Reload Check (ms)', 'description' => 'How often to check ui_version.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 360, 'secret' => 0,
    ],
    [
      'key' => 'ui_reload_token', 'value' => '0', 'group' => 'ui',
      'label' => 'UI Reload Token', 'description' => 'Change to force a reload even if ui_version unchanged.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 365, 'secret' => 0,
    ],

    // ===========================
    // Sync / Telemetry
    // ===========================
    [
      'key' => 'ping_interval_ms', 'value' => '60000', 'group' => 'health',
      'label' => 'Ping Interval (ms)', 'description' => 'How often client pings /api/kiosk/ping.php.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 410, 'secret' => 0,
    ],
    [
      'key' => 'device_offline_after_sec', 'value' => '300', 'group' => 'health',
      'label' => 'Device Offline After (sec)', 'description' => 'Mark kiosk offline if no ping seen within this time.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 415, 'secret' => 0,
    ],
    [
      'key' => 'sync_interval_ms', 'value' => '30000', 'group' => 'sync',
      'label' => 'Sync Interval (ms)', 'description' => 'How often client attempts background sync.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 420, 'secret' => 0,
    ],
    [
      'key' => 'sync_cooldown_ms', 'value' => '8000', 'group' => 'sync',
      'label' => 'Sync Cooldown (ms)', 'description' => 'Cooldown between sync attempts after an error.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 430, 'secret' => 0,
    ],
    [
      'key' => 'sync_batch_size', 'value' => '20', 'group' => 'sync',
      'label' => 'Sync Batch Size', 'description' => 'Max number of queued records per sync batch.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 440, 'secret' => 0,
    ],
    [
      'key' => 'offline_max_backdate_minutes', 'value' => '2880', 'group' => 'sync',
      'label' => 'Offline Max Backdate (minutes)', 'description' => 'Accept offline device_time this many minutes in past (clamp beyond).',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 465, 'secret' => 0,
    ],
    [
      'key' => 'offline_max_future_seconds', 'value' => '120', 'group' => 'sync',
      'label' => 'Offline Max Future (seconds)', 'description' => 'Allow device_time this many seconds in future (clamp beyond).',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 466, 'secret' => 0,
    ],
    [
      'key' => 'offline_time_mismatch_log_sec', 'value' => '300', 'group' => 'sync',
      'label' => 'Time Mismatch Threshold (sec)', 'description' => 'Log time_mismatch if device time differs by more than this.',
      'type' => 'int', 'editable_by' => 'superadmin', 'sort' => 467, 'secret' => 0,
    ],
    [
      'key' => 'offline_allow_unencrypted_pin', 'value' => '1', 'group' => 'sync',
      'label' => 'Allow Unencrypted Offline PIN', 'description' => 'If 1, allow plaintext PIN in offline queue if WebCrypto unavailable (trusted devices only).',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 468, 'secret' => 0,
    ],

    // ===========================
    // Debug
    // ===========================
    [
      'key' => 'debug_mode', 'value' => '0', 'group' => 'debug',
      'label' => 'Debug Mode', 'description' => 'If 1, endpoints may return extra debug details.',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 510, 'secret' => 0,
    ],

    // ===========================
    // UI Text
    // ===========================
    [
      'key' => 'ui_text.kiosk_title', 'value' => 'Clock Kiosk', 'group' => 'ui_text',
      'label' => 'Kiosk Title', 'description' => 'Main title displayed on the kiosk screen.',
      'type' => 'string', 'editable_by' => 'manager', 'sort' => 610, 'secret' => 0,
    ],
    [
      'key' => 'ui_text.kiosk_subtitle', 'value' => 'Clock in / Clock out', 'group' => 'ui_text',
      'label' => 'Kiosk Subtitle', 'description' => 'Subtitle displayed under the kiosk title.',
      'type' => 'string', 'editable_by' => 'manager', 'sort' => 620, 'secret' => 0,
    ],
    [
      'key' => 'ui_text.employee_notice', 'value' => 'Please clock in at the start of your shift and clock out when you finish.', 'group' => 'ui_text',
      'label' => 'Employee Notice', 'description' => 'Notice text shown to staff on kiosk screen.',
      'type' => 'string', 'editable_by' => 'manager', 'sort' => 630, 'secret' => 0,
    ],
    [
      'key' => 'ui_text.not_paired_message', 'value' => 'This device is not paired. Please contact admin.', 'group' => 'ui_text',
      'label' => 'Not Paired Message', 'description' => 'Message shown when kiosk is not paired.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 640, 'secret' => 0,
    ],
    [
      'key' => 'ui_text.not_authorised_message', 'value' => 'This device is not authorised.', 'group' => 'ui_text',
      'label' => 'Not Authorised Message', 'description' => 'Message shown when kiosk token missing/invalid.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 650, 'secret' => 0,
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

function seed_admin_users(PDO $pdo): void {
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
  } catch (Throwable $e) {
    return;
  }
  if ($count > 0) return;

  $username = 'superadmin';
  $display  = 'Super Admin';
  $role     = 'superadmin';
  $hash = password_hash('ChangeMe123!', PASSWORD_BCRYPT);

  $stmt = $pdo->prepare("
    INSERT INTO admin_users (username, display_name, role, password_hash, is_active, created_at, updated_at)
    VALUES (:u,:d,:r,:p,1,UTC_TIMESTAMP,UTC_TIMESTAMP)
  ");
  $stmt->execute([
    ':u' => $username,
    ':d' => $display,
    ':r' => $role,
    ':p' => $hash,
  ]);
}

/**
 * PART C — Tables
 */
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

  // DEVICES
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

  // EMPLOYEE CATEGORIES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_categories (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      slug VARCHAR(120) NOT NULL UNIQUE,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_active (is_active),
      KEY idx_sort (sort_order)
    ) ENGINE=InnoDB;
  ");

  // EMPLOYEES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employees (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_code VARCHAR(50) UNIQUE,
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      nickname VARCHAR(100) NULL,
      category_id INT UNSIGNED NULL,
      is_agency TINYINT(1) NOT NULL DEFAULT 0,
      agency_label VARCHAR(100) NULL,
      pin_hash VARCHAR(255),
      pin_updated_at DATETIME NULL,
      archived_at DATETIME NULL,
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_active (is_active),
      KEY idx_category (category_id),
      KEY idx_agency (is_agency)
    ) ENGINE=InnoDB;
  ");
  add_column_if_missing($pdo, 'kiosk_employees', 'category_id', 'INT UNSIGNED NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'is_agency', "TINYINT(1) NOT NULL DEFAULT 0");
  add_column_if_missing($pdo, 'kiosk_employees', 'agency_label', 'VARCHAR(100) NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'nickname', 'VARCHAR(100) NULL');

  // PAY PROFILES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_pay_profiles (
      employee_id INT UNSIGNED PRIMARY KEY,
      contract_hours_per_week DECIMAL(6,2) NULL,
      break_minutes_default INT NULL,
      break_is_paid TINYINT(1) NOT NULL DEFAULT 0,
      min_hours_for_break DECIMAL(5,2) NULL,
      holiday_entitled TINYINT(1) NOT NULL DEFAULT 0,
      bank_holiday_entitled TINYINT(1) NOT NULL DEFAULT 0,
      bank_holiday_multiplier DECIMAL(5,2) NULL,
      day_rate DECIMAL(10,2) NULL,
      night_rate DECIMAL(10,2) NULL,
      night_start TIME NULL,
      night_end TIME NULL,
      rules_json JSON NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
      payroll_locked_at DATETIME NULL,
      payroll_locked_by VARCHAR(100) NULL,
      payroll_batch_id VARCHAR(64) NULL,
      created_source VARCHAR(20) NULL,
      updated_source VARCHAR(20) NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_open (employee_id, is_closed),
      KEY idx_clock_in (clock_in_at),
      KEY idx_locked (payroll_locked_at)
    ) ENGINE=InnoDB;
  ");
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_locked_at', 'DATETIME NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_locked_by', 'VARCHAR(100) NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_batch_id', 'VARCHAR(64) NULL');

  // SHIFT CHANGES / AUDIT
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_shift_changes (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      shift_id BIGINT UNSIGNED NOT NULL,
      change_type ENUM('edit','approve','unapprove','payroll_lock','payroll_unlock') NOT NULL,
      changed_by_user_id BIGINT UNSIGNED NULL,
      changed_by_username VARCHAR(100) NULL,
      changed_by_role VARCHAR(30) NULL,
      reason VARCHAR(100) NULL,
      note VARCHAR(255) NULL,
      old_json JSON NULL,
      new_json JSON NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_shift_time (shift_id, created_at),
      KEY idx_type_time (change_type, created_at)
    ) ENGINE=InnoDB;
  ");
  try {
    $pdo->exec("
      ALTER TABLE kiosk_shift_changes
      MODIFY change_type ENUM('edit','approve','unapprove','payroll_lock','payroll_unlock') NOT NULL
    ");
  } catch (Throwable $e) { /* ignore */ }

  // PUNCH EVENTS
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

  // EVENT LOG
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

  // HEALTH LOG
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

  // ADMIN USERS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(100) NOT NULL UNIQUE,
      display_name VARCHAR(150) NULL,
      role ENUM('manager','payroll','admin','superadmin') NOT NULL DEFAULT 'manager',
      password_hash VARCHAR(255) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      last_login_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_role (role),
      KEY idx_active (is_active)
    ) ENGINE=InnoDB;
  ");
  try {
    $pdo->exec("
      ALTER TABLE admin_users
      MODIFY role ENUM('manager','payroll','admin','superadmin') NOT NULL DEFAULT 'manager'
    ");
  } catch (Throwable $e) { /* ignore */ }

  // ADMIN DEVICES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_devices (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      token_hash CHAR(64) NOT NULL UNIQUE,
      label VARCHAR(120) NULL,
      pairing_version INT NOT NULL DEFAULT 1,
      first_paired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NULL,
      last_ip VARCHAR(45) NULL,
      last_user_agent VARCHAR(255) NULL,
      revoked_at DATETIME NULL,
      revoked_by BIGINT UNSIGNED NULL,
      revoke_reason VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_revoked (revoked_at),
      KEY idx_seen (last_seen_at),
      KEY idx_pairver (pairing_version)
    ) ENGINE=InnoDB;
  ");

  // ADMIN SESSIONS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_sessions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      session_id VARCHAR(128) NOT NULL UNIQUE,
      user_id BIGINT UNSIGNED NOT NULL,
      device_id BIGINT UNSIGNED NULL,
      ip_address VARCHAR(45) NULL,
      user_agent VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NULL,
      revoked_at DATETIME NULL,
      revoked_by BIGINT UNSIGNED NULL,
      revoke_reason VARCHAR(120) NULL,
      KEY idx_user (user_id),
      KEY idx_revoked (revoked_at),
      KEY idx_seen (last_seen_at)
    ) ENGINE=InnoDB;
  ");
}

/**
 * PART D — Reset
 */
function drop_all(PDO $pdo): void {
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
  foreach ([
    'admin_sessions',
    'admin_devices',
    'admin_users',
    'kiosk_devices',
    'kiosk_health_log',
    'kiosk_event_log',
    'kiosk_punch_events',
    'kiosk_shift_changes',
    'kiosk_shifts',
    'kiosk_employee_pay_profiles',
    'kiosk_employee_categories',
    'kiosk_employees',
    'kiosk_settings',
  ] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS `$t`");
  }
  $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

/**
 * PART E — Controller (actions)
 */
$action = (string)($_GET['action'] ?? '');

try {
  if ($action === 'install') {
    create_tables($pdo);
    seed_settings($pdo);
    seed_employee_categories($pdo);
    seed_admin_users($pdo);
    exit("✅ Install / repair completed");
  }

  if ($action === 'reset') {
    if ((string)($_GET['pin'] ?? '') !== RESET_PIN) {
      http_response_code(403);
      exit("❌ Invalid reset PIN");
    }
    drop_all($pdo);
    create_tables($pdo);
    seed_settings($pdo);
    seed_employee_categories($pdo);
    seed_admin_users($pdo);
    exit("🔥 Database reset completed");
  }

  echo '<h3>SmartCare Kiosk – Setup</h3>
  <ul>
    <li><a href="?action=install">Install / Repair</a></li>
    <li><a href="?action=reset&pin=4321" onclick="return confirm(\'RESET DATABASE?\')">Reset (PIN required)</a></li>
  </ul>';

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>ERROR:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
}


if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('Database connection not available ($pdo missing)');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Add a column if it doesn't already exist (safe for re-running install).
 */
if (!function_exists('add_column_if_missing')) {
  function add_column_if_missing(PDO $pdo, string $table, string $column, string $ddl): void {
    $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $table, ':c' => $column]);
    $exists = (int)$st->fetchColumn() > 0;
    if ($exists) return;

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddl}");
  }
}


/**
 * Seed kiosk_settings with values + metadata
 */
function seed_settings(PDO $pdo): void {
  $defs = [
    // Identity + Pairing
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
    // legacy (kept for older installs)
    [
      'key' => 'paired_device_token',
      'value' => '',
      'group' => 'pairing',
      'label' => 'Legacy Paired Device Token',
      'description' => 'Legacy token storage. Not used for auth; kept for backward compatibility.',
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
      'value' => '4321',
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
      'value' => '1',
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

    // Admin Portal (Back Office)
    [
      'key' => 'admin_ui_version',
      'value' => '1',
      'group' => 'admin',
      'label' => 'Admin UI Version',
      'description' => 'Change this to force admin pages to reload assets (cache-busting).',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 80,
      'secret' => 0,
    ],
    [
      'key' => 'admin_pairing_version',
      'value' => '1',
      'group' => 'admin',
      'label' => 'Admin Pairing Version',
      'description' => 'Bump this to revoke all admin trusted devices.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 90,
      'secret' => 0,
    ],
    [
      'key' => 'admin_pairing_code',
      'value' => '4321',
      'group' => 'admin',
      'label' => 'Admin Pairing Passcode',
      'description' => 'Passcode required to authorise a device for /admin (only works if admin_pairing_mode is enabled).',
      'type' => 'secret',
      'editable_by' => 'superadmin',
      'sort' => 100,
      'secret' => 1,
    ],
    [
      'key' => 'admin_pairing_mode',
      'value' => '0',
      'group' => 'admin',
      'label' => 'Admin Pairing Mode Enabled',
      'description' => 'When 0, /admin/pair.php rejects pairing even if the passcode is known.',
      'type' => 'bool',
      'editable_by' => 'superadmin',
      'sort' => 110,
      'secret' => 0,
    ],
    [
      'key' => 'admin_pairing_mode_until',
      'value' => '',
      'group' => 'admin',
      'label' => 'Admin Pairing Mode Until',
      'description' => 'Optional UTC datetime. If set and expired, admin pairing is auto-disabled.',
      'type' => 'string',
      'editable_by' => 'superadmin',
      'sort' => 120,
      'secret' => 0,
    ],

    // Rounding (Payroll)
    [
      'key' => 'rounding_enabled',
      'value' => '1',
      'group' => 'rounding',
      'label' => 'Rounding Enabled',
      'description' => 'If 1, admin/payroll views can calculate rounded clock in/out times for payroll without changing originals.',
      'type' => 'bool',
      'editable_by' => 'superadmin',
      'sort' => 10,
      'secret' => 0,
    ],
    [
      'key' => 'round_increment_minutes',
      'value' => '15',
      'group' => 'rounding',
      'label' => 'Rounding Increment Minutes',
      'description' => 'Snap time to this minute grid (e.g., 15 => 00,15,30,45). Used only for payroll calculations.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 20,
      'secret' => 0,
    ],
    [
      'key' => 'round_grace_minutes',
      'value' => '5',
      'group' => 'rounding',
      'label' => 'Rounding Grace Minutes',
      'description' => 'Only snap when within this many minutes of boundary.',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 30,
      'secret' => 0,
    ],

    // PIN + Security
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
      'value' => '5',
      'group' => 'security',
      'label' => 'Min Seconds Between Punches',
      'description' => 'Rate limit per employee to prevent double-taps.',
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
    // Limits
    [
      'key' => 'max_shift_minutes',
      'value' => '960',
      'group' => 'limits',
      'label' => 'Max Shift Minutes',
      'description' => 'Maximum shift length for validation/autoclose (future).',
      'type' => 'int',
      'editable_by' => 'superadmin',
      'sort' => 210,
      'secret' => 0,
    ],

    // UI Behaviour
    [
      'key' => 'ui_version',
      'value' => '1',
      'group' => 'ui',
      'label' => 'UI Version',
      'description' => 'Cache-busting version for CSS/JS.',
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
      'value' => '0',
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
    // UI Text
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
      'description' => 'Subtitle displayed under the kiosk title.',
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

/**
 * Seed a default superadmin account (only if none exist)
 */
function seed_admin_users(PDO $pdo): void {
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
  } catch (Throwable $e) {
    return;
  }
  if ($count > 0) return;

  $username = 'superadmin';
  $display  = 'Super Admin';
  $role     = 'superadmin';
  $hash = password_hash('ChangeMe123!', PASSWORD_BCRYPT);

  $stmt = $pdo->prepare("
    INSERT INTO admin_users (username, display_name, role, password_hash, is_active, created_at, updated_at)
    VALUES (:u,:d,:r,:p,1,UTC_TIMESTAMP,UTC_TIMESTAMP)
  ");
  $stmt->execute([
    ':u' => $username,
    ':d' => $display,
    ':r' => $role,
    ':p' => $hash,
  ]);
}

/**
 * Create or repair tables
 */
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

  // DEVICES
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

  // EMPLOYEES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employees (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_code VARCHAR(50) UNIQUE,
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      nickname VARCHAR(100) NULL,
      category_id INT UNSIGNED NULL,
      is_agency TINYINT(1) NOT NULL DEFAULT 0,
      agency_label VARCHAR(100) NULL,
      pin_hash VARCHAR(255),
      pin_updated_at DATETIME NULL,
      archived_at DATETIME NULL,
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_active (is_active),
      KEY idx_category (category_id),
      KEY idx_agency (is_agency)
    ) ENGINE=InnoDB;
  ");

  // Ensure newer employee columns exist (repair older installs)
  add_column_if_missing($pdo, 'kiosk_employees', 'category_id', 'INT UNSIGNED NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'is_agency', "TINYINT(1) NOT NULL DEFAULT 0");
  add_column_if_missing($pdo, 'kiosk_employees', 'agency_label', 'VARCHAR(100) NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'nickname', 'VARCHAR(100) NULL');

  // EMPLOYEE CATEGORIES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_categories (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      slug VARCHAR(120) NOT NULL UNIQUE,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_active (is_active),
      KEY idx_sort (sort_order)
    ) ENGINE=InnoDB;
  ");

  // EMPLOYEE PAY PROFILE
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_pay_profiles (
      employee_id INT UNSIGNED PRIMARY KEY,
      contract_hours_per_week DECIMAL(6,2) NULL,
      break_minutes_default INT NULL,
      break_is_paid TINYINT(1) NOT NULL DEFAULT 0,
      min_hours_for_break DECIMAL(5,2) NULL,
      holiday_entitled TINYINT(1) NOT NULL DEFAULT 0,
      bank_holiday_entitled TINYINT(1) NOT NULL DEFAULT 0,
      bank_holiday_multiplier DECIMAL(5,2) NULL,
      day_rate DECIMAL(10,2) NULL,
      night_rate DECIMAL(10,2) NULL,
      night_start TIME NULL,
      night_end TIME NULL,
      rules_json JSON NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

  // SHIFT CHANGES / AUDIT
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_shift_changes (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      shift_id BIGINT UNSIGNED NOT NULL,
      change_type ENUM('edit','approve','unapprove','payroll_lock','payroll_unlock') NOT NULL,
      changed_by_user_id BIGINT UNSIGNED NULL,
      changed_by_username VARCHAR(100) NULL,
      changed_by_role VARCHAR(30) NULL,
      reason VARCHAR(100) NULL,
      note VARCHAR(255) NULL,
      old_json JSON NULL,
      new_json JSON NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_shift_time (shift_id, created_at),
      KEY idx_type_time (change_type, created_at)
    ) ENGINE=InnoDB;
  ");

  // Repair: ensure enum includes payroll events even on old installs
  try {
    $pdo->exec("
      ALTER TABLE kiosk_shift_changes
      MODIFY change_type ENUM('edit','approve','unapprove','payroll_lock','payroll_unlock') NOT NULL
    ");
  } catch (Throwable $e) { /* ignore */ }

  // Payroll lock columns on shifts (repair-safe)
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_locked_at', 'DATETIME NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_locked_by', 'VARCHAR(100) NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_batch_id',  'VARCHAR(64) NULL');

  // PUNCH EVENTS
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

  // EVENT LOG
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

  // HEALTH LOG
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

  // ADMIN USERS (manager / payroll / admin / superadmin)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(100) NOT NULL UNIQUE,
      display_name VARCHAR(150) NULL,
      role ENUM('manager','payroll','admin','superadmin') NOT NULL DEFAULT 'manager',
      password_hash VARCHAR(255) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      last_login_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_role (role),
      KEY idx_active (is_active)
    ) ENGINE=InnoDB;
  ");

  // Repair: ensure payroll is present in role enum on old installs
  try {
    $pdo->exec("
      ALTER TABLE admin_users
      MODIFY role ENUM('manager','payroll','admin','superadmin') NOT NULL DEFAULT 'manager'
    ");
  } catch (Throwable $e) { /* ignore */ }

  // ADMIN DEVICES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_devices (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      token_hash CHAR(64) NOT NULL UNIQUE,
      label VARCHAR(120) NULL,
      pairing_version INT NOT NULL DEFAULT 1,
      first_paired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NULL,
      last_ip VARCHAR(45) NULL,
      last_user_agent VARCHAR(255) NULL,
      revoked_at DATETIME NULL,
      revoked_by BIGINT UNSIGNED NULL,
      revoke_reason VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_revoked (revoked_at),
      KEY idx_seen (last_seen_at),
      KEY idx_pairver (pairing_version)
    ) ENGINE=InnoDB;
  ");

  // ADMIN SESSIONS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_sessions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      session_id VARCHAR(128) NOT NULL UNIQUE,
      user_id BIGINT UNSIGNED NOT NULL,
      device_id BIGINT UNSIGNED NULL,
      ip_address VARCHAR(45) NULL,
      user_agent VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME NULL,
      revoked_at DATETIME NULL,
      revoked_by BIGINT UNSIGNED NULL,
      revoke_reason VARCHAR(120) NULL,
      KEY idx_user (user_id),
      KEY idx_revoked (revoked_at),
      KEY idx_seen (last_seen_at)
    ) ENGINE=InnoDB;
  ");
}

/**
 * Drop all tables (reset)
 */
function drop_all(PDO $pdo): void {
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
  foreach ([
    'admin_sessions',
    'admin_devices',
    'admin_users',
    'kiosk_devices',
    'kiosk_health_log',
    'kiosk_event_log',
    'kiosk_punch_events',
    'kiosk_shift_changes',
    'kiosk_shifts',
    'kiosk_employee_pay_profiles',
    'kiosk_employee_categories',
    'kiosk_employees',
    'kiosk_settings',
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
    seed_employee_categories($pdo);
    seed_admin_users($pdo);
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
    seed_employee_categories($pdo);
    seed_admin_users($pdo);
    exit("🔥 Database reset completed");
  }

  echo '<h3>SmartCare Kiosk – Setup</h3>
  <ul>
    <li><a href="?action=install">Install / Repair</a></li>
    <li><a href="?action=reset&pin=4321" onclick="return confirm(\'RESET DATABASE?\')">Reset (PIN required)</a></li>
  </ul>';

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>ERROR:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
}
function seed_employee_categories(PDO $pdo): void {
  // Seed a few default categories if table is empty
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_employee_categories")->fetchColumn();
    if ($count > 0) return;
  } catch (Throwable $e) {
    return;
  }

  $defaults = [
    ['Carer', 'carer', 10],
    ['Senior Carer', 'senior-carer', 20],
    ['Nurse', 'nurse', 30],
    ['Kitchen', 'kitchen', 40],
    ['Housekeeping', 'housekeeping', 50],
    ['Maintenance', 'maintenance', 60],
    ['Admin', 'admin', 70],
    ['Agency', 'agency', 80],
  ];

  $stmt = $pdo->prepare("INSERT INTO kiosk_employee_categories (name, slug, sort_order, is_active) VALUES (?,?,?,1)");
  foreach ($defaults as $d) {
    $stmt->execute([$d[0], $d[1], $d[2]]);
  }
}