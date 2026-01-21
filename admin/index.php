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
                <?php if (admin_can($user, 'manage_settings_basic')): ?>
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
              <h2 class="text-lg font-semibold">Quick links</h2>
              <p class="mt-2 text-sm text-white/70">Review and approve shifts, then review monthly payroll hours.</p>
              <div class="mt-4 flex flex-wrap gap-2">
                <a href="<?= h(admin_url('shifts.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white text-slate-900">Open Shifts</a>
                <?php if (admin_can($user, 'manage_employees')): ?>
                  <a href="<?= h(admin_url('employees.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Employees</a>
                <?php endif; ?>
                <?php if (admin_can($user, 'view_payroll')): ?>
                  <a href="<?= h(admin_url('payroll-hours.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Payroll Hours</a>
                <?php endif; ?>
              </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Notes</h2>
              <p class="mt-2 text-sm text-white/70">This admin uses username + password only. Device pairing is disabled.</p>
              <p class="mt-3 text-xs text-white/40">Time calculations are shown as hours/minutes only. Exporting money values is intentionally not part of this system.</p>
            </div>
          </section>
        </main>

      </div>
    </div>
  </div>
</div>

<?php
admin_page_end();