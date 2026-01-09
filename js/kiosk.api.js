// kiosk.api.js

function deviceToken() {
  return localStorage.getItem("kiosk_device_token") || "";
}
function pairingVersion() {
  return localStorage.getItem("kiosk_pairing_version") || "0";
}

function setPairedUI(isPaired) {
  btnIn.disabled = !isPaired;
  btnOut.disabled = !isPaired;
  pairHint.classList.toggle('hidden', !!isPaired);
}

function currentUrlHasVersion(v) {
  const u = new URL(window.location.href);
  return (u.searchParams.get('v') || "") === String(v);
}

function reloadWithVersion(v) {
  const u = new URL(window.location.href);
  u.searchParams.set('v', String(v));
  window.location.replace(u.toString());
}

function maybeReloadFromStatus(d) {
  if (!d || typeof d !== 'object') return;

  UI_VERSION = (d.ui_version ?? "").toString();
  UI_RELOAD_ENABLED = !!d.ui_reload_enabled;

  if (Number.isFinite(+d.ui_reload_check_ms)) {
    UI_RELOAD_CHECK_MS = Math.max(10000, parseInt(d.ui_reload_check_ms, 10));
  }

  if (!UI_RELOAD_ENABLED || !UI_VERSION) return;

  const stored = localStorage.getItem('kiosk_ui_version') || "";

  if (!stored) {
    localStorage.setItem('kiosk_ui_version', UI_VERSION);
    return;
  }

  if (stored !== UI_VERSION) {
    localStorage.setItem('kiosk_ui_version', UI_VERSION);
    if (!currentUrlHasVersion(UI_VERSION)) reloadWithVersion(UI_VERSION);
  }

  if (stored === UI_VERSION && !currentUrlHasVersion(UI_VERSION)) {
    reloadWithVersion(UI_VERSION);
  }
}

async function getStatusAndApply() {
  try {
    const r = await fetch(API_STATUS, { cache: "no-store" });
    const d = await r.json().catch(() => ({}));

    if (d && typeof d === "object") {
      // NOTE: you said JS only — so we DO NOT override SHOW_NAME from server.

      if (Number.isFinite(+d.pin_length)) {
        PIN_LENGTH = Math.max(2, Math.min(8, parseInt(d.pin_length, 10)));
      }
      if (Number.isFinite(+d.ui_thank_ms)) {
        THANK_MS = Math.max(500, Math.min(10000, parseInt(d.ui_thank_ms, 10)));
      }

      if (Number.isFinite(+d.ping_interval_ms)) {
        PING_INTERVAL_MS = Math.max(5000, parseInt(d.ping_interval_ms, 10));
      }
      if (Number.isFinite(+d.sync_interval_ms)) {
        SYNC_INTERVAL_MS = Math.max(5000, parseInt(d.sync_interval_ms, 10));
      }
      if (Number.isFinite(+d.sync_cooldown_ms)) {
        SYNC_COOLDOWN_MS = Math.max(0, parseInt(d.sync_cooldown_ms, 10));
      }
      if (Number.isFinite(+d.sync_batch_size)) {
        SYNC_BATCH_SIZE = Math.max(1, Math.min(100, parseInt(d.sync_batch_size, 10)));
      }
      if (Number.isFinite(+d.max_sync_attempts)) {
        MAX_SYNC_ATTEMPTS = Math.max(1, Math.min(50, parseInt(d.max_sync_attempts, 10)));
      }
      if (Number.isFinite(+d.sync_backoff_base_ms)) {
        SYNC_BACKOFF_BASE_MS = Math.max(250, parseInt(d.sync_backoff_base_ms, 10));
      }
      if (Number.isFinite(+d.sync_backoff_cap_ms)) {
        SYNC_BACKOFF_CAP_MS = Math.max(SYNC_BACKOFF_BASE_MS, parseInt(d.sync_backoff_cap_ms, 10));
      }

      applyPinDots();
      maybeReloadFromStatus(d);
    }

    // ✅ IMPORTANT CHANGE:
    // Return the FULL payload (so kiosk.ui.js can read open_shifts + toggles),
    // but also normalize paired + pairing_version so existing code keeps working.
    const out = (d && typeof d === "object") ? { ...d } : {};

    out.paired = !!out.paired;
    out.pairing_version = Number.isFinite(+out.pairing_version)
      ? parseInt(out.pairing_version, 10)
      : 1;

    return out;

  } catch {
    // Return safe defaults (and include new fields so UI won’t crash)
    return {
      paired: false,
      pairing_version: 1,
      ui_show_open_shifts: false,
      ui_open_shifts_count: 6,
      open_shifts: []
    };
  }
}

async function pairIfNeeded() {
  setPairedUI(false);

  const st = await getStatusAndApply();

  if (st.paired && deviceToken()) {
    const localVer = parseInt(pairingVersion() || "0", 10) || 0;
    if (localVer && localVer !== st.pairing_version) {
      alert("This device has been revoked. Please re-pair with manager.");
      setPairedUI(false);
      return;
    }
    setPairedUI(true);
    return;
  }

  if (!st.paired) {
    const code = prompt("Manager pairing code:");
    if (!code) return;

    const res = await fetch(API_PAIR, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Kiosk-Code": KIOSK_CODE },
      body: JSON.stringify({ pairing_code: code })
    });

    const out = await res.json().catch(() => ({}));
    if (out.ok && out.device_token) {
      localStorage.setItem("kiosk_device_token", out.device_token);
      localStorage.setItem("kiosk_pairing_version", String(out.pairing_version || 1));
      setPairedUI(true);
      return;
    }

    alert(out.error || "Pairing failed");
  }
}

async function pingServer() {
  try {
    const r = await fetch(ENDPOINT_PING, { cache: "no-store" });
    return r.ok;
  } catch {
    return false;
  }
}

function makeUuid() {
  if (crypto?.randomUUID) return crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, c => {
    const r = (Math.random() * 16) | 0;
    const v = c === "x" ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

async function postPunch(payload) {
  const r = await fetch(ENDPOINT_PUNCH, {
    method: "POST",
    cache: "no-store",
    headers: {
      "Content-Type": "application/json",
      "X-Kiosk-Code": KIOSK_CODE,
      "X-Device-Token": deviceToken(),
      "X-Pairing-Version": pairingVersion()
    },
    body: JSON.stringify(payload)
  });

  const data = await r.json().catch(() => ({}));
  if (!r.ok) return { ok: false, error: data?.error || "server_error" };
  return data;
}
