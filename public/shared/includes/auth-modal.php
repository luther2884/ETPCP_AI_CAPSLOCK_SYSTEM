<?php
// WEB_ROOT will be defined by config/database.php.
//
// "Continue with Google" only renders when a Google OAuth client id is
// configured (GOOGLE_CLIENT_ID in .env). Without one the Google Identity
// Services button cannot be created at all, so the block is left out
// entirely rather than shipping a button that can only fail.
$googleClientId = trim((string)(getenv('GOOGLE_CLIENT_ID') ?: ''));
?>
<!-- Login/Register Modal -->
<div id="loginModal" class="modal" aria-hidden="true" role="dialog">
    <div class="modal-content no-scroll">
    <div id="authModalVanta" class="auth-modal-vanta" aria-hidden="true"></div>
    <button class="close" type="button" aria-label="Close" onclick="closeModal()">&times;</button>

    <!-- Notification Area -->
    <div id="notification" class="notification" style="display:none;"></div>

    <div class="auth-container">
      <!-- Login Form -->
      <div id="loginForm" class="auth-form">
        <h2>Login</h2>
        <p>Welcome back! Sign in to your account</p>

        <form id="loginFormElement" action="<?= defined('AUTH_LOGIN_URL') ? AUTH_LOGIN_URL : WEB_ROOT . 'public/guest/login.php' ?>" method="POST">
          <?= csrf_field() ?>
          <div class="form-field">
            <input type="email" id="loginEmail" name="email" placeholder=" " required>
            <label for="loginEmail">Email Address</label>
          </div>
          <div class="form-field password-field">
            <input type="password" id="loginPassword" name="password" placeholder=" " required>
            <label for="loginPassword">Password</label>
            <button type="button" class="password-toggle" onclick="togglePassword('loginPassword', this)"><i class="fi fi-rr-eye"></i></button>
          </div>
          <button type="submit" class="auth-btn">Login</button>
        </form>

<?php if ($googleClientId !== ''): ?>
        <div class="google-auth-block">
          <div class="google-auth-divider"><span>or</span></div>
          <div class="google-auth-btn tw-google-btn"></div>
        </div>
<?php endif; ?>

        <p class="auth-link"><a class="forgotpass" href="#" onclick="showForgot()">Forgot your password?</a></p>
        <p class="auth-link">New customer? <a href="#" onclick="showRegister()">Create your account</a></p>
      </div>

    <!-- Forgot Password Form -->
    <div id="forgotForm" class="auth-form" style="display:none;">
      <h2>Forgot Password</h2>
      <p>Enter your email to receive a verification code</p>
      <form id="forgotFormElement" action="<?= defined('AUTH_FORGOT_URL') ? AUTH_FORGOT_URL : WEB_ROOT . 'public/guest/forgot_password.php' ?>" method="POST">
        <?= csrf_field() ?>
        <input type="email" name="email" placeholder="Email Address" required autocomplete="email">
        <button type="submit" class="auth-btn">Send Code</button>
      </form>
      <p class="auth-link"><a href="#" onclick="modalBack();return false;">Back</a></p>
    </div>
      <!-- Forgot Password: Link Sent view (in-modal) -->
      <div id="forgotSent" class="auth-form" style="display:none;text-align:center;">
        <div style="width:64px;height:64px;border-radius:50%;margin:0 auto 14px;background:linear-gradient(135deg,var(--accent-cyan),var(--accent-blue));display:flex;align-items:center;justify-content:center;color:#0b1220;font-size:28px;">✉</div>
        <h2 style="margin-bottom:8px;">Check your email</h2>
        <p style="opacity:.9;margin-bottom:4px;">We sent a 6‑digit verification code to</p>
        <p id="forgotSentEmail" style="font-weight:600;color:var(--accent-cyan);margin-bottom:14px;text-decoration:none;word-break:break-all;display:block;"></p>
        <button id="enterCodeBtn" type="button" class="auth-btn" style="display:block;margin:12px auto 8px;">Enter code</button>
        <p class="auth-link" style="margin:6px 0 0;"><a href="#" id="forgotResendLink">Resend code</a></p>
        <p class="auth-link" style="margin:6px 0 0;"><a href="#" onclick="showLogin()">Back to login</a></p>
      </div>

      <!-- Password Reset: Enter Code (in-modal) -->
      <div id="resetVerify" class="auth-form" style="display:none; text-align:center;">
        <h2 style="margin-bottom:8px;">Enter verification code</h2>
        <p style="opacity:.9;margin-bottom:4px;">We sent a 6‑digit code to</p>
        <p id="resetVerifyEmail" style="font-weight:600;color:var(--accent-cyan);margin-bottom:14px;"></p>
        <form id="resetVerifyForm" action="<?= defined('AUTH_VERIFY_CODE_URL') ? AUTH_VERIFY_CODE_URL : WEB_ROOT . 'public/guest/verify_code.php' ?>" method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="code" id="resetOtpHidden">
          <div class="otp-group" aria-label="6 digit verification code" role="group" style="display:flex;gap:10px;justify-content:center;margin:8px 0 2px">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 1" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 2" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 3" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 4" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 5" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 6" maxlength="1" pattern="\d">
          </div>
          <!-- No visible submit button - entering all 6 digits auto-submits
               this form (see wireOtp() in main.js), which on success
               advances straight to the "Set New Password" step. Kept in the
               DOM (just hidden) rather than removed so that auto-submit
               logic - and its "Checking..."/disabled-state handling, which
               looks up this exact button - keeps working unchanged. -->
          <button type="submit" class="auth-btn" style="display:none;">Continue</button>
        </form>
        <button type="button" id="resetVerifyResendBtn" class="auth-btn" style="margin-top:14px;width:100%;">Send another code</button>
        <p id="resetVerifyResendStatus" style="margin:8px 0 0;opacity:.9;font-size:13px;"></p>
        <p class="auth-link" style="margin:6px 0 0;"><a href="#" onclick="modalBack();return false;">Back</a></p>
      </div>

      <!-- Password Reset: Set New Password (in-modal) -->
      <div id="resetForm" class="auth-form" style="display:none;">
        <h2>Reset Password</h2>
        <p>Set a new password for your account</p>
        <form id="resetFormElement" action="<?= defined('AUTH_RESET_PASS_URL') ? AUTH_RESET_PASS_URL : WEB_ROOT . 'public/guest/reset_password.php' ?>" method="POST">
          <?= csrf_field() ?>
          <div class="password-field">
            <input type="password" id="pw_modal" name="password" placeholder="New Password" required autocomplete="new-password">
            <button type="button" class="password-toggle" data-pair="cpw_modal" onclick="togglePassword('pw_modal', this)"><i class="fi fi-rr-eye"></i></button>
          </div>
          <div class="password-field">
            <input type="password" id="cpw_modal" name="confirm_password" placeholder="Confirm Password" required autocomplete="new-password">
            <button type="button" class="password-toggle" data-pair="pw_modal" onclick="togglePassword('cpw_modal', this)"><i class="fi fi-rr-eye"></i></button>
          </div>
          <button type="submit" class="auth-btn">Update Password</button>
        </form>
        <p class="auth-link" style="margin:10px 0 0;">
          <a href="<?= WEB_ROOT ?>public/guest/reset_password.php?skip=1">Skip for now</a>
        </p>
        <p class="auth-link" style="margin:6px 0 0;"><a href="#" onclick="modalBack();return false;">Back</a></p>
      </div>

      <!-- Verify Code (shown after login when required) -->
      <div id="verifyForm" class="auth-form" style="display:none; text-align:center;">
        <h2 style="margin-bottom:8px;">Verify your login</h2>
        <p id="verifyEmailText" style="opacity:.9;margin-bottom:14px;"></p>
        <form id="verifyFormElement" action="<?= defined('AUTH_VERIFY_LOGIN_URL') ? AUTH_VERIFY_LOGIN_URL : WEB_ROOT . 'public/guest/verify_login_code.php' ?>" method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="code" id="loginOtpHidden">
          <div class="otp-group" aria-label="6 digit verification code" role="group" style="display:flex;gap:10px;justify-content:center;margin:8px 0 2px">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 1" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 2" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 3" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 4" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 5" maxlength="1" pattern="\d">
            <input class="otp-input" inputmode="numeric" aria-label="Digit 6" maxlength="1" pattern="\d">
          </div>
          <button type="submit" class="auth-btn" style="margin-top:14px;width:100%;">Verify</button>
        </form>
        <p id="resendStatus" style="margin:6px 0 0;opacity:.9;"></p>
        <p class="auth-link" style="margin:6px 0 0;"><a href="#" id="resendCodeLink">Resend code</a></p>
        <p class="auth-link" style="margin:6px 0 0;"><a href="#" onclick="modalBack();return false;">Back</a></p>
      </div>
      <!-- Register Form -->
      <div id="registerForm" class="auth-form">
        <h2>Create Account</h2>
        <p>Join us today! Create your new account</p>

        <form id="registerFormElement" action="<?= defined('AUTH_REGISTER_URL') ? AUTH_REGISTER_URL : WEB_ROOT . 'public/authenticated/auth/register.php' ?>" method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="role" value="customer">

          <div class="form-field">
            <input type="text" id="registerFirstname" name="firstname" placeholder=" " required>
            <label for="registerFirstname">First Name</label>
          </div>
          <div class="form-field">
            <input type="text" id="registerLastname" name="lastname" placeholder=" " required>
            <label for="registerLastname">Last Name</label>
          </div>
          <div class="form-field">
            <input type="email" id="registerEmail" name="email" placeholder=" " required>
            <label for="registerEmail">Email Address</label>
          </div>

          <div class="form-field password-field">
            <input type="password" id="registerPassword" name="password" placeholder=" " required>
            <label for="registerPassword">Password</label>
            <button type="button" class="password-toggle" onclick="togglePassword('registerPassword', this)"><i class="fi fi-rr-eye"></i></button>
          </div>

          <ul class="password-rules" data-password-rules data-password-target="registerPassword" data-email-target="registerEmail">
            <li data-rule="length">At least 8 characters</li>
            <li data-rule="case">Upper &amp; lowercase letters</li>
            <li data-rule="charset">At least one number or symbol</li>
            <li data-rule="noemail">Doesn't contain your email</li>
            <li data-rule="unique">Not a common or easily guessed password</li>
          </ul>

          <div class="form-field password-field">
            <input type="password" id="confirmPassword" name="confirm_password" placeholder=" " required>
            <label for="confirmPassword">Confirm Password</label>
            <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', this)"><i class="fi fi-rr-eye"></i></button>
          </div>

          <button type="submit" class="auth-btn" id="registerBtn">Create Account</button>
        </form>

<?php if ($googleClientId !== ''): ?>
        <div class="google-auth-block">
          <div class="google-auth-divider"><span>or</span></div>
          <div class="google-auth-btn tw-google-btn"></div>
        </div>
<?php endif; ?>

        <p class="auth-link">Already have an account? <a href="#" onclick="showLogin()">Sign in here</a></p>
      </div>

      <!-- Register Verify Code (shown after submitting registration) -->
      <div id="registerVerify" class="auth-form" style="display:none; text-align:center;">
        <h2 style="margin-bottom:8px;">Verify Your Email</h2>
        <p style="opacity:.9;margin-bottom:6px;">We sent a 6‑digit verification code to</p>
        <div style="display:inline-flex;align-items:center;justify-content:center;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
          <span id="registerVerifyEmail" style="font-weight:600;color:var(--accent-cyan);word-break:break-all;font-size:15px;"></span>
          <button type="button" onclick="showRegister();" style="background:var(--bg-card-hover);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:12px;padding:3px 10px;border-radius:6px;transition:0.2s;" title="Change email address">
            <i class="fi fi-rr-edit" style="margin-right:4px;"></i>Change Email
          </button>
        </div>

        <form id="registerVerifyForm" action="<?= defined('AUTH_REGISTER_URL') ? AUTH_REGISTER_URL : WEB_ROOT . 'public/authenticated/auth/register.php' ?>" method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="verify_otp">
          <input type="hidden" name="code" id="registerOtpHidden">
          <div class="otp-group" aria-label="6 digit verification code" role="group" style="display:flex;gap:10px;justify-content:center;margin:8px 0 16px">
            <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 1" maxlength="1" pattern="\d">
            <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 2" maxlength="1" pattern="\d">
            <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 3" maxlength="1" pattern="\d">
            <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 4" maxlength="1" pattern="\d">
            <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 5" maxlength="1" pattern="\d">
            <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 6" maxlength="1" pattern="\d">
          </div>
          <button type="submit" class="auth-btn" style="margin-top:14px;width:100%;">Verify Code & Log In</button>
        </form>
        <p id="regResendStatus" style="margin:6px 0 0;opacity:.9;"></p>
        <p class="auth-link" style="margin:8px 0 0;"><a href="#" id="registerResendLink">Resend code</a></p>
        <p class="auth-link" style="margin:6px 0 0;"><a href="#" onclick="showRegister();return false;">← Back to registration</a></p>
      </div>
    </div>
  </div>

<style>
/* Animated Vanta.js NET background, behind the auth form content. The form
   panels don't have their own background, so they'd otherwise sit directly
   on the animated mesh - give them a translucent card so text stays
   readable while the net still shows through at the edges. */
.auth-modal-vanta {
  position: absolute;
  inset: 0;
  z-index: 0;
}
.modal-content #notification,
.modal-content .auth-container {
  position: relative;
  z-index: 1;
}
.modal-content .auth-container {
  background: var(--bg-card-alpha-80);
  backdrop-filter: blur(6px);
  border-radius: 20px;
}

/* The close button sits on top of the animated Vanta background, so it needs
   its own visible chip rather than a bare glyph that can get lost against
   the moving mesh - a solid circle keeps it legible in both themes. */
.modal-content .close {
  top: 16px;
  right: 16px;
  width: 34px;
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  color: #fff;
  background: rgba(0, 0, 0, 0.45);
  border-radius: 50%;
}
.modal-content .close:hover {
  background: var(--accent-cyan, #a77693);
  color: #fff;
  transform: scale(1.08);
}

/* "Continue with Google" - the button itself is drawn by Google Identity
   Services (its branding rules don't allow a hand-rolled copy), so these
   rules only centre it and separate it from the form above with a hairline
   "or" rule. */
.google-auth-block {
  margin: 4px 0 18px;
}
.google-auth-divider {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  color: var(--text-secondary, #6b7280);
  font-size: 13px;
  text-transform: uppercase;
  letter-spacing: 1px;
}
.google-auth-divider::before,
.google-auth-divider::after {
  content: "";
  flex: 1;
  height: 1px;
  background: var(--border-color, rgba(0, 0, 0, 0.12));
}
.google-auth-btn {
  display: flex;
  justify-content: center;
  min-height: 44px;
}
/* Fitting the register pane inside the modal.
 *
 * style.css caps `.modal > .modal-content` at `max-height: 90vh` with
 * `overflow: hidden`, so anything taller is silently clipped. The register
 * pane - five fields, the password-rules panel, the submit button and now
 * the Google block - came to roughly 945px of content, which overflows that
 * cap on a ~900px viewport and cut off the Google button.
 *
 * Rather than let the box scroll (a scrollbar breaks out of the 20px radius,
 * and the absolutely positioned Vanta background and close button don't
 * scroll with it), the content is tightened to fit: the numbers below trim
 * about 155px of padding and gaps, which brings the tallest pane in under
 * the cap with room to spare while leaving every field above the 44px
 * minimum touch target.
 *
 * All of it is scoped to #loginModal so the standalone auth pages that reuse
 * .auth-form keep the roomier original spacing. The ID also clears the
 * `body:has(.tw-home)` theme rules in techworld-anim.css, which only set
 * colours - no geometry is being fought here.
 */
#loginModal .auth-container {
  padding: 30px 34px;
}
#loginModal .auth-form h2 {
  font-size: 26px;
  margin-bottom: 10px;
}
#loginModal .auth-form p {
  margin-bottom: 20px;
}
#loginModal .auth-form .form-field {
  margin-top: 12px;
}
#loginModal .auth-form .form-field input,
#loginModal .auth-form .password-field input {
  padding: 14px 18px;
}
#loginModal .auth-btn {
  padding: 15px;
  margin-top: 16px;
  margin-bottom: 14px;
}
#loginModal .password-rules {
  padding: 8px 12px;
  gap: 5px;
  margin: 2px 0 10px;
}
#loginModal .google-auth-block {
  margin: 0 0 10px;
}
#loginModal .google-auth-divider {
  margin-bottom: 12px;
}

</style>

<script>
(function () {
  var modal = document.getElementById('loginModal');
  var host = document.getElementById('authModalVanta');
  if (!modal || !host) return;

  var effect = null;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function loadScriptOnce(src) {
    return new Promise(function (resolve, reject) {
      var existing = document.querySelector('script[data-src="' + src + '"]');
      if (existing) {
        if (existing.dataset.loaded) { resolve(); } else { existing.addEventListener('load', resolve); existing.addEventListener('error', reject); }
        return;
      }
      var s = document.createElement('script');
      s.src = src;
      s.dataset.src = src;
      s.onload = function () { s.dataset.loaded = '1'; resolve(); };
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function startVanta() {
    if (reduceMotion || effect) return;
    var base = window.WEB_ROOT || '/';
    var threeSrc = base + 'public/shared/assets/js/vendor/three.min.js';
    var vantaSrc = base + 'public/shared/assets/js/vendor/vanta.net.min.js';
    (window.THREE ? Promise.resolve() : loadScriptOnce(threeSrc))
      .then(function () { return (window.VANTA && window.VANTA.NET) ? Promise.resolve() : loadScriptOnce(vantaSrc); })
      .then(function () {
        if (!window.VANTA || !window.VANTA.NET || effect || !host.isConnected) return;
        effect = window.VANTA.NET({
          el: host,
          THREE: window.THREE,
          mouseControls: true,
          touchControls: true,
          gyroControls: false,
          minHeight: 200.00,
          minWidth: 200.00,
          scale: 1.00,
          scaleMobile: 1.00,
          color: 0xa05f80,
          backgroundColor: 0xf2f3f4,
          points: 8.00,
          maxDistance: 22.00,
          spacing: 16.00
        });
      })
      .catch(function () { /* silent - modal still works without the animated background */ });
  }

  function stopVanta() {
    if (effect) { effect.destroy(); effect = null; }
  }

  var mo = new MutationObserver(function () {
    if (modal.style.display === 'block') { startVanta(); } else { stopVanta(); }
  });
  mo.observe(modal, { attributes: true, attributeFilter: ['style'] });
})();
</script>

<?php if ($googleClientId !== ''): ?>
<script>
/* "Continue with Google" for the customer auth modal.
 *
 * Flow (see public/guest/google_auth.php for the server half):
 *   click -> Google returns a signed ID token -> POST it to google_auth.php
 *     -> registered customer : server emails the 6-digit login code and we
 *        go to the same verify_login_code.php step the password login uses.
 *     -> unknown email       : we say so, switch the modal to the
 *        registration view and prefill the Google name/email, leaving the
 *        normal registration + OTP flow to finish the job.
 *
 * The whole thing is wired once per page: customer/index.php includes this
 * file twice (directly and through its footer), so every lookup below works
 * on all matching nodes and every renderer is idempotent.
 */
(function () {
  if (window.__twGoogleAuthWired) return;
  window.__twGoogleAuthWired = true;

  var CLIENT_ID = <?= json_encode($googleClientId) ?>;
  var ENDPOINT  = <?= json_encode(defined('AUTH_GOOGLE_URL') ? AUTH_GOOGLE_URL : WEB_ROOT . 'public/guest/google_auth.php') ?>;
  var GSI_SRC   = 'https://accounts.google.com/gsi/client';

  function notify(message, type) {
    if (typeof window.showNotification === 'function') { window.showNotification(message, type); return; }
    // Fallback for the few pages that render the modal without main.js.
    var box = document.querySelector('#loginModal .notification');
    if (box) { box.textContent = message; box.className = 'notification ' + (type || 'info'); box.style.display = 'block'; }
  }

  function csrfToken() {
    var input = document.querySelector('#loginModal input[name="csrf_token"]') || document.querySelector('input[name="csrf_token"]');
    if (input && input.value) return input.value;
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function setValue(el, value) {
    if (!el || !value) return;
    el.value = value;
    // Let the live password-rules checklist (which watches the email field)
    // and any other listener see the change.
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  // Unknown Google email: send the visitor to the registration view with
  // what Google told us already filled in, so all that's left is the
  // password (and then the usual registration OTP).
  function goToRegister(data) {
    if (typeof window.showRegister === 'function') {
      window.showRegister();
    } else {
      document.querySelectorAll('#loginModal .auth-form').forEach(function (form) {
        var isRegister = form.id === 'registerForm';
        form.style.display = isRegister ? 'block' : 'none';
        form.classList.toggle('active', isRegister);
      });
    }
    document.querySelectorAll('#registerForm').forEach(function (form) {
      setValue(form.querySelector('#registerFirstname, [name="firstname"]'), data.firstname);
      setValue(form.querySelector('#registerLastname, [name="lastname"]'), data.lastname);
      setValue(form.querySelector('#registerEmail, [name="email"]'), data.email);
    });
    // showRegister() clears the notification area, so say it afterwards.
    notify(data.message || 'This email is not registered yet. Please create your account.', 'warning');
    var pw = document.querySelector('#registerForm #registerPassword');
    if (pw) { try { pw.focus(); } catch (e) {} }
  }

  function onCredential(response) {
    if (!response || !response.credential) { notify('Google sign-in was cancelled.', 'error'); return; }

    var fd = new FormData();
    fd.append('credential', response.credential);
    fd.append('csrf_token', csrfToken());

    notify('Checking your Google account…', 'info');

    fetch(ENDPOINT, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.success && d.require_verification) {
          // Google sign-in lands on the same 6-digit code screen as the
          // password form, so it gets the same cross-fade - see authHandoff()
          // in main.js. Only d.email is available here (masked by the server);
          // there is no typed address to prefer.
          if (typeof window.authHandoff === 'function') {
            window.authHandoff(d.redirect, { email: d.email || '' });
          } else {
            notify('Please verify the 6-digit code we sent to your email.', 'info');
            setTimeout(function () { location.href = d.redirect; }, 1200);
          }
        } else if (d && d.not_registered) {
          goToRegister(d);
        } else {
          notify((d && d.message) || 'Google sign-in failed. Please try again.', 'error');
        }
      })
      .catch(function () { notify('An error occurred. Please try again.', 'error'); });
  }

  // Google draws the button itself; it needs a visible container to measure,
  // so each one is rendered the first time it actually has a width (modal
  // opened, or the login/register view switched to).
  function renderButtons() {
    if (!window.google || !google.accounts || !google.accounts.id) return;
    document.querySelectorAll('.tw-google-btn').forEach(function (host) {
      if (host.dataset.googleRendered === '1') return;
      var width = Math.round(host.clientWidth || 0);
      if (!width) return;
      width = Math.max(200, Math.min(400, width));
      google.accounts.id.renderButton(host, {
        type: 'standard',
        theme: 'outline',
        size: 'large',
        shape: 'pill',
        text: 'continue_with',
        logo_alignment: 'left',
        width: width
      });
      host.dataset.googleRendered = '1';
    });
  }

  function watchForVisibility() {
    // querySelectorAll, not getElementById: customer/index.php ends up with
    // two copies of this modal in the page, and the second one's button
    // would otherwise never get a chance to render.
    var modals = document.querySelectorAll('#loginModal');
    if (!modals.length) return;
    var pending = null;
    var mo = new MutationObserver(function () {
      if (pending) return;
      pending = setTimeout(function () { pending = null; renderButtons(); }, 50);
    });
    modals.forEach(function (modal) {
      mo.observe(modal, { attributes: true, attributeFilter: ['style', 'class'], subtree: true });
    });
  }

  function init() {
    if (!window.google || !google.accounts || !google.accounts.id) return;
    google.accounts.id.initialize({
      client_id: CLIENT_ID,
      callback: onCredential,
      auto_select: false,
      cancel_on_tap_outside: true,
      ux_mode: 'popup'
    });
    renderButtons();
    watchForVisibility();
  }

  function loadGsi() {
    var existing = document.querySelector('script[data-gsi="1"]');
    if (existing) { if (existing.dataset.loaded) { init(); } else { existing.addEventListener('load', init); } return; }
    var s = document.createElement('script');
    s.src = GSI_SRC;
    s.async = true;
    s.defer = true;
    s.dataset.gsi = '1';
    s.onload = function () { s.dataset.loaded = '1'; init(); };
    document.head.appendChild(s);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadGsi);
  } else {
    loadGsi();
  }
})();
</script>
<?php endif; ?>
</div>
