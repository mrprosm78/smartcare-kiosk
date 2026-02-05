<?php
// careers/pages/apply-step2-role.php

$role = $_SESSION['application']['role'] ?? [];

if (!function_exists('sc_old_role')) {
  function sc_old_role(array $src, string $key): string {
    return htmlspecialchars((string)($src[$key] ?? ''), ENT_QUOTES, 'UTF-8');
  }
}
?>

<form method="post" action="<?= h($formAction) ?>" class="space-y-5 text-[11px]">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input type="hidden" name="job" value="<?= h($jobSlug) ?>">
  <input type="hidden" name="step" value="<?= (int)$step ?>">

  <?php sc_csrf_field(); ?>

  <!-- Position and department -->
  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
        Position applied for
      </label>
      <select
        name="position_applied_for"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
      >
        <?php
        $positions = [
          "Care Assistant (Days)",
          "Care Assistant (Nights)",
          "Senior Carer",
          "Nurse (RGN)",
          "Domestic / Housekeeping",
          "Activities Coordinator",
          "Chef / Kitchen Assistant",
          "Administrator",
          "Other"
        ];
        $selected = (string)($role['position_applied_for'] ?? '');
        ?>
        <option value="">Select a role</option>
        <?php foreach ($positions as $p): ?>
          <option value="<?= htmlspecialchars($p); ?>" <?= $selected === $p ? 'selected' : ''; ?>>
            <?= htmlspecialchars($p); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
        Preferred unit / department
      </label>
      <select
        name="preferred_unit"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
      >
        <?php
        $units = ["Residential", "Nursing", "Dementia", "Housekeeping", "Kitchen", "Admin", "Any"];
        $unit_sel = (string)($role['preferred_unit'] ?? '');
        ?>
        <option value="">Select</option>
        <?php foreach ($units as $u): ?>
          <option value="<?= htmlspecialchars($u); ?>" <?= $unit_sel === $u ? 'selected' : ''; ?>>
            <?= htmlspecialchars($u); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Work type + shift preference -->
  <div class="grid gap-3 md:grid-cols-3">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
        Work type
      </label>
      <select
        name="work_type"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
      >
        <?php
        $types = ["Full-time", "Part-time", "Bank / Flexible"];
        $type_sel = (string)($role['work_type'] ?? '');
        ?>
        <option value="">Select</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= htmlspecialchars($t); ?>" <?= $type_sel === $t ? 'selected' : ''; ?>>
            <?= htmlspecialchars($t); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
        Preferred shift pattern
      </label>
      <select
        name="preferred_shift_pattern"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
      >
        <?php
        $shifts = ["Days", "Nights", "Mixed", "Weekends only"];
        $shift_sel = (string)($role['preferred_shift_pattern'] ?? '');
        ?>
        <option value="">Select</option>
        <?php foreach ($shifts as $s): ?>
          <option value="<?= htmlspecialchars($s); ?>" <?= $shift_sel === $s ? 'selected' : ''; ?>>
            <?= htmlspecialchars($s); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
        Hours per week (approx.)
      </label>
      <input
        type="number"
        name="hours_per_week"
        value="<?= sc_old_role($role, 'hours_per_week'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
        placeholder="e.g. 36"
        min="0"
      >
    </div>
  </div>

  <!-- Start date + notice period -->
  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
        Earliest start date
      </label>
      <input
        type="date"
        name="earliest_start_date"
        value="<?= sc_old_role($role, 'earliest_start_date'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
      >
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
        Notice period (if currently employed)
      </label>
      <input
        type="text"
        name="notice_period"
        value="<?= sc_old_role($role, 'notice_period'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
        placeholder="e.g. 1 week, 4 weeks"
      >
    </div>
  </div>

  <!-- Heard about job -->
  <div class="space-y-1">
    <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
      Where did you hear about this job?
    </label>
    <select
      name="heard_about_role"
      class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
    >
      <?php
      $sources = [
        "Care home website",
        "Indeed",
        "NHS Jobs",
        "Agency",
        "Referral",
        "Social media",
        "Other"
      ];
      $src_sel = (string)($role['heard_about_role'] ?? '');
      ?>
      <option value="">Select</option>
      <?php foreach ($sources as $src): ?>
        <option value="<?= htmlspecialchars($src); ?>" <?= $src_sel === $src ? 'selected' : ''; ?>>
          <?= htmlspecialchars($src); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="space-y-1">
    <label class="font-medium text-sc-text-muted">
      Additional notes (optional)
    </label>
    <textarea
      name="extra_notes"
      class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
      placeholder="Anything else you'd like to tell us about your availability or preferred role..."
    ><?= htmlspecialchars((string)($role['extra_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
  </div>

  <!-- Action buttons -->
  <div class="pt-3 flex items-center justify-between">
    <?php
      $backQs = ['token' => $token, 'step' => '1'];
      if (!empty($jobSlug)) $backQs['job'] = $jobSlug;
      $backUrl = sc_careers_url('apply.php?' . http_build_query($backQs));
    ?>
    <a
      href="<?= $backUrl; ?>"
      class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-2 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50"
    >
      ← Back
    </a>

    <button
      type="submit"
      class="inline-flex items-center rounded-md bg-sc-primary px-3 py-2 text-[11px] font-medium text-white hover:bg-blue-600"
    >
      Save & continue to work history →
    </button>
  </div>
</form>
