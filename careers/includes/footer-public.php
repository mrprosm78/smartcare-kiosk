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

// Helpful public links
$base = sc_app_base();
$kioskPath = defined('APP_KIOSK_PATH') ? trim((string)APP_KIOSK_PATH) : '/kiosk';
if ($kioskPath === '') $kioskPath = '/kiosk';
if ($kioskPath[0] !== '/') $kioskPath = '/' . $kioskPath;

$adminPath = defined('APP_ADMIN_PATH') ? trim((string)APP_ADMIN_PATH) : '/dashboard';
if ($adminPath === '') $adminPath = '/dashboard';
if ($adminPath[0] !== '/') $adminPath = '/' . $adminPath;

$kioskUrl = $base . $kioskPath . '/';
$adminUrl = $base . $adminPath . '/login.php';
$careersUrl = sc_careers_url();
?>
<footer class="border-t border-sc-border bg-white">
  <div class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-4 text-[11px] text-sc-text-muted">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>© <?= date('Y'); ?> <?= sc_e($orgName); ?></div>
      <div class="flex flex-wrap items-center gap-3">
        <a class="hover:text-sc-primary" href="<?= sc_e($careersUrl); ?>">Careers</a>
        <a class="hover:text-sc-primary" href="<?= sc_e($kioskUrl); ?>">Kiosk</a>
        <a class="hover:text-sc-primary" href="<?= sc_e($adminUrl); ?>">Dashboard login</a>
        <span class="text-slate-300">·</span>
        <span>Powered by <?= sc_e($productShort); ?></span>
      </div>
    </div>
  </div>
</footer>
