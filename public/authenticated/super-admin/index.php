<?php
// public/authenticated/super-admin/index.php — Super Admin login (panel-styled)
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// If already logged in as super-admin, go to dashboard
if (isLoggedIn() && isSuperAdmin()) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/dashboard.php');
    exit;
}

$pageTitle = 'Super Admin Login - TechWorld';
$activeNav = '';
$hideHeaderActions = true;
$hideNav = true;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="auth-page" style="padding:40px 0;">
  <div class="container">
    <div class="modal-content" style="position:static;">
      <div class="auth-container">
        <div id="notification" class="notification" style="display:none;"></div>
        <div class="auth-form active">
          <h2>Super Admin Login</h2>
          <p>Sign in with your super administrator account</p>
          <form id="saLoginForm" action="<?= WEB_ROOT ?>public/authenticated/super-admin/login_check.php" method="POST">
            <div class="input-group">
              <input type="email" name="email" placeholder="Super Admin Email" required autocomplete="email">
            </div>
            <div class="password-field">
              <input type="password" id="saLoginPassword" name="password" placeholder="Password" required autocomplete="current-password">
              <button type="button" id="toggleSaLoginPw" class="password-toggle" onclick="togglePassword('saLoginPassword', this)" style="display:none;" aria-hidden="true">
                <i class="fi fi-rr-eye"></i>
              </button>
            </div>
            <button type="submit" class="auth-btn">Login</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</main>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const form = document.getElementById('saLoginForm');
  if(!form) return;
  // Show password toggle only when input has value (match admin index behavior)
  const pwInput = document.getElementById('saLoginPassword');
  const pwToggle = document.getElementById('toggleSaLoginPw');
  function updatePwToggle(){
    const hasValue = pwInput && pwInput.value.length > 0;
    if (pwToggle) {
      pwToggle.style.display = hasValue ? 'flex' : 'none';
      pwToggle.setAttribute('aria-hidden', hasValue ? 'false' : 'true');
    }
  }
  if (pwInput) {
    updatePwToggle();
    pwInput.addEventListener('input', updatePwToggle);
    pwInput.addEventListener('change', updatePwToggle);
  }
  form.addEventListener('submit', function(e){
    e.preventDefault();
    const fd = new FormData(form);
    const btn = form.querySelector('button[type=submit]');
    const original = btn.textContent; btn.disabled = true; btn.textContent = 'Logging in...';
    fetch('<?= WEB_ROOT ?>public/authenticated/super-admin/login_check.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
      .then(r=>r.json()).then(d=>{
        if(d.success){ location.href = d.redirect || '<?= WEB_ROOT ?>public/authenticated/super-admin/dashboard.php'; }
        else { showNotification(d.message || 'Login failed.','error'); }
      })
      .catch(()=>showNotification('An error occurred. Please try again.','error'))
      .finally(()=>{ btn.disabled=false; btn.textContent = original; });
  });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>


