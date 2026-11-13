<?php
// api/sales_breakdown.php - Revenue by product category, as a whole-period
// split and as a daily series, backing the admin Analytics "Sales Breakdown"
// card (donut + stacked bars + legend).
//
// WHY THIS EXISTS
// The card rendered from PHP inlined into the page, so it never refreshed
// without a full reload. Moving it here also fixes two things that made it
// actively misleading:
//
// 1. THE TWO HALVES DISAGREED. The donut counted only `status = 'completed'`
//    while the stacked bars counted
//    ('preparing','packed','ready','shipped','completed'). On current data
//    that is PHP 677,466 against PHP 1,531,585 - the same card showing two
//    numbers 2.3x apart for what reads as the same question. Both halves now
//    use ONE filter, declared once below.
//
// 2. ZERO-SALES CATEGORIES WERE DRAWN AS REAL SLICES. The page inflated every
//    empty category to `max(maxSales * 0.01, 1000)` and every small one to
//    twice that, then handed those invented numbers to Chart.js as slice
//    sizes. Tooltips showed the true amount, but the picture - which is the
//    whole point of a donut - was false, and the slices did not add up to the
//    total printed in the middle of it. This endpoint returns real amounts
//    only; the card lists empty categories in the legend instead of drawing
//    them.

require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/report_ranges.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (function_exists('getUserRole') ? getUserRole() : ($_SESSION['user_role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/**
 * Which orders count as sales here.
 *
 * Cancelled orders are excluded and everything else is included - an order
 * being picked, packed or already delivered is revenue either way. This is
 * the one definition both the donut and the bars use, and it matches the
 * "net of cancellations" figure the Sales Report and Total Sales cards show,
 * so the three cards on this screen agree.
 */
const BREAKDOWN_EXCLUDED_STATUS = 'cancelled';

/*
 * A NOTE ON WHY THIS CARD'S TOTAL IS SMALLER THAN THE OTHER CARDS'.
 *
 * This card sums `order_items.total` - the value of the goods. The Sales
 * Report and Total Sales cards sum `orders.total_amount`, which is what the
 * customer was actually charged and therefore includes the 12% VAT added at
 * checkout. On current data that is PHP 1,438,370 here against PHP 1,610,973
 * there, which is exactly the VAT.
 *
 * Both are right; they answer different questions, and a category breakdown
 * can only be built from line items. The `basis` field below says so on the
 * card so the two figures do not read as a contradiction.
 */

/**
 * Category colours. Same palette the page has always used, moved here so the
 * donut, the bars and the legend read from one list instead of three copies
 * (two PHP, one JS) that could drift apart.
 */
const BREAKDOWN_CATEGORY_COLORS = [
    'Processors'         => '#22c55e',
    'Desktops'           => '#3b82f6',
    'Graphics Cards'     => '#f59e0b',
    'Headsets'           => '#8b5cf6',
    'Speakers'           => '#06b6d4',
    'Keyboards & Combos' => '#ef4444',
    'Laptop Stands'      => '#10b981',
    'Laptops'            => '#f97316',
    'Memory'             => '#84cc16',
    'Motherboards'       => '#ec4899',
    'Power Supply'       => '#6366f1',
    'Storage'            => '#14b8a6',
];

const BREAKDOWN_FALLBACK_COLORS = [
    '#fbbf24', '#8b5cf6', '#06b6d4', '#ef4444', '#22c55e', '#3b82f6',
    '#f59e0b', '#a78bfa', '#10b981', '#f97316', '#84cc16', '#ec4899',
    '#6366f1', '#14b8a6',
];

/** Stable colour for any category name, known or new. */
function breakdownCategoryColor(string $name): string
{
    if (isset(BREAKDOWN_CATEGORY_COLORS[$name])) {
        return BREAKDOWN_CATEGORY_COLORS[$name];
    }
    // crc32 keeps a new category on the same colour between page loads, which
    // a round-robin index would not once another category is added above it.
    return BREAKDOWN_FALLBACK_COLORS[abs(crc32($name)) % count(BREAKDOWN_FALLBACK_COLORS)];
}

/** Daily for short ranges, weekly then monthly as the span grows. */
function breakdownGranularity(int $dayCount): string
{
    if ($dayCount <= 31)  { return 'day'; }
    if ($dayCount <= 120) { return 'week'; }
    return 'month';
}

function breakdownBucketSql(string $granularity): string
{
    switch ($granularity) {
        case 'day':
            return "DATE_FORMAT(o.created_at, '%Y-%m-%d')";
        case 'week':
            return "DATE_FORMAT(DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY), '%Y-%m-%d')";
        case 'month':
        default:
            return "DATE_FORMAT(o.created_at, '%Y-%m')";
    }
}

function breakdownBucketLabel(string $key, string $granularity): string
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

$granularity = breakdownGranularity($dayCount);
$bucketSql   = breakdownBucketSql($granularity);
$startSql    = $start->format('Y-m-d');
$endSql      = $end->format('Y-m-d');

try {
    $pdo = getConnection();

    // Every category, including ones that sold nothing - the legend has always
    // listed them all and that is genuinely useful (an empty category is a
    // question worth asking). They are returned with amount 0 and simply are
    // not drawn as slices.
    $catStmt = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC");
    $allCategories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Whole-period totals per category. LEFT JOIN from categories so a
    // category with no matching sales still comes back, and the date/status
    // filter lives in the ON clause - in the WHERE it would turn the LEFT
    // JOIN back into an inner one and silently drop those categories.
    //
    // The CASE is not optional. With the filter in the ON clause, an
    // order_item whose order falls OUTSIDE the range still produces a row -
    // `oi` matched, only `o` came back NULL - so a bare SUM(oi.total) quietly
    // adds the category's entire out-of-range history to the range's total.
    // Caught by cross-checking these totals against the time series below,
    // which disagreed by PHP 577,585 until the CASE went in. Guarding on
    // `o.id IS NOT NULL` counts only the rows that actually matched.
    $totalsStmt = $pdo->prepare("
        SELECT c.id   AS category_id,
               c.name AS category_name,
               COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.total    ELSE 0 END), 0) AS revenue,
               COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.quantity ELSE 0 END), 0) AS units,
               COUNT(DISTINCT o.id)                                                     AS orders
        FROM categories c
        LEFT JOIN products p    ON p.category_id = c.id
        LEFT JOIN order_items oi ON oi.product_id = p.id
        LEFT JOIN orders o       ON o.id = oi.order_id
                                AND DATE(o.created_at) BETWEEN ? AND ?
                                AND o.status <> ?
        GROUP BY c.id, c.name
    ");
    $totalsStmt->execute([$startSql, $endSql, BREAKDOWN_EXCLUDED_STATUS]);
    $totalRows = $totalsStmt->fetchAll(PDO::FETCH_ASSOC);

    // The time series. INNER joins here: a bucket only exists if something
    // sold in it, and the gaps are filled in PHP below so an empty day draws
    // a zero column rather than being skipped (which would compress the time
    // axis into a lie).
    $seriesStmt = $pdo->prepare("
        SELECT $bucketSql AS bucket,
               c.id AS category_id,
               COALESCE(SUM(oi.total), 0) AS revenue
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN products p     ON p.id = oi.product_id
        JOIN categories c   ON c.id = p.category_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
          AND o.status <> ?
        GROUP BY bucket, c.id
        ORDER BY bucket ASC
    ");
    $seriesStmt->execute([$startSql, $endSql, BREAKDOWN_EXCLUDED_STATUS]);
    $seriesRows = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- categories ---------------------------------------------------------

    $byId = [];
    foreach ($totalRows as $r) {
        $byId[(int)$r['category_id']] = $r;
    }

    $categories = [];
    $grandTotal = 0.0;
    foreach ($allCategories as $cat) {
        $id  = (int)$cat['id'];
        $row = $byId[$id] ?? ['revenue' => 0, 'units' => 0, 'orders' => 0];
        $revenue = round((float)$row['revenue'], 2);
        $grandTotal += $revenue;
        $categories[] = [
            'id'      => $id,
            'label'   => $cat['name'],
            'color'   => breakdownCategoryColor($cat['name']),
            'amount'  => $revenue,
            'units'   => (int)$row['units'],
            'orders'  => (int)$row['orders'],
        ];
    }

    // Biggest first, ties alphabetical - the order the legend and the donut
    // both read in.
    usort($categories, function ($a, $b) {
        if ($a['amount'] === $b['amount']) {
            return strcmp($a['label'], $b['label']);
        }
        return $b['amount'] <=> $a['amount'];
    });

    foreach ($categories as &$c) {
        $c['share'] = $grandTotal > 0 ? round($c['amount'] / $grandTotal * 100, 1) : 0.0;
    }
    unset($c);

    $selling = array_values(array_filter($categories, function ($c) { return $c['amount'] > 0; }));
    $empty   = array_values(array_filter($categories, function ($c) { return $c['amount'] <= 0; }));

    // --- series -------------------------------------------------------------

    $seriesByBucket = [];
    foreach ($seriesRows as $r) {
        $seriesByBucket[(string)$r['bucket']][(int)$r['category_id']] = round((float)$r['revenue'], 2);
    }

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

    $points = [];
    while ($cursor <= $limit) {
        $key = $cursor->format($fmt);
        $vals = $seriesByBucket[$key] ?? [];
        $points[] = [
            'key'        => $key,
            'label'      => breakdownBucketLabel($key, $granularity),
            'categories' => $vals,                 // category id => amount, sparse
            'total'      => round(array_sum($vals), 2),
        ];
        $cursor = $cursor->modify($step);
    }

    // --- comparison + insight numbers ---------------------------------------

    [$prevStart, $prevEnd] = report_previous_window($start, $dayCount);
    $prevStmt = $pdo->prepare("
        SELECT COALESCE(SUM(oi.total), 0) AS revenue
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status <> ?
    ");
    $prevStmt->execute([$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d'), BREAKDOWN_EXCLUDED_STATUS]);
    $prevRevenue = (float)($prevStmt->fetchColumn() ?: 0);
    $deltaPct = $prevRevenue > 0 ? round(($grandTotal - $prevRevenue) / $prevRevenue * 100, 1) : null;

    $top = $selling[0] ?? null;
    // How concentrated is the money? Three categories carrying nearly all of
    // it is a very different business from twenty carrying it evenly, and the
    // donut alone does not say which this is.
    $top3 = 0.0;
    foreach (array_slice($selling, 0, 3) as $c) {
        $top3 += $c['amount'];
    }

    $busiest = null;
    foreach ($points as $p) {
        if ($p['total'] > 0 && ($busiest === null || $p['total'] > $busiest['total'])) {
            $busiest = $p;
        }
    }

    echo json_encode([
        'success'      => true,
        'range'        => $range,
        'start_date'   => $startSql,
        'end_date'     => $endSql,
        'day_count'    => $dayCount,
        'granularity'  => $granularity,
        'range_label'  => report_range_label($start, $end),
        'basis'        => 'Item values before VAT, cancelled orders excluded',
        'categories'   => $categories,
        'selling'      => array_column($selling, 'id'),
        'empty_labels' => array_column($empty, 'label'),
        'points'       => $points,
        'totals'       => [
            'revenue'          => round($grandTotal, 2),
            'units'            => array_sum(array_column($categories, 'units')),
            'categories'       => count($categories),
            'selling'          => count($selling),
            'empty'            => count($empty),
            'top3_share'       => $grandTotal > 0 ? round($top3 / $grandTotal * 100, 1) : 0.0,
        ],
        'compare'      => [
            'label'     => report_range_label($prevStart, $prevEnd),
            'revenue'   => round($prevRevenue, 2),
            'delta_pct' => $deltaPct,
        ],
        'insights'     => [
            'top_label'     => $top ? $top['label'] : null,
            'top_amount'    => $top ? $top['amount'] : null,
            'top_share'     => $top ? $top['share'] : null,
            'busiest_label' => $busiest ? $busiest['label'] : null,
            'busiest_total' => $busiest ? $busiest['total'] : null,
        ],
        'generated_at' => date('c'),
    ]);
} catch (Throwable $e) {
    error_log('sales_breakdown.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load sales breakdown data']);
}
