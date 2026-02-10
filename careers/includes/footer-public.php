<?php
// careers/includes/footer-public.php
// Shared public footer for landing + careers + application wizard.

require_once __DIR__ . '/helpers.php';

$brand = function_exists('sc_brand')
  ? sc_brand()
  : (is_file(__DIR__ . '/brand.php') ? require __DIR__ . '/brand.php' : []);

$containerClass = $containerClass ?? ($brand['ui']['container_class'] ?? 'max-w-5xl');
$containerPx    = $containerPx ?? ($brand['ui']['container_padding_x'] ?? 'px-4');

$orgName = (string)($brand['org']['name'] ?? 'Care Home');
$productShort = (string)($brand['product']['short'] ?? ($brand['product']['name'] ?? 'SmartCare'));

// Sub-app paths (configurable)
$adminPath = defined('APP_ADMIN_PATH') ? trim((string)APP_ADMIN_PATH) : '/dashboard';
if ($adminPath === '') $adminPath = '/dashboard';
if ($adminPath[0] !== '/') $adminPath = '/' . $adminPath;

$careersPath = defined('APP_CAREERS_PATH') ? trim((string)APP_CAREERS_PATH) : '/careers';
if ($careersPath === '') $careersPath = '/careers';
if ($careersPath[0] !== '/') $careersPath = '/' . $careersPath;

// Base path (supports installs in subfolders like /smartcare-kiosk)
// Prefer explicit APP_BASE_PATH, otherwise detect by stripping known sub-app paths
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$configuredBase = defined('APP_BASE_PATH') ? rtrim((string)APP_BASE_PATH, '/') : '';
if ($configuredBase === '/') $configuredBase = '';

$basePath = $configuredBase;
if ($basePath === '') {
  $basePath = '';
  $candidates = [
    rtrim($adminPath, '/') . '/',
    rtrim($careersPath, '/') . '/',
  ];
  foreach ($candidates as $needle) {
    $pos = strpos($scriptName, $needle);
    if ($pos !== false) {
      $basePath = rtrim(substr($scriptName, 0, $pos), '/');
      break;
    }
  }

  // Fallback: directory of current script
  if ($basePath === '') {
    $dir = str_replace('\\', '/', dirname($scriptName));
    $basePath = rtrim($dir, '/');
  }
  if ($basePath === '/') $basePath = '';
}

// Footer links (base-path aware)
$careersUrl = $basePath . rtrim($careersPath, '/') . '/';
$adminUrl   = $basePath . rtrim($adminPath, '/') . '/login.php';
?>
<footer class="border-t border-sc-border bg-white">
  <div class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-4 text-[11px] text-sc-text-muted">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>© <?= date('Y'); ?> <?= sc_e($orgName); ?></div>
      <div class="flex flex-wrap items-center gap-3">
        <a class="hover:text-sc-primary" href="<?= sc_e($careersUrl); ?>">Careers</a>
        <a class="hover:text-sc-primary" href="<?= sc_e($adminUrl); ?>">Admin login</a>
        <span class="text-slate-300">·</span>
        <span>Powered by <?= sc_e($productShort); ?></span>
      </div>
    </div>
  </div>
</footer>
