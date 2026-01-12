<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = admin_require_login($pdo, ['manager','payroll','superadmin']);

csrf_check();

// Filters
$range = (string)($_GET['range'] ?? 'week'); // today|week|month|custom
$status = (string)($_GET['status'] ?? ''); // open|closed
$approved = (string)($_GET['approved'] ?? ''); // 0|1
$employeeId = (int)($_GET['employee_id'] ?? 0);

// Date range in UTC
$now = time();
if ($range === 'today') {
  $start = gmdate('Y-m-d 00:00:00', $now);
  $end   = gmdate('Y-m-d 23:59:59', $now);
} elseif ($range === 'month') {
  $start = gmdate('Y-m-01 00:00:00', $now);
  $end   = gmdate('Y-m-t 23:59:59', $now);
} elseif ($range === 'custom') {
  $s = (string)($_GET['start'] ?? '');
  $e = (string)($_GET['end'] ?? '');
  $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? ($s . ' 00:00:00') : gmdate('Y-m-d 00:00:00', $now - 6*86400);
  $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) ? ($e . ' 23:59:59') : gmdate('Y-m-d 23:59:59', $now);
} else {
  // week
  $start = gmdate('Y-m-d 00:00:00', $now - 6*86400);
  $end   = gmdate('Y-m-d 23:59:59', $now);
  $range = 'week';
}

// Actions (manager approval)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $shiftId = (int)($_POST['shift_id'] ?? 0);

  if ($shiftId > 0 && $action === 'approve' && in_array((string)$user['role'], ['manager','superadmin'], true)) {
    $note = trim((string)($_POST['note'] ?? ''));
    $who = (string)($user['email'] ?? 'manager');
    $stmt = $pdo->prepare("UPDATE kiosk_shifts SET approved_at=UTC_TIMESTAMP(), approved_by=?, approval_note=? WHERE id=?");
    $stmt->execute([$who, $note !== '' ? $note : null, $shiftId]);
    admin_flash_set('ok', 'Shift approved.');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }

  if ($shiftId > 0 && $action === 'unapprove' && in_array((string)$user['role'], ['manager','superadmin'], true)) {
    $stmt = $pdo->prepare("UPDATE kiosk_shifts SET approved_at=NULL, approved_by=NULL, approval_note=NULL WHERE id=?");
    $stmt->execute([$shiftId]);
    admin_flash_set('ok', 'Shift approval cleared.');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }
}

// Employees for filter dropdown
$employees = $pdo->query("SELECT id, first_name, last_name, nickname, employee_code FROM kiosk_employees WHERE archived_at IS NULL ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch shifts
$where = ["s.clock_in_at BETWEEN ? AND ?"]; 
$params = [$start, $end];

if ($status === 'open') {
  $where[] = "s.is_closed=0";
} elseif ($status === 'closed') {
  $where[] = "s.is_closed=1";
}

if ($approved === '0') {
  $where[] = "s.is_closed=1 AND s.approved_at IS NULL";
} elseif ($approved === '1') {
  $where[] = "s.approved_at IS NOT NULL";
}

if ($employeeId > 0) {
  $where[] = "s.employee_id=?";
  $params[] = $employeeId;
}

$sql = "
  SELECT
    s.*,
    e.first_name, e.last_name, e.nickname, e.employee_code
  FROM kiosk_shifts s
  LEFT JOIN kiosk_employees e ON e.id = s.employee_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY s.clock_in_at DESC
  LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmt_dt(?string $dt): string {
  if (!$dt) return '—';
  return gmdate('d M Y H:i', strtotime($dt));
}

function dur_label(?int $mins): string {
  if ($mins === null) return '—';
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  return sprintf('%02d:%02d', $h, $m);
}

admin_page_start('Shifts', $user, './shifts.php');
$csrf = h(csrf_token());
?>

<div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
  <div>
    <div class="text-2xl font-extrabold tracking-tight">Shift review</div>
    <div class="mt-1 text-sm text-white/60">Review clock in/out, fix missing times, and approve timesheets. Payroll approval comes later.</div>
  </div>
</div>

<div class="mt-5 rounded-3xl bg-white/5 border border-white/10 p-4">
  <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
    <div>
      <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Range</label>
      <select name="range" class="w-full rounded-2xl bg-white/5 border border-white/10 px-3 py-3 text-white focus:outline-none">
        <option value="today" <?= $range==='today'?'selected':''; ?>>Today</option>
        <option value="week" <?= $range==='week'?'selected':''; ?>>Last 7 days</option>
        <option value="month" <?= $range==='month'?'selected':''; ?>>This month</option>
        <option value="custom" <?= $range==='custom'?'selected':''; ?>>Custom</option>
      </select>
    </div>

    <div>
      <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Employee</label>
      <select name="employee_id" class="w-full rounded-2xl bg-white/5 border border-white/10 px-3 py-3 text-white focus:outline-none">
        <option value="0">All employees</option>
        <?php foreach ($employees as $e):
          $name = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
          if (!empty($e['nickname'])) $name .= ' (' . $e['nickname'] . ')';
        ?>
          <option value="<?= (int)$e['id'] ?>" <?= $employeeId===(int)$e['id']?'selected':''; ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Status</label>
      <select name="status" class="w-full rounded-2xl bg-white/5 border border-white/10 px-3 py-3 text-white focus:outline-none">
        <option value="" <?= $status===''?'selected':''; ?>>All</option>
        <option value="open" <?= $status==='open'?'selected':''; ?>>Open (missing clock-out)</option>
        <option value="closed" <?= $status==='closed'?'selected':''; ?>>Closed</option>
      </select>
    </div>

    <div>
      <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Manager approval</label>
      <select name="approved" class="w-full rounded-2xl bg-white/5 border border-white/10 px-3 py-3 text-white focus:outline-none">
        <option value="" <?= $approved===''?'selected':''; ?>>All</option>
        <option value="0" <?= $approved==='0'?'selected':''; ?>>Pending</option>
        <option value="1" <?= $approved==='1'?'selected':''; ?>>Approved</option>
      </select>
    </div>

    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Start (custom)</label>
        <input name="start" value="<?= h((string)($_GET['start'] ?? '')) ?>" placeholder="YYYY-MM-DD" class="w-full rounded-2xl bg-white/5 border border-white/10 px-3 py-3 text-white placeholder:text-white/30 focus:outline-none" />
      </div>
      <div>
        <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">End (custom)</label>
        <input name="end" value="<?= h((string)($_GET['end'] ?? '')) ?>" placeholder="YYYY-MM-DD" class="w-full rounded-2xl bg-white/5 border border-white/10 px-3 py-3 text-white placeholder:text-white/30 focus:outline-none" />
      </div>
    </div>

    <div class="lg:col-span-5 flex flex-wrap items-center gap-3">
      <button class="rounded-2xl bg-white text-slate-900 px-4 py-3 text-sm font-extrabold hover:bg-white/90">Apply filters</button>
      <div class="text-xs text-white/50">Showing <?= count($shifts) ?> shift(s) • UTC times</div>
    </div>
  </form>
</div>

<div class="mt-5 overflow-hidden rounded-3xl border border-white/10 bg-white/5">
  <div class="overflow-x-auto">
    <table class="min-w-full text-left">
      <thead>
        <tr class="text-xs uppercase tracking-wider text-white/50">
          <th class="px-4 py-3">Employee</th>
          <th class="px-4 py-3">Clock in</th>
          <th class="px-4 py-3">Clock out</th>
          <th class="px-4 py-3">Worked</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3">Manager</th>
          <th class="px-4 py-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-white/10">
        <?php foreach ($shifts as $s):
          $empName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
          if (!empty($s['nickname'])) $empName .= ' (' . $s['nickname'] . ')';
          $isOpen = (int)$s['is_closed'] !== 1;
          $isApproved = !empty($s['approved_at']);
          $badge = $isOpen ? ['Open', 'bg-amber-500/15 border-amber-400/20 text-amber-100'] : ['Closed', 'bg-emerald-500/10 border-emerald-400/20 text-emerald-100'];
          if (!$isOpen && !$isApproved) {
            $mgrBadge = ['Pending', 'bg-rose-500/10 border-rose-400/20 text-rose-200'];
          } else {
            $mgrBadge = ['Approved', 'bg-white/10 border-white/10 text-white/80'];
          }
        ?>
        <tr class="text-sm">
          <td class="px-4 py-3 font-semibold">
            <div><?= h($empName ?: '—') ?></div>
            <div class="mt-1 text-xs text-white/50"><?= h((string)($s['employee_code'] ?? '')) ?></div>
          </td>
          <td class="px-4 py-3"><?= h(fmt_dt($s['clock_in_at'] ?? null)) ?></td>
          <td class="px-4 py-3"><?= h(fmt_dt($s['clock_out_at'] ?? null)) ?></td>
          <td class="px-4 py-3 font-mono"><?= h(dur_label($s['duration_minutes'] !== null ? (int)$s['duration_minutes'] : null)) ?></td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge[1] ?>"><?= h($badge[0]) ?></span>
          </td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $mgrBadge[1] ?>"><?= h($mgrBadge[0]) ?></span>
          </td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <a href="./shift-edit.php?id=<?= (int)$s['id'] ?>" class="rounded-2xl bg-white/10 border border-white/10 px-3 py-2 text-xs font-semibold hover:bg-white/15">Edit</a>
            <?php if (!$isOpen && in_array((string)$user['role'], ['manager','superadmin'], true)): ?>
              <form method="post" class="inline-block ml-2" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                <input type="hidden" name="shift_id" value="<?= (int)$s['id'] ?>">
                <?php if ($isApproved): ?>
                  <input type="hidden" name="action" value="unapprove">
                  <button class="rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-xs font-semibold hover:bg-white/10">Unapprove</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="approve">
                  <button class="rounded-2xl bg-emerald-500 text-slate-950 px-3 py-2 text-xs font-extrabold hover:bg-emerald-400">Approve</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php if (!$shifts): ?>
          <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-white/60">No shifts found for these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
admin_page_end();
