<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// If already logged in, go dashboard
if (admin_is_logged_in($pdo)) {
  admin_redirect(admin_url('index.php'));
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $error = 'Please enter username and password.';
  } else {
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
      $error = 'Invalid credentials.';
      // log
      try {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt2 = $pdo->prepare("INSERT INTO kiosk_event_log (occurred_at, ip_address, user_agent, event_type, result, message, meta_json) VALUES (UTC_TIMESTAMP,?,?, 'admin_login', 'fail', ?, JSON_OBJECT('username', ?))");
        $stmt2->execute([$ip, $ua, 'invalid credentials', $username]);
      } catch (Throwable $e) {}
    } else {
      // Create session record
      $_SESSION['admin_user_id'] = (int)$user['id'];
      $_SESSION['admin_role']    = (string)$user['role'];
      $_SESSION['admin_session_id'] = session_id();

      $sessionId = session_id();
      $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
      $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

      // Device pairing is intentionally disabled; device_id is stored as NULL.
      $stmt = $pdo->prepare("INSERT INTO admin_sessions (session_id, user_id, device_id, ip_address, user_agent, created_at, last_seen_at)
                             VALUES (?,?,?,?,?,UTC_TIMESTAMP,UTC_TIMESTAMP)
                             ON DUPLICATE KEY UPDATE
                               user_id=VALUES(user_id),
                               device_id=VALUES(device_id),
                               ip_address=VALUES(ip_address),
                               user_agent=VALUES(user_agent),
                               last_seen_at=UTC_TIMESTAMP,
                               revoked_at=NULL,
                               revoke_reason=NULL");
      $stmt->execute([$sessionId, (int)$user['id'], null, $ip ?: null, $ua ?: null]);

      $pdo->prepare("UPDATE admin_users SET last_login_at=UTC_TIMESTAMP WHERE id=?")->execute([(int)$user['id']]);

      try {
        $stmt2 = $pdo->prepare("INSERT INTO kiosk_event_log (occurred_at, ip_address, user_agent, event_type, result, message, meta_json) VALUES (UTC_TIMESTAMP,?,?, 'admin_login', 'ok', ?, JSON_OBJECT('user_id', ?, 'role', ?))");
        $stmt2->execute([$ip, $ua, 'login ok', (int)$user['id'], (string)$user['role']]);
      } catch (Throwable $e) {}

      admin_redirect(admin_url('index.php'));
    }
  }
}

$css = admin_asset_css($pdo);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin login</title>
  <?php
  // Base path detection (supports installs in subfolders like /smartcare-kiosk)
  $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  $detectedBase = rtrim(str_replace('\\\\', '/', dirname($scriptName)), '/');
  if ($detectedBase === '/') $detectedBase = '';
  $configuredBase = defined('APP_BASE_PATH') ? rtrim((string)APP_BASE_PATH, '/') : '';
  if ($configuredBase === '/') $configuredBase = '';
  $basePath = ($configuredBase !== '') ? $configuredBase : $detectedBase;
  $cssV = defined('APP_CSS_VERSION') ? (string)APP_CSS_VERSION : (string)time();
?>
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/app.css?v=<?= htmlspecialchars($cssV, ENT_QUOTES) ?>">
</head>
<body class="min-h-screen bg-sc-bg text-sc-text antialiased">
  <div class="min-h-screen flex flex-col">
    <?php
      // Use the public header so branding/contact details stay consistent.
      $brandRightHtml = '';
      require __DIR__ . '/../careers/includes/brand-header.php';
    ?>

    <main class="flex-1">
      <div class="mx-auto max-w-md px-4 py-10 h-full">
        <div class="flex h-full items-center">
          <div class="w-full rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h1 class="text-lg font-semibold text-slate-900">Admin login</h1>
          <p class="mt-1 text-sm text-slate-600">For authorised staff only.</p>

          <?php if ($error !== ''): ?>
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <?= h($error); ?>
            </div>
          <?php endif; ?>

          <form method="post" class="mt-5 space-y-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700">Username</label>
              <input name="username" value="<?= h($username); ?>" autocomplete="username"
                     class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-sc-primary" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700">Password</label>
              <input type="password" name="password" autocomplete="current-password"
                     class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-sc-primary" />
            </div>
            <button class="w-full rounded-md bg-sc-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-95">
              Sign in
            </button>
          </form>
          </div>
        </div>
      </div>
    </main>

    <?php require __DIR__ . '/../careers/includes/footer-public.php'; ?>
  </div>
</body>
</html>
