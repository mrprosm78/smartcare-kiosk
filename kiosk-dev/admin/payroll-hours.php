<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');


/**
 * Compact display for shift times (option B):
 * - show time-only, because row already shows the date
 * - if end falls on a different local date than start, append " (+1)" or "(+N)"
 */
function fmt_dt_time_only(string $utcDt, string $tz): string {
  if ($utcDt === '') return '';
  try {
    $d = new DateTimeImmutable($utcDt, new DateTimeZone('UTC'));
    $d = $d->setTimezone(new DateTimeZone($tz));
    return $d->format('H:i');
  } catch (Throwable $e) {
    return $utcDt;
  }
}

function fmt_dt_range_compact(string $utcStart, string $utcEnd, string $tz): string {
  if ($utcStart === '' || $utcEnd === '') return '—';
  try {
    $s = (new DateTimeImmutable($utcStart, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));
    $e = (new DateTimeImmutable($utcEnd, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));

    $out = $s->format('H:i') . ' → ' . $e->format('H:i');

    // day delta in local timezone (handles DST too)
    $sd = $s->format('Y-m-d');
    $ed = $e->format('Y-m-d');
    if ($sd !== $ed) {
      $days = (int)$s->diff($e)->format('%r%a');
      // For overnight shifts, this will typically be +1. Keep it simple for display.
      if ($days === 0) $days = 1;
      $out .= ' (+' . $days . ')';
    }
    return $out;
  } catch (Throwable $e) {
    // fallback: show raw
    return $utcStart . ' → ' . $utcEnd;
  }
}

/**
 * Build a fully-populated per-day aggregate row.
 * Keeps rendering stable (no undefined index warnings) even when a day has no shifts.
 *
 * @return array<string,mixed>
 */
function day_row_default(string $date): array {
  return [
    'date' => $date,
    'actual_start' => null,
    'actual_end' => null,
    'rounded_start' => null,
    'rounded_end' => null,
    'actual_worked' => 0,
    'rounded_worked' => 0,
    'break_total' => 0,
    'paid_break' => 0,
    'unpaid_break' => 0,
    'paid' => 0,
    'weekend' => 0,
    'bank_holiday' => 0,
    'night' => 0,
    'callout' => 0,
    'training' => 0,
    'dst_delta' => 0,
    'shifts' => [],
  ];
}
// Payroll UI date formatter (easier to scan than weekday-based formats).
// Storage remains UTC; this is display-only.
function payroll_fmt_date_ui(string $dateYmd, string $tz): string {
  try {
    return (new DateTimeImmutable($dateYmd . ' 00:00:00', new DateTimeZone($tz)))->format('d M Y');
  } catch (Throwable $e) {
    return $dateYmd;
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

/**
 * Expand a month period to full payroll weeks (context days) based on immutable week start.
 * Month totals should still only count days within the calendar month.
 *
 * @return array{start:string,end:string,start_date:string,end_date:string,month_start_date:string,month_end_date:string}
 */
function month_context_period(array $monthPeriod, array $settings): array {
  $tz = (string)($settings['payroll_timezone'] ?? 'UTC');
  $weekStartsOn = (string)($settings['payroll_week_starts_on'] ?? 'MONDAY');

  $monthStart = (string)$monthPeriod['start_date'];
  $monthEnd = (string)$monthPeriod['end_date'];

  $ctxStartDate = week_start_date($monthStart, $weekStartsOn, $tz);
  $lastWeekStart = week_start_date($monthEnd, $weekStartsOn, $tz);
  $ctxEndDate = (new DateTimeImmutable($lastWeekStart . ' 00:00:00', new DateTimeZone($tz)))
    ->modify('+6 days')
    ->format('Y-m-d');

  $ctxStartUtc = (new DateTimeImmutable($ctxStartDate . ' 00:00:00', new DateTimeZone($tz)))
    ->setTimezone(new DateTimeZone('UTC'));
  $ctxEndExclusiveUtc = (new DateTimeImmutable($ctxEndDate . ' 00:00:00', new DateTimeZone($tz)))
    ->modify('+1 day')
    ->setTimezone(new DateTimeZone('UTC'));

  return [
    'start' => $ctxStartUtc->format('Y-m-d H:i:s'),
    'end' => $ctxEndExclusiveUtc->format('Y-m-d H:i:s'),
    'start_date' => $ctxStartDate,
    'end_date' => $ctxEndDate,
    'month_start_date' => $monthStart,
    'month_end_date' => $monthEnd,
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
    'default_break_minutes' => 0,
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
  if (isset($map['default_break_minutes']) && is_numeric($map['default_break_minutes'])) {
    $out['default_break_minutes'] = (int)$map['default_break_minutes'];
  }
  // Paid vs unpaid break is determined per employee contract (pay profile).
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
  $out['default_break_minutes'] = max(0, (int)$out['default_break_minutes']);
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

/** @return array<int,array{start_time:string,end_time:string,break_minutes:int,priority:int}> */
function load_break_rules(PDO $pdo): array {
  try {
    $rows = $pdo->query("SELECT start_time,end_time,break_minutes,priority FROM kiosk_break_rules WHERE is_enabled=1 ORDER BY priority DESC, id DESC")
      ->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
  $out = [];
  foreach ($rows as $r) {
    $out[] = [
      'start_time' => (string)$r['start_time'],
      'end_time' => (string)$r['end_time'],
      'break_minutes' => (int)$r['break_minutes'],
      'priority' => (int)$r['priority'],
    ];
  }
  return $out;
}

/** @return array{break_minutes:int} */
function match_break_rule(string $shiftStartHm, array $rules, int $defaultMinutes): array {
  $shiftStartHm = substr($shiftStartHm, 0, 5);
  foreach ($rules as $r) {
    $s = substr((string)($r['start_time'] ?? ''), 0, 5);
    $e = substr((string)($r['end_time'] ?? ''), 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e)) continue;

    if ($s <= $e) {
      // Normal window: [s,e)
      if ($shiftStartHm >= $s && $shiftStartHm < $e) {
        return ['break_minutes' => max(0,(int)($r['break_minutes'] ?? 0))];
      }
    } else {
      // Cross-midnight: match if start >= s OR start < e
      if ($shiftStartHm >= $s || $shiftStartHm < $e) {
        return ['break_minutes' => max(0,(int)($r['break_minutes'] ?? 0))];
      }
    }
  }
  return ['break_minutes' => max(0,$defaultMinutes)];
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
function compute_employee_month(PDO $pdo, int $employeeId, array $period, array $settings, array $bankHolidayIndex, array $breakRules): array {
  $tz = (string)$settings['payroll_timezone'];

  $sql = "
    SELECT
      s.*,
      e.employee_code, e.first_name, e.last_name, e.nickname, e.is_agency, e.agency_label,
      p.contract_hours_per_week,
      p.break_is_paid,
      p.inherit_from_carehome, p.overtime_threshold_hours,
      NULL AS latest_edit_json
    FROM kiosk_shifts s
    JOIN kiosk_employees e ON e.id = s.employee_id
    LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = e.id
    WHERE s.employee_id = :emp
      AND s.clock_in_at >= :from_dt AND s.clock_in_at < :to_dt
      AND s.approved_at IS NOT NULL
    ORDER BY s.clock_in_at ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':emp'=>$employeeId, ':from_dt'=>$period['start'], ':to_dt'=>$period['end']]);
  $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $contractHours = (float)($shifts[0]['contract_hours_per_week'] ?? 0);

  // Overtime threshold (minutes/week): employee profile overrides contract hours.
  $th = $shifts ? ($shifts[0]['overtime_threshold_hours'] ?? null) : null;
  $thresholdHours = 0.0;
  if ($th !== null && is_numeric($th)) {
    $thresholdHours = (float)$th;
  } else {
    $thresholdHours = (float)($shifts[0]['contract_hours_per_week'] ?? 0);
  }
  $contractWeekMinutes = (int)round(max(0.0, $thresholdHours) * 60);


  // Training minutes are shown separately and excluded from OT on this page.
  $trainingMinutes = 0;
  foreach ($shifts as $s) {
    $tm = (int)($s['training_minutes'] ?? 0);
    if ($tm > 0) $trainingMinutes += $tm;
  }

  $days = []; // date => aggregates
  // Pre-fill all days in the selected month (so employee view always shows a full month)
  try {
    $d0 = new DateTimeImmutable((string)$period['month_start_date'] . ' 00:00:00', new DateTimeZone($tz));
    $d1 = new DateTimeImmutable((string)$period['month_end_date'] . ' 00:00:00', new DateTimeZone($tz));
    for ($d = $d0; $d <= $d1; $d = $d->modify('+1 day')) {
      $k = $d->format('Y-m-d');
      $days[$k] = day_row_default($k);
    }
  } catch (Throwable $e) { /* ignore */ }
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

    // Break minutes (LOCKED): match by shift start time against care-home break rules.
    $shiftStartLocalHm = (new DateTimeImmutable($rin, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('H:i');
    $defaultBreakMin = (int)($settings['default_break_minutes'] ?? 0);
    $br = match_break_rule($shiftStartLocalHm, $breakRules, $defaultBreakMin);
    $breakMinutes = max(0, (int)$br['break_minutes']);
    $breakMinutes = min($breakMinutes, $workedRounded);
    $employeeBreakPaid = ((int)($s['break_is_paid'] ?? 0) === 1);

    $breakTotal = $breakMinutes;
    $paidBreakTotal = $employeeBreakPaid ? $breakMinutes : 0;
    $unpaidBreakTotal = $employeeBreakPaid ? 0 : $breakMinutes;

    // Split both actual and rounded intervals by local day
    $actualSegs = split_by_day_detailed($actualIn, $actualOut, $period['start'], $period['end'], $tz);
    $roundedSegs = split_by_day_detailed($rin, $rout, $period['start'], $period['end'], $tz);

    $roundedTotalSegMin = 0;
    foreach ($roundedSegs as $seg) $roundedTotalSegMin += (int)$seg['minutes_utc'];
    if ($roundedTotalSegMin <= 0) continue;

    // Allocate break across rounded segments proportionally.
    $shiftPaidTotal = 0;
    $shiftStartDate = (new DateTimeImmutable($rin, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    $isCallout = ((int)($s['is_callout'] ?? 0) === 1);
    $remainingBreak = $breakTotal;
    foreach ($roundedSegs as $idx => $seg) {
      $segMin = (int)$seg['minutes_utc'];
      if ($segMin <= 0) continue;

      $allocBreakTotal = 0;
      if ($breakTotal > 0) {
        if ($idx === array_key_last($roundedSegs)) {
          $allocBreakTotal = $remainingBreak;
        } else {
          $allocBreakTotal = (int)floor($breakTotal * ($segMin / $roundedTotalSegMin));
          $allocBreakTotal = min($allocBreakTotal, $remainingBreak);
        }
      }
      $remainingBreak -= $allocBreakTotal;

      $allocPaidBreak = $employeeBreakPaid ? $allocBreakTotal : 0;
      $allocUnpaidBreak = $employeeBreakPaid ? 0 : $allocBreakTotal;

      $paidSeg = max(0, $segMin - max(0, $allocUnpaidBreak));
      $shiftPaidTotal += $paidSeg;
      $date = (string)$seg['date'];

      if (!isset($days[$date])) {
        $days[$date] = day_row_default($date);
      }

      // Rounded time bounds
      $days[$date]['rounded_worked'] += $segMin;
      $days[$date]['break_total'] += $allocBreakTotal;
      $days[$date]['paid_break'] += $allocPaidBreak;
      $days[$date]['unpaid_break'] += $allocUnpaidBreak;
      $days[$date]['paid'] += $paidSeg;
      $days[$date]['dst_delta'] += (int)($seg['dst_delta'] ?? 0);

      if ($days[$date]['rounded_start'] === null || (string)$seg['start_utc'] < (string)$days[$date]['rounded_start']) {
        $days[$date]['rounded_start'] = (string)$seg['start_utc'];
      }
      if ($days[$date]['rounded_end'] === null || (string)$seg['end_utc'] > (string)$days[$date]['rounded_end']) {
        $days[$date]['rounded_end'] = (string)$seg['end_utc'];
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
          $days[$shiftStartDate] = day_row_default($shiftStartDate);
        }
        $days[$shiftStartDate]['paid'] += $uplift;
        $days[$shiftStartDate]['callout'] += $uplift;
      }
    }

    foreach ($actualSegs as $aseg) {
      $date = (string)$aseg['date'];
      if (!isset($days[$date])) {
        // If rounding moved all paid time away, still create day entry so payroll sees actual.
        $days[$date] = day_row_default($date);
      }
      $days[$date]['actual_worked'] += (int)$aseg['minutes_utc'];
      if ($days[$date]['actual_start'] === null || (string)$aseg['start_utc'] < (string)$days[$date]['actual_start']) {
        $days[$date]['actual_start'] = (string)$aseg['start_utc'];
      }
      if ($days[$date]['actual_end'] === null || (string)$aseg['end_utc'] > (string)$days[$date]['actual_end']) {
        $days[$date]['actual_end'] = (string)$aseg['end_utc'];
      }
    }
  }

  ksort($days);

  // Week grouping + OT (training excluded)
  $weeks = []; // weekStart => ['days'=>[date=>ref], 'totals'=>...]
  $totalPaid = 0;
  $totalBreak = 0;
  $totalPaidBreak = 0;
  $totalUnpaidBreak = 0; // kept for backwards compatibility
  $totalWeekend = 0;
  $totalBH = 0;
  $totalNight = 0;
  $totalCallout = 0;
  $totalDst = 0;

  foreach ($days as $date => $d) {
    $inMonth = ($date >= (string)$period['month_start_date'] && $date <= (string)$period['month_end_date']);
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

    // Month totals exclude context days.
    if ($inMonth) {
      $totalPaid += (int)$d['paid'];
      $totalBreak += (int)($d['break_total'] ?? 0);
      $totalPaidBreak += (int)($d['paid_break'] ?? 0);
      $totalUnpaidBreak += (int)($d['unpaid_break'] ?? 0);
      $totalWeekend += (int)$d['weekend'];
      $totalBH += (int)$d['bank_holiday'];
      $totalNight += (int)$d['night'];
      $totalCallout += (int)($d['callout'] ?? 0);
      $totalDst += (int)$d['dst_delta'];
    }
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
      'break_total' => $totalBreak,
      'paid_break' => $totalPaidBreak,
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
$defaultMonth = (int)$now->format('n');
$defaultYear  = (int)$now->format('Y');

$month = int_param('month', $defaultMonth);
$year  = int_param('year', $defaultYear);
$employeeId = (int)($_GET['employee_id'] ?? 0);

// Dropdown options (were accidentally removed in some edits).
$monthOptions = range(1, 12);
// Show a sensible rolling window; you can widen this later if needed.
$yearOptions = range($defaultYear - 2, $defaultYear + 1);

$monthPeriod = calendar_month_period($year, $month);
$settings = load_payroll_settings($pdo);
$tz = (string)$settings['payroll_timezone'];
$period = month_context_period($monthPeriod, $settings);
$bankHolidayIndex = load_bank_holiday_index($pdo, $period['start_date'], $period['end_date']);
$breakRules = load_break_rules($pdo);

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
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM kiosk_shifts WHERE clock_in_at >= :s AND clock_in_at < :e AND approved_at IS NULL");
$stmt->execute([':s'=>$monthPeriod['start'], ':e'=>$monthPeriod['end']]);
$unapprovedCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

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
                Period: <span class="font-semibold text-white"><?= h(payroll_fmt_date_ui($monthPeriod['start_date'], $tz)) ?></span> to <span class="font-semibold text-white"><?= h(payroll_fmt_date_ui($monthPeriod['end_date'], $tz)) ?></span>
                · Timezone: <span class="font-semibold text-white"><?= h($tz) ?></span>
                · Week starts: <span class="font-semibold text-white"><?= h((string)$settings['payroll_week_starts_on']) ?></span>
              </div>
              <div class="mt-1 text-xs text-white/50">OT context range: <?= h(payroll_fmt_date_ui($period['start_date'], $tz)) ?> → <?= h(payroll_fmt_date_ui($period['end_date'], $tz)) ?> (context days are greyed and excluded from month totals)</div>
              <div class="mt-1 text-xs text-white/50">Hours-only view (no pay). Training is separate and excluded from overtime here.</div>
            </div>
          </div>

          <div class="mt-4 bg-white/5 border border-white/10 rounded-3xl p-4">
            <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div>
                <label class="text-xs text-white/60">Year</label>
                <select name="year" class="mt-1 w-full rounded-2xl bg-slate-900/60 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                  <?php foreach ($yearOptions as $yy): ?>
                    <option value="<?= (int)$yy ?>" <?= ((int)$yy===$year)?'selected':'' ?>><?= (int)$yy ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="text-xs text-white/60">Month</label>
                <select name="month" class="mt-1 w-full rounded-2xl bg-slate-900/60 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                  <?php foreach ($monthOptions as $mm): ?>
                    <option value="<?= (int)$mm ?>" <?= ((int)$mm===$month)?'selected':'' ?>><?= (int)$mm ?> - <?= h(date('F', mktime(0,0,0,$mm,1,$year))) ?></option>
                  <?php endforeach; ?>
                </select>
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
              <div class="flex gap-2">
                <button type="button" data-preset="this" class="w-full text-center rounded-2xl px-4 py-2.5 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">This month</button>
                <button type="button" data-preset="last" class="w-full text-center rounded-2xl px-4 py-2.5 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Last month</button>
              </div>
              <div>
                <button class="w-full rounded-2xl px-4 py-2.5 text-sm font-semibold bg-sky-600 hover:bg-sky-500">View</button>
              </div>
            </form>
          </div>

          <?php if ($unapprovedCount > 0): ?>
            <div class="mt-4 rounded-3xl border border-amber-500/30 bg-amber-500/10 p-4">
              <div class="font-semibold text-amber-100">Unapproved shifts in this period</div>
              <div class="mt-1 text-sm text-amber-100/90">
                There are <b><?= (int)$unapprovedCount ?></b> unapproved shifts between <?= h(payroll_fmt_date_ui($monthPeriod['start_date'], $tz)) ?> and <?= h(payroll_fmt_date_ui($monthPeriod['end_date'], $tz)) ?>.
                Payroll should not be finalised until managers approve everything.
              </div>
              <div class="mt-2">
                <a class="underline text-amber-100" href="shifts.php?mode=range&from=<?= h($monthPeriod['start_date']) ?>&to=<?= h($monthPeriod['end_date']) ?>&status=unapproved">Open unapproved shifts</a>
              </div>
            </div>
          <?php endif; ?>

          <?php
            // ALL EMPLOYEES SUMMARY
            if ($employeeId === 0):
              $grand = ['paid'=>0,'break_total'=>0,'paid_break'=>0,'unpaid_break'=>0,'weekend'=>0,'bank_holiday'=>0,'night'=>0,'callout'=>0,'overtime'=>0,'training'=>0,'dst_delta'=>0];
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
                      <th class="text-right px-4 py-3">To pay (h)</th>
	                      <th class="text-right px-4 py-3">Break (min)</th>
	                      <th class="text-right px-4 py-3">Paid break (min)</th>
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
                        $calc = compute_employee_month($pdo, $empId, $period, $settings, $bankHolidayIndex, $breakRules);
                        $t = $calc['totals'];
                        $trainingMin = (int)$calc['training_minutes'];
                        $grand['paid'] += (int)$t['paid'];
                        $grand['break_total'] += (int)($t['break_total'] ?? 0);
                        $grand['paid_break'] += (int)($t['paid_break'] ?? 0);
                        $grand['unpaid_break'] += (int)($t['unpaid_break'] ?? 0);
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
	                        <td class="px-4 py-3 text-right"><?= h((string)(int)($t['break_total'] ?? 0)) ?></td>
	                        <td class="px-4 py-3 text-right"><?= h((string)(int)($t['paid_break'] ?? 0)) ?></td>
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
	                      <th class="text-right px-4 py-3"><?= h((string)(int)$grand['break_total']) ?></th>
	                      <th class="text-right px-4 py-3"><?= h((string)(int)$grand['paid_break']) ?></th>
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
              $calc = compute_employee_month($pdo, $employeeId, $period, $settings, $bankHolidayIndex, $breakRules);
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
                        <th class="text-right px-4 py-3">Worked (h)</th>
	                        <th class="text-right px-4 py-3">Break (min)</th>
	                        <th class="text-right px-4 py-3">Paid break (min)</th>
                        <th class="text-right px-4 py-3">To pay (h)</th>
                        <th class="text-right px-4 py-3">Weekend (h)</th>
                        <th class="text-right px-4 py-3">BH (h)</th>
                        <th class="text-right px-4 py-3">Night (h)</th>
                        <th class="text-right px-4 py-3">Call-out (h)</th>
                        <th class="text-right px-4 py-3">DST Δ (min)</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($w['days'] as $date => $d): ?>
                        <?php $inMonth = ($date >= (string)$period['month_start_date'] && $date <= (string)$period['month_end_date']); ?>
                        <tr class="border-t border-white/10 <?= $inMonth ? '' : 'bg-white/5 text-white/50' ?>">
                          <td class="px-4 py-3 font-semibold">
                            <?= h(payroll_fmt_date_ui($date, $tz)) ?>
                            <div class="text-xs text-white/40"><?= h($date) ?></div>
                          </td>
                          <td class="px-4 py-3">
                            <div class="font-semibold text-white">
                              <?php
                                $rs = (string)($d['rounded_start'] ?? '');
                                $re = (string)($d['rounded_end'] ?? '');
                                $as = (string)($d['actual_start'] ?? '');
                                $ae = (string)($d['actual_end'] ?? '');
                              ?>
                              <?= h(($rs !== '' && $re !== '') ? (fmt_dt_range_compact($rs, $re, $tz)) : '—') ?>
                            </div>
                            <div class="mt-1 text-[11px] leading-4 text-white/50">
                              Actual: <?= h(($as !== '' && $ae !== '') ? (fmt_dt_range_compact($as, $ae, $tz)) : '—') ?>
                            </div>
                          </td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)($d['rounded_worked'] ?? 0))/60, 2)) ?></td>
	                          <td class="px-4 py-3 text-right"><?= h((string)(int)($d['break_total'] ?? 0)) ?></td>
	                          <td class="px-4 py-3 text-right"><?= h((string)(int)($d['paid_break'] ?? 0)) ?></td>
                          <td class="px-4 py-3 text-right"><?= h(number_format(((int)$d['paid'])/60, 2)) ?></td>
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
                  <div>To pay: <b><?= h(number_format(((int)$tot['paid'])/60, 2)) ?>h</b></div>
	                  <div>Break: <b><?= h((string)(int)($tot['break_total'] ?? 0)) ?> min</b></div>
	                  <div>Paid break: <b><?= h((string)(int)($tot['paid_break'] ?? 0)) ?> min</b></div>
                  <div>Weekend: <b><?= h(number_format(((int)$tot['weekend'])/60, 2)) ?>h</b></div>
                  <div>Bank holiday: <b><?= h(number_format(((int)$tot['bank_holiday'])/60, 2)) ?>h</b></div>
                  <div>Night: <b><?= h(number_format(((int)$tot['night'])/60, 2)) ?>h</b></div>
                  <div>Call-out: <b><?= h(number_format(((int)($tot['callout'] ?? 0))/60, 2)) ?>h</b></div>
                  <div>Overtime (weekly): <b><?= h(number_format(((int)$tot['overtime'])/60, 2)) ?>h</b></div>
                  <div>Training (separate): <b><?= h(number_format($trainingMin/60, 2)) ?>h</b></div>
                  <div>DST Δ total: <b><?= (int)$tot['dst_delta'] ?> min</b></div>
                </div>
              </div>
              <div class="mt-2 text-xs text-white/50">Note: OT here is calculated from to-pay hours only (training excluded). Month totals exclude context days outside the calendar month.</div>
            </div>

          <?php endif; ?>

        
<script>
(function(){
  const form = document.querySelector('form[method="get"]');
  if (!form) return;
  const yearSel = form.querySelector('select[name="year"]');
  const monthSel = form.querySelector('select[name="month"]');
  const btns = form.querySelectorAll('button[data-preset]');
  if (!yearSel || !monthSel || !btns.length) return;
  const now = new Date();
  function setYM(d){
    yearSel.value = String(d.getFullYear());
    monthSel.value = String(d.getMonth()+1);
  }
  btns.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const preset = btn.getAttribute('data-preset');
      const d = new Date(now.getTime());
      if (preset === 'last') d.setMonth(d.getMonth()-1);
      setYM(d);
      form.submit();
    });
  });
})();
</script>

</main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
