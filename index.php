<?php
declare(strict_types=1);

// Main app entry point (placeholder).
// The kiosk UI lives under /kiosk.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Prefer configured APP_BASE_PATH; fallback to auto-detect from SCRIPT_NAME.
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$detectedBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($detectedBase === '/') $detectedBase = '';
$configuredBase = defined('APP_BASE_PATH') ? rtrim((string)APP_BASE_PATH, '/') : '';
if ($configuredBase === '/') $configuredBase = '';
$basePath = ($configuredBase !== '') ? $configuredBase : $detectedBase;

$kioskPath = defined('APP_KIOSK_PATH') ? trim((string)APP_KIOSK_PATH) : '/kiosk';
if ($kioskPath === '') $kioskPath = '/kiosk';
if ($kioskPath[0] !== '/') $kioskPath = '/' . $kioskPath;

$adminPath = defined('APP_ADMIN_PATH') ? trim((string)APP_ADMIN_PATH) : '/dashboard';
if ($adminPath === '') $adminPath = '/dashboard';
if ($adminPath[0] !== '/') $adminPath = '/' . $adminPath;

$kioskUrl = $basePath . $kioskPath . '/';
$adminUrl = $basePath . $adminPath . '/';

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SmartCare</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/kiosk.css?v=1">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <div class="mx-auto max-w-3xl p-6">
    <div class="rounded-2xl border bg-white p-6 shadow-sm">
      <h1 class="text-2xl font-semibold">SmartCare</h1>
      <p class="mt-2 text-slate-600">Choose where you want to go.</p>

      <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        <a class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-white hover:bg-slate-800" href="<?= htmlspecialchars($kioskUrl, ENT_QUOTES) ?>">Open Kiosk</a>
        <a class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-slate-900 hover:bg-slate-50" href="<?= htmlspecialchars($adminUrl, ENT_QUOTES) ?>">Open Dashboard</a>
      </div>

      <p class="mt-6 text-xs text-slate-500">
        Kiosk runs under <code><?= htmlspecialchars($kioskPath, ENT_QUOTES) ?></code>.
        Admin/Dashboard runs under <code><?= htmlspecialchars($adminPath, ENT_QUOTES) ?></code>.
      </p>
    </div>
  </div>
</body>
</html>
