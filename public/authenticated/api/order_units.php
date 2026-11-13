<?php
declare(strict_types=1);
/**
 * api/order_units.php - recording which physical unit goes into which order.
 *
 * This is the packing bench's endpoint. Staff have the box in their hands and
 * either type the serial off its label or pick it from the list of units on
 * the shelf; this writes that choice against the order line.
 *
 * Three actions:
 *
 *   list     read-only. The lines of one order that need serials, the units
 *            already attached, and the units available to attach. Fills the
 *            assignment panel in the order queues.
 *   assign   attach a unit by unit_id (picked from the list).
 *   scan     attach a unit by serial number or IMEI (typed or barcode-scanned
 *            off the box, which is the faster path and the one that cannot
 *            pick the wrong row by mis-clicking).
 *
 * All the rules live in config/product_units.php - this is the HTTP wrapper.
 * pu_assign_unit() takes row locks and re-checks under them, so two staff
 * assigning the same line at once cannot both win.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/product_units.php';
require_once __DIR__ . '/../../../config/rate_limit.php';

header('Content-Type: application/json');

/* Same roles as api/order_scan.php: the people who physically handle the
   boxes. Super Admin driving the admin panel arrives here as 'admin'. */
$ALLOWED_ROLES = ['admin', 'super-admin', 'owner', 'manager'];
if (!isLoggedIn() || !in_array(getUserRole(), $ALLOWED_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Staff sign-in required.']);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$pdo = getConnection();

// ------------------------------------------------------------------- list ----
if ($action === 'list') {
    $orderId = (int)($_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Which order?']);
        exit;
    }

    try {
        // Only lines whose tracking is live need anything. The same rule the
        // scan gate applies, so this panel and that gate cannot disagree about
        // whether an order is ready to go.
        $stmt = $pdo->prepare(
            "SELECT oi.id AS order_item_id, oi.product_id, oi.quantity, p.name AS product_name
               FROM order_items oi
               JOIN products p ON p.id = oi.product_id
              WHERE oi.order_id = ?
                AND p.requires_serial = 1
                AND EXISTS (SELECT 1 FROM product_units x WHERE x.product_id = p.id)
              ORDER BY oi.id"
        );
        $stmt->execute([$orderId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attached = pu_units_for_order($pdo, $orderId);

        $out = [];
        foreach ($lines as $line) {
            $itemId = (int)$line['order_item_id'];
            $mine = $attached[$itemId] ?? [];
            $out[] = [
                'order_item_id' => $itemId,
                'product_id'    => (int)$line['product_id'],
                'product_name'  => (string)$line['product_name'],
                'quantity'      => (int)$line['quantity'],
                'assigned'      => array_values(array_filter($mine, static fn($u) => in_array($u['status'], ['reserved', 'sold'], true))),
                // Everything on the shelf for this model, so staff can pick a
                // different unit from the one checkout happened to reserve.
                'available'     => pu_assignable_units($pdo, (int)$line['product_id'], $itemId),
            ];
        }

        echo json_encode([
            'ok' => true,
            'order_id' => $orderId,
            'lines' => $out,
            // What the scan gate will say. Rendered as the panel's headline so
            // staff know before they try whether the order can move on.
            'outstanding' => pu_unassigned_lines($pdo, $orderId),
        ]);
    } catch (Throwable $e) {
        error_log('order_units list: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Could not load the units for this order.']);
    }
    exit;
}

// ----------------------------------------------------------------- writes ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}
if (!check_csrf(false)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Reload the page.']);
    exit;
}

// A barcode scanner held against a sheet of labels should not be able to walk
// the serial space. Generous enough that a real packing bench never notices.
rl_guard('order_units', 120, 60, 'Too many unit changes in a row. Wait a moment.');

$orderItemId = (int)($_POST['order_item_id'] ?? 0);
if ($orderItemId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Which order line?']);
    exit;
}

try {
    if ($action === 'assign') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        if ($unitId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Pick a unit to assign.']);
            exit;
        }
        $result = pu_assign_unit($pdo, $orderItemId, $unitId);

        if ($result['ok']) {
            logAudit('order_unit_assigned', 'product_units', $unitId, null,
                ['order_item_id' => $orderItemId]);
        }
        echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
        exit;
    }

    if ($action === 'scan') {
        // The typed or barcode-scanned path. Resolving the identifier here
        // rather than in pu_assign_unit() keeps that function about one thing
        // (attaching a known unit) and lets this report the one failure that is
        // specific to scanning: a serial nothing in the catalogue knows.
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            echo json_encode(['ok' => false, 'message' => 'Scan or type the serial number.']);
            exit;
        }

        $unit = pu_find_unit($pdo, $identifier);
        if (!$unit) {
            echo json_encode(['ok' => false, 'message' => 'No unit on file with serial or IMEI "' . $identifier . '". Add it on the Unit Tracking page first.']);
            exit;
        }

        $result = pu_assign_unit($pdo, $orderItemId, (int)$unit['unit_id']);

        if ($result['ok']) {
            logAudit('order_unit_assigned', 'product_units', (int)$unit['unit_id'], null,
                ['order_item_id' => $orderItemId, 'via' => 'scan']);
        }
        echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
        exit;
    }

    if ($action === 'release') {
        // Put one reserved unit back on the shelf. The counterpart to assign:
        // staff attached the wrong box, or the unit checkout reserved is not
        // the one they are packing and they want the line empty before
        // scanning the right serial.
        $unitId = (int)($_POST['unit_id'] ?? 0);
        if ($unitId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Which unit?']);
            exit;
        }
        $result = pu_release_unit($pdo, $orderItemId, $unitId);

        if ($result['ok']) {
            logAudit('order_unit_released', 'product_units', $unitId, null,
                ['order_item_id' => $orderItemId]);
        }
        echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('order_units write: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not save that. Try again.']);
}
