<?php
declare(strict_types=1);

// Public landing page for the SmartCare care-home portal.
// The kiosk UI lives under /kiosk and the admin backend lives under /dashboard.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Reuse the same public header/footer as Careers (brand-config driven)
require_once __DIR__ . '/careers/includes/helpers.php';

$brand = function_exists('sc_brand')
  ? sc_brand()
  : (is_file(__DIR__ . '/careers/includes/brand.php') ? require __DIR__ . '/careers/includes/brand.php' : []);

$containerClass = $brand['ui']['container_class'] ?? 'max-w-6xl';
$containerPx    = $brand['ui']['container_padding_x'] ?? 'px-4';

$brandSubtitle  = $brand['org']['portal_name'] ?? 'Care Home Portal';


// Prefer configured APP_BASE_PATH; fallback to auto-detect from SCRIPT_NAME.
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$detectedBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($detectedBase === '/') $detectedBase = '';
$configuredBase = defined('APP_BASE_PATH') ? rtrim((string)APP_BASE_PATH, '/') : '';
if ($configuredBase === '/') $configuredBase = '';
$basePath = ($configuredBase !== '') ? $configuredBase : $detectedBase;

// Sub-app paths (configurable)
$kioskPath = defined('APP_KIOSK_PATH') ? trim((string)APP_KIOSK_PATH) : '/kiosk';
if ($kioskPath === '') $kioskPath = '/kiosk';
if ($kioskPath[0] !== '/') $kioskPath = '/' . $kioskPath;

$adminPath = defined('APP_ADMIN_PATH') ? trim((string)APP_ADMIN_PATH) : '/dashboard';
if ($adminPath === '') $adminPath = '/dashboard';
if ($adminPath[0] !== '/') $adminPath = '/' . $adminPath;

$kioskUrl   = $basePath . $kioskPath . '/';
$adminUrl   = $basePath . $adminPath . '/login.php';
$careersUrl = $basePath . '/careers/';

// Asset version (cache-busting)
$cssFile = __DIR__ . '/assets/app.css';
$cssV = is_file($cssFile) ? (string)filemtime($cssFile) : '1';

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SmartCare Portal</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES) ?>/assets/app.css?v=<?= htmlspecialchars($cssV, ENT_QUOTES) ?>">
</head>
<body class="min-h-screen bg-sc-bg text-sc-text antialiased">
  <div class="min-h-screen flex flex-col">
    <?php
      $brandRightHtml = '
        <div class="flex items-center gap-2">
          <a href="' . sc_e($careersUrl) . '" class="text-[11px] text-sc-text-muted hover:text-sc-primary">Careers</a>
          <span class="text-slate-300">·</span>
          <a href="' . sc_e($adminUrl) . '" class="inline-flex items-center rounded-md border border-sc-border bg-white px-2.5 py-1.5 text-[11px] font-medium hover:bg-slate-50">Dashboard login</a>
        </div>
      ';
      include __DIR__ . '/careers/includes/brand-header.php';
    ?>

    <main class="flex-1">
      <div class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-10">
      <div class="grid gap-8 lg:grid-cols-12 lg:items-center">
        <div class="lg:col-span-6">
          <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
            Everything you need to run a care home — in one place.
          </h1>
          <p class="mt-3 text-slate-600">
            HR, staff records, compliance documents, and time tracking — built for audit readiness and day-to-day operations.
          </p>

          <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <a href="<?= htmlspecialchars($adminUrl, ENT_QUOTES) ?>" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-6 py-3 text-white shadow-sm hover:bg-slate-800">
              <span>Go to Dashboard</span>
              <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>
              </svg>
            </a>
            <a href="<?= htmlspecialchars($kioskUrl, ENT_QUOTES) ?>" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-6 py-3 text-slate-900 shadow-sm hover:bg-slate-50">
              <span>Open Kiosk</span>
              <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M8 7h8"/><path d="M8 11h8"/><path d="M8 15h5"/><path d="M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
              </svg>
            </a>
            <a href="<?= htmlspecialchars($careersUrl, ENT_QUOTES) ?>" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-6 py-3 text-slate-900 shadow-sm hover:bg-slate-50">
              <span>Careers</span>
              <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 7h18"/><path d="M5 7l1-3h12l1 3"/><path d="M5 7v13h14V7"/>
              </svg>
            </a>
          </div>

          <p class="mt-6 text-xs text-slate-500">
            Kiosk runs under <code class="rounded bg-slate-100 px-1 py-0.5"><?= htmlspecialchars($kioskPath, ENT_QUOTES) ?></code>
            and the dashboard runs under <code class="rounded bg-slate-100 px-1 py-0.5"><?= htmlspecialchars($adminPath, ENT_QUOTES) ?></code>.
          </p>
        </div>

        <div class="lg:col-span-6">
          <div class="rounded-3xl border bg-white p-6 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-3">
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold text-slate-700">HR</div>
                <div class="mt-1 text-sm text-slate-600">Applications → Staff</div>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold text-slate-700">Staff</div>
                <div class="mt-1 text-sm text-slate-600">Profiles + documents</div>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold text-slate-700">Time</div>
                <div class="mt-1 text-sm text-slate-600">Kiosk → shifts</div>
              </div>
            </div>

            <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-sm font-semibold">Quick links</div>
              <div class="mt-3 grid gap-2">
                <a class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm hover:bg-slate-50" href="<?= htmlspecialchars($adminUrl, ENT_QUOTES) ?>">
                  <span>Dashboard login</span><span class="text-slate-400">→</span>
                </a>
                <a class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm hover:bg-slate-50" href="<?= htmlspecialchars($kioskUrl, ENT_QUOTES) ?>">
                  <span>Clock in / out (kiosk)</span><span class="text-slate-400">→</span>
                </a>
                <a class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm hover:bg-slate-50" href="<?= htmlspecialchars($careersUrl, ENT_QUOTES) ?>">
                  <span>Careers & job applications</span><span class="text-slate-400">→</span>
                </a>
              </div>
            </div>

            <div class="mt-5 text-xs text-slate-500">
              Secure private storage is configured outside the web root (<code class="rounded bg-slate-100 px-1 py-0.5">store_*</code>).
            </div>
          </div>
        </div>
      </div>
    </main>

    <?php include __DIR__ . '/careers/includes/footer-public.php'; ?>
  </div>
</body>
</html>
