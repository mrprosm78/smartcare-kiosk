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
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
  <meta name="theme-color" content="#f3f4f6" />
  <title>Admin Login</title>
  <link rel="stylesheet" href="<?= h($css) ?>" />
</head>
<body class="bg-slate-100 text-slate-900 min-h-dvh">
  <div class="min-h-dvh flex flex-col">
    <header class="px-4 sm:px-6 pt-6 pb-4">
      <div class="max-w-xl mx-auto">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-xs uppercase tracking-widest text-slate-500">SmartCare</div>
            <h1 class="text-2xl font-semibold">Admin login</h1>
          </div>
          <a href="<?= h(kiosk_url()) ?>" class="text-sm text-slate-600 hover:text-slate-900">Kiosk</a>
        </div>
        <p class="mt-2 text-sm text-slate-600">Sign in to continue.</p>
      </div>
    </header>

    <main class="flex-1 px-4 sm:px-6 pb-10">
      <div class="max-w-xl mx-auto">
        <?php if ($error !== ''): ?>
          <div class="mb-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-slate-900">
            <?= h($error) ?>
          </div>
        <?php endif; ?>

        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
          <form method="post" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-700">Username</label>
              <input name="username" value="<?= h($username) ?>" autocomplete="username" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-3 text-base outline-none focus:border-slate-200" placeholder="Enter username" />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700">Password</label>
              <input type="password" name="password" autocomplete="current-password" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-3 text-base outline-none focus:border-slate-200" placeholder="Enter password" />
            </div>

            <button type="submit" class="w-full rounded-2xl bg-white text-slate-900 font-semibold py-3 hover:bg-white/90">Sign in</button>

            <div class="text-xs text-slate-500">No registration is available. Ask Superadmin to create users.</div>
          </form>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
