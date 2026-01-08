<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// payload
$eventUuid  = (string)($input['event_uuid'] ?? '');
$action     = strtoupper((string)($input['action'] ?? ''));
$pin        = (string)($input['pin'] ?? '');
$deviceTime = (string)($input['device_time'] ?? '');

// headers
$kioskCode  = (string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? '');
$deviceTok  = (string)($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
$versionHdr = (int)($_SERVER['HTTP_X_PAIRING_VERSION'] ?? 0);

// useful request meta
$ipAddress  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$tokHash    = $deviceTok !== '' ? hash('sha256', $deviceTok) : null;

$now = now_utc(); // server truth time (Y-m-d H:i:s)

// ---- helper: parse ISO8601 device_time into MySQL DATETIME (UTC) ----
function parse_device_time_to_sql(string $deviceTime): ?string {
    $deviceTime = trim($deviceTime);
    if ($deviceTime === '') return null;

    // Accept common kiosk formats:
    // - ISO8601 from JS: 2026-01-08T21:30:12.123Z
    // - ISO8601 without millis: 2026-01-08T21:30:12Z
    // - MySQL DATETIME already: 2026-01-08 21:30:12
    try {
        // If it already looks like MySQL DATETIME, trust it.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $deviceTime)) {
            return $deviceTime;
        }

        $dt = new DateTimeImmutable($deviceTime);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

// ---- helper: log punch attempt result (update row) ----
function punch_mark(PDO $pdo, string $eventUuid, string $status, ?string $errorCode = null, ?int $shiftId = null): void {
    $stmt = $pdo->prepare("
        UPDATE punch_events
        SET result_status = ?, error_code = ?, shift_id = ?
        WHERE event_uuid = ?
        LIMIT 1
    ");
    $stmt->execute([$status, $errorCode, $shiftId, $eventUuid]);
}

// ---- basic required ----
if ($eventUuid === '' || $action === '' || $pin === '' || $deviceTime === '') {
    json_response(['ok'=>false,'error'=>'missing_fields'], 400);
}

// device_time must be parseable into MySQL DATETIME
$deviceTimeSql = parse_device_time_to_sql($deviceTime);
if (!$deviceTimeSql) {
    json_response(['ok'=>false,'error'=>'invalid_device_time'], 400);
}

// action validation
if (!in_array($action, ['IN','OUT'], true)) {
    json_response(['ok'=>false,'error'=>'invalid_action'], 400);
}

// kiosk auth
if ($kioskCode !== (string)setting($pdo,'kiosk_code','')) {
    json_response(['ok'=>false,'error'=>'kiosk_not_authorized'], 403);
}

if ((string)setting($pdo,'is_paired','0') !== '1') {
    json_response(['ok'=>false,'error'=>'kiosk_not_paired'], 403);
}

$expectedTok = (string)setting($pdo,'paired_device_token','');
if ($expectedTok === '' || $deviceTok === '' || !hash_equals($expectedTok, $deviceTok)) {
    json_response(['ok'=>false,'error'=>'device_not_authorized'], 403);
}

if ($versionHdr !== (int)setting($pdo,'pairing_version','1')) {
    json_response(['ok'=>false,'error'=>'device_revoked'], 403);
}

// PIN format
$pinLength = setting_int($pdo,'pin_length', 4);
if (!valid_pin($pin, $pinLength)) {
    json_response(['ok'=>false,'error'=>'invalid_pin_format'], 400);
}

// duplicate UUID (idempotent)
$chk = $pdo->prepare("SELECT id FROM punch_events WHERE event_uuid=? LIMIT 1");
$chk->execute([$eventUuid]);
if ($chk->fetchColumn()) {
    json_response(['ok'=>true,'status'=>'duplicate']);
}

// employee lookup
$allowPlain = setting_bool($pdo,'allow_plain_pin', false);

$stmt = $pdo->query("SELECT id, first_name, last_name, pin_hash FROM employees WHERE is_active=1");
$employee = null;

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stored = (string)($r['pin_hash'] ?? '');
    if ($stored === '') continue;

    $ok = false;
    if (is_bcrypt($stored)) {
        $ok = password_verify($pin, $stored);
    } elseif ($allowPlain) {
        $ok = hash_equals($stored, $pin);
    }

    if ($ok) { $employee = $r; break; }
}

if (!$employee) {
    // NOTE: we do NOT log invalid pin attempts into punch_events because your table requires employee_id NOT NULL.
    // If you want to log invalid pin attempts too, we’ll add employee_id nullable or a separate table later.
    json_response(['ok'=>false,'error'=>'invalid_pin'], 401);
}

$employeeId = (int)$employee['id'];
$employeeName = trim(((string)($employee['first_name'] ?? '')) . ' ' . ((string)($employee['last_name'] ?? '')));

// anti-spam: min seconds between punches
$minSeconds = setting_int($pdo,'min_seconds_between_punches', 20);
if ($minSeconds > 0) {
    $lastStmt = $pdo->prepare("
        SELECT effective_time
        FROM punch_events
        WHERE employee_id=?
        ORDER BY id DESC
        LIMIT 1
    ");
    $lastStmt->execute([$employeeId]);
    $last = $lastStmt->fetchColumn();

    if ($last) {
        $diffStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, ?) AS diff_sec");
        $diffStmt->execute([$last, $now]);
        $diff = (int)$diffStmt->fetchColumn();

        if ($diff >= 0 && $diff < $minSeconds) {
            // log attempt as rejected
            // Insert first, then mark rejected
            $pdo->prepare("
                INSERT INTO punch_events
                (event_uuid, employee_id, action, device_time, received_at, effective_time,
                 result_status, error_code, kiosk_code, device_token_hash, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, 'received', NULL, ?, ?, ?, ?)
            ")->execute([$eventUuid,$employeeId,$action,$deviceTimeSql,$now,$now,$kioskCode,$tokHash,$ipAddress,$userAgent]);

            punch_mark($pdo, $eventUuid, 'rejected', 'too_soon', null);
            json_response(['ok'=>false,'error'=>'too_soon'], 429);
        }
    }
}

// ---- INSERT the punch attempt FIRST (always) ----
$pdo->prepare("
    INSERT INTO punch_events
    (event_uuid, employee_id, action, device_time, received_at, effective_time,
     result_status, error_code, kiosk_code, device_token_hash, ip_address, user_agent)
    VALUES (?, ?, ?, ?, ?, ?, 'received', NULL, ?, ?, ?, ?)
")->execute([
    $eventUuid,
    $employeeId,
    $action,
    $deviceTimeSql,
    $now,
    $now,
    $kioskCode,
    $tokHash,
    $ipAddress,
    $userAgent
]);

// ---- Apply shift logic (and UPDATE the punch row accordingly) ----
try {

    if ($action === 'IN') {

        $open = $pdo->prepare("SELECT id FROM shifts WHERE employee_id=? AND is_closed=0 LIMIT 1");
        $open->execute([$employeeId]);

        if ($open->fetchColumn()) {
            punch_mark($pdo, $eventUuid, 'rejected', 'already_clocked_in', null);
            json_response(['ok'=>false,'error'=>'already_clocked_in'], 409);
        }

        $pdo->prepare("INSERT INTO shifts (employee_id, clock_in_at, is_closed) VALUES (?, ?, 0)")
            ->execute([$employeeId, $now]);

        $shiftId = (int)$pdo->lastInsertId();

        punch_mark($pdo, $eventUuid, 'processed', null, $shiftId);
        json_response([
  'ok' => true,
  'status' => 'processed',
  'action' => 'IN',
  'employee_name' => $employeeName
]);
    }

    if ($action === 'OUT') {

        $shift = $pdo->prepare("
            SELECT id, clock_in_at
            FROM shifts
            WHERE employee_id=? AND is_closed=0
            ORDER BY clock_in_at DESC
            LIMIT 1
        ");
        $shift->execute([$employeeId]);
        $s = $shift->fetch(PDO::FETCH_ASSOC);

        if (!$s) {
            punch_mark($pdo, $eventUuid, 'rejected', 'no_open_shift', null);
            json_response(['ok'=>false,'error'=>'no_open_shift'], 409);
        }

        $minsStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, ?) AS mins");
        $minsStmt->execute([$s['clock_in_at'], $now]);
        $mins = (int)$minsStmt->fetchColumn();

        if ($mins < 0) {
            punch_mark($pdo, $eventUuid, 'rejected', 'invalid_time_order', null);
            json_response(['ok'=>false,'error'=>'invalid_time_order'], 409);
        }

        $maxMins = setting_int($pdo,'max_shift_minutes', 960);
        if ($maxMins > 0 && $mins > $maxMins) {
            punch_mark($pdo, $eventUuid, 'rejected', 'shift_too_long_needs_review', null);
            json_response(['ok'=>false,'error'=>'shift_too_long_needs_review'], 409);
        }

        $upd = $pdo->prepare("
            UPDATE shifts
            SET clock_out_at=?, is_closed=1, duration_minutes=?
            WHERE id=?
        ");
        $upd->execute([$now, $mins, (int)$s['id']]);

        punch_mark($pdo, $eventUuid, 'processed', null, (int)$s['id']);
        json_response([
  'ok' => true,
  'status' => 'processed',
  'action' => 'OUT',
  'employee_name' => $employeeName
]);
    }

    // should never reach here
    punch_mark($pdo, $eventUuid, 'rejected', 'invalid_action', null);
    json_response(['ok'=>false,'error'=>'invalid_action'], 400);

} catch (Throwable $e) {
    // if something unexpected happens, mark it as rejected server_error
    punch_mark($pdo, $eventUuid, 'rejected', 'server_error', null);
    json_response(['ok'=>false,'error'=>'server_error'], 500);
}
