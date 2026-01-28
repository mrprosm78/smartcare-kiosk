<?php
declare(strict_types=1);

// Small datetime helpers (avoid relying on any global helpers)
if (!function_exists('max_dt')) {
  function max_dt(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable {
    return ($a->getTimestamp() >= $b->getTimestamp()) ? $a : $b;
  }
}
if (!function_exists('min_dt')) {
  function min_dt(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable {
    return ($a->getTimestamp() <= $b->getTimestamp()) ? $a : $b;
  }
}

/**
 * Run monthly payroll and write shift snapshots.
 * Returns the new payroll batch id.
 */
function payroll_run_month(PDO $pdo, array $user, string $ym, string $reason = ''): int {
  if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    throw new InvalidArgumentException('Invalid month format (YYYY-MM).');
  }

  $tzName = (string)setting($pdo, 'payroll_timezone', 'Europe/London');
  if (trim($tzName) === '') $tzName = 'Europe/London';
  $tz = new DateTimeZone($tzName);

  $periodStartLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ym . '-01 00:00:00', $tz);
  if (!$periodStartLocal) throw new RuntimeException('Failed to parse month start.');
  $periodEndLocal = $periodStartLocal->modify('+1 month');

  $periodStartUtc = $periodStartLocal->setTimezone(new DateTimeZone('UTC'));
  $periodEndUtc   = $periodEndLocal->setTimezone(new DateTimeZone('UTC'));

  // Month boundary mode:
  // - midnight: split shifts at local midnight and assign each minute to the month it was worked
  // - end_of_shift: assign whole shift to the month of its start date (advanced)
  // Default to end_of_shift so a shift is assigned to the month it STARTED.
  $monthBoundaryMode = strtolower(trim((string)setting($pdo, 'payroll_month_boundary_mode', 'end_of_shift')));
  if (!in_array($monthBoundaryMode, ['midnight','end_of_shift'], true)) $monthBoundaryMode = 'end_of_shift';

  // bank holiday set (Y-m-d)
  $bh = [];
  try {
    $st = $pdo->query("SELECT holiday_date FROM payroll_bank_holidays");
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
      $bh[(string)$r['holiday_date']] = true;
    }
  } catch (Throwable $e) {}

  // break tiers (min_worked_minutes -> break_minutes)
  $tiers = [];
  try {
    $st = $pdo->query("SELECT min_worked_minutes, break_minutes
                       FROM kiosk_break_tiers
                       WHERE is_enabled=1
                       ORDER BY min_worked_minutes ASC");
    $tiers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}

  $pickBreak = function(int $worked) use ($tiers): int {
    $best = 0;
    foreach ($tiers as $t) {
      $min = (int)$t['min_worked_minutes'];
      if ($worked >= $min) $best = (int)$t['break_minutes'];
      else break;
    }
    return max(0, $best);
  };

  // Payroll week start day (Mon=1..Sun=7)
  $ws = strtoupper(trim((string)setting($pdo, 'payroll_week_starts_on', 'MONDAY')));
  $dowMap = [
    'MONDAY'    => 1,
    'TUESDAY'   => 2,
    'WEDNESDAY' => 3,
    'THURSDAY'  => 4,
    'FRIDAY'    => 5,
    'SATURDAY'  => 6,
    'SUNDAY'    => 7,
  ];
  $weekStartDow = $dowMap[$ws] ?? 1;

  $weekStartLocalFor = function(DateTimeImmutable $dtLocal) use ($weekStartDow): DateTimeImmutable {
    $dayLocal = $dtLocal->setTime(0,0,0);
    $todayDow = (int)$dayLocal->format('N');
    $diff = $todayDow - $weekStartDow;
    if ($diff < 0) $diff += 7;
    return $dayLocal->modify("-{$diff} days");
  };

  // Compute paid minutes (after breaks) for an employee within a UTC window.
  // Used for weekly overtime totals (includes minutes from outside the payroll month when a week spans months).
  $computePaidForWindow = function(int $employeeId, DateTimeImmutable $winStartUtc, DateTimeImmutable $winEndUtc) use ($pdo, $pickBreak): int {
    $st = $pdo->prepare(
      "SELECT s.id, s.clock_in_at, s.clock_out_at, s.duration_minutes, s.break_minutes, p.break_is_paid
       FROM kiosk_shifts s
       LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = s.employee_id
       WHERE s.employee_id = ?
         AND s.clock_in_at < ?
         AND s.clock_out_at > ?
         AND s.clock_out_at IS NOT NULL
         AND s.approved_at IS NOT NULL"
    );
    $st->execute([
      $employeeId,
      $winEndUtc->format('Y-m-d H:i:s'),
      $winStartUtc->format('Y-m-d H:i:s'),
    ]);

    $sumPaid = 0;
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $s) {
      $shiftInUtc  = new DateTimeImmutable((string)$s['clock_in_at'],  new DateTimeZone('UTC'));
      $shiftOutUtc = new DateTimeImmutable((string)$s['clock_out_at'], new DateTimeZone('UTC'));
      $inUtc = max_dt($shiftInUtc, $winStartUtc);
      $outUtc = min_dt($shiftOutUtc, $winEndUtc);
      if ($outUtc <= $inUtc) continue;

      $workedTotal = (int)($s['duration_minutes'] ?? 0);
      if ($workedTotal <= 0) {
        $workedTotal = (int)round(($shiftOutUtc->getTimestamp() - $shiftInUtc->getTimestamp()) / 60);
      }
      if ($workedTotal < 0) $workedTotal = 0;

      $worked = (int)round(($outUtc->getTimestamp() - $inUtc->getTimestamp()) / 60);
      if ($worked < 0) $worked = 0;

      $breakTotal = ($s['break_minutes'] !== null) ? (int)$s['break_minutes'] : $pickBreak($workedTotal);
      if ($breakTotal < 0) $breakTotal = 0;
      if ($breakTotal > $workedTotal) $breakTotal = $workedTotal;

      $break = $breakTotal;
      if ($workedTotal > 0 && $worked !== $workedTotal) {
        $break = (int)floor(($worked * $breakTotal) / $workedTotal);
      }
      if ($break < 0) $break = 0;
      if ($break > $worked) $break = $worked;

      $breakPaid = ((int)($s['break_is_paid'] ?? 0) === 1);
      $paid = ($breakPaid ? $worked : max(0, $worked - $break));
      $sumPaid += $paid;
    }

    return $sumPaid;
  };

  $pdo->beginTransaction();
  try {
    // create batch
    $st = $pdo->prepare("INSERT INTO payroll_batches (period_start, period_end, run_by, status, notes)
                         VALUES (?,?,?,?,?)");
    $st->execute([
      $periodStartLocal->format('Y-m-d'),
      $periodEndLocal->format('Y-m-d'),
      isset($user['id']) ? (int)$user['id'] : null,
      'FINAL',
      $reason !== '' ? $reason : null,
    ]);
    $batchId = (int)$pdo->lastInsertId();

    // Load shifts (closed + approved) for the period.
    // - midnight mode: include shifts that overlap the calendar month
    // - end_of_shift mode: include shifts that start within the calendar month
    if ($monthBoundaryMode === 'end_of_shift') {
      $q = $pdo->prepare(
        "SELECT s.id, s.employee_id, s.clock_in_at, s.clock_out_at,
                s.duration_minutes, s.break_minutes, s.paid_minutes,
                p.break_is_paid
         FROM kiosk_shifts s
         LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = s.employee_id
         WHERE s.clock_in_at >= :start_utc
           AND s.clock_in_at <  :end_utc
           AND s.clock_out_at IS NOT NULL
           AND s.approved_at IS NOT NULL
         ORDER BY s.employee_id ASC, s.clock_in_at ASC"
      );
      $q->execute([
        ':start_utc' => $periodStartUtc->format('Y-m-d H:i:s'),
        ':end_utc'   => $periodEndUtc->format('Y-m-d H:i:s'),
      ]);
    } else {
      // overlap query: start before period end AND end after period start
      $q = $pdo->prepare(
        "SELECT s.id, s.employee_id, s.clock_in_at, s.clock_out_at,
                s.duration_minutes, s.break_minutes, s.paid_minutes,
                p.break_is_paid
         FROM kiosk_shifts s
         LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = s.employee_id
         WHERE s.clock_in_at <  :end_utc
           AND s.clock_out_at >  :start_utc
           AND s.clock_out_at IS NOT NULL
           AND s.approved_at IS NOT NULL
         ORDER BY s.employee_id ASC, s.clock_in_at ASC"
      );
      $q->execute([
        ':start_utc' => $periodStartUtc->format('Y-m-d H:i:s'),
        ':end_utc'   => $periodEndUtc->format('Y-m-d H:i:s'),
      ]);
    }
    $shifts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare(
      "INSERT INTO payroll_shift_snapshots
        (payroll_batch_id, shift_id, employee_id,
         worked_minutes, break_minutes, paid_minutes,
         normal_minutes, weekend_minutes, bank_holiday_minutes, overtime_minutes,
         applied_rule, rounding_minutes, rounded_paid_minutes,
         day_breakdown_json)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    // Collect per-shift segment metadata (for weekly overtime allocation).
    // Stored as paid-minute segments split by local midnight in payroll timezone.
    $shiftMeta = []; // shift_id => meta

    foreach ($shifts as $s) {
      $sid = (int)$s['id'];
      $eid = (int)$s['employee_id'];

      $shiftInUtc  = new DateTimeImmutable((string)$s['clock_in_at'],  new DateTimeZone('UTC'));
      $shiftOutUtc = new DateTimeImmutable((string)$s['clock_out_at'], new DateTimeZone('UTC'));

      // Apply month boundary allocation.
      // - midnight: clip to the month window
      // - end_of_shift: include full shift window
      $inUtc = ($monthBoundaryMode === 'midnight') ? max_dt($shiftInUtc, $periodStartUtc) : $shiftInUtc;
      $outUtc = ($monthBoundaryMode === 'midnight') ? min_dt($shiftOutUtc, $periodEndUtc) : $shiftOutUtc;

      if ($outUtc <= $inUtc) {
        // Shift does not contribute to this month (possible when clipped).
        continue;
      }

      // Total shift minutes (for break tier selection)
      $workedTotal = (int)($s['duration_minutes'] ?? 0);
      if ($workedTotal <= 0) {
        $workedTotal = (int)round(($shiftOutUtc->getTimestamp() - $shiftInUtc->getTimestamp()) / 60);
      }
      if ($workedTotal < 0) $workedTotal = 0;

      // Minutes that fall into this payroll month (after clipping, if applicable)
      $worked = (int)round(($outUtc->getTimestamp() - $inUtc->getTimestamp()) / 60);
      if ($worked < 0) $worked = 0;

      $breakTotal = ($s['break_minutes'] !== null) ? (int)$s['break_minutes'] : $pickBreak($workedTotal);
      if ($breakTotal < 0) $breakTotal = 0;
      if ($breakTotal > $workedTotal) $breakTotal = $workedTotal;

      // Allocate break minutes into this month's portion (so totals reconcile across months)
      $break = $breakTotal;
      if ($monthBoundaryMode === 'midnight' && $workedTotal > 0 && $worked !== $workedTotal) {
        // Proportional allocation (integer minutes)
        $break = (int)floor(($worked * $breakTotal) / $workedTotal);
      }
      if ($break < 0) $break = 0;
      if ($break > $worked) $break = $worked;

      $breakPaid = ((int)($s['break_is_paid'] ?? 0) === 1);
      // Paid minutes for this portion.
      // IMPORTANT: paid breaks are shown as deducted then added back (net effect: paid == worked).
      $paid = ($breakPaid ? $worked : max(0, $worked - $break));

      // bucket paid minutes by local date (BH/weekend/no stacking yet)
      $normal = 0; $weekend = 0; $bhMin = 0;

      $segments = []; // chronological segments for this shift portion

      $cursorUtc = $inUtc;
      $paidRemaining = $paid;

      while ($cursorUtc < $outUtc && $paidRemaining > 0) {
        $cursorLocal = $cursorUtc->setTimezone($tz);
        $nextMidnightLocal = $cursorLocal->setTime(0,0,0)->modify('+1 day');
        $nextBoundaryUtc = $nextMidnightLocal->setTimezone(new DateTimeZone('UTC'));
        if ($nextBoundaryUtc > $outUtc) $nextBoundaryUtc = $outUtc;

        $segMin = (int)floor(($nextBoundaryUtc->getTimestamp() - $cursorUtc->getTimestamp()) / 60);
        if ($segMin <= 0) break;

        $alloc = min($segMin, $paidRemaining);

        $dateYmd = $cursorLocal->format('Y-m-d');
        $dow = (int)$cursorLocal->format('N'); // 6=Sat,7=Sun

        if (isset($bh[$dateYmd])) {
          $bhMin += $alloc;
          $segments[] = ['end_utc_ts' => $nextBoundaryUtc->getTimestamp(), 'bucket' => 'bh', 'minutes' => $alloc];
        } elseif ($dow >= 6) {
          $weekend += $alloc;
          $segments[] = ['end_utc_ts' => $nextBoundaryUtc->getTimestamp(), 'bucket' => 'weekend', 'minutes' => $alloc];
        } else {
          $normal += $alloc;
          $segments[] = ['end_utc_ts' => $nextBoundaryUtc->getTimestamp(), 'bucket' => 'normal', 'minutes' => $alloc];
        }

        $paidRemaining -= $alloc;
        $cursorUtc = $nextBoundaryUtc;
      }

      // drift fix
      $sum = $normal + $weekend + $bhMin;
      if ($sum !== $paid) {
        $delta = ($paid - $sum);
        $normal += $delta;
        // Apply delta to last normal segment if possible; otherwise append.
        for ($i = count($segments) - 1; $i >= 0; $i--) {
          if ($segments[$i]['bucket'] === 'normal') {
            $segments[$i]['minutes'] += $delta;
            break;
          }
        }
        if ($delta !== 0 && (!$segments || $segments[count($segments)-1]['bucket'] !== 'normal')) {
          $segments[] = ['end_utc_ts' => $outUtc->getTimestamp(), 'bucket' => 'normal', 'minutes' => $delta];
        }
      }

      // Store meta for overtime allocation.
      $shiftMeta[$sid] = [
        'shift_id' => $sid,
        'employee_id' => $eid,
        'portion_in_utc_ts' => $inUtc->getTimestamp(),
        'portion_out_utc_ts' => $outUtc->getTimestamp(),
        'worked' => $worked,
        'break' => $break,
        'break_paid' => $breakPaid,
        'paid' => $paid,
        'normal' => $normal,
        'weekend' => $weekend,
        'bh' => $bhMin,
        'segments' => $segments,
        'ot_segments' => [],
      ];

      $ins->execute([
        $batchId,
        $sid,
        $eid,
        $worked,
        $break,
        $paid,
        $normal,
        $weekend,
        $bhMin,
        0,          // overtime_minutes (next step)
        null,       // applied_rule
        null,       // rounding_minutes
        null,       // rounded_paid_minutes
        null,       // day_breakdown_json (filled after overtime allocation)
      ]);
    }

    // ===== Step A: Weekly overtime allocation (no stacking, OT overrides other buckets) =====

    // Load employee weekly contract hours for employees present in this batch.
    $employeeIds = array_values(array_unique(array_map(fn($m) => (int)$m['employee_id'], $shiftMeta)));
    $thresholdMin = []; // employee_id => threshold minutes
    if ($employeeIds) {
      $in = implode(',', array_fill(0, count($employeeIds), '?'));
      $st = $pdo->prepare("SELECT employee_id, contract_hours_per_week FROM kiosk_employee_pay_profiles WHERE employee_id IN ($in)");
      $st->execute($employeeIds);
      $rowsP = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rowsP as $r) {
        $eid = (int)$r['employee_id'];
        $hrs = (float)($r['contract_hours_per_week'] ?? 0);
        $thresholdMin[$eid] = (int)round(max(0.0, $hrs) * 60);
      }
      foreach ($employeeIds as $eid) {
        if (!isset($thresholdMin[$eid])) $thresholdMin[$eid] = 0;
      }
    }

    // Iterate payroll weeks overlapping this month window (in payroll timezone).
    $weekStartLocal = $weekStartLocalFor($periodStartLocal);
    $updateSnap = $pdo->prepare(
      "UPDATE payroll_shift_snapshots
          SET normal_minutes=?, weekend_minutes=?, bank_holiday_minutes=?, overtime_minutes=?, applied_rule=?
        WHERE payroll_batch_id=? AND shift_id=? LIMIT 1"
    );

    while ($weekStartLocal < $periodEndLocal) {
      $weekEndLocalEx = $weekStartLocal->modify('+7 days');
      $isCompleteWithinMonth = ($weekEndLocalEx <= $periodEndLocal);

      // Deferral rule: if month ends mid-week, do NOT finalize OT for that incomplete week in this batch.
      if (!$isCompleteWithinMonth) {
        break;
      }

      $weekStartUtc = $weekStartLocal->setTimezone(new DateTimeZone('UTC'));
      $weekEndUtcEx = $weekEndLocalEx->setTimezone(new DateTimeZone('UTC'));
      $wsTs = $weekStartUtc->getTimestamp();
      $weTs = $weekEndUtcEx->getTimestamp();

      // For each employee, compute overtime minutes for this week.
      foreach ($employeeIds as $eid) {
        $thr = (int)($thresholdMin[$eid] ?? 0);
        if ($thr <= 0) continue; // overtime disabled

        $totalPaidWeek = $computePaidForWindow((int)$eid, $weekStartUtc, $weekEndUtcEx);
        $otRemain = max(0, $totalPaidWeek - $thr);
        if ($otRemain <= 0) continue;

        // Build a flat list of segments (within this week) for this employee in THIS batch.
        $flat = []; // each: [shift_id, idx, end_utc_ts, bucket, minutes]
        foreach ($shiftMeta as $sid => $m) {
          if ((int)$m['employee_id'] !== (int)$eid) continue;
          foreach (($m['segments'] ?? []) as $idx => $seg) {
            $endTs = (int)$seg['end_utc_ts'];
            if ($endTs <= $wsTs || $endTs > $weTs) continue;
            $mins = (int)$seg['minutes'];
            if ($mins <= 0) continue;
            $flat[] = [(int)$sid, (int)$idx, $endTs, (string)$seg['bucket'], $mins];
          }
        }
        if (!$flat) continue;

        // Allocate OT from the END of the week backwards (chronological fairness).
        usort($flat, fn($a, $b) => $b[2] <=> $a[2]);

        $adj = []; // shift_id => ['normal'=>sub,'weekend'=>sub,'bh'=>sub,'ot'=>add]
        foreach ($flat as $item) {
          if ($otRemain <= 0) break;
          [$sid, $idx, $endTs, $bucket, $mins] = $item;
          $alloc = min($mins, $otRemain);
          if ($alloc <= 0) continue;

          if (!isset($adj[$sid])) {
            $adj[$sid] = ['normal' => 0, 'weekend' => 0, 'bh' => 0, 'ot' => 0];
          }
          $adj[$sid]['ot'] += $alloc;
          if ($bucket === 'bh') $adj[$sid]['bh'] += $alloc;
          elseif ($bucket === 'weekend') $adj[$sid]['weekend'] += $alloc;
          else $adj[$sid]['normal'] += $alloc;

          // Reduce the segment so we don't allocate it twice in a rare duplicate pass.
          $shiftMeta[$sid]['segments'][$idx]['minutes'] -= $alloc;
          // Track overtime minutes by the same local-day segment (for audit / day breakdown output).
          $shiftMeta[$sid]['ot_segments'][] = ['end_utc_ts' => $endTs, 'minutes' => $alloc];
          $otRemain -= $alloc;
        }

        // Apply adjustments to snapshots and meta.
        foreach ($adj as $sid => $a) {
          $m = $shiftMeta[$sid] ?? null;
          if (!$m) continue;

          $newNormal = max(0, (int)$m['normal'] - (int)$a['normal']);
          $newWeekend = max(0, (int)$m['weekend'] - (int)$a['weekend']);
          $newBh = max(0, (int)$m['bh'] - (int)$a['bh']);
          $newOt = max(0, (int)($m['ot'] ?? 0) + (int)$a['ot']);

          // Reconcile: ensure buckets sum to paid.
          $sumBuckets = $newNormal + $newWeekend + $newBh + $newOt;
          $paidM = (int)$m['paid'];
          if ($sumBuckets !== $paidM) {
            $newNormal += ($paidM - $sumBuckets);
            if ($newNormal < 0) $newNormal = 0;
          }

          $shiftMeta[$sid]['normal'] = $newNormal;
          $shiftMeta[$sid]['weekend'] = $newWeekend;
          $shiftMeta[$sid]['bh'] = $newBh;
          $shiftMeta[$sid]['ot'] = $newOt;

          $updateSnap->execute([
            $newNormal,
            $newWeekend,
            $newBh,
            $newOt,
            'overtime',
            $batchId,
            (int)$sid,
          ]);
        }
      }

      $weekStartLocal = $weekEndLocalEx;
    }

    // ===== Step B: Persist per-day breakdown JSON (employee audit / Option-C view) =====
    $updateDay = $pdo->prepare(
      "UPDATE payroll_shift_snapshots SET day_breakdown_json=? WHERE payroll_batch_id=? AND shift_id=? LIMIT 1"
    );

    $utcTz = new DateTimeZone('UTC');

    foreach ($shiftMeta as $sid => $m) {
      $inTs = (int)$m['portion_in_utc_ts'];
      $outTs = (int)$m['portion_out_utc_ts'];
      if ($outTs <= $inTs) continue;

      $inUtc = (new DateTimeImmutable('@'.$inTs))->setTimezone($utcTz);
      $outUtc = (new DateTimeImmutable('@'.$outTs))->setTimezone($utcTz);

      // Worked minutes split by local day (no break considered).
      $workedByDay = []; // Y-m-d => minutes
      $cursorUtc = $inUtc;
      while ($cursorUtc < $outUtc) {
        $cursorLocal = $cursorUtc->setTimezone($tz);
        $nextMidnightLocal = $cursorLocal->setTime(0,0,0)->modify('+1 day');
        $nextBoundaryUtc = $nextMidnightLocal->setTimezone($utcTz);
        if ($nextBoundaryUtc > $outUtc) $nextBoundaryUtc = $outUtc;

        $segMin = (int)floor(($nextBoundaryUtc->getTimestamp() - $cursorUtc->getTimestamp()) / 60);
        if ($segMin <= 0) break;

        $dateYmd = $cursorLocal->format('Y-m-d');
        $workedByDay[$dateYmd] = ($workedByDay[$dateYmd] ?? 0) + $segMin;

        $cursorUtc = $nextBoundaryUtc;
      }

      // Allocate break minutes across days proportionally to worked minutes.
      $breakTotal = (int)($m['break'] ?? 0);
      if ($breakTotal < 0) $breakTotal = 0;
      $workedTotal = array_sum($workedByDay);
      $breakMinusByDay = []; // Y-m-d => minutes
      if ($breakTotal > 0 && $workedTotal > 0 && $workedByDay) {
        $days = array_keys($workedByDay);
        sort($days);
        $acc = 0;
        foreach ($days as $i => $d) {
          if ($i === count($days) - 1) {
            $alloc = max(0, $breakTotal - $acc);
          } else {
            $alloc = (int)floor(($breakTotal * (int)$workedByDay[$d]) / $workedTotal);
            $acc += $alloc;
          }
          if ($alloc > (int)$workedByDay[$d]) $alloc = (int)$workedByDay[$d];
          $breakMinusByDay[$d] = $alloc;
        }
      } else {
        foreach ($workedByDay as $d => $_) $breakMinusByDay[$d] = 0;
      }

      $breakPaid = (bool)($m['break_paid'] ?? false);
      $breakPlusByDay = [];
      foreach ($breakMinusByDay as $d => $bm) {
        $breakPlusByDay[$d] = $breakPaid ? (int)$bm : 0;
      }

      // Final bucket minutes by local day.
      $buckets = []; // Y-m-d => ['normal'=>..,'weekend'=>..,'bh'=>..,'ot'=>..]
      $ensure = function(string $d) use (&$buckets): void {
        if (!isset($buckets[$d])) {
          $buckets[$d] = ['normal'=>0,'weekend'=>0,'bh'=>0,'ot'=>0];
        }
      };

      $dateFromEndTs = function(int $endTs) use ($tz, $utcTz): string {
        // endTs marks end of a local-day segment; use endTs-1 to get the segment's local date.
        $ts = max(0, $endTs - 1);
        return (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m-d');
      };

      foreach (($m['segments'] ?? []) as $seg) {
        $mins = (int)($seg['minutes'] ?? 0);
        if ($mins <= 0) continue;
        $d = $dateFromEndTs((int)$seg['end_utc_ts']);
        $ensure($d);
        $bucket = (string)($seg['bucket'] ?? 'normal');
        if ($bucket === 'bh') $buckets[$d]['bh'] += $mins;
        elseif ($bucket === 'weekend') $buckets[$d]['weekend'] += $mins;
        else $buckets[$d]['normal'] += $mins;
      }

      foreach (($m['ot_segments'] ?? []) as $seg) {
        $mins = (int)($seg['minutes'] ?? 0);
        if ($mins <= 0) continue;
        $d = $dateFromEndTs((int)$seg['end_utc_ts']);
        $ensure($d);
        $buckets[$d]['ot'] += $mins;
      }

      // Build JSON rows and reconcile each day so buckets sum to paid.
      $allDays = array_unique(array_merge(array_keys($workedByDay), array_keys($buckets)));
      sort($allDays);
      $rowsDay = [];
      foreach ($allDays as $d) {
        $workedD = (int)($workedByDay[$d] ?? 0);
        $bm = (int)($breakMinusByDay[$d] ?? 0);
        $bp = (int)($breakPlusByDay[$d] ?? 0);
        $paidD = max(0, $workedD - $bm + $bp);

        $ensure($d);
        $n = (int)$buckets[$d]['normal'];
        $w = (int)$buckets[$d]['weekend'];
        $bhD = (int)$buckets[$d]['bh'];
        $otD = (int)$buckets[$d]['ot'];

        $sum = $n + $w + $bhD + $otD;
        if ($sum !== $paidD) {
          $n += ($paidD - $sum);
          if ($n < 0) $n = 0;
        }

        // Skip empty days.
        if ($workedD <= 0 && $paidD <= 0 && ($n+$w+$bhD+$otD) <= 0) continue;

        $rowsDay[] = [
          'date' => $d,
          'worked_minutes' => $workedD,
          'break_deducted_minutes' => $bm,
          'break_added_minutes' => $bp,
          'paid_minutes' => $paidD,
          'normal_minutes' => $n,
          'weekend_minutes' => $w,
          'bank_holiday_minutes' => $bhD,
          'overtime_minutes' => $otD,
        ];
      }

      $json = $rowsDay ? json_encode($rowsDay, JSON_UNESCAPED_SLASHES) : null;
      $updateDay->execute([$json, $batchId, (int)$sid]);
    }

    $pdo->commit();
    return $batchId;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
