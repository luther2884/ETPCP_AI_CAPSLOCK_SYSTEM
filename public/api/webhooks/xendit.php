<?php
// public/api/webhooks/xendit.php - POST /api/webhooks/xendit
//
// Server-to-server callback from Xendit. NOT customer-authenticated - the
// x-callback-token header (checked against XENDIT_WEBHOOK_TOKEN) is the only
// authentication here, and it is verified before anything else runs.
//
// This is the ONLY place an order is ever marked PAID. The success-page
// redirect is purely cosmetic and must never be trusted for fulfillment.
declare(strict_types=1);
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/xendit.php';
require_once __DIR__ . '/../../../config/order_status.php';

header('Content-Type: application/json');

// `never` return type needs PHP 8.1; this server runs 8.0.30.
function xenditWebhookReject(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    xenditWebhookReject(405, 'Method not allowed');
}

// --- 1. Verify the callback token FIRST, before touching the body or DB. ---
$providedToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';
if (!xenditVerifyWebhookToken($providedToken)) {
    error_log('Xendit webhook rejected: invalid or missing x-callback-token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    xenditWebhookReject(401, 'Invalid webhook token');
}

// --- 2. Parse the payload. ---
$rawBody = file_get_contents('php://input');
$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) {
    error_log('Xendit webhook rejected: unparseable JSON body.');
    xenditWebhookReject(400, 'Invalid payload');
}

// Xendit's webhook shape varies a bit by product (Payment Sessions vs legacy
// Invoices), so this is read defensively rather than assuming one exact schema.
$data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
$eventType = (string)($payload['event'] ?? $data['event'] ?? '');
$status = strtoupper((string)($data['status'] ?? $payload['status'] ?? ''));
$paymentId = $data['payment_id'] ?? $data['id'] ?? $payload['id'] ?? null;
$referenceId = $data['reference_id'] ?? $payload['reference_id'] ?? null;

// A stable identifier for this specific delivery, for idempotency. Prefer
// Xendit's own event/payment id; fall back to a hash of the body so even a
// byte-identical retry with no id at all still dedupes.
$eventId = (string)($payload['id'] ?? $payload['event_id'] ?? ($paymentId ? ($eventType . ':' . $paymentId) : hash('sha256', $rawBody)));

if (!$referenceId || !preg_match('/ORDER-(\d+)/', (string)$referenceId, $m)) {
    // Not a payload we can map to an order. Acknowledge with 200 so Xendit
    // doesn't retry forever on something that will never resolve, but log it.
    error_log('Xendit webhook: could not resolve reference_id to an order: ' . json_encode($payload));
    echo json_encode(['success' => true, 'message' => 'Acknowledged (no matching order)']);
    exit;
}
$orderId = (int)$m[1];

$isSuccess = in_array($eventType, ['payment.succeeded', 'session.succeeded'], true)
    || in_array($status, ['SUCCEEDED', 'PAID', 'COMPLETED'], true);
$isFailed = in_array($eventType, ['payment.failed', 'session.failed'], true) || $status === 'FAILED';
$isExpired = in_array($eventType, ['payment.expired', 'session.expired'], true) || $status === 'EXPIRED';
$isCancelled = in_array($eventType, ['payment.cancelled', 'session.cancelled'], true) || $status === 'CANCELLED';

$pdo = null;
$sendPaymentEmail = false;
$order = null;

try {
    $pdo = getConnection();

    // --- 3. Everything below runs in ONE transaction, including the
    // idempotency-gate insert. If any later step throws, the whole thing
    // (including that insert) rolls back - so a genuine retry from Xendit
    // can still succeed later instead of being silently swallowed by a
    // dedupe row for an event that was never actually applied. ---
    $pdo->beginTransaction();

    try {
        $ins = $pdo->prepare("INSERT INTO xendit_webhook_events (event_id, payment_id, order_id, event_type, raw_payload) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$eventId, $paymentId, $orderId, $eventType ?: $status, $rawBody]);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) {
            // Already processed this exact event - roll back (nothing else
            // was written this attempt) and acknowledge without reprocessing.
            $pdo->rollBack();
            echo json_encode(['success' => true, 'message' => 'Already processed']);
            exit;
        }
        throw $e;
    }

    $orderStmt = $pdo->prepare("SELECT id, user_id, payment_method, payment_status, total_amount FROM orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        error_log("Xendit webhook: order #$orderId not found (event $eventId).");
        $pdo->commit(); // keep the event-id row so a truly unresolvable reference doesn't get retried forever
        echo json_encode(['success' => true, 'message' => 'Acknowledged (order not found)']);
        exit;
    }

    // Extra guard beyond the event-id dedupe above: if this order was already
    // marked paid by an earlier event, never re-run fulfillment side effects
    // (emails, notifications) even if a differently-shaped duplicate event
    // for the same payment slips through.
    $alreadyPaid = ($order['payment_status'] === 'paid');

    if ($isSuccess && !$alreadyPaid) {
        $upd = $pdo->prepare("UPDATE orders SET payment_status = 'paid', xendit_payment_id = ?, paid_at = NOW() WHERE id = ? AND payment_status != 'paid'");
        $upd->execute([$paymentId, $orderId]);

        $payStmt = $pdo->prepare("UPDATE payments SET payment_status = 'completed', payment_date = NOW(), transaction_id = ? WHERE order_id = ? AND payment_status != 'completed'");
        $payStmt->execute([$paymentId, $orderId]);

        // The automatic trigger behind "online orders must be paid before
        // anyone picks stock": the order was created at 'awaiting_payment'
        // (see os_initial_status() and process_order.php), and this is the
        // moment it becomes real work. Only ever moves awaiting_payment ->
        // preparing, so a Xendit retry of this same event - or a payment
        // confirmed after staff had already advanced the order by hand -
        // changes nothing. Inside the webhook's existing transaction, so the
        // release and the payment_status write land together or not at all.
        os_release_paid_order($pdo, $orderId, 'webhook');

        $sendPaymentEmail = true;
    } elseif ($isFailed) {
        $pdo->prepare("UPDATE orders SET payment_status = 'failed' WHERE id = ? AND payment_status = 'pending'")->execute([$orderId]);
        $pdo->prepare("UPDATE payments SET payment_status = 'failed' WHERE order_id = ? AND payment_status = 'pending'")->execute([$orderId]);
    } elseif ($isExpired) {
        $pdo->prepare("UPDATE orders SET payment_status = 'expired' WHERE id = ? AND payment_status = 'pending'")->execute([$orderId]);
        $pdo->prepare("UPDATE payments SET payment_status = 'cancelled' WHERE order_id = ? AND payment_status = 'pending'")->execute([$orderId]);
    } elseif ($isCancelled) {
        $pdo->prepare("UPDATE orders SET payment_status = 'cancelled' WHERE id = ? AND payment_status = 'pending'")->execute([$orderId]);
        $pdo->prepare("UPDATE payments SET payment_status = 'cancelled' WHERE order_id = ? AND payment_status = 'pending'")->execute([$orderId]);
    }
    // Any other event type (e.g. session.created) is acknowledged but causes no state change.

    $pdo->commit();

    // Best-effort confirmation email, sent only after the transaction that
    // marks the order paid has committed - and only reached once per order
    // thanks to the guards above, so this can never double-send.
    if ($sendPaymentEmail) {
        try {
            $userStmt = $pdo->prepare("SELECT firstname, lastname, email FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$order['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($user && !empty($user['email'])) {
                $fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
                $subject = 'Payment Received - TechWorld Order #' . $orderId;
                $body = '<p>Hi ' . htmlspecialchars($fullName ?: 'there') . ',</p>'
                    . '<p>We\'ve received your payment of <strong>' . formatCurrency((float)$order['total_amount']) . '</strong> via Xendit.</p>'
                    . '<p>Your order is now being prepared.</p>'
                    . '<p>— TechWorld</p>';
                sendEmail($user['email'], $fullName, $subject, $body);
            }
        } catch (Throwable $e) { /* email failure must never fail the webhook */ }
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Xendit webhook processing error: ' . $e->getMessage());
    // 500 is intentional here - it tells Xendit to retry, which is correct
    // for a transient failure on our end. Because the failure rolled back
    // the whole transaction (including the event-id insert), the retry will
    // actually reprocess instead of being swallowed by the dedupe check.
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error processing webhook']);
}
