<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_employees');

$showContract = admin_can($user, 'view_contract');

admin_page_start($pdo, 'Employees');
$active = admin_url('employees.php');

$status = (string)($_GET['status'] ?? 'active'); // active|inactive|all
$cat = (int)($_GET['cat'] ?? 0);
$agency = (string)($_GET['agency'] ?? 'all'); // all|agency|staff

$cats = $pdo->query("SELECT id, name FROM kiosk_employee_departments ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$where = [];
$params = [];

if ($status === 'active') {
  $where[] = 'e.is_active = 1';
} elseif ($status === 'inactive') {
  $where[] = 'e.is_active = 0';
}

if ($cat > 0) {
  $where[] = 'e.department_id = ?';
  $params[] = $cat;
}

if ($agency === 'agency') {
  $where[] = 'e.is_agency = 1';
} elseif ($agency === 'staff') {
  $where[] = 'e.is_agency = 0';
}

// Search removed by design (filters only).

$sql = "SELECT e.*, c.name AS department_name, t.name AS team_name,
               p.contract_hours_per_week, p.break_is_paid, p.rules_json
        FROM kiosk_employees e
        LEFT JOIN kiosk_employee_departments c ON c.id = e.department_id
   LEFT JOIN kiosk_employee_teams t ON t.id = e.team_id
        LEFT JOIN kiosk_employee_pay_profiles p ON p.employee_id = e.id";
if ($where) {
  $sql .= ' WHERE ' . implode(' AND ', $where);
}
 $sql .= " ORDER BY e.is_active DESC, c.sort_order ASC, c.name ASC, e.is_agency ASC, COALESCE(NULLIF(e.nickname,''), e.first_name, '') ASC, e.last_name ASC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-8">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Employees</h1>
                <p class="mt-2 text-sm text-slate-600">Manage staff + agency profiles. Payroll rules are stored per employee.</p>
              </div>
              <?php if (admin_can($user, 'manage_employees')): ?>
                <div class="flex flex-wrap gap-2">
                  <a href="<?= h(admin_url('departments.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Department</a>
                  <a href="<?= h(admin_url('employee-edit.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-500/15 border border-emerald-500/30 text-slate-900 hover:bg-emerald-500/20">Add employee</a>
                  <a href="<?= h(admin_url('employee-edit.php')) ?>?agency=1" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-sky-500/15 border border-sky-500/30 text-black-100 hover:bg-sky-500/20">Add agency</a>
                </div>
              <?php endif; ?>
            </div>

            <form method="get" id="filters" class="mt-4 grid grid-cols-1 md:grid-cols-12 gap-3">
              <div class="md:col-span-5">
                <label class="block text-xs font-semibold text-slate-600">Department</label>
                <select name="cat" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="0">All</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $cat) ? 'selected' : '' ?>><?= h((string)$c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600">Status</label>
                <select name="status" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
                  <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
                  <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
                </select>
              </div>

              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600">Type</label>
                <select name="agency" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="all" <?= $agency==='all'?'selected':'' ?>>All</option>
                  <option value="staff" <?= $agency==='staff'?'selected':'' ?>>Staff</option>
                  <option value="agency" <?= $agency==='agency'?'selected':'' ?>>Agency</option>
                </select>
              </div>

              <div class="md:col-span-1 flex items-end">
                <a href="<?= h(admin_url('employees.php')) ?>" class="w-full text-center rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Clear</a>
              </div>
            </form>
          </header>

          <div class="mt-5 rounded-3xl border border-slate-200 bg-white overflow-hidden">
            <div class="p-4 flex items-center justify-between">
              <div class="text-sm text-slate-600"><span class="font-semibold text-slate-900"><?= count($rows) ?></span> results</div>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full text-sm border border-slate-200 border-collapse">
                <thead class="bg-slate-50 text-slate-600">
                  <tr>
                    <th class="text-left font-semibold px-4 py-3">Name</th>
                    <th class="text-left font-semibold px-4 py-3">Emp ID</th>
                    <th class="text-left font-semibold px-4 py-3">Type</th>
                    <th class="text-left font-semibold px-4 py-3">Department</th>
                    <?php if ($showContract): ?>
                      <th class="text-left font-semibold px-4 py-3">Contract</th>
                      <th class="text-left font-semibold px-4 py-3">Break</th>
                      <th class="text-left font-semibold px-4 py-3">Multipliers</th>
                    <?php endif; ?>
                    <th class="text-left font-semibold px-4 py-3">Status</th>
                    <th class="text-right font-semibold px-4 py-3">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                  <?php foreach ($rows as $r):
                    $name = trim(((string)$r['first_name'] . ' ' . (string)$r['last_name']));
                    $nick = trim((string)($r['nickname'] ?? ''));
                    if ($nick !== '') $name .= ' (' . $nick . ')';
                    if ((int)$r['is_agency'] === 1) {
                      $name = trim((string)($r['agency_label'] ?? 'Agency'));
                    }
                    $type = ((int)$r['is_agency'] === 1) ? 'Agency' : 'Staff';
                    $contract = $r['contract_hours_per_week'] !== null && $r['contract_hours_per_week'] !== '' ? ((string)$r['contract_hours_per_week'] . ' hrs/wk') : '—';

                    $breakPaid = ((int)($r['break_is_paid'] ?? 0) === 1);

                    $rules = [];
                    if (!empty($r['rules_json'])) {
                      $decoded = json_decode((string)$r['rules_json'], true);
                      if (is_array($decoded)) $rules = $decoded;
                    }
                    $fmtMult = function($v): string {
                      if ($v === null || $v === '' || (is_numeric($v) && (float)$v <= 0)) return '—';
                      $f = (float)$v;
                      $s = rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
                      return $s === '' ? '—' : $s;
                    };
                    $m_bh = $fmtMult($rules['bank_holiday_multiplier'] ?? null);
                    $m_we = $fmtMult($rules['weekend_multiplier'] ?? null);
                    $m_ng = $fmtMult($rules['night_multiplier'] ?? null);
                    $m_ot = $fmtMult($rules['overtime_multiplier'] ?? null);
                    $m_co = $fmtMult($rules['callout_multiplier'] ?? null);
                  ?>
                    <tr>
                      <td class="px-4 py-3">
                        <a href="<?= h(admin_url('employee-edit.php')) ?>?id=<?= (int)$r['id'] ?>" class="font-semibold text-slate-900 hover:underline"><?= h($name) ?></a>
                      </td>
                      <td class="px-4 py-3 text-slate-700 whitespace-nowrap">
                        <?= $r['employee_code'] ? h((string)$r['employee_code']) : '—' ?>
                      </td>
                      <td class="px-4 py-3"><span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold <?= ((int)$r['is_agency']===1) ? 'bg-sky-500/15 border border-sky-500/30 text-black-100' : 'bg-emerald-500/10 border border-emerald-500/30 text-slate-900' ?>"><?= h($type) ?></span></td>
                      <td class="px-4 py-3 text-slate-700"><?= h((string)($r['department_name'] ?? '—')) ?></td>
                      <?php if ($showContract): ?>
                        <td class="px-4 py-3 text-slate-700"><?= h($contract) ?></td>
                        <td class="px-4 py-3">
                          <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold <?= $breakPaid ? 'bg-emerald-500/10 border border-emerald-500/30 text-slate-900' : 'bg-white border border-slate-200 text-slate-600' ?>">
                            <?= $breakPaid ? 'Paid' : 'Unpaid' ?>
                          </span>
                        </td>
                        <td class="px-4 py-3">
                          <div class="flex flex-wrap gap-1">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold bg-white border border-slate-200 text-slate-700">BH <?= h($m_bh) ?></span>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold bg-white border border-slate-200 text-slate-700">WE <?= h($m_we) ?></span>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold bg-white border border-slate-200 text-slate-700">N <?= h($m_ng) ?></span>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold bg-white border border-slate-200 text-slate-700">OT <?= h($m_ot) ?></span>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold bg-white border border-slate-200 text-slate-700">CO <?= h($m_co) ?></span>
                          </div>
                        </td>
                      <?php endif; ?>
                      <td class="px-4 py-3">
                        <?php if ((int)$r['is_active']===1): ?>
                          <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-white border border-slate-200 text-slate-600">Active</span>
                        <?php else: ?>
                          <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-slate-900">Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-right">
                        <div class="inline-flex items-center gap-2">
                          <?php if (admin_can($user, 'manage_employees')): ?>
                            <a href="<?= h(admin_url('employee-edit.php')) ?>?id=<?= (int)$r['id'] ?>" class="rounded-2xl px-3 py-2 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Edit</a>
                          <?php endif; ?>
                          <?php if (admin_can($user, 'view_contract')): ?>
                            <a href="<?= h(admin_url('employee-contract.php')) ?>?id=<?= (int)$r['id'] ?>" class="rounded-2xl px-3 py-2 text-xs font-semibold bg-sky-500/10 border border-sky-500/30 text-black-100 hover:bg-sky-500/20">Contract</a>
                          <?php endif; ?>
                          <?php if (!admin_can($user, 'manage_employees') && !admin_can($user, 'view_contract')): ?>
                            <span class="text-xs text-slate-400">—</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <?php if (!$rows): ?>
                    <tr>
                      <td colspan="<?= $showContract ? 9 : 6 ?>" class="px-4 py-8 text-center text-slate-500">No employees found.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <script>
            (function(){
              var form = document.getElementById('filters');
              if (!form) return;
              var selects = form.querySelectorAll('select');
              selects.forEach(function(s){ s.addEventListener('change', function(){ form.submit(); }); });
            })();
          </script>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>