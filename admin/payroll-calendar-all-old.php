<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

$tzName = (string) setting($pdo, 'payroll_timezone', 'Europe/London');
$weekStartsOn = (string) setting($pdo, 'payroll_week_starts_on', 'monday'); // monday|sunday
$monthBoundaryMode = (string) setting($pdo, 'payroll_month_boundary_mode', 'midnight'); // midnight|end_of_shift
$tz = new DateTimeZone($tzName);

$ym = preg_replace('/[^0-9\-]/', '', (string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}\-\d{2}$/', $ym)) {
  $now = new DateTimeImmutable('now', $tz);
  $ym = $now->format('Y-m');
}
$status = (string)($_GET['status'] ?? 'awaiting'); // all|approved|awaiting|open
$q = trim((string)($_GET['q'] ?? ''));

$monthStartLocal = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
$monthEndLocalEx = $monthStartLocal->modify('first day of next month');
$monthStartUtc = $monthStartLocal->setTimezone(new DateTimeZone('UTC'));
$monthEndUtcEx = $monthEndLocalEx->setTimezone(new DateTimeZone('UTC'));

function week_start_for(DateTimeImmutable $d, string $weekStartsOn): DateTimeImmutable {
  $dow = (int)$d->format('N'); // 1..7
  $startDow = ($weekStartsOn === 'sunday') ? 7 : 1;
  $delta = ($dow - $startDow);
  if ($delta < 0) $delta += 7;
  return $d->setTime(0,0)->modify("-{$delta} days");
}
function dt_min(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable { return ($a <= $b) ? $a : $b; }
function dt_max(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable { return ($a >= $b) ? $a : $b; }

$gridStartLocal = week_start_for($monthStartLocal, $weekStartsOn);
$gridEndLocalEx = week_start_for($monthEndLocalEx->modify('-1 day'), $weekStartsOn)->modify('+7 days');

// Bank holidays set
$bhRows = $pdo->query("SELECT holiday_date FROM payroll_bank_holidays")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$bankHolidays = [];
foreach ($bhRows as $d) { $bankHolidays[(string)$d] = true; }

// Build day buckets
$days = [];
for ($d = $gridStartLocal; $d < $gridEndLocalEx; $d = $d->modify('+1 day')) {
  $key = $d->format('Y-m-d');
  $days[$key] = [
    'date' => $d,
    'in_month' => ($d >= $monthStartLocal && $d < $monthEndLocalEx),
    'is_bh' => isset($bankHolidays[$key]),
    'total_paid' => 0,
    'entries' => [], // each: employee, time, paid, status, shift_id
  ];
}

// Load profiles for paid break flags
$profileRows = $pdo->query("SELECT employee_id, break_is_paid FROM kiosk_employee_pay_profiles")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$breakPaidByEmp = [];
foreach ($profileRows as $r) $breakPaidByEmp[(int)$r['employee_id']] = ((int)$r['break_is_paid']===1);

// Fetch shifts overlapping/within month depending on boundary mode
$where = [];
$params = [];

if ($monthBoundaryMode === 'end_of_shift') {
  $where[] = "s.clock_in_at >= ? AND s.clock_in_at < ?";
  $params[] = $monthStartUtc->format('Y-m-d H:i:s');
  $params[] = $monthEndUtcEx->format('Y-m-d H:i:s');
} else {
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

if ($q !== '') {
  $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "
  SELECT
    s.id, s.employee_id, s.clock_in_at, s.clock_out_at, s.is_autoclosed, s.approved_at,
    e.employee_code, e.first_name, e.last_name
  FROM kiosk_shifts s
  JOIN kiosk_employees e ON e.id = s.employee_id
  WHERE " . implode(" AND ", $where) . "
  ORDER BY s.clock_in_at ASC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$shifts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$utc = new DateTimeZone('UTC');
foreach ($shifts as $s) {
  $inUtc = new DateTimeImmutable((string)$s['clock_in_at'], $utc);
  $outUtc = $s['clock_out_at'] ? new DateTimeImmutable((string)$s['clock_out_at'], $utc) : null;
  $outUtcEff = $outUtc ?: new DateTimeImmutable('now', $utc);

  $inLocal = $inUtc->setTimezone($tz);
  $outLocal = $outUtcEff->setTimezone($tz);

  if ($monthBoundaryMode !== 'end_of_shift') {
    $inLocal = dt_max($inLocal, $monthStartLocal);
    $outLocal = dt_min($outLocal, $monthEndLocalEx);
    if ($outLocal <= $inLocal) continue;
  }

  $worked = (int) floor(($outLocal->getTimestamp() - $inLocal->getTimestamp()) / 60);
  if ($worked <= 0) continue;

  $breakMinutes = payroll_break_minutes_for_worked($pdo, $worked);
  $breakMinus = $breakMinutes;
  $breakPlus = ($breakPaidByEmp[(int)$s['employee_id']] ?? false) ? $breakMinutes : 0;
  $paid = $worked - $breakMinus + $breakPlus;
  if ($paid < 0) $paid = 0;

  $cursor = $inLocal;
  while ($cursor < $outLocal) {
    $dayStart = $cursor->setTime(0,0);
    $dayEnd = $dayStart->modify('+1 day');
    $sliceEnd = dt_min($outLocal, $dayEnd);
    $sliceWorked = (int) floor(($sliceEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
    if ($sliceWorked <= 0) { $cursor = $sliceEnd; continue; }

    $prop = $sliceWorked / $worked;
    $slicePaid = (int) round($paid * $prop);

    $key = $cursor->format('Y-m-d');
    if (isset($days[$key])) {
      $days[$key]['total_paid'] += $slicePaid;

      $statusBadge = $s['approved_at'] ? 'approved' : (($s['clock_out_at'] ? 'awaiting' : 'open'));
      $days[$key]['entries'][] = [
        'shift_id' => (int)$s['id'],
        'employee' => trim(($s['first_name']??'').' '.($s['last_name']??'')),
        'code' => (string)($s['employee_code'] ?? ''),
        'start' => $cursor,
        'end' => $sliceEnd,
        'paid' => $slicePaid,
        'status' => $statusBadge,
        'autoclosed' => ((int)$s['is_autoclosed']===1),
      ];
    }
    $cursor = $sliceEnd;
  }
}

admin_page_start($pdo, 'Payroll Calendar (All)');
$active = admin_url('payroll-calendar-all.php');

function badge_html(string $status): string {
  $map = [
    'approved' => 'bg-emerald-500/15 text-slate-900 border-emerald-500/30',
    'awaiting' => 'bg-amber-500/15 text-slate-900 border-amber-500/30',
    'open' => 'bg-rose-500/15 text-slate-900 border-rose-500/30',
  ];
  $cls = $map[$status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
  return '<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold '.$cls.'">'.h(ucfirst($status)).'</span>';
}

?>
<div class="space-y-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-bold">Payroll Calendar (All Employees)</h1>
      <div class="mt-1 text-sm text-slate-500">
        Month boundary: <span class="font-semibold text-slate-700"><?= h($monthBoundaryMode) ?></span> ·
        Week starts: <span class="font-semibold text-slate-700"><?= h($weekStartsOn) ?></span> ·
        TZ: <span class="font-semibold text-slate-700"><?= h($tzName) ?></span>
      </div>
    </div>
    <form method="get" class="flex flex-wrap gap-2 items-end">
      <label class="text-xs text-slate-500">Month
        <input name="month" value="<?= h($ym) ?>" class="mt-1 w-32 rounded-xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="YYYY-MM" />
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
      <button class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold">Apply</button>
    </form>
  </div>

  <?php
    $week = $gridStartLocal;
    $modalId = 0;
    while ($week < $gridEndLocalEx):
      $weekEnd = $week->modify('+7 days');
  ?>
    <div class="rounded-3xl border border-slate-200 bg-white p-4">
      <div class="flex items-center justify-between gap-3">
        <div class="font-semibold">
          Week <?= h($week->format('d M Y')) ?> → <?= h($weekEnd->modify('-1 day')->format('d M Y')) ?>
        </div>
        <div class="text-xs text-slate-500">
          <?= ($week < $monthStartLocal || $weekEnd > $monthEndLocalEx) ? '<span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-slate-900 font-semibold">Partial month week</span>' : '<span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-slate-700 font-semibold">Full week</span>' ?>
        </div>
      </div>

      <div class="mt-3 grid grid-cols-1 md:grid-cols-7 gap-2">
        <?php for ($i=0; $i<7; $i++):
          $d = $week->modify("+{$i} days");
          $k = $d->format('Y-m-d');
          $cell = $days[$k] ?? null;
          if (!$cell) continue;
          $dim = $cell['in_month'] ? '' : 'opacity-60';

          $entries = $cell['entries'];
          usort($entries, fn($a,$b) => ($b['paid'] <=> $a['paid']));
          $top = array_slice($entries, 0, 5);
          $more = count($entries) - count($top);
          $modalId++;
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
                Paid <span class="font-semibold text-slate-700"><?= h(payroll_fmt_hhmm((int)$cell['total_paid'])) ?></span>
              </div>
            </div>

            <div class="mt-3 space-y-2">
              <?php if (empty($entries)): ?>
                <div class="text-xs text-slate-900/35">—</div>
              <?php else: ?>
                <?php foreach ($top as $it): ?>
                  <div class="rounded-xl border border-slate-200 bg-white p-2">
                    <div class="flex items-center justify-between gap-2">
                      <div class="text-xs font-semibold">
                        <?= h($it['employee']) ?> <span class="text-slate-500 font-normal">(<?= h($it['code']) ?>)</span>
                      </div>
                      <?= badge_html((string)$it['status']) ?>
                    </div>
                    <div class="mt-1 text-[11px] text-slate-500">
                      <?= h($it['start']->format('H:i')) ?>–<?= h($it['end']->format('H:i')) ?> · Paid <?= h(payroll_fmt_hhmm((int)$it['paid'])) ?>
                      <?php if ($it['autoclosed']): ?> · <span class="text-black-200 font-semibold">Auto-closed</span><?php endif; ?>
                    </div>
                    <div class="mt-2">
                      <a class="rounded-xl bg-white text-slate-900 px-2.5 py-1 text-[11px] font-semibold" href="<?= h(admin_url('shift-edit.php?id='.(int)$it['shift_id'])) ?>">Fix</a>
                    </div>
                  </div>
                <?php endforeach; ?>

                <?php if ($more > 0): ?>
                  <button type="button" class="w-full rounded-xl bg-slate-50 border border-slate-200 px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100" onclick="document.getElementById('modal-<?= $modalId ?>').classList.remove('hidden')">
                    + <?= (int)$more ?> more…
                  </button>

                  <div id="modal-<?= $modalId ?>" class="hidden fixed inset-0 z-50">
                    <div class="absolute inset-0 bg-black/70" onclick="document.getElementById('modal-<?= $modalId ?>').classList.add('hidden')"></div>
                    <div class="absolute left-1/2 top-1/2 w-[95vw] max-w-3xl -translate-x-1/2 -translate-y-1/2 rounded-3xl border border-slate-200 bg-white p-4">
                      <div class="flex items-center justify-between gap-3">
                        <div class="font-semibold"><?= h($d->format('D d M Y')) ?> — all shifts</div>
                        <button class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700" onclick="document.getElementById('modal-<?= $modalId ?>').classList.add('hidden')">Close</button>
                      </div>
                      <div class="mt-3 space-y-2 max-h-[70vh] overflow-auto">
                        <?php foreach ($entries as $it): ?>
                          <div class="rounded-2xl border border-slate-200 bg-white p-3">
                            <div class="flex items-center justify-between gap-2">
                              <div class="text-sm font-semibold"><?= h($it['employee']) ?> <span class="text-slate-500 font-normal">(<?= h($it['code']) ?>)</span></div>
                              <?= badge_html((string)$it['status']) ?>
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                              <?= h($it['start']->format('H:i')) ?>–<?= h($it['end']->format('H:i')) ?> · Paid <?= h(payroll_fmt_hhmm((int)$it['paid'])) ?>
                              <?php if ($it['autoclosed']): ?> · <span class="text-black-200 font-semibold">Auto-closed</span><?php endif; ?>
                            </div>
                            <div class="mt-2">
                              <a class="rounded-xl bg-white text-slate-900 px-3 py-2 text-xs font-semibold" href="<?= h(admin_url('shift-edit.php?id='.(int)$it['shift_id'])) ?>">Fix</a>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>

          </div>
        <?php endfor; ?>
      </div>
    </div>
  <?php $week = $week->modify('+7 days'); endwhile; ?>
</div>

<?php admin_page_end(); ?>
