<?php
declare(strict_types=1);
require_once __DIR__ . '/../layout.php';
admin_require_perm($user, 'view_dashboard');
admin_page_start($pdo, 'Help');
$helpActive = 'overview';
?>
<div class="px-6 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold mb-4">Admin Help</h1>
  <p class="text-sm text-slate-600 mb-6">
    This guide explains how managers and admins use the SmartCare admin system.
  </p>

  <ul class="grid md:grid-cols-2 gap-4">
    <li class="border rounded-xl p-4">
      <h2 class="font-semibold">Approve Shifts</h2>
      <p class="text-sm text-slate-600 mt-1">Review, correct and approve shifts.</p>
      <a class="text-sm text-blue-600 mt-2 inline-block" href="approve-shifts.php">Read more →</a>
    </li>
    <li class="border rounded-xl p-4">
      <h2 class="font-semibold">View Payroll</h2>
      <p class="text-sm text-slate-600 mt-1">Understand monthly payroll reports.</p>
      <a class="text-sm text-blue-600 mt-2 inline-block" href="payroll.php">Read more →</a>
    </li>
    <li class="border rounded-xl p-4">
      <h2 class="font-semibold">Punch Details</h2>
      <p class="text-sm text-slate-600 mt-1">Audit clock-in / clock-out events.</p>
      <a class="text-sm text-blue-600 mt-2 inline-block" href="punches.php">Read more →</a>
    </li>
  </ul>
</div>
<?php admin_page_end(); ?>
