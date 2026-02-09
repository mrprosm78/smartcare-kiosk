<?php
declare(strict_types=1);
// Legacy page retained as redirect. Original implementation moved to staff-new-legacy.php.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$to = 'hr-staff.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $to, true, 302);
exit;
