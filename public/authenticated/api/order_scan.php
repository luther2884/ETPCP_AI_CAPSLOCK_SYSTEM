<?php
declare(strict_types=1);
/**
 * api/order_scan.php - the server side of the QR scan flow.
 *
 * Two actions, deliberately split so a scan is never itself a state change:
 *
 *   lookup   read-only. Resolves a scanned token to an order and works out
 *            what the next step would be. This is what fills the confirmation
 *            screen. Scanning something by accident costs nothing.
 *   advance  applies the move, once the operator has pressed Confirm. Carries
 *            `from`, the status the confirmation screen was showing, so the
 *            change can only land on the state the operator actually saw.
 *
 * All the rules (ladder, cooldown, locking) live in config/order_status.php,
 * not here - this is the HTTP wrapper around them. A second scanner posting
 * the same token cannot produce a second change: os_advance_by_scan() takes a
 * row lock and re-checks under it.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';
require_once __DIR__ . '/../../../config/rate_limit.php';

header('Content-Type: application/json');

/* Staff only. Roles match the existing scan page (scan/verify.php): the people
   who physically handle the boxes. */
$ALLOWED_ROLES = ['admin', 'super-admin', 'owner', 'manager'];
if (!isLoggedIn() || !in_array(getUserRole(), $ALLOWED_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Staff sign-in required to scan orders.']);
    exit;
}

// A scanner pointed at a wall of labels, or a script, should not be able to
// walk the token space. Generous enough that a real packing bench never
// notices: a lookup every second all day is under this.
rl_guard('order_scan', 120, 60, 'Too many scans in a row. Wait a moment and scan again.');

$pdo = getConnection();
$userId = (int)getUserId();
$userData = function_exists('getUserData') ? getUserData() : null;
$actorName = $userData
    ? trim(($userData['firstname'] ?? '') . ' ' . ($userData['lastname'] ?? ''))
    : null;

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($_GET['action'] ?? $input['action'] ?? '');
$raw    = (string)($input['code'] ?? $_POST['code'] ?? '');

try {
    switch ($action) {
        case 'lookup':   handle_lookup($pdo, $raw); break;
        case 'advance':  handle_advance($pdo, $raw, $userId, $actorName, $input); break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('order_scan.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Something went wrong handling that scan.']);
}

/** Everything the confirmation screen needs, and nothing that changes state. */
function handle_lookup(PDO $pdo, string $raw): void {
    $token = os_parse_scan($raw);
    if ($token === null) {
        echo json_encode(['ok' => false, 'code' => 'bad_token',
            'message' => 'That is not a TechWorld order label.']);
        return;
    }

    $order = os_order_by_token($pdo, $token);
    if (!$order) {
        echo json_encode(['ok' => false, 'code' => 'unknown',
            'message' => 'No order matches this label. It may be from another system.']);
        return;
    }

    $method  = (string)($order['delivery_method'] ?: 'delivery');
    $current = (string)$order['status'];
    $step    = os_next_scan_status($current, $method);

    // Reported rather than enforced here - os_advance_by_scan() re-checks it
    // under the row lock, which is the check that actually counts. This is
    // only so the screen can say "wait 12 more seconds" instead of failing at
    // the moment the operator presses Confirm.
    $since = os_seconds_since_last_change($pdo, (int)$order['id']);
    $cooling = $since !== null && $since < OS_SCAN_COOLDOWN_SECONDS;

    // Serialised lines with no unit recorded yet, for a preparing -> ready
    // scan. Reported here for exactly the same reason the cooldown above is:
    // so the confirmation screen can say what is wrong instead of inviting the
    // operator to press Confirm and then refusing them. os_advance_by_scan()
    // re-checks it under the row lock, which is the check that counts - this
    // one is only ever advisory and can safely be out of date by a second.
    $needSerial = ($current === 'preparing' && $step['next'] === 'ready')
        ? pu_unassigned_lines($pdo, (int)$order['id'])
        : [];

    echo json_encode([
        'ok' => $step['next'] !== null && !$cooling && !$needSerial,
        'code' => $cooling
            ? 'cooldown'
            : ($step['next'] === null ? 'no_step' : ($needSerial ? 'needs_serial' : 'ready')),
        'order' => [
            'id' => (int)$order['id'],
            'number' => $order['invoice_no'] ?: ('ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT)),
            'customer' => trim((string)$order['customer_name']) ?: 'Guest',
            'delivery_method' => $method,
            'payment_method' => $order['payment_method'],
            'payment_status' => $order['payment_status'],
            'total' => (float)$order['total_amount'],
        ],
        'current' => ['key' => $current, 'label' => os_label($current, $method)],
        'next' => $step['next'] ? ['key' => $step['next'], 'label' => os_label($step['next'], $method)] : null,
        'message' => $cooling
            ? 'This order was updated ' . $since . ' second(s) ago. Wait '
              . (OS_SCAN_COOLDOWN_SECONDS - $since) . ' more second(s).'
            : ($needSerial
                ? pu_missing_serials_sentence($needSerial)
                : ($step['reason'] ?? '')),
        'cooldown_remaining' => $cooling ? (OS_SCAN_COOLDOWN_SECONDS - $since) : 0,
    ]);
}

/** Applies the move the operator confirmed. */
function handle_advance(PDO $pdo, string $raw, int $userId, ?string $actorName, array $input): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Confirming a scan must be a POST.']);
        return;
    }

    // The status the confirmation screen was showing. os_advance_by_scan()
    // refuses if the order has moved since - so a stale tab, or a colleague
    // who scanned the same box first, cannot cause a second step.
    $from = isset($input['from']) ? (string)$input['from'] : null;

    $result = os_advance_by_scan($pdo, $raw, $userId, $actorName, $from);

    if ($result['ok']) {
        $order = $result['order'];
        $method = (string)($order['delivery_method'] ?: 'delivery');
        logAudit('order_status_scan', 'orders', (int)$order['id'],
            ['status' => $result['old']], ['status' => $result['new'], 'source' => 'scan']);

        echo json_encode([
            'ok' => true, 'code' => 'advanced', 'message' => $result['message'],
            'order' => [
                'id' => (int)$order['id'],
                'number' => $order['invoice_no'] ?: ('ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT)),
                'customer' => trim((string)$order['customer_name']) ?: 'Guest',
            ],
            'old' => ['key' => $result['old'], 'label' => os_label((string)$result['old'], $method)],
            'new' => ['key' => $result['new'], 'label' => os_label((string)$result['new'], $method)],
        ]);
        return;
    }

    echo json_encode(['ok' => false, 'code' => $result['code'], 'message' => $result['message']]);
}
