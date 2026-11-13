<?php
// collect.php - Verify order via QR code scan
require_once __DIR__ . '/../../config/database.php';
// Per-unit stock, for the cancellation rollback below. This file deliberately
// does NOT pull in config/functions.php - it is a guest page with no session
// and no auth - so the unit helpers are required directly. product_units.php
// depends only on database.php and product_validation.php.
require_once __DIR__ . '/../../config/product_units.php';

header('Content-Type: text/html; charset=UTF-8');

$orderId = isset($_GET['order']) ? intval($_GET['order']) : 0;
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

if ($orderId <= 0 || empty($code)) {
    http_response_code(400);
    echo '<h3>❌ Invalid QR Code</h3>';
    exit;
}

try {
    $pdo = getConnection();
    
    // Find order by ID and pickup code
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND pickup_code = ? LIMIT 1");
    $stmt->execute([$orderId, $code]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo '<h3>❌ Order not found or invalid code.</h3>';
        exit;
    }
    
    // Check if order is already completed
    if (in_array(strtolower($order['status'] ?? ''), ['completed', 'ready'])) {
        echo '<h3>✅ Order Already Completed</h3>';
        echo '<p>This order has already been processed.</p>';
        exit;
    }
    
    // Check expiry if pickup_code_expires_at is set
    $isExpired = false;
    if (!empty($order['pickup_code_expires_at']) && $order['pickup_code_expires_at'] !== '0000-00-00 00:00:00') {
        $now = new DateTimeImmutable('now');
        $expiresAt = new DateTimeImmutable($order['pickup_code_expires_at']);
        $isExpired = ($now > $expiresAt);
    }
    
    if ($isExpired) {
        // Mark as expired and roll the stock back - in ONE transaction, and
        // once only.
        //
        // This used to be three loose statements on autocommit. Two problems:
        // a crash between the cancel and the restock left the units lost, and
        // nothing stopped the restock running twice. The status guard above
        // only short-circuits 'completed'/'ready', so re-scanning an expired
        // QR of an already-cancelled order fell straight back in here and
        // added the quantities to stock a second time.
        //
        // The fix is the conditional UPDATE: `AND status <> 'cancelled'`
        // means exactly one request can claim the cancellation, and only the
        // one that claimed it (rowCount() === 1) restocks. A concurrent scan
        // blocks on the row lock, then finds 0 rows and restocks nothing.
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status <> 'cancelled'");
            $upd->execute([$order['id']]);

            if ($upd->rowCount() === 1) {
                // Rollback stock. Done as one set-based UPDATE joined to the
                // order's items so the rows are touched in index order, which
                // keeps the lock order consistent with checkout's.
                $inc = $pdo->prepare("
                    UPDATE products p
                    JOIN order_items oi ON p.id = oi.product_id
                    SET p.stock = p.stock + oi.quantity
                    WHERE oi.order_id = ?
                ");
                $inc->execute([(int)$order['id']]);

                // And the specific physical units this order had claimed.
                // Without this they stay 'reserved' against a cancelled order
                // for good: for a serialised product products.stock is a
                // mirror of the unit rows, so the += above is overwritten by
                // the next recount and the shelf is permanently short. Same
                // release the other three cancellation paths do
                // (Order_Cancel_Request.php and the two sweeps in
                // config/functions.php).
                pu_release_for_order($pdo, (int)$order['id']);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        echo '<h3>⏰ QR Code Expired!</h3>';
        echo '<p>This order was not completed within the allowed time and has been cancelled.</p>';
        exit;
    }
    
    // Mark order as completed
    $complete = $pdo->prepare("UPDATE orders SET status = 'completed', pickup_verified_at = NOW(), updated_at = NOW() WHERE id = ?");
    $complete->execute([$order['id']]);

    // The goods have just left with the customer, so the units they reserved
    // are sold. Same omission api/verify_pickup.php had: without this they stay
    // 'reserved' forever with no date_sold, and pu_accept_return() then refuses
    // them with "reserved for an order that has not been handed over yet" - on
    // a unit the customer collected at this very counter.
    pu_mark_order_sold($pdo, (int)$order['id']);

    // And the store warranty starts today - the goods just left with them.
    require_once __DIR__ . '/../../config/warranty.php';
    warranty_start_for_order($pdo, (int)$order['id']);

    // And the sale is logged. No session on this guest page, so the movement
    // carries no user - honest: the customer scanned their own QR.
    require_once __DIR__ . '/../../config/inventory_stock.php';
    inv_log_sold_for_order($pdo, (int)$order['id'], null);
    
    // Get order items for display
    $itemsStmt = $pdo->prepare("SELECT oi.*, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
    $itemsStmt->execute([$order['id']]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log('Order verification error: ' . $e->getMessage());
    http_response_code(500);
    echo '<h3>❌ Server error. Please try again.</h3>';
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Verified - TechWorld</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            margin: 0;
            padding: 20px;
            text-align: center;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .success-icon {
            font-size: 48px;
            color: #28a745;
            margin-bottom: 20px;
        }
        h1 {
            color: #28a745;
            margin-bottom: 20px;
        }
        .order-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: left;
        }
        .order-info {
            margin-bottom: 15px;
        }
        .order-info strong {
            color: #333;
        }
        .items-list {
            margin-top: 15px;
        }
        .item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .item:last-child {
            border-bottom: none;
        }
        .total {
            font-weight: bold;
            font-size: 18px;
            color: #007bff;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #007bff;
        }
        .back-btn {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
        .back-btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✅</div>
        <h1>Order Verified Successfully!</h1>
        
        <div class="order-details">
            <div class="order-info">
                <strong>Order ID:</strong> #<?= htmlspecialchars($order['invoice_no'] ?: $order['id']) ?>
            </div>
            <div class="order-info">
                <strong>Customer:</strong> <?= htmlspecialchars($order['full_name'] ?? 'N/A') ?>
            </div>
            <div class="order-info">
                <strong>Email:</strong> <?= htmlspecialchars($order['email'] ?? 'N/A') ?>
            </div>
            <div class="order-info">
                <strong>Phone:</strong> <?= htmlspecialchars($order['phone'] ?? 'N/A') ?>
            </div>
            
            <?php if (!empty($orderItems)): ?>
            <div class="items-list">
                <strong>Order Items:</strong>
                <?php foreach ($orderItems as $item): ?>
                <div class="item">
                    <span><?= htmlspecialchars($item['name']) ?> (Qty: <?= $item['quantity'] ?>)</span>
                    <span>$<?= number_format($item['price'] * $item['quantity'], 2) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="total">
                    Total: $<?= number_format($order['total_amount'] ?? $order['total'] ?? 0, 2) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <p><strong>Payment completed!</strong> The order has been marked as completed.</p>
        
        <a href="javascript:history.back()" class="back-btn">← Back to Scanner</a>
    </div>
</body>
</html>
