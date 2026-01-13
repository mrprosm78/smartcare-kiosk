<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_devices');

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'revoke') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid device id');
      $reason = substr(trim((string)($_POST['reason'] ?? '')), 0, 120);
      $stmt = $pdo->prepare("UPDATE admin_devices SET revoked_at = UTC_TIMESTAMP, revoke_reason = ? WHERE id = ?");
      $stmt->execute([$reason !== '' ? $reason : 'revoked', $id]);
      $msg = 'Device revoked.';
    }

    if ($action === 'revoke_all') {
      $cur = admin_setting_int($pdo, 'admin_pairing_version', 1);
      admin_set_setting($pdo, 'admin_pairing_version', (string)($cur + 1));
      $msg = 'All devices revoked (pairing version bumped).';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// list devices
$devices = [];
try {
  $stmt = $pdo->query("SELECT id,label,pairing_version,created_at,last_seen_at,last_ip,last_user_agent,revoked_at,revoke_reason
                       FROM admin_devices
                       ORDER BY revoked_at IS NULL DESC, last_seen_at DESC, created_at DESC");
  $devices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $err = $err ?: 'Could not load devices.';
}

admin_page_start($pdo, 'Devices');
$active = admin_url('devices.php');
$csrf = admin_csrf_token();
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-8">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Devices</h1>
                <p class="mt-2 text-sm text-white/70">Trusted admin devices. Revoke a device if it’s lost or you want to lock down access.</p>
              </div>

              <form method="post" class="flex items-center gap-2">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                <input type="hidden" name="action" value="revoke_all" />
                <button type="submit" class="rounded-2xl px-3 py-2 text-sm font-semibold bg-rose-500/10 border border-rose-500/30 text-rose-100 hover:bg-rose-500/20">
                  Revoke all
                </button>
              </form>
            </div>
          </header>

          <?php if ($msg): ?>
            <div class="mt-4 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-100"><?= h($msg) ?></div>
          <?php endif; ?>
          <?php if ($err): ?>
            <div class="mt-4 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-100"><?= h($err) ?></div>
          <?php endif; ?>

          <div class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-5 overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-white/60">
                <tr class="border-b border-white/10">
                  <th class="text-left py-3 pr-3">Label</th>
                  <th class="text-left py-3 pr-3">Last seen</th>
                  <th class="text-left py-3 pr-3">IP</th>
                  <th class="text-left py-3 pr-3">Pair ver</th>
                  <th class="text-left py-3 pr-3">Status</th>
                  <th class="text-right py-3">Action</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$devices): ?>
                <tr><td colspan="6" class="py-6 text-white/60">No devices found.</td></tr>
              <?php endif; ?>
              <?php foreach ($devices as $d): ?>
                <?php
                  $rev = !empty($d['revoked_at']);
                  $status = $rev ? 'Revoked' : 'Active';
                ?>
                <tr class="border-b border-white/10">
                  <td class="py-4 pr-3">
                    <div class="font-semibold text-white/90"><?= h((string)($d['label'] ?: ('Device #' . $d['id']))) ?></div>
                    <div class="mt-1 text-xs text-white/40">Added: <?= h((string)($d['created_at'] ?? '')) ?></div>
                    <?php if (!empty($d['last_user_agent'])): ?>
                      <div class="mt-1 text-xs text-white/40 line-clamp-1"><?= h((string)$d['last_user_agent']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="py-4 pr-3 text-white/70"><?= h((string)($d['last_seen_at'] ?? '—')) ?></td>
                  <td class="py-4 pr-3 text-white/70"><?= h((string)($d['last_ip'] ?? '—')) ?></td>
                  <td class="py-4 pr-3 text-white/70"><?= (int)($d['pairing_version'] ?? 0) ?></td>
                  <td class="py-4 pr-3">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold border <?= $rev ? 'border-rose-500/30 bg-rose-500/10 text-rose-100' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100' ?>">
                      <?= h($status) ?>
                    </span>
                    <?php if ($rev && !empty($d['revoke_reason'])): ?>
                      <div class="mt-1 text-xs text-white/40">Reason: <?= h((string)$d['revoke_reason']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="py-4 text-right">
                    <?php if (!$rev): ?>
                      <form method="post" class="flex items-center justify-end gap-2">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                        <input type="hidden" name="action" value="revoke" />
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>" />
                        <input name="reason" placeholder="reason" class="w-32 rounded-xl bg-slate-950/60 border border-white/10 px-2 py-1 text-xs text-white/80 placeholder:text-white/30" />
                        <button type="submit" class="rounded-2xl px-3 py-2 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-rose-100 hover:bg-rose-500/20">Revoke</button>
                      </form>
                    <?php else: ?>
                      <span class="text-xs text-white/40">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
