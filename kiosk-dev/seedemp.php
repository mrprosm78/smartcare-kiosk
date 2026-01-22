<?php
// seed_employees.php
// DEV ONLY — delete after running once

declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$employees = [
    ['EMP-001', 'Moiz',        '1234'],
    ['EMP-002', 'Ali',         '2345'],
    ['EMP-003', 'Anisha',      '3456'],
    ['EMP-004', 'Salika',      '4567'],
    ['EMP-005', 'Sandhya',     '5678'],
    ['EMP-006', 'Siraj Dev',   '6789'],
    ['EMP-007', 'Shayam',      '7890'],
    ['EMP-008', 'Siraj',       '8901'],
];

$stmt = $pdo->prepare("
    INSERT INTO kiosk_employees
        (
            employee_code,
            nickname,
            pin_hash,
            pin_updated_at,
            is_active,
            created_at,
            updated_at
        )
    VALUES
        (?, ?, ?, NOW(), 1, NOW(), NOW())
");

$count = 0;

foreach ($employees as $e) {
    [$code, $nickname, $pin] = $e;

    $pinHash = password_hash($pin, PASSWORD_DEFAULT);

    try {
        $stmt->execute([
            $code,
            $nickname,
            $pinHash,
        ]);

        echo "Inserted: {$code} ({$nickname}) PIN={$pin}\n";
        $count++;

    } catch (Throwable $ex) {
        echo "Skipped {$code} (already exists?)\n";
    }
}

echo "\nDone. Inserted {$count} employees.\n";
