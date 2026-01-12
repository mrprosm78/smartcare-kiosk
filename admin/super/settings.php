<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = super_require_login($pdo);
csrf_check();

// Curated settings you said you care about now.
// (You can add more keys later, this page won’t break.)
$fields = [
  [
    'group' => 'Kiosk pairing',
    'items' => [
      ['key' => 'pairing_mode', 'label' => 'Pairing mode', 'type' => 'bool', 'help' => 'If OFF, no new kiosk devices can be paired.'],
      ['key' => 'pairing_autolock_minutes', 'label' => 'Auto-lock pairing (minutes)', 'type' => 'int', 'help' => 'When pairing is ON, auto switches it OFF after this many minutes.'],
      ['key' => 'pairing_code', 'label' => 'Pairing code (shared)', 'type' => 'text', 'help' => 'Code shown/entered during device pairing.'],
    ],
  ],
  [
    'group' => 'UI / Refresh',
    'items' => [
      ['key' => 'ui_version', 'label' => 'UI asset version', 'type' => 'text', 'help' => 'Bump to force CSS refresh (admin uses this).'],
      ['key' => 'ui_reload_token', 'label' => 'UI reload token', 'type' => 'text', 'help' => 'Kiosk can poll and reload when this changes (future).'],
    ],
  ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    foreach ($fields as $group) {
      foreach ($group['items'] as $item) {
        $k = (string)$item['key'];
        if (!array_key_exists($k, $_POST)) {
          // for unchecked bools
          if ($item['type'] === 'bool') {
            setting_set($pdo, $k, '0');
          }
          continue;
        }
        $raw = (string)($_POST[$k] ?? '');
        if ($item['type'] === 'bool') {
          setting_set($pdo, $k, $raw === '1' ? '1' : '0');
        } elseif ($item['type'] === 'int') {
          $n = max(0, (int)$raw);
          setting_set($pdo, $k, (string)$n);
        } else {
          setting_set($pdo, $k, trim($raw));
        }
      }
    }
    admin_flash_set('ok', 'Settings saved');
    header('Location: ./settings.php');
    exit;
  } catch (Throwable $e) {
    admin_flash_set('err', 'Could not save settings');
  }
}

// Load current values
$values = [];
foreach ($fields as $group) {
  foreach ($group['items'] as $item) {
    $k = (string)$item['key'];
    $values[$k] = (string)setting($pdo, $k, '');
  }
}

super_page_start('Super Admin • Settings', $user, './settings.php');
?>

<div class="flex flex-wrap items-start justify-between gap-4">
  <div>
    <div class="text-2xl font-bold">Settings</div>
    <div class="text-sm text-white/60">Super Admin-only switches. These map directly to the <code class="text-white/80">kiosk_settings</code> table.</div>
  </div>
</div>

<form method="post" class="mt-6 space-y-6">
  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>" />

  <?php foreach ($fields as $group): ?>
    <div class="rounded-3xl border border-white/10 bg-white/5 overflow-hidden">
      <div class="p-5 border-b border-white/10">
        <div class="text-sm font-semibold"><?php echo h((string)$group['group']); ?></div>
      </div>
      <div class="p-5 space-y-4">
        <?php foreach ($group['items'] as $item):
          $k = (string)$item['key'];
          $val = $values[$k] ?? '';
          $type = (string)$item['type'];
        ?>
          <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
              <div class="text-sm font-semibold"><?php echo h((string)$item['label']); ?></div>
              <div class="text-xs text-white/60 max-w-xl"><?php echo h((string)$item['help']); ?></div>
            </div>
            <div>
              <?php if ($type === 'bool'): ?>
                <label class="inline-flex items-center gap-2 rounded-2xl bg-slate-900/60 border border-white/10 px-3 py-2">
                  <input type="hidden" name="<?php echo h($k); ?>" value="0" />
                  <input type="checkbox" name="<?php echo h($k); ?>" value="1" <?php echo ($val === '1') ? 'checked' : ''; ?> />
                  <span class="text-sm"><?php echo $val === '1' ? 'ON' : 'OFF'; ?></span>
                </label>
              <?php elseif ($type === 'int'): ?>
                <input name="<?php echo h($k); ?>" value="<?php echo h($val); ?>" inputmode="numeric" class="w-28 rounded-2xl bg-slate-900/60 border border-white/10 px-3 py-2 text-sm" />
              <?php else: ?>
                <input name="<?php echo h($k); ?>" value="<?php echo h($val); ?>" class="w-72 max-w-full rounded-2xl bg-slate-900/60 border border-white/10 px-3 py-2 text-sm" />
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="p-5 border-t border-white/10 flex items-center justify-end">
        <button class="rounded-2xl bg-emerald-500 text-emerald-950 px-4 py-2 text-sm font-semibold hover:opacity-90">Save Settings</button>
      </div>
    </div>
  <?php endforeach; ?>
</form>

<?php super_page_end(); ?>
