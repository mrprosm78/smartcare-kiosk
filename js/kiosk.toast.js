// kiosk.toast.js
// Lightweight toast system (Tailwind)
// Usage: toast('success'|'warning'|'error', title, message, {ms})

const TOAST_DEFAULT_MS = 2800;

function toast(type, title, message, opts = {}) {
  const wrap = (typeof toastWrap !== "undefined") ? toastWrap : document.getElementById("toastWrap");
  if (!wrap) return;

  const ms = Number.isFinite(+opts.ms) ? Math.max(800, parseInt(opts.ms, 10)) : TOAST_DEFAULT_MS;

  const base =
    "pointer-events-auto w-[92vw] max-w-sm rounded-2xl border px-4 py-3 shadow-lg backdrop-blur " +
    "transition-all duration-200";

  const tone = (t) => {
    switch ((t || "").toLowerCase()) {
      case "success": return "border-emerald-500/30 bg-emerald-500/10 text-emerald-50";
      case "warning": return "border-amber-500/30 bg-amber-500/10 text-amber-50";
      default: return "border-rose-500/30 bg-rose-500/10 text-rose-50";
    }
  };

  const icon = (t) => {
    switch ((t || "").toLowerCase()) {
      case "success": return "✓";
      case "warning": return "!";
      default: return "×";
    }
  };

  const el = document.createElement("div");
  el.className = base + " " + tone(type);
  el.innerHTML = `
    <div class="flex items-start gap-3">
      <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-bold">
        ${icon(type)}
      </div>
      <div class="min-w-0 flex-1">
        <div class="text-sm font-semibold leading-5">${escapeHtml(title || "")}</div>
        ${message ? `<div class="mt-0.5 text-xs leading-4 opacity-90 break-words">${escapeHtml(message)}</div>` : ""}
      </div>
      <button class="ml-1 rounded-xl px-2 py-1 text-xs opacity-70 hover:opacity-100" aria-label="Close">Close</button>
    </div>
  `.trim();

  const closeBtn = el.querySelector("button");
  const remove = () => {
    el.style.opacity = "0";
    el.style.transform = "translateY(-6px)";
    setTimeout(() => el.remove(), 220);
  };
  closeBtn.addEventListener("click", remove);

  el.style.opacity = "0";
  el.style.transform = "translateY(-6px)";
  wrap.prepend(el);

  requestAnimationFrame(() => {
    el.style.opacity = "1";
    el.style.transform = "translateY(0)";
  });

  window.setTimeout(remove, ms);
}

// small helper (don't rely on PHP)
function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
