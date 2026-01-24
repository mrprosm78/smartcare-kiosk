<?php
declare(strict_types=1);

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

    // load shifts (closed + approved) for the month (by clock_in)
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
    $shifts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare(
      "INSERT INTO payroll_shift_snapshots
        (payroll_batch_id, shift_id, employee_id,
         worked_minutes, break_minutes, paid_minutes,
         normal_minutes, weekend_minutes, bank_holiday_minutes, overtime_minutes,
         applied_rule, rounding_minutes, rounded_paid_minutes)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    foreach ($shifts as $s) {
      $sid = (int)$s['id'];
      $eid = (int)$s['employee_id'];

      $inUtc  = new DateTimeImmutable((string)$s['clock_in_at'],  new DateTimeZone('UTC'));
      $outUtc = new DateTimeImmutable((string)$s['clock_out_at'], new DateTimeZone('UTC'));

      $worked = (int)($s['duration_minutes'] ?? 0);
      if ($worked <= 0) {
        $worked = (int)round(($outUtc->getTimestamp() - $inUtc->getTimestamp()) / 60);
      }
      if ($worked < 0) $worked = 0;

      $break = ($s['break_minutes'] !== null) ? (int)$s['break_minutes'] : $pickBreak($worked);
      if ($break < 0) $break = 0;
      if ($break > $worked) $break = $worked;

      $breakPaid = ((int)($s['break_is_paid'] ?? 0) === 1);
      $paid = ($s['paid_minutes'] !== null)
        ? (int)$s['paid_minutes']
        : ($breakPaid ? $worked : max(0, $worked - $break));

      // bucket paid minutes by local date (BH/weekend/no stacking yet)
      $normal = 0; $weekend = 0; $bhMin = 0;

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

        if (isset($bh[$dateYmd])) $bhMin += $alloc;
        elseif ($dow >= 6) $weekend += $alloc;
        else $normal += $alloc;

        $paidRemaining -= $alloc;
        $cursorUtc = $nextBoundaryUtc;
      }

      // drift fix
      $sum = $normal + $weekend + $bhMin;
      if ($sum !== $paid) $normal += ($paid - $sum);

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
      ]);
    }

    $pdo->commit();
    return $batchId;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
