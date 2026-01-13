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
  ],
  'superadmin' => [
    'rounding_enabled',
    'round_increment_minutes',
    'round_grace_minutes',
  ],
];

$highLevelKeys = [
  // Admin pairing / device authorisation is high-level
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

  // high-level (superadmin only)
  'admin_pairing_mode' => admin_setting_bool($pdo, 'admin_pairing_mode', false),
  'admin_pairing_mode_until' => admin_setting_str($pdo, 'admin_pairing_mode_until', ''),
  'admin_pairing_code' => admin_setting_str($pdo, 'admin_pairing_code', ''),
  'admin_pairing_version' => admin_setting_int($pdo, 'admin_pairing_version', 1),
];

$success = '';
$err = '';

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

    // High-level: admin pairing (superadmin only)
    if ($canHigh) {
      admin_set_setting($pdo, 'admin_pairing_mode', isset($_POST['admin_pairing_mode']) ? '1' : '0');
      admin_set_setting($pdo, 'admin_pairing_mode_until', trim((string)($_POST['admin_pairing_mode_until'] ?? '')));
      $code = trim((string)($_POST['admin_pairing_code'] ?? ''));
      if ($code !== '') admin_set_setting($pdo, 'admin_pairing_code', $code);
    }

    $success = 'Saved';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }

  // Refresh values
  $vals['rounding_enabled'] = admin_setting_bool($pdo, 'rounding_enabled', true);
  $vals['round_increment_minutes'] = admin_setting_int($pdo, 'round_increment_minutes', 15);
  $vals['round_grace_minutes'] = admin_setting_int($pdo, 'round_grace_minutes', 5);
  $vals['admin_pairing_mode'] = admin_setting_bool($pdo, 'admin_pairing_mode', false);
  $vals['admin_pairing_mode_until'] = admin_setting_str($pdo, 'admin_pairing_mode_until', '');
  $vals['admin_pairing_code'] = admin_setting_str($pdo, 'admin_pairing_code', '');
  $vals['admin_pairing_version'] = admin_setting_int($pdo, 'admin_pairing_version', 1);
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
