<?php
// careers/apply.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/helpers.php';

// Session handling
if (function_exists('sc_boot_session')) {
  sc_boot_session();
} else {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
}

if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

// Preserve job slug with validation
$jobSlug = isset($_GET['job']) ? (string)$_GET['job'] : '';
$jobSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($jobSlug));

// Validate allowed job slugs (edit this list as you add jobs)
$allowedJobs = ['care-assistant', 'senior-carer'];
if ($jobSlug !== '' && !in_array($jobSlug, $allowedJobs, true)) {
  $jobSlug = '';
}

// Steps configuration
$totalSteps = 8;
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1) $step = 1;
if ($step > $totalSteps) $step = $totalSteps;

// Public token support (works across devices / after refresh)
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$token = preg_replace('/[^a-f0-9]/', '', strtolower($token)); // token is hex

// Ensure DB exists
if (!isset($pdo) || !($pdo instanceof PDO)) {
  die('Database connection error. Please try again later.');
}

/**
 * Create a new draft application row and return token + id.
 */
function careers_create_draft(PDO $pdo, string $jobSlug): array {
  $token = bin2hex(random_bytes(16));
  $stmt = $pdo->prepare("INSERT INTO hr_applications
    (public_token, status, job_slug, applicant_name, email, phone, payload_json, submitted_at, created_at, updated_at)
    VALUES
    (?, 'draft', ?, '', '', '', '{}', NULL, NOW(), NOW())");
  $stmt->execute([$token, $jobSlug]);
  return [$token, (int)$pdo->lastInsertId()];
}

/**
 * Load application id by token; returns int id or 0.
 */
function careers_load_id_by_token(PDO $pdo, string $token): int {
  if ($token === '') return 0;
  $stmt = $pdo->prepare("SELECT id FROM hr_applications WHERE public_token = ? LIMIT 1");
  $stmt->execute([$token]);
  $id = (int)($stmt->fetchColumn() ?: 0);
  return $id;
}

// If token missing OR invalid, create and redirect to token URL (prevents "not found" issues)
$appId = 0;
if ($token !== '') {
  $appId = careers_load_id_by_token($pdo, $token);
}

if ($token === '' || $appId <= 0) {
  try {
    [$token, $appId] = careers_create_draft($pdo, $jobSlug);
  } catch (Throwable $e) {
    error_log('HR draft create failed: ' . $e->getMessage());
    die('Unable to create application. Please try again.');
  }

  // Persist to session for convenience (but token is source of truth)
  $_SESSION['careers_public_token'] = $token;
  $_SESSION['careers_app_id'] = $appId;

  $qs = [
    'token' => $token,
    'step' => (string)$step,
  ];
  if ($jobSlug !== '') $qs['job'] = $jobSlug;

  header('Location: apply.php?' . http_build_query($qs));
  exit;
}

// Persist token/id to session for convenience
$_SESSION['careers_public_token'] = $token;
$_SESSION['careers_app_id'] = $appId;

// Load saved payload into session (so the step pages render prefilled values)
$stmt = $pdo->prepare("SELECT payload_json, job_slug, status FROM hr_applications WHERE id = ? LIMIT 1");
$stmt->execute([$appId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$payloadJson = (string)($row['payload_json'] ?? '{}');
$savedJob = (string)($row['job_slug'] ?? '');
$status = (string)($row['status'] ?? 'draft');

$payload = json_decode($payloadJson, true);
if (!is_array($payload)) $payload = [];

// Prefer DB job if present
if ($savedJob !== '') $jobSlug = $savedJob;

// Step keys mapping (must match the page names)
$stepMap = [
  1 => 'personal',
  2 => 'role',
  3 => 'work_history',
  4 => 'education',
  5 => 'references',
  6 => 'checks',
  7 => 'review',
  8 => 'declaration',
];

$currentKey = $stepMap[$step] ?? 'personal';

// Ensure session storage exists
if (!isset($_SESSION['application']) || !is_array($_SESSION['application'])) {
  $_SESSION['application'] = [];
}

// Merge DB payload into session, but let session override (in-progress edits)
foreach ($payload as $k => $v) {
  if (!isset($_SESSION['application'][$k]) && is_array($v)) {
    $_SESSION['application'][$k] = $v;
  }
}

// Handle POST submission for this step
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

// CSRF (uses careers/includes/helpers.php)
if (!function_exists('sc_csrf_verify')) {
  die('Security error: CSRF not available.');
}
sc_csrf_verify($_POST['csrf'] ?? null);


  $data = $_POST;
  unset($data['csrf'], $data['token'], $data['job'], $data['step']);

  if (!is_array($data)) $data = [];
  if (!isset($_SESSION['application'][$currentKey]) || !is_array($_SESSION['application'][$currentKey])) {
    $_SESSION['application'][$currentKey] = [];
  }

  // Merge posted values for this step
  foreach ($data as $k => $v) {
    $_SESSION['application'][$currentKey][$k] = $v;
  }

  // Persist to DB every step (draft save)
  try {
    $appData = $_SESSION['application'];

    // Convenience: fill summary columns
    $applicantName = '';
    $email = '';
    $phone = '';
    if (isset($appData['personal']) && is_array($appData['personal'])) {
      $p = $appData['personal'];
      $first = trim((string)($p['first_name'] ?? ''));
      $last = trim((string)($p['last_name'] ?? ''));
      $applicantName = trim($first . ' ' . $last);
      $email = trim((string)($p['email'] ?? ''));
      $phone = trim((string)($p['phone'] ?? ''));
    }

    $stmt = $pdo->prepare("UPDATE hr_applications
      SET job_slug = ?, applicant_name = ?, email = ?, phone = ?, payload_json = ?, updated_at = NOW()
      WHERE id = ?
      LIMIT 1");
    $stmt->execute([$jobSlug, $applicantName, $email, $phone, json_encode($appData, JSON_UNESCAPED_SLASHES), $appId]);

    // Final submit on step 8
    if ($step === 8) {
      $pdo->prepare("UPDATE hr_applications SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1")
          ->execute([$appId]);
      header('Location: apply.php?' . http_build_query(['token'=>$token, 'job'=>$jobSlug, 'submitted'=>'1']));
      exit;
    }
  } catch (Throwable $e) {
    error_log('HR save failed: ' . $e->getMessage());
  }

  // Go next step
  $next = min($totalSteps, $step + 1);
  $qs = ['token'=>$token, 'step'=>(string)$next];
  if ($jobSlug !== '') $qs['job'] = $jobSlug;
  header('Location: apply.php?' . http_build_query($qs));
  exit;
}

// Submitted screen
if (!empty($_GET['submitted'])) {
  include __DIR__ . '/includes/apply-layout.php';
  exit;
}

// Determine view file
$currentView = 'pages/apply-step' . $step . '-' . $currentKey . '.php';
if (!file_exists(__DIR__ . '/' . $currentView)) {
  die('Application step not found.');
}

// Include the layout template (it will include the step view)
$layoutFile = __DIR__ . '/includes/apply-layout.php';
if (!file_exists($layoutFile)) {
  die('Layout template not found.');
}

include $layoutFile;
