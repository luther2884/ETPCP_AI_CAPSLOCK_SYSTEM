<?php
// WEB_ROOT will be defined by config/functions.php
require_once __DIR__ . '/../../../../config/functions.php';

// Allow pages (like index/login) to hide this navigation entirely
if (!empty($hideNav)) { return; }

// Brand + user identity + logout live here, same pattern as the admin
// sidebar (admin/includes/navigation.php) and the customer sidebar's
// profile block (customer/includes/sidebar.php) - the top header bar that
// used to hold these was removed.
$__onLoggedIn = function_exists('isLoggedIn') ? isLoggedIn() : isset($_SESSION['user_id']);
$__onFullName = trim((($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')));
$__onDisplayName = $__onFullName !== '' ? $__onFullName : ($_SESSION['username'] ?? 'Owner');

// Profile photo avatar next to the name - same pattern as admin/includes/navigation.php.
$__onUid = function_exists('getUserId') ? getUserId() : ($_SESSION['user_id'] ?? null);
$__onAvatarRel = null;
if (!empty($_SESSION['profile_photo_rel'])) {
    $__onAvatarRel = $_SESSION['profile_photo_rel'];
} elseif ($__onUid && function_exists('resolveProfilePhotoPath')) {
    if (function_exists('userHasAnyProfilePhoto') && userHasAnyProfilePhoto($__onUid)) {
        $__onAvatarRel = resolveProfilePhotoPath($__onUid);
        if ($__onAvatarRel) { $_SESSION['profile_photo_rel'] = $__onAvatarRel; }
    }
}
$__onInitials = strtoupper(substr($_SESSION['firstname'] ?? 'O', 0, 1) . substr($_SESSION['lastname'] ?? 'W', 0, 1));
?>
<!-- Owner Navigation - left side pull-tab drawer. Reuses the exact same
     .admin-menu-toggle/.admin-sidebar* classes (and CSS/JS) as the admin
     panel's sidebar (admin/includes/navigation.php) - both live in
     admin.css - so Admin Panel and Owner Panel share one identical
     component instead of two hand-maintained copies. -->
<button type="button" class="admin-menu-toggle" id="adminMenuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="adminSidebar">
  <span class="admin-menu-toggle-bar"></span>
  <span class="admin-menu-toggle-bar"></span>
  <span class="admin-menu-toggle-bar"></span>
</button>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
<aside class="admin-sidebar" id="adminSidebar">
  <?php /* Brand + the manual lock, same arrangement as the admin sidebar
           (admin/includes/navigation.php). The lock sits here rather than in
           the nav list below because it is not a destination - it is the one
           control that takes the panel away, so it belongs with the panel's
           identity, not among the pages. The idle timer in includes/header.php
           locks the same way after 5 minutes; this is the same lock on demand,
           for an owner stepping away from the desk who does not want to wait
           it out.

           .admin-sidebar-head / .admin-sidebar-lock are already defined in
           shared/admin.css (which this panel loads) and already have their
           collapsed-rail handling there - the lock drops out of the 72px rail
           and returns when it hover-expands - so no CSS is added for this. */ ?>
  <div class="admin-sidebar-head">
    <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/dashboard.php" class="admin-sidebar-brand">
      <img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="TechWorld" class="admin-sidebar-logo">
      <span class="admin-sidebar-brand-text">
        <strong>TechWorld</strong>
        <span>Owner Panel</span>
      </span>
    </a>
    <?php /* Same condition header.php uses to render the lock overlay - the
             button must not appear on a page that has nothing to show when it
             is pressed. */ ?>
    <?php if ($__onLoggedIn && function_exists('isSuperAdmin') && isSuperAdmin()): ?>
      <button type="button" class="admin-sidebar-lock" data-owner-lock-now
              title="Lock the panel now (unlocks with your password)"
              aria-label="Lock the panel now">
        <i class="fi fi-rr-lock" aria-hidden="true"></i>
      </button>
    <?php endif; ?>
  </div>

  <nav class="admin-sidebar-nav">
    <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/dashboard.php" class="action-btn btn-muted <?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/dashboard.gif" alt="" aria-hidden="true"> <span>Dashboard</span>
    </a>
    <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/requests.php" class="action-btn btn-muted <?= ($activeNav ?? '') === 'requests' ? 'active' : '' ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/manager-accounts.gif" alt="" aria-hidden="true"> <span>Employee Account</span>
    </a>
    <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/pullout_approvals.php" class="action-btn btn-muted <?= ($activeNav ?? '') === 'pullouts' ? 'active' : '' ?>">
      <i class="fi fi-rr-exclamation-triangle" aria-hidden="true"></i> <span>Pull-Out Approvals</span>
    </a>
    <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/audit_logs.php" class="action-btn btn-muted <?= ($activeNav ?? '') === 'audit' ? 'active' : '' ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/audit.gif" alt="" aria-hidden="true"> <span>Audit Logs</span>
    </a>
    <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/sales_logs.php" class="action-btn btn-muted <?= ($activeNav ?? '') === 'sales' ? 'active' : '' ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/sales-logs.gif" alt="" aria-hidden="true"> <span>Sales Logs</span>
    </a>
    <!-- Email Settings is deliberately NOT linked here. The page itself still
         exists and is still owner-gated - reach it by URL
         (super-admin/email_settings.php) when the sending account has to
         change. It is a set-once config screen, not day-to-day owner work, so
         it does not earn a permanent slot in the nav. -->
    <!-- What the AI features are costing, the caps in force, and the bill as
         the store grows. Owner-only: it is a spending page. -->
    <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/ai_usage.php" class="action-btn btn-muted <?= ($activeNav ?? '') === 'ai_usage' ? 'active' : '' ?>">
      <i class="fi fi-rr-chart-histogram" aria-hidden="true" style="width:20px;font-size:16px;text-align:center;flex-shrink:0;"></i> <span>AI Usage &amp; Cost</span>
    </a>
  </nav>

  <?php if ($__onLoggedIn): ?>
  <!-- Profile block sits at the very bottom of the panel, directly above
       Logout - same "fixed footer" positioning as the customer sidebar
       (customer/includes/sidebar.php) and admin/includes/navigation.php. -->
  <div class="admin-sidebar-profile">
    <div class="admin-sidebar-avatar">
      <?php if ($__onAvatarRel): ?>
        <img src="<?= WEB_ROOT ?>public/authenticated/<?= $__onAvatarRel ?>" alt="Profile">
      <?php else: ?>
        <span><?= htmlspecialchars($__onInitials) ?></span>
      <?php endif; ?>
    </div>
    <span class="admin-sidebar-name" title="<?= htmlspecialchars($__onDisplayName) ?>"><?= htmlspecialchars($__onDisplayName) ?></span>
  </div>
  <div class="admin-sidebar-divider"></div>
  <a href="<?= WEB_ROOT ?>public/authenticated/auth/logout.php?ns=TECHWRLD_SA&redirect=public/authenticated/super-admin/" class="admin-sidebar-logout" data-confirm="Are you sure you want to log out?" data-confirm-ok="Log out" >
    <i class="fi fi-bs-sign-out"></i> <span>Logout</span>
  </a>
  <?php endif; ?>
</aside>

<!-- Phone navigation (<=768px). The sidebar above is hidden at that width by
     owner-bottomnav.css and this takes its place: the same four sections as
     thumb-reachable tabs, with the panel's identity and Logout behind More so
     nothing the drawer carried becomes unreachable on a phone. The links are
     the same hrefs and the same $activeNav values - one nav, two shapes. -->
<nav class="sa-bottomnav" aria-label="Owner sections">
  <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/dashboard.php" class="sa-bn-item <?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>"<?= ($activeNav ?? '') === 'dashboard' ? ' aria-current="page"' : '' ?>>
    <i class="fi fi-rr-apps" aria-hidden="true"></i><span>Dashboard</span>
  </a>
  <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/requests.php" class="sa-bn-item <?= ($activeNav ?? '') === 'requests' ? 'active' : '' ?>"<?= ($activeNav ?? '') === 'requests' ? ' aria-current="page"' : '' ?>>
    <i class="fi fi-rr-users" aria-hidden="true"></i><span>Accounts</span>
  </a>
  <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/audit_logs.php" class="sa-bn-item <?= ($activeNav ?? '') === 'audit' ? 'active' : '' ?>"<?= ($activeNav ?? '') === 'audit' ? ' aria-current="page"' : '' ?>>
    <i class="fi fi-rr-list-check" aria-hidden="true"></i><span>Audit</span>
  </a>
  <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/sales_logs.php" class="sa-bn-item <?= ($activeNav ?? '') === 'sales' ? 'active' : '' ?>"<?= ($activeNav ?? '') === 'sales' ? ' aria-current="page"' : '' ?>>
    <i class="fi fi-rr-chart-histogram" aria-hidden="true"></i><span>Sales</span>
  </a>
  <button type="button" class="sa-bn-item" id="saMoreBtn" aria-haspopup="dialog" aria-expanded="false" aria-controls="saMoreSheet">
    <i class="fi fi-rr-menu-burger" aria-hidden="true"></i><span>More</span>
  </button>
</nav>

<!-- More: the foot of the sidebar (identity + Logout) as a bottom sheet. A
     <dialog>, so it sits in the browser's top layer above the fixed bar with
     no z-index arithmetic, and gets Escape plus focus trapping for free. -->
<dialog class="sa-sheet" id="saMoreSheet" aria-label="More owner options">
  <div class="sa-sheet-grip" aria-hidden="true"></div>
  <p class="sa-sheet-title">Panel</p>
  <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/ai_usage.php" class="admin-sidebar-logout" style="color:var(--admin-text);">
    <i class="fi fi-rr-chart-histogram" aria-hidden="true"></i> <span>AI Usage &amp; Cost</span>
  </a>
  <?php if ($__onLoggedIn): ?>
    <div class="admin-sidebar-divider"></div>
    <p class="sa-sheet-title">Signed in</p>
    <div class="admin-sidebar-profile">
      <div class="admin-sidebar-avatar">
        <?php if ($__onAvatarRel): ?>
          <img src="<?= WEB_ROOT ?>public/authenticated/<?= $__onAvatarRel ?>" alt="Profile">
        <?php else: ?>
          <span><?= htmlspecialchars($__onInitials) ?></span>
        <?php endif; ?>
      </div>
      <span class="admin-sidebar-name" title="<?= htmlspecialchars($__onDisplayName) ?>"><?= htmlspecialchars($__onDisplayName) ?></span>
    </div>
    <div class="admin-sidebar-divider"></div>
    <?php /* The manual lock, again. The sidebar that carries it on desktop is
             display:none below 767px (owner-bottomnav.css), so without a row
             here the button would simply not exist on a phone - which is the
             device most likely to be put down mid-task. It shares the
             [data-owner-lock-now] hook with the sidebar button, so one
             delegated handler in includes/header.php drives both.

             Above Logout rather than below it: they are the two ways to leave
             the panel, and the reversible one should be the one the thumb
             reaches first. */ ?>
    <?php if (function_exists('isSuperAdmin') && isSuperAdmin()): ?>
      <button type="button" class="admin-sidebar-logout sa-sheet-action" data-owner-lock-now
              style="color:var(--admin-text);" aria-label="Lock the panel now">
        <i class="fi fi-rr-lock" aria-hidden="true"></i> <span>Lock panel</span>
      </button>
    <?php endif; ?>
    <a href="<?= WEB_ROOT ?>public/authenticated/auth/logout.php?ns=TECHWRLD_SA&redirect=public/authenticated/super-admin/" class="admin-sidebar-logout" data-confirm="Are you sure you want to log out?" data-confirm-ok="Log out">
      <i class="fi fi-bs-sign-out"></i> <span>Logout</span>
    </a>
  <?php endif; ?>
</dialog>

<!-- Sidebar CSS lives in admin.css (shared with admin/includes/navigation.php) -->

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
    if (focusFirst) {
      var firstLink = sidebar.querySelector('a');
      if (firstLink) firstLink.focus();
    }
  }

  function closeMenu(returnFocus) {
    document.body.classList.remove('admin-menu-open');
    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.setAttribute('aria-label', 'Open navigation menu');
    if (returnFocus) toggleBtn.focus();
  }

  toggleBtn.addEventListener('click', function () {
    if (isOpen()) { closeMenu(false); } else { openMenu(true); }
  });

  backdrop.addEventListener('click', function () { closeMenu(false); });

  // Same hover-to-open / grace-delay-close behavior as the customer sidebar.
  var hoverCloseTimer = null;
  function cancelHoverClose() {
    if (hoverCloseTimer) { clearTimeout(hoverCloseTimer); hoverCloseTimer = null; }
  }
  function scheduleHoverClose() {
    cancelHoverClose();
    hoverCloseTimer = setTimeout(function () { closeMenu(false); }, 200);
  }

  toggleBtn.addEventListener('mouseenter', function () {
    cancelHoverClose();
    if (!isOpen()) { openMenu(false); }
  });
  toggleBtn.addEventListener('mouseleave', scheduleHoverClose);
  sidebar.addEventListener('mouseenter', cancelHoverClose);
  sidebar.addEventListener('mouseleave', scheduleHoverClose);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) { closeMenu(true); }
  });

  // Hover-to-expand icon rail (hover-capable devices only - see the
  // @media (hover:hover) block in admin.css, where the panel is collapsed
  // to ~68px by default there). Hovering the panel expands it in place;
  // leaving it schedules a collapse after a short grace delay so moving
  // between icons doesn't flicker. Also responds to keyboard focus so
  // tabbing through it doesn't strand a keyboard user on icon-only,
  // unlabeled links. Same pattern as the customer sidebar
  // (customer/includes/sidebar.php).
  var hoverExpandTimer = null;
  function cancelHoverExpandClose() {
    if (hoverExpandTimer) { clearTimeout(hoverExpandTimer); hoverExpandTimer = null; }
  }
  function expandSidebarOnHover() {
    cancelHoverExpandClose();
    sidebar.classList.add('is-hover-expanded');
    document.body.classList.add('admin-sidebar-hover-expanded');
  }
  function scheduleSidebarHoverCollapse() {
    cancelHoverExpandClose();
    hoverExpandTimer = setTimeout(function () {
      sidebar.classList.remove('is-hover-expanded');
      document.body.classList.remove('admin-sidebar-hover-expanded');
    }, 180);
  }
  sidebar.addEventListener('mouseenter', expandSidebarOnHover);
  sidebar.addEventListener('mouseleave', scheduleSidebarHoverCollapse);
  sidebar.addEventListener('focusin', expandSidebarOnHover);
  sidebar.addEventListener('focusout', function (e) {
    if (!sidebar.contains(e.relatedTarget)) { scheduleSidebarHoverCollapse(); }
  });
})();
</script>

<script>
/* The bottom bar's More sheet. The bar itself is plain links and needs no JS;
   this is only the sheet. */
(function () {
  var btn = document.getElementById('saMoreBtn');
  var sheet = document.getElementById('saMoreSheet');
  if (!btn || !sheet) { return; }

  // Pre-2022 Safari has no showModal(); `open` still shows the sheet, just
  // without the top layer, and the CSS already positions it either way.
  var modal = typeof sheet.showModal === 'function';

  function setExpanded(v) { btn.setAttribute('aria-expanded', v ? 'true' : 'false'); }

  btn.addEventListener('click', function () {
    if (sheet.open) { sheet.close(); return; }
    if (modal) { sheet.showModal(); } else { sheet.setAttribute('open', ''); }
    setExpanded(true);
    var first = sheet.querySelector('a[href]');
    if (first) { first.focus(); }
  });

  // The sheet is its own scrim in the top layer, so a click landing on the
  // element itself (not on a child) landed outside it.
  sheet.addEventListener('click', function (e) { if (e.target === sheet) { sheet.close(); } });
  sheet.addEventListener('close', function () { setExpanded(false); btn.focus(); });
})();
</script>
