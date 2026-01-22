<?php
// seed-emp-stowpark-from-csv.php
// DEV ONLY — run once, then DELETE

declare(strict_types=1);

require __DIR__ . '/db.php';

$csvFile = __DIR__ . '/stowpark-employee-pins.csv';

if (!file_exists($csvFile)) {
  exit("CSV file not found: {$csvFile}\n");
}

$fh = fopen($csvFile, 'r');
$header = fgetcsv($fh); // skip header

$stmt = $pdo->prepare("
  INSERT INTO kiosk_employees
    (
      employee_code,
      nickname,
      pin_hash,
      pin_fingerprint,
      pin_updated_at,
      is_active,
      created_at,
      updated_at
    )
  VALUES
    (?, ?, ?, ?, NOW(), 1, NOW(), NOW())
");

$count = 0;

function pin_fingerprint(string $pin): string {
  return hash('sha256', $pin);
}

echo "Seeding employees from CSV...\n";

while (($row = fgetcsv($fh)) !== false) {
  [$code, $name, $pin] = $row;

  $pinHash = password_hash($pin, PASSWORD_DEFAULT);
  $fp = pin_fingerprint($pin);

  try {
    $stmt->execute([$code, $name, $pinHash, $fp]);
    echo "Inserted {$code} ({$name})\n";
    $count++;
  } catch (Throwable $e) {
    echo "Skipped {$code} ({$name}) — already exists?\n";
  }
}

fclose($fh);

echo "\nDone. Inserted {$count} employees.\n";
echo "IMPORTANT: Delete seed file + CSV after use.\n";
