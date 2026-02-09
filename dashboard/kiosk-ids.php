<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_employees');

$canManage = admin_can($user, 'manage_employees');

$active = admin_url('kiosk-ids.php');
admin_page_start($pdo, 'Kiosk IDs');

/**
 * SHA-256 fingerprint for fast indexed lookup; bcrypt remains authoritative.
 */
function pin_fingerprint(string $pin): string {
  return hash('sha256', $pin);
}

function full_name(?string $first, ?string $last): string {
  $n = trim((string)$first . ' ' . (string)$last);
  return $n !== '' ? $n : '—';
}

$errors = [];
$success = (string)($_GET['ok'] ?? '');

$selectId = (int)($_GET['select'] ?? 0);

/**
 * Load staff options for dropdown (name + dept).
 * dept comes from kiosk_employee_departments (current schema).
 */
$staffOptions = [];
try {
  $staffOptions = $pdo->query("
    SELECT s.id,
           s.first_name, s.last_name, s.email,
           s.department_id,
           d.name AS department_name,
           s.status
    FROM hr_staff s
    LEFT JOIN kiosk_employee_departments d ON d.id = s.department_id
    WHERE s.archived_at IS NULL
    ORDER BY (s.status='active') DESC, s.last_name ASC, s.first_name ASC, s.id ASC
    LIMIT 3000
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $staffOptions = [];
}

/**
 * Handle POST (add/edit).
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Dashboard uses admin_csrf token helpers (see dashboard/bootstrap.php)
  admin_csrf_verify();

  if (!$canManage) {
    http_response_code(403);
    exit('Forbidden');
  }

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
    $hrStaffId = (int)($_POST['hr_staff_id'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $newPin = trim((string)($_POST['new_pin'] ?? ''));

    if ($id <= 0) $errors[] = 'Missing kiosk id.';
    if ($employeeCode === '') $errors[] = 'Kiosk Employee Code is required.';

    // Optional: enforce 1 staff <-> 1 kiosk identity (recommended default)
    if ($hrStaffId > 0) {
      $stmt = $pdo->prepare("SELECT id FROM kiosk_employees WHERE hr_staff_id = ? AND id <> ? LIMIT 1");
      $stmt->execute([$hrStaffId, $id]);
      if ($stmt->fetchColumn()) {
        $errors[] = 'That staff record is already linked to another kiosk ID.';
      }
    }

    if (!$errors) {
      $pdo->beginTransaction();
      try {
        $fields = [
          'employee_code' => $employeeCode,
          'hr_staff_id' => ($hrStaffId > 0 ? $hrStaffId : null),
          'is_active' => $isActive,
        ];

        // PIN update (optional)
        if ($newPin !== '') {
          if (!preg_match('/^\d{4,10}$/', $newPin)) {
            throw new RuntimeException('PIN must be 4–10 digits.');
          }
          $fields['pin_hash'] = password_hash($newPin, PASSWORD_BCRYPT);
          $fields['pin_fingerprint'] = pin_fingerprint($newPin);
          $fields['pin_updated_at'] = gmdate('Y-m-d H:i:s');
        }

        // Build update query
        $set = [];
        $params = [];
        foreach ($fields as $k => $v) {
          $set[] = "`$k` = ?";
          $params[] = $v;
        }
        $params[] = $id;

        $sql = "UPDATE kiosk_employees SET " . implode(', ', $set) . " WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();
        header('Location: ' . admin_url('kiosk-ids.php?ok=saved&select=' . $id));
        exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
      }
    }
  }

  if ($action === 'add') {
    $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
    $hrStaffId = (int)($_POST['hr_staff_id'] ?? 0);
    $pin = trim((string)($_POST['pin'] ?? ''));
    $isActive = 1;

    if ($employeeCode === '') $errors[] = 'Kiosk Employee Code is required.';
    if ($hrStaffId <= 0) $errors[] = 'Please select a staff record.';
    if ($pin === '') $errors[] = 'PIN is required.';
    if ($pin !== '' && !preg_match('/^\d{4,10}$/', $pin)) $errors[] = 'PIN must be 4–10 digits.';

    // enforce 1 staff <-> 1 kiosk id
    if ($hrStaffId > 0) {
      $stmt = $pdo->prepare("SELECT id FROM kiosk_employees WHERE hr_staff_id = ? LIMIT 1");
      $stmt->execute([$hrStaffId]);
      if ($stmt->fetchColumn()) {
        $errors[] = 'That staff record is already linked to another kiosk ID.';
      }
    }

    if (!$errors) {
      try {
        $stmt = $pdo->prepare("
          INSERT INTO kiosk_employees
            (employee_code, hr_staff_id, is_active, is_agency, pin_hash, pin_fingerprint, pin_updated_at, created_at, updated_at)
          VALUES
            (?, ?, ?, 0, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
          $employeeCode,
          $hrStaffId,
          $isActive,
          password_hash($pin, PASSWORD_BCRYPT),
          pin_fingerprint($pin),
          gmdate('Y-m-d H:i:s'),
        ]);
        $newId = (int)$pdo->lastInsertId();
        header('Location: ' . admin_url('kiosk-ids.php?ok=added&select=' . $newId));
        exit;
      } catch (Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }
  }
}

/**
 * Load kiosk identities list.
 * Name/Department come from linked hr_staff.
 */
$rows = $pdo->query("
  SELECT e.id, e.employee_code, e.is_active, e.is_agency, e.agency_label, e.nickname, e.hr_staff_id,
         s.first_name AS staff_first_name, s.last_name AS staff_last_name, s.email AS staff_email,
         s.department_id AS staff_department_id, d.name AS staff_department_name
  FROM kiosk_employees e
  LEFT JOIN hr_staff s ON s.id = e.hr_staff_id
  LEFT JOIN kiosk_employee_departments d ON d.id = s.department_id
  WHERE e.archived_at IS NULL
  ORDER BY e.is_active DESC, e.id DESC
  LIMIT 1000
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selected = null;
if ($selectId > 0) {
  foreach ($rows as $r) {
    if ((int)$r['id'] === $selectId) { $selected = $r; break; }
  }
}
if (!$selected && $rows) $selected = $rows[0];
$selectedId = $selected ? (int)$selected['id'] : 0;

function staff_label(array $st): string {
  $id = (int)($st['id'] ?? 0);
  $name = trim((string)($st['first_name'] ?? '') . ' ' . (string)($st['last_name'] ?? ''));
  if ($name === '') $name = 'Staff #' . $id;
  $email = trim((string)($st['email'] ?? ''));
  $dept = trim((string)($st['department_name'] ?? ''));
  $parts = [$name];
  if ($email !== '') $parts[] = $email;
  if ($dept !== '') $parts[] = $dept;
  $parts[] = '#' . $id;
  return implode(' · ', $parts);
}
?>
<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
    <header class="rounded-3xl border border-slate-200 bg-white p-4">
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold">Kiosk IDs</h1>
          <p class="mt-1 text-sm text-slate-600">
            Operational kiosk identities. Name/Department come from linked HR Staff (no HR data stored in kiosk).
          </p>
        </div>

        <?php if ($canManage): ?>
          <button type="button" id="btnAdd" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-500/15 border border-emerald-500/30 text-slate-900 hover:bg-emerald-500/20">
            Add kiosk ID
          </button>
        <?php endif; ?>
      </div>

      <?php if ($success): ?>
        <div class="mt-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
          Saved.
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="mt-3 rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
          <div class="font-semibold">Please fix:</div>
          <ul class="list-disc ml-5 mt-1">
            <?php foreach ($errors as $e): ?>
              <li><?= h((string)$e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </header>

    <div class="mt-5 grid grid-cols-1 xl:grid-cols-12 gap-5">
      <!-- List -->
      <section class="xl:col-span-7 min-w-0">
        <div class="rounded-3xl border border-slate-200 bg-white overflow-hidden">
          <div class="p-3 flex items-center justify-between">
            <div class="text-sm text-slate-600"><span class="font-semibold text-slate-900"><?= count($rows) ?></span> kiosk IDs</div>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm border border-slate-200 border-collapse">
              <thead class="bg-slate-50 text-slate-600">
                <tr>
                  <th class="text-left font-semibold px-3 py-2">Kiosk ID</th>
                  <th class="text-left font-semibold px-3 py-2">Name</th>
                  <th class="text-left font-semibold px-3 py-2">Department</th>
                  <th class="text-left font-semibold px-3 py-2">Staff ID</th>
                  <th class="text-left font-semibold px-3 py-2">Active</th>
                  <th class="text-right font-semibold px-3 py-2">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <?php foreach ($rows as $r):
                  $kid = (int)$r['id'];
                  $linkedStaffId = (int)($r['hr_staff_id'] ?? 0);

                  if ($linkedStaffId > 0) {
                    $name = full_name((string)$r['staff_first_name'], (string)$r['staff_last_name']);
                    $dept = (string)($r['staff_department_name'] ?? '—');
                    $staffIdLabel = '#' . $linkedStaffId;
                  } else {
                    $name = trim((string)($r['agency_label'] ?? '')) ?: (trim((string)($r['nickname'] ?? '')) ?: '—');
                    $dept = '—';
                    $staffIdLabel = '—';
                  }

                  $isActive = (int)($r['is_active'] ?? 0) === 1;
                ?>
                  <tr class="<?= $kid === $selectedId ? 'bg-slate-50' : 'hover:bg-slate-50' ?>">
                    <td class="px-3 py-2 font-semibold text-slate-900"><?= h((string)($r['employee_code'] ?? '')) ?></td>
                    <td class="px-3 py-2 text-slate-700"><?= h($name) ?></td>
                    <td class="px-3 py-2 text-slate-700"><?= h($dept !== '' ? $dept : '—') ?></td>
                    <td class="px-3 py-2 text-slate-700"><?= h($staffIdLabel) ?></td>
                    <td class="px-3 py-2">
                      <?php if ($isActive): ?>
                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 text-emerald-700 px-2 py-0.5 text-xs font-semibold border border-emerald-500/20">Active</span>
                      <?php else: ?>
                        <span class="inline-flex items-center rounded-full bg-slate-500/10 text-slate-700 px-2 py-0.5 text-xs font-semibold border border-slate-500/20">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-right">
                      <a class="rounded-xl px-3 py-1.5 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50"
                         href="<?= h(admin_url('kiosk-ids.php?select=' . $kid)) ?>">
                        Edit
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                  <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">No kiosk IDs found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- Right panel -->
      <aside class="xl:col-span-5">
        <div class="rounded-3xl border border-slate-200 bg-white p-4 sticky top-5">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs font-semibold text-slate-600">Details</div>
              <div class="text-lg font-semibold text-slate-900">
                <?= $selected ? 'Kiosk ID: ' . h((string)($selected['employee_code'] ?? '')) : 'Select a kiosk ID' ?>
              </div>
            </div>
          </div>

          <?php if ($selected && $canManage): ?>
            <form method="post" class="mt-4 space-y-3">
              <?php admin_csrf_field(); ?>
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="id" value="<?= (int)$selected['id'] ?>">

              <div>
                <label class="block text-xs font-semibold text-slate-600">Kiosk Employee Code</label>
                <input name="employee_code" value="<?= h((string)($selected['employee_code'] ?? '')) ?>"
                       class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-600">Linked Staff</label>
                <select name="hr_staff_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                  <option value="0">— Not linked —</option>
                  <?php foreach ($staffOptions as $st): ?>
                    <?php $sid = (int)$st['id']; ?>
                    <option value="<?= $sid ?>" <?= ((int)($selected['hr_staff_id'] ?? 0) === $sid) ? 'selected' : '' ?>>
                      <?= h(staff_label($st)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="mt-1 text-xs text-slate-500">
                  This is the only place to link kiosk identities to staff (writes kiosk_employees.hr_staff_id).
                </div>
              </div>

              <?php if ((int)($selected['hr_staff_id'] ?? 0) > 0): ?>
                <a class="inline-block text-xs font-semibold text-slate-700 hover:text-slate-900 underline"
                   href="<?= h(admin_url('hr-staff-view.php?id=' . (int)$selected['hr_staff_id'])) ?>">
                  View staff
                </a>
              <?php endif; ?>

              <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-2 text-sm">
                  <input type="checkbox" name="is_active" value="1" <?= ((int)($selected['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
                  Active
                </label>
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-600">Set / reset PIN (optional)</label>
                <input name="new_pin" inputmode="numeric" pattern="\d{4,10}"
                       class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm"
                       placeholder="Leave blank to keep current PIN">
                <div class="mt-1 text-xs text-slate-500">
                  Stores bcrypt hash + indexed SHA-256 fingerprint (fast lookup; bcrypt remains authoritative).
                </div>
              </div>

              <div class="pt-2 flex gap-2">
                <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800">
                  Save
                </button>
              </div>
            </form>
          <?php elseif ($selected): ?>
            <div class="mt-4 text-sm text-slate-600">You don’t have permission to edit kiosk IDs.</div>
          <?php endif; ?>

          <!-- Add form (hidden; toggled by button) -->
          <?php if ($canManage): ?>
            <div id="addPanel" class="mt-6 hidden">
              <div class="text-xs font-semibold text-slate-600">Add kiosk ID</div>

              <form method="post" class="mt-3 space-y-3">
                <?php admin_csrf_field(); ?>
                <input type="hidden" name="action" value="add">

                <div>
                  <label class="block text-xs font-semibold text-slate-600">Select Staff</label>
                  <select name="hr_staff_id" id="add_staff_id" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
                    <option value="0">— Select staff —</option>
                    <?php foreach ($staffOptions as $st): ?>
                      <?php $sid = (int)$st['id']; ?>
                      <option value="<?= $sid ?>" data-dept="<?= h((string)($st['department_name'] ?? '')) ?>">
                        <?= h(staff_label($st)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div>
                  <label class="block text-xs font-semibold text-slate-600">Kiosk Employee Code</label>
                  <input name="employee_code" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm"
                         placeholder="e.g. 115">
                </div>

                <div>
                  <label class="block text-xs font-semibold text-slate-600">PIN</label>
                  <input name="pin" inputmode="numeric" pattern="\d{4,10}"
                         class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm"
                         placeholder="4–10 digits">
                </div>

                <div class="pt-2 flex gap-2">
                  <button class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">
                    Create
                  </button>
                  <button type="button" id="btnCancelAdd" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">
                    Cancel
                  </button>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  </main>
</div>

<script>
  (function () {
    const btnAdd = document.getElementById('btnAdd');
    const addPanel = document.getElementById('addPanel');
    const btnCancel = document.getElementById('btnCancelAdd');

    if (btnAdd && addPanel) {
      btnAdd.addEventListener('click', () => addPanel.classList.toggle('hidden'));
    }
    if (btnCancel && addPanel) {
      btnCancel.addEventListener('click', () => addPanel.classList.add('hidden'));
    }
  })();
</script>

<?php admin_page_end(); ?>
