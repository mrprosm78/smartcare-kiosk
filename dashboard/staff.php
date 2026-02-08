<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

admin_page_start($pdo, 'Staff');
$active = admin_url('staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$status = (string)($_GET['status'] ?? 'active'); // active|inactive|archived|all
$dept = (int)($_GET['department_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

// Sorting (like Applicants)
$sort = strtolower(trim((string)($_GET['sort'] ?? 'name')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';

$sortMap = [
  'name'       => 'name',
  'department' => 'department',
  'status'     => 'status',
  'kiosk'      => 'kiosk',
  'updated'    => 'updated',
  'created'    => 'created',
];
if (!isset($sortMap[$sort])) $sort = 'name';

function sc_query_staff(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) { unset($q[$k]); continue; }
    $q[$k] = $v;
  }
  foreach ($q as $k => $v) {
    if ($v === '' || $v === null) unset($q[$k]);
  }
  return http_build_query($q);
}

function sc_sort_link_staff(string $key, string $label, string $currentSort, string $currentDir): string {
  $is = ($currentSort === $key);
  $nextDir = $is && $currentDir === 'asc' ? 'desc' : 'asc';
  $arrow = '';
  if ($is) $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
  $qs = sc_query_staff(['sort' => $key, 'dir' => $nextDir]);
  $href = admin_url('staff.php' . ($qs ? ('?' . $qs) : ''));
  return '<a class="hover:text-slate-900" href="' . h($href) . '">' . h($label) . '</a><span class="text-[11px] text-slate-500">' . h($arrow) . '</span>';
}

// Ensure HR staff table exists (best-effort on older installs)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS hr_staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    KEY idx_hr_staff_dept (department_id),
    KEY idx_hr_staff_status (status),
    KEY idx_hr_staff_updated (updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore */ }

$depts = $pdo->query("SELECT id, name FROM kiosk_employee_departments ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$where = [];
$params = [];

if ($status !== 'all') {
  $where[] = 's.status = ?';
  $params[] = $status;
}
if ($dept > 0) {
  $where[] = 's.department_id = ?';
  $params[] = $dept;
}
if ($q !== '') {
  $where[] = "(CONCAT_WS(' ', s.first_name, s.last_name, s.nickname) LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like; $params[] = $like; $params[] = $like;
}


$dirSql = strtoupper($dir);

// Build ORDER BY from whitelist
switch ($sort) {
  case 'department':
    $orderBy = "department_name {$dirSql}, s.last_name ASC, s.first_name ASC, s.id DESC";
    break;
  case 'status':
    $orderBy = "s.status {$dirSql}, s.last_name ASC, s.first_name ASC, s.id DESC";
    break;
  case 'kiosk':
    $orderBy = "has_kiosk {$dirSql}, s.last_name ASC, s.first_name ASC, s.id DESC";
    break;
  case 'updated':
    $orderBy = "s.updated_at {$dirSql}, s.id DESC";
    break;
  case 'created':
    $orderBy = "s.created_at {$dirSql}, s.id DESC";
    break;
  case 'name':
  default:
    $orderBy = "s.last_name {$dirSql}, s.first_name {$dirSql}, s.id DESC";
    break;
}

$sql = "SELECT
          s.*,
          d.name AS department_name,
          (ke.id IS NOT NULL) AS has_kiosk,
          ke.employee_code AS kiosk_employee_code
        FROM hr_staff s
        LEFT JOIN kiosk_employee_departments d ON d.id = s.department_id
        LEFT JOIN (
          SELECT hr_staff_id, MAX(id) AS id, MAX(employee_code) AS employee_code
          FROM kiosk_employees
          WHERE hr_staff_id IS NOT NULL AND archived_at IS NULL
          GROUP BY hr_staff_id
        ) ke ON ke.hr_staff_id = s.id
        " . ($where ? ('WHERE ' . implode(' AND ', $where)) : '') . "
        ORDER BY " . $orderBy . "
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">

        <main class="flex-1 min-w-0">
          <header class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
              <div>
                <h1 class="text-2xl font-semibold">Staff</h1>
                <p class="mt-1 text-sm text-slate-600">HR staff profiles (the authoritative staff record). Kiosk access is enabled separately.</p>
              </div>
              <?php if (admin_can($user, 'manage_staff')): ?>
                <div class="flex flex-wrap gap-2">
                  <a href="<?php echo h(admin_url('staff-new.php')); ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-500/15 border border-emerald-500/30 text-slate-900 hover:bg-emerald-500/20">Add staff</a>
                </div>
              <?php endif; ?>
            </div>

            <form class="mt-4 flex flex-col lg:flex-row gap-3" method="get" action="">
              <div class="flex gap-2 flex-wrap">
                <select name="status" class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <?php
                    $opts = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'all' => 'All'];
                    foreach ($opts as $k => $lbl) {
                      $sel = ($status === $k) ? ' selected' : '';
                      echo '<option value="' . h($k) . '"' . $sel . '>' . h($lbl) . '</option>';
                    }
                  ?>
                </select>

                <select name="department_id" class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="0">All departments</option>
                  <?php foreach ($depts as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>"<?php echo ($dept === (int)$d['id']) ? ' selected' : ''; ?>>
                      <?php echo h2((string)$d['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="flex-1">
                <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search name, email, phone…" class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" />
              </div>

              <div class="flex gap-2">
                <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" type="submit">Filter</button>
                <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('staff.php')); ?>">Reset</a>
              </div>
            </form>
          </header>

          <div class="mt-4 rounded-3xl border border-slate-200 bg-white overflow-hidden">
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                  <tr>
                    <th class="text-left font-semibold px-4 py-3"><?= sc_sort_link_staff('name','Name',$sort,$dir) ?></th>
                    <th class="text-left font-semibold px-4 py-3"><?= sc_sort_link_staff('department','Department',$sort,$dir) ?></th>
                    <th class="text-left font-semibold px-4 py-3"><?= sc_sort_link_staff('status','Status',$sort,$dir) ?></th>
                    <th class="text-left font-semibold px-4 py-3"><?= sc_sort_link_staff('kiosk','Kiosk',$sort,$dir) ?></th>
                    <th class="text-right font-semibold px-4 py-3">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                  <tr><td class="px-4 py-6 text-slate-600" colspan="5">No staff found.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <?php
                      $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                      if ($name === '') $name = 'Staff #' . (int)$r['id'];
                      $deptName = (string)($r['department_name'] ?? '—');
                      $st = (string)($r['status'] ?? 'active');
                      $hasKiosk = (int)($r['has_kiosk'] ?? 0) === 1;
                    ?>
                    <tr class="border-t border-slate-100">
                      <td class="px-4 py-3">
                        <a class="font-semibold text-slate-900 hover:underline" href="<?php echo h(admin_url('staff-view.php?id=' . (int)$r['id'])); ?>">
                          <?php echo h2($name); ?>
                        </a>
                        <?php if (!empty($r['email'])): ?>
                          <div class="text-xs text-slate-600"><?php echo h2((string)$r['email']); ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-slate-700"><?php echo h2($deptName); ?></td>
                      <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-semibold text-slate-700">
                          <?php echo h2(ucfirst($st)); ?>
                        </span>
                      </td>
                      <td class="px-4 py-3">
                        <?php if ($hasKiosk): ?>
                          <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-900">Enabled</span>
                        <?php else: ?>
                          <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700">Not enabled</span>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                          <a class="rounded-2xl px-3 py-1.5 text-xs font-semibold bg-slate-900 text-white hover:bg-slate-800" href="<?php echo h(admin_url('staff-view.php?id=' . (int)$r['id'])); ?>">View</a>
                          <a class="rounded-2xl px-3 py-1.5 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('staff-edit.php?id=' . (int)$r['id'])); ?>">Edit</a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </main>
</div>
<?php admin_page_end(); ?>
