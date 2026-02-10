<?php
// pages/apply-step7-review.php

$app = $_SESSION['application'] ?? [];

$personal = $app['personal'] ?? [];
$role     = $app['role'] ?? [];
$work     = $app['work_history'] ?? [];
$edu      = $app['education'] ?? [];
$refs     = $app['references'] ?? [];
$checks   = $app['checks'] ?? [];

$jobs     = $work['jobs'] ?? [];
$quals    = $edu['qualifications'] ?? [];
$regs     = $edu['registrations'] ?? [];
$refList  = $refs['references'] ?? [];

function scv($v): string {
    $v = is_string($v) ? trim($v) : $v;
    if ($v === null || $v === '' || $v === []) return '—';
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function yesno($v): string {
    if ($v === 'yes' || $v === '1' || $v === 1) return 'Yes';
    if ($v === 'no') return 'No';
    if ($v === 'in_progress') return 'In progress';
    if ($v === 'unsure') return 'Not sure';
    return '—';
}
function monthYear($m, $y): string {
    if (!$m && !$y) return '—';
    $months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
    $mm = $m ? ($months[(int)$m] ?? $m) : '';
    $yy = $y ? $y : '';
    $out = trim($mm . ' ' . $yy);
    return $out !== '' ? $out : '—';
}
function normalizeJobs(array $jobs): array {
    // Keep only non-empty jobs
    $clean = [];
    foreach ($jobs as $j) {
        $hasContent = false;
        foreach (['employer_name','job_title','start_year','end_year','start_month','end_month'] as $k) {
            if (!empty($j[$k])) { $hasContent = true; break; }
        }
        if ($hasContent) $clean[] = $j;
    }
    return $clean;
}
function jobToStartDate(array $j): ?DateTimeImmutable {
    $y = (int)($j['start_year'] ?? 0);
    $m = (int)($j['start_month'] ?? 0);
    if ($y <= 0 || $m <= 0) return null;
    return new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));
}
function jobToEndDate(array $j): ?DateTimeImmutable {
    if (!empty($j['is_current'])) return new DateTimeImmutable('now');
    $y = (int)($j['end_year'] ?? 0);
    $m = (int)($j['end_month'] ?? 0);
    if ($y <= 0 || $m <= 0) return null;
    // end-of-month
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));
    return $start->modify('last day of this month');
}
function detectGaps(array $jobs, int $gapDays = 31): array {
    // Very simple initial: sort by start date desc, then check gaps between end of newer job and start of older job
    $items = [];
    foreach ($jobs as $j) {
        $s = jobToStartDate($j);
        $e = jobToEndDate($j);
        if (!$s || !$e) continue;
        $items[] = ['job' => $j, 'start' => $s, 'end' => $e];
    }
    if (count($items) < 2) return [];

    usort($items, fn($a,$b) => $b['start'] <=> $a['start']); // newest start first

    $gaps = [];
    for ($i=0; $i<count($items)-1; $i++) {
        $newer = $items[$i];
        $older = $items[$i+1];

        // if older starts after newer ends, the data is odd, ignore
        if ($older['start'] > $newer['end']) continue;

        $gapStart = $older['start'];
        $gapEnd   = $newer['end'];

        // Actual gap is from end of older? depends on ordering; we want gap between older end and newer start
        // Better: sort by end date desc for timeline. We'll do a simpler timeline sort by start asc:
        // For now, compute gap between older end and newer start:
        $gapA = jobToEndDate($older['job']);
        $gapB = jobToStartDate($newer['job']);
        if (!$gapA || !$gapB) continue;

        $diff = $gapB->diff($gapA);
        // if newer starts AFTER older ends -> there is a gap
        if ($gapB > $gapA) {
            $days = (int)$gapA->diff($gapB)->format('%a');
            if ($days >= $gapDays) {
                $gaps[] = [
                    'from' => $gapA->modify('+1 day'),
                    'to'   => $gapB->modify('-1 day'),
                    'days' => $days,
                ];
            }
        }
    }
    return $gaps;
}

/** -------------------------
 * Warnings / validation summary (initial)
 * ------------------------ */
$warnings = [];

// Personal required fields (basic)
if (empty(trim($personal['first_name'] ?? '')) || empty(trim($personal['last_name'] ?? ''))) {
    $warnings[] = ['type'=>'missing', 'label'=>'Personal details: name is missing', 'step'=>1];
}
if (empty(trim($personal['email'] ?? ''))) {
    $warnings[] = ['type'=>'missing', 'label'=>'Personal details: email is missing', 'step'=>1];
}
if (empty(trim($personal['mobile'] ?? ''))) {
    $warnings[] = ['type'=>'missing', 'label'=>'Personal details: mobile number is missing', 'step'=>1];
}

// Role
if (empty(trim($role['position_applied_for'] ?? ''))) {
    $warnings[] = ['type'=>'missing', 'label'=>'Role: position applied for is missing', 'step'=>2];
}

// Work
$cleanJobs = normalizeJobs($jobs);
if (count($cleanJobs) === 0) {
    $warnings[] = ['type'=>'missing', 'label'=>'Work history: no jobs added', 'step'=>3];
}

// Gap detection (only warn if we have multiple jobs with valid dates)
$gaps = detectGaps($cleanJobs, 31);
$gapExplanationText = trim((string)($work['gap_explanations'] ?? ''));
if (!empty($gaps) && $gapExplanationText === '') {
    $warnings[] = ['type'=>'gap', 'label'=>'Work history: gaps detected — please explain them', 'step'=>3];
}

// Education
if (empty(trim($edu['highest_education_level'] ?? ''))) {
    $warnings[] = ['type'=>'missing', 'label'=>'Education: highest level is missing', 'step'=>4];
}

// References
$nonEmptyRefs = [];
foreach ($refList as $r) {
    if (!empty(trim($r['name'] ?? '')) || !empty(trim($r['email'] ?? '')) || !empty(trim($r['phone'] ?? ''))) {
        $nonEmptyRefs[] = $r;
    }
}
if (count($nonEmptyRefs) < 2) {
    $warnings[] = ['type'=>'missing', 'label'=>'References: please add at least 2 references', 'step'=>5];
}

// Checks
if (empty(trim($checks['has_right_to_work'] ?? ''))) {
    $warnings[] = ['type'=>'missing', 'label'=>'Checks: right to work answer is missing', 'step'=>6];
}
if (empty(trim($checks['has_current_dbs'] ?? ''))) {
    $warnings[] = ['type'=>'missing', 'label'=>'Checks: DBS status is missing', 'step'=>6];
}
?>

<div class="space-y-5 text-[11px]">

    <p class="text-sc-text-muted">
        Please review your application before submitting. If anything is incorrect, use the edit button for that section.
    </p>

    <?php if (!empty($warnings)): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-900">A few things to check</p>
                    <p class="text-[10px] text-slate-600 mt-1">
                        You can still continue, but we recommend fixing these before submitting.
                    </p>
                </div>
                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-[10px] font-semibold text-amber-700">
                    <?= count($warnings); ?> warning<?= count($warnings) === 1 ? '' : 's'; ?>
                </span>
            </div>

            <ul class="mt-3 space-y-2">
                <?php foreach ($warnings as $w): ?>
                    <li class="flex items-start justify-between gap-3">
                        <span class="text-[11px] text-slate-700"><?= scv($w['label']); ?></span>
                        <a href="apply.php?step=<?= (int)$w['step']; ?>" class="text-[11px] font-medium text-sc-primary hover:text-sc-primary">
                            Fix
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (!empty($gaps)): ?>
                <div class="mt-3 rounded-xl border border-amber-200 bg-white px-3 py-2">
                    <p class="text-[10px] font-semibold text-slate-900">Detected gaps (initial)</p>
                    <ul class="mt-1 space-y-1 text-[10px] text-slate-700">
                        <?php foreach ($gaps as $g): ?>
                            <li>
                                <?= scv($g['from']->format('d M Y')); ?> → <?= scv($g['to']->format('d M Y')); ?>
                                <span class="text-slate-500">(about <?= (int)$g['days']; ?> days)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-1 text-[10px] text-slate-500">
                        If these are correct, just explain them in the “Gap explanations” box on Step 3.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- PERSONAL -->
    <div class="rounded-2xl border border-sc-border bg-white px-4 py-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-xs font-semibold text-slate-900">Personal details</h3>
            <a href="apply.php?step=1" class="text-[11px] font-medium text-sc-primary hover:text-sc-primary">Edit</a>
        </div>

        <div class="mt-3 grid gap-3 md:grid-cols-2">
            <div class="space-y-1">
                <p class="text-sc-text-muted">Name</p>
                <p class="font-medium text-slate-900">
                    <?= scv(trim(($personal['title'] ?? '') . ' ' . ($personal['first_name'] ?? '') . ' ' . ($personal['last_name'] ?? ''))); ?>
                </p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Date of birth</p>
                <p class="font-medium text-slate-900"><?= scv($personal['dob'] ?? ''); ?></p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Email</p>
                <p class="font-medium text-slate-900"><?= scv($personal['email'] ?? ''); ?></p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Mobile</p>
                <p class="font-medium text-slate-900"><?= scv($personal['mobile'] ?? ''); ?></p>
            </div>
            <div class="space-y-1 md:col-span-2">
                <p class="text-sc-text-muted">Address</p>
                <p class="font-medium text-slate-900">
                    <?php
                    $addrParts = [
                        trim((string)($personal['address_line1'] ?? '')),
                        trim((string)($personal['address_line2'] ?? '')),
                        trim((string)($personal['address_town'] ?? '')),
                        trim((string)($personal['address_county'] ?? '')),
                        trim((string)($personal['address_postcode'] ?? '')),
                    ];
                    $addrParts = array_values(array_filter($addrParts, fn($p)=>$p !== ''));
                    echo scv(!empty($addrParts) ? implode(', ', $addrParts) : '—');
                    ?>
                </p>
            </div>
        </div>
    </div>

    <!-- ROLE -->
    <div class="rounded-2xl border border-sc-border bg-white px-4 py-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-xs font-semibold text-slate-900">Role & availability</h3>
            <a href="apply.php?step=2" class="text-[11px] font-medium text-sc-primary hover:text-sc-primary">Edit</a>
        </div>

        <div class="mt-3 grid gap-3 md:grid-cols-3">
            <div class="space-y-1">
                <p class="text-sc-text-muted">Position</p>
                <p class="font-medium text-slate-900"><?= scv($role['position_applied_for'] ?? ''); ?></p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Unit</p>
                <p class="font-medium text-slate-900"><?= scv($role['preferred_unit'] ?? ''); ?></p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Work type</p>
                <p class="font-medium text-slate-900"><?= scv($role['work_type'] ?? ''); ?></p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Shift pattern</p>
                <p class="font-medium text-slate-900"><?= scv($role['preferred_shift_pattern'] ?? ''); ?></p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Hours / week</p>
                <p class="font-medium text-slate-900"><?= scv($role['hours_per_week'] ?? ''); ?></p>
            </div>
            <div class="space-y-1">
                <p class="text-sc-text-muted">Earliest start</p>
                <p class="font-medium text-slate-900"><?= scv($role['earliest_start_date'] ?? ''); ?></p>
            </div>
        </div>
    </div>

    <!-- WORK HISTORY -->
    <div class="rounded-2xl border border-sc-border bg-white px-4 py-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-xs font-semibold text-slate-900">Work history</h3>
            <a href="apply.php?step=3" class="text-[11px] font-medium text-sc-primary hover:text-sc-primary">Edit</a>
        </div>

        <div class="mt-3 space-y-3">
            <?php if (empty($cleanJobs)): ?>
                <p class="text-sc-text-muted">No jobs added.</p>
            <?php else: ?>
                <?php foreach ($cleanJobs as $j): ?>
                    <div class="rounded-xl border border-sc-border bg-slate-50 px-3 py-2">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-semibold text-slate-900"><?= scv($j['job_title'] ?? ''); ?></p>
                            <p class="text-[10px] text-sc-text-muted"><?= scv($j['employer_name'] ?? ''); ?></p>
                        </div>
                        <p class="text-[10px] text-sc-text-muted mt-1">
                            <?= monthYear($j['start_month'] ?? '', $j['start_year'] ?? ''); ?>
                            →
                            <?= !empty($j['is_current']) ? 'Present' : monthYear($j['end_month'] ?? '', $j['end_year'] ?? ''); ?>
                            · Care role: <?= yesno($j['is_care_role'] ?? ''); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="rounded-xl bg-slate-50 border border-sc-border px-3 py-2">
                <p class="text-sc-text-muted">Gap explanations</p>
                <p class="text-slate-900"><?= scv($work['gap_explanations'] ?? ''); ?></p>
            </div>
        </div>
    </div>

    <!-- EDUCATION -->
    <div class="rounded-2xl border border-sc-border bg-white px-4 py-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-xs font-semibold text-slate-900">Education & training</h3>
            <a href="apply.php?step=4" class="text-[11px] font-medium text-sc-primary hover:text-sc-primary">Edit</a>
        </div>

        <div class="mt-3 space-y-3">
            <div class="rounded-xl bg-slate-50 border border-sc-border px-3 py-2">
                <p class="text-sc-text-muted">Highest education level</p>
                <p class="font-medium text-slate-900"><?= scv($edu['highest_education_level'] ?? ''); ?></p>
            </div>

            <div class="rounded-xl bg-slate-50 border border-sc-border px-3 py-2">
                <p class="text-sc-text-muted">Qualifications</p>
                <?php
                $qualLines = [];
                foreach ($quals as $q) {
                    $name = trim((string)($q['name'] ?? ''));
                    $provider = trim((string)($q['provider'] ?? ''));
                    $date = trim((string)($q['date_achieved'] ?? ''));
                    if ($name === '' && $provider === '' && $date === '') continue;
                    $qualLines[] = [
                        'name' => $name,
                        'provider' => $provider,
                        'date' => $date,
                    ];
                }
                ?>
                <?php if (empty($qualLines)): ?>
                    <p class="text-slate-900">—</p>
                <?php else: ?>
                    <ul class="mt-1 space-y-1">
                        <?php foreach ($qualLines as $q): ?>
                            <li class="text-slate-900">
                                <span class="font-medium"><?= scv($q['name']); ?></span>
                                <span class="text-sc-text-muted"> · <?= scv($q['provider']); ?> · <?= scv($q['date']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="rounded-xl bg-slate-50 border border-sc-border px-3 py-2">
                <p class="text-sc-text-muted">Training summary</p>
                <p class="text-slate-900"><?= scv($edu['training_summary'] ?? ''); ?></p>
            </div>
        </div>
    </div>

    <!-- REFERENCES -->
    <div class="rounded-2xl border border-sc-border bg-white px-4 py-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-xs font-semibold text-slate-900">References</h3>
            <a href="apply.php?step=5" class="text-[11px] font-medium text-sc-primary hover:text-sc-primary">Edit</a>
        </div>

        <div class="mt-3 space-y-2">
            <?php if (empty($nonEmptyRefs)): ?>
                <p class="text-sc-text-muted">No references added.</p>
            <?php else: ?>
                <?php foreach ($nonEmptyRefs as $r): ?>
                    <div class="rounded-xl border border-sc-border bg-slate-50 px-3 py-2">
                        <p class="font-semibold text-slate-900"><?= scv($r['name'] ?? ''); ?></p>
                        <p class="text-[10px] text-sc-text-muted">
                            <?= scv($r['job_title'] ?? ''); ?> · <?= scv($r['organisation'] ?? ''); ?>
                            · Contact now: <?= yesno($r['can_contact_now'] ?? ''); ?>
                        </p>
                        <p class="text-[10px] text-sc-text-muted">
                            <?= scv($r['email'] ?? ''); ?> · <?= scv($r['phone'] ?? ''); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- CHECKS -->
    <div class="rounded-2xl border border-sc-border bg-white px-4 py-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-xs font-semibold text-slate-900">Right to work & checks</h3>
            <a href="apply.php?step=6" class="text-[11px] font-medium text-sc-primary hover:text-sc-primary">Edit</a>
        </div>

        <div class="mt-3 grid gap-3 md:grid-cols-2">
            <div class="rounded-xl border border-sc-border bg-slate-50 px-3 py-2">
                <p class="text-sc-text-muted">Right to work in UK</p>
                <p class="font-medium text-slate-900"><?= yesno($checks['has_right_to_work'] ?? ''); ?></p>
            </div>
            <div class="rounded-xl border border-sc-border bg-slate-50 px-3 py-2">
                <p class="text-sc-text-muted">Requires sponsorship</p>
                <p class="font-medium text-slate-900"><?= yesno($checks['requires_sponsorship'] ?? ''); ?></p>
            </div>
            <div class="rounded-xl border border-sc-border bg-slate-50 px-3 py-2">
                <p class="text-sc-text-muted">Barred from working</p>
                <p class="font-medium text-slate-900"><?= yesno($checks['barred_from_working'] ?? ''); ?></p>
            </div>
            <div class="rounded-xl border border-sc-border bg-slate-50 px-3 py-2">
                <p class="text-sc-text-muted">Current DBS</p>
                <p class="font-medium text-slate-900"><?= yesno($checks['has_current_dbs'] ?? ''); ?></p>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="pt-1 flex items-center justify-between">
        <a
            href="apply.php?step=6"
            class="inline-flex items-center rounded-md border border-sc-border bg-white px-3 py-2 text-[11px] font-medium text-sc-text-muted hover:bg-slate-50"
        >
            ← Back
        </a>

        <a
            href="apply.php?step=8"
            class="inline-flex items-center rounded-md bg-sc-primary px-4 py-2 text-[11px] font-medium text-white hover:bg-sc-primary-hover"
        >
            Continue to declaration →
        </a>
    </div>

</div>
