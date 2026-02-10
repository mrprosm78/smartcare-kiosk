<?php
// https://zapsite.co.uk/kiosk-dev/setup.php?action=install
// https://zapsite.co.uk/kiosk-dev/setup.php?action=reset&pin=2468
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const RESET_PIN = '2468';

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

if (!function_exists('index_exists')) {
  function index_exists(PDO $pdo, string $table, string $indexName): bool {
    $st = $pdo->prepare("
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :t
        AND INDEX_NAME = :i
    ");
    $st->execute([':t' => $table, ':i' => $indexName]);
    return (int)$st->fetchColumn() > 0;
  }
}

if (!function_exists('drop_column_if_exists')) {
  function drop_column_if_exists(PDO $pdo, string $table, string $column): void {
    $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $table, ':c' => $column]);
    $exists = (int)$st->fetchColumn() > 0;
    if (!$exists) return;
    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
  }
}

if (!function_exists('delete_setting_if_exists')) {
  function delete_setting_if_exists(PDO $pdo, string $key): void {
    try {
      $st = $pdo->prepare('DELETE FROM kiosk_settings WHERE `key` = :k');
      $st->execute([':k' => $key]);
    } catch (Throwable $e) {
      // ignore
    }
  }
}


/**
 * PART B — Seeds
 */
function seed_employee_departments(PDO $pdo): void {
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_employee_departments")->fetchColumn();
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
    ['Activities', 'activities', 90],
  ];

  $stmt = $pdo->prepare("INSERT INTO kiosk_employee_departments (name, slug, sort_order, is_active) VALUES (?,?,?,1)");
  foreach ($defaults as $d) {
    $stmt->execute([$d[0], $d[1], $d[2]]);
  }
}

function seed_break_tiers(PDO $pdo): void {
  // Seed only if empty
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_break_tiers")->fetchColumn();
    if ($count > 0) return;
  } catch (Throwable $e) {
    return;
  }

  // Default tiers (can be edited in admin).
  // Rule meaning:
  // - pick the tier with the highest min_worked_minutes <= worked_minutes.
  // - if no tiers match, treat break as 0.
  $tiers = [
    [0, 0, 10],
    [181, 30, 20],
    [361, 45, 30],
  ];

  $st = $pdo->prepare("INSERT INTO kiosk_break_tiers (min_worked_minutes, break_minutes, sort_order, is_enabled) VALUES (?,?,?,1)");
  foreach ($tiers as $t) {
    $st->execute([$t[0], $t[1], $t[2]]);
  }
}

function seed_settings(PDO $pdo): void {
  $defs = [
    // (unchanged — your full settings list remains as-is)
    // ...
  ];

  // Cleanup: remove legacy/global payroll-rule settings.
  $legacyKeysToRemove = [
    'default_break_minutes',
    'default_break_is_paid',
    'night_shift_threshold_percent',
    'night_premium_enabled',
    'night_premium_start',
    'night_premium_end',
    'overtime_default_multiplier',
    'weekend_premium_enabled',
    'weekend_days',
    'weekend_rate_multiplier',
    'bank_holiday_enabled',
    'bank_holiday_paid',
    'bank_holiday_paid_cap_hours',
    'bank_holiday_rate_multiplier',
    'payroll_overtime_priority',
    'payroll_overtime_threshold_hours',
    'payroll_stacking_mode',
    'payroll_night_start',
    'payroll_night_end',
    'payroll_bank_holiday_cap_hours',
    'payroll_callout_min_paid_hours',
    'default_night_multiplier',
    'default_night_premium_per_hour',
    'default_weekend_multiplier',
    'default_weekend_premium_per_hour',
    'default_bank_holiday_multiplier',
    'default_bank_holiday_premium_per_hour',
    'default_overtime_multiplier',
    'default_overtime_premium_per_hour',
    'default_callout_multiplier',
    'default_callout_premium_per_hour',
  ];

  try {
    if (!empty($legacyKeysToRemove)) {
      $in = implode(',', array_fill(0, count($legacyKeysToRemove), '?'));
      $del = $pdo->prepare("DELETE FROM kiosk_settings WHERE `key` IN ($in)");
      $del->execute(array_values($legacyKeysToRemove));
    }
  } catch (Throwable $e) {
    // ignore cleanup errors
  }

  $sql = "
    INSERT INTO kiosk_settings
      (`key`, `value`, `group_name`, `label`, `description`, `type`, `editable_by`, `sort_order`, `is_secret`)
    VALUES
      (:k, :v, :g, :l, :d, :t, :e, :s, :sec)
    ON DUPLICATE KEY UPDATE
      `value`       = `value`,
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

  // Lock setup-only settings after app initialization
  try {
    $appInit = (string)($pdo->query("SELECT value FROM kiosk_settings WHERE `key`='app_initialized' LIMIT 1")->fetchColumn() ?? '0');
    if ($appInit === '1') {
      $pdo->prepare("UPDATE kiosk_settings SET editable_by='none' WHERE `key`='payroll_week_starts_on'")->execute();
    }
  } catch (Throwable $e) { /* ignore */ }
}

function seed_admin_users(PDO $pdo): void {
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
  } catch (Throwable $e) {
    return;
  }
  if ($count > 0) return;

  $users = [
    [
      'username' => 'superadmin1',
      'display_name' => 'Super Admin 1',
      'role' => 'superadmin',
      'password' => 'Stowpark@7842'
    ],
    [
      'username' => 'superadmin2',
      'display_name' => 'Super Admin 2',
      'role' => 'superadmin',
      'password' => 'Stowpark@5169'
    ],
    [
      'username' => 'acd',
      'display_name' => 'ACD Admin',
      'role' => 'superadmin',
      'password' => 'Stowpark@2578'
    ],
    [
      'username' => 'manager1',
      'display_name' => 'Manager 1',
      'role' => 'manager',
      'password' => 'Stowpark@2468'
    ],
    [
      'username' => 'manager2',
      'display_name' => 'Manager 2',
      'role' => 'manager',
      'password' => 'Stowpark@2468'
    ],
    [
      'username' => 'payroll1',
      'display_name' => 'Payroll Admin',
      'role' => 'payroll',
      'password' => 'Stowpark@2053'
    ]
  ];

  $stmt = $pdo->prepare("
    INSERT INTO admin_users (username, display_name, role, password_hash, is_active, created_at, updated_at)
    VALUES (:u, :d, :r, :p, 1, UTC_TIMESTAMP, UTC_TIMESTAMP)
  ");

  foreach ($users as $user) {
    $hash = password_hash($user['password'], PASSWORD_BCRYPT);
    $stmt->execute([
      ':u' => $user['username'],
      ':d' => $user['display_name'],
      ':r' => $user['role'],
      ':p' => $hash,
    ]);
  }
}

/**
 * PART C — Schema (tables)
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

  // EMPLOYEE departments
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_departments (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      staff_code VARCHAR(20) NULL,
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

  // EMPLOYEE TEAMS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_teams (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      staff_code VARCHAR(20) NULL,
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

  // HR APPLICATIONS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS hr_applications (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_token VARCHAR(64) NOT NULL,
      status ENUM('draft','submitted','reviewing','rejected','hired','archived') NOT NULL DEFAULT 'draft',
      job_slug VARCHAR(120) NOT NULL DEFAULT '',
      applicant_name VARCHAR(160) NOT NULL DEFAULT '',
      email VARCHAR(190) NOT NULL DEFAULT '',
      phone VARCHAR(80) NOT NULL DEFAULT '',
      payload_json LONGTEXT NOT NULL,
      review_notes LONGTEXT NULL,
      reviewed_by_admin_id INT UNSIGNED NULL,
      reviewed_at DATETIME NULL,
      submitted_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_hr_app_public_token (public_token),
      KEY idx_hr_status (status),
      KEY idx_hr_job (job_slug),
      KEY idx_hr_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // LOCKED LINK: hr_applications.hr_staff_id
  add_column_if_missing($pdo, 'hr_applications', 'hr_staff_id', 'INT UNSIGNED NULL');
  try { $pdo->exec("ALTER TABLE hr_applications ADD KEY idx_hr_staff (hr_staff_id)"); } catch (Throwable $e) { /* ignore */ }

  // HR STAFF
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS hr_staff (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      staff_code VARCHAR(20) NULL,
      first_name VARCHAR(100) NOT NULL DEFAULT '',
      last_name VARCHAR(100) NOT NULL DEFAULT '',
      nickname VARCHAR(100) NULL,
      email VARCHAR(190) NULL,
      phone VARCHAR(80) NULL,
      department_id INT UNSIGNED NULL,
      team_id INT UNSIGNED NULL,
      status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
      photo_path VARCHAR(255) NULL,
      profile_json LONGTEXT NULL,
      created_by_admin_id INT UNSIGNED NULL,
      updated_by_admin_id INT UNSIGNED NULL,
      archived_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_hr_staff_dept (department_id),
      KEY idx_hr_staff_status (status),
      KEY idx_hr_staff_updated (updated_at),
      UNIQUE KEY uq_hr_staff_code (staff_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  /**
   * ✅ CRITICAL GUARDS (prevents "Unknown column s.staff_code" forever)
   * Old DBs may have hr_staff without staff_code / department_id.
   */
  add_column_if_missing($pdo, 'hr_staff', 'staff_code', 'VARCHAR(20) NULL');
  add_column_if_missing($pdo, 'hr_staff', 'department_id', 'INT UNSIGNED NULL');

  // Ensure unique index exists (ignore if already exists)
  try {
    if (!index_exists($pdo, 'hr_staff', 'uq_hr_staff_code')) {
      $pdo->exec("ALTER TABLE hr_staff ADD UNIQUE KEY uq_hr_staff_code (staff_code)");
    }
  } catch (Throwable $e) { /* ignore */ }

  // Backfill staff_code (LOCKED): SC prefix + 4-digit ID.
  // Format: SC0001 (SC + LPAD(id,4,'0')).
  // Upgrade older DBs that previously stored plain numeric IDs.
  try {
    $pdo->exec("UPDATE hr_staff SET staff_code = CONCAT('SC', LPAD(id, 4, '0')) WHERE staff_code IS NULL OR staff_code = '' OR staff_code REGEXP '^[0-9]+$'");
  } catch (Throwable $e) { /* ignore */ }

  // HR STAFF CONTRACTS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS hr_staff_contracts (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      staff_code VARCHAR(20) NULL,
      staff_id INT UNSIGNED NOT NULL,
      effective_from DATE NOT NULL,
      effective_to DATE NULL,
      contract_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_hr_staff_contracts_staff (staff_id),
      KEY idx_hr_staff_contracts_effective (effective_from, effective_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // HR STAFF DOCUMENTS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS hr_staff_documents (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      staff_id INT UNSIGNED NOT NULL,
      doc_type VARCHAR(50) NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      stored_path VARCHAR(255) NOT NULL,
      mime_type VARCHAR(100) NOT NULL,
      file_size INT UNSIGNED NOT NULL DEFAULT 0,
      note VARCHAR(255) NULL,
      uploaded_by_admin_id INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_hr_staff_docs_staff (staff_id),
      KEY idx_hr_staff_docs_type (doc_type),
      KEY idx_hr_staff_docs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // Track conversion (safe additive columns)
  add_column_if_missing($pdo, 'hr_applications', 'hired_employee_id', 'INT UNSIGNED NULL');
  add_column_if_missing($pdo, 'hr_applications', 'hired_at', 'DATETIME NULL');
  add_column_if_missing($pdo, 'hr_applications', 'hired_by_admin_id', 'INT UNSIGNED NULL');

  // KIOSK EMPLOYEES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employees (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      staff_code VARCHAR(20) NULL,
      employee_code VARCHAR(50) UNIQUE,
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      nickname VARCHAR(100) NULL,
      department_id INT UNSIGNED NULL,
      team_id INT UNSIGNED NULL,
      is_agency TINYINT(1) NOT NULL DEFAULT 0,
      agency_label VARCHAR(100) NULL,
      pin_hash VARCHAR(255),
      pin_fingerprint CHAR(64) NULL,
      pin_updated_at DATETIME NULL,
      archived_at DATETIME NULL,
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_active (is_active),
      KEY idx_department (department_id),
      KEY idx_team (team_id),
      KEY idx_pin_fp (pin_fingerprint),
      KEY idx_agency (is_agency)
    ) ENGINE=InnoDB;
  ");

  add_column_if_missing($pdo, 'kiosk_employees', 'department_id', 'INT UNSIGNED NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'team_id', 'INT UNSIGNED NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'pin_fingerprint', 'CHAR(64) NULL');

  // LOCKED LINK: Kiosk identity → HR staff
  add_column_if_missing($pdo, 'kiosk_employees', 'hr_staff_id', 'BIGINT UNSIGNED NULL');
  try { $pdo->exec("ALTER TABLE kiosk_employees ADD KEY idx_hr_staff (hr_staff_id)"); } catch (Throwable $e) { /* ignore */ }

  // pin_fingerprint must NOT be unique
  try { $pdo->exec("ALTER TABLE kiosk_employees DROP INDEX uq_pin_fp"); } catch (Throwable $e) { /* ignore */ }
  try { $pdo->exec("ALTER TABLE kiosk_employees ADD INDEX idx_pin_fp (pin_fingerprint)"); } catch (Throwable $e) { /* ignore */ }

  add_column_if_missing($pdo, 'kiosk_employees', 'is_agency', "TINYINT(1) NOT NULL DEFAULT 0");
  add_column_if_missing($pdo, 'kiosk_employees', 'agency_label', 'VARCHAR(100) NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'nickname', 'VARCHAR(100) NULL');

  // PAY PROFILES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_pay_profiles (
      employee_id INT UNSIGNED PRIMARY KEY,
      contract_hours_per_week DECIMAL(6,2) NULL,
      hourly_rate DECIMAL(8,2) NULL,
      break_is_paid TINYINT(1) NOT NULL DEFAULT 0,
      rules_json JSON NULL,
      inherit_from_carehome TINYINT(1) NOT NULL DEFAULT 1,
      overtime_threshold_hours DECIMAL(6,2) NULL,
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
      training_minutes INT NULL,
      training_note VARCHAR(255) NULL,
      is_callout TINYINT(1) NOT NULL DEFAULT 0,
      duration_minutes INT NULL,
      break_minutes INT NULL,
      paid_minutes INT NULL,
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

  // PUNCH PHOTOS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_punch_photos (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_uuid CHAR(36) NOT NULL UNIQUE,
      action ENUM('IN','OUT') NOT NULL,
      device_id VARCHAR(100) NULL,
      device_name VARCHAR(150) NULL,
      photo_path VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_created_at (created_at),
      KEY idx_action_created (action, created_at)
    ) ENGINE=InnoDB;
  ");

  // Fix typo in engine if it happened (safe)
  try { $pdo->exec("ALTER TABLE kiosk_punch_photos ENGINE=InnoDB"); } catch (Throwable $e) { /* ignore */ }

  // PUNCH PROCESSING STEPS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_punch_processing_steps (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_uuid CHAR(36) NOT NULL,
      step VARCHAR(30) NOT NULL,
      status VARCHAR(20) NOT NULL,
      code VARCHAR(50) NULL,
      message VARCHAR(255) NULL,
      meta_json TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_event (event_uuid),
      KEY idx_step (step),
      KEY idx_created (created_at)
    ) ENGINE=InnoDB;
  ");

  // Add status columns to punch photos (safe)
  add_column_if_missing($pdo, 'kiosk_punch_photos', 'photo_status', "VARCHAR(20) NULL");
  add_column_if_missing($pdo, 'kiosk_punch_photos', 'photo_error_code', "VARCHAR(50) NULL");
  add_column_if_missing($pdo, 'kiosk_punch_photos', 'uploaded_at', "DATETIME NULL");

  // BREAK TIERS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_break_tiers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      min_worked_minutes INT NOT NULL,
      break_minutes INT NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      is_enabled TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_enabled_sort (is_enabled, sort_order),
      KEY idx_min_worked (min_worked_minutes)
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

  // PAYROLL BANK HOLIDAYS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS payroll_bank_holidays (
      holiday_date DATE PRIMARY KEY,
      name VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  // PAYROLL BATCHES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS payroll_batches (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      period_start DATE NOT NULL,
      period_end DATE NOT NULL,
      run_by BIGINT UNSIGNED NULL,
      run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      status ENUM('FINAL','VOID') NOT NULL DEFAULT 'FINAL',
      notes VARCHAR(255) NULL,
      snapshot_json JSON NULL,
      KEY idx_period (period_start, period_end),
      KEY idx_run_at (run_at)
    ) ENGINE=InnoDB;
  ");

  // PAYROLL SHIFT SNAPSHOTS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS payroll_shift_snapshots (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      payroll_batch_id BIGINT UNSIGNED NOT NULL,
      shift_id BIGINT UNSIGNED NOT NULL,
      employee_id INT UNSIGNED NOT NULL,
      worked_minutes INT NOT NULL DEFAULT 0,
      break_minutes INT NOT NULL DEFAULT 0,
      paid_minutes INT NOT NULL DEFAULT 0,
      normal_minutes INT NOT NULL DEFAULT 0,
      weekend_minutes INT NOT NULL DEFAULT 0,
      bank_holiday_minutes INT NOT NULL DEFAULT 0,
      overtime_minutes INT NOT NULL DEFAULT 0,
      applied_rule VARCHAR(50) NULL,
      rounding_minutes INT NULL,
      rounded_paid_minutes INT NULL,
      day_breakdown_json MEDIUMTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_batch_emp (payroll_batch_id, employee_id),
      KEY idx_shift (shift_id),
      KEY idx_batch (payroll_batch_id)
    ) ENGINE=InnoDB;
  ");

  // PAYROLL RUN LOGS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS payroll_run_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      payroll_batch_id BIGINT UNSIGNED NOT NULL,
      action VARCHAR(30) NOT NULL,
      performed_by BIGINT UNSIGNED NULL,
      performed_by_username VARCHAR(100) NULL,
      performed_by_role VARCHAR(30) NULL,
      reason VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_batch_time (payroll_batch_id, created_at),
      KEY idx_action_time (action, created_at)
    ) ENGINE=InnoDB;
  ");

}

/**
 * PART D — Reset
 */
function drop_all(PDO $pdo): void {
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
  foreach ([
    // Payroll
    'payroll_batches',
    'payroll_shift_snapshots',
    'payroll_run_logs',
    'payroll_bank_holidays',

    // Admin
    'admin_sessions',
    'admin_devices',
    'admin_users',

    // Kiosk logs/devices
    'kiosk_devices',
    'kiosk_health_log',
    'kiosk_event_log',

    // Punching
    'kiosk_punch_processing_steps',
    'kiosk_punch_photos',
    'kiosk_punch_events',

    // Shifts
    'kiosk_shift_changes',
    'kiosk_shifts',

    // Breaks / Pay
    'kiosk_break_tiers',
    'kiosk_employee_pay_profiles',

    // HR (✅ missing previously)
    'hr_staff_documents',
    'hr_staff_contracts',
    'hr_staff',
    'hr_applications',

    // Org
    'kiosk_employee_departments',
    'kiosk_employee_teams',

    // Kiosk identities + settings
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
    seed_employee_departments($pdo);
    seed_break_tiers($pdo);
    seed_admin_users($pdo);

    // Lock setup-only settings after first successful install/repair
    try {
      $pdo->prepare("UPDATE kiosk_settings SET value='1' WHERE `key`='app_initialized'")->execute();
    } catch (Throwable $e) { /* ignore */ }

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
    seed_employee_departments($pdo);
    seed_break_tiers($pdo);
    seed_admin_users($pdo);

    // Lock setup-only settings after reset too
    try {
      $pdo->prepare("UPDATE kiosk_settings SET value='1' WHERE `key`='app_initialized'")->execute();
    } catch (Throwable $e) { /* ignore */ }

    exit("🔥 Database reset completed");
  }

  echo '<h3>SmartCare Kiosk – Setup</h3>
  <ul>
    <li><a href="?action=install">Install / Repair</a></li>
    <li><a href="?action=reset&pin=2468" onclick="return confirm(\'RESET DATABASE?\')">Reset (PIN required)</a></li>
  </ul>';

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>ERROR:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
}
