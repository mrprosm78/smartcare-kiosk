<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_settings_basic');

$active = admin_url('break-tiers.php');
$err = '';

function hm_to_minutes(string $s): int {
  $s = trim($s);
  if ($s === '') return 0;

  // allow "H:MM" or "HH:MM" or just minutes
  if (preg_match('/^\d+$/', $s)) {
    return max(0, (int)$s);
  }

  if (preg_match('/^(\d{1,3})\s*:\s*(\d{1,2})$/', $s, $m)) {
    $h = (int)$m[1];
    $min = (int)$m[2];
    if ($min < 0) $min = 0;
    if ($min > 59) $min = 59;
    return max(0, ($h * 60) + $min);
  }

  throw new RuntimeException('Time must be in H:MM (e.g., 6:00) or minutes (e.g., 360).');
}

function minutes_to_hm(int $mins): string {
  $mins = max(0, $mins);
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  return $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'create') {
      $minWorked = hm_to_minutes((string)($_POST['min_worked'] ?? '0'));
      $breakMins = hm_to_minutes((string)($_POST['break_time'] ?? '0'));
      $sort = (int)($_POST['sort_order'] ?? 0);
      $enabled = isset($_POST['is_enabled']) ? 1 : 0;

      $pdo->prepare("INSERT INTO kiosk_break_tiers (min_worked_minutes, break_minutes, sort_order, is_enabled) VALUES (?,?,?,?)")
        ->execute([$minWorked, $breakMins, $sort, $enabled]);

      admin_redirect($active);
    }

    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid tier');

      $minWorked = hm_to_minutes((string)($_POST['min_worked'] ?? '0'));
      $breakMins = hm_to_minutes((string)($_POST['break_time'] ?? '0'));
      $sort = (int)($_POST['sort_order'] ?? 0);
      $enabled = isset($_POST['is_enabled']) ? 1 : 0;

      $pdo->prepare("UPDATE kiosk_break_tiers SET min_worked_minutes=?, break_minutes=?, sort_order=?, is_enabled=? WHERE id=?")
        ->execute([$minWorked, $breakMins, $sort, $enabled, $id]);

      admin_redirect($active);
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid tier');
      $pdo->prepare("DELETE FROM kiosk_break_tiers WHERE id=?")->execute([$id]);
      admin_redirect($active);
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Load tiers
$tiers = [];
try {
  $st = $pdo->query("SELECT * FROM kiosk_break_tiers ORDER BY min_worked_minutes ASC, sort_order ASC, id ASC");
  $tiers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $tiers = [];
}

admin_page_start($pdo, 'Break Tiers');
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
                <h1 class="text-2xl font-semibold">Break Tiers</h1>
                <p class="mt-2 text-sm text-slate-600">
                  Break entitlement is calculated per shift using worked minutes. The tier with the highest <span class="font-semibold">min worked</span> that is ≤ worked time wins.
                </p>
              </div>
            </div>
          </header>

          <?php if ($err !== ''): ?>
            <div class="mt-5 rounded-3xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-black-100">
              <?= h($err) ?>
            </div>
          <?php endif; ?>

          <div class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Add tier</h2>
            <form method="post" class="mt-4 grid grid-cols-1 md:grid-cols-12 gap-3">
              <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="create">

              <div class="md:col-span-4">
                <label class="block text-xs font-semibold text-slate-600">Min worked (H:MM)</label>
                <input name="min_worked" required class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="e.g. 3:01">
              </div>

              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600">Break time (H:MM)</label>
                <input name="break_time" required class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="e.g. 0:30">
              </div>

              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">Sort</label>
                <input name="sort_order" type="number" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" value="0">
              </div>

              <div class="md:col-span-2 flex items-end">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" name="is_enabled" checked class="rounded border-slate-200 bg-white">
                  Enabled
                </label>
              </div>

              <div class="md:col-span-1 flex items-end">
                <button class="w-full rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-500/15 border border-emerald-500/30 text-black-100 hover:bg-emerald-500/20">Add</button>
              </div>
            </form>
          </div>

          <div class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Current tiers</h2>
            <div class="mt-4 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="text-slate-500">
                  <tr>
                    <th class="text-left py-2">Min worked</th>
                    <th class="text-left py-2">Break</th>
                    <th class="text-left py-2">Sort</th>
                    <th class="text-left py-2">Enabled</th>
                    <th class="text-right py-2">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  <?php foreach ($tiers as $t): ?>
                    <tr>
                      <td class="py-3 font-semibold text-slate-900">
                        <?= h(minutes_to_hm((int)$t['min_worked_minutes'])) ?>
                        <div class="text-xs text-slate-500"><?= (int)$t['min_worked_minutes'] ?> mins</div>
                      </td>
                      <td class="py-3 text-slate-900">
                        <?= h(minutes_to_hm((int)$t['break_minutes'])) ?>
                        <div class="text-xs text-slate-500"><?= (int)$t['break_minutes'] ?> mins</div>
                      </td>
                      <td class="py-3 text-slate-600"><?= (int)$t['sort_order'] ?></td>
                      <td class="py-3">
                        <?php if ((int)$t['is_enabled'] === 1): ?>
                          <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/15 border border-emerald-500/30 text-black-100">Yes</span>
                        <?php else: ?>
                          <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold bg-white border border-slate-200 text-slate-500">No</span>
                        <?php endif; ?>
                      </td>
                      <td class="py-3">
                        <div class="flex justify-end gap-2">
                          <details class="group">
                            <summary class="cursor-pointer rounded-2xl px-3 py-2 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Edit</summary>
                            <div class="mt-2 rounded-2xl border border-slate-200 bg-white p-3 w-80">
                              <form method="post" class="grid grid-cols-1 gap-2">
                                <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">

                                <label class="text-xs font-semibold text-slate-500">Min worked (H:MM)</label>
                                <input name="min_worked" class="rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" value="<?= h(minutes_to_hm((int)$t['min_worked_minutes'])) ?>">

                                <label class="text-xs font-semibold text-slate-500">Break time (H:MM)</label>
                                <input name="break_time" class="rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" value="<?= h(minutes_to_hm((int)$t['break_minutes'])) ?>">

                                <label class="text-xs font-semibold text-slate-500">Sort</label>
                                <input name="sort_order" type="number" class="rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" value="<?= (int)$t['sort_order'] ?>">

                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                  <input type="checkbox" name="is_enabled" <?= ((int)$t['is_enabled'] === 1 ? 'checked' : '') ?> class="rounded border-slate-200 bg-white">
                                  Enabled
                                </label>

                                <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-indigo-500/15 border border-indigo-500/30 text-indigo-100 hover:bg-indigo-500/20">Save</button>
                              </form>
                            </div>
                          </details>

                          <form method="post" onsubmit="return confirm('Delete this tier?')">
                            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="rounded-2xl px-3 py-2 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-black-100 hover:bg-rose-500/20">Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <?php if (!$tiers): ?>
                    <tr>
                      <td colspan="5" class="py-6 text-center text-slate-500">No tiers found. Add one above.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
