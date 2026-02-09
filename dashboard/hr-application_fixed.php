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

/** Check if a column exists (safe across installs). */
function sc_col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$hasHrStaffId = sc_col_exists($pdo, 'hr_applications', 'hr_staff_id');


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

 

// Determine if already converted (LOCKED: prefer hr_applications.hr_staff_id)
$staffId = null;
if ($hasHrStaffId && !empty($app['hr_staff_id'])) {
  $staffId = (int)$app['hr_staff_id'];
} else {
  // Legacy fallback (do not create new installs with this)
  try {
    $chk = $pdo->prepare("SELECT employee_id FROM hr_staff_profiles WHERE application_id = ? LIMIT 1");
    $chk->execute([$id]);
    $legacy = $chk->fetchColumn();
    if ($legacy !== false && $legacy !== null && $legacy !== '') {
      $staffId = (int)$legacy;
    }
  } catch (Throwable $e) {
    $staffId = null;
  }
}

// ===== Actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  // Status / details updates are allowed for managers (future: granular permission table)
  if ($action === 'update_status') {
    if ($staffId !== null) {
      http_response_code(400);
      exit('Application is locked after conversion to staff');
    }
    admin_require_perm($user, 'manage_hr_applications');

    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $allowed = ['draft','submitted','reviewing','rejected','hired','archived'];
    if (!in_array($newStatus, $allowed, true)) {
      http_response_code(400);
      exit('Invalid status');
    }

    $upd = $pdo->prepare("UPDATE hr_applications SET status = ? WHERE id = ? LIMIT 1");
    $upd->execute([$newStatus, $id]);

    header('Location: ' . admin_url('hr-application.php?id=' . $id));
    exit;
  }

  // Convert to staff (enabled only when hired + not already converted)
  if ($action === 'convert_to_staff') {
    admin_require_perm($user, 'manage_hr_applications');
    admin_require_perm($user, 'manage_staff');

    if (!$hasHrStaffId) {
      http_response_code(500);
      exit('Database is missing hr_applications.hr_staff_id. Please run setup.php?action=install once.');
    }
    if ((string)($app['status'] ?? '') !== 'hired') {
      http_response_code(400);
      exit('Only hired applications can be converted to staff.');
    }
    if ($staffId !== null && $staffId > 0) {
      http_response_code(400);
      exit('This application has already been converted.');
    }

    try {
      $pdo->beginTransaction();

      // hr_applications stores a single applicant_name. hr_staff stores first/last.
      $applicantName = trim((string)($app['applicant_name'] ?? ''));
      $firstName = $applicantName;
      $lastName = '';
      if ($applicantName !== '' && preg_match('/\s+/', $applicantName)) {
        $parts = preg_split('/\s+/', $applicantName);
        $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        if (count($parts) >= 2) {
          $lastName = array_pop($parts);
          $firstName = trim(implode(' ', $parts));
        }
      }
      if ($firstName === '') {
        $firstName = 'Unknown';
      }

      $ins = $pdo->prepare(
        "INSERT INTO hr_staff (first_name, last_name, email, phone, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
      );
      $ins->execute([
        $firstName,
        $lastName,
        (string)($app['email'] ?? ''),
        (string)($app['phone'] ?? ''),
      ]);
      $newStaffId = (int)$pdo->lastInsertId();

      $link = $pdo->prepare("UPDATE hr_applications SET hr_staff_id = ? WHERE id = ? AND hr_staff_id IS NULL LIMIT 1");
      $link->execute([$newStaffId, $id]);

      $pdo->commit();

      header('Location: ' . admin_url('hr-staff-view.php?id=' . $newStaffId));
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      http_response_code(500);
      exit('Conversion failed: ' . $e->getMessage());
    }
  }

  http_response_code(400);
  exit('Unknown action');
}



admin_page_start($pdo, 'HR Application');
?>
<div class="min-h-dvh flex">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="flex-1 p-8">
    <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Application #<?= (int)$app['id'] ?></h1>
              <p class="mt-1 text-sm text-slate-600">Submitted application (immutable). You can update status and convert to staff when hired.</p>

              <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                  Application #<?= (int)$app['id'] ?>
                </span>

                <?php if (!empty($app['email'])): ?>
                  <span class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    <?= h((string)$app['email']) ?>
                  </span>
                <?php endif; ?>

                <?php if (!empty($app['phone'])): ?>
                  <span class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    <?= h((string)$app['phone']) ?>
                  </span>
                <?php endif; ?>

                <form method="post" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="update_status">
                  <span class="text-xs font-semibold text-slate-600">Status</span>
                  <select name="status" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs" <?= ($staffId !== null) ? "disabled" : "" ?> onchange="this.form.submit()">
                    <?php foreach (['draft','submitted','reviewing','rejected','hired','archived'] as $s): ?>
                      <option value="<?= h($s) ?>" <?= ((string)($app['status'] ?? '') === $s) ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>

                <?php if ($staffId !== null && $staffId > 0): ?>
                  <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                    Converted to staff
                  </span>
                  <a href="<?= h(admin_url('hr-staff-view.php?id=' . (int)$staffId)) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-1 text-xs font-semibold hover:bg-slate-50">View staff</a>
                <?php elseif ((string)($app['status'] ?? '') === 'hired' && $hasHrStaffId): ?>
                  <form method="post" class="inline">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="convert_to_staff">
                    <button class="rounded-xl bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-500" type="submit">
                      Convert to staff
                    </button>
                  </form>
                <?php endif; ?>
              </div>

	    </div>
	  </div>
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
  </main>
</div>
<?php admin_page_end(); ?>
