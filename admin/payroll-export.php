<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'export_payroll');

// This file outputs a CSV.
// Query params match payroll.php

function p_str(string $k, string $default=''): string {
  return trim((string)($_GET[$k] ?? $default));
}

$from = p_str('from', '');
$to = p_str('to', '');
// Match payroll.php query param name
$includeUnapproved = (int)($_GET['include_unapproved'] ?? 0) === 1;

if ($from === '' || $to === '') {
  http_response_code(400);
  exit('Missing date range');
}

// Payroll users can only export approved shifts.
if ((string)($user['role'] ?? '') === 'payroll') {
  $includeUnapproved = false;
}

$roundingEnabled = admin_setting_bool($pdo, 'rounding_enabled', true);
$roundInc = admin_setting_int($pdo, 'round_increment_minutes', 15);
$roundGrace = admin_setting_int($pdo, 'round_grace_minutes', 5);

// Build datetime range
$fromDt = $from . ' 00:00:00';
$toDt   = $to   . ' 00:00:00';

// Load shifts
$sql = "
SELECT
  s.*,
  e.employee_code, e.first_name, e.last_name, e.nickname, e.is_agency, e.agency_label,
  p.break_minutes_default, p.break_is_paid, p.min_hours_for_break, p.contract_hours_per_week,
  (
    SELECT sc.new_json
    FROM kiosk_shift_changes sc
    WHERE sc.shift_id = s.id AND sc.change_type = 'edit'
    ORDER BY sc.id DESC
    LIMIT 1
  ) AS latest_edit_json
FROM kiosk_shifts s
JOIN kiosk_employees e ON e.id = s.employee_id
LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = e.id
WHERE s.clock_in_at >= :from_dt AND s.clock_in_at < :to_dt
";

if (!$includeUnapproved) {
  $sql .= " AND s.approved_at IS NOT NULL";
}

$sql .= " ORDER BY s.employee_id ASC, s.clock_in_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':from_dt' => $fromDt,
  ':to_dt' => $toDt,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Aggregate per employee
$totals = [];
foreach ($rows as $r) {
  $eff = admin_shift_effective($r);
  $in = $eff['clock_in_at'] ?: null;
  $out = $eff['clock_out_at'] ?: null;
  if (!$in || !$out) continue;

  // rounding
  $rin = $in;
  $rout = $out;
  if ($roundingEnabled) {
    $rin = admin_round_datetime($in, $roundInc, $roundGrace) ?: $in;
    $rout = admin_round_datetime($out, $roundInc, $roundGrace) ?: $out;
  }

  $worked = admin_minutes_between($rin, $rout);
  if ($worked === null || $worked <= 0) continue;

  $breakDefault = (int)($r['break_minutes_default'] ?? 0);
  $break = $eff['break_minutes'] !== null ? (int)$eff['break_minutes'] : $breakDefault;
  $breakIsPaid = (int)($r['break_is_paid'] ?? 0) === 1;
  $minHours = $r['min_hours_for_break'] !== null ? (float)$r['min_hours_for_break'] : 0.0;

  if ($minHours > 0) {
    $hours = $worked / 60;
    if ($hours < $minHours) {
      $break = 0;
    }
  }

  $paid = $worked;
  if (!$breakIsPaid) {
    $paid = max(0, $worked - max(0, $break));
  }

  $empId = (int)$r['employee_id'];
  // Canonical display name: nickname if present, else first + last.
  // Agency uses agency_label.
  $nick = trim((string)($r['nickname'] ?? ''));
  $firstLast = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
  $name = ($nick !== '') ? $nick : $firstLast;
  if ((int)($r['is_agency'] ?? 0) === 1) {
    $name = trim((string)($r['agency_label'] ?? 'Agency'));
  }

  if (!isset($totals[$empId])) {
    $totals[$empId] = [
      'employee_id' => $empId,
      'employee_code' => (string)($r['employee_code'] ?? ''),
      'name' => $name,
      'type' => ((int)($r['is_agency'] ?? 0) === 1) ? 'Agency' : 'Staff',
      'contract_hours_per_week' => $r['contract_hours_per_week'] ?? null,
      'worked_minutes' => 0,
      'unpaid_break_minutes' => 0,
      'paid_minutes' => 0,
      'shift_count' => 0,
    ];
  }

  $totals[$empId]['worked_minutes'] += $worked;
  if (!$breakIsPaid) $totals[$empId]['unpaid_break_minutes'] += max(0, $break);
  $totals[$empId]['paid_minutes'] += $paid;
  $totals[$empId]['shift_count'] += 1;
}

/**
 * PAYROLL LOCKING
 * - After export, lock shifts in this range (approved only unless include_unapproved=1)
 * - Never lock already-locked shifts
 * - Attempt to write an audit entry; if schema/enum doesn't support it yet, ignore and still lock
 */
try {
  // Build a payroll batch id for traceability
  $batchId = 'PAY-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));

  // Collect shift ids we are about to lock (only those not already locked)
  $idsSql = "SELECT id FROM kiosk_shifts WHERE clock_in_at >= ? AND clock_in_at < ? AND payroll_locked_at IS NULL";
  $idsParams = [$fromDt, $toDt];
  if (!$includeUnapproved) {
    $idsSql .= " AND approved_at IS NOT NULL";
  }

  $idsStmt = $pdo->prepare($idsSql);
  $idsStmt->execute($idsParams);
  $shiftIds = $idsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

  if (!empty($shiftIds)) {
    $pdo->beginTransaction();
    try {
      // Lock shifts
      $lockSql = "
        UPDATE kiosk_shifts
        SET payroll_locked_at = UTC_TIMESTAMP,
            payroll_locked_by = ?,
            payroll_batch_id  = ?
        WHERE clock_in_at >= ? AND clock_in_at < ?
          AND payroll_locked_at IS NULL
      ";
      $lockParams = [
        (string)($user['username'] ?? 'system'),
        $batchId,
        $fromDt,
        $toDt,
      ];
      if (!$includeUnapproved) {
        $lockSql .= " AND approved_at IS NOT NULL";
      }

      $lockStmt = $pdo->prepare($lockSql);
      $lockStmt->execute($lockParams);

      // Audit (best effort)
      // Note: requires kiosk_shift_changes exists and enum includes payroll_lock
      $auditStmt = null;
      try {
        $auditStmt = $pdo->prepare("
          INSERT INTO kiosk_shift_changes
            (shift_id, change_type, changed_by_user_id, changed_by_username, changed_by_role, reason, note, old_json, new_json)
          VALUES
            (?, 'payroll_lock', ?, ?, ?, 'payroll_lock', ?, NULL, ?)
        ");
      } catch (Throwable $e) {
        $auditStmt = null;
      }

      if ($auditStmt) {
        $meta = [
          'batch_id' => $batchId,
          'range' => ['from' => $from, 'to' => $to],
          'include_unapproved' => $includeUnapproved ? 1 : 0,
          'source' => 'payroll-export',
        ];
        $metaJson = json_encode($meta);

        foreach ($shiftIds as $sid) {
          $auditStmt->execute([
            (int)$sid,
            (int)($user['user_id'] ?? 0),
            (string)($user['username'] ?? ''),
            (string)($user['role'] ?? ''),
            'Locked by payroll export',
            $metaJson,
          ]);
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      // Do not block export if lock fails.
    }
  }
} catch (Throwable $e) {
  // Do not block export if lock fails.
}

// Output
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payroll_' . $from . '_to_' . $to . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Employee ID','Employee Code','Name','Type','Shifts','Contract (hrs/wk)','Worked (hrs)','Unpaid break (hrs)','Paid (hrs)']);

ksort($totals);
foreach ($totals as $t) {
  $workedH = round($t['worked_minutes'] / 60, 2);
  $breakH = round($t['unpaid_break_minutes'] / 60, 2);
  $paidH = round($t['paid_minutes'] / 60, 2);
  fputcsv($out, [
    (string)$t['employee_id'],
    (string)($t['employee_code'] ?? ''),
    $t['name'],
    $t['type'],
    (string)$t['shift_count'],
    $t['contract_hours_per_week'] !== null ? (string)$t['contract_hours_per_week'] : '',
    (string)$workedH,
    (string)$breakH,
    (string)$paidH,
  ]);
}

fclose($out);
exit;
