<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Admin/super-admin (office staff, full visibility) and driver (Logistics/
// Delivery Personnel - see admin/logistics_login.php for how they sign in)
// can both reach this page. isDriver() gates the sidebar below down to just
// this page for drivers; the query/actions here are otherwise identical for
// every role that can reach it.
require_role(['admin', 'super-admin', 'driver']);

// Riders/couriers moved out of /admin/ into their own area. Anyone arriving
// here on an old link goes to the board that actually carries their delivery
// actions; this page is office-staff oversight.
if (isDriver()) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/courier/dispatch.php');
    exit;
}

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
$pageTitle = (function_exists('isDriver') && isDriver() ? 'Courier Dispatch' : 'Delivery Report') . ' - TechWorld';
$activeNav = 'delivery_report';
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

// Start live GPS tracking for an assigned delivery. Driver-only, and only
// for an order that's actually assigned to the driver clicking the button -
// re-verified here server-side rather than trusted from the form. This just
// records the first GPS fix synchronously with the button click (nicer UX
// than waiting on the browser's first watchPosition callback) and flips
// is_tracking on; the recurring fixes after this one go straight to
// api/update_driver_location.php from driver-location-sender.js.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_tracking') {
    if (!isDriver()) {
        $_SESSION['error_message'] = 'Only Delivery Portal accounts can start live tracking.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;

    if ($orderId <= 0) {
        $_SESSION['error_message'] = 'Invalid order.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
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

        // Same event as far as the customer is concerned, whoever pressed it.
        notifyCustomerTrackingStarted($pdo, $orderId);

        $_SESSION['success_message'] = 'Live tracking started.';
    } catch (Throwable $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
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

    // Drivers only ever see orders assigned to them - admin/super-admin keep
    // seeing the full shared queue, unchanged from before this feature.
    $isDriverRole = isDriver();
    $driverFilterSql = $isDriverRole ? " AND assigned_driver_id = :driver_id " : "";

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
               COALESCE(o2.delivery_phone, (SELECT phone FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1)) as recipient_phone
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

    // Get statistics (same driver scoping as the order list above)
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
            $driverFilterSql
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

include __DIR__ . '/includes/header.php';
// Drivers (Logistics/Delivery Personnel) get a minimal nav scoped to just
// this page - they have no access to Product/Customer/Order Management etc,
// so showing them the full admin sidebar would just be a wall of dead links.
if (function_exists('isDriver') && isDriver()) {
    include __DIR__ . '/includes/navigation_driver.php';
} else {
    include __DIR__ . '/includes/navigation.php';
}
?>

<main class="container">
  <style>
  </style>
  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href=<?= (function_exists('isDriver') && isDriver()) ? "'" . WEB_ROOT . "public/authenticated/courier/dispatch.php'" : "'Home_Delivery_Orders.php'" ?>; }">Orders</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Delivery Report</span>
      </nav>
    </div>
  </div>
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
        <div class="mobile-greeting-avatar">
          <?php if (!empty($__anAvatarRel)): ?>
            <img src="<?= WEB_ROOT ?>public/authenticated/<?= $__anAvatarRel ?>" alt="Profile">
          <?php else: ?>
            <span><?= htmlspecialchars($__anInitials) ?></span>
          <?php endif; ?>
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
        <?= isDriver() ? 'Courier Dispatch' : 'Delivery Report' ?>
      </h1>
      <p><?= isDriver()
            ? 'Your assigned deliveries. Confirm each one with a proof photo as you go.'
            : 'Manage home delivery orders and track proof of delivery.' ?></p>
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
    // Period boundaries for the Daily/Weekly/Monthly filter.
    //
    // These cannot be worked out in the browser. Every row's data-created-ts
    // is strtotime($order['created_at']), and this server's PHP timezone does
    // not match MySQL's - PHP is currently six hours behind and on the
    // previous calendar day. A boundary derived from the browser clock would
    // be compared against timestamps parsed under a different one, and orders
    // near midnight would fall into the wrong period.
    //
    // So: ask MySQL for the calendar dates (it wrote created_at, so it owns
    // the calendar), then push those date strings through the same strtotime()
    // the rows went through. Both sides of the comparison then share one
    // interpretation - the same approach get_driver_location.php documents.
    $__periods = ['day' => 0, 'week' => 0, 'month' => 0];
    $__periodLabels = ['day' => '', 'week' => '', 'month' => ''];
    try {
        $__b = getConnection()->query(
            "SELECT CURDATE() AS d,
                    DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AS w,
                    DATE_FORMAT(CURDATE(), '%Y-%m-01') AS m"
        )->fetch(PDO::FETCH_ASSOC);
        if ($__b) {
            $__periods['day']   = strtotime($__b['d'] . ' 00:00:00');
            $__periods['week']  = strtotime($__b['w'] . ' 00:00:00');
            $__periods['month'] = strtotime($__b['m'] . ' 00:00:00');
            $__td = date('M j, Y', strtotime($__b['d']));
            $__periodLabels['day']   = $__td;
            $__periodLabels['week']  = date('M j', strtotime($__b['w'])) . ' to ' . $__td;
            $__periodLabels['month'] = date('M j', strtotime($__b['m'])) . ' to ' . $__td;
        }
    } catch (Throwable $e) {
        error_log('Delivery_Report period bounds: ' . $e->getMessage());
    }
    ?>
    <script>
      window.__twPeriods = <?= json_encode(['start' => $__periods, 'label' => $__periodLabels]) ?>;
    </script>

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
          <div class="orders-filter-tabs" role="tablist" aria-label="Filter delivery orders by status">
            <button type="button" class="orders-filter-btn active" data-filter="all" role="tab" aria-selected="true">All <span class="orders-filter-count"></span></button>
            <button type="button" class="orders-filter-btn" data-filter="shipped" role="tab" aria-selected="false">Shipped <span class="orders-filter-count"></span></button>
            <button type="button" class="orders-filter-btn" data-filter="completed" role="tab" aria-selected="false">Completed <span class="orders-filter-count"></span></button>
          </div>
          <!-- Reporting period. Calendar-aligned rather than rolling, so
               "Weekly" means this week and not "the last 7 days" - a report
               that slides its own window is hard to reconcile against
               anything else. The active range is spelled out underneath so
               there is no ambiguity about where a period starts. -->
          <div class="orders-period-tabs" role="group" aria-label="Filter by period">
            <button type="button" class="orders-period-btn active" data-period="all">All time</button>
            <button type="button" class="orders-period-btn" data-period="day">Daily</button>
            <button type="button" class="orders-period-btn" data-period="week">Weekly</button>
            <button type="button" class="orders-period-btn" data-period="month">Monthly</button>
          </div>
          <div class="orders-sort-wrap">
            <label for="ordersSortSelect">Sort</label>
            <select id="ordersSortSelect" class="admin-form-input">
              <option value="newest">Newest first</option>
              <option value="oldest">Oldest first</option>
            </select>
          </div>
          <!-- Delivery notes are the per-order document; the button above
               prints this list as a table. Two different outputs, so both
               live here rather than one replacing the other. -->
          <!-- Ticks every completed order the current filters are showing,
               so "select all" means all of what is on screen, not all of what
               is in the database. -->
          <button type="button" id="dnSelectAll" class="dn-select-all">Select all</button>
          <button type="button" id="printSelectedNotes" class="admin-quick-link" style="border:none;cursor:pointer;margin:0;">
            <i class="fi fi-rr-document"></i> Print delivery notes (<span id="dnSelectedCount">0</span>)
          </button>
          <p class="orders-period-range" id="ordersPeriodRange"></p>
        </div>
      </div>

      <?php if (empty($orders)): ?>
        <div class="admin-table-empty" style="padding:40px;">
          <i class="fi fi-rr-truck" style="font-size:48px;margin-bottom:16px;display:block;"></i>
          <p style="margin:0;font-size:16px;">No delivery orders found.</p>
        </div>
      <?php else: ?>
        <div style="padding:20px;">
          <div class="pickup-grid orders-grid">
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
                // Only ever used by the dev-only GPS simulator button below
                // (admin/localhost-gated) - a best-effort city-centroid for
                // "somewhere to simulate the driver moving toward", not a
                // real destination pin.
                $devSimDest = geocodeApproxFromRegionText($order['delivery_region'] ?? '');
                if ($order['status'] === 'completed') {
                    $actionType = 'completed';
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
                } elseif (isDriver() && empty($order['tracking_is_active'])) {
                    $actionType = 'start_delivery';
                } elseif (isDriver()) {
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
                $orderItemsPayload = array_map(function ($item) {
                    return [
                        'name' => $item['product_name'] ?? 'Product',
                        'quantity' => (int)$item['quantity'],
                        'price' => (float)($item['price'] ?? 0),
                    ];
                }, $order['items']);
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
                    // Completed orders only - the print view re-checks this
                    // server-side, this just avoids offering it where it
                    // cannot work.
                    'deliveryNoteUrl' => $order['status'] === 'completed'
                        ? WEB_ROOT . 'public/authenticated/admin/delivery_note_print.php?view=note&ids=' . (int)$order['id']
                        : '',
                    // Dev-only GPS simulator (localhost + admin + assigned
                    // driver + shipped). Null everywhere else, so the modal
                    // simply renders nothing.
                    'devSim' => ((isAdmin() || isSuperAdmin()) && isLocalDevEnvironment()
                                 && !empty($order['assigned_driver_id']) && $order['status'] === 'shipped')
                        ? [
                            'orderId' => (int)$order['id'],
                            'fromLat' => STORE_LAT, 'fromLng' => STORE_LNG,
                            'toLat' => $devSimDest['lat'] ?? STORE_LAT,
                            'toLng' => $devSimDest['lng'] ?? STORE_LNG,
                          ]
                        : null,
                ];
                ?>
                <?php
                $orderSearchBlob = strtolower($orderNumber . ' ' . trim($order['firstname'] . ' ' . $order['lastname']) . ' ' . ($order['email'] ?? '') . ' ' . ($order['tracking_code'] ?? ''));
                $orderInitials = strtoupper(substr($order['firstname'] ?? '', 0, 1) . substr($order['lastname'] ?? '', 0, 1)) ?: '#';
                ?>
                <?php
                // Delivery notes are issued for completed orders only - the
                // print view re-checks this server-side, this just avoids
                // offering it where it cannot work.
                $dnPrintable = $order['status'] === 'completed';
                ?>
                <!-- Same data-* hooks the table rows carried, because the
                     filter/period/sort/search toolbar and the delivery-note
                     selection all read them straight off the element. A div
                     with role=button rather than a <button>, since the
                     delivery-note checkbox nests inside it. -->
                <div class="pickup-card orders-card" role="button" tabindex="0"
                     data-status="<?= htmlspecialchars($order['status']) ?>"
                     data-created-ts="<?= strtotime($order['created_at']) ?>"
                     data-amount="<?= (float)$order['total_amount'] ?>"
                     data-paid="<?= $order['payment_status'] === 'paid' ? '1' : '0' ?>"
                     data-search="<?= htmlspecialchars($orderSearchBlob) ?>"
                     onclick='openOrderDetailsModal(<?= json_encode($orderDetailsPayload, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                     onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }">
                  <span class="pickup-card-top">
                    <span class="pickup-card-id"><?= htmlspecialchars($orderNumber) ?></span>
                    <span class="admin-badge <?= $badgeClass ?> pickup-card-badge"><?= ucfirst($order['status']) ?></span>
                  </span>
                  <span class="pickup-card-name"><?= htmlspecialchars($order['firstname'] . ' ' . $order['lastname']) ?></span>
                  <span class="pickup-card-date"><?= $orderDateFormatted ?></span>
                  <span class="pickup-card-total">Total Amount: <?= $orderDetailsPayload['amount'] ?></span>
                  <?php if ($dnPrintable): ?>
                    <!-- stopPropagation: ticking a card for a delivery note
                         must not also open its details dialog. -->
                    <label class="dn-pick orders-card-pick" onclick="event.stopPropagation();">
                      <input type="checkbox" class="dn-pick-box" value="<?= (int)$order['id'] ?>">
                      <span>Select for delivery note</span>
                    </label>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
          </div>

          <!-- Shown by the toolbar's JS when a filter leaves nothing visible
               (e.g. a driver with no completed orders yet). -->
          <div id="ordersFilterEmptyRow" style="display:none;text-align:center;padding:32px;color:var(--admin-muted);">
            No orders match this filter.
          </div>

          <!-- Filled in by the toolbar JS: the grid pages 20 at a time (4 wide
               x 5 deep) over whatever the current filters leave showing. -->
          <nav class="pickup-pager" id="ordersPager" aria-label="Order pages"></nav>
        </div>
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
// Delivery Orders filter (by status) + period + sort + search toolbar -
// client-side, operating directly on the already-rendered
// <div class="pickup-card" data-status data-created-ts> cards (see the PHP
// loop above). Paging is client-side too, for the same reason: the counts on
// the filter tabs and "Select all" have to mean the whole result set, which
// server-side paging would silently reduce to one page's worth.
(function () {
  var currentFilter = 'all';
  var currentSort = 'newest';
  var currentSearch = ''; // fed by the mobile greeting header's search box
  var currentPeriod = 'all'; // all | day | week | month
  var currentPage = 1;
  var PER_PAGE = 20; // 4 cards across x 5 rows

  // Boundaries are handed down by PHP (see the block above the table). They
  // come from MySQL's calendar and are parsed by the same strtotime() that
  // produced every row's data-created-ts, so the comparison in matchesPeriod
  // is apples-to-apples. Deriving them from the browser clock would not be:
  // this server's PHP timezone and MySQL's disagree by six hours.
  function periodStart(period) {
    var t = (window.__twPeriods && window.__twPeriods.start) || {};
    return t[period] || null;
  }

  function periodLabel(period) {
    var l = (window.__twPeriods && window.__twPeriods.label) || {};
    return l[period] || '';
  }

  function periodName(period) {
    return { day: 'Today', week: 'This week', month: 'This month' }[period] || 'All time';
  }

  // Any filter change invalidates the current page number - page 4 of an
  // unfiltered list is meaningless once only completed orders are showing.
  function resetPage() { currentPage = 1; }

  function matchesPeriod(row) {
    var from = periodStart(currentPeriod);
    if (from === null) { return true; }
    var ts = parseInt(row.getAttribute('data-created-ts'), 10) || 0;
    return ts >= from;
  }

  function getGrid() {
    return document.querySelector('.orders-grid');
  }

  function getOrderRows() {
    var grid = getGrid();
    return grid ? Array.from(grid.querySelectorAll('[data-status]')) : [];
  }

  // Numbered pager, windowed to first/last/current +-2 so a long list never
  // grows an endless row of numbers.
  function renderPager(totalPages) {
    var nav = document.getElementById('ordersPager');
    if (!nav) return;
    nav.innerHTML = '';
    if (totalPages <= 1) { nav.style.display = 'none'; return; }
    nav.style.display = '';

    var wanted = [1, totalPages, currentPage];
    for (var i = currentPage - 2; i <= currentPage + 2; i++) { wanted.push(i); }
    var pages = wanted
      .filter(function (n, idx, arr) { return n >= 1 && n <= totalPages && arr.indexOf(n) === idx; })
      .sort(function (a, b) { return a - b; });

    function pageLink(label, target, opts) {
      var a = document.createElement('a');
      a.className = 'pickup-page' + (opts.current ? ' is-current' : '') + (opts.disabled ? ' is-disabled' : '');
      a.href = '#';
      a.textContent = label;
      if (opts.current) { a.setAttribute('aria-current', 'page'); }
      if (opts.label) { a.setAttribute('aria-label', opts.label); }
      a.addEventListener('click', function (e) {
        e.preventDefault();
        if (opts.disabled || opts.current) return;
        currentPage = target;
        applyOrdersFilterSort();
        var wrap = document.querySelector('.orders-table');
        if (wrap) { wrap.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
      return a;
    }

    nav.appendChild(pageLink('\u2039', currentPage - 1, { disabled: currentPage <= 1, label: 'Previous page' }));
    var prev = 0;
    pages.forEach(function (n) {
      if (prev && n > prev + 1) {
        var gap = document.createElement('span');
        gap.className = 'pickup-page-gap';
        gap.textContent = '\u2026';
        nav.appendChild(gap);
      }
      nav.appendChild(pageLink(String(n), n, { current: n === currentPage }));
      prev = n;
    });
    nav.appendChild(pageLink('\u203a', currentPage + 1, { disabled: currentPage >= totalPages, label: 'Next page' }));
  }

  function updateFilterCounts(rows) {
    var counts = { all: rows.length, shipped: 0, completed: 0 };
    rows.forEach(function (row) {
      var status = row.getAttribute('data-status');
      if (counts.hasOwnProperty(status)) { counts[status]++; }
    });
    document.querySelectorAll('.orders-filter-btn').forEach(function (btn) {
      var filter = btn.getAttribute('data-filter');
      var countEl = btn.querySelector('.orders-filter-count');
      if (countEl && counts.hasOwnProperty(filter)) {
        countEl.textContent = '(' + counts[filter] + ')';
      }
    });
  }

  function applyOrdersFilterSort() {
    var grid = getGrid();
    var rows = getOrderRows();
    if (!grid || rows.length === 0) return;

    // Which cards the filters allow through, in sorted order...
    var matched = rows.filter(function (row) {
      var status = row.getAttribute('data-status');
      var statusMatch = currentFilter === 'all' || status === currentFilter;
      var searchMatch = currentSearch === '' || (row.getAttribute('data-search') || '').indexOf(currentSearch) !== -1;
      return statusMatch && searchMatch && matchesPeriod(row);
    });

    rows.sort(function (a, b) {
      var ta = parseInt(a.getAttribute('data-created-ts'), 10) || 0;
      var tb = parseInt(b.getAttribute('data-created-ts'), 10) || 0;
      return currentSort === 'newest' ? (tb - ta) : (ta - tb);
    });
    rows.forEach(function (row) { grid.appendChild(row); });
    matched.sort(function (a, b) { return rows.indexOf(a) - rows.indexOf(b); });

    // ...then only this page's slice of them is actually shown. Everything
    // downstream (Select all, the counts) keys off display, so it follows.
    var visibleCount = matched.length;
    var totalPages = Math.max(1, Math.ceil(visibleCount / PER_PAGE));
    if (currentPage > totalPages) { currentPage = totalPages; }
    var from = (currentPage - 1) * PER_PAGE;
    var to = from + PER_PAGE;

    rows.forEach(function (row) { row.style.display = 'none'; });
    matched.slice(from, to).forEach(function (row) { row.style.display = ''; });

    renderPager(totalPages);

    var emptyRow = document.getElementById('ordersFilterEmptyRow');
    if (emptyRow) { emptyRow.style.display = visibleCount === 0 ? '' : 'none'; }

    updateFilterCounts(rows);

    if (typeof window.__dnSync === 'function') { window.__dnSync(); }

    var rangeEl = document.getElementById('ordersPeriodRange');
    if (rangeEl) {
      rangeEl.textContent = currentPeriod === 'all'
        ? ''
        : periodName(currentPeriod) + ': ' + periodLabel(currentPeriod) +
          ' \u00B7 ' + visibleCount + ' order' + (visibleCount === 1 ? '' : 's');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var filterBtns = document.querySelectorAll('.orders-filter-btn');
    filterBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        filterBtns.forEach(function (b) {
          b.classList.remove('active');
          b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        currentFilter = btn.getAttribute('data-filter');
        resetPage();
        applyOrdersFilterSort();
      });
    });

    var periodBtns = document.querySelectorAll('.orders-period-btn');
    periodBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        periodBtns.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        currentPeriod = btn.getAttribute('data-period');
        resetPage();
        applyOrdersFilterSort();
      });
    });

    var sortSelect = document.getElementById('ordersSortSelect');
    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        currentSort = sortSelect.value;
        resetPage();
        applyOrdersFilterSort();
      });
    }

    // Mobile greeting header's search box - live-filters the same rows the
    // status tabs above operate on, no page reload.
    var searchInput = document.getElementById('deliverySearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        currentSearch = searchInput.value.trim().toLowerCase();
        resetPage();
        applyOrdersFilterSort();
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

    // "Pending completion" highlight banner's CTA - reuses the real Shipped
    // filter tab (click(), so its own handler updates aria-selected/active
    // state correctly) instead of duplicating filter logic here.
    var pendingCta = document.getElementById('pendingBannerCta');
    if (pendingCta) {
      pendingCta.addEventListener('click', function () {
        var shippedBtn = document.querySelector('.orders-filter-btn[data-filter="shipped"]');
        if (shippedBtn) { shippedBtn.click(); }
        var table = document.querySelector('.orders-table');
        if (table) { table.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    }

    // Delivery-note selection. dnCount/dnPicked were lost when the old
    // "Print completed report" handler was removed from around them, which
    // left dnRefresh() calling two undefined names - that threw on load and
    // took the rest of this block (the filters and Select all) down with it.
    var dnCount = document.getElementById('dnSelectedCount');
    function dnPicked() {
      return Array.prototype.slice
        .call(document.querySelectorAll('.dn-pick-box:checked'))
        .map(function (b) { return b.value; });
    }
    function dnRefresh() {
      if (dnCount) { dnCount.textContent = dnPicked().length; }
    }
    // Exposed so applyOrdersFilterSort() can re-sync after a filter changes
    // which rows are on screen.
    window.__dnSync = function () { dnRefresh(); dnSyncAllLabel(); };
    document.addEventListener('change', function (e) {
      if (e.target && e.target.classList.contains('dn-pick-box')) { dnRefresh(); dnSyncAllLabel(); }
    });
    dnRefresh();
    dnSyncAllLabel();

    // Only rows the filters are actually showing can be selected - ticking a
    // hidden row would print a note the admin cannot see on screen.
    function dnVisibleBoxes() {
      return Array.prototype.slice
        .call(document.querySelectorAll('.dn-pick-box'))
        .filter(function (b) {
          var row = b.closest('[data-status]');
          return row && row.style.display !== 'none';
        });
    }

    var dnAll = document.getElementById('dnSelectAll');
    function dnSyncAllLabel() {
      if (!dnAll) { return; }
      var boxes = dnVisibleBoxes();
      var allOn = boxes.length > 0 && boxes.every(function (b) { return b.checked; });
      dnAll.textContent = allOn ? 'Clear selection' : 'Select all';
      dnAll.disabled = boxes.length === 0;
    }
    if (dnAll) {
      dnAll.addEventListener('click', function () {
        var boxes = dnVisibleBoxes();
        if (!boxes.length) { return; }
        var allOn = boxes.every(function (b) { return b.checked; });
        boxes.forEach(function (b) { b.checked = !allOn; });
        dnRefresh();
        dnSyncAllLabel();
      });
    }

    var dnBtn = document.getElementById('printSelectedNotes');
    if (dnBtn) {
      dnBtn.addEventListener('click', function () {
        var ids = dnPicked();
        if (!ids.length) {
          alert('Tick “Select” on the completed orders you want delivery notes for.');
          return;
        }
        if (ids.length > 50) {
          alert('Up to 50 delivery notes per print. Please select fewer orders.');
          return;
        }
        // view=table: selected orders print as one continuous list, not a
        // sheet each. The per-row link opts into view=note for a single
        // customer copy.
        window.open('<?= WEB_ROOT ?>public/authenticated/admin/delivery_note_print.php?view=table&ids=' +
                    encodeURIComponent(ids.join(',')), '_blank', 'noopener');
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
        <div id="odCustomerName" style="font-weight:600;color:var(--admin-text);overflow-wrap:anywhere;"></div>
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
  const phoneEl = document.getElementById('odCustomerPhone');
  if (data.customerPhone) {
    phoneEl.textContent = data.customerPhone;
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
  if (data.actionType === 'completed') {
    const btn = document.createElement('button');
    btn.disabled = true;
    btn.style.cssText = 'width:100%;min-height:48px;background:var(--admin-surface);color:var(--admin-muted);border:none;border-radius:var(--radius-sm);cursor:not-allowed;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;';
    btn.innerHTML = '<i class="fi fi-rr-check"></i> Completed';
    actionWrap.appendChild(btn);
    // The per-order delivery note used to be a link in the table's Actions
    // column; with the table gone this dialog is where it lives.
    if (data.deliveryNoteUrl) {
      const note = document.createElement('a');
      note.className = 'dn-single';
      note.target = '_blank';
      note.rel = 'noopener';
      note.href = data.deliveryNoteUrl;
      note.style.cssText = 'margin-top:10px;justify-content:center;';
      note.innerHTML = '<i class="fi fi-rr-document"></i> Delivery note';
      actionWrap.appendChild(note);
    }
  } else if (data.actionType === 'start_delivery') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'admin-quick-link';
    btn.style.cssText = 'width:100%;min-height:48px;margin:0;border:none;cursor:pointer;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;';
    btn.innerHTML = '<i class="fi fi-rr-navigation"></i> Start Delivery';
    btn.onclick = function () {
      closeOrderDetailsModal();
      startDeliveryTracking(data.orderId, data.orderNumber);
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
    // Dev-only GPS simulator - PHP sends devSim as null unless this is an
    // admin on localhost looking at a shipped order with a driver assigned,
    // so this renders nowhere else.
    if (data.devSim) {
      const sim = document.createElement('button');
      sim.type = 'button';
      sim.style.cssText = 'width:100%;background:var(--admin-surface);color:var(--admin-muted);padding:8px 10px;border:1px dashed var(--admin-border);border-radius:var(--radius-sm);cursor:pointer;font-size:12px;font-weight:600;';
      sim.innerHTML = '<i class="fi fi-rr-flask" style="margin-right:4px;"></i> Simulate (dev)';
      sim.onclick = function () {
        closeOrderDetailsModal();
        DevGpsSimulator.start(data.devSim.orderId, data.devSim.fromLat, data.devSim.fromLng, data.devSim.toLat, data.devSim.toLng);
      };
      actionWrap.appendChild(sim);
    }
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
          <label for="amount_collected" class="admin-form-label" style="font-size:14px;">
            Amount Collected (&#8369;) <span style="color:var(--admin-danger);">*</span>
          </label>
          <!-- Starts at 0, not pre-filled with the total - the rider types
               in the amount rather than blindly confirming a pre-filled
               value. Must match the order total exactly (no partial, no
               over/under, no change to give back) to submit. -->
          <input type="number" step="0.01" min="0" name="amount_collected" id="rpAmountInput" required
                 class="admin-form-input" style="width:100%;box-sizing:border-box;height:52px;font-size:18px;font-weight:600;">
          <p style="color:var(--admin-muted);font-size:12px;margin:8px 0 0 0;">Enter the exact order total collected in cash - no change is given.</p>
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
  // Starts at 0 - the rider types in the exact cash handed over, it's not
  // pre-filled with the total.
  document.getElementById('rpAmountInput').value = '0';
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
/* --- Delivery orders grid ------------------------------------------------
   The orders list used to be a wide table (8 columns, horizontally scrolled
   on anything under a laptop). It is now the same 4-across card grid the
   pickup/delivery queues use - order number, status, customer, date, total -
   with everything else in the details dialog that already existed for the
   mobile layout. The toolbar above it (status tabs, period, sort, search,
   delivery-note selection) reads the data-* attributes off the cards exactly
   as it read them off the rows. */
  html body.admin main.container{max-width:min(1560px,96vw);}
  .pickup-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;}
  .pickup-card{display:flex;flex-direction:column;gap:8px;text-align:left;width:100%;min-width:0;
    padding:24px 22px;border:none;cursor:pointer;font:inherit;
    background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);
    transition:transform var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out);}
  .pickup-card:hover{transform:translateY(-3px);}
  .pickup-card:active{transform:translateY(0);box-shadow:var(--neu-pressed);}
  .pickup-card:focus-visible{outline:2px solid var(--admin-primary-2);outline-offset:2px;}
  .pickup-card > span:not(.pickup-card-top){min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .pickup-card-top{display:flex;align-items:center;justify-content:space-between;gap:10px;min-width:0;}
  .pickup-card-id{font-size:22px;font-weight:800;color:var(--admin-text);letter-spacing:.2px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .pickup-card-badge{flex:0 0 auto;padding:4px 11px;border-radius:999px;color:var(--admin-text);
    font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;line-height:1.4;}
  .pickup-card-name{font-size:17px;font-weight:600;color:var(--admin-text);}
  .pickup-card-date{font-size:15px;color:var(--admin-muted);}
  .pickup-card-total{margin-top:8px;padding-top:12px;border-top:1px solid var(--admin-border);
    font-size:17px;font-weight:700;color:var(--admin-text);}
  @media (max-width: 1200px){ .pickup-grid{grid-template-columns:repeat(3,minmax(0,1fr));} }
  @media (max-width: 900px){ .pickup-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
  @media (max-width: 560px){ .pickup-grid{grid-template-columns:minmax(0,1fr);} }

  .pickup-filter{display:flex;align-items:center;gap:10px;margin:0;}
  .pickup-filter label{color:var(--admin-muted);font-size:15px;font-weight:700;}
  .pickup-filter select{min-width:210px;font-size:15px;}

  /* --- Page numbers ------------------------------------------------------ */
  .pickup-pager{display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin-top:26px;}
  .pickup-page{min-width:44px;height:44px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;
    border-radius:var(--radius-sm);background:var(--admin-surface-2);color:var(--admin-text);
    box-shadow:var(--neu-raised-sm);text-decoration:none;font-weight:700;font-size:15px;
    transition:filter var(--duration-fast) var(--ease-out),transform var(--duration-fast) var(--ease-out);}
  .pickup-page:hover{filter:brightness(1.08);text-decoration:none;color:var(--admin-text);}
  .pickup-page:active{transform:scale(0.97);box-shadow:var(--neu-pressed);}
  .pickup-page.is-current{background:var(--admin-primary-2);color:#f2f3f4;box-shadow:var(--neu-pressed);}
  .pickup-page.is-disabled{opacity:.4;pointer-events:none;}
  .pickup-page-gap{color:var(--admin-muted);padding:0 2px;}

/* A card here is a div (it nests the delivery-note checkbox) rather than the
   button the other queues use, so the pointer/focus affordances are explicit. */
.orders-card{cursor:pointer;}
.orders-card:focus-visible{outline:2px solid var(--admin-primary-2);outline-offset:2px;}
.orders-card .orders-card-pick{display:flex;align-items:center;gap:8px;margin:10px 0 0;padding-top:12px;
  border-top:1px solid var(--admin-border);font-size:13px;color:var(--admin-muted);cursor:pointer;}
.orders-card .orders-card-pick input{width:18px;height:18px;cursor:pointer;}
#ordersPager{margin-top:22px;}
</style>

<style>
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
  .orders-sort-wrap { justify-content: space-between; }
  .orders-sort-wrap select { flex: 1; }
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
  /* Greeting header sat 44px in from each side ON TOP of the
     container's own padding. Pull it flush; only the name/avatar
     row needs to clear the fixed hamburger pull-tab (top-left). */
  .mobile-greeting-header { margin: 0 0 18px 0 !important; }
  .mobile-greeting-top { padding-left: 42px; min-height: 44px; }

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

/* Period pills. Same shape as the status pills above them, in the muted
   accent so it is clear they are a second, independent axis. */
.orders-period-tabs { display: flex; flex-wrap: wrap; gap: 8px; }
.orders-period-btn {
  background: var(--admin-surface);
  color: var(--admin-muted);
  border: 1px solid var(--admin-border);
  border-radius: 999px;
  padding: 8px 16px;
  font-size: 13px; font-weight: 600;
  cursor: pointer; min-height: 36px;
  transition: all 0.15s ease;
}
.orders-period-btn.active {
  background: var(--admin-primary);
  color: #fff; border-color: transparent;
}
.orders-period-range {
  flex: 1 1 100%;
  margin: 0; font-size: 12px; color: var(--admin-muted); font-weight: 600;
}
.orders-period-range:empty { display: none; }

/* Delivery-note picker + per-row link. */
.dn-pick {
  display: inline-flex; align-items: center; gap: 6px; margin-bottom: 6px;
  font-size: 12px; font-weight: 600; color: var(--admin-muted);
  cursor: pointer; min-height: 32px;
}
.dn-pick input { width: 18px; height: 18px; accent-color: var(--admin-accent); }
.dn-select-all {
  background: none; border: 1px solid var(--admin-border);
  color: var(--admin-text); border-radius: var(--radius-sm);
  padding: 8px 16px; font-size: 13px; font-weight: 600;
  cursor: pointer; min-height: 36px;
}
.dn-select-all:hover { background: var(--admin-surface); }
.dn-single {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: var(--radius-sm);
  background: var(--admin-surface); color: var(--admin-text);
  box-shadow: var(--neu-raised-sm);
  font-size: 12px; font-weight: 600; text-decoration: none;
  white-space: nowrap; min-height: 32px;
}
.dn-single:hover { filter: brightness(1.08); text-decoration: none; color: var(--admin-text); }

/* ---- Print report ---------------------------------------------------
   Prints the table already on screen, so the sheet can never drift from
   what the admin is looking at.

   The hard part is not the styling, it is that an A4 portrait sheet is
   about 688 CSS px wide once margins are taken off - under this page's
   767px breakpoint. So every "phone" rule above fires while printing: the
   table collapses into blocks, data-label captions get injected, and the
   Status cell and avatar chip go position:absolute. That is what made the
   printed report overlap itself. Two defences below: landscape (which puts
   the sheet comfortably over 767px so those rules never match), and an
   explicit unwind of each one in case a browser prints portrait anyway. */
@media print {
  /* Landscape: seven columns of addresses and emails do not fit portrait,
     and the extra width also lifts the sheet above the mobile breakpoint. */
  @page { size: A4 landscape; margin: 12mm; }

  /* Chrome that means nothing on paper. */
  .admin-sidebar, .admin-sidebar-backdrop, .admin-menu-toggle,
  .admin-footer, .orders-toolbar, .dashboard-welcome, .quick-stats,
  .mobile-greeting-header, .mobile-highlight-banner, .modal-overlay,
  #adminLockOverlay, .orders-view-details-cell, .order-card-top,
  .dn-pick, .dn-single,
  td[data-label="Actions"], th:nth-child(8) {
    display: none !important;
  }

  html, body { background: #fff !important; color: #000 !important; margin: 0; padding: 0; }
  .container, main, .admin-dashboard { max-width: none !important; padding: 0 !important; margin: 0 !important; }
  .admin-table-wrap {
    box-shadow: none !important; border: 0 !important;
    background: #fff !important; overflow: visible !important;
    border-radius: 0 !important;
  }
  .admin-table-wrap > div { overflow: visible !important; padding: 0 !important; }
  /* the panel's own "Delivery Orders" heading block - the print header
     above already says what this is */
  .admin-table-wrap > div:first-child { display: none !important; }

  /* --- unwind the phone card layout ------------------------------------ */
  .orders-table table { display: table !important; width: 100% !important; table-layout: fixed !important; }
  .orders-table thead  { display: table-header-group !important; }
  .orders-table tbody  { display: table-row-group !important; }
  .orders-table tr     { display: table-row !important; }
  .orders-table th,
  .orders-table td     { display: table-cell !important; }
  .orders-table td::before { content: none !important; }   /* no injected captions */
  .orders-table tbody tr {
    position: static !important;
    margin: 0 !important; padding: 0 !important;
    background: none !important; box-shadow: none !important;
    border-radius: 0 !important; width: auto !important;
  }
  .orders-table td[data-label="Status"] {
    position: static !important; top: auto !important; right: auto !important;
  }
  .orders-table td:first-child { padding-top: 6px !important; }

  /* --- readable type --------------------------------------------------- */
  .orders-table table { border-collapse: collapse !important; font-size: 8.5pt !important; }
  .orders-table th, .orders-table td {
    border: 1px solid #999 !important;
    padding: 5px 6px !important;
    color: #000 !important; background: #fff !important;
    text-align: left !important; vertical-align: top !important;
    white-space: normal !important;
    overflow-wrap: break-word !important; word-break: normal !important;
    line-height: 1.35 !important;
  }
  .orders-table th { font-weight: 700 !important; background: #eee !important; font-size: 8pt !important; }
  .orders-table tr { page-break-inside: avoid !important; }

  /* Column widths, so long emails cannot squeeze Amount into one digit
     per line. Order#, Customer, Amount, Status, Payment, Photos, Date. */
  .orders-table th:nth-child(1), .orders-table td:nth-child(1) { width: 17% !important; }
  .orders-table th:nth-child(2), .orders-table td:nth-child(2) { width: 26% !important; }
  .orders-table th:nth-child(3), .orders-table td:nth-child(3) { width: 11% !important; text-align: right !important; }
  .orders-table th:nth-child(4), .orders-table td:nth-child(4) { width: 10% !important; }
  .orders-table th:nth-child(5), .orders-table td:nth-child(5) { width: 12% !important; }
  .orders-table th:nth-child(6), .orders-table td:nth-child(6) { width: 10% !important; }
  .orders-table th:nth-child(7), .orders-table td:nth-child(7) { width: 14% !important; }

  /* Badges and the proof link become plain text - a pill and a button both
     read as noise once there is nothing to click. */
  .admin-badge {
    border: 0 !important; padding: 0 !important; background: none !important;
    color: #000 !important; font-weight: 700 !important; font-size: 8pt !important;
  }
  .admin-badge::before { display: none !important; }
  .orders-table td button {
    border: 0 !important; background: none !important; padding: 0 !important;
    color: #000 !important; font-size: 8.5pt !important; font-weight: 400 !important;
    min-height: 0 !important;
  }
  .orders-table td button i, .orders-table td i { display: none !important; }

  /* --- report header / summary ----------------------------------------- */
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
  #odActionWrap button, #odActionWrap > span { width: 100%; }
}
</style>

<?php if (isDriver()): ?>
<script>
let deliveryCameraStream = null;
let deliveryCapturedFiles = []; // File objects, in capture order - synced into #delivery_photos via DataTransfer

function openDeliveryModal(data) {
  const modal = document.getElementById('deliveryModal');
  document.getElementById('deliveryOrderId').value = data.orderId;
  document.getElementById('deliveryOrderNumber').textContent = data.orderNumber;

  document.getElementById('deliveryRecipientName').textContent = data.recipientName || data.customerName || 'N/A';
  document.getElementById('deliveryRecipientPhone').textContent = data.recipientPhone || data.customerPhone || 'N/A';
  document.getElementById('deliveryAddress').textContent = data.deliveryAddress || 'N/A';
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

  function fail(message) {
    video.style.display = 'none';
    errText.textContent = message;
    errBox.style.display = 'block';
    captureBtn.disabled = true;
  }

  // Idempotent: this is also the Retry button's handler, so a second run must
  // release the tracks the first one took or the camera stays busy.
  stopDeliveryCamera();
  errBox.style.display = 'none';
  errText.textContent = '';
  video.style.display = 'block';
  // Stays disabled until there is a live frame to grab. Enabling it up front
  // is what let a driver tap Capture on a black preview and get nothing at
  // all, with no message either way.
  captureBtn.disabled = true;

  // Same diagnosis as courier/dispatch.php, from the same helper - see
  // shared/includes/camera-support.php. The origin case renders the https URL
  // of this page as a tappable link rather than describing it in prose.
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

  try {
    deliveryCameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
  } catch (e1) {
    try {
      deliveryCameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    } catch (e2) {
      fail('Could not access the camera (' + (e2.message || e2.name || 'permission denied') + '). Allow camera access for this site, then tap Retry camera.');
      return;
    }
  }

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
    fail('The camera started but the preview could not play (' + (e3.message || e3.name) + '). Tap Retry camera.');
    return;
  }

  // play() can resolve before the first frame's dimensions are known, and
  // videoWidth is what capture depends on. Bounded, so a stalled camera
  // leaves a message rather than a modal that never becomes usable.
  if (!video.videoWidth) {
    await new Promise(function (resolve) {
      let settled = false;
      const finish = function () { if (!settled) { settled = true; resolve(); } };
      video.addEventListener('loadedmetadata', finish, { once: true });
      setTimeout(finish, 4000);
    });
  }

  if (!video.videoWidth) {
    fail('The camera is connected but is not sending any video. Check that no other app is using it, then tap Retry camera.');
    return;
  }

  captureBtn.disabled = false;
}

function stopDeliveryCamera() {
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
    if (!blob) return;
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
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('deliveryForm');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    const fileInput = document.getElementById('delivery_photos');
    if (!fileInput.files || fileInput.files.length === 0) {
      e.preventDefault();
      alert('Please take or upload at least one delivery proof photo before confirming this delivery.');
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
async function startDeliveryTracking(orderId, orderNumber) {
  if (!(await uiConfirm('Start live location sharing for order #' + orderNumber + '?\n\nPlease pull over safely before using this feature.'))) {
    return;
  }
  DriverLocationSender.start(orderId, {
    updateUrl: '<?= WEB_ROOT ?>public/authenticated/api/update_driver_location.php',
    onFirstFix: function (lat, lng) {
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
      alert('Could not start location tracking: ' + msg);
    }
  });
}
</script>
<?php endif; ?>

<?php if ((isAdmin() || isSuperAdmin()) && !isDriver() && isLocalDevEnvironment()): ?>
<script src="<?= WEB_ROOT ?>public/shared/assets/js/dev-gps-simulator.js"></script>
<script>
  // Wired to the update endpoint here (same endpoint the real driver sender
  // uses) so DevGpsSimulator.start(orderId, storeLat, storeLng, destLat,
  // destLng) - called from the "Simulate (dev)" button above - only needs
  // coordinates, not this URL, in its inline onclick.
  DevGpsSimulator.configure('<?= WEB_ROOT ?>public/authenticated/api/update_driver_location.php');
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
