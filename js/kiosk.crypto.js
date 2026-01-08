// kiosk.crypto.js

const CRYPTO_SALT_KEY = "kiosk_crypto_salt_v1";

function bufToB64(buf) {
  const bytes = new Uint8Array(buf);
  let bin = "";
  bytes.forEach(b => (bin += String.fromCharCode(b)));
  return btoa(bin);
}
function b64ToBuf(b64) {
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes.buffer;
}
function textToBuf(s) {
  return new TextEncoder().encode(s);
}
function bufToText(buf) {
  return new TextDecoder().decode(buf);
}

function getOrCreateSaltB64() {
  let salt = localStorage.getItem(CRYPTO_SALT_KEY);
  if (salt) return salt;

  const a = new Uint8Array(16);
  crypto.getRandomValues(a);
  salt = bufToB64(a.buffer);
  localStorage.setItem(CRYPTO_SALT_KEY, salt);
  return salt;
}

async function deriveAesKey() {
  const token = localStorage.getItem("kiosk_device_token") || "";
  if (!token) throw new Error("no_device_token");

  const saltB64 = getOrCreateSaltB64();
  const saltBuf = b64ToBuf(saltB64);

  const material = await crypto.subtle.importKey(
    "raw",
    textToBuf(token + "|" + (KIOSK_CODE || "")),
    { name: "PBKDF2" },
    false,
    ["deriveKey"]
  );

  return crypto.subtle.deriveKey(
    {
      name: "PBKDF2",
      salt: saltBuf,
      iterations: 100000,
      hash: "SHA-256"
    },
    material,
    { name: "AES-GCM", length: 256 },
    false,
    ["encrypt", "decrypt"]
  );
}

async function encryptPin(pin) {
  if (!crypto?.subtle) throw new Error("no_webcrypto");

  const key = await deriveAesKey();
  const iv = new Uint8Array(12);
  crypto.getRandomValues(iv);

  const ct = await crypto.subtle.encrypt(
    { name: "AES-GCM", iv },
    key,
    textToBuf(pin)
  );

  return { v: 1, iv: bufToB64(iv.buffer), ct: bufToB64(ct) };
}

async function decryptPin(pinEnc) {
  if (!pinEnc || typeof pinEnc !== "object") throw new Error("bad_pin_enc");
  if (!crypto?.subtle) throw new Error("no_webcrypto");

  const key = await deriveAesKey();
  const ivBuf = b64ToBuf(pinEnc.iv);
  const ctBuf = b64ToBuf(pinEnc.ct);

  const pt = await crypto.subtle.decrypt(
    { name: "AES-GCM", iv: new Uint8Array(ivBuf) },
    key,
    ctBuf
  );

  return bufToText(pt);
}
