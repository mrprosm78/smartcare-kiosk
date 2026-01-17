<?php
declare(strict_types=1);

/**
 * PayrollCalculator (Milestone 1)
 *
 * - Monthly pay period (calendar month)
 * - Weekly overtime classification (week start configurable)
 * - Premium extras (weekend + bank holiday)
 * - Uses APPROVED shifts only
 *
 * Assumptions for this milestone:
 * - Contract/rates taken from kiosk_employee_pay_profiles (current profile)
 * - Overtime priority implemented: PREMIUMS_THEN_OVERTIME
 */

final class PayrollCalculator {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  /** @return array{start:string,end:string,start_date:string,end_date:string} */
  public static function calendarMonthPeriod(int $year, int $month): array {
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
   * Run a payroll calculation for a month.
   *
   * @return array{
   *   period: array{start:string,end:string,start_date:string,end_date:string},
   *   settings: array,
   *   employees: array<int, array>,
   *   exceptions: array{missing_rate: array<int,array>, missing_contract_hours: array<int,array>, missing_clock_out: array<int,array>}
   * }
   */
  public function calculateForMonth(int $year, int $month, bool $includeLocked = false): array {
    $period = self::calendarMonthPeriod($year, $month);
    $settings = $this->loadPayrollSettings();
    $bankHolidayIndex = $this->loadBankHolidayIndex($period['start_date'], $period['end_date']);

    $shifts = $this->fetchApprovedShifts($period['start'], $period['end'], $includeLocked);

    $usedShiftIds = []; // shift ids included in calculation

    $exceptions = [
      'missing_rate' => [],
      'missing_contract_hours' => [],
      'missing_clock_out' => [],
    ];

    // Per employee weekly buckets
    $weekly = []; // [empId][weekKey] => ['paid_minutes'=>int,'premium_extra'=>float]
    $employeeMeta = []; // [empId] => ['employee_code','name','hourly_rate','contract_hours_per_week','overtime_multiplier']

    foreach ($shifts as $row) {
      $empId = (int)$row['employee_id'];
      $meta = $this->employeeMetaFromRow($row, $settings);
      $employeeMeta[$empId] = $meta;

      if ($meta['hourly_rate'] <= 0) {
        $exceptions['missing_rate'][$empId] = $meta;
      }
      if ($meta['contract_hours_per_week'] <= 0) {
        $exceptions['missing_contract_hours'][$empId] = $meta;
      }

      $eff = $this->effectiveShiftTimes($row);
      $in = $eff['clock_in_at'];
      $out = $eff['clock_out_at'];
      if ($in === '' || $out === '') {
        $exceptions['missing_clock_out'][] = ['shift_id' => (int)$row['id'], 'employee_id'=>$empId] + $meta;
        continue;
      }

      // Apply rounding rules (existing system settings)
      [$rin, $rout] = $this->applyRounding($in, $out, $settings);

      $workedMinutes = $this->minutesBetween($rin, $rout);
      if ($workedMinutes <= 0) continue;

      // Break rules (Milestone 1: use default break + min hours)
      $breakMinutes = $eff['break_minutes'] !== null ? (int)$eff['break_minutes'] : (int)($row['break_minutes_default'] ?? 0);
      $breakIsPaid = ((int)($row['break_is_paid'] ?? 0) === 1);
      $minHours = $row['min_hours_for_break'] !== null ? (float)$row['min_hours_for_break'] : 0.0;
      if ($minHours > 0 && ($workedMinutes/60.0) < $minHours) {
        $breakMinutes = 0;
      }

      $paidMinutes = $workedMinutes;
      if (!$breakIsPaid) {
        $paidMinutes = max(0, $workedMinutes - max(0, $breakMinutes));
      }
      if ($paidMinutes <= 0) continue;

      $usedShiftIds[] = (int)$row['id'];

      // Split into day segments for premium computation, then into week buckets.
      $segments = $this->splitByDay($rin, $rout, $period['start'], $period['end']);

      // Allocate paid minutes proportionally across segments
      $totalSegMinutes = array_sum(array_column($segments, 'minutes'));
      if ($totalSegMinutes <= 0) continue;

      $alloc = [];
      $remainingPaid = $paidMinutes;
      $remainingSeg = $totalSegMinutes;
      foreach ($segments as $i => $seg) {
        $segMinutes = (int)$seg['minutes'];
        if ($i === array_key_last($segments)) {
          $paidSeg = $remainingPaid;
        } else {
          $paidSeg = (int)floor($paidMinutes * ($segMinutes / $totalSegMinutes));
          $paidSeg = min($paidSeg, $remainingPaid);
        }
        $remainingPaid -= $paidSeg;
        $remainingSeg -= $segMinutes;

        $premiumExtra = $this->premiumExtraForSegment($seg['date'], $paidSeg, $meta, $settings, $bankHolidayIndex);

        $weekKey = $this->weekKeyForDate($seg['date'], $settings['payroll_week_starts_on']);
        if (!isset($weekly[$empId])) $weekly[$empId] = [];
        if (!isset($weekly[$empId][$weekKey])) {
          $weekly[$empId][$weekKey] = [
            'paid_minutes' => 0,
            'premium_extra' => 0.0,
            'week_start' => $this->weekStartDate($seg['date'], $settings['payroll_week_starts_on']),
          ];
        }
        $weekly[$empId][$weekKey]['paid_minutes'] += $paidSeg;
        $weekly[$empId][$weekKey]['premium_extra'] += $premiumExtra;
      }
    }

    // Compute weekly overtime & amounts; then aggregate for month.
    $employeesOut = [];
    foreach ($employeeMeta as $empId => $meta) {
      $weeks = $weekly[$empId] ?? [];
      ksort($weeks);

      $contractWeekMinutes = (int)round(max(0.0, $meta['contract_hours_per_week']) * 60);

      $perWeek = [];
      $month = [
        'regular_minutes' => 0,
        'overtime_minutes' => 0,
        'premium_extra' => 0.0,
        'regular_amount' => 0.0,
        'overtime_amount' => 0.0,
        'gross_pay' => 0.0,
      ];

      foreach ($weeks as $wk => $w) {
        $paid = (int)$w['paid_minutes'];
        $regular = $paid;
        $ot = 0;
        if ($contractWeekMinutes > 0) {
          $regular = min($paid, $contractWeekMinutes);
          $ot = max(0, $paid - $contractWeekMinutes);
        }

        $rate = (float)$meta['hourly_rate'];
        $otMult = (float)$meta['overtime_multiplier'];

        $regularAmt = ($regular/60.0) * $rate;
        $otAmt = ($ot/60.0) * $rate * $otMult;
        $prem = (float)$w['premium_extra'];
        $gross = $regularAmt + $otAmt + $prem;

        $perWeek[] = [
          'week_key' => $wk,
          'week_start' => $w['week_start'],
          'paid_hours' => round($paid/60.0, 2),
          'regular_hours' => round($regular/60.0, 2),
          'overtime_hours' => round($ot/60.0, 2),
          'premium_extra' => round($prem, 2),
          'regular_amount' => round($regularAmt, 2),
          'overtime_amount' => round($otAmt, 2),
          'gross_pay' => round($gross, 2),
        ];

        $month['regular_minutes'] += $regular;
        $month['overtime_minutes'] += $ot;
        $month['premium_extra'] += $prem;
        $month['regular_amount'] += $regularAmt;
        $month['overtime_amount'] += $otAmt;
        $month['gross_pay'] += $gross;
      }

      $employeesOut[$empId] = [
        'employee_id' => $empId,
        'employee_code' => $meta['employee_code'],
        'name' => $meta['name'],
        'hourly_rate' => round((float)$meta['hourly_rate'], 2),
        'contract_hours_per_week' => (float)$meta['contract_hours_per_week'],
        'overtime_multiplier' => (float)$meta['overtime_multiplier'],
        'totals' => [
          'regular_hours' => round($month['regular_minutes']/60.0, 2),
          'overtime_hours' => round($month['overtime_minutes']/60.0, 2),
          'premium_extra' => round($month['premium_extra'], 2),
          'regular_amount' => round($month['regular_amount'], 2),
          'overtime_amount' => round($month['overtime_amount'], 2),
          'gross_pay' => round($month['gross_pay'], 2),
        ],
        'weeks' => $perWeek,
      ];
    }

    // Sort employees by name
    uasort($employeesOut, fn($a,$b) => strcmp((string)$a['name'], (string)$b['name']));

    return [
      'period' => $period,
      'settings' => $settings,
      'employees' => $employeesOut,
      'exceptions' => $exceptions,
    'used_shift_ids' => $usedShiftIds,
    ];
  }

  /** @return array<string,mixed> */
  private function loadPayrollSettings(): array {
    // Defaults
    $defaults = [
      'rounding_enabled' => true,
      'round_increment_minutes' => 15,
      'round_grace_minutes' => 5,
      'payroll_week_starts_on' => 'MONDAY',
      'overtime_default_multiplier' => 1.5,
      'weekend_premium_enabled' => false,
      'weekend_days' => ['SAT','SUN'],
      'weekend_rate_multiplier' => 1.25,
      'bank_holiday_enabled' => true,
      'bank_holiday_paid' => true,
      'bank_holiday_rate_multiplier' => 1.5,
      'payroll_overtime_priority' => 'PREMIUMS_THEN_OVERTIME',
    ];

    $keys = array_keys($defaults);
    $in = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $this->pdo->prepare("SELECT `key`,`value` FROM kiosk_settings WHERE `key` IN ($in)");
    $stmt->execute($keys);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $r) $map[(string)$r['key']] = (string)$r['value'];

    $out = $defaults;
    $out['rounding_enabled'] = isset($map['rounding_enabled']) ? ((int)$map['rounding_enabled'] === 1) : $defaults['rounding_enabled'];
    $out['round_increment_minutes'] = isset($map['round_increment_minutes']) ? (int)$map['round_increment_minutes'] : $defaults['round_increment_minutes'];
    $out['round_grace_minutes'] = isset($map['round_grace_minutes']) ? (int)$map['round_grace_minutes'] : $defaults['round_grace_minutes'];

    if (isset($map['payroll_week_starts_on']) && $map['payroll_week_starts_on'] !== '') {
      $out['payroll_week_starts_on'] = strtoupper(trim($map['payroll_week_starts_on']));
    }
    if (isset($map['overtime_default_multiplier']) && is_numeric($map['overtime_default_multiplier'])) {
      $out['overtime_default_multiplier'] = (float)$map['overtime_default_multiplier'];
    }
    $out['weekend_premium_enabled'] = isset($map['weekend_premium_enabled']) ? ((int)$map['weekend_premium_enabled']===1) : $defaults['weekend_premium_enabled'];
    if (isset($map['weekend_days']) && $map['weekend_days'] !== '') {
      try {
        $arr = json_decode($map['weekend_days'], true);
        if (is_array($arr)) {
          $out['weekend_days'] = array_values(array_map(fn($x) => strtoupper((string)$x), $arr));
        }
      } catch (Throwable $e) {}
    }
    if (isset($map['weekend_rate_multiplier']) && is_numeric($map['weekend_rate_multiplier'])) {
      $out['weekend_rate_multiplier'] = (float)$map['weekend_rate_multiplier'];
    }
    $out['bank_holiday_enabled'] = isset($map['bank_holiday_enabled']) ? ((int)$map['bank_holiday_enabled']===1) : $defaults['bank_holiday_enabled'];
    $out['bank_holiday_paid'] = isset($map['bank_holiday_paid']) ? ((int)$map['bank_holiday_paid']===1) : $defaults['bank_holiday_paid'];
    if (isset($map['bank_holiday_rate_multiplier']) && is_numeric($map['bank_holiday_rate_multiplier'])) {
      $out['bank_holiday_rate_multiplier'] = (float)$map['bank_holiday_rate_multiplier'];
    }
    if (isset($map['payroll_overtime_priority']) && $map['payroll_overtime_priority'] !== '') {
      $out['payroll_overtime_priority'] = strtoupper(trim($map['payroll_overtime_priority']));
    }

    // Validate week start
    $allowed = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
    if (!in_array($out['payroll_week_starts_on'], $allowed, true)) {
      $out['payroll_week_starts_on'] = $defaults['payroll_week_starts_on'];
    }

    return $out;
  }

  /** @return array<string,string> date => name */
  private function loadBankHolidayIndex(string $startDate, string $endDate): array {
    $stmt = $this->pdo->prepare("SELECT holiday_date, name FROM payroll_bank_holidays WHERE holiday_date >= :s AND holiday_date <= :e");
    try {
      $stmt->execute([':s'=>$startDate, ':e'=>$endDate]);
    } catch (Throwable $e) {
      return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $idx = [];
    foreach ($rows as $r) {
      $idx[(string)$r['holiday_date']] = (string)($r['name'] ?? '');
    }
    return $idx;
  }

  /**
   * Fetch approved shifts overlapping [from,to) by clock_in_at.
   * Milestone 1 uses the shift start time filtering as existing payroll views do.
   */
  private function fetchApprovedShifts(string $fromDt, string $toDt, bool $includeLocked): array {
    $sql = "
      SELECT
        s.*,
        e.employee_code, e.first_name, e.last_name, e.nickname, e.is_agency, e.agency_label,
        p.break_minutes_default, p.break_is_paid, p.min_hours_for_break, p.contract_hours_per_week,
        p.day_rate,
        p.rules_json,
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
        AND s.approved_at IS NOT NULL
    ";
    if (!$includeLocked) {
      $sql .= " AND s.payroll_locked_at IS NULL";
    }
    $sql .= " ORDER BY s.employee_id ASC, s.clock_in_at ASC";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([':from_dt'=>$fromDt, ':to_dt'=>$toDt]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  /** @return array{employee_id:int,employee_code:string,name:string,hourly_rate:float,contract_hours_per_week:float,overtime_multiplier:float} */
  private function employeeMetaFromRow(array $r, array $settings): array {
    $empId = (int)$r['employee_id'];
    $nick = trim((string)($r['nickname'] ?? ''));
    $firstLast = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
    $name = ($nick !== '') ? $nick : $firstLast;
    if ((int)($r['is_agency'] ?? 0) === 1) {
      $name = trim((string)($r['agency_label'] ?? 'Agency'));
    }

    $contract = (float)($r['contract_hours_per_week'] ?? 0);

    // Base hourly rate: use day_rate for now.
    $rate = (float)($r['day_rate'] ?? 0);

    $overtimeMult = (float)$settings['overtime_default_multiplier'];
    // Allow override from rules_json if present
    $rulesJson = (string)($r['rules_json'] ?? '');
    if ($rulesJson !== '') {
      $rules = json_decode($rulesJson, true);
      if (is_array($rules)) {
        if (isset($rules['overtime_rate_multiplier']) && is_numeric($rules['overtime_rate_multiplier'])) {
          $overtimeMult = (float)$rules['overtime_rate_multiplier'];
        } elseif (isset($rules['overtime_multiplier']) && is_numeric($rules['overtime_multiplier'])) {
          $overtimeMult = (float)$rules['overtime_multiplier'];
        }
      }
    }

    return [
      'employee_id' => $empId,
      'employee_code' => (string)($r['employee_code'] ?? ''),
      'name' => $name,
      'hourly_rate' => $rate,
      'contract_hours_per_week' => $contract,
      'overtime_multiplier' => max(1.0, $overtimeMult),
    ];
  }

  /** @return array{clock_in_at:string,clock_out_at:string,break_minutes:?int} */
  private function effectiveShiftTimes(array $shiftRow): array {
    $eff = [
      'clock_in_at' => (string)($shiftRow['clock_in_at'] ?? ''),
      'clock_out_at' => (string)($shiftRow['clock_out_at'] ?? ''),
      'break_minutes' => null,
    ];
    $json = (string)($shiftRow['latest_edit_json'] ?? '');
    if ($json !== '') {
      $data = json_decode($json, true);
      if (is_array($data)) {
        if (!empty($data['clock_in_at'])) $eff['clock_in_at'] = (string)$data['clock_in_at'];
        if (array_key_exists('clock_out_at', $data)) {
          $co = $data['clock_out_at'];
          $eff['clock_out_at'] = ($co === null || $co === '') ? '' : (string)$co;
        }
        if (array_key_exists('break_minutes', $data) && $data['break_minutes'] !== null && $data['break_minutes'] !== '') {
          $eff['break_minutes'] = (int)$data['break_minutes'];
        }
      }
    }
    return $eff;
  }

  private function minutesBetween(string $a, string $b): int {
    try {
      $da = new DateTimeImmutable($a, new DateTimeZone('UTC'));
      $db = new DateTimeImmutable($b, new DateTimeZone('UTC'));
      $diff = $db->getTimestamp() - $da->getTimestamp();
      return (int)floor($diff/60);
    } catch (Throwable $e) {
      return 0;
    }
  }

  /** @return array{0:string,1:string} */
  private function applyRounding(string $in, string $out, array $settings): array {
    if (!(bool)$settings['rounding_enabled']) return [$in, $out];
    $inc = max(1, (int)$settings['round_increment_minutes']);
    $grace = max(0, (int)$settings['round_grace_minutes']);
    return [
      $this->roundDatetime($in, $inc, $grace),
      $this->roundDatetime($out, $inc, $grace),
    ];
  }

  private function roundDatetime(string $dt, int $incMinutes, int $graceMinutes): string {
    try {
      $d = new DateTimeImmutable($dt, new DateTimeZone('UTC'));
      $h = (int)$d->format('H');
      $m = (int)$d->format('i');
      $total = $h * 60 + $m;

      $inc = max(1, $incMinutes);
      $grace = max(0, $graceMinutes);

      $floor = intdiv($total, $inc) * $inc;
      $ceil  = (($total % $inc) === 0) ? $total : ($floor + $inc);

      $dFloor = abs($total - $floor);
      $dCeil  = abs($ceil - $total);

      $candidate = null;
      if ($dFloor <= $grace && $dFloor <= $dCeil) {
        $candidate = $floor;
      } elseif ($dCeil <= $grace) {
        $candidate = $ceil;
      }

      if ($candidate === null) {
        return $d->format('Y-m-d H:i:s');
      }

      $newH = intdiv($candidate, 60) % 24;
      $newM = $candidate % 60;
      return $d->setTime($newH, $newM, 0)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
      return $dt;
    }
  }

  /**
   * Split an interval into segments by day (midnight), clamped to [periodStart, periodEnd).
   * Returns list of ['date'=>'Y-m-d', 'minutes'=>int].
   */
  private function splitByDay(string $start, string $end, string $periodStart, string $periodEnd): array {
    $out = [];
    try {
      $s = new DateTimeImmutable($start, new DateTimeZone('UTC'));
      $e = new DateTimeImmutable($end, new DateTimeZone('UTC'));
      $ps = new DateTimeImmutable($periodStart, new DateTimeZone('UTC'));
      $pe = new DateTimeImmutable($periodEnd, new DateTimeZone('UTC'));

      if ($e <= $ps || $s >= $pe) return [];
      if ($s < $ps) $s = $ps;
      if ($e > $pe) $e = $pe;

      $cur = $s;
      while ($cur < $e) {
        $nextMidnight = $cur->setTime(0,0,0)->modify('+1 day');
        $segEnd = $e < $nextMidnight ? $e : $nextMidnight;
        $mins = (int)floor(($segEnd->getTimestamp() - $cur->getTimestamp())/60);
        if ($mins > 0) {
          $out[] = ['date' => $cur->format('Y-m-d'), 'minutes' => $mins];
        }
        $cur = $segEnd;
      }
    } catch (Throwable $e) {
      return [];
    }
    return $out;
  }

  private function isWeekendDate(string $dateYmd, array $settings): bool {
    try {
      $d = new DateTimeImmutable($dateYmd.' 00:00:00', new DateTimeZone('UTC'));
      $dow = strtoupper($d->format('D')); // MON, TUE, ...
      $weekend = $settings['weekend_days'] ?? ['SAT','SUN'];
      if (!is_array($weekend)) $weekend = ['SAT','SUN'];
      return in_array($dow, $weekend, true);
    } catch (Throwable $e) {
      return false;
    }
  }

  private function premiumExtraForSegment(string $dateYmd, int $paidMinutes, array $meta, array $settings, array $bankHolidayIndex): float {
    if ($paidMinutes <= 0) return 0.0;
    $rate = (float)$meta['hourly_rate'];
    if ($rate <= 0) return 0.0;

    $extra = 0.0;

    // Bank holiday
    $isBH = isset($bankHolidayIndex[$dateYmd]);
    if ($isBH && (bool)$settings['bank_holiday_enabled'] && (bool)$settings['bank_holiday_paid']) {
      $mult = (float)$settings['bank_holiday_rate_multiplier'];
      // allow per-employee override if profile has bank_holiday_multiplier
      // (meta doesn't carry it; we keep it global for milestone 1)
      if ($mult > 1.0) {
        $extra += ($paidMinutes/60.0) * $rate * ($mult - 1.0);
      }
    }

    // Weekend premium
    if ((bool)$settings['weekend_premium_enabled'] && $this->isWeekendDate($dateYmd, $settings)) {
      $mult = (float)$settings['weekend_rate_multiplier'];
      if ($mult > 1.0) {
        $extra += ($paidMinutes/60.0) * $rate * ($mult - 1.0);
      }
    }

    return $extra;
  }

  private function weekStartDate(string $dateYmd, string $weekStartsOn): string {
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
    $d = new DateTimeImmutable($dateYmd.' 00:00:00', new DateTimeZone('UTC'));
    $iso = (int)$d->format('N');
    $delta = ($iso - $target) % 7;
    if ($delta < 0) $delta += 7;
    $ws = $d->modify('-'.$delta.' days');
    return $ws->format('Y-m-d');
  }

  private function weekKeyForDate(string $dateYmd, string $weekStartsOn): string {
    $ws = $this->weekStartDate($dateYmd, $weekStartsOn);
    return $ws; // weekKey as week start date
  }
}
