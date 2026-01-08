// kiosk.ui.js

let currentAction = null; // 'IN' or 'OUT'
let pin = "";
let thankTimeout = null;
let tickTimer = null;
let isSubmitting = false;

function setScreen(screen) {
  if (screen === 'home') {
    homeScreen.classList.remove('hidden');
    thankScreen.classList.add('hidden');
  } else {
    homeScreen.classList.add('hidden');
    thankScreen.classList.remove('hidden');
  }
}

function setKeypadDisabled(disabled) {
  document.querySelectorAll('button.key, #keyClear, #keyBack, #pinCancel').forEach(el => {
    el.disabled = !!disabled;
    el.classList.toggle("opacity-50", !!disabled);
  });
}

function updateDots() {
  pinDots.forEach((d, i) => {
    if (i >= PIN_LENGTH) return;
    d.className = "pinDot h-3.5 w-3.5 rounded-full " + (i < pin.length ? "bg-white" : "bg-white/15");
  });
  if (pin.length === PIN_LENGTH) submitPin();
}

function openPin(action) {
  currentAction = action;
  pin = "";
  isSubmitting = false;
  setKeypadDisabled(false);
  updateDots();
  pinError.classList.add('hidden');

  pinTitle.textContent = (action === 'IN') ? 'Clock In' : 'Clock Out';
  pinHelp.textContent = `Enter your ${PIN_LENGTH}-digit PIN to continue.`;

  pinOverlay.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closePin() {
  pinOverlay.classList.add('hidden');
  document.body.style.overflow = '';
  setKeypadDisabled(false);
  isSubmitting = false;
}

function showThank(action, msgOverride, staffName) {
  setScreen('thank');
  thankAction.textContent = action === 'IN' ? 'Clock In' : 'Clock Out';

  const now = new Date();
  thankTime.textContent = now.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  thankMsg.textContent = msgOverride || (action === 'IN' ? "Your clock-in has been saved." : "Your clock-out has been saved.");

  if (SHOW_NAME && staffName) {
    thankName.textContent = staffName;
    thankName.classList.remove('hidden');
  } else {
    thankName.textContent = "";
    thankName.classList.add('hidden');
  }

  if (thankTimeout) clearTimeout(thankTimeout);
  thankTimeout = setTimeout(() => setScreen('home'), THANK_MS);
}

async function submitPin() {
  if (isSubmitting) return;
  if (pin.length !== PIN_LENGTH) return;

  isSubmitting = true;
  setKeypadDisabled(true);

  const event_uuid = makeUuid();
  const device_time = new Date().toISOString();

  let pin_enc = null;
  try {
    pin_enc = await encryptPin(pin);
  } catch (e) {
    pin_enc = null;
  }

  if (!pin_enc) {
    let online = navigator.onLine;
    if (online) online = await pingServer();

    if (!online) {
      closePin();
      showThank(currentAction, "This device can't save offline punches yet. Please try again when online or ask manager to re-pair.", "");
      isSubmitting = false;
      setKeypadDisabled(false);
      return;
    }
  }

  const evt = {
    event_uuid,
    action: currentAction,
    pin_enc,
    device_time,
    created_at: new Date().toISOString(),
    status: "queued",
    attempts: 0,
    last_error: null,
    last_attempt_at: null
  };

  try { await queueEvent(evt); } catch {}

  let online = navigator.onLine;
  if (online) online = await pingServer();

  if (online) {
    const res = await postPunch({ event_uuid, action: currentAction, pin, device_time });

    if (res.ok && (res.status === "processed" || res.status === "duplicate")) {
      try { await deleteEvent(event_uuid); } catch {}
      closePin();
      const staffName = (res.name || res.employee_name || "").toString();
      showThank(currentAction, null, staffName);
      syncQueueIfNeeded(true);
      return;
    }

    if (!res.ok) {
      const msgMap = {
        invalid_pin: "Invalid PIN. Please try again.",
        already_clocked_in: "You're already clocked in.",
        no_open_shift: "No open shift found. Please contact manager.",
        shift_too_long_needs_review: "Shift needs manager review (missing punch).",
        too_soon: "Please wait a moment and try again.",
        device_not_authorized: "This device is not authorised.",
        device_revoked: "This device has been revoked. Please re-pair.",
        kiosk_not_paired: "Kiosk not paired (manager required).",
        kiosk_not_authorized: "Kiosk not authorised."
      };

      if (msgMap[res.error]) {
        try { await deleteEvent(event_uuid); } catch {}
        closePin();
        showThank(currentAction, msgMap[res.error], "");
        return;
      }
    }

    closePin();
    showThank(currentAction, "Saved offline — will sync automatically.", "");
    syncQueueIfNeeded(true);
    return;
  }

  closePin();
  showThank(currentAction, "Saved offline — will sync automatically.", "");
  syncQueueIfNeeded(true);
}

function tickClock() {
  const now = new Date();
  nowDateEl.textContent = now.toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  nowTimeEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

async function statusLoop() {
  await getStatusAndApply();
  setTimeout(statusLoop, UI_RELOAD_CHECK_MS || 60000);
}
