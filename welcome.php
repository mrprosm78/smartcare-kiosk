<?php
declare(strict_types=1);

// Public landing page for the SmartCare care-home portal.
// Note: This page intentionally does not link to the Kiosk.

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
$adminPath = defined('APP_ADMIN_PATH') ? trim((string)APP_ADMIN_PATH) : '/dashboard';
if ($adminPath === '') $adminPath = '/dashboard';
if ($adminPath[0] !== '/') $adminPath = '/' . $adminPath;

$careersUrl   = $basePath . '/careers/';
$adminUrl     = $basePath . $adminPath . '/login.php';

// Future portals (coming soon)
$staffUrl     = $basePath . '/staff/';
$applicantUrl = $basePath . '/applicant/';

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
      // Keep header exactly as provided by the brand system.
      $brandRightHtml = '
        <div class="flex items-center gap-2">
          <a href="' . sc_e($careersUrl) . '" class="text-[11px] text-sc-text-muted hover:text-sc-primary">Careers</a>
          <span class="text-slate-300">·</span>
          <a href="' . sc_e($adminUrl) . '" class="inline-flex items-center rounded-md border border-sc-border bg-white px-2.5 py-1.5 text-[11px] font-medium hover:bg-slate-50">Admin dashboard</a>
        </div>
      ';
      include __DIR__ . '/careers/includes/brand-header.php';
    ?>

    <main class="flex-1">
      <div class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-10">
        <div class="grid gap-10 lg:grid-cols-12 lg:items-center">
          <div class="lg:col-span-6">
            <div class="inline-flex items-center gap-2 rounded-full border border-sc-border bg-white px-3 py-1 text-xs text-sc-text-muted">
              <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
              <span>Secure portal • Audit-ready records</span>
            </div>

            <h1 class="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">
              Welcome to SmartCare
              <span class="block text-sc-text-muted text-xl font-medium mt-2"><?= sc_e($brandSubtitle); ?></span>
            </h1>

            <p class="mt-4 text-slate-600">
              Start a job application, and use the admin dashboard to manage staff records and compliance documents.
            </p>

            
            <div class="mt-7 grid gap-3 sm:grid-cols-2">
              <a href="<?= htmlspecialchars($careersUrl, ENT_QUOTES) ?>"
                 class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-6 py-3 text-white shadow-sm hover:bg-slate-800">
                <span>Apply for jobs</span>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>
                </svg>
              </a>

              <a href="<?= htmlspecialchars($adminUrl, ENT_QUOTES) ?>"
                 class="w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-6 py-3 text-slate-900 shadow-sm hover:bg-slate-50">
                <span>Admin dashboard</span>
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M4 13h6v7H4z"/><path d="M14 4h6v16h-6z"/><path d="M4 4h6v7H4z"/><path d="M14 13h6v-6h-6z"/>
                </svg>
              </a>

              <button type="button" disabled
                 class="w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-6 py-3 text-slate-500 shadow-sm cursor-not-allowed">
                <span>Staff login</span>
                <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600 border border-slate-200">Coming soon</span>
              </button>

              <button type="button" disabled
                 class="w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-6 py-3 text-slate-500 shadow-sm cursor-not-allowed">
                <span>Applicant portal</span>
                <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600 border border-slate-200">Coming soon</span>
              </button>
            </div>

            <div class="mt-7 rounded-2xl border border-sc-border bg-white p-5">
              <div class="text-sm font-semibold">What you can do here</div>
              <ul class="mt-3 grid gap-2 text-sm text-slate-600">
                <li class="flex gap-2">
                  <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-700">1</span>
                  <span><span class="font-medium text-slate-700">Applicants:</span> complete the 7-step job application with validation and review warnings.</span>
                </li>
                <li class="flex gap-2">
                  <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-700">2</span>
                  <span><span class="font-medium text-slate-700">Admins:</span> access the dashboard to review applications and maintain staff records.</span>
                </li>
                <li class="flex gap-2">
                  <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-700">3</span>
                  <span><span class="font-medium text-slate-700">Compliance:</span> store audit-ready information with clear validation and traceable updates.</span>
                </li>
              </ul>
              <div class="mt-4 text-xs text-slate-500">
                Staff portal (<code class="rounded bg-slate-100 px-1 py-0.5">/staff/</code>) and applicant portal (<code class="rounded bg-slate-100 px-1 py-0.5">/applicant/</code>) are planned for a future release.
              </div>
            </div>

          </div>

          <div class="lg:col-span-6">
            <div class="relative overflow-hidden rounded-3xl border border-sc-border bg-white shadow-sm">
              <div class="absolute inset-0 bg-gradient-to-br from-slate-50 via-white to-slate-50"></div>
              <div class="relative p-8 sm:p-10">
                <!-- Inline SVG illustration (no extra assets required) -->
                <svg viewBox="0 0 720 520" class="w-full h-auto" role="img" aria-label="SmartCare illustration">
                  <defs>
                    <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0" stop-color="#0f172a" stop-opacity="0.08"/>
                      <stop offset="1" stop-color="#0f172a" stop-opacity="0.02"/>
                    </linearGradient>
                    <linearGradient id="g2" x1="0" y1="1" x2="1" y2="0">
                      <stop offset="0" stop-color="#10b981" stop-opacity="0.14"/>
                      <stop offset="1" stop-color="#3b82f6" stop-opacity="0.08"/>
                    </linearGradient>
                  </defs>

                  <rect x="40" y="40" width="640" height="440" rx="28" fill="url(#g1)" stroke="#e2e8f0"/>

                  <!-- Left card -->
                  <rect x="90" y="120" width="260" height="290" rx="18" fill="#ffffff" stroke="#e2e8f0"/>
                  <rect x="120" y="155" width="200" height="14" rx="7" fill="#e2e8f0"/>
                  <rect x="120" y="185" width="160" height="10" rx="5" fill="#e2e8f0"/>
                  <rect x="120" y="210" width="190" height="10" rx="5" fill="#e2e8f0"/>
                  <rect x="120" y="255" width="210" height="12" rx="6" fill="#e2e8f0"/>
                  <rect x="120" y="280" width="170" height="10" rx="5" fill="#e2e8f0"/>
                  <rect x="120" y="305" width="195" height="10" rx="5" fill="#e2e8f0"/>
                  <rect x="120" y="350" width="120" height="36" rx="18" fill="#0f172a" fill-opacity="0.85"/>

                  <!-- Right card -->
                  <rect x="380" y="95" width="250" height="340" rx="18" fill="#ffffff" stroke="#e2e8f0"/>
                  <rect x="410" y="130" width="190" height="14" rx="7" fill="#e2e8f0"/>
                  <rect x="410" y="165" width="120" height="10" rx="5" fill="#e2e8f0"/>
                  <rect x="410" y="192" width="170" height="10" rx="5" fill="#e2e8f0"/>
                  <rect x="410" y="240" width="200" height="110" rx="16" fill="url(#g2)" stroke="#e2e8f0"/>
                  <circle cx="450" cy="295" r="18" fill="#10b981" fill-opacity="0.6"/>
                  <circle cx="510" cy="305" r="22" fill="#3b82f6" fill-opacity="0.35"/>
                  <circle cx="560" cy="285" r="14" fill="#0f172a" fill-opacity="0.12"/>

                  <!-- Accent dots -->
                  <circle cx="110" cy="95" r="6" fill="#10b981" fill-opacity="0.7"/>
                  <circle cx="132" cy="95" r="6" fill="#3b82f6" fill-opacity="0.45"/>
                  <circle cx="154" cy="95" r="6" fill="#0f172a" fill-opacity="0.12"/>
                </svg>

                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                  <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                    <div class="text-xs font-semibold text-slate-700">Recruitment</div>
                    <div class="mt-1 text-xs text-slate-600">Validated applications</div>
                  </div>
                  <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                    <div class="text-xs font-semibold text-slate-700">Records</div>
                    <div class="mt-1 text-xs text-slate-600">Staff & compliance</div>
                  </div>
                  <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                    <div class="text-xs font-semibold text-slate-700">Security</div>
                    <div class="mt-1 text-xs text-slate-600">Least-privilege access</div>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

    <?php include __DIR__ . '/careers/includes/footer-public.php'; ?>
  </div>
</body>
</html>
