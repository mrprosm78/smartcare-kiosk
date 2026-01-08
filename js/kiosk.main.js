// kiosk.main.js

// Wire events
btnIn.addEventListener('click', () => openPin('IN'));
btnOut.addEventListener('click', () => openPin('OUT'));

pinCancel.addEventListener('click', () => {
  if (isSubmitting) return;
  closePin();
});

document.getElementById('keyBack').addEventListener('click', () => {
  if (isSubmitting) return;
  if (pin.length > 0) { pin = pin.slice(0, -1); updateDots(); }
});

document.getElementById('keyClear').addEventListener('click', () => {
  if (isSubmitting) return;
  pin = ""; updateDots();
});

document.querySelectorAll('button.key').forEach(btn => {
  btn.addEventListener('click', () => {
    if (isSubmitting) return;
    if (pin.length >= PIN_LENGTH) return;
    pin += btn.dataset.key;
    updateDots();
  });
});

// Boot
setScreen('home');
applyPinDots();
updateNetworkUIOnly();

// Pairing + background loops
pairIfNeeded();
syncLoop();
statusLoop();

tickClock();
tickTimer = setInterval(tickClock, 1000);
