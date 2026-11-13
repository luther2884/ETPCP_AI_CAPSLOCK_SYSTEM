<?php
// api/orders.php - Orders API endpoint
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

// Check if user is logged in and has admin role
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $pdo = getConnection();

    // Opportunistic sweep for online-payment orders left unpaid over an
    // hour - see cancelExpiredOnlinePaymentOrders() in config/functions.php.
    // This endpoint is exactly where the badge-polling JS (admin/includes/
    // header.php) lands every 30s, so it's a natural place to keep the
    // counts below honest without a real cron job.
    cancelExpiredOnlinePaymentOrders();

    switch ($action) {
        case 'count':
            // Get total order count
            $stmt = $pdo->query("SELECT COUNT(*) FROM orders");
            $count = (int)$stmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;
            
        case 'pending_count':
            // Newly-placed orders not yet started - 'pending' was never a
            // real value of orders.status (see the enum: preparing/packed/
            // ready/shipped/completed/cancelled), so this always silently
            // returned 0 and the Order Management badge never actually lit
            // up. 'preparing' is the real just-placed status every new
            // order starts in.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'preparing'");
            $stmt->execute();
            $count = (int)$stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;

        case 'home_delivery_pending_count':
            // Same filter Home_Delivery_Orders.php's own listing uses - a
            // home delivery order still awaiting action, whether that's
            // fulfillment or (for online-payment orders) payment
            // verification. Kept in exact sync with that page so the badge
            // count always matches what actually shows up when the admin
            // opens it - unpaid online-payment orders count too since
            // that page now shows them (with a "Mark Verified" prompt)
            // instead of hiding them.
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT invoice_no) FROM orders
                WHERE delivery_method = 'delivery' AND status IN ('preparing','packed','ready')
            ");
            $stmt->execute();
            $count = (int)$stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;

        case 'store_pickup_pending_count':
            // Same idea as home_delivery_pending_count above, for Store
            // Pickup Orders - a pickup order still awaiting action
            // (fulfillment progress or online-payment verification).
            // store_pickup_orders.php's own listing has no status/payment
            // filter at all (shows everything), so this stays in sync with
            // "still in the active pipeline" rather than mirroring an exact
            // page filter the way the home-delivery one does.
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT invoice_no) FROM orders
                WHERE delivery_method = 'pickup' AND status IN ('preparing','packed','ready')
            ");
            $stmt->execute();
            $count = (int)$stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;

        case 'recent_orders':
            // Get recent orders (last 10)
            $stmt = $pdo->prepare("
                SELECT o.*, u.firstname, u.lastname, u.email 
                FROM orders o 
                LEFT JOIN users u ON o.user_id = u.id 
                ORDER BY o.created_at DESC 
                LIMIT 10
            ");
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'orders' => $orders
            ]);
            break;
            
        case 'update_status':
            // Update order status and tracking number
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            if (!check_csrf()) {
                throw new Exception('Invalid CSRF token');
            }
            
            /* STAFF ONLY.
               The comment at the top of this file says it checks for an admin
               role. It did not - only isLoggedIn() - and there is no ownership
               check on $orderId either, so any signed-in CUSTOMER could post
               their own CSRF token here and set ANY order to ANY status. That
               is checked properly now, on the one action in this file that
               writes. */
            if (!in_array(getUserRole(), ['admin', 'super-admin', 'owner', 'manager'], true)) {
                http_response_code(403);
                throw new Exception('Staff sign-in required to change an order status.');
            }

            $orderId = (int)($_POST['order_id'] ?? 0);
            $newStatus = trim($_POST['status'] ?? '');
            $trackingNumber = trim($_POST['tracking_number'] ?? '');
            
            if ($orderId <= 0 || !in_array($newStatus, ['preparing', 'packed', 'ready', 'shipped', 'completed', 'cancelled'])) {
                throw new Exception('Invalid order ID or status');
            }

            /* ROUTED THROUGH config/order_status.php, NOT WRITTEN HERE.
               
               This used to be a bare `UPDATE orders SET status = ?`, and it was
               the last survivor of the nine places that each used to write this
               column with its own idea of the rules (see the header of
               config/order_status.php). Going straight to the column meant it
               bypassed every one of them:
               
                 - no ladder, so it could skip steps or move backwards
                 - no order_status_history row, so the change was invisible to
                   the audit trail AND to the scan cooldown, which reads that
                   table
                 - no serial gate, so a serialised order could be marked Ready
                   with no unit recorded against it
                 - no unit bookkeeping, so 'shipped'/'completed' never marked
                   the units sold and 'cancelled' never put them back on the
                   shelf - leaving stock permanently short
               
               os_manual_set() is the right home for it: it is the deliberate
               override path, it records history with source 'manual', it keeps
               the unit records in step, and it warns (rather than refuses) when
               a serial is missing. Nothing in the UI calls this endpoint any
               more - the queue dropdowns that did were removed - so this is
               about the hole being reachable, not about a live caller. */
            require_once __DIR__ . '/../../../config/order_status.php';

            $actorName = trim((string)(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''))) ?: null;
            // os_manual_set() requires a reason of its own accord. A caller
            // that supplies none gets one naming this endpoint, so the history
            // row says where the change came from instead of being rejected.
            $apiReason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($apiReason) < 5) {
                $apiReason = 'Changed via the orders API (no reason given).';
            }

            $osRes = os_manual_set($pdo, $orderId, $newStatus, (int)getUserId(), $actorName, $apiReason);
            if (empty($osRes['ok'])) {
                throw new Exception((string)($osRes['message'] ?? 'Could not change the status.'));
            }

            // The tracking code is this endpoint's own business and has nothing
            // to do with the status ladder, so it stays a direct write.
            if (!empty($trackingNumber)) {
                $pdo->prepare("UPDATE orders SET tracking_code = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$trackingNumber, $orderId]);
                $message = 'Order status and tracking number updated successfully';
            } else {
                $message = 'Order status updated successfully';
            }
            // An override that skipped a serial still succeeded, but the caller
            // is told so rather than getting a bare success.
            if (!empty($osRes['warning'])) {
                $message .= ' ' . $osRes['warning'];
            }

            // The notification block below used to be guarded by
            // `if ($stmt->rowCount() > 0)` - the row count of the direct UPDATE
            // that no longer exists. os_manual_set() has already been checked
            // for success above and throws otherwise, so reaching here means
            // the status really did change.
            {
                // Get order details for notification
                $orderStmt = $pdo->prepare("SELECT user_id, invoice_no, total_amount FROM orders WHERE id = ?");
                $orderStmt->execute([$orderId]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
                
                // Send notification for all completed orders
                if ($order && $newStatus === 'completed') {
                    $additionalData = [
                        'total' => formatCurrency($order['total_amount'] ?? 0),
                        'invoice_no' => $order['invoice_no'] ?? '#' . str_pad($orderId, 6, '0', STR_PAD_LEFT)
                    ];
                    
                    // Add tracking number to notification if provided
                    if (!empty($trackingNumber)) {
                        $additionalData['tracking_number'] = $trackingNumber;
                    }
                    
                    // Send completion notification
                    $result = sendOrderStatusNotification($order['user_id'], $orderId, 'delivered', $additionalData);
                    if (!$result || (isset($result['success']) && $result['success'] === false)) {
                        $genericTitle = 'Order Completed - #' . ($order['invoice_no'] ?? str_pad($orderId, 6, '0', STR_PAD_LEFT));
                        $genericMessage = 'Your order has been successfully completed!';
                        createNotification($order['user_id'], 'order_status', $genericTitle, $genericMessage, $additionalData);
                    }
                    
                    // Send completion email with invoice (only for home delivery orders)
                    if (($order['delivery_method'] ?? 'pickup') === 'delivery') {
                        try {
                            $userStmt = $pdo->prepare("SELECT firstname, lastname, email FROM users WHERE id = ?");
                            $userStmt->execute([$order['user_id']]);
                            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($user && !empty($user['email'])) {
                                $fullName = trim($user['firstname'] . ' ' . $user['lastname']);
                                $subject = 'Order Completed - Invoice #' . ($order['invoice_no'] ?? str_pad($orderId, 6, '0', STR_PAD_LEFT));
                                $body = '<p>Hi ' . htmlspecialchars($fullName) . ',</p>' .
                                        '<p>Great news! Your order <strong>' . htmlspecialchars($order['invoice_no'] ?? '#' . str_pad($orderId, 6, '0', STR_PAD_LEFT)) . '</strong> has been successfully delivered.</p>' .
                                        '<p><strong>Total Amount:</strong> ' . formatCurrency($order['total_amount'] ?? 0) . '</p>' .
                                        '<p>Please find your invoice attached to this email or download it from your order details page.</p>' .
                                        '<p>You can view your order details at: <a href="' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId . '">View Order</a></p>' .
                                        '<p>Thank you for choosing TechWorld!</p>';
                                sendEmail($user['email'], $fullName, $subject, $body);
                            }
                        } catch (Throwable $e) {
                            error_log('Failed to send completion email: ' . $e->getMessage());
                        }
                    }
                }
                
                // Log the status update
                logAudit(
                    empty($osRes['warning']) ? 'order_status_update' : 'order_status_update_no_serial',
                    'orders', $orderId, ['status' => $osRes['old'] ?? null], [
                        'status' => $newStatus,
                        'tracking_number' => $trackingNumber,
                        'source' => 'orders_api',
                        'serial_override' => $osRes['warning'] ?? null
                    ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    // Null unless a serial requirement was overridden.
                    'warning' => $osRes['warning'] ?? null
                ]);
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
