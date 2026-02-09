<?php
declare(strict_types=1);

// LOCKED: Staff identity fields are read-only for now.
// Editing will be reintroduced later via controlled HR modules (contracts, training, etc.).

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
  header('Location: ' . admin_url('hr-staff-view.php?id=' . $id));
  exit;
}
header('Location: ' . admin_url('hr-staff.php'));
exit;
