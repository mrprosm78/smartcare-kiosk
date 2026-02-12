<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_dashboard');

$active = admin_url('rota.php');

function qstr(string $k, string $default=''): string {
  $v = $_GET[$k] ?? $default;
  return is_string($v) ? trim($v) : $default;
}
function is_ymd(string $s): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

$tz = new DateTimeZone(payroll_timezone($pdo));

// ----------------------
// Week navigation
// ----------------------
$weekParam = qstr('week_start', '');
$anyLocal = null;
if ($weekParam !== '' && is_ymd($weekParam)) {
  try { $anyLocal = new DateTimeImmutable($weekParam . ' 12:00:00', $tz); } catch (Throwable $e) { $anyLocal = null; }
}
if (!$anyLocal) $anyLocal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($tz);

$window = payroll_week_window($pdo, $anyLocal);
$weekStartLocal = $window['start_local'];
$weekEndLocalEx = $window['end_local_ex'];
$weekStartsOn = $window['week_starts_on'];

$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = $weekStartLocal->modify('+' . $i . ' days');
  $days[] = [
    'local' => $d,
    'ymd' => $d->format('Y-m-d'),
    'dow' => $d->format('D'),
    'label' => $d->format('d M'),
  ];
}

$rangeLabel = $weekStartLocal->format('D d M Y') . ' — ' . $weekEndLocalEx->modify('-1 day')->format('D d M Y');
$prevWeek = $weekStartLocal->modify('-7 days')->format('Y-m-d');
$nextWeek = $weekStartLocal->modify('+7 days')->format('Y-m-d');

// ----------------------
// Filters
// ----------------------
$dept = (int)($_GET['dept'] ?? 0);
$q = qstr('q', '');

// ----------------------
// Departments
// ----------------------
$depts = $pdo->query("SELECT id, name FROM kiosk_employee_departments WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ----------------------
// Employees (HR staff)
// NOTE: setup.php defines hr_staff.status enum (active/inactive/archived).
// For now we show active only to avoid guessing different rules.
// ----------------------
$where = ["s.status = 'active'"];
$params = [];

if ($dept > 0) { $where[] = 's.department_id = ?'; $params[] = $dept; }

if ($q !== '') {
  $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.staff_code LIKE ?)';
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

$sql = "
  SELECT
    s.id,
    s.staff_code,
    s.first_name,
    s.last_name,
    s.department_id,
    s.status,
    d.name AS department_name
  FROM hr_staff s
  LEFT JOIN kiosk_employee_departments d ON d.id = s.department_id
  " . ($where ? ('WHERE ' . implode(' AND ', $where)) : '') . "
  ORDER BY d.sort_order ASC, d.name ASC, s.last_name ASC, s.first_name ASC
  LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

admin_page_start($pdo, 'Rota');
?>
<div class="min-h-dvh flex">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 p-8">
    <div class="space-y-4">

      <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4">

          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Rota</h1>
              <p class="mt-1 text-sm text-slate-600">
                Weekly planner (anchored to <span class="font-semibold"><?= h($weekStartsOn) ?></span>, timezone <span class="font-semibold"><?= h(payroll_timezone($pdo)) ?></span>).
                Click a shift template, then click a cell to assign. Click a shift pill to remove.
              </p>
            </div>

            <div class="flex items-center gap-2">
              <a href="<?= h(admin_url('rota.php')) ?>"
                 class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                 This week
              </a>
            </div>
          </div>

          <!-- Filters + week nav -->
          <form method="get" class="grid grid-cols-1 lg:grid-cols-12 gap-3">
            <div class="lg:col-span-4">
              <div class="text-xs font-semibold text-slate-600">Department</div>
              <select name="dept"
                class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                <option value="0">All departments</option>
                <?php foreach ($depts as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" <?= $dept===(int)$d['id'] ? 'selected' : '' ?>><?= h((string)$d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="lg:col-span-6">
              <div class="text-xs font-semibold text-slate-600">Search staff</div>
              <input type="text" name="q" value="<?= h($q) ?>" placeholder="Name or staff code…"
                class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200" />
            </div>

            <div class="lg:col-span-2 flex items-end">
              <button class="w-full inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                Apply
              </button>
            </div>

            <input type="hidden" name="week_start" value="<?= h($weekStartLocal->format('Y-m-d')) ?>">
          </form>

          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="text-sm font-semibold text-slate-900"><?= h($rangeLabel) ?></div>

            <div class="flex items-center gap-2">
              <a href="<?= h(admin_url('rota.php') . '?' . http_build_query(array_merge($_GET, ['week_start'=>$prevWeek]))) ?>"
                 class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                ← Prev
              </a>

              <a href="<?= h(admin_url('rota.php') . '?' . http_build_query(array_merge($_GET, ['week_start'=>$nextWeek]))) ?>"
                 class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Next →
              </a>
            </div>
          </div>

        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-12 gap-4">

        <!-- Shift templates -->
        <section class="xl:col-span-3">
          <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-2">
              <div>
                <div class="text-sm font-semibold text-slate-900">Shift templates</div>
                <div class="mt-1 text-xs text-slate-600">Click one, then click cells to assign.</div>
              </div>
              <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 border border-slate-200">
                Planned
              </span>
            </div>

            <div class="mt-4 space-y-2" id="shiftPalette"></div>

            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-700">
              <div class="font-semibold">Notes</div>
              <ul class="mt-2 list-disc pl-5 space-y-1">
                <li>This is UI-only (no saving yet).</li>
                <li>Current staff list uses <span class="font-semibold">hr_staff.status = active</span>.</li>
              </ul>
            </div>
          </div>

          <div class="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-sm font-semibold text-slate-900">Quick actions (UI)</div>
            <div class="mt-3 grid grid-cols-1 gap-2">
              <button type="button" id="btnClearWeek"
                class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Clear week (not saved)
              </button>
              <button type="button" id="btnDemoFill"
                class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                Demo fill (not saved)
              </button>
            </div>
          </div>
        </section>

        <!-- Rota grid -->
        <section class="xl:col-span-9">
          <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">

            <div class="p-5 border-b border-slate-200">
              <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-slate-900">Weekly rota</div>
                  <div class="mt-1 text-xs text-slate-600">
                    Showing <span class="font-semibold"><?= count($staff) ?></span> staff.
                    Assign hours will update the weekly totals + department summary below.
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                    Selected shift: <span id="selectedShiftLabel" class="font-semibold">None</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="overflow-auto">
              <table class="min-w-[1100px] w-full text-sm">
                <thead class="sticky top-0 bg-white z-10">
                  <tr class="border-b border-slate-200">
                    <th class="w-72 text-left px-4 py-3 bg-white">
                      <div class="text-xs font-semibold text-slate-600">Staff</div>
                    </th>
                    <?php foreach ($days as $d): ?>
                      <th class="min-w-36 text-left px-3 py-3 bg-white">
                        <div class="text-xs font-semibold text-slate-600"><?= h($d['dow']) ?></div>
                        <div class="text-sm font-semibold text-slate-900"><?= h($d['label']) ?></div>
                      </th>
                    <?php endforeach; ?>
                    <th class="w-28 text-left px-3 py-3 bg-white">
                      <div class="text-xs font-semibold text-slate-600">Total</div>
                      <div class="text-sm font-semibold text-slate-900">Hours</div>
                    </th>
                  </tr>
                </thead>

                <tbody id="rotaBody">
                  <?php if (!$staff): ?>
                    <tr>
                      <td colspan="9" class="px-4 py-6 text-sm text-slate-600">No staff found for these filters.</td>
                    </tr>
                  <?php endif; ?>

                  <?php foreach ($staff as $s): ?>
                    <?php
                      $empId = (int)$s['id'];
                      $full = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
                      if ($full === '') $full = 'Staff #' . $empId;
                      $deptName = (string)($s['department_name'] ?? '—');
                      $deptId = (int)($s['department_id'] ?? 0);
                    ?>
                    <tr class="border-t border-slate-100 hover:bg-slate-50/40" data-emp-row="<?= $empId ?>" data-dept-id="<?= $deptId ?>" data-dept-name="<?= h($deptName) ?>">
                      <td class="px-4 py-3 align-top">
                        <div class="flex items-start justify-between gap-2">
                          <div>
                            <div class="font-semibold text-slate-900"><?= h($full) ?></div>
                            <div class="mt-1 text-xs text-slate-600"><?= h($deptName) ?></div>
                            <?php if (!empty($s['staff_code'])): ?>
                              <div class="mt-1 text-[11px] text-slate-500"><?= h((string)$s['staff_code']) ?></div>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>

                      <?php foreach ($days as $d): ?>
                        <td class="px-3 py-3 align-top">
                          <div class="min-h-14 rounded-2xl border border-slate-200 bg-white p-2 hover:border-slate-300 cursor-pointer"
                               data-cell="1"
                               data-emp="<?= $empId ?>"
                               data-dept="<?= $deptId ?>"
                               data-date="<?= h($d['ymd']) ?>">
                            <div data-plus="1" class="text-xs text-slate-500">+</div>
                            <div class="mt-1 space-y-1" data-pill-wrap="1"></div>
                          </div>
                        </td>
                      <?php endforeach; ?>

                      <td class="px-3 py-3 align-top">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                          <div class="text-xs text-slate-600">Weekly</div>
                          <div class="mt-0.5 text-lg font-semibold text-slate-900" data-emp-total="<?= $empId ?>">0</div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

          </div>

          <!-- Department summary -->
          <div class="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-slate-900">Department summary</div>
                <div class="mt-1 text-xs text-slate-600">Assigned hours vs target (targets are UI-only for now).</div>
              </div>
              <div class="text-xs text-slate-600">Week: <span class="font-semibold"><?= h($weekStartLocal->format('Y-m-d')) ?></span></div>
            </div>

            <div class="mt-4 overflow-auto">
              <table class="min-w-[700px] w-full text-sm">
                <thead>
                  <tr class="border-b border-slate-200">
                    <th class="text-left px-3 py-2 text-xs font-semibold text-slate-600">Department</th>
                    <th class="text-left px-3 py-2 text-xs font-semibold text-slate-600">Assigned</th>
                    <th class="text-left px-3 py-2 text-xs font-semibold text-slate-600">Target</th>
                    <th class="text-left px-3 py-2 text-xs font-semibold text-slate-600">Diff</th>
                  </tr>
                </thead>
                <tbody id="deptSummary">
                  <tr>
                    <td class="px-3 py-4 text-sm text-slate-600" colspan="4">Assign shifts to see summary.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </section>
      </div>

    </div>
  </main>
</div>

<script>
(() => {
  // UI-only rota planner (no DB save yet)

  const shiftTemplates = [
    { id: 'D07_19', name: 'Day', start: '07:00', end: '19:00', code: '07–19', hours: 12.0 },
    { id: 'N19_07', name: 'Night', start: '19:00', end: '07:00', code: '19–07', hours: 12.0, overnight: true },
    { id: 'E08_20', name: 'Long Day', start: '08:00', end: '20:00', code: '08–20', hours: 12.0 },
    { id: 'S07_15', name: 'Early', start: '07:00', end: '15:00', code: '07–15', hours: 8.0 },
    { id: 'L13_21', name: 'Late', start: '13:00', end: '21:00', code: '13–21', hours: 8.0 },
  ];

  let selected = null;

  const $ = (sel, el=document) => el.querySelector(sel);
  const $$ = (sel, el=document) => Array.from(el.querySelectorAll(sel));

  const palette = $('#shiftPalette');
  const selectedLabel = $('#selectedShiftLabel');

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function renderPalette() {
    palette.innerHTML = '';
    shiftTemplates.forEach(t => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className =
        'w-full text-left rounded-2xl border px-4 py-3 hover:bg-slate-50 transition ' +
        (selected?.id === t.id ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-900');
      btn.innerHTML = `
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold">${escapeHtml(t.name)}</div>
            <div class="mt-1 text-xs ${selected?.id === t.id ? 'text-white/80' : 'text-slate-600'}">${escapeHtml(t.start)}–${escapeHtml(t.end)} • ${t.hours}h</div>
          </div>
          <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold border ${selected?.id === t.id ? 'border-white/30 bg-white/10 text-white' : 'border-slate-200 bg-slate-50 text-slate-700'}">${escapeHtml(t.code)}</span>
        </div>
      `;
      btn.addEventListener('click', () => {
        selected = t;
        selectedLabel.textContent = `${t.name} (${t.start}–${t.end})`;
        renderPalette();
      });
      palette.appendChild(btn);
    });

    const clr = document.createElement('button');
    clr.type = 'button';
    clr.className = 'w-full text-left rounded-2xl border border-slate-200 bg-white px-4 py-3 hover:bg-slate-50 transition';
    clr.innerHTML = `<div class="text-sm font-semibold text-slate-900">No shift selected</div><div class="mt-1 text-xs text-slate-600">Click to stop assigning</div>`;
    clr.addEventListener('click', () => {
      selected = null;
      selectedLabel.textContent = 'None';
      renderPalette();
    });
    palette.appendChild(clr);
  }

  // key: empId|date -> array of shifts (currently 1)
  const assignments = new Map();
  function cellKey(empId, date) { return `${empId}|${date}`; }

  function renderCell(cell) {
    const empId = cell.getAttribute('data-emp');
    const date = cell.getAttribute('data-date');
    const wrap = cell.querySelector('[data-pill-wrap="1"]');
    const plus = cell.querySelector('[data-plus="1"]');
    const key = cellKey(empId, date);
    const items = assignments.get(key) || [];

    wrap.innerHTML = '';
    if (items.length === 0) {
      plus.textContent = '+';
      plus.className = 'text-xs text-slate-500';
      return;
    }

    plus.textContent = '−';
    plus.className = 'text-xs text-slate-600';

    items.forEach((it, idx) => {
      const pill = document.createElement('button');
      pill.type = 'button';
      pill.className = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-2 text-left hover:bg-slate-100 transition';
      pill.innerHTML = `
        <div class="flex items-center justify-between gap-2">
          <div class="text-xs font-semibold text-slate-900">${escapeHtml(it.start)}–${escapeHtml(it.end)}</div>
          <div class="text-[11px] font-semibold text-slate-600">${escapeHtml(it.hours)}h</div>
        </div>
        <div class="mt-1 text-[11px] text-slate-600">${escapeHtml(it.name)}</div>
      `;
      pill.title = 'Click to remove';
      pill.addEventListener('click', (e) => {
        e.stopPropagation();
        items.splice(idx, 1);
        if (items.length === 0) assignments.delete(key);
        else assignments.set(key, items);
        renderCell(cell);
        recalcTotals();
      });
      wrap.appendChild(pill);
    });
  }

  function addShiftToCell(cell, template) {
    const empId = cell.getAttribute('data-emp');
    const date = cell.getAttribute('data-date');
    const key = cellKey(empId, date);

    assignments.set(key, [{
      id: template.id,
      name: template.name,
      start: template.start,
      end: template.end,
      hours: template.hours,
      overnight: !!template.overnight,
    }]);

    renderCell(cell);
    recalcTotals();
  }

  function recalcTotals() {
    const empTotals = new Map();
    const deptTotals = new Map(); // deptId -> {name, hours}

    assignments.forEach((items, key) => {
      const [empIdStr, date] = key.split('|');
      const empId = parseInt(empIdStr, 10);
      const cell = document.querySelector(`[data-cell="1"][data-emp="${CSS.escape(empIdStr)}"][data-date="${CSS.escape(date)}"]`);
      const row = cell?.closest('tr[data-emp-row]');
      const deptId = row ? parseInt(row.getAttribute('data-dept-id') || '0', 10) : 0;
      const deptName = row ? (row.getAttribute('data-dept-name') || '—') : '—';

      items.forEach(it => {
        empTotals.set(empId, (empTotals.get(empId) || 0) + Number(it.hours || 0));
        const curr = deptTotals.get(deptId) || { id: deptId, name: deptName, hours: 0 };
        curr.hours += Number(it.hours || 0);
        deptTotals.set(deptId, curr);
      });
    });

    $$('[data-emp-total]').forEach(el => {
      const empId = parseInt(el.getAttribute('data-emp-total') || '0', 10);
      const v = empTotals.get(empId) || 0;
      el.textContent = String(Math.round(v * 10) / 10);
    });

    renderDeptSummary(Array.from(deptTotals.values()));
  }

  function renderDeptSummary(rows) {
    const tbody = $('#deptSummary');
    if (!rows.length) {
      tbody.innerHTML = `<tr><td class="px-3 py-4 text-sm text-slate-600" colspan="4">Assign shifts to see summary.</td></tr>`;
      return;
    }

    rows.sort((a,b) => String(a.name).localeCompare(String(b.name)));
    tbody.innerHTML = '';

    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.className = 'border-t border-slate-100';
      tr.innerHTML = `
        <td class="px-3 py-3"><div class="font-semibold text-slate-900">${escapeHtml(r.name)}</div></td>
        <td class="px-3 py-3"><div class="font-semibold text-slate-900" data-assigned="${r.id}">${(Math.round(r.hours*10)/10).toFixed(1)}</div></td>
        <td class="px-3 py-3">
          <input type="number" min="0" step="0.5" value="0"
            class="w-28 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
            data-target="${r.id}" />
        </td>
        <td class="px-3 py-3"><div class="font-semibold text-slate-900" data-diff="${r.id}">0.0</div></td>
      `;
      tbody.appendChild(tr);
    });

    $$('input[data-target]').forEach(inp => inp.addEventListener('input', updateDiffs));
    updateDiffs();
  }

  function updateDiffs() {
    $$('input[data-target]').forEach(inp => {
      const id = inp.getAttribute('data-target');
      const assignedEl = document.querySelector(`[data-assigned="${CSS.escape(id)}"]`);
      const diffEl = document.querySelector(`[data-diff="${CSS.escape(id)}"]`);
      if (!assignedEl || !diffEl) return;

      const assigned = parseFloat(assignedEl.textContent || '0') || 0;
      const target = parseFloat(inp.value || '0') || 0;
      const diff = assigned - target;

      diffEl.textContent = diff.toFixed(1);
      diffEl.className = 'font-semibold ' + (diff >= 0 ? 'text-emerald-700' : 'text-rose-700');
    });
  }

  function bindCells() {
    $$('[data-cell="1"]').forEach(cell => {
      cell.addEventListener('click', () => {
        if (!selected) return;
        addShiftToCell(cell, selected);
      });
      renderCell(cell);
    });
  }

  $('#btnClearWeek')?.addEventListener('click', () => {
    assignments.clear();
    $$('[data-cell="1"]').forEach(renderCell);
    recalcTotals();
  });

  $('#btnDemoFill')?.addEventListener('click', () => {
    const rows = $$('tr[data-emp-row]').slice(0, 8);
    rows.forEach((row, idx) => {
      const empId = row.getAttribute('data-emp-row');
      const cells = $$(`[data-cell="1"][data-emp="${CSS.escape(empId)}"]`, row);
      cells.forEach((cell, di) => {
        if (di <= 4) {
          const t = (idx % 2 === 0) ? shiftTemplates[0] : shiftTemplates[3];
          addShiftToCell(cell, t);
        }
      });
    });
    recalcTotals();
  });

  renderPalette();
  bindCells();
  recalcTotals();
})();
</script>

<?php admin_page_end(); ?>
