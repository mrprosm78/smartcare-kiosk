<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');


// Payroll locking uses existing kiosk_shifts fields: payroll_locked_at, payroll_locked_by, payroll_batch_id.
// This keeps managers from editing a pay period after payroll starts, while preserving audit safety.
// We also keep a small batches table (created lazily) to record processed/exported batches.
function ensure_payroll_batches_table(PDO $pdo): void {
  $sql = "CREATE TABLE IF NOT EXISTS kiosk_payroll_batches (\n"
       . "  id INT AUTO_INCREMENT PRIMARY KEY,\n"
       . "  batch_id VARCHAR(64) NOT NULL UNIQUE,\n"
       . "  period_start_utc DATETIME NOT NULL,\n"
       . "  period_end_utc DATETIME NOT NULL,\n"
       . "  locked_at DATETIME NULL,\n"
       . "  locked_by_username VARCHAR(64) NULL,\n"
       . "  locked_by_user_id INT NULL,\n"
       . "  processed_at DATETIME NULL,\n"
       . "  processed_by_username VARCHAR(64) NULL,\n"
       . "  processed_by_user_id INT NULL,\n"
       . "  note VARCHAR(255) NULL,\n"
       . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
       . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $pdo->exec($sql);
}

function next_payroll_batch_id(PDO $pdo, string $periodLabel): string {
  // periodLabel: YYYY-MM
  $prefix = 'PR-' . str_replace('-', '', $periodLabel) . '-';

  // Prefer batch table (if present)
  try {
    ensure_payroll_batches_table($pdo);
    $stmt = $pdo->prepare("SELECT batch_id FROM kiosk_payroll_batches WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['batch_id'])) {
      $last = (string)$row['batch_id'];
      if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{3})$/', $last, $m)) {
        $n = (int)$m[1] + 1;
        return $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
      }
    }
  } catch (Throwable $e) {
    // ignore
  }

  // Fallback: inspect shifts
  try {
    $stmt = $pdo->prepare("SELECT payroll_batch_id FROM kiosk_shifts WHERE payroll_batch_id LIKE ? ORDER BY payroll_batch_id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['payroll_batch_id'])) {
      $last = (string)$row['payroll_batch_id'];
      if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{3})$/', $last, $m)) {
        $n = (int)$m[1] + 1;
        return $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
      }
    }
  } catch (Throwable $e) {
    // ignore
  }

  return $prefix . '001';
}

function lock_approved_shifts_for_period(PDO $pdo, string $startUtc, string $endUtc, string $batchId, array $user): int {
  // Lock only APPROVED shifts in range, excluding voided.
  $sql = "UPDATE kiosk_shifts
          SET payroll_locked_at = NOW(),
              payroll_locked_by = :by,
              payroll_batch_id = :batch,
              updated_source = 'payroll'
          WHERE approved_at IS NOT NULL
            AND (close_reason IS NULL OR close_reason <> 'void')
            AND clock_in_at < :endUtc
            AND clock_out_at > :startUtc
            AND payroll_locked_at IS NULL";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':by' => (string)($user['username'] ?? 'payroll'),
    ':batch' => $batchId,
    ':startUtc' => $startUtc,
    ':endUtc' => $endUtc,
  ]);
  return $stmt->rowCount();
}

function upsert_batch_lock(PDO $pdo, string $batchId, string $startUtc, string $endUtc, array $user): void {
  ensure_payroll_batches_table($pdo);
  $stmt = $pdo->prepare(
    "INSERT INTO kiosk_payroll_batches
      (batch_id, period_start_utc, period_end_utc, locked_at, locked_by_username, locked_by_user_id)
     VALUES
      (:bid, :ps, :pe, NOW(), :u, :uid)
     ON DUPLICATE KEY UPDATE
      period_start_utc = VALUES(period_start_utc),
      period_end_utc = VALUES(period_end_utc),
      locked_at = NOW(),
      locked_by_username = VALUES(locked_by_username),
      locked_by_user_id = VALUES(locked_by_user_id)"
  );
  $stmt->execute([
    ':bid' => $batchId,
    ':ps' => $startUtc,
    ':pe' => $endUtc,
    ':u' => (string)($user['username'] ?? ''),
    ':uid' => (int)($user['user_id'] ?? 0),
  ]);
}

function mark_batch_processed(PDO $pdo, string $batchId, array $user, ?string $note): void {
  ensure_payroll_batches_table($pdo);
  $stmt = $pdo->prepare(
    "UPDATE kiosk_payroll_batches
        SET processed_at = NOW(),
            processed_by_username = :u,
            processed_by_user_id = :uid,
            note = :note
      WHERE batch_id = :bid"
  );
  $stmt->execute([
    ':u' => (string)($user['username'] ?? ''),
    ':uid' => (int)($user['user_id'] ?? 0),
    ':note' => ($note !== null && trim($note) !== '') ? trim($note) : null,
    ':bid' => $batchId,
  ]);
}

// Handle payroll lock/process actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  admin_verify_csrf($_POST['csrf'] ?? null);

  // Determine period based on posted year/month (same as UI)
  $yearP = (int)($_POST['year'] ?? 0);
  $monthP = (int)($_POST['month'] ?? 0);
  $employeeP = (int)($_POST['employee_id'] ?? 0);

  if ($yearP > 0 && $monthP > 0) {
    $period = calendar_month_period($yearP, $monthP);
    $periodLabel = sprintf('%04d-%02d', $yearP, $monthP);
  } else {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $period = calendar_month_period((int)$now->format('Y'), (int)$now->format('m'));
    $periodLabel = $now->format('Y-m');
  }

  if ($action === 'lock_period') {
    admin_require_perm($user, 'run_payroll');

    $pdo->beginTransaction();
    try {
      $batchId = next_payroll_batch_id($pdo, $periodLabel);
      $lockedCount = lock_approved_shifts_for_period($pdo, $period['start'], $period['end'], $batchId, $user);
      upsert_batch_lock($pdo, $batchId, $period['start'], $period['end'], $user);
      $pdo->commit();

      admin_redirect(admin_url('payroll-hours.php') . '?' . http_build_query([
        'year' => $yearP,
        'month' => $monthP,
        'employee_id' => $employeeP,
        'n' => 'locked',
        'batch' => $batchId,
        'count' => $lockedCount,
      ]));
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      admin_redirect(admin_url('payroll-hours.php') . '?' . http_build_query([
        'year' => $yearP,
        'month' => $monthP,
        'employee_id' => $employeeP,
        'n' => 'lock_failed',
      ]));
    }
  }

  if ($action === 'mark_processed') {
    admin_require_perm($user, 'run_payroll');

    $batchId = trim((string)($_POST['batch_id'] ?? ''));
    $note = (string)($_POST['note'] ?? '');

    if ($batchId !== '') {
      try {
        mark_batch_processed($pdo, $batchId, $user, $note);
        admin_redirect(admin_url('payroll-hours.php') . '?' . http_build_query([
          'year' => $yearP,
          'month' => $monthP,
          'employee_id' => $employeeP,
          'n' => 'processed',
          'batch' => $batchId,
        ]));
      } catch (Throwable $e) {
        admin_redirect(admin_url('payroll-hours.php') . '?' . http_build_query([
          'year' => $yearP,
          'month' => $monthP,
          'employee_id' => $employeeP,
          'n' => 'process_failed',
          'batch' => $batchId,
        ]));
      }
    }
  }
}

// Payroll Hours (Month)
// Hours-only view for feeding into Sage:
// - Shows ACTUAL vs ROUNDED times (rounding applied only for payroll-time)
// - Day-wise rows, grouped week-wise (week start is a setting)
// - Monthly totals at the bottom, plus an all-employees monthly summary mode
// - No money/rates (hours only)
// - Training hours are shown separately and do NOT contribute to overtime on this page

function int_param(string $k, int $default): int {
  $v = $_GET[$k] ?? $_POST[$k] ?? null;
  if ($v === null) return $default;
  $i = (int)$v;
  return $i > 0 ? $i : $default;
}

/** @return array{start:string,end:string,start_date:string,end_date:string} */
function calendar_month_period(int $year, int $month): array {
  $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('UTC'));
  $end = $start->modify('first day of next month');
  return [
    'start' => $start->format('Y-m-d H:i:s'),
    'end' => $end->format('Y-m-d H:i:s'), // exclusive
    'start_date' => $start->format('Y-m-d'),
    'end_date' => $end->modify('-1 day')->format('Y-m-d'),
  ];
}

/** @return array<string,mixed> */
function load_payroll_settings(PDO $pdo): array {
  // Settings keys are seeded by setup.php (Payroll Rules section).
  $defaults = [
    'rounding_enabled' => true,
    'round_increment_minutes' => 15,
    'round_grace_minutes' => 5,
    'payroll_week_starts_on' => 'MONDAY',
    'payroll_timezone' => 'Europe/London',
    'payroll_overtime_threshold_hours' => 40,
    'payroll_stacking_mode' => 'exclusive',
    'payroll_night_start' => '20:00',
    'payroll_night_end' => '07:00',
    'payroll_bank_holiday_cap_hours' => 12,
    'payroll_callout_min_paid_hours' => 4,
    // Weekend bucket is hours-only; we default to Sat/Sun unless a JSON weekend_days setting exists.
    'weekend_days' => ['SAT','SUN'],
    // Used only for picking night break minutes; kept for backwards compatibility.
    'night_shift_threshold_percent' => 50,
  ];

  $keys = array_keys($defaults);
  $in = implode(',', array_fill(0, count($keys), '?'));
  $stmt = $pdo->prepare("SELECT `key`,`value` FROM kiosk_settings WHERE `key` IN ($in)");
  $stmt->execute($keys);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $map = [];
  foreach ($rows as $r) $map[(string)$r['key']] = (string)$r['value'];

  $out = $defaults;

  // Scalars
  $out['rounding_enabled'] = isset($map['rounding_enabled']) ? ((int)$map['rounding_enabled'] === 1) : $defaults['rounding_enabled'];
  $out['round_increment_minutes'] = isset($map['round_increment_minutes']) ? (int)$map['round_increment_minutes'] : $defaults['round_increment_minutes'];
  $out['round_grace_minutes'] = isset($map['round_grace_minutes']) ? (int)$map['round_grace_minutes'] : $defaults['round_grace_minutes'];

  if (isset($map['payroll_week_starts_on']) && trim($map['payroll_week_starts_on']) !== '') {
    $out['payroll_week_starts_on'] = strtoupper(trim($map['payroll_week_starts_on']));
  }
  if (isset($map['payroll_timezone']) && trim($map['payroll_timezone']) !== '') {
    $out['payroll_timezone'] = trim($map['payroll_timezone']);
  }
  if (isset($map['payroll_overtime_threshold_hours']) && is_numeric($map['payroll_overtime_threshold_hours'])) {
    $out['payroll_overtime_threshold_hours'] = (float)$map['payroll_overtime_threshold_hours'];
  }
  if (isset($map['payroll_stacking_mode']) && trim($map['payroll_stacking_mode']) !== '') {
    $out['payroll_stacking_mode'] = trim((string)$map['payroll_stacking_mode']);
  }
  if (isset($map['payroll_night_start']) && trim($map['payroll_night_start']) !== '') {
    $out['payroll_night_start'] = trim((string)$map['payroll_night_start']);
  }
  if (isset($map['payroll_night_end']) && trim($map['payroll_night_end']) !== '') {
    $out['payroll_night_end'] = trim((string)$map['payroll_night_end']);
  }
  if (isset($map['payroll_bank_holiday_cap_hours']) && is_numeric($map['payroll_bank_holiday_cap_hours'])) {
    $out['payroll_bank_holiday_cap_hours'] = (int)$map['payroll_bank_holiday_cap_hours'];
  }
  if (isset($map['payroll_callout_min_paid_hours']) && is_numeric($map['payroll_callout_min_paid_hours'])) {
    $out['payroll_callout_min_paid_hours'] = (float)$map['payroll_callout_min_paid_hours'];
  }
  if (isset($map['night_shift_threshold_percent']) && is_numeric($map['night_shift_threshold_percent'])) {
    $out['night_shift_threshold_percent'] = (int)$map['night_shift_threshold_percent'];
  }

  // Optional weekend_days as JSON array (backwards/optional)
  if (isset($map['weekend_days']) && $map['weekend_days'] !== '') {
    try {
      $arr = json_decode($map['weekend_days'], true);
      if (is_array($arr) && $arr) {
        $out['weekend_days'] = array_values(array_map(fn($x) => strtoupper((string)$x), $arr));
      }
    } catch (Throwable $e) {}
  }

  // Validate week start
  $allowed = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
  if (!in_array($out['payroll_week_starts_on'], $allowed, true)) {
    $out['payroll_week_starts_on'] = $defaults['payroll_week_starts_on'];
  }
  // Validate timezone
  try {
    new DateTimeZone((string)$out['payroll_timezone']);
  } catch (Throwable $e) {
    $out['payroll_timezone'] = $defaults['payroll_timezone'];
  }

  $out['round_increment_minutes'] = max(1, (int)$out['round_increment_minutes']);
  $out['round_grace_minutes'] = max(0, (int)$out['round_grace_minutes']);
  $out['payroll_overtime_threshold_hours'] = max(0.0, (float)$out['payroll_overtime_threshold_hours']);
  $out['payroll_bank_holiday_cap_hours'] = max(0, (int)$out['payroll_bank_holiday_cap_hours']);
  $out['payroll_callout_min_paid_hours'] = max(0.0, (float)$out['payroll_callout_min_paid_hours']);
  $out['night_shift_threshold_percent'] = max(0, min(100, (int)$out['night_shift_threshold_percent']));

  return $out;
}

/** @return array<string,string> date => name */
function load_bank_holiday_index(PDO $pdo, string $startDate, string $endDate): array {
  $stmt = $pdo->prepare("SELECT holiday_date, name FROM payroll_bank_holidays WHERE holiday_date >= :s AND holiday_date <= :e");
  try {
    $stmt->execute([':s'=>$startDate, ':e'=>$endDate]);
  } catch (Throwable $e) {
    return [];
  }
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $idx = [];
  foreach ($rows as $r) $idx[(string)$r['holiday_date']] = (string)($r['name'] ?? '');
  return $idx;
}

function week_start_date(string $dateYmd, string $weekStartsOn, string $tz): string {
  $weekStartsOn = strtoupper($weekStartsOn);
  $map = [
    'MONDAY' => 1,
    'TUESDAY' => 2,
    'WEDNESDAY' => 3,
    'THURSDAY' => 4,
    'FRIDAY' => 5,
    'SATURDAY' => 6,
    'SUNDAY' => 7,
  ];
  $target = $map[$weekStartsOn] ?? 1;
  $d = new DateTimeImmutable($dateYmd.' 00:00:00', new DateTimeZone($tz));
  $iso = (int)$d->format('N');
  $delta = ($iso - $target) % 7;
  if ($delta < 0) $delta += 7;
  return $d->modify('-'.$delta.' days')->format('Y-m-d');
}

function is_weekend_date(string $dateYmd, array $settings): bool {
  try {
    $tz = new DateTimeZone((string)($settings['payroll_timezone'] ?? 'UTC'));
    $d = new DateTimeImmutable($dateYmd.' 00:00:00', $tz);
    $dow = strtoupper($d->format('D'));
    $weekend = $settings['weekend_days'] ?? ['SAT','SUN'];
    if (!is_array($weekend)) $weekend = ['SAT','SUN'];
    return in_array($dow, $weekend, true);
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Split an interval by midnight in payroll timezone.
 * Returns segments keyed by date.
 * Each segment: date, start_utc, end_utc, start_local, end_local, minutes_utc, minutes_local, dst_delta
 */
function split_by_day_detailed(string $startUtc, string $endUtc, string $periodStartUtc, string $periodEndUtc, string $tz): array {
  $out = [];
  try {
    $tzObj = new DateTimeZone($tz);
    $sUtc = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
    $eUtc = new DateTimeImmutable($endUtc, new DateTimeZone('UTC'));
    $psUtc = new DateTimeImmutable($periodStartUtc, new DateTimeZone('UTC'));
    $peUtc = new DateTimeImmutable($periodEndUtc, new DateTimeZone('UTC'));

    if ($eUtc <= $psUtc || $sUtc >= $peUtc) return [];
    if ($sUtc < $psUtc) $sUtc = $psUtc;
    if ($eUtc > $peUtc) $eUtc = $peUtc;
    if ($eUtc <= $sUtc) return [];

    $curUtc = $sUtc;
    while ($curUtc < $eUtc) {
      $curLocal = $curUtc->setTimezone($tzObj);
      $nextMidnightLocal = $curLocal->setTime(0,0,0)->modify('+1 day');
      $nextMidnightUtc = $nextMidnightLocal->setTimezone(new DateTimeZone('UTC'));

      $segEndUtc = $eUtc < $nextMidnightUtc ? $eUtc : $nextMidnightUtc;
      if ($segEndUtc <= $curUtc) break;

      $segStartLocal = $curUtc->setTimezone($tzObj);
      $segEndLocal = $segEndUtc->setTimezone($tzObj);

      $minUtc = (int)floor(($segEndUtc->getTimestamp() - $curUtc->getTimestamp())/60);
      $minLocal = (int)floor(($segEndLocal->getTimestamp() - $segStartLocal->getTimestamp())/60);
      $dstDelta = $minLocal - $minUtc;

      if ($minUtc > 0) {
        $out[] = [
          'date' => $segStartLocal->format('Y-m-d'),
          'start_utc' => $curUtc->format('Y-m-d H:i:s'),
          'end_utc' => $segEndUtc->format('Y-m-d H:i:s'),
          'start_local' => $segStartLocal->format('Y-m-d H:i'),
          'end_local' => $segEndLocal->format('Y-m-d H:i'),
          'minutes_utc' => $minUtc,
          'minutes_local' => $minLocal,
          'dst_delta' => $dstDelta,
        ];
      }

      $curUtc = $segEndUtc;
    }
  } catch (Throwable $e) {
    return [];
  }
  return $out;
}

/** Return overlap minutes of [startUtc,endUtc) with night windows, in payroll timezone. */
function normalize_hms(string $t, string $fallback): string {
  $t = trim($t);
  if ($t === '') return $fallback;
  // Allow HH:MM or HH:MM:SS
  if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) return $fallback;
  $hh = (int)$m[1];
  $mm = (int)$m[2];
  $ss = isset($m[3]) ? (int)$m[3] : 0;
  if ($hh < 0 || $hh > 23) return $fallback;
  if ($mm < 0 || $mm > 59) return $fallback;
  if ($ss < 0 || $ss > 59) return $fallback;
  return sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
}

/** @return array{start:string,end:string} */
function night_window_for_row(array $row, array $settings): array {
  // Payroll-wide night window (LOCKED model). We don't use per-employee night windows here.
  $defaultStart = '20:00:00';
  $defaultEnd = '07:00:00';
  $ns = normalize_hms((string)($settings['payroll_night_start'] ?? ''), $defaultStart);
  $ne = normalize_hms((string)($settings['payroll_night_end'] ?? ''), $defaultEnd);
  return ['start'=>$ns, 'end'=>$ne];
}


function night_minutes_between(string $startUtc, string $endUtc, string $nightStart, string $nightEnd, string $tz): int {
  try {
    $tzObj = new DateTimeZone($tz);
    $sUtc = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
    $eUtc = new DateTimeImmutable($endUtc, new DateTimeZone('UTC'));
    if ($eUtc <= $sUtc) return 0;

    $s = $sUtc->setTimezone($tzObj);
    $e = $eUtc->setTimezone($tzObj);

    $nightStart = normalize_hms($nightStart, '22:00:00');
    $nightEnd = normalize_hms($nightEnd, '06:00:00');

    $nightMin = 0;
    $curDay = $s->setTime(0,0,0);
    $endDay = $e->setTime(0,0,0)->modify('+1 day');
    while ($curDay < $endDay) {
      $ns = new DateTimeImmutable($curDay->format('Y-m-d').' '.$nightStart, $tzObj);
      $ne = new DateTimeImmutable($curDay->format('Y-m-d').' '.$nightEnd, $tzObj);
      if ($ne <= $ns) $ne = $ne->modify('+1 day');

      $os = ($s > $ns) ? $s : $ns;
      $oe = ($e < $ne) ? $e : $ne;
      if ($oe > $os) {
        $nightMin += (int)floor(($oe->getTimestamp() - $os->getTimestamp())/60);
      }
      $curDay = $curDay->modify('+1 day');
    }
    return max(0, $nightMin);
  } catch (Throwable $e) {
    return 0;
  }
}

function is_night_shift_for_break(string $startUtc, string $endUtc, array $row, array $settings): bool {
  $tz = (string)($settings['payroll_timezone'] ?? 'Europe/London');
  $w = night_window_for_row($row, $settings);
  $nightStart = $w['start'];
  $nightEnd = $w['end'];
  $threshold = (int)($settings['night_shift_threshold_percent'] ?? 50);
  $threshold = max(0, min(100, $threshold));

  $totalMin = admin_minutes_between($startUtc, $endUtc) ?? 0;
  if ($totalMin <= 0) return false;
  $nightMin = night_minutes_between($startUtc, $endUtc, $nightStart, $nightEnd, $tz);
  $pct = ($nightMin / $totalMin) * 100.0;
  return $pct >= $threshold;
}

/** @return array{contract_hours_per_week:float, night_start:string, night_end:string} */
function employee_contract_meta_from_rows(array $rows): array {
  $first = $rows[0] ?? [];
  return [
    'contract_hours_per_week' => (float)($first['contract_hours_per_week'] ?? 0),
    'night_start' => (string)($first['night_start'] ?? ''),
    'night_end' => (string)($first['night_end'] ?? ''),
  ];
}

/** @return array{days:array<string,array>, weeks:array<string,array>, totals:array, training_minutes:int, contract_week_minutes:int} */
function compute_employee_month(PDO $pdo, int $employeeId, array $period, array $settings, array $bankHolidayIndex): array {
  $tz = (string)$settings['payroll_timezone'];

  $sql = "
    SELECT
      s.*,
      e.employee_code, e.first_name, e.last_name, e.nickname, e.is_agency, e.agency_label,
      p.contract_hours_per_week,
      p.break_minutes_default, p.break_minutes_night,
      p.break_is_paid, p.min_hours_for_break,
      NULL AS latest_edit_json
    FROM kiosk_shifts s
    JOIN kiosk_employees e ON e.id = s.employee_id
    LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = e.id
    WHERE s.employee_id = :emp
      AND s.clock_in_at >= :from_dt AND s.clock_in_at < :to_dt
      AND s.approved_at IS NOT NULL
      AND (s.close_reason IS NULL OR s.close_reason <> 'void')
    ORDER BY s.clock_in_at ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':emp'=>$employeeId, ':from_dt'=>$period['start'], ':to_dt'=>$period['end']]);
  $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $contractHours = (float)($shifts[0]['contract_hours_per_week'] ?? 0);
  // LOCKED: overtime threshold is a care-home payroll setting (Mon–Sun week).
  $thresholdHours = (float)($settings['payroll_overtime_threshold_hours'] ?? 0);
  $contractWeekMinutes = (int)round(max(0.0, $thresholdHours) * 60);

  // Training minutes are shown separately and excluded from OT on this page.
  $trainingMinutes = 0;
  foreach ($shifts as $s) {
    $tm = (int)($s['training_minutes'] ?? 0);
    if ($tm > 0) $trainingMinutes += $tm;
  }

  $days = []; // date => aggregates
  foreach ($shifts as $s) {
    $eff = admin_shift_effective($s);
    $in = (string)($eff['clock_in_at'] ?? '');
    $out = (string)($eff['clock_out_at'] ?? '');
    if ($in === '' || $out === '') continue;

    // Actual interval (UTC strings)
    $actualIn = $in;
    $actualOut = $out;

    // Rounded interval (UTC strings)
    $rin = $actualIn;
    $rout = $actualOut;
    if ((bool)$settings['rounding_enabled']) {
      $rin = admin_round_datetime($actualIn, (int)$settings['round_increment_minutes'], (int)$settings['round_grace_minutes']) ?? $actualIn;
      $rout = admin_round_datetime($actualOut, (int)$settings['round_increment_minutes'], (int)$settings['round_grace_minutes']) ?? $actualOut;
    }

    $workedRounded = admin_minutes_between($rin, $rout) ?? 0;
    if ($workedRounded <= 0) continue;

    // Break minutes
    $isNightForBreak = is_night_shift_for_break($rin, $rout, $s, $settings);
    $breakMinutes = $eff['break_minutes'] !== null
      ? (int)$eff['break_minutes']
      : (int)($isNightForBreak
        ? ($s['break_minutes_night'] ?? ($s['break_minutes_default'] ?? 0))
        : ($s['break_minutes_default'] ?? 0)
      );
    $breakMinutes = max(0, $breakMinutes);

    $breakIsPaid = ((int)($s['break_is_paid'] ?? 0) === 1);
    $minHours = $s['min_hours_for_break'] !== null ? (float)$s['min_hours_for_break'] : 0.0;
    if ($minHours > 0 && ($workedRounded/60.0) < $minHours) {
      $breakMinutes = 0;
    }
    $unpaidBreakTotal = $breakIsPaid ? 0 : $breakMinutes;
    $unpaidBreakTotal = min($unpaidBreakTotal, $workedRounded);

    // Split both actual and rounded intervals by local day
    $actualSegs = split_by_day_detailed($actualIn, $actualOut, $period['start'], $period['end'], $tz);
    $roundedSegs = split_by_day_detailed($rin, $rout, $period['start'], $period['end'], $tz);

    $roundedTotalSegMin = 0;
    foreach ($roundedSegs as $seg) $roundedTotalSegMin += (int)$seg['minutes_utc'];
    if ($roundedTotalSegMin <= 0) continue;

    // Allocate unpaid break across rounded segments proportionally.
    $shiftPaidTotal = 0;
    $shiftStartDate = (new DateTimeImmutable($rin, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    $isCallout = ((int)($s['is_callout'] ?? 0) === 1);
    $remainingBreak = $unpaidBreakTotal;
    foreach ($roundedSegs as $idx => $seg) {
      $segMin = (int)$seg['minutes_utc'];
      if ($segMin <= 0) continue;

      $allocBreak = 0;
      if ($unpaidBreakTotal > 0) {
        if ($idx === array_key_last($roundedSegs)) {
          $allocBreak = $remainingBreak;
        } else {
          $allocBreak = (int)floor($unpaidBreakTotal * ($segMin / $roundedTotalSegMin));
          $allocBreak = min($allocBreak, $remainingBreak);
        }
      }
      $remainingBreak -= $allocBreak;

      $paidSeg = max(0, $segMin - max(0, $allocBreak));
      $shiftPaidTotal += $paidSeg;
      $date = (string)$seg['date'];

      if (!isset($days[$date])) {
        $days[$date] = [
          'date' => $date,
          'actual_start' => null,
          'actual_end' => null,
          'rounded_start' => null,
          'rounded_end' => null,
          'actual_worked' => 0,
          'rounded_worked' => 0,
          'unpaid_break' => 0,
          'paid' => 0,
          'weekend' => 0,
          'bank_holiday' => 0,
          'night' => 0,
          'callout' => 0,
          'dst_delta' => 0,
        ];
      }

      // Rounded time bounds
      $days[$date]['rounded_worked'] += $segMin;
      $days[$date]['unpaid_break'] += $allocBreak;
      $days[$date]['paid'] += $paidSeg;
      $days[$date]['dst_delta'] += (int)($seg['dst_delta'] ?? 0);

      if ($days[$date]['rounded_start'] === null || (string)$seg['start_local'] < (string)$days[$date]['rounded_start']) {
        $days[$date]['rounded_start'] = (string)$seg['start_local'];
      }
      if ($days[$date]['rounded_end'] === null || (string)$seg['end_local'] > (string)$days[$date]['rounded_end']) {
        $days[$date]['rounded_end'] = (string)$seg['end_local'];
      }

      // Weekend/BH (hours only)
      // Weekend hours are always calculated (hours-only reporting).
      if (is_weekend_date($date, $settings)) {
        $days[$date]['weekend'] += $paidSeg;
      }
      if (isset($bankHolidayIndex[$date])) {
        $capHours = (int)($settings['payroll_bank_holiday_cap_hours'] ?? 12);
        $capMin = max(0, $capHours) * 60;
        if ($capMin <= 0) {
          $days[$date]['bank_holiday'] += $paidSeg;
        } else {
          $cur = (int)($days[$date]['bank_holiday'] ?? 0);
          $remain = max(0, $capMin - $cur);
          if ($remain > 0) {
            $days[$date]['bank_holiday'] += min($paidSeg, $remain);
          }
        }
      }

      // Night minutes (scaled to paid minutes after break allocation)
      $w = night_window_for_row($s, $settings);
      $nightStart = $w['start'];
      $nightEnd = $w['end'];
      $nightOverlap = night_minutes_between((string)$seg['start_utc'], (string)$seg['end_utc'], $nightStart, $nightEnd, $tz);
      if ($nightOverlap > 0 && $segMin > 0) {
        $days[$date]['night'] += (int)floor($nightOverlap * ($paidSeg / $segMin));
      }

      // Call-out minutes (hours-only bucket)
      if ((int)($s['is_callout'] ?? 0) === 1) {
        $days[$date]['callout'] += $paidSeg;
      }
    }

    // Accumulate ACTUAL worked minutes + bounds by day (independently)

    // LOCKED: Call-out minimum paid hours uplift happens before overtime.
    if ($isCallout) {
      $minPaidH = (float)($settings['payroll_callout_min_paid_hours'] ?? 0);
      $minPaidMin = (int)round(max(0.0, $minPaidH) * 60);
      if ($minPaidMin > 0 && $shiftPaidTotal > 0 && $shiftPaidTotal < $minPaidMin) {
        $uplift = $minPaidMin - $shiftPaidTotal;
        if (!isset($days[$shiftStartDate])) {
          $days[$shiftStartDate] = [
            'date' => $shiftStartDate,
            'actual_start' => null,
            'actual_end' => null,
            'rounded_start' => null,
            'rounded_end' => null,
            'actual_worked' => 0,
            'rounded_worked' => 0,
            'unpaid_break' => 0,
            'paid' => 0,
            'weekend' => 0,
            'bank_holiday' => 0,
            'night' => 0,
            'callout' => 0,
            'dst_delta' => 0,
          ];
        }
        $days[$shiftStartDate]['paid'] += $uplift;
        $days[$shiftStartDate]['callout'] += $uplift;
      }
    }

    foreach ($actualSegs as $aseg) {
      $date = (string)$aseg['date'];
      if (!isset($days[$date])) {
        // If rounding moved all paid time away, still create day entry so payroll sees actual.
        $days[$date] = [
          'date' => $date,
          'actual_start' => null,
          'actual_end' => null,
          'rounded_start' => null,
          'rounded_end' => null,
          'actual_worked' => 0,
          'rounded_worked' => 0,
          'unpaid_break' => 0,
          'paid' => 0,
          'weekend' => 0,
          'bank_holiday' => 0,
          'night' => 0,
          'callout' => 0,
          'dst_delta' => 0,
        ];
      }
      $days[$date]['actual_worked'] += (int)$aseg['minutes_utc'];
      if ($days[$date]['actual_start'] === null || (string)$aseg['start_local'] < (string)$days[$date]['actual_start']) {
        $days[$date]['actual_start'] = (string)$aseg['start_local'];
      }
      if ($days[$date]['actual_end'] === null || (string)$aseg['end_local'] > (string)$days[$date]['actual_end']) {
        $days[$date]['actual_end'] = (string)$aseg['end_local'];
      }
    }
  }

  ksort($days);

  // Week grouping + OT (training excluded)
  $weeks = []; // weekStart => ['days'=>[date=>ref], 'totals'=>...]
  $totalPaid = 0;
  $totalUnpaidBreak = 0;
  $totalWeekend = 0;
  $totalBH = 0;
  $totalNight = 0;
  $totalCallout = 0;
  $totalDst = 0;

  foreach ($days as $date => $d) {
    $wk = week_start_date($date, (string)$settings['payroll_week_starts_on'], $tz);
    if (!isset($weeks[$wk])) {
      $weeks[$wk] = [
        'week_start' => $wk,
        'days' => [],
        'totals' => ['paid'=>0, 'regular'=>0, 'overtime'=>0],
      ];
    }
    $weeks[$wk]['days'][$date] = $d;
    $weeks[$wk]['totals']['paid'] += (int)$d['paid'];

    $totalPaid += (int)$d['paid'];
    $totalUnpaidBreak += (int)$d['unpaid_break'];
    $totalWeekend += (int)$d['weekend'];
    $totalBH += (int)$d['bank_holiday'];
    $totalNight += (int)$d['night'];
    $totalCallout += (int)($d['callout'] ?? 0);
    $totalDst += (int)$d['dst_delta'];
  }

  foreach ($weeks as $wk => &$w) {
    $paid = (int)$w['totals']['paid'];
    $regular = $paid;
    $ot = 0;
    if ($contractWeekMinutes > 0) {
      $regular = min($paid, $contractWeekMinutes);
      $ot = max(0, $paid - $contractWeekMinutes);
    }
    $w['totals']['regular'] = $regular;
    $w['totals']['overtime'] = $ot;
  }
  unset($w);
  ksort($weeks);

  $monthOT = 0;
  $monthRegular = 0;
  foreach ($weeks as $w) {
    $monthOT += (int)$w['totals']['overtime'];
    $monthRegular += (int)$w['totals']['regular'];
  }

  return [
    'days' => $days,
    'weeks' => $weeks,
    'totals' => [
      'paid' => $totalPaid,
      'unpaid_break' => $totalUnpaidBreak,
      'weekend' => $totalWeekend,
      'bank_holiday' => $totalBH,
      'night' => $totalNight,
      'callout' => $totalCallout,
      'dst_delta' => $totalDst,
      'regular' => $monthRegular,
      'overtime' => $monthOT,
    ],
    'training_minutes' => $trainingMinutes,
    'contract_week_minutes' => $contractWeekMinutes,
  ];
}

// Defaults: previous month
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$defaultMonth = (int)$now->modify('-1 month')->format('n');
$defaultYear  = (int)$now->modify('-1 month')->format('Y');

$month = int_param('month', $defaultMonth);
$year  = int_param('year', $defaultYear);
$employeeId = (int)($_GET['employee_id'] ?? 0);

$period = calendar_month_period($year, $month);
$settings = load_payroll_settings($pdo);
$tz = (string)$settings['payroll_timezone'];
$bankHolidayIndex = load_bank_holiday_index($pdo, $period['start_date'], $period['end_date']);

// Employee list (active)
$empSql = "
  SELECT e.id, e.employee_code,
         " . admin_sql_employee_display_name('e') . " AS display_name
  FROM kiosk_employees e
  WHERE e.is_active = 1
  ORDER BY display_name ASC
";
$employees = $pdo->query($empSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Unapproved shifts warning (block in payroll-run; here we just show)
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM kiosk_shifts WHERE clock_in_at >= :s AND clock_in_at < :e AND approved_at IS NULL AND (close_reason IS NULL OR close_reason <> 'void')");
$stmt->execute([':s'=>$period['start'], ':e'=>$period['end']]);

$unapprovedCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

// Payroll lock status for this period
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM kiosk_shifts
  WHERE clock_in_at >= :s AND clock_in_at < :e
    AND payroll_locked_at IS NOT NULL
    AND (close_reason IS NULL OR close_reason <> 'void')");
$stmt->execute([':s'=>$period['start'], ':e'=>$period['end']]);
$lockedCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

$stmt = $pdo->prepare("SELECT DISTINCT payroll_batch_id FROM kiosk_shifts
  WHERE clock_in_at >= :s AND clock_in_at < :e
    AND payroll_batch_id IS NOT NULL AND payroll_batch_id <> ''
    AND (close_reason IS NULL OR close_reason <> 'void')
  ORDER BY payroll_batch_id DESC");
$stmt->execute([':s'=>$period['start'], ':e'=>$period['end']]);
$batchIds = array_values(array_filter(array_map(fn($r) => (string)($r['payroll_batch_id'] ?? ''), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));

$batchInfo = null;
if ($batchIds) {
  try {
    ensure_payroll_batches_table($pdo);
    $stmt = $pdo->prepare("SELECT * FROM kiosk_payroll_batches WHERE batch_id = ? LIMIT 1");
    $stmt->execute([$batchIds[0]]);
    $batchInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    $batchInfo = null;
  }
}

$notice = (string)($_GET['n'] ?? '');

$periodLabel = sprintf('%04d-%02d', $year, $month);
$title = 'Payroll Hours - ' . $periodLabel;

admin_page_start($pdo, $title);
$active = admin_url('payroll-hours.php');

// Render
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="w-full">
      <div class="flex flex-col lg:flex-row gap-5">

        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1 min-w-0">

          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-2xl font-semibold">Payroll Hours</h1>
              <div class="mt-1 text-sm text-white/70">
                Period: <span class="font-semibold text-white"><?= h($period['start_date']) ?></span> to <span class="font-semibold text-white"><?= h($period['end_date']) ?></span>
                · Timezone: <span class="font-semibold text-white"><?= h($tz) ?></span>
                · Week starts: <span class="font-semibold text-white"><?= h((string)$settings['payroll_week_starts_on']) ?></span>
              </div>
              <div class="mt-1 text-xs text-white/50">Hours-only view (no pay). Training is separate and excluded from overtime here.</div>
            </div>
          </div>

          <div class="mt-4 bg-white/5 border border-white/10 rounded-3xl p-4">
            <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div>
                <label class="text-xs text-white/60">Year</label>
                <input name="year" value="<?= (int)$year ?>" class="mt-1 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
              </div>
              <div>
                <label class="text-xs text-white/60">Month</label>
                <input name="month" value="<?= (int)$month ?>" class="mt-1 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" />
              </div>
              <div>
                <label class="text-xs text-white/60">Employee</label>
                <select name="employee_id" class="mt-1 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                  <option value="0" <?= $employeeId===0?'selected':'' ?>>All employees (monthly totals)</option>
                  <?php foreach ($employees as $e): ?>
                    <option value="<?= (int)$e['id'] ?>" <?= ((int)$e['id']===$employeeId)?'selected':'' ?>><?= h((string)$e['display_name']) ?> (<?= h((string)$e['employee_code']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <button class="w-full rounded-2xl px-4 py-2.5 text-sm font-semibold bg-sky-600 hover:bg-sky-500">View</button>
              </div>
            </form>
          </div>

          <?php if ($notice === 'locked'): ?>
            <div class="mt-4 rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-4">
              <div class="font-semibold text-emerald-100">Payroll locked for this period</div>
              <div class="mt-1 text-sm text-emerald-100/90">Batch <b><?= h((string)($_GET['batch'] ?? '')) ?></b> locked <b><?= (int)($_GET['count'] ?? 0) ?></b> shift(s).</div>
            </div>
          <?php elseif ($notice === 'processed'): ?>
            <div class="mt-4 rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-4">
              <div class="font-semibold text-emerald-100">Payroll batch marked as processed</div>
              <div class="mt-1 text-sm text-emerald-100/90">Batch <b><?= h((string)($_GET['batch'] ?? '')) ?></b> is now marked processed/exported.</div>
            </div>
          <?php elseif ($notice === 'lock_failed' || $notice === 'process_failed'): ?>
            <div class="mt-4 rounded-3xl border border-rose-500/30 bg-rose-500/10 p-4">
              <div class="font-semibold text-rose-100">Action failed</div>
              <div class="mt-1 text-sm text-rose-100/90">Could not complete the requested payroll action. Check server logs.</div>
            </div>
          <?php endif; ?>

          <div class="mt-4 bg-white/5 border border-white/10 rounded-3xl p-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
              <div>
                <div class="font-semibold">Payroll lock</div>
                <div class="mt-1 text-sm text-white/70">Locked shifts: <b><?= (int)$lockedCount ?></b>
                  <?php if (!empty($batchIds)): ?>
                    · Latest batch: <b><?= h((string)$batchIds[0]) ?></b>
                    <?php if ($batchInfo && !empty($batchInfo['processed_at'])): ?>
                      · Status: <span class="text-emerald-200">processed</span>
                    <?php elseif ($batchInfo): ?>
                      · Status: <span class="text-amber-200">locked</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
              <?php if (admin_can($user, 'run_payroll')): ?>
                <div class="flex flex-col sm:flex-row gap-2">
                  <form method="post" action="" class="inline">
                    <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="lock_period">
                    <input type="hidden" name="year" value="<?= (int)$year ?>">
                    <input type="hidden" name="month" value="<?= (int)$month ?>">
                    <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                    <button class="w-full sm:w-auto rounded-2xl px-4 py-2.5 text-sm font-semibold bg-indigo-600 hover:bg-indigo-500" <?= $unapprovedCount>0?'disabled':'' ?>>Lock approved shifts for this period</button>
                    <?php if ($unapprovedCount>0): ?>
                      <div class="mt-1 text-xs text-white/50">Disabled until unapproved shifts are resolved.</div>
                    <?php endif; ?>
                  </form>

                  <?php if (!empty($batchIds)): ?>
                    <form method="post" action="" class="inline">
                      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
                      <input type="hidden" name="action" value="mark_processed">
                      <input type="hidden" name="year" value="<?= (int)$year ?>">
                      <input type="hidden" name="month" value="<?= (int)$month ?>">
                      <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
                      <input type="hidden" name="batch_id" value="<?= h((string)$batchIds[0]) ?>">
                      <input type="text" name="note" placeholder="optional note" class="w-full sm:w-56 rounded-2xl bg-slate-950/40 border border-white/10 px-3 py-2 text-sm outline-none focus:border-white/30">
                      <button class="mt-2 sm:mt-0 w-full sm:w-auto rounded-2xl px-4 py-2.5 text-sm font-semibold bg-emerald-600 hover:bg-emerald-500">Mark processed</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($unapprovedCount > 0): ?>
            <div class="mt-4 rounded-3xl border border-amber-500/30 bg-amber-500/10 p-4">
              <div class="font-semibold text-amber-100">Unapproved shifts in this period</div>
              <div class="mt-1 text-sm text-amber-100/90">
                There are <b><?= (int)$unapprovedCount ?></b> unapproved shifts between <?= h($period['start_date']) ?> and <?= h($period['end_date']) ?>.
                Payroll should not be finalised until managers approve everything.
              </div>
              <div class="mt-2">
                <a class="underline text-amber-100" href="shifts.php?mode=range&from=<?= h($period['start_date']) ?>&to=<?= h($period['end_date']) ?>&status=unapproved">Open unapproved shifts</a>
              </div>
            </div>
          <?php endif; ?>

          <?php
            // ALL EMPLOYEES SUMMARY
            if ($employeeId === 0):
              $grand = ['paid'=>0,'unpaid_break'=>0,'weekend'=>0,'bank_holiday'=>0,'night'=>0,'callout'=>0,'overtime'=>0,'training'=>0,'dst_delta'=>0];
          ?>

            <div class="mt-6 bg-white/5 border border-white/10 rounded-3xl overflow-hidden">
              <div class="p-4">
                <div class="text-lg font-semibold">Monthly totals (all employees)</div>
                <div class="mt-1 text-sm text-white/60">Click an employee to see day/week breakdown (rounded time shown, actual time shown smaller underneath).</div>
              </div>
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-white/70">
                    <tr class="border-t border-white/10">
                      <th class="text-left px-4 py-3">Employee</th>
                      <th class="text-right px-4 py-3">Paid (h)</th>
                      <th class="text-right px-4 py-3">Unpaid break (h)</th>
                      <th class="text-right px-4 py-3">Weekend (h)</th>
                      <th class="text-right px-4 py-3">Bank holiday (h)</th>
                      <th class="text-right px-4 py-3">Night (h)</th>
                      <th class="text-right px-4 py-3">Call-out (h)</th>
                      <th class="text-right px-4 py-3">Overtime (h)</th>
                      <th class="text-right px-4 py-3">Training (h)</th>
                      <th class="text-right px-4 py-3">DST Δ (min)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($employees as $e): ?>
                      <?php
                        $empId = (int)$e['id'];
                        $calc = compute_employee_month($pdo, $empId, $period, $settings, $bankHolidayIndex);
                        $t = $calc['totals'];
                        $trainingMin = (int)$calc['training_minutes'];
                        $grand['paid'] += (int)$t['paid'];
                        $grand['unpaid_break'] += (int)$t['unpaid_break'];
                        $grand['weekend'] += (int)$t['weekend'];
                        $grand['bank_holiday'] += (int)$t['bank_holiday'];
                        $grand['night'] += (int)$t['night'];
                        $grand['callout'] += (int)($t['callout'] ?? 0);
                        $grand['overtime'] += (int)$t['overtime'];
                        $grand['training'] += $trainingMin;
                        $grand['dst_delta'] += (int)$t['dst_delta'];
                      ?>
                      <tr class="border-t border-white/10 hover:bg-white/5">
                        <td class="px-4 py-3">
                          <a class="underline" href="payroll-hours.php?year=<?= (int)$year ?>&month=<?= (int)$month ?>&employee_id=<?= (int)$empId ?>"><?= h((string)$e['display_name']) ?></a>
                          <div class="text-xs text-white/40"><?= h((string)$e['employee_code']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-right"><?= h(number_format(((int)$t['paid'])/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= h(number_format(((int)$t['unpaid_break'])/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= h(number_format(((int)$t['weekend'])/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= h(number_format(((int)$t['bank_holiday'])/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= h(number_format(((int)$t['night'])/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= h(number_format(((int)($t['callout'] ?? 0))/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= h(number_format(((int)$t['overtime'])/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= h(number_format($trainingMin/60, 2)) ?></td>
                        <td class="px-4 py-3 text-right"><?= (int)$t['dst_delta'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr class="border-t border-white/20 bg-slate-950/40">
                      <th class="text-left px-4 py-3">Grand total</th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['paid']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['unpaid_break']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['weekend']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['bank_holiday']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['night']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['callout']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['overtime']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= h(number_format($grand['training']/60, 2)) ?></th>
                      <th class="text-right px-4 py-3"><?= (int)$grand['dst_delta'] ?></th>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>

          <?php
            // SINGLE EMPLOYEE DETAIL
            else:
              $calc = compute_employee_month($pdo, $employeeId, $period, $settings, $bankHolidayIndex);
              $weeks = $calc['weeks'];
              $tot = $calc['totals'];
              $trainingMin = (int)$calc['training_minutes'];

              $empName = '';
              $empCode = '';
              foreach ($employees as $e) {
                if ((int)$e['id'] === $employeeId) {
                  $empName = (string)$e['display_name'];
                  $empCode = (string)$e['employee_code'];
                  break;
                }
              }
          ?>

            <div class="mt-6">
              <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                  <div class="text-sm text-white/60">Employee</div>
                  <div class="text-xl font-semibold"><?= h($empName) ?> <span class="text-white/40 text-sm font-normal">(<?= h($empCode) ?>)</span></div>
                </div>
                <a class="underline text-white/70" href="payroll-hours.php?year=<?= (int)$year ?>&month=<?= (int)$month ?>&employee_id=0">Back to all employees</a>
              </div>
            </div>

            <?php foreach ($weeks as $wk => $w): ?>
              <div class="mt-6 bg-white/5 border border-white/10 rounded-3xl overflow-hidden">
                <div class="p-4 flex items-center justify-between gap-3 flex-wrap">
                  <div>
                    <div class="text-xs uppercase tracking-widest text-white/50">Week starting</div>
                    <div class="text-lg font-semibold"><?= h($wk) ?></div>
                  </div>
                  <div class="text-sm text-white/70">
                    <span class="text-white/50">Paid:</span> <b class="text-white"><?= h(number_format(((int)$w['totals']['paid'])/60, 2)) ?>h</b>
                    · <span class="text-white/50">Regular:</span> <b class="text-white"><?= h(number_format(((int)$w['totals']['regular'])/60, 2)) ?>h</b>
                    · <span class="text-white/50">OT:</span> <b class="text-white"><?= h(number_format(((int)$w['totals']['overtime'])/60, 2)) ?>h</b>
                  </div>
                </div>

                <div class="overflow-x-auto">
                  <table class="min-w-full text-sm">
                    <thead class="text-white/70">
                      <tr class="border-t border-white/10">
                        <th class="text-left px-4 py-3">Date</th>
                        <th class="text-left px-4 py-3">Shift (rounded)</th>
                        <th class="text-right px-4 py-3">Paid (h)</th>
                        <th class="text-right px-4 py-3">Unpaid break (h)</th>
                        <th class="text-right px-4 py-3">Weekend (h)</th>
                        <th class="text-right px-4 py-3">BH (h)</th>
                        <th class="text-right px-4 py-3">Night (h)</th>
                        <th class="text-right px-4 py-3">Call-out (h)</th>
                        <th class="text-right px-4 py-3">DST Δ (min)</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($w['days'] as $date => $d): ?>
                        <tr class="border-t border-white/10">
                          <td class="px-4 py-3 font-semibold"><?= h($date) ?></td>
                          <td class="px-4 py-3">
                            <div class="font-semibold text-white">
                              <?= h((string)($d['rounded_start'] ?? '—')) ?> → <?= h((string)($d['rounded_end'] ?? '—')) ?>
                            </div>
                            <div class="mt-1 text-[11px] leading-4 text-white/50">
                              Actual: <?= h((string)($d['actual_start'] ?? '—')) ?> → <?= h((string)($d['actual_end'] ?? '—')) ?>
                            </div>
                          </td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)$d['paid'])/60, 2)) ?></td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)$d['unpaid_break'])/60, 2)) ?></td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)$d['weekend'])/60, 2)) ?></td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)$d['bank_holiday'])/60, 2)) ?></td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)$d['night'])/60, 2)) ?></td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)($d['callout'] ?? 0))/60, 2)) ?></td>
                          <td class="px-4 py-3 text-right"><?= (int)$d['dst_delta'] ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="mt-6 bg-white/5 border border-white/10 rounded-3xl p-4">
              <div class="text-sm text-white/60">Month totals</div>
              <div class="mt-2 text-sm text-white">
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                  <div>Paid: <b><?= h(number_format(((int)$tot['paid'])/60, 2)) ?>h</b></div>
                  <div>Unpaid break: <b><?= h(number_format(((int)$tot['unpaid_break'])/60, 2)) ?>h</b></div>
                  <div>Weekend: <b><?= h(number_format(((int)$tot['weekend'])/60, 2)) ?>h</b></div>
                  <div>Bank holiday: <b><?= h(number_format(((int)$tot['bank_holiday'])/60, 2)) ?>h</b></div>
                  <div>Night: <b><?= h(number_format(((int)$tot['night'])/60, 2)) ?>h</b></div>
                  <div>Call-out: <b><?= h(number_format(((int)($tot['callout'] ?? 0))/60, 2)) ?>h</b></div>
                  <div>Overtime (weekly): <b><?= h(number_format(((int)$tot['overtime'])/60, 2)) ?>h</b></div>
                  <div>Training (separate): <b><?= h(number_format($trainingMin/60, 2)) ?>h</b></div>
                  <div>DST Δ total: <b><?= (int)$tot['dst_delta'] ?> min</b></div>
                </div>
              </div>
              <div class="mt-2 text-xs text-white/50">Note: OT here is calculated from paid hours only (training excluded).</div>
            </div>

          <?php endif; ?>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
