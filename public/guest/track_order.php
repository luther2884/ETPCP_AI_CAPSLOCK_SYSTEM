<?php
// guest/track_order.php - Public Order Tracking Page
require_once __DIR__ . '/../../config/functions.php';

$pageTitle = 'Track Order - TechWorld';
$trackingCode = isset($_GET['code']) ? trim($_GET['code']) : '';
$order = null;
$orderItems = [];

if (!empty($trackingCode)) {
    try {
        $pdo = getConnection();
        
        // Get order details by tracking code
        $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE tracking_code = ?");
        $orderStmt->execute([$trackingCode]);
        $order = $orderStmt->fetch();

        // Delivery address actually chosen at checkout (orders.address
        // doesn't exist - it's snapshotted in delivery_full_address). Falls
        // back to the customer's current saved address only for orders
        // placed before that snapshot existed - best effort, may not match
        // what was actually chosen.
        if ($order && ($order['delivery_method'] ?? '') === 'delivery') {
            if (!empty($order['delivery_full_address'])) {
                $order['address'] = $order['delivery_full_address'];
            } else {
                $legacyAddrStmt = $pdo->prepare("
                    SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses
                    WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1
                ");
                $legacyAddrStmt->execute([$order['user_id']]);
                $order['address'] = $legacyAddrStmt->fetchColumn() ?: '';
            }
        }

        if ($order) {
            // Get order items
            $itemsStmt = $pdo->prepare("
                SELECT oi.*, p.name as product_name, p.image_url 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = ?
            ");
            $itemsStmt->execute([$order['id']]);
            $orderItems = $itemsStmt->fetchAll();
        }
        
    } catch (PDOException $e) {
        error_log('Error loading order: ' . $e->getMessage());
        $order = null;
    }
}

// Define tracking stages
$trackingStages = [
    'preparing' => [
        'title' => 'Preparing',
        'icon' => 'fi fi-rr-clipboard-list',
        'description' => 'Your order has been received and is being prepared'
    ],
    'processing' => [
        'title' => 'Processing',
        'icon' => 'fi fi-rr-settings',
        'description' => 'We are processing your order for shipment'
    ],
    'ready' => [
        'title' => 'Ready to Deliver',
        'icon' => 'fi fi-rr-package',
        'description' => 'Your order is ready and waiting for delivery'
    ],
    'shipped' => [
        'title' => 'Shipped',
        'icon' => 'fi fi-rr-plane',
        'description' => 'Your order is on its way to you'
    ],
    'completed' => [
        'title' => 'Completed',
        'icon' => 'fi fi-rr-check-circle',
        'description' => 'Your order has been successfully delivered'
    ],
    'cancelled' => [
        'title' => 'Cancelled',
        'icon' => 'fi fi-rr-cross',
        'description' => 'This order has been cancelled'
    ]
];

// Define tracking stages for In-Store Payment orders (simplified)
$onsiteTrackingStages = [
    'preparing' => [
        'title' => 'Preparing',
        'icon' => 'fi fi-rr-clipboard-list',
        'description' => 'Your order is being prepared for pickup'
    ],
    'completed' => [
        'title' => 'Complete',
        'icon' => 'fi fi-rr-check-circle',
        'description' => 'Your order is ready for pickup'
    ],
    'cancelled' => [
        'title' => 'Cancel',
        'icon' => 'fi fi-rr-cross',
        'description' => 'This order has been cancelled'
    ]
];

// Determine current stage
$currentStatus = $order ? $order['status'] : '';
$isOnsitePayment = $order && $order['payment_method'] === 'onsite';

// Set stage order based on payment method
if ($isOnsitePayment) {
    $stageOrder = ['preparing', 'completed']; // Only 2 stages for onsite payment
    $trackingStages = $onsiteTrackingStages; // Use simplified tracking stages
} else {
    $stageOrder = ['preparing', 'processing', 'ready', 'shipped', 'completed']; // Full stages for regular orders
}

$currentStageIndex = array_search($currentStatus, $stageOrder);

// Handle cancelled orders
if ($currentStatus === 'cancelled') {
    $currentStageIndex = -1; // Special case for cancelled
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<section class="track-order-page">
    <div class="container">
        <div class="track-header">
            <h1>Track Your Order</h1>
            <p>Enter your tracking code to monitor the progress of your order</p>
        </div>

        <!-- Tracking Code Input -->
        <div class="tracking-input-card">
            <form method="GET" class="tracking-form">
                <div class="input-group">
                    <input type="text" name="code" value="<?= htmlspecialchars($trackingCode) ?>" placeholder="Enter your tracking code" required>
                    <button type="submit">Track Order</button>
                </div>
            </form>
        </div>

        <?php if (!empty($trackingCode) && !$order): ?>
            <div class="error-message">
                <i class="fi fi-rr-exclamation"></i>
                <h3>Order Not Found</h3>
                <p>The tracking code "<?= htmlspecialchars($trackingCode) ?>" was not found. Please check your tracking code and try again.</p>
            </div>
        <?php endif; ?>

        <?php if ($order): ?>
            <!-- Order Information -->
            <div class="order-info-card">
                <div class="order-details">
                    <h2>Order #<?= htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'], 6, '0', STR_PAD_LEFT)) ?></h2>
                    <div class="order-meta">
                        <span><strong>Order Date:</strong> <?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></span>
                        <span><strong>Total Amount:</strong> <?= formatCurrency($order['total_amount']) ?></span>
                        <span><strong>Payment Method:</strong> <?= ucfirst($order['payment_method']) ?></span>
                        <span><strong>Tracking Code:</strong> <?= htmlspecialchars($order['tracking_code']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Order Tracking Timeline -->
            <div class="tracking-timeline">
                <h3>Order Progress</h3>
                <div class="timeline-container">
                    <?php foreach ($stageOrder as $index => $stage): ?>
                        <?php 
                        $stageData = $trackingStages[$stage];
                        $isCompleted = $index <= $currentStageIndex;
                        $isCurrent = $index === $currentStageIndex;
                        $isCancelled = $currentStatus === 'cancelled';
                        ?>
                        <div class="timeline-stage <?= $isCompleted ? 'completed' : '' ?> <?= $isCurrent ? 'current' : '' ?> <?= $isCancelled ? 'cancelled' : '' ?>">
                            <div class="stage-circle">
                                <i class="<?= $stageData['icon'] ?>"></i>
                            </div>
                            <div class="stage-content">
                                <h4><?= $stageData['title'] ?></h4>
                                <p><?= $stageData['description'] ?></p>
                            </div>
                            <?php if ($index < count($stageOrder) - 1): ?>
                                <?php $isConnectorCompleted = $index + 1 <= $currentStageIndex; ?>
                                <div class="timeline-connector <?= $isConnectorCompleted ? 'completed' : '' ?>"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Cancelled Stage (if applicable) -->
                    <?php if ($isCancelled): ?>
                        <div class="timeline-stage cancelled">
                            <div class="stage-circle">
                                <i class="<?= $trackingStages['cancelled']['icon'] ?>"></i>
                            </div>
                            <div class="stage-content">
                                <h4><?= $trackingStages['cancelled']['title'] ?></h4>
                                <p><?= $trackingStages['cancelled']['description'] ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Order Items -->
            <div class="order-items-section">
                <h3>Order Items</h3>
                <div class="order-items-list">
                    <?php foreach ($orderItems as $item): ?>
                        <div class="order-item">
                            <div class="item-image">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                                <?php else: ?>
                                    <div class="image-placeholder">
                                        <i class="fi fi-rr-package"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="item-details">
                                <h4><?= htmlspecialchars($item['product_name']) ?></h4>
                                <p>Quantity: <?= $item['quantity'] ?></p>
                                <p>Price: <?= formatCurrency($item['price']) ?></p>
                            </div>
                            <div class="item-total">
                                <?= formatCurrency($item['quantity'] * $item['price']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Delivery Information -->
            <div class="delivery-info">
                <h3>Delivery Information</h3>
                <div class="delivery-details">
                    <div class="delivery-method">
                        <strong>Delivery Method:</strong> 
                        <?= ($order['delivery_method'] ?? 'pickup') === 'pickup' ? 'Store Pickup' : 'Home Delivery' ?>
                    </div>
                    <?php if (!empty($order['address'])): ?>
                        <div class="delivery-address">
                            <strong>Delivery Address:</strong>
                            <p><?= htmlspecialchars($order['address']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['pickup_code'])): ?>
                        <div class="pickup-code">
                            <strong>Pickup Code:</strong> <?= htmlspecialchars($order['pickup_code']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.track-order-page {
    padding: 40px 0;
    background: var(--page-bg-gradient);
    min-height: 100vh;
}

.track-header {
    text-align: center;
    margin-bottom: 40px;
}

.track-header h1 {
    color: var(--text-primary);
    font-size: 32px;
    margin-bottom: 10px;
}

.track-header p {
    color: var(--text-muted);
    font-size: 16px;
}

.tracking-input-card {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    border: 1px solid var(--border-color);
    text-align: center;
}

.tracking-form {
    max-width: 500px;
    margin: 0 auto;
}

.input-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.input-group input {
    flex: 1;
    padding: 15px 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    background: var(--border-color);
    color: var(--text-primary);
    font-size: 16px;
}

.input-group input:focus {
    outline: none;
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
}

.input-group input::placeholder {
    color: var(--input-placeholder);
}

.input-group button {
    padding: 15px 30px;
    background: linear-gradient(45deg, #ef4444, #dc2626);
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.input-group button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
}

.error-message {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 15px;
    padding: 30px;
    text-align: center;
    margin-bottom: 30px;
}

.error-message i {
    font-size: 48px;
    color: #ef4444;
    margin-bottom: 15px;
    display: block;
}

.error-message h3 {
    color: #ef4444;
    margin-bottom: 10px;
    font-size: 24px;
}

.error-message p {
    color: var(--text-secondary);
    font-size: 16px;
}

.order-info-card {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    border: 1px solid var(--border-color);
}

.order-details h2 {
    color: var(--accent-cyan);
    margin-bottom: 15px;
    font-size: 24px;
}

.order-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    color: var(--text-secondary);
}

.order-meta span {
    display: block;
}

.tracking-timeline {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    border: 1px solid var(--border-color);
}

.tracking-timeline h3 {
    color: var(--text-primary);
    margin-bottom: 25px;
    font-size: 20px;
}

.timeline-container {
    position: relative;
    display: flex;
    align-items: flex-start;
    padding: 20px 0;
}

.timeline-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    flex: 1;
}

.stage-circle {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #374151;
    border: 3px solid #6b7280;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
    position: relative;
    z-index: 2;
    transition: all 0.3s ease;
}

.stage-circle i {
    font-size: 24px;
    color: #9ca3af;
    transition: color 0.3s ease;
}

.timeline-stage.completed .stage-circle {
    background: #ef4444;
    border-color: #ef4444;
}

.timeline-stage.completed .stage-circle i {
    color: #ffffff;
}

.timeline-stage.current .stage-circle {
    background: #ef4444;
    border-color: #ef4444;
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.5);
    animation: pulse 2s infinite;
}

.timeline-stage.current .stage-circle i {
    color: #ffffff;
}

.timeline-stage.cancelled .stage-circle {
    background: #6b7280;
    border-color: #6b7280;
}

.timeline-stage.cancelled .stage-circle i {
    color: #ffffff;
}

@keyframes pulse {
    0% { box-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }
    50% { box-shadow: 0 0 30px rgba(239, 68, 68, 0.8); }
    100% { box-shadow: 0 0 20px rgba(239, 68, 68, 0.5); }
}

.stage-content {
    text-align: center;
}

.stage-content h4 {
    color: var(--text-secondary);
    font-size: 14px;
    margin-bottom: 5px;
    font-weight: 600;
}

.stage-content p {
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.4;
}

.timeline-stage.completed .stage-content h4,
.timeline-stage.current .stage-content h4 {
    color: #ef4444;
}

.timeline-stage.completed .stage-content p,
.timeline-stage.current .stage-content p {
    color: var(--text-secondary);
}

.timeline-connector {
    position: absolute;
    top: 30px;
    left: 50%;
    width: 100%;
    height: 3px;
    background: #6b7280;
    z-index: 1;
}

.timeline-connector.completed {
    background: #ef4444;
}

/* Final "Complete" stage turns green once reached, instead of red */
.timeline-stage:last-child.completed .stage-circle,
.timeline-stage:last-child.current .stage-circle {
    background: #22c55e;
    border-color: #22c55e;
}

.timeline-stage:last-child.current .stage-circle {
    box-shadow: 0 0 20px rgba(34, 197, 94, 0.5);
    animation: pulse-green 2s infinite;
}

.timeline-stage:last-child.completed .stage-content h4,
.timeline-stage:last-child.current .stage-content h4 {
    color: #22c55e;
}

@keyframes pulse-green {
    0% { box-shadow: 0 0 20px rgba(34, 197, 94, 0.5); }
    50% { box-shadow: 0 0 30px rgba(34, 197, 94, 0.8); }
    100% { box-shadow: 0 0 20px rgba(34, 197, 94, 0.5); }
}

.order-items-section {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    border: 1px solid var(--border-color);
}

.order-items-section h3 {
    color: var(--text-primary);
    margin-bottom: 20px;
    font-size: 20px;
}

.order-items-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.order-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: var(--bg-card-hover);
    border-radius: 10px;
    border: 1px solid var(--border-color);
}

.item-image {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    overflow: hidden;
    background: var(--border-color);
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
    color: var(--text-muted);
    font-size: 24px;
}

.item-details {
    flex: 1;
}

.item-details h4 {
    color: var(--text-primary);
    margin-bottom: 5px;
    font-size: 16px;
}

.item-details p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 2px 0;
}

.item-total {
    color: var(--accent-cyan);
    font-weight: 700;
    font-size: 16px;
}

.delivery-info {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    border: 1px solid var(--border-color);
}

.delivery-info h3 {
    color: var(--text-primary);
    margin-bottom: 20px;
    font-size: 20px;
}

.delivery-details {
    color: var(--text-secondary);
}

.delivery-details > div {
    margin-bottom: 15px;
}

.delivery-address p {
    margin: 5px 0 0 0;
    color: var(--text-muted);
}

@media (max-width: 768px) {
    .timeline-container {
        flex-direction: column;
        gap: 20px;
    }
    
    .timeline-stage {
        flex-direction: row;
        max-width: none;
        width: 100%;
        text-align: left;
    }
    
    .stage-circle {
        margin-right: 15px;
        margin-bottom: 0;
        flex-shrink: 0;
    }
    
    .timeline-connector {
        display: none;
    }
    
    .order-meta {
        grid-template-columns: 1fr;
    }
    
    .input-group {
        flex-direction: column;
    }
    
    .input-group input,
    .input-group button {
        width: 100%;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
