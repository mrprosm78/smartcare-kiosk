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
    <a href="' . sc_e(sc_app_url('dashboard/login.php')) . '" class="inline-flex items-center rounded-md border border-sc-border bg-white px-2.5 py-1.5 text-[11px] font-medium hover:bg-slate-50">Admin login</a>
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

    <main class="flex-1 bg-sc-bg">
  <div class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-10">
    <div class="grid items-start gap-8 lg:grid-cols-2">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">We are hiring</h1>
        <p class="mt-2 text-sm text-slate-600">
          Full-time and part-time opportunities are available. Apply online — your progress is saved automatically.
        </p>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
          <h2 class="text-sm font-semibold text-slate-900">Roles we recruit for</h2>
          <ul class="mt-3 grid grid-cols-1 gap-2 text-[13px] text-slate-700 sm:grid-cols-2">
            <li class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-sc-primary"></span> Nurse</li>
            <li class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-sc-primary"></span> Care Assistant</li>
            <li class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-sc-primary"></span> Senior Carer</li>
            <li class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-sc-primary"></span> Kitchen</li>
            <li class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-sc-primary"></span> Maintenance</li>
            <li class="flex items-center gap-2"><span class="h-1.5 w-1.5 rounded-full bg-sc-primary"></span> Other roles</li>
          </ul>

          <div class="mt-5">
            <a href="apply.php" class="inline-flex items-center rounded-md bg-sc-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-95">
              Apply for a role
            </a>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-6">
        <h2 class="text-sm font-semibold text-slate-900">What happens next</h2>
        <ol class="mt-3 space-y-3 text-[13px] text-slate-700">
          <li class="flex gap-3"><span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[12px] font-semibold text-slate-700">1</span><span>We review your application against the role requirements.</span></li>
          <li class="flex gap-3"><span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[12px] font-semibold text-slate-700">2</span><span>If shortlisted, we contact you to arrange an interview.</span></li>
          <li class="flex gap-3"><span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[12px] font-semibold text-slate-700">3</span><span>Offers are subject to checks (e.g., references, right-to-work, DBS where required).</span></li>
        </ol>

        <div class="mt-6 rounded-xl bg-slate-50 p-4 text-[12px] text-slate-600">
          <div class="font-semibold text-slate-800">Need support?</div>
          <div class="mt-1">If you have any issues applying, please contact us using the phone or email shown at the top of the page.</div>
        </div>
      </div>
    </div>
  </div>
</main>

    <?php include __DIR__ . '/includes/footer-public.php'; ?>
  </div>
</body>
</html>
