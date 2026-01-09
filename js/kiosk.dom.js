// kiosk.dom.js

// Main screens
const homeScreen  = document.getElementById('homeScreen');
const thankScreen = document.getElementById('thankScreen');

// Header / network
const netDot  = document.getElementById('netDot');
const netText = document.getElementById('netText');

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
