<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'edit_shifts');

$shiftId = (int)($_GET['id'] ?? 0);
if ($shiftId <= 0) {
  http_response_code(400);
  exit('Missing shift id');
}

// Load shift + employee + latest edit json (includes payroll lock fields)
$stmt = $pdo->prepare("
  SELECT s.*,
         " . admin_sql_employee_display_name('e') . " AS full_name,
         " . admin_sql_employee_number('e') . " AS employee_number,
         e.is_agency, e.agency_label,
         c.new_json AS latest_edit_json,
         c.created_at AS latest_edit_at,
         c.changed_by_username AS latest_edit_by
  FROM kiosk_shifts s
  LEFT JOIN kiosk_employees e ON e.id = s.employee_id
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
$shift = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$shift) {
  http_response_code(404);
  exit('Shift not found');
}

$isLocked = !empty($shift['payroll_locked_at']);

$eff = admin_shift_effective($shift);

// Defaults for form from effective values
$inVal  = $eff['clock_in_at'] ? str_replace(' ', 'T', substr($eff['clock_in_at'], 0, 16)) : '';
$outVal = $eff['clock_out_at'] ? str_replace(' ', 'T', substr($eff['clock_out_at'], 0, 16)) : '';
$breakVal = $eff['break_minutes'] !== null ? (string)$eff['break_minutes'] : '';
$trainingVal = (string)((int)($shift['training_minutes'] ?? 0));
$trainingNoteVal = (string)($shift['training_note'] ?? '');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);

  $canOverrideLock = admin_can($user, 'manage_settings_high'); // superadmin only

  if ($isLocked && !$canOverrideLock) {
    $error = 'This shift is Payroll Locked and cannot be edited. Super Admin must unlock it first.';
  } else {

    $newIn  = trim((string)($_POST['clock_in_at'] ?? ''));
    $newOut = trim((string)($_POST['clock_out_at'] ?? ''));
    $newBreak = trim((string)($_POST['break_minutes'] ?? ''));
    $newTraining = trim((string)($_POST['training_minutes'] ?? ''));
    $newTrainingNote = trim((string)($_POST['training_note'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    // normalise to mysql DATETIME
    $norm = function(string $v): string {
      $v = str_replace('T', ' ', $v);
      if (strlen($v) === 16) $v .= ':00';
      return $v;
    };

    if ($newIn === '') {
      $error = 'Clock-in time is required.';
    } else {
      $newJson = [
        'clock_in_at' => $norm($newIn),
        'clock_out_at' => $newOut === '' ? null : $norm($newOut),
        'break_minutes' => ($newBreak === '') ? null : max(0, (int)$newBreak),
        'training_minutes' => ($newTraining === '') ? (int)($shift['training_minutes'] ?? 0) : max(0, (int)$newTraining),
        'training_note' => $newTrainingNote !== '' ? $newTrainingNote : null,
      ];

      // Build old snapshot from current effective values
      $oldJson = [
        'clock_in_at' => $eff['clock_in_at'] ?: null,
        'clock_out_at' => $eff['clock_out_at'] ?: null,
        'break_minutes' => $eff['break_minutes'],
        'training_minutes' => (int)($shift['training_minutes'] ?? 0),
        'training_note' => (string)($shift['training_note'] ?? ''),
      ];

      try {
        $ins = $pdo->prepare("
          INSERT INTO kiosk_shift_changes
            (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
          VALUES
            (?, 'edit', ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
          $shiftId,
          (int)$user['user_id'],
          (string)($user['username'] ?? ''),
          (string)($user['role'] ?? ''),
          $reason !== '' ? $reason : null,
          $note !== '' ? $note : null,
          json_encode($oldJson),
          json_encode($newJson),
        ]);

        // Mark shift as modified (but do NOT overwrite original times)
        $upd = $pdo->prepare("UPDATE kiosk_shifts SET last_modified_reason=?, training_minutes=?, training_note=?, updated_source='admin' WHERE id=?");
        $upd->execute([
          $reason !== '' ? $reason : 'edit',
          ($newTraining === '') ? (int)($shift['training_minutes'] ?? 0) : max(0, (int)$newTraining),
          $newTrainingNote !== '' ? $newTrainingNote : null,
          $shiftId
        ]);

        $success = 'Saved shift adjustment.';
        // refresh effective values
        $shift['latest_edit_json'] = json_encode($newJson);
        $eff = admin_shift_effective($shift);
        $inVal  = str_replace(' ', 'T', substr($eff['clock_in_at'], 0, 16));
        $outVal = $eff['clock_out_at'] ? str_replace(' ', 'T', substr($eff['clock_out_at'], 0, 16)) : '';
        $breakVal = $eff['break_minutes'] !== null ? (string)$eff['break_minutes'] : '';
        $trainingVal = (string)((int)($newTraining === '' ? (int)($shift['training_minutes'] ?? 0) : max(0, (int)$newTraining)));
        $trainingNoteVal = $newTrainingNote;
      } catch (Throwable $e) {
        $error = 'Failed to save adjustment: ' . $e->getMessage();
      }
    }
  }
}

// Rounding preview (uses settings)
$roundingEnabled = admin_setting_bool($pdo, 'rounding_enabled', true);
$inc = admin_setting_int($pdo, 'round_increment_minutes', 15);
$grace = admin_setting_int($pdo, 'round_grace_minutes', 5);
$roundedIn  = $roundingEnabled ? admin_round_datetime($eff['clock_in_at'] ?: null, $inc, $grace) : ($eff['clock_in_at'] ?: null);
$roundedOut = $roundingEnabled ? admin_round_datetime($eff['clock_out_at'] ?: null, $inc, $grace) : ($eff['clock_out_at'] ?: null);

admin_page_start($pdo, 'Edit shift');
$active = admin_url('shifts.php');

$who = (string)($shift['full_name'] ?? 'Unknown');
if ((int)($shift['is_agency'] ?? 0) === 1) {
  $lbl = trim((string)($shift['agency_label'] ?? 'Agency'));
  $who = $lbl !== '' ? ($lbl . ' (Agency)') : 'Agency';
}
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Edit shift</h1>
                <p class="mt-2 text-sm text-slate-600"><?= h($who) ?> • Shift #<?= (int)$shiftId ?></p>
              </div>
              <a href="<?= h(admin_url('shifts.php')) ?>" class="rounded-2xl bg-slate-50 border border-slate-200 px-4 py-2 text-sm hover:bg-slate-100">Back</a>
            </div>
          </header>

          <?php if ($isLocked): ?>
            <div class="mt-4 rounded-2xl border border-rose-400/20 bg-rose-500/10 p-4 text-sm text-black-100">
              <div class="font-semibold">Payroll Locked</div>
              <div class="mt-1 text-black-100/80">
                Locked at: <?= h(admin_fmt_dt((string)$shift['payroll_locked_at'])) ?>
                <?php if (!empty($shift['payroll_locked_by'])): ?> • By: <?= h((string)$shift['payroll_locked_by']) ?><?php endif; ?>
                <?php if (!empty($shift['payroll_batch_id'])): ?> • Batch: <?= h((string)$shift['payroll_batch_id']) ?><?php endif; ?>
              </div>
              <div class="mt-2 text-black-100/70 text-xs">
                This shift cannot be edited until a Super Admin unlocks it from the Shifts page.
              </div>
            </div>
          <?php endif; ?>

          <?php if ($error): ?>
            <div class="mt-4 rounded-2xl border border-rose-400/20 bg-rose-500/10 p-4 text-sm text-black-100">
              <?= h($error) ?>
            </div>
          <?php endif; ?>

          <?php if ($success): ?>
            <div class="mt-4 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 text-sm text-black-100">
              <?= h($success) ?>
            </div>
          <?php endif; ?>

          <div class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-5">
            <section class="lg:col-span-2 rounded-3xl border border-slate-200 bg-white p-5">
              <h2 class="text-lg font-semibold">Adjustment (does not change original punch)</h2>
              <p class="mt-1 text-sm text-slate-600">Payroll + approvals will use the adjusted values. Original clock times remain unchanged.</p>

              <form method="post" class="mt-4 space-y-4">
                <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-slate-500">Clock in (effective)</div>
                    <input type="datetime-local" id="clock_in_at" name="clock_in_at" value="<?= h($inVal) ?>"
                      class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                      required <?= $isLocked ? 'disabled' : '' ?> />
                    <div class="mt-2 text-xs text-slate-500">Original: <?= h(admin_fmt_dt((string)$shift['clock_in_at'])) ?></div>
                  </label>

                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-slate-500">Clock out (effective)</div>
                    <input type="datetime-local" id="clock_out_at" name="clock_out_at" value="<?= h($outVal) ?>"
                      class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                      <?= $isLocked ? 'disabled' : '' ?> />
                    <div class="mt-2 text-xs text-slate-500">Original: <?= h(admin_fmt_dt((string)$shift['clock_out_at'])) ?></div>
                  </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-slate-500">Unpaid break (minutes)</div>
                    <input type="number" min="0" step="1" name="break_minutes" value="<?= h($breakVal) ?>"
                      class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                      <?= $isLocked ? 'disabled' : '' ?> />
                  </label>

                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-slate-500">Training (minutes)</div>
                    <input type="number" min="0" step="1" name="training_minutes" value="<?= h($trainingVal) ?>"
                      class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                      <?= $isLocked ? 'disabled' : '' ?> />
                    <div class="mt-2 text-xs text-slate-500">Adds paid training time to payroll (separate from punches).</div>
                  </label>

                  <label class="block md:col-span-2">
                    <div class="text-xs uppercase tracking-widest text-slate-500">Reason</div>
                    <input name="reason" value="<?= h((string)($_POST['reason'] ?? '')) ?>" placeholder="e.g. Forgot to clock out"
                      class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                      <?= $isLocked ? 'disabled' : '' ?> />
                  </label>
                </div>

                <label class="block">
                  <div class="text-xs uppercase tracking-widest text-slate-500">Training note (optional)</div>
                  <input name="training_note" value="<?= h($trainingNoteVal) ?>" placeholder="e.g. Manual handling training"
                    class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                    <?= $isLocked ? 'disabled' : '' ?> />
                </label>

                <label class="block">
                  <div class="text-xs uppercase tracking-widest text-slate-500">Note (optional)</div>
                  <textarea name="note" rows="3"
                    class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                    <?= $isLocked ? 'disabled' : '' ?>><?= h((string)($_POST['note'] ?? '')) ?></textarea>
                </label>

                <label class="block">
                  <div class="text-xs uppercase tracking-widest text-slate-500">Training note (optional)</div>
                  <textarea name="training_note" rows="2"
                    class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                    <?= $isLocked ? 'disabled' : '' ?>><?= h((string)($_POST['training_note'] ?? $trainingNoteVal)) ?></textarea>
                </label>

                <div class="flex items-center justify-end gap-3">
                  <?php if (!$isLocked): ?>
                    <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Save adjustment</button>
                  <?php else: ?>
                    <span class="text-xs text-slate-500">Locked — cannot save.</span>
                  <?php endif; ?>
                </div>
              </form>
            </section>

            <aside class="rounded-3xl border border-slate-200 bg-white p-5">
              <h2 class="text-lg font-semibold">Rounding preview</h2>
              <div class="mt-3 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                  <div class="text-slate-500">Rounded in</div>
                  <div class="font-semibold"><?= h(admin_fmt_dt($roundedIn)) ?></div>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <div class="text-slate-500">Rounded out</div>
                  <div class="font-semibold"><?= h(admin_fmt_dt($roundedOut)) ?></div>
                </div>
                <div class="pt-3 border-t border-slate-200 text-xs text-slate-500">
                  Uses settings: enabled <?= $roundingEnabled ? 'Yes' : 'No' ?> • increment <?= (int)$inc ?> • grace <?= (int)$grace ?>
                </div>
              </div>
            </aside>
          </div>

          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Audit trail</h2>
            <p class="mt-1 text-sm text-slate-600">Every edit/approval is recorded here. Originals are preserved.</p>

            <?php
              $hist = $pdo->prepare("SELECT * FROM kiosk_shift_changes WHERE shift_id=? ORDER BY id DESC LIMIT 50");
              $hist->execute([$shiftId]);
              $rows = $hist->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div class="mt-4 space-y-3">
              <?php if (!$rows): ?>
                <div class="text-sm text-slate-500">No changes yet.</div>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                      <div class="text-sm font-semibold">
                        <?= h(strtoupper((string)$r['change_type'])) ?>
                        <span class="text-slate-500 font-normal">• <?= h((string)($r['changed_by_username'] ?? 'system')) ?> (<?= h((string)($r['changed_by_role'] ?? '')) ?>)</span>
                      </div>
                      <div class="text-xs text-slate-500"><?= h(admin_fmt_dt((string)$r['created_at'])) ?></div>
                    </div>
                    <?php if (!empty($r['reason']) || !empty($r['note'])): ?>
                      <div class="mt-2 text-xs text-slate-500">
                        <?= $r['reason'] ? ('Reason: ' . h((string)$r['reason'])) : '' ?>
                        <?= ($r['reason'] && $r['note']) ? ' • ' : '' ?>
                        <?= $r['note'] ? ('Note: ' . h((string)$r['note'])) : '' ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </section>

        </main>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const p = new URLSearchParams(window.location.search);
  const f = p.get('focus');
  if (f === 'in') {
    const el = document.getElementById('clock_in_at');
    if (el && !el.disabled) { el.focus(); el.scrollIntoView({block:'center'}); }
  }
  if (f === 'out') {
    const el = document.getElementById('clock_out_at');
    if (el && !el.disabled) { el.focus(); el.scrollIntoView({block:'center'}); }
  }
})();
</script>

<?php admin_page_end(); ?>
