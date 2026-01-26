<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

$canRun = admin_can($user, 'run_payroll');

$tzName = (string)setting($pdo, 'payroll_timezone', 'Europe/London');
if (trim($tzName) === '') $tzName = 'Europe/London';
$tz = new DateTimeZone($tzName);

// Default month in payroll timezone
$nowLocal = new DateTimeImmutable('now', $tz);
$ym = (string)($_GET['ym'] ?? $nowLocal->format('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
  $ym = $nowLocal->format('Y-m');
}

// POST: run payroll
$err = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canRun) {
    http_response_code(403);
    exit('Forbidden');
  }
  admin_verify_csrf($_POST['csrf'] ?? null);

  $action = (string)($_POST['action'] ?? '');
  if ($action === 'run') {
    $ymPost = (string)($_POST['ym'] ?? $ym);
    if (!preg_match('/^\d{4}-\d{2}$/', $ymPost)) {
      $err = 'Invalid month.';
    } else {
      $reason = trim((string)($_POST['reason'] ?? ''));
      try {
        require_once __DIR__ . '/payroll-runner.php';
        $batchId = payroll_run_month($pdo, $user, $ymPost, $reason);
        $success = 'Payroll run created (Batch #' . (int)$batchId . ').';
        $ym = $ymPost;
      } catch (Throwable $e) {
        $err = 'Failed to run payroll: ' . $e->getMessage();
      }
    }
  }
}

// Load recent batches
$st = $pdo->prepare('SELECT * FROM payroll_batches ORDER BY run_at DESC LIMIT 25');
$st->execute();
$batches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$title = 'Payroll Runs';
$active = admin_url('payroll-runs.php');
admin_page_start($pdo, $title);
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="w-full">
      <div class="flex flex-col lg:flex-row gap-5">

        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1 min-w-0">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-2xl font-semibold">Payroll Runs</h1>
              <div class="mt-1 text-sm text-slate-600">Run monthly payroll batches (hours + audit snapshots). Overtime is calculated weekly; the last incomplete week of the month is deferred to the next month.</div>
            </div>
          </div>

          <?php if ($err): ?>
            <div class="mt-4 p-3 rounded-2xl bg-red-500/15 border border-red-500/30 text-red-100"><?php echo h($err); ?></div>
          <?php endif; ?>
          <?php if ($success): ?>
            <div class="mt-4 p-3 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-slate-900"><?php echo h($success); ?></div>
          <?php endif; ?>

          <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <section class="rounded-3xl border border-slate-200 bg-white p-4">
              <h2 class="text-lg font-semibold">Select month</h2>
              <form method="get" class="mt-3 flex items-end gap-3">
                <label class="block flex-1">
                  <div class="text-sm text-slate-600">Month</div>
                  <input type="month" name="ym" value="<?php echo h($ym); ?>" class="mt-1 w-full rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">
                </label>
                <button class="px-4 py-2 rounded-xl bg-slate-50 hover:bg-slate-100">View</button>
              </form>

              <?php if ($canRun): ?>
                <div class="mt-5 border-t border-slate-200 pt-4">
                  <h3 class="font-semibold">Run payroll</h3>
                  <form method="post" class="mt-3 space-y-3">
                    <input type="hidden" name="csrf" value="<?php echo h(admin_csrf_token()); ?>">
                    <input type="hidden" name="action" value="run">
                    <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                    <label class="block">
                      <div class="text-sm text-slate-600">Reason / note (optional)</div>
                      <input name="reason" class="mt-1 w-full rounded-xl bg-slate-50 border border-slate-200 px-3 py-2" placeholder="e.g. Monthly run" />
                    </label>

                    <button class="w-full px-4 py-2 rounded-xl bg-emerald-500/20 border border-emerald-500/30 hover:bg-emerald-500/25">Run payroll for <?php echo h($ym); ?></button>
                  </form>
                </div>
              <?php else: ?>
                <div class="mt-5 text-sm text-slate-500">You can view batches, but your role cannot run payroll.</div>
              <?php endif; ?>
            </section>

            <section class="lg:col-span-2 rounded-3xl border border-slate-200 bg-white p-4">
              <h2 class="text-lg font-semibold">Recent batches</h2>
              <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-slate-500">
                      <th class="text-left py-2">Batch</th>
                      <th class="text-left py-2">Period</th>
                      <th class="text-left py-2">Run at (UTC)</th>
                      <th class="text-left py-2">Status</th>
                      <th class="text-left py-2">Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($batches as $b): ?>
                      <tr class="border-t border-slate-200">
                        <td class="py-2">
                          <a class="text-black-200 hover:underline" href="<?php echo h(admin_url('payroll-view.php?batch_id='.(int)$b['id'])); ?>">#<?php echo (int)$b['id']; ?></a>
                        </td>
                        <td class="py-2"><?php echo h((string)$b['period_start']); ?> → <?php echo h((string)$b['period_end']); ?></td>
                        <td class="py-2"><?php echo h((string)$b['run_at']); ?></td>
                        <td class="py-2"><?php echo h((string)$b['status']); ?></td>
                        <td class="py-2"><?php echo h((string)($b['notes'] ?? '')); ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$batches): ?>
                      <tr><td colspan="5" class="py-3 text-slate-500">No payroll batches yet.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </div>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
