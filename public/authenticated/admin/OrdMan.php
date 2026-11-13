<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Require admin only
require_role(['admin']);

$pageTitle = 'Order Management - TechWorld';
$activeNav = 'orders';
$isAdminTheme = true;
// Determine if current admin is approved
$pdo = getConnection();
$currentUserStatus = 'active';
try {
  $st = $pdo->prepare('SELECT status, role FROM users WHERE id = ? LIMIT 1');
  $st->execute([getUserId()]);
  $row = $st->fetch();
  if ($row) { $currentUserStatus = $row['status'] ?? 'active'; }
} catch (Throwable $e) { /* ignore and assume active */ }
$actionsDisabled = (!isSuperAdmin() && $currentUserStatus !== 'active');

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    /* All four cards on one row, equal widths. The admin theme caps .container
       at 1200px, which only leaves ~275px a card; widening the page keeps them
       the larger size while still fitting a single row. body.admin in the
       selector is what outranks `.admin .container`. */
    html body.admin main.container{max-width:min(1560px,96vw);}

    /* flex:1 1 0 + min-width:0 overrides the shared 250-300px card clamp, so the
       four tracks are exactly equal rather than sized by their own content. */
    .admin-grid{flex-wrap:nowrap;justify-content:center;gap:24px;}
    .admin-grid .action-card{flex:1 1 0;min-width:0;max-width:none;padding:32px 26px;}
    .admin-grid .action-card .action-card-icon-gif{width:58px;height:58px;margin-bottom:16px;}
    .admin-grid .action-card h3{font-size:21px;}
    .admin-grid .action-card p{font-size:15px;}
    /* Four across stops being readable well before phone widths. */
    @media (max-width: 1100px){
      .admin-grid{flex-wrap:wrap;}
      .admin-grid .action-card{flex:1 1 300px;padding:26px 22px;}
    }
  </style>
  <style>

    @media (min-width: 1700px){
      main.container{position:relative;}
    }
  </style>
    <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='dashboard.php'; }">Dashboard</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Order Management</span>
      </nav>
    </div>
  </div>
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">
    <div class="dashboard-welcome">
      <h1>Order Management</h1>
      <p>Process orders and track shipments efficiently.</p>
      <?php if ($actionsDisabled): ?>
        <div style="margin-top:10px;color:var(--admin-text);background:rgba(194,104,111,0.14);border:1px solid var(--admin-danger);border-radius:var(--radius-sm);padding:8px 14px;display:inline-block;font-weight:700;">⚠ Your admin account is pending Super Admin approval. Actions are temporarily disabled.</div>
      <?php endif; ?>
    </div>

    <div class="admin-grid" style="display:flex;flex-wrap:wrap;margin-bottom:30px;">
      <!-- Home Delivery Orders Card -->
      <div class="action-card" style="position:relative;">
        <span class="home-delivery-badge notification-badge" style="display:none;position:absolute;top:10px;right:10px;background:#ef4444;color:#fff;border-radius:50%;min-width:20px;height:20px;padding:0 4px;font-size:11px;align-items:center;justify-content:center;font-weight:bold;"></span>
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/home-delivery-orders.gif" alt="" aria-hidden="true">
        <h3>Home Delivery Orders</h3>
        <p>Manage home delivery orders</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/Home_Delivery_Orders.php">View Home Deliveries</a>
      </div>

      <!-- Store Pickup Orders Card -->
      <div class="action-card" style="position:relative;">
        <span class="store-pickup-badge notification-badge" style="display:none;position:absolute;top:10px;right:10px;background:#ef4444;color:#fff;border-radius:50%;min-width:20px;height:20px;padding:0 4px;font-size:11px;align-items:center;justify-content:center;font-weight:bold;"></span>
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/store-pickup-orders.gif" alt="" aria-hidden="true">
        <h3>Store Pickup Orders</h3>
        <p>Manage store pickup orders</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/store_pickup_orders.php">View Pickup Orders</a>
      </div>

      <!-- Inventory Control Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/inventory-control.gif" alt="" aria-hidden="true">
        <h3>Inventory Control</h3>
        <p>Manage product inventory levels</p>
        <a class="action-btn admin-quick-link admin-quick-link--accent" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/inventory_control.php">Manage Inventory</a>
      </div>

      <!-- Order Cancel Requests Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/order-cancel-requests.gif" alt="" aria-hidden="true">
        <h3>Order Cancel Requests</h3>
        <p>Review and process cancellation requests</p>
        <a class="action-btn admin-quick-link admin-quick-link--danger" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/Order_Cancel_Request.php">View Cancellations</a>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
