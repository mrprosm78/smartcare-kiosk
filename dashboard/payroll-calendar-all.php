<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

$tzName = (string) setting($pdo, 'payroll_timezone', 'Europe/London');
// Week start is set once at initial setup (stored as e.g. MONDAY, SUNDAY, etc.).
// Never assume Monday; always respect the configured setting.
$weekStartsOn = payroll_week_starts_on($pdo); // returns UPPERCASE day name
$tz = new DateTimeZone($tzName);

$mode = (string)($_GET['mode'] ?? 'custom'); // this_month|last_month|custom
$ym = preg_replace('/[^0-9\-]/', '', (string)($_GET['month'] ?? ''));

$now = new DateTimeImmutable('now', $tz);
if ($mode === 'this_month') {
  $ym = $now->format('Y-m');
} elseif ($mode === 'last_month') {
  $ym = $now->modify('first day of last month')->format('Y-m');
} else {
  $mode = 'custom';
  if (!preg_match('/^\d{4}\-\d{2}$/', $ym)) {
    $ym = $now->format('Y-m');
  }
}
$status = (string)($_GET['status'] ?? 'awaiting'); // all|approved|awaiting|open
$q = trim((string)($_GET['q'] ?? ''));

// Month window (payroll TZ) — anchored by clock-in date
$monthStartLocal = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
$monthEndLocalEx = $monthStartLocal->modify('first day of next month');
$monthStartUtc = $monthStartLocal->setTimezone(new DateTimeZone('UTC'));
$monthEndUtcEx = $monthEndLocalEx->setTimezone(new DateTimeZone('UTC'));

function week_start_for(DateTimeImmutable $d, string $weekStartsOnUpper): DateTimeImmutable {
  $dow = (int)$d->format('N'); // 1..7 Mon..Sun
  $map = [
    'MONDAY'    => 1,
    'TUESDAY'   => 2,
    'WEDNESDAY' => 3,
    'THURSDAY'  => 4,
    'FRIDAY'    => 5,
    'SATURDAY'  => 6,
    'SUNDAY'    => 7,
  ];
  $startDow = $map[strtoupper(trim($weekStartsOnUpper))] ?? 1;
  $delta = $dow - $startDow;
  if ($delta < 0) $delta += 7;
  return $d->setTime(0,0)->modify("-{$delta} days");
}

function badge_html(string $status): string {
  $map = [
    'approved' => 'bg-emerald-500/15 text-slate-900 border-emerald-500/30',
    'awaiting' => 'bg-amber-500/15 text-slate-900 border-amber-500/30',
    'open' => 'bg-rose-500/15 text-slate-900 border-rose-500/30',
  ];
  $cls = $map[$status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
  return '<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold '.$cls.'">'.h(ucfirst($status)).'</span>';
}

// Bank holidays (local date Y-m-d)
$bhRows = $pdo->query("SELECT holiday_date FROM payroll_bank_holidays")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$bankHolidays = [];
foreach ($bhRows as $d) { $bankHolidays[(string)$d] = true; }

// Build day buckets (ONLY month days)
$days = [];
for ($d = $monthStartLocal; $d < $monthEndLocalEx; $d = $d->modify('+1 day')) {
  $key = $d->format('Y-m-d');
  $days[$key] = [
    'date' => $d,
    'is_bh' => isset($bankHolidays[$key]),
    'is_weekend' => ((int)$d->format('N') >= 6),
    'total_paid' => 0,
    'bh' => 0,
    'weekend' => 0,
    'ot' => 0,
    'entries' => [], // shift entries anchored to this day (shift start day)
  ];
}

// Month options for the Custom selector (recent months)
$monthOptions = [];
$m0 = $now->modify('first day of this month');
for ($i = 0; $i < 18; $i++) {
  $m = $m0->modify("-{$i} months");
  $k = $m->format('Y-m');
  $monthOptions[$k] = $m->format('M Y');
}

// Employee contract source of truth: HR Staff contracts (via kiosk_employees.hr_staff_id).
// We resolve per-shift and per-week using payroll_employee_profile().

// Fetch shifts whose CLOCK-IN is within the month (UTC boundary based on payroll TZ)
$where = [];
$params = [];

$where[] = "s.clock_in_at >= ? AND s.clock_in_at < ?";
$params[] = $monthStartUtc->format('Y-m-d H:i:s');
$params[] = $monthEndUtcEx->format('Y-m-d H:i:s');

if ($status === 'approved') {
  $where[] = "s.approved_at IS NOT NULL";
} elseif ($status === 'awaiting') {
  $where[] = "s.clock_out_at IS NOT NULL AND s.approved_at IS NULL";
} elseif ($status === 'open') {
  $where[] = "s.clock_out_at IS NULL";
}

if ($q !== '') {
  $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "
  SELECT
    s.id, s.employee_id, s.clock_in_at, s.clock_out_at, s.is_autoclosed, s.approved_at,
    e.employee_code, e.first_name, e.last_name, e.nickname
  FROM kiosk_shifts s
  JOIN kiosk_employees e ON e.id = s.employee_id
  WHERE " . implode(" AND ", $where) . "
  ORDER BY s.clock_in_at ASC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$shifts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$utc = new DateTimeZone('UTC');
$nowUtc = new DateTimeImmutable('now', $utc);

foreach ($shifts as $s) {
  $inUtc = new DateTimeImmutable((string)$s['clock_in_at'], $utc);
  $outUtc = $s['clock_out_at'] ? new DateTimeImmutable((string)$s['clock_out_at'], $utc) : null;
  $outUtcEff = $outUtc ?: $nowUtc;

  $inLocal = $inUtc->setTimezone($tz);
  $outLocal = $outUtcEff->setTimezone($tz);

  // Anchor the whole shift to its START day (no splitting across midnight/month)
  $dayKey = $inLocal->format('Y-m-d');
  if (!isset($days[$dayKey])) continue;

  $worked = (int) floor(($outUtcEff->getTimestamp() - $inUtc->getTimestamp()) / 60);
  if ($worked < 0) $worked = 0;

  $breakMinutes = ($worked > 0) ? payroll_break_minutes_for_worked($pdo, $worked) : 0;
  $profile = payroll_employee_profile($pdo, (int)$s['employee_id'], $dayKey);
  $breakIsPaid = (bool)($profile['break_is_paid'] ?? false);

  $unpaidBreak = $breakIsPaid ? 0 : $breakMinutes;
  $paid = max(0, $worked - $unpaidBreak);

  $statusBadge = $s['approved_at'] ? 'approved' : (($s['clock_out_at'] ? 'awaiting' : 'open'));

  $entry = [
    'shift_id' => (int)$s['id'],
    'employee_id' => (int)$s['employee_id'],
    'employee' => admin_employee_display_name($s),
    'code' => (string)($s['employee_code'] ?? ''),
    'start' => $inLocal,
    'end' => $outLocal,
    'worked' => $worked,
    'break' => $breakMinutes,
    'unpaid_break' => $unpaidBreak,
    'paid' => $paid,
    'base' => 0,
    'bh' => 0,
    'weekend' => 0,
    'ot' => 0,
    'week_start_key' => week_start_for($inLocal, $weekStartsOn)->format('Y-m-d'),
    'status' => $statusBadge,
    'autoclosed' => ((int)$s['is_autoclosed']===1),
  ];

  $days[$dayKey]['total_paid'] += $paid;
  $days[$dayKey]['entries'][] = $entry;
}

// OT allocation per employee per week (non-stacking: OT > BH > Weekend > Base)
$entriesByEmpWeek = []; // emp|week => list of [&entryRef, dayKey]
foreach ($days as $dayKey => &$cell) {
  foreach ($cell['entries'] as $idx => &$it) {
    $empId = (int)($it['employee_id'] ?? 0);
    $wk = (string)($it['week_start_key'] ?? '');
    if ($empId <= 0 || $wk === '') continue;
    $k = $empId . '|' . $wk;
    if (!isset($entriesByEmpWeek[$k])) $entriesByEmpWeek[$k] = [];
    $entriesByEmpWeek[$k][] = [&$cell['entries'][$idx], $dayKey];
  }
}
unset($cell, $it);

foreach ($entriesByEmpWeek as $k => $arr) {
  [$empIdStr, $wk] = explode('|', $k, 2);
  $empId = (int)$empIdStr;

  // Overtime threshold is based on contracted weekly hours from the HR Staff contract.
// If contracted hours is NULL/0, this staff member is NOT paid overtime.
$weekProfile = payroll_employee_profile($pdo, $empId, $wk);
$contractH = (float)($weekProfile['contract_hours_per_week'] ?? 0);
$thresholdMinutes = ($contractH > 0) ? (int)round($contractH * 60) : 0;
  $weekPaid = 0;
  foreach ($arr as $pair) { $weekPaid += (int)$pair[0]['paid']; }
  $otRemain = ($thresholdMinutes > 0) ? max(0, $weekPaid - $thresholdMinutes) : 0;

  usort($arr, function($a, $b) {
    /** @var DateTimeImmutable $as */
    $as = $a[0]['start'];
    $bs = $b[0]['start'];
    return $as <=> $bs;
  });

  for ($i = count($arr) - 1; $i >= 0; $i--) {
    $it =& $arr[$i][0];
    $dayKey = $arr[$i][1];
    $paid = (int)$it['paid'];
    if ($paid <= 0) continue;

    $ot = 0;
    if ($otRemain > 0) {
      $ot = min($paid, $otRemain);
      $otRemain -= $ot;
    }
    $nonOt = $paid - $ot;

    $bh = 0; $we = 0; $base = 0;
    if ($nonOt > 0) {
      if (!empty($days[$dayKey]['is_bh'])) {
        $bh = $nonOt;
      } elseif (!empty($days[$dayKey]['is_weekend'])) {
        $we = $nonOt;
      } else {
        $base = $nonOt;
      }
    }

    $it['ot'] = $ot;
    $it['bh'] = $bh;
    $it['weekend'] = $we;
    $it['base'] = $base;

    $days[$dayKey]['ot'] += $ot;
    $days[$dayKey]['bh'] += $bh;
    $days[$dayKey]['weekend'] += $we;
  }
}

admin_page_start($pdo, 'Payroll Monthly Report (All)');
?>

<div class="space-y-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-bold">Payroll Monthly Report (All Employees)</h1>
      <div class="mt-1 text-sm text-slate-500">
        Anchored by <span class="font-semibold text-slate-700">clock-in date</span> ·
        Week starts: <span class="font-semibold text-slate-700"><?= h($weekStartsOn) ?></span> ·
        TZ: <span class="font-semibold text-slate-700"><?= h($tzName) ?></span>
      </div>
      <div class="mt-3">
        <a href="<?= h(admin_url('index.php')) ?>" class="inline-flex items-center gap-2 rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
          ← Back to Admin
        </a>
      </div>
    </div>

    <form method="get" class="flex flex-wrap gap-2 items-end">
      <label class="text-xs text-slate-500">Range
        <select name="mode" class="mt-1 w-36 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm">
          <option value="this_month" <?= $mode==='this_month'?'selected':'' ?>>This month</option>
          <option value="last_month" <?= $mode==='last_month'?'selected':'' ?>>Last month</option>
          <option value="custom" <?= $mode==='custom'?'selected':'' ?>>Custom</option>
        </select>
      </label>
      <label class="text-xs text-slate-500">Month
        <select name="month" class="mt-1 w-40 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm <?= $mode!=='custom'?'opacity-50 pointer-events-none':'' ?>">
          <?php foreach ($monthOptions as $k => $lab): ?>
            <option value="<?= h($k) ?>" <?= $ym===$k?'selected':'' ?>><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs text-slate-500">Status
        <select name="status" class="mt-1 w-40 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm">
          <?php foreach (['all'=>'All','approved'=>'Approved','awaiting'=>'Awaiting','open'=>'Open'] as $k=>$lab): ?>
            <option value="<?= h($k) ?>" <?= $status===$k?'selected':'' ?>><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs text-slate-500">Search
        <input name="q" value="<?= h($q) ?>" class="mt-1 w-56 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Name or code" />
      </label>
      <button class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold border border-slate-200 hover:bg-slate-50">Apply</button>
    </form>
  </div>

  <?php
    $currentWeekStartKey = '';
    $monthTotals = ['paid'=>0,'bh'=>0,'weekend'=>0,'ot'=>0,'break'=>0];

    foreach ($days as $dayKey => $cell):
      $d = $cell['date'];
      $weekStart = week_start_for($d, $weekStartsOn);
      $weekStartKey = $weekStart->format('Y-m-d');

      if ($weekStartKey !== $currentWeekStartKey):
        if ($currentWeekStartKey !== ''):
          // close previous week card
          echo "</div></div>";
        endif;

        $currentWeekStartKey = $weekStartKey;
        $weekEndEx = $weekStart->modify('+7 days');

        // Compute week totals (only days inside this month)
        $wkPaid = 0; $wkBH = 0; $wkWE = 0; $wkOT = 0;
        for ($i=0; $i<7; $i++) {
          $dk = $weekStart->modify("+{$i} days")->format('Y-m-d');
          if (!isset($days[$dk])) continue;
          $wkPaid += (int)$days[$dk]['total_paid'];
          $wkBH += (int)$days[$dk]['bh'];
          $wkWE += (int)$days[$dk]['weekend'];
          $wkOT += (int)$days[$dk]['ot'];
        }
  ?>
    <div class="rounded-3xl border border-slate-200 bg-white p-4">
      <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
        <div class="font-semibold">
          Week <?= h($weekStart->format('d M Y')) ?> → <?= h($weekEndEx->modify('-1 day')->format('d M Y')) ?>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
          <div>Hours <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkPaid)) ?></span></div>
          <div>BH <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkBH)) ?></span></div>
          <div>Weekend <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkWE)) ?></span></div>
          <div>OT <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkOT)) ?></span></div>
        </div>
      </div>

      <div class="mt-4 space-y-3">
  <?php
      endif;

      $entries = $cell['entries'];
      usort($entries, fn($a,$b) => ($a['start'] <=> $b['start']));

      $dayBreak = 0;
      foreach ($entries as $it) { $dayBreak += (int)($it['unpaid_break'] ?? 0); }

      $monthTotals['paid'] += (int)$cell['total_paid'];
      $monthTotals['bh'] += (int)$cell['bh'];
      $monthTotals['weekend'] += (int)$cell['weekend'];
      $monthTotals['ot'] += (int)$cell['ot'];
      $monthTotals['break'] += $dayBreak;
  ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
          <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <div class="text-sm font-semibold">
                <?= h($d->format('D d M Y')) ?>
                <?php if (!empty($cell['is_bh'])): ?>
                  <span class="ml-2 rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-slate-900">BH</span>
                <?php endif; ?>
                <?php if (!empty($cell['is_weekend'])): ?>
                  <span class="ml-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-700">Weekend</span>
                <?php endif; ?>
              </div>
              <div class="mt-1 text-xs text-slate-500">
                Worked <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['total_paid'])) ?></span>
                · Break deducted <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($dayBreak)) ?></span>
                · BH <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['bh'])) ?></span>
                · Weekend <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['weekend'])) ?></span>
                · OT <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['ot'])) ?></span>
              </div>
            </div>
          </div>

          <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-xs text-slate-500">
                <tr class="border-b border-slate-200">
                  <th class="py-2 text-left font-semibold">Employee</th>
                  <th class="py-2 text-left font-semibold">In</th>
                  <th class="py-2 text-left font-semibold">Out</th>
                  <th class="py-2 text-right font-semibold">Worked</th>
                  <th class="py-2 text-right font-semibold">Break</th>
                  <th class="py-2 text-right font-semibold">Paid</th>
                  <th class="py-2 text-right font-semibold">BH</th>
                  <th class="py-2 text-right font-semibold">Weekend</th>
                  <th class="py-2 text-right font-semibold">OT</th>
                  <th class="py-2 text-right font-semibold">Status</th>
                  <th class="py-2 text-right font-semibold"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <?php if (empty($entries)): ?>
                  <tr><td colspan="11" class="py-3 text-slate-400">—</td></tr>
                <?php else: ?>
                  <?php foreach ($entries as $it): ?>
                    <tr>
                      <td class="py-2">
                        <?php
                          $code = trim((string)($it['code'] ?? ''));
                          $name = trim((string)($it['employee'] ?? ''));
                          $label = $code !== '' ? ($code . ' — ' . $name) : ($name !== '' ? $name : '—');
                        ?>
                        <div class="font-semibold"><?= h($label) ?></div>
                      </td>
                      <td class="py-2 text-slate-700"><?= h($it['start']->format('H:i')) ?></td>
                      <td class="py-2 text-slate-700"><?= h($it['end']->format('H:i')) ?></td>
                      <td class="py-2 text-right text-slate-700"><?= h(payroll_fmt_hhmm((int)$it['worked'])) ?></td>
                      <td class="py-2 text-right text-slate-700"><?= h(payroll_fmt_hhmm((int)($it['unpaid_break'] ?? 0))) ?></td>
                      <td class="py-2 text-right font-semibold text-slate-900"><?= h(payroll_fmt_hhmm((int)$it['paid'])) ?></td>
                      <td class="py-2 text-right text-slate-700"><?= h(payroll_fmt_hhmm((int)($it['bh'] ?? 0))) ?></td>
                      <td class="py-2 text-right text-slate-700"><?= h(payroll_fmt_hhmm((int)($it['weekend'] ?? 0))) ?></td>
                      <td class="py-2 text-right text-slate-700"><?= h(payroll_fmt_hhmm((int)($it['ot'] ?? 0))) ?></td>
                      <td class="py-2 text-right"><?= badge_html((string)$it['status']) ?></td>
                      <td class="py-2 text-right">
                        <a class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100" href="<?= h(admin_url('shift-edit.php?id='.(int)$it['shift_id'])) ?>">Fix</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

  <?php endforeach; ?>

  <?php if ($currentWeekStartKey !== ''): ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="rounded-3xl border border-slate-200 bg-white p-4">
    <div class="flex flex-wrap items-center gap-4">
      <div class="font-semibold">Month totals</div>
      <div class="text-slate-500 text-sm">Worked <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$monthTotals['paid'])) ?></span></div>
      <div class="text-slate-500 text-sm">Break deducted <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$monthTotals['break'])) ?></span></div>
      <div class="text-slate-500 text-sm">BH <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$monthTotals['bh'])) ?></span></div>
      <div class="text-slate-500 text-sm">Weekend <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$monthTotals['weekend'])) ?></span></div>
      <div class="text-slate-500 text-sm">OT <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$monthTotals['ot'])) ?></span></div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
