<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

admin_require_perm($user, 'manage_settings_high');

$keys = [
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
  'payroll_overtime_threshold_hours',
  'payroll_stacking_mode',
  'payroll_night_start',
  'payroll_night_end',
  'payroll_bank_holiday_cap_hours',
  'payroll_callout_min_paid_hours',
  'default_night_multiplier',
  'default_night_premium_per_hour',
  'default_weekend_multiplier',
  'default_weekend_premium_per_hour',
  'default_bank_holiday_multiplier',
  'default_bank_holiday_premium_per_hour',
  'default_overtime_multiplier',
  'default_overtime_premium_per_hour',
  'default_callout_multiplier',
  'default_callout_premium_per_hour',
];

$deleted = 0;
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);
  try {
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("DELETE FROM kiosk_settings WHERE `key` IN ($in)");
    $st->execute($keys);
    $deleted = $st->rowCount();
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

admin_page_start($pdo, 'Cleanup legacy settings');
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-3xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <h1 class="text-2xl font-semibold">Cleanup legacy payroll settings</h1>
            <p class="mt-2 text-sm text-slate-600">
              Removes old care-home payroll rule keys from <code class="px-2 py-1 rounded-xl bg-slate-50">kiosk_settings</code>.
              Safe to run multiple times.
            </p>
          </header>

          <?php if ($deleted > 0): ?>
            <div class="mt-4 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 text-sm text-slate-900">
              Deleted <?= (int)$deleted ?> rows.
            </div>
          <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === ''): ?>
            <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
              Nothing to delete (already clean).
            </div>
          <?php endif; ?>

          <?php if ($err): ?>
            <div class="mt-4 rounded-2xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-slate-900"><?= h($err) ?></div>
          <?php endif; ?>

          <form method="post" class="mt-5">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
            <div class="rounded-3xl border border-slate-200 bg-white p-5">
              <h2 class="text-lg font-semibold">Keys to delete</h2>
              <ul class="mt-3 space-y-1 text-sm text-slate-600">
                <?php foreach ($keys as $k): ?>
                  <li><code class="px-2 py-1 rounded-xl bg-slate-50"><?= h($k) ?></code></li>
                <?php endforeach; ?>
              </ul>

              <div class="mt-5 flex justify-end">
                <button type="submit" class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">
                  Delete legacy keys
                </button>
              </div>
            </div>
          </form>
        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
