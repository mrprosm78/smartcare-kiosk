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
$defaultFrom = $todayLocal->modify('first day of this month')->format('Y-m-d');
$defaultTo = $todayLocal->modify('last day of this month')->format('Y-m-d');

$from = q('from', $defaultFrom);
$to   = q('to', $defaultTo);
if (!is_ymd($from)) $from = $defaultFrom;
if (!is_ymd($to))   $to   = $defaultTo;

$employeeId = (int)($_GET['employee_id'] ?? 0);
$deptId = (int)($_GET['dept_id'] ?? 0);
$status = q('status', 'all'); // needs_review(attention)|approved|open|all // needs_review|approved|open|all
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
// Calendar: count days that have shifts needing attention.
// Definition (simple + safe): a shift needs attention if it is open OR autoclosed OR triggers an alert (e.g. long/short).
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

  $sql = "SELECT s.clock_in_at, s.clock_out_at, s.duration_minutes, s.is_autoclosed
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

// Needs-attention definition (flexible):
// - Open shifts always need attention
// - Autoclosed shifts always need attention
// - Duration alerts: "long" or "short" shifts need attention
//
// NOTE: Approval is separate. We do NOT count "unapproved" closed shifts as needing attention.

if (empty($r['clock_out_at'])) $needsReview = true; // open
if ((int)($r['is_autoclosed'] ?? 0) === 1) $needsReview = true; // autoclosed

// Duration-based alerts (defaults; can be moved to settings later)
$LONG_MINUTES = 750;  // 12h30m
$SHORT_MINUTES = 30;  // 30m

$dur = null;
if (!$needsReview) {
  // Only compute duration for closed shifts that aren't already open/autoclosed.
  $cinUtc = (string)($r['clock_in_at'] ?? '');
  $coutUtc = (string)($r['clock_out_at'] ?? '');
  $durStored = $r['duration_minutes'] ?? null;
  if ($durStored !== null && $durStored !== '') {
    $dur = (int)$durStored;
  } elseif ($cinUtc !== '' && $coutUtc !== '') {
    try {
      $d1 = new DateTimeImmutable($cinUtc, new DateTimeZone('UTC'));
      $d2 = new DateTimeImmutable($coutUtc, new DateTimeZone('UTC'));
      $dur = (int)round(($d2->getTimestamp() - $d1->getTimestamp())/60);
    } catch (Throwable $e) {
      $dur = null;
    }
  }
  if ($dur !== null) {
    if ($dur > $LONG_MINUTES) $needsReview = true; // long
    if ($dur >= 0 && $dur < $SHORT_MINUTES) $needsReview = true; // short
  }
}

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
  $mode = (string)($_POST['mode'] ?? 'edit'); // edit|add|training_add

  // Separate training entries (NOT part of shift calculations).
  // Stored in kiosk_training_entries so training can be added without selecting a shift.
  if ($mode === 'training_add') {
    $tEmp = (int)($_POST['training_employee_id'] ?? 0);
    $tStartLocal = (string)($_POST['training_start_local'] ?? '');
    $tMins = (int)($_POST['training_minutes'] ?? 0);
    if ($tMins < 0) $tMins = 0;
    $tNote = trim((string)($_POST['training_note'] ?? ''));
    if (strlen($tNote) > 255) $tNote = substr($tNote, 0, 255);

    $tStartUtc = local_input_to_utc($tStartLocal, $tz);

    if ($tEmp <= 0 || !$tStartUtc || $tMins <= 0) {
      $flash = 'Training needs employee, date/time, and minutes.';
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS kiosk_training_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        employee_id INT UNSIGNED NOT NULL,
        training_start_at DATETIME NOT NULL,
        training_minutes INT NOT NULL,
        training_note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        created_by_user_id INT UNSIGNED NULL,
        created_by_username VARCHAR(100) NULL,
        created_by_role VARCHAR(50) NULL,
        INDEX idx_training_start_at (training_start_at),
        INDEX idx_training_employee (employee_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $insT = $pdo->prepare("INSERT INTO kiosk_training_entries
        (employee_id, training_start_at, training_minutes, training_note, created_at, created_by_user_id, created_by_username, created_by_role)
        VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?)");
      $insT->execute([
        $tEmp,
        $tStartUtc,
        $tMins,
        $tNote !== '' ? $tNote : null,
        (int)($user['id'] ?? 0) ?: null,
        (string)($user['username'] ?? ''),
        (string)($user['role'] ?? ''),
      ]);

      admin_redirect(admin_url('shift-editor.php?' . http_build_query([
        'from' => $from,
        'to' => $to,
        'employee_id' => $employeeId,
        'dept_id' => $deptId,
        'status' => $status,
        'duration' => $duration,
        'month' => $monthParam,
        'shift_id' => $selectedShiftId,
      ])));
    }
  }


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
$employees = $pdo->query(
  "SELECT e.id, e.nickname, e.first_name, e.last_name, e.employee_code, e.is_agency, e.agency_label, d.name AS dept
   FROM kiosk_employees e
   LEFT JOIN kiosk_employee_departments d ON d.id = e.department_id
   WHERE e.is_active = 1
   ORDER BY
     CASE
       WHEN TRIM(CONCAT(IFNULL(e.first_name,''),' ',IFNULL(e.last_name,''))) <> '' THEN TRIM(CONCAT(IFNULL(e.first_name,''),' ',IFNULL(e.last_name,'')))
       ELSE TRIM(IFNULL(e.nickname,''))
     END ASC,
     e.employee_code ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
  // Needs-attention inbox (flexible): open OR autoclosed OR duration alerts (long/short).
  $expr = "COALESCE(s.duration_minutes, TIMESTAMPDIFF(MINUTE, s.clock_in_at, COALESCE(s.clock_out_at, UTC_TIMESTAMP())))";
  $where[] = "(s.clock_out_at IS NULL OR s.is_autoclosed = 1 OR $expr > 750 OR (s.clock_out_at IS NOT NULL AND $expr < 30))";
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

<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
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
                    <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id'] === $employeeId) ? 'selected' : '' ?>><?= h($displayName($e)) ?><?= ($e['employee_code'] !== null && $e['employee_code'] !== '' ? ' — ' . h(str_pad((string)$e['employee_code'], 4, '0', STR_PAD_LEFT)) : '') ?></option>
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
              <input type="hidden" name="shift_id" value="<?= (int)$selectedShiftId ?>" />
              <input type="hidden" name="month" value="<?= h($monthParam) ?>" />
              <?php if ($duration !== 'all'): ?>
                <input type="hidden" name="duration" value="<?= h($duration) ?>" />
              <?php endif; ?>
            </form>
            <div class="mt-2 text-xs text-slate-500">Filters apply automatically — no Apply button needed.</div>

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

                      // Duration alert flags (defaults; can be moved to settings later)
                      if (!empty($s['clock_out_at'])) {
                        $LONG_MINUTES = 750;  // 12h30m
                        $SHORT_MINUTES = 30;  // 30m
                        if ($durMin > $LONG_MINUTES) $flags[] = 'long';
                        if ($durMin >= 0 && $durMin < $SHORT_MINUTES) $flags[] = 'short';
                      }
                      if (!empty($s['last_modified_reason'])) $flags[] = (string)$s['last_modified_reason'];
                      if ((int)($s['is_callout'] ?? 0) === 1) $flags[] = 'callout';
                      $flagText = $flags ? implode(', ', $flags) : '—';

                      // Row highlighting for manager attention (approved stays approved; we only highlight risky shifts).
                      $isOpen = empty($s['clock_out_at']);
                      $isAutoclosed = ((int)($s['is_autoclosed'] ?? 0) === 1);

                      // Recompute long/short booleans (to drive highlight). Keep flexible for future alert rules.
                      $LONG_MINUTES = 750;  // 12h30m (can be moved to settings later)
                      $SHORT_MINUTES = 30;  // 30m
                      $isLong = (!$isOpen && $durMin > $LONG_MINUTES);
                      $isShort = (!$isOpen && $durMin >= 0 && $durMin < $SHORT_MINUTES);

                      $needsAttention = ($isOpen || $isAutoclosed || $isLong || $isShort);

                      $rowBg = 'bg-white';
                      if ($needsAttention) {
                        $rowBg = $isOpen ? 'bg-amber-50' : 'bg-rose-50';
                      }
                      if ($isSel) {
                        $rowBg = 'bg-emerald-50';
                      }
                    ?>
                    <tr id="shift-row-<?= (int)$s['id'] ?>" class="<?= $rowBg ?> hover:bg-slate-50 cursor-pointer" data-shift-id="<?= (int)$s['id'] ?>">
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

                // When browsing months, automatically set filter window to the whole month.
                $prevStart = new DateTimeImmutable($calPrevMonth . '-01 00:00:00', $tz);
                $nextStart = new DateTimeImmutable($calNextMonth . '-01 00:00:00', $tz);
                $calPrevFrom = $prevStart->format('Y-m-d');
                $calPrevTo   = $prevStart->modify('last day of this month')->format('Y-m-d');
                $calNextFrom = $nextStart->format('Y-m-d');
                $calNextTo   = $nextStart->modify('last day of this month')->format('Y-m-d');
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
                    <div class="text-sm font-semibold">Attention calendar</div>
                    <div class="mt-1 text-xs text-slate-500">Red days have at least one shift that needs attention (open, autoclosed, long, or short).</div>
                  </div>
                  <div class="flex items-center gap-2 shrink-0">
                    <a class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50"
                      href="<?= h(admin_url('shift-editor.php?' . http_build_query(array_merge($baseQuery, ['month' => $calPrevMonth, 'from' => $calPrevFrom, 'to' => $calPrevTo, 'shift_id' => 0]))) ) ?>">←</a>
                    <div class="text-sm font-semibold text-slate-900 min-w-[110px] text-center"><?= h($calMonthStartLocal2->format('M Y')) ?></div>
                    <a class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50"
                      href="<?= h(admin_url('shift-editor.php?' . http_build_query(array_merge($baseQuery, ['month' => $calNextMonth, 'from' => $calNextFrom, 'to' => $calNextTo, 'shift_id' => 0]))) ) ?>">→</a>
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
                          'shift_id' => 0,
                        ])));
                    ?>
                      <a href="<?= h($href) ?>" class="<?= h($cls) ?>" title="<?= $isRed ? h($cnt . ' shift(s) need attention') : 'No attention needed' ?>">
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
                  <div class="text-sm font-semibold">Create / Edit shift</div>
                  <div class="mt-1 text-xs text-slate-500">Click a shift to edit it. If nothing is selected, fill the form to create a manual shift.</div>
                </div>

                <?php
                  $isEditing = (bool)$selected;
                  $formMode = $isEditing ? 'edit' : 'add';

                  // Default date/time for creating a manual shift:
                  // - if user clicked a single day (from==to), use that day
                  // - otherwise, use today's local date
                  $defaultDate = ($from === $to) ? $from : $todayLocal->format('Y-m-d');
                  $defaultClockIn = $defaultDate . 'T08:00';

                  $uShiftId = $isEditing ? (int)$selected['id'] : 0;
                  $uEmployeeId = $isEditing ? (int)$selected['employee_id'] : ($employeeId > 0 ? $employeeId : 0);
                  $uIn  = $isEditing ? dt_utc_to_local_input((string)$selected['clock_in_at'], $tz) : $defaultClockIn;
                  $uOut = $isEditing ? dt_utc_to_local_input($selected['clock_out_at'] ? (string)$selected['clock_out_at'] : null, $tz) : '';
                  $uCallout = $isEditing ? ((int)($selected['is_callout'] ?? 0) === 1) : false;

                  $headerName = $isEditing ? $displayName($selected) : '';
                  $headerDept = $isEditing ? (string)($selected['department_name'] ?? '—') : '';
                  $headerCode = $isEditing ? (string)($selected['employee_code'] ?? '') : '';
                ?>

                <div class="p-5 space-y-5">
                  <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <?php if ($isEditing): ?>
                      <div class="text-sm font-semibold text-slate-900">
                        <?= h($headerName) ?><?php if ($headerCode !== ''): ?> <span class="text-slate-500 font-normal">(#<?= h($headerCode) ?>)</span><?php endif; ?>
                      </div>
                      <div class="mt-1 text-xs text-slate-600"><?= h($headerDept) ?> · Shift ID <?= (int)$uShiftId ?></div>
                    <?php else: ?>
                      <div class="text-sm font-semibold text-slate-900">No shift selected</div>
                      <div class="mt-1 text-xs text-slate-600">Creating a manual shift. Defaults to the selected calendar day (if you clicked one) or today.</div>
                    <?php endif; ?>
                  </div>

                  <form method="post" id="shiftForm" class="space-y-4">
                    <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>" />
                    <input type="hidden" name="mode" value="<?= h($formMode) ?>" />
                    <input type="hidden" name="shift_id" value="<?= (int)$uShiftId ?>" />

                    <?php if (!$isEditing): ?>
                      <div>
                        <label class="block text-xs font-semibold text-slate-600">Employee</label>
                        <select name="employee_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                          <option value="">Select…</option>
                          <?php foreach ($employees as $e): ?>
                            <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id'] === $uEmployeeId && $uEmployeeId > 0) ? 'selected' : '' ?>>
                              <?= h($displayName($e)) ?><?= ($e['employee_code'] !== null && $e['employee_code'] !== '' ? ' — ' . h(str_pad((string)$e['employee_code'], 4, '0', STR_PAD_LEFT)) : '') ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    <?php else: ?>
                      <input type="hidden" name="employee_id" value="<?= (int)$uEmployeeId ?>" />
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                      <div>
                        <label class="block text-xs font-semibold text-slate-600">Clock in</label>
                        <input type="datetime-local" name="clock_in_local" id="clock_in_local" value="<?= h($uIn) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                        <div class="mt-1 flex flex-wrap gap-2 text-[11px]">
                          <button type="button" class="roundHour rounded-xl border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50" data-dir="down">Round down to hour</button>
                          <button type="button" class="roundHour rounded-xl border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50" data-dir="up">Round up to hour</button>
                        </div>
                      </div>

                      <div>
                        <label class="block text-xs font-semibold text-slate-600">Clock out (leave blank = open)</label>
                        <input type="datetime-local" name="clock_out_local" id="clock_out_local" value="<?= h($uOut) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                        <div class="mt-1 flex flex-wrap gap-2 text-[11px]">
                          <button type="button" class="quickClose rounded-xl border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50" data-hours="5">Close +5h</button>
                          <button type="button" class="quickClose rounded-xl border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50" data-hours="8">Close +8h</button>
                          <button type="button" class="quickClose rounded-xl border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50" data-hours="12">Close +12h</button>
                          <button type="button" class="nudgeOut rounded-xl border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50" data-mins="-30">-30m</button>
                          <button type="button" class="nudgeOut rounded-xl border border-slate-200 bg-white px-2 py-1 hover:bg-slate-50" data-mins="30">+30m</button>
                        </div>
                      </div>
                    </div>

                    <div class="flex items-center gap-2">
                      <input type="checkbox" id="is_callout" name="is_callout" value="1" <?= $uCallout ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300">
                      <label for="is_callout" class="text-sm text-slate-800 font-semibold">Callout</label>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-2">
                      <button type="submit" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700"><?= $isEditing ? 'Save changes' : 'Create shift' ?></button>
                      <?php if ($isEditing): ?>
                        <a href="<?= h(admin_url('shift-editor.php?' . http_build_query([
                          'from' => $from,
                          'to' => $to,
                          'employee_id' => $employeeId,
                          'dept_id' => $deptId,
                          'status' => $status,
                          'duration' => $duration,
                          'month' => $monthParam,
                          'shift_id' => 0,
                        ]))) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Clear selection</a>
                      <?php endif; ?>
                    </div>

                    <p class="text-xs text-slate-500">Edits are logged in <span class="font-semibold">kiosk_shift_changes</span>.</p>
                  </form>
                </div>
              </aside>


              <!-- Training panel (separate section) -->
              <?php
                $pdo->exec("CREATE TABLE IF NOT EXISTS kiosk_training_entries (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  employee_id INT UNSIGNED NOT NULL,
                  training_start_at DATETIME NOT NULL,
                  training_minutes INT NOT NULL,
                  training_note VARCHAR(255) NULL,
                  created_at DATETIME NOT NULL,
                  created_by_user_id INT UNSIGNED NULL,
                  created_by_username VARCHAR(100) NULL,
                  created_by_role VARCHAR(50) NULL,
                  INDEX idx_training_start_at (training_start_at),
                  INDEX idx_training_employee (employee_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $trainingEntries = [];
                try {
                  $tWhere = ["t.training_start_at >= ?", "t.training_start_at < ?"];
                  $tParams = [$fromUtc, $toUtcEx];
                  if ($employeeId > 0) { $tWhere[] = "t.employee_id = ?"; $tParams[] = $employeeId; }
                  if ($deptId > 0) { $tWhere[] = "e.department_id = ?"; $tParams[] = $deptId; }

                  $tSql = "SELECT t.*, e.nickname, e.first_name, e.last_name, e.employee_code, e.is_agency, e.agency_label, d.name AS department_name
                           FROM kiosk_training_entries t
                           JOIN kiosk_employees e ON e.id=t.employee_id
                           LEFT JOIN kiosk_employee_departments d ON d.id=e.department_id
                           WHERE " . implode(" AND ", $tWhere) . "
                           ORDER BY t.training_start_at DESC
                           LIMIT 60";
                  $stT = $pdo->prepare($tSql);
                  $stT->execute($tParams);
                  $trainingEntries = $stT->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $e) {
                  $trainingEntries = [];
                }

                $trainingDefaultDate = ($from === $to) ? $from : $todayLocal->format('Y-m-d');
                $trainingDefaultStart = $trainingDefaultDate . 'T08:00';
              ?>

              <aside class="rounded-3xl border border-slate-200 bg-white overflow-hidden" id="trainingPanel">
                <div class="px-5 py-4 border-b border-slate-200">
                  <div class="text-sm font-semibold">Training</div>
                  <div class="mt-1 text-xs text-slate-500">Separate section (not included in calculations). Uses the selected calendar day by default.</div>
                </div>
                <div class="p-5 space-y-5">
                  <form method="post" id="trainingForm" class="space-y-4">
                    <input type="hidden" name="_csrf" value="<?= h(admin_csrf_token()) ?>" />
                    <input type="hidden" name="mode" value="training_add" />

                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Employee</label>
                      <select name="training_employee_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                        <option value="">Select…</option>
                        <?php foreach ($employees as $e): ?>
                          <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id'] === $employeeId && $employeeId > 0) ? 'selected' : '' ?>>
                            <?= h($displayName($e)) ?><?= ($e['employee_code'] !== null && $e['employee_code'] !== '' ? ' — ' . h(str_pad((string)$e['employee_code'], 4, '0', STR_PAD_LEFT)) : '') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                      <div>
                        <label class="block text-xs font-semibold text-slate-600">Date/time</label>
                        <input type="datetime-local" name="training_start_local" id="training_start_local" value="<?= h($trainingDefaultStart) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                      </div>
                      <div>
                        <label class="block text-xs font-semibold text-slate-600">Minutes</label>
                        <input type="number" min="1" step="1" name="training_minutes" value="30" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" required>
                      </div>
                    </div>

                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Note</label>
                      <input type="text" name="training_note" value="" maxlength="255" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                    </div>

                    <div class="flex flex-wrap gap-2 pt-1">
                      <button type="submit" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-indigo-600 text-white hover:bg-indigo-700">Save training</button>
                    </div>
                  </form>

                  <div class="rounded-2xl border border-slate-200 overflow-hidden">
                    <div class="px-4 py-2 bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-700">
                      Recent training (<?= count($trainingEntries) ?>)
                    </div>
                    <div class="max-h-[240px] overflow-auto divide-y divide-slate-200">
                      <?php if (!$trainingEntries): ?>
                        <div class="px-4 py-4 text-sm text-slate-500">No training entries in this range.</div>
                      <?php endif; ?>
                      <?php foreach ($trainingEntries as $t): ?>
                        <?php $tStartLocal = (new DateTimeImmutable((string)$t['training_start_at'], new DateTimeZone('UTC')))->setTimezone($tz); ?>
                        <div class="px-4 py-3">
                          <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                              <div class="text-sm font-semibold text-slate-900 truncate"><?= h($displayName($t)) ?> <span class="text-slate-500 font-normal">(#<?= h((string)($t['employee_code'] ?? '')) ?>)</span></div>
                              <div class="mt-1 text-xs text-slate-600"><?= h((string)($t['department_name'] ?? '—')) ?> · <?= h($tStartLocal->format('d M Y H:i')) ?></div>
                              <?php if (!empty($t['training_note'])): ?>
                                <div class="mt-1 text-xs text-slate-500"><?= h((string)$t['training_note']) ?></div>
                              <?php endif; ?>
                            </div>
                            <div class="text-sm font-semibold text-slate-900 shrink-0"><?= h(minutes_to_hhmm((int)$t['training_minutes'])) ?></div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>

                </div>
              </aside>

            </div>
          </div>
        </main>
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

  
  

  // Remember current calendar/filter position across navigations (e.g., coming back from other pages).
  (function() {
    const KEY = 'shift_editor_last_query';
    try {
      // Save current query whenever we are on this page.
      sessionStorage.setItem(KEY, window.location.search.replace(/^\?/, ''));
    } catch (e) {}

    // If this page is opened without from/to/month params (e.g. deep link with only shift_id),
    // restore the last known position so the calendar doesn't jump back to default.
    try {
      const qs = new URLSearchParams(window.location.search);
      const hasFrom = qs.has('from');
      const hasTo = qs.has('to');
      const hasMonth = qs.has('month');
      if (!hasFrom || !hasTo || !hasMonth) {
        const saved = sessionStorage.getItem(KEY) || '';
        if (saved) {
          const savedQs = new URLSearchParams(saved);
          // Keep explicit shift_id if provided on this URL.
          if (qs.get('shift_id')) savedQs.set('shift_id', qs.get('shift_id'));
          // Prevent infinite loops: only redirect if we're actually missing params.
          const target = window.location.pathname + '?' + savedQs.toString();
          if (target !== window.location.pathname + window.location.search) {
            window.location.replace(target);
          }
        }
      }
    } catch (e) {}
  })();

// Filters: auto-submit on change (no Apply button).
  (function() {
    const form = document.getElementById('filters');
    if (!form) return;

    const shiftId = form.querySelector('input[name="shift_id"]');
    const month = form.querySelector('input[name="month"]');
    const from = form.querySelector('input[name="from"]');
    const to = form.querySelector('input[name="to"]');

    function syncMonth() {
      if (!month || !from) return;
      if (from.value && /^\d{4}-\d{2}-\d{2}$/.test(from.value)) {
        month.value = from.value.slice(0,7);
      }
    }

    form.addEventListener('change', (ev) => {
      if (shiftId) shiftId.value = '0';
      if (ev && (ev.target === from || ev.target === to)) syncMonth();
      try { sessionStorage.setItem('shift_editor_scrollY', String(window.scrollY || 0)); } catch (e) {}
      form.submit();
    });

    syncMonth();
  })();

  // Editor helpers (round/quick close/nudge)
  (function() {
    const cin = document.getElementById('clock_in_local');
    const cout = document.getElementById('clock_out_local');

    function parseLocal(v) {
      if (!v || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(v)) return null;
      const d = new Date(v);
      return isNaN(d.getTime()) ? null : d;
    }
    function fmt(d) {
      const pad = (n) => String(n).padStart(2,'0');
      return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    document.querySelectorAll('.roundHour').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!cin) return;
        const d = parseLocal(cin.value);
        if (!d) return;
        const dir = btn.getAttribute('data-dir') || 'down';
        const mins = d.getMinutes();
        if (mins === 0) return;
        if (dir === 'up') {
          d.setHours(d.getHours() + 1);
          d.setMinutes(0,0,0);
        } else {
          d.setMinutes(0,0,0);
        }
        cin.value = fmt(d);
      });
    });

    document.querySelectorAll('.quickClose').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!cin || !cout) return;
        const d = parseLocal(cin.value);
        if (!d) return;
        const h = parseInt(btn.getAttribute('data-hours') || '0', 10);
        if (!h) return;
        const out = new Date(d.getTime() + h*60*60*1000);
        cout.value = fmt(out);
      });
    });

    document.querySelectorAll('.nudgeOut').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!cout) return;
        const d = parseLocal(cout.value);
        if (!d) return;
        const mins = parseInt(btn.getAttribute('data-mins') || '0', 10);
        if (!mins) return;
        const out = new Date(d.getTime() + mins*60*1000);
        cout.value = fmt(out);
      });
    });
  })();

  // When a calendar day is clicked (single-day view), keep Training date/time aligned.
  (function() {
    // If the current view is a single day, ensure the training_start_local date matches that day (keep time).
    try {
      const qs = new URLSearchParams(window.location.search);
      const from = qs.get('from');
      const to = qs.get('to');
      const t = document.getElementById('training_start_local');
      if (t && from && to && from === to && /^\d{4}-\d{2}-\d{2}$/.test(from)) {
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(t.value)) {
          t.value = from + t.value.slice(10);
        } else {
          t.value = from + 'T08:00';
        }
      }
    } catch (e) {}
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
</script>

<?php admin_page_end();
