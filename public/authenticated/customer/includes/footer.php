<?php
// WEB_ROOT will be defined by config/functions.php
require_once __DIR__ . '/../../../../config/functions.php';

// The store footer (site map, contact, hours) is homepage-only on the
// customer side. Every other customer page is a task the shopper is already
// in the middle of - cart, checkout, order tracking, support - and a second
// navigation surface at the bottom only competes with the sidebar.
//
// Gated here rather than at the ~30 include sites so there's one rule to
// read and nothing to forget when a page is added. Note this covers ONLY
// the <footer> element and its CSS below: the auth modal and the shared
// helper script that follow are used by every page that includes this file
// and stay unconditional.
$__isCustomerHomepage = in_array(
    basename($_SERVER['SCRIPT_NAME'] ?? ''),
    ['index.php', 'homepage.php'], // both files render the customer home
    true
) && strpos(
    str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''),
    '/authenticated/customer/'
) !== false;
?>

<?php if ($__isCustomerHomepage): ?>
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
          <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=Processors">Processors</a></li>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=Graphics Cards">Graphics Cards</a></li>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=Motherboards">Motherboards</a></li>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=Memory">Memory</a></li>    
          <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=Accessories">Accessories</a></li>
        </ul>
      </div>
      
      <!-- Support Column -->
      <div class="footer-column">
        <h3>Support</h3>
        <ul>
          <li>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/support.php">Customer Service</a>
          </li>
          <li>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/returns.php">Returns</a>
          </li>
          <li>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/warranty.php">Warranty</a>
          </li>
          <li>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/faq.php">FAQ</a>    
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
<?php endif; ?>

<?php // Ensure auth modal markup is present for any page using the customer footer ?>
<?php include __DIR__ . '/../../../shared/includes/auth-modal.php'; ?>

<?php if ($__isCustomerHomepage): ?>
<!-- Footer CSS - every selector below is footer-scoped (.main-footer,
     .footer-*, .contact-list, .made-with, @keyframes heartbeat) and used
     nowhere else on the customer side, so it travels with the markup rather
     than shipping to 29 pages that no longer render it. The guest footer
     carries its own copy of these rules and is unaffected. -->
<style>
.main-footer {
  background: var(--bg-secondary);
  color: var(--text-primary);
  margin-top: 0px;
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
<?php endif; ?>

<script>
/* ====== Helpers (shared across pages) ====== */
function showCenterModal(message){
  try{
    const overlay=document.createElement('div');
    overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;z-index:9999;';
    const box=document.createElement('div');
    box.style.cssText='min-width:280px;max-width:520px;border-radius:12px;padding:18px 22px;background:#0b1220;color:#d1fae5;box-shadow:0 10px 30px rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08);text-align:center;';
    box.textContent=message||'Success';
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    setTimeout(()=>{ overlay.style.transition='opacity .3s'; overlay.style.opacity='0'; setTimeout(()=>{ overlay.remove(); }, 320); }, 2000);
  }catch(e){}
}

function showTopToast(message){
  try{
    const toast=document.createElement('div');
    // Colours come from the theme tokens in shared/css/theme-light.css
    // (linked by customer/includes/sidebar.php), not hardcoded hexes. The
    // text, border and surface were all pinned to one raw #34d399 on a fixed
    // navy, so this toast kept its dark-mode look in light mode - mint-on-navy
    // floating over a white page. --tw-ok is the system's success ink and
    // flips with [data-mode] (#9df3d0 dark / #0f6b4a light), both contrast-
    // checked against the surface they land on. Fallbacks are the dark values,
    // for any page that renders this without the token sheet.
    toast.style.cssText='position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:10000;'
      + 'background:var(--tw-surface, #0b1626);'
      + 'color:var(--tw-ok, #9df3d0);'
      + 'border:1px solid var(--tw-ok-line, rgba(52,211,153,.40));'
      + 'border-radius:10px;padding:10px 16px;'
      + 'box-shadow:var(--tw-shadow, 0 10px 30px rgba(0,0,0,.25));';
    toast.textContent=message||'Success';
    document.body.appendChild(toast);
    setTimeout(()=>{ toast.style.transition='opacity .3s, transform .3s'; toast.style.opacity='0'; toast.style.transform='translateX(-50%) translateY(-10px)'; setTimeout(()=>toast.remove(), 320); }, 2000);
  }catch(e){}
}
function openLoginModal(){
  const m=document.getElementById('loginModal');
  if(m){ m.style.display='block'; showLogin(); }
  else { window.location.href = '<?= WEB_ROOT ?>public/guest/login.php'; }
}
function closeModal(){ const m=document.getElementById('loginModal'); if(m){ m.style.display='none'; hideNotification(); } }
function showLogin(){ const l=document.getElementById('loginForm'); const r=document.getElementById('registerForm'); if(l&&r){ l.classList.add('active'); r.classList.remove('active'); hideNotification(); } }
function showRegister(){ const l=document.getElementById('loginForm'); const r=document.getElementById('registerForm'); if(l&&r){ r.classList.add('active'); l.classList.remove('active'); hideNotification(); setupSecureRoleSelection(); } }
function togglePassword(id,btn){ const input=document.getElementById(id); const icon=btn?.querySelector('i'); if(!input) return; if(input.type==='password'){ input.type='text'; if(icon) icon.className='fi fi-rr-eye-crossed'; btn.title='Hide password'; } else { input.type='password'; if(icon) icon.className='fi fi-rr-eye'; btn.title='Show password'; } }
function showNotification(msg,type){ const n=document.getElementById('notification'); if(!n) return; n.textContent=msg; n.className='notification '+type; n.style.display='block'; if(msg.length>100){ setTimeout(hideNotification, 10000); } }
function hideNotification(){ const n=document.getElementById('notification'); if(n) n.style.display='none'; }
function updateBadgeDisplay(el, count){ if(!el) return; el.textContent = count; el.style.display = (count>0 ? 'inline-flex' : 'none'); }
function updateFloatingCartBtn(count){
  const btn = document.getElementById('floatingCartBtn');
  if(!btn) return;
  const wasShown = btn.classList.contains('show');
  if(count > 0){
    btn.classList.add('show');
    if(wasShown){
      btn.classList.remove('pop');
      void btn.offsetWidth;
      btn.classList.add('pop');
    }
  } else {
    btn.classList.remove('show','pop');
  }
}
function updateCartCount(count){
  const el=document.getElementById('cartCount');
  if(typeof count === 'number'){ updateBadgeDisplay(el, count); updateFloatingCartBtn(count); return; }
  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php?action=count', { headers: { 'X-Requested-With':'XMLHttpRequest' } })
    .then(r=>r.json()).then(d=>{ if(d.success){ updateBadgeDisplay(el, d.count); updateFloatingCartBtn(d.count); } })
    .catch(()=>{});
}

// Cart item selection persistence - items added via "Add to Cart" show up
// pre-selected (checked) the next time the cart page loads. Selection state
// is kept in sync with the cart page's checkboxes too (see cart.php).
const CART_SELECTION_KEY = 'tw_cart_selected_<?= (int)(function_exists('getUserId') ? getUserId() : 0) ?>';
function getCartSelection(){
  try{
    const raw = localStorage.getItem(CART_SELECTION_KEY);
    const arr = raw ? JSON.parse(raw) : [];
    return new Set(Array.isArray(arr) ? arr.map(Number) : []);
  }catch(e){ return new Set(); }
}
function setCartSelection(productId, isSelected){
  const id = parseInt(productId, 10);
  if(!id) return;
  const set = getCartSelection();
  if(isSelected){ set.add(id); } else { set.delete(id); }
  try{ localStorage.setItem(CART_SELECTION_KEY, JSON.stringify(Array.from(set))); }catch(e){}
}
function markCartItemSelected(productId){ setCartSelection(productId, true); }

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
      markCartItemSelected(productId);

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
        (isLoggedIn ? 'Only Owners can enable manager registration.' : 'Please contact system owner if you need manager access.'),
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
  if(params.get('login') === 'success'){ showTopToast('Woohoo! You\'re logged in successfully.'); }
  if(params.get('register') === 'pending'){ showNotification('Admin registration submitted! Please wait for Super Admin approval.','info'); }
  if(params.get('login') === 'error'){ showNotification(decodeURIComponent(params.get('message')||'Login failed. Please try again.'),'error'); }
  if(params.get('register') === 'error'){ showNotification(decodeURIComponent(params.get('message')||'Registration failed. Please try again.'),'error'); }

  // Modal overlay click closing disabled (must use form buttons)

  // AJAX: Login
  const loginForm = document.getElementById('loginFormElement');
  if(loginForm){
    loginForm.addEventListener('submit', function(e){
      e.preventDefault();
      const fd = new FormData(loginForm);
      const btn = loginForm.querySelector('button[type="submit"]');
      const original = btn.textContent;
      btn.disabled = true; btn.textContent = 'Logging in...';
      // Released only on failure: the success path redirects after 1.2s, and
      // re-enabling during that window allows a duplicate submit.
      const releaseBtn = ()=>{ btn.disabled=false; btn.textContent=original; };
      fetch(loginForm.action, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r=>r.json())
        .then(d=>{
          if(d.success){
            const redirect = d.redirect || '<?= WEB_ROOT ?>index.php?login=success';
            // Same handoff as the guest footer: a customer login still has to
            // clear the 6-digit code step, and closing the modal a beat before
            // navigating flashed whatever page this modal was opened over.
            if(redirect.indexOf('/public/guest/verify_login_code.php') !== -1 && typeof authHandoff === 'function'){
              authHandoff(redirect, { email: (fd.get('email') || '').toString().trim() });
            } else {
              showNotification(d.message || 'Login successful.','success');
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

      fetch(registerForm.action, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r=>r.json())
        .then(d=>{
          if(d.success){
            if (d.require_otp) {
              hideNotification();
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
        .catch(()=>{ showNotification('An error occurred. Please try again.','error'); releaseBtn(); });
    });
  }
});


/* ---------------------------------------------------------------------------
 * Add-to-cart sound.
 *
 * Hooked by wrapping the global addToCart() rather than by editing each
 * button: every add-to-cart control on the customer side - the product grids,
 * the product page, the wishlist row, the AI Builder's results - calls this
 * one function, most of them through an inline onclick that resolves it off
 * `window` at click time. Wrapping it once therefore covers all of them, and
 * a new button added later is covered for free.
 *
 * Deliberately wrapped on DOMContentLoaded, not immediately: several pages
 * declare their own addToCart() further down (products.php has one), and the
 * last declaration parsed is the one that wins. Waiting until the document is
 * parsed means this decorates whichever of them is actually live.
 *
 * The sound is fired before delegating, so it responds to the click rather
 * than to the round-trip - a confirmation that lags the server call feels
 * broken. Success/failure is still reported the way it always was, by
 * showNotification() inside the original function.
 * ------------------------------------------------------------------------- */
(function () {
  var cartSound = null;

  function playCartSound() {
    try {
      if (!cartSound) {
        cartSound = new Audio('<?= WEB_ROOT ?>public/shared/assets/video/Addtocart.mp3');
        cartSound.preload = 'auto';
      }
      /* Rewind rather than layering: adding three items quickly should click
         three times, not build a chord of overlapping copies. */
      cartSound.currentTime = 0;
      var p = cartSound.play();
      /* A blocked or interrupted play must never break the add itself. */
      if (p && typeof p.catch === 'function') { p.catch(function () {}); }
    } catch (e) { /* no audio support - the cart still works */ }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var original = window.addToCart;
    if (typeof original !== 'function' || original.__hasCartSound) { return; }

    var wrapped = function () {
      playCartSound();
      return original.apply(this, arguments);
    };
    /* Marked so a page that includes this footer twice cannot stack two
       wrappers and play the clip twice per click. */
    wrapped.__hasCartSound = true;
    window.addToCart = wrapped;
  });
})();

/* Avatar dropdown behavior is handled by header include to avoid duplicate listeners. */
</script>

<?php /* ---------------------------------------------------------------------
   The responsive pass for everything below 1100px.

   Linked here, and from nowhere else, because this file is the LAST include
   on every customer page - several of them carry their own inline <style>
   after the shared sheets, and a rule that has to correct one of those needs
   to come after it. Linking it from header.php or sidebar.php would put it
   before those blocks and turn every correction into an !important.

   A stylesheet inside <body> is how the whole customer area is already
   built (see the note at the top of includes/header.php); this follows that,
   it is not an exception to it.

   Every rule in the file is inside a max-width query, so at 1100px and up it
   contributes nothing.
   ------------------------------------------------------------------- */ ?>
<link rel="stylesheet" href="<?= asset_url('public/shared/css/tw-mobile.css') ?>">
