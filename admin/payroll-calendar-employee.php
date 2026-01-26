<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

$tzName = (string) setting($pdo, 'payroll_timezone', 'Europe/London');
$weekStartsOn = (string) setting($pdo, 'payroll_week_starts_on', 'monday'); // monday|sunday
$monthBoundaryMode = (string) setting($pdo, 'payroll_month_boundary_mode', 'midnight'); // midnight|end_of_shift

$tz = new DateTimeZone($tzName);

// Month param: YYYY-MM (default current month in payroll TZ)
$ym = preg_replace('/[^0-9\-]/', '', (string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}\-\d{2}$/', $ym)) {
  $now = new DateTimeImmutable('now', $tz);
  $ym = $now->format('Y-m');
}
$status = (string)($_GET['status'] ?? 'all'); // all|approved|awaiting|open
$employeeId = (int)($_GET['employee_id'] ?? 0);

// Load employees for dropdown
$emps = $pdo->query("SELECT id, employee_code, first_name, last_name FROM kiosk_employees WHERE is_active=1 ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($employeeId <= 0 && !empty($emps)) $employeeId = (int)$emps[0]['id'];

// Build month window in payroll TZ
$monthStartLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ym . '-01 00:00:00', $tz);
if (!$monthStartLocal) $monthStartLocal = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
$monthEndLocalEx = $monthStartLocal->modify('first day of next month');

$monthStartUtc = $monthStartLocal->setTimezone(new DateTimeZone('UTC'));
$monthEndUtcEx = $monthEndLocalEx->setTimezone(new DateTimeZone('UTC'));

function week_start_for(DateTimeImmutable $d, string $weekStartsOn): DateTimeImmutable {
  // $d in payroll TZ
  $dow = (int)$d->format('N'); // 1..7 Mon..Sun
  $startDow = ($weekStartsOn === 'sunday') ? 7 : 1;
  $delta = ($dow - $startDow);
  if ($delta < 0) $delta += 7;
  return $d->setTime(0,0)->modify("-{$delta} days");
}
function week_end_ex_for(DateTimeImmutable $weekStart): DateTimeImmutable {
  return $weekStart->modify('+7 days');
}
function dt_min(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable { return ($a <= $b) ? $a : $b; }
function dt_max(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable { return ($a >= $b) ? $a : $b; }

$gridStartLocal = week_start_for($monthStartLocal, $weekStartsOn);
$gridEndLocalEx = week_end_ex_for(week_start_for($monthEndLocalEx->modify('-1 day'), $weekStartsOn)); // end of last week block

// Bank holidays set (local date Y-m-d)
$bhRows = $pdo->query("SELECT holiday_date FROM payroll_bank_holidays")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$bankHolidays = [];
foreach ($bhRows as $d) { $bankHolidays[(string)$d] = true; }

// Fetch employee profile for paid breaks flag
$breakIsPaid = false;
$contractHoursPerWeek = 0;
if ($employeeId > 0) {
  $st = $pdo->prepare("SELECT break_is_paid, contract_hours_per_week FROM kiosk_employee_pay_profiles WHERE employee_id=? LIMIT 1");
  $st->execute([$employeeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($row) {
    $breakIsPaid = ((int)($row['break_is_paid'] ?? 0)) === 1;
    $contractHoursPerWeek = (int)($row['contract_hours_per_week'] ?? 0);
  }
}

// Fetch shifts
$shifts = [];
if ($employeeId > 0) {
  $where = ["s.employee_id = ?"];
  $params = [$employeeId];

  if ($monthBoundaryMode === 'end_of_shift') {
    // Shifts whose LOCAL clock-in is within the month (simple: use UTC boundary on clock_in_at)
    $where[] = "s.clock_in_at >= ? AND s.clock_in_at < ?";
    $params[] = $monthStartUtc->format('Y-m-d H:i:s');
    $params[] = $monthEndUtcEx->format('Y-m-d H:i:s');
  } else {
    // midnight mode: shifts overlapping the month window
    $where[] = "s.clock_in_at < ? AND COALESCE(s.clock_out_at, UTC_TIMESTAMP()) > ?";
    $params[] = $monthEndUtcEx->format('Y-m-d H:i:s');
    $params[] = $monthStartUtc->format('Y-m-d H:i:s');
  }

  if ($status === 'approved') {
    $where[] = "s.approved_at IS NOT NULL";
  } elseif ($status === 'awaiting') {
    $where[] = "s.clock_out_at IS NOT NULL AND s.approved_at IS NULL";
  } elseif ($status === 'open') {
    $where[] = "s.clock_out_at IS NULL";
  }

  $sql = "
    SELECT
      s.id,
      s.employee_id,
      s.clock_in_at,
      s.clock_out_at,
      s.is_autoclosed,
      s.close_reason,
      s.approved_at,
      s.updated_at,
      e.employee_code,
      e.first_name,
      e.last_name
    FROM kiosk_shifts s
    JOIN kiosk_employees e ON e.id = s.employee_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY s.clock_in_at ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $shifts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Build day buckets for the grid (only days in the grid)
$days = [];
for ($d = $gridStartLocal; $d < $gridEndLocalEx; $d = $d->modify('+1 day')) {
  $key = $d->format('Y-m-d');
  $isWeekend = ((int)$d->format('N') >= 6);
  $days[$key] = [
    'date' => $d,
    'in_month' => ($d >= $monthStartLocal && $d < $monthEndLocalEx),
    'is_bh' => isset($bankHolidays[$key]),
    'is_weekend' => $isWeekend,
    'total_worked' => 0,
    'break_minus' => 0,
    'break_plus' => 0,
    'total_paid' => 0,
    'base' => 0,
    'bh' => 0,
    'weekend' => 0,
    'ot' => 0,
    'shift_slices' => [], // each: [shift_id, start_local, end_local, worked, break_minus, break_plus, paid, status...]
  ];
}

// Slice each shift into day chunks (local midnight). Apply month clipping in midnight mode.
$utc = new DateTimeZone('UTC');
foreach ($shifts as $s) {
  $inUtc = new DateTimeImmutable((string)$s['clock_in_at'], $utc);
  $outUtc = $s['clock_out_at'] ? new DateTimeImmutable((string)$s['clock_out_at'], $utc) : null;

  // For open shifts, show until now (local) just for calendar visibility
  $outUtcEff = $outUtc ?: new DateTimeImmutable('now', $utc);

  $inLocal = $inUtc->setTimezone($tz);
  $outLocal = $outUtcEff->setTimezone($tz);

  // Clip to month if midnight mode
  if ($monthBoundaryMode !== 'end_of_shift') {
    $inLocal = dt_max($inLocal, $monthStartLocal);
    $outLocal = dt_min($outLocal, $monthEndLocalEx);
    if ($outLocal <= $inLocal) continue;
  } else {
    // end_of_shift: only include shifts starting in month, no clipping
    if ($outLocal <= $inLocal) continue;
  }

  $worked = (int) floor(($outLocal->getTimestamp() - $inLocal->getTimestamp()) / 60);
  if ($worked <= 0) continue;

  $breakMinutes = payroll_break_minutes_for_worked($pdo, $worked);
  $breakMinus = $breakMinutes;
  $breakPlus = $breakIsPaid ? $breakMinutes : 0;
  $paid = $worked - $breakMinus + $breakPlus;
  if ($paid < 0) $paid = 0;

  // Allocate break and paid proportionally across slices by worked minutes
  $cursor = $inLocal;
  while ($cursor < $outLocal) {
    $dayStart = $cursor->setTime(0,0);
    $dayEnd = $dayStart->modify('+1 day');
    $sliceEnd = dt_min($outLocal, $dayEnd);
    $sliceWorked = (int) floor(($sliceEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
    if ($sliceWorked <= 0) { $cursor = $sliceEnd; continue; }

    $prop = $sliceWorked / $worked;
    $sliceBreakMinus = (int) round($breakMinus * $prop);
    $sliceBreakPlus  = (int) round($breakPlus * $prop);
    $slicePaid = $sliceWorked - $sliceBreakMinus + $sliceBreakPlus;
    if ($slicePaid < 0) $slicePaid = 0;

    $key = $cursor->format('Y-m-d');
    if (isset($days[$key])) {
      $days[$key]['total_worked'] += $sliceWorked;
      $days[$key]['break_minus'] += $sliceBreakMinus;
      $days[$key]['break_plus'] += $sliceBreakPlus;
      $days[$key]['total_paid'] += $slicePaid;

      $statusBadge = $s['approved_at'] ? 'approved' : (($s['clock_out_at'] ? 'awaiting' : 'open'));
      $flags = [];
      if ((int)$s['is_autoclosed'] === 1) $flags[] = 'autoclosed';
      // naive edited detection: updated_at differs materially from created? (we only have updated_at here)
      $days[$key]['shift_slices'][] = [
        'shift_id' => (int)$s['id'],
        'start' => $cursor,
        'end' => $sliceEnd,
        'worked' => $sliceWorked,
        'break_minus' => $sliceBreakMinus,
        'break_plus' => $sliceBreakPlus,
        'paid' => $slicePaid,
        // classification will be filled after OT allocation
        'base' => 0,
        'bh' => 0,
        'weekend' => 0,
        'ot' => 0,
        'week_start_key' => week_start_for($cursor, $weekStartsOn)->format('Y-m-d'),
        'status' => $statusBadge,
        'flags' => $flags,
      ];
    }

    $cursor = $sliceEnd;
  }
}

// Allocate buckets with non-stacking priority: OT > BH > Weekend > Base
// OT is weekly and allocated from end-of-week backwards.
$thresholdMinutes = max(0, $contractHoursPerWeek) * 60;

// Gather slices per week
$slicesByWeek = []; // weekStartYmd => list of [&slice, dayKey]
foreach ($days as $dayKey => &$cell) {
  foreach ($cell['shift_slices'] as $idx => &$sl) {
    $wk = (string)$sl['week_start_key'];
    if (!isset($slicesByWeek[$wk])) $slicesByWeek[$wk] = [];
    $slicesByWeek[$wk][] = [&$cell['shift_slices'][$idx], $dayKey];
  }
}
unset($cell, $sl);

foreach ($slicesByWeek as $wk => $arr) {
  // total paid for the week
  $weekPaid = 0;
  foreach ($arr as $pair) { $weekPaid += (int)$pair[0]['paid']; }
  $otRemain = ($thresholdMinutes > 0) ? max(0, $weekPaid - $thresholdMinutes) : 0;

  // Sort slices by start time ascending, then allocate OT from the end.
  usort($arr, function($a, $b) {
    /** @var DateTimeImmutable $as */
    $as = $a[0]['start'];
    $bs = $b[0]['start'];
    return $as <=> $bs;
  });

  for ($i = count($arr)-1; $i >= 0; $i--) {
    $sl =& $arr[$i][0];
    $dayKey = $arr[$i][1];
    $paid = (int)$sl['paid'];
    if ($paid <= 0) continue;

    $ot = 0;
    if ($otRemain > 0) {
      $ot = min($paid, $otRemain);
      $otRemain -= $ot;
    }
    $nonOt = $paid - $ot;

    // Assign remaining to BH/weekend/base (BH wins over weekend)
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

    $sl['ot'] = $ot;
    $sl['bh'] = $bh;
    $sl['weekend'] = $we;
    $sl['base'] = $base;

    // Roll up into day totals
    $days[$dayKey]['ot'] += $ot;
    $days[$dayKey]['bh'] += $bh;
    $days[$dayKey]['weekend'] += $we;
    $days[$dayKey]['base'] += $base;
  }
}

admin_page_start($pdo, 'Payroll Calendar (Employee)');
$active = admin_url('payroll-calendar-employee.php');

function badge_html(string $status): string {
  $map = [
    'approved' => 'bg-emerald-500/15 text-black-100 border-emerald-500/30',
    'awaiting' => 'bg-amber-500/15 text-black-100 border-amber-500/30',
    'open' => 'bg-rose-500/15 text-black-100 border-rose-500/30',
  ];
  $cls = $map[$status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
  return '<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold '.$cls.'">'.h(ucfirst($status)).'</span>';
}

?>
<div class="space-y-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-bold">Payroll Calendar (Employee)</h1>
      <div class="mt-1 text-sm text-slate-500">
        Month boundary: <span class="font-semibold text-slate-700"><?= h($monthBoundaryMode) ?></span> ·
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
      <label class="text-xs text-slate-500">Month
        <input name="month" value="<?= h($ym) ?>" class="mt-1 w-32 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="YYYY-MM" />
      </label>
      <label class="text-xs text-slate-500">Employee
        <select name="employee_id" class="mt-1 w-56 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm">
          <?php foreach ($emps as $e): $id=(int)$e['id']; ?>
            <option value="<?= $id ?>" <?= $id===$employeeId?'selected':'' ?>>
              <?= h(($e['last_name']??'') . ', ' . ($e['first_name']??'') . ' (' . ($e['employee_code']??'') . ')') ?>
            </option>
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
      <button class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold">Apply</button>
    </form>
  </div>

  <?php
    // Render weeks
    $week = $gridStartLocal;
    while ($week < $gridEndLocalEx):
      $weekEnd = $week->modify('+7 days');
      // Week totals (from day rollups)
      $wkPaid = 0; $wkBH = 0; $wkWE = 0; $wkOT = 0;
      for ($i=0; $i<7; $i++) {
        $dk = $week->modify("+{$i} days")->format('Y-m-d');
        if (!isset($days[$dk])) continue;
        $wkPaid += (int)$days[$dk]['total_paid'];
        $wkBH += (int)$days[$dk]['bh'];
        $wkWE += (int)$days[$dk]['weekend'];
        $wkOT += (int)$days[$dk]['ot'];
      }
  ?>
    <div class="rounded-3xl border border-slate-200 bg-white p-4">
      <div class="flex items-center justify-between gap-3">
        <div class="font-semibold">
          Week <?= h($week->format('d M Y')) ?> → <?= h($weekEnd->modify('-1 day')->format('d M Y')) ?>
        </div>
        <div class="text-xs text-slate-500">
          <?= ($week < $monthStartLocal || $weekEnd > $monthEndLocalEx) ? '<span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-black-100 font-semibold">Partial month week</span>' : '<span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-slate-700 font-semibold">Full week</span>' ?>
        </div>
      </div>

      <div class="mt-3 grid grid-cols-1 md:grid-cols-7 gap-2">
        <?php for ($i=0; $i<7; $i++):
          $d = $week->modify("+{$i} days");
          $k = $d->format('Y-m-d');
          $cell = $days[$k] ?? null;
          if (!$cell) continue;
          $dim = $cell['in_month'] ? '' : 'opacity-60';
        ?>
          <div class="rounded-2xl border border-slate-200 bg-white p-3 <?= $dim ?>">
            <div class="flex items-start justify-between gap-2">
              <div class="text-sm font-semibold">
                <?= h($d->format('D d M')) ?>
                <?php if ($cell['is_bh']): ?>
                  <span class="ml-1 rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-sky-100">BH</span>
                <?php endif; ?>
              </div>
              <div class="text-xs text-slate-500">
                Hours <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($cell['total_paid'])) ?></span>
              </div>
            </div>

            <div class="mt-2 text-[12px] text-slate-500">
              BH <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['bh'])) ?></span>
              · Weekend <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['weekend'])) ?></span>
              · OT <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['ot'])) ?></span>
            </div>

            <div class="mt-3 space-y-2">
              <?php if (empty($cell['shift_slices'])): ?>
                <div class="text-xs text-slate-900/35">—</div>
              <?php else: ?>
                <?php foreach ($cell['shift_slices'] as $sl): ?>
                  <div class="rounded-xl border border-slate-200 bg-white p-2">
                    <div class="flex items-center justify-between gap-2">
                      <div class="text-xs font-semibold">
                        <?= h($sl['start']->format('H:i')) ?>–<?= h($sl['end']->format('H:i')) ?>
                      </div>
                      <?= badge_html((string)$sl['status']) ?>
                    </div>
                    <div class="mt-1 text-[11px] text-slate-500">
                      Hours <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$sl['paid'])) ?></span>
                      · BH <?= h(payroll_fmt_hhmm((int)$sl['bh'])) ?>
                      · Weekend <?= h(payroll_fmt_hhmm((int)$sl['weekend'])) ?>
                      · OT <?= h(payroll_fmt_hhmm((int)$sl['ot'])) ?>
                    </div>
                    <div class="mt-2 flex gap-2">
                      <a class="rounded-xl bg-white text-slate-900 px-2.5 py-1 text-[11px] font-semibold" href="<?= h(admin_url('shift-edit.php?id='.(int)$sl['shift_id'])) ?>">Fix</a>
                      <a class="rounded-xl bg-slate-50 border border-slate-200 text-slate-700 px-2.5 py-1 text-[11px] font-semibold" href="<?= h(admin_url('shift-view.php?id='.(int)$sl['shift_id'])) ?>">View</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endfor; ?>
      </div>

      <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-3">
        <div class="flex flex-wrap items-center gap-3 text-sm">
          <div class="font-semibold">Week totals</div>
          <div class="text-slate-500">Hours <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkPaid)) ?></span></div>
          <div class="text-slate-500">BH <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkBH)) ?></span></div>
          <div class="text-slate-500">Weekend <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkWE)) ?></span></div>
          <div class="text-slate-500">OT <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm($wkOT)) ?></span></div>
        </div>
      </div>
    </div>
  <?php
    $week = $week->modify('+7 days');
    endwhile;
  ?>
</div>

<?php admin_page_end(); ?>
