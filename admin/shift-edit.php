<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = admin_require_login($pdo, ['manager','payroll','superadmin']);
csrf_check();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing shift id');
}

$stmt = $pdo->prepare("SELECT s.*, e.first_name, e.last_name, e.nickname, e.employee_code FROM kiosk_shifts s LEFT JOIN kiosk_employees e ON e.id=s.employee_id WHERE s.id=? LIMIT 1");
$stmt->execute([$id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$shift) {
  http_response_code(404);
  exit('Shift not found');
}

function parse_dt(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  // Accept "YYYY-MM-DD HH:MM" or "YYYY-MM-DD HH:MM:SS"
  if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s)) return null;
  $ts = strtotime($s . ' UTC');
  if ($ts === false) return null;
  return gmdate('Y-m-d H:i:s', $ts);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');

  if ($action === 'delete_approval' && in_array((string)$user['role'], ['manager','superadmin'], true)) {
    $pdo->prepare("UPDATE kiosk_shifts SET approved_at=NULL, approved_by=NULL, approval_note=NULL WHERE id=?")->execute([$id]);
    admin_flash_set('ok', 'Manager approval cleared.');
    header('Location: ./shift-edit.php?id=' . $id);
    exit;
  }

  if ($action === 'approve' && in_array((string)$user['role'], ['manager','superadmin'], true)) {
    $note = trim((string)($_POST['approval_note'] ?? ''));
    $who = (string)($user['email'] ?? 'manager');
    $pdo->prepare("UPDATE kiosk_shifts SET approved_at=UTC_TIMESTAMP(), approved_by=?, approval_note=? WHERE id=?")->execute([$who, $note !== '' ? $note : null, $id]);
    admin_flash_set('ok', 'Shift approved.');
    header('Location: ./shift-edit.php?id=' . $id);
    exit;
  }

  if ($action === 'save' && in_array((string)$user['role'], ['manager','superadmin'], true)) {
    $clockIn  = parse_dt((string)($_POST['clock_in_at'] ?? ''));
    $clockOut = parse_dt((string)($_POST['clock_out_at'] ?? ''));
    $closeReason = trim((string)($_POST['close_reason'] ?? ''));
    $note = trim((string)($_POST['modify_note'] ?? ''));

    if (!$clockIn) {
      admin_flash_set('err', 'Clock-in time is invalid. Use YYYY-MM-DD HH:MM.');
      header('Location: ./shift-edit.php?id=' . $id);
      exit;
    }

    if ($clockOut && strtotime($clockOut) < strtotime($clockIn)) {
      admin_flash_set('err', 'Clock-out cannot be before clock-in.');
      header('Location: ./shift-edit.php?id=' . $id);
      exit;
    }

    $isClosed = $clockOut ? 1 : 0;
    $dur = null;
    if ($clockOut) {
      $dur = (int)round((strtotime($clockOut) - strtotime($clockIn)) / 60);
      if ($dur < 0) $dur = 0;
    }

    $before = $shift;

    $stmt = $pdo->prepare("UPDATE kiosk_shifts SET clock_in_at=?, clock_out_at=?, duration_minutes=?, is_closed=?, close_reason=?, last_modified_reason=?, updated_source=? WHERE id=?");
    $stmt->execute([
      $clockIn,
      $clockOut,
      $dur,
      $isClosed,
      $closeReason !== '' ? $closeReason : null,
      $note !== '' ? $note : 'manager_edit',
      'admin',
      $id,
    ]);

    // Audit log
    try {
      $meta = json_encode([
        'shift_id' => $id,
        'user' => (string)($user['email'] ?? ''),
        'before' => ['clock_in_at' => $before['clock_in_at'] ?? null, 'clock_out_at' => $before['clock_out_at'] ?? null, 'duration_minutes' => $before['duration_minutes'] ?? null, 'is_closed' => $before['is_closed'] ?? null],
        'after'  => ['clock_in_at' => $clockIn, 'clock_out_at' => $clockOut, 'duration_minutes' => $dur, 'is_closed' => $isClosed],
      ], JSON_UNESCAPED_SLASHES);
      $pdo->prepare("INSERT INTO kiosk_event_log (occurred_at, event_type, result, message, meta_json) VALUES (UTC_TIMESTAMP(),'shift_edit','ok',?,?)")
        ->execute(['Shift edited from admin', $meta]);
    } catch (Throwable $e) {
      // ignore logging failures
    }

    admin_flash_set('ok', 'Shift saved.');
    header('Location: ./shift-edit.php?id=' . $id);
    exit;
  }

  admin_flash_set('err', 'Action not allowed.');
  header('Location: ./shift-edit.php?id=' . $id);
  exit;
}

// Reload
$stmt->execute([$id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);

$empName = trim((string)($shift['first_name'] ?? '') . ' ' . (string)($shift['last_name'] ?? ''));
if (!empty($shift['nickname'])) $empName .= ' (' . $shift['nickname'] . ')';

function fmt_dt_input(?string $dt): string {
  if (!$dt) return '';
  return gmdate('Y-m-d H:i', strtotime($dt));
}

admin_page_start('Edit shift', $user, './shifts.php');
$csrf = h(csrf_token());
$isOpen = (int)($shift['is_closed'] ?? 0) !== 1;
$isApproved = !empty($shift['approved_at']);
?>

<div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
  <div>
    <div class="text-2xl font-extrabold tracking-tight">Edit shift</div>
    <div class="mt-1 text-sm text-white/60"><?=h($empName ?: '—')?> • ID #<?= (int)$shift['id'] ?> • UTC times</div>
  </div>
  <div class="flex items-center gap-2">
    <a href="./shifts.php" class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3 text-sm font-semibold hover:bg-white/15">Back to shifts</a>
  </div>
</div>

<div class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-4">
  <div class="lg:col-span-2 rounded-3xl bg-white/5 border border-white/10 p-5">
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">
      <input type="hidden" name="action" value="save">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Clock in (UTC)</label>
          <input name="clock_in_at" value="<?=h(fmt_dt_input($shift['clock_in_at'] ?? null))?>" class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="YYYY-MM-DD HH:MM" required>
        </div>
        <div>
          <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Clock out (UTC)</label>
          <input name="clock_out_at" value="<?=h(fmt_dt_input($shift['clock_out_at'] ?? null))?>" class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="YYYY-MM-DD HH:MM">
          <?php if ($isOpen): ?>
            <div class="mt-2 text-xs text-amber-100/80">This shift is open (missing clock-out). Add a clock-out time to close it.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Close reason (optional)</label>
          <input name="close_reason" value="<?=h((string)($shift['close_reason'] ?? ''))?>" class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="manual_fix / forgot_clockout">
        </div>
        <div>
          <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Manager note (for audit)</label>
          <input name="modify_note" value="" class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="e.g. Employee forgot to clock out">
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <button class="rounded-2xl bg-white text-slate-900 px-4 py-3 text-sm font-extrabold hover:bg-white/90">Save</button>
        <?php if (!$isOpen): ?>
          <a href="#approval" class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3 text-sm font-semibold hover:bg-white/15">Approval</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-lg font-bold">Summary</div>
    <div class="mt-3 space-y-2 text-sm text-white/70">
      <div class="flex items-center justify-between"><span>Worked</span><span class="font-mono text-white"><?=h((string)($shift['duration_minutes'] !== null ? sprintf('%02d:%02d', intdiv((int)$shift['duration_minutes'],60), (int)$shift['duration_minutes']%60) : '—'))?></span></div>
      <div class="flex items-center justify-between"><span>Status</span><span class="text-white"><?=$isOpen?'Open':'Closed'?></span></div>
      <div class="flex items-center justify-between"><span>Manager</span><span class="text-white"><?=$isApproved?'Approved':'Pending'?></span></div>
      <div class="flex items-center justify-between"><span>Approved by</span><span class="text-white/80"><?=h((string)($shift['approved_by'] ?? '—'))?></span></div>
      <div class="flex items-center justify-between"><span>Approved at</span><span class="text-white/80"><?=h($shift['approved_at'] ? gmdate('d M Y H:i', strtotime((string)$shift['approved_at'])) : '—')?></span></div>
    </div>

    <?php if (!$isOpen): ?>
      <div id="approval" class="mt-6 pt-6 border-t border-white/10">
        <div class="text-lg font-bold">Manager approval</div>
        <div class="mt-2 text-sm text-white/60">Manager approval is required before payroll can process.</div>

        <form method="post" class="mt-4 space-y-3">
          <input type="hidden" name="csrf_token" value="<?=$csrf?>">
          <textarea name="approval_note" rows="2" class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="Optional note for payroll"><?=h((string)($shift['approval_note'] ?? ''))?></textarea>

          <?php if ($isApproved): ?>
            <div class="flex items-center gap-3">
              <button name="action" value="approve" class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3 text-sm font-semibold hover:bg-white/15">Re-approve</button>
              <button name="action" value="delete_approval" class="rounded-2xl bg-rose-500/10 border border-rose-400/20 px-4 py-3 text-sm font-semibold text-rose-200 hover:bg-rose-500/15">Clear approval</button>
            </div>
          <?php else: ?>
            <button name="action" value="approve" class="w-full rounded-2xl bg-emerald-500 text-slate-950 px-4 py-3 text-sm font-extrabold hover:bg-emerald-400">Approve shift</button>
          <?php endif; ?>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
admin_page_end();
