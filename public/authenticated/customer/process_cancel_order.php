<?php
require_once __DIR__ . '/../../../config/functions.php';
require_login();
require_role(['customer']);

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php');
    exit;
}

$userId = getUserId();
$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$reason = trim($_POST['reason'] ?? '');
$customReason = trim($_POST['custom_reason'] ?? '');
$additionalNotes = trim($_POST['additional_notes'] ?? '');
$refundMethod = trim($_POST['refund_method'] ?? '');

// Validate input
if ($orderId <= 0 || empty($reason)) {
    $_SESSION['error_message'] = 'Invalid order or missing cancellation reason.';
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php');
    exit;
}

// If reason is "Others", custom reason is required
if ($reason === 'Others' && empty($customReason)) {
    $_SESSION['error_message'] = 'Please specify your cancellation reason.';
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId);
    exit;
}

try {
    $pdo = getConnection();
    
    // Verify order belongs to user and is cancellable. seconds_since_created
    // is computed entirely in MySQL (TIMESTAMPDIFF), never via PHP's
    // strtotime()/time() - this server's PHP default timezone doesn't match
    // the DB's real local time, so PHP-side date math here drifts by
    // several hours. That drift used to make $timeElapsed always come out
    // negative, meaning the 30-minute window below was never actually
    // enforced server-side at all (only order_success.php's now-fixed
    // display ever hid the button) - a direct POST after the real window
    // closed would have still gone through.
    $orderStmt = $pdo->prepare("
        SELECT o.id, o.status, o.delivery_method, o.created_at, o.payment_method,
               p.payment_status AS online_payment_status,
               TIMESTAMPDIFF(SECOND, o.created_at, NOW()) as seconds_since_created
        FROM orders o
        LEFT JOIN payments p ON p.order_id = o.id
        WHERE o.id = ? AND o.user_id = ?
    ");
    $orderStmt->execute([$orderId, $userId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        $_SESSION['error_message'] = 'Order not found or access denied.';
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php');
        exit;
    }

    // Same definition order_success.php uses to decide whether to show the
    // refund-method choice at all - only orders that were actually paid
    // online have any money to give back yet.
    $isOnlinePaid = in_array($order['payment_method'] ?? '', ['gcash', 'paymaya', 'xendit'], true)
        && ($order['online_payment_status'] ?? '') === 'completed';

    if ($isOnlinePaid && !in_array($refundMethod, ['original_payment', 'store_credit'], true)) {
        $_SESSION['error_message'] = 'Please choose how you\'d like to receive your money back.';
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId);
        exit;
    }
    
    // Check if order is already cancelled or completed
    if (in_array($order['status'], ['cancelled', 'completed'])) {
        $_SESSION['error_message'] = 'This order cannot be cancelled.';
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId);
        exit;
    }
    
    // Check 30-minute cancellation time limit
    $timeElapsed = max(0, (int)($order['seconds_since_created'] ?? 0));
    $cancellationWindow = 30 * 60; // 30 minutes in seconds

    if ($timeElapsed > $cancellationWindow) {
        $timeRemaining = $cancellationWindow - $timeElapsed;
        $hoursRemaining = floor(abs($timeRemaining) / 3600);
        $minutesRemaining = floor((abs($timeRemaining) % 3600) / 60);

        $_SESSION['error_message'] = "Order cancellation is no longer available. The 30-minute cancellation window has expired.";
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId);
        exit;
    }
    
    // Check if there's already a pending cancellation request
    $existingStmt = $pdo->prepare("SELECT id FROM order_cancellation_requests WHERE order_id = ? AND status = 'pending'");
    $existingStmt->execute([$orderId]);
    if ($existingStmt->fetch()) {
        $_SESSION['error_message'] = 'A cancellation request for this order is already pending.';
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId);
        exit;
    }
    
    // Insert cancellation request
    $insertStmt = $pdo->prepare("
        INSERT INTO order_cancellation_requests (order_id, user_id, reason, custom_reason, additional_notes, refund_method, refund_status, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");

    $additionalNotes = !empty($additionalNotes) ? $additionalNotes : null;
    $customReason = ($reason === 'Others') ? $customReason : null;
    // Not an online-paid order (COD/onsite, or not yet paid) - nothing was
    // actually collected yet, so there's no refund to choose or process.
    $refundMethodToStore = $isOnlinePaid ? $refundMethod : null;
    $refundStatusToStore = $isOnlinePaid ? 'pending' : 'not_applicable';

    $insertStmt->execute([
        $orderId,
        $userId,
        $reason,
        $customReason,
        $additionalNotes,
        $refundMethodToStore,
        $refundStatusToStore
    ]);
    
    // Log the action
    error_log("Cancellation request submitted for order #{$orderId} by user #{$userId}. Reason: {$reason}");
    
    $_SESSION['success_message'] = 'Your cancellation request has been submitted successfully. Our admin team will review it shortly.';
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId);
    exit;
    
} catch (Throwable $e) {
    error_log("Error processing cancellation request: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred while processing your cancellation request. Please try again.';
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $orderId);
    exit;
}
?>
