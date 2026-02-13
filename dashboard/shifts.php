<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_shifts');

/**
 * Shifts (Weekly Grid)
 * - Rows: employees
 * - Columns: 7 days of selected payroll week (respects payroll timezone + week start)
 * - Shows approved + unapproved
 * - Bank holidays highlighted
 * - Totals per employee + contract hours
 * - Department summary after grid
 *
 * Old list view preserved as: admin/shifts-list-old.php
 */

function qstr(string $k, string $default=''): string {
  $v = $_GET[$k] ?? $default;
  return is_string($v) ? trim($v) : $default;
}

function is_ymd(string $s): bool {
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

function fmt_local_hhmm(?DateTimeImmutable $d): string {
  if (!$d) return '—';
  return $d->format('H:i');
}




// ----------------------
// Week selection
// ----------------------
$tzName = payroll_timezone($pdo);
$tz = new DateTimeZone($tzName);
$weekParam = qstr('week', ''); // YYYY-MM-DD (any date inside the desired week)

$anyLocal = null;
if ($weekParam !== '' && is_ymd($weekParam)) {
  try {
    // Use midday in payroll timezone to avoid DST edge cases.
    $anyLocal = new DateTimeImmutable($weekParam . ' 12:00:00', $tz);
  } catch (Throwable $e) {
    $anyLocal = null;
  }
}
if (!$anyLocal) {
  $anyLocal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($tz);
}

$window = payroll_week_window($pdo, $anyLocal);
$weekStartLocal = $window['start_local'];
$weekEndLocalEx = $window['end_local_ex'];
$weekStartUtc = $window['start_utc'];
$weekEndUtcEx = $window['end_utc_ex'];
$weekStartsOn = $window['week_starts_on'];

$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = $weekStartLocal->modify('+' . $i . ' days');
  $days[] = [
    'local' => $d,
    'ymd' => $d->format('Y-m-d'),
    'label' => $d->format('D') . "\n" . $d->format('d M'),
  ];
}

$bh = payroll_bank_holidays($pdo, $days[0]['ymd'], $days[6]['ymd']);

// ----------------------
// Employees
// ----------------------
$status = qstr('status', 'active'); // active|inactive|all
$cat = (int)($_GET['cat'] ?? 0);
$q = qstr('q', '');
$hideEmpty = !isset($_GET['hide_empty']) || (string)$_GET['hide_empty'] === '1';


$cats = $pdo->query("SELECT id, name FROM hr_staff_departments ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$where = [];
$params = [];
if ($status === 'active') {
  $where[] = 'e.is_active = 1';
} elseif ($status === 'inactive') {
  $where[] = 'e.is_active = 0';
}
if ($cat > 0) {
  $where[] = 'e.department_id = ?';
  $params[] = $cat;
}
if ($q !== '') {
  $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.nickname LIKE ? OR e.employee_code LIKE ? OR e.agency_label LIKE ?)';
  for ($i = 0; $i < 5; $i++) $params[] = '%' . $q . '%';
}

$sqlEmp = "SELECT e.id, e.first_name, e.last_name, e.nickname, e.employee_code, e.is_active, e.is_agency,
                 e.department_id, d.name AS department_name
          FROM kiosk_employees e
          LEFT JOIN hr_staff_departments d ON d.id = e.department_id";
if ($where) $sqlEmp .= ' WHERE ' . implode(' AND ', $where);
$sqlEmp .= ' ORDER BY e.is_active DESC, e.is_agency ASC, e.first_name ASC, e.last_name ASC LIMIT 500';

$stEmp = $pdo->prepare($sqlEmp);
$stEmp->execute($params);
$employees = $stEmp->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Attach contract flags from HR Staff contract (source of truth) for display.
foreach ($employees as &$e) {
  $eid = (int)($e['id'] ?? 0);
  if ($eid <= 0) continue;
  $p = payroll_employee_profile($pdo, $eid);
  $e['contract_hours_per_week'] = (float)($p['contract_hours_per_week'] ?? 0);
  $e['break_is_paid'] = (bool)($p['break_is_paid'] ?? false);
}
unset($e);

// Sort employees by Department (dept sort_order/name) then display name
$deptOrder = [];
$di = 0;
foreach ($cats as $c) { $deptOrder[(int)$c['id']] = $di++; }
$displayName = function(array $e): string {
  if ((int)($e['is_agency'] ?? 0) === 1) {
    $label = trim((string)($e['agency_label'] ?? ''));
    return $label !== '' ? $label : 'Agency';
  }
  $nick = trim((string)($e['nickname'] ?? ''));
  if ($nick !== '') return $nick;
  $fn = trim((string)($e['first_name'] ?? ''));
  $ln = trim((string)($e['last_name'] ?? ''));
  $n = trim($fn . ' ' . $ln);
  return $n !== '' ? $n : '—';
};

usort($employees, function($a,$b) use ($deptOrder,$displayName) {
  $da = (int)($a['department_id'] ?? 0);
  $db = (int)($b['department_id'] ?? 0);
  $oa = $deptOrder[$da] ?? 9999;
  $ob = $deptOrder[$db] ?? 9999;
  if ($oa !== $ob) return $oa <=> $ob;
  $na = mb_strtolower($displayName($a));
  $nb = mb_strtolower($displayName($b));
  if ($na !== $nb) return $na <=> $nb;
  return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});


$employeeIds = array_map(fn($r) => (int)$r['id'], $employees);

// ----------------------
// Load shifts for week
// ----------------------
$shifts = [];
if ($employeeIds) {
  $in = implode(',', array_fill(0, count($employeeIds), '?'));

  // IMPORTANT RULE (LOCKED): shifts are anchored to the START (clock-in) local date.
  // A shift that crosses midnight belongs entirely to its start day/week/month.
  // Therefore, the weekly grid only loads shifts whose clock_in_at falls within this week.
  $sqlShift = "SELECT s.*, c.new_json AS latest_edit_json
               FROM kiosk_shifts s
               LEFT JOIN (
                 SELECT sc1.*
                 FROM kiosk_shift_changes sc1
                 JOIN (
                   SELECT shift_id, MAX(id) AS max_id
                   FROM kiosk_shift_changes
                   WHERE change_type='edit'
                   GROUP BY shift_id
                 ) sc2 ON sc2.max_id = sc1.id
               ) c ON c.shift_id = s.id
               WHERE s.employee_id IN ($in)
                 AND s.clock_in_at >= ?
                 AND s.clock_in_at < ?
                 AND (s.close_reason IS NULL OR s.close_reason <> 'void')
               ORDER BY s.clock_in_at ASC";

  $paramsShift = $employeeIds;
  $paramsShift[] = $weekStartUtc->format('Y-m-d H:i:s');
  $paramsShift[] = $weekEndUtcEx->format('Y-m-d H:i:s');

  $st = $pdo->prepare($sqlShift);
  $st->execute($paramsShift);
  $shifts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Index shifts by employee -> day
$cell = []; // [empId][ymd] => list of blocks
$dayTotals = []; // [empId][ymd] => minutes
$weekTotals = []; // [empId] => minutes
$deptTotals = []; // [dept] => minutes

// Build a quick employee map for break_is_paid + contract hours + dept
$empMap = [];
foreach ($employees as $e) {
  $empMap[(int)$e['id']] = $e;
}

foreach ($shifts as $s) {
  $empId = (int)$s['employee_id'];
  if (!isset($empMap[$empId])) continue;
  $emp = $empMap[$empId];

  $eff = admin_shift_effective($s);
  $cin = trim((string)$eff['clock_in_at']);
  $cout = trim((string)$eff['clock_out_at']);
  if ($cin === '') continue;

  try {
    $startUtc = new DateTimeImmutable($cin, new DateTimeZone('UTC'));
  } catch (Throwable $e) {
    continue;
  }

  $hasOut = ($cout !== '');
  $endUtc = null;
  if ($hasOut) {
    try {
      $endUtc = new DateTimeImmutable($cout, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
      $endUtc = null;
      $hasOut = false;
    }
  }
  // Only show CLOSED shifts in this grid (open shifts are excluded, especially for "today")
  if (!$hasOut || !$endUtc) {
    continue;
  }

  // Work minutes are always based on the full shift (no midnight splitting in this view).
  $shiftWorkedFull = max(0, (int)floor(($endUtc->getTimestamp() - $startUtc->getTimestamp()) / 60));

  $breakMinutes = null;
  if ($eff['break_minutes'] !== null) {
    $breakMinutes = (int)$eff['break_minutes'];
  } elseif ($s['break_minutes'] !== null) {
    $breakMinutes = (int)$s['break_minutes'];
  } elseif ($shiftWorkedFull !== null) {
    $breakMinutes = payroll_break_minutes_for_worked($pdo, $shiftWorkedFull);
  } else {
    $breakMinutes = 0;
  }

  $breakIsPaid = ((int)($emp['break_is_paid'] ?? 0) === 1);

  // Determine which day this shift belongs to (START day in payroll timezone).
  $startLocal = $startUtc->setTimezone($tz);
  $endLocal = $endUtc->setTimezone($tz);
  $ymd = $startLocal->format('Y-m-d');

  // Ensure day is in our header list (it should be, because we filtered by week clock_in_at).
  $isHeaderDay = false;
  foreach ($days as $d) {
    if ($d['ymd'] === $ymd) { $isHeaderDay = true; break; }
  }
  if (!$isHeaderDay) continue;

  $breakDeduct = (!$breakIsPaid ? max(0, (int)$breakMinutes) : 0);
  $netMins = max(0, $shiftWorkedFull - $breakDeduct);

  $actual = fmt_local_hhmm($startLocal) . '–' . fmt_local_hhmm($endLocal);
  if ($endLocal->format('Y-m-d') !== $ymd) {
    $actual .= ' (+1)';
  }

  $isAutoclosed = ((int)($s['is_autoclosed'] ?? 0) === 1);
  $closeReason = trim((string)($s['close_reason'] ?? ''));
  $isEdited = !empty($s['latest_edit_json']);
  $needsFix = $isAutoclosed || ($closeReason !== '' && $closeReason !== '0') || $isEdited;

  $fixUrl = admin_url('shift-edit.php?id=' . (int)$s['id']);
  $viewUrl = admin_url('punch-details.php?mode=custom&employee_id=' . $empId . '&from=' . rawurlencode($ymd) . '&to=' . rawurlencode($ymd));

  $cell[$empId][$ymd] = $cell[$empId][$ymd] ?? [];
  $cell[$empId][$ymd][] = [
    'shift_id' => (int)$s['id'],
    'actual' => $actual,
    'net_mins' => $netMins,
    'needs_fix' => $needsFix,
    'fix_url' => $fixUrl,
    'view_url' => $viewUrl,
  ];

  $dayTotals[$empId][$ymd] = ($dayTotals[$empId][$ymd] ?? 0) + $netMins;
  $weekTotals[$empId] = ($weekTotals[$empId] ?? 0) + $netMins;
}

// Build department totals based on weekTotals
foreach ($employees as $e) {
  $empId = (int)$e['id'];
  if ($hideEmpty && (int)($weekTotals[$empId] ?? 0) === 0) continue;
  $dept = (string)($e['department_name'] ?? '—');
  if ($dept === '') $dept = '—';
  $deptTotals[$dept] = ($deptTotals[$dept] ?? 0) + (int)($weekTotals[$empId] ?? 0);
}

// Build running totals (cumulative from week start through each day)
// Example (Sunday start): Sun 7.5, Tue 7.5 => Tue running total 15.0
$runningTotals = []; // [empId][ymd] => minutes
foreach ($employees as $e) {
  $empId = (int)$e['id'];
  $cum = 0;
  foreach ($days as $d) {
    $ymd = $d['ymd'];
    $cum += (int)($dayTotals[$empId][$ymd] ?? 0);
    $runningTotals[$empId][$ymd] = $cum;
  }
}

// ----------------------
// Render
// ----------------------
$weekTitle = $weekStartLocal->format('d M Y') . ' – ' . $weekStartLocal->modify('+6 days')->format('d M Y');

// Week picker options (week start dates around current week)
$weekOptions = [];
for ($i = -12; $i <= 12; $i++) {
  $ws = $weekStartLocal->modify(($i * 7) . ' days');
  $weekOptions[] = [
    'value' => $ws->format('Y-m-d'),
    'label' => $ws->format('d M Y') . ' – ' . $ws->modify('+6 days')->format('d M Y'),
  ];
}

admin_page_start($pdo, 'Shifts');
$active = admin_url('shifts.php');
?>

<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Shifts</h1>
                <p class="mt-2 text-sm text-slate-600">Weekly grid view (approved + unapproved). Week start: <span class="font-semibold"><?= h($weekStartsOn) ?></span> · Timezone: <span class="font-semibold"><?= h($tzName) ?></span></p>
                <p class="mt-1 text-sm text-slate-600">Week: <span class="font-semibold"><?= h($weekTitle) ?></span></p>
              </div>
              <div class="flex flex-wrap gap-2">
                <?php
                  $prev = $weekStartLocal->modify('-7 days')->format('Y-m-d');
                  $next = $weekStartLocal->modify('+7 days')->format('Y-m-d');
                  $qsBase = $_GET;
                  $qsPrev = $qsBase; $qsPrev['week'] = $prev;
                  $qsNext = $qsBase; $qsNext['week'] = $next;

                  // Shift editor link: prefill with this displayed week.
                  $qsEdit = [
                    'from' => $weekStartLocal->format('Y-m-d'),
                    'to'   => $weekStartLocal->modify('+6 days')->format('Y-m-d'),
                  ];
                ?>
                <a href="<?= h(admin_url('shift-editor.php?' . http_build_query($qsEdit))) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-500/15 border border-emerald-500/30 text-slate-900 hover:bg-emerald-500/20">Edit shifts</a>
                <a href="<?= h(admin_url('shifts.php?' . http_build_query($qsPrev))) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">← Prev week</a>
                <a href="<?= h(admin_url('shifts.php?' . http_build_query($qsNext))) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Next week →</a>
              </div>
            </div>

            
            <form id="shiftFilters" method="get" class="mt-4 grid grid-cols-1 md:grid-cols-12 gap-3">
              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600">Week starting</label>
                <select name="week" id="week" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <?php foreach ($weekOptions as $opt): ?>
                    <option value="<?= h($opt['value']) ?>" <?= ($opt['value'] === $weekStartLocal->format('Y-m-d')) ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600">Search</label>
                <input name="q" id="q" value="<?= h($q) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Name, code, nickname">
              </div>

              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600">Department</label>
                <select name="cat" id="cat" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="0">All</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $cat) ? 'selected' : '' ?>><?= h((string)$c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="md:col-span-1">
                <label class="block text-xs font-semibold text-slate-600">Status</label>
                <select name="status" id="status" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
                  <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
                  <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
                </select>
              </div>

              <div class="md:col-span-1">
                <label class="block text-xs font-semibold text-slate-600">Hide empty</label>
                <label class="mt-1 inline-flex items-center gap-2 text-sm">
  <input type="hidden" name="hide_empty" value="0" />
  <input type="checkbox" name="hide_empty" value="1" <?= $hideEmpty ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300" />
  <span class="text-slate-700">Yes</span>
</label>
              </div>

              <div class="md:col-span-1 flex items-end">
                <a href="<?= h(admin_url('shifts.php')) ?>" class="w-full text-center rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Clear</a>
              </div>
            </form>

          </header>

          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-4">
            <div class="overflow-auto">
              <table class="min-w-[980px] w-full rounded-2xl border border-slate-200 table-fixed text-sm border-separate border-spacing-0 border border-slate-200">
                <thead>
                  <tr>
                    <th class="sticky left-0 z-10 bg-white border-b border-slate-200 p-2 text-left w-[220px]">Employee</th>
                    <?php foreach ($days as $d):
                      $isBH = array_key_exists($d['ymd'], $bh);
                      $thCls = $isBH ? 'bg-amber-50' : 'bg-white';
                    ?>
                      <th class="border-b border-slate-200 p-2 text-left min-w-[105px] max-w-[120px] <?= $thCls ?>">
                        <div class="font-semibold"><?= h($d['label']) ?></div>
                        <?php if ($isBH): ?>
                          <div class="mt-1 text-[11px] font-semibold text-amber-800">BH</div>
                        <?php endif; ?>
                      </th>
                    <?php endforeach; ?>
                                      </tr>
                </thead>
                <tbody>
                  <?php if (!$employees): ?>
                    <tr><td colspan="8" class="p-6 text-slate-600">No employees match this filter.</td></tr>
                  <?php endif; ?>

                  <?php $lastDept = null; foreach ($employees as $e):
                    $empId = (int)$e['id'];
                    $name = trim((string)($e['first_name'] ?? '') . ' ' . (string)($e['last_name'] ?? ''));
                    if ($name === '') $name = (string)($e['nickname'] ?? '');
                    $code = (string)($e['employee_code'] ?? '');
                    $dept = (string)($e['department_name'] ?? '—');
                    $contractH = (float)($e['contract_hours_per_week'] ?? 0);
                    $totalM = (int)($weekTotals[$empId] ?? 0);
                    $totalH = $totalM / 60;
                    $varH = $totalH - $contractH;

                    if ($hideEmpty && $totalM === 0) {
                      continue;
                    }
                    $deptLabel = (string)($e['department_name'] ?? '—');
                    if ($deptLabel === '') $deptLabel = '—';
                    if ($lastDept !== $deptLabel) {
                      $lastDept = $deptLabel;
                      echo '<tr class="bg-slate-50"><td colspan="8" class="px-2 py-2 text-xs font-semibold text-slate-700 border-b border-slate-200">' . h($deptLabel) . '</td></tr>';
                    }

                  ?>
                    <tr class="align-top">
                      <td class="sticky left-0 z-10 bg-white border-b border-slate-200 p-2 w-[220px]">
                        <a href="<?= h(admin_url('payroll-calendar-employee.php')) ?>?id=<?= (int)$empId ?>" class="font-semibold text-slate-900 hover:underline"><?= h($name) ?></a>
                        <div class="mt-1 text-[11px] text-slate-600"><?= h($code !== '' ? $code : '—') ?> · <?= h($dept !== '' ? $dept : '—') ?></div>
                      </td>

                      <?php foreach ($days as $d):
                        $ymd = $d['ymd'];
                        $isBH = array_key_exists($ymd, $bh);
                        $tdCls = $isBH ? 'bg-amber-50/70' : 'bg-white';
                        $blocks = $cell[$empId][$ymd] ?? [];
                        $dayM = (int)($dayTotals[$empId][$ymd] ?? 0);
                      ?>
                        <td class="border-b border-slate-200 p-1 align-top min-w-[105px] max-w-[120px] <?= $tdCls ?>">
                          <?php if ($blocks): ?>
                            <div class="flex flex-col gap-1">
                              <?php
                                // Running total up to this day (cumulative from week start)
                                $totalMins = (int)($runningTotals[$empId][$ymd] ?? 0);
                                $contractHours = (float)($e['contract_hours_per_week'] ?? 0);
                                $contractMins = (int)round($contractHours * 60);
                                $over = ($contractMins > 0 && $totalMins > $contractMins);
                                $weekCls = $over ? 'text-red-700' : 'text-green-700';
                              ?>
                              <?php foreach ($blocks as $b): ?>
                                <div class="rounded-md border bg-white px-1.5 py-1 text-xs shadow-sm">
                                  <div class="flex items-center gap-2">
                                    <div class="truncate font-semibold text-slate-900">
                                      <?= h((string)$b['actual']) ?>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                      <a href="<?= h((string)$b['view_url']) ?>" class="text-blue-600 hover:underline text-xs font-semibold">View</a>
                                      <?php if (!empty($b['needs_fix'])): ?>
                                        <a href="<?= h((string)$b['fix_url']) ?>" class="text-red-600 hover:underline text-xs font-semibold">Fix</a>
                                      <?php endif; ?>
                                    </div>
                                  </div>
                                  <div class="flex items-center gap-2 pt-1 text-[11px]">
                                    <div class="text-slate-700 font-semibold">
                                      <?= h(payroll_fmt_hhmm((int)$b['net_mins'])) ?> <span class="text-slate-400 font-semibold">(-b)</span>
                                    </div>
                                    <div class="<?= $weekCls ?> font-semibold">
                                      <?= h(payroll_fmt_hhmm($totalMins)) ?><span class="text-slate-400 font-semibold">(t)</span>
                                    </div>
                                  </div>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php else: ?>
                            <div class="text-[11px] text-slate-500">—</div>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Department totals (week)</h2>
            <p class="mt-1 text-sm text-slate-600">Sum of net hours after breaks across employees in this week view.</p>

            <div class="mt-4 overflow-auto">
              <table class="min-w-[520px] w-full text-sm">
                <thead>
                  <tr>
                    <th class="text-left p-2 border-b border-slate-200">Department</th>
                    <th class="text-right p-2 border-b border-slate-200">Hours</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    ksort($deptTotals);
                    foreach ($deptTotals as $deptName => $mins):
                  ?>
                    <tr>
                      <td class="p-2 border-b border-slate-100"><?= h($deptName) ?></td>
                      <td class="p-2 border-b border-slate-100 text-right font-semibold"><?= h(payroll_fmt_hhmm((int)$mins)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>

                  </main>
</div>


<script>
  (function(){
    const form = document.getElementById('shiftFilters');
    if(!form) return;
    const submitNow = () => form.submit();
    form.querySelectorAll('select,input[type=checkbox]').forEach(el => {
      el.addEventListener('change', submitNow);
    });
    const q = document.getElementById('q');
    if(q){
      let tmr = null;
      q.addEventListener('input', () => {
        if(tmr) clearTimeout(tmr);
        tmr = setTimeout(() => form.submit(), 300);
      });
    }
  })();
</script>

<?php admin_page_end(); ?>
