(function () {
    'use strict';

    var fetchBtn, feedbackEl, statsEl, listEl, tableBody;
    var statDownloaded, statPending, statFailed;
    var isBusy = false;
    var feedbackTimer = null;

    function setBusy(busy) {
        isBusy = busy;
        if (fetchBtn) fetchBtn.disabled = busy;
    }

    function showFeedback(message, isError) {
        if (!feedbackEl) return;
        feedbackEl.textContent = message;
        feedbackEl.className = 'text-xs font-medium ' +
            (isError ? 'text-[#E04438]' : 'text-[#6C757D]');
        clearTimeout(feedbackTimer);
        feedbackTimer = setTimeout(function () {
            if (feedbackEl) feedbackEl.textContent = '';
        }, 5000);
    }

    function loadCaptures() {
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) return;

        fetch('/api/captures', {
            headers: { 'Authorization': 'Bearer ' + token }
        }).then(function (res) {
            if (!res.ok) throw new Error('Failed');
            return res.json();
        }).then(function (data) {
            var items = data.data.data || [];
            var downloaded = items.filter(function (i) { return i.status === 'downloaded'; }).length;
            var pending = items.filter(function (i) { return i.status === 'pending'; }).length;
            var failed = items.filter(function (i) { return i.status === 'failed'; }).length;

            if (statDownloaded) statDownloaded.textContent = downloaded;
            if (statPending) statPending.textContent = pending;
            if (statFailed) statFailed.textContent = failed;

            if (items.length > 0) {
                if (statsEl) statsEl.classList.remove('hidden');
                if (listEl) listEl.classList.remove('hidden');
                renderTable(items.slice(0, 10));
            }
        }).catch(function () {});
    }

    function renderTable(items) {
        if (!tableBody) return;
        tableBody.innerHTML = '';

        items.forEach(function (item) {
            var tr = document.createElement('tr');
            tr.className = 'border-b border-[#6C757D]/10';

            var statusClass = item.status === 'downloaded'
                ? 'text-emerald-600'
                : item.status === 'failed'
                    ? 'text-[#E04438]'
                    : 'text-[#6C757D]';

            var actionHtml = item.status === 'failed'
                ? '<button class="retry-btn text-[#E04438] hover:underline text-xs font-semibold" data-id="' + item.id + '">Retry</button>'
                : item.status === 'downloaded'
                    ? '<span class="text-xs text-[#6C757D]">OK</span>'
                    : '<span class="text-xs text-[#6C757D]">-</span>';

            tr.innerHTML =
                '<td class="py-2 text-[#212529] font-medium break-all">' + escapeHtml(item.original_filename) + '</td>' +
                '<td class="py-2 text-[#6C757D]">' + escapeHtml(item.template_id) + ' \u2014 ' + escapeHtml(item.template_name) + '</td>' +
                '<td class="py-2 ' + statusClass + ' font-medium capitalize">' + escapeHtml(item.status) + '</td>' +
                '<td class="py-2 text-[#6C757D]">' + new Date(item.created_at).toLocaleDateString() + '</td>' +
                '<td class="py-2 text-right">' + actionHtml + '</td>';

            tableBody.appendChild(tr);
        });

        var retryBtns = tableBody.querySelectorAll('.retry-btn');
        for (var i = 0; i < retryBtns.length; i++) {
            retryBtns[i].addEventListener('click', function () {
                retryCapture(this.getAttribute('data-id'));
            });
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function fetchCaptures() {
        if (isBusy) return;

        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) {
            showFeedback('Sesi berakhir. Silakan login kembali.', true);
            return;
        }

        setBusy(true);
        showFeedback('Mengambil file dari external service...', false);

        fetch('/api/captures/fetch', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        }).then(function (res) {
            if (res.status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                throw new Error('UNAUTHORIZED');
            }
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'Server error'); });
            return res.json();
        }).then(function (data) {
            setBusy(false);
            showFeedback(data.message, false);
            console.log('[LabelCapture] Fetch result:', data);
            loadCaptures();
        }).catch(function (err) {
            setBusy(false);
            if (err && err.message === 'UNAUTHORIZED') {
                showFeedback('Sesi berakhir. Silakan login kembali.', true);
            } else {
                showFeedback('Gagal mengambil file. Coba lagi.', true);
            }
        });
    }

    function retryCapture(id) {
        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) return;

        showFeedback('Retry download...', false);

        fetch('/api/captures/' + id + '/retry', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        }).then(function (res) {
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message); });
            return res.json();
        }).then(function (data) {
            showFeedback(data.message, false);
            loadCaptures();
        }).catch(function () {
            showFeedback('Retry gagal.', true);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        fetchBtn = document.getElementById('fetch-captures-btn');
        feedbackEl = document.getElementById('fetch-feedback');
        statsEl = document.getElementById('capture-stats');
        listEl = document.getElementById('capture-list');
        tableBody = document.getElementById('capture-table-body');
        statDownloaded = document.getElementById('stat-downloaded');
        statPending = document.getElementById('stat-pending');
        statFailed = document.getElementById('stat-failed');

        if (fetchBtn) fetchBtn.addEventListener('click', fetchCaptures);

        loadCaptures();
    });
})();
