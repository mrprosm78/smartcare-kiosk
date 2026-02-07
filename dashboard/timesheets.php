<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

// Placeholder page (Phase 6 planned)
admin_require_perm($user, 'view_dashboard');

$active = admin_url('timesheets.php');

admin_page_start($pdo, 'Timesheets');
?>
<div class="min-h-dvh flex">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>
  <main class="flex-1 p-8">
    <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Timesheets</h1>
              <p class="mt-1 text-sm text-slate-600">Coming soon. Weekly approval + locking of actual shifts, with audit trail and "edited after approval" flags.</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 border border-slate-200">Actual</span>
          </div>

          <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            Payroll export will consume <b>approved timesheets only</b>. For now this page is a placeholder to reserve the navigation location.
          </div>
        </div>
    </div>
  </main>
</div>
<?php
admin_page_end();
