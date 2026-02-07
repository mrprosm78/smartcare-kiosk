<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_hr_applications');

$active = admin_url('hr-applications.php');

$status = strtolower(trim((string)($_GET['status'] ?? '')));
$job    = trim((string)($_GET['job'] ?? ''));
$q      = trim((string)($_GET['q'] ?? ''));

// Extra filters
$converted = strtolower(trim((string)($_GET['converted'] ?? ''))); // '', 'yes', 'no'
$from = trim((string)($_GET['from'] ?? '')); // YYYY-MM-DD
$to   = trim((string)($_GET['to'] ?? ''));   // YYYY-MM-DD

/** Check if a column exists (safe across installs). */
function sc_col_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$hasHiredEmployeeId = sc_col_exists($pdo, 'hr_applications', 'hired_employee_id');
$hasSubmittedAt     = sc_col_exists($pdo, 'hr_applications', 'submitted_at');
$hasCreatedAt       = sc_col_exists($pdo, 'hr_applications', 'created_at');

// Choose best date column for filtering
$dateCol = $hasSubmittedAt ? 'submitted_at' : ($hasCreatedAt ? 'created_at' : '');

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

// Converted filter
if ($converted === 'yes') {
  if ($hasHiredEmployeeId) {
    $where[] = "hired_employee_id IS NOT NULL";
  } else {
    $where[] = "status = 'hired'";
  }
} elseif ($converted === 'no') {
  if ($hasHiredEmployeeId) {
    $where[] = "hired_employee_id IS NULL";
  } else {
    $where[] = "status <> 'hired'";
  }
}

// Date range filter (inclusive)
if ($dateCol !== '') {
  if ($from !== '') {
    $where[] = "$dateCol >= ?";
    $params[] = $from . ' 00:00:00';
  }
  if ($to !== '') {
    $where[] = "$dateCol <= ?";
    $params[] = $to . ' 23:59:59';
  }
}

$sql = "SELECT id, status, job_slug, applicant_name, email, phone, submitted_at, updated_at";
if ($hasHiredEmployeeId) {
  $sql .= ", hired_employee_id";
}
if ($hasCreatedAt) {
  $sql .= ", created_at";
}
$sql .= "\n        FROM hr_applications";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY updated_at DESC, id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Job list for filter
$jobs = $pdo->query("SELECT DISTINCT job_slug FROM hr_applications WHERE job_slug <> '' ORDER BY job_slug ASC")->fetchAll(PDO::FETCH_COLUMN);

admin_page_start($pdo, 'HR Applications');

function sc_status_badge(string $status): array {
  $s = strtolower(trim($status));
  return match ($s) {
    'submitted' => ['bg' => 'bg-blue-50',   'bd' => 'border-blue-200',  'tx' => 'text-blue-700'],
    'reviewing' => ['bg' => 'bg-amber-50',  'bd' => 'border-amber-200', 'tx' => 'text-amber-700'],
    'hired'     => ['bg' => 'bg-green-50',  'bd' => 'border-green-200', 'tx' => 'text-green-700'],
    'rejected'  => ['bg' => 'bg-red-50',    'bd' => 'border-red-200',   'tx' => 'text-red-700'],
    'draft'     => ['bg' => 'bg-slate-50',  'bd' => 'border-slate-200', 'tx' => 'text-slate-600'],
    'archived'  => ['bg' => 'bg-slate-50',  'bd' => 'border-slate-200', 'tx' => 'text-slate-500'],
    default     => ['bg' => 'bg-white',     'bd' => 'border-slate-200', 'tx' => 'text-slate-700'],
  };
}
?>
<div class="min-h-dvh flex">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="flex-1 p-8">
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

          <form method="get" class="mt-4 grid gap-3 sm:grid-cols-6">
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

            <label class="block">
              <span class="text-xs font-semibold text-slate-600">Converted</span>
              <select name="converted" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="" <?= $converted === '' ? 'selected' : '' ?>>All</option>
                <option value="yes" <?= $converted === 'yes' ? 'selected' : '' ?>>Yes</option>
                <option value="no"  <?= $converted === 'no' ? 'selected' : '' ?>>No</option>
              </select>
            </label>

            <label class="block">
              <span class="text-xs font-semibold text-slate-600">From</span>
              <input type="date" name="from" value="<?= h($from) ?>"
                     class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
            </label>

            <label class="block">
              <span class="text-xs font-semibold text-slate-600">To</span>
              <input type="date" name="to" value="<?= h($to) ?>"
                     class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
            </label>

            <div class="flex items-end gap-2 sm:col-span-6">
              <button type="submit"
                      class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Apply
              </button>
              <a href="<?= h(admin_url('hr-applications.php')) ?>"
                 class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">
                Clear
              </a>
            </div>
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
                <th class="px-3 py-2 text-left">Updated</th>
                <th class="px-3 py-2 text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="8" class="px-3 py-6 text-center text-slate-500">No applications found.</td></tr>
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
                    <?php
                      $st = (string)($r['status'] ?? '');
                      $b = sc_status_badge($st);
                      $isConverted = $hasHiredEmployeeId
                        ? !empty($r['hired_employee_id'])
                        : (strtolower($st) === 'hired');
                    ?>
                    <div class="flex flex-wrap items-center gap-1">
                      <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold <?= h($b['bd']) ?> <?= h($b['bg']) ?> <?= h($b['tx']) ?>">
                        <?= h($st) ?>
                      </span>
                      <?php if ($isConverted): ?>
                        <span class="inline-flex rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                          Converted
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="px-3 py-2"><?= h((string)($r['submitted_at'] ?: '—')) ?></td>
                  <td class="px-3 py-2"><?= h((string)($r['updated_at'] ?: '—')) ?></td>
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
  </main>
</div>
<?php admin_page_end(); ?>
