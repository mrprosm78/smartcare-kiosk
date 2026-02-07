<?php
// pages/apply-step6-righttowork.php

$checks = $_SESSION['application']['checks'] ?? [];

function sc_old_checks(array $src, string $key): string {
    return htmlspecialchars($src[$key] ?? '', ENT_QUOTES, 'UTF-8');
}
function sc_selected(array $src, string $key, string $value): string {
    return (($src[$key] ?? '') === $value) ? 'selected' : '';
}
?>
<form method="post" action="<?= h($formAction) ?>" class="space-y-5 text-[11px]">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input type="hidden" name="job" value="<?= h($jobSlug) ?>">
  <input type="hidden" name="step" value="<?= (int)$step ?>">

  <?php sc_csrf_field(); ?>
<p class="text-sc-text-muted">
        This section helps us understand your eligibility to work and any checks that may be required.
        Documents (passport/visa/DBS) will be requested later if you progress.
    </p>

    <!-- Right to work -->
    <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 space-y-3">
        <h3 class="text-xs font-semibold text-slate-900">
            Right to work in the UK
        </h3>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Do you have the right to work in the UK?
                </label>
                <select
                    name="has_right_to_work"
                    id="has_right_to_work"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
                    <option value="">Select</option>
                    <option value="yes" <?= sc_selected($checks, 'has_right_to_work', 'yes'); ?>>Yes</option>
                    <option value="no"  <?= sc_selected($checks, 'has_right_to_work', 'no'); ?>>No</option>
                </select>
            </div>

            <div class="space-y-1" id="visa_type_wrap">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    If no, what type of visa / permission do you hold?
                </label>
                <input
                    type="text"
                    name="visa_type"
                    value="<?= sc_old_checks($checks, 'visa_type'); ?>"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. Skilled Worker visa, Student visa, etc."
                >
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2" id="sponsorship_wrap">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Do you require sponsorship?
                </label>
                <select
                    name="requires_sponsorship"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
                    <option value="">Select</option>
                    <option value="yes" <?= sc_selected($checks, 'requires_sponsorship', 'yes'); ?>>Yes</option>
                    <option value="no"  <?= sc_selected($checks, 'requires_sponsorship', 'no'); ?>>No</option>
                </select>
            </div>

            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Notes (optional)
                </label>
                <input
                    type="text"
                    name="rtw_notes"
                    value="<?= sc_old_checks($checks, 'rtw_notes'); ?>"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="Anything relevant (optional)"
                >
            </div>
        </div>
    </div>

    <!-- Safeguarding declaration -->
    <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 space-y-3">
        <h3 class="text-xs font-semibold text-slate-900">
            Safeguarding declaration
        </h3>

        <div class="space-y-1">
            <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                Are you barred from working with vulnerable adults or children?
            </label>
            <select
                name="barred_from_working"
                class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
            >
                <option value="">Select</option>
                <option value="no"  <?= sc_selected($checks, 'barred_from_working', 'no'); ?>>No</option>
                <option value="yes" <?= sc_selected($checks, 'barred_from_working', 'yes'); ?>>Yes</option>
                <option value="unsure" <?= sc_selected($checks, 'barred_from_working', 'unsure'); ?>>I am not sure</option>
            </select>
        </div>

        <p class="text-[10px] text-sc-text-muted">
            If you are offered a role, appropriate safeguarding checks will be completed in line with care sector requirements.
        </p>
    </div>

    <!-- DBS -->
    <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-3 space-y-3">
        <h3 class="text-xs font-semibold text-slate-900">
            DBS (Disclosure and Barring Service)
        </h3>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Do you have a current DBS check?
                </label>
                <select
                    name="has_current_dbs"
                    id="has_current_dbs"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
                    <option value="">Select</option>
                    <option value="yes" <?= sc_selected($checks, 'has_current_dbs', 'yes'); ?>>Yes</option>
                    <option value="in_progress" <?= sc_selected($checks, 'has_current_dbs', 'in_progress'); ?>>In progress</option>
                    <option value="no"  <?= sc_selected($checks, 'has_current_dbs', 'no'); ?>>No</option>
                </select>
            </div>

            <div class="space-y-1" id="dbs_type_wrap">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    DBS type (if yes)
                </label>
                <select
                    name="dbs_type"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
                    <option value="">Select</option>
                    <option value="standard"  <?= sc_selected($checks, 'dbs_type', 'standard'); ?>>Standard</option>
                    <option value="enhanced"  <?= sc_selected($checks, 'dbs_type', 'enhanced'); ?>>Enhanced</option>
                    <option value="enhanced_barred" <?= sc_selected($checks, 'dbs_type', 'enhanced_barred'); ?>>Enhanced + barred list</option>
                </select>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2" id="update_service_wrap">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Are you on the DBS update service? (if yes)
                </label>
                <select
                    name="on_update_service"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
                    <option value="">Select</option>
                    <option value="yes" <?= sc_selected($checks, 'on_update_service', 'yes'); ?>>Yes</option>
                    <option value="no"  <?= sc_selected($checks, 'on_update_service', 'no'); ?>>No</option>
                    <option value="unsure" <?= sc_selected($checks, 'on_update_service', 'unsure'); ?>>Not sure</option>
                </select>
            </div>

            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    DBS notes (optional)
                </label>
                <input
                    type="text"
                    name="dbs_notes"
                    value="<?= sc_old_checks($checks, 'dbs_notes'); ?>"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="e.g. DBS completed in 2024"
                >
            </div>
        </div>

        <p class="text-[10px] text-sc-text-muted">
            If you are successful, SmartCare Solutions will guide you through the DBS process and document upload.
        </p>
    </div>

    <!-- Actions -->
    <div class="pt-3 flex items-center justify-between">
        <?php
        $backQs = ['token' => $token, 'step' => '5'];
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
            class="inline-flex items-center rounded-md bg-sc-primary px-3 py-2 text-[11px] font-medium text-white hover:bg-sc-primary-hover"
        >
            Save & continue to declaration →
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const rtw = document.getElementById('has_right_to_work');
    const visaWrap = document.getElementById('visa_type_wrap');
    const sponsorWrap = document.getElementById('sponsorship_wrap');

    const dbs = document.getElementById('has_current_dbs');
    const dbsTypeWrap = document.getElementById('dbs_type_wrap');
    const updateWrap = document.getElementById('update_service_wrap');

    function syncRTW() {
        const v = rtw ? rtw.value : '';
        const show = (v === 'no');
        if (visaWrap) visaWrap.style.display = show ? '' : 'none';
        if (sponsorWrap) sponsorWrap.style.display = show ? '' : 'none';
    }

    function syncDBS() {
        const v = dbs ? dbs.value : '';
        const show = (v === 'yes');
        if (dbsTypeWrap) dbsTypeWrap.style.display = show ? '' : 'none';
        if (updateWrap) updateWrap.style.display = show ? '' : 'none';
    }

    if (rtw) rtw.addEventListener('change', syncRTW);
    if (dbs) dbs.addEventListener('change', syncDBS);

    // Initial state on load (important when navigating back)
    syncRTW();
    syncDBS();
});
</script>
