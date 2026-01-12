<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (admin_current_user($pdo)) {
  header('Location: ./dashboard.php');
} else {
  header('Location: ./login.php');
}
exit;
