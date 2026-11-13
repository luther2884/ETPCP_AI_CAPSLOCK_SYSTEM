<?php
// customer/track_order.php - Order Tracking Page
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Track Order - TechWorld';
$activeNav = 'track_order';
$userId = getUserId();

// Get order ID from URL
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$trackingCode = isset($_GET['code']) ? trim($_GET['code']) : '';

// If neither id nor code supplied, show search UI instead of redirecting
$showSearch = false;
$order = null;
$orderItems = [];
// Live-map state, declared up here with the other defaults because the block
// that computes it further down only runs when an order was actually loaded.
// The search view (no id/code in the URL) and the PDOException path both skip
// it, and the template still tests $showLiveMap unconditionally.
$liveTracking = null;
$showLiveMap = false;
// Split from $showLiveMap: the card and the map now render as soon as a rider
// is assigned and the order is out for delivery, and this says whether that
// rider is actually sharing a position yet. They used to be the same flag, so
// the whole feature was invisible until the exact moment tracking switched on -
// and since nothing re-rendered the page, a customer sitting on it when the
// rider set off never saw the map at all.
$liveTrackingActive = false;
// Place details for the card, so it can name the drop-off before (and whether
// or not) any live position exists - see where these are filled in below.
$liveDropoffLabel = '';
$liveDropoffLat = null;
$liveDropoffLng = null;
if ($orderId <= 0 && $trackingCode === '') {
    $showSearch = true;
}

if (!$showSearch) {
    try {
        $pdo = getConnection();

        // Opportunistic sweep for online-payment orders left unpaid over an
        // hour - see cancelExpiredOnlinePaymentOrders() in config/functions.php.
        cancelExpiredOnlinePaymentOrders();

        // Get order details. seconds_until_expiry is computed entirely in
        // MySQL (never via PHP's strtotime()/time()) - this server's PHP
        // default timezone doesn't match the DB's real local time, so any
        // PHP-side date math here would drift by several hours (same class
        // of bug already fixed for the live-tracking "Last updated" time,
        // the checkout countdown, and the cancel-order timer). Matches
        // cancelExpiredOnlinePaymentOrders()'s own "created_at + 1 hour"
        // cutoff exactly, so this countdown always agrees with when the
        // order actually gets auto-cancelled.
        if ($orderId > 0) {
            $orderStmt = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL 1 HOUR) as seconds_until_expiry FROM orders WHERE id = ? AND user_id = ?");
            $orderStmt->execute([$orderId, $userId]);
        } else {
            // Accept either tracking number or invoice number; allow optional leading '#'
            $searchCode = ltrim($trackingCode, "# \t\n\r\0\x0B");
            $orderStmt = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL 1 HOUR) as seconds_until_expiry FROM orders WHERE (tracking_code = ? OR invoice_no = ?) AND user_id = ?");
            $orderStmt->execute([$searchCode, $searchCode, $userId]);
        }
    
        $order = $orderStmt->fetch();

        // Delivery address actually chosen at checkout (orders.address
        // doesn't exist - it's snapshotted in delivery_full_address). Falls
        // back to the customer's current saved address only for orders
        // placed before that snapshot existed (delivery_full_address is
        // NULL) - best effort, may not match what was actually chosen.
        if ($order && ($order['delivery_method'] ?? '') === 'delivery') {
            if (!empty($order['delivery_full_address'])) {
                $order['address'] = $order['delivery_full_address'];
            } else {
                $legacyAddrStmt = $pdo->prepare("
                    SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses
                    WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1
                ");
                $legacyAddrStmt->execute([$userId]);
                $order['address'] = $legacyAddrStmt->fetchColumn() ?: '';
            }
        }

        // Live Delivery Tracking - only ever considered for Home Delivery
        // orders with a driver assigned and actively 'shipped' ("Out for
        // Delivery"). Deliberately excludes 'completed' (and everything
        // earlier than 'shipped') so the map only shows up while a delivery
        // is genuinely in progress, matching this page's own existing
        // Order Progress/Checkpoints labels. This is only a render decision
        // for whether to draw the map shell at all - the actual live
        // coordinates always come from polling get_driver_location.php
        // client-side, never trusted from this page-load snapshot.
        $liveTracking = null;
        if ($order
            && ($order['delivery_method'] ?? '') === 'delivery'
            && !empty($order['assigned_driver_id'])
            && $order['status'] === 'shipped') {
            // Driven from orders.assigned_driver_id, not from delivery_tracking:
            // before the rider presses Start Tracking there is no row in that
            // table at all, and the old INNER JOIN therefore returned nothing -
            // which is what hid the card entirely rather than showing it
            // waiting. The LEFT JOIN keeps is_tracking when a row does exist.
            $trkStmt = $pdo->prepare("
                SELECT d.firstname as driver_firstname, d.lastname as driver_lastname,
                       COALESCE(dt.is_tracking, 0) as is_tracking
                FROM users d
                LEFT JOIN delivery_tracking dt ON dt.order_id = ?
                WHERE d.id = ?
            ");
            $trkStmt->execute([$order['id'], $order['assigned_driver_id']]);
            $liveTracking = $trkStmt->fetch() ?: null;
        }
        $showLiveMap = (bool)$liveTracking;
        $liveTrackingActive = $showLiveMap && (int)($liveTracking['is_tracking'] ?? 0) === 1;

        // Where this delivery is going, resolved here rather than waiting on a
        // poll. The card has to name a place from the moment it renders: until
        // the rider presses Start Tracking there is no position to show, and
        // without this the standby state was a map of the store and a line
        // saying nothing had happened yet.
        if ($showLiveMap) {
            $liveDropoffLabel = trim((string)($order['delivery_full_address'] ?? ''));
            if ($liveDropoffLabel === '' || strtoupper($liveDropoffLabel) === 'N/A') {
                $liveDropoffLabel = trim((string)($order['address'] ?? ''));
            }
            // The customer's own map pin beats the address text - it needs no
            // geocoding and survives a misspelled street. Same precedence
            // get_driver_location.php uses once tracking is live.
            $pinLat = $order['delivery_latitude'] ?? null;
            $pinLng = $order['delivery_longitude'] ?? null;
            if ($pinLat !== null && $pinLng !== null && is_numeric($pinLat) && is_numeric($pinLng)) {
                $liveDropoffLat = (float)$pinLat;
                $liveDropoffLng = (float)$pinLng;
            }
        }

        if ($order) {
            // Get order items
            $itemsStmt = $pdo->prepare("
                SELECT oi.*, p.name as product_name, p.image_url 
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = ?
            ");
            $itemsStmt->execute([$order['id']]);
            $orderItems = $itemsStmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('Error loading order: ' . $e->getMessage());
        $order = null;
        $orderItems = [];
        $liveTracking = null;
        $showLiveMap = false;
        $liveTrackingActive = false;
    }
}

// Load recent orders for search view or when not found
$recentOrders = [];
if ($showSearch || (!$showSearch && empty($order))) {
    try {
        $pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : getConnection();
        $stmt = $pdo->prepare("SELECT id, invoice_no, status, total_amount, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$userId]);
        $recentOrders = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Error loading recent orders: ' . $e->getMessage());
        $recentOrders = [];
    }
}

// Define tracking stages
$trackingStages = [
    'preparing' => [
        'title' => 'Preparing',
        'icon' => 'fi fi-rr-clipboard-list',
        'description' => 'Your order has been received and is being prepared'
    ],
    'packed' => [
        'title' => 'Packed',
        'icon' => 'fi fi-rr-package',
        'description' => 'Your order has been packed and is ready for shipment'
    ],
    'ready' => [
        'title' => 'Ready to Deliver',
        'icon' => 'fi fi-ss-person-dolly',
        'description' => 'Your order is ready and waiting for delivery'
    ],
    'shipped' => [
        'title' => 'Shipped',
        'icon' => 'fi fi-rr-motorcycle',
        'description' => 'Your order is on its way to you'
    ],
    'completed' => [
        'title' => 'Completed',
        'icon' => 'fi fi-rr-check',
        'description' => 'Your order has been successfully delivered'
    ],
    'cancelled' => [
        'title' => 'Cancelled',
        'icon' => 'fi fi-rr-cross',
        'description' => 'This order has been cancelled'
    ]
];

// orders.status is 'preparing' from the moment an order is placed,
// regardless of payment_status - the two are independent columns, and
// staff can't actually start preparing an online-payment order until it's
// paid (see the server-side guard in Home_Delivery_Orders.php/
// store_pickup_orders.php). "Your order has been received and is being
// prepared" would be inaccurate here, so this stage's title/description are
// overridden while payment is still outstanding - same fix already applied
// to orders.php, Home_Delivery_Orders.php, and store_pickup_orders.php.
$needsOnlinePayment = $order
    && in_array($order['payment_method'] ?? '', ['gcash', 'paymaya', 'xendit'], true)
    && ($order['payment_status'] ?? '') !== 'paid'
    && !in_array($order['status'] ?? '', ['cancelled', 'completed'], true);
if ($needsOnlinePayment) {
    $trackingStages['preparing'] = [
        'title' => 'Awaiting Payment',
        'icon' => 'fi fi-rr-clock',
        'description' => 'Waiting for your payment to be confirmed before we start preparing your order'
    ];
}

// Define tracking stages for In-Store Payment orders (simplified)
$onsiteTrackingStages = [
    'preparing' => [
        'title' => 'Preparing',
        'icon' => 'fi fi-rr-clipboard-list',
        'description' => 'Your order is being prepared for pickup'
    ],
    'completed' => [
        'title' => 'Complete',
        'icon' => 'fi fi-rr-check',
        'description' => 'Your order is ready for pickup'
    ],
    'cancelled' => [
        'title' => 'Cancel',
        'icon' => 'fi fi-rr-cross',
        'description' => 'This order has been cancelled'
    ]
];

// Map internal statuses to courier-style labels (5 stages)
$statusToCourierLabel = [
	'awaiting_payment' => 'Awaiting Payment',
	'preparing' => 'Ordered',
	'packed' => 'Packed',
	'ready' => 'Ready for Delivery',
	'shipped' => 'Out for Delivery',
	'completed' => 'Delivered'
];

// Map internal statuses to courier-style labels for In-Store Payment orders (simplified)
$onsiteStatusToCourierLabel = [
	'awaiting_payment' => 'Awaiting Payment',
	'preparing' => 'Preparing',
	'completed' => 'Ready for Pickup'
];

// Determine current stage (only when order is present)
$currentStatus = $order['status'] ?? null;
$isOnsitePayment = $order && $order['payment_method'] === 'onsite';

// Set stage order based on payment method
if ($isOnsitePayment) {
    $stageOrder = ['preparing', 'completed']; // Only 2 stages for onsite payment
    $trackingStages = $onsiteTrackingStages; // Use simplified tracking stages
} else {
    $stageOrder = ['preparing', 'packed', 'ready', 'shipped', 'completed']; // Full stages for regular orders
}

$currentStageIndex = $currentStatus !== null ? array_search($currentStatus, $stageOrder) : -2; // -2: not applicable

// Handle cancelled orders
if ($currentStatus === 'cancelled') {
    $currentStageIndex = -1; // Special case for cancelled
}

// Build checkpoints list (uses available timestamps)
$checkpoints = [];
if ($currentStatus !== null && $currentStageIndex >= 0) {
	foreach ($stageOrder as $index => $statusKey) {
		$state = $index < $currentStageIndex ? 'completed' : ($index === $currentStageIndex ? 'current' : 'pending');
		$timestamp = null;
		if ($statusKey === 'preparing') {
			$timestamp = $order['created_at'] ?? null;
		} elseif ($index === $currentStageIndex) {
			$timestamp = $order['updated_at'] ?? null;
		}
		
		// Use appropriate label mapping based on payment method
		$labelMapping = $isOnsitePayment ? $onsiteStatusToCourierLabel : $statusToCourierLabel;
		$checkpoints[] = [
			'label' => $labelMapping[$statusKey] ?? ucfirst($statusKey),
			'state' => $state,
			'time' => $timestamp
		];
	}
}

// One plain-language read of where the order is, for the badge beside the
// order number. Reuses the same courier labels as the checkpoint list so the
// headline and the list can never disagree, and follows $needsOnlinePayment
// for the same reason the 'preparing' stage does above: orders.status is
// already 'preparing' before the payment lands, and "Ordered" would overstate
// it. The CSS colour comes from orders.status itself (the class below), not
// from the label, so an unpaid order still reads amber.
$statusBadgeLabel = null;
if ($currentStatus !== null) {
	if ($currentStatus === 'cancelled') {
		$statusBadgeLabel = 'Cancelled';
	} elseif ($needsOnlinePayment) {
		$statusBadgeLabel = 'Awaiting Payment';
	} else {
		$badgeLabels = $isOnsitePayment ? $onsiteStatusToCourierLabel : $statusToCourierLabel;
		$statusBadgeLabel = $badgeLabels[$currentStatus] ?? ucfirst($currentStatus);
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js
         is deferred and would run a frame after first paint, which is exactly
         the flash this prevents. Resolution order is localStorage -> OS
         preference -> dark. There is no server-rendered preference to fall
         back on yet - `users` has no theme column; see the TODO in theme.js. */ ?>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
  <!-- Figtree for headings, JetBrains Mono for eyebrows, codes and the
       small-caps labels - the pair the landing page and the other redesigned
       customer pages load. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<section class="track-order-page">
    <div class="container">
		<div class="track-header">
			<div class="cx-eyebrow"><span aria-hidden="true"></span>Live tracking</div>
			<h1 class="cx-title">Track Your <em>Order</em></h1>
			<p class="cx-lede">Monitor the progress of your order in real-time</p>
		</div>
		<div class="cx-rule" aria-hidden="true"><i></i></div>

		<?php if ($showSearch): ?>
			<div class="order-info-card">
				<form action="" method="get" class="tracking-search-form">
					<label for="code" style="display:block;margin-bottom:8px;">Enter tracking number</label>
					<div style="display:flex;gap:10px;align-items:center;">
						<input type="text" id="code" name="code" value="" placeholder="e.g. UFxxxxx2YP" required style="flex:1;padding:12px 14px;border-radius:8px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--input-text);">
						<button type="submit" class="btn-primary">Search</button>
					</div>
				</form>
				<?php if (!empty($recentOrders)): ?>
					<div class="recent-orders">
						<h3>Recent Orders</h3>
						<ul>
							<?php foreach ($recentOrders as $ro): ?>
								<li>
									<div class="ro-meta">
										<span class="ro-id">#<?= htmlspecialchars($ro['invoice_no']) ?></span>
										<span class="ro-date"><?= date('M j, Y', strtotime($ro['created_at'])) ?></span>
										<span class="ro-status <?= htmlspecialchars($ro['status']) ?>"><?= ucfirst($ro['status']) ?></span>
									</div>
									<div class="ro-actions">
										<a class="btn-secondary" href="<?= WEB_ROOT ?>public/authenticated/customer/track_order.php?code=<?= urlencode($ro['invoice_no']) ?>">Track</a>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		<?php elseif (!$order): ?>
			<div class="order-info-card">
				<p>No order found for that tracking number. Please try again.</p>
				<form action="" method="get" class="tracking-search-form" style="margin-top:15px;">
					<div style="display:flex;gap:10px;align-items:center;">
						<input type="text" name="code" value="<?= htmlspecialchars($trackingCode) ?>" placeholder="Enter tracking number" required style="flex:1;padding:12px 14px;border-radius:8px;border:1px solid var(--input-border);background:var(--input-bg);color:var(--input-text);">
						<button type="submit" class="btn-primary">Search</button>
					</div>
				</form>
				<?php if (!empty($recentOrders)): ?>
					<div class="recent-orders" style="margin-top:16px;">
						<h3>Recent Orders</h3>
						<ul>
							<?php foreach ($recentOrders as $ro): ?>
								<li>
									<div class="ro-meta">
										<span class="ro-id">#<?= htmlspecialchars($ro['invoice_no']) ?></span>
										<span class="ro-date"><?= date('M j, Y', strtotime($ro['created_at'])) ?></span>
										<span class="ro-status <?= htmlspecialchars($ro['status']) ?>"><?= ucfirst($ro['status']) ?></span>
									</div>
									<div class="ro-actions">
										<a class="btn-secondary" href="<?= WEB_ROOT ?>public/authenticated/customer/track_order.php?code=<?= urlencode($ro['invoice_no']) ?>">Track</a>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		<?php else: ?>

		<!-- Tracking Modal Overlay -->
		<div class="tracking-modal-overlay show" id="trackingModal">
			<div class="tracking-modal">
				<button type="button" class="tracking-modal-close" aria-label="Close" onclick="window.location='<?= WEB_ROOT ?>public/authenticated/customer/track_order.php'">×</button>
				<button type="button" class="scroll-more-btn" id="scrollMoreBtn" title="See more details">More ↓</button>
				<div class="tracking-modal-body" id="modalScroll">

		<!-- Order Information -->
		<div class="order-info-card pop-in">
			<div class="order-details">
				<div class="order-headline">
					<h2>Order #<?= htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'], 6, '0', STR_PAD_LEFT)) ?></h2>
					<?php if ($statusBadgeLabel !== null): ?>
						<span class="order-status-badge <?= htmlspecialchars($currentStatus) ?>"><?= htmlspecialchars($statusBadgeLabel) ?></span>
					<?php endif; ?>
				</div>
				<div class="order-meta">
					<span class="om-date"><strong>Order Date:</strong> <?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></span>
					<span class="om-total"><strong>Total Amount:</strong> <?= formatCurrency($order['total_amount']) ?></span>
					<span class="om-pay"><strong>Payment Method:</strong> <?= htmlspecialchars(getPaymentMethodLabel($order['payment_method'])) ?>
						<?php if ($needsOnlinePayment): ?>
							<span style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:rgba(239,68,68,.2);color:#ef4444;">Unpaid</span>
							<a href="<?= WEB_ROOT ?>public/authenticated/customer/<?= $order['payment_method'] === 'xendit' ? 'xendit_payment.php' : 'epayment_instructions.php' ?>?id=<?= (int)$order['id'] ?>" style="display:inline-block;margin-left:6px;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#ef4444;color:#fff;text-decoration:none;"><?= $order['payment_method'] === 'xendit' ? 'Pay with Xendit' : 'Complete Payment' ?></a>
							<span class="pay-countdown" data-seconds-left="<?= max(0, (int)($order['seconds_until_expiry'] ?? 0)) ?>" style="display:inline-block;margin-left:6px;font-size:11px;color:#ef4444;font-weight:600;white-space:nowrap;"></span>
						<?php endif; ?>
					</span>
					<?php if (!empty($order['tracking_code'])): ?>
						<span class="om-code"><strong>Tracking Code:</strong> <?= htmlspecialchars($order['tracking_code']) ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Order Tracking Timeline -->
		<div class="tracking-timeline pop-in">
			<h3>Order Progress</h3>
			<div class="timeline-container">
				<?php foreach ($stageOrder as $index => $stage): ?>
					<?php 
					$stageData = $trackingStages[$stage];
					$isCompleted = $index <= $currentStageIndex;
					$isCurrent = $index === $currentStageIndex;
					$isCancelled = $currentStatus === 'cancelled';
					?>
					<div class="timeline-stage <?= $isCompleted ? 'completed' : '' ?> <?= $isCurrent ? 'current' : '' ?> <?= $isCancelled ? 'cancelled' : '' ?>">
						<div class="stage-circle">
							<i class="<?= $stageData['icon'] ?>"></i>
				</div>
						<div class="stage-content">
							<h4><?= $stageData['title'] ?></h4>
							<p><?= $stageData['description'] ?></p>
						</div>
						<?php if ($index < count($stageOrder) - 1): ?>
							<?php $isConnectorCompleted = $index + 1 <= $currentStageIndex; ?>
							<div class="timeline-connector <?= $isConnectorCompleted ? 'completed' : '' ?>"></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				
				<!-- Cancelled Stage (if applicable) -->
				<?php if ($isCancelled): ?>
					<div class="timeline-stage cancelled">
						<div class="stage-circle">
							<i class="<?= $trackingStages['cancelled']['icon'] ?>"></i>
						</div>
						<div class="stage-content">
							<h4><?= $trackingStages['cancelled']['title'] ?></h4>
							<p><?= $trackingStages['cancelled']['description'] ?></p>
						</div>
					</div>
				<?php endif; ?>
			</div>
					</div>

			<?php if ($showLiveMap): ?>
			<!-- Live Delivery Tracking - rendered for an active Home Delivery
			     order with an assigned driver (see $showLiveMap above), whether
			     or not that driver has started sharing yet
			     ($liveTrackingActive). The map polls either way and fills in by
			     itself the moment the rider presses Start Tracking, so this no
			     longer depends on the customer happening to reload at the right
			     second. Sits between Order Progress and Checkpoints. -->
			<div class="live-tracking-card pop-in">
				<h3><i class="fi fi-rr-motorcycle"></i> Live Delivery Tracking</h3>
				<div class="driver-info-row">
					<div class="driver-avatar"><i class="fi fi-rr-motorcycle"></i></div>
					<div class="driver-meta">
						<strong><?= htmlspecialchars(trim($liveTracking['driver_firstname'] . ' ' . $liveTracking['driver_lastname'])) ?></strong>
						<span id="liveTrackingUpdatedAt"><?= $liveTrackingActive ? 'Connecting…' : 'Not sharing location yet' ?></span>
					</div>
					<span class="live-badge<?= $liveTrackingActive ? '' : ' live-badge--waiting' ?>"><?= $liveTrackingActive ? '🟢 LIVE' : '⚪ STANDBY' ?></span>
				</div>
				<!-- The route in words. Printed server-side and never touched by
				     the poller, so the card always names where this order is
				     coming from and going to - including in standby, where
				     there is no courier position to show yet. -->
				<p class="live-route">
					<span class="live-route-leg">
						<i class="fi fi-rr-shop" aria-hidden="true"></i>
						<span><span class="live-route-tag">From</span><?= htmlspecialchars(STORE_ADDRESS_LABEL) ?></span>
					</span>
					<span class="live-route-arrow" aria-hidden="true">→</span>
					<span class="live-route-leg">
						<i class="fi fi-rr-marker" aria-hidden="true"></i>
						<span><span class="live-route-tag">To</span><?= $liveDropoffLabel !== '' ? htmlspecialchars($liveDropoffLabel) : 'your saved delivery address' ?></span>
					</span>
				</p>
				<div class="live-map-wrap">
					<div id="liveTrackingMap"></div>
					<button type="button" id="liveTrackingRecenter" class="live-recenter" title="Recenter the map">
						<i class="fi fi-rr-marker"></i> Recenter
					</button>
				</div>
				<p id="liveTrackingEta" class="live-tracking-eta"></p>
				<p id="liveTrackingStatusText"><?= $liveTrackingActive ? 'Connecting…' : 'Waiting for your courier to start sharing their location…' ?></p>
				<?php if (!$liveTrackingActive): ?>
					<!-- Only in standby. Says what the map IS showing while it has
					     no courier on it, so the pins read as deliberate rather
					     than as a feed that has failed. -->
					<p class="live-standby-note">
						<?= htmlspecialchars(trim(($liveTracking['driver_firstname'] ?? '') . ' ' . ($liveTracking['driver_lastname'] ?? ''))) ?: 'Your courier' ?>
						has your order and is assigned to this delivery. The map already shows the pick-up point and your
						drop-off<?= $liveDropoffLabel !== '' ? ' at ' . htmlspecialchars($liveDropoffLabel) : '' ?> &mdash;
						their live position joins it the moment they start sharing, with no need to reload this page.
					</p>
				<?php endif; ?>
			</div>
			<script>window.__liveTrackingOrderId = <?= (int)$order['id'] ?>;</script>
			<?php endif; ?>

			<!-- Courier Checkpoints -->
		<div class="checkpoints pop-in">
				<h4>Checkpoints</h4>
				<ul class="checkpoint-list">
					<?php foreach ($checkpoints as $cp): ?>
						<li class="checkpoint <?= htmlspecialchars($cp['state']) ?>">
							<span class="dot"></span>
							<span class="label"><?= htmlspecialchars($cp['label']) ?></span>
							<?php if (!empty($cp['time'])): ?>
								<span class="time"><?= date('Y-m-d H:i', strtotime($cp['time'])) ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

		<!-- Order Items -->
		<div class="order-items-section pop-in" id="moreInfoStart">
			<h3>Order Items</h3>
			<div class="order-items-list">
				<?php foreach ($orderItems as $item): ?>
					<div class="order-item">
						<div class="item-image">
							<?php if (!empty($item['image_url'])): ?>
								<img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
						<?php else: ?>
							<div class="image-placeholder">
								<i class="fi fi-rr-package"></i>
							</div>
						<?php endif; ?>
						</div>
						<div class="item-details">
							<h4><?= htmlspecialchars($item['product_name']) ?></h4>
							<p>Quantity: <?= $item['quantity'] ?></p>
							<p>Price: <?= formatCurrency($item['price']) ?></p>
						</div>
						<div class="item-total">
							<?= formatCurrency($item['quantity'] * $item['price']) ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Delivery Information -->
		<div class="delivery-info pop-in">
			<h3>Delivery Information</h3>
			<div class="delivery-details">
				<div class="delivery-method">
					<strong>Delivery Method:</strong> 
					<?= ($order['delivery_method'] ?? 'pickup') === 'pickup' ? 'Store Pickup' : 'Home Delivery' ?>
				</div>
				<div class="payment-method">
					<strong>Payment Method:</strong> 
					<?= ($order['delivery_method'] ?? 'pickup') === 'delivery' ? 'Cash on Delivery' : 'Pay at Store' ?>
				</div>
				<?php if (!empty($order['address'])): ?>
					<div class="delivery-address">
						<strong>Delivery Address:</strong>
						<p><?= htmlspecialchars($order['address']) ?></p>
					</div>
				<?php endif; ?>
				<?php if (!empty($order['tracking_code'])): ?>
					<div class="tracking-number">
						<strong>Tracking Number:</strong> 
						<span><?= htmlspecialchars($order['tracking_code']) ?></span>
					</div>
				<?php endif; ?>
				<?php if (!empty($order['pickup_code']) && ($order['delivery_method'] ?? 'pickup') === 'pickup'): ?>
					<div class="pickup-code">
						<strong>Pickup Code:</strong> <?= htmlspecialchars($order['pickup_code']) ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Action Buttons -->
		<div class="tracking-actions">
			<a href="<?= WEB_ROOT ?>public/authenticated/customer/track_order.php" class="btn-secondary">
				<i class="fi fi-rr-arrow-left"></i> Back
			</a>
			<?php if (($order['delivery_method'] ?? 'pickup') === 'pickup' && ($order['status'] ?? '') === 'ready'): ?>
				<a href="<?= WEB_ROOT ?>public/authenticated/customer/pickup.php?order_id=<?= $order['id'] ?>" class="btn-primary">
					<i class="fi fi-rr-qr-code"></i> Show Pickup QR Code
				</a>
			<?php endif; ?>
		</div>
			</div>
		</div>
		<?php endif; ?>
    </div>
</section>

<style>
/* ===========================================================================
   Order tracking, in the landing page's visual language.

   PRESENTATION ONLY. Every hook the scripts use is untouched and still
   carries the same meaning: #trackingModal / .tracking-modal-overlay.show /
   body.modal-open, #scrollMoreBtn / #moreInfoStart / #modalScroll, .pop-in,
   #liveTrackingMap / #liveTrackingStatusText / #liveTrackingUpdatedAt /
   #liveTrackingEta / #liveTrackingRecenter, the .tw-courier-* marker classes
   that live-tracking-map.js builds its divIcon from, .pay-countdown, and the
   .completed / .current / .cancelled / .pending state classes on the timeline
   and the checkpoint list.

   The shared .cx-* primitives (eyebrow, title, rule) live in
   customer-dark.css; the dark navy ground is already painted there for
   .track-order-page.

   What changed: this page mixed three unrelated palettes - a warm vanilla
   card, grey-on-grey timeline stages, and a red primary button - none of
   which belong to the landing system. Panels are now the landing panel (navy
   gradient over a 1px blue hairline), progress reads in cyan and green, and
   the primary action is the landing CTA gradient.
   ======================================================================== */
.track-order-page {
    padding: 40px 0 64px;
    background: var(--page-bg-gradient);
    min-height: 100vh;
}
.track-order-page .container { max-width: 1180px; }

/* --- header --------------------------------------------------------------- */
.track-header { text-align: left; margin-bottom: 0; }

/* --- shared panel --------------------------------------------------------- */
/* One surface for every block on this page, so the screen reads as a stack of
   the same material rather than a vanilla card above translucent ones. */
.order-info-card,
.tracking-timeline,
.live-tracking-card,
.order-items-section,
.delivery-info {
    padding: 24px;
    margin-bottom: 18px;
    border: 1px solid var(--cx-line);
    border-radius: 20px;
    background: var(--cx-panel);
    box-shadow: 0 20px 48px rgba(3, 8, 16, .4);
}
.order-info-card h3,
.tracking-timeline h3,
.live-tracking-card h3,
.order-items-section h3,
.delivery-info h3,
.checkpoints h4 {
    display: flex; align-items: center; gap: 10px;
    margin: 0 0 18px;
    font-family: var(--cx-font);
    font-size: 18px; font-weight: 800; letter-spacing: -.01em;
    color: #fff;
}
/* A short accent bar in front of every section title. It is the one piece of
   colour that repeats the whole way down the sheet, so the five panels read as
   one document rather than five unrelated cards - and it gives every heading a
   fixed left edge for the eye to return to while scrolling. */
.order-info-card h3::before,
.tracking-timeline h3::before,
.live-tracking-card h3::before,
.order-items-section h3::before,
.delivery-info h3::before,
.checkpoints h4::before {
    content: ''; flex: none;
    width: 3px; height: 17px; border-radius: 2px;
    background: linear-gradient(180deg, var(--cx-cyan), var(--cx-violet));
}
.live-tracking-card h3 i { color: var(--cx-cyan); }

/* --- the order identity block --------------------------------------------- */
/* The invoice number and the order's state belong on one line. The first
   question this page answers is "where is my order", and it used to be
   answerable only by working out which of five grey circles was glowing. */
.order-headline {
    display: flex; align-items: center; flex-wrap: wrap; gap: 12px;
    margin: 0 0 18px;
}
.order-details h2 {
    margin: 0;
    font-family: var(--cx-mono);
    font-size: 20px; font-weight: 700; letter-spacing: .02em;
    color: #fff;
    overflow-wrap: anywhere;
}
.order-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 12px;
}
/* Each fact becomes its own small plate: a mono label over the value. */
.order-meta span {
    display: block;
    padding: 13px 15px;
    border: 1px solid var(--cx-line);
    border-radius: 14px;
    background: var(--cx-panel-soft);
    font-family: var(--cx-font);
    font-size: 15px; line-height: 1.45; color: #fff;
}
/* These labels were 9px uppercase mono tracked out to .22em. The colour was
   already fine (7.7:1 on the plate); the SIZE was the problem - letterspaced
   caps that small stop being read and start being skipped. 10.5px at .14em
   keeps the same quiet role and is actually legible, and the tone moves up one
   step to the faint ink so label and value separate by weight, not by murk. */
.order-meta span strong {
    display: block; margin-bottom: 6px;
    font-family: var(--cx-mono);
    font-size: 10.5px; font-weight: 600; letter-spacing: .14em;
    text-transform: uppercase; color: var(--cx-ink-faint);
}
/* The money is the one fact a customer re-reads, so it stops being the same
   15px as "Home Delivery" and takes a tinted plate of its own. */
.order-meta .om-total {
    border-color: rgba(103, 232, 249, .26);
    background:
        linear-gradient(140deg, rgba(103, 232, 249, .10), transparent 65%),
        var(--cx-panel-soft);
    font-size: 21px; font-weight: 800; letter-spacing: -.015em;
}
/* The tracking code is a code - it reads as one in mono, and it now matches
   the same code repeated in the Delivery Information panel further down. */
.order-meta .om-code {
    font-family: var(--cx-mono);
    font-size: 15px; font-weight: 700; letter-spacing: .06em;
    color: var(--cx-cyan);
}

/* --- the order's state, as a badge ---------------------------------------- */
/* Shared with the search view's result pills, so one order can never read
   amber in the list and grey in the detail. */
.recent-orders .ro-status,
.order-status-badge {
    padding: 6px 13px; white-space: nowrap;
    border: 1px solid transparent; border-radius: 999px;
    font-family: var(--cx-mono); font-size: 10px; font-weight: 700;
    letter-spacing: .16em; text-transform: uppercase;
    background: rgba(var(--tw-veil-rgb, 255, 255, 255), .06); color: var(--cx-ink-dim);
}
.recent-orders .ro-status.preparing, .order-status-badge.preparing { background: rgba(252, 211, 77, .14); color: var(--cx-amber); border-color: rgba(252, 211, 77, .40); }
.recent-orders .ro-status.packed,    .order-status-badge.packed    { background: rgba(127, 106, 224, .18); color: #b9adf4; border-color: rgba(127, 106, 224, .48); }
.recent-orders .ro-status.ready,     .order-status-badge.ready     { background: rgba(157, 243, 208, .14); color: var(--tw-ok, #9df3d0); border-color: rgba(157, 243, 208, .40); }
.recent-orders .ro-status.shipped,   .order-status-badge.shipped   { background: rgba(103, 232, 249, .14); color: var(--cx-cyan); border-color: rgba(103, 232, 249, .42); }
.recent-orders .ro-status.completed, .order-status-badge.completed { background: rgba(74, 222, 128, .14); color: var(--cx-green); border-color: rgba(74, 222, 128, .40); }
.recent-orders .ro-status.cancelled, .order-status-badge.cancelled { background: rgba(224, 86, 122, .16); color: var(--cx-pink-lt); border-color: rgba(224, 86, 122, .44); }

/* --- search view ---------------------------------------------------------- */
.tracking-search-form label {
    font-family: var(--cx-mono);
    font-size: 10px; letter-spacing: .2em; text-transform: uppercase;
    color: var(--cx-ink-faint);
}
/* The two search inputs carry inline style attributes from the markup, so
   these need !important to reach them. Nothing about the field's behaviour
   changes - only its colours. */
.order-info-card input[type="text"] {
    background: rgba(6, 13, 24, .65) !important;
    border: 1px solid var(--cx-line) !important;
    border-radius: 999px !important;
    padding: 13px 18px !important;
    color: #fff !important;
    font-family: var(--cx-font) !important;
    font-size: 14px !important;
}
.order-info-card input[type="text"]::placeholder { color: var(--cx-ink-label); }
.order-info-card input[type="text"]:focus {
    outline: none;
    border-color: rgba(103, 232, 249, .55) !important;
    box-shadow: 0 0 0 3px rgba(103, 232, 249, .12) !important;
}
.order-info-card > p { margin: 0; color: var(--cx-ink-dim); font-size: 15px; line-height: 1.6; }

.recent-orders h3 { margin: 22px 0 12px; }
.recent-orders ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 9px; }
.recent-orders li {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px;
    padding: 13px 16px;
    border: 1px solid var(--cx-line);
    border-radius: 14px;
    background: var(--cx-panel-soft);
    transition: border-color .2s ease, transform .2s var(--cx-ease);
}
.recent-orders li:hover { transform: translateY(-1px); border-color: var(--tw-line-ctl, rgba(120, 170, 230, .55)); }
.recent-orders .ro-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 14px; }
.recent-orders .ro-id { font-family: var(--cx-mono); font-size: 13px; font-weight: 700; color: #fff; }
.recent-orders .ro-date { font-family: var(--cx-mono); font-size: 11px; letter-spacing: .06em; color: var(--cx-ink-faint); }
.recent-orders .ro-actions .btn-secondary { padding: 8px 16px; }

/* --- progress timeline ---------------------------------------------------- */
.timeline-container { position: relative; display: flex; align-items: flex-start; padding: 16px 0 4px; }
.timeline-stage { position: relative; flex: 1; display: flex; flex-direction: column; align-items: center; }
.stage-circle {
    position: relative; z-index: 2;
    width: 58px; height: 58px; margin-bottom: 14px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid rgba(var(--tw-line-rgb, 120, 170, 230), .62);
    border-radius: 50%;
    background: rgba(6, 13, 24, .8);
    transition: background .3s ease, border-color .3s ease, box-shadow .3s ease;
}
.stage-circle i { font-size: 22px; color: var(--cx-ink-faint); transition: color .3s ease; }

/* A passed stage is cyan and settled - the route already travelled. */
.timeline-stage.completed .stage-circle {
    background: rgba(103, 232, 249, .14);
    border-color: rgba(103, 232, 249, .5);
}
.timeline-stage.completed .stage-circle i { color: var(--cx-cyan); }

/* The current stage is the only one that glows. It carries .completed too
   (see the PHP above), so this must come later at equal specificity - it
   does. */
.timeline-stage.current .stage-circle {
    background: linear-gradient(140deg, rgba(74, 222, 128, .9), rgba(34, 197, 94, .75));
    border-color: #4ade80;
    box-shadow: 0 0 0 5px rgba(74, 222, 128, .12), 0 0 26px rgba(74, 222, 128, .45);
    animation: pulse-green 2.4s ease-in-out infinite;
}
.timeline-stage.current .stage-circle i { color: #04210f; }

.timeline-stage.cancelled .stage-circle {
    background: rgba(224, 86, 122, .16);
    border-color: rgba(224, 86, 122, .5);
    box-shadow: none;
    animation: none;
}
.timeline-stage.cancelled .stage-circle i { color: var(--cx-pink-lt); }

.stage-content { text-align: center; padding: 0 6px; }
/* Five stage names and five one-line explanations are the bulk of this
   panel's text, and four of the five are always in the un-reached tone - so
   this is where "the text is hard to read" actually lives. The name goes to
   11px at .12em (a stage name is a name, not a caption), the description to
   12.5px with real leading, and both move a step brighter. */
.stage-content h4 {
    margin: 0 0 6px;
    font-family: var(--cx-mono);
    font-size: 11px; font-weight: 700; letter-spacing: .12em;
    text-transform: uppercase; color: var(--cx-ink-faint);
}
.stage-content p { margin: 0; font-size: 12.5px; line-height: 1.55; color: var(--cx-ink-label); }
.timeline-stage.completed .stage-content h4 { color: var(--cx-cyan); }
.timeline-stage.completed .stage-content p { color: var(--cx-ink-dim); }
.timeline-stage.current .stage-content h4 { color: var(--cx-green); }
.timeline-stage.current .stage-content p { color: var(--cx-ink-dim); }
.timeline-stage.cancelled .stage-content h4 { color: var(--cx-pink-lt); }

.timeline-connector {
    position: absolute; z-index: 1;
    top: 28px; left: 50%;
    width: 100%; height: 2px;
    background: rgba(var(--tw-line-rgb, 120, 170, 230), .56);
}
.timeline-connector.completed { background: linear-gradient(90deg, rgba(103, 232, 249, .7), rgba(103, 232, 249, .35)); }

@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 5px rgba(74, 222, 128, .12), 0 0 26px rgba(74, 222, 128, .45); }
    50%      { box-shadow: 0 0 0 9px rgba(74, 222, 128, .05), 0 0 34px rgba(74, 222, 128, .65); }
}

/* --- live delivery -------------------------------------------------------- */
.driver-info-row {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 14px; padding: 12px 14px;
    border: 1px solid var(--cx-line); border-radius: 16px;
    background: var(--cx-panel-soft);
}
.driver-avatar {
    width: 44px; height: 44px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; font-size: 20px; color: #04202e;
    background: linear-gradient(140deg, var(--cx-cyan), #3fb8d6);
    box-shadow: 0 6px 18px rgba(103, 232, 249, .28);
}
.driver-meta { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.driver-meta strong { font-family: var(--cx-font); font-size: 15px; font-weight: 700; color: #fff; }
.driver-meta span { font-family: var(--cx-mono); font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: var(--cx-ink-faint); }
.live-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; white-space: nowrap;
    border: 1px solid rgba(74, 222, 128, .45); border-radius: 999px;
    background: rgba(74, 222, 128, .12);
    font-family: var(--cx-mono); font-size: 9px; font-weight: 700;
    letter-spacing: .18em; color: var(--cx-green);
}
/* Standby - a rider is assigned and on the order, but has not started sharing
   a position yet. Deliberately not green: the badge is the one element people
   read as "is this feed real right now". */
.live-badge--waiting {
    border-color: var(--cx-line);
    background: rgba(255, 255, 255, .06);
    color: var(--cx-ink-faint);
}
/* The written route - the card's one piece of information that does not depend
   on a live feed, so it is styled to read as fact rather than as status. */
.live-route {
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px;
    margin: 14px 0 0; padding: 12px 14px;
    border: 1px solid var(--cx-line); border-radius: 14px;
    background: rgba(255, 255, 255, .04);
    font-family: var(--cx-font); font-size: 13px; line-height: 1.4; color: #fff;
}
.live-route-leg { display: flex; align-items: flex-start; gap: 8px; flex: 1 1 220px; min-width: 0; }
.live-route-leg i { margin-top: 2px; font-size: 13px; color: var(--cx-green); flex-shrink: 0; }
.live-route-leg > span { min-width: 0; overflow-wrap: anywhere; }
.live-route-tag {
    display: block;
    font-family: var(--cx-mono); font-size: 9px; font-weight: 700;
    letter-spacing: .18em; text-transform: uppercase; color: var(--cx-ink-faint);
}
.live-route-arrow { color: var(--cx-ink-faint); font-size: 15px; flex-shrink: 0; }
@media (max-width: 520px) {
    /* Stacked legs read as two lines, so the horizontal arrow stops making
       sense - the From/To tags already carry the direction. */
    .live-route-arrow { display: none; }
}
.live-standby-note {
    margin: 10px 0 0;
    font-family: var(--cx-font); font-size: 12.5px; line-height: 1.55;
    color: var(--cx-ink-faint);
}
/* Leaflet measures this element, so the height stays explicit. */
#liveTrackingMap {
    height: 340px;
    border: 1px solid var(--cx-line);
    border-radius: 16px;
    overflow: hidden;
}
#liveTrackingStatusText {
    margin: 12px 0 0;
    font-family: var(--cx-mono); font-size: 10px; letter-spacing: .14em;
    text-transform: uppercase; color: var(--cx-ink-faint);
}
.live-tracking-eta {
    margin: 12px 0 0;
    font-family: var(--cx-font); font-size: 14px; font-weight: 700; color: #fff;
}
.live-tracking-eta:empty { display: none; }

.live-map-wrap { position: relative; }
.live-recenter {
    position: absolute; right: 12px; bottom: 12px; z-index: 500;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 15px; border: 1px solid rgba(var(--tw-line-rgb, 120, 170, 230), .3); border-radius: 999px;
    background: rgba(8, 16, 29, .88);
    -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
    color: #dbe6f5; cursor: pointer;
    font-family: var(--cx-mono); font-size: 10px; font-weight: 600;
    letter-spacing: .14em; text-transform: uppercase;
    box-shadow: 0 8px 22px rgba(0, 0, 0, .45);
    transition: background .18s ease, border-color .18s ease, color .18s ease;
}
.live-recenter:hover { background: rgba(103, 232, 249, .18); border-color: rgba(103, 232, 249, .5); color: #fff; }

/* Courier marker - a divIcon built in live-tracking-map.js, so these exact
   class names have to stay. */
.tw-courier-marker { background: none; border: 0; }
.tw-courier-dot {
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(140deg, var(--cx-cyan), #2f9fbe);
    border: 3px solid #08101d;
    box-shadow: 0 0 0 3px rgba(103, 232, 249, .3), 0 4px 14px rgba(0, 0, 0, .5);
}
.tw-courier-glyph { font-size: 16px; line-height: 1; }

@media (max-width: 767px) {
    #liveTrackingMap { height: 58vh; min-height: 300px; }
    .live-tracking-card { padding-left: 14px; padding-right: 14px; }
    .live-tracking-eta { font-size: 15px; }
}

/* --- checkpoints ---------------------------------------------------------- */
.checkpoints {
    margin-bottom: 18px; padding: 22px 24px;
    border: 1px solid var(--cx-line); border-radius: 20px;
    background: var(--cx-panel);
    box-shadow: 0 20px 48px rgba(3, 8, 16, .4);
}
/* "Checkpoints" was set as a 10px mono micro-label while every panel above it
   had an 18px heading, so the one panel that names where the order actually is
   looked like a footnote. It joins the heading group above (that rule carries
   the font, size, colour and accent bar); only the spacing is local. The
   inline colour it used to carry is gone from the markup, so no !important. */
.checkpoints h4 { margin-bottom: 8px; }

.checkpoint-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; }
/* Rows are inset by their own padding so the current one can take a plate
   without shifting the dots; the rail below is offset to match. */
.checkpoint {
    position: relative;
    display: grid; grid-template-columns: 16px minmax(0, 1fr) auto;
    align-items: center; gap: 14px;
    padding: 11px 12px;
    margin: 0 -12px;
    border-radius: 12px;
}
/* A hairline rail behind the dots turns the list into one continuous run. */
.checkpoint:not(:last-child)::before {
    content: ''; position: absolute;
    left: 19px; top: 50%; bottom: -50%; width: 2px;
    background: rgba(var(--tw-line-rgb, 120, 170, 230), .56);
}
/* The row that says where the order is now gets the only tinted plate in the
   list, so it is findable without reading all five labels. */
.checkpoint.current {
    background: rgba(74, 222, 128, .07);
    box-shadow: inset 0 0 0 1px rgba(74, 222, 128, .48);
}
.checkpoint .dot {
    position: relative; z-index: 1;
    width: 12px; height: 12px; border-radius: 50%;
    background: #2b3c55; box-shadow: 0 0 0 3px rgba(6, 13, 24, .9);
}
.checkpoint.completed .dot { background: var(--cx-cyan); }
.checkpoint.current .dot { background: var(--cx-green); box-shadow: 0 0 0 3px rgba(6, 13, 24, .9), 0 0 14px rgba(74, 222, 128, .8); }
/* #2b3c55 was 1.4:1 on the panel - a stage still to come simply was not there.
   A hollow ring still reads as "not yet" while being visible. */
.checkpoint.pending .dot {
    background: transparent;
    box-shadow: 0 0 0 3px rgba(6, 13, 24, .9), inset 0 0 0 2px #52688a;
}
.checkpoint .label {
    font-family: var(--cx-font); font-size: 14.5px; font-weight: 600;
    color: var(--cx-ink-dim);
}
.checkpoint.pending .label { color: var(--cx-ink-label); font-weight: 500; }
.checkpoint.completed .label { color: var(--cx-ink-dim); }
.checkpoint.current .label { color: #fff; font-weight: 700; }
.checkpoint .time { font-family: var(--cx-mono); font-size: 11px; letter-spacing: .06em; color: var(--cx-ink-faint); }

/* --- order items ---------------------------------------------------------- */
.order-items-list { display: flex; flex-direction: column; gap: 10px; }
.order-item {
    display: flex; align-items: center; gap: 15px;
    padding: 13px 15px;
    border: 1px solid var(--cx-line) !important;
    border-radius: 16px;
    background: var(--cx-panel-soft) !important;
    box-shadow: none !important;
    transition: border-color .2s ease;
}
.order-item:hover { border-color: var(--cx-line-hi) !important; }
.order-item .item-image {
    width: 66px; height: 66px; flex: none;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(var(--tw-line-rgb, 120, 170, 230), .12);
    border-radius: 13px; overflow: hidden;
    background:
        radial-gradient(70% 70% at 30% 20%, rgba(103, 232, 249, .10), transparent 70%),
        rgba(6, 13, 24, .65) !important;
}
.order-item .item-image img { width: 100%; height: 100%; object-fit: cover; }
.order-item .image-placeholder { font-size: 22px; color: var(--cx-ink-label); }
.order-item .item-details { flex: 1; min-width: 0; }
.order-item .item-details h4 {
    margin: 0 0 6px;
    font-family: var(--cx-font); font-size: 15.5px; font-weight: 700;
    line-height: 1.35; color: #fff;
}
/* 11px mono at .06em for the quantity and unit price was the smallest text on
   the page and carried real numbers. 12.5px at .03em. */
.order-item .item-details p {
    margin: 2px 0;
    font-family: var(--cx-mono); font-size: 12.5px; letter-spacing: .03em;
    color: var(--cx-ink-faint);
}
.order-item .item-total {
    font-family: var(--cx-font); font-size: 17px; font-weight: 800;
    letter-spacing: -.01em; color: var(--cx-cyan);
}

/* --- delivery information ------------------------------------------------- */
.delivery-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
.delivery-details > div {
    margin: 0; padding: 15px 16px;
    border: 1px solid var(--cx-line); border-radius: 14px;
    background: var(--cx-panel-soft);
    font-family: var(--cx-font); font-size: 15px; line-height: 1.45; color: #fff;
}
/* Same lift as the fact plates above - these are the same kind of label and
   were the same unreadable 9px/.22em. */
.delivery-details > div strong {
    display: block; margin-bottom: 6px;
    font-family: var(--cx-mono); font-size: 10.5px; font-weight: 600;
    letter-spacing: .14em; text-transform: uppercase; color: var(--cx-ink-faint);
}
.delivery-address p { margin: 0; color: var(--cx-ink-dim); line-height: 1.6; }
/* The inline style="color:#00c6ff" is gone from the markup, so this no longer
   needs !important and the light theme reaches it the ordinary way. */
.tracking-number span {
    font-family: var(--cx-mono); font-size: 16px; font-weight: 700;
    letter-spacing: .06em; color: var(--cx-cyan);
}

/* --- actions -------------------------------------------------------------- */
.tracking-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; padding: 6px 0 2px; }
.btn-primary, .btn-secondary {
    display: inline-flex; align-items: center; gap: 9px;
    padding: 13px 26px;
    border: 1px solid transparent; border-radius: 999px;
    text-decoration: none;
    font-family: var(--cx-mono); font-size: 10.5px; font-weight: 700;
    letter-spacing: .16em; text-transform: uppercase;
    cursor: pointer;
    transition: transform .2s var(--cx-ease), background .2s ease,
                border-color .2s ease, box-shadow .2s ease, color .2s ease;
}
.btn-primary {
    background: linear-gradient(120deg, var(--cx-pink), #c2185b);
    color: #fff;
    box-shadow: 0 10px 26px rgba(224, 86, 122, .3);
}
.btn-primary:hover {
    transform: translateY(-2px);
    background: linear-gradient(120deg, var(--cx-pink-lt), var(--cx-pink));
    box-shadow: 0 16px 34px rgba(224, 86, 122, .44);
}
/* --tw-line-ctl, not --tw-line: this button has no fill to speak of (a 4%%
   wash) and no shadow, so its border is the only thing that says "button".
   --tw-line is 1.2:1 on the panel in dark and 1.29:1 on white in light - the
   label was readable, the control was not there. See the token's own note in
   theme-light.css. */
.btn-secondary {
    background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04);
    border-color: var(--tw-line-ctl, rgba(120, 170, 230, .55));
    color: var(--cx-ink-dim);
}
.btn-secondary:hover {
    transform: translateY(-1px);
    background: rgba(103, 232, 249, .12);
    border-color: rgba(103, 232, 249, .45);
    color: #fff;
}

/* --- the unpaid flag on the payment-method line --------------------------- */
/* Three inline-styled spans in the markup; these reach them without changing
   what they say or where they link. */
.order-meta .pay-countdown {
    font-family: var(--cx-mono) !important;
    font-size: 10px !important; letter-spacing: .1em;
    color: var(--cx-amber) !important;
}

@media (max-width: 768px) {
    .timeline-container { flex-direction: column; gap: 18px; }
    .timeline-stage { flex-direction: row; width: 100%; max-width: none; text-align: left; align-items: center; }
    .stage-circle { margin: 0 14px 0 0; flex-shrink: 0; }
    .stage-content { text-align: left; padding: 0; }
    .timeline-connector { display: none; }
    .order-meta, .delivery-details { grid-template-columns: 1fr; }
    .order-headline { gap: 10px; }
    .order-details h2 { font-size: 18px; }
    .order-info-card, .tracking-timeline, .live-tracking-card,
    .order-items-section, .delivery-info, .checkpoints { padding: 18px; }
}

@media (prefers-reduced-motion: reduce) {
    .timeline-stage.current .stage-circle { animation: none; }
    .btn-primary:hover, .btn-secondary:hover, .recent-orders li:hover { transform: none; }
}
</style>

<style>
/* Modal styles */
.tracking-modal-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(3, 8, 16, 0.72);
	-webkit-backdrop-filter: blur(6px);
	backdrop-filter: blur(6px);
	z-index: 1000;
	display: none;
	align-items: flex-start;
	justify-content: center;
	opacity: 0;
	transition: opacity 180ms ease-out;
}

.tracking-modal-overlay.show { display: flex; opacity: 1; }

.tracking-modal {
	margin: 60px 16px;
	/* The landing ground, so the sheet reads as a window onto the same page
	   rather than a slab of a different colour. */
	background: radial-gradient(1000px 600px at 70% -10%, #16283f 0%, #0a1424 45%, #060d18 100%);
	border: 1px solid var(--cx-line);
	border-radius: 22px;
	width: 100%;
	max-width: 960px;
	box-shadow: 0 24px 64px rgba(0,0,0,0.45);
	position: relative;
	padding: 0;
	max-height: calc(100vh - 120px);
	transform: scale(0.96) translateY(-6px);
	opacity: 0;
	transition: transform 200ms ease-out, opacity 200ms ease-out;
}

.tracking-modal-body {
    padding: 26px;
    max-height: calc(100vh - 120px);
    overflow: auto; /* allow scroll */
    -ms-overflow-style: none; /* IE and Edge */
    scrollbar-width: none; /* Firefox */
}
.tracking-modal-body::-webkit-scrollbar { display: none; } /* Chrome, Safari */

.tracking-modal-overlay.show .tracking-modal { transform: scale(1) translateY(0); opacity: 1; }

/* Make body behind modal non-scrollable */
body.modal-open { overflow: hidden; }

.tracking-modal-close {
	position: absolute;
	top: 14px;
	right: 16px;
	width: 36px;
	height: 36px;
	border-radius: 50%;
	border: 1px solid rgba(var(--tw-line-rgb, 120, 170, 230), .22);
	background: rgba(8,16,29,.7);
	color: #dbe6f5;
	font-size: 20px;
	line-height: 1;
	cursor: pointer;
	z-index: 10; /* Ensure close button is above all other elements */
	transition: background .18s ease, border-color .18s ease, color .18s ease;
}

.tracking-modal-close:hover { background: rgba(224,86,122,.22); border-color: rgba(224,86,122,.55); color: #fff; }

/* Pop-in effect for content blocks */
.pop-in { animation: popIn 240ms ease-out both; }
@keyframes popIn {
	0% { transform: translateY(6px); opacity: 0; }
	100% { transform: translateY(0); opacity: 1; }
}

/* Scroll more button */
.scroll-more-btn {
	position: absolute;
	bottom: 16px;
	right: 20px;
	background: linear-gradient(120deg,#e0567a,#c2185b);
	color: #fff;
	border: none;
	border-radius: 999px;
	padding: 10px 18px;
	font-family: var(--cx-mono);
	font-size: 10px;
	font-weight: 700;
	letter-spacing: .16em;
	text-transform: uppercase;
	cursor: pointer;
	box-shadow: 0 10px 26px rgba(224,86,122,.36);
	z-index: 5; /* ensure above content */
	transition: transform .2s var(--cx-ease), box-shadow .2s ease, background .2s ease;
}
.scroll-more-btn:hover {
	transform: translateY(-2px);
	background: linear-gradient(120deg,#ff9ab5,#e0567a);
	box-shadow: 0 14px 32px rgba(224,86,122,.5);
}
</style>

<script>
(function(){
	var overlay = document.getElementById('trackingModal');
	if (overlay) {
		// prevent page scroll when modal is open
		document.body.classList.add('modal-open');
		// Close on ESC
		document.addEventListener('keydown', function(e){
			if (e.key === 'Escape') {
				window.location = '<?= WEB_ROOT ?>public/authenticated/customer/track_order.php';
			}
		});
		// Close on outside click
		overlay.addEventListener('click', function(e){
			if (e.target === overlay) {
				window.location = '<?= WEB_ROOT ?>public/authenticated/customer/track_order.php';
			}
		});
		// Scroll more button - smooth scroll using internal container and hide when at bottom
		var btn = document.getElementById('scrollMoreBtn');
		var moreStart = document.getElementById('moreInfoStart');
		var modalBody = document.getElementById('modalScroll');
		if (btn && moreStart && modalBody) {
			btn.addEventListener('click', function(){
				moreStart.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
			var toggleBtn = function(){
				var atBottom = Math.ceil(modalBody.scrollTop + modalBody.clientHeight) >= modalBody.scrollHeight;
				btn.style.display = atBottom ? 'none' : 'inline-block';
			};
			modalBody.addEventListener('scroll', toggleBtn);
			// initial state
			setTimeout(toggleBtn, 50);
		}
	}
})();
</script>

<?php if ($showLiveMap): ?>
<!-- Leaflet + OpenStreetMap - free, no API key. Only ever loaded on this
     page when there's an active, driver-assigned Home Delivery order to
     actually show; every other render of this page (search view, pickup
     orders, non-active statuses) never touches these. -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="<?= WEB_ROOT ?>public/shared/assets/js/delivery-route.js"></script>
<script src="<?= WEB_ROOT ?>public/shared/assets/js/live-tracking-map.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    LiveTrackingMap.init({
      orderId: window.__liveTrackingOrderId,
      mapElementId: 'liveTrackingMap',
      statusTextElementId: 'liveTrackingStatusText',
      updatedAtElementId: 'liveTrackingUpdatedAt',
      etaElementId: 'liveTrackingEta',
      recenterElementId: 'liveTrackingRecenter',
      pollUrl: '<?= WEB_ROOT ?>public/authenticated/api/get_driver_location.php',
      pollIntervalMs: 4000,
      storeLat: <?= json_encode(STORE_LAT) ?>,
      storeLng: <?= json_encode(STORE_LNG) ?>,
      // The drop-off, handed over at init so the map pins it immediately
      // instead of waiting for a poll that only succeeds once the rider is
      // sharing. destExact marks the customer's own checkout pin, which must
      // not be overwritten by geocoding the address text.
      destLat: <?= $liveDropoffLat !== null ? json_encode($liveDropoffLat) : 'null' ?>,
      destLng: <?= $liveDropoffLng !== null ? json_encode($liveDropoffLng) : 'null' ?>,
      destExact: <?= $liveDropoffLat !== null ? 'true' : 'false' ?>,
      destAddress: <?= json_encode($liveDropoffLabel) ?>
    });
  });
</script>
<?php endif; ?>

<script>
// Live "time left to pay" countdown for an unpaid online-payment order -
// same pattern as orders.php. Starts from a seconds-remaining value already
// computed server-side in MySQL (seconds_until_expiry), ticks down locally
// in the browser from there. Reaching 0 doesn't mean the order has actually
// been cancelled yet (that only happens server-side, next page load) - this
// just tells the customer to refresh.
document.addEventListener('DOMContentLoaded', function () {
  var els = document.querySelectorAll('.pay-countdown');
  if (!els.length) return;

  function formatCountdown(seconds) {
    if (seconds <= 0) { return 'Expired - refresh to update'; }
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    return 'Pay within ' + m + ':' + (s < 10 ? '0' : '') + s;
  }

  els.forEach(function (el) {
    var remaining = parseInt(el.getAttribute('data-seconds-left'), 10) || 0;
    el.textContent = formatCountdown(remaining);
    var timer = setInterval(function () {
      remaining -= 1;
      el.textContent = formatCountdown(remaining);
      if (remaining <= 0) { clearInterval(timer); }
    }, 1000);
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>