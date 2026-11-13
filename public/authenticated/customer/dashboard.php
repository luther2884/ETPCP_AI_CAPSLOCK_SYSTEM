<?php
// customer/dashboard.php - Customer dashboard
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Dashboard - TechWorld';
$activeNav = 'dashboard';

// This page paints its own dark ground (techworld-dashboard-bg.css) - suppress
// the customer-area site background video so it doesn't sit behind it.
//
// It has to be suppressed rather than hidden in CSS. header.php renders the
// video at z-index:-2 with a light scrim at z-index:-1; nothing sets a
// background on <html>, so body's background propagates to the canvas and
// paints BEHIND both of them. No body background can cover the clip, however
// !important it is. Same reason index.php and ai_builder.php set this.
$suppressSiteBgVideo = true;

$userData = getUserData();
$userId = getUserId();

// Get user statistics
try {
    $pdo = getConnection();

    // Get order statistics. orders.status is an ENUM of
    // preparing/packed/ready/shipped/completed/cancelled - there is no
    // 'pending' value, so counting status='pending' always returned 0
    // regardless of how many orders were actually in progress.
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_orders,
                                 COUNT(CASE WHEN status IN ('awaiting_payment','preparing','packed','ready','shipped') THEN 1 END) as pending_orders,
                                 COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders
                          FROM orders WHERE user_id = ?");
    $stmt->execute([$userId]);
    $orderStats = $stmt->fetch();

    // Get recent orders
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $recentOrders = $stmt->fetchAll();

    // Get total spent - every order the customer placed except a cancelled
    // one counts as spend, not just orders that reached packed/ready/shipped.
    // Excluding 'preparing' made a freshly-placed order with real money on
    // it show as ₱0.00 here while its own row still showed the real total.
    $stmt = $pdo->prepare("SELECT SUM(total_amount) as total_spent FROM orders WHERE user_id = ? AND status != 'cancelled'");
    $stmt->execute([$userId]);
    $totalSpent = $stmt->fetchColumn() ?: 0;

    // Get notifications
    $notifications = getUnreadNotifications($userId);

    // Store credit balance - just the summary number for the stat card
    // here; the full transaction history now lives on profile.php instead
    // (see the Store Credit card there).
    $storeCreditBalance = getStoreCreditBalance($userId);

} catch (PDOException $e) {
    error_log("Error fetching dashboard data: " . $e->getMessage());
    $orderStats = ['total_orders' => 0, 'pending_orders' => 0, 'completed_orders' => 0];
    $recentOrders = [];
    $totalSpent = 0;
    $notifications = [];
    $storeCreditBalance = 0;
}

// ---------------------------------------------------------------------------
// Presentation-only derivations for the redesign. Every value below comes from
// data already fetched above - no new query, no new round trip.
// ---------------------------------------------------------------------------

// The first still-moving order, for the Active Delivery card. Uses the same
// in-progress set the "pending orders" stat counts, so the card and the stat
// can never disagree. Null => the card is not rendered at all.
$activeOrder = null;
foreach ($recentOrders as $o) {
    if (in_array($o['status'], ['awaiting_payment', 'preparing', 'packed', 'ready', 'shipped'], true)) {
        $activeOrder = $o;
        break;
    }
}
// Which of PLACED / PACKED / IN TRANSIT / DELIVERED are behind us.
$shipStepDone = ['awaiting_payment' => 0, 'preparing' => 0, 'packed' => 1, 'ready' => 1, 'shipped' => 2];

// Stat meters, each a share of something real. Total Orders and Total Spent
// have no denominator in this data set, so they read full rather than
// pretending to a target that does not exist.
$statTotal = max(1, (int)$orderStats['total_orders']);
$barPending   = round((int)$orderStats['pending_orders']   / $statTotal * 100);
$barCompleted = round((int)$orderStats['completed_orders'] / $statTotal * 100);
$barCredit    = $totalSpent > 0 ? min(100, round($storeCreditBalance / $totalSpent * 100)) : 0;

// The completion-rate pill is the only stat pill that is a number, and it is
// derived, not invented. The others are context labels. The brief's "+3 MO" /
// "ON TIME" / "+PHP53K" style pills would need period-over-period data this
// page does not fetch, so they are deliberately not shown.
$pctCompleted = (int)$orderStats['total_orders'] > 0 ? $barCompleted : 0;
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

  <!-- Figtree + JetBrains Mono. techworld-dashboard.css sets every label,
       number and chip in var(--tw-mono); without these the mono runs fall
       back to Consolas. Same pair guest/index.php links for the landing. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <!-- The dashboard skin. Note this is NOT the last sheet the page loads:
       header.php links style.css and customer-dark.css from inside <body>,
       so both come after this one and win any tie on source order. The skin
       is written to outrank them on specificity instead - see its section 0. -->
  <link rel="stylesheet" href="<?= asset_url('public/shared/css/techworld-dashboard.css') ?>">
</head>
<body class="tw-page tw-dash tw-glass">
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="dashboard-container">
    <div class="container">
        <!-- Main Content -->
        <!-- tw-main is added to the existing <main> rather than wrapping the
             content in a second one: a document may hold only one <main>, and
             nesting them is invalid. Same element, same content, two classes. -->
        <main class="dashboard-main tw-main">
            <div class="dash-headrow">
                <div class="dashboard-header">
                    <p class="dash-eyebrow">Customer account &middot; <?= date('M j, Y') ?></p>
                    <h1>Welcome, <?php echo htmlspecialchars($userData['firstname']); ?>!</h1>
                    <p>Here's what's happening with your account today.</p>
                </div>
                <!-- Both point at routes this page already links elsewhere;
                     nothing new was added. -->
                <div class="dash-headactions">
                    <a href="<?= WEB_ROOT ?>public/authenticated/customer/track_order.php" class="dash-btn-ghost">Track an order</a>
                    <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="dash-btn-cta">Continue shopping</a>
                </div>
            </div>

            <!-- Stats Cards -->
            <!-- The .stat-icon tiles are kept in the DOM exactly as they were
                 and hidden in CSS. .stat-pill and .stat-bar are the two new
                 presentational elements; every number in them is derived
                 above from data this page already fetched. -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fi fi-rs-shopping-bag"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $orderStats['total_orders']; ?></h3>
                        <p>Total Orders</p>
                    </div>
                    <span class="stat-pill">All time</span>
                    <span class="stat-bar"><span style="width:100%"></span></span>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fi fi-rs-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $orderStats['pending_orders']; ?></h3>
                        <p>In Progress</p>
                    </div>
                    <span class="stat-pill">Active</span>
                    <span class="stat-bar"><span style="width:<?= $barPending ?>%"></span></span>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fi fi-rs-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $orderStats['completed_orders']; ?></h3>
                        <p>Completed</p>
                    </div>
                    <span class="stat-pill"><?= $pctCompleted ?>%</span>
                    <span class="stat-bar"><span style="width:<?= $barCompleted ?>%"></span></span>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fi fi-rs-money-bill-wave"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo formatCurrency($totalSpent); ?></h3>
                        <p>Total Spent</p>
                    </div>
                    <span class="stat-pill">Net</span>
                    <span class="stat-bar"><span style="width:100%"></span></span>
                </div>

                <div class="stat-card" style="<?= $storeCreditBalance > 0 ? 'border-color:var(--accent-cyan,#a05f80);' : '' ?>">
                    <div class="stat-icon">
                        <i class="fi fi-rs-credit-card"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo formatCurrency($storeCreditBalance); ?></h3>
                        <p>Store Credit</p>
                    </div>
                    <span class="stat-pill"><?= $storeCreditBalance > 0 ? 'Available' : 'Earn' ?></span>
                    <span class="stat-bar"><span style="width:<?= $barCredit ?>%"></span></span>
                </div>
            </div>

            <!-- Two-column body. Left: Recent Orders, then Recent
                 Notifications. Right rail (sticky): Active Delivery, then
                 Quick Actions. Quick Actions moved here from above - same
                 four links, same hrefs, same copy. -->
            <div class="dash-columns">
            <div class="dash-col-main">

            <!-- Recent Orders -->
            <div class="recent-orders">
                <div class="section-header">
                    <h2>Recent Orders</h2>
                    <a href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php" class="view-all-link">View All</a>
                </div>

                <?php if (empty($recentOrders)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fi fi-rs-shopping-bag"></i>
                        </div>
                        <h3>No orders yet</h3>
                        <p>Start shopping to see your orders here!</p>
                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="btn-primary">Start Shopping</a>
                    </div>
                <?php else: ?>
                    <div class="orders-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Total</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                    <?php /* data-label on every cell: under
                                             640px this stops being a table and
                                             each row becomes a card, where
                                             these are the field captions the
                                             <thead> can no longer supply.
                                             Above 640px they are inert. */ ?>
                                    <tr>
                                        <td data-label="Order ID">
                                            <span class="order-id">#<?php echo htmlspecialchars($order['invoice_no']); ?></span>
                                        </td>
                                        <td data-label="Date"><?php echo date('M j, Y', strtotime($order['created_at'])); ?></td>
                                        <td data-label="Status">
                                            <span class="status-badge status-<?php echo $order['status']; ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                        <td class="order-total" data-label="Total"><?php echo formatCurrency($order['total_amount']); ?></td>
                                        <td class="order-actions" data-label="Actions">
                                            <a href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php?view=<?php echo $order['id']; ?>" class="btn-small">View</a>
                                            <?php if (in_array($order['status'], ['ready', 'completed']) && ($order['delivery_method'] ?? 'pickup') === 'pickup'): ?>
                                                <a href="<?= WEB_ROOT ?>public/authenticated/customer/pickup.php?order_id=<?php echo $order['id']; ?>" class="btn-small btn-primary">Pickup QR</a>
                                            <?php endif; ?>
                                            <?php if (($order['delivery_method'] ?? '') === 'pickup' && ($order['payment_method'] ?? '') === 'onsite' && !empty($order['payment_code'])): ?>
                                                <a href="<?= WEB_ROOT ?>public/authenticated/customer/payment_qr.php?order_id=<?php echo $order['id']; ?>" class="btn-small" style="background: #b91c1c; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500;">Payment QR</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Notifications -->
            <?php if (!empty($notifications)): ?>
            <div class="recent-notifications">
                <div class="section-header">
                    <h2>Recent Notifications</h2>
                    <a href="<?= WEB_ROOT ?>public/authenticated/customer/notifications.php" class="view-all-link">View All</a>
                </div>

                <div class="notifications-list">
                    <?php foreach (array_slice($notifications, 0, 3) as $notification): ?>
                        <div class="notification-item notification-<?php echo $notification['type']; ?>">
                            <div class="notification-icon">
                                <?php
                                switch ($notification['type']) {
                                    case 'success': echo '<i class="fi fi-ss-check-circle"></i>'; break;
                                    case 'warning': echo '<i class="fi fi-ss-exclamation-triangle"></i>'; break;
                                    case 'error': echo '<i class="fi fi-ss-cross-circle"></i>'; break;
                                    default: echo '<i class="fi fi-rs-info"></i>'; break;
                                }
                                ?>
                            </div>
                            <div class="notification-content">
                                <h4><?php echo htmlspecialchars($notification['title']); ?></h4>
                                <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                <span class="notification-time"><?php echo timeAgo($notification['created_at']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- /.dash-col-main -->

            <aside class="dash-rail">
                <?php if ($activeOrder): ?>
                <!-- Active Delivery. Rendered only while an order is still
                     moving. The orders table has no ETA column, so the line
                     below states the placed date rather than inventing an
                     arrival. Item count is not shown for the same reason -
                     order_items is not part of this page's query. -->
                <div class="dash-shipment">
                    <p class="ship-eyebrow">Active delivery</p>
                    <p class="ship-title"><span class="ship-inv">#<?= htmlspecialchars($activeOrder['invoice_no']) ?></span></p>
                    <p class="ship-eta">Placed <?= date('M j, Y', strtotime($activeOrder['created_at'])) ?> &middot; <?= ucfirst($activeOrder['status']) ?></p>
                    <?php
                    $doneUpTo = $shipStepDone[$activeOrder['status']] ?? 0;
                    $shipSteps = ['Placed', 'Packed', 'In transit', 'Delivered'];
                    ?>
                    <div class="ship-steps">
                        <?php foreach ($shipSteps as $i => $label): ?>
                            <div class="ship-step<?= $i <= $doneUpTo ? ' is-done' : '' ?>"><?= $label ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="action-card">
                            <i class="fi fi-rs-shopping-bag"></i>
                            <h3>Shop Products</h3>
                            <p>Browse our latest products</p>
                        </a>

                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/cart.php" class="action-card">
                            <i class="fi fi-rs-shopping-cart"></i>
                            <h3>View Cart</h3>
                            <p>Review items in your cart</p>
                        </a>

                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/track_order.php" class="action-card">
                            <i class="fi fi-rs-package"></i>
                            <h3>Track Orders</h3>
                            <p>Check your order status</p>
                        </a>

                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/profile.php" class="action-card">
                            <i class="fi fi-rs-user"></i>
                            <h3>Update Profile</h3>
                            <p>Manage your account</p>
                        </a>
                    </div>
                </div>
            </aside>
            </div><!-- /.dash-columns -->
        </main>
    </div>
</div>

<style>
.dashboard-container { padding: 40px 0; background: var(--page-bg-gradient); min-height: 100vh; overflow-x: hidden; }
.dashboard-main { background: var(--bg-card-alpha-80); border-radius: 15px; padding: 40px; border: 1px solid var(--border-color); backdrop-filter: blur(10px); }
.dashboard-header { margin-bottom: 40px; }
.dashboard-header h1 { color: var(--text-primary); font-size: 32px; margin-bottom: 10px; }
.dashboard-header p { color: var(--text-muted); font-size: 16px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 25px; margin-bottom: 40px; }
.stat-card { background: var(--bg-card-alpha-80); border-radius: 12px; padding: 25px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 20px; transition: all 0.3s ease; }
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(var(--twd-black-rgb, 0, 0, 0), 0.3); border-color: var(--accent-cyan-bg-30); }
.stat-icon { width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue)); display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; }
.stat-info h3 { color: var(--text-primary); font-size: 28px; font-weight: 700; margin-bottom: 5px; }
.stat-info p { color: var(--text-muted); font-size: 14px; font-weight: 500; }
.quick-actions { margin-bottom: 40px; }
.quick-actions h2 { color: var(--text-primary); margin-bottom: 25px; font-size: 24px; }
.actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
.action-card { background: var(--bg-card-alpha-80); border-radius: 12px; padding: 25px; text-decoration: none; border: 1px solid var(--border-color); transition: all 0.3s ease; text-align: center; }
.action-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(var(--twd-black-rgb, 0, 0, 0), 0.3); border-color: var(--accent-cyan-bg-30); }
.action-card i { font-size: 32px; color: var(--accent-cyan); margin-bottom: 15px; }
.action-card h3 { color: var(--text-primary); margin-bottom: 8px; font-size: 16px; }
.action-card p { color: var(--text-muted); font-size: 14px; }
.recent-orders, .recent-notifications { margin-bottom: 40px; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
.section-header h2 { color: var(--text-primary); font-size: 24px; }
.view-all-link { color: var(--accent-cyan); text-decoration: none; font-weight: 600; font-size: 14px; }
.view-all-link:hover { text-decoration: underline; }
.empty-state { text-align: center; padding: 60px 20px; background: var(--bg-card-alpha-80); border-radius: 12px; border: 1px solid var(--border-color); }
.empty-icon i { font-size: 64px; color: var(--text-muted); margin-bottom: 20px; }
.btn-primary { background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue)); color: white; padding: 12px 24px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-block; transition: all 0.3s ease; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(var(--twd-blue-rgb, 23, 72, 113), 0.3); }
.orders-table { background: var(--bg-card-alpha-80); border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color); }
.orders-table table { width: 100%; border-collapse: collapse; }
.orders-table th { background: var(--bg-card-alpha-80); color: var(--text-primary); padding: 15px 20px; text-align: left; font-weight: 600; border-bottom: 1px solid var(--border-color); }
.orders-table td { padding: 15px 20px; color: var(--text-muted); border-bottom: 1px solid var(--border-color); }
.order-id { color: var(--accent-cyan); font-weight: 600; }
.order-total { color: var(--text-primary); font-weight: 600; }
/* Matches orders.status's real ENUM values (preparing/packed/ready/shipped/
   completed/cancelled) - the previous status-pending/status-processing
   classes never matched an actual order and rendered unstyled, and
   status-preparing/status-packed/status-shipped had no rule at all. */
.status-badge { padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
.status-preparing { background: rgba(var(--twd-amber-rgb, 245, 158, 11), 0.2); color: var(--twd-amber, #f59e0b); }
.status-packed { background: rgba(var(--twd-steel-rgb, 91, 155, 213), 0.2); color: var(--twd-blue, #5b9bd5); }
.status-ready { background: var(--accent-cyan-bg); color: var(--accent-cyan); }
.status-shipped { background: rgba(var(--twd-steel-rgb, 91, 155, 213), 0.2); color: var(--twd-blue, #5b9bd5); }
.status-completed { background: rgba(var(--twd-emer-rgb, 16, 185, 129), 0.2); color: var(--twd-green, #10b981); }
.status-cancelled { background: rgba(var(--twd-red-rgb, 239, 68, 68), 0.2); color: var(--twd-red, #ef4444); }
.order-actions { display: flex; gap: 8px; }
.btn-small { padding: 6px 12px; font-size: 12px; border-radius: 6px; text-decoration: none; background: var(--border-color); color: var(--text-primary); transition: all 0.3s ease; }
.btn-small:hover { background: var(--bg-card-hover); }
.btn-small.btn-primary { background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue)); color: white; }
.notifications-list { display: flex; flex-direction: column; gap: 15px; }
.notification-item { background: var(--bg-card-alpha-80); border-radius: 12px; padding: 20px; display: flex; gap: 15px; border: 1px solid var(--border-color); }
.notification-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.notification-success .notification-icon { background: rgba(var(--twd-emer-rgb, 16, 185, 129), 0.2); color: var(--twd-green, #10b981); }
.notification-warning .notification-icon { background: rgba(var(--twd-amber-rgb, 245, 158, 11), 0.2); color: var(--twd-amber, #f59e0b); }
.notification-error .notification-icon { background: rgba(var(--twd-red-rgb, 239, 68, 68), 0.2); color: var(--twd-red, #ef4444); }
.notification-info .notification-icon { background: rgba(var(--twd-blue-rgb, 23, 72, 113), 0.2); color: var(--twd-blue-ink, #174871); }
.notification-content h4 { color: var(--text-primary); margin-bottom: 5px; font-size: 16px; }
.notification-content p { color: var(--text-muted); margin-bottom: 8px; font-size: 14px; line-height: 1.5; }
.notification-time { color: var(--text-muted); font-size: 12px; }
@media (max-width: 768px) {
    .dashboard-main { padding: 20px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; }
    .actions-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; }
    .orders-table { overflow-x: auto; }
    .order-actions { flex-direction: column; gap: 4px; }
}
</style>

<?php
include __DIR__ . '/includes/footer.php';
?>

<!-- techworld-dashboard-bg.css - LAST stylesheet on the page, deliberately.

     It is NOT next to techworld-dashboard.css in <head>, because that spot is
     not last: header.php is included from inside <body> and links style.css,
     customer-dark.css and six icon sheets after it, and header.php,
     sidebar.php, this page and footer.php each add a <style> block on top of
     that. Down here, after the footer include, is the only point in the
     document where nothing further can load. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/css/techworld-dashboard-bg.css') ?>">

<!-- techworld-dashboard-glass.css - the dark-glass pass, and now the genuine
     last stylesheet on the page. Scoped to `body.tw-dash.tw-glass`; dropping
     the tw-glass class from <body> reverts the page exactly. It has to sit
     after the sheet above, which paints every panel #fff4ef with !important -
     see this file's header for the full list of what it is beating. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/css/techworld-dashboard-glass.css') ?>">
</body>
</html>