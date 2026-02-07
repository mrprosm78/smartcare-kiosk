<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/../helpers.php';

// Must be logged in and permitted.
$user = admin_require_login($pdo);
admin_require_perm($user, 'manage_staff');

$staffId = (int)($_GET['id'] ?? 0);
if ($staffId <= 0) {
  http_response_code(400);
  echo 'Missing staff id';
  exit;
}

try {
  $st = $pdo->prepare('SELECT id, photo_path FROM hr_staff WHERE id = ? LIMIT 1');
  $st->execute([$staffId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    echo 'Not found';
    exit;
  }

  $photoPath = (string)($row['photo_path'] ?? '');
  if ($photoPath === '') {
    http_response_code(404);
    echo 'Photo not set';
    exit;
  }

  // Resolve base uploads directory (supports private store_* path via APP_UPLOADS_PATH).
  $baseCfg = trim(admin_setting_str($pdo, 'uploads_base_path', 'auto'));
  $base = resolve_uploads_base_path($baseCfg);

  // DB stores relative path like "staff_photos/..." or old "uploads/...".
  $photoRel = ltrim($photoPath, "/\\");
  if (str_starts_with($photoRel, 'uploads/')) {
    $photoRel = substr($photoRel, strlen('uploads/'));
  }

  $isAbsolute = false;
  if ($photoPath !== '' && ($photoPath[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $photoPath))) {
    $isAbsolute = true;
  }

  $full = $isAbsolute ? $photoPath : (rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($photoRel, '/\\'));
  $realFull = realpath($full);
  if ($realFull === false || !is_file($realFull)) {
    http_response_code(404);
    echo 'File missing';
    exit;
  }

  // Security: if relative, ensure resolved path stays inside base.
  $realBase = realpath($base);
  if (!$isAbsolute && $realBase !== false) {
    $prefix = rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR;
    if (strpos($realFull, $prefix) !== 0) {
      http_response_code(403);
      echo 'Forbidden';
      exit;
    }
  }

  // Detect mime
  $mime = 'image/jpeg';
  if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
      $m = finfo_file($fi, $realFull);
      if (is_string($m) && $m !== '') $mime = $m;
      finfo_close($fi);
    }
  }

  header('Content-Type: ' . $mime);
  header('Content-Disposition: inline; filename="staff-photo-' . $staffId . '"');
  header('Cache-Control: private, max-age=3600');
  readfile($realFull);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo 'Error';
  exit;
}
