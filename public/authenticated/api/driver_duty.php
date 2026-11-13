<?php
// api/driver_duty.php - the rider's own duty switch and heartbeat.
//
// Three actions, all scoped to the calling rider - a driver can only ever
// change their own duty state, never another's, so no id is accepted from the
// client at all.
//
//   POST action=on        go on duty (and immediately pick up waiting orders)
//   POST action=off       go off duty
//   POST action=heartbeat keep-alive from the open dispatch board
//
// The heartbeat is what makes the toggle trustworthy: without it a rider who
// closed the app would stay "on duty" forever and sit at the head of the
// assignment queue holding orders nobody is out delivering. See
// DRIVER_HEARTBEAT_TIMEOUT_MINUTES in config/functions.php.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CRR'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_login();
if (!isDriver()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Riders only.']);
    exit;
}

// Same rationale as api/payment/create.php: this is a session-authenticated
// endpoint called by same-origin fetch, and the header cannot be set by a
// plain cross-origin form post without a CORS preflight this never grants.
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$driverId = (int)getUserId();
$action = strtolower(trim((string)($_POST['action'] ?? '')));

switch ($action) {
    case 'on':
    case 'off':
        $onDuty = ($action === 'on');
        if (!setDriverDuty($driverId, $onDuty)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not update your duty status.']);
            exit;
        }
        // Coming on duty is one of the moments that changes who is available,
        // so run the queue right here rather than waiting for a page load.
        $picked = $onDuty ? autoAssignPendingDeliveries() : 0;
        echo json_encode([
            'success' => true,
            'on_duty' => $onDuty,
            'assigned' => $picked,
            'message' => $onDuty
                ? ($picked > 0
                    ? "You're on duty - $picked order" . ($picked === 1 ? '' : 's') . ' assigned to you.'
                    : "You're on duty. You'll be assigned the next delivery automatically.")
                : "You're off duty. No new deliveries will be assigned.",
        ]);
        break;

    case 'heartbeat':
        touchDriverHeartbeat($driverId);
        echo json_encode([
            'success' => true,
            'online' => driverIsOnline($driverId),
            'active_deliveries' => driverActiveDeliveryCount($driverId),
        ]);
        break;

    default:
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
