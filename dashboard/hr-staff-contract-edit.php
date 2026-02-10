<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_staff');

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function is_date(string $d): bool {
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}


$staffId = (int)($_GET['staff_id'] ?? $_POST['staff_id'] ?? 0);
if ($staffId <= 0) { http_response_code(400); exit('Missing staff_id'); }

$contractId = (int)($_GET['contract_id'] ?? $_POST['contract_id'] ?? 0);

// Load staff
$stmt = $pdo->prepare("SELECT id, staff_code, first_name, last_name FROM hr_staff WHERE id = ? LIMIT 1");
$stmt->execute([$staffId]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) { http_response_code(404); exit('Staff not found'); }

$name = trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''));
if ($name === '') $name = 'Staff #' . $staffId;
$staffCode = trim((string)($s['staff_code'] ?? ''));
if ($staffCode === '') $staffCode = (string)$staffId;

// Load contract (if editing)
$row = null;
if ($contractId > 0) {
  $c = $pdo->prepare("SELECT * FROM hr_staff_contracts WHERE id = ? AND staff_id = ? LIMIT 1");
  $c->execute([$contractId, $staffId]);
  $row = $c->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$row) { http_response_code(404); exit('Contract not found'); }
}

$errors = [];
$notice = '';

// Defaults
$effectiveFrom = $row ? (string)$row['effective_from'] : gmdate('Y-m-d');
$effectiveTo   = $row ? (string)($row['effective_to'] ?? '') : '';

$data = [];
if ($row && !empty($row['contract_json'])) {
  $d = json_decode((string)$row['contract_json'], true);
  if (is_array($d)) $data = $d;
}

$hourlyRate = (string)($data['hourly_rate'] ?? '');
$hoursPerWeek = (string)($data['contract_hours_per_week'] ?? '');
$breaksPaid = !empty($data['breaks_paid']);


$uplifts = $data['uplifts'] ?? [];
if (!is_array($uplifts)) $uplifts = [];

function uplift_val(array $uplifts, string $key, string $field, string $default = ''): string {
  $u = $uplifts[$key] ?? null;
  if (!is_array($u)) return $default;
  return (string)($u[$field] ?? $default);
}

$weekendType = uplift_val($uplifts, 'weekend', 'type', 'premium');
$weekendValue = uplift_val($uplifts, 'weekend', 'value', '');

$bhType = uplift_val($uplifts, 'bank_holiday', 'type', 'multiplier');
$bhValue = uplift_val($uplifts, 'bank_holiday', 'value', '');

$nightType = uplift_val($uplifts, 'night', 'type', 'multiplier');
$nightValue = uplift_val($uplifts, 'night', 'value', '');

$otType = uplift_val($uplifts, 'overtime', 'type', 'multiplier');
$otValue = uplift_val($uplifts, 'overtime', 'value', '');

$calloutType = uplift_val($uplifts, 'callout', 'type', 'premium');
$calloutValue = uplift_val($uplifts, 'callout', 'value', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_csrf_verify();

  $effectiveFrom = trim((string)($_POST['effective_from'] ?? ''));
  $effectiveTo = trim((string)($_POST['effective_to'] ?? ''));
  if (!is_date($effectiveFrom)) $errors[] = 'Effective from date is required.';
  if ($effectiveTo !== '' && !is_date($effectiveTo)) $errors[] = 'Effective to date must be YYYY-MM-DD.';
  if ($effectiveTo !== '' && is_date($effectiveFrom) && $effectiveTo < $effectiveFrom) $errors[] = 'Effective to must be on/after effective from.';

  $hourlyRate = trim((string)($_POST['hourly_rate'] ?? ''));
  if ($hourlyRate !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $hourlyRate)) $errors[] = 'Hourly rate must be a number (up to 2 decimals).';

  $hoursPerWeek = trim((string)($_POST['contract_hours_per_week'] ?? ''));
  if ($hoursPerWeek !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $hoursPerWeek)) $errors[] = 'Contract hours per week must be a number.';

  $breaksPaid = !empty($_POST['breaks_paid']);


  // uplifts
  $weekendType = trim((string)($_POST['weekend_type'] ?? 'premium'));
  $weekendValue = trim((string)($_POST['weekend_value'] ?? ''));
  $bhType = trim((string)($_POST['bh_type'] ?? 'multiplier'));
  $bhValue = trim((string)($_POST['bh_value'] ?? ''));
  $nightType = trim((string)($_POST['night_type'] ?? 'multiplier'));
  $nightValue = trim((string)($_POST['night_value'] ?? ''));
  $otType = trim((string)($_POST['ot_type'] ?? 'multiplier'));
  $otValue = trim((string)($_POST['ot_value'] ?? ''));
  $calloutType = trim((string)($_POST['callout_type'] ?? 'premium'));
  $calloutValue = trim((string)($_POST['callout_value'] ?? ''));

  $validTypes = ['premium','multiplier'];
  if (!in_array($weekendType, $validTypes, true)) $weekendType = 'premium';
  if (!in_array($bhType, $validTypes, true)) $bhType = 'multiplier';
  if (!in_array($nightType, $validTypes, true)) $nightType = 'multiplier';
  if (!in_array($otType, $validTypes, true)) $otType = 'multiplier';
  if (!in_array($calloutType, $validTypes, true)) $calloutType = 'premium';

  foreach ([['Weekend',$weekendValue],['Bank holiday',$bhValue],['Night',$nightValue],['Overtime',$otValue],['Callout',$calloutValue]] as [$label,$val]) {
    if ($val === '') continue;
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', (string)$val)) {
      $errors[] = $label . ' value must be numeric.';
      break;
    }
  }

  if (!$errors) {
    $save = [
      'hourly_rate' => $hourlyRate === '' ? null : (float)$hourlyRate,
      'contract_hours_per_week' => $hoursPerWeek === '' ? null : (float)$hoursPerWeek,
      'breaks_paid' => $breaksPaid,
      'uplifts' => [
        'weekend' => ['type' => $weekendType, 'value' => $weekendValue === '' ? null : (float)$weekendValue],
        'bank_holiday' => ['type' => $bhType, 'value' => $bhValue === '' ? null : (float)$bhValue],
        'night' => ['type' => $nightType, 'value' => $nightValue === '' ? null : (float)$nightValue],
        'overtime' => ['type' => $otType, 'value' => $otValue === '' ? null : (float)$otValue],
        'callout' => ['type' => $calloutType, 'value' => $calloutValue === '' ? null : (float)$calloutValue],
      ],
    ];

    $json = json_encode($save, JSON_UNESCAPED_SLASHES);

    // Prevent overlapping ongoing contract when inserting a new one
    if ($contractId <= 0) {
      try {
        $pdo->beginTransaction();
        $prev = $pdo->prepare("SELECT id, effective_from, effective_to FROM hr_staff_contracts
                              WHERE staff_id = ?
                                AND effective_from <= ?
                                AND (effective_to IS NULL OR effective_to >= ?)
                              ORDER BY effective_from DESC, id DESC
                              LIMIT 1");
        $prev->execute([$staffId, $effectiveFrom, $effectiveFrom]);
        $p = $prev->fetch(PDO::FETCH_ASSOC);
        if ($p) {
          $prevId = (int)$p['id'];
          // Set previous contract to end the day before new effective_from
          $updPrev = $pdo->prepare("UPDATE hr_staff_contracts SET effective_to = DATE_SUB(?, INTERVAL 1 DAY) WHERE id = ? LIMIT 1");
          $updPrev->execute([$effectiveFrom, $prevId]);
        }

        $ins = $pdo->prepare("INSERT INTO hr_staff_contracts (staff_code, staff_id, effective_from, effective_to, contract_json)
                              VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$staffCode, $staffId, $effectiveFrom, ($effectiveTo === '' ? null : $effectiveTo), $json]);
        $pdo->commit();
      } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $e2) {}
        $errors[] = 'Unable to save contract. Please try again.';
      }
    } else {
      try {
        $upd = $pdo->prepare("UPDATE hr_staff_contracts
                              SET effective_from = ?, effective_to = ?, contract_json = ?, staff_code = ?
                              WHERE id = ? AND staff_id = ?");
        $upd->execute([$effectiveFrom, ($effectiveTo === '' ? null : $effectiveTo), $json, $staffCode, $contractId, $staffId]);
      } catch (Throwable $e) {
        $errors[] = 'Unable to update contract. Please try again.';
      }
    }

    if (!$errors) {
      header('Location: ' . admin_url('hr-staff-contract.php?staff_id=' . $staffId));
      exit;
    }
  }
}

admin_page_start($pdo, $contractId > 0 ? 'Edit Contract' : 'Add Contract');
$active = admin_url('hr-staff.php');

?>

<div class="min-h-dvh flex flex-col lg:flex-row">
  <?php require __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 px-4 sm:px-6 pt-6 pb-8">
    <header class="rounded-3xl border border-slate-200 bg-white p-4">
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-2xl font-semibold truncate"><?php echo $contractId > 0 ? 'Edit pay contract' : 'Add pay contract'; ?></h1>
          <p class="mt-1 text-sm text-slate-600"><?php echo h2($name); ?> · Staff ID: <span class="font-semibold text-slate-900"><?php echo h2($staffCode); ?></span></p>
        </div>
        <div class="flex flex-wrap gap-2">
          <a class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50" href="<?php echo h(admin_url('hr-staff-contract.php?staff_id=' . $staffId)); ?>">Back</a>
        </div>
      </div>
    </header>

    <?php if ($errors): ?>
      <div class="mt-4 rounded-3xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
        <ul class="list-disc pl-5">
          <?php foreach ($errors as $e): ?><li><?php echo h2($e); ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form class="mt-4 space-y-4" method="post">
      <?php admin_csrf_field(); ?>
      <input type="hidden" name="staff_id" value="<?php echo (int)$staffId; ?>">
      <input type="hidden" name="contract_id" value="<?php echo (int)$contractId; ?>">

      <section class="rounded-3xl border border-slate-200 bg-white p-4">
        <h2 class="text-lg font-semibold">Effective dates</h2>
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700">Effective from</label>
            <input name="effective_from" value="<?php echo h2($effectiveFrom); ?>" type="date" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" required>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700">Effective to (optional)</label>
            <input name="effective_to" value="<?php echo h2($effectiveTo); ?>" type="date" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
          </div>
        </div>
        <?php if ($contractId <= 0): ?>
          <p class="mt-2 text-xs text-slate-600">If there is an existing active contract, it will be automatically ended the day before this start date.</p>
        <?php endif; ?>
      </section>

      <section class="rounded-3xl border border-slate-200 bg-white p-4">
        <h2 class="text-lg font-semibold">Pay & contracted hours</h2>
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700">Hourly rate (£)</label>
            <input name="hourly_rate" value="<?php echo h2($hourlyRate); ?>" inputmode="decimal" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 12.50">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700">Contract hours per week</label>
            <input name="contract_hours_per_week" value="<?php echo h2($hoursPerWeek); ?>" inputmode="decimal" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 37.5">
          </div>
        </div>
      </section>

      <section class="rounded-3xl border border-slate-200 bg-white p-4">
        <h2 class="text-lg font-semibold">Breaks</h2>
        <div class="mt-3 flex items-center gap-2">
          <input id="breaks_paid" name="breaks_paid" type="checkbox" class="h-4 w-4" <?php echo $breaksPaid ? 'checked' : ''; ?>>
          <label for="breaks_paid" class="text-sm font-semibold text-slate-900">Breaks are paid</label>
        </div>

      </section>

      <section class="rounded-3xl border border-slate-200 bg-white p-4">
        <h2 class="text-lg font-semibold">Premiums & multipliers</h2>
        <p class="mt-1 text-xs text-slate-600">Store as either a premium (extra £/hour) or multiplier (e.g. 1.5).</p>

        <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-3">
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-sm font-semibold text-slate-900">Weekend</div>
            <div class="mt-2 flex gap-2">
              <select name="weekend_type" class="w-32 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="premium" <?php echo $weekendType==='premium'?'selected':''; ?>>Premium</option>
                <option value="multiplier" <?php echo $weekendType==='multiplier'?'selected':''; ?>>Multiplier</option>
              </select>
              <input name="weekend_value" value="<?php echo h2($weekendValue); ?>" class="flex-1 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 0.20 or 1.5">
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-sm font-semibold text-slate-900">Bank holiday</div>
            <div class="mt-2 flex gap-2">
              <select name="bh_type" class="w-32 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="premium" <?php echo $bhType==='premium'?'selected':''; ?>>Premium</option>
                <option value="multiplier" <?php echo $bhType==='multiplier'?'selected':''; ?>>Multiplier</option>
              </select>
              <input name="bh_value" value="<?php echo h2($bhValue); ?>" class="flex-1 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 1.5">
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-sm font-semibold text-slate-900">Night</div>
            <div class="mt-2 flex gap-2">
              <select name="night_type" class="w-32 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="premium" <?php echo $nightType==='premium'?'selected':''; ?>>Premium</option>
                <option value="multiplier" <?php echo $nightType==='multiplier'?'selected':''; ?>>Multiplier</option>
              </select>
              <input name="night_value" value="<?php echo h2($nightValue); ?>" class="flex-1 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 0.20 or 1.25">
            </div>
            <div class="mt-1 text-xs text-slate-600">Night time window comes from care home settings.</div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-sm font-semibold text-slate-900">Overtime</div>
            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
              <select name="ot_type" class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="premium" <?php echo $otType==='premium'?'selected':''; ?>>Premium</option>
                <option value="multiplier" <?php echo $otType==='multiplier'?'selected':''; ?>>Multiplier</option>
              </select>
              <input name="ot_value" value="<?php echo h2($otValue); ?>" class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 1.5">
            </div>
            <div class="mt-1 text-xs text-slate-600">Overtime is calculated weekly based on this staff member’s contracted hours per week. If contracted hours is blank or 0, overtime will not apply.</div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-sm font-semibold text-slate-900">Callout</div>
            <div class="mt-2 flex gap-2">
              <select name="callout_type" class="w-32 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                <option value="premium" <?php echo $calloutType==='premium'?'selected':''; ?>>Premium</option>
                <option value="multiplier" <?php echo $calloutType==='multiplier'?'selected':''; ?>>Multiplier</option>
              </select>
              <input name="callout_value" value="<?php echo h2($calloutValue); ?>" class="flex-1 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="e.g. 2.00">
            </div>
          </div>
        </div>
      </section>

      <div class="flex justify-end">
        <button class="rounded-2xl px-5 py-2.5 text-sm font-semibold bg-slate-900 text-white hover:bg-slate-800" type="submit">Save contract</button>
      </div>
    </form>
  </main>
</div>


<?php admin_page_end(); ?>
