<?php
// seed_employees.php
// DEV ONLY — delete after running once

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$employees = [
    ['EMP-001', 'Sarah',  'Brown',  '1234'],
    ['EMP-002', 'John',   'Ahmed',  '2345'],
    ['EMP-003', 'Maria',  'Patel',  '3456'],
    ['EMP-004', 'David',  'Wilson', '4567'],
    ['EMP-005', 'Aisha',  'Khan',   '5678'],
    ['EMP-006', 'Michael','Smith',  '6789'],
    ['EMP-007', 'Fatima', 'Ali',    '7890'],
    ['EMP-008', 'James',  'Taylor', '8901'],
    ['EMP-009', 'Lucy',   'Green',  '9012'],
    ['EMP-010', 'Omar',   'Hussain', '0123'],
];

$stmt = $pdo->prepare("
    INSERT INTO employees
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
