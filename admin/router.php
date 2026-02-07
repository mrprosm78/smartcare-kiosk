<?php
declare(strict_types=1);

// Backward compatibility router: redirect /admin/* to the configured admin path (e.g. /dashboard/*)
require_once __DIR__ . '/../db.php';

$base = defined('APP_BASE_PATH') ? rtrim((string)APP_BASE_PATH, '/') : '';
if ($base === '/') $base = '';
$adminPath = defined('APP_ADMIN_PATH') ? trim((string)APP_ADMIN_PATH) : '/dashboard';
if ($adminPath === '') $adminPath = '/dashboard';
if ($adminPath[0] !== '/') $adminPath = '/' . $adminPath;

// Requested path after /admin/
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
$path = parse_url($uri, PHP_URL_PATH) ?: '';
$query = parse_url($uri, PHP_URL_QUERY);
$prefix = $base . '/admin';
$rest = '';
if ($path !== '' && str_starts_with($path, $prefix)) {
  $rest = substr($path, strlen($prefix)); // includes leading slash or empty
}
$target = $base . $adminPath . $rest;
if (is_string($query) && $query !== '') {
  $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
