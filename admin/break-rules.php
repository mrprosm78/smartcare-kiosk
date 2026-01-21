<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

admin_require_perm($user, 'manage_settings_basic');

function is_hhmm(string $t): bool {
  return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $t);
}

function time_to_minutes(string $hhmm): int {
  [$h, $m] = array_map('intval', explode(':', $hhmm));
  return ($h * 60) + $m;
}

/**
 * Does a "start" time fall within a window [start,end) where end may wrap past midnight.
 */
function start_in_window(string $start, string $winStart, string $winEnd): bool {
  $s = time_to_minutes($start);
  $a = time_to_minutes($winStart);
  $b = time_to_minutes($winEnd);

  if ($a === $b) {
    // full-day window
    return true;
  }
  if ($a < $b) {
    return ($s >= $a) && ($s < $b);
  }
  // wraps midnight
  return ($s >= $a) || ($s < $b);
}

$active = admin_url('break-rules.php');

// Ensure table exists (older installs)
$tableOk = true;
try {
  $pdo->query("SELECT 1 FROM kiosk_break_rules LIMIT 1");
} catch (Throwable $e) {
  $tableOk = false;
}

$flash = null;
$error = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);

  $action = (string)($_POST['action'] ?? '');

  if (!$tableOk) {
    $error = 'Shift rules table missing. Please run setup.php?action=install first.';
  } else {
    try {
      if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $start = strtoupper(trim((string)($_POST['start_time'] ?? '')));
        $end = strtoupper(trim((string)($_POST['end_time'] ?? '')));
        $breakMin = (int)($_POST['break_minutes'] ?? 0);
        $priority = (int)($_POST['priority'] ?? 0);
        $enabled = ((string)($_POST['is_enabled'] ?? '0') === '1') ? 1 : 0;

        if (!is_hhmm($start) || !is_hhmm($end)) {
          throw new RuntimeException('Start/End time must be in HH:MM (24h) format.');
        }
        if ($breakMin < 0 || $breakMin > 240) {
          throw new RuntimeException('Break minutes must be between 0 and 240.');
        }
        if ($priority < -1000 || $priority > 1000) {
          throw new RuntimeException('Priority looks invalid.');
        }

        if ($action === 'create') {
          $st = $pdo->prepare("INSERT INTO kiosk_break_rules (start_time,end_time,break_minutes,priority,is_enabled,created_at,updated_at)
                               VALUES (?,?,?,?,?,UTC_TIMESTAMP,UTC_TIMESTAMP)");
          $st->execute([$start, $end, $breakMin, $priority, $enabled]);
          $flash = 'Shift rule added.';
        } else {
          if ($id <= 0) throw new RuntimeException('Invalid rule id.');
          $st = $pdo->prepare("UPDATE kiosk_break_rules
                               SET start_time=?, end_time=?, break_minutes=?, priority=?, is_enabled=?, updated_at=UTC_TIMESTAMP
                               WHERE id=?");
          $st->execute([$start, $end, $breakMin, $priority, $enabled, $id]);
          $flash = 'Shift rule updated.';
        }
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid rule id.');
        $st = $pdo->prepare('DELETE FROM kiosk_break_rules WHERE id = ?');
        $st->execute([$id]);
        $flash = 'Shift rule deleted.';
      } elseif ($action === 'reorder') {
        // optional: allow quick priority bump buttons
        $id = (int)($_POST['id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        if ($id <= 0 || $delta === 0) throw new RuntimeException('Invalid reorder request.');
        $st = $pdo->prepare('UPDATE kiosk_break_rules SET priority = priority + ?, updated_at = UTC_TIMESTAMP WHERE id = ?');
        $st->execute([$delta, $id]);
        $flash = 'Priority updated.';
      }
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

// Fetch rules
$rules = [];
if ($tableOk) {
$st = $pdo->query('SELECT id,start_time,end_time,break_minutes,priority,is_enabled,created_at,updated_at FROM kiosk_break_rules ORDER BY is_enabled DESC, priority DESC, start_time ASC');
  $rules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Edit mode
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0 && $tableOk) {
  foreach ($rules as $r) {
    if ((int)$r['id'] === $editId) { $edit = $r; break; }
  }
}

// Defaults
$settings = [];
try {
  $st = $pdo->query("SELECT `key`, `value` FROM kiosk_settings WHERE `key` IN ('default_break_minutes')");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $settings[(string)$row['key']] = (string)$row['value'];
  }
} catch (Throwable $e) {
}
$defaultBreakMin = (int)($settings['default_break_minutes'] ?? 0);
$defaultBreakPaid = false;

admin_page_start($pdo, 'Shift Rules');
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
                <h1 class="text-2xl font-semibold">Shift Rules</h1>
                <p class="mt-2 text-sm text-white/70">
                  Configure shift windows that map to fixed break minutes. Payroll matches by <span class="font-semibold text-white/90">shift start time</span>.
                </p>
                <p class="mt-2 text-xs text-white/40">
                  Fallback when no rule matches: <span class="font-semibold text-white/80"><?= (int)$defaultBreakMin ?> mins</span>.
                  Whether that break is deducted depends on the employee contract (paid vs unpaid).
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <a href="<?= h(admin_url('settings.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Settings</a>
              </div>
            </div>
          </header>

          <?php if (!$tableOk): ?>
            <div class="mt-5 rounded-3xl border border-rose-500/30 bg-rose-500/10 p-5 text-rose-100">
              Shift rules table is missing. Please run <span class="font-mono">setup.php?action=install</span>.
            </div>
          <?php endif; ?>

          <?php if ($flash): ?>
            <div class="mt-5 rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-emerald-100">
              <?= h($flash) ?>
            </div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="mt-5 rounded-3xl border border-rose-500/30 bg-rose-500/10 p-4 text-rose-100">
              <?= h($error) ?>
            </div>
          <?php endif; ?>

          <section class="mt-5 grid grid-cols-1 xl:grid-cols-5 gap-4">
            <div class="xl:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold"><?= $edit ? 'Edit rule' : 'Add rule' ?></h2>
              <p class="mt-2 text-sm text-white/70">Use 24h HH:MM. End can be earlier than start (overnight).</p>

              <form method="post" class="mt-4 grid gap-3">
                <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
                <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"/>
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"/><?php endif; ?>

                <div class="grid grid-cols-2 gap-3">
                  <label class="grid gap-1">
                    <span class="text-xs text-white/70">Start</span>
                    <input name="start_time" value="<?= h((string)($edit['start_time'] ?? '08:00')) ?>" placeholder="08:00"
                      class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                  </label>
                  <label class="grid gap-1">
                    <span class="text-xs text-white/70">End</span>
                    <input name="end_time" value="<?= h((string)($edit['end_time'] ?? '20:00')) ?>" placeholder="20:00"
                      class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                  </label>
                </div>

                <label class="grid gap-1">
                  <span class="text-xs text-white/70">Break minutes</span>
                  <input type="number" name="break_minutes" min="0" max="240" value="<?= h((string)($edit['break_minutes'] ?? '30')) ?>"
                    class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                </label>

                <div class="grid grid-cols-2 gap-3">
                  <label class="grid gap-1">
                    <span class="text-xs text-white/70">Priority</span>
                    <input type="number" name="priority" value="<?= h((string)($edit['priority'] ?? '0')) ?>"
                      class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                  </label>
                </div>

                <label class="flex items-center gap-2">
                  <input type="hidden" name="is_enabled" value="0"/>
                  <input type="checkbox" name="is_enabled" value="1" <?= (!isset($edit['is_enabled']) || (int)$edit['is_enabled'] === 1) ? 'checked' : '' ?>
                    class="h-4 w-4 rounded border-white/20 bg-slate-950/50"/>
                  <span class="text-sm text-white/80">Enabled</span>
                </label>

                <div class="flex flex-wrap gap-2">
                  <button class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white text-slate-900">
                    <?= $edit ? 'Save changes' : 'Add rule' ?>
                  </button>
                  <?php if ($edit): ?>
                    <a href="<?= h(admin_url('break-rules.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Cancel</a>
                  <?php endif; ?>
                </div>
              </form>
            </div>

            <div class="xl:col-span-3 rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Existing rules</h2>
              <p class="mt-2 text-sm text-white/70">Higher priority wins if multiple windows match the same start time.</p>

              <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-white/60">
                    <tr>
                      <th class="text-left py-2 pr-4">Window</th>
                      <th class="text-left py-2 pr-4">Break</th>
                      <th class="text-left py-2 pr-4">Priority</th>
                      <th class="text-left py-2 pr-4">Enabled</th>
                      <th class="text-right py-2">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-white/10">
                    <?php if (!$rules): ?>
                      <tr><td colspan="5" class="py-4 text-white/50">No shift rules yet. Payroll will use fallback default.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rules as $r): ?>
                      <tr class="<?= ((int)$r['is_enabled'] === 1) ? '' : 'opacity-50' ?>">
                        <td class="py-2 pr-4 font-mono"><?= h((string)$r['start_time']) ?> → <?= h((string)$r['end_time']) ?></td>
                        <td class="py-2 pr-4"><?= (int)$r['break_minutes'] ?> min</td>
                        <td class="py-2 pr-4"><?= (int)$r['priority'] ?></td>
                        <td class="py-2 pr-4"><?= ((int)$r['is_enabled'] === 1) ? 'Yes' : 'No' ?></td>
                        <td class="py-2 text-right">
                          <div class="inline-flex gap-2">
                            <a href="<?= h(admin_url('break-rules.php?edit=' . (int)$r['id'])) ?>" class="rounded-2xl px-3 py-1.5 text-xs font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Edit</a>
                            <form method="post" onsubmit="return confirm('Delete this rule?')" style="display:inline">
                              <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
                              <input type="hidden" name="action" value="delete"/>
                              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"/>
                              <button class="rounded-2xl px-3 py-1.5 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-rose-100 hover:bg-rose-500/20">Delete</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          <section class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5">
            <h3 class="text-lg font-semibold">How matching works</h3>
            <ul class="mt-2 text-sm text-white/70 list-disc pl-5 space-y-1">
              <li>Payroll takes the <span class="font-semibold text-white/90">rounded shift start time</span> in payroll timezone.</li>
              <li>It finds the first enabled rule whose window contains that start time (overnight windows supported).</li>
              <li>If multiple match, the rule with the highest <span class="font-semibold text-white/90">priority</span> wins.</li>
              <li>If none match, payroll uses the fallback default break minutes.</li>
            </ul>
          </section>

        </main>
      </div>
    </div>
  </div>
</div>

<?php
admin_page_end();
