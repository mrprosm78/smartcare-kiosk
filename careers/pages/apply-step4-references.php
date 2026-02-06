<?php
// pages/apply-step5-references.php

$refs = $_SESSION['application']['references'] ?? [];
$references = $refs['references'] ?? [[], []]; // default to 2 empty references

function sc_old_ref(array $rows, int $index, string $key): string {
    return htmlspecialchars($rows[$index][$key] ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<form method="post" action="<?= h($formAction) ?>" class="space-y-5 text-[11px]">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input type="hidden" name="job" value="<?= h($jobSlug) ?>">
  <input type="hidden" name="step" value="<?= (int)$step ?>">

  <?php sc_csrf_field(); ?>
<p class="text-sc-text-muted">
        Please provide at least two references. Ideally, one should be your most recent line manager or supervisor.
        You can add more references if needed.
    </p>

    <div class="space-y-4" id="references-container" data-next-index="<?= count($references); ?>">
        <?php foreach ($references as $i => $r): ?>
            <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 reference-block" data-ref-index="<?= $i; ?>">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <h3 class="text-xs font-semibold text-slate-900">
                        Reference <?= $i + 1; ?>
                    </h3>
                    <?php if ($i > 1): ?>
                        <button
                            type="button"
                            class="text-[10px] text-sc-text-muted hover:text-red-600 remove-reference-btn"
                        >
                            Remove
                        </button>
                    <?php endif; ?>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Full name
                        </label>
                        <input
                            type="text"
                            name="references[<?= $i; ?>][name]"
                            value="<?= sc_old_ref($references, $i, 'name'); ?>"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            placeholder="e.g. Sarah Thompson"
                        >
                    </div>

                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Job title
                        </label>
                        <input
                            type="text"
                            name="references[<?= $i; ?>][job_title]"
                            value="<?= sc_old_ref($references, $i, 'job_title'); ?>"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            placeholder="e.g. Registered Manager"
                        >
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2 mt-2">
                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Relationship to you
                        </label>
                        <input
                            type="text"
                            name="references[<?= $i; ?>][relationship]"
                            value="<?= sc_old_ref($references, $i, 'relationship'); ?>"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            placeholder="e.g. Line manager / Supervisor / Tutor"
                        >
                    </div>

                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Organisation
                        </label>
                        <input
                            type="text"
                            name="references[<?= $i; ?>][organisation]"
                            value="<?= sc_old_ref($references, $i, 'organisation'); ?>"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            placeholder="e.g. Rosewood Care Home"
                        >
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2 mt-2">
                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Email address
                        </label>
                        <input
                            type="email"
                            name="references[<?= $i; ?>][email]"
                            value="<?= sc_old_ref($references, $i, 'email'); ?>"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            placeholder="email@example.com"
                        >
                    </div>

                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Phone number
                        </label>
                        <input
                            type="tel"
                            name="references[<?= $i; ?>][phone]"
                            value="<?= sc_old_ref($references, $i, 'phone'); ?>"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                            placeholder="07... / 01..."
                        >
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2 mt-2">
                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Reference type
                        </label>
                        <?php
                        $typeSel = $references[$i]['reference_type'] ?? '';
                        $types = [
                            '' => 'Select',
                            'Employer' => 'Employer (preferred)',
                            'Character' => 'Character',
                            'Academic' => 'Academic',
                            'Other' => 'Other',
                        ];
                        ?>
                        <select
                            name="references[<?= $i; ?>][reference_type]"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                        >
                            <?php foreach ($types as $val => $label): ?>
                                <option value="<?= htmlspecialchars($val); ?>"
                                    <?= $typeSel === $val ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                            Can we contact them now?
                        </label>
                        <?php
                        $contactSel = $references[$i]['can_contact_now'] ?? '';
                        ?>
                        <select
                            name="references[<?= $i; ?>][can_contact_now]"
                            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                        >
                            <option value="">Select</option>
                            <option value="yes" <?= $contactSel === 'yes' ? 'selected' : ''; ?>>Yes</option>
                            <option value="no"  <?= $contactSel === 'no' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-1 mt-2">
                    <label class="font-medium text-sc-text-muted">
                        Address (optional)
                    </label>
                    <input
                        type="text"
                        name="references[<?= $i; ?>][address]"
                        value="<?= sc_old_ref($references, $i, 'address'); ?>"
                        class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                        placeholder="Optional: address or town/postcode"
                    >
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Add reference -->
    <div class="flex justify-end">
        <button
            type="button"
            id="add-reference-btn"
            class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-2 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50"
        >
            + Add another reference
        </button>
    </div>

    <!-- Optional notes -->
    <div class="space-y-1">
        <label class="font-medium text-sc-text-muted">
            Notes (optional)
        </label>
        <textarea
            name="reference_notes"
            class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
            placeholder="If there is anything important about your references (e.g. best times to contact), add it here."
        ><?= htmlspecialchars($refs['reference_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
    </div>

    <!-- Actions -->
    <div class="pt-3 flex items-center justify-between">
        <?php
        $backQs = ['token' => $token, 'step' => '3'];
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
            Save & continue to checks →
        </button>
    </div>
</form>

<!-- TEMPLATE: new reference -->
<template id="reference-template">
    <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 reference-block" data-ref-index="__INDEX__">
        <div class="flex items-center justify-between gap-2 mb-2">
            <h3 class="text-xs font-semibold text-slate-900">
                Reference __NUMBER__
            </h3>
            <button
                type="button"
                class="text-[10px] text-sc-text-muted hover:text-red-600 remove-reference-btn"
            >
                Remove
            </button>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Full name</label>
                <input type="text" name="references[__INDEX__][name]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Sarah Thompson">
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Job title</label>
                <input type="text" name="references[__INDEX__][job_title]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Registered Manager">
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 mt-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Relationship to you</label>
                <input type="text" name="references[__INDEX__][relationship]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Line manager / Supervisor">
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Organisation</label>
                <input type="text" name="references[__INDEX__][organisation]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="e.g. Rosewood Care Home">
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 mt-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Email address</label>
                <input type="email" name="references[__INDEX__][email]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="email@example.com">
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Phone number</label>
                <input type="tel" name="references[__INDEX__][phone]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="07... / 01...">
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 mt-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Reference type</label>
                <select name="references[__INDEX__][reference_type]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
                    <option value="">Select</option>
                    <option value="Employer">Employer (preferred)</option>
                    <option value="Character">Character</option>
                    <option value="Academic">Academic</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">Can we contact them now?</label>
                <select name="references[__INDEX__][can_contact_now]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs">
                    <option value="">Select</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                </select>
            </div>
        </div>

        <div class="space-y-1 mt-2">
            <label class="font-medium text-sc-text-muted">Address (optional)</label>
            <input type="text" name="references[__INDEX__][address]" class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs" placeholder="Optional: address or town/postcode">
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('references-container');
    const addBtn = document.getElementById('add-reference-btn');
    const template = document.getElementById('reference-template');
    if (!container || !addBtn || !template) return;

    function reindexHeadings() {
        container.querySelectorAll('.reference-block').forEach((block, idx) => {
            const h = block.querySelector('h3');
            if (h) h.textContent = 'Reference ' + (idx + 1);
        });
    }

    function attachRemoveHandlers() {
        container.querySelectorAll('.remove-reference-btn').forEach(btn => {
            btn.onclick = function () {
                const block = btn.closest('.reference-block');
                if (!block) return;
                block.remove();
                reindexHeadings();
            };
        });
    }

    addBtn.addEventListener('click', function () {
        let nextIndex = parseInt(container.getAttribute('data-next-index') || '0', 10);
        if (isNaN(nextIndex)) nextIndex = 0;

        let html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
        html = html.replace(/__NUMBER__/g, nextIndex + 1);

        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        container.appendChild(wrap.firstElementChild);

        container.setAttribute('data-next-index', String(nextIndex + 1));
        attachRemoveHandlers();
    });

    attachRemoveHandlers();
});
</script>
