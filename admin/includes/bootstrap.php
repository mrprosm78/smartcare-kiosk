<?php
declare(strict_types=1);

// Admin bootstrap (shared by all admin pages)

if (session_status() !== PHP_SESSION_ACTIVE) {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

$projectRoot = dirname(__DIR__, 2);

require_once $projectRoot . '/db.php';
require_once $projectRoot . '/helpers.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('Database connection not available');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function admin_ui_version(PDO $pdo): string {
  $v = '1';
  try {
    if (function_exists('setting')) {
      $v = (string)setting($pdo, 'ui_version', '1');
      $v = trim($v) !== '' ? trim($v) : '1';
    }
  } catch (Throwable $e) {
    $v = '1';
  }
  return $v;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_check(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
  $in = (string)($_POST['csrf_token'] ?? '');
  $ok = !empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $in);
  if (!$ok) {
    http_response_code(403);
    exit('CSRF check failed');
  }
}

function admin_current_user(PDO $pdo): ?array {
  $id = (int)($_SESSION['admin_user_id'] ?? 0);
  if ($id <= 0) return null;
  $stmt = $pdo->prepare('SELECT id, email, name, role, is_active FROM kiosk_admin_users WHERE id=? LIMIT 1');
  $stmt->execute([$id]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$u || (int)$u['is_active'] !== 1) return null;
  return $u;
}

function admin_require_login(PDO $pdo, array $roles = []): array {
  $u = admin_current_user($pdo);
  if (!$u) {
    header('Location: ./login.php');
    exit;
  }
  if ($roles && !in_array($u['role'], $roles, true)) {
    http_response_code(403);
    exit('Forbidden');
  }
  return $u;
}

function admin_flash_set(string $key, string $value): void {
  $_SESSION['flash'][$key] = $value;
}

function admin_flash_get(string $key): ?string {
  $v = $_SESSION['flash'][$key] ?? null;
  if ($v !== null) {
    unset($_SESSION['flash'][$key]);
  }
  return $v;
}
