<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

// ---------------------------------------------------------------------
// Compatibility helpers
// Some installs may not include certain helper functions yet.
// ---------------------------------------------------------------------
if (!function_exists('h')) {
  function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('admin_redirect')) {
  function admin_redirect(string $url): void {
    header('Location: ' . $url);
    exit;
  }
}

if (!function_exists('setting')) {
  /** Minimal fallback for kiosk_settings lookups (string values). */
  function setting(PDO $pdo, string $key, string $default = ''): string {
    try {
      $stmt = $pdo->prepare("SELECT value FROM kiosk_settings WHERE `key` = ? LIMIT 1");
      $stmt->execute([$key]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return $row ? (string)$row['value'] : $default;
    } catch (Throwable $e) {
      return $default;
    }
  }
}

/** Set a kiosk_settings value (insert or update). */
function admin_set_setting(PDO $pdo, string $key, string $value): void {
  // kiosk_settings schema uses `key` as the primary identifier (no numeric id).
  // So we upsert by `key`.
  $stmt = $pdo->prepare("SELECT `key` FROM kiosk_settings WHERE `key` = ? LIMIT 1");
  $stmt->execute([$key]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row && isset($row['key'])) {
    $upd = $pdo->prepare("UPDATE kiosk_settings SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE `key` = ?");
    $upd->execute([$value, $key]);
    return;
  }

  // Insert with your actual kiosk_settings column names.
  $ins = $pdo->prepare(
    "INSERT INTO kiosk_settings (`key`, `value`, group_name, label, description, type, editable_by, sort_order, is_secret, created_at, updated_at)
     VALUES (?, ?, 'admin', NULL, NULL, 'string', 'superadmin', 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
  );
  $ins->execute([$key, $value]);
}

function admin_csrf_token(): string {
  if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['admin_csrf'];
}

function admin_verify_csrf(?string $token): void {
  $ok = is_string($token) && $token !== '' && hash_equals((string)($_SESSION['admin_csrf'] ?? ''), $token);
  if (!$ok) {
    http_response_code(419);
    echo 'Invalid CSRF token';
    exit;
  }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('Database connection not available');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Sessions
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * Compute base path for subfolder installs.
 * Example:
 *  /kiosk-dev/admin/login.php -> admin_base=/kiosk-dev/admin , app_base=/kiosk-dev
 */
$__script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$admin_base = rtrim(str_replace('\\', '/', dirname($__script)), '/');
$app_base   = rtrim(str_replace('\\', '/', dirname($admin_base)), '/');
if ($app_base === '/') $app_base = '';

function admin_url(string $path = ''): string {
  global $admin_base;
  $path = ltrim($path, '/');
  return $admin_base . ($path ? '/' . $path : '');
}

function app_url(string $path = ''): string {
  global $app_base;
  $path = ltrim($path, '/');
  return $app_base . ($path ? '/' . $path : '');
}

function admin_asset_css(PDO $pdo): string {
  $v = (string)setting($pdo, 'admin_ui_version', (string)setting($pdo, 'ui_version', '1'));
  return app_url('assets/kiosk.css') . '?v=' . rawurlencode($v);
}

function admin_setting_bool(PDO $pdo, string $key, bool $default): bool {
  return setting($pdo, $key, $default ? '1' : '0') === '1';
}

function admin_setting_int(PDO $pdo, string $key, int $default): int {
  return (int)setting($pdo, $key, (string)$default);
}

function admin_setting_str(PDO $pdo, string $key, string $default = ''): string {
  return (string)setting($pdo, $key, $default);
}

function admin_pairing_is_allowed(PDO $pdo): bool {
  if (!admin_setting_bool($pdo, 'admin_pairing_mode', false)) return false;
  $until = trim(admin_setting_str($pdo, 'admin_pairing_mode_until', ''));
  if ($until === '') return true;
  // UTC timestamps stored as 'Y-m-d H:i:s'
  return $until > gmdate('Y-m-d H:i:s');
}

function admin_cookie_params(): array {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  return [
    'expires'  => time() + (60 * 60 * 24 * 365),
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ];
}

function admin_get_device_token(): string {
  return (string)($_COOKIE['admin_device_token'] ?? '');
}

function admin_set_device_token(string $token): void {
  setcookie('admin_device_token', $token, admin_cookie_params());
}

function admin_clear_device_token(): void {
  setcookie('admin_device_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function admin_device_hash(string $token): string {
  return hash('sha256', $token);
}

function admin_require_device(PDO $pdo): array {
  $token = admin_get_device_token();
  if ($token === '') {
    admin_redirect(admin_url('pair.php'));
  }

  $hash = admin_device_hash($token);
  $pairVer = admin_setting_int($pdo, 'admin_pairing_version', 1);

  $stmt = $pdo->prepare("SELECT * FROM admin_devices WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1");
  $stmt->execute([$hash]);
  $device = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$device) {
    admin_clear_device_token();
    admin_redirect(admin_url('pair.php'));
  }

  if ((int)$device['pairing_version'] !== $pairVer) {
    // pairing version mismatch => revoke
    admin_clear_device_token();
    admin_redirect(admin_url('pair.php'));
  }

  // update last seen
  $upd = $pdo->prepare("UPDATE admin_devices SET last_seen_at = UTC_TIMESTAMP, last_ip = ?, last_user_agent = ? WHERE id = ?");
  $upd->execute([
    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    (int)$device['id'],
  ]);

  return $device;
}

function admin_current_user(PDO $pdo): ?array {
  $uid = (int)($_SESSION['admin_user_id'] ?? 0);
  if ($uid <= 0) return null;

  $sid = (string)($_SESSION['admin_session_id'] ?? '');
  if ($sid === '' || session_id() !== $sid) {
    return null;
  }

  // validate session row
  $stmt = $pdo->prepare("SELECT s.*, u.username, u.display_name, u.role, u.is_active
                         FROM admin_sessions s
                         JOIN admin_users u ON u.id = s.user_id
                         WHERE s.session_id = ? LIMIT 1");
  $stmt->execute([session_id()]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  if (!empty($row['revoked_at'])) return null;
  if ((int)$row['is_active'] !== 1) return null;

  // touch
  $upd = $pdo->prepare("UPDATE admin_sessions SET last_seen_at = UTC_TIMESTAMP WHERE session_id = ?");
  $upd->execute([session_id()]);

  return $row;
}

function admin_require_user(PDO $pdo): array {
  $user = admin_current_user($pdo);
  if (!$user) {
    admin_redirect(admin_url('login.php'));
  }
  return $user;
}


function admin_is_logged_in(PDO $pdo): bool {
  return admin_current_user($pdo) !== null;
}

function admin_require_login(PDO $pdo): array {
  return admin_require_user($pdo);
}

function admin_logout(PDO $pdo): void {
  try {
    $sid = session_id();
    $stmt = $pdo->prepare("UPDATE admin_sessions SET revoked_at = UTC_TIMESTAMP, revoke_reason='logout' WHERE session_id = ?");
    $stmt->execute([$sid]);
  } catch (Throwable $e) {}

  // Clear session
  $_SESSION['admin_user_id'] = 0;
  $_SESSION['admin_role'] = '';
  $_SESSION['admin_session_id'] = '';

  session_regenerate_id(true);
}

function admin_require_role(array $user, array $roles): void {
  if (!in_array((string)$user['role'], $roles, true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}

// ---------------------------------------------------------------------
// Canonical employee SQL projections
// ---------------------------------------------------------------------
// IMPORTANT:
// - Do NOT add phantom columns (e.g. full_name) to kiosk_employees.
// - Always compute display fields from the real schema:
//     first_name, last_name, nickname, employee_code
// This keeps admin pages stable across fresh installs and schema repairs.

/**
 * SQL expression for an employee's display name.
 * Prefers nickname when present, otherwise "First Last".
 */
function admin_sql_employee_display_name(string $alias = 'e'): string {
  $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
  if ($a === '') $a = 'e';
  return "TRIM(COALESCE(NULLIF({$a}.nickname,''), CONCAT(COALESCE({$a}.first_name,''),' ',COALESCE({$a}.last_name,''))))";
}

/**
 * SQL expression for an employee number / code.
 */
function admin_sql_employee_number(string $alias = 'e'): string {
  $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
  if ($a === '') $a = 'e';
  return "{$a}.employee_code";
}

/**
 * PHP helper for displaying an employee name from a fetched row.
 * Mirrors admin_sql_employee_display_name().
 */
function admin_employee_display_name(array $employeeRow): string {
  $nickname = trim((string)($employeeRow['nickname'] ?? ''));
  if ($nickname !== '') return $nickname;
  $first = trim((string)($employeeRow['first_name'] ?? ''));
  $last  = trim((string)($employeeRow['last_name'] ?? ''));
  $full = trim($first . ' ' . $last);
  if ($full !== '') return $full;
  // Fallback to code if name missing.
  $code = trim((string)($employeeRow['employee_code'] ?? ''));
  return $code !== '' ? $code : 'Employee';
}

function admin_redirect(string $to): void {
  header('Location: ' . $to);
  exit;
}

function admin_layout_start(PDO $pdo, string $title): void {
  $css = admin_asset_css($pdo);
  $brand = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
  echo "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\"/>\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\"/>\n<meta name=\"theme-color\" content=\"#0f172a\"/>\n<title>{$brand}</title>\n<link rel=\"stylesheet\" href=\"{$css}\"/>\n<style>html,body{height:100%} .min-h-dvh{min-height:100dvh}</style>\n</head>\n<body class=\"bg-slate-950 text-white min-h-dvh\">\n";
}

function admin_layout_end(): void {
  echo "\n</body>\n</html>";
}



// ---------------------------------------------------------------------
// Rounding helpers (used for payroll display; does NOT modify originals)
// ---------------------------------------------------------------------
/**
 * Snap a datetime to the nearest rounding boundary ONLY if within grace minutes.
 * - increment: minute grid (e.g. 15 => 00/15/30/45)
 * - grace: tolerance window (e.g. 5 => within 5 mins of boundary)
 * Returns original if outside grace.
 */
function admin_round_datetime(?string $dtStr, int $incrementMin, int $graceMin): ?string {
  if (!$dtStr) return null;
  try {
    $dt = new DateTimeImmutable($dtStr, new DateTimeZone('UTC'));
  } catch (Throwable $e) {
    return $dtStr;
  }

  $inc = max(1, $incrementMin);
  $grace = max(0, $graceMin);

  $minutes = (int)$dt->format('i');
  $hours   = (int)$dt->format('H');
  $total   = $hours * 60 + $minutes;

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
    return $dt->format('Y-m-d H:i:s');
  }

  $newH = intdiv($candidate, 60) % 24;
  $newM = $candidate % 60;
  return $dt->setTime($newH, $newM, 0)->format('Y-m-d H:i:s');
}

function admin_minutes_between(?string $a, ?string $b): ?int {
  if (!$a || !$b) return null;
  try {
    $da = new DateTimeImmutable($a, new DateTimeZone('UTC'));
    $db = new DateTimeImmutable($b, new DateTimeZone('UTC'));
    $diff = $db->getTimestamp() - $da->getTimestamp();
    return (int)floor($diff / 60);
  } catch (Throwable $e) {
    return null;
  }
}

function admin_fmt_dt(?string $dt): string {
  if (!$dt) return '—';
  try {
    $d = new DateTimeImmutable($dt, new DateTimeZone('UTC'));
    return $d->format('d M Y, H:i');
  } catch (Throwable $e) {
    return $dt;
  }
}

// ---------------------------------------------------------------------
// Shift effective values (original + latest edit change)
// ---------------------------------------------------------------------
function admin_shift_effective(array $shiftRow): array {
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
        if ($co === null || $co === '') {
          $eff['clock_out_at'] = '';
        } else {
          $eff['clock_out_at'] = (string)$co;
        }
      }
      if (array_key_exists('break_minutes', $data) && $data['break_minutes'] !== null && $data['break_minutes'] !== '') {
        $eff['break_minutes'] = (int)$data['break_minutes'];
      }
    }
  }
  return $eff;
}
