-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: sdb-76.hosting.stackcp.net
-- Generation Time: Feb 10, 2026 at 11:42 AM
-- Server version: 10.6.18-MariaDB-log
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kiosk-stowpark-35303733a7b0`
--

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_settings`
--

CREATE TABLE `kiosk_settings` (
  `key` varchar(150) NOT NULL,
  `value` text NOT NULL,
  `group_name` varchar(50) NOT NULL DEFAULT 'general',
  `label` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'string',
  `editable_by` varchar(20) NOT NULL DEFAULT 'superadmin',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_secret` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `kiosk_settings`
--

INSERT INTO `kiosk_settings` (`key`, `value`, `group_name`, `label`, `description`, `type`, `editable_by`, `sort_order`, `is_secret`, `created_at`, `updated_at`) VALUES
('admin_pairing_code', '2468', 'admin', 'Admin Pairing Passcode', 'Passcode required to authorise a device for /admin (only works if admin_pairing_mode is enabled).', 'secret', 'superadmin', 100, 1, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('admin_pairing_mode', '0', 'admin', 'Admin Pairing Mode Enabled', 'When 0, /admin/pair.php rejects pairing even if the passcode is known.', 'bool', 'superadmin', 110, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('admin_pairing_mode_until', '', 'admin', 'Admin Pairing Mode Until', 'Optional UTC datetime. If set and expired, admin pairing is auto-disabled.', 'string', 'superadmin', 120, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('admin_pairing_version', '1', 'admin', 'Admin Pairing Version', 'Bump this to revoke all admin trusted devices.', 'int', 'superadmin', 90, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('admin_ui_version', '1', 'admin', 'Admin UI Version', 'Change this to force admin pages to reload assets (cache-busting).', 'string', 'superadmin', 80, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('allow_plain_pin', '1', 'security', 'Allow Plain PIN', 'If 1, allows plain PIN entries in kiosk_employees (simple installs). Prefer hashed in production.', 'bool', 'superadmin', 120, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('app_initialized', '1', 'system', 'App Initialized', 'Internal lock flag. Once set to 1, setup-only settings become read-only.', 'bool', 'superadmin', 705, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('auth_fail_max', '5', 'security', 'Max Auth Failures', 'Max failures within window before returning 429.', 'int', 'superadmin', 150, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('auth_fail_window_sec', '300', 'security', 'Auth Fail Window (seconds)', 'Time window to count failed PIN attempts.', 'int', 'superadmin', 140, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('auto_approve_clean_shifts', '1', 'payroll', 'Auto-approve Clean Shifts', 'If 1, shifts that clock-in and clock-out normally (not autoclosed, not edited, valid duration) are auto-approved.', 'bool', 'admin', 719, 0, '2026-01-29 01:19:00', '2026-02-01 11:08:32'),
('clockin_cooldown_minutes', '15', 'payroll', 'Clock-in Cooldown Minutes', 'Minimum minutes required after last clock-out before employee can clock in again. Set 0 to disable.', 'int', 'admin', 720, 0, '2026-01-29 01:19:00', '2026-02-01 11:08:32'),
('debug_mode', '0', 'debug', 'Debug Mode', 'If 1, endpoints may return extra debug details.', 'bool', 'superadmin', 510, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('device_offline_after_sec', '300', 'health', 'Device Offline After (sec)', 'Mark kiosk offline if no ping seen within this time.', 'int', 'superadmin', 415, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('is_paired', '1', 'pairing', 'Is Paired', '0/1 flag used by UI to show paired status. Pairing sets this to 1; revoke clears it.', 'bool', 'none', 20, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('kiosk_code', 'KIOSK-STOWPARK', 'identity', 'Kiosk Code', 'Short identifier for this kiosk (used in logs and API headers).', 'string', 'superadmin', 10, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('manager_pin', '2468', 'security', 'Manager PIN', 'PIN used for manager-only actions (must be different from pairing_code).', 'secret', 'superadmin', 55, 1, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('max_shift_minutes', '960', 'limits', 'Max Shift Minutes', 'Maximum shift length for validation/autoclose (future).', 'int', 'superadmin', 210, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('min_seconds_between_punches', '5', 'security', 'Min Seconds Between Punches', 'Rate limit per employee to prevent double taps.', 'int', 'superadmin', 130, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('offline_allow_unencrypted_pin', '1', 'sync', 'Allow Unencrypted Offline PIN', 'If 1, allow plaintext PIN in offline queue if WebCrypto unavailable (trusted devices only).', 'bool', 'superadmin', 468, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('offline_max_backdate_minutes', '2880', 'sync', 'Offline Max Backdate (minutes)', 'Accept offline device_time this many minutes in past (clamp beyond).', 'int', 'superadmin', 465, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('offline_max_future_seconds', '120', 'sync', 'Offline Max Future (seconds)', 'Allow device_time this many seconds in future (clamp beyond).', 'int', 'superadmin', 466, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('offline_time_mismatch_log_sec', '300', 'sync', 'Time Mismatch Threshold (sec)', 'Log time_mismatch if device time differs by more than this.', 'int', 'superadmin', 467, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('paired_device_token', '', 'pairing', 'Legacy Paired Device Token', 'Legacy token storage. Not used for auth; kept for backward compatibility.', 'secret', 'none', 31, 1, '2026-02-01 11:08:32', '2026-02-01 11:08:32'),
('paired_device_token_hash', '9c961b5954d9431e79c4cbfbcb457eb066f583c737d253870bd256f92f59474f', 'pairing', 'Paired Device Token Hash', 'SHA-256 hash of the paired device token. Only the paired device can authorise requests.', 'secret', 'none', 30, 1, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('pairing_code', '2468', 'pairing', 'Pairing Passcode', 'Passcode required to pair a device (only works if pairing_mode is enabled).', 'secret', 'superadmin', 50, 1, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('pairing_mode', '0', 'pairing', 'Pairing Mode Enabled', 'When 0, /api/kiosk/pair.php rejects pairing even if the passcode is known.', 'bool', 'superadmin', 60, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('pairing_mode_until', '', 'pairing', 'Pairing Mode Until', 'Optional UTC datetime. If set and expired, pairing is auto-disabled.', 'string', 'superadmin', 70, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('pairing_version', '1', 'pairing', 'Pairing Version', 'Bumps whenever pairing is revoked/reset; helps clients detect pairing changes.', 'int', 'superadmin', 40, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('pair_fail_max', '5', 'security', 'Max Pair Failures', 'Max failures within window before returning 429.', 'int', 'superadmin', 180, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('pair_fail_window_sec', '600', 'security', 'Pair Fail Window (seconds)', 'Time window to count failed pairing attempts.', 'int', 'superadmin', 170, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('payroll_month_boundary_mode', 'end_of_shift', 'payroll', 'Payroll Month Boundary', 'How to assign shifts that cross the month boundary: midnight (split at local midnight) or end_of_shift (assign whole shift to the month of its start date). Only superadmin should change this and it should not be changed retroactively.', 'string', 'superadmin', 718, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('payroll_timezone', 'Europe/London', 'payroll', 'Payroll Timezone', 'Timezone used for day/week boundaries (weekend and bank holiday cutoffs).', 'string', 'admin', 715, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('payroll_week_starts_on', 'SUNDAY', 'payroll', 'Week Starts On', 'Defines the week boundary used everywhere (payroll, overtime, rota/week views). Set once at initial setup and cannot be changed later.', 'string', 'none', 710, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ping_interval_ms', '60000', 'health', 'Ping Interval (ms)', 'How often client pings /api/kiosk/ping.php.', 'int', 'superadmin', 410, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('pin_length', '4', 'security', 'PIN Length', 'Number of digits required for staff PINs.', 'int', 'superadmin', 110, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('rounding_enabled', '1', 'rounding', 'Rounding Enabled', 'If 1, admin/payroll views can calculate rounded times for payroll without changing originals.', 'bool', 'superadmin', 10, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('round_grace_minutes', '5', 'rounding', 'Rounding Grace Minutes', 'Only snap when within this many minutes of boundary.', 'int', 'superadmin', 30, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('round_increment_minutes', '15', 'rounding', 'Rounding Increment Minutes', 'Snap time to this minute grid (e.g., 15 => 00,15,30,45).', 'int', 'superadmin', 20, 0, '2026-01-26 11:16:11', '2026-01-26 11:16:53'),
('sync_batch_size', '20', 'sync', 'Sync Batch Size', 'Max number of queued records per sync batch.', 'int', 'superadmin', 440, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('sync_cooldown_ms', '8000', 'sync', 'Sync Cooldown (ms)', 'Cooldown between sync attempts after an error.', 'int', 'superadmin', 430, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('sync_interval_ms', '30000', 'sync', 'Sync Interval (ms)', 'How often client attempts background sync.', 'int', 'superadmin', 420, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_open_shifts_count', '6', 'ui', 'Open Shifts Count', 'How many open shifts to show.', 'int', 'manager', 340, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_open_shifts_show_time', '1', 'ui', 'Open Shifts Show Time', 'If 1, panel shows clock-in time and duration.', 'bool', 'manager', 345, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_reload_check_ms', '60000', 'ui', 'UI Reload Check (ms)', 'How often to check ui_version.', 'int', 'superadmin', 360, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_reload_enabled', '0', 'ui', 'UI Auto Reload Enabled', 'If 1, kiosk checks ui_version changes and reloads.', 'bool', 'superadmin', 350, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_reload_token', '0', 'ui', 'UI Reload Token', 'Change to force a reload even if ui_version unchanged.', 'string', 'superadmin', 365, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_show_clock', '1', 'ui', 'Show Clock', 'If 1, kiosk shows current time/date panel.', 'bool', 'manager', 325, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_show_open_shifts', '0', 'ui', 'Show Open Shifts', 'If 1, kiosk can display currently clocked-in staff.', 'bool', 'manager', 330, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_text.employee_notice', 'Please clock in at the start of your shift and clock out when you finish.', 'ui_text', 'Employee Notice', 'Notice text shown to staff on kiosk screen.', 'string', 'manager', 630, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_text.kiosk_subtitle', 'Clock in / Clock out', 'ui_text', 'Kiosk Subtitle', 'Subtitle displayed under the kiosk title.', 'string', 'manager', 620, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_text.kiosk_title', 'Clock Kiosk', 'ui_text', 'Kiosk Title', 'Main title displayed on the kiosk screen.', 'string', 'manager', 610, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_text.not_authorised_message', 'This device is not authorised.', 'ui_text', 'Not Authorised Message', 'Message shown when kiosk token missing/invalid.', 'string', 'superadmin', 650, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_text.not_paired_message', 'This device is not paired. Please contact admin.', 'ui_text', 'Not Paired Message', 'Message shown when kiosk is not paired.', 'string', 'superadmin', 640, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_thank_ms', '3000', 'ui', 'Thank You Screen (ms)', 'How long to show success screen after a punch.', 'int', 'superadmin', 320, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('ui_version', '1', 'ui', 'UI Version', 'Cache-busting version for CSS/JS.', 'string', 'superadmin', 310, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32'),
('uploads_base_path', '', 'system', 'Uploads Base Path', 'Filesystem base directory for uploads. Use \'auto\' to use the private APP_UPLOADS_PATH constant (recommended). Or set a relative path like \"uploads\" for public storage (dev only).', 'string', 'superadmin', 704, 0, '2026-01-26 11:16:11', '2026-02-01 11:08:32');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `kiosk_settings`
--
ALTER TABLE `kiosk_settings`
  ADD PRIMARY KEY (`key`),
  ADD KEY `idx_group` (`group_name`),
  ADD KEY `idx_editable` (`editable_by`),
  ADD KEY `idx_sort` (`sort_order`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
