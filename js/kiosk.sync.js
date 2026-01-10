// kiosk.sync.js

let syncing = false;
let lastPingAt = 0;
let lastSyncAt = 0;

async function hasQueueItems() {
  const items = await listQueuedEvents(1);
  return items.length > 0;
}

async function updateNetworkUIOnly() {
  if (!navigator.onLine) {
    setNetUI(false, "Offline");
    return false;
  }
  const now = Date.now();
  if (now - lastPingAt < PING_INTERVAL_MS) {
    setNetUI(true, "Online");
    return true;
  }
  lastPingAt = now;
  const ok = await pingServer();
  setNetUI(ok, ok ? "Online" : "Offline");
  return ok;
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

      try {
        pinToSend = await decryptPin(evt.pin_enc);
      } catch (e) {
        evt.status = "dead";
        evt.last_error = "decrypt_failed";
        evt.last_attempt_at = new Date().toISOString();
        evt.attempts = (evt.attempts || 0) + 1;
        await queueEvent(evt);
        continue;
      }

      const res = await postPunch({
        event_uuid: evt.event_uuid,
        action: evt.action,
        pin: pinToSend,
        device_time: evt.device_time
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
