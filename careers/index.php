<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

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
  <link rel="stylesheet" href="<?= sc_asset_url('kiosk.css'); ?>">
</head>
<body class="bg-slate-50 text-slate-900">
  <div class="mx-auto max-w-5xl px-4 py-10">
    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-3xl font-semibold text-slate-900">Careers</h1>
          <p class="mt-2 text-slate-600">Apply online using our 8-step application form.</p>
        </div>
        <a class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
           href="<?= sc_app_url('admin/'); ?>">
          Manager sign in
        </a>
      </div>

      <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
          <div class="text-sm font-semibold text-slate-900">Care Assistant</div>
          <div class="mt-1 text-xs text-slate-600">Full-time / Part-time</div>
          <a class="mt-3 inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
             href="<?= sc_careers_url('apply.php?job=care-assistant'); ?>">
            Apply now
          </a>
        </div>

        <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
          <div class="text-sm font-semibold text-slate-900">Senior Carer</div>
          <div class="mt-1 text-xs text-slate-600">Days / Nights</div>
          <a class="mt-3 inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
             href="<?= sc_careers_url('apply.php?job=senior-carer'); ?>">
            Apply now
          </a>
        </div>
      </div>

      <p class="mt-6 text-xs text-slate-500">Admin review: Admin → HR Applications</p>
    </div>
  </div>
</body>
</html>
