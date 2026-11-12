(function () {
    'use strict';

    // ===== Live Cam — 1 Guide Line (1 label horizontal 2x bolak-balik, radius luas +10mm toleransi) =====
    var currentRef = null;          // data reference acuan (template_id/name/width/height)
    var checkStream = null;         // MediaStream aktif
    var checkGuideSize = 100;       // % frame size (default 100)
    var checkGuideResizeTimer = null;
    var checkSlotsData = [];        // hasil crop 4 blob URL (memory only)
    var checkEl = {};               // cache elemen
    var checkUploadInProgress = false;
    var checkLastSessionId = null;
    var checkBatchAbort = null;     // AbortController per batch

    var GUIDE_COLS = 1;             // 1 label horizontal (fixed 160×35 mm)
    // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) D2: jumlah slot ekspektasi
    // dinamis — null = ikut GUIDE_COLS (alur single); diisi 4 saat batch dual
    // 2-sisi (Front-1/2 + Back-1/2) agar polling/summary tidak berhenti di slot 1.
    var checkExpectedSlots = null;
    var checkDualAutoProcess = false; // true = otomatis Process setelah upload dual sent
    function checkSlotCount() { return checkExpectedSlots || GUIDE_COLS; }
    var GUIDE_GAP_RATIO = 0.025;    // gap antar box = 2.5% lebar content
    var GUIDE_TOLERANCE_MM = 0;     // no tolerance for fixed dimensions
    var GUIDE_BOX_HEIGHT_FACTOR = 0.72; // tinggi box 72% content.h
    var GUIDE_MAX_WIDTH_RATIO = 0.92; // pakai 92% lebar content
    var GUIDE_DEFAULT_SIZE = 100;
    var GUIDE_MIN_SIZE = 20, GUIDE_MAX_SIZE = 100;
    var CHECK_GUIDE_KEY = 'check_guide_frame_size';
    var CAMERA_STORAGE_KEY = 'label_inspection_camera_id';

    // Fixed label dimensions for guide line (horizontal)
    var GUIDE_FIXED_LENGTH_MM = 160;  // panjang label (horizontal)
    var GUIDE_FIXED_WIDTH_MM = 35;    // lebar label (vertikal)

    // ===== Dual-side Scan State (UI only — mirrors Reference CP-037) =====
    var checkScanStage = 1;           // 1 = Front, 2 = Back, 'result' = dual result shown
    var checkFrontCapture = null;     // {blob, url, filename, w, h, size}
    var checkBackCapture = null;      // {blob, url, filename, w, h, size}
    var checkFrontMiniCanvas = null;  // Offscreen canvas for mini preview

    // ===== Set Rotation per sisi — tiru Reference manual rotate (Canvas 2D, WYSIWYG) =====
    // Base canvas horizontal sekali per sisi + derajat 0/90/180/270. Blob final selalu sama dengan preview terlihat.
    var checkBaseFront = null, checkDegFront = 0, checkBusyFront = false;
    var checkBaseBack = null, checkDegBack = 0, checkBusyBack = false;

    // ===== Auto Crop split: 1 master sisi panjang -> Label 1 + Label 2 (tiru Reference splitLongSide) =====
    // Tiap {blob,url,filename,w,h,size}. Default null (belum di-crop). Induk tetap disimpan untuk crop ulang.
    var checkSplitFront1 = null, checkSplitFront2 = null;
    var checkSplitBack1 = null, checkSplitBack2 = null;
    var checkSplitBusyFront = false, checkSplitBusyBack = false;

    function getRefIdFromUrl() {
        try {
            var u = new URL(window.location.href);
            return u.searchParams.get('ref');
        } catch (e) { return null; }
    }

    // Id BACK (sisi belakang) dari URL (?ref_back=) — numerik saja, validasi beda dari depan di caller
    function getRefBackIdFromUrl() {
        try {
            var u = new URL(window.location.href);
            var v = u.searchParams.get('ref_back');
            if (v && /^\d+$/.test(v)) return v;
            return null;
        } catch (e) { return null; }
    }

    // Sesi eksplisit (?session=) — cara baru 1 sesi = 1 card, resolve server-side.
    function getSessionFromUrl() {
        try {
            var u = new URL(window.location.href);
            var v = u.searchParams.get('session');
            if (v && /^[A-Za-z0-9\-_]{1,64}$/.test(v)) return v;
            return null;
        } catch (e) { return null; }
    }

    function fetchSession(sessionKey, token) {
        return fetch('/api/references/session/' + encodeURIComponent(sessionKey), {
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function (res) {
            if (res.status === 401) throw new Error('UNAUTHORIZED');
            if (!res.ok) throw new Error('NOT_FOUND');
            return res.json();
        }).then(function (j) { return j.data; });
    }

    function pickBackFromPayload(frontData, refBackId) {
        // Prioritas: ?ref_back= eksplisit > pair server (1 sesi) > null.
        if (refBackId && frontData && frontData.pair) {
            var hit = null;
            try {
                (frontData.pair || []).forEach(function (p) { if (String(p.id) === String(refBackId)) hit = p; });
            } catch (e) {}
            if (hit) return hit;
        }
        try {
            var pair = (frontData && frontData.pair) || [];
            if (pair.length) {
                // Pilih saudara yang sisinya berlawanan (front<->back), fallback item pertama.
                var fs = frontData.side || 'single';
                for (var i = 0; i < pair.length; i++) {
                    if ((pair[i].side || '') !== fs) return pair[i];
                }
                return pair[0];
            }
        } catch (e) {}
        return null;
    }

    // Parse sisi + nomor belahan dari filename acuan (pola Reference: -front-1/-front-2/-back-1/-back-2).
    // Tanpa -idx (data lama 2-file) = Label 1. Tak berpola = single.
    function parseRefSideIdx(filename) {
        try {
            var m = String(filename || '').match(/-(front|back)(-\d+)?(\.[a-z0-9]+)?$/i);
            if (!m) return { side: 'single', idx: 1 };
            var idx = m[2] ? parseInt(m[2].slice(1), 10) : 1;
            if (idx !== 2) idx = 1;
            return { side: m[1].toLowerCase(), idx: idx };
        } catch (e) { return { side: 'single', idx: 1 }; }
    }

    // Resolve 4 slot acuan (f1/f2/b1/b2) dari frontData + pair 1 sesi.
    // ?ref_back= eksplisit yang cocok kandidat BACK dijadikan b1 (prioritas lama dipertahankan).
    function pickSlotsFromPayload(frontData, refBackId) {
        var out = { f1: null, f2: null, b1: null, b2: null };
        if (!frontData) return out;
        var cands = [];
        try {
            cands.push(frontData);
            (frontData.pair || []).forEach(function (p) { if (p && p.id) cands.push(p); });
        } catch (e) { cands = [frontData]; }
        var fronts = [], backs = [];
        cands.forEach(function (c) {
            var parsed = parseRefSideIdx(c.filename);
            var side = (parsed.side !== 'single') ? parsed.side : ((c.side === 'front' || c.side === 'back') ? c.side : 'single');
            if (side !== 'front' && side !== 'back') {
                // Single tanpa pasangan: anggap depan agar tetap tampil seperti hari ini.
                side = 'front';
            }
            c._slotSide = side;
            c._slotIdx = parsed.idx;
            if (side === 'front') fronts.push(c); else backs.push(c);
        });
        function sortSide(arr) {
            arr.sort(function (a, b) {
                var ai = (a._slotIdx === 2 ? 2 : 1), bi = (b._slotIdx === 2 ? 2 : 1);
                if (ai !== bi) return ai - bi;
                return (a.id || 0) - (b.id || 0);
            });
        }
        sortSide(fronts);
        sortSide(backs);
        // Dedup bila filename kembar menunjuk baris yang sama (jaga-jaga): buang id duplikat.
        function uniq(arr) {
            var seen = {}, res = [];
            arr.forEach(function (c) { var k = String(c.id); if (!seen[k]) { seen[k] = true; res.push(c); } });
            return res;
        }
        fronts = uniq(fronts);
        backs = uniq(backs);
        out.f1 = fronts[0] || null;
        out.f2 = fronts[1] || null;
        out.b1 = backs[0] || null;
        out.b2 = backs[1] || null;
        if (refBackId) {
            try {
                for (var i = 0; i < backs.length; i++) {
                    if (String(backs[i].id) === String(refBackId)) {
                        // b1 = yang diminta eksplisit, b2 = saudara BACK lain (bila ada).
                        out.b1 = backs[i];
                        var rest = backs.filter(function (c) { return String(c.id) !== String(refBackId); });
                        out.b2 = rest[0] || null;
                        break;
                    }
                }
            } catch (e) {}
        }
        return out;
    }

    function formatBytes(bytes) {
        if (bytes == null) return '-';
        if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }

    function showLoading(show) {
        var el = document.getElementById('check-loading');
        if (el) el.classList.toggle('hidden', !show);
    }
    function showError(msg) {
        showLoading(false);
        var el = document.getElementById('check-error');
        if (el) { el.textContent = msg; el.classList.remove('hidden'); }
        var card = document.getElementById('check-ref-card');
        if (card) card.classList.add('hidden');
        var slots = document.getElementById('check-slots');
        if (slots) slots.classList.add('hidden');
        var badge = document.getElementById('check-ref-badge');
        if (badge) badge.classList.add('hidden');
    }
    function showEmpty() {
        showLoading(false);
        var el = document.getElementById('check-empty');
        if (el) el.classList.remove('hidden');
        var card = document.getElementById('check-ref-card');
        if (card) card.classList.add('hidden');
        var slots = document.getElementById('check-slots');
        if (slots) slots.classList.add('hidden');
        var badge = document.getElementById('check-ref-badge');
        if (badge) badge.classList.add('hidden');
        var err = document.getElementById('check-error');
        if (err) err.classList.add('hidden');
    }
    var checkRefImageUrl = null; // ObjectURL gambar acuan (revoke saat ganti ref)
    var checkRefImageUrlBack = null; // ObjectURL gambar BACK (revoke saat ganti ref)
    var checkRefImageUrlFront2 = null; // ObjectURL gambar FRONT label 2 (revoke saat ganti ref)
    var checkRefImageUrlBack2 = null; // ObjectURL gambar BACK label 2 (revoke saat ganti ref)
    var checkRefIdBack = null; // id BACK aktif (null = mode single)

    function checkRevokeRefImage() {
        if (checkRefImageUrl) { try { URL.revokeObjectURL(checkRefImageUrl); } catch (e) {} checkRefImageUrl = null; }
        if (checkRefImageUrlFront2) { try { URL.revokeObjectURL(checkRefImageUrlFront2); } catch (e) {} checkRefImageUrlFront2 = null; }
    }

    function checkRevokeRefImageBack() {
        if (checkRefImageUrlBack) { try { URL.revokeObjectURL(checkRefImageUrlBack); } catch (e) {} checkRefImageUrlBack = null; }
        if (checkRefImageUrlBack2) { try { URL.revokeObjectURL(checkRefImageUrlBack2); } catch (e) {} checkRefImageUrlBack2 = null; }
    }

    // Muat gambar acuan via fetch auth (img tidak bisa kirim Bearer) → blob URL
    function checkLoadRefImage(refId, imageUrl) {
        var img = document.getElementById('check-notif-image');
        var loading = document.getElementById('check-notif-image-loading');
        var empty = document.getElementById('check-notif-image-empty');
        if (!img) return;
        checkRevokeRefImage();
        img.classList.add('hidden');
        img.removeAttribute('src');
        if (empty) empty.classList.add('hidden');
        if (loading) loading.classList.remove('hidden');
        var token = checkGetToken();
        var url = imageUrl || (refId ? '/api/references/' + encodeURIComponent(refId) + '/image' : null);
        if (!url || !token) {
            if (loading) loading.classList.add('hidden');
            if (empty) empty.classList.remove('hidden');
            return;
        }
        fetch(url, { headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'image/*' } })
            .then(function (res) { if (!res.ok) throw new Error('IMAGE_' + res.status); return res.blob(); })
            .then(function (blob) {
                if (!blob || !blob.size) throw new Error('IMAGE_EMPTY');
                checkRefImageUrl = URL.createObjectURL(blob);
                img.src = checkRefImageUrl;
                img.classList.remove('hidden');
                if (loading) loading.classList.add('hidden');
            })
            .catch(function (err) {
                if (loading) loading.classList.add('hidden');
                if (empty) empty.classList.remove('hidden');
                console.warn('[CHECK] ref image failed', err);
            });
    }

    // Muat gambar BACK via fetch auth (img tidak bisa kirim Bearer) → blob URL (cerminan slot depan, independen)
    function checkLoadRefImageBack(refId, imageUrl) {
        var img = document.getElementById('check-notif-image-back');
        var loading = document.getElementById('check-notif-image-loading-back');
        var empty = document.getElementById('check-notif-image-empty-back');
        if (!img) return;
        checkRevokeRefImageBack();
        img.classList.add('hidden');
        img.removeAttribute('src');
        if (empty) empty.classList.add('hidden');
        if (loading) loading.classList.remove('hidden');
        var token = checkGetToken();
        var url = imageUrl || (refId ? '/api/references/' + encodeURIComponent(refId) + '/image' : null);
        if (!url || !token) {
            if (loading) loading.classList.add('hidden');
            if (empty) empty.classList.remove('hidden');
            return;
        }
        fetch(url, { headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'image/*' } })
            .then(function (res) { if (!res.ok) throw new Error('IMAGE_' + res.status); return res.blob(); })
            .then(function (blob) {
                if (!blob || !blob.size) throw new Error('IMAGE_EMPTY');
                checkRefImageUrlBack = URL.createObjectURL(blob);
                img.src = checkRefImageUrlBack;
                img.classList.remove('hidden');
                if (loading) loading.classList.add('hidden');
            })
            .catch(function (err) {
                if (loading) loading.classList.add('hidden');
                if (empty) empty.classList.remove('hidden');
                console.warn('[CHECK] ref BACK image failed', err);
            });
    }

    // Muat gambar FRONT label 2 via fetch auth (salinan pola slot depan, independen)
    function checkLoadRefImageFront2(refId, imageUrl) {
        var img = document.getElementById('check-notif-image-front-2');
        var loading = document.getElementById('check-notif-image-loading-front-2');
        var empty = document.getElementById('check-notif-image-empty-front-2');
        if (!img) return;
        if (checkRefImageUrlFront2) { try { URL.revokeObjectURL(checkRefImageUrlFront2); } catch (e) {} checkRefImageUrlFront2 = null; }
        img.classList.add('hidden');
        img.removeAttribute('src');
        if (empty) empty.classList.add('hidden');
        if (loading) loading.classList.remove('hidden');
        var token = checkGetToken();
        var url = imageUrl || (refId ? '/api/references/' + encodeURIComponent(refId) + '/image' : null);
        if (!url || !token) {
            if (loading) loading.classList.add('hidden');
            if (empty) empty.classList.remove('hidden');
            return;
        }
        fetch(url, { headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'image/*' } })
            .then(function (res) { if (!res.ok) throw new Error('IMAGE_' + res.status); return res.blob(); })
            .then(function (blob) {
                if (!blob || !blob.size) throw new Error('IMAGE_EMPTY');
                checkRefImageUrlFront2 = URL.createObjectURL(blob);
                img.src = checkRefImageUrlFront2;
                img.classList.remove('hidden');
                if (loading) loading.classList.add('hidden');
            })
            .catch(function (err) {
                if (loading) loading.classList.add('hidden');
                if (empty) empty.classList.remove('hidden');
                console.warn('[CHECK] ref FRONT-2 image failed', err);
            });
    }

    // Muat gambar BACK label 2 via fetch auth (salinan pola slot belakang, independen)
    function checkLoadRefImageBack2(refId, imageUrl) {
        var img = document.getElementById('check-notif-image-back-2');
        var loading = document.getElementById('check-notif-image-loading-back-2');
        var empty = document.getElementById('check-notif-image-empty-back-2');
        if (!img) return;
        if (checkRefImageUrlBack2) { try { URL.revokeObjectURL(checkRefImageUrlBack2); } catch (e) {} checkRefImageUrlBack2 = null; }
        img.classList.add('hidden');
        img.removeAttribute('src');
        if (empty) empty.classList.add('hidden');
        if (loading) loading.classList.remove('hidden');
        var token = checkGetToken();
        var url = imageUrl || (refId ? '/api/references/' + encodeURIComponent(refId) + '/image' : null);
        if (!url || !token) {
            if (loading) loading.classList.add('hidden');
            if (empty) empty.classList.remove('hidden');
            return;
        }
        fetch(url, { headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'image/*' } })
            .then(function (res) { if (!res.ok) throw new Error('IMAGE_' + res.status); return res.blob(); })
            .then(function (blob) {
                if (!blob || !blob.size) throw new Error('IMAGE_EMPTY');
                checkRefImageUrlBack2 = URL.createObjectURL(blob);
                img.src = checkRefImageUrlBack2;
                img.classList.remove('hidden');
                if (loading) loading.classList.add('hidden');
            })
            .catch(function (err) {
                if (loading) loading.classList.add('hidden');
                if (empty) empty.classList.remove('hidden');
                console.warn('[CHECK] ref BACK-2 image failed', err);
            });
    }

    // Render 1 set acuan: FRONT 1 + FRONT 2 berdampingan, BACK 1 + BACK 2 berdampingan.
    // backDataOrNull null → resolve dari pair 1 sesi; tanpa BACK sama sekali → identik single (dual disembunyikan).
    function renderRefDual(frontData, backDataOrNull) {
        // Resolve 4 slot dulu agar slot-1 selalu belahan FRONT idx-1
        // (latest mengembalikan baris terbaru = BACK-2, bukan FRONT — jangan tampilkan BACK di slot FRONT).
        var slots = null;
        try { slots = pickSlotsFromPayload(frontData, null); } catch (e) { slots = null; }
        if (!slots) slots = { f1: frontData, f2: null, b1: backDataOrNull || null, b2: null };
        var displayFront = slots.f1 || frontData;
        renderRef(displayFront);
        var explicitBack = (backDataOrNull && backDataOrNull.id) ? backDataOrNull : null;
        var b1 = slots.b1 || null, b2 = slots.b2 || null, f2 = slots.f2 || null;
        if (explicitBack) {
            // Eksplisit ?ref_back= (sudah di-fetch caller): utamakan sebagai b1.
            var inPair = false;
            try {
                var fp = frontData.pair || [];
                for (var k = 0; k < fp.length; k++) { if (String(fp[k].id) === String(explicitBack.id)) { inPair = true; break; } }
                if (!inPair && explicitBack.session_key && frontData.session_key) {
                    inPair = String(explicitBack.session_key) === String(frontData.session_key);
                }
            } catch (e) { inPair = false; }
            b1 = explicitBack;
            if (inPair) {
                // Satu sesi: b2 = saudara BACK lain (bukan b1).
                b2 = null;
                try {
                    var allB = [];
                    (frontData.pair || []).forEach(function (p) {
                        if (!p || !p.id || String(p.id) === String(explicitBack.id)) return;
                        var ps = parseRefSideIdx(p.filename);
                        var sd = (ps.side !== 'single') ? ps.side : (p.side || '');
                        if (sd === 'back') allB.push(p);
                    });
                    allB.sort(function (a, c) { return (a.id || 0) - (c.id || 0); });
                    b2 = allB[0] || null;
                } catch (e) { b2 = null; }
            } else {
                // Lintas sesi (legacy): b2 dari sesi milik eksplisit, f2 dari sesi depan.
                b2 = null;
                try {
                    var eb = [];
                    (explicitBack.pair || []).forEach(function (p) {
                        if (!p || !p.id || String(p.id) === String(explicitBack.id)) return;
                        var ps2 = parseRefSideIdx(p.filename);
                        var sd2 = (ps2.side !== 'single') ? ps2.side : (p.side || '');
                        if (sd2 === 'back') eb.push(p);
                    });
                    eb.sort(function (a, c) { return (a.id || 0) - (c.id || 0); });
                    b2 = eb[0] || null;
                } catch (e) { b2 = null; }
            }
        }
        // Slot FRONT-2 (f1 sudah dimuat renderRef via checkLoadRefImage).
        if (f2 && f2.id) {
            checkLoadRefImageFront2(f2.id, f2.image_url);
        } else {
            resetRefSlot('front-2');
        }
        var hasBack = !!(b1 || b2);
        var figBack = document.getElementById('check-notif-fig-back');
        var badgeFront = document.getElementById('check-notif-badge-front');
        var capFront = document.getElementById('check-notif-file-front');
        var capBack = document.getElementById('check-notif-file-back');
        var fileBackWrap = document.getElementById('check-notif-file-back-wrap');
        var fileBackMeta = document.getElementById('check-notif-file-back-meta');
        if (figBack) figBack.classList.toggle('hidden', !hasBack);
        if (badgeFront) badgeFront.classList.toggle('hidden', !hasBack);
        if (capFront) {
            capFront.classList.toggle('hidden', !hasBack);
            if (hasBack) {
                capFront.textContent = displayFront.filename || '-';
                capFront.title = displayFront.filename || '';
            }
        }
        if (fileBackWrap) fileBackWrap.classList.add('hidden');
        if (!hasBack) {
            checkRefIdBack = null;
            checkRevokeRefImageBack();
            resetRefSlot('back');
            resetRefSlot('back-2');
            return;
        }
        var b1r = b1 || b2;
        checkRefIdBack = b1r.id;
        try { sessionStorage.setItem('last_reference_id_back', String(b1r.id)); } catch (e) {}
        var sub = document.getElementById('check-notif-subtitle');
        if (sub) sub.textContent = (displayFront.template_id || '-') + ' -- ' + getRefDisplayName(displayFront) + ' · 2 sisi';
        if (fileBackMeta) fileBackMeta.textContent = b1r.filename || '-';
        if (capBack) {
            capBack.textContent = b1r.filename || '-';
            capBack.title = b1r.filename || '';
        }
        if (b1 && b1.id) {
            checkLoadRefImageBack(b1.id, b1.image_url);
        } else {
            resetRefSlot('back');
        }
        if (b2 && b2.id) {
            checkLoadRefImageBack2(b2.id, b2.image_url);
        } else {
            resetRefSlot('back-2');
        }
        console.log('[CHECK] ref quad loaded: front1=', displayFront.id, (displayFront.filename || ''), 'front2=', f2 ? f2.id : null, 'back1=', b1 ? b1.id : null, 'back2=', b2 ? b2.id : null);
    }

    // Kembalikan 1 slot gambar ke kondisi awal (img disembunyikan, loading mati, empty tampil).
    // suffix: '' (FRONT-1) | 'front-2' | 'back' (BACK-1) | 'back-2'.
    function resetRefSlot(suffix) {
        var base = 'check-notif-image' + (suffix ? '-' + suffix : '');
        var img = document.getElementById(base);
        var loading = document.getElementById(base.replace('check-notif-image', 'check-notif-image-loading'));
        var empty = document.getElementById(base.replace('check-notif-image', 'check-notif-image-empty'));
        if (img) { img.classList.add('hidden'); try { img.removeAttribute('src'); } catch (e) {} }
        if (loading) loading.classList.add('hidden');
        if (empty) empty.classList.remove('hidden');
    }

    // Nama display acuan: pakai kolom `name` (popup Reference), fallback `template_name` untuk data lama.
    function getRefDisplayName(data) {
        var nm = data && data.name ? String(data.name).trim() : '';
        if (!nm && data && data.template_name) nm = String(data.template_name).trim();
        return nm || '-';
    }

    function renderRef(data) {
        showLoading(false);
        var err = document.getElementById('check-error');
        if (err) err.classList.add('hidden');
        var empty = document.getElementById('check-empty');
        if (empty) empty.classList.add('hidden');

        var ocr = data.ocr_result || data.ocrResult || {};
        var status = ocr.status || 'success';

        var card = document.getElementById('check-ref-card');
        if (card) card.classList.remove('hidden');
        var slots = document.getElementById('check-slots');
        if (slots) slots.classList.remove('hidden');

        // Notification subtitle: Template (ringkas, bukan DL) — pakai nama label custom bila ada
        var sub = document.getElementById('check-notif-subtitle');
        if (sub) sub.textContent = (data.template_id || '-') + ' -- ' + getRefDisplayName(data);

        // Dot status
        var dot = document.getElementById('check-notif-dot');
        if (dot) {
            if (status === 'success') dot.className = 'w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0';
            else if (status === 'failed') dot.className = 'w-2.5 h-2.5 rounded-full bg-[#E04438] flex-shrink-0';
            else dot.className = 'w-2.5 h-2.5 rounded-full bg-[#6C757D] flex-shrink-0';
        }

        // Tandai acuan aktif (flag active dari API) pada badge yang sudah ada
        var aktifBadge = document.getElementById('check-ref-badge');
        if (aktifBadge) {
            if (data.active) {
                aktifBadge.textContent = 'Aktif';
                aktifBadge.className = 'px-3 py-1 rounded-full bg-white border border-emerald-300 text-xs font-semibold text-[#212529]';
                aktifBadge.classList.remove('hidden');
            } else {
                aktifBadge.classList.add('hidden');
            }
        }

        // Dropdown content: Gambar Acuan (ganti Cleaned Text) + meta ringkas (Template pakai nama label)
        var elTpl = document.getElementById('check-notif-template');
        if (elTpl) elTpl.textContent = (data.template_id || '-') + ' -- ' + getRefDisplayName(data);
        var elSize = document.getElementById('check-notif-size');
        if (elSize) elSize.textContent = ((data.template_height || 0) * 2 / 10) + ' \u00d7 ' + ((data.template_width || 0) / 10) + ' cm';
        var elFile = document.getElementById('check-notif-file');
        if (elFile) elFile.textContent = data.filename || '-';
        checkLoadRefImage(data.id, data.image_url);

        // Reset dropdown closed
        var dropdown = document.getElementById('check-notif-dropdown');
        var toggle = document.getElementById('check-notif-toggle');
        var chevron = document.getElementById('check-notif-chevron');
        if (dropdown) dropdown.classList.add('hidden');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        if (chevron) chevron.classList.remove('rotate-180');

        // Simpan acuan untuk guide line kamera + badge ukuran (top-right)
        currentRef = data;
        var cmW = (data.template_height || 0) * 2 / 10;
        var cmH = (data.template_width || 0) / 10;
        var guideBadge = document.getElementById('check-guide-size-badge');
        if (guideBadge) guideBadge.textContent = (data.template_id || '-') + ' \u00b7 ' + cmW + ' \u00d7 ' + cmH + ' cm \u00b7 FRONT';

        console.log('[CHECK] ref loaded:', data.id, (data.template_id || '-') + ' ' + (data.filename || ''));
    }

    function initNotifDropdown() {
        var card = document.getElementById('check-ref-card');
        var toggle = document.getElementById('check-notif-toggle');
        var dropdown = document.getElementById('check-notif-dropdown');
        var chevron = document.getElementById('check-notif-chevron');
        if (!toggle || !dropdown) return;

        function isOpen() { return !dropdown.classList.contains('hidden'); }
        function open() { dropdown.classList.remove('hidden'); toggle.setAttribute('aria-expanded', 'true'); if (chevron) chevron.classList.add('rotate-180'); }
        function close() { dropdown.classList.add('hidden'); toggle.setAttribute('aria-expanded', 'false'); if (chevron) chevron.classList.remove('rotate-180'); }

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (isOpen()) close(); else open();
        });

        document.addEventListener('click', function (e) {
            if (!card) return;
            if (!card.contains(e.target) && isOpen()) close();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) close();
        });
    }

    function fetchRefById(id, token) {
        return fetch('/api/references/' + encodeURIComponent(id), {
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function (res) {
            if (res.status === 401) throw new Error('UNAUTHORIZED');
            if (res.status === 404 || res.status === 403) throw new Error('NOT_FOUND');
            if (!res.ok) throw new Error('FETCH_FAILED');
            return res.json();
        }).then(function (j) { return j.data; });
    }

    function fetchLatest(token) {
        return fetch('/api/references/latest', {
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function (res) {
            if (res.status === 401) throw new Error('UNAUTHORIZED');
            if (res.status === 404) throw new Error('NO_REF');
            if (!res.ok) throw new Error('FETCH_FAILED');
            return res.json();
        }).then(function (j) { return j.data; });
    }

    // ===================== Live Cam + 5 Guide Lines =====================

    function checkFeedback(message, isError) {
        var el = checkEl.captureFeedback;
        if (!el) return;
        el.textContent = message || '';
        el.className = 'text-xs font-medium ' + (isError ? 'text-[#E04438]' : 'text-sky-600');
    }

    function checkLoadGuideSize() {
        try {
            var v = parseInt(sessionStorage.getItem(CHECK_GUIDE_KEY), 10);
            if (v >= GUIDE_MIN_SIZE && v <= GUIDE_MAX_SIZE) return v;
        } catch (e) {}
        return GUIDE_DEFAULT_SIZE;
    }

    function checkPersistGuideSize(v) {
        try { sessionStorage.setItem(CHECK_GUIDE_KEY, String(v)); } catch (e) {}
    }

    // FIX-GUIDE-SLIDER: sumber kebenaran = DOM live, variabel sebagai fallback (atasi H1 desync)
    function checkSyncGuideSizeFromDom() {
        try {
            if (checkEl && checkEl.guideRange) {
                var _v = parseInt(checkEl.guideRange.value, 10);
                if (_v >= GUIDE_MIN_SIZE && _v <= GUIDE_MAX_SIZE) {
                    checkGuideSize = _v;
                    return _v;
                }
            }
        } catch (e) {}
        if (!(checkGuideSize >= GUIDE_MIN_SIZE && checkGuideSize <= GUIDE_MAX_SIZE)) checkGuideSize = GUIDE_DEFAULT_SIZE;
        return checkGuideSize;
    }

    function checkBuildStamp(d) {
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return '' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
    }

    function checkDownloadBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { try { URL.revokeObjectURL(url); } catch (e) {} }, 1000);
    }

    // ===== Batch upload helpers (CP-029) =====
    function checkBuildBatchCode(d) {
        function p(n) { return (n < 10 ? '0' : '') + n; }
        var stamp = '' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
        var rand = Math.random().toString(36).slice(2, 6);
        return 'CHK-' + stamp + '-' + rand;
    }

    function checkBuildOriginalFilename(slot, date) {
        var tpl = currentRef ? (currentRef.template_id || 'x').toLowerCase() : 'x';
        return 'check-' + tpl + '-' + checkBuildStamp(date) + '-slot' + slot + '.png';
    }

    function checkGetToken() {
        try { return sessionStorage.getItem('label_inspection_token') || ''; } catch (e) { return ''; }
    }

    function checkSetCaptureBusy(busy) {
        checkUploadInProgress = busy;
        if (checkEl.captureBtn) checkEl.captureBtn.disabled = busy;
        // Keep Stop enabled to allow abort during upload
        if (checkEl.captureSpinner) checkEl.captureSpinner.classList.toggle('hidden', !busy);
        if (checkEl.captureIcon) checkEl.captureIcon.classList.toggle('hidden', busy);
        if (checkEl.captureText) checkEl.captureText.textContent = busy ? 'Mengirim...' : 'Capture Label';
        if (checkEl.uploadHint) checkEl.uploadHint.classList.toggle('hidden', !busy);
    }

    // ===== Preview Card helpers (CP-031) =====
    function checkShowCaptureLoading(show, subText) {
        var el = checkEl.captureLoading;
        if (!el) return;
        el.classList.toggle('hidden', !show);
        var sub = checkEl.captureLoadingSub;
        if (sub && subText) sub.textContent = subText;
    }

    // ===== Slot is_match badge helpers — badge tepat di atas tiap gambar preview =====
    // Minimalist pill: Matched (emerald + check) / Not Matched (red + X) / Checking... (sky)
    // Notes sengaja belum ditampilkan (is_match saja).
    var CHECK_BADGE_BASE = 'items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold truncate';
    var CHECK_ICON_CHECK = '<svg class="size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
    var CHECK_ICON_X = '<svg class="size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>';
    var CHECK_ICON_SPIN = '<span class="size-3.5 shrink-0 rounded-full border-2 border-current border-t-transparent animate-spin" aria-hidden="true"></span>';

    function checkSetSlotBadge(slot, state, label, iconHtml) {
        var badge = document.getElementById('check-preview-status-' + slot);
        var card = document.getElementById('check-preview-slot-' + slot);
        if (!badge || !card) return;
        // Reset badge tone
        badge.classList.remove('border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
        badge.classList.remove('border-[#E04438]/30', 'bg-[#E04438]/10', 'text-[#E04438]');
        badge.classList.remove('border-sky-200', 'bg-sky-50', 'text-sky-700');
        // Reset card tone
        card.classList.remove('border-emerald-300', 'ring-1', 'ring-emerald-200');
        card.classList.remove('border-[#E04438]/40', 'ring-[#E04438]/20');
        if (!state) {
            badge.classList.add('hidden');
            badge.classList.remove('inline-flex');
            badge.innerHTML = '';
            badge.removeAttribute('data-state');
            return;
        }
        badge.classList.remove('hidden');
        badge.classList.add('inline-flex');
        badge.setAttribute('data-state', state);
        if (state === 'match') {
            badge.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
            card.classList.add('border-emerald-300', 'ring-1', 'ring-emerald-200');
        } else if (state === 'mismatch') {
            badge.classList.add('border-[#E04438]/30', 'bg-[#E04438]/10', 'text-[#E04438]');
            card.classList.add('border-[#E04438]/40', 'ring-1', 'ring-[#E04438]/20');
        } else {
            badge.classList.add('border-sky-200', 'bg-sky-50', 'text-sky-700');
        }
        badge.innerHTML = iconHtml + '<span class="truncate">' + label + '</span>';
    }

    function checkNormMatch(v) {
        if (v === true || v === 1 || v === '1' || v === 'true' || v === 'True' || v === 'TRUE') return true;
        if (v === false || v === 0 || v === '0' || v === 'false' || v === 'False' || v === 'FALSE') return false;
        return null;
    }

    function checkFormatPercent(score) {
        if (score === undefined || score === null || isNaN(score)) return null;
        var num = Number(score);
        if (num <= 1.0 && num >= 0) num = num * 100;
        var clamped = Math.min(100, Math.max(0, num));
        return {
            val: clamped,
            text: (clamped % 1 === 0 ? clamped.toFixed(0) : clamped.toFixed(1)) + '%'
        };
    }

    function checkRenderSlotStatus(slot, isMatch, conf) {
        var m = checkNormMatch(isMatch);
        var pctObj = checkFormatPercent(conf);
        var pctText = pctObj ? pctObj.text : null;

        if (m === true) {
            checkSetSlotBadge(slot, 'match', 'Matched' + (pctText ? ' · ' + pctText : ''), CHECK_ICON_CHECK);
        } else if (m === false) {
            checkSetSlotBadge(slot, 'mismatch', 'Not Matched' + (pctText ? ' · ' + pctText : ''), CHECK_ICON_X);
        }

        var scoreBox = document.getElementById('check-preview-score-box-' + slot);
        var scoreVal = document.getElementById('check-preview-score-val-' + slot);
        var scoreBar = document.getElementById('check-preview-score-bar-' + slot);
        if (scoreBox && scoreVal && scoreBar && pctObj) {
            scoreBox.classList.remove('hidden');
            scoreVal.textContent = pctText;
            scoreVal.className = m === true ? 'font-bold text-emerald-600' : 'font-bold text-[#E04438]';
            scoreBar.className = m === true ? 'h-1.5 rounded-full bg-emerald-500 transition-all duration-500' : 'h-1.5 rounded-full bg-[#E04438] transition-all duration-500';
            scoreBar.style.width = pctObj.val + '%';
        }
    }

    // COMPARE_TEXT_SIMILARITY (2026-09-12 / 2026-11-12): verdict teks reference-vs-slot.
    // Menampilkan persentase kemiripan teks langsung di badge & visual progress bar card
    var checkOcrVerdictSlots = {};
    function checkRenderSlotVerdict(slot, verdict, score, extra) {
        if (!verdict) return;
        checkOcrVerdictSlots[slot] = verdict;

        var pctObj = checkFormatPercent(score);
        var pctText = pctObj ? pctObj.text : null;
        var badgeLabel = verdict + (pctText ? ' · ' + pctText : '');

        if (verdict === 'PASS') {
            checkSetSlotBadge(slot, 'match', badgeLabel, CHECK_ICON_CHECK);
        } else if (verdict === 'FAIL') {
            checkSetSlotBadge(slot, 'mismatch', badgeLabel, CHECK_ICON_X);
        } else if (verdict === 'SKIP') {
            checkSetSlotBadge(slot, 'checking', badgeLabel, '');
        }

        var scoreBox = document.getElementById('check-preview-score-box-' + slot);
        var scoreVal = document.getElementById('check-preview-score-val-' + slot);
        var scoreBar = document.getElementById('check-preview-score-bar-' + slot);
        var scoreTarget = document.getElementById('check-preview-score-target-' + slot);

        if (scoreBox && scoreVal && scoreBar) {
            scoreBox.classList.remove('hidden');
            if (pctObj) {
                scoreVal.textContent = pctText;
                if (verdict === 'PASS') {
                    scoreVal.className = 'font-bold text-emerald-600';
                    scoreBar.className = 'h-1.5 rounded-full bg-emerald-500 transition-all duration-500';
                } else {
                    scoreVal.className = 'font-bold text-[#E04438]';
                    scoreBar.className = 'h-1.5 rounded-full bg-[#E04438] transition-all duration-500';
                }
                scoreBar.style.width = pctObj.val + '%';
            } else {
                scoreVal.textContent = '-';
                scoreBar.style.width = '0%';
            }
            if (scoreTarget && extra && extra.threshold) {
                var thrPct = Math.round(extra.threshold * 100);
                scoreTarget.textContent = 'Target ≥' + thrPct + '%';
            }
        }
    }

    function checkRenderSlotPending(slot) {
        checkSetSlotBadge(slot, 'checking', 'Checking...', CHECK_ICON_SPIN);
        var scoreBox = document.getElementById('check-preview-score-box-' + slot);
        if (scoreBox) scoreBox.classList.add('hidden');
    }

    function checkResetSlotStatus() {
        for (var i = 1; i <= checkSlotCount(); i++) {
            checkSetSlotBadge(i, null);
            var scoreBox = document.getElementById('check-preview-score-box-' + i);
            if (scoreBox) scoreBox.classList.add('hidden');
        }
        checkOcrVerdictSlots = {};
        var metricsEl = document.getElementById('check-ocr-metrics');
        if (metricsEl) metricsEl.classList.add('hidden');
    }

    function checkEnterPreviewMode(session, external) {
        // Preserve preview data BEFORE stopping camera (fix bug: checkStopCamera revokes URLs)
        var savedSlots = checkSlotsData.slice();
        // Stop camera tracks without clearing thumbs (preserve for preview)
        try {
            if (checkBatchAbort) { try { checkBatchAbort.abort(); } catch (e) {} checkBatchAbort = null; }
            if (checkUploadInProgress) {
                checkSetCaptureBusy(false);
                if (checkEl.uploadProgress) { checkEl.uploadProgress.classList.add('hidden'); checkEl.uploadProgress.textContent = ''; }
            }
            if (checkStream) {
                try { checkStream.getTracks().forEach(function (t) { t.stop(); }); } catch(e){}
                checkStream = null;
            }
            if (checkEl.video) checkEl.video.srcObject = null;
            if (checkEl.cameraCard) checkEl.cameraCard.classList.add('hidden');
            if (checkEl.startBtn) { checkEl.startBtn.classList.remove('hidden'); checkEl.startBtn.disabled = false; }
            // Do NOT call checkClearSlotThumbs here — preserve ObjectURLs for preview
            checkFeedback('', false);
        } catch (e) {}
        var slots = document.getElementById('check-slots');
        if (slots) slots.classList.add('hidden');
        checkShowCaptureLoading(false);
        checkSetCaptureBusy(false);
        if (checkEl.uploadProgress) { checkEl.uploadProgress.classList.add('hidden'); checkEl.uploadProgress.textContent = ''; }
        // Copy thumbs to preview card using preserved blobs (create new ObjectURLs for preview)
        for (var i = 0; i < savedSlots.length; i++) {
            var d = savedSlots[i];
            if (!d || !d.blob) continue;
            var slotNum = d.slot || (i+1);
            var dstImg = document.getElementById('check-preview-thumb-' + slotNum);
            if (dstImg) {
                // Create fresh URL for preview (independent from live strip) to survive revoke of live URLs later
                try {
                    var previewUrl = URL.createObjectURL(d.blob);
                    // Store preview URL for later revoke on retake (reuse checkSlotsData entry or new array)
                    // Keep in checkSlotsData for now, but also track preview separately
                    if (!d.previewUrl) d.previewUrl = previewUrl;
                    else {
                        try { URL.revokeObjectURL(d.previewUrl); } catch(e){}
                        d.previewUrl = previewUrl;
                    }
                    dstImg.src = previewUrl;
                    dstImg.classList.remove('hidden');
                } catch (e) {
                    // Fallback use existing url if create fails
                    if (d.url) {
                        dstImg.src = d.url;
                        dstImg.classList.remove('hidden');
                    }
                }
            }
        }
        // Fallback: if savedSlots empty, try copy from live strip src (before hidden)
        if (savedSlots.length === 0) {
            for (var i2 = 1; i2 <= GUIDE_COLS; i2++) {
                var srcImg2 = document.getElementById('check-slot-thumb-' + i2);
                var dstImg2 = document.getElementById('check-preview-thumb-' + i2);
                if (srcImg2 && dstImg2 && srcImg2.src && !srcImg2.classList.contains('hidden')) {
                    dstImg2.src = srcImg2.src;
                    dstImg2.classList.remove('hidden');
                }
            }
        }
        // CP-066: dual 2-sisi — isi 4 thumb preview (FRONT 1·2 + BACK 1·2) dari split crop.
        // Alur dual tidak mengisi checkSlotsData, sehingga preview lama hanya tampil Slot 1 kosong.
        try {
            var _df1 = (typeof checkGetSplit === 'function') ? checkGetSplit('front', 1) : null;
            var _df2 = (typeof checkGetSplit === 'function') ? checkGetSplit('front', 2) : null;
            var _db1 = (typeof checkGetSplit === 'function') ? checkGetSplit('back', 1) : null;
            var _db2 = (typeof checkGetSplit === 'function') ? checkGetSplit('back', 2) : null;
            var _dualCaps = [_df1, _df2, _db1, _db2];
            var _hasDual = _dualCaps.filter(function (c) { return c && c.blob; }).length === 4;
            var _isDualMode = _hasDual || checkSlotCount() === 4;
            // Toggle visibilitas slot 2-4: dual tampil 4, single hanya slot 1 (legacy 1 label).
            for (var _vi = 2; _vi <= 4; _vi++) {
                var _slotEl = document.getElementById('check-preview-slot-' + _vi);
                if (_slotEl) {
                    if (_isDualMode) {
                        _slotEl.classList.remove('hidden');
                        if (!_slotEl.classList.contains('flex')) _slotEl.classList.add('flex');
                    } else {
                        _slotEl.classList.add('hidden');
                        _slotEl.classList.remove('flex');
                    }
                }
            }
            var _slot1El = document.getElementById('check-preview-slot-1');
            if (_slot1El) {
                _slot1El.classList.remove('hidden');
                if (!_slot1El.classList.contains('flex')) _slot1El.classList.add('flex');
            }
            if (_hasDual) {
                for (var _di = 0; _di < 4; _di++) {
                    var _cap = _dualCaps[_di];
                    var _dst = document.getElementById('check-preview-thumb-' + (_di + 1));
                    if (!_cap || !_cap.blob || !_dst) continue;
                    try {
                        var _url = URL.createObjectURL(_cap.blob);
                        _dst.src = _url;
                        _dst.classList.remove('hidden');
                    } catch (_e) {
                        if (_cap.url) {
                            _dst.src = _cap.url;
                            _dst.classList.remove('hidden');
                        }
                    }
                }
            }
        } catch (_dualErr) {}
        var sessId = session && session.id || checkLastSessionId || '-';
        checkResetSlotStatus();
        var batchCode = session && session.batch_code || '';
        var pBadge = document.getElementById('check-preview-session-badge');
        var pSid = document.getElementById('check-preview-session-id');
        var pCode = document.getElementById('check-preview-session-code');
        if (pBadge) pBadge.classList.remove('hidden');
        if (pSid) pSid.textContent = sessId;
        if (pCode) pCode.textContent = batchCode;
        // External bar dihapus — tetap hidden, hanya console log
        var liveExt = document.getElementById('check-external-status');
        var prevExt = document.getElementById('check-preview-external-status');
        if (liveExt) liveExt.classList.add('hidden');
        if (prevExt) prevExt.classList.add('hidden');
        if (external && external.paths) console.log('[CHECK] external paths (hidden UI):', external.paths);
        var card = document.getElementById('check-preview-card');
        if (card) {
            card.classList.remove('hidden');
            try { card.scrollIntoView({behavior:'smooth', block:'start'}); } catch (e) {}
        }
        try { checkProcessOverlayHide(); } catch (e) {}
        // Enable Retake + Process (Process enabled only if external sent)
        if (checkEl.retakeBtn) {
            checkEl.retakeBtn.disabled = false;
            checkEl.retakeBtn.removeAttribute('title');
        }
        if (checkEl.processBtn) {
            var canProcess = external && (external.status === 'sent' || external.status === 'success' || external.external_status === 'sent');
            if (!canProcess && session && session.external_status === 'sent') canProcess = true;
            if (canProcess) {
                checkEl.processBtn.disabled = false;
                checkEl.processBtn.title = 'Kirim ' + checkSlotCount() + ' label ke OCR — hasil di console log';
            } else {
                checkEl.processBtn.disabled = true;
                checkEl.processBtn.title = external && external.status === 'failed' ? 'Gagal tersimpan di FastAPI — retry dulu' : 'Menunggu FastAPI...';
            }
        }
        if (checkEl.ocrStatus) checkEl.ocrStatus.classList.add('hidden');
        console.log('[CHECK] enter preview mode', sessId, batchCode);
    }

    function checkHandleRetake() {
        console.log('[CHECK] Retake clicked — reopen Live Scan');
        // D2: batalkan mode dual (kembali single).
        checkExpectedSlots = null;
        checkDualAutoProcess = false;
        if (checkOcrPollTimer) { try { clearInterval(checkOcrPollTimer); } catch (e) {} checkOcrPollTimer = null; }
        checkOcrLoggedSlots = {};
        // Hide preview card
        var previewCard = document.getElementById('check-preview-card');
        if (previewCard) previewCard.classList.add('hidden');
        // Clear preview thumbs and revoke preview URLs (keep live slots data for now, but clear all via checkClearSlotThumbs)
        // Preserve that checkClearSlotThumbs will clear both live and preview thumbs and revoke URLs
        checkClearSlotThumbs();
        // Ensure preview external hidden
        var prevExt = document.getElementById('check-preview-external-status');
        if (prevExt) prevExt.classList.add('hidden');
        if (checkEl.ocrStatus) checkEl.ocrStatus.classList.add('hidden');
        // Show Live Scan slots
        var slots = document.getElementById('check-slots');
        if (slots) slots.classList.remove('hidden');
        // Reset feedback and loading
        checkFeedback('', false);
        if (checkEl.uploadProgress) { checkEl.uploadProgress.classList.add('hidden'); checkEl.uploadProgress.textContent = ''; }
        if (checkEl.captureLoading) checkEl.captureLoading.classList.add('hidden');
        checkSetCaptureBusy(false);
        if (checkEl.processBtn) { checkEl.processBtn.disabled = true; checkEl.processBtn.title = 'Menunggu capture'; }
        // Disable Retake again until next preview
        if (checkEl.retakeBtn) checkEl.retakeBtn.disabled = true;
    }

    // ===== External FastAPI helpers (CP-030) — dihapus bar notifikasi Saved to FastAPI per request (hidden) =====
    function checkRenderExternal(external, batchCode) {
        // Dihapus: bar 'Saved to FastAPI 5 labels saved successfully' tidak ditampilkan lagi — hanya console log
        var el = checkEl.externalStatus;
        if (el) el.classList.add('hidden');
        var prevExt = document.getElementById('check-preview-external-status');
        if (prevExt) prevExt.classList.add('hidden');
        if (external && external.paths) {
            console.log('[CHECK] external paths (debug, hidden UI):', external.paths);
        }
    }

    function checkPollExternal(sessionId, batchCode, attempts) {
        attempts = attempts || 0;
        if (attempts > 3) return;
        setTimeout(function () {
            var token = checkGetToken();
            if (!token) return;
            fetch('/api/check/sessions/' + encodeURIComponent(sessionId), {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
            }).then(function (res) { return res.json(); }).then(function (j) {
                if (!j || !j.data || !j.data.session) return;
                var sess = j.data.session;
                var caps = j.data.captures || [];
                var ext = {
                    status: sess.external_status || sess.externalStatus || 'queued',
                    saved_count: sess.external_status === 'sent' ? caps.length : 0,
                    paths: caps.map(function(c){ return c.external_path; }).filter(Boolean),
                    response: sess.external_response,
                    error: sess.external_error,
                    batch_code: sess.batch_code
                };
                checkRenderExternal(ext, sess.batch_code);
                if (ext.status === 'queued' || ext.status === 'pending') {
                    checkPollExternal(sessionId, batchCode, attempts + 1);
                }
            }).catch(function(){});
        }, 2000);
    }

    function checkRetryExternal() {
        var sid = checkLastSessionId;
        if (!sid) return;
        var token = checkGetToken();
        if (!token) return;
        var btn = document.getElementById('check-external-retry');
        if (btn) { btn.disabled = true; btn.textContent = 'Retrying...'; }
        fetch('/api/check/sessions/' + encodeURIComponent(sid) + '/retry-external', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function(res){ return res.json().then(function(b){ if(!res.ok) throw {status:res.status, body:b}; return b; }); })
        .then(function(json){
            checkFeedback('Retry queued — menunggu FastAPI', false);
            checkRenderExternal({status:'queued'}, json.data && json.data.session ? json.data.session.batch_code : '');
            checkPollExternal(sid, '', 0);
            if(btn){ btn.disabled=false; btn.textContent='Retry kirim ke eksternal'; }
        }).catch(function(err){
            if(btn){ btn.disabled=false; btn.textContent='Retry kirim ke eksternal'; }
            var msg = (err.body && err.body.message) || 'Retry gagal';
            checkFeedback(msg, true);
        });
    }

    // ===== Process → OCR (FastAPI) → Callback console log (CP-034b simple 'processing success') =====
    function checkSetProcessBusy(busy, text) {
        if (checkEl.processBtn) {
            checkEl.processBtn.disabled = busy;
            if (busy) checkEl.processBtn.setAttribute('aria-busy', 'true'); else checkEl.processBtn.removeAttribute('aria-busy');
        }
        if (checkEl.processSpinner) checkEl.processSpinner.classList.toggle('hidden', !busy);
        if (checkEl.processText) checkEl.processText.textContent = busy ? (text || 'Processing...') : 'Process';
        if (checkEl.retakeBtn) checkEl.retakeBtn.disabled = busy;
        // Banner OCR simple — hanya tampil saat busy sebagai 'Processing...' lalu success 'processing success'
        var ocr = checkEl.ocrStatus;
        if (ocr) {
            if (busy) {
                ocr.className = 'mt-4 p-3 rounded-xl border bg-white border-[#6C757D]/20 text-sm';
                ocr.classList.remove('hidden');
                if (checkEl.ocrTitle) checkEl.ocrTitle.textContent = 'processing...';
            } else {
                // jangan auto-hide di sini — polling success yang akan tampilkan 'processing success'
                // jika dipanggil dengan busy=false tanpa polling, hide
                // biarkan polling yang handle success; jika tidak polling, hide
            }
        }
    }

    var checkOcrPollTimer = null;
    var checkOcrLoggedSlots = {};
    var checkLastCaps = [];
    // PROGRESSIVE (2026-10-09): flag batch summary yang sudah di-log per batch_code.
    var checkBatchSummaryLogged = {};

    // COMPARE_TEXT_SIMILARITY (2026-09-12 / 2026-11-12): ringkasan verdict ke banner OCR.
    // Menampilkan total PASS/FAIL, % Keberhasilan Batch, dan % Rata-rata Kemiripan Teks
    function checkRenderOcrBannerSummary(capList) {
        var pass = 0, fail = 0, skip = 0;
        var totalScore = 0;
        var scoreCount = 0;
        for (var k = 0; k < (capList || []).length; k++) {
            var c = capList[k];
            var s = (c && c.similarity) || null;
            if (s && s.verdict) {
                if (s.verdict === 'PASS') pass++;
                else if (s.verdict === 'FAIL') fail++;
                else skip++;

                if (s.score !== undefined && s.score !== null) {
                    var sc = Number(s.score);
                    if (sc <= 1.0) sc = sc * 100;
                    totalScore += sc;
                    scoreCount++;
                }
            } else if (c && c.is_match !== undefined && c.is_match !== null) {
                if (c.is_match) pass++; else fail++;
                if (c.confidence_score !== undefined && c.confidence_score !== null) {
                    var conf = Number(c.confidence_score);
                    if (conf <= 1.0) conf = conf * 100;
                    totalScore += conf;
                    scoreCount++;
                }
            }
        }
        if (!checkEl.ocrTitle) return;
        if (pass + fail + skip === 0) {
            checkEl.ocrTitle.textContent = 'processing success';
            return;
        }
        var totalEval = pass + fail;
        var successRate = totalEval > 0 ? Math.round((pass / totalEval) * 100) : 0;
        var avgSim = scoreCount > 0 ? (totalScore / scoreCount).toFixed(1) : null;

        var t = 'Inspeksi Selesai — PASS ' + pass + ' · FAIL ' + fail;
        if (skip > 0) t += ' · SKIP ' + skip;
        checkEl.ocrTitle.textContent = t;

        var metricsEl = document.getElementById('check-ocr-metrics');
        var successRateEl = document.getElementById('check-ocr-stat-success-rate');
        var avgSimEl = document.getElementById('check-ocr-stat-avg-similarity');
        if (metricsEl && successRateEl && avgSimEl) {
            metricsEl.classList.remove('hidden');
            successRateEl.textContent = successRate + '% (' + pass + '/' + (totalEval || 1) + ' PASS)';
            avgSimEl.textContent = avgSim !== null ? avgSim + '%' : '-';
        }
    }

    function checkCloseSimilarityDetailModal() {
        var modal = document.getElementById('check-similarity-modal');
        if (modal) modal.classList.add('hidden');
    }

    function checkOpenSimilarityDetailModal(slot) {
        var modal = document.getElementById('check-similarity-modal');
        if (!modal) return;

        var cap = null;
        for (var i = 0; i < (checkLastCaps || []).length; i++) {
            if (checkLastCaps[i].slot_number == slot) {
                cap = checkLastCaps[i];
                break;
            }
        }

        var side = (cap && cap.side) ? cap.side : (slot <= 2 ? 'front' : 'back');
        var sideLabel = String(side || '').toUpperCase();
        var slotInSide = (slot === 1 || slot === 3) ? 'Label 1' : 'Label 2';

        var sideBadge = document.getElementById('check-sim-modal-side-badge');
        var titleEl = document.getElementById('check-sim-modal-title');
        if (sideBadge) sideBadge.textContent = sideLabel;
        if (titleEl) titleEl.textContent = 'Rincian Kemiripan — ' + sideLabel + ' ' + slotInSide;

        var sim = (cap && cap.similarity) ? cap.similarity : {};
        var verdict = sim.verdict || (cap && cap.is_match !== undefined && cap.is_match !== null ? (cap.is_match ? 'PASS' : 'FAIL') : 'Belum Selesai');
        var rawScore = (sim && sim.score !== undefined && sim.score !== null) ? sim.score : (cap ? cap.confidence_score : null);

        var pctObj = checkFormatPercent(rawScore);
        var pctText = pctObj ? pctObj.text : '-';

        // Verdict Badge
        var verdictEl = document.getElementById('check-sim-modal-verdict');
        if (verdictEl) {
            verdictEl.textContent = verdict;
            if (verdict === 'PASS') {
                verdictEl.className = 'inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-bold border border-emerald-300 bg-emerald-50 text-emerald-700';
            } else if (verdict === 'FAIL') {
                verdictEl.className = 'inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-bold border border-[#E04438]/30 bg-[#E04438]/10 text-[#E04438]';
            } else {
                verdictEl.className = 'inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-bold border border-sky-300 bg-sky-50 text-sky-700';
            }
        }

        // Metric Values
        var scoreEl = document.getElementById('check-sim-modal-score');
        var thrEl = document.getElementById('check-sim-modal-threshold');
        var linesEl = document.getElementById('check-sim-modal-matched-lines');
        var barPct = document.getElementById('check-sim-modal-bar-pct');
        var barFill = document.getElementById('check-sim-modal-bar-fill');

        if (scoreEl) scoreEl.textContent = pctText;
        if (thrEl) {
            var thr = (sim && sim.threshold !== undefined) ? '≥' + Math.round(sim.threshold * 100) + '%' : '≥85%';
            thrEl.textContent = thr;
        }
        if (linesEl) {
            var matched = (sim && sim.matched_lines !== undefined) ? sim.matched_lines : '-';
            var refL = (sim && sim.ref_lines !== undefined) ? sim.ref_lines : '-';
            linesEl.textContent = matched + ' / ' + refL + ' baris';
        }
        if (barPct) barPct.textContent = pctText;
        if (barFill) {
            var fillVal = pctObj ? pctObj.val : 0;
            barFill.style.width = fillVal + '%';
            if (verdict === 'PASS') {
                barFill.className = 'h-2.5 rounded-full bg-emerald-500 transition-all duration-500';
                if (barPct) barPct.className = 'font-bold text-emerald-600';
            } else {
                barFill.className = 'h-2.5 rounded-full bg-[#E04438] transition-all duration-500';
                if (barPct) barPct.className = 'font-bold text-[#E04438]';
            }
        }

        // Diff details
        var diffContainer = document.getElementById('check-sim-modal-diff-container');
        var diffCountEl = document.getElementById('check-sim-modal-diff-count');
        if (diffContainer) {
            diffContainer.innerHTML = '';
            var diffs = (sim && sim.diff_details && Array.isArray(sim.diff_details)) ? sim.diff_details : [];
            if (diffCountEl) diffCountEl.textContent = diffs.length + ' perbedaan';

            if (diffs.length === 0) {
                if (verdict === 'PASS') {
                    diffContainer.innerHTML = '<div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs text-emerald-700 flex items-center gap-2"><svg class="w-4 h-4 shrink-0 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg><span>Semua baris teks cocok sempurna dengan label acuan referensi.</span></div>';
                } else {
                    diffContainer.innerHTML = '<div class="p-3 rounded-xl bg-[#F8F9FA] border border-[#6C757D]/15 text-xs text-[#6C757D]">Tidak ada perbedaan spesifik per baris atau teks belum siap.</div>';
                }
            } else {
                diffs.forEach(function (d) {
                    var item = document.createElement('div');
                    item.className = 'p-2.5 rounded-xl border text-xs flex flex-col gap-1 ';
                    if (d.type === 'changed') {
                        item.className += 'bg-amber-50/70 border-amber-200 text-amber-900';
                        item.innerHTML = '<div class="flex items-center gap-1.5 font-semibold text-[11px] text-amber-700"><span class="px-1.5 py-0.5 rounded bg-amber-200 text-amber-800 text-[10px] font-bold">CHANGED</span> Baris ' + (d.ref_line_num || '?') + ' → ' + (d.check_line_num || '?') + '</div>' +
                            '<div class="grid grid-cols-1 sm:grid-cols-2 gap-1 text-[11px] mt-0.5"><div class="text-[#6C757D]">Acuan: <span class="font-mono text-[#212529] font-medium">"' + (d.ref_text || '') + '"</span></div><div class="text-[#6C757D]">Check: <span class="font-mono text-amber-800 font-medium">"' + (d.check_text || '') + '"</span></div></div>';
                    } else if (d.type === 'missing') {
                        item.className += 'bg-rose-50/70 border-rose-200 text-rose-900';
                        item.innerHTML = '<div class="flex items-center gap-1.5 font-semibold text-[11px] text-[#E04438]"><span class="px-1.5 py-0.5 rounded bg-rose-200 text-rose-800 text-[10px] font-bold">MISSING</span> Baris ' + (d.ref_line_num || '?') + ' (tidak ada di scan)</div>' +
                            '<div class="text-[11px] font-mono text-rose-800 font-medium mt-0.5">"' + (d.ref_text || '') + '"</div>';
                    } else if (d.type === 'added') {
                        item.className += 'bg-sky-50/70 border-sky-200 text-sky-900';
                        item.innerHTML = '<div class="flex items-center gap-1.5 font-semibold text-[11px] text-[#0284C7]"><span class="px-1.5 py-0.5 rounded bg-sky-200 text-sky-800 text-[10px] font-bold">ADDED</span> Baris ' + (d.check_line_num || '?') + ' (tambahan di scan)</div>' +
                            '<div class="text-[11px] font-mono text-sky-800 font-medium mt-0.5">"' + (d.check_text || '') + '"</div>';
                    }
                    diffContainer.appendChild(item);
                });
            }
        }

        // Raw / Cleaned Texts
        var refTextEl = document.getElementById('check-sim-modal-ref-text');
        var slotTextEl = document.getElementById('check-sim-modal-slot-text');
        if (refTextEl) {
            refTextEl.textContent = (cap && cap.reference_excerpt) ? cap.reference_excerpt : '(Teks acuan referensi)';
        }
        if (slotTextEl) {
            slotTextEl.textContent = (cap && (cap.cleaned_text || cap.raw_text)) ? (cap.cleaned_text || cap.raw_text) : '-';
        }

        modal.classList.remove('hidden');
    }

    function checkFetchLatestSessionStatus(sessionId) {
        var token = checkGetToken();
        var sid = sessionId || checkLastSessionId;
        if (!sid) { try { sid = sessionStorage.getItem('last_check_session_id'); } catch (e) {} }
        if (!token || !sid) return;

        var retryBtn = document.getElementById('check-ocr-retry-poll-btn');
        if (retryBtn) {
            retryBtn.disabled = true;
            retryBtn.textContent = 'Memeriksa status...';
        }

        fetch('/api/check/sessions/' + encodeURIComponent(sid), {
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function (res) { return res.json(); }).then(function (j) {
            if (retryBtn) {
                retryBtn.disabled = false;
                retryBtn.innerHTML = '<svg class="w-3.5 h-3.5 inline-block mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Cek Ulang Status Hasil';
            }
            if (!j || !j.data) return;
            var caps = j.data.captures || [];
            checkLastCaps = caps;
            var allDone = true;

            for (var i = 0; i < caps.length; i++) {
                var c = caps[i];
                var slot = c.slot_number;
                var sim = c.similarity || null;
                if (sim && sim.verdict) {
                    checkRenderSlotVerdict(slot, sim.verdict, sim.score, sim, c);
                } else if (c.is_match !== undefined && c.is_match !== null) {
                    checkRenderSlotStatus(slot, c.is_match, c.confidence_score);
                } else {
                    allDone = false;
                }
            }

            if (allDone && caps.length >= checkSlotCount()) {
                if (retryBtn) retryBtn.classList.add('hidden');
                checkRenderOcrBannerSummary(caps);
                checkFeedback('Semua label selesai diperiksa!', false);
                checkSetProcessBusy(false);
                if (checkEl.processBtn) checkEl.processBtn.disabled = false;
            } else {
                checkFeedback('Pemeriksaan sebagian masih berlangsung di server. Melanjutkan polling...', false);
                checkPollOcrBrowserLog(sid);
            }
        }).catch(function (err) {
            if (retryBtn) {
                retryBtn.disabled = false;
                retryBtn.textContent = 'Cek Ulang Status Hasil';
            }
            console.error('[CHECK] Gagal fetch status session:', err);
        });
    }

    function checkPollOcrBrowserLog(sessionId) {
        if (checkOcrPollTimer) { try { clearInterval(checkOcrPollTimer); } catch (e) {} checkOcrPollTimer = null; }
        checkOcrLoggedSlots = {};
        checkOcrVerdictSlots = {};
        try { checkBatchSummaryLogged = {}; } catch (e) {}
        var token = checkGetToken();
        if (!token || !sessionId) return;
        var attempts = 0;
        var maxAttempts = 150; // 300s total (2s x 150) — batas polling 5 menit
        console.log('[CHECK] OCR Browser Polling started for session', sessionId, '— hasil per slot akan tampil di console ini');
        checkOcrPollTimer = setInterval(function () {
            attempts++;
            if (attempts > maxAttempts) {
                clearInterval(checkOcrPollTimer); checkOcrPollTimer = null;
                console.log('[CHECK] OCR Browser Polling selesai — max attempts', maxAttempts);
                // Update banner akhir jika belum semua slot ter-log
                var loggedCount = Object.keys(checkOcrLoggedSlots).length;
                if (loggedCount < GUIDE_COLS) {
                    console.warn('[CHECK] OCR Browser Polling: hanya ' + loggedCount + '/' + GUIDE_COLS + ' slot diterima dalam 300s — cek pail / laravel.log');
                }
                // COMPARE_TEXT_SIMILARITY (2026-09-12): spinner WAJIB berhenti di sini
                // (sebelumnya terus berputar). Banner tampilkan verdict yang sudah masuk.
                checkSetProcessBusy(false);
                if (checkEl.processBtn) checkEl.processBtn.disabled = false;
                var ocrTO = checkEl.ocrStatus;
                if (ocrTO) {
                    ocrTO.className = 'mt-4 p-3 rounded-xl border bg-emerald-50 border-emerald-200 text-sm flex flex-col items-center gap-2';
                    ocrTO.classList.remove('hidden');
                    checkRenderOcrBannerSummary(checkLastCaps);
                    if (checkEl.ocrTitle && /processing success/.test(checkEl.ocrTitle.textContent)) {
                        checkEl.ocrTitle.textContent += ' (timeout — sebagian slot belum diterima)';
                    }
                }
                var retryBtn = document.getElementById('check-ocr-retry-poll-btn');
                if (retryBtn) retryBtn.classList.remove('hidden');
                return;
            }
            fetch('/api/check/sessions/' + encodeURIComponent(sessionId), {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
            }).then(function (res) { return res.json(); }).then(function (j) {
                if (!j || !j.data) return;
                var caps = j.data.captures || [];
                checkLastCaps = caps;
                var session = j.data.session || {};
                var batchCode = session.batch_code || '';
                // PROGRESSIVE (2026-10-09): ringkasan akhir batch (event
                // batch_completed dari FastAPI). Render 1x per batch: kunci
                // banner SELESAI + log combined_overall. Tidak mengganggu alur
                // lama bila summary belum ada (polling per-slot tetap jalan).
                try {
                    var ocrSummary = j.data.ocr_batch_summary || session.ocr_batch_summary || null;
                    if (ocrSummary && batchCode && !checkBatchSummaryLogged[batchCode]) {
                        checkBatchSummaryLogged[batchCode] = true;
                        var sumOverall = ocrSummary.overall_status || '-';
                        var sumProg = ocrSummary.progress || '-';
                        var sumCombined = (ocrSummary.compare_summary && ocrSummary.compare_summary.combined_overall) || '-';
                        console.log('[CHECK SUMMARY] batch=' + batchCode + ' SELESAI overall=' + sumOverall +
                            ' progress=' + sumProg + ' combined=' + sumCombined +
                            ' completed=' + (ocrSummary.completed_count || 0) +
                            ' failed=' + (ocrSummary.failed_count || 0));
                        try { console.log('[CHECK SUMMARY] full', ocrSummary); } catch (e) {}
                        var sumBanner = document.getElementById('check-ocr-status');
                        var sumTitle = checkEl.ocrTitle;
                        if (sumBanner) {
                            sumBanner.className = 'mt-4 p-3 rounded-xl border bg-emerald-50 border-emerald-200 text-sm';
                            sumBanner.classList.remove('hidden');
                        }
                        if (sumTitle) {
                            var baseTitle = sumTitle.textContent || 'processing success';
                            if (baseTitle.indexOf('SELESAI') === -1) {
                                sumTitle.textContent = baseTitle + ' — SELESAI ' + sumProg + ' ' + sumCombined;
                            }
                        }
                    }
                } catch (e) {}
                // Deteksi batch JSON di session (jika ada compare_batch/results) — FastAPI baru
                var batchResults = (session.compare_batch && session.compare_batch.results) || (j.data.compare_batch && j.data.compare_batch.results) || (session.compare_json && (typeof session.compare_json==='string'?JSON.parse(session.compare_json):session.compare_json).results) || null;
                if(batchResults && Array.isArray(batchResults) && Object.keys(checkOcrLoggedSlots).length===0){
                    console.log('[CHECK] Batch compare JSON detected', session.compare_batch||batchResults);
                    try{ console.table(batchResults.map(function(r){return {slot:r.slot,is_match:r.is_match,confidence:r.confidence_score,notes:(r.notes||'').substring(0,100)}})); }catch(e){}
                    for(var bi=0; bi<batchResults.length; bi++){
                        var br=batchResults[bi]; if(!checkOcrLoggedSlots[br.slot]){
                            checkOcrLoggedSlots[br.slot]=true;
                            console.log('[CHECK] Slot '+br.slot+': is_match='+br.is_match+' confidence='+br.confidence_score+' notes='+JSON.stringify((br.notes||'').substring(0,200)));
                            checkRenderSlotStatus(br.slot, br.is_match, br.confidence_score);
                        }
                    }
                }
                var allDone = true;
                for (var i = 0; i < caps.length; i++) {
                    var c = caps[i];
                    var slot = c.slot_number;
                    var cleaned = c.cleaned_text;
                    // NULL-SAFE: null/undefined berarti data BELUM ada (jangan anggap baru).
                    // false/0/'' adalah nilai VALID (mis. is_match=false) dan tetap dihitung.
                    var hasNew = (c.is_match!==undefined && c.is_match!==null) || (c.confidence_score!==undefined && c.confidence_score!==null) || (c.compare_notes!==undefined && c.compare_notes!==null) || (c.compare_result!==undefined && c.compare_result!==null);
                    var isMatch=c.is_match, conf=c.confidence_score, notes=c.compare_notes||c.notes||c.raw_text;
                    if(hasNew && !checkOcrLoggedSlots[slot]){
                        checkOcrLoggedSlots[slot]=true;
                        console.log('[CHECK] Slot '+slot+': is_match='+isMatch+' confidence='+conf+' notes='+JSON.stringify((notes||'').substring(0,200)));
                        console.log('[CHECK] Slot '+slot+' full', {batch_code:batchCode,slot:slot,is_match:isMatch,confidence_score:conf,notes:notes,filename:c.filename_original, compare_result:(c.compare_result||null)});
                        checkRenderSlotStatus(slot, isMatch, conf);
                    } else if(cleaned !== null && cleaned !== undefined && !checkOcrLoggedSlots[slot]){
                        checkOcrLoggedSlots[slot] = true;
                        var isEmpty = (cleaned === '' || c.ocr_status === 'empty');
                        // PROGRESSIVE (2026-10-09): tampilkan progres per slot (1/4…)
                        // bila tersedia dari callback FastAPI (aditif, fallback '-').
                        var prog = c.progress || ('?' + '/' + checkSlotCount());
                        var line = '[CHECK] OCR Result → batch=' + batchCode + ' slot=' + slot + ' progress=' + prog + ' is_empty=' + (isEmpty ? 'EMPTY' : 'OK') + ' cleaned_text=' + JSON.stringify(cleaned);
                        console.log(line);
                        // OCR MODE (2026-09-11): raw teks eksplisit per slot di console browser (F12)
                        console.log('[CHECK OCR RAW] batch=' + batchCode + ' slot=' + slot + ' file=' + (c.filename_original || '-'));
                        console.log('[CHECK OCR RAW] cleaned_text:', cleaned);
                        console.log('[CHECK OCR RAW] raw_text:', (c.raw_text || ''));
                        console.log('[CHECK] OCR Slot ' + slot + ':', {
                            batch_code: batchCode,
                            slot: slot,
                            filename: c.filename_original,
                            cleaned_text: cleaned,
                            raw_text: c.raw_text,
                            ocr_status: c.ocr_status,
                            is_empty: isEmpty,
                            engine: c.ocr_engine,
                            // Tuning RapidOCR (2026-09-12): model rec (small=cepat, medium=akurat).
                            rec_model: c.ocr_rec_model || null
                        });
                        if (isEmpty) {
                            console.warn('[CHECK] OCR Slot ' + slot + ' kosong — cek gambar/label atau coba Preprocess ulang di FastAPI');
                        }
                        // Kontrak RapidOCR (2026-09-11): 'none' = tidak ada teks = valid, bukan error/retake
                        if (typeof cleaned === 'string' && cleaned.trim().toLowerCase() === 'none') {
                            console.log('[CHECK OCR] Slot ' + slot + ' tidak ada teks (none) — hasil valid');
                        }
                        // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): tampilkan verdict
                        // PASS/FAIL/SKIP (dihitung FastAPI via similarity, dikirim di callback).
                        // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): + sisi label.
                        var sim = c.similarity || null;
                        var side = c.side || 'unknown';
                        if (sim && sim.verdict) {
                            var engine = sim.compare_engine || 'difflib';
                            var simLine = '[CHECK COMPARE] batch=' + batchCode + ' slot=' + slot +
                                ' side=' + side + ' engine=' + engine +
                                ' verdict=' + sim.verdict + ' score=' + sim.score +
                                ' (' + (sim.matched_lines || 0) + '/' + (sim.ref_lines || 0) +
                                ' lines, thr=' + sim.threshold + ')';
                            console.log(simLine);
                            console.log('[CHECK COMPARE] ref : ' + (c.reference_excerpt || '(tanpa referensi)'));
                            console.log('[CHECK COMPARE] slot' + slot + ' (' + side + ') raw:', cleaned);
                            // FIX_WRONG_REFERENCE (2026-10-07): sumber acuan tiap slot.
                            // Guard: payload lama tanpa ref_source tetap jalan.
                            if (c.ref_source) {
                                console.log('[CHECK COMPARE] slot' + slot + ' ref_source=' + c.ref_source);
                                if (c.ref_source === 'single') {
                                    console.warn('[CHECK COMPARE] slot ' + slot + ' memakai acuan TUNGGAL — ' +
                                        'pastikan benar bila batch berisi >1 produk (risiko salah pasangan, lihat FastAPI [COMPARE] WARNING)');
                                }
                            }
                            if (sim.verdict === 'FAIL') {
                                console.warn('[CHECK COMPARE] slot ' + slot + ' (' + side + ') FAIL — kemiripan rendah dengan reference, cek label fisik');
                            }
                            if (sim.verdict === 'SKIP') {
                                console.warn('[CHECK COMPARE] slot ' + slot + ' SKIP — ' + (sim.reason || 'tanpa referensi'));
                            }
                            // PLAN_IMPROVE_COMPARE_PRECISION_WITH_DIFF_REASON (2026-10-07):
                            // alasan perbedaan per baris (diff_details dari FastAPI via callback).
                            // Guard Array agar payload lama (tanpa diff_details) tetap jalan.
                            if (sim.diff_details && Array.isArray(sim.diff_details)) {
                                var diffs = sim.diff_details;
                                var label = 'LABEL ' + String(side || 'unknown').toUpperCase() + ' ' + slot;
                                console.log('%c=== COMPARE RESULT: ' + label + ' ===',
                                    'color: white; background: ' + (sim.verdict === 'PASS' ? '#22c55e' : '#ef4444') + '; padding: 4px 8px; font-weight: bold;');
                                console.log('  Status: ' + sim.verdict + ' | Score: ' + sim.score + ' | Threshold: ' + sim.threshold);
                                console.log('  Ref lines: ' + sim.ref_lines + ' | Check lines: ' + sim.slot_lines + ' | Matched: ' + sim.matched_lines);
                                if (diffs.length === 0 && sim.verdict === 'PASS') {
                                    console.log('  %c\u2713 Semua baris cocok \u2014 tidak ada perbedaan.', 'color: #22c55e;');
                                } else if (diffs.length > 0) {
                                    console.log('  %c\u2717 Ditemukan ' + diffs.length + ' perbedaan:', 'color: #ef4444; font-weight: bold;');
                                    diffs.forEach(function (d) {
                                        if (d.type === 'changed') {
                                            console.log('    %c[CHANGED] L' + d.ref_line_num + '\u2192L' + d.check_line_num + ':', 'color: #f59e0b;',
                                                'Acuan \u2192 "' + d.ref_text + '"  |  Check \u2192 "' + d.check_text + '"');
                                        } else if (d.type === 'missing') {
                                            console.log('    %c[MISSING] L' + d.ref_line_num + ':', 'color: #ef4444;',
                                                'Acuan \u2192 "' + d.ref_text + '" (tidak ada di Check)');
                                        } else if (d.type === 'added') {
                                            console.log('    %c[ADDED] L' + d.check_line_num + ':', 'color: #3b82f6;',
                                                'Check \u2192 "' + d.check_text + '" (tidak ada di Acuan)');
                                        }
                                    });
                                }
                                console.log('=== END COMPARE: ' + label + ' ===');
                            }
                            // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07):
                            // gemini_diffs semantik (critical/minor + category).
                            // Kebijakan: TIDAK ADA TOLERANSI — 1+ perbedaan = FAIL.
                            // Guard Array agar payload difflib lama tetap jalan.
                            (function () {
                                var gd = sim.gemini_diffs;
                                var cc = (sim.critical_count !== undefined && sim.critical_count !== null) ? sim.critical_count : null;
                                var mc = (sim.minor_count !== undefined && sim.minor_count !== null) ? sim.minor_count : null;
                                var eng = sim.compare_engine || 'difflib';
                                if (!Array.isArray(gd) && cc === null && mc === null && eng !== 'gemini_flash') return;
                                var glabel = 'LABEL ' + String(side || 'unknown').toUpperCase() + ' ' + slot;
                                var gbg = (sim.verdict === 'PASS') ? '#22c55e' : '#ef4444';
                                console.log('%c=== GEMINI COMPARE: ' + glabel + ' ===',
                                    'color: white; background: ' + gbg + '; padding: 4px 8px; font-weight: bold;');
                                console.log('  Engine: ' + eng + ' | Verdict: ' + sim.verdict + ' | Score: ' + sim.score +
                                    ' | Critical: ' + (cc !== null ? cc : '-') + ' | Minor: ' + (mc !== null ? mc : '-'));
                                var list = Array.isArray(gd) ? gd : [];
                                if (list.length === 0) {
                                    if (sim.verdict === 'PASS') {
                                        console.log('  %c\u2713 Identik \u2014 tidak ada perbedaan.', 'color: #22c55e;');
                                    } else {
                                        console.log('  (tidak ada rincian gemini_diffs)');
                                    }
                                } else {
                                    console.log('  %c\u2717 Ditemukan ' + list.length + ' perbedaan (tanpa toleransi):', 'color: #ef4444; font-weight: bold;');
                                    list.slice(0, 10).forEach(function (d) {
                                        var sev = (d && d.severity) || '?';
                                        var cat = (d && d.category) || '?';
                                        var ta = (d && d.teks_acuan !== undefined && d.teks_acuan !== null) ? '"' + d.teks_acuan + '"' : '(tidak ada)';
                                        var tc = (d && d.teks_check !== undefined && d.teks_check !== null) ? '"' + d.teks_check + '"' : '(tidak ada)';
                                        var style = (sev === 'critical') ? 'color: #ef4444;' : 'color: #f59e0b;';
                                        console.log('    %c[' + String(sev).toUpperCase() + '] ' + cat + ':', style,
                                            'Acuan \u2192 ' + ta + '  |  Check \u2192 ' + tc);
                                    });
                                    if (list.length > 10) {
                                        console.log('  ... dan ' + (list.length - 10) + ' perbedaan lainnya');
                                    }
                                }
                                console.log('=== END GEMINI: ' + glabel + ' ===');
                            })();
                            // PLAN_COMPARE_PARAGRAPH_GLOBAL (2026-10-07):
                            // ringkasan + detail kata (c.similarity tetap, blok baru sejajar).
                            // Guard penuh: payload lama / tanpa perbandingan tetap jalan.
                            (function () {
                                var perb = c.perbandingan || null;
                                if (!perb || typeof perb !== 'object') return;
                                var ringkas = perb.ringkasan_perbandingan || {};
                                var details = perb.detail_perbedaan;
                                if (!Array.isArray(details)) return;
                                var total = (ringkas.total_perbedaan !== undefined && ringkas.total_perbedaan !== null)
                                    ? ringkas.total_perbedaan : details.length;
                                var pstatus = ringkas.status || '?';
                                var plabel = 'LABEL ' + String(side || 'unknown').toUpperCase() + ' ' + slot;
                                var bg = (sim.verdict === 'PASS' && total === 0) ? '#22c55e'
                                    : (sim.verdict === 'PASS' ? '#f59e0b' : '#ef4444');
                                console.log('%c=== PERBANDINGAN KATA: ' + plabel + ' ===',
                                    'color: white; background: ' + bg + '; padding: 4px 8px; font-weight: bold;');
                                console.log('  Status: ' + sim.verdict + ' | Score: ' + sim.score +
                                    ' | Total perbedaan: ' + total + ' | ' + pstatus);
                                if (details.length === 0) {
                                    console.log('  %c\u2713 Tidak ada perbedaan kata.', 'color: #22c55e;');
                                } else {
                                    details.forEach(function (d) {
                                        var ta = (d.teks_acuan !== undefined && d.teks_acuan !== null)
                                            ? '"' + d.teks_acuan + '"' : '(tidak ada)';
                                        var tc = (d.teks_check !== undefined && d.teks_check !== null)
                                            ? '"' + d.teks_check + '"' : '(tidak ada)';
                                        console.log('    ' + d.id + '. Acuan \u2192 ' + ta + '  |  Check \u2192 ' + tc);
                                    });
                                }
                                console.log('=== END PERBANDINGAN: ' + plabel + ' ===');
                            })();
                            // Tampilkan badge PASS/FAIL/SKIP di kartu slot (dashboard Checking Label).
                            checkRenderSlotVerdict(slot, sim.verdict, sim.score, sim, c);
                            // TOLERANSI TANDA BACA (2026-10-09): perbedaan menyentuh
                            // digit angka (desimal/kode) wajib verifikasi manual
                            // walau verdict PASS — flag aditif dari FastAPI.
                            if (sim.numeric_diff) {
                                console.warn('[CHECK COMPARE] slot ' + slot + ' ada perbedaan ANGKA (desimal/kode) — verifikasi manual nilai di label fisik');
                            }
                            if (sim.punct_ignored_count > 0) {
                                console.log('[CHECK COMPARE] slot ' + slot + ' ' + sim.punct_ignored_count + ' perbedaan tanda baca/spasi diabaikan (toleransi)');
                            }
                        }
                    }
                    if(hasNew){
                    } else {
                        if (cleaned === null || cleaned === undefined) {
                            allDone = false;
                        }
                    }
                }
                var loggedCount=Object.keys(checkOcrLoggedSlots).length;
                // D2: batch dual 4 split → selesai setelah 4 slot, bukan GUIDE_COLS.
                if(loggedCount>=checkSlotCount()) allDone=true; else if(!batchResults) {
                    for(var k=0;k<caps.length;k++){ if(caps[k].cleaned_text===null && caps[k].is_match===undefined) {allDone=false; break;}}
                }
                // COMPARE_TEXT_SIMILARITY_PLAN (2026-09-12): ringkasan akhir verdict.
                // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05): breakdown per sisi.
                // PLAN_GEMINI_API_TEXT_COMPARE (2026-10-07): + breakdown engine
                // (gemini_flash vs difflib) + total critical/minor. Guard agar
                // payload lama tanpa field Gemini tetap jalan.
                function checkLogCompareSummary(capList) {
                    var pass = 0, fail = 0, skip = 0, thr = null;
                    var frontPass = 0, frontFail = 0, backPass = 0, backFail = 0;
                    var geminiN = 0, difflibN = 0, critT = 0, minorT = 0;
                    for (var k = 0; k < capList.length; k++) {
                        var s = (capList[k] && capList[k].similarity) || null;
                        if (!s || !s.verdict) continue;
                        if (thr === null) thr = s.threshold;
                        if (s.verdict === 'PASS') pass++;
                        else if (s.verdict === 'FAIL') fail++;
                        else skip++;
                        var sd = (capList[k] && capList[k].side) || 'unknown';
                        if (sd === 'front') {
                            if (s.verdict === 'PASS') frontPass++;
                            else if (s.verdict === 'FAIL') frontFail++;
                        } else if (sd === 'back') {
                            if (s.verdict === 'PASS') backPass++;
                            else if (s.verdict === 'FAIL') backFail++;
                        }
                        var eng = (s.compare_engine || 'difflib');
                        if (eng === 'gemini_flash') geminiN++;
                        else difflibN++;
                        if (typeof s.critical_count === 'number') critT += s.critical_count;
                        if (typeof s.minor_count === 'number') minorT += s.minor_count;
                    }
                    if (pass + fail + skip > 0) {
                        console.log('[CHECK COMPARE] DONE batch=' + batchCode + ' PASS=' + pass +
                            ' FAIL=' + fail + ' SKIP=' + skip + ' (threshold=' + (thr !== null ? thr : '-') + ')');
                        console.log('[CHECK COMPARE] DONE batch=' + batchCode +
                            ' FRONT: PASS=' + frontPass + ' FAIL=' + frontFail +
                            ' | BACK: PASS=' + backPass + ' FAIL=' + backFail +
                            ' (threshold=' + (thr !== null ? thr : '-') + ')');
                        console.log('[GEMINI COMPARE] DONE batch=' + batchCode +
                            ' engine gemini_flash=' + geminiN + ' difflib=' + difflibN +
                            ' critical_total=' + critT + ' minor_total=' + minorT);
                    }
                }
                // Hapus hitungan OCR — simple saja (checkSlotCount dinamis: 1 single / 4 dual)
                if (allDone && caps.length === checkSlotCount()) {
                    clearInterval(checkOcrPollTimer); checkOcrPollTimer = null;
                    console.log('[CHECK] OCR Semua ' + checkSlotCount() + ' slot telah diterima — polling berhenti');
                    checkLogCompareSummary(caps);
                    var ocr = checkEl.ocrStatus;
                    if (ocr) {
                        ocr.className = 'mt-4 p-3 rounded-xl border bg-emerald-50 border-emerald-200 text-sm flex flex-col items-center gap-2';
                        ocr.classList.remove('hidden');
                        // COMPARE_TEXT_SIMILARITY (2026-09-12): judul bawa hitungan verdict.
                        checkRenderOcrBannerSummary(caps);
                    }
                    var retryBtn = document.getElementById('check-ocr-retry-poll-btn');
                    if (retryBtn) retryBtn.classList.add('hidden');
                    checkSetProcessBusy(false);
                    if (checkEl.processBtn) checkEl.processBtn.disabled = false;
                } else if (allDone && batchResults && batchResults.length === GUIDE_COLS) {
                    clearInterval(checkOcrPollTimer); checkOcrPollTimer = null;
                    console.log('[CHECK] OCR Semua ' + GUIDE_COLS + ' slot telah diterima — polling berhenti (batch)');
                    checkLogCompareSummary(caps);
                    var ocr2 = checkEl.ocrStatus;
                    if (ocr2) {
                        ocr2.className = 'mt-4 p-3 rounded-xl border bg-emerald-50 border-emerald-200 text-sm flex flex-col items-center gap-2';
                        ocr2.classList.remove('hidden');
                        // COMPARE_TEXT_SIMILARITY (2026-09-12): judul bawa hitungan verdict.
                        checkRenderOcrBannerSummary(caps);
                    }
                    var retryBtn2 = document.getElementById('check-ocr-retry-poll-btn');
                    if (retryBtn2) retryBtn2.classList.add('hidden');
                    checkSetProcessBusy(false);
                    if (checkEl.processBtn) checkEl.processBtn.disabled = false;
                }
            }).catch(function (err) {
                console.warn('[CHECK] OCR Browser Polling fetch error attempt ' + attempts, err);
            });
        }, 2000);
    }

    function checkHandleProcess() {
        if (checkUploadInProgress) { checkFeedback('Masih mengirim batch...', true); return; }
        var sid = checkLastSessionId;
        if (!sid) { try { sid = sessionStorage.getItem('last_check_session_id'); } catch (e) {} }
        if (!sid || sid === '-') { checkFeedback('Belum ada batch — Capture dulu.', true); return; }
        var token = checkGetToken();
        if (!token) { checkFeedback('Sesi berakhir. Silakan login kembali.', true); return; }
        checkSetProcessBusy(true, 'Processing...');
        for (var pi = 1; pi <= checkSlotCount(); pi++) checkRenderSlotPending(pi);
        checkFeedback('Mengirim ' + checkSlotCount() + ' label ke OCR — hasil akan tampil di console browser (F12)...', false);
        console.log('[CHECK] Process clicked → POST /api/check/sessions/' + sid + '/process');
        console.log('[CHECK] Tip: Buka DevTools Console (F12) untuk melihat OCR cleaned_text per slot secara real-time');
        fetch('/api/check/sessions/' + encodeURIComponent(sid) + '/process', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        }).then(function (res) {
            return res.json().then(function (body) { if (!res.ok) throw { status: res.status, body: body }; return body; });
        }).then(function (json) {
            console.log('[CHECK] Process queued — FastAPI OCR dimulai', json);
            // BARU: log langsung JSON results jika ada di response (sync FastAPI batch JSON)
            var batchCode = (json.data && json.data.batch_code) || (json.data && json.data.session && json.data.session.batch_code) || sid;
            var fastResult = json.fastapi_result || (json.data && json.data.fastapi_result) || json.results || (json.data && json.data.results) || (json.data && json.data.session && json.data.session.compare_batch);
            if (!fastResult && json.results) fastResult = json;
            if (!fastResult && json.data && json.data.results) fastResult = json.data;
            if (fastResult && fastResult.results && Array.isArray(fastResult.results)) {
                var resArr = fastResult.results;
                console.log('[CHECK] Process JSON → batch=' + (fastResult.batch_code || batchCode) + ' total_checked=' + (fastResult.total_checked || resArr.length) + ' model=' + (fastResult.model || '-') + ' processing_time=' + (fastResult.processing_time_seconds || '-') + ' status=' + (fastResult.status || '-'));
                console.log('[CHECK] Process results raw', fastResult);
                try {
                    console.table(resArr.map(function(r){ return {slot: r.slot, file_name: r.file_name||r.filename||'-', is_match: r.is_match, confidence_score: r.confidence_score, notes: (r.notes||'').substring(0,120)}; }));
                } catch(e){ console.log('[CHECK] Process results', resArr); }
                for(var i=0;i<resArr.length;i++){
                    var rr=resArr[i];
                    console.log('[CHECK] Slot ' + rr.slot + ': is_match=' + rr.is_match + ' confidence=' + rr.confidence_score + ' notes=' + JSON.stringify((rr.notes||'').substring(0,200)));
                    checkRenderSlotStatus(rr.slot, rr.is_match, rr.confidence_score);
                }
                if (resArr.length === checkSlotCount()) {
                    var ocr = checkEl.ocrStatus;
                    if(ocr){ ocr.className='mt-4 p-3 rounded-xl border bg-emerald-50 border-emerald-200 text-sm'; ocr.classList.remove('hidden'); if(checkEl.ocrTitle) checkEl.ocrTitle.textContent='processing success'; }
                    checkSetProcessBusy(false);
                    if(checkEl.processBtn) checkEl.processBtn.disabled=false;
                }
            }
            console.log('[CHECK] Menunggu ' + checkSlotCount() + ' callback OCR dari FastAPI → Laravel → Browser Polling (2s interval, max 180s)');
            checkFeedback('OCR queued — polling hasil ' + checkSlotCount() + ' label, lihat console (F12).', false);
            // Mulai polling hybrid (fallback, akan deteksi is_match jika sudah ada → langsung success)
            checkPollOcrBrowserLog(sid);
            // Fallback: tetap simple 'processing...' tanpa hitungan
            setTimeout(function () {
                if (checkOcrPollTimer && Object.keys(checkOcrLoggedSlots).length === 0) {
                    if (checkEl.ocrTitle) checkEl.ocrTitle.textContent = 'processing...';
                }
            }, 6000);
        }).catch(function (err) {
            if (checkOcrPollTimer) { clearInterval(checkOcrPollTimer); checkOcrPollTimer = null; }
            checkSetProcessBusy(false);
            checkResetSlotStatus();
            if (checkEl.ocrStatus) checkEl.ocrStatus.classList.add('hidden');
            var status = err && err.status;
            var body = err && err.body;
            if (status === 401) { try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {} checkFeedback('Sesi berakhir. Silakan login kembali.', true); setTimeout(function () { window.location.href = '/login'; }, 900); return; }
            if (status === 422) { var msg = (body && body.message) || 'Validasi gagal'; checkFeedback(msg, true); return; }
            if (status === 429) { checkFeedback('Terlalu banyak permintaan. Coba lagi 60 detik.', true); return; }
            var fallback = (body && body.message) || (err && err.message) || 'Gagal memproses OCR. Coba lagi.';
            checkFeedback(fallback, true);
            console.error('[CHECK] Process failed', err);
        });
    }

    // Area video yang benar-benar terlihat (object-contain letterbox) — adaptasi reference.js:280
    function checkGetContentRect() {
        var video = checkEl.video;
        if (!video) return null;
        var cw = video.clientWidth, ch = video.clientHeight;
        var vw = video.videoWidth, vh = video.videoHeight;
        if (!cw || !ch || !vw || !vh) return null;
        var scale = Math.min(cw / vw, ch / vh);
        var w = vw * scale, h = vh * scale;
        return { x: (cw - w) / 2, y: (ch - h) / 2, w: w, h: h, el: video };
    }

    // 1 box horizontal fixed 160×35 mm — horizontal guide line
    function checkGetGuideBoxes() {
        var content = checkGetContentRect();
        if (!content) return null;
        // Fixed dimensions: 160mm (length/horizontal) × 35mm (width/vertical)
        var wEff = GUIDE_FIXED_LENGTH_MM;
        var hEff = GUIDE_FIXED_WIDTH_MM;
        var aspect = wEff / hEff; // 160/35 ≈ 4.57

        // FIX-GUIDE-SLIDER: sync fresh sebelum hitung (DOM live diprioritaskan)
        var _sizePct = checkSyncGuideSizeFromDom();
        // FIX-GUIDE-SLIDER (CP-073): skala proporsional thd box maksimum (post-cap).
        // Sebelumnya slider diskala dulu baru di-cap → plateau/dead-zone (s>~50% hasilnya
        // identik = cap, mis. 88% vs 100% tak ada perubahan). Kini: hitung box 100%,
        // cap sekali jadi boxMax, lalu skala linear s/100 → tiap % terlihat bedanya.
        var _boxH100 = content.h * GUIDE_BOX_HEIGHT_FACTOR;
        var _boxW100 = _boxH100 * aspect;
        var _gap0 = content.w * GUIDE_GAP_RATIO;
        var _totalW100 = GUIDE_COLS * _boxW100 + (GUIDE_COLS - 1) * _gap0;
        var _maxScale = 1;
        var _capW = content.w * GUIDE_MAX_WIDTH_RATIO;
        if (_totalW100 > _capW) _maxScale = _capW / _totalW100;
        var boxH = _boxH100 * _maxScale * (_sizePct / 100);
        var boxW = _boxW100 * _maxScale * (_sizePct / 100);
        // Gap tetap konstan (tidak ikut slider) seperti perilaku semula — hanya box yang diskala.
        var gap = _gap0 * _maxScale;
        var totalW = GUIDE_COLS * boxW + (GUIDE_COLS - 1) * gap;
        var startX = content.x + (content.w - totalW) / 2;
        var boxY = content.y + (content.h - boxH) / 2;

        var boxes = [];
        for (var i = 0; i < GUIDE_COLS; i++) {
            boxes.push({ x: startX + i * (boxW + gap), y: boxY, w: boxW, h: boxH, slot: i + 1 });
        }
        return { boxes: boxes, content: content };
    }

    function checkRoundedRectPath(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function checkDrawSlotPill(ctx, text, cx, cy) {
        ctx.font = '600 12px ui-sans-serif, system-ui, sans-serif';
        var tw = ctx.measureText(text).width;
        var w = tw + 14, h = 20;
        checkRoundedRectPath(ctx, cx - w / 2, cy - h / 2, w, h, h / 2);
        ctx.fillStyle = 'rgba(33,37,41,0.75)';
        ctx.fill();
        ctx.fillStyle = '#F8F9FA';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, cx, cy + 0.5);
    }

    function checkDrawGuide() {
        var canvas = checkEl.guideCanvas;
        var video = checkEl.video;
        if (!canvas || !video || !checkStream || !video.videoWidth) return;
        var ctx = canvas.getContext('2d');
        if (!ctx) return;

        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var cw = video.clientWidth, ch = video.clientHeight;
        var bw = Math.round(cw * dpr), bh = Math.round(ch * dpr);
        if (canvas.width !== bw || canvas.height !== bh) {
            canvas.width = bw; canvas.height = bh;
        }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cw, ch);

        var result = checkGetGuideBoxes();
        if (!result) return;

        // Gelap di luar kotak guide line (sama seperti Reference: rgba(0,0,0,0.35))
        // Fill seluruh area content dengan gelap, lalu clear box agar video terlihat
        var content = result.content;
        ctx.fillStyle = 'rgba(0,0,0,0.35)';
        ctx.fillRect(content.x, content.y, content.w, content.h);
        result.boxes.forEach(function (b) {
            ctx.clearRect(b.x, b.y, b.w, b.h);
        });

        result.boxes.forEach(function (b) {
            ctx.strokeStyle = '#38BDF8';
            ctx.lineWidth = 2;
            ctx.strokeRect(b.x, b.y, b.w, b.h);

            var cl = Math.min(Math.min(b.w, b.h) * 0.18, 28);
            ctx.lineWidth = 3;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(b.x, b.y + cl); ctx.lineTo(b.x, b.y); ctx.lineTo(b.x + cl, b.y);
            ctx.moveTo(b.x + b.w - cl, b.y); ctx.lineTo(b.x + b.w, b.y); ctx.lineTo(b.x + b.w, b.y + cl);
            ctx.moveTo(b.x + b.w, b.y + b.h - cl); ctx.lineTo(b.x + b.w, b.y + b.h); ctx.lineTo(b.x + b.w - cl, b.y + b.h);
            ctx.moveTo(b.x + cl, b.y + b.h); ctx.lineTo(b.x, b.y + b.h); ctx.lineTo(b.x, b.y + b.h - cl);
            ctx.stroke();

            // Center crosshair (dashed)
            ctx.strokeStyle = 'rgba(255,255,255,0.55)';
            ctx.lineWidth = 1;
            ctx.setLineDash([6, 6]);
            ctx.beginPath();
            ctx.moveTo(b.x + b.w / 2, b.y); ctx.lineTo(b.x + b.w / 2, b.y + b.h);
            ctx.moveTo(b.x, b.y + b.h / 2); ctx.lineTo(b.x + b.w, b.y + b.h / 2);
            ctx.stroke();
            ctx.setLineDash([]);

            // Slot label
            checkDrawSlotPill(ctx, 'Slot ' + b.slot, b.x + b.w / 2, b.y - 12);
        });
    }

    // Update top-right badge with FRONT/BACK indicator based on scan stage
    function checkUpdateGuideBadge() {
        var guideBadge = document.getElementById('check-guide-size-badge');
        if (!guideBadge || !currentRef) return;
        var cmW = (currentRef.template_height || 0) * 2 / 10;
        var cmH = (currentRef.template_width || 0) / 10;
        var sideText = 'FRONT';
        if (checkScanStage === 2) sideText = 'BACK';
        else if (checkScanStage === 'result') sideText = 'FRONT / BACK';
        else if (checkScanStage === 'preview2') sideText = 'BACK';
        else sideText = 'FRONT'; // 1 dan 'preview1'
        guideBadge.textContent = (currentRef.template_id || '-') + ' \u00b7 ' + cmW + ' \u00d7 ' + cmH + ' cm \u00b7 ' + sideText;
    }

    // Pill tahapan sesi — mirror Reference dualPill (kelas sama persis)
    function checkStagePill(el, mode) {
        if (!el) return;
        var base = 'px-3 py-1.5 rounded-full border ';
        if (mode === 'active') el.className = base + 'bg-[#0284C7] text-white border-[#0284C7]';
        else if (mode === 'done') el.className = base + 'bg-[#F8F9FA] text-[#212529] border-emerald-300';
        else el.className = base + 'bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30';
    }

    // Stepper tahapan sesi — mirror Reference dualSetStepper (teks " done" ikut seperti Reference)
    function checkStageStepper() {
        var s1 = document.getElementById('check-stage-step-1');
        var s2 = document.getElementById('check-stage-step-2');
        var s3 = document.getElementById('check-stage-step-3');
        var frontDone = !!checkFrontCapture;
        var backDone = !!checkBackCapture;
        if (checkScanStage === 2) {
            checkStagePill(s1, 'done'); checkStagePill(s2, 'active'); checkStagePill(s3, 'idle');
        } else if (checkScanStage === 'result') {
            checkStagePill(s1, 'done'); checkStagePill(s2, 'done'); checkStagePill(s3, 'active');
        } else if (checkScanStage === 'preview1') {
            checkStagePill(s1, 'done'); checkStagePill(s2, 'idle'); checkStagePill(s3, 'idle');
        } else if (checkScanStage === 'preview2') {
            checkStagePill(s1, 'done'); checkStagePill(s2, 'done'); checkStagePill(s3, 'idle');
        } else {
            checkStagePill(s1, 'active'); checkStagePill(s2, 'idle'); checkStagePill(s3, 'idle');
        }
        if (s1) s1.textContent = frontDone ? '1 \u00b7 Front (Depan) done' : '1 \u00b7 Front (Depan)';
        if (s2) s2.textContent = backDone ? '2 \u00b7 Back (Belakang) done' : '2 \u00b7 Back (Belakang)';
    }

    // ===== Set Rotation helpers — tiru Reference rotateCanvas90CW/renderBaseAtDeg (Canvas 2D bawaan) =====
    // Rotate 90 derajat kanan (clockwise). Return canvas baru atau null bila gagal.
    function checkRotateCanvas90CW(srcCanvas) {
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

    // Render base horizontal pada derajat 0/90/180/270 (WYSIWYG untuk upload/Process).
    function checkRenderBaseAtDeg(baseCanvas, deg) {
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
                tmp = checkRotateCanvas90CW(cur);
                if (!tmp) return null;
                cur = tmp;
            }
            return cur;
        } catch (e) {
            return null;
        }
    }

    function checkDegLabel(deg) {
        var d = ((deg % 360) + 360) % 360;
        return d + '°';
    }

    function checkGetRotateBase(side) { return side === 'back' ? checkBaseBack : checkBaseFront; }
    function checkGetRotateDeg(side) { return side === 'back' ? checkDegBack : checkDegFront; }
    function checkSetRotateDeg(side, d) { if (side === 'back') checkDegBack = d; else checkDegFront = d; }
    function checkGetRotateBusy(side) { return side === 'back' ? checkBusyBack : checkBusyFront; }
    function checkSetRotateBusyFlag(side, b) { if (side === 'back') checkBusyBack = !!b; else checkBusyFront = !!b; }

    // Update label derajat Sesi 1 + kolom FRONT (deg Front) + kolom BACK (deg Back).
    function checkUpdateRotateUI() {
        var s1 = document.getElementById('check-session1-rotate-deg');
        if (s1) s1.textContent = checkDegLabel(checkDegFront);
        var rf = document.getElementById('check-result-front-rotate-deg');
        if (rf) rf.textContent = checkDegLabel(checkDegFront);
        var rb = document.getElementById('check-result-back-rotate-deg');
        if (rb) rb.textContent = checkDegLabel(checkDegBack);
    }

    // Kunci tombol rotate sisi itu selama encode. Front mengunci 4 tombol (sesi1 2x + result-front 2x).
    function checkSetRotateUI(side, enabled) {
        var dis = !enabled;
        var ids = side === 'back'
            ? ['check-session2-rotate-left', 'check-session2-rotate-right',
               'check-result-back-rotate-left', 'check-result-back-rotate-right']
            : ['check-session1-rotate-left', 'check-session1-rotate-right',
               'check-result-front-rotate-left', 'check-result-front-rotate-right'];
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.disabled = dis;
        });
    }

    // Fungsi inti: satu fungsi untuk 6 tombol rotate. Blob final = preview terlihat.
    function checkRotateSide(side, dir) {
        var base = checkGetRotateBase(side);
        var cap = side === 'back' ? checkBackCapture : checkFrontCapture;
        if (!base || !cap || !cap.blob) {
            checkFeedback('Capture dulu sebelum rotate.', true);
            return;
        }
        if (checkGetRotateBusy(side)) return;
        checkSetRotateBusyFlag(side, true);
        checkSetRotateUI(side, false);
        var nextDeg = ((checkGetRotateDeg(side) + dir) % 360 + 360) % 360;
        var rendered = checkRenderBaseAtDeg(base, nextDeg);
        if (!rendered) {
            checkSetRotateBusyFlag(side, false);
            checkSetRotateUI(side, true);
            checkFeedback('Rotate gagal. Coba lagi.', true);
            return;
        }
        var rw = rendered.width;
        var rh = rendered.height;
        rendered.toBlob(function (blob) {
            if (!blob) {
                checkSetRotateBusyFlag(side, false);
                checkSetRotateUI(side, true);
                checkFeedback('Rotate gagal. Coba lagi.', true);
                return;
            }
            var oldUrl = cap.url;
            cap.blob = blob;
            cap.url = URL.createObjectURL(blob);
            cap.w = rw;
            cap.h = rh;
            cap.size = blob.size;
            try { if (oldUrl) URL.revokeObjectURL(oldUrl); } catch (e) {}
            checkSetRotateDeg(side, nextDeg);
            // Rotate ulang membuat split basi: buang 2 split sisi itu (wajib Crop ulang)
            try { checkClearSplitSide(side); } catch (e) {}
            // Update preview: FRONT = img Sesi 1 + canvas hasil; BACK = img Sesi 2 + canvas hasil.
            if (side === 'front') {
                var s1Img = document.getElementById('check-session1-img');
                if (s1Img) s1Img.src = cap.url;
                var frontCanvas = document.getElementById('check-result-front-canvas');
                if (frontCanvas && checkFrontCapture) {
                    var rc = document.getElementById('check-dual-result-card');
                    checkDrawCaptureToCanvas(frontCanvas, checkFrontCapture, rc);
                }
            } else {
                // Sesi 2 img WAJIB diupdate (preview BACK yang terlihat) — sebelumnya hanya canvas
                // hasil (hidden saat Sesi 2 tampil) yang digambar ulang sehingga rotate tak terlihat.
                var s2Img = document.getElementById('check-session2-img');
                if (s2Img) s2Img.src = cap.url;
                var backCanvas = document.getElementById('check-result-back-canvas');
                if (backCanvas && checkBackCapture) {
                    var rc2 = document.getElementById('check-dual-result-card');
                    checkDrawCaptureToCanvas(backCanvas, checkBackCapture, rc2);
                }
            }
            checkUpdateRotateUI();
            checkFeedback('Rotasi ' + checkDegLabel(nextDeg) + ' — lanjutkan bila sudah pas.', false);
            console.log('[CHECK] rotate', side, checkDegLabel(nextDeg));
            checkSetRotateBusyFlag(side, false);
            checkSetRotateUI(side, true);
        }, 'image/png');
    }

    // ===== Auto Crop helpers — tiru Reference splitLongSide (belah tengah sisi panjang, 0px overlap) =====
    // Input canvas final (hasil rotate). Output {c1,c2,horizontal} atau null bila gagal.
    function checkSplitLongSide(finalCanvas) {
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

    // Nama file split: turunkan dari filename induk + sisi + -1/-2 sebelum .png (stabil per capture).
    function checkSplitFilename(parentFilename, side, idx) {
        var base = String(parentFilename || 'check-label.png');
        var m = base.match(/^(.*)(\.[a-z0-9]+)$/i);
        var stem = m ? m[1] : base;
        var ext = m ? m[2] : '.png';
        // Hindari akhiran ganda bila crop ulang: buang -front-1/-2/-back-1/-2 lama dulu.
        stem = stem.replace(/-(front|back)-[12]$/i, '');
        return stem + '-' + side + '-' + idx + ext;
    }

    function checkGetSplit(side, idx) {
        if (side === 'back') return idx === 2 ? checkSplitBack2 : checkSplitBack1;
        return idx === 2 ? checkSplitFront2 : checkSplitFront1;
    }

    function checkSetSplit(side, idx, cap) {
        if (side === 'back') { if (idx === 2) checkSplitBack2 = cap; else checkSplitBack1 = cap; }
        else { if (idx === 2) checkSplitFront2 = cap; else checkSplitFront1 = cap; }
    }

    function checkGetSplitBusy(side) { return side === 'back' ? checkSplitBusyBack : checkSplitBusyFront; }
    function checkSetSplitBusy(side, b) { if (side === 'back') checkSplitBusyBack = !!b; else checkSplitBusyFront = !!b; }

    // Buang 2 split sisi itu (revoke URL) + sembunyikan panel + reset badge/meta/img.
    function checkClearSplitSide(side) {
        // AUTO-DIRECT effortless (2026-10-09): crop-ulang membuka kesempatan auto lagi.
        try { checkAutoResetFlagsFor(side); } catch (e) {}
        // Hentikan polling OCR sisi itu (split basi, misal rotate ulang).
        try { checkSplitAbort(side, 1); } catch (e) {}
        try { checkSplitAbort(side, 2); } catch (e) {}
        [1, 2].forEach(function (idx) {
            var cap = checkGetSplit(side, idx);
            if (cap && cap.url) { try { URL.revokeObjectURL(cap.url); } catch (e) {} }
            checkSetSplit(side, idx, null);
        });
        checkSetSplitBusy(side, false);
        var prefix = side === 'back' ? 'check-result-back-split' : null;
        var panels = side === 'back'
            ? ['check-session2-split', 'check-result-back-split']
            : ['check-session1-split', 'check-result-front-split'];
        panels.forEach(function (id) {
            var p = document.getElementById(id);
            if (p) p.classList.add('hidden');
        });
        var imgIds = side === 'back'
            ? ['check-session2-split-img-1', 'check-session2-split-img-2',
               'check-result-back-split-img-1', 'check-result-back-split-img-2']
            : ['check-session1-split-img-1', 'check-session1-split-img-2',
               'check-result-front-split-img-1', 'check-result-front-split-img-2'];
        imgIds.forEach(function (id) {
            var im = document.getElementById(id);
            if (im) { im.classList.add('hidden'); try { im.removeAttribute('src'); } catch (e) {} }
        });
        // Review Sesi 1 ikut dibersihkan bila front
        if (side !== 'back') {
            var rev = document.getElementById('check-session1-split-review');
            if (rev) { try { rev.removeAttribute('src'); } catch (e) {} }
            // Tampilkan kembali section single (kasus split basi: rotate-ulang dari tahap hasil / retake / reset)
            var sg = document.getElementById('check-session1-single');
            if (sg) sg.classList.remove('hidden');
            // Split hilang = gate tertutup → kunci Lanjut lagi (wajib Simpan ulang)
            var gotoBtnLock = document.getElementById('check-goto-session2-btn');
            if (gotoBtnLock) gotoBtnLock.disabled = true;
        } else {
            // Review Sesi 2 + single BACK + gate hasil (cermin FRONT)
            var rev2 = document.getElementById('check-session2-split-review');
            if (rev2) { try { rev2.removeAttribute('src'); } catch (e) {} }
            var sg2 = document.getElementById('check-session2-single');
            if (sg2) sg2.classList.remove('hidden');
            var gotoResLock = document.getElementById('check-goto-result-btn');
            if (gotoResLock) gotoResLock.disabled = true;
        }
        if (prefix) { /* badge/meta dibiarkan, ditimpa saat crop berikutnya */ }
    }

    // Kunci tombol crop sisi itu. Front mengunci 2 tombol (sesi1 + result-front).
    function checkSetCropUI(side, enabled) {
        var dis = !enabled;
        var ids = side === 'back'
            ? ['check-session2-crop', 'check-result-back-crop']
            : ['check-session1-crop', 'check-result-front-crop'];
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.disabled = dis;
        });
    }

    function checkSplitMetaText(cap) {
        if (!cap) return '-';
        return (cap.w || '-') + '×' + (cap.h || '-') + ' px · ' + (cap.filename || '-');
    }

    // Render 2 split sisi itu ke panel Sesi 1 (front) dan/atau kolom hasil. Dipanggil setelah crop sukses.
    function checkRenderSplitSide(side) {
        var s1 = checkGetSplit(side, 1), s2 = checkGetSplit(side, 2);
        if (side === 'front') {
            var wrapS1 = document.getElementById('check-session1-split');
            if (wrapS1 && s1 && s2) {
                wrapS1.classList.remove('hidden');
                var rev = document.getElementById('check-session1-split-review');
                if (rev && checkFrontCapture) rev.src = checkFrontCapture.url;
                var i1 = document.getElementById('check-session1-split-img-1');
                if (i1) { i1.src = s1.url; i1.classList.remove('hidden'); }
                var i2 = document.getElementById('check-session1-split-img-2');
                if (i2) { i2.src = s2.url; i2.classList.remove('hidden'); }
                var m1 = document.getElementById('check-session1-split-meta-1');
                if (m1) m1.textContent = checkSplitMetaText(s1);
                var m2 = document.getElementById('check-session1-split-meta-2');
                if (m2) m2.textContent = checkSplitMetaText(s2);
                checkSplitSetBadge('front', 1, 'idle', 'Label 1 · idle');
                checkSplitSetBadge('front', 2, 'idle', 'Label 2 · idle');
                checkSplitSetCheck('front', 1, false);
                checkSplitSetCheck('front', 2, false);
            }
            var wrapRF = document.getElementById('check-result-front-split');
            if (wrapRF && s1 && s2) {
                wrapRF.classList.remove('hidden');
                var r1 = document.getElementById('check-result-front-split-img-1');
                if (r1) { r1.src = s1.url; r1.classList.remove('hidden'); }
                var r2 = document.getElementById('check-result-front-split-img-2');
                if (r2) { r2.src = s2.url; r2.classList.remove('hidden'); }
                var rm1 = document.getElementById('check-result-front-split-meta-1');
                if (rm1) rm1.textContent = checkSplitMetaText(s1);
                var rm2 = document.getElementById('check-result-front-split-meta-2');
                if (rm2) rm2.textContent = checkSplitMetaText(s2);
                checkSplitSetBadge('front', 1, 'idle', 'Label 1 · idle');
                checkSplitSetBadge('front', 2, 'idle', 'Label 2 · idle');
                checkSplitSetCheck('front', 1, false);
                checkSplitSetCheck('front', 2, false);
            }
        } else {
            // Sesi 2 (kartu preview BACK) + kolom hasil BACK — cermin Sesi 1.
            var wrapS2 = document.getElementById('check-session2-split');
            if (wrapS2 && s1 && s2) {
                wrapS2.classList.remove('hidden');
                var v2 = document.getElementById('check-session2-split-review');
                if (v2 && checkBackCapture) v2.src = checkBackCapture.url;
                var n1 = document.getElementById('check-session2-split-img-1');
                if (n1) { n1.src = s1.url; n1.classList.remove('hidden'); }
                var n2 = document.getElementById('check-session2-split-img-2');
                if (n2) { n2.src = s2.url; n2.classList.remove('hidden'); }
                var nm1 = document.getElementById('check-session2-split-meta-1');
                if (nm1) nm1.textContent = checkSplitMetaText(s1);
                var nm2 = document.getElementById('check-session2-split-meta-2');
                if (nm2) nm2.textContent = checkSplitMetaText(s2);
                var nb1 = document.getElementById('check-session2-split-badge-1');
                if (nb1) nb1.textContent = 'siap';
                var nb2 = document.getElementById('check-session2-split-badge-2');
                if (nb2) nb2.textContent = 'siap';
            }
            var wrapRB = document.getElementById('check-result-back-split');
            if (wrapRB && s1 && s2) {
                wrapRB.classList.remove('hidden');
                var q1 = document.getElementById('check-result-back-split-img-1');
                if (q1) { q1.src = s1.url; q1.classList.remove('hidden'); }
                var q2 = document.getElementById('check-result-back-split-img-2');
                if (q2) { q2.src = s2.url; q2.classList.remove('hidden'); }
                var qm1 = document.getElementById('check-result-back-split-meta-1');
                if (qm1) qm1.textContent = checkSplitMetaText(s1);
                var qm2 = document.getElementById('check-result-back-split-meta-2');
                if (qm2) qm2.textContent = checkSplitMetaText(s2);
                var qb1 = document.getElementById('check-result-back-split-badge-1');
                if (qb1) qb1.textContent = 'siap';
                var qb2 = document.getElementById('check-result-back-split-badge-2');
                if (qb2) qb2.textContent = 'siap';
            }
        }
    }

    // Fungsi inti: satu fungsi untuk 3 tombol Crop. Belah master final rotate jadi Label 1 + Label 2.
    function checkCropSide(side, opts) {
        var skipOcr = !!(opts && opts.skipOcr); // display phase Sesi 2: crop tampil tanpa kirim OCR (pemanggil lama tanpa opts = perilaku 100% sama)
        var base = side === 'back' ? checkBaseBack : checkBaseFront;
        var deg = side === 'back' ? checkDegBack : checkDegFront;
        var cap = side === 'back' ? checkBackCapture : checkFrontCapture;
        if (!base || !cap || !cap.blob || !cap.filename_original) {
            checkFeedback('Capture dan atur rotasi dulu sebelum crop.', true);
            return;
        }
        if (checkGetSplitBusy(side)) return;
        checkSetSplitBusy(side, true);
        checkSetCropUI(side, false);
        checkSetRotateUI(side, false);
        var finalCanvas = checkRenderBaseAtDeg(base, deg);
        if (!finalCanvas) {
            checkSetSplitBusy(side, false);
            checkSetCropUI(side, true);
            checkSetRotateUI(side, true);
            checkFeedback('Crop gagal. Coba lagi.', true);
            return;
        }
        var parts = checkSplitLongSide(finalCanvas);
        if (!parts) {
            checkSetSplitBusy(side, false);
            checkSetCropUI(side, true);
            checkSetRotateUI(side, true);
            checkFeedback('Crop gagal. Coba lagi.', true);
            return;
        }
        var c1 = parts.c1, c2 = parts.c2;
        var w1 = c1.width, h1 = c1.height, w2 = c2.width, h2 = c2.height;
        c1.toBlob(function (blob1) {
            if (!blob1) {
                checkSetSplitBusy(side, false);
                checkSetCropUI(side, true);
                checkSetRotateUI(side, true);
                checkFeedback('Crop gagal. Coba lagi.', true);
                return;
            }
            c2.toBlob(function (blob2) {
                if (!blob2) {
                    checkSetSplitBusy(side, false);
                    checkSetCropUI(side, true);
                    checkSetRotateUI(side, true);
                    checkFeedback('Crop gagal. Coba lagi.', true);
                    return;
                }
                // Buang split lama sisi itu dulu (crop ulang aman)
                [1, 2].forEach(function (idx) {
                    var old = checkGetSplit(side, idx);
                    if (old && old.url) { try { URL.revokeObjectURL(old.url); } catch (e) {} }
                });
                var f1 = checkSplitFilename(cap.filename_original, side === 'back' ? 'back' : 'front', 1);
                var f2 = checkSplitFilename(cap.filename_original, side === 'back' ? 'back' : 'front', 2);
                checkSetSplit(side, 1, { blob: blob1, url: URL.createObjectURL(blob1), filename: f1, w: w1, h: h1, size: blob1.size });
                checkSetSplit(side, 2, { blob: blob2, url: URL.createObjectURL(blob2), filename: f2, w: w2, h: h2, size: blob2.size });
                checkRenderSplitSide(side);
                // Split OCR per Label: kirim 2 crop ke FastAPI single OCR (async, tidak blokir UI).
                // Fase display Sesi 2 (skipOcr) melewati baris ini — tanpa request, tanpa teks OCR.
                if (!skipOcr) {
                    try { checkSplitOcrFor(side); } catch (e) { console.warn('[CHECK SPLIT] trigger failed', e); }
                }
                // FRONT Sesi 1: sembunyikan section single (master + rotate + Simpan) setelah crop sukses —
                // mirip Reference (adjust disembunyikan setelah Simpan). Ulangi via Retake Front.
                // Crop sukses = gate terpenuhi → aktifkan Lanjut ke Capture 2.
                if (side === 'front') {
                    var singleGrid = document.getElementById('check-session1-single');
                    if (singleGrid) singleGrid.classList.add('hidden');
                    // Nav-gate: kunci Lanjut selama OCR berjalan; skipOcr (display tanpa OCR) langsung dibuka.
                    if (skipOcr) { checkSplitNavMark(side, 1, true); checkSplitNavMark(side, 2, true); }
                    else { checkSplitNavMark(side, 1, false); checkSplitNavMark(side, 2, false); try { checkOcrLoaderShow(side); } catch (e) {} }
                    var sFbOk = document.getElementById('check-session1-feedback');
                    if (sFbOk) sFbOk.textContent = '';
                } else {
                    // BACK Sesi 2: sembunyikan section single + aktifkan Lihat Hasil 2 Sisi (cermin FRONT).
                    var singleGrid2 = document.getElementById('check-session2-single');
                    if (singleGrid2) singleGrid2.classList.add('hidden');
                    // Nav-gate: kunci Lihat Hasil selama OCR berjalan (cermin FRONT).
                    if (skipOcr) { checkSplitNavMark(side, 1, true); checkSplitNavMark(side, 2, true); }
                    else { checkSplitNavMark(side, 1, false); checkSplitNavMark(side, 2, false); try { checkOcrLoaderShow(side); } catch (e) {} }
                    var s2FbOk = document.getElementById('check-session2-feedback');
                    if (s2FbOk) s2FbOk.textContent = '';
                }
                var orient = parts.horizontal ? 'horizontal' : 'vertical';
                checkFeedback('Crop ' + side + ' jadi Label 1 + Label 2 (' + orient + '). Siap diproses.', false);
                console.log('[CHECK] crop', side, 'Label1+2', f1, f2);
                checkSetSplitBusy(side, false);
                checkSetCropUI(side, true);
                checkSetRotateUI(side, true);
            }, 'image/png');
        }, 'image/png');
    }

    // ===== Split OCR per Label — Checking 2-capture (PLAN_CHECKING_LABEL_2_CAPTURE_OCR_CALLBACK) =====
    // 1x Simpan crop -> 2 split (Label 1 + Label 2) -> POST /api/check/split-preview per split
    // -> FastAPI POST /api/test/status (single OCR) -> callback cleaned_text
    // -> GET /api/check/split-ocr-status polling 1 URL/poll -> render card Hasil ekstraksi teks.
    // Retake 1 sisi tidak menghapus sisi lain (state + baris DB per sisi independen).
    var checkSplitSessionCode = null;
    var checkSplitSaved = { front: { 1: null, 2: null }, back: { 1: null, 2: null } };
    var checkSplitTimers = { front: { 1: null, 2: null }, back: { 1: null, 2: null } };
    var checkSplitTexts = { front: { 1: '', 2: '' }, back: { 1: '', 2: '' } };
    var checkSession1Tab = 1;
    // PLAN_CHECKING_LABEL_BACK_SIDE_OCR B2: tab aktif card Sesi 2 BACK (cermin Sesi 1).
    var checkSession2Tab = 1;

    function checkSplitEnsureSession() {
        if (!checkSplitSessionCode) {
            try { checkSplitSessionCode = checkBuildBatchCode(new Date()); }
            catch (e) { checkSplitSessionCode = 'CHK-' + Date.now(); }
        }
        return checkSplitSessionCode;
    }

    function checkSplitGlobalSlot(side, idx) {
        return side === 'back' ? idx + 2 : idx;
    }

    function checkSplitBadgeIds(side, idx) {
        if (side === 'back') return ['check-session2-split-badge-' + idx, 'check-result-back-split-badge-' + idx];
        return ['check-session1-split-badge-' + idx, 'check-result-front-split-badge-' + idx];
    }

    function checkSplitTextIds(side, idx) {
        if (side === 'back') return ['check-result-back-split-text-' + idx];
        return ['check-result-front-split-text-' + idx];
    }

    function checkSplitMetaIds(side, idx) {
        if (side === 'back') return ['check-session2-split-meta-' + idx, 'check-result-back-split-meta-' + idx];
        return ['check-session1-split-meta-' + idx, 'check-result-front-split-meta-' + idx];
    }

    var CHECK_SPLIT_BADGE_CLS = {
        idle: 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30',
        processing: 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30',
        success: 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-white text-[#212529] border-emerald-300',
        failed: 'px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E04438]/10 text-[#E04438] border-[#E04438]/30'
    };

    function checkSplitCheckIds(side, idx) {
        if (side === 'back') return ['check-session2-split-check-' + idx, 'check-result-back-split-check-' + idx];
        return ['check-session1-split-check-' + idx, 'check-result-front-split-check-' + idx];
    }

    function checkSplitSetCheck(side, idx, show) {
        var ids = checkSplitCheckIds(side, idx);
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            if (show) el.classList.remove('hidden');
            else el.classList.add('hidden');
        });
    }

    // Nav-gate anti-interferensi: tombol "Lanjut ke Capture 2 (Back)" / "Lihat Hasil 2 Sisi"
    // dikunci selama ekstraksi teks sisi itu berjalan, dibuka saat 2 label terminal
    // (success / failed / empty / timeout / tunggu-429). Cegah user pindah tahap di tengah OCR.
    var checkSplitNavDone = { front: { 1: false, 2: false }, back: { 1: false, 2: false } };

    function checkSplitNavRefresh(side) {
        var id = side === 'back' ? 'check-goto-result-btn' : 'check-goto-session2-btn';
        var btn = document.getElementById(id);
        if (!btn) return;
        var d = checkSplitNavDone[side] || { 1: false, 2: false };
        var hasSplit = !!(checkGetSplit(side, 1) && checkGetSplit(side, 2));
        btn.disabled = !(hasSplit && d[1] && d[2]);
        // Sinkron loader inline dengan nav-gate (TD-012): tombol enabled = OCR selesai = loader hilang.
        try {
            if (btn.disabled === false) checkOcrLoaderHide(side);
            else if (hasSplit && !(d[1] && d[2])) checkOcrLoaderShow(side);
        } catch (e) {}
        // AUTO-DIRECT #1 effortless (2026-10-09): Front 1·2 terminal → auto Lanjut ke Capture 2.
        if (side === 'front' && !btn.disabled && checkAutoEnabled() && !checkAutoFired.toSession2) {
            checkAutoClick('check-goto-session2-btn', 'toSession2', 800,
                'check-session1-feedback', 'OCR Front selesai — lanjut otomatis ke Capture 2…',
                function () {
                    return !!(checkGetSplit('front', 1) && checkGetSplit('front', 2))
                        && checkScanStage === 'preview1';
                });
        }
        // AUTO-DIRECT #2 effortless (2026-10-09): Back 1·2 terminal → auto Lihat Hasil 2 Sisi.
        if (side === 'back' && !btn.disabled && checkAutoEnabled() && !checkAutoFired.toResult) {
            checkAutoClick('check-goto-result-btn', 'toResult', 800,
                'check-session2-feedback', 'OCR Back selesai — menampilkan hasil 2 sisi…',
                function () {
                    return !!(checkGetSplit('back', 1) && checkGetSplit('back', 2))
                        && checkScanStage === 'preview2';
                });
        }
    }

    function checkSplitNavMark(side, idx, done) {
        if (checkSplitNavDone[side]) checkSplitNavDone[side][idx] = !!done;
        checkSplitNavRefresh(side);
    }

    // AUTO-DIRECT effortless (2026-10-09): orkestrasi 3 klik lanjutan tanpa aksi manual.
    // Prinsip: HANYA klik saat tombol sudah enabled alami (nav-gate), selalu re-validasi
    // split sebelum klik, selalu bisa dibatalkan via Retake / ?auto=0 / toggle.
    var checkAutoFired = { toSession2: false, toResult: false, toProcess: false };
    var checkAutoTimers = { toSession2: null, toResult: null, toProcess: null };

    function checkAutoEnabled() {
        try {
            var q = new URLSearchParams(window.location.search).get('auto');
            if (q === '0') return false;
            var t = document.getElementById('check-auto-direct-toggle');
            if (t && t.checked === false) return false;
        } catch (e) {}
        return true;
    }
    function checkAutoClear(name) {
        try { if (checkAutoTimers[name]) { clearTimeout(checkAutoTimers[name]); checkAutoTimers[name] = null; } } catch (e) {}
    }
    function checkAutoClearAll() {
        checkAutoClear('toSession2'); checkAutoClear('toResult'); checkAutoClear('toProcess');
    }
    function checkAutoResetFlagsFor(side) {
        if (side === 'front') { checkAutoFired.toSession2 = false; checkAutoClear('toSession2'); }
        if (side === 'back') { checkAutoFired.toResult = false; checkAutoClear('toResult'); }
    }
    function checkAutoClick(btnId, timerName, delayMs, feedbackId, feedbackText, validateFn) {
        if (!checkAutoEnabled() || checkAutoFired[timerName]) return;
        var btn = document.getElementById(btnId);
        if (!btn || btn.disabled) return;
        if (typeof validateFn === 'function' && !validateFn()) return;
        checkAutoFired[timerName] = true;
        if (feedbackId) { var fb = document.getElementById(feedbackId); if (fb) fb.textContent = feedbackText; }
        checkAutoClear(timerName);
        checkAutoTimers[timerName] = setTimeout(function () {
            try {
                var b2 = document.getElementById(btnId);
                if (!b2 || b2.disabled) return;
                if (typeof validateFn === 'function' && !validateFn()) return;
                if (!checkAutoEnabled()) return;
                b2.click();
            } catch (e) { console.warn('[CHECK AUTO] click failed ' + btnId, e); }
        }, delayMs);
    }

    // ===== Inline OCR loader + Process overlay (loader motion minimalist) =====
    // Semua helper null-safe (FE-09): Blade boleh tidak punya ID baru, JS tidak boleh crash.
    var checkProcessOverlayTimer = null;
    function checkOcrLoaderShow(side) {
        var id = side === 'back' ? 'check-session2-ocr-loader' : 'check-session1-ocr-loader';
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        if (!el.classList.contains('inline-flex')) el.classList.add('inline-flex');
    }
    function checkOcrLoaderHide(side) {
        var id = side === 'back' ? 'check-session2-ocr-loader' : 'check-session1-ocr-loader';
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('inline-flex');
    }
    function checkOcrLoaderHideIfDone(side) {
        if (checkSplitNavDone[side] && checkSplitNavDone[side][1] && checkSplitNavDone[side][2]) checkOcrLoaderHide(side);
    }
    function checkProcessOverlayShow() {
        var el = document.getElementById('check-process-overlay');
        if (!el) return;
        el.classList.remove('hidden');
        if (!el.classList.contains('flex')) el.classList.add('flex');
        try {
            if (checkProcessOverlayTimer) clearTimeout(checkProcessOverlayTimer);
            checkProcessOverlayTimer = setTimeout(function () { checkProcessOverlayHide(); }, 8000);
        } catch (e) {}
    }
    function checkProcessOverlayHide() {
        var el = document.getElementById('check-process-overlay');
        if (!el) return;
        el.classList.add('hidden');
        el.classList.remove('flex');
        try {
            if (checkProcessOverlayTimer) { clearTimeout(checkProcessOverlayTimer); checkProcessOverlayTimer = null; }
        } catch (e) {}
    }

    function checkSplitSetBadge(side, idx, state, text) {
        var ids = checkSplitBadgeIds(side, idx);
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = text || state;
            el.className = CHECK_SPLIT_BADGE_CLS[state] || CHECK_SPLIT_BADGE_CLS.idle;
        });
    }

    function checkSplitSetText(side, idx, text) {
        checkSplitTexts[side][idx] = text;
        var ids = checkSplitTextIds(side, idx);
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = text;
        });
        if (side === 'front') checkSession1RenderTab();
        // PLAN_CHECKING_LABEL_BACK_SIDE_OCR B3: teks BACK tampil di view Sesi 2 (cermin FRONT).
        else checkSession2RenderTab();
    }

    function checkSplitSetMeta(side, idx, ocrInfo) {
        var cap = checkGetSplit(side, idx);
        var base = checkSplitMetaText(cap);
        var ids = checkSplitMetaIds(side, idx);
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = ocrInfo ? (base + ' · ' + ocrInfo) : base;
        });
    }

    function checkSession1RenderTab() {
        var view = document.getElementById('check-session1-ocr-view');
        var t1 = document.getElementById('check-session1-tab-1');
        var t2 = document.getElementById('check-session1-tab-2');
        var active = 'px-3.5 py-1.5 rounded-full text-xs font-semibold border bg-[#0284C7] text-white border-[#0284C7] transition focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed';
        var idle = 'px-3.5 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed';
        if (t1) t1.className = checkSession1Tab === 1 ? active : idle;
        if (t2) t2.className = checkSession1Tab === 2 ? active : idle;
        if (view) {
            var txt = checkSplitTexts.front[checkSession1Tab] || '';
            view.textContent = txt !== '' ? txt : 'Hasil ekstraksi akan tampil di sini setelah proses OCR (tahap berikutnya).';
        }
    }

    function checkSession2RenderTab() {
        var view = document.getElementById('check-session2-ocr-view');
        var t1 = document.getElementById('check-session2-tab-1');
        var t2 = document.getElementById('check-session2-tab-2');
        var active = 'px-3.5 py-1.5 rounded-full text-xs font-semibold border bg-[#0284C7] text-white border-[#0284C7] transition focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed';
        var idle = 'px-3.5 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed';
        if (t1) t1.className = checkSession2Tab === 1 ? active : idle;
        if (t2) t2.className = checkSession2Tab === 2 ? active : idle;
        if (view) {
            var txt = checkSplitTexts.back[checkSession2Tab] || '';
            view.textContent = txt !== '' ? txt : 'Hasil ekstraksi akan tampil di sini setelah proses OCR (tahap berikutnya).';
        }
    }

    function checkSplitWireTabs() {
        var t1 = document.getElementById('check-session1-tab-1');
        var t2 = document.getElementById('check-session1-tab-2');
        if (t1 && !t1.dataset.wired) {
            t1.dataset.wired = '1';
            t1.addEventListener('click', function (e) { e.preventDefault(); checkSession1Tab = 1; checkSession1RenderTab(); });
        }
        if (t2 && !t2.dataset.wired) {
            t2.dataset.wired = '1';
            t2.addEventListener('click', function (e) { e.preventDefault(); checkSession1Tab = 2; checkSession1RenderTab(); });
        }
        // PLAN_CHECKING_LABEL_BACK_SIDE_OCR B2: wiring tab Sesi 2 BACK (cermin Sesi 1).
        var s1 = document.getElementById('check-session2-tab-1');
        var s2 = document.getElementById('check-session2-tab-2');
        if (s1 && !s1.dataset.wired) {
            s1.dataset.wired = '1';
            s1.addEventListener('click', function (e) { e.preventDefault(); checkSession2Tab = 1; checkSession2RenderTab(); });
        }
        if (s2 && !s2.dataset.wired) {
            s2.dataset.wired = '1';
            s2.addEventListener('click', function (e) { e.preventDefault(); checkSession2Tab = 2; checkSession2RenderTab(); });
        }
    }

    // FIX-OCR-COLLAPSE (CP-075) — Check collapsible Hasil ekstraksi teks, default tertutup (cermin reference.js CP-074).
    // Render tab SENGAJA tidak memaksa show body (P3 planning).
    function checkOcrToggleIds(side) {
        if (side === 'back') return { toggle: 'check-session2-ocr-toggle', body: 'check-session2-ocr-body' };
        return { toggle: 'check-session1-ocr-toggle', body: 'check-session1-ocr-body' };
    }
    function checkOcrToggleSet(side, open) {
        var ids = checkOcrToggleIds(side);
        var toggle = document.getElementById(ids.toggle);
        var body = document.getElementById(ids.body);
        if (!toggle || !body) return;
        if (open) body.classList.remove('hidden');
        else body.classList.add('hidden');
        try { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); } catch (e) {}
        var label = null;
        try { label = toggle.querySelector('[data-ocr-toggle-label]'); } catch (e) { label = null; }
        if (label) label.textContent = open ? 'Sembunyikan' : 'Lihat teks';
        var chev = null;
        try { chev = toggle.querySelector('svg'); } catch (e) { chev = null; }
        if (chev) { if (open) chev.classList.add('rotate-180'); else chev.classList.remove('rotate-180'); }
    }
    function checkOcrToggleWireOne(side) {
        var ids = checkOcrToggleIds(side);
        var toggle = document.getElementById(ids.toggle);
        var body = document.getElementById(ids.body);
        if (!toggle || !body) return;
        if (toggle.dataset && toggle.dataset.wired) return;
        if (toggle.dataset) toggle.dataset.wired = '1';
        toggle.addEventListener('click', function (e) {
            if (e) { try { e.preventDefault(); } catch (err) {} }
            var isHidden = body.classList.contains('hidden');
            checkOcrToggleSet(side, isHidden);
        });
    }
    function checkOcrToggleWire() {
        checkOcrToggleWireOne('front');
        checkOcrToggleWireOne('back');
    }

    function checkSplitAbort(side, idx) {
        var timers = checkSplitTimers[side];
        if (timers && timers[idx]) { try { clearTimeout(timers[idx]); } catch (e) {} timers[idx] = null; }
    }

    function checkSplitOcrFor(side) {
        if (!currentRef || !currentRef.id) { checkFeedback('Belum ada Reference acuan.', true); return; }
        var token = checkGetToken();
        if (!token) return;
        var sessionCode = checkSplitEnsureSession();
        try { checkOcrLoaderShow(side); } catch (e) {}
        [1, 2].forEach(function (idx) {
            var cap = checkGetSplit(side, idx);
            if (!cap || !cap.blob) return;
            checkSplitAbort(side, idx);
            checkSplitSaved[side][idx] = null;
            checkSplitSetBadge(side, idx, 'processing', 'Label ' + idx + ' · processing');
            checkSplitSetCheck(side, idx, false);
            checkSplitNavMark(side, idx, false);
            checkSplitSetText(side, idx, 'Menunggu hasil OCR…');
            checkSplitSetMeta(side, idx, 'OCR processing…');
            checkSplitPostPreview(side, idx, cap, sessionCode, token);
        });
    }

    function checkSplitPostPreview(side, idx, cap, sessionCode, token) {
        var fd = new FormData();
        fd.append('image', cap.blob, cap.filename);
        fd.append('check_session_code', sessionCode);
        fd.append('side', side);
        fd.append('label_slot', String(idx));
        fd.append('template_id', currentRef.template_id || 'M');
        fd.append('template_name', currentRef.template_name || currentRef.template_id || 'M');
        fd.append('template_width', String(currentRef.template_width || 160));
        fd.append('template_height', String(currentRef.template_height || 35));
        fd.append('filename', cap.filename);
        fd.append('resolution_w', String(cap.w || 0));
        fd.append('resolution_h', String(cap.h || 0));
        fd.append('filesize', String(cap.size || cap.blob.size || 0));
        fetch('/api/check/split-preview', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
            body: fd
        }).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok) {
                    var raPrev = null;
                    try { raPrev = res.headers.get('Retry-After'); } catch (e) {}
                    throw { status: res.status, body: body, retryAfterHeader: raPrev };
                }
                return body;
            });
        }).then(function (json) {
            var d = (json && json.data) || {};
            if (!d.saved_filename) throw { status: 0, body: json };
            // Guard: abaikan bila split sudah diganti (crop ulang / retake) selama request jalan.
            var cur = checkGetSplit(side, idx);
            if (!cur || cur.filename !== cap.filename) {
                console.warn('[CHECK SPLIT] stale preview ignored side=' + side + ' idx=' + idx);
                return;
            }
            checkSplitSaved[side][idx] = d.saved_filename;
            console.log('[CHECK SPLIT] queued side=' + side + ' idx=' + idx + ' saved=' + d.saved_filename);
            checkSplitPoll(side, idx, d.saved_filename, token, 0);
        }).catch(function (err) {
            var status = err && err.status;
            var body = err && err.body;
            if (status === 429) {
                // FIX 429 (2026-10-08): bedakan sumber 429, kunci crop selama countdown.
                var wait = parseInt((err && err.retryAfterHeader) || (body && body.retry_after_seconds) || '60', 10);
                if (isNaN(wait) || wait < 2) wait = 60;
                if (wait > 120) wait = 120;
                var isFastApi = body && body.status === 'rate_limited';
                var msg = isFastApi
                    ? 'FastAPI sibuk (5 gambar/menit) — tunggu ' + wait + ' detik lalu crop ulang sisi ini. Tidak perlu restart.'
                    : 'Terlalu banyak permintaan (throttle) — tunggu ' + wait + ' detik lalu crop ulang sisi ini.';
                checkSplitSetBadge(side, idx, 'processing', 'Label ' + idx + ' · tunggu ' + wait + 's…');
                checkSplitSetCheck(side, idx, false);
                checkSplitNavMark(side, idx, true);
                try { checkOcrLoaderHideIfDone(side); } catch (e) {}
                checkSplitSetText(side, idx, msg);
                try { checkSetCropUI(side, false); } catch (e) {}
                console.warn('[CHECK SPLIT] 429 side=' + side + ' idx=' + idx + ' retry_after=' + wait + ' src=' + (isFastApi ? 'fastapi' : 'laravel'));
                // Countdown badge + buka kunci crop otomatis agar user tidak spam POST baru.
                (function (s, i, total) {
                    var left = total;
                    var tick = setInterval(function () {
                        left -= 1;
                        if (left <= 0) {
                            clearInterval(tick);
                            try { checkSetCropUI(s, true); } catch (e) {}
                            checkSplitSetBadge(s, i, 'processing', 'Label ' + i + ' · siap crop ulang');
                            return;
                        }
                        checkSplitSetBadge(s, i, 'processing', 'Label ' + i + ' · tunggu ' + left + 's…');
                    }, 1000);
                })(side, idx, wait);
                return;
            }
            if (status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                checkFeedback('Sesi berakhir. Silakan login kembali.', true);
                return;
            }
            checkSplitSetBadge(side, idx, 'failed', 'Label ' + idx + ' · failed');
            checkSplitSetCheck(side, idx, false);
            checkSplitNavMark(side, idx, true);
            try { checkOcrLoaderHideIfDone(side); } catch (e) {}
            checkSplitSetText(side, idx, 'Gagal mengirim ke OCR. Coba crop ulang sisi ini.');
            console.warn('[CHECK SPLIT] preview failed side=' + side + ' idx=' + idx, err);
        });
    }

    // FIX timeout Label 1/2 (2026-10-06): OCR 60-90s, batas lama 10x3s=30s selalu kalah.
    // Baru 40x3s=120s. Habis 120s BUKAN failed, tapi "masih processing, klik Cek Lagi" (re-attach filename lama).
    // FIX 429 (2026-10-08): long-poll wait=8 + interval 10s -> 12x10s=120s.
    // Hemat throttle Laravel (split-status 120/menit) + FastAPI GET.
    var CHECK_SPLIT_POLL_MAX = 12;
    var CHECK_SPLIT_POLL_MS = 10000;
    var CHECK_SPLIT_POLL_WAIT = 8;
    function checkSplitPoll(side, idx, saved, token, attempt) {
        checkSplitAbort(side, idx);
        if (attempt >= CHECK_SPLIT_POLL_MAX) {
            checkSplitSetBadge(side, idx, 'processing', 'Label ' + idx + ' · masih processing');
            checkSplitSetCheck(side, idx, false);
            checkSplitNavMark(side, idx, true);
            try { checkOcrLoaderHideIfDone(side); } catch (e) {}
            checkSplitSetText(side, idx, 'Masih processing setelah 120 detik. Klik Cek Lagi (tanpa crop ulang) — hasil FastAPI biasanya sudah ada.');
            checkSplitSetMeta(side, idx, 'Klik Cek Lagi untuk lanjut polling ' + saved);
            // Tampilkan tombol Cek Lagi bila ada (lihat checkSplitShowRetry). Jangan auto-failed.
            try { checkSplitShowRetry(side, idx, saved, token); } catch (e) {}
            return;
        }
        try { checkSplitSetMeta(side, idx, 'OCR processing... ' + (attempt * 10) + 's / maks 120s'); } catch (e) {}
        checkSplitTimers[side][idx] = setTimeout(function () {
            var pollUrl = '/api/check/split-ocr-status?filename=' + encodeURIComponent(saved) + '&wait=' + CHECK_SPLIT_POLL_WAIT;
            fetch(pollUrl, {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
            }).then(function (res) {
                return res.json().then(function (body) {
                    var raH = null;
                    try { raH = res.headers.get('Retry-After'); } catch (e) {}
                    return { status: res.status, body: body, retryAfterHeader: raH };
                });
            }).then(function (out) {
                // Guard anti-stale: hanya terima filename yang masih aktif untuk sisi ini.
                if (checkSplitSaved[side][idx] !== saved) {
                    console.warn('[CHECK SPLIT] stale poll ignored side=' + side + ' idx=' + idx);
                    return;
                }
                var code = out.status, b = out.body || {}, d = b.data || {};
                if ((code === 200 && b.success && (d.cleaned_text !== undefined && d.cleaned_text !== null)) || b.source === 'database' || b.source === 'external') {
                    var txt = d.cleaned_text != null ? String(d.cleaned_text) : '';
                    if (txt.trim() === '') {
                        checkSplitSetBadge(side, idx, 'failed', 'Label ' + idx + ' · empty');
                        checkSplitSetCheck(side, idx, false);
                        checkSplitNavMark(side, idx, true);
                        try { checkOcrLoaderHideIfDone(side); } catch (e) {}
                        checkSplitSetText(side, idx, 'OCR kosong — coba retake sisi ini dengan pencahayaan lebih terang.');
                        checkSplitSetMeta(side, idx, 'OCR empty');
                        return;
                    }
                    checkSplitSetBadge(side, idx, 'success', 'Label ' + idx + ' · success');
                    checkSplitSetCheck(side, idx, true);
                    checkSplitNavMark(side, idx, true);
                    try { checkOcrLoaderHideIfDone(side); } catch (e) {}
                    checkSplitSetText(side, idx, txt);
                    var eng = d.engine ? ('engine ' + d.engine) : 'OCR success';
                    checkSplitSetMeta(side, idx, eng);
                    console.log('[CHECK SPLIT] success side=' + side + ' idx=' + idx + ' (' + txt.length + ' char)');
                    return;
                }
                if (code === 202 || (b.status === 'processing')) {
                    checkSplitSetBadge(side, idx, 'processing', 'Label ' + idx + ' · processing');
                    checkSplitPoll(side, idx, saved, token, attempt + 1);
                    return;
                }
                if (code === 429) {
                    // FIX 429 (2026-10-08): hormati Retry-After + jitter, jangan retry ketat.
                    var raP = parseInt((out && out.retryAfterHeader) || (b && b.retry_after_seconds) || '10', 10);
                    if (isNaN(raP) || raP < 2) raP = 10;
                    if (raP > 60) raP = 60;
                    var jitP = Math.floor(Math.random() * 2000);
                    checkSplitSetBadge(side, idx, 'processing', 'Label ' + idx + ' · tunggu ' + raP + 's…');
                    try { checkSplitSetMeta(side, idx, 'Throttle — lanjut otomatis ' + raP + 's'); } catch (e) {}
                    console.warn('[CHECK SPLIT] poll 429 side=' + side + ' idx=' + idx + ' backoff=' + raP + 's');
                    checkSplitAbort(side, idx);
                    checkSplitTimers[side][idx] = setTimeout(function () {
                        checkSplitPoll(side, idx, saved, token, attempt + 1);
                    }, (raP * 1000) + jitP);
                    return;
                }
                if (code === 422 && b.status === 'empty') {
                    checkSplitSetBadge(side, idx, 'failed', 'Label ' + idx + ' · empty');
                    checkSplitSetCheck(side, idx, false);
                    checkSplitNavMark(side, idx, true);
                    try { checkOcrLoaderHideIfDone(side); } catch (e) {}
                    checkSplitSetText(side, idx, 'OCR kosong — coba retake sisi ini dengan pencahayaan lebih terang.');
                    checkSplitSetMeta(side, idx, 'OCR empty');
                    return;
                }
                // 404 not_found → masih antre callback, lanjut poll.
                checkSplitPoll(side, idx, saved, token, attempt + 1);
            }).catch(function (e) {
                console.warn('[CHECK SPLIT] poll error side=' + side + ' idx=' + idx, e);
                checkSplitPoll(side, idx, saved, token, attempt + 1);
            });
        }, CHECK_SPLIT_POLL_MS);
    }

    function checkSplitRetry(side, idx) {
        var saved = (typeof checkSplitSaved !== 'undefined' && checkSplitSaved[side]) ? checkSplitSaved[side][idx] : null;
        var token = null;
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!saved || !token) return;
        checkSplitSetBadge(side, idx, 'processing', 'Label ' + idx + ' · lanjut polling');
        checkSplitSetText(side, idx, 'Lanjut polling tanpa crop ulang...');
        checkSplitPoll(side, idx, saved, token, 0);
    }
    function checkSplitShowRetry(side, idx, saved, token) {
        // Fallback tanpa ubah Blade: log + expose ke window agar bisa dipanggil dari console/tombol bila ada.
        // Jangan auto-loop infinite. Tunggu aksi user (klik Cek Lagi).
        console.log('[CHECK SPLIT] batas 120s habis, siap lanjut polling saved=' + saved);
        try { window.checkSplitRetry = checkSplitRetry; } catch (e) {}
    }

    // Reset state OCR sisi itu: hentikan polling, kembalikan badge/teks, hapus baris DB (best-effort).
    function checkSplitOcrResetSide(side) {
        [1, 2].forEach(function (idx) {
            checkSplitAbort(side, idx);
            checkSplitSaved[side][idx] = null;
            checkSplitTexts[side][idx] = '';
            checkSplitSetBadge(side, idx, 'idle', 'Label ' + idx + ' · idle');
            checkSplitSetCheck(side, idx, false);
            checkSplitNavMark(side, idx, false);
            try { checkOcrLoaderHide(side); } catch (e) {}
            checkSplitSetText(side, idx, '-');
            var cap = checkGetSplit(side, idx);
            var base = checkSplitMetaText(cap);
            checkSplitMetaIds(side, idx).forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.textContent = cap ? base : '-';
            });
            // Best-effort hapus baris DB agar tidak stale saat crop ulang.
            try {
                var token = checkGetToken();
                if (token && checkSplitSessionCode) {
                    var fd = new FormData();
                    fd.append('check_session_code', checkSplitSessionCode);
                    fd.append('side', side);
                    fd.append('label_slot', String(idx));
                    fetch('/api/check/split-retake-cleanup', {
                        method: 'POST',
                        headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                        body: fd
                    }).catch(function () {});
                }
            } catch (e) {}
        });
        // FIX-OCR-COLLAPSE (CP-075) — retake/reset kembali tertutup default.
        try { checkOcrToggleSet(side, false); } catch (e) {}
        if (side === 'front') checkSession1RenderTab();
        // PLAN_CHECKING_LABEL_BACK_SIDE_OCR B4: reset BACK kembalikan tab Sesi 2 ke Label 1.
        else checkSession2RenderTab();
    }

    // ===== Dual-side Scan UI Functions (UI only, mirrors Reference DUAL-SIDE) =====
    function checkDualResetAll() {
        // AUTO-DIRECT effortless (2026-10-09): Reset membatalkan semua auto pending + buka flag lagi.
        try { checkAutoClearAll(); } catch (e) {}
        try { checkAutoFired.toSession2 = false; checkAutoFired.toResult = false; checkAutoFired.toProcess = false; } catch (e) {}
        checkScanStage = 1;
        // D2: kembali ke mode single (reset ekspektasi slot dual).
        checkExpectedSlots = null;
        checkDualAutoProcess = false;
        // Revoke any existing capture URLs BEFORE nulling
        if (checkFrontCapture && checkFrontCapture.url) {
            try { URL.revokeObjectURL(checkFrontCapture.url); } catch (e) {}
        }
        if (checkBackCapture && checkBackCapture.url) {
            try { URL.revokeObjectURL(checkBackCapture.url); } catch (e) {}
        }
        checkFrontCapture = null;
        checkBackCapture = null;
        // Reset Set Rotation per sisi (base + derajat + busy)
        checkBaseFront = null; checkDegFront = 0; checkBusyFront = false;
        checkBaseBack = null; checkDegBack = 0; checkBusyBack = false;
        // Reset Auto Crop split (buang 4 blob split)
        try { checkClearSplitSide('front'); } catch (e) {}
        try { checkClearSplitSide('back'); } catch (e) {}
        // Reset OCR split + sesi (full reset = pasangan baru berikutnya).
        try { checkSplitOcrResetSide('front'); } catch (e) {}
        try { checkSplitOcrResetSide('back'); } catch (e) {}
        checkSplitSessionCode = null;
        checkSession1Tab = 1;
        // PLAN_CHECKING_LABEL_BACK_SIDE_OCR B4: reset tab Sesi 2 ke Label 1.
        checkSession2Tab = 1;
        try { checkUpdateRotateUI(); } catch (e) {}
        try { checkSetRotateUI('front', true); } catch (e) {}
        try { checkSetRotateUI('back', true); } catch (e) {}
        // Hide all dual UI elements
        var stageWrap = document.getElementById('check-stage-stepper-wrap');
        if (stageWrap) stageWrap.classList.add('hidden');
        var frontMini = document.getElementById('check-dual-front-mini');
        if (frontMini) frontMini.remove();
        var resultCard = document.getElementById('check-dual-result-card');
        if (resultCard) resultCard.classList.add('hidden');
        var resultActions = document.getElementById('check-dual-result-actions');
        if (resultActions) resultActions.classList.add('hidden');
        var retakeFront = document.getElementById('check-retake-front');
        if (retakeFront) retakeFront.classList.add('hidden');
        var retakeBack = document.getElementById('check-retake-back');
        if (retakeBack) retakeBack.classList.add('hidden');
        // Preview Sesi 1 (single FRONT) — disembunyikan saat reset
        var session1Card = document.getElementById('check-session1-card');
        if (session1Card) session1Card.classList.add('hidden');
        // Preview Sesi 2 (single BACK) — disembunyikan saat reset (cermin Sesi 1)
        var session2CardR = document.getElementById('check-session2-card');
        if (session2CardR) session2CardR.classList.add('hidden');
        var processHelp = document.getElementById('check-dual-process-help');
        if (processHelp) processHelp.classList.add('hidden');
        var processFeedback = document.getElementById('check-dual-process-feedback');
        if (processFeedback) processFeedback.textContent = '';
        // Hide label dimension badge
        var labelDimBadge = document.getElementById('check-label-dim-badge');
        if (labelDimBadge) labelDimBadge.classList.add('hidden');
        // Reset stepper tahapan sesi (idle, teks dasar)
        checkStageStepper();
        // Redraw guide for stage 1
        checkDrawGuide();
    }

    function checkDualEnterStage1() {
        checkScanStage = 1;
        // Tampilkan information section tahapan sesi (mirror Reference)
        var stageWrapShow = document.getElementById('check-stage-stepper-wrap');
        if (stageWrapShow) stageWrapShow.classList.remove('hidden');
        // Hide mini preview, session1 preview, result card, actions
        var frontMini = document.getElementById('check-dual-front-mini');
        if (frontMini) frontMini.remove();
        var session1Card = document.getElementById('check-session1-card');
        if (session1Card) session1Card.classList.add('hidden');
        var session2CardE1 = document.getElementById('check-session2-card');
        if (session2CardE1) session2CardE1.classList.add('hidden');
        var resultCard = document.getElementById('check-dual-result-card');
        if (resultCard) resultCard.classList.add('hidden');
        var resultActions = document.getElementById('check-dual-result-actions');
        if (resultActions) resultActions.classList.add('hidden');
        var processHelp = document.getElementById('check-dual-process-help');
        if (processHelp) processHelp.classList.add('hidden');
        // Update capture button text
        var captureText = document.getElementById('check-capture-text');
        if (captureText) captureText.textContent = 'Capture Depan';
        // Redraw guide
        checkDrawGuide();
        // Update top-right badge + stepper tahapan sesi
        checkUpdateGuideBadge();
        checkStageStepper();
    }

    function checkDualEnterStage2() {
        checkScanStage = 2;
        // Mini front preview dihapus (Stage 2 minimalist): live cam bersih, badge ukuran tetap satu-satunya di kanan atas.
        // Data checkFrontCapture tetap dipertahankan (Preview Sesi 1 + Result tidak terpengaruh).
        var session1Card2 = document.getElementById('check-session1-card');
        if (session1Card2) session1Card2.classList.add('hidden');
        var session2CardE2 = document.getElementById('check-session2-card');
        if (session2CardE2) session2CardE2.classList.add('hidden');
        var frontMini = document.getElementById('check-dual-front-mini');
        if (frontMini) frontMini.remove();
        // Update capture button text
        var captureText = document.getElementById('check-capture-text');
        if (captureText) captureText.textContent = 'Capture Belakang';
        // Redraw guide
        checkDrawGuide();
        // Update top-right badge + stepper tahapan sesi
        checkUpdateGuideBadge();
        checkStageStepper();
    }

    // Preview Sesi 1 — FRONT tersimpan sementara (mirror Reference dual-front-mini, UI saja).
    // Kamera dimatikan untuk hemat performa. Tepat 2 tombol: Retake Front dan Lanjut ke Capture 2.
    function checkDualShowSession1() {
        checkScanStage = 'preview1';
        // Matikan track kamera saja (tanpa reset flow / tanpa memunculkan tombol Start)
        try {
            if (checkStream) {
                checkStream.getTracks().forEach(function (t) { t.stop(); });
                checkStream = null;
            }
            if (checkEl.video) checkEl.video.srcObject = null;
            if (checkEl.cameraCard) checkEl.cameraCard.classList.add('hidden');
        } catch (e) {}
        // Bersihkan banner live scan agar layar preview bersih
        var frontMini = document.getElementById('check-dual-front-mini');
        if (frontMini) frontMini.remove();
        var resultCard = document.getElementById('check-dual-result-card');
        if (resultCard) resultCard.classList.add('hidden');
        var resultActions = document.getElementById('check-dual-result-actions');
        if (resultActions) resultActions.classList.add('hidden');
        var processHelp = document.getElementById('check-dual-process-help');
        if (processHelp) processHelp.classList.add('hidden');
        var session2CardH1 = document.getElementById('check-session2-card');
        if (session2CardH1) session2CardH1.classList.add('hidden');
        // Tampilkan kartu Sesi 1
        var session1Card = document.getElementById('check-session1-card');
        if (session1Card) {
            session1Card.classList.remove('hidden');
            try { session1Card.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
        var session1Img = document.getElementById('check-session1-img');
        if (session1Img && checkFrontCapture) {
            session1Img.src = checkFrontCapture.url;
        }
        var session1Meta = document.getElementById('check-session1-meta');
        if (session1Meta && checkFrontCapture) {
            var tpl = currentRef ? ((currentRef.template_id || '-') + ' — ' + (currentRef.template_name || '-')) : '-';
            session1Meta.textContent = 'Template: ' + tpl + ' · File: ' + (checkFrontCapture.filename_original || '-');
        }
        // Gate alur: Lanjut aktif hanya bila split FRONT sudah ada (selesai Simpan/Auto Crop).
        // Capture baru belum ada split → kunci + beri petunjuk (mirip Reference: Lanjut dikunci sampai Simpan sukses).
        var hasFrontSplit = !!(checkGetSplit('front', 1) && checkGetSplit('front', 2));
        // Nav-gate: Lanjut dibuka hanya bila split ada + 2 label terminal (anti-interferensi OCR).
        checkSplitNavRefresh('front');
        var session1Feedback = document.getElementById('check-session1-feedback');
        if (session1Feedback) session1Feedback.textContent = hasFrontSplit ? '' : 'Atur rotasi → Simpan (Auto Crop) untuk mengaktifkan Lanjut.';
        checkUpdateGuideBadge();
        checkStageStepper();
        // Sinkron label derajat + kunci rotate FRONT (base sudah ada dari capture)
        try { checkUpdateRotateUI(); } catch (e) {}
        try { checkSetRotateUI('front', !!checkBaseFront); } catch (e) {}
        // Capture baru: tampilkan section single (master + rotate + Simpan), split masih hidden.
        // Bila split sudah ada (jalur kembali), single tetap hidden agar hanya 3 capture terlihat.
        try {
            var sGrid = document.getElementById('check-session1-single');
            if (sGrid) {
                var hasSplit = checkGetSplit('front', 1) && checkGetSplit('front', 2);
                sGrid.classList.toggle('hidden', !!hasSplit);
            }
        } catch (e) {}
        // Sinkron tombol Crop FRONT (enabled bila ada capture); render ulang split bila sudah ada (mis. kembali dari Back)
        try { checkSetCropUI('front', !!checkFrontCapture); } catch (e) {}
        try { if (checkGetSplit('front', 1) && checkGetSplit('front', 2)) checkRenderSplitSide('front'); } catch (e) {}
    }

    // Preview Sesi 2 — BACK tersimpan sementara (cermin Sesi 1 / Reference dual-back-mini).
    // Kamera dimatikan untuk hemat performa. Retake Back / Lihat Hasil 2 Sisi di kartu ini.
    function checkDualShowSession2() {
        checkScanStage = 'preview2';
        // Matikan track kamera saja (tanpa reset flow / tanpa memunculkan tombol Start)
        try {
            if (checkStream) {
                checkStream.getTracks().forEach(function (t) { t.stop(); });
                checkStream = null;
            }
            if (checkEl.video) checkEl.video.srcObject = null;
            if (checkEl.cameraCard) checkEl.cameraCard.classList.add('hidden');
        } catch (e) {}
        // Bersihkan banner live scan agar layar preview bersih
        var frontMini = document.getElementById('check-dual-front-mini');
        if (frontMini) frontMini.remove();
        var session1Card = document.getElementById('check-session1-card');
        if (session1Card) session1Card.classList.add('hidden');
        var resultCard = document.getElementById('check-dual-result-card');
        if (resultCard) resultCard.classList.add('hidden');
        var resultActions = document.getElementById('check-dual-result-actions');
        if (resultActions) resultActions.classList.add('hidden');
        var processHelp = document.getElementById('check-dual-process-help');
        if (processHelp) processHelp.classList.add('hidden');
        // Tampilkan kartu Sesi 2
        var session2Card = document.getElementById('check-session2-card');
        if (session2Card) {
            session2Card.classList.remove('hidden');
            try { session2Card.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
        var session2Img = document.getElementById('check-session2-img');
        if (session2Img && checkBackCapture) {
            session2Img.src = checkBackCapture.url;
        }
        var session2Meta = document.getElementById('check-session2-meta');
        if (session2Meta && checkBackCapture) {
            var tpl2 = currentRef ? ((currentRef.template_id || '-') + ' — ' + (currentRef.template_name || '-')) : '-';
            session2Meta.textContent = 'Template: ' + tpl2 + ' · File: ' + (checkBackCapture.filename_original || '-');
        }
        // Gate alur: Lihat Hasil aktif hanya bila split BACK sudah ada (cermin gate Lanjut FRONT).
        var hasBackSplit = !!(checkGetSplit('back', 1) && checkGetSplit('back', 2));
        // Nav-gate: Lihat Hasil dibuka hanya bila split ada + 2 label terminal (cermin FRONT).
        checkSplitNavRefresh('back');
        var session2Feedback = document.getElementById('check-session2-feedback');
        if (session2Feedback) session2Feedback.textContent = hasBackSplit ? '' : 'Atur rotasi → Simpan (Auto Crop) untuk mengaktifkan Lihat Hasil.';
        checkUpdateGuideBadge();
        checkStageStepper();
        // Sinkron label derajat + kunci rotate BACK (base sudah ada dari capture)
        try { checkUpdateRotateUI(); } catch (e) {}
        try { checkSetRotateUI('back', !!checkBaseBack); } catch (e) {}
        // Capture baru: tampilkan section single BACK, split masih hidden.
        // Bila split sudah ada, single tetap hidden agar hanya 3 capture terlihat.
        try {
            var sGrid2 = document.getElementById('check-session2-single');
            if (sGrid2) {
                var hasSplit2 = checkGetSplit('back', 1) && checkGetSplit('back', 2);
                sGrid2.classList.toggle('hidden', !!hasSplit2);
            }
        } catch (e) {}
        // Sinkron tombol Simpan BACK; render ulang split bila sudah ada
        try { checkSetCropUI('back', !!checkBackCapture); } catch (e) {}
        try { if (checkGetSplit('back', 1) && checkGetSplit('back', 2)) checkRenderSplitSide('back'); } catch (e) {}
    }

    // Lihat Hasil 2 Sisi — dari Sesi 2 ke kartu hasil gabungan (cermin Lanjut FRONT, gate split BACK).
    function checkDualGoToResult() {
        if (!checkBackCapture) {
            checkFeedback('Capture Sisi Belakang dulu sebelum lihat hasil.', true);
            return;
        }
        if (!checkGetSplit('back', 1) || !checkGetSplit('back', 2)) {
            checkFeedback('Atur rotasi lalu tekan Simpan (Auto Crop 2 label) dulu sebelum lihat hasil.', true);
            var s2Fb = document.getElementById('check-session2-feedback');
            if (s2Fb) s2Fb.textContent = 'Atur rotasi → Simpan (Auto Crop) untuk mengaktifkan Lihat Hasil.';
            return;
        }
        var session2Card = document.getElementById('check-session2-card');
        if (session2Card) session2Card.classList.add('hidden');
        checkDualShowResult();
            // Compare similarity (Phase 1) — Front-vs-Front, Back-vs-Back
            if (typeof checkCallCompareSimilarity === "function") {
                checkCallCompareSimilarity().then(function (cmp) {
                    if (!cmp || !Array.isArray(cmp.pairs)) return;
                    var map = {};
                    cmp.pairs.forEach(function (p) {
                        if (!p || !p.side || !p.idx) return;
                        map[p.side + "_" + p.idx] = p;
                    });
                    function setCmp(id, cls, text) {
                        var el = document.getElementById(id);
                        if (!el) return;
                        el.classList.remove("hidden");
                        el.className = "px-2.5 py-0.5 rounded-full text-xs font-semibold border " + cls;
                        el.textContent = text || "";
                    }
                    var f1 = map.front_1, f2 = map.front_2, b1 = map.back_1, b2 = map.back_2;
                    if (f1) {
                        if (f1.verdict === "PASS") setCmp("check-result-front-split-cmp-1","border-emerald-200 bg-emerald-50 text-emerald-700","PASS ("+Math.round((f1.similarity||0)*100)+"%)");
                        else if (f1.verdict === "FAIL") setCmp("check-result-front-split-cmp-1","border-[#E04438]/30 bg-[#E04438]/10 text-[#E04438]","FAIL ("+Math.round((f1.similarity||0)*100)+"%)");
                        else setCmp("check-result-front-split-cmp-1","border-[#6C757D]/30 bg-[#E9ECEF] text-[#6C757D]", (f1.verdict||"SKIP"));
                    }
                    if (f2) {
                        if (f2.verdict === "PASS") setCmp("check-result-front-split-cmp-2","border-emerald-200 bg-emerald-50 text-emerald-700","PASS ("+Math.round((f2.similarity||0)*100)+"%)");
                        else if (f2.verdict === "FAIL") setCmp("check-result-front-split-cmp-2","border-[#E04438]/30 bg-[#E04438]/10 text-[#E04438]","FAIL ("+Math.round((f2.similarity||0)*100)+"%)");
                        else setCmp("check-result-front-split-cmp-2","border-[#6C757D]/30 bg-[#E9ECEF] text-[#6C757D]", (f2.verdict||"SKIP"));
                    }
                    if (b1) {
                        if (b1.verdict === "PASS") setCmp("check-result-back-split-cmp-1","border-emerald-200 bg-emerald-50 text-emerald-700","PASS ("+Math.round((b1.similarity||0)*100)+"%)");
                        else if (b1.verdict === "FAIL") setCmp("check-result-back-split-cmp-1","border-[#E04438]/30 bg-[#E04438]/10 text-[#E04438]","FAIL ("+Math.round((b1.similarity||0)*100)+"%)");
                        else setCmp("check-result-back-split-cmp-1","border-[#6C757D]/30 bg-[#E9ECEF] text-[#6C757D]", (b1.verdict||"SKIP"));
                    }
                    if (b2) {
                        if (b2.verdict === "PASS") setCmp("check-result-back-split-cmp-2","border-emerald-200 bg-emerald-50 text-emerald-700","PASS ("+Math.round((b2.similarity||0)*100)+"%)");
                        else if (b2.verdict === "FAIL") setCmp("check-result-back-split-cmp-2","border-[#E04438]/30 bg-[#E04438]/10 text-[#E04438]","FAIL ("+Math.round((b2.similarity||0)*100)+"%)");
                        else setCmp("check-result-back-split-cmp-2","border-[#6C757D]/30 bg-[#E9ECEF] text-[#6C757D]", (b2.verdict||"SKIP"));
                    }
                }).catch(function(){});
            }
        checkFeedback('Dua sisi tertangkap. Siap untuk Check Label.', false);
    }

    // Lanjut ke Sesi 2 — buka kembali live preview BACK (FRONT tetap tersimpan)
    function checkDualGoToSession2() {
        if (!checkFrontCapture) {
            checkFeedback('Capture Sisi Depan dulu sebelum lanjut.', true);
            return;
        }
        // Gate alur: wajib sudah Simpan (Auto Crop 2 label) — mirip Reference (Lanjut aktif setelah Simpan sukses)
        if (!checkGetSplit('front', 1) || !checkGetSplit('front', 2)) {
            checkFeedback('Atur rotasi lalu tekan Simpan (Auto Crop 2 label) dulu sebelum lanjut.', true);
            var sFb = document.getElementById('check-session1-feedback');
            if (sFb) sFb.textContent = 'Atur rotasi → Simpan (Auto Crop) untuk mengaktifkan Lanjut.';
            return;
        }
        var session1Card = document.getElementById('check-session1-card');
        if (session1Card) session1Card.classList.add('hidden');
        if (checkEl.cameraCard) checkEl.cameraCard.classList.remove('hidden');
        checkDualEnterStage2();
        try { checkStartCamera(); } catch (e) {}
    }

    // Gambar blob capture ke canvas hasil (closure aman per-canvas).
    // Alasan: menggambar langsung dengan `var img/ctx` ganda dalam satu fungsi
    // menyebabkan handler onload FRONT memakai variabel milik BACK (var itu
    // function-scoped), sehingga kanvas FRONT kosong. Helper ini mengisolasi
    // tiap gambar dalam scope pemanggilan sendiri.
    function checkDrawCaptureToCanvas(canvas, cap, container) {
        if (!canvas || !cap || !cap.blob || !cap.url) return;
        var ctx2d = canvas.getContext('2d');
        if (!ctx2d) return;
        var thumb = new Image();
        thumb.onload = function () {
            var cw = container ? container.clientWidth : 0;
            var targetW = cw ? Math.max(160, Math.floor(cw / 2) - 8) : 320;
            canvas.width = targetW;
            canvas.height = Math.floor(targetW / 2); // aspek 2:1
            ctx2d.drawImage(thumb, 0, 0, canvas.width, canvas.height);
        };
        thumb.src = cap.url;
    }

    function checkDualShowResult() {
        checkScanStage = 'result';
        // Stop camera (like Reference dualEnterDualResult)
        try {
            if (checkStream) {
                checkStream.getTracks().forEach(function (t) { t.stop(); });
                checkStream = null;
            }
            if (checkEl.video) checkEl.video.srcObject = null;
            if (checkEl.cameraCard) checkEl.cameraCard.classList.add('hidden');
        } catch (e) {}
        // Hide side badge and mini preview
        var frontMini = document.getElementById('check-dual-front-mini');
        if (frontMini) frontMini.remove();
        var session1Card = document.getElementById('check-session1-card');
        if (session1Card) session1Card.classList.add('hidden');
        var session2CardHR = document.getElementById('check-session2-card');
        if (session2CardHR) session2CardHR.classList.add('hidden');
        // Show dual result card
        var resultCard = document.getElementById('check-dual-result-card');
        if (resultCard) resultCard.classList.remove('hidden');
        // Draw thumbnails on canvases (via helper ber-closure aman — lihat checkDrawCaptureToCanvas)
        var frontCanvas = document.getElementById('check-result-front-canvas');
        var backCanvas = document.getElementById('check-result-back-canvas');
        if (frontCanvas) try { frontCanvas.remove(); } catch (e) {}
        if (backCanvas) try { backCanvas.remove(); } catch (e) {}
        // Tidak menggambar master canvas besar di Capture Result — tampilkan 4 split saja (Label 1+2)
        // Show action buttons
        var resultActions = document.getElementById('check-dual-result-actions');
        if (resultActions) resultActions.classList.remove('hidden');
        var retakeFront = document.getElementById('check-retake-front');
        var retakeBack = document.getElementById('check-retake-back');
        if (retakeFront) retakeFront.classList.remove('hidden');
        if (retakeBack) retakeBack.classList.remove('hidden');
        // Meta + bantuan Process (UI saja)
        var resultMeta = document.getElementById('check-dual-result-meta');
        if (resultMeta) {
            var tpl2 = currentRef ? ((currentRef.template_id || '-') + ' — ' + (currentRef.template_name || '-')) : '-';
            var ff = checkFrontCapture ? (checkFrontCapture.filename_original || '-') : '-';
            var bf = checkBackCapture ? (checkBackCapture.filename_original || '-') : '-';
            resultMeta.textContent = 'Template: ' + tpl2 + ' · File Front: ' + ff + ' · File Back: ' + bf;
        }
        var processHelp = document.getElementById('check-dual-process-help');
        if (processHelp) processHelp.classList.remove('hidden');
        var processFeedback = document.getElementById('check-dual-process-feedback');
        if (processFeedback) processFeedback.textContent = '';
        // Redraw guide (will show result state)
        checkDrawGuide();
        // Update top-right badge + stepper tahapan sesi
        checkUpdateGuideBadge();
        checkStageStepper();
        // Sinkron label derajat kedua sisi + enable rotate (base sudah ada dari capture)
        try { checkUpdateRotateUI(); } catch (e) {}
        try { checkSetRotateUI('front', !!checkBaseFront); } catch (e) {}
        try { checkSetRotateUI('back', !!checkBaseBack); } catch (e) {}
        // Sinkron tombol Crop + render ulang split yang sudah ada (mis. rotate ulang membatalkan, crop ulang menampilkan)
        try { checkSetCropUI('front', !!checkFrontCapture); } catch (e) {}
        try { checkSetCropUI('back', !!checkBackCapture); } catch (e) {}
        try { if (checkGetSplit('front', 1) && checkGetSplit('front', 2)) checkRenderSplitSide('front'); } catch (e) {}
        try { if (checkGetSplit('back', 1) && checkGetSplit('back', 2)) checkRenderSplitSide('back'); } catch (e) {}
        // AUTO-DIRECT #3 effortless (2026-10-09): Capture Result tampil → auto Process.
        try {
            checkAutoFired.toProcess = false; checkAutoClear('toProcess');
            if (checkAutoEnabled()) {
                checkAutoTimers.toProcess = setTimeout(function () {
                    try {
                        var card = document.getElementById('check-dual-result-card');
                        var acts = document.getElementById('check-dual-result-actions');
                        if (!card || card.classList.contains('hidden')) return;
                        if (!acts || acts.classList.contains('hidden')) return;
                        var proc = document.getElementById('check-proceed-check');
                        if (!proc) return;
                        var ok4 = !!(checkGetSplit('front', 1) && checkGetSplit('front', 2)
                            && checkGetSplit('back', 1) && checkGetSplit('back', 2));
                        if (!ok4 || checkUploadInProgress) return;
                        if (!checkAutoEnabled() || checkAutoFired.toProcess) return;
                        checkAutoFired.toProcess = true;
                        var pfb = document.getElementById('check-dual-process-feedback');
                        if (pfb) pfb.textContent = 'Hasil 2 sisi siap — memproses otomatis…';
                        try { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
                        proc.click();
                    } catch (e) { console.warn('[CHECK AUTO] toProcess failed', e); }
                }, 1000);
            }
        } catch (e) {}
    }

    function checkDualOnCaptureClick() {
        var video = checkEl.video;
        if (checkUploadInProgress) {
            checkFeedback('Masih mengirim batch sebelumnya...', true);
            return;
        }
        if (!checkStream || !video || video.readyState < 2 || !video.videoWidth) {
            checkFeedback('Camera is not ready yet.', true);
            return;
        }
        var result = checkGetGuideBoxes();
        if (!result) {
            checkFeedback('Belum ada Reference acuan.', true);
            return;
        }
        if (!currentRef || !currentRef.id) {
            checkFeedback('Reference acuan belum ter-load.', true);
            return;
        }

        // Capture the current guide box area
        var scaleX = video.videoWidth / result.content.w;
        var scaleY = video.videoHeight / result.content.h;
        var box = result.boxes[0]; // Only 1 box (GUIDE_COLS = 1)
        var sx = Math.max(0, Math.min(Math.round((box.x - result.content.x) * scaleX), video.videoWidth - 1));
        var sy = Math.max(0, Math.min(Math.round((box.y - result.content.y) * scaleY), video.videoHeight - 1));
        var sw = Math.max(1, Math.min(Math.round(box.w * scaleX), video.videoWidth - sx));
        var sh = Math.max(1, Math.min(Math.round(box.h * scaleY), video.videoHeight - sy));

        var c = document.createElement('canvas');
        c.width = sw; c.height = sh;
        c.getContext('2d').drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
        // Simpan canvas horizontal sebagai base rotate sisi aktif (reset derajat ke 0)
        if (checkScanStage === 1) {
            checkBaseFront = c;
            checkDegFront = 0; checkBusyFront = false;
            try { checkUpdateRotateUI(); } catch (e) {}
        } else if (checkScanStage === 2) {
            checkBaseBack = c;
            checkDegBack = 0; checkBusyBack = false;
            try { checkUpdateRotateUI(); } catch (e) {}
        }
        c.toBlob(function (blob) {
            if (!blob) {
                checkFeedback('Gagal menangkap gambar. Coba lagi.', true);
                return;
            }
            var now = new Date();
            var filenameOriginal = 'check-' + (currentRef.template_id || 'x').toLowerCase() + '-' + checkBuildStamp(now) + '-slot1.png';
            var url = URL.createObjectURL(blob);
            var capData = { blob: blob, url: url, slot: 1, filename_original: filenameOriginal, w: sw, h: sh, size: blob.size };

            if (checkScanStage === 1) {
                // Sesi 1: Capture Front → tampil preview Sesi 1 (kamera mati, UI saja)
                if (checkFrontCapture && checkFrontCapture.url) {
                    try { URL.revokeObjectURL(checkFrontCapture.url); } catch (e) {}
                }
                checkFrontCapture = capData;
                // BACK lama tidak valid lagi bila ada (aturan pasangan)
                if (checkBackCapture && checkBackCapture.url) {
                    try { URL.revokeObjectURL(checkBackCapture.url); } catch (e) {}
                }
                checkBackCapture = null;
                checkBaseBack = null; checkDegBack = 0; checkBusyBack = false;
                // Capture baru = split basi: buang split kedua sisi
                try { checkClearSplitSide('front'); } catch (e) {}
                try { checkClearSplitSide('back'); } catch (e) {}
                try { checkUpdateRotateUI(); } catch (e) {}
                checkDualShowSession1();
                checkFeedback('Sisi Depan tersimpan sementara. Lanjut ke Capture 2 (Back).', false);
            } else if (checkScanStage === 2) {
                // Stage 2: Capture Back → tampil preview Sesi 2 (cermin Sesi 1, bukan langsung hasil)
                if (checkBackCapture && checkBackCapture.url) {
                    try { URL.revokeObjectURL(checkBackCapture.url); } catch (e) {}
                }
                checkBackCapture = capData;
                // Capture Back baru = split BACK basi
                try { checkClearSplitSide('back'); } catch (e) {}
                checkDualShowSession2();
                checkFeedback('Sisi Belakang tersimpan sementara. Atur rotasi → Simpan.', false);
            }
        }, 'image/png');
    }

    function checkDualRetake(side) {
        // AUTO-DIRECT effortless (2026-10-09): Retake membatalkan semua auto pending + buka flag lagi.
        try { checkAutoClearAll(); } catch (e) {}
        try { checkAutoResetFlagsFor(side); } catch (e) {}
        if (side === 'front') { try { checkAutoResetFlagsFor('back'); } catch (e) {} }
        if (side === 'front') {
            // Retake Front: buang FRONT dan BACK (aturan pasangan), kembali ke Sesi 1
            if (checkFrontCapture && checkFrontCapture.url) {
                try { URL.revokeObjectURL(checkFrontCapture.url); } catch (e) {}
            }
            checkFrontCapture = null;
            if (checkBackCapture && checkBackCapture.url) {
                try { URL.revokeObjectURL(checkBackCapture.url); } catch (e) {}
            }
            checkBackCapture = null;
            // Buang base + derajat rotate kedua sisi
            checkBaseFront = null; checkDegFront = 0; checkBusyFront = false;
            checkBaseBack = null; checkDegBack = 0; checkBusyBack = false;
            // Buang split kedua sisi (aturan pasangan)
            try { checkClearSplitSide('front'); } catch (e) {}
            try { checkClearSplitSide('back'); } catch (e) {}
            // Reset OCR split kedua sisi + sesi baru berikutnya (pasangan dibuang semua).
            try { checkSplitOcrResetSide('front'); } catch (e) {}
            try { checkSplitOcrResetSide('back'); } catch (e) {}
            try { checkOcrLoaderHide('front'); } catch (e) {}
            try { checkOcrLoaderHide('back'); } catch (e) {}
            checkSplitSessionCode = null;
            try { checkUpdateRotateUI(); } catch (e) {}
            var session1Card = document.getElementById('check-session1-card');
            if (session1Card) session1Card.classList.add('hidden');
            var session2CardRt = document.getElementById('check-session2-card');
            if (session2CardRt) session2CardRt.classList.add('hidden');
            checkDualEnterStage1();
            // Restart camera for retake
            checkStartCamera();
            checkFeedback('Retake Sisi Depan. Posisikan ulang label.', false);
        } else if (side === 'back') {
            // Retake Back: clear back, go back to Stage 2 (keep Front)
            if (checkBackCapture && checkBackCapture.url) {
                try { URL.revokeObjectURL(checkBackCapture.url); } catch (e) {}
            }
            checkBackCapture = null;
            // Buang base + derajat BACK saja (FRONT utuh)
            checkBaseBack = null; checkDegBack = 0; checkBusyBack = false;
            // Buang split BACK saja (FRONT utuh — sesi dipertahankan)
            try { checkClearSplitSide('back'); } catch (e) {}
            try { checkSplitOcrResetSide('back'); } catch (e) {}
            try { checkOcrLoaderHide('back'); } catch (e) {}
            try { checkUpdateRotateUI(); } catch (e) {}
            checkDualEnterStage2();
            // Restart camera for retake
            checkStartCamera();
            checkFeedback('Retake Sisi Belakang. Posisikan ulang label.', false);
        }
    }

    // Process 2 sisi — kirim 4 split (Front-1/2 + Back-1/2) sebagai 1 batch check
    // ke POST /api/check/captures, lalu otomatis Process → verdict PASS/FAIL per slot.
    // Nama file split SUDAH mengandung penanda sisi (-front-1/-back-2) sehingga
    // FastAPI detect_side() bisa compare Front-vs-Front & Back-vs-Back.
    // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) — ganti stub inert.
    function checkDualProcessInert() {
        var f1 = checkGetSplit('front', 1), f2 = checkGetSplit('front', 2);
        var b1 = checkGetSplit('back', 1), b2 = checkGetSplit('back', 2);
        var splits = [
            { cap: f1, slot: 1 }, { cap: f2, slot: 2 },
            { cap: b1, slot: 3 }, { cap: b2, slot: 4 }
        ];
        var ready = splits.filter(function (s) { return s.cap && s.cap.blob; });
        var processFeedback = document.getElementById('check-dual-process-feedback');
        if (ready.length < 4) {
            if (processFeedback) {
                if (ready.length > 0) processFeedback.textContent = 'Process — ' + ready.length + '/4 split siap (lengkapi crop Front-1/2 + Back-1/2 dulu).';
                else processFeedback.textContent = 'Belum ada split — crop Front-1/2 dan Back-1/2 dulu (tombol Simpan).';
            }
            if (ready.length === 0) checkFeedback('Belum ada split — crop Front-1/2 dan Back-1/2 dulu.', true);
            console.log('[CHECK] Process 2 sisi dibatalkan: hanya ' + ready.length + '/4 split siap');
            return;
        }
        var token = checkGetToken();
        if (!token) { checkFeedback('Sesi berakhir. Silakan login kembali.', true); return; }
        if (!currentRef) { checkFeedback('Belum ada Reference acuan. Muat ulang halaman atau buat Reference dulu.', true); return; }
        if (checkUploadInProgress) { checkFeedback('Masih mengirim batch...', true); return; }
        var batchCode = checkBuildBatchCode(new Date());
        var blobs = ready.map(function (s) {
            return {
                blob: s.cap.blob,
                slot: s.slot,
                // PENTING: pertahankan nama file split (…-front-1.png) agar
                // FastAPI detect_side() tahu sisi tiap slot.
                filename_original: s.cap.filename,
                w: s.cap.w, h: s.cap.h, size: s.cap.size
            };
        });
        checkExpectedSlots = 4;
        checkDualAutoProcess = true;
        try { checkOcrLoggedSlots = {}; } catch (e) {}
        try { checkOcrVerdictSlots = {}; } catch (e) {}
        if (processFeedback) processFeedback.textContent = 'Mengirim 4 split (Front-1/2 + Back-1/2) ke FastAPI...';
        try { checkProcessOverlayShow(); } catch (e) {}
        console.log('[CHECK] Process 2 sisi → upload 4 split batch=' + batchCode, {
            slot1: blobs[0].filename_original,
            slot2: blobs[1].filename_original,
            slot3: blobs[2].filename_original,
            slot4: blobs[3].filename_original
        });
        checkUploadBatch(blobs, batchCode, token);
    }

    // (Compat lama — tidak lagi dipakai alur 2 sesi; dipertahankan agar tidak merusak referensi lain)
    function checkProceedToCheck() {
        // Hide dual UI, show existing preview flow with both captures
        var resultCard = document.getElementById('check-dual-result-card');
        if (resultCard) resultCard.classList.add('hidden');
        var resultActions = document.getElementById('check-dual-result-actions');
        if (resultActions) resultActions.classList.add('hidden');
        var frontMini = document.getElementById('check-dual-front-mini');
        if (frontMini) frontMini.remove();

        // Store captures in checkSlotsData for existing preview flow
        checkSlotsData = [];
        if (checkFrontCapture) {
            checkSlotsData[0] = {
                blob: checkFrontCapture.blob,
                url: checkFrontCapture.url,
                slot: 1,
                filename_original: checkFrontCapture.filename_original,
                w: checkFrontCapture.w,
                h: checkFrontCapture.h,
                size: checkFrontCapture.size
            };
        }
        if (checkBackCapture) {
            // For single slot, we only have slot 1. Back capture would be a separate flow.
            // Since GUIDE_COLS = 1, we'll just use the front capture for now.
            // The existing preview expects GUIDE_COLS items.
        }

        // Simulate a session for preview
        var mockSession = {
            id: 'dual-' + Date.now(),
            batch_code: 'CHK-' + checkBuildStamp(new Date()) + '-dual'
        };
        var mockExternal = { status: 'sent', paths: [checkFrontCapture?.url].filter(Boolean) };
        // checkEnterPreviewMode saves checkSlotsData internally before stopping camera
        checkEnterPreviewMode(mockSession, mockExternal);
    }

    function checkPopulateCameraSelect(currentDeviceId) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices || !checkEl || !checkEl.cameraSelect) return;

        navigator.mediaDevices.enumerateDevices().then(function (devices) {
            var videoDevices = devices.filter(function (d) { return d.kind === 'videoinput'; });
            checkEl.cameraSelect.innerHTML = '';

            if (videoDevices.length === 0) {
                var emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = 'Tidak ada kamera terdeteksi';
                checkEl.cameraSelect.appendChild(emptyOpt);
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
                checkEl.cameraSelect.appendChild(opt);
            });

            if (!found && videoDevices.length > 0) {
                checkEl.cameraSelect.selectedIndex = 0;
            }
        }).catch(function (err) {
            console.warn('Gagal membaca daftar kamera di Check:', err);
        });
    }

    function checkUpdateCameraResolutionBadge() {
        if (!checkEl || !checkEl.cameraResBadge) return;
        var w = (checkEl.video && checkEl.video.videoWidth) ? checkEl.video.videoWidth : 0;
        var h = (checkEl.video && checkEl.video.videoHeight) ? checkEl.video.videoHeight : 0;
        if ((!w || !h) && checkStream) {
            var track = checkStream.getVideoTracks()[0];
            if (track && track.getSettings) {
                var s = track.getSettings();
                w = s.width || 0;
                h = s.height || 0;
            }
        }
        if (w && h) {
            checkEl.cameraResBadge.textContent = 'Resolusi: ' + w + ' × ' + h + ' px';
            checkEl.cameraResBadge.classList.remove('hidden');
        } else {
            checkEl.cameraResBadge.classList.add('hidden');
        }
    }

    function checkStartCamera(preferredDeviceId, isRetry) {
        var video = checkEl.video;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            checkFeedback('Camera is not supported on this browser or the connection is not secure (HTTPS/localhost required).', true);
            return;
        }
        if (!currentRef) {
            checkFeedback('Belum ada Reference acuan. Muat ulang halaman atau buat Reference dulu.', true);
            return;
        }

        var savedDeviceId = null;
        try { savedDeviceId = localStorage.getItem(CAMERA_STORAGE_KEY); } catch (e) {}
        var targetDeviceId = preferredDeviceId || savedDeviceId;

        if (checkEl.startBtn) checkEl.startBtn.disabled = true;
        checkFeedback('Requesting camera...', false);

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
            checkStream = stream;
            video.srcObject = stream;
            checkEl.cameraCard.classList.remove('hidden');
            checkEl.startBtn.classList.add('hidden');
            checkEl.startBtn.disabled = false;

            var track = stream.getVideoTracks()[0];
            var settings = (track && track.getSettings) ? track.getSettings() : {};
            var activeDeviceId = settings.deviceId || targetDeviceId;
            if (activeDeviceId) {
                try { localStorage.setItem(CAMERA_STORAGE_KEY, activeDeviceId); } catch (e) {}
            }

            if (checkEl.cameraLabel) checkEl.cameraLabel.textContent = track && track.label ? track.label : 'Camera active';
            checkFeedback('', false);

            checkPopulateCameraSelect(activeDeviceId);
            checkUpdateCameraResolutionBadge();

            checkDrawGuide();
            // Update top-right badge with FRONT/BACK indicator
            checkUpdateGuideBadge();
        }).catch(function (error) {
            if (targetDeviceId && !isRetry && (error.name === 'OverconstrainedError' || error.name === 'NotFoundError' || error.name === 'AbortError')) {
                try { localStorage.removeItem(CAMERA_STORAGE_KEY); } catch (e) {}
                console.warn('Kamera pilihan tidak tersedia di Check, fallback ke kamera default...');
                return checkStartCamera(null, true);
            }

            checkEl.startBtn.disabled = false;
            var message = 'Unable to start the camera. Please try again.';
            if (error.name === 'NotAllowedError' || error.name === 'SecurityError') {
                message = 'Camera access was denied. Allow camera permission and try again.';
            } else if (error.name === 'NotFoundError' || error.name === 'OverconstrainedError') {
                message = 'No camera device was found on this system.';
            } else if (error.name === 'NotReadableError') {
                message = 'The camera is already in use by another application.';
            }
            checkFeedback(message, true);
        });
    }

    function checkClearSlotThumbs() {
        checkSlotsData.forEach(function (d) {
            if (d && d.url) { try { URL.revokeObjectURL(d.url); } catch (e) {} }
            if (d && d.previewUrl) { try { URL.revokeObjectURL(d.previewUrl); } catch (e) {} }
        });
        checkSlotsData = [];
        for (var i = 1; i <= GUIDE_COLS; i++) {
            var img = document.getElementById('check-slot-thumb-' + i);
            if (img) { img.classList.add('hidden'); img.removeAttribute('src'); }
        }
        // CP-066: preview result selalu 4 slot (FRONT 1·2 + BACK 1·2) — bersihkan semua agar retake tidak sisa gambar.
        for (var pi = 1; pi <= 4; pi++) {
            var pImg = document.getElementById('check-preview-thumb-' + pi);
            if (pImg) { pImg.classList.add('hidden'); pImg.removeAttribute('src'); }
        }
        // Compat: jika DOM masih ada slot 5 lama (between 4,5) pastikan hidden juga
        var extra5 = document.getElementById('check-slot-thumb-5');
        if (extra5) { extra5.classList.add('hidden'); extra5.removeAttribute('src'); }
        var pExtra5 = document.getElementById('check-preview-thumb-5');
        if (pExtra5) { pExtra5.classList.add('hidden'); pExtra5.removeAttribute('src'); }
        if (checkEl.strip) checkEl.strip.classList.add('hidden');
        if (checkEl.sessionBadge) checkEl.sessionBadge.classList.add('hidden');
        if (checkEl.externalStatus) checkEl.externalStatus.classList.add('hidden');
        checkResetSlotStatus();
        if (checkEl.ocrStatus) checkEl.ocrStatus.classList.add('hidden');
        if (checkOcrPollTimer) { try { clearInterval(checkOcrPollTimer); } catch (e) {} checkOcrPollTimer = null; }
        checkOcrLoggedSlots = {};
        // Keep preview card hidden on Stop (preview is separate, do not auto-hide here — leave as is)
        // Note: Preview card will be explicitly hidden on Retake (when implemented)
    }

    function checkStopCamera() {
        // Abort batch upload if in progress
        if (checkBatchAbort) {
            try { checkBatchAbort.abort(); } catch (e) {}
            checkBatchAbort = null;
        }
        if (checkUploadInProgress) {
            checkSetCaptureBusy(false);
            if (checkEl.uploadProgress) { checkEl.uploadProgress.classList.add('hidden'); checkEl.uploadProgress.textContent = ''; }
        }
        if (checkStream) {
            try { checkStream.getTracks().forEach(function (t) { t.stop(); }); } catch(e){}
            checkStream = null;
        }
        if (checkEl.video) checkEl.video.srcObject = null;
        if (checkEl.cameraCard) checkEl.cameraCard.classList.add('hidden');
        if (checkEl.startBtn) { checkEl.startBtn.classList.remove('hidden'); checkEl.startBtn.disabled = false; }
        if (checkEl.cameraResBadge) checkEl.cameraResBadge.classList.add('hidden');
        checkClearSlotThumbs();
        checkFeedback('', false);
        // Hide label dimension badge
        var labelDimBadge = document.getElementById('check-label-dim-badge');
        if (labelDimBadge) labelDimBadge.classList.add('hidden');
    }

    // ===== Batch upload 1 image to DB + forward FastAPI (CP-029 + CP-030, updated 1) =====
    function checkCaptureLabels() {
        var video = checkEl.video;
        if (checkUploadInProgress) {
            checkFeedback('Masih mengirim batch sebelumnya...', true);
            return;
        }
        if (!checkStream || !video || video.readyState < 2 || !video.videoWidth) {
            checkFeedback('Camera is not ready yet.', true);
            return;
        }
        var result = checkGetGuideBoxes();
        if (!result) {
            checkFeedback('Belum ada Reference acuan.', true);
            return;
        }
        if (!currentRef || !currentRef.id) {
            checkFeedback('Reference acuan belum ter-load.', true);
            return;
        }
        var token = checkGetToken();
        if (!token) {
            checkFeedback('Sesi berakhir. Silakan login kembali.', true);
            return;
        }

        var scaleX = video.videoWidth / result.content.w;
        var scaleY = video.videoHeight / result.content.h;
        var now = new Date();
        var batchCode = checkBuildBatchCode(now);

        // Show preparing state + loading overlay
        checkSetCaptureBusy(true);
        checkShowCaptureLoading(true, 'Menyiapkan ' + GUIDE_COLS + ' label...');
        checkFeedback('', false);
        if (checkEl.uploadProgress) {
            checkEl.uploadProgress.textContent = 'Menyiapkan ' + GUIDE_COLS + ' label...';
            checkEl.uploadProgress.classList.remove('hidden');
        }
        if (checkEl.sessionBadge) checkEl.sessionBadge.classList.add('hidden');
        if (checkEl.externalStatus) checkEl.externalStatus.classList.add('hidden');
        var previewCard = document.getElementById('check-preview-card');
        if (previewCard) previewCard.classList.add('hidden');

        // Revoke old thumbs before new capture
        checkSlotsData.forEach(function (d) {
            if (d && d.url) { try { URL.revokeObjectURL(d.url); } catch (e) {} }
        });
        checkSlotsData = [];

        var blobs = []; // index 0..(GUIDE_COLS-1) → {blob, slot, filename_original, w, h, size}
        var pending = GUIDE_COLS;

        result.boxes.forEach(function (b) {
            var sx = Math.max(0, Math.min(Math.round((b.x - result.content.x) * scaleX), video.videoWidth - 1));
            var sy = Math.max(0, Math.min(Math.round((b.y - result.content.y) * scaleY), video.videoHeight - 1));
            var sw = Math.max(1, Math.min(Math.round(b.w * scaleX), video.videoWidth - sx));
            var sh = Math.max(1, Math.min(Math.round(b.h * scaleY), video.videoHeight - sy));

            var c = document.createElement('canvas');
            c.width = sw; c.height = sh;
            c.getContext('2d').drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
            c.toBlob(function (blob) {
                var idx = b.slot;
                if (!blob) {
                    pending--;
                    if (pending === 0 && blobs.filter(Boolean).length !== GUIDE_COLS) {
                        checkSetCaptureBusy(false);
                        if (checkEl.uploadProgress) { checkEl.uploadProgress.classList.add('hidden'); }
                        checkFeedback('Gagal menyiapkan gambar slot ' + idx + '. Coba lagi.', true);
                    }
                    return;
                }
                var filenameOriginal = checkBuildOriginalFilename(idx, now);
                var url = URL.createObjectURL(blob);
                checkSlotsData[idx - 1] = { blob: blob, url: url, slot: idx, filename_original: filenameOriginal, w: sw, h: sh, size: blob.size };
                var img = document.getElementById('check-slot-thumb-' + idx);
                if (img) { img.src = url; img.classList.remove('hidden'); }

                blobs[idx - 1] = { blob: blob, slot: idx, filename_original: filenameOriginal, w: sw, h: sh, size: blob.size };
                var readyCount = blobs.filter(Boolean).length;
                if (checkEl.uploadProgress) checkEl.uploadProgress.textContent = 'Menyiapkan ' + readyCount + '/' + GUIDE_COLS + '...';
                if (checkEl.captureLoadingSub) checkEl.captureLoadingSub.textContent = 'Menyiapkan ' + readyCount + '/' + GUIDE_COLS + '...';
                if (readyCount === GUIDE_COLS) {
                    if (checkEl.strip) checkEl.strip.classList.remove('hidden');
                    if (checkEl.uploadProgress) checkEl.uploadProgress.textContent = 'Mengirim ' + GUIDE_COLS + ' label ke server...';
                    if (checkEl.captureLoadingSub) checkEl.captureLoadingSub.textContent = 'Mengirim ' + GUIDE_COLS + ' label ke server...';
                    checkUploadBatch(blobs, batchCode, token);
                }
            }, 'image/png');
        });
    }

    function checkUploadBatch(blobs, batchCode, token) {
        if (checkBatchAbort) { try { checkBatchAbort.abort(); } catch (e) {} }
        checkBatchAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        var fd = new FormData();
        fd.append('reference_id', String(currentRef.id));
        fd.append('batch_code', batchCode);
        // Sort by slot to keep deterministic order
        blobs.sort(function (a, b) { return a.slot - b.slot; });
        blobs.forEach(function (item, i) {
            fd.append('images[' + i + ']', item.blob, item.filename_original);
            fd.append('filenames[' + i + ']', item.filename_original);
            fd.append('slot_numbers[' + i + ']', String(item.slot));
        });

        fetch('/api/check/captures', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Accept': 'application/json'
            },
            body: fd,
            signal: checkBatchAbort ? checkBatchAbort.signal : undefined
        }).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok) throw { status: res.status, body: body };
                return body;
            });
        }).then(function (json) {
            var session = (json.data && json.data.session) || {};
            var external = json.data && json.data.external;
            checkLastSessionId = session.id || null;
            try { sessionStorage.setItem('last_check_session_id', String(checkLastSessionId)); } catch (e) {}
            if (checkEl.uploadProgress) { checkEl.uploadProgress.classList.add('hidden'); checkEl.uploadProgress.textContent = ''; }
            var isSent = external && (external.status === 'sent' || external.status === 'success');
            var isQueued = external && (external.status === 'queued' || external.status === 'pending');
            // Render external first for preview fallback
            checkRenderExternal(external, session.batch_code || batchCode);
            if (isSent) {
                checkFeedback(checkSlotCount() + ' label tersimpan di FastAPI — menampilkan preview', false);
                checkEnterPreviewMode(session, external);
                // D3: batch dual 2-sisi → otomatis Process agar langsung ke hasil
                // compare PASS/FAIL per slot (tanpa tekan tombol Process manual).
                if (checkDualAutoProcess) {
                    checkDualAutoProcess = false;
                    console.log('[CHECK] Dual batch sent → auto Process ke OCR/compare');
                    setTimeout(function () { checkHandleProcess(); }, 600);
                }
            } else if (isQueued) {
                checkFeedback('Menunggu FastAPI menyimpan ' + checkSlotCount() + ' file...', false);
                if (checkEl.captureLoadingSub) checkEl.captureLoadingSub.textContent = 'Menyimpan ke FastAPI...';
                // Poll, then enter preview even if still queued after 3 tries
                var pollCount = 0;
                function pollOnce() {
                    if (pollCount++ > 3) {
                        checkEnterPreviewMode(session, external);
                        return;
                    }
                    setTimeout(function(){
                        var tok = checkGetToken();
                        fetch('/api/check/sessions/' + encodeURIComponent(session.id), {headers:{'Authorization':'Bearer '+tok, 'Accept':'application/json'}})
                        .then(function(r){return r.json();}).then(function(j){
                            var sess = j.data && j.data.session;
                            var caps = j.data && j.data.captures || [];
                            var polled = {
                                status: sess ? sess.external_status : 'queued',
                                saved_count: sess && sess.external_status==='sent'? caps.length:0,
                                paths: caps.map(function(c){return c.external_path;}).filter(Boolean),
                                error: sess ? sess.external_error : null,
                                batch_code: sess ? sess.batch_code : batchCode
                            };
                            checkRenderExternal(polled, sess ? sess.batch_code : batchCode);
                            if (polled.status==='sent') {
                                checkEnterPreviewMode(sess, polled);
                            } else if (polled.status==='failed') {
                                checkEnterPreviewMode(sess, polled);
                            } else {
                                pollOnce();
                            }
                        }).catch(function(){ pollOnce(); });
                    }, 2000);
                }
                // Also start generic poll for retry button
                checkPollExternal(session.id, session.batch_code || batchCode, 0);
                pollOnce();
            } else {
                // failed -> still show preview with error (preview tetap karena DB sukses)
                checkFeedback(external && external.error ? external.error : GUIDE_COLS + ' label terkirim — menampilkan preview', external && external.status==='failed');
                checkEnterPreviewMode(session, external);
            }
            // Legacy session badge still update (hidden in live scan, cloned to preview)
            if (checkEl.sessionBadge && session.id) {
                if (checkEl.sessionIdEl) checkEl.sessionIdEl.textContent = session.id;
                if (checkEl.sessionCodeEl) checkEl.sessionCodeEl.textContent = session.batch_code || batchCode;
                checkEl.sessionBadge.classList.remove('hidden');
            }
            var flash = document.getElementById('check-capture-flash');
            if (flash) { flash.classList.remove('opacity-0'); setTimeout(function () { if (flash) flash.classList.add('opacity-0'); }, 150); }
            console.log('[CHECK] batch uploaded:', session.id, batchCode, blobs.map(function (b) { return b.filename_original; }).join(','), 'external:', external);
        }).catch(function (err) {
            checkShowCaptureLoading(false);
            if (checkEl.uploadProgress) { checkEl.uploadProgress.classList.add('hidden'); checkEl.uploadProgress.textContent = ''; }
            checkSetCaptureBusy(false);
            checkBatchAbort = null;
            if (err && err.name === 'AbortError') {
                checkFeedback('Pengiriman dibatalkan.', true);
                return;
            }
            var status = err && err.status;
            var body = err && err.body;
            if (status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                checkFeedback('Sesi berakhir. Silakan login kembali.', true);
                setTimeout(function () { window.location.href = '/login'; }, 900);
                return;
            }
            if (status === 422 && body && body.errors) {
                var firstKey = Object.keys(body.errors)[0];
                var msg = firstKey ? (body.errors[firstKey][0] || 'Validasi gagal') : 'Validasi gagal';
                checkFeedback(msg, true);
                return;
            }
            if (status === 429) {
                checkFeedback('Terlalu banyak permintaan. Coba lagi 60 detik.', true);
                return;
            }
            var fallbackMsg = (body && body.message) || (err && err.message) || 'Gagal mengirim. Coba lagi.';
            checkFeedback(fallbackMsg, true);
            // Still try to render external if present in error body
            if (body && body.data && body.data.external) {
                checkRenderExternal(body.data.external, batchCode);
            }
        });
    }

    function checkInitScanControls() {
        checkEl = {
            startBtn: document.getElementById('check-start-scan-btn'),
            stopBtn: document.getElementById('check-stop-scan-btn'),
            captureBtn: document.getElementById('check-capture-btn'),
            cameraCard: document.getElementById('check-camera-card'),
            video: document.getElementById('check-camera-preview'),
            guideCanvas: document.getElementById('check-guide-overlay'),
            guideRange: document.getElementById('check-guide-size-range'),
            guideValue: document.getElementById('check-guide-size-value'),
            cameraLabel: document.getElementById('check-camera-label'),
            cameraSelect: document.getElementById('check-camera-select'),
            cameraResBadge: document.getElementById('check-camera-res-badge'),
            captureFeedback: document.getElementById('check-capture-feedback'),
            strip: document.getElementById('check-slot-strip'),
            uploadProgress: document.getElementById('check-upload-progress'),
            sessionBadge: document.getElementById('check-session-badge'),
            sessionIdEl: document.getElementById('check-session-id'),
            sessionCodeEl: document.getElementById('check-session-code'),
            captureSpinner: document.getElementById('check-capture-spinner'),
            captureIcon: document.getElementById('check-capture-icon'),
            captureText: document.getElementById('check-capture-text'),
            uploadHint: document.getElementById('check-upload-hint'),
            externalStatus: document.getElementById('check-external-status'),
            externalIcon: document.getElementById('check-external-icon'),
            externalTitle: document.getElementById('check-external-title'),
            externalMsg: document.getElementById('check-external-message'),
            externalPaths: document.getElementById('check-external-paths'),
            externalRetry: document.getElementById('check-external-retry'),
            captureLoading: document.getElementById('check-capture-loading'),
            captureLoadingSub: document.getElementById('check-capture-loading-sub'),
            previewCard: document.getElementById('check-preview-card'),
            previewSessionBadge: document.getElementById('check-preview-session-badge'),
            previewSessionId: document.getElementById('check-preview-session-id'),
            previewSessionCode: document.getElementById('check-preview-session-code'),
            previewExternal: document.getElementById('check-preview-external-status'),
            ocrStatus: document.getElementById('check-ocr-status'),
            ocrIcon: document.getElementById('check-ocr-icon'),
            ocrTitle: document.getElementById('check-ocr-title'),
            ocrMsg: document.getElementById('check-ocr-message'),
            retakeBtn: document.getElementById('check-retake-btn'),
            processBtn: document.getElementById('check-process-btn'),
            processSpinner: document.getElementById('check-process-spinner'),
            processText: document.getElementById('check-process-text'),
            // Dual-side scan elements
            dualFrontMini: document.getElementById('check-dual-front-mini'),
            dualResultCard: document.getElementById('check-dual-result-card'),
            dualResultActions: document.getElementById('check-dual-result-actions'),
            retakeFrontBtn: document.getElementById('check-retake-front'),
            retakeBackBtn: document.getElementById('check-retake-back'),
            proceedCheckBtn: document.getElementById('check-proceed-check'),
            session1Card: document.getElementById('check-session1-card'),
            session1Img: document.getElementById('check-session1-img'),
            session1RetakeBtn: document.getElementById('check-session1-retake'),
            gotoSession2Btn: document.getElementById('check-goto-session2-btn'),
            dualProcessFeedback: document.getElementById('check-dual-process-feedback')
        };

        // Kill-switch permanen: buang sisa mini FRONT bila masih ada di DOM (mis. HTML lama ter-cache).
        try { var _killMini = document.getElementById('check-dual-front-mini'); if (_killMini) _killMini.remove(); } catch (e) {}
        try { var _killMiniCanvas = document.getElementById('check-front-mini-canvas'); if (_killMiniCanvas) _killMiniCanvas.remove(); } catch (e) {}

        checkGuideSize = checkLoadGuideSize();
        if (checkEl.guideRange) checkEl.guideRange.value = checkGuideSize;
        if (checkEl.guideValue) checkEl.guideValue.textContent = checkGuideSize + '%';

        if (checkEl.startBtn) checkEl.startBtn.addEventListener('click', function() {
            checkDualEnterStage1();
            checkStartCamera();
        });
        if (checkEl.stopBtn) checkEl.stopBtn.addEventListener('click', function() {
            checkDualResetAll();
            checkStopCamera();
        });
        if (checkEl.captureBtn) checkEl.captureBtn.addEventListener('click', checkDualOnCaptureClick);
        if (checkEl.externalRetry) checkEl.externalRetry.addEventListener('click', checkRetryExternal);
        if (checkEl.retakeBtn) checkEl.retakeBtn.addEventListener('click', function(e){
            e.preventDefault();
            checkHandleRetake();
        });
        if (checkEl.processBtn) checkEl.processBtn.addEventListener('click', function(e){
            e.preventDefault();
            if (checkEl.processBtn.disabled) return;
            checkHandleProcess();
        });
        var retryPollBtn = document.getElementById('check-ocr-retry-poll-btn');
        if (retryPollBtn) {
            retryPollBtn.addEventListener('click', function(e) {
                e.preventDefault();
                checkFetchLatestSessionStatus();
            });
        }

        // Similarity Detail Modal event listeners
        var previewCard = document.getElementById('check-preview-card');
        if (previewCard) {
            previewCard.addEventListener('click', function (e) {
                var btn = e.target.closest('.check-detail-btn');
                if (btn) {
                    e.preventDefault();
                    var slot = btn.getAttribute('data-slot');
                    if (slot) checkOpenSimilarityDetailModal(parseInt(slot, 10));
                }
            });
        }
        var simModal = document.getElementById('check-similarity-modal');
        var simClose1 = document.getElementById('check-sim-modal-close');
        var simClose2 = document.getElementById('check-sim-modal-close-btn');
        if (simClose1) simClose1.addEventListener('click', checkCloseSimilarityDetailModal);
        if (simClose2) simClose2.addEventListener('click', checkCloseSimilarityDetailModal);
        if (simModal) {
            simModal.addEventListener('click', function (e) {
                if (e.target === simModal) checkCloseSimilarityDetailModal();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && simModal && !simModal.classList.contains('hidden')) {
                checkCloseSimilarityDetailModal();
            }
        });
        // Dual-side retake buttons
        if (checkEl.retakeFrontBtn) checkEl.retakeFrontBtn.addEventListener('click', function() { checkDualRetake('front'); });
        if (checkEl.retakeBackBtn) checkEl.retakeBackBtn.addEventListener('click', function() { checkDualRetake('back'); });
        // Preview Sesi 1: Retake Front + Lanjut ke Capture 2 (Back)
        if (checkEl.session1RetakeBtn) checkEl.session1RetakeBtn.addEventListener('click', function() { checkDualRetake('front'); });
        if (checkEl.gotoSession2Btn) checkEl.gotoSession2Btn.addEventListener('click', function() { checkDualGoToSession2(); });
        // Sesi 2 BACK: Retake Back + Lihat Hasil 2 Sisi (cermin Sesi 1, guard per tombol)
        var session2RetakeBtn = document.getElementById('check-session2-retake');
        if (session2RetakeBtn) session2RetakeBtn.addEventListener('click', function() { checkDualRetake('back'); });
        var gotoResultBtn = document.getElementById('check-goto-result-btn');
        if (gotoResultBtn) gotoResultBtn.addEventListener('click', function(e) { e.preventDefault(); checkDualGoToResult(); });
        // Process 2 sisi — kirim 4 split lalu auto-Process (D1+D3).
        if (checkEl.proceedCheckBtn) checkEl.proceedCheckBtn.addEventListener('click', function(e) {
            e.preventDefault();
            checkDualProcessInert();
        });
        // Set Rotation — 8 tombol: 6 existing + 2 Sesi 2 (cermin Sesi 1, guard per tombol)
        (function checkWireRotate() {
            var pairs = [
                ['check-session1-rotate-left', 'front', -90],
                ['check-session1-rotate-right', 'front', 90],
                ['check-result-front-rotate-left', 'front', -90],
                ['check-result-front-rotate-right', 'front', 90],
                ['check-result-back-rotate-left', 'back', -90],
                ['check-result-back-rotate-right', 'back', 90],
                ['check-session2-rotate-left', 'back', -90],
                ['check-session2-rotate-right', 'back', 90]
            ];
            pairs.forEach(function (p) {
                var el = document.getElementById(p[0]);
                if (el) el.addEventListener('click', function (e) {
                    e.preventDefault();
                    checkRotateSide(p[1], p[2]);
                });
            });
            try { checkUpdateRotateUI(); } catch (e) {}
        })();
        // Tab Hasil ekstraksi teks Sesi 1 (Label 1 / Label 2) + render awal.
        try { checkSplitWireTabs(); } catch (e) {}
        // FIX-OCR-COLLAPSE (CP-075) — wiring dropdown Lihat teks/Sembunyikan (null-safe, sekali saja).
        try { checkOcrToggleWire(); } catch (e) {}
        try { checkSession1RenderTab(); } catch (e) {}
        // PLAN_CHECKING_LABEL_BACK_SIDE_OCR B4: render awal tab Sesi 2 BACK.
        try { checkSession2RenderTab(); } catch (e) {}
        // Auto Crop — 4 tombol Simpan, SEMUA auto-OCR (FRONT + BACK sama).
        // PLAN_CHECKING_LABEL_BACK_SIDE_OCR B1: skipOcr Sesi 2 dihapus — Simpan BACK kirim OCR.
        (function checkWireCrop() {
            var pairs = [
                ['check-session1-crop', 'front', null],
                ['check-result-front-crop', 'front', null],
                ['check-result-back-crop', 'back', null],
                ['check-session2-crop', 'back', null]
            ];
            pairs.forEach(function (p) {
                var el = document.getElementById(p[0]);
                if (el) el.addEventListener('click', function (e) {
                    e.preventDefault();
                    checkCropSide(p[1], p[2]);
                });
            });
            try { checkSetCropUI('front', !!checkFrontCapture); } catch (e) {}
            try { checkSetCropUI('back', !!checkBackCapture); } catch (e) {}
        })();
        // Ensure preview card hidden initially
        if (checkEl.previewCard) checkEl.previewCard.classList.add('hidden');
        if (checkEl.captureLoading) checkEl.captureLoading.classList.add('hidden');
        if (checkEl.ocrStatus) checkEl.ocrStatus.classList.add('hidden');

        // FIX-GUIDE-SLIDER: update variabel + label + persist + debounce redraw + change untuk keyboard
        if (checkEl.guideRange) {
            var _checkSliderOnInput = function () {
                var _v = parseInt(checkEl.guideRange.value, 10);
                if (_v >= GUIDE_MIN_SIZE && _v <= GUIDE_MAX_SIZE) checkGuideSize = _v;
                else checkGuideSize = checkSyncGuideSizeFromDom();
                if (checkEl.guideValue) checkEl.guideValue.textContent = (checkEl.guideRange.value || checkGuideSize) + '%';
                checkPersistGuideSize(checkGuideSize);
                // FIX-GUIDE-SLIDER (CP-073): gambar langsung tiap input agar smooth saat drag;
                // debounce di bawah sebagai trailing guarantee bila event datang beruntun.
                try { checkDrawGuide(); } catch (e) {}
                clearTimeout(checkGuideResizeTimer);
                checkGuideResizeTimer = setTimeout(checkDrawGuide, 150);
            };
            checkEl.guideRange.addEventListener('input', _checkSliderOnInput);
            checkEl.guideRange.addEventListener('change', function () {
                _checkSliderOnInput();
                try { checkDrawGuide(); } catch (e) {}
            });
        }

        if (checkEl.video) {
            checkEl.video.addEventListener('loadedmetadata', function () {
                checkDrawGuide();
                checkUpdateCameraResolutionBadge();
            });
            checkEl.video.addEventListener('canplay', function () {
                checkDrawGuide();
                checkUpdateCameraResolutionBadge();
            });
        }

        if (checkEl.cameraSelect) {
            checkEl.cameraSelect.addEventListener('change', function () {
                var selectedId = this.value;
                if (!selectedId) return;
                try { localStorage.setItem(CAMERA_STORAGE_KEY, selectedId); } catch (e) {}
                if (checkStream) {
                    try { checkStream.getTracks().forEach(function (t) { t.stop(); }); } catch(e){}
                    checkStream = null;
                    checkStartCamera(selectedId);
                }
            });
        }

        if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
            navigator.mediaDevices.addEventListener('devicechange', function () {
                var savedId = null;
                try { savedId = localStorage.getItem(CAMERA_STORAGE_KEY); } catch (e) {}
                checkPopulateCameraSelect(savedId);
            });
        }
        checkPopulateCameraSelect();
        window.addEventListener('resize', function () {
            clearTimeout(checkGuideResizeTimer);
            checkGuideResizeTimer = setTimeout(checkDrawGuide, 150);
        });
        window.addEventListener('orientationchange', function () {
            clearTimeout(checkGuideResizeTimer);
            checkGuideResizeTimer = setTimeout(checkDrawGuide, 300);
        });
        window.addEventListener('pagehide', function () { checkRevokeRefImage(); checkRevokeRefImageBack(); checkDualResetAll(); checkStopCamera(); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!window.App) return;
        App.protectPage();
        App.initLogout();

        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) {
            showError('Sesi berakhir. Silakan login kembali.');
            return;
        }

        var urlRef = getRefIdFromUrl();
        var urlBack = getRefBackIdFromUrl();
        var urlSession = getSessionFromUrl();
        // ?session= menang atas ?ref= (single source: 1 sesi server-side).
        if (urlSession) {
            fetchSession(urlSession, token).then(function (sdata) {
                var front = sdata.front || (sdata.items || [])[0];
                var back = sdata.back || null;
                if (!front) throw new Error('NO_REF');
                // items = seluruh belahan 1 sesi (4 untuk Simpan-4): jadikan pair agar resolver 4-slot yang sama terpakai.
                try {
                    var items = sdata.items || [];
                    if (front && items.length > 1) {
                        var sibs = items.filter(function (it) { return it && it.id && String(it.id) !== String(front.id); });
                        var copy = {};
                        for (var kk in front) { if (Object.prototype.hasOwnProperty.call(front, kk)) copy[kk] = front[kk]; }
                        copy.pair = sibs;
                        front = copy;
                    }
                } catch (e) {}
                try { sessionStorage.setItem('last_reference_id', String(front.id)); } catch (e) {}
                if (back) { try { sessionStorage.setItem('last_reference_id_back', String(back.id)); } catch (e) {} }
                renderRefDual(front, back);
                initNotifDropdown();
                checkInitScanControls();
            }).catch(function (err) {
                if (err.message === 'UNAUTHORIZED') {
                    try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                    showError('Sesi berakhir. Silakan login kembali.');
                    return;
                }
                showEmpty();
            });
            return;
        }
        var refId = urlRef;
        if (!refId) {
            try { refId = sessionStorage.getItem('last_reference_id'); } catch (e) {}
        }
        if (refId) {
            try { sessionStorage.setItem('last_reference_id', refId); } catch (e) {}
        }

        // Id belakang: URL dulu, lalu session — session hanya bila depan efektif satu set dengan depan tersimpan
        var refBackId = urlBack;
        if (!refBackId) {
            var storedFront = null;
            try { storedFront = sessionStorage.getItem('last_reference_id'); } catch (e) {}
            if (refId && storedFront && String(refId) === String(storedFront)) {
                try { refBackId = sessionStorage.getItem('last_reference_id_back'); } catch (e) {}
            }
        }
        if (refBackId && String(refBackId) === String(refId)) refBackId = null; // harus beda dari depan
        if (refBackId) {
            try { sessionStorage.setItem('last_reference_id_back', refBackId); } catch (e) {}
        } else if (urlRef) {
            try { sessionStorage.removeItem('last_reference_id_back'); } catch (e) {} // acuan baru tanpa pasangan → buang pasangan basi
        }

        var promise;
        if (refId) {
            promise = fetchRefById(refId, token).catch(function (e) {
                if (e.message === 'UNAUTHORIZED') throw e;
                // NOT_FOUND (mis. milik user lain atau dihapus) → fallback latest (pasangan lama tidak berlaku)
                refBackId = null;
                try { sessionStorage.removeItem('last_reference_id_back'); } catch (err) {}
                return fetchLatest(token);
            }).then(function (frontData) {
                if (!frontData) throw new Error('NO_REF');
                // Tanpa ?ref_back= eksplisit, pakai pair 1 sesi dari server (front+back kompak).
                var autoBack = pickBackFromPayload(frontData, refBackId);
                if (!refBackId) return { front: frontData, back: autoBack };
                return fetchRefById(refBackId, token).then(function (backData) {
                    return { front: frontData, back: backData || autoBack };
                }).catch(function (e2) {
                    if (e2.message === 'UNAUTHORIZED') throw e2;
                    return { front: frontData, back: autoBack }; // BACK tidak tersedia — depan tetap tampil
                });
            });
        } else {
            promise = fetchLatest(token).then(function (frontData) {
                if (!frontData) throw new Error('NO_REF');
                return { front: frontData, back: pickBackFromPayload(frontData, null) };
            });
        }

        promise.then(function (pair) {
            if (!pair || !pair.front) throw new Error('NO_REF');
            if (pair.back) renderRefDual(pair.front, pair.back);
            initNotifDropdown();
            checkInitScanControls();
        }).catch(function (err) {
            if (err.message === 'UNAUTHORIZED') {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                showError('Sesi berakhir. Silakan login kembali.');
                return;
            }
            if (err.message === 'NO_REF' || err.message === 'NOT_FOUND') {
                showEmpty();
                return;
            }
            showError(err.message || 'Gagal memuat Reference acuan. Coba muat ulang.');
        });
    });
})();

async function checkCallCompareSimilarity() {
    const sess = checkCurrentSession || {};
    const sid = sess.id;
    const bcode = sess.batch_code || checkBatchCode || null;
    if (!sid) return null;
    try {
        const token = localStorage.getItem('label_inspection_token') || sessionStorage.getItem('label_inspection_token');
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (token) headers['Authorization'] = 'Bearer ' + token;
        const pairs = [ {side:'front',idx:1},{side:'front',idx:2},{side:'back',idx:1},{side:'back',idx:2} ];
        const idemp = 'cmp-' + (bcode || sid) + '-' + Date.now().toString().slice(-6);
        headers['Idempotency-Key'] = idemp;
        const res = await fetch('/api/check/sessions/' + sid + '/compare-similarity', { method: 'POST', headers, body: JSON.stringify({ batch_code: bcode, pairs }) });
        if (!res.ok) return null;
        const json = await res.json();
        if (json && json.success) return json;
        return null;
    } catch (e) {
        if (window.DEBUG) console.warn('[checkCallCompareSimilarity] err', e);
        return null;
    }
}

