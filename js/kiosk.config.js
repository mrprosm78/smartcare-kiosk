// kiosk.config.js

// Deployment config
const API_BASE = "http://localhost/smartcare-kiosk";

const API_STATUS     = `${API_BASE}/api/kiosk/status.php`;
const API_PAIR       = `${API_BASE}/api/kiosk/pair.php`;
const ENDPOINT_PUNCH = `${API_BASE}/api/kiosk/punch.php`;
const ENDPOINT_PING  = `${API_BASE}/api/kiosk/ping.php`;
const ENDPOINT_PHOTO = `${API_BASE}/api/kiosk/photo_upload.php`;

const KIOSK_CODE = "KIOSK-DEV";

// Runtime defaults (may be overwritten by status.php)
let THANK_MS = 3000;
let SHOW_NAME = true;
let PIN_LENGTH = 4;

let PING_INTERVAL_MS = 60000;
let SYNC_INTERVAL_MS = 30000;
let PHOTO_SYNC_INTERVAL_MS = 45000;
let SYNC_COOLDOWN_MS = 8000;

let SYNC_BATCH_SIZE = 20;
let MAX_SYNC_ATTEMPTS = 10;

let SYNC_BACKOFF_BASE_MS = 2000;
let SYNC_BACKOFF_CAP_MS  = 300000;

// Optional: UI auto reload
let UI_VERSION = "";
let UI_RELOAD_ENABLED = false;
let UI_RELOAD_CHECK_MS = 60000;
