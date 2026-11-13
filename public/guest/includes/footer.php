<?php
// WEB_ROOT will be defined by config/functions.php
require_once __DIR__ . '/../../../config/functions.php';
?>

<!-- Footer Section -->
<footer class="main-footer">
  <div class="container">
    <div class="footer-content">
      <!-- TechWorld Column -->
      <div class="footer-column">
        <h3>TechWorld Computer Store</h3>
        <p>Your One-Stop Computer Shop. We offer a wide range of high-quality computers, printers, accessories, and more.</p>
      </div>

      <!-- Categories Column -->
      <div class="footer-column">
        <h3>Categories</h3>
        <ul>
          <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=Processors">Processors</a></li>
          <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=Graphics Cards">Graphics Cards</a></li>
          <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=Motherboards">Motherboards</a></li>
          <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=Memory">Memory</a></li>    
          <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=Accessories">Accessories</a></li>
        </ul>
      </div>
      
      <!-- Support Column -->
      <div class="footer-column">
        <h3>Support</h3>
        <ul>
          <li>
            <a href="javascript:void(0);" onclick="openLoginModal(); return false;">Customer Service</a>
          </li>
          <li>
            <a href="javascript:void(0);" onclick="openLoginModal(); return false;">Returns</a>
          </li>
          <li>
            <a href="javascript:void(0);" onclick="openLoginModal(); return false;">Warranty</a>
          </li>
          <li>
            <a href="javascript:void(0);" onclick="openLoginModal(); return false;">FAQ</a>    
          </li>
        </ul>
      </div>
      
      <!-- Contact Column -->
      <div class="footer-column">
        <h3>Contact</h3>
        <ul class="contact-list">
          <li>
            <i class="fi fi-rr-envelope" style="color: #174871;"></i>
            <span>techworldcomputerstore@gmail.com</span>
          </li>
          <li>
            <i class="fi fi-rr-phone-call" style="color: #ec4899;"></i>
            <span>0995 586 7973</span>
          </li>
        </ul>
        <ul class="contact-list" style="margin-top:16px;">
          <li><a class="social-link" href="https://www.facebook.com/techworldcomputerstore/" target="_blank" rel="noopener"><img class="social-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/facebook.svg" alt="Facebook"> Facebook</a></li>
          <li><a class="social-link" href="https://www.tiktok.com/@techworld.tiktok" target="_blank" rel="noopener"><img class="social-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/tiktok.svg" alt="TikTok"> TikTok</a></li>
        </ul>
      </div>

      <!-- Hours Column -->
      <div class="footer-column">
        <h3>Store Hours</h3>
        <ul class="contact-list">
          <li>
            <i class="fi fi-rr-clock" style="color: #6b7280;"></i>
            <span>Mon - Sat: 9:00 AM - 5:00 PM</span>
          </li>
          <li>
            <i class="fi fi-rr-clock" style="color: #6b7280;"></i>
            <span>Sunday: Closed</span>
          </li>
        </ul>
      </div>
    </div>
    
    <!-- Footer Bottom -->
    <div class="footer-bottom">
      <p class="footer-address">
        <i class="fi fi-rr-marker" style="color: #22c55e;"></i>
        Unit 48C, 5th Street, Brgy. 104, East Grace Park, Caloocan, Philippines
      </p>
      <div class="footer-bottom-content">
        <p>&copy; 2025 TechWorld. All rights reserved.</p>
        <div class="made-with">
          <span>Made with</span>
          <i class="fi fi-rr-heart" style="color: var(--accent-cyan); margin: 0 4px;"></i>
          <span>for tech enthusiasts</span>
        </div>
      </div>
    </div>
  </div>
</footer>
<?php // Ensure auth modal markup is present for any page using the shared footer ?>
<?php include __DIR__ . '/../../shared/includes/auth-modal.php'; ?>

<!-- Footer CSS -->
<style>
.main-footer {
  background: var(--bg-secondary);
  color: var(--text-primary);
  margin-top: 0;
  border-top: 1px solid var(--border-color);
}

.footer-content {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 40px;
  padding: 60px 0 40px;
  border-bottom: 1px solid var(--border-color);
}

/* 5 columns (TechWorld, Categories, Support, Contact, Store Hours) at
   250px min each don't all fit one row at typical desktop container widths
   - auto-fit above wraps "Store Hours" alone onto its own row. Force an
   even 5-across row from 992px up so it sits in line with the rest instead;
   narrower widths keep the auto-fit/stacked behavior above unchanged. */
@media (min-width: 992px) {
  .footer-content {
    grid-template-columns: repeat(5, 1fr);
  }
}

.footer-column h3 {
  color: var(--text-primary);
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 20px;
  letter-spacing: 0.5px;
}

.footer-column p {
  color: var(--text-secondary);
  line-height: 1.6;
  font-size: 14px;
}

.footer-column ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.footer-column ul li {
  margin-bottom: 12px;
}

.footer-column ul li a {
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 14px;
  transition: color 0.3s ease;
}

.footer-column ul li a:hover {
  color: var(--accent-cyan);
}

.contact-list li {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 15px;
}

.contact-list i {
  font-size: 16px;
  width: 20px;
  text-align: center;
}

.contact-list span {
  color: var(--text-secondary);
  font-size: 14px;
}

.footer-bottom {
  padding: 30px 0;
}

/* Store address - a single compact line in the bottom bar instead of a
   3-line block stretching the first footer column. */
.footer-address {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 14px 0;
  color: var(--text-muted);
  font-size: 13px;
}
.footer-address i { font-size: 14px; flex-shrink: 0; }

.footer-bottom-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 20px;
}

.footer-bottom p {
  color: var(--text-muted);
  font-size: 14px;
  margin: 0;
}

.made-with {
  display: flex;
  align-items: center;
  color: var(--text-muted);
  font-size: 14px;
}

.made-with i {
  animation: heartbeat 1.5s ease-in-out infinite;
}

@keyframes heartbeat {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.1); }
}

/* Responsive Design */
@media (max-width: 768px) {
  .footer-content {
    grid-template-columns: 1fr;
    gap: 30px;
    padding: 40px 0 30px;
  }
  
  .footer-bottom-content {
    flex-direction: column;
    text-align: center;
    gap: 15px;
  }
  
  .footer-column h3 {
    font-size: 16px;
  }
  
  .footer-column p,
  .footer-column ul li a,
  .contact-list span {
    font-size: 13px;
  }
}

@media (max-width: 480px) {
  .footer-content {
    padding: 30px 0 20px;
  }
  
  .footer-bottom {
    padding: 20px 0;
  }
}
</style>

<script>
/* ====== Helpers (shared across pages) ====== */
function openLoginModal(){
  const m=document.getElementById('loginModal');
  if(m){ m.style.display='block'; showLogin(); }
  else { window.location.href = '<?= WEB_ROOT ?>public/guest/login.php'; }
}
function closeModal(){ const m=document.getElementById('loginModal'); if(m){ m.style.display='none'; hideNotification(); } }
function showLogin(){
  if (typeof showAuthView === 'function') { showAuthView('loginForm'); hideNotification(); return; }
  const views = ['loginForm','registerForm','forgotForm','forgotSent','resetVerify','resetForm','verifyForm'];
  views.forEach(v => { const el = document.getElementById(v); if(el) { el.style.display = (v === 'loginForm' ? 'block' : 'none'); el.classList.toggle('active', v === 'loginForm'); } });
  hideNotification();
}
function showRegister(){
  if (typeof showAuthView === 'function') { showAuthView('registerForm'); hideNotification(); setupSecureRoleSelection(); return; }
  const views = ['loginForm','registerForm','forgotForm','forgotSent','resetVerify','resetForm','verifyForm'];
  views.forEach(v => { const el = document.getElementById(v); if(el) { el.style.display = (v === 'registerForm' ? 'block' : 'none'); el.classList.toggle('active', v === 'registerForm'); } });
  hideNotification(); setupSecureRoleSelection();
}
function showForgot(){
  if (typeof showAuthView === 'function') { showAuthView('forgotForm'); hideNotification(); return; }
  const views = ['loginForm','registerForm','forgotForm','forgotSent','resetVerify','resetForm','verifyForm'];
  views.forEach(v => { const el = document.getElementById(v); if(el) { el.style.display = (v === 'forgotForm' ? 'block' : 'none'); el.classList.toggle('active', v === 'forgotForm'); } });
  hideNotification();
}
function togglePassword(id,btn){ const input=document.getElementById(id); const icon=btn?.querySelector('i'); if(!input) return; if(input.type==='password'){ input.type='text'; if(icon) icon.className='fi fi-rr-eye-crossed'; btn.title='Hide password'; } else { input.type='password'; if(icon) icon.className='fi fi-rr-eye'; btn.title='Show password'; } }
function showNotification(msg,type){ const n=document.getElementById('notification'); if(!n) return; n.textContent=msg; n.className='notification '+type; n.style.display='block'; if(msg.length>100){ setTimeout(hideNotification, 10000); } }
function hideNotification(){ const n=document.getElementById('notification'); if(n) n.style.display='none'; }
function updateBadgeDisplay(el, count){ if(!el) return; el.textContent = count; el.style.display = (count>0 ? 'inline-flex' : 'none'); }
function updateCartCount(count){
  const el=document.getElementById('cartCount');
  if(typeof count === 'number'){ updateBadgeDisplay(el, count); return; }
  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php?action=count', { headers: { 'X-Requested-With':'XMLHttpRequest' } })
    .then(r=>r.json()).then(d=>{ if(d.success){ updateBadgeDisplay(el, d.count); } })
    .catch(()=>{});
}

// Add to Cart (global for customer pages)
function addToCart(productId, quantity, callback){
  quantity = typeof quantity === 'number' && quantity > 0 ? quantity : 1;
  const fallbackUrl = '<?= WEB_ROOT ?>public/authenticated/customer/add_to_cart.php?id=' + encodeURIComponent(productId) + '&qty=' + encodeURIComponent(quantity) + '&redirect=' + encodeURIComponent(location.href);
  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php', {
    method:'POST',
    headers:{ 'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest' },
    body: JSON.stringify({ action:'add', product_id: productId, quantity: quantity })
  })
  .then(r=>r.json())
  .then(d=>{
    if(d.success){
      if(typeof d.cart_count === 'number') updateCartCount(d.cart_count); else updateCartCount();
      showNotification(d.message || 'Product added to cart!','success');
      
      // Execute callback if provided (for Buy Now functionality)
      if(typeof callback === 'function') {
        callback();
      }
    } else {
      // Fallback to server redirect to ensure cart updates even if AJAX fails
      window.location.href = fallbackUrl;
    }
  })
  .catch(()=>{ window.location.href = fallbackUrl; });
}

function checkAuthenticationStatus(){
  fetch('<?= WEB_ROOT ?>auth_check.php')
    .then(r=>r.json())
    .then(d=>{
      // Save flags for role selection gate
      window.__canRequestAdmin = !!d.canRequestAdmin;
      window.__isLoggedIn = !!d.loggedIn;

      // Do not overwrite header auth UI; counts and flags only
    })
    .catch(()=>{ /* silent */ });
}

/* Enhanced role selection with server-side guard flag */
function setupSecureRoleSelection(){
  const roleButtons=document.querySelectorAll('.role-button');
  const adminField=document.getElementById('adminRequestField');
  const registerBtn=document.getElementById('registerBtn');
  const roleInput=document.getElementById('selectedRole');
  const adminBtn=document.querySelector('.role-button[data-role="admin"]');

  const canRequestAdmin = !!window.__canRequestAdmin;
  const isLoggedIn      = !!window.__isLoggedIn;

  if(adminBtn && !canRequestAdmin){
    adminBtn.disabled=true;
    adminBtn.style.opacity='0.5';
    adminBtn.style.cursor='not-allowed';
    adminBtn.style.background='rgba(255,255,255,0.02)';
    const desc=adminBtn.querySelector('.role-description');
    if(desc){ desc.textContent='Access restricted to authorized personnel only'; desc.style.color='#ff6b6b'; }
    adminBtn.addEventListener('click',(e)=>{
      e.preventDefault(); e.stopPropagation();
      showNotification(
        'Manager registration is restricted to authorized personnel only. ' +
        (isLoggedIn ? 'Only Owners can enable manager registration.' : 'Please contact your system administrator if you need manager access.'),
        'error'
      );
    }, { once:true });
  }

  roleButtons.forEach(btn=>{
    if(btn.disabled) return;
    btn.onclick=(e)=>{
      e.preventDefault();
      const selected=btn.getAttribute('data-role');
      if((selected==='admin' || selected==='manager') && !canRequestAdmin){
        showNotification('Manager registration is not available. ' + (isLoggedIn ? 'Only Owners can enable manager registration.' : 'Please contact system owner if you need manager access.'),'error');
        return;
      }
      roleButtons.forEach(b=>!b.disabled && b.classList.remove('active'));
      btn.classList.add('active');
      if(roleInput) roleInput.value = selected;

      if(selected==='admin' || selected==='manager'){
        adminField?.classList.add('show');
        if(registerBtn) registerBtn.textContent='Submit Manager Request';
        const msg=document.getElementById('admin_request_message'); if(msg) msg.required=true;
        let ctx=document.getElementById('admin-context'); if(ctx) ctx.remove();
        ctx=document.createElement('p'); ctx.id='admin-context';
        ctx.style.cssText='color:var(--accent-cyan);font-size:12px;margin-top:10px;';
        ctx.textContent='This request will be reviewed by the Owner.';
        adminField?.appendChild(ctx);
      } else {
        adminField?.classList.remove('show');
        if(registerBtn) registerBtn.textContent='Create Account';
        const msg=document.getElementById('admin_request_message'); if(msg) msg.required=false;
        const ctx=document.getElementById('admin-context'); if(ctx) ctx.remove();
      }
    };
  });
}

/* Client-side validation for registration */
function validateRegistrationForm(fd){
  const pw = fd.get('password') || '';
  const cpw= fd.get('confirm_password') || '';
  const email = fd.get('email') || '';
  const role = fd.get('role');
  const msg = (fd.get('admin_request_message')||'').trim();

  const rules = evaluatePasswordRules(pw, email);
  if(!rules.length){ showNotification('Password must be at least 8 characters long.','error'); return false; }
  if(!rules.case){ showNotification('Password must contain both uppercase and lowercase letters.','error'); return false; }
  if(!rules.charset){ showNotification('Password must contain at least one number or symbol.','error'); return false; }
  if(!rules.noemail){ showNotification('Password must not contain any part of your email address.','error'); return false; }
  if(!rules.unique){ showNotification('Password is too common or easily guessed. Please choose a more unique password.','error'); return false; }
  if(pw !== cpw){ showNotification('Passwords do not match.','error'); return false; }
  if(role==='admin' || role==='manager'){
    if(!window.__canRequestAdmin){ showNotification('You are not authorized to request manager accounts.','error'); return false; }
    if(msg.length < 10){ showNotification('Please provide a brief reason (10+ chars) for manager access.','error'); return false; }
  }
  return true;
}

/* Bind events once DOM is ready */
document.addEventListener('DOMContentLoaded', function(){
  updateCartCount();
  checkAuthenticationStatus();

  // URL param banners (index/home convenience)
  const params = new URLSearchParams(location.search);
  if(params.get('logout') === 'success'){ showNotification('You have been logged out successfully.','success'); }
  if(params.get('register') === 'pending'){ showNotification('Admin registration submitted! Please wait for Super Admin approval.','info'); }
  if(params.get('login') === 'error'){ showNotification(decodeURIComponent(params.get('message')||'Login failed. Please try again.'),'error'); }
  if(params.get('register') === 'error'){ showNotification(decodeURIComponent(params.get('message')||'Registration failed. Please try again.'),'error'); }
  if(params.get('expired') === '1'){ showNotification('Your verification code has expired. Please login again to receive a new code.','warning'); }
  // ?modal=login opens the login form straight away. Every route that sends a
  // visitor back here to sign in again - "Back to login" on the 2FA page, an
  // expired code, the not-signed-in guard - has always appended this param,
  // but nothing acted on it, so those links just dropped people on the
  // homepage with the modal shut.
  if(params.get('modal') === 'login' && typeof openLoginModal === 'function'){ openLoginModal(); }

  // Modal overlay click closing disabled (must use form buttons)

  // AJAX: Login
  const loginForm = document.getElementById('loginFormElement');
  if(loginForm){
    loginForm.addEventListener('submit', function(e){
      e.preventDefault();
      const fd = new FormData(loginForm);
      const btn = loginForm.querySelector('button[type="submit"]');
      const original = btn.textContent;
      // Disable while the request is in flight so a second click can't fire a
      // duplicate login - and a duplicate OTP email. Released only on failure,
      // see releaseBtn: the success path redirects after 1.2s and re-enabling
      // during that window would allow another submit.
      btn.disabled = true; btn.textContent = 'Logging in...';
      const releaseBtn = ()=>{ btn.disabled=false; btn.textContent=original; };
      const actionUrl = loginForm.getAttribute('action') || (typeof loginForm.action === 'string' ? loginForm.action : '');
      fetch(actionUrl, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r=>r.json())
        .then(d=>{
          if(d.success){
            const redirect = d.redirect || '<?= WEB_ROOT ?>index.php?login=success';
            const needsVerify = redirect.indexOf('/public/guest/verify_login_code.php') !== -1;
            if(needsVerify){
              // Cross-fade into the code screen instead of announcing it in a
              // notification and then dropping the modal a beat before the
              // browser navigates - that gap is what flashed the landing page.
              // authHandoff() carries the message on the destination's own
              // ground; see main.js and the .auth-handoff block in style.css.
              //
              // The address shown is the one typed into this form, matching
              // what verify_login_code.php prints; d.email is masked and would
              // visibly change between the two screens.
              const typedEmail = (fd.get('email') || '').toString().trim();
              if(typeof authHandoff === 'function'){
                authHandoff(redirect, { email: typedEmail || d.email || '' });
              } else {
                showNotification('Please verify the 6-digit code we sent to your email.','info');
                setTimeout(()=>{ location.href = redirect; }, 1200);
              }
            } else {
              showNotification(d.message || 'Login successful.','success');
              // closeModal() is deliberately not called first: the browser
              // keeps this document painted until the next one renders, so
              // lifting the modal here only uncovers the page behind it.
              setTimeout(()=>{ location.href = redirect; }, 1200);
            }
          } else {
            showNotification(d.message || 'Invalid credentials.','error');
            releaseBtn();
          }
        })
        .catch(()=>{ showNotification('An error occurred. Please try again.','error'); releaseBtn(); });
    });
  }

  // AJAX: Register
  const registerForm = document.getElementById('registerFormElement');
  if(registerForm){
    registerForm.addEventListener('submit', function(e){
      e.preventDefault();
      const fd = new FormData(registerForm);
      if(!validateRegistrationForm(fd)) return;

      const btn = registerForm.querySelector('button[type="submit"]');
      const original = btn.textContent;
      btn.disabled = true; btn.textContent = (fd.get('role')==='admin' ? 'Submitting Request...' : 'Creating Account...');
      // Released only on failure - on success the handler is already closing
      // the modal or swapping to the OTP pane, and re-enabling in between
      // would allow a duplicate registration request.
      const releaseBtn = ()=>{ btn.disabled=false; btn.textContent = original; };

      const regActionUrl = registerForm.getAttribute('action') || (typeof registerForm.action === 'string' ? registerForm.action : '');
      fetch(regActionUrl, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r => {
          if (!r.ok) {
            console.error('Registration HTTP error:', r.status, r.statusText);
            throw new Error(`Server returned HTTP ${r.status}`);
          }
          return r.json();
        })
        .then(d=>{
          if(d.success){
            if (d.require_otp) {
              hideNotification();
              const lm = document.getElementById('loginModal');
              if(lm) { lm.style.display = 'block'; }

              const rf = document.getElementById('registerForm');
              if(rf) { rf.style.display = 'none'; rf.classList.remove('active'); }

              const rv = document.getElementById('registerVerify');
              if(rv) { rv.style.display = 'block'; rv.classList.add('active'); }

              const lbl = document.getElementById('registerVerifyEmail');
              if (lbl) lbl.textContent = d.email || fd.get('email');

              // Clear any stale OTP input values
              const rvInputs = document.querySelectorAll('#registerVerify .otp-input');
              rvInputs.forEach(inp => inp.value = '');
              const rvHidden = document.getElementById('registerOtpHidden');
              if (rvHidden) rvHidden.value = '';

              if(typeof window.wireOtp === 'function') {
                window.wireOtp('#registerVerify', '#registerOtpHidden');
              }

              const firstOtp = document.querySelector('#registerVerify .otp-input');
              if (firstOtp) {
                firstOtp.focus();
              }
            } else if(d.pending_approval){
              setTimeout(()=>{ closeModal(); registerForm.reset(); setupSecureRoleSelection(); }, 2500);
            } else if (d.auto_login){
              setTimeout(()=>{ closeModal(); location.href = d.redirect || '<?= WEB_ROOT ?>index.php?register=success'; }, 1200);
            } else if (d.require_login){
              // Show login form after successful registration
              setTimeout(()=>{ 
                registerForm.reset(); 
                setupSecureRoleSelection(); 
                showLogin(); // Switch to login form
              }, 1500);
            } else {
              setTimeout(()=>{ closeModal(); registerForm.reset(); setupSecureRoleSelection(); }, 2000);
            }
          } else {
            showNotification(d.message || 'Registration failed.','error');
            releaseBtn();
          }
        })
        .catch(err => {
          console.error('Registration error details:', err);
          showNotification(err.message || 'An error occurred. Please try again.', 'error');
          releaseBtn();
        });
    });
  }
});

/* ===== Avatar dropdown behavior ===== */
document.addEventListener('click', function(e){
  var toggle = document.getElementById('avatarToggle');
  var menu = document.getElementById('avatarMenu');
  if(!toggle || !menu) return;
  if(toggle.contains(e.target)){
    var isOpen = menu.classList.contains('show');
    menu.classList.toggle('show', !isOpen);
    menu.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
    toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    e.preventDefault();
    return;
  }
  if(!menu.contains(e.target)){
    menu.classList.remove('show');
    menu.setAttribute('aria-hidden', 'true');
    toggle.setAttribute('aria-expanded', 'false');
  }
});
</script>
