<?php
declare(strict_types=1);

/**
 * SmartCare Kiosk — DB Installer (run once, then delete)
 * - Creates/updates: employees, shifts, punch_events, settings
 */

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

function q(PDO $pdo, string $sql): void {
    $pdo->exec($sql);
    echo "OK: " . preg_replace("/\s+/", " ", trim($sql)) . "\n";
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$col]);
    return (bool)$stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
    $stmt->execute([$index]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    // Ensure utf8mb4 by default
    q($pdo, "SET NAMES utf8mb4");

    /**
     * SETTINGS
     */
    if (!table_exists($pdo, 'settings')) {
        q($pdo, "
            CREATE TABLE settings (
              `key` VARCHAR(100) NOT NULL,
              `value` TEXT NOT NULL,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } else {
        // Convert collation if needed (safe)
        q($pdo, "ALTER TABLE settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        if (column_exists($pdo, 'settings', 'updated_at')) {
            q($pdo, "ALTER TABLE settings MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
    }

    /**
     * EMPLOYEES
     */
    if (!table_exists($pdo, 'employees')) {
        q($pdo, "
            CREATE TABLE employees (
              id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              employee_code VARCHAR(50) NOT NULL,
              first_name VARCHAR(100) NOT NULL,
              last_name VARCHAR(100) NOT NULL,
              pin_hash VARCHAR(255) NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY ux_employee_code (employee_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } else {
        // Ensure timestamp defaults
        if (column_exists($pdo, 'employees', 'created_at')) {
            q($pdo, "ALTER TABLE employees MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
        if (column_exists($pdo, 'employees', 'updated_at')) {
            q($pdo, "ALTER TABLE employees MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        if (!index_exists($pdo, 'employees', 'ux_employee_code')) {
            q($pdo, "CREATE UNIQUE INDEX ux_employee_code ON employees(employee_code)");
        }
    }

    /**
     * SHIFTS
     */
    if (!table_exists($pdo, 'shifts')) {
        q($pdo, "
            CREATE TABLE shifts (
              id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              employee_id INT(10) UNSIGNED NOT NULL,
              clock_in_at DATETIME NOT NULL,
              clock_out_at DATETIME NULL,
              duration_minutes INT(10) UNSIGNED NULL,
              is_closed TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_shift_employee_open (employee_id, is_closed),
              KEY idx_shift_clockin (employee_id, clock_in_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } else {
        if (column_exists($pdo, 'shifts', 'created_at')) {
            q($pdo, "ALTER TABLE shifts MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
        if (column_exists($pdo, 'shifts', 'updated_at')) {
            q($pdo, "ALTER TABLE shifts MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        if (!index_exists($pdo, 'shifts', 'idx_shift_employee_open')) {
            q($pdo, "CREATE INDEX idx_shift_employee_open ON shifts(employee_id, is_closed)");
        }
        if (!index_exists($pdo, 'shifts', 'idx_shift_clockin')) {
            q($pdo, "CREATE INDEX idx_shift_clockin ON shifts(employee_id, clock_in_at)");
        }
    }

    /**
     * PUNCH EVENTS
     */
    if (!table_exists($pdo, 'punch_events')) {
        q($pdo, "
            CREATE TABLE punch_events (
              id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              event_uuid CHAR(36) NOT NULL,
              employee_id INT(10) UNSIGNED NOT NULL,
              action ENUM('IN','OUT') NOT NULL,
              device_time DATETIME NOT NULL,
              received_at DATETIME NOT NULL,
              effective_time DATETIME NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

              result_status VARCHAR(20) NOT NULL DEFAULT 'processed',
              error_code VARCHAR(50) NULL,
              shift_id BIGINT(20) NULL,
              kiosk_code VARCHAR(50) NULL,
              device_token_hash CHAR(64) NULL,
              ip_address VARCHAR(45) NULL,
              user_agent VARCHAR(255) NULL,

              PRIMARY KEY (id),
              UNIQUE KEY ux_event_uuid (event_uuid),
              KEY idx_punch_employee_time (employee_id, effective_time),
              KEY idx_punch_shift (shift_id),
              KEY idx_punch_kiosk_time (kiosk_code, effective_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } else {
        // Ensure new columns exist (upgrade path)
        $addCols = [
            "result_status VARCHAR(20) NOT NULL DEFAULT 'processed'",
            "error_code VARCHAR(50) NULL",
            "shift_id BIGINT(20) NULL",
            "kiosk_code VARCHAR(50) NULL",
            "device_token_hash CHAR(64) NULL",
            "ip_address VARCHAR(45) NULL",
            "user_agent VARCHAR(255) NULL",
        ];
        foreach ($addCols as $def) {
            $col = trim(strtok($def, " "));
            if (!column_exists($pdo, 'punch_events', $col)) {
                q($pdo, "ALTER TABLE punch_events ADD COLUMN $def");
            }
        }

        // Unique uuid
        if (!index_exists($pdo, 'punch_events', 'ux_event_uuid')) {
            q($pdo, "ALTER TABLE punch_events ADD UNIQUE KEY ux_event_uuid (event_uuid)");
        }

        // Indexes
        if (!index_exists($pdo, 'punch_events', 'idx_punch_employee_time')) {
            q($pdo, "CREATE INDEX idx_punch_employee_time ON punch_events(employee_id, effective_time)");
        }
        if (!index_exists($pdo, 'punch_events', 'idx_punch_shift')) {
            q($pdo, "CREATE INDEX idx_punch_shift ON punch_events(shift_id)");
        }
        if (!index_exists($pdo, 'punch_events', 'idx_punch_kiosk_time')) {
            q($pdo, "CREATE INDEX idx_punch_kiosk_time ON punch_events(kiosk_code, effective_time)");
        }
    }

    /**
     * DEFAULT SETTINGS (insert if missing)
     */
    $defaults = [
        'kiosk_code' => 'KIOSK-9F3A6C2D8A71',
        'is_paired' => '0',
        'paired_device_token' => '',
        'pairing_version' => '1',
        'pin_length' => '4',
        'allow_plain_pin' => '1',
        'min_seconds_between_punches' => '20',
        'max_shift_minutes' => '960',
        'debug_mode' => '0',
    ];

    foreach ($defaults as $k => $v) {
        $stmt = $pdo->prepare("SELECT `key` FROM settings WHERE `key`=? LIMIT 1");
        $stmt->execute([$k]);
        if (!$stmt->fetchColumn()) {
            $ins = $pdo->prepare("INSERT INTO settings (`key`,`value`,`updated_at`) VALUES (?,?,NOW())");
            $ins->execute([$k, $v]);
            echo "OK: inserted default setting {$k}\n";
        }
    }

    echo "\nDONE ✅\nDelete install.php now.\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "INSTALL FAILED ❌\n";
    echo $e->getMessage() . "\n";
}
