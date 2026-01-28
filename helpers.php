<?php
declare(strict_types=1);

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function now_utc(): string {
    return gmdate('Y-m-d H:i:s');
}

function valid_pin(string $pin, int $length): bool {
    return ctype_digit($pin) && strlen($pin) === $length;
}

/* ===== SETTINGS HELPERS ===== */
function setting(PDO $pdo, string $key, $default = null) {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $pdo->prepare("SELECT value FROM kiosk_settings WHERE `key`=? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();

    $cache[$key] = ($val === false) ? $default : $val;
    return $cache[$key];
}

function setting_int(PDO $pdo, string $key, int $default): int {
    return (int) setting($pdo, $key, (string)$default);
}

function setting_bool(PDO $pdo, string $key, bool $default): bool {
    return setting($pdo, $key, $default ? '1' : '0') === '1';
}


function setting_set(PDO $pdo, string $key, string $value): void {
    // Update only the value (preserve metadata columns like group/description/editable_by)
    $sql = "INSERT INTO kiosk_settings (`key`,`value`) VALUES (?,?)
            ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=UTC_TIMESTAMP()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$key, $value]);
}

/**
 * Resolve the uploads base directory to an absolute filesystem path.
 *
 * Goal: portable across duplicated installs (e.g. /yyy/) where uploads live in
 * <project_root>/uploads. Store uploads_base_path as a relative value like "uploads".
 *
 * Supports:
 *  - empty / "auto" => <project_root>/uploads
 *  - absolute paths ("/home/.../uploads")
 *  - relative paths ("uploads", "../uploads") resolved from project root
 *  - existing relative-to-CWD directories (some shared hosts) via realpath()
 */
function resolve_uploads_base_path(string $configured): string {
    // If a private uploads path constant is defined (recommended for production),
    // allow setting value 'auto' (or blank) to resolve to that private directory.
    $v = trim($configured);
 if (defined('APP_UPLOADS_PATH') && ($v === '' || strtolower($v) === 'auto')) {
    return rtrim((string) APP_UPLOADS_PATH, "/\\");
}

    $projectRoot = realpath(__DIR__) ?: __DIR__;

    if ($v === '' || strtolower($v) === 'auto') {
        return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'uploads';
    }

    // If it already resolves as a directory (relative-to-CWD on some hosts), accept it.
    $rp = @realpath($v);
    if ($rp !== false && is_dir($rp)) {
        return rtrim($rp, '/\\');
    }

    // Absolute filesystem path.
    if ($v[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $v)) {
        return rtrim($v, '/\\');
    }

    // Relative to project root.
    $candidate = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($v, '/\\');
    $candidateRp = @realpath($candidate);
    if ($candidateRp !== false && is_dir($candidateRp)) {
        return rtrim($candidateRp, '/\\');
    }
    return rtrim($candidate, '/\\');
}

function is_bcrypt(string $s): bool {
    return str_starts_with($s,'$2y$') || str_starts_with($s,'$2a$') || str_starts_with($s,'$2b$');
}

/**
 * Convert admin datetime-local input (local timezone) to UTC datetime string
 * Expected input format: YYYY-MM-DDTHH:MM
 * Returns UTC string Y-m-d H:i:s or null on failure
 */
function admin_input_to_utc(string $localInput, DateTimeZone $tz): ?string {
  $localInput = trim($localInput);
  if ($localInput === '') {
    return null;
  }

  try {
    // datetime-local comes as 2026-01-21T08:30
    $dt = DateTimeImmutable::createFromFormat(
      'Y-m-d\TH:i',
      $localInput,
      $tz
    );

    if (!$dt) {
      return null;
    }

    return $dt
      ->setTimezone(new DateTimeZone('UTC'))
      ->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}


/**
 * Week bounds in UTC using payroll_week_starts_on, but "what is this week"
 * is defined in payroll timezone (Europe/London etc.), then converted to UTC.
 */
function sc_week_bounds_utc(PDO $pdo, string $tz): array {
  $ws = strtoupper(trim((string)setting($pdo, 'payroll_week_starts_on', 'MONDAY')));

  $map = [
    'MONDAY'    => 1,
    'TUESDAY'   => 2,
    'WEDNESDAY' => 3,
    'THURSDAY'  => 4,
    'FRIDAY'    => 5,
    'SATURDAY'  => 6,
    'SUNDAY'    => 7,
  ];
  $startDow = $map[$ws] ?? 1;

  $tzObj = new DateTimeZone($tz);
  $nowLocal = new DateTimeImmutable('now', $tzObj);
  $todayLocal = $nowLocal->setTime(0, 0, 0);
  $todayDow = (int)$todayLocal->format('N'); // Mon=1..Sun=7

  $diff = $todayDow - $startDow;
  if ($diff < 0) $diff += 7;

  $weekStartLocal = $todayLocal->modify("-{$diff} days");
  $weekEndLocalEx = $weekStartLocal->modify('+7 days');

  $weekStartUtc = $weekStartLocal->setTimezone(new DateTimeZone('UTC'));
  $weekEndUtcEx = $weekEndLocalEx->setTimezone(new DateTimeZone('UTC'));

  return [
    'start_utc' => $weekStartUtc,
    'end_utc_ex' => $weekEndUtcEx,
    'start_local' => $weekStartLocal,
    'end_local_ex' => $weekEndLocalEx,
    'week_starts_on' => $ws,
  ];
}

/**
 * Given a moment in time, return the payroll-week window that contains it.
 *
 * Week boundaries are defined in the payroll timezone (setting: payroll_timezone)
 * using payroll_week_starts_on, then converted to UTC.
 *
 * The input can be in any timezone; it will be interpreted correctly.
 *
 * @return array{start_utc:DateTimeImmutable,end_utc_ex:DateTimeImmutable,start_local:DateTimeImmutable,end_local_ex:DateTimeImmutable,week_starts_on:string}
 */
function payroll_week_window(PDO $pdo, DateTimeImmutable $anyTime): array {
  $tz = payroll_timezone($pdo);
  $ws = payroll_week_starts_on($pdo);

  $map = [
    'MONDAY'    => 1,
    'TUESDAY'   => 2,
    'WEDNESDAY' => 3,
    'THURSDAY'  => 4,
    'FRIDAY'    => 5,
    'SATURDAY'  => 6,
    'SUNDAY'    => 7,
  ];
  $startDow = $map[$ws] ?? 1;

  $tzObj = new DateTimeZone($tz);
  $utcObj = new DateTimeZone('UTC');

  $local = $anyTime->setTimezone($tzObj);
  $localDay = $local->setTime(0, 0, 0);
  $dow = (int)$localDay->format('N'); // Mon=1..Sun=7

  $diff = $dow - $startDow;
  if ($diff < 0) $diff += 7;

  $weekStartLocal = $localDay->modify("-{$diff} days");
  $weekEndLocalEx = $weekStartLocal->modify('+7 days');

  return [
    'start_local' => $weekStartLocal,
    'end_local_ex' => $weekEndLocalEx,
    'start_utc' => $weekStartLocal->setTimezone($utcObj),
    'end_utc_ex' => $weekEndLocalEx->setTimezone($utcObj),
    'week_starts_on' => $ws,
  ];
}

/* ===== PAYROLL HELPERS (minutes-only internal) ===== */

function payroll_timezone(PDO $pdo): string {
  $tz = (string)setting($pdo, 'payroll_timezone', 'Europe/London');
  $tz = trim($tz);
  return $tz !== '' ? $tz : 'Europe/London';
}

function payroll_week_starts_on(PDO $pdo): string {
  return strtoupper(trim((string)setting($pdo, 'payroll_week_starts_on', 'MONDAY')));
}

/** Parse employee contract pay profile. */
function payroll_employee_profile(PDO $pdo, int $employeeId): array {
  $out = [
    'contract_hours_per_week' => 0.0,
    'break_is_paid' => false,
    'rules' => [
      'bank_holiday' => ['multiplier' => null, 'premium_per_hour' => null],
      'weekend'      => ['multiplier' => null, 'premium_per_hour' => null],
      'night'        => ['multiplier' => null, 'premium_per_hour' => null],
      'overtime'     => ['multiplier' => null, 'premium_per_hour' => null],
      'callout'      => ['multiplier' => null, 'premium_per_hour' => null],
    ],
  ];

  try {
    $st = $pdo->prepare('SELECT contract_hours_per_week, break_is_paid, rules_json FROM kiosk_employee_pay_profiles WHERE employee_id=? LIMIT 1');
    $st->execute([$employeeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $out;

    $out['contract_hours_per_week'] = (float)($row['contract_hours_per_week'] ?? 0);
    $out['break_is_paid'] = ((int)($row['break_is_paid'] ?? 0) === 1);

    $decoded = null;
    if (!empty($row['rules_json'])) {
      $decoded = json_decode((string)$row['rules_json'], true);
    }
    if (is_array($decoded)) {
      foreach (array_keys($out['rules']) as $k) {
        $mk = $k.'_multiplier';
        $pk = $k.'_premium_per_hour';
        if (array_key_exists($mk, $decoded)) {
          $v = $decoded[$mk];
          $out['rules'][$k]['multiplier'] = (is_numeric($v) ? (float)$v : null);
        }
        if (array_key_exists($pk, $decoded)) {
          $v = $decoded[$pk];
          $out['rules'][$k]['premium_per_hour'] = (is_numeric($v) ? (float)$v : null);
        }
      }
    }
  } catch (Throwable $e) {
    // ignore
  }

  return $out;
}

/**
 * Break minutes by tiered worked-minutes rules.
 * Select highest tier where min_worked_minutes <= worked.
 */
function payroll_break_minutes_for_worked(PDO $pdo, int $workedMinutes): int {
  if ($workedMinutes <= 0) return 0;
  try {
    $st = $pdo->prepare('SELECT break_minutes FROM kiosk_break_tiers WHERE is_enabled=1 AND min_worked_minutes <= ? ORDER BY min_worked_minutes DESC, sort_order DESC, id DESC LIMIT 1');
    $st->execute([$workedMinutes]);
    $bm = $st->fetchColumn();
    if ($bm !== false && $bm !== null) return max(0, (int)$bm);
  } catch (Throwable $e) {
    // ignore
  }
  // No fallback setting: if no tier matches, break is 0.
  return 0;
}

/** Load bank holidays between two local dates (inclusive). Returns set of 'Y-m-d' => name */
function payroll_bank_holidays(PDO $pdo, string $startYmd, string $endYmd): array {
  $out = [];
  try {
    $st = $pdo->prepare('SELECT holiday_date, name FROM payroll_bank_holidays WHERE holiday_date BETWEEN ? AND ?');
    $st->execute([$startYmd, $endYmd]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $d = (string)$r['holiday_date'];
      $out[$d] = (string)($r['name'] ?? '');
    }
  } catch (Throwable $e) {
    // ignore
  }
  return $out;
}

function payroll_is_weekend_local(DateTimeImmutable $local): bool {
  $dow = (int)$local->format('N'); // 6=Sat 7=Sun
  return $dow === 6 || $dow === 7;
}

/** Convert minutes to HH:MM */
function payroll_fmt_hhmm(int $minutes): string {
  if ($minutes < 0) $minutes = 0;
  $h = intdiv($minutes, 60);
  $m = $minutes % 60;
  return sprintf('%02d:%02d', $h, $m);
}

/**
 * Split a UTC interval into chunks by local midnight boundaries.
 * Returns list of segments: [start_utc, end_utc, minutes, local_date_ymd, local_start]
 */
function payroll_split_by_local_day(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc, DateTimeZone $tz): array {
  if ($endUtc <= $startUtc) return [];
  $segments = [];

  $curUtc = $startUtc;
  while ($curUtc < $endUtc) {
    $curLocal = $curUtc->setTimezone($tz);
    $nextMidLocal = $curLocal->setTime(0,0,0)->modify('+1 day');
    $nextMidUtc = $nextMidLocal->setTimezone(new DateTimeZone('UTC'));
    $segEndUtc = ($nextMidUtc < $endUtc) ? $nextMidUtc : $endUtc;

    $mins = (int)round(($segEndUtc->getTimestamp() - $curUtc->getTimestamp()) / 60);
    if ($mins < 0) $mins = 0;

    $segments[] = [
      'start_utc' => $curUtc,
      'end_utc' => $segEndUtc,
      'minutes' => $mins,
      'local_date' => $curLocal->format('Y-m-d'),
      'local_start' => $curLocal,
    ];

    $curUtc = $segEndUtc;
  }
  return $segments;
}

