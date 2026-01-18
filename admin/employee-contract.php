<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'view_contract');

$active = admin_url('employees.php');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing employee ID');
}

// Load employee
$stmt = $pdo->prepare("SELECT e.*, c.name AS category_name FROM kiosk_employees e LEFT JOIN kiosk_employee_categories c ON c.id=e.category_id WHERE e.id=? LIMIT 1");
$stmt->execute([$id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
  http_response_code(404);
  exit('Employee not found');
}

$canEdit = admin_can($user, 'edit_contract');
$err = '';
$success = '';

$pay = [
  'contract_hours_per_week' => null,
  'break_minutes_default' => null,
  'break_minutes_day' => null,
  'break_minutes_night' => null,
  'break_is_paid' => 0,
  'min_hours_for_break' => null,
  'holiday_entitled' => 0,
  'bank_holiday_entitled' => 0,
  'bank_holiday_multiplier' => null,
  'day_rate' => null,
  'night_rate' => null,
  'night_start' => null,
  'night_end' => null,
];

$ps = $pdo->prepare("SELECT * FROM kiosk_employee_pay_profiles WHERE employee_id=? LIMIT 1");
$ps->execute([$id]);
$prow = $ps->fetch(PDO::FETCH_ASSOC);
if ($prow) {
  foreach ($pay as $k => $_) {
    if (array_key_exists($k, $prow)) $pay[$k] = $prow[$k];
  }
  $pay['break_is_paid'] = (int)($prow['break_is_paid'] ?? 0);
  $pay['holiday_entitled'] = (int)($prow['holiday_entitled'] ?? 0);
  $pay['bank_holiday_entitled'] = (int)($prow['bank_holiday_entitled'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEdit) {
    http_response_code(403);
    exit('Forbidden');
  }
  admin_verify_csrf($_POST['csrf'] ?? null);
  try {
    $pp = [
      'contract_hours_per_week' => trim((string)($_POST['contract_hours_per_week'] ?? '')),
      'break_minutes_default' => trim((string)($_POST['break_minutes_default'] ?? '')),
      'break_minutes_day' => trim((string)($_POST['break_minutes_day'] ?? '')),
      'break_minutes_night' => trim((string)($_POST['break_minutes_night'] ?? '')),
      'break_is_paid' => (int)($_POST['break_is_paid'] ?? 0) === 1 ? 1 : 0,
      'min_hours_for_break' => trim((string)($_POST['min_hours_for_break'] ?? '')),
      'holiday_entitled' => (int)($_POST['holiday_entitled'] ?? 0) === 1 ? 1 : 0,
      'bank_holiday_entitled' => (int)($_POST['bank_holiday_entitled'] ?? 0) === 1 ? 1 : 0,
      'bank_holiday_multiplier' => trim((string)($_POST['bank_holiday_multiplier'] ?? '')),
      'day_rate' => trim((string)($_POST['day_rate'] ?? '')),
      'night_rate' => trim((string)($_POST['night_rate'] ?? '')),
      'night_start' => trim((string)($_POST['night_start'] ?? '')),
      'night_end' => trim((string)($_POST['night_end'] ?? '')),
    ];

    foreach (['contract_hours_per_week','break_minutes_default','break_minutes_day','break_minutes_night','min_hours_for_break','bank_holiday_multiplier','day_rate','night_rate','night_start','night_end'] as $k) {
      if ($pp[$k] === '') $pp[$k] = null;
    }

    $pdo->prepare("INSERT INTO kiosk_employee_pay_profiles (employee_id, contract_hours_per_week, break_minutes_default, break_minutes_day, break_minutes_night, break_is_paid, min_hours_for_break, holiday_entitled, bank_holiday_entitled, bank_holiday_multiplier, day_rate, night_rate, night_start, night_end, created_at, updated_at)
                   VALUES (:id,:ch,:bm,:bmd,:bmn,:bp,:mh,:he,:bhe,:bhm,:dr,:nr,:ns,:ne, UTC_TIMESTAMP, UTC_TIMESTAMP)
                   ON DUPLICATE KEY UPDATE contract_hours_per_week=VALUES(contract_hours_per_week), break_minutes_default=VALUES(break_minutes_default), break_minutes_day=VALUES(break_minutes_day), break_minutes_night=VALUES(break_minutes_night), break_is_paid=VALUES(break_is_paid), min_hours_for_break=VALUES(min_hours_for_break), holiday_entitled=VALUES(holiday_entitled), bank_holiday_entitled=VALUES(bank_holiday_entitled), bank_holiday_multiplier=VALUES(bank_holiday_multiplier), day_rate=VALUES(day_rate), night_rate=VALUES(night_rate), night_start=VALUES(night_start), night_end=VALUES(night_end), updated_at=UTC_TIMESTAMP")
      ->execute([
        ':id' => $id,
        ':ch' => $pp['contract_hours_per_week'],
        ':bm' => $pp['break_minutes_default'],
        ':bmd' => $pp['break_minutes_day'],
        ':bmn' => $pp['break_minutes_night'],
        ':bp' => $pp['break_is_paid'],
        ':mh' => $pp['min_hours_for_break'],
        ':he' => $pp['holiday_entitled'],
        ':bhe' => $pp['bank_holiday_entitled'],
        ':bhm' => $pp['bank_holiday_multiplier'],
        ':dr' => $pp['day_rate'],
        ':nr' => $pp['night_rate'],
        ':ns' => $pp['night_start'],
        ':ne' => $pp['night_end'],
      ]);

    $success = 'Saved';

    // reload
    $ps->execute([$id]);
    $prow = $ps->fetch(PDO::FETCH_ASSOC);
    if ($prow) {
      foreach ($pay as $k => $_) {
        if (array_key_exists($k, $prow)) $pay[$k] = $prow[$k];
      }
      $pay['break_is_paid'] = (int)($prow['break_is_paid'] ?? 0);
      $pay['holiday_entitled'] = (int)($prow['holiday_entitled'] ?? 0);
      $pay['bank_holiday_entitled'] = (int)($prow['bank_holiday_entitled'] ?? 0);
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

admin_page_start($pdo, 'Employee Contract');

$name = ((int)$employee['is_agency'] === 1) ? (string)($employee['agency_label'] ?? 'Agency') : trim(((string)$employee['first_name'] . ' ' . (string)$employee['last_name']));
if ((int)$employee['is_agency'] !== 1 && (string)($employee['nickname'] ?? '') !== '') {
  $name .= ' (' . (string)$employee['nickname'] . ')';
}

function ro_attr(bool $readonly): string {
  return $readonly ? 'readonly disabled' : '';
}

$readonly = !$canEdit;

?>

<div class="min-h-dvh">
  <div class="px-4 sm:px-6 pt-6 pb-10">
    <div class="max-w-6xl mx-auto">
      <div class="flex flex-col lg:flex-row gap-5">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="flex-1">
          <header class="rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-semibold">Contract & Pay</h1>
                <p class="mt-2 text-sm text-white/70">
                  <?= h($name) ?>
                  <span class="text-white/40">• Employee ID <?= (int)$employee['id'] ?></span>
                </p>
              </div>
              <div class="flex gap-2">
                <a href="<?= h(admin_url('employees.php')) ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">Back</a>
                <a href="<?= h(admin_url('employee-edit.php')) ?>?id=<?= (int)$employee['id'] ?>" class="rounded-2xl px-4 py-2 text-sm font-semibold bg-white text-slate-900 hover:bg-white/90">Profile</a>
              </div>
            </div>
          </header>

          <?php if ($err !== ''): ?>
            <div class="mt-5 rounded-3xl border border-rose-500/40 bg-rose-500/10 p-4 text-sm text-rose-100"><?= h($err) ?></div>
          <?php endif; ?>
          <?php if ($success !== ''): ?>
            <div class="mt-5 rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-100"><?= h($success) ?></div>
          <?php endif; ?>

          <form method="post" class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-5">
            <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">

            <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Contract</h2>
              <p class="mt-1 text-sm text-white/70"><?= $readonly ? 'Read-only (Payroll)' : 'Editable (Admin / Superadmin)' ?></p>

              <div class="mt-4 space-y-4">
                <label class="block">
                  <div class="text-xs uppercase tracking-widest text-white/50">Contract hours per week</div>
                  <input name="contract_hours_per_week" value="<?= h((string)($pay['contract_hours_per_week'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                    class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 37.5">
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-white/50">Default break minutes</div>
                    <input name="break_minutes_default" value="<?= h((string)($pay['break_minutes_default'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 30">
                  </label>

                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-white/50">Day break minutes</div>
                    <input name="break_minutes_day" value="<?= h((string)($pay['break_minutes_day'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 45">
                    <div class="mt-2 text-xs text-white/50">Used when the shift is not classified as a night shift.</div>
                  </label>

                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-white/50">Night break minutes</div>
                    <input name="break_minutes_night" value="<?= h((string)($pay['break_minutes_night'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 30">
                    <div class="mt-2 text-xs text-white/50">Used when the shift is classified as a night shift (based on night window and threshold).</div>
                  </label>

                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-white/50">Minimum hours for break</div>
                    <input name="min_hours_for_break" value="<?= h((string)($pay['min_hours_for_break'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 4">
                    <div class="mt-2 text-xs text-white/50">If shift is shorter, break is not applied.</div>
                  </label>
                </div>

                <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                  <input type="checkbox" name="break_is_paid" value="1" class="h-4 w-4 rounded" <?= ((int)($pay['break_is_paid'] ?? 0)===1) ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?> />
                  <div>
                    <div class="text-sm font-semibold">Break is paid</div>
                    <div class="text-xs text-white/60">If off, break minutes will be deducted from payable time.</div>
                  </div>
                </label>
              </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-white/5 p-5">
              <h2 class="text-lg font-semibold">Entitlements & rates</h2>
              <p class="mt-1 text-sm text-white/70">Optional fields — expand later (bank holidays, night/day splits).</p>

              <div class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-3">
                  <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <input type="checkbox" name="holiday_entitled" value="1" class="h-4 w-4 rounded" <?= ((int)($pay['holiday_entitled'] ?? 0)===1) ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?> />
                    <div>
                      <div class="text-sm font-semibold">Holiday entitled</div>
                      <div class="text-xs text-white/60">Employee receives paid holiday.</div>
                    </div>
                  </label>

                  <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <input type="checkbox" name="bank_holiday_entitled" value="1" class="h-4 w-4 rounded" <?= ((int)($pay['bank_holiday_entitled'] ?? 0)===1) ? 'checked' : '' ?> <?= $readonly ? 'disabled' : '' ?> />
                    <div>
                      <div class="text-sm font-semibold">Bank holiday entitled</div>
                      <div class="text-xs text-white/60">Multiplier may apply when applicable.</div>
                    </div>
                  </label>
                </div>

                <label class="block">
                  <div class="text-xs uppercase tracking-widest text-white/50">Bank holiday multiplier</div>
                  <input name="bank_holiday_multiplier" value="<?= h((string)($pay['bank_holiday_multiplier'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                    class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 1.5">
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-white/50">Day rate (£/hr)</div>
                    <input name="day_rate" value="<?= h((string)($pay['day_rate'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 12.00">
                  </label>
                  <label class="block">
                    <div class="text-xs uppercase tracking-widest text-white/50">Night rate (£/hr)</div>
                    <input name="night_rate" value="<?= h((string)($pay['night_rate'] ?? '')) ?>" <?= ro_attr($readonly) ?>
                      class="mt-2 w-full rounded-2xl bg-slate-950/40 border border-white/10 px-4 py-2.5 text-sm outline-none focus:border-white/30" placeholder="e.g. 13.50">
                  </label>
                </div>
              </div>
            </section>

            <?php if ($canEdit): ?>
              <div class="lg:col-span-2 flex items-center justify-end">
                <button class="rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-white/90">Save contract</button>
              </div>
            <?php endif; ?>
          </form>

        </main>
      </div>
    </div>
  </div>
</div>

<?php admin_page_end(); ?>
