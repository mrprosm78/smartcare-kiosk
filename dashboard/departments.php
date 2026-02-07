<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_employees');

$active = admin_url('departments.php'); // keep Employees highlighted
$err = '';

function sc_slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9\s-]/', '', $s) ?? '';
  $s = preg_replace('/\s+/', '-', $s) ?? '';
  $s = preg_replace('/-+/', '-', $s) ?? '';
  return trim($s, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf($_POST['csrf'] ?? null);
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      if ($name === '') throw new RuntimeException('Name is required');
      $slug = sc_slugify((string)($_POST['slug'] ?? ''));
      if ($slug === '') $slug = sc_slugify($name);
      if ($slug === '') throw new RuntimeException('Slug is required');
      $sort = (int)($_POST['sort_order'] ?? 0);

      $stmt = $pdo->prepare("INSERT INTO kiosk_employee_departments (name, slug, sort_order, is_active) VALUES (?,?,?,1)");
      $stmt->execute([$name, $slug, $sort]);
      admin_redirect(admin_url('departments.php'));
    }

    if ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid department');
      $pdo->prepare("UPDATE kiosk_employee_departments SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute([$id]);
      admin_redirect(admin_url('departments.php'));
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid department');

      // prevent deleting departments in use
      $c = (int)$pdo->prepare("SELECT COUNT(*) FROM kiosk_employees WHERE department_id=?")->execute([$id]) ?: 0;
      $stmtC = $pdo->prepare("SELECT COUNT(*) FROM kiosk_employees WHERE department_id=?");
      $stmtC->execute([$id]);
      $inUse = (int)$stmtC->fetchColumn();
      if ($inUse > 0) throw new RuntimeException('Department is in use by employees. Deactivate instead.');

      $pdo->prepare("DELETE FROM kiosk_employee_departments WHERE id=?")->execute([$id]);
      admin_redirect(admin_url('departments.php'));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$stmt = $pdo->query("SELECT * FROM kiosk_employee_departments ORDER BY sort_order ASC, name ASC");
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

admin_page_start($pdo, 'Employee Departments');
?>

<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
          <header class="rounded-3xl border border-slate-200 bg-white p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Employee Departments</h1>
                <p class="mt-2 text-sm text-slate-600">Used for reporting and filtering (Carer, Kitchen, etc.).</p>
              </div>
              <a href="<?= h(admin_url('employees.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">Back to Employees</a>
            </div>
          </header>

          <?php if ($err !== ''): ?>
            <div class="mt-5 rounded-3xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-slate-900">
              <?= h($err) ?>
            </div>
          <?php endif; ?>

          <div class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Add department</h2>
            <form method="post" class="mt-4 grid grid-cols-1 md:grid-cols-6 gap-3">
              <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="create">

              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">Name</label>
                <input name="name" required class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="e.g. Carer">
              </div>

              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600">Slug (optional)</label>
                <input name="slug" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" placeholder="auto-generated">
              </div>

              <div class="md:col-span-1">
                <label class="block text-xs font-semibold text-slate-600">Sort</label>
                <input name="sort_order" type="number" class="mt-1 w-full rounded-2xl bg-white border border-slate-200 px-3 py-2 text-sm" value="0">
              </div>

              <div class="md:col-span-1 flex items-end">
                <button class="w-full rounded-2xl px-4 py-2 text-sm font-semibold bg-emerald-500/15 border border-emerald-500/30 text-slate-900 hover:bg-emerald-500/20">Add</button>
              </div>
            </form>
          </div>

          <div class="mt-5 rounded-3xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Departments</h2>

            <div class="mt-4 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="text-slate-500">
                  <tr>
                    <th class="text-left py-2">Name</th>
                    <th class="text-left py-2">Slug</th>
                    <th class="text-left py-2">Status</th>
                    <th class="text-right py-2">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                  <?php foreach ($cats as $c): ?>
                    <tr>
                      <td class="py-3 font-semibold text-slate-900"><?= h((string)$c['name']) ?></td>
                      <td class="py-3 text-slate-600"><?= h((string)$c['slug']) ?></td>
                      <td class="py-3">
                        <?php if ((int)$c['is_active'] === 1): ?>
                          <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/15 border border-emerald-500/30 text-slate-900">Active</span>
                        <?php else: ?>
                          <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold bg-white border border-slate-200 text-slate-500">Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td class="py-3 text-right">
                        <form method="post" class="inline">
                          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                          <button class="rounded-2xl px-3 py-1.5 text-xs font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50">
                            <?= ((int)$c['is_active'] === 1) ? 'Deactivate' : 'Activate' ?>
                          </button>
                        </form>

                        <form method="post" class="inline" onsubmit="return confirm('Delete this department? Only allowed if not in use.');">
                          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                          <button class="ml-2 rounded-2xl px-3 py-1.5 text-xs font-semibold bg-rose-500/10 border border-rose-500/30 text-slate-900 hover:bg-rose-500/20">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (count($cats) === 0): ?>
                    <tr><td colspan="4" class="py-4 text-slate-500">No departments yet.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </main>
</div>

<?php admin_page_end(); ?>
