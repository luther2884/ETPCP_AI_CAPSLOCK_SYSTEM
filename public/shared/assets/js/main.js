// Clean up any service worker left over from the removed PWA feature.
// Browsers that installed it keep it active (and intercepting fetches)
// even after sw.js was deleted from the server, which can serve stale
// cached/offline responses for pages and API calls that no longer exist.
(function unregisterStaleServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.getRegistrations().then(function (regs) {
        regs.forEach(function (reg) { reg.unregister(); });
    }).catch(function () {});
    if (window.caches && caches.keys) {
        caches.keys().then(function (keys) {
            keys.forEach(function (key) { caches.delete(key); });
        }).catch(function () {});
    }
})();

// Theme System - light-only. Dark mode has been removed entirely; the site
// always runs on the palette's light gradient (see style.css :root). This
// stub keeps window.applyTheme/toggleTheme as harmless no-ops in case any
// older cached page or inline script still calls them, but they can no
// longer switch anything - data-theme stays "light" always.
(function setupThemeSystem() {
    function forceLight() {
        document.documentElement.setAttribute('data-theme', 'light');
        if (document.body) {
            document.body.setAttribute('data-theme', 'light');
        }
        try { localStorage.removeItem('techworld_theme'); } catch (e) {}
    }

    window.applyTheme = forceLight;
    window.toggleTheme = forceLight;

    forceLight();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', forceLight);
    }
})();

// Network status indicator (online/offline)
(function setupNetworkStatusIndicator() {
    function ensureEl() {
        var el = document.getElementById('tw-net-status');
        if (!el) {
            el = document.createElement('div');
            el.id = 'tw-net-status';
            el.className = 'net-status';
            el.innerHTML = '<span class="net-dot"></span><span class="net-text"></span>';
            document.body.appendChild(el);
        }
        return el;
    }
    function show(status) {
        var el = ensureEl();
        var textEl = el.querySelector('.net-text');
        el.classList.remove('online', 'offline');
        el.classList.add(status);
        textEl.textContent = status === 'online' ? 'Back online' : 'You are offline';
        el.style.display = 'inline-flex';
        if (status === 'online') {
            setTimeout(function () { el.style.display = 'none'; }, 2000);
        }
    }
    window.addEventListener('online', function () { show('online'); });
    window.addEventListener('offline', function () { show('offline'); });
    // initial state hint (don’t flash online on load)
    if (!navigator.onLine) { show('offline'); }
})();

// Hands the browser off to the 6-digit code screen without letting the page
// underneath show. See the .auth-handoff block in style.css for why this
// exists: closing the modal and *then* navigating exposed the landing page for
// the whole navigation gap, which read as a one-second flash of a screen
// nobody asked for.
//
// The overlay paints the destination page's own ground, so the cover is a
// cross-fade rather than a curtain: fade up here, navigate while opaque, and
// verify_login_code.php fades its card in on the identical background.
//
// Returns nothing and is deliberately one-way - once called, this page is
// leaving, so there is no caller-facing cancel.
function authHandoff(url, opts) {
    opts = opts || {};
    if (!url) { return; }

    // A second call (double-submitted form, Google callback racing the form)
    // must not stack a second overlay or schedule a second navigation.
    if (window.__authHandoffActive) { return; }
    window.__authHandoffActive = true;

    var reduced = false;
    try { reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

    var el = document.createElement('div');
    el.className = 'auth-handoff';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');

    var card = document.createElement('div');
    card.className = 'auth-handoff__card';

    var mark = document.createElement('div');
    mark.className = 'auth-handoff__mark';
    mark.setAttribute('aria-hidden', 'true');
    mark.textContent = '✉';

    var title = document.createElement('p');
    title.className = 'auth-handoff__title';
    title.textContent = opts.title || 'Verify your login';

    var sub = document.createElement('p');
    sub.className = 'auth-handoff__sub';
    sub.textContent = opts.message || 'We sent a 6‑digit code to';

    card.appendChild(mark);
    card.appendChild(title);
    card.appendChild(sub);

    // textContent, not innerHTML - this string is an address the person typed.
    if (opts.email) {
        var mail = document.createElement('span');
        mail.className = 'auth-handoff__email';
        mail.textContent = opts.email;
        card.appendChild(mail);
    }

    var bar = document.createElement('div');
    bar.className = 'auth-handoff__bar';
    bar.setAttribute('aria-hidden', 'true');
    bar.appendChild(document.createElement('i'));
    card.appendChild(bar);

    el.appendChild(card);
    document.body.appendChild(el);

    // The page beneath must not scroll while it is being covered - a stray
    // wheel event during the fade would move the landing page behind a
    // transparent overlay, which is visible and looks like a glitch.
    var prevOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';

    // Read before write so the browser has an "opacity: 0" frame to animate
    // from; without it the class lands in the same style recalculation and
    // there is no transition at all.
    void el.offsetWidth;
    el.classList.add('is-in');

    // Navigate once the cover is opaque. The fade is 300ms (see style.css) and
    // the extra beat lets the "code sent" line actually be read - it replaces
    // the 1.2s notification the old handler waited out with the modal still up.
    var delay = reduced ? 60 : (typeof opts.delay === 'number' ? opts.delay : 620);
    setTimeout(function () { location.href = url; }, delay);

    // Coming back with the Back button can restore this page from the back/
    // forward cache exactly as it was - overlay included, scroll locked, and
    // no reload to clear either. Tear it down on restore so Back lands on a
    // working login modal instead of a dead blue screen.
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) { return; }
        window.__authHandoffActive = false;
        document.documentElement.style.overflow = prevOverflow;
        if (el.parentNode) { el.parentNode.removeChild(el); }
    });
}
window.authHandoff = authHandoff;

// Auth Modal Functions
function openLoginModal() {
    document.getElementById('loginModal').style.display = 'block';
    // initialize modal view history
    window.__authViewStack = [];
    showLogin();
}

function closeModal() {
    document.getElementById('loginModal').style.display = 'none';
    hideNotification();
}

// Centralized view switching with history stack
const AUTH_VIEWS = ['loginForm', 'registerForm', 'forgotForm', 'forgotSent', 'resetVerify', 'resetForm', 'verifyForm', 'registerVerify'];
function showAuthView(id, push = true) {
    AUTH_VIEWS.forEach(v => {
        const el = document.getElementById(v);
        if (el) {
            const isTarget = (v === id);
            el.style.display = isTarget ? 'block' : 'none';
            el.classList.toggle('active', isTarget);
        }
    });
    if (push) {
        if (!Array.isArray(window.__authViewStack)) window.__authViewStack = [];
        const last = window.__authViewStack[window.__authViewStack.length - 1];
        if (last !== id) { window.__authViewStack.push(id); }
    }
}
function modalBack() {
    if (!Array.isArray(window.__authViewStack) || window.__authViewStack.length <= 1) { showAuthView('loginForm', true); return; }
    // pop current, show previous without pushing
    window.__authViewStack.pop();
    const prev = window.__authViewStack[window.__authViewStack.length - 1] || 'loginForm';
    showAuthView(prev, false);
}

function showLogin() {
    showAuthView('loginForm');
    hideNotification();
}

function showRegister() {
    showAuthView('registerForm');
    hideNotification();
}

// Show Forgot Password within the same modal
function showForgot() {
    showAuthView('forgotForm');
    hideNotification();
}

// In-modal Forgot Password flow wiring (AJAX)
document.addEventListener('DOMContentLoaded', function () {
    const forgotForm = document.getElementById('forgotFormElement');
    if (forgotForm) {
        forgotForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(forgotForm);
            const btn = forgotForm.querySelector('button[type="submit"]');
            const actionUrl = forgotForm.getAttribute('action') || (typeof forgotForm.action === 'string' ? forgotForm.action : '');
            fetch(actionUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(() => {
                    // Directly show 6-box code entry
                    const email = (fd.get('email') || '').toString();
                    showAuthView('resetVerify');
                    const lbl = document.getElementById('resetVerifyEmail'); if (lbl) lbl.textContent = email;
                    const firstOtp = document.querySelector('#resetVerify .otp-input'); if (firstOtp) { firstOtp.focus(); firstOtp.select && firstOtp.select(); }
                })
                .catch(() => { showNotification('Could not send code. Please try again.', 'error'); })
                .finally(() => { btn.disabled = false; btn.textContent = 'Send Code'; });
        });
    }

    const enterBtn = document.getElementById('enterCodeBtn');
    if (enterBtn) {
        enterBtn.addEventListener('click', function () {
            // Switch to in-modal reset verify code form
            showAuthView('resetVerify');
            const email = (document.getElementById('forgotSentEmail')?.textContent || '').trim();
            const lbl = document.getElementById('resetVerifyEmail'); if (lbl) lbl.textContent = email;
        });
    }

    const resendLink = document.getElementById('forgotResendLink');
    if (resendLink) {
        resendLink.addEventListener('click', function (e) {
            e.preventDefault();
            const email = (document.getElementById('forgotSentEmail')?.textContent || '').trim();
            if (!email) { showNotification('Missing email to resend.', 'error'); return; }
            const fd = new FormData(); fd.append('email', email); fd.append('csrf_token', (document.querySelector('input[name="csrf_token"]') || {}).value || '');
            fetch(window.WEB_ROOT + 'public/guest/forgot_password.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(() => { showNotification('A new code was sent.', 'success'); })
                .catch(() => { showNotification('Could not resend code.', 'error'); });
        });
    }

    // "Send another code" button on the Enter Verification Code step
    // (#resetVerify) - replaces the old visible "Continue" submit button,
    // which auto-submit (see wireOtp() below) already made redundant. Uses
    // #resetVerifyEmail since that's populated on every path into this step
    // (direct submit from #forgotForm, or via #forgotSent's "Enter code"
    // button) - #forgotSentEmail above is not always set by then.
    const resetVerifyResendBtn = document.getElementById('resetVerifyResendBtn');
    if (resetVerifyResendBtn) {
        resetVerifyResendBtn.addEventListener('click', function () {
            const email = (document.getElementById('resetVerifyEmail')?.textContent || '').trim();
            const statusEl = document.getElementById('resetVerifyResendStatus');
            if (!email) {
                if (statusEl) { statusEl.textContent = 'Missing email to resend.'; statusEl.style.color = '#f87171'; }
                return;
            }
            resetVerifyResendBtn.disabled = true;
            const originalText = resetVerifyResendBtn.textContent;
            resetVerifyResendBtn.textContent = 'Sending...';
            if (statusEl) { statusEl.textContent = ''; }
            const fd = new FormData();
            fd.append('email', email);
            fd.append('csrf_token', (document.querySelector('#resetVerifyForm input[name="csrf_token"]') || {}).value || '');
            fetch(window.WEB_ROOT + 'public/guest/forgot_password.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(() => {
                    if (statusEl) { statusEl.textContent = 'A new code was sent.'; statusEl.style.color = '#34d399'; }
                    // Clear the OTP boxes so a stale/previous code isn't left
                    // sitting there confusing the auto-submit check.
                    const inputs = document.querySelectorAll('#resetVerify .otp-input');
                    inputs.forEach(i => i.value = '');
                    const hidden = document.getElementById('resetOtpHidden');
                    if (hidden) hidden.value = '';
                    if (inputs[0]) inputs[0].focus();
                })
                .catch(() => {
                    if (statusEl) { statusEl.textContent = 'Could not resend code.'; statusEl.style.color = '#f87171'; }
                })
                .finally(() => {
                    resetVerifyResendBtn.disabled = false;
                    resetVerifyResendBtn.textContent = originalText;
                });
        });
    }

    // Generic OTP wiring for modal forms (login verify + reset verify + register verify)
    function wireOtp(containerSelector, hiddenSelector) {
        const container = document.querySelector(containerSelector);
        const hidden = document.querySelector(hiddenSelector);
        if (!container || !hidden) return;
        if (container.dataset.otpWired === 'true') return;
        container.dataset.otpWired = 'true';

        const inputs = Array.prototype.slice.call(container.querySelectorAll('.otp-input'));
        function setHidden() { hidden.value = inputs.map(i => i.value || '').join(''); }

        inputs.forEach((inp, idx) => {
            inp.addEventListener('input', function () {
                let v = inp.value
                    .replace(/[\u0660-\u0669]/g, d => String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48))
                    .replace(/[\u06F0-\u06F9]/g, d => String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48))
                    .replace(/[^0-9]/g, '');
                inp.value = v.slice(0, 1);
                if (v && idx < inputs.length - 1) {
                    inputs[idx + 1].focus();
                    if (inputs[idx + 1].select) inputs[idx + 1].select();
                }
                setHidden();

                // Auto-submit when all 6 digits are entered
                const allFilled = inputs.every(input => input.value.length === 1);
                if (allFilled) {
                    const formToSubmit = container.querySelector('form');
                    if (formToSubmit && formToSubmit.dataset.submitting !== 'true') {
                        setTimeout(() => {
                            if (formToSubmit.dataset.submitting === 'true') return;
                            if (typeof formToSubmit.requestSubmit === 'function') {
                                formToSubmit.requestSubmit();
                            } else {
                                formToSubmit.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                            }
                        }, 80);
                    }
                }
            });

            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !inp.value && idx > 0) {
                    inputs[idx - 1].focus();
                    if (inputs[idx - 1].select) inputs[idx - 1].select();
                }
                if (e.key === 'ArrowLeft' && idx > 0) { e.preventDefault(); inputs[idx - 1].focus(); }
                if (e.key === 'ArrowRight' && idx < inputs.length - 1) { e.preventDefault(); inputs[idx + 1].focus(); }
            });

            inp.addEventListener('paste', function (e) {
                const txt = (e.clipboardData || window.clipboardData).getData('text') || ''; if (!txt) return; e.preventDefault();
                const digits = txt
                    .replace(/[\u0660-\u0669]/g, d => String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48))
                    .replace(/[\u06F0-\u06F9]/g, d => String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48))
                    .replace(/[^0-9]/g, '')
                    .slice(0, 6).split('');
                for (let i = 0; i < inputs.length; i++) { inputs[i].value = digits[i] || ''; }
                setHidden();
                const next = digits.length >= 6 ? inputs[inputs.length - 1] : inputs[digits.length] || inputs[inputs.length - 1];
                if (next) { next.focus(); if (next.select) next.select(); }

                // Auto-submit if 6 digits were pasted
                if (digits.length === 6) {
                    const formToSubmit = container.querySelector('form');
                    if (formToSubmit && formToSubmit.dataset.submitting !== 'true') {
                        setTimeout(() => {
                            if (formToSubmit.dataset.submitting === 'true') return;
                            if (typeof formToSubmit.requestSubmit === 'function') {
                                formToSubmit.requestSubmit();
                            } else {
                                formToSubmit.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                            }
                        }, 80);
                    }
                }
            });
        });
    }

    window.wireOtp = wireOtp;

    wireOtp('#resetVerify', '#resetOtpHidden');
    wireOtp('#verifyForm', '#loginOtpHidden');
    wireOtp('#registerVerify', '#registerOtpHidden');

    // Submit register verification OTP code via AJAX
    const registerVerifyForm = document.getElementById('registerVerifyForm');
    if (registerVerifyForm) {
        registerVerifyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (registerVerifyForm.dataset.submitting === 'true') return;
            registerVerifyForm.dataset.submitting = 'true';

            const setHidden = () => {
                const inputs = Array.prototype.slice.call(registerVerifyForm.querySelectorAll('.otp-input'));
                const hidden = document.getElementById('registerOtpHidden');
                if (hidden) { hidden.value = inputs.map(i => i.value || '').join('').replace(/[^0-9]/g, ''); }
            };
            setHidden();
            const fd = new FormData(registerVerifyForm);
            const btn = registerVerifyForm.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.textContent = 'Verifying...'; }

            const actionUrl = registerVerifyForm.getAttribute('action') || (typeof registerVerifyForm.action === 'string' ? registerVerifyForm.action : '');
            fetch(actionUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.text())
                .then(txt => {
                    let d;
                    try { d = JSON.parse(txt); } catch (e) { console.error('Register verify response invalid JSON:', txt); }
                    if (d && d.success) {
                        showNotification(d.message || 'Email verified! Logging in...', 'success');
                        setTimeout(() => {
                            if (typeof closeModal === 'function') closeModal();
                            location.href = d.redirect || ((window.WEB_ROOT || '/') + 'public/authenticated/customer/index.php');
                        }, 800);
                    } else {
                        showNotification((d && d.message) || 'Invalid verification code.', 'error');
                        const inputs = registerVerifyForm.querySelectorAll('.otp-input');
                        inputs.forEach(i => i.value = '');
                        const hidden = document.getElementById('registerOtpHidden');
                        if (hidden) hidden.value = '';
                        if (inputs[0]) { inputs[0].focus(); }
                    }
                })
                .catch(err => {
                    console.error('Register verify catch error:', err);
                    showNotification('An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    registerVerifyForm.dataset.submitting = 'false';
                    if (btn) { btn.disabled = false; btn.textContent = 'Verify Code & Log In'; }
                });
        });
    }

    // Resend registration OTP code via AJAX
    const registerResendLink = document.getElementById('registerResendLink');
    if (registerResendLink) {
        registerResendLink.addEventListener('click', function (e) {
            e.preventDefault();
            const statusEl = document.getElementById('regResendStatus');
            if (statusEl) { statusEl.textContent = 'Sending new code...'; statusEl.style.color = '#93c5fd'; }
            const csrf = (document.querySelector('#registerVerifyForm input[name="csrf_token"]') || document.querySelector('input[name="csrf_token"]') || {}).value || '';
            const fd = new FormData();
            fd.append('action', 'resend_otp');
            if (csrf) fd.append('csrf_token', csrf);

            fetch(window.WEB_ROOT + 'public/authenticated/auth/register.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.success) {
                        if (statusEl) { statusEl.textContent = d.message || 'A new code was sent.'; statusEl.style.color = '#34d399'; }
                    } else {
                        if (statusEl) { statusEl.textContent = (d && d.message) || 'Could not resend code.'; statusEl.style.color = '#f87171'; }
                    }
                })
                .catch(() => {
                    if (statusEl) { statusEl.textContent = 'Network error. Please try again.'; statusEl.style.color = '#f87171'; }
                });
        });
    }

    // Submit reset verification code via AJAX
    const resetVerifyForm = document.getElementById('resetVerifyForm');
    if (resetVerifyForm) {
        resetVerifyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            // Ensure hidden code is synced
            const setHidden = () => {
                const inputs = Array.prototype.slice.call(resetVerifyForm.querySelectorAll('.otp-input'));
                const hidden = document.getElementById('resetOtpHidden');
                if (hidden) { hidden.value = inputs.map(i => i.value || '').join(''); }
            };
            setHidden();
            const fd = new FormData(resetVerifyForm);
            const btn = resetVerifyForm.querySelector('button[type="submit"]');
            btn.disabled = true; btn.textContent = 'Checking...';
            const actionUrl = resetVerifyForm.getAttribute('action') || (typeof resetVerifyForm.action === 'string' ? resetVerifyForm.action : '');
            fetch(actionUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.success) {
                        // Proceed to enter new password inside modal
                        showAuthView('resetForm');
                    } else {
                        showNotification((d && d.message) || 'Invalid or expired code.', 'error');
                        showAuthView('resetVerify', false);
                        const inputs = resetVerifyForm.querySelectorAll('.otp-input');
                        inputs.forEach(i => i.value = '');
                        const hidden = document.getElementById('resetOtpHidden');
                        if (hidden) hidden.value = '';
                        if (inputs[0]) { inputs[0].focus(); }
                    }
                })
                .catch(() => {
                    showNotification('An error occurred. Please try again.', 'error');
                    showAuthView('resetVerify', false);
                })
                .finally(() => { btn.disabled = false; btn.textContent = 'Continue'; });
        });
    }
    // Submit new password via AJAX; on success redirect to reset_success
    const resetFormEl = document.getElementById('resetFormElement');
    if (resetFormEl) {
        resetFormEl.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(resetFormEl);
            const actionUrl = resetFormEl.getAttribute('action') || (typeof resetFormEl.action === 'string' ? resetFormEl.action : '');
            fetch(actionUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.redirected ? (window.location.href = r.url, null) : r.text())
                .then(txt => { if (!txt) return; try { const d = JSON.parse(txt); if (d.success && d.redirect) { location.href = d.redirect; } } catch (e) { location.href = window.WEB_ROOT + 'public/guest/reset_success.php'; } })
                .catch(() => showNotification('Could not update password.', 'error'))
                .finally(() => { btn.disabled = false; btn.textContent = 'Update Password'; });
        });
    }
});

function togglePassword(inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button.querySelector("i");
    if (!input) return;

    // Determine new state (show/hide)
    const willShow = input.type === 'password';
    input.type = willShow ? 'text' : 'password';
    if (icon) { icon.className = willShow ? 'fi fi-rr-eye-crossed' : 'fi fi-rr-eye'; }
    button.title = willShow ? 'Hide password' : 'Show password';

    // If a pair is defined, mirror the same state on the paired input and sync its icon
    const pairId = button.getAttribute('data-pair');
    if (pairId) {
        const pairInput = document.getElementById(pairId);
        if (pairInput) { pairInput.type = willShow ? 'text' : 'password'; }
        const buddy = document.querySelector(`.password-toggle[data-pair="${inputId}"]`);
        if (buddy) {
            const buddyIcon = buddy.querySelector('i');
            if (buddyIcon) { buddyIcon.className = willShow ? 'fi fi-rr-eye-crossed' : 'fi fi-rr-eye'; }
            buddy.title = willShow ? 'Hide password' : 'Show password';
        }
    }
}

// Live password requirements checklist (register forms)
var COMMON_PASSWORDS = [
    'password', 'password1', 'password123', '12345678', '123456789', '1234567890',
    'qwerty123', 'qwertyuiop', 'letmein123', 'iloveyou1', 'admin1234', 'welcome123',
    'abc123456', 'football1', '111111111', '123123123', 'qazwsx123', '1q2w3e4r5t',
    'trustno11', 'monkey123', 'dragon123', 'master1234', 'passw0rd1', 'sunshine1',
    'princess1', 'superman1', 'starwars1', 'whatever1', 'freedom123'
];

function isCommonPassword(pw) {
    var lower = pw.toLowerCase();
    if (COMMON_PASSWORDS.indexOf(lower) !== -1) return true;
    if (/^(.)\1+$/.test(pw)) return true; // all one repeated character
    if (/(01234567|12345678|23456789|abcdefgh|qwertyui)/i.test(pw)) return true; // keyboard/sequential runs
    if (lower.indexOf('password') !== -1 || lower.indexOf('qwerty') !== -1) return true;
    return false;
}

function passwordContainsEmail(pw, email) {
    if (!pw || !email) return false;
    var lowerPw = pw.toLowerCase();
    var lowerEmail = email.toLowerCase().trim();
    var local = lowerEmail.split('@')[0];
    if (local && local.length >= 3 && lowerPw.indexOf(local) !== -1) return true;
    if (lowerEmail && lowerPw.indexOf(lowerEmail) !== -1) return true;
    return false;
}

function evaluatePasswordRules(pw, email) {
    return {
        length: pw.length >= 8,
        case: /[a-z]/.test(pw) && /[A-Z]/.test(pw),
        charset: /[0-9]/.test(pw) || /[^A-Za-z0-9]/.test(pw),
        noemail: pw.length > 0 && !passwordContainsEmail(pw, email),
        unique: pw.length > 0 && !isCommonPassword(pw)
    };
}

function passwordMeetsAllRules(pw, email) {
    var r = evaluatePasswordRules(pw, email);
    return r.length && r.case && r.charset && r.noemail && r.unique;
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-password-rules]').forEach(function (rulesEl) {
        var form = rulesEl.closest('form');
        if (!form) return;
        var pwInput = form.querySelector('input[name="password"]');
        var emailInput = form.querySelector('input[name="email"]');
        if (!pwInput) return;

        function refresh() {
            var pw = pwInput.value;
            var results = evaluatePasswordRules(pw, emailInput ? emailInput.value : '');
            Object.keys(results).forEach(function (rule) {
                var li = rulesEl.querySelector('[data-rule="' + rule + '"]');
                if (li) { li.classList.toggle('valid', !!results[rule]); }
            });

            // Reflect overall pass/fail on the password field itself (green once all
            // rules are met, red while any are still unmet; neutral while empty)
            pwInput.classList.remove('pw-valid', 'pw-invalid');
            if (pw.length > 0) {
                var allValid = results.length && results.case && results.charset && results.noemail && results.unique;
                pwInput.classList.add(allValid ? 'pw-valid' : 'pw-invalid');
            }
        }

        pwInput.addEventListener('input', refresh);
        if (emailInput) { emailInput.addEventListener('input', refresh); }
        refresh();
    });
});

// Floating Toast Notification System
function createToast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;

    let iconClass = 'fi-rr-info';
    let iconColor = '#00f5ff';
    if (type === 'success') { iconClass = 'fi-rr-check-circle'; iconColor = '#10b981'; }
    else if (type === 'error') { iconClass = 'fi-rr-cross-circle'; iconColor = '#ef4444'; }
    else if (type === 'warning') { iconClass = 'fi-rr-exclamation'; iconColor = '#f59e0b'; }

    toast.innerHTML = `
        <i class="fi ${iconClass} toast-icon" style="color:${iconColor}; font-size: 18px; flex-shrink: 0;"></i>
        <div class="toast-message" style="flex: 1; line-height: 1.4;">${message}</div>
        <button type="button" class="toast-close" aria-label="Close">&times;</button>
    `;

    const closeBtn = toast.querySelector('.toast-close');
    const dismiss = () => {
        if (toast.classList.contains('toast-leaving')) return;
        toast.classList.add('toast-leaving');
        setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 260);
    };

    closeBtn.addEventListener('click', dismiss);
    container.appendChild(toast);

    // Auto-dismiss after 4.5 seconds
    setTimeout(dismiss, 4500);
}

function showNotification(message, type) {
    // If inside open modal, show inside modal error element if visible.
    // Must check the *computed* display, not modal.style.display - the modal
    // is hidden by the .modal{display:none} CSS rule, not an inline style,
    // so modal.style.display stays '' (not 'none') until closeModal() has
    // actually run once. Checking the inline property made this branch
    // think the modal was open on every fresh page load, silently writing
    // the message into the still-hidden modal instead of showing a toast.
    const modal = document.getElementById('loginModal');
    if (modal && getComputedStyle(modal).display !== 'none') {
        const modalNotif = modal.querySelector('.notification');
        if (modalNotif) {
            modalNotif.textContent = message;
            modalNotif.className = `notification ${type}`;
            modalNotif.style.display = 'block';
            return;
        }
    }
    createToast(message, type);
}

function hideNotification() {
    const notification = document.getElementById('notification');
    if (notification) {
        notification.style.display = 'none';
    }
}

// Profile Dropdown Functions
function toggleProfile() {
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) {
        dropdown.classList.toggle('active');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function (event) {
    const profileSection = document.querySelector('.profile-section');
    const dropdown = document.getElementById('profileDropdown');

    if (profileSection && dropdown && !profileSection.contains(event.target)) {
        dropdown.classList.remove('active');
    }
});

// Outside clicks do not close the auth modal (user must use modal buttons)
window.onclick = function (event) {
    // Reserved for dropdowns / notifications if needed
};

// Cart Functions
//
// THIS IS THE FALLBACK, NOT THE OVERRIDE. It is installed below only when no
// richer addToCart() already exists, and the reason is load order: the customer
// pages pull this file in with `defer` (includes/header.php), so it executes
// AFTER the whole document is parsed - after includes/footer.php's inline
// block and after the pages that declare their own addToCart() further down.
// A plain `function addToCart(...)` here therefore rebound the global LAST and
// silently replaced every one of them. What that cost: the callback argument
// (Buy Now passes the redirect to checkout through it, so Buy Now just added
// to the cart and sat there), marking the item selected for the cart page, and
// the add_to_cart.php server fallback when the AJAX call fails.
//
// The callback is honoured here too, so a caller that passes one behaves the
// same whichever implementation ends up live.
function twDefaultAddToCart(productId, quantity = 1, callback = null) {
    fetch(window.WEB_ROOT + 'public/authenticated/api/cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            action: 'add',
            product_id: productId,
            quantity: quantity
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateCartCount(data.cart_count);
                showNotification(data.message || 'Product added to cart!', 'success');
                // Only after the item is really in the cart - a Buy Now that
                // redirected on failure would land on an empty checkout.
                if (typeof callback === 'function') { callback(); }
            } else {
                showNotification(data.message || 'Failed to add to cart', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred', 'error');
        });
}

if (typeof window.addToCart !== 'function') {
    window.addToCart = twDefaultAddToCart;
}

function updateCartCount(count = null) {
    if (count === null) {
        // Fetch current count
        fetch(window.WEB_ROOT + 'public/authenticated/api/cart.php?action=count')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateCartDisplay(data.count);
                }
            })
            .catch(error => console.error('Error fetching cart count:', error));
    } else {
        updateCartDisplay(count);
    }
}

function updateCartDisplay(count) {
    const cartCountElements = document.querySelectorAll('.cart-count, #cartCount');
    cartCountElements.forEach(element => {
        element.textContent = count;
        if (count > 0) {
            element.style.display = 'flex';
        } else {
            element.style.display = 'none';
        }
    });

    const floatingCartBtn = document.getElementById('floatingCartBtn');
    if (floatingCartBtn) {
        // The button itself is permanent - emptying the cart no longer removes
        // the only way back to the cart page. Only the badge and the pop react
        // to the count.
        floatingCartBtn.classList.add('show');
        if (count > 0) {
            floatingCartBtn.classList.remove('pop');
            void floatingCartBtn.offsetWidth; // restart animation
            floatingCartBtn.classList.add('pop');
        } else {
            floatingCartBtn.classList.remove('pop');
        }
    }
}

// Search functionality is now handled by the form action attribute
// No JavaScript interception needed - let the form submit naturally

document.addEventListener('DOMContentLoaded', function () {
    // Initialize cart count
    updateCartCount();

    // Handle notification parameters
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('logout') === 'success') {
        showBannerNotification('You have been logged out successfully.', 'success');
    }
    // Suppress legacy login banner; handled by customer footer modal now
    if (urlParams.get('register') === 'success') {
        showBannerNotification('Welcome to TechWorld! Your account has been created successfully.', 'success');
    }

    // Auto-open auth modal if requested
    const modalParam = urlParams.get('modal');
    if (modalParam === 'login' && typeof openLoginModal === 'function') {
        openLoginModal();
        if (typeof showLogin === 'function') { showLogin(); }
    }
    if (modalParam === 'register' && typeof openLoginModal === 'function') {
        openLoginModal();
        if (typeof showRegister === 'function') { showRegister(); }
    }
});

// Toast banner notification function
function showBannerNotification(message, type) {
    createToast(message, type);
}

// Utility Functions
function formatCurrency(amount) {
    return '₱' + new Intl.NumberFormat('en-PH').format(amount);
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Product image error handling
document.addEventListener('DOMContentLoaded', function () {
    const images = document.querySelectorAll('.product-image img, .category-image img');
    images.forEach(img => {
        img.addEventListener('error', function () {
            const placeholder = document.createElement('div');
            placeholder.className = 'image-placeholder';
            placeholder.textContent = 'IMG';
            this.parentNode.replaceChild(placeholder, this);
        });
    });
});

// Scroll to top functionality
function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// Add scroll to top button
document.addEventListener('DOMContentLoaded', function () {
    const scrollBtn = document.createElement('button');
    scrollBtn.innerHTML = '↑';
    scrollBtn.className = 'scroll-to-top';
    scrollBtn.onclick = scrollToTop;
    // Colours come straight from the site palette tokens (style.css :root)
    // so this always matches the rest of the UI - mauve accent -> deep blue,
    // same gradient the primary buttons use.
    scrollBtn.style.cssText = `
        position: fixed;
        bottom: 96px;
        right: 25px;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(45deg, var(--accent-cyan, #a05f80), var(--accent-blue, #174871));
        border: none;
        color: #fff;
        font-size: 20px;
        cursor: pointer;
        display: none;
        z-index: 1000;
        box-shadow: 0 8px 22px rgba(23, 72, 113, 0.32);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    `;
    scrollBtn.addEventListener('mouseenter', function () {
        scrollBtn.style.transform = 'translateY(-3px)';
        scrollBtn.style.boxShadow = '0 12px 28px rgba(23, 72, 113, 0.45)';
    });
    scrollBtn.addEventListener('mouseleave', function () {
        scrollBtn.style.transform = '';
        scrollBtn.style.boxShadow = '0 8px 22px rgba(23, 72, 113, 0.32)';
    });

    document.body.appendChild(scrollBtn);

    window.addEventListener('scroll', function () {
        if (window.pageYOffset > 300) {
            scrollBtn.style.display = 'block';
        } else {
            scrollBtn.style.display = 'none';
        }
    });
});

