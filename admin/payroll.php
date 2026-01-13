<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

$active = admin_url('payroll.php');

// Default range: current week (Mon-Sun) in UTC
function admin_week_range_utc(): array {
  $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
  $dow = (int)$now->format('N'); // 1=Mon..7=Sun
  $mon = $now->sub(new DateInterval('P' . ($dow - 1) . 'D'));
  $start = $mon->setTime(0,0,0);
  $end = $start->add(new DateInterval('P7D')); // exclusive end
  return [$start, $end];
}

[$dStart, $dEnd] = admin_week_range_utc();
$from = (string)($_GET['from'] ?? $dStart->format('Y-m-d'));
$to   = (string)($_GET['to'] ?? $dEnd->sub(new DateInterval('P1D'))->format('Y-m-d'));

$includeUnapproved = false;
if (admin_can($user, 'edit_shifts') || admin_can($user, 'approve_shifts')) {
  $includeUnapproved = ((string)($_GET['include_unapproved'] ?? '') === '1');
}

// Payroll users can NEVER include unapproved
if ((string)($user['role'] ?? '') === 'payroll') {
  $includeUnapproved = false;
}

// Settings
$roundingEnabled = admin_setting_bool($pdo, 'rounding_enabled', true);
$roundInc = admin_setting_int($pdo, 'round_increment_minutes', 15);
$roundGrace = admin_setting_int($pdo, 'round_grace_minutes', 5);

// Build query range (use exclusive end boundary to avoid time edge bugs)
$fromStart = $from . ' 00:00:00';
$toExclusive = (new DateTimeImmutable($to, new DateTimeZone('UTC')))
  ->setTime(0,0,0)
  ->add(new DateInterval('P1D'))
  ->format('Y-m-d H:i:s');

// Locking helpers
$notice = (string)($_GET['n'] ?? '');
$error = '';
$success = '';

$canLock = admin_can($user, 'export_payroll'); // payroll + admin
$canUnlock = admin_can($user, 'manage_settings_high'); // superadmin only (recommended)

function payroll_make_batch_id(string $from, string $to): string {
  // stable + readable
  return 'PR-' . $from . '_to_' . $to . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
}

// Handle lock/unlock actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'lock_period') {
    if (!$canLock) {
      $error = 'Not authorised to lock payroll.';
    } else {
      $batchId = payroll_make_batch_id($from, $to);
      $by = (string)($user['username'] ?? 'payroll');

      try {
        // Lock ONLY approved shifts in range that are not already locked
        $stmt = $pdo->prepare("
          UPDATE kiosk_shifts
          SET payroll_locked_at = UTC_TIMESTAMP,
              payroll_locked_by = :by,
              payroll_batch_id  = :batch
          WHERE clock_in_at >= :from_dt
            AND clock_in_at <  :to_dt
            AND approved_at IS NOT NULL
            AND payroll_locked_at IS NULL
        ");
        $stmt->execute([
          ':by' => $by,
          ':batch' => $batchId,
          ':from_dt' => $fromStart,
          ':to_dt' => $toExclusive,
        ]);

        admin_redirect(admin_url('payroll.php?' . http_build_query($_GET + ['n' => 'locked', 'batch' => $batchId])));
      } catch (Throwable $e) {
        $error = 'Failed to lock payroll period: ' . $e->getMessage();
      }
    }
  }

  if ($action === 'unlock_period') {
    if (!$canUnlock) {
      $error = 'Only Super Admin can unlock payroll.';
    } else {
      try {
        // Unlock shifts in range (you can keep batch_id history by not clearing it,
        // but typically we clear to reflect unlock state cleanly)
        $stmt = $pdo->prepare("
          UPDATE kiosk_shifts
          SET payroll_locked_at = NULL,
              payroll_locked_by = NULL,
              payroll_batch_id  = NULL
          WHERE clock_in_at >= :from_dt
            AND clock_in_at <  :to_dt
        ");
        $stmt->execute([
          ':from_dt' => $fromStart,
          ':to_dt' => $toExclusive,
        ]);

        admin_redirect(admin_url('payroll.php?' . http_build_query($_GET + ['n' => 'unlocked'])));
      } catch (Throwable $e) {
        $error = 'Failed to unlock payroll period: ' . $e->getMessage();
      }
    }
  }
}

// Pull shifts + latest edit json + pay profile
$sql = "
  SELECT
    s.*,
    e.first_name, e.last_name, e.nickname, e.is_agency, e.agency_label,
    c.name AS category_name,
    p.contract_hours_per_week,
    p.break_minutes_default,
    p.break_is_paid,
    p.min_hours_for_break,
    (
      SELECT sc.new_json
      FROM kiosk_shift_changes sc
      WHERE sc.shift_id = s.id AND sc.change_type = 'edit'
      ORDER BY sc.id DESC
      LIMIT 1
    ) AS latest_edit_json
  FROM kiosk_shifts s
  JOIN kiosk_employees e ON e.id = s.employee_id
  LEFT JOIN kiosk_employee_categories c ON c.id = e.category_id
  LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = e.id
  WHERE s.clock_in_at >= :fromDt AND s.clock_in_at < :toDt
";

if (!$includeUnapproved) {
  $sql .= " AND s.approved_at IS NOT NULL ";
}

$sql .= " ORDER BY e.is_agency ASC, e.last_name ASC, e.first_name ASC, s.clock_in_at ASC ";

$stmt = $pdo->prepare($sql);
$stmt->execute([':fromDt' => $fromStart, ':toDt' => $toExclusive]);
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Period lock status summary
$lockInfo = [
  'locked_count' => 0,
  'approved_count' => 0,
  'unlocked_approved_count' => 0,
  'batch_id' => '',
  'last_locked_at' => '',
  'last_locked_by' => '',
];

try {
  $st = $pdo->prepare("
    SELECT
      SUM(CASE WHEN approved_at IS NOT NULL THEN 1 ELSE 0 END) AS approved_count,
      SUM(CASE WHEN approved_at IS NOT NULL AND payroll_locked_at IS NOT NULL THEN 1 ELSE 0 END) AS locked_count,
      SUM(CASE WHEN approved_at IS NOT NULL AND payroll_locked_at IS NULL THEN 1 ELSE 0 END) AS unlocked_approved_count,
      MAX(payroll_locked_at) AS last_locked_at,
      SUBSTRING_INDEX(GROUP_CONCAT(payroll_locked_by ORDER BY payroll_locked_at DESC SEPARATOR ','), ',', 1) AS last_locked_by,
      SUBSTRING_INDEX(GROUP_CONCAT(payroll_batch_id ORDER BY payroll_locked_at DESC SEPARATOR ','), ',', 1) AS batch_id
    FROM kiosk_shifts
    WHERE clock_in_at >= :from_dt AND clock_in_at < :to_dt
  ");
  $st->execute([':from_dt' => $fromStart, ':to_dt' => $toExclusive]);
  $lockInfo = array_merge($lockInfo, (array)$st->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  // ignore; page still renders
}

// Aggregate by employee (only payable closed shifts)
$rows = [];
foreach ($shifts as $s) {
  $eff = admin_shift_effective($s);
  $in = $eff['clock_in_at'] ?: null;
  $out = $eff['clock_out_at'] ?: null;
  if (!$in || !$out) continue;

  // rounding for payroll display
  $rin = $in;
  $rout = $out;
  if ($roundingEnabled) {
    $rin = admin_round_datetime($in, $roundInc, $roundGrace) ?: $in;
    $rout = admin_round_datetime($out, $roundInc, $roundGrace) ?: $out;
  }

  $mins = admin_minutes_between($rin, $rout);
  if ($mins === null || $mins <= 0) continue;

  // break minutes
  $break = null;
  if ($eff['break_minutes'] !== null) {
    $break = (int)$eff['break_minutes'];
  } elseif ($s['break_minutes_default'] !== null && $s['break_minutes_default'] !== '') {
    $break = (int)$s['break_minutes_default'];
  } else {
    $break = 0;
  }

  $minHours = ($s['min_hours_for_break'] !== null && $s['min_hours_for_break'] !== '') ? (float)$s['min_hours_for_break'] : null;
  if ($minHours !== null) {
    $shiftHours = $mins / 60.0;
    if ($shiftHours < $minHours) $break = 0;
  }

  $breakPaid = ((int)($s['break_is_paid'] ?? 0) === 1);
  $paidMins = $breakPaid ? $mins : max(0, $mins - $break);

  $empId = (int)$s['employee_id'];
  if (!isset($rows[$empId])) {
    $name = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
    $nick = trim((string)($s['nickname'] ?? ''));
    if ($nick !== '') $name .= ' (' . $nick . ')';
    if ((int)$s['is_agency'] === 1) {
      $name = trim((string)($s['agency_label'] ?? 'Agency'));
    }
    $rows[$empId] = [
      'employee_id' => $empId,
      'name' => $name,
      'type' => ((int)$s['is_agency'] === 1) ? 'Agency' : 'Staff',
      'category' => (string)($s['category_name'] ?? ''),
      'contract_hours_per_week' => $s['contract_hours_per_week'],
      'worked_minutes' => 0,
      'break_minutes' => 0,
      'paid_minutes' => 0,
      'shifts' => 0,
    ];
  }

  $rows[$empId]['worked_minutes'] += $mins;
  $rows[$empId]['break_minutes'] += (int)$break;
  $rows[$empId]['paid_minutes'] += $paidMins;
  $rows[$empId]['shifts'] += 1;
}

// Sort rows by name
$rows = array_values($rows);
usort($rows, fn($a,$b) => strcmp((string)$a['name'], (string)$b['name']));

function mins_to_hours_str(int $mins): string {
  $h = (int)floor($mins / 60);
  $m = $mins % 60;
  return sprintf('%d:%02d', $h, $m);
}

admin_page_start($pdo, 'Payroll');
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Payroll</h1>
                <p class="mt-2 text-sm text-white/70">Totals use <span class="font-semibold">approved</span> shifts (unless you include unapproved) and rounding settings. Originals are never overwritten.</p>

                <div class="mt-3 text-xs text-white/60">
                  Approved in range: <span class="text-white font-semibold"><?= (int)$lockInfo['approved_count'] ?></span>
                  • Locked: <span class="text-white font-semibold"><?= (int)$lockInfo['locked_count'] ?></span>
                  • Unlocked approved: <span class="text-white font-semibold"><?= (int)$lockInfo['unlocked_approved_count'] ?></span>
                  <?php if (!empty($lockInfo['last_locked_at'])): ?>
                    <div class="mt-1 text-xs text-white/50">
                      Last lock: <?= h((string)$lockInfo['last_locked_at']) ?> by <?= h((string)($lockInfo['last_locked_by'] ?? '')) ?>
                      <?php if (!empty($lockInfo['batch_id'])): ?> • Batch <?= h((string)$lockInfo['batch_id']) ?><?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="flex items-center gap-2">
                <a href="<?= h(admin_url('payroll-export.php')) ?>?from=<?= h(urlencode($from)) ?>&to=<?= h(urlencode($to)) ?><?= $includeUnapproved ? '&include_unapproved=1' : '' ?>"
                   class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white text-slate-900 hover:bg-white/90">
                  Export CSV
                </a>

                <?php if ($canLock): ?>
                  <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="lock_period"/>
                    <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white/10 border border-white/10 hover:bg-white/15"
                            onclick="return confirm('Lock approved shifts for this payroll period? Locked shifts cannot be edited/approved without Super Admin unlock.');">
                      Lock period
                    </button>
                  </form>
                <?php endif; ?>

                <?php if ($canUnlock): ?>
                  <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="unlock_period"/>
                    <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-rose-500/10 border border-rose-400/20 text-rose-100 hover:bg-rose-500/15"
                            onclick="return confirm('UNLOCK shifts for this period? Use only if you must correct payroll.');">
                      Unlock (Super Admin)
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </header>

          <?php if ($error): ?>
            <div class="mt-4 rounded-2xl border border-rose-400/20 bg-rose-500/10 p-4 text-sm text-rose-100">
              <?= h($error) ?>
            </div>
          <?php endif; ?>

          <?php if ($notice === 'locked'): ?>
            <div class="mt-4 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 text-sm text-emerald-100">
              Payroll period locked. Batch: <?= h((string)($_GET['batch'] ?? '')) ?>
            </div>
          <?php endif; ?>

          <?php if ($notice === 'unlocked'): ?>
            <div class="mt-4 rounded-2xl border border-amber-400/20 bg-amber-500/10 p-4 text-sm text-amber-100">
              Payroll period unlocked.
            </div>
          <?php endif; ?>

          <form class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-4" method="get">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
              <label class="block">
                <div class="text-xs uppercase tracking-widest text-white/50">From</div>
                <input type="date" name="from" value="<?= h($from) ?>" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm" />
              </label>
              <label class="block">
                <div class="text-xs uppercase tracking-widest text-white/50">To</div>
                <input type="date" name="to" value="<?= h($to) ?>" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm" />
              </label>
              <div class="flex items-center gap-3 justify-between">
                <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Run</button>
                <?php if (admin_can($user, 'approve_shifts') || admin_can($user, 'edit_shifts')): ?>
                  <label class="flex items-center gap-2 text-sm text-white/80">
                    <input type="checkbox" name="include_unapproved" value="1" class="h-4 w-4 rounded" <?= $includeUnapproved ? 'checked' : '' ?> />
                    Include unapproved
                  </label>
                <?php endif; ?>
              </div>
            </div>
          </form>

          <div class="mt-5 rounded-3xl border border-white/10 bg-white/5 overflow-hidden">
            <div class="p-4 flex items-center justify-between">
              <div class="text-sm text-white/70"><span class="font-semibold text-white"><?= count($rows) ?></span> employees</div>
              <div class="text-xs text-white/50">Range: <?= h($from) ?> → <?= h($to) ?></div>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="bg-white/5 text-white/70">
                  <tr>
                    <th class="text-left font-semibold px-4 py-3">Employee</th>
                    <th class="text-left font-semibold px-4 py-3">Type</th>
                    <th class="text-left font-semibold px-4 py-3">Category</th>
                    <th class="text-right font-semibold px-4 py-3">Shifts</th>
                    <th class="text-right font-semibold px-4 py-3">Worked</th>
                    <th class="text-right font-semibold px-4 py-3">Break</th>
                    <th class="text-right font-semibold px-4 py-3">Paid</th>
                    <?php if (admin_can($user, 'view_contract')): ?>
                      <th class="text-right font-semibold px-4 py-3">Contract</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <td class="px-4 py-3">
                        <div class="font-semibold text-white"><?= h((string)$r['name']) ?></div>
                        <div class="mt-1 text-xs text-white/50">ID: <?= (int)$r['employee_id'] ?></div>
                      </td>
                      <td class="px-4 py-3 text-white/80"><?= h((string)$r['type']) ?></td>
                      <td class="px-4 py-3 text-white/80"><?= h((string)($r['category'] ?: '—')) ?></td>
                      <td class="px-4 py-3 text-right text-white/80"><?= (int)$r['shifts'] ?></td>
                      <td class="px-4 py-3 text-right text-white/80"><?= h(mins_to_hours_str((int)$r['worked_minutes'])) ?></td>
                      <td class="px-4 py-3 text-right text-white/80"><?= h(mins_to_hours_str((int)$r['break_minutes'])) ?></td>
                      <td class="px-4 py-3 text-right font-semibold text-white"><?= h(mins_to_hours_str((int)$r['paid_minutes'])) ?></td>
                      <?php if (admin_can($user, 'view_contract')): ?>
                        <?php $ch = $r['contract_hours_per_week'] !== null && $r['contract_hours_per_week'] !== '' ? ((string)$r['contract_hours_per_week'] . ' hrs/wk') : '—'; ?>
                        <td class="px-4 py-3 text-right text-white/80"><?= h($ch) ?></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>

                  <?php if (!$rows): ?>
                    <tr>
                      <td colspan="<?= admin_can($user, 'view_contract') ? 8 : 7 ?>" class="px-4 py-8 text-center text-white/60">No approved shifts in this range.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-4 text-sm text-white/70">
            <div class="font-semibold text-white">Notes</div>
            <ul class="mt-2 list-disc list-inside space-y-1">
              <li>Totals use <span class="font-semibold">effective</span> times (manager edits) and optional rounding from Settings.</li>
              <li>Unpaid breaks deduct from paid time. Paid breaks do not deduct.</li>
              <li>Open shifts (missing clock-out) are excluded.</li>
              <li><span class="font-semibold">Payroll lock</span> prevents edits/approval changes for locked shifts (recommended after export).</li>
            </ul>
          </div>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
