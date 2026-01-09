// kiosk.main.js

/**
 * Network UI helper
 * NOTE: kiosk.sync.js expects setNetUI() to exist globally.
 * We keep it here to avoid breaking anything.
 */
function setNetUI(online, label) {
  if (!netDot || !netText) return;

  netDot.className =
    "inline-block h-3 w-3 rounded-full " + (online ? "bg-emerald-400" : "bg-amber-400");
  netText.textContent = label || (online ? "Online" : "Offline");
}

/**
 * Keypad wiring helpers
 */
function appendDigit(d) {
  if (isSubmitting) return;
  if (pin.length >= PIN_LENGTH) return;
  pin += String(d);
  updateDots();
}

function clearPin() {
  if (isSubmitting) return;
  pin = "";
  updateDots();
}

function backspacePin() {
  if (isSubmitting) return;
  if (pin.length > 0) {
    pin = pin.slice(0, -1);
    updateDots();
  }
}

/**
 * Wire events
 */
btnIn.addEventListener("click", () => openPin("IN"));
btnOut.addEventListener("click", () => openPin("OUT"));

pinCancel.addEventListener("click", () => {
  if (isSubmitting) return;
  closePin();
});

// Digit keys (buttons with class="key")
keyButtons.forEach((b) => {
  b.addEventListener("click", () => {
    const t = (b.textContent || "").trim();
    if (/^\d$/.test(t)) appendDigit(t);
  });
});

// Clear / Back keys
if (keyClear) keyClear.addEventListener("click", clearPin);
if (keyBack)  keyBack.addEventListener("click", backspacePin);

// Optional: physical keyboard support (handy in testing)
document.addEventListener("keydown", (e) => {
  if (pinOverlay.classList.contains("hidden")) return;

  if (e.key >= "0" && e.key <= "9") {
    appendDigit(e.key);
    e.preventDefault();
  } else if (e.key === "Backspace") {
    backspacePin();
    e.preventDefault();
  } else if (e.key === "Escape") {
    if (!isSubmitting) closePin();
    e.preventDefault();
  } else if (e.key === "Enter") {
    // Submit only if fully entered
    if (!isSubmitting && pin.length === PIN_LENGTH) submitPin();
    e.preventDefault();
  }
});

/**
 * Boot
 */
(function boot() {
  setScreen("home");
  applyPinDots();

  // initial UI state
  setNetUI(false, "Checking…");
  if (typeof updateNetworkUIOnly === "function") updateNetworkUIOnly();

  // refresh network dot on connection changes
  window.addEventListener("online",  () => updateNetworkUIOnly && updateNetworkUIOnly());
  window.addEventListener("offline", () => updateNetworkUIOnly && updateNetworkUIOnly());

  // pairing + background loops
  if (typeof pairIfNeeded === "function") pairIfNeeded();
  if (typeof syncLoop === "function") syncLoop();
  if (typeof statusLoop === "function") statusLoop();

  // clock ticker
  tickClock();
  tickTimer = setInterval(tickClock, 1000);
})();
