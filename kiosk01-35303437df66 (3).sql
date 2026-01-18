-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: sdb-67.hosting.stackcp.net
-- Generation Time: Jan 17, 2026 at 11:15 PM
-- Server version: 10.6.18-MariaDB-log
-- PHP Version: 8.3.29

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
  `is_agency` tinyint(1) NOT NULL DEFAULT 0,
  `agency_label` varchar(100) DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
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

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_employee_pay_profiles`
--

CREATE TABLE `kiosk_employee_pay_profiles` (
  `employee_id` int(10) UNSIGNED NOT NULL,
  `contract_hours_per_week` decimal(6,2) DEFAULT NULL,
  `break_minutes_default` int(11) DEFAULT NULL,
  `break_is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `min_hours_for_break` decimal(5,2) DEFAULT NULL,
  `holiday_entitled` tinyint(1) NOT NULL DEFAULT 0,
  `bank_holiday_entitled` tinyint(1) NOT NULL DEFAULT 0,
  `bank_holiday_multiplier` decimal(5,2) DEFAULT NULL,
  `day_rate` decimal(10,2) DEFAULT NULL,
  `night_rate` decimal(10,2) DEFAULT NULL,
  `night_start` time DEFAULT NULL,
  `night_end` time DEFAULT NULL,
  `rules_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rules_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `break_minutes_day` int(11) DEFAULT NULL,
  `break_minutes_night` int(11) DEFAULT NULL
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
  `id` int(10) UNSIGNED NOT NULL,
  `event_uuid` varchar(64) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `mime_type` varchar(50) NOT NULL DEFAULT 'image/jpeg',
  `byte_size` int(10) UNSIGNED DEFAULT NULL,
  `action` enum('IN','OUT') NOT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_shifts`
--

CREATE TABLE `kiosk_shifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED DEFAULT NULL,
  `clock_in_at` datetime NOT NULL,
  `clock_out_at` datetime DEFAULT NULL,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `training_minutes` int(11) DEFAULT NULL,
  `training_note` varchar(255) DEFAULT NULL
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
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_category` (`category_id`),
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
  ADD UNIQUE KEY `uq_event_uuid_action` (`event_uuid`,`action`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kiosk_event_log`
--
ALTER TABLE `kiosk_event_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
