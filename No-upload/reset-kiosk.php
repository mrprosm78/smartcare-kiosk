<?php
declare(strict_types=1);

/**
 * SmartCare Kiosk — FULL RESET SCRIPT
 * ⚠️ DELETES ALL DATA
 * - Clears employees, shifts, punch_events
 * - Resets pairing + settings
 * - Keeps table structure intact
 *
 * RUN ONCE, THEN DELETE
 */

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo->beginTransaction();

    // Disable FK checks just in case
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Clear data tables
    $pdo->exec("TRUNCATE TABLE punch_events");
    echo "✔ punch_events cleared\n";

    $pdo->exec("TRUNCATE TABLE shifts");
    echo "✔ shifts cleared\n";

    $pdo->exec("TRUNCATE TABLE employees");
    echo "✔ employees cleared\n";

    // Reset settings (delete all, then reinsert defaults)
    $pdo->exec("DELETE FROM settings");

    $stmt = $pdo->prepare("
        INSERT INTO settings (`key`, `value`, `updated_at`) VALUES
          ('kiosk_code', 'KIOSK-1', NOW()),
          ('is_paired', '0', NOW()),
          ('paired_device_token', '', NOW()),
          ('pairing_version', '1', NOW()),
          ('pairing_code', '456123', NOW()),
          ('pin_length', '4', NOW()),
          ('allow_plain_pin', '1', NOW()),
          ('min_seconds_between_punches', '20', NOW()),
          ('max_shift_minutes', '960', NOW()),
          ('debug_mode', '0', NOW())
    ");
    $stmt->execute();

    echo "✔ settings reset\n";

    // Re-enable FK checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    $pdo->commit();

    echo "\nDONE ✅\n";
    echo "All tables cleaned and kiosk reset.\n";
    echo "DELETE THIS FILE NOW.\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "RESET FAILED ❌\n";
    echo $e->getMessage() . "\n";
}
