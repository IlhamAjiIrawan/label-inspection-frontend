(function () {
    'use strict';

    var TOKEN_KEY = 'label_inspection_token';

    function getToken() {
        return sessionStorage.getItem(TOKEN_KEY);
    }

    function clearToken() {
        sessionStorage.removeItem(TOKEN_KEY);
    }

    function authHeaders(extra) {
        var headers = Object.assign({
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }, extra || {});

        var token = getToken();
        if (token) headers['Authorization'] = 'Bearer ' + token;

        return headers;
    }

    function redirectToLogin() {
        clearToken();
        window.location.href = '/login';
    }

    function protectPage() {
        var token = getToken();
        if (!token) {
            redirectToLogin();
            return;
        }

        fetch('/api/user', {
            method: 'GET',
            headers: authHeaders()
        }).then(function (response) {
            if (response.status === 401) {
                redirectToLogin();
                throw new Error('unauthorized');
            }
            return response.json();
        }).catch(function (error) {
            if (error.message !== 'unauthorized') {
                // Network failure: keep the user on the page without a hard error.
            }
        });
    }

    function initLogout() {
        var logoutBtn = document.getElementById('logout-btn');
        if (!logoutBtn) return;

        logoutBtn.addEventListener('click', function () {
            logoutBtn.disabled = true;
            logoutBtn.textContent = 'Logging out...';

            fetch('/api/logout', {
                method: 'POST',
                headers: authHeaders()
            }).then(function () {
                redirectToLogin();
            }).catch(function () {
                redirectToLogin();
            });
        });
    }

    function formatToday() {
        try {
            return new Date().toLocaleDateString('en-GB', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
        } catch (e) {
            return new Date().toDateString();
        }
    }

    window.App = {
        getToken: getToken,
        clearToken: clearToken,
        authHeaders: authHeaders,
        protectPage: protectPage,
        initLogout: initLogout,
        formatToday: formatToday
    };
})();