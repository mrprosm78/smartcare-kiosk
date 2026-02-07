<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/permissions.php';

/**
 * Use on protected pages.
 *
 * Provides:
 *  - $user   (current user row)
 */
$user = admin_require_login($pdo);

function admin_page_start(PDO $pdo, string $title): void {
  $css = admin_asset_css($pdo);
  $safeTitle = h($title);
  // Full-screen admin shell: override older max-width wrappers used across pages.
  // Keep changes here so we don't need to touch every page.
  echo "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\"/>\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\"/>\n<meta name=\"theme-color\" content=\"#f3f4f6\"/>\n<title>{$safeTitle}</title>\n<link rel=\"stylesheet\" href=\"" . h($css) . "\"/>\n<style>html,body{height:100%} .min-h-dvh{min-height:100dvh} .max-w-6xl,.max-w-7xl{max-width:100%!important} .mx-auto{margin-left:0!important;margin-right:0!important}</style>\n</head>\n<body class=\"bg-slate-100 text-slate-900 min-h-dvh\">\n";
}

function admin_page_end(): void {
  echo "\n</body>\n</html>";
}
