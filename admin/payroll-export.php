<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

$batchId = (int)($_GET['batch_id'] ?? ($_GET['id'] ?? 0));
if ($batchId <= 0) {
  http_response_code(400);
  exit('Missing batch_id');
}

// Load batch
$st = $pdo->prepare('SELECT * FROM payroll_batches WHERE id=? LIMIT 1');
$st->execute([$batchId]);
$batch = $st->fetch(PDO::FETCH_ASSOC);
if (!$batch) {
  http_response_code(404);
  exit('Batch not found');
}

// Load snapshots joined with employee
$q = $pdo->prepare(
  "SELECT ps.*, e.employee_code, e.first_name, e.last_name
   FROM payroll_shift_snapshots ps
   INNER JOIN kiosk_employees e ON e.id = ps.employee_id
   WHERE ps.payroll_batch_id = ?
   ORDER BY e.employee_code ASC, ps.shift_id ASC"
);
$q->execute([$batchId]);
$rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

function hhmm(int $m): string {
  if ($m < 0) $m = 0;
  $h = intdiv($m, 60);
  $mm = $m % 60;
  return sprintf('%02d:%02d', $h, $mm);
}

$byEmp = []; // eid => totals
foreach ($rows as $r) {
  $eid = (int)$r['employee_id'];
  if (!isset($byEmp[$eid])) {
    $byEmp[$eid] = [
      'employee_code' => (string)$r['employee_code'],
      'name' => trim((string)$r['first_name'].' '.(string)$r['last_name']),
      'worked' => 0,
      'break_minus' => 0,
      'break_plus' => 0,
      'paid' => 0,
      'base' => 0,
      'weekend' => 0,
      'bh' => 0,
      'ot' => 0,
      'shifts' => 0,
    ];
  }

  // Prefer day_breakdown_json (contains break_minus/break_plus per day slice)
  $json = $r['day_breakdown_json'] ?? null;
  if ($json) {
    $decoded = json_decode((string)$json, true);
    if (is_array($decoded)) {
      foreach ($decoded as $drow) {
        $byEmp[$eid]['worked'] += (int)($drow['worked_minutes'] ?? 0);
        $byEmp[$eid]['break_minus'] += (int)($drow['break_deducted_minutes'] ?? 0);
        $byEmp[$eid]['break_plus'] += (int)($drow['break_added_minutes'] ?? 0);
      }
    } else {
      // Fallback if corrupt JSON
      $byEmp[$eid]['worked'] += (int)$r['worked_minutes'];
      $byEmp[$eid]['break_minus'] += (int)$r['break_minutes'];
    }
  } else {
    // Older batches: fallback
    $byEmp[$eid]['worked'] += (int)$r['worked_minutes'];
    $byEmp[$eid]['break_minus'] += (int)$r['break_minutes'];
  }

  $paid = (int)$r['paid_minutes'];
  $base = (int)$r['normal_minutes'];
  $weekend = (int)$r['weekend_minutes'];
  $bh = (int)$r['bank_holiday_minutes'];
  $ot = (int)$r['overtime_minutes'];

  $byEmp[$eid]['paid'] += $paid;
  $byEmp[$eid]['base'] += $base;
  $byEmp[$eid]['weekend'] += $weekend;
  $byEmp[$eid]['bh'] += $bh;
  $byEmp[$eid]['ot'] += $ot;
  $byEmp[$eid]['shifts']++;
}

// Output CSV
$filename = 'payroll_batch_'.$batchId.'_monthly_export.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out = fopen('php://output', 'w');
fputcsv($out, [
  'employee_code',
  'employee_name',
  'worked_raw_hhmm',
  'break_deducted_hhmm',
  'paid_break_added_hhmm',
  'paid_raw_hhmm',
  'base_raw_hhmm',
  'overtime_raw_hhmm',
  'bank_holiday_raw_hhmm',
  'weekend_raw_hhmm',
  'paid_rounded_hhmm',
  'base_rounded_hhmm',
  'overtime_rounded_hhmm',
  'bank_holiday_rounded_hhmm',
  'weekend_rounded_hhmm',
]);

// Keep consistent ordering by employee_code
uasort($byEmp, function($a, $b) {
  return strcmp((string)$a['employee_code'], (string)$b['employee_code']);
});

foreach ($byEmp as $t) {
  $paidRaw = (int)$t['paid'];
  $baseRaw = (int)$t['base'];
  $otRaw = (int)$t['ot'];
  $bhRaw = (int)$t['bh'];
  $weekendRaw = (int)$t['weekend'];

  // Rounded values: round each bucket + total, then derive base to ensure identity.
  $paidRounded = payroll_round_minutes($pdo, $paidRaw);
  $otRounded = payroll_round_minutes($pdo, $otRaw);
  $bhRounded = payroll_round_minutes($pdo, $bhRaw);
  $weekendRounded = payroll_round_minutes($pdo, $weekendRaw);

  $baseRounded = $paidRounded - $otRounded - $bhRounded - $weekendRounded;
  if ($baseRounded < 0) $baseRounded = 0;

  fputcsv($out, [
    (string)$t['employee_code'],
    (string)$t['name'],
    hhmm((int)$t['worked']),
    hhmm((int)$t['break_minus']),
    hhmm((int)$t['break_plus']),
    hhmm($paidRaw),
    hhmm($baseRaw),
    hhmm($otRaw),
    hhmm($bhRaw),
    hhmm($weekendRaw),
    hhmm($paidRounded),
    hhmm($baseRounded),
    hhmm($otRounded),
    hhmm($bhRounded),
    hhmm($weekendRounded),
  ]);
}

fclose($out);
exit;
