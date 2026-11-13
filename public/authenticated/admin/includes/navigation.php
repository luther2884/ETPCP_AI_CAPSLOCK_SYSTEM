<?php
// WEB_ROOT will be defined by config/functions.php
require_once __DIR__ . '/../../../../config/functions.php';

// Brand + user identity + logout now live here, same as the customer
// sidebar's profile block (customer/includes/sidebar.php) - the top header
// bar that used to hold these was removed.
$__anLoggedIn = function_exists('isLoggedIn') ? isLoggedIn() : isset($_SESSION['user_id']);
$__anFullName = trim((($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')));
$__anDisplayName = $__anFullName !== '' ? $__anFullName : ($_SESSION['username'] ?? 'Admin User');

// Profile photo avatar next to the name - same resolution pattern as the
// customer sidebar (customer/includes/sidebar.php): DB blob/path first,
// filesystem fallback, cached on the session so it isn't re-resolved on
// every request. Falls back to initials when no photo exists.
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
$__anInitials = strtoupper(substr($_SESSION['firstname'] ?? 'A', 0, 1) . substr($_SESSION['lastname'] ?? 'U', 0, 1));

// Set by super-admin/switch_to_admin.php: this admin session belongs to a
// Super Admin working as an Admin. Renders the bar below on every admin page
// so the way back is never more than one click away.
$__anSaMode = !empty($_SESSION['sa_mode']);

// ---------------------------------------------------------------------------
// Sidebar information architecture.
//
// One table drives the whole nav: which page belongs to which item, and which
// item belongs to which collapsible section. Adding a page means adding it to
// a $__anItems entry - nothing below has to change, and no second list of
// "which pages count as Orders" can drift out of sync with the markup.
//
// Pages that are ACTIONS rather than destinations (add_product.php,
// product_prices.php) deliberately map onto the item whose page launches them,
// so working on one keeps its section highlighted without earning a row of its
// own in the sidebar.
$__anPage = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
$__anView = strtolower((string)($_GET['view'] ?? ''));

$__anItems = [
    'dashboard'     => ['dashboard.php'],
    // Orders
    // scan_orders.php and the per-order status/label pages it links to live
    // here now: the scanner moved out of the sidebar into the floating button
    // present on every page (includes/scan_fab.php), so without this those
    // pages would highlight nothing at all.
    'all-orders'    => ['ordman.php', 'home_delivery_orders.php', 'store_pickup_orders.php',
                        'scan_orders.php', 'order_status.php', 'order_sticker.php'],
    'cancellations' => ['order_cancel_request.php'],
    // The packing-bench scanner, plus the per-order status/label page that it
    // and the order queues both link to.
    // Products - add_product.php and product_prices.php are buttons on the
    // products page, not nav items, so they light up "Products".
    'products'      => ['products.php', 'product_management.php', 'add_product.php', 'product_prices.php'],
    'categories'    => ['categories.php'],
    // Standalone
    'inventory'     => ['inventory_control.php', 'demand_forecast.php'],
    // The stock-movement pages. Registered here rather than relying on the
    // $activeNav legacy fallback, because this table is what an_active()
    // actually reads - a key that is not in it can never light up.
    'receive'       => ['receive_delivery.php'],
    'pullout'       => ['defective_pullout.php'],
    'movements'     => ['stock_movements.php'],
    'invreports'    => ['inventory_reports.php'],
    // The customer list. It is a child row now, not the section header, but
    // the item key is unchanged so both pages still light up the same row.
    'customers'     => ['cusman.php', 'view_customer.php'],
    'feedback'      => ['reviews.php'],
    'support'       => ['support_dashboard.php'],
    'promo'         => ['promotional_carousel.php'],
    'reports'       => ['repana.php', 'sales_report.php'],
    'analytics'     => [],
    'audit'         => ['audit_logs.php'],
];

// Which collapsible section each nested item lives in. Items absent from this
// map are top-level and never open a section.
$__anItemSection = [
    'all-orders'    => 'orders',
    'cancellations' => 'orders',
    'products'      => 'products',
    'categories'    => 'products',
    'customers'     => 'customers',
    'feedback'      => 'customers',
    'support'       => 'customers',
    'promo'         => 'marketing',
];

// Resolve the active item. reports_analytics.php serves Reports and Analytics
// from the same script and separates them by ?view=, so it is settled here
// rather than in the table above.
$__anActive = '';
if ($__anPage === 'reports_analytics.php') {
    $__anActive = $__anView === 'analytics' ? 'analytics' : 'reports';
} else {
    foreach ($__anItems as $__key => $__pages) {
        if (in_array($__anPage, $__pages, true)) { $__anActive = $__key; break; }
    }
}
// Legacy fallback: pages that set $activeNav but aren't in the table above.
if ($__anActive === '') {
    $__anLegacy = ['dashboard' => 'dashboard', 'orders' => 'all-orders', 'products' => 'products',
                   'customers' => 'customers', 'reports' => 'reports', 'promo_carousel' => 'promo'];
    $__anActive = $__anLegacy[$activeNav ?? ''] ?? '';
}

// Only the section holding the active page starts expanded (everything else
// stays collapsed until the admin opens it).
$__anOpenSection = $__anItemSection[$__anActive] ?? '';

/** Marks the row for the currently active item. Guarded because this file is
 *  an include, and a page that pulls it in twice would otherwise fatal on the
 *  redeclare rather than just rendering the nav twice. */
if (!function_exists('an_active')) {
    function an_active(string $item): string {
        global $__anActive;
        return $__anActive === $item ? ' active' : '';
    }
}

// Pending cancellation requests, shown as a badge on that row so the count
// sits on the thing it refers to rather than on the Orders parent. Rendered
// server-side: it's one indexed COUNT on a page that already queries, and it
// needs no polling endpoint the way the orders badge does.
$__anCancelCount = 0;
try {
    $__anCancelCount = (int)getConnection()
        ->query("SELECT COUNT(*) FROM order_cancellation_requests WHERE status = 'pending'")
        ->fetchColumn();
} catch (Throwable $__e) { $__anCancelCount = 0; }
?>
<?php if ($__anSaMode): ?>
<div class="admin-sa-mode-bar">
  <span>
    <i class="fi fi-bs-user-gear"></i>
    You are in <strong>Admin Mode</strong> as <?= htmlspecialchars($__anDisplayName) ?>. Actions apply an Admin's permissions.
  </span>
  <a href="<?= WEB_ROOT ?>public/authenticated/admin/exit_admin_mode.php">Return to Super Admin</a>
</div>
<style>
  .admin-sa-mode-bar{display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;
    padding:10px 20px;background:var(--admin-surface-2);color:var(--admin-text);
    border-bottom:1px solid var(--admin-border);box-shadow:var(--neu-raised-sm);
    font-size:13px;font-weight:600;}
  .admin-sa-mode-bar i{color:var(--accent-cyan);margin-right:4px;}
  .admin-sa-mode-bar a{display:inline-flex;align-items:center;padding:7px 14px;border-radius:var(--radius-sm);
    background:var(--admin-primary-2);color:#f2f3f4;text-decoration:none;font-weight:700;white-space:nowrap;}
  .admin-sa-mode-bar a:hover{filter:brightness(1.12);color:#f2f3f4;text-decoration:none;}
</style>
<?php endif; ?>
<!-- Admin Navigation - left side pull-tab drawer, same function as the
     customer sidebar (customer/includes/sidebar.php): fixed pull-tab toggle,
     hover-to-open with a short close delay, backdrop click / click / Escape
     to close. Nav links keep the existing action-btn/btn-muted neumorphic
     pill treatment from admin.css - only the container changed from a
     horizontal top bar to a vertical drawer. -->
<button type="button" class="admin-menu-toggle" id="adminMenuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="adminSidebar">
  <span class="admin-menu-toggle-bar"></span>
  <span class="admin-menu-toggle-bar"></span>
  <span class="admin-menu-toggle-bar"></span>
</button>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
<aside class="admin-sidebar" id="adminSidebar">
  <?php /* Brand + the manual lock. The lock sits here rather than in the nav
           list below because it is not a destination - it is the one control
           that takes the panel away, so it belongs with the panel's identity,
           not among the pages. The idle timer in includes/header.php locks the
           same way after 5 minutes; this is the same lock on demand, for an
           admin stepping away from the counter who does not want to wait it
           out. */ ?>
  <div class="admin-sidebar-head">
    <a href="<?= WEB_ROOT ?>public/authenticated/admin/index.php" class="admin-sidebar-brand">
      <img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="TechWorld" class="admin-sidebar-logo">
      <span class="admin-sidebar-brand-text">
        <strong>TechWorld</strong>
        <span>Admin Panel</span>
      </span>
    </a>
    <?php /* Same condition header.php uses to render the lock overlay - the
             button must not appear on a page that has nothing to show when it
             is pressed. Guarded like $__anLoggedIn above for the same reason. */ ?>
    <?php if ($__anLoggedIn && function_exists('isAdmin') && isAdmin()): ?>
      <button type="button" class="admin-sidebar-lock" data-admin-lock-now
              title="Lock the panel now (unlocks with your password)"
              aria-label="Lock the panel now">
        <i class="fi fi-rr-lock" aria-hidden="true"></i>
      </button>
    <?php endif; ?>
  </div>

  <!-- Two levels only: destinations at the top, and the pages that belong to
       a workflow nested under it. Actions (Add Product, Edit Price) live on
       their page, not here - a sidebar row should be somewhere you go, not
       something you do. Sections are <button> disclosures rather than links,
       because "Orders" is a heading for two pages, not a third page. -->
  <nav class="admin-sidebar-nav">
    <a href="<?= WEB_ROOT ?>public/authenticated/admin/dashboard.php" class="action-btn btn-muted<?= an_active('dashboard') ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/dashboard.gif" alt="" aria-hidden="true"> <span>Dashboard</span>
    </a>

    <div class="admin-sidebar-has-submenu<?= $__anOpenSection === 'orders' ? ' is-open' : '' ?>" data-nav-section="orders">
      <!-- A disclosure, and nothing else. This used to carry data-nav-modal
           too, so clicking it raised the very same delivery-vs-pickup dialog
           the All Orders row below raises - one question, two triggers, three
           pixels apart.

           That was only ever worth it while opening the section cost a click:
           the dialog on the header saved you from "open the section, THEN
           click the row". Pointing at the header now opens it on its own, so
           All Orders is already on screen by the time you could press
           anything - which left the shortcut as a pure duplicate that also
           made a single click do two unrelated things (toggle a section and
           raise a modal). The chooser lives on the row it belongs to. -->
      <button type="button" class="action-btn btn-muted admin-nav-toggle"
              aria-expanded="<?= $__anOpenSection === 'orders' ? 'true' : 'false' ?>" aria-controls="navSection-orders">
        <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/orders.gif" alt="" aria-hidden="true">
        <span class="admin-nav-text">
          <span>Orders</span>
          <span class="admin-nav-desc"><span class="admin-nav-desc-track"><span class="admin-nav-desc-copy">Process orders and track shipments.</span><span class="admin-nav-desc-copy" aria-hidden="true">Process orders and track shipments.</span></span></span>
        </span>
        <?php if ($__anCancelCount > 0): ?>
          <!-- Roll-up count. The real badge lives on Cancellation Requests
               below, but that row is inside .admin-sidebar-submenu, which is
               clipped to max-height:0 while the section is closed - so with a
               collapsed sidebar the count was invisible exactly when it most
               needed to be seen. This shows the same number on the section
               header and hides itself the moment the section opens, so it is
               never displayed twice. -->
          <span class="admin-nav-badge admin-nav-rollup"
                aria-label="<?= (int)$__anCancelCount ?> cancellation request<?= $__anCancelCount === 1 ? '' : 's' ?> pending"><?= $__anCancelCount > 99 ? '99+' : (int)$__anCancelCount ?></span>
        <?php endif; ?>
        <i class="fi fi-rr-angle-small-down admin-nav-caret" aria-hidden="true"></i>
      </button>
      <div class="admin-sidebar-submenu" id="navSection-orders">
        <div class="admin-sidebar-submenu-item">
          <!-- Keeps the chooser modal: Home Delivery and Store Pickup are both
               "all orders" and neither is the default. href stays OrdMan.php so
               the row still works with JS off and with ctrl/middle click. -->
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/OrdMan.php" id="adminOrdersNavLink"
             class="action-btn btn-muted<?= an_active('all-orders') ?>" style="position:relative;" aria-haspopup="dialog">
            <i class="fi fi-rr-document" aria-hidden="true"></i> <span>All Orders</span>
            <span id="pendingOrdersBadge" class="admin-nav-badge" style="display:none;"></span>
          </a>
        </div>
        <div class="admin-sidebar-submenu-item">
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/Order_Cancel_Request.php"
             class="action-btn btn-muted<?= an_active('cancellations') ?>" style="position:relative;">
            <i class="fi fi-rr-undo" aria-hidden="true"></i> <span>Cancellation Requests</span>
            <?php if ($__anCancelCount > 0): ?>
              <span class="admin-nav-badge" aria-label="<?= (int)$__anCancelCount ?> pending"><?= $__anCancelCount > 99 ? '99+' : (int)$__anCancelCount ?></span>
            <?php endif; ?>
          </a>
        </div>
      </div>
    </div>

    <div class="admin-sidebar-has-submenu<?= $__anOpenSection === 'products' ? ' is-open' : '' ?>" data-nav-section="products">
      <!-- Disclosure only, for the same reason as the Orders header above:
           the catalog/new-product/prices question is asked by the Products row
           inside this section, and asking it from the header as well was two
           controls for one answer now that hovering the header reveals that
           row anyway. -->
      <button type="button" class="action-btn btn-muted admin-nav-toggle"
              aria-expanded="<?= $__anOpenSection === 'products' ? 'true' : 'false' ?>" aria-controls="navSection-products">
        <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/product.gif" alt="" aria-hidden="true">
        <span class="admin-nav-text">
          <span>Products</span>
          <span class="admin-nav-desc"><span class="admin-nav-desc-track"><span class="admin-nav-desc-copy">Add, edit, and manage your product catalog.</span><span class="admin-nav-desc-copy" aria-hidden="true">Add, edit, and manage your product catalog.</span></span></span>
        </span>
        <i class="fi fi-rr-angle-small-down admin-nav-caret" aria-hidden="true"></i>
      </button>
      <div class="admin-sidebar-submenu" id="navSection-products">
        <div class="admin-sidebar-submenu-item">
          <!-- Adding a product and editing prices are only reachable from
               product_management.php, so a sidebar that went straight to the
               view-only catalog hid them completely. Same treatment as All
               Orders: href stays the catalog so the row still works with JS
               off and with ctrl/middle click, and a plain click asks which of
               the three you meant. -->
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/products.php" id="adminProductsNavLink"
             class="action-btn btn-muted<?= an_active('products') ?>" aria-haspopup="dialog">
            <i class="fi fi-rr-box" aria-hidden="true"></i> <span>Products</span>
          </a>
        </div>
        <div class="admin-sidebar-submenu-item">
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/categories.php" class="action-btn btn-muted<?= an_active('categories') ?>">
            <i class="fi fi-bs-archive" aria-hidden="true"></i> <span>Categories</span>
          </a>
        </div>
        <div class="admin-sidebar-submenu-item">
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php" class="action-btn btn-muted<?= an_active('units') ?>">
            <i class="fi fi-rr-barcode" aria-hidden="true"></i> <span>Unit Tracking</span>
          </a>
        </div>
      </div>
    </div>

    <a href="<?= WEB_ROOT ?>public/authenticated/admin/inventory_control.php" class="action-btn btn-muted<?= an_active('inventory') ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/inventory-control.gif" alt="" aria-hidden="true"> <span>Inventory</span>
    </a>
    <?php /* The stock-movement pages. Kept as plain rows under Inventory
             rather than behind a disclosure: receiving a delivery and raising
             a pull-out are daily work, and a daily task one click away beats a
             tidier sidebar. */ ?>
    <div class="admin-sidebar-submenu-item">
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/receive_delivery.php" class="action-btn btn-muted<?= an_active('receive') ?>">
        <i class="fi fi-rr-box" aria-hidden="true"></i> <span>Receive Delivery</span>
      </a>
    </div>
    <div class="admin-sidebar-submenu-item">
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/defective_pullout.php" class="action-btn btn-muted<?= an_active('pullout') ?>">
        <i class="fi fi-rr-exclamation-triangle" aria-hidden="true"></i> <span>Defective / Pull-Out</span>
      </a>
    </div>
    <div class="admin-sidebar-submenu-item">
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/stock_movements.php" class="action-btn btn-muted<?= an_active('movements') ?>">
        <i class="fi fi-rr-time-past" aria-hidden="true"></i> <span>Movement History</span>
      </a>
    </div>

    <!-- Customers used to be the one section whose header was a LINK, because
         it doubled as a destination (the customer list). It is a disclosure
         button now, like Orders and Products: the customer list has its own
         row below, so the header no longer has anywhere of its own to go, and
         a heading that is only a heading is the less surprising of the two. -->
    <div class="admin-sidebar-has-submenu<?= $__anOpenSection === 'customers' ? ' is-open' : '' ?>" data-nav-section="customers">
      <button type="button" class="action-btn btn-muted admin-nav-toggle"
              aria-expanded="<?= $__anOpenSection === 'customers' ? 'true' : 'false' ?>" aria-controls="navSection-customers">
        <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/total-users.gif" alt="" aria-hidden="true">
        <span class="admin-nav-text">
          <span>Customers</span>
          <span class="admin-nav-desc"><span class="admin-nav-desc-track"><span class="admin-nav-desc-copy">View and manage customer accounts.</span><span class="admin-nav-desc-copy" aria-hidden="true">View and manage customer accounts.</span></span></span>
        </span>
        <i class="fi fi-rr-angle-small-down admin-nav-caret" aria-hidden="true"></i>
      </button>
      <div class="admin-sidebar-submenu" id="navSection-customers">
        <div class="admin-sidebar-submenu-item">
          <?php // The destination the section header used to carry. It keeps
                // an_active('customers'), so the pages already mapped to that
                // item (view_customer.php, CusMan.php) light up this row. ?>
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/view_customer.php" class="action-btn btn-muted<?= an_active('customers') ?>">
            <i class="fi fi-rr-user" aria-hidden="true"></i> <span>View Customers</span>
          </a>
        </div>
        <div class="admin-sidebar-submenu-item">
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/reviews.php" class="action-btn btn-muted<?= an_active('feedback') ?>">
            <i class="fi fi-rr-comment-alt" aria-hidden="true"></i> <span>Customer Feedback</span>
          </a>
        </div>
        <div class="admin-sidebar-submenu-item">
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/support_dashboard.php" class="action-btn btn-muted<?= an_active('support') ?>">
            <i class="fi fi-rr-headset" aria-hidden="true"></i> <span>Support Dashboard</span>
          </a>
        </div>
      </div>
    </div>

    <div class="admin-sidebar-has-submenu<?= $__anOpenSection === 'marketing' ? ' is-open' : '' ?>" data-nav-section="marketing">
      <button type="button" class="action-btn btn-muted admin-nav-toggle"
              aria-expanded="<?= $__anOpenSection === 'marketing' ? 'true' : 'false' ?>" aria-controls="navSection-marketing">
        <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/promo.gif" alt="" aria-hidden="true">
        <span>Marketing</span>
        <i class="fi fi-rr-angle-small-down admin-nav-caret" aria-hidden="true"></i>
      </button>
      <div class="admin-sidebar-submenu" id="navSection-marketing">
        <div class="admin-sidebar-submenu-item">
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/promotional_carousel.php" class="action-btn btn-muted<?= an_active('promo') ?>">
            <i class="fi fi-rr-settings-sliders" aria-hidden="true"></i> <span>Promo Carousel</span>
          </a>
        </div>
      </div>
    </div>

    <!-- Reports keeps its chooser modal (Sales vs Inventory report); it has no
         nested pages of its own, so it stays a top-level row. -->
    <a href="<?= WEB_ROOT ?>public/authenticated/admin/RepAna.php" id="adminReportsNavLink"
       class="action-btn btn-muted<?= an_active('reports') ?>" aria-haspopup="dialog">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/sales-logs.gif" alt="" aria-hidden="true"> <span>Reports</span>
    </a>

    <a href="<?= WEB_ROOT ?>public/authenticated/admin/inventory_reports.php" class="action-btn btn-muted<?= an_active('invreports') ?>">
      <i class="fi fi-rr-box" aria-hidden="true"></i> <span>Inventory Reports</span>
    </a>
    <a href="<?= WEB_ROOT ?>public/authenticated/admin/reports_analytics.php?view=analytics" class="action-btn btn-muted<?= an_active('analytics') ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/analytics.gif" alt="" aria-hidden="true"> <span>Analytics</span>
    </a>

    <?php // A record of what has been done across the whole panel, so it sits
          // at the end as its own destination rather than inside Orders or
          // Products - it covers all of them. ?>
    <a href="<?= WEB_ROOT ?>public/authenticated/admin/audit_logs.php" class="action-btn btn-muted<?= an_active('audit') ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/audit.gif" alt="" aria-hidden="true"> <span>Audit Logs</span>
    </a>
  </nav>

  <?php if ($__anLoggedIn): ?>
  <!-- Profile block sits at the very bottom of the panel, directly above
       Logout - same "fixed footer" positioning as the customer sidebar
       (customer/includes/sidebar.php). -->
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
  <?php if ($__anSaMode): ?>
    <a href="<?= WEB_ROOT ?>public/authenticated/admin/exit_admin_mode.php" class="admin-sidebar-logout">
      <i class="fi fi-bs-user-gear"></i> <span>Return to Super Admin</span>
    </a>
  <?php endif; ?>
  <a href="<?= WEB_ROOT ?>public/authenticated/admin/logout.php" class="admin-sidebar-logout" data-confirm="Are you sure you want to log out?" data-confirm-ok="Log out" >
    <i class="fi fi-bs-sign-out"></i> <span>Logout</span>
  </a>
  <?php endif; ?>
</aside>

<!-- Order Management chooser. Lives outside <aside> because the panel is
     position:fixed with overflow:hidden and would clip it. Its own
     .admin-nav-modal-* classes rather than the .modal-overlay/.modal-
     container names the individual admin pages use: those are re-declared
     per page (Delivery_Report.php gives .modal-overlay its own animation,
     for instance), and this markup ships on every admin page, so borrowing
     the name would make the same dialog look different depending on which
     page you opened it from. Styling still comes from the shared admin
     tokens, so it reads as part of the same system. -->
<div class="admin-nav-modal" id="adminOrdersModal" hidden>
  <div class="admin-nav-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="adminOrdersModalTitle">
    <div class="admin-nav-modal-head">
      <h2 id="adminOrdersModalTitle">Order Management</h2>
      <button type="button" class="admin-nav-modal-close" aria-label="Close">
        <i class="fi fi-rr-cross" aria-hidden="true"></i>
      </button>
    </div>
    <p class="admin-nav-modal-lead">Home delivery and store pickup are both orders. Which set do you want to work on?</p>
    <div class="admin-nav-modal-choices">
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/Home_Delivery_Orders.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-truck" aria-hidden="true"></i>
        <span>
          <strong>Home Delivery Orders</strong>
          <small>Orders shipped out to the customer's address</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/store_pickup_orders.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-store" aria-hidden="true"></i>
        <span>
          <strong>Store Pickup Orders</strong>
          <small>Orders the customer collects at the store</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
    </div>
    <p class="admin-nav-modal-hint">Press <kbd>Esc</kbd> to close</p>
  </div>
</div>

<!-- Products chooser - same component again. The three choices mirror the
     cards on product_management.php, minus Categories, which already has its
     own row in this section. -->
<div class="admin-nav-modal" id="adminProductsModal" hidden>
  <div class="admin-nav-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="adminProductsModalTitle">
    <div class="admin-nav-modal-head">
      <h2 id="adminProductsModalTitle">Products</h2>
      <button type="button" class="admin-nav-modal-close" aria-label="Close">
        <i class="fi fi-rr-cross" aria-hidden="true"></i>
      </button>
    </div>
    <p class="admin-nav-modal-lead">What do you want to do with the catalog?</p>
    <div class="admin-nav-modal-choices">
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/products.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-box" aria-hidden="true"></i>
        <span>
          <strong>Product Catalog</strong>
          <small>Browse every product, its price, stock and status</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/add_product.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-add" aria-hidden="true"></i>
        <span>
          <strong>Add Product</strong>
          <small>Create a new product in the catalog</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/product_prices.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-tags" aria-hidden="true"></i>
        <span>
          <strong>Edit Prices</strong>
          <small>Update pricing and offers across products</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-barcode" aria-hidden="true"></i>
        <span>
          <strong>Unit Tracking</strong>
          <small>Serial numbers and IMEIs, one per physical unit</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/unit_lookup.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-search" aria-hidden="true"></i>
        <span>
          <strong>Serial / IMEI Lookup</strong>
          <small>Verify a unit before accepting a return</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
    </div>
    <p class="admin-nav-modal-hint">Press <kbd>Esc</kbd> to close</p>
  </div>
</div>

<!-- Reports chooser - same component as the Order Management one above,
     wired by the same wireChooserModal() call below. -->
<div class="admin-nav-modal" id="adminReportsModal" hidden>
  <div class="admin-nav-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="adminReportsModalTitle">
    <div class="admin-nav-modal-head">
      <h2 id="adminReportsModalTitle">Reports</h2>
      <button type="button" class="admin-nav-modal-close" aria-label="Close">
        <i class="fi fi-rr-cross" aria-hidden="true"></i>
      </button>
    </div>
    <p class="admin-nav-modal-lead">Which report do you want to open?</p>
    <div class="admin-nav-modal-choices">
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/sales_report.php" class="admin-nav-modal-choice">
        <i class="fi fi-rr-document" aria-hidden="true"></i>
        <span>
          <strong>Sales Report</strong>
          <small>Revenue and order totals over a date range</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/reports_analytics.php?view=inventory" class="admin-nav-modal-choice">
        <i class="fi fi-bs-archive" aria-hidden="true"></i>
        <span>
          <strong>Inventory Report</strong>
          <small>Stock levels and movement across the catalogue</small>
        </span>
        <span class="admin-nav-modal-chev" aria-hidden="true">&rsaquo;</span>
      </a>
    </div>
    <p class="admin-nav-modal-hint">Press <kbd>Esc</kbd> to close</p>
  </div>
</div>

<!-- Sidebar CSS lives in admin.css (shared with super-admin/includes/navigation.php) -->

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

  // A group sitting low in the list expands its children below the fold of
  // the nav's own scroll area, so the thing the hover just revealed would be
  // off-screen until the admin scrolled for it. Nudge that scroller by
  // exactly the amount the submenu overflows by - never more, so the parent
  // row itself stays in view and the list doesn't jump. Same as the customer
  // sidebar (customer/includes/sidebar.php).
  var navList = sidebar.querySelector('.admin-sidebar-nav');
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealTimer = null;

  function revealSubmenu(group) {
    var submenu = group.querySelector('.admin-sidebar-submenu');
    if (!navList || !submenu) return;
    if (revealTimer) { clearTimeout(revealTimer); }
    // Waits out the 220ms max-height transition, otherwise the submenu is
    // still collapsed and measures as zero-height.
    revealTimer = setTimeout(function () {
      var navRect = navList.getBoundingClientRect();
      var subRect = submenu.getBoundingClientRect();
      var hiddenBelow = subRect.bottom - navRect.bottom;
      if (hiddenBelow <= 0) return;
      var top = navList.scrollTop + hiddenBelow + 8; // 8px so it isn't flush
      if (typeof navList.scrollTo === 'function') {
        navList.scrollTo({ top: top, behavior: reduceMotion ? 'auto' : 'smooth' });
      } else {
        navList.scrollTop = top;
      }
    }, 240);
  }

  // Called by openSection() below. It used to be bound to mouseenter, which
  // meant the nav scrolled itself under a pointer that was only passing over
  // a row - motion nobody asked for. It now runs only when a section actually
  // opens.

  // Chooser rows. Some nav rows don't resolve to a single page - All Orders is
  // delivery vs pickup, Products is catalog vs new vs prices, Reports is sales
  // vs inventory, and in each case neither half is a sensible default. Those
  // rows stay real <a>s pointing at the hub behind them, and this intercepts
  // the plain left-click to ask instead. One implementation, wired once per
  // chooser.
  //
  // Each chooser now belongs to exactly ONE row. There used to be a
  // `chooserOpeners` registry here so the Orders and Products section HEADERS
  // could raise the same dialogs as the rows inside them; both headers are
  // plain disclosures now, so the registry had no readers left.

  function wireChooserModal(linkId, modalId) {
    var link = document.getElementById(linkId);
    var modal = document.getElementById(modalId);
    if (!modal) { return; }
    var closeBtn = modal.querySelector('.admin-nav-modal-close');
    var lastFocused = null;

    function openChooser() {
      lastFocused = document.activeElement;
      modal.hidden = false;
      // The panel is mid-hover when this fires; collapse it so the dialog
      // isn't sitting on top of an expanded sidebar.
      scheduleSidebarHoverCollapse();
      var first = modal.querySelector('.admin-nav-modal-choice');
      if (first) { first.focus(); }
    }

    function closeChooser() {
      if (modal.hidden) { return; }
      modal.hidden = true;
      if (lastFocused && typeof lastFocused.focus === 'function') { lastFocused.focus(); }
      lastFocused = null;
    }

    if (link) {
      link.addEventListener('click', function (e) {
        // Leave ctrl/cmd/shift/middle-click alone so "open in new tab" still
        // reaches the hub, which lists all of these anyway.
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
        e.preventDefault();
        openChooser();
      });
    }

    if (closeBtn) { closeBtn.addEventListener('click', closeChooser); }
    modal.addEventListener('click', function (e) {
      if (e.target === modal) { closeChooser(); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) { closeChooser(); }
    });
  }

  wireChooserModal('adminOrdersNavLink', 'adminOrdersModal');
  wireChooserModal('adminProductsNavLink', 'adminProductsModal');
  wireChooserModal('adminReportsNavLink', 'adminReportsModal');

  // ---------------------------------------------------------------------
  // Collapsible sections (Orders, Products, Customers, Marketing).
  //
  // HOVER OPENS THEM. Put the pointer on Orders, Products, Customers or
  // Marketing and its children appear - no click, and nothing to aim at: the
  // caret is an indicator here, not a control. Clicking is still available
  // (and is what touch and keyboard get), it just isn't required.
  //
  // The thing that made this confusing before was not hover itself, it was
  // having THREE ways to open a section - .is-open, .is-hover-open, and a
  // :focus-within rule that also matched the header button - while the caret
  // followed only the first. An open section routinely showed a down arrow.
  //
  // So hover is back, but there is still exactly ONE open state: `.is-open`
  // on the section. Hover sets it, click sets it, keyboard focus sets it, and
  // the caret rule in admin.css keys off that same class - so the arrow can
  // never disagree with what is on screen. Down = closed, up = open.
  //
  // HOVER PREVIEWS, CLICK COMMITS:
  //   - the section holding the current page is open when the sidebar loads
  //     ($__anOpenSection above). That is the PINNED section;
  //   - hovering any section opens it and closes the rest. Leaving it puts the
  //     pinned section back, so a pointer wandering across the nav can't lose
  //     the admin their "you are here";
  //   - clicking pins a section (it stays open after the pointer leaves);
  //     clicking the pinned section again unpins and closes it.
  // ---------------------------------------------------------------------

  // How long an opened-by-hover section stays open after the pointer leaves.
  // Generous on purpose: the pointer has to cross the .admin-sidebar-nav 12px
  // row gap - which belongs to no section - to reach anything else, and a
  // rushed close there collapses the list mid-movement and drops the pointer
  // on a row the user was not aiming at.
  var SECTION_CLOSE_MS = 500;

  var navSections = sidebar.querySelectorAll('.admin-sidebar-has-submenu[data-nav-section]');

  // Whichever section the server rendered open, if any.
  var pinnedSection = null;
  Array.prototype.forEach.call(navSections, function (section) {
    if (section.classList.contains('is-open')) { pinnedSection = section; }
  });

  // NOT named hoverCloseTimer: the mobile drawer above already declares a
  // `var hoverCloseTimer` in this same IIFE scope, and `var` does not give you
  // a second variable - it gives you the same one. Sharing it silently broke
  // the grace close: leaving the nav fires the section's mouseleave (which
  // schedules this timer) and then the sidebar's mouseleave, whose
  // scheduleHoverClose() starts by calling cancelHoverClose() - clearing what
  // it thinks is its own pending timer and was actually ours. The section
  // never reopened the pinned one, and whatever hover had opened stayed open
  // forever. Caught by the browser test; no DOM stub would have shown it.
  var sectionCloseTimer = null;
  function cancelSectionClose() {
    if (sectionCloseTimer) { clearTimeout(sectionCloseTimer); sectionCloseTimer = null; }
  }

  // The one place `.is-open` and aria-expanded are written, so they cannot
  // drift from each other or from the caret.
  function setSection(section, open) {
    section.classList.toggle('is-open', open);
    var t = section.querySelector('.admin-nav-toggle');
    if (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }

  // Accordion: one section open at a time. Pass null to close them all.
  function showOnly(section) {
    Array.prototype.forEach.call(navSections, function (other) {
      if (other !== section) { setSection(other, false); }
    });
    if (section) { setSection(section, true); }
  }

  function openSection(section) {
    cancelSectionClose();
    if (section.classList.contains('is-open')) { return; }
    showOnly(section);
    revealSubmenu(section);
  }

  // What the nav settles back to once the pointer (or focus) leaves: the
  // pinned section, or nothing if none is pinned.
  function restorePinned() {
    showOnly(pinnedSection);
  }

  Array.prototype.forEach.call(navSections, function (section) {
    // Pointer anywhere in the section - the header OR the children under it.
    // Bound to the wrapper rather than written as a CSS :hover because the
    // wrapper GROWS as the submenu opens: a cursor moving down toward the
    // children can fall outside the still-expanding box for a frame, drop
    // :hover, and start the close. A scripted grace timer cannot be raced
    // that way.
    //
    // Bound before the toggle lookup on purpose, so a section whose header is
    // a link rather than a button - it has no .admin-nav-toggle, and the early
    // return below skips it - still reveals its children on hover.
    section.addEventListener('mouseenter', function () { openSection(section); });
    section.addEventListener('mouseleave', function () {
      cancelSectionClose();
      sectionCloseTimer = setTimeout(restorePinned, SECTION_CLOSE_MS);
    });

    // Keyboard equivalent. The rows inside stay tabbable while the submenu is
    // clipped to zero height, so focus landing in there has to open it, and
    // focus leaving puts the nav back the way hover does.
    var submenu = section.querySelector('.admin-sidebar-submenu');
    if (submenu) {
      submenu.addEventListener('focusin', function () {
        openSection(section);
      });
      submenu.addEventListener('focusout', function (e) {
        if (submenu.contains(e.relatedTarget)) { return; }
        cancelSectionClose();
        sectionCloseTimer = setTimeout(restorePinned, SECTION_CLOSE_MS);
      });
    }

    var toggle = section.querySelector('.admin-nav-toggle');
    if (!toggle) { return; }

    // Pin / unpin. A header does one thing: it decides whether this section
    // stays open after the pointer leaves. It no longer raises any dialog -
    // the choosers belong to the rows inside, which hover has already put on
    // screen by the time anyone could click up here.
    toggle.addEventListener('click', function () {
      cancelSectionClose();

      // Clicking the pinned section unpins it and shuts it now, not after the
      // hover grace period - the pointer is still on the button, and waiting
      // would read as the click having done nothing.
      if (pinnedSection === section) {
        pinnedSection = null;
        showOnly(null);
        return;
      }

      pinnedSection = section;
      showOnly(section);
      revealSubmenu(section);
    });
  });
})();
</script>

<?php // The floating QR scanner, on every admin page. Included from here
      // rather than page by page because every admin page already includes
      // this file, so none can be missed and none has to opt in.
      include __DIR__ . '/scan_fab.php'; ?>
