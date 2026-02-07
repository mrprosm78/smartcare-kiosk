<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$active = admin_url('staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$staffId = (int)($_GET['id'] ?? 0);
if ($staffId <= 0) {
  http_response_code(400);
  exit('Missing staff id');
}

// Ensure table exists (best-effort, keeps local dev smooth)
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
} catch (Throwable $e) {
  // ignore
}

// Load staff
$stmt = $pdo->prepare("SELECT s.*, d.name AS department_name, t.name AS team_name
  FROM hr_staff s
  LEFT JOIN kiosk_employee_departments d ON d.id = s.department_id
  LEFT JOIN kiosk_employee_teams t ON t.id = s.team_id
  WHERE s.id=? LIMIT 1");
$stmt->execute([$staffId]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$staff) {
  http_response_code(404);
  exit('Staff not found');
}

// Load profile JSON from staff
$profile = [];
$raw = (string)($staff['profile_json'] ?? '');
if ($raw !== '') {
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) $profile = $decoded;
}

$tab = (string)($_GET['tab'] ?? 'personal');
$allowedTabs = ['personal','role','work_history','education','references','checks','declaration'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'personal';

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify();
  $tab = (string)($_POST['tab'] ?? $tab);
  if (!in_array($tab, $allowedTabs, true)) $tab = 'personal';

  // Update section based on tab
  $section = [];

  if (in_array($tab, ['work_history','references'], true)) {
    // Repeaters
    $items = $_POST['items'] ?? [];
    if (!is_array($items)) $items = [];
    // Clean each item to scalar strings
    $clean = [];
    foreach ($items as $it) {
      if (!is_array($it)) continue;
      $row = [];
      foreach ($it as $k => $v) {
        $k = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$k);
        if ($k === '') continue;
        $row[$k] = is_scalar($v) ? trim((string)$v) : '';
      }
      // drop empty rows
      $has = false;
      foreach ($row as $v) { if ($v !== '') { $has = true; break; } }
      if ($has) $clean[] = $row;
    }
    if ($tab === 'work_history') {
      $section = $profile['work_history'] ?? [];
      if (!is_array($section)) $section = [];
      $section['jobs'] = $clean;
      $section['gap_explanations'] = trim((string)($_POST['gap_explanations'] ?? ($section['gap_explanations'] ?? '')));
      $profile['work_history'] = $section;
    } else {
      $section = $profile['references'] ?? [];
      if (!is_array($section)) $section = [];
      $section['references'] = $clean;
      $profile['references'] = $section;
    }
  } else {
    // Generic scalar editor
    $fields = $_POST['fields'] ?? [];
    if (!is_array($fields)) $fields = [];
    foreach ($fields as $k => $v) {
      $k = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$k);
      if ($k === '') continue;
      $section[$k] = is_scalar($v) ? trim((string)$v) : '';
    }
    $profile[$tab] = $section;
  }

  $json = json_encode($profile, JSON_UNESCAPED_SLASHES) ?: '{}';
  $upd = $pdo->prepare("UPDATE hr_staff SET profile_json=?, updated_by_admin_id=?, updated_at=NOW() WHERE id=? LIMIT 1");
  $upd->execute([$json, (int)($user['id'] ?? 0), $staffId]);

  header('Location: ' . admin_url('staff-profile.php?id=' . $staffId . '&tab=' . urlencode($tab) . '&saved=1'));
  exit;
}

// Helpers for rendering
function field_input(string $name, string $value): string {
  $v = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
  return '<input name="fields[' . $n . ']" value="' . $v . '" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">';
}

$tabs = [
  'personal' => 'Personal',
  'role' => 'Role & availability',
  'work_history' => 'Work history',
  'education' => 'Education & training',
  'references' => 'References',
  'checks' => 'Right to work & checks',
  'declaration' => 'Declaration',
];

$empName = trim((string)(($staff['nickname'] ?? '') ?: trim((string)(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? '')))));
if ($empName === '') $empName = 'Staff #' . (int)$staffId;

admin_page_start($pdo, 'Staff HR Profile');
?>
<div class="min-h-dvh flex">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="flex-1 p-8">
    <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Staff HR profile</h1>
              <p class="mt-1 text-sm text-slate-600">
                <?= h2($empName) ?>
                <span class="text-slate-400">·</span> <span class="font-semibold text-slate-900">Staff ID: <?= (int)$staffId ?></span>
              </p>
              <?php if (!empty($_GET['saved'])): ?>
                <div class="mt-2 inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Saved</div>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
              <a href="<?= h(admin_url('staff-view.php?id=' . (int)$staffId)) ?>"
                 class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50">← Staff profile</a>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap gap-2">
            <?php foreach ($tabs as $k => $label): ?>
              <a href="<?= h(admin_url('staff-profile.php?id=' . (int)$staffId . '&tab=' . $k)) ?>"
                 class="rounded-full border px-3 py-1 text-xs font-semibold <?= $tab === $k ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?>">
                <?= h2($label) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <form method="post" class="space-y-4">
            <?php admin_csrf_field(); ?>
            <input type="hidden" name="tab" value="<?= h2($tab) ?>">

            <?php
              $sectionData = $profile[$tab] ?? [];
              if (!is_array($sectionData)) $sectionData = [];
            ?>

            <?php if ($tab === 'work_history'): ?>
              <?php
                $jobs = $sectionData['jobs'] ?? [];
                if (!is_array($jobs)) $jobs = [];
                if (!$jobs) $jobs = [ [] ];
                $gap = (string)($sectionData['gap_explanations'] ?? '');
              ?>
              <p class="text-sm text-slate-600">Edit work history jobs. Keep it simple for now; you can add/remove rows.</p>
              <div id="items-container" class="space-y-3" data-next-index="<?= (int)count($jobs) ?>">
                <?php foreach ($jobs as $i => $job): if (!is_array($job)) $job = []; ?>
                  <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 item-row" data-index="<?= (int)$i ?>">
                    <div class="flex items-center justify-between">
                      <div class="text-sm font-semibold text-slate-900">Job <?= (int)($i+1) ?></div>
                      <?php if ($i > 0): ?>
                        <button type="button" class="text-xs font-semibold text-slate-500 hover:text-rose-600 remove-item">Remove</button>
                      <?php endif; ?>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                      <?php
                        $fields = [
                          'employer_name' => 'Employer name',
                          'employer_location' => 'Location',
                          'job_title' => 'Job title',
                          'organisation_type' => 'Organisation type',
                          'start_month' => 'Start month',
                          'start_year' => 'Start year',
                          'end_month' => 'End month',
                          'end_year' => 'End year',
                          'is_current' => 'Is current (1/0)',
                          'is_care_role' => 'Care role (yes/no)',
                          'can_contact_now' => 'Can contact now (yes/no)',
                        ];
                      ?>
                      <?php foreach ($fields as $key => $label): ?>
                        <label class="block">
                          <span class="text-xs font-semibold text-slate-600"><?= h2($label) ?></span>
                          <input name="items[<?= (int)$i ?>][<?= h2($key) ?>]" value="<?= h2((string)($job[$key] ?? '')) ?>"
                                 class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        </label>
                      <?php endforeach; ?>
                      <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-600">Main duties</span>
                        <textarea name="items[<?= (int)$i ?>][main_duties]" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"><?= h2((string)($job['main_duties'] ?? '')) ?></textarea>
                      </label>
                      <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-600">Reason for leaving</span>
                        <textarea name="items[<?= (int)$i ?>][reason_for_leaving]" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"><?= h2((string)($job['reason_for_leaving'] ?? '')) ?></textarea>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="flex justify-end">
                <button type="button" id="add-item" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold hover:bg-slate-50">+ Add job</button>
              </div>
              <label class="block">
                <span class="text-xs font-semibold text-slate-600">Gap explanations</span>
                <textarea name="gap_explanations" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"><?= h2($gap) ?></textarea>
              </label>

              <template id="item-template">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 item-row" data-index="__INDEX__">
                  <div class="flex items-center justify-between">
                    <div class="text-sm font-semibold text-slate-900">Job __NUMBER__</div>
                    <button type="button" class="text-xs font-semibold text-slate-500 hover:text-rose-600 remove-item">Remove</button>
                  </div>
                  <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Employer name</span><input name="items[__INDEX__][employer_name]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Location</span><input name="items[__INDEX__][employer_location]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Job title</span><input name="items[__INDEX__][job_title]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Organisation type</span><input name="items[__INDEX__][organisation_type]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Start month</span><input name="items[__INDEX__][start_month]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Start year</span><input name="items[__INDEX__][start_year]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">End month</span><input name="items[__INDEX__][end_month]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">End year</span><input name="items[__INDEX__][end_year]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Is current (1/0)</span><input name="items[__INDEX__][is_current]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Care role (yes/no)</span><input name="items[__INDEX__][is_care_role]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Can contact now (yes/no)</span><input name="items[__INDEX__][can_contact_now]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block sm:col-span-2"><span class="text-xs font-semibold text-slate-600">Main duties</span><textarea name="items[__INDEX__][main_duties]" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></textarea></label>
                    <label class="block sm:col-span-2"><span class="text-xs font-semibold text-slate-600">Reason for leaving</span><textarea name="items[__INDEX__][reason_for_leaving]" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></textarea></label>
                  </div>
                </div>
              </template>

            <?php elseif ($tab === 'references'): ?>
              <?php
                $refs = $sectionData['references'] ?? [];
                if (!is_array($refs)) $refs = [];
                if (!$refs) $refs = [ [] ];
              ?>
              <p class="text-sm text-slate-600">Edit references. Add/remove rows as needed.</p>
              <div id="items-container" class="space-y-3" data-next-index="<?= (int)count($refs) ?>">
                <?php foreach ($refs as $i => $ref): if (!is_array($ref)) $ref = []; ?>
                  <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 item-row" data-index="<?= (int)$i ?>">
                    <div class="flex items-center justify-between">
                      <div class="text-sm font-semibold text-slate-900">Reference <?= (int)($i+1) ?></div>
                      <?php if ($i > 0): ?>
                        <button type="button" class="text-xs font-semibold text-slate-500 hover:text-rose-600 remove-item">Remove</button>
                      <?php endif; ?>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                      <?php
                        $fields = [
                          'name' => 'Name',
                          'relationship' => 'Relationship',
                          'company' => 'Company',
                          'phone' => 'Phone',
                          'email' => 'Email',
                          'address' => 'Address',
                        ];
                      ?>
                      <?php foreach ($fields as $key => $label): ?>
                        <label class="block">
                          <span class="text-xs font-semibold text-slate-600"><?= h2($label) ?></span>
                          <input name="items[<?= (int)$i ?>][<?= h2($key) ?>]" value="<?= h2((string)($ref[$key] ?? '')) ?>"
                                 class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        </label>
                      <?php endforeach; ?>
                      <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-600">Notes</span>
                        <textarea name="items[<?= (int)$i ?>][notes]" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"><?= h2((string)($ref['notes'] ?? '')) ?></textarea>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="flex justify-end">
                <button type="button" id="add-item" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold hover:bg-slate-50">+ Add reference</button>
              </div>

              <template id="item-template">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 item-row" data-index="__INDEX__">
                  <div class="flex items-center justify-between">
                    <div class="text-sm font-semibold text-slate-900">Reference __NUMBER__</div>
                    <button type="button" class="text-xs font-semibold text-slate-500 hover:text-rose-600 remove-item">Remove</button>
                  </div>
                  <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Name</span><input name="items[__INDEX__][name]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Relationship</span><input name="items[__INDEX__][relationship]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Company</span><input name="items[__INDEX__][company]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Phone</span><input name="items[__INDEX__][phone]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block"><span class="text-xs font-semibold text-slate-600">Email</span><input name="items[__INDEX__][email]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block sm:col-span-2"><span class="text-xs font-semibold text-slate-600">Address</span><input name="items[__INDEX__][address]" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></label>
                    <label class="block sm:col-span-2"><span class="text-xs font-semibold text-slate-600">Notes</span><textarea name="items[__INDEX__][notes]" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></textarea></label>
                  </div>
                </div>
              </template>

            <?php else: ?>
              <?php
                // Make sure there are some default keys so editing is easy
                $defaults = [];
                if ($tab === 'personal') {
                  $defaults = [
                    'first_name' => '',
                    'last_name' => '',
                    'email' => '',
                    'phone' => '',
                    'address' => '',
                    'postcode' => '',
                  ];
                } elseif ($tab === 'role') {
                  $defaults = [
                    'position_applied_for' => '',
                    'preferred_unit' => '',
                    'work_type' => '',
                    'preferred_shift_pattern' => '',
                    'hours_per_week' => '',
                    'earliest_start_date' => '',
                    'notice_period' => '',
                    'heard_about_role' => '',
                    'extra_notes' => '',
                  ];
                }
                $sectionData = array_merge($defaults, $sectionData);
              ?>
              <div class="grid gap-3 sm:grid-cols-2">
                <?php foreach ($sectionData as $k => $v): ?>
                  <?php if ($k === 'csrf') continue; ?>
                  <label class="block <?= (strlen((string)$v) > 70 ? 'sm:col-span-2' : '') ?>">
                    <span class="text-xs font-semibold text-slate-600"><?= h2((string)$k) ?></span>
                    <?php if (strlen((string)$v) > 90): ?>
                      <textarea name="fields[<?= h2((string)$k) ?>]" rows="4" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"><?= h2((string)$v) ?></textarea>
                    <?php else: ?>
                      <?= field_input((string)$k, (string)$v) ?>
                    <?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="flex items-center gap-2">
              <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save</button>
              <span class="text-xs text-slate-500">Edits update the staff HR profile only (not the original application).</span>
            </div>
          </form>
        </div>
    </div>
  </main>
</div>

<script>
(function(){
  const container = document.getElementById('items-container');
  const addBtn = document.getElementById('add-item');
  const tpl = document.getElementById('item-template');
  if (!container || !addBtn || !tpl) return;

  function renumber() {
    const rows = container.querySelectorAll('.item-row');
    rows.forEach((row, idx) => {
      const title = row.querySelector('.text-sm.font-semibold');
      if (title) {
        const base = title.textContent.replace(/\d+$/, '').trim();
        // Keep existing prefix ("Job"/"Reference") if present
        const prefix = base.match(/^(Job|Reference)/i) ? base.split(' ')[0] : 'Item';
        title.textContent = prefix + ' ' + (idx + 1);
      }
    });
  }

  function attachRemove() {
    container.querySelectorAll('.remove-item').forEach(btn => {
      btn.onclick = function(){
        const row = btn.closest('.item-row');
        if (!row) return;
        row.remove();
        renumber();
      };
    });
  }

  addBtn.addEventListener('click', function(){
    let next = parseInt(container.getAttribute('data-next-index') || '0', 10);
    if (isNaN(next)) next = 0;
    let html = tpl.innerHTML.replace(/__INDEX__/g, String(next)).replace(/__NUMBER__/g, String(next + 1));
    const w = document.createElement('div');
    w.innerHTML = html.trim();
    const row = w.firstElementChild;
    if (row) container.appendChild(row);
    container.setAttribute('data-next-index', String(next + 1));
    attachRemove();
    renumber();
  });

  attachRemove();
})();
</script>

<?php admin_page_end(); ?>
