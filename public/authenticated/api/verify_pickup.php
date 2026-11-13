<?php
// api/verify_pickup.php - API endpoint for Pay at Store order verification & payment confirmation
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!in_array(getUserRole(), ['driver', 'admin', 'super-admin'])) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$orderId = intval($input['order_id'] ?? 0);
$pickupCode = trim($input['pickup_code'] ?? '');
$confirm = $input['confirm'] ?? false;
$amountPaid = isset($input['amount_paid']) ? trim((string)$input['amount_paid']) : null;
$discountPercentInput = $input['discount_percent'] ?? null;

// Promo discount: a flat percentage off the order total, clamped to a sane range.
$discountPercent = is_numeric($discountPercentInput) ? max(0, min(100, (float)$discountPercentInput)) : 0.0;
$discountType = $discountPercent > 0 ? 'promo' : 'none';

if (!$orderId && !$pickupCode) {
    echo json_encode(['success' => false, 'message' => 'Order ID or pickup code required']);
    exit;
}

try {
    $pdo = getConnection();

    // Build query based on available parameters
    $whereClause = [];
    $params = [];

    if ($orderId) {
        $whereClause[] = "o.id = ?";
        $params[] = $orderId;
    }

    if ($pickupCode) {
        $whereClause[] = "o.pickup_code = ?";
        $params[] = $pickupCode;
    }

    // Get order with customer details
    $sql = "SELECT o.*,
                   CONCAT(u.firstname, ' ', u.lastname) as customer_name,
                   u.email as customer_email
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE " . implode(' AND ', $whereClause);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    // Store Pickup orders go through this workflow, whether paid with cash
    // at the counter (onsite) or already paid online (gcash/paymaya/xendit).
    if (($order['delivery_method'] ?? '') !== 'pickup') {
        echo json_encode(['success' => false, 'message' => 'This order is not a Store Pickup order.']);
        exit;
    }
    $paymentMethodLower = strtolower((string)$order['payment_method']);
    $isCashOnPickup = $paymentMethodLower === 'onsite';
    $isPrepaid = in_array($paymentMethodLower, ['gcash', 'paymaya', 'xendit'], true);

    $totalAmount = (float)$order['total_amount'];
    $creditApplied = (float)($order['credit_applied'] ?? 0);

    // Store credit is spent at checkout (orders.credit_applied) and is already
    // deducted from total_amount, so an order it fully covered has nothing left
    // to collect at the counter - no cash, no gateway. Used below both to skip
    // the cash-tendered requirement and to exempt the order from code expiry:
    // the money is real and already taken, exactly as customer/pickup.php
    // treats an order the gateway has confirmed.
    $nothingToCollect = $totalAmount <= 0.009;
    $nothingOutstanding = $nothingToCollect || ($order['payment_status'] ?? '') === 'paid';

    // Load the order's payment record (created at checkout)
    $payStmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $payStmt->execute([$order['id']]);
    $payment = $payStmt->fetch();

    // Check if already picked up. pickup_verified_at is the only authoritative
    // signal for this - payments.payment_status alone is NOT enough, since for
    // prepaid orders (gcash/paymaya/xendit) it turns 'completed' as soon as the
    // webhook confirms payment, which happens well before the customer actually
    // shows up to collect the item.
    $alreadyCompleted = !empty($order['pickup_verified_at']);
    if ($alreadyCompleted) {
        echo json_encode([
            'success' => false,
            'message' => 'Order already processed' . (!empty($order['pickup_verified_at']) ? ' on ' . date('M j, Y g:i A', strtotime($order['pickup_verified_at'])) : '.')
        ]);
        exit;
    }

    // Check if pickup code expired. An order with nothing outstanding is
    // exempt: the expiry window exists to release stock held against money
    // that never arrived, and refusing to hand over goods the customer has
    // already paid for (in credit, or through the gateway) is not a rollback,
    // it is just keeping both.
    if (!$nothingOutstanding && strtotime($order['pickup_code_expires_at']) < time()) {
        echo json_encode([
            'success' => false,
            'message' => 'Pickup code expired on ' . date('M j, Y g:i A', strtotime($order['pickup_code_expires_at']))
        ]);
        exit;
    }

    // A cancelled order is never eligible for pickup/payment, regardless of role
    if ($order['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'This order was cancelled and cannot be processed.']);
        exit;
    }

    // Check order status
    $userRole = getUserRole();
    $statusAllowed = in_array($order['status'], ['ready', 'processing']);

    // Allow admins and super-admins to override the status restriction so they can process orders.
    $isAdminOverride = in_array($userRole, ['admin', 'super-admin']) && !$statusAllowed;

    if (!$statusAllowed && !$isAdminOverride) {
        echo json_encode([
            'success' => false,
            'message' => 'Order is not ready for pickup (status: ' . $order['status'] . ')'
        ]);
        exit;
    }

    // Fetch order items with product names for the confirmation/invoice view
    $itemsStmt = $pdo->prepare("
        SELECT oi.product_id, oi.quantity, oi.price, oi.total, p.name AS product_name
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$order['id']]);
    $items = $itemsStmt->fetchAll();
    $order['items'] = $items;
    $order['payment'] = $payment ?: null;

    // If not confirming, just return order + payment data for the confirmation screen
    if (!$confirm) {
        $response = [
            'success' => true,
            'message' => 'Pickup code verified successfully',
            'order' => $order
        ];

        if ($isAdminOverride) {
            $response['warning'] = 'Order status is ' . $order['status'] . '. Admin override is allowed.';
        }

        echo json_encode($response);
        exit;
    }

    // $nothingToCollect is tested before the prepaid gate on purpose: a
    // zero-total e-payment order was never charged through the gateway, so
    // waiting for a webhook to flip payment_status would hold it hostage
    // forever. The customer has already paid, in credit, and the counter just
    // needs Complete.
    if ($nothingToCollect) {
        $discountAmount = 0.0;
        $amountDue = 0.0;
        $amountPaid = 0.0;
        $change = 0.0;
        $discountType = 'none';
        $discountLabel = 'None';
    } elseif ($isPrepaid) {
        // The customer already paid online. The server's own payment_status
        // (set only by the Xendit webhook or a staff-verified manual
        // reference - never by anything the browser sends here) is the sole
        // source of truth. Refuse to release the order until it says 'paid',
        // even if a client is somehow claiming otherwise.
        if (($order['payment_status'] ?? '') !== 'paid') {
            echo json_encode(['success' => false, 'message' => 'Payment has not been confirmed yet for this order. It cannot be completed until payment is verified.']);
            exit;
        }
        $discountAmount = 0.0;
        $amountDue = $totalAmount;
        $amountPaid = $totalAmount;
        $change = 0.0;
        $discountType = 'none';
        $discountLabel = 'None';
    } else {
        // Confirming payment requires a valid tendered amount for Pay at Store orders
        $discountAmount = round($totalAmount * $discountPercent / 100, 2);
        $amountDue = round($totalAmount - $discountAmount, 2);

        if ($amountPaid === null || $amountPaid === '' || !is_numeric($amountPaid)) {
            echo json_encode(['success' => false, 'message' => 'Enter a valid amount paid by the customer.']);
            exit;
        }
        $amountPaid = round((float)$amountPaid, 2);
        if ($amountPaid < $amountDue) {
            echo json_encode(['success' => false, 'message' => 'Amount paid is less than the amount due.']);
            exit;
        }
        $change = round($amountPaid - $amountDue, 2);
        $discountLabel = $discountType === 'promo' ? 'Promo (' . $discountPercent . '%)' : 'None';
    }

    // Confirm pickup + payment - update order, payment, and log the transaction
    $pdo->beginTransaction();

    try {
        // Update order with pickup verification. A store-credit-settled order
        // also gets orders.payment_status = 'paid' here: the money is real and
        // was taken from the customer's ledger at checkout, so leaving it
        // 'pending' would show a completed order as unpaid in every report
        // that reads that column.
        $updateStmt = $pdo->prepare("
            UPDATE orders
            SET pickup_verified_at = NOW(),
                pickup_verified_by = ?,
                status = 'completed',
                " . (($nothingToCollect && array_key_exists('payment_status', $order)) ? "payment_status = 'paid'," : "") . "
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([getUserId(), $order['id']]);

        /* THE UNITS LEAVE WITH THE CUSTOMER HERE.
           
           This path completes a pickup order without going through
           os_advance_by_scan(), so nothing was marking its serialised units
           sold - they stayed 'reserved' forever, with no date_sold. The
           consequence was not cosmetic: pu_accept_return() refuses a reserved
           unit with "reserved for an order that has not been handed over yet",
           so a customer bringing back a laptop they had collected at this very
           counter would have been turned away.
           
           Inside the transaction, so the sale date and the status change share
           one fate. */
        $unitsSold = pu_mark_order_sold($pdo, (int)$order['id']);

        // The customer is walking out with it, so the store warranty starts
        // today. Same reasoning and the same guard as the scan path.
        require_once __DIR__ . '/../../../config/warranty.php';
        $warrantiesStarted = warranty_start_for_order($pdo, (int)$order['id']);

        // And the sale goes into the stock movement log.
        require_once __DIR__ . '/../../../config/inventory_stock.php';
        inv_log_sold_for_order($pdo, (int)$order['id'], (int)getUserId());

        /* The admin override above ($isAdminOverride) lets staff complete an
           order that is NOT yet 'ready' - including one still in 'preparing',
           which has therefore never passed the serial gate in
           os_advance_by_scan(). That is a legitimate escape hatch, but it must
           not be silent: the same rule os_manual_set() follows applies here.
           Recorded against the operator and returned to the screen. */
        $serialOverride = null;
        if ($isAdminOverride) {
            $missingPickup = pu_unassigned_lines($pdo, (int)$order['id']);
            if ($missingPickup) {
                $bits = [];
                foreach ($missingPickup as $mp) {
                    $bits[] = $mp['product_name'] . ' ('
                        . ((int)$mp['quantity'] - (int)$mp['assigned']) . ' of ' . (int)$mp['quantity'] . ')';
                }
                $serialOverride = 'Released with no serial number recorded for: ' . implode('; ', $bits)
                                . '. This override has been logged against your name.';
            }
        }

        // Update (or create, for legacy orders missing one) the payment record.
        // amount reflects what was actually charged (after any PWD/Senior/custom discount).
        if ($payment) {
            $updPay = $pdo->prepare("UPDATE payments SET payment_status = 'completed', payment_date = NOW(), amount = ? WHERE id = ?");
            $updPay->execute([$amountDue, $payment['id']]);
            $paymentId = $payment['id'];
        } else {
            $paymentId = $isPrepaid
                ? createPayment($order['id'], $order['user_id'], 'online', 'store_pickup', $amountDue, 'completed')
                : createPayment($order['id'], $order['user_id'], 'onsite', 'store_pickup', $amountDue, 'completed');
        }

        // Log the transaction - for prepaid orders there's no cash tendered,
        // just a record that the (already-paid) order was released in person.
        if ($paymentId) {
            $txnNotes = $nothingToCollect
                ? ("Settled in full by store credit (" . formatCurrency($creditApplied) . ") | Released at pickup, nothing collected | Balance due: " . formatCurrency(0))
                : ($isPrepaid
                ? ("Prepaid via " . getPaymentMethodLabel($order['payment_method']) . " | Released at pickup, no cash collected | Total: " . formatCurrency($totalAmount))
                : ("Subtotal: " . formatCurrency($totalAmount) . " | Discount: $discountLabel (-" . formatCurrency($discountAmount) . ") | Amount due: " . formatCurrency($amountDue) . " | Amount paid: " . formatCurrency($amountPaid) . " | Change: " . formatCurrency($change)));
            $txnStmt = $pdo->prepare("
                INSERT INTO payment_transactions (payment_id, transaction_type, amount, status, processed_at, processed_by, notes)
                VALUES (?, 'payment', ?, 'completed', NOW(), ?, ?)
            ");
            $txnStmt->execute([
                $paymentId,
                $amountPaid,
                getUserId(),
                $txnNotes
            ]);
        }

        // Log audit trail
        logAudit(
            'pickup_verified',
            'orders',
            $order['id'],
            ['status' => $order['status']],
            [
                'status' => 'completed', 'pickup_verified_by' => getUserId(),
                'discount_type' => $discountType, 'discount_amount' => $discountAmount,
                'amount_due' => $amountDue, 'amount_paid' => $amountPaid, 'change' => $change,
                'units_marked_sold' => $unitsSold,
                'warranties_started' => $warrantiesStarted,
                'serial_override' => $serialOverride
            ]
        );

        // A release that skipped the serial gate gets its own audit action, so
        // it can be found without reading every pickup_verified row.
        if ($serialOverride !== null) {
            logAuditFailure('pickup_verified_no_serial', 'orders', (int)$order['id'],
                $serialOverride, ['status' => $order['status']], ['status' => 'completed']);
        }

        // Notify customer
        $notificationTitle = 'Order Picked Up Successfully';
        $notificationMessage = "Your order #{$order['invoice_no']} has been picked up and paid successfully. Thank you for shopping with TechWorld!";
        addNotification($order['user_id'], $notificationTitle, $notificationMessage, 'order_status');

        $pdo->commit();

        $processedBy = getUserData();

        echo json_encode([
            'success' => true,
            'message' => 'Pickup confirmed successfully'
                . ($serialOverride !== null ? ' - ' . $serialOverride : ''),
            'serial_override' => $serialOverride,
            'order' => array_merge($order, [
                'pickup_verified_at' => date('Y-m-d H:i:s'),
                'pickup_verified_by' => getUserId(),
                'status' => 'completed'
            ]),
            'invoice' => [
                'invoice_no' => $order['invoice_no'],
                'customer_name' => $order['customer_name'],
                'items' => $items,
                'subtotal' => $totalAmount,
                // Credit spent at checkout, echoed back so the counter receipt
                // can say where the money came from on a ₱0.00 collection.
                'credit_applied' => $creditApplied,
                'settled_by_credit' => $nothingToCollect,
                'discount_type' => $discountType,
                'discount_label' => $discountLabel,
                'discount_amount' => $discountAmount,
                'total_amount' => $amountDue,
                'amount_paid' => $amountPaid,
                'change' => $change,
                'payment_method' => $nothingToCollect ? 'Store Credit' : getPaymentMethodLabel($order['payment_method']),
                'processed_by' => $processedBy ? trim(($processedBy['firstname'] ?? '') . ' ' . ($processedBy['lastname'] ?? '')) : '',
                'processed_at' => date('M j, Y g:i A')
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }

} catch (PDOException $e) {
    error_log("Pickup verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Pickup verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred during pickup verification']);
}
