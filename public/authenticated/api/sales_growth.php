<?php
// api/sales_growth.php - Year-on-year and month-on-month growth, backing the
// admin Analytics "Sales Growth Rate" card.
//
// SCOPE IS DELIBERATELY FIXED, NOT FILTERED
// This card compares calendar periods - this year to date against the same
// months last year, and the current month against the previous one. It does
// NOT follow the page's date range, and that is on purpose: "growth" against
// an arbitrary picked window is not a meaningful number. The card says so in
// its own copy. What it was missing is simply being live.
//
// WHAT WAS WRONG WITH THE NUMBERS
// The page computed every growth figure as:
//     lastYear > 0 ? ((thisYear - lastYear) / lastYear) * 100 : (thisYear > 0 ? 100 : 0)
// That `: 100` is the problem. With no prior-year data at all - which is the
// case here, every order in the database is from 2026 - the headline read
// "+100%" and the trend line sat flat at 100% for every month. Both were
// invented. A percentage needs a non-zero baseline; without one this endpoint
// returns null and the card says "no comparable period" in words.
//
// The monthly series is also returned as actual revenue, not only as a growth
// percentage, so the card can draw something real when there is nothing to
// compare against.

require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (function_exists('getUserRole') ? getUserRole() : ($_SESSION['user_role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/**
 * Percentage change, or null when there is no baseline to measure against.
 * Never fabricates 100%.
 */
function growthPct(float $current, float $previous): ?float
{
    if ($previous <= 0) {
        return null;
    }
    return round(($current - $previous) / $previous * 100, 1);
}

try {
    $pdo = getConnection();

    $thisYear    = (int)date('Y');
    $lastYear    = $thisYear - 1;
    $currentMonth = (int)date('n');
    $monthNames  = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    // Cancelled orders are excluded: a cancelled sale is not growth. This
    // matches what the page's own query already did.
    $stmt = $pdo->prepare("
        SELECT YEAR(created_at) AS yr, MONTH(created_at) AS mo,
               SUM(total_amount) AS total, COUNT(*) AS orders
        FROM orders
        WHERE status <> 'cancelled' AND YEAR(created_at) IN (?, ?)
        GROUP BY YEAR(created_at), MONTH(created_at)
    ");
    $stmt->execute([$thisYear, $lastYear]);

    $totals = [];
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $totals[(int)$r['yr']][(int)$r['mo']] = (float)$r['total'];
        $counts[(int)$r['yr']][(int)$r['mo']] = (int)$r['orders'];
    }

    // --- monthly series, Jan through the current real month -----------------
    // Not the full twelve: months that have not happened yet would draw as a
    // 100% collapse to zero.

    $months = [];
    $thisYtd = 0.0;
    $lastYtd = 0.0;
    $ordersYtd = 0;
    for ($m = 1; $m <= $currentMonth; $m++) {
        $cur  = $totals[$thisYear][$m] ?? 0.0;
        $prev = $totals[$lastYear][$m] ?? 0.0;
        $thisYtd += $cur;
        $lastYtd += $prev;
        $ordersYtd += $counts[$thisYear][$m] ?? 0;
        $months[] = [
            'month'      => $monthNames[$m - 1],
            'short'      => substr($monthNames[$m - 1], 0, 3),
            'this_year'  => round($cur, 2),
            'last_year'  => round($prev, 2),
            'orders'     => $counts[$thisYear][$m] ?? 0,
            'growth_pct' => growthPct($cur, $prev),
        ];
    }

    // --- headline figures ---------------------------------------------------

    $yoyPct = growthPct($thisYtd, $lastYtd);

    $currentMonthTotal = $totals[$thisYear][$currentMonth] ?? 0.0;
    if ($currentMonth > 1) {
        $previousMonthTotal = $totals[$thisYear][$currentMonth - 1] ?? 0.0;
        $previousMonthLabel = $monthNames[$currentMonth - 2];
    } else {
        // January compares to December of last year, not to "month zero".
        $previousMonthTotal = $totals[$lastYear][12] ?? 0.0;
        $previousMonthLabel = 'December ' . $lastYear;
    }
    $momPct = growthPct($currentMonthTotal, $previousMonthTotal);

    // --- a little extra context ---------------------------------------------

    $best = null;
    $worst = null;
    foreach ($months as $m) {
        if ($m['this_year'] > 0 && ($best === null || $m['this_year'] > $best['this_year']))  { $best = $m; }
        if ($m['this_year'] > 0 && ($worst === null || $m['this_year'] < $worst['this_year'])) { $worst = $m; }
    }

    // Months with any sales, so an average is per trading month rather than
    // being dragged down by months the shop had not opened yet.
    $activeMonths = count(array_filter($months, function ($m) { return $m['this_year'] > 0; }));
    $avgMonth = $activeMonths > 0 ? round($thisYtd / $activeMonths, 2) : 0.0;

    // Simple run-rate: this year's monthly average projected over 12 months.
    // Flagged as an estimate in the UI; it is arithmetic, not a forecast.
    $runRate = $activeMonths > 0 ? round($avgMonth * 12, 2) : 0.0;

    echo json_encode([
        'success'  => true,
        'this_year' => $thisYear,
        'last_year' => $lastYear,
        'through'   => $monthNames[$currentMonth - 1] . ' ' . $thisYear,
        'months'    => $months,
        'headline'  => [
            'yoy_pct'          => $yoyPct,
            'ytd_this_year'    => round($thisYtd, 2),
            'ytd_last_year'    => round($lastYtd, 2),
            'mom_pct'          => $momPct,
            'current_month'    => $monthNames[$currentMonth - 1],
            'current_total'    => round($currentMonthTotal, 2),
            'previous_month'   => $previousMonthLabel,
            'previous_total'   => round($previousMonthTotal, 2),
            'has_baseline'     => $lastYtd > 0,
        ],
        'context'   => [
            'orders_ytd'    => $ordersYtd,
            'active_months' => $activeMonths,
            'avg_month'     => $avgMonth,
            'run_rate'      => $runRate,
            'best_month'    => $best ? $best['month'] : null,
            'best_total'    => $best ? $best['this_year'] : null,
            'worst_month'   => $worst ? $worst['month'] : null,
            'worst_total'   => $worst ? $worst['this_year'] : null,
        ],
        'generated_at' => date('c'),
    ]);
} catch (Throwable $e) {
    error_log('sales_growth.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load growth data']);
}
