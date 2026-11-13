<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Require admin only
require_role(['admin']);

$pageTitle = 'Reports & Analytics - TechWorld';
$activeNav = 'reports';
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
    /* Same single-row card treatment as OrdMan/product_management. Three cards
       here, so the row is capped rather than stretched across the full width -
       that keeps each card about the size it has on the four-card pages instead
       of ballooning to ~500px. */
    html body.admin main.container{max-width:min(1560px,96vw);}

    /* Content sits at the top of the page rather than floating in the middle:
       <main> still absorbs the leftover height so the footer stays put, but its
       children stack from the top instead of being centred vertically. */
    html body.admin main.container{flex:1 0 auto;display:flex;flex-direction:column;justify-content:flex-start;}

    /* flex:1 1 0 + min-width:0 overrides the shared 250-300px card clamp, so the
       tracks are exactly equal rather than sized by their own content. */
    .admin-grid{flex-wrap:nowrap;justify-content:flex-start;gap:24px;max-width:1150px;margin-left:0;margin-right:auto;}
    .admin-grid .action-card{flex:1 1 0;min-width:0;max-width:none;padding:32px 26px;}
    .admin-grid .action-card .action-card-icon-gif{width:58px;height:58px;margin-bottom:16px;}
    .admin-grid .action-card h3{font-size:21px;}
    .admin-grid .action-card p{font-size:15px;}
    /* Three across stops being readable well before phone widths. */
    @media (max-width: 1000px){
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
        <span class="adm-crumb-cur" aria-current="page">Reports & Analytics</span>
      </nav>
    </div>
  </div>
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0;padding:0;max-width:100%;width:100%;">
    <div class="dashboard-welcome">
      <h1>Reports & Analytics</h1>
      <p>View sales reports and analytics to track your business performance.</p>
      <?php if ($actionsDisabled): ?>
        <div style="margin-top:10px;color:var(--admin-text);background:rgba(194,104,111,0.14);border:1px solid var(--admin-danger);border-radius:var(--radius-sm);padding:8px 14px;display:inline-block;font-weight:700;">⚠ Your admin account is pending Super Admin approval. Actions are temporarily disabled.</div>
      <?php endif; ?>
    </div>

    <div class="admin-grid" style="display:flex;flex-wrap:wrap;margin-bottom:30px;">
      <!-- Sales Report Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/sales-report.gif" alt="" aria-hidden="true">
        <h3>Sales Report</h3>
        <p>View detailed sales performance</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/sales_report.php">View Sales Report</a>
      </div>

      <!-- Inventory Report Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/inventory-report.gif" alt="" aria-hidden="true">
        <h3>Inventory Report</h3>
        <p>Track inventory levels and stock</p>
        <a class="action-btn admin-quick-link" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/reports_analytics.php?view=inventory">View Inventory Report</a>
      </div>

      <!-- Analytics Card -->
      <div class="action-card">
        <img class="action-card-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/analytics.gif" alt="" aria-hidden="true">
        <h3>Analytics</h3>
        <p>Advanced analytics and insights</p>
        <a class="action-btn admin-quick-link admin-quick-link--accent" style="<?= $actionsDisabled ? 'pointer-events:none;opacity:.5;' : '' ?>" href="<?= WEB_ROOT ?>public/authenticated/admin/reports_analytics.php?view=analytics">View Analytics</a>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
