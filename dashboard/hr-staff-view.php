<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

admin_page_start($pdo, 'Staff Profile');
$active = admin_url('hr-staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$staffId = (int)($_GET['id'] ?? 0);
if ($staffId <= 0) { http_response_code(400); exit('Missing staff id'); }

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

$staffCode = trim((string)($s['staff_code'] ?? ''));
if ($staffCode === '') $staffCode = (string)$staffId;

$kiosk = null;
try {
  // LOCKED: kiosk identity links to HR staff via kiosk_employees.hr_staff_id
  $k = $pdo->prepare("SELECT id, employee_code, is_active, archived_at, pin_updated_at
                      FROM kiosk_employees
                      WHERE hr_staff_id = ?
                      ORDER BY id DESC
                      LIMIT 1");
  $k->execute([$staffId]);
  $kiosk = $k->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $kiosk = null;
}


$app = null;
try {
  // LOCKED: application links to staff via hr_applications.hr_staff_id
  $a = $pdo->prepare("SELECT id, status, job_slug, applicant_name, email, phone, submitted_at, created_at
                      FROM hr_applications
                      WHERE hr_staff_id = ?
                      ORDER BY id DESC
                      LIMIT 1");
  $a->execute([$staffId]);
  $app = $a->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $app = null;
}

// Kiosk linking is managed from the Kiosk IDs page.
$errors = [];
$notice = '';

// ===== Update HR Staff department =====
// We currently reuse kiosk_employee_departments as the lookup table.
// HR Staff owns the department_id field (kiosk identity should link to staff, not own HR data).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_department') {
  admin_csrf_verify();

  $deptId = (int)($_POST['department_id'] ?? 0);
  if ($deptId <= 0) {
    $deptId = null; // allow clearing
  }

  if ($deptId !== null) {
    $chk = $pdo->prepare('SELECT id FROM kiosk_employee_departments WHERE id = ? LIMIT 1');
    $chk->execute([$deptId]);
    if (!$chk->fetchColumn()) {
      $errors[] = 'Please choose a valid department.';
    }
  }

  if (!$errors) {
    $upd = $pdo->prepare('UPDATE hr_staff SET department_id = ?, updated_by_admin_id = ? WHERE id = ? LIMIT 1');
    $upd->execute([$deptId, (int)($user['id'] ?? 0), $staffId]);
    header('Location: ' . admin_url('hr-staff-view.php?id=' . $staffId));
    exit;
  }
}

// Load departments for dropdown
$departments = [];
try {
  $departments = $pdo->query('SELECT id, name FROM kiosk_employee_departments ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $departments = [];
}

// Load active contract summary (by today)
$contract = null;
try {
  $today = gmdate('Y-m-d');
  $c = $pdo->prepare(
    "SELECT id, effective_from, effective_to, contract_json
     FROM hr_staff_contracts
     WHERE staff_id = ?
       AND effective_from <= ?
       AND (effective_to IS NULL OR effective_to >= ?)
     ORDER BY effective_from DESC, id DESC
     LIMIT 1"
  );
  $c->execute([$staffId, $today, $today]);
  $contract = $c->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $contract = null;
}

$contractData = [];
if ($contract && !empty($contract['contract_json'])) {
  $decoded = json_decode((string)$contract['contract_json'], true);
  if (is_array($decoded)) $contractData = $decoded;
}

// Decode profile JSON (safe)
$profile = [];
if (!empty($s['profile_json'])) {
  $decoded = json_decode((string)$s['profile_json'], true);
  if (is_array($decoded)) $profile = $decoded;
}

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

            header('Location: ' . admin_url('hr-staff-view.php?id=' . $staffId));
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
            $ins = $pdo->prepare("INSERT INTO hr_staff_documents
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

            header('Location: ' . admin_url('hr-staff-view.php?id=' . $staffId));
            exit;
          }
        }
      }
    }
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_kiosk') {
  // LOCKED: linking Kiosk IDs to Staff is done from the Kiosk IDs module.
  http_response_code(403);
  exit('Kiosk linking is managed from the Kiosk IDs page.');
}

?>
<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">

          <header class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
              <div class="min-w-0 flex items-start gap-4">
                <div class="shrink-0">
                  <div class="h-16 w-16 rounded-2xl border border-slate-200 bg-slate-50 overflow-hidden">
                    <?php if (!empty($s['photo_path'])): ?>
                      <img alt="Staff photo" class="h-full w-full object-cover" src="<?= h(admin_url('hr-staff-photo.php?id=' . $staffId)) ?>">
                    <?php else: ?>
                      <div class="h-full w-full flex items-center justify-center text-xs font-semibold text-slate-500">No photo</div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="min-w-0">
                <h1 class="text-2xl font-semibold truncate"><?php echo h2($name); ?></h1>
                <p class="mt-1 text-sm text-slate-600">
                  Staff ID: <span class="font-semibold text-slate-900"><?php echo h2($staffCode); ?></span>
                  ·
                  Department: <span class="font-semibold text-slate-900"><?php echo h2((string)($s['department_name'] ?? '—')); ?></span>
                  <?php if (!empty($s['team_name'])): ?>
                    · Team: <span class="font-semibold text-slate-900"><?php echo h2((string)$s['team_name']); ?></span>
                  <?php endif; ?>
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('hr-staff.php')); ?>">Back</a>
                <?php if (!empty($app) && !empty($app['id'])): ?>
                  <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('hr-application.php?id=' . (int)$app['id'])); ?>">View application</a>
                <?php endif; ?>
                <?php if (!empty($kiosk) && !empty($kiosk['id'])): ?>
                  <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('kiosk-ids.php?select=' . (int)$kiosk['id'])); ?>">Manage kiosk identity</a>
                <?php else: ?>
                  <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('kiosk-ids.php')); ?>">Manage kiosk identity</a>
                <?php endif; ?>
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
                    FROM hr_staff_documents
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
                               href="<?php echo h(admin_url('hr-staff-document.php?id=' . (int)$d['id'])); ?>">Download</a>
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

            <aside class="rounded-3xl border border-slate-200 bg-white p-4 space-y-4">
              <div>
                <h2 class="text-lg font-semibold">Department</h2>
                <p class="mt-1 text-xs text-slate-600">Staff department is stored on the HR Staff record.</p>

                <form class="mt-2" method="post">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="update_department">

                  <label class="block text-xs font-semibold text-slate-700">Select department</label>
                  <select name="department_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                    <option value="0">— None —</option>
                    <?php foreach ($departments as $d): ?>
                      <option value="<?php echo (int)$d['id']; ?>" <?php echo ((int)($s['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : ''; ?>>
                        <?php echo h2((string)$d['name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>

                  <button class="mt-2 w-full rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" type="submit">Save department</button>
                </form>
              </div>

              <div>
                <h2 class="text-lg font-semibold">Pay contract</h2>
                <p class="mt-1 text-xs text-slate-600">Payroll and rota will use the HR Staff contract (effective by date).</p>

                <?php if ($contract): ?>
                  <?php
                    $rate = $contractData['hourly_rate'] ?? null;
                    $hours = $contractData['contract_hours_per_week'] ?? null;
                    $breakPaid = $contractData['breaks_paid'] ?? null;
                  ?>
                  <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm">
                    <div class="text-xs text-slate-600">Active contract</div>
                    <div class="mt-1 font-semibold text-slate-900">
                      <?php echo h2((string)($contract['effective_from'] ?? '')); ?>
                      <?php if (!empty($contract['effective_to'])): ?>
                        – <?php echo h2((string)$contract['effective_to']); ?>
                      <?php else: ?>
                        (ongoing)
                      <?php endif; ?>
                    </div>
                    <div class="mt-2 text-xs text-slate-700 space-y-1">
                      <div>Hourly rate: <span class="font-semibold text-slate-900"><?php echo $rate !== null && $rate !== '' ? h2('£' . (string)$rate) : '—'; ?></span></div>
                      <div>Contract hours/wk: <span class="font-semibold text-slate-900"><?php echo $hours !== null && $hours !== '' ? h2((string)$hours) : '—'; ?></span></div>
                      <div>Breaks paid: <span class="font-semibold text-slate-900"><?php echo $breakPaid === null ? '—' : ($breakPaid ? 'Yes' : 'No'); ?></span></div>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="mt-2 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    No active contract found for today.
                  </div>
                <?php endif; ?>

                <div class="mt-2">
                  <a class="inline-flex w-full items-center justify-center rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('hr-staff-contract.php?staff_id=' . $staffId)); ?>">Manage pay contract</a>
                </div>
              </div>

              <div>
                <h2 class="text-lg font-semibold">Kiosk access</h2>
              <?php if ($kiosk): ?>
                <div class="mt-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm">
                  <div class="font-semibold text-emerald-900">Enabled</div>
                  <div class="mt-1 text-xs text-emerald-900/80">Kiosk ID: <?php echo (int)$kiosk['id']; ?></div>
                  <div class="mt-1 text-xs text-emerald-900/80">Employee code: <?php echo h2((string)($kiosk['employee_code'] ?? '—')); ?></div>
                </div>
                <div class="mt-3">
                  <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('kiosk-ids.php?select=' . (int)$kiosk['id'])); ?>">Open linked kiosk ID</a>
                </div>
              <?php else: ?>
                <div class="mt-3 text-sm text-slate-700">
                  Kiosk access is not enabled yet. Use the Kiosk IDs page to create/link a kiosk identity.
                </div>
                <div class="mt-3">
                  <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('kiosk-ids.php')); ?>">Go to kiosk IDs</a>
                </div>
              <?php endif; ?>
              </div>
            </aside>
          </div>

  </main>
</div>
<?php admin_page_end(); ?>
