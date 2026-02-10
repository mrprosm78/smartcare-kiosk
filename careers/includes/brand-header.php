<?php
// includes/brand-header.php
// Public header (brand-config driven)
// - Shows org logo image if set, else falls back to logo text initials.

require_once __DIR__ . '/helpers.php';

$brand = function_exists('sc_brand') ? sc_brand() : (is_file(__DIR__ . '/brand.php') ? require __DIR__ . '/brand.php' : []);

$containerClass = $brand['ui']['container_class'] ?? 'max-w-5xl';
$showContactBar = !empty($brand['ui']['show_contact_bar']);

$productName  = $brand['product']['name']  ?? 'SmartCare Solutions';
$productShort = $brand['product']['short'] ?? 'SmartCare';

$orgName     = $brand['org']['name'] ?? 'Single Care Home';
$orgShort    = $brand['org']['short'] ?? 'Care Home';
$orgLocation = $brand['org']['location'] ?? 'United Kingdom';

$orgPhone = $brand['org']['phone'] ?? '';
$orgEmail = $brand['org']['email'] ?? '';

// Logo: image first, default SmartCare mark fallback.
// Note: avoid external URLs in shipped configs; prefer sc_asset_url(...) for local assets.
$logoUrl  = $brand['org']['logo']['url'] ?? '';
$logoAlt  = $brand['org']['logo']['alt'] ?? $orgName;
$logoText = $brand['org']['logo_text'] ?? '';
if ($logoText === '') {
    $src = ($orgShort !== '' ? $orgShort : $orgName);
    $src = trim((string)$src);
    $logoText = $src !== '' ? strtoupper(substr($src, 0, 1)) : 'S';
}

$brandSubtitle = $brandSubtitle ?? ($brand['org']['portal_name'] ?? 'Recruitment Portal');
$brandRightHtml = $brandRightHtml ?? '';

$mainName = $orgName ?: $productName;
?>
<header class="border-b border-sc-border bg-white">
    <!-- Row 1 -->
    <div class="<?= sc_e($containerClass); ?> mx-auto px-4 py-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-2 min-w-0">
            <div class="h-8 w-8 rounded-xl bg-sc-primary-soft flex items-center justify-center shrink-0 overflow-hidden">
                <?php if (!empty($logoUrl)): ?>
                    <img
                        src="<?= sc_e($logoUrl); ?>"
                        alt="<?= sc_e($logoAlt); ?>"
                        class="h-full w-full object-contain"
                        loading="lazy"
                    >
                <?php else: ?>
                    <!-- Default mark (used unless a custom logo is provided) -->
                    <svg viewBox="0 0 24 24" class="h-4 w-4 text-sc-primary" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M12 2l7 4v6c0 5-3 9-7 10-4-1-7-5-7-10V6l7-4z"/>
                        <path d="M9 12l2 2 4-4"/>
                    </svg>
                <?php endif; ?>
            </div>

            <div class="leading-tight min-w-0">
                <p class="text-sm font-semibold text-slate-900 truncate">
                    <?= sc_e($mainName); ?>
                </p>

                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <p class="text-[11px] text-sc-text-muted">
                        <?= sc_e($brandSubtitle); ?>
                    </p>

                    <?php if (!empty($brand['product']['show_powered_by'])): ?>
                        <span class="text-slate-300 text-[10px]">·</span>
                        <p class="text-[11px] text-sc-text-muted">
                            Powered by <?= sc_e($productShort ?: $productName); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($brandRightHtml): ?>
            <div class="shrink-0">
                <?= $brandRightHtml; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Row 2: contact strip -->
    <?php if ($showContactBar): ?>
        <div class="border-t border-sc-border bg-white">
            <div class="<?= sc_e($containerClass); ?> mx-auto px-4 py-2">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-sc-text-muted">
                    <?php if ($orgLocation): ?>
                        <span class="inline-flex items-center gap-1">
                            <span class="text-slate-300">📍</span>
                            <span><?= sc_e($orgLocation); ?></span>
                        </span>
                    <?php endif; ?>

                    <?php if ($orgPhone): ?>
                        <span class="inline-flex items-center gap-1">
                            <span class="text-slate-300">☎</span>
                            <span><?= sc_e($orgPhone); ?></span>
                        </span>
                    <?php endif; ?>

                    <?php if ($orgEmail): ?>
                        <span class="inline-flex items-center gap-1">
                            <span class="text-slate-300">@</span>
                            <span><?= sc_e($orgEmail); ?></span>
                        </span>
                    <?php endif; ?>

                    <span class="sm:ml-auto text-[10px] text-sc-text-muted">
                        Careers & applications · Contact the home if you need support
                    </span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</header>
