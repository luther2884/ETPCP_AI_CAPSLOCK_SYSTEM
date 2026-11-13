<?php
declare(strict_types=1);
/**
 * api/courier_scan.php - the server side of the courier's QR scan.
 *
 * The rider scans the order label stuck on the parcel (admin/order_sticker.php,
 * payload "TWO:<qr_token>"). This endpoint resolves that token to one of the
 * rider's own Home Delivery orders and reports which step of the delivery flow
 * it is sitting at, so the board can open the right modal for that exact order.
 *
 * Read-only, deliberately. Unlike api/order_scan.php - which advances an order
 * one rung up the status ladder on Confirm - nothing here writes to `orders`.
 * A courier's two remaining steps both already have their own POST handlers on
 * courier/dispatch.php:
 *
 *   shipped -> completed        complete_delivery (requires a proof photo)
 *   COD cash collected          record_payment    (requires the exact amount)
 *
 * os_next_scan_status() refuses `shipped` on purpose ("the driver completes it
 * from the courier board with a proof photo"), so a scan must not be allowed to
 * stand in for those. The scan's job is navigation: find the order, decide what
 * it needs next, hand it to the modal that already knows how to do that. The
 * status change still happens in the existing handler when the rider submits.
 *
 * Session name is TECHWRLD_CRR, not TECHWRLD_ADM - the courier area runs its
 * own session bucket (see courier/dispatch.php and api/driver_duty.php).
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CRR'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';
require_once __DIR__ . '/../../../config/rate_limit.php';

header('Content-Type: application/json');

/** Riders only. Office staff have their own scanner on the admin dashboard.
    Answered as JSON rather than with require_login()'s redirect - this is only
    ever called by fetch(), and a 302 to the HTML login page would reach the
    board as an unparseable response instead of "your session expired". */
if (!isLoggedIn() || !getUserId()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'unauthenticated', 'message' => 'Your session has expired. Reload the board and sign in again.']);
    exit;
}
if (!isDriver()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'code' => 'forbidden', 'message' => 'Delivery Portal sign-in required to scan orders.']);
    exit;
}

// Same rationale as api/driver_duty.php: this is session-authenticated and
// called by same-origin fetch, and the header cannot be set by a plain
// cross-origin form post without a CORS preflight this never grants.
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'code' => 'forbidden', 'message' => 'Invalid request.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'code' => 'method', 'message' => 'Scans must be posted.']);
    exit;
}

// A camera pointed at a wall of labels fires one lookup per decoded frame, so
// this has to be generous - but not unlimited, or a stolen session could walk
// the token space. 120/minute is far past what a real doorstep ever needs.
$rl = rl_check('courier_scan', 120, 60);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (int)$rl['retry_after']);
    echo json_encode(['ok' => false, 'code' => 'rate_limited', 'message' => 'Too many scans in a row. Wait a moment and scan again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($_GET['action'] ?? $input['action'] ?? 'lookup');
$raw    = (string)($input['code'] ?? '');

try {
    if ($action === 'lookup') {
        courier_scan_lookup(getConnection(), $raw, (int)getUserId());
    } elseif ($action === 'claim') {
        courier_scan_claim(getConnection(), $raw, (int)getUserId());
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'code' => 'unknown_action', 'message' => 'Unknown action.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('courier_scan.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'code' => 'error', 'message' => 'Something went wrong handling that scan.']);
}

/** Refusal helper - one shape for every "cannot process this label" answer. */
function courier_scan_fail(string $code, string $message, ?array $order = null): void {
    echo json_encode(['ok' => false, 'code' => $code, 'message' => $message, 'order' => $order]);
}

/**
 * Resolves a scanned label to one of this rider's deliveries and works out
 * which step it needs next. Everything returned is already shaped the way
 * courier/dispatch.php's own per-order payload is, so the scan result can be
 * handed straight to openDeliveryModal()/openRecordPaymentModal() without the
 * board having to look anything up a second time.
 */
function courier_scan_lookup(PDO $pdo, string $raw, int $driverId): void {
    $token = os_parse_scan($raw);
    if ($token === null) {
        courier_scan_fail('bad_token', 'That is not a TechWorld order label. Point the camera at the QR code on the parcel.');
        return;
    }

    // The qr_token column is created at first use (first label printed), so a
    // store that has not printed one yet would otherwise fail on the query
    // below rather than on the label itself.
    os_ensure_schema($pdo);

    // Same address/recipient resolution the dispatch board uses: the snapshot
    // taken at checkout first (what the customer actually chose for THIS
    // order), falling back to their saved address only for orders placed
    // before those columns existed.
    $stmt = $pdo->prepare("
        SELECT o.id, o.invoice_no, o.status, o.delivery_method, o.payment_method, o.payment_status,
               o.total_amount, o.tracking_code, o.created_at, o.assigned_driver_id,
               o.delivery_completed_at, o.delivery_latitude, o.delivery_longitude,
               -- The customer's own checkout note. It rides the scan response
               -- rather than the QR itself: the label carries an opaque token
               -- precisely so a photographed box gives nothing away, and a note
               -- can name a gate code or an unlocked side door.
               o.notes,
               u.firstname, u.lastname, u.email, u.phone,
               (SELECT is_tracking FROM delivery_tracking WHERE order_id = o.id) AS tracking_is_active,
               COALESCE(
                   o.delivery_full_address,
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1),
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o.user_id LIMIT 1),
                   u.address
               ) AS delivery_address,
               COALESCE(o.delivery_recipient_name, (SELECT full_name FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1)) AS recipient_name,
               COALESCE(o.delivery_phone, (SELECT phone FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1)) AS recipient_phone
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        courier_scan_fail('unknown', 'No order matches this label. It may be from another system.');
        return;
    }

    $orderId = (int)$order['id'];
    $number  = $order['invoice_no'] ?: ('ORD-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT));
    $method  = (string)($order['delivery_method'] ?: 'delivery');
    $status  = (string)$order['status'];
    $brief   = ['id' => $orderId, 'number' => $number, 'status_label' => os_label($status, $method)];

    /* ---- what this rider may do with this label ------------------------ */

    if ($method !== 'delivery') {
        courier_scan_fail('not_delivery', $number . ' is a Store Pickup order. It is completed at the counter, not on a delivery run.', $brief);
        return;
    }
    if ($status === 'cancelled') {
        courier_scan_fail('cancelled', $number . ' was cancelled. Do not hand it over - bring it back to the store.', $brief);
        return;
    }
    if ($status === 'completed') {
        courier_scan_fail('completed', $number . ' is already completed. There is nothing left to process.', $brief);
        return;
    }
    if ($status !== 'shipped') {
        // preparing / packed / ready / awaiting_payment - the parcel has not
        // been handed to a rider yet, so it is not this board's job.
        courier_scan_fail('not_shipped', $number . ' is still ' . os_label($status, $method)
            . '. It has to be scanned out for delivery at the store before you can process it here.', $brief);
        return;
    }
    /* ---- who delivers this ---------------------------------------------- */
    // Scanning the label means the rider is holding the parcel, so possession
    // decides ownership here rather than the round-robin that assigned it (or
    // failed to). See claimDeliveryForDriver() for why the capacity cap is not
    // applied to a claim. `claim` tells the board to POST action=claim before
    // it opens the modal; nothing is written by this lookup.
    $assignedTo = (int)($order['assigned_driver_id'] ?? 0);
    $claim = 'none';          // none | unassigned | takeover
    $holderName = null;

    if ($assignedTo === 0) {
        $claim = 'unassigned';
    } elseif ($assignedTo !== $driverId) {
        // Another rider holds it. Only a rider who is off duty or whose
        // heartbeat has gone stale can be taken over - live work is never
        // silently moved.
        if (driverIsOnline($assignedTo)) {
            courier_scan_fail('held', $number . ' is being delivered by another rider who is on duty. Ask dispatch to reassign it before delivering.', $brief);
            return;
        }
        $claim = 'takeover';
        $nameStmt = $pdo->prepare("SELECT CONCAT(COALESCE(firstname,''), ' ', COALESCE(lastname,'')) FROM users WHERE id = ? LIMIT 1");
        $nameStmt->execute([$assignedTo]);
        $holderName = trim((string)$nameStmt->fetchColumn()) ?: ('Rider #' . $assignedTo);
    }

    /* ---- which step it needs next -------------------------------------- */
    // Mirrors dispatch.php's own $actionType ladder, in the same order, so the
    // scanner can never reach a step the board itself would not offer.
    $isPaid = $order['payment_status'] === 'paid';
    $isCod  = $order['payment_method'] === 'cod';

    if (!empty($order['delivery_completed_at']) && $isCod && !$isPaid) {
        // Proof photo already captured, cash still outstanding - the order is
        // deliberately still 'shipped' until the payment is recorded.
        $actionType = 'record_payment';
    } else {
        $actionType = 'mark_delivered';
    }
    /* Live tracking comes FIRST on a scanned order, and the card below says
       so. The scan is the moment the rider takes possession of this parcel,
       which is the moment the customer's map should start moving - leaving it
       as an equal-weight secondary button meant it was skipped, and the
       customer watched a "shipped" order sit still until it arrived.

       Only while it would still do something: an order already streaming, or
       one whose proof photo is already captured, goes straight to its real
       next step. */
    $canStartTracking = empty($order['tracking_is_active']) && empty($order['delivery_completed_at']);
    $trackingFirst = $canStartTracking && $actionType === 'mark_delivered';

    $items = [];
    $itemStmt = $pdo->prepare("
        SELECT oi.quantity, oi.price, p.name AS product_name
        FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?");
    $itemStmt->execute([$orderId]);
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items[] = [
            'name' => $item['product_name'] ?? 'Product',
            'quantity' => (int)$item['quantity'],
            'price' => (float)($item['price'] ?? 0),
        ];
    }

    // A pinned drop-off beats the address text for navigation, exactly as the
    // board's own Navigate link decides it.
    $hasPin = is_numeric($order['delivery_latitude']) && is_numeric($order['delivery_longitude']);

    $paymentLabels = [
        'cod' => 'Cash on Delivery',
        'onsite' => 'Pay at Store',
        'gcash' => 'GCash',
        'paymaya' => 'Maya',
        'xendit' => 'Online Payment (Xendit)',
    ];
    $paymentMethod = (string)$order['payment_method'];

    // Same resolution the board uses for its Call button: whoever the customer
    // said would take this delivery, falling back to the account phone.
    $callRaw = trim((string)($order['recipient_phone'] ?? '')) !== ''
        ? (string)$order['recipient_phone']
        : (string)($order['phone'] ?? '');
    $callDigits = preg_replace('/[^0-9]/', '', $callRaw);
    $callHref = $callDigits !== ''
        ? (strpos(trim($callRaw), '+') === 0 ? '+' : '') . $callDigits
        : '';

    echo json_encode([
        'ok' => true,
        'code' => 'ready',
        'actionType' => $actionType,
        'claim' => $claim,
        'holderName' => $holderName,
        // One line for the confirm card, so the rider always knows whose
        // delivery they are about to take before they take it.
        'ownershipLabel' => $claim === 'unassigned'
            ? 'Unclaimed - confirming this assigns it to you'
            : ($claim === 'takeover'
                ? 'Held by ' . $holderName . ', who is off duty - confirming takes it over'
                : 'Assigned to you'),
        'paymentMethodLabel' => $paymentLabels[$paymentMethod] ?? strtoupper($paymentMethod),
        'paymentStatusLabel' => $isPaid ? 'Paid' : 'Unpaid',
        'statusLabel' => os_label($status, $method),
        // What the rider is about to do, spelled out on the confirm screen so
        // the scan is never a mystery button.
        'nextStepLabel' => $trackingFirst
            ? 'Start live tracking, then confirm delivery with a proof photo'
            : [
                'mark_delivered' => 'Confirm delivery with a proof photo',
                'record_payment' => 'Record the cash collected',
            ][$actionType],
        // The card makes tracking the step in front of the proof photo when
        // this is true. Sent rather than re-derived on the client so the
        // wording above and the buttons below can never disagree.
        'trackingFirst' => $trackingFirst,
        // What that line becomes once tracking is running - the card swaps to
        // it in place, without a second scan, so the wording still comes from
        // here rather than being retyped in JavaScript.
        'nextStepLabelTracked' => [
            'mark_delivered' => 'Confirm delivery with a proof photo',
            'record_payment' => 'Record the cash collected',
        ][$actionType],
        // Shaped exactly like dispatch.php's $orderDetailsPayload so the same
        // modals can consume it unchanged.
        'order' => [
            'orderId' => $orderId,
            'orderNumber' => $number,
            'trackingCode' => $order['tracking_code'] ?? '',
            'customerName' => trim(($order['firstname'] ?? '') . ' ' . ($order['lastname'] ?? '')),
            'customerEmail' => $order['email'] ?? '',
            'customerPhone' => $order['phone'] ?? '',
            'recipientName' => $order['recipient_name'] ?? '',
            'recipientPhone' => $order['recipient_phone'] ?? '',
            'deliveryAddress' => $order['delivery_address'] ?: 'N/A',
            // What the customer typed at checkout about finding the place.
            // Empty string when they left it blank - the modal decides how to
            // say that, so the wording lives in one place.
            'deliveryNotes' => trim((string)($order['notes'] ?? '')),
            'navTarget' => $hasPin ? ((float)$order['delivery_latitude'] . ',' . (float)$order['delivery_longitude']) : '',
            'items' => $items,
            'amount' => formatCurrency($order['total_amount']),
            'amountRaw' => (float)$order['total_amount'],
            'statusText' => ucfirst($status),
            'orderDate' => date('M j, Y g:i A', strtotime((string)$order['created_at'])),
            'actionType' => $actionType,
            'isPaid' => $isPaid,
            'canRecordPayment' => $isCod && !$isPaid,
            'paymentMethod' => $paymentMethod,
            'canStartTracking' => $canStartTracking,
            // Dialable number for the scan card's Call button - the delivery
            // contact for this order first, the account phone as fallback,
            // stripped to digits (a leading + is the one thing tel: keeps).
            'callHref' => $callHref,
            'callLabel' => $callRaw,
        ],
    ]);
}

/**
 * The rider takes the scanned delivery for themselves.
 *
 * The only write in this file, and it writes exactly one column -
 * assigned_driver_id. All the rules (and the row lock that makes two riders
 * scanning the same label safe) live in claimDeliveryForDriver(); this is the
 * HTTP wrapper. The order's status is untouched: it still moves only when the
 * rider submits Confirm Delivery or Record Payment on the board.
 */
function courier_scan_claim(PDO $pdo, string $raw, int $driverId): void {
    $token = os_parse_scan($raw);
    if ($token === null) {
        courier_scan_fail('bad_token', 'That is not a TechWorld order label.');
        return;
    }

    os_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $orderId = (int)$stmt->fetchColumn();
    if ($orderId <= 0) {
        courier_scan_fail('unknown', 'No order matches this label.');
        return;
    }

    $result = claimDeliveryForDriver($orderId, $driverId);
    echo json_encode([
        'ok' => (bool)$result['ok'],
        'code' => $result['code'],
        'message' => $result['message'],
        'takeover' => (bool)$result['takeover'],
        'orderId' => $orderId,
    ]);
}
