// kiosk.photo.js
// Offline-first photo queue + background uploader.

let photoSyncing = false;
let lastPhotoSyncAt = 0;

function kioskDeviceHeaders() {
  // Provided by Android WebView shell (if present)
  try {
    const d = window.__KIOSK_DEVICE__ || null;
    if (!d) return {};
    const h = {};
    if (d.id) h["X-Device-Id"] = String(d.id);
    if (d.name) h["X-Device-Name"] = String(d.name);
    if (d.version) h["X-Kiosk-Version"] = String(d.version);
    // cameraEnabled is a client-side toggle; no need to send but harmless if useful later
    h["X-Kiosk-Camera"] = d.cameraEnabled ? "1" : "0";
    return h;
  } catch {
    return {};
  }
}

async function enqueuePhoto({ event_uuid, action, device_time, blob }) {
  if (!event_uuid || !blob) return false;

  const item = {
    event_uuid: String(event_uuid),
    action: String(action || ''),
    device_time: String(device_time || ''),
    blob,
    created_at: new Date().toISOString(),
    status: 'queued',
    attempts: 0,
    last_error: null,
    last_attempt_at: null,
  };

  try {
    await queuePhoto(item);
    // Update header/offline banner queue indicators
    if (typeof updateNetworkUIOnly === 'function') updateNetworkUIOnly();
    return true;
  } catch {
    return false;
  }
}

async function uploadOnePhoto(item) {
  const fd = new FormData();
  fd.append('event_uuid', item.event_uuid);
  fd.append('action', item.action);
  fd.append('device_time', item.device_time);
  // Field name MUST be `photo`
  fd.append('photo', item.blob, `${item.event_uuid}.jpg`);

  const headers = {
    "X-Kiosk-Code": KIOSK_CODE,
    "X-Device-Token": (typeof deviceToken === 'function') ? deviceToken() : '',
    "X-Pairing-Version": (typeof pairingVersion === 'function') ? pairingVersion() : '0',
    ...kioskDeviceHeaders(),
  };

  const r = await fetch(ENDPOINT_PHOTO, {
    method: 'POST',
    cache: 'no-store',
    headers,
    body: fd,
  });

  const data = await r.json().catch(() => ({}));
  if (!r.ok) return { ok: false, error: data?.error || 'server_error' };
  return data;
}

async function syncPhotosIfNeeded(force = false) {
  if (photoSyncing) return;

  const now = Date.now();
  if (!force && now - lastPhotoSyncAt < (SYNC_COOLDOWN_MS || 8000)) return;

  // Only attempt when we have something to upload
  let items = [];
  try { items = await listQueuedPhotos(Math.max(5, Math.min(20, SYNC_BATCH_SIZE || 20))); }
  catch { items = []; }

  if (!items.length) return;

  const online = (typeof updateNetworkUIOnly === 'function') ? await updateNetworkUIOnly() : navigator.onLine;
  if (!online) return;

  // If kiosk not authorised, avoid noisy calls
  const hasToken = (typeof deviceToken === 'function') ? !!deviceToken() : false;
  if (!hasToken) return;

  photoSyncing = true;
  lastPhotoSyncAt = now;

  try {
    // Oldest first
    items.sort((a, b) => String(a.device_time || '').localeCompare(String(b.device_time || '')));

    const NON_RETRYABLE = new Set([
      'missing_fields', 'invalid_action', 'invalid_event_uuid',
      'kiosk_not_authorized', 'kiosk_not_paired', 'device_not_authorized', 'device_revoked',
      'missing_file', 'invalid_file_type', 'file_too_large'
    ]);

    for (const it of items) {
      // Respect shared backoff logic from kiosk.idb.js
      if (typeof shouldRetry === 'function' && !shouldRetry(it)) continue;

      const res = await uploadOnePhoto(it);

      if (res.ok && (res.status === 'stored' || res.status === 'duplicate')) {
        // Server confirmed upload; delete from local queue
        await deletePhoto(it.event_uuid);

        // Optional: ask native shell to delete any on-device cached file (if implemented)
        try {
          if (res.delete_local && window.__KIOSK_DEVICE__ && typeof window.__KIOSK_DEVICE__.deleteLocalPhoto === 'function') {
            await window.__KIOSK_DEVICE__.deleteLocalPhoto(String(it.event_uuid));
          }
        } catch (e) {
          // ignore
        }

        continue;
      }

      if (!res.ok && NON_RETRYABLE.has(res.error)) {
        await deletePhoto(it.event_uuid);
        continue;
      }

      // Retryable error
      it.status = 'error';
      it.attempts = (it.attempts || 0) + 1;
      it.last_error = res.error || 'upload_failed';
      it.last_attempt_at = new Date().toISOString();
      if (it.attempts >= (MAX_SYNC_ATTEMPTS || 10)) it.status = 'dead';
      await queuePhoto(it);
    }
  } finally {
    photoSyncing = false;
    if (typeof updateNetworkUIOnly === 'function') updateNetworkUIOnly();
  }
}

function photoSyncLoop() {
  // Similar to syncLoop() but for photo queue
  const hasToken = (typeof deviceToken === 'function') ? !!deviceToken() : false;
  if (!hasToken) {
    setTimeout(photoSyncLoop, Math.max(15000, PHOTO_SYNC_INTERVAL_MS || 45000));
    return;
  }

  syncPhotosIfNeeded(false);
  setTimeout(photoSyncLoop, PHOTO_SYNC_INTERVAL_MS || 45000);
}

// Keep behaviour consistent with punch sync: when connection comes back, push immediately.
window.addEventListener('online', () => { try { syncPhotosIfNeeded(true); } catch {} });

// Expose minimal API for submitPin()
window.SC_PHOTO = {
  enqueuePhoto,
  syncPhotosIfNeeded,
  photoSyncLoop,
};
