<?php
// customer/process_order.php - Order processing endpoint
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';
// Arrival estimate used in the confirmation notification below.
require_once __DIR__ . '/../../../config/delivery_estimate.php';
// Per-unit (serial/IMEI) stock. Only products whose tracking is live are
// touched - see the reservation block further down.
require_once __DIR__ . '/../../../config/product_units.php';
// The 7-day store warranty, registered automatically for every purchase.
require_once __DIR__ . '/../../../config/warranty.php';

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

try {
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    $userId = getUserId();
    
    // Get form data
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    // Map pin for the chosen address (checkout.php's #delivery_latitude /
    // #delivery_longitude). Optional: blank means the customer never pinned
    // one, and every map downstream falls back to geocoding $address.
    // Both or neither - half a coordinate would put the rider in the ocean.
    // The bounds are the Philippines; anything outside is a bad payload.
    $deliveryLat = null;
    $deliveryLng = null;
    $rawLat = $_POST['delivery_latitude'] ?? '';
    $rawLng = $_POST['delivery_longitude'] ?? '';
    if (is_numeric($rawLat) && is_numeric($rawLng)) {
        $candLat = (float)$rawLat;
        $candLng = (float)$rawLng;
        if ($candLat >= 4.0 && $candLat <= 22.0 && $candLng >= 112.0 && $candLng <= 127.0) {
            $deliveryLat = round($candLat, 7);
            $deliveryLng = round($candLng, 7);
        }
    }
    $paymentMethod = $_POST['payment_method'] ?? '';
    $deliveryMethod = $_POST['delivery_method'] ?? 'delivery';
    $orderNotes = trim($_POST['order_notes'] ?? '');
    
    // Debug logging for troubleshooting
    error_log("Order Processing Debug - Payment Method: '$paymentMethod', Delivery Method: '$deliveryMethod'");
    
    // For store pickup, fallback address and phone if not provided
    if ($deliveryMethod === 'pickup' || $paymentMethod === 'store') {
        if (empty($address) || $address === 'Enter your delivery address') {
            $address = 'Store Pickup';
        }
        if (empty($phone)) {
            $phone = 'N/A';
        }
        // Nothing is being delivered anywhere - a drop-off pin here would only
        // mislead whoever reads the order later.
        $deliveryLat = null;
        $deliveryLng = null;
    }

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
    // If checkout was initiated with a selection, expect hidden input 'selected_ids'
    $selectedRaw = trim($_POST['selected_ids'] ?? '');
    $selectedIds = [];
    if ($selectedRaw !== '') {
        $selectedIds = array_filter(array_map('intval', explode(',', $selectedRaw)));
        $selectedIds = array_values(array_unique($selectedIds));
    }
    // Restrict to selected IDs if provided; otherwise use all cart items
    if (!empty($selectedIds)) {
        $intersect = array_values(array_intersect(array_keys($cartSession), $selectedIds));
        // If nothing intersected (e.g., session cart empty but selected provided), use selected IDs directly
        $productIds = !empty($intersect) ? $intersect : $selectedIds;
        // Ensure session has at least quantity 1 for any selected IDs missing from session
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
    // Read the rows this order needs with FOR UPDATE, so from here to the
    // COMMIT no other checkout can read or change their stock.
    //
    // WHY: this SELECT used to be an ordinary read. The availability check
    // below and the `stock = stock - ?` further down are separate statements
    // with the whole order build in between, so two concurrent checkouts both
    // read stock = 1, both passed the check, and both deducted - which is
    // exactly how stock went to -1 and the inventory page reported "Oversold".
    // InnoDB row locks close that window: the second request now blocks here
    // until the first commits, then reads the already-decremented stock and
    // fails the check honestly.
    //
    // ORDER BY id ASC is deliberate: every checkout (including a multi-item
    // AI Builder build) takes its locks in the same product-id order, so two
    // overlapping orders sharing two products can never hold one lock each and
    // wait on the other - no deadlock. The deduction loop below is sorted the
    // same way for the same reason.
    //
    // status = 'active' is kept exactly as it was - an inactive product is
    // still silently dropped from the order, same as before this change.
    $stmt = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE id IN ($placeholders) AND status = 'active' ORDER BY id ASC FOR UPDATE");
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
    
    // Check stock availability. The rows are locked, so these numbers are the
    // real ones and cannot move before the deduction below. The message is
    // split so the customer who lost the race is told plainly what happened:
    // nothing has been written yet (the transaction is rolled back by the
    // handler at the bottom) and their cart is untouched.
    foreach ($cartItems as $item) {
        $available = (int)$item['stock'];
        $wanted    = (int)$item['quantity'];
        if ($available <= 0) {
            throw new RuntimeException("Sorry, this item just sold out: {$item['name']}. Your cart has not been changed.");
        }
        if ($available < $wanted) {
            throw new RuntimeException("Only {$available} left of {$item['name']} (you asked for {$wanted}). Your cart has not been changed.");
        }
    }
    
    // Calculate totals - PH standard 12% VAT is added on top of the item subtotal
    $subtotal = array_sum(array_map(function($item) {
        return $item['price'] * $item['quantity'];
    }, $cartItems));

    $shipping = 0; // Free shipping
    $tax = round($subtotal * 0.12, 2);
    $total = $subtotal + $shipping + $tax;

    // Store credit is applied as a reduction of what's actually charged
    // through the chosen payment method, not as a discount to the taxable
    // subtotal - same as a gift card. The client-submitted amount is never
    // trusted: it's re-clamped here to the customer's real ledger balance
    // (getStoreCreditBalance queries the DB directly) and to the order
    // total itself, so this can never be manipulated into a negative total
    // or into spending credit the customer doesn't have.
    $creditRequested = max(0, (float)($_POST['use_store_credit'] ?? 0));
    $creditToApply = 0.0;
    if ($creditRequested > 0) {
        $availableCredit = getStoreCreditBalance($userId);
        $creditToApply = round(min($creditRequested, $availableCredit, $total), 2);
        $total = round($total - $creditToApply, 2);
    }

    // "Store credit" is only a payment method when the credit actually
    // settles the whole order - there is nothing left for a gateway or a
    // rider to collect, so checkout.php locks the payment choice and posts
    // this instead. The claim is re-proved here against the ledger totals
    // computed above: the POST is as forgeable as any other field, and a
    // crafted one must not buy a free order out of an empty balance.
    $paidByCredit = ($paymentMethod === 'store_credit');
    if ($paidByCredit && ($creditToApply <= 0 || $total > 0.009)) {
        throw new RuntimeException('Your store credit no longer covers this order - please choose a payment method.');
    }

    // Determine schema of orders table and insert accordingly (backward compatible)
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $colsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'orders'");
    $colsStmt->execute([$dbName]);
    $orderCols = array_map('strtolower', array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));

    $hasExtended = in_array('invoice_no', $orderCols, true) && in_array('tracking_code', $orderCols, true);
    $hasOrderHash = in_array('order_hash', $orderCols, true);

    // Generate a deterministic order hash (idempotency key) to prevent double orders on rapid clicks
    $orderHash = null;
    if ($hasOrderHash) {
        // Use stable cart data plus a 2-minute time bucket, so multiple submissions of the same
        // cart within the same short window produce the same hash (catches rapid double-clicks),
        // but the same product ordered again later gets a different hash - order_hash has a
        // UNIQUE constraint, so a never-expiring fingerprint would fail INSERT on any later reorder.
        $cartFingerprint = array_map(function($i){ return ['id'=>$i['product_id'], 'qty'=>$i['quantity'], 'price'=>$i['price']]; }, $cartItems);
        $timeBucket = (int)floor(time() / 120);
        $orderHash = hash(
            'sha256',
            (string)$userId . '|' . json_encode($cartFingerprint, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . '|' . strtolower((string)$deliveryMethod) . '|' . strtolower((string)$paymentMethod) . '|' . $timeBucket
        );
        // If an order with the same hash already exists (same cart, same 2-minute window), reuse
        // it - this only guards against accidental double-submits, not a legitimate later reorder.
        $existingStmt = $pdo->prepare("SELECT id FROM orders WHERE order_hash = ? LIMIT 1");
        $existingStmt->execute([$orderHash]);
        $existingId = $existingStmt->fetchColumn();
        if ($existingId) {
            // If called via fetch/XHR, return JSON with redirect instead of issuing an HTTP redirect
            echo json_encode([
                'success' => true,
                'message' => 'Order already created. Redirecting…',
                'order_id' => (int)$existingId,
                'redirect' => WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . (int)$existingId
            ]);
            exit;
        }
    }

    if ($hasExtended) {
        // Newer schema with invoice/tracking/pickup and totals named total_amount
        $invoiceNo = generateInvoiceNumber();
        $trackingCode = generateTrackingCode();
        
        // Map payment method label if using onsite instead of store (use canonical lowercase)
        // 'gcash'/'paymaya'/'xendit' pass through unchanged - they're valid orders.payment_method values.
        $dbPaymentMethod = ($paymentMethod === 'store') ? 'onsite' : $paymentMethod;
        $isManualEpayment = in_array($dbPaymentMethod, ['gcash', 'paymaya'], true); // customer submits their own reference number
        $isXendit = ($dbPaymentMethod === 'xendit'); // hosted checkout, webhook-confirmed

        // Where this order starts on the ladder. Orders paid online begin at
        // 'awaiting_payment' instead of 'preparing': nobody picks stock for an
        // order that has not been paid for yet. The Xendit webhook releases it
        // into 'preparing' the moment payment actually succeeds (see
        // os_release_paid_order()), and cancelExpiredOnlinePaymentOrders() in
        // functions.php still cancels it and puts the stock back if payment
        // never lands within the hour. COD and pay-at-store are unchanged and
        // still start at 'preparing' - for those the money arrives at handover.
        $initialStatus = os_initial_status($dbPaymentMethod);
        $isEpayment = $isManualEpayment || $isXendit;

        // Generate pickup code for onsite payments and pickup orders
        $pickupCode = '';
        $pickupExpiresAt = null;

        if ($deliveryMethod === 'pickup') {
            $pickupCode = generatePickupCode();
            $pickupExpiresAt = date('Y-m-d H:i:s', strtotime('+60 minutes'));
        } elseif ($dbPaymentMethod === 'onsite') {
            // For onsite payments, generate pickup code with 1-hour expiry
            $pickupCode = generatePickupCode();
            $pickupExpiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        }

        // Manual e-payment orders (GCash/Maya) get a short reference code the
        // customer quotes when they pay, so staff can match it in their
        // merchant app. Xendit doesn't need this - the hosted checkout and
        // webhook handle matching on their own.
        $paymentReference = $isManualEpayment ? strtoupper(bin2hex(random_bytes(4))) : null;

        // Set delivery method and payment method display values
        $dbDeliveryMethod = $deliveryMethod; // 'delivery' or 'pickup'
        $epaymentLabels = ['gcash' => 'GCash Payment', 'paymaya' => 'Maya Payment', 'xendit' => 'Online Payment (Xendit)'];
        if ($paidByCredit) {
            // Not "Home Delivery Payment"/"Store Pickup Payment": nothing is
            // collected at handover, and this string is what the invoice,
            // the dispatch sheet and the admin lists print.
            $paymentMethodDisplay = 'Store Credit';
        } else {
            $paymentMethodDisplay = $isEpayment
                ? ($epaymentLabels[$dbPaymentMethod] ?? 'E-Payment')
                : (($deliveryMethod === 'delivery') ? 'Home Delivery Payment' : 'Store Pickup Payment');
        }

        // Check if new columns exist
        $hasDeliveryMethod = in_array('delivery_method', $orderCols, true);
        $hasPaymentMethodDisplay = in_array('payment_method_display', $orderCols, true);
        $hasPaymentReference = in_array('payment_reference', $orderCols, true);
        // Snapshot of the address actually picked at checkout (full_name/
        // phone/address above, from applyAddress() on the checkout page) -
        // without this, every page that displays "the delivery address"
        // has to re-query user_addresses for whatever the customer's
        // CURRENT default/first saved address happens to be, completely
        // disconnected from what was actually selected here.
        $hasDeliverySnapshot = in_array('delivery_recipient_name', $orderCols, true)
            && in_array('delivery_phone', $orderCols, true)
            && in_array('delivery_full_address', $orderCols, true);
        $snapCols = $hasDeliverySnapshot ? ', delivery_recipient_name, delivery_phone, delivery_full_address' : '';
        $snapPlaceholders = $hasDeliverySnapshot ? ', ?, ?, ?' : '';
        $snapParams = $hasDeliverySnapshot ? [$fullName, $phone, $address] : [];

        // The pin is snapshotted for the same reason the address text is: it
        // must stay the pin chosen for THIS delivery even if the customer
        // later edits or deletes that saved address.
        // DBSQL/add_address_geo_pin.sql.
        $hasDeliveryGeo = $hasDeliverySnapshot
            && in_array('delivery_latitude', $orderCols, true)
            && in_array('delivery_longitude', $orderCols, true);
        if ($hasDeliveryGeo) {
            $snapCols .= ', delivery_latitude, delivery_longitude';
            $snapPlaceholders .= ', ?, ?';
            $snapParams[] = $deliveryLat;
            $snapParams[] = $deliveryLng;
        }

        // Prefer column names present
        $hasTotalAmount = in_array('total_amount', $orderCols, true);
        if ($hasTotalAmount) {
            if ($hasOrderHash) {
                if ($hasDeliveryMethod && $hasPaymentMethodDisplay && $hasPaymentReference) {
                    $stmtSql = "INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, order_hash, total_amount, payment_method, delivery_method, payment_method_display, payment_reference, status, notes, created_at$snapCols) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', ?, NOW()$snapPlaceholders)";
                    $orderStmt = $pdo->prepare($stmtSql);
                    $orderStmt->execute(array_merge([$userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $orderHash, $total, $dbPaymentMethod, $dbDeliveryMethod, $paymentMethodDisplay, $paymentReference, $orderNotes], $snapParams));
                } elseif ($hasDeliveryMethod && $hasPaymentMethodDisplay) {
                    $stmtSql = "INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, order_hash, total_amount, payment_method, delivery_method, payment_method_display, status, notes, created_at$snapCols) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', ?, NOW()$snapPlaceholders)";
                    $orderStmt = $pdo->prepare($stmtSql);
                    $orderStmt->execute(array_merge([$userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $orderHash, $total, $dbPaymentMethod, $dbDeliveryMethod, $paymentMethodDisplay, $orderNotes], $snapParams));
                } else {
                    $stmtSql = "INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, order_hash, total_amount, payment_method, status, notes, created_at$snapCols) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', ?, NOW()$snapPlaceholders)";
                    $orderStmt = $pdo->prepare($stmtSql);
                    $orderStmt->execute(array_merge([$userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $orderHash, $total, $dbPaymentMethod, $orderNotes], $snapParams));
                }
            } else {
                if ($hasDeliveryMethod && $hasPaymentMethodDisplay) {
                    $stmtSql = "INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, total_amount, payment_method, delivery_method, payment_method_display, status, notes, created_at$snapCols) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', ?, NOW()$snapPlaceholders)";
                    $orderStmt = $pdo->prepare($stmtSql);
                    $orderStmt->execute(array_merge([$userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $total, $dbPaymentMethod, $dbDeliveryMethod, $paymentMethodDisplay, $orderNotes], $snapParams));
                } else {
                    $stmtSql = "INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, total_amount, payment_method, status, notes, created_at$snapCols) VALUES (?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', ?, NOW()$snapPlaceholders)";
                    $orderStmt = $pdo->prepare($stmtSql);
                    $orderStmt->execute(array_merge([$userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $total, $dbPaymentMethod, $orderNotes], $snapParams));
                }
            }
        } else {
            // Extended columns plus old totals
            if ($hasOrderHash) {
                $stmtSql = "INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, order_hash, full_name, email, phone, address, payment_method, delivery_method, order_notes, subtotal, shipping, tax, total, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', NOW())";
                $orderStmt = $pdo->prepare($stmtSql);
                $orderStmt->execute([$userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $orderHash, $fullName, $email, $phone, $address, $dbPaymentMethod, $deliveryMethod, $orderNotes, $subtotal, $shipping, $tax, $total]);
            } else {
                $stmtSql = "INSERT INTO orders (user_id, invoice_no, tracking_code, pickup_code, pickup_code_expires_at, full_name, email, phone, address, payment_method, delivery_method, order_notes, subtotal, shipping, tax, total, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', NOW())";
                $orderStmt = $pdo->prepare($stmtSql);
                $orderStmt->execute([$userId, $invoiceNo, $trackingCode, $pickupCode, $pickupExpiresAt, $fullName, $email, $phone, $address, $dbPaymentMethod, $deliveryMethod, $orderNotes, $subtotal, $shipping, $tax, $total]);
            }
        }
    } else {
        // Legacy schema without tracking/invoice/pickup
        if ($hasOrderHash) {
            $orderStmt = $pdo->prepare("INSERT INTO orders (user_id, order_hash, full_name, email, phone, address, payment_method, delivery_method, order_notes, subtotal, shipping, tax, total, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', NOW())");
            $orderStmt->execute([$userId, $orderHash, $fullName, $email, $phone, $address, $paymentMethod, $deliveryMethod, $orderNotes, $subtotal, $shipping, $tax, $total]);
        } else {
            $orderStmt = $pdo->prepare("INSERT INTO orders (user_id, full_name, email, phone, address, payment_method, delivery_method, order_notes, subtotal, shipping, tax, total, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '{$initialStatus}', NOW())");
            $orderStmt->execute([$userId, $fullName, $email, $phone, $address, $paymentMethod, $deliveryMethod, $orderNotes, $subtotal, $shipping, $tax, $total]);
        }
        // For legacy, synthesize values for notification/display
        $invoiceNo = 'INV-' . str_pad((string)$pdo->lastInsertId(), 6, '0', STR_PAD_LEFT);
        $trackingCode = null;
    }
    
    $orderId = $pdo->lastInsertId();

    // Actually spend the credit now that there's a real order to attach the
    // ledger entry to - inside the same transaction as everything else
    // here, so a failure here rolls back the whole order too, the same way
    // an insufficient-stock check earlier would.
    if ($creditToApply > 0) {
        $pdo->prepare("UPDATE orders SET credit_applied = ? WHERE id = ?")->execute([$creditToApply, $orderId]);
        $deductId = deductStoreCredit($userId, $creditToApply, 'redemption', $orderId, "Applied to order #$orderId at checkout");
        if ($deductId === false) {
            throw new RuntimeException('Failed to apply your store credit - please try again.');
        }
    }

    // A store-credit order is settled the moment it is placed: the ledger
    // deduction above IS the payment. Stamping that here, rather than
    // leaving payment_status 'pending' the way a cash order does, is what
    // keeps the rider and the pickup counter from being handed an order
    // with PHP 0.00 still to collect.
    if ($paidByCredit && in_array('payment_status', $orderCols, true)) {
        $paidAtSet = in_array('paid_at', $orderCols, true) ? ', paid_at = NOW()' : '';
        $pdo->prepare("UPDATE orders SET payment_status = 'paid'{$paidAtSet} WHERE id = ?")->execute([$orderId]);
    }

    // NOTE: a QR block used to sit here and was removed. It wrote `order_code`
    // and `expires_at` - columns that do not exist on this schema - so it threw
    // on every onsite order, was swallowed by its own catch, and left a
    // "Column not found: 1054 Unknown column 'order_code'" line in the error
    // log for each one. Nothing downstream ever read what it produced.
    //
    // It was left over from an earlier copy of this project: the code it
    // encoded was "ORD" . rand(1000, 9999) - 9000 possible values from a
    // non-cryptographic RNG, as a token for collecting goods - and the URL it
    // pointed at was hardcoded to another machine's IP and a TECHWRLD_V2
    // path with a verify_order.php that does not exist here.
    //
    // Pickup verification is already handled, properly, by the pickup_code /
    // pickup_code_expires_at columns: generatePickupCode() fills them in the
    // INSERT above, customer/payment_qr.php renders the QR from them (and
    // renews an expired one), and api/verify_pickup.php is what the counter
    // scans against. There was nothing here to replace.

    // Create payment record with correct mapping
    if ($paidByCredit) {
        // payment_type stays fulfilment-shaped (that is what it describes);
        // only the method says the money came from the ledger. Recorded as
        // completed because it already is - see the payment_status stamp above.
        $paymentType = ($deliveryMethod === 'delivery') ? 'home_delivery' : 'store_pickup';
        $paymentMethodCode = 'store_credit';
        $paymentNotes = ['channel' => 'store_credit', 'credit_applied' => $creditToApply];
    } elseif ($isEpayment) {
        // GCash/Maya/Xendit: payments.payment_method/payment_type both have
        // dedicated ENUM values for this, unlike the cod/store legacy codes below.
        $paymentType = 'online_payment';
        $paymentMethodCode = 'online';
        // createPayment() json_encodes this 8th arg into payments.notes.
        $paymentNotes = $isXendit
            ? ['channel' => 'xendit', 'status' => 'awaiting_gateway_confirmation']
            : ['channel' => $dbPaymentMethod, 'reference' => $paymentReference, 'status' => 'pending_verification'];
    } else {
        $paymentType = ($deliveryMethod === 'delivery') ? 'home_delivery' : 'store_pickup';
        // Home Delivery = In-Store Payment, Store Pickup = Cash on Delivery
        $paymentMethodCode = ($deliveryMethod === 'delivery') ? 'home_delivery' : 'store_pickup';
        $paymentNotes = null;
    }
    // Cash payments stay pending until collected; e-payments until staff
    // verify the reference. Store credit is already collected.
    $paymentStatus = $paidByCredit ? 'completed' : 'pending';
    // $total is zero on a credit-settled order by definition, so recording it
    // would log a payment of nothing - the figure that actually moved is the
    // credit drawn from the ledger.
    $paymentAmount = $paidByCredit ? $creditToApply : $total;

    $paymentId = createPayment(
        $orderId,
        $userId,
        $paymentMethodCode,
        $paymentType,
        $paymentAmount,
        $paymentStatus,
        null,
        $paymentNotes
    );
    
    if (!$paymentId) {
        error_log("Failed to create payment record for order ID: $orderId");
    }
    
    // Create order items and deduct stock.
    //
    // Deduction is ATOMIC: the `AND stock >= ?` makes the database itself
    // re-prove availability as part of the write, and rowCount() === 0 means
    // it could not. That can only happen if something slipped past the locked
    // check above, so it aborts the whole order rather than writing a short
    // one. Combined with the FOR UPDATE read and the CHECK (stock >= 0)
    // constraint on products.stock, overselling has three independent
    // barriers; this one is the barrier that does not depend on the read.
    //
    // $cartItems is sorted by product_id first so the UPDATEs take their row
    // locks in the same order the SELECT ... FOR UPDATE above took them, and
    // in the same order every other concurrent checkout takes them. A
    // multi-item order (an AI Builder build is the big one) therefore cannot
    // deadlock against another order that shares some of its products.
    //
    // Any throw below leaves the single transaction opened at the top of this
    // file uncommitted - the catch block rolls it back, so the order row, the
    // payment row, the items written so far, the store-credit ledger entry and
    // every stock deduction are all undone together. There is no partial order.
    usort($cartItems, function ($a, $b) {
        return (int)$a['product_id'] <=> (int)$b['product_id'];
    });

    $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, total) VALUES (?, ?, ?, ?, ?)");
    $updateStock = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
    
    foreach ($cartItems as $item) {
        $itemTotal = $item['price'] * $item['quantity'];
        $itemStmt->execute([
            $orderId, 
            $item['product_id'], 
            $item['quantity'], 
            $item['price'],
            $itemTotal
        ]);
        // Read immediately, not after the UPDATE below. lastInsertId() happens
        // to survive an UPDATE on MySQL, but it is the id of "the last
        // auto-increment on this connection" - reading it two statements later
        // makes the unit reservation depend on that staying true.
        $orderItemId = (int)$pdo->lastInsertId();

        // Update product stock - conditional, so it is a no-op (0 rows) rather
        // than a negative balance when the stock is no longer there.
        $qty = (int)$item['quantity'];
        $updateStock->execute([$qty, (int)$item['product_id'], $qty]);
        if ($updateStock->rowCount() === 0) {
            throw new RuntimeException("Sorry, this item just sold out: {$item['name']}. Your cart has not been changed and nothing has been charged.");
        }

        // Claim specific physical units for this line, for a product that is
        // tracked per serial number.
        //
        // WHY HERE AND NOT AT PACKING. The deduction above happens at
        // checkout, not at fulfilment, so between this moment and the moment
        // staff pick the order `products.stock` is already net of it. For a
        // tracked product that number is a mirror of the in-stock unit count
        // (config/product_units.php), so the units have to leave the shelf on
        // the same statement the stock figure does, or the two would disagree
        // for every order waiting to be picked.
        //
        // Which units these are does not matter yet and is not asked: any
        // in-stock unit of the right model satisfies the order. The serial
        // that actually goes in the box is recorded by staff at picking
        // (api/order_units.php), which is the one thing a human can do that
        // this code cannot - read the label on the box in their hand.
        //
        // The rows are locked FOR UPDATE inside pu_reserve_for_order_item(),
        // and the product row itself is already held by the SELECT ... FOR
        // UPDATE at the top of this file, so two checkouts cannot both claim
        // the same unit.
        //
        // pu_tracking_live() rather than requires_serial: a product flagged
        // for tracking that nobody has entered serials for yet still sells on
        // its quantity stock, exactly as it did before this feature existed.
        // Without that distinction, turning the flag on for six whole
        // categories would have failed every checkout for the 138 products
        // that have not been onboarded.
        if (pu_tracking_live($pdo, (int)$item['product_id'])) {
            $reserved = pu_reserve_for_order_item($pdo, (int)$item['product_id'], $orderItemId, $qty);
            if ($reserved < $qty) {
                // The shelf is short of identified units. Treated exactly as a
                // failed stock deduction: the whole transaction rolls back, so
                // the order, its items, the payment row, the store-credit entry
                // and every other reservation are all undone together.
                throw new RuntimeException("Sorry, we can only confirm {$reserved} of {$qty} for {$item['name']} right now. Your cart has not been changed and nothing has been charged.");
            }
        }
    }
    
    /* AUTOMATIC WARRANTY REGISTRATION.
     *
     * Inside the order's own transaction, after the items and the unit
     * reservations exist - it reads both. If anything below throws, the
     * warranties roll back with the order rather than being left pointing at
     * a sale that never happened.
     *
     * The rows are created with no start date. A 7-day store warranty must not
     * burn days while the goods are still with us, so the clock is started at
     * handover by warranty_start_for_order() - see config/warranty.php for the
     * delivery timings that settled that.
     *
     * Best-effort: a customer must still get their order if warranty
     * registration hits something unexpected. It is logged loudly and can be
     * re-run - warranty_register_for_order() is idempotent.
     */
    try {
        warranty_register_for_order($pdo, $orderId);
    } catch (Throwable $e) {
        error_log('warranty_register_for_order failed for order ' . $orderId . ': ' . $e->getMessage());
    }

    // Clear only purchased items from session cart
    foreach ($cartItems as $item) {
        unset($_SESSION['cart'][$userId][$item['product_id']]);
    }
    if (empty($_SESSION['cart'][$userId])) {
        unset($_SESSION['cart'][$userId]);
    }
    
    // Also clear purchased items from database cart
    if (function_exists('clearCartInDb')) {
        // Clear only the purchased items from database cart
        $pdo = getConnection();
        $productIds = array_column($cartItems, 'product_id');
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $clearDbStmt = $pdo->prepare("DELETE FROM user_carts WHERE user_id = ? AND product_id IN ($placeholders)");
            $clearDbStmt->execute(array_merge([$userId], $productIds));
        }
    }
    
    // Log audit
    logAudit('order_created', 'orders', $orderId, null, [
        'order_id' => $orderId,
        'total' => $total,
        'payment_method' => $paymentMethod,
        'delivery_method' => $deliveryMethod
    ]);
    
    $pdo->commit();
    
    // Arrival estimate for the confirmation notification. Read back from the
    // DB rather than assembled from the variables in scope: the estimate is
    // anchored on orders.created_at, and the row's own timestamp is the only
    // one in the DB's timezone (see the TIMEZONE note in
    // config/delivery_estimate.php). Best-effort - a failure here must not
    // cost the customer their confirmation notification.
    $etaSentence = '';
    try {
        $etaStmt = $pdo->prepare("SELECT status, delivery_method, payment_method, payment_status,
                                         paid_at, created_at, delivery_full_address,
                                         delivery_latitude, delivery_longitude,
                                         CURDATE() as db_today
                                  FROM orders WHERE id = ?");
        $etaStmt->execute([$orderId]);
        if ($etaRow = $etaStmt->fetch()) {
            $etaSentence = deliveryEstimateSentence(deliveryEstimateForOrder($etaRow));
        }
    } catch (Throwable $e) {
        error_log('Order ETA for notification failed: ' . $e->getMessage());
    }

    // Send order confirmation notification
    sendOrderStatusNotification($userId, $orderId, 'placed', [
        'total' => formatCurrency($total),
        'invoice_no' => $invoiceNo,
        'tracking_code' => $trackingCode,
        'payment_method' => getPaymentMethodLabel($dbPaymentMethod),
        'delivery_method' => $deliveryMethod === 'delivery' ? 'Home Delivery' : 'Store Pickup',
        // Consumed by the order_placed template's {eta} placeholder, and kept
        // in the notification's data JSON so the bell and the notifications
        // page can show it without recomputing.
        'eta' => $etaSentence
    ]);
    
    // Try sending confirmation email (best-effort)
    try {
        $user = getUserData();
        if ($user && !empty($user['email'])) {
            $subject = 'TechWorld Order Confirmation #' . ($invoiceNo ?: str_pad($orderId, 6, '0', STR_PAD_LEFT));
            $body = '<p>Hi ' . htmlspecialchars($fullName) . ',</p>' .
                    '<p>Thanks for your order. Your order number is <strong>' . htmlspecialchars($invoiceNo ?: $orderId) . '</strong>.</p>' .
                    (!empty($trackingCode) ? '<p>Tracking Code: <strong>' . htmlspecialchars($trackingCode) . '</strong></p>' : '') .
                    '<p>Total: <strong>' . formatCurrency($total) . '</strong></p>' .
                    '<p>You can view details at: <a href="' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId . '">Order Details</a></p>';
            sendEmail($user['email'], $fullName, $subject, $body);
        }
    } catch (Throwable $e) { /* ignore email failures */ }

    // Return success response - Xendit orders go to the "Pay with Xendit" page
    // (which creates the hosted checkout session); manual e-payment orders go
    // to the payment instructions page (QR + reference number); everything
    // else goes straight to order_success.php
    if ($isXendit) {
        $redirectUrl = WEB_ROOT . 'public/authenticated/customer/xendit_payment.php?id=' . $orderId;
    } elseif ($isManualEpayment) {
        $redirectUrl = WEB_ROOT . 'public/authenticated/customer/epayment_instructions.php?id=' . $orderId;
    } else {
        $redirectUrl = WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Order placed successfully!',
        'order_id' => $orderId,
        'redirect' => $redirectUrl
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Order processing error: ' . $e->getMessage());

    http_response_code(400);
    echo json_encode([
        'success' => false,
        // Never surface raw DB/exception details to the customer - log them above instead.
        'message' => ($e instanceof PDOException)
            ? 'We could not process your order. Please try again.'
            : $e->getMessage()
    ]);
}
?>
