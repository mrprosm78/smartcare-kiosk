<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_employees');

$canManage = admin_can($user, 'manage_employees');

$active = admin_url('kiosk-ids.php');
admin_page_start($pdo, 'Kiosk IDs');

// Flash helpers (session-backed)
function flash_set(string $key, $value): void {
  if (!isset($_SESSION['flash'])) $_SESSION['flash'] = [];
  $_SESSION['flash'][$key] = $value;
}

function flash_get(string $key, $default = null) {
  $v = $_SESSION['flash'][$key] ?? $default;
  if (isset($_SESSION['flash'][$key])) unset($_SESSION['flash'][$key]);
  return $v;
}

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

function gen_pin(int $digits = 4): string {
  $digits = max(4, min(10, $digits));
  $max = (10 ** $digits) - 1;
  $n = random_int(0, $max);
  return str_pad((string)$n, $digits, '0', STR_PAD_LEFT);
}

function gen_kiosk_code(int $id): string {
  return 'K' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

$errors = [];
$success = (string)($_GET['ok'] ?? '');
$flashPin = $canManage ? (string)flash_get('show_pin', '') : '';

$selectId = (int)($_GET['select'] ?? 0);

// Staff missing kiosk IDs (right-side panel)
$missingStaff = [];
try {
  $missingStaff = $pdo->query("
    SELECT s.id,
           s.staff_code,
           s.first_name, s.last_name,
           s.department_id,
           d.name AS department_name,
           s.status
    FROM hr_staff s
    LEFT JOIN kiosk_employees e ON e.hr_staff_id = s.id AND e.archived_at IS NULL
    LEFT JOIN hr_staff_departments d ON d.id = s.department_id
    WHERE s.archived_at IS NULL
      AND e.id IS NULL
    ORDER BY (s.status='active') DESC, s.last_name ASC, s.first_name ASC, s.id ASC
    LIMIT 500
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $missingStaff = [];
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
    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $agencyName = trim((string)($_POST['agency_name'] ?? ''));
    $pinMode = (string)($_POST['pin_mode'] ?? '');
    $pinManual = trim((string)($_POST['pin_manual'] ?? ''));

    if ($id <= 0) $errors[] = 'Missing kiosk id.';

    if (!$errors) {
      $pdo->beginTransaction();
      try {
        // Load identity first (for type/linked rules)
        $stmt = $pdo->prepare("SELECT id, hr_staff_id, is_agency FROM kiosk_employees WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cur) throw new RuntimeException('Kiosk ID not found.');

        $fields = ['is_active' => $isActive];

        // Agency name editable for agency identities only
        $isAgency = (int)($cur['is_agency'] ?? 0) === 1 || (int)($cur['hr_staff_id'] ?? 0) === 0;
        if ($isAgency) {
          $fields['is_agency'] = 1;
          $fields['agency_label'] = ($agencyName !== '' ? $agencyName : null);
        }

        // PIN reset (optional)
        $newPin = '';
        if ($pinMode === 'auto') {
          $newPin = gen_pin(4);
        } elseif ($pinMode === 'manual') {
          $newPin = $pinManual;
        }
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
        if ($newPin !== '') flash_set('show_pin', $newPin);
        header('Location: ' . admin_url('kiosk-ids.php?ok=saved&select=' . $id));
        exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
      }
    }
  }

  if ($action === 'create_staff') {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $pinMode = (string)($_POST['pin_mode'] ?? 'auto');
    $pinManual = trim((string)($_POST['pin_manual'] ?? ''));

    if ($staffId <= 0) $errors[] = 'Missing staff.';

    // enforce 1 staff <-> 1 kiosk id
    if ($staffId > 0) {
      $stmt = $pdo->prepare("SELECT id FROM kiosk_employees WHERE hr_staff_id = ? AND archived_at IS NULL LIMIT 1");
      $stmt->execute([$staffId]);
      if ($stmt->fetchColumn()) {
        $errors[] = 'That staff record already has a kiosk ID.';
      }
    }

    $pin = '';
    if ($pinMode === 'manual') {
      $pin = $pinManual;
    } else {
      $pin = gen_pin(4);
    }
    if ($pin === '' || !preg_match('/^\d{4,10}$/', $pin)) $errors[] = 'PIN must be 4–10 digits.';

    if (!$errors) {
      try {
        // Insert placeholder employee_code then update using id
        $stmt = $pdo->prepare("
          INSERT INTO kiosk_employees
            (employee_code, hr_staff_id, is_active, is_agency, pin_hash, pin_fingerprint, pin_updated_at, created_at, updated_at)
          VALUES
            ('', ?, ?, 0, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
          $staffId,
          $isActive,
          password_hash($pin, PASSWORD_BCRYPT),
          pin_fingerprint($pin),
          gmdate('Y-m-d H:i:s'),
        ]);
        $newId = (int)$pdo->lastInsertId();
        $code = gen_kiosk_code($newId);
        $u = $pdo->prepare("UPDATE kiosk_employees SET employee_code = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        $u->execute([$code, $newId]);
        flash_set('show_pin', $pin);
        header('Location: ' . admin_url('kiosk-ids.php?ok=added&select=' . $newId));
        exit;
      } catch (Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }
  }

  if ($action === 'create_agency') {
    $agencyName = trim((string)($_POST['agency_name'] ?? ''));
    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $pinMode = (string)($_POST['pin_mode'] ?? 'auto');
    $pinManual = trim((string)($_POST['pin_manual'] ?? ''));

    if ($agencyName === '') $errors[] = 'Agency name is required.';

    $pin = ($pinMode === 'manual') ? $pinManual : gen_pin(4);
    if ($pin === '' || !preg_match('/^\d{4,10}$/', $pin)) $errors[] = 'PIN must be 4–10 digits.';

    if (!$errors) {
      try {
        $stmt = $pdo->prepare("
          INSERT INTO kiosk_employees
            (employee_code, hr_staff_id, is_active, is_agency, agency_label, pin_hash, pin_fingerprint, pin_updated_at, created_at, updated_at)
          VALUES
            ('', NULL, ?, 1, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
          $isActive,
          $agencyName,
          password_hash($pin, PASSWORD_BCRYPT),
          pin_fingerprint($pin),
          gmdate('Y-m-d H:i:s'),
        ]);
        $newId = (int)$pdo->lastInsertId();
        $code = gen_kiosk_code($newId);
        $u = $pdo->prepare("UPDATE kiosk_employees SET employee_code = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        $u->execute([$code, $newId]);
        flash_set('show_pin', $pin);
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
         e.pin_updated_at,
         s.staff_code AS staff_code,
         s.first_name AS staff_first_name, s.last_name AS staff_last_name, s.email AS staff_email,
         s.department_id AS staff_department_id, d.name AS staff_department_name
  FROM kiosk_employees e
  LEFT JOIN hr_staff s ON s.id = e.hr_staff_id
  LEFT JOIN hr_staff_departments d ON d.id = s.department_id
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

function staff_short_label(array $st): string {
  $id = (int)($st['id'] ?? 0);
  $code = trim((string)($st['staff_code'] ?? ''));
  $name = trim((string)($st['first_name'] ?? '') . ' ' . (string)($st['last_name'] ?? ''));
  if ($name === '') $name = 'Staff #' . $id;
  $dept = trim((string)($st['department_name'] ?? ''));
  $status = trim((string)($st['status'] ?? ''));
  $parts = [];
  $parts[] = ($code !== '' ? $code : ('#' . $id));
  $parts[] = $name;
  if ($dept !== '') $parts[] = $dept;
  if ($status !== '') $parts[] = $status;
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
            Kiosk identities used for punch in/out. Staff kiosk IDs are created from HR staff. Agency kiosk IDs are allowed (not linked).
          </p>
        </div>
        <?php if ($canManage): ?>
          <button type="button" id="btnCreateAgency" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">
            Add agency kiosk ID
          </button>
        <?php endif; ?>
      </div>

      <?php if ($flashPin !== ''): ?>
        <div class="mt-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800">
          <div class="font-semibold">PIN</div>
          <div class="mt-0.5">New PIN: <span class="font-mono font-bold tracking-wider"><?= h($flashPin) ?></span></div>
          <div class="mt-1 text-xs text-indigo-700">Shown once. Please store it safely.</div>
        </div>
      <?php endif; ?>

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

      <div class="mt-4 grid grid-cols-1 lg:grid-cols-12 gap-3">
        <div class="lg:col-span-6">
          <label class="block text-xs font-semibold text-slate-600">Search</label>
          <input id="flt_q" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Search name, kiosk code, staff code">
        </div>
        <div class="lg:col-span-3">
          <label class="block text-xs font-semibold text-slate-600">Type</label>
          <select id="flt_type" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
            <option value="all">All</option>
            <option value="staff">Staff</option>
            <option value="agency">Agency</option>
          </select>
        </div>
        <div class="lg:col-span-3">
          <label class="block text-xs font-semibold text-slate-600">Status</label>
          <select id="flt_status" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm">
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
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
                  <th class="text-left font-semibold px-3 py-2">Code</th>
                  <th class="text-left font-semibold px-3 py-2">Type</th>
                  <th class="text-left font-semibold px-3 py-2">Name</th>
                  <th class="text-left font-semibold px-3 py-2">Staff</th>
                  <th class="text-left font-semibold px-3 py-2">Active</th>
                  <th class="text-left font-semibold px-3 py-2">PIN</th>
                  <th class="text-right font-semibold px-3 py-2">Action</th>
                </tr>
              </thead>
              <tbody id="kioskTable" class="divide-y divide-slate-200">
                <?php foreach ($rows as $r):
                  $kid = (int)$r['id'];
                  $linkedStaffId = (int)($r['hr_staff_id'] ?? 0);
                  $isAgency = ((int)($r['is_agency'] ?? 0) === 1) || $linkedStaffId === 0;
                  $type = $isAgency ? 'agency' : 'staff';

                  $staffCode = trim((string)($r['staff_code'] ?? ''));
                  $staffName = full_name((string)($r['staff_first_name'] ?? ''), (string)($r['staff_last_name'] ?? ''));
                  $displayName = $isAgency
                    ? (trim((string)($r['agency_label'] ?? '')) ?: (trim((string)($r['nickname'] ?? '')) ?: '—'))
                    : $staffName;

                  $staffLabel = ($linkedStaffId > 0)
                    ? (($staffCode !== '' ? $staffCode : ('#' . $linkedStaffId)) . ' · ' . $staffName)
                    : '—';

                  $isActive = (int)($r['is_active'] ?? 0) === 1;
                  $pinSet = !empty($r['pin_updated_at']);
                  $qhay = strtolower(trim((string)($r['employee_code'] ?? '') . ' ' . $displayName . ' ' . $staffLabel));
                ?>
                  <tr data-q="<?= h($qhay) ?>" data-type="<?= h($type) ?>" data-status="<?= $isActive ? 'active' : 'inactive' ?>" class="<?= $kid === $selectedId ? 'bg-slate-50' : 'hover:bg-slate-50' ?>">
                    <td class="px-3 py-2 font-semibold text-slate-900"><?= h((string)($r['employee_code'] ?? '')) ?></td>
                    <td class="px-3 py-2">
                      <?php if ($type === 'staff'): ?>
                        <span class="inline-flex items-center rounded-full bg-indigo-500/10 text-indigo-700 px-2 py-0.5 text-xs font-semibold border border-indigo-500/20">Staff</span>
                      <?php else: ?>
                        <span class="inline-flex items-center rounded-full bg-amber-500/10 text-amber-800 px-2 py-0.5 text-xs font-semibold border border-amber-500/20">Agency</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-slate-700"><?= h($displayName) ?></td>
                    <td class="px-3 py-2 text-slate-700"><?= h($staffLabel) ?></td>
                    <td class="px-3 py-2">
                      <?php if ($isActive): ?>
                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 text-emerald-700 px-2 py-0.5 text-xs font-semibold border border-emerald-500/20">Active</span>
                      <?php else: ?>
                        <span class="inline-flex items-center rounded-full bg-slate-500/10 text-slate-700 px-2 py-0.5 text-xs font-semibold border border-slate-500/20">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                      <?php if ($pinSet): ?>
                        <span class="inline-flex items-center rounded-full bg-slate-900/5 text-slate-800 px-2 py-0.5 text-xs font-semibold border border-slate-900/10">Set</span>
                      <?php else: ?>
                        <span class="inline-flex items-center rounded-full bg-rose-500/10 text-rose-700 px-2 py-0.5 text-xs font-semibold border border-rose-500/20">Not set</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-right">
                      <a class="rounded-xl px-3 py-1.5 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50"
                         href="<?= h(admin_url('kiosk-ids.php?select=' . $kid)) ?>">
                        Manage
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                  <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">No kiosk IDs found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- Right panel -->
      <aside class="xl:col-span-5">
        <div class="sticky top-5 space-y-4">
          

          <?php if ($selected):
            ?>
            <div class="rounded-3xl border border-slate-200 bg-white p-4 space-y-4">
            <?php
            $linkedStaffId = (int)($selected['hr_staff_id'] ?? 0);
            $isAgencySel = ((int)($selected['is_agency'] ?? 0) === 1) || $linkedStaffId === 0;
            $staffCodeSel = trim((string)($selected['staff_code'] ?? ''));
            $staffNameSel = full_name((string)($selected['staff_first_name'] ?? ''), (string)($selected['staff_last_name'] ?? ''));
            $staffLabelSel = ($linkedStaffId > 0) ? (($staffCodeSel !== '' ? $staffCodeSel : ('#' . $linkedStaffId)) . ' · ' . $staffNameSel) : '—';
          ?>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm">
              <div class="flex items-center justify-between">
                <div class="text-slate-600">Type</div>
                <div class="font-semibold text-slate-900"><?= $isAgencySel ? 'Agency' : 'Staff' ?></div>
              </div>
              <div class="mt-2 flex items-center justify-between">
                <div class="text-slate-600">Linked staff</div>
                <div class="font-semibold text-slate-900"><?= h($staffLabelSel) ?></div>
              </div>
              <?php if ($linkedStaffId > 0): ?>
                <div class="mt-2">
                  <a class="text-xs font-semibold text-slate-700 hover:text-slate-900 underline" href="<?= h(admin_url('hr-staff-view.php?id=' . $linkedStaffId)) ?>">View staff</a>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($canManage): ?>
              <form method="post" class="space-y-3">
                <?php admin_csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)$selected['id'] ?>">

                <?php if ($isAgencySel): ?>
                  <div>
                    <label class="block text-xs font-semibold text-slate-600">Agency name</label>
                    <input name="agency_name" value="<?= h((string)($selected['agency_label'] ?? '')) ?>" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="e.g. Agency Worker">
                  </div>
                <?php endif; ?>

                <div class="flex items-center gap-3">
                  <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" <?= ((int)($selected['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
                    Active
                  </label>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-3">
                  <div class="text-xs font-semibold text-slate-700">Reset PIN</div>
                  <div class="mt-2 flex gap-2">
                    <label class="inline-flex items-center gap-2 text-xs">
                      <input type="radio" name="pin_mode" value="" checked>
                      No change
                    </label>
                    <label class="inline-flex items-center gap-2 text-xs">
                      <input type="radio" name="pin_mode" value="auto">
                      Auto
                    </label>
                    <label class="inline-flex items-center gap-2 text-xs">
                      <input type="radio" name="pin_mode" value="manual">
                      Manual
                    </label>
                  </div>
                  <input name="pin_manual" id="pin_manual_save" inputmode="numeric" pattern="\d{4,10}" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Manual PIN (4–10 digits)" disabled>
                  <div class="mt-1 text-xs text-slate-500">PIN is shown once after save.</div>
                </div>

                <button class="w-full rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800">Save changes</button>
              </form>
            <?php else: ?>
              <div class="text-sm text-slate-600">You don’t have permission to edit kiosk IDs.</div>
            <?php endif; ?>            </div>


          <?php endif; ?>

          <?php if ($canManage): ?>
            <div class="rounded-3xl border border-slate-200 bg-white p-4">
              <div class="flex items-center justify-between">
                <div>
                  <div class="text-xs font-semibold text-slate-600">Staff missing kiosk ID</div>
                  <div class="text-sm text-slate-600 mt-1"><?= count($missingStaff) ?> staff without kiosk IDs</div>
                </div>
              </div>

              <div class="mt-3 max-h-[320px] overflow-auto rounded-2xl border border-slate-200 bg-white">
                <?php if ($missingStaff): ?>
                  <ul class="divide-y divide-slate-100">
                    <?php foreach ($missingStaff as $st):
                      $sid = (int)$st['id'];
                      $label = staff_short_label($st);
                    ?>
                      <li class="p-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                          <div class="text-sm font-semibold text-slate-900 truncate"><?= h($label) ?></div>
                        </div>
                        <button type="button" class="btnCreateStaff shrink-0 rounded-xl px-3 py-1.5 text-xs font-semibold bg-emerald-600 text-white hover:bg-emerald-700" data-staff-id="<?= $sid ?>" data-staff-label="<?= h($label) ?>">
                          Create
                        </button>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <div class="p-3 text-sm text-slate-600">All staff have kiosk IDs.</div>
                <?php endif; ?>
              </div>

              <div id="createStaffPanel" class="mt-3 hidden rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div class="text-xs font-semibold text-slate-700">Create kiosk ID for staff</div>
                <div id="createStaffLabel" class="mt-1 text-sm font-semibold text-slate-900">—</div>
                <form method="post" class="mt-3 space-y-3">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="create_staff">
                  <input type="hidden" name="staff_id" id="create_staff_id" value="0">

                  <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-sm">
                      <input type="checkbox" name="is_active" value="1" checked>
                      Active
                    </label>
                  </div>

                  <div class="rounded-2xl border border-slate-200 bg-white p-3">
                    <div class="text-xs font-semibold text-slate-700">PIN</div>
                    <div class="mt-2 flex gap-2">
                      <label class="inline-flex items-center gap-2 text-xs">
                        <input type="radio" name="pin_mode" value="auto" checked>
                        Auto-generate
                      </label>
                      <label class="inline-flex items-center gap-2 text-xs">
                        <input type="radio" name="pin_mode" value="manual">
                        Manual
                      </label>
                    </div>
                    <input name="pin_manual" id="pin_manual_create_staff" inputmode="numeric" pattern="\d{4,10}" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Manual PIN (4–10 digits)" disabled>
                    <div class="mt-1 text-xs text-slate-500">Kiosk code is auto-generated. PIN is shown once after create.</div>
                  </div>

                  <div class="flex gap-2">
                    <button class="flex-1 rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Create kiosk ID</button>
                    <button type="button" id="btnCancelCreateStaff" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Cancel</button>
                  </div>
                </form>
              </div>
            </div>

            <div id="createAgencyPanel" class="hidden rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-xs font-semibold text-slate-600">Add agency kiosk ID</div>
              <form method="post" class="mt-3 space-y-3">
                <?php admin_csrf_field(); ?>
                <input type="hidden" name="action" value="create_agency">
                <div>
                  <label class="block text-xs font-semibold text-slate-600">Name</label>
                  <input name="agency_name" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Agency worker name">
                </div>
                <div class="flex items-center gap-3">
                  <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" checked>
                    Active
                  </label>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                  <div class="text-xs font-semibold text-slate-700">PIN</div>
                  <div class="mt-2 flex gap-2">
                    <label class="inline-flex items-center gap-2 text-xs">
                      <input type="radio" name="pin_mode" value="auto" checked>
                      Auto-generate
                    </label>
                    <label class="inline-flex items-center gap-2 text-xs">
                      <input type="radio" name="pin_mode" value="manual">
                      Manual
                    </label>
                  </div>
                  <input name="pin_manual" id="pin_manual_create_agency" inputmode="numeric" pattern="\d{4,10}" class="mt-2 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="Manual PIN (4–10 digits)" disabled>
                  <div class="mt-1 text-xs text-slate-500">Kiosk code is auto-generated. PIN is shown once after create.</div>
                </div>
                <div class="flex gap-2">
                  <button class="flex-1 rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Create agency kiosk ID</button>
                  <button type="button" id="btnCancelCreateAgency" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Cancel</button>
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
    const q = document.getElementById('flt_q');
    const type = document.getElementById('flt_type');
    const status = document.getElementById('flt_status');
    const tbody = document.getElementById('kioskTable');
    const rows = tbody ? Array.from(tbody.querySelectorAll('tr[data-q]')) : [];

    function applyFilters() {
      const qv = (q?.value || '').trim().toLowerCase();
      const tv = type?.value || 'all';
      const sv = status?.value || 'all';
      rows.forEach(tr => {
        const hay = tr.getAttribute('data-q') || '';
        const t = tr.getAttribute('data-type') || '';
        const s = tr.getAttribute('data-status') || '';
        const okQ = !qv || hay.includes(qv);
        const okT = (tv === 'all') || (t === tv);
        const okS = (sv === 'all') || (s === sv);
        tr.style.display = (okQ && okT && okS) ? '' : 'none';
      });
    }

    q?.addEventListener('input', applyFilters);
    type?.addEventListener('change', applyFilters);
    status?.addEventListener('change', applyFilters);
    applyFilters();

    // Enable/disable manual pin fields
    function wirePinMode(form) {
      if (!form) return;
      const manualRadio = form.querySelector('input[type="radio"][name="pin_mode"][value="manual"]');
      const autoRadio = form.querySelector('input[type="radio"][name="pin_mode"][value="auto"]');
      const noneRadio = form.querySelector('input[type="radio"][name="pin_mode"][value=""]');
      const manualInput = form.querySelector('input[name="pin_manual"]');
      function update() {
        if (!manualInput) return;
        const v = (manualRadio && manualRadio.checked) ? 'manual' : ((autoRadio && autoRadio.checked) ? 'auto' : '');
        manualInput.disabled = (v !== 'manual');
        if (v !== 'manual') manualInput.value = '';
      }
      manualRadio?.addEventListener('change', update);
      autoRadio?.addEventListener('change', update);
      noneRadio?.addEventListener('change', update);
      update();
    }

    wirePinMode(document.querySelector('form[action][method="post"]'));
    document.querySelectorAll('form').forEach(wirePinMode);

    // Create staff panel
    const createStaffPanel = document.getElementById('createStaffPanel');
    const createStaffLabel = document.getElementById('createStaffLabel');
    const createStaffId = document.getElementById('create_staff_id');
    const btnCancelCreateStaff = document.getElementById('btnCancelCreateStaff');

    document.querySelectorAll('.btnCreateStaff').forEach(btn => {
      btn.addEventListener('click', () => {
        const sid = btn.getAttribute('data-staff-id') || '0';
        const lbl = btn.getAttribute('data-staff-label') || '—';
        if (createStaffId) createStaffId.value = sid;
        if (createStaffLabel) createStaffLabel.textContent = lbl;
        createStaffPanel?.classList.remove('hidden');
        createStaffPanel?.scrollIntoView({behavior: 'smooth', block: 'nearest'});
      });
    });
    btnCancelCreateStaff?.addEventListener('click', () => createStaffPanel?.classList.add('hidden'));

    // Agency panel
    const btnCreateAgency = document.getElementById('btnCreateAgency');
    const createAgencyPanel = document.getElementById('createAgencyPanel');
    const btnCancelCreateAgency = document.getElementById('btnCancelCreateAgency');
    btnCreateAgency?.addEventListener('click', () => {
      createAgencyPanel?.classList.toggle('hidden');
      createAgencyPanel?.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    });
    btnCancelCreateAgency?.addEventListener('click', () => createAgencyPanel?.classList.add('hidden'));
  })();
</script>

<?php admin_page_end(); ?>
