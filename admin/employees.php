<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = admin_require_login($pdo, ['manager','superadmin']);
csrf_check();

$pinLength = function_exists('setting_int') ? setting_int($pdo, 'pin_length', 4) : 4;

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'add') {
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    $nick = trim((string)($_POST['nickname'] ?? ''));
    $code = trim((string)($_POST['employee_code'] ?? ''));
    $pin  = trim((string)($_POST['pin'] ?? ''));

    if ($first === '' || $last === '' || $pin === '') {
      admin_flash_set('err', 'First name, last name and PIN are required.');
      header('Location: ./employees.php');
      exit;
    }
    if (!ctype_digit($pin) || strlen($pin) !== (int)$pinLength) {
      admin_flash_set('err', 'PIN must be exactly ' . (int)$pinLength . ' digits.');
      header('Location: ./employees.php');
      exit;
    }

    $hash = password_hash($pin, PASSWORD_DEFAULT);

    try {
      $stmt = $pdo->prepare("INSERT INTO kiosk_employees (employee_code, first_name, last_name, nickname, pin_hash, pin_updated_at, is_active) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),1)");
      $stmt->execute([
        $code !== '' ? $code : null,
        $first,
        $last,
        $nick !== '' ? $nick : null,
        $hash,
      ]);
      admin_flash_set('ok', 'Employee added.');
    } catch (Throwable $e) {
      admin_flash_set('err', 'Could not add employee. ' . $e->getMessage());
    }

    header('Location: ./employees.php');
    exit;
  }

  if ($action === 'archive') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    if ($empId > 0) {
      $pdo->prepare("UPDATE kiosk_employees SET is_active=0, archived_at=UTC_TIMESTAMP() WHERE id=?")->execute([$empId]);
      admin_flash_set('ok', 'Employee archived.');
    }
    header('Location: ./employees.php');
    exit;
  }

  if ($action === 'reset_pin') {
    $empId = (int)($_POST['employee_id'] ?? 0);
    $pin  = trim((string)($_POST['pin'] ?? ''));
    if ($empId <= 0) {
      admin_flash_set('err', 'Invalid employee.');
      header('Location: ./employees.php');
      exit;
    }
    if (!ctype_digit($pin) || strlen($pin) !== (int)$pinLength) {
      admin_flash_set('err', 'PIN must be exactly ' . (int)$pinLength . ' digits.');
      header('Location: ./employees.php');
      exit;
    }
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE kiosk_employees SET pin_hash=?, pin_updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$hash, $empId]);
    admin_flash_set('ok', 'PIN updated.');
    header('Location: ./employees.php');
    exit;
  }
}

$employees = $pdo->query("SELECT id, employee_code, first_name, last_name, nickname, is_active, archived_at, created_at FROM kiosk_employees ORDER BY is_active DESC, first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

admin_page_start('Employees', $user, './employees.php');
$csrf = h(csrf_token());
?>

<div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
  <div>
    <div class="text-2xl font-extrabold tracking-tight">Employees</div>
    <div class="mt-1 text-sm text-white/60">Add employees, reset PINs, and archive leavers. PINs are stored hashed.</div>
  </div>
</div>

<div class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-4">
  <div class="lg:col-span-1 rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-lg font-bold">Add employee</div>
    <form method="post" class="mt-4 space-y-3">
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">
      <input type="hidden" name="action" value="add">

      <div>
        <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Employee code (optional)</label>
        <input name="employee_code" class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="E.g. SC-001">
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">First name</label>
          <input name="first_name" required class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="First name">
        </div>
        <div>
          <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Last name</label>
          <input name="last_name" required class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="Last name">
        </div>
      </div>

      <div>
        <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">Nickname (optional)</label>
        <input name="nickname" class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="E.g. Mo">
      </div>

      <div>
        <label class="block text-xs uppercase tracking-wider text-white/50 mb-2">PIN (<?=$pinLength?> digits)</label>
        <input name="pin" inputmode="numeric" pattern="\d*" required class="w-full rounded-2xl bg-white/5 border border-white/10 px-4 py-3 text-white placeholder:text-white/30 focus:outline-none" placeholder="<?=$pinLength?>-digit PIN">
      </div>

      <button class="w-full rounded-2xl bg-white text-slate-900 px-4 py-3 text-sm font-extrabold hover:bg-white/90">Add employee</button>

      <div class="text-xs text-white/40">Tip: use the same PIN length as the kiosk.</div>
    </form>
  </div>

  <div class="lg:col-span-2 rounded-3xl bg-white/5 border border-white/10 p-5">
    <div class="text-lg font-bold">Employee list</div>

    <div class="mt-4 overflow-hidden rounded-3xl border border-white/10 bg-slate-950/30">
      <div class="overflow-x-auto">
        <table class="min-w-full text-left">
          <thead>
            <tr class="text-xs uppercase tracking-wider text-white/50">
              <th class="px-4 py-3">Name</th>
              <th class="px-4 py-3">Code</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/10">
            <?php foreach ($employees as $e):
              $name = trim((string)$e['first_name'].' '.(string)$e['last_name']);
              if (!empty($e['nickname'])) $name .= ' (' . $e['nickname'] . ')';
              $active = (int)$e['is_active'] === 1 && empty($e['archived_at']);
            ?>
            <tr class="text-sm">
              <td class="px-4 py-3 font-semibold">
                <div><?=h($name)?></div>
                <div class="mt-1 text-xs text-white/50">Added <?=h(gmdate('d M Y', strtotime((string)$e['created_at'])))?></div>
              </td>
              <td class="px-4 py-3 text-white/80"><?=h((string)($e['employee_code'] ?? ''))?></td>
              <td class="px-4 py-3">
                <?php if ($active): ?>
                  <span class="inline-flex items-center rounded-full border border-emerald-400/20 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-100">Active</span>
                <?php else: ?>
                  <span class="inline-flex items-center rounded-full border border-white/10 bg-white/10 px-3 py-1 text-xs font-semibold text-white/70">Archived</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <?php if ($active): ?>
                  <details class="inline-block">
                    <summary class="cursor-pointer rounded-2xl bg-white/10 border border-white/10 px-3 py-2 text-xs font-semibold hover:bg-white/15 inline-block">Reset PIN</summary>
                    <div class="mt-2 rounded-2xl border border-white/10 bg-slate-950/60 p-3">
                      <form method="post" class="flex items-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                        <input type="hidden" name="action" value="reset_pin">
                        <input type="hidden" name="employee_id" value="<?= (int)$e['id'] ?>">
                        <input name="pin" placeholder="New PIN" class="rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-xs text-white placeholder:text-white/30 focus:outline-none" required>
                        <button class="rounded-2xl bg-white text-slate-900 px-3 py-2 text-xs font-extrabold hover:bg-white/90">Save</button>
                      </form>
                    </div>
                  </details>

                  <form method="post" class="inline-block ml-2" onsubmit="return confirm('Archive this employee?');">
                    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="employee_id" value="<?= (int)$e['id'] ?>">
                    <button class="rounded-2xl bg-rose-500/10 border border-rose-400/20 px-3 py-2 text-xs font-semibold text-rose-200 hover:bg-rose-500/15">Archive</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>

            <?php if (!$employees): ?>
              <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-white/60">No employees yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
admin_page_end();
