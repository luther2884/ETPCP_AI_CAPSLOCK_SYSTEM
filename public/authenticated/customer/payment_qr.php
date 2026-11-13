<?php
// customer/payment_qr.php - Payment QR code display for store pickup orders
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
    
    // Check if this is a store pickup order
    if ($order['payment_method'] !== 'onsite' || $order['delivery_method'] !== 'pickup') {
        header('Location: orders.php?error=invalid_order_type');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error fetching order: " . $e->getMessage());
    header('Location: orders.php?error=database_error');
    exit;
}

$pageTitle = 'Payment QR Code - Order #' . $order['invoice_no'];

// Generate a pickup code if this order somehow doesn't have one (for older orders)
if (empty($order['pickup_code'])) {
    try {
        $pickupCode = generatePickupCode();
        // For ONSITE checkout, QR/pickup code must expire in 1 hour
        $pickupExpiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $updateStmt = $pdo->prepare("UPDATE orders SET pickup_code = ?, pickup_code_expires_at = ? WHERE id = ?");
        $updateStmt->execute([$pickupCode, $pickupExpiresAt, $orderId]);

        $order['pickup_code'] = $pickupCode;
        $order['pickup_code_expires_at'] = $pickupExpiresAt;
    } catch (PDOException $e) {
        error_log("Error generating pickup code: " . $e->getMessage());
        header('Location: orders.php?error=payment_code_error');
        exit;
    }
}

// Ensure ONSITE payment QR/code always expires in 1 hour (idempotent adjustment)
if (strtolower((string)$order['payment_method']) === 'onsite') {
    $desiredExpiryTs = time() + 3600; // 1 hour from now
    $currentExpiryTs = !empty($order['pickup_code_expires_at']) ? strtotime($order['pickup_code_expires_at']) : 0;
    // If missing, already expired, or longer than 1 hour ahead, clamp to 1 hour window
    if ($currentExpiryTs < time() || $currentExpiryTs > $desiredExpiryTs + 60) {
        $newExpiry = date('Y-m-d H:i:s', $desiredExpiryTs);
        try {
            $upd = $pdo->prepare("UPDATE orders SET pickup_code_expires_at = ? WHERE id = ?");
            $upd->execute([$newExpiry, $orderId]);
            $order['pickup_code_expires_at'] = $newExpiry;
        } catch (Throwable $_e) { /* ignore */ }
    }
}

// Check if pickup code is still valid
$isExpired = strtotime($order['pickup_code_expires_at']) < time();
$isAlreadyPaid = !empty($order['pickup_verified_at']) || $order['status'] === 'completed';

// Generate QR code for the payment
$qrCodeData = null;
$qrCodePath = null;
$debugInfo = '';

try {
    $invoiceNo = $order['invoice_no'] ?? str_pad($order['id'], 6, '0', STR_PAD_LEFT);
    $totalAmount = $order['total_amount'] ?? $order['total'];
    $trackingCode = $order['tracking_code'] ?? null;
    
    $debugInfo = "Debug: Payment method: {$order['payment_method']}, Delivery: {$order['delivery_method']}, Invoice: $invoiceNo";
    
    // Encode the exact payload the staff scanners expect for ANY order that
    // has a pickup code - not just 'onsite'. How (or whether) money is
    // collected is the scanner's business; the QR's only job is to identify
    // the order. Gating this on onsite left prepaid pickup orders carrying a
    // human-readable text blob instead, and the scanner's fallback then
    // grabbed the first 6-digit run it found - the invoice number, not the
    // pickup code - so scanning one said "order not found".
    if (!empty($order['pickup_code'])) {
        // Payload consumed by public/authenticated/scan/verify.php
        $payload = 'order:' . (int)$order['id'] . ':' . $order['pickup_code'];
        $qrCodeData = ['data' => $payload];
        $debugInfo .= " | JS QR Payload: $payload";
    } else {
        // Fallback to existing generator when not onsite
        $qrResult = function_exists('generateOrderQRCode') ? generateOrderQRCode($orderId, $invoiceNo, $totalAmount, $trackingCode) : null;
        // Check if it's a string (file path) or array (data for JS generation)
        if (is_string($qrResult) && $qrResult !== '') {
            $qrCodePath = $qrResult;
            $debugInfo .= " | QR Path: $qrCodePath";
        } elseif (is_array($qrResult)) {
            $qrCodeData = $qrResult;
            $debugInfo .= " | QR Data: " . (isset($qrResult['data']) ? $qrResult['data'] : 'No data');
        } else {
            // As a last resort, create a minimal JS payload with basic info
            $qrCodeData = ['data' => 'Order #' . ($invoiceNo) . "\nAmount: " . formatCurrency($totalAmount) . "\nTechWorld Store"];
            $debugInfo .= " | QR Data: fallback-basic";
        }
    }
} catch (Exception $e) {
    error_log('Error generating QR code: ' . $e->getMessage());
    $debugInfo .= " | Error: " . $e->getMessage();
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

<!-- Payment QR Page -->
<section class="payment-qr-page">
    <div class="container">
        <div class="payment-qr-container">
            <div class="payment-qr-card">
                <div class="payment-header">
                    <h1><i class="fi fi-rr-credit-card"></i> Payment QR Code</h1>
                    <p class="order-info">Order #<?php echo htmlspecialchars($order['invoice_no']); ?></p>
                </div>
                
                <div class="payment-details">
                    <div class="amount-section">
                        <h3>Amount to Pay</h3>
                        <div class="amount-display">
                            <?php echo formatCurrency($order['total_amount']); ?>
                        </div>
                    </div>
                    
                    <div class="customer-info">
                        <h4>Customer: <?php echo htmlspecialchars($order['customer_name']); ?></h4>
                        <p>Order Date: <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></p>
                    </div>
                </div>
                
                <?php if ($isAlreadyPaid): ?>
                    <!-- Already Paid -->
                    <div class="payment-status paid">
                        <div class="status-icon">
                            <i class="fi fi-rr-check-circle"></i>
                        </div>
                        <h3>Payment Completed</h3>
                        <p>This order has already been paid for.</p>
                        <div class="payment-info">
                            <p><strong>Paid on:</strong> <?php echo date('M j, Y g:i A', strtotime($order['pickup_verified_at'] ?? $order['updated_at'])); ?></p>
                        </div>
                    </div>
                <?php elseif ($isExpired): ?>
                    <!-- Expired -->
                    <div class="payment-status expired">
                        <div class="status-icon">
                            <i class="fi fi-rr-cross-circle"></i>
                        </div>
                        <h3>Payment Code Expired</h3>
                        <p>This payment code has expired. Please contact support for assistance.</p>
                        <div class="expiry-info">
                            <p><strong>Expired on:</strong> <?php echo date('M j, Y g:i A', strtotime($order['pickup_code_expires_at'])); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Active Payment QR Code -->
                    <div class="payment-qr-section">
                        <h3>Scan QR Code to Pay</h3>
                        <div class="qr-code-container">
                            <?php if ($qrCodePath): ?>
                                <!-- Display pre-generated QR code image -->
                                <img src="<?= htmlspecialchars($qrCodePath) ?>" alt="Order QR Code" style="width:250px;height:250px;display:block;">
                            <?php else: ?>
                                <!-- Generate QR code with JavaScript -->
                                <div id="payment-qrcode" style="width:250px;height:250px;border:1px solid #ccc;display:flex;align-items:center;justify-content:center;color:#666;background:white;border-radius:8px;margin:0 auto;">
                                    <span>Loading QR Code...</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="payment-code-manual">
                            <p>Or provide this code manually:</p>
                            <div class="payment-code">
                                <?php echo htmlspecialchars($order['pickup_code']); ?>
                            </div>
                        </div>

                        <div class="payment-validity">
                            <div class="validity-info">
                                <i class="fi fi-rs-clock"></i>
                                <span>Valid until: <?php echo date('M j, Y g:i A', strtotime($order['pickup_code_expires_at'])); ?></span>
                            </div>
                            
                            <div class="countdown" id="countdown">
                                <span>Expires in: <span id="timeLeft"></span></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Instructions -->
                    <div class="payment-instructions">
                        <h4>Payment Instructions:</h4>
                        <ol>
                            <li>Visit our store with this QR code or payment code</li>
                            <li>Show the QR code to our staff for scanning</li>
                            <li>Complete your payment at the counter</li>
                            <li>Collect your order items</li>
                        </ol>
                    </div>
                <?php endif; ?>
                
                <!-- Actions -->
                <div class="payment-actions">
                    <a href="orders.php" class="action-btn secondary-btn">
                        <i class="fi fi-rs-list"></i>
                        View All Orders
                    </a>
                    
                    <?php if (!$isAlreadyPaid && !$isExpired): ?>
                        <button class="action-btn share-btn" onclick="sharePaymentCode()">
                            <i class="fi fi-rs-share"></i>
                            Share Payment Info
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Store Information -->
                <div class="store-info">
                    <h4>Store Information</h4>
                    <div class="store-details">
                        <div class="store-address">
                            <i class="fi fi-rr-marker"></i>
                            <div>
                                <strong>TechWorld Computer Store</strong><br>
                                Unit 48C, 5th Street, Brgy. 104<br>
                                East Grace Park, Caloocan, Philippines
                            </div>
                        </div>

                        <div class="store-hours">
                            <i class="fi fi-rr-clock"></i>
                            <div>
                                <strong>Store Hours:</strong><br>
                                Mon - Sat: 9:00 AM - 5:00 PM<br>
                                Sunday: Closed
                            </div>
                        </div>

                        <div class="store-contact">
                            <i class="fi fi-rr-phone"></i>
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

<style>
.payment-qr-page {
    padding: 40px 0;
    background: var(--page-bg-gradient);
    min-height: 100vh;
}

.payment-qr-container {
    max-width: 600px;
    margin: 0 auto;
    padding: 0 20px;
}

.payment-qr-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 40px;
    text-align: center;
}

.payment-header h1 {
    color: var(--text-primary);
    font-size: 28px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.payment-header .order-info {
    color: var(--text-muted);
    font-size: 16px;
    margin-bottom: 30px;
}

.payment-details {
    margin-bottom: 30px;
}

.amount-section {
    margin-bottom: 20px;
}

.amount-section h3 {
    color: var(--text-muted);
    font-size: 16px;
    margin-bottom: 10px;
}

.amount-display {
    font-size: 36px;
    font-weight: 700;
    color: var(--accent-cyan);
    text-shadow: 0 0 20px var(--accent-cyan-bg-30);
}

.customer-info {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.customer-info h4 {
    color: var(--text-primary);
    font-size: 18px;
    margin-bottom: 8px;
}

.customer-info p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0;
}

.payment-status {
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.payment-status.paid {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.payment-status.expired {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.status-icon {
    font-size: 48px;
    margin-bottom: 20px;
}

.payment-status.paid .status-icon {
    color: #10b981;
}

.payment-status.expired .status-icon {
    color: #ef4444;
}

.payment-status h3 {
    color: var(--text-primary);
    font-size: 24px;
    margin-bottom: 10px;
}

.payment-status p {
    color: var(--text-muted);
    font-size: 16px;
    margin-bottom: 20px;
}

.payment-info, .expiry-info {
    background: rgba(255, 255, 255, 0.04);
    border-radius: 8px;
    padding: 15px;
}

.payment-info p, .expiry-info p {
    color: var(--text-primary);
    margin: 0;
    font-size: 14px;
}

.payment-qr-section {
    margin-bottom: 30px;
}

.payment-qr-section h3 {
    color: var(--text-primary);
    font-size: 20px;
    margin-bottom: 20px;
}

.qr-code-container {
    display: flex;
    justify-content: center;
    margin-bottom: 30px;
}

.payment-code-manual {
    margin-bottom: 20px;
}

.payment-code-manual p {
    color: var(--text-muted);
    font-size: 14px;
    margin-bottom: 10px;
}

.payment-code {
    font-family: 'Courier New', monospace;
    font-size: 24px;
    font-weight: 700;
    color: var(--accent-cyan);
    background: var(--accent-cyan-bg);
    border: 2px solid var(--accent-cyan-bg-30);
    border-radius: 8px;
    padding: 15px 20px;
    letter-spacing: 2px;
    display: inline-block;
}

.payment-validity {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.validity-info {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--text-muted);
    font-size: 14px;
    margin-bottom: 10px;
}

.countdown {
    text-align: center;
    color: #f59e0b;
    font-weight: 600;
    font-size: 16px;
}

.payment-instructions {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 30px;
    text-align: left;
}

.payment-instructions h4 {
    color: var(--text-primary);
    font-size: 16px;
    margin-bottom: 15px;
}

.payment-instructions ol {
    color: var(--text-muted);
    font-size: 14px;
    line-height: 1.6;
    margin: 0;
    padding-left: 20px;
}

.payment-instructions li {
    margin-bottom: 8px;
}

.payment-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 30px;
}

.action-btn {
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
    border: none;
    cursor: pointer;
}

.action-btn.secondary-btn {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: var(--text-primary);
}

.action-btn.secondary-btn:hover {
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.25);
}

.action-btn.share-btn {
    background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
    color: #fff;
}

.action-btn.share-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3);
}

.store-info {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 20px;
    text-align: left;
}

.store-info h4 {
    color: var(--text-primary);
    font-size: 18px;
    margin-bottom: 20px;
    text-align: center;
}

.store-details {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

.store-address, .store-hours, .store-contact {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.store-address i, .store-hours i, .store-contact i {
    color: var(--accent-cyan);
    font-size: 18px;
    margin-top: 2px;
    flex-shrink: 0;
}

.store-address div, .store-hours div, .store-contact div {
    color: var(--text-muted);
    font-size: 14px;
    line-height: 1.5;
}

.store-address strong, .store-hours strong, .store-contact strong {
    color: var(--text-primary);
}

@media (max-width: 768px) {
    .payment-qr-container {
        margin: 0 20px;
    }
    
    .payment-qr-card {
        padding: 30px 20px;
    }
    
    .amount-display {
        font-size: 28px;
    }
    
    .payment-code {
        font-size: 20px;
        padding: 12px 16px;
    }
    
    .payment-actions {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .store-details {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}
</style>

<!-- Load QR Code Library for JavaScript generation -->
<script src="<?php echo WEB_ROOT; ?>davidshimjs-qrcodejs-04f46c6/qrcode.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Generate QR code using davidshim qrcode.js
    const qrCodeElement = document.getElementById('payment-qrcode');
    if (qrCodeElement) {
        <?php if ($qrCodeData): ?>
        const qrData = '<?= addslashes($qrCodeData['data']) ?>';
        <?php else: ?>
        // ASCII amount, deliberately. formatCurrency() prefixes a peso sign
        // (U+20B1), and this QR library picks its QR version from the CHARACTER
        // count while the encoder writes UTF-8 BYTES - so one 3-byte character
        // made it choose a version too small and throw "code length overflow".
        // The throw landed after the innerHTML='' below, which is why the box
        // rendered empty. The amount is scanned as plain text, so "PHP" reads
        // the same and encodes cleanly.
        const qrData = 'Order #<?= htmlspecialchars($order['invoice_no'] ?? str_pad($order['id'], 6, '0', STR_PAD_LEFT)) ?>\nAmount: PHP <?= number_format((float)($order['total_amount'] ?? $order['total']), 2) ?>\nPickup Code: <?= htmlspecialchars($order['pickup_code']) ?>\nTechWorld Store';
        <?php endif; ?>
        
        // Clear any existing content
        qrCodeElement.innerHTML = '';
        
        // Generate QR code using davidshim qrcode.js
        new QRCode(qrCodeElement, {
            text: qrData,
            width: 250,
            height: 250,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
        
        console.log('QR code generated with data:', qrData);
    }
    
    // Setup countdown timer
    const expiryTime = new Date('<?php echo $order['pickup_code_expires_at']; ?>').getTime();
    updateCountdown();
    
    function updateCountdown() {
        const now = new Date().getTime();
        const timeLeft = expiryTime - now;
        
        if (timeLeft > 0) {
            const hours = Math.floor(timeLeft / (1000 * 60 * 60));
            const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
            
            document.getElementById('timeLeft').textContent = 
                `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            setTimeout(updateCountdown, 1000);
        } else {
            document.getElementById('timeLeft').textContent = 'EXPIRED';
            location.reload();
        }
    }
});

function sharePaymentCode() {
    if (navigator.share) {
        navigator.share({
            title: 'Payment QR Code - Order #<?php echo $order['invoice_no']; ?>',
            text: 'Pickup Code: <?php echo $order['pickup_code']; ?>\nAmount: <?php echo formatCurrency($order['total_amount']); ?>',
            url: window.location.href
        });
    } else {
        // Fallback: copy to clipboard
        const text = `Pickup Code: <?php echo $order['pickup_code']; ?>\nAmount: <?php echo formatCurrency($order['total_amount']); ?>\nOrder: #<?php echo $order['invoice_no']; ?>`;
        navigator.clipboard.writeText(text).then(() => {
            alert('Payment information copied to clipboard!');
        });
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>