<?php
declare(strict_types=1);
require_once __DIR__ . '/../layout.php';
admin_require_perm($user, 'view_shifts');
admin_page_start($pdo, 'Help – Approve Shifts');
?>
<div class="px-6 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold mb-4">Approve Shifts</h1>
  <ol class="list-decimal pl-6 text-sm space-y-2">
    <li>Open the Shifts page from the sidebar.</li>
    <li>Review flagged or edited shifts.</li>
    <li>Correct times if required.</li>
    <li>Approve shifts so they are included in payroll.</li>
  </ol>
</div>
<?php admin_page_end(); ?>
