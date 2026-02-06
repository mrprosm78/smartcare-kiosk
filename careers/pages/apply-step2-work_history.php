<?php
// careers/pages/apply-step3-work.php

$work = $_SESSION['application']['work_history'] ?? [];
$jobs = $work['jobs'] ?? [[]]; // at least 1 empty job

if (!function_exists('sc_old_job')) {
  function sc_old_job(array $jobs, int $index, string $key): string {
    return htmlspecialchars((string)($jobs[$index][$key] ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

$gapExplanation = (string)($work['gap_explanations'] ?? '');

// Month options
$months = [
  ''  => 'Month',
  '1' => 'Jan', '2' => 'Feb', '3' => 'Mar', '4' => 'Apr',
  '5' => 'May', '6' => 'Jun', '7' => 'Jul', '8' => 'Aug',
  '9' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec',
];
?>

<form method="post" action="<?= h($formAction) ?>" class="space-y-5 text-[11px]">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input type="hidden" name="job" value="<?= h($jobSlug) ?>">
  <input type="hidden" name="step" value="<?= (int)$step ?>">

  <?php sc_csrf_field(); ?>

  <p class="text-sc-text-muted">
    Please provide your employment history for the last 10 years or since leaving full-time education.
    You can add multiple jobs. If you have any gaps of 1 month or more, please explain them below.
  </p>

  <!-- JOBS LIST -->
  <div class="space-y-4" id="jobs-container" data-next-index="<?= (int)count($jobs); ?>">
    <?php foreach ($jobs as $i => $job): ?>
      <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 job-block" data-job-index="<?= (int)$i; ?>">
        <div class="flex items-center justify-between gap-2 mb-2">
          <h3 class="text-xs font-semibold text-slate-900">
            Job <?= (int)$i + 1; ?>
          </h3>
          <?php if ($i > 0): ?>
            <button
              type="button"
              class="text-[10px] text-sc-text-muted hover:text-red-600 remove-job-btn"
            >
              Remove
            </button>
          <?php endif; ?>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              Employer name
            </label>
            <input
              type="text"
              name="jobs[<?= (int)$i; ?>][employer_name]"
              value="<?= sc_old_job($jobs, (int)$i, 'employer_name'); ?>"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
              placeholder="e.g. Rosewood Care Home"
            >
          </div>

          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              Location (town / area)
            </label>
            <input
              type="text"
              name="jobs[<?= (int)$i; ?>][employer_location]"
              value="<?= sc_old_job($jobs, (int)$i, 'employer_location'); ?>"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
              placeholder="e.g. Kingston upon Thames"
            >
          </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 mt-2">
          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              Job title
            </label>
            <input
              type="text"
              name="jobs[<?= (int)$i; ?>][job_title]"
              value="<?= sc_old_job($jobs, (int)$i, 'job_title'); ?>"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
              placeholder="e.g. Care Assistant"
            >
          </div>

          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              Type of organisation
            </label>
            <select
              name="jobs[<?= (int)$i; ?>][organisation_type]"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
            >
              <?php
              $orgTypes = [
                '' => 'Select',
                'Care home' => 'Care home',
                'Hospital' => 'Hospital',
                'Domiciliary care' => 'Domiciliary care',
                'Agency' => 'Agency',
                'Other' => 'Other',
              ];
              $org_sel = (string)($jobs[$i]['organisation_type'] ?? '');
              ?>
              <?php foreach ($orgTypes as $val => $label): ?>
                <option value="<?= htmlspecialchars($val); ?>" <?= $org_sel === $val ? 'selected' : ''; ?>>
                  <?= htmlspecialchars($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Dates row -->
        <div class="grid gap-3 md:grid-cols-[1.1fr,1.1fr,0.8fr] mt-2">
          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              Start date
            </label>
            <div class="grid grid-cols-2 gap-2">
              <select
                name="jobs[<?= (int)$i; ?>][start_month]"
                class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs"
              >
                <?php
                $startMonth = (string)($jobs[$i]['start_month'] ?? '');
                foreach ($months as $val => $label):
                ?>
                  <option value="<?= htmlspecialchars($val); ?>" <?= $startMonth === (string)$val ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <input
                type="number"
                name="jobs[<?= (int)$i; ?>][start_year]"
                value="<?= sc_old_job($jobs, (int)$i, 'start_year'); ?>"
                class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs"
                placeholder="Year"
                min="1980"
                max="2100"
              >
            </div>
          </div>

          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              End date
            </label>
            <div class="grid grid-cols-2 gap-2">
              <select
                name="jobs[<?= (int)$i; ?>][end_month]"
                class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs"
              >
                <?php
                $endMonth = (string)($jobs[$i]['end_month'] ?? '');
                foreach ($months as $val => $label):
                ?>
                  <option value="<?= htmlspecialchars($val); ?>" <?= $endMonth === (string)$val ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <input
                type="number"
                name="jobs[<?= (int)$i; ?>][end_year]"
                value="<?= sc_old_job($jobs, (int)$i, 'end_year'); ?>"
                class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs"
                placeholder="Year or blank if current"
                min="1980"
                max="2100"
              >
            </div>
          </div>

          <div class="space-y-1 flex items-end">
            <?php $isCurrent = !empty($jobs[$i]['is_current']); ?>
            <label class="inline-flex items-center gap-2">
              <input
                type="checkbox"
                name="jobs[<?= (int)$i; ?>][is_current]"
                value="1"
                <?= $isCurrent ? 'checked' : ''; ?>
                class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
              >
              <span class="text-sc-text-muted">This is my current job</span>
            </label>
          </div>
        </div>

        <!-- Duties + reason -->
        <div class="grid gap-3 md:grid-cols-2 mt-2">
          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted">
              Main duties / responsibilities
            </label>
            <textarea
              name="jobs[<?= (int)$i; ?>][main_duties]"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
              placeholder="Briefly describe what you did in this role"
            ><?= htmlspecialchars((string)($jobs[$i]['main_duties'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>

          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted">
              Reason for leaving
            </label>
            <textarea
              name="jobs[<?= (int)$i; ?>][reason_for_leaving]"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
              placeholder="e.g. relocation, promotion, end of contract"
            ><?= htmlspecialchars((string)($jobs[$i]['reason_for_leaving'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>
        </div>

        <!-- Flags -->
        <div class="grid gap-3 md:grid-cols-2 mt-2">
          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              Was this a care role?
            </label>
            <?php $careRole = (string)($jobs[$i]['is_care_role'] ?? ''); ?>
            <select
              name="jobs[<?= (int)$i; ?>][is_care_role]"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
            >
              <option value="">Select</option>
              <option value="yes" <?= $careRole === 'yes' ? 'selected' : ''; ?>>Yes</option>
              <option value="no"  <?= $careRole === 'no'  ? 'selected' : ''; ?>>No</option>
            </select>
          </div>

          <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
              Can we contact this employer now?
            </label>
            <?php $contactNow = (string)($jobs[$i]['can_contact_now'] ?? ''); ?>
            <select
              name="jobs[<?= (int)$i; ?>][can_contact_now]"
              class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
            >
              <option value="">Select</option>
              <option value="yes" <?= $contactNow === 'yes' ? 'selected' : ''; ?>>Yes</option>
              <option value="no"  <?= $contactNow === 'no'  ? 'selected' : ''; ?>>No</option>
            </select>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Add job button -->
  <div class="flex justify-end">
    <button
      type="button"
      id="add-job-btn"
      class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-2 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50"
    >
      + Add another job
    </button>
  </div>

  <!-- Gap explanation -->
  <div class="space-y-1">
    <label class="font-medium text-sc-text-muted">
      Explain any gaps in employment or education (1 month or more)
    </label>
    <textarea
      name="gap_explanations"
      class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
      placeholder="If there were any periods when you were not working or studying, please explain briefly (e.g. caring responsibilities, travelling, job searching, etc.)"
    ><?= htmlspecialchars($gapExplanation, ENT_QUOTES, 'UTF-8'); ?></textarea>
  </div>

  <!-- Actions -->
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
      Save & continue to education →
    </button>
  </div>
</form>

<!-- TEMPLATE FOR NEW JOB BLOCK -->
<template id="job-template">
  <?= trim(preg_replace('/^\s+|\s+$/m', '', '
  <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 job-block" data-job-index="__INDEX__">
    <div class="flex items-center justify-between gap-2 mb-2">
      <h3 class="text-xs font-semibold text-slate-900">Job __NUMBER__</h3>
      <button type="button" class="text-[10px] text-sc-text-muted hover:text-red-600 remove-job-btn">Remove</button>
    </div>

    <div class="grid gap-3 md:grid-cols-2">
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Employer name</label>
        <input type="text" name="jobs[__INDEX__][employer_name]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Rosewood Care Home">
      </div>
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Location (town / area)</label>
        <input type="text" name="jobs[__INDEX__][employer_location]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Kingston upon Thames">
      </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 mt-2">
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Job title</label>
        <input type="text" name="jobs[__INDEX__][job_title]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Care Assistant">
      </div>
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Type of organisation</label>
        <select name="jobs[__INDEX__][organisation_type]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
          <option value="">Select</option>
          <option value="Care home">Care home</option>
          <option value="Hospital">Hospital</option>
          <option value="Domiciliary care">Domiciliary care</option>
          <option value="Agency">Agency</option>
          <option value="Other">Other</option>
        </select>
      </div>
    </div>

    <div class="grid gap-3 md:grid-cols-[1.1fr,1.1fr,0.8fr] mt-2">
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Start date</label>
        <div class="grid grid-cols-2 gap-2">
          <select name="jobs[__INDEX__][start_month]" class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs">
            <option value="">Month</option>
            <option value="1">Jan</option><option value="2">Feb</option><option value="3">Mar</option><option value="4">Apr</option>
            <option value="5">May</option><option value="6">Jun</option><option value="7">Jul</option><option value="8">Aug</option>
            <option value="9">Sep</option><option value="10">Oct</option><option value="11">Nov</option><option value="12">Dec</option>
          </select>
          <input type="number" name="jobs[__INDEX__][start_year]" class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs" placeholder="Year" min="1980" max="2100">
        </div>
      </div>
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">End date</label>
        <div class="grid grid-cols-2 gap-2">
          <select name="jobs[__INDEX__][end_month]" class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs">
            <option value="">Month</option>
            <option value="1">Jan</option><option value="2">Feb</option><option value="3">Mar</option><option value="4">Apr</option>
            <option value="5">May</option><option value="6">Jun</option><option value="7">Jul</option><option value="8">Aug</option>
            <option value="9">Sep</option><option value="10">Oct</option><option value="11">Nov</option><option value="12">Dec</option>
          </select>
          <input type="number" name="jobs[__INDEX__][end_year]" class="rounded-md border border-sc-border bg-white px-2 py-2 text-xs" placeholder="Year or blank if current" min="1980" max="2100">
        </div>
      </div>
      <div class="space-y-1 flex items-end">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="jobs[__INDEX__][is_current]" value="1" class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft">
          <span class="text-sc-text-muted">This is my current job</span>
        </label>
      </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 mt-2">
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted">Main duties / responsibilities</label>
        <textarea name="jobs[__INDEX__][main_duties]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="Briefly describe what you did in this role"></textarea>
      </div>
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted">Reason for leaving</label>
        <textarea name="jobs[__INDEX__][reason_for_leaving]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. relocation, promotion, end of contract"></textarea>
      </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 mt-2">
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Was this a care role?</label>
        <select name="jobs[__INDEX__][is_care_role]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
          <option value="">Select</option><option value="yes">Yes</option><option value="no">No</option>
        </select>
      </div>
      <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Can we contact this employer now?</label>
        <select name="jobs[__INDEX__][can_contact_now]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
          <option value="">Select</option><option value="yes">Yes</option><option value="no">No</option>
        </select>
      </div>
    </div>
  </div>
  ')); ?>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const container = document.getElementById('jobs-container');
  if (!container) return;

  const addBtn = document.getElementById('add-job-btn');
  const template = document.getElementById('job-template');

  function reindexJobHeadings() {
    const blocks = container.querySelectorAll('.job-block');
    blocks.forEach((block, idx) => {
      const heading = block.querySelector('h3');
      if (heading) heading.textContent = 'Job ' + (idx + 1);
    });
  }

  function attachRemoveHandlers() {
    container.querySelectorAll('.remove-job-btn').forEach(btn => {
      btn.onclick = function () {
        const block = btn.closest('.job-block');
        if (!block) return;
        block.remove();
        reindexJobHeadings();
      };
    });
  }

  if (addBtn && template) {
    addBtn.addEventListener('click', function () {
      let nextIndex = parseInt(container.getAttribute('data-next-index') || '0', 10);
      if (isNaN(nextIndex)) nextIndex = 0;

      let html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
      html = html.replace(/__NUMBER__/g, nextIndex + 1);

      const wrapper = document.createElement('div');
      wrapper.innerHTML = html.trim();
      const block = wrapper.firstElementChild;
      container.appendChild(block);

      container.setAttribute('data-next-index', String(nextIndex + 1));
      attachRemoveHandlers();
    });
  }

  attachRemoveHandlers();
});
</script>
