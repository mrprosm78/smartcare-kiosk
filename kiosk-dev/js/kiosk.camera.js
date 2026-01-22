// kiosk.camera.js
// Camera overlay + single-tap capture (no preview) for Android WebView / Chrome.

(function () {
  const $overlay = () => document.getElementById('scCamOverlay');
  const $video   = () => document.getElementById('scCamVideo');
  const $btn     = () => document.getElementById('scCamCaptureBtn');

  let stream = null;

  function isCameraEnabled() {
    try {
      return !!(window.__KIOSK_DEVICE__ && window.__KIOSK_DEVICE__.cameraEnabled);
    } catch {
      return false;
    }
  }

  async function openCameraOverlay() {
    const ov = $overlay();
    const v  = $video();
    if (!ov || !v) throw new Error('camera_overlay_missing');

    ov.classList.remove('hidden');

    // Android WebView: prefer user-facing camera
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user' },
      audio: false,
    });

    v.srcObject = stream;
    // Some WebViews require a play() call after assigning srcObject
    await v.play();
  }

  function closeCameraOverlay() {
    const ov = $overlay();
    const v  = $video();

    try {
      if (v) v.srcObject = null;
    } catch {}

    if (ov) ov.classList.add('hidden');

    if (stream) {
      try { stream.getTracks().forEach(t => t.stop()); } catch {}
      stream = null;
    }
  }

  async function captureJpegBlob(opts = {}) {
    const v = $video();
    if (!v) throw new Error('camera_video_missing');

    const quality = Number.isFinite(+opts.quality) ? Math.max(0.5, Math.min(0.95, +opts.quality)) : 0.82;
    const maxW    = Number.isFinite(+opts.maxWidth) ? Math.max(640, Math.min(2400, +opts.maxWidth)) : 1280;

    const vw = v.videoWidth || 1280;
    const vh = v.videoHeight || 720;
    if (!vw || !vh) throw new Error('camera_stream_not_ready');

    // Downscale to reduce upload size & avoid memory spikes on tablets
    const scale = Math.min(1, maxW / vw);
    const w = Math.round(vw * scale);
    const h = Math.round(vh * scale);

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;

    const ctx = canvas.getContext('2d', { alpha: false });
    ctx.drawImage(v, 0, 0, w, h);

    const blob = await new Promise((resolve) => {
      canvas.toBlob((b) => resolve(b), 'image/jpeg', quality);
    });

    if (!blob) throw new Error('camera_blob_failed');
    return blob;
  }

  // Opens camera and resolves with JPEG Blob when Capture is tapped.
  // If camera is disabled, returns null.
  async function openAndWaitCapture() {
    if (!isCameraEnabled()) return null;

    await openCameraOverlay();

    return await new Promise((resolve, reject) => {
      const b = $btn();
      if (!b) {
        closeCameraOverlay();
        reject(new Error('camera_capture_button_missing'));
        return;
      }

      const onClick = async () => {
        b.removeEventListener('click', onClick);
        try {
          const blob = await captureJpegBlob({ quality: 0.82, maxWidth: 1280 });
          closeCameraOverlay();
          resolve(blob);
        } catch (e) {
          closeCameraOverlay();
          reject(e);
        }
      };

      b.addEventListener('click', onClick);
    });
  }

  window.SC_CAM = {
    isCameraEnabled,
    openCameraOverlay,
    closeCameraOverlay,
    captureJpegBlob,
    openAndWaitCapture,
  };
})();
