<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

$staffId = (int)($_GET['staff_id'] ?? 0);
if ($staffId <= 0) { http_response_code(400); exit('Missing staff_id'); }

$stmt = $pdo->prepare("SELECT s.id, s.staff_code, s.first_name, s.last_name, d.name AS department_name
  FROM hr_staff s
  LEFT JOIN hr_staff_departments d ON d.id = s.department_id
  WHERE s.id = ?
  LIMIT 1");
$stmt->execute([$staffId]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) { http_response_code(404); exit('Staff not found'); }

$name = trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''));
if ($name === '') $name = 'Staff #' . $staffId;
$staffCode = trim((string)($s['staff_code'] ?? ''));
if ($staffCode === '') $staffCode = (string)$staffId;

admin_page_start($pdo, 'Pay Contract');
$active = admin_url('hr-staff.php');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$contracts = [];
try {
  $c = $pdo->prepare("SELECT id, effective_from, effective_to, contract_json, created_at, updated_at
                      FROM hr_staff_payroll_contracts
                      WHERE staff_id = ?
                      ORDER BY effective_from DESC, id DESC");
  $c->execute([$staffId]);
  $contracts = $c->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $contracts = [];
}

$today = gmdate('Y-m-d');
$activeContractId = null;
foreach ($contracts as $row) {
  $from = (string)($row['effective_from'] ?? '');
  $to = (string)($row['effective_to'] ?? '');
  if ($from !== '' && $from <= $today && ($to === '' || $to >= $today)) {
    $activeContractId = (int)$row['id'];
    break;
  }
}

?>

<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
    <header class="rounded-3xl border border-slate-200 bg-white p-4">
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-2xl font-semibold truncate">Pay Contract</h1>
          <p class="mt-1 text-sm text-slate-600">
            <?php echo h2($name); ?> · Staff ID: <span class="font-semibold text-slate-900"><?php echo h2($staffCode); ?></span>
            <?php if (!empty($s['department_name'])): ?> · Department: <span class="font-semibold text-slate-900"><?php echo h2((string)$s['department_name']); ?></span><?php endif; ?>
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('hr-staff-view.php?id=' . $staffId)); ?>">Back to profile</a>
          <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" href="<?php echo h(admin_url('hr-staff-contract-edit.php?staff_id=' . $staffId)); ?>">Add new contract</a>
        </div>
      </div>
    </header>

    <section class="mt-4 rounded-3xl border border-slate-200 bg-white p-4">
      <h2 class="text-lg font-semibold">Contract history</h2>
      <p class="mt-1 text-xs text-slate-600">Payroll and rota will use the contract effective on the shift start date.</p>

      <?php if (!$contracts): ?>
        <div class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">No contracts added yet.</div>
      <?php else: ?>
        <div class="mt-3 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-xs text-slate-600 border-b border-slate-200">
                <th class="py-2 pr-3">Effective</th>
                <th class="py-2 pr-3">Pay</th>
                <th class="py-2 pr-3">Hours</th>
                <th class="py-2 pr-3">Breaks</th>
                <th class="py-2 pr-3"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($contracts as $row):
                $id = (int)$row['id'];
                $data = [];
                if (!empty($row['contract_json'])) {
                  $d = json_decode((string)$row['contract_json'], true);
                  if (is_array($d)) $data = $d;
                }
                $rate = $data['hourly_rate'] ?? '';
                $hours = $data['contract_hours_per_week'] ?? '';
                $breakPaid = $data['breaks_paid'] ?? null;
                $isActive = ($activeContractId !== null && $id === $activeContractId);
              ?>
                <tr class="border-b border-slate-100">
                  <td class="py-2 pr-3 align-top">
                    <div class="font-semibold text-slate-900">
                      <?php echo h2((string)$row['effective_from']); ?>
                      <?php if (!empty($row['effective_to'])): ?>–<?php echo h2((string)$row['effective_to']); ?><?php else: ?>–ongoing<?php endif; ?>
                    </div>
                    <?php if ($isActive): ?>
                      <div class="mt-1 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Active</div>
                    <?php endif; ?>
                  </td>
                  <td class="py-2 pr-3 align-top">
                    <?php echo $rate === '' ? '—' : h2('£' . (string)$rate); ?>
                  </td>
                  <td class="py-2 pr-3 align-top">
                    <?php echo $hours === '' ? '—' : h2((string)$hours); ?>
                  </td>
                  <td class="py-2 pr-3 align-top">
                    <?php
                      if ($breakPaid === null) echo '—';
                      else echo $breakPaid ? 'Paid' : 'Unpaid';
                    ?>
                  </td>
                  <td class="py-2 pr-3 align-top text-right">
                    <a class="rounded-2xl px-3 py-1.5 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('hr-staff-contract-edit.php?staff_id=' . $staffId . '&contract_id=' . $id)); ?>">Edit</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>

<?php admin_page_end(); ?>
