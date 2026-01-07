<?php
require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$eventUuid  = $input['event_uuid'] ?? null;
$action     = strtoupper($input['action'] ?? '');
$pin        = (string)($input['pin'] ?? '');
$deviceTime = (string)($input['device_time'] ?? '');

$kioskCode  = $_SERVER['HTTP_X_KIOSK_CODE'] ?? '';
$deviceTok  = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
$versionHdr = (int)($_SERVER['HTTP_X_PAIRING_VERSION'] ?? 0);

if (!$eventUuid || !$action || !$pin || !$deviceTime) {
    json_response(['ok'=>false,'error'=>'missing_fields'],400);
}

if ($kioskCode !== setting($pdo,'kiosk_code','')) {
    json_response(['ok'=>false,'error'=>'kiosk_not_authorized'],403);
}

if (setting($pdo,'is_paired','0') !== '1') {
    json_response(['ok'=>false,'error'=>'kiosk_not_paired'],403);
}

if (!hash_equals(setting($pdo,'paired_device_token',''), $deviceTok)) {
    json_response(['ok'=>false,'error'=>'device_not_authorized'],403);
}

if ($versionHdr !== (int)setting($pdo,'pairing_version','1')) {
    json_response(['ok'=>false,'error'=>'device_revoked'],403);
}

$pinLength = setting_int($pdo,'pin_length',4);
if (!valid_pin($pin,$pinLength)) {
    json_response(['ok'=>false,'error'=>'invalid_pin_format'],400);
}

/* Duplicate UUID */
$chk = $pdo->prepare("SELECT id FROM punch_events WHERE event_uuid=?");
$chk->execute([$eventUuid]);
if ($chk->fetch()) {
    json_response(['ok'=>true,'status'=>'duplicate']);
}

/* Employee lookup */
$allowPlain = setting_bool($pdo,'allow_plain_pin',true);
$stmt = $pdo->query("SELECT id,first_name,last_name,pin_hash FROM employees WHERE is_active=1");

$employee = null;
while ($r = $stmt->fetch()) {
    if (is_bcrypt($r['pin_hash'])) {
        if (password_verify($pin,$r['pin_hash'])) { $employee=$r; break; }
    } elseif ($allowPlain && hash_equals($r['pin_hash'],$pin)) {
        $employee=$r; break;
    }
}

if (!$employee) {
    json_response(['ok'=>false,'error'=>'invalid_pin'],401);
}

$employeeId = (int)$employee['id'];
$now = now_utc();

/* Insert punch */
$pdo->beginTransaction();

$pdo->prepare("
  INSERT INTO punch_events
  (event_uuid,employee_id,action,device_time,received_at,effective_time)
  VALUES (?,?,?,?,?,?)
")->execute([$eventUuid,$employeeId,$action,$deviceTime,$now,$now]);

if ($action === 'IN') {

    $open = $pdo->prepare("SELECT id FROM shifts WHERE employee_id=? AND is_closed=0");
    $open->execute([$employeeId]);
    if ($open->fetch()) {
        $pdo->rollBack();
        json_response(['ok'=>false,'error'=>'already_clocked_in'],409);
    }

    $pdo->prepare("INSERT INTO shifts (employee_id,clock_in_at,is_closed) VALUES (?,?,0)")
        ->execute([$employeeId,$now]);

    $pdo->commit();
    json_response(['ok'=>true,'status'=>'processed','action'=>'IN']);
}

if ($action === 'OUT') {

    $shift = $pdo->prepare("
      SELECT id,clock_in_at FROM shifts
      WHERE employee_id=? AND is_closed=0
      ORDER BY clock_in_at DESC LIMIT 1
    ");
    $shift->execute([$employeeId]);
    $s = $shift->fetch();

    if (!$s) {
        $pdo->rollBack();
        json_response(['ok'=>false,'error'=>'no_open_shift'],409);
    }

    $mins = (int)$pdo->query("
      SELECT TIMESTAMPDIFF(MINUTE,'{$s['clock_in_at']}','$now')
    ")->fetchColumn();

    if ($mins > setting_int($pdo,'max_shift_minutes',960)) {
        $pdo->rollBack();
        json_response(['ok'=>false,'error'=>'shift_too_long_needs_review'],409);
    }

    $pdo->prepare("
      UPDATE shifts SET clock_out_at=?,is_closed=1,duration_minutes=?
      WHERE id=?
    ")->execute([$now,$mins,$s['id']]);

    $pdo->commit();
    json_response(['ok'=>true,'status'=>'processed','action'=>'OUT']);
}

$pdo->rollBack();
json_response(['ok'=>false,'error'=>'invalid_action'],400);
