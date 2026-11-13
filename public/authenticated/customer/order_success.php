<?php
require_once __DIR__ . '/../../../config/functions.php';
// Arrival estimate, worked out from the delivery pin / postal code against the
// branch. Deterministic and offline - see config/delivery_estimate.php.
require_once __DIR__ . '/../../../config/delivery_estimate.php';
require_login();
require_role(['customer']);

$userId = getUserId();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php');
    exit;
}

try {
    $pdo = getConnection();
    // seconds_since_created is computed entirely in MySQL (TIMESTAMPDIFF),
    // never via PHP's strtotime()/time() - this server's PHP default
    // timezone doesn't match the DB's real local time, so PHP-side date
    // math here drifts by several hours (same class of bug already hit and
    // fixed for the live-tracking "Last updated" time and the Xendit
    // pay-by countdown on orders.php). That drift is exactly why this
    // page's 30-minute cancellation timer always restarted at the full
    // 30:00 on every reload instead of actually counting down.
    $st = $pdo->prepare("SELECT o.*, p.payment_status AS online_payment_status,
                                 TIMESTAMPDIFF(SECOND, o.created_at, NOW()) as seconds_since_created,
                                 -- The DB's own date, for the arrival estimate: it re-bases a
                                 -- window that has already gone past, and PHP's today is the
                                 -- wrong day here (same drift as seconds_since_created above).
                                 CURDATE() as db_today
                          FROM orders o
                          LEFT JOIN payments p ON p.order_id = o.id
                          WHERE o.id = ? AND o.user_id = ?");
    $st->execute([$orderId, $userId]);
    $order = $st->fetch();
    if (!$order) { header('Location: orders.php'); exit; }

    // Online payments (GCash/Maya/Xendit) that are already confirmed paid
    // don't need the self-service cancellation window - cancelling a paid
    // order has to go through the admin refund/cancellation review instead.
    $isOnlinePaid = in_array($order['payment_method'] ?? '', ['gcash', 'paymaya', 'xendit'], true)
        && ($order['online_payment_status'] ?? '') === 'completed';
    
    // Get order items for the modal
    $itemsStmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url
                                FROM order_items oi
                                JOIN products p ON p.id = oi.product_id
                                WHERE oi.order_id = ?");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll();

    // Which of these products has this customer already reviewed - used to
    // show "Rate this product" vs "Reviewed" per item below. Only matters
    // for completed orders (api/reviews.php only allows reviewing products
    // from a completed order anyway).
    $reviewedProductIds = [];
    if (($order['status'] ?? '') === 'completed' && !empty($orderItems)) {
        $productIds = array_column($orderItems, 'product_id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $revStmt = $pdo->prepare("SELECT product_id FROM reviews WHERE user_id = ? AND product_id IN ($placeholders)");
        $revStmt->execute(array_merge([$userId], $productIds));
        $reviewedProductIds = array_map('intval', $revStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $orderGrossTotal = (float)($order['total'] ?? $order['total_amount'] ?? 0);
    $vatBreakdown = phVatBreakdown($orderGrossTotal);

    // Null for a cancelled or finished order - at that point the real date is
    // already on the page and an estimate beside it only invites argument.
    $etaEstimate = deliveryEstimateForOrder($order);
} catch (Throwable $e) { header('Location: orders.php'); exit; }

$pageTitle = 'Order Successful - TechWorld';
// Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js is
// deferred and would run a frame after first paint, which is exactly the
// flash this prevents. Resolution order is localStorage -> OS preference ->
// dark. There is no server-rendered preference to fall back on yet - `users`
// has no theme column; see the TODO in theme.js.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<!-- Figtree (headings, prose) + JetBrains Mono (labels, codes, the timer,
     pills, money). Same pair techworld-anim.css uses on the landing page. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

<!-- `ords` is the scope hook for order-success-skin.css (linked last, below
     the footer). This page includes header.php, which starts mid-document
     with no <body> tag to carry a class - so the sheet scopes on
     body:has(.ords), the same mechanism techworld-anim.css already uses for
     the landing pages. -->
<section class="confirmation-page ords">
  <div class="container" style="position: relative;">
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
      <div style="background:rgba(16, 185, 129, 0.1);border:1px solid rgba(16, 185, 129, 0.25);border-radius:8px;padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:#10b981;margin:0;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-check" style="font-size:16px;"></i>
          <?= htmlspecialchars($_SESSION['success_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
      <div style="background:rgba(239, 68, 68, 0.1);border:1px solid rgba(239, 68, 68, 0.25);border-radius:8px;padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:#ef4444;margin:0;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-exclamation-triangle" style="font-size:16px;"></i>
          <?= htmlspecialchars($_SESSION['error_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    
    <div class="confirmation-header">
      <?php if (($order['status'] ?? '') === 'cancelled'): ?>
        <!-- Cancelled Order Display -->
        <div class="cancelled-icon" style="text-align: center; margin-bottom: 24px;">
          <img src="<?php echo WEB_ROOT ?>public/shared/img/cancel stamp.png" alt="Order Cancelled" style="width: 200px; height: auto; opacity: 0.8; animation: stampAppear 0.5s ease-out;" />
        </div>
        <h1 style="color: #dc2626;">Order Cancelled</h1>
        <p>Order #<?php echo htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'],6,'0',STR_PAD_LEFT)); ?> • Cancelled on <?php echo date('M j, Y g:i A', strtotime($order['updated_at'])); ?></p>
      <?php else: ?>
        <!-- Normal Order Display -->
        <div class="success-icon">✔</div>
        <span class="ords-eyebrow">Order Confirmed</span>
        <h1>Thank you! Your order is confirmed.</h1>
        <p>Order <span class="ords-inv">#<?php echo htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'],6,'0',STR_PAD_LEFT)); ?></span> &middot; Placed on <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></p>
      <?php endif; ?>
    </div>

    <?php
      $isPickupOrder = ($order['delivery_method'] ?? '') === 'pickup';
      // Has the order actually been handed over? pickup.php treats
      // pickup_verified_at as the moment of handover, so this page reads the
      // same column rather than inventing a second rule; `completed` is the
      // end of the pickup ladder (see config/order_status.php) and catches
      // rows collected before that column was being written.
      $pickedUp = $isPickupOrder
        && (!empty($order['pickup_verified_at']) || ($order['status'] ?? '') === 'completed');
    ?>
    <?php
      // The one value the customer needs at the counter (A) or for tracking
      // (B). Same fields, same emptiness checks as the summary row this
      // replaces - nothing new is computed.
      $heroValue = $isPickupOrder ? ($order['pickup_code'] ?? '') : ($order['tracking_code'] ?? '');
    ?>
    <?php if (($order['status'] ?? '') !== 'cancelled'): ?>
    <div class="ords-hero">
      <span class="ords-hero-label"><?php echo $isPickupOrder ? 'Pickup Code' : 'Tracking Number'; ?></span>
      <div class="ords-hero-row">
        <?php if (!empty($heroValue)): ?>
          <span class="ords-hero-value" id="ordsHeroValue"><?php echo htmlspecialchars($heroValue); ?></span>
          <button type="button" class="ords-copy" id="ordsCopyBtn"
                  data-copy="<?php echo htmlspecialchars($heroValue); ?>">Copy</button>
        <?php else: ?>
          <span class="ords-hero-value is-pending">Will be assigned when your order is processed</span>
        <?php endif; ?>
      </div>
      <?php /* What the number is FOR, in plain words.
               A big code with a label above it tells a shopper what it is
               called, not what to do with it - and "Tracking Number" is jargon
               to most people. One sentence, no terminology, written for
               someone who has never needed a tracking number before. */ ?>
      <p class="ords-hero-note">
        <?php if ($isPickupOrder): ?>
          <?php if ($pickedUp): ?>
            This code has already been used to collect your order. It is kept here
            for your records and can no longer be scanned at the counter.
          <?php elseif (!empty($heroValue)): ?>
            Show this code at the store counter and our staff will hand over your order.
            It is what proves the order is yours, so please keep it to yourself.
          <?php else: ?>
            We will create your pickup code once we start preparing your order.
            It will appear here, and we will email it to you.
          <?php endif; ?>
        <?php else: ?>
          <?php if (!empty($heroValue)): ?>
            This is your order's reference number. Use it to see where your delivery has
            reached, and give it to us if you ever message or call about this order.
            You do not need to write it down &mdash; it is saved in My Orders.
          <?php else: ?>
            We will create your tracking number once we start preparing your order.
            It will appear here and in My Orders, and we will email it to you.
          <?php endif; ?>
        <?php endif; ?>
      </p>

      <?php if ($isPickupOrder): ?>
        <?php if (!$pickedUp && !empty($order['pickup_code_expires_at'])): ?>
          <span class="ords-deadline">Expires <?php echo date('M j, Y g:i A', strtotime($order['pickup_code_expires_at'])); ?></span>
        <?php endif; ?>
      <?php else: ?>
        <a class="ords-track" href="<?php echo WEB_ROOT ?>public/authenticated/customer/track_order.php?id=<?php echo (int)$orderId; ?>">Track this order &rarr;</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php /* ---- Estimated arrival -------------------------------------------
             The question a shopper actually has after "did it go through?" is
             "when does it get here", and until now this page answered only the
             first. It is framed as an estimate everywhere it appears - a range,
             the word "Estimated", and the reason underneath - because there is
             no courier API behind this store and a single confident date would
             be a promise nobody can keep.

             Placed directly under the hero and above Order Summary: it is the
             second thing they want, not a detail to find halfway down.      */ ?>
    <?php if ($etaEstimate): ?>
    <div class="ords-eta<?php echo $etaEstimate['provisional'] ? ' is-provisional' : ''; ?>">
      <div class="ords-eta-head">
        <i class="fi fi-rr-<?php echo $etaEstimate['kind'] === 'pickup' ? 'shop' : 'shipping-fast'; ?>" aria-hidden="true"></i>
        <span class="ords-eta-label"><?php echo $etaEstimate['kind'] === 'pickup' ? 'Estimated ready for pickup' : 'Estimated arrival'; ?></span>
      </div>
      <p class="ords-eta-value"><?php echo $etaEstimate['label']; ?></p>
      <?php if ($etaEstimate['provisional']): ?>
        <p class="ords-eta-provisional">
          <i class="fi fi-rr-info" aria-hidden="true"></i>
          These dates start counting once your payment is confirmed.
        </p>
      <?php elseif (!empty($etaEstimate['delayed'])): ?>
        <?php /* The original window has passed. Saying so is better than
                 silently printing a fresh range as though it were always the
                 estimate - they have seen the earlier one. */ ?>
        <p class="ords-eta-provisional">
          <i class="fi fi-rr-info" aria-hidden="true"></i>
          This order is running later than planned &mdash; the dates above are our updated estimate.
        </p>
      <?php endif; ?>
      <p class="ords-eta-note"><?php echo $etaEstimate['note']; ?></p>
      <?php /* The address it was measured to, so the customer can tell at a
               glance whether we are estimating to the right place - and the
               distance when we have real coordinates, which is the whole
               reason the range is what it is. */ ?>
      <?php if (!empty($etaEstimate['area']) || $etaEstimate['distance_km'] !== null): ?>
        <p class="ords-eta-basis">
          <?php if (!empty($etaEstimate['area'])): ?>
            To <?php echo htmlspecialchars($etaEstimate['area']); ?><?php endif; ?>
          <?php if ($etaEstimate['distance_km'] !== null): ?>
            <span class="ords-eta-km"><?php echo rtrim(rtrim(number_format($etaEstimate['distance_km'], 1), '0'), '.'); ?> km from the branch</span>
          <?php endif; ?>
        </p>
      <?php endif; ?>
      <p class="ords-eta-fine">An estimate, not a guaranteed delivery date.</p>
    </div>
    <?php endif; ?>

    <div class="order-details-card">
      <span class="ords-summary-head">Order Summary</span>
      <div class="detail-row">
        <div class="detail-label">Delivery Method</div>
        <div class="detail-value"><?php
          // Determine service name from payment_methods.name using canonical code
          $serviceCode = (($order['delivery_method'] ?? '') === 'pickup') ? 'store_pickup' : 'home_delivery';
          $serviceName = function_exists('getPaymentMethodNameByCode') ? getPaymentMethodNameByCode($serviceCode) : null;
          if (!$serviceName) { $serviceName = (($order['delivery_method'] ?? '') === 'pickup') ? 'Store Pickup' : 'Home Delivery'; }
          echo htmlspecialchars($serviceName);
        ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Payment Method</div>
        <div class="detail-value"><?php
          // orders.payment_method carries the specific e-payment channel
          // (gcash/paymaya) that the shared 'online' payments record can't
          // distinguish, so prefer it for those; otherwise use the payment record.
          $payment = function_exists('getPaymentByOrderId') ? getPaymentByOrderId($orderId) : false;
          if (in_array($order['payment_method'] ?? '', ['gcash', 'paymaya'], true)) {
            echo htmlspecialchars(getPaymentMethodLabel($order['payment_method']));
          } elseif ($payment && !empty($payment['payment_method'])) {
            echo htmlspecialchars(getPaymentMethodLabel($payment['payment_method']));
          } else {
            echo htmlspecialchars(getPaymentMethodLabel($order['payment_method'] ?? ''));
          }
        ?></div>
      </div>
      <?php
        // The code/tracking row that used to sit here is now the hero card
        // above - same value, same branch, shown once.
        $ordsItemCount = 0;
        foreach ($orderItems as $ordsIt) { $ordsItemCount += (int)($ordsIt['quantity'] ?? 0); }
      ?>
      <?php if ($ordsItemCount > 0): ?>
      <div class="detail-row">
        <div class="detail-label">Items</div>
        <div class="detail-value"><?php echo (int)$ordsItemCount; ?> item<?php echo $ordsItemCount === 1 ? '' : 's'; ?></div>
      </div>
      <?php endif; ?>
      <div class="detail-row ords-total">
        <div class="detail-label">Total Amount</div>
        <div class="detail-value total-amount"><?php echo formatCurrency($order['total'] ?? $order['total_amount'] ?? 0); ?></div>
      </div>
      <?php if (($order['status'] ?? '') === 'cancelled'): ?>
        <?php
        // Get cancellation details
        try {
          $cancelStmt = $pdo->prepare("SELECT reason, custom_reason, admin_notes, processed_at FROM order_cancellation_requests WHERE order_id = ? AND status = 'approved' ORDER BY processed_at DESC LIMIT 1");
          $cancelStmt->execute([$orderId]);
          $cancellation = $cancelStmt->fetch();
        } catch (Throwable $e) {
          $cancellation = null;
        }
        ?>
        <?php if ($cancellation): ?>
        <div class="detail-row" style="border-top: 2px solid #dc2626; margin-top: 16px; padding-top: 16px;">
          <div class="detail-label" style="color: #dc2626; font-weight: 600;">Cancellation Reason</div>
          <div class="detail-value" style="color: #fca5a5;"><?php echo htmlspecialchars($cancellation['reason']); ?></div>
        </div>
        <?php if (!empty($cancellation['custom_reason'])): ?>
        <div class="detail-row">
          <div class="detail-label" style="color: #dc2626;">Custom Reason</div>
          <div class="detail-value" style="color: #fca5a5;"><?php echo htmlspecialchars($cancellation['custom_reason']); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($cancellation['admin_notes'])): ?>
        <div class="detail-row">
          <div class="detail-label" style="color: #dc2626;">Admin Notes</div>
          <div class="detail-value" style="color: #fca5a5;"><?php echo htmlspecialchars($cancellation['admin_notes']); ?></div>
        </div>
        <?php endif; ?>
        <div class="detail-row">
          <div class="detail-label" style="color: #dc2626;">Cancelled On</div>
          <div class="detail-value" style="color: #fca5a5;"><?php echo date('M j, Y g:i A', strtotime($cancellation['processed_at'])); ?></div>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php /* Labels name WHAT each one opens, not the act of viewing.
             "View Order Details" and "View My Orders" both started with the
             same verb and differed by one word, so they read as the same
             thing twice; "This Order" against "All My Orders" is the
             distinction that actually matters, and it is visible at a glance
             without reading to the end of the label. */ ?>
    <div class="ords-actions">
      <?php if (($order['status'] ?? '') !== 'cancelled'): ?>
      <button onclick="openOrderSummaryModal()" class="btn-primary ords-btn ords-btn-primary">
        <i class="fi fi-rr-document"></i>
        This Order's Details
      </button>
      <?php else: ?>
      <button onclick="openOrderSummaryModal()" class="btn-primary ords-btn ords-btn-primary" disabled>
        <i class="fi fi-rr-document"></i>
        This Order's Details
      </button>
      <?php endif; ?>
      <a href="<?php echo WEB_ROOT ?>public/authenticated/customer/orders.php" class="ords-btn ords-btn-secondary">
        <i class="fi fi-rr-list"></i>
        All My Orders
      </a>
      <?php if (($order['status'] ?? '') !== 'cancelled'): ?>
      <a href="<?php echo WEB_ROOT ?>public/authenticated/customer/products.php" class="ords-btn ords-btn-secondary">
        <i class="fi fi-rr-shopping-bag"></i>
        Continue Shopping
      </a>
      <?php endif; ?>
    </div>

    <div class="ords-after">
      <?php if (($order['delivery_method'] ?? '') === 'pickup' && ($order['status'] ?? '') !== 'cancelled' && $pickedUp): ?>

      <!-- Already collected. No QR control: the code has been spent, and
           offering it again invites a customer to queue at the counter with a
           slip that will not scan. pickup.php shows the same "already picked
           up" state if they reach it by a bookmark. -->
      <div class="ords-strip ords-strip-green">
        <i class="fi fi-rr-check-circle"></i>
        <p>
          This order has been picked up<?php echo !empty($order['pickup_verified_at'])
            ? ' on ' . htmlspecialchars(date('M j, Y g:i A', strtotime($order['pickup_verified_at'])))
            : ''; ?>. Your receipt was issued at the counter &mdash; keep it for
          warranty claims.
        </p>
      </div>

      <?php elseif (($order['delivery_method'] ?? '') === 'pickup' && ($order['status'] ?? '') !== 'cancelled'): ?>
      
      <!-- Pickup: one card, not a panel inside a panel. The QR itself is NOT
           rendered on this page - this control links to pickup.php, which
           generates it; inlining one would be a logic change. -->
      <div class="ords-pickup">
        <a href="<?php echo WEB_ROOT ?>public/authenticated/customer/pickup.php?order_id=<?php echo (int)$orderId; ?>" class="btn-primary ords-qr-btn">
          <i class="fi fi-rr-qr-code"></i>
          Show Pickup QR
        </a>

        <!-- Wording unchanged, expiry sentence included. -->
        <div class="ords-strip ords-strip-amber">
          <div class="ords-strip-head">
            <i class="fi fi-rr-clock"></i>
            <span>Important Instructions</span>
          </div>
          <p>
            Please proceed to our store as soon as possible to claim your order.
            <?php 
              $expireText = !empty($order['pickup_code_expires_at']) 
                ? 'This pickup QR/code will expire on ' . date('M j, Y g:i A', strtotime($order['pickup_code_expires_at'])) . '.' 
                : 'This pickup QR/code has a limited validity period.'; 
              echo htmlspecialchars($expireText); 
            ?>
          </p>
        </div>
      </div>

      <?php elseif (($order['delivery_method'] ?? '') === 'delivery' && ($order['status'] ?? '') === 'completed' && ($order['status'] ?? '') !== 'cancelled'): ?>
      <a href="<?php echo WEB_ROOT ?>public/authenticated/customer/invoice.php?id=<?php echo (int)$orderId; ?>" class="btn-primary ords-btn ords-btn-secondary">
        <i class="fi fi-rr-receipt"></i>
        View Invoice
      </a>
      <?php elseif (($order['delivery_method'] ?? '') === 'delivery' && ($order['status'] ?? '') !== 'cancelled'): ?>
      <div class="ords-strip ords-strip-cyan">
        <i class="fi fi-rr-info"></i>
        <p>Invoice will be available when your order is completed and delivered</p>
      </div>
      <?php endif; ?>
      
      <!-- Cancel Order Button - shown for any order that isn't completed/cancelled yet.
           Paid online-payment orders can request cancellation too now - the refund-method
           choice (original payment vs. store credit) is captured in the modal below and
           the actual refund/credit is issued once admin approves the request. -->
      <?php if (($order['status'] ?? '') !== 'completed' && ($order['status'] ?? '') !== 'cancelled'): ?>
        <?php
        // Check 30-minute cancellation time limit - seconds_since_created
        // comes straight from the query above (MySQL TIMESTAMPDIFF), not
        // PHP's strtotime()/time(), so it's not subject to the timezone
        // drift that used to make this always read as ~0 elapsed.
        $timeElapsed = max(0, (int)($order['seconds_since_created'] ?? 0));
        $cancellationWindow = 30 * 60; // 30 minutes in seconds
        // Never allow remaining time to exceed the window
        $timeRemaining = max(0, min($cancellationWindow, $cancellationWindow - $timeElapsed));
        $canCancel = $timeRemaining > 0;
        $minutesRemaining = max(0, floor($timeRemaining / 60));
        $secondsRemaining = max(0, $timeRemaining % 60);
        ?>
        
        <?php if ($canCancel): ?>
          <!-- One card: the window on the left, the way out on the right.
               #cancellation-timer keeps its id and its exact inner markup -
               the script below rewrites its innerHTML every second - and the
               button keeps onclick="openCancelOrderModal()" byte-for-byte,
               because that same script finds it with
               querySelector('button[onclick="openCancelOrderModal()"]').
               `is-urgent` comes from $timeRemaining, which PHP already
               computed, so the under-5-minutes colour is right on load; it
               does not flip live at the boundary, since the script never
               touches a class. -->
          <div class="ords-cancel<?= $timeRemaining <= 300 ? ' is-urgent' : '' ?>">
            <div>
              <span class="ords-cancel-label">Cancellation Window</span>
              <span id="cancellation-timer">You have <strong><?= $minutesRemaining ?>m <?= $secondsRemaining ?>s</strong> to cancel this order</span>
            </div>
            <button onclick="openCancelOrderModal()" class="btn-primary ords-cancel-btn">
              <i class="fi fi-rr-cross"></i>
              Cancel Order
            </button>
          </div>

          <script>
          // Simple countdown timer
          let timeRemaining = <?= $timeRemaining ?>;
          const timerElement = document.getElementById('cancellation-timer');
          const cancelButton = document.querySelector('button[onclick="openCancelOrderModal()"]');
          
          function updateTimer() {
            if (timeRemaining <= 0) {
              timerElement.innerHTML = 'Order cancellation is no longer available';
              cancelButton.style.display = 'none';
              return;
            }
            
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            timerElement.innerHTML = `You have <strong>${minutes}m ${seconds}s</strong> to cancel this order`;
            timeRemaining--;
          }
          
          // Update timer every second
          setInterval(updateTimer, 1000);
          </script>
        <?php else: ?>
          <!-- Expired. PHP renders this instead of the timer and the
               button, so there is no control left to dim - the card states
               it plainly. -->
          <div class="ords-cancel is-closed">
            <div>
              <span class="ords-cancel-label">Cancellation Window Closed</span>
              <p>Order cancellation is no longer available (30-minute window expired)</p>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      
    </div>

    <?php if (($order['status'] ?? '') !== 'cancelled'): ?>
    <div class="ords-next">
      <h2>What happens next</h2>
      <ol>
        <?php if ($isPickupOrder): ?>
          <li>We prepare your order at the store and hold it under <?php echo $isPickupOrder && !empty($order['pickup_code']) ? 'pickup code ' . htmlspecialchars($order['pickup_code']) : 'your pickup code'; ?>.</li>
          <li>Bring your pickup code or the QR above to our Caloocan branch &mdash; Mon&ndash;Sat, 9AM&ndash;5PM.</li>
          <?php if (($order['payment_method'] ?? '') === 'onsite'): ?>
            <li>Pay <?php echo formatCurrency($order['total'] ?? $order['total_amount'] ?? 0); ?> at the counter when you collect.</li>
          <?php else: ?>
            <li>Present your code at the counter and our staff will release the order.</li>
          <?php endif; ?>
        <?php else: ?>
          <li>We prepare and pack your order, then hand it to a rider for dispatch.</li>
          <li>You can follow its progress from Track this order once it leaves the store.</li>
          <?php if (($order['payment_method'] ?? '') === 'cod'): ?>
            <li>Have <?php echo formatCurrency($order['total'] ?? $order['total_amount'] ?? 0); ?> ready in cash when the rider arrives.</li>
          <?php else: ?>
            <li>Your invoice becomes available here once the order is delivered.</li>
          <?php endif; ?>
        <?php endif; ?>
      </ol>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Order Summary Modal -->
<div id="orderSummaryModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:9999;backdrop-filter:blur(4px);overflow-y:auto;padding:40px 16px;box-sizing:border-box;">
  <div class="modal-container" style="position:relative;width:100%;max-width:1000px;margin:0 auto;background:#0f172a;border-radius:16px;border:1px solid rgba(255,255,255,0.1);overflow:hidden;display:flex;flex-direction:column;">

    <!-- Modal Header -->
    <div class="modal-header" style="flex:0 0 auto;padding:24px 32px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;justify-content:space-between;align-items:center;">
      <h2 style="color:#e5e7eb;font-size:24px;font-weight:600;margin:0;">
        <i class="fi fi-rr-document" style="margin-right:12px;color:#a77693;"></i>
        Order Summary
      </h2>
      <button onclick="closeOrderSummaryModal()" style="background:transparent;border:none;color:#94a3b8;font-size:24px;cursor:pointer;padding:8px;border-radius:8px;transition:all 0.2s ease;">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>

    <!-- Modal Content - no internal scroll: sized to its natural content
         height instead of being capped and scrolled inside a fixed-height
         box. #orderSummaryModal's overlay (above) already has its own
         overflow-y:auto, so on a short viewport the whole modal scrolls
         with the page instead of showing a second, boxed-in scrollbar. The
         explicit position/margin/width resets defend against the shared
         `.modal > .modal-content` auth-modal rule in style.css in case it
         is ever un-scoped again. -->
    <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;max-height:none;width:100%;max-width:none;height:auto;box-sizing:border-box;padding:32px;">

      <!-- Order Info -->
      <div class="order-info-section" style="margin-bottom:32px;">
        <div class="order-info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;">
          <div class="info-item">
            <span class="info-label" style="color:#94a3b8;font-size:14px;display:block;margin-bottom:4px;">Order Number</span>
            <span class="info-value" style="color:#e5e7eb;font-weight:600;font-size:16px;"><?php echo htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'],6,'0',STR_PAD_LEFT)); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label" style="color:#94a3b8;font-size:14px;display:block;margin-bottom:4px;">Order Date</span>
            <span class="info-value" style="color:#e5e7eb;font-weight:600;font-size:16px;"><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label" style="color:#94a3b8;font-size:14px;display:block;margin-bottom:4px;">Status</span>
            <span class="info-value" style="color:#10b981;font-weight:600;font-size:16px;text-transform:capitalize;"><?php echo ucfirst($order['status'] ?? 'preparing'); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label" style="color:#94a3b8;font-size:14px;display:block;margin-bottom:4px;">Delivery Method</span>
            <span class="info-value" style="color:#e5e7eb;font-weight:600;font-size:16px;"><?php echo ($order['delivery_method'] ?? '') === 'pickup' ? 'Store Pickup' : 'Home Delivery'; ?></span>
          </div>
        </div>
      </div>

      <!-- Order Items -->
      <div class="order-items-section" style="margin-bottom:32px;">
        <h3 style="color:#e5e7eb;font-size:20px;margin-bottom:20px;">
          <i class="fi fi-rr-shopping-bag" style="margin-right:8px;color:#a77693;"></i>
          Order Items
        </h3>
        <div class="order-items-list" style="background:rgba(30,41,59,0.5);border-radius:12px;padding:20px;border:1px solid rgba(255,255,255,0.1);">
          <?php foreach ($orderItems as $item): ?>
          <div class="order-item" style="display:flex;gap:16px;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.1);align-items:center;">
            <div class="item-image" style="width:80px;height:80px;border-radius:12px;overflow:hidden;background:rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <?php if (!empty($item['image_url'])): ?>
                <img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <div class="image-placeholder" style="color:#a77693;font-size:16px;font-weight:600;">IMG</div>
              <?php endif; ?>
            </div>
            <div class="item-details" style="flex:1;">
              <h4 style="color:#e5e7eb;font-size:16px;margin-bottom:8px;line-height:1.4;"><?= htmlspecialchars($item['name']) ?></h4>
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span style="color:#94a3b8;font-size:14px;">Quantity: <?= (int)$item['quantity'] ?></span>
                <span style="color:#a77693;font-weight:600;font-size:16px;"><?= formatCurrency($item['price'] * $item['quantity']) ?></span>
              </div>
              <?php if (($order['status'] ?? '') === 'completed'): ?>
                <?php if (in_array((int)$item['product_id'], $reviewedProductIds, true)): ?>
                  <span class="order-item-reviewed"><i class="fi fi-rr-check"></i> Reviewed</span>
                <?php else: ?>
                  <a class="order-item-rate-btn" href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= (int)$item['product_id'] ?>#reviews">
                    <i class="fi fi-rr-star"></i> Rate this product
                  </a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Order Totals -->
      <div class="order-totals-section">
        <h3 style="color:#e5e7eb;font-size:20px;margin-bottom:20px;">
          <i class="fi fi-rr-calculator" style="margin-right:8px;color:#a77693;"></i>
          Order Summary
        </h3>
        <div class="totals-card" style="background:rgba(30,41,59,0.5);border-radius:12px;padding:24px;border:1px solid rgba(255,255,255,0.1);">
          <div class="total-line" style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;color:#94a3b8;font-size:16px;">
            <span>Subtotal</span>
            <span><?php echo formatCurrency($vatBreakdown['net']); ?></span>
          </div>
          <div class="total-line" style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;color:#94a3b8;font-size:16px;">
            <span>VAT (12%)</span>
            <span><?php echo formatCurrency($vatBreakdown['vat']); ?></span>
          </div>
          <div class="total-divider" style="height:1px;background:rgba(255,255,255,0.1);margin:16px 0;"></div>
          <div class="total-line final-total" style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;color:#e5e7eb;font-weight:700;font-size:20px;">
            <span>Total</span>
            <span><?php echo formatCurrency($orderGrossTotal); ?></span>
          </div>
        </div>
      </div>

      <!-- Order Notes -->
      <?php if (!empty($order['order_notes'])): ?>
      <div class="order-notes-section" style="margin-top:32px;">
        <h3 style="color:#e5e7eb;font-size:20px;margin-bottom:20px;">
          <i class="fi fi-rr-comment" style="margin-right:8px;color:#a77693;"></i>
          Order Notes
        </h3>
        <div class="notes-card" style="background:rgba(30,41,59,0.5);border-radius:12px;padding:20px;border:1px solid rgba(255,255,255,0.1);">
          <p style="color:#94a3b8;font-size:16px;line-height:1.6;margin:0;"><?= htmlspecialchars($order['order_notes']) ?></p>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- Modal Footer -->
    <div class="modal-footer" style="flex:0 0 auto;padding:24px 32px;border-top:1px solid rgba(255,255,255,0.1);display:flex;justify-content:flex-end;gap:16px;">
      <button onclick="closeOrderSummaryModal()" style="background:transparent;border:1px solid rgba(255,255,255,0.2);color:#e5e7eb;padding:12px 24px;border-radius:8px;cursor:pointer;font-weight:500;transition:all 0.2s ease;">
        Close
      </button>
    </div>

  </div>
</div>

<!-- Cancel Order Modal -->
<div id="cancelOrderModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:9999;backdrop-filter:blur(4px);">
  <div class="modal-container" style="position:relative;max-width:500px;margin:5% auto;background:#0f172a;border-radius:16px;border:1px solid rgba(255,255,255,0.1);overflow:hidden;max-height:90vh;overflow-y:auto;">

    <!-- Modal Header -->
    <div class="modal-header" style="padding:24px 32px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;justify-content:space-between;align-items:center;">
      <h2 style="color:#e5e7eb;font-size:24px;font-weight:600;margin:0;">
        <i class="fi fi-rr-cross" style="margin-right:12px;color:#dc2626;"></i>
        Cancel Order
      </h2>
      <button onclick="closeCancelOrderModal()" style="background:transparent;border:none;color:#94a3b8;font-size:24px;cursor:pointer;padding:8px;border-radius:8px;transition:all 0.2s ease;">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>

    <!-- Modal Content -->
    <!-- position/max-width/height reset explicitly - see the identical fix
         and explanation on the Order Summary modal's .modal-content above. -->
    <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;width:100%;max-width:none;height:auto;max-height:none;padding:32px;">

      <!-- Order Info -->
      <div class="order-info-section" style="margin-bottom:24px;">
        <div style="background:rgba(220, 38, 38, 0.1);border:1px solid rgba(220, 38, 38, 0.25);border-radius:8px;padding:16px;margin-bottom:20px;">
          <p style="color:#fca5a5;margin:0;font-size:14px;display:flex;align-items:center;gap:8px;">
            <i class="fi fi-rr-exclamation-triangle" style="font-size:16px;"></i>
            Are you sure you want to cancel this order? This action will send a cancellation request to our admin team for review.
          </p>
        </div>

        <div style="background:rgba(30,41,59,0.5);border-radius:8px;padding:16px;border:1px solid rgba(255,255,255,0.1);">
          <h4 style="color:#e5e7eb;font-size:16px;margin-bottom:8px;">Order Details</h4>
          <p style="color:#94a3b8;font-size:14px;margin:0;">Order #<?php echo htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'],6,'0',STR_PAD_LEFT)); ?> • <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></p>
          <p style="color:#94a3b8;font-size:14px;margin:4px 0 0 0;">Total: <?php echo formatCurrency($order['total'] ?? $order['total_amount'] ?? 0); ?></p>
        </div>
      </div>

      <!-- Cancellation Form -->
      <form id="cancelOrderForm" method="POST" action="<?php echo WEB_ROOT ?>public/authenticated/customer/process_cancel_order.php">
        <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">

        <div class="form-group" style="margin-bottom:20px;">
          <label for="cancel_reason" style="color:#e5e7eb;font-size:14px;font-weight:500;display:block;margin-bottom:8px;">
            Reason for Cancellation <span style="color:#dc2626;">*</span>
          </label>
          <select id="cancel_reason" name="reason" required style="width:100%;padding:12px;background:var(--bg-card-alpha-80);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;transition:all 0.2s ease;">
            <option value="">Select a reason...</option>
            <option value="Changed my mind">Changed my mind</option>
            <option value="Found better price elsewhere">Found better price elsewhere</option>
            <option value="Product no longer needed">Product no longer needed</option>
            <option value="Wrong detail provided">Wrong detail provided</option>
            <option value="Financial constraints">Financial constraints</option>
            <option value="Duplicate order">Duplicate order</option>
            <option value="Wrong product ordered">Wrong product ordered</option>
            <option value="Others">Others</option>
          </select>
        </div>
        
        <div class="form-group" id="custom_reason_group" style="margin-bottom:20px;display:none;">
          <label for="custom_reason" style="color:#e5e7eb;font-size:14px;font-weight:500;display:block;margin-bottom:8px;">
            Please specify your reason <span style="color:#dc2626;">*</span>
          </label>
          <textarea id="custom_reason" name="custom_reason" rows="3" style="width:100%;padding:12px;background:var(--bg-card-alpha-80);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;transition:all 0.2s ease;resize:vertical;" placeholder="Please provide details about your cancellation reason..."></textarea>
        </div>
        
        <div class="form-group" style="margin-bottom:24px;">
          <label for="additional_notes" style="color:#e5e7eb;font-size:14px;font-weight:500;display:block;margin-bottom:8px;">
            Additional Notes (Optional)
          </label>
          <textarea id="additional_notes" name="additional_notes" rows="2" style="width:100%;padding:12px;background:var(--bg-card-alpha-80);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;transition:all 0.2s ease;resize:vertical;" placeholder="Any additional information you'd like to share..."></textarea>
        </div>

        <?php if ($isOnlinePaid):
          $__orderTotalForCancel = (float)($order['total'] ?? $order['total_amount'] ?? 0);
          $__creditBonusPct = 5; // Incentive for choosing instant credit over a cash refund - not mandatory, just more attractive.
          $__creditAmount = round($__orderTotalForCancel * (1 + $__creditBonusPct / 100), 2);
        ?>
        <div class="form-group" style="margin-bottom:24px;">
          <label style="color:#e5e7eb;font-size:14px;font-weight:500;display:block;margin-bottom:8px;">
            You already paid for this order. How would you like to receive your money back? <span style="color:#dc2626;">*</span>
          </label>
          <p style="color:#94a3b8;font-size:12.5px;margin:0 0 12px;">This only applies once our team approves your cancellation - nothing is charged or credited yet.</p>

          <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid rgba(255,255,255,0.15);border-radius:8px;margin-bottom:10px;cursor:pointer;">
            <input type="radio" name="refund_method" value="original_payment" checked style="margin-top:3px;">
            <span>
              <strong style="color:#e5e7eb;">Refund to original payment method</strong>
              <span style="display:block;color:#94a3b8;font-size:13px;margin-top:2px;"><?php echo formatCurrency($__orderTotalForCancel); ?> back to your <?php echo htmlspecialchars(getPaymentMethodLabel($order['payment_method'] ?? '')); ?> - usually takes a few business days once our team processes it.</span>
            </span>
          </label>

          <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid rgba(139,76,108,0.4);background:rgba(139,76,108,0.08);border-radius:8px;cursor:pointer;">
            <input type="radio" name="refund_method" value="store_credit" style="margin-top:3px;">
            <span>
              <strong style="color:#e5e7eb;">Instant store credit</strong>
              <span style="display:block;color:#94a3b8;font-size:13px;margin-top:2px;"><?php echo formatCurrency($__creditAmount); ?> (includes a <?php echo $__creditBonusPct; ?>% bonus) added to your account right away, to use on your next order.</span>
            </span>
          </label>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" onclick="closeCancelOrderModal()" style="background:transparent;border:1px solid rgba(255,255,255,0.2);color:#e5e7eb;padding:12px 24px;border-radius:8px;cursor:pointer;font-weight:500;transition:all 0.2s ease;">
            Cancel
          </button>
          <button type="submit" style="background:#b91c1c;border:1px solid #b91c1c;color:#fff;padding:12px 24px;border-radius:8px;cursor:pointer;font-weight:500;transition:all 0.2s ease;">
            Submit Cancellation Request
          </button>
        </div>
      </form>
      
    </div>
    
  </div>
</div>

<style>
.btn-primary:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.btn-secondary:hover {
  background: var(--accent-cyan-bg);
  border-color: var(--accent-cyan);
  color: var(--accent-cyan);
}

.confirmation-page {
  background: var(--page-bg-gradient);
  min-height: calc(100vh - 200px);
  padding-bottom: 20px;
}

.confirmation-page .container {
  max-width: 600px;
  margin: 0 auto;
  padding: 40px 20px 20px 20px;
}

.confirmation-page .success-icon {
  font-size: 72px;
  color: #10b981;
  text-align: center;
  margin-bottom: 24px;
}

.confirmation-page .confirmation-header h1 {
  color: var(--text-primary);
  font-size: 28px;
  font-weight: 600;
  margin-bottom: 8px;
  text-align: center;
}

.confirmation-page .confirmation-header p {
  color: var(--text-muted);
  font-size: 16px;
  text-align: center;
  margin-bottom: 32px;
}

.confirmation-page .order-details-card {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 32px;
}

.confirmation-page .detail-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.confirmation-page .detail-row:last-child {
  border-bottom: none;
}

.confirmation-page .detail-label {
  color: var(--text-muted);
  font-size: 14px;
}

.confirmation-page .detail-value {
  color: var(--text-primary);
  font-weight: 600;
  font-size: 16px;
}

.confirmation-page .total-amount {
  color: var(--text-primary);
  font-weight: 700;
  font-size: 18px;
}

/* Cancelled order styles */

/* ---------------------------------------------------------------------------
   The order modals used to be a deliberately dark card - background:#0f172a on
   .modal-container, with #e5e7eb text pinned on top of it - held dark on
   purpose so the sitewide [data-theme="light"] .modal-content rule could not
   wash the light-on-dark text out.

   That predates the data-mode Light/Dark switch. Now that the customer can
   actually choose, a modal that stays night-dark over a light page is the
   odd one out, so these follow data-mode instead of opting out of theming.
   Every colour comes from the same --tw-* tokens the rest of the light theme
   uses, and the dark block at the end restores the original look for dark
   mode - so nothing is lost, it is just no longer unconditional.

   !important throughout for two reasons: these override inline style
   attributes on the markup, and they still have to beat that sitewide
   [data-theme="light"] .modal-content rule.
   ------------------------------------------------------------------------ */
#orderSummaryModal .modal-container,
#cancelOrderModal .modal-container {
  background: var(--tw-surface, #fff) !important;
  border: 1px solid var(--tw-line, #dbe3ee) !important;
  box-shadow: 0 24px 60px rgba(2, 6, 16, .26) !important;
}
#orderSummaryModal .modal-content,
#orderSummaryModal .modal-content h1,
#orderSummaryModal .modal-content h2,
#orderSummaryModal .modal-content h3,
#orderSummaryModal .modal-header h2,
#cancelOrderModal .modal-header h2 {
  background: transparent !important;
  border: none !important;
  box-shadow: none !important;
  color: var(--tw-ink-strong, #0f1a2c) !important;
}
/* Rules and dividers */
#orderSummaryModal .modal-header,
#cancelOrderModal .modal-header,
#orderSummaryModal .order-item,
#orderSummaryModal .total-line {
  border-color: var(--tw-line, #dbe3ee) !important;
}
#orderSummaryModal .order-item {
  background: transparent !important;
  border: none !important;
  border-bottom: 1px solid var(--tw-line, #dbe3ee) !important;
}
#orderSummaryModal .total-divider { background: var(--tw-line, #dbe3ee) !important; }
/* The two inset panels - items list and totals card. */
#orderSummaryModal .order-items-list,
#orderSummaryModal .totals-card {
  background: var(--tw-surface-2, #f7f9fc) !important;
  border-color: var(--tw-line, #dbe3ee) !important;
}
#orderSummaryModal .item-image {
  background: var(--tw-surface-2, #f7f9fc) !important;
  border: 1px solid var(--tw-line, #dbe3ee) !important;
}
/* Labels and secondary lines. #94a3b8 is 2.4:1 on white - it was tuned for the
   navy card, and is the single most-repeated colour in this modal. */
#orderSummaryModal .info-label,
#orderSummaryModal .order-item span[style*="#94a3b8"],
#orderSummaryModal .total-line,
#cancelOrderModal .modal-content p {
  color: var(--tw-ink-dim, #46586f) !important;
}
#orderSummaryModal .info-value,
#orderSummaryModal .order-item h4,
#orderSummaryModal .final-total {
  color: var(--tw-ink-strong, #0f1a2c) !important;
}
/* Prices and the section icons keep the brand accent, at the light-mode value
   - #a77693 is 2.9:1 on white and reads as disabled text. */
#orderSummaryModal .order-item span[style*="#a77693"],
#orderSummaryModal h3 i,
#orderSummaryModal .modal-header h2 i {
  color: var(--tw-accent, #c23a63) !important;
}
#orderSummaryModal .modal-header button,
#cancelOrderModal .modal-header button {
  color: var(--tw-ink-faint, #5d6f86) !important;
}
#orderSummaryModal .modal-header button:hover,
#cancelOrderModal .modal-header button:hover {
  color: var(--tw-ink, #16233a) !important;
}

/* ---- dark mode: the card this modal was originally designed as ---- */
html[data-mode="dark"] #orderSummaryModal .modal-container,
html[data-mode="dark"] #cancelOrderModal .modal-container {
  background: #0f172a !important;
  border-color: rgba(255, 255, 255, .1) !important;
  box-shadow: 0 24px 60px rgba(0, 0, 0, .55) !important;
}
html[data-mode="dark"] #orderSummaryModal .modal-content,
html[data-mode="dark"] #orderSummaryModal .modal-content h1,
html[data-mode="dark"] #orderSummaryModal .modal-content h2,
html[data-mode="dark"] #orderSummaryModal .modal-content h3,
html[data-mode="dark"] #orderSummaryModal .modal-header h2,
html[data-mode="dark"] #cancelOrderModal .modal-header h2,
html[data-mode="dark"] #orderSummaryModal .info-value,
html[data-mode="dark"] #orderSummaryModal .order-item h4,
html[data-mode="dark"] #orderSummaryModal .final-total { color: #e5e7eb !important; }
html[data-mode="dark"] #orderSummaryModal .modal-header,
html[data-mode="dark"] #cancelOrderModal .modal-header,
html[data-mode="dark"] #orderSummaryModal .order-item,
html[data-mode="dark"] #orderSummaryModal .total-line { border-color: rgba(255, 255, 255, .1) !important; }
html[data-mode="dark"] #orderSummaryModal .total-divider { background: rgba(255, 255, 255, .1) !important; }
html[data-mode="dark"] #orderSummaryModal .order-items-list,
html[data-mode="dark"] #orderSummaryModal .totals-card { background: rgba(30, 41, 59, .5) !important; border-color: rgba(255, 255, 255, .1) !important; }
html[data-mode="dark"] #orderSummaryModal .item-image { background: rgba(255, 255, 255, .06) !important; border: none !important; }
html[data-mode="dark"] #orderSummaryModal .info-label,
html[data-mode="dark"] #orderSummaryModal .order-item span[style*="#94a3b8"],
html[data-mode="dark"] #orderSummaryModal .total-line,
html[data-mode="dark"] #cancelOrderModal .modal-content p { color: #94a3b8 !important; }
html[data-mode="dark"] #orderSummaryModal .order-item span[style*="#a77693"],
html[data-mode="dark"] #orderSummaryModal h3 i,
html[data-mode="dark"] #orderSummaryModal .modal-header h2 i { color: #a77693 !important; }
.order-item-rate-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  padding: 6px 12px;
  border-radius: 6px;
  background: rgba(139, 76, 108, 0.15);
  border: 1px solid rgba(139, 76, 108, 0.4);
  color: #d9a7c0;
  font-size: 12.5px;
  font-weight: 600;
  text-decoration: none;
  transition: background 0.2s ease;
}
.order-item-rate-btn:hover { background: rgba(139, 76, 108, 0.28); }
.order-item-reviewed {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  color: #10b981;
  font-size: 12.5px;
  font-weight: 600;
}

/* Disabled button styles */
button:disabled {
  cursor: not-allowed !important;
  opacity: 0.6 !important;
}

button:disabled:hover {
  transform: none !important;
  box-shadow: none !important;
}

@media (max-width: 640px) {
  .confirmation-page .container {
    padding: 20px 16px 10px 16px;
  }
  
  .confirmation-page .confirmation-header h1 {
    font-size: 24px;
  }
  
  .confirmation-page .order-details-card {
    padding: 20px;
    margin-bottom: 20px;
  }
  
  .confirmation-page .detail-row {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
  }
}

/* Modal Styles */
.modal-overlay {
  animation: fadeIn 0.3s ease-out;
}

.modal-container {
  animation: slideIn 0.3s ease-out;
}

.modal-overlay.closing {
  animation: fadeOut 0.3s ease-in;
}

.modal-container.closing {
  animation: slideOut 0.3s ease-in;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes fadeOut {
  from { opacity: 1; }
  to { opacity: 0; }
}

@keyframes slideIn {
  from { 
    opacity: 0;
    transform: translateY(-50px) scale(0.95);
  }
  to { 
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@keyframes slideOut {
  from { 
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  to { 
    opacity: 0;
    transform: translateY(-50px) scale(0.95);
  }
}

.modal-header button:hover {
  background: var(--border-color);
  color: var(--text-primary);
}

.modal-footer button:hover {
  background: var(--border-color);
  border-color: rgba(255, 255, 255, 0.3);
}

/* Form element styling */
select:focus, textarea:focus {
  border-color: #174871;
  box-shadow: 0 0 0 3px rgba(23, 72, 113, 0.1);
}

select:hover, textarea:hover {
  border-color: rgba(255, 255, 255, 0.3);
}

@media (max-width: 768px) {
  .modal-container {
    margin: 2% auto;
    max-width: 95%;
  }
  
  .modal-content {
    padding: 24px;
  }
  
  .modal-header {
    padding: 20px 24px;
  }
  
  .modal-footer {
    padding: 20px 24px;
    flex-direction: column;
  }
  
  .order-info-grid {
    grid-template-columns: 1fr;
    gap: 16px;
  }
  
  .order-item {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  
  .item-image {
    width: 100%;
    height: 120px;
  }
}
</style>

<script>
function openOrderSummaryModal() {
  const modal = document.getElementById('orderSummaryModal');
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden'; // Prevent background scrolling
  
  // Add animation classes
  modal.classList.remove('closing');
  modal.classList.add('opening');
}

function closeOrderSummaryModal() {
  const modal = document.getElementById('orderSummaryModal');
  
  // Add closing animation
  modal.classList.add('closing');
  
  // Hide modal after animation
  setTimeout(() => {
    modal.style.display = 'none';
    modal.classList.remove('closing');
    document.body.style.overflow = 'auto'; // Restore scrolling
  }, 300);
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
  const orderModal = document.getElementById('orderSummaryModal');
  const cancelModal = document.getElementById('cancelOrderModal');
  if (event.target === orderModal) {
    closeOrderSummaryModal();
  } else if (event.target === cancelModal) {
    closeCancelOrderModal();
  }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    const orderModal = document.getElementById('orderSummaryModal');
    const cancelModal = document.getElementById('cancelOrderModal');
    if (orderModal.style.display === 'block') {
      closeOrderSummaryModal();
    } else if (cancelModal.style.display === 'block') {
      closeCancelOrderModal();
    }
  }
});

// Cancel Order Modal Functions
function openCancelOrderModal() {
  const modal = document.getElementById('cancelOrderModal');
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  
  // Add animation classes
  modal.classList.remove('closing');
  modal.classList.add('opening');
}

function closeCancelOrderModal() {
  const modal = document.getElementById('cancelOrderModal');
  
  // Add closing animation
  modal.classList.add('closing');
  
  // Hide modal after animation
  setTimeout(() => {
    modal.style.display = 'none';
    modal.classList.remove('closing');
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('cancelOrderForm').reset();
    document.getElementById('custom_reason_group').style.display = 'none';
  }, 300);
}

// Auto-open the Order Summary modal when navigated here from the orders list "View" link
document.addEventListener('DOMContentLoaded', function() {
  const params = new URLSearchParams(window.location.search);
  if (params.get('summary') === '1') {
    openOrderSummaryModal();
  }
  // ?cancel=1 - the My Orders list's "Cancel order" button lands here, because
  // cancelling needs a reason (and, for a paid online order, a refund method)
  // and that form already exists on this page. This only opens it; the modal,
  // its fields and process_cancel_order.php are untouched.
  //
  // The button is rendered only while PHP's own $canCancel is true, so if the
  // 30-minute window has closed between the list being drawn and this page
  // loading, there is simply nothing to open and the page shows normally.
  if (params.get('cancel') === '1' && typeof openCancelOrderModal === 'function') {
    openCancelOrderModal();
  }
});

// Handle reason selection change
document.addEventListener('DOMContentLoaded', function() {
  const reasonSelect = document.getElementById('cancel_reason');
  const customReasonGroup = document.getElementById('custom_reason_group');
  const customReasonField = document.getElementById('custom_reason');
  
  if (reasonSelect && customReasonGroup && customReasonField) {
    reasonSelect.addEventListener('change', function() {
      if (this.value === 'Others') {
        customReasonGroup.style.display = 'block';
        customReasonField.required = true;
      } else {
        customReasonGroup.style.display = 'none';
        customReasonField.required = false;
        customReasonField.value = '';
      }
    });
  }
});

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
/* Copy-to-clipboard for the hero code. Entirely client-side: it reads the
   value already printed into the page and writes nothing back. */
(function () {
  var btn = document.getElementById('ordsCopyBtn');
  if (!btn) { return; }
  var original = btn.textContent;
  btn.addEventListener('click', function () {
    var value = btn.getAttribute('data-copy') || '';
    function done() {
      btn.textContent = 'Copied \u2713';
      btn.classList.add('is-done');
      setTimeout(function () {
        btn.textContent = original;
        btn.classList.remove('is-done');
      }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done).catch(fallback);
    } else { fallback(); }
    function fallback() {
      /* execCommand is deprecated but is the only route on a page served
         over plain http, where navigator.clipboard is undefined. */
      var ta = document.createElement('textarea');
      ta.value = value;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { /* leave the label alone */ }
      document.body.removeChild(ta);
    }
  });
})();
</script>

<!-- order-success-skin.php's sheet, linked LAST - after style.css,
     customer-dark.css, the icon sheets, this page's own <style> block and
     the footer's - so it wins on source order without needing !important
     on anything but the handful of rules that fight an inline style or a
     [data-theme="light"] !important declaration. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/css/order-success-skin.css') ?>">


