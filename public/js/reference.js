(function () {
    'use strict';

    var TEMPLATES = [
        { id: 'S',  name: 'Small',       width: 30, height: 60 },
        { id: 'M',  name: 'Medium',      width: 40, height: 80 },
        { id: 'L',  name: 'Large',       width: 50, height: 100 },
        { id: 'XL', name: 'Extra Large', width: 60, height: 120 },
        { id: 'TL', name: 'Tall Label',  width: 35, height: 80 }
    ];
    var TEMPLATE_KEY = 'label_template';

    var videoEl, cameraCard, statusBox, startBtn, stopBtn, cameraLabelEl;
    var cameraSelectEl, cameraResBadgeEl;
    var CAMERA_STORAGE_KEY = 'label_inspection_camera_id';
    var activeStream = null;

    var templateStep, scanStep, templateGrid, continueBtn, stepIndicator;
    var templateInfoEl, changeTemplateBtn;
    var selectedTemplate = null;
    var templateCards = {};

    // ===== Reference entry menu: Choose vs Create New =====
    var menuStep, chooseBtn, createNewBtn, backToMenuBtn;

    // ===== Choose Label Reference — Jalur B (pilih tanpa scan ulang) =====
    // Satu-satunya sumber: Library FastAPI (tab "Milik Saya" dihapus sesuai request).
    var choosePanel, chooseSearch,
        chooseGrid, chooseLoading, chooseError, chooseEmpty,
        choosePrev, chooseNext, choosePageInfo, chooseBackBtn,
        chooseRefreshBtn, chooseRefreshIcon;
    var chooseTab = 'global'; // 'mine' (nonaktif, dipertahankan untuk kompatibilitas) | 'global'
    var choosePage = 1, chooseTotalPages = 1, chooseTotal = 0, chooseQ = '';
    var CHOOSE_PER_PAGE = 12;
    var chooseSearchTimer = null;
    var chooseAbort = null;
    var chooseBlobUrls = [];
    var choosePollTimer = null;
    var choosePollAttempts = 0;
    var CHOOSE_POLL_MAX_ATTEMPTS = 25; // 25 attempts * 2.5s = ~60s
    var CHOOSE_POLL_INTERVAL_MS = 2500;
    // Peta sisi FRONT/BACK dari data lokal (ground truth filename -front/-back).
    // fastapi_ref_id -> { side: 'front'|'back', sessionKey: basis stamp }. Best-effort.
    var chooseSideMap = {};
    var chooseSideMapPromise = null;
    // Delete card Choose: popup konfirmasi + hapus DB & daftar (fungsional CP-050).
    var deleteModal, deleteModalFiles, deleteModalError,
        deleteModalCancel, deleteModalConfirm, deleteModalBackdrop;
    var deleteTarget = null; // { files:[{label,name}], urls:[DELETE...] }
    var deleteBusy = false;
    // Popup nama reference label (STAGE-B: fungsional — nama dikirim via dualSaveBoth/saveToServer).
    var refnameModal, refnameInput, refnameError,
        refnameCancel, refnameConfirm, refnameBackdrop;
    var pendingRefName = null; // nama dari popup, dikirim sebagai field `name` (3..80, selaras FastAPI)
    var REFNAME_MIN_LEN = 3, REFNAME_MAX_LEN = 80;

    // ===== Guide overlay =====
    var guideCanvas, guideCtx, guideBadge, guideRange, guideValue;
    var GUIDE_PAD = 24;
    var GUIDE_RANGE_KEY = 'guide_frame_size_v2'; // v2: reset pref lama agar default baru 100% langsung berlaku
    var guideResizeTimer = null;
    var GUIDE_DEFAULT_SIZE = 100;
    var GUIDE_MIN_SIZE = 20, GUIDE_MAX_SIZE = 100;

    // ===== Capture =====
    var captureBtn, captureFeedbackEl, captureFlashEl;
    var captureFeedbackTimer = null;
    var CAPTURE_FEEDBACK_MS = 3000;

    // ===== Result =====
    var resultCard, resultImage, scanHeader;
    var resultInfoTemplate, resultInfoSize, resultInfoTime,
        resultInfoFilename, resultInfoResolution, resultInfoFilesize;
    var saveCaptureBtn, retakeBtn, resultFeedbackEl;
    var saveBtnIcon, saveBtnText, resultHelpText;
    var resultFeedbackTimer = null;
    var lastCapture = null;

    // ===== Test Signal (DEPRECATED — auto OCR now) =====
    var testCaptureBtn, testBtnIcon, testBtnText, testFeedbackEl;
    var testFeedbackTimer = null;

    // ===== Auto OCR on Capture =====
    var pendingOcrData = null;
    var isOcrProcessing = false;
    var activeOcrFilename = null; // CP-016: filename yang sedang diproses (anti stale)
    var activeOcrAbort = null;    // CP-016: AbortController untuk fetch preview
    var retakeCleanupTimer = null;

    // ===== OCR Panel (Hybrid PUSH + PULL) =====
    var ocrPanel, ocrLoading, ocrLoadingSeconds, ocrCleanedWrapper, ocrCleanedTextEl,
        ocrRawWrapper, ocrRawTextEl, ocrMeta, ocrEngineEl, ocrTimeEl, ocrProcessedAtEl,
        ocrLinesCountEl, ocrStatusBadge, ocrErrorEl;
    var ocrPollTimer = null;
    var ocrLoadingTimer = null;
    var ocrStartTime = null;
    var OCR_POLL_INTERVAL_MS = 3000;
    // FIX timeout Label 1/2 (2026-10-06): OCR nyata 60-90s, 14x3s=42s selalu kalah.
    // Baru 40x3s=120s. Habis 120s tampilkan "masih processing, klik Cek Lagi", bukan failed.
    var OCR_POLL_MAX_ATTEMPTS = 40;

    // ===== Loading / Success =====
    var loadingOverlay, loadingText, successOverlay, backToScanBtn;
    var successTitleEl, successDescEl;

    // ===== DUAL-SIDE Front+Back (2 tahap, tanpa migration) =====
    // Kontrak: 2x POST /api/references + 2x POST /api/ocr/preview, filename 1 basis stamp + -front/-back.
    var dualSide = 'front'; // 'front' | 'back' | 'done'
    var dualBaseStamp = null;
    var lastCaptureFront = null, lastCaptureBack = null;
    var pendingOcrFront = null, pendingOcrBack = null;
    var dualFrontDone = false, dualBackDone = false;
    var dualRefIdFront = null, dualRefIdBack = null;
    var dualStep1El, dualStep2El, dualStepSaveEl, dualStageInfoEl, dualSideBadgeEl,
        dualFrontMiniEl, dualFrontThumbEl, dualFrontMetaEl, dualFrontOcrEl,
        dualRetakeFrontEl, dualNextToBackEl, dualMiniFeedbackEl,
        dualResultCardEl, dualFrontImgEl, dualBackImgEl,
        dualFrontTextEl, dualBackTextEl, dualFrontBadgeEl, dualBackBadgeEl,
        dualResultMetaEl, dualSaveBtnEl, dualSaveBtnTextEl, dualSaveFeedbackEl,
        dualRetakeFront2El, dualRetakeBack2El, captureBtnTextEl,
        dualBackLoadingEl, dualBackThumbEl, dualBackLoadingFileEl, dualBackLoadingSecsEl;
    var dualOcrAbortFront = null, dualOcrAbortBack = null;
    var dualBackLoadingTimer = null, dualBackLoadingStart = 0;
    // ===== Manual rotate preview (default horizontal, OCR eksplisit via Proses) =====
    // Base canvas horizontal per sisi + derajat 0/90/180/270. Blob final selalu sama dengan preview terlihat.
    var dualBaseFront = null, dualDegFront = 0, dualBusyFront = false;
    var dualBaseBack = null, dualDegBack = 0, dualBusyBack = false;
    var singleBase = null, singleDeg = 0, singleBusy = false;
    var dualRotateFrontLeftEl, dualRotateFrontRightEl, dualRotateFrontDegEl, dualProcessFrontEl,
        dualBackMiniEl, dualBackPreviewEl, dualBackMetaEl, dualBackOcrEl,
        dualRotateBackLeftEl, dualRotateBackRightEl, dualRotateBackDegEl, dualProcessBackEl,
        dualRetakeBackEl, dualBackFeedbackEl, dualNextToResultEl,
        dualRotateResFrontLeftEl, dualRotateResFrontRightEl, dualRotateResFrontDegEl, dualReprocessFrontEl,
        dualRotateResBackLeftEl, dualRotateResBackRightEl, dualRotateResBackDegEl, dualReprocessBackEl,
        resultRotateLeftEl, resultRotateRightEl, resultRotateDegEl, resultProcessOcrEl;
    // ===== Auto-crop split: 1 sisi panjang -> Label 1 + Label 2 (OCR + simpan per belahan) =====
    var splitFrontA = null, splitFrontB = null, splitBackA = null, splitBackB = null;
    var ocrFront1 = null, ocrFront2 = null, ocrBack1 = null, ocrBack2 = null;
    var doneFront1 = false, doneFront2 = false, doneBack1 = false, doneBack2 = false;
    var refFront1 = null, refFront2 = null, refBack1 = null, refBack2 = null;
    var splitAbortFront1 = null, splitAbortFront2 = null, splitAbortBack1 = null, splitAbortBack2 = null;
    var frontSplitWrapEl, frontSplitImg1El, frontSplitMeta1El, frontSplitBadge1El, frontSplitOcr1El,
        frontSplitImg2El, frontSplitMeta2El, frontSplitBadge2El, frontSplitOcr2El,
        backSplitWrapEl, backSplitImg1El, backSplitMeta1El, backSplitBadge1El, backSplitOcr1El,
        backSplitImg2El, backSplitMeta2El, backSplitBadge2El, backSplitOcr2El,
        frontAdjustEl, backAdjustEl,
        frontSingleEl, backSingleEl, frontReviewImgEl, backReviewImgEl,
        frontTab1El, frontTab2El, frontOcrViewEl, frontCheck1El, frontCheck2El,
        backTab1El, backTab2El, backOcrViewEl, backCheck1El, backCheck2El,
        frontOcrToggleEl, frontOcrBodyEl, backOcrToggleEl, backOcrBodyEl,
        resFrontImg1El, resFrontBadge1El, resFrontText1El, resFrontImg2El, resFrontBadge2El, resFrontText2El,
        resBackImg1El, resBackBadge1El, resBackText1El, resBackImg2El, resBackBadge2El, resBackText2El;

    function showStatus(message, type) {
        if (!statusBox) return;
        statusBox.textContent = message || '';
        statusBox.className = 'mt-4 p-4 rounded-xl text-sm ' + (type === 'error'
            ? 'bg-sky-50 border border-sky-200 text-sky-600'
            : 'bg-[#E9ECEF] border border-[#6C757D]/30 text-[#212529]');
    }

    function populateCameraSelect(currentDeviceId) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices || !cameraSelectEl) return;

        navigator.mediaDevices.enumerateDevices().then(function (devices) {
            var videoDevices = devices.filter(function (d) { return d.kind === 'videoinput'; });
            cameraSelectEl.innerHTML = '';

            if (videoDevices.length === 0) {
                var emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = 'Tidak ada kamera terdeteksi';
                cameraSelectEl.appendChild(emptyOpt);
                return;
            }

            var savedId = null;
            try { savedId = localStorage.getItem(CAMERA_STORAGE_KEY); } catch (e) {}
            var targetId = currentDeviceId || savedId;
            var found = false;

            videoDevices.forEach(function (device, idx) {
                var opt = document.createElement('option');
                opt.value = device.deviceId;
                opt.textContent = device.label || ('Kamera ' + (idx + 1));
                if (targetId && device.deviceId === targetId) {
                    opt.selected = true;
                    found = true;
                }
                cameraSelectEl.appendChild(opt);
            });

            if (!found && videoDevices.length > 0) {
                cameraSelectEl.selectedIndex = 0;
            }
        }).catch(function (err) {
            console.warn('Gagal membaca daftar kamera:', err);
        });
    }

    function updateCameraResolutionBadge() {
        if (!cameraResBadgeEl) return;
        var w = (videoEl && videoEl.videoWidth) ? videoEl.videoWidth : 0;
        var h = (videoEl && videoEl.videoHeight) ? videoEl.videoHeight : 0;
        if ((!w || !h) && activeStream) {
            var track = activeStream.getVideoTracks()[0];
            if (track && track.getSettings) {
                var s = track.getSettings();
                w = s.width || 0;
                h = s.height || 0;
            }
        }
        if (w && h) {
            cameraResBadgeEl.textContent = 'Resolusi: ' + w + ' × ' + h + ' px';
            cameraResBadgeEl.classList.remove('hidden');
        } else {
            cameraResBadgeEl.classList.add('hidden');
        }
    }

    function stopCamera() {
        if (activeStream) {
            activeStream.getTracks().forEach(function (track) { track.stop(); });
            activeStream = null;
        }
        if (videoEl) videoEl.srcObject = null;
        if (cameraCard) cameraCard.classList.add('hidden');
        if (startBtn) startBtn.classList.remove('hidden');
        if (statusBox) statusBox.textContent = '';
        if (cameraResBadgeEl) cameraResBadgeEl.classList.add('hidden');
    }

    function startCamera(preferredDeviceId, isRetry) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showStatus('Camera is not supported on this browser or the connection is not secure (HTTPS/localhost required).', 'error');
            return;
        }

        var savedDeviceId = null;
        try { savedDeviceId = localStorage.getItem(CAMERA_STORAGE_KEY); } catch (e) {}
        var targetDeviceId = preferredDeviceId || savedDeviceId;

        startBtn.disabled = true;
        showStatus('Requesting camera...', 'info');

        var videoConstraints = {
            width: { ideal: 3840, min: 640 },
            height: { ideal: 2160, min: 480 }
        };

        if (targetDeviceId) {
            videoConstraints.deviceId = { exact: targetDeviceId };
        } else {
            videoConstraints.facingMode = { ideal: 'environment' };
        }

        navigator.mediaDevices.getUserMedia({
            video: videoConstraints,
            audio: false
        }).then(function (stream) {
            activeStream = stream;
            videoEl.srcObject = stream;
            cameraCard.classList.remove('hidden');
            startBtn.classList.add('hidden');
            startBtn.disabled = false;

            var track = stream.getVideoTracks()[0];
            var settings = (track && track.getSettings) ? track.getSettings() : {};
            var activeDeviceId = settings.deviceId || targetDeviceId;
            if (activeDeviceId) {
                try { localStorage.setItem(CAMERA_STORAGE_KEY, activeDeviceId); } catch (e) {}
            }

            if (cameraLabelEl) {
                cameraLabelEl.textContent = track && track.label ? track.label : 'Camera active';
            }

            populateCameraSelect(activeDeviceId);
            updateCameraResolutionBadge();

            statusBox.textContent = '';
            statusBox.className = 'hidden';

            drawGuide();
        }).catch(function (error) {
            if (targetDeviceId && !isRetry && (error.name === 'OverconstrainedError' || error.name === 'NotFoundError' || error.name === 'AbortError')) {
                try { localStorage.removeItem(CAMERA_STORAGE_KEY); } catch (e) {}
                console.warn('Kamera pilihan tidak tersedia, fallback ke kamera default...');
                return startCamera(null, true);
            }

            stopCamera();
            startBtn.disabled = false;

            var message = 'Unable to start the camera. Please try again.';
            if (error.name === 'NotAllowedError' || error.name === 'SecurityError') {
                message = 'Camera access was denied. Allow camera permission and try again.';
            } else if (error.name === 'NotFoundError' || error.name === 'OverconstrainedError') {
                message = 'No camera device was found on this system.';
            } else if (error.name === 'NotReadableError') {
                message = 'The camera is already in use by another application.';
            }

            showStatus(message, 'error');
        });
    }

    function svgShape(t) {
        var stage = 40;
        var scale = 60 / 120;
        var w = Math.round(t.width * scale * 10) / 10;
        var h = Math.round(t.height * scale * 10) / 10;
        var x = (stage - w) / 2;
        var y = (60 - h) / 2;

        return '<svg viewBox="0 0 40 60" class="w-14 h-16 text-sky-600">' +
            '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="2" ' +
            'fill="rgba(2,132,199,0.08)" stroke="currentColor" stroke-width="1.5"/>' +
            '<line x1="0" y1="30" x2="40" y2="30" stroke="currentColor" stroke-width="0.5" stroke-dasharray="2 3" opacity="0.5"/>' +
            '<line x1="20" y1="0" x2="20" y2="60" stroke="currentColor" stroke-width="0.5" stroke-dasharray="2 3" opacity="0.5"/>' +
            '</svg>';
    }

    function onTemplateSelect(t) {
        selectedTemplate = t;

        Object.keys(templateCards).forEach(function (key) {
            var card = templateCards[key];
            var active = key === t.id;
            card.classList.toggle('border-sky-600', active);
            card.classList.toggle('bg-[#F8F9FA]', active);
            card.classList.toggle('ring-2', active);
            card.classList.toggle('ring-sky-500', active);
            card.classList.toggle('border-[#6C757D]/20', !active);
            card.classList.toggle('bg-[#E9ECEF]', !active);
            card.setAttribute('aria-pressed', active ? 'true' : 'false');

            var check = card.querySelector('.template-check');
            if (check) check.classList.toggle('hidden', !active);

            var badge = card.querySelector('.template-badge');
            if (badge) badge.classList.toggle('bg-sky-100', active);
            if (badge) badge.classList.toggle('text-sky-600', active);
        });

        if (continueBtn) continueBtn.disabled = false;
    }

    function persistSelection() {
        if (!selectedTemplate) return;
        sessionStorage.setItem(TEMPLATE_KEY, JSON.stringify(selectedTemplate));
    }

    function loadSelection() {
        try {
            var raw = sessionStorage.getItem(TEMPLATE_KEY);
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (!parsed || parsed.id === undefined) return null;

            var match = TEMPLATES.filter(function (t) { return t.id === parsed.id; })[0];
            return match || null;
        } catch (e) {
            return null;
        }
    }

    function renderTemplates() {
        templateGrid.innerHTML = '';

        TEMPLATES.forEach(function (t) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('data-template', t.id);
            btn.setAttribute('aria-pressed', 'false');
            btn.className = [
                'group relative flex flex-col items-center justify-center gap-2 ',
                'bg-[#E9ECEF] border-2 border-[#6C757D]/20 rounded-2xl p-4 ',
                'cursor-pointer transition-all duration-200 ',
                'hover:border-sky-400 hover:shadow-md ',
                'focus:outline-none focus:ring-2 focus:ring-sky-300'
            ].join('');

            btn.innerHTML =
                svgShape(t) +
                '<span class="template-badge inline-flex px-2.5 py-0.5 rounded-md bg-[#F8F9FA] border border-[#6C757D]/20 text-xs font-bold text-[#6C757D]">' + t.id + '</span>' +
                '<span class="text-sm font-semibold text-[#212529]">' + t.name + '</span>' +
                '<span class="text-xs text-[#6C757D]">' + t.width + ' \u00d7 ' + t.height + ' mm</span>' +
                '<svg class="template-check hidden absolute top-3 right-3 w-5 h-5 text-sky-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>' +
                '</svg>';

            btn.addEventListener('click', function () { onTemplateSelect(t); });
            templateGrid.appendChild(btn);
            templateCards[t.id] = btn;
        });
    }

    function goToSelectStep() {
        showReferenceMenu();
    }

    function showReferenceMenu() {
        if (typeof stopCamera === 'function') { try { stopCamera(); } catch (e) {} }
        abortChooseFetch();
        if (menuStep) menuStep.classList.remove('hidden');
        if (templateStep) templateStep.classList.add('hidden');
        if (scanStep) scanStep.classList.add('hidden');
        if (typeof hideChoosePanel === 'function') hideChoosePanel();
        if (stepIndicator) stepIndicator.textContent = 'Start';
        try { if (typeof dualResetAll === 'function') dualResetAll(); } catch (e) {}
    }

    function showTemplateStep() {
        goToScanStep();
    }

    function backToMenuFromScan() {
        if (typeof stopCamera === 'function') { try { stopCamera(); } catch (e) {} }
        abortChooseFetch();
        if (scanStep) scanStep.classList.add('hidden');
        if (templateStep) templateStep.classList.add('hidden');
        if (typeof hideChoosePanel === 'function') hideChoosePanel();
        if (menuStep) menuStep.classList.remove('hidden');
        if (stepIndicator) stepIndicator.textContent = 'Start';
        try { if (typeof dualResetAll === 'function') dualResetAll(); } catch (e) {}
    }

    function goToScanStep() {
        if (!selectedTemplate) {
            selectedTemplate = { id: 'AUTO', name: 'Auto-Detected', width: 35, height: 90 };
        }

        persistSelection();
        if (templateInfoEl) {
            templateInfoEl.textContent = 'Mode: Auto-Detect Label (Opsi C)';
        }
        updateGuideBadge();
        if (menuStep) menuStep.classList.add('hidden');
        if (templateStep) templateStep.classList.add('hidden');
        if (typeof hideChoosePanel === 'function') hideChoosePanel();
        if (scanStep) scanStep.classList.remove('hidden');
        if (stepIndicator) stepIndicator.textContent = 'Live Scan';
        try { if (typeof dualResetAll === 'function') dualResetAll(); } catch (e) {}
        try { if (typeof dualApplyStageToCamera === 'function') dualApplyStageToCamera(); } catch (e) {}
        if (scanHeader) scanHeader.classList.remove('hidden');
        try { startCamera(); } catch (e) {}
    }

    // ===== Guide overlay =====

    function initGuideElements() {
        guideCanvas = document.getElementById('guide-overlay');
        guideBadge = document.getElementById('guide-size-badge');
        guideRange = document.getElementById('guide-size-range');
        guideValue = document.getElementById('guide-size-value');
        if (guideCanvas) guideCtx = guideCanvas.getContext('2d');
    }

    function loadGuideSize() {
        try {
            var v = parseInt(sessionStorage.getItem(GUIDE_RANGE_KEY), 10);
            if (v >= GUIDE_MIN_SIZE && v <= GUIDE_MAX_SIZE) return v;
        } catch (e) {}
        return GUIDE_DEFAULT_SIZE;
    }

    function persistGuideSize(v) {
        try { sessionStorage.setItem(GUIDE_RANGE_KEY, String(v)); } catch (e) {}
    }

    // Area video yang benar-benar terlihat (object-contain letterbox)
    function getContentRect() {
        var cw = videoEl.clientWidth, ch = videoEl.clientHeight;
        var vw = videoEl.videoWidth, vh = videoEl.videoHeight;
        if (!cw || !ch || !vw || !vh) return null;
        var scale = Math.min(cw / vw, ch / vh);
        var w = vw * scale, h = vh * scale;
        return { x: (cw - w) / 2, y: (ch - h) / 2, w: w, h: h };
    }

    // Dimensi guide: horizontal 2x untuk scan bolak-balik 2 sisi dalam 1 frame (Create New).
    // Master tegak (mis. TL 35 x 80 mm) -> Guide horizontal 160 x 35 mm -> 16 x 3.5 cm.
    // Rumus acuan planning/reference_guide_horizontal_double_length.md:
    // lebar guide = tinggi master x 2, tinggi guide = lebar master.
    function guideEffectiveDims() {
        if (!selectedTemplate) return null;
        var mmW = selectedTemplate.height * 2;
        var mmH = selectedTemplate.width;
        return { mmW: mmW, mmH: mmH, cmW: mmW / 10, cmH: mmH / 10 };
    }

    // Posisi & ukuran kotak guide (auto-fit, horizontal 2x)
    function getGuideBox(content) {
        var dims = guideEffectiveDims();
        if (!dims) return null;
        var cmW = dims.cmW;
        var cmH = dims.cmH;
        var availW = content.w - GUIDE_PAD * 2;
        var availH = content.h - GUIDE_PAD * 2;
        var base = Math.min(availW / cmW, availH / cmH);
        // FIX-GUIDE-SLIDER: validasi live DOM, fallback default bila NaN/di luar 20-100
        var _rawSize = guideRange ? parseInt(guideRange.value, 10) : NaN;
        var size = (_rawSize >= GUIDE_MIN_SIZE && _rawSize <= GUIDE_MAX_SIZE) ? _rawSize : GUIDE_DEFAULT_SIZE;
        var pxPerCm = base * (size / 100);
        var w = cmW * pxPerCm, h = cmH * pxPerCm;
        return {
            x: content.x + (content.w - w) / 2,
            y: content.y + (content.h - h) / 2,
            w: w, h: h, cmW: cmW, cmH: cmH
        };
    }

    function roundedRectPath(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function drawLabelPill(ctx, text, cx, cy) {
        ctx.font = '600 12px ui-sans-serif, system-ui, sans-serif';
        var tw = ctx.measureText(text).width;
        var w = tw + 16, h = 20;
        roundedRectPath(ctx, cx - w / 2, cy - h / 2, w, h, h / 2);
        ctx.fillStyle = 'rgba(33,37,41,0.75)';
        ctx.fill();
        ctx.fillStyle = '#F8F9FA';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, cx, cy + 0.5);
    }

    function drawGuide() {
        if (!guideCtx || !guideCanvas) return;
        var cw = videoEl ? videoEl.clientWidth : (guideCanvas.width || 0);
        var ch = videoEl ? videoEl.clientHeight : (guideCanvas.height || 0);
        if (cw && ch) {
            guideCtx.clearRect(0, 0, cw, ch);
        }
    }

    function updateGuideBadge() {
        if (!guideBadge) return;
        guideBadge.textContent = 'Auto-Detect';
    }

    function onGuideResize() {
        clearTimeout(guideResizeTimer);
        guideResizeTimer = setTimeout(drawGuide, 150);
    }

    // ===== Capture =====

    function buildCaptureFilename(dateParam) {
        var d = dateParam || new Date();
        function p(n) { return (n < 10 ? '0' : '') + n; }
        var stamp = '' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) +
            '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
        return 'label-ref-' + (selectedTemplate ? selectedTemplate.id.toLowerCase() : 'auto') + '-' + stamp + '.png';
    }

    function showCaptureFeedback(message, isError) {
        if (!captureFeedbackEl) return;
        captureFeedbackEl.textContent = message;
        captureFeedbackEl.className = 'text-xs font-medium ' +
            (isError ? 'text-red-600' : 'text-sky-600');
        clearTimeout(captureFeedbackTimer);
        captureFeedbackTimer = setTimeout(function () {
            if (captureFeedbackEl) captureFeedbackEl.textContent = '';
        }, CAPTURE_FEEDBACK_MS);
    }

    function flashEffect() {
        if (!captureFlashEl) return;
        captureFlashEl.classList.remove('opacity-0');
        setTimeout(function () {
            if (captureFlashEl) captureFlashEl.classList.add('opacity-0');
        }, 150);
    }

    // ===== Result =====

    function downloadBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function formatDateTime(d) {
        function p(n) { return (n < 10 ? '0' : '') + n; }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() +
            ' \u00b7 ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    // Helper khusus card Choose: format tanggal capture "18 Sep 2026 · 14:05:22".
    // Input kosong/null -> '-'; parsing gagal -> string asli (jangan error).
    // (formatDateTime di atas JANGAN diubah — dipakai alur capture.)
    function formatChooseDate(v) {
        if (v === undefined || v === null || v === '') return '-';
        var d = (v instanceof Date) ? v : new Date(v);
        if (isNaN(d.getTime())) return String(v);
        function p2(n) { return (n < 10 ? '0' : '') + n; }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return p2(d.getDate()) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() +
            ' · ' + p2(d.getHours()) + ':' + p2(d.getMinutes()) + ':' + p2(d.getSeconds());
    }

    function formatBytes(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }

    function discardLastCapture() {
        if (lastCapture && lastCapture.url) {
            try { URL.revokeObjectURL(lastCapture.url); } catch (e) {}
        }
        lastCapture = null;
    }

    function showResultFeedback(message, isError) {
        if (!resultFeedbackEl) return;
        resultFeedbackEl.textContent = message;
        resultFeedbackEl.className = 'text-xs font-medium ' +
            (isError ? 'text-red-600' : 'text-sky-600');
        clearTimeout(resultFeedbackTimer);
        resultFeedbackTimer = setTimeout(function () {
            if (resultFeedbackEl) resultFeedbackEl.textContent = '';
        }, CAPTURE_FEEDBACK_MS);
    }

    function enterResultState() {
        stopCamera();
        if (scanHeader) scanHeader.classList.add('hidden');
        if (cameraCard) cameraCard.classList.add('hidden');
        if (resultCard) resultCard.classList.remove('hidden');
    }

    function retakeCapture() {
        var oldFilename = lastCapture ? lastCapture.filename : (activeOcrFilename || '');

        // 1. Abort poll & preview (single flight, anti stale)
        if (activeOcrAbort) { try { activeOcrAbort.abort(); } catch (e) {} activeOcrAbort = null; }
        clearOcrPolling();
        isOcrProcessing = false;
        activeOcrFilename = null;

        // 2. Panggil backend cleanup (best effort, jangan block UI) — hapus pending DB + external file
        if (oldFilename) {
            var token = '';
            try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
            if (token) {
                fetch('/api/ocr/retake-cleanup', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ filename: oldFilename })
                }).then(function (r) { return r.json(); })
                    .then(function (d) { console.log('[OCR] retake cleanup:', d); })
                    .catch(function (e) { console.warn('[OCR] cleanup failed', e); });
            }
        }

        // 3. Reset state seperti sebelumnya
        pendingOcrData = null;
        discardLastCapture();
        singleBase = null; singleDeg = 0; singleBusy = false;
        try { singleUpdateRotateUI(); } catch (e) {}
        try { singleSetRotateBusy(false); } catch (e) {}
        hideOcrPanel();
        if (ocrPanel) ocrPanel.removeAttribute('data-filename');
        if (resultCard) resultCard.classList.add('hidden');
        if (scanHeader) scanHeader.classList.remove('hidden');
        if (saveCaptureBtn) saveCaptureBtn.disabled = true;
        if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
        if (resultFeedbackEl) resultFeedbackEl.textContent = '';
        startCamera();
    }

    function onSaveCapture() {
        if (!lastCapture || !lastCapture.blob) {
            showResultFeedback('No capture to save.', true);
            return;
        }
        saveToServer();
    }

    function showLoading(message) {
        if (loadingOverlay) loadingOverlay.classList.remove('hidden');
        if (loadingText) loadingText.textContent = message || 'Menyimpan reference...';
    }

    function hideLoading() {
        if (loadingOverlay) loadingOverlay.classList.add('hidden');
    }

    function showSuccess() {
        if (successOverlay) successOverlay.classList.remove('hidden');
    }

    function hideSuccess() {
        if (successOverlay) successOverlay.classList.add('hidden');
    }

    function setSaveBusy(busy) {
        if (saveCaptureBtn) saveCaptureBtn.disabled = busy;
        if (retakeBtn) retakeBtn.disabled = busy;
    }

    function resetSaveButton() {
        if (saveBtnIcon) {
            saveBtnIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>';
        }
        if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
    }

    // ===== Test Signal =====

    function setTestBusy(busy) {
        if (testCaptureBtn) testCaptureBtn.disabled = busy;
        if (saveCaptureBtn) saveCaptureBtn.disabled = busy;
        if (retakeBtn) retakeBtn.disabled = busy;
    }

    function showTestFeedback(message, isError) {
        if (!testFeedbackEl) return;
        testFeedbackEl.textContent = message;
        testFeedbackEl.className = 'text-xs font-medium ' +
            (isError ? 'text-[#E04438]' : 'text-[#6C757D]');
        clearTimeout(testFeedbackTimer);
        testFeedbackTimer = setTimeout(function () {
            if (testFeedbackEl) testFeedbackEl.textContent = '';
        }, 5000);
    }

    function resetTestButton() {
        if (testBtnIcon) {
            testBtnIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>';
        }
        if (testBtnText) testBtnText.textContent = 'Test';
    }

    // ===== Auto OCR on Capture — AKTIF (2026-09-11 / REVERT OCR MODE) =====
    // Alur: Capture Label → POST /api/ocr/preview → FastAPI OCR → raw teks tampil
    // di panel OCR Result + console browser → Simpan Reference (image + OCR) → /check.
    function triggerAutoOcr() {
        if (!lastCapture || !lastCapture.blob) return;
        if (isOcrProcessing && activeOcrAbort) {
            try { activeOcrAbort.abort(); } catch (e) {}
        }

        pendingOcrData = null;
        isOcrProcessing = true;
        activeOcrFilename = lastCapture.filename;
        // AbortController untuk bisa abort saat retake
        try {
            activeOcrAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        } catch (e) { activeOcrAbort = null; }

        if (saveCaptureBtn) saveCaptureBtn.disabled = true;
        if (saveBtnText) saveBtnText.textContent = 'Processing OCR...';

        showOcrPanel();
        setOcrLoading(true);
        if (ocrPanel) ocrPanel.setAttribute('data-filename', activeOcrFilename);
        showResultFeedback('Memproses OCR...', false);

        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) {
            renderOcrError('Sesi telah berakhir. Silakan login kembali.');
            isOcrProcessing = false;
            activeOcrFilename = null;
            activeOcrAbort = null;
            if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
            return;
        }

        var fd = new FormData();
        fd.append('image', lastCapture.blob, lastCapture.filename);
        fd.append('template_id', lastCapture.templateId);
        fd.append('template_name', lastCapture.templateName);
        fd.append('template_width', lastCapture.widthCm * 10);
        fd.append('template_height', lastCapture.heightCm * 10);
        fd.append('filename', lastCapture.filename);
        fd.append('resolution_w', lastCapture.widthPx);
        fd.append('resolution_h', lastCapture.heightPx);
        fd.append('filesize', lastCapture.filesize);

        var capturedFilename = lastCapture.filename;
        var fetchOpts = {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token },
            body: fd
        };
        if (activeOcrAbort && activeOcrAbort.signal) fetchOpts.signal = activeOcrAbort.signal;

        fetch('/api/ocr/preview', fetchOpts).then(function (res) {
            if (res.status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                throw new Error('UNAUTHORIZED');
            }
            if (res.status === 429) throw new Error('RATE_LIMITED');
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'Server error'); });
            return res.json();
        }).then(function (data) {
            console.log('[OCR Preview] Response:', data);

            var syncOcr = null;
            if (data && data.data && data.data.cleaned_text) syncOcr = data.data;
            else if (data && data.cleaned_text) syncOcr = data;

            if (syncOcr && syncOcr.cleaned_text) {
                // GUARD: hanya render jika masih filename aktif (anti stale)
                var syncFn = syncOcr.original_filename || syncOcr.filename || capturedFilename;
                if (activeOcrFilename && syncFn && syncFn !== activeOcrFilename) {
                    console.warn('[OCR] stale sync ignored', { expected: activeOcrFilename, got: syncFn });
                    return;
                }
                if (lastCapture && syncFn && syncFn !== lastCapture.filename) {
                    console.warn('[OCR] stale vs lastCapture ignored (sync)', { lastCapture: lastCapture.filename, got: syncFn });
                    return;
                }
                pendingOcrData = syncOcr;
                renderOcrResult(syncOcr);
                isOcrProcessing = false;
                activeOcrAbort = null;
                if (saveCaptureBtn) saveCaptureBtn.disabled = false;
                if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
                showResultFeedback('OCR selesai \u2014 siap disimpan', false);
                return;
            }

            // Guard empty dari preview (Fix A): status empty → jangan poll, langsung error
            if (data && (data.status === 'empty' || (data.data && (data.data.ocr_status === 'empty' || data.data.status === 'empty')))) {
                renderOcrError('OCR kosong — gambar terlalu gelap atau kontras rendah. Coba Retake dengan pencahayaan lebih terang.');
                showResultFeedback('OCR kosong — coba Retake', true);
                isOcrProcessing = false;
                activeOcrAbort = null;
                if (saveCaptureBtn) saveCaptureBtn.disabled = true;
                if (saveBtnText) saveBtnText.textContent = 'OCR Kosong — Retake Diperlukan';
                return;
            }

            if (data && (data.status === 'processing' || (data.data && data.data.ocr_status === 'processing'))) {
                showResultFeedback('Terkirim \u2014 menunggu OCR (webhook)...', false);
                if (saveBtnText) saveBtnText.textContent = 'Menunggu OCR...';
                pollOcrResult(capturedFilename, 0);
                return;
            }

            pollOcrResult(capturedFilename, 0);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') {
                console.log('[OCR] preview aborted for', capturedFilename);
                return;
            }
            clearOcrPolling();
            setOcrLoading(false);
            isOcrProcessing = false;
            // jangan reset activeOcrFilename jika abort — sudah di-retake
            if (err && err.message && err.message.indexOf('RATE_LIMITED') === 0) {
                var waitParts = err.message.split(':');
                var waitSecs = parseInt(waitParts[1], 10) || 60;
                renderOcrError('Terlalu cepat — FastAPI batasi 5 gambar/menit. Tunggu ' + waitSecs + ' detik lalu Retake.');
                showResultFeedback('Terlalu cepat. Tunggu ' + waitSecs + ' detik lalu Retake.', true);
                if (captureBtn) { captureBtn.disabled = true; setTimeout(function () { if (captureBtn) captureBtn.disabled = false; }, 3000); }
                activeOcrAbort = null;
                if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
                return;
            }
            activeOcrAbort = null;
            if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
            if (err && err.message === 'UNAUTHORIZED') {
                renderOcrError('Sesi berakhir. Silakan login kembali.');
                showResultFeedback('Sesi telah berakhir. Silakan login kembali.', true);
            } else {
                renderOcrError(err.message || 'Gagal memproses OCR');
                showResultFeedback('Gagal memproses OCR. Coba Retake.', true);
            }
        });
    }

    // ===== OCR Panel Helpers =====
    function initOcrPanel() {
        ocrPanel = document.getElementById('ocr-panel');
        ocrLoading = document.getElementById('ocr-loading');
        ocrLoadingSeconds = document.getElementById('ocr-loading-seconds');
        ocrCleanedWrapper = document.getElementById('ocr-cleaned-wrapper');
        ocrCleanedTextEl = document.getElementById('ocr-cleaned-text');
        ocrRawWrapper = document.getElementById('ocr-raw-wrapper');
        ocrRawTextEl = document.getElementById('ocr-raw-text');
        ocrMeta = document.getElementById('ocr-meta');
        ocrEngineEl = document.getElementById('ocr-engine');
        ocrTimeEl = document.getElementById('ocr-time');
        ocrProcessedAtEl = document.getElementById('ocr-processed-at');
        ocrLinesCountEl = document.getElementById('ocr-lines-count');
        ocrStatusBadge = document.getElementById('ocr-status-badge');
        ocrErrorEl = document.getElementById('ocr-error');
    }

    function showOcrPanel() {
        if (ocrPanel) ocrPanel.classList.remove('hidden');
    }

    function hideOcrPanel() {
        if (ocrPanel) ocrPanel.classList.add('hidden');
        clearOcrPolling();
        if (ocrLoading) ocrLoading.classList.add('hidden');
        if (ocrCleanedWrapper) ocrCleanedWrapper.classList.add('hidden');
        if (ocrRawWrapper) ocrRawWrapper.classList.add('hidden');
        if (ocrMeta) ocrMeta.classList.add('hidden');
        if (ocrErrorEl) { ocrErrorEl.classList.add('hidden'); ocrErrorEl.textContent = ''; }
        if (ocrStatusBadge) { ocrStatusBadge.textContent = 'idle'; ocrStatusBadge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30'; }
    }

    function setOcrLoading(isLoading) {
        if (!ocrPanel) return;
        showOcrPanel();
        if (isLoading) {
            if (ocrLoading) ocrLoading.classList.remove('hidden');
            if (ocrCleanedWrapper) ocrCleanedWrapper.classList.add('hidden');
            if (ocrRawWrapper) ocrRawWrapper.classList.add('hidden');
            if (ocrMeta) ocrMeta.classList.add('hidden');
            if (ocrErrorEl) ocrErrorEl.classList.add('hidden');
            if (ocrStatusBadge) { ocrStatusBadge.textContent = 'processing'; ocrStatusBadge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30'; }
            ocrStartTime = Date.now();
            clearInterval(ocrLoadingTimer);
            ocrLoadingTimer = setInterval(updateOcrLoadingTimer, 1000);
            updateOcrLoadingTimer();
        } else {
            if (ocrLoading) ocrLoading.classList.add('hidden');
            clearInterval(ocrLoadingTimer);
        }
    }

    function updateOcrLoadingTimer() {
        if (!ocrLoadingSeconds || !ocrStartTime) return;
        var elapsed = Math.floor((Date.now() - ocrStartTime) / 1000);
        ocrLoadingSeconds.textContent = elapsed + 's';
    }

    function clearOcrPolling() {
        if (ocrPollTimer) { clearTimeout(ocrPollTimer); ocrPollTimer = null; }
        if (ocrLoadingTimer) { clearInterval(ocrLoadingTimer); ocrLoadingTimer = null; }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function renderOcrResult(data) {
        if (!data) return;
        // GUARD CP-016: hanya render jika data milik capture yang sedang aktif (anti stale)
        var dataFilename = data.original_filename || data.filename || '';
        if (activeOcrFilename && dataFilename && dataFilename !== activeOcrFilename) {
            console.warn('[OCR] stale result ignored', { expected: activeOcrFilename, got: dataFilename });
            return;
        }
        if (lastCapture && dataFilename && dataFilename !== lastCapture.filename) {
            console.warn('[OCR] stale vs lastCapture ignored', { lastCapture: lastCapture.filename, got: dataFilename });
            return;
        }
        var cleaned = data.cleaned_text || data.cleanedText || '';
        var raw = data.raw_text || data.rawText || '';
        var lines = data.lines || [];
        var engine = data.engine || '-';
        // Tuning RapidOCR (2026-09-12): tampilkan model rec (small=cepat, medium=akurat).
        var recModel = data.rec_model || data.recModel || '';
        var detModel = data.det_model || data.detModel || '';
        var engineLabel = recModel ? (engine + ' · ' + recModel) : engine;
        var procTime = data.processing_time_seconds || data.processing_time || '-';
        var processedAt = data.processed_at || data.processedAt || '-';
        var status = data.status || 'success';

        // Guard empty (Fix A): jangan render success kosong — tampilkan error retake
        var trimmedCleaned = (cleaned || '').toString().trim();
        var hasLines = lines && lines.length && lines.some(function (l) { return (l || '').toString().trim() !== ''; });
        if (status === 'success' && trimmedCleaned === '' && !hasLines) {
            console.warn('[OCR] empty cleaned_text — treating as failed', data);
            renderOcrError('OCR kosong — gambar terlalu gelap atau kontras rendah. Coba Retake dengan pencahayaan lebih terang dan jarak lebih dekat.');
            showResultFeedback('OCR kosong — coba Retake dengan pencahayaan lebih baik', true);
            isOcrProcessing = false;
            if (saveCaptureBtn) saveCaptureBtn.disabled = true;
            if (saveBtnText) saveBtnText.textContent = 'OCR Kosong — Retake Diperlukan';
            return;
        }

        console.log('[OCR] cleaned_text:', cleaned);
        console.log('[OCR] raw_text:', raw);
        console.log('[OCR RAW] file=' + dataFilename + ' engine=' + engine + ' rec_model=' + (recModel || '-') + ' lines=' + lines.length);
        // Kontrak RapidOCR (2026-09-11): 'none' = tidak ada teks = valid, bukan error/retake
        if (trimmedCleaned.toLowerCase() === 'none') {
            console.log('[OCR] tidak ada teks terdeteksi (none) — hasil valid, siap disimpan');
        }
        console.log('[OCR] full:', data);

        if (ocrStatusBadge) {
            ocrStatusBadge.textContent = status;
            if (status === 'success') {
                ocrStatusBadge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-white text-[#212529] border-emerald-300';
            } else if (status === 'processing') {
                ocrStatusBadge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
            } else {
                ocrStatusBadge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E04438]/10 text-[#E04438] border-[#E04438]/30';
            }
        }

        if (ocrCleanedTextEl) ocrCleanedTextEl.textContent = cleaned || '-';
        if (ocrCleanedWrapper) ocrCleanedWrapper.classList.remove('hidden');

        if (raw && ocrRawTextEl) {
            ocrRawTextEl.textContent = raw;
            if (ocrRawWrapper) ocrRawWrapper.classList.remove('hidden');
        }

        if (ocrEngineEl) ocrEngineEl.textContent = engineLabel;
        if (ocrTimeEl) ocrTimeEl.textContent = procTime !== '-' ? procTime + 's' : '-';
        if (ocrProcessedAtEl) {
            try {
                var d = new Date(processedAt);
                ocrProcessedAtEl.textContent = isNaN(d.getTime()) ? processedAt : d.toLocaleString();
            } catch (e) { ocrProcessedAtEl.textContent = processedAt; }
        }
        if (ocrLinesCountEl) ocrLinesCountEl.textContent = lines.length;
        if (ocrMeta) ocrMeta.classList.remove('hidden');

        setOcrLoading(false);
        if (ocrErrorEl) ocrErrorEl.classList.add('hidden');
        try { if (typeof singleSetRotateBusy === 'function') singleSetRotateBusy(false); } catch (e) {}
    }

    function renderOcrError(message) {
        console.log('[OCR] error:', message);
        if (ocrErrorEl) { ocrErrorEl.textContent = message; ocrErrorEl.classList.remove('hidden'); }
        if (ocrStatusBadge) { ocrStatusBadge.textContent = 'failed'; ocrStatusBadge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E04438]/10 text-[#E04438] border-[#E04438]/30'; }
        setOcrLoading(false);
        try { if (typeof singleSetRotateBusy === 'function') singleSetRotateBusy(false); } catch (e) {}
    }

    function pollOcrResult(filename, attempt) {
        attempt = attempt || 0;
        // GUARD CP-016: jika filename bukan active, abort (stale poll)
        if (activeOcrFilename && filename !== activeOcrFilename) {
            console.warn('[OCR] poll stale aborted', filename, 'vs', activeOcrFilename);
            return;
        }
        if (attempt >= OCR_POLL_MAX_ATTEMPTS) {
            renderOcrError('Masih processing setelah 120 detik. Klik Cek Lagi (tanpa capture ulang) atau tunggu callback.');
            isOcrProcessing = false;
            if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
            // FIX timeout Label 1/2 (2026-10-06): masih-processing BUKAN gagal.
            // Jangan paksa disabled permanen; biarkan Cek Lagi tanpa capture ulang.
            showResultFeedback('Masih processing — klik Cek Lagi', false);
            // Deprecated Test UI fallback
            setTestBusy(false);
            resetTestButton();
            return;
        }

        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) return;

        // Jitter 0-500ms untuk hindari thundering herd + cache buster
        var jitter = Math.floor(Math.random() * 500);
        var delay = attempt === 0 ? 0 : OCR_POLL_INTERVAL_MS + jitter;
        try { if (ocrMeta) ocrMeta.textContent = 'OCR processing... ' + (attempt * 3) + 's / maks 120s'; } catch (e) {}

        ocrPollTimer = setTimeout(function () {
            // Guard lagi setelah delay — mungkin sudah retake
            if (activeOcrFilename && filename !== activeOcrFilename) {
                console.warn('[OCR] poll stale aborted (delayed)', filename, 'vs', activeOcrFilename);
                return;
            }
            fetch('/api/ocr/' + encodeURIComponent(filename) + '?_t=' + Date.now(), {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
            }).then(function (res) {
                if (res.status === 401) throw new Error('UNAUTHORIZED');
            // 429 dari Laravel (terusan rate-limit 1/menit FastAPI, fix 2026-09-15):
            // bawa retry_after_seconds agar pesan menunjukkan detik tunggu asli — tanpa polling sia-sia.
            if (res.status === 429) return res.json().then(function (d) {
                var wait = (d && d.retry_after_seconds) || 60;
                throw new Error('RATE_LIMITED:' + wait);
            }, function () { throw new Error('RATE_LIMITED'); });
                if (res.status === 422) throw new Error('EMPTY');
                if (res.status === 202) return { status: 'processing' };
                if (res.status === 404) return { status: 'not_found' };
                if (!res.ok) throw new Error('poll failed');
                return res.json();
            }).then(function (data) {
                var payload = data && data.data ? data.data : data;
                if (payload && payload.cleaned_text) {
                    var pf = payload.original_filename || payload.filename || '';
                    if (pf && pf !== filename) {
                        console.warn('[OCR] poll payload mismatch', pf, filename);
                        ocrPollTimer = setTimeout(function () { pollOcrResult(filename, attempt + 1); }, OCR_POLL_INTERVAL_MS + jitter);
                        return;
                    }
                    // GUARD lagi sebelum render
                    if (activeOcrFilename && pf && pf !== activeOcrFilename) {
                        console.warn('[OCR] poll stale payload vs active', pf, activeOcrFilename);
                        return;
                    }
                    pendingOcrData = payload;
                    renderOcrResult(payload);
                    isOcrProcessing = false;
                    activeOcrAbort = null;
                    if (saveCaptureBtn) saveCaptureBtn.disabled = false;
                    if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
                    showResultFeedback('OCR selesai \u2014 siap disimpan', false);
                    clearOcrPolling();
                    // Deprecated fallback
                    setTestBusy(false);
                    resetTestButton();
                    return;
                }
                if (payload && payload.status === 'empty') {
                    renderOcrError('OCR kosong — gambar terlalu gelap atau kontras rendah. Coba Retake dengan pencahayaan lebih terang.');
                    showResultFeedback('OCR kosong — coba Retake', true);
                    isOcrProcessing = false;
                    if (saveBtnText) saveBtnText.textContent = 'OCR Kosong — Retake Diperlukan';
                    if (saveCaptureBtn) saveCaptureBtn.disabled = true;
                    clearOcrPolling();
                    return;
                }
                if (payload && payload.status === 'processing') {
                    ocrPollTimer = setTimeout(function () { pollOcrResult(filename, attempt + 1); }, OCR_POLL_INTERVAL_MS + jitter);
                    return;
                }
                if (payload && payload.status === 'not_found') {
                    ocrPollTimer = setTimeout(function () { pollOcrResult(filename, attempt + 1); }, OCR_POLL_INTERVAL_MS + jitter);
                    return;
                }
                if (data && data.cleaned_text) {
                    var df = data.original_filename || data.filename || '';
                    if (df && df !== filename && df !== activeOcrFilename) {
                        console.warn('[OCR] payload mismatch (direct)', df, filename);
                        ocrPollTimer = setTimeout(function () { pollOcrResult(filename, attempt + 1); }, OCR_POLL_INTERVAL_MS + jitter);
                        return;
                    }
                    pendingOcrData = data;
                    renderOcrResult(data);
                    isOcrProcessing = false;
                    activeOcrAbort = null;
                    if (saveCaptureBtn) saveCaptureBtn.disabled = false;
                    if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
                    showResultFeedback('OCR selesai \u2014 siap disimpan', false);
                    clearOcrPolling();
                    setTestBusy(false);
                    resetTestButton();
                    return;
                }
                ocrPollTimer = setTimeout(function () { pollOcrResult(filename, attempt + 1); }, OCR_POLL_INTERVAL_MS + jitter);
            }).catch(function (err) {
                if (err && err.message === 'UNAUTHORIZED') {
                    try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                    renderOcrError('Sesi berakhir. Silakan login kembali.');
                    isOcrProcessing = false;
                    if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
                    setTestBusy(false);
                    resetTestButton();
                    return;
                }
                if (err && err.message === 'EMPTY') {
                    renderOcrError('OCR kosong — gambar terlalu gelap atau kontras rendah. Coba Retake dengan pencahayaan lebih terang.');
                    showResultFeedback('OCR kosong — coba Retake', true);
                    isOcrProcessing = false;
                    if (saveBtnText) saveBtnText.textContent = 'OCR Kosong — Retake Diperlukan';
                    if (saveCaptureBtn) saveCaptureBtn.disabled = true;
                    clearOcrPolling();
                    return;
                }
                if (err && err.message === 'RATE_LIMITED') {
                    // backoff exponential: 3s -> 5s -> 8s
                    var backoff = OCR_POLL_INTERVAL_MS * (attempt + 1) * 1.5;
                    var wait = Math.min(backoff, 8000) + jitter;
                    console.warn('[OCR] rate limited, backoff', wait);
                    ocrPollTimer = setTimeout(function () { pollOcrResult(filename, attempt + 1); }, wait);
                    return;
                }
                ocrPollTimer = setTimeout(function () { pollOcrResult(filename, attempt + 1); }, OCR_POLL_INTERVAL_MS + jitter);
            });
        }, delay);
    }

    function sendToTest() {
        if (!lastCapture || !lastCapture.blob) {
            showTestFeedback('No capture to test.', true);
            return;
        }

        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) {
            showTestFeedback('Sesi telah berakhir. Silakan login kembali.', true);
            return;
        }

        setTestBusy(true);
        showTestFeedback('Mengirim ke external service...', false);
        setOcrLoading(true);

        if (testBtnIcon) {
            testBtnIcon.innerHTML = '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>';
        }
        if (testBtnText) testBtnText.textContent = 'Sending...';

        var fd = new FormData();
        fd.append('image', lastCapture.blob, lastCapture.filename);
        fd.append('template_id', lastCapture.templateId);
        fd.append('template_name', lastCapture.templateName);
        fd.append('template_width', lastCapture.widthCm * 10);
        fd.append('template_height', lastCapture.heightCm * 10);
        fd.append('filename', lastCapture.filename);
        fd.append('resolution_w', lastCapture.widthPx);
        fd.append('resolution_h', lastCapture.heightPx);
        fd.append('filesize', lastCapture.filesize);

        var capturedFilename = lastCapture.filename;

        fetch('/api/test', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token },
            body: fd
        }).then(function (res) {
            if (res.status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                throw new Error('UNAUTHORIZED');
            }
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'Server error'); });
            return res.json();
        }).then(function (data) {
            console.log('[TestSignal] Response:', data);

            // Hybrid: cek apakah sync OCR langsung ada cleaned_text
            var syncOcr = null;
            if (data && data.data) {
                if (data.data.cleaned_text) syncOcr = data.data;
                else if (data.data.ocr && data.data.ocr.cleaned_text) syncOcr = data.data.ocr;
                else if (data.data.data && data.data.data.cleaned_text) syncOcr = data.data.data;
            }
            if (syncOcr && syncOcr.cleaned_text) {
                renderOcrResult(syncOcr);
                setTestBusy(false);
                resetTestButton();
                showTestFeedback('OCR selesai (sync)', false);
                return;
            }

            // Async: server return queued/processing — mulai polling PULL sebagai fallback untuk webhook
            showTestFeedback('Terkirim — menunggu OCR (webhook)...', false);
            // Tetap polling sebagai fallback, jika webhook sudah masuk DB akan langsung success
            pollOcrResult(capturedFilename, 0);
            // Jangan reset busy di sini — polling akan reset saat success/timeout
            // Tapi tetap update button text agar tidak stuck di Sending...
            if (testBtnText) testBtnText.textContent = 'Processing...';
        }).catch(function (err) {
            clearOcrPolling();
            setOcrLoading(false);
            setTestBusy(false);
            resetTestButton();
            if (err && err.message === 'UNAUTHORIZED') {
                showTestFeedback('Sesi telah berakhir. Silakan login kembali.', true);
                renderOcrError('Sesi berakhir. Silakan login kembali.');
            } else {
                showTestFeedback('Gagal mengirim. Coba lagi.', true);
                renderOcrError(err.message || 'Gagal mengirim ke external service');
            }
        });
    }

    function saveToServer() {
        if (!lastCapture || !lastCapture.blob) {
            showResultFeedback('No capture to save.', true);
            return;
        }
        // OCR MODE (2026-09-11): simpan image + hasil OCR (pendingOcrData dari
        // triggerAutoOcr). Jika OCR kosong/gagal, simpan tetap jalan tanpa teks.
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) {
            showResultFeedback('Sesi telah berakhir. Silakan login kembali.', true);
            return;
        }

        setSaveBusy(true);
        showLoading('Menyimpan reference...');

        var fd = new FormData();
        fd.append('image', lastCapture.blob, lastCapture.filename);
        fd.append('template_id', lastCapture.templateId);
        fd.append('template_name', lastCapture.templateName);
        // Jalur single lama: ikut kirim nama popup bila pernah diisi (tanpa RTT tambahan).
        if (pendingRefName && String(pendingRefName).trim() !== '') {
            fd.append('name', String(pendingRefName).trim().slice(0, REFNAME_MAX_LEN));
        }
        fd.append('template_width', lastCapture.widthCm * 10);
        fd.append('template_height', lastCapture.heightCm * 10);
        fd.append('filename', lastCapture.filename);
        fd.append('resolution_w', lastCapture.widthPx);
        fd.append('resolution_h', lastCapture.heightPx);
        fd.append('filesize', lastCapture.filesize);

        if (pendingOcrData) {
            if (pendingOcrData.cleaned_text) fd.append('cleaned_text', pendingOcrData.cleaned_text);
            if (pendingOcrData.raw_text) fd.append('raw_text', pendingOcrData.raw_text);
            if (pendingOcrData.lines) fd.append('lines', JSON.stringify(pendingOcrData.lines));
            if (pendingOcrData.engine) fd.append('engine', pendingOcrData.engine);
            if (pendingOcrData.language) fd.append('language', pendingOcrData.language);
            if (pendingOcrData.ocr_filename || pendingOcrData.filename) fd.append('ocr_filename', pendingOcrData.ocr_filename || pendingOcrData.filename);
            if (pendingOcrData.processing_time_seconds) fd.append('processing_time_seconds', String(pendingOcrData.processing_time_seconds));
            if (pendingOcrData.processed_at) fd.append('processed_at', String(pendingOcrData.processed_at));
        }

        fetch('/api/references', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token },
            body: fd
        }).then(function (res) {
            if (res.status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                throw new Error('UNAUTHORIZED');
            }
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'Server error'); });
            return res.json();
        }).then(function (res) {
            // Backend kini auto-activate 1 sesi dalam transaksi yang sama,
            // tapi frontend tetap panggil activate sebagai safety-net idempoten
            // agar Check (?ref=) dan Daftar (badge Aktif) selalu 1 sumber.
            var refId = res && res.data && res.data.id ? res.data.id : null;
            var sessKey = res && res.data && res.data.session_key ? res.data.session_key : null;
            if (!refId) throw new Error('Server error');
            try { sessionStorage.setItem('last_reference_id', String(refId)); } catch (e) {}
            try { sessionStorage.setItem('last_reference_ocr', JSON.stringify(res.data.ocr_result || pendingOcrData || {})); } catch (e) {}
            if (sessKey) { try { sessionStorage.setItem('last_reference_session', String(sessKey)); } catch (e) {} }
            if (typeof showLoading === 'function') { try { showLoading('Mengaktifkan acuan...'); } catch (e) {} }
            return activateReferences([refId], token).then(function () { return res; }, function (actErr) {
                // Server sudah auto-activate — aktivasi susulan gagal (mis. race) tetap lanjut,
                // tapi catat agar badge Daftar bisa direkonsiliasi via re-fetch.
                try { console.warn('[REF] activate safety-net gagal, lanjut (server sudah auto-activate)', actErr); } catch (e) {}
                return res;
            });
        }).then(function (res) {
            hideLoading();
            var refId = res && res.data && res.data.id ? res.data.id : null;
            var sessKey = res && res.data && res.data.session_key ? res.data.session_key : null;
            showSuccess();
            // Continue → data label capture + raw teks tersimpan sebagai Reference
            // Acuan, lalu direct ke /check?ref=id (klik Continue atau auto fallback).
            var target = refId ? ('/check?ref=' + encodeURIComponent(refId)) : '/check';
            try { window.__refGoCheck = target; } catch (e) {}
            if (backToScanBtn) {
                backToScanBtn.disabled = false;
                backToScanBtn.innerHTML = 'Continue ke Check Label' +
                    ' <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>';
            }
            // Auto direct fallback dalam 2.5s bila user tidak klik Continue
            setTimeout(function () {
                window.location.href = target;
            }, 2500);
        }).catch(function (err) {
            hideLoading();
            setSaveBusy(false);
            if (err && err.message === 'UNAUTHORIZED') {
                showResultFeedback('Sesi telah berakhir. Silakan login kembali.', true);
            } else {
                showResultFeedback('Gagal menyimpan. Silakan coba lagi.', true);
            }
        });
    }

    function renderResultInfo(m) {
        if (!resultInfoTemplate) return;
        resultInfoTemplate.textContent = m.templateId + ' \u2014 ' + m.templateName;
        resultInfoSize.textContent = m.widthCm + ' \u00d7 ' + m.heightCm + ' cm';
        resultInfoTime.textContent = formatDateTime(m.capturedAt);
        resultInfoResolution.textContent = m.widthPx + ' \u00d7 ' + m.heightPx + ' px';
        resultInfoFilename.textContent = m.filename;
        resultInfoFilesize.textContent = formatBytes(m.filesize);
    }

    // Rotate 90 derajat kanan (clockwise) untuk preview + OCR Reference.
    // Crop guide horizontal (mis. TL 160x35) -> canvas vertical (35x160) bila diputar.
    // Murni Canvas 2D bawaan, tanpa lib baru. Return canvas baru atau null bila gagal.
    function rotateCanvas90CW(srcCanvas) {
        if (!srcCanvas || !srcCanvas.width || !srcCanvas.height) return null;
        try {
            var dst = document.createElement('canvas');
            dst.width = srcCanvas.height;
            dst.height = srcCanvas.width;
            var ctx = dst.getContext('2d');
            if (!ctx) return null;
            ctx.translate(dst.width / 2, dst.height / 2);
            ctx.rotate(Math.PI / 2);
            ctx.drawImage(srcCanvas, -srcCanvas.width / 2, -srcCanvas.height / 2);
            return dst;
        } catch (e) {
            return null;
        }
    }

    // Render base horizontal pada derajat 0/90/180/270 (manual rotate, WYSIWYG untuk OCR).
    // deg 0 = horizontal asli, 90 = kanan 1x (vertical), 180 = putar 2x, 270 = kiri 1x.
    function renderBaseAtDeg(baseCanvas, deg) {
        if (!baseCanvas) return null;
        var d = ((deg % 360) + 360) % 360;
        if (d === 0) return baseCanvas;
        try {
            if (d === 180) {
                var c180 = document.createElement('canvas');
                c180.width = baseCanvas.width;
                c180.height = baseCanvas.height;
                var x180 = c180.getContext('2d');
                if (!x180) return null;
                x180.translate(c180.width / 2, c180.height / 2);
                x180.rotate(Math.PI);
                x180.drawImage(baseCanvas, -baseCanvas.width / 2, -baseCanvas.height / 2);
                return c180;
            }
            var steps = (d === 90) ? 1 : 3;
            var cur = baseCanvas;
            var tmp = null;
            for (var i = 0; i < steps; i++) {
                tmp = rotateCanvas90CW(cur);
                if (!tmp) return null;
                cur = tmp;
            }
            return cur;
        } catch (e) {
            return null;
        }
    }

    function degLabel(deg) {
        var d = ((deg % 360) + 360) % 360;
        return d + '°';
    }

    function isVerticalDeg(deg) {
        var d = ((deg % 360) + 360) % 360;
        return d === 90 || d === 270;
    }

    function onCaptureClick() {
        if (!activeStream || !selectedTemplate || !videoEl.videoWidth || videoEl.readyState < 2) {
            showCaptureFeedback('Camera is not ready yet.', true);
            return;
        }
        if (captureBtn) {
            captureBtn.disabled = true;
            // Debounce 1s — cegah double click spam
            setTimeout(function () { if (captureBtn) captureBtn.disabled = false; }, 1000);
        }
        try {
            captureFromCamera();
        } catch (e) {
            if (captureBtn) captureBtn.disabled = false;
            showCaptureFeedback('Capture failed. Please try again.', true);
        }
    }

    function captureFromCamera() {
        var content = getContentRect();
        if (!content) {
            if (captureBtn) captureBtn.disabled = false;
            return;
        }
        var g = getGuideBox(content);
        if (!g) {
            if (captureBtn) captureBtn.disabled = false;
            return;
        }

        var scaleX = videoEl.videoWidth / content.w;
        var scaleY = videoEl.videoHeight / content.h;
        var sx = Math.round((g.x - content.x) * scaleX);
        var sy = Math.round((g.y - content.y) * scaleY);
        var sw = Math.round(g.w * scaleX);
        var sh = Math.round(g.h * scaleY);

        sx = Math.max(0, Math.min(sx, videoEl.videoWidth - 1));
        sy = Math.max(0, Math.min(sy, videoEl.videoHeight - 1));
        sw = Math.max(1, Math.min(sw, videoEl.videoWidth - sx));
        sh = Math.max(1, Math.min(sh, videoEl.videoHeight - sy));

        var capCanvas = document.createElement('canvas');
        capCanvas.width = sw;
        capCanvas.height = sh;
        capCanvas.getContext('2d').drawImage(videoEl, sx, sy, sw, sh, 0, 0, sw, sh);

        // Manual rotate: preview default horizontal (0 derajat), OCR eksplisit via Proses.
        singleBase = capCanvas;
        singleDeg = 0;
        singleBusy = false;
        capCanvas.toBlob(function (blob) {
            if (captureBtn) captureBtn.disabled = false;
            if (!blob) {
                singleBase = null;
                showCaptureFeedback('Capture failed. Please try again.', true);
                return;
            }

            discardLastCapture();

            var now = new Date();
            var capDims = guideEffectiveDims();
            lastCapture = {
                blob: blob,
                url: URL.createObjectURL(blob),
                filename: buildCaptureFilename(now),
                templateId: selectedTemplate.id,
                templateName: selectedTemplate.name,
                widthCm: capDims ? capDims.cmW : selectedTemplate.width / 10,
                heightCm: capDims ? capDims.cmH : selectedTemplate.height / 10,
                widthPx: sw,
                heightPx: sh,
                filesize: blob.size,
                capturedAt: now
            };
            pendingOcrData = null;
            isOcrProcessing = false;

            if (resultImage) resultImage.src = lastCapture.url;
            renderResultInfo(lastCapture);
            singleUpdateRotateUI();

            flashEffect();
            enterResultState();

            // Manual rotate: belum OCR. User atur rotasi lalu tekan Proses OCR.
            hideOcrPanel();
            showResultFeedback('Atur rotasi lalu tekan Proses OCR.', false);
            if (saveCaptureBtn) saveCaptureBtn.disabled = true;
            if (saveBtnText) saveBtnText.textContent = 'Simpan Reference';
            singleSetRotateBusy(false);
        }, 'image/png');
    }

    function singleUpdateRotateUI() {
        if (resultRotateDegEl) resultRotateDegEl.textContent = degLabel(singleDeg);
    }

    function singleSetRotateBusy(busy) {
        singleBusy = !!busy;
        if (resultRotateLeftEl) resultRotateLeftEl.disabled = busy || !lastCapture;
        if (resultRotateRightEl) resultRotateRightEl.disabled = busy || !lastCapture;
        if (resultProcessOcrEl) resultProcessOcrEl.disabled = busy || !lastCapture;
    }

    function singleRotate(dir) {
        if (singleBusy || isOcrProcessing) return;
        if (!singleBase || !lastCapture || !lastCapture.blob) {
            showResultFeedback('Capture dulu sebelum rotate.', true);
            return;
        }
        singleSetRotateBusy(true);
        var nextDeg = ((singleDeg + dir) % 360 + 360) % 360;
        var rendered = renderBaseAtDeg(singleBase, nextDeg);
        if (!rendered) {
            singleSetRotateBusy(false);
            showResultFeedback('Rotate gagal. Coba lagi.', true);
            return;
        }
        var rw = rendered.width;
        var rh = rendered.height;
        var vertical = isVerticalDeg(nextDeg);
        rendered.toBlob(function (blob) {
            if (!blob) {
                singleSetRotateBusy(false);
                showResultFeedback('Rotate gagal. Coba lagi.', true);
                return;
            }
            var oldUrl = lastCapture.url;
            var capDims = null;
            try { capDims = guideEffectiveDims(); } catch (e) {}
            var baseW = capDims ? capDims.cmW : selectedTemplate.width / 10;
            var baseH = capDims ? capDims.cmH : selectedTemplate.height / 10;
            lastCapture.blob = blob;
            lastCapture.url = URL.createObjectURL(blob);
            lastCapture.widthCm = vertical ? baseH : baseW;
            lastCapture.heightCm = vertical ? baseW : baseH;
            lastCapture.widthPx = rw;
            lastCapture.heightPx = rh;
            lastCapture.filesize = blob.size;
            try { if (oldUrl) URL.revokeObjectURL(oldUrl); } catch (e) {}
            singleDeg = nextDeg;
            if (resultImage) resultImage.src = lastCapture.url;
            renderResultInfo(lastCapture);
            singleUpdateRotateUI();
            // Rotate setelah OCR = basi, wajib Proses ulang.
            pendingOcrData = null;
            hideOcrPanel();
            if (saveCaptureBtn) saveCaptureBtn.disabled = true;
            showResultFeedback('Rotasi ' + degLabel(singleDeg) + ' — tekan Proses OCR.', false);
            singleSetRotateBusy(false);
        }, 'image/png');
    }

    function singleProcessOcr() {
        if (!lastCapture || !lastCapture.blob) {
            showResultFeedback('Capture dulu sebelum OCR.', true);
            return;
        }
        if (isOcrProcessing) return;
        singleSetRotateBusy(true);
        showResultFeedback('Mengirim ke OCR...', false);
        triggerAutoOcr();
    }

    // ===== Choose Label Reference — Jalur B (pilih dari daftar, tanpa scan ulang) =====

    function dualPad(n) { return (n < 10 ? '0' : '') + n; }

    function dualMakeBaseStamp(d) {
        d = d || new Date();
        return '' + d.getFullYear() + dualPad(d.getMonth() + 1) + dualPad(d.getDate()) +
            '-' + dualPad(d.getHours()) + dualPad(d.getMinutes()) + dualPad(d.getSeconds());
    }

    function dualEnsureBaseStamp() {
        if (!dualBaseStamp) dualBaseStamp = dualMakeBaseStamp(new Date());
        return dualBaseStamp;
    }

    function dualBuildFilename(side) {
        var tpl = selectedTemplate ? selectedTemplate.id.toLowerCase() : 'x';
        return 'label-ref-' + tpl + '-' + dualEnsureBaseStamp() + '-' + side + '.png';
    }

    function dualBasisOf(filename) {
        if (!filename) return '';
        return String(filename).replace(/-(front|back)(-\d+)?(\.[a-z0-9]+)?$/i, '');
    }

    function dualGroupMineRows(rows) {
        var groups = {}, order = [];
        rows.forEach(function (r) {
            var fn = r.filename || '';
            var m = String(fn).match(/^(.*)-(front|back)(-\d+)?(\.[a-z0-9]+)?$/i);
            if (m) {
                var basis = m[1];
                var side = m[2].toLowerCase();
                var idx = m[3] ? parseInt(m[3].slice(1), 10) : 1;
                if (idx !== 2) idx = 1;
                if (!groups[basis]) { groups[basis] = { basis: basis, front1: null, front2: null, back1: null, back2: null, front: null, back: null, templateId: r.template_id }; order.push(basis); }
                if (side === 'front') {
                    if (idx === 2 && !groups[basis].front2) groups[basis].front2 = r;
                    else if (idx === 1 && !groups[basis].front1) groups[basis].front1 = r;
                    else if (!groups[basis].front1) groups[basis].front1 = r;
                    else if (!groups[basis].front2) groups[basis].front2 = r;
                }
                if (side === 'back') {
                    if (idx === 2 && !groups[basis].back2) groups[basis].back2 = r;
                    else if (idx === 1 && !groups[basis].back1) groups[basis].back1 = r;
                    else if (!groups[basis].back1) groups[basis].back1 = r;
                    else if (!groups[basis].back2) groups[basis].back2 = r;
                }
                groups[basis].front = groups[basis].front1 || groups[basis].front2 || null;
                groups[basis].back = groups[basis].back1 || groups[basis].back2 || null;
            } else {
                var key = '__single__' + r.id;
                groups[key] = { basis: key, front: r, back: null, single: true };
                order.push(key);
            }
        });
        return order.map(function (k) { return groups[k]; });
    }

    function dualCacheElements() {
        dualStep1El = document.getElementById('dual-step-1');
        dualStep2El = document.getElementById('dual-step-2');
        dualStepSaveEl = document.getElementById('dual-step-save');
        dualStageInfoEl = document.getElementById('dual-stage-info');
        dualSideBadgeEl = document.getElementById('dual-side-badge');
        dualFrontMiniEl = document.getElementById('dual-front-mini');
        dualFrontThumbEl = document.getElementById('dual-front-thumb');
        dualFrontMetaEl = document.getElementById('dual-front-meta');
        dualFrontOcrEl = document.getElementById('dual-front-ocr');
        dualRetakeFrontEl = document.getElementById('dual-retake-front');
        dualNextToBackEl = document.getElementById('dual-next-to-back');
        dualMiniFeedbackEl = document.getElementById('dual-mini-feedback');
        dualResultCardEl = document.getElementById('dual-result-card');
        dualFrontImgEl = document.getElementById('dual-result-front-img');
        dualBackImgEl = document.getElementById('dual-result-back-img');
        dualFrontTextEl = document.getElementById('dual-result-front-text');
        dualBackTextEl = document.getElementById('dual-result-back-text');
        dualFrontBadgeEl = document.getElementById('dual-front-badge');
        dualBackBadgeEl = document.getElementById('dual-back-badge');
        dualResultMetaEl = document.getElementById('dual-result-meta');
        dualSaveBtnEl = document.getElementById('dual-save-btn');
        dualSaveBtnTextEl = document.getElementById('dual-save-btn-text');
        dualSaveFeedbackEl = document.getElementById('dual-save-feedback');
        dualRetakeFront2El = document.getElementById('dual-retake-front-2');
        dualRetakeBack2El = document.getElementById('dual-retake-back-2');
        captureBtnTextEl = document.getElementById('capture-btn-text');
        successTitleEl = document.getElementById('success-title');
        successDescEl = document.getElementById('success-desc');
        dualBackLoadingEl = document.getElementById('dual-back-loading');
        dualBackThumbEl = document.getElementById('dual-back-thumb');
        dualBackLoadingFileEl = document.getElementById('dual-back-loading-file');
        dualBackLoadingSecsEl = document.getElementById('dual-back-loading-seconds');
        dualCacheRotateElements();
    }

    function dualCacheRotateElements() {
        dualRotateFrontLeftEl = document.getElementById('dual-rotate-front-left');
        dualRotateFrontRightEl = document.getElementById('dual-rotate-front-right');
        dualRotateFrontDegEl = document.getElementById('dual-rotate-front-deg');
        dualProcessFrontEl = document.getElementById('dual-process-front');
        dualBackMiniEl = document.getElementById('dual-back-mini');
        dualBackPreviewEl = document.getElementById('dual-back-preview');
        dualBackMetaEl = document.getElementById('dual-back-meta');
        dualBackOcrEl = document.getElementById('dual-back-ocr');
        dualRotateBackLeftEl = document.getElementById('dual-rotate-back-left');
        dualRotateBackRightEl = document.getElementById('dual-rotate-back-right');
        dualRotateBackDegEl = document.getElementById('dual-rotate-back-deg');
        dualProcessBackEl = document.getElementById('dual-process-back');
        dualRetakeBackEl = document.getElementById('dual-retake-back');
        dualBackFeedbackEl = document.getElementById('dual-back-feedback');
        dualNextToResultEl = document.getElementById('dual-next-to-result');
        dualRotateResFrontLeftEl = document.getElementById('dual-rotate-result-front-left');
        dualRotateResFrontRightEl = document.getElementById('dual-rotate-result-front-right');
        dualRotateResFrontDegEl = document.getElementById('dual-rotate-result-front-deg');
        dualReprocessFrontEl = document.getElementById('dual-reprocess-front');
        dualRotateResBackLeftEl = document.getElementById('dual-rotate-result-back-left');
        dualRotateResBackRightEl = document.getElementById('dual-rotate-result-back-right');
        dualRotateResBackDegEl = document.getElementById('dual-rotate-result-back-deg');
        dualReprocessBackEl = document.getElementById('dual-reprocess-back');
        resultRotateLeftEl = document.getElementById('result-rotate-left');
        resultRotateRightEl = document.getElementById('result-rotate-right');
        resultRotateDegEl = document.getElementById('result-rotate-deg');
        resultProcessOcrEl = document.getElementById('result-process-ocr');
        dualCacheSplitElements();
    }

    var frontTabIdx = 1, backTabIdx = 1;

    function dualCacheSplitElements() {
        frontAdjustEl = document.getElementById('dual-front-adjust');
        backAdjustEl = document.getElementById('dual-back-adjust');
        frontSingleEl = document.getElementById('dual-front-single');
        backSingleEl = document.getElementById('dual-back-single');
        frontReviewImgEl = document.getElementById('dual-front-split-review');
        backReviewImgEl = document.getElementById('dual-back-split-review');
        frontTab1El = document.getElementById('dual-front-tab-1');
        frontTab2El = document.getElementById('dual-front-tab-2');
        frontOcrViewEl = document.getElementById('dual-front-ocr-view');
        // FIX-OCR-COLLAPSE (CP-074) — cache toggle/body collapsible, null-safe bila ID hilang.
        frontOcrToggleEl = document.getElementById('dual-front-ocr-toggle');
        frontOcrBodyEl = document.getElementById('dual-front-ocr-body');
        frontCheck1El = document.getElementById('dual-front-check-1');
        frontCheck2El = document.getElementById('dual-front-check-2');
        backTab1El = document.getElementById('dual-back-tab-1');
        backTab2El = document.getElementById('dual-back-tab-2');
        backOcrViewEl = document.getElementById('dual-back-ocr-view');
        // FIX-OCR-COLLAPSE (CP-074) — cache toggle/body collapsible, null-safe bila ID hilang.
        backOcrToggleEl = document.getElementById('dual-back-ocr-toggle');
        backOcrBodyEl = document.getElementById('dual-back-ocr-body');
        backCheck1El = document.getElementById('dual-back-check-1');
        backCheck2El = document.getElementById('dual-back-check-2');
        frontSplitWrapEl = document.getElementById('dual-front-split');
        frontSplitImg1El = document.getElementById('dual-front-split-img-1');
        frontSplitMeta1El = document.getElementById('dual-front-split-meta-1');
        frontSplitBadge1El = document.getElementById('dual-front-split-badge-1');
        frontSplitOcr1El = document.getElementById('dual-front-split-ocr-1');
        frontSplitImg2El = document.getElementById('dual-front-split-img-2');
        frontSplitMeta2El = document.getElementById('dual-front-split-meta-2');
        frontSplitBadge2El = document.getElementById('dual-front-split-badge-2');
        frontSplitOcr2El = document.getElementById('dual-front-split-ocr-2');
        backSplitWrapEl = document.getElementById('dual-back-split');
        backSplitImg1El = document.getElementById('dual-back-split-img-1');
        backSplitMeta1El = document.getElementById('dual-back-split-meta-1');
        backSplitBadge1El = document.getElementById('dual-back-split-badge-1');
        backSplitOcr1El = document.getElementById('dual-back-split-ocr-1');
        backSplitImg2El = document.getElementById('dual-back-split-img-2');
        backSplitMeta2El = document.getElementById('dual-back-split-meta-2');
        backSplitBadge2El = document.getElementById('dual-back-split-badge-2');
        backSplitOcr2El = document.getElementById('dual-back-split-ocr-2');
        resFrontImg1El = document.getElementById('dual-res-front-img-1');
        resFrontBadge1El = document.getElementById('dual-res-front-badge-1');
        resFrontText1El = document.getElementById('dual-res-front-text-1');
        resFrontImg2El = document.getElementById('dual-res-front-img-2');
        resFrontBadge2El = document.getElementById('dual-res-front-badge-2');
        resFrontText2El = document.getElementById('dual-res-front-text-2');
        resBackImg1El = document.getElementById('dual-res-back-img-1');
        resBackBadge1El = document.getElementById('dual-res-back-badge-1');
        resBackText1El = document.getElementById('dual-res-back-text-1');
        resBackImg2El = document.getElementById('dual-res-back-img-2');
        resBackBadge2El = document.getElementById('dual-res-back-badge-2');
        resBackText2El = document.getElementById('dual-res-back-text-2');
    }

    // Belah sisi PANJANG canvas final tepat di tengah (tanpa hardcode sumbu).
    // Return { c1, c2, horizontal } atau null bila gagal. Overlap 0 px.
    function splitLongSide(finalCanvas) {
        if (!finalCanvas || !finalCanvas.width || !finalCanvas.height) return null;
        try {
            var w = finalCanvas.width, h = finalCanvas.height;
            var horizontal = w >= h;
            var c1 = document.createElement('canvas');
            var c2 = document.createElement('canvas');
            if (horizontal) {
                var w1 = Math.floor(w / 2);
                c1.width = w1; c1.height = h;
                c2.width = w - w1; c2.height = h;
                c1.getContext('2d').drawImage(finalCanvas, 0, 0, w1, h, 0, 0, w1, h);
                c2.getContext('2d').drawImage(finalCanvas, w1, 0, w - w1, h, 0, 0, w - w1, h);
            } else {
                var h1 = Math.floor(h / 2);
                c1.width = w; c1.height = h1;
                c2.width = w; c2.height = h - h1;
                c1.getContext('2d').drawImage(finalCanvas, 0, 0, w, h1, 0, 0, w, h1);
                c2.getContext('2d').drawImage(finalCanvas, 0, h1, w, h - h1, 0, 0, w, h - h1);
            }
            return { c1: c1, c2: c2, horizontal: horizontal };
        } catch (e) {
            return null;
        }
    }

    function dualBuildSplitFilename(side, idx) {
        var tpl = selectedTemplate ? selectedTemplate.id.toLowerCase() : 'x';
        return 'label-ref-' + tpl + '-' + dualEnsureBaseStamp() + '-' + side + '-' + idx + '.png';
    }

    function splitGetCap(side, idx) {
        if (side === 'front') return idx === 1 ? splitFrontA : splitFrontB;
        return idx === 1 ? splitBackA : splitBackB;
    }

    function splitSetCap(side, idx, cap) {
        if (side === 'front') { if (idx === 1) splitFrontA = cap; else splitFrontB = cap; }
        else { if (idx === 1) splitBackA = cap; else splitBackB = cap; }
    }

    function splitGetOcr(side, idx) {
        if (side === 'front') return idx === 1 ? ocrFront1 : ocrFront2;
        return idx === 1 ? ocrBack1 : ocrBack2;
    }

    function splitSetOcr(side, idx, ocr) {
        if (side === 'front') { if (idx === 1) ocrFront1 = ocr; else ocrFront2 = ocr; }
        else { if (idx === 1) ocrBack1 = ocr; else ocrBack2 = ocr; }
    }

    function splitGetDone(side, idx) {
        if (side === 'front') return idx === 1 ? doneFront1 : doneFront2;
        return idx === 1 ? doneBack1 : doneBack2;
    }

    function splitSetDone(side, idx, done) {
        if (side === 'front') { if (idx === 1) doneFront1 = !!done; else doneFront2 = !!done; }
        else { if (idx === 1) doneBack1 = !!done; else doneBack2 = !!done; }
        dualSyncSplitAggregates();
    }

    function dualSyncSplitAggregates() {
        dualFrontDone = doneFront1 && doneFront2;
        dualBackDone = doneBack1 && doneBack2;
    }

    function splitGetAbort(side, idx) {
        if (side === 'front') return idx === 1 ? splitAbortFront1 : splitAbortFront2;
        return idx === 1 ? splitAbortBack1 : splitAbortBack2;
    }

    function splitSetAbort(side, idx, ac) {
        if (side === 'front') { if (idx === 1) splitAbortFront1 = ac; else splitAbortFront2 = ac; }
        else { if (idx === 1) splitAbortBack1 = ac; else splitAbortBack2 = ac; }
    }

    function splitAbortAll(side) {
        [1, 2].forEach(function (idx) {
            var ac = splitGetAbort(side, idx);
            if (ac) { try { ac.abort(); } catch (e) {} }
            splitSetAbort(side, idx, null);
        });
    }

    function splitPanelEls(side, idx) {
        var mini = (side === 'front')
            ? (idx === 1
                ? { img: frontSplitImg1El, meta: frontSplitMeta1El, badge: frontSplitBadge1El, ocr: frontSplitOcr1El }
                : { img: frontSplitImg2El, meta: frontSplitMeta2El, badge: frontSplitBadge2El, ocr: frontSplitOcr2El })
            : (idx === 1
                ? { img: backSplitImg1El, meta: backSplitMeta1El, badge: backSplitBadge1El, ocr: backSplitOcr1El }
                : { img: backSplitImg2El, meta: backSplitMeta2El, badge: backSplitBadge2El, ocr: backSplitOcr2El });
        var res = (side === 'front')
            ? (idx === 1
                ? { img: resFrontImg1El, badge: resFrontBadge1El, text: resFrontText1El }
                : { img: resFrontImg2El, badge: resFrontBadge2El, text: resFrontText2El })
            : (idx === 1
                ? { img: resBackImg1El, badge: resBackBadge1El, text: resBackText1El }
                : { img: resBackImg2El, badge: resBackBadge2El, text: resBackText2El });
        return { mini: mini, res: res };
    }

    function splitRevokeSide(side) {
        [1, 2].forEach(function (idx) {
            var cap = splitGetCap(side, idx);
            if (cap && cap.url) { try { URL.revokeObjectURL(cap.url); } catch (e) {} }
            splitSetCap(side, idx, null);
            splitSetOcr(side, idx, null);
            splitSetDone(side, idx, false);
        });
    }

    function splitSetAdjustVisible(side, visible) {
        var el = side === 'front' ? frontAdjustEl : backAdjustEl;
        if (!el) return;
        if (visible) el.classList.remove('hidden');
        else el.classList.add('hidden');
    }

    function splitResetPanels(side) {
        splitSetAdjustVisible(side, true);
        splitSetTripleVisible(side, false);
        if (side === 'front') { frontTabIdx = 1; } else { backTabIdx = 1; }
        // FIX-OCR-COLLAPSE (CP-074) — retake/reset kembali tertutup default.
        try { dualOcrToggleSet(side, false); } catch (e) {}
        setSplitTab(side, 1);
        splitSetCheck(side, 1, false);
        splitSetCheck(side, 2, false);
        refreshSplitView(side);
        [1, 2].forEach(function (idx) {
            var els = splitPanelEls(side, idx);
            if (els.mini.img) els.mini.img.removeAttribute('src');
            if (els.mini.meta) els.mini.meta.textContent = '-';
            if (els.mini.ocr) els.mini.ocr.textContent = 'Menunggu split...';
            if (els.res.img) els.res.img.removeAttribute('src');
            if (els.res.text) els.res.text.textContent = '-';
            dualSetSplitBadge(els.mini.badge, 'idle', 'Label ' + idx + ' · idle');
            dualSetSplitBadge(els.res.badge, 'idle', 'Label ' + idx + ' · idle');
        });
        var wrap = side === 'front' ? frontSplitWrapEl : backSplitWrapEl;
        if (wrap) wrap.classList.add('hidden');
    }

    function dualSetSplitBadge(el, state, label) {
        if (!el) return;
        el.textContent = label || state;
        var base = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border ';
        if (state === 'success') el.className = base + 'bg-white text-[#212529] border-emerald-300';
        else if (state === 'failed') el.className = base + 'bg-[#E04438]/10 text-[#E04438] border-[#E04438]/30';
        else if (state === 'processing') el.className = base + 'bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
        else el.className = base + 'bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
    }

    function splitUpdateMeta(sides) {
        sides = sides || ['front', 'back'];
        sides.forEach(function (side) {
            [1, 2].forEach(function (idx) {
                var cap = splitGetCap(side, idx);
                var els = splitPanelEls(side, idx);
                if (cap && els.mini.meta && selectedTemplate) {
                    els.mini.meta.textContent = selectedTemplate.id + ' - ' + selectedTemplate.name + ' - ' +
                        cap.widthCm + ' x ' + cap.heightCm + ' cm - ' + cap.filename;
                }
                if (cap && els.mini.img && !els.mini.img.getAttribute('src')) els.mini.img.src = cap.url;
            });
        });
    }

    // Box OCR tunggal bertab: 1 box menampilkan teks Label 1 / Label 2 sesuai tombol.
    function splitTabEls(side) {
        if (side === 'front') return { t1: frontTab1El, t2: frontTab2El, view: frontOcrViewEl };
        return { t1: backTab1El, t2: backTab2El, view: backOcrViewEl };
    }

    function splitActiveTab(side) { return side === 'front' ? frontTabIdx : backTabIdx; }

    function setSplitTab(side, idx) {
        if (side === 'front') frontTabIdx = idx === 2 ? 2 : 1;
        else backTabIdx = idx === 2 ? 2 : 1;
        var els = splitTabEls(side);
        var on = 'px-3.5 py-1.5 rounded-full text-xs font-semibold border bg-[#0284C7] text-white border-[#0284C7] transition focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed';
        var off = 'px-3.5 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed';
        var active = splitActiveTab(side);
        if (els.t1) els.t1.className = active === 1 ? on : off;
        if (els.t2) els.t2.className = active === 2 ? on : off;
        refreshSplitView(side);
    }

    function refreshSplitView(side) {
        var els = splitTabEls(side);
        if (!els.view) return;
        var idx = splitActiveTab(side);
        var store = splitPanelEls(side, idx).mini.ocr;
        var txt = store && store.textContent ? store.textContent : '';
        if (!txt || txt === 'Menunggu split...') {
            els.view.textContent = 'Pilih Label 1 / Label 2 untuk melihat hasil ekstraksi.';
        } else {
            els.view.textContent = txt;
        }
    }

    // FIX-OCR-COLLAPSE (CP-074) — collapsible Hasil ekstraksi teks, default tertutup.
    // Tab render di atas SENGAJA tidak memaksa show body (P3 planning).
    function dualOcrToggleEls(side) {
        if (side === 'front') return { toggle: frontOcrToggleEl, body: frontOcrBodyEl };
        return { toggle: backOcrToggleEl, body: backOcrBodyEl };
    }
    function dualOcrToggleSet(side, open) {
        var els = dualOcrToggleEls(side);
        if (!els.toggle || !els.body) return;
        if (open) els.body.classList.remove('hidden');
        else els.body.classList.add('hidden');
        try { els.toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); } catch (e) {}
        var label = null;
        try { label = els.toggle.querySelector('[data-ocr-toggle-label]'); } catch (e) { label = null; }
        if (label) label.textContent = open ? 'Sembunyikan' : 'Lihat teks';
        var chev = null;
        try { chev = els.toggle.querySelector('svg'); } catch (e) { chev = null; }
        if (chev) { if (open) chev.classList.add('rotate-180'); else chev.classList.remove('rotate-180'); }
    }
    function dualOcrToggleWireOne(side) {
        var els = dualOcrToggleEls(side);
        if (!els.toggle || !els.body) return;
        if (els.toggle.dataset && els.toggle.dataset.wired) return;
        if (els.toggle.dataset) els.toggle.dataset.wired = '1';
        els.toggle.addEventListener('click', function (e) {
            if (e) { try { e.preventDefault(); } catch (err) {} }
            var isHidden = els.body.classList.contains('hidden');
            dualOcrToggleSet(side, isHidden);
        });
    }
    function dualOcrToggleWire() {
        dualOcrToggleWireOne('front');
        dualOcrToggleWireOne('back');
    }

    function splitCheckEl(side, idx) {
        if (side === 'front') return idx === 1 ? frontCheck1El : frontCheck2El;
        return idx === 1 ? backCheck1El : backCheck2El;
    }

    function splitSetCheck(side, idx, show) {
        var el = splitCheckEl(side, idx);
        if (!el) return;
        if (show) el.classList.remove('hidden');
        else el.classList.add('hidden');
    }

    // Toggle blok single (review + adjust) vs triple (review + Label 1 + Label 2 + box tab).
    function splitSetTripleVisible(side, triple) {
        var single = side === 'front' ? frontSingleEl : backSingleEl;
        var wrap = side === 'front' ? frontSplitWrapEl : backSplitWrapEl;
        if (single) { if (triple) single.classList.add('hidden'); else single.classList.remove('hidden'); }
        if (wrap) { if (triple) wrap.classList.remove('hidden'); else wrap.classList.add('hidden'); }
    }

    function dualGetBase(side) { return side === 'front' ? dualBaseFront : dualBaseBack; }
    function dualGetDeg(side) { return side === 'front' ? dualDegFront : dualDegBack; }
    function dualSetDeg(side, deg) {
        var d = ((deg % 360) + 360) % 360;
        if (side === 'front') dualDegFront = d; else dualDegBack = d;
        dualUpdateDegLabels();
    }
    function dualGetBusy(side) { return side === 'front' ? dualBusyFront : dualBusyBack; }
    function dualSetBusy(side, busy) {
        if (side === 'front') dualBusyFront = !!busy; else dualBusyBack = !!busy;
    }

    function dualUpdateDegLabels() {
        if (dualRotateFrontDegEl) dualRotateFrontDegEl.textContent = degLabel(dualDegFront);
        if (dualRotateBackDegEl) dualRotateBackDegEl.textContent = degLabel(dualDegBack);
        if (dualRotateResFrontDegEl) dualRotateResFrontDegEl.textContent = degLabel(dualDegFront);
        if (dualRotateResBackDegEl) dualRotateResBackDegEl.textContent = degLabel(dualDegBack);
    }

    function dualSetRotateUI(side, enabled) {
        var dis = !enabled;
        if (side === 'front') {
            if (dualRotateFrontLeftEl) dualRotateFrontLeftEl.disabled = dis;
            if (dualRotateFrontRightEl) dualRotateFrontRightEl.disabled = dis;
            if (dualRotateResFrontLeftEl) dualRotateResFrontLeftEl.disabled = dis;
            if (dualRotateResFrontRightEl) dualRotateResFrontRightEl.disabled = dis;
        } else {
            if (dualRotateBackLeftEl) dualRotateBackLeftEl.disabled = dis;
            if (dualRotateBackRightEl) dualRotateBackRightEl.disabled = dis;
            if (dualRotateResBackLeftEl) dualRotateResBackLeftEl.disabled = dis;
            if (dualRotateResBackRightEl) dualRotateResBackRightEl.disabled = dis;
        }
    }

    function dualSetProcessUI(side, enabled) {
        var dis = !enabled;
        if (side === 'front') {
            if (dualProcessFrontEl) dualProcessFrontEl.disabled = dis;
            if (dualReprocessFrontEl) dualReprocessFrontEl.disabled = dis;
        } else {
            if (dualProcessBackEl) dualProcessBackEl.disabled = dis;
            if (dualReprocessBackEl) dualReprocessBackEl.disabled = dis;
        }
    }

    function dualShowBackMiniFeedback(msg, isError) {
        if (!dualBackFeedbackEl) return;
        dualBackFeedbackEl.textContent = msg || '';
        dualBackFeedbackEl.className = 'text-xs font-medium ' + (isError ? 'text-[#E04438]' : 'text-[#6C757D]');
    }

    function dualShowBackMini(cap) {
        try { dualHideBackLoading(); } catch (e) {}
        if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
        if (dualFrontMiniEl) dualFrontMiniEl.classList.add('hidden');
        if (dualBackMiniEl) dualBackMiniEl.classList.remove('hidden');
        if (cap) {
            if (dualBackPreviewEl) dualBackPreviewEl.src = cap.url;
            dualUpdateBackMini();
        }
        if (dualBackMiniEl && dualBackMiniEl.scrollIntoView) {
            try { dualBackMiniEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
    }

    function dualHideBackMini() {
        if (dualBackMiniEl) dualBackMiniEl.classList.add('hidden');
    }

    function dualUpdateBackMini() {
        if (!dualBackMiniEl || !lastCaptureBack) return;
        if (dualBackPreviewEl) dualBackPreviewEl.src = lastCaptureBack.url;
        if (backReviewImgEl && lastCaptureBack.url) backReviewImgEl.src = lastCaptureBack.url;
        if (dualBackMetaEl && selectedTemplate) {
            var sizeTxt = lastCaptureBack.widthCm + ' x ' + lastCaptureBack.heightCm + ' cm';
            dualBackMetaEl.textContent = selectedTemplate.id + ' - ' + selectedTemplate.name + ' - ' + sizeTxt + ' - ' + lastCaptureBack.filename;
        }
        dualUpdateDegLabels();
    }

    function dualSyncResultImages() {
        if (dualFrontImgEl && lastCaptureFront) dualFrontImgEl.src = lastCaptureFront.url;
        if (dualBackImgEl && lastCaptureBack) dualBackImgEl.src = lastCaptureBack.url;
        ['front', 'back'].forEach(function (side) {
            [1, 2].forEach(function (idx) {
                var cap = splitGetCap(side, idx);
                var els = splitPanelEls(side, idx);
                if (cap && els.res.img) els.res.img.src = cap.url;
            });
        });
        dualUpdateDegLabels();
    }

    function dualInvalidateSide(side) {
        // Rotate ulang = split + OCR basi. Bersihkan belahan, kembali ke preview tunggal.
        splitAbortAll(side);
        splitRevokeSide(side);
        splitResetPanels(side);
        dualSyncSplitAggregates();
        if (side === 'front') {
            pendingOcrFront = null; dualFrontDone = false;
            dualSetBadge(dualFrontBadgeEl, 'idle', 'idle');
            if (dualFrontOcrEl) dualFrontOcrEl.textContent = 'Belum OCR — atur rotasi lalu tekan Proses.';
            if (dualFrontTextEl) dualFrontTextEl.textContent = 'Belum OCR — atur rotasi lalu tekan Proses.';
            dualShowMiniFeedback('Rotasi diubah — tekan Simpan.', false);
            dualSetStageInfo('front');
        } else {
            pendingOcrBack = null; dualBackDone = false;
            dualSetBadge(dualBackBadgeEl, 'idle', 'idle');
            if (dualBackOcrEl) dualBackOcrEl.textContent = 'Belum OCR — atur rotasi lalu tekan Proses.';
            if (dualBackTextEl) dualBackTextEl.textContent = 'Belum OCR — atur rotasi lalu tekan Proses.';
            dualShowBackMiniFeedback('Rotasi diubah — tekan Simpan.', false);
            dualShowSaveFeedback('Back diubah — proses ulang sebelum simpan.', true);
        }
        dualSetProcessUI(side, true);
        dualUpdateSaveState();
    }

    function dualRotateSide(side, dir) {
        if (dualGetBusy(side)) return;
        var base = dualGetBase(side);
        var cap = side === 'front' ? lastCaptureFront : lastCaptureBack;
        if (!base || !cap) {
            if (side === 'front') dualShowMiniFeedback('Capture dulu sebelum rotate.', true);
            else dualShowBackMiniFeedback('Capture dulu sebelum rotate.', true);
            return;
        }
        dualSetBusy(side, true);
        dualSetRotateUI(side, false);
        var nextDeg = ((dualGetDeg(side) + dir) % 360 + 360) % 360;
        var rendered = renderBaseAtDeg(base, nextDeg);
        if (!rendered) {
            dualSetBusy(side, false);
            dualSetRotateUI(side, true);
            if (side === 'front') dualShowMiniFeedback('Rotate gagal. Coba lagi.', true);
            else dualShowBackMiniFeedback('Rotate gagal. Coba lagi.', true);
            return;
        }
        var rw = rendered.width;
        var rh = rendered.height;
        var vertical = isVerticalDeg(nextDeg);
        rendered.toBlob(function (blob) {
            dualSetBusy(side, false);
            if (!blob) {
                dualSetRotateUI(side, true);
                if (side === 'front') dualShowMiniFeedback('Rotate gagal. Coba lagi.', true);
                else dualShowBackMiniFeedback('Rotate gagal. Coba lagi.', true);
                return;
            }
            var oldUrl = cap.url;
            var capDims = null;
            try { capDims = guideEffectiveDims(); } catch (e) {}
            var baseW = capDims ? capDims.cmW : selectedTemplate.width / 10;
            var baseH = capDims ? capDims.cmH : selectedTemplate.height / 10;
            cap.blob = blob;
            cap.url = URL.createObjectURL(blob);
            cap.widthCm = vertical ? baseH : baseW;
            cap.heightCm = vertical ? baseW : baseH;
            cap.widthPx = rw;
            cap.heightPx = rh;
            cap.filesize = blob.size;
            try { if (oldUrl) URL.revokeObjectURL(oldUrl); } catch (e) {}
            dualSetDeg(side, nextDeg);
            if (side === 'front') {
                if (dualFrontThumbEl) dualFrontThumbEl.src = cap.url;
                dualUpdateFrontMini();
            } else {
                dualUpdateBackMini();
            }
            dualSyncResultImages();
            dualSetRotateUI(side, true);
            // Setiap rotate membuat OCR basi — wajib Proses ulang.
            dualInvalidateSide(side);
            if (side === 'front') dualShowMiniFeedback('Rotasi ' + degLabel(nextDeg) + ' — tekan Simpan.', false);
            else dualShowBackMiniFeedback('Rotasi ' + degLabel(nextDeg) + ' — tekan Simpan.', false);
        }, 'image/png');
    }

    // Simpan sisi = kirim citra final (sesuai rotasi) ke detect-regions (OpenCV), lalu OCR sequential 1 lalu 2.
    function dualProcessSide(side) {
        var cap = side === 'front' ? lastCaptureFront : lastCaptureBack;
        var base = dualGetBase(side);
        if (!cap || !base) {
            if (side === 'front') dualShowMiniFeedback('Capture dulu sebelum proses.', true);
            else dualShowBackMiniFeedback('Capture dulu sebelum proses.', true);
            return;
        }
        if (dualGetBusy(side)) return;
        dualSetBusy(side, true);
        dualSetRotateUI(side, false);
        dualSetProcessUI(side, false);

        if (side === 'front') {
            dualShowMiniFeedback('Mendeteksi label & meluruskan otomatis (OpenCV)...', false);
            dualSetStageInfo('front-ocr');
        } else {
            dualShowBackMiniFeedback('Mendeteksi label & meluruskan otomatis (OpenCV)...', false);
            dualSetStageInfo('back-ocr');
        }

        var deg = dualGetDeg(side);
        var finalCanvas = renderBaseAtDeg(base, deg);
        if (!finalCanvas) {
            dualSetBusy(side, false);
            dualSetRotateUI(side, true);
            dualSetProcessUI(side, true);
            if (side === 'front') dualShowMiniFeedback('Proses gagal. Coba lagi.', true);
            else dualShowBackMiniFeedback('Proses gagal. Coba lagi.', true);
            return;
        }

        finalCanvas.toBlob(function (blob) {
            if (!blob) {
                dualSetBusy(side, false);
                dualSetRotateUI(side, true);
                dualSetProcessUI(side, true);
                if (side === 'front') dualShowMiniFeedback('Gagal mengolah gambar.', true);
                else dualShowBackMiniFeedback('Gagal mengolah gambar.', true);
                return;
            }

            var fd = new FormData();
            fd.append('image', blob, 'process-' + side + '.png');
            var token = '';
            try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
            var headers = token ? { 'Authorization': 'Bearer ' + token } : {};

            fetch('/api/references/detect-regions', {
                method: 'POST',
                headers: headers,
                body: fd
            })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success || !data.slots || data.slots.length < 2) {
                    var msg = (data && data.message) ? data.message : 'Gagal mendeteksi label.';
                    if (side === 'front') dualShowMiniFeedback(msg + ' Coba atur rotasi atau retake.', true);
                    else dualShowBackMiniFeedback(msg + ' Coba atur rotasi atau retake.', true);
                    dualSetBusy(side, false);
                    dualSetRotateUI(side, true);
                    dualSetProcessUI(side, true);
                    return;
                }
                applyDetectedSlots(side, blob, data);
            })
            .catch(function (err) {
                console.error('[AUTO-DETECT] Deteksi gagal:', err);
                if (side === 'front') dualShowMiniFeedback('Gagal deteksi otomatis. Periksa koneksi backend.', true);
                else dualShowBackMiniFeedback('Gagal deteksi otomatis. Periksa koneksi backend.', true);
                dualSetBusy(side, false);
                dualSetRotateUI(side, true);
                dualSetProcessUI(side, true);
            });
        }, 'image/png');
    }

    function dualPill(el, mode) {
        if (!el) return;
        var base = 'px-3 py-1.5 rounded-full border ';
        if (mode === 'active') el.className = base + 'bg-[#0284C7] text-white border-[#0284C7]';
        else if (mode === 'done') el.className = base + 'bg-[#F8F9FA] text-[#212529] border-emerald-300';
        else el.className = base + 'bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
    }

    function dualSetStepper() {
        if (dualSide === 'front') { dualPill(dualStep1El, 'active'); dualPill(dualStep2El, 'idle'); dualPill(dualStepSaveEl, 'idle'); }
        else if (dualSide === 'back') { dualPill(dualStep1El, 'done'); dualPill(dualStep2El, 'active'); dualPill(dualStepSaveEl, 'idle'); }
        else { dualPill(dualStep1El, 'done'); dualPill(dualStep2El, 'done'); dualPill(dualStepSaveEl, 'active'); }
        if (dualStep1El) dualStep1El.textContent = dualFrontDone ? '1 - Front (Depan) done' : '1 - Front (Depan)';
        if (dualStep2El) dualStep2El.textContent = dualBackDone ? '2 - Back (Belakang) done' : '2 - Back (Belakang)';
    }

    function dualSetStageInfo(kind) {
        if (!dualStageInfoEl) return;
        var html = '';
        if (kind === 'front-ocr') html = 'Memproses OCR <b>Sisi Depan</b>... tunggu hingga selesai.';
        else if (kind === 'front-ok') html = '<b>Sisi Depan tersimpan sementara</b> - tekan <b>Lanjut ke Capture 2</b> untuk memindai <b>Sisi Belakang</b>.';
        else if (kind === 'back') html = 'Tahap 2/2 - Posisikan <b>SISI BELAKANG (Back)</b> di dalam bingkai, lalu tekan <b>Capture Label 2</b>.';
        else if (kind === 'back-ocr') html = 'Memproses OCR <b>Sisi Belakang</b>... tunggu hingga selesai.';
        else if (kind === 'back-ok') html = '<b>Sisi Belakang tersimpan sementara</b> - tekan <b>Next</b> untuk melihat <b>Capture Result (2 sisi)</b>.';
        else if (kind === 'dual-ok') html = '<b>Kedua sisi siap</b> - periksa 2 hasil di bawah, lalu tekan <b>Simpan 2 Label</b>.';
        else if (kind === 'saving') html = '<b>Menyimpan 2 label...</b> jangan tutup halaman.';
        else html = 'Tahap 1/2 - Posisikan <b>SISI DEPAN (Front)</b> di dalam bingkai, lalu tekan <b>Capture Label</b>.';
        dualStageInfoEl.innerHTML = html;
    }

    function dualSetBadge(el, state, label) {
        if (!el) return;
        el.textContent = label || state;
        var base = 'px-2.5 py-0.5 rounded-full text-xs font-semibold border ';
        if (state === 'success') el.className = base + 'bg-white text-[#212529] border-emerald-300';
        else if (state === 'failed') el.className = base + 'bg-[#E04438]/10 text-[#E04438] border-[#E04438]/30';
        else el.className = base + 'bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
    }

    function dualApplyStageToCamera() {
        if (captureBtn) {
            if (dualSide === 'back') { captureBtn.setAttribute('data-side', 'back'); if (captureBtnTextEl) captureBtnTextEl.textContent = 'Capture Label 2'; }
            else { captureBtn.setAttribute('data-side', 'front'); if (captureBtnTextEl) captureBtnTextEl.textContent = 'Capture Label'; }
        }
        if (cameraLabelEl) {
            if (dualSide === 'back') cameraLabelEl.textContent = 'Kamera aktif - Sisi Belakang (Back)';
            else if (dualSide === 'done') cameraLabelEl.textContent = 'Kedua sisi selesai';
            else cameraLabelEl.textContent = 'Kamera aktif - Sisi Depan (Front)';
        }
        if (dualSideBadgeEl) dualSideBadgeEl.textContent = (dualSide === 'back') ? 'BACK' : 'FRONT';
        dualSetStepper();
    }

    function dualRevokeSide(side) {
        var c = (side === 'front') ? lastCaptureFront : lastCaptureBack;
        if (c && c.url) { try { URL.revokeObjectURL(c.url); } catch (e) {} }
        if (side === 'front') lastCaptureFront = null; else lastCaptureBack = null;
    }

    function dualResetAll() {
        dualSide = 'front';
        dualFrontDone = false; dualBackDone = false;
        pendingOcrFront = null; pendingOcrBack = null;
        dualRefIdFront = null; dualRefIdBack = null;
        try { if (dualOcrAbortFront) dualOcrAbortFront.abort(); } catch (e) {}
        try { if (dualOcrAbortBack) dualOcrAbortBack.abort(); } catch (e) {}
        dualOcrAbortFront = null; dualOcrAbortBack = null;
        if (lastCaptureFront && lastCaptureFront.url) { try { URL.revokeObjectURL(lastCaptureFront.url); } catch (e) {} }
        if (lastCaptureBack && lastCaptureBack.url) { try { URL.revokeObjectURL(lastCaptureBack.url); } catch (e) {} }
        lastCaptureFront = null; lastCaptureBack = null;
        try { splitAbortAll('front'); splitAbortAll('back'); } catch (e) {}
        splitRevokeSide('front'); splitRevokeSide('back');
        splitResetPanels('front'); splitResetPanels('back');
        ocrFront1 = null; ocrFront2 = null; ocrBack1 = null; ocrBack2 = null;
        refFront1 = null; refFront2 = null; refBack1 = null; refBack2 = null;
        dualSyncSplitAggregates();
        dualBaseFront = null; dualBaseBack = null;
        dualDegFront = 0; dualDegBack = 0; dualBusyFront = false; dualBusyBack = false;
        dualBaseStamp = null;
        if (dualFrontMiniEl) dualFrontMiniEl.classList.add('hidden');
        if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
        try { if (typeof dualHideBackLoading === 'function') dualHideBackLoading(); } catch (e) {}
        try { if (typeof dualHideBackMini === 'function') dualHideBackMini(); } catch (e) {}
        if (dualNextToBackEl) dualNextToBackEl.disabled = true;
        if (typeof dualNextToResultEl !== 'undefined' && dualNextToResultEl) dualNextToResultEl.disabled = true;
        if (dualSaveBtnEl) dualSaveBtnEl.disabled = true;
        if (dualSaveBtnTextEl) dualSaveBtnTextEl.textContent = 'Simpan 4 Label';
        if (dualSaveFeedbackEl) { dualSaveFeedbackEl.textContent = ''; dualSaveFeedbackEl.className = 'text-xs font-medium text-[#6C757D]'; }
        if (dualMiniFeedbackEl) dualMiniFeedbackEl.textContent = '';
        try { dualShowBackMiniFeedback('', false); } catch (e) {}
        dualSetBadge(dualFrontBadgeEl, 'idle', 'idle');
        dualSetBadge(dualBackBadgeEl, 'idle', 'idle');
        if (resultCard) resultCard.classList.add('hidden');
        try { dualUpdateDegLabels(); } catch (e) {}
        try { dualSetRotateUI('front', false); dualSetRotateUI('back', false); } catch (e) {}
        try { dualSetProcessUI('front', false); dualSetProcessUI('back', false); } catch (e) {}
        dualSetStepper();
        dualSetStageInfo('front');
        dualApplyStageToCamera();
    }

    function dualShowMiniFeedback(msg, isError) {
        if (!dualMiniFeedbackEl) return;
        dualMiniFeedbackEl.textContent = msg || '';
        dualMiniFeedbackEl.className = 'text-xs font-medium ' + (isError ? 'text-[#E04438]' : 'text-[#6C757D]');
    }

    function dualShowSaveFeedback(msg, isError) {
        if (!dualSaveFeedbackEl) return;
        dualSaveFeedbackEl.textContent = msg || '';
        dualSaveFeedbackEl.className = 'text-xs font-medium ' + (isError ? 'text-[#E04438]' : 'text-[#6C757D]');
    }

    function dualUpdateBackLoadingTimer() {
        if (!dualBackLoadingSecsEl || !dualBackLoadingStart) return;
        var elapsed = Math.floor((Date.now() - dualBackLoadingStart) / 1000);
        dualBackLoadingSecsEl.textContent = elapsed + 's';
    }

    function dualShowBackLoading(cap) {
        if (dualFrontMiniEl) dualFrontMiniEl.classList.add('hidden');
        if (typeof dualHideBackMini === 'function') { try { dualHideBackMini(); } catch (e) {} }
        if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
        if (dualBackLoadingEl) dualBackLoadingEl.classList.remove('hidden');
        if (cap) {
            if (dualBackThumbEl) dualBackThumbEl.src = cap.url;
            if (dualBackLoadingFileEl) dualBackLoadingFileEl.textContent = cap.filename + ' - ' + cap.widthPx + 'x' + cap.heightPx + 'px';
        }
        dualBackLoadingStart = Date.now();
        if (dualBackLoadingTimer) { try { clearInterval(dualBackLoadingTimer); } catch (e) {} }
        dualUpdateBackLoadingTimer();
        try { dualBackLoadingTimer = setInterval(dualUpdateBackLoadingTimer, 1000); } catch (e) {}
        if (dualBackLoadingEl && dualBackLoadingEl.scrollIntoView) {
            try { dualBackLoadingEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
    }

    function dualHideBackLoading() {
        if (dualBackLoadingTimer) { try { clearInterval(dualBackLoadingTimer); } catch (e) {} dualBackLoadingTimer = null; }
        dualBackLoadingStart = 0;
        if (dualBackLoadingEl) dualBackLoadingEl.classList.add('hidden');
    }

    function dualUpdateFrontMini() {
        if (!dualFrontMiniEl) return;
        if (lastCaptureFront) {
            dualFrontMiniEl.classList.remove('hidden');
            if (dualFrontThumbEl) dualFrontThumbEl.src = lastCaptureFront.url;
            if (frontReviewImgEl && lastCaptureFront.url) frontReviewImgEl.src = lastCaptureFront.url;
            if (dualFrontMetaEl && selectedTemplate) {
                // Pakai angka rotated vertical dari capture (3.5 x 16 cm), bukan guide horizontal.
                var sizeTxt = lastCaptureFront.widthCm + ' x ' + lastCaptureFront.heightCm + ' cm';
                dualFrontMetaEl.textContent = selectedTemplate.id + ' - ' + selectedTemplate.name + ' - ' + sizeTxt + ' - ' + lastCaptureFront.filename;
            }
        } else {
            dualFrontMiniEl.classList.add('hidden');
        }
    }

    function dualUpdateResultMeta() {
        if (!dualResultMetaEl) return;
        var t = selectedTemplate ? (selectedTemplate.id + ' - ' + selectedTemplate.name) : '-';
        var f1 = splitFrontA ? splitFrontA.filename : (lastCaptureFront ? lastCaptureFront.filename : '-');
        var f2 = splitFrontB ? splitFrontB.filename : '-';
        var b1 = splitBackA ? splitBackA.filename : (lastCaptureBack ? lastCaptureBack.filename : '-');
        var b2 = splitBackB ? splitBackB.filename : '-';
        dualResultMetaEl.textContent = 'Template: ' + t + ' - Front: ' + f1 + ' + ' + f2 + ' - Back: ' + b1 + ' + ' + b2;
    }

    function dualUpdateSaveState() {
        var ready = doneFront1 && doneFront2 && doneBack1 && doneBack2 &&
            splitFrontA && splitFrontB && splitBackA && splitBackB;
        if (dualSaveBtnEl) dualSaveBtnEl.disabled = !ready;
        if (dualNextToBackEl && dualSide === 'front') dualNextToBackEl.disabled = !(doneFront1 && doneFront2);
        if (typeof dualNextToResultEl !== 'undefined' && dualNextToResultEl) dualNextToResultEl.disabled = !(doneBack1 && doneBack2 && splitBackA && splitBackB);
        dualSetStepper();
    }

    function dataUrlToBlob(dataUrl) {
        if (!dataUrl) return null;
        try {
            var parts = dataUrl.split(',');
            var mime = (parts[0].match(/:(.*?);/) || ['', 'image/png'])[1];
            var bstr = atob(parts[1]);
            var n = bstr.length;
            var u8arr = new Uint8Array(n);
            while (n--) {
                u8arr[n] = bstr.charCodeAt(n);
            }
            return new Blob([u8arr], { type: mime });
        } catch (e) {
            console.error('[AUTO-DETECT] dataUrlToBlob error:', e);
            return null;
        }
    }

    function dualOnCaptureClick() {
        if (!selectedTemplate) {
            selectedTemplate = { id: 'AUTO', name: 'Auto-Detected', width: 35, height: 90 };
        }
        if (!activeStream || !videoEl || !videoEl.videoWidth || videoEl.readyState < 2) {
            showCaptureFeedback('Camera is not ready yet.', true);
            return;
        }
        if (dualSide === 'done') return;
        if (captureBtn) captureBtn.disabled = true;
        try {
            dualCaptureForSide(dualSide);
        } catch (e) {
            if (captureBtn) captureBtn.disabled = false;
            showCaptureFeedback('Capture failed. Please try again.', true);
        }
    }

    function applyDetectedSlots(side, fullBlob, data) {
        if (!data || !data.slots || data.slots.length < 2) {
            if (captureBtn) captureBtn.disabled = false;
            showCaptureFeedback('Gagal mendeteksi 2 label. Silakan coba lagi.', true);
            return;
        }

        var slot1 = data.slots[0];
        var slot2 = data.slots[1];
        var blob1 = dataUrlToBlob(slot1.crop_data_url);
        var blob2 = dataUrlToBlob(slot2.crop_data_url);

        if (!blob1 || !blob2) {
            if (captureBtn) captureBtn.disabled = false;
            showCaptureFeedback('Gagal memproses potongan gambar.', true);
            return;
        }

        dualEnsureBaseStamp();
        var now = new Date();
        var tId = (selectedTemplate && selectedTemplate.id) || 'AUTO';
        var tName = (selectedTemplate && selectedTemplate.name) || 'Auto-Detected';

        var cap = {
            blob: fullBlob,
            url: URL.createObjectURL(fullBlob),
            filename: dualBuildFilename(side),
            templateId: tId,
            templateName: tName,
            widthCm: 3.5,
            heightCm: 9.0,
            widthPx: data.image_width || (videoEl ? videoEl.videoWidth : 1280),
            heightPx: data.image_height || (videoEl ? videoEl.videoHeight : 720),
            filesize: fullBlob.size,
            capturedAt: now
        };

        var cap1 = {
            blob: blob1,
            url: URL.createObjectURL(blob1),
            filename: dualBuildSplitFilename(side, 1),
            templateId: tId,
            templateName: tName,
            widthCm: 3.5,
            heightCm: 9.0,
            widthPx: slot1.w,
            heightPx: slot1.h,
            filesize: blob1.size,
            capturedAt: now
        };

        var cap2 = {
            blob: blob2,
            url: URL.createObjectURL(blob2),
            filename: dualBuildSplitFilename(side, 2),
            templateId: tId,
            templateName: tName,
            widthCm: 3.5,
            heightCm: 9.0,
            widthPx: slot2.w,
            heightPx: slot2.h,
            filesize: blob2.size,
            capturedAt: now
        };

        splitSetCap(side, 1, cap1);
        splitSetCap(side, 2, cap2);
        splitSetOcr(side, 1, null);
        splitSetOcr(side, 2, null);
        splitSetDone(side, 1, false);
        splitSetDone(side, 2, false);

        if (side === 'front') {
            dualRevokeSide('front');
            lastCaptureFront = cap;
            pendingOcrFront = null;
            dualFrontDone = false;
            dualSetBadge(dualFrontBadgeEl, 'idle', 'idle');
            if (dualFrontThumbEl) dualFrontThumbEl.src = cap.url;
            dualUpdateFrontMini();
            try { stopCamera(); } catch (e) {}

            splitSetAdjustVisible('front', false);
            splitSetTripleVisible('front', true);

            if (frontReviewImgEl) {
                frontReviewImgEl.src = data.annotated_preview || cap.url;
            }

            var els1 = splitPanelEls('front', 1), els2 = splitPanelEls('front', 2);
            if (els1.mini.img) els1.mini.img.src = cap1.url;
            if (els1.res.img) els1.res.img.src = cap1.url;
            if (els1.mini.meta) els1.mini.meta.textContent = 'Label 1 · ' + slot1.w + ' × ' + slot1.h + ' px' + (slot1.is_fallback ? ' (Auto)' : ' (OpenCV)');

            if (els2.mini.img) els2.mini.img.src = cap2.url;
            if (els2.res.img) els2.res.img.src = cap2.url;
            if (els2.mini.meta) els2.mini.meta.textContent = 'Label 2 · ' + slot2.w + ' × ' + slot2.h + ' px' + (slot2.is_fallback ? ' (Auto)' : ' (OpenCV)');

            dualSetStageInfo('front');
            dualShowMiniFeedback('Label terdeteksi! Memproses OCR Label 1...', false);
            if (dualNextToBackEl) dualNextToBackEl.disabled = true;

            if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
            try { dualHideBackLoading(); } catch (e) {}
            dualHideBackMini();
        } else {
            dualRevokeSide('back');
            lastCaptureBack = cap;
            pendingOcrBack = null;
            dualBackDone = false;
            dualSetBadge(dualBackBadgeEl, 'idle', 'idle');
            try { stopCamera(); } catch (e) {}

            splitSetAdjustVisible('back', false);
            splitSetTripleVisible('back', true);

            if (backReviewImgEl) {
                backReviewImgEl.src = data.annotated_preview || cap.url;
            }

            var bEls1 = splitPanelEls('back', 1), bEls2 = splitPanelEls('back', 2);
            if (bEls1.mini.img) bEls1.mini.img.src = cap1.url;
            if (bEls1.res.img) bEls1.res.img.src = cap1.url;
            if (bEls1.mini.meta) bEls1.mini.meta.textContent = 'Label 1 · ' + slot1.w + ' × ' + slot1.h + ' px' + (slot1.is_fallback ? ' (Auto)' : ' (OpenCV)');

            if (bEls2.mini.img) bEls2.mini.img.src = cap2.url;
            if (bEls2.res.img) bEls2.res.img.src = cap2.url;
            if (bEls2.mini.meta) bEls2.mini.meta.textContent = 'Label 2 · ' + slot2.w + ' × ' + slot2.h + ' px' + (slot2.is_fallback ? ' (Auto)' : ' (OpenCV)');

            dualSetStageInfo('back');
            dualShowBackMini(cap);
            dualShowBackMiniFeedback('Label terdeteksi! Memproses OCR Label 1...', false);
        }

        // Jalankan OCR sequential: Label 1 dulu, lalu Label 2
        sendSplitOcr(side, 1, function () {
            var still1 = splitGetCap(side, 1);
            if (!still1) {
                if (captureBtn) captureBtn.disabled = false;
                return;
            }
            if (side === 'front') dualShowMiniFeedback('Label 1 selesai — OCR Label 2...', false);
            else dualShowBackMiniFeedback('Label 1 selesai — OCR Label 2...', false);

            sendSplitOcr(side, 2, function () {
                if (captureBtn) captureBtn.disabled = false;
                if (side === 'front') {
                    dualShowMiniFeedback('FRONT selesai! Tekan "Lanjut ke Capture 2 (Back)".', false);
                    if (dualNextToBackEl) dualNextToBackEl.disabled = false;
                } else {
                    dualShowBackMiniFeedback('BACK selesai! Tekan "Simpan 2 Label".', false);
                }
                dualUpdateSaveState();
            });
        });
    }

    function dualCaptureForSide(side) {
        if (!videoEl || !videoEl.videoWidth) {
            if (captureBtn) captureBtn.disabled = false;
            showCaptureFeedback('Kamera belum siap.', true);
            return;
        }

        var vw = videoEl.videoWidth;
        var vh = videoEl.videoHeight;
        var capCanvas = document.createElement('canvas');
        capCanvas.width = vw;
        capCanvas.height = vh;
        var ctx = capCanvas.getContext('2d');
        ctx.drawImage(videoEl, 0, 0, vw, vh);

        if (side === 'front') {
            dualBaseFront = capCanvas;
            dualDegFront = 0;
        } else {
            dualBaseBack = capCanvas;
            dualDegBack = 0;
        }
        dualUpdateDegLabels();

        capCanvas.toBlob(function (blob) {
            if (captureBtn) captureBtn.disabled = false;
            if (!blob) {
                if (side === 'front') dualBaseFront = null; else dualBaseBack = null;
                showCaptureFeedback('Capture gagal. Silakan coba lagi.', true);
                return;
            }
            dualEnsureBaseStamp();
            var now = new Date();
            var tId = (selectedTemplate && selectedTemplate.id) || 'AUTO';
            var tName = (selectedTemplate && selectedTemplate.name) || 'Auto-Detected';
            var cap = {
                blob: blob,
                url: URL.createObjectURL(blob),
                filename: dualBuildFilename(side),
                templateId: tId,
                templateName: tName,
                widthCm: 3.5,
                heightCm: 9.0,
                widthPx: vw,
                heightPx: vh,
                filesize: blob.size,
                capturedAt: now
            };

            if (side === 'front') {
                dualRevokeSide('front');
                lastCaptureFront = cap;
                pendingOcrFront = null;
                dualFrontDone = false;
                dualSetBadge(dualFrontBadgeEl, 'idle', 'idle');
                if (dualFrontThumbEl) dualFrontThumbEl.src = cap.url;
                dualUpdateFrontMini();
                try { stopCamera(); } catch (e) {}
                if (dualFrontOcrEl) dualFrontOcrEl.textContent = 'Belum diproses — atur rotasi jika perlu, lalu tekan Simpan.';
                if (dualFrontTextEl) dualFrontTextEl.textContent = 'Belum diproses — atur rotasi jika perlu, lalu tekan Simpan.';
                dualSetStageInfo('front');
                dualShowMiniFeedback('Atur rotasi jika perlu, lalu tekan Simpan.', false);
                if (dualNextToBackEl) dualNextToBackEl.disabled = true;
                dualSetRotateUI('front', true);
                dualSetProcessUI('front', true);
                splitSetAdjustVisible('front', true);
                splitSetTripleVisible('front', false);
                if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
                try { dualHideBackLoading(); } catch (e) {}
                dualHideBackMini();
                flashEffect();
            } else {
                dualRevokeSide('back');
                lastCaptureBack = cap;
                pendingOcrBack = null;
                dualBackDone = false;
                dualSetBadge(dualBackBadgeEl, 'idle', 'idle');
                if (dualBackTextEl) dualBackTextEl.textContent = 'Belum diproses — atur rotasi jika perlu, lalu tekan Simpan.';
                try { stopCamera(); } catch (e) {}
                flashEffect();
                dualSetStageInfo('back');
                dualShowBackMini(cap);
                if (dualBackOcrEl) dualBackOcrEl.textContent = 'Belum diproses — atur rotasi jika perlu, lalu tekan Simpan.';
                dualShowBackMiniFeedback('Atur rotasi jika perlu, lalu tekan Simpan.', false);
                dualSetRotateUI('back', true);
                dualSetProcessUI('back', true);
                splitSetAdjustVisible('back', true);
                splitSetTripleVisible('back', false);
            }
            dualUpdateSaveState();
        }, 'image/png');
    }

    function dualGetAbort(side) {
        if (side === 'front') {
            try { if (dualOcrAbortFront) dualOcrAbortFront.abort(); } catch (e) {}
            try { dualOcrAbortFront = (typeof AbortController !== 'undefined') ? new AbortController() : null; } catch (e) { dualOcrAbortFront = null; }
            return dualOcrAbortFront;
        }
        try { if (dualOcrAbortBack) dualOcrAbortBack.abort(); } catch (e) {}
        try { dualOcrAbortBack = (typeof AbortController !== 'undefined') ? new AbortController() : null; } catch (e) { dualOcrAbortBack = null; }
        return dualOcrAbortBack;
    }

    function triggerAutoOcrFor(side) {
        var cap = (side === 'front') ? lastCaptureFront : lastCaptureBack;
        if (!cap || !cap.blob) return;
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) { dualRenderOcrError(side, 'Sesi telah berakhir. Silakan login kembali.'); return; }
        var aborter = dualGetAbort(side);
        dualSetBadge(side === 'front' ? dualFrontBadgeEl : dualBackBadgeEl, 'processing', 'processing');
        if (side === 'front') { if (dualFrontTextEl) dualFrontTextEl.textContent = 'Memproses OCR...'; }
        else { if (dualBackTextEl) dualBackTextEl.textContent = 'Memproses OCR...'; }
        if (side === 'front' && dualFrontOcrEl) dualFrontOcrEl.textContent = 'Memproses OCR sisi depan...';
        dualUpdateSaveState();
        var fd = new FormData();
        fd.append('image', cap.blob, cap.filename);
        fd.append('template_id', cap.templateId);
        fd.append('template_name', cap.templateName);
        fd.append('template_width', cap.widthCm * 10);
        fd.append('template_height', cap.heightCm * 10);
        fd.append('filename', cap.filename);
        fd.append('resolution_w', cap.widthPx);
        fd.append('resolution_h', cap.heightPx);
        fd.append('filesize', cap.filesize);
        var fetchOpts = { method: 'POST', headers: { 'Authorization': 'Bearer ' + token }, body: fd };
        if (aborter && aborter.signal) fetchOpts.signal = aborter.signal;
        var capturedFilename = cap.filename;
        fetch('/api/ocr/preview', fetchOpts).then(function (res) {
            if (res.status === 401) { try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {} throw new Error('UNAUTHORIZED'); }
            if (res.status === 429) return res.json().then(function (d) {
                var wait = (d && d.retry_after_seconds) || 60;
                throw new Error('RATE_LIMITED:' + wait);
            }, function () { throw new Error('RATE_LIMITED:60'); });
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'Server error'); });
            return res.json();
        }).then(function (data) {
            var syncOcr = null;
            if (data && data.data && data.data.cleaned_text) syncOcr = data.data;
            else if (data && data.cleaned_text) syncOcr = data;
            if (syncOcr && syncOcr.cleaned_text) {
                var syncFn = syncOcr.original_filename || syncOcr.filename || capturedFilename;
                var expected = (side === 'front' ? (lastCaptureFront && lastCaptureFront.filename) : (lastCaptureBack && lastCaptureBack.filename));
                if (syncFn && expected && syncFn !== expected) return;
                dualRenderOcrResult(side, syncOcr);
                return;
            }
            if (data && (data.status === 'empty' || (data.data && (data.data.ocr_status === 'empty' || data.data.status === 'empty')))) {
                dualRenderOcrError(side, 'OCR kosong - gambar terlalu gelap atau kontras rendah. Coba Retake sisi ini dengan pencahayaan lebih terang.');
                return;
            }
            dualPollOcr(side, capturedFilename, 0);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (err && err.message && err.message.indexOf('RATE_LIMITED') === 0) {
                var parts = String(err.message).split(':');
                var waitSecs = parseInt(parts[1], 10) || 60;
                dualRenderOcrError(side, 'Terlalu cepat - FastAPI batasi 5 gambar/menit. Tunggu ' + waitSecs + ' detik lalu Retake sisi ini.');
                return;
            }
            if (err && err.message === 'UNAUTHORIZED') dualRenderOcrError(side, 'Sesi berakhir. Silakan login kembali.');
            else dualRenderOcrError(side, (err && err.message) || 'Gagal memproses OCR sisi ini. Coba Retake.');
        });
    }

    function dualPollOcr(side, filename, attempt) {
        attempt = attempt || 0;
        var expected = (side === 'front' ? (lastCaptureFront && lastCaptureFront.filename) : (lastCaptureBack && lastCaptureBack.filename));
        if (expected && filename !== expected) return;
        if (attempt >= OCR_POLL_MAX_ATTEMPTS) {
            dualRenderOcrError(side, 'Masih processing setelah 120 detik. Klik Cek Lagi sisi ini (tanpa capture ulang).');
            return;
        }
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) return;
        var jitter = Math.floor(Math.random() * 500);
        var delay = attempt === 0 ? 0 : OCR_POLL_INTERVAL_MS + jitter;
        setTimeout(function () {
            var expected2 = (side === 'front' ? (lastCaptureFront && lastCaptureFront.filename) : (lastCaptureBack && lastCaptureBack.filename));
            if (expected2 && filename !== expected2) return;
            fetch('/api/ocr/' + encodeURIComponent(filename) + '?_t=' + Date.now(), {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
            }).then(function (res) {
                if (res.status === 401) throw new Error('UNAUTHORIZED');
                if (res.status === 429) return res.json().then(function (d) {
                    throw new Error('RATE_LIMITED:' + ((d && d.retry_after_seconds) || 60));
                }, function () { throw new Error('RATE_LIMITED:60'); });
                if (res.status === 422) throw new Error('EMPTY');
                if (res.status === 202) return { status: 'processing' };
                if (res.status === 404) return { status: 'not_found' };
                if (!res.ok) throw new Error('poll failed');
                return res.json();
            }).then(function (data) {
                var payload = data && data.data ? data.data : data;
                if (payload && payload.cleaned_text) {
                    var pf = payload.original_filename || payload.filename || '';
                    if (pf && pf !== filename) { dualPollOcr(side, filename, attempt + 1); return; }
                    dualRenderOcrResult(side, payload);
                    return;
                }
                if (payload && payload.status === 'empty') {
                    dualRenderOcrError(side, 'OCR kosong - gambar terlalu gelap atau kontras rendah. Coba Retake sisi ini.');
                    return;
                }
                dualPollOcr(side, filename, attempt + 1);
            }).catch(function (err) {
                if (err && err.message === 'UNAUTHORIZED') { dualRenderOcrError(side, 'Sesi berakhir. Silakan login kembali.'); return; }
                if (err && err.message === 'EMPTY') { dualRenderOcrError(side, 'OCR kosong - coba Retake sisi ini.'); return; }
                if (err && err.message && err.message.indexOf('RATE_LIMITED') === 0) {
                    var w = parseInt(String(err.message).split(':')[1], 10) || 60;
                    dualRenderOcrError(side, 'Terlalu cepat - tunggu ' + w + ' detik lalu Retake sisi ini.');
                    return;
                }
                dualPollOcr(side, filename, attempt + 1);
            });
        }, delay);
    }

    // ===== Split OCR: 1 sisi -> Label 1 + Label 2, dikirim SEQUENTIAL (bukan paralel) =====
    // Alasan sequential: OCR eksternal serial 1 engine + rate-limit ketat; paralel memicu 429/timeout.
    function sendSplitOcr(side, idx, doneCb) {
        var cap = splitGetCap(side, idx);
        var els = splitPanelEls(side, idx);
        if (!cap || !cap.blob) {
            dualRenderSplitOcrError(side, idx, 'Capture belahan hilang. Tekan Simpan ulang sisi ini.');
            if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
            return;
        }
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) {
            dualRenderSplitOcrError(side, idx, 'Sesi telah berakhir. Silakan login kembali.');
            if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
            return;
        }
        var aborter = null;
        try { aborter = (typeof AbortController !== 'undefined') ? new AbortController() : null; } catch (e) { aborter = null; }
        splitSetAbort(side, idx, aborter);
        dualSetSplitBadge(els.mini.badge, 'processing', 'Label ' + idx + ' · processing');
        dualSetSplitBadge(els.res.badge, 'processing', 'Label ' + idx + ' · processing');
        if (els.mini.ocr) els.mini.ocr.textContent = 'Memproses OCR Label ' + idx + '...';
        if (els.res.text) els.res.text.textContent = 'Memproses OCR Label ' + idx + '...';
        splitSetCheck(side, idx, false);
        refreshSplitView(side);
        // Paritas Front: section 3 gambar Back tetap tampil selama OCR (jangan tutup dengan loader).
        // Loader hanya disembunyikan defensif (anti sisa dari alur lama / race).
        try { dualHideBackLoading(); } catch (e) {}
        if (side === 'back' && dualBackMiniEl) dualBackMiniEl.classList.remove('hidden');
        dualUpdateSaveState();
        var fd = new FormData();
        fd.append('image', cap.blob, cap.filename);
        fd.append('template_id', cap.templateId);
        fd.append('template_name', cap.templateName);
        fd.append('template_width', cap.widthCm * 10);
        fd.append('template_height', cap.heightCm * 10);
        fd.append('filename', cap.filename);
        fd.append('resolution_w', cap.widthPx);
        fd.append('resolution_h', cap.heightPx);
        fd.append('filesize', cap.filesize);
        var fetchOpts = { method: 'POST', headers: { 'Authorization': 'Bearer ' + token }, body: fd };
        if (aborter && aborter.signal) fetchOpts.signal = aborter.signal;
        var capturedFilename = cap.filename;
        fetch('/api/ocr/preview', fetchOpts).then(function (res) {
            if (res.status === 401) { try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {} throw new Error('UNAUTHORIZED'); }
            if (res.status === 429) return res.json().then(function (d) {
                var wait = (d && d.retry_after_seconds) || 60;
                throw new Error('RATE_LIMITED:' + wait);
            }, function () { throw new Error('RATE_LIMITED:60'); });
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'Server error'); });
            return res.json();
        }).then(function (data) {
            var syncOcr = null;
            if (data && data.data && data.data.cleaned_text) syncOcr = data.data;
            else if (data && data.cleaned_text) syncOcr = data;
            if (syncOcr && syncOcr.cleaned_text) {
                var syncFn = syncOcr.original_filename || syncOcr.filename || capturedFilename;
                var cur = splitGetCap(side, idx);
                if (!cur || (syncFn && syncFn !== cur.filename)) {
                    if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                    return;
                }
                dualRenderSplitOcrResult(side, idx, syncOcr);
                if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                return;
            }
            if (data && (data.status === 'empty' || (data.data && (data.data.ocr_status === 'empty' || data.data.status === 'empty')))) {
                dualRenderSplitOcrError(side, idx, 'OCR kosong Label ' + idx + ' - gambar terlalu gelap. Coba Retake sisi ini.');
                if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                return;
            }
            dualPollSplitOcr(side, idx, capturedFilename, 0, doneCb);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (err && err.message && err.message.indexOf('RATE_LIMITED') === 0) {
                var parts = String(err.message).split(':');
                var waitSecs = parseInt(parts[1], 10) || 60;
                dualRenderSplitOcrError(side, idx, 'Terlalu cepat Label ' + idx + ' - tunggu ' + waitSecs + ' detik lalu Proses ulang.');
            } else if (err && err.message === 'UNAUTHORIZED') {
                dualRenderSplitOcrError(side, idx, 'Sesi berakhir. Silakan login kembali.');
            } else {
                dualRenderSplitOcrError(side, idx, (err && err.message) || 'Gagal memproses OCR Label ' + idx + '.');
            }
            if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
        });
    }

    function dualPollSplitOcr(side, idx, filename, attempt, doneCb) {
        attempt = attempt || 0;
        var cur = splitGetCap(side, idx);
        if (!cur || filename !== cur.filename) return;
        if (attempt >= OCR_POLL_MAX_ATTEMPTS) {
            dualRenderSplitOcrError(side, idx, 'Masih processing Label ' + idx + ' setelah 120 detik. Klik Cek Lagi (tanpa kirim ulang).');
            if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
            return;
        }
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) return;
        var jitter = Math.floor(Math.random() * 500);
        var delay = attempt === 0 ? 0 : OCR_POLL_INTERVAL_MS + jitter;
        setTimeout(function () {
            var cur2 = splitGetCap(side, idx);
            if (!cur2 || filename !== cur2.filename) return;
            fetch('/api/ocr/' + encodeURIComponent(filename) + '?_t=' + Date.now(), {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
            }).then(function (res) {
                if (res.status === 401) throw new Error('UNAUTHORIZED');
                if (res.status === 429) return res.json().then(function (d) {
                    throw new Error('RATE_LIMITED:' + ((d && d.retry_after_seconds) || 60));
                }, function () { throw new Error('RATE_LIMITED:60'); });
                if (res.status === 422) throw new Error('EMPTY');
                if (res.status === 202) return { status: 'processing' };
                if (res.status === 404) return { status: 'not_found' };
                if (!res.ok) throw new Error('poll failed');
                return res.json();
            }).then(function (data) {
                var payload = data && data.data ? data.data : data;
                if (payload && payload.cleaned_text) {
                    var pf = payload.original_filename || payload.filename || '';
                    if (pf && pf !== filename) { dualPollSplitOcr(side, idx, filename, attempt + 1, doneCb); return; }
                    dualRenderSplitOcrResult(side, idx, payload);
                    if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                    return;
                }
                if (payload && payload.status === 'empty') {
                    dualRenderSplitOcrError(side, idx, 'OCR kosong Label ' + idx + '. Coba Retake sisi ini.');
                    if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                    return;
                }
                dualPollSplitOcr(side, idx, filename, attempt + 1, doneCb);
            }).catch(function (err) {
                if (err && err.message === 'UNAUTHORIZED') {
                    dualRenderSplitOcrError(side, idx, 'Sesi berakhir. Silakan login kembali.');
                    if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                    return;
                }
                if (err && err.message === 'EMPTY') {
                    dualRenderSplitOcrError(side, idx, 'OCR kosong Label ' + idx + '.');
                    if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                    return;
                }
                if (err && err.message && err.message.indexOf('RATE_LIMITED') === 0) {
                    var w = parseInt(String(err.message).split(':')[1], 10) || 60;
                    dualRenderSplitOcrError(side, idx, 'Terlalu cepat Label ' + idx + ' - tunggu ' + w + ' detik.');
                    if (typeof doneCb === 'function') { try { doneCb(); } catch (e) {} }
                    return;
                }
                dualPollSplitOcr(side, idx, filename, attempt + 1, doneCb);
            });
        }, delay);
    }

    // Teks OCR dianggap kosong bila: string kosong (setelah trim) + tidak ada lines.
    // Kontrak backend (TestSignalController, "kontrak RapidOCR 2026-09-11"): cleaned_text
    // 'none' = success VALID (FastAPI tidak menemukan teks: text_present=False, 0 lines).
    // Terbukti dari log FastAPI: cleaned_text='none' + callback Laravel 200 + PULL 200 OK.
    // Maka frontend WAJIB menerima 'none' sebagai sukses (badge success, Next aktif),
    // bukan error "OCR kosong ... gambar terlalu gelap". Jangan tambah daftar
    // placeholder di sini tanpa mengubah kontrak backend yang sama.
    function splitIsEmptyText(cleaned, lines) {
        var t = String(cleaned || '').trim();
        if (t === '' && !(lines && lines.length)) return true;
        return false;
    }

    function dualRenderSplitOcrResult(side, idx, data) {
        if (!data) return;
        // Pastikan loader mati + section Back tampil di semua status terminal (anti stuck).
        if (side === 'back') {
            try { dualHideBackLoading(); } catch (e) {}
            if (dualBackMiniEl) dualBackMiniEl.classList.remove('hidden');
        }
        var cap = splitGetCap(side, idx);
        if (!cap) return;
        var dataFilename = data.original_filename || data.filename || '';
        if (dataFilename && dataFilename !== cap.filename) return;
        var cleaned = data.cleaned_text || data.cleanedText || '';
        if (splitIsEmptyText(cleaned, data.lines)) {
            dualRenderSplitOcrError(side, idx, 'OCR kosong Label ' + idx + ' - gambar terlalu gelap. Coba Retake sisi ini.');
            return;
        }
        splitSetOcr(side, idx, data);
        splitSetDone(side, idx, true);
        splitSetAbort(side, idx, null);
        var els = splitPanelEls(side, idx);
        if (els.mini.ocr) els.mini.ocr.textContent = cleaned || '-';
        if (els.res.text) els.res.text.textContent = cleaned || '-';
        dualSetSplitBadge(els.mini.badge, 'success', 'Label ' + idx + ' · ' + (data.status || 'success'));
        dualSetSplitBadge(els.res.badge, 'success', 'Label ' + idx + ' · ' + (data.status || 'success'));
        // Penanda checklist: ekstraksi selesai dan tampil di box.
        splitSetCheck(side, idx, true);
        refreshSplitView(side);
        try { console.log('[OCR Split] ' + side + '-' + idx + ' cleaned_text:', cleaned); } catch (e) {}
        if (side === 'front' && doneFront1 && doneFront2) {
            dualSetBadge(dualFrontBadgeEl, 'success', 'success');
            dualShowMiniFeedback('Front siap (Label 1+2) - lanjut ke Capture 2.', false);
            dualSetStageInfo('front-ok');
            dualSetProcessUI('front', false);
        }
        if (side === 'back' && doneBack1 && doneBack2) {
            dualSetBadge(dualBackBadgeEl, 'success', 'success');
            dualShowBackMiniFeedback('Back siap (Label 1+2) — tekan Next untuk lihat hasil 2 sisi.', false);
            dualSetStageInfo('back-ok');
            dualSetProcessUI('back', false);
        }
        // Paritas Front: Back done TIDAK auto ke Result — tunggu klik Next (dualGoToResult).
        dualUpdateSaveState();
    }

    function dualRenderSplitOcrError(side, idx, message) {
        // Pastikan loader mati + section Back tampil agar user bisa Retake / Simpan ulang (anti stuck).
        if (side === 'back') {
            try { dualHideBackLoading(); } catch (e) {}
            if (dualBackMiniEl) dualBackMiniEl.classList.remove('hidden');
        }
        splitSetDone(side, idx, false);
        splitSetAbort(side, idx, null);
        var els = splitPanelEls(side, idx);
        if (els.mini.ocr) els.mini.ocr.textContent = message;
        if (els.res.text) els.res.text.textContent = message;
        dualSetSplitBadge(els.mini.badge, 'failed', 'Label ' + idx + ' · failed');
        dualSetSplitBadge(els.res.badge, 'failed', 'Label ' + idx + ' · failed');
        splitSetCheck(side, idx, false);
        refreshSplitView(side);
        if (side === 'front') {
            dualSetBadge(dualFrontBadgeEl, 'failed', 'failed');
            dualShowMiniFeedback('Front Label ' + idx + ': ' + message + ' - Proses ulang atau Retake.', true);
            dualSetStageInfo('front');
            if (dualNextToBackEl) dualNextToBackEl.disabled = true;
        } else {
            dualSetBadge(dualBackBadgeEl, 'failed', 'failed');
            dualShowSaveFeedback('Back Label ' + idx + ': ' + message + ' - Proses ulang atau Retake.', true);
            dualShowBackMiniFeedback('Back Label ' + idx + ': ' + message, true);
        }
        dualSetRotateUI(side, true);
        dualSetProcessUI(side, true);
        dualUpdateSaveState();
    }

    function dualRenderOcrResult(side, data) {
        if (!data) return;
        // Pastikan loader mati + section Back tampil di jalur lama juga (anti stuck).
        if (side === 'back') {
            try { dualHideBackLoading(); } catch (e) {}
            if (dualBackMiniEl) dualBackMiniEl.classList.remove('hidden');
        }
        var dataFilename = data.original_filename || data.filename || '';
        var cap = (side === 'front') ? lastCaptureFront : lastCaptureBack;
        if (cap && dataFilename && dataFilename !== cap.filename) return;
        var cleaned = data.cleaned_text || data.cleanedText || '';
        if (splitIsEmptyText(cleaned, data.lines)) {
            dualRenderOcrError(side, 'OCR kosong - gambar terlalu gelap atau kontras rendah. Coba Retake sisi ini dengan pencahayaan lebih terang.');
            return;
        }
        if (side === 'front') {
            pendingOcrFront = data; dualFrontDone = true;
            if (dualFrontOcrEl) dualFrontOcrEl.textContent = cleaned || '-';
            if (dualFrontTextEl) dualFrontTextEl.textContent = cleaned || '-';
            dualSetBadge(dualFrontBadgeEl, 'success', data.status || 'success');
            dualShowMiniFeedback('Front siap - lanjut ke Capture 2.', false);
            dualSetStageInfo('front-ok');
            if (dualFrontMiniEl) dualFrontMiniEl.classList.remove('hidden');
            dualUpdateFrontMini();
            dualSetRotateUI('front', true);
            dualSetProcessUI('front', false);
        } else {
            pendingOcrBack = data; dualBackDone = true;
            if (dualBackTextEl) dualBackTextEl.textContent = cleaned || '-';
            if (dualBackOcrEl) dualBackOcrEl.textContent = cleaned || '-';
            dualSetBadge(dualBackBadgeEl, 'success', data.status || 'success');
            dualShowBackMiniFeedback('Back siap — tekan Next untuk lihat hasil 2 sisi.', false);
            dualSetStageInfo('back-ok');
            dualSetRotateUI('back', true);
            dualSetProcessUI('back', false);
        }
        // Paritas Front (jalur lama): Back done TIDAK auto ke Result — tunggu klik Next (dualGoToResult).
        dualUpdateSaveState();
    }

    function dualRenderOcrError(side, message) {
        if (side === 'front') {
            dualFrontDone = false;
            if (dualFrontOcrEl) dualFrontOcrEl.textContent = message;
            if (dualFrontTextEl) dualFrontTextEl.textContent = message;
            dualSetBadge(dualFrontBadgeEl, 'failed', 'failed');
            dualShowMiniFeedback(message + ' - koreksi rotasi lalu Proses ulang atau Retake.', true);
            dualSetStageInfo('front');
            if (dualNextToBackEl) dualNextToBackEl.disabled = true;
            dualSetRotateUI('front', true);
            dualSetProcessUI('front', true);
        } else {
            dualBackDone = false;
            try { dualHideBackLoading(); } catch (e) {}
            if (dualBackTextEl) dualBackTextEl.textContent = message;
            if (dualBackOcrEl) dualBackOcrEl.textContent = message;
            dualSetBadge(dualBackBadgeEl, 'failed', 'failed');
            dualShowSaveFeedback('Back: ' + message + ' - koreksi rotasi lalu Proses ulang atau Retake.', true);
            dualShowBackMiniFeedback(message + ' - koreksi rotasi lalu Proses ulang.', true);
            if (lastCaptureBack) { try { dualShowBackMini(lastCaptureBack); } catch (e) {} }
            dualSetRotateUI('back', true);
            dualSetProcessUI('back', true);
            if (dualFrontMiniEl) dualFrontMiniEl.classList.add('hidden');
            if (scanHeader) scanHeader.classList.add('hidden');
            if (dualResultCardEl) dualResultCardEl.classList.remove('hidden');
            if (dualFrontImgEl && lastCaptureFront) dualFrontImgEl.src = lastCaptureFront.url;
            if (dualBackImgEl && lastCaptureBack) dualBackImgEl.src = lastCaptureBack.url;
            if (dualFrontTextEl && pendingOcrFront) dualFrontTextEl.textContent = pendingOcrFront.cleaned_text || '-';
            if (typeof dualUpdateResultMeta === 'function') { try { dualUpdateResultMeta(); } catch (e) {} }
            dualSetStageInfo('back');
            dualSide = dualFrontDone ? 'done' : 'back';
            if (dualResultCardEl && dualResultCardEl.scrollIntoView) {
                try { dualResultCardEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
            }
        }
        dualUpdateSaveState();
    }

    function dualGoToBack() {
        if (!dualFrontDone || !lastCaptureFront) { dualShowMiniFeedback('Selesaikan OCR Front dulu.', true); return; }
        dualSide = 'back';
        try { dualHideBackLoading(); } catch (e) {}
        try { dualHideBackMini(); } catch (e) {}
        if (dualFrontMiniEl) dualFrontMiniEl.classList.add('hidden');
        if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
        dualSetStageInfo('back');
        dualApplyStageToCamera();
        try { startCamera(); } catch (e) {}
        if (lastCaptureBack && dualBackImgEl) dualBackImgEl.src = lastCaptureBack.url;
    }

    function dualGoToResult() {
        if (!(doneBack1 && doneBack2 && splitBackA && splitBackB)) { dualShowBackMiniFeedback('Selesaikan OCR Back (Label 1+2) dulu.', true); return; }
        dualEnterDualResult();
    }

    function dualEnterDualResult() {
        dualSide = 'done';
        try { stopCamera(); } catch (e) {}
        try { dualHideBackLoading(); } catch (e) {}
        try { dualHideBackMini(); } catch (e) {}
        if (dualFrontMiniEl) dualFrontMiniEl.classList.add('hidden');
        if (resultCard) resultCard.classList.add('hidden');
        if (scanHeader) scanHeader.classList.add('hidden');
        if (dualResultCardEl) dualResultCardEl.classList.remove('hidden');
        if (dualFrontImgEl && lastCaptureFront) dualFrontImgEl.src = lastCaptureFront.url;
        if (dualBackImgEl && lastCaptureBack) dualBackImgEl.src = lastCaptureBack.url;
        if (dualFrontTextEl && pendingOcrFront) dualFrontTextEl.textContent = pendingOcrFront.cleaned_text || '-';
        if (dualBackTextEl && pendingOcrBack) dualBackTextEl.textContent = pendingOcrBack.cleaned_text || '-';
        // Panel split hasil: 2 belahan per sisi + teks OCR per belahan.
        [['front', 1], ['front', 2], ['back', 1], ['back', 2]].forEach(function (pair) {
            var side = pair[0], idx = pair[1];
            var cap = splitGetCap(side, idx);
            var ocr = splitGetOcr(side, idx);
            var els = splitPanelEls(side, idx);
            if (cap && els.res.img) els.res.img.src = cap.url;
            if (els.res.text) els.res.text.textContent = ocr ? (ocr.cleaned_text || '-') : '-';
            var done = splitGetDone(side, idx);
            dualSetSplitBadge(els.res.badge, done ? 'success' : 'idle',
                'Label ' + idx + ' · ' + (done ? (ocr && ocr.status ? ocr.status : 'success') : 'idle'));
        });
        dualUpdateResultMeta();
        dualSetStageInfo('dual-ok');
        dualSyncResultImages();
        dualSetRotateUI('front', true);
        dualSetRotateUI('back', true);
        dualSetProcessUI('front', false);
        dualSetProcessUI('back', false);
        // Reprocess dibuka kembali agar koreksi terakhir di result card bisa diproses ulang.
        // Tombol aktif hanya bermakna bila user memutar ulang (lihat dualInvalidateSide).
        dualUpdateSaveState();
        if (dualResultCardEl && dualResultCardEl.scrollIntoView) {
            try { dualResultCardEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
    }

    function dualRetake(side) {
        try { dualHideBackLoading(); } catch (e) {}
        var oldFn = (side === 'front' ? (lastCaptureFront && lastCaptureFront.filename) : (lastCaptureBack && lastCaptureBack.filename)) || '';
        try {
            if (side === 'front' && dualOcrAbortFront) dualOcrAbortFront.abort();
            if (side === 'back' && dualOcrAbortBack) dualOcrAbortBack.abort();
        } catch (e) {}
        if (side === 'front') dualOcrAbortFront = null; else dualOcrAbortBack = null;
        if (oldFn) {
            var token = '';
            try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
            if (token) {
                fetch('/api/ocr/retake-cleanup', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ filename: oldFn })
                }).then(function (r) { return r.json(); })
                    .catch(function (e) {});
            }
        }
        splitAbortAll(side);
        // Cleanup file split di backend (best effort) agar tidak yatim.
        [1, 2].forEach(function (idx) {
            var sc = splitGetCap(side, idx);
            if (sc && sc.filename && token) {
                fetch('/api/ocr/retake-cleanup', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ filename: sc.filename })
                }).then(function (r) { return r.json(); })
                    .catch(function (e) {});
            }
        });
        splitRevokeSide(side);
        splitResetPanels(side);
        dualSyncSplitAggregates();
        if (side === 'front') {
            dualRevokeSide('front');
            dualBaseFront = null; dualDegFront = 0; dualBusyFront = false;
            pendingOcrFront = null; dualFrontDone = false;
            dualSetBadge(dualFrontBadgeEl, 'idle', 'idle');
            if (dualFrontOcrEl) dualFrontOcrEl.textContent = 'Menunggu OCR...';
            if (dualFrontTextEl) dualFrontTextEl.textContent = '-';
            refFront1 = null; refFront2 = null;
            dualSide = 'front';
            if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
            dualSetStageInfo('front');
            dualShowMiniFeedback('', false);
            dualUpdateDegLabels();
            dualSetRotateUI('front', false);
            dualSetProcessUI('front', false);
        } else {
            dualRevokeSide('back');
            dualBaseBack = null; dualDegBack = 0; dualBusyBack = false;
            pendingOcrBack = null; dualBackDone = false;
            dualSetBadge(dualBackBadgeEl, 'idle', 'idle');
            if (dualBackTextEl) dualBackTextEl.textContent = '-';
            if (dualBackOcrEl) dualBackOcrEl.textContent = 'Menunggu OCR...';
            refBack1 = null; refBack2 = null;
            dualShowBackMiniFeedback('', false);
            dualHideBackMini();
            dualSide = dualFrontDone ? 'back' : 'front';
            if (dualResultCardEl) dualResultCardEl.classList.add('hidden');
            if (dualFrontDone && dualSide === 'back') {
                if (dualFrontMiniEl) dualFrontMiniEl.classList.add('hidden');
                dualSetStageInfo('back');
            } else {
                dualSetStageInfo('front');
            }
            dualShowSaveFeedback('', false);
        }
        dualApplyStageToCamera();
        dualUpdateSaveState();
        try { startCamera(); } catch (e) {}
    }

    function dualAppendOcr(fd, ocr) {
        if (!ocr) return;
        if (ocr.cleaned_text) fd.append('cleaned_text', ocr.cleaned_text);
        if (ocr.raw_text) fd.append('raw_text', ocr.raw_text);
        if (ocr.lines) fd.append('lines', JSON.stringify(ocr.lines));
        if (ocr.engine) fd.append('engine', ocr.engine);
        if (ocr.language) fd.append('language', ocr.language);
        if (ocr.ocr_filename || ocr.filename) fd.append('ocr_filename', ocr.ocr_filename || ocr.filename);
        if (ocr.processing_time_seconds) fd.append('processing_time_seconds', String(ocr.processing_time_seconds));
        if (ocr.processed_at) fd.append('processed_at', String(ocr.processed_at));
    }

    function dualPostOne(cap, ocr, token, refName) {
        var fd = new FormData();
        fd.append('image', cap.blob, cap.filename);
        fd.append('template_id', cap.templateId);
        fd.append('template_name', cap.templateName);
        // Nama custom popup (1 sesi front+back memakai nama yang sama).
        // Dikirim dalam request yang sama (tanpa RTT tambahan); server fallback
        // ke template_name bila kosong agar klien lama tetap kompatibel.
        if (refName && String(refName).trim() !== '') {
            fd.append('name', String(refName).trim().slice(0, REFNAME_MAX_LEN));
        }
        fd.append('template_width', cap.widthCm * 10);
        fd.append('template_height', cap.heightCm * 10);
        fd.append('filename', cap.filename);
        fd.append('resolution_w', cap.widthPx);
        fd.append('resolution_h', cap.heightPx);
        fd.append('filesize', cap.filesize);
        dualAppendOcr(fd, ocr);
        return fetch('/api/references', { method: 'POST', headers: { 'Authorization': 'Bearer ' + token }, body: fd })
            .then(function (res) {
                if (res.status === 401) throw new Error('UNAUTHORIZED');
                if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || ('HTTP ' + res.status)); });
                return res.json();
            });
    }

    function dualSetSaving(busy, text) {
        if (dualSaveBtnEl) dualSaveBtnEl.disabled = busy;
        if (dualRetakeFrontEl) dualRetakeFrontEl.disabled = busy;
        if (dualRetakeFront2El) dualRetakeFront2El.disabled = busy;
        if (dualRetakeBack2El) dualRetakeBack2El.disabled = busy;
        if (dualRetakeBackEl) dualRetakeBackEl.disabled = busy;
        if (dualNextToBackEl) dualNextToBackEl.disabled = busy;
        if (typeof dualNextToResultEl !== 'undefined' && dualNextToResultEl) dualNextToResultEl.disabled = busy;
        if (busy && dualSaveBtnTextEl) dualSaveBtnTextEl.textContent = text || 'Menyimpan...';
        else if (dualSaveBtnTextEl) dualSaveBtnTextEl.textContent = 'Simpan 4 Label';
    }

    // Simpan final: 4 belahan berurutan Front-1 -> Front-2 -> Back-1 -> Back-2 (1 POST per belahan).
    function dualSaveBoth(refName) {
        if (!splitFrontA || !splitFrontB || !splitBackA || !splitBackB ||
            !doneFront1 || !doneFront2 || !doneBack1 || !doneBack2) {
            dualShowSaveFeedback('Lengkapi 2 sisi (Label 1+2) + tunggu OCR selesai dulu.', true);
            return;
        }
        // Nama sesi dari popup (fallback: nama yang sedang diketik bila pemanggil lupa oper).
        var sessionName = (refName !== undefined && refName !== null) ? String(refName).trim() : '';
        if (!sessionName && refnameInput) { sessionName = String(refnameInput.value || '').trim(); }
        if (!sessionName && pendingRefName) { sessionName = String(pendingRefName).trim(); }
        if (sessionName.length < REFNAME_MIN_LEN || sessionName.length > REFNAME_MAX_LEN) {
            dualShowSaveFeedback('Nama reference harus ' + REFNAME_MIN_LEN + '..' + REFNAME_MAX_LEN + ' karakter.', true);
            openRefnameModal();
            return;
        }
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) { dualShowSaveFeedback('Sesi telah berakhir. Silakan login kembali.', true); return; }
        dualSetSaving(true, 'Menyimpan 1/4 (Front-1)...');
        dualShowSaveFeedback('Menyimpan 1/4 (Front Label 1) sebagai "' + sessionName + '"...', false);
        showLoading('Menyimpan 1/4 (Front Label 1)...');
        dualSetStageInfo('saving');
        var queue = [
            { key: 'front1', cap: function () { return splitFrontA; }, ocr: function () { return ocrFront1; }, getRef: function () { return refFront1; }, setRef: function (id) { refFront1 = id; }, label: 'Front Label 1' },
            { key: 'front2', cap: function () { return splitFrontB; }, ocr: function () { return ocrFront2; }, getRef: function () { return refFront2; }, setRef: function (id) { refFront2 = id; }, label: 'Front Label 2' },
            { key: 'back1', cap: function () { return splitBackA; }, ocr: function () { return ocrBack1; }, getRef: function () { return refBack1; }, setRef: function (id) { refBack1 = id; }, label: 'Back Label 1' },
            { key: 'back2', cap: function () { return splitBackB; }, ocr: function () { return ocrBack2; }, getRef: function () { return refBack2; }, setRef: function (id) { refBack2 = id; }, label: 'Back Label 2' }
        ];
        var chain = Promise.resolve();
        var savedIds = {};
        queue.forEach(function (item, i) {
            chain = chain.then(function () {
                var existing = item.getRef();
                if (existing) {
                    savedIds[item.key] = existing;
                    return null;
                }
                if (loadingText) loadingText.textContent = 'Menyimpan ' + (i + 1) + '/4 (' + item.label + ')...';
                if (dualSaveBtnTextEl) dualSaveBtnTextEl.textContent = 'Menyimpan ' + (i + 1) + '/4...';
                dualShowSaveFeedback('Menyimpan ' + (i + 1) + '/4 (' + item.label + ')...', false);
                return dualPostOne(item.cap(), item.ocr(), token, sessionName).then(function (res) {
                    var id = res && res.data && res.data.id ? res.data.id : null;
                    item.setRef(id);
                    savedIds[item.key] = id;
                });
            });
        });
        chain.then(function () {
            // Safety-net idempoten: aktifkan keempat belahan sebelum ke Check.
            if (loadingText) loadingText.textContent = 'Mengaktifkan acuan...';
            if (dualSaveBtnTextEl) dualSaveBtnTextEl.textContent = 'Mengaktifkan...';
            dualShowSaveFeedback('Mengaktifkan acuan...', false);
            var allIds = [savedIds.front1, savedIds.front2, savedIds.back1, savedIds.back2].filter(function (id) { return !!id; });
            return activateReferences(allIds, token).then(function () { return savedIds; }, function (actErr) {
                try { console.warn('[REF] dual activate safety-net gagal, lanjut (server sudah auto-activate)', actErr); } catch (e) {}
                return savedIds;
            });
        }).then(function (out) {
            hideLoading();
            dualSetSaving(false);
            try { sessionStorage.setItem('last_reference_id', String(out.front1)); } catch (e) {}
            try { sessionStorage.setItem('last_reference_id_back', String(out.back1)); } catch (e) {}
            try { sessionStorage.setItem('last_reference_id_front2', String(out.front2)); } catch (e) {}
            try { sessionStorage.setItem('last_reference_id_back2', String(out.back2)); } catch (e) {}
            try { sessionStorage.setItem('dual_base_stamp', String(dualBaseStamp || '')); } catch (e) {}
            if (successTitleEl) successTitleEl.textContent = '4 Label Tersimpan';
            if (successDescEl) successDescEl.textContent = 'Front (Label 1+2) + Back (Label 1+2) tersimpan sebagai 1 set Reference.';
            showSuccess();
            // Kompatibilitas Check 2-ref: kirim belahan pertama tiap sisi; follow-up Check 4-ref terpisah.
            var target = '/check?ref=' + encodeURIComponent(out.front1) + '&ref_back=' + encodeURIComponent(out.back1);
            try { window.__refGoCheck = target; } catch (e) {}
            if (backToScanBtn) {
                backToScanBtn.disabled = false;
                backToScanBtn.innerHTML = 'Continue ke Check Label' +
                    ' <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>';
            }
            dualShowSaveFeedback('4 label tersimpan - lanjut ke Check Label.', false);
            setTimeout(function () { window.location.href = target; }, 2500);
        }).catch(function (err) {
            hideLoading();
            dualSetSaving(false);
            dualSetStageInfo('dual-ok');
            if (err && err.message === 'UNAUTHORIZED') {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                dualShowSaveFeedback('Sesi telah berakhir. Silakan login kembali.', true);
                return;
            }
            dualShowSaveFeedback('Gagal menyimpan (' + (err.message || 'error') + '). Belahan yang sudah tersimpan tidak diulang — tekan Simpan lagi.', true);
            dualUpdateSaveState();
        });
    }

    function getChooseToken() {
        try { return sessionStorage.getItem('label_inspection_token') || ''; } catch (e) { return ''; }
    }

    function stopChoosePolling() {
        if (choosePollTimer) {
            clearTimeout(choosePollTimer);
            choosePollTimer = null;
        }
        choosePollAttempts = 0;
    }

    function startChoosePolling() {
        if (choosePollTimer) return;
        if (choosePollAttempts >= CHOOSE_POLL_MAX_ATTEMPTS) {
            return;
        }
        choosePollTimer = setTimeout(function () {
            choosePollTimer = null;
            choosePollAttempts++;
            fetchChooseList(true, true);
        }, CHOOSE_POLL_INTERVAL_MS);
    }

    function refreshChooseList() {
        stopChoosePolling();
        if (chooseRefreshIcon) chooseRefreshIcon.classList.add('animate-spin');
        chooseSideMapPromise = null;
        return Promise.resolve(fetchChooseList(false, true)).finally(function () {
            if (chooseRefreshIcon) {
                setTimeout(function () { chooseRefreshIcon.classList.remove('animate-spin'); }, 400);
            }
        });
    }

    function hideChoosePanel() {
        stopChoosePolling();
        abortChooseFetch();
        if (choosePanel) choosePanel.classList.add('hidden');
    }

    function revokeChooseBlobs() {
        chooseBlobUrls.forEach(function (u) { try { URL.revokeObjectURL(u); } catch (e) {} });
        chooseBlobUrls = [];
    }

    function abortChooseFetch() {
        if (chooseAbort) { try { chooseAbort.abort(); } catch (e) {} chooseAbort = null; }
    }

    function openChoosePanel() {
        if (typeof stopCamera === 'function') { try { stopCamera(); } catch (e) {} }
        if (menuStep) menuStep.classList.add('hidden');
        if (templateStep) templateStep.classList.add('hidden');
        if (scanStep) scanStep.classList.add('hidden');
        if (choosePanel) choosePanel.classList.remove('hidden');
        if (stepIndicator) stepIndicator.textContent = 'Pilih Reference';
        stopChoosePolling();
        // Kick off peta sisi lokal paralel (best-effort) + daftar library; grouping menunggu keduanya.
        try {
            chooseSideMap = {};
            chooseSideMapPromise = fetchChooseSideMap(getChooseToken());
        } catch (e) { chooseSideMapPromise = null; }
        fetchChooseList(false, true);
    }

    function setChooseLoading(on) {
        if (chooseLoading) chooseLoading.classList.toggle('hidden', !on);
        if (on && chooseError) chooseError.classList.add('hidden');
    }

    function showChooseError(msg) {
        if (!chooseError) return;
        chooseError.textContent = msg;
        chooseError.classList.remove('hidden');
    }

    function updateChoosePager() {
        if (choosePageInfo) choosePageInfo.textContent = chooseTotal > 0
            ? ('Hal ' + choosePage + ' / ' + chooseTotalPages + ' · ' + chooseTotal + ' item')
            : '-';
        if (choosePrev) choosePrev.disabled = choosePage <= 1;
        if (chooseNext) chooseNext.disabled = choosePage >= chooseTotalPages;
    }

    // ===== Peta sisi FRONT/BACK dari data lokal (1 sesi = 1 card, anti campur sesi) =====
    // Sumber kebenaran sisi: filename lokal berpola -front/-back (ground truth),
    // BUKAN urutan tampil API library. Best-effort: gagal -> peta kosong -> fallback temporal.
    function buildChooseSideMap(rows) {
        var map = {};
        (rows || []).forEach(function (r) {
            var refId = r && r.fastapi_ref_id ? String(r.fastapi_ref_id) : '';
            var fn = r && r.filename ? String(r.filename) : '';
            if (!refId || !fn) return;
            var m = fn.match(/-(front|back)(-\d+)?(\.[a-z0-9]+)?$/i);
            if (!m) return;
            var basis = '';
            try { basis = dualBasisOf(fn); } catch (e) { basis = ''; }
            if (!basis) return;
            var idx = m[2] ? parseInt(m[2].slice(1), 10) : 1;
            if (idx !== 2) idx = 1;
            map[refId] = { side: m[1].toLowerCase(), idx: idx, sessionKey: basis, active: !!(r.active) };
        });
        return map;
    }

    function fetchChooseSideMap(token) {
        chooseSideMapPromise = fetch('/api/references?per_page=50', {
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function (res) {
            if (!res.ok) throw new Error('map HTTP ' + res.status);
            return res.json();
        }).then(function (json) {
            var map = {};
            try { map = buildChooseSideMap(json.data || []); } catch (e) { map = {}; }
            chooseSideMap = map;
            return map;
        }).catch(function () {
            chooseSideMap = {};
            return {};
        });
        return chooseSideMapPromise;
    }

    function fetchChooseList(silent, forceFresh) {
        abortChooseFetch();
        if (!silent) {
            revokeChooseBlobs();
            setChooseLoading(true);
            if (chooseGrid) chooseGrid.innerHTML = '';
            if (chooseEmpty) chooseEmpty.classList.add('hidden');
        }
        var token = getChooseToken();
        if (!token) {
            stopChoosePolling();
            if (!silent) showChooseError('Sesi telah berakhir. Silakan login kembali.');
            return Promise.reject(new Error('NO_TOKEN'));
        }

        var baseEndpoint = chooseTab === 'mine' ? '/api/references' : '/api/references-library';
        var url = baseEndpoint + '?per_page=' + CHOOSE_PER_PAGE + '&page=' + choosePage + '&q=' + encodeURIComponent(chooseQ);
        if (silent || forceFresh) {
            url += '&fresh=1';
        }

        try { chooseAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null; }
        catch (e) { chooseAbort = null; }
        var opts = { headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' } };
        if (chooseAbort && chooseAbort.signal) opts.signal = chooseAbort.signal;

        return fetch(url, opts).then(function (res) {
            if (res.status === 401) throw new Error('UNAUTHORIZED');
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        }).then(function (json) {
            chooseAbort = null;
            if (!silent) setChooseLoading(false);
            if (!json.success && chooseTab === 'global') {
                throw new Error(json.message || 'Library global tidak tersedia');
            }
            var cards, page, totalPages, total, mineGroups = null;
            if (chooseTab === 'mine') {
                var rows = json.data || [];
                var meta = json.meta || {};
                page = meta.current_page || 1;
                totalPages = meta.last_page || 1;
                total = (meta.total !== undefined) ? meta.total : rows.length;
                try { mineGroups = dualGroupMineRows(rows); } catch (e) { mineGroups = null; }
                cards = rows.map(function (r) {
                    var ocr = r.ocr_result || r.ocrResult || {};
                    // Nama popup diutamakan agar hasil simpan langsung terlihat di daftar.
                    var displayName = (r.name && String(r.name).trim() !== '')
                        ? String(r.name).trim()
                        : ((r.template_id || '-') + ' — ' + (r.template_name || r.filename || ('#' + r.id)));
                    return {
                        kind: 'mine', id: r.id,
                        title: displayName,
                        subtitle: r.filename || '-',
                        ocrStatus: ocr.status || 'unknown',
                        createdAt: r.created_at || r.updated_at || '',
                        active: !!r.active
                    };
                });
            } else {
                var items = json.items || [];
                page = json.page || 1;
                totalPages = json.total_pages || 1;
                total = (json.total !== undefined) ? json.total : items.length;
                cards = items.map(function (it) {
                    return {
                        kind: 'global', refId: it.ref_id,
                        title: it.name || it.ref_id,
                        subtitle: (it.template_id ? it.template_id + ' · ' : '') + (it.excerpt || '').slice(0, 60),
                        ocrStatus: it.ocr_status || 'unknown',
                        templateId: it.template_id || '',
                        thumbUrl: it.thumb_url || null,
                        createdAt: it.created_at || it.captured_at || it.updated_at || ''
                    };
                });
            }
            choosePage = page;
            chooseTotalPages = Math.max(1, totalPages);
            chooseTotal = total;
            // Halaman kosong (mis. item terakhir di halaman ini baru dihapus) → mundur 1 halaman.
            var gotCount = chooseTab === 'mine' ? (json.data || []).length : (json.items || []).length;
            if (gotCount === 0 && choosePage > 1) {
                choosePage = choosePage - 1;
                updateChoosePager();
                fetchChooseList();
                return;
            }
            if (chooseTab === 'mine' && mineGroups && mineGroups.length) {
                var hasDual = mineGroups.some(function (gr) { return gr.front && gr.back; });
                if (hasDual) { renderChooseDualMine(mineGroups); updateChoosePager(); return; }
            }
            if (chooseTab === 'global') {
                // 1 sesi = 1 card (FRONT atas + BACK bawah); tunggu peta sisi lokal (best-effort).
                updateChoosePager();
                if (!chooseSideMapPromise || forceFresh) {
                    try { chooseSideMapPromise = fetchChooseSideMap(token); } catch (e) { chooseSideMapPromise = null; }
                }
                Promise.resolve(chooseSideMapPromise).then(function () {
                    try { renderChooseSessionsGlobal(groupGlobalSessions(cards)); }
                    catch (e) { renderChooseGrid(cards); }
                });
                return;
            }
            renderChooseGrid(cards);
            updateChoosePager();
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            chooseAbort = null;
            if (!silent) setChooseLoading(false);
            updateChoosePager();
            if (err && err.message === 'UNAUTHORIZED') {
                stopChoosePolling();
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                showChooseError('Sesi telah berakhir. Silakan login kembali.');
            } else if (!silent) {
                if (chooseTab === 'global') {
                    showChooseError((err && err.message) || 'Library tidak tersedia. Coba lagi.');
                } else {
                    showChooseError((err && err.message) || 'Gagal memuat daftar. Coba lagi.');
                }
            }
        });
    }

    // Kelompokkan item library menjadi sesi: 1 sesi = 1 card (FRONT atas + BACK bawah).
    // Prioritas 1 (eksak): sessionKey SAMA via peta lokal. Prioritas 2 (fallback ketat):
    // success + template sama + selisih <= 10 detik + berurutan + belum berpasangan
    // (yang lebih awal = FRONT, sesuai urutan push front-dulu). Sisanya -> single.
    function groupGlobalSessions(cards) {
        var sessions = [], byKey = {}, leftovers = [];
        (cards || []).forEach(function (card) {
            if (!card) return;
            var hit = (card.refId && chooseSideMap) ? chooseSideMap[card.refId] : null;
            card.side = hit ? hit.side : '';
            card.idx = hit ? (hit.idx === 2 ? 2 : 1) : 1;
            card.sessionKey = hit ? hit.sessionKey : '';
            card.activeRef = !!(hit && hit.active);
            card.active = !!(hit && hit.active);
            if (hit && hit.sessionKey) {
                var key = 'ex:' + hit.sessionKey;
                if (!byKey[key]) { byKey[key] = { key: key, front1: null, front2: null, back1: null, back2: null, front: null, back: null, autoPair: false }; sessions.push(byKey[key]); }
                var slot = byKey[key];
                var s = hit.side;
                var ix = (hit.idx === 2 ? 2 : 1);
                var placed = false;
                if (s === 'front') {
                    if (ix === 1 && !slot.front1) { slot.front1 = card; placed = true; }
                    else if (ix === 2 && !slot.front2) { slot.front2 = card; placed = true; }
                    else if (!slot.front1) { slot.front1 = card; placed = true; }
                    else if (!slot.front2) { slot.front2 = card; placed = true; }
                } else if (s === 'back') {
                    if (ix === 1 && !slot.back1) { slot.back1 = card; placed = true; }
                    else if (ix === 2 && !slot.back2) { slot.back2 = card; placed = true; }
                    else if (!slot.back1) { slot.back1 = card; placed = true; }
                    else if (!slot.back2) { slot.back2 = card; placed = true; }
                }
                if (!placed) {
                    // Duplikat sisi tak terduga: render sebagai sesi satu sisi (mate = placeholder).
                    var dk = 'dup:' + card.refId;
                    var solo = { key: dk, front1: null, front2: null, back1: null, back2: null, front: null, back: null, autoPair: false };
                    if (s === 'front') { solo.front1 = card; solo.front = card; } else { solo.back1 = card; solo.back = card; }
                    sessions.push(solo);
                } else {
                    slot.front = slot.front1 || slot.front2 || null;
                    slot.back = slot.back1 || slot.back2 || null;
                }
            } else {
                leftovers.push(card);
            }
        });
        // Fallback temporal: hanya success + createdAt valid, urut waktu menaik.
        var pool = leftovers.filter(function (card) {
            if (!card || card.ocrStatus !== 'success' || !card.createdAt) return false;
            try { return !isNaN(new Date(card.createdAt).getTime()); } catch (e) { return false; }
        });
        var rest = leftovers.filter(function (card) { return pool.indexOf(card) === -1; });
        pool.sort(function (a, b) {
            try { return new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime(); }
            catch (e) { return 0; }
        });
        var used = {};
        var i, autoCount = 0;
        for (i = 0; i < pool.length - 1; i++) {
            if (used[i]) continue;
            var a = pool[i], b = pool[i + 1], ok = false;
            try {
                var ta = new Date(a.createdAt).getTime(), tb = new Date(b.createdAt).getTime();
                ok = !used[i + 1] && (a.templateId || '') === (b.templateId || '') && (tb - ta) >= 0 && (tb - ta) <= 10000;
            } catch (e) { ok = false; }
            if (ok) {
                used[i] = true; used[i + 1] = true; autoCount++;
                sessions.push({ key: 'auto:' + a.refId + '+' + b.refId, front1: a, front2: null, back1: b, back2: null, front: a, back: b, autoPair: true });
            }
        }
        for (i = 0; i < pool.length; i++) {
            if (!used[i]) rest.push(pool[i]);
        }
        rest.forEach(function (card) { sessions.push({ single: true, card: card }); });
        return sessions;
    }

    function chooseCardShell(card) {
        var wrap = document.createElement('div');
        wrap.className = 'flex flex-col bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl overflow-hidden shadow-sm';
        function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
        // Minimalist: header judul + Aktif sejajar, tanpa File/Capture/badge status.
        // Tanggal sesi kecil rata kanan di bawah gambar sebagai pengganti badge.
        var captureText = formatChooseDate(card.createdAt);
        wrap.innerHTML =
            '<div class="aspect-[4/3] bg-[#E9ECEF] flex items-center justify-center overflow-hidden relative">' +
                '<span class="choose-img-loading text-xs text-[#6C757D]">Memuat...</span>' +
                '<img alt="" class="hidden w-full h-full object-contain" />' +
            '</div>' +
            '<div class="p-2.5 flex flex-col gap-1 flex-1">' +
                '<div class="flex items-center gap-2">' +
                    '<p class="min-w-0 flex-1 text-[13px] font-bold text-[#212529] truncate" title="' + esc(card.title) + '">' + esc(card.title) + '</p>' +
                    (card.active ? '<span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-white text-[#212529] border-emerald-300">Aktif</span>' : '') +
                '</div>' +
                '<p class="mt-1 text-[10px] leading-tight text-[#6C757D] truncate text-right" title="' + esc(captureText) + '">' + esc(captureText) + '</p>' +
                '<div class="mt-2 flex items-center gap-2">' +
                    '<button type="button" class="choose-use-btn flex-1 min-w-0 inline-flex items-center justify-center px-2 py-1.5 rounded-xl bg-sky-600 text-white text-[13px] font-semibold transition shadow-sm hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed">Gunakan</button>' +
                    '<button type="button" title="Hapus capture ini" aria-label="Hapus capture ini" data-act="delete" class="choose-delete-btn inline-flex shrink-0 items-center justify-center gap-1 px-2.5 py-1.5 rounded-xl border border-[#E04438]/40 text-[#E04438] text-[12px] font-semibold transition hover:bg-[#E04438]/10 disabled:opacity-50 disabled:cursor-not-allowed"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>Delete</button>' +
                '</div>' +
            '</div>';
        return wrap;
    }

    function dualMineCardShell(group) {
        var wrap = document.createElement('div');
        wrap.className = 'flex flex-col bg-[#E9ECEF] border border-[#6C757D]/30 rounded-2xl overflow-hidden shadow-sm p-3';
        function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
        var f1 = group.front1 || group.front || null;
        var f2 = group.front2 || null;
        var b1 = group.back1 || group.back || null;
        var b2 = group.back2 || null;
        var front = f1 || null, back = b1 || null;
        var tpl = (front && front.template_id) || (back && back.template_id) || '-';
        var tplName = (front && front.template_name) || (back && back.template_name) || '';
        // Nama sesi popup diutamakan (front+back berbagi 1 nama sesi).
        var sessName = (front && front.name && String(front.name).trim() !== '')
            ? String(front.name).trim()
            : ((back && back.name && String(back.name).trim() !== '') ? String(back.name).trim() : '');
        var title = sessName !== '' ? esc(sessName + ' - 2 sisi') : esc(tpl + ' - ' + tplName + ' - 2 sisi');
        // Minimalist: tanpa File/Capture per sisi. Satu tanggal sesi kecil rata kanan di bawah BACK.
        // Badge Aktif tampil bila SELURUH belahan sesi active (1 set acuan kompak).
        var presentRefs = [f1, f2, b1, b2].filter(function (r) { return !!r; });
        var isActive = presentRefs.length > 0 && presentRefs.every(function (r) { return !!r.active; });
        var sessDateRaw = front ? (front.created_at || front.updated_at) : (back ? (back.created_at || back.updated_at) : '');
        var sessDate = esc(formatChooseDate(sessDateRaw));
        wrap.innerHTML =
            '<div class="flex items-center gap-2">' +
                '<p class="min-w-0 flex-1 text-sm font-bold text-[#212529] truncate" title="' + title + '">' + title + '</p>' +
                (isActive ? '<span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-white text-[#212529] border-emerald-300">Aktif</span>' : '') +
            '</div>' +
            '<div class="mt-3 flex flex-col gap-2">' +
                '<figure class="bg-[#F8F9FA] rounded-xl border border-[#6C757D]/20 p-2 flex flex-col gap-2">' +
                    '<span class="self-start px-2 py-0.5 rounded-full text-[11px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span>' +
                    '<div class="grid grid-cols-2 gap-1.5">' +
                        '<div class="relative bg-[#212529] rounded-lg overflow-hidden flex items-center justify-center min-h-[4rem]"><span class="dual-img-loading text-xs text-[#6C757D]">Memuat...</span><img alt="Front 1" class="hidden w-full h-16 sm:h-20 object-contain" /><span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">1</span></div>' +
                        '<div class="relative bg-[#212529] rounded-lg overflow-hidden flex items-center justify-center min-h-[4rem]"><span class="dual-img-loading text-xs text-[#6C757D]">Memuat...</span><img alt="Front 2" class="hidden w-full h-16 sm:h-20 object-contain" /><span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">2</span></div>' +
                    '</div>' +
                '</figure>' +
                '<figure class="bg-[#F8F9FA] rounded-xl border border-[#6C757D]/20 p-2 flex flex-col gap-2">' +
                    '<span class="self-start px-2 py-0.5 rounded-full text-[11px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span>' +
                    '<div class="grid grid-cols-2 gap-1.5">' +
                        '<div class="relative bg-[#212529] rounded-lg overflow-hidden flex items-center justify-center min-h-[4rem]"><span class="dual-img-loading text-xs text-[#6C757D]">Memuat...</span><img alt="Back 1" class="hidden w-full h-16 sm:h-20 object-contain" /><span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">1</span></div>' +
                        '<div class="relative bg-[#212529] rounded-lg overflow-hidden flex items-center justify-center min-h-[4rem]"><span class="dual-img-loading text-xs text-[#6C757D]">Memuat...</span><img alt="Back 2" class="hidden w-full h-16 sm:h-20 object-contain" /><span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">2</span></div>' +
                    '</div>' +
                    '<p class="text-[10px] leading-tight text-[#6C757D] truncate text-right" title="' + sessDate + '">' + sessDate + '</p>' +
                '</figure>' +
            '</div>' +
            '<div class="mt-3 flex items-center gap-2">' +
                '<button type="button" class="dual-use-btn flex-1 min-w-0 inline-flex items-center justify-center px-2 py-2 rounded-xl bg-[#0284C7] text-white text-[13px] font-semibold transition shadow-sm hover:bg-[#0369A1] disabled:opacity-50 disabled:cursor-not-allowed">Gunakan</button>' +
                '<button type="button" title="Hapus capture ini" aria-label="Hapus capture ini" data-act="delete" class="choose-delete-btn inline-flex shrink-0 items-center justify-center gap-1 px-2.5 py-2 rounded-xl border border-[#E04438]/40 text-[#E04438] text-[12px] font-semibold transition hover:bg-[#E04438]/10 disabled:opacity-50 disabled:cursor-not-allowed"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>Delete</button>' +
            '</div>';
        return wrap;
    }

    function renderChooseDualMine(groups) {
        if (!chooseGrid) return;
        chooseGrid.innerHTML = '';
        if (!groups.length) { if (chooseEmpty) chooseEmpty.classList.remove('hidden'); stopChoosePolling(); return; }
        if (chooseEmpty) chooseEmpty.classList.add('hidden');
        var token = getChooseToken();
        var anyPendingOnPage = false;
        groups.forEach(function (group) {
            if (group.single) {
                var r = group.front;
                var ocr = r.ocr_result || r.ocrResult || {};
                var singleTitle = (r.name && String(r.name).trim() !== '')
                    ? String(r.name).trim()
                    : ((r.template_id || '-') + ' - ' + (r.template_name || r.filename));
                var singleCard = { kind: 'mine', id: r.id, title: singleTitle, subtitle: r.filename || '-', ocrStatus: ocr.status || 'unknown', createdAt: r.created_at || r.updated_at || '', active: !!r.active };
                var el = chooseCardShell(singleCard);
                chooseGrid.appendChild(el);
                var img = el.querySelector('img');
                var loading = el.querySelector('.choose-img-loading');
                var btn = el.querySelector('.choose-use-btn');
                fetch('/api/references/' + encodeURIComponent(r.id) + '/image', {
                    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'image/*' }
                }).then(function (res) {
                    if (!res.ok) throw new Error('img');
                    return res.blob();
                }).then(function (blob) {
                    var u = URL.createObjectURL(blob);
                    chooseBlobUrls.push(u);
                    img.src = u;
                    img.classList.remove('hidden');
                    if (loading) loading.remove();
                }).catch(function () { if (loading) loading.textContent = 'Gambar tak tersedia'; });
                btn.addEventListener('click', function () { useMineReference(r.id, btn); });
                var delBtnDualSolo = el.querySelector('.choose-delete-btn');
                if (delBtnDualSolo) delBtnDualSolo.addEventListener('click', function () {
                    openDeleteModal({ files: [{ label: 'File', name: r.filename || '-' }], urls: ['/api/references/' + encodeURIComponent(r.id)] });
                });
                if (singleCard.ocrStatus === 'pending') {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Menunggu OCR...</span>';
                    anyPendingOnPage = true;
                }
                return;
            }
            var del = dualMineCardShell(group);
            chooseGrid.appendChild(del);
            var imgs = del.querySelectorAll('img');
            var loadings = del.querySelectorAll('.dual-img-loading');
            var useBtn = del.querySelector('.dual-use-btn');
            var gF1 = group.front1 || group.front || null;
            var gF2 = group.front2 || null;
            var gB1 = group.back1 || group.back || null;
            var gB2 = group.back2 || null;
            var isFourFile = !!(group.front2 || group.back2 || (gF1 && gF2) || (gB1 && gB2) || (gF1 && gF2 && gB1 && gB2));
            // Heuristik sesi 4-file: ada belahan idx-2 ATAU keempat slot terisi sebagian.
            // Sesi lama 2-file (tanpa idx) tetap 2 slot agar kompatibel.
            if (!isFourFile) {
                var filledCount = [gF1, gF2, gB1, gB2].filter(function (r) { return !!r; }).length;
                if (filledCount > 2) isFourFile = true;
            }
            function loadSide(ref, idx, waitLabel) {
                if (!ref) { if (loadings[idx]) loadings[idx].textContent = waitLabel || 'Menunggu sisi'; return; }
                fetch('/api/references/' + encodeURIComponent(ref.id) + '/image', {
                    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'image/*' }
                }).then(function (res) {
                    if (!res.ok) throw new Error('img');
                    return res.blob();
                }).then(function (blob) {
                    var u = URL.createObjectURL(blob);
                    chooseBlobUrls.push(u);
                    imgs[idx].src = u;
                    imgs[idx].classList.remove('hidden');
                    if (loadings[idx]) loadings[idx].remove();
                }).catch(function () { if (loadings[idx]) loadings[idx].textContent = 'Gambar tak tersedia'; });
            }
            loadSide(gF1, 0, 'Menunggu sisi FRONT');
            loadSide(gF2, 1, 'Menunggu sisi FRONT');
            loadSide(gB1, 2, 'Menunggu sisi BACK');
            loadSide(gB2, 3, 'Menunggu sisi BACK');
            function mineOcrStatus(ref) {
                if (!ref) return 'missing';
                var o = ref.ocr_result || ref.ocrResult || {};
                return o.status || 'unknown';
            }
            var presentMine = [gF1, gF2, gB1, gB2].filter(function (r) { return !!r; });
            var needMine = isFourFile ? 4 : 2;
            var missingMine = presentMine.length < needMine;
            // Untuk sesi lama 2-file, kebutuhan = front1+back1.
            if (!isFourFile) missingMine = !(gF1 && gB1);
            var hasFailedMine = presentMine.some(function (r) { return mineOcrStatus(r) === 'failed'; });
            var hasPendingMine = presentMine.some(function (r) {
                var s = mineOcrStatus(r);
                return s !== 'success' && s !== 'failed';
            });
            if (hasPendingMine) anyPendingOnPage = true;
            if (missingMine) { useBtn.disabled = true; useBtn.textContent = 'Menunggu pasangan'; useBtn.title = 'Belahan sesi belum lengkap'; }
            else if (hasFailedMine) { useBtn.disabled = true; useBtn.textContent = 'OCR Gagal'; useBtn.title = 'OCR gagal — buat scan baru untuk label ini'; }
            else if (hasPendingMine) {
                useBtn.disabled = true;
                useBtn.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Menunggu OCR...</span>';
                useBtn.title = 'OCR masih diproses';
            }
            else {
                useBtn.addEventListener('click', function () {
                    var dualToken = getChooseToken();
                    if (!dualToken) return;
                    useBtn.disabled = true;
                    useBtn.textContent = 'Mengaktifkan...';
                    var ids = [gF1, gF2, gB1, gB2].filter(function (r) { return !!r; }).map(function (r) { return r.id; });
                    if (!ids.length) {
                        if (group.front) ids.push(group.front.id);
                        if (group.back) ids.push(group.back.id);
                    }
                    activateReferences(ids, dualToken).then(function () {
                        try { sessionStorage.setItem('last_reference_id', String((gF1 || group.front).id)); } catch (e) {}
                        try { sessionStorage.setItem('last_reference_id_back', String((gB1 || group.back).id)); } catch (e) {}
                        window.location.href = '/check?ref=' + encodeURIComponent((gF1 || group.front).id) + '&ref_back=' + encodeURIComponent((gB1 || group.back).id);
                    }).catch(function (err) {
                        useBtn.disabled = false;
                        useBtn.textContent = 'Gunakan';
                        showChooseError((err && err.message) || 'Gagal mengaktifkan acuan. Coba lagi.');
                    });
                });
            }
            var dualDelBtn = del.querySelector('.choose-delete-btn');
            if (dualDelBtn) dualDelBtn.addEventListener('click', function () {
                var files = [], urls = [];
                if (gF1) { files.push({ label: 'FRONT 1', name: gF1.filename || '-' }); }
                if (gF2) { files.push({ label: 'FRONT 2', name: gF2.filename || '-' }); }
                if (gB1) { files.push({ label: 'BACK 1', name: gB1.filename || '-' }); }
                if (gB2) { files.push({ label: 'BACK 2', name: gB2.filename || '-' }); }
                // Hapus 1 sesi atomik (4 belahan sekaligus) agar tak yatim.
                // session_key dari baris (fallback basis filename pra-migrasi).
                var sessKey = (gF1 && gF1.session_key) || (gF2 && gF2.session_key) || (gB1 && gB1.session_key) || (gB2 && gB2.session_key) || (group.front && group.front.session_key) || (group.back && group.back.session_key) || group.basis || '';
                if (sessKey) {
                    urls.push('/api/references/session/' + encodeURIComponent(sessKey));
                } else {
                    if (gF1) urls.push('/api/references/' + encodeURIComponent(gF1.id));
                    if (gF2) urls.push('/api/references/' + encodeURIComponent(gF2.id));
                    if (gB1) urls.push('/api/references/' + encodeURIComponent(gB1.id));
                    if (gB2) urls.push('/api/references/' + encodeURIComponent(gB2.id));
                }
                openDeleteModal({ files: files, urls: urls });
            });
        });
        if (anyPendingOnPage) {
            startChoosePolling();
        } else {
            stopChoosePolling();
        }
    }

    function renderChooseGrid(cards) {
        if (!chooseGrid) return;
        chooseGrid.innerHTML = '';
        if (!cards.length) {
            if (chooseEmpty) chooseEmpty.classList.remove('hidden');
            stopChoosePolling();
            return;
        }
        if (chooseEmpty) chooseEmpty.classList.add('hidden');
        var token = getChooseToken();
        var anyPendingOnPage = false;
        cards.forEach(function (card) {
            var el = chooseCardShell(card);
            chooseGrid.appendChild(el);
            var img = el.querySelector('img');
            var loading = el.querySelector('.choose-img-loading');
            var btn = el.querySelector('.choose-use-btn');

            if (card.kind === 'mine') {
                // Gambar milik sendiri: fetch auth -> blob (img tag tak bisa kirim Bearer)
                fetch('/api/references/' + encodeURIComponent(card.id) + '/image', {
                    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'image/*' }
                }).then(function (res) {
                    if (!res.ok) throw new Error('img');
                    return res.blob();
                }).then(function (blob) {
                    var u = URL.createObjectURL(blob);
                    chooseBlobUrls.push(u);
                    img.src = u;
                    img.classList.remove('hidden');
                    if (loading) loading.remove();
                }).catch(function () {
                    if (loading) loading.textContent = 'Gambar tak tersedia';
                });
                btn.addEventListener('click', function () { useMineReference(card.id, btn); });
                var delBtnMine = el.querySelector('.choose-delete-btn');
                if (delBtnMine) delBtnMine.addEventListener('click', function () {
                    openDeleteModal({ files: [{ label: 'File', name: card.subtitle || '-' }], urls: ['/api/references/' + encodeURIComponent(card.id)] });
                });
                if (card.ocrStatus === 'failed') {
                    btn.disabled = true;
                    btn.textContent = 'OCR Gagal';
                    btn.title = 'OCR gagal — buat scan baru untuk label ini';
                } else if (card.ocrStatus === 'pending') {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Menunggu OCR...</span>';
                    btn.title = 'OCR masih diproses di latar belakang';
                    anyPendingOnPage = true;
                }
            } else {
                // Library Global: thumb public FastAPI, langsung <img> (tanpa auth/CORS issue)
                if (card.thumbUrl) {
                    img.src = card.thumbUrl;
                    img.onload = function () { img.classList.remove('hidden'); if (loading) loading.remove(); };
                    img.onerror = function () { if (loading) loading.textContent = 'Gambar tak tersedia'; };
                } else if (loading) {
                    loading.textContent = 'Tanpa pratinjau';
                }
                btn.addEventListener('click', function () { useGlobalReference(card, btn); });
                var delBtnLib = el.querySelector('.choose-delete-btn');
                if (delBtnLib) delBtnLib.addEventListener('click', function () {
                    openDeleteModal({ files: [{ label: 'File', name: card.refId || '-' }], urls: ['/api/references-library/' + encodeURIComponent(card.refId)] });
                });
                if (card.ocrStatus === 'failed') {
                    btn.disabled = true;
                    btn.textContent = 'OCR Gagal';
                } else if (card.ocrStatus === 'pending') {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Menunggu OCR...</span>';
                    btn.title = 'OCR masih diproses di latar belakang';
                    anyPendingOnPage = true;
                }
            }
        });
        if (anyPendingOnPage) {
            startChoosePolling();
        } else {
            stopChoosePolling();
        }
    }

    // 1 sesi = 1 card: blok FRONT di ATAS, blok BACK di BAWAH (tanpa teks hasil OCR).
    // Tiap blok berisi 2 thumb berdampingan (Label 1 + Label 2).
    function globalSessionCardShell(sess) {
        var wrap = document.createElement('div');
        wrap.className = 'flex flex-col bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl overflow-hidden shadow-sm p-2.5';
        function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
        var f1 = sess.front1 || sess.front || null;
        var f2 = sess.front2 || null;
        var b1 = sess.back1 || sess.back || null;
        var b2 = sess.back2 || null;
        var t = f1 || b1 || f2 || b2 || {};
        var title = esc((t.templateId ? t.templateId + ' — ' : '') + (t.title || '-'));
        var earliestRaw = '';
        try {
            var times = [f1, f2, b1, b2].filter(function (c) { return c && c.createdAt; }).map(function (c) { return new Date(c.createdAt).getTime(); }).filter(function (v) { return !isNaN(v); });
            if (times.length) {
                var minT = Math.min.apply(null, times);
                var pick = [f1, f2, b1, b2].filter(function (c) { return c && c.createdAt && new Date(c.createdAt).getTime() === minT; })[0];
                earliestRaw = pick ? pick.createdAt : '';
            }
        } catch (e) {}
        var presentAll = [f1, f2, b1, b2].filter(function (c) { return !!c; });
        var sessActive = presentAll.length > 0 && presentAll.every(function (c) { return !!(c.activeRef || c.active); });
        // Minimalist: tanpa File/Capture per sisi, tanpa badge status. Satu tanggal sesi kecil rata kanan di bawah BACK.
        var sessDate = esc(formatChooseDate(earliestRaw));
        var isFour = !!(sess.front2 || sess.back2 || presentAll.length > 2);
        var waitingPair = isFour ? !(f1 && f2 && b1 && b2) : (!f1 || !b1);
        var sessDateLine = waitingPair ? ('menunggu pasangan · ' + sessDate) : sessDate;
        function thumbCell(card, label, num) {
            var numTag = '<span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">' + num + '</span>';
            if (card && card.thumbUrl) {
                return '<div class="relative bg-[#212529] rounded-lg overflow-hidden flex items-center justify-center"><span class="choose-img-loading text-xs text-[#6C757D]">Memuat...</span>' +
                    '<img alt="' + label + ' ' + num + '" src="' + esc(card.thumbUrl) + '" class="choose-side-img hidden w-full h-16 sm:h-20 object-contain" />' + numTag + '</div>';
            } else if (card) {
                return '<div class="relative bg-[#212529] rounded-lg flex items-center justify-center h-16 sm:h-20"><span class="text-[11px] text-[#6C757D]">Tanpa pratinjau</span>' + numTag + '</div>';
            } else {
                return '<div class="relative rounded-lg border border-dashed border-[#6C757D]/40 flex items-center justify-center h-16 sm:h-20 bg-[#F8F9FA]"><span class="text-[11px] text-[#6C757D]">Menunggu sisi ' + label + '</span>' + numTag + '</div>';
            }
        }
        function sideBlock(label, card1, card2) {
            var hasAny = !!(card1 || card2);
            var pillCls = hasAny
                ? 'bg-[#0284C7] text-white border-[#0284C7]'
                : 'bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
            var html = '<figure class="bg-[#E9ECEF] rounded-xl border border-[#6C757D]/20 p-2 flex flex-col gap-1">' +
                '<span class="self-start px-2 py-0.5 rounded-full text-[10px] font-bold border ' + pillCls + '">' + label + '</span>';
            html += '<div class="grid grid-cols-2 gap-1.5">' + thumbCell(card1, label, '1') + thumbCell(card2, label, '2') + '</div>';
            return html + '</figure>';
        }
        wrap.innerHTML =
            '<div class="flex items-center gap-2">' +
                '<p class="min-w-0 flex-1 text-[13px] font-bold text-[#212529] truncate" title="' + title + '">' + title + '</p>' +
                (sessActive ? '<span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-white text-[#212529] border-emerald-300">Aktif</span>' : '') +
            '</div>' +
            (sess.autoPair ? '<p class="mt-1 text-[10px] leading-tight text-[#6C757D]">Pasangan otomatis · pastikan sesi sama</p>' : '') +
            '<div class="mt-2 flex flex-col gap-2">' +
                sideBlock('FRONT', f1, f2) +
                sideBlock('BACK', b1, b2) +
            '</div>' +
            '<p class="mt-2 text-[10px] leading-tight text-[#6C757D] truncate text-right" title="' + esc(sessDateLine) + '">' + esc(sessDateLine) + '</p>' +
            '<div class="mt-2 flex items-center gap-2">' +
                '<button type="button" class="choose-use-btn flex-1 min-w-0 inline-flex items-center justify-center px-2 py-1.5 rounded-xl bg-sky-600 text-white text-[13px] font-semibold transition shadow-sm hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed">Gunakan</button>' +
                '<button type="button" title="Hapus capture ini" aria-label="Hapus capture ini" data-act="delete" class="choose-delete-btn inline-flex shrink-0 items-center justify-center gap-1 px-2.5 py-1.5 rounded-xl border border-[#E04438]/40 text-[#E04438] text-[12px] font-semibold transition hover:bg-[#E04438]/10 disabled:opacity-50 disabled:cursor-not-allowed"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>Delete</button>' +
            '</div>';
        return wrap;
    }

    function renderChooseSessionsGlobal(sessions) {
        if (!chooseGrid) return;
        chooseGrid.innerHTML = '';
        if (!sessions.length) {
            if (chooseEmpty) chooseEmpty.classList.remove('hidden');
            stopChoosePolling();
            return;
        }
        if (chooseEmpty) chooseEmpty.classList.add('hidden');
        var anyPendingOnPage = false;
        sessions.forEach(function (sess) {
            // Single tak-berpasangan: card tunggal existing (tanpa excerpt) + wiring global existing.
            if (sess.single) {
                var card = sess.card;
                var el = chooseCardShell(card);
                chooseGrid.appendChild(el);
                var img = el.querySelector('img');
                var loading = el.querySelector('.choose-img-loading');
                var btn = el.querySelector('.choose-use-btn');
                if (card.thumbUrl) {
                    img.src = card.thumbUrl;
                    img.onload = function () { img.classList.remove('hidden'); if (loading) loading.remove(); };
                    img.onerror = function () { if (loading) loading.textContent = 'Gambar tak tersedia'; };
                } else if (loading) {
                    loading.textContent = 'Tanpa pratinjau';
                }
                btn.addEventListener('click', function () { useGlobalReference(card, btn); });
                var delBtnSolo = el.querySelector('.choose-delete-btn');
                if (delBtnSolo) delBtnSolo.addEventListener('click', function () {
                    openDeleteModal({ files: [{ label: 'File', name: card.refId || '-' }], urls: ['/api/references-library/' + encodeURIComponent(card.refId)] });
                });
                if (card.ocrStatus === 'failed') {
                    btn.disabled = true;
                    btn.textContent = 'OCR Gagal';
                } else if (card.ocrStatus === 'pending') {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Menunggu OCR...</span>';
                    btn.title = 'OCR masih diproses di latar belakang';
                    anyPendingOnPage = true;
                }
                return;
            }
            var del = globalSessionCardShell(sess);
            chooseGrid.appendChild(del);
            var useBtn = del.querySelector('.choose-use-btn');
            Array.prototype.forEach.call(del.querySelectorAll('img.choose-side-img'), function (im) {
                var ld = im.parentNode ? im.parentNode.querySelector('.choose-img-loading') : null;
                // Gambar sudah ada src (thumb public): bila cached dan complete, tampilkan langsung.
                try {
                    if (im.complete && im.naturalWidth > 0) { im.classList.remove('hidden'); if (ld) ld.remove(); return; }
                } catch (e) {}
                im.onload = function () { im.classList.remove('hidden'); if (ld) ld.remove(); };
                im.onerror = function () { if (ld) ld.textContent = 'Gambar tak tersedia'; };
            });
            var gF1 = sess.front1 || sess.front || null;
            var gF2 = sess.front2 || null;
            var gB1 = sess.back1 || sess.back || null;
            var gB2 = sess.back2 || null;
            var presentG = [gF1, gF2, gB1, gB2].filter(function (c) { return !!c; });
            var isFourG = !!(sess.front2 || sess.back2 || presentG.length > 2);
            var missingG = isFourG ? (presentG.length < 4) : (!(gF1 && gB1));
            var hasFailedG = presentG.some(function (c) { return (c.ocrStatus || '') === 'failed'; });
            var hasPendingG = presentG.some(function (c) {
                var s = c.ocrStatus || '';
                return s !== 'success' && s !== 'failed';
            });
            if (hasPendingG) anyPendingOnPage = true;

            if (!missingG && !hasFailedG && !hasPendingG) {
                useBtn.addEventListener('click', function () { useGlobalPair(gF1, gF2, gB1, gB2, useBtn); });
            } else {
                useBtn.disabled = true;
                if (missingG) {
                    useBtn.textContent = 'Menunggu pasangan';
                } else if (hasFailedG) {
                    useBtn.textContent = 'OCR Gagal';
                } else {
                    useBtn.innerHTML = '<span class="inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Menunggu OCR...</span>';
                    useBtn.title = 'OCR masih diproses di latar belakang';
                }
            }
            var delBtnSess = del.querySelector('.choose-delete-btn');
            if (delBtnSess) delBtnSess.addEventListener('click', function () {
                var files = [], urls = [];
                if (gF1) { files.push({ label: 'FRONT 1', name: gF1.refId || '-' }); urls.push('/api/references-library/' + encodeURIComponent(gF1.refId)); }
                if (gF2) { files.push({ label: 'FRONT 2', name: gF2.refId || '-' }); urls.push('/api/references-library/' + encodeURIComponent(gF2.refId)); }
                if (gB1) { files.push({ label: 'BACK 1', name: gB1.refId || '-' }); urls.push('/api/references-library/' + encodeURIComponent(gB1.refId)); }
                if (gB2) { files.push({ label: 'BACK 2', name: gB2.refId || '-' }); urls.push('/api/references-library/' + encodeURIComponent(gB2.refId)); }
                openDeleteModal({ files: files, urls: urls });
            });
        });

        if (anyPendingOnPage) {
            startChoosePolling();
        } else {
            stopChoosePolling();
        }
    }

    // Jalur B sesi: import FRONT 1+2 lalu BACK 1+2 berurutan (dedup server aman),
    // lalu ke /check?ref=&ref_back=. Tetap TANPA scan/OCR ulang.
    // session_key bersama agar 4 import terikat 1 sesi (badge Aktif kompak).
    // Format library lama tanpa idx (2 belahan) tetap jalan: hanya yang ada yang diimport.
    function useGlobalPair(f1, f2, b1, b2, btn) {
        // Kompatibel pemanggil lama 3-arg (front, back, btn).
        if (arguments.length === 3) {
            btn = b1; b1 = f2; f2 = null; b2 = null;
        }
        var front1 = f1 || null, front2 = f2 || null, back1 = b1 || null, back2 = b2 || null;
        var token = getChooseToken();
        if (!token || (!front1 && !back1)) return;
        var libSession = 'lib-' + Date.now().toString(36) + Math.floor(Math.random() * 1296).toString(36);
        libSession = libSession.replace(/[^A-Za-z0-9\-_]/g, '').slice(0, 64);
        function payloadFor(card, side) {
            var meta = (card.templateId && templateMetaById(card.templateId)) || templateMetaById('M');
            return {
                fastapi_ref_id: card.refId,
                template_id: meta.id,
                template_name: meta.name,
                template_width: meta.width * 10,
                template_height: meta.height * 10,
                session_key: libSession,
                side: side
            };
        }
        function postOne(card, side) {
            return fetch('/api/references/import', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payloadFor(card, side))
            }).then(function (res) {
                if (!res.ok) return res.json().then(function (d) { throw new Error((d && d.message) || 'Gagal menyalin'); });
                return res.json();
            });
        }
        var queue = [];
        if (front1) queue.push({ card: front1, side: 'front', key: 'f1' });
        if (front2) queue.push({ card: front2, side: 'front', key: 'f2' });
        if (back1) queue.push({ card: back1, side: 'back', key: 'b1' });
        if (back2) queue.push({ card: back2, side: 'back', key: 'b2' });
        if (!queue.length) return;
        var total = queue.length;
        if (btn) { btn.disabled = true; btn.textContent = 'Menyalin 1/' + total + '...'; }
        var chain = Promise.resolve();
        var out = {};
        queue.forEach(function (item, i) {
            chain = chain.then(function () {
                if (btn) btn.textContent = 'Menyalin ' + (i + 1) + '/' + total + '...';
                return postOne(item.card, item.side).then(function (j) { out[item.key] = (j.data || {}); });
            });
        });
        chain.then(function () {
            var ids = [out.f1, out.f2, out.b1, out.b2].filter(function (d) { return d && d.id; }).map(function (d) { return d.id; });
            if (!ids.length) throw new Error('Gagal menyalin');
            if (btn) btn.textContent = 'Mengaktifkan...';
            return activateReferences(ids, token).then(function () { return out; });
        }).then(function () {
            var rf1 = out.f1 || out.f2 || {};
            var rb1 = out.b1 || out.b2 || {};
            if (!rf1.id || !rb1.id) throw new Error('Gagal menyalin');
            try { sessionStorage.setItem('last_reference_id', String(rf1.id)); } catch (e) {}
            try { sessionStorage.setItem('last_reference_ocr', JSON.stringify(rf1.ocr_result || {})); } catch (e) {}
            window.location.href = '/check?ref=' + encodeURIComponent(rf1.id) + '&ref_back=' + encodeURIComponent(rb1.id);
        }).catch(function (err) {
            if (btn) { btn.disabled = false; btn.textContent = 'Gunakan'; }
            showChooseError((err && err.message) || 'Gagal memakai sesi ini. Coba lagi.');
        });
    }

    // ===== Delete card Choose: popup konfirmasi + hapus DB & daftar =====
    function openDeleteModal(target) {
        deleteTarget = target || null;
        if (!deleteModal || !deleteTarget) return;
        if (deleteModalFiles) {
            deleteModalFiles.innerHTML = '';
            (deleteTarget.files || []).forEach(function (fl) {
                var li = document.createElement('li');
                li.className = 'flex items-center gap-2 bg-white border border-[#6C757D]/20 rounded-lg px-3 py-2 min-w-0';
                var lab = document.createElement('span');
                lab.className = 'shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
                lab.textContent = fl.label || 'File';
                var nm = document.createElement('span');
                nm.className = 'text-[11px] text-[#212529] truncate';
                nm.textContent = fl.name || '-';
                nm.title = fl.name || '-';
                li.appendChild(lab);
                li.appendChild(nm);
                deleteModalFiles.appendChild(li);
            });
        }
        if (deleteModalError) { deleteModalError.textContent = ''; deleteModalError.classList.add('hidden'); }
        setDeleteBusy(false);
        deleteModal.classList.remove('hidden');
        try { if (deleteModalCancel) deleteModalCancel.focus(); } catch (e) {}
    }

    function closeDeleteModal() {
        if (deleteBusy) return;
        deleteTarget = null;
        if (deleteModal) deleteModal.classList.add('hidden');
    }

    // ===== Nama reference label: popup form (STAGE-B fungsional) =====
    // Alur: Simpan 2 Label -> openRefnameModal -> confirmRefnameModal(name)
    // -> dualSaveBoth(name) -> 2x POST /api/references + field `name`.
    // Nama tersimpan di kolom references.name (1 UPDATE per sisi, tanpa query tambahan)
    // + diteruskan ke library FastAPI sebagai ref_library.name.
    function openRefnameModal() {
        if (!refnameModal) return;
        // Jangan reset bila user baru saja mengetik lalu modal dibuka ulang karena validasi gagal.
        if (pendingRefName && refnameInput && !refnameInput.value) { refnameInput.value = pendingRefName; }
        else if (!pendingRefName && refnameInput) { /* biarkan nilai terakhir bila ada */ }
        if (refnameError) { refnameError.textContent = ''; refnameError.classList.add('hidden'); }
        validateRefname();
        refnameModal.classList.remove('hidden');
        try { if (refnameInput) refnameInput.focus(); } catch (e) {}
    }

    function closeRefnameModal() {
        if (refnameModal) refnameModal.classList.add('hidden');
        try { if (dualSaveBtnEl) dualSaveBtnEl.focus(); } catch (e) {}
    }

    function validateRefname() {
        var v = refnameInput ? refnameInput.value.trim() : '';
        var ok = v.length >= REFNAME_MIN_LEN && v.length <= REFNAME_MAX_LEN;
        if (refnameConfirm) refnameConfirm.disabled = !ok;
        if (ok && refnameError) { refnameError.textContent = ''; refnameError.classList.add('hidden'); }
        return ok;
    }

    function confirmRefnameModal() {
        var v = refnameInput ? refnameInput.value.trim() : '';
        if (v.length < REFNAME_MIN_LEN) {
            if (refnameError) { refnameError.textContent = 'Nama minimal ' + REFNAME_MIN_LEN + ' karakter.'; refnameError.classList.remove('hidden'); }
            try { if (refnameInput) refnameInput.focus(); } catch (e) {}
            return;
        }
        if (v.length > REFNAME_MAX_LEN) {
            if (refnameError) { refnameError.textContent = 'Nama maksimal ' + REFNAME_MAX_LEN + ' karakter.'; refnameError.classList.remove('hidden'); }
            try { if (refnameInput) refnameInput.focus(); } catch (e) {}
            return;
        }
        pendingRefName = v;
        // Fungsional: tutup popup lalu langsung simpan 2 sisi dengan nama ini.
        // dualSaveBoth memvalidasi ulang + menampilkan error server bila 422.
        closeRefnameModal();
        try { dualSaveBoth(pendingRefName); } catch (e) {
            if (refnameError) { refnameError.textContent = 'Gagal menyimpan. Coba lagi.'; refnameError.classList.remove('hidden'); }
            openRefnameModal();
        }
    }

    function setDeleteBusy(busy) {
        deleteBusy = !!busy;
        if (deleteModalConfirm) {
            deleteModalConfirm.disabled = deleteBusy;
            deleteModalConfirm.textContent = deleteBusy ? 'Menghapus...' : 'Ya, Hapus';
        }
        if (deleteModalCancel) deleteModalCancel.disabled = deleteBusy;
    }

    function showDeleteError(msg) {
        if (!deleteModalError) return;
        deleteModalError.textContent = msg || 'Gagal menghapus. Coba lagi.';
        deleteModalError.classList.remove('hidden');
    }

    function deleteOne(url, token) {
        return fetch(url, {
            method: 'DELETE',
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function (res) {
            if (res.ok) return res.json().catch(function () { return {}; });
            return res.json().then(function (d) {
                var err = new Error((d && d.message) || ('Gagal menghapus (HTTP ' + res.status + ')'));
                err.status = res.status;
                err.code = d && d.error ? d.error : '';
                err.data = d && d.data ? d.data : null;
                throw err;
            }, function () {
                var err2 = new Error('Gagal menghapus (HTTP ' + res.status + ')');
                err2.status = res.status;
                throw err2;
            });
        });
    }

    function confirmDeleteModal() {
        if (!deleteTarget || deleteBusy) return;
        var token = getChooseToken();
        if (!token) { showDeleteError('Sesi telah berakhir. Silakan login kembali.'); return; }
        var urls = deleteTarget.urls || [];
        if (!urls.length) { showDeleteError('Target hapus tidak valid.'); return; }
        // Retry paksa setelah 409 recently_used: tambahkan ?force=1.
        if (deleteTarget.force) {
            urls = urls.map(function (u) { return u + (u.indexOf('?') === -1 ? '?force=1' : '&force=1'); });
        }
        setDeleteBusy(true);
        if (deleteModalError) deleteModalError.classList.add('hidden');
        // Hapus berurutan (FRONT lalu BACK / 1 sesi): berhenti di kegagalan pertama agar tak yatim.
        var chain = Promise.resolve();
        urls.forEach(function (u) { chain = chain.then(function () { return deleteOne(u, token); }); });
        chain.then(function () {
            setDeleteBusy(false);
            if (deleteModalConfirm) deleteModalConfirm.textContent = 'Ya, Hapus';
            if (deleteTarget) deleteTarget.force = false;
            if (deleteModal) deleteModal.classList.add('hidden');
            deleteTarget = null;
            try { chooseSideMapPromise = fetchChooseSideMap(getChooseToken()); } catch (e) {} // peta sisi ikut segar
            try { fetchChooseList(); } catch (e) {} // refresh daftar dari server (hitungan akurat)
        }).catch(function (err) {
            setDeleteBusy(false);
            if (err && err.code === 'recently_used') {
                // Check baru saja memakai acuan (<10 mnt): tawarkan hapus paksa, jangan tutup modal.
                var info = '';
                try {
                    if (err.data && err.data.batch_code) info = ' (' + err.data.batch_code + ')';
                } catch (e) {}
                showDeleteError((err.message || 'Check baru saja memakai acuan ini') + info + ' — tekan Tetap hapus untuk lanjut, riwayat Check tetap tersimpan.');
                if (deleteModalConfirm) deleteModalConfirm.textContent = 'Tetap hapus';
                if (deleteTarget) deleteTarget.force = true;
                return;
            }
            if (deleteModalConfirm) deleteModalConfirm.textContent = 'Ya, Hapus';
            if (deleteTarget) deleteTarget.force = false;
            showDeleteError((err && err.message) || 'Gagal menghapus. Coba lagi.');
        });
    }

    // Jalur B — pakai milik sendiri: TANPA capture/OCR, langsung ke /check?ref=id
    // Aktivasi acuan (flag active): redirect HANYA bila sukses agar Check tak salah acuan.
    function activateReferences(ids, token) {
        return fetch('/api/references/activate', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ ids: ids })
        }).then(function (res) {
            if (res.status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                throw new Error('Sesi telah berakhir. Silakan login kembali.');
            }
            if (!res.ok) return res.json().then(function (d) { throw new Error((d && d.message) || 'Gagal mengaktifkan acuan'); });
            return res.json();
        });
    }

    function useMineReference(id, btn) {
        var token = getChooseToken();
        if (!token) return;
        if (btn) { btn.disabled = true; btn.textContent = 'Mengaktifkan...'; }
        activateReferences([id], token).then(function () {
            if (btn) btn.textContent = 'Membuka...';
            return fetch('/api/references/' + encodeURIComponent(id), {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
            }).then(function (res) {
                if (!res.ok) throw new Error('fetch');
                return res.json();
            });
        }).then(function (json) {
            var data = json.data || {};
            try { sessionStorage.setItem('last_reference_id', String(data.id || id)); } catch (e) {}
            try { sessionStorage.setItem('last_reference_ocr', JSON.stringify(data.ocr_result || data.ocrResult || {})); } catch (e) {}
            window.location.href = '/check?ref=' + encodeURIComponent(data.id || id);
        }).catch(function (err) {
            if (btn) { btn.disabled = false; btn.textContent = 'Gunakan'; }
            showChooseError((err && err.message) || 'Gagal membuka reference. Coba lagi.');
        });
    }

    function templateMetaById(tid) {
        for (var i = 0; i < TEMPLATES.length; i++) {
            if (TEMPLATES[i].id === tid) return TEMPLATES[i];
        }
        return null;
    }

    // Jalur B — pakai dari Library Global: import server-to-server jadi milik
    // sendiri (dedup otomatis), lalu ke /check?ref=id. Tetap TANPA scan/OCR ulang.
    function useGlobalReference(card, btn) {
        var token = getChooseToken();
        if (!token) return;
        var meta = (card.templateId && templateMetaById(card.templateId)) || templateMetaById('M');
        if (btn) { btn.disabled = true; btn.textContent = 'Menyalin...'; }
        fetch('/api/references/import', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                fastapi_ref_id: card.refId,
                template_id: meta.id,
                template_name: meta.name,
                template_width: meta.width * 10,
                template_height: meta.height * 10
            })
        }).then(function (res) {
            if (!res.ok) return res.json().then(function (d) { throw new Error((d && d.message) || 'Gagal menyalin'); });
            return res.json();
        }).then(function (json) {
            var data = json.data || {};
            if (!data.id) throw new Error('Gagal menyalin');
            if (btn) btn.textContent = 'Mengaktifkan...';
            return activateReferences([data.id], token).then(function () { return data; });
        }).then(function (data) {
            try { sessionStorage.setItem('last_reference_id', String(data.id)); } catch (e) {}
            try { sessionStorage.setItem('last_reference_ocr', JSON.stringify(data.ocr_result || {})); } catch (e) {}
            window.location.href = '/check?ref=' + encodeURIComponent(data.id);
        }).catch(function (err) {
            if (btn) { btn.disabled = false; btn.textContent = 'Gunakan'; }
            showChooseError((err && err.message) || 'Gagal memakai item library. Coba lagi.');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!window.App) return;

        App.protectPage();
        App.initLogout();

        videoEl = document.getElementById('camera-preview');
        cameraCard = document.getElementById('camera-card');
        statusBox = document.getElementById('camera-status');
        startBtn = document.getElementById('start-scan-btn');
        stopBtn = document.getElementById('stop-scan-btn');
        cameraLabelEl = document.getElementById('camera-label');
        cameraSelectEl = document.getElementById('camera-select');
        cameraResBadgeEl = document.getElementById('camera-res-badge');

        templateStep = document.getElementById('template-step');
        scanStep = document.getElementById('scan-step');
        templateGrid = document.getElementById('template-grid');
        continueBtn = document.getElementById('continue-btn');
        stepIndicator = document.getElementById('step-indicator');
        templateInfoEl = document.getElementById('template-info');
        changeTemplateBtn = document.getElementById('change-template-btn');

        menuStep = document.getElementById('reference-menu-step');
        chooseBtn = document.getElementById('btn-choose-reference');
        createNewBtn = document.getElementById('btn-create-new-reference');
        backToMenuBtn = document.getElementById('back-to-menu-from-template');

        choosePanel = document.getElementById('choose-panel');
        chooseSearch = document.getElementById('choose-search');
        chooseRefreshBtn = document.getElementById('choose-refresh-btn');
        chooseRefreshIcon = document.getElementById('choose-refresh-icon');
        chooseGrid = document.getElementById('choose-grid');
        chooseLoading = document.getElementById('choose-loading');
        chooseError = document.getElementById('choose-error');
        chooseEmpty = document.getElementById('choose-empty');
        choosePrev = document.getElementById('choose-prev');
        chooseNext = document.getElementById('choose-next');
        choosePageInfo = document.getElementById('choose-page-info');
        chooseBackBtn = document.getElementById('back-to-menu-from-choose');
        deleteModal = document.getElementById('delete-modal');
        deleteModalFiles = document.getElementById('delete-modal-files');
        deleteModalError = document.getElementById('delete-modal-error');
        deleteModalCancel = document.getElementById('delete-modal-cancel');
        deleteModalConfirm = document.getElementById('delete-modal-confirm');
        deleteModalBackdrop = document.getElementById('delete-modal-backdrop');
        if (deleteModalCancel) deleteModalCancel.addEventListener('click', closeDeleteModal);
        if (deleteModalBackdrop) deleteModalBackdrop.addEventListener('click', closeDeleteModal);
        if (deleteModalConfirm) deleteModalConfirm.addEventListener('click', confirmDeleteModal);
        // Popup nama reference label (STAGE-B: fungsional, confirm -> dualSaveBoth)
        refnameModal = document.getElementById('refname-modal');
        refnameInput = document.getElementById('refname-input');
        refnameError = document.getElementById('refname-error');
        refnameCancel = document.getElementById('refname-cancel');
        refnameConfirm = document.getElementById('refname-confirm');
        refnameBackdrop = document.getElementById('refname-modal-backdrop');
        if (refnameCancel) refnameCancel.addEventListener('click', closeRefnameModal);
        if (refnameBackdrop) refnameBackdrop.addEventListener('click', closeRefnameModal);
        if (refnameConfirm) refnameConfirm.addEventListener('click', confirmRefnameModal);
        if (refnameInput) {
            refnameInput.addEventListener('input', validateRefname);
            refnameInput.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); confirmRefnameModal(); }
            });
        }
        document.addEventListener('keydown', function (ev) {
            if ((ev.key === 'Escape' || ev.key === 'Esc') && deleteModal && !deleteModal.classList.contains('hidden')) closeDeleteModal();
            if ((ev.key === 'Escape' || ev.key === 'Esc') && refnameModal && !refnameModal.classList.contains('hidden')) closeRefnameModal();
        });

        dualCacheElements();
        if (startBtn) startBtn.addEventListener('click', function () { startCamera(); try { dualApplyStageToCamera(); } catch (e) {} });
        if (stopBtn) stopBtn.addEventListener('click', stopCamera);
        window.addEventListener('pagehide', function () { try { stopCamera(); } catch (e) {} try { dualHideBackLoading(); } catch (e) {} });

        renderTemplates();

        var saved = loadSelection();
        if (saved) {
            onTemplateSelect(saved);
        }

        if (continueBtn) continueBtn.addEventListener('click', goToScanStep);
        if (changeTemplateBtn) changeTemplateBtn.addEventListener('click', goToSelectStep);

        if (createNewBtn) createNewBtn.addEventListener('click', goToScanStep);
        var backToMenuFromScanBtn = document.getElementById('back-to-menu-from-scan');
        if (backToMenuFromScanBtn) backToMenuFromScanBtn.addEventListener('click', backToMenuFromScan);
        // Jalur B: Choose Label Reference — daftar + pakai tanpa scan ulang
        if (chooseBtn) chooseBtn.addEventListener('click', openChoosePanel);
        if (chooseRefreshBtn) chooseRefreshBtn.addEventListener('click', refreshChooseList);
        if (backToMenuBtn) backToMenuBtn.addEventListener('click', showReferenceMenu);
        if (chooseBackBtn) chooseBackBtn.addEventListener('click', showReferenceMenu);
        if (chooseSearch) chooseSearch.addEventListener('input', function () {
            stopChoosePolling();
            clearTimeout(chooseSearchTimer);
            chooseSearchTimer = setTimeout(function () {
                chooseQ = chooseSearch.value.trim();
                choosePage = 1;
                fetchChooseList(false, true);
            }, 400);
        });
        if (choosePrev) choosePrev.addEventListener('click', function () {
            stopChoosePolling();
            if (choosePage > 1) { choosePage -= 1; fetchChooseList(); }
        });
        if (chooseNext) chooseNext.addEventListener('click', function () {
            stopChoosePolling();
            if (choosePage < chooseTotalPages) { choosePage += 1; fetchChooseList(); }
        });

        initGuideElements();

        // FIX-GUIDE-SLIDER: sync label + persist + redraw (input debounce + change immediate untuk keyboard)
        if (guideRange) {
            guideRange.value = loadGuideSize();
            if (guideValue) guideValue.textContent = guideRange.value + '%';
            var _guideSliderRedraw = function () {
                if (guideValue) guideValue.textContent = guideRange.value + '%';
                var _v = parseInt(guideRange.value, 10);
                if (_v >= GUIDE_MIN_SIZE && _v <= GUIDE_MAX_SIZE) persistGuideSize(_v);
                clearTimeout(guideResizeTimer);
                guideResizeTimer = setTimeout(drawGuide, 150);
                try { drawGuide(); } catch (e) {}
            };
            guideRange.addEventListener('input', function () {
                if (guideValue) guideValue.textContent = guideRange.value + '%';
                var _v2 = parseInt(guideRange.value, 10);
                if (_v2 >= GUIDE_MIN_SIZE && _v2 <= GUIDE_MAX_SIZE) persistGuideSize(_v2);
                // FIX-GUIDE-SLIDER (CP-073): gambar langsung tiap input agar smooth saat drag;
                // debounce di bawah sebagai trailing guarantee bila event datang beruntun.
                try { drawGuide(); } catch (e) {}
                clearTimeout(guideResizeTimer);
                guideResizeTimer = setTimeout(drawGuide, 150);
            });
            guideRange.addEventListener('change', _guideSliderRedraw);
        }

        videoEl.addEventListener('loadedmetadata', function () {
            drawGuide();
            updateCameraResolutionBadge();
        });
        videoEl.addEventListener('canplay', function () {
            drawGuide();
            updateCameraResolutionBadge();
        });

        if (cameraSelectEl) {
            cameraSelectEl.addEventListener('change', function () {
                var selectedId = this.value;
                if (!selectedId) return;
                try { localStorage.setItem(CAMERA_STORAGE_KEY, selectedId); } catch (e) {}
                if (activeStream) {
                    activeStream.getTracks().forEach(function (track) { track.stop(); });
                    activeStream = null;
                    startCamera(selectedId);
                }
            });
        }

        if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
            navigator.mediaDevices.addEventListener('devicechange', function () {
                var savedId = null;
                try { savedId = localStorage.getItem(CAMERA_STORAGE_KEY); } catch (e) {}
                populateCameraSelect(savedId);
            });
        }
        populateCameraSelect();
        window.addEventListener('resize', onGuideResize);
        window.addEventListener('orientationchange', onGuideResize);

        captureBtn = document.getElementById('capture-btn');
        captureFeedbackEl = document.getElementById('capture-feedback');
        captureFlashEl = document.getElementById('capture-flash');

        if (captureBtn) { try { captureBtn.removeEventListener('click', onCaptureClick); } catch (e) {} captureBtn.addEventListener('click', dualOnCaptureClick); }

        scanHeader = document.getElementById('scan-header');
        resultCard = document.getElementById('result-card');
        resultImage = document.getElementById('result-image');
        resultInfoTemplate = document.getElementById('result-info-template');
        resultInfoSize = document.getElementById('result-info-size');
        resultInfoTime = document.getElementById('result-info-time');
        resultInfoFilename = document.getElementById('result-info-filename');
        resultInfoResolution = document.getElementById('result-info-resolution');
        resultInfoFilesize = document.getElementById('result-info-filesize');
        saveCaptureBtn = document.getElementById('save-capture-btn');
        retakeBtn = document.getElementById('retake-btn');
        resultFeedbackEl = document.getElementById('result-feedback');
        saveBtnIcon = document.getElementById('save-btn-icon');
        saveBtnText = document.getElementById('save-btn-text');
        resultHelpText = document.getElementById('result-help-text');
        loadingOverlay = document.getElementById('loading-overlay');
        loadingText = document.getElementById('loading-text');
        successOverlay = document.getElementById('success-overlay');
        backToScanBtn = document.getElementById('back-to-scan-btn');

        if (saveCaptureBtn) {
            saveCaptureBtn.addEventListener('click', onSaveCapture);
            saveCaptureBtn.disabled = true;
        }
        if (retakeBtn) retakeBtn.addEventListener('click', retakeCapture);
        if (dualRetakeFrontEl) dualRetakeFrontEl.addEventListener('click', function () { dualRetake('front'); });
        if (dualRetakeFront2El) dualRetakeFront2El.addEventListener('click', function () { dualRetake('front'); });
        if (dualRetakeBack2El) dualRetakeBack2El.addEventListener('click', function () { dualRetake('back'); });
        if (dualRetakeBackEl) dualRetakeBackEl.addEventListener('click', function () { dualRetake('back'); });
        if (dualNextToBackEl) dualNextToBackEl.addEventListener('click', dualGoToBack);
        if (typeof dualNextToResultEl !== 'undefined' && dualNextToResultEl) dualNextToResultEl.addEventListener('click', dualGoToResult);
        // Manual rotate + Proses OCR eksplisit (default horizontal, tanpa auto-OCR).
        try { dualCacheRotateElements(); } catch (e) {}
        if (dualRotateFrontLeftEl) dualRotateFrontLeftEl.addEventListener('click', function () { dualRotateSide('front', -90); });
        if (dualRotateFrontRightEl) dualRotateFrontRightEl.addEventListener('click', function () { dualRotateSide('front', 90); });
        if (dualProcessFrontEl) dualProcessFrontEl.addEventListener('click', function () { dualProcessSide('front'); });
        if (dualRotateBackLeftEl) dualRotateBackLeftEl.addEventListener('click', function () { dualRotateSide('back', -90); });
        if (dualRotateBackRightEl) dualRotateBackRightEl.addEventListener('click', function () { dualRotateSide('back', 90); });
        if (dualProcessBackEl) dualProcessBackEl.addEventListener('click', function () { dualProcessSide('back'); });
        if (dualRotateResFrontLeftEl) dualRotateResFrontLeftEl.addEventListener('click', function () { dualRotateSide('front', -90); });
        if (dualRotateResFrontRightEl) dualRotateResFrontRightEl.addEventListener('click', function () { dualRotateSide('front', 90); });
        if (dualReprocessFrontEl) dualReprocessFrontEl.addEventListener('click', function () { dualProcessSide('front'); });
        if (dualRotateResBackLeftEl) dualRotateResBackLeftEl.addEventListener('click', function () { dualRotateSide('back', -90); });
        if (dualRotateResBackRightEl) dualRotateResBackRightEl.addEventListener('click', function () { dualRotateSide('back', 90); });
        if (dualReprocessBackEl) dualReprocessBackEl.addEventListener('click', function () { dualProcessSide('back'); });
        if (resultRotateLeftEl) resultRotateLeftEl.addEventListener('click', function () { singleRotate(-90); });
        if (resultRotateRightEl) resultRotateRightEl.addEventListener('click', function () { singleRotate(90); });
        if (resultProcessOcrEl) resultProcessOcrEl.addEventListener('click', function () { singleProcessOcr(); });
        if (frontTab1El) frontTab1El.addEventListener('click', function () { setSplitTab('front', 1); });
        if (frontTab2El) frontTab2El.addEventListener('click', function () { setSplitTab('front', 2); });
        if (backTab1El) backTab1El.addEventListener('click', function () { setSplitTab('back', 1); });
        if (backTab2El) backTab2El.addEventListener('click', function () { setSplitTab('back', 2); });
        // FIX-OCR-COLLAPSE (CP-074) — wiring dropdown Lihat teks/Sembunyikan (null-safe, sekali saja).
        try { dualOcrToggleWire(); } catch (e) {}
        try { dualUpdateDegLabels(); singleUpdateRotateUI(); } catch (e) {}
        try { setSplitTab('front', 1); setSplitTab('back', 1); } catch (e) {}
        // STAGE-B: Simpan 2 Label membuka popup nama dulu; confirm memanggil dualSaveBoth(name).
        if (dualSaveBtnEl) dualSaveBtnEl.addEventListener('click', openRefnameModal);
        if (backToScanBtn) backToScanBtn.addEventListener('click', function () {
            // OCR MODE: setelah save sukses tombol ini = Continue ke /check
            try { if (window.__refGoCheck) { window.location.href = window.__refGoCheck; return; } } catch (e) {}
            hideSuccess();
            if (activeOcrAbort) { try { activeOcrAbort.abort(); } catch (e) {} activeOcrAbort = null; }
            activeOcrFilename = null;
            if (ocrPanel) ocrPanel.removeAttribute('data-filename');
            pendingOcrData = null;
            isOcrProcessing = false;
            clearOcrPolling();
            discardLastCapture();
            hideOcrPanel();
            resetSaveButton();
            resetTestButton();
            if (saveCaptureBtn) saveCaptureBtn.disabled = true;
            if (resultCard) resultCard.classList.add('hidden');
            if (scanHeader) scanHeader.classList.remove('hidden');
            try { dualResetAll(); } catch (e) {}
            startCamera();
            try { dualApplyStageToCamera(); } catch (e) {}
        });

        // Test button deprecated — tidak ada lagi di DOM, tapi tetap init untuk backward compat
        testCaptureBtn = document.getElementById('test-capture-btn');
        testBtnIcon = document.getElementById('test-btn-icon');
        testBtnText = document.getElementById('test-btn-text');
        testFeedbackEl = document.getElementById('result-feedback');

        initOcrPanel();

        // Auto OCR AKTIF (2026-09-11 / REVERT OCR MODE): capture Reference
        // otomatis OCR via triggerAutoOcr() → panel + console.
        // if (testCaptureBtn) testCaptureBtn.addEventListener('click', sendToTest);

        showReferenceMenu();
    });
})();
