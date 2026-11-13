<?php
// customer/xendit_payment.php - "Pay with Xendit" step for orders placed
// with payment_method = 'xendit'. Creates the Xendit Payment Session on
// button click (not on page load) and redirects to Xendit's hosted checkout.
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$orderId = intval($_GET['id'] ?? 0);
$userId = getUserId();

if (!$orderId) {
    header('Location: orders.php');
    exit;
}

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        header('Location: orders.php?error=order_not_found');
        exit;
    }

    if (($order['payment_method'] ?? '') !== 'xendit') {
        header('Location: order_success.php?id=' . $orderId);
        exit;
    }
} catch (PDOException $e) {
    error_log('Error fetching order: ' . $e->getMessage());
    header('Location: orders.php?error=database_error');
    exit;
}

$isPaid = ($order['payment_status'] ?? '') === 'paid';
$isFailedOrExpired = in_array($order['payment_status'] ?? '', ['failed', 'expired', 'cancelled'], true);

$pageTitle = 'Complete Payment - Order #' . $order['invoice_no'];
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

<section class="payment-qr-page">
    <div class="container">
        <div class="payment-qr-container">
            <div class="payment-qr-card">
                <div class="payment-header">
                    <h1><i class="fi fi-rr-credit-card"></i> Pay with Xendit</h1>
                    <p class="order-info">Order #<?= htmlspecialchars($order['invoice_no']) ?></p>
                </div>

                <div class="payment-details">
                    <div class="amount-section">
                        <h3>Amount to Pay</h3>
                        <div class="amount-display"><?= formatCurrency($order['total_amount']) ?></div>
                    </div>
                </div>

                <?php if ($isPaid): ?>
                    <div class="payment-status paid">
                        <div class="status-icon"><i class="fi fi-rr-check-circle"></i></div>
                        <h3>Payment Received</h3>
                        <p>This order has already been paid via Xendit.</p>
                    </div>
                <?php else: ?>
                    <div class="payment-instructions">
                        <h4>How it works:</h4>
                        <ol>
                            <li>Click "Pay with Xendit" below</li>
                            <li>You'll be redirected to Xendit's secure checkout page</li>
                            <li>Choose your preferred method (card, e-wallet, and more) and complete payment</li>
                            <li>You'll be brought back here once payment is confirmed</li>
                        </ol>
                    </div>

                    <?php if ($isFailedOrExpired): ?>
                        <p style="color:#f59e0b;font-size:14px;margin-bottom:16px;">
                            Your previous payment attempt did not go through. You can try again below.
                        </p>
                    <?php endif; ?>

                    <div class="payment-actions" style="grid-template-columns:1fr;">
                        <button type="button" id="payWithXenditBtn" class="action-btn share-btn" style="width:100%;">
                            <i class="fi fi-rr-credit-card"></i>
                            <span id="payWithXenditLabel">Pay with Xendit</span>
                        </button>
                    </div>
                    <p id="xenditPayError" role="alert" style="display:none;color:#f87171;font-size:14px;margin-top:12px;text-align:center;"></p>
                <?php endif; ?>

                <div class="payment-actions">
                    <a href="orders.php" class="action-btn secondary-btn">
                        <i class="fi fi-rs-list"></i>
                        View All Orders
                    </a>
                    <a href="order_success.php?id=<?= (int)$order['id'] ?>" class="action-btn secondary-btn">
                        <i class="fi fi-rr-receipt"></i>
                        Order Details
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.payment-qr-page { padding: 40px 0; background: var(--page-bg-gradient); min-height: 100vh; }
.payment-qr-container { max-width: 600px; margin: 0 auto; padding: 0 20px; }
.payment-qr-card { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 40px; text-align: center; }
.payment-header h1 { color: var(--text-primary); font-size: 26px; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; gap: 12px; }
.payment-header .order-info { color: var(--text-muted); font-size: 16px; margin-bottom: 30px; }
.payment-details { margin-bottom: 20px; }
.amount-section h3 { color: var(--text-muted); font-size: 16px; margin-bottom: 10px; }
.amount-display { font-size: 36px; font-weight: 700; color: var(--accent-cyan); text-shadow: 0 0 20px var(--accent-cyan-bg-30); }
.payment-status { padding: 40px; border-radius: 12px; margin-bottom: 30px; }
.payment-status.paid { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); }
.status-icon { font-size: 48px; margin-bottom: 20px; color: #10b981; }
.payment-status h3 { color: var(--text-primary); font-size: 24px; margin-bottom: 10px; }
.payment-status p { color: var(--text-muted); font-size: 16px; margin-bottom: 0; }
.payment-instructions { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; margin-bottom: 20px; text-align: left; }
.payment-instructions h4 { color: var(--text-primary); font-size: 16px; margin-bottom: 15px; }
.payment-instructions ol { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0; padding-left: 20px; }
.payment-instructions li { margin-bottom: 8px; }
.payment-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px; }
.action-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s ease; border: none; cursor: pointer; }
.action-btn.secondary-btn { background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--text-primary); }
.action-btn.secondary-btn:hover { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.25); }
.action-btn.share-btn { background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue)); color: #fff; }
.action-btn.share-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3); }
.action-btn:disabled { opacity: .6; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
@media (max-width: 768px) {
    .payment-qr-container { margin: 0 20px; }
    .payment-qr-card { padding: 30px 20px; }
    .amount-display { font-size: 28px; }
    .payment-actions { grid-template-columns: 1fr; gap: 10px; }
}
</style>

<?php if (!$isPaid): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('payWithXenditBtn');
    var label = document.getElementById('payWithXenditLabel');
    var errorEl = document.getElementById('xenditPayError');
    if (!btn) return;

    btn.addEventListener('click', function () {
        // 1. Disable the button. 2. Show a loading state.
        btn.disabled = true;
        label.textContent = 'Redirecting to Xendit...';
        errorEl.style.display = 'none';

        // 3. Call POST /api/payment/create.
        fetch('<?= WEB_ROOT ?>public/api/payment/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ order_id: <?= (int)$order['id'] ?> })
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (result) {
            var d = result.body;
            if (result.ok && d && d.success && d.checkout_url) {
                // 4/5. Receive the checkout URL and redirect the customer to Xendit.
                label.textContent = 'Redirecting...';
                window.location.href = d.checkout_url;
            } else {
                // 6. Handle API errors gracefully.
                throw new Error((d && d.message) || 'Could not start payment. Please try again.');
            }
        })
        .catch(function (err) {
            errorEl.textContent = err.message || 'Network error. Please try again.';
            errorEl.style.display = 'block';
            btn.disabled = false;
            label.textContent = 'Pay with Xendit';
        });
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
