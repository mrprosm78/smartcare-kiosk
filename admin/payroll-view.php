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

$selectedEmployeeId = (int)($_GET['employee_id'] ?? 0);

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

// Default selected employee
if ($selectedEmployeeId <= 0 && $totals) {
  $first = array_key_first($totals);
  $selectedEmployeeId = (int)$first;
}

// Load pay profile for selected employee (for display)
$profile = null;
if ($selectedEmployeeId > 0) {
  $profile = payroll_employee_profile($pdo, $selectedEmployeeId);
}

// Build day/ week breakdown for selected employee using stored JSON.
$dayTotals = []; // date => sums
$dayShiftRows = []; // date => list of shifts
foreach ($rows as $r) {
  if ((int)$r['employee_id'] !== $selectedEmployeeId) continue;
  $json = $r['day_breakdown_json'] ?? null;
  if (!$json) continue;
  $decoded = json_decode((string)$json, true);
  if (!is_array($decoded)) continue;
  foreach ($decoded as $drow) {
    $date = (string)($drow['date'] ?? '');
    if ($date === '') continue;
    if (!isset($dayTotals[$date])) {
      $dayTotals[$date] = [
        'worked' => 0, 'break_minus' => 0, 'break_plus' => 0, 'paid' => 0,
        'normal' => 0, 'weekend' => 0, 'bh' => 0, 'ot' => 0,
        'shift_count' => 0,
      ];
    }
    $dayTotals[$date]['worked'] += (int)($drow['worked_minutes'] ?? 0);
    $dayTotals[$date]['break_minus'] += (int)($drow['break_deducted_minutes'] ?? 0);
    $dayTotals[$date]['break_plus'] += (int)($drow['break_added_minutes'] ?? 0);
    $dayTotals[$date]['paid'] += (int)($drow['paid_minutes'] ?? 0);
    $dayTotals[$date]['normal'] += (int)($drow['normal_minutes'] ?? 0);
    $dayTotals[$date]['weekend'] += (int)($drow['weekend_minutes'] ?? 0);
    $dayTotals[$date]['bh'] += (int)($drow['bank_holiday_minutes'] ?? 0);
    $dayTotals[$date]['ot'] += (int)($drow['overtime_minutes'] ?? 0);

    $dayShiftRows[$date] = $dayShiftRows[$date] ?? [];
    $dayShiftRows[$date][] = [
      'shift_id' => (int)$r['shift_id'],
      'worked' => (int)($drow['worked_minutes'] ?? 0),
      'break_minus' => (int)($drow['break_deducted_minutes'] ?? 0),
      'break_plus' => (int)($drow['break_added_minutes'] ?? 0),
      'paid' => (int)($drow['paid_minutes'] ?? 0),
      'normal' => (int)($drow['normal_minutes'] ?? 0),
      'weekend' => (int)($drow['weekend_minutes'] ?? 0),
      'bh' => (int)($drow['bank_holiday_minutes'] ?? 0),
      'ot' => (int)($drow['overtime_minutes'] ?? 0),
    ];
  }
}

// Normalise shift counts per day
foreach ($dayShiftRows as $d => $list) {
  $dayTotals[$d]['shift_count'] = count(array_unique(array_map(fn($x) => (int)$x['shift_id'], $list)));
}

// Group days by payroll week
$weeks = []; // week_key => ['start_local'=>DateTimeImmutable,'end_local_ex'=>..,'days'=>[date=>totals]]
if ($dayTotals) {
  $tz = new DateTimeZone(payroll_timezone($pdo));
  $utc = new DateTimeZone('UTC');
  $dates = array_keys($dayTotals);
  sort($dates);
  foreach ($dates as $date) {
    $dtLocal = new DateTimeImmutable($date.' 12:00:00', $tz);
    $win = payroll_week_window($pdo, $dtLocal->setTimezone($utc));
    $wk = $win['start_local']->format('Y-m-d');
    if (!isset($weeks[$wk])) {
      $weeks[$wk] = [
        'start_local' => $win['start_local'],
        'end_local_ex' => $win['end_local_ex'],
        'days' => [],
      ];
    }
    $weeks[$wk]['days'][$date] = $dayTotals[$date];
  }
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
              <div class="mt-1 text-sm text-slate-600">Period: <b><?= h((string)$batch['period_start']) ?></b> to <b><?= h((string)$batch['period_end']) ?></b> · Status: <b><?= h((string)$batch['status']) ?></b></div>
            </div>
            <div class="flex gap-2">
              <a href="<?= h(admin_url('payroll-runs.php')) ?>" class="px-4 py-2 rounded-xl bg-slate-50 hover:bg-slate-100">Back</a>
              <a href="<?= h(admin_url('shifts.php')) ?>" class="px-4 py-2 rounded-xl bg-slate-50 hover:bg-slate-100">Review shifts</a>
              <a href="<?= h(admin_url('payroll-export.php?batch_id='.(int)$batchId)) ?>" class="px-4 py-2 rounded-xl bg-emerald-500/20 hover:bg-emerald-500/30 border border-emerald-500/30">Export CSV (Raw + Rounded)</a>
            </div>
          </div>

          <div class="mt-6 overflow-hidden rounded-3xl border border-slate-200">
            <table class="w-full text-sm">
              <thead class="bg-white text-slate-600">
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
                  <tr><td colspan="7" class="px-4 py-4 text-slate-500">No snapshots in this batch.</td></tr>
                <?php endif; ?>

                <?php foreach ($totals as $t): ?>
                  <tr class="border-t border-slate-200">
                    <td class="px-4 py-3">
                      <div class="font-semibold"><?= h((string)$t['employee_code']) ?></div>
                      <div class="text-xs text-slate-500"><?= h((string)$t['name']) ?></div>
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
            <div class="flex items-center justify-between gap-4 flex-wrap">
              <h2 class="text-lg font-semibold">Employee breakdown (weeks → days)</h2>
              <form method="get" class="flex items-center gap-2">
                <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>" />
                <label class="text-sm text-slate-600">Employee</label>
                <select name="employee_id" class="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200">
                  <?php foreach ($totals as $eid => $t): ?>
                    <option value="<?= (int)$eid ?>" <?= ((int)$eid === (int)$selectedEmployeeId) ? 'selected' : '' ?>><?= h((string)$t['employee_code'].' — '.(string)$t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="px-4 py-2 rounded-xl bg-slate-50 hover:bg-slate-100">View</button>
              </form>
            </div>

            <?php if ($selectedEmployeeId <= 0): ?>
              <div class="mt-3 text-slate-500 text-sm">Select an employee to view the week/day calculation.</div>
            <?php else: ?>
              <?php $tSel = $totals[$selectedEmployeeId] ?? null; ?>
              <?php if ($tSel): ?>
                <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-4">
                  <div class="rounded-3xl border border-slate-200 p-4">
                    <div class="text-sm text-slate-600">Calculation summary (month)</div>
                    <div class="mt-3 space-y-1 text-sm">
                      <div class="flex justify-between"><span>Worked (raw)</span><b><?= hhmm((int)$tSel['worked']) ?></b></div>
                      <div class="flex justify-between"><span>Break deducted</span><b>-<?= hhmm((int)$tSel['break']) ?></b></div>
                      <?php $breakPaid = (bool)($profile['break_is_paid'] ?? false); ?>
                      <div class="flex justify-between"><span>Paid break added back</span><b><?= $breakPaid ? '+'.hhmm((int)$tSel['break']) : '+00:00' ?></b></div>
                      <div class="pt-2 border-t border-slate-200 flex justify-between"><span>Paid (after breaks)</span><b><?= hhmm((int)$tSel['paid']) ?></b></div>
                      <div class="flex justify-between"><span>Base</span><b><?= hhmm((int)$tSel['normal']) ?></b></div>
                      <div class="flex justify-between"><span>Weekend</span><b><?= hhmm((int)$tSel['weekend']) ?></b></div>
                      <div class="flex justify-between"><span>Bank Holiday</span><b><?= hhmm((int)$tSel['bh']) ?></b></div>
                      <div class="flex justify-between"><span>Overtime</span><b><?= hhmm((int)$tSel['ot']) ?></b></div>
                      <div class="pt-2 text-xs text-slate-500">
                        No stacking: overtime replaces BH/weekend when contract weekly hours are exceeded.
                      </div>
                    </div>
                  </div>

                  <div class="rounded-3xl border border-slate-200 p-4">
                    <div class="text-sm text-slate-600">Contract (for overtime)</div>
                    <div class="mt-3 text-sm space-y-1">
                      <div class="flex justify-between"><span>Contract hours / week</span><b><?= h((string)($profile['contract_hours_per_week'] ?? 0)) ?></b></div>
                      <div class="flex justify-between"><span>Breaks</span><b><?= $breakPaid ? 'Paid' : 'Unpaid' ?></b></div>
                      <div class="flex justify-between"><span>Payroll timezone</span><b><?= h(payroll_timezone($pdo)) ?></b></div>
                      <div class="flex justify-between"><span>Week starts on</span><b><?= h(payroll_week_starts_on($pdo)) ?></b></div>
                    </div>
                  </div>
                </div>

                <div class="mt-4 space-y-3">
                  <?php if (!$weeks): ?>
                    <div class="text-slate-500 text-sm">No day breakdown data found for this employee in this batch. (Tip: rerun payroll for this month to populate day breakdown.)</div>
                  <?php endif; ?>

                  <?php foreach ($weeks as $wk => $w): ?>
                    <?php
                      $startL = $w['start_local'];
                      $endLEx = $w['end_local_ex'];
                      $endL = $endLEx->modify('-1 day');
                      $weekPaid = 0; $weekWorked = 0; $weekBm = 0; $weekBp = 0; $weekN=0; $weekW=0; $weekBH=0; $weekOT=0;
                      foreach ($w['days'] as $d => $v) {
                        $weekWorked += (int)$v['worked'];
                        $weekBm += (int)$v['break_minus'];
                        $weekBp += (int)$v['break_plus'];
                        $weekPaid += (int)$v['paid'];
                        $weekN += (int)$v['normal'];
                        $weekW += (int)$v['weekend'];
                        $weekBH += (int)$v['bh'];
                        $weekOT += (int)$v['ot'];
                      }
                      // Incomplete week note (month-end defers OT)
                      $periodEndStr = (string)($batch['period_end'] ?? '');
                      $tzTmp = new DateTimeZone(payroll_timezone($pdo));
                      $periodEndLocal = $periodEndStr ? new DateTimeImmutable($periodEndStr.' 00:00:00', $tzTmp) : null;
                      $incomplete = false;
                      if ($periodEndLocal) {
                        $incomplete = ($endLEx > $periodEndLocal);
                      }
                    ?>
                    <details class="rounded-3xl border border-slate-200 overflow-hidden" open>
                      <summary class="cursor-pointer select-none px-4 py-3 bg-white flex items-center justify-between gap-4">
                        <div class="font-semibold text-sm">Week <?= h($startL->format('d M Y')) ?> → <?= h($endL->format('d M Y')) ?></div>
                        <div class="flex items-center gap-4 text-xs text-slate-600">
                          <?php if ($incomplete): ?><span class="px-2 py-1 rounded-lg bg-yellow-500/20 text-yellow-200">Incomplete week · OT deferred</span><?php endif; ?>
                          <span>Paid <b class="text-slate-900"><?= hhmm($weekPaid) ?></b></span>
                          <span>OT <b class="text-slate-900"><?= hhmm($weekOT) ?></b></span>
                        </div>
                      </summary>
                      <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                          <thead class="bg-white text-slate-600">
                            <tr>
                              <th class="text-left px-4 py-3">Date</th>
                              <th class="text-left px-4 py-3">Shifts</th>
                              <th class="text-left px-4 py-3">Worked</th>
                              <th class="text-left px-4 py-3">Break −</th>
                              <th class="text-left px-4 py-3">Break +</th>
                              <th class="text-left px-4 py-3">Paid</th>
                              <th class="text-left px-4 py-3">Base</th>
                              <th class="text-left px-4 py-3">Weekend</th>
                              <th class="text-left px-4 py-3">BH</th>
                              <th class="text-left px-4 py-3">OT</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php
                              $days = array_keys($w['days']);
                              sort($days);
                              foreach ($days as $d):
                                $v = $w['days'][$d];
                                $shiftList = $dayShiftRows[$d] ?? [];
                            ?>
                              <tr class="border-t border-slate-200">
                                <td class="px-4 py-3 font-semibold"><?=
                                  h((new DateTimeImmutable($d.' 00:00:00', new DateTimeZone(payroll_timezone($pdo))))->format('D, d M'))
                                ?></td>
                                <td class="px-4 py-3">
                                  <details>
                                    <summary class="cursor-pointer underline"><?= (int)($v['shift_count'] ?? 0) ?> shift(s)</summary>
                                    <div class="mt-2 text-xs text-slate-600 space-y-1">
                                      <?php foreach ($shiftList as $sr): ?>
                                        <div class="flex items-center justify-between gap-3">
                                          <a class="underline" href="<?= h(admin_url('shift-view.php?id='.(int)$sr['shift_id'])) ?>">#<?= (int)$sr['shift_id'] ?></a>
                                          <span>Paid <?= hhmm((int)$sr['paid']) ?> · Base <?= hhmm((int)$sr['normal']) ?> · W/E <?= hhmm((int)$sr['weekend']) ?> · BH <?= hhmm((int)$sr['bh']) ?> · OT <?= hhmm((int)$sr['ot']) ?></span>
                                        </div>
                                      <?php endforeach; ?>
                                    </div>
                                  </details>
                                </td>
                                <td class="px-4 py-3"><?= hhmm((int)$v['worked']) ?></td>
                                <td class="px-4 py-3">-<?= hhmm((int)$v['break_minus']) ?></td>
                                <td class="px-4 py-3"><?= ((int)$v['break_plus'] > 0) ? '+'.hhmm((int)$v['break_plus']) : '+00:00' ?></td>
                                <td class="px-4 py-3 font-semibold"><?= hhmm((int)$v['paid']) ?></td>
                                <td class="px-4 py-3"><?= hhmm((int)$v['normal']) ?></td>
                                <td class="px-4 py-3"><?= hhmm((int)$v['weekend']) ?></td>
                                <td class="px-4 py-3"><?= hhmm((int)$v['bh']) ?></td>
                                <td class="px-4 py-3"><?= hhmm((int)$v['ot']) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </details>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <div class="mt-6">
            <h2 class="text-lg font-semibold">Shift snapshots</h2>
            <div class="mt-3 overflow-hidden rounded-3xl border border-slate-200">
              <table class="w-full text-sm">
                <thead class="bg-white text-slate-600">
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
                    <tr class="border-t border-slate-200">
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