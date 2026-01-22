// kiosk.idb.js

const IDB_NAME = "kiosk_db";
const IDB_VERSION = 2;
const STORE_QUEUE = "event_queue";
const STORE_PHOTOS = "photo_queue";

function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(IDB_NAME, IDB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_QUEUE)) {
        const store = db.createObjectStore(STORE_QUEUE, { keyPath: "event_uuid" });
        store.createIndex("status", "status", { unique: false });
        store.createIndex("created_at", "created_at", { unique: false });
      }

    // Photo queue (camera add-on)
    if (!db.objectStoreNames.contains(STORE_PHOTOS)) {
      const store = db.createObjectStore(STORE_PHOTOS, { keyPath: "event_uuid" });
      store.createIndex("status", "status", { unique: false });
      store.createIndex("created_at", "created_at", { unique: false });
    }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function queueEvent(evt) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_QUEUE, "readwrite");
    tx.objectStore(STORE_QUEUE).put(evt);
    tx.oncomplete = () => resolve(true);
    tx.onerror = () => reject(tx.error);
  });
}

async function deleteEvent(event_uuid) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_QUEUE, "readwrite");
    tx.objectStore(STORE_QUEUE).delete(event_uuid);
    tx.oncomplete = () => resolve(true);
    tx.onerror = () => reject(tx.error);
  });
}

function retryBackoffMs(attempts) {
  const base = SYNC_BACKOFF_BASE_MS || 2000;
  const cap  = SYNC_BACKOFF_CAP_MS || 300000;
  const ms = base * Math.pow(2, Math.max(0, attempts || 0));
  return Math.min(ms, cap);
}

function shouldRetry(evt) {
  const attempts = evt.attempts || 0;
  if (attempts >= MAX_SYNC_ATTEMPTS) return false;
  const last = evt.last_attempt_at ? Date.parse(evt.last_attempt_at) : 0;
  if (!last) return true;
  return Date.now() - last >= retryBackoffMs(attempts);
}

async function listQueuedEvents(limit = SYNC_BATCH_SIZE) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_QUEUE, "readonly");
    const store = tx.objectStore(STORE_QUEUE);
    const req = store.getAll();
    req.onsuccess = () => {
      const all = (req.result || [])
        .filter(x => (x.status === "queued" || x.status === "error") && shouldRetry(x))
        .sort((a, b) => (a.created_at || "").localeCompare(b.created_at || ""));
      resolve(all.slice(0, limit));
    };
    req.onerror = () => reject(req.error);
  });
}


// Count active queued events (excluding sent/dead)
async function countQueuedEvents() {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_QUEUE, 'readonly');
    const store = tx.objectStore(STORE_QUEUE);
    const req = store.openCursor();
    let n = 0;
    req.onsuccess = () => {
      const cur = req.result;
      if (!cur) return resolve(n);
      const v = cur.value || {};
      const st = String(v.status || 'queued');
      if (st !== 'sent' && st !== 'dead') n++;
      cur.continue();
    };
    req.onerror = () => resolve(0);
  });
}

/* ------------------------------------------------------------------------
   Photo queue helpers (camera add-on)
   --------------------------------------------------------------------- */

async function queuePhoto(item) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_PHOTOS, "readwrite");
    tx.objectStore(STORE_PHOTOS).put(item);
    tx.oncomplete = () => resolve(true);
    tx.onerror = () => reject(tx.error);
  });
}

async function deletePhoto(event_uuid) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_PHOTOS, "readwrite");
    tx.objectStore(STORE_PHOTOS).delete(event_uuid);
    tx.oncomplete = () => resolve(true);
    tx.onerror = () => reject(tx.error);
  });
}

async function listQueuedPhotos(limit = 10) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_PHOTOS, "readonly");
    const store = tx.objectStore(STORE_PHOTOS);
    const req = store.getAll();
    req.onsuccess = () => {
      const all = (req.result || [])
        .filter(x => (x.status === "queued" || x.status === "error") && shouldRetry(x))
        .sort((a, b) => (a.created_at || "").localeCompare(b.created_at || ""));
      resolve(all.slice(0, limit));
    };
    req.onerror = () => reject(req.error);
  });
}

async function countQueuedPhotos() {
  const db = await openDb();
  return new Promise((resolve) => {
    const tx = db.transaction(STORE_PHOTOS, 'readonly');
    const store = tx.objectStore(STORE_PHOTOS);
    const req = store.openCursor();
    let n = 0;
    req.onsuccess = () => {
      const cur = req.result;
      if (!cur) return resolve(n);
      const v = cur.value || {};
      const st = String(v.status || 'queued');
      if (st !== 'sent' && st !== 'dead') n++;
      cur.continue();
    };
    req.onerror = () => resolve(0);
  });
}
