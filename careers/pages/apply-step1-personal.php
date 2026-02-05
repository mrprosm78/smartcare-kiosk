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
