<?php
declare(strict_types=1);
require_once __DIR__ . '/../layout.php';
admin_require_perm($user, 'view_punches');
admin_page_start($pdo, 'Help – Punch Details');
?>
<div class="px-6 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold mb-4">Punch Details</h1>
  <ul class="list-disc pl-6 text-sm space-y-2">
    <li>Shows all clock-in and clock-out events.</li>
    <li>Includes audit trail and photos (if enabled).</li>
    <li>Used to investigate missing or disputed punches.</li>
  </ul>
</div>
<?php admin_page_end(); ?>
