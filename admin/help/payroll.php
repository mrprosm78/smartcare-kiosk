<?php
declare(strict_types=1);
require_once __DIR__ . '/../layout.php';
admin_require_perm($user, 'view_payroll');
admin_page_start($pdo, 'Help – Payroll');
?>
<div class="px-6 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold mb-4">View Payroll</h1>
  <ul class="list-disc pl-6 text-sm space-y-2">
    <li>Payroll reports are monthly.</li>
    <li>Hours are calculated after breaks.</li>
    <li>Weekly totals reset based on payroll week start.</li>
    <li>(b) indicates break deducted.</li>
  </ul>
</div>
<?php admin_page_end(); ?>
