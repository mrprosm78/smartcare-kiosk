// kiosk.dom.js

// Main screens
const homeScreen  = document.getElementById('homeScreen');
const thankScreen = document.getElementById('thankScreen');


// Overlay + modal
const stateOverlay   = document.getElementById('stateOverlay');
const stateTitle     = document.getElementById('stateTitle');
const stateMsg       = document.getElementById('stateMsg');
const stateBadge     = document.getElementById('stateBadge');
const stateActionBtn = document.getElementById('stateActionBtn');
const stateRetryBtn  = document.getElementById('stateRetryBtn');
const stateHint      = document.getElementById('stateHint');

const mgrModal     = document.getElementById('mgrModal');
const mgrModalMsg  = document.getElementById('mgrModalMsg');
const mgrDotsWrap  = document.getElementById('mgrDotsWrap');
const mgrClose     = document.getElementById('mgrClose');
const mgrClear     = document.getElementById('mgrClear');
const mgrSubmit    = document.getElementById('mgrSubmit');
const mgrKeyGrid   = document.getElementById('mgrKeyGrid');

// Toast wrap (from index.php)
const toastWrap = document.getElementById('toastWrap');

// Header / network
const netDot  = document.getElementById('netDot');
const netText = document.getElementById('netText');
const queueCount = document.getElementById('queueCount');

// Offline banner
const offlineBanner = document.getElementById('offlineBanner');
const offlineBannerQueue = document.getElementById('offlineBannerQueue');

// Date/Time
const nowDateEl = document.getElementById('nowDate');
const nowTimeEl = document.getElementById('nowTime');

// Pairing hint + primary buttons
const pairHint = document.getElementById('pairHint');
const btnIn    = document.getElementById('btnIn');
const btnOut   = document.getElementById('btnOut');

// Thank you screen elements
const thankMsg    = document.getElementById('thankMsg');
const thankName   = document.getElementById('thankName');
const thankAction = document.getElementById('thankAction');
const thankTime   = document.getElementById('thankTime');
const thankIconWrap = document.getElementById('thankIconWrap');

// PIN overlay elements
const pinOverlay = document.getElementById('pinOverlay');
const pinTitle   = document.getElementById('pinTitle');
const pinHelp    = document.getElementById('pinHelp');
const pinCancel  = document.getElementById('pinCancel');

const pinError   = document.getElementById('pinError');

// PIN dots (array)
const pinDotsWrap = document.getElementById('pinDots');
const pinDots = pinDotsWrap ? Array.from(pinDotsWrap.querySelectorAll('.pinDot')) : [];

// Keypad buttons
const keyButtons = Array.from(document.querySelectorAll('button.key'));
const keyClear   = document.getElementById('keyClear');
const keyBack    = document.getElementById('keyBack');

// NEW: Open shifts panel (currently clocked-in staff)
const openShiftsWrap  = document.getElementById('openShiftsWrap');
const openShiftsList  = document.getElementById('openShiftsList');
const openShiftsEmpty = document.getElementById('openShiftsEmpty');

/**
 * Rebuild PIN dots UI if PIN_LENGTH changes from status.php
 * Ensures kiosk.ui.js can call applyPinDots() safely.
 */
function applyPinDots() {
  if (!pinDotsWrap) return;

  // clear
  pinDotsWrap.innerHTML = '';

  const count = (typeof PIN_LENGTH === 'number' && PIN_LENGTH > 0) ? PIN_LENGTH : 4;

  for (let i = 0; i < count; i++) {
    const d = document.createElement('div');
    d.className = 'pinDot h-3.5 w-3.5 rounded-full bg-white/15';
    pinDotsWrap.appendChild(d);
  }

  // refresh pinDots array reference
  // (kiosk.ui.js reads pinDots)
  const newDots = Array.from(pinDotsWrap.querySelectorAll('.pinDot'));
  pinDots.length = 0;
  newDots.forEach(x => pinDots.push(x));
}


/**
 * Build manager PIN dots (same length as PIN_LENGTH, defaults to 4).
 * kiosk.ui.js uses applyMgrDots() before showing the modal.
 */
function applyMgrDots(len) {
  const L = Number.isFinite(+len) ? Math.max(4, parseInt(len, 10)) : (typeof PIN_LENGTH !== "undefined" ? PIN_LENGTH : 4);
  if (!mgrDotsWrap) return;
  mgrDotsWrap.innerHTML = "";
  for (let i = 0; i < L; i++) {
    const d = document.createElement("div");
    d.className = "mgrDot h-3.5 w-3.5 rounded-full bg-white/15";
    mgrDotsWrap.appendChild(d);
  }
}
