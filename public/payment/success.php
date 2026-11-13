<?php
// public/payment/success.php - Xendit success_return_url landing page.
//
// This is a customer-facing redirect ONLY. It never marks anything as paid
// itself - it polls public/api/payment/status.php, which reflects whatever
// the Xendit webhook has (or hasn't yet) confirmed server-side.
require_once __DIR__ . '/../../config/functions.php';

require_login();
require_role(['customer']);

$orderId = intval($_GET['order_id'] ?? 0);
$userId = getUserId();

if (!$orderId) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php');
    exit;
}

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT id, invoice_no, total_amount, payment_status FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php?error=order_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log('payment/success.php error: ' . $e->getMessage());
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php?error=database_error');
    exit;
}

$pageTitle = 'Payment Status - Order #' . $order['invoice_no'];
include __DIR__ . '/../shared/includes/header.php';
?>

<section class="payment-qr-page">
    <div class="container">
        <div class="payment-qr-container">
            <div class="payment-qr-card">
                <div id="statusIcon" class="status-icon" style="color:#f59e0b;"><i class="fi fi-rr-hourglass"></i></div>
                <h1 id="statusTitle" style="color:var(--text-primary);font-size:26px;margin-bottom:10px;">Payment processing...</h1>
                <p id="statusMessage" style="color:var(--text-muted);font-size:16px;margin-bottom:30px;">
                    Your payment is being verified. Please wait - this usually takes just a few seconds.
                </p>

                <div class="amount-section" style="margin-bottom:30px;">
                    <h3>Order #<?= htmlspecialchars($order['invoice_no']) ?></h3>
                    <div class="amount-display"><?= formatCurrency($order['total_amount']) ?></div>
                </div>

                <div id="statusActions" class="payment-actions" style="display:none;">
                    <a href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php" class="action-btn secondary-btn">
                        <i class="fi fi-rs-list"></i>
                        View All Orders
                    </a>
                    <a href="<?= WEB_ROOT ?>public/authenticated/customer/order_success.php?id=<?= (int)$order['id'] ?>" class="action-btn share-btn">
                        <i class="fi fi-rr-receipt"></i>
                        Order Details
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.payment-qr-page { padding: 60px 0; background: var(--page-bg-gradient); min-height: 100vh; }
.payment-qr-container { max-width: 600px; margin: 0 auto; padding: 0 20px; }
.payment-qr-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 40px; text-align: center; box-shadow: var(--neu-raised); }
.status-icon { font-size: 56px; margin-bottom: 20px; }
.amount-section h3 { color: var(--text-muted); font-size: 16px; margin-bottom: 10px; }
.amount-display { font-size: 32px; font-weight: 700; color: #a77693; text-shadow: 0 0 20px rgba(167, 118, 147, 0.3); }
.payment-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.action-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s ease; border: none; cursor: pointer; }
.action-btn.secondary-btn { background: var(--bg-card-hover); border: 1px solid var(--border-color); color: var(--text-primary); }
.action-btn.secondary-btn:hover { background: var(--bg-card); border-color: var(--border-color-hover); }
.action-btn.share-btn { background: linear-gradient(45deg, #a77693, #174871); color: #fff; }
.action-btn.share-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3); }
@media (max-width: 640px) { .payment-actions { grid-template-columns: 1fr; } }
</style>

<script>
(function () {
    var orderId = <?= (int)$order['id'] ?>;
    var maxAttempts = 20; // ~60s of polling at 3s intervals
    var attempt = 0;
    var iconEl = document.getElementById('statusIcon');
    var titleEl = document.getElementById('statusTitle');
    var msgEl = document.getElementById('statusMessage');
    var actionsEl = document.getElementById('statusActions');

    function showPaid() {
        iconEl.innerHTML = '<i class="fi fi-rr-check-circle"></i>';
        iconEl.style.color = '#10b981';
        titleEl.textContent = 'Payment successful!';
        msgEl.textContent = 'Thank you - your payment has been confirmed and your order is being prepared.';
        actionsEl.style.display = 'grid';
    }

    function showFailed(status) {
        iconEl.innerHTML = '<i class="fi fi-rr-cross-circle"></i>';
        iconEl.style.color = '#ef4444';
        titleEl.textContent = status === 'expired' ? 'Payment link expired' : 'Payment was not completed';
        msgEl.innerHTML = 'Your payment ' + (status === 'expired' ? 'link expired before it was completed' : 'did not go through') + '. You can try again from your order details.';
        actionsEl.style.display = 'grid';
    }

    function showStillWaiting() {
        titleEl.textContent = 'Still verifying...';
        msgEl.textContent = "This is taking longer than usual. You can keep waiting, or check your order details - we'll update it automatically once confirmed.";
        actionsEl.style.display = 'grid';
    }

    function poll() {
        attempt++;
        fetch('<?= WEB_ROOT ?>public/api/payment/status.php?order_id=' + orderId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) { return; }
            if (d.payment_status === 'paid') { showPaid(); return; }
            if (['failed', 'expired', 'cancelled'].indexOf(d.payment_status) !== -1) { showFailed(d.payment_status); return; }
            // Still pending.
            if (attempt >= maxAttempts) { showStillWaiting(); return; }
            setTimeout(poll, 3000);
        })
        .catch(function () {
            if (attempt >= maxAttempts) { showStillWaiting(); return; }
            setTimeout(poll, 3000);
        });
    }

    poll();
})();
</script>

<?php include __DIR__ . '/../shared/includes/footer.php'; ?>
