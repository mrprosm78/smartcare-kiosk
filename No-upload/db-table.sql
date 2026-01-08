/* ==========================================================
   SmartCare Kiosk Time Clock — SQL Installer (tables only)
   - Does NOT create the database
   - Run inside your chosen DB (e.g. via phpMyAdmin)
   ========================================================== */

SET NAMES utf8mb4;

/* =========================
   1) settings
   ========================= */
CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* =========================
   2) employees
   ========================= */
CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_code` VARCHAR(50) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `pin_hash` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_employee_code` (`employee_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* =========================
   3) shifts
   ========================= */
CREATE TABLE IF NOT EXISTS `shifts` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT(10) UNSIGNED NOT NULL,
  `clock_in_at` DATETIME NOT NULL,
  `clock_out_at` DATETIME NULL,
  `duration_minutes` INT(10) UNSIGNED NULL,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shift_employee_open` (`employee_id`, `is_closed`),
  KEY `idx_shift_clockin` (`employee_id`, `clock_in_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* (Optional but recommended) add foreign key constraints
   Only enable if you are sure you want FK enforcement.
   Comment out if you prefer loose coupling.

ALTER TABLE `shifts`
  ADD CONSTRAINT `fk_shifts_employee`
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;
*/

/* =========================
   4) punch_events
   ========================= */
CREATE TABLE IF NOT EXISTS `punch_events` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

  `event_uuid` CHAR(36) NOT NULL,
  `employee_id` INT(10) UNSIGNED NOT NULL,
  `action` ENUM('IN','OUT') NOT NULL,

  `device_time` DATETIME NOT NULL,
  `received_at` DATETIME NOT NULL,
  `effective_time` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  `result_status` VARCHAR(20) NOT NULL DEFAULT 'processed',
  `error_code` VARCHAR(50) NULL,

  `shift_id` BIGINT(20) NULL,
  `kiosk_code` VARCHAR(50) NULL,
  `device_token_hash` CHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_event_uuid` (`event_uuid`),

  KEY `idx_punch_employee_time` (`employee_id`, `effective_time`),
  KEY `idx_punch_shift` (`shift_id`),
  KEY `idx_punch_kiosk_time` (`kiosk_code`, `effective_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* (Optional but recommended) add foreign key constraints
   Comment out if you prefer to allow logs even if employee/shift removed.

ALTER TABLE `punch_events`
  ADD CONSTRAINT `fk_punch_employee`
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `punch_events`
  ADD CONSTRAINT `fk_punch_shift`
  FOREIGN KEY (`shift_id`) REFERENCES `shifts`(`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;
*/

/* =========================
   5) Default settings (safe to re-run)
   ========================= */
INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES
  ('kiosk_code', 'KIOSK-1', NOW()),
  ('is_paired', '0', NOW()),
  ('paired_device_token', '', NOW()),
  ('pairing_version', '1', NOW()),

  -- ✅ plain text pairing code (as requested)
  ('pairing_code', '456123', NOW()),

  -- auth + rules
  ('pin_length', '4', NOW()),
  ('allow_plain_pin', '1', NOW()),
  ('min_seconds_between_punches', '20', NOW()),
  ('max_shift_minutes', '960', NOW()),
  ('debug_mode', '0', NOW()),

  /* =========================
     NEW: Sync + ping tuning
     (Lets you change behaviour without editing kiosk.html)
     ========================= */
  ('ping_interval_ms', '60000', NOW()),
  ('sync_interval_ms', '30000', NOW()),
  ('sync_cooldown_ms', '8000', NOW()),
  ('sync_batch_size', '20', NOW()),
  ('max_sync_attempts', '10', NOW()),
  ('sync_backoff_base_ms', '2000', NOW()),
  ('sync_backoff_cap_ms', '300000', NOW()),

  /* =========================
     NEW: Optional UI behaviour
     ========================= */
  ('ui_thank_ms', '1500', NOW()),
  ('ui_show_clock', '1', NOW())
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = VALUES(`updated_at`);
