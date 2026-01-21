<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_employees');

$active = admin_url('employees.php');

$id = (int)($_GET['id'] ?? 0);
$isNew = ($id <= 0);
$err = '';

// load departments

$cats = $pdo->query("SELECT id, name FROM kiosk_employee_categories WHERE is_active=1 ORDER BY sort_order ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];

// load teams
$teams = $pdo->query("SELECT id, name FROM kiosk_employee_teams WHERE is_active=1 ORDER BY sort_order ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];

$employee = [
  'id' => 0,
  'employee_code' => '',
  'first_name' => '',
  'last_name' => '',
  'nickname' => '',
  'category_id' => null,
  'team_id' => null,
  'is_agency' => 0,
  'agency_label' => '',
  'is_active' => 1,
];

// agency preset
if ($isNew && (string)($_GET['agency'] ?? '') === '1') {
  $employee['is_agency'] = 1;
  $employee['agency_label'] = 'Agency';
}

if (!$isNew) {
  $stmt = $pdo->prepare('SELECT * FROM kiosk_employees WHERE id=? LIMIT 1');
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    exit('Employee not found');
  }
  $employee = array_merge($employee, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);

  try {
    $employee_code = trim((string)($_POST['employee_code'] ?? ''));
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last = trim((string)($_POST['last_name'] ?? ''));
    $nick = trim((string)($_POST['nickname'] ?? ''));
    $category_id = (int)($_POST['category_id'] ?? 0);
    $category_id = $category_id > 0 ? $category_id : null;
    $is_agency = (int)($_POST['is_agency'] ?? 0) === 1 ? 1 : 0;
    $agency_label = trim((string)($_POST['agency_label'] ?? ''));
    $is_active = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($first === '' && $is_agency === 0) throw new RuntimeException('First name is required');
    if ($is_agency === 1 && $agency_label === '') $agency_label = 'Agency';

    // PIN
    $pin = trim((string)($_POST['pin'] ?? ''));
    $pin_hash = null;
    $pin_fingerprint = null;
    if ($pin !== '') {
      if (!preg_match('/^\d{4,10}$/', $pin)) {
        throw new RuntimeException('PIN must be 4-10 digits');
      }
      $pin_hash = password_hash($pin, PASSWORD_BCRYPT);
      $pin_fingerprint = hash('sha256', $pin);

      // Ensure employee PIN is unique
      $chk = $pdo->prepare("SELECT id FROM kiosk_employees WHERE pin_fingerprint = ? AND archived_at IS NULL LIMIT 1");
      $chk->execute([$pin_fingerprint]);
      $existingId = (int)($chk->fetchColumn() ?: 0);
      if ($existingId > 0 && ($isNew || $existingId !== (int)$id)) {
        throw new RuntimeException('PIN is already in use by another employee');
      }
    }

    if ($isNew) {
      $stmt = $pdo->prepare(
        'INSERT INTO kiosk_employees (employee_code, first_name, last_name, nickname, category_id, team_id, is_agency, agency_label, pin_hash, pin_fingerprint, pin_updated_at, is_active, created_at, updated_at)
         VALUES (:code,:first,:last,:nick,:cat,:team,:ag,:al,:pin,:pinfp, UTC_TIMESTAMP, :active, UTC_TIMESTAMP, UTC_TIMESTAMP)'
      );
      $stmt->execute([
        ':code' => $employee_code !== '' ? $employee_code : null,
        ':first' => $first,
        ':last' => $last,
        ':nick' => $nick !== '' ? $nick : null,
        ':cat' => $category_id,
        ':team' => $team_id,
        ':ag' => $is_agency,
        ':al' => $agency_label !== '' ? $agency_label : null,
        ':pin' => $pin_hash ?? '',
        ':pinfp' => $pin_fingerprint,
        ':active' => $is_active,
      ]);
      $id = (int)$pdo->lastInsertId();
      $isNew = false;

      // Ensure a pay profile row exists (editable by Admin; viewable by Payroll)
      $pdo->prepare('INSERT IGNORE INTO kiosk_employee_pay_profiles (employee_id, created_at, updated_at) VALUES (?, UTC_TIMESTAMP, UTC_TIMESTAMP)')
        ->execute([$id]);
    } else {
      $sql = 'UPDATE kiosk_employees SET employee_code=:code, first_name=:first, last_name=:last, nickname=:nick, category_id=:cat, team_id=:team, is_agency=:ag, agency_label=:al, is_active=:active, updated_at=UTC_TIMESTAMP';
      $params = [
        ':team' => $team_id,
        ':code' => $employee_code !== '' ? $employee_code : null,
        ':first' => $first,
        ':last' => $last,
        ':nick' => $nick !== '' ? $nick : null,
        ':cat' => $category_id,
        ':ag' => $is_agency,
        ':al' => $agency_label !== '' ? $agency_label : null,
        ':active' => $is_active,
        ':id' => $id,
      ];
      if ($pin_hash !== null) {
        $sql .= ', pin_hash=:pin, pin_fingerprint=:pinfp, pin_updated_at=UTC_TIMESTAMP';
        $params[':pin'] = $pin_hash;
        $params[':pinfp'] = $pin_fingerprint;
      }
      $sql .= ' WHERE id=:id LIMIT 1';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
    }

    admin_redirect(admin_url('employees.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

admin_page_start($pdo, $isNew ? 'Add Employee' : 'Edit Employee');

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
                <h1 class="text-2xl font-semibold"><?= h($isNew ? 'Add employee' : 'Edit employee') ?></h1>
                <p class="mt-2 text-sm text-white/70">Basic employee profile (no contract/pay fields). Contract & pay is managed by Admin.</p>
              </div>
              <a href="<?= h(admin_url('employees.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Back</a>
            </div>
          </header>

          <?php if ($err !== ''): ?>
            <div class="mt-5 rounded-3xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-100"><?= h($err) ?></div>
          <?php endif; ?>

          <form method="post" class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-5">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">

            <section class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Profile</h2>

              <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-semibold text-white/70">Employee code (optional)</label>
                  <input name="employee_code" value="<?= h((string)($employee['employee_code'] ?? '')) ?>" class="mt-1 w-full rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm" placeholder="e.g. EMP-001">
                </div>

                <div>
                  <label class="block text-xs font-semibold text-white/70">Department</label>
                  <select name="category_id" class="mt-1 w-full rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm">
                    <option value="0">—</option>
                    <?php foreach ($cats as $c): ?>
                      <option value="<?= (int)$c['id'] ?>" <?php if ((int)($employee['category_id'] ?? 0) === (int)$c['id']) echo 'selected'; ?>><?= h((string)$c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="mt-2 text-xs text-white/50"><a class="underline hover:text-white" href="<?= h(admin_url('categories.php')) ?>">Manage categories</a></div>
                </div>
              <div class="mt-4">
                <label class="text-xs text-white/60">Team</label>
                <select name="team_id" class="mt-1 w-full rounded-2xl bg-slate-900/60 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30">
                  <option value="0">—</option>
                  <?php foreach ($teams as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= ((int)$employee['team_id']===(int)$t['id'])?'selected':'' ?>><?= h((string)$t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>


                <div>
                  <label class="block text-xs font-semibold text-white/70">First name</label>
                  <input name="first_name" value="<?= h((string)($employee['first_name'] ?? '')) ?>" class="mt-1 w-full rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm" placeholder="e.g. Aisha">
                </div>

                <div>
                  <label class="block text-xs font-semibold text-white/70">Last name</label>
                  <input name="last_name" value="<?= h((string)($employee['last_name'] ?? '')) ?>" class="mt-1 w-full rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm" placeholder="e.g. Khan">
                </div>

                <div>
                  <label class="block text-xs font-semibold text-white/70">Nickname (optional)</label>
                  <input name="nickname" value="<?= h((string)($employee['nickname'] ?? '')) ?>" class="mt-1 w-full rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm" placeholder="e.g. Ash">
                </div>

                <div>
                  <label class="block text-xs font-semibold text-white/70">PIN (<?= $isNew ? 'required' : 'leave blank to keep' ?>)</label>
                  <input name="pin" inputmode="numeric" autocomplete="new-password" class="mt-1 w-full rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm" placeholder="4+ digits">
                  <div class="mt-1 text-xs text-white/50">Stored securely as a hash.</div>
                </div>

                <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 md:col-span-2">
                  <input type="checkbox" name="is_agency" value="1" class="h-4 w-4 rounded" <?= ((int)($employee['is_agency'] ?? 0) === 1) ? 'checked' : '' ?> />
                  <div class="flex-1">
                    <div class="text-sm font-semibold">Agency profile</div>
                    <div class="text-xs text-white/60">Use for agency workers. Name field will show agency label.</div>
                  </div>
                  <input name="agency_label" value="<?= h((string)($employee['agency_label'] ?? '')) ?>" class="w-40 rounded-2xl bg-slate-950/50 border border-white/10 px-3 py-2 text-sm" placeholder="Agency" />
                </div>

                <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 md:col-span-2">
                  <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded" <?= ((int)($employee['is_active'] ?? 1) === 1) ? 'checked' : '' ?> />
                  <div>
                    <div class="text-sm font-semibold">Active</div>
                    <div class="text-xs text-white/60">Inactive employees cannot clock in/out.</div>
                  </div>
                </div>
              </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Contract & Pay</h2>
              <p class="mt-2 text-sm text-white/70">Hidden from Managers. Use the Contract page to view/edit contract rules.</p>
              <div class="mt-4">
                <?php if (!$isNew && admin_can($user, 'view_contract')): ?>
                  <a href="<?= h(admin_url('employee-contract.php')) ?>?id=<?= (int)$id ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white text-slate-900 hover:bg-white/90">Open Contract</a>
                <?php else: ?>
                  <div class="text-sm text-white/60">Save employee first to manage contract details.</div>
                <?php endif; ?>
              </div>
            </section>

            <div class="lg:col-span-3 flex items-center justify-end">
              <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Save</button>
            </div>
          </form>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
