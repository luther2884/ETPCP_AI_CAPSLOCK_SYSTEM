<?php
// order_details_fixed.php - Fixed version for mobile QR scanning
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simple error handling
try {
    // Include database configuration
    require_once __DIR__ . '/../../config/database.php';
    
    $orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if ($orderId <= 0) {
        throw new Exception('Invalid Order ID');
    }
    
    // Get database connection
    $pdo = getConnection();
    
    // Get order details
    $stmt = $pdo->prepare("
        SELECT o.*, u.firstname, u.lastname, u.email, u.phone 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('Order not found');
    }
    
    // Get order items
    $itemsStmt = $pdo->prepare("
        SELECT oi.*, p.name as product_name, p.image_url 
        FROM order_items oi 
        JOIN products p ON p.id = oi.product_id 
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Show error in a user-friendly way
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - TechWorld</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                background: #f5f5f5; 
                padding: 20px; 
                text-align: center; 
            }
            .error-container { 
                background: white; 
                padding: 30px; 
                border-radius: 10px; 
                box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
                max-width: 500px; 
                margin: 0 auto; 
            }
            .error-icon { 
                font-size: 48px; 
                color: #e74c3c; 
                margin-bottom: 20px; 
            }
            .error-title { 
                color: #2c3e50; 
                margin-bottom: 10px; 
            }
            .error-message { 
                color: #7f8c8d; 
                margin-bottom: 20px; 
            }
            .retry-btn { 
                background: #3498db; 
                color: white; 
                padding: 10px 20px; 
                border: none; 
                border-radius: 5px; 
                cursor: pointer; 
                text-decoration: none; 
                display: inline-block; 
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h2 class="error-title">Order Not Found</h2>
            <p class="error-message">The order you're looking for doesn't exist or has been removed.</p>
            <a href="javascript:history.back()" class="retry-btn">Go Back</a>
        </div>
    </body>
    </html>
    <?php
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .order-id {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .order-date {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 15px;
        }
        
        .status-pending { background: rgba(255,193,7,0.3); color: #ff8f00; }
        .status-processing { background: rgba(33,150,243,0.3); color: #1976d2; }
        .status-ready { background: rgba(76,175,80,0.3); color: #388e3c; }
        .status-completed { background: rgba(76,175,80,0.3); color: #388e3c; }
        .status-cancelled { background: rgba(244,67,54,0.3); color: #d32f2f; }
        
        .content {
            padding: 30px;
        }
        
        .info-section {
            margin-bottom: 30px;
        }
        
        .info-section h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 18px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
        }
        
        .info-value {
            color: #333;
        }
        
        .item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .item-image {
            width: 50px;
            height: 50px;
            background: #e9ecef;
            border-radius: 8px;
            margin-right: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
        }
        
        .item-details {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .item-meta {
            color: #6c757d;
            font-size: 14px;
        }
        
        .item-total {
            font-weight: bold;
            color: #667eea;
            font-size: 16px;
        }
        
        .order-total {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-top: 20px;
        }
        
        .order-total-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        
        .order-total-amount {
            font-size: 24px;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 10px;
            }
            .header {
                padding: 20px;
            }
            .content {
                padding: 20px;
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
        
        <div class="content">
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
            </div>
            
            <?php if (!empty($orderItems)): ?>
            <div class="info-section">
                <h3>Order Items (<?= count($orderItems) ?> items)</h3>
                <?php foreach ($orderItems as $item): ?>
                <div class="item">
                    <div class="item-image">
                        <?php if (!empty($item['image_url'])): ?>
                            <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;">
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
    </div>
</body>
</html>
