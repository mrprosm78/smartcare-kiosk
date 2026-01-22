<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/permissions.php';

// Must be logged in and permitted.
$user = admin_require_login($pdo);
admin_require_perm($user, 'view_punches');

$id = (int)($_GET['id'] ?? 0);
$size = strtolower(trim((string)($_GET['size'] ?? 'full')));
if (!in_array($size, ['thumb','full'], true)) $size = 'full';

if ($id <= 0) {
  http_response_code(400);
  echo 'Missing photo id';
  exit;
}

try {
  $st = $pdo->prepare("SELECT id, photo_path FROM kiosk_punch_photos WHERE id = ? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    echo 'Not found';
    exit;
  }

  $photoPath = (string)($row['photo_path'] ?? '');
    if ($photoPath === '') {
    http_response_code(404);
    echo 'Not found';
    exit;
  }

  // Base path is configurable so you can move uploads outside public folder later.
  // For portability across duplicated installs (/yyy/), store uploads_base_path as
  // a RELATIVE path like "uploads" and we resolve it from project root.
  $baseCfg = trim(admin_setting_str($pdo, 'uploads_base_path', 'auto'));
  $base = resolve_uploads_base_path($baseCfg);

  // Backward compatibility: older installs stored "uploads/..." in photo_path.
  // If uploads_base_path already points to the uploads folder, strip the prefix.
  $photoRel = ltrim($photoPath, "/\\");
  if (str_starts_with($photoRel, 'uploads/')) {
    $photoRel = substr($photoRel, strlen('uploads/'));
  }

  $isAbsolute = false;
  if (strlen($photoPath) > 0 && ($photoPath[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $photoPath))) {
    $isAbsolute = true;
  }

  $full = $isAbsolute ? $photoPath : (rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($photoRel, '/\\'));
  $realFull = realpath($full);
  if ($realFull === false || !is_file($realFull)) {
    http_response_code(404);
    echo 'File missing';
    exit;
  }

  // Security: if DB holds relative paths, ensure resolved path stays inside base.
  $realBase = realpath($base);
  if (!$isAbsolute && $realBase !== false) {
    $prefix = rtrim($realBase, '/\\') . DIRECTORY_SEPARATOR;
    if (strpos($realFull, $prefix) !== 0) {
      http_response_code(403);
      echo 'Forbidden';
      exit;
    }
  }

  // Output
  header('Content-Type: image/jpeg');
  header('Content-Disposition: inline; filename="punch-photo-' . $id . '.jpg"');
  header('Cache-Control: private, max-age=3600');

  // For now, thumb/full both return the same file (browser scales thumbnail in UI).
  // You can add cached thumbnail generation later without changing URLs.
  readfile($realFull);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo 'Error';
  exit;
}
