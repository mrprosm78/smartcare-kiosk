<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'edit_shifts');

$title = 'Add Shift';
$error = '';
$notice = '';

// Ensure timezone exists (layout.php normally sets this)
if (!isset($tz) || !($tz instanceof DateTimeZone)) {
  $tz = new DateTimeZone('Europe/London');
}

// Load active employees for dropdown
$emps = $pdo->query(
  "SELECT id, " . admin_sql_employee_display_name('kiosk_employees') . " AS full_name, is_agency, agency_label\n" .
  "FROM kiosk_employees\n" .
  "WHERE is_active = 1\n" .
  "ORDER BY first_name ASC, last_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Defaults (stored as UTC strings in $clockIn/$clockOut)
$employeeId = (int)($_GET['employee_id'] ?? 0);
$clockIn = (string)($_GET['in'] ?? '');
$clockOut = (string)($_GET['out'] ?? '');
$isCallout = 0;
$trainingMinutes = '';
$trainingNote = '';
$note = '';
$approveNow = 0;

// Local input values for datetime-local fields (YYYY-MM-DDTHH:MM)
$clockInInput = '';
$clockOutInput = '';

// If GET provided UTC timestamps, pre-fill inputs by converting to local tz
$prefillInputsFromUtc = function (?string $utc) use ($tz): string {
  if ($utc === null || trim($utc) === '') return '';
  try {
    // Accept "Y-m-d H:i:s" (or any parsable) as UTC
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone($tz)->format('Y-m-d\TH:i');
  } catch (Throwable $e) {
    return '';
  }
};

$clockInInput = $prefillInputsFromUtc($clockIn);
$clockOutInput = $prefillInputsFromUtc($clockOut);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);

  $employeeId = (int)($_POST['employee_id'] ?? 0);

  // Keep what user typed (sticky form)
  $clockInLocal = trim((string)($_POST['clock_in_at'] ?? ''));
  $clockOutLocal = trim((string)($_POST['clock_out_at'] ?? ''));
  $clockInInput = $clockInLocal;
  $clockOutInput = $clockOutLocal;

  // Convert local -> UTC for storage
  $clockIn = admin_input_to_utc($clockInLocal, $tz) ?? '';
  $clockOut = admin_input_to_utc($clockOutLocal, $tz) ?? '';

  $isCallout = ((int)($_POST['is_callout'] ?? 0) === 1) ? 1 : 0;
  $trainingMinutes = trim((string)($_POST['training_minutes'] ?? ''));
  $trainingNote = trim((string)($_POST['training_note'] ?? ''));
  $note = trim((string)($_POST['note'] ?? ''));
  $approveNow = ((int)($_POST['approve_now'] ?? 0) === 1) ? 1 : 0;

  if ($employeeId <= 0) {
    $error = 'Please choose an employee.';
  } elseif ($clockIn === '') {
    $error = 'Clock-in time is required.';
  }

  $inDt = null;
  $outDt = null;

  if ($error === '') {
    try {
      $inDt = new DateTimeImmutable($clockIn, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
      $error = 'Invalid clock-in datetime.';
    }
  }

  if ($error === '' && $clockOut !== '') {
    try {
      $outDt = new DateTimeImmutable($clockOut, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
      $error = 'Invalid clock-out datetime.';
    }
  }

  if ($error === '' && $outDt && $inDt && $outDt <= $inDt) {
    $error = 'Clock-out must be after clock-in.';
  }

  $durationMinutes = null;
  $isClosed = 0;
  $closeReason = null;

  if ($error === '' && $inDt && $outDt) {
    $mins = (int)floor(($outDt->getTimestamp() - $inDt->getTimestamp()) / 60);
    $durationMinutes = max(0, $mins);
    $isClosed = 1;
    $closeReason = 'manual';
  } elseif ($error === '' && $inDt && !$outDt) {
    $isClosed = 0;
    $closeReason = 'manual_open';
  }

  $tmins = null;
  if ($trainingMinutes !== '') {
    $tmins = max(0, (int)$trainingMinutes);
  }

  $approvedAt = null;
  $approvedBy = null;
  $approvalNote = null;

  if ($approveNow === 1) {
    // Only users with approve_shifts can approve immediately
    if (!admin_can($user, 'approve_shifts')) {
      $error = 'You do not have permission to approve shifts.';
    } elseif (!$outDt) {
      $error = 'You can only approve a closed shift (requires clock-out).';
    } else {
      $approvedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
      $approvedBy = (string)($user['username'] ?? '');
      $approvalNote = ($note !== '' ? $note : 'Approved on creation');
    }
  }

  if ($error === '') {
    $pdo->beginTransaction();
    try {
      $ins = $pdo->prepare(
        "INSERT INTO kiosk_shifts\n" .
        "(employee_id, clock_in_at, clock_out_at, training_minutes, training_note, is_callout, duration_minutes, is_closed, close_reason, is_autoclosed, approved_at, approved_by, approval_note, created_source, updated_source, created_at, updated_at)\n" .
        "VALUES\n" .
        "(:eid, :cin, :cout, :tmins, :tnote, :callout, :dur, :closed, :reason, 0, :app_at, :app_by, :app_note, 'manager_manual', 'manager_manual', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
      );

      $ins->execute([
        ':eid' => $employeeId,
        ':cin' => $inDt?->format('Y-m-d H:i:s'),
        ':cout' => $outDt?->format('Y-m-d H:i:s'),
        ':tmins' => $tmins,
        ':tnote' => ($trainingNote !== '' ? $trainingNote : null),
        ':callout' => $isCallout,
        ':dur' => $durationMinutes,
        ':closed' => $isClosed,
        ':reason' => $closeReason,
        ':app_at' => $approvedAt,
        ':app_by' => $approvedBy,
        ':app_note' => $approvalNote,
      ]);

      $shiftId = (int)$pdo->lastInsertId();

      // Audit
      $meta = [
        'note' => $note,
        'created_source' => 'manager_manual',
        'is_callout' => $isCallout,
        'approved_on_create' => $approveNow,
      ];

      $chg = $pdo->prepare(
        "INSERT INTO kiosk_shift_changes\n" .
        "(shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)\n" .
        "VALUES\n" .
        "(:sid, 'edit', :uid, :uname, :role, 'manual_create', :note, NULL, :newj)"
      );

      $chg->execute([
        ':sid' => $shiftId,
        ':uid' => (int)($user['user_id'] ?? 0),
        ':uname' => (string)($user['username'] ?? ''),
        ':role' => (string)($user['role'] ?? ''),
        ':note' => ($note !== '' ? $note : null),
        ':newj' => json_encode($meta),
      ]);

      if ($approveNow === 1) {
        $chg2 = $pdo->prepare(
          "INSERT INTO kiosk_shift_changes\n" .
          "(shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)\n" .
          "VALUES\n" .
          "(:sid, 'approve', :uid, :uname, :role, 'approve', :note, NULL, :newj)"
        );

        $chg2->execute([
          ':sid' => $shiftId,
          ':uid' => (int)($user['user_id'] ?? 0),
          ':uname' => (string)($user['username'] ?? ''),
          ':role' => (string)($user['role'] ?? ''),
          ':note' => ($approvalNote !== '' ? $approvalNote : null),
          ':newj' => json_encode($meta),
        ]);
      }

      $pdo->commit();
      admin_redirect(admin_url('shifts.php?mode=day&date=' . rawurlencode($inDt->format('Y-m-d'))));
    } catch (Throwable $e) {
      $pdo->rollBack();
      $error = $e->getMessage();
    }
  }
}

admin_page_start($pdo, $title);
$active = admin_url('shifts.php');
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Add shift</h1>
                <p class="mt-2 text-sm text-white/70">Use this when an employee forgot to clock in/out. This does not modify punches.</p>
              </div>
              <a href="<?= h(admin_url('shifts.php')) ?>" class="rounded-2xl bg-white/5 border border-white/10 px-4 py-2 text-sm hover:bg-white/10">Back</a>
            </div>
          </header>

          <?php if ($error): ?>
            <div class="mt-5 rounded-3xl border border-rose-400/20 bg-rose-500/10 p-5 text-sm text-rose-100">
              <b>Error:</b> <?= h($error) ?>
            </div>
          <?php endif; ?>

          <form method="post" class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label>
                <div class="text-xs uppercase tracking-widest text-white/50">Employee</div>
                <select name="employee_id" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                  <option value="0">Select…</option>
                  <?php foreach ($emps as $e): ?>
                    <?php
                      $label = (string)$e['full_name'];
                      if ((int)$e['is_agency'] === 1) {
                        $al = trim((string)$e['agency_label']);
                        $label = ($al !== '' ? $al : $label) . ' (Agency)';
                      }
                    ?>
                    <option value="<?= (int)$e['id'] ?>" <?= $employeeId === (int)$e['id'] ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label>
                  <div class="text-xs uppercase tracking-widest text-white/50">Call-out</div>
                  <div class="mt-3 flex items-center gap-2">
                    <input type="checkbox" name="is_callout" value="1" class="h-4 w-4 rounded" <?= $isCallout === 1 ? 'checked' : '' ?> />
                    <span class="text-sm text-white/80">Mark as call-out</span>
                  </div>
                </label>

                <label>
                  <div class="text-xs uppercase tracking-widest text-white/50">Approve now</div>
                  <div class="mt-3 flex items-center gap-2">
                    <input type="checkbox" name="approve_now" value="1" class="h-4 w-4 rounded"
                      <?= $approveNow === 1 ? 'checked' : '' ?>
                      <?= admin_can($user, 'approve_shifts') ? '' : 'disabled' ?> />
                    <span class="text-sm text-white/80">
                      <?= admin_can($user, 'approve_shifts') ? 'Approve immediately (closed shifts only)' : 'Requires approve permission' ?>
                    </span>
                  </div>
                </label>
              </div>

              <label>
                <div class="text-xs uppercase tracking-widest text-white/50">Clock in (local)</div>
                <input name="clock_in_at" type="datetime-local" value="<?= h($clockInInput) ?>"
                  class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
              </label>

              <label>
                <div class="text-xs uppercase tracking-widest text-white/50">Clock out (local)</div>
                <input name="clock_out_at" type="datetime-local" value="<?= h($clockOutInput) ?>"
                  class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
              </label>

              <label>
                <div class="text-xs uppercase tracking-widest text-white/50">Training minutes (optional)</div>
                <input name="training_minutes" type="number" min="0" step="1" value="<?= h($trainingMinutes) ?>"
                  class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
              </label>

              <label>
                <div class="text-xs uppercase tracking-widest text-white/50">Training note (optional)</div>
                <input name="training_note" value="<?= h($trainingNote) ?>"
                  class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
              </label>

              <label class="md:col-span-2">
                <div class="text-xs uppercase tracking-widest text-white/50">Manager note (optional)</div>
                <input name="note" value="<?= h($note) ?>"
                  class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                  placeholder="Why was this added?" />
              </label>
            </div>

            <div class="mt-5 flex items-center justify-end gap-2">
              <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">
                Create shift
              </button>
            </div>
          </form>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
