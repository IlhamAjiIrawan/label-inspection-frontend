(function () {
    'use strict';

    function maskNik(nik) {
        if (!nik) return '-';
        if (nik.length === 9) return nik.slice(0, 3) + '***' + nik.slice(6);
        if (nik.length === 16) return nik.slice(0, 4) + '********' + nik.slice(12);
        return nik;
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!window.App) return;

        App.protectPage();
        App.initLogout();

        var dateEl = document.getElementById('today-date');
        if (dateEl) dateEl.textContent = App.formatToday();

        // Populate user panel NIK mask (NIK-only login, 9 digits)
        var token = App.getToken();
        if (!token) return;
        fetch('/api/user', {
            method: 'GET',
            headers: App.authHeaders()
        })
            .then(function (response) {
                if (!response.ok) throw new Error('unauthorized');
                return response.json();
            })
            .then(function (user) {
                var nameEl = document.getElementById('user-name');
                var nikEl = document.getElementById('user-nik');
                var initialEl = document.getElementById('user-initial');
                var panel = document.getElementById('user-panel');
                if (nameEl) nameEl.textContent = user.name || '-';
                if (nikEl) {
                    nikEl.textContent = user.nik ? maskNik(user.nik) : (user.username || '-');
                    nikEl.title = user.nik || user.username || '';
                    nikEl.setAttribute('data-nik-full', user.nik || '');
                }
                if (initialEl) initialEl.textContent = user.name ? user.name.charAt(0).toUpperCase() : '?';
                if (panel && user.name) panel.classList.remove('hidden');
            })
            .catch(function () { /* silent: protectPage will redirect on 401 */ });
    });
})();
