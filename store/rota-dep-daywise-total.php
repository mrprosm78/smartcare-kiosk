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

// Timezone + payroll week window
$tz = new DateTimeZone(payroll_timezone($pdo));

$weekParam = qstr('week_start', '');
$anyLocal = null;
if ($weekParam !== '' && is_ymd($weekParam)) {
  try { $anyLocal = new DateTimeImmutable($weekParam . ' 12:00:00', $tz); } catch (Throwable $e) { $anyLocal = null; }
}
if (!$anyLocal) $anyLocal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($tz);

$window = payroll_week_window($pdo, $anyLocal);
$weekStartLocal = $window['start_local'];        /** @var DateTimeImmutable $weekStartLocal */
$weekEndLocalEx = $window['end_local_ex'];       /** @var DateTimeImmutable $weekEndLocalEx */
$weekStartsOn = (string)$window['week_starts_on'];

$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = $weekStartLocal->modify('+' . $i . ' days');
  $days[] = [
    'ymd' => $d->format('Y-m-d'),
    'dow' => $d->format('D'),
    'label' => $d->format('d M'),
  ];
}

$rangeLabel = $weekStartLocal->format('D d M Y') . ' — ' . $weekEndLocalEx->modify('-1 day')->format('D d M Y');
$prevWeek = $weekStartLocal->modify('-7 days')->format('Y-m-d');
$nextWeek = $weekStartLocal->modify('+7 days')->format('Y-m-d');

// Filters
$dept = (int)($_GET['dept'] ?? 0);
$q = qstr('q', '');

// Departments
$depts = $pdo->query("SELECT id, name FROM kiosk_employee_departments WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Staff (hr_staff uses status enum: active/inactive/archived)
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

      <!-- Header / Filters -->
      <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4">

          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Rota</h1>
              <p class="mt-1 text-sm text-slate-600">
                Weekly planner (anchored to <span class="font-semibold"><?= h($weekStartsOn) ?></span>, timezone <span class="font-semibold"><?= h(payroll_timezone($pdo)) ?></span>).
              </p>
            </div>

            <div class="flex items-center gap-2">
              <a href="<?= h(admin_url('rota.php')) ?>"
                 class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                 This week
              </a>
            </div>
          </div>

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

      <!-- Weekly rota (full width) -->
      <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">

        <!-- Shift templates ON TOP of weekly rota section (full width) -->
        <div class="p-4 border-b border-slate-200 bg-white">
          <div class="grid grid-cols-1 xl:grid-cols-2 gap-3 items-start">
            <div class="flex flex-wrap items-center gap-2 justify-start" id="shiftPaletteRow"></div>

            <div class="flex flex-wrap items-center gap-2 justify-start xl:justify-end">
              <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                Selected: <span id="selectedShiftLabel" class="font-semibold">None</span>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                Week total: <span id="weekTotalHours" class="font-semibold">0</span>h
              </div>

              <button type="button" id="btnAddTemplate"
                class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                + Shift
              </button>

              <button type="button" id="btnClearWeek"
                class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                Clear
              </button>

              <button type="button" id="btnDemoFill"
                class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                Demo fill
              </button>
            </div>
          </div>

          <!-- Create shift template (UI-only) -->
          <div id="templatePanel" class="hidden mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-slate-900">Create shift template</div>
                <div class="mt-1 text-xs text-slate-600">Time-only buttons. Day = green, Night = indigo. (UI only)</div>
              </div>
              <button type="button" id="btnCloseTemplate"
                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                Close
              </button>
            </div>

            <div class="mt-3 grid grid-cols-1 md:grid-cols-4 gap-3">
              <div>
                <div class="text-xs font-semibold text-slate-600">Type</div>
                <select id="tplType"
                  class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                  <option value="day">Day</option>
                  <option value="night">Night</option>
                </select>
              </div>
              <div>
                <div class="text-xs font-semibold text-slate-600">Start</div>
                <input id="tplStart" type="time"
                  class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200" />
              </div>
              <div>
                <div class="text-xs font-semibold text-slate-600">End</div>
                <input id="tplEnd" type="time"
                  class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200" />
              </div>
              <div class="flex items-end">
                <button type="button" id="btnSaveTemplate"
                  class="w-full inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                  Add template
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Weekly rota table -->
        <div class="p-5 border-b border-slate-200">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-slate-900">Weekly rota</div>
              <div class="mt-1 text-xs text-slate-600">
                Showing <span class="font-semibold"><?= count($staff) ?></span> staff. Click a shift, then click a cell.
              </div>
            </div>
          </div>
        </div>

        <div class="overflow-auto">
          <table class="min-w-[1200px] w-full text-sm">
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
                    <div>
                      <div class="font-semibold text-slate-900"><?= h($full) ?></div>
                      <div class="mt-1 text-xs text-slate-600"><?= h($deptName) ?></div>
                      <?php if (!empty($s['staff_code'])): ?>
                        <div class="mt-1 text-[11px] text-slate-500"><?= h((string)$s['staff_code']) ?></div>
                      <?php endif; ?>
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

      <!-- Totals -->
      <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-slate-900">Department totals</div>
            <div class="mt-1 text-xs text-slate-600">Department hours by day (UI-only; based on assigned shifts).</div>
          </div>
          <div class="text-xs text-slate-600">Week: <span class="font-semibold"><?= h($weekStartLocal->format('Y-m-d')) ?></span></div>
        </div>

        <div class="mt-4 overflow-auto">
          <table class="min-w-[980px] w-full text-sm">
            <thead>
              <tr class="border-b border-slate-200">
                <th class="text-left px-3 py-2 text-xs font-semibold text-slate-600">Department</th>
                <?php foreach ($days as $d): ?>
                  <th class="text-left px-3 py-2 text-xs font-semibold text-slate-600"><?= h($d['dow']) ?><div class="text-[11px] font-normal text-slate-500"><?= h($d['label']) ?></div></th>
                <?php endforeach; ?>
                <th class="text-left px-3 py-2 text-xs font-semibold text-slate-600">Total</th>
              </tr>
            </thead>
            <tbody id="deptSummary">
              <tr>
                <td class="px-3 py-4 text-sm text-slate-600" colspan="9">Assign shifts to see department hours.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>
</div>

<script>
(() => {
  // UI-only rota planner (no DB save yet)

  const shiftTemplates = [
    { id: 'D07_19', type: 'day',   start: '07:00', end: '19:00', hours: 12.0 },
    { id: 'N19_07', type: 'night', start: '19:00', end: '07:00', hours: 12.0, overnight: true },
    { id: 'E08_20', type: 'day',   start: '08:00', end: '20:00', hours: 12.0 },
    { id: 'S07_15', type: 'day',   start: '07:00', end: '15:00', hours: 8.0 },
    { id: 'L13_21', type: 'day',   start: '13:00', end: '21:00', hours: 8.0 },
  ];

  // Week dates (YYYY-MM-DD) for day-wise totals
  const weekDates = <?= json_encode(array_map(fn($d) => $d['ymd'], $days), JSON_UNESCAPED_SLASHES) ?>;


  let selected = null;

  const $ = (sel, el=document) => el.querySelector(sel);
  const $$ = (sel, el=document) => Array.from(el.querySelectorAll(sel));

  const palette = $('#shiftPaletteRow');
  const selectedLabel = $('#selectedShiftLabel');

  function fmtHH(t) { return String(t || '').replace(':00', ''); }

  function renderPalette() {
    if (!palette) return;
    palette.innerHTML = '';

    shiftTemplates.forEach(t => {
      const btn = document.createElement('button');
      btn.type = 'button';

      const isSelected = selected && selected.id === t.id;
      const isNight = (t.type === 'night') || !!t.overnight;

      const dayCls = isSelected ? 'bg-emerald-600 text-white border-emerald-700' : 'bg-emerald-50 text-emerald-800 border-emerald-200 hover:bg-emerald-100';
      const nightCls = isSelected ? 'bg-indigo-600 text-white border-indigo-700' : 'bg-indigo-50 text-indigo-800 border-indigo-200 hover:bg-indigo-100';

      btn.className = 'inline-flex items-center justify-center rounded-2xl border px-3 py-2 text-sm font-semibold transition ' + (isNight ? nightCls : dayCls);
      btn.textContent = `${fmtHH(t.start)}-${fmtHH(t.end)}`;

      btn.addEventListener('click', () => {
        selected = t;
        if (selectedLabel) selectedLabel.textContent = `${fmtHH(t.start)}-${fmtHH(t.end)}`;
        renderPalette();
      });

      palette.appendChild(btn);
    });

    const clr = document.createElement('button');
    clr.type = 'button';
    clr.className = 'inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition';
    clr.textContent = 'Clear';
    clr.addEventListener('click', () => {
      selected = null;
      if (selectedLabel) selectedLabel.textContent = 'None';
      renderPalette();
    });
    palette.appendChild(clr);
  }

  function calcHours(start, end) {
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    let s = sh * 60 + sm;
    let e = eh * 60 + em;
    if (e <= s) e += 24 * 60;
    return Math.round(((e - s) / 60) * 10) / 10;
  }

  // Template panel (UI-only)
  const panel = $('#templatePanel');
  $('#btnAddTemplate')?.addEventListener('click', () => panel?.classList.toggle('hidden'));
  $('#btnCloseTemplate')?.addEventListener('click', () => panel?.classList.add('hidden'));

  $('#btnSaveTemplate')?.addEventListener('click', () => {
    const type = ($('#tplType')?.value || 'day').toLowerCase();
    const start = ($('#tplStart')?.value || '').trim();
    const end = ($('#tplEnd')?.value || '').trim();
    if (!start || !end) return;

    const overnight = (type === 'night') || (end <= start);
    const id = 'C_' + Math.random().toString(36).slice(2, 9).toUpperCase();
    const hours = calcHours(start, end);

    shiftTemplates.push({ id, type, start, end, hours, overnight });
    selected = shiftTemplates[shiftTemplates.length - 1];
    if (selectedLabel) selectedLabel.textContent = `${fmtHH(start)}-${fmtHH(end)}`;

    if ($('#tplStart')) $('#tplStart').value = '';
    if ($('#tplEnd')) $('#tplEnd').value = '';
    panel?.classList.add('hidden');
    renderPalette();
  });

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
          <div class="text-xs font-semibold text-slate-900">${fmtHH(it.start)}-${fmtHH(it.end)}</div>
          <div class="text-[11px] font-semibold text-slate-600">${it.hours}h</div>
        </div>
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
      type: template.type,
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
    const deptTotals = new Map(); // deptId -> { id, name, total, byDate: { ymd: hours } }

    assignments.forEach((items, key) => {
      const [empIdStr, ymd] = key.split('|');
      const empId = parseInt(empIdStr, 10);

      const cell = document.querySelector(`[data-cell="1"][data-emp="${CSS.escape(empIdStr)}"][data-date="${CSS.escape(ymd)}"]`);
      const row = cell?.closest('tr[data-emp-row]');
      const deptId = row ? parseInt(row.getAttribute('data-dept-id') || '0', 10) : 0;
      const deptName = row ? (row.getAttribute('data-dept-name') || '—') : '—';

      items.forEach(it => {
        const h = Number(it.hours || 0);
        empTotals.set(empId, (empTotals.get(empId) || 0) + h);

        const curr = deptTotals.get(deptId) || { id: deptId, name: deptName, total: 0, byDate: {} };
        curr.total += h;
        curr.byDate[ymd] = (Number(curr.byDate[ymd] || 0) + h);
        deptTotals.set(deptId, curr);
      });
    });

    $$('[data-emp-total]').forEach(el => {
      const empId = parseInt(el.getAttribute('data-emp-total') || '0', 10);
      const v = empTotals.get(empId) || 0;
      el.textContent = String(Math.round(v * 10) / 10);
    });

    renderDeptSummary(Array.from(deptTotals.values()));

    // Week total (sum of all department totals)
    let weekTotal = 0;
    deptTotals.forEach(v => { weekTotal += Number(v.total || 0); });
    const wt = document.getElementById('weekTotalHours');
    if (wt) wt.textContent = String(Math.round(weekTotal * 10) / 10);
  }


  function renderDeptSummary(rows) {
    const tbody = $('#deptSummary');
    if (!tbody) return;

    const fmt = (n) => (Math.round(Number(n || 0) * 10) / 10).toFixed(1);

    if (!rows.length) {
      tbody.innerHTML = `<tr><td class="px-3 py-4 text-sm text-slate-600" colspan="${1 + (weekDates?.length || 7) + 1}">Assign shifts to see department hours.</td></tr>`;
      return;
    }

    rows.sort((a,b) => String(a.name).localeCompare(String(b.name)));
    tbody.innerHTML = '';

    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.className = 'border-t border-slate-100';

      let cells = `
        <td class="px-3 py-3">
          <div class="font-semibold text-slate-900">${String(r.name)}</div>
        </td>
      `;

      (weekDates || []).forEach(d => {
        const h = r.byDate?.[d] || 0;
        cells += `<td class="px-3 py-3 whitespace-nowrap text-slate-900">${fmt(h)}</td>`;
      });

      cells += `<td class="px-3 py-3 whitespace-nowrap font-semibold text-slate-900">${fmt(r.total)}</td>`;
      tr.innerHTML = cells;
      tbody.appendChild(tr);
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
          const t = (idx % 2 === 0) ? shiftTemplates[0] : shiftTemplates[1];
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
