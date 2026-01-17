<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'export_payroll');

$batchId = (int)($_GET['batch_id'] ?? 0);
if ($batchId <= 0) {
  http_response_code(400);
  exit('Missing batch_id');
}

$stmt = $pdo->prepare('SELECT * FROM payroll_batches WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $batchId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$batch) {
  http_response_code(404);
  exit('Batch not found');
}

$snapshotJson = (string)($batch['snapshot_json'] ?? '');
$snapshot = json_decode($snapshotJson, true);
if (!is_array($snapshot)) {
  http_response_code(500);
  exit('Batch snapshot is missing or invalid');
}

$period = $snapshot['period'] ?? [];
$employees = $snapshot['employees'] ?? [];

$filename = sprintf('payroll-batch-%d_%s_to_%s.csv', $batchId, $batch['period_start'], $batch['period_end']);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

fputcsv($out, [
  'Batch ID',
  'Period Start',
  'Period End',
  'Employee ID',
  'Employee Code',
  'Name',
  'Hourly Rate',
  'Contract Hours/Week',
  'Regular Hours',
  'Overtime Hours',
  'Premium Extra',
  'Regular Amount',
  'Overtime Amount',
  'Gross Pay',
]);

foreach ($employees as $emp) {
  $t = $emp['totals'] ?? [];
  fputcsv($out, [
    $batchId,
    $batch['period_start'],
    $batch['period_end'],
    $emp['employee_id'] ?? '',
    $emp['employee_code'] ?? '',
    $emp['name'] ?? '',
    $emp['hourly_rate'] ?? '',
    $emp['contract_hours_per_week'] ?? '',
    $t['regular_hours'] ?? '',
    $t['overtime_hours'] ?? '',
    $t['premium_extra'] ?? '',
    $t['regular_amount'] ?? '',
    $t['overtime_amount'] ?? '',
    $t['gross_pay'] ?? '',
  ]);
}

fclose($out);
exit;
