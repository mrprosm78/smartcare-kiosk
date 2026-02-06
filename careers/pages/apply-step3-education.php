<?php
// pages/apply-step4-education.php

$education      = $_SESSION['application']['education'] ?? [];
$qualifications = $education['qualifications'] ?? [[]];
$registrations  = $education['registrations']  ?? [[]];

function sc_old_edu(array $src, string $key): string {
    return htmlspecialchars($src[$key] ?? '', ENT_QUOTES, 'UTF-8');
}
function sc_old_qual(array $rows, int $index, string $key): string {
    return htmlspecialchars($rows[$index][$key] ?? '', ENT_QUOTES, 'UTF-8');
}
function sc_old_reg(array $rows, int $index, string $key): string {
    return htmlspecialchars($rows[$index][$key] ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<form method="post" action="<?= h($formAction) ?>" class="space-y-5 text-[11px]">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input type="hidden" name="job" value="<?= h($jobSlug) ?>">
  <input type="hidden" name="step" value="<?= (int)$step ?>">

  <?php sc_csrf_field(); ?>
<p class="text-sc-text-muted">
        Please tell us about your education, relevant care qualifications and any professional registrations.
        You do not need to list every school qualification, just the most relevant and highest level.
    </p>

    <!-- Highest education level -->
    <div class="space-y-1">
        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
            Highest level of education
        </label>
        <select
            name="highest_education_level"
            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
        >
            <?php
            $levels = [
                '' => 'Select',
                'No formal qualifications' => 'No formal qualifications',
                'GCSE or equivalent'      => 'GCSE or equivalent',
                'A Level or equivalent'   => 'A Level or equivalent',
                'NVQ/QCF Level 2'         => 'NVQ/QCF Level 2',
                'NVQ/QCF Level 3'         => 'NVQ/QCF Level 3',
                'NVQ/QCF Level 4+'        => 'NVQ/QCF Level 4 or above',
                'Degree or above'         => 'Degree or above',
                'Other'                   => 'Other',
            ];
            $selLevel = $education['highest_education_level'] ?? '';
            ?>
            <?php foreach ($levels as $val => $label): ?>
                <option value="<?= htmlspecialchars($val); ?>"
                    <?= $selLevel === $val ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- QUALIFICATIONS -->
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-xs font-semibold text-slate-900">
                Relevant qualifications
            </h3>
            <button
                type="button"
                id="add-qualification-btn"
                class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-1.5 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50"
            >
                + Add qualification
            </button>
        </div>
        <p class="text-[10px] text-sc-text-muted">
            Include any qualifications related to health and social care, nursing, or other relevant subjects.
        </p>

        <div class="space-y-3" id="qualifications-container" data-next-index="<?= count($qualifications); ?>">
            <?php foreach ($qualifications as $i => $q): ?>
                <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 qualification-block" data-qual-index="<?= $i; ?>">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <h4 class="text-[11px] font-semibold text-slate-900">
                            Qualification <?= $i + 1; ?>
                        </h4>
                        <?php if ($i > 0): ?>
                            <button
                                type="button"
                                class="text-[10px] text-sc-text-muted hover:text-red-600 remove-qualification-btn"
                            >
                                Remove
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                                Qualification name
                            </label>
                            <input
                                type="text"
                                name="qualifications[<?= $i; ?>][name]"
                                value="<?= sc_old_qual($qualifications, $i, 'name'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                                placeholder="e.g. NVQ Level 2 in Health & Social Care"
                            >
                        </div>
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                                Provider / college
                            </label>
                            <input
                                type="text"
                                name="qualifications[<?= $i; ?>][provider]"
                                value="<?= sc_old_qual($qualifications, $i, 'provider'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                                placeholder="e.g. Kingston College"
                            >
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 mt-2">
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                                Date achieved
                            </label>
                            <input
                                type="month"
                                name="qualifications[<?= $i; ?>][date_achieved]"
                                value="<?= sc_old_qual($qualifications, $i, 'date_achieved'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            >
                        </div>
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted">
                                Notes (optional)
                            </label>
                            <input
                                type="text"
                                name="qualifications[<?= $i; ?>][notes]"
                                value="<?= sc_old_qual($qualifications, $i, 'notes'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                                placeholder="e.g. Currently studying Level 3"
                            >
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- PROFESSIONAL REGISTRATIONS -->
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-xs font-semibold text-slate-900">
                Professional registrations (if applicable)
            </h3>
            <button
                type="button"
                id="add-registration-btn"
                class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-1.5 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50"
            >
                + Add registration
            </button>
        </div>
        <p class="text-[10px] text-sc-text-muted">
            For example: NMC (nursing), HCPC or other registration bodies. If not applicable, you can leave this section blank.
        </p>

        <div class="space-y-3" id="registrations-container" data-next-index="<?= count($registrations); ?>">
            <?php foreach ($registrations as $i => $r): ?>
                <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 registration-block" data-reg-index="<?= $i; ?>">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <h4 class="text-[11px] font-semibold text-slate-900">
                            Registration <?= $i + 1; ?>
                        </h4>
                        <?php if ($i > 0): ?>
                            <button
                                type="button"
                                class="text-[10px] text-sc-text-muted hover:text-red-600 remove-registration-btn"
                            >
                                Remove
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                                Registration body
                            </label>
                            <input
                                type="text"
                                name="registrations[<?= $i; ?>][body]"
                                value="<?= sc_old_reg($registrations, $i, 'body'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                                placeholder="e.g. NMC"
                            >
                        </div>
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                                Registration / PIN number
                            </label>
                            <input
                                type="text"
                                name="registrations[<?= $i; ?>][number]"
                                value="<?= sc_old_reg($registrations, $i, 'number'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                                placeholder="e.g. 12A3456B"
                            >
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 mt-2">
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                                Renewal / expiry date
                            </label>
                            <input
                                type="date"
                                name="registrations[<?= $i; ?>][renewal_date]"
                                value="<?= sc_old_reg($registrations, $i, 'renewal_date'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            >
                        </div>
                        <div class="space-y-1">
                            <label class="font-medium text-sc-text-muted">
                                Notes (optional)
                            </label>
                            <input
                                type="text"
                                name="registrations[<?= $i; ?>][notes]"
                                value="<?= sc_old_reg($registrations, $i, 'notes'); ?>"
                                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                                placeholder="e.g. On revalidation cycle"
                            >
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- KEY TRAINING -->
    <div class="space-y-2">
        <h3 class="text-xs font-semibold text-slate-900">
            Key training (summary)
        </h3>
        <p class="text-[10px] text-sc-text-muted">
            You will be able to provide full details and upload certificates later. For now, please give a brief summary.
        </p>

        <div class="grid gap-3 md:grid-cols-2">
            <?php
            $training = $education['training'] ?? [];
            $checked = function($field) use ($training) {
                return !empty($training[$field]) ? 'checked' : '';
            };
            ?>
            <div class="space-y-1">
                <label class="inline-flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="training[moving_handling]"
                        value="1"
                        <?= $checked('moving_handling'); ?>
                        class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
                    >
                    <span class="text-sc-text-muted">Moving & handling</span>
                </label><br>
                <label class="inline-flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="training[medication]"
                        value="1"
                        <?= $checked('medication'); ?>
                        class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
                    >
                    <span class="text-sc-text-muted">Medication training</span>
                </label><br>
                <label class="inline-flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="training[first_aid]"
                        value="1"
                        <?= $checked('first_aid'); ?>
                        class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
                    >
                    <span class="text-sc-text-muted">First aid / CPR</span>
                </label>
            </div>
            <div class="space-y-1">
                <label class="inline-flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="training[dementia]"
                        value="1"
                        <?= $checked('dementia'); ?>
                        class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
                    >
                    <span class="text-sc-text-muted">Dementia awareness</span>
                </label><br>
                <label class="inline-flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="training[infection_control]"
                        value="1"
                        <?= $checked('infection_control'); ?>
                        class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
                    >
                    <span class="text-sc-text-muted">Infection control</span>
                </label><br>
                <label class="inline-flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="training[other]"
                        value="1"
                        <?= $checked('other'); ?>
                        class="rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
                    >
                    <span class="text-sc-text-muted">Other relevant training</span>
                </label>
            </div>
        </div>

        <div class="space-y-1">
            <label class="font-medium text-sc-text-muted">
                Brief training summary (optional)
            </label>
            <textarea
                name="training_summary"
                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                placeholder="e.g. Completed mandatory care training within the last 12 months, including moving & handling, infection control and fire safety."
            ><?= sc_old_edu($education, 'training_summary'); ?></textarea>
        </div>
    </div>

    <!-- Actions -->
    <div class="pt-3 flex items-center justify-between">
        <?php
        $backQs = ['token' => $token, 'step' => '2'];
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
            Save & continue to references →
        </button>
    </div>
</form>

<!-- TEMPLATE: qualification -->
<template id="qualification-template">
    <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 qualification-block" data-qual-index="__INDEX__">
        <div class="flex items-center justify-between gap-2 mb-2">
            <h4 class="text-[11px] font-semibold text-slate-900">
                Qualification __NUMBER__
            </h4>
            <button
                type="button"
                class="text-[10px] text-sc-text-muted hover:text-red-600 remove-qualification-btn"
            >
                Remove
            </button>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Qualification name
                </label>
                <input
                    type="text"
                    name="qualifications[__INDEX__][name]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. NVQ Level 2 in Health & Social Care"
                >
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Provider / college
                </label>
                <input
                    type="text"
                    name="qualifications[__INDEX__][provider]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. Kingston College"
                >
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 mt-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Date achieved
                </label>
                <input
                    type="month"
                    name="qualifications[__INDEX__][date_achieved]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted">
                    Notes (optional)
                </label>
                <input
                    type="text"
                    name="qualifications[__INDEX__][notes]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. Currently studying Level 3"
                >
            </div>
        </div>
    </div>
</template>

<!-- TEMPLATE: registration -->
<template id="registration-template">
    <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 registration-block" data-reg-index="__INDEX__">
        <div class="flex items-center justify-between gap-2 mb-2">
            <h4 class="text-[11px] font-semibold text-slate-900">
                Registration __NUMBER__
            </h4>
            <button
                type="button"
                class="text-[10px] text-sc-text-muted hover:text-red-600 remove-registration-btn"
            >
                Remove
            </button>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Registration body
                </label>
                <input
                    type="text"
                    name="registrations[__INDEX__][body]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. NMC"
                >
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Registration / PIN number
                </label>
                <input
                    type="text"
                    name="registrations[__INDEX__][number]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. 12A3456B"
                >
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 mt-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Renewal / expiry date
                </label>
                <input
                    type="date"
                    name="registrations[__INDEX__][renewal_date]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted">
                    Notes (optional)
                </label>
                <input
                    type="text"
                    name="registrations[__INDEX__][notes]"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. On revalidation cycle"
                >
            </div>
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Qualifications
    const qualContainer = document.getElementById('qualifications-container');
    const addQualBtn    = document.getElementById('add-qualification-btn');
    const qualTpl       = document.getElementById('qualification-template');

    function reindexQualifications() {
        const blocks = qualContainer.querySelectorAll('.qualification-block');
        blocks.forEach((block, idx) => {
            const heading = block.querySelector('h4');
            if (heading) heading.textContent = 'Qualification ' + (idx + 1);
        });
    }

    function attachQualRemoveHandlers() {
        qualContainer.querySelectorAll('.remove-qualification-btn').forEach(btn => {
            btn.onclick = function () {
                const block = btn.closest('.qualification-block');
                if (!block) return;
                block.remove();
                reindexQualifications();
            };
        });
    }

    if (addQualBtn && qualTpl && qualContainer) {
        addQualBtn.addEventListener('click', function () {
            let nextIndex = parseInt(qualContainer.getAttribute('data-next-index') || '0', 10);
            if (isNaN(nextIndex)) nextIndex = 0;

            let html = qualTpl.innerHTML.replace(/__INDEX__/g, nextIndex);
            html = html.replace(/__NUMBER__/g, nextIndex + 1);

            const wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            const block = wrapper.firstElementChild;
            qualContainer.appendChild(block);

            qualContainer.setAttribute('data-next-index', String(nextIndex + 1));
            attachQualRemoveHandlers();
        });
    }
    attachQualRemoveHandlers();

    // Registrations
    const regContainer = document.getElementById('registrations-container');
    const addRegBtn    = document.getElementById('add-registration-btn');
    const regTpl       = document.getElementById('registration-template');

    function reindexRegistrations() {
        const blocks = regContainer.querySelectorAll('.registration-block');
        blocks.forEach((block, idx) => {
            const heading = block.querySelector('h4');
            if (heading) heading.textContent = 'Registration ' + (idx + 1);
        });
    }

    function attachRegRemoveHandlers() {
        regContainer.querySelectorAll('.remove-registration-btn').forEach(btn => {
            btn.onclick = function () {
                const block = btn.closest('.registration-block');
                if (!block) return;
                block.remove();
                reindexRegistrations();
            };
        });
    }

    if (addRegBtn && regTpl && regContainer) {
        addRegBtn.addEventListener('click', function () {
            let nextIndex = parseInt(regContainer.getAttribute('data-next-index') || '0', 10);
            if (isNaN(nextIndex)) nextIndex = 0;

            let html = regTpl.innerHTML.replace(/__INDEX__/g, nextIndex);
            html = html.replace(/__NUMBER__/g, nextIndex + 1);

            const wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            const block = wrapper.firstElementChild;
            regContainer.appendChild(block);

            regContainer.setAttribute('data-next-index', String(nextIndex + 1));
            attachRegRemoveHandlers();
        });
    }
    attachRegRemoveHandlers();
});
</script>
