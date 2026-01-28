<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_shifts');

/**
 * Shifts
 * - Filter presets (today/yesterday/this week/last week/this month/last month/custom)
 * - From/To only (mode auto-populates)
 * - Show Clock In / Clock Out with MISSING badges
 * - Duration shown as HH:MM (no raw minutes)
 * - Bulk approve selected (manager/admin with approve_shifts)
 * - Exclude voided shifts by default (close_reason='void')
 */

function sc_shift_reason_badge(array $s): string {
  $created = (string)($s['created_source'] ?? '');
  $updated = (string)($s['updated_source'] ?? '');
  $reason  = (string)($s['last_modified_reason'] ?? '');
  $bits = [];
  if ($created !== '') $bits[] = 'Created: ' . $created;
  if ($updated !== '' && $updated !== $created) $bits[] = 'Updated: ' . $updated;
  if ($reason !== '') $bits[] = 'Reason: ' . $reason;
  if (!$bits) return '—';
  return implode(' · ', $bits);
}

function badge(string $text, string $kind): string {
  $base = "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold border";
  if ($kind === 'ok')   return "<span class='$base bg-emerald-500/10 border-emerald-400/20 text-slate-900'>$text</span>";
  if ($kind === 'warn') return "<span class='$base bg-amber-500/10 border-amber-400/20 text-slate-900'>$text</span>";
  if ($kind === 'bad')  return "<span class='$base bg-rose-500/10 border-rose-400/20 text-slate-900'>$text</span>";
  return "<span class='$base bg-white border border-slate-200 text-slate-700'>$text</span>";
}

function fmt_hhmm(?int $minutes): string {
  if ($minutes === null || $minutes < 0) return '—';
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  return sprintf('%02d:%02d', $h, $m);
}

// ----------------------
// POST actions
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  admin_verify_csrf($_POST['csrf'] ?? null);

  // Single shift actions
  $shiftId = (int)($_POST['shift_id'] ?? 0);

  // Bulk approve
  if ($action === 'bulk_approve') {
    admin_require_perm($user, 'approve_shifts');
    $ids = $_POST['shift_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

    if ($ids) {
      $pdo->beginTransaction();
      try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT * FROM kiosk_shifts WHERE id IN ($in) FOR UPDATE");
        $st->execute($ids);
        $shifts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $approvedIds = [];
        foreach ($shifts as $s) {
          $locked = !empty($s['payroll_locked_at']);
          $voided = ((string)($s['close_reason'] ?? '')) === 'void';
          $hasIn = !empty($s['clock_in_at']);
          $hasOut = !empty($s['clock_out_at']);
          $alreadyApproved = !empty($s['approved_at']);

          if ($locked || $voided || $alreadyApproved || !$hasIn || !$hasOut) continue;

          $eff = admin_shift_effective($s);
          $snapshot = [
            'effective' => $eff,
            'payroll_lock' => [
              'locked_at' => $s['payroll_locked_at'] ?? null,
              'batch_id'  => $s['payroll_batch_id'] ?? null,
            ],
            'is_callout' => (int)($s['is_callout'] ?? 0),
            'bulk' => 1,
          ];

          // Best-effort audit
          try {
            $ins = $pdo->prepare(
              "INSERT INTO kiosk_shift_changes
                (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
               VALUES
                (?, 'approve', ?, ?, ?, 'approve', ?, NULL, ?)"
            );
            $ins->execute([
              (int)$s['id'],
              (int)($user['user_id'] ?? 0),
              (string)($user['username'] ?? ''),
              (string)($user['role'] ?? ''),
              'Bulk approved',
              json_encode($snapshot),
            ]);
          } catch (Throwable $e) {
            // ignore
          }

          $approvedIds[] = (int)$s['id'];
        }

        if ($approvedIds) {
          $in2 = implode(',', array_fill(0, count($approvedIds), '?'));
          $upd = $pdo->prepare("UPDATE kiosk_shifts SET approved_at=UTC_TIMESTAMP, approved_by=?, approval_note=?, updated_source='admin' WHERE id IN ($in2)");
          $params = array_merge([(string)($user['username'] ?? ''), 'Bulk approved'], $approvedIds);
          $upd->execute($params);
        }

        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
      }
    }

    admin_redirect(admin_url('shifts.php?' . http_build_query($_GET)));
  }

  // Single shift actions (approve/unapprove/unlock_payroll)
  if ($shiftId > 0) {
    $stmt = $pdo->prepare(
      "SELECT s.*,
              c.new_json AS latest_edit_json
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
       WHERE s.id = ?
       LIMIT 1"
    );
    $stmt->execute([$shiftId]);
    $shiftRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($shiftRow) {
      $isLocked = !empty($shiftRow['payroll_locked_at']);

      if ($action === 'unlock_payroll') {
        // ✅ LOCKED RULE: only superadmin has this perm
        admin_require_perm($user, 'unlock_payroll_locked_shifts');

        $pdo->beginTransaction();
        try {
          $upd = $pdo->prepare(
            "UPDATE kiosk_shifts
             SET payroll_locked_at = NULL,
                 payroll_locked_by = NULL,
                 payroll_batch_id  = NULL,
                 updated_source='admin'
             WHERE id = ?"
          );
          $upd->execute([$shiftId]);

          // Best-effort audit
          try {
            $meta = ['note' => trim((string)($_POST['note'] ?? 'Unlocked by Super Admin'))];

            $ins = $pdo->prepare(
              "INSERT INTO kiosk_shift_changes
                (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
               VALUES
                (?, 'payroll_unlock', ?, ?, ?, 'payroll_unlock', ?, NULL, ?)"
            );
            $ins->execute([
              $shiftId,
              (int)($user['user_id'] ?? 0),
              (string)($user['username'] ?? ''),
              (string)($user['role'] ?? ''),
              $meta['note'] !== '' ? $meta['note'] : null,
              json_encode($meta),
            ]);
          } catch (Throwable $e) {
            // ignore
          }

          $pdo->commit();
        } catch (Throwable $e) {
          $pdo->rollBack();
        }

        admin_redirect(admin_url('shifts.php?' . http_build_query($_GET)));
      }

      if ($isLocked && ($action === 'approve' || $action === 'unapprove')) {
        admin_redirect(admin_url('shifts.php?' . http_build_query($_GET + ['n' => 'locked'])));
      }

      $eff = admin_shift_effective($shiftRow);
      $snapshot = [
        'effective' => $eff,
        'payroll_lock' => [
          'locked_at' => $shiftRow['payroll_locked_at'] ?? null,
          'batch_id'  => $shiftRow['payroll_batch_id'] ?? null,
        ],
        'is_callout' => (int)($shiftRow['is_callout'] ?? 0),
      ];

      if ($action === 'approve') {
        admin_require_perm($user, 'approve_shifts');
        $note = trim((string)($_POST['note'] ?? ''));
        $isCallout = (int)($_POST['is_callout'] ?? 0) === 1 ? 1 : 0;
        $snapshot['is_callout'] = $isCallout;

        $pdo->beginTransaction();
        try {
          $ins = $pdo->prepare(
            "INSERT INTO kiosk_shift_changes
              (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
             VALUES
              (?, 'approve', ?, ?, ?, 'approve', ?, NULL, ?)"
          );
          $ins->execute([
            $shiftId,
            (int)($user['user_id'] ?? 0),
            (string)($user['username'] ?? ''),
            (string)($user['role'] ?? ''),
            $note !== '' ? $note : null,
            json_encode($snapshot),
          ]);

          $upd = $pdo->prepare("UPDATE kiosk_shifts SET approved_at=UTC_TIMESTAMP, approved_by=?, approval_note=?, is_callout=?, updated_source='admin' WHERE id=?");
          $upd->execute([
            (string)($user['username'] ?? ''),
            $note !== '' ? $note : null,
            $isCallout,
            $shiftId
          ]);

          $pdo->commit();
        } catch (Throwable $e) {
          $pdo->rollBack();
        }
      }

      if ($action === 'unapprove') {
        admin_require_perm($user, 'approve_shifts');

        $pdo->beginTransaction();
        try {
          $ins = $pdo->prepare(
            "INSERT INTO kiosk_shift_changes
              (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
             VALUES
              (?, 'unapprove', ?, ?, ?, 'unapprove', NULL, ?, NULL)"
          );
          $ins->execute([
            $shiftId,
            (int)($user['user_id'] ?? 0),
            (string)($user['username'] ?? ''),
            (string)($user['role'] ?? ''),
            json_encode($snapshot),
          ]);

          $upd = $pdo->prepare("UPDATE kiosk_shifts SET approved_at=NULL, approved_by=NULL, approval_note=NULL, updated_source='admin' WHERE id=?");
          $upd->execute([$shiftId]);

          $pdo->commit();
        } catch (Throwable $e) {
          $pdo->rollBack();
        }
      }
    }
  }

  admin_redirect(admin_url('shifts.php?' . http_build_query($_GET)));
}

// ----------------------
// GET (filters + data)
// ----------------------
admin_page_start($pdo, 'Shifts');

// ✅ IMPORTANT FIX: generate CSRF once for the whole page.
$csrf = admin_csrf_token();

$period = (string)($_GET['period'] ?? 'this_week');
$empId = (int)($_GET['employee_id'] ?? 0);
$status = (string)($_GET['status'] ?? 'all');

$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$from = (string)($_GET['from'] ?? $today->format('Y-m-d'));
$to   = (string)($_GET['to']   ?? $today->format('Y-m-d'));

try { $fromDt = (new DateTimeImmutable($from, new DateTimeZone('UTC')))->setTime(0,0,0); }
catch (Throwable $e) { $fromDt = $today->setTime(0,0,0); }

try { $toDt = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->setTime(0,0,0); }
catch (Throwable $e) { $toDt = $fromDt; }

$endDt = $toDt->modify('+1 day');
$startSql = $fromDt->format('Y-m-d H:i:s');
$endSql   = $endDt->format('Y-m-d H:i:s');

// Load employees for filter dropdown
$emps = $pdo->query(
  "SELECT id, " . admin_sql_employee_display_name('kiosk_employees') . " AS full_name, is_agency, agency_label
   FROM kiosk_employees
   WHERE is_active = 1
   ORDER BY first_name ASC, last_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// ✅ FIX: allow missing clock-in shifts to appear by anchoring date filter on COALESCE(clock_in_at, created_at)
// NOTE: if your column is not created_at, replace it with the correct created timestamp column.
$where = ["COALESCE(s.clock_in_at, s.created_at) >= ? AND COALESCE(s.clock_in_at, s.created_at) < ?"];
$params = [$startSql, $endSql];

if ($empId > 0) { $where[] = "s.employee_id = ?"; $params[] = $empId; }

if ($status === 'voided') $where[] = "s.close_reason = 'void'";
else $where[] = "(s.close_reason IS NULL OR s.close_reason <> 'void')";

// ✅ FIX: open means missing IN or missing OUT (matches your lifecycle definition)
if ($status === 'open') $where[] = "(s.clock_in_at IS NULL OR s.clock_out_at IS NULL)";
elseif ($status === 'closed') $where[] = "s.clock_out_at IS NOT NULL";
elseif ($status === 'approved') $where[] = "s.approved_at IS NOT NULL";
elseif ($status === 'unapproved') $where[] = "s.approved_at IS NULL";
elseif ($status === 'missing_out') $where[] = "s.clock_out_at IS NULL";
elseif ($status === 'missing_in') $where[] = "s.clock_in_at IS NULL";

$sql = "
  SELECT s.*,
         " . admin_sql_employee_display_name('e') . " AS full_name,
         " . admin_sql_employee_number('e') . " AS employee_number,
         e.is_agency, e.agency_label,
         cat.name AS department_name,
         c.new_json AS latest_edit_json
  FROM kiosk_shifts s
  LEFT JOIN kiosk_employees e ON e.id = s.employee_id
  LEFT JOIN kiosk_employee_departments cat ON cat.id = e.department_id
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
  WHERE " . implode(" AND ", $where) . "
  ORDER BY s.clock_in_at DESC
  LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Shifts</h1>
                <p class="mt-2 text-sm text-slate-600">Filter, edit, approve, and quickly spot missing clock-in/clock-out.</p>
              </div>
              <div class="flex items-center gap-2">
                <?php if (admin_can($user, 'edit_shifts')): ?>
                  <a href="<?= h(admin_url('shift-add.php')) ?>" class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold hover:bg-white/90">Add shift</a>
                <?php endif; ?>
              </div>
            </div>
          </header>

          <?php if ((string)($_GET['n'] ?? '') === 'locked'): ?>
            <div class="mt-5 rounded-3xl border border-rose-400/20 bg-rose-500/10 p-5 text-sm text-slate-900">
              This shift is <b>Payroll Locked</b> and cannot be edited or (un)approved. Super Admin can unlock if needed.
            </div>
          <?php endif; ?>

          <form method="get" id="shift-filters" class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">

              <label class="md:col-span-2">
                <div class="text-xs uppercase tracking-widest text-slate-500">Period</div>
                <select name="period" id="period" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                  <?php foreach ([
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'this_week' => 'This week',
                    'last_week' => 'Last week',
                    'this_month' => 'This month',
                    'last_month' => 'Last month',
                    'custom' => 'Custom',
                  ] as $k=>$lab): ?>
                    <option value="<?= h($k) ?>" <?= $period===$k?'selected':'' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-slate-500">From</div>
                <input type="date" name="from" id="from" value="<?= h($fromDt->format('Y-m-d')) ?>" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"/>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-slate-500">To</div>
                <input type="date" name="to" id="to" value="<?= h($toDt->format('Y-m-d')) ?>" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"/>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-slate-500">Employee</div>
                <select name="employee_id" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                  <option value="0">All</option>
                  <?php foreach ($emps as $e): ?>
                    <?php
                      $label = (string)$e['full_name'];
                      if ((int)$e['is_agency'] === 1) {
                        $al = trim((string)$e['agency_label']);
                        $label = ($al !== '' ? $al : $label) . ' (Agency)';
                      }
                    ?>
                    <option value="<?= (int)$e['id'] ?>" <?= $empId===(int)$e['id']?'selected':'' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-slate-500">Status</div>
                <select name="status" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                  <?php foreach ([
                    'all'=>'All',
                    'open'=>'Open',
                    'closed'=>'Closed',
                    'missing_in'=>'Missing clock-in',
                    'missing_out'=>'Missing clock-out',
                    'approved'=>'Approved',
                    'unapproved'=>'Unapproved',
                    'voided'=>'Voided',
                  ] as $k=>$lab): ?>
                    <option value="<?= h($k) ?>" <?= $status===$k?'selected':'' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

            </div>

            <div class="mt-4 flex items-center justify-between">
              <div class="text-xs text-slate-500">Showing up to 500 shifts • Week runs Monday → Sunday</div>
              <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Apply filters</button>
            </div>
          </form>

          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5 overflow-x-auto">

            <?php if (admin_can($user, 'approve_shifts')): ?>
              <form method="post" id="bulk-approve-form" class="mb-4 flex items-center justify-between gap-3">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>"/>
                <input type="hidden" name="action" value="bulk_approve"/>
                <div class="text-sm text-slate-600">
                  <span class="font-semibold">Bulk approve</span> (selected only)
                </div>
                <button type="submit" class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold hover:bg-white/90" onclick="return confirm('Approve selected shifts? Only closed, unapproved, unlocked shifts will be approved.');">
                  Approve selected
                </button>
              </form>
            <?php endif; ?>

            <table class="min-w-full text-sm">
              <thead class="text-xs uppercase tracking-widest text-slate-500">
                <tr>
                  <?php if (admin_can($user, 'approve_shifts')): ?>
                    <th class="text-left py-3 pr-4"><input type="checkbox" id="select-all" class="h-4 w-4 rounded" /></th>
                  <?php endif; ?>
                  <th class="text-left py-3 pr-4">Employee</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">Reason</th>
                  <th class="text-left py-3 pr-4">Clock In</th>
                  <th class="text-left py-3 pr-4">Clock Out</th>
                  <th class="text-left py-3 pr-4">Duration</th>
                  <th class="text-left py-3 pr-4">Status</th>
                  <th class="text-right py-3">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                <?php if (!$rows): ?>
                  <tr>
                    <td colspan="<?= admin_can($user,'approve_shifts') ? '8' : '7' ?>" class="py-6 text-center text-slate-500">No shifts found for this filter.</td>
                  </tr>
                <?php endif; ?>

                <?php foreach ($rows as $r): ?>
                  <?php
                    $eff = admin_shift_effective($r);
                    $effIn = $eff['clock_in_at'];
                    $effOut = $eff['clock_out_at'];

                    $mins = admin_minutes_between($effIn ?: null, $effOut ?: null);

                    $approved = !empty($r['approved_at']);
                    $locked = !empty($r['payroll_locked_at']);
                    $voided = ((string)($r['close_reason'] ?? '')) === 'void';
                    $edited = ((string)($r['latest_edit_json'] ?? '')) !== '';

                    $name = (string)($r['full_name'] ?? 'Unknown');
                    if ((int)($r['is_agency'] ?? 0) === 1) {
                      $al = trim((string)($r['agency_label'] ?? 'Agency'));
                      $name = ($al !== '' ? $al : $name) . ' (Agency)';
                    }

                    $statusBadge = '';
                    if ($voided) {
                      $statusBadge = badge('Voided', 'bad');
                    } else {
                      $statusBadge = $approved ? badge('Approved', 'ok') : badge('Unapproved', 'warn');
                      if (empty($effIn)) $statusBadge .= ' ' . badge('Missing IN', 'bad');
                      if (empty($effOut)) $statusBadge .= ' ' . badge('Missing OUT', 'bad');
                      if ($edited) $statusBadge .= ' ' . badge('Edited', 'neutral');
                      if ($locked) $statusBadge .= ' ' . badge('Payroll Locked', 'bad');
                    }

                    $eligibleForBulk = (!$voided && !$locked && !$approved && !empty($effIn) && !empty($effOut));
                  ?>
                  <tr>
                    <?php if (admin_can($user, 'approve_shifts')): ?>
                      <td class="py-4 pr-4">
                        <input type="checkbox" name="shift_ids[]" value="<?= (int)$r['id'] ?>" form="bulk-approve-form" class="h-4 w-4 rounded" <?= $eligibleForBulk ? '' : 'disabled' ?> />
                      </td>
                    <?php endif; ?>

                    <td class="py-4 pr-4">
                      <div class="font-semibold"><?= h($name) ?></div>
                      <div class="text-xs text-slate-500">
                        <?= h((string)($r['department_name'] ?? '—')) ?>
                        <?php if (!empty($r['employee_number'])): ?> • #<?= h((string)$r['employee_number']) ?><?php endif; ?>
                      </div>
                    </td>

                    <td class="px-4 py-4 text-slate-700">
                      <div class="text-sm"><?= h(sc_shift_reason_badge($r)) ?></div>
                    </td>

                    <td class="py-4 pr-4 text-slate-700">
                      <?php if (!empty($effIn)): ?>
                        <?= h(admin_fmt_dt($effIn)) ?>
                      <?php else: ?>
                        <?= badge('MISSING IN', 'bad') ?>
                      <?php endif; ?>
                    </td>

                    <td class="py-4 pr-4 text-slate-700">
                      <?php if (!empty($effOut)): ?>
                        <?= h(admin_fmt_dt($effOut)) ?>
                      <?php else: ?>
                        <?= badge('MISSING OUT', 'bad') ?>
                      <?php endif; ?>
                    </td>

                    <td class="py-4 pr-4">
                      <div class="font-semibold"><?= h(fmt_hhmm($mins)) ?></div>
                      <?php if ($eff['break_minutes'] !== null): ?>
                        <div class="text-xs text-slate-500 mt-1">Break: <?= (int)$eff['break_minutes'] ?>m</div>
                      <?php endif; ?>
                    </td>

                    <td class="py-4 pr-4"><?= $statusBadge ?></td>

                    <td class="py-4 text-right">
                      <div class="flex items-center justify-end gap-2">

                        <?php if (admin_can($user, 'edit_shifts') && !$locked): ?>
                          <?php if (empty($effIn) || empty($effOut)): ?>
                            <?php $focus = empty($effIn) ? 'in' : 'out'; ?>
                            <a href="<?= h(admin_url('shift-edit.php?id=' . (int)$r['id'] . '&focus=' . $focus)) ?>" class="rounded-2xl bg-amber-500/10 border border-amber-400/20 px-3 py-2 text-xs hover:bg-amber-500/15">Fix</a>
                          <?php endif; ?>
                          <a href="<?= h(admin_url('shift-edit.php?id=' . (int)$r['id'])) ?>" class="rounded-2xl bg-slate-50 border border-slate-200 px-3 py-2 text-xs hover:bg-slate-100">Edit</a>
                        <?php endif; ?>

                        <?php if (admin_can($user, 'approve_shifts') && !$locked && !$voided): ?>
                          <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"/>
                            <input type="hidden" name="shift_id" value="<?= (int)$r['id'] ?>"/>
                            <?php if ($approved): ?>
                              <input type="hidden" name="action" value="unapprove"/>
                              <button class="rounded-2xl bg-white border border-slate-200 px-3 py-2 text-xs hover:bg-slate-50">Unapprove</button>
                            <?php else: ?>
                              <input type="hidden" name="action" value="approve"/>
                              <label class="inline-flex items-center gap-2 mr-2 text-xs text-slate-600">
                                <input type="checkbox" name="is_callout" value="1" class="h-4 w-4 rounded" <?= ((int)($r['is_callout'] ?? 0)===1) ? 'checked' : '' ?> />
                                Call-out
                              </label>
                              <button class="rounded-2xl bg-white text-slate-900 px-3 py-2 text-xs font-semibold hover:bg-white/90">Approve</button>
                            <?php endif; ?>
                          </form>
                        <?php endif; ?>

                        <?php if ($locked): ?>
                          <span class="rounded-2xl bg-white border border-slate-200 px-3 py-2 text-xs text-slate-500">Locked</span>
                        <?php endif; ?>

                        <?php if ($locked && admin_can($user, 'unlock_payroll_locked_shifts')): ?>
                          <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"/>
                            <input type="hidden" name="shift_id" value="<?= (int)$r['id'] ?>"/>
                            <input type="hidden" name="action" value="unlock_payroll"/>
                            <button class="rounded-2xl bg-rose-500/10 border border-rose-400/20 px-3 py-2 text-xs hover:bg-rose-500/15">Unlock</button>
                          </form>
                        <?php endif; ?>

                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>

              </tbody>
            </table>
          </section>

          <div class="mt-4 text-xs text-slate-500">
            Tip: edits are stored in <code class="px-2 py-1 rounded-xl bg-slate-50">kiosk_shift_changes</code> and never overwrite originals.
          </div>

        </main>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const periodEl = document.getElementById('period');
  const fromEl = document.getElementById('from');
  const toEl = document.getElementById('to');
  const selectAll = document.getElementById('select-all');

  function pad(n){ return String(n).padStart(2,'0'); }
  function ymd(d){
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
  }

  function startOfWeekMonday(d){
    const day = d.getDay(); // 0 Sun..6 Sat
    const diff = (day === 0 ? -6 : 1 - day); // Monday
    const res = new Date(d);
    res.setDate(d.getDate() + diff);
    return res;
  }

  function endOfWeekSunday(d){
    const s = startOfWeekMonday(d);
    const e = new Date(s);
    e.setDate(s.getDate() + 6);
    return e;
  }

  function startOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
  function endOfMonth(d){ return new Date(d.getFullYear(), d.getMonth()+1, 0); }

  function applyPeriod(p){
    const now = new Date();
    let a = null, b = null;

    if (p === 'today') {
      a = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      b = new Date(a);
    } else if (p === 'yesterday') {
      a = new Date(now.getFullYear(), now.getMonth(), now.getDate()-1);
      b = new Date(a);
    } else if (p === 'this_week') {
      a = startOfWeekMonday(now);
      b = endOfWeekSunday(now);
    } else if (p === 'last_week') {
      const last = new Date(now);
      last.setDate(now.getDate() - 7);
      a = startOfWeekMonday(last);
      b = endOfWeekSunday(last);
    } else if (p === 'this_month') {
      a = startOfMonth(now);
      b = endOfMonth(now);
    } else if (p === 'last_month') {
      const last = new Date(now.getFullYear(), now.getMonth()-1, 1);
      a = startOfMonth(last);
      b = endOfMonth(last);
    } else {
      return; // custom
    }

    if (a && b) {
      fromEl.value = ymd(a);
      toEl.value = ymd(b);
    }
  }

  if (periodEl && fromEl && toEl) {
    periodEl.addEventListener('change', function(){
      if (periodEl.value === 'custom') return;
      applyPeriod(periodEl.value);
    });

    fromEl.addEventListener('change', function(){ periodEl.value = 'custom'; });
    toEl.addEventListener('change', function(){ periodEl.value = 'custom'; });
  }

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const boxes = document.querySelectorAll("input[name='shift_ids[]']");
      for (const b of boxes) {
        if (b.disabled) continue;
        b.checked = selectAll.checked;
      }
    });
  }
})();
</script>

<?php admin_page_end(); ?>
