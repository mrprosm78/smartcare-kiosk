<?php
declare(strict_types=1);

require_once __DIR__ . '/../layout.php';

// Always require login via layout.php bootstrap.
// GET: view_employees
// POST: manage_employees

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    admin_require_perm($user, 'view_employees');

    $id = (int)($_GET['id'] ?? 0);
    $isNew = ($id <= 0);

    $employee = [
      'id' => 0,
      'employee_code' => '',
      'first_name' => '',
      'last_name' => '',
      'nickname' => '',
      'hr_staff_id' => null,
      'department_id' => null,
      'team_id' => null,
      'is_agency' => 0,
      'agency_label' => '',
      'is_active' => 1,
    ];

    if ($isNew && (string)($_GET['agency'] ?? '') === '1') {
      $employee['is_agency'] = 1;
      $employee['agency_label'] = 'Agency';
    }

    if (!$isNew) {
      $stmt = $pdo->prepare('SELECT id, employee_code, first_name, last_name, nickname, hr_staff_id, department_id, team_id, is_agency, agency_label, is_active
                             FROM kiosk_employees WHERE id=? LIMIT 1');
      $stmt->execute([$id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row) json_out(['ok' => false, 'error' => 'Employee not found'], 404);
      $employee = array_merge($employee, $row);
    }

    json_out([
      'ok' => true,
      'employee' => $employee,
      'can_edit_contract' => admin_can($user, 'edit_contract'),
      'can_manage_employees' => admin_can($user, 'manage_employees'),
    ]);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_perm($user, 'manage_employees');
    admin_verify_csrf($_POST['csrf'] ?? null);

    $id = (int)($_POST['id'] ?? 0);
    $isNew = ($id <= 0);

    $employee_code = trim((string)($_POST['employee_code'] ?? ''));
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    $nick = trim((string)($_POST['nickname'] ?? ''));
    $hr_staff_id = (int)($_POST['hr_staff_id'] ?? 0);
    $hr_staff_id = $hr_staff_id > 0 ? $hr_staff_id : null;
    $department_id = (int)($_POST['department_id'] ?? 0);
    $department_id = $department_id > 0 ? $department_id : null;

    $is_agency = (int)($_POST['is_agency'] ?? 0) === 1 ? 1 : 0;
    $agency_label = trim((string)($_POST['agency_label'] ?? ''));
    $is_active = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($nick === '' && $is_agency === 0) {
      json_out(['ok' => false, 'error' => 'Nickname is required'], 422);
    }
    if ($is_agency === 1 && $agency_label === '') $agency_label = 'Agency';

    // PIN reset (optional)
    $pin = trim((string)($_POST['pin'] ?? ''));
    $pin_hash = null;
    $pin_fingerprint = null;
    if ($pin !== '') {
      if (!preg_match('/^\d{4,10}$/', $pin)) {
        json_out(['ok' => false, 'error' => 'PIN must be 4-10 digits'], 422);
      }
      $pin_hash = password_hash($pin, PASSWORD_BCRYPT);
      $pin_fingerprint = hash('sha256', $pin);

      $chk = $pdo->prepare("SELECT id FROM kiosk_employees WHERE pin_fingerprint = ? AND archived_at IS NULL LIMIT 1");
      $chk->execute([$pin_fingerprint]);
      $existingId = (int)($chk->fetchColumn() ?: 0);
      if ($existingId > 0 && ($isNew || $existingId !== (int)$id)) {
        json_out(['ok' => false, 'error' => 'PIN is already in use by another employee'], 422);
      }
    }

    // Enforce 1 staff ↔ 1 kiosk identity (default). Can be relaxed later.
    if ($hr_staff_id !== null) {
      $chk = $pdo->prepare('SELECT id FROM kiosk_employees WHERE hr_staff_id = ? AND archived_at IS NULL LIMIT 1');
      $chk->execute([$hr_staff_id]);
      $existing = (int)($chk->fetchColumn() ?: 0);
      if ($existing > 0 && ($isNew || $existing !== (int)$id)) {
        json_out(['ok' => false, 'error' => 'That HR staff record is already linked to another kiosk ID'], 422);
      }
    }

    if ($isNew) {
      $stmt = $pdo->prepare(
        'INSERT INTO kiosk_employees (employee_code, first_name, last_name, nickname, hr_staff_id, department_id, team_id, is_agency, agency_label, pin_hash, pin_fingerprint, pin_updated_at, is_active, created_at, updated_at)
         VALUES (:code,:first,:last,:nick,:hr_staff_id,:cat,:team,:ag,:al,:pin,:pinfp, UTC_TIMESTAMP, :active, UTC_TIMESTAMP, UTC_TIMESTAMP)'
      );
      $stmt->execute([
        ':code' => $employee_code !== '' ? $employee_code : null,
        ':first' => $first,
        ':last' => $last,
        ':nick' => $nick !== '' ? $nick : null,
        ':hr_staff_id' => $hr_staff_id,
        ':cat' => $department_id,
        ':team' => null,
        ':ag' => $is_agency,
        ':al' => $agency_label !== '' ? $agency_label : null,
        ':pin' => $pin_hash ?? '',
        ':pinfp' => $pin_fingerprint,
        ':active' => $is_active,
      ]);
      $id = (int)$pdo->lastInsertId();
    } else {
      $sql = 'UPDATE kiosk_employees SET employee_code=:code, first_name=:first, last_name=:last, nickname=:nick, hr_staff_id=:hr_staff_id, department_id=:cat, team_id=:team, is_agency=:ag, agency_label=:al, is_active=:active, updated_at=UTC_TIMESTAMP';
      $params = [
        ':team' => null,
        ':code' => $employee_code !== '' ? $employee_code : null,
        ':first' => $first,
        ':last' => $last,
        ':nick' => $nick !== '' ? $nick : null,
        ':hr_staff_id' => $hr_staff_id,
        ':cat' => $department_id,
        ':ag' => $is_agency,
        ':al' => $agency_label !== '' ? $agency_label : null,
        ':active' => $is_active,
        ':id' => $id,
      ];
      if ($pin_hash !== null) {
        $sql .= ', pin_hash=:pin, pin_fingerprint=:pinfp, pin_updated_at=UTC_TIMESTAMP';
        $params[':pin'] = $pin_hash;
        $params[':pinfp'] = $pin_fingerprint;
      }
      $sql .= ' WHERE id=:id LIMIT 1';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
    }

    // Fetch updated for UI
    $stmt = $pdo->prepare("SELECT e.*, d.name AS department_name
                           FROM kiosk_employees e
                           LEFT JOIN kiosk_employee_departments d ON d.id=e.department_id
                           WHERE e.id=? LIMIT 1");
    $stmt->execute([$id]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    json_out(['ok' => true, 'employee' => $e]);
  }

  json_out(['ok' => false, 'error' => 'Method not allowed'], 405);
} catch (Throwable $ex) {
  json_out(['ok' => false, 'error' => 'Server error: ' . $ex->getMessage()], 500);
}
