<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

// Placeholder page (Phase 5 planned)
admin_require_perm($user, 'view_dashboard');

$active = admin_url('rota.php');

admin_page_start($pdo, 'Rota');
?>
<div class="min-h-dvh flex">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>
  <main class="flex-1 p-8">
    <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Rota</h1>
              <p class="mt-1 text-sm text-slate-600">Coming soon. Planned shifts will remain separate from actual kiosk shifts (locked rule: planned ≠ actual).</p>
            </div>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 border border-slate-200">Planned</span>
          </div>

          <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            This module will be added after HR/Staff hardening. For now this page is a placeholder to reserve the navigation location.
          </div>
        </div>
    </div>
  </main>
</div>
<?php
admin_page_end();
