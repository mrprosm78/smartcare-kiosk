<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_employees');

$showContract = admin_can($user, 'view_contract');

admin_page_start($pdo, 'Kiosk IDs');
$active = admin_url('kiosk-ids.php');

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
<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">

        <main class="flex-1 min-w-0">
          <header class="rounded-3xl border border-slate-200 bg-white p-4">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
              <div>
                <h1 class="text-2xl font-semibold">Kiosk IDs</h1>
                <p class="mt-1 text-sm text-slate-600">Click a kiosk identity to edit on the right. No page reload.</p>
              </div>
              <?php if (admin_can($user, 'manage_employees')): ?>
                <div class="flex flex-wrap gap-2">
                  <button type="button" id="btnAddStaff" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-500/15 border border-emerald-500/30 text-slate-900 hover:bg-emerald-500/20">Add kiosk ID</button>
                  <button type="button" id="btnAddAgency" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-sky-500/15 border border-sky-500/30 text-slate-900 hover:bg-sky-500/20">Add agency ID</button>
                </div>
              <?php endif; ?>
            </div>

            <form method="get" id="filters" class="mt-3 grid grid-cols-1 md:grid-cols-12 gap-3">
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

          <div class="mt-5 grid grid-cols-1 xl:grid-cols-12 gap-5">
            <!-- List -->
            <section class="xl:col-span-7 min-w-0">
              <div class="rounded-3xl border border-slate-200 bg-white overflow-hidden">
                <div class="p-3 flex items-center justify-between">
                  <div class="text-sm text-slate-600"><span class="font-semibold text-slate-900"><?= count($rows) ?></span> results</div>
                  <div class="text-xs text-slate-500">Tip: click a row to edit</div>
                </div>

                <div class="overflow-x-auto">
                  <table class="min-w-full text-sm border border-slate-200 border-collapse">
                    <thead class="bg-slate-50 text-slate-600">
                      <tr>
                        <th class="text-left font-semibold px-3 py-2">Name</th>
                        <th class="text-left font-semibold px-3 py-2">Emp ID</th>
                        <th class="text-left font-semibold px-3 py-2">Type</th>
                        <th class="text-left font-semibold px-3 py-2">Department</th>
                        <th class="text-left font-semibold px-3 py-2">Status</th>
                        <th class="text-right font-semibold px-3 py-2">Action</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200" id="empTbody">
                      <?php foreach ($rows as $r):
                        $displayName = trim(((string)$r['first_name'] . ' ' . (string)$r['last_name']));
                        $nick = trim((string)($r['nickname'] ?? ''));
                        if ($nick !== '') $displayName = trim($nick);
                        if ((int)$r['is_agency'] === 1) {
                          $displayName = trim((string)($r['agency_label'] ?? 'Agency'));
                        }
                        $type = ((int)$r['is_agency'] === 1) ? 'Agency' : 'Staff';
                        $deptName = (string)($r['department_name'] ?? '—');
                        $isActive = (int)($r['is_active'] ?? 0) === 1;
                      ?>
                      <tr data-emp-id="<?= (int)$r['id'] ?>" class="hover:bg-slate-50 cursor-pointer">
                        <td class="px-3 py-2 font-semibold text-slate-900 emp-name"><?= h($displayName) ?></td>
                        <td class="px-3 py-2 text-slate-700 emp-code"><?= h((string)($r['employee_code'] ?? '')) ?></td>
                        <td class="px-3 py-2 text-slate-700 emp-type"><?= h($type) ?></td>
                        <td class="px-3 py-2 text-slate-700 emp-dept"><?= h($deptName !== '' ? $deptName : '—') ?></td>
                        <td class="px-3 py-2 text-slate-700 emp-status">
                          <?php if ($isActive): ?>
                            <span class="inline-flex items-center rounded-full bg-emerald-500/10 text-emerald-700 px-2 py-0.5 text-xs font-semibold border border-emerald-500/20">Active</span>
                          <?php else: ?>
                            <span class="inline-flex items-center rounded-full bg-slate-500/10 text-slate-700 px-2 py-0.5 text-xs font-semibold border border-slate-500/20">Inactive</span>
                          <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-right">
                          <button type="button" class="rounded-xl px-3 py-1.5 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 btn-edit" data-emp-id="<?= (int)$r['id'] ?>">Edit</button>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </section>

            <!-- Side panel (always visible) -->
            <aside class="xl:col-span-5">
              <div class="rounded-3xl border border-slate-200 bg-white p-4 sticky top-5">
                <div class="flex items-center justify-between">
                  <div>
                    <div class="text-xs font-semibold text-slate-600">Edit employee</div>
                    <div class="text-lg font-semibold text-slate-900" id="panelTitle">Select an employee</div>
                  </div>
                  <div class="text-xs text-slate-500" id="panelState">—</div>
                </div>

                <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 hidden" id="panelError"></div>

                <form id="empForm" class="mt-4 space-y-3">
                  <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
                  <input type="hidden" name="id" id="f_id" value="0">

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                      <label class="block text-xs font-semibold text-slate-600">Nickname (display name)</label>
                      <input name="nickname" id="f_nickname" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Required for staff">
                    </div>

                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Employee code</label>
                      <input name="employee_code" id="f_employee_code" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                    </div>

                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Department</label>
                      <select name="department_id" id="f_department_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                        <option value="0">—</option>
                        <?php foreach ($cats as $c): ?>
                          <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div>
                      <label class="block text-xs font-semibold text-slate-600">Type</label>
                      <select name="is_agency" id="f_is_agency" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                        <option value="0">Staff</option>
                        <option value="1">Agency</option>
                      </select>
                    </div>

                    <div id="agencyLabelWrap" class="hidden">
                      <label class="block text-xs font-semibold text-slate-600">Agency label</label>
                      <input name="agency_label" id="f_agency_label" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Agency">
                    </div>

                    <div class="sm:col-span-2">
                      <label class="block text-xs font-semibold text-slate-600">Reset PIN (optional)</label>
                      <input name="pin" id="f_pin" inputmode="numeric" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Leave blank to keep existing">
                      <div class="mt-1 text-xs text-slate-500">PIN must be 4–10 digits and unique.</div>
                    </div>

                    <div class="sm:col-span-2 flex items-center justify-between pt-1">
                      <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" id="f_is_active" name="is_active" value="1" class="rounded border-slate-300">
                        Active
                      </label>

                      <div class="flex gap-2">
                        <button type="button" id="btnNew" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">New</button>
                        <button type="submit" id="btnSave" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60">Save</button>
                      </div>
                    </div>
                  </div>
                </form>

                <div class="mt-4 border-t border-slate-200 pt-3">
                  <div class="text-xs font-semibold text-slate-600">Contract</div>
                  <?php if (admin_can($user, 'edit_contract')): ?>
                    <a id="contractLink" href="#" class="mt-2 inline-flex rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Open contract</a>
                  <?php else: ?>
                    <div class="mt-2 text-sm text-slate-500">Super admin only.</div>
                  <?php endif; ?>
                </div>
              </div>
            </aside>
          </div>
        </main>
</div>

<script>
(function(){
  const tbody = document.getElementById('empTbody');
  const panelTitle = document.getElementById('panelTitle');
  const panelState = document.getElementById('panelState');
  const panelError = document.getElementById('panelError');
  const form = document.getElementById('empForm');
  const btnSave = document.getElementById('btnSave');
  const btnNew = document.getElementById('btnNew');
  const btnAddStaff = document.getElementById('btnAddStaff');
  const btnAddAgency = document.getElementById('btnAddAgency');
  const contractLink = document.getElementById('contractLink');


  // Auto-apply filters (no Apply button)
  const filters = document.getElementById('filters');
  if (filters) {
    filters.querySelectorAll('select').forEach(sel => {
      sel.addEventListener('change', () => {
        // If the edit form is dirty, warn to prevent accidental loss.
        const dirty = form && form.dataset && form.dataset.dirty === '1';
        if (dirty && !confirm('You have unsaved changes. Apply filter and lose them?')) return;
        filters.submit();
      });
    });
  }
  const f_id = document.getElementById('f_id');
  const f_nickname = document.getElementById('f_nickname');
  const f_employee_code = document.getElementById('f_employee_code');
  const f_department_id = document.getElementById('f_department_id');
  const f_is_agency = document.getElementById('f_is_agency');
  const agencyLabelWrap = document.getElementById('agencyLabelWrap');
  const f_agency_label = document.getElementById('f_agency_label');
  const f_pin = document.getElementById('f_pin');
  const f_is_active = document.getElementById('f_is_active');

  let selectedId = 0;

  function showError(msg){
    if (!msg) { panelError.classList.add('hidden'); panelError.textContent=''; return; }
    panelError.textContent = msg;
    panelError.classList.remove('hidden');
  }

  function setLoading(on, label){
    panelState.textContent = on ? (label || 'Loading…') : '—';
    btnSave.disabled = on;
  }

  function toggleAgency(){
    const isAgency = String(f_is_agency.value) === '1';
    agencyLabelWrap.classList.toggle('hidden', !isAgency);
    if (isAgency && !f_agency_label.value.trim()) f_agency_label.value = 'Agency';
  }

  async function loadEmployee(id, agencyPreset){
    showError('');
    setLoading(true, 'Loading…');
    const url = new URL('<?= h(admin_url('ajax/employee.php')) ?>', window.location.origin);
    url.searchParams.set('id', String(id));
    if (agencyPreset) url.searchParams.set('agency','1');

    const res = await fetch(url.toString(), {headers:{'Accept':'application/json'}});
    const data = await res.json().catch(()=>({ok:false,error:'Invalid response'}));
    if (!res.ok || !data.ok) {
      showError(data.error || ('Failed to load (HTTP ' + res.status + ')'));
      setLoading(false);
      return;
    }

    const e = data.employee || {};
    selectedId = parseInt(e.id || 0, 10) || 0;

    f_id.value = String(selectedId);
    f_nickname.value = e.nickname || '';
    f_employee_code.value = e.employee_code || '';
    f_department_id.value = String(e.department_id || 0);
    f_is_agency.value = String(e.is_agency || 0);
    f_agency_label.value = e.agency_label || '';
    f_pin.value = '';
    f_is_active.checked = String(e.is_active || 0) === '1' || e.is_active === 1;

    toggleAgency();

    panelTitle.textContent = selectedId > 0 ? ('#' + selectedId + ' · ' + (e.nickname || e.agency_label || 'Employee')) : (agencyPreset ? 'New agency' : 'New employee');

    if (contractLink) {
      if (selectedId > 0) {
        contractLink.href = '<?= h(admin_url('employee-contract.php')) ?>?id=' + encodeURIComponent(String(selectedId));
        contractLink.classList.remove('opacity-50','pointer-events-none');
      } else {
        contractLink.href = '#';
        contractLink.classList.add('opacity-50','pointer-events-none');
      }
    }

    // highlight row
    document.querySelectorAll('tr[data-emp-id]').forEach(tr=>{
      tr.classList.toggle('bg-slate-50', tr.getAttribute('data-emp-id') === String(selectedId));
    });

    setLoading(false);
  }

  function updateRowFromSaved(emp){
    if (!emp || !emp.id) return;

    const tr = document.querySelector('tr[data-emp-id="'+ emp.id +'"]');
    const isAgency = String(emp.is_agency) === '1' || emp.is_agency === 1;
    const name = isAgency ? (emp.agency_label || 'Agency') : (emp.nickname || '(no nickname)');
    const type = isAgency ? 'Agency' : 'Staff';
    const dept = emp.department_name || '—';
    const code = emp.employee_code || '';
    const isActive = String(emp.is_active) === '1' || emp.is_active === 1;

    if (tr) {
      tr.querySelector('.emp-name').textContent = name;
      tr.querySelector('.emp-code').textContent = code;
      tr.querySelector('.emp-type').textContent = type;
      tr.querySelector('.emp-dept').textContent = dept;
      tr.querySelector('.emp-status').innerHTML = isActive
        ? '<span class="inline-flex items-center rounded-full bg-emerald-500/10 text-emerald-700 px-2 py-0.5 text-xs font-semibold border border-emerald-500/20">Active</span>'
        : '<span class="inline-flex items-center rounded-full bg-slate-500/10 text-slate-700 px-2 py-0.5 text-xs font-semibold border border-slate-500/20">Inactive</span>';
      tr.classList.add('bg-slate-50');
    } else {
      // new row: insert at top
      const tbody = document.getElementById('empTbody');
      const row = document.createElement('tr');
      row.setAttribute('data-emp-id', emp.id);
      row.className = 'hover:bg-slate-50 cursor-pointer bg-slate-50';
      row.innerHTML = `
        <td class="px-3 py-2 font-semibold text-slate-900 emp-name"></td>
        <td class="px-3 py-2 text-slate-700 emp-code"></td>
        <td class="px-3 py-2 text-slate-700 emp-type"></td>
        <td class="px-3 py-2 text-slate-700 emp-dept"></td>
        <td class="px-3 py-2 text-slate-700 emp-status"></td>
        <td class="px-3 py-2 text-right">
          <button type="button" class="rounded-xl px-3 py-1.5 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 btn-edit" data-emp-id="${emp.id}">Edit</button>
        </td>
      `;
      tbody.prepend(row);
      updateRowFromSaved(emp);
    }
  }

  tbody && tbody.addEventListener('click', (e)=>{
    const btn = e.target.closest('.btn-edit');
    const tr = e.target.closest('tr[data-emp-id]');
    const id = btn ? btn.getAttribute('data-emp-id') : (tr ? tr.getAttribute('data-emp-id') : null);
    if (!id) return;
    loadEmployee(parseInt(id,10)||0, false);
  });

  btnNew && btnNew.addEventListener('click', ()=> loadEmployee(0,false));
  btnAddStaff && btnAddStaff.addEventListener('click', ()=> loadEmployee(0,false));
  btnAddAgency && btnAddAgency.addEventListener('click', ()=> loadEmployee(0,true));

  f_is_agency && f_is_agency.addEventListener('change', toggleAgency);

  form && form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    showError('');
    setLoading(true,'Saving…');

    const url = '<?= h(admin_url('ajax/employee.php')) ?>';
    const fd = new FormData(form);
    // checkbox handling
    if (f_is_active.checked) fd.set('is_active','1'); else fd.set('is_active','0');

    const res = await fetch(url, {method:'POST', body: fd, headers:{'Accept':'application/json'}});
    const data = await res.json().catch(()=>({ok:false,error:'Invalid response'}));
    if (!res.ok || !data.ok) {
      showError(data.error || ('Failed to save (HTTP ' + res.status + ')'));
      setLoading(false);
      return;
    }
    const emp = data.employee || {};
    // If new: load selected id
    if (emp && emp.id) {
      await loadEmployee(parseInt(emp.id,10)||0, false);
      updateRowFromSaved(emp);
    }
    panelState.textContent = 'Saved';
    setTimeout(()=>{ panelState.textContent = '—'; }, 1200);
    setLoading(false);
  });

  // auto-select first row
  const first = document.querySelector('tr[data-emp-id]');
  if (first) {
    loadEmployee(parseInt(first.getAttribute('data-emp-id'),10)||0, false);
  } else {
    loadEmployee(0,false);
  }
})();
</script>


<?php admin_page_end(); ?>
