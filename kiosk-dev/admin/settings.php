<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

// Anyone who can see Settings must have at least basic settings permission.
admin_require_perm($user, 'manage_settings_basic');

// ---------------------------------------------------------------------
// Settings access model
// ---------------------------------------------------------------------
// Basic settings are controlled via an allow-list per role.
// High-level settings (kiosk/system) are superadmin-only.

$role = (string)($user['role'] ?? '');

$basicAllow = [
  // You can tweak these lists later without touching DB
  'manager' => [
    // Payroll rounding controls (keeps originals untouched)
    'rounding_enabled',
    'round_increment_minutes',
    'round_grace_minutes',
  ],
  'payroll' => [
    // Payroll users can view settings page only if you want; leave empty to hide via sidebar
  ],
  'admin' => [
    'rounding_enabled',
    'round_increment_minutes',
    'round_grace_minutes',
    // Payroll (carehome rules)
    'payroll_timezone',
    'default_break_minutes',
    'night_shift_threshold_percent',
    // Night premium window for "Night hours" bucket
    'night_premium_enabled',
    'night_premium_start',
    'night_premium_end',
    'overtime_default_multiplier',
    'weekend_premium_enabled',
    'weekend_days',
    'weekend_rate_multiplier',
    'bank_holiday_enabled',
    'bank_holiday_paid',
    'bank_holiday_paid_cap_hours',
    'bank_holiday_rate_multiplier',
    'payroll_overtime_priority',
  ],
  'superadmin' => [
    'rounding_enabled',
    'round_increment_minutes',
    'round_grace_minutes',
    // Payroll (carehome rules)
    'payroll_week_starts_on',
    'payroll_timezone',
    'default_break_minutes',
    'night_shift_threshold_percent',
    'night_premium_enabled',
    'night_premium_start',
    'night_premium_end',
    'overtime_default_multiplier',
    'weekend_premium_enabled',
    'weekend_days',
    'weekend_rate_multiplier',
    'bank_holiday_enabled',
    'bank_holiday_paid',
    'bank_holiday_paid_cap_hours',
    'bank_holiday_rate_multiplier',
    'payroll_overtime_priority',
  ],
];
$stripCarehomePayrollKeys = [
  'night_shift_threshold_percent',
  'night_premium_enabled',
  'night_premium_start',
  'night_premium_end',
  'overtime_default_multiplier',
  'weekend_premium_enabled',
  'weekend_days',
  'weekend_rate_multiplier',
  'bank_holiday_enabled',
  'bank_holiday_paid',
  'bank_holiday_paid_cap_hours',
  'bank_holiday_rate_multiplier',
  'payroll_overtime_priority',
];

foreach ($basicAllow as $rk => &$list) {
  if (!is_array($list)) continue;
  $list = array_values(array_filter($list, function($k) use ($stripCarehomePayrollKeys) {
    return !in_array($k, $stripCarehomePayrollKeys, true);
  }));
}
unset($list);



$highLevelKeys = [
  // Admin pairing / device authorisation is high-level
  // File-system paths should be superadmin-only.
  'uploads_base_path',
  'admin_pairing_mode',
  'admin_pairing_mode_until',
  'admin_pairing_code',
  'admin_pairing_version',
];

$canHigh = admin_can($user, 'manage_settings_high');

$allowedBasic = $basicAllow[strtolower($role)] ?? [];

// Read settings helpers
$vals = [
  'rounding_enabled' => admin_setting_bool($pdo, 'rounding_enabled', true),
  'round_increment_minutes' => admin_setting_int($pdo, 'round_increment_minutes', 15),
  'round_grace_minutes' => admin_setting_int($pdo, 'round_grace_minutes', 5),

  // system lock
  'app_initialized' => admin_setting_bool($pdo, 'app_initialized', true),

  // payroll (admin/superadmin)
  'payroll_week_starts_on' => admin_setting_str($pdo, 'payroll_week_starts_on', 'MONDAY'),
  'payroll_timezone' => admin_setting_str($pdo, 'payroll_timezone', 'Europe/London'),
  'default_break_minutes' => admin_setting_int($pdo, 'default_break_minutes', 30),
  'night_shift_threshold_percent' => admin_setting_int($pdo, 'night_shift_threshold_percent', 50),
  'night_premium_enabled' => admin_setting_bool($pdo, 'night_premium_enabled', true),
  'night_premium_start' => admin_setting_str($pdo, 'night_premium_start', '22:00:00'),
  'night_premium_end' => admin_setting_str($pdo, 'night_premium_end', '06:00:00'),
  'overtime_default_multiplier' => admin_setting_str($pdo, 'overtime_default_multiplier', '1.5'),
  'weekend_premium_enabled' => admin_setting_bool($pdo, 'weekend_premium_enabled', false),
  'weekend_days' => admin_setting_str($pdo, 'weekend_days', '["SAT","SUN"]'),
  'weekend_rate_multiplier' => admin_setting_str($pdo, 'weekend_rate_multiplier', '1.25'),
  'bank_holiday_enabled' => admin_setting_bool($pdo, 'bank_holiday_enabled', true),
  'bank_holiday_paid' => admin_setting_bool($pdo, 'bank_holiday_paid', true),
  'bank_holiday_paid_cap_hours' => admin_setting_int($pdo, 'bank_holiday_paid_cap_hours', 12),
  'bank_holiday_rate_multiplier' => admin_setting_str($pdo, 'bank_holiday_rate_multiplier', '1.5'),
  'payroll_overtime_priority' => admin_setting_str($pdo, 'payroll_overtime_priority', 'PREMIUMS_THEN_OVERTIME'),

  // high-level (superadmin only)
  'admin_pairing_mode' => admin_setting_bool($pdo, 'admin_pairing_mode', false),
  'admin_pairing_mode_until' => admin_setting_str($pdo, 'admin_pairing_mode_until', ''),
  'admin_pairing_code' => admin_setting_str($pdo, 'admin_pairing_code', ''),
  'admin_pairing_version' => admin_setting_int($pdo, 'admin_pairing_version', 1),

  // uploads
  // This is a base directory used to resolve kiosk upload paths (e.g., punch photos).
  // Can be inside or outside public folder.
  'uploads_base_path' => admin_setting_str($pdo, 'uploads_base_path', ''),
];

$success = '';
$err = '';

// Week start is setup-only once app is initialized
$weekLocked = (bool)($vals['app_initialized'] ?? true);

// For superadmin: load complete kiosk_settings so it can be managed in an accordion.
$allSettings = [];
if ($canHigh) {
  try {
    $st = $pdo->query("SELECT `key`,`value` FROM kiosk_settings ORDER BY `key` ASC");
    $allSettings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $allSettings = [];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);

  try {
    // Basic: rounding
    if (in_array('rounding_enabled', $allowedBasic, true)) {
      admin_set_setting($pdo, 'rounding_enabled', isset($_POST['rounding_enabled']) ? '1' : '0');
    }
    if (in_array('round_increment_minutes', $allowedBasic, true)) {
      admin_set_setting($pdo, 'round_increment_minutes', (string)max(1, (int)($_POST['round_increment_minutes'] ?? 15)));
    }
    if (in_array('round_grace_minutes', $allowedBasic, true)) {
      admin_set_setting($pdo, 'round_grace_minutes', (string)max(0, (int)($_POST['round_grace_minutes'] ?? 5)));
    }

    // Payroll (admin/superadmin)
    // Week start is setup-only. Once app_initialized=1, it cannot be changed.
    $weekLocked = (bool)($vals['app_initialized'] ?? true);
    if (!$weekLocked && $role === 'superadmin' && in_array('payroll_week_starts_on', $allowedBasic, true)) {
      admin_set_setting($pdo, 'payroll_week_starts_on', strtoupper(trim((string)($_POST['payroll_week_starts_on'] ?? 'MONDAY'))));
    }
    if (in_array('payroll_timezone', $allowedBasic, true)) {
      admin_set_setting($pdo, 'payroll_timezone', trim((string)($_POST['payroll_timezone'] ?? 'Europe/London')));
    }
    if (in_array('default_break_minutes', $allowedBasic, true)) {
      admin_set_setting($pdo, 'default_break_minutes', (string)max(0, (int)($_POST['default_break_minutes'] ?? 30)));
    }
    if (in_array('night_shift_threshold_percent', $allowedBasic, true)) {
      admin_set_setting($pdo, 'night_shift_threshold_percent', (string)max(0, min(100, (int)($_POST['night_shift_threshold_percent'] ?? 50))));
    }
    if (in_array('night_premium_enabled', $allowedBasic, true)) {
      admin_set_setting($pdo, 'night_premium_enabled', isset($_POST['night_premium_enabled']) ? '1' : '0');
    }
    if (in_array('night_premium_start', $allowedBasic, true)) {
      admin_set_setting($pdo, 'night_premium_start', trim((string)($_POST['night_premium_start'] ?? '22:00:00')));
    }
    if (in_array('night_premium_end', $allowedBasic, true)) {
      admin_set_setting($pdo, 'night_premium_end', trim((string)($_POST['night_premium_end'] ?? '06:00:00')));
    }
    if (in_array('overtime_default_multiplier', $allowedBasic, true)) {
      admin_set_setting($pdo, 'overtime_default_multiplier', trim((string)($_POST['overtime_default_multiplier'] ?? '1.5')));
    }
    if (in_array('weekend_premium_enabled', $allowedBasic, true)) {
      admin_set_setting($pdo, 'weekend_premium_enabled', isset($_POST['weekend_premium_enabled']) ? '1' : '0');
    }
    if (in_array('weekend_days', $allowedBasic, true)) {
      admin_set_setting($pdo, 'weekend_days', trim((string)($_POST['weekend_days'] ?? '["SAT","SUN"]')));
    }
    if (in_array('weekend_rate_multiplier', $allowedBasic, true)) {
      admin_set_setting($pdo, 'weekend_rate_multiplier', trim((string)($_POST['weekend_rate_multiplier'] ?? '1.25')));
    }
    if (in_array('bank_holiday_enabled', $allowedBasic, true)) {
      admin_set_setting($pdo, 'bank_holiday_enabled', isset($_POST['bank_holiday_enabled']) ? '1' : '0');
    }
    if (in_array('bank_holiday_paid', $allowedBasic, true)) {
      admin_set_setting($pdo, 'bank_holiday_paid', isset($_POST['bank_holiday_paid']) ? '1' : '0');
    }
    if (in_array('bank_holiday_paid_cap_hours', $allowedBasic, true)) {
      admin_set_setting($pdo, 'bank_holiday_paid_cap_hours', (string)max(0, (int)($_POST['bank_holiday_paid_cap_hours'] ?? 12)));
    }
    if (in_array('bank_holiday_rate_multiplier', $allowedBasic, true)) {
      admin_set_setting($pdo, 'bank_holiday_rate_multiplier', trim((string)($_POST['bank_holiday_rate_multiplier'] ?? '1.5')));
    }
    if (in_array('payroll_overtime_priority', $allowedBasic, true)) {
      admin_set_setting($pdo, 'payroll_overtime_priority', strtoupper(trim((string)($_POST['payroll_overtime_priority'] ?? 'PREMIUMS_THEN_OVERTIME'))));
    }

    // High-level: admin pairing (superadmin only)
    if ($canHigh) {
      // Global uploads base path (superadmin)
      if (isset($_POST['uploads_base_path'])) {
        admin_set_setting($pdo, 'uploads_base_path', trim((string)($_POST['uploads_base_path'] ?? '')));
      }

      admin_set_setting($pdo, 'admin_pairing_mode', isset($_POST['admin_pairing_mode']) ? '1' : '0');
      admin_set_setting($pdo, 'admin_pairing_mode_until', trim((string)($_POST['admin_pairing_mode_until'] ?? '')));
      $code = trim((string)($_POST['admin_pairing_code'] ?? ''));
      if ($code !== '') admin_set_setting($pdo, 'admin_pairing_code', $code);

      // Superadmin "All settings" accordion save
      if (isset($_POST['all_settings']) && is_array($_POST['all_settings'])) {
        foreach ($_POST['all_settings'] as $k => $v) {
          $key = trim((string)$k);
          if ($key === '') continue;
          // Do not allow empty pairing code to overwrite existing one
          if ($key === 'admin_pairing_code' && trim((string)$v) === '') continue;
          admin_set_setting($pdo, $key, (string)$v);
        }
      }
    }

    $success = 'Saved';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }

  // Refresh values
  $vals['rounding_enabled'] = admin_setting_bool($pdo, 'rounding_enabled', true);
  $vals['round_increment_minutes'] = admin_setting_int($pdo, 'round_increment_minutes', 15);
  $vals['round_grace_minutes'] = admin_setting_int($pdo, 'round_grace_minutes', 5);
  $vals['payroll_week_starts_on'] = admin_setting_str($pdo, 'payroll_week_starts_on', 'MONDAY');
  $vals['payroll_timezone'] = admin_setting_str($pdo, 'payroll_timezone', 'Europe/London');
  $vals['night_shift_threshold_percent'] = admin_setting_int($pdo, 'night_shift_threshold_percent', 50);
  $vals['night_premium_enabled'] = admin_setting_bool($pdo, 'night_premium_enabled', true);
  $vals['night_premium_start'] = admin_setting_str($pdo, 'night_premium_start', '22:00:00');
  $vals['night_premium_end'] = admin_setting_str($pdo, 'night_premium_end', '06:00:00');
  $vals['overtime_default_multiplier'] = admin_setting_str($pdo, 'overtime_default_multiplier', '1.5');
  $vals['weekend_premium_enabled'] = admin_setting_bool($pdo, 'weekend_premium_enabled', false);
  $vals['weekend_days'] = admin_setting_str($pdo, 'weekend_days', '["SAT","SUN"]');
  $vals['weekend_rate_multiplier'] = admin_setting_str($pdo, 'weekend_rate_multiplier', '1.25');
  $vals['bank_holiday_enabled'] = admin_setting_bool($pdo, 'bank_holiday_enabled', true);
  $vals['bank_holiday_paid'] = admin_setting_bool($pdo, 'bank_holiday_paid', true);
  $vals['bank_holiday_paid_cap_hours'] = admin_setting_int($pdo, 'bank_holiday_paid_cap_hours', 12);
  $vals['bank_holiday_rate_multiplier'] = admin_setting_str($pdo, 'bank_holiday_rate_multiplier', '1.5');
  $vals['payroll_overtime_priority'] = admin_setting_str($pdo, 'payroll_overtime_priority', 'PREMIUMS_THEN_OVERTIME');
  $vals['admin_pairing_mode'] = admin_setting_bool($pdo, 'admin_pairing_mode', false);
  $vals['admin_pairing_mode_until'] = admin_setting_str($pdo, 'admin_pairing_mode_until', '');
  $vals['admin_pairing_code'] = admin_setting_str($pdo, 'admin_pairing_code', '');
  $vals['admin_pairing_version'] = admin_setting_int($pdo, 'admin_pairing_version', 1);
  $vals['uploads_base_path'] = admin_setting_str($pdo, 'uploads_base_path', '');
}

admin_page_start($pdo, 'Settings');
$active = admin_url('settings.php');

?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <h1 class="text-2xl font-semibold">Settings</h1>
            <p class="mt-2 text-sm text-white/70">These settings live in <code class="px-2 py-1 rounded-xl bg-white/10">kiosk_settings</code>. Access is controlled by an allow-list per role.</p>
          </header>

          <?php if ($success): ?>
            <div class="mt-4 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 text-sm text-emerald-100"><?= h($success) ?></div>
          <?php endif; ?>

          <?php if ($err): ?>
            <div class="mt-4 rounded-2xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-100"><?= h($err) ?></div>
          <?php endif; ?>

          <form method="post" class="mt-5 space-y-5">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>

            <?php if (count($allowedBasic) > 0): ?>
              <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-lg font-semibold">Basic settings</h2>
                <p class="mt-1 text-sm text-white/70">These can be enabled per role using the allow-list in <code class="px-2 py-1 rounded-xl bg-white/10">admin/settings.php</code>.</p>

                <?php if (in_array('rounding_enabled', $allowedBasic, true) || in_array('round_increment_minutes', $allowedBasic, true) || in_array('round_grace_minutes', $allowedBasic, true)): ?>
                  <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h3 class="text-sm font-semibold">Rounding (payroll calculations)</h3>
                    <p class="mt-1 text-sm text-white/70">Rounding is shown as separate “Rounded In/Out” values for payroll. Original clock times are never changed.</p>

                    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                      <?php if (in_array('rounding_enabled', $allowedBasic, true)): ?>
                        <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 md:col-span-1">
                          <input type="checkbox" name="rounding_enabled" class="h-4 w-4 rounded" <?= $vals['rounding_enabled'] ? 'checked' : '' ?>/>
                          <div>
                            <div class="text-sm font-semibold">Enable rounding</div>
                            <div class="text-xs text-white/60">Used in payroll views.</div>
                          </div>
                        </label>
                      <?php endif; ?>

                      <?php if (in_array('round_increment_minutes', $allowedBasic, true)): ?>
                        <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                          <div class="text-xs uppercase tracking-widest text-white/50">Increment (minutes)</div>
                          <input type="number" min="1" step="1" name="round_increment_minutes" value="<?= h((string)$vals['round_increment_minutes']) ?>"
                            class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                          <div class="mt-2 text-xs text-white/50">Example: 15 ⇒ 00/15/30/45.</div>
                        </label>
                      <?php endif; ?>

                      <?php if (in_array('round_grace_minutes', $allowedBasic, true)): ?>
                        <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                          <div class="text-xs uppercase tracking-widest text-white/50">Grace (minutes)</div>
                          <input type="number" min="0" step="1" name="round_grace_minutes" value="<?= h((string)$vals['round_grace_minutes']) ?>"
                            class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                          <div class="mt-2 text-xs text-white/50">Snap only within this window.</div>
                        </label>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </section>
            <?php else: ?>
              <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-lg font-semibold">Basic settings</h2>
                <p class="mt-2 text-sm text-white/70">No basic settings are enabled for your role.</p>
              </section>
            <?php endif; ?>

            <?php
              $payrollKeys = [
                'payroll_week_starts_on','payroll_timezone','default_break_minutes','night_shift_threshold_percent','overtime_default_multiplier',
                'weekend_premium_enabled','weekend_days','weekend_rate_multiplier',
                'bank_holiday_enabled','bank_holiday_paid','bank_holiday_rate_multiplier',
                'payroll_overtime_priority',
              ];
$payrollKeys = array_values(array_filter($payrollKeys, function($k) use ($stripCarehomePayrollKeys) {
  return !in_array($k, $stripCarehomePayrollKeys, true);
}));


              $canSeePayroll = false;
              foreach ($payrollKeys as $k) { if (in_array($k, $allowedBasic, true)) { $canSeePayroll = true; break; } }
            ?>

            <?php if ($canSeePayroll): ?>
              <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-lg font-semibold">Payroll settings (admin)</h2>
                <p class="mt-1 text-sm text-white/70">These affect monthly payroll calculations. Weekend & bank holiday premiums are applied per-day until midnight in the configured timezone.</p>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                  <?php if (in_array('payroll_week_starts_on', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Week starts on</div>
                      <select name="payroll_week_starts_on" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" <?= $weekLocked ? "disabled" : "" ?>>
                        <?php foreach (['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'] as $d): ?>
                          <option value="<?= h($d) ?>" <?= strtoupper((string)$vals['payroll_week_starts_on'])===$d ? 'selected' : '' ?>><?= h($d) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="mt-2 text-xs text-white/50"><?php if ($weekLocked): ?>Locked after initial setup<?php else: ?>Set once at initial setup<?php endif; ?></div>
                    </label>
                  <?php endif; ?>

                  <?php if (in_array('payroll_timezone', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Payroll timezone</div>
                      <input name="payroll_timezone" value="<?= h((string)$vals['payroll_timezone']) ?>" placeholder="Europe/London"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                      <div class="mt-2 text-xs text-white/50">Used for midnight/day boundaries for premiums.</div>
                    </label>
                  <?php endif; ?>

                  <?php if (in_array('default_break_minutes', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Default break minutes</div>
                      <input type="number" min="0" step="1" name="default_break_minutes" value="<?= h((string)$vals['default_break_minutes']) ?>"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                      <div class="mt-2 text-xs text-white/50">Fallback deducted when no break rule matches.</div>
                    </label>
                  <?php endif; ?>



                  <?php if (in_array('night_shift_threshold_percent', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Night threshold (%)</div>
                      <input type="number" min="0" max="100" step="1" name="night_shift_threshold_percent" value="<?= h((string)$vals['night_shift_threshold_percent']) ?>"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    </label>
                  <?php endif; ?>
                </div>

                <?php if (in_array('night_premium_enabled', $allowedBasic, true) || in_array('night_premium_start', $allowedBasic, true) || in_array('night_premium_end', $allowedBasic, true)): ?>
                  <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php if (in_array('night_premium_enabled', $allowedBasic, true)): ?>
                      <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                        <input type="checkbox" name="night_premium_enabled" class="h-4 w-4 rounded" <?= $vals['night_premium_enabled'] ? 'checked' : '' ?> />
                        <div>
                          <div class="text-sm font-semibold">Night hours enabled</div>
                          <div class="text-xs text-white/60">Uses the time window below for the Night hours bucket.</div>
                        </div>
                      </label>
                    <?php endif; ?>

                    <?php if (in_array('night_premium_start', $allowedBasic, true)): ?>
                      <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-widest text-white/50">Night starts</div>
                        <input name="night_premium_start" value="<?= h((string)$vals['night_premium_start']) ?>" placeholder="22:00:00"
                          class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                        <div class="mt-2 text-xs text-white/50">Format HH:MM or HH:MM:SS (e.g. 22:00).</div>
                      </label>
                    <?php endif; ?>

                    <?php if (in_array('night_premium_end', $allowedBasic, true)): ?>
                      <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="text-xs uppercase tracking-widest text-white/50">Night ends</div>
                        <input name="night_premium_end" value="<?= h((string)$vals['night_premium_end']) ?>" placeholder="06:00:00"
                          class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                        <div class="mt-2 text-xs text-white/50">If end is earlier than start, it rolls into the next day.</div>
                      </label>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                  <?php if (in_array('overtime_default_multiplier', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Overtime multiplier</div>
                      <input name="overtime_default_multiplier" value="<?= h((string)$vals['overtime_default_multiplier']) ?>" placeholder="1.5"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    </label>
                  <?php endif; ?>

                  <?php if (in_array('payroll_overtime_priority', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">OT vs premium rule</div>
                      <select name="payroll_overtime_priority" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                        <?php foreach (['PREMIUMS_THEN_OVERTIME','OVERTIME_THEN_PREMIUMS','HIGHEST_WINS'] as $r): ?>
                          <option value="<?= h($r) ?>" <?= strtoupper((string)$vals['payroll_overtime_priority'])===$r ? 'selected' : '' ?>><?= h($r) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="mt-2 text-xs text-white/50">Currently implemented: PREMIUMS_THEN_OVERTIME.</div>
                    </label>
                  <?php endif; ?>
                </div>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                  <?php if (in_array('weekend_premium_enabled', $allowedBasic, true)): ?>
                    <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                      <input type="checkbox" name="weekend_premium_enabled" class="h-4 w-4 rounded" <?= $vals['weekend_premium_enabled'] ? 'checked' : '' ?> />
                      <div>
                        <div class="text-sm font-semibold">Weekend premium enabled</div>
                        <div class="text-xs text-white/60">Applies per-day until midnight.</div>
                      </div>
                    </label>
                  <?php endif; ?>
                  <?php if (in_array('weekend_days', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Weekend days (JSON)</div>
                      <input name="weekend_days" value="<?= h((string)$vals['weekend_days']) ?>" placeholder='["SAT","SUN"]'
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    </label>
                  <?php endif; ?>
                  <?php if (in_array('weekend_rate_multiplier', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Weekend multiplier</div>
                      <input name="weekend_rate_multiplier" value="<?= h((string)$vals['weekend_rate_multiplier']) ?>" placeholder="1.25"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    </label>
                  <?php endif; ?>
                </div>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                  <?php if (in_array('bank_holiday_enabled', $allowedBasic, true)): ?>
                    <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                      <input type="checkbox" name="bank_holiday_enabled" class="h-4 w-4 rounded" <?= $vals['bank_holiday_enabled'] ? 'checked' : '' ?> />
                      <div>
                        <div class="text-sm font-semibold">Bank holiday enabled</div>
                        <div class="text-xs text-white/60">Uses dates from Bank Holidays table.</div>
                      </div>
                    </label>
                  <?php endif; ?>
                  <?php if (in_array('bank_holiday_paid', $allowedBasic, true)): ?>
                    <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                      <input type="checkbox" name="bank_holiday_paid" class="h-4 w-4 rounded" <?= $vals['bank_holiday_paid'] ? 'checked' : '' ?> />
                      <div>
                        <div class="text-sm font-semibold">Bank holiday is paid</div>
                        <div class="text-xs text-white/60">If off, BH days are ignored.</div>
                      </div>
                    </label>
                  <?php endif; ?>
                  <?php if (in_array('bank_holiday_paid_cap_hours', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">BH paid cap (hours)</div>
                      <input type="number" min="0" step="1" name="bank_holiday_paid_cap_hours" value="<?= h((string)$vals['bank_holiday_paid_cap_hours']) ?>" placeholder="12"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                      <div class="mt-2 text-xs text-white/50">Caps the Bank Holiday hours bucket per day (until midnight)</div>
                    </label>
                  <?php endif; ?>
                  <?php if (in_array('bank_holiday_rate_multiplier', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Bank holiday multiplier</div>
                      <input name="bank_holiday_rate_multiplier" value="<?= h((string)$vals['bank_holiday_rate_multiplier']) ?>" placeholder="1.5"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    </label>
                  <?php endif; ?>
                </div>
              </section>
            <?php endif; ?>

            <?php if ($canHigh): ?>
              <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <h2 class="text-lg font-semibold">High-level settings (superadmin)</h2>
                    <p class="mt-1 text-sm text-white/70">Device authorisation and other system controls.</p>
                  </div>
                  <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-rose-100">Superadmin only</span>
                </div>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                  <label class="rounded-2xl border border-white/10 bg-white/5 p-4 md:col-span-2">
                    <div class="text-xs uppercase tracking-widest text-white/50">Uploads base path</div>
                    <input name="uploads_base_path" value="<?= h($vals['uploads_base_path']) ?>" placeholder="/home/sites/.../private_uploads or /home/sites/.../public_html/uploads"
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    <div class="mt-2 text-xs text-white/50">Base folder for uploads (e.g., punch photos). Leave blank to use <code class="px-2 py-1 rounded-xl bg-white/10">../uploads</code>.</div>
                  </label>

                  <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <input type="checkbox" name="admin_pairing_mode" class="h-4 w-4 rounded" <?= $vals['admin_pairing_mode'] ? 'checked' : '' ?>/>
                    <div>
                      <div class="text-sm font-semibold">Enable admin pairing mode</div>
                      <div class="text-xs text-white/60">When off, /admin/pair.php blocks pairing.</div>
                    </div>
                  </label>

                  <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-widest text-white/50">Pairing mode until (UTC)</div>
                    <input name="admin_pairing_mode_until" value="<?= h($vals['admin_pairing_mode_until']) ?>" placeholder="2026-01-12 23:59:00"
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    <div class="mt-2 text-xs text-white/50">Leave blank to keep on until you turn it off.</div>
                  </label>

                  <label class="rounded-2xl border border-white/10 bg-white/5 p-4 md:col-span-2">
                    <div class="text-xs uppercase tracking-widest text-white/50">Admin pairing passcode</div>
                    <input name="admin_pairing_code" value="<?= h($vals['admin_pairing_code']) ?>" class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    <div class="mt-2 text-xs text-white/50">Changing this does not revoke existing trusted devices. Use “Revoke all” in Devices for that.</div>
                  </label>
                </div>
              </section>

              <section class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <h2 class="text-lg font-semibold">All settings (kiosk_settings)</h2>
                    <p class="mt-1 text-sm text-white/70">Superadmin tool to edit any key/value stored in <code class="px-2 py-1 rounded-xl bg-white/10">kiosk_settings</code>. Save uses the main “Save settings” button at the bottom.</p>
                  </div>
                  <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-rose-100">Superadmin only</span>
                </div>

                <?php if (empty($allSettings)): ?>
                  <div class="mt-4 text-sm text-white/70">No settings found (or failed to load).</div>
                <?php else: ?>
                  <div class="mt-4 space-y-3">
                    <?php foreach ($allSettings as $r):
                      $k = (string)($r['key'] ?? '');
                      $v = (string)($r['value'] ?? '');
                      if ($k === '') continue;
                    ?>
                      <details class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <summary class="cursor-pointer select-none flex items-center justify-between gap-4">
                          <span class="text-sm font-semibold"><?= h($k) ?></span>
                          <span class="text-xs text-white/50 truncate max-w-[60%]"><?= h($v) ?></span>
                        </summary>
                        <div class="mt-3">
                          <input name="all_settings[<?= h($k) ?>]" value="<?= h($v) ?>"
                            class="w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                        </div>
                      </details>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </section>
            <?php endif; ?>

            <?php if (count($allowedBasic) > 0 || $canHigh): ?>
              <div class="flex items-center justify-end">
                <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Save settings</button>
              </div>
            <?php endif; ?>
          </form>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
