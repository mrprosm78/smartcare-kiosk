<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

admin_require_perm($user, 'view_dashboard');

// lightweight stats (safe defaults)
$today = gmdate('Y-m-d');

$counts = [
  'today_shifts' => 0,
  'open_shifts' => 0,
  'unapproved'  => 0,
  'employees'   => 0,
];

try {
  // shifts today (by clock_in date)
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM kiosk_shifts WHERE DATE(clock_in) = ?");
  $stmt->execute([$today]);
  $counts['today_shifts'] = (int)$stmt->fetchColumn();

  // open shifts (missing clock_out)
  $stmt = $pdo->query("SELECT COUNT(*) FROM kiosk_shifts WHERE clock_out IS NULL");
  $counts['open_shifts'] = (int)$stmt->fetchColumn();

  // unapproved (approved_at null)
  $stmt = $pdo->query("SELECT COUNT(*) FROM kiosk_shifts WHERE approved_at IS NULL");
  $counts['unapproved'] = (int)$stmt->fetchColumn();

  // active employees
  $stmt = $pdo->query("SELECT COUNT(*) FROM kiosk_employees WHERE is_active = 1");
  $counts['employees'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
  // keep zeros
}

admin_page_start($pdo, 'Admin Dashboard');

$active = admin_url('index.php');
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-8">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">

        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Dashboard</h1>
                <p class="mt-2 text-sm text-white/70">
                  Welcome back, <span class="font-semibold text-white/90"><?= h((string)($user['display_name'] ?: $user['username'])) ?></span>.
                  <span class="text-white/40">(UTC date: <?= h($today) ?>)</span>
                </p>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <?php if (admin_can($user, 'manage_devices')): ?>
                  <a href="<?= h(admin_url('devices.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Devices</a>
                <?php endif; ?>
                <?php if (admin_can($user, 'manage_settings')): ?>
                  <a href="<?= h(admin_url('settings.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Settings</a>
                <?php endif; ?>
              </div>
            </div>
          </header>

          <section class="mt-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Shifts today</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['today_shifts'] ?></div>
              <div class="mt-2 text-xs text-white/40">Based on clock-in date</div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Open shifts</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['open_shifts'] ?></div>
              <div class="mt-2 text-xs text-white/40">Missing clock-out</div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Unapproved</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['unapproved'] ?></div>
              <div class="mt-2 text-xs text-white/40">Awaiting manager approval</div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <div class="text-sm text-white/60">Active employees</div>
              <div class="mt-2 text-3xl font-semibold"><?= (int)$counts['employees'] ?></div>
              <div class="mt-2 text-xs text-white/40">Excludes inactive/archived</div>
            </div>
          </section>

          <section class="mt-5 grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Next: Manager logs review</h2>
              <p class="mt-2 text-sm text-white/70">We’ll wire your existing UI (UI/manager-log-review.html) into real pages: filters, edit shifts, approvals, audit trail.</p>
              <div class="mt-4 flex flex-wrap gap-2">
                <a href="<?= h(admin_url('shifts.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white text-slate-900">Open Shifts</a>
                <?php if (admin_can($user, 'manage_employees')): ?>
                  <a href="<?= h(admin_url('employees.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Employees</a>
                <?php endif; ?>
                <?php if (admin_can($user, 'run_payroll')): ?>
                  <a href="<?= h(admin_url('payroll-run.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Run Payroll (Monthly)</a>
                <?php endif; ?>
                <?php if (admin_can($user, 'export_payroll')): ?>
                  <a href="<?= h(admin_url('payroll.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Payroll Export</a>
                <?php endif; ?>
              </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Security</h2>
              <div class="mt-3 grid gap-2 text-sm">
                <div class="flex items-center justify-between">
                  <span class="text-white/70">Trusted device</span>
                  <span class="font-semibold text-white/90"><?= h((string)($device['label'] ?? 'Device')) ?></span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-white/70">Pairing mode</span>
                  <span class="font-semibold <?= admin_pairing_is_allowed($pdo) ? 'text-emerald-200' : 'text-white/70' ?>"><?= admin_pairing_is_allowed($pdo) ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-white/70">Admin pairing version</span>
                  <span class="font-semibold text-white/90"><?= (int)admin_setting_int($pdo, 'admin_pairing_version', 1) ?></span>
                </div>
              </div>
              <p class="mt-3 text-xs text-white/40">If you revoke devices or bump pairing version, those devices will need re-pairing.</p>
            </div>
          </section>
        </main>

      </div>
    </div>
  </div>
</div>

<?php
admin_page_end();