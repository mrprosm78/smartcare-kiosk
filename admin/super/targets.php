<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = super_require_login($pdo);
csrf_check();

$tz = new DateTimeZone('UTC');
$today = new DateTimeImmutable('today', $tz);
$from = $today;
$to = $today->modify('+60 days');

// employee required for targets
$employeeId = (int)($_GET['employee_id'] ?? ($_POST['employee_id'] ?? 0));

// Load employees for dropdown
$emps = $pdo->query("SELECT id, full_name, is_active FROM kiosk_employees ORDER BY is_active DESC, full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($employeeId <= 0) {
    admin_flash_set('err', 'Select an employee first.');
    header('Location: ./targets.php');
    exit;
  }

  $rows = $_POST['t'] ?? [];
  if (!is_array($rows)) $rows = [];

  $ins = $pdo->prepare(
    "INSERT INTO kiosk_targets (employee_id, target_date, target_minutes)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE target_minutes=VALUES(target_minutes), updated_at=CURRENT_TIMESTAMP"
  );

  foreach ($rows as $date => $val) {
    $date = (string)$date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
    $hours = trim((string)$val);
    if ($hours === '') continue;

    // allow "7.5" etc
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $hours)) continue;
    $mins = (int)round(((float)$hours) * 60);
    if ($mins < 0) $mins = 0;
    if ($mins > 24*60) $mins = 24*60;

    $ins->execute([$employeeId, $date, $mins]);
  }

  admin_flash_set('ok', 'Targets saved.');
  header('Location: ./targets.php?employee_id=' . $employeeId);
  exit;
}

// Prefill existing targets
$targets = [];
if ($employeeId > 0) {
  $stmt = $pdo->prepare("SELECT target_date, target_minutes FROM kiosk_targets WHERE employee_id=? AND target_date BETWEEN ? AND ?");
  $stmt->execute([$employeeId, $from->format('Y-m-d'), $to->format('Y-m-d')]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $targets[(string)$r['target_date']] = (int)$r['target_minutes'];
  }
}

super_page_start('Targets', $user, './targets.php');
?>

<div class="flex flex-wrap items-end justify-between gap-4">
  <div>
    <div class="text-2xl font-bold">Target Hours</div>
    <div class="text-sm text-white/60">Set expected hours per day (next 60 days). Used in the Super Admin calendar.</div>
  </div>
</div>

<form method="get" class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-5">
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
      <label class="block text-xs font-semibold text-white/70 mb-2">Employee</label>
      <select name="employee_id" class="w-full rounded-2xl bg-slate-900/60 border border-white/10 px-3 py-2 text-sm">
        <option value="0">Select employee…</option>
        <?php foreach ($emps as $e): ?>
          <option value="<?php echo (int)$e['id']; ?>" <?php echo ((int)$e['id']===$employeeId)?'selected':''; ?>>
            <?php echo h((string)$e['full_name']); ?><?php echo ((int)$e['is_active']===1)?'':' (archived)'; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:col-span-2 flex items-end justify-end">
      <button class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold hover:opacity-90">Load</button>
    </div>
  </div>
</form>

<?php if ($employeeId > 0): ?>
<form method="post" class="mt-6">
  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>" />
  <input type="hidden" name="employee_id" value="<?php echo (int)$employeeId; ?>" />

  <div class="rounded-3xl border border-white/10 bg-white/5 overflow-hidden">
    <div class="p-5 border-b border-white/10 flex items-center justify-between gap-4">
      <div class="text-sm font-semibold">Next 60 days</div>
      <button class="rounded-2xl bg-emerald-500 text-emerald-950 px-4 py-2 text-sm font-semibold hover:opacity-90">Save Targets</button>
    </div>

    <div class="divide-y divide-white/10">
      <?php
        $d = $from;
        while ($d <= $to) {
          $key = $d->format('Y-m-d');
          $mins = $targets[$key] ?? null;
          $val = ($mins === null) ? '' : rtrim(rtrim(number_format($mins/60, 2, '.', ''), '0'), '.');
          $isWeekend = in_array((int)$d->format('N'), [6,7], true);
          echo '<div class="p-4 sm:p-5 flex flex-wrap items-center justify-between gap-3 ' . ($isWeekend ? 'bg-white/0' : '') . '">';
          echo '<div><div class="text-sm font-semibold">' . h($d->format('l')) . '</div><div class="text-xs text-white/60">' . h($d->format('d M Y')) . '</div></div>';
          echo '<div class="flex items-center gap-2">';
          echo '<span class="text-xs text-white/50">Hours</span>';
          echo '<input name="t[' . h($key) . ']" value="' . h($val) . '" inputmode="decimal" placeholder="e.g. 7.5" class="w-24 rounded-2xl bg-slate-900/60 border border-white/10 px-3 py-2 text-sm" />';
          echo '</div>';
          echo '</div>';
          $d = $d->modify('+1 day');
        }
      ?>
    </div>

    <div class="p-5 border-t border-white/10 flex items-center justify-end">
      <button class="rounded-2xl bg-emerald-500 text-emerald-950 px-4 py-2 text-sm font-semibold hover:opacity-90">Save Targets</button>
    </div>
  </div>
</form>
<?php else: ?>
  <div class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-6 text-sm text-white/70">
    Select an employee to set daily target hours.
  </div>
<?php endif; ?>

<?php super_page_end(); ?>
