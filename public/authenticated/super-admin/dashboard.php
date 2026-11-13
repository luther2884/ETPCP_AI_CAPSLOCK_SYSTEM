<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Require owner
require_role(['owner', 'super-admin']);

$pageTitle = 'Owner Dashboard - TechWorld';
$activeNav = 'dashboard';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';

// Basic stats
$pdo = getConnection();
$stats = [
    'users' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'admins' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('manager','owner','admin','super-admin')")->fetchColumn(),
    'products' => (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'orders' => (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'pending_requests' => (int)$pdo->query("SELECT COUNT(*) FROM manager_invitations WHERE expires_at > NOW()")->fetchColumn(),
];
?>
<style>
/* Level out the three action cards: grid already stretches them to equal
   height per row, so making each card a flex column and letting the <p>
   take the slack (flex:1) drops every "View / Manage / Yearly" button onto
   the same baseline regardless of how long the heading or description is. */
.admin-grid .admin-card { display: flex; flex-direction: column; }
.admin-grid .admin-card h3 { min-height: 2.4em; }
.admin-grid .admin-card p { flex: 1; margin-bottom: 18px; }
.admin-grid .admin-card > div:last-child { margin-top: auto; }

/* Admin Mode switch: outside .admin-dashboard, upper right. On a wide screen it
   moves out into the blank space beside the page - the offset is fixed (the
   button's own 150px width plus a 20px gap) rather than viewport maths, so it
   can never overlap the dashboard, and the media query is what guarantees the
   gutter is wide enough: the theme caps .container at 1200px, so 1560px of
   viewport leaves 180px each side. Below that it stays in normal flow,
   right-aligned above the dashboard. */
.sa-mode-switch { display: flex; justify-content: flex-end; margin: 0 0 12px; }
.sa-mode-switch button { width: 150px; justify-content: center; text-align: center; }
@media (min-width: 1560px) {
  main.container { position: relative; }
  .sa-mode-switch { position: absolute; top: 30px; right: -170px; margin: 0; z-index: 2; }
}
</style>
<main class="container" style="padding: 30px 0;">
  <?php if (($stats['pending_requests'] ?? 0) > 0): ?>
    <div class="notification-banner notification-info" style="margin-bottom:15px;padding:14px 18px;border-radius:var(--radius-md);">
      <div class="container">
        <span>There are <?= (int)$stats['pending_requests'] ?> active employee setup invitation(s). <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/requests.php" style="color:var(--admin-text);font-weight:700;text-decoration:underline;">Manage Accounts</a>.</span>
      </div>
    </div>
  <?php endif; ?>
  <!-- Admin Mode. Outside .admin-dashboard on purpose, upper right - it switches
       the whole panel rather than opening one of the sections inside it.
       POST + CSRF because it is a state change, not a link. -->
  <div class="sa-mode-switch">
    <form method="POST" action="<?= WEB_ROOT ?>public/authenticated/super-admin/switch_to_admin.php" style="margin:0;">
      <?= csrf_field() ?>
      <button type="submit" class="action-btn admin-quick-link" style="border:none;cursor:pointer;margin:0;">Admin Mode</button>
    </form>
  </div>
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:1200px;">
    <div class="dashboard-welcome">
      <h1>Owner Dashboard</h1>
      <p>Welcome, <?= htmlspecialchars($_SESSION['firstname'] ?? '') ?>! Full platform management access.</p>
    </div>

    <div class="quick-stats" style="grid-template-columns:repeat(5,1fr);margin-bottom:30px;">
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/total-users.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['users'] ?></div>
        <div class="stat-label">Total Users</div>
      </div>
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/admin.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['admins'] ?></div>
        <div class="stat-label">Managers & Owners</div>
      </div>
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/product.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['products'] ?></div>
        <div class="stat-label">Products</div>
      </div>
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/orders.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['orders'] ?></div>
        <div class="stat-label">Orders</div>
      </div>
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/shopping-list.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['pending_requests'] ?></div>
        <div class="stat-label">Pending Employee Invitations</div>
      </div>
    </div>

    <div class="admin-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:25px;margin-bottom:30px;">
      <div class="admin-card" style="padding:25px;">
        <h3 style="margin-bottom:15px;display:flex;align-items:center;gap:8px;"><i class="fi fi-bs-document-signed" style="color:var(--accent-cyan);"></i> Audit Logs</h3>
        <p>Track system actions and changes across the platform.</p>
        <div>
          <a class="action-btn admin-quick-link" href="<?= WEB_ROOT ?>public/authenticated/super-admin/audit_logs.php">View Audit Logs</a>
        </div>
      </div>
      <div class="admin-card" style="padding:25px;">
        <h3 style="margin-bottom:15px;display:flex;align-items:center;gap:8px;"><i class="fi fi-bs-user-check" style="color:var(--accent-cyan);"></i> Employee Account Management</h3>
        <p>Create and manage Manager and Delivery Personnel accounts and invitations.</p>
        <div>
          <a class="action-btn admin-quick-link" href="<?= WEB_ROOT ?>public/authenticated/super-admin/requests.php">Manage Accounts</a>
        </div>
      </div>
      <div class="admin-card" style="padding:25px;">
        <h3 style="margin-bottom:15px;display:flex;align-items:center;gap:8px;"><i class="fi fi-bs-chart-histogram" style="color:var(--accent-cyan);"></i> Sales Logs</h3>
        <p>Analyze yearly sales performance and totals.</p>
        <div>
          <a class="action-btn admin-quick-link" href="<?= WEB_ROOT ?>public/authenticated/super-admin/sales_logs.php">Yearly Sales</a>
        </div>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>