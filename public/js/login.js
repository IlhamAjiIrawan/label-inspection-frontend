(function () {
    'use strict';

    var TOKEN_KEY = 'label_inspection_token';

    function getToken() {
        try { return sessionStorage.getItem(TOKEN_KEY); } catch (e) { return null; }
    }

    function setToken(token) {
        try { sessionStorage.setItem(TOKEN_KEY, token); } catch (e) { /* quota / private mode */ }
    }

    function clearToken() {
        try { sessionStorage.removeItem(TOKEN_KEY); } catch (e) { /* ignore */ }
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

    // --- UI helpers (AOI palette #0284C7 for error) ---
    function showError(containerId, message) {
        var box = document.getElementById(containerId);
        if (!box) return;
        var textEl = document.getElementById('error-text') || box;
        var msg = message || 'An unexpected error occurred. Please try again.';
        if (textEl !== box) {
            textEl.textContent = msg;
        } else {
            box.textContent = msg;
        }
        box.classList.remove('hidden');
        box.classList.add('flex');
        box.classList.remove('animate-shake');
        void box.offsetWidth;
        box.classList.add('animate-shake');
        setTimeout(function () { box.classList.remove('animate-shake'); }, 360);
    }

    function hideError(containerId) {
        var box = document.getElementById(containerId);
        if (!box) return;
        box.classList.add('hidden');
        box.classList.remove('flex', 'animate-shake');
        var textEl = document.getElementById('error-text');
        if (textEl) textEl.textContent = '';
    }

    function setFieldInvalid(input, invalid) {
        if (!input) return;
        var field = input.closest('[data-field]');
        if (invalid) {
            input.setAttribute('aria-invalid', 'true');
            input.classList.add('border-[#0284C7]', 'bg-[#0284C7]/[0.04]');
            input.classList.remove('border-[#E9ECEF]', 'bg-white');
            if (field) field.setAttribute('data-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
            input.classList.remove('border-[#0284C7]', 'bg-[#0284C7]/[0.04]');
            input.classList.add('border-[#E9ECEF]', 'bg-white');
            if (field) field.removeAttribute('data-invalid');
        }
    }

    function setLoading(btn, isLoading) {
        if (!btn) return;
        var spinner = document.getElementById('btn-spinner');
        var label = document.getElementById('btn-text');
        btn.disabled = isLoading;
        btn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        if (spinner) {
            if (isLoading) spinner.classList.remove('hidden');
            else spinner.classList.add('hidden');
        }
        if (label) label.textContent = isLoading ? 'Processing...' : 'Sign In';
        if (!label) btn.textContent = isLoading ? 'Processing...' : 'Sign In';
    }

    function maskNik(nik) {
        if (!nik) return '-';
        if (nik.length === 9) return nik.slice(0, 3) + '***' + nik.slice(6);
        if (nik.length === 16) return nik.slice(0, 4) + '********' + nik.slice(12);
        return nik;
    }

    function initNikInput() {
        var nik = document.getElementById('nik');
        var counter = document.getElementById('nik-counter');
        if (!nik) return;

        function sanitize(v) { return v.replace(/[^0-9]/g, '').slice(0, 9); }

        function updateCounter() {
            if (!counter) return;
            counter.textContent = nik.value.length + '/9';
            counter.className = nik.value.length === 9
                ? 'text-xs tabular-nums text-[#0284C7] font-medium'
                : 'text-xs tabular-nums text-[#6C757D]/70';
        }

        nik.addEventListener('input', function () {
            var s = sanitize(nik.value);
            if (nik.value !== s) nik.value = s;
            hideError('error-box');
            setFieldInvalid(nik, false);
            updateCounter();
        });

        nik.addEventListener('paste', function () {
            setTimeout(function () {
                nik.value = sanitize(nik.value);
                hideError('error-box');
                setFieldInvalid(nik, false);
                updateCounter();
            }, 0);
        });

        nik.addEventListener('focus', function () {
            var box = document.getElementById('error-box');
            if (box) box.classList.remove('animate-shake');
        });

        updateCounter();
    }

    function initLoginPage() {
        var form = document.getElementById('login-form');
        if (!form) return;

        if (getToken()) {
            window.location.href = '/dashboard';
            return;
        }

        var nikInput = document.getElementById('nik');
        var btn = document.getElementById('submit-btn');

        initNikInput();

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hideError('error-box');
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var nik = nikInput ? nikInput.value.trim() : '';

            hideError('error-box');
            setFieldInvalid(nikInput, false);

            if (!nik) {
                setFieldInvalid(nikInput, true);
                showError('error-box', 'Employee NIK is required.');
                nikInput.focus();
                return;
            }

            if (!/^[0-9]{9}$/.test(nik)) {
                setFieldInvalid(nikInput, true);
                showError('error-box', 'Employee NIK must be 9 digits.');
                nikInput.focus();
                return;
            }

            setLoading(btn, true);

            fetch('/api/login', {
                method: 'POST',
                headers: authHeaders(),
                body: JSON.stringify({ nik: nik })
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, status: response.status, data: data };
                    }).catch(function () {
                        return { ok: response.ok, status: response.status, data: {} };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.data.token) {
                        setToken(result.data.token);
                        window.location.href = '/dashboard';
                    } else if (result.status === 429) {
                        showError('error-box', 'Too many attempts. Please try again later.');
                    } else if (result.status === 422) {
                        var msg = (result.data.errors && result.data.errors.nik) ? result.data.errors.nik[0] : 'Employee NIK must be 9 digits.';
                        showError('error-box', msg);
                        setFieldInvalid(nikInput, true);
                    } else {
                        showError('error-box', 'Employee NIK not registered');
                        setFieldInvalid(nikInput, true);
                    }
                })
                .catch(function () {
                    showError('error-box', 'Unable to connect to server. Please check your connection.');
                })
                .finally(function () {
                    setLoading(btn, false);
                });
        });
    }

    function initDashboardPage() {
        var nameEl = document.getElementById('user-name');
        var usernameEl = document.getElementById('user-username');
        var nikEl = document.getElementById('user-nik');
        var initialEl = document.getElementById('user-initial');
        var logoutBtn = document.getElementById('logout-btn');
        if (!nameEl && !nikEl && !usernameEl) return;
        if (!logoutBtn && !nameEl) return;

        var token = getToken();
        if (!token) {
            window.location.href = '/login';
            return;
        }

        fetch('/api/user', {
            method: 'GET',
            headers: authHeaders()
        })
            .then(function (response) {
                if (response.status === 401) {
                    clearToken();
                    window.location.href = '/login';
                    throw new Error('unauthorized');
                }
                return response.json();
            })
            .then(function (user) {
                if (!user || !user.name) {
                    clearToken();
                    window.location.href = '/login';
                    return;
                }
                if (nameEl) nameEl.textContent = user.name;
                if (initialEl) initialEl.textContent = user.name.charAt(0).toUpperCase();
                if (nikEl) {
                    nikEl.textContent = user.nik ? maskNik(user.nik) : (user.username || '-');
                    nikEl.setAttribute('data-nik-full', user.nik || '');
                    nikEl.title = user.nik || '';
                }
                if (usernameEl) usernameEl.textContent = user.username || user.nik || '-';
            })
            .catch(function (error) {
                if (error.message !== 'unauthorized') {
                    showError('error-box', 'Failed to verify session. Please sign in again.');
                }
            });

        if (logoutBtn) {
            logoutBtn.addEventListener('click', function () {
                logoutBtn.disabled = true;
                logoutBtn.textContent = 'Logging out...';

                fetch('/api/logout', {
                    method: 'POST',
                    headers: authHeaders()
                })
                    .then(function () {
                        clearToken();
                        window.location.href = '/login';
                    })
                    .catch(function () {
                        clearToken();
                        window.location.href = '/login';
                    });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initLoginPage();
        initDashboardPage();
    });
})();
