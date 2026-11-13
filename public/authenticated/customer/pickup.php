<?php
// customer/pickup.php - QR pickup code display
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$orderId = intval($_GET['order_id'] ?? 0);
$userId = getUserId();

if (!$orderId) {
    header('Location: orders.php');
    exit;
}

// Get order details
try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("
        SELECT o.*, 
               CONCAT(u.firstname, ' ', u.lastname) as customer_name
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        header('Location: orders.php?error=order_not_found');
        exit;
    }

    // Items for the printable pickup slip (the interactive dashboard above
    // never needed a line-item breakdown, but a receipt does).
    $itemsStmt = $pdo->prepare("
        SELECT oi.quantity, oi.price, p.name
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching order: " . $e->getMessage());
    header('Location: orders.php?error=database_error');
    exit;
}

$pageTitle = 'Pickup QR Code - Order #' . $order['invoice_no'];

// Prepaid orders (gcash/paymaya/xendit) must show a confirmed payment
// before the pickup slip/receipt can be printed - payment_status is set
// only by the Xendit webhook or a staff-verified manual reference, never
// by anything the customer's browser claims here. Computed BEFORE the
// expiry check below, since that check needs to know payment status too.
$paymentMethodLower = strtolower((string)($order['payment_method'] ?? ''));
$isPrepaidMethod = in_array($paymentMethodLower, ['gcash', 'paymaya', 'xendit'], true);
// Store credit is spent at checkout and already deducted from total_amount,
// so credit that covered the whole order leaves a zero balance - nothing is
// outstanding, which makes it paid for every purpose on this page even though
// no gateway was ever charged (payment_status stays 'pending' until the
// counter completes the pickup). Without this an onsite order settled
// entirely in credit would hit the expiry rollback below and be cancelled
// with the customer's credit already spent.
$isSettledByCredit = (float)($order['credit_applied'] ?? 0) > 0
    && (float)($order['total_amount'] ?? 0) <= 0.009;
$isPaid = ($order['payment_status'] ?? '') === 'paid' || $isSettledByCredit;
$isAlreadyPickedUp = !empty($order['pickup_verified_at']);
$needsPayment = $isPrepaidMethod && !$isPaid && !$isAlreadyPickedUp;

// Check if pickup code is still valid. An already-paid order has no
// business expiring or being auto-cancelled here - the money is real and
// already collected, so the pickup code (and any "time left" framing)
// stays valid indefinitely; only unpaid holds (onsite, or a prepaid order
// nobody ever completed payment for) are time-boxed and eligible for the
// auto-cancel-and-restock rollback below.
$rawExpired = strtotime($order['pickup_code_expires_at']) < time();
$isExpired = $rawExpired && !$isPaid;
if ($isExpired && ($order['status'] ?? '') !== 'cancelled') {
    // Attempt rollback and refresh order state if expired
    if (function_exists('cancelPickupOrderAndRestoreStock')) {
        cancelPickupOrderAndRestoreStock((int)$order['id']);
        // Reload order to reflect new status
        try {
            $stmt = getConnection()->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([(int)$order['id']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: $order;
        } catch (Throwable $_e) {}
    }
}
$payNowUrl = $paymentMethodLower === 'xendit'
    ? 'xendit_payment.php?id=' . (int)$order['id']
    : 'epayment_instructions.php?id=' . (int)$order['id'];
$payNowLabel = $paymentMethodLower === 'xendit' ? 'Pay with Xendit' : 'Complete Payment';

// Payment line for the printable slip - "Print Pickup Slip" is only ever
// reachable when $needsPayment is false, so this only has to describe the
// two ways that can happen: money already confirmed (prepaid + paid), or
// this method collects payment in person (onsite/COD) at pickup.
$slipPaymentStatus = ($isPrepaidMethod || $isSettledByCredit)
    ? ($isSettledByCredit && !$isPrepaidMethod ? 'Paid in Full with Store Credit' : 'Payment Successfully Processed')
    : 'Payment Due at Pickup (' . ($order['payment_method_display'] ?? ucfirst($paymentMethodLower)) . ')';
$slipVat = phVatBreakdown((float)($order['total_amount'] ?? 0));

// QR payload - the exact shape both staff scanners parse natively
// (scan_fab.php's scanProcessData() and scan/verify.php). It used to be a URL
// hardcoded to a dev machine's LAN IP and an old project folder, which no
// phone outside that network could ever open.
$qrPayload = 'order:' . (int)$order['id'] . ':' . $order['pickup_code'];

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

<!-- QR Pickup Page -->
<section class="pickup-page pickup-onscreen">
    <div class="container">
        <div class="pickup-container">
            <div class="pickup-header">
                <a href="orders.php" class="back-btn">
                    <i class="fi fi-rs-angle-left"></i> Back to Orders
                </a>
                <h1>Pickup QR Code</h1>
                <p>Show this QR code to staff for order pickup</p>
            </div>
            
            <div class="pickup-content">
                <!-- Order Info Card -->
                <div class="order-info-card">
                    <div class="order-header">
                        <h2>Order #<?php echo htmlspecialchars($order['invoice_no']); ?></h2>
                        <span class="status-badge status-<?php echo $order['status']; ?>">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </div>
                    
                    <div class="order-details">
                        <div class="detail-row">
                            <span class="label">Customer:</span>
                            <span class="value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Order Date:</span>
                            <span class="value"><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Total Amount:</span>
                            <span class="value total-amount"><?php echo formatCurrency($order['total_amount']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Payment:</span>
                            <span class="value"><?php echo ucfirst($order['payment_method']); ?></span>
                        </div>
                        <?php if ($isPrepaidMethod): ?>
                        <div class="detail-row">
                            <span class="label">Payment Status:</span>
                            <span class="value payment-status-badge <?php echo $isPaid ? 'paid' : 'pending'; ?>">
                                <?php echo $isPaid ? 'Paid' : 'Payment Pending'; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <span class="label">Tracking Code:</span>
                            <span class="value tracking-code"><?php echo htmlspecialchars($order['tracking_code']); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- QR Code Card -->
                <div class="qr-card">
                    <?php if ($isAlreadyPickedUp): ?>
                        <div class="pickup-status completed">
                            <div class="status-icon">
                                <i class="fi fi-ss-check-circle"></i>
                            </div>
                            <h3>Order Already Picked Up</h3>
                            <p>This order was picked up on <?php echo date('M j, Y g:i A', strtotime($order['pickup_verified_at'])); ?></p>
                        </div>
                    <?php elseif ($isExpired): ?>
                        <div class="pickup-status expired">
                            <div class="status-icon">
                                <i class="fi fi-ss-exclamation-triangle"></i>
                            </div>
                            <h3>Pickup Code Expired</h3>
                            <p>This pickup code expired on <?php echo date('M j, Y g:i A', strtotime($order['pickup_code_expires_at'])); ?></p>
                            <p>Please contact customer service for assistance.</p>
                        </div>
                    <?php else: ?>
                        <?php if ($needsPayment): ?>
                        <div class="payment-pending-banner">
                            <i class="fi fi-rr-credit-card"></i>
                            <div>
                                <strong>Payment Pending</strong>
                                <p>Your QR code and pickup code are ready to use, but this order must be paid online before staff can release it at the store. Pay now to avoid delays at pickup.</p>
                            </div>
                            <a href="<?php echo htmlspecialchars($payNowUrl); ?>" class="action-btn pay-btn">
                                <i class="fi fi-rr-credit-card"></i>
                                <?php echo htmlspecialchars($payNowLabel); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <!-- Active QR Code -->
                        <div class="qr-code-section">
                            <h3>Scan QR Code for Pickup</h3>
                            <div class="qr-code-container">
                                <div id="qrcode"></div>
                            </div>

                            <div class="pickup-code-manual">
                                <p>Or give this code to our staff:</p>
                                <div class="pickup-code"><?php echo htmlspecialchars($order['pickup_code']); ?></div>
                            </div>

                            <div class="pickup-validity">
                                <?php if ($isPaid): ?>
                                    <!-- Already paid: no expiry framing at all, matching the fact
                                         that the auto-cancel above no longer touches this order
                                         either - the code just stays valid until picked up. -->
                                    <div class="validity-info validity-info--paid">
                                        <i class="fi fi-rs-check-circle"></i>
                                        <span>Paid - ready for pickup anytime</span>
                                    </div>
                                <?php else: ?>
                                    <div class="validity-info">
                                        <i class="fi fi-rs-clock"></i>
                                        <span>Valid until: <?php echo date('M j, Y g:i A', strtotime($order['pickup_code_expires_at'])); ?></span>
                                    </div>

                                    <div class="countdown" id="countdown">
                                        <span>Expires in: <span id="timeLeft"></span></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Instructions -->
                        <div class="pickup-instructions">
                            <h4>Pickup Instructions:</h4>
                            <ol>
                                <li>Visit our store at your convenience</li>
                                <li>Show this QR code or the 6-digit code to our staff</li>
                                <li>Present a valid ID for verification</li>
                                <li>Complete payment if using Cash on Delivery</li>
                                <li>Receive your order items</li>
                            </ol>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Actions -->
                <div class="pickup-actions">
                    <?php if ($needsPayment): ?>
                        <a href="<?php echo htmlspecialchars($payNowUrl); ?>" class="action-btn pay-btn">
                            <i class="fi fi-rr-credit-card"></i>
                            <?php echo htmlspecialchars($payNowLabel); ?>
                        </a>
                    <?php else: ?>
                        <button type="button" class="action-btn print-btn" onclick="window.print();">
                            <i class="fi fi-rs-print"></i>
                            Print Pickup Slip
                        </button>
                    <?php endif; ?>

                    <a href="orders.php" class="action-btn secondary-btn">
                        <i class="fi fi-rs-list"></i>
                        View All Orders
                    </a>
                    
                    <?php if (!$isAlreadyPickedUp && !$isExpired): ?>
                        <button class="action-btn share-btn" onclick="sharePickupCode()">
                            <i class="fi fi-rs-share"></i>
                            Share Pickup Info
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Store Information -->
                <div class="store-info">
                    <h4>Store Location & Hours</h4>
                    <div class="store-details">
                        <div class="store-address">
                            <i class="fi fi-rs-map-marker"></i>
                            <div>
                                <strong>TechWorld Computer Store</strong><br>
                                Unit 48C, 5th Street, Brgy. 104<br>
                                East Grace Park, Caloocan, Philippines
                            </div>
                        </div>

                        <div class="store-hours">
                            <i class="fi fi-rs-clock"></i>
                            <div>
                                <strong>Store Hours:</strong><br>
                                Mon - Sat: 9:00 AM - 5:00 PM<br>
                                Sunday: Closed
                            </div>
                        </div>

                        <div class="store-contact">
                            <i class="fi fi-rs-phone-call"></i>
                            <div>
                                <strong>Contact:</strong><br>
                                Phone: 0995 586 7973<br>
                                Email: techworldcomputerstore@gmail.com
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Print-only pickup slip. Hidden on screen; the interactive dashboard
     above (QR widget, countdown, action buttons, sidebar/nav chrome) is
     what "Print Pickup Slip" used to send to the printer, which read as
     a screenshot of the app rather than a receipt. This is a clean,
     itemized document instead - shown only inside @media print below. -->
<section class="print-slip">
    <div class="print-slip-header">
        <div class="print-slip-brand">TechWorld</div>
        <div class="print-slip-title">Order Confirmed</div>
        <div class="print-slip-status"><?php echo htmlspecialchars($isAlreadyPickedUp ? 'Picked Up' : $slipPaymentStatus); ?></div>
    </div>

    <div class="print-slip-meta">
        <div><strong>Invoice #:</strong> <?php echo htmlspecialchars($order['invoice_no']); ?></div>
        <div><strong>Order Date:</strong> <?php echo date('F j, Y g:i A', strtotime($order['created_at'])); ?></div>
        <div><strong>Customer:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></div>
        <div><strong>Payment Method:</strong> <?php echo htmlspecialchars($order['payment_method_display'] ?? ucfirst($paymentMethodLower)); ?></div>
        <div><strong>Tracking Code:</strong> <?php echo htmlspecialchars($order['tracking_code']); ?></div>
        <div><strong>Pickup Code:</strong> <?php echo htmlspecialchars($order['pickup_code']); ?></div>
        <?php if ($isAlreadyPickedUp): ?>
        <div><strong>Picked Up:</strong> <?php echo date('F j, Y g:i A', strtotime($order['pickup_verified_at'])); ?></div>
        <?php endif; ?>
    </div>

    <table class="print-slip-items">
        <thead>
            <tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>
        </thead>
        <tbody>
            <?php foreach ($orderItems as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['name']); ?></td>
                <td><?php echo (int)$item['quantity']; ?></td>
                <td><?php echo formatCurrency($item['price']); ?></td>
                <td><?php echo formatCurrency($item['price'] * $item['quantity']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="print-slip-totals">
        <div><span>Subtotal</span><span><?php echo formatCurrency($slipVat['net']); ?></span></div>
        <div><span>VAT (12%)</span><span><?php echo formatCurrency($slipVat['vat']); ?></span></div>
        <div class="print-slip-grand"><span>Total</span><span><?php echo formatCurrency($order['total_amount']); ?></span></div>
    </div>

    <div class="print-slip-footer">
        <p>Thank you for shopping with TechWorld.</p>
        <p>Unit 48C, 5th Street, Brgy. 104, East Grace Park, Caloocan, Philippines</p>
    </div>
</section>

<style>
.pickup-page {
    padding: 40px 0;
    background: var(--page-bg-gradient);
    min-height: 100vh;
}

.pickup-container {
    max-width: 800px;
    margin: 0 auto;
}

.pickup-header {
    text-align: center;
    margin-bottom: 40px;
}

.back-btn {
    color: var(--accent-cyan);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.back-btn:hover {
    color: var(--accent-blue);
}

.pickup-header h1 {
    color: var(--text-primary);
    font-size: 32px;
    margin-bottom: 10px;
}

.pickup-header p {
    color: var(--text-muted);
    font-size: 16px;
}

.pickup-content {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.order-info-card {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 30px;
    border: 1px solid var(--border-color);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.order-header h2 {
    color: var(--text-primary);
    font-size: 24px;
}

.status-badge {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Matches orders.status's real ENUM values (preparing/packed/ready/shipped/
   completed/cancelled) - status-pending/status-processing never matched an
   actual order, and preparing/packed/shipped had no rule at all. */
.status-preparing { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
.status-packed { background: rgba(91, 155, 213, 0.2); color: #5b9bd5; }
.status-ready { background: rgba(16, 185, 129, 0.2); color: #10b981; }
.status-shipped { background: rgba(91, 155, 213, 0.2); color: #5b9bd5; }
.status-completed { background: rgba(16, 185, 129, 0.2); color: #10b981; }
.status-cancelled { background: rgba(239, 68, 68, 0.2); color: #ef4444; }

.order-details {
    display: grid;
    gap: 15px;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.detail-row:last-child {
    border-bottom: none;
}

.label {
    color: var(--text-muted);
    font-weight: 500;
}

.value {
    color: var(--text-primary);
    font-weight: 600;
}

.total-amount {
    color: var(--accent-cyan);
    font-size: 18px;
}

.tracking-code {
    font-family: monospace;
    background: var(--accent-cyan-bg);
    padding: 4px 8px;
    border-radius: 4px;
    border: 1px solid var(--accent-cyan-bg-20);
}

.qr-card {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 40px;
    border: 1px solid var(--border-color);
    text-align: center;
}

.pickup-status {
    padding: 40px;
}

.status-icon {
    font-size: 64px;
    margin-bottom: 20px;
}

.pickup-status.completed .status-icon {
    color: #10b981;
}

.pickup-status.expired .status-icon {
    color: #f59e0b;
}

.pickup-status h3 {
    color: var(--text-primary);
    font-size: 24px;
    margin-bottom: 15px;
}

.pickup-status p {
    color: var(--text-muted);
    font-size: 16px;
    line-height: 1.6;
    margin-bottom: 10px;
}

.qr-code-section h3 {
    color: var(--text-primary);
    font-size: 24px;
    margin-bottom: 30px;
}

.qr-code-container {
    background: white;
    padding: 20px;
    border-radius: 15px;
    display: inline-block;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.pickup-code-manual {
    margin-bottom: 20px;
}

.pickup-code-manual p {
    color: var(--text-muted);
    font-size: 14px;
    margin-bottom: 10px;
}

.pickup-code {
    font-family: 'Courier New', monospace;
    font-size: 28px;
    font-weight: 700;
    color: var(--accent-cyan);
    background: var(--accent-cyan-bg);
    border: 2px solid var(--accent-cyan-bg-30);
    border-radius: 8px;
    padding: 15px 20px;
    letter-spacing: 4px;
    display: inline-block;
}

.pickup-validity {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-bottom: 30px;
}

.validity-info {
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 14px;
}

.countdown {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 8px;
    padding: 12px 20px;
    color: #f59e0b;
    font-weight: 600;
    font-size: 16px;
}

/* Paid orders get the calm "no expiry" treatment instead of the amber
   countdown chip - green, matching the payment-status-badge.paid color
   used elsewhere on this page, so "paid" reads consistently everywhere. */
.validity-info--paid {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 8px;
    padding: 12px 20px;
    color: #22c55e;
    font-weight: 600;
}

.pickup-instructions {
    background: rgba(23, 72, 113, 0.1);
    border: 1px solid rgba(23, 72, 113, 0.2);
    border-radius: 12px;
    padding: 25px;
    text-align: left;
    margin-top: 30px;
}

.pickup-instructions h4 {
    color: #174871;
    margin-bottom: 15px;
    font-size: 18px;
}

.pickup-instructions ol {
    color: var(--text-muted);
    padding-left: 20px;
    line-height: 1.8;
}

.pickup-instructions li {
    margin-bottom: 8px;
}

.pickup-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.action-btn {
    padding: 15px 20px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.print-btn {
    background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
    color: white;
}

.print-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3);
}

.secondary-btn {
    background: var(--border-color);
    color: var(--text-primary);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.secondary-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}

.share-btn {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.share-btn:hover {
    background: rgba(16, 185, 129, 0.2);
}

.pay-btn {
    background: linear-gradient(45deg, #10b981, #059669);
    color: white;
    text-decoration: none;
}

.pay-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
}

.payment-status-badge {
    font-weight: 700;
}

.payment-status-badge.paid {
    color: #22c55e;
}

.payment-status-badge.pending {
    color: #f59e0b;
}

.payment-pending-banner {
    display: flex;
    align-items: center;
    gap: 16px;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    text-align: left;
}

.payment-pending-banner i {
    font-size: 28px;
    color: #f59e0b;
    flex-shrink: 0;
}

.payment-pending-banner strong {
    display: block;
    color: var(--text-primary);
    font-size: 16px;
    margin-bottom: 4px;
}

.payment-pending-banner p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0;
    line-height: 1.5;
}

.payment-pending-banner .pay-btn {
    flex-shrink: 0;
    padding: 12px 20px;
    white-space: nowrap;
}

@media (max-width: 600px) {
    .payment-pending-banner {
        flex-direction: column;
        text-align: center;
    }
}

.store-info {
    background: var(--bg-card);
    box-shadow: var(--neu-raised);
    border-radius: 15px;
    padding: 30px;
    border: 1px solid var(--border-color);
}

.store-info h4 {
    color: var(--text-primary);
    font-size: 20px;
    margin-bottom: 25px;
    text-align: center;
}

.store-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
}

.store-address,
.store-hours,
.store-contact {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    color: var(--text-muted);
    line-height: 1.6;
}

.store-address i,
.store-hours i,
.store-contact i {
    color: var(--accent-cyan);
    font-size: 20px;
    margin-top: 2px;
}

.store-address strong,
.store-hours strong,
.store-contact strong {
    color: var(--text-primary);
}

@media (max-width: 768px) {
    .pickup-container {
        margin: 0 20px;
    }
    
    .qr-card {
        padding: 30px 20px;
    }
    
    .pickup-code {
        font-size: 24px;
        letter-spacing: 2px;
        padding: 15px;
    }
    
    .pickup-actions {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .store-details {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .detail-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
}
</style>

<style>
/* Print-only receipt (see markup above). Hidden on screen; swapped in for
   the whole interactive dashboard - sidebar, floating cart, background
   video and page chrome included - when printing. */
.print-slip { display:none; }

.print-slip-header { text-align:center; margin-bottom:24px; padding-bottom:16px; border-bottom:2px solid #111; }
.print-slip-brand { font-size:14px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#555; }
.print-slip-title { font-size:26px; font-weight:800; color:#111; margin:6px 0 4px; }
.print-slip-status { font-size:15px; font-weight:600; color:#0a7a3d; }

.print-slip-meta { display:grid; grid-template-columns:1fr 1fr; gap:6px 24px; margin-bottom:24px; font-size:13px; color:#222; }

.print-slip-items { width:100%; border-collapse:collapse; margin-bottom:20px; font-size:13px; }
.print-slip-items th, .print-slip-items td { padding:8px; text-align:left; border-bottom:1px solid #ddd; }
.print-slip-items th:not(:first-child), .print-slip-items td:not(:first-child) { text-align:right; }

.print-slip-totals { max-width:280px; margin:0 0 24px auto; font-size:13px; }
.print-slip-totals > div { display:flex; justify-content:space-between; padding:4px 0; }
.print-slip-grand { border-top:2px solid #111; margin-top:6px; padding-top:8px !important; font-weight:800; font-size:15px; }

.print-slip-footer { text-align:center; font-size:12px; color:#555; border-top:1px solid #ddd; padding-top:12px; }
.print-slip-footer p { margin:4px 0; }

/* Top level, NOT nested in @media print below - @page only applies to paged
   media anyway, and engines disagree about honouring a nested one.

   margin:0 removes the browser's own print furniture: the page URL, title,
   date and page number are drawn INSIDE the @page margin box, so leaving no
   margin leaves nowhere to draw them. The paper margin returns as padding on
   the slip. Same approach as reports_analytics.php and the customer invoice. */
@page { size:A4; margin:0; }

@media print {
  .pickup-onscreen, .site-bg-video, .site-bg-video-scrim, .floating-cart-btn,
  .customer-sidebar, .customer-menu-toggle, .customer-sidebar-backdrop, .main-footer {
    display:none !important;
  }
  .print-slip { display:block !important; }
  body { background:#fff !important; margin:0 !important; }
  /* Padding, not @page margin - see the note above. */
  .print-slip { padding:14mm 14mm 16mm !important; box-sizing:border-box; }
}
</style>

<!-- Load QR Code Library -->
<script src="<?= WEB_ROOT ?>davidshimjs-qrcodejs-04f46c6/qrcode.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!$isAlreadyPickedUp && !$isExpired): ?>
        // Generate QR Code
        const qrContainer = document.getElementById('qrcode');
        if (qrContainer) {
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: '<?php echo $qrPayload; ?>',
                width: 250,
                height: 250,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }
        
        <?php if (!$isPaid): ?>
        // Setup countdown timer (fallback to 60 minutes if already expired) -
        // paid orders skip this entirely: there's no #countdown/#timeLeft in
        // the DOM for them (see the PHP above), and no expiry to count down
        // to in the first place.
        let expiryTime = new Date('<?php echo $order['pickup_code_expires_at']; ?>').getTime();
        const nowBoot = Date.now();
        if (isNaN(expiryTime) || expiryTime <= nowBoot) {
            expiryTime = nowBoot + (60 * 60 * 1000); // 60 minutes from now
            // Also reflect the new "Valid until" timestamp in the UI
            const validTextEl = document.querySelector('.validity-info span');
            if (validTextEl) {
                const d = new Date(expiryTime);
                const opts = { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' };
                validTextEl.textContent = 'Valid until: ' + d.toLocaleString(undefined, opts);
            }
        }
        updateCountdown();

        function updateCountdown() {
            const now = Date.now();
            const distance = expiryTime - now;

            if (distance < 0) {
                // Gracefully stop at 0m 0s when countdown completes
                document.getElementById('timeLeft').textContent = '0m 0s';
                document.getElementById('countdown').style.background = 'rgba(239, 68, 68, 0.1)';
                document.getElementById('countdown').style.borderColor = 'rgba(239, 68, 68, 0.3)';
                document.getElementById('countdown').style.color = '#ef4444';
                return;
            }

            const minutes = Math.floor(distance / 1000 / 60);
            const seconds = Math.floor((distance / 1000) % 60);
            document.getElementById('timeLeft').textContent = `${minutes}m ${seconds}s`;

            // Update every second
            setTimeout(updateCountdown, 1000);
        }
        <?php endif; ?>
    <?php endif; ?>
});

function sharePickupCode() {
    const shareData = {
        title: 'TechWorld Pickup Code',
        text: `Order #<?php echo $order['invoice_no']; ?> - Pickup Code: <?php echo $order['pickup_code']; ?>`,
        url: window.location.href   
    };
    
    if (navigator.share) {
        navigator.share(shareData);
    } else {
        // Fallback to copying to clipboard
        const textToCopy = `TechWorld Order Pickup\nOrder: #<?php echo $order['invoice_no']; ?>\nPickup Code: <?php echo $order['pickup_code']; ?>\nValid until: <?php echo date('M j, Y g:i A', strtotime($order['pickup_code_expires_at'])); ?>`;
        
        navigator.clipboard.writeText(textToCopy).then(() => {
            showNotification('Pickup information copied to clipboard!', 'success');
        }).catch(() => {
            showNotification('Unable to copy to clipboard', 'error');
        });
    }
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>