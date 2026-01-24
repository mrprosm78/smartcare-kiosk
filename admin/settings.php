<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

// Anyone who can see Settings must have at least basic settings permission.
admin_require_perm($user, 'manage_settings_basic');

$role = strtolower((string)($user['role'] ?? ''));

// ---------------------------------------------------------------------
// Settings access model
// ---------------------------------------------------------------------
// Basic settings are controlled via an allow-list per role.
// High-level settings (admin pairing + filesystem paths) require manage_settings_high.

$basicAllow = [
  'manager' => [
    'rounding_enabled',
    'round_increment_minutes',
    'round_grace_minutes',
  ],
  'payroll' => [
    // intentionally empty for now
  ],
  'admin' => [
    'rounding_enabled',
    'round_increment_minutes',
    'round_grace_minutes',
    // payroll boundaries
    'payroll_timezone',
  ],
  'superadmin' => [
    'rounding_enabled',
    'round_increment_minutes',
    'round_grace_minutes',
    // payroll boundaries
    'payroll_week_starts_on',
    'payroll_timezone',
  ],
];

$allowedBasic = $basicAllow[$role] ?? [];
$canHigh = admin_can($user, 'manage_settings_high');

// ---------------------------------------------------------------------
// Read current settings
// ---------------------------------------------------------------------
$vals = [
  // rounding
  'rounding_enabled' => admin_setting_bool($pdo, 'rounding_enabled', true),
  'round_increment_minutes' => admin_setting_int($pdo, 'round_increment_minutes', 15),
  'round_grace_minutes' => admin_setting_int($pdo, 'round_grace_minutes', 5),

  // setup lock
  'app_initialized' => admin_setting_bool($pdo, 'app_initialized', true),

  // payroll boundaries (LOCKED rules)
  'payroll_week_starts_on' => admin_setting_str($pdo, 'payroll_week_starts_on', 'MONDAY'),
  'payroll_timezone' => admin_setting_str($pdo, 'payroll_timezone', 'Europe/London'),

  // high-level (superadmin only)
  'admin_pairing_mode' => admin_setting_bool($pdo, 'admin_pairing_mode', false),
  'admin_pairing_mode_until' => admin_setting_str($pdo, 'admin_pairing_mode_until', ''),
  'admin_pairing_code' => admin_setting_str($pdo, 'admin_pairing_code', ''),
  'admin_pairing_version' => admin_setting_int($pdo, 'admin_pairing_version', 1),
  'uploads_base_path' => admin_setting_str($pdo, 'uploads_base_path', 'auto'),
];

// Week start is setup-only once app is initialized
$weekLocked = (bool)($vals['app_initialized'] ?? true);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);

  try {
    // -------------------------
    // Basic: Rounding
    // -------------------------
    if (in_array('rounding_enabled', $allowedBasic, true)) {
      admin_set_setting($pdo, 'rounding_enabled', isset($_POST['rounding_enabled']) ? '1' : '0');
    }
    if (in_array('round_increment_minutes', $allowedBasic, true)) {
      admin_set_setting($pdo, 'round_increment_minutes', (string)max(1, (int)($_POST['round_increment_minutes'] ?? 15)));
    }
    if (in_array('round_grace_minutes', $allowedBasic, true)) {
      admin_set_setting($pdo, 'round_grace_minutes', (string)max(0, (int)($_POST['round_grace_minutes'] ?? 5)));
    }

    // -------------------------
    // Payroll boundaries
    // -------------------------
    if (!$weekLocked && $role === 'superadmin' && in_array('payroll_week_starts_on', $allowedBasic, true)) {
      admin_set_setting($pdo, 'payroll_week_starts_on', strtoupper(trim((string)($_POST['payroll_week_starts_on'] ?? 'MONDAY'))));
    }
    if (in_array('payroll_timezone', $allowedBasic, true)) {
      admin_set_setting($pdo, 'payroll_timezone', trim((string)($_POST['payroll_timezone'] ?? 'Europe/London')));
    }

    // -------------------------
    // High-level: admin pairing + uploads
    // -------------------------
    if ($canHigh) {
      if (isset($_POST['uploads_base_path'])) {
        admin_set_setting($pdo, 'uploads_base_path', trim((string)($_POST['uploads_base_path'] ?? 'auto')));
      }

      admin_set_setting($pdo, 'admin_pairing_mode', isset($_POST['admin_pairing_mode']) ? '1' : '0');
      admin_set_setting($pdo, 'admin_pairing_mode_until', trim((string)($_POST['admin_pairing_mode_until'] ?? '')));

      // Don't allow empty pairing code to overwrite
      $code = trim((string)($_POST['admin_pairing_code'] ?? ''));
      if ($code !== '') admin_set_setting($pdo, 'admin_pairing_code', $code);
    }

    // PRG: redirect so refresh shows saved values and prevents re-post
    header('Location: ' . admin_url('settings.php?saved=1'));
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$success = isset($_GET['saved']) ? 'Saved' : '';

admin_page_start($pdo, 'Settings');

?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <h1 class="text-2xl font-semibold">Settings</h1>
            <p class="mt-2 text-sm text-white/70">
              These settings live in <code class="px-2 py-1 rounded-xl bg-white/10">kiosk_settings</code>.
              Payroll rules (weekend/bank holiday/overtime multipliers & premiums) are contract-based and are <span class="font-semibold">not</span> configured here.
            </p>
          </header>

          <?php if ($success): ?>
            <div class="mt-4 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 text-sm text-emerald-100"><?= h($success) ?></div>
          <?php endif; ?>

          <?php if ($err): ?>
            <div class="mt-4 rounded-2xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-100"><?= h($err) ?></div>
          <?php endif; ?>

          <form method="post" class="mt-5 space-y-5">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>

            <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Rounding</h2>
              <p class="mt-1 text-sm text-white/70">
                Rounding is applied at payroll/export stage (original punch times are never changed).
              </p>

              <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php if (in_array('rounding_enabled', $allowedBasic, true)): ?>
                  <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 md:col-span-1">
                    <input type="checkbox" name="rounding_enabled" class="h-4 w-4 rounded" <?= $vals['rounding_enabled'] ? 'checked' : '' ?>/>
                    <div>
                      <div class="text-sm font-semibold">Enable rounding</div>
                      <div class="text-xs text-white/60">Shows rounded in/out in payroll views.</div>
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
                    <div class="mt-2 text-xs text-white/50">Only snap when within this window.</div>
                  </label>
                <?php endif; ?>
              </div>

              <?php if (!in_array('rounding_enabled', $allowedBasic, true) && !in_array('round_increment_minutes', $allowedBasic, true) && !in_array('round_grace_minutes', $allowedBasic, true)): ?>
                <div class="mt-4 text-sm text-white/70">No rounding settings are enabled for your role.</div>
              <?php endif; ?>
            </section>

            <?php
              $canSeePayrollBoundary = in_array('payroll_week_starts_on', $allowedBasic, true) || in_array('payroll_timezone', $allowedBasic, true);
            ?>
            <?php if ($canSeePayrollBoundary): ?>
              <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-lg font-semibold">Payroll boundaries</h2>
                <p class="mt-1 text-sm text-white/70">
                  These settings define day/week boundaries for weekend and bank holiday cutoffs.
                </p>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                  <?php if (in_array('payroll_week_starts_on', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Week starts on</div>
                      <select name="payroll_week_starts_on"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30"
                        <?= $weekLocked ? "disabled" : "" ?>>
                        <?php foreach (['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'] as $d): ?>
                          <option value="<?= h($d) ?>" <?= strtoupper((string)$vals['payroll_week_starts_on'])===$d ? 'selected' : '' ?>><?= h($d) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="mt-2 text-xs text-white/50">
                        <?php if ($weekLocked): ?>Locked after initial setup<?php else: ?>Set once at initial setup<?php endif; ?>
                      </div>
                    </label>
                  <?php endif; ?>

                  <?php if (in_array('payroll_timezone', $allowedBasic, true)): ?>
                    <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <div class="text-xs uppercase tracking-widest text-white/50">Payroll timezone</div>
                      <input name="payroll_timezone" value="<?= h((string)$vals['payroll_timezone']) ?>" placeholder="Europe/London"
                        class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                      <div class="mt-2 text-xs text-white/50">Used for midnight/day boundaries in payroll.</div>
                    </label>
                  <?php endif; ?>
                </div>
              </section>
            <?php endif; ?>

            <?php if ($canHigh): ?>
              <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-lg font-semibold">System</h2>
                <p class="mt-1 text-sm text-white/70">High-level settings (superadmin only).</p>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                  <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-widest text-white/50">Uploads base path</div>
                    <input name="uploads_base_path" value="<?= h((string)$vals['uploads_base_path']) ?>" placeholder="auto"
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    <div class="mt-2 text-xs text-white/50">
                      Use <code class="px-1.5 py-0.5 rounded-xl bg-white/10">auto</code> to use the private uploads path, or set a relative directory like <code class="px-1.5 py-0.5 rounded-xl bg-white/10">uploads</code>.
                    </div>
                  </label>

                  <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <input type="checkbox" name="admin_pairing_mode" class="h-4 w-4 rounded" <?= $vals['admin_pairing_mode'] ? 'checked' : '' ?> />
                    <div>
                      <div class="text-sm font-semibold">Admin pairing mode enabled</div>
                      <div class="text-xs text-white/60">When off, /admin/pair.php rejects pairing even if the passcode is known.</div>
                    </div>
                  </label>

                  <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-widest text-white/50">Admin pairing mode until (UTC)</div>
                    <input name="admin_pairing_mode_until" value="<?= h((string)$vals['admin_pairing_mode_until']) ?>" placeholder=""
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    <div class="mt-2 text-xs text-white/50">Optional UTC datetime. If expired, pairing is auto-disabled.</div>
                  </label>

                  <label class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-widest text-white/50">Admin pairing passcode</div>
                    <input name="admin_pairing_code" value="" placeholder="(leave blank to keep current)"
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
                    <div class="mt-2 text-xs text-white/50">Leave blank to avoid overwriting the existing code.</div>
                  </label>
                </div>
              </section>
            <?php endif; ?>

            <div class="flex justify-end">
              <button type="submit" class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Save</button>
            </div>
          </form>
        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
