<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$next = (string)($_GET['next'] ?? '');
$next = ($next !== '' && str_starts_with($next, admin_url(''))) ? $next : admin_url('login.php');

// If already trusted, skip pairing
$token = admin_get_device_token();
if ($token !== '') {
  $hash = admin_device_hash($token);
  $stmt = $pdo->prepare("SELECT id FROM admin_devices WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1");
  $stmt->execute([$hash]);
  if ($stmt->fetchColumn()) {
    admin_redirect($next);
  }
}

$error = '';
$label = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code  = trim((string)($_POST['code'] ?? ''));
  $label = trim((string)($_POST['label'] ?? ''));

  if (!admin_pairing_is_allowed($pdo)) {
    $error = 'Admin pairing is currently disabled.';
  } else {
    $expected = admin_setting_str($pdo, 'admin_pairing_code', '4321');
    if ($code === '' || $code !== $expected) {
      $error = 'Invalid pairing code.';
    } else {
      // Create trusted device token
      $token = bin2hex(random_bytes(32));
      $hash  = admin_device_hash($token);
      $pairVer = admin_setting_int($pdo, 'admin_pairing_version', 1);

      $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
      $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

      $stmt = $pdo->prepare("INSERT INTO admin_devices (token_hash, label, pairing_version, first_paired_at, last_seen_at, last_ip, last_user_agent) VALUES (?,?,?,?,UTC_TIMESTAMP,?,?)");
      $stmt->execute([
        $hash,
        $label !== '' ? $label : null,
        $pairVer,
        gmdate('Y-m-d H:i:s'),
        $ip !== '' ? $ip : null,
        $ua !== '' ? $ua : null,
      ]);

      admin_set_device_token($token);

      // Log event (optional)
      try {
        $stmt = $pdo->prepare("INSERT INTO kiosk_event_log (occurred_at, ip_address, user_agent, event_type, result, message) VALUES (UTC_TIMESTAMP,?,?, 'admin_pair', 'ok', ?)");
        $stmt->execute([$ip, $ua, $label !== '' ? $label : 'paired']);
      } catch (Throwable $e) {
        // ignore if kiosk_event_log not available
      }

      admin_redirect($next);
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
  <meta name="theme-color" content="#0f172a" />
  <title>Admin Pair Device</title>
  <link rel="stylesheet" href="<?= h($css) ?>" />
</head>
<body class="bg-slate-950 text-white min-h-dvh">
  <div class="min-h-dvh flex flex-col">
    <header class="px-4 sm:px-6 pt-6 pb-4">
      <div class="max-w-xl mx-auto">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-xs uppercase tracking-widest text-white/50">SmartCare</div>
            <h1 class="text-2xl font-semibold">Authorise this device</h1>
          </div>
          <a href="<?= h(app_url('index.php')) ?>" class="text-sm text-white/70 hover:text-white">Kiosk</a>
        </div>
        <p class="mt-2 text-sm text-white/70">Pairing is only possible when Superadmin enables admin pairing mode.</p>
      </div>
    </header>

    <main class="flex-1 px-4 sm:px-6 pb-10">
      <div class="max-w-xl mx-auto">
        <?php if ($error !== ''): ?>
          <div class="mb-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
            <?= h($error) ?>
          </div>
        <?php endif; ?>

        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-sm">
          <form method="post" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-white/80">Pairing code</label>
              <input name="code" inputmode="numeric" autocomplete="one-time-code" class="mt-2 w-full rounded-2xl bg-slate-950/60 border border-white/10 px-4 py-3 text-base outline-none focus:border-white/20" placeholder="Enter code" />
              <div class="mt-2 text-xs text-white/50">Ask Superadmin for the code.</div>
            </div>

            <div>
              <label class="block text-sm font-medium text-white/80">Device label (optional)</label>
              <input name="label" value="<?= h($label) ?>" class="mt-2 w-full rounded-2xl bg-slate-950/60 border border-white/10 px-4 py-3 text-base outline-none focus:border-white/20" placeholder="e.g., Office Laptop" />
            </div>

            <button type="submit" class="w-full rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-semibold py-3">Authorise device</button>

            <div class="text-xs text-white/50">Once authorised, this device can access /admin login.</div>
          </form>
        </div>

        <div class="mt-4 text-xs text-white/40">
          If you lose this device, Superadmin can revoke it from the Admin devices list (we'll add that page next).
        </div>
      </div>
    </main>
  </div>
</body>
</html>
