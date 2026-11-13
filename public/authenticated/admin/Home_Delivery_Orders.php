<?php
// admin/Home_Delivery_Orders.php - Home Delivery Orders page
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';
// The printed customer invoice - same document the pickup queue prints.
require_once __DIR__ . '/../../../config/invoice.php';
// Serial-number display and the packing-bench assignment controls. Required at
// the top, not inside the try below: tw_units_item_serials() is called from the
// markup, which still renders when the orders query fails.
require_once __DIR__ . '/includes/order_units_panel.php';

require_login();
require_role(['admin']);

$pageTitle = 'Home Delivery Orders - TechWorld Admin';
$isAdminTheme = true;

// Handle order status updates and tracking number updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_epayment') {
    if (!check_csrf(false)) {
        $error = 'Invalid security token.';
    } else {
        $orderId = (int)($_POST['order_id'] ?? 0);
        try {
            $pdo = getConnection();
            $st = $pdo->prepare("
                SELECT p.id FROM payments p
                JOIN orders o ON o.id = p.order_id
                WHERE p.order_id = ? AND o.payment_method IN ('gcash','paymaya') AND p.transaction_id IS NOT NULL
                ORDER BY p.id DESC LIMIT 1
            ");
            $st->execute([$orderId]);
            $paymentId = $st->fetchColumn();
            if ($paymentId) {
                $pdo->beginTransaction();
                $upd = $pdo->prepare("UPDATE payments SET payment_status = 'completed', payment_date = NOW() WHERE id = ?");
                $upd->execute([$paymentId]);
                // orders.payment_status is the order-level, gateway-agnostic flag
                // (same one the Xendit webhook sets to 'paid' on success) that the
                // fulfillment queue below and the pickup/delivery status guards now
                // gate on - without this, a manually-verified GCash/Maya order would
                // stay stuck showing as unpaid even after staff confirm the reference.
                $updOrder = $pdo->prepare("UPDATE orders SET payment_status = 'paid', paid_at = NOW() WHERE id = ? AND payment_status != 'paid'");
                $updOrder->execute([$orderId]);
                // Same release the Xendit webhook performs, for the manual
                // path: staff confirming a GCash/Maya reference by hand is the
                // other way an online order's money is proven to have arrived.
                // No-op unless the order is still at 'awaiting_payment'.
                os_release_paid_order($pdo, (int)$orderId, 'manual', getUserId(), null);
                $pdo->commit();
                if (function_exists('logAudit')) {
                    logAudit('epayment_verified', 'payments', (int)$paymentId, null, ['order_id' => $orderId]);
                }
                $success = "Payment for order #{$orderId} marked as verified.";
            } else {
                $error = 'No submitted transaction reference found for this order yet.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('E-payment verification error: ' . $e->getMessage());
            $error = 'Failed to verify payment.';
        }
    }
}

// The status / tracking-number / rider POST handler that used to live here is
// gone, for the same reason it was removed from store_pickup_orders.php. The
// modal's dropdowns and tracking field were taken out earlier (see the note by
// the status line in the dialog) and nothing has posted to this page since -
// no form here, and no other page targets it - so this was ~195 lines reachable
// only by hand-crafting a POST, and what it reached was UPDATE orders SET
// status / tracking_code / assigned_driver_id: exactly the three edits that
// were deliberately taken off this screen.
//   status   -> scanning (admin/scan_orders.php, admin/order_status.php), which
//               is the only path that records who moved the order and when.
//   tracking -> issued with the order, never rewritten.
//   rider    -> autoAssignPendingDeliveries(), overridden on the dispatch board.
// The 'delivered' customer notification and the Delivery_Report redirect it
// carried belonged to the manual 'shipped'/'completed' transitions and are
// raised by those paths now. verify_epayment above is unrelated and stays.

// Get pending orders
try {
    $pdo = getConnection();

    // Opportunistic sweep for online-payment orders left unpaid over an
    // hour - see cancelExpiredOnlinePaymentOrders() in config/functions.php.
    cancelExpiredOnlinePaymentOrders();

    // Hand any waiting deliveries to riders who are on duty and free. There is
    // no cron here, so the queue also runs from the moments that change the
    // answer (an order reaching 'shipped', a rider coming on duty, a delivery
    // completing) - this sweep just catches anything those missed.
    autoAssignPendingDeliveries();
    $__waitingForRider = pendingUnassignedDeliveryCount();
    $__ridersAvailable = count(availableDrivers());

    // The working queue plus Completed. The pre-ship statuses are the orders
    // staff still have to act on; `completed` is here because a finished
    // delivery is the one an admin goes looking for afterwards - to see the
    // rider's proof photo and when it was handed over. `shipped` stays out:
    // an order in transit belongs to the dispatch board, which is where it
    // can actually be acted on.
    //
    // Anything else falls back to "all", which is what makes the value safe
    // to inline into the WHERE clauses below.
    $statusOptions = ['awaiting_payment', 'preparing', 'packed', 'ready', 'completed'];
    $statusFilter = (string)($_GET['status'] ?? '');
    if (!in_array($statusFilter, $statusOptions, true)) { $statusFilter = ''; }
    $statusWhere = $statusFilter !== '' ? " WHERE o2.status = " . $pdo->quote($statusFilter) : '';

    // The de-duplicated (one row per invoice_no) base set, reused by the
    // count, the tallies and the page query.
    $dedupFrom = "
        FROM (
            SELECT MAX(id) AS id
            FROM orders
            WHERE delivery_method = 'delivery' AND status IN ('awaiting_payment','preparing','packed','ready','completed')
            GROUP BY invoice_no
        ) latest
        JOIN orders o2 ON o2.id = latest.id
    ";

    $statusCounts = [];
    foreach ($pdo->query("SELECT o2.status AS s, COUNT(*) AS c {$dedupFrom} GROUP BY o2.status") as $row) {
        $statusCounts[(string)$row['s']] = (int)$row['c'];
    }

    $totalOrders = (int)$pdo->query("SELECT COUNT(*) {$dedupFrom} {$statusWhere}")->fetchColumn();

    // 10 rows per page in the orders table. These rows are tall - product
    // thumbnail, customer avatar and two lines of text per cell - so ~10 is
    // what actually fits a screen without scrolling past the pager. It also
    // halves the work the page does: every row renders its own full details
    // dialog (#orderModal-<id> below), so the row count is a direct
    // multiplier on the markup shipped per request.
    $perPage = 10;
    $totalPages = max(1, (int)ceil($totalOrders / $perPage));
    $page = max(1, (int)($_GET['page'] ?? 1));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;

    // Get home delivery orders with user info (deduplicated by invoice_no and limited to pre-ship statuses)
    $ordersStmt = $pdo->prepare("
        SELECT o2.*, u.firstname, u.lastname, u.email,
               -- Legacy fallback only - orders placed before the
               -- delivery_recipient_name/delivery_phone/delivery_full_address
               -- snapshot (o2.*, above) existed have those columns NULL, so
               -- PHP below falls back to these for old orders only. Aliased
               -- away from the real column names to avoid PDO's associative
               -- fetch silently overwriting the real snapshot columns with
               -- these.
               COALESCE(
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1),
                   (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o2.user_id LIMIT 1),
                   u.address
               ) as legacy_address,
               (SELECT full_name FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1) as legacy_delivery_name,
               (SELECT phone FROM user_addresses WHERE user_id = o2.user_id AND is_default = 1 LIMIT 1) as legacy_delivery_phone,
               (SELECT transaction_id FROM payments WHERE order_id = o2.id ORDER BY id DESC LIMIT 1) as epay_transaction_id,
               (SELECT payment_status FROM payments WHERE order_id = o2.id ORDER BY id DESC LIMIT 1) as epay_status,
               (SELECT CONCAT(d.firstname, ' ', d.lastname) FROM users d WHERE d.id = o2.assigned_driver_id) as assigned_driver_name,
               (SELECT CONCAT(c.firstname, ' ', c.lastname) FROM users c WHERE c.id = o2.delivery_completed_by) as delivery_completed_by_name
        FROM (
            SELECT MAX(id) AS id
            FROM orders
            WHERE delivery_method = 'delivery' AND status IN ('awaiting_payment','preparing','packed','ready','completed')
            GROUP BY invoice_no
        ) latest
        JOIN orders o2 ON o2.id = latest.id
        LEFT JOIN users u ON o2.user_id = u.id
        {$statusWhere}
        ORDER BY o2.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $ordersStmt->execute();
    $orders = $ordersStmt->fetchAll();


    // Proof-of-delivery photos for every order on this page, in ONE query
    // rather than one per row. The rider uploads these from courier/
    // dispatch.php when confirming the handover (and Delivery_Report.php for
    // office-side corrections); until now nothing in the admin area showed
    // them back, so a completed delivery could not be checked after the fact.
    //
    // Keyed by order id, so the dialog below is a lookup, not a query.
    //
    // photo_type is deliberately not read: the writers insert the literal
    // 'delivery_proof', which is not one of that column's ENUM members, so
    // every row stores '' instead. The photos themselves are intact - it is
    // only the label that was lost, and nothing here needs it.
    $proofPhotos = [];
    $orderIds = array_map(static fn($o) => (int)$o['id'], $orders);
    if ($orderIds) {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $proofStmt = $pdo->prepare("
            SELECT dp.order_id, dp.photo_path, dp.uploaded_at,
                   CONCAT(up.firstname, ' ', up.lastname) AS uploaded_by_name
            FROM delivery_proof_photos dp
            LEFT JOIN users up ON up.id = dp.uploaded_by
            WHERE dp.order_id IN ({$ph})
            ORDER BY dp.uploaded_at ASC, dp.id ASC
        ");
        $proofStmt->execute($orderIds);
        foreach ($proofStmt->fetchAll() as $row) {
            $proofPhotos[(int)$row['order_id']][] = $row;
        }
    }

    // Get order items for each order
    foreach ($orders as &$order) {
        $order['proof_photos'] = $proofPhotos[(int)$order['id']] ?? [];
        // Prefer the address snapshot taken at checkout (what the customer
        // actually chose for this order) over the legacy_* fallbacks, which
        // only apply to orders placed before that snapshot existed.
        $order['address'] = $order['delivery_full_address'] ?: $order['legacy_address'];
        $order['delivery_name'] = $order['delivery_recipient_name'] ?: $order['legacy_delivery_name'];
        $order['delivery_phone'] = $order['delivery_phone'] ?: $order['legacy_delivery_phone'];

        // image_url and the category name feed the leading column of the
        // orders table (thumbnail + product + category), the way the queue
        // reads now that it is rows rather than cards.
        $itemsStmt = $pdo->prepare("
            SELECT oi.*, p.name as product_name, p.price, p.image_url,
                   -- oi.* already selects oi.price and oi.total, but the bare
                   -- p.price above lands on the same key and overwrites oi.price
                   -- in the fetched row, so the unaliased `price` is TODAY'S
                   -- catalogue price rather than what the customer was charged.
                   -- The invoice reads these two aliases instead. Purely
                   -- additive: every existing reader keeps the keys it uses.
                   oi.price AS oi_unit_price,
                   oi.total AS oi_line_total,
                   (SELECT c.name FROM categories c WHERE c.id = p.category_id) AS category_name
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
            ORDER BY oi.id
        ");
        $itemsStmt->execute([$order['id']]);
        $order['items'] = $itemsStmt->fetchAll();
    }
    // Break the reference left dangling by the loop above - without this,
    // the next foreach ($orders as $order) below (by value, same variable
    // name) silently overwrites the second-to-last order with a copy of
    // the last one instead of iterating normally (classic PHP foreach-by-
    // reference gotcha), which is why one order rendered twice while
    // another disappeared entirely.
    unset($order);

    // Serial numbers for every line, and which lines still need one. Must run
    // AFTER the items loop above (it reads $order['items']) and BEFORE
    // tw_invoice_payload() below, which reads the `serials` key this attaches
    // so the printed invoice carries the same serials as the screen.
    tw_units_attach($pdo, $orders);

} catch (PDOException $e) {
    error_log("Orders query error: " . $e->getMessage());
    $orders = [];
    $totalOrders = 0;
    $page = 1;
    $totalPages = 1;
    $offset = 0;
    $statusFilter = '';
    // Mirrors the live list above so the filter chips do not change shape
    // just because the query failed.
    $statusOptions = ['awaiting_payment', 'preparing', 'packed', 'ready', 'completed'];
    $statusCounts = [];
}

// Pager links have to carry the active filter, otherwise page 2 silently
// resets to "all statuses".
function deliveryPageUrl(int $n): string {
    global $statusFilter;
    $qs = ['page' => $n];
    if ($statusFilter !== '') { $qs['status'] = $statusFilter; }
    return '?' . http_build_query($qs);
}

/* The printed invoice, as data. 'delivery' swaps the second address panel
   from the store counter to the recipient and their address - everything else
   about the document is identical to the pickup queue's. */
$invoiceData  = tw_invoice_payload($orders, 'delivery');
$invoiceStore = tw_invoice_store();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<?php
/* -----------------------------------------------------------------------
   VIEW LAYER ONLY. Every query, the POST handlers, the status flow, rider
   assignment and the Details dialogs below are untouched. These are derived
   from values the page already computed.
   -------------------------------------------------------------------- */
$hdoShownFrom = $orders ? $offset + 1 : 0;
$hdoShownTo   = $orders ? $offset + count($orders) : 0;
/* The exact condition the old .is-stalled class used, from the two variables
   computed beside the query (pendingUnassignedDeliveryCount /
   availableDrivers). Nothing is re-queried here. */
$hdoStalled   = $__waitingForRider > 0 && $__ridersAvailable === 0;

/* Same six tints as view_customer.php, picked by id so a customer keeps
   theirs. Ink measured at 5.4:1 or better on its own ground. */
$hdoTints = [
  ['#e7f6ef', '#0f6b4a'], ['#e8f0fa', '#2f5d8f'], ['#f3eefb', '#5647a8'],
  ['#fdecf1', '#a8264d'], ['#fdf3e0', '#8a5a00'], ['#e6f4f4', '#17646b'],
];

/* STATUS -> pill trio. The queue query only ever returns the first four
   (WHERE status IN awaiting_payment/preparing/packed/ready), so shipped,
   completed and cancelled cannot appear here today; they are defined anyway
   so the column keeps working if that filter is ever widened.
   awaiting_payment is not in the brief's list - it takes the danger trio
   because it is the one state where the money has not arrived. */
$hdoStatusTrio = [
  'awaiting_payment' => ['#fdecf1', '#a8264d', '#f2c4d3'],
  'preparing'        => ['#fdf3e0', '#8a5a00', '#ecd9ab'],
  'packed'           => ['#e6f0fb', '#1f4f86', '#c3d8f0'],
  'ready'            => ['#e6f0fb', '#1f4f86', '#c3d8f0'],
  'shipped'          => ['#f1ecfb', '#5a3fa8', '#d6cbf2'],
  'completed'        => ['#e7f6ef', '#0f6b4a', '#b9e3d0'],
  'cancelled'        => ['#fdecf1', '#a8264d', '#f2c4d3'],
];
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

<!-- The queue layout both order pages share. Linked here, before the
     markup it styles, rather than after it. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/css/admin-queue.css') ?>">

<main class="container hdoq">
<div class="hdoq-wrap">

  <?php if (isset($success)): ?>
    <div class="notification-banner notification-success"><div class="container"><span><?= htmlspecialchars($success) ?></span></div></div>
  <?php endif; ?>
  <?php if (isset($error)): ?>
    <div class="notification-banner notification-error"><div class="container"><span><?= htmlspecialchars($error) ?></span></div></div>
  <?php endif; ?>

  <!-- 1. Header. Replaces the centred banner, the duplicate caps heading and
       the floating Delivery Report card - all three said the same thing. -->
  <header class="hdoq-head">
    <div class="hdoq-head-l">
      <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='OrdMan.php'; }" aria-label="Back" class="hdoq-back">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
          <path d="M9 12h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>
      </a>
      <div>
        <div class="hdoq-eyebrow">Admin &middot; Orders</div>
        <h1>Home Delivery Orders</h1>
      </div>
    </div>
    <div class="hdoq-head-r">
      <?php if (!$hdoStalled): ?>
        <span class="hdoq-chip<?= $__ridersAvailable > 0 ? '' : ' is-none' ?>">
          <span class="hdoq-chip-dot" aria-hidden="true"></span>
          <?= $__ridersAvailable > 0
                ? ((int)$__ridersAvailable . ' rider' . ($__ridersAvailable === 1 ? '' : 's') . ' free')
                : 'No riders free' ?>
        </span>
      <?php endif; ?>
      <a class="hdoq-ghost" href="<?= WEB_ROOT ?>public/authenticated/admin/store_pickup_orders.php">Store pickup orders &rarr;</a>
      <a class="hdoq-ghost" href="<?= WEB_ROOT ?>public/authenticated/admin/Delivery_Report.php">Delivery report</a>
    </div>
  </header>

  <!-- 2. Rider alert. Same condition the old .is-stalled class used, from the
       same two variables - nothing is re-queried. When riders are free the
       strip is absent entirely. -->
  <?php if ($hdoStalled): ?>
    <div class="hdoq-alert" role="status">
      <span class="hdoq-alert-l">
        <span class="hdoq-dot" aria-hidden="true"></span>
        No riders are free &middot; <?= (int)$__waitingForRider ?> order<?= $__waitingForRider === 1 ? '' : 's' ?> waiting for a rider
      </span>
    </div>
  <?php endif; ?>

  <!-- 3. Status tabs. The real <select> keeps its id, name and onchange and
       stays in the DOM (visually hidden); the tabs just set its value and
       dispatch 'change', so the existing GET filter runs untouched and the
       <noscript> path still works. -->
  <div class="hdoq-toolbar">
    <form method="GET" class="pickup-filter">
      <label class="hdoq-sr" for="deliveryStatusFilter">Show:</label>
      <select id="deliveryStatusFilter" name="status" class="admin-form-input hdoq-sr" onchange="this.form.submit()">
        <option value="">All orders (<?= number_format(array_sum($statusCounts)) ?>)</option>
        <?php foreach ($statusOptions as $opt): ?>
          <option value="<?= $opt ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>>
            <?= os_label($opt, 'delivery') ?> (<?= number_format($statusCounts[$opt] ?? 0) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="admin-quick-link" style="border:none;cursor:pointer;margin:0;">Apply</button></noscript>
      <div class="hdoq-tabs" role="tablist" aria-label="Filter by status">
        <button type="button" class="hdoq-tab" role="tab" data-status="" aria-selected="<?= $statusFilter === '' ? 'true' : 'false' ?>">
          All orders <span class="hdoq-tab-n"><?= number_format(array_sum($statusCounts)) ?></span>
        </button>
        <?php foreach ($statusOptions as $opt): ?>
          <button type="button" class="hdoq-tab" role="tab" data-status="<?= htmlspecialchars($opt) ?>" aria-selected="<?= $statusFilter === $opt ? 'true' : 'false' ?>">
            <?= htmlspecialchars(os_label($opt, 'delivery')) ?> <span class="hdoq-tab-n"><?= number_format($statusCounts[$opt] ?? 0) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <?php if (empty($orders)): ?>
    <div class="hdo-card">
      <div class="hdoq-empty">
        <?php if ($statusFilter !== ''): ?>
          <h3>No <?= htmlspecialchars(os_label($statusFilter, 'delivery')) ?> deliveries</h3>
          <p>Nothing matches this filter right now. <a href="?">Show all orders</a>.</p>
        <?php else: ?>
          <h3>No pending orders</h3>
          <p>All orders have been processed.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <!-- 4. The queue. Same rows, same dialog, same handlers - ORDER moves to
         the front because it is what admins search by, and PAYMENT gets its
         own column instead of being grey text under the amount.
         role="table"/"row"/"cell" are explicit because display:grid drops the
         native table semantics. -->
    <div class="hdo-card">
      <div class="hdo-table-wrap">
        <table class="hdo-table" role="table">
          <thead>
            <tr role="row">
              <th scope="col" role="columnheader">Order</th>
              <th scope="col" role="columnheader">Customer</th>
              <th scope="col" role="columnheader">Items</th>
              <th scope="col" role="columnheader" class="num">Amount</th>
              <th scope="col" role="columnheader">Payment</th>
              <th scope="col" role="columnheader">Status</th>
              <th scope="col" role="columnheader"><span class="hdoq-sr">Actions</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $order): ?>
            <?php
              // Unchanged from the previous markup: orders.status reads
              // 'preparing' from the moment an order is placed, which is
              // misleading while an online payment is still outstanding.
              $cardUnpaid = in_array($order['payment_method'] ?? '', ['gcash', 'paymaya', 'xendit'], true)
                  && ($order['payment_status'] ?? '') !== 'paid';

              $items = $order['items'] ?? [];
              $firstItem = $items[0] ?? null;
              $extraItems = max(0, count($items) - 1);
              $productName = $firstItem['product_name'] ?? 'Order items unavailable';
              $productSub = $extraItems > 0
                  ? ('+' . $extraItems . ' more item' . ($extraItems === 1 ? '' : 's'))
                  : ($firstItem['category_name'] ?? '');

              $customerName = trim($order['firstname'] . ' ' . $order['lastname']);
              $initials = strtoupper(mb_substr($order['firstname'] ?? '', 0, 1) . mb_substr($order['lastname'] ?? '', 0, 1));
              if (trim($initials) === '') { $initials = '#'; }
              $tint = $hdoTints[(int)$order['id'] % 6];

              $orderNumber = $order['invoice_no'] ?: ('#' . (int)$order['id']);

              // PAYMENT pill. payment_method is an ENUM of 5 and
              // payment_status an ENUM of 6, so this is a rule rather than a
              // 30-row lookup. $payLabels is the same map the old amount
              // sub-line used.
              $payLabels = ['cod' => 'COD', 'gcash' => 'GCash', 'paymaya' => 'Maya', 'xendit' => 'Xendit', 'onsite' => 'In store'];
              $payMethod = (string)($order['payment_method'] ?? '');
              $payState  = (string)($order['payment_status'] ?? '');
              $payShort  = $payLabels[$payMethod] ?? ucfirst($payMethod ?: 'Unknown');
              if ($payState === 'paid') {
                  $payTrio = ['#e7f6ef', '#0f6b4a', '#b9e3d0'];
                  $payText = 'Paid &middot; ' . htmlspecialchars($payShort);
              } elseif (in_array($payMethod, ['cod', 'onsite'], true)) {
                  // Money arrives on delivery - expected, not a problem.
                  $payTrio = ['#fdf3e0', '#8a5a00', '#ecd9ab'];
                  $payText = htmlspecialchars($payShort) . ' &middot; Unpaid';
              } else {
                  // An online order that has not been paid. 'failed',
                  // 'expired' and 'cancelled' were invisible before this.
                  $payTrio = ['#fdecf1', '#a8264d', '#f2c4d3'];
                  $payText = htmlspecialchars($payShort) . ' &middot; ' . htmlspecialchars(ucfirst($payState ?: 'Unpaid'));
              }

              $stTrio = $hdoStatusTrio[$order['status']] ?? ['#f7f9fc', '#46586f', '#dbe3ee'];
              $stText = ($cardUnpaid && $order['status'] === 'preparing')
                  ? 'Unpaid'
                  : os_label((string)$order['status'], 'delivery');
            ?>
            <tr class="hdo-row" role="row" tabindex="0"
                aria-haspopup="dialog" aria-controls="orderModal-<?= (int)$order['id'] ?>"
                aria-label="Order <?= htmlspecialchars($orderNumber) ?>, <?= htmlspecialchars($customerName) ?>"
                data-order-id="<?= (int)$order['id'] ?>">
              <td role="cell" data-label="Order">
                <span class="hdo-cell-text">
                  <span class="hdo-strong hdo-mono"><?= htmlspecialchars($orderNumber) ?></span>
                  <span class="hdo-sub"><?= date('M j, Y', strtotime($order['created_at'])) ?></span>
                </span>
              </td>
              <td role="cell" data-label="Customer">
                <div class="hdo-cell-media">
                  <span class="hdo-avatar" style="background:<?= $tint[0] ?>;color:<?= $tint[1] ?>;" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                  <span class="hdo-cell-text">
                    <span class="hdo-strong"><?= htmlspecialchars($customerName) ?></span>
                    <span class="hdo-sub"><?= htmlspecialchars($order['email'] ?? '') ?></span>
                  </span>
                </div>
              </td>
              <td role="cell" data-label="Items">
                <div class="hdo-cell-media">
                  <span class="hdo-thumb">
                    <?php if (!empty($firstItem['image_url'])): ?>
                      <img src="<?= WEB_ROOT . htmlspecialchars($firstItem['image_url']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <i class="fi fi-rr-box" aria-hidden="true"></i>
                    <?php endif; ?>
                  </span>
                  <span class="hdo-cell-text">
                    <span class="hdo-strong"><?= htmlspecialchars($productName) ?></span>
                    <?php if ($productSub !== ''): ?>
                      <span class="hdo-sub"><?= htmlspecialchars($productSub) ?></span>
                    <?php endif; ?>
                  </span>
                </div>
              </td>
              <td role="cell" data-label="Amount" class="num">
                <span class="hdo-amount">&#8369;<?= number_format((float)($order['total_amount'] ?? 0), 2) ?></span>
              </td>
              <td role="cell" data-label="Payment">
                <span class="hdo-pill" style="background:<?= $payTrio[0] ?>;color:<?= $payTrio[1] ?>;border-color:<?= $payTrio[2] ?>;"><?= $payText ?></span>
              </td>
              <td role="cell" data-label="Status">
                <span class="status-badge status-<?= htmlspecialchars($order['status']) ?> hdo-badge"
                      style="background:<?= $stTrio[0] ?>;color:<?= $stTrio[1] ?>;border-color:<?= $stTrio[2] ?>;">
                  <span class="hdo-pill-dot" aria-hidden="true"></span><?= htmlspecialchars($stText) ?>
                </span>
              </td>
              <td role="cell" data-label="Actions" class="hdo-col-action">
                <?php // The row itself opens the order's details, so this cell no
                      // longer duplicates that. It goes to the order's history
                      // instead - admin/order_status.php, which lists every recorded
                      // change (scan, webhook, manual) and puts the manual override
                      // form directly beneath it.
                      //
                      // An <a>, not a button: it navigates, so ctrl/middle click and
                      // "open in new tab" have to keep working. It keeps the
                      // .hdo-details-btn class purely for the shared pill styling in
                      // admin.css. ?>
                <a class="hdo-details-btn hdo-history-btn"
                   href="<?= WEB_ROOT ?>public/authenticated/admin/order_status.php?id=<?= (int)$order['id'] ?>"
                   aria-label="Order history for <?= htmlspecialchars($orderNumber) ?>">
                  <i class="fi fi-rr-time-past" aria-hidden="true"></i> Order history
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="hdoq-foot">
        <span class="hdoq-foot-n">Showing <?= number_format($hdoShownFrom) ?>&ndash;<?= number_format($hdoShownTo) ?> of <?= number_format($totalOrders) ?></span>
      </div>
    </div>

        <?php if ($totalPages > 1): ?>
          <?php
            // Windowed numbers: first, last, and the two either side of the
            // current page, so a long queue never grows an endless pager row.
            $window = [1, $totalPages, $page];
            for ($i = $page - 2; $i <= $page + 2; $i++) { $window[] = $i; }
            $window = array_values(array_unique(array_filter($window, fn($n) => $n >= 1 && $n <= $totalPages)));
            sort($window);
          ?>
          <nav class="pickup-pager" aria-label="Order pages">
            <a class="pickup-page<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= deliveryPageUrl(max(1, $page - 1)) ?>" aria-label="Previous page">&#8249;</a>
            <?php $prev = 0; foreach ($window as $n): ?>
              <?php if ($prev && $n > $prev + 1): ?><span class="pickup-page-gap">&hellip;</span><?php endif; ?>
              <a class="pickup-page<?= $n === $page ? ' is-current' : '' ?>" href="<?= deliveryPageUrl($n) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
              <?php $prev = $n; ?>
            <?php endforeach; ?>
            <a class="pickup-page<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= deliveryPageUrl(min($totalPages, $page + 1)) ?>" aria-label="Next page">&#8250;</a>
          </nav>
        <?php endif; ?>

        <!-- One dialog per card. Everything the old full-height list showed -
             recipient and address, payment details, items, the status/driver
             form - lives here now, so the queue itself stays scannable. -->
        <?php foreach ($orders as $order): ?>
          <?php
            // Online-payment orders (gcash/paymaya/xendit) can't be
            // progressed - assigned a driver, advanced past preparing -
            // until payment is actually confirmed. The update_status
            // handler above already rejects this server-side; this just
            // keeps the form itself from being shown for an action that
            // would only get rejected anyway.
            $isOnlinePaymentUnpaid = in_array($order['payment_method'] ?? '', ['gcash', 'paymaya', 'xendit'], true)
                && ($order['payment_status'] ?? '') !== 'paid';
          ?>
          <div class="pickup-modal" id="orderModal-<?= (int)$order['id'] ?>" role="dialog" aria-modal="true"
               aria-label="Order #<?= (int)$order['id'] ?>" hidden
               onclick="if (event.target === this) closeOrderModal(<?= (int)$order['id'] ?>)">
            <div class="pickup-modal-panel">
              <div class="pickup-modal-head">
                <div>
                  <h3 style="margin:0;color:var(--admin-text);">Order #<?= (int)$order['id'] ?></h3>
                  <p style="margin:5px 0 0 0;">
                    Customer: <?= htmlspecialchars($order['firstname'] . ' ' . $order['lastname']) ?>
                    (<?= htmlspecialchars($order['email']) ?>)
                  </p>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                  <span class="status-badge status-<?= htmlspecialchars($order['status']) ?>" style="padding:6px 12px;border-radius:20px;font-size:12px;font-weight:700;text-transform:uppercase;color:#fff;">
                    <?= ($isOnlinePaymentUnpaid && $order['status'] === 'preparing') ? 'AWAITING PAYMENT' : strtoupper(htmlspecialchars($order['status'])) ?>
                  </span>
                  <button type="button" class="pickup-modal-close" onclick="closeOrderModal(<?= (int)$order['id'] ?>)" aria-label="Close">&times;</button>
                </div>
              </div>

                <div class="order-details" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:20px;margin-bottom:15px;padding:15px;background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);color:var(--admin-text);">
                  <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Order Date:</strong>
                    <span style="color:var(--admin-muted);"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></span>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Total Amount:</strong>
                    <span style="font-weight:600;">₱<?= number_format((float)($order['total_amount'] ?? 0), 2) ?></span>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Payment Method:</strong>
                    <span style="color:var(--admin-muted);"><?= htmlspecialchars(getPaymentMethodLabel($order['payment_method'] ?? 'N/A')) ?></span>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Assigned Driver:</strong>
                    <span style="color:var(--admin-muted);"><?= !empty($order['assigned_driver_name']) ? htmlspecialchars($order['assigned_driver_name']) : 'Not assigned yet' ?></span>
                  </div>
                  <?php if (in_array($order['payment_method'] ?? '', ['gcash', 'paymaya'], true)): ?>
                  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <strong>Customer's Txn Ref:</strong>
                    <span style="color:var(--admin-muted);"><?= htmlspecialchars($order['epay_transaction_id'] ?? 'Not submitted yet') ?></span>
                    <span class="admin-badge <?= ($order['epay_status'] ?? '') === 'completed' ? 'admin-badge--active' : 'admin-badge--pending' ?>">
                      <?= htmlspecialchars(ucfirst($order['epay_status'] ?? 'pending')) ?>
                    </span>
                    <?php if (($order['epay_status'] ?? '') !== 'completed' && !empty($order['epay_transaction_id'])): ?>
                      <form method="POST" style="display:inline;margin:0;" data-confirm="Confirm you have verified this payment in your GCash/Maya merchant app?" data-confirm-ok="Yes, verified">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify_epayment">
                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                        <button type="submit" style="background:var(--admin-success);color:#f2f3f4;border:0;padding:4px 10px;border-radius:var(--radius-sm);cursor:pointer;font-size:12px;font-weight:600;">Mark Verified</button>
                      </form>
                    <?php endif; ?>
                  </div>
                  <?php elseif (($order['payment_method'] ?? '') === 'xendit' && ($order['payment_status'] ?? '') !== 'paid'): ?>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Payment:</strong>
                    <span class="admin-badge admin-badge--pending">Awaiting Xendit confirmation</span>
                  </div>
                  <?php endif; ?>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Delivery Address:</strong>
                    <span style="color:var(--admin-muted);">
                      <?php if (!empty($order['delivery_name'])): ?>
                        <strong style="color:var(--admin-text);">Recipient:</strong> <span style="color:var(--admin-text);"><?= htmlspecialchars($order['delivery_name']) ?></span><br>
                      <?php endif; ?>
                      <?= htmlspecialchars($order['address'] ?? 'N/A') ?>
                    </span>
                  </div>
                  <?php if (!empty($order['delivery_phone'])): ?>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <strong>Contact No.:</strong>
                    <span style="color:var(--admin-muted);"><?= htmlspecialchars($order['delivery_phone']) ?></span>
                  </div>
                  <?php endif; ?>
                </div>

                <div class="order-items" style="margin-bottom:15px;">
                  <h4 style="margin:0 0 10px 0;color:var(--admin-text);">Order Items:</h4>
                  <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($order['items'] as $item): ?>
                      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);color:var(--admin-text);">
                        <div style="flex:1;">
                          <span style="font-weight:600;"><?= htmlspecialchars($item['product_name']) ?></span>
                          <?= tw_units_item_serials($item) ?>
                        </div>
                        <div style="display:flex;align-items:center;gap:15px;">
                          <span style="color:var(--admin-muted);">Qty: <?= $item['quantity'] ?></span>
                          <span style="color:var(--admin-muted);">×</span>
                          <span style="font-weight:600;">₱<?= number_format((float)($item['price'] ?? 0), 2) ?></span>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <?php
                  /* Serial numbers, and the controls to record them. Renders
                     nothing for an order with no serialised lines, which is
                     most of them. */
                  tw_units_panel($order, !empty($actionsDisabled));
                ?>

                <?php
                  /* ---- Proof of delivery -------------------------------------
                     Shown for any order that has reached 'completed', and for
                     any order that already carries photos even if it has not
                     (an office-side upload from Delivery_Report.php can land
                     before the status does). A completed order with no photos
                     still renders the panel, saying so plainly: that gap is
                     exactly the thing an admin is checking for, so hiding the
                     section would hide the finding. */
                  $hdoProof       = $order['proof_photos'] ?? [];
                  $hdoIsCompleted = ($order['status'] ?? '') === 'completed';
                  $hdoDeliveredAt = $order['delivery_completed_at'] ?? null;
                ?>
                <?php if ($hdoIsCompleted || $hdoProof): ?>
                <div class="hdo-proof">
                  <h4 class="hdo-proof-head">
                    <i class="fi fi-rr-camera" aria-hidden="true"></i>
                    Proof of Delivery
                    <?php if ($hdoProof): ?>
                      <span class="hdo-proof-count"><?= count($hdoProof) ?> photo<?= count($hdoProof) === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                  </h4>

                  <?php /* The handover record, from the columns the rider's
                           confirmation wrote (courier/dispatch.php's
                           complete_delivery). Each line is omitted rather than
                           shown empty when its column was never written - old
                           orders predate them. */ ?>
                  <dl class="hdo-proof-meta">
                    <?php if ($hdoDeliveredAt): ?>
                      <div><dt>Delivered</dt><dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$hdoDeliveredAt))) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($order['delivery_completed_by_name'])): ?>
                      <div><dt>Confirmed by</dt><dd><?= htmlspecialchars($order['delivery_completed_by_name']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($order['assigned_driver_name'])): ?>
                      <div><dt>Rider</dt><dd><?= htmlspecialchars($order['assigned_driver_name']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($order['tracking_code'])): ?>
                      <div><dt>Tracking</dt><dd><?= htmlspecialchars($order['tracking_code']) ?></dd></div>
                    <?php endif; ?>
                  </dl>

                  <?php if ($hdoProof): ?>
                    <div class="hdo-proof-grid">
                      <?php foreach ($hdoProof as $i => $photo): ?>
                        <?php $hdoProofUrl = WEB_ROOT . ltrim((string)$photo['photo_path'], '/'); ?>
                        <figure class="hdo-proof-cell">
                          <?php /* A plain click opens the viewer below, on top of
                                   this dialog, so checking a photo never costs
                                   the admin their place in the queue. The href
                                   stays real: Ctrl/middle-click still gets a tab
                                   with a pasteable URL, and the viewer itself
                                   carries an "Open original" link - both of which
                                   a dispute reply needs. data-caption is what the
                                   viewer shows under the image. */ ?>
                          <a href="<?= htmlspecialchars($hdoProofUrl) ?>" target="_blank" rel="noopener"
                             class="hdo-proof-zoom"
                             data-caption="Order #<?= (int)$order['id'] ?> &middot; photo <?= $i + 1 ?> of <?= count($hdoProof) ?> &middot; <?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$photo['uploaded_at']))) ?><?= !empty($photo['uploaded_by_name']) ? ' &middot; ' . htmlspecialchars($photo['uploaded_by_name']) : '' ?>"
                             title="View full size">
                            <img src="<?= htmlspecialchars($hdoProofUrl) ?>" loading="lazy"
                                 alt="Delivery proof photo <?= $i + 1 ?> for order #<?= (int)$order['id'] ?>">
                          </a>
                          <figcaption>
                            <?= htmlspecialchars(date('M j, g:i A', strtotime((string)$photo['uploaded_at']))) ?>
                            <?php if (!empty($photo['uploaded_by_name'])): ?>
                              &middot; <?= htmlspecialchars($photo['uploaded_by_name']) ?>
                            <?php endif; ?>
                          </figcaption>
                        </figure>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <p class="hdo-proof-empty">
                      <i class="fi fi-rr-exclamation-triangle" aria-hidden="true"></i>
                      This order was marked delivered with no proof photo on file. Deliveries
                      confirmed through the rider app always carry one, so this was either
                      completed before that was required, or completed from the office.
                    </p>
                  <?php endif; ?>

                  <?php /* The full status history already has a page of its own,
                           linked from every row. Linked rather than reproduced:
                           one timeline, one place it is maintained. */ ?>
                  <a class="hdo-proof-history"
                     href="<?= WEB_ROOT ?>public/authenticated/admin/order_status.php?id=<?= (int)$order['id'] ?>">
                    <i class="fi fi-rr-time-past" aria-hidden="true"></i>
                    View full status history
                  </a>
                </div>
                <?php endif; ?>

                <div class="order-actions" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                  <!-- Status is changed by scanning the order's label, not from
                       here - see admin/scan_orders.php. This opens the order's
                       QR, its ladder, its full status history, and the
                       admin-only manual override for fixing mistakes. -->
                  <?php /* Same treatment as store_pickup_orders.php's modal: one
                           shape for every action, equal share of the row. The
                           inline min-width:190px that used to hold these three
                           together only equalised them until a label outgrew it,
                           and the delivery note below sat on a different shape
                           again. .hdo-act is that pill in one place, and the grid
                           gives the three documents a third of the row each.

                           The note keeps its own full-width row underneath: it is
                           conditional (and often a non-clickable <span> explaining
                           when it unlocks), so standing it beside three always-on
                           buttons would make a disabled-looking fourth column the
                           first thing the eye lands on. */ ?>
                  <div class="hdo-doc-actions">
                    <div class="hdo-acts">
                      <a class="hdo-act" href="<?= WEB_ROOT ?>public/authenticated/admin/order_status.php?id=<?= (int)$order['id'] ?>">
                        <i class="fi fi-rr-qr-code" aria-hidden="true"></i><span>Scan Label &amp; Status</span>
                      </a>
                      <button type="button" class="hdo-act" onclick="openStickerModal(<?= (int)$order['id'] ?>)">
                        <i class="fi fi-rr-document" aria-hidden="true"></i><span>Print Sticker</span>
                      </button>
                      <?php /* The customer's invoice, the same document the pickup
                               queue prints - only the second address panel differs.
                               Unlike the delivery note below it is available at any
                               status: the order exists and has been priced, which is
                               all an invoice states. */ ?>
                      <button type="button" class="hdo-act" onclick="printOrderInvoice(<?= (int)$order['id'] ?>)">
                        <i class="fi fi-rr-file-invoice" aria-hidden="true"></i><span>Print Invoice</span>
                      </button>
                    </div>
                    <?php
                      // Mirrors $dnPrintable in delivery_note_print.php, which
                      // re-checks server-side. The note carries the rider's
                      // navigation block, so it has to be printable BEFORE the
                      // trip - which is this queue's whole population. It is shown
                      // either way rather than hidden: staff asked for it here, and
                      // a row that explains when it becomes available is more use
                      // than a control that silently is not there. Only the
                      // ready/shipped/completed case is clickable.
                      $noteReady = in_array($order['status'] ?? '', ['ready', 'shipped', 'completed'], true);
                    ?>
                    <?php if ($noteReady): ?>
                      <a class="hdo-act hdo-act--wide" target="_blank" rel="noopener"
                         href="<?= WEB_ROOT ?>public/authenticated/admin/delivery_note_print.php?view=note&amp;ids=<?= (int)$order['id'] ?>">
                        <i class="fi fi-rr-receipt" aria-hidden="true"></i><span>Print Delivery Note</span>
                      </a>
                    <?php else: ?>
                      <span class="hdo-act hdo-act--wide hdo-act--pending"
                            title="The delivery note is issued once the order is packed and ready to send out.">
                        <i class="fi fi-rr-receipt" aria-hidden="true"></i><span>Delivery note &mdash; available once ready</span>
                      </span>
                    <?php endif; ?>
                  </div>
                  <?php if ($isOnlinePaymentUnpaid): ?>
                    <p style="margin:0;color:var(--admin-danger);font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;">
                      <i class="fi fi-rr-exclamation-triangle"></i>
                      <?= in_array($order['payment_method'], ['gcash', 'paymaya'], true)
                          ? 'Payment not yet verified - use "Mark Verified" above once the customer\'s reference checks out.'
                          : 'Awaiting online payment confirmation - Xendit orders complete this automatically once the customer pays.' ?>
                    </p>
                  <?php else: ?>
                  <!-- Nothing to submit here any more. The status dropdown went
                       first - an order moves by scanning its label, which is what
                       makes Packed and Ready real steps instead of ones staff skip
                       past; corrections go through the manual override on the QR &
                       Status page, which requires a written reason. The rider
                       override followed it: autoAssignPendingDeliveries() picks the
                       rider the moment an order ships, so the dropdown was a second
                       way to do a job already done. Reassignment, when it is really
                       needed, belongs on the dispatch board where the whole roster
                       and its load are visible - not on one order's card. -->
                  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                    <span style="color:var(--admin-muted);font-size:13px;">
                      Status: <strong style="color:var(--admin-text);"><?= htmlspecialchars(os_label((string)$order['status'], 'delivery')) ?></strong>
                      &mdash; changed by scanning
                    </span>
                    <span style="color:var(--admin-muted);font-size:13px;">
                      Rider: <strong style="color:var(--admin-text);"><?= !empty($order['assigned_driver_name']) ? htmlspecialchars($order['assigned_driver_name']) : 'waiting' ?></strong>
                      &mdash; assigned automatically
                    </span>
                  </div>
                  <?php endif; ?>
                </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  </div>
</main>

<!-- The proof-photo viewer. One for the page, filled from whichever thumbnail
     was clicked, and stacked above the order dialog (z-index 3100 vs 3000) so
     the order stays open underneath. -->
<div id="hdoLightbox" class="hdo-lightbox" hidden role="dialog" aria-modal="true"
     aria-label="Delivery proof photo">
  <div class="hdo-lightbox-bar">
    <span class="hdo-lightbox-caption" id="hdoLightboxCaption"></span>
    <div class="hdo-lightbox-tools">
      <a id="hdoLightboxOpen" class="hdo-lightbox-link" href="#" target="_blank" rel="noopener">
        <i class="fi fi-rr-external-link" aria-hidden="true"></i> Open original
      </a>
      <button type="button" class="hdo-lightbox-close" id="hdoLightboxClose" aria-label="Close photo">&times;</button>
    </div>
  </div>
  <div class="hdo-lightbox-stage">
    <?php /* Both arrows stay in the DOM and are hidden when the order has a
             single photo, so nothing has to be created at click time. */ ?>
    <button type="button" class="hdo-lightbox-nav hdo-lightbox-prev" id="hdoLightboxPrev" aria-label="Previous photo">
      <i class="fi fi-rr-angle-left" aria-hidden="true"></i>
    </button>
    <img id="hdoLightboxImg" alt="">
    <button type="button" class="hdo-lightbox-nav hdo-lightbox-next" id="hdoLightboxNext" aria-label="Next photo">
      <i class="fi fi-rr-angle-right" aria-hidden="true"></i>
    </button>
  </div>
</div>

<script>
// One dialog per order is rendered up front (20 per page), so opening is just
// a visibility toggle - no fetch, and the status/verify forms inside post
// exactly as they did when they lived in the long list.
// --- Orders table ----------------------------------------------------------
// The whole row opens the order, the way the cards did. Delegated, so the
// pager and the status filter can swap the tbody without rebinding anything.
document.addEventListener('click', function (e) {
  // A click on the history link is that link's own - it must not also open
  // the details dialog for the row underneath it.
  if (e.target.closest('.hdo-history-btn')) { return; }
  var row = e.target.closest('.hdo-row');
  if (!row) { return; }
  openOrderModal(parseInt(row.dataset.orderId, 10));
});

// role="button" promises Enter and Space; a <tr> gives neither for free.
document.addEventListener('keydown', function (e) {
  var row = e.target.closest ? e.target.closest('.hdo-row') : null;
  if (!row || (e.key !== 'Enter' && e.key !== ' ')) { return; }
  if (e.target.closest('.hdo-history-btn')) { return; }
  e.preventDefault();
  openOrderModal(parseInt(row.dataset.orderId, 10));
});

// The per-row "..." menu and its open/close/Escape handling used to sit
// here. Both of its own controls are gone: the row now has a single details
// button, and everything the menu offered lives inside the dialog it opens.

function openOrderModal(orderId) {
  var modal = document.getElementById('orderModal-' + orderId);
  if (!modal) return;
  modal.hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeOrderModal(orderId) {
  var modal = document.getElementById('orderModal-' + orderId);
  if (!modal) return;
  modal.hidden = true;
  if (!document.querySelector('.pickup-modal:not([hidden])')) {
    document.body.style.overflow = '';
  }
}

document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  // The proof viewer stacks on top of the order dialog, so Escape has to
  // peel one layer at a time - closing both at once would drop the admin
  // back to the queue when they only wanted out of the photo.
  if (window.hdoProofViewerOpen && window.hdoProofViewerOpen()) {
    window.hdoCloseProofViewer();
    return;
  }
  var open = document.querySelector('.pickup-modal:not([hidden])');
  if (open) { open.hidden = true; document.body.style.overflow = ''; }
});
</script>

<script>
/* ---- Proof-photo viewer -------------------------------------------------
   Delegated off the document, so it covers every order dialog on the page
   (and any row the pager swaps in) with one listener. The thumbnails stay
   real links: a modified click or a middle-click is left to the browser, so
   "open it in a tab" is still there for anyone who wants the URL. */
(function () {
  var box = document.getElementById('hdoLightbox');
  if (!box) { return; }
  var img      = document.getElementById('hdoLightboxImg');
  var caption  = document.getElementById('hdoLightboxCaption');
  var openOrig = document.getElementById('hdoLightboxOpen');
  var prevBtn  = document.getElementById('hdoLightboxPrev');
  var nextBtn  = document.getElementById('hdoLightboxNext');

  var shots = [];   // the clicked order's photo links, in grid order
  var at    = 0;

  function show(i) {
    if (!shots.length) { return; }
    at = (i + shots.length) % shots.length;
    var link = shots[at];
    var thumb = link.querySelector('img');
    img.src = link.getAttribute('href');
    img.alt = thumb ? thumb.alt : '';
    // textContent, not innerHTML: data-caption carries a customer-facing
    // uploader name that the parser has already decoded, so re-parsing it
    // as markup would be an injection path.
    caption.textContent = link.dataset.caption || '';
    openOrig.href = link.getAttribute('href');
    // One photo needs no arrows; the buttons stay in the DOM either way.
    prevBtn.hidden = nextBtn.hidden = shots.length < 2;
  }

  function openViewer(link) {
    var grid = link.closest('.hdo-proof-grid');
    shots = grid ? Array.prototype.slice.call(grid.querySelectorAll('.hdo-proof-zoom')) : [link];
    show(shots.indexOf(link));
    box.hidden = false;
  }

  function close() {
    box.hidden = true;
    img.removeAttribute('src');   // drop the decoded full-size bitmap
    shots = [];
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('.hdo-proof-zoom') : null;
    if (!link) { return; }
    // Ctrl/Cmd/Shift-click and middle-click belong to the browser.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }
    e.preventDefault();
    e.stopPropagation();   // the order dialog's own handlers stay out of it
    openViewer(link);
  });

  // Clicking the dark ground closes, the way the order dialog does. The
  // image, the bar and the arrows are all excluded by the target test.
  box.addEventListener('click', function (e) {
    if (e.target === box || e.target.classList.contains('hdo-lightbox-stage')) { close(); }
  });
  document.getElementById('hdoLightboxClose').addEventListener('click', close);
  prevBtn.addEventListener('click', function () { show(at - 1); });
  nextBtn.addEventListener('click', function () { show(at + 1); });

  // Arrow keys page through the order's photos while the viewer is up.
  document.addEventListener('keydown', function (e) {
    if (box.hidden) { return; }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); show(at - 1); }
    if (e.key === 'ArrowRight') { e.preventDefault(); show(at + 1); }
  });

  // Escape is handled by the page's one Escape listener above, which checks
  // these before it reaches for the order dialog.
  window.hdoProofViewerOpen  = function () { return !box.hidden; };
  window.hdoCloseProofViewer = close;
})();
</script>

<script>
// Status tabs -> the real <select>. The select keeps its id, name, class and
// its onchange="this.form.submit()", and stays in the DOM (visually hidden),
// so the existing GET filter is what actually runs - the tabs are only a
// different way to set it. An inline onchange IS a registered listener, so a
// dispatched 'change' triggers it exactly like a user picking an option.
// With JS off the tabs are inert and the <noscript> Apply button still works.
(function () {
  var sel = document.getElementById('deliveryStatusFilter');
  if (!sel) { return; }
  Array.prototype.forEach.call(document.querySelectorAll('.hdoq-tab'), function (tab) {
    tab.addEventListener('click', function () {
      var want = tab.getAttribute('data-status') || '';
      if (sel.value === want) { return; }          // already on this filter
      sel.value = want;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
})();

/* ---------------------------------------------------------------------
   ?highlight=<order id> - arrived here from a new-order toast's View link.

   Finds that order's row, brings it into view, flashes it, and opens the
   dialog the page already has. Nothing here queries or filters: if the
   order is not among the rows this page rendered (wrong tab, wrong page of
   the pager, or a filter excludes it) there is no row to find, and the
   admin is told that rather than left watching nothing happen.

   window.scrollTo with an offset rather than scrollIntoView: the sticky
   header would otherwise sit on top of the row it just scrolled to.
   --------------------------------------------------------------------- */
(function () {
  var id = new URLSearchParams(location.search).get('highlight');
  if (!id) { return; }

  function run() {
    var row = document.querySelector('.hdo-row[data-order-id="' + CSS.escape(id) + '"]');

    if (!row) {
      var note = document.createElement('div');
      note.className = 'noa-missing';
      note.innerHTML = 'Order #' + String(id).replace(/[^0-9]/g, '') +
        ' is not in this view.' +
        ' <a href="' + location.pathname + '">Show all</a>';
      var anchor = document.querySelector('.hdo-table-wrap, table');
      if (anchor && anchor.parentNode) { anchor.parentNode.insertBefore(note, anchor); }
      return;
    }

    var top = row.getBoundingClientRect().top + window.pageYOffset - 120;
    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });

    row.classList.add('noa-flash');
    setTimeout(function () { row.classList.remove('noa-flash'); }, 2000);

    // The page's own dialog, opened the page's own way.
    if (typeof openOrderModal === 'function') {
      openOrderModal(parseInt(id, 10));
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else { run(); }
})();
</script>

<style>
/* Page-scoped sticky footer for Pending Orders only */
body.admin{min-height:100vh;display:flex;flex-direction:column;}
body.admin > main{flex:1;}
/* Darkened versions of each status hue - the raw palette accent/success/
   danger tones only clear ~2.1-3.8:1 with white text (verified), well under
   WCAG AA 4.5:1. Same fix as store_pickup_orders.php. */
.status-badge {
  background: #6b7280;
  color: #fff;
}
.status-preparing { background: #b45309; }
.status-packed { background: #174871; }
.status-ready { background: #6d6485; }
.status-shipped { background: #0f2d4d; }
.status-completed { background: #047857; }
.status-cancelled { background: #b91c1c; }

/* The history link. It borrows .hdo-details-btn's pill from admin.css, so
   only the bits an <a> needs are set here - and scoped to this page, because
   that shared class also dresses store_pickup_orders.php's plain button. */
.hdo-col-action .hdo-history-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  text-decoration: none;
}
.hdo-col-action .hdo-history-btn:hover { text-decoration: none; color: var(--admin-text); }
.hdo-col-action .hdo-history-btn i { font-size: 13px; line-height: 1; }

/* The dialog's document actions. Same component as store_pickup_orders.php's
   .pickup-act, kept per-page rather than hoisted into admin.css: the two
   modals are the only users, and this one needs a full-width variant the
   other has no use for.

   Three 1fr tracks, so the buttons are equal width whatever their labels say
   - the inline min-width:190px this replaces only equalised them while every
   label fitted inside 190px. min-height (not padding alone) is what keeps
   them level when "Scan Label & Status" wraps. Restyled from scratch
   (appearance:none, explicit font/border) because these are an <a>, two
   <button>s and sometimes a <span>: a link inherits no button metrics and a
   button inherits no font. */
/* flex-basis 100%: the parent .order-actions is still a wrapping flex row (it
   also holds the status/rider line and the unpaid warning), and as a plain
   flex item this grid would shrink to its content - leaving the three "equal"
   columns equal to each other but not spanning the panel. */
.hdo-doc-actions { display: grid; gap: 8px; flex: 1 1 100%; width: 100%; }
.hdo-acts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; align-items: stretch; }
.hdo-act {
  -webkit-appearance: none; appearance: none;
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  min-height: 42px; padding: 10px 14px; margin: 0;
  box-sizing: border-box;
  background: var(--admin-surface); color: var(--admin-text);
  border: 1px solid var(--admin-border); border-radius: var(--radius-sm);
  box-shadow: var(--neu-raised-sm);
  font-family: inherit; font-size: 13.5px; font-weight: 600; line-height: 1.2;
  text-align: center; text-decoration: none; cursor: pointer;
  transition: box-shadow .14s ease, transform .14s ease, color .14s ease, background .14s ease;
}
.hdo-act i { font-size: 15px; line-height: 1; flex: none; }
.hdo-act span { min-width: 0; }
/* The note spans the row under the three. */
.hdo-act--wide { width: 100%; }
/* Hover FILLS. Tinting the label instead is invisible in the default theme,
   where --admin-surface (#123554) and --admin-primary (#174871) are two
   navies ~1.3:1 apart; --admin-primary-2 with #f2f3f4 is ~8.3:1 and is the
   pair .admin-quick-link (admin.css:187) already uses. */
.hdo-act:hover {
  background: var(--admin-primary-2); border-color: var(--admin-primary-2);
  color: #f2f3f4; box-shadow: var(--neu-raised); transform: translateY(-1px);
  text-decoration: none;
}
.hdo-act:hover i { color: #f2f3f4; }
.hdo-act:active {
  background: var(--admin-primary-2); color: #f2f3f4;
  transform: translateY(1px); box-shadow: var(--neu-pressed);
}
/* Accent, not primary: a #174871 ring would vanish against the same navy. */
.hdo-act:focus-visible { outline: 2px solid var(--admin-accent); outline-offset: 2px; }

/* The delivery note before the order is ready: it keeps the row's shape so the
   group stays even, but is plainly not a button - flat, muted, no pointer, and
   none of the hover above. */
.hdo-act--pending,
.hdo-act--pending:hover {
  background: none; border-style: dashed; border-color: var(--admin-border);
  box-shadow: none; color: var(--admin-muted); font-weight: 500;
  transform: none; cursor: default;
}
.hdo-act--pending:hover i { color: var(--admin-muted); }
.hdo-act--pending i { opacity: .6; }

@media (max-width: 620px) {
  /* Three equal columns give each label ~150px here, which wraps every one of
     them onto two lines. One per row instead. */
  .hdo-acts { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  .hdo-act { transition: none; }
  .hdo-act:hover, .hdo-act:active { transform: none; }
}

/* The order dialog.
   These rules were missing entirely on this page: .pickup-modal was styled
   only inside Order_Cancel_Request.php's own <style> block, so here the
   markup had no positioning, no overlay and no panel. openOrderModal() ran
   and did clear the `hidden` attribute, but an unstyled <div> simply became
   a plain block at the bottom of the document - which read as the button
   doing nothing at all.

   .pickup-modal[hidden] is load-bearing: `display:flex` below is an author
   rule and outranks the browser's own [hidden]{display:none}, so without it
   every order's dialog would be open permanently. */
.pickup-modal {
  position: fixed;
  inset: 0;
  z-index: 3000;
  background: rgba(8, 12, 20, .62);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 20px;
  overflow-y: auto;
}
.pickup-modal[hidden] { display: none; }
.pickup-modal-panel {
  width: 100%;
  max-width: 820px;
  background: var(--admin-surface-2);
  color: var(--admin-text);
  border-radius: var(--radius-xl);
  box-shadow: var(--neu-raised);
  padding: 24px;
  text-align: left;
}
.pickup-modal-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 18px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--admin-border);
  flex-wrap: wrap;
}
.pickup-modal-close {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 28px;
  line-height: 1;
  color: var(--admin-muted);
  padding: 0 4px;
}
.pickup-modal-close:hover { color: var(--admin-text); }

/* ---- Proof of delivery, inside the order dialog -------------------------
   Same surface/pressed-shadow pair the dialog's other panels use, so it
   reads as another section of the card rather than a bolted-on gallery. */
.hdo-proof {
  margin-bottom: 15px;
  padding: 15px;
  background: var(--admin-surface);
  box-shadow: var(--neu-pressed);
  border-radius: var(--radius-sm);
  color: var(--admin-text);
}
.hdo-proof-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  font-size: 15px;
  color: var(--admin-text);
}
.hdo-proof-head i { color: var(--admin-accent, #2f5d8f); }
.hdo-proof-count {
  margin-left: auto;
  font-size: 12px;
  font-weight: 600;
  color: var(--admin-muted);
}

/* Label/value pairs on one line each, wrapping as a group - the dialog is
   read at a glance, not scanned column by column. */
.hdo-proof-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 24px;
  margin: 0 0 14px;
}
.hdo-proof-meta div { display: flex; align-items: baseline; gap: 8px; }
.hdo-proof-meta dt { font-weight: 600; font-size: 13px; }
.hdo-proof-meta dd { margin: 0; font-size: 13px; color: var(--admin-muted); }

/* auto-fill, not fixed columns: one photo should not stretch to the full
   dialog width, and six should still wrap tidily. */
.hdo-proof-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 12px;
}
.hdo-proof-cell { margin: 0; }
.hdo-proof-cell a {
  display: block;
  border-radius: var(--radius-sm);
  overflow: hidden;
  box-shadow: var(--neu-raised-sm);
}
.hdo-proof-cell img {
  display: block;
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  background: var(--admin-surface-2);
  transition: transform 160ms ease;
}
.hdo-proof-cell a:hover img { transform: scale(1.03); }
.hdo-proof-cell a:focus-visible { outline: 2px solid var(--accent-cyan, #17646b); outline-offset: 2px; }
.hdo-proof-cell a { cursor: zoom-in; }

/* ---- The proof-photo viewer --------------------------------------------
   Deliberately dark and chrome-free rather than another neumorphic panel:
   the photo is the content, and a near-black ground is what lets an admin
   judge a dim porch shot. Sits above .pickup-modal (3000) so the order
   dialog stays open behind it; [hidden] has to be restated for the same
   reason it does there - `display:flex` is an author rule. */
.hdo-lightbox {
  position: fixed;
  inset: 0;
  z-index: 3100;
  background: rgba(6, 9, 14, .93);
  display: flex;
  flex-direction: column;
  padding: 14px 16px 22px;
  gap: 10px;
}
.hdo-lightbox[hidden] { display: none; }
.hdo-lightbox-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  color: rgba(255, 255, 255, .88);
}
.hdo-lightbox-caption { font-size: 13px; font-weight: 600; letter-spacing: .01em; }
.hdo-lightbox-tools { display: flex; align-items: center; gap: 14px; }
.hdo-lightbox-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 600;
  color: rgba(255, 255, 255, .78);
  text-decoration: none;
}
.hdo-lightbox-link:hover { color: #fff; text-decoration: underline; }
.hdo-lightbox-close {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 30px;
  line-height: 1;
  padding: 0 4px;
  color: rgba(255, 255, 255, .7);
}
.hdo-lightbox-close:hover { color: #fff; }

/* The stage takes the remaining height and the image is capped by it, so a
   tall portrait photo scales down instead of pushing the bar off-screen. */
.hdo-lightbox-stage {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}
.hdo-lightbox-stage img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  border-radius: var(--radius-sm);
  background: #0b0f16;
}
.hdo-lightbox-nav {
  flex: 0 0 auto;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  border: 1px solid rgba(255, 255, 255, .22);
  background: rgba(255, 255, 255, .08);
  color: #fff;
  font-size: 18px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.hdo-lightbox-nav:hover { background: rgba(255, 255, 255, .18); }
.hdo-lightbox-nav[hidden] { display: none; }
@media (max-width: 600px) {
  .hdo-lightbox-nav { width: 38px; height: 38px; }
  .hdo-lightbox-caption { font-size: 12px; }
}
.hdo-proof-cell figcaption {
  margin-top: 6px;
  font-size: 11.5px;
  line-height: 1.4;
  color: var(--admin-muted);
}

/* The missing-photo case is a finding, not a neutral empty state - it is
   given the warning tint rather than the muted one. */
.hdo-proof-empty {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 0;
  padding: 12px 14px;
  border-radius: var(--radius-sm);
  background: var(--tw-warn-bg, #fdf3e0);
  border: 1px solid var(--tw-warn-line, #ecd9ab);
  color: var(--tw-warn, #8a5a00);
  font-size: 13px;
  line-height: 1.5;
}
.hdo-proof-empty i { flex: 0 0 auto; margin-top: 2px; }

.hdo-proof-history {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 14px;
  font-size: 13px;
  font-weight: 600;
  color: var(--admin-accent, #2f5d8f);
  text-decoration: none;
}
.hdo-proof-history:hover { text-decoration: underline; }

/* The row is the primary control (the JS opens the dialog from a click or
   Enter/Space anywhere on it), so it has to look like one. */
.hdo-row { cursor: pointer; }
.hdo-row:hover { background: var(--admin-surface); }
.hdo-row:focus-visible {
  outline: 2px solid var(--accent-cyan, #174871);
  outline-offset: -2px;
}
</style>

<?php /* The invoice document - stylesheet, print node and builder.
         Shared with store_pickup_orders.php; see includes/order_invoice.php. */ ?>
<?php include __DIR__ . '/includes/order_invoice.php'; ?>
<?php include __DIR__ . '/includes/sticker_modal.php'; ?>
<?php /* Styles and behaviour for the unit panels above. Emits once however
         many orders are on the page. */ ?>
<?php tw_units_assets(); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
