<?php
declare(strict_types=1);

// Reserved for future: Print-friendly / audit view with selectable sections.
// For now, the canonical staff profile is hr-staff-view.php.

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
  header('Location: ' . admin_url('hr-staff-view.php?id=' . $id));
  exit;
}
header('Location: ' . admin_url('hr-staff.php'));
exit;
