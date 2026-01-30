<?php
// admin/rota.php – LIGHT ADMIN THEME (UI only)
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Rota</title>

<!-- USE THE SAME CSS PATH AS OTHER ADMIN PAGES -->
<link rel="stylesheet" href="/kiosk-dev/assets/kiosk.css?v=1"/>

<style>
  .rota-scroll::-webkit-scrollbar{height:10px}
  .rota-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
  .sticky-col{position:sticky;left:0;background:white;z-index:10}
  .sticky-head{position:sticky;top:0;background:white;z-index:20}
  .cell{min-width:130px}
  .namecol{min-width:260px}
</style>
</head>

<body class="bg-slate-50 text-slate-800">

<div class="max-w-[1400px] mx-auto px-6 py-6">

<!-- HEADER -->
<div class="flex justify-between items-start mb-6">
  <div>
    <h1 class="text-2xl font-semibold text-slate-900">Weekly Rota</h1>
    <p class="text-sm text-slate-500 mt-1">
      Create and review staff rotas with automatic hours & overtime alerts
    </p>
  </div>

  <div class="flex items-center gap-2">
    <button class="px-3 py-2 rounded-lg bg-slate-100 text-sm">← Prev</button>
    <div class="px-3 py-2 text-sm font-medium">
      Week Commencing: Sun, 01 Feb 2026
    </div>
    <button class="px-3 py-2 rounded-lg bg-slate-100 text-sm">Next →</button>

    <button class="ml-2 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium">
      Publish rota
    </button>
  </div>
</div>

<!-- ALERT STRIP -->
<div class="mb-4 rounded-xl border border-slate-200 bg-white p-4 flex justify-between items-center">
  <div class="flex gap-4 text-sm">
    <span class="px-2 py-1 rounded bg-red-50 text-red-700 font-medium">
      1 staff in overtime
    </span>
    <span class="px-2 py-1 rounded bg-amber-50 text-amber-700 font-medium">
      2 staff near limit
    </span>
    <span class="px-2 py-1 rounded bg-slate-100 text-slate-600">
      3 staff under hours
    </span>
  </div>

  <button class="text-sm text-indigo-600 font-medium">
    Show only issues
  </button>
</div>

<div class="grid grid-cols-12 gap-4">

<!-- LEFT FILTERS -->
<aside class="col-span-3">
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="font-semibold text-sm mb-3">Filters</div>

    <input
      class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
      placeholder="Search staff"
    />

    <div class="mt-3 grid grid-cols-2 gap-2">
      <select class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
        <option>All Departments</option>
        <option>Care</option>
        <option>Nursing</option>
      </select>

      <select class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
        <option>All Teams</option>
      </select>
    </div>
  </div>
</aside>

<!-- ROTA GRID -->
<section class="col-span-7">
  <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">

    <div class="rota-scroll overflow-x-auto">
      <table class="w-full text-sm border-collapse">
        <thead>
          <tr class="sticky-head border-b border-slate-200">
            <th class="sticky-col namecol px-4 py-3 text-left font-semibold">Staff</th>
            <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
              <th class="cell px-3 py-3 font-semibold"><?= $d ?></th>
            <?php endforeach; ?>
            <th class="px-3 py-3 font-semibold">Total</th>
          </tr>
        </thead>

        <tbody>
          <tr class="bg-slate-100">
            <td colspan="9" class="px-4 py-2 text-xs font-semibold text-slate-600 uppercase">
              Care Department
            </td>
          </tr>

          <tr class="border-b border-slate-200 hover:bg-slate-50">
            <td class="sticky-col px-4 py-3">
              <div class="font-medium">Aisha Khan</div>
              <div class="text-xs text-slate-500">Senior Carer · 40h</div>
            </td>

            <?php foreach(['DAY','DAY','DAY','DAY','OFF','DAY','OFF'] as $s): ?>
              <td class="cell px-3 py-3">
                <?php if($s === 'OFF'): ?>
                  <span class="text-slate-400 text-xs">Off</span>
                <?php else: ?>
                  <div class="rounded-lg border border-slate-200 px-2 py-1">
                    <div class="font-medium"><?= $s ?></div>
                    <div class="text-xs text-slate-500">07:00–19:00</div>
                  </div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>

            <td class="px-3 py-3">
              <div class="font-semibold">44h</div>
              <div class="text-xs text-red-600">+4h OT</div>
            </td>
          </tr>
        </tbody>

        <tfoot>
          <tr class="bg-slate-50 border-t border-slate-200">
            <td class="sticky-col px-4 py-3 font-semibold">
              Department total
            </td>
            <td colspan="7"></td>
            <td class="px-3 py-3 font-semibold">84h</td>
          </tr>
        </tfoot>

      </table>
    </div>
  </div>
</section>

<!-- SHIFT TEMPLATES -->
<aside class="col-span-2">
  <div class="bg-white border border-slate-200 rounded-xl p-4">
    <div class="font-semibold text-sm mb-3">Shift templates</div>

    <?php foreach([
      'DAY 07–19',
      'NIGHT 19–07',
      'EARLY 07–15',
      'LATE 14–22',
      'OFF'
    ] as $t): ?>
      <button class="w-full mb-2 text-left px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50">
        <?= $t ?>
      </button>
    <?php endforeach; ?>
  </div>
</aside>

</div>
</div>

</body>
</html>
