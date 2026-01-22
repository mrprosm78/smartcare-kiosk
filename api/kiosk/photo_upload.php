<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

// multipart/form-data fields
$eventUuid  = (string)($_POST['event_uuid'] ?? '');
$action     = strtoupper((string)($_POST['action'] ?? ''));
$deviceTime = (string)($_POST['device_time'] ?? '');

// headers (same auth as punch)
$kioskCode  = (string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? '');
$deviceTok  = (string)($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
$versionHdr = (int)($_SERVER['HTTP_X_PAIRING_VERSION'] ?? 0);

// optional device identity from Android shell
$deviceIdHdr   = (string)($_SERVER['HTTP_X_DEVICE_ID'] ?? '');
$deviceNameHdr = (string)($_SERVER['HTTP_X_DEVICE_NAME'] ?? '');

// useful request meta
$ipAddress  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$tokHash    = $deviceTok !== '' ? hash('sha256', $deviceTok) : null;

function bad(string $error, int $status = 400): void {
    json_response(['ok' => false, 'error' => $error], $status);
}

try {
    // Basic validation
    if ($eventUuid === '' || strlen($eventUuid) > 40) bad('invalid_event_uuid', 400);
    if (!in_array($action, ['IN','OUT'], true)) bad('invalid_action', 400);
    if (!isset($_FILES['photo'])) bad('missing_file', 400);

    // Kiosk auth (mirror punch.php)
    if ($kioskCode !== setting($pdo,'kiosk_code','')) bad('kiosk_not_authorized', 403);
    if (!setting_bool($pdo,'is_paired', false)) bad('kiosk_not_paired', 403);

    if ($deviceTok === '') bad('device_not_authorized', 403);

    $expectedHash = (string)setting($pdo,'paired_device_token_hash','');
    if ($expectedHash !== '') {
        $calc = hash('sha256', $deviceTok);
        if (!hash_equals($expectedHash, $calc)) bad('device_not_authorized', 403);
    } else {
        $legacy = (string)setting($pdo,'paired_device_token','');
        if ($legacy === '' || !hash_equals($legacy, $deviceTok)) bad('device_not_authorized', 403);
    }

    $serverVer = (int)setting($pdo,'pairing_version','0');
    if ($serverVer > 0 && $versionHdr > 0 && $serverVer !== $versionHdr) {
        bad('device_revoked', 403);
    }

    $f = $_FILES['photo'];
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        bad('upload_failed', 400);
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) bad('upload_failed', 400);

    // File constraints
    $maxBytes = 5 * 1024 * 1024; // 5MB
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) bad('upload_failed', 400);
    if ($size > $maxBytes) bad('file_too_large', 413);

    // Validate MIME
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!in_array($mime, ['image/jpeg','image/jpg'], true)) {
        bad('invalid_file_type', 415);
    }

    // Ensure punch exists (retryable if not yet synced)
    $stmt = $pdo->prepare("SELECT id FROM kiosk_punch_events WHERE event_uuid = ? LIMIT 1");
    $stmt->execute([$eventUuid]);
    $punchId = (int)($stmt->fetchColumn() ?: 0);
    if (!$punchId) {
        // Let client retry later (punch might still be in offline queue)
        bad('no_matching_punch', 409);
    }

    // Determine uploads base path (configurable) and resolve to filesystem path.
    // For portability across duplicated installs (/yyy/), store uploads_base_path as
    // a RELATIVE path like "uploads".
    $baseCfg = (string)setting($pdo, 'uploads_base_path', 'auto');
    $base = resolve_uploads_base_path($baseCfg);

    $dateFolder = gmdate('Y-m-d');
    // Store relative path in DB (relative to uploads_base_path)
    $relDir = 'kiosk_photos/' . $dateFolder;
    $absDir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);

    if (!is_dir($absDir)) {
        @mkdir($absDir, 0775, true);
    }
    if (!is_dir($absDir) || !is_writable($absDir)) {
        bad('upload_dir_not_writable', 500);
    }

    $fileName = $eventUuid . '.jpg';
    $absPath = $absDir . DIRECTORY_SEPARATOR . $fileName;
    $relPath = $relDir . '/' . $fileName;

    if (!@move_uploaded_file($tmp, $absPath)) {
        bad('file_write_failed', 500);
    }

    // Store DB row (idempotent by event_uuid unique)
    $stmt = $pdo->prepare("
        INSERT INTO kiosk_punch_photos
          (event_uuid, action, device_id, device_name, photo_path, created_at)
        VALUES
          (?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
          action = VALUES(action),
          device_id = VALUES(device_id),
          device_name = VALUES(device_name),
          photo_path = VALUES(photo_path)
    ");

    $stmt->execute([
        $eventUuid,
        $action,
        $deviceIdHdr !== '' ? $deviceIdHdr : null,
        $deviceNameHdr !== '' ? $deviceNameHdr : null,
        $relPath,
    ]);

    json_response([
        'ok' => true,
        'status' => 'stored',
        'event_uuid' => $eventUuid,
        'path' => $relPath,
    ]);

} catch (Throwable $e) {
    // Do not leak details
    json_response(['ok' => false, 'error' => 'server_error'], 500);
}
