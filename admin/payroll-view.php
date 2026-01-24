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

// Totals per employee
$totals = [];
foreach ($rows as $r) {
  $eid = (int)$r['employee_id'];
  if (!isset($totals[$eid])) {
    $totals[$eid] = [
      'employee_code' => (string)$r['employee_code'],
      'name' => trim((string)$r['first_name'].' '.(string)$r['last_name']),
      'worked' => 0,
      'break' => 0,
      'paid' => 0,
      'normal' => 0,
      'weekend' => 0,
      'bh' => 0,
      'ot' => 0,
      'shifts' => 0,
    ];
  }
  $totals[$eid]['worked'] += (int)$r['worked_minutes'];
  $totals[$eid]['break'] += (int)$r['break_minutes'];
  $totals[$eid]['paid'] += (int)$r['paid_minutes'];
  $totals[$eid]['normal'] += (int)$r['normal_minutes'];
  $totals[$eid]['weekend'] += (int)$r['weekend_minutes'];
  $totals[$eid]['bh'] += (int)$r['bank_holiday_minutes'];
  $totals[$eid]['ot'] += (int)$r['overtime_minutes'];
  $totals[$eid]['shifts']++;
}

$title = 'Payroll Batch #' . $batchId;
$active = admin_url('payroll-runs.php');
admin_page_start($pdo, $title);
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="w-full">
      <div class="flex flex-col lg:flex-row gap-5">

        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1 min-w-0">

          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-2xl font-semibold">Payroll Batch #<?= (int)$batchId ?></h1>
              <div class="mt-1 text-sm text-white/70">Period: <b><?= h((string)$batch['period_start']) ?></b> to <b><?= h((string)$batch['period_end']) ?></b> · Status: <b><?= h((string)$batch['status']) ?></b></div>
            </div>
            <div class="flex gap-2">
              <a href="<?= h(admin_url('payroll-runs.php')) ?>" class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/15">Back</a>
              <a href="<?= h(admin_url('shifts.php')) ?>" class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/15">Review shifts</a>
            </div>
          </div>

          <div class="mt-6 overflow-hidden rounded-3xl border border-white/10">
            <table class="w-full text-sm">
              <thead class="bg-white/5 text-white/70">
                <tr>
                  <th class="text-left px-4 py-3">Employee</th>
                  <th class="text-left px-4 py-3">Shifts</th>
                  <th class="text-left px-4 py-3">Paid</th>
                  <th class="text-left px-4 py-3">Normal</th>
                  <th class="text-left px-4 py-3">Weekend</th>
                  <th class="text-left px-4 py-3">Bank Holiday</th>
                  <th class="text-left px-4 py-3">Overtime</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$totals): ?>
                  <tr><td colspan="7" class="px-4 py-4 text-white/60">No snapshots in this batch.</td></tr>
                <?php endif; ?>

                <?php foreach ($totals as $t): ?>
                  <tr class="border-t border-white/10">
                    <td class="px-4 py-3">
                      <div class="font-semibold"><?= h((string)$t['employee_code']) ?></div>
                      <div class="text-xs text-white/60"><?= h((string)$t['name']) ?></div>
                    </td>
                    <td class="px-4 py-3"><?= (int)$t['shifts'] ?></td>
                    <td class="px-4 py-3 font-semibold"><?= hhmm((int)$t['paid']) ?></td>
                    <td class="px-4 py-3"><?= hhmm((int)$t['normal']) ?></td>
                    <td class="px-4 py-3"><?= hhmm((int)$t['weekend']) ?></td>
                    <td class="px-4 py-3"><?= hhmm((int)$t['bh']) ?></td>
                    <td class="px-4 py-3"><?= hhmm((int)$t['ot']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="mt-6">
            <h2 class="text-lg font-semibold">Shift snapshots</h2>
            <div class="mt-3 overflow-hidden rounded-3xl border border-white/10">
              <table class="w-full text-sm">
                <thead class="bg-white/5 text-white/70">
                  <tr>
                    <th class="text-left px-4 py-3">Shift</th>
                    <th class="text-left px-4 py-3">Employee</th>
                    <th class="text-left px-4 py-3">Paid</th>
                    <th class="text-left px-4 py-3">Normal</th>
                    <th class="text-left px-4 py-3">Weekend</th>
                    <th class="text-left px-4 py-3">BH</th>
                    <th class="text-left px-4 py-3">OT</th>
                    <th class="text-left px-4 py-3">View shift</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr class="border-t border-white/10">
                      <td class="px-4 py-3 font-semibold">#<?= (int)$r['shift_id'] ?></td>
                      <td class="px-4 py-3"><?= h((string)$r['employee_code']) ?></td>
                      <td class="px-4 py-3"><?= hhmm((int)$r['paid_minutes']) ?></td>
                      <td class="px-4 py-3"><?= hhmm((int)$r['normal_minutes']) ?></td>
                      <td class="px-4 py-3"><?= hhmm((int)$r['weekend_minutes']) ?></td>
                      <td class="px-4 py-3"><?= hhmm((int)$r['bank_holiday_minutes']) ?></td>
                      <td class="px-4 py-3"><?= hhmm((int)$r['overtime_minutes']) ?></td>
                      <td class="px-4 py-3"><a class="underline" href="<?= h(admin_url('shift-view.php?id='.(int)$r['shift_id'])) ?>">Open</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
