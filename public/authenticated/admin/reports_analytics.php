<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
// Company block on the printed inventory report - same single source the
// delivery notes read from.
require_once __DIR__ . '/../../../config/business_info.php';
require_role(['admin']);

$isAdminTheme = true;
$activeNav = 'reports';
// Determine which sub-view to show: sales, inventory, analytics
$view = $_GET['view'] ?? 'sales';

/* What this screen is called, per view.
   ?view=analytics is reached from the sidebar's own "Analytics" item (see
   includes/navigation.php, which already resolves this page to the 'analytics'
   nav entry when view=analytics) - so calling the screen itself "Reports &
   Analytics" there made the breadcrumb disagree with the link that got you
   here. The sales and inventory views keep the original wording; they are
   reached from the "Reports" chooser. One value feeds both the browser tab
   title and the breadcrumb so the two can never drift apart. */
$screenLabel = ($view === 'analytics') ? 'Analytics' : 'Reports & Analytics';
$pageTitle = $screenLabel . ' - TechWorld Admin';

// Get date range for reports - Use all-time data by default for accurate sales breakdown
$startDate = $_GET['start_date'] ?? '2020-01-01'; // Start from beginning to show all sales
$endDate = $_GET['end_date'] ?? date('Y-m-d'); // Today

/* Did the admin actually pick a range, or is this the all-time default?
   The difference matters further down: the Sales Overview chart has a range
   control of its own, and when the page filter has been used it should follow
   it rather than sit on "This Month" showing a different period to every other
   card on the screen. */
$hasExplicitRange = isset($_GET['start_date']) || isset($_GET['end_date']);

try {
    $pdo = getConnection();

    // Distinct order dates over a wide window, for the date-range picker's
    // "has data" dot indicator under calendar days (same picker/query
    // pattern as sales_report.php).
    $activityDates = [];
    try {
        $actStmt = $pdo->prepare("SELECT DISTINCT DATE(created_at) as d FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 YEAR)");
        $actStmt->execute();
        $activityDates = array_column($actStmt->fetchAll(), 'd');
    } catch (PDOException $e) {
        error_log("Reports activity-dates query error: " . $e->getMessage());
    }

    if ($view !== 'inventory') {
        // KPI Data
        $salesStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_order_value,
            COUNT(CASE WHEN status = 'delivered' THEN 1 END) as completed_orders,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as total_returns
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?
        ");
        $salesStmt->execute([$startDate, $endDate]);
        $salesData = $salesStmt->fetch();

        // Sales Figure data (monthly trends)
        $salesFigureStmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(created_at, '%Y-%m') as month,
            SUM(total_amount) as marketing_sales,
            COUNT(*) as cases_sales
        FROM orders
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
        ");
        $salesFigureStmt->execute();
        $salesFigureData = $salesFigureStmt->fetchAll();

        /* The Sales Report card's data used to be fetched here and inlined
           into the page as JSON, which is precisely why that card never
           refreshed: its numbers were fixed at page-render time and only a
           full reload could move them. It now loads from
           public/authenticated/api/sales_report.php on demand, so the query
           that used to live here has gone with it rather than running on
           every page load for a variable nothing reads.

           The channel buckets moved too, and were wrong here: the old CASE
           list covered ('online','gcash','paymaya') / 'cod' / ('store',
           'onsite') and silently dropped every `xendit` order - a real and
           sizeable chunk of revenue that simply never reached the chart.
           SALES_REPORT_CHANNELS in the API is now the single place channel
           membership is declared, and anything unmatched lands in "Other"
           instead of disappearing. */

        // Best Selling Products - Top 5
        $bestProductStmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.price,
            p.stock,
            p.image_url,
            SUM(oi.quantity) as total_sold,
            SUM(oi.quantity * oi.price) as total_revenue
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY p.id, p.name, p.price, p.stock, p.image_url
        ORDER BY total_sold DESC
        LIMIT 5
        ");
        $bestProductStmt->execute([$startDate, $endDate]);
        $bestProducts = $bestProductStmt->fetchAll();
        $bestProduct = $bestProducts[0] ?? null; // kept for any other code still reading the single top product

        // Worst Selling Products - Bottom 5 (LEFT JOIN so never-sold products show up too,
        // which is the whole point of a "worst sellers" list - an INNER JOIN like bestProductStmt
        // would silently hide them).
        $worstProductStmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.price,
            p.stock,
            p.image_url,
            COALESCE(SUM(CASE WHEN DATE(o.created_at) BETWEEN ? AND ? THEN oi.quantity ELSE 0 END), 0) as total_sold,
            COALESCE(SUM(CASE WHEN DATE(o.created_at) BETWEEN ? AND ? THEN oi.quantity * oi.price ELSE 0 END), 0) as total_revenue
        FROM products p
        LEFT JOIN order_items oi ON oi.product_id = p.id
        LEFT JOIN orders o ON oi.order_id = o.id
        WHERE p.status = 'active'
        GROUP BY p.id, p.name, p.price, p.stock, p.image_url
        ORDER BY total_sold ASC, total_revenue ASC, p.name ASC
        LIMIT 5
        ");
        $worstProductStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $worstProducts = $worstProductStmt->fetchAll();

        /* ---- Product Performance trend: the ten products above, over time ----
         *
         * CUMULATIVE units, not units-per-bucket. Per-bucket was the obvious
         * first choice and it is unreadable on this data: a best seller moves a
         * handful of units on a handful of days, so every line is a flat zero
         * with occasional single-unit spikes, and ten of those overlaid is
         * noise. Cumulative turns each product into a rising staircase whose
         * SLOPE is its sales rate - which is exactly the comparison this card
         * is for, and it degrades gracefully to a flat line at zero for a
         * product that never sold.
         *
         * ADAPTIVE BUCKET. The default filter range is 2020-01-01 to today, so
         * daily buckets would be ~2,400 points for ~6 weeks of actual orders.
         * The window is clamped to the real order dates inside the filter and
         * then bucketed to keep the point count sane whatever the range:
         *
         *   <= 92 days   daily
         *   <= 730 days  weekly
         *   else         monthly
         *
         * Everything is derived from the SAME $startDate/$endDate the lists
         * above use, so the chart and the two lists can never disagree about
         * which period they describe. The filter is a GET form (a page reload),
         * which is why this is inlined rather than fetched - see the note on
         * the category breakdown below for the card that genuinely needed an
         * API.
         */
        $perfSeries = ['labels' => [], 'best' => [], 'worst' => [], 'bucket' => 'day', 'from' => null, 'to' => null, 'clamped' => false];

        $perfIds = [];
        foreach ($bestProducts as $p)  { $perfIds[(int)$p['id']] = 'best'; }
        foreach ($worstProducts as $p) { if (!isset($perfIds[(int)$p['id']])) { $perfIds[(int)$p['id']] = 'worst'; } }

        if ($perfIds) {
            // The real extent of orders inside the filter. NULL when the range
            // holds no orders at all, in which case there is nothing to plot.
            $spanStmt = $pdo->prepare(
                "SELECT MIN(DATE(created_at)) AS d0, MAX(DATE(created_at)) AS d1
                   FROM orders WHERE DATE(created_at) BETWEEN ? AND ?"
            );
            $spanStmt->execute([$startDate, $endDate]);
            $span = $spanStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if (!empty($span['d0']) && !empty($span['d1'])) {
                $d0 = new DateTimeImmutable((string)$span['d0']);
                $d1 = new DateTimeImmutable((string)$span['d1']);
                $days = (int)$d0->diff($d1)->days;

                if ($days <= 92)       { $bucket = 'day';   $sqlFmt = '%Y-%m-%d'; }
                elseif ($days <= 730)  { $bucket = 'week';  $sqlFmt = '%x-W%v';   }
                else                   { $bucket = 'month'; $sqlFmt = '%Y-%m';    }
                $perfSeries['bucket'] = $bucket;
                // The window actually PLOTTED, which is the filter range
                // clamped to the days that hold orders. The header prints
                // this rather than $startDate/$endDate: on the default
                // filter those read "1 Jan 2020 - 15 Oct 2026" above a
                // chart covering six weeks, which looks like a broken axis.
                $perfSeries['from'] = $d0->format('Y-m-d');
                $perfSeries['to']   = $d1->format('Y-m-d');
                $perfSeries['clamped'] = ($d0->format('Y-m-d') !== $startDate)
                                      || ($d1->format('Y-m-d') !== $endDate);

                // Every bucket in the window, including ones with no orders -
                // a gap left out would make the line jump across dead time and
                // misrepresent the slope.
                $labels = [];
                $step = $bucket === 'day' ? '+1 day' : ($bucket === 'week' ? '+1 week' : '+1 month');
                $cursor = $bucket === 'month' ? $d0->modify('first day of this month') : $d0;
                while ($cursor <= $d1) {
                    $labels[] = $bucket === 'day'
                        ? $cursor->format('Y-m-d')
                        : ($bucket === 'week' ? $cursor->format('o-\WW') : $cursor->format('Y-m'));
                    $cursor = $cursor->modify($step);
                }
                // A single bucket is a dot, not a line. Widen to the day level
                // so one busy day still draws something with shape.
                if (count($labels) < 2 && $bucket !== 'day') { $labels = [$d0->format('Y-m-d'), $d1->format('Y-m-d')]; }
                $perfSeries['labels'] = $labels;
                $labelIndex = array_flip($labels);

                $ph = implode(',', array_fill(0, count($perfIds), '?'));
                $qtyStmt = $pdo->prepare(
                    "SELECT oi.product_id AS pid,
                            DATE_FORMAT(o.created_at, '{$sqlFmt}') AS bucket,
                            SUM(oi.quantity) AS qty
                       FROM order_items oi
                       JOIN orders o ON o.id = oi.order_id
                      WHERE oi.product_id IN ($ph)
                        AND DATE(o.created_at) BETWEEN ? AND ?
                      GROUP BY oi.product_id, bucket"
                );
                $qtyStmt->execute(array_merge(array_keys($perfIds), [$startDate, $endDate]));

                $byProduct = [];
                foreach ($qtyStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byProduct[(int)$r['pid']][(string)$r['bucket']] = (int)$r['qty'];
                }

                /** Running total across every label, so the line never dips. */
                $accumulate = static function (array $buckets) use ($labels, $labelIndex): array {
                    $out = [];
                    $run = 0;
                    foreach ($labels as $lb) {
                        $run += $buckets[$lb] ?? 0;
                        $out[] = $run;
                    }
                    unset($labelIndex);
                    return $out;
                };

                foreach (['best' => $bestProducts, 'worst' => $worstProducts] as $group => $rows) {
                    foreach ($rows as $p) {
                        $pid = (int)$p['id'];
                        // A product appearing in both lists (possible when the
                        // range has almost no sales) is charted once, in the
                        // group that claimed it first.
                        if (($perfIds[$pid] ?? null) !== $group) { continue; }
                        $perfSeries[$group][] = [
                            'id'    => $pid,
                            'name'  => (string)$p['name'],
                            'total' => (int)($p['total_sold'] ?? 0),
                            'data'  => $accumulate($byProduct[$pid] ?? []),
                        ];
                    }
                }
            }
        }

        /* The category breakdown (donut, legend and stacked bars) used to be
           queried here and inlined into the page, which is why that card never
           refreshed. It now loads from public/authenticated/api/sales_breakdown.php.

           Two things went with it rather than being carried over:

           - The donut query filtered `o.status = 'completed'` while the
             stacked-series query filtered
             IN ('preparing','packed','ready','shipped','completed'), so the
             two halves of one card answered different questions - PHP 677,466
             against PHP 1,531,585 on this data. The API declares the filter
             once (cancelled excluded) and both halves read it.

           - A long run of error_log() debug probes (sample order_items, a
             "Speakers category" check, a list of every category name) ran on
             every single page load and wrote to the PHP error log. They were
             diagnostics for a bug that is fixed; they are not reporting.

           The `UPDATE order_items SET total = price * quantity WHERE total = 0`
           repair that sat among them is KEPT below, deliberately: the
           breakdown sums `order_items.total`, so a row left at 0 would be
           silently missing revenue. It currently matches no rows. */

        // Backfill any order_items row whose stored total was never computed.
        // Idempotent, and cheap when there is nothing to fix (indexed scan,
        // 0 rows matched today). The breakdown API sums this column, so a 0
        // here would under-report a category's revenue.
        $fixOrderItemsStmt = $pdo->prepare("UPDATE order_items SET total = price * quantity WHERE total = 0.00");
        $fixOrderItemsStmt->execute();

        /* The Total Sales card's payment-channel split used to be fetched here
           and inlined into the page, which is why that card never refreshed
           either. It now loads from public/authenticated/api/total_sales.php,
           so the query has moved there rather than running on every page load
           for a variable nothing reads.

           The rule moved too, and was unsafe here: these were three
           independent CASE sums over payment_type AND payment_method, which
           are different axes - a row matching both an 'online' method and a
           delivery type would have been counted in two slices at once. The
           API classifies each payment into exactly one channel instead, which
           produces the same figures for this data and cannot double count. */

        // Sales Growth Rate - real month-by-month totals for this calendar year vs last,
        // excluding cancelled orders. This used to be a hardcoded Jan-July array that never
        // changed no matter what the real sales data looked like.
        $thisYearNum = (int)date('Y');
        $lastYearNum = $thisYearNum - 1;
        $currentMonthNum = (int)date('n');
        $monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

        $yoyStmt = $pdo->prepare("
            SELECT YEAR(created_at) as yr, MONTH(created_at) as mo, SUM(total_amount) as total
            FROM orders
            WHERE status != 'cancelled' AND YEAR(created_at) IN (?, ?)
            GROUP BY YEAR(created_at), MONTH(created_at)
        ");
        $yoyStmt->execute([$thisYearNum, $lastYearNum]);
        $monthlyTotals = [];
        foreach ($yoyStmt->fetchAll() as $row) {
            $monthlyTotals[(int)$row['yr']][(int)$row['mo']] = (float)$row['total'];
        }

        $totalSalesData = [];
        for ($m = 1; $m <= 12; $m++) {
            $totalSalesData[] = [
                'month' => $monthNames[$m - 1],
                'last_year' => $monthlyTotals[$lastYearNum][$m] ?? 0,
                'this_year' => $monthlyTotals[$thisYearNum][$m] ?? 0,
            ];
        }

        /* The per-month growth series that used to be built here fed the
           Sales Growth Rate line chart. It now comes from
           api/sales_growth.php, which also drops the `: 100` fallback this
           had - with no prior-year month, that reported a confident +100%
           growth rather than admitting there was nothing to compare to, and
           drew a flat fake line across the whole chart. */

        // Overall growth rate = year-to-date (Jan through the current real month) this year
        // vs the same months last year - comparing full calendar years would unfairly count
        // this year's not-yet-happened months as a 100% drop.
        $totalLastYearYtd = 0;
        $totalThisYearYtd = 0;
        for ($m = 1; $m <= $currentMonthNum; $m++) {
            $totalLastYearYtd += $monthlyTotals[$lastYearNum][$m] ?? 0;
            $totalThisYearYtd += $monthlyTotals[$thisYearNum][$m] ?? 0;
        }
        $overallGrowthRate = $totalLastYearYtd > 0 ? (($totalThisYearYtd - $totalLastYearYtd) / $totalLastYearYtd) * 100 : ($totalThisYearYtd > 0 ? 100 : 0);
        $overallGrowthRate = round($overallGrowthRate, 1);

        /* Month-over-month moved to api/sales_growth.php with the rest of the
           card. $overallGrowthRate below is deliberately kept: the KPI cards
           at the top of this page still read it. */

        // Real YoY (year-to-date) trend %s for the KPI cards above, replacing what used to be
        // a hardcoded "+2.5%"/"-2.5%" on every single card regardless of actual performance.
        $ytdStmt = $pdo->prepare("
            SELECT
                COUNT(*) as orders,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as returns,
                AVG(total_amount) as avg_value
            FROM orders
            WHERE YEAR(created_at) = ? AND MONTH(created_at) <= ?
        ");
        $ytdStmt->execute([$thisYearNum, $currentMonthNum]);
        $ytdThis = $ytdStmt->fetch();
        $ytdStmt->execute([$lastYearNum, $currentMonthNum]);
        $ytdLast = $ytdStmt->fetch();
        $pctChange = function ($this_, $last_) {
            $this_ = (float)$this_; $last_ = (float)$last_;
            if ($last_ > 0) { return round((($this_ - $last_) / $last_) * 100, 1); }
            return $this_ > 0 ? 100.0 : 0.0;
        };
        $kpiTrends = [
            'sales' => $overallGrowthRate, // already YoY YTD revenue growth
            'purchase' => $pctChange($ytdThis['orders'] ?? 0, $ytdLast['orders'] ?? 0),
            'return' => $pctChange($ytdThis['returns'] ?? 0, $ytdLast['returns'] ?? 0),
            'avg_value' => $pctChange($ytdThis['avg_value'] ?? 0, $ytdLast['avg_value'] ?? 0),
        ];
    } else {
        // Inventory report data (grouped sales by date/category/product).
        // remaining_stock is products.stock (a live snapshot, not a historical
        // point-in-time value - this app doesn't keep per-day stock history).
        // stock_movement is the net stock_movements change recorded for that
        // product on that same sale date (in adds, out subtracts, adjustment
        // applied as-is since its sign already encodes direction).
        $inventoryStmt = $pdo->prepare("
            SELECT
                DATE(o.created_at) AS sale_date,
                p.id AS product_id,
                COALESCE(c.name, 'Uncategorized') AS category_name,
                p.name AS product_name,
                SUM(oi.quantity) AS quantity_sold,
                ROUND(AVG(oi.price), 2) AS unit_price,
                SUM(oi.total) AS total_sales,
                p.stock AS remaining_stock,
                COALESCE(sm.net_movement, 0) AS stock_movement
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN (
                SELECT product_id, DATE(created_at) AS mv_date,
                       SUM(CASE WHEN movement_type = 'in' THEN quantity
                                WHEN movement_type = 'out' THEN -quantity
                                ELSE quantity END) AS net_movement
                FROM stock_movements
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY product_id, DATE(created_at)
            ) sm ON sm.product_id = p.id AND sm.mv_date = DATE(o.created_at)
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            GROUP BY DATE(o.created_at), p.id, c.name, p.name, p.stock
            ORDER BY sale_date ASC, category_name ASC, product_name ASC
        ");
        $inventoryStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $inventoryRows = $inventoryStmt->fetchAll();

        // Totals for footer
        $inventoryTotals = ['total_rows' => count($inventoryRows), 'total_qty' => 0, 'total_sales' => 0.0];
        foreach ($inventoryRows as $r) {
            $inventoryTotals['total_qty'] += (int)$r['quantity_sold'];
            $inventoryTotals['total_sales'] += (float)$r['total_sales'];
        }

        // Power-BI-importable XLSX export of the same inventory rows shown on screen.
        // Must run before any HTML/header output below, since it sends binary + headers directly.
        if (($_GET['export'] ?? '') === 'xlsx') {
            require_once __DIR__ . '/../../../vendor/autoload.php';
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Inventory Report');
            $headers = ['Date', 'Product ID', 'Category', 'Product', 'Quantity Sold', 'Unit Price', 'Total Sales', 'Remaining Stock', 'Stock Movement'];
            $sheet->fromArray($headers, null, 'A1');
            $sheet->getStyle('A1:I1')->getFont()->setBold(true);

            $rowNum = 2;
            foreach ($inventoryRows as $r) {
                $sheet->setCellValueExplicit('A' . $rowNum, (string)$r['sale_date'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('B' . $rowNum, (int)$r['product_id']);
                $sheet->setCellValueExplicit('C' . $rowNum, (string)$r['category_name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('D' . $rowNum, (string)$r['product_name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('E' . $rowNum, (int)$r['quantity_sold']);
                $sheet->setCellValue('F' . $rowNum, (float)$r['unit_price']);
                $sheet->setCellValue('G' . $rowNum, (float)$r['total_sales']);
                $sheet->setCellValue('H' . $rowNum, (int)$r['remaining_stock']);
                $sheet->setCellValue('I' . $rowNum, (int)$r['stock_movement']);
                $rowNum++;
            }
            foreach (range('A', 'I') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="inventory_report_' . date('Y-m-d') . '.xlsx"');
            header('Cache-Control: max-age=0');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        }

        // Reset other datasets not needed in inventory view
        $salesData = ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'completed_orders' => 0, 'total_returns' => 0];
        $salesFigureData = [];
        $bestProduct = null;
        $bestProducts = [];
        $worstProducts = [];
        $perfSeries = ['labels' => [], 'best' => [], 'worst' => [], 'bucket' => 'day', 'from' => null, 'to' => null, 'clamped' => false];
        $totalSalesData = [];
        $overallGrowthRate = 0;
        $kpiTrends = ['sales' => 0, 'purchase' => 0, 'return' => 0, 'avg_value' => 0];
    }

} catch (PDOException $e) {
    error_log("Reports query error: " . $e->getMessage());
    $salesData = ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'completed_orders' => 0, 'total_returns' => 0];
    $salesFigureData = [];
    $bestProduct = null;
    $bestProducts = [];
    $worstProducts = [];
    $perfSeries = ['labels' => [], 'best' => [], 'worst' => [], 'bucket' => 'day', 'from' => null, 'to' => null, 'clamped' => false];
    $totalSalesData = [];
    $overallGrowthRate = 0;
    $kpiTrends = ['sales' => 0, 'purchase' => 0, 'return' => 0, 'avg_value' => 0];
    $inventoryRows = $inventoryRows ?? [];
    $inventoryTotals = $inventoryTotals ?? ['total_rows' => 0, 'total_qty' => 0, 'total_sales' => 0.0];
    $activityDates = $activityDates ?? [];
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<link rel="stylesheet" href="<?= asset_url('public/shared/css/date-range-picker.css') ?>">
<script src="<?= WEB_ROOT ?>public/shared/assets/js/date-range-picker.js" defer></script>

<main class="container" style="min-height: calc(100vh - 160px);">
  <?php // Straight to the dashboard, not history.back(). The filters here
        // submit by GET, so every date-range and period change is its own
        // history entry and stepping back walks the admin through all of
        // them one at a time. A plain link leaves in a single hop, and it
        // also restores middle-click and open-in-new-tab. ?>
      <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/dashboard.php">Dashboard</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page"><?= htmlspecialchars($screenLabel) ?></span>
      </nav>
    </div>
  </div>
  <div class="admin-dashboard" style="background:transparent;color:inherit;margin:0 auto;padding:20px;border-radius:12px;max-width:1400px;">
    <?php if ($view === 'inventory'): ?>
      <div class="admin-card" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:25px;border-radius:12px;">
        <?php // Reporting sits on the title row rather than in a block under the
              // table: on a long inventory list it was below the fold, which is
              // exactly where a print action should not be. ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin:0 0 15px 0;" class="inv-screen-head">
          <h2 style="margin:0;">Inventory Report</h2>
          <a href="#" onclick="window.print();return false;" style="background:#C5EBAA;color:#1F3A24;font-size:13.3333px;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;white-space:nowrap;">Print Report</a>
        </div>

        <?php /* ---------------------------------------------------------------
           The printed document. Hidden on screen; this is the only masthead
           the paper version gets, because printing the page as-is produced a
           screenshot of the admin - sidebar, date pickers, the Print button
           itself - on a sheet with no title, period or totals.

           Layout follows the supplied reference: a peach masthead band with
           the business block on the left and the report title right-aligned,
           a fully ruled table with a peach header row, and a sign-off strip
           along the bottom (prepared / approved / notes).

           Company details come from config/business_info.php - the same
           single source the delivery notes read - so an address change is
           still a one-file edit.
           --------------------------------------------------------------- */ ?>
        <?php $invBiz = function_exists('business_info') ? business_info() : ['name' => 'TECHWRLD', 'address' => '', 'email' => '', 'phone' => '']; ?>
        <div class="inv-print-doc">
          <div class="inv-print-masthead">
            <div class="inv-print-biz">
              <div class="inv-print-name"><?= htmlspecialchars($invBiz['name']) ?></div>
              <?php if (!empty($invBiz['address'])): ?><div><?= htmlspecialchars($invBiz['address']) ?></div><?php endif; ?>
              <?php if (!empty($invBiz['email'])): ?><div class="inv-print-mail"><?= htmlspecialchars($invBiz['email']) ?></div><?php endif; ?>
              <?php if (!empty($invBiz['phone'])): ?><div><?= htmlspecialchars($invBiz['phone']) ?></div><?php endif; ?>
            </div>
            <div class="inv-print-heading">
              <div class="inv-print-title">INVENTORY REPORT</div>
              <div class="inv-print-period">
                <?= htmlspecialchars(date('M j, Y', strtotime($startDate))) ?> &ndash; <?= htmlspecialchars(date('M j, Y', strtotime($endDate))) ?>
              </div>
            </div>
          </div>
        </div>

        <style>
          /* @page lives at the top level, NOT inside @media print. It only ever
             applies to paged media anyway, and nesting it inside a media block
             is the kind of thing engines disagree about - Chrome honours it,
             others drop it silently. At the top level every engine sees it.

             margin:0 is what removes the browser's own print furniture: the
             "localhost/TECHWRLD/...reports_analytics.php" footer and the date
             and page number beside it are drawn by the browser INSIDE the @page
             margin box, so leaving no margin leaves nowhere to draw them. The
             report's breathing room comes back as padding on the card. */
          @page{size:A4 landscape;margin:0;}

          .inv-print-doc, .inv-print-signoff{display:none;}

          @media print{

            /* Palette lifted from the reference: a soft peach band for the
               masthead and the sign-off labels, one step deeper for the table
               header so the column names sit above the rows rather than
               blending into them. */
            .inv-print-doc, .inv-print-signoff, .admin-card{
              --rep-band:#FAE0C2;
              --rep-head:#F6CB97;
              --rep-rule:#D8BC9B;
              --rep-ink:#1F1A16;
            }

            /* Strip the application. Everything here is either navigation, a
               control, or an overlay - none of it belongs on paper. */
            .admin-sidebar,
            .admin-sidebar-backdrop,
            .admin-menu-toggle,
            .admin-sa-mode-bar,
            #adminLockOverlay,
            .adm-head,
            .scan-fab,
            .inv-screen-head,
            .admin-card form,
            footer,
            .site-footer{display:none !important;}

            /* Flatten the shell so the document starts at the paper edge
               instead of inheriting the app's centred, padded, rounded card. */
            html, body{background:#fff !important;margin:0 !important;padding:0 !important;}
            body.admin{display:block !important;}
            main.container{max-width:none !important;width:auto !important;padding:0 !important;margin:0 !important;}
            .admin-dashboard{padding:0 !important;margin:0 !important;max-width:none !important;}
            /* Padding, not @page margin - see the note above. Left/right padding
               holds on every sheet; top/bottom apply to the first and last only,
               which is why the vertical value is the larger of the two. */
            .admin-card{
              background:#fff !important;border:0 !important;border-radius:0 !important;
              padding:12mm 10mm !important;box-shadow:none !important;color:var(--rep-ink) !important;
            }

            /* Kill the entrance animation - a card caught mid-fade prints at
               partial opacity, which on paper is just a faint page. */
            .admin-enter{animation:none !important;opacity:1 !important;transform:none !important;}

            /* ---- masthead ---- */
            .inv-print-doc{display:block !important;color:var(--rep-ink);}
            .inv-print-masthead{
              display:flex;justify-content:space-between;align-items:flex-start;gap:24px;
              background:var(--rep-band);padding:12px 14px;margin-bottom:10px;
              /* print-color-adjust is what stops the browser dropping the band
                 to save ink - without it the masthead prints white */
              -webkit-print-color-adjust:exact;print-color-adjust:exact;
            }
            .inv-print-biz{font-size:7.5pt;line-height:1.5;}
            .inv-print-name{font-size:16pt;font-weight:800;letter-spacing:-.01em;margin-bottom:2px;line-height:1.1;}
            .inv-print-mail{text-decoration:underline;}
            .inv-print-heading{text-align:right;}
            .inv-print-title{font-size:12pt;font-weight:800;letter-spacing:.06em;}
            .inv-print-period{font-size:8pt;margin-top:3px;}

            /* ---- the table is the report ---- */
            .admin-card > div[style*="overflow"]{
              overflow:visible !important;border:0 !important;border-radius:0 !important;
            }
            .admin-card table{
              width:100% !important;min-width:0 !important;font-size:7.5pt;
              border-collapse:collapse !important;
            }
            /* table-header-group is what repeats the column headings at the top
               of every printed page - without it pages 2+ are unlabelled rows. */
            .admin-card thead{display:table-header-group !important;}
            .admin-card tfoot{display:table-footer-group !important;}
            .admin-card tr{page-break-inside:avoid;break-inside:avoid;}
            .admin-card th, .admin-card td{
              padding:4px 6px !important;border:1px solid var(--rep-rule) !important;
              color:var(--rep-ink) !important;background:none !important;
            }
            .admin-card thead th{
              background:var(--rep-head) !important;font-weight:700;
              font-size:7pt;text-transform:none;text-align:center;
              -webkit-print-color-adjust:exact;print-color-adjust:exact;
            }
            .admin-card tfoot td{
              background:var(--rep-band) !important;font-weight:700;
              -webkit-print-color-adjust:exact;print-color-adjust:exact;
            }

            /* ---- sign-off strip ---- */
            .inv-print-signoff{
              display:grid !important;grid-template-columns:1fr 1fr 2fr;gap:14px;
              margin-top:14px;font-size:7.5pt;color:var(--rep-ink);
              page-break-inside:avoid;break-inside:avoid;
            }
            .inv-print-col{display:flex;flex-direction:column;}
            .inv-print-label{
              background:var(--rep-band);padding:3px 7px;font-weight:700;
              -webkit-print-color-adjust:exact;print-color-adjust:exact;
            }
            .inv-print-value{
              padding:5px 7px 9px;border-bottom:1px solid var(--rep-rule);margin-bottom:7px;min-height:15px;
            }
            .inv-print-col--notes .inv-print-note{padding:6px 7px;line-height:1.5;}

            /* The on-screen totals line duplicates the table's own totals row. */
            .admin-card > div[style*="margin-top:8px"]{display:none !important;}

            a[href]:after{content:"" !important;}
          }
        </style>
        <form method="GET" style="display:flex;gap:15px;align-items:flex-end;margin-bottom:15px;">
          <input type="hidden" name="view" value="inventory">
          <div>
            <label style="display:block;margin-bottom:5px;">Start Date:</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="padding:8px;border-radius:6px;border:1px solid var(--admin-border);">
          </div>
          <div>
            <label style="display:block;margin-bottom:5px;">End Date:</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="padding:8px;border-radius:6px;border:1px solid var(--admin-border);">
          </div>
          <button type="submit" style="background:var(--admin-primary-2);color:#fff;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;">Update</button>
        </form>

        <div style="overflow:auto;border-radius:8px;border:1px solid var(--admin-border);">
          <table style="width:100%;border-collapse:separate;border-spacing:0;min-width:900px;">
            <thead>
              <tr style="background:var(--admin-surface-2);color:var(--admin-text);text-align:left;">
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);">Date</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);">Product ID</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);">Category</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);">Product</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);text-align:right;">Quantity Sold</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);text-align:right;">Unit Price (₱)</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);text-align:right;">Total Sales (₱)</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);text-align:right;">Remaining Stock</th>
                <th style="padding:10px 12px;border-bottom:1px solid var(--admin-border);text-align:right;">Stock Movement</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($inventoryRows)): ?>
                <?php foreach ($inventoryRows as $row): ?>
                  <?php $__mv = (int)$row['stock_movement']; ?>
                  <tr style="border-bottom:1px solid var(--admin-border);">
                    <td style="padding:10px 12px;"><?= htmlspecialchars($row['sale_date']) ?></td>
                    <td style="padding:10px 12px;">#<?= (int)$row['product_id'] ?></td>
                    <td style="padding:10px 12px;"><?= htmlspecialchars($row['category_name']) ?></td>
                    <td style="padding:10px 12px;"><?= htmlspecialchars($row['product_name']) ?></td>
                    <td style="padding:10px 12px;text-align:right;"><?= number_format((int)$row['quantity_sold']) ?></td>
                    <td style="padding:10px 12px;text-align:right;"><?= number_format((float)$row['unit_price'], 2) ?></td>
                    <td style="padding:10px 12px;text-align:right;"><?= number_format((float)$row['total_sales'], 2) ?></td>
                    <td style="padding:10px 12px;text-align:right;"><?= number_format((int)$row['remaining_stock']) ?></td>
                    <td style="padding:10px 12px;text-align:right;color:<?= $__mv > 0 ? 'var(--admin-success)' : ($__mv < 0 ? 'var(--admin-danger)' : 'inherit') ?>;"><?= $__mv > 0 ? '+' : '' ?><?= number_format($__mv) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="9" style="padding:14px 12px;color:var(--admin-muted);text-align:center;">No inventory sales within this range.</td>
                </tr>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr style="background:var(--admin-surface-2);color:var(--admin-text);font-weight:600;">
                <td colspan="4" style="padding:12px;">Total</td>
                <td style="padding:12px;text-align:right;"><?= number_format((int)$inventoryTotals['total_qty']) ?></td>
                <td style="padding:12px;text-align:right;">--</td>
                <td style="padding:12px;text-align:right;">₱<?= number_format((float)$inventoryTotals['total_sales'], 2) ?></td>
                <td style="padding:12px;text-align:right;">--</td>
                <td style="padding:12px;text-align:right;">--</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div style="margin-top:8px;color:var(--admin-muted);">Rows: <?= number_format((int)$inventoryTotals['total_rows']) ?> | Qty: <?= number_format((int)$inventoryTotals['total_qty']) ?> | Sales: ₱<?= number_format((float)$inventoryTotals['total_sales'], 2) ?></div>
        <?php /* Sign-off strip. Sits after the table in source order so it
                 prints below the rows; the label cells carry the same band
                 colour as the masthead, as in the reference. */ ?>
        <div class="inv-print-signoff">
          <div class="inv-print-col">
            <div class="inv-print-label">Prepared by</div>
            <div class="inv-print-value"><?= htmlspecialchars(trim((($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''))) ?: ($_SESSION['username'] ?? '')) ?></div>
            <div class="inv-print-label">Date Prepared</div>
            <div class="inv-print-value"><?= htmlspecialchars(date('m/d/Y')) ?></div>
          </div>
          <div class="inv-print-col">
            <div class="inv-print-label">Approved by</div>
            <div class="inv-print-value">&nbsp;</div>
            <div class="inv-print-label">Date Approved</div>
            <div class="inv-print-value">&nbsp;</div>
          </div>
          <div class="inv-print-col inv-print-col--notes">
            <div class="inv-print-label">Notes/Comments</div>
            <div class="inv-print-note">
              Figures cover <?= number_format((int)$inventoryTotals['total_rows']) ?> line item<?= (int)$inventoryTotals['total_rows'] === 1 ? '' : 's' ?>,
              <?= number_format((int)$inventoryTotals['total_qty']) ?> unit<?= (int)$inventoryTotals['total_qty'] === 1 ? '' : 's' ?> sold,
              totalling &#8369;<?= number_format((float)$inventoryTotals['total_sales'], 2) ?> for the period shown above.
              Remaining stock is the quantity on hand at the time of printing. Verify counts against a physical
              stocktake before acting on reorder decisions.
            </div>
          </div>
        </div>

      </div>
    <?php else: ?>

    <?php /* Report launcher. The six report cards below are parked in hidden
             "stash" wrappers; the page itself shows nothing but a grid of six
             buttons, so you pick one report at a time instead of scrolling a wall
             of charts. Clicking a button moves that card's real DOM node into the
             modal (see the launcher script at the bottom of this file) and moves
             it back on close - the Chart.js instances are never rebuilt. */ ?>
    <style>
      /* Same layering fix as sales_report.php: the date picker's popover drops
         out of the filter card and over whatever follows it, and z-index only
         orders POSITIONED siblings - so the unpositioned launcher below painted
         over it. Explicit layers on the two rows settle it. */
      .date-filter{position:relative;z-index:20;}
      .report-launcher{position:relative;z-index:1;}

      /* --- launcher grid: two rows of three, centred in 70% of the width so the
             block reads as a panel with clear gutters either side. The column
             count is fixed rather than auto-fit, so it stays 3-up instead of
             re-flowing to 4 or 6 on a wide monitor. --- */
      .report-launcher{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;width:92%;margin:0 auto 30px;}
      /* give the gutters back as the viewport tightens, then drop to 2-up, 1-up */
      @media (max-width:1100px){.report-launcher{width:100%;}}
      @media (max-width:860px){.report-launcher{width:100%;grid-template-columns:repeat(2,minmax(0,1fr));}}
      @media (max-width:560px){.report-launcher{grid-template-columns:minmax(0,1fr);}}
      /* Tile box and type scale 1.3x off the original 20px/1.02rem set. The row
         layout - icon beside the text, not above it - is what keeps the card wide
         and short: height is whatever the text needs, with no tall min-height
         padding it out. */
      /* vertical padding stays well under the 26px horizontal - that asymmetry is
         what keeps the card wide and flat. 24px lands it ~15% taller than the
         tightest setting while the sides keep their breathing room. */
      .report-tile{display:flex;flex-direction:row;align-items:center;gap:18px;text-align:left;width:100%;padding:24px 26px;font:inherit;color:var(--admin-text);background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:14px;cursor:pointer;transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease;}
      .report-tile:hover{transform:translateY(-2px);border-color:var(--admin-primary-2);box-shadow:0 14px 32px rgba(0,0,0,.36);}
      .report-tile:focus-visible{outline:2px solid var(--admin-primary-2);outline-offset:2px;}
      .report-tile .tile-icon{font-size:2.2rem;line-height:1;flex:0 0 auto;}
      /* min-width:0 so a long description wraps inside the column instead of
         stretching the grid track */
      .report-tile .tile-text{display:flex;flex-direction:column;gap:3px;min-width:0;}
      .report-tile .tile-name{font-size:1.33rem;font-weight:700;line-height:1.15;}
      .report-tile .tile-desc{font-size:1.07rem;color:var(--admin-muted);line-height:1.25;}
      .report-tile .tile-open{font-size:1.01rem;color:var(--admin-primary-2);font-weight:600;}

      /* --- the stashed cards: present in the DOM, never shown in the page flow.
             !important because each wrapper carries an inline display:grid. --- */
      .report-stash{display:none !important;}

      /* --- modal --- */
      .report-modal{position:fixed;inset:0;z-index:9000;display:none;} /* above the floating admin widget (2500), below the lock overlay (999999) */
      .report-modal.is-open{display:block;}
      .report-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,16,.74);}
      .report-modal-panel{position:relative;width:min(1100px,calc(100% - 32px));max-height:90vh;margin:5vh auto;display:flex;flex-direction:column;background:var(--admin-surface);color:var(--admin-text);border:1px solid var(--admin-border);border-radius:14px;box-shadow:0 30px 80px rgba(0,0,0,.6);overflow:hidden;animation:reportModalIn .18s ease-out;}
      @keyframes reportModalIn{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}
      @media (prefers-reduced-motion:reduce){.report-modal-panel{animation:none}.report-tile{transition:none}}
      .report-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--admin-border);}
      .report-modal-head h3{margin:0;font-size:1.2rem;}
      .report-modal-close{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;font:inherit;font-size:1.1rem;line-height:1;color:var(--admin-text);background:var(--admin-surface-2);border:1px solid var(--admin-border);border-radius:8px;cursor:pointer;}
      .report-modal-close:hover{border-color:var(--admin-danger);color:var(--admin-danger);}
      .report-modal-body{padding:20px;overflow:auto;}
      /* the card keeps its own panel chrome in the page; inside the modal the
         modal *is* the panel, so flatten it */
      .report-modal-body > [data-report-card]{background:transparent !important;border:0 !important;padding:0 !important;min-height:0 !important;}
      body.report-modal-open{overflow:hidden;}

      /* Print (the Sales Report card has a Print button): flatten the modal so
         the open report prints as a normal block instead of one clipped screen. */
      @media print{
        .report-launcher{display:none;}
        .report-modal{position:static;}
        .report-modal-backdrop,.report-modal-close{display:none;}
        .report-modal-panel{width:100%;max-height:none;margin:0;border:0;box-shadow:none;animation:none;}
        .report-modal-body{overflow:visible;}
      }
    </style>

    <!-- Filter by Date -->
    <div class="date-filter" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:20px;border-radius:10px;margin-bottom:30px;">
      <h3 style="margin:0 0 6px 0;">Filter by Date</h3>
      <?php /* The applied range, in words, above the control that sets it.
               Without it the only feedback that a range took effect was the
               numbers changing - and when they did not (see the all-time
               fallback this page used to carry) there was nothing to tell an
               admin whether the filter or the data was at fault. */ ?>
      <p style="margin:0 0 15px;color:var(--admin-muted);font-size:.85rem;">
        Showing
        <strong style="color:var(--admin-text);">
          <?= $hasExplicitRange
                ? htmlspecialchars(date('M j, Y', strtotime($startDate)) . ' - ' . date('M j, Y', strtotime($endDate)))
                : 'all time' ?>
        </strong>
        <?php if ($hasExplicitRange): ?>
          &middot; <a href="?view=<?= htmlspecialchars($view) ?>" style="color:var(--admin-accent, #a05f80);">clear</a>
        <?php endif; ?>
      </p>
      <?php // Analytics tab has no "Update Reports" button - its charts must track stocks,
            // sales and inventory live, so applying a range in the picker submits at once
            // (see the change dispatch in _apply(), shared/assets/js/date-range-picker.js).
            $autoSubmitFilter = ($view === 'analytics'); ?>
      <form method="GET" style="display:flex;gap:15px;align-items:center;"<?= $autoSubmitFilter ? ' data-autosubmit' : '' ?>>
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <div>
          <label style="display:block;margin-bottom:5px;">Date Range:</label>
          <div class="drp" data-drp
               data-drp-start="<?= htmlspecialchars($startDate) ?>"
               data-drp-end="<?= htmlspecialchars($endDate) ?>"
               data-drp-activity="<?= htmlspecialchars(json_encode($activityDates)) ?>">
            <button type="button" class="drp-trigger" data-drp-trigger>
              <i class="fi fi-rr-calendar"></i>
              <span class="drp-trigger-label" data-drp-label></span>
            </button>
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>" data-drp-hidden-start>
            <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>" data-drp-hidden-end>
          </div>
        </div>
        <?php if (!$autoSubmitFilter): ?>
          <button type="submit" style="background:var(--admin-primary-2);color:#fff;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;margin-top:20px;">Update Reports</button>
        <?php endif; ?>
      </form>
    </div>
    <?php if ($autoSubmitFilter): ?>
      <script>
        (function () {
          var form = document.querySelector('.date-filter form[data-autosubmit]');
          if (!form) return;
          // Submit only when the picker writes an applied range into its hidden
          // inputs - never on a change that merely bubbled out of some control
          // inside the form (the picker's own Year/Month/Day selects used to
          // trigger this and reload the page with the previous range still in
          // the hidden fields). _apply() dispatches on both hidden inputs, so
          // the flag keeps that from firing two submits.
          var submitted = false;
          form.addEventListener('change', function (e) {
            var t = e.target;
            if (!t || !t.hasAttribute) return;
            if (!t.hasAttribute('data-drp-hidden-start') && !t.hasAttribute('data-drp-hidden-end')) return;
            if (submitted) return;
            submitted = true;
            form.submit();
          });
        })();
      </script>
    <?php endif; ?>

    <!-- Report launcher: one button per report, each opens in a modal -->
    <?php
    // Single source of truth for the six tiles. The key must match the
    // data-report-card attribute on the card further down the page.
    $reportTiles = [
        'sales-overview'      => ['icon' => '📈', 'name' => 'Sales Overview Chart', 'desc' => 'Revenue trend for a range you pick - day, week, month or custom.'],
        'sales-report'        => ['icon' => '📊', 'name' => 'Sales Report',         'desc' => 'Live revenue by payment channel, with totals, channel mix and period comparison.'],
        'product-performance' => ['icon' => '🏆', 'name' => 'Product Performance',  'desc' => 'Best and worst selling products side by side.'],
        'growth-rate'         => ['icon' => '🚀', 'name' => 'Sales Growth Rate',    'desc' => 'Growth against last year and last month.'],
        'total-sales'         => ['icon' => '🧾', 'name' => 'Total Sales',          'desc' => 'Live revenue split across online, pickup and COD, and how much is actually collected.'],
        'sales-breakdown'     => ['icon' => '🧩', 'name' => 'Sales Breakdown',      'desc' => 'Which product categories the money came from.'],
    ];
    ?>
    <div class="report-launcher">
      <?php foreach ($reportTiles as $key => $tile): ?>
        <button type="button" class="report-tile" data-open-report="<?= htmlspecialchars($key) ?>">
          <span class="tile-icon" aria-hidden="true"><?= $tile['icon'] ?></span>
          <span class="tile-text">
            <span class="tile-name"><?= htmlspecialchars($tile['name']) ?></span>
            <span class="tile-desc"><?= htmlspecialchars($tile['desc']) ?></span>
            <span class="tile-open">Open report →</span>
          </span>
        </button>
      <?php endforeach; ?>
    </div>

    <?php if ($view === 'sales'): ?>
    <!-- KPI Cards -->
    <div class="kpi-section" style="margin-bottom:30px;">
      <div class="kpi-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">
        <!-- Sales -->
        <div class="kpi-card" style="background:linear-gradient(135deg,var(--accent-cyan) 0%,var(--accent-blue) 100%);color:#fff;padding:25px;border-radius:12px;text-align:center;position:relative;overflow:hidden;">
          <div class="kpi-icon" style="position:absolute;top:15px;right:15px;font-size:24px;opacity:0.3;">💰</div>
          <div class="kpi-number" style="font-size:2.5rem;font-weight:bold;margin-bottom:8px;">₱<?= number_format((float)($salesData['total_revenue'] ?? 0), 0) ?></div>
          <div class="kpi-label" style="font-size:1.1rem;opacity:.9;margin-bottom:8px;">Sales</div>
          <div class="kpi-trend" style="display:flex;align-items:center;justify-content:center;gap:5px;font-size:0.9rem;">
            <span style="color:<?= $kpiTrends['sales'] >= 0 ? '#22c55e' : '#fca5a5' ?>;"><?= $kpiTrends['sales'] >= 0 ? '↗' : '↘' ?></span>
            <span><?= $kpiTrends['sales'] >= 0 ? '+' : '' ?><?= $kpiTrends['sales'] ?>% YTD</span>
          </div>
        </div>

        <!-- Purchase -->
        <div class="kpi-card" style="background:linear-gradient(135deg,var(--accent-cyan) 0%,var(--accent-blue) 100%);color:#fff;padding:25px;border-radius:12px;text-align:center;position:relative;overflow:hidden;">
          <div class="kpi-icon" style="position:absolute;top:15px;right:15px;font-size:24px;opacity:0.3;">🛒</div>
          <div class="kpi-number" style="font-size:2.5rem;font-weight:bold;margin-bottom:8px;"><?= number_format((int)($salesData['total_orders'] ?? 0)) ?></div>
          <div class="kpi-label" style="font-size:1.1rem;opacity:.9;margin-bottom:8px;">Purchase</div>
          <div class="kpi-trend" style="display:flex;align-items:center;justify-content:center;gap:5px;font-size:0.9rem;">
            <span style="color:<?= $kpiTrends['purchase'] >= 0 ? '#22c55e' : '#fca5a5' ?>;"><?= $kpiTrends['purchase'] >= 0 ? '↗' : '↘' ?></span>
            <span><?= $kpiTrends['purchase'] >= 0 ? '+' : '' ?><?= $kpiTrends['purchase'] ?>% YTD</span>
          </div>
        </div>

        <!-- Return -->
        <div class="kpi-card" style="background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%);color:#fff;padding:25px;border-radius:12px;text-align:center;position:relative;overflow:hidden;">
          <div class="kpi-icon" style="position:absolute;top:15px;right:15px;font-size:24px;opacity:0.3;">↩️</div>
          <div class="kpi-number" style="font-size:2.5rem;font-weight:bold;margin-bottom:8px;"><?= number_format((int)($salesData['total_returns'] ?? 0)) ?></div>
          <div class="kpi-label" style="font-size:1.1rem;opacity:.9;margin-bottom:8px;">Return</div>
          <div class="kpi-trend" style="display:flex;align-items:center;justify-content:center;gap:5px;font-size:0.9rem;">
            <?php /* more cancellations is bad, so the color logic is inverted vs. the other cards */ ?>
            <span style="color:<?= $kpiTrends['return'] <= 0 ? '#22c55e' : '#fca5a5' ?>;"><?= $kpiTrends['return'] >= 0 ? '↗' : '↘' ?></span>
            <span><?= $kpiTrends['return'] >= 0 ? '+' : '' ?><?= $kpiTrends['return'] ?>% YTD</span>
          </div>
        </div>

        <!-- Average Sales Time -->
        <div class="kpi-card" style="background:linear-gradient(135deg,#10b981 0%,#059669 100%);color:#fff;padding:25px;border-radius:12px;text-align:center;position:relative;overflow:hidden;">
          <div class="kpi-icon" style="position:absolute;top:15px;right:15px;font-size:24px;opacity:0.3;">⏱️</div>
          <div class="kpi-number" style="font-size:2.5rem;font-weight:bold;margin-bottom:8px;"><?= number_format((float)($salesData['avg_order_value'] ?? 0), 0) ?></div>
          <div class="kpi-label" style="font-size:1.1rem;opacity:.9;margin-bottom:8px;">Average Sales Time</div>
          <div class="kpi-trend" style="display:flex;align-items:center;justify-content:center;gap:5px;font-size:0.9rem;">
            <span style="color:<?= $kpiTrends['avg_value'] >= 0 ? '#22c55e' : '#fca5a5' ?>;"><?= $kpiTrends['avg_value'] >= 0 ? '↗' : '↘' ?></span>
            <span><?= $kpiTrends['avg_value'] >= 0 ? '+' : '' ?><?= $kpiTrends['avg_value'] ?>% YTD</span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Charts Section -->
    <div class="charts-section report-stash" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:30px;">
      <!-- Sales Figure Line Chart -->
      <div class="chart-container" data-report-card="sales-overview" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:25px;border-radius:12px;min-height:400px;">
        <div style="display:flex;justify-content:flex-end;align-items:center;margin:0 0 8px;flex-wrap:wrap;gap:10px;" class="sales-overview-controls">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <?php /* This card keeps its own range control - it is the one chart
                     meant to be swept through periods without reloading. But
                     when the page's own Filter by Date has been used, it starts
                     on that range instead of "This Month": two range controls
                     disagreeing on one screen is exactly what "the filter does
                     nothing" looks like. Its own dropdown still overrides it
                     for the rest of the visit. */ ?>
            <select id="salesOverviewRange" aria-label="Sales overview date range" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
              <option value="today">Today</option>
              <option value="yesterday">Yesterday</option>
              <option value="this_week">This Week</option>
              <option value="last_week">Last Week</option>
              <option value="this_month"<?= $hasExplicitRange ? '' : ' selected' ?>>This Month</option>
              <option value="last_month">Last Month</option>
              <option value="custom_range"<?= $hasExplicitRange ? ' selected' : '' ?>>Custom Date Range</option>
            </select>
            <input type="date" id="salesOverviewStartDate" aria-label="Range start date" value="<?= htmlspecialchars($hasExplicitRange ? $startDate : date('Y-m-d', strtotime('-6 days'))) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
            <span id="salesOverviewRangeSep" style="display:none;color:var(--admin-muted);">to</span>
            <input type="date" id="salesOverviewEndDate" aria-label="Range end date" value="<?= htmlspecialchars($hasExplicitRange ? $endDate : date('Y-m-d')) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
          </div>
        </div>
        <div id="salesOverviewRangeLabel" role="status" aria-live="polite" style="color:var(--admin-muted);font-size:.85rem;margin-bottom:12px;min-height:1.2em;"></div>
        <div style="height:300px;position:relative;">
          <canvas id="salesFigureChart"></canvas>
          <div id="salesOverviewLoading" role="status" aria-live="polite" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(11,18,32,.65);border-radius:8px;color:var(--admin-muted);font-size:.95rem;">Loading sales data…</div>
          <div id="salesOverviewEmpty" role="status" aria-live="polite" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:var(--admin-muted);font-size:.95rem;text-align:center;padding:0 20px;"></div>
          <div id="salesOverviewError" role="alert" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#f87171;font-size:.95rem;text-align:center;padding:0 20px;"></div>
        </div>
        <?php /* The chart answers "which days"; these answer "how much, how
                 often, and how does that compare" - all computed from the same
                 points the chart is already drawing, so there is no second
                 request and the two can never disagree. */ ?>
        <div class="so-stats" id="salesOverviewStats" hidden>
          <div class="so-stat"><span>This period</span><strong id="soTotal">&mdash;</strong><em id="soTotalSub">&nbsp;</em></div>
          <div class="so-stat"><span>Same period last year</span><strong id="soLastYear">&mdash;</strong><em id="soLastYearSub">&nbsp;</em></div>
          <div class="so-stat"><span>Busiest day</span><strong id="soBest">&mdash;</strong><em id="soBestSub">&nbsp;</em></div>
          <div class="so-stat"><span>Daily average</span><strong id="soAvg">&mdash;</strong><em id="soAvgSub">&nbsp;</em></div>
        </div>
        <div class="so-insight" id="salesOverviewInsight" role="status" aria-live="polite"></div>

        <style>
          @media (max-width: 640px) {
            .sales-overview-controls { flex-direction: column; align-items: stretch !important; }
            .sales-overview-controls > div { flex-wrap: wrap; }
            #salesOverviewRange, #salesOverviewStartDate, #salesOverviewEndDate { flex: 1 1 auto; min-width: 130px; }
          }
          .so-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:18px;}
          .so-stat{background:var(--admin-surface-2);border:1px solid var(--admin-border);border-radius:10px;padding:11px 13px;display:flex;flex-direction:column;gap:1px;min-width:0;}
          .so-stat span{font-size:.7rem;text-transform:uppercase;letter-spacing:.02em;color:var(--admin-muted);}
          .so-stat strong{font-size:1.08rem;font-weight:700;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .so-stat em{font-style:normal;font-size:.73rem;color:var(--admin-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .so-stat em.up{color:#22c55e;}
          .so-stat em.down{color:#ef4444;}
          .so-insight{font-size:.83rem;line-height:1.55;color:var(--admin-muted);background:var(--admin-surface-2);border:1px solid var(--admin-border);border-left:3px solid var(--admin-accent,#a05f80);border-radius:8px;padding:11px 13px;margin-top:14px;}
          .so-insight:empty{display:none;}
          .so-insight strong{color:var(--admin-text);}
          @media print{
            #salesOverviewRange,#salesOverviewStartDate,#salesOverviewEndDate,#salesOverviewRangeSep{display:none !important;}
          }
        </style>
      </div>

      <!-- Sales Report Bar Chart -->
      <?php /* Live, like the Sales Overview card above it: the range control
               here fetches api/sales_report.php rather than relying on a page
               reload. It used to render from PHP data baked in at page load,
               fixed to a monthly grouping, with no control of its own - so it
               only ever moved when the whole page was reloaded through the
               date filter at the top, which is what "not updating" looked
               like. Its figures were also wrong in two ways: the y-axis was
               hardcoded to 10-100 while real revenue runs into the millions
               (every bar clipped flat off the top), and the channel buckets
               omitted `xendit` entirely, so hundreds of thousands of pesos of
               online payments were missing from the chart. Both are fixed in
               api/sales_report.php, which declares the channels once. */ ?>
      <div class="chart-container" data-report-card="sales-report" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:25px;border-radius:12px;min-height:400px;">
        <div class="sales-report-head" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 6px;">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <label for="salesReportRange" style="color:var(--admin-muted);font-size:.85rem;">Period</label>
            <select id="salesReportRange" aria-label="Sales report period" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
              <option value="today">Today</option>
              <option value="yesterday">Yesterday</option>
              <option value="this_week">This Week</option>
              <option value="last_week">Last Week</option>
              <option value="this_month">This Month</option>
              <option value="last_month">Last Month</option>
              <option value="last_6_months" selected>Last 6 Months</option>
              <option value="this_year">This Year</option>
              <option value="custom_range"<?= $hasExplicitRange ? ' selected' : '' ?>>Custom Date Range</option>
            </select>
            <input type="date" id="salesReportStartDate" aria-label="Sales report range start" value="<?= htmlspecialchars($hasExplicitRange ? $startDate : date('Y-m-01')) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
            <span id="salesReportRangeSep" style="display:none;color:var(--admin-muted);">to</span>
            <input type="date" id="salesReportEndDate" aria-label="Sales report range end" value="<?= htmlspecialchars($hasExplicitRange ? $endDate : date('Y-m-d')) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <button type="button" id="salesReportRefresh" title="Reload this report now" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;font:inherit;font-size:.9rem;cursor:pointer;">&#8635; Refresh</button>
            <a href="#" onclick="window.print();return false;" style="background:#C5EBAA;color:#1F3A24;padding:6px 10px;border-radius:6px;text-decoration:none;font-size:.9rem;">Print</a>
          </div>
        </div>
        <div id="salesReportRangeLabel" role="status" aria-live="polite" style="color:var(--admin-muted);font-size:.85rem;margin:0 0 14px;min-height:1.2em;"></div>

        <?php /* Headline numbers. The chart answers "when"; these answer "how
                 much, how many, and is that good" - which is what the card was
                 missing. Gross sits beside net on purpose: gross matches the
                 page's own Sales KPI (both count every order in the range,
                 cancelled included), and net makes the cancellation gap
                 visible instead of burying it. */ ?>
        <div class="sales-report-tiles" id="salesReportTiles">
          <div class="srt"><span class="srt-k">Gross revenue</span><strong class="srt-v" id="srtRevenue">&mdash;</strong><span class="srt-s" id="srtRevenueSub">&nbsp;</span></div>
          <div class="srt"><span class="srt-k">Net of cancellations</span><strong class="srt-v" id="srtNet">&mdash;</strong><span class="srt-s" id="srtNetSub">&nbsp;</span></div>
          <div class="srt"><span class="srt-k">Orders</span><strong class="srt-v" id="srtOrders">&mdash;</strong><span class="srt-s" id="srtOrdersSub">&nbsp;</span></div>
          <div class="srt"><span class="srt-k">Average order</span><strong class="srt-v" id="srtAov">&mdash;</strong><span class="srt-s" id="srtAovSub">&nbsp;</span></div>
          <div class="srt"><span class="srt-k">Units sold</span><strong class="srt-v" id="srtUnits">&mdash;</strong><span class="srt-s" id="srtUnitsSub">&nbsp;</span></div>
        </div>

        <div style="height:300px;position:relative;margin-top:18px;">
          <canvas id="salesReportChart"></canvas>
          <div id="salesReportLoading" role="status" aria-live="polite" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(11,18,32,.65);border-radius:8px;color:var(--admin-muted);font-size:.95rem;">Loading sales report&hellip;</div>
          <div id="salesReportEmpty" role="status" aria-live="polite" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:var(--admin-muted);font-size:.95rem;text-align:center;padding:0 20px;"></div>
          <div id="salesReportError" role="alert" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#f87171;font-size:.95rem;text-align:center;padding:0 20px;"></div>
        </div>

        <?php /* Channel mix. The stacked bars show it over time; this shows the
                 whole-period split with the share and average order value per
                 channel, which is the part you can actually act on. */ ?>
        <div class="sales-report-channels" id="salesReportChannels" style="margin-top:18px;"></div>

        <div class="sales-report-insight" id="salesReportInsight" role="status" aria-live="polite" style="margin-top:16px;"></div>

        <style>
          .sales-report-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;}
          .srt{background:var(--admin-surface-2);border:1px solid var(--admin-border);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:2px;min-width:0;}
          .srt-k{font-size:.72rem;letter-spacing:.02em;text-transform:uppercase;color:var(--admin-muted);}
          .srt-v{font-size:1.15rem;font-weight:700;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .srt-s{font-size:.74rem;color:var(--admin-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .srt-s.up{color:#22c55e;}
          .srt-s.down{color:#ef4444;}
          .src-row{display:grid;grid-template-columns:minmax(120px,1.4fr) minmax(90px,1fr) 92px 56px;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--admin-border);}
          .src-row:last-child{border-bottom:0;}
          .src-name{display:flex;align-items:center;gap:8px;font-size:.85rem;min-width:0;}
          .src-dot{width:10px;height:10px;border-radius:3px;flex-shrink:0;}
          .src-label{min-width:0;display:flex;flex-direction:column;line-height:1.3;}
          .src-sub{font-size:.72rem;color:var(--admin-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .src-bar{height:8px;border-radius:999px;background:var(--admin-surface-2);overflow:hidden;}
          .src-bar>span{display:block;height:100%;border-radius:999px;}
          .src-amt{font-size:.85rem;font-weight:600;text-align:right;white-space:nowrap;}
          .src-share{font-size:.78rem;color:var(--admin-muted);text-align:right;white-space:nowrap;}
          .src-head{font-size:.72rem;text-transform:uppercase;letter-spacing:.02em;color:var(--admin-muted);padding-bottom:6px;border-bottom:1px solid var(--admin-border);}
          .sales-report-insight{font-size:.85rem;line-height:1.6;color:var(--admin-muted);background:var(--admin-surface-2);border:1px solid var(--admin-border);border-left:3px solid var(--admin-accent,#a05f80);border-radius:8px;padding:12px 14px;}
          .sales-report-insight:empty{display:none;}
          .sales-report-insight strong{color:var(--admin-text);}
          @media (max-width: 640px){
            .sales-report-head{flex-direction:column;align-items:stretch;}
            #salesReportRange,#salesReportStartDate,#salesReportEndDate{flex:1 1 auto;min-width:130px;}
            .src-row{grid-template-columns:1fr 92px 56px;}
            .src-bar{display:none;}
          }
          @media print{
            #salesReportRange,#salesReportStartDate,#salesReportEndDate,#salesReportRangeSep,#salesReportRefresh{display:none !important;}
            .sales-report-head label{display:none !important;}
          }
        </style>
      </div>
    </div>

    <!-- Bottom Section -->
    <div class="bottom-section report-stash" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:30px;">
      <?php /* Product Performance trend palette and legend.

               Scoped dark-first because the admin theme is: admin.css declares
               its dark values on `:root, [data-theme="dark"]` and treats light
               as the opt-in override, so the series slots are declared the same
               way round. Getting that backwards would have left the light theme
               painting dark-surface steps onto a near-white card.

               --pp-c1..c5 are categorical slots 1-5 of the validated palette in
               fixed order; --pp-muted is deliberately achromatic. It is the one
               value here that would fail the palette's chroma floor, and that
               is the point: it is not an identity slot competing with the five,
               it is the recessive "floor" group. */ ?>
      <style>
        .product-card{
          /* dark surface (#123554) steps */
          --pp-c1:#3987e5; --pp-c2:#d95926; --pp-c3:#199e70; --pp-c4:#c98500; --pp-c5:#d55181;
          --pp-muted:#a9b7c6;
          --pp-grid:rgba(242,243,244,.10);
        }
        [data-theme="light"] .product-card{
          /* light surface (#eef0f1) steps */
          --pp-c1:#2a78d6; --pp-c2:#eb6834; --pp-c3:#1baf7a; --pp-c4:#eda100; --pp-c5:#e87ba4;
          --pp-muted:#556575;
          --pp-grid:rgba(23,72,113,.12);
        }

        .pp-legend{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:16px;}
        @media (max-width:720px){.pp-legend{grid-template-columns:1fr;}}
        .pp-legend-heading{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;
          color:var(--admin-muted);margin-bottom:6px;font-weight:700;}
        /* A button, not a div: toggling a series is a real action, so it has to
           be reachable by keyboard and announce its state. */
        .pp-legend-item{display:flex;align-items:center;gap:9px;width:100%;
          background:none;border:0;padding:4px 2px;font:inherit;color:var(--admin-text);
          text-align:left;cursor:pointer;border-radius:5px;}
        .pp-legend-item:hover{background:var(--admin-nav-hover-tint,rgba(128,128,128,.08));}
        .pp-legend-item:focus-visible{outline:2px solid var(--pp-c1);outline-offset:1px;}
        /* Hidden series stay legible rather than vanishing - you need to be able
           to find the row again to switch it back on. */
        .pp-legend-item[aria-pressed="false"]{opacity:.42;}
        .pp-legend-item[aria-pressed="false"] .pp-legend-name{text-decoration:line-through;}
        .pp-legend-name{flex:1;min-width:0;font-size:.78rem;overflow:hidden;
          text-overflow:ellipsis;white-space:nowrap;}
        /* Totals are text, so they wear a text token - never the series colour. */
        .pp-legend-total{font-size:.72rem;color:var(--admin-muted);font-variant-numeric:tabular-nums;flex-shrink:0;}
        .pp-swatch{width:22px;height:0;border-top-width:3px;border-top-style:solid;
          border-radius:2px;flex-shrink:0;}
        .pp-swatch--c1{border-top-color:var(--pp-c1);}
        .pp-swatch--c2{border-top-color:var(--pp-c2);}
        .pp-swatch--c3{border-top-color:var(--pp-c3);}
        .pp-swatch--c4{border-top-color:var(--pp-c4);}
        .pp-swatch--c5{border-top-color:var(--pp-c5);}
        /* The dash is drawn into the swatch so the legend matches the stroke it
           stands for, and so the two groups differ by more than hue. */
        .pp-swatch--muted{border-top-color:var(--pp-muted);border-top-style:dashed;}
      </style>

      <!-- Product Performance: Best + Worst Selling, side by side in one container -->
      <div class="product-card" data-report-card="product-performance" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:25px;border-radius:12px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div style="<?= '' ?>border-right:1px solid var(--admin-border);padding-right:20px;">
            <h4 style="margin:0 0 12px 0;font-size:1rem;opacity:.85;">Best Selling</h4>
            <?php if (!empty($bestProducts)): ?>
              <?php $rankColors = [1 => '#f59e0b', 2 => '#94a3b8', 3 => '#cd7f32', 4 => '#4b5563', 5 => '#4b5563']; ?>
              <div>
                <?php foreach ($bestProducts as $i => $p): $rank = $i + 1; ?>
                  <div style="display:flex;align-items:center;gap:10px;padding:8px 0;<?= $rank < count($bestProducts) ? 'border-bottom:1px solid var(--admin-border);' : '' ?>">
                    <div style="width:22px;height:22px;border-radius:50%;background:<?= $rankColors[$rank] ?>;color:#0b1220;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.7rem;flex-shrink:0;">#<?= $rank ?></div>
                    <div style="width:38px;height:38px;border-radius:8px;overflow:hidden;border:1px solid var(--admin-border);flex-shrink:0;background:var(--admin-surface-2);">
                      <?php if (!empty($p['image_url'])): ?>
                        <img src="<?= WEB_ROOT . htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                      <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1rem;">📦</div>
                      <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                      <div style="font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                      <div style="font-size:.72rem;color:var(--admin-muted);"><?= number_format((int)($p['total_sold'] ?? 0)) ?> sold</div>
                    </div>
                    <div style="font-weight:700;color:#3b82f6;font-size:.8rem;white-space:nowrap;text-align:right;">₱<?= number_format((float)($p['total_revenue'] ?? 0), 0) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div style="text-align:center;color:#6b7280;"><p>No data available</p></div>
            <?php endif; ?>
          </div>

          <div>
            <h4 style="margin:0 0 12px 0;font-size:1rem;opacity:.85;">Worst Selling</h4>
            <?php if (!empty($worstProducts)): ?>
              <div>
                <?php foreach ($worstProducts as $i => $p): ?>
                  <div style="display:flex;align-items:center;gap:10px;padding:8px 0;<?= $i < count($worstProducts) - 1 ? 'border-bottom:1px solid var(--admin-border);' : '' ?>">
                    <div style="width:22px;height:22px;border-radius:50%;background:#ef4444;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.7rem;flex-shrink:0;">!</div>
                    <div style="width:38px;height:38px;border-radius:8px;overflow:hidden;border:1px solid var(--admin-border);flex-shrink:0;background:var(--admin-surface-2);">
                      <?php if (!empty($p['image_url'])): ?>
                        <img src="<?= WEB_ROOT . htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" style="width:100%;height:100%;object-fit:cover;">
                      <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1rem;">📦</div>
                      <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                      <div style="font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
                      <div style="font-size:.72rem;color:var(--admin-muted);"><?= number_format((int)($p['total_sold'] ?? 0)) ?> sold</div>
                    </div>
                    <div style="font-weight:700;color:var(--admin-danger);font-size:.8rem;white-space:nowrap;text-align:right;">₱<?= number_format((float)($p['total_revenue'] ?? 0), 0) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div style="text-align:center;color:#6b7280;"><p>No data available</p></div>
            <?php endif; ?>
          </div>
        </div>

        <?php /* ---- Trend comparison -------------------------------------
                 Cumulative units for the ten products listed above, so the
                 two groups can be compared as slopes rather than as two
                 separate top-five tables.

                 WHY THE WORST FIVE ARE ONE GREY DASH AND NOT FIVE HUES.
                 The request was a different colour per product. For the best
                 sellers that is exactly what happens. The worst sellers
                 cannot work that way, and not because of this catalogue's
                 current data: "worst five" is selected BY having the fewest
                 sales, so on any range it is five products sitting on zero,
                 drawn as five pixel-identical lines along the axis. Five
                 colours there would be five legend swatches pointing at one
                 visible line - a palette that promises a distinction the plot
                 cannot show. They share a recessive grey and a dashed stroke,
                 which says "this whole group is the floor" in one read, and
                 each is still named in its own legend row. A worst seller that
                 does move in some range lifts off the floor and is identifiable
                 by its dash.

                 The five best-seller hues are the first five categorical slots
                 of the validated palette, in fixed order, assigned by rank and
                 never cycled. Both the light and dark steps were run through
                 the palette validator against this admin theme's own two
                 surfaces (#eef0f1 and #123554) - every check passes; the light
                 mode's sub-3:1 contrast warning is relieved by the two lists
                 above, which are the table view of the same numbers. */ ?>
        <div style="margin-top:22px;border-top:1px solid var(--admin-border);padding-top:18px;">
          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:4px;">
            <h4 style="margin:0;font-size:1rem;opacity:.85;">Cumulative Units Sold</h4>
            <span style="font-size:.72rem;color:var(--admin-muted);">
              <?= htmlspecialchars((string)count($perfSeries['labels'])) ?>
              <?= htmlspecialchars($perfSeries['bucket']) ?><?= count($perfSeries['labels']) === 1 ? '' : 's' ?>
              <?php if (!empty($perfSeries['from'])): ?>
                &middot; <?= htmlspecialchars(date('j M Y', strtotime((string)$perfSeries['from']))) ?>
                &ndash; <?= htmlspecialchars(date('j M Y', strtotime((string)$perfSeries['to']))) ?>
              <?php endif; ?>
            </span>
          </div>
          <p style="margin:0 0 14px;font-size:.75rem;color:var(--admin-muted);line-height:1.5;">
            A running total, so each line&rsquo;s <em>steepness</em> is its sales rate. Flat means no sales in that period.
            <?php if (!empty($perfSeries['clamped'])): ?>
              The selected range runs <?= htmlspecialchars(date('j M Y', strtotime($startDate))) ?>
              &ndash; <?= htmlspecialchars(date('j M Y', strtotime($endDate))) ?>; the chart is trimmed to the days that hold orders.
            <?php endif; ?>
          </p>

          <?php if (!empty($perfSeries['labels']) && (!empty($perfSeries['best']) || !empty($perfSeries['worst']))): ?>
            <div style="position:relative;height:320px;">
              <canvas id="productPerfChart"></canvas>
            </div>

            <?php /* Legend built in markup rather than by Chart.js: it carries
                     each product's total next to its name, groups best from
                     worst under headings, and keeps identity off colour alone
                     (the dash pattern is drawn into the swatch). */ ?>
            <div class="pp-legend">
              <?php foreach (['best' => 'Best selling', 'worst' => 'Worst selling'] as $grp => $heading): ?>
                <?php if (empty($perfSeries[$grp])) { continue; } ?>
                <div class="pp-legend-group">
                  <div class="pp-legend-heading"><?= htmlspecialchars($heading) ?></div>
                  <?php foreach ($perfSeries[$grp] as $gi => $sv): ?>
                    <button type="button" class="pp-legend-item" data-pp-series="<?= $grp ?>:<?= (int)$gi ?>"
                            aria-pressed="true" title="Hide or show this product">
                      <span class="pp-swatch pp-swatch--<?= $grp === 'best' ? 'c' . ($gi + 1) : 'muted' ?>" aria-hidden="true"></span>
                      <span class="pp-legend-name"><?= htmlspecialchars($sv['name']) ?></span>
                      <span class="pp-legend-total"><?= number_format((int)$sv['total']) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="text-align:center;color:var(--admin-muted);font-size:.85rem;padding:28px 0;">
              No orders in this date range, so there is no trend to plot.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Sales Growth Rate KPI Card -->
      <?php /* This card is a fixed comparison - this calendar year against
               last, and this month against last - so it deliberately does not
               move with the date filter. Saying so is the difference between
               "this ignores my filter on purpose" and "the filter is broken",
               which is how it read when every other card moved and this one
               did not. */ ?>
      <?php /* Live from api/sales_growth.php. The scope stays fixed on
               purpose (see the note above) - what changed is that the figures
               are fetched rather than baked into the page, so a refresh or
               opening the card in the modal re-reads them.

               The percentages were also being invented. Both headlines used
               `last > 0 ? ((this-last)/last)*100 : (this > 0 ? 100 : 0)`, so
               with no prior-year data at all - every order in this database is
               from 2026 - the card read a confident "+100%" and the trend line
               sat flat at 100% for every month. A percentage needs a non-zero
               baseline; the API returns null without one and the card says so
               in words. The trend line now plots actual monthly revenue, which
               is real whether or not there is anything to compare it to. */ ?>
      <div class="sales-growth-card" data-report-card="growth-rate" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:25px;border-radius:12px;text-align:center;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;text-align:left;">
          <p style="margin:0 0 14px;color:var(--admin-muted);font-size:.82rem;line-height:1.5;flex:1;">
            Year-to-date and month-over-month comparison &mdash; always the calendar periods,
            not the date range picked above.
          </p>
          <button type="button" id="salesGrowthRefresh" title="Reload this report now" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:4px 8px;border-radius:6px;font:inherit;font-size:.85rem;cursor:pointer;flex-shrink:0;">&#8635;</button>
        </div>

        <!-- KPI Card Content -->
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:200px;position:relative;">
          <!-- Main Growth Rate Display -->
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
            <span style="font-size:2.5rem;" id="sgIcon">📈</span>
            <div style="text-align:left;">
              <div style="font-size:2.2rem;font-weight:bold;line-height:1.1;" id="sgYoy">&mdash;</div>
              <div style="font-size:0.9rem;opacity:0.8;" id="sgYoyLabel">vs last year</div>
            </div>
          </div>

          <!-- Month-over-Month Growth -->
          <div style="display:flex;align-items:center;gap:8px;font-size:1.1rem;" id="sgMomWrap">
            <span id="sgMomArrow"></span>
            <span id="sgMom">&mdash;</span>
            <span style="opacity:0.8;font-size:0.9rem;" id="sgMomLabel">vs last month</span>
          </div>

          <?php /* The pesos behind the percentages. A growth figure with no
                   amounts next to it cannot be sanity-checked. */ ?>
          <div class="sg-figures" id="sgFigures"></div>

          <div id="salesGrowthLoading" role="status" aria-live="polite" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(11,18,32,.65);border-radius:8px;color:var(--admin-muted);font-size:.9rem;">Loading&hellip;</div>
          <div id="salesGrowthError" role="alert" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#f87171;font-size:.9rem;text-align:center;padding:0 16px;"></div>
        </div>

        <!-- Growth Rate Chart -->
        <div style="height:150px;position:relative;margin-top:16px;">
          <canvas id="salesGrowthRateChart"></canvas>
        </div>
        <div id="sgChartNote" style="font-size:.73rem;color:var(--admin-muted);margin-top:6px;"></div>

        <div class="sg-insight" id="salesGrowthInsight" role="status" aria-live="polite"></div>

        <style>
          .sg-figures{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-top:14px;width:100%;}
          .sg-figures div{background:var(--admin-surface-2);border:1px solid var(--admin-border);border-radius:8px;padding:8px 10px;text-align:left;min-width:0;}
          .sg-figures span{display:block;font-size:.67rem;text-transform:uppercase;letter-spacing:.02em;color:var(--admin-muted);}
          .sg-figures b{display:block;font-size:.95rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .sg-insight{font-size:.8rem;line-height:1.55;color:var(--admin-muted);background:var(--admin-surface-2);border:1px solid var(--admin-border);border-left:3px solid var(--admin-accent,#a05f80);border-radius:8px;padding:10px 12px;margin-top:14px;text-align:left;}
          .sg-insight:empty{display:none;}
          .sg-insight strong{color:var(--admin-text);}
          @media print{ #salesGrowthRefresh{display:none !important;} }
        </style>
      </div>

      <!-- Total Sales Donut Chart -->
      <?php /* Live, from api/total_sales.php. Everything on this card used to
               be rendered from PHP at page load - the donut, the channel list
               and the orders/AOV footer - so it only moved when the whole page
               was reloaded through the date filter at the top. It now has its
               own period control, like the Sales Overview and Sales Report
               cards, sharing their range vocabulary via config/report_ranges.php.

               The settlement row is new. The channel split answered "where did
               the money come from" but not "did any of it actually arrive":
               cancelled and pending payments were drawn in the donut exactly
               like collected ones. */ ?>
      <div class="sales-card" data-report-card="total-sales" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:25px;border-radius:12px;">
        <div class="total-sales-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 4px;">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <label for="totalSalesRange" style="color:var(--admin-muted);font-size:.85rem;">Period</label>
            <select id="totalSalesRange" aria-label="Total sales period" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:5px 9px;border-radius:6px;font-size:.85rem;">
              <option value="today">Today</option>
              <option value="yesterday">Yesterday</option>
              <option value="this_week">This Week</option>
              <option value="last_week">Last Week</option>
              <option value="this_month">This Month</option>
              <option value="last_month">Last Month</option>
              <option value="last_6_months" selected>Last 6 Months</option>
              <option value="this_year">This Year</option>
              <option value="custom_range"<?= $hasExplicitRange ? ' selected' : '' ?>>Custom Date Range</option>
            </select>
            <input type="date" id="totalSalesStartDate" aria-label="Total sales range start" value="<?= htmlspecialchars($hasExplicitRange ? $startDate : date('Y-m-01')) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:5px 9px;border-radius:6px;font-size:.85rem;">
            <span id="totalSalesRangeSep" style="display:none;color:var(--admin-muted);">to</span>
            <input type="date" id="totalSalesEndDate" aria-label="Total sales range end" value="<?= htmlspecialchars($hasExplicitRange ? $endDate : date('Y-m-d')) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:5px 9px;border-radius:6px;font-size:.85rem;">
          </div>
          <button type="button" id="totalSalesRefresh" title="Reload this report now" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:5px 9px;border-radius:6px;font:inherit;font-size:.85rem;cursor:pointer;">&#8635;</button>
        </div>
        <div id="totalSalesRangeLabel" role="status" aria-live="polite" style="color:var(--admin-muted);font-size:.8rem;margin:0 0 10px;min-height:1.1em;"></div>

        <div style="position:relative;height:180px;">
          <canvas id="totalSalesChart" width="200" height="200"></canvas>
          <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;">
            <div style="font-size:1.5rem;font-weight:bold;" id="totalSalesCentre">&mdash;</div>
            <div style="font-size:0.9rem;opacity:0.8;">Total Sales</div>
            <div style="font-size:0.74rem;opacity:0.65;" id="totalSalesCentreSub">&nbsp;</div>
          </div>
          <div id="totalSalesLoading" role="status" aria-live="polite" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(11,18,32,.65);border-radius:8px;color:var(--admin-muted);font-size:.9rem;">Loading&hellip;</div>
          <div id="totalSalesError" role="alert" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#f87171;font-size:.9rem;text-align:center;padding:0 16px;"></div>
        </div>

        <div id="totalSalesChannels" style="margin-top:16px;"></div>

        <?php /* Did the money actually arrive? Settled = payments marked
                 completed. Awaiting = pending or still processing. Not
                 collected = cancelled, failed or refunded. */ ?>
        <div id="totalSalesSettlement" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--admin-border);"></div>

        <div style="display:flex;justify-content:space-between;margin-top:14px;padding-top:14px;border-top:1px solid var(--admin-border);font-size:.85rem;">
          <div>
            <div style="opacity:.7;">Total Orders</div>
            <div style="font-weight:700;" id="totalSalesOrders">&mdash;</div>
          </div>
          <div style="text-align:right;">
            <div style="opacity:.7;">Avg Order Value</div>
            <div style="font-weight:700;" id="totalSalesAov">&mdash;</div>
          </div>
        </div>

        <div class="total-sales-insight" id="totalSalesInsight" role="status" aria-live="polite"></div>

        <style>
          .ts-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:.85rem;gap:10px;}
          .ts-name{display:flex;align-items:center;gap:8px;min-width:0;}
          .ts-dot{display:inline-block;width:9px;height:9px;border-radius:50%;flex-shrink:0;}
          .ts-label{display:flex;flex-direction:column;line-height:1.3;min-width:0;}
          .ts-sub{font-size:.71rem;color:var(--admin-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .ts-amt{text-align:right;white-space:nowrap;}
          .ts-amt b{font-weight:600;}
          .ts-amt span{opacity:.7;}
          .ts-settle{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;text-align:center;}
          .ts-settle div span{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.02em;color:var(--admin-muted);}
          .ts-settle div b{display:block;font-size:.92rem;font-weight:700;margin-top:2px;white-space:nowrap;}
          .ts-settle div em{display:block;font-style:normal;font-size:.68rem;color:var(--admin-muted);}
          .ts-settle-bar{display:flex;height:6px;border-radius:999px;overflow:hidden;margin-bottom:10px;background:var(--admin-surface-2);}
          .ts-settle-bar i{display:block;height:100%;}
          .total-sales-insight{font-size:.8rem;line-height:1.55;color:var(--admin-muted);background:var(--admin-surface-2);border:1px solid var(--admin-border);border-left:3px solid var(--admin-accent,#a05f80);border-radius:8px;padding:10px 12px;margin-top:14px;}
          .total-sales-insight:empty{display:none;}
          .total-sales-insight strong{color:var(--admin-text);}
          @media print{
            #totalSalesRange,#totalSalesStartDate,#totalSalesEndDate,#totalSalesRangeSep,#totalSalesRefresh{display:none !important;}
            .total-sales-head label{display:none !important;}
          }
        </style>
      </div>
    </div>

    <!-- Sales Breakdown: Donut + Stacked Bars -->
    <?php /* Live from api/sales_breakdown.php. Three things were wrong here.

             1. It never refreshed - donut, legend and bars were all PHP
                inlined at page load.

             2. THE TWO HALVES COUNTED DIFFERENT ORDERS. The donut query
                filtered `o.status = 'completed'`; the stacked-bars query
                filtered IN ('preparing','packed','ready','shipped',
                'completed'). On current data that is PHP 677,466 against
                PHP 1,531,585 - one card, two answers, 2.3x apart. The API
                declares one filter (cancelled orders excluded) and both
                halves read it.

             3. THE DONUT DREW INVENTED NUMBERS. Categories with no sales were
                inflated to `max(maxSales * 0.01, 1000)` and small ones to
                twice that before being handed to Chart.js, so the slice sizes
                were not the real proportions and did not add up to the total
                printed in the middle. Real amounts only now; empty categories
                are listed in the legend, which is where they belong. */ ?>
    <div class="markets-section report-stash" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:25px;border-radius:12px;">
      <div data-report-card="sales-breakdown">
      <div class="bd-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 4px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <label for="salesBreakdownRange" style="color:var(--admin-muted);font-size:.85rem;">Period</label>
          <select id="salesBreakdownRange" aria-label="Sales breakdown period" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="this_week">This Week</option>
            <option value="last_week">Last Week</option>
            <option value="this_month">This Month</option>
            <option value="last_month">Last Month</option>
            <option value="last_6_months" selected>Last 6 Months</option>
            <option value="this_year">This Year</option>
            <option value="custom_range"<?= $hasExplicitRange ? ' selected' : '' ?>>Custom Date Range</option>
          </select>
          <input type="date" id="salesBreakdownStartDate" aria-label="Breakdown range start" value="<?= htmlspecialchars($hasExplicitRange ? $startDate : date('Y-m-01')) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
          <span id="salesBreakdownRangeSep" style="display:none;color:var(--admin-muted);">to</span>
          <input type="date" id="salesBreakdownEndDate" aria-label="Breakdown range end" value="<?= htmlspecialchars($hasExplicitRange ? $endDate : date('Y-m-d')) ?>" style="display:none;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;">
        </div>
        <button type="button" id="salesBreakdownRefresh" title="Reload this report now" style="background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);padding:6px 10px;border-radius:6px;font:inherit;font-size:.9rem;cursor:pointer;">&#8635; Refresh</button>
      </div>
      <div id="salesBreakdownRangeLabel" role="status" aria-live="polite" style="color:var(--admin-muted);font-size:.82rem;margin:0 0 14px;min-height:1.1em;"></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:center;" class="bd-grid">
        <div style="position:relative;height:260px;">
          <canvas id="salesBreakdownDonut"></canvas>
          <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;">
            <div style="font-size:1.4rem;font-weight:800;" id="bdCentre">&mdash;</div>
            <div style="opacity:.8;">Total Sales</div>
            <div style="font-size:.72rem;opacity:.65;" id="bdCentreSub">&nbsp;</div>
          </div>
          <div id="salesBreakdownLoading" role="status" aria-live="polite" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(11,18,32,.65);border-radius:8px;color:var(--admin-muted);font-size:.9rem;">Loading&hellip;</div>
          <div id="salesBreakdownError" role="alert" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#f87171;font-size:.9rem;text-align:center;padding:0 16px;"></div>
        </div>
        <div id="salesBreakdownLegend"></div>
      </div>

      <div style="height:260px;margin-top:20px;">
        <canvas id="salesBreakdownStacked"></canvas>
      </div>

      <div class="bd-insight" id="salesBreakdownInsight" role="status" aria-live="polite"></div>

      <style>
        .bd-legend-row{display:grid;grid-template-columns:14px 1fr auto 52px;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--admin-border);}
        .bd-legend-row:last-child{border-bottom:0;}
        .bd-dot{width:12px;height:12px;border-radius:50%;}
        .bd-name{min-width:0;display:flex;flex-direction:column;line-height:1.25;}
        .bd-name b{font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .bd-name em{font-style:normal;font-size:.71rem;color:var(--admin-muted);white-space:nowrap;}
        .bd-amt{font-size:.88rem;font-weight:600;white-space:nowrap;text-align:right;}
        .bd-share{font-size:.76rem;color:var(--admin-muted);text-align:right;white-space:nowrap;}
        .bd-legend-row.is-empty .bd-dot{opacity:.3;}
        .bd-legend-row.is-empty b,.bd-legend-row.is-empty .bd-amt{opacity:.55;}
        .bd-empty-note{font-size:.75rem;color:var(--admin-muted);padding:8px 0 0;border-top:1px solid var(--admin-border);margin-top:6px;}
        .bd-insight{font-size:.82rem;line-height:1.55;color:var(--admin-muted);background:var(--admin-surface-2);border:1px solid var(--admin-border);border-left:3px solid var(--admin-accent,#a05f80);border-radius:8px;padding:11px 13px;margin-top:16px;}
        .bd-insight:empty{display:none;}
        .bd-insight strong{color:var(--admin-text);}
        @media (max-width: 760px){ .bd-grid{grid-template-columns:1fr;} }
        @media print{
          #salesBreakdownRange,#salesBreakdownStartDate,#salesBreakdownEndDate,#salesBreakdownRangeSep,#salesBreakdownRefresh{display:none !important;}
          .bd-head label{display:none !important;}
        }
      </style>
      </div>
    </div>

    <!-- Modal shell. The body starts empty; the launcher script moves the chosen
         card's real node in here and puts it back in its stash on close. -->
    <div class="report-modal" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportModalTitle" hidden>
      <div class="report-modal-backdrop" data-report-close></div>
      <div class="report-modal-panel" role="document">
        <div class="report-modal-head">
          <h3 id="reportModalTitle">Report</h3>
          <button type="button" class="report-modal-close" data-report-close aria-label="Close report">✕</button>
        </div>
        <div class="report-modal-body" id="reportModalBody"></div>
      </div>
    </div>

    <?php endif; ?>
  </div>
</main>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    /* The Sales Report card used to read a $salesReportData blob inlined here
       at page render time - that is exactly why it never updated without a
       full reload. It now loads from api/sales_report.php (see the Sales
       Report chart block further down); the query that produced that blob has
       been removed from the PHP above, since nothing else read it. */

    // ===== Sales Overview Chart - This Year vs Last Year, by specific day/date =====
    // Backed live by public/authenticated/api/sales_overview.php (real orders.total_amount
    // data, day-by-day, compared against the same calendar date one year earlier).

    // Parses 'YYYY-MM-DD' as a LOCAL date (not UTC) so tooltip/label formatting never
    // shifts by a day depending on the browser's timezone offset.
    function parseIsoDateLocal(iso) {
        const [y, m, d] = iso.split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    function formatFullDate(iso) {
        return parseIsoDateLocal(iso).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }
    function formatMoney(n) {
        return '₱' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    // Abbreviated pesos for axis ticks, where a full "₱2,362,242" label does not
    // fit and gets dropped or rotated by Chart.js.
    function formatMoneyShort(n) {
        const v = Number(n || 0);
        const abs = Math.abs(v);
        if (abs >= 1000000) { return '₱' + (v / 1000000).toFixed(abs >= 10000000 ? 0 : 1).replace(/\.0$/, '') + 'M'; }
        if (abs >= 1000)    { return '₱' + (v / 1000).toFixed(abs >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k'; }
        return '₱' + v.toFixed(0);
    }
    // Channel labels come from the API, which builds them from a server-side
    // constant - but they are injected with innerHTML to carry the colour
    // swatch markup, so they are escaped rather than trusted on sight.
    function escapeHtml(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // Builds the "Last year" / "This year" legend labels with the actual
    // date/year being compared, instead of the generic static text - a
    // single-day range shows the exact date, a multi-day range shows the year.
    function salesOverviewLegendLabels(points) {
        if (!points.length) { return { thisYear: 'This year', lastYear: 'Last year' }; }
        const first = points[0];
        if (points.length === 1) {
            const thisYearDate = parseIsoDateLocal(first.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const lastYearDate = parseIsoDateLocal(first.last_year_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            return { thisYear: 'This year (' + thisYearDate + ')', lastYear: 'Last year (' + lastYearDate + ')' };
        }
        const thisYear = parseIsoDateLocal(first.date).getFullYear();
        const lastYear = parseIsoDateLocal(first.last_year_date).getFullYear();
        return { thisYear: 'This year (' + thisYear + ')', lastYear: 'Last year (' + lastYear + ')' };
    }
    function debounce(fn, delayMs) {
        let t;
        return function() {
            const args = arguments;
            clearTimeout(t);
            t = setTimeout(() => fn.apply(null, args), delayMs);
        };
    }
    function salesOverviewMaxTicks() {
        if (window.innerWidth < 480) return 5;
        if (window.innerWidth < 768) return 7;
        if (window.innerWidth < 1200) return 10;
        return 14;
    }

    let salesOverviewPoints = [];

    const salesFigureCtx = document.getElementById('salesFigureChart').getContext('2d');
    const salesFigureChart = new Chart(salesFigureCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Last year',
                data: [],
                borderColor: '#6b7280',
                backgroundColor: 'rgba(107, 114, 128, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointStyle: 'circle',
                pointBackgroundColor: '#6b7280',
                pointBorderColor: '#6b7280',
                pointRadius: 4,
                pointHoverRadius: 6
            }, {
                label: 'This year',
                data: [],
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointStyle: 'circle',
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#3b82f6',
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            aspectRatio: 2,
            animation: { duration: 400 },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: '#17324a',
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            const p = salesOverviewPoints[items[0].dataIndex];
                            return p ? formatFullDate(p.date) : '';
                        },
                        label: function(item) {
                            const p = salesOverviewPoints[item.dataIndex];
                            if (!p) { return item.dataset.label + ': ' + item.formattedValue; }
                            // datasetIndex 1 = "This year", 0 = "Last year" (see dataset order below) -
                            // checked by index, not by label text, since the legend labels now carry
                            // the actual year/date and change dynamically per selected range.
                            return item.datasetIndex === 1
                                ? ('This year: ' + formatMoney(p.this_year))
                                : ('Last year (' + formatFullDate(p.last_year_date) + '): ' + formatMoney(p.last_year));
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#17324a',
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: salesOverviewMaxTicks()
                    },
                    grid: {
                        color: 'rgba(23,72,113,0.14)'
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#17324a',
                        callback: function(value) { return '₱' + Number(value).toLocaleString('en-US'); }
                    },
                    grid: {
                        color: 'rgba(23,72,113,0.14)'
                    }
                }
            }
        }
    });

    const salesOverviewEls = {
        range: document.getElementById('salesOverviewRange'),
        startDate: document.getElementById('salesOverviewStartDate'),
        endDate: document.getElementById('salesOverviewEndDate'),
        sep: document.getElementById('salesOverviewRangeSep'),
        rangeLabel: document.getElementById('salesOverviewRangeLabel'),
        loading: document.getElementById('salesOverviewLoading'),
        empty: document.getElementById('salesOverviewEmpty'),
        error: document.getElementById('salesOverviewError')
    };

    function syncSalesOverviewInputs() {
        const v = salesOverviewEls.range.value;
        salesOverviewEls.startDate.style.display = v === 'custom_range' ? 'inline-block' : 'none';
        salesOverviewEls.endDate.style.display = v === 'custom_range' ? 'inline-block' : 'none';
        salesOverviewEls.sep.style.display = v === 'custom_range' ? 'inline' : 'none';
    }

    function setSalesOverviewState(state) {
        salesOverviewEls.loading.style.display = state === 'loading' ? 'flex' : 'none';
        salesOverviewEls.empty.style.display = state === 'empty' ? 'flex' : 'none';
        salesOverviewEls.error.style.display = state === 'error' ? 'flex' : 'none';
    }

    /* Everything below is derived from the points already on the chart - no
       extra request, and nothing that could report a different total than the
       bars the admin is looking at. */
    function renderSalesOverviewStats(d) {
        const pts = d.points || [];
        const stats = document.getElementById('salesOverviewStats');
        const insight = document.getElementById('salesOverviewInsight');
        if (!pts.length) { stats.hidden = true; insight.innerHTML = ''; return; }
        stats.hidden = false;

        const thisTotal = d.total_this_year || 0;
        const lastTotal = d.total_last_year || 0;
        const delta = lastTotal > 0 ? ((thisTotal - lastTotal) / lastTotal) * 100 : null;

        // Busiest day, and how many days actually took money. Days with no
        // sales are part of the story: 8 quiet days out of 31 is a different
        // month from 8 busy ones, and the line alone does not say which.
        let best = pts[0];
        let active = 0;
        pts.forEach(function (p) {
            if (p.this_year > best.this_year) { best = p; }
            if (p.this_year > 0) { active++; }
        });
        const quiet = pts.length - active;
        // Averaged over trading days, not calendar days - dividing by days the
        // shop took nothing would understate a normal day.
        const avg = active > 0 ? thisTotal / active : 0;

        const set = function (id, subId, value, sub, dir) {
            document.getElementById(id).textContent = value;
            const el = document.getElementById(subId);
            el.textContent = sub || '\u00a0';
            el.classList.remove('up', 'down');
            if (dir) { el.classList.add(dir); }
        };

        const deltaText = formatDelta(delta);
        set('soTotal', 'soTotalSub', formatMoney(thisTotal),
            deltaText !== null ? deltaText + ' vs last year' : 'no sales a year ago to compare',
            deltaText === null ? null : (delta >= 0 ? 'up' : 'down'));
        set('soLastYear', 'soLastYearSub', formatMoney(lastTotal),
            pts.length === 1 ? formatFullDate(pts[0].last_year_date) : 'same dates, ' + (new Date(pts[0].last_year_date.slice(0, 4), 0, 1)).getFullYear());
        set('soBest', 'soBestSub', best.this_year > 0 ? formatMoney(best.this_year) : '\u2014',
            best.this_year > 0 ? best.day + ', ' + best.label : 'no sales in this period');
        set('soAvg', 'soAvgSub', formatMoney(avg),
            active + ' of ' + pts.length + (pts.length === 1 ? ' day' : ' days') + ' with sales');

        const bits = [];
        if (deltaText !== null) {
            bits.push('<strong>' + deltaText + '</strong> against the same dates last year ('
                    + formatMoney(lastTotal) + ').');
        }
        if (best.this_year > 0) {
            const bestShare = thisTotal > 0 ? (best.this_year / thisTotal * 100) : 0;
            bits.push('<strong>' + best.day + ' ' + best.label + '</strong> was the busiest day at '
                    + formatMoney(best.this_year)
                    + (pts.length > 1 ? ' \u2014 ' + bestShare.toFixed(0) + '% of the period.' : '.'));
        }
        if (quiet > 0 && pts.length > 1) {
            bits.push(quiet + ' day' + (quiet === 1 ? '' : 's') + ' with no sales at all.');
        }
        insight.innerHTML = bits.length ? bits.join(' ') : '';
    }

    let salesOverviewRequestSeq = 0;
    let salesOverviewLastKey = null;

    function fetchSalesOverview() {
        const range = salesOverviewEls.range.value;
        const params = new URLSearchParams({ range: range });
        if (range === 'custom_range') {
            params.set('start_date', salesOverviewEls.startDate.value);
            params.set('end_date', salesOverviewEls.endDate.value);
        }

        const requestKey = params.toString();
        if (requestKey === salesOverviewLastKey) { return; } // same query already loaded/loading - avoid a redundant request
        salesOverviewLastKey = requestKey;

        const seq = ++salesOverviewRequestSeq;
        setSalesOverviewState('loading');

        fetch('<?= WEB_ROOT ?>public/authenticated/api/sales_overview.php?' + requestKey, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(d => {
            if (seq !== salesOverviewRequestSeq) { return; } // superseded by a newer selection
            if (!d || !d.success) {
                setSalesOverviewState('error');
                salesOverviewEls.error.textContent = (d && d.message) ? d.message : 'Could not load sales data.';
                salesOverviewEls.rangeLabel.textContent = '';
                document.getElementById('salesOverviewStats').hidden = true;
                document.getElementById('salesOverviewInsight').innerHTML = '';
                return;
            }

            salesOverviewPoints = d.points || [];
            const hasData = salesOverviewPoints.some(p => p.this_year > 0 || p.last_year > 0);

            const legendLabels = salesOverviewLegendLabels(salesOverviewPoints);
            salesFigureChart.data.labels = salesOverviewPoints.map(p => [p.day.slice(0, 3), p.label]);
            salesFigureChart.data.datasets[0].label = legendLabels.lastYear;
            salesFigureChart.data.datasets[0].data = salesOverviewPoints.map(p => p.last_year);
            salesFigureChart.data.datasets[1].label = legendLabels.thisYear;
            salesFigureChart.data.datasets[1].data = salesOverviewPoints.map(p => p.this_year);
            salesFigureChart.options.scales.x.ticks.maxTicksLimit = salesOverviewMaxTicks();
            salesFigureChart.update();

            salesOverviewEls.rangeLabel.textContent = d.range_label
                ? (d.range_label + '  •  This year: ' + formatMoney(d.total_this_year) + '   |   Last year: ' + formatMoney(d.total_last_year))
                : '';

            renderSalesOverviewStats(d);

            if (!hasData) {
                setSalesOverviewState('empty');
                salesOverviewEls.empty.textContent = salesOverviewPoints.length === 1
                    ? ('No sales data available for ' + formatFullDate(salesOverviewPoints[0].date) + '.')
                    : 'No sales data available for this date range.';
            } else {
                setSalesOverviewState(null);
            }
        })
        .catch(function() {
            if (seq !== salesOverviewRequestSeq) { return; }
            setSalesOverviewState('error');
            salesOverviewEls.error.textContent = 'Could not load sales data. Please try again.';
            salesOverviewEls.rangeLabel.textContent = '';
        });
    }

    const debouncedSalesOverviewFetch = debounce(fetchSalesOverview, 350);

    salesOverviewEls.range.addEventListener('change', function() {
        syncSalesOverviewInputs();
        fetchSalesOverview();
    });
    salesOverviewEls.startDate.addEventListener('change', debouncedSalesOverviewFetch);
    salesOverviewEls.endDate.addEventListener('change', debouncedSalesOverviewFetch);
    window.addEventListener('resize', debounce(function() {
        salesFigureChart.options.scales.x.ticks.maxTicksLimit = salesOverviewMaxTicks();
        salesFigureChart.update();
    }, 200));

    syncSalesOverviewInputs();
    // Loads whatever the select starts on - "This Month" normally, or the
    // page's own Filter by Date range when the admin has applied one.
    fetchSalesOverview();

    // ===== Sales Report Bar Chart - revenue by payment channel, over time =====
    // Backed live by public/authenticated/api/sales_report.php. Starts empty and
    // is filled by fetchSalesReport() below, the same way the Sales Overview
    // chart above works.
    //
    // The y-axis is NOT pinned any more. It used to be `min: 10, max: 100`,
    // which silently clipped every bar: real monthly revenue here is in the
    // hundreds of thousands of pesos, so all of them ran off the top of a
    // 10-100 axis and the chart could not be read at all. Chart.js scales it
    // from the data instead, from zero.
    //
    // Bars are stacked by channel so each bar's height is the period's total
    // revenue while the segments show where it came from - previously only
    // two of the three computed series were plotted at all, so a bar did not
    // add up to its own period.
    const salesReportCtx = document.getElementById('salesReportChart').getContext('2d');
    const salesReportChart = new Chart(salesReportCtx, {
        type: 'bar',
        data: { labels: [], datasets: [] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: { color: '#17324a', usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10 }
                },
                tooltip: {
                    callbacks: {
                        // Channel amount plus its share of that period, so a
                        // tall bar can be read without doing the arithmetic.
                        label: function (ctx) {
                            const value = ctx.parsed.y || 0;
                            let periodTotal = 0;
                            ctx.chart.data.datasets.forEach(function (ds) {
                                periodTotal += parseFloat(ds.data[ctx.dataIndex]) || 0;
                            });
                            const share = periodTotal > 0 ? ' (' + (value / periodTotal * 100).toFixed(1) + '%)' : '';
                            return ctx.dataset.label + ': ' + formatMoney(value) + share;
                        },
                        footer: function (items) {
                            if (!items.length) { return ''; }
                            let periodTotal = 0;
                            items[0].chart.data.datasets.forEach(function (ds) {
                                periodTotal += parseFloat(ds.data[items[0].dataIndex]) || 0;
                            });
                            const point = salesReportPoints[items[0].dataIndex];
                            const orders = point ? point.orders : 0;
                            return 'Total: ' + formatMoney(periodTotal) + '  \u00b7  ' + orders + (orders === 1 ? ' order' : ' orders');
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: { color: '#17324a', autoSkip: true, maxRotation: 0 },
                    grid: { color: 'rgba(23,72,113,0.14)' }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        color: '#17324a',
                        // Peso-abbreviated, because a raw 2362242.4 tick label
                        // is unreadable at this width.
                        callback: function (v) { return formatMoneyShort(v); }
                    },
                    grid: { color: 'rgba(23,72,113,0.14)' }
                }
            },
            datasets: { bar: { maxBarThickness: 44 } }
        }
    });

    // ---- live loading for the Sales Report card ----------------------------

    const salesReportEls = {
        range: document.getElementById('salesReportRange'),
        startDate: document.getElementById('salesReportStartDate'),
        endDate: document.getElementById('salesReportEndDate'),
        sep: document.getElementById('salesReportRangeSep'),
        refresh: document.getElementById('salesReportRefresh'),
        rangeLabel: document.getElementById('salesReportRangeLabel'),
        loading: document.getElementById('salesReportLoading'),
        empty: document.getElementById('salesReportEmpty'),
        error: document.getElementById('salesReportError'),
        channels: document.getElementById('salesReportChannels'),
        insight: document.getElementById('salesReportInsight')
    };
    let salesReportPoints = [];

    function syncSalesReportInputs() {
        const isCustom = salesReportEls.range.value === 'custom_range';
        salesReportEls.startDate.style.display = isCustom ? 'inline-block' : 'none';
        salesReportEls.endDate.style.display = isCustom ? 'inline-block' : 'none';
        salesReportEls.sep.style.display = isCustom ? 'inline' : 'none';
    }

    function setSalesReportState(state) {
        salesReportEls.loading.style.display = state === 'loading' ? 'flex' : 'none';
        salesReportEls.empty.style.display = state === 'empty' ? 'flex' : 'none';
        salesReportEls.error.style.display = state === 'error' ? 'flex' : 'none';
    }

    // A delta against a near-zero baseline produces numbers like +8739%, which
    // is arithmetically true and completely unreadable. Past 999% it is shown
    // as a multiplier instead.
    function formatDelta(pct) {
        if (pct === null || pct === undefined) { return null; }
        const abs = Math.abs(pct);
        if (abs > 999) {
            return (pct > 0 ? '\u00d7' : '\u00f7') + (abs / 100 + 1).toFixed(1).replace(/\.0$/, '');
        }
        return (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';
    }

    function setTile(valueId, subId, value, sub, direction) {
        document.getElementById(valueId).textContent = value;
        const subEl = document.getElementById(subId);
        subEl.textContent = sub || '\u00a0';
        subEl.classList.remove('up', 'down');
        if (direction === 'up') { subEl.classList.add('up'); }
        if (direction === 'down') { subEl.classList.add('down'); }
    }

    function renderSalesReportChannels(channels, total) {
        if (!channels || !channels.length || total <= 0) {
            salesReportEls.channels.innerHTML = '';
            return;
        }
        const max = Math.max.apply(null, channels.map(function (c) { return c.amount; }));
        // Header cells sit in the same four grid columns as the data cells, in
        // the same order - name, bar, revenue, share.
        let html = '<div class="src-row src-head">'
                 + '<span>Payment channel</span>'
                 + '<span>Share of revenue</span>'
                 + '<span style="text-align:right;">Revenue</span>'
                 + '<span style="text-align:right;">%</span>'
                 + '</div>';
        channels.forEach(function (c) {
            const width = max > 0 ? Math.max(c.amount / max * 100, c.amount > 0 ? 2 : 0) : 0;
            // Order count and average order value go under the channel name.
            // They are what distinguishes "one huge order" from "steady
            // volume", and for Store Credit they are the only non-zero
            // numbers on the row - those orders are recorded at a 0 total
            // because the credit settled them in full at checkout, so without
            // the count the row looks like dead weight rather than 20 sales.
            const sub = c.orders > 0
                ? c.orders + (c.orders === 1 ? ' order' : ' orders') + ' \u00b7 avg ' + formatMoney(c.aov)
                : 'no orders';
            html += '<div class="src-row">'
                  + '<span class="src-name"><span class="src-dot" style="background:' + c.color + ';"></span>'
                  + '<span class="src-label">' + escapeHtml(c.label)
                  + '<span class="src-sub">' + sub + '</span></span></span>'
                  + '<span class="src-bar"><span style="width:' + width + '%;background:' + c.color + ';"></span></span>'
                  + '<span class="src-amt">' + formatMoney(c.amount) + '</span>'
                  + '<span class="src-share">' + c.share.toFixed(1) + '%</span>'
                  + '</div>';
        });
        salesReportEls.channels.innerHTML = html;
    }

    function renderSalesReportInsight(d) {
        const bits = [];
        const t = d.totals || {};
        const ins = d.insights || {};
        const cmp = d.compare || {};

        const delta = formatDelta(cmp.delta_pct);
        if (delta !== null) {
            const dir = cmp.delta_pct >= 0 ? 'up' : 'down';
            bits.push('Revenue is <strong>' + delta + '</strong> ' + (dir === 'up' ? 'above' : 'below')
                    + ' the preceding ' + d.day_count + (d.day_count === 1 ? ' day' : ' days')
                    + ' (' + escapeHtml(cmp.label) + ', ' + formatMoney(cmp.revenue) + ').');
        } else if (cmp.revenue === 0) {
            bits.push('No sales in the preceding ' + d.day_count + (d.day_count === 1 ? ' day' : ' days')
                    + ', so there is no comparison to draw.');
        }

        if (ins.top_channel) {
            bits.push('<strong>' + escapeHtml(ins.top_channel) + '</strong> is the biggest channel at '
                    + ins.top_channel_share.toFixed(1) + '% of revenue.');
        }
        if (ins.best_label) {
            bits.push('Strongest ' + (d.granularity === 'day' ? 'day' : d.granularity) + ': <strong>'
                    + escapeHtml(ins.best_label) + '</strong> (' + formatMoney(ins.best_total) + ').');
        }
        if (t.cancelled_orders > 0) {
            bits.push('<strong>' + formatMoney(t.cancelled_amount) + '</strong> sits in '
                    + t.cancelled_orders + ' cancelled ' + (t.cancelled_orders === 1 ? 'order' : 'orders')
                    + ' (' + t.cancelled_rate.toFixed(1) + '% of orders) and is excluded from the net figure.');
        }
        if (t.units > 0) {
            bits.push(t.units + ' unit' + (t.units === 1 ? '' : 's') + ' across '
                    + t.distinct_products + ' different product' + (t.distinct_products === 1 ? '' : 's')
                    + ', ' + t.units_per_order.toFixed(2) + ' per order.');
        }

        salesReportEls.insight.innerHTML = bits.length ? bits.join(' ') : '';
    }

    let salesReportRequestSeq = 0;

    function fetchSalesReport() {
        const range = salesReportEls.range.value;
        const params = new URLSearchParams({ range: range });
        if (range === 'custom_range') {
            params.set('start_date', salesReportEls.startDate.value);
            params.set('end_date', salesReportEls.endDate.value);
        }

        const seq = ++salesReportRequestSeq;
        setSalesReportState('loading');

        fetch('<?= WEB_ROOT ?>public/authenticated/api/sales_report.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(r => r.json())
        .then(d => {
            if (seq !== salesReportRequestSeq) { return; } // superseded by a newer selection
            if (!d || !d.success) {
                setSalesReportState('error');
                salesReportEls.error.textContent = (d && d.message) ? d.message : 'Could not load the sales report.';
                salesReportEls.rangeLabel.textContent = '';
                salesReportEls.channels.innerHTML = '';
                salesReportEls.insight.innerHTML = '';
                return;
            }

            salesReportPoints = d.points || [];
            const channels = d.channels || [];
            const totals = d.totals || {};

            // One dataset per channel, in the order the API declares them, so
            // the colours stay stable as the range changes.
            salesReportChart.data.labels = salesReportPoints.map(p => p.label);
            salesReportChart.data.datasets = channels.map(function (c) {
                return {
                    label: c.label,
                    data: salesReportPoints.map(p => (p.channels && p.channels[c.key]) || 0),
                    backgroundColor: c.color,
                    borderColor: c.color,
                    borderWidth: 0,
                    borderRadius: 3
                };
            });
            salesReportChart.update();

            const grainWord = d.granularity === 'day' ? 'day' : (d.granularity === 'week' ? 'week' : 'month');
            salesReportEls.rangeLabel.textContent = d.range_label + '  \u2022  by ' + grainWord
                + '  \u2022  updated ' + new Date().toLocaleTimeString();

            const delta = formatDelta(d.compare ? d.compare.delta_pct : null);
            setTile('srtRevenue', 'srtRevenueSub', formatMoney(totals.revenue),
                    delta !== null ? delta + ' vs previous ' + d.day_count + 'd' : 'no prior period to compare',
                    delta === null ? null : (d.compare.delta_pct >= 0 ? 'up' : 'down'));
            setTile('srtNet', 'srtNetSub', formatMoney(totals.net_revenue),
                    totals.cancelled_orders > 0
                        ? 'after ' + totals.cancelled_orders + ' cancelled'
                        : 'nothing cancelled');
            setTile('srtOrders', 'srtOrdersSub', Number(totals.orders).toLocaleString(),
                    totals.cancelled_rate > 0 ? totals.cancelled_rate.toFixed(1) + '% cancelled' : 'none cancelled');
            setTile('srtAov', 'srtAovSub', formatMoney(totals.aov), 'per order');
            setTile('srtUnits', 'srtUnitsSub', Number(totals.units).toLocaleString(),
                    totals.distinct_products + ' distinct product' + (totals.distinct_products === 1 ? '' : 's'));

            renderSalesReportChannels(channels, totals.revenue);
            renderSalesReportInsight(d);

            if (!(totals.revenue > 0) && !(totals.orders > 0)) {
                setSalesReportState('empty');
                salesReportEls.empty.textContent = 'No sales recorded for ' + d.range_label + '.';
            } else {
                setSalesReportState(null);
            }
        })
        .catch(function () {
            if (seq !== salesReportRequestSeq) { return; }
            setSalesReportState('error');
            salesReportEls.error.textContent = 'Could not load the sales report. Please try again.';
        });
    }

    const debouncedSalesReportFetch = debounce(fetchSalesReport, 350);

    salesReportEls.range.addEventListener('change', function () {
        syncSalesReportInputs();
        fetchSalesReport();
    });
    salesReportEls.startDate.addEventListener('change', debouncedSalesReportFetch);
    salesReportEls.endDate.addEventListener('change', debouncedSalesReportFetch);
    salesReportEls.refresh.addEventListener('click', fetchSalesReport);

    syncSalesReportInputs();
    fetchSalesReport();

    /* Opening the card in the modal re-reads the API, so what you see is the
       data as of the moment you opened it, not as of page load. The launcher
       at the bottom of this file calls whatever is registered here for the
       report key it is opening. */
    window.__reportRefreshers = window.__reportRefreshers || {};
    window.__reportRefreshers['sales-report'] = fetchSalesReport;

    // Total Sales Donut Chart
    const totalSalesCtx = document.getElementById('totalSalesChart').getContext('2d');
    const totalSalesChart = new Chart(totalSalesCtx, {
        type: 'doughnut',
        // Starts empty; filled by fetchTotalSales() below. The labels, amounts
        // and colours all come from api/total_sales.php now, so the slice
        // colours cannot drift out of step with the legend rows underneath -
        // they used to be two separate hardcoded lists.
        data: { labels: [], datasets: [{ data: [], backgroundColor: [], borderWidth: 2, borderColor: '#eef0f1' }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            const total = ctx.dataset.data.reduce(function (a, b) { return a + (+b || 0); }, 0);
                            const v = ctx.parsed || 0;
                            const share = total > 0 ? ' (' + (v / total * 100).toFixed(1) + '%)' : '';
                            const ch = totalSalesChannelData[ctx.dataIndex];
                            const settled = ch ? '  \u00b7  ' + formatMoney(ch.settled) + ' collected' : '';
                            return ctx.label + ': ' + formatMoney(v) + share + settled;
                        }
                    }
                }
            }
        }
    });

    // ---- live loading for the Total Sales card -----------------------------

    const totalSalesEls = {
        range: document.getElementById('totalSalesRange'),
        startDate: document.getElementById('totalSalesStartDate'),
        endDate: document.getElementById('totalSalesEndDate'),
        sep: document.getElementById('totalSalesRangeSep'),
        refresh: document.getElementById('totalSalesRefresh'),
        rangeLabel: document.getElementById('totalSalesRangeLabel'),
        loading: document.getElementById('totalSalesLoading'),
        error: document.getElementById('totalSalesError'),
        centre: document.getElementById('totalSalesCentre'),
        centreSub: document.getElementById('totalSalesCentreSub'),
        channels: document.getElementById('totalSalesChannels'),
        settlement: document.getElementById('totalSalesSettlement'),
        orders: document.getElementById('totalSalesOrders'),
        aov: document.getElementById('totalSalesAov'),
        insight: document.getElementById('totalSalesInsight')
    };
    let totalSalesChannelData = [];

    function syncTotalSalesInputs() {
        const isCustom = totalSalesEls.range.value === 'custom_range';
        totalSalesEls.startDate.style.display = isCustom ? 'inline-block' : 'none';
        totalSalesEls.endDate.style.display = isCustom ? 'inline-block' : 'none';
        totalSalesEls.sep.style.display = isCustom ? 'inline' : 'none';
    }

    function renderTotalSalesChannels(channels) {
        if (!channels || !channels.length) { totalSalesEls.channels.innerHTML = ''; return; }
        totalSalesEls.channels.innerHTML = channels.map(function (c) {
            // The sub-line is the new part: order count, and how much of that
            // channel's money has actually been collected. A channel can be
            // the biggest slice on the donut and still have collected almost
            // none of it.
            const sub = c.orders + (c.orders === 1 ? ' payment' : ' payments')
                      + ' \u00b7 ' + c.settled_share.toFixed(0) + '% collected';
            return '<div class="ts-row">'
                 + '<span class="ts-name"><span class="ts-dot" style="background:' + c.color + ';"></span>'
                 + '<span class="ts-label">' + escapeHtml(c.label)
                 + '<span class="ts-sub">' + sub + '</span></span></span>'
                 + '<span class="ts-amt"><b>' + formatMoney(c.amount) + '</b> '
                 + '<span>(' + c.share.toFixed(1) + '%)</span></span>'
                 + '</div>';
        }).join('');
    }

    function renderTotalSalesSettlement(st) {
        if (!st) { totalSalesEls.settlement.innerHTML = ''; return; }
        const parts = [
            { key: 'settled',  label: 'Collected',     color: '#22c55e' },
            { key: 'awaiting', label: 'Awaiting',      color: '#f59e0b' },
            { key: 'lost',     label: 'Not collected', color: '#ef4444' }
        ];
        const bar = parts.map(function (p) {
            const share = (st[p.key] && st[p.key].share) || 0;
            return share > 0 ? '<i style="width:' + share + '%;background:' + p.color + ';"></i>' : '';
        }).join('');
        const cells = parts.map(function (p) {
            const v = st[p.key] || { amount: 0, count: 0, share: 0 };
            return '<div><span style="color:' + p.color + ';">' + p.label + '</span>'
                 + '<b>' + formatMoney(v.amount) + '</b>'
                 + '<em>' + v.share.toFixed(0) + '% \u00b7 ' + v.count + '</em></div>';
        }).join('');
        totalSalesEls.settlement.innerHTML =
            '<div class="ts-settle-bar">' + bar + '</div><div class="ts-settle">' + cells + '</div>';
    }

    function renderTotalSalesInsight(d) {
        const bits = [];
        const ins = d.insights || {};
        const st = d.settlement || {};
        const cmp = d.compare || {};

        const delta = formatDelta(cmp.delta_pct);
        if (delta !== null) {
            bits.push('<strong>' + delta + '</strong> vs the preceding ' + d.day_count
                    + (d.day_count === 1 ? ' day' : ' days') + '.');
        }
        if (ins.top_channel) {
            bits.push('<strong>' + escapeHtml(ins.top_channel) + '</strong> leads at '
                    + ins.top_channel_share.toFixed(1) + '%.');
        }
        if (st.settled) {
            bits.push('<strong>' + formatMoney(st.settled.amount) + '</strong> of '
                    + formatMoney(d.reconciliation ? d.reconciliation.payments_total : 0)
                    + ' has actually been collected (' + st.settled.share.toFixed(0) + '%).');
        }
        if (st.lost && st.lost.amount > 0) {
            bits.push(formatMoney(st.lost.amount) + ' in ' + st.lost.count
                    + ' cancelled or refunded payment' + (st.lost.count === 1 ? '' : 's') + '.');
        }
        // Only worth saying when the two sources genuinely disagree. Store-credit
        // orders are stored at a 0 total with the credit on the payment row, so a
        // small gap is normal and silent.
        if (d.reconciliation && Math.abs(d.reconciliation.gap) >= 1000) {
            bits.push('Payments total differs from order revenue by '
                    + formatMoney(Math.abs(d.reconciliation.gap)) + ' \u2014 worth a look.');
        }
        totalSalesEls.insight.innerHTML = bits.length ? bits.join(' ') : '';
    }

    let totalSalesRequestSeq = 0;

    function fetchTotalSales() {
        const range = totalSalesEls.range.value;
        const params = new URLSearchParams({ range: range });
        if (range === 'custom_range') {
            params.set('start_date', totalSalesEls.startDate.value);
            params.set('end_date', totalSalesEls.endDate.value);
        }

        const seq = ++totalSalesRequestSeq;
        totalSalesEls.loading.style.display = 'flex';
        totalSalesEls.error.style.display = 'none';

        fetch('<?= WEB_ROOT ?>public/authenticated/api/total_sales.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(r => r.json())
        .then(d => {
            if (seq !== totalSalesRequestSeq) { return; } // superseded
            totalSalesEls.loading.style.display = 'none';
            if (!d || !d.success) {
                totalSalesEls.error.style.display = 'flex';
                totalSalesEls.error.textContent = (d && d.message) ? d.message : 'Could not load total sales.';
                return;
            }

            const t = d.totals || {};
            totalSalesChannelData = d.channels || [];

            totalSalesChart.data.labels = totalSalesChannelData.map(c => c.label);
            totalSalesChart.data.datasets[0].data = totalSalesChannelData.map(c => c.amount);
            totalSalesChart.data.datasets[0].backgroundColor = totalSalesChannelData.map(c => c.color);
            totalSalesChart.update();

            totalSalesEls.centre.textContent = formatMoney(t.revenue);
            totalSalesEls.centreSub.textContent = t.cancelled_orders > 0
                ? formatMoney(t.net_revenue) + ' net'
                : '\u00a0';
            totalSalesEls.orders.textContent = Number(t.orders || 0).toLocaleString();
            totalSalesEls.aov.textContent = formatMoney(t.aov);
            totalSalesEls.rangeLabel.textContent = d.range_label + '  \u2022  updated ' + new Date().toLocaleTimeString();

            renderTotalSalesChannels(totalSalesChannelData);
            renderTotalSalesSettlement(d.settlement);
            renderTotalSalesInsight(d);
        })
        .catch(function () {
            if (seq !== totalSalesRequestSeq) { return; }
            totalSalesEls.loading.style.display = 'none';
            totalSalesEls.error.style.display = 'flex';
            totalSalesEls.error.textContent = 'Could not load total sales. Please try again.';
        });
    }

    const debouncedTotalSalesFetch = debounce(fetchTotalSales, 350);

    totalSalesEls.range.addEventListener('change', function () {
        syncTotalSalesInputs();
        fetchTotalSales();
    });
    totalSalesEls.startDate.addEventListener('change', debouncedTotalSalesFetch);
    totalSalesEls.endDate.addEventListener('change', debouncedTotalSalesFetch);
    totalSalesEls.refresh.addEventListener('click', fetchTotalSales);

    syncTotalSalesInputs();
    fetchTotalSales();

    // Re-read when the card is opened in the modal, same as the Sales Report card.
    window.__reportRefreshers = window.__reportRefreshers || {};
    window.__reportRefreshers['total-sales'] = fetchTotalSales;

    // ===== Sales Breakdown - donut + stacked bars, live ====================
    // Both charts start empty and are filled by fetchSalesBreakdown() from
    // api/sales_breakdown.php, which is also the single source of the category
    // colours - they used to be written out three times (twice in PHP, once
    // here) and could drift apart.
    //
    // The donut plots REAL amounts. The previous version inflated empty
    // categories to `max(maxSales * 0.01, 1000)` and small ones to twice that
    // before drawing them, so the slice sizes were fiction and did not sum to
    // the total in the middle. Categories with no sales are listed in the
    // legend instead, greyed, which is the honest way to say "nothing sold
    // here".
    const donutCtx = document.getElementById('salesBreakdownDonut').getContext('2d');
    const donutChart = new Chart(donutCtx, {
        type: 'doughnut',
        data: { labels: [], datasets: [{ data: [], backgroundColor: [], borderWidth: 0, hoverOffset: 10 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            const total = ctx.dataset.data.reduce(function (a, b) { return a + (+b || 0); }, 0);
                            const v = ctx.parsed || 0;
                            const share = total > 0 ? ' (' + (v / total * 100).toFixed(1) + '%)' : '';
                            const c = salesBreakdownSelling[ctx.dataIndex];
                            const units = c ? '  \u00b7  ' + c.units + (c.units === 1 ? ' unit' : ' units') : '';
                            return ctx.label + ': ' + formatMoney(v) + share + units;
                        }
                    }
                }
            }
        }
    });

    const stackedCtx = document.getElementById('salesBreakdownStacked').getContext('2d');
    const stackedChart = new Chart(stackedCtx, {
        type: 'bar',
        data: { labels: [], datasets: [] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false }, // the legend list beside the donut is the legend
                tooltip: {
                    // Only categories that actually sold in the hovered bucket -
                    // a stack of fourteen "PHP 0" lines is noise, not detail.
                    filter: function (item) { return (item.parsed.y || 0) > 0; },
                    callbacks: {
                        label: function (ctx) { return ctx.dataset.label + ': ' + formatMoney(ctx.parsed.y || 0); },
                        footer: function (items) {
                            if (!items.length) { return ''; }
                            let total = 0;
                            items[0].chart.data.datasets.forEach(function (ds) {
                                total += parseFloat(ds.data[items[0].dataIndex]) || 0;
                            });
                            return 'Total: ' + formatMoney(total);
                        }
                    }
                }
            },
            scales: {
                x: { stacked: true, ticks: { color: '#17324a', autoSkip: true, maxRotation: 0 }, grid: { color: 'rgba(23,72,113,0.14)' } },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { color: '#17324a', callback: function (v) { return formatMoneyShort(v); } },
                    grid: { color: 'rgba(23,72,113,0.14)' }
                }
            }
        }
    });

    // ---- live loading for the Sales Breakdown card -------------------------

    const salesBreakdownEls = {
        range: document.getElementById('salesBreakdownRange'),
        startDate: document.getElementById('salesBreakdownStartDate'),
        endDate: document.getElementById('salesBreakdownEndDate'),
        sep: document.getElementById('salesBreakdownRangeSep'),
        refresh: document.getElementById('salesBreakdownRefresh'),
        rangeLabel: document.getElementById('salesBreakdownRangeLabel'),
        loading: document.getElementById('salesBreakdownLoading'),
        error: document.getElementById('salesBreakdownError'),
        centre: document.getElementById('bdCentre'),
        centreSub: document.getElementById('bdCentreSub'),
        legend: document.getElementById('salesBreakdownLegend'),
        insight: document.getElementById('salesBreakdownInsight')
    };
    let salesBreakdownSelling = [];

    function syncSalesBreakdownInputs() {
        const isCustom = salesBreakdownEls.range.value === 'custom_range';
        salesBreakdownEls.startDate.style.display = isCustom ? 'inline-block' : 'none';
        salesBreakdownEls.endDate.style.display = isCustom ? 'inline-block' : 'none';
        salesBreakdownEls.sep.style.display = isCustom ? 'inline' : 'none';
    }

    function renderSalesBreakdownLegend(categories) {
        const selling = categories.filter(function (c) { return c.amount > 0; });
        const empty   = categories.filter(function (c) { return c.amount <= 0; });

        let html = selling.map(function (c) {
            return '<div class="bd-legend-row">'
                 + '<span class="bd-dot" style="background:' + c.color + ';"></span>'
                 + '<span class="bd-name"><b>' + escapeHtml(c.label) + '</b>'
                 + '<em>' + c.units + (c.units === 1 ? ' unit' : ' units') + ' \u00b7 '
                 + c.orders + (c.orders === 1 ? ' order' : ' orders') + '</em></span>'
                 + '<span class="bd-amt">' + formatMoney(c.amount) + '</span>'
                 + '<span class="bd-share">' + c.share.toFixed(1) + '%</span>'
                 + '</div>';
        }).join('');

        // Empty categories are still worth seeing - a category selling nothing
        // all period is a question - but they are named compactly rather than
        // taking a full row each and crowding out the ones that did sell.
        if (empty.length) {
            html += '<div class="bd-empty-note"><strong>' + empty.length + '</strong> '
                  + (empty.length === 1 ? 'category' : 'categories') + ' sold nothing: '
                  + empty.map(function (c) { return escapeHtml(c.label); }).join(', ') + '.</div>';
        }
        if (!selling.length && !empty.length) { html = ''; }
        salesBreakdownEls.legend.innerHTML = html;
    }

    function renderSalesBreakdownInsight(d) {
        const bits = [];
        const t = d.totals || {};
        const ins = d.insights || {};
        const cmp = d.compare || {};

        const delta = formatDelta(cmp.delta_pct);
        if (delta !== null) {
            bits.push('<strong>' + delta + '</strong> vs the preceding ' + d.day_count
                    + (d.day_count === 1 ? ' day' : ' days') + ' (' + formatMoney(cmp.revenue) + ').');
        }
        if (ins.top_label) {
            bits.push('<strong>' + escapeHtml(ins.top_label) + '</strong> is the biggest category at '
                    + formatMoney(ins.top_amount) + ' (' + ins.top_share.toFixed(1) + '%).');
        }
        if (t.selling > 0) {
            // Concentration is the thing a donut alone will not tell you:
            // three categories carrying 90% is a very different business from
            // fourteen carrying it evenly.
            bits.push('Top 3 categories carry <strong>' + t.top3_share.toFixed(1) + '%</strong> of revenue; '
                    + t.selling + ' of ' + t.categories + ' categories sold anything.');
        }
        if (ins.busiest_label) {
            bits.push('Busiest ' + (d.granularity === 'day' ? 'day' : d.granularity) + ': <strong>'
                    + escapeHtml(ins.busiest_label) + '</strong> (' + formatMoney(ins.busiest_total) + ').');
        }
        salesBreakdownEls.insight.innerHTML = bits.join(' ');
    }

    let salesBreakdownRequestSeq = 0;

    function fetchSalesBreakdown() {
        const range = salesBreakdownEls.range.value;
        const params = new URLSearchParams({ range: range });
        if (range === 'custom_range') {
            params.set('start_date', salesBreakdownEls.startDate.value);
            params.set('end_date', salesBreakdownEls.endDate.value);
        }

        const seq = ++salesBreakdownRequestSeq;
        salesBreakdownEls.loading.style.display = 'flex';
        salesBreakdownEls.error.style.display = 'none';

        fetch('<?= WEB_ROOT ?>public/authenticated/api/sales_breakdown.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(r => r.json())
        .then(d => {
            if (seq !== salesBreakdownRequestSeq) { return; }
            salesBreakdownEls.loading.style.display = 'none';
            if (!d || !d.success) {
                salesBreakdownEls.error.style.display = 'flex';
                salesBreakdownEls.error.textContent = (d && d.message) ? d.message : 'Could not load the breakdown.';
                salesBreakdownEls.legend.innerHTML = '';
                salesBreakdownEls.insight.innerHTML = '';
                return;
            }

            const categories = d.categories || [];
            const totals = d.totals || {};
            salesBreakdownSelling = categories.filter(function (c) { return c.amount > 0; });

            // Donut: only categories that sold. Real amounts, so the slices
            // add up to the figure printed in the middle.
            donutChart.data.labels = salesBreakdownSelling.map(c => c.label);
            donutChart.data.datasets[0].data = salesBreakdownSelling.map(c => c.amount);
            donutChart.data.datasets[0].backgroundColor = salesBreakdownSelling.map(c => c.color);
            donutChart.update();

            // Bars: one dataset per selling category, same colours, same order.
            const points = d.points || [];
            stackedChart.data.labels = points.map(p => p.label);
            stackedChart.data.datasets = salesBreakdownSelling.map(function (c) {
                return {
                    label: c.label,
                    data: points.map(p => (p.categories && p.categories[c.id]) || 0),
                    backgroundColor: c.color,
                    borderColor: c.color,
                    borderWidth: 0
                };
            });
            stackedChart.update();

            salesBreakdownEls.centre.textContent = formatMoney(totals.revenue);
            salesBreakdownEls.centreSub.textContent = totals.units
                ? totals.units + (totals.units === 1 ? ' unit' : ' units')
                : '\u00a0';
            salesBreakdownEls.rangeLabel.textContent = d.range_label + '  \u2022  by ' + d.granularity
                + '  \u2022  ' + d.basis
                + '  \u2022  updated ' + new Date().toLocaleTimeString();

            renderSalesBreakdownLegend(categories);
            renderSalesBreakdownInsight(d);
        })
        .catch(function () {
            if (seq !== salesBreakdownRequestSeq) { return; }
            salesBreakdownEls.loading.style.display = 'none';
            salesBreakdownEls.error.style.display = 'flex';
            salesBreakdownEls.error.textContent = 'Could not load the breakdown. Please try again.';
        });
    }

    const debouncedSalesBreakdownFetch = debounce(fetchSalesBreakdown, 350);

    salesBreakdownEls.range.addEventListener('change', function () {
        syncSalesBreakdownInputs();
        fetchSalesBreakdown();
    });
    salesBreakdownEls.startDate.addEventListener('change', debouncedSalesBreakdownFetch);
    salesBreakdownEls.endDate.addEventListener('change', debouncedSalesBreakdownFetch);
    salesBreakdownEls.refresh.addEventListener('click', fetchSalesBreakdown);

    syncSalesBreakdownInputs();
    fetchSalesBreakdown();

    window.__reportRefreshers = window.__reportRefreshers || {};
    window.__reportRefreshers['sales-breakdown'] = fetchSalesBreakdown;

    // ===== Sales Growth Rate - live from api/sales_growth.php ==============
    // The line used to plot a growth PERCENTAGE per month, computed as
    // `last > 0 ? ... : 100`. With no prior-year data that drew a flat line at
    // exactly 100% for every month - a fabricated shape. It now plots actual
    // monthly revenue, which is a real number regardless of whether there is
    // anything to compare it against; the year-on-year comparison, where one
    // exists, is drawn as a second line.
    const salesGrowthRateCtx = document.getElementById('salesGrowthRateChart').getContext('2d');
    const salesGrowthRateChart = new Chart(salesGrowthRateCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Revenue',
                data: [],
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointStyle: 'circle',
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#3b82f6',
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            aspectRatio: 2,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#17324a',
                        maxRotation: 45
                    },
                    grid: {
                        color: 'rgba(23,72,113,0.14)'
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#17324a',
                        // Pesos now, not '%': the series is revenue. The old
                        // '%' suffix was labelling a fabricated growth figure.
                        callback: function (value) { return formatMoneyShort(value); }
                    },
                    grid: {
                        color: 'rgba(23,72,113,0.14)'
                    }
                }
            }
        }
    });

    // ---- live loading for the Sales Growth Rate card ----------------------

    const salesGrowthEls = {
        refresh: document.getElementById('salesGrowthRefresh'),
        yoy: document.getElementById('sgYoy'),
        yoyLabel: document.getElementById('sgYoyLabel'),
        icon: document.getElementById('sgIcon'),
        momWrap: document.getElementById('sgMomWrap'),
        momArrow: document.getElementById('sgMomArrow'),
        mom: document.getElementById('sgMom'),
        momLabel: document.getElementById('sgMomLabel'),
        figures: document.getElementById('sgFigures'),
        chartNote: document.getElementById('sgChartNote'),
        insight: document.getElementById('salesGrowthInsight'),
        loading: document.getElementById('salesGrowthLoading'),
        error: document.getElementById('salesGrowthError')
    };

    let salesGrowthRequestSeq = 0;

    function fetchSalesGrowth() {
        const seq = ++salesGrowthRequestSeq;
        salesGrowthEls.loading.style.display = 'flex';
        salesGrowthEls.error.style.display = 'none';

        fetch('<?= WEB_ROOT ?>public/authenticated/api/sales_growth.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(r => r.json())
        .then(d => {
            if (seq !== salesGrowthRequestSeq) { return; }
            salesGrowthEls.loading.style.display = 'none';
            if (!d || !d.success) {
                salesGrowthEls.error.style.display = 'flex';
                salesGrowthEls.error.textContent = (d && d.message) ? d.message : 'Could not load growth data.';
                return;
            }

            const h = d.headline || {};
            const c = d.context || {};
            const months = d.months || [];

            // --- year on year ---------------------------------------------
            // null means there is no prior-year baseline. Saying so is the
            // whole point; the old card printed "+100%" here instead.
            if (h.yoy_pct === null || h.yoy_pct === undefined) {
                salesGrowthEls.yoy.textContent = 'No baseline';
                salesGrowthEls.yoy.style.color = 'var(--admin-muted)';
                salesGrowthEls.yoyLabel.textContent = 'nothing recorded in ' + d.last_year;
                salesGrowthEls.icon.textContent = '\ud83d\udcca';
            } else {
                const up = h.yoy_pct >= 0;
                salesGrowthEls.yoy.textContent = (up ? '+' : '') + h.yoy_pct.toFixed(1) + '%';
                salesGrowthEls.yoy.style.color = up ? '#22c55e' : '#ef4444';
                salesGrowthEls.yoyLabel.textContent = 'vs ' + d.last_year + ' (same months)';
                salesGrowthEls.icon.textContent = up ? '\ud83d\udcc8' : '\ud83d\udcc9';
            }

            // --- month on month -------------------------------------------
            if (h.mom_pct === null || h.mom_pct === undefined) {
                salesGrowthEls.momWrap.style.color = 'var(--admin-muted)';
                salesGrowthEls.momArrow.textContent = '';
                salesGrowthEls.mom.textContent = 'No baseline';
                salesGrowthEls.momLabel.textContent = 'nothing in ' + h.previous_month;
            } else {
                const up = h.mom_pct >= 0;
                salesGrowthEls.momWrap.style.color = up ? '#22c55e' : '#ef4444';
                salesGrowthEls.momArrow.textContent = up ? '\u2197' : '\u2198';
                salesGrowthEls.mom.textContent = (up ? '+' : '') + h.mom_pct.toFixed(1) + '%';
                salesGrowthEls.momLabel.textContent = h.current_month + ' vs ' + h.previous_month;
            }

            // --- the pesos behind the percentages --------------------------
            salesGrowthEls.figures.innerHTML =
                  '<div><span>' + d.this_year + ' to date</span><b>' + formatMoney(h.ytd_this_year) + '</b></div>'
                + '<div><span>' + d.last_year + ' same months</span><b>' + formatMoney(h.ytd_last_year) + '</b></div>'
                + '<div><span>' + escapeHtml(h.current_month) + '</span><b>' + formatMoney(h.current_total) + '</b></div>'
                + '<div><span>Avg trading month</span><b>' + formatMoney(c.avg_month) + '</b></div>';

            // --- the trend line --------------------------------------------
            const hasLastYear = months.some(function (m) { return m.last_year > 0; });
            salesGrowthRateChart.data.labels = months.map(m => m.short);
            salesGrowthRateChart.data.datasets = [{
                label: d.this_year + ' revenue',
                data: months.map(m => m.this_year),
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3, fill: true, tension: 0.4,
                pointBackgroundColor: '#3b82f6', pointBorderColor: '#3b82f6',
                pointRadius: 4, pointHoverRadius: 6
            }];
            if (hasLastYear) {
                salesGrowthRateChart.data.datasets.push({
                    label: d.last_year + ' revenue',
                    data: months.map(m => m.last_year),
                    borderColor: '#94a3b8',
                    backgroundColor: 'transparent',
                    borderWidth: 2, borderDash: [5, 4], fill: false, tension: 0.4,
                    pointRadius: 3, pointHoverRadius: 5
                });
            }
            salesGrowthRateChart.options.plugins.legend.display = hasLastYear;
            salesGrowthRateChart.update();

            salesGrowthEls.chartNote.textContent = 'Monthly revenue, January through '
                + d.through + (hasLastYear ? ', against the same months last year.' : '. Cancelled orders excluded.');

            // --- insight ---------------------------------------------------
            const bits = [];
            if (!h.has_baseline) {
                bits.push('There is no ' + d.last_year + ' data to compare against, so no year-on-year percentage is shown '
                        + '\u2014 the first full year of trading has no "last year".');
            }
            if (c.best_month) {
                bits.push('Best month so far: <strong>' + escapeHtml(c.best_month) + '</strong> ('
                        + formatMoney(c.best_total) + ').');
            }
            if (c.active_months > 0) {
                bits.push(c.orders_ytd + ' orders across ' + c.active_months
                        + (c.active_months === 1 ? ' trading month' : ' trading months')
                        + ', averaging ' + formatMoney(c.avg_month) + ' a month.');
            }
            if (c.active_months >= 2) {
                // Arithmetic, not a forecast - said plainly so it is not read
                // as one.
                bits.push('At that rate a full year would be about <strong>' + formatMoney(c.run_rate)
                        + '</strong> (simple run-rate, not a forecast).');
            }
            salesGrowthEls.insight.innerHTML = bits.join(' ');
        })
        .catch(function () {
            if (seq !== salesGrowthRequestSeq) { return; }
            salesGrowthEls.loading.style.display = 'none';
            salesGrowthEls.error.style.display = 'flex';
            salesGrowthEls.error.textContent = 'Could not load growth data. Please try again.';
        });
    }

    salesGrowthEls.refresh.addEventListener('click', fetchSalesGrowth);
    fetchSalesGrowth();

    window.__reportRefreshers = window.__reportRefreshers || {};
    window.__reportRefreshers['growth-rate'] = fetchSalesGrowth;

    /* ===== Product Performance: cumulative units, best vs worst =============
     *
     * Built here, inside the same DOMContentLoaded block as every other chart
     * on this page, because the launcher's resizeCharts() walks the card for
     * existing Chart instances when it moves it into the modal. A chart built
     * lazily on open would not be there to find, and the panel would come up
     * blank on the first click.
     *
     * The data is inlined by PHP (the date filter is a GET form, so the page
     * reloads anyway) - see the long note beside the query.
     */
    (function () {
        var canvas = document.getElementById('productPerfChart');
        if (!canvas || typeof Chart === 'undefined') { return; }

        var perf = <?= json_encode($perfSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        if (!perf || !perf.labels || !perf.labels.length) { return; }

        // Read the palette off the card's own custom properties rather than
        // repeating the hexes here, so the two themes stay defined in exactly
        // one place (the <style> block beside the card) and cannot drift.
        var cardEl = canvas.closest('[data-report-card="product-performance"]');
        var css = getComputedStyle(cardEl);
        function token(name, fallback) {
            var v = css.getPropertyValue(name).trim();
            return v || fallback;
        }
        var BEST_HUES = [
            token('--pp-c1', '#3987e5'), token('--pp-c2', '#d95926'),
            token('--pp-c3', '#199e70'), token('--pp-c4', '#c98500'),
            token('--pp-c5', '#d55181')
        ];
        var MUTED = token('--pp-muted', '#a9b7c6');
        var GRID  = token('--pp-grid', 'rgba(242,243,244,.10)');
        var INK_2 = token('--admin-muted', '#a9b7c6');

        // Axis labels. A 44-day range would collide if every date were drawn,
        // so Chart.js is given a tick budget and the full date goes in the
        // tooltip title instead.
        var labelText = perf.labels.map(function (lb) {
            if (perf.bucket === 'day') {
                var d = new Date(lb + 'T00:00:00');
                return isNaN(d) ? lb : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
            }
            if (perf.bucket === 'month') {
                var m = new Date(lb + '-01T00:00:00');
                return isNaN(m) ? lb : m.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
            }
            return lb;
        });

        /* A one-bucket range (filter start == end) plots a single point per
           series, and a line through one point draws nothing at all - the
           panel came up blank. Points are normally suppressed because 44 of
           them per line is a smear, so they are turned back on for exactly
           this case. */
        var SINGLE = perf.labels.length === 1;
        var DOT = SINGLE ? 4 : 0;

        var datasets = [];
        // Fixed order, assigned by rank: slot 1 to the top seller, and never
        // cycled. A filter that changes the list does not repaint the
        // survivors into different colours.
        (perf.best || []).forEach(function (s, i) {
            datasets.push({
                label: s.name,
                data: s.data,
                borderColor: BEST_HUES[i % BEST_HUES.length],
                backgroundColor: BEST_HUES[i % BEST_HUES.length],
                borderWidth: 2,
                tension: 0.25,          // a hint of smoothing, not a wave
                pointRadius: DOT,       // suppressed unless the range is one bucket
                pointHoverRadius: 5,
                pointHitRadius: 14,     // generous target; the mark stays thin
                order: 1,
                __group: 'best'
            });
        });
        (perf.worst || []).forEach(function (s, i) {
            datasets.push({
                label: s.name,
                data: s.data,
                borderColor: MUTED,
                backgroundColor: MUTED,
                borderWidth: 1.5,
                borderDash: [5, 4],
                tension: 0,
                pointRadius: DOT,
                pointHoverRadius: 5,
                pointHitRadius: 14,
                order: 2,               // drawn under the best sellers
                __group: 'worst'
            });
        });

        var perfChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: labelText, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // Crosshair + shared tooltip: on a cumulative comparison the
                // question is always "where is everyone at this date", not
                // "what is this one point".
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // Identity comes from the markup legend beside the chart,
                    // which also carries each product's total.
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(11,18,32,.94)',
                        titleColor: '#f2f3f4',
                        bodyColor: '#f2f3f4',
                        borderColor: 'rgba(242,243,244,.18)',
                        borderWidth: 1,
                        padding: 10,
                        titleFont: { size: 12 },
                        bodyFont: { size: 11.5 },
                        callbacks: {
                            title: function (items) {
                                // The real bucket key, not the shortened tick.
                                var i = items.length ? items[0].dataIndex : 0;
                                return perf.labels[i] || '';
                            },
                            label: function (ctx) {
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + ' sold';
                            }
                        },
                        // Zero-sellers would otherwise fill the tooltip with
                        // five identical "0 sold" rows on every hover.
                        filter: function (ctx) {
                            return ctx.dataset.__group !== 'worst' || ctx.parsed.y > 0;
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: INK_2, font: { size: 10 }, maxRotation: 0,
                                 autoSkip: true, maxTicksLimit: 8 }
                    },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Units sold (running total)',
                                 color: INK_2, font: { size: 10.5 } },
                        grid: { color: GRID, drawBorder: false },
                        ticks: { color: INK_2, font: { size: 10 }, precision: 0 }
                    }
                }
            }
        });

        /* Legend rows toggle their series. Delegated from the card so it keeps
           working after the launcher re-parents the whole node into the modal
           and back out again. */
        cardEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.pp-legend-item');
            if (!btn) { return; }
            var ref = (btn.getAttribute('data-pp-series') || '').split(':');
            var group = ref[0], idx = parseInt(ref[1], 10);
            if (!group || isNaN(idx)) { return; }

            // The markup legend lists best then worst, and the datasets were
            // pushed in that same order, so the offset is just the number of
            // best-seller series.
            var di = group === 'best' ? idx : (perf.best || []).length + idx;
            var meta = perfChart.getDatasetMeta(di);
            if (!meta) { return; }
            meta.hidden = meta.hidden === null ? true : !meta.hidden;
            btn.setAttribute('aria-pressed', meta.hidden ? 'false' : 'true');
            perfChart.update();
        });

        // Repaint on a theme switch: the hexes come from CSS custom properties,
        // so they are only read once at construction. Without this, flipping to
        // light mode leaves dark-surface steps on a near-white card.
        var themeHost = document.documentElement;
        new MutationObserver(function () {
            var c2 = getComputedStyle(cardEl);
            var reread = function (n, f) { return c2.getPropertyValue(n).trim() || f; };
            var hues = ['--pp-c1','--pp-c2','--pp-c3','--pp-c4','--pp-c5'].map(function (n, i) {
                return reread(n, BEST_HUES[i]);
            });
            var muted = reread('--pp-muted', MUTED);
            perfChart.data.datasets.forEach(function (ds, i) {
                var col = ds.__group === 'best' ? hues[i % hues.length] : muted;
                ds.borderColor = col;
                ds.backgroundColor = col;
            });
            var ink2 = reread('--admin-muted', INK_2);
            perfChart.options.scales.x.ticks.color = ink2;
            perfChart.options.scales.y.ticks.color = ink2;
            perfChart.options.scales.y.title.color = ink2;
            perfChart.options.scales.y.grid.color = reread('--pp-grid', GRID);
            perfChart.update();
        }).observe(themeHost, { attributes: true, attributeFilter: ['data-theme'] });
    })();
});
</script>

<script>
// Report launcher + modal. Runs after the Chart.js block above, so every chart
// already exists by the time a card can be moved into the modal.
(function () {
  var modal   = document.getElementById('reportModal');
  var body    = document.getElementById('reportModalBody');
  var titleEl = document.getElementById('reportModalTitle');
  if (!modal || !body) return;

  // Re-parent to <body>: position:fixed is measured against the nearest
  // transformed ancestor, and the admin shell is free to animate its wrappers.
  if (modal.parentNode !== document.body) document.body.appendChild(modal);

  var openKey = null;        // which report is showing
  var anchor  = null;        // marker left behind in the stash
  var lastTrigger = null;    // tile to hand focus back to

  // The charts are built once, inside hidden stash wrappers, so their canvases
  // measure 0x0. Chart.js only re-measures on a resize, so nudge each chart once
  // the card is visible inside the modal - otherwise you get a blank panel.
  function resizeCharts(scope) {
    if (typeof Chart === 'undefined' || !Chart.getChart) return;
    Array.prototype.forEach.call(scope.querySelectorAll('canvas'), function (cv) {
      var chart = Chart.getChart(cv);
      if (chart) chart.resize();
    });
  }

  function close() {
    if (!openKey) return;
    var card = body.firstElementChild;
    // put the card back exactly where it came from, so its id-based JS
    // (the Sales Overview fetch, for one) keeps finding the same nodes
    if (card && anchor && anchor.parentNode) anchor.parentNode.replaceChild(card, anchor);
    anchor = null;
    openKey = null;
    modal.classList.remove('is-open');
    modal.hidden = true;
    document.body.classList.remove('report-modal-open');
    if (lastTrigger) { lastTrigger.focus(); lastTrigger = null; }
  }

  function open(key, trigger) {
    var card = document.querySelector('[data-report-card="' + key + '"]');
    if (!card) return;
    if (openKey) close();

    anchor = document.createComment('report:' + key);
    card.parentNode.replaceChild(anchor, card);
    body.appendChild(card);
    body.scrollTop = 0;

    titleEl.textContent = trigger ? trigger.querySelector('.tile-name').textContent : 'Report';
    openKey = key;
    lastTrigger = trigger || null;

    modal.hidden = false;
    modal.classList.add('is-open');
    document.body.classList.add('report-modal-open');

    // one frame later the panel has real dimensions for Chart.js to measure
    requestAnimationFrame(function () {
      resizeCharts(body);
      var closeBtn = modal.querySelector('.report-modal-close');
      if (closeBtn) closeBtn.focus();
    });

    // Cards that load their own data get a fresh read on open, so a report
    // left open in a tab for an hour is not showing hour-old numbers. Cards
    // with no refresher registered are unaffected.
    var refresh = (window.__reportRefreshers || {})[key];
    if (typeof refresh === 'function') {
      try { refresh(); } catch (e) { /* a stale card beats a broken modal */ }
    }
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-open-report]'), function (tile) {
    tile.addEventListener('click', function () { open(tile.getAttribute('data-open-report'), tile); });
  });

  // backdrop and the X share one hook
  Array.prototype.forEach.call(modal.querySelectorAll('[data-report-close]'), function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && openKey) close();
  });

  // Keep focus inside the panel while it is open.
  modal.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab' || !openKey) return;
    var focusable = modal.querySelectorAll('button, [href], select, input, [tabindex]:not([tabindex="-1"])');
    if (!focusable.length) return;
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  // Deep link: ...?view=analytics&report=sales-overview opens that report, and
  // the Sales Report card's Print button prints whatever the modal is showing.
  var wanted = new URLSearchParams(window.location.search).get('report');
  if (wanted) {
    var tile = document.querySelector('[data-open-report="' + wanted.replace(/"/g, '') + '"]');
    if (tile) open(wanted, tile);
  }
})();
</script>

<style>
.status-badge {
  background: #6b7280;
  color: #fff;
}
.status-preparing { background: #f59e0b; }
.status-packed { background: #3b82f6; }
.status-ready { background: #a77693; }
.status-shipped { background: #a77693; }
.status-completed { background: #10b981; }
.status-cancelled { background: #ef4444; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
