<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

// Managers can update status for now (future: permission table for fine-grained control)
admin_require_perm($user, 'view_hr_applications');

$active = admin_url('hr-applications.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing application id');
}

// Handle status/notes updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'update');

  // Managers can update status for now (per your decision)
  admin_require_perm($user, $action === 'convert' ? 'manage_staff' : 'manage_hr_applications');
  admin_csrf_verify();

  // Ensure conversion tables exist (best-effort)
  if ($action === 'convert') {
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS hr_staff_profiles (
        employee_id INT UNSIGNED NOT NULL PRIMARY KEY,
        application_id INT UNSIGNED NULL,
        profile_json LONGTEXT NOT NULL,
        created_by_admin_id INT UNSIGNED NULL,
        updated_by_admin_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hr_staff_application (application_id),
        KEY idx_hr_staff_updated (updated_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
      // If we cannot create tables in this environment, fail safely.
      http_response_code(500);
      exit('Unable to prepare HR conversion tables.');
    }
  }

  $newStatus = (string)($_POST['status'] ?? '');
  $note = trim((string)($_POST['review_notes'] ?? ''));

  $allowed = ['draft','submitted','reviewing','rejected','hired','archived'];
  if (!in_array($newStatus, $allowed, true)) {
    $newStatus = 'submitted';
  }

  // Convert to staff (creates kiosk_employees + hr_staff_profiles)
  if ($action === 'convert') {
    // Check if already converted
    $existingEmpId = null;
    try {
      $chk = $pdo->prepare("SELECT employee_id FROM hr_staff_profiles WHERE application_id = ? LIMIT 1");
      $chk->execute([$id]);
      $existingEmpId = $chk->fetchColumn();
    } catch (Throwable $e) { $existingEmpId = null; }

    if ($existingEmpId) {
      header('Location: ' . admin_url('employee-edit.php?id=' . (int)$existingEmpId . '&from_app=' . $id));
      exit;
    }

    // Load application again (safe)
    $s = $pdo->prepare("SELECT * FROM hr_applications WHERE id = ? LIMIT 1");
    $s->execute([$id]);
    $appRow = $s->fetch(PDO::FETCH_ASSOC);
    if (!$appRow) {
      http_response_code(404);
      exit('Application not found');
    }
    $payload = [];
    if (!empty($appRow['payload_json'])) {
      $decoded = json_decode((string)$appRow['payload_json'], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
    $dept = (int)($_POST['department_id'] ?? 0);
    $team = (int)($_POST['team_id'] ?? 0);
    $isAgency = (int)($_POST['is_agency'] ?? 0) ? 1 : 0;
    $agencyLabel = trim((string)($_POST['agency_label'] ?? ''));

    if ($employeeCode === '') {
      header('Location: ' . admin_url('hr-application.php?id=' . $id . '&err=missing_code'));
      exit;
    }

    // Prevent duplicate employee codes
    $chkCode = $pdo->prepare("SELECT id FROM kiosk_employees WHERE employee_code = ? LIMIT 1");
    $chkCode->execute([$employeeCode]);
    if ($chkCode->fetchColumn()) {
      header('Location: ' . admin_url('hr-application.php?id=' . $id . '&err=code_taken'));
      exit;
    }

    // Extract a sensible name
    $first = $payload['personal']['first_name'] ?? null;
    $last  = $payload['personal']['last_name'] ?? null;
    $nick  = null;
    if ((!$first || !$last) && !empty($appRow['applicant_name'])) {
      $nick = (string)$appRow['applicant_name'];
    }

    // Create employee
    $ins = $pdo->prepare("INSERT INTO kiosk_employees
      (employee_code, first_name, last_name, nickname, department_id, team_id, is_agency, agency_label,
       pin_hash, pin_fingerprint, pin_updated_at, archived_at, is_active, created_at, updated_at)
      VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, 1, NOW(), NOW())");
    $ins->execute([
      $employeeCode,
      $first ? (string)$first : null,
      $last ? (string)$last : null,
      $nick,
      $dept > 0 ? $dept : null,
      $team > 0 ? $team : null,
      $isAgency,
      $isAgency ? ($agencyLabel !== '' ? $agencyLabel : null) : null,
    ]);
    $empId = (int)$pdo->lastInsertId();

    // Store HR profile snapshot for staff
    $profileJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $insP = $pdo->prepare("INSERT INTO hr_staff_profiles
      (employee_id, application_id, profile_json, created_by_admin_id, updated_by_admin_id, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
    $insP->execute([$empId, $id, $profileJson ?: '{}', (int)$user['id'], (int)$user['id']]);

    // Mark application as hired + linked (safe if columns don't exist)
    try {
      $pdo->prepare("UPDATE hr_applications
        SET status='hired', review_notes=?, reviewed_by_admin_id=?, reviewed_at=NOW(),
            hired_employee_id=?, hired_at=NOW(), hired_by_admin_id=?, updated_at=NOW()
        WHERE id=? LIMIT 1")
        ->execute([$note !== '' ? $note : ($appRow['review_notes'] ?? null), (int)$user['id'], $empId, (int)$user['id'], $id]);
    } catch (Throwable $e) {
      // Fallback if additive columns aren't present yet
      $pdo->prepare("UPDATE hr_applications
        SET status='hired', review_notes=?, reviewed_by_admin_id=?, reviewed_at=NOW(), updated_at=NOW()
        WHERE id=? LIMIT 1")
        ->execute([$note !== '' ? $note : ($appRow['review_notes'] ?? null), (int)$user['id'], $id]);
    }

    header('Location: ' . admin_url('employee-edit.php?id=' . $empId . '&from_app=' . $id));
    exit;
  }

  // Normal status update
  $stmt = $pdo->prepare("UPDATE hr_applications
                         SET status = ?, review_notes = ?, reviewed_by_admin_id = ?, reviewed_at = NOW(), updated_at = NOW()
                         WHERE id = ?
                         LIMIT 1");
  $stmt->execute([$newStatus, $note !== '' ? $note : null, (int)$user['id'], $id]);

  // Best-effort audit log (safe; creates table if missing)
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      actor_admin_id INT UNSIGNED NULL,
      action VARCHAR(80) NOT NULL,
      entity_type VARCHAR(40) NOT NULL,
      entity_id BIGINT UNSIGNED NOT NULL,
      meta_json LONGTEXT NULL,
      ip VARCHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_audit_entity (entity_type, entity_id),
      KEY idx_audit_actor (actor_admin_id),
      KEY idx_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $meta = json_encode([
      'new_status' => $newStatus,
      'note_len' => strlen($note),
    ], JSON_UNESCAPED_SLASHES);

    $ins = $pdo->prepare("INSERT INTO audit_log (actor_admin_id, action, entity_type, entity_id, meta_json, ip, user_agent, created_at)
                          VALUES (?, 'hr_application_update', 'hr_application', ?, ?, ?, ?, NOW())");
    $ins->execute([(int)$user['id'], (int)$id, $meta, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
  } catch (Throwable $e) { /* ignore */ }

  header('Location: ' . admin_url('hr-application.php?id=' . $id . '&saved=1'));
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM hr_applications WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
  http_response_code(404);
  exit('Application not found');
}

$payload = [];
if (!empty($app['payload_json'])) {
  $decoded = json_decode((string)$app['payload_json'], true);
  if (is_array($decoded)) $payload = $decoded;
}

$canManage = admin_can($user, 'manage_hr_applications');
$canConvert = admin_can($user, 'manage_staff');

// If already converted, fetch staff id
$convertedEmpId = null;
try {
  $chk = $pdo->prepare("SELECT employee_id FROM hr_staff_profiles WHERE application_id = ? LIMIT 1");
  $chk->execute([$id]);
  $convertedEmpId = $chk->fetchColumn();
} catch (Throwable $e) { $convertedEmpId = null; }

// Departments/Teams for conversion form
$departments = $pdo->query("SELECT id, name FROM kiosk_employee_departments WHERE is_active=1 ORDER BY sort_order ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);
$teams = $pdo->query("SELECT id, name FROM kiosk_employee_teams WHERE is_active=1 ORDER BY sort_order ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);

admin_page_start($pdo, 'HR Application');
?>
<div class="p-6">
  <div class="max-w-7xl">
    <div class="grid gap-4 lg:grid-cols-[280px,1fr]">
      <?php include __DIR__ . '/partials/sidebar.php'; ?>

      <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Application #<?= (int)$app['id'] ?></h1>
              <p class="mt-1 text-sm text-slate-600">Read-only view of submitted answers.</p>
              <?php if (!empty($_GET['saved'])): ?>
                <div class="mt-2 inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                  Saved
                </div>
              <?php endif; ?>
            </div>
            <a href="<?= h(admin_url('hr-applications.php')) ?>"
               class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50">
              ← Back
            </a>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
            <div>
              <div class="text-xs uppercase tracking-widest text-slate-500">Status</div>
              <div class="font-semibold"><?= h((string)$app['status']) ?></div>
            </div>
            <div>
              <div class="text-xs uppercase tracking-widest text-slate-500">Submitted</div>
              <div class="font-semibold"><?= h((string)($app['submitted_at'] ?: '—')) ?></div>
            </div>
            <div>
              <div class="text-xs uppercase tracking-widest text-slate-500">Job</div>
              <div class="font-semibold"><?= h((string)($app['job_slug'] ?: '—')) ?></div>
            </div>
          </div>

          <?php if ($canConvert): ?>
            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="text-xs uppercase tracking-widest text-slate-500">Hire workflow</div>
                  <div class="font-semibold text-slate-900">Convert applicant to staff</div>
                  <p class="mt-1 text-sm text-slate-600">Creates a staff record and copies the application into the staff HR profile. Applicants and staff remain separate.</p>
                </div>
                <?php if ($convertedEmpId): ?>
                  <a href="<?= h(admin_url('employee-edit.php?id=' . (int)$convertedEmpId . '&from_app=' . $id)) ?>"
                     class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    View staff profile →
                  </a>
                <?php endif; ?>
              </div>

              <?php if (!$convertedEmpId): ?>
                <?php
                  $err = (string)($_GET['err'] ?? '');
                  $errMsg = '';
                  if ($err === 'missing_code') $errMsg = 'Employee code is required to create staff.';
                  if ($err === 'code_taken') $errMsg = 'Employee code already exists. Please use a different code.';
                ?>
                <?php if ($errMsg !== ''): ?>
                  <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <?= h($errMsg) ?>
                  </div>
                <?php endif; ?>

                <form method="post" class="mt-3 grid gap-3">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="convert">
                  <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                      <span class="text-xs font-semibold text-slate-600">Employee code *</span>
                      <input name="employee_code" value="<?= h((string)($_POST['employee_code'] ?? '')) ?>"
                             class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" required>
                    </label>

                    <label class="block">
                      <span class="text-xs font-semibold text-slate-600">Employment type</span>
                      <select name="is_agency" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <option value="0">Permanent / Direct</option>
                        <option value="1">Agency</option>
                      </select>
                    </label>

                    <label class="block">
                      <span class="text-xs font-semibold text-slate-600">Agency label</span>
                      <input name="agency_label" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="Optional">
                    </label>

                    <label class="block">
                      <span class="text-xs font-semibold text-slate-600">Department</span>
                      <select name="department_id" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <option value="0">—</option>
                        <?php foreach ($departments as $d): ?>
                          <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>

                    <label class="block sm:col-span-2">
                      <span class="text-xs font-semibold text-slate-600">Team</span>
                      <select name="team_id" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <option value="0">—</option>
                        <?php foreach ($teams as $t): ?>
                          <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>

                  <div class="flex items-center gap-2">
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                      Convert to staff
                    </button>
                    <span class="text-xs text-slate-500">Creates staff now. You can edit full HR details afterwards.</span>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($canManage): ?>
            <form method="post" class="mt-5 grid gap-3">
              <?php admin_csrf_field(); ?>
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                  <span class="text-xs font-semibold text-slate-600">Update status</span>
                  <select name="status" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                    <?php $opts = ['draft','submitted','reviewing','rejected','hired','archived']; foreach ($opts as $s): ?>
                      <option value="<?= h($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>

                <label class="block sm:col-span-2">
                  <span class="text-xs font-semibold text-slate-600">Internal notes</span>
                  <textarea name="review_notes" rows="4"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                    placeholder="Notes visible to admin/staff only..."><?= h2((string)($app['review_notes'] ?? '')) ?></textarea>
                </label>
              </div>

              <div class="flex items-center gap-2">
                <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                  Save status & notes
                </button>
                <span class="text-xs text-slate-500">Managers can update status for now.</span>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <?php
        $sections = [
          'personal'     => 'Step 1 — Personal',
          'role'         => 'Step 2 — Role & availability',
          'work_history' => 'Step 3 — Work history',
          'education'    => 'Step 4 — Education & training',
          'references'   => 'Step 5 — References',
          'checks'       => 'Step 6 — Right to work & checks',
          'declaration'  => 'Step 8 — Declaration',
        ];

        foreach ($sections as $key => $label):
          $data = is_array($payload[$key] ?? null) ? $payload[$key] : [];
        ?>
          <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold"><?= h($label) ?></h2>
            <?php if (!$data): ?>
              <p class="mt-2 text-sm text-slate-500">No data saved for this section.</p>
            <?php else: ?>
              <div class="mt-3 grid gap-2 sm:grid-cols-2 text-sm">
                <?php foreach ($data as $k => $v): ?>
                  <?php if ($k === 'csrf') continue; ?>
                  <div class="rounded-2xl border border-slate-100 bg-slate-50 px-3 py-2">
                    <div class="text-[11px] uppercase tracking-widest text-slate-500"><?= h((string)$k) ?></div>
                    <div class="mt-1 font-medium text-slate-900">
                      <?= h(is_array($v) ? json_encode($v) : (string)$v) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php admin_page_end(); ?>
