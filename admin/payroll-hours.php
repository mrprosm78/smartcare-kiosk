<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

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
  // Keep in sync with PayrollCalculator defaults (hours-only subset).
  $defaults = [
    'rounding_enabled' => true,
    'round_increment_minutes' => 15,
    'round_grace_minutes' => 5,
    'payroll_week_starts_on' => 'MONDAY',
    'payroll_timezone' => 'Europe/London',
    'night_shift_threshold_percent' => 50,
    // Global night premium window used for the "Night hours" bucket.
    // If you want to use employee-specific night windows, disable this
    // and payroll-hours will fall back to the employee pay profile.
    'night_premium_enabled' => true,
    'night_premium_start' => '22:00:00',
    'night_premium_end' => '06:00:00',
    'weekend_premium_enabled' => false,
    'weekend_days' => ['SAT','SUN'],
    'bank_holiday_enabled' => true,
    'bank_holiday_paid' => true,
    // Bank holiday paid hours cap per day (care-home rule: paid up to 12h)
    'bank_holiday_paid_cap_hours' => 12,
  ];

  $keys = array_keys($defaults);
  $in = implode(',', array_fill(0, count($keys), '?'));
  $stmt = $pdo->prepare("SELECT `key`,`value` FROM kiosk_settings WHERE `key` IN ($in)");
  $stmt->execute($keys);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $map = [];
  foreach ($rows as $r) $map[(string)$r['key']] = (string)$r['value'];

  $out = $defaults;
  $out['rounding_enabled'] = isset($map['rounding_enabled']) ? ((int)$map['rounding_enabled'] === 1) : $defaults['rounding_enabled'];
  $out['round_increment_minutes'] = isset($map['round_increment_minutes']) ? (int)$map['round_increment_minutes'] : $defaults['round_increment_minutes'];
  $out['round_grace_minutes'] = isset($map['round_grace_minutes']) ? (int)$map['round_grace_minutes'] : $defaults['round_grace_minutes'];

  if (isset($map['payroll_week_starts_on']) && trim($map['payroll_week_starts_on']) !== '') {
    $out['payroll_week_starts_on'] = strtoupper(trim($map['payroll_week_starts_on']));
  }
  if (isset($map['payroll_timezone']) && trim($map['payroll_timezone']) !== '') {
    $out['payroll_timezone'] = trim($map['payroll_timezone']);
  }
  if (isset($map['night_shift_threshold_percent']) && is_numeric($map['night_shift_threshold_percent'])) {
    $out['night_shift_threshold_percent'] = (int)$map['night_shift_threshold_percent'];
  }
  $out['night_premium_enabled'] = isset($map['night_premium_enabled']) ? ((int)$map['night_premium_enabled']===1) : $defaults['night_premium_enabled'];
  if (isset($map['night_premium_start']) && trim($map['night_premium_start']) !== '') {
    $out['night_premium_start'] = trim((string)$map['night_premium_start']);
  }
  if (isset($map['night_premium_end']) && trim($map['night_premium_end']) !== '') {
    $out['night_premium_end'] = trim((string)$map['night_premium_end']);
  }
  $out['weekend_premium_enabled'] = isset($map['weekend_premium_enabled']) ? ((int)$map['weekend_premium_enabled']===1) : $defaults['weekend_premium_enabled'];
  if (isset($map['weekend_days']) && $map['weekend_days'] !== '') {
    $arr = json_decode($map['weekend_days'], true);
    if (is_array($arr)) {
      $out['weekend_days'] = array_values(array_map(fn($x) => strtoupper((string)$x), $arr));
    }
  }
  $out['bank_holiday_enabled'] = isset($map['bank_holiday_enabled']) ? ((int)$map['bank_holiday_enabled']===1) : $defaults['bank_holiday_enabled'];
  $out['bank_holiday_paid'] = isset($map['bank_holiday_paid']) ? ((int)$map['bank_holiday_paid']===1) : $defaults['bank_holiday_paid'];
  if (isset($map['bank_holiday_paid_cap_hours']) && is_numeric($map['bank_holiday_paid_cap_hours'])) {
    $out['bank_holiday_paid_cap_hours'] = (int)$map['bank_holiday_paid_cap_hours'];
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
  $out['night_shift_threshold_percent'] = max(0, min(100, (int)$out['night_shift_threshold_percent']));
  $out['bank_holiday_paid_cap_hours'] = max(0, (int)$out['bank_holiday_paid_cap_hours']);

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
  $defaultStart = '22:00:00';
  $defaultEnd = '06:00:00';
  $useGlobal = (bool)($settings['night_premium_enabled'] ?? true);
  if ($useGlobal) {
    $ns = normalize_hms((string)($settings['night_premium_start'] ?? ''), $defaultStart);
    $ne = normalize_hms((string)($settings['night_premium_end'] ?? ''), $defaultEnd);
    return ['start'=>$ns, 'end'=>$ne];
  }
  $ns = normalize_hms((string)($row['night_start'] ?? ''), $defaultStart);
  $ne = normalize_hms((string)($row['night_end'] ?? ''), $defaultEnd);
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
      p.break_minutes_default, p.break_minutes_day, p.break_minutes_night,
      p.break_is_paid, p.min_hours_for_break,
      p.night_start, p.night_end,
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
    WHERE s.employee_id = :emp
      AND s.clock_in_at >= :from_dt AND s.clock_in_at < :to_dt
      AND s.approved_at IS NOT NULL
    ORDER BY s.clock_in_at ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':emp'=>$employeeId, ':from_dt'=>$period['start'], ':to_dt'=>$period['end']]);
  $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $contractHours = (float)($shifts[0]['contract_hours_per_week'] ?? 0);
  $contractWeekMinutes = (int)round(max(0.0, $contractHours) * 60);

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
        : ($s['break_minutes_day'] ?? ($s['break_minutes_default'] ?? 0))
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
      if ((bool)$settings['bank_holiday_enabled'] && (bool)$settings['bank_holiday_paid'] && isset($bankHolidayIndex[$date])) {
        $capHours = (int)($settings['bank_holiday_paid_cap_hours'] ?? 12);
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
    }

    // Accumulate ACTUAL worked minutes + bounds by day (independently)
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
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM kiosk_shifts WHERE clock_in_at >= :s AND clock_in_at < :e AND approved_at IS NULL");
$stmt->execute([':s'=>$period['start'], ':e'=>$period['end']]);
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
              $grand = ['paid'=>0,'unpaid_break'=>0,'weekend'=>0,'bank_holiday'=>0,'night'=>0,'overtime'=>0,'training'=>0,'dst_delta'=>0];
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
