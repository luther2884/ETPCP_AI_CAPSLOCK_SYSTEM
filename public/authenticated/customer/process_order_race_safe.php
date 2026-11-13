<?php
// customer/process_order_race_safe.php - Race condition safe order processing
//
// ===========================================================================
// NOT WIRED UP. DO NOT POINT CHECKOUT AT THIS FILE.
// ===========================================================================
// Nothing references this endpoint: checkout.php posts to process_order.php
// (see its <form action>), and a repo-wide search finds no other caller. It
// was written alongside DBSQL/race_condition_prevention.sql and
// RACE_CONDITION_PREVENTION_GUIDE.md but never routed, which is why the
// overselling bug was still live - the protection described in that guide was
// never on the code path a real checkout takes.
//
// It is also not currently runnable: processStockUpdate() below calls the
// stored procedure `UpdateProductStock`, which does not exist in the database
// (the .sql file's table/column half was applied, the procedure half was not).
// Any order sent here would fail on that CALL.
//
// The overselling fix lives in process_order.php instead: SELECT ... ORDER BY
// id ASC FOR UPDATE, then `UPDATE products SET stock = stock - ? WHERE id = ?
// AND stock >= ?` with a rowCount() check, all inside the one transaction that
// writes the order - plus CHECK (stock >= 0) on products.stock as a database
// backstop (DBSQL/90_stock_never_negative.sql).
//
// Kept on disk rather than deleted so nothing that may reference it by path
// breaks, and so the duplicate-order and processing-lock ideas in here are not
// lost. Treat it as a design sketch, not as live code.
// ===========================================================================
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
if (!check_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

/**
 * Race Condition Safe Order Processing
 * 
 * This implementation prevents:
 * 1. Overselling products when multiple users buy simultaneously
 * 2. Duplicate orders from users clicking "Buy" multiple times
 * 3. Inventory inconsistencies during concurrent purchases
 * 
 * Key Features:
 * - Database row locking with FOR UPDATE
 * - Atomic stock updates using stored procedures
 * - Order hash to prevent duplicates
 * - Processing locks to prevent concurrent orders from same user
 * - Optimistic locking with version control
 */

class RaceSafeOrderProcessor {
    private $pdo;
    private $userId;
    private $lockTimeout = 30; // 30 seconds
    private $pendingOrderTimeout = 300; // 5 minutes
    
    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    /**
     * Generate a unique order hash to prevent duplicate orders
     */
    private function generateOrderHash($cartData, $timestamp) {
        $cartString = json_encode($cartData, JSON_SORT_KEYS);
        return hash('sha256', $this->userId . ':' . $cartString . ':' . $timestamp);
    }
    
    /**
     * Acquire a processing lock to prevent concurrent orders from same user
     */
    private function acquireProcessingLock() {
        $lockKey = 'order_process_' . $this->userId . '_' . time();
        $expiresAt = date('Y-m-d H:i:s', time() + $this->lockTimeout);
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO order_locks (user_id, lock_key, expires_at) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$this->userId, $lockKey, $expiresAt]);
            return $lockKey;
        } catch (PDOException $e) {
            // Lock already exists or other error
            throw new RuntimeException('Another order is being processed. Please wait and try again.');
        }
    }
    
    /**
     * Release the processing lock
     */
    private function releaseProcessingLock($lockKey) {
        $stmt = $this->pdo->prepare("DELETE FROM order_locks WHERE lock_key = ?");
        $stmt->execute([$lockKey]);
    }
    
    /**
     * Check if order already exists (duplicate prevention)
     */
    private function checkDuplicateOrder($orderHash) {
        $stmt = $this->pdo->prepare("SELECT id FROM orders WHERE order_hash = ?");
        $stmt->execute([$orderHash]);
        return $stmt->fetchColumn() !== false;
    }
    
    /**
     * Reserve stock for pending order (prevents overselling)
     */
    private function reserveStock($productId, $quantity) {
        $expiresAt = date('Y-m-d H:i:s', time() + $this->pendingOrderTimeout);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO pending_orders (user_id, product_id, quantity, expires_at) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->userId, $productId, $quantity, $expiresAt]);
    }
    
    /**
     * Release reserved stock
     */
    private function releaseReservedStock($productId, $quantity) {
        $stmt = $this->pdo->prepare("
            DELETE FROM pending_orders 
            WHERE user_id = ? AND product_id = ? AND quantity = ?
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        $stmt->execute([$this->userId, $productId, $quantity]);
    }
    
    /**
     * Atomically update product stock using stored procedure
     */
    private function updateProductStock($productId, $quantity, $operation = 'decrease') {
        $stmt = $this->pdo->prepare("CALL UpdateProductStock(?, ?, ?, @success, @new_stock, @message)");
        $stmt->execute([$productId, $quantity, $operation]);
        
        $result = $this->pdo->query("SELECT @success as success, @new_stock as new_stock, @message as message")->fetch();
        
        if (!$result['success']) {
            throw new RuntimeException($result['message']);
        }
        
        return $result['new_stock'];
    }
    
    /**
     * Validate stock availability with row locking
     */
    private function validateStockAvailability($cartItems) {
        $productIds = array_column($cartItems, 'product_id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        
        if (empty($placeholders)) {
            throw new RuntimeException('No products to validate');
        }
        
        // Lock products for update to prevent race conditions
        $stmt = $this->pdo->prepare("
            SELECT id, name, price, stock, version 
            FROM products 
            WHERE id IN ($placeholders) AND status = 'active'
            FOR UPDATE
        ");
        $stmt->execute($productIds);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $productMap = [];
        foreach ($products as $product) {
            $productMap[$product['id']] = $product;
        }
        
        // Validate each cart item
        foreach ($cartItems as $item) {
            if (!isset($productMap[$item['product_id']])) {
                throw new RuntimeException("Product {$item['name']} not found or inactive");
            }
            
            $product = $productMap[$item['product_id']];
            if ($product['stock'] < $item['quantity']) {
                throw new RuntimeException(
                    "Insufficient stock for {$product['name']}. " .
                    "Available: {$product['stock']}, Requested: {$item['quantity']}"
                );
            }
        }
        
        return $productMap;
    }
    
    /**
     * Process the order with full race condition protection
     */
    public function processOrder($orderData) {
        $lockKey = null;
        
        try {
            // Step 1: Acquire processing lock
            $lockKey = $this->acquireProcessingLock();
            
            // Step 2: Generate order hash for duplicate prevention
            $orderHash = $this->generateOrderHash($orderData['cart_items'], time());
            
            // Step 3: Check for duplicate orders
            if ($this->checkDuplicateOrder($orderHash)) {
                throw new RuntimeException('Duplicate order detected. Please check your order history.');
            }
            
            // Step 4: Start transaction
            $this->pdo->beginTransaction();
            
            // Step 5: Validate stock availability with row locking
            $productMap = $this->validateStockAvailability($orderData['cart_items']);
            
            // Step 6: Reserve stock for pending order
            foreach ($orderData['cart_items'] as $item) {
                $this->reserveStock($item['product_id'], $item['quantity']);
            }
            
            // Step 7: Create order with hash
            $orderId = $this->createOrder($orderData, $orderHash);
            
            // Step 7.5: Create payment record with correct mapping
            $paymentType = ($orderData['delivery_method'] === 'delivery') ? 'home_delivery' : 'store_pickup';
            // Home Delivery = In-Store Payment, Store Pickup = Cash on Delivery
            $paymentMethodCode = ($orderData['delivery_method'] === 'delivery') ? 'home_delivery' : 'store_pickup';
            $paymentStatus = 'pending'; // Both are cash payments, so pending until collected
            
            $paymentId = createPayment(
                $orderId, 
                $this->userId, 
                $paymentMethodCode, 
                $paymentType, 
                $orderData['total'], 
                $paymentStatus
            );
            
            if (!$paymentId) {
                error_log("Failed to create payment record for order ID: $orderId");
            }
            
            // Step 8: Create order items
            $this->createOrderItems($orderId, $orderData['cart_items']);
            
            // Step 9: Atomically update stock for each product
            foreach ($orderData['cart_items'] as $item) {
                $newStock = $this->updateProductStock($item['product_id'], $item['quantity'], 'decrease');
                
                // Log stock movement
                $this->logStockMovement($item['product_id'], 'out', $item['quantity'], 'order', $orderId);
                
                // Release reserved stock
                $this->releaseReservedStock($item['product_id'], $item['quantity']);
            }
            
            // Step 10: Clear cart
            $this->clearCart($orderData['cart_items']);
            
            // Step 11: Commit transaction
            $this->pdo->commit();
            
            return [
                'success' => true,
                'order_id' => $orderId,
                'message' => 'Order processed successfully with race condition protection'
            ];
            
        } catch (Exception $e) {
            // Rollback transaction if active
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            
            // Release any reserved stock
            if (isset($orderData['cart_items'])) {
                foreach ($orderData['cart_items'] as $item) {
                    $this->releaseReservedStock($item['product_id'], $item['quantity']);
                }
            }
            
            throw $e;
        } finally {
            // Always release processing lock
            if ($lockKey) {
                $this->releaseProcessingLock($lockKey);
            }
        }
    }
    
    /**
     * Create order record
     */
    private function createOrder($orderData, $orderHash) {
        // Determine schema and create order accordingly
        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $colsStmt = $this->pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'orders'");
        $colsStmt->execute([$dbName]);
        $orderCols = array_map('strtolower', array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));
        
        $hasExtended = in_array('invoice_no', $orderCols, true) && in_array('tracking_code', $orderCols, true);
        
        if ($hasExtended) {
            $invoiceNo = $this->generateInvoiceNumber();
            $trackingCode = $this->generateTrackingCode();
            
            // Only generate pickup code for pickup orders; use empty string for delivery to satisfy NOT NULL schemas
            $pickupCode = ($orderData['delivery_method'] === 'pickup') ? $this->generatePickupCode() : '';
            $pickupExpiresAt = ($orderData['delivery_method'] === 'pickup') ? date('Y-m-d H:i:s', strtotime('+60 minutes')) : null;
            
            $dbPaymentMethod = ($orderData['payment_method'] === 'store') ? 'onsite' : $orderData['payment_method'];
            
            // Set delivery method and payment method display values
            $dbDeliveryMethod = $orderData['delivery_method']; // 'delivery' or 'pickup'
            $paymentMethodDisplay = ($orderData['delivery_method'] === 'delivery') ? 'Home Delivery Payment' : 'Store Pickup Payment';
            
            // Check if new columns exist
            $hasDeliveryMethod = in_array('delivery_method', $orderCols, true);
            $hasPaymentMethodDisplay = in_array('payment_method_display', $orderCols, true);
            
            $hasTotalAmount = in_array('total_amount', $orderCols, true);
            if ($hasTotalAmount) {
                if ($hasDeliveryMethod && $hasPaymentMethodDisplay) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, 
                                          order_hash, total_amount, payment_method, delivery_method, payment_method_display, status, notes, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'preparing', ?, NOW())
                    ");
                    $stmt->execute([
                        $this->userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt,
                        $orderHash, $orderData['total'], $dbPaymentMethod, $dbDeliveryMethod, $paymentMethodDisplay, $orderData['order_notes']
                    ]);
                } else {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, 
                                          order_hash, total_amount, payment_method, status, notes, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'preparing', ?, NOW())
                    ");
                    $stmt->execute([
                        $this->userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt,
                        $orderHash, $orderData['total'], $dbPaymentMethod, $orderData['order_notes']
                    ]);
                }
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, 
                                      order_hash, full_name, email, phone, address, payment_method, delivery_method, 
                                      order_notes, subtotal, shipping, tax, total, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'preparing', NOW())
                ");
                $stmt->execute([
                    $this->userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $orderHash,
                    $orderData['full_name'], $orderData['email'], $orderData['phone'], $orderData['address'],
                    $dbPaymentMethod, $orderData['delivery_method'], $orderData['order_notes'],
                    $orderData['subtotal'], $orderData['shipping'], $orderData['tax'], $orderData['total']
                ]);
            }
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO orders (user_id, order_hash, full_name, email, phone, address, payment_method, 
                                  delivery_method, order_notes, subtotal, shipping, tax, total, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'preparing', NOW())
            ");
            $stmt->execute([
                $this->userId, $orderHash, $orderData['full_name'], $orderData['email'], 
                $orderData['phone'], $orderData['address'], $orderData['payment_method'], 
                $orderData['delivery_method'], $orderData['order_notes'], $orderData['subtotal'], 
                $orderData['shipping'], $orderData['tax'], $orderData['total']
            ]);
        }
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Create order items
     */
    private function createOrderItems($orderId, $cartItems) {
        $stmt = $this->pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, price, total) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($cartItems as $item) {
            $total = $item['price'] * $item['quantity'];
            $stmt->execute([
                $orderId, $item['product_id'], $item['quantity'], 
                $item['price'], $total
            ]);
        }
    }
    
    /**
     * Log stock movement
     */
    private function logStockMovement($productId, $movementType, $quantity, $referenceType, $referenceId) {
        $stmt = $this->pdo->prepare("
            INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $productId, $movementType, $quantity, $referenceType, $referenceId,
            "Order processing - race safe"
        ]);
    }
    
    /**
     * Clear cart items
     */
    private function clearCart($cartItems) {
        foreach ($cartItems as $item) {
            unset($_SESSION['cart'][$this->userId][$item['product_id']]);
        }
        if (empty($_SESSION['cart'][$this->userId])) {
            unset($_SESSION['cart'][$this->userId]);
        }
        
        // Also clear purchased items from database cart
        if (function_exists('clearCartInDb')) {
            $productIds = array_column($cartItems, 'product_id');
            if (!empty($productIds)) {
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $clearDbStmt = $this->pdo->prepare("DELETE FROM user_carts WHERE user_id = ? AND product_id IN ($placeholders)");
                $clearDbStmt->execute(array_merge([$this->userId], $productIds));
            }
        }
    }
    
    // Helper methods for generating codes
    private function generateInvoiceNumber() {
        return 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    private function generateTrackingCode() {
        return 'TRK-' . strtoupper(substr(md5(uniqid()), 0, 8));
    }
    
    private function generatePickupCode() {
        return str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }
}

// Main processing logic
try {
    $pdo = getConnection();
    $userId = getUserId();
    
    // Get form data
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $deliveryMethod = $_POST['delivery_method'] ?? 'delivery';
    $orderNotes = trim($_POST['order_notes'] ?? '');
    
    // Validate required fields
    if (empty($fullName) || empty($email) || empty($phone) || empty($address) || empty($paymentMethod)) {
        throw new RuntimeException('All required fields must be filled');
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email format');
    }
    
    // Get cart items from session; if empty, fall back to DB-backed cart
    if (!isset($_SESSION['cart'][$userId]) || empty($_SESSION['cart'][$userId])) {
        if (function_exists('loadCartFromDb')) {
            $dbCart = loadCartFromDb($userId);
            if (!empty($dbCart)) {
                $_SESSION['cart'][$userId] = $dbCart;
            }
        }
    }
    if (!isset($_SESSION['cart'][$userId]) || empty($_SESSION['cart'][$userId])) {
        // Still empty after DB fallback; allow selected_ids to drive checkout if provided later
        $_SESSION['cart'][$userId] = [];
    }
    
    $cartSession = $_SESSION['cart'][$userId];
    $selectedRaw = trim($_POST['selected_ids'] ?? '');
    $selectedIds = [];
    if ($selectedRaw !== '') {
        $selectedIds = array_filter(array_map('intval', explode(',', $selectedRaw)));
        $selectedIds = array_values(array_unique($selectedIds));
    }
    
    if (!empty($selectedIds)) {
        $intersect = array_values(array_intersect(array_keys($cartSession), $selectedIds));
        $productIds = !empty($intersect) ? $intersect : $selectedIds;
        foreach ($productIds as $pid) {
            if (!isset($_SESSION['cart'][$userId][$pid])) {
                $_SESSION['cart'][$userId][$pid] = 1;
            }
        }
        $cartSession = $_SESSION['cart'][$userId];
    } else {
        $productIds = array_keys($cartSession);
    }
    
    // Fetch products from database
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    if (empty($placeholders)) {
        throw new RuntimeException('No products selected for checkout');
    }
    $stmt = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE id IN ($placeholders) AND status = 'active'");
    $stmt->execute($productIds);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build cart items array
    $cartItems = [];
    foreach ($products as $product) {
        $quantity = $cartSession[$product['id']] ?? 1;
        $cartItems[] = [
            'product_id' => $product['id'],
            'name' => $product['name'],
            'price' => $product['price'],
            'stock' => $product['stock'],
            'quantity' => $quantity
        ];
    }
    
    if (empty($cartItems)) {
        throw new RuntimeException('Your cart is empty');
    }
    
    // Calculate totals
    $subtotal = array_sum(array_map(function($item) {
        return $item['price'] * $item['quantity'];
    }, $cartItems));
    
    $shipping = 0; // Free shipping
    $tax = 0; // Tax included in price
    $total = $subtotal + $shipping + $tax;
    
    // Prepare order data
    $orderData = [
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'payment_method' => $paymentMethod,
        'delivery_method' => $deliveryMethod,
        'order_notes' => $orderNotes,
        'subtotal' => $subtotal,
        'shipping' => $shipping,
        'tax' => $tax,
        'total' => $total,
        'cart_items' => $cartItems
    ];
    
    // Process order with race condition protection
    $processor = new RaceSafeOrderProcessor($pdo, $userId);
    $result = $processor->processOrder($orderData);
    
    // Log audit
    logAudit('order_created_race_safe', 'orders', $result['order_id'], null, [
        'order_id' => $result['order_id'],
        'total' => $total,
        'payment_method' => $paymentMethod,
        'delivery_method' => $deliveryMethod,
        'race_protection' => true
    ]);
    
    // Send order confirmation notification
    sendOrderStatusNotification($userId, $result['order_id'], 'placed', [
        'total' => formatCurrency($total),
        'payment_method' => $paymentMethod === 'cod' ? 'Cash on Delivery' : 'Pay at Store',
        'delivery_method' => $deliveryMethod === 'delivery' ? 'Home Delivery' : 'Store Pickup'
    ]);
    
    // Try sending confirmation email (best-effort)
    try {
        $user = getUserData();
        if ($user && !empty($user['email'])) {
            $subject = 'TechWorld Order Confirmation #' . $result['order_id'];
            $body = '<p>Hi ' . htmlspecialchars($fullName) . ',</p>' .
                    '<p>Thanks for your order. Your order number is <strong>' . $result['order_id'] . '</strong>.</p>' .
                    '<p>Total: <strong>' . formatCurrency($total) . '</strong></p>' .
                    '<p>This order was processed with race condition protection to ensure inventory accuracy.</p>' .
                    '<p>You can view details at: <a href="' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $result['order_id'] . '">Order Details</a></p>';
            sendEmail($user['email'], $fullName, $subject, $body);
        }
    } catch (Throwable $e) { /* ignore email failures */ }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Order placed successfully with race condition protection!',
        'order_id' => $result['order_id'],
        'redirect' => WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $result['order_id']
    ]);
    
} catch (Exception $e) {
    error_log('Race safe order processing error: ' . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
