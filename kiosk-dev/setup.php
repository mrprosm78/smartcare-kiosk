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
      'key' => 'pairing_code', 'value' => '2468', 'group' => 'pairing',
      'label' => 'Pairing Passcode', 'description' => 'Passcode required to pair a device (only works if pairing_mode is enabled).',
      'type' => 'secret', 'editable_by' => 'superadmin', 'sort' => 50, 'secret' => 1,
    ],
    [
      'key' => 'manager_pin', 'value' => '2468', 'group' => 'security',
      'label' => 'Manager PIN', 'description' => 'PIN used for manager-only actions (must be different from pairing_code).',
      'type' => 'secret', 'editable_by' => 'superadmin', 'sort' => 55, 'secret' => 1,
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
      'key' => 'admin_pairing_code', 'value' => '2468', 'group' => 'admin',
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

    // ===========================
    // System / Setup Locks
    // ===========================
    [
      'key' => 'uploads_base_path', 'value' => 'uploads', 'group' => 'system',
      'label' => 'Uploads Base Path', 'description' => "Filesystem base directory for uploads. Use 'auto' to use the private APP_UPLOADS_PATH constant (recommended). Or set a relative path like \"uploads\" for public storage (dev only).",

      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 704, 'secret' => 0,
    ],
    [
      'key' => 'app_initialized', 'value' => '0', 'group' => 'system',
      'label' => 'App Initialized', 'description' => 'Internal lock flag. Once set to 1, setup-only settings become read-only.',
      'type' => 'bool', 'editable_by' => 'superadmin', 'sort' => 705, 'secret' => 0,
    ],

    // ===========================
    // Payroll (Care-home Rules)
    // ===========================
    [
      'key' => 'payroll_week_starts_on', 'value' => 'MONDAY', 'group' => 'payroll',
      'label' => 'Week Starts On', 'description' => 'Defines the week boundary used everywhere (payroll, overtime, rota/week views). Set once at initial setup and cannot be changed later.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 710, 'secret' => 0,
    ],
    [
      'key' => 'payroll_timezone', 'value' => 'Europe/London', 'group' => 'payroll',
      'label' => 'Payroll Timezone', 'description' => 'Timezone used for day/week boundaries (weekend and bank holiday cutoffs).',
      'type' => 'string', 'editable_by' => 'admin', 'sort' => 715, 'secret' => 0,
    ],
    [
      'key' => 'default_break_minutes', 'value' => '0', 'group' => 'payroll',
      'label' => 'Default Break Minutes', 'description' => 'Fallback unpaid break minutes deducted when no shift break rule matches.',
      'type' => 'string', 'editable_by' => 'admin', 'sort' => 716, 'secret' => 0,
    ],
    [
      'key' => 'payroll_overtime_threshold_hours', 'value' => '40', 'group' => 'payroll',
      'label' => 'Overtime Threshold (hours/week)', 'description' => 'If paid hours in the payroll week exceed this, the excess becomes overtime (contract rules can override enhancements).',
      'type' => 'string', 'editable_by' => 'admin', 'sort' => 720, 'secret' => 0,
    ],
    [
      'key' => 'payroll_stacking_mode', 'value' => 'exclusive', 'group' => 'payroll',
      'label' => 'Stacking Mode', 'description' => 'exclusive = apply at most ONE enhancement total per minute (highest wins). stack = allow ONE multiplier + ONE premium per minute.',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 730, 'secret' => 0,
    ],
    [
      'key' => 'payroll_night_start', 'value' => '20:00', 'group' => 'payroll',
      'label' => 'Night Start', 'description' => 'Night window start time (24h HH:MM).',
      'type' => 'string', 'editable_by' => 'admin', 'sort' => 740, 'secret' => 0,
    ],
    [
      'key' => 'payroll_night_end', 'value' => '07:00', 'group' => 'payroll',
      'label' => 'Night End', 'description' => 'Night window end time (24h HH:MM).',
      'type' => 'string', 'editable_by' => 'admin', 'sort' => 741, 'secret' => 0,
    ],
    [
      'key' => 'payroll_bank_holiday_cap_hours', 'value' => '12', 'group' => 'payroll',
      'label' => 'Bank Holiday Cap (hours)', 'description' => 'Max bank holiday enhanced hours per shift (e.g., 12 hours).',
      'type' => 'string', 'editable_by' => 'admin', 'sort' => 750, 'secret' => 0,
    ],
    [
      'key' => 'payroll_callout_min_paid_hours', 'value' => '4', 'group' => 'payroll',
      'label' => 'Call-out Minimum Paid Hours', 'description' => 'If a shift is marked call-out and paid hours are below this, uplift paid hours to this minimum BEFORE overtime is calculated.',
      'type' => 'string', 'editable_by' => 'admin', 'sort' => 760, 'secret' => 0,
    ],

    // Default premiums & multipliers (care-home defaults). Contract overrides via employee pay profile rules_json.
    [
      'key' => 'default_night_multiplier', 'value' => '1.00', 'group' => 'payroll',
      'label' => 'Default Night Multiplier', 'description' => 'Default night multiplier (1.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 800, 'secret' => 0,
    ],
    [
      'key' => 'default_night_premium_per_hour', 'value' => '0.00', 'group' => 'payroll',
      'label' => 'Default Night Premium (£/hour)', 'description' => 'Default night premium per hour (0.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 801, 'secret' => 0,
    ],
    [
      'key' => 'default_weekend_multiplier', 'value' => '1.00', 'group' => 'payroll',
      'label' => 'Default Weekend Multiplier', 'description' => 'Default weekend multiplier (1.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 810, 'secret' => 0,
    ],
    [
      'key' => 'default_weekend_premium_per_hour', 'value' => '0.00', 'group' => 'payroll',
      'label' => 'Default Weekend Premium (£/hour)', 'description' => 'Default weekend premium per hour (0.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 811, 'secret' => 0,
    ],
    [
      'key' => 'default_bank_holiday_multiplier', 'value' => '1.50', 'group' => 'payroll',
      'label' => 'Default Bank Holiday Multiplier', 'description' => 'Default bank holiday multiplier (1.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 820, 'secret' => 0,
    ],
    [
      'key' => 'default_bank_holiday_premium_per_hour', 'value' => '0.00', 'group' => 'payroll',
      'label' => 'Default Bank Holiday Premium (£/hour)', 'description' => 'Default bank holiday premium per hour (0.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 821, 'secret' => 0,
    ],
    [
      'key' => 'default_overtime_multiplier', 'value' => '1.00', 'group' => 'payroll',
      'label' => 'Default Overtime Multiplier', 'description' => 'Default overtime multiplier (1.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 830, 'secret' => 0,
    ],
    [
      'key' => 'default_overtime_premium_per_hour', 'value' => '0.00', 'group' => 'payroll',
      'label' => 'Default Overtime Premium (£/hour)', 'description' => 'Default overtime premium per hour (0.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 831, 'secret' => 0,
    ],
    [
      'key' => 'default_callout_multiplier', 'value' => '1.00', 'group' => 'payroll',
      'label' => 'Default Call-out Multiplier', 'description' => 'Default call-out multiplier (1.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 840, 'secret' => 0,
    ],
    [
      'key' => 'default_callout_premium_per_hour', 'value' => '0.00', 'group' => 'payroll',
      'label' => 'Default Call-out Premium (£/hour)', 'description' => 'Default call-out premium per hour (0.00 = none).',
      'type' => 'string', 'editable_by' => 'superadmin', 'sort' => 841, 'secret' => 0,
    ],
  ];
  $stripKeys = [
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

  $defs = array_values(array_filter($defs, function(array $d) use ($stripKeys) {
    $k = $d['key'] ?? null;
    if ($k === null) return true;
    return !in_array((string)$k, $stripKeys, true);
  }));



  

  // Cleanup: ensure legacy care-home payroll rules are removed from kiosk_settings.
  try {
    if (!empty($stripKeys)) {
      $in = implode(',', array_fill(0, count($stripKeys), '?'));
      $del = $pdo->prepare("DELETE FROM kiosk_settings WHERE `key` IN ($in)");
      $del->execute(array_values($stripKeys));
    }
  } catch (Throwable $e) {
    // Ignore cleanup errors to avoid blocking install/upgrade.
  }

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

  // Cleanup legacy settings that are no longer used.
  delete_setting_if_exists($pdo, 'default_break_is_paid');
}

function seed_admin_users(PDO $pdo): void {
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
  } catch (Throwable $e) {
    return;
  }
  if ($count > 0) return;

  // Define all users with their passwords
  $users = [
    // Super Admins
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
    // Managers
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
    // Payroll
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

  // EMPLOYEE TEAMS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_teams (
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
      KEY idx_category (category_id),
      KEY idx_team (team_id),
      UNIQUE KEY uq_pin_fp (pin_fingerprint),
      KEY idx_agency (is_agency)
    ) ENGINE=InnoDB;
  ");
  add_column_if_missing($pdo, 'kiosk_employees', 'category_id', 'INT UNSIGNED NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'team_id', 'INT UNSIGNED NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'pin_fingerprint', 'CHAR(64) NULL');
  try { $pdo->exec("ALTER TABLE kiosk_employees ADD UNIQUE KEY uq_pin_fp (pin_fingerprint)"); } catch (Throwable $e) { /* ignore */ }

  add_column_if_missing($pdo, 'kiosk_employees', 'is_agency', "TINYINT(1) NOT NULL DEFAULT 0");
  add_column_if_missing($pdo, 'kiosk_employees', 'agency_label', 'VARCHAR(100) NULL');
  add_column_if_missing($pdo, 'kiosk_employees', 'nickname', 'VARCHAR(100) NULL');

  // PAY PROFILES
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_employee_pay_profiles (
      employee_id INT UNSIGNED PRIMARY KEY,
      contract_hours_per_week DECIMAL(6,2) NULL,
      hourly_rate DECIMAL(8,2) NULL,
      -- Break model (LOCKED): break minutes come from Shift Rules; this flag controls paid/unpaid per contract
      break_is_paid TINYINT(1) NOT NULL DEFAULT 0,

      -- Enhancement rules (LOCKED): stored in JSON (contract-first)
      rules_json JSON NULL,

      -- Inheritance + overtime threshold (hours/week)
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
  add_column_if_missing($pdo, 'kiosk_shifts', 'training_minutes', 'INT NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'training_note', 'VARCHAR(255) NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_locked_at', 'DATETIME NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_locked_by', 'VARCHAR(100) NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'payroll_batch_id', 'VARCHAR(64) NULL');
  add_column_if_missing($pdo, 'kiosk_shifts', 'is_callout', "TINYINT(1) NOT NULL DEFAULT 0");
  // Pay profile cleanup (break minutes are now shift-rule based)
  drop_column_if_exists($pdo, 'kiosk_employee_pay_profiles', 'break_minutes_default');
  drop_column_if_exists($pdo, 'kiosk_employee_pay_profiles', 'break_minutes_night');
  drop_column_if_exists($pdo, 'kiosk_employee_pay_profiles', 'min_hours_for_break');
  add_column_if_missing($pdo, 'kiosk_employee_pay_profiles', 'hourly_rate', 'DECIMAL(8,2) NULL');
  add_column_if_missing($pdo, 'kiosk_employee_pay_profiles', 'inherit_from_carehome', 'TINYINT(1) NOT NULL DEFAULT 1');
  add_column_if_missing($pdo, 'kiosk_employee_pay_profiles', 'overtime_threshold_hours', 'DECIMAL(6,2) NULL');
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

  // PUNCH PHOTOS (camera add-on)
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


  // BREAK RULES (shift windows; matched by shift start time)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS kiosk_break_rules (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      start_time CHAR(5) NOT NULL,
      end_time CHAR(5) NOT NULL,
      break_minutes INT NOT NULL,
      is_paid_break TINYINT(1) NOT NULL DEFAULT 0,
      priority INT NOT NULL DEFAULT 0,
      is_enabled TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_enabled_priority (is_enabled, priority)
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

  // PAYROLL BANK HOLIDAYS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS payroll_bank_holidays (
      holiday_date DATE PRIMARY KEY,
      name VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
  ");

  // PAYROLL BATCHES (monthly payroll runs)
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

}

/**
 * PART D — Reset
 */
function drop_all(PDO $pdo): void {
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
  foreach ([
    'payroll_batches',
    'payroll_bank_holidays',
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
    seed_employee_categories($pdo);
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


