<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

admin_page_start($pdo, 'Staff Profile');
$active = admin_url('staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$staffId = (int)($_GET['id'] ?? 0);
if ($staffId <= 0) { http_response_code(400); exit('Missing staff id'); }

// Ensure table exists (best-effort)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS hr_staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kiosk_employee_id INT UNSIGNED NULL,
    application_id INT UNSIGNED NULL,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    nickname VARCHAR(100) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(80) NULL,
    department_id INT UNSIGNED NULL,
    team_id INT UNSIGNED NULL,
    status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    photo_path VARCHAR(255) NULL,
    profile_json LONGTEXT NULL,
    created_by_admin_id INT UNSIGNED NULL,
    updated_by_admin_id INT UNSIGNED NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_staff_kiosk (kiosk_employee_id),
    UNIQUE KEY uq_hr_staff_application (application_id),
    KEY idx_hr_staff_dept (department_id),
    KEY idx_hr_staff_status (status),
    KEY idx_hr_staff_updated (updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore */ }

$stmt = $pdo->prepare("SELECT s.*, d.name AS department_name, t.name AS team_name
  FROM hr_staff s
  LEFT JOIN kiosk_employee_departments d ON d.id = s.department_id
  LEFT JOIN kiosk_employee_teams t ON t.id = s.team_id
  WHERE s.id = ?
  LIMIT 1");
$stmt->execute([$staffId]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) { http_response_code(404); exit('Staff not found'); }

$name = trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''));
if ($name === '') $name = 'Staff #' . $staffId;

$kiosk = null;
if (!empty($s['kiosk_employee_id'])) {
  $k = $pdo->prepare("SELECT id, employee_code, is_active, archived_at, pin_updated_at FROM kiosk_employees WHERE id = ? LIMIT 1");
  $k->execute([(int)$s['kiosk_employee_id']]);
  $kiosk = $k->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Handle "Enable Kiosk Access"
$errors = [];
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_kiosk') {
  admin_csrf_verify();

  if ($kiosk) {
    $errors[] = 'Kiosk access is already enabled for this staff member.';
  } else {
    $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
    $pin = trim((string)($_POST['pin'] ?? ''));

    if ($pin === '' || !preg_match('/^\d{4,10}$/', $pin)) {
      $errors[] = 'PIN must be 4–10 digits.';
    }

    // Employee code is optional but must be unique if provided
    if ($employeeCode !== '') {
      $chk = $pdo->prepare("SELECT id FROM kiosk_employees WHERE employee_code = ? LIMIT 1");
      $chk->execute([$employeeCode]);
      $exists = (int)($chk->fetchColumn() ?: 0);
      if ($exists > 0) $errors[] = 'Employee code is already in use.';
    }

    // PIN must be unique (fingerprint)
    if (!$errors) {
      $pin_fingerprint = hash('sha256', $pin);
      $chk = $pdo->prepare("SELECT id FROM kiosk_employees WHERE pin_fingerprint = ? AND archived_at IS NULL LIMIT 1");
      $chk->execute([$pin_fingerprint]);
      $existingId = (int)($chk->fetchColumn() ?: 0);
      if ($existingId > 0) $errors[] = 'PIN is already in use.';
    }

    if (!$errors) {
      $pin_hash = password_hash($pin, PASSWORD_BCRYPT);
      $ins = $pdo->prepare("INSERT INTO kiosk_employees
        (employee_code, first_name, last_name, nickname, department_id, team_id, is_agency, agency_label, pin_hash, pin_fingerprint, pin_updated_at, archived_at, is_active, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, NULL, NULL, 0, NULL, ?, ?, NOW(), NULL, 1, NOW(), NOW())");
      // Note: We intentionally do NOT store department/team in kiosk_employees (kiosk identity is minimal).
      $ins->execute([
        $employeeCode !== '' ? $employeeCode : null,
        (string)($s['first_name'] ?? ''),
        (string)($s['last_name'] ?? ''),
        (string)($s['nickname'] ?? ''),
        $pin_hash,
        $pin_fingerprint,
      ]);
      $kioskId = (int)$pdo->lastInsertId();

      $upd = $pdo->prepare("UPDATE hr_staff SET kiosk_employee_id = ?, updated_by_admin_id = ? WHERE id = ? LIMIT 1");
      $upd->execute([$kioskId, (int)($user['id'] ?? 0), $staffId]);

      header('Location: ' . admin_url('staff-view.php?id=' . $staffId));
      exit;
    }
  }
}

?>
<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-8">
    <div class="max-w-7xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1 min-w-0">
          <header class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
              <div class="min-w-0">
                <h1 class="text-2xl font-semibold truncate"><?php echo h2($name); ?></h1>
                <p class="mt-1 text-sm text-slate-600">
                  Department: <span class="font-semibold text-slate-900"><?php echo h2((string)($s['department_name'] ?? '—')); ?></span>
                  <?php if (!empty($s['team_name'])): ?>
                    · Team: <span class="font-semibold text-slate-900"><?php echo h2((string)$s['team_name']); ?></span>
                  <?php endif; ?>
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('staff.php')); ?>">Back</a>
                <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" href="<?php echo h(admin_url('staff-edit.php?id=' . $staffId)); ?>">Edit</a>
              </div>
            </div>
          </header>

          <?php if ($errors): ?>
            <div class="mt-4 rounded-3xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
              <ul class="list-disc pl-5">
                <?php foreach ($errors as $e): ?><li><?php echo h2($e); ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <section class="lg:col-span-2 rounded-3xl border border-slate-200 bg-white p-4">
              <h2 class="text-lg font-semibold">Staff details</h2>
              <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                  <div class="text-xs text-slate-600">Status</div>
                  <div class="font-semibold text-slate-900"><?php echo h2(ucfirst((string)($s['status'] ?? 'active'))); ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                  <div class="text-xs text-slate-600">Preferred name</div>
                  <div class="font-semibold text-slate-900"><?php echo h2((string)($s['nickname'] ?? '—')); ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                  <div class="text-xs text-slate-600">Email</div>
                  <div class="font-semibold text-slate-900"><?php echo h2((string)($s['email'] ?? '—')); ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                  <div class="text-xs text-slate-600">Phone</div>
                  <div class="font-semibold text-slate-900"><?php echo h2((string)($s['phone'] ?? '—')); ?></div>
                </div>
              </div>

              <div class="mt-4">
                <h3 class="text-sm font-semibold text-slate-900">Profile JSON (temporary)</h3>
                <p class="mt-1 text-xs text-slate-600">This will be replaced by structured sections + uploads (ID/DBS/RTW) and a staff photo.</p>
                <pre class="mt-2 whitespace-pre-wrap rounded-2xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-800"><?php
                  $pj = (string)($s['profile_json'] ?? '');
                  echo h2($pj !== '' ? $pj : '{}');
                ?></pre>
              </div>
            </section>

            <aside class="rounded-3xl border border-slate-200 bg-white p-4">
              <h2 class="text-lg font-semibold">Kiosk access</h2>
              <?php if ($kiosk): ?>
                <div class="mt-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm">
                  <div class="font-semibold text-emerald-900">Enabled</div>
                  <div class="mt-1 text-xs text-emerald-900/80">Kiosk ID: <?php echo (int)$kiosk['id']; ?></div>
                  <div class="mt-1 text-xs text-emerald-900/80">Employee code: <?php echo h2((string)($kiosk['employee_code'] ?? '—')); ?></div>
                </div>
                <div class="mt-3">
                  <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('kiosk-ids.php')); ?>">View kiosk IDs</a>
                </div>
              <?php else: ?>
                <div class="mt-3 text-sm text-slate-700">
                  Kiosk access is not enabled yet. Create a kiosk identity and set a PIN.
                </div>

                <form class="mt-3" method="post">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="enable_kiosk"/>

                  <label class="block text-sm font-semibold text-slate-700">Employee code (optional)</label>
                  <input name="employee_code" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 115"/>

                  <label class="block mt-3 text-sm font-semibold text-slate-700">PIN (required)</label>
                  <input name="pin" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="4–10 digits" inputmode="numeric"/>

                  <button class="mt-3 w-full rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" type="submit">Enable kiosk access</button>
                </form>
              <?php endif; ?>
            </aside>
          </div>

        </main>
      </div>
    </div>
  </div>
</div>
<?php admin_page_end(); ?>
