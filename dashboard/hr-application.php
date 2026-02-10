<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

// Managers can update status for now (future: permission table for fine-grained control)
admin_require_perm($user, 'view_hr_applications');

$active = admin_url('hr-applications.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function sc_yesno(mixed $v): string {
  if ($v === null) return '—';
  $s = strtolower(trim((string)$v));
  if ($s === '') return '—';
  if (in_array($s, ['1','true','yes','y','on'], true)) return 'Yes';
  if (in_array($s, ['0','false','no','n','off'], true)) return 'No';
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function sc_fmt_month_year(?string $month, ?string $year): string {
  $m = trim((string)$month);
  $y = trim((string)$year);
  if ($m === '' && $y === '') return '';
  if ($m !== '' && ctype_digit($m)) {
    $mi = (int)$m;
    if ($mi >= 1 && $mi <= 12) {
      $m = date('M', mktime(0, 0, 0, $mi, 1, 2000));
    }
  }
  return trim($m . ' ' . $y);
}

function sc_fmt_period(array $row): string {
  $start = sc_fmt_month_year($row['start_month'] ?? null, $row['start_year'] ?? null);
  $end = sc_fmt_month_year($row['end_month'] ?? null, $row['end_year'] ?? null);
  if ($start === '' && $end === '') return '—';
  if ($end === '') $end = 'Present';
  if ($start === '') return '—';
  return $start . ' – ' . $end;
}

function sc_cell(string $s): string {
  $t = trim($s);
  if ($t === '') return '<span class="text-slate-500">—</span>';
  return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

/**
 * Many sections store repeating rows inside a nested key (e.g. work_history.jobs).
 * This helper safely extracts that list and tolerates JSON strings.
 */
function sc_extract_list(mixed $root, array $path): array {
  $cur = $root;
  foreach ($path as $k) {
    if (is_string($cur) && $cur !== '') {
      $decoded = json_decode($cur, true);
      if (json_last_error() === JSON_ERROR_NONE) $cur = $decoded;
    }
    if (!is_array($cur) || !array_key_exists($k, $cur)) return [];
    $cur = $cur[$k];
  }
  if (is_string($cur) && $cur !== '') {
    $decoded = json_decode($cur, true);
    if (json_last_error() === JSON_ERROR_NONE) $cur = $decoded;
  }
  if (!is_array($cur)) return [];
  return array_values(array_filter($cur, fn($r) => is_array($r)));
}

/**
 * Returns true if the row has at least one non-empty value.
 */
function sc_row_has_data(array $row): bool {
  foreach ($row as $v) {
    if (is_array($v)) continue;
    if (trim((string)$v) !== '') return true;
  }
  return false;
}

function sc_render_work_history(mixed $workHistory): string {
  // Stored as work_history.jobs in the Apply wizard.
  $rows = sc_extract_list($workHistory, ['jobs']);
  // If older records stored the list at the top level, fall back.
  if (!$rows && is_array($workHistory)) {
    $rows = array_values(array_filter($workHistory, fn($r) => is_array($r)));
  }
  // Remove completely blank rows (common when user leaves default empty row).
  $rows = array_values(array_filter($rows, 'sc_row_has_data'));
  if (!$rows) return '<p class="mt-2 text-sm text-slate-500">No work history provided.</p>';

  ob_start();
  echo '<div class="mt-3 overflow-hidden rounded-2xl border border-slate-200">';
  echo '<table class="w-full text-sm">';
  echo '<thead class="bg-slate-50 text-xs text-slate-600">';
  echo '<tr>';
  echo '<th class="p-2 text-left font-semibold">Employer</th>';
  echo '<th class="p-2 text-left font-semibold">Job title</th>';
  echo '<th class="p-2 text-left font-semibold">Location</th>';
  echo '<th class="p-2 text-left font-semibold">Period</th>';
  echo '<th class="p-2 text-left font-semibold">Care role</th>';
  echo '<th class="p-2 text-left font-semibold">Contact now</th>';
  echo '</tr>';
  echo '</thead><tbody class="divide-y divide-slate-100">';

  foreach ($rows as $r) {
    echo '<tr class="bg-white align-top">';
    echo '<td class="p-2">' . sc_cell((string)($r['employer_name'] ?? '')) . '</td>';
    echo '<td class="p-2">' . sc_cell((string)($r['job_title'] ?? '')) . '</td>';
    echo '<td class="p-2">' . sc_cell((string)($r['employer_location'] ?? '')) . '</td>';
    echo '<td class="p-2">' . sc_cell(sc_fmt_period($r)) . '</td>';
    echo '<td class="p-2">' . sc_yesno($r['is_care_role'] ?? null) . '</td>';
    echo '<td class="p-2">' . sc_yesno($r['can_contact_now'] ?? null) . '</td>';
    echo '</tr>';

    $duties = trim((string)($r['main_duties'] ?? ''));
    $leave = trim((string)($r['reason_for_leaving'] ?? ''));
    $org = trim((string)($r['organisation_type'] ?? ''));
    if ($duties !== '' || $leave !== '' || $org !== '') {
      echo '<tr class="bg-slate-50">';
      echo '<td class="p-2 text-xs text-slate-600" colspan="6">';
      echo '<div class="grid gap-2 sm:grid-cols-3">';
      echo '<div><span class="font-semibold">Organisation type:</span> ' . sc_cell($org) . '</div>';
      echo '<div class="sm:col-span-2"><span class="font-semibold">Main duties:</span> ' . sc_cell($duties) . '</div>';
      echo '<div class="sm:col-span-3"><span class="font-semibold">Reason for leaving:</span> ' . sc_cell($leave) . '</div>';
      echo '</div>';
      echo '</td>';
      echo '</tr>';
    }
  }

  echo '</tbody></table></div>';
  return (string)ob_get_clean();
}

function sc_render_references(mixed $refs): string {
  // Stored as references.references in the Apply wizard.
  $rows = sc_extract_list($refs, ['references']);
  // If older records stored the list at the top level, fall back.
  if (!$rows && is_array($refs)) {
    $rows = array_values(array_filter($refs, fn($r) => is_array($r)));
  }
  $rows = array_values(array_filter($rows, 'sc_row_has_data'));
  if (!$rows) return '<p class="mt-2 text-sm text-slate-500">No references provided.</p>';

  ob_start();
  echo '<div class="mt-3 grid gap-3">';
  $i = 1;
  foreach ($rows as $r) {
    $name = trim((string)($r['referee_name'] ?? ($r['name'] ?? '')));
    $org = trim((string)($r['referee_organisation'] ?? ($r['organisation'] ?? ($r['company'] ?? ''))));
    $role = trim((string)($r['referee_job_title'] ?? ($r['job_title'] ?? ($r['position'] ?? ''))));
    $rel = trim((string)($r['relationship'] ?? ''));
    $email = trim((string)($r['referee_email'] ?? ($r['email'] ?? '')));
    $phone = trim((string)($r['referee_phone'] ?? ($r['phone'] ?? '')));
    $contact = $r['can_contact_now'] ?? ($r['can_contact'] ?? null);

    echo '<div class="rounded-2xl border border-slate-200 bg-white p-4">';
    echo '<div class="flex items-start justify-between gap-3">';
    echo '<div class="min-w-0">';
    echo '<div class="text-sm font-semibold text-slate-900">Reference ' . $i . ($name !== '' ? ': ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '') . '</div>';
    $sub = trim($org . ($role !== '' ? ' · ' . $role : ''));
    if ($sub !== '') echo '<div class="mt-0.5 text-xs text-slate-600">' . htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div>';
    echo '<div class="shrink-0 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">Contact: ' . sc_yesno($contact) . '</div>';
    echo '</div>';

    echo '<div class="mt-3 grid gap-2 sm:grid-cols-3 text-sm">';
    echo '<div><div class="text-[11px] uppercase tracking-widest text-slate-500">Relationship</div><div class="mt-1 font-medium">' . sc_cell($rel) . '</div></div>';
    echo '<div><div class="text-[11px] uppercase tracking-widest text-slate-500">Email</div><div class="mt-1 font-medium">' . sc_cell($email) . '</div></div>';
    echo '<div><div class="text-[11px] uppercase tracking-widest text-slate-500">Phone</div><div class="mt-1 font-medium">' . sc_cell($phone) . '</div></div>';
    echo '</div>';
    echo '</div>';
    $i++;
  }
  echo '</div>';
  return (string)ob_get_clean();
}

function sc_render_education(mixed $edu): string {
  if (is_string($edu)) {
    $d = json_decode($edu, true);
    if (is_array($d)) $edu = $d;
  }
  if (!is_array($edu)) {
    return '<p class="mt-2 text-sm text-slate-500">No education details provided.</p>';
  }

  $level = trim((string)($edu['highest_education_level'] ?? ''));
  $quals = sc_extract_list($edu, ['qualifications']);
  $quals = array_values(array_filter($quals, 'sc_row_has_data'));
  $regs = sc_extract_list($edu, ['registrations']);
  $regs = array_values(array_filter($regs, 'sc_row_has_data'));

  ob_start();
  if ($level !== '') {
    echo '<div class="mt-2 text-sm"><span class="text-slate-500">Highest level:</span> <span class="font-semibold text-slate-900">' . htmlspecialchars($level, ENT_QUOTES, 'UTF-8') . '</span></div>';
  }

  // Qualifications
  echo '<div class="mt-4">';
  echo '<div class="text-sm font-semibold text-slate-900">Qualifications</div>';
  if (!$quals) {
    echo '<p class="mt-2 text-sm text-slate-500">No qualifications provided.</p>';
  } else {
    echo '<div class="mt-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white">';
    echo '<table class="min-w-full text-sm"><thead class="bg-slate-50 text-xs text-slate-600">';
    echo '<tr>';
    echo '<th class="px-3 py-2 text-left">Qualification</th>';
    echo '<th class="px-3 py-2 text-left">Awarding body</th>';
    echo '<th class="px-3 py-2 text-left">Year</th>';
    echo '</tr></thead><tbody class="divide-y divide-slate-200">';
    foreach ($quals as $q) {
      echo '<tr>';
      echo '<td class="px-3 py-2">' . sc_cell((string)($q['qualification_name'] ?? '')) . '</td>';
      echo '<td class="px-3 py-2">' . sc_cell((string)($q['qualification_awarding_body'] ?? '')) . '</td>';
      echo '<td class="px-3 py-2">' . sc_cell((string)($q['qualification_year'] ?? '')) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }
  echo '</div>';

  // Professional registrations
  echo '<div class="mt-4">';
  echo '<div class="text-sm font-semibold text-slate-900">Professional registrations</div>';
  if (!$regs) {
    echo '<p class="mt-2 text-sm text-slate-500">No registrations provided.</p>';
  } else {
    echo '<div class="mt-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white">';
    echo '<table class="min-w-full text-sm"><thead class="bg-slate-50 text-xs text-slate-600">';
    echo '<tr>';
    echo '<th class="px-3 py-2 text-left">Registration</th>';
    echo '<th class="px-3 py-2 text-left">Number</th>';
    echo '<th class="px-3 py-2 text-left">Expiry</th>';
    echo '</tr></thead><tbody class="divide-y divide-slate-200">';
    foreach ($regs as $r) {
      echo '<tr>';
      echo '<td class="px-3 py-2">' . sc_cell((string)($r['registration_name'] ?? '')) . '</td>';
      echo '<td class="px-3 py-2">' . sc_cell((string)($r['registration_number'] ?? '')) . '</td>';
      echo '<td class="px-3 py-2">' . sc_cell((string)($r['registration_expiry_date'] ?? '')) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }
  echo '</div>';

  return (string)ob_get_clean();
}

/** Render scalar/array values consistently for audit-friendly display. */
function sc_render_value(mixed $v): string {
  if ($v === null) return '<span class="text-slate-500">—</span>';
  if (is_bool($v)) return $v ? 'Yes' : 'No';
  if (is_array($v)) {
    // Pretty-print arrays/objects (e.g., work history, references) so they are readable.
    $json = json_encode(
      $v,
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    if ($json === false) $json = '[]';
    $safe = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
    return '<pre class="whitespace-pre-wrap break-words rounded-xl border border-slate-200 bg-white p-2 text-xs font-mono text-slate-800">' . $safe . '</pre>';
  }

  // Scalar / string
  $s = trim((string)$v);
  if ($s === '') return '<span class="text-slate-500">—</span>';
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing application id');
}

/** Check if a column exists (safe across installs). */
function sc_col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$hasHrStaffId = sc_col_exists($pdo, 'hr_applications', 'hr_staff_id');


$stmt = $pdo->prepare("SELECT * FROM hr_applications WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
  http_response_code(404);
  exit('Application not found');
}

$payload = [];
if (!empty($app['payload_json'])) {
  $decoded = json_decode((string)$app['payload_json'], true);
  if (is_array($decoded)) $payload = $decoded;
}

 

// Determine if already converted (LOCKED: prefer hr_applications.hr_staff_id)
$staffId = null;
if ($hasHrStaffId && !empty($app['hr_staff_id'])) {
  $staffId = (int)$app['hr_staff_id'];
} else {
  // Legacy fallback (do not create new installs with this)
  try {
    $chk = $pdo->prepare("SELECT employee_id FROM hr_staff_profiles WHERE application_id = ? LIMIT 1");
    $chk->execute([$id]);
    $legacy = $chk->fetchColumn();
    if ($legacy !== false && $legacy !== null && $legacy !== '') {
      $staffId = (int)$legacy;
    }
  } catch (Throwable $e) {
    $staffId = null;
  }
}

// ===== Actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  // Status / details updates are allowed for managers (future: granular permission table)
  if ($action === 'update_status') {
    if ($staffId !== null) {
      http_response_code(400);
      exit('Application is locked after conversion to staff');
    }
    admin_require_perm($user, 'manage_hr_applications');

    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $allowed = ['draft','submitted','reviewing','rejected','hired','archived'];
    if (!in_array($newStatus, $allowed, true)) {
      http_response_code(400);
      exit('Invalid status');
    }

    $upd = $pdo->prepare("UPDATE hr_applications SET status = ? WHERE id = ? LIMIT 1");
    $upd->execute([$newStatus, $id]);

    header('Location: ' . admin_url('hr-application.php?id=' . $id));
    exit;
  }

  // Convert to staff (enabled only when hired + not already converted)
  if ($action === 'convert_to_staff') {
    admin_require_perm($user, 'manage_hr_applications');
    admin_require_perm($user, 'manage_staff');

    if (!$hasHrStaffId) {
      http_response_code(500);
      exit('Database is missing hr_applications.hr_staff_id. Please run setup.php?action=install once.');
    }
    if ((string)($app['status'] ?? '') !== 'hired') {
      http_response_code(400);
      exit('Only hired applications can be converted to staff.');
    }
    if ($staffId !== null && $staffId > 0) {
      http_response_code(400);
      exit('This application has already been converted.');
    }

    try {
      $pdo->beginTransaction();

      // hr_applications stores a single applicant_name. hr_staff stores first/last.
      $applicantName = trim((string)($app['applicant_name'] ?? ''));
      $firstName = $applicantName;
      $lastName = '';
      if ($applicantName !== '' && preg_match('/\s+/', $applicantName)) {
        $parts = preg_split('/\s+/', $applicantName);
        $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        if (count($parts) >= 2) {
          $lastName = array_pop($parts);
          $firstName = trim(implode(' ', $parts));
        }
      }
      if ($firstName === '') {
        $firstName = 'Unknown';
      }

      $ins = $pdo->prepare(
        "INSERT INTO hr_staff (first_name, last_name, email, phone, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
      );
      $ins->execute([
        $firstName,
        $lastName,
        (string)($app['email'] ?? ''),
        (string)($app['phone'] ?? ''),
      ]);
      $newStaffId = (int)$pdo->lastInsertId();

      // Staff code (LOCKED): SC prefix + 4-digit ID (SC0001).
      // Stored as a string in hr_staff.staff_code for audit-friendly display.
      try {
        $sc = $pdo->prepare('UPDATE hr_staff SET staff_code = ? WHERE id = ? AND (staff_code IS NULL OR staff_code = "") LIMIT 1');
        $scCode = 'SC' . str_pad((string)$newStaffId, 4, '0', STR_PAD_LEFT);
        $sc->execute([$scCode, $newStaffId]);
      } catch (Throwable $e) {
        // ignore if column/index not present on older installs
      }

      $link = $pdo->prepare("UPDATE hr_applications SET hr_staff_id = ? WHERE id = ? AND hr_staff_id IS NULL LIMIT 1");
      $link->execute([$newStaffId, $id]);

      $pdo->commit();

      header('Location: ' . admin_url('hr-staff-view.php?id=' . $newStaffId));
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      http_response_code(500);
      exit('Conversion failed: ' . $e->getMessage());
    }
  }

  http_response_code(400);
  exit('Unknown action');
}



admin_page_start($pdo, 'HR Application');
?>
<div class="min-h-dvh flex">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="flex-1 p-8">
    <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Application #<?= (int)$app['id'] ?></h1>
              <p class="mt-1 text-sm text-slate-600">Submitted application (immutable). You can update status and convert to staff when hired.</p>

              <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                  Application #<?= (int)$app['id'] ?>
                </span>

                <?php if (!empty($app['email'])): ?>
                  <span class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    <?= h((string)$app['email']) ?>
                  </span>
                <?php endif; ?>

                <?php if (!empty($app['phone'])): ?>
                  <span class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    <?= h((string)$app['phone']) ?>
                  </span>
                <?php endif; ?>

                <form method="post" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1">
                  <?php admin_csrf_field(); ?>
                  <input type="hidden" name="action" value="update_status">
                  <span class="text-xs font-semibold text-slate-600">Status</span>
                  <select name="status" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs" <?= ($staffId !== null) ? "disabled" : "" ?> onchange="this.form.submit()">
                    <?php foreach (['draft','submitted','reviewing','rejected','hired','archived'] as $s): ?>
                      <option value="<?= h($s) ?>" <?= ((string)($app['status'] ?? '') === $s) ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>

                <?php if ($staffId !== null && $staffId > 0): ?>
                  <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                    Converted to staff
                  </span>
                  <a href="<?= h(admin_url('hr-staff-view.php?id=' . (int)$staffId)) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-1 text-xs font-semibold hover:bg-slate-50">View staff</a>
                <?php elseif ((string)($app['status'] ?? '') === 'hired' && $hasHrStaffId): ?>
                  <form method="post" class="inline">
                    <?php admin_csrf_field(); ?>
                    <input type="hidden" name="action" value="convert_to_staff">
                    <button class="rounded-xl bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-500" type="submit">
                      Convert to staff
                    </button>
                  </form>
                <?php endif; ?>
              </div>

	    </div>
	  </div>
	</div>

        <?php

        $sections = [
          'personal'     => 'Step 1 — Personal',
          'role'         => 'Step 2 — Role & availability',
          'work_history' => 'Step 3 — Work history',
          'education'    => 'Step 4 — Education & training',
          'references'   => 'Step 5 — References',
          'checks'       => 'Step 6 — Right to work & checks',
          'declaration'  => 'Step 8 — Declaration',
        ];

        foreach ($sections as $key => $label):
          // Section payloads can be arrays or JSON strings depending on how they were saved.
          $data = $payload[$key] ?? null;
        ?>
          <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold"><?= h($label) ?></h2>
            <?php
              // Renderers return an appropriate "No data..." message, so we don't need a generic empty check
              // for the structured sections.
            ?>

            <?php if ($key === 'work_history'): ?>
              <?= sc_render_work_history($data) ?>
            <?php elseif ($key === 'references'): ?>
              <?= sc_render_references($data) ?>
            <?php elseif ($key === 'education'): ?>
              <?= sc_render_education($data) ?>
            <?php else: ?>
              <?php
                if (is_string($data)) {
                  $decoded = json_decode($data, true);
                  if (is_array($decoded)) $data = $decoded;
                }
              ?>

              <?php if (!is_array($data) || !$data): ?>
                <p class="mt-2 text-sm text-slate-500">No data saved for this section.</p>
              <?php else: ?>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 text-sm">
                  <?php foreach ($data as $k => $v): ?>
                    <?php if ($k === 'csrf') continue; ?>
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 px-3 py-2">
                      <div class="text-[11px] uppercase tracking-widest text-slate-500"><?= h((string)$k) ?></div>
                      <div class="mt-1 font-medium text-slate-900">
                        <?= sc_render_value($v) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
    </div>
  </main>
</div>
<?php admin_page_end(); ?>
