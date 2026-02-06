<?php
// careers/pages/apply-step1-personal.php

$personal = $_SESSION['application']['personal'] ?? [];

if (!function_exists('sc_old')) {
  function sc_old(array $src, string $key): string {
    return htmlspecialchars((string)($src[$key] ?? ''), ENT_QUOTES, 'UTF-8');
  }
}
?>

<form method="post" action="<?= h($formAction) ?>" class="space-y-4 text-[11px]">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input type="hidden" name="job" value="<?= h($jobSlug) ?>">
  <input type="hidden" name="step" value="<?= (int)$step ?>">

  <?php sc_csrf_field(); ?>

  <!-- Basic identity -->
  <div class="grid gap-3 md:grid-cols-[0.6fr,1.2fr,1.2fr]">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Title</label>
      <select
        name="title"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
      >
        <?php
          $titles = ['Mr', 'Mrs', 'Miss', 'Ms', 'Mx', 'Dr', 'Other'];
          $selectedTitle = (string)($personal['title'] ?? '');
        ?>
        <option value="">Select</option>
        <?php foreach ($titles as $t): ?>
          <option value="<?= htmlspecialchars($t); ?>" <?= $selectedTitle === $t ? 'selected' : ''; ?>>
            <?= htmlspecialchars($t); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">First name</label>
      <input
        type="text"
        name="first_name"
        value="<?= sc_old($personal, 'first_name'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
        placeholder="e.g. Sarah"
      >
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Last name</label>
      <input
        type="text"
        name="last_name"
        value="<?= sc_old($personal, 'last_name'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
        placeholder="e.g. Brown"
      >
    </div>
  </div>

  <div class="grid gap-3 md:grid-cols-3">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Preferred name (optional)</label>
      <input
        type="text"
        name="preferred_name"
        value="<?= sc_old($personal, 'preferred_name'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
        placeholder="Name you like to be called"
      >
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Date of birth</label>
      <input
        type="date"
        name="dob"
        value="<?= sc_old($personal, 'dob'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
      >
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Phone (mobile)</label>
      <input
        type="tel"
        name="phone"
        value="<?= sc_old($personal, 'phone'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
        placeholder="07..."
      >
    </div>
  </div>

  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Email address</label>
      <input
        type="email"
        name="email"
        value="<?= sc_old($personal, 'email'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
        placeholder="you@example.com"
      >
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Home phone (optional)</label>
      <input
        type="tel"
        name="phone_home"
        value="<?= sc_old($personal, 'phone_home'); ?>"
        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
        placeholder="Landline if you have one"
      >
    </div>
  </div>

  <!-- Current address -->
  <div class="space-y-2">
    <h3 class="text-xs font-semibold text-slate-900">Current address</h3>

    <div class="grid gap-3">
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Address line 1</label>
        <input
          type="text"
          name="address_line1"
          value="<?= sc_old($personal, 'address_line1'); ?>"
          class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
          placeholder="House name/number and street"
        >
      </div>

      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Address line 2 (optional)</label>
        <input
          type="text"
          name="address_line2"
          value="<?= sc_old($personal, 'address_line2'); ?>"
          class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
          placeholder="Area, building, etc."
        >
      </div>

      <div class="grid gap-3 md:grid-cols-3">
        <div class="space-y-1">
          <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Town / City</label>
          <input
            type="text"
            name="address_town"
            value="<?= sc_old($personal, 'address_town'); ?>"
            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
            placeholder="Town or city"
          >
        </div>

        <div class="space-y-1">
          <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">County (optional)</label>
          <input
            type="text"
            name="address_county"
            value="<?= sc_old($personal, 'address_county'); ?>"
            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
            placeholder="County"
          >
        </div>

        <div class="space-y-1">
          <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Postcode</label>
          <input
            type="text"
            name="address_postcode"
            value="<?= sc_old($personal, 'address_postcode'); ?>"
            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs text-sc-text placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-sc-primary-soft focus:border-sc-primary"
            placeholder="e.g. KT2 6PT"
          >
        </div>
      </div>
    </div>
  </div>

  <p class="mt-2 text-[10px] text-sc-text-muted">
    In the full SmartCare Solutions system, previous addresses for the last 5 years will also be collected here
    to support DBS checks. For this prototype we are focusing on layout and flow.
  </p>

  <hr class="border-slate-200">

  <!-- Role & availability (merged from old Step 2) -->
  <?php $role = $_SESSION['application']['role'] ?? []; ?>
  <div class="space-y-2">
    <h3 class="text-xs font-semibold text-slate-900">Role & availability</h3>
    <p class="text-sc-text-muted">Tell us which role you are applying for and your availability.</p>
  </div>

  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Position applied for</label>
      <select name="position_applied_for" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
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
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Work type</label>
      <select name="work_type" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
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
  </div>

  <div class="grid gap-3 md:grid-cols-3">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Preferred shift pattern</label>
      <select name="preferred_shift_pattern" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
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
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Hours per week (approx.)</label>
      <input type="number" name="hours_per_week" value="<?= htmlspecialchars((string)($role['hours_per_week'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
             class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. 36" min="0">
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Earliest start date</label>
      <input type="date" name="earliest_start_date" value="<?= htmlspecialchars((string)($role['earliest_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
             class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
    </div>
  </div>

  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Notice period (if currently employed)</label>
      <input type="text" name="notice_period" value="<?= htmlspecialchars((string)($role['notice_period'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
             class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. 1 week, 4 weeks">
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Where did you hear about this job?</label>
      <select name="heard_about_role" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
        <?php
          $sources = ["Care home website","Indeed","NHS Jobs","Agency","Referral","Social media","Other"];
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
  </div>

  <div class="space-y-1">
    <label class="font-medium text-sc-text-muted">Additional notes (optional)</label>
    <textarea name="extra_notes" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
              placeholder="Anything else you'd like to tell us about your availability or preferred role..."><?= htmlspecialchars((string)($role['extra_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
  </div>

  <hr class="border-slate-200">

  <!-- Eligibility checks (merged from old Step 6) -->
  <?php $checks = $_SESSION['application']['checks'] ?? []; ?>
  <div class="space-y-2">
    <h3 class="text-xs font-semibold text-slate-900">Eligibility</h3>
    <p class="text-sc-text-muted">A few quick checks before we continue.</p>
  </div>

  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Do you have the right to work in the UK?</label>
      <?php $rtw = (string)($checks['has_right_to_work'] ?? ''); ?>
      <select name="has_right_to_work" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
        <option value="">Select</option>
        <option value="yes" <?= $rtw === 'yes' ? 'selected' : ''; ?>>Yes</option>
        <option value="no"  <?= $rtw === 'no'  ? 'selected' : ''; ?>>No</option>
      </select>
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Will you require sponsorship?</label>
      <?php $spon = (string)($checks['requires_sponsorship'] ?? ''); ?>
      <select name="requires_sponsorship" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
        <option value="">Select</option>
        <option value="yes" <?= $spon === 'yes' ? 'selected' : ''; ?>>Yes</option>
        <option value="no"  <?= $spon === 'no'  ? 'selected' : ''; ?>>No</option>
      </select>
    </div>
  </div>

  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Visa type (if applicable)</label>
      <input type="text" name="visa_type" value="<?= htmlspecialchars((string)($checks['visa_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
             class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Skilled Worker">
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Right to work notes (optional)</label>
      <input type="text" name="rtw_notes" value="<?= htmlspecialchars((string)($checks['rtw_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
             class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="Any details you want to add">
    </div>
  </div>

  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Do you have a current DBS?</label>
      <?php $dbs = (string)($checks['has_current_dbs'] ?? ''); ?>
      <select name="has_current_dbs" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
        <option value="">Select</option>
        <option value="yes" <?= $dbs === 'yes' ? 'selected' : ''; ?>>Yes</option>
        <option value="no"  <?= $dbs === 'no'  ? 'selected' : ''; ?>>No</option>
      </select>
    </div>

    <div class="space-y-1">
      <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">DBS type (if you have one)</label>
      <input type="text" name="dbs_type" value="<?= htmlspecialchars((string)($checks['dbs_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
             class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Enhanced">
    </div>
  </div>

  <div class="grid gap-3 md:grid-cols-2">
    <div class="space-y-1">
      <label class="inline-flex items-center gap-2">
        <?php $upd = !empty($checks['on_update_service']); ?>
        <input type="checkbox" name="on_update_service" value="1" <?= $upd ? 'checked' : ''; ?>
               class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft">
        <span class="text-sc-text-muted">On the DBS Update Service</span>
      </label>
    </div>

    <div class="space-y-1">
      <label class="inline-flex items-center gap-2">
        <?php $bar = !empty($checks['barred_from_working']); ?>
        <input type="checkbox" name="barred_from_working" value="1" <?= $bar ? 'checked' : ''; ?>
               class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft">
        <span class="text-sc-text-muted">I am barred from working with vulnerable adults</span>
      </label>
    </div>
  </div>

  <div class="space-y-1">
    <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">DBS notes (optional)</label>
    <textarea name="dbs_notes" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
              placeholder="Any details about your DBS status..."><?= htmlspecialchars((string)($checks['dbs_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
  </div>

  <!-- Actions -->
  <div class="pt-3 flex items-center justify-between">
    <a
      href="<?= function_exists('sc_careers_url') ? sc_careers_url() : 'index.php'; ?>"
      class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-2 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50"
    >
      ← Back to jobs
    </a>

    <button
      type="submit"
      class="inline-flex items-center rounded-md bg-sc-primary px-3 py-2 text-[11px] font-medium text-white hover:bg-blue-600"
    >
      Save & continue →
    </button>
  </div>
</form>
