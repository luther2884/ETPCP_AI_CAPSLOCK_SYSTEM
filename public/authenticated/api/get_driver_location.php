<?php
// api/get_driver_location.php - Customer polls this for their assigned
// driver's latest live GPS fix (Live Delivery Tracking feature on
// public/authenticated/customer/track_order.php). Fetch-based JSON GET
// endpoint - no CSRF check, matching the established convention for this
// style of endpoint elsewhere in api/ (see api/address_suggestions.php).
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

if (!isCustomer()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Customer accounts only.']);
    exit;
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid order.']);
    exit;
}

try {
    $pdo = getConnection();
    $userId = getUserId();

    // Ownership + eligibility re-checked fresh on every poll, never cached
    // from the page-load render in track_order.php. A failure here is the
    // expected/graceful "tracking has ended (or never applied)" signal, not
    // an error - live-tracking-map.js treats success:false as "stop polling
    // and show a static message", not a fault.
    $orderStmt = $pdo->prepare("
        SELECT o.id, o.assigned_driver_id, o.status,
               COALESCE(
                   (SELECT region FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1),
                   (SELECT region FROM user_addresses WHERE user_id = o.user_id LIMIT 1)
               ) as delivery_region,
               -- Full address for the precise drop-off pin. Same snapshot the
               -- page already prints to this customer, and the WHERE clause
               -- below pins the row to o.user_id, so this cannot leak anyone
               -- else's address.
               COALESCE(
                   o.delivery_full_address,
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1),
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o.user_id LIMIT 1)
               ) as delivery_full_address,
               -- Exact drop-off pin, when the customer set one at checkout
               -- (DBSQL/add_address_geo_pin.sql). Falls back to the pin on
               -- their saved address for orders placed before the snapshot
               -- columns existed.
               COALESCE(
                   o.delivery_latitude,
                   (SELECT latitude FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1)
               ) as delivery_latitude,
               COALESCE(
                   o.delivery_longitude,
                   (SELECT longitude FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1)
               ) as delivery_longitude
        FROM orders o
        WHERE o.id = ? AND o.user_id = ? AND o.delivery_method = 'delivery' AND o.status = 'shipped' AND o.assigned_driver_id IS NOT NULL
    ");
    $orderStmt->execute([$orderId, $userId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    // `state` is the machine-readable twin of `message`, added because the
    // client has to tell "the driver has not started sharing yet" (keep
    // polling - the map is about to come alive) apart from "this is over"
    // (stop). It used to have only the prose to go on, so live-tracking-map.js
    // treated every success:false as terminal and killed its own poll timer -
    // which meant a customer with the page already open never saw the map
    // appear, no matter how long they waited.
    if (!$order) {
        echo json_encode([
            'success' => false,
            'state' => 'ineligible',
            'message' => 'Live tracking is not currently active for this order.',
        ]);
        exit;
    }

    $trkStmt = $pdo->prepare("
        SELECT dt.latitude, dt.longitude, dt.accuracy, dt.last_updated, dt.is_tracking,
               d.firstname as driver_firstname, d.lastname as driver_lastname
        FROM delivery_tracking dt
        JOIN users d ON d.id = dt.driver_id
        WHERE dt.order_id = ?
    ");
    $trkStmt->execute([$orderId]);
    $tracking = $trkStmt->fetch(PDO::FETCH_ASSOC);

    // No row at all = the rider has not pressed Start Tracking yet. A row with
    // is_tracking = 0 = it ran and was switched off (complete_delivery does
    // exactly that). Same HTTP shape, opposite advice to the client.
    if (!$tracking) {
        echo json_encode([
            'success' => false,
            'state' => 'not_started',
            'message' => 'Your courier has not started sharing their location yet.',
        ]);
        exit;
    }
    if ((int)($tracking['is_tracking'] ?? 0) !== 1) {
        echo json_encode([
            'success' => false,
            'state' => 'ended',
            'message' => 'Live tracking has ended for this order.',
        ]);
        exit;
    }

    $response = [
        'success' => true,
        'driver' => [
            'name' => trim($tracking['driver_firstname'] . ' ' . $tracking['driver_lastname']),
            'lat' => (float)$tracking['latitude'],
            'lng' => (float)$tracking['longitude'],
            'accuracy' => $tracking['accuracy'] !== null ? (float)$tracking['accuracy'] : null,
            // Formatted server-side as a ready-to-display string, not an
            // ISO instant - PHP's default timezone on this server doesn't
            // match the DB's (MySQL stores/reads in the OS's real local
            // time; PHP's date_default_timezone_get() is misconfigured to
            // a different zone). date('c') would tag the string with the
            // wrong UTC offset, which the browser's `new Date(iso)` then
            // "correctly" mis-converts to a wrong local time. Formatting
            // straight to a display string with strtotime()+date() instead
            // keeps the same self-consistent (mis)interpretation PHP uses
            // everywhere else in this codebase, which reproduces MySQL's
            // true wall-clock value correctly without ever round-tripping
            // through an explicit timezone offset.
            'last_updated' => date('g:i:s A', strtotime($tracking['last_updated'])),
            // Age in seconds, computed server-side. The display string above
            // is deliberately not machine-readable (see the note above), so
            // the client cannot work this out for itself.
            'age_seconds' => max(0, time() - strtotime($tracking['last_updated'])),
        ],
        'store' => [
            'lat' => STORE_LAT,
            'lng' => STORE_LNG,
            'label' => STORE_ADDRESS_LABEL,
        ],
        'order_status' => $order['status'],
    ];

    // The customer's own pin, when they dropped one. This is the best
    // destination there is: it needs no geocoding and survives a misspelled
    // street, which is exactly why the feature exists.
    $pinLat = $order['delivery_latitude'] ?? null;
    $pinLng = $order['delivery_longitude'] ?? null;
    if ($pinLat !== null && $pinLng !== null && is_numeric($pinLat) && is_numeric($pinLng)) {
        $response['destination'] = [
            'lat' => (float)$pinLat,
            'lng' => (float)$pinLng,
            'label' => 'Drop-off (pinned by customer)',
            'approx' => false,
        ];
    } else {
        // Best-effort destination pin - omitted entirely (not a fabricated
        // fallback) if the customer's saved region text doesn't match anything
        // in the curated PH locations dataset.
        $dest = geocodeApproxFromRegionText($order['delivery_region'] ?? '');
        if ($dest) {
            $response['destination'] = [
                'lat' => $dest['lat'],
                'lng' => $dest['lng'],
                'label' => $dest['label'],
                'approx' => true,
            ];
        }
    }

    // The address text itself, so the map can geocode an exact drop-off pin
    // and route to the doorstep instead of to the middle of the city. Still
    // sent when a pin exists: the map shows the text as the human-readable
    // label, and only geocodes it when there is no pin to use.
    $fullAddress = trim((string)($order['delivery_full_address'] ?? ''));
    if ($fullAddress !== '' && strtoupper($fullAddress) !== 'N/A') {
        $response['destination_address'] = $fullAddress;
    }

    echo json_encode($response);
} catch (Throwable $e) {
    error_log('get_driver_location error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
