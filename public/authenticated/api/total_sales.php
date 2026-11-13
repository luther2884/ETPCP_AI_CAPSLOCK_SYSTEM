<?php
// api/total_sales.php - Payment-channel split plus settlement state, backing
// the admin Analytics "Total Sales" card.
//
// WHY THIS EXISTS
// Like the Sales Report card before it, Total Sales rendered from PHP inlined
// into the page at load time: the donut, the channel list and the orders/AOV
// footer were all fixed at render and only a full page reload through the
// page's GET date filter could move them. This endpoint gives the card its own
// period control and a live read, using the range vocabulary in
// config/report_ranges.php that the other report cards share.
//
// WHAT IT ADDS
// The card showed how much money each channel brought in, but not whether any
// of it had actually been collected. On this data 82% of the "Online" slice is
// cancelled payments - money that never arrived, presented identically to
// money that did. `settlement` below splits the same total into settled /
// awaiting / cancelled so the donut can be read honestly.
//
// CHANNEL RULES
// The page's old inline query summed three independent CASE expressions:
//   store_pickup -> instore, home_delivery -> cod, payment_method='online' -> online
// payment_type and payment_method are different axes, so a row matching both
// an "online" method and a delivery type would have been counted twice. It
// happens not to occur in current data, but the rule was unsafe. Each payment
// is now classified into exactly one channel, in priority order, which yields
// the same figures for this data and cannot double count.
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/report_ranges.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (function_exists('getUserRole') ? getUserRole() : ($_SESSION['user_role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/** Display order, labels and swatches - the same three the card has always shown. */
const TOTAL_SALES_CHANNELS = [
    'online'  => ['label' => 'Online',           'color' => '#06b6d4'],
    'instore' => ['label' => 'In-Store Pickup',  'color' => '#f59e0b'],
    'cod'     => ['label' => 'Cash on Delivery', 'color' => '#10b981'],
    'other'   => ['label' => 'Other',            'color' => '#94a3b8'],
];

/**
 * Exactly one channel per payment row, checked in priority order so the
 * buckets cannot overlap. Online wins first because an online payment is an
 * online sale regardless of how the goods then reach the customer.
 */
function totalSalesChannelFor(?string $paymentType, ?string $paymentMethod): string
{
    $type   = strtolower(trim((string)$paymentType));
    $method = strtolower(trim((string)$paymentMethod));

    if ($type === 'online_payment' || $method === 'online') {
        return 'online';
    }
    if ($type === 'store_pickup') {
        return 'instore';
    }
    if ($type === 'home_delivery') {
        return 'cod';
    }
    return 'other';
}

/**
 * Settlement state of a payment row. 'processing' sits with awaiting rather
 * than settled: the money is in flight, not banked. 'refunded' sits with
 * cancelled - it came in and went back out, so counting it as collected would
 * overstate what the shop actually kept.
 */
function totalSalesSettlementFor(?string $paymentStatus): string
{
    switch (strtolower(trim((string)$paymentStatus))) {
        case 'completed':
            return 'settled';
        case 'cancelled':
        case 'failed':
        case 'refunded':
            return 'lost';
        default: // pending, processing, anything new
            return 'awaiting';
    }
}

$range       = $_GET['range'] ?? 'last_6_months';
$customStart = $_GET['start_date'] ?? null;
$customEnd   = $_GET['end_date'] ?? null;

try {
    [$start, $end] = report_resolve_range($range, null, $customStart, $customEnd);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid date supplied']);
    exit;
}

$dayCount = (int)$start->diff($end)->days + 1;
if ($dayCount > 732) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Date range is too large (maximum 732 days).']);
    exit;
}

$startSql = $start->format('Y-m-d');
$endSql   = $end->format('Y-m-d');

try {
    $pdo = getConnection();

    // The payments table is guarded the same way the page used to guard it -
    // it may not exist on every install, and the card should degrade to the
    // headline totals rather than 500.
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $hasPayments = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'payments'");
    $hasPayments->execute([$dbName]);
    $paymentsExist = $hasPayments->rowCount() > 0;

    // Headline figures come from `orders`, NOT from `payments`. That is
    // deliberate: the number in the middle of the donut has to agree with the
    // page's own Sales KPI card and with the Sales Report card, and all of
    // those count orders. The channel slices are a payments-table view of the
    // same window, so `reconciliation` below reports any gap instead of
    // letting two totals silently disagree on one screen.
    $ordersStmt = $pdo->prepare("
        SELECT COUNT(*) AS orders,
               COALESCE(SUM(total_amount), 0) AS revenue,
               COALESCE(SUM(CASE WHEN status = 'cancelled' THEN total_amount ELSE 0 END), 0) AS cancelled_amount,
               COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled_orders
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $ordersStmt->execute([$startSql, $endSql]);
    $o = $ordersStmt->fetch(PDO::FETCH_ASSOC) ?: ['orders' => 0, 'revenue' => 0, 'cancelled_amount' => 0, 'cancelled_orders' => 0];

    $ordersCount    = (int)$o['orders'];
    $ordersRevenue  = (float)$o['revenue'];
    $cancelledAmt   = (float)$o['cancelled_amount'];
    $cancelledCount = (int)$o['cancelled_orders'];

    // --- channel + settlement split from payments --------------------------

    $channelKeys   = array_keys(TOTAL_SALES_CHANNELS);
    $channelAmount = array_fill_keys($channelKeys, 0.0);
    $channelOrders = array_fill_keys($channelKeys, 0);
    $channelSettled = array_fill_keys($channelKeys, 0.0);
    $settlement = [
        'settled'  => ['amount' => 0.0, 'count' => 0],
        'awaiting' => ['amount' => 0.0, 'count' => 0],
        'lost'     => ['amount' => 0.0, 'count' => 0],
    ];
    $paymentsTotal = 0.0;

    if ($paymentsExist) {
        $payStmt = $pdo->prepare("
            SELECT p.payment_type, p.payment_method, p.payment_status,
                   SUM(p.amount) AS amount, COUNT(*) AS cnt
            FROM orders o
            INNER JOIN payments p ON o.id = p.order_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            GROUP BY p.payment_type, p.payment_method, p.payment_status
        ");
        $payStmt->execute([$startSql, $endSql]);

        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $channel = totalSalesChannelFor($row['payment_type'], $row['payment_method']);
            $state   = totalSalesSettlementFor($row['payment_status']);
            $amount  = (float)$row['amount'];
            $count   = (int)$row['cnt'];

            $channelAmount[$channel] += $amount;
            $channelOrders[$channel] += $count;
            if ($state === 'settled') {
                $channelSettled[$channel] += $amount;
            }
            $settlement[$state]['amount'] += $amount;
            $settlement[$state]['count']  += $count;
            $paymentsTotal += $amount;
        }
    }

    $channels = [];
    foreach (TOTAL_SALES_CHANNELS as $key => $meta) {
        // 'Other' only appears when something actually lands in it - a payment
        // type the rules above do not recognise. A permanent 0% row would be
        // noise; a row that suddenly appears is a signal worth seeing.
        if ($key === 'other' && $channelAmount[$key] <= 0 && $channelOrders[$key] === 0) {
            continue;
        }
        $amount = round($channelAmount[$key], 2);
        $channels[] = [
            'key'           => $key,
            'label'         => $meta['label'],
            'color'         => $meta['color'],
            'amount'        => $amount,
            'orders'        => $channelOrders[$key],
            'share'         => $paymentsTotal > 0 ? round($amount / $paymentsTotal * 100, 1) : 0.0,
            'settled'       => round($channelSettled[$key], 2),
            'settled_share' => $amount > 0 ? round($channelSettled[$key] / $amount * 100, 1) : 0.0,
        ];
    }

    foreach ($settlement as $k => $v) {
        $settlement[$k]['amount'] = round($v['amount'], 2);
        $settlement[$k]['share']  = $paymentsTotal > 0 ? round($v['amount'] / $paymentsTotal * 100, 1) : 0.0;
    }

    // --- the immediately preceding window, same length ---------------------

    [$prevStart, $prevEnd] = report_previous_window($start, $dayCount);
    $prevStmt = $pdo->prepare("
        SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $prevStmt->execute([$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')]);
    $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['orders' => 0, 'revenue' => 0];
    $prevRevenue = (float)$prev['revenue'];

    // Null, not "+100%": a percentage against a zero baseline is a made-up
    // number and the card says so in words instead.
    $deltaPct = $prevRevenue > 0
        ? round(($ordersRevenue - $prevRevenue) / $prevRevenue * 100, 1)
        : null;

    $topChannel = null;
    foreach ($channels as $c) {
        if ($c['amount'] > 0 && ($topChannel === null || $c['amount'] > $topChannel['amount'])) {
            $topChannel = $c;
        }
    }

    // Orders and payments are two views of the same window and should agree.
    // A gap means something recorded a payment for a different amount than the
    // order total (store-credit orders do exactly this: the order is stored at
    // 0 after credit is applied while the payment row carries the credit
    // amount). Reported rather than hidden, and only called out in the UI when
    // it is big enough to matter.
    $gap = round($paymentsTotal - $ordersRevenue, 2);

    echo json_encode([
        'success'     => true,
        'range'       => $range,
        'start_date'  => $startSql,
        'end_date'    => $endSql,
        'day_count'   => $dayCount,
        'range_label' => report_range_label($start, $end),
        'totals'      => [
            'revenue'          => round($ordersRevenue, 2),
            'orders'           => $ordersCount,
            'aov'              => $ordersCount > 0 ? round($ordersRevenue / $ordersCount, 2) : 0.0,
            'cancelled_amount' => round($cancelledAmt, 2),
            'cancelled_orders' => $cancelledCount,
            'net_revenue'      => round($ordersRevenue - $cancelledAmt, 2),
        ],
        'channels'    => $channels,
        'settlement'  => $settlement,
        'reconciliation' => [
            'payments_total' => round($paymentsTotal, 2),
            'orders_total'   => round($ordersRevenue, 2),
            'gap'            => $gap,
            'payments_table' => $paymentsExist,
        ],
        'compare'     => [
            'label'     => report_range_label($prevStart, $prevEnd),
            'revenue'   => round($prevRevenue, 2),
            'orders'    => (int)$prev['orders'],
            'delta_pct' => $deltaPct,
        ],
        'insights'    => [
            'top_channel'       => $topChannel ? $topChannel['label'] : null,
            'top_channel_share' => $topChannel ? $topChannel['share'] : null,
            'settled_share'     => $settlement['settled']['share'],
        ],
        'generated_at' => date('c'),
    ]);
} catch (Throwable $e) {
    error_log('total_sales.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load total sales data']);
}
