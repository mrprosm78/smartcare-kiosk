-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: sdb-67.hosting.stackcp.net
-- Generation Time: Jan 21, 2026 at 11:42 PM
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
-- Database: `kiosk01-35303437df66`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_devices`
--

CREATE TABLE `admin_devices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `pairing_version` int(11) NOT NULL DEFAULT 1,
  `first_paired_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `last_user_agent` varchar(255) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` bigint(20) UNSIGNED DEFAULT NULL,
  `revoke_reason` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_sessions`
--

CREATE TABLE `admin_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `device_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` bigint(20) UNSIGNED DEFAULT NULL,
  `revoke_reason` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `display_name` varchar(150) DEFAULT NULL,
  `role` enum('manager','payroll','admin','superadmin') NOT NULL DEFAULT 'manager',
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `display_name`, `role`, `password_hash`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'superadmin', 'Super Admin', 'superadmin', '$2y$10$wi6HM.IMEfS4PF4nXNHjLuxeIup5lSQPBC4tmdFuS.g7G2M/BxK86', 1, NULL, '2026-01-21 23:31:48', '2026-01-21 23:31:48');

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_break_rules`
--

CREATE TABLE `kiosk_break_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `start_time` char(5) NOT NULL,
  `end_time` char(5) NOT NULL,
  `break_minutes` int(11) NOT NULL,
  `is_paid_break` tinyint(1) NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_devices`
--

CREATE TABLE `kiosk_devices` (
  `kiosk_code` varchar(50) NOT NULL,
  `device_token_hash` char(64) DEFAULT NULL,
  `pairing_version` int(11) DEFAULT NULL,
  `last_seen_at` datetime NOT NULL,
  `last_seen_kind` varchar(20) DEFAULT NULL,
  `last_authorised` tinyint(1) NOT NULL DEFAULT 0,
  `last_error_code` varchar(50) DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `last_user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `kiosk_devices`
--

INSERT INTO `kiosk_devices` (`kiosk_code`, `device_token_hash`, `pairing_version`, `last_seen_at`, `last_seen_kind`, `last_authorised`, `last_error_code`, `last_ip`, `last_user_agent`, `created_at`, `updated_at`) VALUES
('KIOSK-1', '78c971fb5b356920970fdf29a1b350e6007a2860e3ced66a6dba7fd9afbc6c8f', 1, '2026-01-21 23:41:43', 'status', 1, NULL, '2a00:23c6:35d6:b901:db0:5ad9:5717:6f33', 'Mozilla/5.0 (Linux; Android 10; SM-N960F Build/QP1A.190711.020; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/143.0.7499.192 Mobile Safari/537.36', '2026-01-21 23:32:08', '2026-01-21 23:41:43');

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_employees`
--

CREATE TABLE `kiosk_employees` (
  `id` int(10) UNSIGNED NOT NULL,
  `employee_code` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `nickname` varchar(100) DEFAULT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `team_id` int(10) UNSIGNED DEFAULT NULL,
  `is_agency` tinyint(1) NOT NULL DEFAULT 0,
  `agency_label` varchar(100) DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `pin_fingerprint` char(64) DEFAULT NULL,
  `pin_updated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_employee_categories`
--

CREATE TABLE `kiosk_employee_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `kiosk_employee_categories`
--

INSERT INTO `kiosk_employee_categories` (`id`, `name`, `slug`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Carer', 'carer', 1, 10, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
(2, 'Senior Carer', 'senior-carer', 1, 20, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
(3, 'Nurse', 'nurse', 1, 30, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
(4, 'Kitchen', 'kitchen', 1, 40, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
(5, 'Housekeeping', 'housekeeping', 1, 50, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
(6, 'Maintenance', 'maintenance', 1, 60, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
(7, 'Admin', 'admin', 1, 70, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
(8, 'Agency', 'agency', 1, 80, '2026-01-21 23:31:48', '2026-01-21 23:31:48');

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_employee_pay_profiles`
--

CREATE TABLE `kiosk_employee_pay_profiles` (
  `employee_id` int(10) UNSIGNED NOT NULL,
  `contract_hours_per_week` decimal(6,2) DEFAULT NULL,
  `hourly_rate` decimal(8,2) DEFAULT NULL,
  `break_is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `rules_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rules_json`)),
  `inherit_from_carehome` tinyint(1) NOT NULL DEFAULT 1,
  `overtime_threshold_hours` decimal(6,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_employee_teams`
--

CREATE TABLE `kiosk_employee_teams` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_event_log`
--

CREATE TABLE `kiosk_event_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  `kiosk_code` varchar(50) DEFAULT NULL,
  `pairing_version` int(11) DEFAULT NULL,
  `device_token_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `result` varchar(20) NOT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`))
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `kiosk_event_log`
--

INSERT INTO `kiosk_event_log` (`id`, `occurred_at`, `kiosk_code`, `pairing_version`, `device_token_hash`, `ip_address`, `user_agent`, `employee_id`, `event_type`, `result`, `error_code`, `message`, `meta_json`) VALUES
(1, '2026-01-21 23:32:08', 'KIOSK-1', 1, 'e6d91c615ea9b581b264cb3bc100596f140aba1267034528c2d74ebd4fcf44a7', '2a00:23c6:35d6:b901:db0:5ad9:5717:6f33', 'Mozilla/5.0 (Linux; Android 10; SM-N960F Build/QP1A.190711.020; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/143.0.7499.192 Mobile Safari/537.36', NULL, 'punch_auth', 'fail', 'kiosk_not_paired', NULL, NULL),
(2, '2026-01-21 23:32:17', 'KIOSK-1', 1, '78c971fb5b356920970fdf29a1b350e6007a2860e3ced66a6dba7fd9afbc6c8f', '2a00:23c6:35d6:b901:db0:5ad9:5717:6f33', 'Mozilla/5.0 (Linux; Android 10; SM-N960F Build/QP1A.190711.020; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/143.0.7499.192 Mobile Safari/537.36', NULL, 'pair', 'success', NULL, 'Device paired successfully', NULL),
(3, '2026-01-21 23:32:22', 'KIOSK-1', 1, '78c971fb5b356920970fdf29a1b350e6007a2860e3ced66a6dba7fd9afbc6c8f', '2a00:23c6:35d6:b901:db0:5ad9:5717:6f33', 'Mozilla/5.0 (Linux; Android 10; SM-N960F Build/QP1A.190711.020; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/143.0.7499.192 Mobile Safari/537.36', NULL, 'punch_auth', 'fail', 'invalid_pin', NULL, NULL),
(4, '2026-01-21 23:32:28', 'KIOSK-1', 1, '78c971fb5b356920970fdf29a1b350e6007a2860e3ced66a6dba7fd9afbc6c8f', '2a00:23c6:35d6:b901:db0:5ad9:5717:6f33', 'Mozilla/5.0 (Linux; Android 10; SM-N960F Build/QP1A.190711.020; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/143.0.7499.192 Mobile Safari/537.36', NULL, 'punch_auth', 'fail', 'invalid_pin', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_health_log`
--

CREATE TABLE `kiosk_health_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `kiosk_code` varchar(50) DEFAULT NULL,
  `pairing_version` int(11) DEFAULT NULL,
  `device_token_hash` char(64) DEFAULT NULL,
  `online` tinyint(1) DEFAULT 1,
  `queue_size` int(11) DEFAULT 0,
  `last_error_code` varchar(50) DEFAULT NULL,
  `ui_version` varchar(20) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`))
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_punch_events`
--

CREATE TABLE `kiosk_punch_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_uuid` char(36) DEFAULT NULL,
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `action` enum('IN','OUT') DEFAULT NULL,
  `device_time` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `effective_time` datetime DEFAULT NULL,
  `result_status` varchar(20) DEFAULT NULL,
  `source` varchar(20) DEFAULT NULL,
  `was_offline` tinyint(1) NOT NULL DEFAULT 0,
  `error_code` varchar(50) DEFAULT NULL,
  `shift_id` bigint(20) UNSIGNED DEFAULT NULL,
  `kiosk_code` varchar(50) DEFAULT NULL,
  `device_token_hash` char(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_punch_photos`
--

CREATE TABLE `kiosk_punch_photos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_uuid` char(36) NOT NULL,
  `action` enum('IN','OUT') NOT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `device_name` varchar(150) DEFAULT NULL,
  `photo_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

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
('admin_pairing_code', '2468', 'admin', 'Admin Pairing Passcode', 'Passcode required to authorise a device for /admin (only works if admin_pairing_mode is enabled).', 'secret', 'superadmin', 100, 1, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('admin_pairing_mode', '0', 'admin', 'Admin Pairing Mode Enabled', 'When 0, /admin/pair.php rejects pairing even if the passcode is known.', 'bool', 'superadmin', 110, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('admin_pairing_mode_until', '', 'admin', 'Admin Pairing Mode Until', 'Optional UTC datetime. If set and expired, admin pairing is auto-disabled.', 'string', 'superadmin', 120, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('admin_pairing_version', '1', 'admin', 'Admin Pairing Version', 'Bump this to revoke all admin trusted devices.', 'int', 'superadmin', 90, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('admin_ui_version', '1', 'admin', 'Admin UI Version', 'Change this to force admin pages to reload assets (cache-busting).', 'string', 'superadmin', 80, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('allow_plain_pin', '1', 'security', 'Allow Plain PIN', 'If 1, allows plain PIN entries in kiosk_employees (simple installs). Prefer hashed in production.', 'bool', 'superadmin', 120, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('app_initialized', '1', 'system', 'App Initialized', 'Internal lock flag. Once set to 1, setup-only settings become read-only.', 'bool', 'superadmin', 705, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('auth_fail_max', '5', 'security', 'Max Auth Failures', 'Max failures within window before returning 429.', 'int', 'superadmin', 150, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('auth_fail_window_sec', '300', 'security', 'Auth Fail Window (seconds)', 'Time window to count failed PIN attempts.', 'int', 'superadmin', 140, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('debug_mode', '0', 'debug', 'Debug Mode', 'If 1, endpoints may return extra debug details.', 'bool', 'superadmin', 510, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('default_break_minutes', '0', 'payroll', 'Default Break Minutes', 'Fallback unpaid break minutes deducted when no shift break rule matches.', 'string', 'admin', 716, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('device_offline_after_sec', '300', 'health', 'Device Offline After (sec)', 'Mark kiosk offline if no ping seen within this time.', 'int', 'superadmin', 415, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('is_paired', '1', 'pairing', 'Is Paired', '0/1 flag used by UI to show paired status. Pairing sets this to 1; revoke clears it.', 'bool', 'none', 20, 0, '2026-01-21 23:31:48', '2026-01-21 23:32:17'),
('kiosk_code', 'KIOSK-1', 'identity', 'Kiosk Code', 'Short identifier for this kiosk (used in logs and API headers).', 'string', 'superadmin', 10, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('manager_pin', '2468', 'security', 'Manager PIN', 'PIN used for manager-only actions (must be different from pairing_code).', 'secret', 'superadmin', 55, 1, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('max_shift_minutes', '960', 'limits', 'Max Shift Minutes', 'Maximum shift length for validation/autoclose (future).', 'int', 'superadmin', 210, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('min_seconds_between_punches', '5', 'security', 'Min Seconds Between Punches', 'Rate limit per employee to prevent double taps.', 'int', 'superadmin', 130, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('offline_allow_unencrypted_pin', '1', 'sync', 'Allow Unencrypted Offline PIN', 'If 1, allow plaintext PIN in offline queue if WebCrypto unavailable (trusted devices only).', 'bool', 'superadmin', 468, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('offline_max_backdate_minutes', '2880', 'sync', 'Offline Max Backdate (minutes)', 'Accept offline device_time this many minutes in past (clamp beyond).', 'int', 'superadmin', 465, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('offline_max_future_seconds', '120', 'sync', 'Offline Max Future (seconds)', 'Allow device_time this many seconds in future (clamp beyond).', 'int', 'superadmin', 466, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('offline_time_mismatch_log_sec', '300', 'sync', 'Time Mismatch Threshold (sec)', 'Log time_mismatch if device time differs by more than this.', 'int', 'superadmin', 467, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('paired_device_token_hash', '78c971fb5b356920970fdf29a1b350e6007a2860e3ced66a6dba7fd9afbc6c8f', 'pairing', 'Paired Device Token Hash', 'SHA-256 hash of the paired device token. Only the paired device can authorise requests.', 'secret', 'none', 30, 1, '2026-01-21 23:31:48', '2026-01-21 23:32:17'),
('pairing_code', '2468', 'pairing', 'Pairing Passcode', 'Passcode required to pair a device (only works if pairing_mode is enabled).', 'secret', 'superadmin', 50, 1, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('pairing_mode', '0', 'pairing', 'Pairing Mode Enabled', 'When 0, /api/kiosk/pair.php rejects pairing even if the passcode is known.', 'bool', 'superadmin', 60, 0, '2026-01-21 23:31:48', '2026-01-21 23:32:17'),
('pairing_mode_until', '', 'pairing', 'Pairing Mode Until', 'Optional UTC datetime. If set and expired, pairing is auto-disabled.', 'string', 'superadmin', 70, 0, '2026-01-21 23:31:48', '2026-01-21 23:32:17'),
('pairing_version', '1', 'pairing', 'Pairing Version', 'Bumps whenever pairing is revoked/reset; helps clients detect pairing changes.', 'int', 'superadmin', 40, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('pair_fail_max', '5', 'security', 'Max Pair Failures', 'Max failures within window before returning 429.', 'int', 'superadmin', 180, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('pair_fail_window_sec', '600', 'security', 'Pair Fail Window (seconds)', 'Time window to count failed pairing attempts.', 'int', 'superadmin', 170, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('payroll_timezone', 'Europe/London', 'payroll', 'Payroll Timezone', 'Timezone used for day/week boundaries (weekend and bank holiday cutoffs).', 'string', 'admin', 715, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('payroll_week_starts_on', 'MONDAY', 'payroll', 'Week Starts On', 'Defines the week boundary used everywhere (payroll, overtime, rota/week views). Set once at initial setup and cannot be changed later.', 'string', 'superadmin', 710, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ping_interval_ms', '60000', 'health', 'Ping Interval (ms)', 'How often client pings /api/kiosk/ping.php.', 'int', 'superadmin', 410, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('pin_length', '4', 'security', 'PIN Length', 'Number of digits required for staff PINs.', 'int', 'superadmin', 110, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('rounding_enabled', '1', 'rounding', 'Rounding Enabled', 'If 1, admin/payroll views can calculate rounded times for payroll without changing originals.', 'bool', 'superadmin', 10, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('round_grace_minutes', '5', 'rounding', 'Rounding Grace Minutes', 'Only snap when within this many minutes of boundary.', 'int', 'superadmin', 30, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('round_increment_minutes', '15', 'rounding', 'Rounding Increment Minutes', 'Snap time to this minute grid (e.g., 15 => 00,15,30,45).', 'int', 'superadmin', 20, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('sync_batch_size', '20', 'sync', 'Sync Batch Size', 'Max number of queued records per sync batch.', 'int', 'superadmin', 440, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('sync_cooldown_ms', '8000', 'sync', 'Sync Cooldown (ms)', 'Cooldown between sync attempts after an error.', 'int', 'superadmin', 430, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('sync_interval_ms', '30000', 'sync', 'Sync Interval (ms)', 'How often client attempts background sync.', 'int', 'superadmin', 420, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_open_shifts_count', '6', 'ui', 'Open Shifts Count', 'How many open shifts to show.', 'int', 'manager', 340, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_open_shifts_show_time', '1', 'ui', 'Open Shifts Show Time', 'If 1, panel shows clock-in time and duration.', 'bool', 'manager', 345, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_reload_check_ms', '60000', 'ui', 'UI Reload Check (ms)', 'How often to check ui_version.', 'int', 'superadmin', 360, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_reload_enabled', '0', 'ui', 'UI Auto Reload Enabled', 'If 1, kiosk checks ui_version changes and reloads.', 'bool', 'superadmin', 350, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_reload_token', '0', 'ui', 'UI Reload Token', 'Change to force a reload even if ui_version unchanged.', 'string', 'superadmin', 365, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_show_clock', '1', 'ui', 'Show Clock', 'If 1, kiosk shows current time/date panel.', 'bool', 'manager', 325, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_show_open_shifts', '0', 'ui', 'Show Open Shifts', 'If 1, kiosk can display currently clocked-in staff.', 'bool', 'manager', 330, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_text.employee_notice', 'Please clock in at the start of your shift and clock out when you finish.', 'ui_text', 'Employee Notice', 'Notice text shown to staff on kiosk screen.', 'string', 'manager', 630, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_text.kiosk_subtitle', 'Clock in / Clock out', 'ui_text', 'Kiosk Subtitle', 'Subtitle displayed under the kiosk title.', 'string', 'manager', 620, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_text.kiosk_title', 'Clock Kiosk', 'ui_text', 'Kiosk Title', 'Main title displayed on the kiosk screen.', 'string', 'manager', 610, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_text.not_authorised_message', 'This device is not authorised.', 'ui_text', 'Not Authorised Message', 'Message shown when kiosk token missing/invalid.', 'string', 'superadmin', 650, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_text.not_paired_message', 'This device is not paired. Please contact admin.', 'ui_text', 'Not Paired Message', 'Message shown when kiosk is not paired.', 'string', 'superadmin', 640, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_thank_ms', '3000', 'ui', 'Thank You Screen (ms)', 'How long to show success screen after a punch.', 'int', 'superadmin', 320, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('ui_version', '1', 'ui', 'UI Version', 'Cache-busting version for CSS/JS.', 'string', 'superadmin', 310, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48'),
('uploads_base_path', 'uploads', 'system', 'Uploads Base Path', 'Filesystem base directory for uploads. Use a relative value like \"uploads\" for portable installs (resolved from project root).', 'string', 'superadmin', 704, 0, '2026-01-21 23:31:48', '2026-01-21 23:31:48');

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_shifts`
--

CREATE TABLE `kiosk_shifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `clock_in_at` datetime NOT NULL,
  `clock_out_at` datetime DEFAULT NULL,
  `training_minutes` int(11) DEFAULT NULL,
  `training_note` varchar(255) DEFAULT NULL,
  `is_callout` tinyint(1) NOT NULL DEFAULT 0,
  `duration_minutes` int(11) DEFAULT NULL,
  `is_closed` tinyint(1) DEFAULT 0,
  `close_reason` varchar(50) DEFAULT NULL,
  `is_autoclosed` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `approval_note` varchar(255) DEFAULT NULL,
  `last_modified_reason` varchar(50) DEFAULT NULL,
  `payroll_locked_at` datetime DEFAULT NULL,
  `payroll_locked_by` varchar(100) DEFAULT NULL,
  `payroll_batch_id` varchar(64) DEFAULT NULL,
  `created_source` varchar(20) DEFAULT NULL,
  `updated_source` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_shift_changes`
--

CREATE TABLE `kiosk_shift_changes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `shift_id` bigint(20) UNSIGNED NOT NULL,
  `change_type` enum('edit','approve','unapprove','payroll_lock','payroll_unlock') NOT NULL,
  `changed_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `changed_by_username` varchar(100) DEFAULT NULL,
  `changed_by_role` varchar(30) DEFAULT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `old_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_json`)),
  `new_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_bank_holidays`
--

CREATE TABLE `payroll_bank_holidays` (
  `holiday_date` date NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_batches`
--

CREATE TABLE `payroll_batches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `run_by` bigint(20) UNSIGNED DEFAULT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('FINAL','VOID') NOT NULL DEFAULT 'FINAL',
  `notes` varchar(255) DEFAULT NULL,
  `snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot_json`))
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_devices`
--
ALTER TABLE `admin_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_revoked` (`revoked_at`),
  ADD KEY `idx_seen` (`last_seen_at`),
  ADD KEY `idx_pairver` (`pairing_version`);

--
-- Indexes for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_id` (`session_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_revoked` (`revoked_at`),
  ADD KEY `idx_seen` (`last_seen_at`);

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `kiosk_break_rules`
--
ALTER TABLE `kiosk_break_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enabled_priority` (`is_enabled`,`priority`);

--
-- Indexes for table `kiosk_devices`
--
ALTER TABLE `kiosk_devices`
  ADD PRIMARY KEY (`kiosk_code`),
  ADD KEY `idx_seen` (`last_seen_at`),
  ADD KEY `idx_auth` (`last_authorised`);

--
-- Indexes for table `kiosk_employees`
--
ALTER TABLE `kiosk_employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD UNIQUE KEY `uq_pin_fp` (`pin_fingerprint`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_team` (`team_id`),
  ADD KEY `idx_agency` (`is_agency`);

--
-- Indexes for table `kiosk_employee_categories`
--
ALTER TABLE `kiosk_employee_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `kiosk_employee_pay_profiles`
--
ALTER TABLE `kiosk_employee_pay_profiles`
  ADD PRIMARY KEY (`employee_id`);

--
-- Indexes for table `kiosk_employee_teams`
--
ALTER TABLE `kiosk_employee_teams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `kiosk_event_log`
--
ALTER TABLE `kiosk_event_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_time` (`occurred_at`),
  ADD KEY `idx_ip_time` (`ip_address`,`occurred_at`),
  ADD KEY `idx_device_time` (`device_token_hash`,`occurred_at`),
  ADD KEY `idx_event` (`event_type`,`result`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indexes for table `kiosk_health_log`
--
ALTER TABLE `kiosk_health_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_time` (`recorded_at`),
  ADD KEY `idx_device_time` (`device_token_hash`,`recorded_at`);

--
-- Indexes for table `kiosk_punch_events`
--
ALTER TABLE `kiosk_punch_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_uuid` (`event_uuid`),
  ADD KEY `idx_employee_time` (`employee_id`,`effective_time`),
  ADD KEY `idx_shift` (`shift_id`),
  ADD KEY `idx_result` (`result_status`),
  ADD KEY `idx_kiosk` (`kiosk_code`);

--
-- Indexes for table `kiosk_punch_photos`
--
ALTER TABLE `kiosk_punch_photos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_uuid` (`event_uuid`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_action_created` (`action`,`created_at`);

--
-- Indexes for table `kiosk_settings`
--
ALTER TABLE `kiosk_settings`
  ADD PRIMARY KEY (`key`),
  ADD KEY `idx_group` (`group_name`),
  ADD KEY `idx_editable` (`editable_by`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Indexes for table `kiosk_shifts`
--
ALTER TABLE `kiosk_shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_open` (`employee_id`,`is_closed`),
  ADD KEY `idx_clock_in` (`clock_in_at`),
  ADD KEY `idx_locked` (`payroll_locked_at`);

--
-- Indexes for table `kiosk_shift_changes`
--
ALTER TABLE `kiosk_shift_changes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_time` (`shift_id`,`created_at`),
  ADD KEY `idx_type_time` (`change_type`,`created_at`);

--
-- Indexes for table `payroll_bank_holidays`
--
ALTER TABLE `payroll_bank_holidays`
  ADD PRIMARY KEY (`holiday_date`);

--
-- Indexes for table `payroll_batches`
--
ALTER TABLE `payroll_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_period` (`period_start`,`period_end`),
  ADD KEY `idx_run_at` (`run_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_devices`
--
ALTER TABLE `admin_devices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `kiosk_break_rules`
--
ALTER TABLE `kiosk_break_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_employees`
--
ALTER TABLE `kiosk_employees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_employee_categories`
--
ALTER TABLE `kiosk_employee_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `kiosk_employee_teams`
--
ALTER TABLE `kiosk_employee_teams`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_event_log`
--
ALTER TABLE `kiosk_event_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `kiosk_health_log`
--
ALTER TABLE `kiosk_health_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_punch_events`
--
ALTER TABLE `kiosk_punch_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_punch_photos`
--
ALTER TABLE `kiosk_punch_photos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_shifts`
--
ALTER TABLE `kiosk_shifts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_shift_changes`
--
ALTER TABLE `kiosk_shift_changes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payroll_batches`
--
ALTER TABLE `payroll_batches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
