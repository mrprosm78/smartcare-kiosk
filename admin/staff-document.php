<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$docId = (int)($_GET['id'] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  exit('Missing document id');
}

$stmt = $pdo->prepare(
  "SELECT d.id, d.staff_id, d.doc_type, d.original_name, d.stored_path, d.mime_type, d.file_size, d.created_at
   FROM staff_documents d
   JOIN hr_staff s ON s.id = d.staff_id
   WHERE d.id = ?
   LIMIT 1"
);
$stmt->execute([$docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
  http_response_code(404);
  exit('Document not found');
}

$rel = (string)($doc['stored_path'] ?? '');
if ($rel === '') {
  http_response_code(404);
  exit('Document not found');
}

$baseCfg = trim(admin_setting_str($pdo, 'uploads_base_path', 'auto'));
$base = resolve_uploads_base_path($baseCfg);
$baseReal = @realpath($base);

$relClean = ltrim($rel, "/\\");
if (str_starts_with($relClean, 'uploads/')) {
  $relClean = substr($relClean, strlen('uploads/'));
}

$full = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relClean);
$real = @realpath($full);

if (!$baseReal || !$real || !is_file($real)) {
  http_response_code(404);
  exit('File missing');
}

$prefix = rtrim($baseReal, "/\\") . DIRECTORY_SEPARATOR;
if (strpos($real, $prefix) !== 0) {
  http_response_code(403);
  exit('Access denied');
}

$mime = (string)($doc['mime_type'] ?? 'application/octet-stream');
$name = (string)($doc['original_name'] ?? ('document_' . $docId));

// Basic header hardening
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . (string)filesize($real));

readfile($real);
exit;
