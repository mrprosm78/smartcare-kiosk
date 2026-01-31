<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_punches');

/**
 * Punch Details (read-only)
 * - Lists raw kiosk_punch_events with optional photo thumbnail.
 * - Manager/Payroll/Admin/Superadmin can view (permission: view_punches).
 * - No edits.
 */

function badge(string $text, string $kind = 'neutral'): string {
  $base = "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold border";
  if ($kind === 'ok')   return "<span class='$base bg-emerald-500/10 border-emerald-400/20 text-slate-900'>$text</span>";
  if ($kind === 'warn') return "<span class='$base bg-amber-500/10 border-amber-400/20 text-slate-900'>$text</span>";
  if ($kind === 'bad')  return "<span class='$base bg-rose-500/10 border-rose-400/20 text-slate-900'>$text</span>";
  return "<span class='$base bg-white border border-slate-200 text-slate-700'>$text</span>";
}

function iso_date(string $ymd): bool {
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd);
}

function period_bounds(string $mode, string $tz = 'Europe/London', string $weekStartsOn = 'MONDAY'): array {
  $now = new DateTimeImmutable('now', new DateTimeZone($tz));
  $today = $now->setTime(0, 0, 0);

  $ws = strtoupper(trim($weekStartsOn));
  $wsLower = strtolower($ws);
  // PHP supports phrases like "monday this week", "sunday this week", etc.
  $weekStartThis = $today->modify($wsLower . ' this week');
  $weekStartLast = $weekStartThis->modify('-7 days');
  $weekEndThis   = $weekStartThis->modify('+6 days');
  $weekEndLast   = $weekStartLast->modify('+6 days');

  $firstThisMonth = $today->modify('first day of this month');
  $lastThisMonth  = $today->modify('last day of this month');
  $firstLastMonth = $firstThisMonth->modify('-1 month');
  $lastLastMonth  = $firstThisMonth->modify('-1 day');

  $from = $today;
  $to = $today;
  switch ($mode) {
    case 'yesterday':
      $from = $today->modify('-1 day');
      $to = $from;
      break;
    case 'this_week':
      $from = $weekStartThis;
      $to = $weekEndThis;
      break;
    case 'last_week':
      $from = $weekStartLast;
      $to = $weekEndLast;
      break;
    case 'this_month':
      $from = $firstThisMonth;
      $to = $lastThisMonth;
      break;
    case 'last_month':
      $from = $firstLastMonth;
      $to = $lastLastMonth;
      break;
    case 'today':
    default:
      $from = $today;
      $to = $today;
      break;
  }

  return [
    'from' => $from->format('Y-m-d'),
    'to' => $to->format('Y-m-d'),
  ];
}

$tz = admin_setting_str($pdo, 'payroll_timezone', 'Europe/London');
$weekStartsOn = payroll_week_starts_on($pdo);

$hasQuery = !empty($_GET);
// Default behaviour: on first load (no query params), show Yesterday.
$mode = (string)($_GET['mode'] ?? ($hasQuery ? 'this_week' : 'yesterday'));
if (!in_array($mode, ['today','yesterday','this_week','last_week','this_month','last_month','custom'], true)) {
  $mode = 'this_week';
}

$bounds = period_bounds($mode === 'custom' ? 'this_week' : $mode, $tz, $weekStartsOn);
$from = (string)($_GET['from'] ?? $bounds['from']);
$to   = (string)($_GET['to'] ?? $bounds['to']);
if (!iso_date($from)) $from = $bounds['from'];
if (!iso_date($to)) $to = $bounds['to'];

$employeeId = (int)($_GET['employee_id'] ?? 0);
$action = strtoupper(trim((string)($_GET['action'] ?? '')));
if (!in_array($action, ['', 'IN', 'OUT'], true)) $action = '';

// Fetch employees for filter
$employees = [];
try {
  $st = $pdo->query("SELECT id, employee_code, first_name, last_name, nickname FROM kiosk_employees ORDER BY employee_code ASC");
  $employees = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $employees = [];
}

// Query punch events
$where = [];
$params = [];

// Use effective_time when available, else received_at, else device_time
$timeExpr = "COALESCE(pe.effective_time, pe.received_at, pe.device_time)";

// ✅ FIX: build range in payroll timezone, convert boundaries to UTC for DB comparison
$fromStartLocal = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone($tz));
$toEndLocal     = (new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone($tz)))->modify('+1 day');

$fromStartUtc = $fromStartLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$toEndUtc     = $toEndLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$where[] = "$timeExpr >= ?";
$where[] = "$timeExpr < ?";
$params[] = $fromStartUtc;
$params[] = $toEndUtc;

if ($employeeId > 0) {
  $where[] = "pe.employee_id = ?";
  $params[] = $employeeId;
}
if ($action !== '') {
  $where[] = "pe.action = ?";
  $params[] = $action;
}

$sql = "
  SELECT
    pe.id,
    pe.event_uuid,
    pe.employee_id,
    pe.action,
    pe.device_time,
    pe.received_at,
    pe.effective_time,
    pe.result_status,
    pe.source,
    pe.was_offline,
    pe.error_code,
    pe.shift_id,
    pe.ip_address,
    pe.user_agent,
    e.employee_code,
    e.first_name,
    e.last_name,
    e.nickname,
    pp.id AS photo_id
  FROM kiosk_punch_events pe
  LEFT JOIN kiosk_employees e ON e.id = pe.employee_id
  LEFT JOIN kiosk_punch_photos pp ON pp.event_uuid = pe.event_uuid AND pp.action = pe.action
  WHERE " . implode(' AND ', $where) . "
  ORDER BY $timeExpr DESC, pe.id DESC
  LIMIT 500
";

$rows = [];
try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $rows = [];
  $err = $e->getMessage();
}

admin_page_start($pdo, 'Punch Details');
$active = admin_url('punch-details.php');
?>
<!-- rest of your HTML stays the same -->


<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-7xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Punch Details</h1>
                <p class="mt-2 text-sm text-slate-600">Read-only audit of punch events. Photos are served via admin endpoint (no direct links).</p>
              </div>
              <div class="text-xs text-slate-500">Showing up to 500 rows</div>
            </div>

            <form id="filters" method="get" class="mt-4 grid grid-cols-1 md:grid-cols-12 gap-3">
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">Period</label>
                <select name="mode" id="mode" class="mt-1 w-full h-10 rounded-2xl bg-white border border-slate-200 px-3 text-sm">
                  <?php
                    $modes = [
                      'today' => 'Today',
                      'yesterday' => 'Yesterday',
                      'this_week' => 'This week',
                      'last_week' => 'Last week',
                      'this_month' => 'This month',
                      'last_month' => 'Last month',
                      'custom' => 'Custom',
                    ];
                    foreach ($modes as $k => $label) {
                      $sel = ($mode === $k) ? 'selected' : '';
                      echo '<option value="' . h($k) . '" ' . $sel . '>' . h($label) . '</option>';
                    }
                  ?>
                </select>
              </div>

              <div class="md:col-span-4">
                <label class="block text-xs font-semibold text-slate-600">Employee</label>
                <select name="employee_id" id="employee_id" class="mt-1 w-full h-10 rounded-2xl bg-white border border-slate-200 px-3 text-sm">
                  <option value="0">All employees</option>
                  <?php foreach ($employees as $e):
                    $id = (int)($e['id'] ?? 0);
                    $sel = ($employeeId === $id) ? 'selected' : '';
                    $label = trim((string)($e['employee_code'] ?? ''));
                    $name = admin_employee_display_name($e);
                    $text = $label !== '' ? ($label . ' — ' . $name) : $name;
                  ?>
                    <option value="<?= $id ?>" <?= $sel ?>><?= h($text) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">From</label>
                <input type="date" name="from" id="from" value="<?= h($from) ?>" class="mt-1 w-full h-10 rounded-2xl bg-white border border-slate-200 px-3 text-sm" />
              </div>

              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">To</label>
                <input type="date" name="to" id="to" value="<?= h($to) ?>" class="mt-1 w-full h-10 rounded-2xl bg-white border border-slate-200 px-3 text-sm" />
              </div>

              <div class="md:col-span-1">
                <label class="block text-xs font-semibold text-slate-600">Action</label>
                <select name="action" id="action" class="mt-1 w-full h-10 rounded-2xl bg-white border border-slate-200 px-3 text-sm">
                  <option value="" <?= $action === '' ? 'selected' : '' ?>>All</option>
                  <option value="IN" <?= $action === 'IN' ? 'selected' : '' ?>>IN</option>
                  <option value="OUT" <?= $action === 'OUT' ? 'selected' : '' ?>>OUT</option>
                </select>
              </div>

              <div class="md:col-span-1 flex items-end justify-end">
                <a href="<?= h(admin_url('punch-details.php')) ?>" class="inline-flex h-10 items-center justify-center rounded-2xl px-4 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 w-full">Clear</a>
              </div>
            </form>

          </header>

          <div class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <?php if (!empty($err ?? '')): ?>
              <div class="mt-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-slate-900">Error: <?= h((string)$err) ?></div>
            <?php endif; ?>

            <div class="overflow-x-auto">
              <table class="min-w-full text-sm border border-slate-200 border-collapse">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-widest text-slate-500">
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Time</th>
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Employee</th>
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Action</th>
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Status</th>
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Source</th>
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Shift</th>
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Photo</th>
                    <th class="py-2 px-2 border-b border-slate-200 bg-slate-50 whitespace-nowrap">Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="py-6 text-slate-500">No punches found for this range.</td></tr>
                  <?php else: ?>
                    <?php foreach ($rows as $r):
                      $t = (string)($r['effective_time'] ?? '');
                      if ($t === '') $t = (string)($r['received_at'] ?? '');
                      if ($t === '') $t = (string)($r['device_time'] ?? '');
                      $emp = trim((string)($r['employee_code'] ?? ''));
                      $empName = admin_employee_display_name($r);
                      $empLabel = $emp !== '' ? ($emp . ' — ' . $empName) : $empName;
                      $act = (string)($r['action'] ?? '');
                      $status = trim((string)($r['result_status'] ?? ''));
                      $offline = ((int)($r['was_offline'] ?? 0) === 1);
                      $src = trim((string)($r['source'] ?? ''));
                      $errCode = trim((string)($r['error_code'] ?? ''));
                      $photoId = (int)($r['photo_id'] ?? 0);
                      $shiftId = (string)($r['shift_id'] ?? '');
                      $ua = (string)($r['user_agent'] ?? '');
                      $ip = (string)($r['ip_address'] ?? '');
                      $uuid = (string)($r['event_uuid'] ?? '');
                    ?>
                      <tr class="border-b border-slate-100">
                        <td class="py-2 px-2 whitespace-nowrap text-slate-900"><?= h($t !== '' ? admin_fmt_dt($t) : '—') ?></td>
                        <td class="py-2 px-2 whitespace-nowrap"><?= h($empLabel) ?></td>
                        <td class="py-2 px-2"><?= $act === 'IN' ? badge('IN','ok') : ($act === 'OUT' ? badge('OUT','warn') : badge('—')) ?></td>
                        <td class="py-2 px-2">
                          <div class="flex flex-col gap-1">
                            <?php
                              // kiosk_punch_events.result_status is typically: received | processed | rejected
                              $kind = 'neutral';
                              if ($status === 'processed') $kind = 'ok';
                              if ($status === 'received')  $kind = 'neutral';
                              if ($status === 'rejected')  $kind = 'bad';
                              // Some processed rows carry a warning error_code (e.g. shift_too_long_flagged)
                              if ($status === 'processed' && $errCode !== '') $kind = 'warn';
                              echo $status !== '' ? badge($status, $kind) : badge('—');
                            ?>
                            <?php if ($offline): ?><span class="text-[11px] text-slate-500">offline</span><?php endif; ?>
                            <?php if ($errCode !== ''): ?><span class="text-[11px] text-slate-700"><?= h($errCode) ?></span><?php endif; ?>
                          </div>
                        </td>
                        <td class="py-2 px-2 whitespace-nowrap"><?= h($src !== '' ? $src : '—') ?></td>
                        <td class="py-2 px-2 whitespace-nowrap"><?= h($shiftId !== '' ? $shiftId : '—') ?></td>
                        <td class="px-2">
                          <?php if ($photoId > 0): ?>
                            <a href="<?= h(admin_url('punch-photo.php?id=' . $photoId . '&size=full')) ?>" target="_blank" class="inline-block">
                              <img src="<?= h(admin_url('punch-photo.php?id=' . $photoId . '&size=thumb')) ?>" alt="Punch photo" class="h-10 w-10 rounded-lg object-cover border border-slate-200" />
                            </a>
                          <?php else: ?>
                            <span class="text-slate-400">—</span>
                          <?php endif; ?>
                        </td>
                        <td class="py-2 px-2">
                          <details class="rounded-xl border border-slate-200 bg-white px-2 py-1">
                            <summary class="cursor-pointer select-none text-[11px] text-slate-600">View</summary>
                            <div class="mt-1 text-[11px] text-slate-500 space-y-1">
                              <?php if ($uuid !== ''): ?><div><span class="text-slate-400">event_uuid:</span> <?= h($uuid) ?></div><?php endif; ?>
                              <?php if ($ip !== ''): ?><div><span class="text-slate-400">ip:</span> <?= h($ip) ?></div><?php endif; ?>
                              <?php if ($ua !== ''): ?><div class="break-words"><span class="text-slate-400">ua:</span> <?= h($ua) ?></div><?php endif; ?>
                              <?php if (!empty($r['device_time'])): ?><div><span class="text-slate-400">device:</span> <?= h((string)$r['device_time']) ?></div><?php endif; ?>
                              <?php if (!empty($r['received_at'])): ?><div><span class="text-slate-400">received:</span> <?= h((string)$r['received_at']) ?></div><?php endif; ?>
                            </div>
                          </details>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </main>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const WEEK_START = "<?= h(payroll_week_starts_on($pdo)) ?>"; // e.g. MONDAY or SUNDAY
  const mode = document.getElementById('mode');
  const from = document.getElementById('from');
  const to = document.getElementById('to');
  const emp = document.getElementById('employee_id');
  const act = document.getElementById('action');
  const form = document.getElementById('filters');
  if (!mode || !from || !to) return;
  function pad(n){ return (n<10?('0'+n):(''+n)); }
  function fmt(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }

  function weekStart(d){
    // Week start based on configured payroll week start
    const map = {SUNDAY:0, MONDAY:1, TUESDAY:2, WEDNESDAY:3, THURSDAY:4, FRIDAY:5, SATURDAY:6};
    const start = map[WEEK_START] ?? 1; // default Monday
    const day = d.getDay(); // 0..6
    const diff = (day - start + 7) % 7;
    const s = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    s.setDate(s.getDate() - diff);
    return s;
  }

  function setRange(k){
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    let a = new Date(today);
    let b = new Date(today);

    if (k === 'yesterday') {
      a.setDate(a.getDate() - 1);
      b = new Date(a);
    } else if (k === 'this_week') {
      a = weekStart(today);
      b = new Date(a);
      b.setDate(b.getDate() + 6);
    } else if (k === 'last_week') {
      a = weekStart(today);
      a.setDate(a.getDate() - 7);
      b = new Date(a);
      b.setDate(b.getDate() + 6);
    } else if (k === 'this_month') {
      a = new Date(today.getFullYear(), today.getMonth(), 1);
      b = new Date(today.getFullYear(), today.getMonth()+1, 0);
    } else if (k === 'last_month') {
      a = new Date(today.getFullYear(), today.getMonth()-1, 1);
      b = new Date(today.getFullYear(), today.getMonth(), 0);
    } else {
      // today
    }

    from.value = fmt(a);
    to.value = fmt(b);
  }

  function submitNow(){
    if (!form) return;
    form.submit();
  }

  let tmr = null;
  function submitSoon(){
    if (!form) return;
    if (tmr) clearTimeout(tmr);
    tmr = setTimeout(()=>form.submit(), 200);
  }

  // Period: set dates + auto-apply (except Custom)
  mode.addEventListener('change', function(){
    if (this.value === 'custom') { submitNow(); return; }
    setRange(this.value);
    submitNow();
  });

  // Other filters auto-apply
  if (emp) emp.addEventListener('change', submitNow);
  if (act) act.addEventListener('change', submitNow);
  from.addEventListener('change', function(){ mode.value = 'custom'; submitSoon(); });
  to.addEventListener('change', function(){ mode.value = 'custom'; submitSoon(); });
})();
</script>





<?php admin_page_end(); ?>
