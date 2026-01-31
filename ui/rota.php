<?php
// admin/rota.php  (UI-only mockup page)
// Drop-in preview UI — no backend logic yet.

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#0f172a"/>
  <title>Rota (Weekly)</title>

  <!-- Match your existing admin pages -->
  <link rel="stylesheet" href="./assets/kiosk.css?v=10"/>
  <style>
    html,body{height:100%}
    .min-h-dvh{min-height:100dvh}

    /* nice grid scroll */
    .rota-scroll::-webkit-scrollbar{height:10px;width:10px}
    .rota-scroll::-webkit-scrollbar-thumb{background:rgba(148,163,184,.35);border-radius:999px}
    .rota-scroll::-webkit-scrollbar-track{background:rgba(15,23,42,.35)}

    /* sticky first column + header row */
    .sticky-col{position:sticky;left:0;z-index:5}
    .sticky-head{position:sticky;top:0;z-index:6}

    /* cell sizes */
    .cell{min-width:132px}
    .namecol{min-width:280px}
  </style>
</head>

<body class="bg-slate-950 text-white min-h-dvh">
  <!-- PAGE WRAP -->
  <div class="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8 py-6">

    <!-- HEADER (match style of other pages) -->
    <div class="flex items-start justify-between gap-4 mb-6">
      <div>
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-2xl bg-slate-900 flex items-center justify-center">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="text-slate-200">
              <path d="M8 7V3M16 7V3M4 11H20M6 5H18C19.1046 5 20 5.89543 20 7V19C20 20.1046 19.1046 21 18 21H6C4.89543 21 4 20.1046 4 19V7C4 5.89543 4.89543 5 6 5Z"
                stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div>
            <h1 class="text-xl sm:text-2xl font-semibold tracking-tight">Rota</h1>
            <p class="text-slate-300 text-sm mt-1">Weekly rota builder (templates + totals + alerts). No break minutes shown.</p>
          </div>
        </div>
      </div>

      <!-- WEEK NAV + ACTIONS -->
      <div class="flex flex-col items-end gap-2">
        <div class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-3 py-2">
          <button class="rounded-xl bg-slate-800 hover:bg-slate-700 px-3 py-2 text-sm">← Prev</button>
          <div class="text-sm text-slate-200 px-2">
            Week Commencing: <span class="font-semibold">Sun, 01 Feb 2026</span>
          </div>
          <button class="rounded-xl bg-slate-800 hover:bg-slate-700 px-3 py-2 text-sm">Next →</button>
        </div>

        <div class="flex items-center gap-2">
          <span class="inline-flex items-center gap-2 rounded-full bg-slate-900 px-3 py-1 text-xs text-slate-200">
            <span class="h-2 w-2 rounded-full bg-amber-400"></span> Draft
          </span>
          <button class="rounded-xl bg-slate-900 hover:bg-slate-800 px-3 py-2 text-sm">Copy last week</button>
          <button class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-3 py-2 text-sm font-semibold">Publish rota</button>
        </div>
      </div>
    </div>

    <!-- ALERT STRIP -->
    <div class="mb-4 rounded-2xl bg-slate-900 border border-slate-800 p-4">
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
          <div class="mt-0.5 h-9 w-9 rounded-xl bg-slate-800 flex items-center justify-center">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="text-amber-300">
              <path d="M12 9V13M12 17H12.01M10.29 3.86L1.82 18A2 2 0 0 0 3.54 21H20.46A2 2 0 0 0 22.18 18L13.71 3.86A2 2 0 0 0 10.29 3.86Z"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div>
            <div class="text-sm font-semibold">Rota checks</div>
            <div class="text-sm text-slate-300 mt-1">
              <span class="text-amber-200 font-semibold">2 staff near limit</span> ·
              <span class="text-red-200 font-semibold">1 staff in overtime</span> ·
              <span class="text-slate-300">3 staff under hours</span>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button class="rounded-xl bg-slate-800 hover:bg-slate-700 px-3 py-2 text-sm">Show only issues</button>
          <button class="rounded-xl bg-slate-800 hover:bg-slate-700 px-3 py-2 text-sm">Clear filter</button>
        </div>
      </div>
    </div>

    <!-- CONTENT GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">

      <!-- LEFT: FILTERS -->
      <aside class="lg:col-span-3">
        <div class="rounded-2xl bg-slate-900 border border-slate-800 p-4">
          <div class="text-sm font-semibold mb-3">Filters</div>

          <label class="block text-xs text-slate-300 mb-1">Search staff</label>
          <input class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-sm text-slate-100"
                 placeholder="Type a name…" />

          <div class="mt-3 grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs text-slate-300 mb-1">Department</label>
              <select class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-sm">
                <option>All</option>
                <option>Care</option>
                <option>Nursing</option>
                <option>Kitchen</option>
                <option>Housekeeping</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-slate-300 mb-1">Team</label>
              <select class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-sm">
                <option>All</option>
                <option>Team A</option>
                <option>Team B</option>
              </select>
            </div>
          </div>

          <div class="mt-3 flex flex-wrap gap-2">
            <button class="rounded-full bg-slate-800 hover:bg-slate-700 px-3 py-1.5 text-xs">Only issues</button>
            <button class="rounded-full bg-slate-800 hover:bg-slate-700 px-3 py-1.5 text-xs">Unassigned days</button>
            <button class="rounded-full bg-slate-800 hover:bg-slate-700 px-3 py-1.5 text-xs">Agency</button>
          </div>

          <div class="mt-4 pt-4 border-t border-slate-800">
            <div class="text-xs text-slate-300 mb-2">Legend</div>
            <div class="space-y-2 text-sm">
              <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                <span class="text-slate-200">Within contract</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                <span class="text-slate-200">Near limit</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-red-400"></span>
                <span class="text-slate-200">Overtime</span>
              </div>
            </div>
          </div>
        </div>
      </aside>

      <!-- CENTER: ROTA GRID -->
      <section class="lg:col-span-7">
        <div class="rounded-2xl bg-slate-900 border border-slate-800 overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 border-b border-slate-800">
            <div class="text-sm font-semibold">Weekly rota</div>
            <div class="text-xs text-slate-300">Click cells to assign a template (UI preview)</div>
          </div>

          <div class="rota-scroll overflow-auto">
            <table class="w-full text-sm border-separate" style="border-spacing:0">
              <!-- Header row -->
              <thead>
                <tr class="sticky-head">
                  <th class="sticky-col namecol bg-slate-900 border-b border-slate-800 px-4 py-3 text-left">
                    Staff
                  </th>
                  <?php
                    $days = [
                      ['Sun', '01 Feb'],
                      ['Mon', '02 Feb'],
                      ['Tue', '03 Feb'],
                      ['Wed', '04 Feb'],
                      ['Thu', '05 Feb'],
                      ['Fri', '06 Feb'],
                      ['Sat', '07 Feb'],
                    ];
                    foreach ($days as $d) {
                      echo '<th class="cell bg-slate-900 border-b border-slate-800 px-3 py-3 text-left">';
                      echo '<div class="text-slate-200 font-semibold">'.$d[0].'</div>';
                      echo '<div class="text-xs text-slate-400">'.$d[1].'</div>';
                      echo '</th>';
                    }
                  ?>
                  <th class="bg-slate-900 border-b border-slate-800 px-3 py-3 text-left min-w-[140px]">
                    Total
                  </th>
                </tr>
              </thead>

              <tbody>
                <!-- Department: Care -->
                <tr>
                  <td class="sticky-col namecol bg-slate-900 border-b border-slate-800 px-4 py-2" colspan="9">
                    <div class="text-xs font-semibold text-slate-300 uppercase tracking-wide">Care</div>
                  </td>
                </tr>

                <!-- Staff row 1 -->
                <tr class="hover:bg-slate-950/40">
                  <td class="sticky-col namecol bg-slate-900 border-b border-slate-800 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <div class="font-semibold">Aisha Khan</div>
                        <div class="text-xs text-slate-400">Senior Carer · Contract 40h</div>
                      </div>
                      <span class="inline-flex items-center gap-2 text-xs text-slate-200">
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span> Near limit
                      </span>
                    </div>
                  </td>

                  <?php
                    $cells = ['DAY', 'DAY', 'DAY', 'DAY', 'OFF', 'DAY', 'OFF'];
                    foreach ($cells as $c) {
                      echo '<td class="cell border-b border-slate-800 px-3 py-3 align-top">';
                      if ($c === 'OFF') {
                        echo '<div class="rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-slate-400 text-xs">Off</div>';
                      } else {
                        echo '<button class="w-full text-left rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2">';
                        echo '<div class="font-semibold">'.$c.'</div>';
                        echo '<div class="text-xs text-slate-400">07:00–19:00</div>';
                        echo '</button>';
                      }
                      echo '</td>';
                    }
                  ?>

                  <td class="border-b border-slate-800 px-3 py-3">
                    <div class="font-semibold">44h</div>
                    <div class="text-xs text-red-200">+4h OT</div>
                  </td>
                </tr>

                <!-- Staff row 2 -->
                <tr class="hover:bg-slate-950/40">
                  <td class="sticky-col namecol bg-slate-900 border-b border-slate-800 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <div class="font-semibold">John Smith</div>
                        <div class="text-xs text-slate-400">Carer · Contract 36h</div>
                      </div>
                      <span class="inline-flex items-center gap-2 text-xs text-slate-200">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span> OK
                      </span>
                    </div>
                  </td>

                  <?php
                    $cells = ['OFF', 'EARLY', 'EARLY', 'EARLY', 'EARLY', 'EARLY', 'OFF'];
                    foreach ($cells as $c) {
                      echo '<td class="cell border-b border-slate-800 px-3 py-3 align-top">';
                      if ($c === 'OFF') {
                        echo '<div class="rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-slate-400 text-xs">Off</div>';
                      } else {
                        echo '<button class="w-full text-left rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2">';
                        echo '<div class="font-semibold">'.$c.'</div>';
                        echo '<div class="text-xs text-slate-400">07:00–15:00</div>';
                        echo '</button>';
                      }
                      echo '</td>';
                    }
                  ?>

                  <td class="border-b border-slate-800 px-3 py-3">
                    <div class="font-semibold">40h</div>
                    <div class="text-xs text-slate-400">+4h extra</div>
                  </td>
                </tr>

                <!-- Department: Nursing -->
                <tr>
                  <td class="sticky-col namecol bg-slate-900 border-b border-slate-800 px-4 py-2" colspan="9">
                    <div class="text-xs font-semibold text-slate-300 uppercase tracking-wide">Nursing</div>
                  </td>
                </tr>

                <!-- Staff row 3 -->
                <tr class="hover:bg-slate-950/40">
                  <td class="sticky-col namecol bg-slate-900 border-b border-slate-800 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <div class="font-semibold">Mark Taylor</div>
                        <div class="text-xs text-slate-400">Nurse · Contract 37.5h</div>
                      </div>
                      <span class="inline-flex items-center gap-2 text-xs text-slate-200">
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-500"></span> Under
                      </span>
                    </div>
                  </td>

                  <?php
                    $cells = ['NIGHT', 'OFF', 'NIGHT', 'OFF', 'OFF', 'OFF', 'OFF'];
                    foreach ($cells as $c) {
                      echo '<td class="cell border-b border-slate-800 px-3 py-3 align-top">';
                      if ($c === 'OFF') {
                        echo '<div class="rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-slate-400 text-xs">Off</div>';
                      } else {
                        echo '<button class="w-full text-left rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2">';
                        echo '<div class="font-semibold">'.$c.'</div>';
                        echo '<div class="text-xs text-slate-400">19:00–07:00</div>';
                        echo '</button>';
                      }
                      echo '</td>';
                    }
                  ?>

                  <td class="border-b border-slate-800 px-3 py-3">
                    <div class="font-semibold">24h</div>
                    <div class="text-xs text-slate-300">13.5h left</div>
                  </td>
                </tr>
              </tbody>

              <!-- Department totals footer -->
              <tfoot>
                <tr>
                  <td class="sticky-col namecol bg-slate-900 border-t border-slate-800 px-4 py-3">
                    <div class="font-semibold">Department totals</div>
                    <div class="text-xs text-slate-400">Week total (all staff)</div>
                  </td>
                  <td class="bg-slate-900 border-t border-slate-800 px-3 py-3" colspan="7">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      <div class="rounded-xl bg-slate-950 border border-slate-800 p-3">
                        <div class="text-xs text-slate-400">Care</div>
                        <div class="text-lg font-semibold">84h</div>
                      </div>
                      <div class="rounded-xl bg-slate-950 border border-slate-800 p-3">
                        <div class="text-xs text-slate-400">Nursing</div>
                        <div class="text-lg font-semibold">24h</div>
                      </div>
                    </div>
                  </td>
                  <td class="bg-slate-900 border-t border-slate-800 px-3 py-3">
                    <div class="text-xs text-slate-400">All depts</div>
                    <div class="text-lg font-semibold">108h</div>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="px-4 py-3 border-t border-slate-800 text-xs text-slate-400">
            Tip: In the real version, clicking a cell opens a small side panel (change shift / remove / swap). Breaks stay hidden but are used for net hours.
          </div>
        </div>
      </section>

      <!-- RIGHT: SHIFT TEMPLATE PALETTE -->
      <aside class="lg:col-span-2">
        <div class="rounded-2xl bg-slate-900 border border-slate-800 p-4">
          <div class="flex items-center justify-between mb-3">
            <div class="text-sm font-semibold">Shift templates</div>
            <button class="text-xs text-slate-300 hover:text-white">Manage</button>
          </div>

          <div class="space-y-2">
            <button class="w-full rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2 text-left">
              <div class="font-semibold">DAY</div>
              <div class="text-xs text-slate-400">07:00–19:00</div>
            </button>

            <button class="w-full rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2 text-left">
              <div class="font-semibold">NIGHT</div>
              <div class="text-xs text-slate-400">19:00–07:00</div>
            </button>

            <button class="w-full rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2 text-left">
              <div class="font-semibold">EARLY</div>
              <div class="text-xs text-slate-400">07:00–15:00</div>
            </button>

            <button class="w-full rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2 text-left">
              <div class="font-semibold">LATE</div>
              <div class="text-xs text-slate-400">14:00–22:00</div>
            </button>

            <button class="w-full rounded-xl bg-slate-950 border border-slate-800 hover:border-slate-600 px-3 py-2 text-left">
              <div class="font-semibold">OFF</div>
              <div class="text-xs text-slate-400">No shift</div>
            </button>
          </div>

          <div class="mt-4 pt-4 border-t border-slate-800">
            <div class="text-xs text-slate-300 mb-2">Bulk actions (UI)</div>
            <div class="grid grid-cols-1 gap-2">
              <button class="rounded-xl bg-slate-800 hover:bg-slate-700 px-3 py-2 text-sm">Apply Mon–Fri</button>
              <button class="rounded-xl bg-slate-800 hover:bg-slate-700 px-3 py-2 text-sm">Apply all week</button>
              <button class="rounded-xl bg-slate-800 hover:bg-slate-700 px-3 py-2 text-sm">Clear selected</button>
            </div>
          </div>
        </div>
      </aside>
    </div>

  </div>
</body>
</html>
