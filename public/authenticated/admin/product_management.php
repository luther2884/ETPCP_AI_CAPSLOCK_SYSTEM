<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Require admin only
require_role(['admin']);

$pageTitle = 'Product Management - TechWorld';
$activeNav = 'products';
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
        <span class="adm-crumb-cur" aria-current="page">Products</span>
      </nav>
    </div>
  </div>
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">
    <div class="dashboard-welcome">
      <h1>Product Management</h1>
      <p>Manage your product catalog efficiently.</p>
      <?php if ($actionsDisabled): ?>
        <div style="margin-top:10px;color:var(--admin-text);background:rgba(194,104,111,0.14);border:1px solid var(--admin-danger);border-radius:var(--radius-sm);padding:8px 14px;display:inline-block;font-weight:700;">⚠ Your admin account is pending Super Admin approval. Actions are temporarily disabled.</div>
      <?php endif; ?>
    </div>

    <div class="admin-grid" style="display:flex;flex-wrap:wrap;margin-bottom:30px;">
      <!-- Add Product Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/add-product.gif" alt="" aria-hidden="true">
        <h3>Add Product</h3>
        <p>Create new products for your catalog</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/add_product.php">Add New Product</a>
      </div>

      <!-- View Products Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/view-products.gif" alt="" aria-hidden="true">
        <h3>View Products</h3>
        <p>Browse and manage existing products</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/products.php">View All Products</a>
      </div>

      <!-- Categories Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/categories.gif" alt="" aria-hidden="true">
        <h3>Categories</h3>
        <p>Organize products into categories</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/categories.php">Manage Categories</a>
      </div>

      <!-- Edit Prices Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/edit-prices.gif" alt="" aria-hidden="true">
        <h3>Edit Prices</h3>
        <p>Update product pricing and offers</p>
        <a class="action-btn admin-quick-link admin-quick-link--accent" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/product_prices.php">Update Prices</a>
      </div>

      <!-- Unit Tracking Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/qr-code.gif" alt="" aria-hidden="true">
        <h3>Unit Tracking</h3>
        <p>Serial numbers and IMEIs, one per physical unit</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php">Manage Units</a>
      </div>

      <!-- Serial / IMEI Lookup Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/track-order.gif" alt="" aria-hidden="true">
        <h3>Serial / IMEI Lookup</h3>
        <p>Verify a unit before accepting a return</p>
        <a class="action-btn admin-quick-link" href="<?= WEB_ROOT ?>public/authenticated/admin/unit_lookup.php">Look Up a Unit</a>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
