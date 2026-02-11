<?php
declare(strict_types=1);

// expects: $user, $active (string)
$active = $active ?? '';

/**
 * Inline SVG icons (outline style). Dependency-free (no icon libraries).
 */
function admin_icon(string $name, string $classes = 'h-5 w-5'): string {
  $open = '<svg class="' . h($classes) . '" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">';
  $close = '</svg>';

  $path = '';
  switch ($name) {
    case 'dashboard':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h7v7h-7v-7Z"/>';
      break;
    case 'hr':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M18 20.25c0-2.485-2.686-4.5-6-4.5s-6 2.015-6 4.5M12 12.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z"/>';
      break;
    case 'applicants':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M8.25 4.5h7.5A2.25 2.25 0 0 1 18 6.75v10.5A2.25 2.25 0 0 1 15.75 19.5h-7.5A2.25 2.25 0 0 1 6 17.25V6.75A2.25 2.25 0 0 1 8.25 4.5Z"/>';
      break;
    case 'staff':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M15 7.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM4.5 19.5a7.5 7.5 0 0 1 15 0"/>';
      break;
    case 'rota':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4.5 9.5h15M6.75 5.25h10.5A2.25 2.25 0 0 1 19.5 7.5v10.5A2.25 2.25 0 0 1 17.25 20.25H6.75A2.25 2.25 0 0 1 4.5 18V7.5A2.25 2.25 0 0 1 6.75 5.25Z"/>';
      break;
    case 'timesheets':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5.25h6M9 9h6M9 12.75h6M7.5 3.75h9A2.25 2.25 0 0 1 18.75 6v12A2.25 2.25 0 0 1 16.5 20.25h-9A2.25 2.25 0 0 1 5.25 18V6A2.25 2.25 0 0 1 7.5 3.75Z"/>';
      break;
    case 'approvals':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>';
      break;
    case 'payroll':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-9h6M4.5 7.5h15A2.25 2.25 0 0 1 21.75 9.75v4.5A2.25 2.25 0 0 1 19.5 16.5h-15A2.25 2.25 0 0 1 2.25 14.25v-4.5A2.25 2.25 0 0 1 4.5 7.5Z"/>';
      break;
    case 'shiftgrid':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6h16.5M3.75 10.5h16.5M3.75 15h16.5M6 3.75v16.5M12 3.75v16.5M18 3.75v16.5"/>';
      break;
    case 'report':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7.5 15.75V10.5M12 15.75V6.75M16.5 15.75V12"/>';
      break;
    case 'kiosk':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M7.5 2.25h9A2.25 2.25 0 0 1 18.75 4.5v15A2.25 2.25 0 0 1 16.5 21.75h-9A2.25 2.25 0 0 1 5.25 19.5v-15A2.25 2.25 0 0 1 7.5 2.25ZM12 18.75h.008v.008H12v-.008Z"/>';
      break;
    case 'kioskids':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M15 7.5V6A3 3 0 0 0 9 6v1.5m8.25 0h.75A2.25 2.25 0 0 1 20.25 9.75v8.25A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V9.75A2.25 2.25 0 0 1 6 7.5h.75m10.5 0h-10.5"/>';
      break;
    case 'punch':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>';
      break;
    case 'settings':
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6.75h-6m6 0a2.25 2.25 0 1 0 0-4.5m0 4.5a2.25 2.25 0 1 1 0-4.5m3 15h6m-6 0a2.25 2.25 0 1 1 0-4.5m0 4.5a2.25 2.25 0 1 0 0-4.5M6.75 12h10.5m-10.5 0a2.25 2.25 0 1 1 0-4.5m0 4.5a2.25 2.25 0 1 0 0-4.5"/>';
      break;
    default:
      $path = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/>';
      break;
  }

  return $open . $path . $close;
}

function admin_nav_item(string $href, string $label, string $active, string $icon = '', bool $isSub = false): string {
  $is = ($href === $active);

  // Visual difference between top links and sub-links
  $textSize = $isSub ? 'text-[13px]' : 'text-sm';
  $padY = $isSub ? 'py-1.5' : 'py-2';

  // Icon wrapper: use colour only; sizing comes from the SVG itself.
  $iconHtml = $icon !== ''
    ? '<span class="' . ($isSub ? 'text-slate-400' : 'text-slate-500') . ' group-hover:text-slate-800 ' . ($is ? 'text-indigo-700' : '') . '">' . $icon . '</span>'
    : '';

  if ($is) {
    return '<a href="' . h($href) . '" class="group flex items-center gap-3 px-3 ' . $padY . ' ' . $textSize . ' font-semibold border-l-4 border-indigo-600 bg-indigo-50 text-slate-900 transition-colors">'
      . $iconHtml . '<span class="flex-1">' . h($label) . '</span></a>';
  }

  return '<a href="' . h($href) . '" class="group flex items-center gap-3 px-3 ' . $padY . ' ' . $textSize . ' ' . ($isSub ? 'font-medium' : 'font-medium') . ' border-l-4 border-transparent text-slate-700 hover:bg-indigo-50/40 hover:text-slate-900 hover:border-slate-300 transition-colors">'
    . $iconHtml . '<span class="flex-1">' . h($label) . '</span></a>';
}


function admin_nav_item_soon(string $href, string $label, string $active, string $icon = ''): string {
  $is = ($href === $active);
  $iconHtml = $icon !== ''
    ? '<span class="text-slate-400 ' . ($is ? 'text-indigo-700' : '') . '">' . $icon . '</span>'
    : '';

  if ($is) {
    return '<a href="' . h($href) . '" class="group flex items-center gap-3 px-3 py-2 text-sm font-semibold border-l-2 border-indigo-600 bg-indigo-50 text-slate-900 transition-colors">'
      . $iconHtml . '<span class="flex-1">' . h($label) . '</span><span class="text-[11px] font-semibold text-slate-500 px-2 py-0.5">Coming soon</span></a>';
  }

  return '<a href="' . h($href) . '" class="group flex items-center gap-3 px-3 py-2 text-sm font-medium border-l-2 border-transparent text-slate-500 hover:bg-indigo-50/30 hover:text-slate-700 hover:border-slate-200 transition-colors">'
    . $iconHtml . '<span class="flex-1">' . h($label) . '</span><span class="text-[11px] font-semibold text-slate-500 px-2 py-0.5">Coming soon</span></a>';
}

function admin_nav_group_summary(string $label, bool $isActive, string $icon = ''): string {
  // + / - icons are toggled via CSS using details[open]
  $iconHtml = $icon !== '' ? '<span class="text-slate-500">' . $icon . '</span>' : '';
  $classes = 'cursor-pointer list-none px-3 py-2 text-sm font-semibold flex items-center gap-3 transition-colors';
  if ($isActive) {
    $classes .= ' bg-indigo-50 text-slate-900 border-l-2 border-indigo-600';
  } else {
    $classes .= ' text-slate-900 hover:bg-indigo-50/40 border-l-2 border-transparent';
  }
  return '<summary class="' . $classes . '">' 
    . $iconHtml
    . '<span class="flex-1">' . h($label) . '</span>'
    . '<span class="nav-toggle text-slate-500 text-lg leading-none select-none">'
    . '<span class="nav-plus">+</span><span class="nav-minus">−</span>'
    . '</span>'
    . '</summary>';
}

function admin_can_any(array $items, array $user): bool {
  foreach ($items as $it) {
    if (admin_can($user, (string)$it['perm'])) {
      return true;
    }
  }
  return false;
}

// --- Sidebar structure (Feb 2026) ---
// Locked order requested:
//  Dashboard, HR, Rota, Timesheets, Payroll, Kiosk, Other links, Settings (last)

$dashboardItem = ['href' => admin_url('index.php'), 'label' => 'Dashboard', 'perm' => 'view_dashboard', 'icon' => 'dashboard'];

$hrItems = [
  ['href' => admin_url('hr-applications.php'), 'label' => 'Applicants', 'perm' => 'view_hr_applications', 'icon' => 'applicants'],
  ['href' => admin_url('hr-staff.php'), 'label' => 'Staff', 'perm' => 'manage_staff', 'icon' => 'staff'],
];

$rotaItem = ['href' => admin_url('rota.php'), 'label' => 'Rota', 'perm' => 'view_dashboard', 'soon' => true, 'icon' => 'rota'];

$timesheetItems = [
  ['href' => admin_url('shift-editor.php'), 'label' => 'Approvals', 'perm' => 'approve_shifts', 'icon' => 'approvals'],
];

$payrollItems = [
  ['href' => admin_url('shifts.php'), 'label' => 'Shift Grid', 'perm' => 'view_shifts', 'icon' => 'shiftgrid'],
  ['href' => admin_url('payroll-calendar-employee.php'), 'label' => 'Payroll Monthly Report', 'perm' => 'view_payroll', 'icon' => 'report'],
];

$kioskItems = [
  ['href' => admin_url('kiosk-ids.php'), 'label' => 'Kiosk IDs', 'perm' => 'view_employees', 'icon' => 'kioskids'],
  ['href' => admin_url('punch-details.php'), 'label' => 'Punch Details', 'perm' => 'view_punches', 'icon' => 'punch'],
];

// Keep other operational links visible for now (between Kiosk and Settings). We'll reorganise later.
$otherItems = [
  ['href' => admin_url('departments.php'), 'label' => 'Departments', 'perm' => 'manage_settings_basic', 'icon' => 'hr'],
  ['href' => admin_url('break-tiers.php'), 'label' => 'Break Tiers', 'perm' => 'manage_settings_basic', 'icon' => 'timesheets'],
  ['href' => admin_url('permissions.php'), 'label' => 'Permissions', 'perm' => 'manage_users', 'icon' => 'settings'],
];

$settingsItem = ['href' => admin_url('settings.php'), 'label' => 'Settings', 'perm' => 'manage_settings_basic', 'icon' => 'settings'];

// Highlight group header if any child is active (but do NOT auto-open).
$hrActive = false;
foreach ($hrItems as $it) {
  if ((string)$it['href'] === (string)$active) { $hrActive = true; break; }
}
$timesheetsActive = false;
foreach ($timesheetItems as $it) {
  if ((string)$it['href'] === (string)$active) { $timesheetsActive = true; break; }
}
$payrollActive = false;
foreach ($payrollItems as $it) {
  if ((string)$it['href'] === (string)$active) { $payrollActive = true; break; }
}
$kioskActive = false;
foreach ($kioskItems as $it) {
  if ((string)$it['href'] === (string)$active) { $kioskActive = true; break; }
}

?>

<style>
  /* Toggle plus/minus using details[open] */
  details .nav-minus { display:none; }
  details[open] .nav-plus { display:none; }
  details[open] .nav-minus { display:inline; }

  /* Remove default marker */
  summary::-webkit-details-marker { display:none; }
</style>

<aside class="w-full lg:w-72 shrink-0 bg-white flex flex-col h-dvh overflow-hidden lg:sticky lg:top-0">

  <!-- Brand / Top -->
  <div class="px-5 py-4">
    <div class="flex items-start justify-between gap-3">
      <div class="leading-tight">
        <div class="text-sm font-semibold tracking-tight text-slate-900">SmartCare</div>
        <div class="text-xs text-slate-500">Dashboard</div>
      </div>
      <a href="<?= h(admin_url('logout.php')) ?>" class="px-3 py-2 text-xs font-semibold text-slate-600 hover:text-slate-900">Logout</a>
    </div>
  </div>

  <!-- Nav (scrollable) -->
  <nav class="flex-1 min-h-0 overflow-y-auto px-3 py-3 space-y-1">

    <?php if (admin_can($user, (string)$dashboardItem['perm'])): ?>
      <?= admin_nav_item((string)$dashboardItem['href'], (string)$dashboardItem['label'], (string)$active, admin_icon((string)$dashboardItem['icon'])) ?>
    <?php endif; ?>

    <?php if (admin_can_any($hrItems, $user)): ?>
      <details id="nav-hr" class="">
        <?= admin_nav_group_summary('HR', $hrActive, admin_icon('hr')) ?>
        <div class="mt-1 ml-8 space-y-1 pb-2">
          <?php foreach ($hrItems as $it): ?>
            <?php if (admin_can($user, (string)$it['perm'])): ?>
              <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active, admin_icon((string)$it['icon'], 'h-4 w-4'), true) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

    <?php if (admin_can($user, (string)$rotaItem['perm'])): ?>
      <?php if (!empty($rotaItem['soon'])): ?>
        <?= admin_nav_item_soon((string)$rotaItem['href'], (string)$rotaItem['label'], (string)$active, admin_icon((string)$rotaItem['icon'])) ?>
      <?php else: ?>
        <?= admin_nav_item((string)$rotaItem['href'], (string)$rotaItem['label'], (string)$active, admin_icon((string)$rotaItem['icon'])) ?>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (admin_can_any($timesheetItems, $user)): ?>
      <details id="nav-timesheets" class="">
        <?= admin_nav_group_summary('Timesheets', $timesheetsActive, admin_icon('timesheets')) ?>
        <div class="mt-1 ml-8 space-y-1 pb-2">
          <?php foreach ($timesheetItems as $it): ?>
            <?php if (admin_can($user, (string)$it['perm'])): ?>
              <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active, admin_icon((string)$it['icon'], 'h-4 w-4'), true) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

    <?php if (admin_can_any($payrollItems, $user)): ?>
      <details id="nav-payroll" class="">
        <?= admin_nav_group_summary('Payroll', $payrollActive, admin_icon('payroll')) ?>
        <div class="mt-1 ml-8 space-y-1 pb-2">
          <?php foreach ($payrollItems as $it): ?>
            <?php if (admin_can($user, (string)$it['perm'])): ?>
              <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active, admin_icon((string)$it['icon'], 'h-4 w-4'), true) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

    <?php if (admin_can_any($kioskItems, $user)): ?>
      <details id="nav-kiosk" class="">
        <?= admin_nav_group_summary('Kiosk', $kioskActive, admin_icon('kiosk')) ?>
        <div class="mt-1 ml-8 space-y-1 pb-2">
          <?php foreach ($kioskItems as $it): ?>
            <?php if (admin_can($user, (string)$it['perm'])): ?>
              <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active, admin_icon((string)$it['icon'], 'h-4 w-4'), true) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

    <?php if (admin_can_any($otherItems, $user)): ?>
      <div class="pt-4"></div>

      <div class="pt-2">
        <div class="px-3 text-[11px] uppercase tracking-wide text-slate-500">Other</div>
        <div class="mt-2 space-y-1">
          <?php foreach ($otherItems as $it): ?>
            <?php if (admin_can($user, (string)$it['perm'])): ?>
              <?= admin_nav_item((string)$it['href'], (string)$it['label'], (string)$active, admin_icon((string)$it['icon'], 'h-4 w-4'), true) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (admin_can($user, (string)$settingsItem['perm'])): ?>
      <div class="pt-3">
        <?= admin_nav_item((string)$settingsItem['href'], (string)$settingsItem['label'], (string)$active, admin_icon((string)$settingsItem['icon'])) ?>
      </div>
    <?php endif; ?>

  </nav>

  <!-- Footer -->
  <div class="px-5 py-4">
    <div class="text-xs text-slate-600">
      <div>Signed in as <span class="font-semibold text-slate-900"><?= h((string)($user['username'] ?? 'acd')) ?></span></div>
      <div class="mt-1 text-slate-500">Role: <?= h((string)($user['role'] ?? 'superadmin')) ?></div>
    </div>
  </div>

</aside>

<script>
  // Persist nav group open/closed state so groups only change when you click +/−.
  (function () {
    const key = 'smartcare_dashboard_nav_open_v1';
    let state = {};
    try { state = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) { state = {}; }

    const details = document.querySelectorAll('aside details[id^="nav-"]');
    details.forEach((d) => {
      if (state[d.id] === true) d.open = true;
      d.addEventListener('toggle', () => {
        state[d.id] = d.open;
        try { localStorage.setItem(key, JSON.stringify(state)); } catch (e) {}
      });
    });
  })();
</script>
