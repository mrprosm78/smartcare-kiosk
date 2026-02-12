<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$active = admin_url('hr-staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$status = (string)($_GET['status'] ?? 'active'); // active|inactive|archived|all
$dept   = (int)($_GET['department_id'] ?? 0);
$q      = trim((string)($_GET['q'] ?? ''));

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
  $href = admin_url('hr-staff.php' . ($qs ? ('?' . $qs) : ''));
  return '<a class="hover:text-slate-900" href="' . h($href) . '">' . h($label) . '</a><span class="text-[11px] text-slate-500">' . h($arrow) . '</span>';
}

$depts = $pdo->query("SELECT id, name FROM kiosk_employee_departments ORDER BY sort_order ASC, name ASC")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        ORDER BY {$orderBy}
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

admin_page_start($pdo, 'Staff');
?>

<div class="min-h-dvh flex">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 p-8">
    <div class="space-y-4">

      <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h1 class="text-2xl font-semibold">Staff</h1>
            <p class="mt-1 text-sm text-slate-600">HR staff profiles (the authoritative staff record). Kiosk access is enabled separately.</p>
          </div>
        </div>

        <form method="get" data-filters="hr-staff" class="mt-4 w-full flex items-end gap-3">
          <label class="block flex-1 min-w-[160px]">
            <span class="text-xs font-semibold text-slate-600">Status</span>
            <select data-auto-submit="1" name="status" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
              <?php
                $opts = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'all' => 'All'];
                foreach ($opts as $k => $lbl) {
                  $sel = ($status === $k) ? ' selected' : '';
                  echo '<option value="' . h($k) . '"' . $sel . '>' . h($lbl) . '</option>';
                }
              ?>
            </select>
          </label>

          <label class="block flex-1 min-w-[200px]">
            <span class="text-xs font-semibold text-slate-600">Department</span>
            <select data-auto-submit="1" name="department_id" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
              <option value="0">All departments</option>
              <?php foreach ($depts as $d): ?>
                <option value="<?php echo (int)$d['id']; ?>"<?php echo ($dept === (int)$d['id']) ? ' selected' : ''; ?>>
                  <?php echo h2((string)$d['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="block shrink-0 w-72 md:w-96">
            <span class="text-xs font-semibold text-slate-600">Search</span>
            <input name="q" value="<?php echo h($q); ?>" placeholder="Name, email, phone…"
                   class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
          </label>

          <div class="flex items-end gap-2">
            <a href="<?php echo h(admin_url('hr-staff.php')); ?>"
               class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">
              Clear
            </a>
          </div>
        </form>
      </div>

      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-3 py-2 text-left">Staff ID</th>
              <th class="px-3 py-2 text-left"><?= sc_sort_link_staff('name','Name',$sort,$dir) ?></th>
              <th class="px-3 py-2 text-left"><?= sc_sort_link_staff('department','Department',$sort,$dir) ?></th>
              <th class="px-3 py-2 text-left"><?= sc_sort_link_staff('status','Status',$sort,$dir) ?></th>
              <th class="px-3 py-2 text-left"><?= sc_sort_link_staff('kiosk','Kiosk',$sort,$dir) ?></th>
              <th class="px-3 py-2 text-right">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td class="px-3 py-6 text-slate-600" colspan="6">No staff found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                if ($name === '') $name = 'Staff #' . (int)$r['id'];
                $staffCode = trim((string)($r['staff_code'] ?? ''));
                if ($staffCode === '') $staffCode = (string)(int)$r['id'];
                $deptName = (string)($r['department_name'] ?? '—');
                $st = (string)($r['status'] ?? 'active');
                $hasKiosk = (int)($r['has_kiosk'] ?? 0) === 1;
              ?>
              <tr class="border-t border-slate-100">
                <td class="px-3 py-3 text-slate-700 font-semibold"><?php echo h2($staffCode); ?></td>
                <td class="px-3 py-3">
                  <a class="font-semibold text-slate-900 hover:underline" href="<?php echo h(admin_url('hr-staff-view.php?id=' . (int)$r['id'])); ?>">
                    <?php echo h2($name); ?>
                  </a>
                  <?php if (!empty($r['email'])): ?>
                    <div class="text-xs text-slate-600"><?php echo h2((string)$r['email']); ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-3 text-slate-700"><?php echo h2($deptName); ?></td>
                <td class="px-3 py-3">
                  <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-semibold text-slate-700">
                    <?php echo h2(ucfirst($st)); ?>
                  </span>
                </td>
                <td class="px-3 py-3">
                  <?php if ($hasKiosk): ?>
                    <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-900">Enabled</span>
                  <?php else: ?>
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700">Not enabled</span>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-3 text-right">
                  <a class="rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800" href="<?php echo h(admin_url('hr-staff-view.php?id=' . (int)$r['id'])); ?>">View</a>
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

<script>
(function(){
  const form = document.querySelector('form[data-filters="hr-staff"]');
  if (!form) return;
  form.querySelectorAll('select[data-auto-submit="1"]').forEach(sel => {
    sel.addEventListener('change', () => form.submit());
  });
})();
</script>

<?php admin_page_end(); ?>
