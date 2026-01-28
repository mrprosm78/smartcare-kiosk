<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

admin_require_perm($user, 'view_dashboard');

$tzName = admin_setting_str($pdo, 'payroll_timezone', 'Europe/London');
$tz = new DateTimeZone($tzName);

// People currently clocked in = open shifts (clock_out_at IS NULL), excluding void
$open = [];
try {
  $st = $pdo->prepare("
    SELECT
      s.employee_id,
      s.clock_in_at,
      e.employee_code,
      e.first_name,
      e.last_name,
      e.nickname
    FROM kiosk_shifts s
    LEFT JOIN kiosk_employees e ON e.id = s.employee_id
    WHERE s.clock_out_at IS NULL
      AND (s.close_reason IS NULL OR s.close_reason <> 'void')
    ORDER BY s.clock_in_at ASC
  ");
  $st->execute();
  $open = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $open = [];
}

admin_page_start($pdo, 'Dashboard');
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-8">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Dashboard</h1>
                <p class="mt-2 text-sm text-slate-600">
                  People currently clocked in <span class="text-slate-400">(Payroll TZ: <?= h($tzName) ?>)</span>
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <a href="<?= h(admin_url('shifts.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800">Weekly Shifts</a>
                <?php if (admin_can($user, 'manage_settings_basic')): ?>
                  <a href="<?= h(admin_url('settings.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Settings</a>
                <?php endif; ?>
              </div>
            </div>
          </header>

          <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5 overflow-x-auto">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold">Clocked-in list</h2>
                <p class="mt-1 text-sm text-slate-600">Shows open shifts only (no clock-out yet).</p>
              </div>
              <div class="text-sm text-slate-700">
                <span class="font-semibold"><?= (int)count($open) ?></span> active
              </div>
            </div>

            <?php if (!$open): ?>
              <div class="mt-4 text-sm text-slate-500">No one is currently clocked in.</div>
            <?php else: ?>
              <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-xs uppercase tracking-widest text-slate-500">
                    <tr>
                      <th class="text-left py-2 pr-4">Employee</th>
                      <th class="text-left py-2 pr-4">Clocked in (<?= h($tzName) ?>)</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <?php foreach ($open as $r):
                      $name = admin_employee_display_name($r);
                      $code = trim((string)($r['employee_code'] ?? ''));
                      if ($code !== '') $name = $code . ' — ' . $name;

                      $inUtc = (string)($r['clock_in_at'] ?? '');
                      $inLocal = '—';
                      if ($inUtc !== '') {
                        try {
                          $inLocal = (new DateTimeImmutable($inUtc, new DateTimeZone('UTC')))
                            ->setTimezone($tz)
                            ->format('D, d M Y H:i');
                        } catch (Throwable $e) {
                          $inLocal = $inUtc;
                        }
                      }
                    ?>
                      <tr>
                        <td class="py-3 pr-4 font-semibold text-slate-900"><?= h($name) ?></td>
                        <td class="py-3 pr-4 text-slate-700"><?= h($inLocal) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
