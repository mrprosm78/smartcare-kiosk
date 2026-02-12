<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_hr_applications');

$active = admin_url('hr-applications.php');

$status = strtolower(trim((string)($_GET['status'] ?? '')));
$job    = trim((string)($_GET['job'] ?? ''));
$q      = trim((string)($_GET['q'] ?? ''));

// Sorting
$sort = strtolower(trim((string)($_GET['sort'] ?? 'updated')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'desc')));
if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

// Extra filters
$converted = strtolower(trim((string)($_GET['converted'] ?? ''))); // '', 'yes', 'no'

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

$hasHrStaffId       = sc_col_exists($pdo, 'hr_applications', 'hr_staff_id');
$hasHiredEmployeeId = sc_col_exists($pdo, 'hr_applications', 'hired_employee_id'); // legacy
$hasSubmittedAt     = sc_col_exists($pdo, 'hr_applications', 'submitted_at');
$hasCreatedAt       = sc_col_exists($pdo, 'hr_applications', 'created_at');


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
  if ($hasHrStaffId) {
    $where[] = "hr_staff_id IS NOT NULL";
  } elseif ($hasHiredEmployeeId) {
    $where[] = "hired_employee_id IS NOT NULL";
  } else {
    $where[] = "status = 'hired'";
  }
} elseif ($converted === 'no') {
  if ($hasHrStaffId) {
    $where[] = "hr_staff_id IS NULL";
  } elseif ($hasHiredEmployeeId) {
    $where[] = "hired_employee_id IS NULL";
  } else {
    $where[] = "status <> 'hired'";
  }
}



// Sorting whitelist (never trust raw column names from the URL)
$submittedSortCol = $hasSubmittedAt ? 'submitted_at' : ($hasCreatedAt ? 'created_at' : 'updated_at');
$sortMap = [
  'id'        => 'id',
  'applicant' => 'applicant_name',
  'email'     => 'email',
  'job'       => 'job_slug',
  'status'    => 'status',
  'submitted' => $submittedSortCol,
  'updated'   => 'updated_at',
];
if (!isset($sortMap[$sort])) $sort = 'updated';
$orderBy = $sortMap[$sort] . ' ' . strtoupper($dir);

function sc_query(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) { unset($q[$k]); continue; }
    $q[$k] = $v;
  }
  // Drop empty values for cleaner URLs
  foreach ($q as $k => $v) {
    if ($v === '' || $v === null) unset($q[$k]);
  }
  return http_build_query($q);
}

function sc_sort_link(string $key, string $label, string $currentSort, string $currentDir): string {
  $is = ($currentSort === $key);
  $nextDir = $is && $currentDir === 'asc' ? 'desc' : 'asc';
  $arrow = '';
  if ($is) {
    $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
  }
  $href = admin_url('hr-applications.php' . (sc_query(['sort' => $key, 'dir' => $nextDir]) ? ('?' . sc_query(['sort' => $key, 'dir' => $nextDir])) : ''));
  return '<a class="hover:text-slate-900" href="' . h($href) . '">' . h($label) . '</a><span class="text-[11px] text-slate-500">' . h($arrow) . '</span>';
}
$sql = "SELECT id, status, job_slug, applicant_name, email, phone, submitted_at, updated_at";
if ($hasHrStaffId) {
  $sql .= ", hr_staff_id";
}
if ($hasHiredEmployeeId) {
  $sql .= ", hired_employee_id";
}
if ($hasCreatedAt) {
  $sql .= ", created_at";
}
$sql .= "\n        FROM hr_applications";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY $orderBy, id DESC LIMIT 200";

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
              <p class="mt-1 text-sm text-slate-600">View and review job applications.</p>
            </div>          </div>

          <form method="get" data-filters="hr-applications" class="mt-4 w-full flex items-end gap-3">
            <label class="block flex-1 min-w-[160px]">
              <span class="text-xs font-semibold text-slate-600">Status</span>
              <select data-auto-submit="1" name="status" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="">Any</option>
                <?php foreach (['draft','submitted','reviewing','rejected','hired','archived'] as $s): ?>
                  <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block flex-1 min-w-[200px]">
              <span class="text-xs font-semibold text-slate-600">Job</span>
              <select data-auto-submit="1" name="job" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="">Any</option>
                <?php foreach ($jobs as $j): ?>
                  <option value="<?= h((string)$j) ?>" <?= $job === (string)$j ? 'selected' : '' ?>><?= h((string)$j) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="block shrink-0 w-72 md:w-96">
              <span class="text-xs font-semibold text-slate-600">Search</span>
              <input name="q" value="<?= h($q) ?>" placeholder="Name, email, phone, token…"
                     class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
            </label>

            <label class="block flex-1 min-w-[160px]">
              <span class="text-xs font-semibold text-slate-600">Converted</span>
              <select data-auto-submit="1" name="converted" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="" <?= $converted === '' ? 'selected' : '' ?>>Any</option>
                <option value="yes" <?= $converted === 'yes' ? 'selected' : '' ?>>Yes</option>
                <option value="no"  <?= $converted === 'no' ? 'selected' : '' ?>>No</option>
              </select>
            </label>
            <div class="flex items-end gap-2">
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
                <th class="px-3 py-2 text-left"><?= sc_sort_link('id','ID',$sort,$dir) ?></th>
                <th class="px-3 py-2 text-left"><?= sc_sort_link('applicant','Applicant',$sort,$dir) ?></th>
                <th class="px-3 py-2 text-left"><?= sc_sort_link('email','Contact',$sort,$dir) ?></th>
                <th class="px-3 py-2 text-left"><?= sc_sort_link('job','Job',$sort,$dir) ?></th>
                <th class="px-3 py-2 text-left"><?= sc_sort_link('status','Status',$sort,$dir) ?></th>
                <th class="px-3 py-2 text-left"><?= sc_sort_link('submitted','Submitted',$sort,$dir) ?></th>
                <th class="px-3 py-2 text-left"><?= sc_sort_link('updated','Updated',$sort,$dir) ?></th>
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
                      $isConverted = $hasHrStaffId
                        ? !empty($r['hr_staff_id'])
                        : ($hasHiredEmployeeId ? !empty($r['hired_employee_id']) : (strtolower($st) === 'hired'));
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

<script>
  // Auto-apply dropdown filters (Status / Job / Converted) on change.
  (function(){
    const form = document.querySelector('form[method="get"]');
    if (!form) return;
    const selects = form.querySelectorAll('select[name="status"], select[name="job"], select[name="converted"]');
    selects.forEach((sel) => {
      sel.addEventListener('change', () => form.submit());
    });
  })();
</script>

<?php
admin_page_end(); ?>
