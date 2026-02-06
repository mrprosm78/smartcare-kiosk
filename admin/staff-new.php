<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$active = admin_url('employees.php');

$errors = [];
function post_str(string $k): string { return trim((string)($_POST[$k] ?? '')); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify();

  $employeeCode = post_str('employee_code');
  $first = post_str('first_name');
  $last = post_str('last_name');
  $nick = post_str('nickname');
  $dept = (int)($_POST['department_id'] ?? 0);
  $team = (int)($_POST['team_id'] ?? 0);
  $isAgency = (int)($_POST['is_agency'] ?? 0) ? 1 : 0;
  $agencyLabel = post_str('agency_label');
  $isActive = (int)($_POST['is_active'] ?? 1) ? 1 : 0;

  if ($employeeCode === '') $errors[] = 'Employee code is required.';
  if ($nick === '' && ($first === '' || $last === '')) $errors[] = 'Provide First+Last name or Nickname.';

  if (!$errors) {
    $chk = $pdo->prepare("SELECT id FROM kiosk_employees WHERE employee_code = ? LIMIT 1");
    $chk->execute([$employeeCode]);
    if ($chk->fetchColumn()) $errors[] = 'Employee code already exists.';
  }

  if (!$errors) {
    $stmt = $pdo->prepare("
      INSERT INTO kiosk_employees
        (employee_code, first_name, last_name, nickname, department_id, team_id, is_agency, agency_label, pin_hash, pin_fingerprint, pin_updated_at, archived_at, is_active, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, NOW(), NOW())
    ");
    $stmt->execute([
      $employeeCode,
      $first !== '' ? $first : null,
      $last !== '' ? $last : null,
      $nick !== '' ? $nick : null,
      $dept > 0 ? $dept : null,
      $team > 0 ? $team : null,
      $isAgency,
      $isAgency ? ($agencyLabel !== '' ? $agencyLabel : null) : null,
      $isActive,
    ]);

    $newId = (int)$pdo->lastInsertId();
    header('Location: ' . admin_url('employees.php?highlight=' . $newId));
    exit;
  }
}

// Departments/Teams in SmartCare Kiosk use `is_active` (there is no `archived_at`).
$departments = $pdo->query("SELECT id, name FROM kiosk_employee_departments WHERE is_active=1 ORDER BY sort_order ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);
$teams = $pdo->query("SELECT id, name FROM kiosk_employee_teams WHERE is_active=1 ORDER BY sort_order ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);

admin_page_start($pdo, 'Add Staff');
?>
<div class="p-6">
  <div class="max-w-5xl">
    <div class="grid gap-4 lg:grid-cols-[280px,1fr]">
      <?php include __DIR__ . '/partials/sidebar.php'; ?>

      <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Add staff</h1>
              <p class="mt-1 text-sm text-slate-600">Create an employee record for existing staff.</p>
            </div>
            <a href="<?= h(admin_url('employees.php')) ?>"
               class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50">← Back</a>
          </div>

          <?php if ($errors): ?>
            <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
              <ul class="list-disc pl-5"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            </div>
          <?php endif; ?>

          <form method="post" class="mt-5 grid gap-4">
            <?php admin_csrf_field(); ?>

            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Employee code *</span>
                <input name="employee_code" value="<?= h((string)($_POST['employee_code'] ?? '')) ?>"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" required>
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Status</span>
                <select name="is_active" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="1" <?= ((string)($_POST['is_active'] ?? '1') === '1') ? 'selected' : '' ?>>Active</option>
                  <option value="0" <?= ((string)($_POST['is_active'] ?? '1') === '0') ? 'selected' : '' ?>>Inactive</option>
                </select>
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-slate-600">First name</span>
                <input name="first_name" value="<?= h((string)($_POST['first_name'] ?? '')) ?>"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Last name</span>
                <input name="last_name" value="<?= h((string)($_POST['last_name'] ?? '')) ?>"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
              </label>

              <label class="block sm:col-span-2">
                <span class="text-xs font-semibold text-slate-600">Nickname</span>
                <input name="nickname" value="<?= h((string)($_POST['nickname'] ?? '')) ?>"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Department</span>
                <select name="department_id" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="0">—</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= ((int)($_POST['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Team</span>
                <select name="team_id" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="0">—</option>
                  <?php foreach ($teams as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= ((int)($_POST['team_id'] ?? 0) === (int)$t['id']) ? 'selected' : '' ?>><?= h($t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Employment type</span>
                <select name="is_agency" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="0" <?= ((string)($_POST['is_agency'] ?? '0') === '0') ? 'selected' : '' ?>>Permanent / Direct</option>
                  <option value="1" <?= ((string)($_POST['is_agency'] ?? '0') === '1') ? 'selected' : '' ?>>Agency</option>
                </select>
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Agency label</span>
                <input name="agency_label" value="<?= h((string)($_POST['agency_label'] ?? '')) ?>"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>

            <div class="flex items-center gap-2">
              <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Create staff</button>
              <span class="text-xs text-slate-500">PIN can be set later.</span>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>
<?php admin_page_end(); ?>
