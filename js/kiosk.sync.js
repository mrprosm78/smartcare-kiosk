// kiosk.sync.js

let syncing = false;
let lastPingAt = 0;
let lastPingOk = false;
let lastSyncAt = 0;

async function hasQueueItems() {
  const items = await listQueuedEvents(1);
  return items.length > 0;
}

async function updateNetworkUIOnly() {
  const updateQueueIndicators = async (onlineNow) => {
    try {
      const n = (typeof countQueuedEvents === 'function') ? await countQueuedEvents() : 0;
      if (typeof queueCount !== 'undefined' && queueCount) {
        if (n > 0) {
          queueCount.textContent = `Queue: ${n}`;
          queueCount.classList.remove('hidden');
        } else {
          queueCount.textContent = '';
          queueCount.classList.add('hidden');
        }
      }
      if (typeof offlineBanner !== 'undefined' && offlineBanner) {
        offlineBanner.classList.toggle('hidden', !!onlineNow);
      }
      if (typeof offlineBannerQueue !== 'undefined' && offlineBannerQueue) {
        offlineBannerQueue.textContent = n > 0 ? `(Queued punches: ${n})` : '';
      }
    } catch {
      // ignore
    }
  };

  if (!navigator.onLine) {
    lastPingOk = false;
    setNetUI(false, "Offline");
    await updateQueueIndicators(false);
    return false;
  }

  const now = Date.now();

  // If we pinged recently, respect the last result (don't assume online)
  if (now - lastPingAt < PING_INTERVAL_MS) {
    setNetUI(!!lastPingOk, lastPingOk ? "Online" : "Offline");
    await updateQueueIndicators(!!lastPingOk);
    return !!lastPingOk;
  }

  const ok = await pingServer();
  lastPingAt = now;
  lastPingOk = !!ok;

  setNetUI(!!ok, ok ? "Online" : "Offline");
  await updateQueueIndicators(!!ok);
  return !!ok;
}

async function syncQueueIfNeeded(force = false) {
  if (syncing) return;

  const now = Date.now();
  if (!force && now - lastSyncAt < SYNC_COOLDOWN_MS) return;

  const queued = await hasQueueItems();
  if (!queued) return;

  const online = await updateNetworkUIOnly();
  if (!online) return;

  syncing = true;
  lastSyncAt = now;

  try {
    const items = await listQueuedEvents(SYNC_BATCH_SIZE);
    if (!items.length) return;

    // Process in chronological order (older device_time first)
    items.sort((a, b) => String(a.device_time || '').localeCompare(String(b.device_time || '')));

    setNetUI(true, `Syncing (${items.length})…`);

    const NON_RETRYABLE = new Set([
      "invalid_pin","invalid_pin_format","invalid_action","missing_fields",
      "already_clocked_in","no_open_shift","shift_too_long_needs_review",
      "invalid_time_order","too_soon",
      "kiosk_not_authorized","kiosk_not_paired","device_not_authorized","device_revoked",
      "invalid_device_time"
    ]);

    for (const evt of items) {
      let pinToSend = null;

      // Prefer encrypted PIN (default). If unavailable and plaintext PIN is present (server-controlled), use it.
      if (evt.pin_enc) {
        try {
          pinToSend = await decryptPin(evt.pin_enc);
        } catch (e) {
          // Fallback: if server allows plaintext PIN storage, use it rather than killing the event.
          if (typeof evt.pin_plain === "string" && evt.pin_plain.length) {
            pinToSend = evt.pin_plain;
          } else {
            evt.status = "dead";
            evt.last_error = "decrypt_failed";
            evt.last_attempt_at = new Date().toISOString();
            evt.attempts = (evt.attempts || 0) + 1;
            await queueEvent(evt);
            continue;
          }
        }
      } else if (typeof evt.pin_plain === "string" && evt.pin_plain.length) {
        pinToSend = evt.pin_plain;
      } else {
        evt.status = "dead";
        evt.last_error = "missing_pin_payload";
        evt.last_attempt_at = new Date().toISOString();
        evt.attempts = (evt.attempts || 0) + 1;
        await queueEvent(evt);
        continue;
      }


      const res = await postPunch({
        event_uuid: evt.event_uuid,
        action: evt.action,
        pin: pinToSend,
        device_time: evt.device_time,
        was_offline: true,
        source: 'offline_sync'
      });

      if (res.ok && (res.status === "processed" || res.status === "duplicate")) {
        await deleteEvent(evt.event_uuid);
        continue;
      }

      if (!res.ok && NON_RETRYABLE.has(res.error)) {
        await deleteEvent(evt.event_uuid);

        // If kiosk authorisation is invalid, stop hammering the API and show overlay.
        if (res.error === "device_not_authorized" || res.error === "device_revoked" || res.error === "kiosk_not_paired") {
          try {
            localStorage.removeItem("kiosk_device_token");
          } catch {}
          try {
            // keep local pairing_version for revoked detection; but reset if server says not paired
            if (res.error === "kiosk_not_paired") localStorage.removeItem("kiosk_pairing_version");
          } catch {}

          if (typeof toast === "function") {
            const msg = (res.error === "device_revoked")
              ? "This device was revoked. Manager must re-authorise."
              : (res.error === "kiosk_not_paired")
                ? "Kiosk is not paired. Manager setup required."
                : "Device not authorised. Manager approval required.";
            toast("warning", "Authorisation required", msg, { ms: 4500 });
          }

          if (typeof window.setKioskOverlay === "function") {
            const overlayState = (res.error === "device_revoked") ? "revoked" : (res.error === "kiosk_not_paired" ? "not_paired" : "not_authorised");
            window.setKioskOverlay(overlayState, {
              badge: overlayState === "revoked" ? "Revoked" : (overlayState === "not_paired" ? "Not paired" : "Unauthorised"),
              title: overlayState === "revoked" ? "Device revoked" : (overlayState === "not_paired" ? "This kiosk is not set up" : "Device not authorised"),
              message: overlayState === "revoked"
                ? "This device was removed from the system. A manager must re-authorise it."
                : (overlayState === "not_paired"
                  ? "A manager must authorise this device before staff can clock in/out."
                  : "This kiosk needs manager approval before staff can clock in/out."),
              actionText: "Enter Manager PIN",
              action: "pair",
            });
          }

          // Exit early: no more sync until authorisation restored
          return;
        }

        continue;
      }

      evt.status = "error";
      evt.attempts = (evt.attempts || 0) + 1;
      evt.last_error = res.error || "sync_failed";
      evt.last_attempt_at = new Date().toISOString();

      if (evt.attempts >= MAX_SYNC_ATTEMPTS) evt.status = "dead";
      await queueEvent(evt);
    }
  } finally {
    syncing = false;
    await updateNetworkUIOnly();
  }
}

window.addEventListener("online", () => { updateNetworkUIOnly(); syncQueueIfNeeded(true); });
window.addEventListener("offline", () => updateNetworkUIOnly());

async function syncLoop() {
  // If kiosk is not authorised, avoid noisy background calls.
  // statusLoop will keep checking and we will resume automatically after authorisation.
  const hasToken = (typeof deviceToken === "function") ? !!deviceToken() : false;
  if (!hasToken) {
    setTimeout(syncLoop, Math.max(15000, SYNC_INTERVAL_MS));
    return;
  }

  await syncQueueIfNeeded(false);
  setTimeout(syncLoop, SYNC_INTERVAL_MS);
}
