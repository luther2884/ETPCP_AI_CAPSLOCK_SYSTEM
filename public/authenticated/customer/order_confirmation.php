<?php
// customer/order_confirmation.php - Order confirmation page
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Order Confirmation - TechWorld';
$userId = getUserId();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/dashboard.php');
    exit;
}

try {
    $pdo = getConnection();
    
    // Get order details
    $orderStmt = $pdo->prepare("SELECT o.*, u.username,
                                COALESCE(
                                    o.address, 
                                    (SELECT CONCAT_WS(', ', full_name, phone, street, region, postal) FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1),
                                    (SELECT CONCAT_WS(', ', full_name, phone, street, region, postal) FROM user_addresses WHERE user_id = o.user_id LIMIT 1),
                                    u.address
                                ) as address
                                FROM orders o 
                                JOIN users u ON u.id = o.user_id 
                                WHERE o.id = ? AND o.user_id = ?");
    $orderStmt->execute([$orderId, $userId]);
    $order = $orderStmt->fetch();
    
    if (!$order) {
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/dashboard.php');
        exit;
    }
    
    // Get order items
    $itemsStmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url 
                                FROM order_items oi 
                                JOIN products p ON p.id = oi.product_id 
                                WHERE oi.order_id = ?");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll();

    $orderGrossTotal = (float)($order['total'] ?? $order['total_amount'] ?? 0);
    $vatBreakdown = phVatBreakdown($orderGrossTotal);

} catch (PDOException $e) {
    error_log('Error loading order: ' . $e->getMessage());
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/dashboard.php');
    exit;
}

// Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js is
// deferred and would run a frame after first paint, which is exactly the
// flash this prevents. Resolution order is localStorage -> OS preference ->
// dark. There is no server-rendered preference to fall back on yet - `users`
// has no theme column; see the TODO in theme.js.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<section class="confirmation-page">
  <div class="container">
    <div class="confirmation-header">
      <div class="success-icon">
        <i class="fi fi-rr-check-circle"></i>
      </div>
      <h1>Order Confirmed!</h1>
      <p>Thank you for your purchase. Your order has been successfully placed.</p>
    </div>

    <div class="confirmation-layout">
      <!-- Order Details -->
      <div class="order-details">
        <div class="details-card">
          <h3><i class="fi fi-rr-receipt"></i> Order Information</h3>
          
          <div class="order-info">
            <div class="info-row">
              <span class="label">Order Number:</span>
              <span class="value">#<?= htmlspecialchars($order['invoice_no'] ?? str_pad($order['id'], 6, '0', STR_PAD_LEFT)) ?></span>
            </div>
            <?php if (!empty($order['tracking_code'])): ?>
            <div class="info-row">
              <span class="label">Tracking Code:</span>
              <span class="value"><?= htmlspecialchars($order['tracking_code']) ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
              <span class="label">Order Date:</span>
              <span class="value"><?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></span>
            </div>
            <div class="info-row">
              <span class="label">Status:</span>
              <span class="value status-<?= $order['status'] ?>"><?= ucfirst($order['status']) ?></span>
            </div>
            <div class="info-row">
              <span class="label">Payment Method:</span>
              <span class="value"><?= htmlspecialchars(getPaymentMethodLabel($order['payment_method'] ?? '')) ?></span>
            </div>
            <div class="info-row">
              <span class="label">Delivery Method:</span>
              <span class="value"><?= $order['delivery_method'] === 'delivery' ? 'Home Delivery' : 'Store Pickup' ?></span>
            </div>
          </div>
        </div>

        <!-- Customer Information -->
        <div class="details-card">
          <h3><i class="fi fi-rr-user"></i> Customer Information</h3>
          
          <div class="customer-info">
            <div class="info-row">
              <span class="label">Name:</span>
              <span class="value"><?= htmlspecialchars($order['full_name']) ?></span>
            </div>
            <div class="info-row">
              <span class="label">Email:</span>
              <span class="value"><?= htmlspecialchars($order['email']) ?></span>
            </div>
            <div class="info-row">
              <span class="label">Phone:</span>
              <span class="value"><?= htmlspecialchars($order['phone']) ?></span>
            </div>
            <div class="info-row">
              <span class="label">Address:</span>
              <span class="value"><?= htmlspecialchars($order['address']) ?></span>
            </div>
          </div>
        </div>

        <!-- Order Items -->
        <div class="details-card">
          <h3><i class="fi fi-rr-shopping-bag"></i> Order Items</h3>
          
          <div class="order-items">
            <?php foreach ($orderItems as $item): ?>
            <div class="order-item">
              <div class="item-image">
                <?php if (!empty($item['image_url'])): ?>
                  <img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                <?php else: ?>
                  <div class="image-placeholder">IMG</div>
                <?php endif; ?>
              </div>
              <div class="item-details">
                <h4><?= htmlspecialchars($item['name']) ?></h4>
                <p>Quantity: <?= (int)$item['quantity'] ?></p>
                <span class="item-price"><?= formatCurrency($item['price'] * $item['quantity']) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Order Notes -->
        <?php if (!empty($order['order_notes'])): ?>
        <div class="details-card">
          <h3><i class="fi fi-rr-comment"></i> Order Notes</h3>
          <p class="order-notes"><?= htmlspecialchars($order['order_notes']) ?></p>
        </div>
        <?php endif; ?>
      </div>

      <!-- Order Summary -->
      <div class="order-summary">
        <div class="summary-card">
          <h3>Order Summary</h3>
          
          <div class="summary-totals">
            <div class="total-line">
              <span>Subtotal</span>
              <span><?= formatCurrency($vatBreakdown['net']) ?></span>
            </div>
            <div class="total-line">
              <span>VAT (12%)</span>
              <span><?= formatCurrency($vatBreakdown['vat']) ?></span>
            </div>
            <div class="total-divider"></div>
            <div class="total-line final-total">
              <span>Total</span>
              <span><?= formatCurrency($orderGrossTotal) ?></span>
            </div>
          </div>

          <!-- Next Steps -->
          <div class="next-steps">
            <h4>What's Next?</h4>
            <ul>
              <li><i class="fi fi-rr-clock"></i> We'll process your order within 24 hours</li>
              <li><i class="fi fi-rr-bell"></i> You'll receive email updates about your order</li>
              <?php if ($order['delivery_method'] === 'delivery'): ?>
              <li><i class="fi fi-rr-truck"></i> We'll deliver to your address</li>
              <?php else: ?>
              <li><i class="fi fi-rr-shop"></i> You can pick up at our store</li>
              <?php endif; ?>
            </ul>
          </div>

          <!-- Action Buttons -->
          <div class="confirmation-actions">
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php" class="btn-primary">
              <i class="fi fi-rr-list"></i>
              View All Orders
            </a>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="btn-secondary">
              <i class="fi fi-rr-shopping-bag"></i>
              Continue Shopping
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
.confirmation-page {
  padding: 40px 0;
  background: var(--page-bg-gradient);
  min-height: 100vh;
}

.confirmation-header {
  text-align: center;
  margin-bottom: 40px;
}

.success-icon {
  margin-bottom: 20px;
}

.success-icon i {
  font-size: 80px;
  color: #10b981;
  animation: bounce 1s ease-in-out;
}

@keyframes bounce {
  0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
  40% { transform: translateY(-10px); }
  60% { transform: translateY(-5px); }
}

.confirmation-header h1 {
  color: var(--text-primary);
  font-size: 36px;
  margin-bottom: 10px;
}

.confirmation-header p {
  color: var(--text-muted);
  font-size: 18px;
}

.confirmation-layout {
  display: grid;
  grid-template-columns: 1fr 400px;
  gap: 40px;
  max-width: 1200px;
  margin: 0 auto;
}

.details-card {
  background: var(--bg-card);
  box-shadow: var(--neu-raised);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 24px;
}

.details-card h3 {
  color: var(--text-primary);
  font-size: 18px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.info-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid var(--border-color);
}

.info-row:last-child {
  border-bottom: none;
}

.info-row .label {
  color: var(--text-muted);
  font-size: 14px;
}

.info-row .value {
  color: var(--text-primary);
  font-weight: 600;
  font-size: 14px;
}

.status-preparing {
  color: #f59e0b;
}

.status-packed {
  color: #174871;
}

.status-ready {
  color: var(--accent-cyan);
}

.status-shipped {
  color: #a77693;
}

.status-delivered {
  color: #10b981;
}

.status-cancelled {
  color: #ef4444;
}

.order-items {
  margin-top: 16px;
}

.order-item {
  display: flex;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--border-color);
}

.order-item:last-child {
  border-bottom: none;
}

.item-image {
  width: 60px;
  height: 60px;
  border-radius: 8px;
  overflow: hidden;
  background: var(--bg-product-img);
  display: flex;
  align-items: center;
  justify-content: center;
}

.item-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.image-placeholder {
  color: var(--accent-cyan);
  font-size: 12px;
  font-weight: 600;
}

.item-details h4 {
  color: var(--text-primary);
  font-size: 14px;
  margin-bottom: 4px;
  line-height: 1.4;
}

.item-details p {
  color: var(--text-muted);
  font-size: 12px;
  margin-bottom: 4px;
}

.item-price {
  color: var(--accent-cyan);
  font-weight: 600;
  font-size: 14px;
}

.order-notes {
  color: var(--text-muted);
  font-style: italic;
  line-height: 1.6;
}

.summary-card {
  background: var(--bg-card);
  box-shadow: var(--neu-raised);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 24px;
  position: sticky;
  top: 20px;
}

.summary-card h3 {
  color: var(--text-primary);
  font-size: 20px;
  margin-bottom: 20px;
}

.summary-totals {
  margin-bottom: 24px;
}

.total-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  color: var(--text-muted);
  font-size: 14px;
}

.total-line.final-total {
  color: var(--text-primary);
  font-weight: 600;
  font-size: 16px;
}

.total-divider {
  height: 1px;
  background: var(--border-color);
  margin: 12px 0;
}

.next-steps {
  margin-bottom: 24px;
}

.next-steps h4 {
  color: var(--text-primary);
  font-size: 16px;
  margin-bottom: 12px;
}

.next-steps ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.next-steps li {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text-muted);
  font-size: 14px;
  margin-bottom: 8px;
}

.next-steps li i {
  color: var(--accent-cyan);
  font-size: 12px;
}

.confirmation-actions {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.btn-primary,
.btn-secondary {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 20px;
  border-radius: 8px;
  text-decoration: none;
  font-weight: 600;
  font-size: 14px;
  transition: all 0.3s ease;
}

.btn-primary {
  background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
  color: #fff;
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3);
}

.btn-secondary {
  background: var(--bg-card-hover);
  color: var(--text-primary);
  border: 1px solid var(--border-color);
}

.btn-secondary:hover {
  background: var(--bg-card);
  transform: translateY(-2px);
}

@media (max-width: 768px) {
  .confirmation-layout {
    grid-template-columns: 1fr;
    gap: 24px;
  }
  
  .confirmation-header h1 {
    font-size: 28px;
  }
  
  .confirmation-header p {
    font-size: 16px;
  }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
