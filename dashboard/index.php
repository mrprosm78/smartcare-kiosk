<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

admin_require_perm($user, 'view_dashboard');

$tzName = admin_setting_str($pdo, 'payroll_timezone', 'Europe/London');
$tz = new DateTimeZone($tzName);

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowLocal = $nowUtc->setTimezone($tz);

// Week window (payroll TZ + week start)
$window = payroll_week_window($pdo, $nowLocal);
$weekStartLocal = $window['start_local'];
$weekEndLocalEx = $window['end_local_ex'];
$weekStartUtc = $window['start_utc'];
$weekEndUtcEx = $window['end_utc_ex'];
$weekStartsOn = $window['week_starts_on'];

// Week day buckets (local)
$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = $weekStartLocal->modify('+' . $i . ' days');
  $days[] = [
    'local' => $d,
    'ymd' => $d->format('Y-m-d'),
    'label' => $d->format('D'),
    'label_long' => $d->format('D, d M'),
  ];
}

function fmt_minutes(int $m): string {
  if ($m <= 0) return '0:00';
  $h = intdiv($m, 60);
  $mm = $m % 60;
  return $h . ':' . str_pad((string)$mm, 2, '0', STR_PAD_LEFT);
}

// Devices (heartbeat)
$devices = [];
try {
  $st = $pdo->query("SELECT kiosk_code, last_seen_at, last_seen_kind, last_authorised, last_error_code, last_ip FROM kiosk_devices ORDER BY last_seen_at DESC LIMIT 10");
  $devices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $devices = [];
}
$onlineCutoffUtc = $nowUtc->modify('-5 minutes');
$devicesOnline = 0;
foreach ($devices as $d) {
  $seen = (string)($d['last_seen_at'] ?? '');
  if ($seen === '') continue;
  try {
    $seenUtc = new DateTimeImmutable($seen, new DateTimeZone('UTC'));
    if ($seenUtc >= $onlineCutoffUtc && (int)($d['last_authorised'] ?? 0) === 1) $devicesOnline++;
  } catch (Throwable $e) {
    // ignore
  }
}

// Open shifts
$open = [];
try {
  $st = $pdo->prepare("
    SELECT
      s.employee_id,
      s.clock_in_at,
      e.employee_code,
      e.first_name,
      e.last_name,
      e.nickname,
      d.name AS department_name
    FROM kiosk_shifts s
    LEFT JOIN kiosk_employees e ON e.id = s.employee_id
    LEFT JOIN kiosk_employee_departments d ON d.id = e.department_id
    WHERE s.clock_out_at IS NULL
      AND (s.close_reason IS NULL OR s.close_reason <> 'void')
    ORDER BY s.clock_in_at ASC
  ");
  $st->execute();
  $open = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $open = [];
}

// Shifts needing approval (THIS WEEK)
$need = [
  'unapproved' => 0,
  'autoclosed' => 0,
  'edited' => 0,
];
try {
  $st = $pdo->prepare("
    SELECT
      SUM(CASE WHEN s.clock_out_at IS NOT NULL AND s.approved_at IS NULL THEN 1 ELSE 0 END) AS unapproved,
      SUM(CASE WHEN s.clock_out_at IS NOT NULL AND s.approved_at IS NULL AND s.is_autoclosed=1 THEN 1 ELSE 0 END) AS autoclosed,
      SUM(CASE WHEN s.clock_out_at IS NOT NULL AND s.approved_at IS NULL AND (s.last_modified_reason IS NOT NULL AND s.last_modified_reason <> '') THEN 1 ELSE 0 END) AS edited
    FROM kiosk_shifts s
    WHERE s.clock_in_at >= ? AND s.clock_in_at < ?
      AND (s.close_reason IS NULL OR s.close_reason <> 'void')
  ");
  $st->execute([$weekStartUtc->format('Y-m-d H:i:s'), $weekEndUtcEx->format('Y-m-d H:i:s')]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $need['unapproved'] = (int)($row['unapproved'] ?? 0);
  $need['autoclosed'] = (int)($row['autoclosed'] ?? 0);
  $need['edited'] = (int)($row['edited'] ?? 0);
} catch (Throwable $e) {
  // ignore
}

// Weekly department hours (minutes, split by local day)
$deptDayMinutes = []; // [deptName][ymd] => minutes
$deptTotals = [];     // [deptName] => minutes

try {
  $st = $pdo->prepare("
    SELECT
      s.clock_in_at,
      s.clock_out_at,
      COALESCE(s.paid_minutes, s.duration_minutes, TIMESTAMPDIFF(MINUTE, s.clock_in_at, s.clock_out_at)) AS minutes,
      d.name AS department_name
    FROM kiosk_shifts s
    LEFT JOIN kiosk_employees e ON e.id = s.employee_id
    LEFT JOIN kiosk_employee_departments d ON d.id = e.department_id
    WHERE s.clock_in_at >= ? AND s.clock_in_at < ?
      AND s.clock_out_at IS NOT NULL
      AND (s.close_reason IS NULL OR s.close_reason <> 'void')
  ");
  $st->execute([$weekStartUtc->format('Y-m-d H:i:s'), $weekEndUtcEx->format('Y-m-d H:i:s')]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Precompute day boundaries in UTC for each local day
  $dayBoundsUtc = [];
  foreach ($days as $d) {
    $startLocal = $d['local']->setTime(0, 0, 0);
    $endLocalEx = $startLocal->modify('+1 day');
    $dayBoundsUtc[$d['ymd']] = [
      'start' => $startLocal->setTimezone(new DateTimeZone('UTC')),
      'end' => $endLocalEx->setTimezone(new DateTimeZone('UTC')),
    ];
  }

  foreach ($rows as $r) {
    $dept = trim((string)($r['department_name'] ?? ''));
    if ($dept === '') $dept = 'Unassigned';

    $inUtcStr = (string)($r['clock_in_at'] ?? '');
    $outUtcStr = (string)($r['clock_out_at'] ?? '');
    if ($inUtcStr === '' || $outUtcStr === '') continue;
    try {
      $inUtc = new DateTimeImmutable($inUtcStr, new DateTimeZone('UTC'));
      $outUtc = new DateTimeImmutable($outUtcStr, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
      continue;
    }
    if ($outUtc <= $inUtc) continue;

    foreach ($dayBoundsUtc as $ymd => $b) {
      $segStart = $inUtc > $b['start'] ? $inUtc : $b['start'];
      $segEnd = $outUtc < $b['end'] ? $outUtc : $b['end'];
      if ($segEnd <= $segStart) continue;
      $mins = (int)floor(($segEnd->getTimestamp() - $segStart->getTimestamp()) / 60);
      if ($mins <= 0) continue;
      if (!isset($deptDayMinutes[$dept])) $deptDayMinutes[$dept] = [];
      $deptDayMinutes[$dept][$ymd] = (int)($deptDayMinutes[$dept][$ymd] ?? 0) + $mins;
      $deptTotals[$dept] = (int)($deptTotals[$dept] ?? 0) + $mins;
    }
  }
} catch (Throwable $e) {
  // ignore
}

// Sort departments by configured sort_order, then name
try {
  $cats = $pdo->query("SELECT name FROM kiosk_employee_departments ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $deptOrder = [];
  $i = 0;
  foreach ($cats as $c) { $deptOrder[(string)$c['name']] = $i++; }
  uksort($deptDayMinutes, function($a, $b) use ($deptOrder) {
    $oa = $deptOrder[$a] ?? 9999;
    $ob = $deptOrder[$b] ?? 9999;
    if ($oa === $ob) return strcasecmp($a, $b);
    return $oa <=> $ob;
  });
} catch (Throwable $e) {
  ksort($deptDayMinutes);
}

admin_page_start($pdo, 'Dashboard');
?>

<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Dashboard</h1>
                <p class="mt-2 text-sm text-slate-600">
                  This week (<?= h($weekStartsOn) ?> start):
                  <span class="font-semibold text-slate-900"><?= h($weekStartLocal->format('D, d M')) ?></span>
                  <span class="text-slate-400">→</span>
                  <span class="font-semibold text-slate-900"><?= h($weekEndLocalEx->modify('-1 day')->format('D, d M')) ?></span>
                  <span class="text-slate-400">(Payroll TZ: <?= h($tzName) ?>)</span>
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <a href="<?= h(admin_url('shifts.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800">Weekly Shifts</a>
                <a href="<?= h(admin_url('punch-details.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Punch Details</a>
                <?php if (admin_can($user, 'manage_settings_basic')): ?>
                  <a href="<?= h(admin_url('settings.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Settings</a>
                <?php endif; ?>
              </div>
            </div>
          </header>

          <!-- Stat cards -->
          <section class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-3xl border border-slate-200 bg-white p-5">
              <div class="text-sm text-slate-500">Open shifts now</div>
              <div class="mt-2 text-3xl font-semibold text-slate-900"><?= (int)count($open) ?></div>
              <div class="mt-2 text-sm text-slate-600">Currently clocked in</div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-5">
              <div class="text-sm text-slate-500">Needs approval (this week)</div>
              <div class="mt-2 text-3xl font-semibold text-slate-900"><?= (int)$need['unapproved'] ?></div>
              <div class="mt-2 text-sm text-slate-600">
                <span class="font-semibold"><?= (int)$need['autoclosed'] ?></span> autoclosed ·
                <span class="font-semibold"><?= (int)$need['edited'] ?></span> edited
              </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-5">
              <div class="text-sm text-slate-500">Devices online</div>
              <div class="mt-2 text-3xl font-semibold text-slate-900"><?= (int)$devicesOnline ?></div>
              <div class="mt-2 text-sm text-slate-600">Last 5 minutes</div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-5">
              <div class="text-sm text-slate-500">Week total hours</div>
              <?php
                $allMins = 0;
                foreach ($deptTotals as $m) $allMins += (int)$m;
              ?>
              <div class="mt-2 text-3xl font-semibold text-slate-900"><?= h(fmt_minutes($allMins)) ?></div>
              <div class="mt-2 text-sm text-slate-600">Closed shifts only</div>
            </div>
          </section>

          <!-- Open shifts list -->
          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5 overflow-x-auto">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold">Open shifts</h2>
                <p class="mt-1 text-sm text-slate-600">People currently clocked in (no clock-out yet).</p>
              </div>
              <div class="text-sm text-slate-700"><span class="font-semibold"><?= (int)count($open) ?></span> active</div>
            </div>

            <?php if (!$open): ?>
              <div class="mt-4 text-sm text-slate-500">No one is currently clocked in.</div>
            <?php else: ?>
              <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-xs uppercase tracking-widest text-slate-500">
                    <tr>
                      <th class="text-left py-2 pr-4">Employee</th>
                      <th class="text-left py-2 pr-4">Department</th>
                      <th class="text-left py-2 pr-4">Clocked in (<?= h($tzName) ?>)</th>
                      <th class="text-left py-2 pr-4">Duration</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <?php foreach ($open as $r):
                      $name = admin_employee_display_name($r);
                      $code = trim((string)($r['employee_code'] ?? ''));
                      if ($code !== '') $name = $code . ' — ' . $name;

                      $dept = trim((string)($r['department_name'] ?? ''));
                      if ($dept === '') $dept = 'Unassigned';

                      $inUtc = (string)($r['clock_in_at'] ?? '');
                      $inLocal = '—';
                      $dur = '—';
                      if ($inUtc !== '') {
                        try {
                          $inDtUtc = new DateTimeImmutable($inUtc, new DateTimeZone('UTC'));
                          $inDtLocal = $inDtUtc->setTimezone($tz);
                          $inLocal = $inDtLocal->format('D, d M Y H:i');
                          $mins = (int)floor(($nowUtc->getTimestamp() - $inDtUtc->getTimestamp()) / 60);
                          if ($mins < 0) $mins = 0;
                          $dur = fmt_minutes($mins);
                        } catch (Throwable $e) {
                          $inLocal = $inUtc;
                        }
                      }
                    ?>
                      <tr>
                        <td class="py-3 pr-4 font-semibold text-slate-900"><?= h($name) ?></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($dept) ?></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($inLocal) ?></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($dur) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <!-- Weekly department hours -->
          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5 overflow-x-auto">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold">Department hours (this week)</h2>
                <p class="mt-1 text-sm text-slate-600">Totals by department and day (closed shifts only).</p>
              </div>
            </div>

            <div class="mt-4 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="text-xs uppercase tracking-widest text-slate-500">
                  <tr>
                    <th class="text-left py-2 pr-4">Department</th>
                    <?php foreach ($days as $d): ?>
                      <th class="text-left py-2 pr-4" title="<?= h($d['label_long']) ?>"><?= h($d['label']) ?></th>
                    <?php endforeach; ?>
                    <th class="text-left py-2 pr-4">Total</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <?php if (!$deptDayMinutes): ?>
                    <tr>
                      <td colspan="<?= 2 + count($days) ?>" class="py-4 text-sm text-slate-500">No closed shifts found for this week.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($deptDayMinutes as $dept => $byDay): ?>
                      <tr>
                        <td class="py-3 pr-4 font-semibold text-slate-900"><?= h($dept) ?></td>
                        <?php foreach ($days as $d):
                          $m = (int)($byDay[$d['ymd']] ?? 0);
                        ?>
                          <td class="py-3 pr-4 text-slate-700"><?= h(fmt_minutes($m)) ?></td>
                        <?php endforeach; ?>
                        <td class="py-3 pr-4 font-semibold text-slate-900"><?= h(fmt_minutes((int)($deptTotals[$dept] ?? 0))) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Device status -->
          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5 overflow-x-auto">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold">Kiosk devices</h2>
                <p class="mt-1 text-sm text-slate-600">Online = seen within 5 minutes and authorised (uses /api/kiosk/ping.php).</p>
              </div>
            </div>

            <div class="mt-4 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="text-xs uppercase tracking-widest text-slate-500">
                  <tr>
                    <th class="text-left py-2 pr-4">Kiosk</th>
                    <th class="text-left py-2 pr-4">Status</th>
                    <th class="text-left py-2 pr-4">Last seen (<?= h($tzName) ?>)</th>
                    <th class="text-left py-2 pr-4">Kind</th>
                    <th class="text-left py-2 pr-4">IP</th>
                    <th class="text-left py-2 pr-4">Last error</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <?php if (!$devices): ?>
                    <tr>
                      <td colspan="6" class="py-4 text-sm text-slate-500">No device heartbeats yet.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($devices as $d):
                      $code = (string)($d['kiosk_code'] ?? '');
                      $seen = (string)($d['last_seen_at'] ?? '');
                      $kind = (string)($d['last_seen_kind'] ?? '');
                      $auth = (int)($d['last_authorised'] ?? 0);
                      $err  = (string)($d['last_error_code'] ?? '');
                      $ip   = (string)($d['last_ip'] ?? '');

                      $status = 'Offline';
                      $badge = 'bg-slate-100 text-slate-700';
                      $seenLocal = '—';
                      try {
                        if ($seen !== '') {
                          $seenUtc = new DateTimeImmutable($seen, new DateTimeZone('UTC'));
                          $seenLocal = $seenUtc->setTimezone($tz)->format('D, d M H:i');
                          if ($seenUtc >= $onlineCutoffUtc && $auth === 1) {
                            $status = 'Online';
                            $badge = 'bg-emerald-100 text-emerald-800';
                          } elseif ($seenUtc >= $onlineCutoffUtc && $auth !== 1) {
                            $status = 'Seen (unauthorised)';
                            $badge = 'bg-amber-100 text-amber-800';
                          }
                        }
                      } catch (Throwable $e) {
                        // ignore
                      }
                    ?>
                      <tr>
                        <td class="py-3 pr-4 font-semibold text-slate-900"><?= h($code) ?></td>
                        <td class="py-3 pr-4"><span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold <?= h($badge) ?>"><?= h($status) ?></span></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($seenLocal) ?></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($kind !== '' ? $kind : '—') ?></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($ip !== '' ? $ip : '—') ?></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($err !== '' ? $err : '—') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>

        </main>
</div>

<?php admin_page_end(); ?>
