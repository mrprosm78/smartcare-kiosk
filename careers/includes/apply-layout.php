<?php
// careers/includes/apply-layout.php

require_once __DIR__ . '/helpers.php';

/**
 * NOTE:
 * - apply.php is the source of truth for $token, $jobSlug, $step, $totalSteps, $currentView, $currentTitle.
 * - This layout must NOT override them from $_GET (to avoid drift vs DB / canonical state).
 */

// These come from apply.php (fallbacks only)
$step         = $step         ?? 1;
$totalSteps   = $totalSteps   ?? 6;
$currentView  = $currentView  ?? null;
$currentTitle = $currentTitle ?? 'Application';
$token        = $token        ?? '';
$jobSlug      = $jobSlug      ?? '';

// Build a base-path safe form action URL (token + step always present)
$qs = [
  'token' => $token,
  'step'  => $step,
];
if ($jobSlug !== '') {
  // Optional reporting-only param
  $qs['job'] = $jobSlug;
}

$formAction = sc_careers_url('apply.php?' . http_build_query($qs));

// Pull brand config (Option A)
$brand = function_exists('sc_brand')
  ? sc_brand()
  : (is_file(__DIR__ . '/brand.php') ? require __DIR__ . '/brand.php' : []);

$containerClass = $brand['ui']['container_class'] ?? 'max-w-5xl';
$containerPx    = $brand['ui']['container_padding_x'] ?? 'px-4';

$submitted = isset($_GET['submitted']) && $_GET['submitted'] == '1';

// Header + shell config
$title = 'Apply · ' . ($brand['product']['name'] ?? 'SmartCare Solutions');
$brandSubtitle = $brand['org']['portal_name'] ?? 'Careers portal';

// Right-side header actions
$brandRightHtml = '
  <div class="flex items-center gap-2">
    <a href="' . sc_e(sc_careers_url()) . '" class="text-[11px] text-sc-primary hover:text-blue-700">← All jobs</a>
    <span class="text-slate-300">·</span>
    <a href="' . sc_e(sc_app_url('admin/')) . '" class="inline-flex items-center rounded-md border border-sc-border bg-white px-2.5 py-1.5 text-[11px] font-medium hover:bg-slate-50">
      Manager sign in
    </a>
  </div>
';

// Progress %
$progress = max(0, min(100, (int) round(($step / max(1, $totalSteps)) * 100)));

// Page header (shared rhythm)
$pageTitle = 'Job application';
$pageSubtitle = 'Please complete each step. You can move back and forth before submitting.';

$pageMetaRightHtml = '
  <p class="text-[11px] text-sc-text-muted leading-4">Step ' . (int)$step . ' of ' . (int)$totalSteps . '</p>
  <p class="text-[11px] text-sc-text-muted leading-4">' . (int)$progress . '% complete</p>
';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= sc_e($title); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?= sc_asset_url('kiosk.css'); ?>">
  <link rel="icon" href="<?= sc_asset_url('careers/favicon.ico'); ?>" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= sc_asset_url('careers/icon.png'); ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= sc_asset_url('careers/icon.png'); ?>">
  <link rel="apple-touch-icon" href="<?= sc_asset_url('careers/icon.png'); ?>">
</head>
<body class="min-h-screen bg-sc-bg text-sc-text antialiased">

<div class="min-h-screen flex flex-col">
  <?php include __DIR__ . '/brand-header.php'; ?>

  <main class="flex-1">
    <section class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-8 space-y-6">
      <?php include __DIR__ . '/page-header.php'; ?>

      <div class="bg-white border border-sc-border rounded-2xl shadow-sm px-5 py-5 space-y-4">
        <header class="flex items-center justify-between gap-4">
          <div class="min-w-0">
            <p class="text-[11px] font-semibold text-sc-text-muted uppercase tracking-[0.16em]">
              Step <?= htmlspecialchars((string)$step); ?> of <?= htmlspecialchars((string)$totalSteps); ?>
            </p>
            <h2 class="text-sm font-semibold text-slate-900 mt-1">
              <?= htmlspecialchars($currentTitle); ?>
            </h2>
          </div>

          <div class="w-40 sm:w-56 shrink-0">
            <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
              <div class="h-full bg-sc-primary" style="width: <?= (int)$progress; ?>%;"></div>
            </div>
            <p class="mt-1 text-[10px] text-right text-sc-text-muted">
              <?= (int)$progress; ?>% complete
            </p>
          </div>
        </header>

        <?php if ($submitted && (int)$step === (int)$totalSteps): ?>
          <section class="mt-2 text-xs space-y-2">
            <p class="font-semibold text-slate-900">
              Thank you – your application has been submitted.
            </p>
            <p class="text-sc-text-muted">
              Your application has been saved securely and sent to the care home manager/HR for review.
            </p>

            <div class="pt-2 flex flex-wrap gap-2">
              <a href="<?= sc_careers_url(); ?>" class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-2 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50">
                Back to jobs
              </a>
              <a href="<?= sc_app_url('admin/'); ?>" class="inline-flex items-center rounded-md bg-sc-primary px-3 py-2 text-[11px] font-medium text-white hover:bg-blue-600">
                Manager sign in
              </a>
            </div>
          </section>
        <?php else: ?>
          <section class="mt-2">
            <?php
            // $currentView is expected to be a filename like "apply-step1-personal.php"
            // or "pages/apply-step1-personal.php" (either is fine)
            $viewFile = $currentView ? basename($currentView) : null;
            $viewPath = $viewFile ? (__DIR__ . '/../pages/' . $viewFile) : null;

            if ($viewPath && file_exists($viewPath)) {
              include $viewPath;
            } else {
              ?>
              <p class="text-xs text-sc-text-muted">
                This step is not yet configured. Please check your step filename/path.
              </p>
              <?php
            }
            ?>
          </section>
        <?php endif; ?>
      </div>

      <p class="text-center text-[10px] text-sc-text-muted">
        Application wizard · Progress is saved to your application token.
      </p>
    </section>
  </main>

  <?php
  $footerFile = __DIR__ . '/footer-public.php';
  if (is_file($footerFile)) {
    include $footerFile;
  } else {
    ?>
    <footer class="border-t border-sc-border bg-white">
      <div class="<?= sc_e($containerClass); ?> mx-auto <?= sc_e($containerPx); ?> py-4 text-[11px] text-sc-text-muted flex justify-between gap-3">
        <span>&copy; <?= date('Y'); ?> <?= sc_e($brand['org']['name'] ?? 'Single care home'); ?></span>
        <span>Powered by <?= sc_e($brand['product']['short'] ?? ($brand['product']['name'] ?? 'SmartCare')); ?></span>
      </div>
    </footer>
    <?php
  }
  ?>
</div>

</body>
</html>
