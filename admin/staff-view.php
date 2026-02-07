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

// Decode profile JSON (safe)
$profile = [];
if (!empty($s['profile_json'])) {
  $decoded = json_decode((string)$s['profile_json'], true);
  if (is_array($decoded)) $profile = $decoded;
}

// Ensure staff documents table exists (best-effort)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS staff_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    uploaded_by_admin_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_docs_staff (staff_id),
    KEY idx_staff_docs_type (doc_type),
    KEY idx_staff_docs_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore */ }

// Handle staff photo upload (stored in private uploads path)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_photo') {
  admin_csrf_verify();

  if (!isset($_FILES['staff_photo']) || !is_array($_FILES['staff_photo'])) {
    $errors[] = 'Please choose a photo file.';
  } else {
    $f = $_FILES['staff_photo'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errors[] = 'Upload failed.';
    } else {
      $tmp = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);
      if ($size <= 0 || $size > 5 * 1024 * 1024) {
        $errors[] = 'Photo must be less than 5MB.';
      }
      if (!is_uploaded_file($tmp)) {
        $errors[] = 'Invalid upload.';
      }

      $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
      ];
      $mime = '';
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
          $m = finfo_file($fi, $tmp);
          if (is_string($m)) $mime = $m;
          finfo_close($fi);
        }
      }
      if ($mime === '' && function_exists('mime_content_type')) {
        $m = mime_content_type($tmp);
        if (is_string($m)) $mime = $m;
      }
      if ($mime === '' || !isset($allowed[$mime])) {
        $errors[] = 'Photo must be a JPG, PNG, or WEBP image.';
      }

      if (!$errors) {
        $ext = $allowed[$mime];

        $baseCfg = trim(admin_setting_str($pdo, 'uploads_base_path', 'auto'));
        $base = resolve_uploads_base_path($baseCfg);
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'staff_photos';
        if (!is_dir($dir)) {
          @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
          $errors[] = 'Uploads folder is not writable.';
        } else {
          $rand = bin2hex(random_bytes(6));
          $fname = 'staff_' . $staffId . '_' . gmdate('Ymd_His') . '_' . $rand . '.' . $ext;
          $dest = $dir . DIRECTORY_SEPARATOR . $fname;

          if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = 'Unable to save photo.';
          } else {
            // Store relative path under uploads base.
            $rel = 'staff_photos/' . $fname;

            // Optional: delete old photo if it exists under the same base.
            $old = (string)($s['photo_path'] ?? '');
            if ($old !== '' && $old !== $rel) {
              $oldRel = ltrim($old, "/\\");
              if (str_starts_with($oldRel, 'uploads/')) {
                $oldRel = substr($oldRel, strlen('uploads/'));
              }
              $oldFull = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($oldRel, '/\\');
              $oldReal = @realpath($oldFull);
              $baseReal = @realpath($base);
              if ($oldReal && $baseReal && is_file($oldReal)) {
                $prefix = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR;
                if (strpos($oldReal, $prefix) === 0) {
                  @unlink($oldReal);
                }
              }
            }

            $upd = $pdo->prepare("UPDATE hr_staff SET photo_path=?, updated_by_admin_id=? WHERE id=? LIMIT 1");
            $upd->execute([$rel, (int)($user['id'] ?? 0), $staffId]);

            header('Location: ' . admin_url('staff-view.php?id=' . $staffId));
            exit;
          }
        }
      }
    }
  }
}

// Handle staff document upload (stored in private uploads path)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document') {
  admin_csrf_verify();

  $docType = trim((string)($_POST['doc_type'] ?? ''));
  $note = trim((string)($_POST['note'] ?? ''));

  $allowedTypes = [
    'photo_id' => 'Right-to-work / ID',
    'dbs' => 'DBS',
    'cv' => 'CV',
    'training' => 'Training certificate',
    'reference' => 'Reference',
    'other' => 'Other',
  ];
  if (!isset($allowedTypes[$docType])) {
    $errors[] = 'Please choose a valid document type.';
  }

  if (!isset($_FILES['staff_document']) || !is_array($_FILES['staff_document'])) {
    $errors[] = 'Please choose a document file.';
  } else {
    $f = $_FILES['staff_document'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errors[] = 'Upload failed.';
    } else {
      $tmp = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);
      if ($size <= 0 || $size > 12 * 1024 * 1024) {
        $errors[] = 'Document must be less than 12MB.';
      }
      if (!is_uploaded_file($tmp)) {
        $errors[] = 'Invalid upload.';
      }

      $allowedMimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
      ];
      $mime = '';
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
          $m = finfo_file($fi, $tmp);
          if (is_string($m)) $mime = $m;
          finfo_close($fi);
        }
      }
      if ($mime === '' && function_exists('mime_content_type')) {
        $m = mime_content_type($tmp);
        if (is_string($m)) $mime = $m;
      }
      if ($mime === '' || !isset($allowedMimes[$mime])) {
        $errors[] = 'Document must be a PDF, JPG, PNG, or WEBP.';
      }

      if (!$errors) {
        $ext = $allowedMimes[$mime];
        $origName = (string)($f['name'] ?? 'document.' . $ext);
        $origName = trim($origName);
        if ($origName === '') $origName = 'document.' . $ext;
        if (mb_strlen($origName) > 255) $origName = mb_substr($origName, 0, 250) . '.' . $ext;

        $baseCfg = trim(admin_setting_str($pdo, 'uploads_base_path', 'auto'));
        $base = resolve_uploads_base_path($baseCfg);
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'staff_documents' . DIRECTORY_SEPARATOR . 'staff_' . $staffId;
        if (!is_dir($dir)) {
          @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
          $errors[] = 'Uploads folder is not writable.';
        } else {
          $rand = bin2hex(random_bytes(6));
          $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($origName, PATHINFO_FILENAME));
          $safeBase = trim((string)$safeBase, '._-');
          if ($safeBase === '') $safeBase = 'document';
          $fname = $docType . '_' . gmdate('Ymd_His') . '_' . $rand . '_' . $safeBase . '.' . $ext;
          if (mb_strlen($fname) > 180) {
            $fname = $docType . '_' . gmdate('Ymd_His') . '_' . $rand . '.' . $ext;
          }
          $dest = $dir . DIRECTORY_SEPARATOR . $fname;

          if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = 'Unable to save document.';
          } else {
            $rel = 'staff_documents/staff_' . $staffId . '/' . $fname;
            $ins = $pdo->prepare("INSERT INTO staff_documents
              (staff_id, doc_type, original_name, stored_path, mime_type, file_size, note, uploaded_by_admin_id, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([
              $staffId,
              $docType,
              $origName,
              $rel,
              $mime,
              $size,
              $note !== '' ? $note : null,
              (int)($user['id'] ?? 0),
            ]);

            header('Location: ' . admin_url('staff-view.php?id=' . $staffId));
            exit;
          }
        }
      }
    }
  }
}
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
              <div class="min-w-0 flex items-start gap-4">
                <div class="shrink-0">
                  <div class="h-16 w-16 rounded-2xl border border-slate-200 bg-slate-50 overflow-hidden">
                    <?php if (!empty($s['photo_path'])): ?>
                      <img alt="Staff photo" class="h-full w-full object-cover" src="<?= h(admin_url('staff-photo.php?id=' . $staffId)) ?>">
                    <?php else: ?>
                      <div class="h-full w-full flex items-center justify-center text-xs font-semibold text-slate-500">No photo</div>
                    <?php endif; ?>
                  </div>
                </div>
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
                <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('staff-profile.php?id=' . $staffId)); ?>">Edit HR profile</a>
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
                <h3 class="text-sm font-semibold text-slate-900">Staff photo</h3>
                <p class="mt-1 text-xs text-slate-600">Stored in the private store_* uploads path (not publicly accessible).</p>
                <form class="mt-2" method="post" enctype="multipart/form-data">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="upload_photo">
                  <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-end">
                    <div class="flex-1">
                      <label class="block text-xs font-semibold text-slate-700">Upload photo (JPG/PNG/WEBP)</label>
                      <input type="file" name="staff_photo" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm" required>
                    </div>
                    <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" type="submit">Upload</button>
                  </div>
                </form>
              </div>

              <div class="mt-5">
                <h3 class="text-sm font-semibold text-slate-900">Documents</h3>
                <p class="mt-1 text-xs text-slate-600">Uploads are stored in the private store_* path and are only downloadable by permitted admin users.</p>

                <?php
                  $docsStmt = $pdo->prepare("SELECT id, doc_type, original_name, note, created_at
                    FROM staff_documents
                    WHERE staff_id = ?
                    ORDER BY created_at DESC
                    LIMIT 50");
                  $docsStmt->execute([$staffId]);
                  $docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                  $docTypeLabels = [
                    'photo_id' => 'Right-to-work / ID',
                    'dbs' => 'DBS',
                    'cv' => 'CV',
                    'training' => 'Training certificate',
                    'reference' => 'Reference',
                    'other' => 'Other',
                  ];
                ?>

                <?php if (!$docs): ?>
                  <div class="mt-2 text-xs text-slate-600">No documents uploaded yet.</div>
                <?php else: ?>
                  <div class="mt-2 space-y-2">
                    <?php foreach ($docs as $d): ?>
                      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                          <div class="min-w-0">
                            <div class="text-xs text-slate-600">
                              <?php
                                $dt = (string)($d['doc_type'] ?? 'other');
                                echo h2($docTypeLabels[$dt] ?? ucfirst($dt));
                              ?>
                              · <span class="text-slate-500"><?php echo h2((string)($d['created_at'] ?? '')); ?></span>
                            </div>
                            <div class="mt-0.5 font-semibold text-slate-900 truncate">
                              <?php echo h2((string)($d['original_name'] ?? 'Document')); ?>
                            </div>
                            <?php if (!empty($d['note'])): ?>
                              <div class="mt-1 text-xs text-slate-700"><?php echo h2((string)$d['note']); ?></div>
                            <?php endif; ?>
                          </div>
                          <div class="shrink-0">
                            <a class="inline-flex items-center rounded-2xl px-3 py-1.5 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-100"
                               href="<?php echo h(admin_url('staff-document.php?id=' . (int)$d['id'])); ?>">Download</a>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <form class="mt-3" method="post" enctype="multipart/form-data">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="upload_document">

                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <div>
                      <label class="block text-xs font-semibold text-slate-700">Document type</label>
                      <select name="doc_type" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" required>
                        <option value="">Choose…</option>
                        <option value="photo_id">Right-to-work / ID</option>
                        <option value="dbs">DBS</option>
                        <option value="cv">CV</option>
                        <option value="training">Training certificate</option>
                        <option value="reference">Reference</option>
                        <option value="other">Other</option>
                      </select>
                    </div>
                    <div class="sm:col-span-2">
                      <label class="block text-xs font-semibold text-slate-700">File (PDF/JPG/PNG/WEBP)</label>
                      <input type="file" name="staff_document" accept="application/pdf,image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm" required>
                    </div>
                    <div class="sm:col-span-3">
                      <label class="block text-xs font-semibold text-slate-700">Note (optional)</label>
                      <input name="note" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. Passport (expires 2029)" maxlength="255" />
                    </div>
                  </div>

                  <button class="mt-2 rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" type="submit">Upload document</button>
                </form>
              </div>

              <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-3">
                  <h3 class="text-sm font-semibold text-slate-900">Work history</h3>
                  <?php
                    $wh = $profile['work_history'] ?? [];
                    if (!is_array($wh)) $wh = [];
                    $jobs = $wh['jobs'] ?? [];
                    if (!is_array($jobs)) $jobs = [];
                  ?>
                  <?php if (!$jobs): ?>
                    <p class="mt-1 text-xs text-slate-600">No work history added yet.</p>
                  <?php else: ?>
                    <ul class="mt-2 space-y-2 text-sm">
                      <?php foreach ($jobs as $j): if (!is_array($j)) continue; ?>
                        <li class="rounded-xl border border-slate-200 bg-slate-50 p-2">
                          <div class="font-semibold text-slate-900"><?php echo h2((string)($j['employer'] ?? ($j['company'] ?? 'Employer'))); ?></div>
                          <div class="text-xs text-slate-700">
                            <?php echo h2((string)($j['role'] ?? ($j['job_title'] ?? ''))); ?>
                            <?php
                              $from = trim((string)($j['from'] ?? ($j['start'] ?? '')));
                              $to = trim((string)($j['to'] ?? ($j['end'] ?? '')));
                              $range = trim($from . ($to !== '' ? (' – ' . $to) : ''));
                              if ($range !== '') echo ' · ' . h2($range);
                            ?>
                          </div>
                          <?php if (!empty($j['note'])): ?>
                            <div class="mt-1 text-xs text-slate-600"><?php echo h2((string)$j['note']); ?></div>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                  <?php if (!empty($wh['gap_explanations'])): ?>
                    <div class="mt-2 text-xs text-slate-700">
                      <span class="font-semibold">Gaps:</span> <?php echo h2((string)$wh['gap_explanations']); ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-3">
                  <h3 class="text-sm font-semibold text-slate-900">References</h3>
                  <?php
                    $refs = $profile['references'] ?? [];
                    if (!is_array($refs)) $refs = [];
                    $rs = $refs['references'] ?? [];
                    if (!is_array($rs)) $rs = [];
                  ?>
                  <?php if (!$rs): ?>
                    <p class="mt-1 text-xs text-slate-600">No references added yet.</p>
                  <?php else: ?>
                    <ul class="mt-2 space-y-2 text-sm">
                      <?php foreach ($rs as $r): if (!is_array($r)) continue; ?>
                        <li class="rounded-xl border border-slate-200 bg-slate-50 p-2">
                          <div class="font-semibold text-slate-900"><?php echo h2((string)($r['name'] ?? 'Reference')); ?></div>
                          <div class="text-xs text-slate-700">
                            <?php
                              $org = trim((string)($r['company'] ?? ($r['organisation'] ?? '')));
                              $rel = trim((string)($r['relationship'] ?? ''));
                              $meta = trim($org . ($rel !== '' ? (' · ' . $rel) : ''));
                              echo h2($meta !== '' ? $meta : '');
                            ?>
                          </div>
                          <div class="mt-1 text-xs text-slate-700">
                            <?php
                              $phone = trim((string)($r['phone'] ?? ''));
                              $email = trim((string)($r['email'] ?? ''));
                              $line = trim($phone . ($email !== '' ? (' · ' . $email) : ''));
                              echo h2($line !== '' ? $line : '');
                            ?>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
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
