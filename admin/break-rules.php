<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

admin_require_perm($user, 'manage_settings_basic');

$active = admin_url('break-rules.php');

function clamp_int(int $v, int $min, int $max): int {
  return max($min, min($max, $v));
}

function mins_to_hhmm(int $mins): string {
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  return sprintf('%dh %02dm', $h, $m);
}

function parse_hours_or_minutes(?string $hoursRaw, ?string $minutesRaw): int {
  $minutesRaw = trim((string)$minutesRaw);
  $hoursRaw = trim((string)$hoursRaw);

  if ($minutesRaw !== '' && is_numeric($minutesRaw)) {
    return (int)round((float)$minutesRaw);
  }
  if ($hoursRaw !== '' && is_numeric($hoursRaw)) {
    return (int)round(((float)$hoursRaw) * 60);
  }
  throw new RuntimeException('Please enter a value in hours or minutes.');
}

function range_label(?int $minMins, ?int $maxMins): string {
  $minMins = $minMins ?? 0;
  if ($maxMins === null) {
    return mins_to_hhmm($minMins) . ' → +∞';
  }
  return mins_to_hhmm($minMins) . ' → ' . mins_to_hhmm($maxMins);
}

function ranges_overlap(int $aMin, ?int $aMax, int $bMin, ?int $bMax): bool {
  // Treat null max as infinity
  $aMaxV = $aMax === null ? PHP_INT_MAX : $aMax;
  $bMaxV = $bMax === null ? PHP_INT_MAX : $bMax;
  // Overlap if intervals intersect: [aMin, aMaxV) ∩ [bMin, bMaxV) != empty
  return ($aMin < $bMaxV) && ($bMin < $aMaxV);
}

/** Ensure table exists (safe every request) */
$tableOk = true;
try {
  $pdo->query("SELECT 1 FROM kiosk_break_rules_ranges LIMIT 1");
} catch (Throwable $e) {
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS kiosk_break_rules_ranges (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        min_work_minutes INT NOT NULL,
        max_work_minutes INT NULL,
        break_minutes INT NOT NULL,
        priority INT NOT NULL DEFAULT 0,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_enabled_min (is_enabled, min_work_minutes),
        KEY idx_enabled_priority (is_enabled, priority)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  } catch (Throwable $e2) {
    $tableOk = false;
  }
}

$flash = null;
$error = null;

/** Read fallback default break minutes */
$defaultBreakMin = 0;
try {
  $st = $pdo->prepare("SELECT `value` FROM kiosk_settings WHERE `key`='default_break_minutes' LIMIT 1");
  $st->execute();
  $defaultBreakMin = (int)($st->fetch(PDO::FETCH_ASSOC)['value'] ?? 0);
} catch (Throwable $e) {
  $defaultBreakMin = 0;
}
$defaultBreakMin = max(0, $defaultBreakMin);

/** Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);
  $action = (string)($_POST['action'] ?? '');

  if (!$tableOk) {
    $error = 'Break rules table missing. Please run setup.php?action=install (or create kiosk_break_rules_ranges).';
  } else {
    try {
      if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);

        // min
        $minWork = parse_hours_or_minutes($_POST['min_work_hours'] ?? null, $_POST['min_work_minutes'] ?? null);

        // max (optional)
        $maxWork = null;
        $maxHoursRaw = trim((string)($_POST['max_work_hours'] ?? ''));
        $maxMinsRaw  = trim((string)($_POST['max_work_minutes'] ?? ''));
        if ($maxMinsRaw !== '' || $maxHoursRaw !== '') {
          $maxWork = parse_hours_or_minutes($maxHoursRaw, $maxMinsRaw);
        }

        $breakMin = (int)($_POST['break_minutes'] ?? 0);
        $priority = (int)($_POST['priority'] ?? 0);
        $enabled  = ((string)($_POST['is_enabled'] ?? '0') === '1') ? 1 : 0;

        // Validate + clamp
        $minWork = clamp_int($minWork, 0, 7 * 24 * 60);
        if ($maxWork !== null) $maxWork = clamp_int($maxWork, 0, 7 * 24 * 60);
        $breakMin = clamp_int($breakMin, 0, 240);
        $priority = clamp_int($priority, -1000, 1000);

        if ($maxWork !== null && $maxWork <= $minWork) {
          throw new RuntimeException('Max worked time must be greater than Min worked time (or leave max empty for “no upper limit”).');
        }

        if ($action === 'create') {
          $st = $pdo->prepare("
            INSERT INTO kiosk_break_rules_ranges
              (min_work_minutes, max_work_minutes, break_minutes, priority, is_enabled, created_at, updated_at)
            VALUES
              (:minm, :maxm, :brk, :pri, :en, UTC_TIMESTAMP(), UTC_TIMESTAMP())
          ");
          $st->execute([
            ':minm' => $minWork,
            ':maxm' => $maxWork,
            ':brk'  => $breakMin,
            ':pri'  => $priority,
            ':en'   => $enabled,
          ]);
          $flash = 'Break rule added.';
        } else {
          if ($id <= 0) throw new RuntimeException('Invalid rule id.');
          $st = $pdo->prepare("
            UPDATE kiosk_break_rules_ranges
            SET min_work_minutes=:minm,
                max_work_minutes=:maxm,
                break_minutes=:brk,
                priority=:pri,
                is_enabled=:en,
                updated_at=UTC_TIMESTAMP()
            WHERE id=:id
            LIMIT 1
          ");
          $st->execute([
            ':minm' => $minWork,
            ':maxm' => $maxWork,
            ':brk'  => $breakMin,
            ':pri'  => $priority,
            ':en'   => $enabled,
            ':id'   => $id,
          ]);
          $flash = 'Break rule updated.';
        }

      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid rule id.');
        $st = $pdo->prepare("DELETE FROM kiosk_break_rules_ranges WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $flash = 'Break rule deleted.';
      } elseif ($action === 'reorder') {
        $id = (int)($_POST['id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        if ($id <= 0 || $delta === 0) throw new RuntimeException('Invalid reorder request.');
        $st = $pdo->prepare("UPDATE kiosk_break_rules_ranges SET priority = priority + ?, updated_at=UTC_TIMESTAMP() WHERE id=? LIMIT 1");
        $st->execute([$delta, $id]);
        $flash = 'Priority updated.';
      }
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

/** Fetch rules */
$rules = [];
if ($tableOk) {
  $st = $pdo->query("
    SELECT id, min_work_minutes, max_work_minutes, break_minutes, priority, is_enabled, created_at, updated_at
    FROM kiosk_break_rules_ranges
    ORDER BY is_enabled DESC, priority DESC, min_work_minutes DESC, id DESC
  ");
  $rules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Overlap warnings (enabled rules only) */
$overlaps = [];
$enabledRules = array_values(array_filter($rules, fn($r) => (int)($r['is_enabled'] ?? 0) === 1));
for ($i = 0; $i < count($enabledRules); $i++) {
  for ($j = $i + 1; $j < count($enabledRules); $j++) {
    $a = $enabledRules[$i];
    $b = $enabledRules[$j];
    $aMin = (int)$a['min_work_minutes'];
    $aMax = $a['max_work_minutes'] === null ? null : (int)$a['max_work_minutes'];
    $bMin = (int)$b['min_work_minutes'];
    $bMax = $b['max_work_minutes'] === null ? null : (int)$b['max_work_minutes'];

    if (ranges_overlap($aMin, $aMax, $bMin, $bMax)) {
      $overlaps[] = [
        'a' => $a,
        'b' => $b,
      ];
    }
  }
}

/** Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0 && $tableOk) {
  foreach ($rules as $r) {
    if ((int)$r['id'] === $editId) { $edit = $r; break; }
  }
}

$editMinMins = (int)($edit['min_work_minutes'] ?? 0);
$editMaxMins = ($edit && $edit['max_work_minutes'] !== null) ? (int)$edit['max_work_minutes'] : null;

admin_page_start($pdo, 'Break Rules');
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
                <h1 class="text-2xl font-semibold">Break Rules</h1>
                <p class="mt-2 text-sm text-white/70">
                  Range-based rules: <span class="font-semibold text-white/90">0 → X</span>, <span class="font-semibold text-white/90">X → Y</span>, <span class="font-semibold text-white/90">Y → Z</span>, etc.
                  Add as many ranges as you want.
                </p>
                <p class="mt-2 text-xs text-white/40">
                  If no rule matches, fallback default is <span class="font-semibold text-white/80"><?= (int)$defaultBreakMin ?> mins</span>.
                  Paid vs unpaid remains controlled by the employee contract.
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <a href="<?= h(admin_url('settings.php')) ?>" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Settings</a>
              </div>
            </div>
          </header>

          <?php if (!$tableOk): ?>
            <div class="mt-5 rounded-3xl border border-rose-500/30 bg-rose-500/10 p-5 text-rose-100">
              Break rules table is missing and could not be created automatically.
              Please create <span class="font-mono">kiosk_break_rules_ranges</span>.
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

          <?php if ($overlaps): ?>
            <div class="mt-5 rounded-3xl border border-amber-500/30 bg-amber-500/10 p-4 text-amber-100">
              <div class="font-semibold">Overlapping enabled rules detected</div>
              <div class="mt-1 text-sm text-amber-100/90">
                Overlaps are allowed, but payroll will pick by priority order. Consider adjusting ranges if you want deterministic behaviour.
              </div>
            </div>
          <?php endif; ?>

          <section class="mt-5 grid grid-cols-1 xl:grid-cols-5 gap-4">
            <div class="xl:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold"><?= $edit ? 'Edit rule' : 'Add rule' ?></h2>
              <p class="mt-2 text-sm text-white/70">
                Matching uses <span class="font-semibold text-white/90">[Min, Max)</span> minutes (Max is optional).
              </p>

              <form method="post" class="mt-4 grid gap-3">
                <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>"/>
                <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"/>
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"/><?php endif; ?>

                <div class="grid grid-cols-2 gap-3">
                  <label class="grid gap-1">
                    <span class="text-xs text-white/70">Min worked (hours)</span>
                    <input name="min_work_hours" inputmode="decimal"
                           value="<?= h(number_format($editMinMins / 60, 2)) ?>"
                           placeholder="0"
                           class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                  </label>
                  <label class="grid gap-1">
                    <span class="text-xs text-white/70">Min worked (minutes)</span>
                    <input name="min_work_minutes" inputmode="numeric"
                           value="<?= h((string)$editMinMins) ?>"
                           placeholder="0"
                           class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                    <span class="text-[11px] text-white/40">Minutes wins if filled</span>
                  </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                  <label class="grid gap-1">
                    <span class="text-xs text-white/70">Max worked (hours, optional)</span>
                    <input name="max_work_hours" inputmode="decimal"
                           value="<?= h($editMaxMins === null ? '' : number_format($editMaxMins / 60, 2)) ?>"
                           placeholder="(empty = no limit)"
                           class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                  </label>
                  <label class="grid gap-1">
                    <span class="text-xs text-white/70">Max worked (minutes, optional)</span>
                    <input name="max_work_minutes" inputmode="numeric"
                           value="<?= h($editMaxMins === null ? '' : (string)$editMaxMins) ?>"
                           placeholder="(empty = no limit)"
                           class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                    <span class="text-[11px] text-white/40">Minutes wins if filled</span>
                  </label>
                </div>

                <label class="grid gap-1">
                  <span class="text-xs text-white/70">Break minutes</span>
                  <input type="number" name="break_minutes" min="0" max="240"
                         value="<?= h((string)($edit['break_minutes'] ?? '30')) ?>"
                         class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                </label>

                <label class="grid gap-1">
                  <span class="text-xs text-white/70">Priority</span>
                  <input type="number" name="priority"
                         value="<?= h((string)($edit['priority'] ?? '0')) ?>"
                         class="rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm"/>
                </label>

                <label class="flex items-center gap-2">
                  <input type="hidden" name="is_enabled" value="0"/>
                  <input type="checkbox" name="is_enabled" value="1"
                         <?= (!isset($edit['is_enabled']) || (int)$edit['is_enabled'] === 1) ? 'checked' : '' ?>
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
              <p class="mt-2 text-sm text-white/70">
                Rule format: <span class="font-semibold text-white/90">[Min, Max)</span>. Max empty means “no upper limit”.
                Matching uses priority (higher wins) when overlaps exist.
              </p>

              <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-white/60">
                    <tr>
                      <th class="text-left py-2 pr-4">Worked range</th>
                      <th class="text-left py-2 pr-4">Break</th>
                      <th class="text-left py-2 pr-4">Priority</th>
                      <th class="text-left py-2 pr-4">Enabled</th>
                      <th class="text-right py-2">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-white/10">
                    <?php if (!$rules): ?>
                      <tr><td colspan="5" class="py-4 text-white/50">No rules yet. Payroll will use fallback default.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($rules as $r): ?>
                      <?php
                        $minm = (int)$r['min_work_minutes'];
                        $maxm = ($r['max_work_minutes'] === null) ? null : (int)$r['max_work_minutes'];
                        $enabled = (int)$r['is_enabled'] === 1;
                      ?>
                      <tr class="<?= $enabled ? '' : 'opacity-50' ?>">
                        <td class="py-2 pr-4 font-mono"><?= h(range_label($minm, $maxm)) ?></td>
                        <td class="py-2 pr-4"><?= (int)$r['break_minutes'] ?> min</td>
                        <td class="py-2 pr-4"><?= (int)$r['priority'] ?></td>
                        <td class="py-2 pr-4"><?= $enabled ? 'Yes' : 'No' ?></td>
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
            <h3 class="text-lg font-semibold">How matching works (for payroll)</h3>
            <ul class="mt-2 text-sm text-white/70 list-disc pl-5 space-y-1">
              <li>Payroll computes worked minutes (usually using rounded time).</li>
              <li>It finds enabled rules where <span class="font-semibold text-white/90">worked ≥ min</span> and (if max is set) <span class="font-semibold text-white/90">worked &lt; max</span>.</li>
              <li>If multiple match (overlap), highest <span class="font-semibold text-white/90">priority</span> wins.</li>
              <li>If none match, payroll uses the fallback default break minutes.</li>
            </ul>
            <div class="mt-3 text-xs text-white/50">
              Table used by this page: <code class="px-2 py-1 rounded-xl bg-white/10">kiosk_break_rules_ranges</code>
              (you’ll need to update payroll-hours later to read from this table).
            </div>
          </section>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
