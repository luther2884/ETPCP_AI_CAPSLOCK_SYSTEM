<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';
// The printed invoice: payload builders, which in turn pull the seller's
// details, tax status and policy boilerplate from config/business_info.php.
require_once __DIR__ . '/../../../config/invoice.php';
// Serial-number display and the packing-bench assignment controls.
// Required at the top, not inside the try below: tw_units_item_serials()
// is called from the markup, which still renders when the query fails.
require_once __DIR__ . '/includes/order_units_panel.php';
require_role(['admin']);

$pageTitle = 'Store Pickup Orders - TechWorld Admin';
$isAdminTheme = true;

// Manually verify a GCash/Maya reference number for a pickup order - same
// handler as Home_Delivery_Orders.php's, duplicated here (rather than
// cross-page POSTing there) so staff stay on this page and the confirmation
// they see matches the order they were looking at.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_epayment') {
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
// There is deliberately no status/tracking POST handler here any more. The
// pickup modal used to carry a status <select> plus a tracking-number text
// field, and this is where they landed - UPDATE orders SET status, tracking_code.
// Status now moves by scanning (scan_orders.php / order_status.php), which is
// the one path that records who scanned what and when; tracking codes are
// issued with the order and never rewritten. The form is gone from the modal,
// so this handler had no caller - and left in place it would still have been a
// reachable endpoint for both edits, which is the thing being removed.
// verify_epayment above is unrelated and stays: it proves money arrived.

// Get store pickup orders
try {
    $pdo = getConnection();

    // Opportunistic sweep for online-payment orders left unpaid over an
    // hour - see cancelExpiredOnlinePaymentOrders() in config/functions.php.
    cancelExpiredOnlinePaymentOrders();

    // Status filter. Anything not in this list falls back to "all", so the
    // value is safe to drop straight into the WHERE clause below.
    $statusOptions = ['preparing', 'packed', 'ready', 'completed', 'cancelled'];
    $statusFilter = (string)($_GET['status'] ?? '');
    if (!in_array($statusFilter, $statusOptions, true)) { $statusFilter = ''; }
    $statusWhere = $statusFilter !== '' ? " AND o.status = " . $pdo->quote($statusFilter) : '';

    // Per-status tallies for the filter labels - one grouped read, not one
    // count per option.
    $statusCounts = [];
    foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM orders WHERE delivery_method = 'pickup' GROUP BY status") as $row) {
        $statusCounts[(string)$row['status']] = (int)$row['c'];
    }

    // Count of store pickup orders matching the current filter
    $countStmt = $pdo->query("SELECT COUNT(*) FROM orders o WHERE o.delivery_method = 'pickup'" . $statusWhere);
    $totalOrders = (int)$countStmt->fetchColumn();

    // 10 rows per page, matching Home_Delivery_Orders.php now that this queue
    // uses the same table. Every row also renders its own details dialog
    // (#orderModal-<id> below), so the count is a direct multiplier on the
    // markup shipped per request.
    // Page is clamped to the real range before it reaches the query.
    $perPage = 10;
    $totalPages = max(1, (int)ceil($totalOrders / $perPage));
    $page = max(1, (int)($_GET['page'] ?? 1));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;
    
    // Fetch store pickup orders with user info
    $ordersStmt = $pdo->prepare("
        SELECT o.*, u.firstname, u.lastname, u.email,
               COALESCE(
                   (SELECT CONCAT_WS(', ', full_name, phone, street, region, postal) FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1),
                   (SELECT CONCAT_WS(', ', full_name, phone, street, region, postal) FROM user_addresses WHERE user_id = o.user_id LIMIT 1),
                   u.address
               ) as address,
               (SELECT transaction_id FROM payments WHERE order_id = o.id ORDER BY id DESC LIMIT 1) as epay_transaction_id,
               (SELECT payment_status FROM payments WHERE order_id = o.id ORDER BY id DESC LIMIT 1) as epay_status
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.delivery_method = 'pickup'{$statusWhere}
        ORDER BY o.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $ordersStmt->execute();
    $orders = $ordersStmt->fetchAll();
    
    foreach ($orders as &$order) {
        // image_url and the category name feed the leading column of the
        // orders table; the rest is what the details dialog already used.
        $itemsStmt = $pdo->prepare("
            SELECT oi.*, p.name as product_name, p.price, p.image_url,
                   -- oi.* already selects oi.price and oi.total, but the bare
                   -- p.price above lands on the same key and overwrites oi.price
                   -- in the fetched row - so `price` is the CURRENT catalogue
                   -- price, not what the customer was charged. The invoice was
                   -- computing its line amounts from it, which meant reprinting
                   -- an old order after a price change produced an invoice that
                   -- disagreed with what was actually paid. These two aliases
                   -- are unambiguous, and purely additive: every existing
                   -- reader of the items array keeps the keys it already uses.
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
    unset($order);

    // Serial numbers for every line, and which lines still need one. Must run
    // AFTER the items loop (it reads $order['items']) and BEFORE
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
    $statusOptions = ['preparing', 'packed', 'ready', 'completed', 'cancelled'];
    $statusCounts = [];
}

// Pager links have to carry the active filter, otherwise page 2 silently
// resets to "all statuses".
function pickupPageUrl(int $n): string {
    global $statusFilter;
    $qs = ['page' => $n];
    if ($statusFilter !== '') { $qs['status'] = $statusFilter; }
    return '?' . http_build_query($qs);
}

/* The printed invoice, as data. Both admin queues that print one build it
   the same way - see config/invoice.php - so the money, the escaping and the
   one-page budget cannot drift between this page and the delivery queue. */
$invoiceData  = tw_invoice_payload($orders, 'pickup');
$invoiceStore = tw_invoice_store();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<?php
/* -----------------------------------------------------------------------
   VIEW LAYER ONLY. Every query, the POST handlers, the status flow and the
   Details dialogs below are untouched. Derived from values already computed.
   -------------------------------------------------------------------- */
$spoShownFrom = $orders ? $offset + 1 : 0;
$spoShownTo   = $orders ? $offset + count($orders) : 0;

/* Same six avatar tints as the delivery queue and view_customer.php. */
$spoTints = [
  ['#e7f6ef', '#0f6b4a'], ['#e8f0fa', '#2f5d8f'], ['#f3eefb', '#5647a8'],
  ['#fdecf1', '#a8264d'], ['#fdf3e0', '#8a5a00'], ['#e6f4f4', '#17646b'],
];

/* Same trio map as Home_Delivery_Orders.php. This queue is NOT pre-ship - it
   filters on delivery_method='pickup' with no status restriction - so
   completed and cancelled really do occur here, and awaiting_payment or
   shipped can appear under "All orders" even though $statusOptions has no
   tab for them. All seven are mapped so no status can fall through. */
$spoStatusTrio = [
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
<!-- The queue layout both order pages share. Linked here, before the markup
     it styles, rather than after it. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/css/admin-queue.css') ?>">

<main class="container hdoq hdoq-pickup">
<div class="hdoq-wrap">

  <?php if (isset($success)): ?>
    <div class="notification-banner notification-success"><div class="container"><span><?= htmlspecialchars($success) ?></span></div></div>
  <?php endif; ?>
  <?php if (isset($error)): ?>
    <div class="notification-banner notification-error"><div class="container"><span><?= htmlspecialchars($error) ?></span></div></div>
  <?php endif; ?>

  <!-- 1. Header. Same pattern as the delivery queue: back control, eyebrow,
       title, ghost group. No rider chip - store pickup has no riders. -->
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
        <h1>Store Pickup Orders</h1>
      </div>
    </div>
    <div class="hdoq-head-r">
      <!-- The reciprocal of the delivery queue's "Store pickup orders" link.
           New here: that page linked to this one, nothing linked back. -->
      <a class="hdoq-ghost" href="<?= WEB_ROOT ?>public/authenticated/admin/Home_Delivery_Orders.php">Home delivery orders &rarr;</a>
    </div>
  </header>

  <!-- 2. Status tabs. The real <select> keeps its id, name and onchange and
       stays in the DOM (visually hidden); the tabs set it and dispatch
       'change', so the existing GET filter runs untouched. Six options here
       against the delivery queue's five - this queue includes completed and
       cancelled. -->
  <div class="hdoq-toolbar">
    <form method="GET" class="pickup-filter">
      <label class="hdoq-sr" for="pickupStatusFilter">Show:</label>
      <select id="pickupStatusFilter" name="status" class="admin-form-input hdoq-sr" onchange="this.form.submit()">
        <option value="">All orders (<?= number_format(array_sum($statusCounts)) ?>)</option>
        <?php foreach ($statusOptions as $opt): ?>
          <option value="<?= $opt ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>>
            <?= os_label($opt, 'pickup') ?> (<?= number_format($statusCounts[$opt] ?? 0) ?>)
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
            <?= htmlspecialchars(os_label($opt, 'pickup')) ?> <span class="hdoq-tab-n"><?= number_format($statusCounts[$opt] ?? 0) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <?php if (empty($orders)): ?>
    <div class="hdo-card">
      <div class="hdoq-empty">
        <?php if ($statusFilter !== ''): ?>
          <h3>No <?= htmlspecialchars(os_label($statusFilter, 'pickup')) ?> pickup orders</h3>
          <p>Nothing matches this filter right now. <a href="?">Show all orders</a>.</p>
        <?php else: ?>
          <h3>No store pickup orders</h3>
          <p>Orders will appear here once customers choose in-store pickup.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <!-- 3. The queue. Same seven columns as the delivery page. ORDER leads
         because it is what admins search by; PAYMENT is its own column
         instead of grey text under the amount. role="table"/"row"/"cell" are
         explicit because display:grid drops the native table semantics. -->
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
              <?php /* No visible label: the cell below holds a button that says
                       what it does, so a "Details"/"Actions" word above it is
                       the same word twice. Kept for screen readers, which
                       announce column headers and would otherwise read the
                       last cell with no context. */ ?>
              <th scope="col" role="columnheader" class="hdo-col-action-h"><span class="hdoq-sr">Actions</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $order): ?>
            <?php
              // Same reasoning as Home_Delivery_Orders.php: orders.status is
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

              $customerName = trim(($order['firstname'] ?? '') . ' ' . ($order['lastname'] ?? ''));
              $initials = strtoupper(mb_substr($order['firstname'] ?? '', 0, 1) . mb_substr($order['lastname'] ?? '', 0, 1));
              if (trim($initials) === '') { $initials = '#'; }
              $tint = $spoTints[(int)$order['id'] % 6];

              $orderNumber = $order['invoice_no'] ?: ('#' . (int)$order['id']);

              // Same rule as the delivery queue: 5 methods x 6 payment states
              // is 30 combinations, so this is a rule rather than a lookup.
              $payLabels = ['cod' => 'COD', 'gcash' => 'GCash', 'paymaya' => 'Maya', 'xendit' => 'Xendit', 'onsite' => 'In store'];
              $payMethod = (string)($order['payment_method'] ?? '');
              $payState  = (string)($order['payment_status'] ?? '');
              $payShort  = $payLabels[$payMethod] ?? ucfirst($payMethod ?: 'Unknown');
              if ($payState === 'paid') {
                  $payTrio = ['#e7f6ef', '#0f6b4a', '#b9e3d0'];
                  $payText = 'Paid &middot; ' . htmlspecialchars($payShort);
              } elseif (in_array($payMethod, ['cod', 'onsite'], true)) {
                  // Paid at the counter on collection - expected, not a problem.
                  $payTrio = ['#fdf3e0', '#8a5a00', '#ecd9ab'];
                  $payText = htmlspecialchars($payShort) . ' &middot; Unpaid';
              } else {
                  $payTrio = ['#fdecf1', '#a8264d', '#f2c4d3'];
                  $payText = htmlspecialchars($payShort) . ' &middot; ' . htmlspecialchars(ucfirst($payState ?: 'Unpaid'));
              }

              $stTrio = $spoStatusTrio[$order['status']] ?? ['#f7f9fc', '#46586f', '#dbe3ee'];
              $stText = ($cardUnpaid && $order['status'] === 'preparing')
                  ? 'Unpaid'
                  : os_label((string)$order['status'], 'pickup');
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
                <!-- Details only. The "..." menu that sat beside it held exactly
                     two items and both were already one click away: "View full
                     details" opened this same modal, and "Print invoice" is a
                     button inside it. A second control that leads where the
                     first one goes is not a shortcut, so the column is one
                     button wide now and the label above it says Details.
                     (Home_Delivery_Orders.php shares this table's CSS but keeps
                     its own menu - it has four real actions.) -->
                <div class="hdo-actions">
                  <button type="button" class="hdo-details-btn" onclick="openOrderModal(<?= (int)$order['id'] ?>)"
                          aria-haspopup="dialog" aria-controls="orderModal-<?= (int)$order['id'] ?>">Details</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="hdoq-foot">
        <span class="hdoq-foot-n">Showing <?= number_format($spoShownFrom) ?>&ndash;<?= number_format($spoShownTo) ?> of <?= number_format($totalOrders) ?></span>
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
            <a class="pickup-page<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= pickupPageUrl(max(1, $page - 1)) ?>" aria-label="Previous page">&#8249;</a>
            <?php $prev = 0; foreach ($window as $n): ?>
              <?php if ($prev && $n > $prev + 1): ?><span class="pickup-page-gap">&hellip;</span><?php endif; ?>
              <a class="pickup-page<?= $n === $page ? ' is-current' : '' ?>" href="<?= pickupPageUrl($n) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
              <?php $prev = $n; ?>
            <?php endforeach; ?>
            <a class="pickup-page<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= pickupPageUrl(min($totalPages, $page + 1)) ?>" aria-label="Next page">&#8250;</a>
          </nav>
        <?php endif; ?>

        <!-- One dialog per card. Everything the old full-height list showed -
             payment details, items, the status form, print invoice - lives here
             now, so the queue itself stays scannable. -->
        <?php foreach ($orders as $order): ?>
          <?php
            // Same reasoning as Home_Delivery_Orders.php: orders.status is
            // always 'preparing' from the moment an order is placed,
            // regardless of payment_status - "PREPARING" would be misleading
            // for an unpaid online-payment order since nothing can actually
            // be prepared until it's paid.
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

                <div class="order-details" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:15px;color:var(--admin-text);">
                  <div>
                    <strong>Order Date:</strong><br>
                    <span style="color:var(--admin-muted);"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></span>
                  </div>
                  <div>
                    <strong>Total Amount:</strong><br>
                    ₱<?= number_format((float)($order['total_amount'] ?? 0), 2) ?>
                  </div>
                  <div>
                    <strong>Payment Method:</strong><br>
                    <span style="color:var(--admin-muted);"><?= htmlspecialchars(getPaymentMethodLabel($order['payment_method'] ?? 'N/A')) ?></span>
                  </div>
                  <?php if (in_array($order['payment_method'] ?? '', ['gcash', 'paymaya'], true)): ?>
                  <div>
                    <strong>Customer's Txn Ref:</strong><br>
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
                  <div>
                    <strong>Payment:</strong><br>
                    <span class="admin-badge admin-badge--pending">Awaiting Xendit confirmation</span>
                  </div>
                  <?php endif; ?>
                  <div>
                    <strong>Pickup Location:</strong><br>
                    <span style="color:var(--admin-muted);"><?= htmlspecialchars($order['address'] ?? 'Main Store') ?></span>
                  </div>
                  <?php if (!empty($order['tracking_code'])): ?>
                  <div>
                    <strong>Tracking Number:</strong><br>
                    <span class="tracking-code" style="color:var(--admin-text);font-weight:600;"><?= htmlspecialchars($order['tracking_code']) ?></span>
                  </div>
                  <?php endif; ?>
                </div>

                <div class="order-items" style="margin-bottom:15px;">
                  <h4 style="margin:0 0 10px 0;color:var(--admin-text);">Order Items:</h4>
                  <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($order['items'] as $item): ?>
                      <div style="display:flex;justify-content:space-between;padding:8px 10px;background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);color:var(--admin-text);">
                        <span><?= htmlspecialchars($item['product_name']) ?><?= tw_units_item_serials($item) ?></span>
                        <span>Qty: <?= $item['quantity'] ?> × ₱<?= number_format((float)($item['price'] ?? 0), 2) ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <?php
                  /* Serial numbers, and the controls to record them. Renders
                     nothing for an order with no serialised lines. */
                  tw_units_panel($order, !empty($actionsDisabled));
                ?>

                <?php /* Three actions, one shape, equal share of the row.
                         The manual status <select> and the tracking-number
                         field that used to sit in the middle of this row are
                         gone: status is driven by scanning (scan_orders.php,
                         and the label/history page below), and the tracking
                         code is issued once with the order - a free-text box
                         that silently rewrote it was a way to break a code a
                         customer is already holding, not a feature. The POST
                         handler behind that form was removed with it, so the
                         endpoint is gone too, not just the control.

                         Equal-width tracks rather than inline-flex: the three
                         labels are different lengths, and left-packed pills of
                         three different widths were what made this row look
                         unfinished. One <a> and two <button>s are three
                         different elements, so .pickup-act resets the chrome
                         on all three instead of trusting .admin-quick-link. */ ?>
                <div class="order-actions pickup-acts">
                  <a class="pickup-act" href="<?= WEB_ROOT ?>public/authenticated/admin/order_status.php?id=<?= (int)$order['id'] ?>">
                    <i class="fi fi-rr-qr-code" aria-hidden="true"></i><span>Label &amp; history</span>
                  </a>
                  <button type="button" class="pickup-act" onclick="openStickerModal(<?= (int)$order['id'] ?>)">
                    <i class="fi fi-rr-document" aria-hidden="true"></i><span>Print Sticker</span>
                  </button>
                  <button type="button" class="pickup-act" onclick="printOrderInvoice(<?= (int)$order['id'] ?>)">
                    <i class="fi fi-rs-print" aria-hidden="true"></i><span>Print Invoice</span>
                  </button>
                </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  </div>
</main>

<style>
/* The order dialog. Ported from Home_Delivery_Orders.php, where these rules
   were added after the same markup was found to be completely unstyled here.
   .pickup-modal[hidden] is load-bearing: `display:flex` is an author rule and
   outranks the browser's own [hidden]{display:none}, so without it every
   order's dialog would be open at once. */
.pickup-modal{position:fixed;inset:0;z-index:3000;background:rgba(8,12,20,.62);
  display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto;}
.pickup-modal[hidden]{display:none;}
.pickup-modal-panel{width:100%;max-width:820px;background:var(--admin-surface-2);color:var(--admin-text);
  border-radius:var(--radius-xl);box-shadow:var(--neu-raised);padding:24px;text-align:left;}
.pickup-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;
  margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--admin-border);flex-wrap:wrap;}
.pickup-modal-close{background:none;border:none;cursor:pointer;font-size:28px;line-height:1;
  color:var(--admin-muted);padding:0 4px;}
.pickup-modal-close:hover{color:var(--admin-text);}

/* The dialog's action row. Three tracks of exactly 1fr: every button gets the
   same width and the same share of the row, whatever its label is - which is
   what "uniform" has to mean across labels of 15, 13 and 13 characters. The
   row stretches edge to edge of the panel, so the three read as one group
   rather than as a left-packed cluster with dead space after it.

   .pickup-act restyles from scratch (appearance:none, explicit border and
   font) because the three are an <a> and two <button>s: a link inherits no
   button metrics and a button inherits no font, so anything left implicit
   would differ between them. min-height, not padding alone, is what keeps the
   three the same height when one label wraps. */
.pickup-acts{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;align-items:stretch;margin:0;}
.pickup-act{
  -webkit-appearance:none;appearance:none;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  min-height:42px;padding:10px 14px;margin:0;
  box-sizing:border-box;
  background:var(--admin-surface);color:var(--admin-text);
  border:1px solid var(--admin-border);border-radius:var(--radius-sm);
  box-shadow:var(--neu-raised-sm);
  font-family:inherit;font-size:13.5px;font-weight:600;line-height:1.2;
  text-align:center;text-decoration:none;cursor:pointer;
  transition:box-shadow .14s ease,transform .14s ease,color .14s ease;}
.pickup-act i{font-size:15px;line-height:1;flex:none;}
.pickup-act span{min-width:0;}
/* Hover FILLS instead of recolouring the label. The first version tinted the
   text with --admin-primary and left the surface alone, which is invisible in
   the default theme: --admin-surface is #123554 and --admin-primary is
   #174871, two navies about 1.3:1 apart. --admin-primary-2 is #174871 in both
   themes and #f2f3f4 on it is ~8.3:1, so the same pair works light and dark -
   and it is the pair .admin-quick-link (admin.css:187) already uses, so a
   filled navy button on hover is what the rest of the panel does.
   The icon is given the colour explicitly: `fi` glyphs are a font, but some
   icon rules in admin.css set their own colour and would otherwise stay. */
.pickup-act:hover{
  background:var(--admin-primary-2);border-color:var(--admin-primary-2);
  color:#f2f3f4;box-shadow:var(--neu-raised);transform:translateY(-1px);
  text-decoration:none;}
.pickup-act:hover i{color:#f2f3f4;}
.pickup-act:active{
  background:var(--admin-primary-2);color:#f2f3f4;
  transform:translateY(1px);box-shadow:var(--neu-pressed);}
/* Accent, not primary: a #174871 outline would vanish against the same navy
   ground the ring is drawn on. */
.pickup-act:focus-visible{outline:2px solid var(--admin-accent);outline-offset:2px;}
/* Below ~560px three equal columns give each label about 150px, which wraps
   "Label & history" onto two lines. One per row instead. */
@media (max-width:560px){
  .pickup-acts{grid-template-columns:1fr;}
}
@media (prefers-reduced-motion:reduce){
  .pickup-act{transition:none;}
  .pickup-act:hover,.pickup-act:active{transform:none;}
}
</style>
<script>
// Status tabs -> the real <select>, exactly as on Home_Delivery_Orders.php.
// The select keeps its id, name, class and onchange and stays in the DOM
// (visually hidden), so the existing GET filter is what runs. An inline
// onchange IS a registered listener, so a dispatched 'change' triggers it.
// With JS off the tabs are inert and the <noscript> Apply button still works.
(function () {
  var sel = document.getElementById('pickupStatusFilter');
  if (!sel) { return; }
  Array.prototype.forEach.call(document.querySelectorAll('.hdoq-tab'), function (tab) {
    tab.addEventListener('click', function () {
      var want = tab.getAttribute('data-status') || '';
      if (sel.value === want) { return; }
      sel.value = want;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
})();
</script>

<script>
// One dialog per order is rendered up front (10 per page), so opening is just
// a visibility toggle - no fetch, and the status/verify forms inside post
// exactly as they did when they lived in the long list.

// --- Orders table ----------------------------------------------------------
// Same row handlers as Home_Delivery_Orders.php, which uses this table too.
// The whole row opens the order, the way the cards did. Delegated, so the
// pager and the status filter can swap the tbody without rebinding anything.
// The row-menu code that used to follow is gone with the menu itself - this
// queue renders no .hdo-more-btn, so it was listening for a button that is
// never on the page.
document.addEventListener('click', function (e) {
  // A click that landed on the Details button is that button's own - it must
  // not also fire the row and open the modal twice.
  if (e.target.closest('.hdo-details-btn')) { return; }
  var row = e.target.closest('.hdo-row');
  if (!row) { return; }
  openOrderModal(parseInt(row.dataset.orderId, 10));
});

// role="button" promises Enter and Space; a <tr> gives neither for free.
document.addEventListener('keydown', function (e) {
  var row = e.target.closest ? e.target.closest('.hdo-row') : null;
  if (!row || (e.key !== 'Enter' && e.key !== ' ')) { return; }
  if (e.target.closest('.hdo-details-btn')) { return; }
  e.preventDefault();
  openOrderModal(parseInt(row.dataset.orderId, 10));
});
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
  closeAllRowMenus(null);
  var open = document.querySelector('.pickup-modal:not([hidden])');
  if (open) { open.hidden = true; document.body.style.overflow = ''; }
});
</script>

<?php /* The invoice document - stylesheet, print node and builder.
         Shared with Home_Delivery_Orders.php; see includes/order_invoice.php. */ ?>
<?php include __DIR__ . '/includes/order_invoice.php'; ?>

<script>
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


<?php include __DIR__ . '/includes/sticker_modal.php'; ?>
<?php /* Styles and behaviour for the unit panels above. Emits once. */ ?>
<?php tw_units_assets(); ?>
<?php include __DIR__ . '/includes/footer.php'; ?>


