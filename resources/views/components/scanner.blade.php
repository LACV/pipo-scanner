@script
<script>
Alpine.data('docScanner', () => ({
        // State machine: loading | camera | editor | filter | saving | saved | error | no_camera
        phase: 'loading',
        errorMsg: '',

        // Libs
        scanner: null,

        // Camera
        stream: null,
        isFrontCamera: false,
        detectionRaf: null,
        detectionInterval: null,
        lastCorners: null,
        docDetected: false,
        cornerHistory: [],
        detectionFailCount: 0,

        // Editor
        rawCanvas: null,
        editCorners: null,
        dragPoint: null,
        editorScale: 1,
        editorOffsetX: 0,
        editorOffsetY: 0,
        showLoupe: false,
        loupeX: 0,
        loupeY: 0,

        // Filter
        croppedCanvas: null,
        activeFilter: 'color',

        // Rotation (degrees, multiple of 90) and horizontal flip
        rotation: 0,
        flipH: false,

        // Multi-page accumulation
        pages: [],

        // Camera permission
        cameraErrorType: null, // 'denied' | 'notfound' | 'unknown'

        // Save
        savedPath: null,
        savedUrl: null,

        // Existing document (edit mode)
        existingDocumentPath: null,
        existingDocumentUrl: null,
        existingPdfBytes: null, // ArrayBuffer of existing PDF for merge (add-page flow)

        // ─── INIT ────────────────────────────────────────────────────────────
        async init() {
            // PHP injects the existing document (if any) into data-* attributes at
            // render time — more reliable than reading $wire.data inside wire:ignore.
            const existingPath = this.$el.dataset.existingPath || null;
            const existingUrl  = this.$el.dataset.existingUrl  || null;
            if (existingPath) {
                this.existingDocumentPath = existingPath;
                this.existingDocumentUrl  = existingUrl;
                this.phase = 'existing';
                return;
            }
            await this.loadLibs();
        },

        async loadLibs() {
            const loadScript = (id, src) => new Promise(resolve => {
                if (document.getElementById(id)) { resolve(); return; }
                const s = document.createElement('script');
                s.id = id; s.src = src;
                s.onload = resolve;
                s.onerror = resolve;
                document.head.appendChild(s);
            });

            await Promise.all([
                loadScript('opencv-js', 'https://docs.opencv.org/4.x/opencv.js'),
                loadScript('jscanify-js', '{{ asset("vendor/pipo-scanner/jscanify.js") }}'),
            ]);

            // Wait for OpenCV WASM initialisation
            await new Promise(resolve => {
                const check = () => {
                    try { if (window.cv && window.cv.Mat) { resolve(); return; } } catch {}
                    setTimeout(check, 200);
                };
                check();
            });

            try {
                this.scanner = new window.jscanify();
            } catch {
                this.errorMsg = 'Error al inicializar el motor de procesamiento de imagen.';
                this.phase = 'error';
                return;
            }

            // Don't auto-start: wait for explicit user tap (ensures getUserMedia fires
            // from a real gesture, which is required for the Android permission prompt).
            this.phase = 'camera_prompt';
        },

        // ─── CAMERA ──────────────────────────────────────────────────────────

        // Called by the "Activar Cámara" button — user gesture context guaranteed.
        async activateCamera() {
            this.phase = 'camera';
            await this.$nextTick();
            await this.startCamera();
        },

        async startCamera() {
            // Camera API requires HTTPS (or localhost). On plain HTTP over LAN the
            // browser exposes no mediaDevices at all — show a specific message.
            if (!navigator.mediaDevices?.getUserMedia) {
                this.cameraErrorType = 'insecure';
                this.phase = 'no_camera';
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
                });
                const video = this.$refs.video;
                if (!video) { this.cameraErrorType = 'unknown'; this.phase = 'no_camera'; return; }
                video.srcObject = this.stream;
                // Detect front vs back camera to correct mirror on capture
                const facingMode = this.stream.getVideoTracks()[0]?.getSettings()?.facingMode ?? '';
                this.isFrontCamera = facingMode === 'user';
                // autoplay attribute handles playback; play() call is fire-and-forget
                // (it can reject when element is hidden during Livewire morph — don't await)
                video.play().catch(() => {});
                this.cameraErrorType = null;
                this.startDetectionLoop();
            } catch (err) {
                const name = err?.name ?? '';
                if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                    this.cameraErrorType = 'denied';
                } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
                    this.cameraErrorType = 'notfound';
                } else {
                    this.cameraErrorType = 'unknown';
                }
                this.phase = 'no_camera';
            }
        },

        async retryCamera() {
            this.cameraErrorType = null;
            this.phase = 'camera_prompt';
        },

        get isIOS() { return /iPhone|iPad|iPod/i.test(navigator.userAgent); },
        get isAndroid() { return /Android/i.test(navigator.userAgent); },

        stopCamera() {
            this.stream?.getTracks().forEach(t => t.stop());
            this.stream = null;
            if (this.detectionRaf) { cancelAnimationFrame(this.detectionRaf); this.detectionRaf = null; }
            if (this.detectionInterval) { clearInterval(this.detectionInterval); this.detectionInterval = null; }
        },

        startDetectionLoop() {
            // Detection at ~4 fps (CPU-friendly)
            this.detectionInterval = setInterval(() => {
                if (this.phase === 'camera') this.runDetection();
            }, 250);

            // Draw overlay at display refresh rate
            const drawLoop = () => {
                if (this.phase !== 'camera') return;
                this.drawOverlay();
                this.detectionRaf = requestAnimationFrame(drawLoop);
            };
            this.detectionRaf = requestAnimationFrame(drawLoop);
        },

        runDetection() {
            const video = this.$refs.video;
            if (!video || video.videoWidth === 0 || !this.scanner) return;

            // Downscale for performance
            const scale = 0.4;
            const w = Math.floor(video.videoWidth * scale);
            const h = Math.floor(video.videoHeight * scale);
            const tmp = document.createElement('canvas');
            tmp.width = w; tmp.height = h;
            tmp.getContext('2d').drawImage(video, 0, 0, w, h);

            try {
                const img = cv.imread(tmp);
                const contour = this.scanner.findPaperContour(img);
                if (contour) {
                    const raw = this.scanner.getCornerPoints(contour);
                    const scaled = Object.fromEntries(
                        Object.entries(raw).map(([k, v]) => [k, { x: v.x / scale, y: v.y / scale }])
                    );
                    // Temporal smoothing: keep last 4 frames and average
                    this.cornerHistory.push(scaled);
                    if (this.cornerHistory.length > 4) this.cornerHistory.shift();
                    this.lastCorners = this._averageCorners(this.cornerHistory);
                    this.detectionFailCount = 0;
                    this.docDetected = true;
                    contour.delete();
                } else {
                    // Debounce: require 3 consecutive misses before hiding the overlay
                    this.detectionFailCount++;
                    if (this.detectionFailCount >= 3) {
                        this.docDetected = false;
                        this.cornerHistory = [];
                    }
                }
                img.delete();
            } catch {
                this.detectionFailCount++;
                if (this.detectionFailCount >= 3) {
                    this.docDetected = false;
                    this.cornerHistory = [];
                }
            }
        },

        _averageCorners(history) {
            const keys = ['topLeftCorner', 'topRightCorner', 'bottomRightCorner', 'bottomLeftCorner'];
            const result = {};
            for (const key of keys) {
                result[key] = {
                    x: history.reduce((s, h) => s + h[key].x, 0) / history.length,
                    y: history.reduce((s, h) => s + h[key].y, 0) / history.length,
                };
            }
            return result;
        },

        drawOverlay() {
            const video = this.$refs.video;
            const canvas = this.$refs.overlay;
            if (!video || !canvas || video.videoWidth === 0) return;

            // Match canvas to displayed video element size
            canvas.width  = video.clientWidth;
            canvas.height = video.clientHeight;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!this.lastCorners || !this.docDetected) return;

            // Transform: video resolution → displayed pixels (object-fit: contain)
            const vw = video.videoWidth,  vh = video.videoHeight;
            const dw = video.clientWidth, dh = video.clientHeight;
            const sc = Math.min(dw / vw, dh / vh);
            const ox = (dw - vw * sc) / 2;
            const oy = (dh - vh * sc) / 2;
            const tx = x => x * sc + ox;
            const ty = y => y * sc + oy;

            const c = this.lastCorners;
            const pts = [c.topLeftCorner, c.topRightCorner, c.bottomRightCorner, c.bottomLeftCorner];

            // Filled polygon
            ctx.fillStyle = 'rgba(34,197,94,0.12)';
            ctx.beginPath();
            pts.forEach((p, i) => i ? ctx.lineTo(tx(p.x), ty(p.y)) : ctx.moveTo(tx(p.x), ty(p.y)));
            ctx.closePath();
            ctx.fill();

            // Border
            ctx.strokeStyle = '#22c55e';
            ctx.lineWidth = 2.5;
            ctx.stroke();

            // Corner dots
            pts.forEach(p => {
                ctx.fillStyle = 'white';
                ctx.beginPath();
                ctx.arc(tx(p.x), ty(p.y), 8, 0, Math.PI * 2);
                ctx.fill();
                ctx.strokeStyle = '#22c55e';
                ctx.lineWidth = 2;
                ctx.stroke();
            });
        },

        async capture() {
            const video = this.$refs.video;
            if (!video || video.videoHeight === 0) return;

            this.rawCanvas = document.createElement('canvas');
            this.rawCanvas.width  = video.videoWidth;
            this.rawCanvas.height = video.videoHeight;
            this.rawCanvas.getContext('2d').drawImage(video, 0, 0);

            this.stopCamera();

            if (this.lastCorners && this.docDetected) {
                this.editCorners = JSON.parse(JSON.stringify(this.lastCorners));
            } else {
                const w = video.videoWidth, h = video.videoHeight;
                const p = Math.min(w, h) * 0.08;
                this.editCorners = {
                    topLeftCorner:     { x: p,     y: p },
                    topRightCorner:    { x: w - p, y: p },
                    bottomRightCorner: { x: w - p, y: h - p },
                    bottomLeftCorner:  { x: p,     y: h - p },
                };
            }

            await this.goToEditor();
        },

        // ─── EDITOR ──────────────────────────────────────────────────────────
        renderEditor() {
            const canvas = this.$refs.editorCanvas;
            const ctr    = this.$refs.editorCtr;
            if (!canvas || !this.rawCanvas || !ctr) return;

            canvas.width  = ctr.clientWidth;
            canvas.height = ctr.clientHeight;

            const sc = Math.min(canvas.width / this.rawCanvas.width, canvas.height / this.rawCanvas.height);
            const w  = this.rawCanvas.width  * sc;
            const h  = this.rawCanvas.height * sc;

            this.editorScale   = sc;
            this.editorOffsetX = (canvas.width  - w) / 2;
            this.editorOffsetY = (canvas.height - h) / 2;

            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(this.rawCanvas, this.editorOffsetX, this.editorOffsetY, w, h);
            this.drawEditorHandles(ctx);
        },

        drawEditorHandles(ctx) {
            if (!this.editCorners) return;
            const { editorScale: sc, editorOffsetX: ox, editorOffsetY: oy } = this;
            const toC = p => ({ x: p.x * sc + ox, y: p.y * sc + oy });

            const c = this.editCorners;
            const pts = [toC(c.topLeftCorner), toC(c.topRightCorner), toC(c.bottomRightCorner), toC(c.bottomLeftCorner)];
            const R = Math.max(18, (this.$refs.editorCanvas?.width ?? 400) / 30);

            // Polygon fill
            ctx.fillStyle = 'rgba(99,102,241,0.18)';
            ctx.beginPath();
            pts.forEach((p, i) => i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y));
            ctx.closePath();
            ctx.fill();

            // Border
            ctx.strokeStyle = '#818cf8';
            ctx.lineWidth = 2;
            ctx.stroke();

            // Handle circles
            pts.forEach(p => {
                // Outer filled circle
                ctx.beginPath();
                ctx.arc(p.x, p.y, R, 0, Math.PI * 2);
                ctx.fillStyle = '#4f46e5';
                ctx.fill();
                ctx.strokeStyle = 'rgba(255,255,255,0.9)';
                ctx.lineWidth = 2.5;
                ctx.stroke();

                // Inner cross
                ctx.strokeStyle = 'white';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(p.x - R * 0.38, p.y); ctx.lineTo(p.x + R * 0.38, p.y);
                ctx.moveTo(p.x, p.y - R * 0.38); ctx.lineTo(p.x, p.y + R * 0.38);
                ctx.stroke();
            });
        },

        onPointerDown(e) {
            e.preventDefault();
            const canvas = this.$refs.editorCanvas;
            const rect   = canvas.getBoundingClientRect();
            const { editorScale: sc, editorOffsetX: ox, editorOffsetY: oy } = this;
            const px = (e.clientX ?? e.touches?.[0]?.clientX ?? 0) - rect.left;
            const py = (e.clientY ?? e.touches?.[0]?.clientY ?? 0) - rect.top;
            const hitR = Math.max(18, canvas.width / 30) * 2.2;

            for (const [key, p] of Object.entries(this.editCorners)) {
                const cpx = p.x * sc + ox, cpy = p.y * sc + oy;
                if (Math.hypot(cpx - px, cpy - py) < hitR) {
                    this.dragPoint = key;
                    canvas.setPointerCapture?.(e.pointerId);
                    return;
                }
            }
        },

        onPointerMove(e) {
            if (!this.dragPoint) return;
            e.preventDefault();
            const canvas = this.$refs.editorCanvas;
            const rect   = canvas.getBoundingClientRect();
            const { editorScale: sc, editorOffsetX: ox, editorOffsetY: oy } = this;
            const px = (e.clientX ?? e.touches?.[0]?.clientX ?? 0) - rect.left;
            const py = (e.clientY ?? e.touches?.[0]?.clientY ?? 0) - rect.top;

            this.editCorners[this.dragPoint].x = Math.max(0, Math.min(this.rawCanvas.width,  (px - ox) / sc));
            this.editCorners[this.dragPoint].y = Math.max(0, Math.min(this.rawCanvas.height, (py - oy) / sc));

            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(this.rawCanvas, ox, oy, this.rawCanvas.width * sc, this.rawCanvas.height * sc);
            this.drawEditorHandles(ctx);

            // Loupe: show zoomed view of the corner being dragged
            this._drawLoupe(canvas, px, py);
        },

        onPointerUp() {
            this.dragPoint = null;
            const loupe = this.$refs.loupeCanvas;
            if (loupe) loupe.style.display = 'none';
        },

        _drawLoupe(editorCanvas, cpx, cpy) {
            const loupe = this.$refs.loupeCanvas;
            if (!loupe || !this.rawCanvas) return;

            const R    = 64;
            const zoom = 2.5;
            const size = R * 2;

            loupe.width  = size;
            loupe.height = size;

            // Position above finger; flip below if near the top edge
            const above = cpy > size + 24;
            loupe.style.left    = Math.round(cpx - R) + 'px';
            loupe.style.top     = (above ? Math.round(cpy - size - 24) : Math.round(cpy + 24)) + 'px';
            loupe.style.display = 'block';

            const ctx = loupe.getContext('2d');
            ctx.clearRect(0, 0, size, size);

            // Build a clean view of just the raw image (no handles drawn)
            const clean = document.createElement('canvas');
            clean.width  = editorCanvas.width;
            clean.height = editorCanvas.height;
            const cc = clean.getContext('2d');
            cc.fillStyle = '#0f172a';
            cc.fillRect(0, 0, clean.width, clean.height);
            cc.drawImage(
                this.rawCanvas,
                this.editorOffsetX, this.editorOffsetY,
                this.rawCanvas.width  * this.editorScale,
                this.rawCanvas.height * this.editorScale
            );

            // Clip to circle and zoom into clean image
            ctx.save();
            ctx.beginPath();
            ctx.arc(R, R, R - 2, 0, Math.PI * 2);
            ctx.clip();
            const sw = size / zoom;
            const sh = size / zoom;
            ctx.drawImage(clean,
                Math.max(0, cpx - sw / 2), Math.max(0, cpy - sh / 2), sw, sh,
                0, 0, size, size
            );
            ctx.restore();

            // Border ring
            ctx.beginPath();
            ctx.arc(R, R, R - 2, 0, Math.PI * 2);
            ctx.strokeStyle = '#818cf8';
            ctx.lineWidth = 3;
            ctx.stroke();

            // Thin crosshair lines
            ctx.strokeStyle = 'rgba(255,255,255,0.6)';
            ctx.lineWidth   = 1;
            ctx.beginPath();
            ctx.moveTo(R - 20, R); ctx.lineTo(R + 20, R);
            ctx.moveTo(R, R - 20); ctx.lineTo(R, R + 20);
            ctx.stroke();

            // Red centre dot — exact corner position
            ctx.beginPath();
            ctx.arc(R, R, 4, 0, Math.PI * 2);
            ctx.fillStyle = '#f87171';
            ctx.fill();
        },

        async goToEditor() {
            this.phase = 'editor';
            // Signal to the form that a scan is in progress — block form submission.
            $wire.call('setScannerDocumentPath', '__scanning__');
            await this.$nextTick();

            // Wait for the browser to finish painting and compute layout.
            // x-show removes display:none, but clientWidth can still be 0 until
            // the next paint cycle. We poll until we get real dimensions (max ~300ms).
            const ctr = this.$refs.editorCtr;
            for (let i = 0; i < 10; i++) {
                await new Promise(r => requestAnimationFrame(r));
                if (ctr && ctr.clientWidth > 0) break;
            }

            this.renderEditor();
        },

        async confirmCrop() {
            const c = this.editCorners;
            const w = Math.max(
                Math.hypot(c.topRightCorner.x - c.topLeftCorner.x,    c.topRightCorner.y - c.topLeftCorner.y),
                Math.hypot(c.bottomRightCorner.x - c.bottomLeftCorner.x, c.bottomRightCorner.y - c.bottomLeftCorner.y)
            );
            const h = Math.max(
                Math.hypot(c.bottomLeftCorner.x - c.topLeftCorner.x,   c.bottomLeftCorner.y - c.topLeftCorner.y),
                Math.hypot(c.bottomRightCorner.x - c.topRightCorner.x, c.bottomRightCorner.y - c.topRightCorner.y)
            );

            try {
                this.croppedCanvas = this.scanner.extractPaper(this.rawCanvas, Math.round(w), Math.round(h), this.editCorners);
            } catch {
                this.croppedCanvas = this.rawCanvas;
            }

            this.activeFilter = 'color';
            this.rotation = 0;
            this.flipH = false;
            this.phase = 'filter';
            await this.$nextTick();
            this.applyFilter('color');

            // Sentinel AFTER el canvas está dibujado — bloquea guardar hasta confirmar.
            $wire.call('setScannerDocumentPath', '__scanning__');
        },

        // ─── FILTER ──────────────────────────────────────────────────────────
        applyFilter(f) {
            this.activeFilter = f;
            if (!this.croppedCanvas) return;

            // Step 1: Apply color processing to a temp canvas
            const tmp = document.createElement('canvas');
            tmp.width  = this.croppedCanvas.width;
            tmp.height = this.croppedCanvas.height;

            if (f === 'color') {
                tmp.getContext('2d').drawImage(this.croppedCanvas, 0, 0);
            } else {
                try {
                    const src  = cv.imread(this.croppedCanvas);
                    const gray = new cv.Mat();
                    cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY);

                    let dst;
                    if (f === 'gray') {
                        dst = new cv.Mat();
                        cv.cvtColor(gray, dst, cv.COLOR_GRAY2RGBA);
                    } else if (f === 'document') {
                        const bin = new cv.Mat();
                        cv.adaptiveThreshold(gray, bin, 255, cv.ADAPTIVE_THRESH_GAUSSIAN_C, cv.THRESH_BINARY, 21, 8);
                        dst = new cv.Mat();
                        cv.cvtColor(bin, dst, cv.COLOR_GRAY2RGBA);
                        bin.delete();
                    } else if (f === 'enhance') {
                        const boosted = new cv.Mat();
                        cv.convertScaleAbs(gray, boosted, 1.4, 12);
                        dst = new cv.Mat();
                        cv.cvtColor(boosted, dst, cv.COLOR_GRAY2RGBA);
                        boosted.delete();
                    }

                    cv.imshow(tmp, dst);
                    src.delete(); gray.delete(); dst.delete();
                } catch {
                    tmp.getContext('2d').drawImage(this.croppedCanvas, 0, 0);
                }
            }

            // Step 2: Apply rotation
            const rotated = this.rotation ? this.rotateCanvas(tmp, this.rotation) : tmp;

            // Step 3: Apply horizontal flip if requested
            const final = this.flipH ? this.flipCanvas(rotated) : rotated;

            // Step 4: Draw to filterCanvas
            const canvas = this.$refs.filterCanvas;
            if (!canvas) return;
            canvas.width  = final.width;
            canvas.height = final.height;
            canvas.getContext('2d').drawImage(final, 0, 0);
        },

        // Flip a canvas horizontally and return new canvas
        flipCanvas(src) {
            const c = document.createElement('canvas');
            c.width = src.width; c.height = src.height;
            const ctx = c.getContext('2d');
            ctx.translate(src.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(src, 0, 0);
            return c;
        },

        // Toggle horizontal flip and re-render
        toggleFlip() {
            this.flipH = !this.flipH;
            this.applyFilter(this.activeFilter);
        },

        // Rotate a canvas by degrees (multiples of 90) and return new canvas
        rotateCanvas(src, deg) {
            const norm = ((deg % 360) + 360) % 360;
            const swap = norm === 90 || norm === 270;
            const cw = swap ? src.height : src.width;
            const ch = swap ? src.width  : src.height;
            const c = document.createElement('canvas');
            c.width = cw; c.height = ch;
            const ctx = c.getContext('2d');
            ctx.translate(cw / 2, ch / 2);
            ctx.rotate(norm * Math.PI / 180);
            ctx.drawImage(src, -src.width / 2, -src.height / 2);
            return c;
        },

        // Rotate current page by delta degrees and re-render
        rotate(delta) {
            this.rotation = ((this.rotation + delta) % 360 + 360) % 360;
            this.applyFilter(this.activeFilter);
        },

        // ─── SAVE ────────────────────────────────────────────────────────────
        async saveDocument() {
            const canvas = this.$refs.filterCanvas;
            if (!canvas) return;

            this.phase = 'saving';

            // Collect queued pages + current page
            const allPages = [...this.pages, canvas.toDataURL('image/jpeg', 0.92)];

            try {
                let pdfDataUri;

                if (this.existingPdfBytes) {
                    // ── MERGE: append scanned pages to the existing PDF via pdf-lib ──
                    await this.loadPdfLib();
                    const { PDFDocument } = window.PDFLib;

                    const mergedPdf = await PDFDocument.load(this.existingPdfBytes);

                    for (const pageDataUrl of allPages) {
                        // pageDataUrl is a JPEG data-URL produced by canvas.toDataURL('image/jpeg')
                        const base64 = pageDataUrl.split(',')[1];
                        const imgBytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
                        const jpgImage = await mergedPdf.embedJpg(imgBytes);
                        const page = mergedPdf.addPage([jpgImage.width, jpgImage.height]);
                        page.drawImage(jpgImage, { x: 0, y: 0, width: jpgImage.width, height: jpgImage.height });
                    }

                    const pdfBytes = await mergedPdf.save();
                    // Convert Uint8Array → base64 in chunks to avoid stack overflow on large files
                    let binary = '';
                    const chunk = 8192;
                    for (let i = 0; i < pdfBytes.length; i += chunk) {
                        binary += String.fromCharCode(...pdfBytes.subarray(i, i + chunk));
                    }
                    pdfDataUri = 'data:application/pdf;base64,' + btoa(binary);
                    this.existingPdfBytes = null;

                } else {
                    // ── NEW PDF: build from scratch with jsPDF ──
                    await this.loadJsPdf();
                    const { jsPDF } = window.jspdf;

                    const imgs = await Promise.all(allPages.map(d => this.loadImage(d)));
                    const pw = imgs[0].naturalWidth;
                    const ph = imgs[0].naturalHeight;
                    const orientation = pw >= ph ? 'landscape' : 'portrait';

                    const doc = new jsPDF({ orientation, unit: 'px', format: [pw, ph], compress: true });

                    imgs.forEach((img, i) => {
                        if (i > 0) doc.addPage([pw, ph], orientation);
                        doc.addImage(allPages[i], 'JPEG', 0, 0, pw, ph, undefined, 'FAST');
                    });

                    pdfDataUri = doc.output('datauristring');
                }

                const body = { pdf: pdfDataUri };

                const res = await fetch('{{ route("pipo-scanner.upload") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });

                if (!res.ok) {
                    const errBody = await res.json().catch(() => ({}));
                    const msg = errBody?.message || errBody?.error || JSON.stringify(errBody);
                    console.error('[Scanner] upload error', res.status, errBody);
                    throw new Error('Error del servidor (' + res.status + '): ' + msg);
                }
                const data = await res.json();

                this.savedPath = data.path;
                this.savedUrl  = data.url;
                this.pages     = [];
                this.phase     = 'saved';

                $wire.call('setScannerDocumentPath', data.path)
                    .catch(e => console.error('[Scanner] setScannerDocumentPath falló', e));
                this.$dispatch('scanner-saved', { path: data.path, url: data.url });

            } catch (err) {
                this.errorMsg = err.message;
                this.phase = 'error';
            }
        },

        // Load jsPDF from CDN (idempotent)
        loadJsPdf() {
            return new Promise(resolve => {
                if (window.jspdf) { resolve(); return; }
                const s = document.createElement('script');
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
                s.onload = resolve; s.onerror = resolve;
                document.head.appendChild(s);
            });
        },

        // Load pdf-lib from CDN (idempotent) — used for merging existing PDFs
        loadPdfLib() {
            return new Promise(resolve => {
                if (window.PDFLib) { resolve(); return; }
                const s = document.createElement('script');
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js';
                s.onload = resolve; s.onerror = resolve;
                document.head.appendChild(s);
            });
        },

        // Resolve an Image element from a data URL
        loadImage(dataUrl) {
            return new Promise(resolve => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.src = dataUrl;
            });
        },

        // ─── UTILS ───────────────────────────────────────────────────────────

        // Add current page to queue and go back to camera for next page
        async addPage() {
            const canvas = this.$refs.filterCanvas;
            if (!canvas) return;
            this.pages.push(canvas.toDataURL('image/jpeg', 0.92));
            console.log('[Scanner] addPage → pages.length ahora:', this.pages.length);
            this.stopCamera();
            Object.assign(this, {
                rawCanvas: null, editCorners: null, croppedCanvas: null,
                lastCorners: null, docDetected: false, rotation: 0, flipH: false,
                savedPath: null, savedUrl: null, errorMsg: '',
            });
            this.phase = 'camera';
            await this.$nextTick();
            await this.startCamera();
        },

        // Full reset — discards all queued pages and clears the form field sentinel
        async retake() {
            this.stopCamera();
            Object.assign(this, {
                rawCanvas: null, editCorners: null, croppedCanvas: null,
                lastCorners: null, docDetected: false, rotation: 0, flipH: false,
                pages: [], savedPath: null, savedUrl: null, errorMsg: '',
                existingPdfBytes: null,
            });
            $wire.call('setScannerDocumentPath', null)
                .catch(e => console.error('[Scanner] setScannerDocumentPath falló', e));
            this.phase = 'camera';
            await this.$nextTick();
            await this.startCamera();
        },

        // ─── EXISTING DOCUMENT ACTIONS ───────────────────────────────────────

        // Replace: discard existing reference, start a fresh scan.
        async startReplace() {
            this.existingDocumentPath = null;
            this.existingDocumentUrl  = null;
            this.phase = 'loading';
            await this.loadLibs();
        },

        // Add page to existing document.
        // - Image: pre-load it as page 1 of the new scan session.
        // - PDF: fetch its bytes so saveDocument() can merge new scanned pages via pdf-lib.
        async startAddPage() {
            this.phase = 'loading';
            const isPdf   = this.existingDocumentPath?.endsWith('.pdf');
            const isImage = this.existingDocumentPath && !isPdf;

            if (isImage) {
                try {
                    const resp    = await fetch(this.existingDocumentUrl);
                    const blob    = await resp.blob();
                    const dataUrl = await new Promise(r => {
                        const reader = new FileReader();
                        reader.onload = e => r(e.target.result);
                        reader.readAsDataURL(blob);
                    });
                    this.pages = [dataUrl]; // existing image becomes page 1
                } catch { /* start fresh if fetch fails */ }
            } else if (isPdf) {
                try {
                    const resp = await fetch(this.existingDocumentUrl);
                    this.existingPdfBytes = await resp.arrayBuffer();
                    // new scanned pages will be appended to this PDF in saveDocument()
                } catch {
                    this.existingPdfBytes = null; // start fresh if fetch fails
                }
            }

            this.existingDocumentPath = null;
            this.existingDocumentUrl  = null;
            await this.loadLibs();
        },

        fromGallery(e) {
            const file = e.target.files?.[0];
            if (!file) return;
            // reset input so same file can be reselected
            e.target.value = '';

            // Validar tamaño máximo: 4 MB
            const MAX_BYTES = 4 * 1024 * 1024;
            if (file.size > MAX_BYTES) {
                this.errorMsg = `El archivo es demasiado grande (${(file.size / 1024 / 1024).toFixed(1)} MB). El tamaño máximo permitido es 4 MB.`;
                this.phase = 'error';
                return;
            }

            // PDF selected
            if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                this.stopCamera();
                this.phase = 'saving';
                const reader = new FileReader();
                reader.onload = async ev => {
                    try {
                        let pdfDataUri;

                        // Recopilar todas las páginas acumuladas
                        const allScanned = [...this.pages];
                        console.log('[Scanner] fromGallery PDF → pages.length:', this.pages.length, '| allScanned:', allScanned.length, '| phase:', this.phase);

                        if (allScanned.length > 0) {
                            // Hay páginas escaneadas → merge: imágenes primero, luego páginas del PDF de galería
                            console.log('[Scanner] Iniciando merge con', allScanned.length, 'páginas escaneadas + PDF de galería');
                            await this.loadPdfLib();
                            const { PDFDocument } = window.PDFLib;
                            console.log('[Scanner] PDFLib cargado:', !!PDFDocument);

                            const mergedPdf = await PDFDocument.create();

                            // Agregar todas las páginas escaneadas
                            for (const pageDataUrl of allScanned) {
                                const base64 = pageDataUrl.split(',')[1];
                                const imgBytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
                                const jpgImage = await mergedPdf.embedJpg(imgBytes);
                                const page = mergedPdf.addPage([jpgImage.width, jpgImage.height]);
                                page.drawImage(jpgImage, { x: 0, y: 0, width: jpgImage.width, height: jpgImage.height });
                            }

                            // Copiar páginas del PDF de galería al final
                            const galleryBase64 = ev.target.result.split(',')[1];
                            const galleryBytes = Uint8Array.from(atob(galleryBase64), c => c.charCodeAt(0));
                            const galleryPdf = await PDFDocument.load(galleryBytes);
                            const copiedPages = await mergedPdf.copyPages(galleryPdf, galleryPdf.getPageIndices());
                            copiedPages.forEach(p => mergedPdf.addPage(p));

                            const pdfBytes = await mergedPdf.save();
                            let binary = '';
                            const chunk = 8192;
                            for (let i = 0; i < pdfBytes.length; i += chunk) {
                                binary += String.fromCharCode(...pdfBytes.subarray(i, i + chunk));
                            }
                            pdfDataUri = 'data:application/pdf;base64,' + btoa(binary);
                            this.pages = [];
                        } else {
                            // Sin páginas escaneadas → subir el PDF directamente
                            pdfDataUri = ev.target.result;
                        }

                        const res = await fetch('{{ route("pipo-scanner.upload") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ pdf: pdfDataUri }),
                        });
                        if (!res.ok) {
                            const errBody = await res.json().catch(() => ({}));
                            throw new Error('Error del servidor (' + res.status + '): ' + (errBody?.message || errBody?.error || ''));
                        }
                        const data = await res.json();
                        this.savedPath = data.path;
                        this.savedUrl  = data.url;
                        this.phase     = 'saved';
                        $wire.call('setScannerDocumentPath', data.path)
                            .catch(err => console.error('[Scanner] setScannerDocumentPath falló', err));
                        this.$dispatch('scanner-saved', { path: data.path, url: data.url });
                    } catch (err) {
                        this.errorMsg = err.message;
                        this.phase = 'error';
                    }
                };
                reader.readAsDataURL(file);
                return;
            }

            // Validate: only images beyond this point
            if (!file.type.startsWith('image/')) {
                this.errorMsg = 'Tipo de archivo no soportado. Seleccione una imagen (JPG, PNG, HEIC) o un PDF.';
                this.phase = 'error';
                return;
            }

            const reader = new FileReader();
            reader.onload = ev => {
                const img = new Image();
                img.onload = async () => {
                    this.rawCanvas = document.createElement('canvas');
                    this.rawCanvas.width  = img.width;
                    this.rawCanvas.height = img.height;
                    this.rawCanvas.getContext('2d').drawImage(img, 0, 0);
                    this.stopCamera();
                    const w = img.width, h = img.height, p = Math.min(w, h) * 0.05;
                    this.editCorners = {
                        topLeftCorner:     { x: p,     y: p },
                        topRightCorner:    { x: w - p, y: p },
                        bottomRightCorner: { x: w - p, y: h - p },
                        bottomLeftCorner:  { x: p,     y: h - p },
                    };
                    await this.goToEditor();
                };
                img.onerror = () => {
                    this.errorMsg = 'No se pudo cargar la imagen seleccionada.';
                    this.phase = 'error';
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        },
}));
</script>
@endscript

{{-- ───────────────────────────────────────────────────────────────────────────
     DOCUMENT SCANNER — Professional (Adobe Scan / CamScanner style)
     Real-time edge detection · Perspective correction · 4 filters · Upload
──────────────────────────────────────────────────────────────────────────── --}}
@php
    // Filament passes $record to all component views via getExtraViewData().
    // In create mode $record is null; in edit mode it's the Movement model.
    $scannerExistingPath = '';
    $scannerExistingUrl  = '';
    $scannerRecord       = $record ?? null;
    if ($scannerRecord && !empty($scannerRecord->document_path)) {
        $scannerExistingPath = $scannerRecord->document_path;
        $scannerExistingUrl  = asset('storage/' . $scannerRecord->document_path);
    }
@endphp
<div
    x-data="docScanner()"
    x-init="init()"
    wire:ignore
    data-storage-url="{{ rtrim(asset('storage'), '/') }}"
    data-existing-path="{{ $scannerExistingPath }}"
    data-existing-url="{{ $scannerExistingUrl }}"
    class="w-full rounded-2xl bg-slate-900 shadow-2xl select-none"
    style="display:flex;flex-direction:column;overflow:hidden;position:relative;height:580px;min-height:580px;border-radius:1rem;"
>

    {{-- ── HEADER ─────────────────────────────────────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;background:#1e293b;border-bottom:1px solid #334155;flex-shrink:0;">

        {{-- Status dot + label --}}
        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
            <span style="position:relative;display:flex;width:10px;height:10px;flex-shrink:0;">
                <span x-show="phase === 'camera'"
                      class="animate-ping"
                      style="position:absolute;width:100%;height:100%;border-radius:50%;background:#4ade80;opacity:0.7;"></span>
                <span :style="{
                          'background': phase === 'camera' || phase === 'saved' ? '#4ade80'
                                      : phase === 'existing' ? '#38bdf8'
                                      : phase === 'editor' ? '#fbbf24'
                                      : phase === 'filter' ? '#818cf8'
                                      : phase === 'error'  ? '#f87171'
                                      : '#64748b'
                      }"
                      style="position:relative;width:10px;height:10px;border-radius:50%;display:inline-block;">
                </span>
            </span>
            <span style="color:#fff;font-size:0.75rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <span x-show="phase === 'loading'">Iniciando escáner…</span>
                <span x-show="phase === 'camera_prompt'">Escáner Listo</span>
                <span x-show="phase === 'camera'">Captura de Documento</span>
                <span x-show="phase === 'editor'">Ajustar Recorte</span>
                <span x-show="phase === 'filter'">Seleccionar Filtro</span>
                <span x-show="phase === 'saving'">Guardando…</span>
                <span x-show="phase === 'saved'">Documento Listo ✓</span>
                <span x-show="phase === 'error'">Error</span>
                <span x-show="phase === 'no_camera'">Sin Cámara</span>
                <span x-show="phase === 'existing'">Documento Adjunto</span>
            </span>
        </div>

        {{-- Right actions --}}
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">

            {{-- Gallery (camera_prompt / camera / no_camera) --}}
            <label x-show="['camera_prompt','camera','no_camera'].includes(phase)"
                   style="cursor:pointer;display:flex;align-items:center;gap:6px;color:#cbd5e1;font-size:0.75rem;font-weight:500;padding:5px 10px;border-radius:8px;border:1px solid #334155;background:transparent;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                Galería
                <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" style="display:none;" @change="fromGallery($event)">
            </label>

            {{-- Cancel / discard scan (editor / filter only) --}}
            <button x-show="['editor','filter'].includes(phase)"
                    type="button" @click="retake()"
                    style="display:flex;align-items:center;gap:6px;color:#f87171;font-size:0.75rem;font-weight:500;padding:5px 10px;border-radius:8px;border:1px solid #451a1a;background:rgba(127,29,29,0.2);cursor:pointer;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Descartar
            </button>
        </div>
    </div>

    {{-- ── MAIN VIEW ─────────────────────────────────────────────────────── --}}
    <div class="flex-1 min-h-0 relative overflow-hidden"
         style="flex:1;min-height:0;position:relative;overflow:hidden;">

        {{-- LOADING --}}
        <div x-show="phase === 'loading'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;background:#0f172a;z-index:10;">
            <div class="animate-spin"
                 style="width:52px;height:52px;border-radius:50%;border:4px solid #334155;border-top-color:#6366f1;"></div>
            <div style="text-align:center;">
                <p style="color:#cbd5e1;font-size:0.875rem;font-weight:600;">Cargando motor de escaneo</p>
                <p style="color:#64748b;font-size:0.75rem;margin-top:4px;">Inicializando OpenCV y jscanify…</p>
            </div>
        </div>

        {{-- CAMERA PROMPT — user must tap to trigger getUserMedia (required for Android permission dialog) --}}
        <div x-show="phase === 'camera_prompt'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem;background:#0f172a;padding:2rem;z-index:10;">

            {{-- Camera icon --}}
            <div style="width:80px;height:80px;border-radius:50%;background:rgba(79,70,229,0.15);border:1px solid rgba(99,102,241,0.4);display:flex;align-items:center;justify-content:center;">
                <svg width="36" height="36" fill="none" stroke="#818cf8" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"/>
                </svg>
            </div>

            <div style="text-align:center;max-width:280px;">
                <p style="color:#e2e8f0;font-weight:700;font-size:0.95rem;">Escáner listo</p>
                <p style="color:#64748b;font-size:0.75rem;margin-top:6px;line-height:1.6;">
                    Toca el botón para activar la cámara.<br>
                    El navegador pedirá permiso de acceso.
                </p>
            </div>

            <button type="button" @click="activateCamera()"
                    style="display:flex;align-items:center;justify-content:center;gap:10px;padding:12px 28px;border-radius:12px;background:#4f46e5;color:#fff;font-size:0.875rem;font-weight:700;border:none;cursor:pointer;box-shadow:0 0 20px rgba(79,70,229,0.4);">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"/>
                </svg>
                Activar Cámara
            </button>
        </div>

        {{-- NO CAMERA --}}
        <div x-show="phase === 'no_camera'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;background:#0f172a;padding:1.5rem 2rem;z-index:10;overflow-y:auto;">

            {{-- Icon --}}
            <div :style="cameraErrorType === 'denied'
                            ? 'background:rgba(127,29,29,0.3);border-color:#7f1d1d;'
                            : 'background:#1e293b;border-color:#334155;'"
                 style="width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:1px solid;flex-shrink:0;">
                {{-- Lock icon for denied --}}
                <svg x-show="cameraErrorType === 'denied'" width="30" height="30" fill="none" stroke="#f87171" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                </svg>
                {{-- Crossed camera for notfound / unknown --}}
                <svg x-show="cameraErrorType !== 'denied'" width="30" height="30" fill="none" stroke="#64748b" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18"/>
                </svg>
            </div>

            {{-- Message --}}
            <div style="text-align:center;max-width:300px;">
                {{-- DENIED --}}
                <template x-if="cameraErrorType === 'denied'">
                    <div>
                        <p style="color:#fca5a5;font-weight:700;font-size:0.875rem;">Permiso de cámara denegado</p>
                        <p style="color:#94a3b8;font-size:0.72rem;margin-top:6px;line-height:1.6;">
                            El navegador bloqueó el acceso a la cámara.
                        </p>
                        {{-- iOS instructions --}}
                        <template x-if="isIOS">
                            <p style="color:#64748b;font-size:0.7rem;margin-top:8px;line-height:1.7;background:#1e293b;padding:8px 10px;border-radius:8px;text-align:left;">
                                <strong style="color:#94a3b8;">En iPhone/iPad:</strong><br>
                                Ajustes → Safari → Cámara → <strong style="color:#94a3b8;">Permitir</strong><br>
                                Luego recarga la página.
                            </p>
                        </template>
                        {{-- Android instructions --}}
                        <template x-if="isAndroid">
                            <p style="color:#64748b;font-size:0.7rem;margin-top:8px;line-height:1.7;background:#1e293b;padding:8px 10px;border-radius:8px;text-align:left;">
                                <strong style="color:#94a3b8;">En Android:</strong><br>
                                Toca el ícono 🔒 en la barra de dirección → Permisos → Cámara → <strong style="color:#94a3b8;">Permitir</strong>
                            </p>
                        </template>
                        {{-- Desktop --}}
                        <template x-if="!isIOS && !isAndroid">
                            <p style="color:#64748b;font-size:0.7rem;margin-top:8px;line-height:1.7;background:#1e293b;padding:8px 10px;border-radius:8px;text-align:left;">
                                Haz clic en el ícono 🔒 o 📷 en la barra de dirección del navegador y activa el permiso de cámara.
                            </p>
                        </template>
                    </div>
                </template>

                {{-- NOT FOUND --}}
                <template x-if="cameraErrorType === 'notfound'">
                    <div>
                        <p style="color:#e2e8f0;font-weight:700;font-size:0.875rem;">No se encontró cámara</p>
                        <p style="color:#64748b;font-size:0.72rem;margin-top:6px;line-height:1.6;">
                            Este dispositivo no tiene cámara disponible o está siendo usada por otra aplicación.
                        </p>
                    </div>
                </template>

                {{-- INSECURE (HTTP on LAN / non-localhost) --}}
                <template x-if="cameraErrorType === 'insecure'">
                    <div>
                        <p style="color:#fbbf24;font-weight:700;font-size:0.875rem;">Se requiere HTTPS</p>
                        <p style="color:#64748b;font-size:0.72rem;margin-top:6px;line-height:1.6;">
                            El navegador bloquea la cámara en conexiones HTTP.<br>
                            Para pruebas locales accede por <strong style="color:#94a3b8;">localhost</strong> o habilita HTTPS.<br>
                            Puedes usar <strong style="color:#94a3b8;">Galería</strong> para cargar una imagen.
                        </p>
                    </div>
                </template>

                {{-- UNKNOWN --}}
                <template x-if="cameraErrorType === 'unknown' || !cameraErrorType">
                    <div>
                        <p style="color:#e2e8f0;font-weight:700;font-size:0.875rem;">Cámara no disponible</p>
                        <p style="color:#64748b;font-size:0.72rem;margin-top:6px;line-height:1.6;">
                            No se pudo acceder a la cámara. Verifica los permisos del navegador.
                        </p>
                    </div>
                </template>
            </div>

            {{-- Actions --}}
            <div style="display:flex;flex-direction:column;gap:8px;width:100%;max-width:240px;">
                {{-- Retry button — hidden on iOS denied or insecure context --}}
                <button x-show="cameraErrorType !== 'insecure' && !(cameraErrorType === 'denied' && isIOS)"
                        type="button" @click="retryCamera()"
                        style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:9px 16px;border-radius:10px;background:#4f46e5;color:#fff;font-size:0.8rem;font-weight:600;border:none;cursor:pointer;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                    </svg>
                    <span x-text="cameraErrorType === 'denied' ? 'Solicitar Permiso' : 'Reintentar'"></span>
                </button>

                {{-- Gallery fallback --}}
                <label style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:9px 16px;border-radius:10px;background:transparent;color:#94a3b8;font-size:0.8rem;font-weight:500;border:1px solid #334155;cursor:pointer;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    Cargar desde Galería
                    <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" style="display:none;" @change="fromGallery($event)">
                </label>
            </div>
        </div>

        {{-- CAMERA VIEW --}}
        <div x-show="phase === 'camera'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;background:#000;">
            <video x-ref="video" autoplay playsinline muted
                   style="width:100%;height:100%;object-fit:contain;display:block;"></video>
            <canvas x-ref="overlay"
                    style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;"></canvas>

            {{-- Corner guides --}}
            <div x-show="!docDetected" style="position:absolute;top:24px;right:24px;bottom:24px;left:24px;pointer-events:none;">
                <div style="position:absolute;top:0;left:0;width:28px;height:28px;border-top:2px solid rgba(148,163,184,0.4);border-left:2px solid rgba(148,163,184,0.4);border-radius:2px 0 0 0;"></div>
                <div style="position:absolute;top:0;right:0;width:28px;height:28px;border-top:2px solid rgba(148,163,184,0.4);border-right:2px solid rgba(148,163,184,0.4);border-radius:0 2px 0 0;"></div>
                <div style="position:absolute;bottom:0;left:0;width:28px;height:28px;border-bottom:2px solid rgba(148,163,184,0.4);border-left:2px solid rgba(148,163,184,0.4);border-radius:0 0 0 2px;"></div>
                <div style="position:absolute;bottom:0;right:0;width:28px;height:28px;border-bottom:2px solid rgba(148,163,184,0.4);border-right:2px solid rgba(148,163,184,0.4);border-radius:0 0 2px 0;"></div>
            </div>

            {{-- Page queue badge (multi-page mode) --}}
            <template x-if="pages.length > 0">
                <div style="position:absolute;top:12px;right:12px;pointer-events:none;">
                    <div style="background:rgba(79,70,229,0.9);color:#fff;border:1px solid rgba(129,140,248,0.5);display:flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:0.7rem;font-weight:700;">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span x-text="pages.length + ' pág. en cola'"></span>
                    </div>
                </div>
            </template>

            {{-- Detection badge --}}
            <div style="position:absolute;bottom:14px;left:50%;transform:translateX(-50%);pointer-events:none;">
                <div :style="docDetected
                        ? 'background:rgba(34,197,94,0.9);color:#fff;border-color:rgba(74,222,128,0.5);'
                        : 'background:rgba(0,0,0,0.65);color:#94a3b8;border-color:rgba(71,85,105,0.5);'"
                     style="display:flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;font-size:0.75rem;font-weight:600;border:1px solid;transition:all 0.3s;">
                    <span :style="docDetected ? 'background:white;' : 'background:#64748b;'"
                          style="width:6px;height:6px;border-radius:50%;display:inline-block;transition:background 0.3s;"></span>
                    <span x-text="docDetected ? 'Documento detectado' : 'Buscando documento…'"></span>
                </div>
            </div>
        </div>

        {{-- EDITOR VIEW --}}
        <div x-show="phase === 'editor'"
             x-ref="editorCtr"
             style="position:absolute;top:0;right:0;bottom:0;left:0;background:#020617;">
            <canvas x-ref="editorCanvas"
                    style="position:absolute;top:0;left:0;width:100%;height:100%;touch-action:none;cursor:crosshair;"
                    @pointerdown="onPointerDown($event)"
                    @pointermove="onPointerMove($event)"
                    @pointerup="onPointerUp()"
                    @pointercancel="onPointerUp()">
            </canvas>

            {{-- Loupe (magnifier) — shown while dragging a corner handle, positioned via JS --}}
            <canvas x-ref="loupeCanvas"
                    style="display:none;position:absolute;border-radius:50%;pointer-events:none;z-index:20;box-shadow:0 4px 24px rgba(0,0,0,0.7);">
            </canvas>

            <div style="position:absolute;top:12px;left:50%;transform:translateX(-50%);pointer-events:none;">
                <div style="background:rgba(0,0,0,0.75);color:#cbd5e1;font-size:0.75rem;padding:6px 14px;border-radius:999px;border:1px solid rgba(71,85,105,0.6);">
                    Arrastra las esquinas para ajustar el recorte
                </div>
            </div>
        </div>

        {{-- FILTER VIEW — image fills all space, filter bar overlaid at top --}}
        <div x-show="phase === 'filter'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;background:#020617;">

            {{-- Image fills entire area --}}
            <div style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;align-items:center;justify-content:center;padding:8px;">
                <canvas x-ref="filterCanvas"
                        style="max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 25px 50px rgba(0,0,0,0.5);"></canvas>
            </div>

            {{-- Filter buttons overlay — semi-transparent bar at the top --}}
            <div style="position:absolute;top:0;left:0;right:0;display:flex;gap:6px;padding:8px;background:linear-gradient(to bottom,rgba(2,6,23,0.92) 60%,transparent);pointer-events:auto;">
                <template x-for="[fid, flabel, ficon] in [
                    ['color','Color','🎨'],['gray','Gris','⬛'],['document','Doc','📄'],['enhance','Realce','✨']
                ]" :key="fid">
                    <button type="button"
                            @click="applyFilter(fid)"
                            :style="activeFilter === fid
                                ? 'background:rgba(79,70,229,0.95);color:#fff;border-color:#6366f1;'
                                : 'background:rgba(30,41,59,0.85);color:#94a3b8;border-color:rgba(51,65,85,0.7);'"
                            style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 4px;border-radius:9px;border:1px solid;font-size:0.75rem;font-weight:500;cursor:pointer;transition:all 0.15s;backdrop-filter:blur(4px);">
                        <span style="font-size:0.95rem;line-height:1;" x-text="ficon"></span>
                        <span style="font-size:10px;text-align:center;line-height:1.2;" x-text="flabel"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- SAVING --}}
        <div x-show="phase === 'saving'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;background:rgba(15,23,42,0.96);z-index:10;">
            <div class="animate-spin"
                 style="width:52px;height:52px;border-radius:50%;border:4px solid #334155;border-top-color:#6366f1;"></div>
            <div style="text-align:center;">
                <p style="color:#e2e8f0;font-size:0.875rem;font-weight:600;">Subiendo documento…</p>
                <p style="color:#64748b;font-size:0.75rem;margin-top:4px;">Por favor espera</p>
            </div>
        </div>

        {{-- EXISTING DOCUMENT (edit mode) --}}
        <div x-show="phase === 'existing'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem;background:#0f172a;padding:2rem;z-index:10;">

            {{-- Icon --}}
            <div style="width:80px;height:80px;border-radius:50%;background:rgba(56,189,248,0.1);display:flex;align-items:center;justify-content:center;border:1px solid rgba(56,189,248,0.3);">
                <svg width="40" height="40" fill="none" stroke="#38bdf8" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                </svg>
            </div>

            {{-- Info --}}
            <div style="text-align:center;">
                <p style="color:#f1f5f9;font-weight:600;font-size:0.9rem;">Documento adjunto</p>
                <p style="color:#64748b;font-size:0.72rem;margin-top:5px;" x-text="existingDocumentPath ? existingDocumentPath.split('/').pop() : ''"></p>
            </div>

            {{-- Actions --}}
            <div style="display:flex;flex-direction:column;gap:10px;width:100%;max-width:280px;">

                {{-- Preview --}}
                <a :href="existingDocumentUrl" target="_blank" rel="noopener"
                   style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;background:rgba(56,189,248,0.1);color:#38bdf8;border:1px solid rgba(56,189,248,0.3);border-radius:10px;font-size:0.8rem;font-weight:600;text-decoration:none;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.641 0-8.572-3.007-9.964-7.178z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Vista previa
                </a>

                {{-- Add page --}}
                <button type="button" @click="startAddPage()"
                        style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;background:#1e293b;color:#cbd5e1;border:1px solid #334155;border-radius:10px;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Agregar página
                    <template x-if="existingDocumentPath && !existingDocumentPath.endsWith('.pdf')">
                        <span style="color:#64748b;font-size:0.65rem;">(imagen existente como pág. 1)</span>
                    </template>
                    <template x-if="existingDocumentPath && existingDocumentPath.endsWith('.pdf')">
                        <span style="color:#64748b;font-size:0.65rem;">(páginas nuevas al final del PDF)</span>
                    </template>
                </button>

                {{-- Replace --}}
                <button type="button" @click="startReplace()"
                        style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;background:#1e293b;color:#f87171;border:1px solid rgba(248,113,113,0.2);border-radius:10px;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                    </svg>
                    Reemplazar documento
                </button>
            </div>
        </div>

        {{-- SAVED --}}
        <div x-show="phase === 'saved'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.25rem;background:#0f172a;padding:2rem;z-index:10;">
            <div style="width:72px;height:72px;border-radius:50%;background:rgba(34,197,94,0.12);display:flex;align-items:center;justify-content:center;border:1px solid rgba(34,197,94,0.25);">
                <svg width="36" height="36" fill="none" stroke="#4ade80" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div style="text-align:center;">
                <p style="color:#f1f5f9;font-weight:600;font-size:0.875rem;">Documento listo</p>
                <p style="color:#64748b;font-size:0.75rem;margin-top:6px;">Vinculado al formulario. Guardá el movimiento para confirmar.</p>
            </div>
            <template x-if="savedUrl">
                <a :href="savedUrl" target="_blank" rel="noopener"
                   style="display:flex;align-items:center;gap:6px;color:#818cf8;font-size:0.75rem;font-weight:500;text-decoration:underline;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                    Ver documento guardado
                </a>
            </template>
        </div>

        {{-- ERROR --}}
        <div x-show="phase === 'error'"
             style="position:absolute;top:0;right:0;bottom:0;left:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.25rem;background:#0f172a;padding:2rem;z-index:10;">
            <div style="width:72px;height:72px;border-radius:50%;background:rgba(239,68,68,0.12);display:flex;align-items:center;justify-content:center;border:1px solid rgba(239,68,68,0.25);">
                <svg width="36" height="36" fill="none" stroke="#f87171" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div style="text-align:center;max-width:280px;">
                <p style="color:#e2e8f0;font-weight:600;font-size:0.875rem;">Ocurrió un error</p>
                <p style="color:#64748b;font-size:0.75rem;margin-top:6px;" x-text="errorMsg"></p>
            </div>
            <button type="button" @click="retake()"
                    style="padding:8px 20px;background:#4f46e5;color:#fff;border-radius:10px;font-size:0.75rem;font-weight:600;border:none;cursor:pointer;">
                Reintentar
            </button>
        </div>
    </div>

    {{-- ── FOOTER ──────────────────────────────────────────────────────────── --}}
    <div class="bg-slate-800 border-t border-slate-700/80"
         style="padding:10px 16px;flex-shrink:0;background:#1e293b;border-top:1px solid #334155;">

        {{-- Existing document: no footer buttons (actions are in the panel) --}}
        <div x-show="phase === 'existing'" style="height:0;overflow:hidden;"></div>

        {{-- Camera: Capture --}}
        <div x-show="phase === 'camera'">
            <button type="button" @click="capture()"
                    :style="docDetected
                        ? 'background:#22c55e;'
                        : 'background:#4f46e5;'"
                    style="width:100%;padding:12px;color:#fff;font-weight:700;font-size:0.875rem;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:all 0.15s;">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span x-text="docDetected ? 'Capturar Documento' : 'Capturar Foto'"></span>
            </button>
        </div>

        {{-- No camera: Gallery --}}
        <div x-show="phase === 'no_camera'">
            <label style="width:100%;padding:12px;background:#4f46e5;color:#fff;font-weight:700;font-size:0.875rem;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                Seleccionar imagen de la galería
                <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" style="display:none;" @change="fromGallery($event)">
            </label>
        </div>

        {{-- Editor: Back + Confirm --}}
        <div x-show="phase === 'editor'" style="display:flex;gap:8px;">
            <button type="button" @click="retake()"
                    style="padding:10px 16px;background:#334155;color:#cbd5e1;font-size:0.75rem;font-weight:600;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Retomar
            </button>
            <button type="button" @click="confirmCrop()"
                    style="flex:1;padding:10px;background:#4f46e5;color:#fff;font-weight:700;font-size:0.875rem;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                Confirmar Recorte
            </button>
        </div>

        {{-- Filter: Confirm (full width) + secondary row --}}
        <div x-show="phase === 'filter'" style="display:flex;flex-direction:column;gap:6px;">

            {{-- Page queue badge --}}
            <template x-if="pages.length > 0">
                <div style="text-align:center;">
                    <span style="display:inline-block;background:#312e81;color:#a5b4fc;font-size:0.7rem;font-weight:600;padding:3px 12px;border-radius:999px;border:1px solid #4338ca;">
                        <span x-text="pages.length + 1"></span> páginas listas para PDF
                    </span>
                </div>
            </template>

            {{-- Primary action: Confirm (always full width) --}}
            <button type="button" @click="saveDocument()"
                    style="width:100%;padding:11px;background:#4f46e5;color:#fff;font-weight:700;font-size:0.875rem;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <span x-text="pages.length > 0 ? 'Confirmar PDF (' + (pages.length + 1) + ' págs.)' : 'Confirmar Escáner'"></span>
            </button>

            {{-- Secondary row: ↺ | Voltear | ↻ | Reajustar | + Página --}}
            <div style="display:flex;gap:5px;">
                {{-- Rotate left --}}
                <button type="button" @click="rotate(-90)"
                        style="flex:1;padding:8px 2px;background:#1e293b;color:#94a3b8;font-size:0.6rem;font-weight:600;border-radius:9px;border:1px solid #334155;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 .49-3.65"/>
                    </svg>
                    <span x-text="rotation ? rotation+'°' : '↺'"></span>
                </button>

                {{-- Flip horizontal --}}
                <button type="button" @click="toggleFlip()"
                        :style="flipH ? 'background:#1e3a5f;color:#60a5fa;border-color:#2563eb;' : 'background:#1e293b;color:#94a3b8;border-color:#334155;'"
                        style="flex:1;padding:8px 2px;border-radius:9px;border:1px solid;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;font-size:0.6rem;font-weight:600;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                    Voltear
                </button>

                {{-- Rotate right --}}
                <button type="button" @click="rotate(90)"
                        style="flex:1;padding:8px 2px;background:#1e293b;color:#94a3b8;font-size:0.6rem;font-weight:600;border-radius:9px;border:1px solid #334155;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-.49-3.65"/>
                    </svg>
                    ↻
                </button>

                {{-- Back to editor --}}
                <button type="button" @click="goToEditor()"
                        style="flex:1;padding:8px 2px;background:#1e293b;color:#94a3b8;font-size:0.6rem;font-weight:600;border-radius:9px;border:1px solid #334155;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Reajustar
                </button>

                {{-- Add another page --}}
                <button type="button" @click="addPage()"
                        style="flex:1;padding:8px 2px;background:#1e293b;color:#818cf8;font-size:0.6rem;font-weight:600;border-radius:9px;border:1px solid #334155;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    + Pág.
                </button>
            </div>
        </div>

        {{-- Saved: Replace scan --}}
        <div x-show="phase === 'saved'">
            <button type="button" @click="retake()"
                    style="width:100%;padding:12px;background:#1e293b;color:#64748b;font-weight:600;font-size:0.8rem;border-radius:10px;border:1px solid #334155;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Reemplazar documento
            </button>
        </div>

        {{-- Spacer for phases without footer button --}}
        <div x-show="['loading','saving','error'].includes(phase)" style="height:44px;"></div>

    </div>

</div>
