<?php
declare(strict_types=1);
// TECHWRLD_CRR, not TECHWRLD_ADM: this page opens the session before
// config/functions.php gets a chance to pick the bucket from the URL, so the
// name has to be stated here too or the courier area would start an empty
// admin session and bounce every signed-in rider back to the login.
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CRR'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Admin/super-admin (office staff, full visibility) and driver (Logistics/
// Delivery Personnel - see admin/logistics_login.php for how they sign in)
// can both reach this page. isDriver() gates the sidebar below down to just
// this page for drivers; the query/actions here are otherwise identical for
// every role that can reach it.
// Riders/couriers only. Office staff have their own oversight screen at
// admin/Delivery_Report.php - this page carries the delivery actions
// (start tracking, confirm delivery, record payment) and nothing else may
// reach them. An admin who lands here is redirected there below.
require_role(['driver']);

// Loading the board is itself proof the rider's app is open, so it counts as
// a heartbeat - the JS ping below keeps it alive from there. Then run the
// assignment queue: arriving here is one of the moments that can change who
// is free (a rider who just completed a drop lands back on this page).
if (function_exists('touchDriverHeartbeat') && getUserId()) {
    touchDriverHeartbeat((int)getUserId());
    autoAssignPendingDeliveries();
}
$__dutyOnline = function_exists('driverIsOnline') && getUserId() ? driverIsOnline((int)getUserId()) : false;

// Additional validation to ensure user is properly authenticated
if (!isLoggedIn() || !getUserId()) {
    error_log("User not properly authenticated for admin delivery reports");
    error_log("Session data: " . print_r($_SESSION, true));
    $_SESSION['error_message'] = 'Authentication error. Please log in again.';
    header('Location: ' . WEB_ROOT . 'public/guest/login.php');
    exit;
}

// Debug session information
error_log("Admin session - User ID: " . getUserId() . ", Role: " . getUserRole());

// Courier/rider accounts get the Delivery Portal's own wording; office staff
// reach the same file for oversight and keep the report wording. Only the
// copy differs - the page, the query and the role gates are unchanged.
$pageTitle = 'Courier Dispatch - TechWorld';
$activeNav = 'courier_dispatch';
$isAdminTheme = true;


// Notifies the customer their invoice is ready to view - invoice.php
// already renders live from the orders/payments tables (no separate
// "generation" step), it only needs the order to be 'completed' to unlock;
// if delivery isn't marked complete yet the link gracefully redirects to
// the order confirmation page instead of erroring, so it's safe either way.
// Shared by complete_delivery (online-payment orders - already paid, so the
// invoice is issued the moment delivery is confirmed) and record_payment
// (COD orders - issued once the cash is actually collected).
function issueDeliveryInvoiceNotification(int $orderId, int $userId, string $invoiceNo, ?float $amountTendered = null): void {
    $invoiceUrl = WEB_ROOT . 'public/authenticated/customer/invoice.php?id=' . $orderId;
    $message = 'Your order ' . $invoiceNo . ' has been delivered';
    if ($amountTendered !== null) {
        $message .= ' and your cash payment of ' . formatCurrency($amountTendered) . ' was received';
    }
    $message .= '. Your invoice is ready to view.';
    createNotification(
        $userId,
        'order_status',
        'Invoice Ready - ' . $invoiceNo,
        $message,
        ['order_id' => $orderId, 'invoice_no' => $invoiceNo, 'invoice_url' => $invoiceUrl, 'action' => 'invoice_ready'],
        'high'
    );
}

// A rider takes an unclaimed delivery for themselves. Driver-only.
//
// This is the recovery path for the auto-assigner's dead end: once every rider
// holds an order that was never completed, availableDrivers() is empty forever
// and a shipped order can never be handed out (see the query's unclaimed clause
// and claimDeliveryForDriver()). Rather than leave that work invisible, it is
// offered to whoever takes it - here from the board's "Take this delivery"
// button, and from the QR scanner, which is the stronger signal of the two
// since scanning the label means the rider is physically holding the parcel.
//
// All the rules and the row lock are in claimDeliveryForDriver(); this only
// decides who is asking. It writes assigned_driver_id and nothing else - the
// status still moves through complete_delivery/record_payment below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_delivery') {
    if (!isDriver()) {
        $_SESSION['error_message'] = 'Only Delivery Portal accounts can take a delivery.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        $_SESSION['error_message'] = 'Invalid order.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $claim = claimDeliveryForDriver($orderId, (int)getUserId());
    if ($claim['ok']) {
        $_SESSION['success_message'] = $claim['message'] . ' Start the delivery when you are ready to go.';
    } else {
        $_SESSION['error_message'] = $claim['message'];
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Start live GPS tracking for an assigned delivery. Driver-only, and only
// for an order that's actually assigned to the driver clicking the button -
// re-verified here server-side rather than trusted from the form. This just
// records the first GPS fix synchronously with the button click (nicer UX
// than waiting on the browser's first watchPosition callback) and flips
// is_tracking on; the recurring fixes after this one go straight to
// api/update_driver_location.php from driver-location-sender.js.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_tracking') {
    /* Two callers now, same rules, same handler.

       The board's button posts this as a form and expects the redirect it has
       always got. The scan card posts it with fetch and must NOT be redirected:
       the rider is standing in the scan modal, tracking is step one of three,
       and reloading the page there would close the modal, drop the GPS watch
       this very request just turned on, and make them scan the label again to
       reach Confirm Delivery. So an XHR gets JSON and the page stays put.

       Everything above the response - who may do this, which order, the fix
       itself - is identical for both. */
    $stIsXhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $stDone = function (bool $ok, string $message) use ($stIsXhr) {
        if ($stIsXhr) {
            header('Content-Type: application/json');
            // No session flash for the XHR caller: it would surface as a stale
            // toast on whatever page the rider loads next.
            echo json_encode(['ok' => $ok, 'message' => $message]);
            exit;
        }
        $_SESSION[$ok ? 'success_message' : 'error_message'] = $message;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    };

    if (!isDriver()) {
        $stDone(false, 'Only Delivery Portal accounts can start live tracking.');
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;

    if ($orderId <= 0) {
        $stDone(false, 'Invalid order.');
    }

    try {
        $pdo = getConnection();
        $chk = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND status = 'shipped' AND delivery_method = 'delivery' AND assigned_driver_id = ?");
        $chk->execute([$orderId, getUserId()]);
        if (!$chk->fetchColumn()) {
            throw new Exception('This order is not assigned to you or is no longer active for delivery.');
        }

        if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
            $up = $pdo->prepare("
                INSERT INTO delivery_tracking (order_id, driver_id, latitude, longitude, is_tracking)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id), latitude = VALUES(latitude), longitude = VALUES(longitude), is_tracking = 1
            ");
            $up->execute([$orderId, getUserId(), $lat, $lng]);
        }

        logAudit('delivery_tracking_started', 'orders', $orderId, null, ['driver_id' => getUserId()]);

        /* Tell the customer their rider is live, with a link straight to the
           map. Idempotent inside the helper, so a rider who stops and starts
           again does not notify them twice. */
        notifyCustomerTrackingStarted($pdo, $orderId);
    } catch (Throwable $e) {
        $stDone(false, $e->getMessage());
    }

    $stDone(true, 'Live tracking started.');
}

// Complete a delivery: exactly one action now, not two. The proof photo and
// the "mark as delivered" status change happen in the same request/
// transaction, so there is no way to reach 'completed' without a photo -
// there's no separate button or code path that flips status without one.
//
// This action is driver-only - admin/super-admin can still see everything
// on this page (stats, order list, proof photos already uploaded), but the
// Delivery Portal (driver) is the only role that actually confirms a
// delivery. Enforced here server-side, not just by hiding the button below,
// since a direct POST would otherwise bypass a UI-only restriction.
//
// Chained payment step: if the order is Cash on Delivery and still unpaid,
// completing the delivery here doesn't issue the invoice yet - it flags
// $_SESSION['auto_open_record_payment'] so the reloaded page immediately
// opens the Record Payment modal for this same order (see the page-render
// section below), keeping "confirm delivery -> collect cash" as one
// continuous flow for the driver instead of two separate button hunts.
// Online-payment orders (already paid before delivery) skip straight to
// issuing the invoice, since there's no cash left to collect.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_delivery') {
    if (!isDriver()) {
        $_SESSION['error_message'] = 'Only Delivery Portal accounts can mark an order as delivered.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        $_SESSION['error_message'] = 'Invalid order.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // At least one photo is mandatory - reject up front, before touching the
    // DB at all, if nothing was actually submitted. Multiple photos are
    // allowed (a driver might want the package at the doorstep, the
    // recipient's signature/ID, etc.) - $_FILES['delivery_photos'] is an
    // array-of-arrays here (upload input name is delivery_photos[]).
    $uploadedNames = $_FILES['delivery_photos']['name'] ?? [];
    $hasAnyFile = false;
    foreach ($uploadedNames as $idx => $name) {
        if (($_FILES['delivery_photos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $hasAnyFile = true;
            break;
        }
    }
    if (!$hasAnyFile) {
        $_SESSION['error_message'] = 'At least one delivery proof photo is required. Please take or upload a photo before marking this order as delivered.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    try {
        $pdo = getConnection();
        $pdo->beginTransaction();

        // Verify order exists and is shipped (not already completed) - lock
        // the row so two near-simultaneous submissions for the same order
        // (e.g. a slow double-tap) can't both pass this check.
        $orderStmt = $pdo->prepare("SELECT id, user_id, invoice_no, payment_method, payment_status, total_amount FROM orders WHERE id = ? AND status = 'shipped' AND delivery_method = 'delivery' FOR UPDATE");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            throw new Exception('Order not found or not eligible - it may already be completed.');
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $uploadDir = __DIR__ . '/../../../public/shared/img/delivery_proof/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $photoStmt = $pdo->prepare(
            "INSERT INTO delivery_proof_photos (order_id, photo_path, photo_type, uploaded_by) VALUES (?, ?, 'delivery_proof', ?)"
        );

        $savedCount = 0;
        foreach ($_FILES['delivery_photos']['name'] as $idx => $name) {
            $error = $_FILES['delivery_photos']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue; // empty extra file slot - fine, at least one real file already passed the check above
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new Exception('One of the photo uploads failed. Please try again.');
            }

            $fileExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($fileExtension, $allowedExtensions, true)) {
                throw new Exception('Invalid file type ("' . htmlspecialchars($name) . '"). Only JPG, PNG, and GIF files are allowed.');
            }

            $fileName = 'delivery_' . $orderId . '_' . time() . '_' . $idx . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            if (!move_uploaded_file($_FILES['delivery_photos']['tmp_name'][$idx], $filePath)) {
                throw new Exception('Could not save one of the uploaded photos. Please try again.');
            }
            $relativePath = 'public/shared/img/delivery_proof/' . $fileName;
            $photoStmt->execute([$orderId, $relativePath, getUserId()]);
            $savedCount++;
        }

        // A COD order isn't actually "done" until the cash is collected and
        // recorded - status deliberately stays 'shipped' (not 'completed')
        // for that case, so the order keeps showing as actionable (see the
        // 'record_payment' actionType above) until record_payment below
        // confirms the exact amount and flips it to 'completed' itself.
        // Already-paid orders (online payment, or COD somehow already
        // recorded) have nothing left to collect, so they complete here as
        // before.
        $needsCashCollection = $order['payment_method'] === 'cod' && $order['payment_status'] !== 'paid';

        $updateStmt = $pdo->prepare("
            UPDATE orders
            SET status = ?,
                delivery_completed_at = NOW(),
                delivery_completed_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$needsCashCollection ? 'shipped' : 'completed', getUserId(), $orderId]);

        // Stop live GPS tracking now that delivery is confirmed - the
        // package has physically arrived either way, regardless of whether
        // payment still needs collecting.
        $stopTrackStmt = $pdo->prepare("UPDATE delivery_tracking SET is_tracking = 0 WHERE order_id = ?");
        $stopTrackStmt->execute([$orderId]);

        $pdo->commit();

        $orderNumber = $order['invoice_no'] ?: 'ORD-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
        logAudit('delivery_proof_uploaded', 'delivery_proof_photos', $orderId, null, ['photo_count' => $savedCount]);

        // This rider just became free - hand them (or whoever is next in the
        // round-robin) the oldest order still waiting for one.
        if (function_exists('autoAssignPendingDeliveries')) {
            autoAssignPendingDeliveries();
        }

        $successMsg = 'Delivery confirmed for order ' . $orderNumber . ' - ' . $savedCount . ' proof photo' . ($savedCount === 1 ? '' : 's') . ' saved.';

        if ($needsCashCollection) {
            // Chain straight into Record Payment on reload - see the
            // page-render section below for where this gets consumed. No
            // "delivered" notification yet either - the customer hears
            // "delivered" once record_payment below actually completes the
            // order, not before, so it's never a premature/inaccurate claim.
            $_SESSION['auto_open_record_payment'] = [
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
                'amountDue' => (float)$order['total_amount'],
            ];
            $successMsg .= ' Now record the cash payment collected.';
        } else {
            // Already paid - genuinely complete right now.
            logAudit('order_completed', 'orders', $orderId, null, ['invoice_no' => $orderNumber]);
            $notificationResult = createNotification(
                $order['user_id'],
                'order_status',
                'Order Delivered - ' . $orderNumber,
                'Your order ' . $orderNumber . ' has been successfully delivered and completed. Thank you for your business!',
                [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'action' => 'delivered'
                ],
                'high'
            );
            if (!$notificationResult['success']) {
                error_log("Failed to create completion notification for user #{$order['user_id']}: " . $notificationResult['message']);
            }
            issueDeliveryInvoiceNotification($orderId, (int)$order['user_id'], $orderNumber);
            $successMsg .= ' Invoice issued to the customer.';
        }

        $_SESSION['success_message'] = $successMsg;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Error completing delivery: ' . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Record a Cash on Delivery payment collected by the driver. Independent of
// complete_delivery above - some orders are already 'completed' (delivered)
// with payment_status still 'pending' from before this feature existed, and
// conversely a driver may want to record payment without necessarily having
// the delivery-proof photo workflow open at the same moment. Driver-only,
// same server-side gate as complete_delivery (a direct POST would otherwise
// bypass a UI-only restriction).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!isDriver()) {
        $_SESSION['error_message'] = 'Only Delivery Portal accounts can record a payment.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    $amountInput = trim($_POST['amount_collected'] ?? '');

    if ($orderId <= 0) {
        $_SESSION['error_message'] = 'Invalid order.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    if ($amountInput === '' || !is_numeric($amountInput) || (float)$amountInput < 0) {
        $_SESSION['error_message'] = 'Please enter a valid payment amount.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $amountCollected = round((float)$amountInput, 2);

    try {
        $pdo = getConnection();
        $pdo->beginTransaction();

        $orderStmt = $pdo->prepare("SELECT id, invoice_no, user_id, payment_method, payment_status, total_amount, status, delivery_method FROM orders WHERE id = ? AND delivery_method = 'delivery' FOR UPDATE");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) {
            throw new Exception('Order not found.');
        }
        if ($order['payment_method'] !== 'cod') {
            throw new Exception('Only Cash on Delivery orders can have payment recorded here.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new Exception('This order is already marked as paid.');
        }

        // Exact amount only - no partial payments, no change to give back.
        // A mismatch either way is rejected outright rather than recorded.
        $orderTotal = round((float)$order['total_amount'], 2);
        if (abs($amountCollected - $orderTotal) > 0.001) {
            throw new Exception('Amount collected (' . formatCurrency($amountCollected) . ') must exactly match the order total (' . formatCurrency($orderTotal) . ').');
        }

        // The delivery itself was already confirmed (proof photo, in
        // complete_delivery above) but status was deliberately held at
        // 'shipped' until payment was actually collected - this is the
        // moment the order is genuinely done, so it completes here.
        // Orders already 'completed' (e.g. from before this flow existed,
        // with payment left unrecorded) just get the payment recorded,
        // status untouched.
        $completesNow = $order['status'] === 'shipped';
        $updOrder = $pdo->prepare("UPDATE orders SET payment_status = 'paid', paid_at = NOW()" . ($completesNow ? ", status = 'completed'" : "") . " WHERE id = ?");
        $updOrder->execute([$orderId]);

        // Every order gets a `payments` row at checkout (see createPayment()
        // in config/functions.php), sitting at status='pending' for COD
        // until now - reuse it instead of inserting a duplicate.
        $paymentNotes = json_encode(['amount_tendered' => $amountCollected]);
        $payStmt = $pdo->prepare("SELECT id FROM payments WHERE order_id = ? AND payment_status = 'pending' ORDER BY id DESC LIMIT 1");
        $payStmt->execute([$orderId]);
        $paymentId = $payStmt->fetchColumn();
        if ($paymentId) {
            $updPay = $pdo->prepare("UPDATE payments SET payment_status = 'completed', amount = ?, payment_date = NOW(), processed_by = ?, notes = ? WHERE id = ?");
            $updPay->execute([$orderTotal, getUserId(), $paymentNotes, $paymentId]);
        } else {
            // Shouldn't normally happen (checkout always creates one), but
            // don't block the driver from recording payment if it's missing.
            $insPay = $pdo->prepare("INSERT INTO payments (order_id, user_id, payment_method, payment_type, payment_status, amount, payment_date, processed_by, notes) VALUES (?, ?, 'cod', 'home_delivery', 'completed', ?, NOW(), ?, ?)");
            $insPay->execute([$orderId, $order['user_id'], $orderTotal, getUserId(), $paymentNotes]);
        }

        $pdo->commit();

        logAudit('payment_collected', 'orders', $orderId, null, [
            'order_total' => $orderTotal,
            'amount_tendered' => $amountCollected,
            'invoice_no' => $order['invoice_no'],
        ]);
        if ($completesNow) {
            logAudit('order_completed', 'orders', $orderId, null, ['invoice_no' => $order['invoice_no']]);
        }

        // Cash is now fully accounted for - issue the invoice. This also
        // covers the "Order Delivered" notification the customer didn't get
        // yet - complete_delivery above deliberately skipped it for this
        // COD-unpaid path so "delivered" was never claimed before it was
        // actually true.
        issueDeliveryInvoiceNotification($orderId, (int)$order['user_id'], $order['invoice_no'], $amountCollected);

        $_SESSION['success_message'] = 'Payment of ' . formatCurrency($amountCollected) . ' recorded for order ' . $order['invoice_no'] . '. Invoice issued to the customer.';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Error recording payment: ' . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get shipped home delivery orders
try {
    $pdo = getConnection();

    // Drivers see their own assignments PLUS anything still unclaimed.
    //
    // The unclaimed half is not cosmetic. availableDrivers() only calls a rider
    // free while they hold fewer than DRIVER_MAX_ACTIVE_DELIVERIES (1) orders
    // that are 'shipped' with no delivery_completed_at - so one order that is
    // never completed occupies that slot for good, and once every rider is in
    // that state autoAssignPendingDeliveries() can never assign anything again.
    // Shipped orders then pile up with assigned_driver_id NULL, and with a
    // strict "= :driver_id" filter they appeared on NOBODY's board: invisible
    // work, no way for a rider to pick it up, and no way for the system to
    // recover by itself. Showing them here (actionType 'claim' below) is what
    // makes that recoverable - see claimDeliveryForDriver().
    $isDriverRole = isDriver();
    $driverFilterSql = $isDriverRole
        ? " AND (assigned_driver_id = :driver_id OR (assigned_driver_id IS NULL AND status = 'shipped' AND delivery_completed_at IS NULL)) "
        : "";

    $query = "
        SELECT o2.*, u.firstname, u.lastname, u.email, u.phone,
               (SELECT COUNT(*) FROM delivery_proof_photos WHERE order_id = o2.id) as photo_count,
               (SELECT CONCAT(d.firstname, ' ', d.lastname) FROM users d WHERE d.id = o2.assigned_driver_id) as assigned_driver_name,
               (SELECT is_tracking FROM delivery_tracking WHERE order_id = o2.id) as tracking_is_active,
               -- Best-effort city/region text for the dev GPS simulator's
               -- destination pin only (geocodeApproxFromRegionText() needs
               -- a short 'City, Region' fragment, not a full street
               -- address) - always the customer's current saved address,
               -- deliberately not the delivery_full_address snapshot below.
               COALESCE(
                   (SELECT region FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1),
                   (SELECT region FROM user_addresses WHERE user_id = o2.user_id LIMIT 1)
               ) as delivery_region,
               -- Displayed delivery address/recipient - prefer the snapshot
               -- taken at checkout (o2.delivery_full_address/
               -- delivery_recipient_name/delivery_phone, in o2.* above),
               -- what the customer actually chose for THIS order. Only
               -- orders placed before that snapshot existed (columns NULL)
               -- fall back to the current saved address.
               COALESCE(
                   o2.delivery_full_address,
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1),
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o2.user_id LIMIT 1),
                   u.address
               ) as delivery_address,
               COALESCE(o2.delivery_recipient_name, (SELECT full_name FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1)) as recipient_name,
               COALESCE(o2.delivery_phone, (SELECT phone FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1)) as recipient_phone,
               -- Drop-off pin the customer set at checkout, if any
               -- (DBSQL/add_address_geo_pin.sql). Snapshot first, then the
               -- pin on their saved address for orders placed before those
               -- columns existed. Coordinates beat the address text: a
               -- misspelled street is exactly what this overrides.
               COALESCE(
                   o2.delivery_latitude,
                   (SELECT latitude FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1)
               ) as delivery_latitude,
               COALESCE(
                   o2.delivery_longitude,
                   (SELECT longitude FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1)
               ) as delivery_longitude
        FROM (
            SELECT MAX(id) AS id
            FROM orders
            WHERE delivery_method = 'delivery' AND status IN ('shipped','completed')
            $driverFilterSql
            GROUP BY invoice_no
        ) latest
        JOIN orders o2 ON o2.id = latest.id
        JOIN users u ON u.id = o2.user_id
        ORDER BY o2.created_at DESC
    ";

    $stmt = $pdo->prepare($query);
    if ($isDriverRole) { $stmt->bindValue(':driver_id', getUserId(), PDO::PARAM_INT); }
    $stmt->execute();
    $orders = $stmt->fetchAll();

    // Statistics count only what is actually THIS rider's - deliberately the
    // strict filter, not $driverFilterSql. The list above also shows unclaimed
    // orders so they can be picked up, but those are nobody's deliveries yet,
    // and counting them here would inflate a rider's own "Total Orders" and
    // "Shipped" with work they have not taken.
    $statsFilterSql = $isDriverRole ? " AND assigned_driver_id = :driver_id " : "";
    $statsStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT invoice_no) as total_shipped,
            SUM(CASE WHEN status = 'shipped' AND delivery_method = 'delivery' THEN 1 ELSE 0 END) as shipped,
            SUM(CASE WHEN status = 'completed' AND delivery_method = 'delivery' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'shipped' AND delivery_method = 'delivery' AND delivery_completed_at IS NULL THEN 1 ELSE 0 END) as pending_completion
        FROM (
            SELECT invoice_no, status, delivery_method, delivery_completed_at
            FROM orders
            WHERE delivery_method = 'delivery' AND status IN ('shipped','completed')
            $statsFilterSql
            GROUP BY invoice_no, status, delivery_method, delivery_completed_at
        ) t"
    );
    if ($isDriverRole) { $statsStmt->bindValue(':driver_id', getUserId(), PDO::PARAM_INT); }
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
    // Get proof photo(s) for each order - purely for display/audit now (the
    // single-mandatory-photo model means a 'shipped' order always has 0 and
    // a 'completed' one always has >=1; there's no partial state to track).
    foreach ($orders as &$order) {
        $photoStmt = $pdo->prepare(
            "SELECT photo_path, photo_type, uploaded_at FROM delivery_proof_photos WHERE order_id = ? ORDER BY uploaded_at ASC"
        );
        $photoStmt->execute([$order['id']]);
        $order['photos'] = $photoStmt->fetchAll();

        $itemsStmt = $pdo->prepare("
            SELECT oi.*, p.name as product_name
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$order['id']]);
        $order['items'] = $itemsStmt->fetchAll();
    }
    unset($order);

} catch (Throwable $e) {
    error_log("Error fetching delivery orders: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $_SESSION['error_message'] = 'An error occurred while loading delivery orders. Error: ' . $e->getMessage();
    $orders = [];
    $stats = ['total_shipped' => 0, 'shipped' => 0, 'completed' => 0, 'pending_completion' => 0];
}

// Chained "confirm delivery -> collect cash" flow: complete_delivery set
// this flag for COD orders that still needed payment - consume it once
// here so the Record Payment modal auto-opens for that order below,
// picking up right where the driver left off instead of making them find
// and tap the order again.
$autoOpenRecordPayment = $_SESSION['auto_open_record_payment'] ?? null;
unset($_SESSION['auto_open_record_payment']);

include __DIR__ . '/../admin/includes/header.php';
// Drivers (Logistics/Delivery Personnel) get a minimal nav scoped to just
// this page - they have no access to Product/Customer/Order Management etc,
// so showing them the full admin sidebar would just be a wall of dead links.
include __DIR__ . '/includes/nav.php';
?>

<main class="container" style="padding: 30px 0;">
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:1400px;">

    <!-- Mobile-only greeting header (<=767px) - "Hello, {name}" + avatar,
         same layout family as the food-app mobile mockup this was modeled
         on. Swaps in for the desktop "Delivery Report" title below (see
         .mobile-greeting-header / .dashboard-welcome in the mobile
         stylesheet block near the end of this file); tablet/desktop keep
         the original title untouched. The search box filters the order
         list below live (order #, customer name/email, tracking code) via
         the existing filter/sort script, it doesn't reload the page. -->
    <div class="mobile-greeting-header">
      <div class="mobile-greeting-top">
        <div>
          <span class="mobile-greeting-label">Hello,</span>
          <h2 class="mobile-greeting-name"><?= htmlspecialchars(($_SESSION['firstname'] ?? '') !== '' ? $_SESSION['firstname'] : explode(' ', $__anDisplayName)[0]) ?></h2>
        </div>
        <!-- The account control on phones. The sidebar and its hamburger
             pull-tab are both hidden below 767px (the sidebar's only nav
             link is this very page, so it has nothing to offer here) -
             tapping the avatar opens the small account menu below it
             instead, which is where Logout lives on mobile. A real
             <button> so Enter and Space work without extra key handling;
             includes/nav.php binds the click. -->
        <div class="mobile-greeting-account">
          <button type="button" class="mobile-greeting-avatar" id="mobileGreetingAvatar"
                  aria-label="Open account menu" aria-haspopup="true" aria-expanded="false"
                  aria-controls="mobileAccountMenu">
            <?php if (!empty($__anAvatarRel)): ?>
              <img src="<?= WEB_ROOT ?>public/authenticated/<?= $__anAvatarRel ?>" alt="Profile">
            <?php else: ?>
              <span><?= htmlspecialchars($__anInitials) ?></span>
            <?php endif; ?>
          </button>
          <div class="mobile-account-menu" id="mobileAccountMenu" role="menu" aria-labelledby="mobileGreetingAvatar" hidden>
            <div class="mobile-account-menu-head">
              <strong><?= htmlspecialchars($__anDisplayName) ?></strong>
              <span>Delivery Portal</span>
            </div>
            <a href="<?= WEB_ROOT ?>public/authenticated/courier/logout.php" class="mobile-account-menu-logout" role="menuitem"
               data-confirm="Are you sure you want to log out?" data-confirm-ok="Log out" >
              <i class="fi fi-bs-sign-out"></i> <span>Logout</span>
            </a>
          </div>
        </div>
      </div>
      <div class="mobile-greeting-search">
        <i class="fi fi-rr-search"></i>
        <input type="search" id="deliverySearchInput" placeholder="Search order #, customer&hellip;" aria-label="Search delivery orders">
        <button type="button" id="deliverySearchFilterBtn" aria-label="Jump to filters">
          <i class="fi fi-rr-settings-sliders"></i>
        </button>
      </div>
    </div>

    <!-- Header (desktop/tablet) -->
    <div class="dashboard-welcome" style="text-align:left;">
      <h1 style="display:flex;align-items:center;gap:12px;">
        <i class="fi fi-rr-truck" style="color:var(--accent-cyan);"></i>
        Courier Dispatch
      </h1>
      <p>Your assigned deliveries. Confirm each one with a proof photo as you go.</p>
    </div>

    <!-- Duty switch. Deliveries are assigned by the system now, not picked by
         office staff, and this is the rider's half of that deal: nothing is
         handed out to someone who is off duty. The board keeps a heartbeat
         running while this is on, so closing the app quietly takes the rider
         out of the queue rather than stranding orders against them. -->
    <div class="duty-card" id="dutyCard" data-on-duty="<?= $__dutyOnline ? '1' : '0' ?>">
      <div class="duty-state">
        <span class="duty-dot" aria-hidden="true"></span>
        <span class="duty-text">
          <strong id="dutyLabel"><?= $__dutyOnline ? 'On duty' : 'Off duty' ?></strong>
          <small id="dutyHint"><?= $__dutyOnline
              ? 'You are in the queue for the next delivery.'
              : 'Go on duty to start receiving deliveries.' ?></small>
        </span>
      </div>
      <button type="button" class="duty-toggle" id="dutyToggle"
              role="switch" aria-checked="<?= $__dutyOnline ? 'true' : 'false' ?>">
        <span class="duty-toggle-track"><span class="duty-toggle-knob"></span></span>
        <span class="duty-toggle-label"><?= $__dutyOnline ? 'Go off duty' : 'Go on duty' ?></span>
      </button>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
      <div style="background:rgba(95,168,138,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-success);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-check" style="font-size:16px;color:var(--admin-success);"></i>
          <?= htmlspecialchars($_SESSION['success_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
      <div style="background:rgba(194,104,111,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-danger);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-exclamation-triangle" style="font-size:16px;color:var(--admin-danger);"></i>
          <?= htmlspecialchars($_SESSION['error_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php if (isDriver()): ?>
      <p style="color:var(--admin-muted);font-size:12px;text-align:center;margin:0 0 16px 0;">
        <i class="fi fi-rr-shield-check"></i> Please pull over safely before using GPS tracking controls.
      </p>
    <?php endif; ?>

    <!-- Statistics Cards - grid-template-columns is set responsively by
         .delivery-stats-grid below (2 cols on phones, 4 from tablet up)
         instead of the fixed 4-col inline style this used to have. -->
    <div class="quick-stats delivery-stats-grid" style="margin-bottom:30px;">
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/delivery-total-orders.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['total_shipped'] ?></div>
        <div class="stat-label">Total Orders</div>
      </div>
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/delivery-shipped.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['shipped'] ?></div>
        <div class="stat-label">Shipped</div>
      </div>
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/delivery-completed.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['completed'] ?></div>
        <div class="stat-label">Completed</div>
      </div>
      <div class="stat-card">
        <img class="stat-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/delivery-pending.gif" alt="" aria-hidden="true">
        <div class="stat-number"><?= $stats['pending_completion'] ?></div>
        <div class="stat-label">Pending Completion</div>
      </div>
    </div>

    <?php if ((int)($stats['pending_completion'] ?? 0) > 0): ?>
    <!-- Mobile-only highlight banner (<=767px) - repurposes the mockup's
         orange discount banner as a same-brand-gradient callout for
         whatever actually needs attention here: shipped orders still
         awaiting delivery confirmation. Uses the site's own accent gradient
         (var(--admin-accent) -> var(--admin-primary-2), same as the active
         filter tab and the mobile "View Details" button) rather than an
         unrelated orange, so it reads as part of this app, not a skin. -->
    <div class="mobile-highlight-banner">
      <div class="mobile-highlight-text">
        <strong><?= (int)$stats['pending_completion'] ?> <?= (int)$stats['pending_completion'] === 1 ? 'order' : 'orders' ?></strong>
        <span>waiting on delivery completion</span>
      </div>
      <button type="button" class="mobile-highlight-cta" id="pendingBannerCta">
        View shipped <i class="fi fi-rr-angle-small-right"></i>
      </button>
    </div>
    <?php endif; ?>

    <?php
    // Delivery areas present in this list, for the Area filter.
    //
    // Derived from delivery_address, NOT delivery_region. delivery_region is
    // the customer's *current* saved region, so it is identical for every
    // order that customer ever placed - and it disagrees with the order's own
    // snapshot the moment they deliver somewhere else (order #12 delivers to
    // Caloocan while their saved region says Baguio City). The address
    // snapshot is what this page prints and what the courier drives to, so it
    // is what the filter has to agree with.
    //
    // Takes the last two comma-separated parts, dropping a trailing postal
    // code: "Buklod ng nayon st., #17, Caloocan, Metro Manila, 1400"
    // becomes "Caloocan, Metro Manila". Rough, but it groups by the part of
    // an address a rider actually plans around.
    $__areaOf = function ($addr) {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string)$addr)),
            function ($x) { return $x !== ''; }
        ));
        if (!$parts) { return ''; }
        if (preg_match('/^\d{4}$/', (string)end($parts))) { array_pop($parts); }
        if (count($parts) <= 1) { return $parts ? (string)end($parts) : ''; }
        return implode(', ', array_slice($parts, -2));
    };
    $__areas = [];
    foreach ($orders as $__ao) {
        $__r = $__areaOf($__ao['delivery_address'] ?? '');
        if ($__r !== '' && strtoupper($__r) !== 'N/A') { $__areas[$__r] = true; }
    }
    unset($__ao);
    $__areas = array_keys($__areas);
    sort($__areas, SORT_NATURAL | SORT_FLAG_CASE);
    ?>
    <!-- Courier location. Shows where the rider is now and refreshes on
         demand - it is a check that GPS is working, not the live feed. The
         feed the customer sees starts with "Start Tracking" and is sent by
         driver-location-sender.js. -->
    <div class="admin-table-wrap courier-geo" style="margin-bottom:20px;">
      <div class="courier-geo-head">
        <div>
          <h3>My location</h3>
          <p id="courierGeoStatus">Getting your location&hellip;</p>
        </div>
        <button type="button" id="courierGeoUpdate" class="admin-quick-link" style="border:none;cursor:pointer;margin:0;">
          <i class="fi fi-rr-marker"></i> Update location
        </button>
      </div>
      <div id="courierGeoMap"></div>
      <p class="courier-geo-fix" id="courierGeoAccuracy"></p>
    </div>

    <!-- Orders Table -->
    <div class="admin-table-wrap orders-table">
      <div style="padding:16px 20px;border-bottom:1px solid var(--admin-border);">
        <h3 style="color:var(--admin-text);margin:0 0 12px 0;font-size:18px;">Delivery Orders</h3>

        <!-- Filter (by status) + sort toolbar - client-side, operates on the
             already-rendered <tr> elements below via each row's
             data-status/data-created-ts attributes, so it works identically
             whether a row is showing as a table row (desktop/tablet) or a
             mobile card. Default filter is "All", default sort is "Newest
             first" - the query itself already orders by created_at DESC, so
             the latest order is on top even with JS disabled; this toolbar
             just lets a driver re-sort/narrow it down to find one order
             quickly instead of scrolling the whole list. -->
        <div class="orders-toolbar">
          <!-- Status filter is a single two-state toggle, not a tab strip.
               A driver's board is only ever "what do I still have to
               deliver" (shipped) or "what did I finish" (completed), and
               the first of those is the whole point of the page - so it's
               the default, and the other is one tap away on the same
               control. data-filter always names the status currently being
               shown; the JS below flips it between shipped/completed and
               relabels the button to match. -->
          <div class="orders-filter-tabs">
            <button type="button" id="ordersStatusToggle" class="orders-filter-btn orders-status-toggle active" data-filter="shipped">
              <i class="fi fi-rr-truck" aria-hidden="true"></i>
              <span class="orders-filter-label">Shipped</span>
              <span class="orders-filter-count"></span>
              <span class="orders-toggle-hint" aria-hidden="true">Completed</span>
            </button>
            <!-- Screen readers get the state change announced here rather
                 than from the button's own label, which would re-read the
                 whole control on every tap. -->
            <span id="ordersStatusLive" class="orders-sr-only" role="status" aria-live="polite"></span>
          </div>
          <div class="orders-sort-wrap">
            <label for="ordersDateFilter">When</label>
            <select id="ordersDateFilter" class="admin-form-input">
              <option value="any">Any date</option>
              <option value="today">Today</option>
              <option value="yesterday">Yesterday</option>
              <option value="7">Last 7 days</option>
              <option value="30">Last 30 days</option>
              <option value="on">On a date&hellip;</option>
            </select>
            <input type="date" id="ordersDateOn" class="admin-form-input" style="display:none;" aria-label="Show orders placed on this date">
          </div>
          <?php if (count($__areas) > 1): ?>
            <div class="orders-sort-wrap">
              <label for="ordersAreaFilter">Area</label>
              <select id="ordersAreaFilter" class="admin-form-input">
                <option value="">All areas</option>
                <?php foreach ($__areas as $__a): ?>
                  <option value="<?= htmlspecialchars($__a) ?>"><?= htmlspecialchars($__a) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="orders-sort-wrap">
            <label for="ordersSortSelect">Sort</label>
            <select id="ordersSortSelect" class="admin-form-input">
              <option value="newest">Newest first</option>
              <option value="oldest">Oldest first</option>
            </select>
          </div>
          <button type="button" id="ordersClearFilters" class="orders-clear-filters" hidden>Clear filters</button>
        </div>
      </div>

      <?php if (empty($orders)): ?>
        <div class="admin-table-empty" style="padding:40px;">
          <i class="fi fi-rr-truck" style="font-size:48px;margin-bottom:16px;display:block;"></i>
          <p style="margin:0;font-size:16px;">No delivery orders found.</p>
        </div>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Photos</th>
                <th>Order Date</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <!-- Shown/hidden by the filter toolbar's JS when a filter
                   leaves zero rows visible (e.g. driver has no completed
                   orders yet). -->
              <tr id="ordersFilterEmptyRow" style="display:none;">
                <td class="orders-empty-filter-cell" colspan="8" style="text-align:center;padding:32px;color:var(--admin-muted);">No orders match this filter.</td>
              </tr>
              <?php foreach ($orders as $order): ?>
                <?php
                // Shared per-order values - computed once, used by both the
                // normal columns (desktop/tablet table) and the mobile-only
                // "View Details" button's JSON payload below, so there's one
                // source of truth instead of two copies of this logic.
                $orderNumber = $order['invoice_no'] ?: 'ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT);
                $orderProofUrls = !empty($order['photos']) ? array_map(function ($p) { return WEB_ROOT . $p['photo_path']; }, $order['photos']) : [];
                $orderCompletedAt = !empty($order['delivery_completed_at']) ? date('M j, Y g:i A', strtotime($order['delivery_completed_at'])) : '';
                $orderDateFormatted = date('M j, Y g:i A', strtotime($order['created_at']));
                $statusBadgeMap = [
                    'shipped' => 'admin-badge--pending',
                    'completed' => 'admin-badge--active',
                    'cancelled' => 'admin-badge--danger'
                ];
                $badgeClass = $statusBadgeMap[$order['status']] ?? 'admin-badge--pending';
                if ($order['status'] === 'completed') {
                    $actionType = 'completed';
                } elseif (isDriver() && (int)($order['assigned_driver_id'] ?? 0) === 0) {
                    // Shipped with no rider. The auto-assigner could not place
                    // it (every rider at capacity, or none on duty when it
                    // shipped), so it is offered to whoever takes it first -
                    // see the query's unclaimed clause above.
                    $actionType = 'claim';
                } elseif (isDriver() && (int)($order['assigned_driver_id'] ?? 0) !== (int)getUserId()) {
                    // Defense-in-depth: the order query above already scopes
                    // a driver to only their own assigned orders, so this
                    // shouldn't be reachable in practice - but never render
                    // an actionable button for someone else's delivery.
                    $actionType = 'not_yours';
                } elseif (isDriver() && !empty($order['delivery_completed_at']) && $order['payment_method'] === 'cod' && $order['payment_status'] !== 'paid') {
                    // Photo proof already captured (delivery_completed_at
                    // set) but the driver closed/cancelled Record Payment
                    // before actually confirming the cash - the order is
                    // deliberately still 'shipped', not 'completed' (see
                    // complete_delivery below), so it stays visibly
                    // actionable here instead of silently sitting "done"
                    // with unrecorded cash.
                    $actionType = 'record_payment';
                } elseif (isDriver()) {
                    // Proof capture is available the moment the order is this
                    // rider's - it is no longer gated behind starting tracking.
                    // Starting live tracking is a separate, optional thing
                    // ($canStartTracking below renders it alongside this):
                    // tracking is for the customer's benefit on the way there,
                    // while the proof photo is the step that actually completes
                    // the delivery, and complete_delivery has never required
                    // tracking to have been running. Gating proof behind it
                    // just stood between a rider at the door and the one action
                    // they need.
                    $actionType = 'mark_delivered';
                } else {
                    $actionType = 'awaiting';
                }
                // Payment: only genuine Cash on Delivery orders are ever
                // collectable by a driver here - gcash/paymaya/xendit are
                // online e-payments verified by office staff (see the
                // existing verify_epayment action on Home_Delivery_Orders.php),
                // a separate flow this deliberately does not duplicate.
                $isPaid = $order['payment_status'] === 'paid';
                $canRecordPayment = !$isPaid && $order['payment_method'] === 'cod';
                // Live tracking is now offered alongside the proof action
                // rather than in front of it - only while it would still do
                // something (this rider's order, on the road, not yet
                // delivered, not already streaming).
                $canStartTracking = isDriver()
                    && (int)($order['assigned_driver_id'] ?? 0) === (int)getUserId()
                    && $order['status'] === 'shipped'
                    && empty($order['tracking_is_active'])
                    && empty($order['delivery_completed_at']);
                // Number the Call button dials. The delivery contact the
                // customer gave for THIS order comes first - it is who is
                // actually waiting at the door - with the account phone as the
                // fallback. tel: wants no spaces or punctuation; a leading +
                // is the one thing that must survive.
                $callRaw = trim((string)($order['recipient_phone'] ?? '')) !== ''
                    ? (string)$order['recipient_phone']
                    : (string)($order['phone'] ?? '');
                $callDigits = preg_replace('/[^0-9]/', '', $callRaw);
                $callHref = $callDigits !== ''
                    ? (strpos(trim($callRaw), '+') === 0 ? '+' : '') . $callDigits
                    : '';
                $orderItemsPayload = array_map(function ($item) {
                    return [
                        'name' => $item['product_name'] ?? 'Product',
                        'quantity' => (int)$item['quantity'],
                        'price' => (float)($item['price'] ?? 0),
                    ];
                }, $order['items']);
                // Drop-off address, used by both the route checkbox in the
                // Order # cell and the Navigate link down in Actions. Computed
                // here so it exists before the first of the three (the route checkbox,
                // $orderDetailsPayload below, and the Actions link).
                $navAddress = trim((string)($order['delivery_address'] ?? ''));
                $showNavigate = $order['status'] !== 'completed' && $navAddress !== '' && strtoupper($navAddress) !== 'N/A';
                // When the customer pinned their exact spot, navigate to the
                // coordinates instead of the address text. Google Maps then
                // routes to the point rather than to its own guess at what
                // the street line meant.
                $navLat = $order['delivery_latitude'] ?? null;
                $navLng = $order['delivery_longitude'] ?? null;
                $hasNavPin = is_numeric($navLat) && is_numeric($navLng);
                $navTarget = $hasNavPin ? ((float)$navLat . ',' . (float)$navLng) : $navAddress;
                // A pin makes Navigate worth showing even when the address
                // text is missing or 'N/A'.
                if ($hasNavPin && $order['status'] !== 'completed') { $showNavigate = true; }
                $orderDetailsPayload = [
                    'orderId' => (int)$order['id'],
                    'orderNumber' => $orderNumber,
                    'trackingCode' => $order['tracking_code'] ?? '',
                    'customerName' => trim($order['firstname'] . ' ' . $order['lastname']),
                    'customerEmail' => $order['email'] ?? '',
                    'customerPhone' => $order['phone'] ?? '',
                    'recipientName' => $order['recipient_name'] ?? '',
                    'recipientPhone' => $order['recipient_phone'] ?? '',
                    'deliveryAddress' => $order['delivery_address'] ?? 'N/A',
                    // Kept in step with courier_scan.php's payload on purpose:
                    // the same modal renders both, so a key present in one and
                    // missing from the other shows the note after a scan and
                    // hides it when the rider opens the very same order from
                    // the board. o2.* already carries notes - see the query.
                    'deliveryNotes' => trim((string)($order['notes'] ?? '')),
                    // "lat,lng" when the customer pinned their exact spot,
                    // otherwise ''. The mobile Navigate link below prefers it
                    // over the address text for the same reason the desktop
                    // row does - see $navTarget.
                    'navTarget' => $hasNavPin ? ((float)$navLat . ',' . (float)$navLng) : '',
                    'items' => $orderItemsPayload,
                    'amount' => formatCurrency($order['total_amount']),
                    'amountRaw' => (float)$order['total_amount'],
                    'statusText' => ucfirst($order['status']),
                    'statusBadgeClass' => $badgeClass,
                    'orderDate' => $orderDateFormatted,
                    'proofUrls' => $orderProofUrls,
                    'completedAt' => $orderCompletedAt,
                    'actionType' => $actionType,
                    'isPaid' => $isPaid,
                    'canRecordPayment' => $canRecordPayment && isDriver(),
                    'paymentMethod' => $order['payment_method'],
                    'canStartTracking' => $canStartTracking,
                    // Dialable form + what to show on the button, so the mobile
                    // details modal renders the same Call control as the row.
                    'callHref' => $callHref,
                    'callLabel' => $callRaw,
                ];
                ?>
                <?php
                $orderSearchBlob = strtolower($orderNumber . ' ' . trim($order['firstname'] . ' ' . $order['lastname']) . ' ' . ($order['email'] ?? '') . ' ' . ($order['tracking_code'] ?? ''));
                $orderInitials = strtoupper(substr($order['firstname'] ?? '', 0, 1) . substr($order['lastname'] ?? '', 0, 1)) ?: '#';
                // Encoded once and hung on the <tr>, because two things now
                // open the details modal for this order: the "View Details"
                // button and - on phones, where the row renders as a card -
                // a tap anywhere on the card itself. Both read this one
                // attribute, so there's still a single payload per order.
                $orderDetailsJson = json_encode($orderDetailsPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                ?>
                <tr data-status="<?= htmlspecialchars($order['status']) ?>" data-created-ts="<?= strtotime($order['created_at']) ?>" data-region="<?= htmlspecialchars($__areaOf($order['delivery_address'] ?? '')) ?>" data-search="<?= htmlspecialchars($orderSearchBlob) ?>" data-order='<?= $orderDetailsJson ?>'>
                  <td data-label="Order #">
                    <!-- Mobile-only avatar chip (see .order-card-top in the
                         mobile stylesheet block) - sits top-left of the card,
                         mirrors the Status badge which the mobile CSS moves
                         to top-right, so the two form the mockup's
                         image+rating-pill header row. Desktop/tablet never
                         see this (display:none by default). -->
                    <div class="order-card-top"><span class="order-card-avatar"><?= htmlspecialchars($orderInitials) ?></span></div>
                    <div style="font-weight:600;overflow-wrap:anywhere;"><?= htmlspecialchars($orderNumber) ?></div>
                    <?php if (!empty($order['tracking_code'])): ?>
                      <div style="font-size:12px;color:var(--admin-muted);overflow-wrap:anywhere;">Tracking: <?= htmlspecialchars($order['tracking_code']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td data-label="Customer">
                    <!-- Name and the Call button sit on one row: at a doorstep
                         "nobody is answering" is the commonest problem a rider
                         has, and it should never cost them a trip back to the
                         customer record to find a number. -->
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                      <span style="font-weight:600;overflow-wrap:anywhere;"><?= htmlspecialchars($order['firstname'] . ' ' . $order['lastname']) ?></span>
                      <?php if ($callHref !== ''): ?>
                        <a class="courier-call-btn" href="tel:<?= htmlspecialchars($callHref) ?>"
                           title="Call <?= htmlspecialchars($callRaw) ?>"
                           aria-label="Call <?= htmlspecialchars(trim($order['firstname'] . ' ' . $order['lastname'])) ?> on <?= htmlspecialchars($callRaw) ?>">
                          <i class="fi fi-rr-phone-call" aria-hidden="true"></i><span>Call</span>
                        </a>
                      <?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:var(--admin-muted);overflow-wrap:anywhere;"><?= htmlspecialchars($order['email']) ?></div>
                    <?php if ($callRaw !== ''): ?>
                      <div style="font-size:12px;color:var(--admin-muted);overflow-wrap:anywhere;"><?= htmlspecialchars($callRaw) ?></div>
                    <?php endif; ?>
                  </td>
                  <td data-label="Amount" style="font-weight:600;">
                    <?= $orderDetailsPayload['amount'] ?>
                  </td>
                  <td data-label="Status">
                    <span class="admin-badge <?= $badgeClass ?>" style="text-transform:uppercase;">
                      <?= ucfirst($order['status']) ?>
                    </span>
                  </td>
                  <td data-label="Payment">
                    <?php if ($isPaid): ?>
                      <span class="admin-badge admin-badge--active" style="text-transform:uppercase;">Paid</span>
                    <?php elseif ($order['payment_method'] !== 'cod'): ?>
                      <span class="admin-badge admin-badge--pending" style="text-transform:uppercase;">Unpaid</span>
                      <div style="font-size:11px;color:var(--admin-muted);margin-top:4px;">Verify in Admin panel</div>
                    <?php else: ?>
                      <span class="admin-badge admin-badge--danger" style="text-transform:uppercase;">Unpaid</span>
                      <?php if (isDriver() && empty($order['delivery_completed_at'])): ?>
                        <!-- Not delivered yet - payment gets collected as
                             part of the "Mark as Delivered" flow (Confirm
                             Delivery -> chains straight into Record Payment).
                             Once delivered, the Actions column's own "Record
                             Payment" button (actionType === 'record_payment')
                             is the real trigger - see there instead of here. -->
                        <div style="font-size:11px;color:var(--admin-muted);margin-top:4px;">Collected on delivery</div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td data-label="Proof">
                    <?php if (!empty($orderProofUrls)): ?>
                      <button type="button" onclick='openProofPhoto(<?= json_encode($orderProofUrls, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($orderNumber, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($orderCompletedAt, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                              style="background:none;border:none;padding:0;color:var(--admin-success);font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-size:inherit;font-family:inherit;min-height:44px;">
                        <i class="fi fi-rr-check"></i> View proof<?= count($orderProofUrls) > 1 ? ' (' . count($orderProofUrls) . ')' : '' ?>
                      </button>
                    <?php else: ?>
                      <span style="color:var(--admin-muted);">Not yet delivered</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Order Date" style="color:var(--admin-muted);font-size:14px;">
                    <?= $orderDateFormatted ?>
                  </td>
                  <td data-label="Actions" style="text-align:center;">
                    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                      <?php if ($actionType === 'completed'): ?>
                        <button disabled style="background:var(--admin-surface);color:var(--admin-muted);padding:6px 12px;border:none;border-radius:var(--radius-sm);cursor:not-allowed;font-size:12px;font-weight:600;">
                          <i class="fi fi-rr-check" style="margin-right:4px;"></i>
                          Completed
                        </button>
                      <?php elseif ($actionType === 'claim'): ?>
                        <!-- Unclaimed: the auto-assigner could not place this
                             one. A real form POST (not fetch) so it behaves
                             like every other action on this board - submit,
                             redirect, reload with the result banner. -->
                        <form method="POST" style="display:inline;" data-confirm="Take delivery <?= htmlspecialchars($orderNumber) ?>? It will be assigned to you." data-confirm-ok="Take it">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="claim_delivery">
                          <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                          <button type="submit" class="admin-quick-link admin-quick-link--accent" style="margin:0;padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
                            <i class="fi fi-rr-hand" style="margin-right:4px;"></i>
                            Take this delivery
                          </button>
                        </form>
                      <?php elseif ($actionType === 'mark_delivered'): ?>
                        <!-- Proof capture is the primary action from the moment
                             the order is this rider's - see $actionType above.
                             Live tracking follows it as an optional extra
                             rather than standing in front of it. -->
                        <button onclick='openDeliveryModal(<?= json_encode($orderDetailsPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                class="admin-quick-link" style="margin:0;padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
                          <i class="fi fi-rr-camera" style="margin-right:4px;"></i>
                          Mark as Delivered
                        </button>
                        <?php if ($canStartTracking): ?>
                          <button onclick="startDeliveryTracking(<?= $order['id'] ?>, '<?= htmlspecialchars($orderNumber) ?>')"
                                  class="courier-secondary-btn" title="Share your live location with the customer while you drive">
                            <i class="fi fi-rr-navigation"></i>
                            Start Tracking
                          </button>
                        <?php endif; ?>
                      <?php elseif ($actionType === 'record_payment'): ?>
                        <!-- Photo proof already captured - the order stays
                             actionable (not "Completed") until this cash
                             payment is actually recorded, whether this is
                             the first time seeing this or the driver closed
                             the auto-opened modal earlier and is coming
                             back to it. -->
                        <button onclick="openRecordPaymentModal(<?= $order['id'] ?>, '<?= htmlspecialchars($orderNumber) ?>', <?= json_encode((float)$order['total_amount']) ?>)"
                                class="admin-quick-link admin-quick-link--accent" style="margin:0;padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
                          <i class="fi fi-rr-wallet" style="margin-right:4px;"></i>
                          Record Payment
                        </button>
                      <?php elseif ($actionType === 'not_yours'): ?>
                        <span style="color:var(--admin-muted);font-size:12px;font-style:italic;">Assigned to another driver</span>
                      <?php else: ?>
                        <!-- Admin/super-admin: view-only here. Confirming a
                             delivery is the Delivery Portal's job now, not
                             office staff's - see the server-side isDriver()
                             gate on the complete_delivery POST handler above. -->
                        <span style="color:var(--admin-muted);font-size:12px;font-style:italic;">Awaiting driver</span>
                        <?php /* The dev GPS simulator deliberately does not live on
                                 this page. dispatch.php is require_role(['driver']),
                                 so the admin gate this block used could never be
                                 true here - it was unreachable markup. It belongs
                                 on admin/Delivery_Report.php, which admins can
                                 actually reach, and the real Start Tracking button
                                 now works on a localhost machine anyway (see the
                                 first-fix grace window in
                                 shared/assets/js/driver-location-sender.js). */ ?>
                      <?php endif; ?>
                      <?php if ($showNavigate): ?>
                        <a class="courier-nav-btn"
                           href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= rawurlencode($navTarget) ?>"
                           target="_blank" rel="noopener noreferrer"
                           title="Open directions to <?= htmlspecialchars($navAddress) ?>">
                          <i class="fi fi-rr-marker"></i> Navigate
                        </a>
                      <?php endif; ?>
                    </div>
                  </td>
                  <!-- Mobile/tablet-only column (<=767px) - desktop never
                       shows this (see .orders-view-details-cell default
                       display:none below), so it adds no extra column to
                       the desktop table. On phones, the card collapses to
                       just Order #/Amount/Status and this single button,
                       which opens #orderDetailsModal with everything else
                       (customer, proof, date, action) - see confirmed plan:
                       mobile/tablet cards only, desktop table unchanged. -->
                  <td class="orders-view-details-cell" data-label="">
                    <button type="button" onclick="openOrderDetailsFromRow(this)">
                      <i class="fi fi-rr-menu-dots"></i> View Details
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pager. Every order is rendered server-side (the filter/sort
             toolbar above needs them all in the DOM to count and search);
             this just windows them to ORDERS_PAGE_SIZE at a time so a
             driver with 80 completed deliveries doesn't get an 80-card
             scroll. Built entirely by the JS below - with JS off the whole
             list simply shows, which is the correct fallback. -->
        <nav class="orders-pagination" id="ordersPagination" aria-label="Delivery orders pages" hidden>
          <p class="orders-pagination-range" id="ordersPaginationRange"></p>
          <div class="orders-pagination-pages" id="ordersPaginationPages"></div>
        </nav>
      <?php endif; ?>
    </div>

  </div>
</main>

<!-- Proof Photo Viewer Modal - shared by both roles ("View proof" link
     above), just an image viewer, no upload. Opens in-page instead of a new
     tab/window. -->
<div id="proofPhotoModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,45,77,0.72);z-index:9999;backdrop-filter:blur(4px);">
  <div class="modal-container" style="position:relative;max-width:700px;margin:4% auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;">
    <div class="modal-header" style="padding:20px 28px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h2 style="color:var(--admin-text);font-size:20px;font-weight:600;margin:0;">
          <i class="fi fi-rr-check" style="margin-right:10px;color:var(--admin-success);"></i>
          Delivery Proof - Order #<span id="proofPhotoOrderNumber"></span>
        </h2>
        <p id="proofPhotoCompletedAtWrap" style="display:none;color:var(--admin-muted);font-size:13px;margin:6px 0 0 30px;">
          <i class="fi fi-rr-clock" style="margin-right:6px;"></i>
          Marked delivered on <span id="proofPhotoCompletedAt" style="font-weight:600;color:var(--admin-text);"></span>
        </p>
      </div>
      <button type="button" onclick="closeProofPhotoModal()" style="background:transparent;border:none;color:var(--admin-muted);font-size:24px;cursor:pointer;padding:8px;border-radius:var(--radius-sm);transition:all 0.2s ease;">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>
    <!-- position/width/height/background/etc explicitly reset here - a
         same-named, unscoped .modal-content rule in shared/style.css
         (written for the login/register modal) otherwise takes over: it
         sets position:absolute + max-width:450px + height:fit-content,
         which pulls this out of the header/content/footer flow entirely
         and shrinks it to a small floating box instead of a normal flex
         child. See the identical fix on the other .modal-content divs in
         this file. -->
    <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;width:100%;max-width:none;height:auto;padding:24px;max-height:75vh;overflow-y:auto;">
      <div id="proofPhotoGrid" style="display:flex;flex-wrap:wrap;gap:14px;justify-content:center;"></div>
    </div>
  </div>
</div>

<script>
// Shared by both roles (unlike the delivery-completion modal below, which
// is driver-only) - "View proof" just displays the already-uploaded
// photo(s) for that order (there can be more than one now), nothing to
// submit. `urls` is an array even when there's only one photo.
function openProofPhoto(urls, orderNumber, completedAt) {
  const grid = document.getElementById('proofPhotoGrid');
  grid.innerHTML = '';
  urls.forEach(function (url) {
    const img = document.createElement('img');
    img.src = url;
    img.alt = 'Delivery proof photo';
    img.style.cssText = 'max-width:100%;max-height:60vh;border-radius:var(--radius-sm);box-shadow:var(--neu-raised-sm);';
    grid.appendChild(img);
  });
  document.getElementById('proofPhotoOrderNumber').textContent = orderNumber;
  const completedAtWrap = document.getElementById('proofPhotoCompletedAtWrap');
  if (completedAt) {
    document.getElementById('proofPhotoCompletedAt').textContent = completedAt;
    completedAtWrap.style.display = 'block';
  } else {
    completedAtWrap.style.display = 'none';
  }
  const modal = document.getElementById('proofPhotoModal');
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function closeProofPhotoModal() {
  const modal = document.getElementById('proofPhotoModal');
  modal.style.display = 'none';
  document.body.style.overflow = 'auto';
  document.getElementById('proofPhotoGrid').innerHTML = '';
}
document.addEventListener('click', function (event) {
  const modal = document.getElementById('proofPhotoModal');
  if (event.target === modal) { closeProofPhotoModal(); }
});
document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
    const modal = document.getElementById('proofPhotoModal');
    if (modal.style.display === 'block') { closeProofPhotoModal(); }
  }
});
</script>

<script>
// Delivery Orders filter (by status) + sort toolbar - client-side, operates
// directly on the already-rendered <tr data-status data-created-ts> rows
// (see the PHP loop above), so it works identically for the desktop table
// and the mobile card layout without a page reload or a second data source.
(function () {
  // How many orders are on screen at once - see .orders-pagination below
  // the table. Changing this number is the only thing needed to resize a
  // page; the pager, the range readout and the clamping all derive from it.
  var ORDERS_PAGE_SIZE = 10;

  // The status toggle is two-state, and "shipped" - the work still to do -
  // is the board's whole reason to exist, so it's what loads.
  var STATUS_STATES = {
    shipped:   { label: 'Shipped',   icon: 'fi-rr-truck', next: 'completed' },
    completed: { label: 'Completed', icon: 'fi-rr-check', next: 'shipped' }
  };

  var currentFilter = 'shipped';
  var currentPage = 1;
  var currentSort = 'newest';
  var currentSearch = ''; // fed by the mobile greeting header's search box
  var currentDate = 'any'; // any | today | yesterday | 7 | 30 | on
  var currentDateOn = ''; // yyyy-mm-dd, only used when currentDate === 'on'
  var currentArea = '';   // '' = every area

  // Midnight today, in the browser's own timezone - the row timestamps are
  // unix seconds from PHP, and both sides agree on the wall clock.
  function startOfToday() {
    var d = new Date();
    d.setHours(0, 0, 0, 0);
    return Math.floor(d.getTime() / 1000);
  }

  function matchesDate(row) {
    if (currentDate === 'any') { return true; }
    var ts = parseInt(row.getAttribute('data-created-ts'), 10) || 0;
    if (!ts) { return false; }
    var day = 86400, today = startOfToday();

    if (currentDate === 'today') { return ts >= today; }
    if (currentDate === 'yesterday') { return ts >= today - day && ts < today; }
    if (currentDate === 'on') {
      if (!currentDateOn) { return true; }
      var picked = new Date(currentDateOn + 'T00:00:00');
      if (isNaN(picked)) { return true; }
      var from = Math.floor(picked.getTime() / 1000);
      return ts >= from && ts < from + day;
    }
    var days = parseInt(currentDate, 10);
    if (!days) { return true; }
    // "Last 7 days" includes today, so it reaches back 6 full days plus today.
    return ts >= today - (days - 1) * day;
  }

  function getOrderRows() {
    var tbody = document.querySelector('.orders-table tbody');
    return tbody ? Array.from(tbody.querySelectorAll('tr[data-status]')) : [];
  }

  // Repaints the single status toggle to whatever currentFilter now names,
  // and shows the count for the state it would switch to so the driver can
  // see there's something worth switching for before tapping.
  function updateStatusToggle(rows) {
    var btn = document.getElementById('ordersStatusToggle');
    if (!btn) { return; }
    var state = STATUS_STATES[currentFilter] || STATUS_STATES.shipped;
    var other = STATUS_STATES[state.next];

    var counts = { shipped: 0, completed: 0 };
    rows.forEach(function (row) {
      var status = row.getAttribute('data-status');
      if (counts.hasOwnProperty(status)) { counts[status]++; }
    });

    btn.setAttribute('data-filter', currentFilter);
    var icon = btn.querySelector('i');
    if (icon) { icon.className = 'fi ' + state.icon; }
    var label = btn.querySelector('.orders-filter-label');
    if (label) { label.textContent = state.label; }
    var countEl = btn.querySelector('.orders-filter-count');
    if (countEl) { countEl.textContent = '(' + counts[currentFilter] + ')'; }
    var hint = btn.querySelector('.orders-toggle-hint');
    if (hint) { hint.textContent = other.label + ' (' + counts[state.next] + ')'; }
    btn.setAttribute('aria-label',
      'Showing ' + state.label.toLowerCase() + ' orders (' + counts[currentFilter] + '). ' +
      'Activate to show ' + other.label.toLowerCase() + ' orders (' + counts[state.next] + ').');
  }

  // Page buttons: first and last are always reachable, plus a window around
  // the current page, with an ellipsis standing in for the rest. Keeps the
  // pager one line wide on a phone no matter how many pages there are.
  function pageNumbersFor(total, current) {
    if (total <= 7) {
      var all = [];
      for (var i = 1; i <= total; i++) { all.push(i); }
      return all;
    }
    var pages = [1];
    var from = Math.max(2, current - 1);
    var to = Math.min(total - 1, current + 1);
    if (from > 2) { pages.push('gap'); }
    for (var p = from; p <= to; p++) { pages.push(p); }
    if (to < total - 1) { pages.push('gap'); }
    pages.push(total);
    return pages;
  }

  function renderPagination(totalPages, matchCount) {
    var nav = document.getElementById('ordersPagination');
    var pagesWrap = document.getElementById('ordersPaginationPages');
    var range = document.getElementById('ordersPaginationRange');
    if (!nav || !pagesWrap) { return; }

    // One page of results needs no pager, and no results has the empty row
    // above saying so already.
    nav.hidden = matchCount <= ORDERS_PAGE_SIZE;
    if (nav.hidden) { pagesWrap.innerHTML = ''; return; }

    var first = (currentPage - 1) * ORDERS_PAGE_SIZE + 1;
    var last = Math.min(currentPage * ORDERS_PAGE_SIZE, matchCount);
    if (range) {
      range.textContent = 'Showing ' + first + '-' + last + ' of ' + matchCount + ' orders';
    }

    pagesWrap.innerHTML = '';

    function addButton(text, page, opts) {
      opts = opts || {};
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'orders-page-btn' + (opts.current ? ' current' : '') + (opts.arrow ? ' arrow' : '');
      b.textContent = text;
      if (opts.current) { b.setAttribute('aria-current', 'page'); }
      if (opts.label) { b.setAttribute('aria-label', opts.label); }
      if (opts.disabled) { b.disabled = true; }
      b.addEventListener('click', function () { goToPage(page); });
      pagesWrap.appendChild(b);
    }

    addButton('‹', currentPage - 1, {
      arrow: true, label: 'Previous page', disabled: currentPage === 1
    });

    pageNumbersFor(totalPages, currentPage).forEach(function (p) {
      if (p === 'gap') {
        var span = document.createElement('span');
        span.className = 'orders-page-gap';
        span.setAttribute('aria-hidden', 'true');
        span.textContent = '…';
        pagesWrap.appendChild(span);
        return;
      }
      addButton(String(p), p, { current: p === currentPage, label: 'Page ' + p });
    });

    addButton('›', currentPage + 1, {
      arrow: true, label: 'Next page', disabled: currentPage === totalPages
    });
  }

  function goToPage(page) {
    currentPage = page;
    applyOrdersFilterSort();
    // Land the driver at the top of the list, not wherever the old page's
    // last card happened to leave the scroll position.
    var table = document.querySelector('.orders-table');
    if (table) { table.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }

  function applyOrdersFilterSort() {
    var tbody = document.querySelector('.orders-table tbody');
    var rows = getOrderRows();
    if (!tbody || rows.length === 0) return;

    // Sort first so "page 1" means the first ten in the driver's chosen
    // order, not the first ten in DOM order that happen to match.
    rows.sort(function (a, b) {
      var ta = parseInt(a.getAttribute('data-created-ts'), 10) || 0;
      var tb = parseInt(b.getAttribute('data-created-ts'), 10) || 0;
      return currentSort === 'newest' ? (tb - ta) : (ta - tb);
    });
    rows.forEach(function (row) { tbody.appendChild(row); });

    var matched = rows.filter(function (row) {
      var statusMatch = (row.getAttribute('data-status') === currentFilter);
      var searchMatch = currentSearch === '' || (row.getAttribute('data-search') || '').indexOf(currentSearch) !== -1;
      var areaMatch = currentArea === '' || (row.getAttribute('data-region') || '') === currentArea;
      return statusMatch && searchMatch && areaMatch && matchesDate(row);
    });

    // A filter change can leave the driver on a page that no longer exists
    // (was on page 4 of Completed, switched to 12 Shipped) - clamp instead
    // of showing a blank list.
    var totalPages = Math.max(1, Math.ceil(matched.length / ORDERS_PAGE_SIZE));
    if (currentPage > totalPages) { currentPage = totalPages; }
    if (currentPage < 1) { currentPage = 1; }
    var start = (currentPage - 1) * ORDERS_PAGE_SIZE;

    rows.forEach(function (row) { row.style.display = 'none'; });
    matched.slice(start, start + ORDERS_PAGE_SIZE).forEach(function (row) { row.style.display = ''; });

    var emptyRow = document.getElementById('ordersFilterEmptyRow');
    if (emptyRow) {
      emptyRow.style.display = matched.length === 0 ? '' : 'none';
      // Name the status, so "nothing here" reads as "you're all caught up"
      // rather than as a filter the driver has to go hunt for and undo.
      var emptyCell = emptyRow.querySelector('.orders-empty-filter-cell');
      if (emptyCell) {
        emptyCell.textContent = 'No ' + (STATUS_STATES[currentFilter] || STATUS_STATES.shipped).label.toLowerCase() +
          ' orders match this filter.';
      }
    }

    updateStatusToggle(rows);
    renderPagination(totalPages, matched.length);

    var clearBtn = document.getElementById('ordersClearFilters');
    if (clearBtn) {
      // The status toggle isn't a "filter" to clear - it's the board's mode,
      // and it always has to be one of the two - so it's left out of this.
      clearBtn.hidden = (currentDate === 'any' && currentArea === '' && currentSearch === '');
    }
  }

  // Every control other than the pager itself invalidates the current page
  // number, so they all go through here instead of calling apply() directly.
  function applyFromFirstPage() {
    currentPage = 1;
    applyOrdersFilterSort();
  }

  // Switches the board to a given status. Exposed to the banner CTA below
  // so it doesn't have to synthesise a click on a button whose meaning now
  // depends on what's already showing.
  function setStatusFilter(status) {
    if (!STATUS_STATES[status] || status === currentFilter) { return; }
    currentFilter = status;
    applyFromFirstPage();
  }

  // First pass runs now rather than on DOMContentLoaded. This <script> sits
  // below the whole table, so every row it touches is already parsed - and
  // waiting would let the browser paint all N orders of both statuses for a
  // frame before the default Shipped/first-10 view replaced them. Listener
  // wiring still waits, since nothing can be clicked before then anyway.
  applyOrdersFilterSort();

  document.addEventListener('DOMContentLoaded', function () {
    var statusToggle = document.getElementById('ordersStatusToggle');
    if (statusToggle) {
      statusToggle.addEventListener('click', function () {
        var state = STATUS_STATES[currentFilter] || STATUS_STATES.shipped;
        setStatusFilter(state.next);
        var live = document.getElementById('ordersStatusLive');
        if (live) {
          live.textContent = 'Now showing ' + STATUS_STATES[currentFilter].label.toLowerCase() + ' orders.';
        }
      });
    }

    var dateSelect = document.getElementById('ordersDateFilter');
    var dateOn = document.getElementById('ordersDateOn');
    if (dateSelect) {
      dateSelect.addEventListener('change', function () {
        currentDate = dateSelect.value;
        // The date box only appears for "On a date…", so the toolbar stays
        // short until a specific day is actually wanted.
        if (dateOn) { dateOn.style.display = currentDate === 'on' ? '' : 'none'; }
        applyFromFirstPage();
      });
    }
    if (dateOn) {
      dateOn.addEventListener('change', function () {
        currentDateOn = dateOn.value;
        applyFromFirstPage();
      });
    }

    var areaSelect = document.getElementById('ordersAreaFilter');
    if (areaSelect) {
      areaSelect.addEventListener('change', function () {
        currentArea = areaSelect.value;
        applyFromFirstPage();
      });
    }

    var clearFilters = document.getElementById('ordersClearFilters');
    if (clearFilters) {
      clearFilters.addEventListener('click', function () {
        currentDate = 'any'; currentDateOn = ''; currentArea = ''; currentSearch = '';
        if (dateSelect) { dateSelect.value = 'any'; }
        if (dateOn) { dateOn.value = ''; dateOn.style.display = 'none'; }
        if (areaSelect) { areaSelect.value = ''; }
        var searchBox = document.getElementById('deliverySearchInput');
        if (searchBox) { searchBox.value = ''; }
        applyFromFirstPage();
      });
    }

    var sortSelect = document.getElementById('ordersSortSelect');
    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        currentSort = sortSelect.value;
        applyFromFirstPage();
      });
    }

    // Mobile greeting header's search box - live-filters the same rows the
    // status toggle above operates on, no page reload.
    var searchInput = document.getElementById('deliverySearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        currentSearch = searchInput.value.trim().toLowerCase();
        applyFromFirstPage();
      });
    }

    // Search box's trailing icon just brings the existing filter/sort
    // toolbar into view - it doesn't hide anything, so there's no extra
    // state to keep in sync with the toolbar's own click handlers above.
    var filterJumpBtn = document.getElementById('deliverySearchFilterBtn');
    if (filterJumpBtn) {
      filterJumpBtn.addEventListener('click', function () {
        var toolbar = document.querySelector('.orders-toolbar');
        if (toolbar) { toolbar.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      });
    }

    // "Pending completion" highlight banner's CTA - switches the board to
    // Shipped. It sets the state directly rather than clicking the toggle:
    // the toggle now *flips* rather than selects, so a synthetic click would
    // send an already-Shipped board to Completed - the opposite of the CTA.
    var pendingCta = document.getElementById('pendingBannerCta');
    if (pendingCta) {
      pendingCta.addEventListener('click', function () {
        setStatusFilter('shipped');
        var table = document.querySelector('.orders-table');
        if (table) { table.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    }

    applyOrdersFilterSort();
  });
})();
</script>

<!-- Order Details Modal - mobile/tablet only (<=767px, see the
     .orders-view-details-cell button in the table loop above and the
     matching CSS in the mobile stylesheet block). Shared by both roles,
     same as the proof modal above: it just displays data already rendered
     into the table row's hidden columns, plus routes its "View Proof" and
     "Mark as Delivered" buttons to the exact same openProofPhoto()/
     openDeliveryModal() already used by the desktop table - no duplicated
     photo-viewing or delivery-confirmation logic. -->
<div id="orderDetailsModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,45,77,0.72);z-index:9999;backdrop-filter:blur(4px);">
  <div class="modal-container" style="position:relative;max-width:480px;margin:6% auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;">
    <div class="modal-header" style="padding:20px 24px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:flex-start;">
      <div>
        <h2 style="color:var(--admin-text);font-size:18px;font-weight:600;margin:0;overflow-wrap:anywhere;">
          Order #<span id="odOrderNumber"></span>
        </h2>
        <div id="odTrackingWrap" style="display:none;font-size:12px;color:var(--admin-muted);margin-top:4px;overflow-wrap:anywhere;">
          Tracking: <span id="odTracking"></span>
        </div>
      </div>
      <button type="button" onclick="closeOrderDetailsModal()" style="background:transparent;border:none;color:var(--admin-muted);font-size:24px;cursor:pointer;padding:8px;border-radius:var(--radius-sm);flex-shrink:0;">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>
    <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;width:100%;max-width:none;height:auto;max-height:none;padding:20px 24px;">

      <div style="margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--admin-muted);margin-bottom:4px;">Customer</div>
        <!-- Name + Call on one row, same as the desktop table cell. This is the
             phone-sized view of the board, so it is the one most likely to be
             open when a rider actually needs to ring the door. -->
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span id="odCustomerName" style="font-weight:600;color:var(--admin-text);overflow-wrap:anywhere;"></span>
          <a id="odCallBtn" class="courier-call-btn" href="#" hidden>
            <i class="fi fi-rr-phone-call" aria-hidden="true"></i><span>Call</span>
          </a>
        </div>
        <div id="odCustomerEmail" style="font-size:13px;color:var(--admin-muted);overflow-wrap:anywhere;"></div>
        <div id="odCustomerPhone" style="font-size:13px;color:var(--admin-muted);overflow-wrap:anywhere;"></div>
      </div>

      <div class="od-two-col" style="display:flex;gap:16px;margin-bottom:16px;">
        <div style="flex:1;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--admin-muted);margin-bottom:4px;">Amount</div>
          <div id="odAmount" style="font-weight:600;color:var(--admin-text);"></div>
        </div>
        <div style="flex:1;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--admin-muted);margin-bottom:4px;">Status</div>
          <span id="odStatusBadge" class="admin-badge" style="text-transform:uppercase;"></span>
        </div>
      </div>

      <div style="margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--admin-muted);margin-bottom:4px;">Order Date</div>
        <div id="odOrderDate" style="color:var(--admin-text);"></div>
      </div>

      <div style="margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--admin-muted);margin-bottom:4px;">Payment</div>
        <div id="odPaymentWrap"></div>
      </div>

      <div style="margin-bottom:20px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--admin-muted);margin-bottom:4px;">Proof</div>
        <div id="odProofWrap"></div>
      </div>

      <div id="odActionWrap"></div>

    </div>
  </div>
</div>

<script>
// Populates and opens #orderDetailsModal from the JSON payload each mobile
// "View Details" button carries (built server-side per order - see the
// $orderDetailsPayload array in the table loop above). Pure presentation:
// no separate data fetch, and the Proof/Action buttons it builds just call
// straight into the same openProofPhoto()/openDeliveryModal() functions the
// desktop table's own buttons already use.
/* On phones (<=767px) each <tr> renders as a self-contained order card (see
   the .orders-table tbody tr rules in the stylesheet block below), so the
   whole card should behave like the "View Details" button sitting inside it -
   tapping a card is the obvious gesture and hunting for the button at the
   bottom of it is not. Delegated from tbody so paginated/filtered rows are
   covered without rebinding, and strictly mobile-only: at desktop widths the
   row is a normal table row again and must not swallow clicks. */
(function () {
  var tbody = document.querySelector('.orders-table tbody');
  if (!tbody) { return; }
  var mq = window.matchMedia('(max-width: 767px)');

  // A card is only a control while it looks like one. Marking it up front
  // (rather than at click time) is what gets it into the tab order and
  // announced as a button; it's reversed as soon as the table goes back to
  // being a table.
  function syncRoles() {
    var rows = tbody.querySelectorAll('tr[data-order]');
    for (var i = 0; i < rows.length; i++) {
      if (mq.matches) {
        rows[i].classList.add('order-card-tappable');
        rows[i].setAttribute('role', 'button');
        rows[i].setAttribute('tabindex', '0');
        // The first div in the Order # cell is the mobile avatar chip; the
        // order number is the one after it.
        var numEl = rows[i].querySelector('td:first-child > div:not(.order-card-top)');
        rows[i].setAttribute('aria-label', 'View details for order ' + (numEl ? numEl.textContent.trim() : ''));
      } else {
        rows[i].classList.remove('order-card-tappable');
        rows[i].removeAttribute('role');
        rows[i].removeAttribute('tabindex');
        rows[i].removeAttribute('aria-label');
      }
    }
  }
  syncRoles();
  // addListener is the pre-2021 Safari spelling - the rider board gets used on
  // whatever phone the driver has.
  if (mq.addEventListener) { mq.addEventListener('change', syncRoles); }
  else if (mq.addListener) { mq.addListener(syncRoles); }

  // Anything that already does something on tap keeps doing only that - the
  // card opener must not fire on top of the View Details button, a Navigate
  // link or a form control.
  function isInteractive(target, row) {
    var el = target.closest('a, button, input, select, textarea, label, [onclick]');
    return !!el && el !== row;
  }

  tbody.addEventListener('click', function (e) {
    if (!mq.matches) { return; }
    var row = e.target.closest('tr[data-order]');
    if (!row || isInteractive(e.target, row)) { return; }
    // A tap that ended a text selection was a selection, not a tap.
    var sel = window.getSelection();
    if (sel && String(sel).length > 0) { return; }
    openOrderDetailsFromRow(row);
  });

  tbody.addEventListener('keydown', function (e) {
    if (!mq.matches) { return; }
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') { return; }
    var row = e.target.closest('tr[data-order]');
    if (!row || e.target !== row) { return; }
    e.preventDefault();          // Space would otherwise scroll the page
    openOrderDetailsFromRow(row);
  });
})();

// Both entry points into the details modal go through here: the explicit
// "View Details" button and a tap on the card body around it. Reads the
// payload off the owning <tr> (data-order), so the two can never drift.
function openOrderDetailsFromRow(el) {
  const row = el.closest('tr');
  if (!row || !row.dataset.order) { return; }
  openOrderDetailsModal(JSON.parse(row.dataset.order));
}

function openOrderDetailsModal(data) {
  document.getElementById('odOrderNumber').textContent = data.orderNumber;

  const trackingWrap = document.getElementById('odTrackingWrap');
  if (data.trackingCode) {
    document.getElementById('odTracking').textContent = data.trackingCode;
    trackingWrap.style.display = 'block';
  } else {
    trackingWrap.style.display = 'none';
  }

  document.getElementById('odCustomerName').textContent = data.customerName;
  document.getElementById('odCustomerEmail').textContent = data.customerEmail;

  // callHref is the delivery contact for this order where there is one, the
  // account phone otherwise - already resolved and sanitised server-side.
  const callBtn = document.getElementById('odCallBtn');
  if (data.callHref) {
    callBtn.href = 'tel:' + data.callHref;
    callBtn.title = 'Call ' + data.callLabel;
    callBtn.setAttribute('aria-label', 'Call ' + data.customerName + ' on ' + data.callLabel);
    callBtn.hidden = false;
  } else {
    callBtn.hidden = true;
  }

  const phoneEl = document.getElementById('odCustomerPhone');
  const shownPhone = data.callLabel || data.customerPhone;
  if (shownPhone) {
    phoneEl.textContent = shownPhone;
    phoneEl.style.display = 'block';
  } else {
    phoneEl.style.display = 'none';
  }

  document.getElementById('odAmount').textContent = data.amount;

  const statusBadge = document.getElementById('odStatusBadge');
  statusBadge.textContent = data.statusText;
  statusBadge.className = 'admin-badge ' + data.statusBadgeClass;

  document.getElementById('odOrderDate').textContent = data.orderDate;

  const paymentWrap = document.getElementById('odPaymentWrap');
  paymentWrap.innerHTML = '';
  const paymentBadge = document.createElement('span');
  paymentBadge.className = 'admin-badge ' + (data.isPaid ? 'admin-badge--active' : (data.paymentMethod === 'cod' ? 'admin-badge--danger' : 'admin-badge--pending'));
  paymentBadge.style.textTransform = 'uppercase';
  paymentBadge.textContent = data.isPaid ? 'Paid' : 'Unpaid';
  paymentWrap.appendChild(paymentBadge);
  if (!data.isPaid && data.paymentMethod !== 'cod') {
    const note = document.createElement('div');
    note.style.cssText = 'font-size:11px;color:var(--admin-muted);margin-top:4px;';
    note.textContent = 'Verify in Admin panel';
    paymentWrap.appendChild(note);
  } else if (!data.isPaid && data.canRecordPayment) {
    // No standalone "Record Payment" trigger here on purpose - cash is
    // collected as part of the "Mark as Delivered" flow (Confirm Delivery
    // chains straight into the Record Payment modal for COD orders).
    const note = document.createElement('div');
    note.style.cssText = 'font-size:11px;color:var(--admin-muted);margin-top:4px;';
    note.textContent = 'Collected on delivery';
    paymentWrap.appendChild(note);
  }

  const proofWrap = document.getElementById('odProofWrap');
  proofWrap.innerHTML = '';
  if (data.proofUrls && data.proofUrls.length > 0) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.style.cssText = 'background:none;border:none;padding:0;color:var(--admin-success);font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:14px;min-height:44px;';
    btn.innerHTML = '<i class="fi fi-rr-check"></i> View proof' + (data.proofUrls.length > 1 ? ' (' + data.proofUrls.length + ')' : '');
    btn.onclick = function () {
      closeOrderDetailsModal();
      openProofPhoto(data.proofUrls, data.orderNumber, data.completedAt || '');
    };
    proofWrap.appendChild(btn);
  } else {
    const span = document.createElement('span');
    span.style.color = 'var(--admin-muted)';
    span.textContent = 'Not yet delivered';
    proofWrap.appendChild(span);
  }

  const actionWrap = document.getElementById('odActionWrap');
  actionWrap.innerHTML = '';

  // Navigate, for phones. The Actions column this normally lives in is
  // display:none below 767px - the whole cell moves into this modal - so on
  // the device that actually has a map app the button was rendering into
  // something invisible. Same link as the desktop row, built from the
  // deliveryAddress already in this payload; no new data, no new endpoint.
  // Rendered first so it survives whichever branch below runs.
  const navAddr = (data.deliveryAddress || '').trim();
  // Coordinates when the customer pinned their spot, the typed address
  // otherwise. The pin is the whole point: it routes to the gate even when
  // the street line is misspelled.
  const navPin = (data.navTarget || '').trim();
  const navDest = navPin || navAddr;
  if (navDest && navDest.toUpperCase() !== 'N/A' && data.statusText !== 'Completed') {
    const nav = document.createElement('a');
    nav.href = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(navDest);
    nav.target = '_blank';
    nav.rel = 'noopener noreferrer';
    nav.className = 'od-navigate';
    nav.innerHTML = '<i class="fi fi-rr-marker"></i> Navigate to drop-off' + (navPin ? ' (pinned)' : '');
    actionWrap.appendChild(nav);
  }

  if (data.actionType === 'completed') {
    const btn = document.createElement('button');
    btn.disabled = true;
    btn.style.cssText = 'width:100%;min-height:48px;background:var(--admin-surface);color:var(--admin-muted);border:none;border-radius:var(--radius-sm);cursor:not-allowed;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;';
    btn.innerHTML = '<i class="fi fi-rr-check"></i> Completed';
    actionWrap.appendChild(btn);
  } else if (data.actionType === 'claim') {
    // Unclaimed - same POST the desktop row's form makes.
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'admin-quick-link admin-quick-link--accent';
    btn.style.cssText = 'width:100%;min-height:48px;margin:0;border:none;cursor:pointer;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;';
    btn.innerHTML = '<i class="fi fi-rr-hand"></i> Take this delivery';
    btn.onclick = async function () {
      if (!(await uiConfirm('Take delivery ' + data.orderNumber + '? It will be assigned to you.', { okText: 'Take it' }))) { return; }
      closeOrderDetailsModal();
      submitClaimDelivery(data.orderId);
    };
    actionWrap.appendChild(btn);
  } else if (data.actionType === 'mark_delivered') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'admin-quick-link';
    btn.style.cssText = 'width:100%;min-height:48px;margin:0;border:none;cursor:pointer;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;';
    btn.innerHTML = '<i class="fi fi-rr-camera"></i> Mark as Delivered';
    btn.onclick = function () {
      closeOrderDetailsModal();
      openDeliveryModal(data);
    };
    actionWrap.appendChild(btn);

    // Live tracking sits after the proof action, not in front of it - same
    // ordering as the desktop row.
    if (data.canStartTracking) {
      const track = document.createElement('button');
      track.type = 'button';
      track.className = 'courier-secondary-btn';
      track.style.cssText = 'width:100%;min-height:48px;justify-content:center;';
      track.innerHTML = '<i class="fi fi-rr-navigation"></i> Start Tracking';
      track.onclick = function () {
        closeOrderDetailsModal();
        startDeliveryTracking(data.orderId, data.orderNumber);
      };
      actionWrap.appendChild(track);
    }
  } else if (data.actionType === 'record_payment') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'admin-quick-link admin-quick-link--accent';
    btn.style.cssText = 'width:100%;min-height:48px;margin:0;border:none;cursor:pointer;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;';
    btn.innerHTML = '<i class="fi fi-rr-wallet"></i> Record Payment';
    btn.onclick = function () {
      closeOrderDetailsModal();
      openRecordPaymentModal(data.orderId, data.orderNumber, data.amountRaw);
    };
    actionWrap.appendChild(btn);
  } else if (data.actionType === 'not_yours') {
    const span = document.createElement('span');
    span.style.cssText = 'display:block;text-align:center;padding:12px 0;color:var(--admin-muted);font-size:13px;font-style:italic;';
    span.textContent = 'Assigned to another driver';
    actionWrap.appendChild(span);
  } else {
    const span = document.createElement('span');
    span.style.cssText = 'display:block;text-align:center;padding:12px 0;color:var(--admin-muted);font-size:13px;font-style:italic;';
    span.textContent = 'Awaiting driver';
    actionWrap.appendChild(span);
  }

  const modal = document.getElementById('orderDetailsModal');
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function closeOrderDetailsModal() {
  const modal = document.getElementById('orderDetailsModal');
  modal.style.display = 'none';
  document.body.style.overflow = 'auto';
}

// Takes an unclaimed delivery. Posts the same claim_delivery action the
// desktop row's form does, so there is one server-side path (and one audit
// entry) whichever control the rider used. A real form POST rather than fetch,
// because the result is a full reload with the outcome banner - the board has
// to re-render anyway once the order becomes theirs.
function submitClaimDelivery(orderId) {
  const f = document.createElement('form');
  f.method = 'POST';
  f.style.display = 'none';
  f.innerHTML = '<?= csrf_field() ?>' +
    '<input type="hidden" name="action" value="claim_delivery">' +
    '<input type="hidden" name="order_id" value="' + encodeURIComponent(orderId) + '">';
  document.body.appendChild(f);
  f.submit();
}
document.addEventListener('click', function (event) {
  const modal = document.getElementById('orderDetailsModal');
  if (event.target === modal) { closeOrderDetailsModal(); }
});
document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
    const modal = document.getElementById('orderDetailsModal');
    if (modal.style.display === 'block') { closeOrderDetailsModal(); }
  }
});
</script>

<?php if (isDriver()): ?>
<!-- Photo Upload Modal - driver-only, same as the "Mark as Delivered"
     button that opens it (admin/super-admin get a read-only view instead,
     see the Actions column above). -->
<div id="deliveryModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,45,77,0.72);z-index:9999;backdrop-filter:blur(4px);overflow-y:auto;padding:24px 16px;box-sizing:border-box;">
  <!-- Capped at 92vh and laid out as a column (header / scrollable body /
       footer) instead of letting the container grow past the viewport -
       on a phone, the live camera preview plus a few captured photos used
       to push "Mark as Delivered" below the fold with no way to reach it.
       The footer sits outside the scrolling area so it's always visible,
       and only the middle section scrolls. -->
  <div id="deliveryModalContainer" class="modal-container" style="position:relative;width:100%;max-width:min(900px, 94vw);max-height:min(92vh, 860px);margin:0 auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;display:flex;flex-direction:column;">

    <!-- Modal Header -->
    <div class="modal-header" style="flex-shrink:0;padding:18px 24px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <h2 style="color:var(--admin-text);font-size:21px;font-weight:600;margin:0;">
        <i class="fi fi-rr-camera" style="margin-right:10px;color:var(--accent-cyan);"></i>
        Confirm Delivery
      </h2>
      <button type="button" onclick="closeDeliveryModal()" style="background:transparent;border:none;color:var(--admin-muted);font-size:22px;cursor:pointer;padding:6px;border-radius:var(--radius-sm);transition:all 0.2s ease;">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>

    <form id="deliveryForm" method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;min-height:0;flex:1;">
      <input type="hidden" name="action" value="complete_delivery">
      <input type="hidden" name="order_id" id="deliveryOrderId">
      <?= csrf_field() ?>

      <!-- Scrollable body - everything that can grow (camera preview,
           captured-photo thumbnails) lives in here, capped by the
           container's max-height above. -->
      <!-- position/width/height/background reset here - an unscoped
           .modal-content rule in shared/style.css (written for the
           login/register modal) otherwise wins: position:absolute +
           max-width:450px + height:fit-content pulls this out of the
           header/content/footer flex flow entirely, shrinking it to a small
           floating box instead of the scrollable flex child it needs to be. -->
      <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;width:100%;max-width:none;height:auto;max-height:none;padding:20px 24px;overflow-y:auto;flex:1;min-height:0;">

        <div style="background:var(--accent-cyan-bg);border:1px solid var(--admin-border);border-left:4px solid var(--accent-cyan);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:16px;">
          <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px;">
            <i class="fi fi-rr-info" style="font-size:15px;color:var(--accent-cyan);"></i>
            A delivery proof photo is required. This order can't be marked as delivered without one.
          </p>
        </div>

        <!-- Two columns from ~700px up (order summary | camera capture) so
             the extra width the modal now has (900px, was 640px) gets used
             for readability instead of just wider empty margins - single
             column below that, unchanged from before. -->
        <div class="delivery-modal-grid" style="display:grid;grid-template-columns:1fr;gap:20px;">

        <div style="background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);padding:14px 16px;align-self:start;">
          <p style="color:var(--admin-muted);font-size:14px;margin:0 0 10px 0;">Order #<span id="deliveryOrderNumber" style="color:var(--admin-text);font-weight:600;font-size:16px;"></span></p>

          <div style="display:flex;flex-direction:column;gap:10px;font-size:15px;">
            <div style="display:flex;flex-direction:column;gap:2px;">
              <strong style="color:var(--admin-text);">Recipient</strong>
              <span id="deliveryRecipientName" style="color:var(--admin-muted);"></span>
            </div>
            <div style="display:flex;flex-direction:column;gap:2px;">
              <strong style="color:var(--admin-text);">Contact</strong>
              <span id="deliveryRecipientPhone" style="color:var(--admin-muted);"></span>
            </div>
            <div style="display:flex;flex-direction:column;gap:2px;">
              <strong style="color:var(--admin-text);">Address</strong>
              <span id="deliveryAddress" style="color:var(--admin-muted);"></span>
            </div>
            <?php /* Directly under the address, because it is the thing that
                     makes the address usable - a tower and unit, a gate, a
                     landmark. Hidden entirely when the customer left it blank
                     rather than shown as an empty label. */ ?>
            <div id="deliveryNotesWrap" style="display:none;flex-direction:column;gap:2px;">
              <strong style="color:var(--admin-text);">Customer notes</strong>
              <span id="deliveryNotes" style="color:var(--admin-text);white-space:pre-wrap;"></span>
            </div>
            <div style="display:flex;flex-direction:column;gap:2px;">
              <strong style="color:var(--admin-text);">Amount</strong>
              <span id="deliveryAmount" style="color:var(--admin-muted);"></span>
              <span id="deliveryPaymentLine" style="color:var(--admin-muted);font-style:italic;font-size:13px;"></span>
            </div>
          </div>

          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--admin-border);">
            <strong style="color:var(--admin-text);font-size:14px;display:block;margin-bottom:6px;">Items</strong>
            <div id="deliveryItemsList" style="font-size:14px;"></div>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="admin-form-label" style="font-size:14px;">
            Delivery Proof Photo(s) <span style="color:var(--admin-danger);">*</span>
          </label>
          <p style="color:var(--admin-muted);font-size:13px;margin:4px 0 10px 0;">Point the camera at the delivered package/receipt and capture at least one photo directly - no file picker, no gallery upload. You can capture more than one.</p>

          <!-- Live camera preview (getUserMedia), started when the modal
               opens and stopped when it closes - see startDeliveryCamera()/
               stopDeliveryCamera() below. Replaces the old file input
               entirely; there is no "choose from device" path anymore.
               Height capped lower than before (260px, was 360px) so the
               preview leaves visible room for the Capture button and
               thumbnails below it even on a short phone screen. -->
          <div style="background:#000;border-radius:var(--radius-sm);overflow:hidden;position:relative;box-shadow:var(--neu-pressed);">
            <video id="deliveryCameraVideo" autoplay playsinline muted style="width:100%;max-height:260px;display:block;object-fit:cover;background:#000;"></video>
            <!-- Failure surface for the camera. Carries its own Retry button:
                 a denied-then-allowed permission, or a camera another app had
                 open, used to mean closing the order and reopening it just to
                 get a second attempt at getUserMedia. -->
            <div id="deliveryCameraError" style="display:none;color:#fff;background:rgba(120,20,20,0.85);padding:16px;font-size:13px;text-align:center;">
              <span id="deliveryCameraErrorText"></span>
              <button type="button" onclick="startDeliveryCamera()" style="display:block;margin:12px auto 0;background:#fff;color:#7a1414;border:none;padding:8px 18px;border-radius:999px;cursor:pointer;font-weight:700;font-size:13px;">
                <i class="fi fi-rr-refresh"></i> Retry camera
              </button>
            </div>
          </div>
          <canvas id="deliveryCameraCanvas" style="display:none;"></canvas>

          <!-- Camera chooser. Hidden until the device actually has more than
               one video input, so a phone (rear + front, chosen automatically)
               never shows a control nobody needs. On a desk it is the whole
               point: with OBS running, the physical webcam is OBS's and the
               only openable device is the OBS Virtual Camera. -->
          <div id="deliveryCameraPickWrap" style="display:none;margin-top:10px;">
            <label for="deliveryCameraPick" style="display:block;color:var(--admin-muted);font-size:12px;margin-bottom:4px;">
              Camera
            </label>
            <select id="deliveryCameraPick" onchange="switchDeliveryCamera(this.value)"
                    style="width:100%;padding:8px 10px;border-radius:var(--radius-sm);border:1px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-text);font:inherit;font-size:13px;"></select>
          </div>

          <div style="display:flex;justify-content:center;margin-top:12px;">
            <button type="button" id="deliveryCaptureBtn" onclick="captureDeliveryPhoto()" disabled style="background:var(--accent-cyan);color:#04202e;border:none;padding:10px 24px;border-radius:999px;cursor:pointer;font-weight:700;display:flex;align-items:center;gap:8px;box-shadow:var(--neu-raised-sm);">
              <i class="fi fi-rr-camera"></i> Capture Photo
            </button>
          </div>

          <!-- Hidden file input kept only so the existing backend (expects
               $_FILES['delivery_photos'] from a real form field) needs no
               changes - each captured frame is added to it via DataTransfer
               in syncDeliveryFileInput(), never picked by the driver. -->
          <input type="file" id="delivery_photos" name="delivery_photos[]" multiple style="display:none;">

          <div id="deliveryPhotoPreviewWrap" style="display:none;margin-top:14px;">
            <p style="color:var(--admin-muted);font-size:12px;margin:0 0 8px 0;">Captured photos (tap &times; to remove):</p>
            <div id="deliveryPhotoPreviewGrid" style="display:flex;flex-wrap:wrap;gap:10px;"></div>
          </div>
        </div>

        </div>
      </div>

      <!-- Footer - outside the scrollable body above, so Cancel/Mark as
           Delivered stay visible no matter how tall the content gets. -->
      <div class="modal-footer" style="flex-shrink:0;padding:14px 20px;border-top:1px solid var(--admin-border);display:flex;gap:12px;justify-content:flex-end;background:var(--admin-surface-2);">
        <button type="button" onclick="closeDeliveryModal()" style="background:var(--admin-surface);color:var(--admin-text);border:none;box-shadow:var(--neu-raised-sm);padding:10px 20px;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;transition:all 0.2s ease;">
          Cancel
        </button>
        <button type="submit" id="deliverySubmitBtn" class="admin-quick-link" style="border:none;padding:10px 20px;cursor:pointer;font-weight:600;margin:0;">
          <i class="fi fi-rr-check" style="margin-right:6px;"></i>
          Mark as Delivered
        </button>
      </div>
    </form>

  </div>
</div>
<?php endif; ?>

<?php if (isDriver()): ?>
<!-- Record Payment Modal - driver-only, opened from the Actions column's
     "Record Payment" button (actionType === 'record_payment'), the mobile
     Order Details modal's matching action, or auto-chained right after
     Confirm Delivery for a COD order (see $autoOpenRecordPayment below) -
     all three call openRecordPaymentModal() with the same 3 args. Cancelling
     out of this modal doesn't lose anything - the order deliberately stays
     'shipped' (not 'completed') until this is actually submitted, in
     complete_delivery's own POST handler above, so "Record Payment" just
     keeps showing as the order's action until it's done. Also reachable for
     orders already 'completed' from before this flow existed, with
     payment_status left at 'pending' - those just get the payment recorded,
     status untouched (see record_payment's own $completesNow check). -->
<div id="recordPaymentModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,45,77,0.72);z-index:9999;backdrop-filter:blur(4px);">
  <div class="modal-container" style="position:relative;max-width:440px;margin:8% auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;">

    <div class="modal-header" style="padding:24px 32px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <h2 style="color:var(--admin-text);font-size:22px;font-weight:600;margin:0;">
        <i class="fi fi-rr-wallet" style="margin-right:12px;color:var(--accent-cyan);"></i>
        Record Payment
      </h2>
      <button type="button" onclick="closeRecordPaymentModal()" style="background:transparent;border:none;color:var(--admin-muted);font-size:24px;cursor:pointer;padding:8px;border-radius:var(--radius-sm);transition:all 0.2s ease;">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>

    <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;width:100%;max-width:none;height:auto;max-height:none;padding:32px;">

      <div style="background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;">
        <h4 style="color:var(--admin-text);font-size:16px;margin-bottom:8px;">Order Details</h4>
        <p style="color:var(--admin-muted);font-size:14px;margin:0;">Order #<span id="rpOrderNumber"></span></p>
        <p style="color:var(--admin-muted);font-size:14px;margin:4px 0 0 0;">Order total: <span id="rpOrderTotal" style="font-weight:600;color:var(--admin-text);"></span></p>
      </div>

      <form id="recordPaymentForm" method="POST" action="">
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="order_id" id="rpOrderId">
        <input type="hidden" id="rpOrderTotalRaw">
        <?= csrf_field() ?>

        <div class="form-group" style="margin-bottom:20px;">
          <label for="rpAmountInput" class="admin-form-label" style="font-size:14px;">
            Amount Collected (&#8369;) <span style="color:var(--admin-danger);">*</span>
          </label>
          <!-- Pre-filled with the order total (openRecordPaymentModal below).
               It used to start at 0 so the rider typed the figure in rather
               than confirming one already on screen - but the form only ever
               accepts a number equal to the order total anyway (no partial,
               no over/under, no change given, enforced here and again in the
               record_payment POST handler), so the typing could not change
               the outcome, only delay it and get it wrong on a phone keypad
               at someone's door.

               Still a normal editable field, not readonly: clearing and
               retyping works exactly as before, and the live feedback below
               still rejects anything that is not the exact total. -->
          <input type="number" step="0.01" min="0" name="amount_collected" id="rpAmountInput" required
                 class="admin-form-input" style="width:100%;box-sizing:border-box;height:52px;font-size:18px;font-weight:600;">
          <p style="color:var(--admin-muted);font-size:12px;margin:8px 0 0 0;">Pre-filled with the order total. Collect this exact amount in cash - no change is given.</p>
          <div id="rpAmountFeedback" style="display:none;margin-top:10px;padding:10px 12px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;"></div>
        </div>

        <div class="rp-actions" style="display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" onclick="closeRecordPaymentModal()" style="background:var(--admin-surface);color:var(--admin-text);border:none;box-shadow:var(--neu-raised-sm);padding:12px 24px;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;transition:all 0.2s ease;">
            Cancel
          </button>
          <button type="submit" id="rpSubmitBtn" class="admin-quick-link" style="border:none;padding:12px 24px;cursor:pointer;font-weight:600;margin:0;">
            <i class="fi fi-rr-check" style="margin-right:6px;"></i>
            Confirm Payment
          </button>
        </div>
      </form>

    </div>

  </div>
</div>

<script>
function openRecordPaymentModal(orderId, orderNumber, amountDue) {
  const modal = document.getElementById('recordPaymentModal');
  const due = Number(amountDue || 0);
  document.getElementById('rpOrderId').value = orderId;
  document.getElementById('rpOrderNumber').textContent = orderNumber;
  document.getElementById('rpOrderTotal').textContent = '₱' + due.toFixed(2);
  document.getElementById('rpOrderTotalRaw').value = due;
  // Pre-filled with the order total, which is the only value this form will
  // accept. toFixed(2) rather than the raw number so it reads as money and
  // matches the "Order total" line above it exactly - a float like 4250.1
  // would otherwise show as "4250.1" against "₱4250.10".
  document.getElementById('rpAmountInput').value = due.toFixed(2);
  // Runs with the field already filled, so the rider opens the modal to a
  // green "Exact amount." rather than to a blank validation state.
  updateRecordPaymentFeedback();

  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  modal.classList.remove('closing');
  modal.classList.add('opening');
  document.getElementById('rpAmountInput').focus();
  document.getElementById('rpAmountInput').select();
}
function closeRecordPaymentModal() {
  const modal = document.getElementById('recordPaymentModal');
  modal.classList.add('closing');
  setTimeout(() => {
    modal.style.display = 'none';
    modal.classList.remove('closing');
    document.body.style.overflow = 'auto';
  }, 300);
}

// Live feedback as the rider types: only an exact match to the order total
// is accepted - no partial payments, no change to give back. Re-run on
// every keystroke so the rider sees this before they ever hit Confirm, not
// just as a rejection after submitting.
function updateRecordPaymentFeedback() {
  const due = Number(document.getElementById('rpOrderTotalRaw').value || 0);
  const enteredRaw = document.getElementById('rpAmountInput').value;
  const entered = Number(enteredRaw);
  const feedback = document.getElementById('rpAmountFeedback');
  const submitBtn = document.getElementById('rpSubmitBtn');

  if (enteredRaw === '' || isNaN(entered)) {
    feedback.style.display = 'none';
    submitBtn.disabled = false;
    return;
  }

  if (Math.abs(entered - due) > 0.001) {
    const diff = entered < due ? (due - entered).toFixed(2) : (entered - due).toFixed(2);
    const word = entered < due ? 'Short by' : 'Over by';
    feedback.style.display = 'block';
    feedback.style.background = 'rgba(194,104,111,0.14)';
    feedback.style.color = 'var(--admin-danger)';
    feedback.innerHTML = '<i class="fi fi-rr-exclamation-triangle"></i> ' + word + ' ₱' + diff + ' - enter the exact order total to confirm.';
    submitBtn.disabled = true;
  } else {
    feedback.style.display = 'block';
    feedback.style.background = 'rgba(95,168,138,0.14)';
    feedback.style.color = 'var(--admin-success)';
    feedback.innerHTML = '<i class="fi fi-rr-check"></i> Exact amount.';
    submitBtn.disabled = false;
  }
}

document.addEventListener('DOMContentLoaded', function () {
  const amountInput = document.getElementById('rpAmountInput');
  if (amountInput) {
    amountInput.addEventListener('input', updateRecordPaymentFeedback);
  }

  const form = document.getElementById('recordPaymentForm');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    // Belt-and-suspenders on top of the disabled-button state above (which
    // a driver could still bypass via devtools) - the real enforcement is
    // server-side in the record_payment POST handler.
    const due = Number(document.getElementById('rpOrderTotalRaw').value || 0);
    const entered = Number(document.getElementById('rpAmountInput').value);
    if (isNaN(entered) || Math.abs(entered - due) > 0.001) {
      e.preventDefault();
      updateRecordPaymentFeedback();
      return;
    }
    const btn = document.getElementById('rpSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fi fi-rr-spinner"></i> Submitting...';
  });
});
document.addEventListener('click', function (event) {
  const modal = document.getElementById('recordPaymentModal');
  if (event.target === modal) { closeRecordPaymentModal(); }
});
document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
    const modal = document.getElementById('recordPaymentModal');
    if (modal.style.display === 'block') { closeRecordPaymentModal(); }
  }
});

<?php if ($autoOpenRecordPayment): ?>
// Continues the "confirm delivery -> collect cash" chain: this order was
// just marked delivered and is still an unpaid COD order, so open Record
// Payment for it immediately instead of leaving the driver to find it again.
document.addEventListener('DOMContentLoaded', function () {
  openRecordPaymentModal(
    <?= json_encode($autoOpenRecordPayment['orderId']) ?>,
    <?= json_encode($autoOpenRecordPayment['orderNumber'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    <?= json_encode($autoOpenRecordPayment['amountDue']) ?>
  );
});
<?php endif; ?>
</script>
<?php endif; ?>


<style>
/* Kill the white half of every neumorphic shadow on this page.
   --neu-raised / --neu-raised-sm / --neu-pressed are all built as a dark
   offset plus a `-Npx -Npx rgba(255,255,255,.9)` highlight (style.css), and
   that highlight is the white glow around the cards, buttons and modals here.
   Redefining the token rather than hunting the ~29 box-shadow declarations
   means every one of them - including the inline styles on the modals - loses
   the white and keeps its dark side, with nothing to miss later.
   !important because the token is set on :root and again per theme. */
html body { --neu-shadow-light: transparent !important; }

/* Page ground. body.admin (admin.css) paints --bg-primary, a grey-to-taupe
   gradient; this page is a flat warm cream instead. background-attachment
   keeps it steady behind the order list rather than scrolling with it. */
html body.admin {
  background: #ffeddc !important;
  background-attachment: fixed !important;
}

.modal-overlay {
  animation: fadeIn 0.3s ease-out;
}

.modal-container {
  animation: slideIn 0.3s ease-out;
}

.modal-overlay.closing {
  animation: fadeOut 0.3s ease-in;
}

.modal-container.closing {
  animation: slideOut 0.3s ease-in;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes fadeOut {
  from { opacity: 1; }
  to { opacity: 0; }
}

@keyframes slideIn {
  from { 
    opacity: 0;
    transform: translateY(-50px) scale(0.95);
  }
  to { 
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@keyframes slideOut {
  from { 
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  to { 
    opacity: 0;
    transform: translateY(-50px) scale(0.95);
  }
}

.modal-header button:hover {
  background: var(--dropdown-hover);
  color: var(--admin-text);
}

/* Makes the blocked-submission state in Record Payment visually obvious,
   not just functionally disabled. */
#rpSubmitBtn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  filter: grayscale(40%);
}
#rpSubmitBtn:disabled:hover {
  transform: none;
  box-shadow: none;
}

button:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Delivery Orders filter/sort toolbar. */
.orders-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
}
.orders-filter-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.orders-filter-btn {
  background: var(--admin-surface);
  color: var(--admin-muted);
  border: 1px solid var(--admin-border);
  border-radius: 999px;
  padding: 8px 16px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  min-height: 36px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.15s ease;
}
.orders-filter-btn.active {
  background: linear-gradient(135deg, var(--admin-accent), var(--admin-primary-2));
  color: #fff;
  border-color: transparent;
}
.orders-filter-count {
  font-size: 11px;
  opacity: 0.85;
}
.orders-sort-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}
/* Status toggle. It's one control with two readings, so it always carries
   the .active treatment - the label inside is what changes, not whether the
   button looks selected. The trailing hint names the state one tap away. */
.orders-status-toggle {
  padding-right: 8px;
  gap: 8px;
}
.orders-toggle-hint {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-left: 2px;
  padding: 3px 9px 3px 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.22);
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}
/* The swap arrow lives here rather than in the markup so the JS only ever
   has to write the other state's name into the hint. */
.orders-toggle-hint::before {
  content: '\21C4';
  font-size: 12px;
  opacity: 0.85;
}
.orders-status-toggle:hover .orders-toggle-hint {
  background: rgba(255, 255, 255, 0.34);
}

.orders-sr-only {
  position: absolute;
  width: 1px; height: 1px;
  margin: -1px; padding: 0; border: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  clip-path: inset(50%);
  white-space: nowrap;
}

/* Pager below the order list. */
.orders-pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 20px 18px;
  border-top: 1px solid var(--admin-border);
}
.orders-pagination[hidden] { display: none; }
.orders-pagination-range {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--admin-muted);
}
.orders-pagination-pages {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
}
.orders-page-btn {
  min-width: 38px;
  min-height: 38px;
  padding: 0 10px;
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-sm);
  background: var(--admin-surface);
  color: var(--admin-text);
  font-size: 13px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.orders-page-btn:hover:not(:disabled):not(.current) {
  border-color: var(--admin-accent);
  color: var(--admin-accent);
}
.orders-page-btn.current {
  background: linear-gradient(135deg, var(--admin-accent), var(--admin-primary-2));
  border-color: transparent;
  color: #fff;
  cursor: default;
}
.orders-page-btn.arrow { font-size: 18px; line-height: 1; }
.orders-page-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
/* The page-wide `button:hover` lift above would make the pager bounce on
   every number - it's a row of small targets, not a call to action. */
.orders-page-btn:hover { transform: none; box-shadow: none; }
.orders-page-gap {
  padding: 0 2px;
  color: var(--admin-muted);
  font-weight: 700;
}

.orders-clear-filters {
  background: none; border: 0; cursor: pointer;
  color: var(--admin-accent); font-size: 12px; font-weight: 700;
  text-decoration: underline; padding: 8px 4px; min-height: 36px;
}
.orders-sort-wrap label {
  font-size: 12px;
  color: var(--admin-muted);
  font-weight: 600;
}
.orders-sort-wrap select {
  padding: 8px 12px;
  font-size: 13px;
  min-height: 36px;
  width: auto;
}
@media (max-width: 480px) {
  .orders-toolbar { flex-direction: column; align-items: stretch; }
  .orders-filter-tabs { justify-content: space-between; }
  .orders-filter-btn { flex: 1; justify-content: center; padding: 8px 10px; }
  /* One control now, not a strip of three - let it take the full width and
     push the "tap for the other status" hint out to the far edge, where a
     thumb already is. */
  .orders-status-toggle {
    justify-content: flex-start;
    padding: 8px 8px 8px 14px;
    min-height: 44px;
  }
  .orders-toggle-hint { margin-left: auto; }
  .orders-sort-wrap { justify-content: space-between; }
  .orders-sort-wrap select { flex: 1; }

  /* Pager stacks: the range readout reads first, the buttons get a full
     row of their own so 44px targets fit without wrapping mid-run. */
  .orders-pagination {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
    padding: 14px 14px 18px;
  }
  .orders-pagination-range { text-align: center; }
  .orders-pagination-pages {
    margin-left: 0;
    justify-content: center;
    flex-wrap: wrap;
  }
  .orders-page-btn { min-width: 44px; min-height: 44px; }
}

/* Statistics cards - mobile-first: 2 columns by default (phones), 4 columns
   from tablet width up. Replaces the old fixed 4-col inline style, which an
   earlier max-width:768px rule here could never actually override (inline
   style specificity always wins over a stylesheet rule). */
.delivery-stats-grid {
  grid-template-columns: repeat(2, 1fr);
}
@media (min-width: 768px) {
  .delivery-stats-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}

/* Long identifiers (invoice numbers, tracking codes, emails) must never
   force horizontal scroll - wrap them instead of letting them overflow.
   Scoped to the cell itself (not every descendant) so short fixed-width
   content like status badges doesn't get needlessly fractured mid-word. */
.orders-table td {
  overflow-wrap: anywhere;
  word-break: break-word;
}
.orders-table .admin-badge {
  overflow-wrap: normal;
  word-break: normal;
  white-space: nowrap;
}

/* "View Details" column - mobile/tablet only (<=767px, see the media query
   below). Hidden by default so it adds no extra column to the desktop/
   tablet-width table. */
.orders-view-details-cell {
  display: none;
}

@media (max-width: 768px) {
  .filters-section form {
    flex-direction: column;
    align-items: stretch;
  }

  .modal-container {
    margin: 2% auto;
    max-width: 95%;
    max-height: 92vh;
    overflow-y: auto !important;
  }

  .modal-content {
    padding: 24px;
  }

  .modal-header {
    padding: 20px 24px;
  }
}

@media (min-width: 700px) {
  .delivery-modal-grid {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
  }
}

/* Phones (<=767px): the fixed hamburger toggle (see admin.css
   .admin-menu-toggle, shared with every admin/owner page) sits at
   top:56px/left:0 - nudge the title over slightly on narrow screens so it
   doesn't sit directly under that pull-tab. */
@media (max-width: 767px) {
  .dashboard-welcome { margin-left: 44px; }
}

/* Phones (<=767px): turn the Delivery Orders table into a stack of
   labeled cards instead of a horizontally-scrolling table. Uses the
   data-label attributes already on each <td> (see the PHP loop above) so
   this is a pure CSS reflow of the exact same server-rendered data - no
   duplicate markup, no JS, nothing hidden via overflow. */
@media (max-width: 767px) {
  .orders-table { overflow-x: visible; }
  .orders-table > div { overflow-x: visible !important; }

  .orders-table table,
  .orders-table tbody,
  .orders-table tr,
  .orders-table td {
    display: block;
    width: 100%;
  }
  .orders-table thead { display: none; }

  .orders-table tbody tr {
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: var(--radius-lg);
    box-shadow: var(--neu-raised-sm);
    /* Matches the "Delivery Orders" header/toolbar's own 20px inset above. */
    margin: 0 20px 16px 20px;
    padding: 4px 16px;
    /* The rule above gives every <tr>/<td> width:100% - combined with this
       row's own 20px left+right margin that made each card 40px wider than
       its container (100% + margins), which is exactly what let the whole
       table wrapper slide left/right. A block element's default (auto)
       width already fills its container correctly accounting for margins,
       so drop the forced 100% here instead of adding it back some other way. */
    width: auto;
    box-sizing: border-box;
  }

  .orders-table td {
    padding: 12px 0;
    border-bottom: 1px dashed var(--admin-border);
    text-align: left !important;
  }
  .orders-table td:last-child { border-bottom: none; padding-bottom: 16px; }
  /* Actions is the card's full-width button strip in the mockup, not a
     labeled field - no "ACTIONS" caption above it. */
  .orders-table td:last-child::before { content: none; }

  .orders-table td::before {
    content: attr(data-label);
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .4px;
    text-transform: uppercase;
    color: var(--admin-muted);
    margin-bottom: 4px;
  }
  /* Order # doubles as the card's title - no redundant label, sits flush
     against the card's top edge, slightly larger. */
  .orders-table td:first-child {
    padding-top: 16px;
  }
  .orders-table td:first-child::before { content: none; }
  .orders-table td:first-child > div:first-child { font-size: 16px; }

  /* Collapse each card to a compact summary - Order #, Amount, Status -
     plus the "View Details" button. Customer/Proof/Order Date/Actions move
     into #orderDetailsModal instead of being shown inline (confirmed scope:
     mobile/tablet cards only; the desktop table below 767px is unaffected
     since .orders-view-details-cell stays display:none there). */
  .orders-table td[data-label="Customer"],
  .orders-table td[data-label="Payment"],
  .orders-table td[data-label="Proof"],
  .orders-table td[data-label="Order Date"],
  .orders-table td[data-label="Actions"] {
    display: none !important;
  }
  /* The "no orders match this filter" row isn't a real order card - skip
     the card border/padding/label treatment above. */
  .orders-empty-filter-cell {
    border: none !important;
  }
  .orders-empty-filter-cell::before { content: none; }

  /* The whole card is a tap target (see the delegated handler in the script
     above) - give it the feedback a button gets, and suppress the grey iOS
     flash over the entire card in favour of the pressed state below. */
  .orders-table tbody tr.order-card-tappable {
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: transform .14s ease, box-shadow .14s ease;
  }
  .orders-table tbody tr.order-card-tappable:active {
    transform: scale(.985);
    box-shadow: var(--neu-pressed);
  }
  .orders-table tbody tr.order-card-tappable:focus-visible {
    outline: 2px solid var(--admin-accent);
    outline-offset: 2px;
  }
  @media (prefers-reduced-motion: reduce) {
    .orders-table tbody tr.order-card-tappable { transition: none; }
    .orders-table tbody tr.order-card-tappable:active { transform: none; }
  }

  .orders-view-details-cell {
    display: block !important;
    padding-top: 16px !important;
  }
  .orders-view-details-cell button {
    width: 100%;
    min-height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-primary-2));
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    box-shadow: var(--neu-raised-sm);
  }
}

/* Small phones (<=480px): push the proof/delivery modals to near-full-screen
   so there's maximum room for photos and the camera preview, and make the
   close button an easy 44px+ tap target. */
@media (max-width: 480px) {
  .modal-container {
    margin: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
    min-height: 100vh;
    border-radius: 0 !important;
  }
  .modal-header { padding: 16px !important; }
  .modal-content { padding: 16px !important; }
  .modal-header button {
    width: 44px;
    height: 44px;
  }
  /* #deliveryModal's overlay carries its own padding (added so the whole
     modal can scroll on viewports where 92vh overshoots what's actually
     visible - see openDeliveryModal) - drop it on small phones so the
     container still goes edge-to-edge like every other modal here. */
  #deliveryModal.modal-overlay {
    padding: 0;
  }
}

/* ---------------------------------------------------------------------
   Mobile reskin (<=767px) - "Hello, {name}" greeting header, search bar,
   rounded stat tiles, an accent-gradient highlight banner, and an
   order-card header row (avatar + status pill), modeled on a food-delivery
   app's mobile layout the site owner shared. Everything here is additive
   and scoped to Delivery_Report.php's own <style> block - it doesn't touch
   admin.css, so no other admin page is affected. Colors stay on the
   existing --admin-accent/--admin-primary-2/--neu-* tokens (light/dark
   both handled automatically) instead of the screenshot's literal orange,
   so it reads as this app's own style, not a pasted-in skin.
   ------------------------------------------------------------------- */

/* Hidden by default (tablet/desktop) - revealed in the max-width:767px
   block below. Declared once here so there's a single source of truth for
   "mobile-only" instead of relying on each media query to remember. */
.mobile-greeting-header,
.mobile-highlight-banner,
.order-card-top { display: none; }

.mobile-greeting-search input[type="search"]::-webkit-search-cancel-button {
  -webkit-appearance: none;
}

@media (max-width: 767px) {
  /* The desktop title block is replaced by the greeting header below -
     avoids showing both on a phone. */
  .dashboard-welcome { display: none; }

  .mobile-greeting-header {
    display: block;
    /* Matches the existing .dashboard-welcome mobile offset above, so the
       greeting/avatar row also clears the fixed hamburger pull-tab
       (admin.css .admin-menu-toggle, top:56px/left:0). */
    margin: 0 44px 20px 44px;
  }
  .mobile-greeting-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .mobile-greeting-label {
    display: block;
    color: var(--admin-muted);
    font-size: 13px;
    font-weight: 600;
  }
  .mobile-greeting-name {
    margin: 2px 0 0;
    color: var(--admin-text);
    font-size: 22px;
    font-weight: 800;
  }
  .mobile-greeting-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
    /* It's a <button> now (it opens the nav) - strip the UA chrome. */
    border: none;
    padding: 0;
    font-family: inherit;
    cursor: pointer;
    -webkit-appearance: none;
    appearance: none;
    transition: transform .18s ease, box-shadow .18s ease;
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-primary-2));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 15px;
  }
  .mobile-greeting-avatar img { width: 100%; height: 100%; object-fit: cover; }

  .mobile-greeting-search {
    margin-top: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--admin-surface);
    box-shadow: var(--neu-pressed);
    border-radius: 999px;
    padding: 12px 16px;
  }
  .mobile-greeting-search i.fi-rr-search { color: var(--admin-muted); font-size: 15px; }
  .mobile-greeting-search input {
    flex: 1;
    border: none;
    background: transparent;
    color: var(--admin-text);
    font-size: 14px;
    min-width: 0;
  }
  .mobile-greeting-search input:focus { outline: none; }
  .mobile-greeting-search input::placeholder { color: var(--admin-muted); }
  .mobile-greeting-search button {
    background: var(--admin-surface);
    box-shadow: var(--neu-raised-sm);
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    min-height: 32px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--admin-text);
    cursor: pointer;
  }

  /* Stat tiles - rounder, icon sits in a soft neumorphic circle instead of
     floating on its own, closer to the mockup's card treatment. */
  .delivery-stats-grid .stat-card {
    border-radius: 20px;
    text-align: left;
    padding: 16px;
  }
  .delivery-stats-grid .stat-icon {
    width: 40px;
    height: 40px;
    margin: 0 0 10px;
    padding: 8px;
    box-sizing: border-box;
    border-radius: 50%;
    background: var(--admin-surface);
    box-shadow: var(--neu-pressed);
  }

  .mobile-highlight-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-primary-2));
    color: #fff;
    border-radius: var(--radius-lg);
    padding: 18px 20px;
    margin-bottom: 20px;
    box-shadow: var(--neu-raised);
  }
  .mobile-highlight-text { display: flex; flex-direction: column; gap: 2px; }
  .mobile-highlight-text strong { font-size: 18px; font-weight: 800; }
  .mobile-highlight-text span { font-size: 13px; opacity: 0.9; }
  .mobile-highlight-cta {
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.4);
    color: #fff;
    border-radius: 999px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  /* Order card header row: avatar chip (top-left) + Status badge, which
     this block repositions to top-right - together they read as the
     mockup's image + rating-pill row. Order #/Amount become the card's
     title/price underneath. */
  .orders-table tbody tr[data-status] { position: relative; }
  .order-card-top {
    display: flex;
    position: absolute;
    top: 14px;
    left: 16px;
  }
  .order-card-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-primary-2));
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .orders-table td:first-child { padding-top: 54px; }
  .orders-table td[data-label="Status"] {
    position: absolute;
    top: 14px;
    right: 16px;
    padding: 0;
    border-bottom: none !important;
    /* Every <td> above is given width:100% for the stacked-card reflow.
       Left on this one, the out-of-flow cell became a full-card-width box
       pinned by its right edge - so its left edge overhung the card and the
       left-aligned badge inside it printed straight over the avatar circle
       at top-left. Shrink-to-fit is what "pin the badge to the top-right"
       actually wants; text-align:right keeps it there if the badge ever
       stops being the cell's only content. */
    width: auto;
    max-width: calc(100% - 64px);
    text-align: right !important;
  }
  .orders-table td[data-label="Status"]::before { content: none; }
  .orders-table td[data-label="Amount"]::before { content: none; }
  .orders-table td[data-label="Amount"] {
    font-size: 20px;
    color: var(--admin-accent);
  }
}

/* ============================================================
   Mobile relayout pass. Consolidated + last in the cascade so it
   corrects the scattered rules above:
   - the greeting block wasted ~64px of side inset on a 360px phone
   - the delivery modals only went full-screen at <=480 and left
     their action buttons floating at the bottom-right
   - card / stat spacing was tight enough to wrap numbers
   ============================================================ */
@media (max-width: 767px) {
  /* Greeting header sat 44px in from each side ON TOP of the container's own
     padding. Pull it flush. Nothing needs to clear the top-left corner any
     more either: the fixed hamburger pull-tab is hidden on phones and the
     greeting avatar opens the nav in its place, so the row starts at the edge
     like the rest of the page. */
  .admin-menu-toggle { display: none !important; }
  /* No sidebar on phones at all. Its nav list is a single link to this page
     and its only other job was Logout, which now lives in the avatar menu
     below - so the drawer, its backdrop and the body scroll-lock class it
     drove are all dead weight here. */
  .admin-sidebar,
  .admin-sidebar-backdrop { display: none !important; }
  /* The real reason every card, tile, banner, filter row and the map all sat
     hard against both screen edges: <main class="container"> carries an
     inline style="padding: 30px 0", and an inline declaration beats
     style.css's own .container rule (0 20px, narrowing to 0 14px / 0 10px on
     phones). So the page's horizontal padding was not 14px, it was zero, and
     every child inherited that edge. One inset here rather than a margin per
     component - they are all measured from this box, so they stay aligned
     with each other for free. !important is what it takes to outrank the
     inline padding; the vertical 30px is left alone. */
  main.container { padding-left: 16px !important; padding-right: 16px !important; }

  /* Now that the container itself is inset, the greeting header goes back to
     flush - it lines up with the cards below instead of being the one block
     with its own outdent. */
  .mobile-greeting-header { margin: 0 0 18px !important; }
  /* Everything below the greeting is a card or pill with its own ~16px of
     inner padding, so bare text sitting flush at the container edge reads as
     further left than the entire page. This second 16px puts "Hello, {name}"
     at the same x as the search pill's icon directly beneath it and the order
     cards' text below that, so the whole left column lines up. Left only -
     the avatar stays flush right, matching the pill's right edge. */
  .mobile-greeting-top { padding-left: 16px; min-height: 44px; }

  /* Reads as a control rather than decoration: a ring while the account menu
     is open, and a press response on tap. */
  .mobile-greeting-account { position: relative; flex-shrink: 0; }
  .mobile-greeting-avatar:active { transform: scale(0.94); }
  .mobile-greeting-avatar[aria-expanded="true"] {
    box-shadow: 0 0 0 2px var(--admin-surface), 0 0 0 4px var(--admin-accent);
  }

  /* Account menu - anchored under the avatar, right-aligned so it never
     runs off the edge of a narrow phone. */
  .mobile-account-menu {
    display: block;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    z-index: 1200;
    min-width: 200px;
    padding: 8px;
    border-radius: var(--radius-md, 14px);
    background: var(--admin-surface);
    box-shadow: var(--neu-raised), 0 10px 28px rgba(0, 0, 0, 0.18);
    transform-origin: top right;
    animation: mobileAccountMenuIn .16s ease-out;
  }
  .mobile-account-menu[hidden] { display: none; }
  .mobile-account-menu-head {
    padding: 8px 10px 10px;
    border-bottom: 1px solid var(--admin-border);
    margin-bottom: 6px;
    line-height: 1.3;
  }
  .mobile-account-menu-head strong {
    display: block;
    color: var(--admin-text);
    font-size: 14px;
    font-weight: 700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .mobile-account-menu-head span {
    display: block;
    margin-top: 2px;
    color: var(--admin-muted);
    font-size: 11.5px;
    font-weight: 600;
  }
  .mobile-account-menu-logout {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 10px;
    border-radius: var(--radius-sm, 10px);
    color: var(--admin-danger, #c0564f);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
  }
  .mobile-account-menu-logout:active { background: rgba(0, 0, 0, 0.05); }
  .mobile-account-menu-logout i { font-size: 14px; }

  @keyframes mobileAccountMenuIn {
    from { opacity: 0; transform: translateY(-4px) scale(.97); }
    to   { opacity: 1; transform: none; }
  }

  /* Order cards edge-to-edge, a little more vertical breathing room;
     the "Delivery Orders" header/toolbar row matches the new inset. */
  .orders-table tbody tr {
    margin: 0 6px 14px 6px !important;
    padding: 8px 14px 14px !important;
  }
  .orders-table > div:first-child { padding-left: 14px !important; padding-right: 14px !important; }

  /* Stat tiles - keep the number on one line at 2-up. */
  .delivery-stats-grid { gap: 10px; }
  .delivery-stats-grid .stat-number { font-size: 23px; }
  .delivery-stats-grid .stat-label { font-size: 11.5px; line-height: 1.35; }

  /* Highlight banner: let the CTA drop below the text instead of
     squeezing both onto one line. */
  .mobile-highlight-banner { flex-direction: column; align-items: flex-start; gap: 14px; }
  .mobile-highlight-cta { align-self: stretch; justify-content: center; padding: 12px 14px; }
}

/* ---- Navigate button + "my location" map -------------------------- */
.courier-nav-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: var(--radius-sm);
  background: var(--admin-surface); color: var(--admin-text);
  box-shadow: var(--neu-raised-sm);
  font-size: 12px; font-weight: 600; text-decoration: none;
  white-space: nowrap; min-height: 32px;
}
.courier-nav-btn:hover { filter: brightness(1.08); text-decoration: none; color: var(--admin-text); }
.courier-geo-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; padding: 16px 20px;
}
.courier-geo-head h3 { margin: 0 0 4px; font-size: 16px; color: var(--admin-text); }
.courier-geo-head p { margin: 0; font-size: 12.5px; color: var(--admin-muted); }
#courierGeoMap { height: 260px; width: 100%; background: var(--admin-surface); }
/* Recentre button, built as a Leaflet control so it lives inside the map and
   inherits Leaflet's own corner spacing rather than floating over the tiles at
   a guessed offset. Sized for a thumb, since this is used one-handed. */
.geo-recenter {
  display: flex; align-items: center; justify-content: center;
  width: 44px; height: 44px;
  background: var(--admin-surface); color: var(--admin-text);
  border-radius: 10px; box-shadow: 0 1px 5px rgba(0,0,0,.4);
  font-size: 18px; text-decoration: none; cursor: pointer;
}
.geo-recenter:hover { background: var(--admin-surface-2); color: var(--admin-accent); text-decoration: none; }
.geo-recenter:active { transform: scale(.94); }
.geo-recenter.is-busy { color: var(--admin-accent); pointer-events: none; }
.geo-recenter.is-busy i { animation: geo-recenter-spin .8s linear infinite; }
@keyframes geo-recenter-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .geo-recenter.is-busy i { animation-duration: 2.4s; } }
.courier-geo-fix {
  margin: 0; padding: 0 20px 14px;
  font-size: 11.5px; color: var(--admin-muted); opacity: .8;
}
.courier-geo-fix:empty, .courier-geo-meta:empty { padding-bottom: 0; }
@media (max-width: 767px) {
  .courier-geo-head { padding: 14px 16px; }
  .courier-geo-head button { width: 100%; }
  #courierGeoMap { height: 220px; }
}

/* Navigate link inside the order-details modal. Matches the row button, but
   sized as a primary tap target since on a phone this is the only place it
   appears. */
.od-navigate {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  min-height: 48px; margin-bottom: 10px;
  border-radius: var(--radius-sm); text-decoration: none;
  background: var(--admin-surface); color: var(--admin-text);
  box-shadow: var(--neu-raised-sm);
  font-size: 14px; font-weight: 700;
}
.od-navigate:hover { filter: brightness(1.08); text-decoration: none; color: var(--admin-text); }

/* Call the customer. Green because it is the one control on this board that
   reaches a person rather than changing a record, and a rider scanning the row
   for it should not have to read anything. */
.courier-call-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 12px; border-radius: 999px;
  background: rgba(95, 168, 138, 0.16);
  border: 1px solid var(--admin-success);
  color: var(--admin-success);
  font-size: 12px; font-weight: 700; line-height: 1;
  text-decoration: none; white-space: nowrap;
}
.courier-call-btn:hover { background: var(--admin-success); color: #fff; text-decoration: none; }
.courier-call-btn:focus-visible { outline: 2px solid var(--admin-success); outline-offset: 2px; }
.courier-call-btn i { font-size: 13px; }

/* Secondary board action (Start Tracking) - deliberately quieter than the
   .admin-quick-link primary next to it, so the proof step reads as the one to
   press. */
.courier-secondary-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: var(--radius-sm);
  background: var(--admin-surface); color: var(--admin-text);
  box-shadow: var(--neu-raised-sm); border: none;
  font-size: 12px; font-weight: 600; cursor: pointer;
}
.courier-secondary-btn:hover { filter: brightness(1.06); }

@media (max-width: 767px) {
  /* Comfortable thumb targets once the rows become cards. */
  .courier-call-btn { padding: 9px 16px; font-size: 13px; min-height: 40px; }
}

/* Every delivery modal becomes a full-screen sheet on phones
   (<=600px, was <=480) with a pinned header + action bar and only
   the middle scrolling. */
@media (max-width: 600px) {
  #proofPhotoModal .modal-container,
  #orderDetailsModal .modal-container,
  #deliveryModal .modal-container,
  #recordPaymentModal .modal-container {
    position: fixed !important;
    inset: 0 !important;
    margin: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
    height: 100% !important;
    max-height: 100% !important;
    border-radius: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
  }
  #proofPhotoModal .modal-header,
  #orderDetailsModal .modal-header,
  #recordPaymentModal .modal-header,
  #deliveryModal .modal-header {
    flex-shrink: 0;
    padding: 14px 16px !important;
  }
  #proofPhotoModal .modal-content,
  #orderDetailsModal .modal-content,
  #recordPaymentModal .modal-content {
    flex: 1 1 auto;
    overflow-y: auto !important;
    max-height: none !important;
    padding: 18px 16px !important;
  }
  #proofPhotoModal .modal-header button,
  #orderDetailsModal .modal-header button,
  #recordPaymentModal .modal-header button,
  #deliveryModal .modal-header button {
    width: 44px; height: 44px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }

  /* Confirm Delivery: full-width buttons in the pinned footer;
     shorter camera preview so Capture + thumbnails stay on-screen. */
  #deliveryModal .modal-footer {
    padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px)) !important;
  }
  #deliveryModal .modal-footer button { flex: 1; min-height: 48px; justify-content: center; padding: 12px !important; }
  #deliveryCaptureBtn { width: 100%; justify-content: center; min-height: 52px; font-size: 15px; }
  #deliveryCameraVideo { max-height: 38vh !important; }

  /* Record Payment: sticky full-width action bar at the bottom of
     the scrolling content. */
  .rp-actions {
    position: sticky;
    bottom: 0;
    gap: 10px;
    margin: 16px -16px calc(-1 * (18px + env(safe-area-inset-bottom, 0px)));
    padding: 14px 16px calc(14px + env(safe-area-inset-bottom, 0px));
    background: var(--admin-surface-2);
    border-top: 1px solid var(--admin-border);
  }
  .rp-actions button { flex: 1; min-height: 48px; justify-content: center; padding: 12px !important; }

  /* Order Details: stack Amount / Status and make the action button
     comfortably tappable. */
  .od-two-col { flex-direction: column; gap: 12px !important; }
  #odActionWrap button, #odActionWrap > span, #odActionWrap > a { width: 100%; }
}
</style>

<?php if (isDriver()): ?>
<script>
let deliveryCameraStream = null;
// Bumped by every start/stop of the preview. Each startDeliveryCamera()
// captures the value it was issued and abandons itself the moment the number
// moves on - the guard against a double-tapped Retry, or a modal closed while
// the permission prompt was still open, leaving a live track nothing owns.
let deliveryCameraGen = 0;
let deliveryCapturedFiles = []; // File objects, in capture order - synced into #delivery_photos via DataTransfer
// Video inputs seen by the last successful start, so the picker can be built
// without re-enumerating (labels are only populated once permission is held).
let deliveryCameraDevices = [];

/* Which camera to reach for first, remembered across visits.
   One rig's "right camera" is not another's: on a rider's phone it is the rear
   lens, on a desk running OBS it is the OBS Virtual Camera, because OBS owns
   the physical webcam and the browser cannot have it. Rather than guess again
   on every open, the one that last worked is tried first - by BOTH the QR
   scanner and the delivery preview, which is why these sit on window. */
const TW_CAMERA_KEY = 'tw_preferred_camera_id';
function twPreferredCameraId() {
  try { return localStorage.getItem(TW_CAMERA_KEY) || null; } catch (e) { return null; }
}
function twRememberCameraId(id) {
  if (!id) { return; }
  try { localStorage.setItem(TW_CAMERA_KEY, id); } catch (e) { /* private mode - just don't remember */ }
}
window.twPreferredCameraId = twPreferredCameraId;
window.twRememberCameraId = twRememberCameraId;

function openDeliveryModal(data) {
  const modal = document.getElementById('deliveryModal');
  document.getElementById('deliveryOrderId').value = data.orderId;
  document.getElementById('deliveryOrderNumber').textContent = data.orderNumber;

  document.getElementById('deliveryRecipientName').textContent = data.recipientName || data.customerName || 'N/A';
  document.getElementById('deliveryRecipientPhone').textContent = data.recipientPhone || data.customerPhone || 'N/A';
  document.getElementById('deliveryAddress').textContent = data.deliveryAddress || 'N/A';
  /* textContent, never innerHTML: this is customer-typed free text arriving
     from an API response, and it is the one field on this screen they control. */
  var __dn = (data.deliveryNotes || '').trim();
  var __dnWrap = document.getElementById('deliveryNotesWrap');
  var __dnText = document.getElementById('deliveryNotes');
  if (__dnWrap && __dnText) {
    __dnText.textContent = __dn;
    __dnWrap.style.display = __dn ? 'flex' : 'none';
  }
  document.getElementById('deliveryAmount').textContent = data.amount || '';
  const paymentLine = document.getElementById('deliveryPaymentLine');
  paymentLine.textContent = data.isPaid ? 'Paid' : (data.paymentMethod === 'cod' ? 'Cash on Delivery - collect on handoff' : 'Unpaid (verified in Admin panel)');

  const itemsList = document.getElementById('deliveryItemsList');
  itemsList.innerHTML = '';
  (data.items || []).forEach(function (item) {
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--admin-border);';
    const nameSpan = document.createElement('span');
    nameSpan.style.color = 'var(--admin-text)';
    nameSpan.textContent = item.name;
    const qtySpan = document.createElement('span');
    qtySpan.style.cssText = 'color:var(--admin-muted);white-space:nowrap;';
    qtySpan.textContent = 'Qty: ' + item.quantity;
    row.appendChild(nameSpan);
    row.appendChild(qtySpan);
    itemsList.appendChild(row);
  });

  // Reset any photos captured for a previously-opened order.
  deliveryCapturedFiles = [];
  syncDeliveryFileInput();
  renderDeliveryPhotoPreview();

  // ...and the "already confirmed" flag, so every order gets its own
  // confirmation. Without this, a rider who confirmed once in this tab and
  // then landed back on the board (a failed POST, a back navigation) would
  // find the next order's "Mark as Delivered" firing on the first tap.
  const deliveryForm = document.getElementById('deliveryForm');
  if (deliveryForm) { deliveryForm.__deliveryConfirmed = false; }

  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  modal.classList.remove('closing');
  modal.classList.add('opening');

  startDeliveryCamera();
}

function closeDeliveryModal() {
  const modal = document.getElementById('deliveryModal');
  modal.classList.add('closing');
  stopDeliveryCamera();
  setTimeout(() => {
    modal.style.display = 'none';
    modal.classList.remove('closing');
    document.body.style.overflow = 'auto';
    document.getElementById('deliveryForm').reset();
    deliveryCapturedFiles = [];
    document.getElementById('deliveryPhotoPreviewGrid').innerHTML = '';
    document.getElementById('deliveryPhotoPreviewWrap').style.display = 'none';
  }, 300);
}

// Turns a getUserMedia rejection into something a rider can act on. The old
// single message blamed permission for every cause, which sent people into
// phone settings for a camera that was actually busy or absent. The
// DOMException *name* is the only reliable discriminator here - Chrome,
// Firefox and Safari all word .message differently.
function deliveryCameraReason(err, inputCount) {
  const name = (err && err.name) || '';
  const detail = (err && err.message) ? ' (' + err.message + ')' : '';
  const tried = inputCount ? ' All ' + inputCount + ' camera(s) on this device were tried.' : '';
  switch (name) {
    case 'NotAllowedError':
    case 'PermissionDeniedError':
      return 'Camera permission was denied for this site. Tap the padlock/camera icon in the address bar, ' +
             'set Camera to Allow, then tap Retry camera.';
    case 'NotFoundError':
    case 'DevicesNotFoundError':
      return 'No camera was found on this device. A delivery cannot be confirmed without a photo - ' +
             'use a phone, or a device with a working camera.';
    case 'NotReadableError':
    case 'TrackStartError':
      // Reached only after the backoff has spent ~5s re-asking for every video
      // input by id, so neither "the scanner on this page" nor "the wrong
      // default device" is still a live suspect - it is something holding the
      // hardware from outside this browser. Only one browser can own a USB
      // webcam at a time, which is the part people do not expect.
      return 'The camera did not come free' + detail + '.' + tried +
             ' Another program still owns it - a different BROWSER counts here (Chrome, Edge), ' +
             'as do Teams, Zoom, Discord, OBS and the Windows Camera app. Close those, then tap Retry camera.';
    case 'OverconstrainedError':
    case 'ConstraintNotSatisfiedError':
      return 'No camera on this device matches what the preview asked for' + detail + '. Tap Retry camera.';
    case 'SecurityError':
      return 'The browser blocked camera access for this page' + detail + '. Open the page over HTTPS and try again.';
    case 'AbortError':
      return 'The camera stopped before it could start' + detail + '. Tap Retry camera.';
    default:
      return 'Could not access the camera' + (name ? ' - ' + name : '') + detail + '. Tap Retry camera.';
  }
}

// Opens the device camera and streams it live into the modal - "real time",
// not a file picker. Prefers the rear camera (facingMode: environment) since
// this runs on a driver's phone; falls back to any camera, then to a visible
// error message if no camera is available/permitted (no fallback to file
// upload - camera capture is now the only path, per requirements).
async function startDeliveryCamera() {
  const video = document.getElementById('deliveryCameraVideo');
  const errBox = document.getElementById('deliveryCameraError');
  const errText = document.getElementById('deliveryCameraErrorText');
  const captureBtn = document.getElementById('deliveryCaptureBtn');

  // Every await below can resolve after this attempt has been superseded - the
  // rider double-tapped Retry, or closed the modal while the permission prompt
  // was still up. Without this token the loser of that race assigned its
  // stream over the winner's (leaking a live track, so the NEXT open failed
  // with NotReadableError - "camera already in use" with nothing visibly using
  // it) and wrote its own stale error into a modal that had moved on.
  const gen = ++deliveryCameraGen;
  const stale = function () { return gen !== deliveryCameraGen; };

  function fail(message) {
    if (stale()) { return; }
    video.style.display = 'none';
    errText.textContent = message;
    errBox.style.display = 'block';
    captureBtn.disabled = true;
  }

  // Idempotent: this is also the Retry button's handler, so a second run must
  // release the tracks the first one took or the camera stays busy. It bumps
  // the generation too, which is why `gen` is read back above it.
  stopDeliveryCamera();
  deliveryCameraGen = gen;

  // The page's QR scanner holds the same device. The scan -> Confirm Delivery
  // handoff already stops it, but reaching this modal from the orders table
  // while that sheet was left open does not - and on most phones (and on
  // Windows) only one consumer can hold a camera at a time.
  if (typeof window.courierScanStop === 'function') {
    try { await window.courierScanStop(); } catch (e) { /* always resolves; never let it matter */ }
    if (stale()) { return; }
    // stop() resolving is not the device being free. Wait for the scanner's
    // preview tracks to actually end before asking for our own stream, so the
    // handoff stops being a race we then have to back off from.
    if (typeof window.courierScanAwaitReleased === 'function') {
      try { await window.courierScanAwaitReleased(1500); } catch (e) { /* bounded; never fatal */ }
      if (stale()) { return; }
    }
  }

  errBox.style.display = 'none';
  errText.textContent = '';
  video.style.display = 'block';
  // Stays disabled until there is a live frame to grab. Enabling it up front
  // is what let a driver tap Capture on a black preview and get nothing at
  // all, with no message either way.
  captureBtn.disabled = true;

  // Almost always the origin, not the browser. getUserMedia is only exposed
  // in a secure context, so the board works on localhost and over HTTPS but
  // the entire API is simply absent when a rider opens it over plain http://
  // on the LAN IP - which is how a phone reaches this page. TWCamera says
  // which of the two it is and, for the origin case, renders the https URL of
  // this very page as a link the rider can tap: retyping an address on a
  // phone at someone's door is not a realistic recovery step.
  var camIssue = window.TWCamera ? window.TWCamera.problem()
               : ((!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)
                  ? { message: 'Camera capture is not available on this connection.' } : null);
  if (camIssue) {
    if (window.TWCamera) {
      video.style.display = 'none';
      errBox.style.display = 'block';
      captureBtn.disabled = true;
      window.TWCamera.render(errText);
    } else {
      fail(camIssue.message);
    }
    return;
  }

  // Whatever worked last time first, then the rear camera, then any camera.
  // The remembered id is what makes a deliberate choice stick - pick the OBS
  // Virtual Camera once and every later open goes straight to it instead of
  // failing its way down the ladder again.
  var preferredId = twPreferredCameraId();
  var constraintSets = [];
  if (preferredId) {
    constraintSets.push({ video: { deviceId: { exact: preferredId } }, audio: false });
  }
  constraintSets.push({ video: { facingMode: { ideal: 'environment' } }, audio: false });
  constraintSets.push({ video: true, audio: false });

  // ...then every video input individually, BY ID.
  //
  // Both requests above resolve to whatever the browser calls the default
  // camera, so between them they only ever reach for ONE device. Windows
  // leaves ghost instances of a USB webcam behind - unplug and replug a
  // LifeCam, or let it re-enumerate, and Device Manager carries two entries,
  // one live and one dead. When the dead one is the default, every generic
  // request answers NotReadableError and no amount of waiting changes that:
  // the device the browser keeps reaching for is never coming back.
  //
  // Naming each input explicitly is what gets past it. enumerateDevices()
  // returns real deviceIds once camera permission has been granted (which is
  // the case by the time this matters), and a dead entry simply fails its own
  // attempt while the live one succeeds.
  var inputCount = 0;
  try {
    // Raced against a timeout: enumerateDevices() is normally instant, but it
    // can sit unresolved behind a wedged capture service - and everything below
    // waits on it, so a hang here would mean a black preview and no message at
    // all. Losing the race just costs the per-device attempts.
    var devices = await Promise.race([
      navigator.mediaDevices.enumerateDevices(),
      new Promise(function (resolve) { setTimeout(function () { resolve([]); }, 2000); })
    ]);
    if (stale()) { return; }
    deliveryCameraDevices = devices.filter(function (d) { return d.kind === 'videoinput' && d.deviceId; });
    deliveryCameraDevices.forEach(function (d) {
      inputCount++;
      if (d.deviceId === preferredId) { return; }   // already first in the ladder
      constraintSets.push({ video: { deviceId: { exact: d.deviceId } }, audio: false });
    });
  } catch (e) {
    // Enumeration is a bonus - the two generic requests above still stand.
    console.warn('[dispatch] could not enumerate cameras', e);
  }

  // THE HANDOFF RACE. This modal is normally reached straight off a QR scan,
  // and the scanner was holding the same device: courierScanStop() above has
  // resolved, but a camera is NOT free the instant its tracks end. Windows in
  // particular keeps it claimed for a second or more after the owning page
  // lets go, and getUserMedia answers NotReadableError the whole time.
  //
  // The budget for that used to be a single 500ms wait, taken only after both
  // constraint sets had already failed back-to-back - 513ms of cover for a gap
  // that routinely runs past two seconds. Past it the rider got "the camera is
  // already in use by another app or browser tab" pointing at a scanner they
  // had just watched close, and the delivery could not be confirmed at all.
  //
  // So: re-walk the ladder on a backoff that covers ~5s. Each round is one
  // honest retry of the same request, not a different one.
  var waits = [0, 250, 500, 1000, 1500, 2000];

  var lastErr = null;
  // Permission, policy and "there is no camera" are settled answers. Retrying
  // those only fires a second prompt, or spends five seconds arriving at the
  // same sentence - and a re-prompt buries the real reason behind a vaguer one.
  var settled = function (name) {
    return name === 'NotAllowedError' || name === 'PermissionDeniedError' ||
           name === 'SecurityError' || name === 'NotFoundError' ||
           name === 'DevicesNotFoundError';
  };
  var done = false;

  for (var w = 0; w < waits.length && !done; w++) {
    if (waits[w]) {
      // Say what the wait is for. A rider looking at a black rectangle has no
      // way to tell "starting" from "broken", and the old code gave them
      // several seconds of exactly that before deciding.
      errText.textContent = 'Waiting for the camera to be released...';
      errBox.style.display = 'block';
      await new Promise(function (r) { setTimeout(r, waits[w]); });
      if (stale()) { return; }
    }
    for (var i = 0; i < constraintSets.length; i++) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia(constraintSets[i]);
        // Won the acquisition but lost the race - the modal closed, or a newer
        // start is already running. Hand the device straight back rather than
        // leaving a track live with nothing pointing at it.
        if (stale()) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
        deliveryCameraStream = stream;
        lastErr = null;
        done = true;
        break;
      } catch (err) {
        if (stale()) { return; }
        lastErr = err;
        if (settled(err && err.name)) { done = true; break; }
      }
    }
  }

  if (lastErr || !deliveryCameraStream) {
    console.error('[dispatch] delivery camera failed after trying ' + constraintSets.length +
                  ' request(s) across ' + inputCount + ' video input(s)', lastErr);
    fail(deliveryCameraReason(lastErr, inputCount));
    return;
  }

  // Clear the "waiting" line - the camera answered.
  errBox.style.display = 'none';
  errText.textContent = '';

  // Which device actually answered? Read it off the track rather than assuming
  // the constraint that won, since `facingMode` and `video:true` both resolve
  // to a device we never named. That id is what gets remembered and what the
  // picker shows as selected.
  var liveId = null;
  try {
    var vt = deliveryCameraStream.getVideoTracks()[0];
    var settings = (vt && vt.getSettings) ? vt.getSettings() : {};
    liveId = settings.deviceId || null;
  } catch (e) { /* getSettings is optional; the picker just won't pre-select */ }
  if (liveId) { twRememberCameraId(liveId); }
  await renderDeliveryCameraPicker(liveId);
  if (stale()) { return; }

  video.srcObject = deliveryCameraStream;

  // THE line that makes the preview appear. The `autoplay` attribute does not
  // reliably start a stream assigned to .srcObject after the element has
  // already loaded - without this the element sits at readyState 0, paused,
  // so the preview was a black box AND video.videoWidth stayed 0, which made
  // captureDeliveryPhoto() hit its `if (!video.videoWidth) return;` and bail
  // silently. One missing call, both symptoms: dead preview, captures that
  // produced nothing.
  try {
    await video.play();
  } catch (e3) {
    // Closing the modal nulls srcObject, which rejects a pending play() with
    // AbortError - that is this code tidying up, not a failure worth showing.
    if (stale()) { return; }
    fail('The camera started but the preview could not play (' + (e3.message || e3.name) + '). Tap Retry camera.');
    return;
  }
  if (stale()) { return; }

  // play() can resolve before the first frame's dimensions are known, and
  // videoWidth is what capture depends on. Bounded, so a stalled camera
  // leaves a message rather than a modal that never becomes usable.
  if (!video.videoWidth) {
    await new Promise(function (resolve) {
      let settled = false;
      const finish = function () { if (!settled) { settled = true; resolve(); } };
      video.addEventListener('loadedmetadata', finish, { once: true });
      video.addEventListener('loadeddata', finish, { once: true });
      setTimeout(finish, 4000);
    });
    if (stale()) { return; }
  }

  if (!video.videoWidth) {
    fail('The camera is connected but is not sending any video. Check that no other app is using it, then tap Retry camera.');
    return;
  }

  captureBtn.disabled = false;
}

/* Builds the camera chooser from the inputs this device reports.
   Re-enumerates rather than trusting the list gathered before the stream
   opened: device LABELS are blank until a camera permission is actually held,
   so the pre-stream list would offer "Camera 1 / Camera 2" with no way to tell
   which is the OBS one. */
async function renderDeliveryCameraPicker(selectedId) {
  const wrap = document.getElementById('deliveryCameraPickWrap');
  const select = document.getElementById('deliveryCameraPick');
  if (!wrap || !select) { return; }

  try {
    const devices = await navigator.mediaDevices.enumerateDevices();
    deliveryCameraDevices = devices.filter(function (d) { return d.kind === 'videoinput' && d.deviceId; });
  } catch (e) { /* keep whatever the earlier enumeration found */ }

  // One camera is not a choice - don't put a control there for it.
  if (deliveryCameraDevices.length < 2) { wrap.style.display = 'none'; return; }

  select.innerHTML = '';
  deliveryCameraDevices.forEach(function (d, i) {
    const opt = document.createElement('option');
    opt.value = d.deviceId;
    opt.textContent = d.label || ('Camera ' + (i + 1));
    if (selectedId && d.deviceId === selectedId) { opt.selected = true; }
    select.appendChild(opt);
  });
  wrap.style.display = 'block';
}

/* Switching is a restart, not a track swap: the constraint ladder, the
   backoff and the readiness checks all live in startDeliveryCamera(), and
   half-reimplementing them here is how the two drift apart. Remembering the
   id first is what makes the new camera the one the restart reaches for. */
function switchDeliveryCamera(deviceId) {
  if (!deviceId) { return; }
  twRememberCameraId(deviceId);
  startDeliveryCamera();
}

function stopDeliveryCamera() {
  // Invalidates any start attempt still sitting on an await, so a stream that
  // arrives after this point stops itself instead of reattaching to a modal
  // the rider has already closed.
  deliveryCameraGen++;
  if (deliveryCameraStream) {
    deliveryCameraStream.getTracks().forEach(function (t) { t.stop(); });
    deliveryCameraStream = null;
  }
  const video = document.getElementById('deliveryCameraVideo');
  if (video) { video.pause(); video.srcObject = null; }
}

// Snapshots the current live frame onto a canvas, turns it into a JPEG File,
// and appends it to the in-progress set - repeatable, so a driver can
// capture the package at the doorstep, then a signature/ID, etc.
function captureDeliveryPhoto() {
  const video = document.getElementById('deliveryCameraVideo');
  if (!video.videoWidth) {
    // Was a bare `return`: the driver tapped Capture, nothing happened, and
    // nothing explained why. The button is disabled until the stream is ready
    // now, so this is a backstop rather than the usual path.
    alert('The camera preview is not ready yet. Wait for the picture to appear, then capture.');
    return;
  }
  const canvas = document.getElementById('deliveryCameraCanvas');
  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
  canvas.toBlob(function (blob) {
    if (!blob) {
      // A silent return here meant a tap that produced no thumbnail and no
      // explanation - the same dead end the disabled-button fix above closed.
      alert('The photo could not be encoded. Tap Capture Photo again.');
      return;
    }
    const idx = deliveryCapturedFiles.length;
    deliveryCapturedFiles.push(new File([blob], 'delivery_capture_' + Date.now() + '_' + idx + '.jpg', { type: 'image/jpeg' }));
    syncDeliveryFileInput();
    renderDeliveryPhotoPreview();
  }, 'image/jpeg', 0.9);
}

function removeDeliveryPhoto(idx) {
  deliveryCapturedFiles.splice(idx, 1);
  syncDeliveryFileInput();
  renderDeliveryPhotoPreview();
}

// Mirrors deliveryCapturedFiles into the hidden #delivery_photos input so
// the form submits exactly like it did with a real file picker - the
// backend's $_FILES['delivery_photos'] handling needed no changes.
function syncDeliveryFileInput() {
  const input = document.getElementById('delivery_photos');
  try {
    const dt = new DataTransfer();
    deliveryCapturedFiles.forEach(function (f) { dt.items.add(f); });
    input.files = dt.files;
  } catch (e) {
    // Older iOS Safari has no assignable input.files, so captured frames would
    // never reach the form field and the submit guard would insist no photo
    // had been taken - the one failure a driver cannot work around. Say so
    // instead of failing mutely.
    alert('This browser cannot attach the captured photos (' + (e.message || e.name) + '). Please use Chrome, or a newer version of Safari.');
  }
}

function renderDeliveryPhotoPreview() {
  const wrap = document.getElementById('deliveryPhotoPreviewWrap');
  const grid = document.getElementById('deliveryPhotoPreviewGrid');
  grid.innerHTML = '';
  if (deliveryCapturedFiles.length === 0) { wrap.style.display = 'none'; return; }
  deliveryCapturedFiles.forEach(function (file, idx) {
    const cell = document.createElement('div');
    cell.style.cssText = 'position:relative;width:110px;height:110px;';
    const img = document.createElement('img');
    img.src = URL.createObjectURL(file);
    img.alt = 'Captured proof photo';
    img.style.cssText = 'width:110px;height:110px;object-fit:cover;border-radius:var(--radius-sm);box-shadow:var(--neu-raised-sm);display:block;';
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick = function () { removeDeliveryPhoto(idx); };
    removeBtn.style.cssText = 'position:absolute;top:-6px;right:-6px;width:24px;height:24px;border-radius:50%;background:var(--admin-danger);color:#fff;border:none;cursor:pointer;font-size:16px;line-height:1;display:flex;align-items:center;justify-content:center;';
    cell.appendChild(img);
    cell.appendChild(removeBtn);
    grid.appendChild(cell);
  });
  wrap.style.display = 'block';
}

// Belt-and-suspenders on top of the input's own `required` attribute (which
// a driver could still bypass via devtools) - the server-side check in the
// POST handler above is the real enforcement; this is just a friendlier,
// immediate message instead of a round-trip to find out the same thing.
//
// Then the last-chance confirmation. "Mark as Delivered" is the one button on
// this board a rider cannot take back: it writes the proof photos, moves the
// order to delivered/completed and notifies the customer, and it sits under a
// thumb on a phone being used one-handed at someone's door. It used to fire on
// the first tap.
//
// uiReview rather than uiConfirm (both are in shared/includes/ui-dialog.php,
// loaded for this page by admin/includes/header.php) because the risk here is
// not "did you mean to press a button" but "are you at the right door" - so it
// prints the order, the recipient and the address back and makes the rider
// read them. It also deliberately does not accept Enter as yes, which an
// ordinary confirm does, so a held key or a double-tap cannot carry straight
// through the dialog that exists to stop exactly that.
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('deliveryForm');
  if (!form) return;

  function text(id) {
    const el = document.getElementById(id);
    return el ? (el.textContent || '').trim() : '';
  }

  form.addEventListener('submit', function (e) {
    const fileInput = document.getElementById('delivery_photos');
    if (!fileInput.files || fileInput.files.length === 0) {
      e.preventDefault();
      alert('Please take or upload at least one delivery proof photo before confirming this delivery.');
      return;
    }

    // First pass: stop, ask, and only replay the submit on a yes. The flag is
    // cleared every time the modal opens (see openDeliveryModal), so backing
    // out and coming back asks again.
    if (!form.__deliveryConfirmed) {
      e.preventDefault();

      const count = fileInput.files.length;
      const payment = text('deliveryPaymentLine');
      const rows = [
        { label: 'Order',     value: '#' + text('deliveryOrderNumber') },
        { label: 'Recipient', value: text('deliveryRecipientName'), emptyText: 'Not recorded' },
        { label: 'Address',   value: text('deliveryAddress'), emptyText: 'Not recorded' },
        { label: 'Amount',    value: text('deliveryAmount'), note: payment || undefined },
        { label: 'Proof photos', value: count + (count === 1 ? ' photo' : ' photos') }
      ];

      window.uiReview({
        title: 'Mark this order as delivered?',
        message: 'This confirms the handoff and cannot be undone. Check you are at the right drop-off before continuing.',
        rows: rows,
        okText: 'Yes, mark as delivered',
        cancelText: 'Go back'
      }).then(function (ok) {
        if (!ok) { return; }
        form.__deliveryConfirmed = true;
        // requestSubmit replays through this same handler - which now falls
        // past the guard to the disable-and-send branch below - so the button
        // still flips to "Submitting...". submit() skips handlers entirely,
        // hence the explicit disable in that fallback.
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          const btn = document.getElementById('deliverySubmitBtn');
          btn.disabled = true;
          btn.innerHTML = '<i class="fi fi-rr-spinner"></i> Submitting...';
          form.submit();
        }
      });
      return;
    }

    const btn = document.getElementById('deliverySubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fi fi-rr-spinner"></i> Submitting...';
  });
});

// Close modal when clicking outside
document.addEventListener('click', function(event) {
  const modal = document.getElementById('deliveryModal');
  if (event.target === modal) {
    closeDeliveryModal();
  }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    // Not while the "Mark this order as delivered?" question is up.
    // ui-dialog treats Escape as "no" but does not stop the event, so without
    // this the one keypress would both dismiss the question and tear down the
    // delivery modal behind it - throwing away the photos just captured.
    const ask = document.getElementById('uiDialog');
    if (ask && ask.open) { return; }
    const modal = document.getElementById('deliveryModal');
    if (modal.style.display === 'block') {
      closeDeliveryModal();
    }
  }
});
</script>
<?php endif; ?>

<?php if (isDriver()): ?>
<script src="<?= WEB_ROOT ?>public/shared/assets/js/driver-location-sender.js"></script>
<script>
// Start Live Delivery Tracking for an assigned Home Delivery order. Requests
// browser GPS permission, records the first fix through the start_tracking
// form POST above (so the button reliably flips to "Mark as Delivered" on
// reload), then hands off to DriverLocationSender for the recurring,
// throttled updates while this tab stays open.
//
// Everything between the tap and that first fix used to be invisible: the
// confirm closed and nothing else happened, for as long as it took GPS to
// answer - which on a device with no precise GPS was forever (see the grace
// window in driver-location-sender.js). The button read as broken because
// nothing ever told the rider it was working. This pill is that telling.
var trackingPill = null;

function trackingSay(msg, kind) {
  if (!trackingPill) {
    trackingPill = document.createElement('div');
    trackingPill.setAttribute('role', 'status');
    trackingPill.setAttribute('aria-live', 'polite');
    trackingPill.style.cssText =
      'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:10050;' +
      'max-width:min(460px,92vw);padding:12px 18px;border-radius:999px;' +
      'font-size:13px;font-weight:600;line-height:1.35;text-align:center;' +
      'box-shadow:0 10px 30px rgba(0,0,0,0.35);display:flex;align-items:center;gap:10px;' +
      'justify-content:center;';
    document.body.appendChild(trackingPill);
  }
  var bad = kind === 'error';
  trackingPill.style.background = bad ? '#7a1414' : '#0f2d4d';
  trackingPill.style.color = '#fff';
  trackingPill.style.borderRadius = bad ? '14px' : '999px';
  trackingPill.innerHTML = '<i class="fi fi-rr-' + (bad ? 'exclamation' : 'navigation') + '"></i>' +
                           '<span></span>';
  trackingPill.lastChild.textContent = msg;
}

function trackingDismiss(afterMs) {
  if (!trackingPill) { return; }
  var el = trackingPill;
  trackingPill = null;
  setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, afterMs || 0);
}

async function startDeliveryTracking(orderId, orderNumber) {
  if (!(await uiConfirm('Start live location sharing for order #' + orderNumber + '?\n\nPlease pull over safely before using this feature.'))) {
    return;
  }
  // Re-tapping while a watch is already running would stack senders; the
  // sender now stops its own previous watch, and this says why nothing new
  // appears to happen.
  if (DriverLocationSender.isTracking()) {
    trackingSay('Live tracking is already running for this trip - waiting for the first GPS fix.');
    return;
  }

  trackingSay('Getting a GPS fix…');

  DriverLocationSender.start(orderId, {
    updateUrl: '<?= WEB_ROOT ?>public/authenticated/api/update_driver_location.php',
    // Every rejected fix and every "still waiting" now reaches the screen
    // instead of being swallowed by a bare `return`.
    onWaiting: function (msg) { trackingSay(msg); },
    onFirstFix: function (lat, lng) {
      trackingSay('Fix acquired - starting live tracking…');
      var f = document.createElement('form');
      f.method = 'POST';
      f.style.display = 'none';
      f.innerHTML = '<?= csrf_field() ?>' +
        '<input type="hidden" name="action" value="start_tracking">' +
        '<input type="hidden" name="order_id" value="' + orderId + '">' +
        '<input type="hidden" name="lat" value="' + lat + '">' +
        '<input type="hidden" name="lng" value="' + lng + '">';
      document.body.appendChild(f);
      f.submit();
    },
    onError: function (msg) {
      trackingSay(msg, 'error');
      trackingDismiss(9000);
    }
  });
}
</script>
<?php endif; ?>

<!-- Leaflet and the tile server are both third-party origins reached late in
     the document; the handshakes cost ~2 round trips each from here. Warm
     them so the map is not waiting on DNS/TLS as well as on GPS. -->
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="preconnect" href="https://a.tile.openstreetmap.org" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
// "My location" map. Display only - it never posts anything. The fix that
// actually reaches the customer is sent by driver-location-sender.js once a
// delivery is started; this is here so a rider can confirm GPS works before
// setting off, and see roughly what the customer will be shown.
(function () {
  // Courier location card. Deliberately simple: one map, one marker, and a
  // button that fetches a fresh fix on demand.
  //
  // This replaced a multi-stop route planner. That version kept its plan in
  // localStorage and rebuilt it after every delivery, because confirming a
  // drop POSTs and reloads the page - too many moving parts for the job, and
  // it fought the reload on every order. Continuous tracking for the customer
  // is a separate thing entirely and still runs from driver-location-sender.js
  // once "Start Tracking" is pressed; nothing here feeds that.
  var btn    = document.getElementById('courierGeoUpdate');
  var status = document.getElementById('courierGeoStatus');
  var fixEl  = document.getElementById('courierGeoAccuracy');
  var mapEl  = document.getElementById('courierGeoMap');
  if (!btn || !status || !mapEl) { return; }

  var map = null, marker = null, halo = null, busy = false;
  // Last known fix, so the in-map recentre button can snap back at once
  // rather than leaving the rider staring at an empty map while a fresh
  // GPS read takes its time.
  var lastFix = null;

  function say(msg) { status.textContent = msg; }

  // The map used to be created inside draw(), i.e. only once a GPS fix had
  // landed - so the card sat as an empty grey box for as long as the fix took
  // (a cold fix indoors runs the full 20s timeout), and on a device that never
  // gets one it stayed blank forever. Tiles now load straight away, centred on
  // the branch, and the fix only has to move the marker.
  function ensureMap(lat, lng, zoom) {
    if (map) { return; }
    map = L.map('courierGeoMap', { zoomControl: true }).setView([lat, lng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    addRecenterControl();
    // The card can be laid out before the tiles exist; force a re-measure.
    setTimeout(function () { map.invalidateSize(); }, 60);
  }

  function draw(pos) {
    var lat = pos.coords.latitude, lng = pos.coords.longitude;
    var acc = Math.round(pos.coords.accuracy || 0);
    lastFix = { lat: lat, lng: lng };

    ensureMap(lat, lng, 16);
    if (!marker) {
      marker = L.marker([lat, lng]).addTo(map).bindPopup('You are here');
      halo = L.circle([lat, lng], { radius: acc, color: '#38bdf8', weight: 1, fillOpacity: 0.12 }).addTo(map);
      map.setView([lat, lng], 16);
    } else {
      marker.setLatLng([lat, lng]);
      halo.setLatLng([lat, lng]).setRadius(acc);
      map.setView([lat, lng], map.getZoom() < 14 ? 16 : map.getZoom());
    }

    say('Location updated.');
    if (fixEl) {
      fixEl.textContent = 'Accurate to about ' + acc + ' m \u00B7 updated ' +
        new Date(pos.timestamp).toLocaleTimeString();
    }
  }

  // Recentre control, sitting inside the map next to Leaflet's own zoom
  // buttons. Two jobs in one tap, in this order so it always feels immediate:
  //   1. snap the view back to the last known fix straight away;
  //   2. ask for a fresh GPS read, which redraws the marker when it lands.
  // Panning away to look at a street and then wanting to get back is the
  // common case, and that half needs no GPS at all.
  var recenterEl = null;

  function recenter() {
    if (!map) { return; }
    if (lastFix) {
      // Never zoom OUT on a recentre - if the rider has zoomed in to read a
      // street name, keep that and just move the view.
      map.setView([lastFix.lat, lastFix.lng], Math.max(map.getZoom(), 16), { animate: true });
      if (marker) { marker.openPopup(); }
    }
    locate();
  }

  function addRecenterControl() {
    if (!map || recenterEl) { return; }
    var Recenter = L.Control.extend({
      options: { position: 'bottomright' },
      onAdd: function () {
        var el = L.DomUtil.create('a', 'geo-recenter');
        el.href = '#';
        el.title = 'Centre the map on my location';
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', 'Centre the map on my location');
        el.innerHTML = '<i class="fi fi-rr-navigation" aria-hidden="true"></i>';
        // Without this a tap on the button also pans/zooms the map underneath.
        L.DomEvent.disableClickPropagation(el);
        L.DomEvent.on(el, 'click', L.DomEvent.stop);
        L.DomEvent.on(el, 'click', recenter);
        recenterEl = el;
        return el;
      }
    });
    map.addControl(new Recenter());
  }

  function fail(err) {
    if (err && err.code === 1) {
      say('Location permission denied. Allow it in your browser settings, then try again.');
    } else if (err && err.code === 3) {
      say('Timed out waiting for a GPS fix. Try again outdoors.');
    } else {
      say('Could not get a GPS fix.');
    }
  }

  function locate() {
    if (busy) { return; }
    if (!navigator.geolocation) { say('This browser has no location support.'); return; }
    // Browsers only expose geolocation in a secure context. localhost counts;
    // a plain http:// LAN address on a real phone does not, and fails with no
    // useful error - so say so plainly rather than looking broken.
    if (!window.isSecureContext) {
      say('Location needs a secure connection (https). On a phone over http this is blocked by the browser.');
      return;
    }

    busy = true;
    if (recenterEl) { recenterEl.classList.add('is-busy'); }
    btn.disabled = true;
    var label = btn.textContent;
    btn.textContent = 'Updating\u2026';
    say('Getting your location\u2026');

    function done() {
      busy = false;
      btn.disabled = false;
      btn.textContent = label;
      if (recenterEl) { recenterEl.classList.remove('is-busy'); }
    }

    navigator.geolocation.getCurrentPosition(function (pos) {
      done();
      draw(pos);
    }, function (err) {
      done();
      fail(err);
    // maximumAge was 0, which refuses any position the browser already holds
    // and forces a fresh lock on every single page load - and this board
    // reloads after every confirmed drop. A fix from the last half-minute is
    // more than good enough for "is my GPS working", and returns instantly.
    }, { enableHighAccuracy: true, maximumAge: 30000, timeout: 20000 });
  }

  // Tiles up front (branch view, zoomed out) so the card is never an empty
  // box; locate() then pulls the view to the rider.
  ensureMap(<?= json_encode((float)STORE_LAT) ?>, <?= json_encode((float)STORE_LNG) ?>, 12);

  btn.addEventListener('click', locate);
  locate();   // one fix on load so the card is useful without a tap
})();
</script>



<style>
/* Duty switch. Uses the page's existing neumorphic surface + token palette so
   it reads as part of the board, not a bolted-on control. */
.duty-card{
  display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
  margin:0 0 20px;padding:16px 20px;
  background:var(--admin-surface);border-radius:var(--radius-lg);box-shadow:var(--neu-raised-sm);
}
.duty-state{display:flex;align-items:center;gap:12px;min-width:0;}
.duty-dot{
  flex:0 0 auto;width:10px;height:10px;border-radius:50%;
  background:var(--admin-muted);box-shadow:0 0 0 4px rgba(169,183,198,.16);
}
/* Colour is the state, so it must not be the ONLY signal - the label next to
   it always says "On duty"/"Off duty" in words too. */
.duty-card[data-on-duty="1"] .duty-dot{
  background:var(--admin-success);box-shadow:0 0 0 4px rgba(95,168,138,.20);
  animation:duty-pulse 2.4s ease-in-out infinite;
}
@keyframes duty-pulse{
  0%,100%{box-shadow:0 0 0 4px rgba(95,168,138,.20);}
  50%    {box-shadow:0 0 0 7px rgba(95,168,138,.08);}
}
.duty-text{display:flex;flex-direction:column;gap:2px;min-width:0;}
.duty-text strong{color:var(--admin-text);font-size:15px;font-weight:700;}
.duty-text small{color:var(--admin-muted);font-size:12.5px;line-height:1.4;}

.duty-toggle{
  display:inline-flex;align-items:center;gap:10px;flex-shrink:0;
  padding:9px 16px 9px 12px;border:none;border-radius:999px;cursor:pointer;
  background:var(--admin-surface);box-shadow:var(--neu-raised-sm);
  color:var(--admin-text);font:inherit;font-size:13px;font-weight:700;
  min-height:44px;   /* thumb target - this is used one-handed on a phone */
  transition:filter var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out);
}
.duty-toggle:hover{filter:brightness(1.06);}
.duty-toggle:active{box-shadow:var(--neu-pressed);transform:scale(.98);}
.duty-toggle[disabled]{opacity:.6;cursor:progress;}
.duty-toggle-track{
  position:relative;width:38px;height:22px;border-radius:999px;flex:0 0 auto;
  background:rgba(169,183,198,.28);transition:background 200ms var(--ease-out);
}
.duty-toggle-knob{
  position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;
  background:#fff;transition:transform 200ms var(--ease-out);
}
.duty-card[data-on-duty="1"] .duty-toggle-track{background:var(--admin-success);}
.duty-card[data-on-duty="1"] .duty-toggle-knob{transform:translateX(16px);}
@media (prefers-reduced-motion: reduce){
  .duty-card[data-on-duty="1"] .duty-dot{animation:none;}
  .duty-toggle-track,.duty-toggle-knob{transition-duration:.01ms;}
}
@media (max-width: 560px){
  .duty-card{margin:0 6px 16px;padding:14px 16px;}
  .duty-toggle{width:100%;justify-content:center;}
}
</style>

<script>
(function () {
  var card = document.getElementById('dutyCard');
  var btn  = document.getElementById('dutyToggle');
  if (!card || !btn) { return; }

  var label = document.getElementById('dutyLabel');
  var hint  = document.getElementById('dutyHint');
  var ENDPOINT = '<?= WEB_ROOT ?>public/authenticated/api/driver_duty.php';

  function isOn() { return card.dataset.onDuty === '1'; }

  function paint(on, hintText) {
    card.dataset.onDuty = on ? '1' : '0';
    btn.setAttribute('aria-checked', on ? 'true' : 'false');
    btn.querySelector('.duty-toggle-label').textContent = on ? 'Go off duty' : 'Go on duty';
    if (label) { label.textContent = on ? 'On duty' : 'Off duty'; }
    if (hint) {
      hint.textContent = hintText || (on
        ? 'You are in the queue for the next delivery.'
        : 'Go on duty to start receiving deliveries.');
    }
  }

  function post(action) {
    return fetch(ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: 'action=' + encodeURIComponent(action)
    }).then(function (r) { return r.json(); });
  }

  btn.addEventListener('click', function () {
    var goingOn = !isOn();
    btn.disabled = true;
    post(goingOn ? 'on' : 'off')
      .then(function (d) {
        if (!d || !d.success) { throw new Error((d && d.message) || 'Could not update duty status.'); }
        paint(d.on_duty, d.message);
        // Going on duty can pull waiting orders straight onto this board, so
        // reload to show them rather than leaving a stale list behind a
        // message that says orders were assigned.
        if (d.on_duty && d.assigned > 0) {
          setTimeout(function () { window.location.href = window.location.href.split('#')[0]; }, 900);
        }
      })
      .catch(function (err) {
        if (hint) { hint.textContent = err.message || 'Network error. Try again.'; }
      })
      .finally(function () { btn.disabled = false; });
  });

  // Heartbeat. This is what lets the server tell "on duty and working" from
  // "tapped on duty three hours ago and went home" - if these stop arriving
  // the rider drops out of the assignment queue on their own. Only runs while
  // actually on duty; an off-duty rider has nothing to keep alive.
  var HEARTBEAT_MS = 120000;   // well inside the server's 10-minute window
  setInterval(function () {
    if (!isOn() || document.hidden) { return; }
    post('heartbeat').catch(function () { /* a dropped ping is not worth surfacing */ });
  }, HEARTBEAT_MS);

  // Coming back to the tab after a while: ping immediately rather than waiting
  // out the interval, so a rider who reopens the app is dispatchable at once.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && isOn()) { post('heartbeat').catch(function () {}); }
  });
})();
</script>

<!-- Pull-to-refresh. A rider checks this board between drops with one hand on
     the phone; reaching the browser's reload button means leaving the page
     chrome they are actually looking at. Dragging down from the top is the
     gesture they already expect from every other app on the device.

     Deliberately NOT a poller: the page reloads only when the rider asks it
     to, so a half-filled proof-photo form or an open modal is never wiped out
     by a background refresh. -->
<style>
  /* The indicator lives above the page and is dragged into view, so it takes
     no layout space and cannot shift the list while idle. */
  .ptr-indicator{
    position:fixed;top:0;left:50%;z-index:9998;
    width:38px;height:38px;margin-left:-19px;
    display:flex;align-items:center;justify-content:center;
    border-radius:50%;
    background:var(--admin-surface);box-shadow:var(--neu-raised-sm);
    color:var(--admin-accent);font-size:16px;
    opacity:0;pointer-events:none;
    transform:translateY(-52px);
  }
  /* No transition while the finger is down - the indicator has to track the
     drag 1:1 or it feels detached. The class is added only for the spring
     back / settle, when a transition is what you want. */
  .ptr-indicator.is-animating{transition:transform 220ms var(--ease-out,cubic-bezier(.2,.7,.3,1)),opacity 220ms ease;}
  .ptr-indicator.is-refreshing i{animation:ptr-spin .7s linear infinite;}
  @keyframes ptr-spin{to{transform:rotate(360deg);}}
  @media (prefers-reduced-motion: reduce){
    .ptr-indicator.is-animating{transition-duration:.01ms;}
    .ptr-indicator.is-refreshing i{animation-duration:2s;}
  }
</style>

<div class="ptr-indicator" id="ptrIndicator" aria-hidden="true">
  <i class="fi fi-rr-refresh"></i>
</div>

<script>
(function () {
  var indicator = document.getElementById('ptrIndicator');
  if (!indicator) { return; }

  // Touch input only. On a desktop the browser's own reload is right there,
  // and a mouse has no overscroll gesture to hook - binding this there would
  // just fight with normal scrolling.
  var isTouch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  if (!isTouch) { return; }

  var THRESHOLD = 70;    // px of pull that commits to a refresh
  var MAX_PULL  = 110;   // px past which the indicator stops following
  var startY = 0, pull = 0, tracking = false, refreshing = false;

  // A modal open means the body is scroll-locked and the rider is mid-task -
  // reloading would throw away whatever they were filling in.
  function modalOpen() {
    return document.body.style.overflow === 'hidden';
  }

  function setPull(px) {
    indicator.style.transform = 'translateY(' + (px - 52) + 'px)';
    indicator.style.opacity = Math.min(1, px / THRESHOLD).toFixed(2);
  }

  function reset(animate) {
    indicator.classList.toggle('is-animating', !!animate);
    setPull(0);
    indicator.style.opacity = '0';
    pull = 0;
    tracking = false;
  }

  document.addEventListener('touchstart', function (e) {
    if (refreshing || modalOpen() || e.touches.length !== 1) { return; }
    // Only from a genuine top-of-page rest position, otherwise this would
    // hijack the first pixel of every upward scroll in the list.
    if (window.scrollY > 0) { return; }
    startY = e.touches[0].clientY;
    tracking = true;
    pull = 0;
    indicator.classList.remove('is-animating');
  }, { passive: true });

  document.addEventListener('touchmove', function (e) {
    if (!tracking || refreshing) { return; }
    var delta = e.touches[0].clientY - startY;
    // Scrolled away from the top mid-gesture, or pulling upward: hand the
    // gesture back to the page.
    if (delta <= 0 || window.scrollY > 0) { reset(false); return; }
    // Resistance: the further it is dragged the less it moves, which is what
    // makes the threshold feel like a physical catch rather than a cliff.
    pull = Math.min(MAX_PULL, delta * 0.5);
    setPull(pull);
  }, { passive: true });

  document.addEventListener('touchend', function () {
    if (!tracking || refreshing) { return; }
    if (pull >= THRESHOLD) {
      refreshing = true;
      tracking = false;
      indicator.classList.add('is-animating', 'is-refreshing');
      setPull(THRESHOLD);
      indicator.style.opacity = '1';
      // location.reload() re-POSTs on a page that was reached by a form
      // submission; this page's actions all redirect after POST, but assigning
      // the href is unconditionally a GET and avoids the resubmit dialog.
      window.location.href = window.location.href.split('#')[0];
    } else {
      reset(true);
    }
  });

  // A cancelled touch (call, notification, system gesture) must not strand
  // the indicator on screen.
  document.addEventListener('touchcancel', function () {
    if (!refreshing) { reset(true); }
  });

  // Coming back via the bfcache leaves the page exactly as it was, spinner
  // included - clear it so the board doesn't look permanently busy.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      refreshing = false;
      indicator.classList.remove('is-refreshing');
      reset(false);
    }
  });
})();
</script>

<?php if (isDriver()): ?>
<!-- =====================================================================
     Scan to process a delivery.

     The parcel carries the order label printed at packing
     (admin/order_sticker.php - a QR holding "TWO:<qr_token>" and nothing
     else). Scanning it here is the fast path to the one order in front of
     the rider: no scrolling a board of forty rows on a doorstep, and no
     chance of confirming the wrong delivery because two customers share a
     street.

     The scan never changes a status. api/courier_scan.php resolves the label
     to a shipped delivery, assigns it to this rider if it is unclaimed (see
     claimDeliveryForDriver), and says which step it needs - then this board
     opens the modal that already owns that step:

       mark_delivered  -> openDeliveryModal()       (complete_delivery POST)
       record_payment  -> openRecordPaymentModal()  (record_payment POST)

     Proof capture is the default: a scan happens at the doorstep, so the
     photo is what the rider needs right then. Starting live tracking is a
     secondary button on the same card, for a rider who scans on the way out.

     So the status update happens on submit, in the same handlers the
     board's own buttons use - a COD order still chains Confirm Delivery ->
     Record Payment before it completes, and an already-paid online-payment
     order still completes the moment delivery is confirmed. None of the
     delivery or payment rules are duplicated here.

     There is deliberately no "type the code instead" box: the token is 64
     hex characters, unusable by hand, and a rider whose camera is broken
     still has every button on the board itself.
====================================================================== -->
<button type="button" class="scan-fab" id="scanFabBtn" title="Scan an order sticker to process that delivery" aria-label="Scan an order sticker to process that delivery">
  <img src="<?= WEB_ROOT ?>public/shared/img/icons/qr-code.gif" alt="" aria-hidden="true" class="scan-fab-icon">
</button>

<div id="courierScanModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,45,77,0.72);z-index:9999;backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;box-sizing:border-box;" role="dialog" aria-modal="true" aria-labelledby="courierScanTitle">
  <div class="modal-container" style="position:relative;width:100%;max-width:480px;max-height:92vh;margin:0;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;display:flex;flex-direction:column;">

    <div class="modal-header" style="flex-shrink:0;padding:18px 24px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <h2 id="courierScanTitle" style="color:var(--admin-text);font-size:20px;font-weight:600;margin:0;display:flex;align-items:center;gap:10px;">
        <i class="fi fi-rr-qr-code" style="color:var(--accent-cyan);"></i>
        Scan Order QR
      </h2>
      <button type="button" onclick="closeCourierScanModal()" aria-label="Close scanner" style="background:transparent;border:none;color:var(--admin-muted);font-size:22px;cursor:pointer;padding:6px;border-radius:var(--radius-sm);">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>

    <!-- position/width/height reset for the same reason every other modal on
         this page carries one: an unscoped .modal-content rule in
         shared/style.css (written for the login modal) otherwise pulls this
         out of the header/body flex flow and shrinks it to a floating box. -->
    <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;width:100%;max-width:none;height:auto;max-height:none;padding:20px 24px;overflow-y:auto;flex:1;min-height:0;">

      <div id="courierScanSection">
        <p style="color:var(--admin-muted);font-size:13px;margin:0 0 12px 0;">Point the camera at the QR code on the parcel label to open that order.</p>

        <div class="courier-scan-viewport">
          <div id="courierScanReader" class="courier-scan-reader"></div>
          <div class="courier-scan-overlay" aria-hidden="true"><div class="courier-scan-frame"></div></div>
        </div>

        <div class="courier-scan-controls">
          <button type="button" class="courier-scan-btn" id="courierScanStartBtn" onclick="courierScanStart()">
            <i class="fi fi-rs-play"></i> Start Scanner
          </button>
          <button type="button" class="courier-scan-btn courier-scan-btn--muted" id="courierScanStopBtn" onclick="courierScanStop()" style="display:none;">
            <i class="fi fi-rs-pause"></i> Stop Scanner
          </button>
        </div>

        <p class="courier-scan-status" id="courierScanStatus" aria-live="polite">Tap &quot;Start Scanner&quot; and allow camera access.</p>
      </div>

      <div class="courier-scan-result" id="courierScanResult" style="display:none;"></div>

    </div>

    <?php /* Waiting for a GPS fix is the one slow moment in this flow - up to
             35 seconds on a phone indoors - and until now the only thing on
             screen was a line of text that read like a fault ("Waiting for a
             sharper GPS fix (currently ±149m, want ±50m)"). A rider
             cannot tell a system working from a system broken by reading that.

             So the panel blurs behind this, and the spinner says the one thing
             the text could not: something is happening, wait. It is dismissed
             by the same two outcomes that end the wait - a fix, or a failure -
             and the sender guarantees one of them inside 35s, so it can never
             be left up. */ ?>
    <div class="courier-scan-busy" id="courierScanBusy" role="status" aria-live="polite" hidden>
      <img class="csb-spinner" src="<?= WEB_ROOT ?>public/shared/img/icons/loading.gif" alt="" aria-hidden="true">
      <p class="csb-title">Starting live tracking…</p>
      <p class="csb-note" id="courierScanBusyNote">Getting a GPS fix.</p>
    </div>

  </div>
</div>

<style>
/* Floating scan button. Bottom-right is free on this board - nothing else
   here is anchored to the bottom of the viewport. */
.scan-fab {
  position: fixed;
  right: 30px;
  bottom: 30px;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  /* Light surface so the qr-code.gif's white canvas can be dropped with
     mix-blend-mode:multiply, exactly like .stat-icon and .nav-icon-gif do -
     on a dark fill the icon's black linework would vanish instead. */
  background: var(--admin-surface);
  color: var(--admin-text);
  border: 2px solid var(--admin-accent);
  cursor: pointer;
  box-shadow: 0 8px 22px rgba(15, 45, 77, 0.28);
  z-index: 900;
  transition: transform var(--duration-fast, .16s) var(--ease-out, ease), box-shadow var(--duration-fast, .16s) var(--ease-out, ease);
}
.scan-fab-icon {
  width: 32px;
  height: 32px;
  object-fit: contain;
  pointer-events: none;
  mix-blend-mode: multiply;
}
.scan-fab:hover { transform: translateY(-3px) scale(1.05); box-shadow: 0 12px 28px rgba(15, 45, 77, 0.4); }
.scan-fab:active { transform: scale(0.96); }
.scan-fab:focus-visible { outline: 3px solid var(--accent-cyan); outline-offset: 3px; }
@media (prefers-reduced-motion: reduce) {
  .scan-fab { transition: none; }
  .scan-fab:hover, .scan-fab:active { transform: none; }
}

.courier-scan-viewport {
  position: relative;
  background: #000;
  border-radius: var(--radius-sm);
  overflow: hidden;
  min-height: 260px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.courier-scan-reader { width: 100%; min-height: 260px; }
#courierScanReader video, #courierScanReader canvas { width: 100% !important; object-fit: cover !important; }
/* html5-qrcode injects its own permission blurb and a file-scan link; the
   frame overlay and the status line below already say all of it, and on a
   phone the extra chrome pushes the buttons off-screen. */
#courierScanReader img[alt="Info icon"], #courierScanReader a { display: none !important; }
.courier-scan-overlay {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  pointer-events: none;
}
.courier-scan-frame {
  width: 190px; height: 190px;
  border: 3px solid var(--admin-accent);
  border-radius: 12px;
  box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.28);
}
.courier-scan-controls { display: flex; justify-content: center; gap: 12px; margin: 16px 0 0; }
.courier-scan-btn {
  background: var(--accent-cyan);
  color: #04202e;
  border: none;
  padding: 10px 20px;
  border-radius: 999px;
  cursor: pointer;
  font-weight: 700;
  font-size: 14px;
  display: inline-flex; align-items: center; gap: 8px;
  min-height: 44px;
  box-shadow: var(--neu-raised-sm);
}
.courier-scan-btn--muted { background: var(--admin-surface); color: var(--admin-text); }
.courier-scan-btn[disabled] { opacity: .55; cursor: not-allowed; }
.courier-scan-status {
  text-align: center; color: var(--admin-muted); font-size: 13px;
  padding: 10px; margin: 14px 0 0;
  background: var(--admin-surface); box-shadow: var(--neu-pressed);
  border-radius: var(--radius-sm);
}

.courier-scan-result {
  background: var(--admin-surface); box-shadow: var(--neu-pressed);
  border-radius: var(--radius-sm); padding: 16px;
  color: var(--admin-text); font-size: 14px;
}
.courier-scan-result h3 {
  margin: 0 0 4px; font-size: 17px; color: var(--admin-text);
  display: flex; align-items: center; gap: 8px;
}
.courier-scan-result .csr-lead { color: var(--admin-muted); font-size: 13px; margin: 0 0 14px; }
.courier-scan-result.is-error h3 { color: var(--admin-danger); }
.courier-scan-result.is-ok h3 { color: var(--admin-success); }
.csr-row {
  display: flex; justify-content: space-between; gap: 12px;
  padding: 6px 0; border-bottom: 1px solid var(--admin-border);
}
.csr-row:last-of-type { border-bottom: none; }
.csr-row .csr-label { color: var(--admin-muted); flex-shrink: 0; }
.csr-row .csr-value { color: var(--admin-text); font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.csr-next {
  margin: 14px 0 0; padding: 12px 14px;
  background: var(--accent-cyan-bg); border-left: 4px solid var(--accent-cyan);
  border-radius: var(--radius-sm);
  color: var(--admin-text); font-size: 13px; font-weight: 600;
}
/* A takeover moves the order off another rider, so it does not get the calm
   cyan treatment the ordinary "next step" line does. */
.csr-next--warn {
  background: rgba(194, 104, 111, 0.14);
  border-left-color: var(--admin-danger);
}
/* ---- "working on it" overlay ------------------------------------------
   Covers the whole panel (the container is position:relative) and blurs what
   is behind it rather than hiding it: the order being scanned stays visible
   and recognisable, it is just plainly not interactive yet. */
.courier-scan-busy {
  position: absolute; inset: 0; z-index: 5;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 26px; text-align: center;
}
.courier-scan-busy[hidden] { display: none; }
/* The blur lives on what is UNDER the overlay, so the spinner and its text
   stay perfectly sharp on top of it. pointer-events keeps a stray tap off the
   buttons while the fix is in flight. */
.modal-container.is-busy > .modal-header,
.modal-container.is-busy > .modal-content {
  filter: blur(3px);
  opacity: .55;
  pointer-events: none;
  transition: filter .18s ease, opacity .18s ease;
}
.csb-spinner {
  width: 88px; height: 88px; object-fit: contain;
  display: block; margin: 0 auto 14px;
}
.csb-title {
  margin: 0; font-size: 15.5px; font-weight: 700; color: var(--admin-text);
}
.csb-note {
  margin: 7px 0 0; max-width: 300px;
  font-size: 13px; line-height: 1.5; color: var(--admin-muted);
}
@media (max-width: 600px) { .csb-spinner { width: 76px; height: 76px; } }
@media (prefers-reduced-motion: reduce) {
  .modal-container.is-busy > .modal-header,
  .modal-container.is-busy > .modal-content { transition: none; }
}

/* The line under the gated Confirm Delivery button. Quieter than .csr-lead and
   sitting after the buttons rather than before them: it is a reassurance, not
   an instruction. */
.courier-scan-result .csr-hint {
  margin: 12px 0 0; font-size: 12px; line-height: 1.5; color: var(--admin-muted);
}
/* How the tracking attempt reports - waiting, running, or failed. In the card,
   because this flow never leaves the modal. */
.courier-scan-result .csr-track {
  margin: 12px 0 0; padding: 11px 13px; border-radius: var(--radius-sm);
  font-size: 12.5px; line-height: 1.5; font-weight: 600;
  background: var(--admin-surface); color: var(--admin-text);
  border-left: 4px solid var(--admin-muted);
}
.courier-scan-result .csr-track.is-ok {
  background: var(--accent-cyan-bg); border-left-color: var(--accent-cyan);
}
.courier-scan-result .csr-track.is-error {
  background: rgba(194, 104, 111, 0.14); border-left-color: var(--admin-danger);
}
.csr-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
.csr-actions .courier-scan-btn { flex: 1; justify-content: center; min-width: 140px; }

@media (max-width: 600px) {
  .scan-fab {
    right: 18px;
    bottom: calc(18px + env(safe-area-inset-bottom, 0px));
    width: 54px; height: 54px;
  }
  .scan-fab-icon { width: 28px; height: 28px; }

  /* Full-screen sheet, matching every other modal on this board. */
  #courierScanModal { padding: 0 !important; }
  #courierScanModal .modal-container {
    position: fixed !important; inset: 0 !important; margin: 0 !important;
    max-width: 100% !important; width: 100% !important;
    height: 100% !important; max-height: 100% !important;
    border-radius: 0 !important;
  }
  #courierScanModal .modal-header { padding: 14px 16px !important; }
  #courierScanModal .modal-header button {
    width: 44px; height: 44px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  }
  #courierScanModal .modal-content { padding: 16px !important; }
  .courier-scan-controls .courier-scan-btn { flex: 1; justify-content: center; }
  .csr-actions .courier-scan-btn { flex: 1 1 100%; min-height: 48px; }
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
  var qr = null;             // Html5Qrcode instance while the camera is live
  var scanning = false;
  var starting = null;       // the in-flight qr.start() promise, while there is one
  var scanGen  = 0;          // bumped by every start AND every stop - see courierScanStart
  var lastDecodeAt = 0;      // decode throttle, for cameras that never look away
  var lastDecodeText = '';
  var scanBlockedUntil = 0;  // set by a 429 - see lookup()
  var looking = false;       // a lookup is in flight - ignore further frames
  var pending = null;        // the resolved scan awaiting the rider's Continue
  var lastCode = '';         // the raw label this screen came from - a claim is
                             // authorised by the scanned token, not an order id

  function el(id) { return document.getElementById(id); }
  function modal() { return el('courierScanModal'); }
  function isOpen() { return modal().style.display === 'flex'; }
  function say(msg) { el('courierScanStatus').textContent = msg; }

  /* ---- modal open/close ------------------------------------------------ */

  window.openCourierScanModal = function () {
    showScannerView();
    modal().style.display = 'flex';
    document.body.style.overflow = 'hidden';
    courierScanStart();
  };

  // `keepScrollLock` is for the handoff path: the modal about to open sets the
  // lock itself, and clearing it here first makes the page jump behind it.
  function hide(keepScrollLock) {
    modal().style.display = 'none';
    if (!keepScrollLock) { document.body.style.overflow = 'auto'; }
    showScannerView();
  }

  window.closeCourierScanModal = function () {
    // Release the camera before the modal disappears, or the device holds the
    // stream (and its indicator light) until the page is reloaded.
    courierScanStop().then(function () { hide(false); });
  };

  function showScannerView() {
    scanBusy(false);
    var result = el('courierScanResult');
    result.style.display = 'none';
    result.className = 'courier-scan-result';
    result.innerHTML = '';
    el('courierScanSection').style.display = '';
    pending = null;
    looking = false;
  }

  function showResultView() {
    el('courierScanSection').style.display = 'none';
    el('courierScanResult').style.display = 'block';
  }

  /* ---- camera --------------------------------------------------------- */

  window.courierScanStart = async function () {
    if (scanning) { return; }

    // Every await below can resolve after a stop has already happened - a label
    // in frame decodes DURING start() (a fixed virtual-camera scene decodes on
    // the first frame), and handleDecoded stops the scanner immediately. The
    // tail of this function would then run anyway: it set `scanning = true`
    // over a `qr` that stop had already nulled, leaving the library's decode
    // loop running with no handle left to stop it. Every frame it then read
    // fired another lookup, which is how a single scan turned into the API's
    // 120-per-minute limiter answering "Too many scans in a row".
    var myGen = ++scanGen;
    var outranked = function () { return myGen !== scanGen; };
    var abandon = async function () {
      starting = null;
      if (qr) { try { await qr.stop(); } catch (e) {} try { qr.clear(); } catch (e) {} }
      qr = null;
      releaseReaderTracks();
    };

    if (!window.isSecureContext) {
      say('Camera blocked: this page must be served over HTTPS or opened on localhost.');
      return;
    }
    if (typeof Html5Qrcode === 'undefined') {
      say('The QR scanner library failed to load. Check your connection and reopen this.');
      return;
    }

    try {
      qr = new Html5Qrcode('courierScanReader');

      var cameras = [];
      try { cameras = await Html5Qrcode.getCameras(); } catch (e) { cameras = []; }
      if (outranked()) { await abandon(); return; }
      if (!cameras || cameras.length === 0) {
        say('No camera found on this device.');
        qr = null;
        return;
      }
      // Preference ORDER, not a filter. A rider's phone should use the rear
      // camera, and a virtual device (OBS, NVIDIA Broadcast, DroidCam) is
      // rarely what a rider wants - so those rank last. They are no longer
      // excluded, though, which they used to be: on a desk where OBS is
      // running, OBS owns the physical webcam, so the only camera the browser
      // can actually open IS the virtual one. Ranking it below the real
      // cameras keeps phones behaving as before; dropping it entirely meant
      // this machine had no working option at all.
      var isVirtual = function (c) { return /obs|virtual|snap camera|manycam|droidcam|iriun|epoccam/i.test(c.label || ''); };
      var rear      = function (c) { return /back|rear|environment/i.test(c.label || ''); };
      var remembered = twPreferredCameraId();

      var ranked = cameras.slice().sort(function (a, b) {
        var score = function (c) {
          if (remembered && c.id === remembered) { return 0; }   // what worked last time
          if (rear(c) && !isVirtual(c)) { return 1; }
          if (!isVirtual(c))            { return 2; }
          return 3;                                              // virtual devices
        };
        return score(a) - score(b);
      });

      // Walk the ranking until one actually STARTS. Picking a single camera
      // and giving up when it failed is what broke this on a machine running
      // OBS: the pick was the physical webcam, OBS already owned it, and the
      // virtual camera that would have worked was never reached.
      var lastStartErr = null;
      var started = null;
      for (var ci = 0; ci < ranked.length; ci++) {
        try {
          starting = qr.start(
            ranked[ci].id,
            { fps: 10, qrbox: { width: 200, height: 200 }, aspectRatio: 1.0 },
            function onDecoded(text) { handleDecoded(text); },
            function onNoCode() { /* fires every frame with no QR in view - ignore */ }
          );
          await starting;
          starting = null;
          if (outranked()) { await abandon(); return; }
          started = ranked[ci];
          break;
        } catch (e) {
          starting = null;
          lastStartErr = e;
          // A failed start can still leave the element holding a device.
          releaseReaderTracks();
        }
      }
      if (!started) { throw (lastStartErr || new Error('No camera on this device could be opened.')); }
      if (outranked()) { await abandon(); return; }
      twRememberCameraId(started.id);

      scanning = true;
      el('courierScanStartBtn').style.display = 'none';
      el('courierScanStopBtn').style.display = 'inline-flex';
      say('Scanner active (' + (started.label || 'camera') + ') - point the camera at the label QR.');
    } catch (err) {
      var text = (err && (err.name || err.message)) ? String(err.name || err.message) : String(err);
      var msg = 'Could not start the camera: ' + text;
      if (text.indexOf('NotAllowedError') !== -1) { msg = 'Camera permission denied. Allow camera access for this site, then try again.'; }
      else if (text.indexOf('NotFoundError') !== -1) { msg = 'No camera device found.'; }
      else if (text.indexOf('NotReadableError') !== -1) { msg = 'The camera is already in use by another app or tab.'; }
      say(msg);
      // A start that threw can still have opened the device before failing.
      // Dropping the instance here without stopping it was a second way to
      // strand the camera.
      starting = null;
      if (qr) { try { await qr.stop(); } catch (e) {} try { qr.clear(); } catch (e) {} }
      qr = null;
      releaseReaderTracks();
    }
  };

  // Last-resort release. html5-qrcode renders its preview into a <video> it
  // injects under #courierScanReader; if stop() never ran, or ran against an
  // instance that had already been dropped, that element is the only remaining
  // handle on the device. Stopping its tracks directly is what guarantees the
  // camera is actually free for the Confirm Delivery modal.
  function releaseReaderTracks() {
    var host = el('courierScanReader');
    if (!host) { return 0; }
    var stopped = 0;
    Array.prototype.forEach.call(host.querySelectorAll('video'), function (v) {
      var stream = v.srcObject;
      if (stream && stream.getTracks) {
        stream.getTracks().forEach(function (t) {
          try { t.stop(); stopped++; } catch (e) { /* already ended */ }
        });
      }
      v.srcObject = null;
    });
    return stopped;
  }

  // Always resolves, so callers can await it whether or not the camera was
  // ever started.
  //
  // THE LEAK THIS FIXES. The guard here used to be `if (qr && scanning)`, but
  // `scanning` is only set once qr.start() has RESOLVED - and the camera is
  // live well before that. Close the sheet (or scan a label) during the second
  // or so the preview takes to come up and the stop was skipped, while the
  // lines below still dropped the instance. The Html5Qrcode object was then
  // unreachable with its stream still running: nothing could stop it, the
  // device stayed claimed for the life of the page, and every later attempt by
  // the Confirm Delivery modal answered NotReadableError. Reloading was the
  // only cure, which is exactly the "it used to work" shape - it works until
  // the first time you close the scanner early, then never again.
  //
  // So: wait out a start that is still in flight, then stop whenever there is
  // an instance at all, then release the preview's tracks by hand regardless.
  window.courierScanStop = async function () {
    // Outranks any start still in flight, so its tail stands down instead of
    // reviving a scanner this call is tearing down.
    scanGen++;
    if (starting) { try { await starting; } catch (e) { /* start failed; still release below */ } }
    if (qr) {
      try { await qr.stop(); } catch (e) { /* never started, or already stopped */ }
      try { qr.clear(); } catch (e) { /* nothing rendered */ }
    }
    qr = null;
    starting = null;
    scanning = false;
    releaseReaderTracks();
    var startBtn = el('courierScanStartBtn');
    var stopBtn = el('courierScanStopBtn');
    if (startBtn) { startBtn.style.display = 'inline-flex'; }
    if (stopBtn) { stopBtn.style.display = 'none'; }
    // Only reset the line while the scanner view is the one on screen - a
    // result screen's "Reading label..." must not be overwritten by it.
    if (!looking && el('courierScanSection').style.display !== 'none') {
      say('Tap "Start Scanner" and allow camera access.');
    }
  };

  /* ---- lookup --------------------------------------------------------- */

  function handleDecoded(text) {
    if (looking) { return; }     // the camera decodes per frame; one lookup is enough

    // A HELD CAMERA NEVER LOOKS AWAY. A phone at a doorstep reads a label once
    // and then moves - the `looking` flag above is enough for that. A fixed
    // source is different: an OBS Virtual Camera (or NVIDIA Broadcast, or a
    // webcam propped facing a label) keeps the SAME code in frame for as long
    // as the scanner runs, so every re-entry to the scanner view - which clears
    // `looking` - decodes again on the very next frame. Rescan a few times and
    // the API's 120-per-minute limiter answers 429, which is the "Too many
    // scans in a row" the rider sees.
    //
    // So: the same code cannot be looked up twice inside 3s, and no two
    // lookups of any kind can be closer than 800ms. Neither is reachable by a
    // person actually scanning parcels; both are hit instantly by a fixed lens.
    var now = Date.now();
    // Serving out a 429. The scanner may well be running - the rider can tap
    // "Scan again" - but no decode is allowed to spend a request until the
    // limiter's window has actually passed.
    if (now < scanBlockedUntil) {
      say('Too many scans - waiting ' + Math.ceil((scanBlockedUntil - now) / 1000) + 's...');
      return;
    }
    if (text === lastDecodeText && (now - lastDecodeAt) < 3000) { return; }
    if ((now - lastDecodeAt) < 800) { return; }
    lastDecodeAt = now;
    lastDecodeText = text;

    looking = true;
    lastCode = text;
    say('Reading label...');
    // The label is read, so the scanner's job is done - shut the camera down
    // here and now, before the lookup, rather than leaving a live preview
    // running behind a result card nobody is pointing at anything. The
    // Confirm Delivery modal that follows opens its own stream, and a USB
    // webcam (or a phone's) only goes round one consumer at a time.
    //
    // showResultView() then takes the viewfinder off screen, so the scanner
    // is visibly closed as well as released.
    courierScanStop().then(function () { lookup(text); });
  }

  // Resolves once no <video> under #courierScanReader still holds a live
  // track, or after `budgetMs`, whichever comes first.
  //
  // stop() resolving is not the same as the device being free: the tracks end
  // asynchronously, and on Windows the OS can hold a USB camera for a beat
  // after the last one does. Awaiting this before handing over to the delivery
  // preview turns that race into a short, bounded wait instead of the
  // NotReadableError the rider used to get.
  async function awaitReaderReleased(budgetMs) {
    var deadline = Date.now() + (budgetMs || 1500);
    var host = el('courierScanReader');
    while (Date.now() < deadline) {
      var live = false;
      if (host) {
        Array.prototype.forEach.call(host.querySelectorAll('video'), function (v) {
          var stream = v.srcObject;
          if (stream && stream.getTracks) {
            stream.getTracks().forEach(function (t) { if (t.readyState === 'live') { live = true; } });
          }
        });
      }
      if (!live) { return true; }
      await new Promise(function (r) { setTimeout(r, 50); });
    }
    return false;
  }
  window.courierScanAwaitReleased = awaitReaderReleased;

  function lookup(code) {
    fetch('<?= WEB_ROOT ?>public/authenticated/api/courier_scan.php?action=lookup', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ code: code })
    })
      .then(function (r) {
        // The STATUS matters here, not just the body. A 429 used to arrive as
        // an ordinary error card whose "Scan again" button restarted the
        // scanner immediately - and with a label still in frame that fired
        // another lookup into an already-empty bucket, pushing the limiter's
        // window further out with every tap. The rider could not scan their way
        // out of it; only walking away for a minute worked.
        var retryAfter = 0;
        if (r.status === 429) {
          retryAfter = parseInt(r.headers.get('Retry-After') || '0', 10) || 30;
          scanBlockedUntil = Date.now() + (retryAfter * 1000);
        }
        return r.json()
          .catch(function () { return { ok: false, message: 'The server sent an unreadable response.' }; })
          .then(function (d) { d = d || {}; d.__retryAfter = retryAfter; return d; });
      })
      .then(function (data) {
        looking = false;
        if (data && data.ok) { renderReady(data); return; }
        if (data && data.__retryAfter) {
          renderError('Too many scans in a row. The scanner is paused for ' + data.__retryAfter +
                      ' seconds - scanning again before then only extends the wait. ' +
                      'If you are scanning from a fixed camera (OBS or similar), take the label ' +
                      'out of frame between scans.');
          return;
        }
        renderError((data && data.message) || 'That label could not be processed.');
      })
      .catch(function () {
        looking = false;
        renderError('Could not reach the server. Check your connection and scan again.');
      });
  }

  /* ---- result screens -------------------------------------------------- */
  // Built with createElement/textContent rather than an HTML string: customer
  // names and addresses are user input and are never markup here.

  function row(label, value) {
    var wrap = document.createElement('div');
    wrap.className = 'csr-row';
    var l = document.createElement('span');
    l.className = 'csr-label';
    l.textContent = label;
    var v = document.createElement('span');
    v.className = 'csr-value';
    v.textContent = value;
    wrap.appendChild(l);
    wrap.appendChild(v);
    return wrap;
  }

  // The Contact row, with the number itself as the Call link - the rider is
  // standing at the door when they see this screen, so tapping the number has
  // to dial rather than just display.
  function callRow(o) {
    var wrap = document.createElement('div');
    wrap.className = 'csr-row';
    var l = document.createElement('span');
    l.className = 'csr-label';
    l.textContent = 'Contact';
    wrap.appendChild(l);

    if (o.callHref) {
      var a = document.createElement('a');
      a.className = 'courier-call-btn';
      a.href = 'tel:' + o.callHref;
      a.title = 'Call ' + o.callLabel;
      a.setAttribute('aria-label', 'Call ' + (o.recipientName || o.customerName || 'the customer') + ' on ' + o.callLabel);
      var i = document.createElement('i');
      i.className = 'fi fi-rr-phone-call';
      i.setAttribute('aria-hidden', 'true');
      var s = document.createElement('span');
      s.textContent = o.callLabel;      // textContent: the number is user data
      a.appendChild(i);
      a.appendChild(s);
      wrap.appendChild(a);
    } else {
      var v = document.createElement('span');
      v.className = 'csr-value';
      v.textContent = 'No contact number';
      wrap.appendChild(v);
    }
    return wrap;
  }

  function actionButton(text, iconClass, onClick, muted) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'courier-scan-btn' + (muted ? ' courier-scan-btn--muted' : '');
    var i = document.createElement('i');
    i.className = iconClass;
    b.appendChild(i);
    b.appendChild(document.createTextNode(' ' + text));
    b.addEventListener('click', onClick);
    return b;
  }

  function resultShell(kind, titleText, iconClass, leadText) {
    var box = el('courierScanResult');
    box.className = 'courier-scan-result ' + kind;
    box.innerHTML = '';

    var h = document.createElement('h3');
    var i = document.createElement('i');
    i.className = iconClass;
    h.appendChild(i);
    h.appendChild(document.createTextNode(' ' + titleText));
    box.appendChild(h);

    if (leadText) {
      var p = document.createElement('p');
      p.className = 'csr-lead';
      p.textContent = leadText;
      box.appendChild(p);
    }
    showResultView();
    return box;
  }

  function rescanButton(muted) {
    return actionButton('Scan again', 'fi fi-rr-qr-code', function () {
      showScannerView();
      courierScanStart();
    }, muted);
  }

  function renderError(message) {
    var box = resultShell('is-error', 'Cannot process this label', 'fi fi-rr-exclamation-triangle', message);
    var actions = document.createElement('div');
    actions.className = 'csr-actions';
    actions.appendChild(rescanButton(false));
    box.appendChild(actions);
  }

  function renderReady(data) {
    var o = data.order;
    pending = data;

    var box = resultShell('is-ok', 'Order ' + o.orderNumber, 'fi fi-rr-check', null);

    box.appendChild(row('Recipient', o.recipientName || o.customerName || 'N/A'));
    box.appendChild(callRow(o));
    box.appendChild(row('Address', o.deliveryAddress || 'N/A'));
    /* Straight after the address, because it is what makes the address
       findable - the tower and unit, the gate, the landmark. Only when the
       customer actually wrote something: an empty "Customer notes" row on the
       confirm screen is noise at the exact moment the rider is scanning. */
    if ((o.deliveryNotes || '').trim()) {
      box.appendChild(row('Customer notes', o.deliveryNotes.trim()));
    }
    box.appendChild(row('Amount', o.amount));
    box.appendChild(row('Payment', data.paymentMethodLabel + ' · ' + data.paymentStatusLabel));
    box.appendChild(row('Status', data.statusLabel));

    // Whose delivery this is, stated before the rider commits - an unclaimed
    // order becomes theirs on Continue, and a takeover moves it off an
    // off-duty rider, so neither may happen silently.
    var owner = document.createElement('p');
    owner.className = 'csr-next' + (data.claim === 'takeover' ? ' csr-next--warn' : '');
    owner.textContent = data.ownershipLabel;
    box.appendChild(owner);

    var next = document.createElement('p');
    next.className = 'csr-next';
    next.textContent = 'Next: ' + data.nextStepLabel;
    box.appendChild(next);

    var actions = document.createElement('div');
    actions.className = 'csr-actions';

    if (data.trackingFirst) {
      /* Tracking is the step in front of the proof photo on a scanned order:
         scanning the label is the rider taking the parcel, and that is when
         the customer's map should start moving. It happens in this card -
         scan, track, confirm, one screen - so the rider never scans the same
         label twice to reach the step that finishes the job.

         Both buttons stay in the same places throughout: a control that moves
         under a thumb at a doorstep is a mis-tap. What changes is which one is
         live. */
      actions.appendChild(actionButton(trackLabel(data.claim, data.trackingFailed), 'fi fi-rr-navigation',
        function () { courierScanStartTracking(); }, !!data.trackingFailed));

      // Gated until tracking is running - unless tracking has already failed,
      // in which case this is the way out and nothing stands in front of it.
      var cont = actionButton(continueLabel(data.actionType, data.claim), continueIcon(data.actionType),
        data.trackingFailed ? courierScanContinue : function () {}, !data.trackingFailed);
      if (!data.trackingFailed) {
        cont.disabled = true;
        cont.title = 'Start live tracking first';
      }
      actions.appendChild(cont);
    } else {
      // Tracking is running (or this order is past the proof photo and only
      // owes cash) - the real next step leads, as it always did.
      actions.appendChild(actionButton(continueLabel(data.actionType, data.claim), continueIcon(data.actionType), courierScanContinue));
    }

    actions.appendChild(rescanButton(true));
    box.appendChild(actions);

    if (data.trackingFirst && !data.trackingFailed) {
      var hint = document.createElement('p');
      hint.className = 'csr-lead csr-hint';
      hint.textContent = 'Confirm Delivery opens the moment tracking is running - you will not need to scan again.';
      box.appendChild(hint);
    }

    // A note carried across a re-render (tracking started, or why it did not).
    if (data.trackNote) { trackStatus(data.trackNote, data.trackNoteKind); }
  }

  /* The tracking attempt reports inside the card. The board's own button uses
     a page-level pill because it leaves the page; this flow never leaves the
     modal, so the status belongs where the rider is already looking. */
  function trackStatus(msg, kind) {
    var p = el('csrTrackStatus');
    if (!p) {
      p = document.createElement('p');
      p.id = 'csrTrackStatus';
      p.className = 'csr-track';
      p.setAttribute('role', 'status');
      p.setAttribute('aria-live', 'polite');
      el('courierScanResult').appendChild(p);
    }
    p.classList.toggle('is-error', kind === 'error');
    p.classList.toggle('is-ok', kind === 'ok');
    p.textContent = msg;
  }

  /** Locks the card's buttons while the GPS fix is in flight. */
  function cardBusy(on) {
    Array.prototype.forEach.call(
      el('courierScanResult').querySelectorAll('.courier-scan-btn'),
      function (b) { b.disabled = !!on; }
    );
  }

  /* The spinner over the blurred panel. `note` is the one line that changes
     while waiting - including the sender's "waiting for a sharper fix", which
     belongs under a spinner rather than in the card where it read as a fault. */
  function scanBusy(on, note) {
    var box = el('courierScanBusy');
    var panel = modal().querySelector('.modal-container');
    if (!box || !panel) { return; }
    if (note) { el('courierScanBusyNote').textContent = note; }
    box.hidden = !on;
    panel.classList.toggle('is-busy', !!on);
  }

  /* Starts the live feed WITHOUT leaving the card.

     This used to close the modal and hand off to the board's
     startDeliveryTracking(), which confirms, gets a fix, then submits a form -
     and that form reload closed the scan flow, so the rider had to scan the
     same label a second time to reach Confirm Delivery. (It also dropped the
     GPS watch the reload had just turned on, since the watch lives in the tab.)

     Here the fix is posted with fetch, the card swaps to its next state in
     place, and the watch keeps running in this tab while the rider walks to
     the door - which is the whole point of starting it.

     Same claim-then-act shape as Continue: a rider cannot start tracking on an
     order that is not theirs (start_tracking refuses it server-side), so the
     claim has to happen first here too. */
  window.courierScanStartTracking = async function () {
    if (!pending) { return; }
    var data = pending;
    var order = data.order;

    if (data.claim === 'unassigned' || data.claim === 'takeover') {
      var ok = await claimScanned(order);
      if (!ok) { return; }       // claimScanned() has already explained why
      data.claim = 'none';
      renderReady(data);         // the buttons drop their "Take & " prefix
    }

    cardBusy(true);
    // The board's version asks this in a confirm dialog. Here it is said under
    // the spinner: a second dialog over an open modal is a tap that buys
    // nothing, and uiConfirm would fight this modal over the body's scroll lock.
    scanBusy(true, 'Getting a GPS fix - please pull over safely before using GPS controls.');

    DriverLocationSender.start(order.orderId, {
      updateUrl: '<?= WEB_ROOT ?>public/authenticated/api/update_driver_location.php',
      onWaiting: function (msg) { scanBusy(true, msg); },
      onFirstFix: function (lat, lng) {
        scanBusy(true, 'Fix acquired - starting live tracking…');
        var body = new URLSearchParams();
        body.set('action', 'start_tracking');
        body.set('order_id', String(order.orderId));
        body.set('lat', String(lat));
        body.set('lng', String(lng));
        body.set('csrf_token', scanCsrfToken());

        fetch('<?= WEB_ROOT ?>public/authenticated/courier/dispatch.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        })
          .then(function (r) { return r.json().catch(function () { return null; }); })
          .then(function (res) {
            if (!res || !res.ok) { throw new Error((res && res.message) || 'Live tracking could not be started.'); }
            // Step one is done: the card becomes the confirmation step, in
            // place. pending is still this order, so Continue goes straight
            // into the delivery modal - no second scan.
            if (!pending) { scanBusy(false); return; }
            scanBusy(false);
            pending.trackingFirst = false;
            pending.trackingFailed = false;
            pending.order.canStartTracking = false;
            pending.nextStepLabel = pending.nextStepLabelTracked || pending.nextStepLabel;
            pending.trackNote = 'Live tracking is running - the customer can see you on the map. '
                              + 'Keep this page open on the way.';
            pending.trackNoteKind = 'ok';
            renderReady(pending);
          })
          .catch(function (e) { trackingFailed(e && e.message); });
      },
      onError: function (msg) { trackingFailed(msg); }
    });
  };

  /* GPS refused, timed out, or the server would not take the fix. The rider is
     at a door either way, so the card stops gating Confirm Delivery and says
     why - rather than leaving them to find the board's own button. */
  function trackingFailed(msg) {
    scanBusy(false);
    if (!pending) { return; }
    pending.trackingFailed = true;
    pending.trackNote = (msg || 'Live tracking could not be started.')
                      + ' You can try again, or go on and confirm the delivery without it.';
    pending.trackNoteKind = 'error';
    renderReady(pending);
  }

  /** The CSRF token the page's own forms carry. */
  function scanCsrfToken() {
    var f = document.querySelector('input[name="csrf_token"]');
    return f ? f.value : '';
  }

  function continueLabel(actionType, claim) {
    // "Take &" makes the assignment side-effect part of the button's promise
    // rather than something that happens quietly behind it.
    var prefix = (claim === 'unassigned' || claim === 'takeover') ? 'Take & ' : '';
    if (actionType === 'record_payment') { return prefix + 'Record Payment'; }
    return prefix + 'Confirm Delivery';
  }
  // Same promise on the tracking button: courierScanStartTracking() claims the
  // order before it starts, exactly as Continue does. After a failed attempt
  // it is a retry, and says so.
  function trackLabel(claim, failed) {
    var prefix = (claim === 'unassigned' || claim === 'takeover') ? 'Take & ' : '';
    return failed ? 'Try Tracking Again' : (prefix + 'Start Tracking');
  }
  function continueIcon(actionType) {
    if (actionType === 'record_payment') { return 'fi fi-rr-wallet'; }
    return 'fi fi-rr-camera';
  }

  /* ---- handoff to the existing flows ---------------------------------- */
  // This is the whole point of the scanner: it processes nothing itself, it
  // opens the scanned order in the modal that already knows how. The status
  // change still happens when the rider submits that modal, through the
  // complete_delivery / record_payment handlers at the top of this file.

  // Assigns the scanned order to this rider. Resolves true only when the order
  // is genuinely theirs afterwards; on a refusal it replaces the confirm card
  // with the server's reason (two riders racing the same label is the case this
  // exists for - the loser is told, not silently dropped into the modal).
  async function claimScanned(order) {
    var btn = el('courierScanResult').querySelector('.courier-scan-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Taking...'; }

    try {
      var res = await fetch('<?= WEB_ROOT ?>public/authenticated/api/courier_scan.php?action=claim', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ code: lastCode })
      });
      var data = await res.json();
      if (data && data.ok) { return true; }
      renderError((data && data.message) || 'That delivery could not be assigned to you.');
      return false;
    } catch (e) {
      renderError('Could not reach the server, so the delivery was NOT assigned to you. Check your connection and scan again.');
      return false;
    }
  }

  window.courierScanContinue = async function () {
    if (!pending) { return; }
    var data = pending;
    var order = data.order;

    // Scanning the label means this rider is holding the parcel, so taking the
    // order is part of processing it. Claimed here, before the modal opens, for
    // two reasons: start_tracking already refuses an order that is not the
    // caller's, and a rider who fills in a proof photo only to be told the
    // delivery was never theirs has wasted the one step that cannot be redone.
    if (data.claim === 'unassigned' || data.claim === 'takeover') {
      var ok = await claimScanned(order);
      if (!ok) { return; }       // claimScanned() has already explained why
      data.claim = 'none';
    }

    pending = null;
    await courierScanStop();

    // Straight into the step the order needs. Tracking is never on this path -
    // it has its own secondary button (courierScanStartTracking).
    hide(true);
    if (data.actionType === 'record_payment') {
      openRecordPaymentModal(order.orderId, order.orderNumber, order.amountRaw);
    } else {
      openDeliveryModal(order);
    }
  };

  /* ---- wiring --------------------------------------------------------- */

  var fab = el('scanFabBtn');
  if (fab) { fab.addEventListener('click', openCourierScanModal); }

  modal().addEventListener('click', function (e) {
    if (e.target === modal()) { closeCourierScanModal(); }
  });

  // Its own Escape handler rather than joining the page's others: those all
  // test `display === 'block'` and this sheet is a flex centrer.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) { closeCourierScanModal(); }
  });

  // Leaving the page must not leave the camera running.
  window.addEventListener('pagehide', function () { courierScanStop(); });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../admin/includes/footer.php'; ?>
