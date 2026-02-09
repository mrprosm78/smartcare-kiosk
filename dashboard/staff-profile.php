<?php
declare(strict_types=1);
// Legacy route stub. Kept temporarily to avoid breaking old links.
// Remove once all environments/bookmarks are updated to the hr-staff* pages.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$to = 'hr-staff-profile.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $to, true, 302);
exit;
