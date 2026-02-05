<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_hr_applications');

$active = admin_url('hr-applications.php');

$status = strtolower(trim((string)($_GET['status'] ?? '')));
$job    = trim((string)($_GET['job'] ?? ''));
$q      = trim((string)($_GET['q'] ?? ''));

$params = [];
$where = [];

if ($status !== '' && in_array($status, ['draft','submitted','reviewing','rejected','hired','archived'], true)) {
  $where[] = "status = ?";
  $params[] = $status;
}
if ($job !== '') {
  $where[] = "job_slug = ?";
  $params[] = $job;
}
if ($q !== '') {
  $where[] = "(applicant_name LIKE ? OR email LIKE ? OR phone LIKE ? OR public_token LIKE ?)";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
}

$sql = "SELECT id, status, job_slug, applicant_name, email, phone, submitted_at, updated_at
        FROM hr_applications";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY updated_at DESC, id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Job list for filter
$jobs = $pdo->query("SELECT DISTINCT job_slug FROM hr_applications WHERE job_slug <> '' ORDER BY job_slug ASC")->fetchAll(PDO::FETCH_COLUMN);

admin_page_start($pdo, 'HR Applications');
?>
<div class="p-6">
  <div class="max-w-7xl">
    <div class="grid gap-4 lg:grid-cols-[280px,1fr]">
      <?php include __DIR__ . '/partials/sidebar.php'; ?>

      <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">HR Applications</h1>
              <p class="mt-1 text-sm text-slate-600">View and manage job applications (managers can update status).</p>
            </div>
            <?php if (admin_can($user, 'manage_staff')): ?>
              <a href="<?= h(admin_url('staff-new.php')) ?>"
                 class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                + Add Staff
              </a>
            <?php endif; ?>
          </div>

          <form method="get" class="mt-4 grid gap-3 sm:grid-cols-4">
            <label class="block">
              <span class="text-xs font-semibold text-slate-600">Status</span>
              <select name="status" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="">All</option>
                <?php foreach (['draft','submitted','reviewing','rejected','hired','archived'] as $s): ?>
                  <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block">
              <span class="text-xs font-semibold text-slate-600">Job</span>
              <select name="job" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="">All</option>
                <?php foreach ($jobs as $j): ?>
                  <option value="<?= h((string)$j) ?>" <?= $job === (string)$j ? 'selected' : '' ?>><?= h((string)$j) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block sm:col-span-2">
              <span class="text-xs font-semibold text-slate-600">Search</span>
              <input name="q" value="<?= h($q) ?>" placeholder="Name, email, phone, token…"
                     class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
            </label>
          </form>
        </div>

        <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-3 py-2 text-left">ID</th>
                <th class="px-3 py-2 text-left">Applicant</th>
                <th class="px-3 py-2 text-left">Contact</th>
                <th class="px-3 py-2 text-left">Job</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-left">Submitted</th>
                <th class="px-3 py-2 text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="7" class="px-3 py-6 text-center text-slate-500">No applications found.</td></tr>
              <?php else: foreach ($rows as $r): ?>
                <tr class="border-t border-slate-100">
                  <td class="px-3 py-2 font-semibold text-slate-900">#<?= (int)$r['id'] ?></td>
                  <td class="px-3 py-2"><?= h((string)($r['applicant_name'] ?: '—')) ?></td>
                  <td class="px-3 py-2">
                    <div class="text-slate-900"><?= h((string)($r['email'] ?: '—')) ?></div>
                    <div class="text-xs text-slate-500"><?= h((string)($r['phone'] ?: '')) ?></div>
                  </td>
                  <td class="px-3 py-2"><?= h((string)($r['job_slug'] ?: '—')) ?></td>
                  <td class="px-3 py-2">
                    <span class="inline-flex rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700">
                      <?= h((string)$r['status']) ?>
                    </span>
                  </td>
                  <td class="px-3 py-2"><?= h((string)($r['submitted_at'] ?: '—')) ?></td>
                  <td class="px-3 py-2 text-right">
                    <a class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold hover:bg-slate-50"
                       href="<?= h(admin_url('hr-application.php?id=' . (int)$r['id'])) ?>">
                      View
                    </a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
</div>
<?php admin_page_end(); ?>
