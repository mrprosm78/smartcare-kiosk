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
if ($employeeId > 0) {
  $st = $pdo->prepare("SELECT break_is_paid FROM kiosk_employee_pay_profiles WHERE employee_id=? LIMIT 1");
  $st->execute([$employeeId]);
  $breakIsPaid = ((int)$st->fetchColumn()) === 1;
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
  $days[$key] = [
    'date' => $d,
    'in_month' => ($d >= $monthStartLocal && $d < $monthEndLocalEx),
    'is_bh' => isset($bankHolidays[$key]),
    'total_worked' => 0,
    'break_minus' => 0,
    'break_plus' => 0,
    'total_paid' => 0,
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
        'status' => $statusBadge,
        'flags' => $flags,
      ];
    }

    $cursor = $sliceEnd;
  }
}

admin_page_start($pdo, 'Payroll Calendar (Employee)');
$active = admin_url('payroll-calendar-employee.php');

function badge_html(string $status): string {
  $map = [
    'approved' => 'bg-emerald-500/15 text-emerald-100 border-emerald-500/30',
    'awaiting' => 'bg-amber-500/15 text-amber-100 border-amber-500/30',
    'open' => 'bg-rose-500/15 text-rose-100 border-rose-500/30',
  ];
  $cls = $map[$status] ?? 'bg-white/10 text-white/80 border-white/20';
  return '<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold '.$cls.'">'.h(ucfirst($status)).'</span>';
}

?>
<div class="space-y-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-bold">Payroll Calendar (Employee)</h1>
      <div class="mt-1 text-sm text-white/60">
        Month boundary: <span class="font-semibold text-white/80"><?= h($monthBoundaryMode) ?></span> ·
        Week starts: <span class="font-semibold text-white/80"><?= h($weekStartsOn) ?></span> ·
        TZ: <span class="font-semibold text-white/80"><?= h($tzName) ?></span>
      </div>
    </div>
    <form method="get" class="flex flex-wrap gap-2 items-end">
      <label class="text-xs text-white/60">Month
        <input name="month" value="<?= h($ym) ?>" class="mt-1 w-32 rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm" placeholder="YYYY-MM" />
      </label>
      <label class="text-xs text-white/60">Employee
        <select name="employee_id" class="mt-1 w-56 rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm">
          <?php foreach ($emps as $e): $id=(int)$e['id']; ?>
            <option value="<?= $id ?>" <?= $id===$employeeId?'selected':'' ?>>
              <?= h(($e['last_name']??'') . ', ' . ($e['first_name']??'') . ' (' . ($e['employee_code']??'') . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs text-white/60">Status
        <select name="status" class="mt-1 w-40 rounded-xl bg-white/5 border border-white/10 px-3 py-2 text-sm">
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
  ?>
    <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
      <div class="flex items-center justify-between gap-3">
        <div class="font-semibold">
          Week <?= h($week->format('d M Y')) ?> → <?= h($weekEnd->modify('-1 day')->format('d M Y')) ?>
        </div>
        <div class="text-xs text-white/60">
          <?= ($week < $monthStartLocal || $weekEnd > $monthEndLocalEx) ? '<span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-amber-100 font-semibold">Partial month week</span>' : '<span class="rounded-full border border-white/20 bg-white/10 px-2 py-0.5 text-white/80 font-semibold">Full week</span>' ?>
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
          <div class="rounded-2xl border border-white/10 bg-slate-950/40 p-3 <?= $dim ?>">
            <div class="flex items-start justify-between gap-2">
              <div class="text-sm font-semibold">
                <?= h($d->format('D d M')) ?>
                <?php if ($cell['is_bh']): ?>
                  <span class="ml-1 rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-sky-100">BH</span>
                <?php endif; ?>
              </div>
              <div class="text-xs text-white/60">
                Paid <span class="font-semibold text-white/80"><?= h(payroll_fmt_hhmm($cell['total_paid'])) ?></span>
              </div>
            </div>

            <div class="mt-2 text-[12px] text-white/60">
              Worked <?= h(payroll_fmt_hhmm($cell['total_worked'])) ?>
              · Break − <?= h(payroll_fmt_hhmm($cell['break_minus'])) ?>
              · Break + <?= h(payroll_fmt_hhmm($cell['break_plus'])) ?>
            </div>

            <div class="mt-3 space-y-2">
              <?php if (empty($cell['shift_slices'])): ?>
                <div class="text-xs text-white/35">—</div>
              <?php else: ?>
                <?php foreach ($cell['shift_slices'] as $sl): ?>
                  <div class="rounded-xl border border-white/10 bg-white/5 p-2">
                    <div class="flex items-center justify-between gap-2">
                      <div class="text-xs font-semibold">
                        <?= h($sl['start']->format('H:i')) ?>–<?= h($sl['end']->format('H:i')) ?>
                      </div>
                      <?= badge_html((string)$sl['status']) ?>
                    </div>
                    <div class="mt-1 text-[11px] text-white/60">
                      Paid <?= h(payroll_fmt_hhmm((int)$sl['paid'])) ?> · Break − <?= h(payroll_fmt_hhmm((int)$sl['break_minus'])) ?>
                      <?php if ((int)$sl['break_plus'] > 0): ?> · Break + <?= h(payroll_fmt_hhmm((int)$sl['break_plus'])) ?><?php endif; ?>
                    </div>
                    <div class="mt-2 flex gap-2">
                      <a class="rounded-xl bg-white text-slate-900 px-2.5 py-1 text-[11px] font-semibold" href="<?= h(admin_url('shift-edit.php?id='.(int)$sl['shift_id'])) ?>">Fix</a>
                      <a class="rounded-xl bg-white/10 border border-white/10 text-white/80 px-2.5 py-1 text-[11px] font-semibold" href="<?= h(admin_url('shift-view.php?id='.(int)$sl['shift_id'])) ?>">View</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>
  <?php
    $week = $week->modify('+7 days');
    endwhile;
  ?>
</div>

<?php admin_page_end(); ?>
