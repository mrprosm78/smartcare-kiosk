<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Backward-compatible stub.
// This project renamed the old Employees (kiosk identities) page to kiosk-ids.php
// but keeps this endpoint so older links/bookmarks don't break.

admin_require_login($pdo);

$qs = $_SERVER['QUERY_STRING'] ?? '';
$to = admin_url('kiosk-ids.php') . ($qs !== '' ? ('?' . $qs) : '');
header('Location: ' . $to, true, 302);
exit;
