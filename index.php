<?php
declare(strict_types=1);

/**
 * Kiosk UI (index.php)
 * - Adds server-controlled cache-busting via ui_version (settings table)
 * - Removes Tailwind CDN (expects compiled CSS at /assets/kiosk.css)
 *
 * REQUIREMENTS:
 * - settings key: ui_version (defaults to "1")
 * - compiled css file: /assets/kiosk.css
 */

// Try to load DB + helpers (supports common folder layouts)
$loaded = false;

$paths = [
  __DIR__ . '/db.php',
  dirname(__DIR__) . '/db.php',
];

foreach ($paths as $p) {
  if (is_file($p)) {
    require_once $p;
    $loaded = true;
    break;
  }
}

// helpers.php is usually in the same project root as db.php.
// If you already include helpers in db.php, you can ignore this.
$helperPaths = [
  __DIR__ . '/helpers.php',
  dirname(__DIR__) . '/helpers.php',
];
foreach ($helperPaths as $p) {
  if (is_file($p)) {
    require_once $p;
    break;
  }
}

// Determine UI version from kiosk_settings (fallback to 1)
$ui_version = '1';
try {
  if (isset($pdo) && $pdo instanceof PDO && function_exists('setting')) {
    $ui_version = (string)setting($pdo, 'ui_version', '1');
    $ui_version = trim($ui_version) !== '' ? trim($ui_version) : '1';
  }
} catch (Throwable $e) {
  $ui_version = '1';
}

// escape for safe HTML output
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
$v = h($ui_version);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <!-- Optimized for Android tablets -->
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#0f172a" />
  <title>Clock Kiosk</title>

  <!-- ✅ Compiled Tailwind CSS (replace CDN) -->
  <link rel="stylesheet" href="./assets/kiosk.css?v=<?=$v?>">

  <style>
    /* Tablet-optimized styles */
    html, body {
      height: 100%;
      -webkit-text-size-adjust: 100%;
    }

    body {
      -webkit-tap-highlight-color: transparent;
      -webkit-user-select: none;
      user-select: none;
      -webkit-touch-callout: none;
    }

    button, .key { touch-action: manipulation; }
    .min-h-dvh { min-height: 100dvh; }
    * { scroll-behavior: smooth; }

    a, button, input, textarea { -webkit-tap-highlight-color: rgba(0,0,0,0); }

    input, textarea, select { font-size: 16px; }

    button:active, .key:active { transform: scale(0.98); }
  </style>
</head>

<body class="no-select bg-slate-950 text-white min-h-dvh">
  <div class="min-h-dvh flex flex-col">
    <!-- Toasts -->
    <div id="toastWrap" class="pointer-events-none fixed right-3 top-3 z-50 flex flex-col gap-2"></div>

    <!-- State overlay (pairing / unauthorised / revoked) -->
    <div id="stateOverlay" class="hidden fixed inset-0 z-40 bg-slate-950/80 backdrop-blur-sm">
      <div class="min-h-dvh flex items-center justify-center p-6">
        <div class="w-full max-w-md rounded-3xl border border-white/10 bg-slate-900/60 p-6 shadow-2xl">
          <div class="flex items-center justify-between gap-4">
            <h2 id="stateTitle" class="text-lg font-semibold tracking-tight">Kiosk</h2>
            <span id="stateBadge" class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white/80">Setup</span>
          </div>
          <p id="stateMsg" class="mt-3 text-sm leading-6 text-white/80">
            This device needs manager authorisation.
          </p>

          <div class="mt-5 flex items-center gap-3">
            <button id="stateActionBtn" class="flex-1 rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-semibold text-slate-950 active:scale-[0.99]">
              Enter Manager PIN
            </button>
            <button id="stateRetryBtn" class="rounded-2xl bg-white/10 px-4 py-3 text-sm font-semibold text-white/90 active:scale-[0.99]">
              Retry
            </button>
          </div>

          <div id="stateHint" class="mt-4 text-xs text-white/50">
            Tip: keep this tablet on the kiosk stand. Only a manager can authorise new devices.
          </div>
        </div>
      </div>
    </div>

    <!-- Manager PIN modal -->
    <div id="mgrModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm">
      <div class="min-h-dvh flex items-center justify-center p-6">
        <div class="w-full max-w-md rounded-3xl border border-white/10 bg-slate-900/70 p-6 shadow-2xl">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h3 class="text-base font-semibold">Manager authorisation</h3>
              <p id="mgrModalMsg" class="mt-1 text-xs text-white/70">Enter the manager PIN to authorise this kiosk.</p>
            </div>
            <button id="mgrClose" class="rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white/80 hover:bg-white/15">Close</button>
          </div>

          <div class="mt-5 flex items-center justify-center">
            <div id="mgrDotsWrap" class="flex items-center gap-2"></div>
          </div>

          <div id="mgrKeyGrid" class="mt-5 grid grid-cols-3 gap-3">
            <!-- buttons are built by JS (keeps markup small) -->
          </div>

          <div class="mt-4 flex gap-3">
            <button id="mgrClear" class="flex-1 rounded-2xl bg-white/10 px-4 py-3 text-sm font-semibold text-white/90 active:scale-[0.99]">Clear</button>
            <button id="mgrSubmit" class="flex-1 rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-semibold text-slate-950 active:scale-[0.99]">Authorise</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Header -->
    <header class="px-6 pt-7 pb-5">
      <div class="mx-auto max-w-5xl flex items-center justify-between gap-4">
        <div class="flex items-center gap-4">
          <div class="h-14 w-14 rounded-2xl bg-white/10 flex items-center justify-center">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" class="opacity-90">
              <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="2"/>
            </svg>
          </div>
          <div>
            <div id="uiKioskTitle" class="text-lg font-semibold leading-tight">Care Home Digital Time Clock</div>
            <div id="uiKioskSubtitle" class="text-sm text-white/60 leading-tight">Kiosk Mode</div>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <span id="netDot" class="inline-block h-3 w-3 rounded-full bg-amber-400"></span>
          <span id="netText" class="text-sm text-white/70">Checking…</span>
          <span id="queueCount" class="ml-2 hidden rounded-full bg-white/10 px-2 py-0.5 text-xs text-white/70"></span>
        </div>
      </div>
    </header>

    <!-- Offline banner -->
    <div id="offlineBanner" class="hidden mx-auto max-w-5xl px-6">
      <div class="mt-4 rounded-2xl border border-amber-400/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-50">
        <span class="font-semibold">Offline mode</span> — punches will sync automatically.
        <span id="offlineBannerQueue" class="ml-2 text-amber-100/80"></span>
      </div>
    </div>

    <!-- Main (center the main card area vertically on tablets) -->
    <main class="flex-1 px-6 pb-8 flex items-center">
      <div class="mx-auto max-w-5xl w-full">
        <!-- Home Screen -->
        <section id="homeScreen" class="rounded-3xl bg-white/5 border border-white/10 p-8 shadow-xl shadow-black/30">
          <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
            <div class="flex-1">
              <h1 id="uiHomeHeadline" class="text-2xl md:text-3xl font-bold tracking-tight">Tap to Clock In or Clock Out</h1>
              <p id="uiEmployeeNotice" class="text-base md:text-lg text-white/60 mt-2">
                Enter your <span class="font-semibold text-white/80">4-digit PIN</span> on the next screen.
              </p>
              <p id="pairHint" class="hidden mt-3 text-base text-amber-200/90">
                This kiosk is not paired yet. Manager pairing is required.
              </p>
            </div>

            <div class="text-base md:text-lg text-white/60 shrink-0">
              <div id="nowDate" class="text-lg md:text-xl"></div>
              <div class="font-semibold text-white/80 text-3xl md:text-4xl leading-tight" id="nowTime"></div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
            <button
              id="btnIn"
              class="w-full rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-white px-7 py-7 text-left shadow-lg shadow-emerald-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              <div class="flex items-center justify-between gap-4">
                <div>
                  <div class="text-xl md:text-2xl font-bold">Clock In</div>
                  <div class="text-base text-emerald-50/90 mt-2">Start your shift</div>
                </div>
                <div class="h-16 w-16 rounded-2xl bg-white/15 flex items-center justify-center">
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" class="text-white">
                    <path d="M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M9 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M20 4v16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                </div>
              </div>
            </button>

            <button
              id="btnOut"
              class="w-full rounded-2xl bg-sky-500 hover:bg-sky-400 text-white px-7 py-7 text-left shadow-lg shadow-sky-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              <div class="flex items-center justify-between gap-4">
                <div>
                  <div class="text-xl md:text-2xl font-bold">Clock Out</div>
                  <div class="text-base text-sky-50/90 mt-2">Finish your shift</div>
                </div>
                <div class="h-16 w-16 rounded-2xl bg-white/15 flex items-center justify-center">
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" class="text-white">
                    <path d="M9 12h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M15 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M4 4v16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                </div>
              </div>
            </button>
          </div>

          <!-- Currently On Shift (Open Shifts) -->
          <div id="openShiftsWrap" class="hidden mt-8 rounded-2xl bg-white/5 border border-white/10 p-5">
            <div class="flex items-center justify-between gap-3">
              <div>
                <div class="text-base md:text-lg font-semibold text-white/85">Currently on shift</div>
                <div class="text-sm text-white/50 mt-1">Staff who are clocked in right now</div>
              </div>
              <div class="shrink-0 rounded-full bg-emerald-500/15 text-emerald-200 px-3 py-1 text-xs font-semibold">LIVE</div>
            </div>

            <div id="openShiftsEmpty" class="mt-4 text-sm text-white/50">No one is currently clocked in.</div>

            <!-- Scroll area -->
            <div id="openShiftsList" class="mt-4 space-y-3 max-h-56 overflow-y-auto pr-1"></div>
          </div>

          <!-- Reminder -->
          <div class="mt-6 rounded-2xl bg-white/5 border border-white/10 px-5 py-4 text-base text-white/70">
            <div class="flex items-start gap-4">
              <div class="mt-0.5 h-10 w-10 rounded-xl bg-white/10 flex items-center justify-center shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" class="opacity-90">
                  <path d="M12 9v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <path d="M12 17h.01" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                  <path d="M10.29 3.86h3.42c.43 0 .82.23 1.03.6l8.09 14.02c.46.8-.12 1.8-1.03 1.8H3.23c-.91 0-1.49-1-.03-1.8l8.09-14.02c.21-.37.6-.6 1.03-.6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                </svg>
              </div>
              <div>
                <div class="font-semibold text-white/85">Staff reminder</div>
                <div class="mt-2">
                  Please clock in at the start of your shift and clock out at the end. If you need help, ask the manager.
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Thank Screen -->
        <section id="thankScreen" class="hidden rounded-3xl bg-white/5 border border-white/10 p-8 md:p-12 shadow-xl shadow-black/30 text-center">
          <div class="mx-auto max-w-lg">
  
            <div class="mt-2 flex justify-center">
              <div id="thankIconWrap" class="h-20 w-20 rounded-3xl flex items-center justify-center shadow-lg"></div>
            </div>
            <p id="thankName" class="mt-5 text-white text-2xl md:text-3xl font-extrabold"></p>
            <p id="thankMsg" class="mt-2 text-white/70 text-lg md:text-xl"></p>

            <div class="mt-8 rounded-2xl bg-white/5 border border-white/10 p-5 text-left">
              <div class="text-sm text-white/50">Details</div>
              <div class="mt-2 text-base md:text-lg">
                <div class="flex items-center justify-between">
                  <span class="text-white/70">Action</span>
                  <span id="thankAction" class="font-semibold">Clock In</span>
                </div>
                <div class="flex items-center justify-between mt-3">
                  <span class="text-white/70">Time</span>
                  <span id="thankTime" class="font-semibold"></span>
                </div>
              </div>
            </div>

            <div class="mt-8 text-base text-white/60">
              Returning to home screen…
            </div>
          </div>
        </section>
      </div>
    </main>

    <!-- Camera Overlay (hidden by default) -->
    <div id="scCamOverlay" class="fixed inset-0 z-[9999] hidden bg-black">
      <div class="absolute inset-0 flex flex-col">
        <div class="flex-1 relative">
          <video id="scCamVideo" class="absolute inset-0 h-full w-full object-cover" playsinline autoplay muted></video>

          <!-- Full-frame border (visual guide only) -->
          <div class="pointer-events-none absolute inset-0 p-4">
            <div class="h-full w-full rounded-[28px] border-4 border-white/25"></div>
          </div>
        </div>

        <!-- Bottom bar: audit notice (left) + capture button (right) -->
        <div class="px-6 pb-6 pt-4 flex items-end justify-between gap-4">
          <div class="text-xs text-white/70 leading-5 max-w-[60%]">
            <div class="font-semibold text-white/80">Photo for audit purposes only</div>
            <div>Automatically deleted after 60 days.</div>
          </div>

          <button
            id="scCamCaptureBtn"
            type="button"
            class="rounded-3xl bg-white text-slate-900 px-8 py-5 text-lg font-semibold active:scale-[0.99]"
          >
            Capture
          </button>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <footer class="px-6 pb-6">
      <div class="mx-auto max-w-5xl text-center text-sm text-white/40">
        SmartCare • Kiosk
      </div>
    </footer>

    <!-- PIN Overlay -->
    <div id="pinOverlay" class="hidden fixed inset-0 z-50 bg-slate-950/95 backdrop-blur-md">
      <div class="min-h-dvh px-6 py-10 flex items-center justify-center">
        <div class="w-full max-w-2xl rounded-3xl bg-white/5 border border-white/10 p-8 shadow-2xl shadow-black/40">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div id="pinTitle" class="text-2xl md:text-3xl font-extrabold tracking-tight">Clock In</div>
              <div id="pinHelp" class="mt-2 text-white/60 text-base md:text-lg">Enter your PIN to continue.</div>
            </div>

            <button id="pinCancel" class="rounded-xl bg-white/10 hover:bg-white/15 px-4 py-3 text-sm font-semibold">
              Cancel
            </button>
          </div>

          <div class="mt-8 flex justify-center gap-3" id="pinDots">
            <div class="pinDot h-3.5 w-3.5 rounded-full bg-white/15"></div>
            <div class="pinDot h-3.5 w-3.5 rounded-full bg-white/15"></div>
            <div class="pinDot h-3.5 w-3.5 rounded-full bg-white/15"></div>
            <div class="pinDot h-3.5 w-3.5 rounded-full bg-white/15"></div>
          </div>

          <div id="pinError" class="hidden mt-5 rounded-2xl bg-rose-500/10 border border-rose-500/20 px-5 py-4 text-rose-100">
            Invalid PIN. Please try again.
          </div>

          <div class="mt-8 grid grid-cols-3 gap-4">
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">1</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">2</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">3</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">4</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">5</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">6</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">7</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">8</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">9</button>

            <button id="keyClear" class="rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-lg font-semibold">Clear</button>
            <button class="key rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-2xl font-bold">0</button>
            <button id="keyBack" class="rounded-2xl bg-white/10 hover:bg-white/15 px-6 py-6 text-lg font-semibold">Back</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ✅ Versioned JS assets -->
  <script src="./js/kiosk.config.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.dom.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.toast.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.idb.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.camera.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.photo.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.crypto.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.api.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.sync.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.ui.js?v=<?=$v?>"></script>
  <script src="./js/kiosk.main.js?v=<?=$v?>"></script>
</body>
</html>
