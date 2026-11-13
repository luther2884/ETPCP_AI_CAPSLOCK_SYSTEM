<?php
// api/sales_report.php - Channel-split sales data (Online/E-Payment vs Cash on
// Delivery vs In-Store vs Store Credit) backing the admin Analytics "Sales
// Report" card.
//
// WHY THIS EXISTS
// The Sales Report card used to render from PHP data baked into the page at
// load time, so the only way to move it was a full page reload through the
// page's GET date filter - it had no range control of its own and never
// refreshed. This endpoint gives it the same live behaviour the Sales Overview
// chart already had (api/sales_overview.php), and the range vocabulary here is
// deliberately identical to that file's so the two cards cannot disagree about
// what "This Week" means.
//
// It also fixes a real reporting gap. The page's old inline query bucketed
// payment methods as ('online','gcash','paymaya') / 'cod' / ('store','onsite')
// and dropped everything else on the floor - which silently excluded every
// `xendit` order from the chart. Channel membership is now declared once, in
// SALES_REPORT_CHANNELS below, and anything not matched lands in "Other"
// rather than disappearing.
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/report_ranges.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (function_exists('getUserRole') ? getUserRole() : ($_SESSION['user_role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/**
 * Payment channels, in display order. `methods` are the raw
 * orders.payment_method values that belong to each; the labels match
 * getPaymentMethodLabel()'s own fallback wording so the chart legend reads the
 * same as the rest of the admin. 'other' is the catch-all and has no method
 * list - anything unrecognised (or NULL) is counted there, which is how a new
 * payment method added later shows up as a visible "Other" slice instead of
 * quietly vanishing from the report.
 */
const SALES_REPORT_CHANNELS = [
    'epayment' => [
        'label'   => 'Online / E-Payment',
        'color'   => '#06b6d4',
        'methods' => ['online', 'gcash', 'paymaya', 'xendit'],
    ],
    'cod' => [
        'label'   => 'Cash on Delivery',
        'color'   => '#10b981',
        'methods' => ['cod'],
    ],
    'instore' => [
        'label'   => 'In-Store Payment',
        'color'   => '#f59e0b',
        'methods' => ['store', 'onsite'],
    ],
    'credit' => [
        'label'   => 'Store Credit',
        'color'   => '#a78bfa',
        'methods' => ['store_credit'],
    ],
    'other' => [
        'label'   => 'Other',
        'color'   => '#94a3b8',
        'methods' => [],
    ],
];

/** Which channel key a raw orders.payment_method belongs to. */
function salesReportChannelFor(?string $paymentMethod): string
{
    $m = strtolower(trim((string)$paymentMethod));
    foreach (SALES_REPORT_CHANNELS as $key => $channel) {
        if (in_array($m, $channel['methods'], true)) {
            return $key;
        }
    }
    return 'other';
}

/**
 * Bucket width. A month-grouped bar is useless for "Today" or "This Week"
 * (it draws one bar), and a day-grouped bar is unreadable across a year - so
 * the granularity follows the span instead of being fixed at monthly the way
 * the old inline query was.
 */
function salesReportGranularity(int $dayCount): string
{
    if ($dayCount <= 31) {
        return 'day';
    }
    if ($dayCount <= 120) {
        return 'week';
    }
    return 'month';
}

/** SQL expression + PHP label format for a granularity. */
function salesReportBucketSql(string $granularity): string
{
    switch ($granularity) {
        case 'day':
            return "DATE_FORMAT(o.created_at, '%Y-%m-%d')";
        case 'week':
            // Monday-anchored, so the buckets line up with the week ranges above.
            return "DATE_FORMAT(DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY), '%Y-%m-%d')";
        case 'month':
        default:
            return "DATE_FORMAT(o.created_at, '%Y-%m')";
    }
}

/** Human label for a bucket key, e.g. "Oct 9" / "Sep 29 - Oct 5" / "Oct 2026". */
function salesReportBucketLabel(string $key, string $granularity): string
{
    try {
        if ($granularity === 'month') {
            return (new DateTimeImmutable($key . '-01'))->format('M Y');
        }
        $d = new DateTimeImmutable($key);
        if ($granularity === 'week') {
            return $d->format('M j') . ' - ' . $d->modify('+6 days')->format('M j');
        }
        return $d->format('M j');
    } catch (Throwable $e) {
        return $key;
    }
}

$range       = $_GET['range'] ?? 'last_6_months';
$customStart = $_GET['start_date'] ?? null;
$customEnd   = $_GET['end_date'] ?? null;

try {
    // Range names resolve in config/report_ranges.php, shared with the Total
    // Sales card, so the two cannot disagree about what "This Week" covers.
    [$start, $end] = report_resolve_range($range, null, $customStart, $customEnd);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid date supplied']);
    exit;
}

// Same guard as sales_overview.php, one span wider because this card is
// allowed to look at a whole year by the month.
$dayCount = (int)$start->diff($end)->days + 1;
if ($dayCount > 732) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Date range is too large (maximum 732 days).']);
    exit;
}

$granularity = salesReportGranularity($dayCount);
$bucketSql   = salesReportBucketSql($granularity);
$startSql    = $start->format('Y-m-d');
$endSql      = $end->format('Y-m-d');

try {
    $pdo = getConnection();

    // One pass over the range, split by bucket AND payment method. Grouping by
    // the raw method (rather than by a CASE per channel) keeps the channel
    // definitions in PHP where SALES_REPORT_CHANNELS can be the only place
    // they are written down.
    $stmt = $pdo->prepare("
        SELECT $bucketSql AS bucket,
               LOWER(COALESCE(o.payment_method, '')) AS method,
               SUM(o.total_amount) AS amount,
               COUNT(*) AS orders,
               SUM(CASE WHEN o.status = 'cancelled' THEN o.total_amount ELSE 0 END) AS cancelled_amount,
               COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) AS cancelled_orders
        FROM orders o
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY bucket, method
        ORDER BY bucket ASC
    ");
    $stmt->execute([$startSql, $endSql]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Units shipped in the range, for the "items sold" tile. Separate query
    // because joining order_items into the aggregate above would multiply
    // total_amount by the number of lines on each order.
    $itemsStmt = $pdo->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0) AS units,
               COUNT(DISTINCT oi.product_id) AS distinct_products
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
    ");
    $itemsStmt->execute([$startSql, $endSql]);
    $itemsRow = $itemsStmt->fetch(PDO::FETCH_ASSOC) ?: ['units' => 0, 'distinct_products' => 0];

    // --- fold the rows into buckets and channel totals ---------------------

    $channelKeys = array_keys(SALES_REPORT_CHANNELS);
    $blankChannels = array_fill_keys($channelKeys, 0.0);

    $buckets = [];           // bucket key => point
    $channelAmount = $blankChannels;
    $channelOrders = array_fill_keys($channelKeys, 0);
    $totalRevenue = 0.0;
    $totalOrders = 0;
    $cancelledAmount = 0.0;
    $cancelledOrders = 0;

    foreach ($rows as $row) {
        $key = (string)$row['bucket'];
        $channel = salesReportChannelFor($row['method']);
        $amount = (float)$row['amount'];
        $orders = (int)$row['orders'];

        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'key'       => $key,
                'label'     => salesReportBucketLabel($key, $granularity),
                'channels'  => $blankChannels,
                'total'     => 0.0,
                'orders'    => 0,
            ];
        }
        $buckets[$key]['channels'][$channel] += $amount;
        $buckets[$key]['total'] += $amount;
        $buckets[$key]['orders'] += $orders;

        $channelAmount[$channel] += $amount;
        $channelOrders[$channel] += $orders;
        $totalRevenue += $amount;
        $totalOrders += $orders;
        $cancelledAmount += (float)$row['cancelled_amount'];
        $cancelledOrders += (int)$row['cancelled_orders'];
    }

    // Fill the gaps, so an empty day/week/month draws a zero bar instead of
    // being skipped and compressing the time axis into a lie.
    $points = [];
    if ($granularity === 'month') {
        $cursor = new DateTimeImmutable($start->format('Y-m-01'));
        $limit  = new DateTimeImmutable($end->format('Y-m-01'));
        $step   = '+1 month';
        $fmt    = 'Y-m';
    } elseif ($granularity === 'week') {
        $cursor = $start->modify('monday this week');
        $limit  = $end->modify('monday this week');
        $step   = '+7 days';
        $fmt    = 'Y-m-d';
    } else {
        $cursor = $start;
        $limit  = $end;
        $step   = '+1 day';
        $fmt    = 'Y-m-d';
    }
    while ($cursor <= $limit) {
        $key = $cursor->format($fmt);
        $b = $buckets[$key] ?? [
            'key'      => $key,
            'label'    => salesReportBucketLabel($key, $granularity),
            'channels' => $blankChannels,
            'total'    => 0.0,
            'orders'   => 0,
        ];
        $b['channels'] = array_map(function ($v) { return round((float)$v, 2); }, $b['channels']);
        $b['total']    = round((float)$b['total'], 2);
        $points[] = $b;
        $cursor = $cursor->modify($step);
    }

    // --- channel table, biggest earner first -------------------------------

    $channels = [];
    foreach (SALES_REPORT_CHANNELS as $key => $meta) {
        $amount = round($channelAmount[$key], 2);
        // 'other' is noise when it is empty - drop it rather than print a 0%
        // row every single time. A real unmatched method makes it reappear.
        if ($key === 'other' && $amount <= 0 && $channelOrders[$key] === 0) {
            continue;
        }
        $channels[] = [
            'key'    => $key,
            'label'  => $meta['label'],
            'color'  => $meta['color'],
            'amount' => $amount,
            'orders' => $channelOrders[$key],
            'share'  => $totalRevenue > 0 ? round($amount / $totalRevenue * 100, 1) : 0.0,
            'aov'    => $channelOrders[$key] > 0 ? round($amount / $channelOrders[$key], 2) : 0.0,
        ];
    }
    $sortedChannels = $channels;
    usort($sortedChannels, function ($a, $b) { return $b['amount'] <=> $a['amount']; });

    // --- the immediately preceding window, for a like-for-like delta -------
    // Same number of days, ending the day before this range starts. This is
    // what makes the card answer "is this better than last time?" rather than
    // just "how much?".

    [$prevStart, $prevEnd] = report_previous_window($start, $dayCount);
    $prevStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) AS amount, COUNT(*) AS orders
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $prevStmt->execute([$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')]);
    $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['amount' => 0, 'orders' => 0];
    $prevRevenue = (float)$prev['amount'];
    $prevOrders  = (int)$prev['orders'];

    // No baseline means no percentage. Reporting "+100%" against zero is a
    // made-up number; the card says "no comparable sales" instead.
    $deltaPct = $prevRevenue > 0
        ? round(($totalRevenue - $prevRevenue) / $prevRevenue * 100, 1)
        : null;

    // --- best and quietest bucket in the range -----------------------------

    $best = null;
    $quietest = null;
    foreach ($points as $p) {
        if ($best === null || $p['total'] > $best['total']) { $best = $p; }
        if ($p['total'] > 0 && ($quietest === null || $p['total'] < $quietest['total'])) { $quietest = $p; }
    }

    $topChannel = !empty($sortedChannels) && $sortedChannels[0]['amount'] > 0 ? $sortedChannels[0] : null;

    echo json_encode([
        'success'     => true,
        'range'       => $range,
        'start_date'  => $startSql,
        'end_date'    => $endSql,
        'day_count'   => $dayCount,
        'granularity' => $granularity,
        'range_label' => report_range_label($start, $end),
        'points'      => $points,
        'channels'    => $channels,
        'totals'      => [
            'revenue'           => round($totalRevenue, 2),
            'orders'            => $totalOrders,
            'aov'               => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0.0,
            'units'             => (int)$itemsRow['units'],
            'distinct_products' => (int)$itemsRow['distinct_products'],
            'units_per_order'   => $totalOrders > 0 ? round((float)$itemsRow['units'] / $totalOrders, 2) : 0.0,
            // Gross includes cancelled orders, because the page's own Sales KPI
            // card does and two figures disagreeing on one screen reads as a
            // bug. Net is shown beside it so the gap is visible rather than
            // hidden inside one number.
            'cancelled_amount'  => round($cancelledAmount, 2),
            'cancelled_orders'  => $cancelledOrders,
            'cancelled_rate'    => $totalOrders > 0 ? round($cancelledOrders / $totalOrders * 100, 1) : 0.0,
            'net_revenue'       => round($totalRevenue - $cancelledAmount, 2),
        ],
        'compare'     => [
            'label'        => $prevStart->format('M j, Y') . ' - ' . $prevEnd->format('M j, Y'),
            'revenue'      => round($prevRevenue, 2),
            'orders'       => $prevOrders,
            'delta_pct'    => $deltaPct,
            'delta_amount' => round($totalRevenue - $prevRevenue, 2),
        ],
        'insights'    => [
            'top_channel'       => $topChannel ? $topChannel['label'] : null,
            'top_channel_share' => $topChannel ? $topChannel['share'] : null,
            'best_label'        => ($best && $best['total'] > 0) ? $best['label'] : null,
            'best_total'        => ($best && $best['total'] > 0) ? $best['total'] : null,
            'quietest_label'    => $quietest ? $quietest['label'] : null,
            'quietest_total'    => $quietest ? $quietest['total'] : null,
        ],
        'generated_at' => date('c'),
    ]);
} catch (Throwable $e) {
    error_log('sales_report.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load sales report data']);
}
