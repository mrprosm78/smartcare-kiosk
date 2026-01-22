<?php
declare(strict_types=1);

// This page is de-scoped. The system is hours-only and uses Payroll Hours as the single payroll screen.
require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_payroll');

header('Location: ' . admin_url('payroll-hours.php'));
exit;
