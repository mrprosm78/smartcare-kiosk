// kiosk.dom.js

// UI elements
const homeScreen   = document.getElementById('homeScreen');
const thankScreen  = document.getElementById('thankScreen');
const pinOverlay   = document.getElementById('pinOverlay');

const btnIn        = document.getElementById('btnIn');
const btnOut       = document.getElementById('btnOut');

const pinTitle     = document.getElementById('pinTitle');
const pinHelp      = document.getElementById('pinHelp');
const pinCancel    = document.getElementById('pinCancel');
const pinError     = document.getElementById('pinError');
const pinDots      = Array.from(document.querySelectorAll('.pinDot'));

const thankMsg     = document.getElementById('thankMsg');
const thankName    = document.getElementById('thankName');
const thankAction  = document.getElementById('thankAction');
const thankTime    = document.getElementById('thankTime');

const nowDateEl    = document.getElementById('nowDate');
const nowTimeEl    = document.getElementById('nowTime');

const netDot       = document.getElementById('netDot');
const netText      = document.getElementById('netText');

const pairHint     = document.getElementById('pairHint');

// Keypad labels
document.querySelectorAll('button.key').forEach(btn => {
  btn.className =
    "key rounded-2xl bg-white/5 border border-white/10 px-4 py-4 text-2xl font-bold text-white/90 hover:bg-white/10 active:bg-white/15";
  btn.textContent = btn.dataset.key;
});

function applyPinDots() {
  pinDots.forEach((d, i) => d.style.display = (i < PIN_LENGTH) ? 'inline-block' : 'none');
}
