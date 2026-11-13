<?php
// order_details.php - Show order details when QR is scanned
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/html; charset=UTF-8');

$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo '<h3>❌ Invalid Order ID</h3>';
    exit;
}

try {
    $pdo = getConnection();
    
    // Get order details with user information
    $stmt = $pdo->prepare("
        SELECT o.*, u.firstname, u.lastname, u.email, u.phone, 
               COALESCE(
                   o.address, 
                   (SELECT CONCAT_WS(', ', full_name, phone, street, region, postal) FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1),
                   (SELECT CONCAT_WS(', ', full_name, phone, street, region, postal) FROM user_addresses WHERE user_id = o.user_id LIMIT 1),
                   u.address
               ) as address
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo '<h3>❌ Order not found.</h3>';
        exit;
    }
    
    // Get order items
    $itemsStmt = $pdo->prepare("SELECT oi.*, p.name as product_name, p.image_url FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log('Order details error: ' . $e->getMessage());
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
    <title>Order Details - TechWorld</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: 
                linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.6)),
                url('../../shared/img/bg.jpg') center center/cover no-repeat fixed;
            min-height: 100vh;
            line-height: 1.6;
            color: var(--text-primary);
            padding: 10px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(20px);
            padding: 20px;
            border-radius: 15px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--accent-cyan-bg-30);
        }
        
        .order-id {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
            text-shadow: 0 0 10px var(--accent-cyan-bg-30);
        }
        
        .order-date {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .status-pending { 
            background: rgba(245, 158, 11, 0.2); 
            color: #f59e0b; 
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .status-processing { 
            background: rgba(23, 72, 113, 0.2); 
            color: #174871; 
            border: 1px solid rgba(23, 72, 113, 0.3);
        }
        .status-ready { 
            background: rgba(16, 185, 129, 0.2); 
            color: #10b981; 
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .status-completed { 
            background: rgba(34, 197, 94, 0.2); 
            color: #22c55e; 
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
        .status-cancelled { 
            background: rgba(239, 68, 68, 0.2); 
            color: #ef4444; 
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .info-section {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            border: 1px solid var(--border-color);
        }
        
        .info-section h3 {
            color: var(--accent-cyan);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--accent-cyan-bg-30);
            font-size: 18px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .info-item {
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-color);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #cbd5e1;
            font-size: 14px;
        }
        
        .info-value {
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .info-value.highlight {
            color: #10b981;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            text-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
        }
        
        
        .items-section {
            margin-top: 25px;
        }
        
        .items-section h3 {
            color: var(--accent-cyan);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--accent-cyan-bg-30);
            font-size: 16px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .item {
            display: flex;
            align-items: center;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 10px;
            margin-bottom: 10px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .item-image {
            width: 50px;
            height: 50px;
            background: var(--border-color);
            border-radius: 8px;
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
        }
        
        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
        }
        
        .item-details {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .item-meta {
            color: var(--text-muted);
            font-size: 12px;
        }
        
        .item-total {
            font-weight: bold;
            color: var(--accent-cyan);
            font-size: 16px;
            text-shadow: 0 0 10px var(--accent-cyan-bg-30);
        }
        
        .order-total {
            background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-top: 20px;
            box-shadow: 0 5px 15px var(--accent-cyan-bg-30);
        }
        
        .order-total-label {
            font-size: 14px;
            margin-bottom: 8px;
            opacity: 0.9;
        }
        
        .order-total-amount {
            font-size: 24px;
            font-weight: bold;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
        }
        
        
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .container {
                padding: 25px 20px;
                margin: 10px;
            }
            .order-id {
                font-size: 24px;
            }
            .item {
                flex-direction: column;
                text-align: center;
            }
            .item-image {
                margin-right: 0;
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="order-id">Order #<?= htmlspecialchars($order['invoice_no'] ?: $orderId) ?></div>
            <div class="order-date"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></div>
            <div class="status-badge status-<?= $order['status'] ?>"><?= ucfirst($order['status']) ?></div>
        </div>
        
        <div class="info-grid">
            <div class="info-section">
                <h3>Customer Information</h3>
                <div class="info-item">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?= htmlspecialchars(trim(($order['firstname'] ?? '') . ' ' . ($order['lastname'] ?? '')) ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?= htmlspecialchars($order['email'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?= htmlspecialchars($order['phone'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Address:</span>
                    <span class="info-value"><?= htmlspecialchars($order['address'] ?? 'N/A') ?></span>
                </div>
            </div>
            
            <div class="info-section">
                <h3>Order Information</h3>
                <div class="info-item">
                    <span class="info-label">Payment:</span>
                    <span class="info-value"><?= ucfirst($order['payment_method'] ?? 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Delivery:</span>
                    <span class="info-value"><?= ucfirst($order['delivery_method'] ?? 'N/A') ?></span>
                </div>
                <?php if (!empty($order['tracking_code']) && $order['delivery_method'] !== 'pickup'): ?>
                <div class="info-item">
                    <span class="info-label">Tracking Code:</span>
                    <span class="info-value highlight"><?= htmlspecialchars($order['tracking_code']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($orderItems)): ?>
        <div class="items-section">
            <h3>Order Items (<?= count($orderItems) ?> items)</h3>
            <?php foreach ($orderItems as $item): ?>
            <div class="item">
                <div class="item-image">
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                    <?php else: ?>
                        IMG
                    <?php endif; ?>
                </div>
                <div class="item-details">
                    <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                    <div class="item-meta">Quantity: <?= $item['quantity'] ?> × $<?= number_format($item['price'], 2) ?></div>
                </div>
                <div class="item-total">$<?= number_format($item['price'] * $item['quantity'], 2) ?></div>
            </div>
            <?php endforeach; ?>
            
            <div class="order-total">
                <div class="order-total-label">Total Amount</div>
                <div class="order-total-amount">$<?= number_format($order['total_amount'] ?? $order['total'] ?? 0, 2) ?></div>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</body>
</html>
