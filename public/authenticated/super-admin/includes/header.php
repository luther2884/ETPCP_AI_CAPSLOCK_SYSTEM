<?php
// Super Admin header.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../../config/functions.php';
$isAdminTheme = true;

// Inactivity auto-lock for the Owner panel (see api/owner_lock.php). Same
// component as the admin panel's lock, against this panel's own session
// bucket and its own flag - the two locks are independent, which matters
// because a Super Admin can have both panels open at once via
// switch_to_admin.php.
//
// The flag is server-side session state, not just a client JS timer, so a
// reload or a brand-new tab opened while the owner is away still comes up
// locked instead of bypassing it. Rendered pre-locked here on page load; the
// JS below handles arming and triggering the lock during an already-open
// session.
$__ownerIsLocked = isLoggedIn() && isSuperAdmin() && !empty($_SESSION['owner_locked']);
// Why it locked, so the overlay doesn't tell an owner who just pressed the
// sidebar lock button that their session timed out on them.
$__ownerLockReason = ($_SESSION['owner_lock_reason'] ?? 'idle') === 'manual' ? 'manual' : 'idle';

// Whose password unlocks this screen, named on the overlay. The Super Admin
// signs in against the SUPER_ADMIN_PASSWORD constant rather than a users-row
// hash (super-admin/login_check.php), and api/owner_lock.php verifies against
// whichever of the two actually applies - but the screen still has to say
// which account it is asking for, or the wrong password gets typed and a
// correct rejection looks like a broken lock.
$__ownerLockName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
$__ownerLockEmail = trim((string)($_SESSION['email'] ?? ''));
if ($__ownerLockEmail === '' && isLoggedIn() && isSuperAdmin()) {
    // Resolve and cache it once rather than querying on every page load.
    try {
        $__ownerLockStmt = getConnection()->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $__ownerLockStmt->execute([getUserId()]);
        $__ownerLockEmail = (string)$__ownerLockStmt->fetchColumn();
        if ($__ownerLockEmail !== '') { $_SESSION['email'] = $__ownerLockEmail; }
    } catch (Throwable $e) {
        $__ownerLockEmail = '';
    }
}
$__ownerLockInitials = strtoupper(mb_substr($_SESSION['firstname'] ?? 'O', 0, 1) . mb_substr($_SESSION['lastname'] ?? 'W', 0, 1));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle ?? 'Owner - TechWorld') ?></title>

  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('public/shared/admin.css') ?>">
  <?php /* Phone-only bottom navigation for this panel - replaces the left
           drawer below 768px. Must load after admin.css: it overrides the
           shared .admin-sidebar* rules rather than duplicating them. */ ?>
  <link rel="stylesheet" href="<?= asset_url('public/shared/assets/css/owner-bottomnav.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
  <script src="<?= asset_url('public/shared/assets/js/main.js') ?>"></script>
  <style>
  /* Pin footer to bottom on short pages (match admin index behavior) */
  html, body.admin { height: 100%; }
  body.admin { display: flex; flex-direction: column; }
  main.auth-page, main.page, main.container { flex: 1; }
  .super-admin-footer { margin-top: auto; }
  </style>
</head>
<body class="admin sa">
<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>

<?php /* Page-entry motion for every card on the page - the same partial the
         admin panel uses, so a new card class only has to be added once. */ ?>
<?php include_once __DIR__ . '/../../../shared/includes/page-enter-motion.php'; ?>

<!-- Brand, user identity, and logout now live in the left sidebar
     (includes/navigation.php), same as the admin panel and the customer
     panel's sidebar profile block - the top header bar is gone. -->

<?php if (isLoggedIn() && isSuperAdmin()): ?>
<!-- Owner inactivity auto-lock overlay. Covers the entire viewport above
     every other owner modal (z-index 999999) and, while shown, everything
     else in <body> is made `inert` (see JS below) so a stray Tab+Enter can't
     reach a hidden control behind it - the overlay isn't just visual.

     Guarded by the same condition api/owner_lock.php enforces, so it is never
     rendered on a page that has nothing to unlock. index.php includes this
     header as its login page, but redirects a signed-in owner away before
     reaching here, so the overlay never appears over a login form. -->
<style>[inert]{pointer-events:none!important;user-select:none!important;}</style>
<div id="ownerLockOverlay" style="position:fixed;inset:0;z-index:999999;background:rgba(10,20,35,0.92);backdrop-filter:blur(14px);align-items:center;justify-content:center;padding:20px;display:<?= $__ownerIsLocked ? 'flex' : 'none' ?>;">
  <div style="max-width:380px;width:100%;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);padding:32px 28px;text-align:center;">
    <div style="font-size:44px;color:var(--accent-cyan);margin-bottom:12px;"><i class="fi fi-rr-lock"></i></div>
    <h2 style="color:var(--admin-text);font-size:20px;font-weight:700;margin:0 0 8px;">Owner Panel Locked</h2>
    <p id="ownerLockReason" style="color:var(--admin-muted);font-size:14px;margin:0 0 16px;"><?= $__ownerLockReason === 'manual' ? 'You locked this panel. Enter your password to return.' : 'Your session was automatically locked due to inactivity.' ?></p>

    <!-- Which account's password - see the note in the PHP block above. -->
    <div style="display:flex;align-items:center;gap:10px;text-align:left;padding:10px 12px;margin:0 0 14px;border-radius:var(--radius-sm);background:var(--admin-surface);box-shadow:var(--neu-pressed);">
      <span style="flex:0 0 auto;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--admin-accent),var(--admin-primary-2));color:#fff;font-size:12px;font-weight:700;">
        <?= htmlspecialchars($__ownerLockInitials) ?>
      </span>
      <span style="min-width:0;display:flex;flex-direction:column;gap:1px;">
        <span style="color:var(--admin-muted);font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Unlock as</span>
        <strong style="color:var(--admin-text);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($__ownerLockName !== '' ? $__ownerLockName : 'Your account') ?></strong>
        <?php if ($__ownerLockEmail !== ''): ?>
          <span style="color:var(--admin-muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($__ownerLockEmail) ?></span>
        <?php endif; ?>
      </span>
    </div>

    <form id="ownerUnlockForm" style="display:grid;gap:10px;text-align:left;">
      <input type="password" id="ownerUnlockPassword" placeholder="Your password" autocomplete="current-password" class="admin-form-input" style="width:100%;box-sizing:border-box;" required>
      <p id="ownerUnlockError" style="display:none;color:var(--admin-danger);font-size:13px;margin:0;"></p>
      <button type="submit" id="ownerUnlockBtn" class="admin-quick-link" style="border:none;padding:12px;cursor:pointer;font-weight:700;margin:0;width:100%;">Unlock</button>
    </form>
  </div>
</div>

<script>
(function () {
  var INACTIVITY_LIMIT_MS = 5 * 60 * 1000; // 5 minutes - same as the admin panel
  var overlay = document.getElementById('ownerLockOverlay');
  var form = document.getElementById('ownerUnlockForm');
  var passwordInput = document.getElementById('ownerUnlockPassword');
  var errorEl = document.getElementById('ownerUnlockError');
  var unlockBtn = document.getElementById('ownerUnlockBtn');
  var lockApi = '<?= WEB_ROOT ?>public/authenticated/api/owner_lock.php';
  var isLocked = <?= $__ownerIsLocked ? 'true' : 'false' ?>;
  var idleTimer = null;
  var reasonEl = document.getElementById('ownerLockReason');
  var REASON_TEXT = {
    idle: 'Your session was automatically locked due to inactivity.',
    manual: 'You locked this panel. Enter your password to return.'
  };

  function setInert(locked) {
    // Disables click/focus/tab on everything except the overlay while locked.
    // Covering it visually is not enough on its own - the point of the lock is
    // that no owner function can be reached, not that it is hard to see.
    Array.prototype.forEach.call(document.body.children, function (el) {
      if (el === overlay) { return; }
      if (locked) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); }
    });
  }

  // A <dialog> opened with showModal() lives in the browser's TOP LAYER, which
  // paints above every z-index in the document - including this overlay's
  // 999999. This panel has two of them: the More sheet on phones
  // (includes/navigation.php) and the shared confirm dialog
  // (shared/includes/ui-dialog.php, which the Logout link uses). Without this,
  // locking while one was open would leave it floating on top of the lock
  // screen, still clickable. Closing resolves uiConfirm()'s promise as a
  // cancel, which is the safe answer to every prompt this panel asks.
  function closeTopLayerDialogs() {
    Array.prototype.forEach.call(document.querySelectorAll('dialog[open]'), function (d) {
      try { d.close(); } catch (e) { d.removeAttribute('open'); }
    });
  }

  function showLock(reason) {
    isLocked = true;
    if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
    if (reasonEl && REASON_TEXT[reason]) { reasonEl.textContent = REASON_TEXT[reason]; }
    closeTopLayerDialogs();
    overlay.style.display = 'flex';
    setInert(true);
    document.body.style.overflow = 'hidden';
    errorEl.style.display = 'none';
    passwordInput.value = '';
    setTimeout(function () { passwordInput.focus(); }, 50);
  }

  function hideLock() {
    isLocked = false;
    overlay.style.display = 'none';
    setInert(false);
    document.body.style.overflow = '';
    resetIdleTimer();
  }

  // reason is 'idle' (the timer elapsed) or 'manual' (the sidebar lock button).
  // The overlay goes up first and the server is told after: the point of the
  // button is that the panel is gone the instant it is pressed, so nothing
  // here waits on the network. The POST is what makes the lock hold across
  // tabs and reloads; if it fails, this tab is still locked and still needs
  // the password - only the other tabs miss out.
  function triggerLock(reason) {
    if (isLocked) { return; }
    reason = reason === 'manual' ? 'manual' : 'idle';
    showLock(reason);
    fetch(lockApi + '?action=lock&reason=' + reason, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
  }

  // Manual lock. Delegated from document because navigation.php - which owns
  // the buttons - is included after this script runs. Both the sidebar button
  // and the phone More sheet's row carry the same attribute, so one handler
  // covers both shapes of the nav.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-owner-lock-now]') : null;
    if (!btn) { return; }
    e.preventDefault();
    triggerLock('manual');
  });
  // The overlay is modal, so there is no in-page shortcut that could collide:
  // Ctrl/Cmd+Shift+L locks from the keyboard without reaching for the sidebar.
  // Same binding the admin panel uses, so the habit carries between them.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'L' || e.key === 'l')) {
      e.preventDefault();
      triggerLock('manual');
    }
  });

  function resetIdleTimer() {
    if (isLocked) { return; }
    if (idleTimer) { clearTimeout(idleTimer); }
    idleTimer = setTimeout(function () { triggerLock('idle'); }, INACTIVITY_LIMIT_MS);
  }

  // Mouse and keyboard activity; click/wheel/touch included as the trackpad
  // and touchscreen equivalents.
  ['mousemove', 'mousedown', 'click', 'keydown', 'keypress', 'wheel', 'touchstart'].forEach(function (evt) {
    document.addEventListener(evt, resetIdleTimer, { passive: true });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var password = passwordInput.value;
    if (!password) { return; }
    unlockBtn.disabled = true;
    unlockBtn.textContent = 'Unlocking...';
    fetch(lockApi + '?action=unlock', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: 'password=' + encodeURIComponent(password)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        unlockBtn.disabled = false;
        unlockBtn.textContent = 'Unlock';
        if (data.success) {
          hideLock();
        } else {
          errorEl.textContent = data.message || 'Incorrect password. Please try again.';
          errorEl.style.display = 'block';
          passwordInput.value = '';
          passwordInput.focus();
        }
      })
      .catch(function () {
        unlockBtn.disabled = false;
        unlockBtn.textContent = 'Unlock';
        errorEl.textContent = 'Could not reach the server. Please try again.';
        errorEl.style.display = 'block';
      });
  });

  // Poll for a lock triggered from another tab so an already-open, idle tab
  // picks it up too, not just tabs that get reloaded.
  setInterval(function () {
    if (isLocked) { return; }
    fetch(lockApi + '?action=status', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { if (data.success && data.locked) { showLock(data.reason); } })
      .catch(function () {});
  }, 30000);

  if (isLocked) {
    setInert(true);
    document.body.style.overflow = 'hidden';
    // ...and again once the document is complete. This script is inline in
    // <head>'s sibling position at the TOP of <body>, so when a page loads
    // already locked, the only body children that exist on this line are the
    // overlay itself and a couple of <script> tags - the sidebar, the bottom
    // nav, <main> and the footer are all parsed afterwards and would never
    // have been marked. Covering them visually is not the same as taking them
    // out of the tab order: without this pass a keyboard user could Tab
    // straight past the lock screen into the panel behind it.
    document.addEventListener('DOMContentLoaded', function () {
      if (isLocked) { setInert(true); setTimeout(function () { passwordInput.focus(); }, 50); }
    });
  } else {
    resetIdleTimer();
  }
})();
</script>
<?php endif; ?>

<?php /* In-page confirm()/alert() replacements - see the file itself for
         how to use data-confirm and uiConfirm(). Included here so every page
         in this area gets them without a per-page include. */ ?>
<?php include_once __DIR__ . '/../../../shared/includes/ui-dialog.php'; ?>
