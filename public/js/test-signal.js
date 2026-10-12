(function () {
    'use strict';

    var trueBtn, falseBtn, feedbackEl;
    var isBusy = false;

    function setBusy(busy) {
        isBusy = busy;
        if (trueBtn) trueBtn.disabled = busy;
        if (falseBtn) falseBtn.disabled = busy;
    }

    function showFeedback(message, isError) {
        if (!feedbackEl) return;
        feedbackEl.textContent = message;
        feedbackEl.className = 'text-xs font-medium ' +
            (isError ? 'text-[#E04438]' : 'text-[#6C757D]');
        setTimeout(function () {
            if (feedbackEl) feedbackEl.textContent = '';
        }, 5000);
    }

    function sendStatus(status) {
        if (isBusy) return;

        var token = '';
        try { token = sessionStorage.getItem('label_inspection_token'); } catch (e) {}
        if (!token) {
            showFeedback('Sesi berakhir. Silakan login kembali.', true);
            return;
        }

        setBusy(true);
        showFeedback('Mengirim data ' + status + '...', false);

        fetch('/api/test', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ status: status })
        }).then(function (res) {
            if (res.status === 401) {
                try { sessionStorage.removeItem('label_inspection_token'); } catch (e) {}
                throw new Error('UNAUTHORIZED');
            }
            if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'Server error'); });
            return res.json();
        }).then(function (data) {
            setBusy(false);
            showFeedback('Terkirim: ' + status + ' → ' + (data.external_status || 'OK'), false);
            console.log('[TestSignal] Response:', data);
        }).catch(function (err) {
            setBusy(false);
            if (err && err.message === 'UNAUTHORIZED') {
                showFeedback('Sesi berakhir. Silakan login kembali.', true);
            } else {
                showFeedback('Gagal mengirim. Coba lagi.', true);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        trueBtn = document.getElementById('test-true-btn');
        falseBtn = document.getElementById('test-false-btn');
        feedbackEl = document.getElementById('test-feedback');

        if (trueBtn) trueBtn.addEventListener('click', function () { sendStatus('true'); });
        if (falseBtn) falseBtn.addEventListener('click', function () { sendStatus('false'); });
    });
})();
