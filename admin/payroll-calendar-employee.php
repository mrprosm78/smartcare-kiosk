<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

$active = admin_url('payroll-calendar-employee.php');

$tzName = (string) setting($pdo, 'payroll_timezone', 'Europe/London');
$weekStartsOnRaw = (string) setting($pdo, 'payroll_week_starts_on', 'monday'); // stored sometimes as SUNDAY
$weekStartsOn = strtolower(trim($weekStartsOnRaw));
if (!in_array($weekStartsOn, ['monday','sunday'], true)) $weekStartsOn = 'monday';

$tz = new DateTimeZone($tzName);
$utc = new DateTimeZone('UTC');

/** @return DateTimeImmutable in payroll TZ at 00:00 */
function week_start_for(DateTimeImmutable $dLocal, string $weekStartsOn): DateTimeImmutable {
  $dow = (int)$dLocal->format('N'); // 1..7 (Mon..Sun)
  $startDow = ($weekStartsOn === 'sunday') ? 7 : 1;
  $delta = $dow - $startDow;
  if ($delta < 0) $delta += 7;
  return $dLocal->setTime(0,0)->modify("-{$delta} days");
}
function fmt_hm(int $minutes): string {
  if ($minutes <= 0) return '0:00';
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  return $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

// Month param: YYYY-MM
$ym = preg_replace('/[^0-9\-]/', '', (string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}\-\d{2}$/', $ym)) {
  $ym = (new DateTimeImmutable('now', $tz))->format('Y-m');
}

$deptId = (int)($_GET['department_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$status = (string)($_GET['status'] ?? 'all'); // all|approved|awaiting

// Load departments
$departments = $pdo->query("SELECT id, name FROM kiosk_employee_departments WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Load employees (filtered by department if selected)
$sqlEmp = "SELECT id, employee_code, first_name, last_name, department_id
           FROM kiosk_employees
           WHERE is_active=1";
$paramsEmp = [];
if ($deptId > 0) { $sqlEmp .= " AND department_id = ?"; $paramsEmp[] = $deptId; }
$sqlEmp .= " ORDER BY last_name, first_name, id";
$stEmp = $pdo->prepare($sqlEmp);
$stEmp->execute($paramsEmp);
$employees = $stEmp->fetchAll(PDO::FETCH_ASSOC) ?: [];

// If selected employee not in list (e.g. dept filter changed), reset
$employeeIds = array_map(fn($r) => (int)$r['id'], $employees);
if ($employeeId > 0 && !in_array($employeeId, $employeeIds, true)) $employeeId = 0;
if ($employeeId <= 0 && !empty($employees)) $employeeId = (int)$employees[0]['id'];

// Selected employee label
$selectedEmployee = null;
foreach ($employees as $e) { if ((int)$e['id'] === $employeeId) { $selectedEmployee = $e; break; } }

// Month boundaries in payroll TZ
$monthStartLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ym . '-01 00:00:00', $tz);
if (!$monthStartLocal) $monthStartLocal = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
$monthEndLocalEx = $monthStartLocal->modify('first day of next month');
$monthStartUtc = $monthStartLocal->setTimezone($utc);
$monthEndUtcEx = $monthEndLocalEx->setTimezone($utc);

// Bank holidays (local date string Y-m-d)
$bhRows = $pdo->query("SELECT holiday_date FROM payroll_bank_holidays")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$bankHolidays = [];
foreach ($bhRows as $d) $bankHolidays[(string)$d] = true;

// Employee pay profile (paid breaks + weekly contract hours)
$breakIsPaid = false;
$contractHoursPerWeek = 0;
if ($employeeId > 0) {
  $st = $pdo->prepare("SELECT break_is_paid, contract_hours_per_week FROM kiosk_employee_pay_profiles WHERE employee_id=? LIMIT 1");
  $st->execute([$employeeId]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $breakIsPaid = ((int)($row['break_is_paid'] ?? 0)) === 1;
    $contractHoursPerWeek = (int)($row['contract_hours_per_week'] ?? 0);
  }
}
$weeklyThresholdMinutes = max(0, $contractHoursPerWeek * 60);

// Fetch shifts anchored to clock_in_at within month (your rule)
$shifts = [];
if ($employeeId > 0) {
  $where = ["s.employee_id = ?",
            "s.clock_in_at IS NOT NULL",
            "s.clock_in_at >= ? AND s.clock_in_at < ?",
            "s.clock_out_at IS NOT NULL"];
  $params = [$employeeId, $monthStartUtc->format('Y-m-d H:i:s'), $monthEndUtcEx->format('Y-m-d H:i:s')];

  if ($status === 'approved') {
    $where[] = "s.approved_at IS NOT NULL";
  } elseif ($status === 'awaiting') {
    $where[] = "s.approved_at IS NULL";
  }

  $sql = "SELECT s.id, s.employee_id, s.clock_in_at, s.clock_out_at, s.break_minutes, s.approved_at, s.is_autoclosed, s.close_reason
          FROM kiosk_shifts s
          WHERE " . implode(" AND ", $where) . "
          ORDER BY s.clock_in_at ASC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $shifts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Build day buckets and week buckets
$days = [];      // date => ['shifts'=>[], 'totals'=>...]
$weeks = [];     // weekStartDate => ['days'=>set, totals...]
$monthTotals = ['worked'=>0,'break'=>0,'paid'=>0,'bh'=>0,'weekend'=>0,'ot'=>0];

foreach ($shifts as $s) {
  $inUtc = new DateTimeImmutable((string)$s['clock_in_at'], $utc);
  $outUtc = new DateTimeImmutable((string)$s['clock_out_at'], $utc);
  $inLocal = $inUtc->setTimezone($tz);
  $outLocal = $outUtc->setTimezone($tz);

  $worked = max(0, (int)round(($outUtc->getTimestamp() - $inUtc->getTimestamp()) / 60));
  $breakMinutes = null;
  if ($s['break_minutes'] !== null) $breakMinutes = (int)$s['break_minutes'];
  if ($breakMinutes === null) $breakMinutes = payroll_break_minutes_for_worked($pdo, $worked);

  $unpaidBreak = $breakIsPaid ? 0 : max(0, $breakMinutes);
  $paid = max(0, $worked - $unpaidBreak);

  $dateKey = $inLocal->format('Y-m-d');
  $dow = (int)$inLocal->format('N'); // 6 Sat, 7 Sun
  $isWeekend = ($dow >= 6);
  $isBH = isset($bankHolidays[$dateKey]);

  $weekStartLocal = week_start_for($inLocal, $weekStartsOn);
  $weekKey = $weekStartLocal->format('Y-m-d');

  $row = [
    'id' => (int)$s['id'],
    'in_local' => $inLocal,
    'out_local' => $outLocal,
    'worked' => $worked,
    'break' => $unpaidBreak,
    'paid' => $paid,
    'is_bh' => $isBH,
    'is_weekend' => $isWeekend,
    'approved' => $s['approved_at'] !== null,
    'is_autoclosed' => ((int)($s['is_autoclosed'] ?? 0)) === 1,
    'close_reason' => (string)($s['close_reason'] ?? ''),
    'week_key' => $weekKey,
    'date_key' => $dateKey,
  ];

  if (!isset($days[$dateKey])) {
    $days[$dateKey] = [
      'shifts' => [],
      'totals' => ['worked'=>0,'break'=>0,'paid'=>0,'bh'=>0,'weekend'=>0],
    ];
  }
  $days[$dateKey]['shifts'][] = $row;
  $days[$dateKey]['totals']['worked'] += $worked;
  $days[$dateKey]['totals']['break'] += $unpaidBreak;
  $days[$dateKey]['totals']['paid'] += $paid;
  if ($isBH) $days[$dateKey]['totals']['bh'] += $paid;
  if ($isWeekend) $days[$dateKey]['totals']['weekend'] += $paid;

  if (!isset($weeks[$weekKey])) {
    $weeks[$weekKey] = [
      'week_start_local' => $weekStartLocal,
      'totals' => ['worked'=>0,'break'=>0,'paid'=>0,'bh'=>0,'weekend'=>0,'ot'=>0],
      'days' => [],
    ];
  }
  $weeks[$weekKey]['totals']['worked'] += $worked;
  $weeks[$weekKey]['totals']['break'] += $unpaidBreak;
  $weeks[$weekKey]['totals']['paid'] += $paid;
  if ($isBH) $weeks[$weekKey]['totals']['bh'] += $paid;
  if ($isWeekend) $weeks[$weekKey]['totals']['weekend'] += $paid;
  $weeks[$weekKey]['days'][$dateKey] = true;

  $monthTotals['worked'] += $worked;
  $monthTotals['break'] += $unpaidBreak;
  $monthTotals['paid'] += $paid;
  if ($isBH) $monthTotals['bh'] += $paid;
  if ($isWeekend) $monthTotals['weekend'] += $paid;
}

// Compute OT per week (weekly paid - threshold)
foreach ($weeks as $wk => &$w) {
  $paid = (int)$w['totals']['paid'];
  $ot = ($weeklyThresholdMinutes > 0) ? max(0, $paid - $weeklyThresholdMinutes) : 0;
  $w['totals']['ot'] = $ot;
  $monthTotals['ot'] += $ot;
}
unset($w);

// Build month day list for rendering
$dayCursor = $monthStartLocal;
$dayKeys = [];
while ($dayCursor < $monthEndLocalEx) {
  $dayKeys[] = $dayCursor->format('Y-m-d');
  $dayCursor = $dayCursor->modify('+1 day');
}

admin_page_start($pdo, 'Payroll Monthly Report');
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Payroll Monthly Report</h1>
                <p class="mt-2 text-sm text-slate-600">
                  Shows shifts from the 1st to the last day of the month, split by payroll weeks (week starts on <span class="font-semibold"><?= h($weekStartsOn) ?></span>).
                </p>
              </div>
            </div>

            <form id="filters" method="get" class="mt-4 flex flex-wrap items-end gap-3">
              <div class="min-w-[140px]">
                <label class="block text-xs font-semibold text-slate-600">Month</label>
                <input name="month" value="<?= h($ym) ?>" placeholder="YYYY-MM" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" />
              </div>

              <div class="min-w-[200px]">
                <label class="block text-xs font-semibold text-slate-600">Department</label>
                <select name="department_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="0">All departments</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= ((int)$d['id'] === $deptId) ? 'selected' : '' ?>><?= h((string)$d['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="min-w-[260px] flex-1">
                <label class="block text-xs font-semibold text-slate-600">Employee</label>
                <select id="employee_id" name="employee_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <?php foreach ($employees as $e): ?>
                    <?php
                      $name = trim((string)($e['first_name'] ?? '') . ' ' . (string)($e['last_name'] ?? ''));
                      $code = (string)($e['employee_code'] ?? '');
                      $label = $name !== '' ? $name : $code;
                      if ($code !== '') $label .= " ({$code})";
                    ?>
                    <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id'] === $employeeId) ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
<p class="mt-1 text-xs text-slate-500">Search filters the dropdown locally; it won’t affect results unless you change the selected employee.</p>
              </div>

              <div class="min-w-[170px]">
                <label class="block text-xs font-semibold text-slate-600">Status</label>
                <select name="status" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="all" <?= $status==='all'?'selected':'' ?>>All closed</option>
                  <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
                  <option value="awaiting" <?= $status==='awaiting'?'selected':'' ?>>Awaiting approval</option>
                </select>
              </div>

              <div class="flex items-center gap-2">
                <a href="<?= h(admin_url('payroll-calendar-employee.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold border border-slate-200 bg-white hover:bg-slate-50">Reset</a>
              </div>
            </form>
            <script>
              (function(){
                const form = document.getElementById("filters");
                if(!form) return;
                form.querySelectorAll("select, input[name=month]").forEach(el => {
                  el.addEventListener("change", () => form.submit());
                });
              })();
            </script>
          </header>

          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
              <div>
                <div class="text-sm text-slate-600">Selected employee</div>
                <div class="text-base font-semibold">
                  <?= h($selectedEmployee ? (trim((string)$selectedEmployee['first_name'].' '.$selectedEmployee['last_name'])) : '—') ?>
                </div>
              </div>

              <div class="grid grid-cols-2 sm:grid-cols-6 gap-2">
                <div class="rounded-2xl border border-slate-200 p-2">
                  <div class="text-xs text-slate-500">Paid hours</div>
                  <div class="mt-1 text-base font-semibold"><?= h(fmt_hm((int)$monthTotals['paid'])) ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 p-2">
                  <div class="text-xs text-slate-500">Break deducted</div>
                  <div class="mt-1 text-base font-semibold"><?= h(fmt_hm((int)$monthTotals['break'])) ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 p-2">
                  <div class="text-xs text-slate-500">Overtime (weekly)</div>
                  <div class="mt-1 text-base font-semibold"><?= h(fmt_hm((int)$monthTotals['ot'])) ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 p-2">
                  <div class="text-xs text-slate-500">Bank holiday</div>
                  <div class="mt-1 text-base font-semibold"><?= h(fmt_hm((int)$monthTotals['bh'])) ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 p-2">
                  <div class="text-xs text-slate-500">Weekend</div>
                  <div class="mt-1 text-base font-semibold"><?= h(fmt_hm((int)$monthTotals['weekend'])) ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 p-2">
                  <div class="text-xs text-slate-500">Worked (gross)</div>
                  <div class="mt-1 text-base font-semibold"><?= h(fmt_hm((int)$monthTotals['worked'])) ?></div>
                </div>
              </div>
            </div>
          </section>

          <?php if ($employeeId <= 0): ?>
            <div class="mt-5 rounded-3xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
              No employees found for the selected department.
            </div>
          <?php else: ?>

            <?php
              // Determine week blocks covering the month, based on configured week start
              $firstWeekStart = week_start_for($monthStartLocal, $weekStartsOn);
              $lastDay = $monthEndLocalEx->modify('-1 day');
              $lastWeekStart = week_start_for($lastDay, $weekStartsOn);
              $wkCursor = $firstWeekStart;
              $weekKeysInRange = [];
              while ($wkCursor <= $lastWeekStart) {
                $weekKeysInRange[] = $wkCursor->format('Y-m-d');
                $wkCursor = $wkCursor->modify('+7 days');
              }
            ?>

            <?php foreach ($weekKeysInRange as $wkKey): ?>
              <?php
                $ws = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wkKey.' 00:00:00', $tz);
                if (!$ws) $ws = new DateTimeImmutable($wkKey.' 00:00:00', $tz);
                $weEx = $ws->modify('+7 days');
                $label = $ws->format('D d M Y') . ' – ' . $weEx->modify('-1 day')->format('D d M Y');
                $w = $weeks[$wkKey] ?? ['totals'=>['worked'=>0,'break'=>0,'paid'=>0,'bh'=>0,'weekend'=>0,'ot'=>0],'days'=>[]];
              ?>

              <section class="mt-5 rounded-3xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
                  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                      <div class="text-xs uppercase tracking-widest text-slate-500">Week</div>
                      <div class="text-base font-semibold"><?= h($label) ?></div>
                    </div>
                    <div class="text-sm text-slate-600">
                      Weekly OT threshold:
                      <span class="font-semibold"><?= $weeklyThresholdMinutes > 0 ? h(fmt_hm($weeklyThresholdMinutes)) : '—' ?></span>
                    </div>
                  </div>
                </div>

                <div class="overflow-x-auto">
                  <table class="min-w-full text-sm">
                    <thead class="bg-white">
                      <tr class="text-left text-slate-600">
                        <th class="px-5 py-3 font-semibold">Date</th>
                        <th class="px-5 py-3 font-semibold">Clock in</th>
                        <th class="px-5 py-3 font-semibold">Clock out</th>
                        <th class="px-5 py-3 font-semibold">Worked</th>
                        <th class="px-5 py-3 font-semibold">Break</th>
                        <th class="px-5 py-3 font-semibold">Paid</th>
                        <th class="px-5 py-3 font-semibold">Tags</th>
                        <th class="px-5 py-3 font-semibold">Status</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                      <?php
                        // Render only days that have shifts in this week (hide empty days)
                        $weekDayKeys = array_keys($w['days'] ?? []);
                        sort($weekDayKeys);
                        foreach ($weekDayKeys as $dk) {
                          $dLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dk.' 00:00:00', $tz);
                          if (!$dLocal) continue;
                          if ($dLocal < $monthStartLocal || $dLocal >= $monthEndLocalEx) continue;

                          $day = $days[$dk] ?? null;
                          if (!$day || empty($day['shifts'])) continue;

                          $dayLabel = $dLocal->format('D d M Y');

                          foreach ($day['shifts'] as $r) {
                            $tags = [];
                            if ($r['is_bh']) $tags[] = 'BH';
                            if ($r['is_weekend']) $tags[] = 'Weekend';
                            $tagHtml = '';
                            foreach ($tags as $t) {
                              $tagHtml .= '<span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-700 mr-1">'.h($t).'</span>';
                            }
                            $statusText = $r['approved'] ? 'Approved' : 'Awaiting';
                            if ($r['is_autoclosed']) $statusText = 'Autoclosed';
                            echo '<tr>';
                            echo '<td class="px-5 py-3 font-semibold text-slate-700">'.h($dayLabel).'</td>';
                            echo '<td class="px-5 py-3 text-slate-700">'.h($r['in_local']->format('H:i')).'</td>';
                            echo '<td class="px-5 py-3 text-slate-700">'.h($r['out_local']->format('H:i')).'</td>';
                            echo '<td class="px-5 py-3 font-semibold">'.h(fmt_hm((int)$r['worked'])).'</td>';
                            echo '<td class="px-5 py-3 font-semibold">'.h(fmt_hm((int)$r['break'])).'</td>';
                            echo '<td class="px-5 py-3 font-semibold">'.h(fmt_hm((int)$r['paid'])).'</td>';
                            echo '<td class="px-5 py-3">'.$tagHtml.'</td>';
                            echo '<td class="px-5 py-3 text-slate-700">'.h($statusText).'</td>';
                            echo '</tr>';
                          }
                        }?>
                    </tbody>

                    <tfoot class="bg-white">
                      <tr class="border-t border-slate-200">
                        <td class="px-5 py-4 font-semibold">Week totals</td>
                        <td class="px-5 py-4" colspan="2"></td>
                        <td class="px-5 py-4 font-semibold"><?= h(fmt_hm((int)$w['totals']['worked'])) ?></td>
                        <td class="px-5 py-4 font-semibold"><?= h(fmt_hm((int)$w['totals']['break'])) ?></td>
                        <td class="px-5 py-4 font-semibold"><?= h(fmt_hm((int)$w['totals']['paid'])) ?></td>
                        <td class="px-5 py-4 text-xs text-slate-700">
                          BH: <span class="font-semibold"><?= h(fmt_hm((int)$w['totals']['bh'])) ?></span>
                          • Weekend: <span class="font-semibold"><?= h(fmt_hm((int)$w['totals']['weekend'])) ?></span>
                          • OT: <span class="font-semibold"><?= h(fmt_hm((int)$w['totals']['ot'])) ?></span>
                        </td>
                        <td class="px-5 py-4"></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </section>
            <?php endforeach; ?>

          <?php endif; ?>
        </main>
      </div>
    </div>
  </div>
</div>
<?php admin_page_end(); ?>
