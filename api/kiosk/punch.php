<?php
require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true);

$eventUuid  = $input['event_uuid'] ?? null;
$action     = $input['action'] ?? null;
$pin        = $input['pin'] ?? null;
$deviceTime = $input['device_time'] ?? null;

$kioskCode = $_SERVER['HTTP_X_KIOSK_CODE'] ?? null;

if (!$eventUuid || !$action || !$pin || !$deviceTime || !$kioskCode) {
    json_response(['ok' => false, 'error' => 'missing_fields'], 400);
}

/* ---------- SETTINGS (PIN LENGTH) ---------- */
$pinLength = setting_int($pdo, 'pin_length', 4); // later load from settings table

if (!valid_pin($pin, $pinLength)) {
    json_response(['ok' => false, 'error' => 'invalid_pin_format'], 400);
}

/* ---------- KIOSK ---------- */
$stmt = $pdo->prepare("SELECT id FROM kiosks WHERE kiosk_code = ? AND is_active = 1");
$stmt->execute([$kioskCode]);
$kiosk = $stmt->fetch();

if (!$kiosk) {
    json_response(['ok' => false, 'error' => 'kiosk_not_authorized'], 403);
}

$kioskId = $kiosk['id'];

/* ---------- DUPLICATE CHECK ---------- */
$stmt = $pdo->prepare("SELECT id FROM punch_events WHERE event_uuid = ?");
$stmt->execute([$eventUuid]);

if ($stmt->fetch()) {
    json_response(['ok' => true, 'status' => 'duplicate']);
}

/* ---------- EMPLOYEE ---------- */
/* ---------- EMPLOYEE ---------- */
// IMPORTANT: select pin_hash so we can verify it
$stmt = $pdo->prepare("SELECT id, first_name, last_name, pin_hash FROM employees WHERE is_active = 1");
$stmt->execute();

$employee = null;

while ($row = $stmt->fetch()) {
    $stored = trim((string)($row['pin_hash'] ?? ''));

    if ($stored === '') {
        continue;
    }

    // Dual mode:
    // 1) bcrypt hash (preferred)
    // 2) plain PIN stored in pin_hash (temporary / dev)
    $isBcrypt = (strpos($stored, '$2y$') === 0 || strpos($stored, '$2a$') === 0 || strpos($stored, '$2b$') === 0);

    $pinValid = $isBcrypt
        ? password_verify($pin, $stored)
        : hash_equals($stored, (string)$pin);

    if ($pinValid) {
        $employee = $row;
        break;
    }
}

if (!$employee) {
    json_response(['ok' => false, 'error' => 'invalid_pin'], 401);
}

$employeeId = $employee['id'];


/* ---------- TIME ---------- */
$receivedAt  = now_utc();
$effectiveAt = $receivedAt;

/* ---------- SAVE PUNCH EVENT ---------- */
$stmt = $pdo->prepare("
    INSERT INTO punch_events
    (event_uuid, kiosk_id, employee_id, action, device_time, received_at, effective_time)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $eventUuid,
    $kioskId,
    $employeeId,
    $action,
    $deviceTime,
    $receivedAt,
    $effectiveAt
]);

/* ---------- SHIFT LOGIC ---------- */
if ($action === 'IN') {

    $stmt = $pdo->prepare("
        SELECT id FROM shifts
        WHERE employee_id = ? AND is_closed = 0
        LIMIT 1
    ");
    $stmt->execute([$employeeId]);

    if ($stmt->fetch()) {
        json_response(['ok' => false, 'error' => 'already_clocked_in']);
    }

    $stmt = $pdo->prepare("
        INSERT INTO shifts (employee_id, kiosk_id, clock_in_at)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$employeeId, $kioskId, $effectiveAt]);

    json_response([
        'ok' => true,
        'status' => 'processed',
        'action' => 'IN',
        'employee' => $employee['first_name'] . ' ' . $employee['last_name'],
        'time' => $effectiveAt
    ]);

}

/* ---------- CLOCK OUT ---------- */
if ($action === 'OUT') {

    $stmt = $pdo->prepare("
        SELECT id FROM shifts
        WHERE employee_id = ? AND is_closed = 0
        ORDER BY clock_in_at DESC
        LIMIT 1
    ");
    $stmt->execute([$employeeId]);
    $shift = $stmt->fetch();

    if (!$shift) {
        json_response(['ok' => false, 'error' => 'no_open_shift']);
    }

    $stmt = $pdo->prepare("
        UPDATE shifts
        SET clock_out_at = ?, is_closed = 1
        WHERE id = ?
    ");
    $stmt->execute([$effectiveAt, $shift['id']]);

    json_response([
        'ok' => true,
        'status' => 'processed',
        'action' => 'OUT',
        'employee' => $employee['first_name'] . ' ' . $employee['last_name'],
        'time' => $effectiveAt
    ]);
}
