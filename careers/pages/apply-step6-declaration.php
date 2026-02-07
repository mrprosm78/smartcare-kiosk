<?php
// pages/apply-step7-declaration.php

$decl = $_SESSION['application']['declaration'] ?? [];

function sc_checked(array $src, string $key): string {
    return !empty($src[$key]) ? 'checked' : '';
}
function sc_old_decl(array $src, string $key): string {
    return htmlspecialchars($src[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
?>
<form method="post" action="<?= h($formAction) ?>" class="space-y-5 text-[11px]">
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <input type="hidden" name="job" value="<?= h($jobSlug) ?>">
  <input type="hidden" name="step" value="<?= (int)$step ?>">

  <?php sc_csrf_field(); ?>
<p class="text-sc-text-muted">
        Please read the declarations carefully. By submitting, you confirm your application is accurate and you consent
        to SmartCare Solutions processing your data for recruitment purposes.
    </p>

    <div class="rounded-2xl border border-sc-border bg-slate-50 px-4 py-4 space-y-3">
        <h3 class="text-xs font-semibold text-slate-900">
            Declarations
        </h3>

        <label class="flex items-start gap-3">
            <input
                type="checkbox"
                name="confirm_true_information"
                value="1"
                <?= sc_checked($decl, 'confirm_true_information'); ?>
                class="mt-0.5 rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
            >
            <span class="text-sc-text-muted">
                I confirm that the information provided in this application is true and complete to the best of my knowledge.
            </span>
        </label>

        <label class="flex items-start gap-3">
            <input
                type="checkbox"
                name="aware_of_false_info_consequences"
                value="1"
                <?= sc_checked($decl, 'aware_of_false_info_consequences'); ?>
                class="mt-0.5 rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
            >
            <span class="text-sc-text-muted">
                I understand that providing false or misleading information may lead to withdrawal of an offer or dismissal.
            </span>
        </label>

        <label class="flex items-start gap-3">
            <input
                type="checkbox"
                name="consent_to_processing"
                value="1"
                <?= sc_checked($decl, 'consent_to_processing'); ?>
                class="mt-0.5 rounded border-sc-border text-sc-primary focus:ring-sc-primary-soft"
            >
            <span class="text-sc-text-muted">
                I consent to SmartCare Solutions processing my personal data for recruitment and pre-employment checks,
                in line with the privacy notice.
            </span>
        </label>

        <div class="pt-2 border-t border-sc-border">
            <p class="text-[10px] text-sc-text-muted">
                In the full system, you will be shown the care home privacy notice and given an option to download a copy.
            </p>
        </div>
    </div>

    <!-- Signature -->
    <div class="rounded-2xl border border-sc-border bg-white px-4 py-4 space-y-3">
        <h3 class="text-xs font-semibold text-slate-900">
            Signature
        </h3>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Type your full name
                </label>
                <input
                    type="text"
                    name="typed_signature"
                    value="<?= sc_old_decl($decl, 'typed_signature'); ?>"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                    placeholder="Your full name"
                >
                <p class="text-[10px] text-sc-text-muted">
                    This acts as your electronic signature for this application.
                </p>
            </div>

            <div class="space-y-1">
                <label class="font-medium text-sc-text-muted uppercase tracking-[0.12em]">
                    Date
                </label>
                <input
                    type="date"
                    name="signature_date"
                    value="<?= sc_old_decl($decl, 'signature_date') ?: $today; ?>"
                    class="w-full rounded-md border border-sc-border bg-white px-3 py-2 text-xs"
                >
            </div>
        </div>
    </div>

    <!-- Optional: review note -->
    <div class="rounded-xl border border-sc-border bg-slate-50 px-4 py-3">
        <p class="text-[11px] text-sc-text-muted">
            After you submit, SmartCare Solutions will show a confirmation message.
            In the full build, we’ll add a “Review application” screen (summary of steps 1–6) before submission.
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
            class="inline-flex items-center rounded-md bg-sc-primary px-4 py-2 text-[11px] font-medium text-white hover:bg-sc-primary-hover"
        >
            Submit application
        </button>
    </div>

</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('declaration-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const mustCheck = ['confirm_true_information', 'aware_of_false_info_consequences', 'consent_to_processing'];
        const allChecked = mustCheck.every(name => form.querySelector(`input[name="${name}"]`)?.checked);

        const signature = form.querySelector('input[name="typed_signature"]')?.value.trim();

        if (!allChecked || !signature) {
            e.preventDefault();
            alert('Please tick all declarations and type your full name before submitting.');
        }
    });
});
</script>
