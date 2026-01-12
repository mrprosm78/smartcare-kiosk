<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = super_require_login($pdo);

// Filter: employee_id (optional)
$employeeId = (int)($_GET['employee_id'] ?? 0);

// Week start (Monday) — default current week
$tz = new DateTimeZone('UTC');
$today = new DateTimeImmutable('today', $tz);
$defaultWeek = $today->modify('monday this week');
$weekParam = (string)($_GET['week'] ?? '');
try {
  $weekStart = $weekParam ? new DateTimeImmutable($weekParam, $tz) : $defaultWeek;
} catch (Throwable $e) {
  $weekStart = $defaultWeek;
}
$weekStart = $weekStart->modify('monday this week')->setTime(0,0,0);
$weekEnd = $weekStart->modify('+7 days');

$minWeek = $today->modify('-60 days')->modify('monday this week')->setTime(0,0,0);
$maxWeek = $today->modify('monday this week')->setTime(0,0,0);

// Employees for filter
$employees = $pdo->query("SELECT id, name, pin, is_active FROM kiosk_employees ORDER BY is_active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Pull worked minutes per day for the week
$params = [
  ':start' => $weekStart->format('Y-m-d H:i:s'),
  ':end'   => $weekEnd->format('Y-m-d H:i:s'),
];
$empSql = '';
if ($employeeId > 0) {
  $empSql = ' AND s.employee_id = :eid ';
  $params[':eid'] = $employeeId;
}

$sqlWorked = "
  SELECT DATE(s.clock_in) as d,
         SUM(TIMESTAMPDIFF(MINUTE, s.clock_in, s.clock_out)) as mins
  FROM kiosk_shifts s
  WHERE s.clock_out IS NOT NULL
    AND s.clock_in >= :start AND s.clock_in < :end
    $empSql
  GROUP BY DATE(s.clock_in)
";
$stmt = $pdo->prepare($sqlWorked);
$stmt->execute($params);
$workedByDay = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $workedByDay[(string)$r['d']] = (int)$r['mins'];
}

// Pull targets per day (same date key)
$sqlTarget = "
  SELECT target_date as d, SUM(target_minutes) as mins
  FROM kiosk_targets
  WHERE target_date >= :d1 AND target_date < :d2
";
if ($employeeId > 0) {
  $sqlTarget .= " AND employee_id = :eid ";
}
$sqlTarget .= " GROUP BY target_date ";
$stmt = $pdo->prepare($sqlTarget);
$tParams = [
  ':d1' => $weekStart->format('Y-m-d'),
  ':d2' => $weekEnd->format('Y-m-d'),
];
if ($employeeId > 0) {
  $tParams[':eid'] = $employeeId;
}
$stmt->execute($tParams);
$targetByDay = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $targetByDay[(string)$r['d']] = (int)$r['mins'];
}

function fmt_hm(int $mins): string {
  $h = intdiv(max(0,$mins), 60);
  $m = max(0,$mins) % 60;
  return sprintf('%d:%02d', $h, $m);
}

$current = './calendar.php';
super_page_start('Super Admin • 60‑Day Calendar', $user, $current);
?>

<div class="flex flex-wrap items-end justify-between gap-4">
  <div>
    <div class="text-2xl font-bold">Weekly Calendar</div>
    <div class="text-sm text-white/60">Monday–Sunday view. Navigate up to the past 60 days.</div>
  </div>

  <form method="get" class="flex flex-wrap items-center gap-2">
    <input type="hidden" name="week" value="<?php echo h($weekStart->format('Y-m-d')); ?>">
    <select name="employee_id" class="rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-sm">
      <option value="0">All employees</option>
      <?php foreach ($employees as $e): ?>
        <option value="<?php echo (int)$e['id']; ?>" <?php echo ($employeeId === (int)$e['id']) ? 'selected' : ''; ?>>
          <?php echo h((string)$e['name']); ?><?php echo ((int)$e['is_active'] === 1) ? '' : ' (archived)'; ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="rounded-2xl bg-white text-slate-900 px-4 py-2 text-sm font-semibold hover:opacity-90">Apply</button>
  </form>
</div>

<div class="mt-6 flex items-center justify-between gap-2">
  <div class="text-sm text-white/70">
    Week: <span class="font-semibold"><?php echo h($weekStart->format('D d M Y')); ?></span>
    → <span class="font-semibold"><?php echo h($weekStart->modify('+6 days')->format('D d M Y')); ?></span>
  </div>
  <div class="flex items-center gap-2">
    <?php
      $prev = $weekStart->modify('-7 days');
      $next = $weekStart->modify('+7 days');
      $canPrev = $prev >= $minWeek;
      $canNext = $next <= $maxWeek;
      $qsBase = 'employee_id=' . urlencode((string)$employeeId);
    ?>
    <a class="rounded-2xl px-4 py-2 text-sm font-semibold border border-white/10 bg-white/5 hover:bg-white/10 <?php echo $canPrev ? '' : 'opacity-40 pointer-events-none'; ?>"
       href="<?php echo $canPrev ? ('./calendar.php?week=' . h($prev->format('Y-m-d')) . '&' . $qsBase) : '#'; ?>">← Prev</a>
    <a class="rounded-2xl px-4 py-2 text-sm font-semibold border border-white/10 bg-white/5 hover:bg-white/10 <?php echo $canNext ? '' : 'opacity-40 pointer-events-none'; ?>"
       href="<?php echo $canNext ? ('./calendar.php?week=' . h($next->format('Y-m-d')) . '&' . $qsBase) : '#'; ?>">Next →</a>
  </div>
</div>

<div class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-3">
<?php
  for ($i=0; $i<7; $i++) {
    $d = $weekStart->modify('+' . $i . ' days');
    $key = $d->format('Y-m-d');
    $worked = (int)($workedByDay[$key] ?? 0);
    $target = (int)($targetByDay[$key] ?? 0);
    $var = $worked - $target;
    $isToday = ($key === $today->format('Y-m-d'));
    $varClass = 'text-white/70';
    if ($target > 0) {
      if ($var < 0) $varClass = 'text-rose-200';
      elseif ($var > 0) $varClass = 'text-emerald-200';
      else $varClass = 'text-sky-200';
    }
    echo '<div class="rounded-3xl border border-white/10 bg-white/5 p-4 ' . ($isToday ? 'ring-1 ring-white/20' : '') . '">';
    echo '<div class="flex items-start justify-between gap-3">';
    echo '<div><div class="text-sm font-semibold">' . h($d->format('l')) . '</div><div class="text-xs text-white/60">' . h($d->format('d M Y')) . '</div></div>';
    echo '<div class="text-xs rounded-full px-2 py-1 bg-white/10 border border-white/10">' . h($d->format('D')) . '</div>';
    echo '</div>';
    echo '<div class="mt-4 space-y-2">';
    echo '<div class="flex items-center justify-between text-sm"><span class="text-white/60">Worked</span><span class="font-semibold">' . h(fmt_hm($worked)) . '</span></div>';
    echo '<div class="flex items-center justify-between text-sm"><span class="text-white/60">Target</span><span class="font-semibold">' . h(fmt_hm($target)) . '</span></div>';
    echo '<div class="flex items-center justify-between text-sm"><span class="text-white/60">Variance</span><span class="font-semibold ' . $varClass . '">' . h(($var>=0?'+':'-') . fmt_hm(abs($var))) . '</span></div>';
    echo '</div>';
    echo '<div class="mt-4">';
    echo '<a class="text-xs text-white/70 hover:text-white underline" href="../shifts.php?from=' . h($key) . '&to=' . h($key) . ($employeeId>0 ? ('&employee_id=' . (int)$employeeId) : '') . '">View shifts</a>';
    echo '</div>';
    echo '</div>';
  }
?>
</div>

<div class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-5 text-sm text-white/70">
  Tip: Set target hours from <a class="underline hover:text-white" href="./targets.php">Targets</a>. If targets are empty, variance will show as 0.
</div>

<?php super_page_end(); ?>
