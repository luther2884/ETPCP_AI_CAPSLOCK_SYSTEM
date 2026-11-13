<?php
// api/update_driver_location.php - Driver sends their latest GPS fix for an
// assigned, active Home Delivery order (Live Delivery Tracking feature).
// Fetch-based JSON endpoint - no CSRF check, matching the established
// convention for this style of endpoint elsewhere in api/ (see
// api/cart.php, api/address_suggestions.php); auth/ownership is enforced
// below on every single call instead.
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

// Two ways to reach this endpoint:
//  - a real driver, sending their own location for their own assigned order
//  - the dev-only GPS simulator (see public/shared/assets/js/dev-gps-
//    simulator.js), run by an admin/super-admin, but ONLY when the server
//    itself is on localhost/a private IP. This never opens the endpoint up
//    on a real deployment, and a real driver/customer account can never use
//    the second branch since it also requires the admin/super-admin role.
$isRealDriver = isDriver();
$isDevSimulator = (isAdmin() || isSuperAdmin()) && isLocalDevEnvironment();
if (!$isRealDriver && !$isDevSimulator) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
$lat = isset($input['lat']) ? (float)$input['lat'] : null;
$lng = isset($input['lng']) ? (float)$input['lng'] : null;
$accuracy = isset($input['accuracy']) ? (float)$input['accuracy'] : null;

if ($orderId <= 0 || $lat === null || $lng === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}
if (abs($lat) > 90 || abs($lng) > 180) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates.']);
    exit;
}

try {
    $pdo = getConnection();

    // Re-verified fresh on every call, never trusted from a prior request:
    // the order must still be assigned to this driver (or, for the dev
    // simulator, simply exist), still a Home Delivery order, and still
    // 'shipped'. The moment any of those stops being true - delivered,
    // cancelled, reassigned - this query stops matching and further
    // location updates are rejected with no separate "stop tracking" signal
    // needed anywhere else in the system.
    if ($isRealDriver) {
        $driverId = getUserId();
        $chk = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND assigned_driver_id = ? AND status = 'shipped' AND delivery_method = 'delivery'");
        $chk->execute([$orderId, $driverId]);
    } else {
        // Dev simulator: no specific driver session to check against - use
        // whichever driver is actually assigned to the order.
        $chk = $pdo->prepare("SELECT assigned_driver_id FROM orders WHERE id = ? AND status = 'shipped' AND delivery_method = 'delivery' AND assigned_driver_id IS NOT NULL");
        $chk->execute([$orderId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        $driverId = $row['assigned_driver_id'] ?? null;
        $chk = null;
        if (!$driverId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Order is not eligible for tracking.']);
            exit;
        }
    }

    if ($isRealDriver && !$chk->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized to update location for this order.']);
        exit;
    }

    // Server-side throttle, belt-and-suspenders on top of the client-side
    // distance/time throttle in driver-location-sender.js - a runaway or
    // misbehaving client still can't write more than once per ~5s per order.
    /* The age is computed BY THE DATABASE, against its own clock.
       It used to be `time() - strtotime($lastUpdated)`, which compares a PHP
       epoch against a MySQL timestamp parsed in PHP's timezone - and the two
       do not agree here: php.ini has date.timezone = Europe/Berlin while
       MySQL writes local (+08) time. strtotime() therefore read every stored
       fix as ~6 hours in the FUTURE, the difference came out negative, and
       `negative < 5` is true - so every update after the very first one was
       throttled away and the rider's position silently stopped moving.
       TIMESTAMPDIFF keeps both sides of the comparison on the DB clock, which
       cannot drift from the column it is measuring. */
    $lastStmt = $pdo->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, last_updated, NOW()) FROM delivery_tracking WHERE order_id = ?"
    );
    $lastStmt->execute([$orderId]);
    $ageSeconds = $lastStmt->fetchColumn();
    if ($ageSeconds !== false && $ageSeconds !== null && (int)$ageSeconds < 5) {
        echo json_encode(['success' => true, 'throttled' => true]);
        exit;
    }

    $up = $pdo->prepare("
        INSERT INTO delivery_tracking (order_id, driver_id, latitude, longitude, accuracy, is_tracking)
        VALUES (?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id), latitude = VALUES(latitude), longitude = VALUES(longitude), accuracy = VALUES(accuracy), is_tracking = 1
    ");
    $up->execute([$orderId, $driverId, $lat, $lng, $accuracy]);

    /* The rider's device can begin streaming fixes without anyone pressing
       "Start live tracking" - this upsert turns is_tracking on by itself - so
       the customer has to be told from here too, or tracking that began this
       way would start silently. The helper's own guard means the notification
       is still sent exactly once however many fixes follow. */
    notifyCustomerTrackingStarted($pdo, (int)$orderId);

    echo json_encode(['success' => true, 'last_updated' => date('c')]);
} catch (Throwable $e) {
    error_log('update_driver_location error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
