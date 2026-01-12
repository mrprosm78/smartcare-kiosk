<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = admin_require_login($pdo, ['manager','payroll','superadmin']);

// Summary metrics (UTC)
$todayStart = gmdate('Y-m-d 00:00:00');
$todayEnd   = gmdate('Y-m-d 23:59:59');

$openShifts = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_shifts WHERE is_closed=0")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM kiosk_shifts WHERE clock_in_at BETWEEN ? AND ?");
$stmt->execute([$todayStart, $todayEnd]);
$todayShifts = (int)$stmt->fetchColumn();

$pendingManager = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_shifts WHERE is_closed=1 AND approved_at IS NULL")->fetchColumn();
$pendingPayroll = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_shifts WHERE is_closed=1 AND approved_at IS NOT NULL AND payroll_approved_at IS NULL")->fetchColumn();

$activeEmployees = (int)$pdo->query("SELECT COUNT(*) FROM kiosk_employees WHERE is_active=1 AND archived_at IS NULL")->fetchColumn();

admin_page_start('Dashboard', $user, './dashboard.php');
?>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
  <div class="rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-xs uppercase tracking-wider text-white/50">Open shifts (no clock-out)</div>
    <div class="mt-2 text-3xl font-extrabold"><?=$openShifts?></div>
    <div class="mt-3"><a href="./shifts.php?status=open" class="text-sm text-white/70 hover:text-white">Review</a></div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-xs uppercase tracking-wider text-white/50">Shifts today</div>
    <div class="mt-2 text-3xl font-extrabold"><?=$todayShifts?></div>
    <div class="mt-3"><a href="./shifts.php?range=today" class="text-sm text-white/70 hover:text-white">View list</a></div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-xs uppercase tracking-wider text-white/50">Pending manager approval</div>
    <div class="mt-2 text-3xl font-extrabold"><?=$pendingManager?></div>
    <div class="mt-3"><a href="./shifts.php?approved=0" class="text-sm text-white/70 hover:text-white">Approve now</a></div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-xs uppercase tracking-wider text-white/50">Ready for payroll</div>
    <div class="mt-2 text-3xl font-extrabold"><?=$pendingPayroll?></div>
    <div class="mt-3"><span class="text-sm text-white/50">Payroll dashboard later</span></div>
  </div>
</div>

<div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
  <div class="lg:col-span-2 rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="text-lg font-bold">Quick actions</div>
        <div class="mt-1 text-sm text-white/60">Manager reviews and approvals happen here. Payroll approval comes later.</div>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
      <a href="./shifts.php?approved=0" class="rounded-2xl bg-emerald-500 text-slate-950 px-4 py-4 font-extrabold hover:bg-emerald-400">
        Review & approve shifts
        <div class="mt-1 text-sm font-semibold opacity-80">Pending manager approval</div>
      </a>
      <a href="./shifts.php" class="rounded-2xl bg-white/10 border border-white/10 px-4 py-4 font-extrabold hover:bg-white/15">
        View all shifts
        <div class="mt-1 text-sm font-semibold text-white/70">Filter by day/week/month</div>
      </a>
      <?php if (in_array((string)$user['role'], ['manager','superadmin'], true)): ?>
        <a href="./employees.php" class="rounded-2xl bg-white/10 border border-white/10 px-4 py-4 font-extrabold hover:bg-white/15">
          Employees
          <div class="mt-1 text-sm font-semibold text-white/70">Add / archive / reset PIN</div>
        </a>
      <?php endif; ?>
      <a href="../setup.php" class="rounded-2xl bg-white/5 border border-white/10 px-4 py-4 font-extrabold hover:bg-white/10">
        Setup / repair
        <div class="mt-1 text-sm font-semibold text-white/60">DB install/reset tool</div>
      </a>
    </div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-xs uppercase tracking-wider text-white/50">Employees active</div>
    <div class="mt-2 text-3xl font-extrabold"><?=$activeEmployees?></div>
    <div class="mt-4 text-sm text-white/60">Employees clock in/out using the kiosk PIN screen. Managers can add and archive employees in the Employees page.</div>
  </div>
</div>

<?php
admin_page_end();
