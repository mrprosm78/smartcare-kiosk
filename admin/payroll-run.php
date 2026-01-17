<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'run_payroll');

require_once __DIR__ . '/../src/payroll/PayrollCalculator.php';

function int_param(string $k, int $default): int {
  $v = $_GET[$k] ?? $_POST[$k] ?? null;
  if ($v === null) return $default;
  $i = (int)$v;
  return $i > 0 ? $i : $default;
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$defaultMonth = (int)$now->modify('-1 month')->format('n');
$defaultYear  = (int)$now->modify('-1 month')->format('Y');

$month = int_param('month', $defaultMonth);
$year  = int_param('year', $defaultYear);

$calc = new PayrollCalculator($pdo);
$result = $calc->calculateForMonth($year, $month, false);

$ran = false;
$batchId = null;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'run') {
  // Recalculate to ensure we run exactly what we preview
  $result = $calc->calculateForMonth($year, $month, false);

  $shiftIds = array_values(array_unique(array_map('intval', $result['used_shift_ids'] ?? [])));
  if (count($shiftIds) === 0) {
    $err = 'No payable approved shifts found to include in payroll run.';
  } else {
    try {
      $pdo->beginTransaction();

      $periodStart = (string)$result['period']['start_date'];
      $periodEnd   = (string)$result['period']['end_date'];

      // Create batch snapshot (reproducible export)
      $stmt = $pdo->prepare("INSERT INTO payroll_batches (period_start, period_end, run_by, status, notes, snapshot_json) VALUES (:ps,:pe,:rb,'FINAL',:n,:sj)");
      $snapshot = json_encode([
        'period' => $result['period'],
        'employees' => $result['employees'],
        'exceptions' => $result['exceptions'],
        'settings' => $result['settings'],
      ], JSON_UNESCAPED_SLASHES);

      $stmt->execute([
        ':ps' => $periodStart,
        ':pe' => $periodEnd,
        ':rb' => (int)($user['id'] ?? 0) ?: null,
        ':n'  => 'Monthly payroll run',
        ':sj' => $snapshot,
      ]);
      $batchId = (int)$pdo->lastInsertId();

      // Lock shifts
      $lockBy = (string)($user['username'] ?? '');
      $inPlaceholders = implode(',', array_fill(0, count($shiftIds), '?'));
      $sqlLock = "UPDATE kiosk_shifts SET payroll_locked_at = UTC_TIMESTAMP, payroll_locked_by = ?, payroll_batch_id = ? WHERE payroll_locked_at IS NULL AND id IN ($inPlaceholders)";
      $params = array_merge([$lockBy, (string)$batchId], $shiftIds);
      $stLock = $pdo->prepare($sqlLock);
      $stLock->execute($params);

      // Audit entries
      $stAudit = $pdo->prepare("INSERT INTO kiosk_shift_changes (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, created_at) VALUES (:sid,'payroll_lock',:uid,:un,:ur,:r,:n,UTC_TIMESTAMP)");
      foreach ($shiftIds as $sid) {
        $stAudit->execute([
          ':sid' => (int)$sid,
          ':uid' => (int)($user['id'] ?? 0) ?: null,
          ':un'  => (string)($user['username'] ?? ''),
          ':ur'  => (string)($user['role'] ?? ''),
          ':r'   => 'Included in payroll batch',
          ':n'   => 'Batch #' . $batchId,
        ]);
      }

      $pdo->commit();
      $ran = true;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = 'Failed to run payroll: ' . $e->getMessage();
    }
  }
}

$periodLabel = sprintf('%04d-%02d', $year, $month);
$title = 'Payroll Run - ' . $periodLabel;
admin_page_start($pdo, $title);

$employees = $result['employees'] ?? [];
$exceptions = $result['exceptions'] ?? [];

?>
<div class="max-w-6xl mx-auto p-6">
  <div class="flex items-center justify-between gap-3 flex-wrap">
    <h1 class="text-2xl font-semibold"><?= h($title) ?></h1>
    <a href="payroll.php" class="text-sm underline">Back to Payroll (Timesheet)</a>
  </div>

  <div class="mt-4 bg-slate-900/60 rounded-lg p-4 border border-slate-800">
    <form method="get" class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-sm text-slate-300">Year</label>
        <input name="year" value="<?= (int)$year ?>" class="mt-1 px-3 py-2 rounded bg-slate-950 border border-slate-700" />
      </div>
      <div>
        <label class="block text-sm text-slate-300">Month</label>
        <input name="month" value="<?= (int)$month ?>" class="mt-1 px-3 py-2 rounded bg-slate-950 border border-slate-700" />
      </div>
      <button class="px-4 py-2 rounded bg-sky-600 hover:bg-sky-500">Preview</button>
    </form>

    <div class="mt-3 text-sm text-slate-300">
      Period: <span class="text-white"><?= h((string)$result['period']['start_date']) ?></span>
      to <span class="text-white"><?= h((string)$result['period']['end_date']) ?></span>
      (approved & unlocked shifts only)
    </div>
  </div>

  <?php if ($err !== ''): ?>
    <div class="mt-4 p-3 rounded bg-red-900/40 border border-red-700 text-red-200"><?= h($err) ?></div>
  <?php endif; ?>

  <?php if ($ran && $batchId): ?>
    <div class="mt-4 p-3 rounded bg-emerald-900/40 border border-emerald-700 text-emerald-200">
      Payroll batch <strong>#<?= (int)$batchId ?></strong> created and shifts locked.
      <a class="underline" href="payroll-run-export.php?batch_id=<?= (int)$batchId ?>">Download export</a>
    </div>
  <?php endif; ?>

  <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-slate-900/60 rounded-lg p-4 border border-slate-800">
      <h2 class="font-semibold">Exceptions</h2>
      <div class="mt-2 text-sm text-slate-300 space-y-1">
        <div>Missing hourly rate: <span class="text-white"><?= count($exceptions['missing_rate'] ?? []) ?></span></div>
        <div>Missing contract hours/week: <span class="text-white"><?= count($exceptions['missing_contract_hours'] ?? []) ?></span></div>
        <div>Shifts missing clock-out: <span class="text-white"><?= count($exceptions['missing_clock_out'] ?? []) ?></span></div>
      </div>
      <p class="mt-3 text-xs text-slate-400">Payroll run will skip shifts without clock-out and still calculate totals for others.</p>
    </div>

    <div class="bg-slate-900/60 rounded-lg p-4 border border-slate-800">
      <h2 class="font-semibold">Run payroll</h2>
      <p class="mt-2 text-sm text-slate-300">Running payroll will create a batch snapshot and lock included shifts (cannot be edited without superadmin unlock).</p>
      <form method="post" class="mt-3">
        <input type="hidden" name="year" value="<?= (int)$year ?>" />
        <input type="hidden" name="month" value="<?= (int)$month ?>" />
        <input type="hidden" name="action" value="run" />
        <button class="px-4 py-2 rounded bg-emerald-600 hover:bg-emerald-500" onclick="return confirm('Run payroll and lock shifts for this month?');">Run Payroll & Lock Shifts</button>
      </form>
    </div>
  </div>

  <div class="mt-6 overflow-x-auto bg-slate-900/60 rounded-lg border border-slate-800">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-900">
        <tr class="text-left">
          <th class="p-3">Employee</th>
          <th class="p-3">Code</th>
          <th class="p-3">Regular (h)</th>
          <th class="p-3">OT (h)</th>
          <th class="p-3">Premium Extra</th>
          <th class="p-3">Gross</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$employees): ?>
        <tr><td class="p-3 text-slate-300" colspan="6">No approved unlocked shifts found for this month.</td></tr>
      <?php else: ?>
        <?php foreach ($employees as $emp): $t = $emp['totals']; ?>
          <tr class="border-t border-slate-800">
            <td class="p-3"><?= h((string)$emp['name']) ?></td>
            <td class="p-3 text-slate-300"><?= h((string)$emp['employee_code']) ?></td>
            <td class="p-3"><?= h((string)$t['regular_hours']) ?></td>
            <td class="p-3"><?= h((string)$t['overtime_hours']) ?></td>
            <td class="p-3"><?= h(number_format((float)$t['premium_extra'], 2)) ?></td>
            <td class="p-3 font-semibold"><?= h(number_format((float)$t['gross_pay'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<?php
admin_page_end();
