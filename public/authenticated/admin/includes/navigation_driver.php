<?php
// includes/navigation_driver.php - minimal sidebar for the driver (Logistics/
// Delivery Personnel) role. Copied from includes/navigation.php (same CSS
// classes/JS, so it looks and behaves identically) but with the nav list
// stripped to just Delivery Report - drivers have no access to Product/
// Customer/Order Management etc, so those links have no reason to exist
// here (require_role() would just bounce them if they clicked one anyway).
require_once __DIR__ . '/../../../../config/functions.php';

$__anLoggedIn = function_exists('isLoggedIn') ? isLoggedIn() : isset($_SESSION['user_id']);
$__anFullName = trim((($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')));
$__anDisplayName = $__anFullName !== '' ? $__anFullName : ($_SESSION['username'] ?? 'Delivery Personnel');

$__anUid = function_exists('getUserId') ? getUserId() : ($_SESSION['user_id'] ?? null);
$__anAvatarRel = null;
if (!empty($_SESSION['profile_photo_rel'])) {
    $__anAvatarRel = $_SESSION['profile_photo_rel'];
} elseif ($__anUid && function_exists('resolveProfilePhotoPath')) {
    if (function_exists('userHasAnyProfilePhoto') && userHasAnyProfilePhoto($__anUid)) {
        $__anAvatarRel = resolveProfilePhotoPath($__anUid);
        if ($__anAvatarRel) { $_SESSION['profile_photo_rel'] = $__anAvatarRel; }
    }
}
$__anInitials = strtoupper(substr($_SESSION['firstname'] ?? 'D', 0, 1) . substr($_SESSION['lastname'] ?? 'P', 0, 1));
?>
<button type="button" class="admin-menu-toggle" id="adminMenuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="adminSidebar">
  <span class="admin-menu-toggle-bar"></span>
  <span class="admin-menu-toggle-bar"></span>
  <span class="admin-menu-toggle-bar"></span>
</button>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
<aside class="admin-sidebar" id="adminSidebar">
  <div class="admin-sidebar-brand">
    <img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="TechWorld" class="admin-sidebar-logo">
    <span class="admin-sidebar-brand-text">
      <strong>TechWorld</strong>
      <span>Delivery Portal</span>
    </span>
  </div>

  <nav class="admin-sidebar-nav">
    <a href="<?= WEB_ROOT ?>public/authenticated/courier/dispatch.php" class="action-btn btn-muted active">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/delivery-shipped.gif" alt="" aria-hidden="true"> <span>Courier Dispatch</span>
    </a>
  </nav>

  <?php if ($__anLoggedIn): ?>
  <div class="admin-sidebar-profile">
    <div class="admin-sidebar-avatar">
      <?php if ($__anAvatarRel): ?>
        <img src="<?= WEB_ROOT ?>public/authenticated/<?= $__anAvatarRel ?>" alt="Profile">
      <?php else: ?>
        <span><?= htmlspecialchars($__anInitials) ?></span>
      <?php endif; ?>
    </div>
    <span class="admin-sidebar-name" title="<?= htmlspecialchars($__anDisplayName) ?>"><?= htmlspecialchars($__anDisplayName) ?></span>
  </div>
  <div class="admin-sidebar-divider"></div>
  <a href="<?= WEB_ROOT ?>public/authenticated/admin/logout.php" class="admin-sidebar-logout" data-confirm="Are you sure you want to log out?" data-confirm-ok="Log out" >
    <i class="fi fi-bs-sign-out"></i> <span>Logout</span>
  </a>
  <?php endif; ?>
</aside>

<script>
(function () {
  var toggleBtn = document.getElementById('adminMenuToggle');
  var sidebar = document.getElementById('adminSidebar');
  var backdrop = document.getElementById('adminSidebarBackdrop');
  if (!toggleBtn || !sidebar || !backdrop) return;

  function isOpen() { return document.body.classList.contains('admin-menu-open'); }
  function openMenu(focusFirst) {
    document.body.classList.add('admin-menu-open');
    toggleBtn.setAttribute('aria-expanded', 'true');
    toggleBtn.setAttribute('aria-label', 'Close navigation menu');
    if (focusFirst) { var firstLink = sidebar.querySelector('a'); if (firstLink) firstLink.focus(); }
  }
  function closeMenu(returnFocus) {
    document.body.classList.remove('admin-menu-open');
    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.setAttribute('aria-label', 'Open navigation menu');
    if (returnFocus) toggleBtn.focus();
  }
  toggleBtn.addEventListener('click', function () { if (isOpen()) { closeMenu(false); } else { openMenu(true); } });
  backdrop.addEventListener('click', function () { closeMenu(false); });

  var hoverCloseTimer = null;
  function cancelHoverClose() { if (hoverCloseTimer) { clearTimeout(hoverCloseTimer); hoverCloseTimer = null; } }
  function scheduleHoverClose() { cancelHoverClose(); hoverCloseTimer = setTimeout(function () { closeMenu(false); }, 200); }
  toggleBtn.addEventListener('mouseenter', function () { cancelHoverClose(); if (!isOpen()) { openMenu(false); } });
  toggleBtn.addEventListener('mouseleave', scheduleHoverClose);
  sidebar.addEventListener('mouseenter', cancelHoverClose);
  sidebar.addEventListener('mouseleave', scheduleHoverClose);

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen()) { closeMenu(true); } });

  var hoverExpandTimer = null;
  function cancelHoverExpandClose() { if (hoverExpandTimer) { clearTimeout(hoverExpandTimer); hoverExpandTimer = null; } }
  function expandSidebarOnHover() { cancelHoverExpandClose(); sidebar.classList.add('is-hover-expanded'); document.body.classList.add('admin-sidebar-hover-expanded'); }
  function scheduleSidebarHoverCollapse() { cancelHoverExpandClose(); hoverExpandTimer = setTimeout(function () { sidebar.classList.remove('is-hover-expanded'); document.body.classList.remove('admin-sidebar-hover-expanded'); }, 180); }
  sidebar.addEventListener('mouseenter', expandSidebarOnHover);
  sidebar.addEventListener('mouseleave', scheduleSidebarHoverCollapse);
  sidebar.addEventListener('focusin', expandSidebarOnHover);
  sidebar.addEventListener('focusout', function (e) { if (!sidebar.contains(e.relatedTarget)) { scheduleSidebarHoverCollapse(); } });
})();
</script>
