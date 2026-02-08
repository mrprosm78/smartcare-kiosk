<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

// Managers can update status for now (future: permission table for fine-grained control)
admin_require_perm($user, 'view_hr_applications');

$active = admin_url('hr-applications.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing application id');
}

// This page is read-only for now (no convert, no status updates).
if ($_SERVER['REQUEST_METHOD'] === "POST") {
  http_response_code(403);
  exit("This page is read-only for now.");
}


$stmt = $pdo->prepare("SELECT * FROM hr_applications WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
  http_response_code(404);
  exit('Application not found');
}

$payload = [];
if (!empty($app['payload_json'])) {
  $decoded = json_decode((string)$app['payload_json'], true);
  if (is_array($decoded)) $payload = $decoded;
}


// If already converted, fetch staff id
$convertedEmpId = null;
try {
  $chk = $pdo->prepare("SELECT employee_id FROM hr_staff_profiles WHERE application_id = ? LIMIT 1");
  $chk->execute([$id]);
  $convertedEmpId = $chk->fetchColumn();
} catch (Throwable $e) { $convertedEmpId = null; }


admin_page_start($pdo, 'HR Application');
?>
<div class="min-h-dvh flex">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>
  <main class="flex-1 p-8">
    <div class="space-y-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h1 class="text-2xl font-semibold">Application #<?= (int)$app['id'] ?></h1>
              <p class="mt-1 text-sm text-slate-600">Read-only view of submitted answers (hire/status actions are disabled for now).</p>

        <?php
        $sections = [
          'personal'     => 'Step 1 — Personal',
          'role'         => 'Step 2 — Role & availability',
          'work_history' => 'Step 3 — Work history',
          'education'    => 'Step 4 — Education & training',
          'references'   => 'Step 5 — References',
          'checks'       => 'Step 6 — Right to work & checks',
          'declaration'  => 'Step 8 — Declaration',
        ];

        foreach ($sections as $key => $label):
          $data = is_array($payload[$key] ?? null) ? $payload[$key] : [];
        ?>
          <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold"><?= h($label) ?></h2>
            <?php if (!$data): ?>
              <p class="mt-2 text-sm text-slate-500">No data saved for this section.</p>
            <?php else: ?>
              <div class="mt-3 grid gap-2 sm:grid-cols-2 text-sm">
                <?php foreach ($data as $k => $v): ?>
                  <?php if ($k === 'csrf') continue; ?>
                  <div class="rounded-2xl border border-slate-100 bg-slate-50 px-3 py-2">
                    <div class="text-[11px] uppercase tracking-widest text-slate-500"><?= h((string)$k) ?></div>
                    <div class="mt-1 font-medium text-slate-900">
                      <?= h(is_array($v) ? json_encode($v) : (string)$v) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
    </div>
  </main>
</div>
<?php admin_page_end(); ?>
