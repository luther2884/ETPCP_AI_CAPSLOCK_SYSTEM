<?php
// order_qr.php - Display QR code for order pickup/verification
session_start();
require_once __DIR__ . '/../../config/database.php';

$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo "<h3>Invalid order ID.</h3>";
    exit;
}

try {
    $pdo = getConnection();
    
    // Debug: Check if order exists
    $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE id = ?");
    $checkStmt->execute([$orderId]);
    $orderExists = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
    
    if (!$orderExists) {
        echo "<h3>Order not found.</h3>";
        echo "<p>Order ID: " . htmlspecialchars($orderId) . " does not exist in the database.</p>";
        exit;
    }
    
    // Get order details with user information
    $stmt = $pdo->prepare("
        SELECT o.id, o.pickup_code, o.payment_method, o.status, o.invoice_no, o.tracking_code, 
               o.total_amount, o.created_at, o.delivery_method,
               u.firstname, u.lastname, u.email, u.phone, u.address
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo "<h3>Order not found.</h3>";
        echo "<p>Order ID: " . htmlspecialchars($orderId) . " exists but could not be retrieved.</p>";
        exit;
    }
    
    // Get order items (this might fail if order_items table doesn't exist or has different structure)
    try {
        $itemsStmt = $pdo->prepare("SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
        $itemsStmt->execute([$orderId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $itemError) {
        error_log('Order items query error: ' . $itemError->getMessage());
        $orderItems = []; // Set empty array if items query fails
    }
    
    // Build target URL for QR code - use LAN IP for mobile access
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    
    // Use LAN IP for mobile scanning, fallback to current host
    $lanIp = '10.32.134.104';
    $currentHost = $_SERVER['HTTP_HOST'];
    
    // For mobile scanning, we need to use a URL that the phone can access
    // Try to detect the best approach for mobile access
    if (strpos($currentHost, 'localhost') !== false || strpos($currentHost, '127.0.0.1') !== false) {
        // Use the computer's network IP for mobile access
        $host = $lanIp;
    } else {
        $host = $currentHost;
    }
    
    // Build the target URL for the QR code
    $baseUrl = $protocol . '://' . $host . dirname($_SERVER['REQUEST_URI']);
    $targetUrl = rtrim($baseUrl, '/') . '/order_details_fixed.php?order_id=' . $orderId;
    
    // Create URL for mobile scanning that shows order details
    $mobileUrl = $protocol . '://' . $host . dirname($_SERVER['REQUEST_URI']) . '/order_details_fixed.php?order_id=' . $orderId;
    
    // QR code will be generated with JavaScript
    $size = 350;
    
} catch (Exception $e) {
    error_log('QR generation error: ' . $e->getMessage());
    echo "<h3>Error generating QR code.</h3>";
    echo "<p>Debug info: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Order ID: " . htmlspecialchars($orderId) . "</p>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order QR Code - TechWorld</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }
        
        .container {
            max-width: 400px;
            width: 100%;
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(20px);
            padding: 20px;
            border-radius: 15px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        
        h1 {
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .order-info {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
            border: 1px solid var(--border-color);
        }
        
        .order-id {
            font-size: 16px;
            font-weight: bold;
            color: var(--accent-cyan);
            margin-bottom: 10px;
            text-shadow: 0 0 10px var(--accent-cyan-bg-30);
        }
        
        .pickup-code {
            font-size: 20px;
            font-weight: bold;
            color: #10b981;
            margin: 10px 0;
            text-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
            font-family: 'Courier New', monospace;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 8px;
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
        
        
        .instructions {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 15px;
            line-height: 1.6;
        }
        
        .instructions p {
            margin-bottom: 8px;
        }
        
        .qr-url {
            background: rgba(0, 0, 0, 0.3);
            padding: 8px;
            border-radius: 6px;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            font-size: 9px;
            word-break: break-all;
            color: #cbd5e1;
        }
        
        
        .loading-text {
            color: var(--accent-cyan);
            font-weight: 600;
        }
        
        .back-to-account-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(45deg, var(--accent-blue), var(--accent-cyan));
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(23, 72, 113, 0.3);
            font-size: 14px;
        }
        
        .back-to-account-btn:hover {
            background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(23, 72, 113, 0.4);
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
                margin: 10px;
            }
            
            h1 {
                font-size: 20px;
            }
            
            .pickup-code {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Order QR Code</h1>
        
        <div class="order-info">
            <div class="order-id">Order #<?= htmlspecialchars($order['invoice_no'] ?: $orderId) ?></div>
            <div class="pickup-code"><?= htmlspecialchars($order['pickup_code'] ?: 'N/A') ?></div>
            <div><span class="status-badge status-<?= $order['status'] ?>"><?= ucfirst($order['status']) ?></span></div>
        </div>
        
        
        <div class="instructions">
            <p><strong>For Store Staff:</strong> Scan this QR code to view complete order details.</p>
            <p><strong>For Customers:</strong> Show this QR code to store staff to display your order information.</p>
            <div class="qr-url">
                <strong>QR URL:</strong> <?= htmlspecialchars($mobileUrl) ?>
            </div>
        </div>
        
        <?php if (isset($_SESSION['user_id'])): ?>
        <div style="text-align: center; margin-top: 20px;">
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/dashboard.php" class="back-to-account-btn">
                <span>←</span> Back to My Account
            </a>
        </div>
        <?php endif; ?>
        
    </div>

</body>
</html>
