<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = super_require_login($pdo);

// Date helpers (UTC) — adjust later if you add per-carehome timezones
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$monthStart = $now->modify('first day of this month')->setTime(0,0,0);
$monthEnd   = $monthStart->modify('+1 month');
$lastMonthStart = $monthStart->modify('-1 month');
$lastMonthEnd   = $monthStart;

function sum_shift_minutes(PDO $pdo, DateTimeImmutable $from, DateTimeImmutable $to, bool $managerOnly=false): int {
  $sql = "
    SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, clock_in, clock_out)),0) AS mins
    FROM kiosk_shifts
    WHERE clock_out IS NOT NULL
      AND clock_in >= ?
      AND clock_in < ?
  ";
  if ($managerOnly) {
    $sql .= " AND approved_at IS NOT NULL ";
  }
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
  return (int)$stmt->fetchColumn();
}

function count_open_shifts(PDO $pdo): int {
  return (int)$pdo->query("SELECT COUNT(*) FROM kiosk_shifts WHERE clock_out IS NULL")->fetchColumn();
}

function pending_manager(PDO $pdo): array {
  $sql = "
    SELECT
      COUNT(*) AS cnt,
      COALESCE(SUM(TIMESTAMPDIFF(MINUTE, clock_in, clock_out)),0) AS mins
    FROM kiosk_shifts
    WHERE clock_out IS NOT NULL
      AND approved_at IS NULL
  ";
  $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'mins'=>0];
  return [(int)$row['cnt'], (int)$row['mins']];
}

function pending_payroll(PDO $pdo): array {
  $sql = "
    SELECT
      COUNT(*) AS cnt,
      COALESCE(SUM(TIMESTAMPDIFF(MINUTE, clock_in, clock_out)),0) AS mins
    FROM kiosk_shifts
    WHERE clock_out IS NOT NULL
      AND approved_at IS NOT NULL
      AND payroll_approved_at IS NULL
  ";
  $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'mins'=>0];
  return [(int)$row['cnt'], (int)$row['mins']];
}

function fmt_hours(int $minutes): string {
  $h = floor($minutes / 60);
  $m = $minutes % 60;
  return sprintf('%d:%02d', $h, $m);
}

$thisMinsAll = sum_shift_minutes($pdo, $monthStart, $monthEnd, false);
$lastMinsAll = sum_shift_minutes($pdo, $lastMonthStart, $lastMonthEnd, false);
$thisMinsApproved = sum_shift_minutes($pdo, $monthStart, $monthEnd, true);
$lastMinsApproved = sum_shift_minutes($pdo, $lastMonthStart, $lastMonthEnd, true);

[$pendingMgrCnt, $pendingMgrMins] = pending_manager($pdo);
[$pendingPayCnt, $pendingPayMins] = pending_payroll($pdo);
$openCnt = count_open_shifts($pdo);

$delta = $thisMinsAll - $lastMinsAll;
$deltaPct = ($lastMinsAll > 0) ? (($delta / $lastMinsAll) * 100.0) : null;

super_page_start('Super Admin • Overview', $user, './dashboard.php');
?>

<div class="flex flex-wrap items-end justify-between gap-4 mb-6">
  <div>
    <div class="text-2xl font-bold tracking-tight">Super Admin Overview</div>
    <div class="text-white/60 text-sm">Totals & oversight for the last two months, plus approval pipeline.</div>
  </div>
  <div class="text-sm text-white/60">
    Data timezone: UTC
  </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
  <div class="rounded-3xl bg-white/5 border border-white/10 p-4">
    <div class="text-xs text-white/60">Worked hours (this month)</div>
    <div class="mt-1 text-2xl font-bold"><?php echo h(fmt_hours($thisMinsAll)); ?></div>
    <div class="mt-1 text-xs text-white/50">Manager approved: <?php echo h(fmt_hours($thisMinsApproved)); ?></div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-4">
    <div class="text-xs text-white/60">Worked hours (last month)</div>
    <div class="mt-1 text-2xl font-bold"><?php echo h(fmt_hours($lastMinsAll)); ?></div>
    <div class="mt-1 text-xs text-white/50">Manager approved: <?php echo h(fmt_hours($lastMinsApproved)); ?></div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-4">
    <div class="text-xs text-white/60">Month change</div>
    <div class="mt-1 text-2xl font-bold"><?php echo h(($delta >= 0 ? '+' : '') . fmt_hours(abs($delta))); ?></div>
    <div class="mt-1 text-xs text-white/50">
      <?php if ($deltaPct !== null): ?>
        <?php echo h(sprintf('%.1f%% vs last month', $deltaPct)); ?>
      <?php else: ?>
        No last‑month baseline
      <?php endif; ?>
    </div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-4">
    <div class="text-xs text-white/60">Open shifts (missing clock‑out)</div>
    <div class="mt-1 text-2xl font-bold"><?php echo h((string)$openCnt); ?></div>
    <div class="mt-1 text-xs text-white/50">Fix via Admin → Shifts</div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
  <div class="rounded-3xl bg-white/5 border border-white/10 p-4">
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="text-sm font-semibold">Pending manager approval</div>
        <div class="text-xs text-white/60">Clock‑outs done but not approved yet.</div>
      </div>
      <a href="../shifts.php?status=pending" class="rounded-2xl bg-white/10 border border-white/10 px-3 py-2 text-sm font-semibold hover:bg-white/15">View</a>
    </div>
    <div class="mt-4 flex items-end justify-between">
      <div class="text-3xl font-bold"><?php echo h((string)$pendingMgrCnt); ?></div>
      <div class="text-sm text-white/70">Hours: <span class="font-semibold"><?php echo h(fmt_hours($pendingMgrMins)); ?></span></div>
    </div>
  </div>

  <div class="rounded-3xl bg-white/5 border border-white/10 p-4">
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="text-sm font-semibold">Ready for payroll</div>
        <div class="text-xs text-white/60">Manager approved, waiting payroll approval (future module).</div>
      </div>
      <div class="rounded-2xl bg-white/10 border border-white/10 px-3 py-2 text-xs font-semibold text-white/70">Payroll later</div>
    </div>
    <div class="mt-4 flex items-end justify-between">
      <div class="text-3xl font-bold"><?php echo h((string)$pendingPayCnt); ?></div>
      <div class="text-sm text-white/70">Hours: <span class="font-semibold"><?php echo h(fmt_hours($pendingPayMins)); ?></span></div>
    </div>
  </div>
</div>

<div class="mt-6 rounded-3xl bg-gradient-to-br from-white/5 to-white/0 border border-white/10 p-5">
  <div class="flex flex-wrap items-center justify-between gap-4">
    <div>
      <div class="text-sm font-semibold">60‑day weekly calendar (Mon–Sun)</div>
      <div class="text-xs text-white/60">Includes target hours (from Targets page) and worked hours per day.</div>
    </div>
    <a href="./calendar.php" class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold hover:opacity-90">Open Calendar</a>
  </div>
</div>

<?php
super_page_end();
