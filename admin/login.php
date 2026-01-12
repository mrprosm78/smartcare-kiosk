<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// If already logged in
if (admin_current_user($pdo)) {
  header('Location: ./dashboard.php');
  exit;
}

csrf_check();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $error = 'Please enter your email and password.';
  } else {
    $stmt = $pdo->prepare('SELECT id,email,name,role,password_hash,is_active FROM kiosk_admin_users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$u || (int)$u['is_active'] !== 1 || !password_verify($pass, (string)$u['password_hash'])) {
      $error = 'Invalid email or password.';
    } else {
      session_regenerate_id(true);
      $_SESSION['admin_user_id'] = (int)$u['id'];
      $pdo->prepare('UPDATE kiosk_admin_users SET last_login_at=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$u['id']]);
      header('Location: ./dashboard.php');
      exit;
    }
  }
}

$v = h(admin_ui_version($pdo));
$csrf = h(csrf_token());
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
  <meta name="theme-color" content="#0f172a" />
  <title>Admin Login</title>
  <link rel="stylesheet" href="../assets/kiosk.css?v=<?=$v?>">
  <style>html,body{height:100%} .min-h-dvh{min-height:100dvh}</style>
</head>
<body class="bg-slate-950 text-white min-h-dvh">
  <div class="min-h-dvh flex flex-col">

    <header class="px-4 sm:px-6 pt-6 pb-4">
      <div class="mx-auto max-w-md flex items-center gap-3">
        <div class="h-10 w-10 rounded-2xl bg-white/10 flex items-center justify-center">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="opacity-90">
            <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Z" stroke="currentColor" stroke-width="2"/>
            <path d="M20 21a8 8 0 0 0-16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <div>
          <div class="text-sm sm:text-base font-semibold leading-tight">Care Home Digital Time Clock</div>
          <div class="text-xs sm:text-sm text-white/60 leading-tight">Admin & Payroll Login</div>
        </div>
      </div>
    </header>

    <main class="flex-1 flex items-center justify-center px-4 pb-10">
      <section class="w-full max-w-md rounded-3xl bg-white/5 border border-white/10 p-6 sm:p-8 shadow-xl shadow-black/30">

        <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Sign in</h1>
        <p class="mt-2 text-sm sm:text-base text-white/70">Managers, payroll and super admins sign in here.</p>

        <form method="post" class="mt-6 space-y-4" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?=$csrf?>">

          <div>
            <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Email</label>
            <input name="email" type="email" required value="<?=h((string)($_POST['email'] ?? ''))?>"
              class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none focus:ring-2 focus:ring-white/15"
              placeholder="you@carehome.co.uk" />
          </div>

          <div>
            <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Password</label>
            <div class="relative">
              <input id="pw" name="password" type="password" required
                class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 pr-12 text-white placeholder:text-white/30 focus:outline-none focus:ring-2 focus:ring-white/15"
                placeholder="••••••••" />
              <button type="button" id="togglePw" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-xl bg-white/5 hover:bg-white/10 px-3 py-2 text-xs text-white/70">Show</button>
            </div>
          </div>

          <button type="submit" class="w-full rounded-2xl bg-white text-slate-900 font-extrabold py-3.5 text-base hover:bg-white/90 active:bg-white/80">Sign in</button>

          <?php if ($error): ?>
            <div class="rounded-2xl bg-rose-500/10 border border-rose-400/20 p-4 text-sm text-rose-200"><?=h($error)?></div>
          <?php endif; ?>

          <div class="text-xs text-white/40">
            Default creds are created by setup.php on first install. You should change them in the DB.
          </div>
        </form>
      </section>
    </main>

    <footer class="px-4 pb-6 text-center text-xs text-white/40">SmartCare • Admin Login</footer>
  </div>

  <script>
    const pw = document.getElementById('pw');
    const toggle = document.getElementById('togglePw');
    toggle.addEventListener('click', () => {
      const isPw = pw.type === 'password';
      pw.type = isPw ? 'text' : 'password';
      toggle.textContent = isPw ? 'Hide' : 'Show';
    });
  </script>
</body>
</html>
