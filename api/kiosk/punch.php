<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$eventUuid  = $input['event_uuid']  ?? null;
$action     = strtoupper((string)($input['action'] ?? ''));
$pin        = (string)($input['pin'] ?? '');
$deviceTime = (string)($input['device_time'] ?? '');

$kioskCode  = trim((string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? ''));
$deviceTok  = trim((string)($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ''));

if (!$eventUuid || !$action || !$pin || !$deviceTime) {
    json_response(['ok'=>false,'error'=>'missing_fields'], 400);
}

if (!in_array($action, ['IN','OUT'], true)) {
    json_response(['ok'=>false,'error'=>'invalid_action'], 400);
}

/** kiosk gate */
if ($kioskCode === '' || $kioskCode !== (string)setting($pdo, 'kiosk_code', '')) {
    json_response(['ok'=>false,'error'=>'kiosk_not_authorized'], 403);
}

/** must be paired */
if (setting($pdo, 'is_paired', '0') !== '1') {
    json_response(['ok'=>false,'error'=>'kiosk_not_paired'], 403);
}

/** device token must match */
$expectedToken = (string)setting($pdo, 'paired_device_token', '');
if ($expectedToken === '' || $deviceTok === '' || !hash_equals($expectedToken, $deviceTok)) {
    json_response(['ok'=>false,'error'=>'device_not_authorized'], 403);
}

/** PIN format */
$pinLength = setting_int($pdo, 'pin_length', 4);
if (!valid_pin($pin, $pinLength)) {
    json_response(['ok'=>false,'error'=>'invalid_pin_format'], 400);
}

/** duplicate UUID (idempotent) */
$stmt = $pdo->prepare("SELECT id FROM punch_events WHERE event_uuid=? LIMIT 1");
$stmt->execute([$eventUuid]);
if ($stmt->fetch()) {
    json_response(['ok'=>true,'status'=>'duplicate']);
}

/** employee lookup (dual mode: bcrypt or plain if allowed) */
$allowPlain = setting_bool($pdo, 'allow_plain_pin', true);

$stmt = $pdo->query("SELECT id, first_name, last_name, pin_hash FROM employees WHERE is_active=1 ORDER BY id ASC");

$employee = null;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stored = trim((string)($row['pin_hash'] ?? ''));
    if ($stored === '') continue;

    $ok = false;
    if (is_bcrypt_hash($stored)) {
        $ok = password_verify($pin, $stored);
    } elseif ($allowPlain) {
        $ok = hash_equals($stored, $pin);
    }

    if ($ok) { $employee = $row; break; }
}

if (!$employee) {
    json_response(['ok'=>false,'error'=>'invalid_pin'], 401);
}

$employeeId = (int)$employee['id'];

/** anti-spam: min seconds between punches */
$minSeconds = setting_int($pdo, 'min_seconds_between_punches', 20);
$now = now_utc();

$stmt = $pdo->prepare("SELECT effective_time FROM punch_events WHERE employee_id=? ORDER BY id DESC LIMIT 1");
$stmt->execute([$employeeId]);
$last = $stmt->fetchColumn();

if ($last) {
    $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, ?) AS diff_sec");
    $stmt->execute([$last, $now]);
    $diff = (int)$stmt->fetchColumn();

    if ($diff >= 0 && $diff < $minSeconds) {
        json_response(['ok'=>false,'error'=>'too_soon'], 429);
    }
}

/** transaction: punch_event + shift logic */
$maxShiftMinutes = setting_int($pdo, 'max_shift_minutes', 960);

try {
    $pdo->beginTransaction();

    // insert punch event (audit trail)
    $ins = $pdo->prepare("
        INSERT INTO punch_events (event_uuid, employee_id, action, device_time, received_at, effective_time)
        VALUES (?,?,?,?,?,?)
    ");
    $ins->execute([$eventUuid, $employeeId, $action, $deviceTime, $now, $now]);

    if ($action === 'IN') {

        $open = $pdo->prepare("SELECT id FROM shifts WHERE employee_id=? AND is_closed=0 LIMIT 1");
        $open->execute([$employeeId]);

        if ($open->fetch()) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'already_clocked_in'], 409);
        }

        $pdo->prepare("INSERT INTO shifts (employee_id, clock_in_at, is_closed) VALUES (?,?,0)")
            ->execute([$employeeId, $now]);

        $pdo->commit();

        json_response([
            'ok'=>true,
            'status'=>'processed',
            'action'=>'IN',
            'employee'=>$employee['first_name'].' '.$employee['last_name'],
            'time'=>$now
        ]);
    }

    if ($action === 'OUT') {

        $shiftStmt = $pdo->prepare("
            SELECT id, clock_in_at
            FROM shifts
            WHERE employee_id=? AND is_closed=0
            ORDER BY clock_in_at DESC
            LIMIT 1
        ");
        $shiftStmt->execute([$employeeId]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'no_open_shift'], 409);
        }

        $minsStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, ?) AS mins");
        $minsStmt->execute([$shift['clock_in_at'], $now]);
        $mins = (int)$minsStmt->fetchColumn();

        if ($mins < 0) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'invalid_time_order'], 409);
        }

        // do NOT auto-close; require review if too long
        if ($mins > $maxShiftMinutes) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'shift_too_long_needs_review','minutes'=>$mins], 409);
        }

        $upd = $pdo->prepare("UPDATE shifts SET clock_out_at=?, is_closed=1, duration_minutes=? WHERE id=?");
        $upd->execute([$now, $mins, $shift['id']]);

        $pdo->commit();

        json_response([
            'ok'=>true,
            'status'=>'processed',
            'action'=>'OUT',
            'employee'=>$employee['first_name'].' '.$employee['last_name'],
            'time'=>$now
        ]);
    }

    $pdo->rollBack();
    json_response(['ok'=>false,'error'=>'invalid_action'], 400);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'error'=>'server_error'], 500);
}
