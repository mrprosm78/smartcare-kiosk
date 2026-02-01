<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_shifts');

// ---------------------------------------------------------------------
// Shift Editor (All Shifts)
// Single page for finding + editing shifts (open + closed) with filters.
// NOTE: This is UI-first. Save is standard POST for now; can be converted
// to AJAX later without changing the layout.
// ---------------------------------------------------------------------

function q(string $k, string $default = ''): string {
  $v = $_GET[$k] ?? $default;
  return is_string($v) ? trim($v) : $default;
}

function is_ymd(string $s): bool {
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

function is_datetime_local(string $s): bool {
  // HTML datetime-local: YYYY-MM-DDTHH:MM
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $s);
}

function dt_utc_to_local_input(?string $utcDt, DateTimeZone $tz): string {
  if (!$utcDt) return '';
  try {
    $d = new DateTimeImmutable($utcDt, new DateTimeZone('UTC'));
    return $d->setTimezone($tz)->format('Y-m-d\TH:i');
  } catch (Throwable $e) {
    return '';
  }
}

function local_input_to_utc(?string $localInput, DateTimeZone $tz): ?string {
  $localInput = is_string($localInput) ? trim($localInput) : '';
  if ($localInput === '') return null;
  if (!is_datetime_local($localInput)) return null;
  try {
    $dLocal = new DateTimeImmutable(str_replace('T', ' ', $localInput) . ':00', $tz);
    return $dLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

function minutes_to_hhmm(int $mins): string {
  $mins = max(0, $mins);
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  return sprintf('%d:%02d', $h, $m);
}

$tzName = payroll_timezone($pdo);
$tz = new DateTimeZone($tzName);

// Defaults: last 7 days
$todayLocal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($tz);
$defaultTo = $todayLocal->format('Y-m-d');
$defaultFrom = $todayLocal->modify('-6 days')->format('Y-m-d');

$from = q('from', $defaultFrom);
$to   = q('to', $defaultTo);
if (!is_ymd($from)) $from = $defaultFrom;
if (!is_ymd($to))   $to   = $defaultTo;

$employeeId = (int)($_GET['employee_id'] ?? 0);
$deptId = (int)($_GET['dept_id'] ?? 0);
$status = q('status', 'needs_review'); // needs_review|approved|open|all
// Keep duration support (legacy/advanced), but do not show by default.
$duration = q('duration', 'all'); // all|lt1|1_4|4_8|8_12|gt12

$selectedShiftId = (int)($_GET['shift_id'] ?? 0);

// Month calendar (for review/approval workflow)
$monthParam = q('month', $todayLocal->format('Y-m')); // YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
  $monthParam = $todayLocal->format('Y-m');
}

$flash = '';

// ---------------------------------------------------------------------
// Calendar: count days that have shifts needing review/approval.
// Definition (simple + safe): a shift needs review if it is open OR not approved OR autoclosed.
// Uses your locked anchoring rule: day is the LOCAL date of clock_in_at.
// ---------------------------------------------------------------------
$calCounts = []; // [ymd => count]
try {
  $calMonthStartLocal = new DateTimeImmutable($monthParam . '-01 00:00:00', $tz);
  $calMonthEndLocalEx = $calMonthStartLocal->modify('first day of next month');
  $calMonthStartUtc = $calMonthStartLocal->setTimezone(new DateTimeZone('UTC'));
  $calMonthEndUtcEx = $calMonthEndLocalEx->setTimezone(new DateTimeZone('UTC'));

  $where = [
    's.clock_in_at >= ? AND s.clock_in_at < ?',
    '(s.close_reason IS NULL OR s.close_reason <> \'void\')',
  ];
  $params = [
    $calMonthStartUtc->format('Y-m-d H:i:s'),
    $calMonthEndUtcEx->format('Y-m-d H:i:s'),
  ];

  if ($employeeId > 0) {
    $where[] = 's.employee_id = ?';
    $params[] = $employeeId;
  }
  if ($deptId > 0) {
    // Join employees for dept filter
    $where[] = 'e.department_id = ?';
    $params[] = $deptId;
  }

  $sql = "SELECT s.clock_in_at, s.clock_out_at, s.approved_at, s.is_autoclosed
          FROM kiosk_shifts s
          JOIN kiosk_employees e ON e.id = s.employee_id
          WHERE " . implode(' AND ', $where);

  $stCal = $pdo->prepare($sql);
  $stCal->execute($params);
  $rows = $stCal->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) {
    $cin = (string)($r['clock_in_at'] ?? '');
    if ($cin === '') continue;
    try {
      $startLocal = (new DateTimeImmutable($cin, new DateTimeZone('UTC')))->setTimezone($tz);
    } catch (Throwable $e) {
      continue;
    }
    $ymd = $startLocal->format('Y-m-d');

    $needsReview = false;
    if (empty($r['clock_out_at'])) $needsReview = true; // open
    if (empty($r['approved_at'])) $needsReview = true; // unapproved
    if ((int)($r['is_autoclosed'] ?? 0) === 1) $needsReview = true;

    if ($needsReview) {
      $calCounts[$ymd] = ($calCounts[$ymd] ?? 0) + 1;
    }
  }
} catch (Throwable $e) {
  // Calendar is a convenience only; never break the editor.
  $calCounts = [];
}

// Calendar render helpers
$calWeekStartsOn = strtoupper(trim(payroll_week_starts_on($pdo)));
$calWeekStartDow = [
  'SUNDAY' => 0,
  'MONDAY' => 1,
  'TUESDAY' => 2,
  'WEDNESDAY' => 3,
  'THURSDAY' => 4,
  'FRIDAY' => 5,
  'SATURDAY' => 6,
][$calWeekStartsOn] ?? 1;

// ---------------------------------------------------------------------
// Save (POST)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['_csrf'] ?? null);

  $sid = (int)($_POST['shift_id'] ?? 0);
  $mode = (string)($_POST['mode'] ?? 'edit'); // edit|add

  // Collect inputs
  $inLocal  = (string)($_POST['clock_in_local'] ?? '');
  $outLocal = (string)($_POST['clock_out_local'] ?? '');
  $isCallout = (int)($_POST['is_callout'] ?? 0) === 1 ? 1 : 0;
  $trainingMins = (int)($_POST['training_minutes'] ?? 0);
  if ($trainingMins < 0) $trainingMins = 0;
  $trainingNote = trim((string)($_POST['training_note'] ?? ''));
  if (strlen($trainingNote) > 255) $trainingNote = substr($trainingNote, 0, 255);

  $clockInUtc = local_input_to_utc($inLocal, $tz);
  $clockOutUtc = local_input_to_utc($outLocal, $tz);

  // For OPEN shifts, allow blank clock_out_at.
  // For CLOSED shifts, clock_out_at must be present.

  if ($mode === 'add') {
    $emp = (int)($_POST['employee_id'] ?? 0);
    if ($emp <= 0 || !$clockInUtc) {
      $flash = 'Missing employee or clock-in time.';
    } else {
      $isClosed = $clockOutUtc ? 1 : 0;
      $durationMinutes = null;
      if ($clockOutUtc) {
        try {
          $d1 = new DateTimeImmutable($clockInUtc, new DateTimeZone('UTC'));
          $d2 = new DateTimeImmutable($clockOutUtc, new DateTimeZone('UTC'));
          $durationMinutes = max(0, (int)round(($d2->getTimestamp() - $d1->getTimestamp()) / 60));
        } catch (Throwable $e) {
          $durationMinutes = null;
        }
      }

      $ins = $pdo->prepare("INSERT INTO kiosk_shifts (employee_id, clock_in_at, clock_out_at, is_closed, is_autoclosed, close_reason, is_callout, training_minutes, training_note, duration_minutes, last_modified_reason, updated_source)
                            VALUES (?, ?, ?, ?, 0, NULL, ?, ?, ?, ?, 'manual_add', 'admin')");
      $ins->execute([
        $emp,
        $clockInUtc,
        $clockOutUtc,
        $isClosed,
        $isCallout,
        $trainingMins ?: null,
        $trainingNote !== '' ? $trainingNote : null,
        $durationMinutes,
      ]);
      $newId = (int)$pdo->lastInsertId();

      // Log change
      $newRow = $pdo->prepare('SELECT * FROM kiosk_shifts WHERE id=? LIMIT 1');
      $newRow->execute([$newId]);
      $newShift = $newRow->fetch(PDO::FETCH_ASSOC) ?: null;
      $log = $pdo->prepare("INSERT INTO kiosk_shift_changes (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, old_json, new_json)
                            VALUES (?, 'edit', ?, ?, ?, 'manual_add', NULL, ?)");
      $log->execute([
        $newId,
        (int)($user['id'] ?? 0) ?: null,
        (string)($user['username'] ?? ''),
        (string)($user['role'] ?? ''),
        $newShift ? json_encode($newShift, JSON_UNESCAPED_SLASHES) : null,
      ]);

      admin_redirect(admin_url('shift-editor.php?' . http_build_query([
        'from' => $from,
        'to' => $to,
        'employee_id' => $employeeId,
        'dept_id' => $deptId,
        'status' => $status,
        'duration' => $duration,
        'shift_id' => $newId,
      ])));
    }
  } else {
    if ($sid <= 0) {
      $flash = 'Missing shift.';
    } else {
      $st = $pdo->prepare('SELECT * FROM kiosk_shifts WHERE id=? LIMIT 1');
      $st->execute([$sid]);
      $old = $st->fetch(PDO::FETCH_ASSOC);
      if (!$old) {
        $flash = 'Shift not found.';
      } else {
        // If user cleared clock_out, keep open.
        $isClosed = $clockOutUtc ? 1 : 0;

        // Recompute duration if closed.
        $durationMinutes = null;
        if ($clockInUtc && $clockOutUtc) {
          try {
            $d1 = new DateTimeImmutable($clockInUtc, new DateTimeZone('UTC'));
            $d2 = new DateTimeImmutable($clockOutUtc, new DateTimeZone('UTC'));
            $durationMinutes = max(0, (int)round(($d2->getTimestamp() - $d1->getTimestamp()) / 60));
          } catch (Throwable $e) {
            $durationMinutes = null;
          }
        }

        $upd = $pdo->prepare("UPDATE kiosk_shifts
                              SET clock_in_at=?, clock_out_at=?, is_closed=?, is_callout=?, training_minutes=?, training_note=?, duration_minutes=?, last_modified_reason='manual_edit', updated_source='admin'
                              WHERE id=?");
        $upd->execute([
          $clockInUtc ?? (string)$old['clock_in_at'],
          $clockOutUtc,
          $isClosed,
          $isCallout,
          $trainingMins ?: null,
          $trainingNote !== '' ? $trainingNote : null,
          $durationMinutes,
          $sid,
        ]);

        $st2 = $pdo->prepare('SELECT * FROM kiosk_shifts WHERE id=? LIMIT 1');
        $st2->execute([$sid]);
        $new = $st2->fetch(PDO::FETCH_ASSOC) ?: null;

        // Log change
        $log = $pdo->prepare("INSERT INTO kiosk_shift_changes (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, old_json, new_json)
                              VALUES (?, 'edit', ?, ?, ?, 'manual_edit', ?, ?)");
        $log->execute([
          $sid,
          (int)($user['id'] ?? 0) ?: null,
          (string)($user['username'] ?? ''),
          (string)($user['role'] ?? ''),
          json_encode($old, JSON_UNESCAPED_SLASHES),
          $new ? json_encode($new, JSON_UNESCAPED_SLASHES) : null,
        ]);

        admin_redirect(admin_url('shift-editor.php?' . http_build_query([
          'from' => $from,
          'to' => $to,
          'employee_id' => $employeeId,
          'dept_id' => $deptId,
          'status' => $status,
          'duration' => $duration,
          'shift_id' => $sid,
        ])));
      }
    }
  }
}

// ---------------------------------------------------------------------
// Data for filters
// ---------------------------------------------------------------------
$employees = $pdo->query("SELECT e.id, e.nickname, e.first_name, e.last_name, e.employee_code, e.is_agency, e.agency_label, d.name AS dept
                          FROM kiosk_employees e
                          LEFT JOIN kiosk_employee_departments d ON d.id=e.department_id
                          WHERE e.is_active=1
                          ORDER BY d.sort_order ASC, d.name ASC, e.nickname ASC, e.first_name ASC, e.last_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$depts = $pdo->query("SELECT id, name FROM kiosk_employee_departments ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$displayName = function(array $e): string {
  if ((int)($e['is_agency'] ?? 0) === 1) {
    $label = trim((string)($e['agency_label'] ?? ''));
    return $label !== '' ? $label : 'Agency';
  }
  $nick = trim((string)($e['nickname'] ?? ''));
  if ($nick !== '') return $nick;
  $fn = trim((string)($e['first_name'] ?? ''));
  $ln = trim((string)($e['last_name'] ?? ''));
  $name = trim($fn . ' ' . $ln);
  return $name !== '' ? $name : ('Emp #' . (string)($e['employee_code'] ?? ''));
};

// ---------------------------------------------------------------------
// Shift list query (UTC window derived from local day range)
// ---------------------------------------------------------------------
try {
  $fromLocal = new DateTimeImmutable($from . ' 00:00:00', $tz);
  $toLocalEx = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day');
} catch (Throwable $e) {
  $fromLocal = new DateTimeImmutable($defaultFrom . ' 00:00:00', $tz);
  $toLocalEx = (new DateTimeImmutable($defaultTo . ' 00:00:00', $tz))->modify('+1 day');
}

$fromUtc = $fromLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$toUtcEx = $toLocalEx->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$where = ["s.clock_in_at >= ?", "s.clock_in_at < ?"];
$params = [$fromUtc, $toUtcEx];

if ($employeeId > 0) {
  $where[] = 's.employee_id = ?';
  $params[] = $employeeId;
}
if ($deptId > 0) {
  $where[] = 'e.department_id = ?';
  $params[] = $deptId;
}
if ($status === 'needs_review') {
  // Safe, conservative definition for review inbox.
  $where[] = '(s.clock_out_at IS NULL OR s.approved_at IS NULL OR s.is_autoclosed = 1)';
} elseif ($status === 'approved') {
  $where[] = 's.clock_out_at IS NOT NULL';
  $where[] = 's.approved_at IS NOT NULL';
} elseif ($status === 'open') {
  $where[] = 's.clock_out_at IS NULL';
} else {
  // all
}

// Duration band filter (use stored duration_minutes when available; fall back to timestamp diff for open)
if ($duration !== 'all') {
  $expr = "COALESCE(s.duration_minutes, TIMESTAMPDIFF(MINUTE, s.clock_in_at, COALESCE(s.clock_out_at, UTC_TIMESTAMP())))";
  if ($duration === 'lt1')      { $where[] = "$expr < 60"; }
  elseif ($duration === '1_4')  { $where[] = "$expr >= 60 AND $expr < 240"; }
  elseif ($duration === '4_8')  { $where[] = "$expr >= 240 AND $expr < 480"; }
  elseif ($duration === '8_12') { $where[] = "$expr >= 480 AND $expr < 720"; }
  elseif ($duration === 'gt12') { $where[] = "$expr >= 720"; }
}

$sql = "SELECT s.*, e.nickname, e.first_name, e.last_name, e.employee_code, e.is_agency, e.agency_label,
               d.name AS department_name
        FROM kiosk_shifts s
        JOIN kiosk_employees e ON e.id=s.employee_id
        LEFT JOIN kiosk_employee_departments d ON d.id=e.department_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.clock_in_at DESC
        LIMIT 600";

$st = $pdo->prepare($sql);
$st->execute($params);
$shifts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($selectedShiftId <= 0 && $shifts) {
  $selectedShiftId = (int)$shifts[0]['id'];
}

$selected = null;
foreach ($shifts as $s) {
  if ((int)$s['id'] === $selectedShiftId) { $selected = $s; break; }
}

admin_page_start($pdo, 'Review & Approvals');
$active = admin_url('shift-editor.php');

// For "Add shift" convenience: use selected calendar day if user is on a single day,
// otherwise fall back to the current "from" date.
$addShiftDate = ($from === $to) ? $from : $from;
$addShiftQuery = ['date' => $addShiftDate];
if ($employeeId > 0) $addShiftQuery['employee_id'] = $employeeId;

// If user clicked a single day and there are no shifts in the current list,
// auto-open the Add Shift form so they can create one immediately.
$showAddByDefault = (($from === $to) && (count($shifts) === 0));

// Default clock-in value for Add form (prefill date + 08:00)
$addDefaultClockIn = '';
if ($addShiftDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $addShiftDate)) {
  $addDefaultClockIn = $addShiftDate . 'T08:00';
}
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-8">
    <div class="max-w-7xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Review &amp; Approvals</h1>
                <p class="mt-2 text-sm text-slate-600">Review, fix, and approve shifts (open + closed). Timezone: <span class="font-semibold"><?= h($tzName) ?></span></p>
              </div>
              <div class="flex flex-wrap gap-2">
                <a href="<?= h(admin_url('shift-editor.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Clear</a>
                <a href="<?= h(admin_url('shifts.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800">Back to weekly grid</a>
              </div>
            </div>

            <?php if ($flash !== ''): ?>
              <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><?= h($flash) ?></div>
            <?php endif; ?>

            <form method="get" id="filters" class="mt-4 grid grid-cols-1 md:grid-cols-12 gap-3">
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">From</label>
                <input type="date" name="from" value="<?= h($from) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" />
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">To</label>
                <input type="date" name="to" value="<?= h($to) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" />
              </div>
              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600">Employee</label>
                <select name="employee_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="0">All</option>
                  <?php foreach ($employees as $e): ?>
                    <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id'] === $employeeId) ? 'selected' : '' ?>><?= h($displayName($e)) ?><?= ($e['employee_code'] ? ' · ' . h((string)$e['employee_code']) : '') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">Department</label>
                <select name="dept_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="0">All</option>
                  <?php foreach ($depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= ((int)$d['id'] === $deptId) ? 'selected' : '' ?>><?= h((string)$d['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">Status</label>
                <select name="status" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="needs_review" <?= $status==='needs_review'?'selected':'' ?>>Needs review</option>
                  <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
                  <option value="open" <?= $status==='open'?'selected':'' ?>>Open only</option>
                  <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
                </select>
              </div>

              <div class="md:col-span-1 flex items-end">
                <button type="submit" class="w-full rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800">Apply</button>
              </div>
              <input type="hidden" name="shift_id" value="<?= (int)$selectedShiftId ?>" />
              <input type="hidden" name="month" value="<?= h($monthParam) ?>" />
              <?php if ($duration !== 'all'): ?>
                <input type="hidden" name="duration" value="<?= h($duration) ?>" />
              <?php endif; ?>
            </form>

            <details class="mt-3">
              <summary class="cursor-pointer select-none text-xs font-semibold text-slate-600">Advanced filters</summary>
              <div class="mt-2 grid grid-cols-1 md:grid-cols-12 gap-3">
                <div class="md:col-span-2">
                  <label class="block text-xs font-semibold text-slate-600">Hours</label>
                  <select name="duration" form="filters" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                    <option value="all" <?= $duration==='all'?'selected':'' ?>>All</option>
                    <option value="lt1" <?= $duration==='lt1'?'selected':'' ?>>&lt;1h</option>
                    <option value="1_4" <?= $duration==='1_4'?'selected':'' ?>>1–4h</option>
                    <option value="4_8" <?= $duration==='4_8'?'selected':'' ?>>4–8h</option>
                    <option value="8_12" <?= $duration==='8_12'?'selected':'' ?>>8–12h</option>
                    <option value="gt12" <?= $duration==='gt12'?'selected':'' ?>>&gt;12h</option>
                  </select>
                  <div class="mt-1 text-[11px] text-slate-500">Optional: filter by approximate shift duration.</div>
                </div>
              </div>
            </details>
          </header>

          <div class="mt-5 grid grid-cols-1 lg:grid-cols-12 gap-5">
            <!-- List (left) -->
            <section class="lg:col-span-7 rounded-3xl border border-slate-200 bg-white overflow-hidden" id="shiftList">
              <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-sm font-semibold">Shifts (<?= count($shifts) ?>)</div>
                <button type="button" id="addShiftBtn" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-sky-500/15 border border-sky-500/30 text-slate-900 hover:bg-sky-500/20">Add shift</button>
              </div>
              <div class="overflow-auto">
                <table class="min-w-full text-sm">
                  <thead class="bg-slate-50 text-slate-600">
                    <tr class="text-left">
                      <th class="px-4 py-2 font-semibold">Employee</th>
                      <th class="px-4 py-2 font-semibold">Dept</th>
                      <th class="px-4 py-2 font-semibold">In</th>
                      <th class="px-4 py-2 font-semibold">Out</th>
                      <th class="px-4 py-2 font-semibold">Duration</th>
                      <th class="px-4 py-2 font-semibold">Flags</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-200">
                  <?php if (!$shifts): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No shifts found for this filter.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($shifts as $s): ?>
                    <?php
                      $isSel = ((int)$s['id'] === (int)$selectedShiftId);
                      $inLocal = (new DateTimeImmutable((string)$s['clock_in_at'], new DateTimeZone('UTC')))->setTimezone($tz);
                      $outLocal = null;
                      if (!empty($s['clock_out_at'])) {
                        $outLocal = (new DateTimeImmutable((string)$s['clock_out_at'], new DateTimeZone('UTC')))->setTimezone($tz);
                      }
                      $durMin = null;
                      if ($s['duration_minutes'] !== null) {
                        $durMin = (int)$s['duration_minutes'];
                      } else {
                        $endTs = $outLocal ? $outLocal->getTimestamp() : (new DateTimeImmutable('now', $tz))->getTimestamp();
                        $durMin = max(0, (int)round(($endTs - $inLocal->getTimestamp())/60));
                      }
                      $flags = [];
                      if (!empty($s['approved_at'])) $flags[] = 'approved';
                      if ((int)($s['is_autoclosed'] ?? 0) === 1) $flags[] = 'autoclosed';
                      if (!empty($s['last_modified_reason'])) $flags[] = (string)$s['last_modified_reason'];
                      if ((int)($s['is_callout'] ?? 0) === 1) $flags[] = 'callout';
                      $flagText = $flags ? implode(', ', $flags) : '—';
                    ?>
                    <tr id="shift-row-<?= (int)$s['id'] ?>" class="<?= $isSel ? 'bg-emerald-50' : 'bg-white' ?> hover:bg-slate-50 cursor-pointer" data-shift-id="<?= (int)$s['id'] ?>">
                      <td class="px-4 py-2">
                        <div class="font-semibold text-slate-900"><?= h($displayName($s)) ?></div>
                        <div class="text-xs text-slate-500">#<?= h((string)$s['employee_code']) ?> · <?= h((string)$inLocal->format('d M Y')) ?></div>
                      </td>
                      <td class="px-4 py-2 text-slate-700"><?= h((string)($s['department_name'] ?? '—')) ?></td>
                      <td class="px-4 py-2 font-semibold text-slate-900"><?= h($inLocal->format('H:i')) ?></td>
                      <td class="px-4 py-2 font-semibold text-slate-900"><?= $outLocal ? h($outLocal->format('H:i')) : '<span class="text-amber-700">Open</span>' ?></td>
                      <td class="px-4 py-2 text-slate-700"><?= h(minutes_to_hhmm((int)$durMin)) ?></td>
                      <td class="px-4 py-2 text-xs text-slate-600"><?= h($flagText) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>

            <!-- Right column: calendar on top, editor below -->
            <div class="lg:col-span-5 flex flex-col gap-5" id="rightRail">
              <!-- Monthly calendar: highlights days that have shifts needing review/approval -->
              <?php
                $calMonthStartLocal2 = new DateTimeImmutable($monthParam . '-01 00:00:00', $tz);
                $calPrevMonth = $calMonthStartLocal2->modify('-1 month')->format('Y-m');
                $calNextMonth = $calMonthStartLocal2->modify('+1 month')->format('Y-m');
                $calDaysInMonth = (int)$calMonthStartLocal2->format('t');
                $calFirstDow = (int)$calMonthStartLocal2->format('w'); // 0=Sun..6=Sat
                $calLead = ($calFirstDow - $calWeekStartDow + 7) % 7;
                $calSelDay = ($from === $to) ? $from : '';

                $calDowLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                // Rotate labels so the calendar starts on the configured week start.
                $calLabels = [];
                for ($i = 0; $i < 7; $i++) {
                  $calLabels[] = $calDowLabels[($calWeekStartDow + $i) % 7];
                }

                $baseQuery = [
                  'employee_id' => $employeeId,
                  'dept_id' => $deptId,
                  'status' => $status,
                  'duration' => $duration,
                  'shift_id' => $selectedShiftId,
                ];
              ?>
              <section class="rounded-3xl border border-slate-200 bg-white overflow-hidden" id="reviewCalendar">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between gap-3">
                  <div class="min-w-0">
                    <div class="text-sm font-semibold">Review calendar</div>
                    <div class="mt-1 text-xs text-slate-500">Red days have at least one shift that is open, unapproved, or autoclosed.</div>
                  </div>
                  <div class="flex items-center gap-2 shrink-0">
                    <a class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50"
                      href="<?= h(admin_url('shift-editor.php?' . http_build_query(array_merge($baseQuery, ['month' => $calPrevMonth, 'from' => $from, 'to' => $to]))) ) ?>">←</a>
                    <div class="text-sm font-semibold text-slate-900 min-w-[110px] text-center"><?= h($calMonthStartLocal2->format('M Y')) ?></div>
                    <a class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50"
                      href="<?= h(admin_url('shift-editor.php?' . http_build_query(array_merge($baseQuery, ['month' => $calNextMonth, 'from' => $from, 'to' => $to]))) ) ?>">→</a>
                  </div>
                </div>
                <div class="p-5">
                  <div class="grid grid-cols-7 gap-2">
                    <?php foreach ($calLabels as $lbl): ?>
                      <div class="text-[11px] font-semibold uppercase tracking-widest text-slate-500 text-center"><?= h($lbl) ?></div>
                    <?php endforeach; ?>

                    <?php
                      $cellCount = $calLead + $calDaysInMonth;
                      // Round up to full weeks.
                      $totalCells = (int)ceil($cellCount / 7) * 7;
                      for ($i = 0; $i < $totalCells; $i++):
                        $dayNum = $i - $calLead + 1;
                        if ($dayNum < 1 || $dayNum > $calDaysInMonth) {
                          echo '<div class="h-10 rounded-xl bg-slate-50"></div>';
                          continue;
                        }
                        $ymd = $calMonthStartLocal2->format('Y-m-') . str_pad((string)$dayNum, 2, '0', STR_PAD_LEFT);
                        $cnt = (int)($calCounts[$ymd] ?? 0);
                        $isRed = $cnt > 0;
                        $isSel = ($calSelDay === $ymd);
                        $cls = 'h-10 rounded-xl border text-sm font-semibold flex items-center justify-center relative transition-colors';
                        if ($isSel) {
                          $cls .= ' bg-slate-900 text-white border-slate-900';
                        } elseif ($isRed) {
                          $cls .= ' bg-rose-50 text-rose-800 border-rose-200 hover:bg-rose-100';
                        } else {
                          $cls .= ' bg-white text-slate-800 border-slate-200 hover:bg-slate-50';
                        }
                        $href = admin_url('shift-editor.php?' . http_build_query(array_merge($baseQuery, [
                          'month' => $monthParam,
                          'from' => $ymd,
                          'to' => $ymd,
                        ])));
                    ?>
                      <a href="<?= h($href) ?>" class="<?= h($cls) ?>" title="<?= $isRed ? h($cnt . ' shift(s) need review') : 'No review needed' ?>">
                        <?= (int)$dayNum ?>
                        <?php if ($isRed && !$isSel): ?>
                          <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-600 text-white text-[10px] leading-[18px] text-center"><?= (int)$cnt ?></span>
                        <?php endif; ?>
                      </a>
                    <?php endfor; ?>
                  </div>
                </div>
              </section>

              <!-- Editor panel -->
              <aside class="rounded-3xl border border-slate-200 bg-white overflow-hidden" id="editorPanel">
                <div class="px-5 py-4 border-b border-slate-200">
                  <div class="text-sm font-semibold" id="editorTitle">
                    <?= $showAddByDefault ? 'Add shift' : 'Edit shift' ?>
                  </div>
                  <div class="mt-1 text-xs text-slate-500" id="editorSub">
                    <?= $showAddByDefault ? 'No shifts found for this day. Create one below.' : 'Select a shift on the left.' ?>
                  </div>
                </div>

                <div class="p-5">
                <?php if ($selected): ?>

                  <?php
                    $selIn = dt_utc_to_local_input((string)$selected['clock_in_at'], $tz);
                    $selOut = dt_utc_to_local_input($selected['clock_out_at'] ? (string)$selected['clock_out_at'] : null, $tz);
                    $selName = $displayName($selected);
                    $selDept = (string)($selected['department_name'] ?? '—');
                  ?>
                  <form method="post" id="editForm" class="space-y-4">
                    <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>" />
                    <input type="hidden" name="mode" id="formMode" value="edit" />
                    <input type="hidden" name="shift_id" id="shift_id" value="<?= (int)$selected['id'] ?>" />

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                      <div class="text-sm font-semibold text-slate-900"><?= h($selName) ?> <span class="text-slate-500 font-normal">#<?= h((string)$selected['employee_code']) ?></span></div>
                      <div class="text-xs text-slate-600 mt-1"><?= h($selDept) ?> · Shift ID <?= (int)$selected['id'] ?></div>
                    </div>

                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Clock in</label>
                      <input type="datetime-local" name="clock_in_local" id="clock_in_local" value="<?= h($selIn) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                    </div>

                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Clock out (leave blank = open)</label>
                      <input type="datetime-local" name="clock_out_local" id="clock_out_local" value="<?= h($selOut) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                    </div>

                    <div class="flex items-center gap-2">
                      <input type="checkbox" id="is_callout" name="is_callout" value="1" <?= ((int)($selected['is_callout'] ?? 0)===1)?'checked':'' ?> class="h-4 w-4 rounded border-slate-300">
                      <label for="is_callout" class="text-sm text-slate-800 font-semibold">Callout</label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                      <div>
                        <label class="block text-xs font-semibold text-slate-600">Training minutes</label>
                        <input type="number" min="0" step="1" name="training_minutes" id="training_minutes" value="<?= (int)($selected['training_minutes'] ?? 0) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                      </div>
                      <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-600">Training note</label>
                        <input type="text" name="training_note" id="training_note" value="<?= h((string)($selected['training_note'] ?? '')) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" maxlength="255">
                      </div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-2">
                      <button type="submit" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Save</button>
                      <a href="<?= h(admin_url('shift-editor.php?' . http_build_query([
                        'from' => $from,
                        'to' => $to,
                        'employee_id' => $employeeId,
                        'dept_id' => $deptId,
                        'status' => $status,
                        'duration' => $duration,
                      ]))) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Clear selection</a>
                    </div>

                    <p class="text-xs text-slate-500">Edits are logged in <span class="font-semibold">kiosk_shift_changes</span> and marked as <span class="font-semibold">manual_edit</span>.</p>
                  </form>
<?php else: ?>
  <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
    <div class="text-sm font-semibold text-slate-900">No shift selected</div>
    <div class="text-xs text-slate-600 mt-1">Use <span class="font-semibold">Add shift</span> to create a manual shift for this day.</div>
  </div>
<?php endif; ?>

<!-- Hidden Add Shift form template (UI only for now; posts with mode=add) -->
                  <form method="post" id="addForm" class="space-y-4 <?= $showAddByDefault ? '' : 'hidden' ?>">
                    <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>" />
                    <input type="hidden" name="mode" value="add" />
                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Employee</label>
                      <select name="employee_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                        <option value="">Select…</option>
                        <?php foreach ($employees as $e): ?>
                          <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id'] === $employeeId && $employeeId > 0) ? 'selected' : '' ?>><?= h($displayName($e)) ?><?= ($e['employee_code'] ? ' · ' . h((string)$e['employee_code']) : '') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Clock in</label>
                      <input type="datetime-local" name="clock_in_local" value="<?= h($addDefaultClockIn) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Clock out (optional)</label>
                      <input type="datetime-local" name="clock_out_local" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                    </div>
                    <div class="flex items-center gap-2">
                      <input type="checkbox" id="add_is_callout" name="is_callout" value="1" class="h-4 w-4 rounded border-slate-300">
                      <label for="add_is_callout" class="text-sm text-slate-800 font-semibold">Callout</label>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                      <div>
                        <label class="block text-xs font-semibold text-slate-600">Training minutes</label>
                        <input type="number" min="0" step="1" name="training_minutes" value="0" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                      </div>
                      <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-600">Training note</label>
                        <input type="text" name="training_note" value="" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" maxlength="255">
                      </div>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-2">
                      <button type="submit" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-sky-600 text-white hover:bg-sky-700">Create shift</button>
                      <button type="button" id="cancelAdd" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Cancel</button>
                    </div>
                    <p class="text-xs text-slate-500">Creates a manual shift and logs it in <span class="font-semibold">kiosk_shift_changes</span> with reason <span class="font-semibold">manual_add</span>.</p>
                  </form>

                
                </div>
              </aside>
            </div>
          </div>
        </main>
      </div>
    </div>
  </div>
</div>

<script>
  // Remember page position between navigations (row select, calendar clicks, save, filters).
  (function() {
    const KEY = 'shift_editor_scrollY';

    // Restore scroll if we saved it.
    const saved = sessionStorage.getItem(KEY);
    if (saved !== null) {
      const y = parseInt(saved, 10);
      if (!Number.isNaN(y)) window.scrollTo(0, y);
      sessionStorage.removeItem(KEY);
    } else {
      // If no saved scroll, try to bring selected row into view.
      try {
        const sid = new URLSearchParams(window.location.search).get('shift_id');
        if (sid) {
          const row = document.getElementById('shift-row-' + sid);
          if (row) row.scrollIntoView({ block: 'center' });
        }
      } catch (e) {}
    }

    // Save scroll for any internal navigation link.
    document.addEventListener('click', (ev) => {
      const a = ev.target && ev.target.closest ? ev.target.closest('a') : null;
      if (!a) return;
      if (a.target === '_blank') return;
      if (!a.href) return;
      try {
        const url = new URL(a.href, window.location.href);
        if (url.origin === window.location.origin) {
          sessionStorage.setItem(KEY, String(window.scrollY || 0));
        }
      } catch (e) {}
    }, { capture: true });

    // Save scroll before submitting forms.
    document.addEventListener('submit', () => {
      try { sessionStorage.setItem(KEY, String(window.scrollY || 0)); } catch (e) {}
    }, { capture: true });
  })();

  // Row click selects shift
  (function() {
    const rows = document.querySelectorAll('tr[data-shift-id]');
    rows.forEach(r => {
      r.addEventListener('click', () => {
        const sid = r.getAttribute('data-shift-id');
        try { sessionStorage.setItem('shift_editor_scrollY', String(window.scrollY || 0)); } catch (e) {}
        const url = new URL(window.location.href);
        url.searchParams.set('shift_id', sid);
        window.location.href = url.toString();
      });
    });
  })();

  // UI toggle: add shift
  (function() {
    const btn = document.getElementById('addShiftBtn');
    const editForm = document.getElementById('editForm');
    const addForm = document.getElementById('addForm');
    const cancel = document.getElementById('cancelAdd');
    const title = document.getElementById('editorTitle');
    const sub = document.getElementById('editorSub');
    const autoOpen = <?= $showAddByDefault ? 'true' : 'false' ?>;
    if (!btn || !addForm) return;

    function openAdd() {
      if (editForm) editForm.classList.add('hidden');
      addForm.classList.remove('hidden');

      // Auto-prefill employee/date from current filters/calendar selection.
      try {
        const addDate = <?= json_encode($addShiftDate) ?>;
        const addEmp = <?= (int)$employeeId ?>;

        const empSel = addForm.querySelector('select[name="employee_id"]');
        if (empSel && addEmp > 0) {
          empSel.value = String(addEmp);
        }

        const cin = addForm.querySelector('input[name="clock_in_local"]');
        if (cin && addDate && /^\d{4}-\d{2}-\d{2}$/.test(addDate)) {
          // Default time 08:00 (easy to adjust).
          if (!cin.value) cin.value = addDate + 'T08:00';
        }

        const cout = addForm.querySelector('input[name="clock_out_local"]');
        if (cout) cout.value = '';
      } catch (e) {}

      if (title) title.textContent = 'Add shift';
      if (sub) sub.textContent = 'Create a manual shift (training is separate and not part of calculations).';
    }

    btn.addEventListener('click', openAdd);

    if (autoOpen) {
      openAdd();
    }

    if (cancel) {
      cancel.addEventListener('click', () => {
        // If there's no edit form (empty day), keep Add open and just reset.
        if (!editForm) {
          try {
            const cout = addForm.querySelector('input[name="clock_out_local"]');
            if (cout) cout.value = '';
          } catch (e) {}
          return;
        }

        addForm.classList.add('hidden');
        editForm.classList.remove('hidden');
        if (title) title.textContent = 'Edit shift';
        if (sub) sub.textContent = 'Select a shift on the left.';
      });
    }
  })();
</script>

<?php admin_page_end();
