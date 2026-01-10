// kiosk.ui.js

let currentAction = null; // 'IN' or 'OUT'
let pin = "";
let thankTimeout = null;
let tickTimer = null;
let isSubmitting = false;

// Open shifts (currently clocked-in staff)
let OPEN_SHIFTS = [];              // array of { shift_id, employee_id, clock_in_at, label }
let UI_SHOW_OPEN_SHIFTS = false;   // controlled by status.php (we will wire this next)
let UI_OPEN_SHIFTS_COUNT = 6;      // controlled by status.php (we will wire this next)
let UI_OPEN_SHIFTS_SHOW_TIME = true; // if false, hide time + elapsed on rows

function fmtTimeFromIso(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "";
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function fmtElapsedFromIso(iso) {
  if (!iso) return "";
  const start = new Date(iso);
  if (isNaN(start.getTime())) return "";
  const diffMs = Date.now() - start.getTime();
  if (diffMs < 0) return "";
  const totalMin = Math.floor(diffMs / 60000);
  const h = Math.floor(totalMin / 60);
  const m = totalMin % 60;
  if (h <= 0) return `${m}m`;
  return `${h}h ${m}m`;
}

/**
 * Safe DOM getters (so we don’t break if elements don’t exist yet)
 */
function el(id) {
  return document.getElementById(id);
}


/**
 * Apply server-driven UI text (optional).
 * This lets you move hardcoded kiosk copy into kiosk_settings without changing layouts.
 */
function applyUiTextFromStatus(st) {
  const t = st && st.ui_text ? st.ui_text : null;
  if (!t) return;

  const titleEl = el("uiKioskTitle");
  const subEl   = el("uiKioskSubtitle");
  const noticeEl= el("uiEmployeeNotice");

  if (titleEl && typeof t.kiosk_title === "string" && t.kiosk_title.trim() !== "") {
    titleEl.textContent = t.kiosk_title.trim();
  }
  if (subEl && typeof t.kiosk_subtitle === "string" && t.kiosk_subtitle.trim() !== "") {
    subEl.textContent = t.kiosk_subtitle.trim();
  }
  if (noticeEl && typeof t.employee_notice === "string" && t.employee_notice.trim() !== "") {
    noticeEl.textContent = t.employee_notice.trim();
  }
}


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

/**
 * Render open shifts list if UI elements exist.
 * NOTE: We’ll add these elements in index.html + kiosk.dom.js next.
 *
 * Expected IDs (we’ll create them):
 * - openShiftsWrap  (container, can be hidden when toggle off)
 * - openShiftsList  (list container)
 * - openShiftsEmpty (empty message)
 */
function renderOpenShifts(list) {
  OPEN_SHIFTS = Array.isArray(list) ? list.slice(0) : [];

  const wrap = el('openShiftsWrap');
  const listEl = el('openShiftsList');
  const emptyEl = el('openShiftsEmpty');

  // If HTML not added yet, do nothing (safe)
  if (!wrap || !listEl) return;

  // Toggle visibility based on setting
  wrap.classList.toggle('hidden', !UI_SHOW_OPEN_SHIFTS);

  // If disabled, stop here
  if (!UI_SHOW_OPEN_SHIFTS) return;

  // Clear list
  listEl.innerHTML = '';

  const max = Math.max(1, Math.min(UI_OPEN_SHIFTS_COUNT || 6, 50));
  const items = OPEN_SHIFTS.slice(0, max);

  if (emptyEl) {
    emptyEl.classList.toggle('hidden', items.length > 0);
  }

  // Build rows
  items.forEach(item => {
    const row = document.createElement('div');
    row.className =
      "flex items-center justify-between gap-3 rounded-xl bg-white/5 border border-white/10 px-4 py-3";

    const left = document.createElement('div');
    left.className = "min-w-0";

    const name = document.createElement('div');
    name.className = "font-semibold text-white/90 truncate";
    name.textContent = (item?.label || "Staff").toString();

    const meta = document.createElement('div');
    meta.className = "text-xs text-white/50 mt-0.5";
    if (UI_OPEN_SHIFTS_SHOW_TIME) {
      const t = fmtTimeFromIso(item?.clock_in_at);
      const e = fmtElapsedFromIso(item?.clock_in_at);
      const parts = ["Clocked in"];
      if (t) parts.push(t);
      if (e) parts.push(e);
      meta.textContent = parts.join(" • ");
    } else {
      meta.textContent = "Clocked in";
    }

    left.appendChild(name);
    left.appendChild(meta);

    const badge = document.createElement('div');
    badge.className = "shrink-0 rounded-full bg-emerald-500/15 text-emerald-200 px-3 py-1 text-xs font-semibold";
    badge.textContent = "ON SHIFT";

    row.appendChild(left);
    row.appendChild(badge);

    listEl.appendChild(row);
  });
}

/**
 * Apply open-shifts settings from a status payload (optional).
 * We will wire status.php → kiosk.api.js → here next.
 */
function applyOpenShiftsFromStatus(d) {
  if (!d || typeof d !== "object") return;

  if (typeof d.ui_show_open_shifts !== "undefined") {
    UI_SHOW_OPEN_SHIFTS = !!d.ui_show_open_shifts;
  }
  if (Number.isFinite(+d.ui_open_shifts_count)) {
    UI_OPEN_SHIFTS_COUNT = Math.max(1, Math.min(50, parseInt(d.ui_open_shifts_count, 10)));
  }
  if (typeof d.ui_open_shifts_show_time !== "undefined") {
    UI_OPEN_SHIFTS_SHOW_TIME = !!d.ui_open_shifts_show_time;
  }

  if (Array.isArray(d.open_shifts)) {
    renderOpenShifts(d.open_shifts);
  } else {
    // still re-render with current state (toggle changes)
    renderOpenShifts(OPEN_SHIFTS);
  }
}

function showThank(action, msgOverride, staffLabel) {
  setScreen('thank');
  thankAction.textContent = action === 'IN' ? 'Clock In' : 'Clock Out';

  const now = new Date();
  thankTime.textContent = now.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  thankMsg.textContent = msgOverride || (action === 'IN' ? "Your clock-in has been saved." : "Your clock-out has been saved.");

  // Show name label only if enabled and we have it
  if (SHOW_NAME && staffLabel) {
    thankName.textContent = staffLabel;
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

    // Success
    if (res.ok && (res.status === "processed" || res.status === "duplicate")) {
      try { await deleteEvent(event_uuid); } catch {}
      closePin();

      // Prefer new employee_label (nickname-friendly), fallback to old fields
      const staffLabel = (res.employee_label || res.name || res.employee_name || "").toString();

      // Update open shifts list immediately if server returned it
      if (Array.isArray(res.open_shifts)) {
        // if server returns list, show it (but only if setting enabled)
        renderOpenShifts(res.open_shifts);
      }

      showThank(currentAction, null, staffLabel);
      syncQueueIfNeeded(true);
      return;
    }

    // Known failures: show message and do NOT keep queued event
    if (!res.ok) {
      const msgMap = {
        invalid_pin: "Invalid PIN. Please try again.",
        already_clocked_in: "You're already clocked in.",
        no_open_shift: "No open shift found. Please contact manager.",
        shift_too_long_needs_review: "Shift needs manager review (missing punch).",
        too_soon: "Please wait a moment and try again.",
        too_many_attempts: "Too many attempts. Please wait and try again.",
        device_not_authorized: "This device is not authorised.",
        device_revoked: "This device has been revoked. Please re-pair.",
        kiosk_not_paired: "Kiosk not paired (manager required).",
        kiosk_not_authorized: "Kiosk not authorised."
      };

      if (msgMap[res.error]) {
        try { await deleteEvent(event_uuid); } catch {}
        closePin();

        // Auth-related: show overlay instead of thank screen
        if (res.error === "device_not_authorized" || res.error === "device_revoked" || res.error === "kiosk_not_paired") {
          if (typeof toast === "function") {
            toast("warning", "Authorisation required", msgMap[res.error], { ms: 4500 });
          }
          if (typeof window.setKioskOverlay === "function") {
            const overlayState = (res.error === "device_revoked") ? "revoked" : (res.error === "kiosk_not_paired" ? "not_paired" : "not_authorised");
            window.setKioskOverlay(overlayState, {
              badge: overlayState === "revoked" ? "Revoked" : (overlayState === "not_paired" ? "Not paired" : "Unauthorised"),
              title: overlayState === "revoked" ? "Device revoked" : (overlayState === "not_paired" ? "This kiosk is not set up" : "Device not authorised"),
              message: msgMap[res.error],
              actionText: "Enter Manager PIN",
              action: "pair",
            });
          }
          return;
        }

        showThank(currentAction, msgMap[res.error], "");
        return;
      }
    }

    // Anything else: treat as offline save
    closePin();
    showThank(currentAction, "Saved offline — will sync automatically.", "");
    syncQueueIfNeeded(true);
    return;
  }

  // Offline: keep queued and sync later
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
  // Existing call (we will enhance getStatusAndApply next to pass through open_shifts)
  const st = await getStatusAndApply();

  // If in the next step we update getStatusAndApply() to return extra fields,
  // this will start working automatically.
  if (st && typeof st === 'object') {
    applyOpenShiftsFromStatus(st);
  }

  setTimeout(statusLoop, UI_RELOAD_CHECK_MS || 60000);
}


/* ========================================================================
   Overlay + Manager Modal (authorisation UX)
   ===================================================================== */

let KIOSK_STATE = {
  server_paired: false,
  authorised: false,
  pairing_version: 0,
  ui_version: "1",
};

function setOverlayVisible(on) {
  if (!stateOverlay) return;
  stateOverlay.classList.toggle("hidden", !on);
}

function setKioskOverlay(state, opts = {}) {
  // state: 'ready' | 'not_paired' | 'not_authorised' | 'revoked'
  const s = String(state || "").toLowerCase();

  if (s === "ready") {
    setOverlayVisible(false);
    return;
  }

  setOverlayVisible(true);

  if (stateTitle) stateTitle.textContent = opts.title || "Clock Kiosk";
  if (stateMsg) stateMsg.textContent = opts.message || "This device needs manager authorisation.";
  if (stateBadge) stateBadge.textContent = opts.badge || "Setup";
  if (stateHint) stateHint.textContent = opts.hint || "Tip: keep this tablet on the kiosk stand. Only a manager can authorise new devices.";

  if (stateActionBtn) stateActionBtn.textContent = opts.actionText || "Enter Manager PIN";
  if (stateActionBtn) stateActionBtn.onclick = () => openManagerModal(opts.action || "pair");

  if (stateRetryBtn) stateRetryBtn.onclick = () => {
    if (typeof window.kioskBootstrap === "function") window.kioskBootstrap();
  };
}

function openManagerModal(action = "pair") {
  if (!mgrModal) return;
  mgrModal.dataset.action = action;

  // reset pin
  window.__mgrPin = "";
  if (typeof applyMgrDots === "function") applyMgrDots(PIN_LENGTH || 4);
  updateMgrDots();

  // build keypad once
  buildMgrKeypad();

  // message
  const msg =
    action === "pair"
      ? "Enter the manager PIN to authorise this kiosk."
      : "Enter the manager PIN.";
  if (mgrModalMsg) mgrModalMsg.textContent = msg;

  mgrModal.classList.remove("hidden");
}

function closeManagerModal() {
  if (!mgrModal) return;
  mgrModal.classList.add("hidden");
}

function updateMgrDots() {
  if (!mgrDotsWrap) return;
  const pin = String(window.__mgrPin || "");
  const dots = mgrDotsWrap.querySelectorAll(".mgrDot");
  dots.forEach((d, i) => {
    d.className = "mgrDot h-3.5 w-3.5 rounded-full " + (i < pin.length ? "bg-white" : "bg-white/15");
  });
}

function buildMgrKeypad() {
  if (!mgrKeyGrid) return;
  if (mgrKeyGrid.dataset.built === "1") return;

  const mk = (label, val) => {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "key rounded-2xl bg-white/10 px-4 py-4 text-lg font-semibold text-white active:scale-[0.99]";
    b.textContent = label;
    b.dataset.val = val;
    b.addEventListener("click", () => mgrKeyPress(val));
    return b;
  };

  // 1..9
  for (let i = 1; i <= 9; i++) mgrKeyGrid.appendChild(mk(String(i), String(i)));
  // spacer, 0, back
  mgrKeyGrid.appendChild(mk("•", "spacer"));
  mgrKeyGrid.lastChild.classList.add("opacity-30");
  mgrKeyGrid.lastChild.disabled = true;

  mgrKeyGrid.appendChild(mk("0", "0"));
  mgrKeyGrid.appendChild(mk("⌫", "back"));

  mgrKeyGrid.dataset.built = "1";

  if (mgrClose) mgrClose.onclick = closeManagerModal;
  if (mgrClear) mgrClear.onclick = () => { window.__mgrPin = ""; updateMgrDots(); };
  if (mgrSubmit) mgrSubmit.onclick = submitManagerPin;
}

function mgrKeyPress(val) {
  if (val === "back") {
    window.__mgrPin = String(window.__mgrPin || "").slice(0, -1);
    updateMgrDots();
    return;
  }
  if (val === "spacer") return;

  const current = String(window.__mgrPin || "");
  if (current.length >= (PIN_LENGTH || 4)) return;

  window.__mgrPin = current + String(val);
  updateMgrDots();

  if (String(window.__mgrPin || "").length === (PIN_LENGTH || 4)) {
    // small delay feels nicer
    setTimeout(submitManagerPin, 120);
  }
}

async function submitManagerPin() {
  const code = String(window.__mgrPin || "");
  if (code.length !== (PIN_LENGTH || 4)) {
    toast && toast("warning", "PIN incomplete", "Please enter the full manager PIN.");
    return;
  }

  // disable submit briefly
  if (mgrSubmit) {
    mgrSubmit.disabled = true;
    mgrSubmit.classList.add("opacity-60");
  }

  try {
    if (typeof window.pairWithManagerCode !== "function") {
      toast && toast("error", "Missing function", "pairWithManagerCode() not available.");
      return;
    }
    const ok = await window.pairWithManagerCode(code);

    if (ok) {
      toast && toast("success", "Authorised", "This device is now authorised.");
      closeManagerModal();
      setKioskOverlay("ready");
    } else {
      // pairWithManagerCode will toast specific error
    }
  } finally {
    if (mgrSubmit) {
      mgrSubmit.disabled = false;
      mgrSubmit.classList.remove("opacity-60");
    }
    window.__mgrPin = "";
    updateMgrDots();
  }
}

// Expose state setters for other modules
window.setKioskOverlay = setKioskOverlay;
window.openManagerModal = openManagerModal;
window.closeManagerModal = closeManagerModal;
window.KIOSK_STATE = KIOSK_STATE;
