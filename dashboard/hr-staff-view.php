<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$active = admin_url('hr-staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }


if (!function_exists('sc_to_array')) {
  function sc_to_array(mixed $v): mixed {
    // Convert stdClass to array recursively (best-effort). Leave scalars unchanged.
    if (is_object($v)) $v = (array)$v;
    if (!is_array($v)) return $v;
    foreach ($v as $k => $vv) {
      if (is_object($vv)) $v[$k] = (array)$vv;
    }
    return $v;
  }
}

if (!function_exists('sc_yesno')) {
  function sc_yesno(mixed $v): string {
    if ($v === null) return '—';
    $s = strtolower(trim((string)$v));
    if ($s === '') return '—';
    if (in_array($s, ['1','true','yes','y','on'], true)) return 'Yes';
    if (in_array($s, ['0','false','no','n','off'], true)) return 'No';
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('sc_cell')) {
  function sc_cell(string $s): string {
    $t = trim($s);
    if ($t === '') return '<span class="text-slate-500">—</span>';
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
  }
}


function sc_extract_list(mixed $root, array $path): array {
  $cur = $root;

  // Allow stdClass/object payloads by coercing to arrays at each step.
  $cur = sc_to_array($cur);

  foreach ($path as $k) {
    if (is_string($cur) && $cur !== '') {
      $decoded = json_decode($cur, true);
      if (json_last_error() === JSON_ERROR_NONE) $cur = $decoded;
    }
    $cur = sc_to_array($cur);
    if (!is_array($cur) || !array_key_exists($k, $cur)) return [];
    $cur = $cur[$k];
  }

  if (is_string($cur) && $cur !== '') {
    $decoded = json_decode($cur, true);
    if (json_last_error() === JSON_ERROR_NONE) $cur = $decoded;
  }
  $cur = sc_to_array($cur);
  if (!is_array($cur)) return [];

  // Keep only array/object rows, coercing objects to arrays.
  $out = [];
  foreach ($cur as $r) {
    $r = sc_to_array($r);
    if (is_array($r)) $out[] = $r;
  }
  return array_values($out);
}
function sc_row_has_data(mixed $row): bool {
  if (is_object($row)) $row = (array)$row;
  if (!is_array($row)) return false;
  foreach ($row as $v) {
    if (is_array($v) || is_object($v)) continue;
    if (trim((string)$v) !== '') return true;
  }
  return false;
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
function sc_render_work_history(mixed $workHistory): string {
  // Stored as work_history.jobs in the Apply wizard.
  $rows = sc_extract_list($workHistory, ['jobs']);
  // If older records stored the list at the top level, fall back.
  if (!$rows && is_array($workHistory)) {
    $rows = array_values(array_filter($workHistory, fn($r) => is_array($r) || is_object($r)));
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
    $r = sc_to_array($r);
    if (!is_array($r)) continue;
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
    $rows = array_values(array_filter($refs, fn($r) => is_array($r) || is_object($r)));
  }
  $rows = array_values(array_filter($rows, 'sc_row_has_data'));
  if (!$rows) return '<p class="mt-2 text-sm text-slate-500">No references provided.</p>';

  ob_start();
  echo '<div class="mt-3 grid gap-3">';
  $i = 1;
  foreach ($rows as $r) {
    $r = sc_to_array($r);
    if (!is_array($r)) continue;
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

function sc_money($v): string {
  if ($v === null || $v === '') return '—';
  if (is_numeric($v)) return '£' . rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
  return '£' . h2((string)$v);
}

function sc_num($v): string {
  if ($v === null || $v === '') return '—';
  if (is_numeric($v)) return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
  return h2((string)$v);
}

function sc_yesno(mixed $v): string {
  if ($v === null) return '—';
  $s = strtolower(trim((string)$v));
  if ($s === '') return '—';
  if (in_array($s, ['1','true','yes','y','on'], true)) return 'Yes';
  if (in_array($s, ['0','false','no','n','off'], true)) return 'No';
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


function sc_render_value($v): string {
  if ($v === null) return '<span class="text-slate-500">—</span>';
  if (is_bool($v)) return $v ? 'Yes' : 'No';
  if (is_scalar($v)) {
    $s = trim((string)$v);
    return $s === '' ? '<span class="text-slate-500">—</span>' : h2($s);
  }
  $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) $json = '[]';
  return '<pre class="whitespace-pre-wrap break-words rounded-xl border border-slate-200 bg-white p-2 text-xs font-mono text-slate-800">' . h2($json) . '</pre>';
}

function sc_render_kv_table(array $data, array $labelMap = [], array $hideKeys = []): string {
  $rows = [];
  foreach ($data as $k => $v) {
    if (in_array((string)$k, $hideKeys, true)) continue;
    $label = $labelMap[(string)$k] ?? (string)$k;
    $rows[] = '<tr class="border-t border-slate-100">'
      . '<td class="w-56 px-3 py-2 align-top text-xs font-semibold text-slate-600">' . h2($label) . '</td>'
      . '<td class="px-3 py-2 align-top text-sm text-slate-900">' . sc_render_value($v) . '</td>'
      . '</tr>';
  }
  if (!$rows) return '<div class="text-xs text-slate-600">No details available.</div>';
  return '<div class="overflow-x-auto"><table class="min-w-full text-sm">' . implode('', $rows) . '</table></div>';
}

function sc_render_kv_grid(array $map): string {
  $out = '<div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">';
  foreach ($map as $label => $valHtml) {
    $out .= '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 flex items-start justify-between gap-3">';
    $out .= '<div class="text-xs font-semibold text-slate-600 whitespace-nowrap pt-0.5">' . h2((string)$label) . ':</div>';
    $out .= '<div class="font-semibold text-slate-900 text-right break-words">' . $valHtml . '</div>';
    $out .= '</div>';
  }
  $out .= '</div>';
  return $out;
}

$staffId = (int)($_GET['id'] ?? 0);
if ($staffId <= 0) { http_response_code(400); exit('Missing staff id'); }

$stmt = $pdo->prepare("SELECT s.*, d.name AS department_name, t.name AS team_name
  FROM hr_staff s
  LEFT JOIN kiosk_employee_departments d ON d.id = s.department_id
  LEFT JOIN kiosk_employee_teams t ON t.id = s.team_id
  WHERE s.id = ?
  LIMIT 1");
$stmt->execute([$staffId]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) { http_response_code(404); exit('Staff not found'); }

$name = trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''));
if ($name === '') $name = 'Staff #' . $staffId;

$staffCode = trim((string)($s['staff_code'] ?? ''));
if ($staffCode === '') $staffCode = (string)$staffId;

$kiosk = null;
try {
  // LOCKED: kiosk identity links to HR staff via kiosk_employees.hr_staff_id
  $k = $pdo->prepare("SELECT id, employee_code, is_active, archived_at, pin_updated_at
                      FROM kiosk_employees
                      WHERE hr_staff_id = ?
                      ORDER BY id DESC
                      LIMIT 1");
  $k->execute([$staffId]);
  $kiosk = $k->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $kiosk = null;
}


$app = null;
$appPayload = [];
try {
  // LOCKED: application links to staff via hr_applications.hr_staff_id
  $a = $pdo->prepare("SELECT id, status, job_slug, applicant_name, email, phone, submitted_at, created_at, payload_json
                      FROM hr_applications
                      WHERE hr_staff_id = ?
                      ORDER BY id DESC
                      LIMIT 1");
  $a->execute([$staffId]);
  $app = $a->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($app && !empty($app['payload_json'])) {
    $decoded = json_decode((string)$app['payload_json'], true);
    if (is_array($decoded)) $appPayload = $decoded;
  }
} catch (Throwable $e) {
  $app = null;
  $appPayload = [];
}

// Kiosk linking is managed from the Kiosk IDs page.
$errors = [];
$notice = '';

// ===== Update HR Staff department =====
// We currently reuse kiosk_employee_departments as the lookup table.
// HR Staff owns the department_id field (kiosk identity should link to staff, not own HR data).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_department') {
  admin_csrf_verify();

  $deptId = (int)($_POST['department_id'] ?? 0);
  if ($deptId <= 0) {
    $deptId = null; // allow clearing
  }

  if ($deptId !== null) {
    $chk = $pdo->prepare('SELECT id FROM kiosk_employee_departments WHERE id = ? LIMIT 1');
    $chk->execute([$deptId]);
    if (!$chk->fetchColumn()) {
      $errors[] = 'Please choose a valid department.';
    }
  }

  if (!$errors) {
    $upd = $pdo->prepare('UPDATE hr_staff SET department_id = ?, updated_by_admin_id = ? WHERE id = ? LIMIT 1');
    $upd->execute([$deptId, (int)($user['id'] ?? 0), $staffId]);
    header('Location: ' . admin_url('hr-staff-view.php?id=' . $staffId . '&pv=' . time()));
            exit;
  }
}

// Load departments for dropdown
$departments = [];
try {
  $departments = $pdo->query('SELECT id, name FROM kiosk_employee_departments ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $departments = [];
}

// Load active contract summary (by today)
$contract = null;
try {
  $today = gmdate('Y-m-d');
  $c = $pdo->prepare(
    "SELECT id, effective_from, effective_to, contract_json
     FROM hr_staff_contracts
     WHERE staff_id = ?
       AND effective_from <= ?
       AND (effective_to IS NULL OR effective_to >= ?)
     ORDER BY effective_from DESC, id DESC
     LIMIT 1"
  );
  $c->execute([$staffId, $today, $today]);
  $contract = $c->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $contract = null;
}

$contractData = [];
if ($contract && !empty($contract['contract_json'])) {
  $decoded = json_decode((string)$contract['contract_json'], true);
  if (is_array($decoded)) $contractData = $decoded;
}

$uplifts = $contractData['uplifts'] ?? [];
if (!is_array($uplifts)) $uplifts = [];

function uplift_label(array $uplifts, string $key): string {
  $u = $uplifts[$key] ?? null;
  if (!is_array($u)) return '—';
  $type = (string)($u['type'] ?? '');
  $val = $u['value'] ?? null;
  if ($val === null || $val === '') return '—';
  if ($type === 'premium') return '£' . (string)$val . ' premium';
  // multiplier default
  return (string)$val . '×';
}

function fmt_date(?string $d): string {
  $d = trim((string)$d);
  if ($d === '') return '—';
  // keep as-is (YYYY-MM-DD / datetime) to avoid timezone confusion here
  return $d;
}

function render_kv_grid($v): string {
  if (!is_array($v)) {
    $s = trim((string)$v);
    return $s === '' ? '—' : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
  // associative array
  $isAssoc = array_keys($v) !== range(0, count($v) - 1);
  if (!$isAssoc) {
    // list
    $out = '<ul class="list-disc pl-5 space-y-1">';
    foreach ($v as $item) {
      if (is_array($item)) {
        $out .= '<li><pre class="whitespace-pre-wrap break-words text-xs font-mono">' .
                htmlspecialchars(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]', ENT_QUOTES, 'UTF-8') .
                '</pre></li>';
      } else {
        $out .= '<li>' . htmlspecialchars(trim((string)$item), ENT_QUOTES, 'UTF-8') . '</li>';
      }
    }
    $out .= '</ul>';
    return $out;
  }
  $out = '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">';
  foreach ($v as $k => $val) {
    $kk = htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8');
    $out .= '<div class="rounded-xl border border-slate-200 bg-slate-50 p-2">';
    $out .= '<div class="text-[11px] text-slate-600">' . $kk . '</div>';
    if (is_array($val)) {
      $out .= '<div class="mt-1 text-xs text-slate-800">' . render_kv_grid($val) . '</div>';
    } else {
      $vv = trim((string)$val);
      $out .= '<div class="mt-1 text-sm font-semibold text-slate-900">' . ($vv === '' ? '—' : htmlspecialchars($vv, ENT_QUOTES, 'UTF-8')) . '</div>';
    }
    $out .= '</div>';
  }
  $out .= '</div>';
  return $out;
}

// Decode profile JSON (safe)
$profile = [];
if (!empty($s['profile_json'])) {
  $decoded = json_decode((string)$s['profile_json'], true);
  if (is_array($decoded)) $profile = $decoded;
}

// ===== Profile status flags (used for header summary) =====
$flagMissingDept = empty($s['department_id']);
$flagMissingContract = ($contract === null);
$flagMissingKiosk = ($kiosk === null);
$flagKioskInactive = false;
if ($kiosk) {
  $flagKioskInactive = ((int)($kiosk['is_active'] ?? 0) !== 1) || !empty($kiosk['archived_at']);
}

$staffStatus = strtolower(trim((string)($s['status'] ?? 'active')));
if ($staffStatus === '') $staffStatus = 'active';

// Handle staff photo upload (stored in private uploads path)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_photo') {
  admin_csrf_verify();

  if (!isset($_FILES['staff_photo']) || !is_array($_FILES['staff_photo'])) {
    $errors[] = 'Please choose a photo file.';
  } else {
    $f = $_FILES['staff_photo'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errors[] = 'Upload failed.';
    } else {
      $tmp = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);
      if ($size <= 0 || $size > 5 * 1024 * 1024) {
        $errors[] = 'Photo must be less than 5MB.';
      }
      if (!is_uploaded_file($tmp)) {
        $errors[] = 'Invalid upload.';
      }

      $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
      ];
      $mime = '';
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
          $m = finfo_file($fi, $tmp);
          if (is_string($m)) $mime = $m;
          finfo_close($fi);
        }
      }
      if ($mime === '' && function_exists('mime_content_type')) {
        $m = mime_content_type($tmp);
        if (is_string($m)) $mime = $m;
      }
      if ($mime === '' || !isset($allowed[$mime])) {
        $errors[] = 'Photo must be a JPG, PNG, or WEBP image.';
      }

      if (!$errors) {
        $ext = $allowed[$mime];

        $baseCfg = trim(admin_setting_str($pdo, 'uploads_base_path', 'auto'));
        $base = resolve_uploads_base_path($baseCfg);
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'staff_photos';
        if (!is_dir($dir)) {
          @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
          $errors[] = 'Uploads folder is not writable.';
        } else {
          $rand = bin2hex(random_bytes(6));
          $fname = 'staff_' . $staffId . '_' . gmdate('Ymd_His') . '_' . $rand . '.' . $ext;
          $dest = $dir . DIRECTORY_SEPARATOR . $fname;

          if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = 'Unable to save photo.';
          } else {
            // Store relative path under uploads base.
            $rel = 'staff_photos/' . $fname;

            // Optional: delete old photo if it exists under the same base.
            $old = (string)($s['photo_path'] ?? '');
            if ($old !== '' && $old !== $rel) {
              $oldRel = ltrim($old, "/\\");
              if (str_starts_with($oldRel, 'uploads/')) {
                $oldRel = substr($oldRel, strlen('uploads/'));
              }
              $oldFull = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($oldRel, '/\\');
              $oldReal = @realpath($oldFull);
              $baseReal = @realpath($base);
              if ($oldReal && $baseReal && is_file($oldReal)) {
                $prefix = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR;
                if (strpos($oldReal, $prefix) === 0) {
                  @unlink($oldReal);
                }
              }
            }

            $upd = $pdo->prepare("UPDATE hr_staff SET photo_path=?, updated_by_admin_id=? WHERE id=? LIMIT 1");
            $upd->execute([$rel, (int)($user['id'] ?? 0), $staffId]);

            header('Location: ' . admin_url('hr-staff-view.php?id=' . $staffId));
            exit;
          }
        }
      }
    }
  }
}

// Handle staff document upload (stored in private uploads path)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document') {
  admin_csrf_verify();

  $docType = trim((string)($_POST['doc_type'] ?? ''));
  $note = trim((string)($_POST['note'] ?? ''));

  $allowedTypes = [
    'photo_id' => 'Right-to-work / ID',
    'dbs' => 'DBS',
    'cv' => 'CV',
    'training' => 'Training certificate',
    'reference' => 'Reference',
    'other' => 'Other',
  ];
  if (!isset($allowedTypes[$docType])) {
    $errors[] = 'Please choose a valid document type.';
  }

  if (!isset($_FILES['staff_document']) || !is_array($_FILES['staff_document'])) {
    $errors[] = 'Please choose a document file.';
  } else {
    $f = $_FILES['staff_document'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errors[] = 'Upload failed.';
    } else {
      $tmp = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);
      if ($size <= 0 || $size > 12 * 1024 * 1024) {
        $errors[] = 'Document must be less than 12MB.';
      }
      if (!is_uploaded_file($tmp)) {
        $errors[] = 'Invalid upload.';
      }

      $allowedMimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
      ];
      $mime = '';
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
          $m = finfo_file($fi, $tmp);
          if (is_string($m)) $mime = $m;
          finfo_close($fi);
        }
      }
      if ($mime === '' && function_exists('mime_content_type')) {
        $m = mime_content_type($tmp);
        if (is_string($m)) $mime = $m;
      }
      if ($mime === '' || !isset($allowedMimes[$mime])) {
        $errors[] = 'Document must be a PDF, JPG, PNG, or WEBP.';
      }

      if (!$errors) {
        $ext = $allowedMimes[$mime];
        $origName = (string)($f['name'] ?? 'document.' . $ext);
        $origName = trim($origName);
        if ($origName === '') $origName = 'document.' . $ext;
        if (mb_strlen($origName) > 255) $origName = mb_substr($origName, 0, 250) . '.' . $ext;

        $baseCfg = trim(admin_setting_str($pdo, 'uploads_base_path', 'auto'));
        $base = resolve_uploads_base_path($baseCfg);
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'staff_documents' . DIRECTORY_SEPARATOR . 'staff_' . $staffId;
        if (!is_dir($dir)) {
          @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
          $errors[] = 'Uploads folder is not writable.';
        } else {
          $rand = bin2hex(random_bytes(6));
          $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($origName, PATHINFO_FILENAME));
          $safeBase = trim((string)$safeBase, '._-');
          if ($safeBase === '') $safeBase = 'document';
          $fname = $docType . '_' . gmdate('Ymd_His') . '_' . $rand . '_' . $safeBase . '.' . $ext;
          if (mb_strlen($fname) > 180) {
            $fname = $docType . '_' . gmdate('Ymd_His') . '_' . $rand . '.' . $ext;
          }
          $dest = $dir . DIRECTORY_SEPARATOR . $fname;

          if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = 'Unable to save document.';
          } else {
            $rel = 'staff_documents/staff_' . $staffId . '/' . $fname;
            $ins = $pdo->prepare("INSERT INTO hr_staff_documents
              (staff_id, doc_type, original_name, stored_path, mime_type, file_size, note, uploaded_by_admin_id, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([
              $staffId,
              $docType,
              $origName,
              $rel,
              $mime,
              $size,
              $note !== '' ? $note : null,
              (int)($user['id'] ?? 0),
            ]);

            header('Location: ' . admin_url('hr-staff-view.php?id=' . $staffId));
            exit;
          }
        }
      }
    }
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_kiosk') {
  // LOCKED: linking Kiosk IDs to Staff is done from the Kiosk IDs module.
  http_response_code(403);
  exit('Kiosk linking is managed from the Kiosk IDs page.');
}



// Cache-bust staff photo after uploads
$photoV = 0;
if (isset($_GET['pv']) && ctype_digit((string)$_GET['pv'])) $photoV = (int)$_GET['pv'];
if ($photoV <= 0) {
  $ts = (string)($s['updated_at'] ?? '');
  $t = $ts ? strtotime($ts) : false;
  if ($t) $photoV = (int)$t;
}
if ($photoV <= 0) $photoV = time();

// Start page output (must happen after POST handlers so redirects work)
admin_page_start($pdo, 'Staff Profile');

?>
<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
    <div class="mx-auto max-w-7xl space-y-4">

      <!-- Header -->
      <header class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div class="flex items-start gap-4">
            <div class="h-16 w-16 shrink-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
              <?php if (!empty($s['photo_path'])): ?>
                <img src="<?= h2(admin_url('hr-staff-photo.php?id=' . (int)$staffId . '&v=' . $photoV)) ?>" alt="Staff photo" class="h-full w-full object-cover">
              <?php else: ?>
                <div class="flex h-full w-full items-center justify-center text-xs font-semibold text-slate-500">No photo</div>
              <?php endif; ?>
            </div>

            <div class="min-w-0">
              <h1 class="text-xl sm:text-2xl font-semibold text-slate-900">
                <?= h2(trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''))) ?>
              </h1>

              <div class="mt-1 flex flex-wrap items-center gap-2">
                <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                  <?= h2((string)($s['staff_code'] ?? ('SC' . str_pad((string)$staffId, 4, '0', STR_PAD_LEFT)))) ?>
                </span>

                <?php
                  $status = strtolower(trim((string)($s['status'] ?? 'active')));
                  $statusClass = 'border-slate-200 bg-slate-50 text-slate-700';
                  if ($status === 'active') $statusClass = 'border-emerald-200 bg-emerald-50 text-emerald-700';
                  if ($status === 'inactive') $statusClass = 'border-amber-200 bg-amber-50 text-amber-700';
                  if ($status === 'archived') $statusClass = 'border-slate-300 bg-slate-100 text-slate-700';
                ?>
                <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= $statusClass ?>">
                  <?= h2(ucfirst($status)) ?>
                </span>

                <?php if (!empty($deptName)): ?>
                  <span class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    <?= h2((string)$deptName) ?>
                  </span>
                <?php endif; ?>

                <?php if (!empty($s['email'])): ?>
                  <span class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    <?= h2((string)$s['email']) ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($s['phone'])): ?>
                  <span class="inline-flex rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                    <?= h2((string)$s['phone']) ?>
                  </span>
                <?php endif; ?>
              </div>

              <!-- Compact warnings -->
              <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <?php if (!$contract): ?>
                  <span class="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2 py-1 font-semibold text-rose-700">Missing contract</span>
                <?php endif; ?>
                <?php if (empty($s['department_id'])): ?>
                  <span class="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2 py-1 font-semibold text-rose-700">Missing department</span>
                <?php endif; ?>
                <?php if (!$kiosk || empty($kiosk['hr_staff_id'])): ?>
                  <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-1 font-semibold text-amber-700">No kiosk identity linked</span>
                <?php elseif (isset($kiosk['is_active']) && !(int)$kiosk['is_active']): ?>
                  <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-1 font-semibold text-amber-700">Kiosk ID inactive (PIN not set)</span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Top links (right) -->
          <div class="flex flex-wrap items-center gap-2 sm:justify-end">
            <?php if ($app): ?>
              <a class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                 href="<?= h2(admin_url('hr-application.php?id=' . (int)$app['id'])) ?>">View application</a>
            <?php endif; ?>

            <a class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
               href="<?= h2(admin_url('kiosk-ids.php?hr_staff_id=' . (int)$staffId)) ?>">Manage kiosk identity</a>

            <a class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
               href="<?= h2(admin_url('hr-staff-contract.php?staff_id=' . (int)$staffId)) ?>">Contracts</a>

            <a class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
               href="<?= h2(admin_url('hr-staff.php')) ?>">Back to staff</a>
          </div>
        </div>
      </header>

      <!-- Two-column layout -->
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_320px]">

        <!-- LEFT: Application + modules -->
        <div class="space-y-4">

          <!-- Application (render like application view) -->
          <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
              <h2 class="text-base font-semibold text-slate-900">Application details</h2>
              <?php if ($app): ?>
                <span class="text-xs font-semibold text-slate-600">
                  Status: <span class="text-slate-900"><?= h2((string)($app['status'] ?? '—')) ?></span>
                  • Submitted: <span class="text-slate-900"><?= h2((string)($app['submitted_at'] ?? ($app['created_at'] ?? '—'))) ?></span>
                </span>
              <?php else: ?>
                <span class="text-xs text-slate-500">No linked application found</span>
              <?php endif; ?>
            </div>

            <?php if ($app && $appPayload): ?>
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
              ?>

              <div class="mt-3 space-y-3">
                <?php foreach ($sections as $key => $label): ?>
                  <?php
                    $data = $appPayload[$key] ?? null;
                    if (is_string($data)) {
                      $decoded = json_decode($data, true);
                      if (is_array($decoded)) $data = $decoded;
                    }
                    $secId = 'sec_' . $key;
                  ?>

                  <div class="rounded-2xl border border-slate-200 bg-white">
                    <button type="button"
                      class="w-full px-4 py-3 flex items-center justify-between text-left"
                      data-toggle="<?= h2($secId) ?>">
                      <div class="font-semibold text-slate-900"><?= h2($label) ?></div>
                      <div class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                        <span data-icon="<?= h2($secId) ?>" class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-200 bg-slate-50">−</span>
                      </div>
                    </button>

                    <div id="<?= h2($secId) ?>" class="px-4 pb-4">
                      <?php if ($key === 'work_history'): ?>
                        <?= sc_render_work_history($data) ?>
                      <?php elseif ($key === 'references'): ?>
                        <?= sc_render_references($data) ?>
                      <?php elseif ($key === 'education'): ?>
                        <?= sc_render_education($data) ?>
                      <?php else: ?>
                        <?php if (!is_array($data) || !$data): ?>
                          <p class="mt-2 text-sm text-slate-500">No data saved for this section.</p>
                        <?php else: ?>
                          <div class="mt-3 grid gap-2 sm:grid-cols-2 text-sm">
                            <?php foreach ($data as $k => $v): ?>
                              <?php if ($k === 'csrf') continue; ?>
                              <div class="rounded-2xl border border-slate-100 bg-slate-50 px-3 py-2">
                                <div class="text-[11px] uppercase tracking-widest text-slate-500"><?= h2((string)$k) ?></div>
                                <div class="mt-1 font-medium text-slate-900"><?= sc_render_value($v) ?></div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

            <?php elseif ($app): ?>
              <p class="mt-3 text-sm text-slate-500">Application exists but no payload was saved.</p>
            <?php endif; ?>
          </section>

          <!-- Future modules -->
          <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
              <h2 class="text-base font-semibold text-slate-900">Future modules</h2>
              <p class="text-xs text-slate-500">Placeholders (safe to add later)</p>
            </div>

            <?php
              $modules = [
                'training' => ['Training', 'Track courses, expiry and certificates.'],
                'supervision' => ['Supervision', '1:1 notes, sessions and outcomes.'],
                'appraisals' => ['Appraisals', 'Reviews, goals and performance notes.'],
                'hr_issues' => ['HR issues', 'Incidents, disciplinaries and actions.'],
                'absence' => ['Absence', 'Sick / leave tracking with notes and approvals.'],
                'audit' => ['Audit timeline', 'Who changed what, and when (contracts, docs, status).'],
              ];
            ?>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <?php foreach ($modules as $k => [$title, $desc]): ?>
                <?php $mid = 'mod_' . $k; ?>
                <div class="rounded-2xl border border-slate-200 bg-white">
                  <button type="button"
                    class="w-full px-4 py-3 flex items-center justify-between text-left"
                    data-toggle="<?= h2($mid) ?>">
                    <div>
                      <div class="font-semibold text-slate-900"><?= h2($title) ?></div>
                      <div class="mt-0.5 text-xs text-slate-500"><?= h2($desc) ?></div>
                    </div>
                    <span data-icon="<?= h2($mid) ?>" class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-700">−</span>
                  </button>
                  <div id="<?= h2($mid) ?>" class="px-4 pb-4">
                    <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                      Coming soon. This block is intentionally a placeholder.
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

        </div>

        <!-- RIGHT SIDEBAR -->
        <aside class="space-y-4">

          <!-- Contract summary + edit -->
          <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-semibold text-slate-900">Active contract</h3>
              <?php if ($contract): ?>
                <a class="text-xs font-semibold text-slate-700 hover:text-slate-900"
                   href="<?= h2(admin_url('hr-staff-contract-edit.php?staff_id=' . (int)$staffId . '&contract_id=' . (int)$contract['id'])) ?>">Edit</a>
              <?php else: ?>
                <a class="text-xs font-semibold text-slate-700 hover:text-slate-900"
                   href="<?= h2(admin_url('hr-staff-contract.php?staff_id=' . (int)$staffId)) ?>">Add</a>
              <?php endif; ?>
            </div>

            <div class="mt-3 space-y-2 text-sm">
              <div class="flex items-center justify-between">
                <span class="text-slate-600">Pay rate</span>
                <span class="font-semibold text-slate-900"><?= sc_money($contractData['pay_rate'] ?? null) ?></span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-600">Hours / week</span>
                <span class="font-semibold text-slate-900"><?= h2((string)($contractData['contracted_hours_per_week'] ?? '—')) ?></span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-600">Breaks paid</span>
                <span class="font-semibold text-slate-900"><?= h2(sc_yesno($contractData['breaks_paid'] ?? null)) ?></span>
              </div>

              <div class="pt-2">
                <div class="text-xs font-semibold text-slate-700">Uplifts</div>
                <div class="mt-1 text-xs text-slate-700">
                  Weekend: <span class="font-semibold text-slate-900"><?= h2(uplift_label($uplifts,'weekend')) ?></span><br>
                  Bank holiday: <span class="font-semibold text-slate-900"><?= h2(uplift_label($uplifts,'bank_holiday')) ?></span><br>
                  Night: <span class="font-semibold text-slate-900"><?= h2(uplift_label($uplifts,'night')) ?></span><br>
                  Overtime: <span class="font-semibold text-slate-900"><?= h2(uplift_label($uplifts,'overtime')) ?></span><br>
                  Callout: <span class="font-semibold text-slate-900"><?= h2(uplift_label($uplifts,'callout')) ?></span>
                </div>
              </div>

              <?php if ($contract): ?>
                <div class="pt-2 text-xs text-slate-500">
                  Effective: <span class="font-semibold text-slate-700"><?= h2(fmt_date($contract['effective_from'] ?? null)) ?></span>
                  <?php if (!empty($contract['effective_to'])): ?>
                    → <span class="font-semibold text-slate-700"><?= h2(fmt_date($contract['effective_to'] ?? null)) ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <!-- Photo upload -->
          <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-semibold text-slate-900">Staff photo</h3>
            </div>
            <div class="mt-3"><form method="post" enctype="multipart/form-data" class="space-y-2">
                <?php admin_csrf_field(); ?>
                <input type="hidden" name="action" value="upload_photo">
                <input type="file" name="staff_photo" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm" required>
                <button class="w-full rounded-2xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800" type="submit">Upload photo</button>
              </form>
              <p class="mt-2 text-xs text-slate-500">Stored privately (store_*), not publicly accessible.</p>
            </div>
          </section>

          <!-- Document upload -->
          <section id="documents" class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-semibold text-slate-900">Documents</h3>
              <a class="text-xs font-semibold text-slate-700 hover:text-slate-900"
                 href="<?= h2(admin_url('hr-staff-view.php?id=' . (int)$staffId . '#documents')) ?>">Manage</a>
            </div>

            <form method="post" enctype="multipart/form-data" class="mt-3 space-y-2">
              <?php admin_csrf_field(); ?>
              <input type="hidden" name="action" value="upload_document">

              <select name="doc_type" class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" required>
                <option value="">Document type…</option>
                <?php foreach (['photo_id'=>'Right to work / ID','dbs'=>'DBS','cv'=>'CV','reference'=>'Reference','training'=>'Training certificate','other'=>'Other'] as $k => $label): ?>
                  <option value="<?= h2($k) ?>"><?= h2($label) ?></option>
                <?php endforeach; ?>
              </select>

              <input type="file" name="staff_document" class="block w-full text-sm" required>
              <input type="text" name="note" placeholder="Note (optional)" class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">

              <button class="w-full rounded-2xl bg-white px-3 py-2 text-sm font-semibold text-slate-900 border border-slate-200 hover:bg-slate-50" type="submit">
                Upload document
              </button>
            </form>

            <?php
              // show recent docs compact
              $recentDocs = [];
              try {
                $d = $pdo->prepare("SELECT doc_type, original_name, created_at FROM hr_staff_documents WHERE staff_id = ? ORDER BY id DESC LIMIT 3");
                $d->execute([$staffId]);
                $recentDocs = $d->fetchAll(PDO::FETCH_ASSOC);
              } catch (Throwable $e) {
                $recentDocs = [];
              }
            ?>

            <?php if ($recentDocs): ?>
              <div class="mt-3 space-y-2">
                <?php foreach ($recentDocs as $rd): ?>
                  <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="text-xs font-semibold text-slate-700"><?= h2((string)($rd['doc_type'] ?? 'document')) ?></div>
                    <div class="mt-0.5 text-xs text-slate-600 truncate"><?= h2((string)($rd['original_name'] ?? '—')) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="mt-3 text-xs text-slate-500">No documents uploaded yet.</p>
            <?php endif; ?>
          </section>

          <!-- Department (small) -->
          <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Department</h3>
            <form method="post" class="mt-3 space-y-2">
              <?php admin_csrf_field(); ?>
              <input type="hidden" name="action" value="update_department">
              <select name="department_id" class="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" onchange="this.form.submit()">
                <option value="0">—</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" <?= ((int)($s['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                    <?= h2((string)$d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="text-xs text-slate-500">Updates HR staff department (not kiosk identity).</p>
            </form>
          </section>

        </aside>
      </div>
    </div>
  </main>
</div>

<script>
(() => {
  const toggles = document.querySelectorAll('[data-toggle]');
  toggles.forEach(btn => {
    const targetId = btn.getAttribute('data-toggle');
    const body = document.getElementById(targetId);
    const icon = document.querySelector(`[data-icon="${CSS.escape(targetId)}"]`);
    if (!body || !icon) return;

    // default: match current state
    icon.textContent = body.classList.contains('hidden') ? '+' : '−';

    btn.addEventListener('click', () => {
      const isHidden = body.classList.contains('hidden');
      body.classList.toggle('hidden', !isHidden);
      icon.textContent = isHidden ? '−' : '+';
      btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    });
});
})();
</script>

<?php admin_page_end(); ?>
