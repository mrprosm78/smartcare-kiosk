-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: sdb-67.hosting.stackcp.net
-- Generation Time: Jan 08, 2026 at 11:27 PM
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
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(10) UNSIGNED NOT NULL,
  `employee_code` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_event_log`
--

CREATE TABLE `kiosk_event_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
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
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kiosk_health_log`
--

CREATE TABLE `kiosk_health_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
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
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `punch_events`
--

CREATE TABLE `punch_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
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
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `clock_in_at` datetime NOT NULL,
  `clock_out_at` datetime DEFAULT NULL,
  `duration_minutes` int(10) UNSIGNED DEFAULT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_employee_code` (`employee_code`);

--
-- Indexes for table `kiosk_event_log`
--
ALTER TABLE `kiosk_event_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kelog_time` (`occurred_at`),
  ADD KEY `idx_kelog_kiosk_time` (`kiosk_code`,`occurred_at`),
  ADD KEY `idx_kelog_employee_time` (`employee_id`,`occurred_at`),
  ADD KEY `idx_kelog_type_time` (`event_type`,`occurred_at`);

--
-- Indexes for table `kiosk_health_log`
--
ALTER TABLE `kiosk_health_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_khlog_time` (`reported_at`),
  ADD KEY `idx_khlog_kiosk_time` (`kiosk_code`,`reported_at`);

--
-- Indexes for table `punch_events`
--
ALTER TABLE `punch_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_event_uuid` (`event_uuid`),
  ADD KEY `idx_punch_employee_time` (`employee_id`,`effective_time`),
  ADD KEY `idx_punch_shift` (`shift_id`),
  ADD KEY `idx_punch_kiosk_time` (`kiosk_code`,`effective_time`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_employee_open` (`employee_id`,`is_closed`),
  ADD KEY `idx_shift_clockin` (`employee_id`,`clock_in_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
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
-- AUTO_INCREMENT for table `punch_events`
--
ALTER TABLE `punch_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
