<?php
// seed_employees.php
// DEV ONLY — delete after running once

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$employees = [
    ['EMP-001', 'Moiz', '', '1234'],
    ['EMP-002', 'Ali', '', '2345'],
    ['EMP-003', 'Anisha', '', '3456'],
    ['EMP-004', 'Salika', '', '4567'],
    ['EMP-005', 'Sandhya', '', '5678'],
    ['EMP-006', 'Siraj Dev', '', '6789'],
    ['EMP-007', 'Shayam', '', '7890'],
    ['EMP-008', 'Siraj', '', '8901'],
];


$stmt = $pdo->prepare("
    INSERT INTO kiosk_employees
        (employee_code, first_name, last_name, pin_hash, is_active, created_at, updated_at)
    VALUES
        (?, ?, ?, ?, 1, NOW(), NOW())
");

$count = 0;

foreach ($employees as $e) {
    [$code, $first, $last, $pin] = $e;

    try {
        $stmt->execute([$code, $first, $last, $pin]);
        echo "Inserted: {$code} ({$first} {$last}) PIN={$pin}\n";
        $count++;
    } catch (Throwable $ex) {
        echo "Skipped {$code} (maybe exists already)\n";
    }
}

echo "\nDone. Inserted {$count} employees.\n";
