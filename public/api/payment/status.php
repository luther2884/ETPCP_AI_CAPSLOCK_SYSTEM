<?php
// public/api/payment/status.php - GET /api/payment/status?order_id=123
//
// Read-only status check used by public/payment/success.php to poll for the
// webhook-confirmed payment outcome, without ever trusting the browser's
// own belief about whether payment succeeded.
declare(strict_types=1);
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$userId = getUserId();
$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'order_id is required.']);
    exit;
}

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT id, payment_status, total_amount, paid_at FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'payment_status' => $order['payment_status'],
        'amount' => (float)$order['total_amount'],
        'paid_at' => $order['paid_at'],
    ]);
} catch (Throwable $e) {
    error_log('payment/status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check payment status.']);
}
