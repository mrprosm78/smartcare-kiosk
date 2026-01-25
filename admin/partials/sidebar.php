<?php
declare(strict_types=1);

// expects: $user, $active (string)

// Default $active so pages don't trigger warnings if they set it later.
$active = $active ?? '';

function admin_nav_item(string $href, string $label, string $active): string {
  $is = ($href === $active);
  $base = 'flex items-center justify-between rounded-2xl px-3 py-2 text-sm font-semibold transition-colors';
  if ($is) {
    return '<a href="' . h($href) . '" class="' . $base . ' bg-white text-slate-900">' . h($label) . '</a>';
  }
  return '<a href="' . h($href) . '" class="' . $base . ' bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">' . h($label) . '</a>';
}

$items = [];

$items[] = ['href' => admin_url('index.php'), 'label' => 'Dashboard', 'perm' => 'view_dashboard'];
$items[] = ['href' => admin_url('shifts.php'), 'label' => 'Shifts', 'perm' => 'view_shifts'];
$items[] = ['href' => admin_url('punch-details.php'), 'label' => 'Punch Details', 'perm' => 'view_punches'];
$items[] = ['href' => admin_url('employees.php'), 'label' => 'Employees', 'perm' => 'view_employees'];
$items[] = ['href' => admin_url('departments.php'), 'label' => 'Departments', 'perm' => 'manage_employees'];
$items[] = ['href' => admin_url('teams.php'), 'label' => 'Teams', 'perm' => 'manage_employees'];
$items[] = ['href' => admin_url('payroll-runs.php'), 'label' => 'Payroll Runs', 'perm' => 'view_payroll'];
$items[] = ['href' => admin_url('payroll-calendar-employee.php'), 'label' => 'Payroll Calendar (Employee)', 'perm' => 'view_payroll'];
$items[] = ['href' => admin_url('payroll-calendar-all.php'), 'label' => 'Payroll Calendar (All)', 'perm' => 'view_payroll'];
$items[] = ['href' => admin_url('break-tiers.php'), 'label' => 'Break Tiers', 'perm' => 'manage_settings_basic'];
$items[] = ['href' => admin_url('settings.php'), 'label' => 'Settings', 'perm' => 'manage_settings_basic'];

?>

<aside class="w-full lg:w-72 shrink-0 lg:sticky lg:top-6 self-start">
  <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="text-xs uppercase tracking-widest text-white/50">SmartCare</div>
        <div class="mt-1 text-lg font-semibold">Admin</div>
        <div class="mt-1 text-xs text-white/60">Signed in as <span class="font-semibold text-white/90"><?= h((string)($user['username'] ?? '')) ?></span></div>
        <div class="mt-1 text-xs text-white/40">Role: <?= h((string)($user['role'] ?? '')) ?></div>
      </div>
      <a href="<?= h(admin_url('logout.php')) ?>" class="rounded-2xl px-3 py-2 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-rose-100 hover:bg-rose-500/20">Logout</a>
    </div>

    <div class="mt-4 grid gap-2">
      <?php foreach ($items as $it): ?>
        <?php if (admin_can($user, (string)$it['perm'])): ?>
          <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active) ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <div class="mt-4 border-t border-white/10 pt-4 flex items-center justify-between">
      <span class="text-[11px] text-white/40">UTC</span>
    </div>
  </div>
</aside>
