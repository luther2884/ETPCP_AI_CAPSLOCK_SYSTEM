<?php
// Admin header.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../../config/functions.php';
$isAdminTheme = true;
// Inactivity auto-lock (see api/admin_lock.php): the flag is server-side
// session state, not just a client JS timer, so a reload or a brand-new
// tab opened while the admin is away still comes up locked instead of
// bypassing it. Rendered pre-locked here on page load; the JS below
// handles arming/triggering the lock during an already-open session.
$__adminIsLocked = isLoggedIn() && isAdmin() && !empty($_SESSION['admin_locked']);
// Why it locked, so the overlay doesn't tell an admin who just pressed the
// sidebar lock button that their session timed out on them.
$__adminLockReason = ($_SESSION['admin_lock_reason'] ?? 'idle') === 'manual' ? 'manual' : 'idle';

// Whose password actually unlocks this screen.
//
// In Admin Mode - a Super Admin driving the admin panel via
// super-admin/switch_to_admin.php - the session deliberately keeps the SUPER
// ADMIN's user_id and only rewrites user_role to 'admin' (so the sidebar shows
// who is really driving). api/admin_lock.php then verifies the password
// against THAT account, which is correct: the person at the keyboard is the
// Super Admin, and accepting some other account's password would be a hole.
//
// What was missing is that the screen never said so. It reads "Enter your
// password" on a panel that looks like the admin panel, so the admin account's
// password gets typed and is correctly rejected - which looks exactly like a
// broken lock. Naming the account is the fix.
$__adminSaMode = !empty($_SESSION['sa_mode']);
$__adminLockName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
$__adminLockEmail = trim((string)($_SESSION['email'] ?? ''));
if ($__adminLockEmail === '' && isLoggedIn() && isAdmin()) {
    // switch_to_admin.php carries the email across, but only if the Super
    // Admin's own login put one in the session. Resolve and cache it once
    // rather than querying on every admin page load.
    try {
        $__lockStmt = getConnection()->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $__lockStmt->execute([getUserId()]);
        $__adminLockEmail = (string)$__lockStmt->fetchColumn();
        $_SESSION['email'] = $__adminLockEmail;
    } catch (Throwable $e) {
        $__adminLockEmail = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>       
  <title><?= htmlspecialchars($pageTitle ?? 'Admin - TechWorld') ?></title>

  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('public/shared/admin.css') ?>">
  <?php /* The flat admin skin. After admin.css on purpose - it re-points the
           neumorphic shadow variables for the page content, and has to win on
           load order. Everything in it is scoped to `html body.admin main`,
           so the sidebar (a sibling of <main>) and the customer area cannot
           match it. */ ?>
  <link rel="stylesheet" href="<?= asset_url('public/shared/admin-flat.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
  <script src="<?= asset_url('public/shared/assets/js/main.js') ?>"></script>
</head>
<body class="admin" style="min-height:100vh;display:flex;flex-direction:column;">
<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>

<?php /* Page-entry motion for every card on the page - shared with the
         super-admin panel, so both stay in step. */ ?>
<?php include_once __DIR__ . '/../../../shared/includes/page-enter-motion.php'; ?>

<!-- Brand, user identity, and logout now live in the left sidebar
     (includes/navigation.php), same as the customer panel's sidebar profile
     block - the top header bar is gone. -->

<?php if (isLoggedIn() && isAdmin()): ?>
<!-- Inactivity auto-lock overlay. Covers the entire viewport above every
     other admin modal (z-index 999999) and, while shown, everything else
     in <body> is made `inert` (see JS below) so a stray Tab+Enter can't
     reach a hidden button behind it - the overlay isn't just visual. -->
<style>[inert]{pointer-events:none!important;user-select:none!important;}</style>
<div id="adminLockOverlay" style="position:fixed;inset:0;z-index:999999;background:rgba(10,20,35,0.92);backdrop-filter:blur(14px);align-items:center;justify-content:center;padding:20px;display:<?= $__adminIsLocked ? 'flex' : 'none' ?>;">
  <div style="max-width:380px;width:100%;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);padding:32px 28px;text-align:center;">
    <div style="font-size:44px;color:var(--accent-cyan);margin-bottom:12px;"><i class="fi fi-rr-lock"></i></div>
    <h2 style="color:var(--admin-text);font-size:20px;font-weight:700;margin:0 0 8px;">System Locked</h2>
    <p id="adminLockReason" style="color:var(--admin-muted);font-size:14px;margin:0 0 16px;"><?= $__adminLockReason === 'manual' ? 'You locked this panel. Enter your password to return.' : 'Your session was automatically locked due to inactivity.' ?></p>

    <!-- Which account's password. Without this the screen is ambiguous in
         Admin Mode and the wrong password gets typed - see the note above. -->
    <div style="display:flex;align-items:center;gap:10px;text-align:left;padding:10px 12px;margin:0 0 14px;border-radius:var(--radius-sm);background:var(--admin-surface);box-shadow:var(--neu-pressed);">
      <span style="flex:0 0 auto;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--admin-accent),var(--admin-primary-2));color:#fff;font-size:12px;font-weight:700;">
        <?= htmlspecialchars(strtoupper(mb_substr($_SESSION['firstname'] ?? 'A', 0, 1) . mb_substr($_SESSION['lastname'] ?? '', 0, 1))) ?>
      </span>
      <span style="min-width:0;display:flex;flex-direction:column;gap:1px;">
        <span style="color:var(--admin-muted);font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Unlock as</span>
        <strong style="color:var(--admin-text);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($__adminLockName !== '' ? $__adminLockName : 'Your account') ?></strong>
        <?php if ($__adminLockEmail !== ''): ?>
          <span style="color:var(--admin-muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($__adminLockEmail) ?></span>
        <?php endif; ?>
      </span>
    </div>

    <?php if ($__adminSaMode): ?>
      <p style="text-align:left;margin:0 0 14px;padding:10px 12px;border-radius:var(--radius-sm);background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.30);color:var(--admin-text);font-size:12.5px;line-height:1.5;">
        You are in <strong>Admin Mode</strong> as a Super Admin. Enter your <strong>Super Admin</strong> password &mdash; not the admin account's.
      </p>
    <?php endif; ?>

    <form id="adminUnlockForm" style="display:grid;gap:10px;text-align:left;">
      <input type="password" id="adminUnlockPassword" placeholder="<?= $__adminSaMode ? 'Super Admin password' : 'Your password' ?>" autocomplete="current-password" class="admin-form-input" style="width:100%;box-sizing:border-box;" required>
      <p id="adminUnlockError" style="display:none;color:var(--admin-danger);font-size:13px;margin:0;"></p>
      <button type="submit" id="adminUnlockBtn" class="admin-quick-link" style="border:none;padding:12px;cursor:pointer;font-weight:700;margin:0;width:100%;">Unlock</button>
    </form>
  </div>
</div>

<script>
(function () {
  var INACTIVITY_LIMIT_MS = 5 * 60 * 1000; // 5 minutes
  var overlay = document.getElementById('adminLockOverlay');
  var form = document.getElementById('adminUnlockForm');
  var passwordInput = document.getElementById('adminUnlockPassword');
  var errorEl = document.getElementById('adminUnlockError');
  var unlockBtn = document.getElementById('adminUnlockBtn');
  var lockApi = '<?= WEB_ROOT ?>public/authenticated/api/admin_lock.php';
  var isLocked = <?= $__adminIsLocked ? 'true' : 'false' ?>;
  var idleTimer = null;
  var reasonEl = document.getElementById('adminLockReason');
  var REASON_TEXT = {
    idle: 'Your session was automatically locked due to inactivity.',
    manual: 'You locked this panel. Enter your password to return.'
  };

  function setInert(locked) {
    // Disables click/focus/tab on everything except the overlay while
    // locked - covering it visually isn't enough on its own to satisfy
    // "prevent the user from accessing, clicking, or interacting with any
    // admin functions while locked".
    Array.prototype.forEach.call(document.body.children, function (el) {
      if (el === overlay) { return; }
      if (locked) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); }
    });
  }

  function showLock(reason) {
    isLocked = true;
    if (reasonEl && REASON_TEXT[reason]) { reasonEl.textContent = REASON_TEXT[reason]; }
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
  // the button - is included after this script runs, and because the button
  // lives in a sidebar that some pages re-render.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-admin-lock-now]') : null;
    if (!btn) { return; }
    e.preventDefault();
    triggerLock('manual');
  });
  // The overlay is modal, so there is no in-page shortcut that could collide:
  // Ctrl/Cmd+Shift+L locks from the keyboard without reaching for the sidebar.
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

  // Mouse and keyboard activity, per the spec - click/wheel/touch included
  // as reasonable extensions of "mouse activity" for trackpads/tablets.
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

  // Poll for a lock triggered from another tab so an already-open, idle
  // tab picks it up too, not just tabs that get reloaded.
  setInterval(function () {
    if (isLocked) { return; }
    fetch(lockApi + '?action=status', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { if (data.success && data.locked) { showLock(data.reason); } })
      .catch(function () {});
  }, 30000);

  if (isLocked) { setInert(true); document.body.style.overflow = 'hidden'; }
  else { resetIdleTimer(); }
})();
</script>
<?php endif; ?>

<script>
// Update pending orders badge
function updatePendingOrdersBadge() {
  fetch('<?= WEB_ROOT ?>public/authenticated/api/orders.php?action=pending_count', {
    method: 'GET',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(response => response.json())
  .then(data => {
    const badge = document.getElementById('pendingOrdersBadge');
    if (badge && data.success) {
      if (data.count > 0) {
        badge.textContent = data.count > 99 ? '99+' : data.count;
        badge.style.display = 'flex';
      } else {
        badge.style.display = 'none';
      }
    }
  })
  .catch(error => {
    console.log('Error updating pending orders badge:', error);
  });
}

// Home delivery orders badge - same polling pattern as the one above, for
// pages that show a "Home Delivery Orders" link/card (OrdMan.php,
// dashboard.php) and want the admin to notice new ones without having to
// click in. Harmless no-op on pages with no #homeDeliveryBadge element.
function updateHomeDeliveryBadge() {
  var badges = document.querySelectorAll('.home-delivery-badge');
  if (!badges.length) { return; }
  fetch('<?= WEB_ROOT ?>public/authenticated/api/orders.php?action=home_delivery_pending_count', {
    method: 'GET',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(response) { return response.json(); })
  .then(function(data) {
    if (!data.success) { return; }
    badges.forEach(function(badge) {
      if (data.count > 0) {
        badge.textContent = data.count > 99 ? '99+' : data.count;
        badge.style.display = 'inline-flex';
      } else {
        badge.style.display = 'none';
      }
    });
  })
  .catch(function(error) { console.log('Error updating home delivery badge:', error); });
}

// Store pickup orders badge - same pattern as updateHomeDeliveryBadge()
// above, for pages that show a "Store Pickup Orders" link/card. Harmless
// no-op on pages with no .store-pickup-badge element.
function updateStorePickupBadge() {
  var badges = document.querySelectorAll('.store-pickup-badge');
  if (!badges.length) { return; }
  fetch('<?= WEB_ROOT ?>public/authenticated/api/orders.php?action=store_pickup_pending_count', {
    method: 'GET',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(response) { return response.json(); })
  .then(function(data) {
    if (!data.success) { return; }
    badges.forEach(function(badge) {
      if (data.count > 0) {
        badge.textContent = data.count > 99 ? '99+' : data.count;
        badge.style.display = 'inline-flex';
      } else {
        badge.style.display = 'none';
      }
    });
  })
  .catch(function(error) { console.log('Error updating store pickup badge:', error); });
}

// Update badges on page load and every 30 seconds
document.addEventListener('DOMContentLoaded', function() {
  updatePendingOrdersBadge();
  updateHomeDeliveryBadge();
  updateStorePickupBadge();
  setInterval(updatePendingOrdersBadge, 30000);
  setInterval(updateHomeDeliveryBadge, 30000);
  setInterval(updateStorePickupBadge, 30000);
});
</script>

<?php
// ---------------------------------------------------------------------------
// New-order alert.
//
// What used to sit here was a sign-in chime: a clip that played once per
// admin session on the first page after logging in. It announced the one
// event the admin was already certain of - their own login - and said
// nothing when something actually happened.
//
// It is replaced by an alert for the event worth hearing: a customer placing
// an order. That one is real-time (SSE, see api/admin_order_stream.php), so
// it reaches an admin who is mid-task on another page or another tab without
// anything being refreshed.
include_once __DIR__ . '/new_order_alert.php';
?>
<?php /* In-page confirm()/alert() replacements - see the file itself for
         how to use data-confirm and uiConfirm(). Included here so every page
         in this area gets them without a per-page include. */ ?>
<?php include_once __DIR__ . '/../../../shared/includes/ui-dialog.php'; ?>
<?php /* window.TWCamera - why the camera is unavailable, said the same way
         everywhere. Included here so every page in this area has it without a
         per-page include, the same as ui-dialog above. */ ?>
<?php include_once __DIR__ . '/../../../shared/includes/camera-support.php'; ?>
