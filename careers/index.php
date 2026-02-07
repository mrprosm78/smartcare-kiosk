<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

// Brand / layout config
$brand = function_exists('sc_brand')
  ? sc_brand()
  : (is_file(__DIR__ . '/includes/brand.php') ? require __DIR__ . '/includes/brand.php' : []);

$containerClass = $brand['ui']['container_class'] ?? 'max-w-5xl';
$containerPx    = $brand['ui']['container_padding_x'] ?? 'px-4';

$brandSubtitle  = $brand['org']['portal_name'] ?? 'Careers portal';
$brandRightHtml = '
  <div class="flex items-center gap-2">
    <a href="' . sc_e(sc_app_url()) . '" class="text-[11px] text-sc-text-muted hover:text-sc-primary">← Portal</a>
    <span class="text-slate-300">·</span>
    <a href="' . sc_e(sc_app_url('dashboard/login.php')) . '" class="inline-flex items-center rounded-md border border-sc-border bg-white px-2.5 py-1.5 text-[11px] font-medium hover:bg-slate-50">Dashboard login</a>
  </div>
';

$title = 'Careers';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= sc_e($title); ?></title>
  <link rel="icon" href="<?= sc_asset_url('careers/favicon.ico'); ?>" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= sc_asset_url('careers/icon.png'); ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= sc_asset_url('careers/icon.png'); ?>">
  <link rel="apple-touch-icon" href="<?= sc_asset_url('careers/icon.png'); ?>">
  <link rel="stylesheet" href="<?= sc_asset_url('app.css'); ?>">
</head>
<body class="min-h-screen bg-sc-bg text-sc-text antialiased">
  <div class="min-h-screen flex flex-col">
    <?php include __DIR__ . '/includes/brand-header.php'; ?>

    <main class="flex-1">
      <div class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-10">
        <div class="rounded-3xl border border-sc-border bg-white p-6 shadow-sm">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-3xl font-semibold text-slate-900">Careers</h1>
          <p class="mt-2 text-slate-600">Apply online using our 8-step application form.</p>
        </div>
        <a class="inline-flex items-center rounded-xl border border-sc-border bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
           href="<?= sc_app_url('dashboard/login.php'); ?>">
          Dashboard login
        </a>
      </div>

      <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
          <div class="text-sm font-semibold text-slate-900">Care Assistant</div>
          <div class="mt-1 text-xs text-slate-600">Full-time / Part-time</div>
          <a class="mt-3 inline-flex items-center rounded-xl bg-sc-primary px-4 py-2 text-sm font-semibold text-white hover:bg-sc-primary-hover"
             href="<?= sc_careers_url('apply.php?job=care-assistant'); ?>">
            Apply now
          </a>
        </div>

        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
          <div class="text-sm font-semibold text-slate-900">Senior Carer</div>
          <div class="mt-1 text-xs text-slate-600">Days / Nights</div>
          <a class="mt-3 inline-flex items-center rounded-xl bg-sc-primary px-4 py-2 text-sm font-semibold text-white hover:bg-sc-primary-hover"
             href="<?= sc_careers_url('apply.php?job=senior-carer'); ?>">
            Apply now
          </a>
        </div>
      </div>

      <p class="mt-6 text-xs text-slate-500">Admin review: Admin → HR Applications</p>
        </div>
      </div>
    </main>

    <?php include __DIR__ . '/includes/footer-public.php'; ?>
  </div>
</body>
</html>
