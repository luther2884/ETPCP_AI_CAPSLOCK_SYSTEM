<?php
// customer/edit_profile.php - Edit Account Information
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Edit Profile - TechWorld';
$user = getUserData();
$userId = getUserId();

// Detect existing profile image - only if user actually has one
$profileImageRel = null;
if (function_exists('userHasAnyProfilePhoto') && userHasAnyProfilePhoto($userId)) {
    $profileImageRel = resolveProfilePhotoPath($userId);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js
         is deferred and would run a frame after first paint, which is exactly
         the flash this prevents. Resolution order is localStorage -> OS
         preference -> dark. There is no server-rendered preference to fall
         back on yet - `users` has no theme column; see the TODO in theme.js. */ ?>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<section class="account-page epro-page">
  <div class="container">
    <div class="account-header">
      <div class="cx-eyebrow"><span aria-hidden="true"></span>Account</div>
      <h1 class="cx-title">Edit Profile</h1>
      <p class="cx-lede">Update your personal information and profile photo</p>
    </div>
    <div class="cx-rule" aria-hidden="true"><i></i></div>

    <div class="profile-layout">
      <!-- Left: Profile Photo -->
      <aside class="profile-card">
        <div class="avatar-lg" id="avatarContainer">
          <?php $initials = strtoupper(substr($user['firstname'],0,1) . substr($user['lastname'],0,1)); ?>
          <?php if ($profileImageRel): ?>
            <img id="profilePhoto" src="<?= WEB_ROOT . 'public/authenticated/' . $profileImageRel ?>" alt="Profile photo">
          <?php else: ?>
            <span id="profileInitials"><?= $initials ?></span>
          <?php endif; ?>
        </div>
        <form id="photoForm" class="photo-actions" action="<?= WEB_ROOT ?>public/authenticated/auth/profile_upload.php" method="post" enctype="multipart/form-data" onsubmit="return uploadProfilePhoto(event)">
          <input type="file" name="photo" id="photoInput" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="previewSelectedPhoto(this)">
          <input type="hidden" name="action" id="photoAction" value="upload">
          <button type="button" class="btn-outline" onclick="document.getElementById('photoInput').click()">Change Photo</button>
          <button type="submit" class="btn-primary" id="savePhotoBtn" disabled>Save</button>
          <button type="button" id="removePhotoBtn" class="btn-danger" onclick="removeProfilePhoto()" style="<?= $profileImageRel ? '' : 'display:none' ?>">Remove</button>
        </form>
        <p id="photoPendingHint" class="hint" style="display:none;margin-top:10px;">New photo selected &mdash; click <strong>Save</strong> to apply it.</p>
        <a href="<?= WEB_ROOT ?>public/authenticated/customer/profile.php" class="btn-outline" style="margin-top:12px;">Back to Profile</a>
      </aside>

      <!-- Right: Edit Forms -->
      <main class="profile-main">
        <div class="card">
          <div class="card-header">
            <h2>Account Information</h2>
            <span class="hint">Update your personal details</span>
          </div>
          <form id="accountForm" class="form-grid" action="<?= WEB_ROOT ?>public/authenticated/auth/update_profile.php" method="post" onsubmit="return saveAccountInfo(event)">
            <div class="form-field">
              <label>First Name</label>
              <input type="text" name="firstname" value="<?= htmlspecialchars($user['firstname']) ?>" placeholder="First name">
            </div>
            <div class="form-field">
              <label>Last Name</label>
              <input type="text" name="lastname" value="<?= htmlspecialchars($user['lastname']) ?>" placeholder="Last name">
            </div>
            <div class="form-field full">
              <label>Email</label>
              <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" placeholder="Email address">
            </div>
            <div class="form-field">
              <label>Phone</label>
              <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g. +63 900 000 0000">
            </div>
            <div class="form-field full">
              <label>Address</label>
              <textarea name="address" rows="3" placeholder="Your address..."><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn-primary">Save Changes</button>
              <a href="<?= WEB_ROOT ?>public/authenticated/customer/profile.php" class="btn-outline">Cancel</a>
            </div>
          </form>
        </div>

        
      </main>
    </div>
  </div>
</section>

<style>
/* ===========================================================================
   Edit Profile, in the landing page's visual language.

   PRESENTATION ONLY. Every id and handler the script below uses is untouched:
   #avatarContainer, #profilePhoto, #profileInitials, #photoForm, #photoInput,
   #photoAction, #savePhotoBtn, #removePhotoBtn and #accountForm, plus the
   inline onclick/onsubmit handlers on every control.

   WHY: this was the last page in the account area still on the old generic
   tokens (--bg-card, --text-primary, --input-bg). customer-dark.css hands
   `.account-page .profile-layout` a warm-vanilla treatment (#fff4ef cards,
   #17324a ink) left over from when these pages were light, so on the dark
   ground the two cards came out cream while Profile, Settings and Orders
   beside them were navy panels. The left card was worse: style.css line 3393
   forces `.profile-card` to `background:#ffffff !important` under
   [data-theme="light"], which this page sets, so it rendered flat white.

   The fix is the one profile.php (.pro-page) and settings.php (.set-page)
   already use - an extra class on the <section> that outranks the vanilla
   block - so all three now read as one system. .epro-page is that hook here;
   the selectors below are (0,4,1) against customer-dark.css's (0,3,1), and
   !important appears ONLY where style.css's own !important has to be
   answered.

   Ink is --cx-ink rather than a literal #fff, so light mode comes for free
   from theme-light.css section 3 instead of needing a 9h block of its own the
   way settings.php did.
   ======================================================================== */
.account-page { padding:40px 0 64px; background: var(--page-bg-gradient); min-height:100vh; }
.epro-page .container { max-width: 1240px; }
.account-header { text-align:left; margin-bottom:0; }

.profile-layout { display:grid; grid-template-columns: 330px minmax(0, 1fr); gap:18px; align-items:start; }

/* --- the photo card ------------------------------------------------------- */
/* Matches profile.php's identity card: same radius, same hairline, same pink
   bloom behind the avatar so the panel has a light source. The !importants
   answer style.css's [data-theme="light"] .profile-card rule. */
body .account-page.epro-page .profile-layout .profile-card {
  position:relative; overflow:hidden;
  height:fit-content; text-align:center;
  padding:30px 26px;
  border: 1px solid var(--cx-line) !important;
  border-radius: 22px;
  background: var(--cx-panel) !important;
  color: var(--cx-ink) !important;
  box-shadow: 0 20px 48px rgba(3, 8, 16, .4) !important;
}
body .account-page.epro-page .profile-layout .profile-card::before {
  content:''; position:absolute; left:50%; top:-90px;
  width:260px; height:260px; transform:translateX(-50%);
  background: radial-gradient(circle, rgba(224, 86, 122, .22), transparent 66%);
  pointer-events:none;
}
.profile-card > * { position:relative; z-index:1; }

.avatar-lg {
  width:112px; height:112px; margin:0 auto 18px;
  display:flex; align-items:center; justify-content:center; overflow:hidden;
  border-radius:50%;
  border: 2px solid rgba(255, 255, 255, .18);
  background: linear-gradient(140deg, var(--cx-pink), var(--cx-violet) 55%, var(--cx-cyan));
  color:#fff;
  font-family: var(--cx-font); font-size:38px; font-weight:800; letter-spacing:-.02em;
  box-shadow: 0 14px 36px rgba(224, 86, 122, .32);
}
.avatar-lg img { width:100%; height:100%; object-fit:cover; display:block; }

/* Three pill controls in a 330px column. They wrap rather than squeeze -
   "Change Photo" was breaking across two lines inside its own button. */
.photo-actions { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-bottom:4px; }

/* --- the form card -------------------------------------------------------- */
body .account-page.epro-page .profile-layout .card {
  margin-bottom:16px; padding:24px;
  border: 1px solid var(--cx-line);
  border-radius: 20px;
  background: var(--cx-panel);
  box-shadow: 0 20px 48px rgba(3, 8, 16, .4);
}
.card-header { margin-bottom:18px; }
body .account-page.epro-page .profile-layout .card-header h2 {
  display:flex; align-items:center; gap:10px;
  margin:0 0 7px;
  font-family: var(--cx-font); font-size:18px; font-weight:800;
  letter-spacing:-.01em; color: var(--cx-ink);
}
/* The accent bar settings.php and the tracking page put in front of a section
   title - the cue that says these cards belong to the same page family. */
body .account-page.epro-page .profile-layout .card-header h2::before {
  content:''; flex:none;
  width:3px; height:17px; border-radius:2px;
  background: linear-gradient(180deg, var(--cx-cyan), var(--cx-violet));
}
/* "Update your personal details" is a sentence, so it gets sentence case in
   the body font - not the 10px tracked-out mono the small-caps are for. Same
   call as settings.php's .hint. */
body .account-page.epro-page .profile-layout .card-header .hint,
body .account-page.epro-page .profile-layout .hint,
.hint {
  font-family: var(--cx-font);
  font-size:13.5px; line-height:1.5; letter-spacing:0;
  text-transform:none; color: var(--cx-ink-dim);
}

/* --- form fields ---------------------------------------------------------- */
.form-grid { display:grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap:16px; }
.form-grid .full { grid-column: 1 / -1; }
.form-field label {
  display:block; margin-bottom:7px;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:600;
  letter-spacing:.14em; text-transform:uppercase; color: var(--cx-ink-faint);
}
/* The textarea is styled WITH the inputs, not after them. On the old sheet it
   set no font-family, so it fell back to the browser default mono - which is
   why the address box read in a different typeface from every field above it
   (visible in the screenshot: "Your address..." in Courier). */
.form-field input,
.form-field textarea {
  width:100%; box-sizing:border-box;
  padding:13px 16px;
  border: 1px solid var(--cx-line);
  border-radius: 12px;
  background: rgba(6, 13, 24, .65);
  color: var(--cx-ink);
  font-family: var(--cx-font); font-size:15px; line-height:1.5;
  transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.form-field textarea { resize:vertical; min-height:96px; }
.form-field input::placeholder,
.form-field textarea::placeholder { color: var(--cx-ink-faint); }
.form-field input:focus,
.form-field textarea:focus {
  outline:none;
  border-color: rgba(103, 232, 249, .55);
  background: rgba(6, 13, 24, .8);
  box-shadow: 0 0 0 3px rgba(103, 232, 249, .12);
}
.form-actions { grid-column: 1 / -1; display:flex; gap:10px; justify-content:flex-end; margin-top:6px; }

/* --- controls ------------------------------------------------------------- */
/* One pill shape, coloured by role - identical to settings.php's set. */
body .account-page.epro-page .profile-layout .btn-primary,
.btn-primary {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:13px 24px;
  border:none; border-radius:999px;
  /* The shared brand gradient, not this page's old cyan-to-blue: white on
     --tw-brand-grad clears 4.5:1 for a 10.5px mono label in both themes. */
  background: var(--tw-brand-grad);
  color:#fff; cursor:pointer;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:700;
  letter-spacing:.14em; text-transform:uppercase;
  box-shadow: 0 10px 26px rgba(3, 8, 16, .35);
  transition: transform .2s var(--cx-ease), box-shadow .2s ease, filter .2s ease;
}
/* Deepen on hover, never brighten - brightening walks white off the 4.5
   floor. Same note as settings.php. */
body .account-page.epro-page .profile-layout .btn-primary:hover:not(:disabled),
.btn-primary:hover:not(:disabled) {
  transform: translateY(-2px);
  filter: saturate(1.08) brightness(0.92);
  box-shadow: 0 16px 34px rgba(3, 8, 16, .45);
}
.btn-primary:active:not(:disabled) { transform: scale(.97); }
/* Save sits disabled until a file is picked, which is most of this page's
   life - it has to LOOK inert, or it reads as the primary action. */
.btn-primary:disabled { opacity:.42; cursor:not-allowed; box-shadow:none; }

body .account-page.epro-page .profile-layout .btn-outline,
.btn-outline {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:11px 20px;
  /* --tw-line-ctl, not --tw-line: a 4% wash behind a 1.2:1 edge is a label
     floating in space, not a button. */
  border: 1px solid var(--tw-line-ctl, rgba(120, 170, 230, .55));
  border-radius:999px;
  background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04);
  color: var(--cx-ink-dim); cursor:pointer; text-decoration:none;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:600;
  letter-spacing:.12em; text-transform:uppercase;
  transition: transform .18s var(--cx-ease), background .18s ease,
              border-color .18s ease, color .18s ease;
}
body .account-page.epro-page .profile-layout .btn-outline:hover:not(:disabled),
.btn-outline:hover:not(:disabled) {
  transform: translateY(-1px);
  background: rgba(103, 232, 249, .12);
  border-color: rgba(103, 232, 249, .45);
  color: var(--cx-ink);
}
.btn-outline:disabled { opacity:.45; cursor:not-allowed; }

body .account-page.epro-page .profile-layout .btn-danger,
.btn-danger {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:11px 20px;
  /* .72, not the .4 this started at - the edge is what makes Remove read as a
     control, and it has to clear 3:1 on the panel like the others. */
  border: 1px solid rgba(224, 86, 122, .72);
  border-radius:999px;
  background: rgba(224, 86, 122, .1);
  color: var(--cx-pink-lt); cursor:pointer;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:600;
  letter-spacing:.12em; text-transform:uppercase;
  transition: transform .18s var(--cx-ease), background .18s ease,
              border-color .18s ease, color .18s ease;
}
body .account-page.epro-page .profile-layout .btn-danger:hover,
.btn-danger:hover {
  transform: translateY(-1px);
  background: rgba(224, 86, 122, .22);
  border-color: rgba(224, 86, 122, .65);
  color:#fff;
}

@media (max-width: 992px) {
  .profile-layout { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .form-grid { grid-template-columns: 1fr; }
  body .account-page.epro-page .profile-layout .card { padding:18px; }
  body .account-page.epro-page .profile-layout .profile-card { padding:24px 18px; }
}
@media (prefers-reduced-motion: reduce) {
  .btn-primary, .btn-outline, .btn-danger { transition:none; }
  .btn-primary:hover, .btn-outline:hover, .btn-danger:hover { transform:none; }
}
</style>

<script>
async function saveAccountInfo(e){
  e.preventDefault();
  const form = document.getElementById('accountForm');
  // Confirmation prompt
  if(!(await uiConfirm('Are you sure you want to update your account information?'))){
    return false;
  }
  const fd = new FormData(form);
  // CSRF
  fd.append('csrf_token', '<?= csrf_token() ?>');
  const btn = form.querySelector('button[type="submit"]');
  if(btn){ btn.disabled = true; btn.textContent = 'Saving...'; }
  fetch(form.action, { method:'POST', body: fd, headers:{ 'X-Requested-With':'XMLHttpRequest' } })
    .then(async r=>{ const t = await r.text(); try{ return JSON.parse(t); } catch(e){ throw new Error(t || 'Invalid server response'); } })
    .then(d=>{
      if(d.success){
        // Broadcast to header so the username chip updates instantly
        try{ window.dispatchEvent(new CustomEvent('profile-name-updated', { detail: d })); }catch(_){}
        showNotification('Account information saved.', 'success');
      } else {
        const msg = d.message || 'Save failed';
        showNotification(msg, 'error');
      }
    })
    .catch(err=>{ showNotification((err && err.message) || 'Request error','error'); })
    .finally(()=>{ if(btn){ btn.disabled = false; btn.textContent = 'Save Changes'; } });
  return false;
}

function previewSelectedPhoto(input){
  const file = input.files && input.files[0];
  if(!file) return;
  if(file.size > 3 * 1024 * 1024){ showNotification('Image too large. Max 3MB.', 'error'); input.value=''; return; }
  const url = URL.createObjectURL(file);
  let img = document.getElementById('profilePhoto');
  const initialsEl = document.getElementById('profileInitials');
  if(!img){ img = document.createElement('img'); img.id='profilePhoto'; document.getElementById('avatarContainer').innerHTML=''; document.getElementById('avatarContainer').appendChild(img); }
  if(initialsEl) initialsEl.remove();
  img.src = url;
  const saveBtn = document.getElementById('savePhotoBtn');
  if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
  const hint = document.getElementById('photoPendingHint');
  if(hint){ hint.style.display = ''; }
}

// No auto-upload: user must click Save

function setHeaderAvatar(url, initials){
  const buildImg = function(src){ const im = document.createElement('img'); im.alt='Profile'; if(typeof src === 'string'){ im.src = src; } im.decoding='async'; im.loading='eager'; return im; };
  const buildSpan = function(text){ const sp = document.createElement('span'); sp.textContent = text || ''; return sp; };
  const updateEl = function(el){ if(!el) return; while(el.firstChild){ el.removeChild(el.firstChild); } if(url && typeof url === 'string'){ el.appendChild(buildImg(url)); } else { el.appendChild(buildSpan(initials||'')); } };
  updateEl(document.getElementById('avatarToggle'));
  updateEl(document.querySelector('#auth-section .header-avatar'));
  document.querySelectorAll('.header-avatar').forEach(updateEl);
}

function uploadProfilePhoto(e){
  e.preventDefault();
  const form = document.getElementById('photoForm');
  const file = document.getElementById('photoInput').files[0];
  if(!file){ document.getElementById('photoInput').click(); return false; }
  const fd = new FormData(form);
  const actionUrl = form.getAttribute('action');
  const saveBtn = document.getElementById('savePhotoBtn');
  if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }
  fetch(actionUrl, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(async r=>{
      const t = await r.text();
      try { return JSON.parse(t); } catch(_){ return { success:false, message: t && t.trim() ? t.trim() : 'Invalid server response' }; }
    })
    .then(d=>{
      if(d && d.success && typeof d.path === 'string'){
        const base = (window.WEB_ROOT || '<?= WEB_ROOT ?>') + 'public/authenticated/';
        const path = d.path ? (base + d.path) : null;
        const persistedUrl = path ? (path + (path.indexOf('?') === -1 ? '?t=' : '&t=') + Date.now()) : null;
        const img = document.getElementById('profilePhoto');
        if(img && persistedUrl){ img.src = persistedUrl; }
        if(persistedUrl){
          setHeaderAvatar(persistedUrl);
          window.dispatchEvent(new CustomEvent('profile-photo-updated', { detail: { url: persistedUrl } }));
          setTimeout(()=>{ setHeaderAvatar(persistedUrl); }, 120);
        }
        if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = 'Saved'; setTimeout(()=>{ saveBtn.textContent='Save'; }, 1500); }
        const hint = document.getElementById('photoPendingHint');
        if(hint){ hint.style.display = 'none'; }
        const removeBtn = document.getElementById('removePhotoBtn');
        if(removeBtn){ removeBtn.style.display = ''; }
        showNotification('Profile photo updated.', 'success');
      }
      else {
        if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
        showNotification((d && d.message) || 'Upload failed.', 'error');
      }
    })
    .catch(()=>{ if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; } showNotification('Upload error.','error'); });
  return false;
}

async function removeProfilePhoto(){
  if(!(await uiConfirm('Remove your profile photo?'))) return;
  const form = document.getElementById('photoForm');
  const fd = new FormData();
  fd.append('action','remove');
  const actionUrl = form.getAttribute('action');
  fetch(actionUrl, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r=>r.json())
    .then(d=>{
      if(d.success){
        const container = document.getElementById('avatarContainer');
        container.innerHTML = '<span id="profileInitials"><?= $initials ?></span>';
        setHeaderAvatar(null, '<?= $initials ?>');
        const saveBtn = document.getElementById('savePhotoBtn');
        if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = 'Save'; }
        const hint = document.getElementById('photoPendingHint');
        if(hint){ hint.style.display = 'none'; }
        const photoInput = document.getElementById('photoInput');
        if(photoInput){ photoInput.value = ''; }
        const removeBtn = document.getElementById('removePhotoBtn');
        if(removeBtn){ removeBtn.style.display = 'none'; }
        showNotification('Profile photo removed.','success');
      } else {
        showNotification(d.message || 'Remove failed.','error');
      }
    })
    .catch(()=>showNotification('Request error.','error'));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
