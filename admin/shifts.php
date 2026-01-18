<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_shifts');

// Actions: approve / unapprove / unlock_payroll
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  admin_verify_csrf($_POST['csrf'] ?? null);

  $shiftId = (int)($_POST['shift_id'] ?? 0);
  if ($shiftId > 0) {

    // Load shift + latest edit json for snapshot (also includes payroll lock fields)
    $stmt = $pdo->prepare("
      SELECT s.*,
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
      LIMIT 1
    ");
    $stmt->execute([$shiftId]);
    $shiftRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($shiftRow) {
      $isLocked = !empty($shiftRow['payroll_locked_at']);

      // Unlock payroll (Super Admin only)
      if ($action === 'unlock_payroll') {
        // Only Super Admin/high permissions can unlock
        admin_require_perm($user, 'manage_settings_high');

        $pdo->beginTransaction();
        try {
          $upd = $pdo->prepare("
            UPDATE kiosk_shifts
            SET payroll_locked_at = NULL,
                payroll_locked_by = NULL,
                payroll_batch_id  = NULL,
                updated_source='admin'
            WHERE id = ?
          ");
          $upd->execute([$shiftId]);

          // Best-effort audit (requires enum includes payroll_unlock)
          try {
            $meta = [
              'note' => trim((string)($_POST['note'] ?? 'Unlocked by Super Admin')),
            ];

            $ins = $pdo->prepare("
              INSERT INTO kiosk_shift_changes
                (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
              VALUES
                (?, 'payroll_unlock', ?, ?, ?, 'payroll_unlock', ?, NULL, ?)
            ");
            $ins->execute([
              $shiftId,
              (int)$user['user_id'],
              (string)($user['username'] ?? ''),
              (string)($user['role'] ?? ''),
              $meta['note'] !== '' ? $meta['note'] : null,
              json_encode($meta),
            ]);
          } catch (Throwable $e) {
            // ignore audit insert errors (enum/table may not yet support)
          }

          $pdo->commit();
        } catch (Throwable $e) {
          $pdo->rollBack();
        }

        admin_redirect(admin_url('shifts.php?' . http_build_query($_GET)));
      }

      // If payroll locked, block approve/unapprove (and any future actions)
      if ($isLocked && ($action === 'approve' || $action === 'unapprove')) {
        admin_redirect(admin_url('shifts.php?' . http_build_query($_GET + ['n' => 'locked'])));
      }

      $eff = admin_shift_effective($shiftRow);

      // rounding snapshot
      $roundingEnabled = admin_setting_bool($pdo, 'rounding_enabled', true);
      $inc = admin_setting_int($pdo, 'round_increment_minutes', 15);
      $grace = admin_setting_int($pdo, 'round_grace_minutes', 5);
      $roundedIn  = $roundingEnabled ? admin_round_datetime($eff['clock_in_at'] ?: null, $inc, $grace) : ($eff['clock_in_at'] ?: null);
      $roundedOut = $roundingEnabled ? admin_round_datetime($eff['clock_out_at'] ?: null, $inc, $grace) : ($eff['clock_out_at'] ?: null);

      $snapshot = [
        'effective' => $eff,
        'rounded' => [
          'in' => $roundedIn,
          'out' => $roundedOut,
          'increment' => $inc,
          'grace' => $grace,
          'enabled' => $roundingEnabled ? 1 : 0,
        ],
        'payroll_lock' => [
          'locked_at' => $shiftRow['payroll_locked_at'] ?? null,
          'batch_id'  => $shiftRow['payroll_batch_id'] ?? null,
        ],
        'is_callout' => (int)($shiftRow['is_callout'] ?? 0),
      ];

      if ($action === 'approve') {
        admin_require_perm($user, 'approve_shifts');
        $note = trim((string)($_POST['note'] ?? ''));
        $isCallout = (int)($_POST['is_callout'] ?? 0)===1 ? 1 : 0;
        $snapshot['is_callout'] = $isCallout;

        $pdo->beginTransaction();
        try {
          $ins = $pdo->prepare("
            INSERT INTO kiosk_shift_changes
              (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
            VALUES
              (?, 'approve', ?, ?, ?, 'approve', ?, NULL, ?)
          ");
          $ins->execute([
            $shiftId,
            (int)$user['user_id'],
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
          $ins = $pdo->prepare("
            INSERT INTO kiosk_shift_changes
              (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
            VALUES
              (?, 'unapprove', ?, ?, ?, 'unapprove', NULL, ?, NULL)
          ");
          $ins->execute([
            $shiftId,
            (int)$user['user_id'],
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

  // Redirect back to GET (PRG)
  admin_redirect(admin_url('shifts.php?' . http_build_query($_GET)));
}

admin_page_start($pdo, 'Shifts');
$active = admin_url('shifts.php');

// Filters
$mode = (string)($_GET['mode'] ?? 'week'); // day|week|month|range
$baseDate = (string)($_GET['date'] ?? gmdate('Y-m-d'));
$empId = (int)($_GET['employee_id'] ?? 0);
$status = (string)($_GET['status'] ?? 'all'); // all|open|closed|approved|unapproved|missing_out

$start = $baseDate;
$end = $baseDate;

try {
  $d = new DateTimeImmutable($baseDate, new DateTimeZone('UTC'));
} catch (Throwable $e) {
  $d = new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

if ($mode === 'day') {
  $startDt = $d->setTime(0,0,0);
  $endDt = $startDt->modify('+1 day');
} elseif ($mode === 'month') {
  $startDt = $d->modify('first day of this month')->setTime(0,0,0);
  $endDt = $startDt->modify('+1 month');
} elseif ($mode === 'range') {
  $from = (string)($_GET['from'] ?? $d->format('Y-m-d'));
  $to = (string)($_GET['to'] ?? $d->format('Y-m-d'));
  try { $startDt = (new DateTimeImmutable($from, new DateTimeZone('UTC')))->setTime(0,0,0); } catch(Throwable $e){ $startDt=$d->setTime(0,0,0); }
  try { $endDt = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->setTime(0,0,0)->modify('+1 day'); } catch(Throwable $e){ $endDt=$startDt->modify('+7 day'); }
} else {
  // week
  $startDt = $d->modify('monday this week')->setTime(0,0,0);
  $endDt = $startDt->modify('+7 day');
}

$startSql = $startDt->format('Y-m-d H:i:s');
$endSql   = $endDt->format('Y-m-d H:i:s');

// Load employees for filter dropdown
// IMPORTANT: kiosk_employees does not have a full_name column. Compute display name from schema.
$emps = $pdo->query(
  "SELECT id, " . admin_sql_employee_display_name('kiosk_employees') . " AS full_name, is_agency, agency_label\n"
  . "FROM kiosk_employees\n"
  . "WHERE is_active = 1\n"
  . "ORDER BY first_name ASC, last_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Query shifts with latest edit json
$where = ["s.clock_in_at >= ? AND s.clock_in_at < ?"];
$params = [$startSql, $endSql];

if ($empId > 0) {
  $where[] = "s.employee_id = ?";
  $params[] = $empId;
}

if ($status === 'open') {
  $where[] = "s.clock_out_at IS NULL";
} elseif ($status === 'closed') {
  $where[] = "s.clock_out_at IS NOT NULL";
} elseif ($status === 'approved') {
  $where[] = "s.approved_at IS NOT NULL";
} elseif ($status === 'unapproved') {
  $where[] = "s.approved_at IS NULL";
} elseif ($status === 'missing_out') {
  $where[] = "s.clock_out_at IS NULL";
}

$sql = "
  SELECT s.*,
         " . admin_sql_employee_display_name('e') . " AS full_name,
         " . admin_sql_employee_number('e') . " AS employee_number,
         e.is_agency, e.agency_label,
         cat.name AS category_name,
         c.new_json AS latest_edit_json
  FROM kiosk_shifts s
  LEFT JOIN kiosk_employees e ON e.id = s.employee_id
  LEFT JOIN kiosk_employee_categories cat ON cat.id = e.category_id
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
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Rounding settings
$roundingEnabled = admin_setting_bool($pdo, 'rounding_enabled', true);
$inc = admin_setting_int($pdo, 'round_increment_minutes', 15);
$grace = admin_setting_int($pdo, 'round_grace_minutes', 5);

function badge(string $text, string $kind): string {
  $base = "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold border";
  if ($kind === 'ok') return "<span class='$base bg-emerald-500/10 border-emerald-400/20 text-emerald-100'>$text</span>";
  if ($kind === 'warn') return "<span class='$base bg-amber-500/10 border-amber-400/20 text-amber-100'>$text</span>";
  if ($kind === 'bad') return "<span class='$base bg-rose-500/10 border-rose-400/20 text-rose-100'>$text</span>";
  return "<span class='$base bg-white/5 border border-white/10 text-white/80'>$text</span>";
}

?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Shifts</h1>
                <p class="mt-2 text-sm text-white/70">Filter, edit (without overwriting originals), approve, and see rounded payroll times.</p>
              </div>
              <?php if (admin_can($user, 'manage_settings')): ?>
                <a href="<?= h(admin_url('settings.php')) ?>" class="rounded-2xl bg-white/10 border border-white/10 px-4 py-2 text-sm hover:bg-white/15">Rounding settings</a>
              <?php endif; ?>
            </div>
          </header>

          <?php if ((string)($_GET['n'] ?? '') === 'locked'): ?>
            <div class="mt-5 rounded-3xl border border-rose-400/20 bg-rose-500/10 p-5 text-sm text-rose-100">
              This shift is <b>Payroll Locked</b> and cannot be edited or (un)approved. Super Admin can unlock if needed.
            </div>
          <?php endif; ?>

          <form method="get" class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-white/50">Mode</div>
                <select name="mode" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                  <?php foreach (['day'=>'Day','week'=>'Week','month'=>'Month','range'=>'Range'] as $k=>$lab): ?>
                    <option value="<?= h($k) ?>" <?= $mode===$k?'selected':'' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-white/50">Date</div>
                <input type="date" name="date" value="<?= h($d->format('Y-m-d')) ?>" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30"/>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-white/50">From</div>
                <input type="date" name="from" value="<?= h((string)($_GET['from'] ?? $d->format('Y-m-d'))) ?>" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30"/>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-white/50">To</div>
                <input type="date" name="to" value="<?= h((string)($_GET['to'] ?? $d->format('Y-m-d'))) ?>" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30"/>
              </label>

              <label class="md:col-span-1">
                <div class="text-xs uppercase tracking-widest text-white/50">Employee</div>
                <select name="employee_id" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
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
                <div class="text-xs uppercase tracking-widest text-white/50">Status</div>
                <select name="status" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                  <?php foreach (['all'=>'All','open'=>'Open','closed'=>'Closed','missing_out'=>'Missing clock-out','approved'=>'Approved','unapproved'=>'Unapproved'] as $k=>$lab): ?>
                    <option value="<?= h($k) ?>" <?= $status===$k?'selected':'' ?>><?= h($lab) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
              <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Apply filters</button>
            </div>

            <div class="mt-3 text-xs text-white/50">
              Showing up to 500 shifts • Rounding: <?= $roundingEnabled ? 'On' : 'Off' ?> (inc <?= (int)$inc ?>, grace <?= (int)$grace ?>)
            </div>
          </form>

          <section class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="text-xs uppercase tracking-widest text-white/50">
                <tr>
                  <th class="text-left py-3 pr-4">Employee</th>
                  <th class="text-left py-3 pr-4">Original In/Out</th>
                  <th class="text-left py-3 pr-4">Effective In/Out</th>
                  <th class="text-left py-3 pr-4">Rounded In/Out</th>
                  <th class="text-left py-3 pr-4">Minutes</th>
                  <th class="text-left py-3 pr-4">Status</th>
                  <th class="text-right py-3">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/10">
                <?php if (!$rows): ?>
                  <tr><td colspan="7" class="py-6 text-center text-white/60">No shifts found for this filter.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $r): ?>
                  <?php
                    $eff = admin_shift_effective($r);
                    $origIn = (string)$r['clock_in_at'];
                    $origOut = (string)($r['clock_out_at'] ?? '');
                    $effIn = $eff['clock_in_at'];
                    $effOut = $eff['clock_out_at'];

                    $roundedIn  = $roundingEnabled ? admin_round_datetime($effIn ?: null, $inc, $grace) : ($effIn ?: null);
                    $roundedOut = $roundingEnabled ? admin_round_datetime($effOut ?: null, $inc, $grace) : ($effOut ?: null);

                    $mins = admin_minutes_between($effIn ?: null, $effOut ?: null);
                    $approved = !empty($r['approved_at']);
                    $locked = !empty($r['payroll_locked_at']);

                    $name = (string)($r['full_name'] ?? 'Unknown');
                    if ((int)($r['is_agency'] ?? 0) === 1) {
                      $al = trim((string)($r['agency_label'] ?? 'Agency'));
                      $name = ($al !== '' ? $al : $name) . ' (Agency)';
                    }

                    $statusBadge = $approved ? badge('Approved', 'ok') : badge('Unapproved', 'warn');
                    if (empty($r['clock_out_at'])) $statusBadge .= ' ' . badge('Missing OUT', 'bad');
                    if ((string)($r['latest_edit_json'] ?? '') !== '') $statusBadge .= ' ' . badge('Edited', 'neutral');
                    if ($locked) $statusBadge .= ' ' . badge('Payroll Locked', 'bad');
                  ?>
                  <tr>
                    <td class="py-4 pr-4">
                      <div class="font-semibold"><?= h($name) ?></div>
                      <div class="text-xs text-white/50">
                        <?= h((string)($r['category_name'] ?? '—')) ?>
                        <?php if (!empty($r['employee_number'])): ?> • #<?= h((string)$r['employee_number']) ?><?php endif; ?>
                      </div>
                    </td>
                    <td class="py-4 pr-4 text-white/80">
                      <div><?= h(admin_fmt_dt($origIn)) ?></div>
                      <div class="text-white/50"><?= h(admin_fmt_dt($origOut ?: null)) ?></div>
                    </td>
                    <td class="py-4 pr-4">
                      <div class="text-white/90"><?= h(admin_fmt_dt($effIn ?: null)) ?></div>
                      <div class="text-white/50"><?= h(admin_fmt_dt($effOut ?: null)) ?></div>
                      <?php if ($eff['break_minutes'] !== null): ?>
                        <div class="text-xs text-white/50 mt-1">Break: <?= (int)$eff['break_minutes'] ?>m</div>
                      <?php endif; ?>
                    </td>
                    <td class="py-4 pr-4">
                      <div class="text-white/90"><?= h(admin_fmt_dt($roundedIn)) ?></div>
                      <div class="text-white/50"><?= h(admin_fmt_dt($roundedOut)) ?></div>
                    </td>
                    <td class="py-4 pr-4">
                      <div class="font-semibold"><?= $mins !== null ? (int)$mins : '—' ?></div>
                    </td>
                    <td class="py-4 pr-4"><?= $statusBadge ?></td>
                    <td class="py-4 text-right">
                      <div class="flex items-center justify-end gap-2">

                        <?php if (admin_can($user, 'edit_shifts') && !$locked): ?>
                          <a href="<?= h(admin_url('shift-edit.php?id=' . (int)$r['id'])) ?>" class="rounded-2xl bg-white/10 border border-white/10 px-3 py-2 text-xs hover:bg-white/15">Edit</a>
                        <?php endif; ?>

                        <?php if (admin_can($user, 'approve_shifts') && !$locked): ?>
                          <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
                            <input type="hidden" name="shift_id" value="<?= (int)$r['id'] ?>"/>
                            <?php if ($approved): ?>
                              <input type="hidden" name="action" value="unapprove"/>
                              <button class="rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-xs hover:bg-white/10">Unapprove</button>
                            <?php else: ?>
                              <input type="hidden" name="action" value="approve"/>
                              <label class="inline-flex items-center gap-2 mr-2 text-xs text-white/70">
                                <input type="checkbox" name="is_callout" value="1" class="h-4 w-4 rounded" <?= ((int)($r['is_callout'] ?? 0)===1) ? 'checked' : '' ?> />
                                Call-out
                              </label>
                              <button class="rounded-2xl bg-white text-slate-900 px-3 py-2 text-xs font-semibold hover:bg-white/90">Approve</button>
                            <?php endif; ?>
                          </form>
                        <?php endif; ?>

                        <?php if ($locked): ?>
                          <span class="rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-xs text-white/60">Locked</span>
                        <?php endif; ?>

                        <?php if ($locked && admin_can($user, 'manage_settings_high')): ?>
                          <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
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

          <div class="mt-4 text-xs text-white/50">
            Tip: edits are stored in <code class="px-2 py-1 rounded-xl bg-white/10">kiosk_shift_changes</code> and never overwrite originals.
          </div>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
