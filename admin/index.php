<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

admin_require_perm($user, 'view_dashboard');

$tz = admin_setting_str($pdo, 'payroll_timezone', 'Europe/London');
$weekStartsOn = strtoupper(trim((string)admin_setting_str($pdo, 'payroll_week_starts_on', 'MONDAY')));

function sc_dt_utc(string $ymdHis): DateTimeImmutable {
  return new DateTimeImmutable($ymdHis, new DateTimeZone('UTC'));
}

function sc_month_bounds_utc(DateTimeImmutable $nowUtc): array {
  $start = $nowUtc->modify('first day of this month')->setTime(0,0,0);
  $endEx = $start->modify('+1 month');
  return [$start, $endEx];
}

function sc_last_month_bounds_utc(DateTimeImmutable $nowUtc): array {
  $thisStart = $nowUtc->modify('first day of this month')->setTime(0,0,0);
  $lastStart = $thisStart->modify('-1 month');
  $lastEndEx = $thisStart;
  return [$lastStart, $lastEndEx];
}

function fmt_hhmm_from_minutes(int $mins): string {
  if ($mins < 0) $mins = 0;
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  return sprintf('%02d:%02d', $h, $m);
}

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$todayUtcYmd = $nowUtc->format('Y-m-d');

// Month bounds (UTC)
[$monthStartUtc, $monthEndUtcEx] = sc_month_bounds_utc($nowUtc);
[$lastMonthStartUtc, $lastMonthEndUtcEx] = sc_last_month_bounds_utc($nowUtc);

// Week bounds (payroll tz defines week, then convert to UTC)
$wb = sc_week_bounds_utc($pdo, $tz);
$weekStartUtc = $wb['start_utc'];
$weekEndUtcEx = $wb['end_utc_ex'];
$weekStartLocal = $wb['start_local'];
$weekEndLocalEx = $wb['end_local_ex'];
$weekEndLocal = $weekEndLocalEx->modify('-1 day');

// --------------
// Top cards (THIS MONTH)
// --------------
$counts = [
  'month_shifts' => 0,
  'month_open_shifts' => 0,
  'month_unapproved' => 0,
  'employees' => 0,
];

try {
  // Shifts this month (anchor by clock_in_at)
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM kiosk_shifts
    WHERE clock_in_at >= ? AND clock_in_at < ?
      AND (close_reason IS NULL OR close_reason <> 'void')
  ");
  $st->execute([$monthStartUtc->format('Y-m-d H:i:s'), $monthEndUtcEx->format('Y-m-d H:i:s')]);
  $counts['month_shifts'] = (int)$st->fetchColumn();

  // Open shifts this month (missing in or out) anchored by clock_in_at (exists by schema)
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM kiosk_shifts
    WHERE clock_in_at >= ? AND clock_in_at < ?
      AND (close_reason IS NULL OR close_reason <> 'void')
      AND clock_out_at IS NULL
  ");
  $st->execute([$monthStartUtc->format('Y-m-d H:i:s'), $monthEndUtcEx->format('Y-m-d H:i:s')]);
  $counts['month_open_shifts'] = (int)$st->fetchColumn();

  // Unapproved this month (approved_at NULL)
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM kiosk_shifts
    WHERE clock_in_at >= ? AND clock_in_at < ?
      AND (close_reason IS NULL OR close_reason <> 'void')
      AND approved_at IS NULL
  ");
  $st->execute([$monthStartUtc->format('Y-m-d H:i:s'), $monthEndUtcEx->format('Y-m-d H:i:s')]);
  $counts['month_unapproved'] = (int)$st->fetchColumn();

  // Active employees
  $st = $pdo->query("SELECT COUNT(*) FROM kiosk_employees WHERE is_active = 1");
  $counts['employees'] = (int)$st->fetchColumn();
} catch (Throwable $e) {
  // keep defaults
}

// --------------
// Current week day breakdown (shift counts)
// --------------
$weekDaily = []; // keyed by Y-m-d (local date)
try {
  $st = $pdo->prepare("
    SELECT
      DATE(CONVERT_TZ(clock_in_at,'UTC',?)) AS local_day,
      COUNT(*) AS total_shifts,
      SUM(CASE WHEN (clock_in_at IS NULL OR clock_out_at IS NULL) THEN 1 ELSE 0 END) AS open_shifts,
      SUM(CASE WHEN approved_at IS NULL THEN 1 ELSE 0 END) AS unapproved
    FROM kiosk_shifts
    WHERE clock_in_at >= ? AND clock_in_at < ?
      AND (close_reason IS NULL OR close_reason <> 'void')
    GROUP BY local_day
  ");
  $st->execute([
    $tz,
    $weekStartUtc->format('Y-m-d H:i:s'),
    $weekEndUtcEx->format('Y-m-d H:i:s'),
  ]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $k = (string)$r['local_day'];
    $weekDaily[$k] = [
      'total' => (int)$r['total_shifts'],
      'open' => (int)$r['open_shifts'],
      'unapproved' => (int)$r['unapproved'],
    ];
  }
} catch (Throwable $e) {
  $weekDaily = [];
}

// Build 7-day list in local TZ for display
$weekDays = [];
$cursor = $weekStartLocal;
for ($i=0; $i<7; $i++) {
  $ymd = $cursor->format('Y-m-d');
  $weekDays[] = [
    'ymd' => $ymd,
    'label' => $cursor->format('D, d M'),
    'total' => $weekDaily[$ymd]['total'] ?? 0,
    'open' => $weekDaily[$ymd]['open'] ?? 0,
    'unapproved' => $weekDaily[$ymd]['unapproved'] ?? 0,
  ];
  $cursor = $cursor->modify('+1 day');
}

// --------------
// Last month total hours (closed shifts, exclude void)
// --------------
$lastMonthMinutes = 0;
$lastMonthApprovedMinutes = 0;
try {
  $st = $pdo->prepare("
    SELECT clock_in_at, clock_out_at, approved_at
    FROM kiosk_shifts
    WHERE clock_in_at >= ? AND clock_in_at < ?
      AND clock_out_at IS NOT NULL
      AND (close_reason IS NULL OR close_reason <> 'void')
  ");
  $st->execute([
    $lastMonthStartUtc->format('Y-m-d H:i:s'),
    $lastMonthEndUtcEx->format('Y-m-d H:i:s'),
  ]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $r) {
    $in = (string)$r['clock_in_at'];
    $out = (string)$r['clock_out_at'];
    if ($in === '' || $out === '') continue;

    // minutes diff in UTC
    $mins = (int)floor((strtotime($out) - strtotime($in)) / 60);
    if ($mins < 0) $mins = 0;
    $lastMonthMinutes += $mins;

    if (!empty($r['approved_at'])) {
      $lastMonthApprovedMinutes += $mins;
    }
  }
} catch (Throwable $e) {
  $lastMonthMinutes = 0;
  $lastMonthApprovedMinutes = 0;
}

// --------------
// Last 4 weeks hours by department (closed shifts; exclude void)
// --------------
$deptWeeks = []; // dept => [w0,w1,w2,w3] minutes (w3 oldest .. w0 current)
$deptNames = [];

$weekRanges = [];
// build 4 week ranges in UTC aligned to payroll week
$wStart = $weekStartUtc;
for ($i=0; $i<4; $i++) {
  $start = $wStart->modify("-" . (7*$i) . " days");
  $endEx = $start->modify('+7 days');
  $weekRanges[] = [$start, $endEx];
}
// we want oldest -> newest
$weekRanges = array_reverse($weekRanges);

try {
  // pull all closed shifts in last 4 weeks window
  $windowStart = $weekRanges[0][0];
  $windowEndEx = $weekRanges[3][1];

  $st = $pdo->prepare("
    SELECT
      s.clock_in_at, s.clock_out_at, s.employee_id,
      e.category_id,
      cat.name AS dept_name
    FROM kiosk_shifts s
    LEFT JOIN kiosk_employees e ON e.id = s.employee_id
    LEFT JOIN kiosk_employee_departments cat ON cat.id = e.category_id
    WHERE s.clock_in_at >= ? AND s.clock_in_at < ?
      AND s.clock_out_at IS NOT NULL
      AND (s.close_reason IS NULL OR s.close_reason <> 'void')
  ");
  $st->execute([
    $windowStart->format('Y-m-d H:i:s'),
    $windowEndEx->format('Y-m-d H:i:s'),
  ]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) {
    $dept = trim((string)($r['dept_name'] ?? ''));
    if ($dept === '') $dept = 'Unassigned';
    $deptNames[$dept] = true;

    $in = (string)$r['clock_in_at'];
    $out = (string)$r['clock_out_at'];
    if ($in === '' || $out === '') continue;

    $mins = (int)floor((strtotime($out) - strtotime($in)) / 60);
    if ($mins < 0) $mins = 0;

    // assign to a week bucket
    $t = sc_dt_utc($in);
    $bucket = null;
    for ($i=0; $i<4; $i++) {
      [$a, $b] = $weekRanges[$i];
      if ($t >= $a && $t < $b) { $bucket = $i; break; }
    }
    if ($bucket === null) continue;

    if (!isset($deptWeeks[$dept])) $deptWeeks[$dept] = [0,0,0,0];
    $deptWeeks[$dept][$bucket] += $mins;
  }

  ksort($deptWeeks);
} catch (Throwable $e) {
  $deptWeeks = [];
}

// --------------
// Punch issues (current week) — permission gated
// --------------
$punchErrCount = 0;
$punchErrRows = [];
if (admin_can($user, 'view_punches')) {
  try {
    $st = $pdo->prepare("
      SELECT
        pe.effective_time, pe.received_at, pe.device_time,
        pe.employee_id, pe.action, pe.result_status, pe.error_code,
        e.employee_code, e.first_name, e.last_name, e.nickname
      FROM kiosk_punch_events pe
      LEFT JOIN kiosk_employees e ON e.id = pe.employee_id
      WHERE COALESCE(pe.effective_time, pe.received_at, pe.device_time) >= ?
        AND COALESCE(pe.effective_time, pe.received_at, pe.device_time) < ?
        AND (
          pe.result_status = 'rejected'
          OR (pe.result_status = 'processed' AND pe.error_code IS NOT NULL AND pe.error_code <> '')
        )
      ORDER BY COALESCE(pe.effective_time, pe.received_at, pe.device_time) DESC
      LIMIT 10
    ");
    $st->execute([
      $weekStartUtc->format('Y-m-d H:i:s'),
      $weekEndUtcEx->format('Y-m-d H:i:s'),
    ]);
    $punchErrRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $st2 = $pdo->prepare("
      SELECT COUNT(*)
      FROM kiosk_punch_events pe
      WHERE COALESCE(pe.effective_time, pe.received_at, pe.device_time) >= ?
        AND COALESCE(pe.effective_time, pe.received_at, pe.device_time) < ?
        AND (
          pe.result_status = 'rejected'
          OR (pe.result_status = 'processed' AND pe.error_code IS NOT NULL AND pe.error_code <> '')
        )
    ");
    $st2->execute([
      $weekStartUtc->format('Y-m-d H:i:s'),
      $weekEndUtcEx->format('Y-m-d H:i:s'),
    ]);
    $punchErrCount = (int)$st2->fetchColumn();
  } catch (Throwable $e) {
    $punchErrCount = 0;
    $punchErrRows = [];
  }
}

admin_page_start($pdo, 'Dashboard');
$active = admin_url('index.php');

function badge(string $text, string $kind = 'neutral'): string {
  $base = "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold border";
  if ($kind === 'ok')   return "<span class='$base bg-emerald-500/10 border-emerald-400/20 text-emerald-100'>$text</span>";
  if ($kind === 'warn') return "<span class='$base bg-amber-500/10 border-amber-400/20 text-amber-100'>$text</span>";
  if ($kind === 'bad')  return "<span class='$base bg-rose-500/10 border-rose-400/20 text-rose-100'>$text</span>";
  return "<span class='$base bg-white/5 border border-white/10 text-white/80'>$text</span>";
}
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-8">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Dashboard</h1>
                <p class="mt-2 text-sm text-white/70">
                  Welcome back, <span class="font-semibold text-white/90"><?= h((string)($user['display_name'] ?: $user['username'])) ?></span>.
                  <span class="text-white/40">(UTC: <?= h($todayUtcYmd) ?> • Payroll TZ: <?= h($tz) ?> • Week starts: <?= h($weekStartsOn) ?>)</span>
                </p>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <?php if (admin_can($user, 'manage_settings_basic')): ?>
                  <a href="<?= h(admin_url('settings.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Settings</a>
                <?php endif; ?>
              </div>
            </div>
          </header>

          <?php if ($counts['month_unapproved'] > 0): ?>
            <div class="mt-5 rounded-3xl border border-amber-400/20 bg-amber-500/10 p-5 text-sm text-amber-100">
              <b><?= (int)$counts['month_unapproved'] ?></b> shifts are unapproved this month.
              <a class="underline ml-2" href="<?= h(admin_url('shifts.php?period=this_month&status=unapproved')) ?>">Review now</a>
            </div>
          <?php endif; ?>

          <section class="mt-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Shifts this month</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['month_shifts'] ?></div>
              <div class="mt-2 text-xs text-white/40"><?= h($monthStartUtc->format('01 M Y')) ?> → <?= h($monthEndUtcEx->modify('-1 day')->format('d M Y')) ?> (UTC)</div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Open shifts this month</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['month_open_shifts'] ?></div>
              <div class="mt-2 text-xs text-white/40">Missing IN or OUT</div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Unapproved this month</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['month_unapproved'] ?></div>
              <div class="mt-2 text-xs text-white/40">Awaiting approval</div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Active employees</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['employees'] ?></div>
              <div class="mt-2 text-xs text-white/40">Excludes inactive/archived</div>
            </div>
          </section>

          <section class="mt-5 grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Current week overview</h2>
              <p class="mt-1 text-sm text-white/70">
                <?= h($weekStartLocal->format('D, d M Y')) ?> → <?= h($weekEndLocal->format('D, d M Y')) ?> (<?= h($tz) ?>)
              </p>

              <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-xs uppercase tracking-widest text-white/50">
                    <tr>
                      <th class="text-left py-2 pr-4">Day</th>
                      <th class="text-right py-2 pr-4">Shifts</th>
                      <th class="text-right py-2 pr-4">Open</th>
                      <th class="text-right py-2">Unapproved</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-white/10">
                    <?php foreach ($weekDays as $d): ?>
                      <tr>
                        <td class="py-2 pr-4"><?= h($d['label']) ?></td>
                        <td class="py-2 pr-4 text-right"><?= (int)$d['total'] ?></td>
                        <td class="py-2 pr-4 text-right"><?= (int)$d['open'] ?></td>
                        <td class="py-2 text-right"><?= (int)$d['unapproved'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="mt-4 flex flex-wrap gap-2">
                <a href="<?= h(admin_url('shifts.php?period=this_week&status=open')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white text-slate-900">Open shifts</a>
                <a href="<?= h(admin_url('shifts.php?period=this_week&status=unapproved')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Unapproved</a>
              </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Last month total hours</h2>
              <p class="mt-1 text-sm text-white/70">
                <?= h($lastMonthStartUtc->format('01 M Y')) ?> → <?= h($lastMonthEndUtcEx->modify('-1 day')->format('d M Y')) ?> (closed shifts only)
              </p>

              <div class="mt-4 flex items-baseline gap-6">
                <div>
                  <div class="text-sm text-white/60">Total</div>
                  <div class="mt-1 text-3xl font-semibold"><?= h(fmt_hhmm_from_minutes($lastMonthMinutes)) ?></div>
                </div>
                <div>
                  <div class="text-sm text-white/60">Approved</div>
                  <div class="mt-1 text-2xl font-semibold"><?= h(fmt_hhmm_from_minutes($lastMonthApprovedMinutes)) ?></div>
                </div>
              </div>

              <p class="mt-4 text-xs text-white/40">
                Hours shown are raw shift duration (clock_in_at → clock_out_at). Enhancements and pay are intentionally not calculated here.
              </p>
            </div>
          </section>

          <section class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5 overflow-x-auto">
            <h2 class="text-lg font-semibold">Last 4 weeks — hours by department</h2>
            <p class="mt-1 text-sm text-white/70">Closed shifts only • Excludes voided</p>

            <?php
              // header labels for the 4 weeks (local start date)
              $weekLabels = [];
              foreach ($weekRanges as [$a,$b]) {
                $labLocal = $a->setTimezone(new DateTimeZone($tz))->format('d M');
                $weekLabels[] = $labLocal;
              }
            ?>

            <div class="mt-4 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="text-xs uppercase tracking-widest text-white/50">
                  <tr>
                    <th class="text-left py-2 pr-4">Department</th>
                    <?php foreach ($weekLabels as $wl): ?>
                      <th class="text-right py-2 pr-4">Wk <?= h($wl) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  <?php if (!$deptWeeks): ?>
                    <tr><td colspan="5" class="py-6 text-white/60">No department hours found for last 4 weeks.</td></tr>
                  <?php else: ?>
                    <?php foreach ($deptWeeks as $dept => $minsArr): ?>
                      <tr>
                        <td class="py-2 pr-4 font-semibold"><?= h($dept) ?></td>
                        <?php foreach ($minsArr as $m): ?>
                          <td class="py-2 pr-4 text-right"><?= h(fmt_hhmm_from_minutes((int)$m)) ?></td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <p class="mt-3 text-xs text-white/40">
              Note: this uses raw shift duration. If you later want “paid hours” (after break rules), we can switch the calculation to your payroll engine.
            </p>
          </section>

          <?php if (admin_can($user, 'view_punches')): ?>
            <section class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5 overflow-x-auto">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <h2 class="text-lg font-semibold">Punch issues detected</h2>
                  <p class="mt-1 text-sm text-white/70">Current payroll week • rejected punches and processed warnings</p>
                </div>
                <div class="text-sm">
                  <?= $punchErrCount > 0 ? badge($punchErrCount . ' issues', 'warn') : badge('No issues', 'ok') ?>
                </div>
              </div>

              <?php if (!$punchErrRows): ?>
                <div class="mt-4 text-sm text-white/60">No punch issues found.</div>
              <?php else: ?>
                <div class="mt-4 overflow-x-auto">
                  <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase tracking-widest text-white/50">
                      <tr>
                        <th class="text-left py-2 pr-4">Time</th>
                        <th class="text-left py-2 pr-4">Employee</th>
                        <th class="text-left py-2 pr-4">Action</th>
                        <th class="text-left py-2 pr-4">Status</th>
                        <th class="text-left py-2 pr-4">Error</th>
                        <th class="text-right py-2">Link</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                      <?php foreach ($punchErrRows as $r):
                        $t = (string)($r['effective_time'] ?? '');
                        if ($t === '') $t = (string)($r['received_at'] ?? '');
                        if ($t === '') $t = (string)($r['device_time'] ?? '');
                        $empCode = trim((string)($r['employee_code'] ?? ''));
                        $empName = admin_employee_display_name($r);
                        $empLabel = $empCode !== '' ? ($empCode . ' — ' . $empName) : $empName;

                        $act = (string)($r['action'] ?? '');
                        $st = (string)($r['result_status'] ?? '');
                        $ec = (string)($r['error_code'] ?? '');
                        $empId = (int)($r['employee_id'] ?? 0);
                      ?>
                        <tr>
                          <td class="py-2 pr-4 whitespace-nowrap"><?= h($t !== '' ? admin_fmt_dt($t) : '—') ?></td>
                          <td class="py-2 pr-4"><?= h($empLabel) ?></td>
                          <td class="py-2 pr-4"><?= $act === 'IN' ? badge('IN','ok') : badge('OUT','warn') ?></td>
                          <td class="py-2 pr-4">
                            <?php
                              // Align with kiosk API statuses (received | processed | rejected)
                              $kind = 'neutral';
                              if ($st === 'processed') $kind = 'ok';
                              elseif ($st === 'received') $kind = 'neutral';
                              elseif ($st === 'rejected') $kind = 'bad';
                              elseif ($st !== '') $kind = 'warn';
                              echo $st !== '' ? badge($st, $kind) : badge('—');
                            ?>
                          </td>
                          <td class="py-2 pr-4"><?= h($ec !== '' ? $ec : '—') ?></td>
                          <td class="py-2 text-right">
                            <a class="underline text-white/80"
                               href="<?= h(admin_url('punch-details.php?mode=this_week&employee_id=' . $empId)) ?>">
                              Open
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </section>
          <?php endif; ?>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
