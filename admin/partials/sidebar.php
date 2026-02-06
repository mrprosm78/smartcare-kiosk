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
  return '<a href="' . h($href) . '" class="' . $base . ' bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">' . h($label) . '</a>';
}

function admin_nav_child_item(string $href, string $label, string $active): string {
  $is = ($href === $active);
  $base = 'flex items-center justify-between rounded-xl px-3 py-2 text-[13px] font-semibold transition-colors';
  if ($is) {
    return '<a href="' . h($href) . '" class="' . $base . ' bg-white text-slate-900">' . h($label) . '</a>';
  }
  return '<a href="' . h($href) . '" class="' . $base . ' bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">' . h($label) . '</a>';
}

$coreItems = [];
$coreItems[] = ['href' => admin_url('index.php'), 'label' => 'Dashboard', 'perm' => 'view_dashboard'];
$coreItems[] = ['href' => admin_url('employees.php'), 'label' => 'Employees', 'perm' => 'view_employees'];
$coreItems[] = ['href' => admin_url('punch-details.php'), 'label' => 'Punch Details', 'perm' => 'view_punches'];
$coreItems[] = ['href' => admin_url('shifts.php'), 'label' => 'Shift Grid', 'perm' => 'view_shifts'];
$coreItems[] = ['href' => admin_url('shift-editor.php'), 'label' => 'Review & Approvals', 'perm' => 'approve_shifts'];
$coreItems[] = ['href' => admin_url('payroll-calendar-employee.php'), 'label' => 'Payroll Monthly Report', 'perm' => 'view_payroll'];

$hrItems = [];
$hrItems[] = ['href' => admin_url('hr-applications.php'), 'label' => 'Applications', 'perm' => 'view_hr_applications'];
$hrItems[] = ['href' => admin_url('staff-new.php'), 'label' => 'Add Staff', 'perm' => 'manage_staff'];
$hrItems[] = ['href' => app_url('careers/'), 'label' => 'Careers (Public)', 'perm' => 'view_dashboard'];

$settingsItems = [];
$settingsItems[] = ['href' => admin_url('settings.php'), 'label' => 'Settings', 'perm' => 'manage_settings_basic'];

// Should the HR group be open?
$hrOpen = false;
foreach ($hrItems as $it) {
  if ((string)$it['href'] === (string)$active) {
    $hrOpen = true;
    break;
  }
}

// Check if user can see at least one HR link
$canSeeHr = false;
foreach ($hrItems as $it) {
  if (admin_can($user, (string)$it['perm'])) {
    $canSeeHr = true;
    break;
  }
}

?>

<aside class="w-full lg:w-72 shrink-0 lg:sticky lg:top-6 self-start">
  <div class="rounded-3xl border border-slate-200 bg-white p-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="text-xs uppercase tracking-widest text-slate-500">SmartCare</div>
        <div class="mt-1 text-lg font-semibold">Admin</div>
        <div class="mt-1 text-xs text-slate-500">Signed in as <span class="font-semibold text-slate-900"><?= h((string)($user['username'] ?? '')) ?></span></div>
        <div class="mt-1 text-xs text-slate-400">Role: <?= h((string)($user['role'] ?? '')) ?></div>
      </div>
      <a href="<?= h(admin_url('logout.php')) ?>" class="rounded-2xl px-3 py-2 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-rose-700 hover:bg-rose-500/20">Logout</a>
    </div>

    <div class="mt-4 grid gap-2">
      <?php foreach ($coreItems as $it): ?>
        <?php if (admin_can($user, (string)$it['perm'])): ?>
          <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active) ?>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($canSeeHr): ?>
        <details class="rounded-2xl" <?= $hrOpen ? 'open' : '' ?>>
          <summary class="cursor-pointer list-none rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 flex items-center justify-between">
            <span>HR</span>
            <span class="text-slate-400 text-xs">▾</span>
          </summary>
          <div class="mt-2 ml-2 grid gap-2">
            <?php foreach ($hrItems as $it): ?>
              <?php if (admin_can($user, (string)$it['perm'])): ?>
                <?= admin_nav_child_item((string)$it['href'], (string)$it['label'], (string)$active) ?>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <?php foreach ($settingsItems as $it): ?>
        <?php if (admin_can($user, (string)$it['perm'])): ?>
          <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active) ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <div class="mt-4 border-t border-slate-200 pt-4 flex items-center justify-between">
      <span class="text-[11px] text-slate-400">UTC</span>
    </div>
  </div>
</aside>
