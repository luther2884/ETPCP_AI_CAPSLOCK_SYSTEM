<?php
// public/api/payment/create.php - POST /api/payment/create
//
// Creates a Xendit Payment Session for an *existing* order and returns the
// hosted checkout URL. The frontend sends only an order identifier; the
// payable amount is always recomputed server-side from the orders table -
// the client-supplied amount (if any) is ignored entirely.
declare(strict_types=1);
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/xendit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// JSON-only endpoint (matches this app's existing api/cart.php, api/addresses.php
// convention): check_csrf() reads $_POST, which a JSON body never populates, so
// this relies on session auth plus X-Requested-With - a plain cross-origin form
// POST can't set a custom header or application/json content-type without a
// CORS preflight, which this endpoint doesn't grant.
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$userId = getUserId();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Accept either key - `orderId` matches the spec example, `order_id` matches
// this app's own naming convention (used by every other endpoint).
$orderId = (int)($input['order_id'] ?? $input['orderId'] ?? 0);
if ($orderId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'orderId is required.']);
    exit;
}

if (!xenditIsConfigured()) {
    error_log('Xendit payment create called but XENDIT_SECRET_KEY/XENDIT_WEBHOOK_TOKEN are not configured.');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Online payment is not available right now. Please choose another payment method.']);
    exit;
}

try {
    $pdo = getConnection();

    // Ownership check is mandatory - a customer must never be able to create
    // a payment session for someone else's order by guessing an id.
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    if (($order['payment_method'] ?? '') !== 'xendit') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This order is not set up for Xendit payment.']);
        exit;
    }

    if (($order['payment_status'] ?? 'pending') === 'paid') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This order has already been paid.']);
        exit;
    }

    if (in_array($order['status'] ?? '', ['cancelled'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This order has been cancelled and can no longer be paid.']);
        exit;
    }

    $amount = round((float)($order['total_amount'] ?? 0), 2);
    if ($amount <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid order amount.']);
        exit;
    }

    // Customer contact info for the Xendit session - orders.php doesn't store
    // these directly (this schema keeps them on users/user_addresses), so
    // fetch the same way the rest of the app already does.
    $userStmt = $pdo->prepare("SELECT firstname, lastname, email FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $addrStmt = $pdo->prepare("SELECT phone FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1");
    $addrStmt->execute([$userId]);
    $addr = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $customer = [
        'given_names' => trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: null,
        'email' => $user['email'] ?? null,
        'mobile_number' => $addr['phone'] ?? null,
    ];

    $session = xenditCreatePaymentSession($order, $customer);
    $checkoutUrl = xenditExtractCheckoutUrl($session);
    // Confirmed live: Xendit's Payment Sessions API returns `payment_session_id`,
    // not a bare `id` (unlike most other Xendit resources).
    $sessionId = $session['payment_session_id'] ?? $session['id'] ?? null;

    if (empty($checkoutUrl) || empty($sessionId)) {
        error_log('Xendit session created but missing expected fields: ' . json_encode($session));
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Payment gateway did not return a checkout link. Please try again.']);
        exit;
    }

    $upd = $pdo->prepare("UPDATE orders SET xendit_payment_session_id = ?, payment_status = 'pending' WHERE id = ?");
    $upd->execute([$sessionId, $orderId]);

    echo json_encode([
        'success' => true,
        'checkout_url' => $checkoutUrl,
        'session_id' => $sessionId,
    ]);
} catch (XenditConfigException $e) {
    error_log('Xendit config error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Online payment is not available right now. Please choose another payment method.']);
} catch (XenditApiException $e) {
    // ->getMessage() here is already the safe, human-readable message
    // extracted in xenditRequest() - never the raw upstream body.
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('payment/create.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not start payment. Please try again.']);
}
