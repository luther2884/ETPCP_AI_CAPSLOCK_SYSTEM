<?php
// api/sales_overview.php - Day-by-day sales data (This Year vs Last Year)
// backing the admin Reports & Analytics "Sales Overview Chart". Reuses the
// same orders.total_amount figure as the page's own "Sales" KPI card so the
// chart never disagrees with the rest of the dashboard.
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (function_exists('getUserRole') ? getUserRole() : ($_SESSION['user_role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/**
 * "Same calendar date, one year earlier" - clamps Feb 29 to Feb 28 when the
 * prior year isn't a leap year, instead of letting DateTime's day-preserving
 * `-1 year` arithmetic silently roll it over to March 1.
 */
function salesOverviewSameDateLastYear(DateTimeImmutable $d): DateTimeImmutable {
    $year = (int)$d->format('Y') - 1;
    $month = (int)$d->format('m');
    $day = (int)$d->format('d');
    $lastDayOfMonth = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $day = min($day, $lastDayOfMonth);
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/**
 * Resolves a named range (or a custom single date / custom date range) into
 * a [startDate, endDate] DateTimeImmutable pair, anchored on "today" so the
 * app never hardcodes a date and naturally rolls over at midnight.
 */
function salesOverviewResolveRange(string $range, ?string $customDate, ?string $customStart, ?string $customEnd): array {
    $today = new DateTimeImmutable('today');
    switch ($range) {
        case 'yesterday':
            $d = $today->modify('-1 day');
            return [$d, $d];
        case 'this_week': // Monday - Sunday
            $start = $today->modify('monday this week');
            return [$start, $start->modify('+6 days')];
        case 'last_week':
            $start = $today->modify('monday this week')->modify('-7 days');
            return [$start, $start->modify('+6 days')];
        case 'this_month':
            return [$today->modify('first day of this month'), $today->modify('last day of this month')];
        case 'last_month':
            return [$today->modify('first day of last month'), $today->modify('last day of last month')];
        case 'custom_date':
            $d = $customDate ? new DateTimeImmutable($customDate) : $today;
            return [$d, $d];
        case 'custom_range':
            $start = $customStart ? new DateTimeImmutable($customStart) : $today;
            $end = $customEnd ? new DateTimeImmutable($customEnd) : $today;
            if ($end < $start) { [$start, $end] = [$end, $start]; }
            return [$start, $end];
        case 'today':
        default:
            return [$today, $today];
    }
}

$range = $_GET['range'] ?? 'today';
$customDate = $_GET['date'] ?? null;
$customStart = $_GET['start_date'] ?? null;
$customEnd = $_GET['end_date'] ?? null;

try {
    [$start, $end] = salesOverviewResolveRange($range, $customDate, $customStart, $customEnd);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid date supplied']);
    exit;
}

// Hard cap so a mistyped huge custom range can't hammer the DB or balloon the payload.
$dayCount = (int)$start->diff($end)->days + 1;
if ($dayCount > 366) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Date range is too large (maximum 366 days).']);
    exit;
}

try {
    $pdo = getConnection();

    $lastYearStart = salesOverviewSameDateLastYear($start);
    $lastYearEnd = salesOverviewSameDateLastYear($end);

    $stmt = $pdo->prepare("
        SELECT DATE(created_at) AS d, SUM(total_amount) AS total
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
    ");

    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $thisYearByDate = array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    $stmt->execute([$lastYearStart->format('Y-m-d'), $lastYearEnd->format('Y-m-d')]);
    $lastYearRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Index by "MM-DD" (not the full last-year date) so each this-year day is
    // matched to its own same-calendar-date counterpart individually, rather
    // than by position - keeps the comparison correct even around the Feb 29
    // clamp above, where the two ranges can end up different lengths.
    $lastYearByMonthDay = [];
    foreach ($lastYearRows as $row) {
        $lastYearByMonthDay[substr($row['d'], 5)] = (float)$row['total'];
    }

    $points = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $lastYearDate = salesOverviewSameDateLastYear($cursor);
        $points[] = [
            'date' => $cursor->format('Y-m-d'),
            'day' => $cursor->format('l'),
            'label' => $cursor->format('M j'),
            'this_year' => round($thisYearByDate[$cursor->format('Y-m-d')] ?? 0, 2),
            'last_year' => round($lastYearByMonthDay[$cursor->format('m-d')] ?? 0, 2),
            'last_year_date' => $lastYearDate->format('Y-m-d'),
        ];
        $cursor = $cursor->modify('+1 day');
    }

    $totalThisYear = array_sum(array_column($points, 'this_year'));
    $totalLastYear = array_sum(array_column($points, 'last_year'));

    echo json_encode([
        'success' => true,
        'range' => $range,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'range_label' => $dayCount === 1
            ? $start->format('F j, Y')
            : ($start->format('M j') . ' – ' . $end->format('M j, Y')),
        'points' => $points,
        'total_this_year' => round($totalThisYear, 2),
        'total_last_year' => round($totalLastYear, 2),
    ]);
} catch (Throwable $e) {
    error_log('sales_overview.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load sales data']);
}
