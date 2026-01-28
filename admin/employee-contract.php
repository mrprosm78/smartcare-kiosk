<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_contract');

$active = admin_url('employees.php');

$employeeId = (int)($_GET['id'] ?? 0);
if ($employeeId <= 0) {
  http_response_code(400);
  exit('Missing employee ID');
}

// Load employee
$stmt = $pdo->prepare(
  "SELECT e.*, c.name AS department_name, t.name AS team_name
   FROM kiosk_employees e
   LEFT JOIN kiosk_employee_departments c ON c.id = e.department_id
   LEFT JOIN kiosk_employee_teams t ON t.id = e.team_id
   WHERE e.id = ?
   LIMIT 1"
);
$stmt->execute([$employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
  http_response_code(404);
  exit('Employee not found');
}

$canEdit = admin_can($user, 'edit_contract');
$err = '';
$success = '';

/**
 * LOCKED model:
 * - Break minutes come from Shift Rules (care home). Contract only controls paid/unpaid breaks.
 * - No legacy BH fields, no day/night rates
 * - Per-department rules stored in rules_json:
 *   *_multiplier (nullable), *_premium_per_hour (nullable)
 * - Contract-first; if contract rule is null, payroll falls back to care-home defaults.
 */

$pay = [
  'contract_hours_per_week' => null,
  'break_is_paid' => 0,
  'rules_json' => null,
];

$ruleKeys = [
  'bank_holiday',
  'weekend',
  'night',
  'overtime',
  'callout',
];

$rules = [
];
foreach ($ruleKeys as $k) {
  $rules[$k . '_multiplier'] = null;
  $rules[$k . '_premium_per_hour'] = null;
}

$ps = $pdo->prepare("SELECT * FROM kiosk_employee_pay_profiles WHERE employee_id=? LIMIT 1");
$ps->execute([$employeeId]);
$prow = $ps->fetch(PDO::FETCH_ASSOC);
if ($prow) {
  foreach ($pay as $k => $_) {
    if (array_key_exists($k, $prow)) $pay[$k] = $prow[$k];
  }
  $pay['break_is_paid'] = (int)($prow['break_is_paid'] ?? 0);

  if (!empty($prow['rules_json'])) {
    $decoded = json_decode((string)$prow['rules_json'], true);
    if (is_array($decoded)) {
      foreach ($ruleKeys as $k) {
        $mk = $k . '_multiplier';
        $pk = $k . '_premium_per_hour';
        if (array_key_exists($mk, $decoded)) $rules[$mk] = $decoded[$mk];
        if (array_key_exists($pk, $decoded)) $rules[$pk] = $decoded[$pk];
      }
    }
  }
}

function null_if_empty(string $v): ?string {
  $v = trim($v);
  return $v === '' ? null : $v;
}

function to_nullable_float(?string $v): ?float {
  if ($v === null) return null;
  $v = trim($v);
  if ($v === '') return null;
  if (!is_numeric($v)) return null;
  return (float)$v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEdit) {
    http_response_code(403);
    exit('Forbidden');
  }
  admin_verify_csrf($_POST['csrf'] ?? null);

  try {
    $pp = [
      'contract_hours_per_week' => null_if_empty((string)($_POST['contract_hours_per_week'] ?? '')),
      'break_is_paid' => ((int)($_POST['break_is_paid'] ?? 0) === 1) ? 1 : 0,
    ];
  $rulesToSave = [];
    foreach ($ruleKeys as $k) {
      $rulesToSave[$k . '_multiplier'] = to_nullable_float(null_if_empty((string)($_POST[$k . '_multiplier'] ?? '')));
      $rulesToSave[$k . '_premium_per_hour'] = to_nullable_float(null_if_empty((string)($_POST[$k . '_premium_per_hour'] ?? '')));
    }

    $rulesJson = json_encode($rulesToSave, JSON_UNESCAPED_SLASHES);

    // Clean schema update (compatible with your updated setup.php)
	    $sql = "INSERT INTO kiosk_employee_pay_profiles
	              (employee_id, contract_hours_per_week, break_is_paid, rules_json, created_at, updated_at)
	            VALUES
	              (:id, :ch, :bip, :rj, UTC_TIMESTAMP, UTC_TIMESTAMP)
	            ON DUPLICATE KEY UPDATE
	              contract_hours_per_week = VALUES(contract_hours_per_week),
	              break_is_paid = VALUES(break_is_paid),
	              rules_json = VALUES(rules_json),
	              updated_at = UTC_TIMESTAMP";

    $pdo->prepare($sql)->execute([
      ':id' => $employeeId,
      ':ch' => $pp['contract_hours_per_week'],
      ':bip' => $pp['break_is_paid'],
      ':rj' => $rulesJson,
    ]);

    // Reload
    $ps->execute([$employeeId]);
    $prow = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
    $success = 'Contract updated.';
  } catch (Throwable $e) {
    $err = 'Failed to save: ' . $e->getMessage();
  }
}

$title = 'Employee Contract - ' . admin_employee_display_name($employee);
admin_page_start($pdo, $title);
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="w-full">
      <div class="flex flex-col lg:flex-row gap-5">

        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1 min-w-0">

          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-2xl font-semibold">Employee Contract</h1>
              <div class="mt-1 text-sm text-slate-600">
                <span class="font-semibold text-slate-900"><?= h(admin_employee_display_name($employee)) ?></span>
                · Code: <span class="font-semibold text-slate-900"><?= h((string)($employee['employee_code'] ?? '')) ?></span>
                <?php if (!empty($employee['department_name'])): ?>
                  · Department: <span class="font-semibold text-slate-900"><?= h((string)$employee['department_name']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="flex gap-2">
              <a href="<?= h($active) ?>" class="px-4 py-2 rounded-xl bg-slate-50 hover:bg-slate-100">Back</a>
            </div>
          </div>

          <?php if ($err): ?>
            <div class="mt-4 p-3 rounded-2xl bg-red-500/15 border border-red-500/30 text-red-100"><?= h($err) ?></div>
          <?php endif; ?>
          <?php if ($success): ?>
            <div class="mt-4 p-3 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-slate-900"><?= h($success) ?></div>
          <?php endif; ?>

          <form method="post" class="mt-4 bg-white border border-slate-200 rounded-3xl p-4 space-y-6">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">

            <div>
              <h2 class="text-lg font-semibold">Basics</h2>
              <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                <label class="block">
                  <div class="text-sm text-slate-600">Contract hours per week</div>
                  <input name="contract_hours_per_week" value="<?= h((string)($prow['contract_hours_per_week'] ?? '')) ?>" class="mt-1 w-full rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">
                </label>
              </div>
            </div>

            <div>
	              <h2 class="text-lg font-semibold">Breaks</h2>
	              <div class="mt-1 text-sm text-slate-500">Break minutes are configured in <b>Shift Rules</b> (care-home). This contract only controls whether those break minutes are treated as paid or unpaid for this employee.</div>
	              <div class="mt-3">
                <label class="inline-flex items-center gap-2">
                  <input type="checkbox" name="break_is_paid" value="1" <?= ((int)($prow['break_is_paid'] ?? 0)===1) ? 'checked' : '' ?> class="rounded">
                  <span class="text-sm text-slate-700">Break is paid</span>
                </label>
              </div>
            </div>

    

              <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <?php foreach ($ruleKeys as $k):
                  $mk = $k.'_multiplier';
                  $pk = $k.'_premium_per_hour';
                  $mv = $rules[$mk] ?? '';
                  $pv = $rules[$pk] ?? '';
                  $label = ucwords(str_replace('_',' ', $k));
                ?>
                  <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="font-semibold text-slate-900"><?= h($label) ?></div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                      <label class="block">
                        <div class="text-sm text-slate-600">Multiplier (e.g. 1.5)</div>
                        <input name="<?= h($mk) ?>" value="<?= h((string)$mv) ?>" class="mt-1 w-full rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">
                      </label>
                      <label class="block">
                        <div class="text-sm text-slate-600">Premium £/hour (e.g. 0.20)</div>
                        <input name="<?= h($pk) ?>" value="<?= h((string)$pv) ?>" class="mt-1 w-full rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <?php if ($canEdit): ?>
              <div class="flex justify-end">
                <button class="px-5 py-2 rounded-xl bg-emerald-500/20 border border-emerald-500/30 hover:bg-emerald-500/25">Save</button>
              </div>
            <?php endif; ?>
          </form>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
