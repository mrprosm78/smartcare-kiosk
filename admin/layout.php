<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/permissions.php';

/**
 * Use on protected pages.
 *
 * Provides:
 *  - $device (trusted device row)
 *  - $user   (current user row)
 */
$device = admin_require_device($pdo);
$user   = admin_require_login($pdo);

function admin_page_start(PDO $pdo, string $title): void {
  $css = admin_asset_css($pdo);
  $safeTitle = h($title);
  echo "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\"/>\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\"/>\n<meta name=\"theme-color\" content=\"#0f172a\"/>\n<title>{$safeTitle}</title>\n<link rel=\"stylesheet\" href=\"" . h($css) . "\"/>\n<style>html,body{height:100%} .min-h-dvh{min-height:100dvh}</style>\n</head>\n<body class=\"bg-slate-950 text-white min-h-dvh\">\n";
}

function admin_page_end(): void {
  echo "\n</body>\n</html>";
}
