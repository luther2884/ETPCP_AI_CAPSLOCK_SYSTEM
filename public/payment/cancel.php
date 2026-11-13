<?php
// public/payment/cancel.php - Xendit cancel_return_url landing page.
// The customer backed out of Xendit's hosted checkout. The order is left
// exactly as-is (still pending) so they can retry - nothing is deleted or
// auto-cancelled here.
require_once __DIR__ . '/../../config/functions.php';

require_login();
require_role(['customer']);

$orderId = intval($_GET['order_id'] ?? 0);
$userId = getUserId();
$order = null;

if ($orderId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT id, invoice_no, total_amount FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log('payment/cancel.php error: ' . $e->getMessage());
    }
}

$pageTitle = 'Payment Cancelled';
include __DIR__ . '/../shared/includes/header.php';
?>

<section class="payment-qr-page">
    <div class="container">
        <div class="payment-qr-container">
            <div class="payment-qr-card">
                <div class="status-icon" style="color:#f59e0b;"><i class="fi fi-rr-cross-circle"></i></div>
                <h1 style="color:var(--text-primary);font-size:26px;margin-bottom:10px;">Payment cancelled</h1>
                <p style="color:var(--text-muted);font-size:16px;margin-bottom:30px;">
                    You cancelled the Xendit checkout before completing payment.
                    <?php if ($order): ?>Your order is still saved and hasn't been charged.<?php endif; ?>
                </p>

                <div class="payment-actions">
                    <?php if ($order): ?>
                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/xendit_payment.php?id=<?= (int)$order['id'] ?>" class="action-btn share-btn">
                            <i class="fi fi-rr-credit-card"></i>
                            Try Again
                        </a>
                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php" class="action-btn secondary-btn">
                            <i class="fi fi-rs-list"></i>
                            View All Orders
                        </a>
                    <?php else: ?>
                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/checkout.php" class="action-btn share-btn">
                            <i class="fi fi-rr-arrow-left"></i>
                            Return to Checkout
                        </a>
                    <?php endif; ?>
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
.payment-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.action-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s ease; border: none; cursor: pointer; }
.action-btn.secondary-btn { background: var(--bg-card-hover); border: 1px solid var(--border-color); color: var(--text-primary); }
.action-btn.secondary-btn:hover { background: var(--bg-card); border-color: var(--border-color-hover); }
.action-btn.share-btn { background: linear-gradient(45deg, #a77693, #174871); color: #fff; }
.action-btn.share-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3); }
@media (max-width: 640px) { .payment-actions { grid-template-columns: 1fr; } }
</style>

<?php include __DIR__ . '/../shared/includes/footer.php'; ?>
