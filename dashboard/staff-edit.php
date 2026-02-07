<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

admin_page_start($pdo, 'Edit Staff');
$active = admin_url('staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$staffId = (int)($_GET['id'] ?? 0);
if ($staffId <= 0) { http_response_code(400); exit('Missing staff id'); }

// Best-effort create table (older installs)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS hr_staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kiosk_employee_id INT UNSIGNED NULL,
    application_id INT UNSIGNED NULL,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    nickname VARCHAR(100) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(80) NULL,
    department_id INT UNSIGNED NULL,
    team_id INT UNSIGNED NULL,
    status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    photo_path VARCHAR(255) NULL,
    profile_json LONGTEXT NULL,
    created_by_admin_id INT UNSIGNED NULL,
    updated_by_admin_id INT UNSIGNED NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_staff_kiosk (kiosk_employee_id),
    UNIQUE KEY uq_hr_staff_application (application_id),
    KEY idx_hr_staff_dept (department_id),
    KEY idx_hr_staff_status (status),
    KEY idx_hr_staff_updated (updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore */ }

$stmt = $pdo->prepare("SELECT * FROM hr_staff WHERE id = ? LIMIT 1");
$stmt->execute([$staffId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Staff not found'); }

$errors = [];
function post_str(string $k): string { return trim((string)($_POST[$k] ?? '')); }

$depts = $pdo->query("SELECT id, name FROM kiosk_employee_departments ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$teams = $pdo->query("SELECT id, name FROM kiosk_employee_teams ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$first = (string)($row['first_name'] ?? '');
$last = (string)($row['last_name'] ?? '');
$nick = (string)($row['nickname'] ?? '');
$email = (string)($row['email'] ?? '');
$phone = (string)($row['phone'] ?? '');
$dept = (int)($row['department_id'] ?? 0);
$team = (int)($row['team_id'] ?? 0);
$status = (string)($row['status'] ?? 'active');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify();

  $first = post_str('first_name');
  $last = post_str('last_name');
  $nick = post_str('nickname');
  $email = post_str('email');
  $phone = post_str('phone');
  $dept = (int)($_POST['department_id'] ?? 0);
  $team = (int)($_POST['team_id'] ?? 0);
  $status = (string)($_POST['status'] ?? 'active');

  if ($first === '' && $last === '') $errors[] = 'Please enter at least a first name or last name.';
  if (!in_array($status, ['active','inactive','archived'], true)) $status = 'active';

  if (!$errors) {
    $u = $pdo->prepare("UPDATE hr_staff SET
      first_name = ?,
      last_name = ?,
      nickname = ?,
      email = ?,
      phone = ?,
      department_id = ?,
      team_id = ?,
      status = ?,
      updated_by_admin_id = ?
      WHERE id = ?
      LIMIT 1");
    $u->execute([
      $first,
      $last,
      $nick !== '' ? $nick : null,
      $email !== '' ? $email : null,
      $phone !== '' ? $phone : null,
      $dept > 0 ? $dept : null,
      $team > 0 ? $team : null,
      $status,
      (int)($user['id'] ?? 0),
      $staffId
    ]);
    header('Location: ' . admin_url('staff-view.php?id=' . $staffId));
    exit;
  }
}

$name = trim($first . ' ' . $last);
if ($name === '') $name = 'Staff #' . $staffId;

?>
<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">

        <main class="flex-1 min-w-0">
          <header class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Edit Staff</h1>
                <p class="mt-1 text-sm text-slate-600"><?php echo h2($name); ?></p>
              </div>
              <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('staff-view.php?id=' . $staffId)); ?>">Back</a>
            </div>
          </header>

          <?php if ($errors): ?>
            <div class="mt-4 rounded-3xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
              <ul class="list-disc pl-5">
                <?php foreach ($errors as $e): ?><li><?php echo h2($e); ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form class="mt-4 rounded-3xl border border-slate-200 bg-white p-4" method="post">
            <?php admin_csrf_field(); ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div>
                <label class="text-sm font-semibold text-slate-700">First name</label>
                <input name="first_name" value="<?php echo h($first); ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="text-sm font-semibold text-slate-700">Last name</label>
                <input name="last_name" value="<?php echo h($last); ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="text-sm font-semibold text-slate-700">Preferred name</label>
                <input name="nickname" value="<?php echo h($nick); ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="text-sm font-semibold text-slate-700">Status</label>
                <select name="status" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <?php foreach (['active'=>'Active','inactive'=>'Inactive','archived'=>'Archived'] as $k=>$lbl): ?>
                    <option value="<?php echo h($k); ?>"<?php echo ($status===$k)?' selected':''; ?>><?php echo h2($lbl); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="text-sm font-semibold text-slate-700">Email</label>
                <input name="email" value="<?php echo h($email); ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="text-sm font-semibold text-slate-700">Phone</label>
                <input name="phone" value="<?php echo h($phone); ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" />
              </div>

              <div>
                <label class="text-sm font-semibold text-slate-700">Department</label>
                <select name="department_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="0">—</option>
                  <?php foreach ($depts as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>"<?php echo ($dept===(int)$d['id'])?' selected':''; ?>><?php echo h2((string)$d['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="text-sm font-semibold text-slate-700">Team</label>
                <select name="team_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="0">—</option>
                  <?php foreach ($teams as $t): ?>
                    <option value="<?php echo (int)$t['id']; ?>"<?php echo ($team===(int)$t['id'])?' selected':''; ?>><?php echo h2((string)$t['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="mt-4 flex gap-2">
              <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" type="submit">Save changes</button>
              <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('staff-view.php?id=' . $staffId)); ?>">Cancel</a>
            </div>
          </form>
        </main>
</div>
<?php admin_page_end(); ?>
