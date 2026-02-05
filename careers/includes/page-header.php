<?php
// includes/page-header.php
// Shared page title/subtitle header

$pageTitle = $pageTitle ?? 'Page';
$pageSubtitle = $pageSubtitle ?? '';
$pageMetaRightHtml = $pageMetaRightHtml ?? '';
$pageActionsHtml = $pageActionsHtml ?? '';
?>
<header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        <h2 class="text-lg font-semibold text-slate-900 leading-tight">
            <?= htmlspecialchars($pageTitle); ?>
        </h2>

        <!-- Reserve one line so all pages align even if subtitle is empty -->
        <p class="text-xs text-sc-text-muted mt-1 min-h-[16px]">
            <?= htmlspecialchars($pageSubtitle); ?>
        </p>
    </div>

    <div class="flex flex-col items-start sm:items-end gap-1 shrink-0">
        <?php if ($pageMetaRightHtml): ?>
            <?= $pageMetaRightHtml; ?>
        <?php endif; ?>

        <?php if ($pageActionsHtml): ?>
            <div class="pt-1">
                <?= $pageActionsHtml; ?>
            </div>
        <?php endif; ?>
    </div>
</header>
