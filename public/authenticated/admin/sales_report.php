<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
// The printed masthead reads the company block from here - the same single
// source the inventory report and the delivery notes use, so an address
// change stays a one-file edit.
require_once __DIR__ . '/../../../config/business_info.php';
require_role(['admin', 'manager']);

$pageTitle = 'Sales Report - TechWorld Admin';
$isAdminTheme = true;
$activeNav = 'reports';

// Daily is the default view: this report is read at the end of a trading day
// to close it out (see the End of Day card below), so the day is what it should
// open on. The longer periods stay one pick away in the Period select.
$SR_PERIODS = ['daily', 'weekly', 'monthly', 'semi-annually', 'annually'];
$period = (string)($_GET['period'] ?? 'daily');
// Whitelisted, not just defaulted: $period chooses the GROUP BY expression
// below, and an unrecognised value used to leave it empty and break the query.
if (!in_array($period, $SR_PERIODS, true)) { $period = 'daily'; }

/**
 * The date range a period implies, ending today.
 *
 * Picking "Weekly" and being shown a month of data is the report disagreeing
 * with its own filter, so the range follows the unit: a week is 7 days, a
 * semi-annual period is 6 months. Ranges are inclusive of both ends, which is
 * why the subtraction is one short (7 days back from today is 8 days of rows).
 *
 * @return array{0:string,1:string} [start, end] as Y-m-d
 */
function sr_period_range(string $period): array
{
    $end = new DateTimeImmutable('today');
    switch ($period) {
        case 'daily':         $start = $end; break;
        case 'weekly':        $start = $end->modify('-6 days'); break;
        case 'semi-annually': $start = $end->modify('-6 months')->modify('+1 day'); break;
        case 'annually':      $start = $end->modify('-1 year')->modify('+1 day'); break;
        case 'monthly':
        default:              $start = $end->modify('-1 month')->modify('+1 day'); break;
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/**
 * How the printed report presents itself for the period being viewed.
 *
 * The screen can afford to say "Sales Report" and leave the Period select to
 * explain the rest, because the select is right there. Paper cannot: a sheet
 * of monthly figures headed "SALES REPORT" over a column of dates is a sheet
 * that has to be worked out rather than read. So the printed document names
 * its own unit in three places that have to agree - the masthead title, the
 * period line under it, and the first column's heading - and all three come
 * from here so they cannot drift apart.
 *
 * 'title'  - masthead heading, e.g. MONTHLY SALES REPORT
 * 'range'  - the period line, written in the unit ("October 2026", not
 *            "Oct 1, 2026 - Oct 31, 2026", which is the same fact spelled
 *            out in the wrong grain)
 * 'column' - heading for the first column, whose cells are already formatted
 *            per period further down ("Week of Oct 5", "H1 2026", ...)
 * 'unit'   - singular noun for the sign-off note
 *
 * @return array{title:string,range:string,column:string,unit:string}
 */
function sr_print_meta(string $period, string $startDate, string $endDate): array
{
    $s = strtotime($startDate);
    $e = strtotime($endDate);
    // A range that will not parse is not worth guessing at - fall back to the
    // generic heading rather than printing a masthead built from false dates.
    if ($s === false || $e === false) {
        return ['title' => 'SALES REPORT', 'range' => '', 'column' => 'Period', 'unit' => 'period'];
    }

    // U+2013 EN DASH, as in the reference document. A hyphen reads as a minus
    // sign next to numerals, which is the wrong signal on a page of figures.
    $dash = ' – ';

    switch ($period) {
        case 'annually':
            $a = date('Y', $s);
            $b = date('Y', $e);
            return [
                'title'  => 'ANNUAL SALES REPORT',
                'range'  => $a === $b ? $a : $a . $dash . $b,
                'column' => 'Year',
                'unit'   => 'year',
            ];

        case 'semi-annually':
            // Halves, not months: H1 is Jan-Jun, H2 is Jul-Dec, which is the
            // same split the table's own cells use.
            $half = static fn(int $ts): string => 'H' . (int)ceil((int)date('n', $ts) / 6) . ' ' . date('Y', $ts);
            $a = $half($s);
            $b = $half($e);
            return [
                'title'  => 'SEMI-ANNUAL SALES REPORT',
                'range'  => $a === $b ? $a : $a . $dash . $b,
                'column' => 'Half',
                'unit'   => 'half-year',
            ];

        case 'monthly':
            $a = date('F Y', $s);
            $b = date('F Y', $e);
            return [
                'title'  => 'MONTHLY SALES REPORT',
                'range'  => $a === $b ? $a : $a . $dash . $b,
                'column' => 'Month',
                'unit'   => 'month',
            ];

        case 'weekly':
        case 'daily':
        default:
            // Days and weeks are both read as a span of dates, so they share a
            // range format; only the column heading and title differ. The year
            // is printed once, on the end date, unless the span crosses one.
            if (date('Y-m-d', $s) === date('Y-m-d', $e)) {
                $range = date('M j, Y', $s);
            } elseif (date('Y', $s) === date('Y', $e)) {
                $range = date('M j', $s) . $dash . date('M j, Y', $e);
            } else {
                $range = date('M j, Y', $s) . $dash . date('M j, Y', $e);
            }
            return $period === 'weekly'
                ? ['title' => 'WEEKLY SALES REPORT', 'range' => $range, 'column' => 'Week',  'unit' => 'week']
                : ['title' => 'DAILY SALES REPORT',  'range' => $range, 'column' => 'Date',  'unit' => 'day'];
    }
}

// An explicit range in the URL always wins - the picker has to stay able to
// override the period's default. Only when one is absent does the period
// decide, which is also what makes a bare ?period=weekly land on 7 days.
[$srStart, $srEnd] = sr_period_range($period);
$startDate = $_GET['start_date'] ?? $srStart;
$endDate   = $_GET['end_date']   ?? $srEnd;

try {
    $pdo = getConnection();
    
    // Build query based on period
    $dateFormat = '';
    $groupBy = '';
    
    switch($period) {
        case 'daily':
            $dateFormat = '%Y-%m-%d';
            $groupBy = 'DATE(created_at)';
            break;
        case 'weekly':
            $dateFormat = '%Y-%u';
            $groupBy = 'YEAR(created_at), WEEK(created_at)';
            break;
        case 'monthly':
            $dateFormat = '%Y-%m';
            $groupBy = 'YEAR(created_at), MONTH(created_at)';
            break;
        case 'semi-annually':
            $dateFormat = '%Y';
            $groupBy = 'YEAR(created_at), CASE WHEN MONTH(created_at) <= 6 THEN 1 ELSE 2 END';
            break;
        case 'annually':
            $dateFormat = '%Y';
            $groupBy = 'YEAR(created_at)';
            break;
    }
    
    // Check if payments table exists
    $tablesStmt = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'payments'");
    $tablesStmt->execute([$pdo->query('SELECT DATABASE()')->fetchColumn()]);
    $hasPaymentsTable = $tablesStmt->rowCount() > 0;
    
    // Get sales data grouped by period
    if ($hasPaymentsTable) {
        // Use payments table for accurate payment data
        // $groupBy's column names are bare ('created_at', not 'o.created_at') because the
        // fallback branch below queries `orders` with no alias. Here we JOIN payments, which
        // has its own created_at column, so the bare name is ambiguous - qualify it for this
        // branch only (this used to throw SQLSTATE 1052 and silently zero out every total).
        $groupByQualified = str_replace('created_at', 'o.created_at', $groupBy);
        // Old query checked p.payment_method for 'home_delivery'/'store_pickup'/'online', which
        // never occur there - those fulfillment values live in p.payment_type. Live data shows
        // p.payment_method is only ever populated for online payments ('online'); in-store/COD
        // orders leave it blank and are only distinguished by p.payment_type. So: in-store pickup
        // and cash-on-delivery are read from payment_type, and online is read from payment_method.
        $salesStmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(o.created_at, ?) as period,
                SUM(CASE WHEN p.payment_type = 'store_pickup' THEN p.amount ELSE 0 END) as instore_sales,
                SUM(CASE WHEN p.payment_type = 'home_delivery' THEN p.amount ELSE 0 END) as cod_sales,
                SUM(CASE WHEN p.payment_method = 'online' THEN p.amount ELSE 0 END) as online_sales,
                SUM(p.amount) as total_sales,
                COUNT(DISTINCT o.id) as order_count
            FROM orders o
            INNER JOIN payments p ON o.id = p.order_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            GROUP BY {$groupByQualified}
            ORDER BY period ASC
        ");
    } else {
        // Fallback to orders table payment method logic
        $salesStmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(created_at, ?) as period,
                SUM(CASE WHEN payment_method IN ('online','gcash','paymaya') OR payment_method IS NULL THEN total_amount ELSE 0 END) as online_sales,
                SUM(CASE WHEN payment_method = 'cod' THEN total_amount ELSE 0 END) as cod_sales,
                SUM(CASE WHEN payment_method = 'store' OR payment_method = 'onsite' THEN total_amount ELSE 0 END) as store_sales,
                SUM(total_amount) as total_sales,
                COUNT(*) as order_count
            FROM orders 
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY {$groupBy}
            ORDER BY period ASC
        ");
    }
    $salesStmt->execute([$dateFormat, $startDate, $endDate]);
    $salesData = $salesStmt->fetchAll();
    
    // Calculate totals
    $totalInstore = 0;
    $totalCod = 0;
    $totalOnline = 0;
    $totalSales = 0;
    $totalOrders = 0;
    
    foreach($salesData as $row) {
        $totalInstore += (float)$row['instore_sales'];
        $totalCod += (float)$row['cod_sales'];
        $totalOnline += (float)$row['online_sales'];
        $totalSales += (float)$row['total_sales'];
        $totalOrders += (int)$row['order_count'];
    }
    
} catch (PDOException $e) {
    error_log("Sales report query error: " . $e->getMessage());
    $salesData = [];
    $totalInstore = 0;
    $totalCod = 0;
    $totalOnline = 0;
    $totalSales = 0;
    $totalOrders = 0;
}

/* ---------------------------------------------------------------------------
   Line items for the printed report - every period, not just daily.

   The period table above groups by period, so printed it is a handful of
   aggregate rows with a Total underneath restating them. The reference
   document this report follows is a list of what actually happened, so every
   period prints one: one row per order placed inside the range.

   Daily and the longer periods therefore print the SAME sheet, which is the
   point - a weekly, monthly, semi-annual or annual report is the daily sheet
   over a longer range. The only difference is the first column, which carries
   a date as well as a time once the range covers more than one day.

   The cost is honest: an annual range reads a year of orders and prints a row
   for each, so the sheet runs to many pages. That is what the range asks for.

   INNER JOIN on the payments aggregate, deliberately: it mirrors the period
   query above exactly, so SUM(paid) down this column equals $totalSales. A
   signed document whose line items do not add up to its own stated total is
   worse than no document, and a LEFT JOIN here would do exactly that for any
   order with no payment row.
   --------------------------------------------------------------------------- */
$srLineItems = [];
$srLineTotals = ['orders' => 0, 'items' => 0, 'amount' => 0.0];
$srLineError = null;

try {
    $pdo = $pdo ?? getConnection();

    if (!empty($hasPaymentsTable)) {
        $liStmt = $pdo->prepare("
            SELECT
                o.id,
                o.invoice_no,
                o.created_at,
                o.status,
                o.total_amount,
                u.firstname,
                u.lastname,
                COALESCE(it.qty, 0) AS item_count,
                pay.paid,
                pay.instore,
                pay.cod,
                pay.online
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN (
                SELECT order_id, SUM(quantity) AS qty
                FROM order_items
                GROUP BY order_id
            ) it ON it.order_id = o.id
            INNER JOIN (
                SELECT order_id,
                       SUM(amount) AS paid,
                       SUM(CASE WHEN payment_type   = 'store_pickup'  THEN amount ELSE 0 END) AS instore,
                       SUM(CASE WHEN payment_type   = 'home_delivery' THEN amount ELSE 0 END) AS cod,
                       SUM(CASE WHEN payment_method = 'online'        THEN amount ELSE 0 END) AS online,
                       MAX(payment_type)   AS ptype,
                       MAX(payment_method) AS pmethod
                FROM payments
                GROUP BY order_id
            ) pay ON pay.order_id = o.id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            ORDER BY o.created_at ASC, o.id ASC
        ");
    } else {
        // Same fallback the period query uses: no payments table, so the
        // channel is read off orders.payment_method and the amount is the
        // order total. Column names are aliased to match the branch above
        // so the markup below does not have to know which one ran.
        $liStmt = $pdo->prepare("
            SELECT
                o.id,
                o.invoice_no,
                o.created_at,
                o.status,
                o.total_amount,
                u.firstname,
                u.lastname,
                COALESCE(it.qty, 0) AS item_count,
                o.total_amount AS paid,
                CASE WHEN o.payment_method = 'onsite' THEN o.total_amount ELSE 0 END AS instore,
                CASE WHEN o.payment_method = 'cod'    THEN o.total_amount ELSE 0 END AS cod,
                0 AS online,
                CASE o.payment_method
                     WHEN 'cod'    THEN 'home_delivery'
                     WHEN 'onsite' THEN 'store_pickup'
                     ELSE NULL END AS ptype,
                o.payment_method AS pmethod
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN (
                SELECT order_id, SUM(quantity) AS qty
                FROM order_items
                GROUP BY order_id
            ) it ON it.order_id = o.id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            ORDER BY o.created_at ASC, o.id ASC
        ");
    }

    $liStmt->execute([$startDate, $endDate]);
    $srLineItems = $liStmt->fetchAll();

    foreach ($srLineItems as $li) {
        $srLineTotals['orders']++;
        $srLineTotals['items']  += (int)$li['item_count'];
        $srLineTotals['amount'] += (float)$li['paid'];
    }
} catch (PDOException $e) {
    // Printing a sheet that silently omits the range's orders is worse than
    // printing one that says it could not read them - the line is shown on
    // paper where the table would have been.
    error_log('Sales report line items query error: ' . $e->getMessage());
    $srLineItems = [];
    $srLineError = 'The orders for this period could not be read.';
}

/**
 * Which channel an order was paid through, for the printed line-item table.
 *
 * Reads the same three buckets the KPI cards and the period table use, so a
 * row's label and the totals it rolls into can never disagree.
 */
function sr_line_channel(array $row): string
{
    // Money first: an order split across channels is labelled by the one that
    // actually carries the amount, which is also the bucket it rolls into.
    if ((float)($row['online']  ?? 0) > 0) { return 'Online'; }
    if ((float)($row['cod']     ?? 0) > 0) { return 'Cash on Delivery'; }
    if ((float)($row['instore'] ?? 0) > 0) { return 'In-Store'; }

    // Nothing banked, but the payment row still records how the order was to
    // be settled. Live data carries completed orders at ₱0.00, and labelling
    // every one of them with a dash prints a column the reader cannot use.
    if ((string)($row['pmethod'] ?? '') === 'online') { return 'Online'; }
    switch ((string)($row['ptype'] ?? '')) {
        case 'home_delivery':  return 'Cash on Delivery';
        case 'store_pickup':   return 'In-Store';
        case 'online_payment': return 'Online';
    }
    return '—';
}

// Refund rate: completed refunds as a share of orders in the same range.
//
// Counted from return_requests, not from cancelled orders - a cancellation is
// an order that never completed, a refund is money returned on one that did,
// and reporting them as one number would overstate refunds. Only 'completed'
// returns of type 'refund' count; a pending request is not yet money back.
//
// The table ships in 40_support_and_service.sql but is guarded the same way the
// payments query above is, so an install without it degrades this one card
// instead of failing the page.
$refundCount  = 0;
$refundAmount = 0.0;
$refundRate   = null; // null = no orders in range, so no rate can be stated
$hasReturnsTable = false;
try {
    $rtStmt = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'return_requests'");
    $rtStmt->execute([$pdo->query('SELECT DATABASE()')->fetchColumn()]);
    $hasReturnsTable = $rtStmt->rowCount() > 0;

    if ($hasReturnsTable) {
        // Dated by the ORDER, not the return, so the rate divides two counts
        // drawn from the same window - refunds logged this month against orders
        // placed last month would otherwise inflate it.
        $refStmt = $pdo->prepare("
            SELECT COUNT(*) AS c, COALESCE(SUM(r.refund_amount), 0) AS amt
            FROM return_requests r
            INNER JOIN orders o ON o.id = r.order_id
            WHERE r.return_type = 'refund'
              AND r.status = 'completed'
              AND DATE(o.created_at) BETWEEN ? AND ?
        ");
        $refStmt->execute([$startDate, $endDate]);
        $refRow = $refStmt->fetch() ?: [];
        $refundCount  = (int)($refRow['c'] ?? 0);
        $refundAmount = (float)($refRow['amt'] ?? 0);
    }
    if ($totalOrders > 0) {
        $refundRate = round(($refundCount / $totalOrders) * 100, 1);
    }
} catch (PDOException $e) {
    error_log('Sales report refund query error: ' . $e->getMessage());
}

// Distinct order dates over a wide window, for the date-range picker's
// "has data" dot indicator under calendar days.
$activityDates = [];
try {
    $actStmt = $pdo->prepare("SELECT DISTINCT DATE(created_at) as d FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 YEAR)");
    $actStmt->execute();
    $activityDates = array_column($actStmt->fetchAll(), 'd');
} catch (PDOException $e) {
    error_log("Sales report activity-dates query error: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// End-of-day close.
//
// Everything above reads live rows, so the figures for a day keep moving while
// that day is still trading. A closing report is the opposite: the figures as
// they stood when the admin signed the day off. Those are frozen into
// daily_sales_reports by api/daily_sales_report.php - this block only reads
// what is needed to render the card.
//
// The card closes ONE day. When the range picker is on a single date that is
// the day it offers (so an admin reviewing yesterday can close yesterday);
// when the range spans several days there is no single day to close, so it
// falls back to today and says which day it means.
require_once __DIR__ . '/../../../config/sales_figures.php';

/**
 * The submit/amend form for one business day.
 *
 * A function because the same form appears in three places on the card (a day
 * not yet closed, an amendment behind a disclosure, and an amendment offered
 * because the day has drifted) and they must not drift apart. Nothing about
 * the day's money is posted - only the date, the admin's own cash count and
 * notes; api/daily_sales_report.php recomputes every figure it stores.
 */
function sr_eod_form(string $date, bool $amend, ?array $report, array $figures): string
{
    $cash = '';
    $notes = '';
    if ($amend && $report) {
        $cash  = $report['cash_counted'] === null ? '' : number_format((float)$report['cash_counted'], 2, '.', '');
        $notes = (string)($report['notes'] ?? '');
    }
    $label = $amend ? 'Save Amendment' : 'Submit End-of-Day Report';

    ob_start(); ?>
    <?php /* The data-eod-* attributes are what the confirmation dialog reads
             back to the admin. They are display values for the figures PHP
             already computed, plus the raw in-store amount so the variance
             against a cash count typed seconds earlier can be worked out
             without a round trip. The figures are NOT inputs - the API
             recomputes everything it stores - so nothing here is trusted on
             the way back in; it exists to be read before clicking Yes. */ ?>
    <form class="eod-form" data-eod-form method="post"
          action="<?= WEB_ROOT ?>public/authenticated/api/daily_sales_report.php?action=submit"
          data-eod-day="<?= htmlspecialchars(date('D, M j, Y', strtotime($date))) ?>"
          data-eod-total="<?= htmlspecialchars(number_format($figures['total_sales'], 2)) ?>"
          data-eod-orders="<?= htmlspecialchars(number_format($figures['order_count'])) ?>"
          data-eod-instore="<?= htmlspecialchars(number_format($figures['instore_sales'], 2, '.', '')) ?>"
          data-eod-instore-display="<?= htmlspecialchars(number_format($figures['instore_sales'], 2)) ?>"
          <?php if ($amend && $report): ?>
            data-eod-prev-total="<?= htmlspecialchars(number_format((float)$report['total_sales'], 2)) ?>"
            data-eod-prev-orders="<?= htmlspecialchars(number_format((int)$report['order_count'])) ?>"
          <?php endif; ?>
          data-eod-mode="<?= $amend ? 'amend' : 'submit' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="report_date" value="<?= htmlspecialchars($date) ?>">
      <?php if ($amend): ?><input type="hidden" name="amend" value="1"><?php endif; ?>

      <div class="eod-form-fields">
        <label class="eod-field">
          <span class="admin-form-label">Cash counted in drawer <small>(optional)</small></span>
          <input type="text" inputmode="decimal" name="cash_counted" class="admin-form-input"
                 placeholder="Leave blank if not counted" value="<?= htmlspecialchars($cash) ?>">
        </label>
        <label class="eod-field eod-field-wide">
          <span class="admin-form-label">Notes <small>(optional)</small></span>
          <input type="text" name="notes" class="admin-form-input" maxlength="2000"
                 placeholder="Anything worth recording about the day" value="<?= htmlspecialchars($notes) ?>">
        </label>
        <div class="eod-field eod-field-action">
          <button type="submit" class="action-btn admin-quick-link eod-submit-btn"><?= $label ?></button>
        </div>
      </div>
      <p class="eod-form-msg" data-eod-msg hidden></p>
    </form>
    <?php
    return (string)ob_get_clean();
}

$eodDate      = ($startDate === $endDate) ? $startDate : date('Y-m-d');
$eodIsToday   = ($eodDate === date('Y-m-d'));
$eodIsFuture  = ($eodDate > date('Y-m-d'));
$eodRangeWide = ($startDate !== $endDate);
$eodFigures   = ['instore_sales'=>0.0,'cod_sales'=>0.0,'online_sales'=>0.0,'total_sales'=>0.0,'order_count'=>0,'refund_count'=>0,'refund_amount'=>0.0];
$eodReport    = null;   // the submitted row for $eodDate, if the day is closed
$eodHistory   = [];     // recent submissions, newest first
$eodOpenDays  = [];     // past days that traded but were never closed
$hasEodTable  = false;

try {
    // $pdo is left unset if the connection itself failed further up, and this
    // block must degrade to an empty card rather than a fatal.
    if (!isset($pdo) || !($pdo instanceof PDO)) { throw new RuntimeException('no database connection'); }

    $eodTblStmt = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'daily_sales_reports'");
    $eodTblStmt->execute([$pdo->query('SELECT DATABASE()')->fetchColumn()]);
    $hasEodTable = $eodTblStmt->rowCount() > 0;

    $eodFigures = sfDayFigures($pdo, $eodDate);

    if ($hasEodTable) {
        $eodStmt = $pdo->prepare("
            SELECT r.*,
                   CONCAT_WS(' ', su.firstname, su.lastname) AS submitted_by_name,
                   CONCAT_WS(' ', au.firstname, au.lastname) AS amended_by_name
            FROM daily_sales_reports r
            LEFT JOIN users su ON su.id = r.submitted_by
            LEFT JOIN users au ON au.id = r.amended_by
            WHERE r.report_date = ? LIMIT 1
        ");
        $eodStmt->execute([$eodDate]);
        $eodReport = $eodStmt->fetch() ?: null;

        $histStmt = $pdo->query("
            SELECT r.*, CONCAT_WS(' ', su.firstname, su.lastname) AS submitted_by_name
            FROM daily_sales_reports r
            LEFT JOIN users su ON su.id = r.submitted_by
            ORDER BY r.report_date DESC
            LIMIT 14
        ");
        $eodHistory = $histStmt->fetchAll();

        // Days that took orders but were never closed. Today is excluded - it
        // is not late until it is over - and so is any day with no orders at
        // all, since there is nothing to report on a day the store took none.
        $openStmt = $pdo->query("
            SELECT DATE(o.created_at) AS d, COUNT(*) AS c
            FROM orders o
            LEFT JOIN daily_sales_reports r ON r.report_date = DATE(o.created_at)
            WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND DATE(o.created_at) < CURDATE()
              AND r.id IS NULL
            GROUP BY DATE(o.created_at)
            ORDER BY d DESC
        ");
        $eodOpenDays = $openStmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('Sales report end-of-day query error: ' . $e->getMessage());
}

// Has the day moved since it was signed off? Compared on the one figure that
// matters for that question - a late order changes the total - and shown as a
// prompt to amend rather than silently overwriting what was submitted.
$eodDrifted = $eodReport
    && (abs((float)$eodReport['total_sales'] - $eodFigures['total_sales']) >= 0.01
        || (int)$eodReport['order_count'] !== $eodFigures['order_count']);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<link rel="stylesheet" href="<?= asset_url('public/shared/css/date-range-picker.css') ?>">
<script src="<?= WEB_ROOT ?>public/shared/assets/js/date-range-picker.js" defer></script>

<main class="container" style="min-height: calc(100vh - 160px);">
  <style>

    @media (min-width: 1860px){
      main.container{position:relative;}
    }

    /* The admin theme caps every .container at 1200px; this report carries a wide
       table and a four-up summary row, so it gets its own wider frame. Needs
       body.admin in the selector to outrank `.admin .container`. */
    html body.admin main.container{max-width:min(1720px,96vw);}

    /* The date picker's popover hangs out of the filter card and down over the
       summary row. Raising the popover alone was not enough: z-index only
       orders elements against POSITIONED siblings, so an unpositioned card row
       later in the document still painted over it.

       So rather than chase whichever property in the card stack is forming its
       own layer, the two rows are given explicit positioned layers. The filter
       card outranks the summary row as a whole, which settles it for every
       descendant of either - popover included - however they are styled later. */
    .report-header{position:relative;z-index:20;}
    .summary-cards{position:relative;z-index:1;}

    /* The four summary cards stay on one evenly-spaced row: fixed 4-up tracks,
       not auto-fit, so a long peso figure can never push one onto a second line.
       minmax(0,1fr) lets the numbers shrink rather than blow out the track. */
    /* Five fixed, equal tracks - not auto-fit - so the row never reflows and a
       long peso figure can never push a card onto a second line.
       minmax(0,1fr) lets the numbers shrink rather than blow out the track. */
    .summary-cards{grid-template-columns:repeat(5,minmax(0,1fr));gap:20px;align-items:stretch;}
    /* Same height for every card whatever it contains, so switching period can
       never make the row jump. */
    .summary-cards .summary-card{min-width:0;min-height:118px;display:flex;flex-direction:column;justify-content:center;}
    /* A FIXED size, deliberately. This was clamp(...,1.85vw,...), which ties the
       numerals to viewport width - so the same card rendered at a different size
       depending on the window, and a card whose figure grew by a digit looked
       squeezed next to its neighbours. One size for all five keeps the row
       consistent; nowrap + ellipsis stays as the overflow guard. */
    .summary-cards .summary-card .stat-number{font-size:1.65rem;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .summary-cards .summary-card .stat-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    @media (max-width: 1400px){
      .summary-cards{grid-template-columns:repeat(3,minmax(0,1fr));}
    }
    @media (max-width: 900px){
      .summary-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
      .summary-cards .summary-card .stat-number{font-size:1.5rem;}
    }
    @media (max-width: 480px){
      .summary-cards{grid-template-columns:minmax(0,1fr);}
    }

    /* ---------- End of Day card ----------
       Three bands in one card: the day being closed, its figures, and the act
       of closing it. The bands are separated by rules rather than being three
       separate cards, because they are one task. */
    .eod-card{position:relative;z-index:2;}
    .eod-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;padding:22px 25px 18px;border-bottom:1px solid var(--admin-border);}
    .eod-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:700;letter-spacing:.02em;text-transform:uppercase;white-space:nowrap;}
    .eod-pill i{font-size:12px;line-height:1;}
    .eod-pill-done{background:rgba(34,197,94,.14);color:var(--admin-success);}
    .eod-pill-open{background:rgba(245,158,11,.14);color:#b45309;}
    .eod-pill-info{background:rgba(59,130,246,.14);color:var(--accent-blue);}
    .eod-pill-warn{background:rgba(194,104,111,.14);color:var(--admin-danger);text-transform:none;letter-spacing:0;}

    /* Six figures on one row, equal tracks - same reasoning as .summary-cards:
       fixed tracks so a long peso figure cannot reflow the row. */
    .eod-figures{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:1px;background:var(--admin-border);}
    .eod-figure{background:var(--admin-surface);padding:16px 18px;min-width:0;}
    .eod-figure-label{display:block;color:var(--admin-muted);font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .eod-figure-value{display:block;color:var(--admin-text);font-size:1.15rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    @media (max-width:1200px){.eod-figures{grid-template-columns:repeat(3,minmax(0,1fr));}}
    @media (max-width:640px){.eod-figures{grid-template-columns:repeat(2,minmax(0,1fr));}}

    .eod-body{padding:22px 25px;}
    .eod-form-fields{display:grid;grid-template-columns:220px minmax(0,1fr) auto;gap:14px;align-items:end;}
    @media (max-width:900px){.eod-form-fields{grid-template-columns:minmax(0,1fr);}}
    .eod-field{display:flex;flex-direction:column;gap:6px;min-width:0;}
    .eod-field .admin-form-label{margin:0;}
    .eod-field .admin-form-label small{font-weight:500;color:var(--admin-muted);text-transform:none;letter-spacing:0;}
    .eod-field-action{justify-content:flex-end;}
    .eod-submit-btn{border:none;cursor:pointer;margin:0;white-space:nowrap;}
    .eod-submit-btn[disabled]{opacity:.6;cursor:progress;}
    .eod-form-msg{margin:12px 0 0;font-size:13px;font-weight:600;}
    .eod-form-msg[data-state="error"]{color:var(--admin-danger);}
    .eod-form-msg[data-state="ok"]{color:var(--admin-success);}

    .eod-snapshot{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0 0;}
    @media (max-width:760px){.eod-snapshot{grid-template-columns:repeat(2,minmax(0,1fr));}}
    .eod-snapshot > div{display:flex;flex-direction:column;gap:3px;padding:12px 14px;border-radius:var(--radius-sm);background:var(--admin-surface-2);box-shadow:var(--neu-pressed);min-width:0;}
    .eod-snapshot span{color:var(--admin-muted);font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .eod-snapshot strong{color:var(--admin-text);font-size:1.02rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .eod-notes{margin:16px 0 0;padding:12px 14px;border-radius:var(--radius-sm);background:var(--admin-surface-2);box-shadow:var(--neu-pressed);color:var(--admin-text);font-size:13px;line-height:1.55;}

    .eod-drift{margin-top:18px;padding:16px;border-radius:var(--radius-sm);background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.30);color:var(--admin-text);}
    .eod-amend{margin-top:18px;}
    .eod-amend > summary{cursor:pointer;color:var(--accent-cyan);font-size:13px;font-weight:700;width:fit-content;}
    .eod-amend[open] > summary{margin-bottom:4px;}

    .eod-open-days{margin-top:20px;padding-top:18px;border-top:1px solid var(--admin-border);color:var(--admin-muted);font-size:13px;line-height:1.9;}
    .eod-open-days strong{color:var(--admin-text);}
    .eod-day-link{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;background:var(--admin-surface-2);box-shadow:var(--neu-raised-sm);color:var(--admin-text);text-decoration:none;font-size:12.5px;font-weight:600;}
    .eod-day-link small{color:var(--admin-muted);font-weight:500;}
    .eod-day-link:hover{color:var(--accent-cyan);}
  </style>
  <?php // Back to this page's own starting state - sales_report.php with no
        // query string - not history.back(). The filters submit by GET, so
        // every date-range and period change is its own history entry and
        // stepping back walks the admin through all of them one at a time.
        // A bare link lands on the default view in a single hop. ?>
      <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/sales_report.php">Reports</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Sales Report</span>
      </nav>
    </div>
  </div>
  <?php /* sr-view-lines is what the print stylesheet keys the printed layout
           off: that sheet is the line-item table and nothing else, for every
           period. A class rather than a body attribute so the rule stays
           scoped to this page's own document. */ ?>
  <div class="admin-dashboard sr-view-lines" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">

    <?php /* ---------------------------------------------------------------
       The printed document's masthead. Hidden on screen; it exists because
       printing this page as-is produced a screenshot of the admin - sidebar,
       date picker, the Print button itself - on a sheet with no title, no
       period and no company block.

       Same document as the inventory report in reports_analytics.php -
       masthead band, ruled table with a banded header row, sign-off strip
       along the bottom - in the same peach palette, so the two read as one
       family of printed reports rather than two designs.
       --------------------------------------------------------------- */ ?>
    <?php
      $srBiz  = function_exists('business_info') ? business_info() : ['name' => 'TECHWRLD', 'address' => '', 'email' => '', 'phone' => ''];
      $srMeta = sr_print_meta($period, $startDate, $endDate);
    ?>
    <div class="sr-print-doc">
      <div class="sr-print-masthead">
        <div class="sr-print-biz">
          <div class="sr-print-name"><?= htmlspecialchars($srBiz['name']) ?></div>
          <?php if (!empty($srBiz['address'])): ?><div><?= htmlspecialchars($srBiz['address']) ?></div><?php endif; ?>
          <?php if (!empty($srBiz['email'])): ?><div class="sr-print-mail"><?= htmlspecialchars($srBiz['email']) ?></div><?php endif; ?>
          <?php if (!empty($srBiz['phone'])): ?><div><?= htmlspecialchars($srBiz['phone']) ?></div><?php endif; ?>
        </div>
        <?php /* Title and period line both come from sr_print_meta(), so the
                 heading and the grain it is written in always agree. There is
                 no separate "Daily view" line any more - the title says it. */ ?>
        <div class="sr-print-heading">
          <div class="sr-print-title"><?= htmlspecialchars($srMeta['title']) ?></div>
          <?php if ($srMeta['range'] !== ''): ?>
            <div class="sr-print-period"><?= htmlspecialchars($srMeta['range']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php /* ---------------------------------------------------------------
       The sheet's line items - the table the reference document is built
       around. Print-only, and now printed for every period: weekly, monthly,
       semi-annual and annual sheets are the daily sheet over a longer range.
       The period table below is hidden on paper whichever period is showing,
       so the sheet never carries both.

       Amounts come from the same payments aggregate the KPI figures do, so
       this table's Total row is the range's total, not a second opinion.

       One column changes with the period. On a daily sheet every order shares
       the date already printed in the masthead, so the first column is just
       the time; over a longer range the date is what tells two rows apart, so
       it carries both and takes the extra width from the Order No. column.
       --------------------------------------------------------------- */ ?>
    <?php
      $srIsDay      = ($period === 'daily');
      $srWhenCol    = $srIsDay ? 'Time' : 'Date &amp; Time';
      $srWhenFmt    = $srIsDay ? 'g:i A' : 'M j, Y g:i A';
      $srEmptyWhere = $srIsDay ? 'on this day' : 'in this period';
    ?>
    <div class="sr-print-lines<?= $srIsDay ? '' : ' sr-lines-dated' ?>">
      <table>
        <thead>
          <tr>
            <th class="sr-c-time"><?= $srWhenCol ?></th>
            <th class="sr-c-order">Order No.</th>
            <th>Customer</th>
            <th>Payment Channel</th>
            <th>Status</th>
            <th class="sr-c-num">Items</th>
            <th class="sr-c-num">Amount (&#8369;)</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($srLineError !== null): ?>
            <tr><td colspan="7" class="sr-print-empty"><?= htmlspecialchars($srLineError) ?></td></tr>
          <?php elseif (empty($srLineItems)): ?>
            <tr><td colspan="7" class="sr-print-empty">No orders were placed <?= $srEmptyWhere ?>.</td></tr>
          <?php else: ?>
            <?php foreach ($srLineItems as $li): ?>
              <?php
                $liName = trim(((string)($li['firstname'] ?? '')) . ' ' . ((string)($li['lastname'] ?? '')));
                // An order whose customer row is gone still happened and still
                // counts toward the day's money, so it keeps its line.
                if ($liName === '') { $liName = 'a deleted user'; }
                $liRef = trim((string)($li['invoice_no'] ?? ''));
                if ($liRef === '') { $liRef = '#' . (int)$li['id']; }
              ?>
              <tr>
                <td class="sr-c-time"><?= htmlspecialchars(date($srWhenFmt, strtotime((string)$li['created_at']))) ?></td>
                <td class="sr-c-order"><?= htmlspecialchars($liRef) ?></td>
                <td><?= htmlspecialchars($liName) ?></td>
                <td><?= htmlspecialchars(sr_line_channel($li)) ?></td>
                <td><?= htmlspecialchars(ucfirst((string)$li['status'])) ?></td>
                <td class="sr-c-num"><?= number_format((int)$li['item_count']) ?></td>
                <td class="sr-c-num"><?= number_format((float)$li['paid'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5">Total<?= $srLineTotals['orders'] > 0 ? ' (' . number_format($srLineTotals['orders']) . ' order' . ($srLineTotals['orders'] === 1 ? '' : 's') . ')' : '' ?></td>
            <td class="sr-c-num"><?= number_format($srLineTotals['items']) ?></td>
            <td class="sr-c-num">&#8369;<?= number_format($srLineTotals['amount'], 2) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Header Section -->
    <div class="admin-card report-header" style="padding:25px;margin-bottom:30px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <div>
          <h1 style="margin:0;font-size:2rem;">Sales Report</h1>
          <p style="margin:5px 0 0 0;">Comprehensive sales analysis and reporting</p>
        </div>
        <div style="display:flex;gap:10px;">
          <a href="<?= WEB_ROOT ?>public/authenticated/admin/reports_analytics.php?view=inventory&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" class="action-btn" style="background:var(--admin-primary-2);color:#fff;padding:8px 16px;border:none;border-radius:var(--radius-sm);cursor:pointer;box-shadow:var(--neu-raised-sm);text-decoration:none;display:inline-flex;align-items:center;">View in Inventory Report</a>
          <button onclick="window.print();" class="action-btn" style="background:#C5EBAA;color:#1F3A24;padding:8px 16px;border:none;border-radius:var(--radius-sm);cursor:pointer;box-shadow:var(--neu-raised-sm);">Print Report</button>
        </div>
      </div>

      <!-- Filters -->
      <form method="GET" style="display:flex;gap:15px;align-items:end;flex-wrap:wrap;">
        <div>
          <label class="admin-form-label">Date Range:</label>
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
        <div>
          <label class="admin-form-label">Period:</label>
          <select name="period" class="admin-form-input" id="srPeriod" data-ranges='<?= htmlspecialchars(json_encode([
              'daily'         => sr_period_range('daily'),
              'weekly'        => sr_period_range('weekly'),
              'monthly'       => sr_period_range('monthly'),
              'semi-annually' => sr_period_range('semi-annually'),
              'annually'      => sr_period_range('annually'),
          ]), ENT_QUOTES) ?>'>
            <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
            <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
            <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            <option value="semi-annually" <?= $period === 'semi-annually' ? 'selected' : '' ?>>Semi-Annually</option>
            <option value="annually" <?= $period === 'annually' ? 'selected' : '' ?>>Annually</option>
          </select>
        </div>
        <button type="submit" class="action-btn admin-quick-link" style="border:none;cursor:pointer;">Update Report</button>
      </form>
      <?php /* Changing the period rewrites the date range to match it and
               reloads. The ranges are computed by sr_period_range() in PHP and
               handed over as data, so the two never drift: there is one
               definition of "a week is 7 days", not a PHP one and a JS one. */ ?>
      <script>
      (function () {
        var sel = document.getElementById('srPeriod');
        if (!sel) return;
        var ranges;
        try { ranges = JSON.parse(sel.getAttribute('data-ranges')); } catch (e) { return; }

        sel.addEventListener('change', function () {
          var r = ranges[sel.value];
          if (!r) return;
          var form = sel.form;
          // Rewrite the hidden inputs the picker owns, then submit: the page
          // comes back with the picker re-rendered from these same values.
          var start = form.querySelector('[data-drp-hidden-start]');
          var end   = form.querySelector('[data-drp-hidden-end]');
          if (start) { start.value = r[0]; }
          if (end)   { end.value   = r[1]; }
          form.submit();
        });
      })();
      </script>
    </div>

    <!-- Summary Cards -->
    <div class="quick-stats summary-cards" style="margin-bottom:30px;">
      <div class="stat-card summary-card" style="padding:25px;">
        <div class="stat-number">₱<?= number_format((float)$totalSales, 0) ?></div>
        <div class="stat-label">Total Sales</div>
      </div>
      <div class="stat-card summary-card" style="padding:25px;">
        <div class="stat-number">₱<?= number_format((float)$totalCod, 0) ?></div>
        <div class="stat-label">Cash on Delivery</div>
      </div>
      <div class="stat-card summary-card" style="padding:25px;">
        <div class="stat-number">₱<?= number_format((float)$totalInstore, 0) ?></div>
        <div class="stat-label">In-Store Payment</div>
      </div>
      <div class="stat-card summary-card" style="padding:25px;">
        <div class="stat-number"><?= number_format((int)$totalOrders) ?></div>
        <div class="stat-label">Total Orders</div>
      </div>
      <div class="stat-card summary-card" style="padding:25px;">
        <?php // A dash, not 0%, when there is nothing to divide by or no returns
              // table - 0% would read as "no refunds", which is a different
              // claim from "no orders in this range to measure". ?>
        <div class="stat-number"><?= $refundRate === null ? '&mdash;' : number_format($refundRate, 1) . '%' ?></div>
        <div class="stat-label">Refund Rate<?= $refundCount > 0 ? ' (' . number_format($refundCount) . ')' : '' ?></div>
      </div>
    </div>

    <?php /* ---------------------------------------------------------------
             End of Day.
             The daily close lives on this page rather than a page of its own
             because closing a day means reading the day's figures first - and
             those are right here. Everything above is analysis; this is the one
             thing the admin DOES at the end of store hours.
             --------------------------------------------------------------- */ ?>
    <?php /* eod-no-print on anything but the daily view. End of Day closes ONE
             day; printed in the middle of a month's or a year's figures it
             reads as though those totals were what was closed out, which is a
             different and wrong claim. On the daily report it belongs - that
             sheet is the day. The card is untouched on screen either way. */ ?>
    <div class="admin-card eod-card<?= $period === 'daily' ? '' : ' eod-no-print' ?>" style="padding:0;margin-bottom:30px;overflow:hidden;">
      <div class="eod-head">
        <div>
          <h3 style="margin:0;font-size:1.25rem;color:var(--admin-text);display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            End of Day
            <?php if ($eodReport): ?>
              <span class="eod-pill eod-pill-done"><i class="fi fi-rr-check"></i> Submitted</span>
              <?php if ((int)$eodReport['amend_count'] > 0): ?>
                <span class="eod-pill eod-pill-info">Amended <?= (int)$eodReport['amend_count'] ?>&times;</span>
              <?php endif; ?>
            <?php elseif ($hasEodTable && !$eodIsFuture): ?>
              <span class="eod-pill eod-pill-open"><i class="fi fi-rr-clock-three"></i> Not submitted</span>
            <?php endif; ?>
          </h3>
          <p style="margin:6px 0 0;color:var(--admin-muted);font-size:13px;">
            <?= date('l, M j, Y', strtotime($eodDate)) ?><?php /* "(today)" and the
                 wide-range note are screen orientation only: one is relative to
                 whenever the page happened to be open, the other tells the admin
                 how to pick a different day. Neither belongs on a printed record
                 of one specific day - the date above it already is the answer. */ ?>
            <span class="eod-no-print"><?= $eodIsToday ? ' (today)' : '' ?></span>
            <?php if ($eodRangeWide): ?>
              <span class="eod-no-print">
                &mdash; the date range above covers several days, so this closes <?= $eodIsToday ? 'today' : date('M j', strtotime($eodDate)) ?>.
                Pick a single date in the range to close a different day.
              </span>
            <?php endif; ?>
          </p>
        </div>
        <?php if (!$hasEodTable): ?>
          <span class="eod-pill eod-pill-warn">Run DBSQL/add_daily_sales_reports.sql to enable</span>
        <?php endif; ?>
      </div>

      <?php /* The day's live figures, so what is about to be submitted is on
               screen right next to the button that submits it. */ ?>
      <div class="eod-figures">
        <?php
        $eodRows = [
          ['Total Sales',      '&#8369;' . number_format($eodFigures['total_sales'], 2)],
          ['Orders',           number_format($eodFigures['order_count'])],
          ['In-Store Payment', '&#8369;' . number_format($eodFigures['instore_sales'], 2)],
          ['Cash on Delivery', '&#8369;' . number_format($eodFigures['cod_sales'], 2)],
          ['Online',           '&#8369;' . number_format($eodFigures['online_sales'], 2)],
          ['Refunds',          $eodFigures['refund_count'] > 0
                                 ? '&#8369;' . number_format($eodFigures['refund_amount'], 2) . ' (' . (int)$eodFigures['refund_count'] . ')'
                                 : '&mdash;'],
        ];
        foreach ($eodRows as $eodRow): ?>
          <div class="eod-figure">
            <span class="eod-figure-label"><?= htmlspecialchars($eodRow[0]) ?></span>
            <strong class="eod-figure-value"><?= $eodRow[1] ?></strong>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($hasEodTable): ?>
      <div class="eod-body">
        <?php if ($eodReport): ?>
          <?php
            $subName = trim((string)($eodReport['submitted_by_name'] ?? ''));
            $amdName = trim((string)($eodReport['amended_by_name'] ?? ''));
            // Variance is derived here, never stored - see the note in
            // DBSQL/add_daily_sales_reports.sql. It is measured against
            // in-store payments because that is the part of the day that is
            // cash in the drawer; COD and online money never passes the till.
            $cashCounted = $eodReport['cash_counted'] === null ? null : (float)$eodReport['cash_counted'];
            $variance    = $cashCounted === null ? null : $cashCounted - (float)$eodReport['instore_sales'];
          ?>
          <div class="eod-submitted">
            <p style="margin:0;color:var(--admin-muted);font-size:13px;">
              Submitted by <strong style="color:var(--admin-text);"><?= htmlspecialchars($subName !== '' ? $subName : 'a deleted user') ?></strong>
              on <?= date('M j, Y', strtotime((string)$eodReport['submitted_at'])) ?>
              at <?= date('g:i A', strtotime((string)$eodReport['submitted_at'])) ?>.
              <?php if (!empty($eodReport['amended_at'])): ?>
                Last amended by <strong style="color:var(--admin-text);"><?= htmlspecialchars($amdName !== '' ? $amdName : 'a deleted user') ?></strong>
                on <?= date('M j, Y', strtotime((string)$eodReport['amended_at'])) ?>
                at <?= date('g:i A', strtotime((string)$eodReport['amended_at'])) ?>.
              <?php endif; ?>
            </p>

            <div class="eod-snapshot">
              <div><span>Submitted total</span><strong>&#8369;<?= number_format((float)$eodReport['total_sales'], 2) ?></strong></div>
              <div><span>Submitted orders</span><strong><?= number_format((int)$eodReport['order_count']) ?></strong></div>
              <div><span>Cash counted</span><strong><?= $cashCounted === null ? 'Not counted' : '&#8369;' . number_format($cashCounted, 2) ?></strong></div>
              <div>
                <span>Variance vs in-store</span>
                <strong style="color:<?= $variance === null ? 'var(--admin-muted)' : (abs($variance) < 0.01 ? 'var(--admin-success)' : 'var(--admin-danger)') ?>;">
                  <?= $variance === null ? '&mdash;' : (($variance > 0 ? '+' : '') . '&#8369;' . number_format($variance, 2)) ?>
                </strong>
              </div>
            </div>

            <?php if (!empty($eodReport['notes'])): ?>
              <p class="eod-notes"><strong>Notes:</strong> <?= nl2br(htmlspecialchars((string)$eodReport['notes'])) ?></p>
            <?php endif; ?>

            <?php if ($eodDrifted): ?>
              <?php /* The submitted snapshot is kept, not quietly refreshed -
                       that is the whole point of a snapshot. The admin is told
                       it has moved and amends it on purpose. */ ?>
              <div class="eod-drift eod-no-print">
                <p style="margin:0 0 12px;font-size:13px;line-height:1.55;">
                  This day has changed since it was submitted &mdash; it now shows
                  <strong>&#8369;<?= number_format($eodFigures['total_sales'], 2) ?></strong>
                  across <strong><?= number_format($eodFigures['order_count']) ?></strong> order<?= $eodFigures['order_count'] === 1 ? '' : 's' ?>.
                  Amending replaces the submitted figures with the current ones and records the change.
                </p>
                <?= sr_eod_form($eodDate, true, $eodReport, $eodFigures) ?>
              </div>
            <?php else: ?>
              <details class="eod-amend eod-no-print">
                <summary>Amend this submission</summary>
                <p style="margin:10px 0;color:var(--admin-muted);font-size:13px;">
                  Re-reads the day's figures and replaces the submitted ones, recording who amended it and when.
                </p>
                <?= sr_eod_form($eodDate, true, $eodReport, $eodFigures) ?>
              </details>
            <?php endif; ?>
          </div>

        <?php elseif ($eodIsFuture): ?>
          <p style="margin:0;color:var(--admin-muted);font-size:13px;">That date is in the future &mdash; there is no day to close yet.</p>

        <?php else: ?>
          <div class="eod-no-print">
            <p style="margin:0 0 14px;color:var(--admin-muted);font-size:13px;line-height:1.55;">
              Submit this at the end of store hours. It freezes the figures above as the day's
              record &mdash; later orders will not change what was submitted, and the day shows as
              closed in the log below.
            </p>
            <?= sr_eod_form($eodDate, false, null, $eodFigures) ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($eodOpenDays)): ?>
          <?php /* Days that traded and were never closed. The point of a daily
                   report is that it happens daily, so a gap belongs on the page
                   rather than being discoverable only by reading the log. Each
                   links to its own single-day view, where this card is then
                   pointed at that day. */ ?>
          <div class="eod-open-days eod-no-print">
            <strong><?= count($eodOpenDays) ?></strong> earlier day<?= count($eodOpenDays) === 1 ? '' : 's' ?>
            in the last 30 took orders but <?= count($eodOpenDays) === 1 ? 'was' : 'were' ?> never closed:
            <span style="display:inline-flex;flex-wrap:wrap;gap:6px;margin-left:4px;">
              <?php foreach (array_slice($eodOpenDays, 0, 8) as $od): ?>
                <a class="eod-day-link"
                   href="?period=daily&amp;start_date=<?= urlencode((string)$od['d']) ?>&amp;end_date=<?= urlencode((string)$od['d']) ?>">
                  <?= date('M j', strtotime((string)$od['d'])) ?>
                  <small>(<?= (int)$od['c'] ?>)</small>
                </a>
              <?php endforeach; ?>
              <?php if (count($eodOpenDays) > 8): ?>
                <span style="align-self:center;color:var(--admin-muted);font-size:12px;">+<?= count($eodOpenDays) - 8 ?> more</span>
              <?php endif; ?>
            </span>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Sales Report Table -->
    <div class="admin-table-wrap report-table-container">
      <div style="padding:20px;border-bottom:1px solid var(--admin-border);">
        <h3 style="margin:0;font-size:1.3rem;color:var(--admin-text);">Sales Report - <?= ucfirst($period) ?> View</h3>
        <p style="margin:5px 0 0 0;color:var(--admin-muted);">Period: <?= date('M j, Y', strtotime($startDate)) ?> to <?= date('M j, Y', strtotime($endDate)) ?></p>
      </div>

      <div style="overflow:auto;">
        <table style="min-width:800px;">
          <thead>
            <tr>
              <?php /* "Period" on screen, where the Period select sits a few
                       inches above and explains it. On paper the column is
                       named for what is actually in it - Date, Week, Month,
                       Half, Year - because the select is not there to ask. */ ?>
              <th style="text-align:left;"><span class="sr-screen-only">Period</span><span class="sr-print-only"><?= htmlspecialchars($srMeta['column']) ?></span></th>
              <th style="text-align:right;">In-Store Payment</th>
              <th style="text-align:right;">Cash on Delivery</th>
              <th style="text-align:right;">Online Sales</th>
              <th style="text-align:right;">Total Sales</th>
              <th style="text-align:right;">Orders</th>
              <th style="text-align:right;">Growth %</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($salesData)): ?>
              <?php
              $prevTotal = 0;
              foreach ($salesData as $index => $row):
                $currentTotal = (float)$row['total_sales'];
                $growthRate = 0;
                if ($index > 0 && $prevTotal > 0) {
                  $growthRate = (($currentTotal - $prevTotal) / $prevTotal) * 100;
                }
                $prevTotal = $currentTotal;
              ?>
                <tr>
                  <td style="font-weight:600;">
                    <?php
                    $periodDisplay = $row['period'];
                    switch($period) {
                      case 'daily':
                        $timestamp = strtotime($row['period']);
                        $periodDisplay = $timestamp ? date('M j, Y', $timestamp) : $row['period'];
                        break;
                      case 'weekly':
                        // Handle weekly format (YYYY-WW)
                        if (preg_match('/^(\d{4})-(\d{1,2})$/', $row['period'], $matches)) {
                          $year = (int)$matches[1];
                          $week = (int)$matches[2];
                          $date = new DateTime();
                          $date->setISODate($year, $week);
                          $periodDisplay = 'Week of ' . $date->format('M j');
                        } else {
                          $periodDisplay = 'Week of ' . $row['period'];
                        }
                        break;
                      case 'monthly':
                        $timestamp = strtotime($row['period'] . '-01');
                        $periodDisplay = $timestamp ? date('F Y', $timestamp) : $row['period'];
                        break;
                      case 'semi-annually':
                        $year = substr($row['period'], 0, 4);
                        $half = substr($row['period'], -1);
                        $periodDisplay = 'H' . $half . ' ' . $year;
                        break;
                      case 'annually':
                        $periodDisplay = $row['period'];
                        break;
                    }
                    echo htmlspecialchars($periodDisplay);
                    ?>
                  </td>
                  <td style="text-align:right;">₱<?= number_format((float)$row['instore_sales'], 2) ?></td>
                  <td style="text-align:right;">₱<?= number_format((float)$row['cod_sales'], 2) ?></td>
                  <td style="text-align:right;">₱<?= number_format((float)$row['online_sales'], 2) ?></td>
                  <td style="text-align:right;font-weight:700;">₱<?= number_format((float)$row['total_sales'], 2) ?></td>
                  <td style="text-align:right;"><?= number_format((int)$row['order_count']) ?></td>
                  <td style="text-align:right;color:var(--admin-text);font-weight:700;">
                    <span style="color:<?= $growthRate >= 0 ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;"><?= $growthRate >= 0 ? '▲' : '▼' ?></span>
                    <?= $growthRate >= 0 ? '+' : '' ?><?= number_format($growthRate, 1) ?>%
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="admin-table-empty">
                  No sales data found for the selected period.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td style="text-align:right;">₱<?= number_format((float)$totalInstore, 2) ?></td>
              <td style="text-align:right;">₱<?= number_format((float)$totalCod, 2) ?></td>
              <td style="text-align:right;">₱<?= number_format((float)$totalOnline, 2) ?></td>
              <td style="text-align:right;">₱<?= number_format((float)$totalSales, 2) ?></td>
              <td style="text-align:right;"><?= number_format((int)$totalOrders) ?></td>
              <td style="text-align:right;">--</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>


    <?php if ($hasEodTable): ?>
    <?php /* The submission log. This is the record that the daily report
             actually happens daily - a run of dates with a name and a time
             against each. Figures here are the SUBMITTED ones, which is why
             they can differ from the live table above. */ ?>
    <div class="admin-table-wrap" style="margin-top:30px;">
      <div style="padding:20px;border-bottom:1px solid var(--admin-border);">
        <h3 style="margin:0;font-size:1.3rem;color:var(--admin-text);">Submitted Daily Reports</h3>
        <?php /* eod-no-print: a count of "the last N days" describes what the
                 screen is showing, not the record. On paper the rows below are
                 the whole answer and they are all there is, so the line is
                 either redundant or, on a multi-sheet print, wrong. */ ?>
        <p class="eod-no-print" style="margin:5px 0 0 0;color:var(--admin-muted);">The last <?= count($eodHistory) ?> day<?= count($eodHistory) === 1 ? '' : 's' ?> closed out, as submitted.</p>
      </div>
      <div style="overflow:auto;">
        <table style="min-width:800px;">
          <thead>
            <tr>
              <th style="text-align:left;">Day</th>
              <th style="text-align:right;">Total Sales</th>
              <th style="text-align:right;">Orders</th>
              <th style="text-align:right;">Cash Counted</th>
              <th style="text-align:right;">Variance</th>
              <th style="text-align:left;">Submitted By</th>
              <th style="text-align:left;">Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($eodHistory)): ?>
              <?php foreach ($eodHistory as $h):
                $hCash = $h['cash_counted'] === null ? null : (float)$h['cash_counted'];
                $hVar  = $hCash === null ? null : $hCash - (float)$h['instore_sales'];
                $hName = trim((string)($h['submitted_by_name'] ?? ''));
              ?>
                <tr>
                  <td style="font-weight:600;">
                    <a class="eod-day-link"
                       href="?period=daily&amp;start_date=<?= urlencode((string)$h['report_date']) ?>&amp;end_date=<?= urlencode((string)$h['report_date']) ?>">
                      <?= date('M j, Y', strtotime((string)$h['report_date'])) ?>
                    </a>
                    <?php if ((int)$h['amend_count'] > 0): ?>
                      <span class="eod-pill eod-pill-info" style="margin-left:6px;">Amended <?= (int)$h['amend_count'] ?>&times;</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:right;font-weight:700;">&#8369;<?= number_format((float)$h['total_sales'], 2) ?></td>
                  <td style="text-align:right;"><?= number_format((int)$h['order_count']) ?></td>
                  <td style="text-align:right;"><?= $hCash === null ? '&mdash;' : '&#8369;' . number_format($hCash, 2) ?></td>
                  <td style="text-align:right;color:<?= $hVar === null ? 'var(--admin-muted)' : (abs($hVar) < 0.01 ? 'var(--admin-success)' : 'var(--admin-danger)') ?>;font-weight:600;">
                    <?= $hVar === null ? '&mdash;' : (($hVar > 0 ? '+' : '') . '&#8369;' . number_format($hVar, 2)) ?>
                  </td>
                  <td><?= htmlspecialchars($hName !== '' ? $hName : 'a deleted user') ?></td>
                  <td style="white-space:nowrap;"><?= date('M j, g:i A', strtotime((string)$h['submitted_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="admin-table-empty">
                  No day has been closed out yet. Submit today's report from the End of Day card above.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Additional Analytics -->
    <div class="additional-stats" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:30px;">
      <div class="admin-card" style="padding:25px;">
        <h4 style="margin:0 0 15px 0;">Sales Distribution</h4>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
          <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent-blue);margin-right:8px;"></span>Online Sales:</span>
          <span style="color:var(--admin-text);font-weight:700;"><?= $totalSales > 0 ? number_format(($totalOnline / $totalSales) * 100, 1) : 0 ?>%</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
          <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--admin-success);margin-right:8px;"></span>Cash on Delivery:</span>
          <span style="color:var(--admin-text);font-weight:700;"><?= $totalSales > 0 ? number_format(($totalCod / $totalSales) * 100, 1) : 0 ?>%</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
          <span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent-cyan);margin-right:8px;"></span>In-Store Payment:</span>
          <span style="color:var(--admin-text);font-weight:700;"><?= $totalSales > 0 ? number_format(($totalInstore / $totalSales) * 100, 1) : 0 ?>%</span>
        </div>
        <div class="admin-progress-track" style="margin-top:15px;height:8px;display:flex;">
          <div style="height:100%;background:var(--accent-cyan);width:<?= $totalSales > 0 ? ($totalInstore / $totalSales) * 100 : 0 ?>%"></div>
          <div style="height:100%;background:var(--admin-success);width:<?= $totalSales > 0 ? ($totalCod / $totalSales) * 100 : 0 ?>%"></div>
          <div style="height:100%;background:var(--accent-blue);width:<?= $totalSales > 0 ? ($totalOnline / $totalSales) * 100 : 0 ?>%"></div>
        </div>
      </div>

      <div class="admin-card" style="padding:25px;">
        <h4 style="margin:0 0 15px 0;">Performance Metrics</h4>
        <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
          <span>Average Order Value:</span>
          <span style="color:var(--admin-text);font-weight:700;">₱<?= $totalOrders > 0 ? number_format($totalSales / $totalOrders, 2) : 0 ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
          <span>Total Periods:</span>
          <span style="color:var(--admin-text);font-weight:700;"><?= count($salesData) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;">
          <span>Best Period:</span>
          <span style="color:var(--admin-text);font-weight:700;">
            <?php
            if (!empty($salesData)) {
              $bestPeriod = max($salesData);
              echo '₱' . number_format((float)$bestPeriod['total_sales'], 0);
            } else {
              echo 'N/A';
            }
            ?>
          </span>
        </div>
      </div>
    </div>

    <?php /* Sign-off strip. Last in source order so it prints below the
             figures; the label cells carry the same band colour as the
             masthead. Screen-hidden, like the masthead. */ ?>
    <div class="sr-print-signoff">
      <div class="sr-print-col">
        <div class="sr-print-label">Prepared by</div>
        <div class="sr-print-value"><?= htmlspecialchars(trim((($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''))) ?: ($_SESSION['username'] ?? '')) ?></div>
        <div class="sr-print-label">Date Prepared</div>
        <div class="sr-print-value"><?= htmlspecialchars(date('m/d/Y')) ?></div>
      </div>
      <div class="sr-print-col">
        <div class="sr-print-label">Approved by</div>
        <div class="sr-print-value">&nbsp;</div>
        <div class="sr-print-label">Date Approved</div>
        <div class="sr-print-value">&nbsp;</div>
      </div>
      <div class="sr-print-col sr-print-col--notes">
        <div class="sr-print-label">Notes/Comments</div>
        <div class="sr-print-note">
          <?php /* The unit comes from sr_print_meta() rather than $period, which
                   is a slug: "3 semi-annually periods" is what printing the raw
                   value produced. */ ?>
          Figures cover <?= number_format(count($salesData)) ?> <?= htmlspecialchars($srMeta['unit']) ?><?= count($salesData) === 1 ? '' : 's' ?>
          and <?= number_format((int)$totalOrders) ?> order<?= (int)$totalOrders === 1 ? '' : 's' ?>,
          totalling &#8369;<?= number_format((float)$totalSales, 2) ?> for the range shown above
          (in-store &#8369;<?= number_format((float)$totalInstore, 2) ?>,
          cash on delivery &#8369;<?= number_format((float)$totalCod, 2) ?>,
          online &#8369;<?= number_format((float)$totalOnline, 2) ?>).
          <?php if ($refundRate !== null && $refundCount > 0): ?>
            Refunds: <?= number_format($refundCount) ?> at <?= number_format($refundRate, 1) ?>% of orders.
          <?php endif; ?>
          Totals are read live at the time of printing; a day already closed out
          keeps the figures that were submitted for it, which is why the submitted
          log above can differ from the period table.
        </div>
      </div>
    </div>
  </div>

<?php /* Submitting the day.
         Asks first, through uiReview() from shared/includes/ui-dialog.php -
         "is this right?" rather than "are you sure?". The risk at the end of a
         shift is not a misclick, it is signing off the wrong day or a mistyped
         cash count, and neither is visible on a yes/no prompt. So the dialog
         prints back the day, the figures about to be frozen, the drawer count
         and the variance it implies, and waits.

         Posted by fetch rather than a plain form submit so a failure (an
         expired session, a server error) is reported in place instead of
         replacing the page with a JSON body. On success the page reloads: the
         card, the log and the "open days" line all re-render from the row that
         was just written, so there is one source of truth for what is on
         screen - the database, not a patched-up DOM. */ ?>
<script>
(function () {
  var forms = document.querySelectorAll('[data-eod-form]');
  if (!forms.length) { return; }

  var PESO = '₱';

  function money(n) {
    return PESO + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  Array.prototype.forEach.call(forms, function (form) {
    var btn = form.querySelector('button[type="submit"]');
    var msg = form.querySelector('[data-eod-msg]');
    var cashInput = form.querySelector('[name="cash_counted"]');
    var notesInput = form.querySelector('[name="notes"]');
    var isAmend = form.getAttribute('data-eod-mode') === 'amend';
    var busy = false;

    function say(text, state) {
      if (!msg) { return; }
      msg.textContent = text;
      msg.setAttribute('data-state', state);
      msg.hidden = false;
    }

    // What the admin is about to commit to, in the order it matters: which
    // day, what it totals, then their own two entries and what they imply.
    function reviewRows() {
      var rows = [{ label: 'Business day', value: form.getAttribute('data-eod-day') || '' }];

      // On an amendment the submitted figure is the `was` - the whole point of
      // the dialog is seeing what the number is being changed FROM.
      rows.push({
        label: 'Total sales',
        value: PESO + (form.getAttribute('data-eod-total') || '0.00'),
        was: isAmend && form.getAttribute('data-eod-prev-total')
          ? PESO + form.getAttribute('data-eod-prev-total') : undefined
      });
      rows.push({
        label: 'Orders',
        value: form.getAttribute('data-eod-orders') || '0',
        was: isAmend && form.getAttribute('data-eod-prev-orders')
          ? form.getAttribute('data-eod-prev-orders') : undefined
      });

      var raw = cashInput ? cashInput.value.replace(/[,\s₱]/g, '') : '';
      var counted = raw !== '' && !isNaN(parseFloat(raw)) ? parseFloat(raw) : null;
      rows.push({
        label: 'Cash counted',
        value: counted === null ? 'Not counted' : money(counted)
      });

      // The variance is the number worth a second look - it is the one the
      // admin cannot read off the card, and a mistyped count shows up here as
      // an amount that makes no sense.
      if (counted !== null) {
        var instore = parseFloat(form.getAttribute('data-eod-instore') || '0') || 0;
        var variance = counted - instore;
        rows.push({
          label: 'Variance vs in-store ' + PESO + (form.getAttribute('data-eod-instore-display') || '0.00'),
          value: (variance > 0 ? '+' : '') + money(variance)
        });
      }

      var notes = notesInput ? notesInput.value.trim() : '';
      if (notes) { rows.push({ label: 'Notes', value: notes }); }

      return rows;
    }

    function send() {
      busy = true;
      var label = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }
      if (msg) { msg.hidden = true; }

      fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      })
        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
        .then(function (data) {
          if (data && data.success) {
            say(data.message || 'Saved.', 'ok');
            // Keep the button disabled through the reload - re-enabling it
            // would invite a second submission in the gap.
            if (btn) { btn.textContent = 'Saved'; }
            window.location.reload();
            return;
          }
          busy = false;
          if (btn) { btn.disabled = false; btn.textContent = label; }
          say((data && data.message) || 'Could not save the report. Please try again.', 'error');
        })
        .catch(function () {
          busy = false;
          if (btn) { btn.disabled = false; btn.textContent = label; }
          say('Could not reach the server. Please try again.', 'error');
        });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) { return; }

      // ui-dialog.php is included by the admin header, so uiReview is normally
      // there. If it ever is not, the day must still be closable - fall back
      // to the browser's own confirm rather than silently skipping the ask.
      if (typeof window.uiReview !== 'function') {
        if (window.confirm(isAmend
              ? 'Replace the submitted figures for ' + form.getAttribute('data-eod-day') + '?'
              : 'Submit the end-of-day report for ' + form.getAttribute('data-eod-day') + '?')) {
          send();
        }
        return;
      }

      window.uiReview({
        title: isAmend ? 'Replace the submitted figures?' : 'Submit this day’s report?',
        message: isAmend
          ? 'The figures below replace what was submitted for this day. The amendment is recorded against your name.'
          : 'These figures are frozen as this day’s record. Orders that arrive later will not change them — you would have to amend the report.',
        rows: reviewRows(),
        okText: isAmend ? 'Yes, amend it' : 'Yes, submit it',
        cancelText: 'Go back'
      }).then(function (ok) {
        if (ok) { send(); }
      });
    });
  });
})();
</script>
</main>

<style>
/* ---------------------------------------------------------------------------
   The printed sales report.

   Same document as the inventory report (reports_analytics.php?view=
   inventory), down to the palette: a masthead band, a fully ruled table with
   a banded header row that repeats across sheets, a totals row, and a
   sign-off strip. The two are meant to look like one family of reports.

   Every period prints the same TABLE - a row per order placed inside the
   range, rather than the period table's aggregate rows. See .sr-print-lines
   below and the line-item query near the top of this file.

   @page lives at the TOP LEVEL, not inside @media print. It only ever applies
   to paged media anyway, and engines disagree about honouring a nested one -
   Chrome does, others drop it silently.

   margin:0 is what removes the browser's own print furniture: the
   "localhost/TECHWRLD/...sales_report.php" footer and the date and page
   number beside it are drawn INSIDE the @page margin box, so leaving no
   margin leaves nowhere to draw them. The report's breathing room comes back
   as padding on the dashboard below.

   Landscape because the period table is seven columns wide and the submitted
   log another seven; in portrait both came out as squeezed numerals.
   --------------------------------------------------------------------------- */
@page { size: A4 landscape; margin: 0; }

/* Screen: the document furniture does not exist. */
.sr-print-doc, .sr-print-signoff, .sr-print-only, .sr-print-lines { display: none; }

@media print {

  /* Palette, the same one the inventory report prints in: four steps of one
     green, assigned darkest-to-lightest by how much weight each surface has
     to carry. The masthead is the heaviest band on the sheet so it takes the
     deepest fill; the column names sit one step above the totals row so the
     header reads as a header; the rules are the darkest of the four because a
     1px line needs the contrast a filled band does not. Low-saturation on
     purpose - a printed sheet is read for minutes at a time. */
  .sr-print-doc, .sr-print-signoff, .sr-print-lines, .admin-dashboard {
    --rep-mast: #A2CB8B;   /* masthead - the band the report's title sits on */
    --rep-head: #C7EABB;   /* column names, one step above the totals row */
    --rep-band: #AAD9BB;   /* totals row, sign-off labels */
    --rep-rule: #84B179;   /* every cell border and ruled line */
    --rep-ink:  #1C2A17;
  }

  /* Strip the application. Everything here is navigation, a control, or an
     overlay - none of it belongs on paper. The screen header card goes too:
     its title, filters and Print button are replaced by the masthead. */
  .admin-sidebar,
  .admin-sidebar-backdrop,
  .admin-menu-toggle,
  .admin-sa-mode-bar,
  #adminLockOverlay,
  .adm-head,
  .scan-fab,
  .report-header,
  .admin-footer,
  footer,
  .site-footer,
  .admin-progress-track,
  .eod-no-print,
  .eod-form,
  /* The "SUBMITTED" chip is a screen status light. The record itself says it
     better and says it with evidence - "Submitted by <name> on <date> at
     <time>" is right below the figures - so the chip is just a second,
     vaguer copy of that on paper. The "Amended Nx" chip stays: nothing else
     on the sheet carries that. */
  .eod-pill-done { display: none !important; }

  /* The screen/paper pair. Used where the same cell has to read one way next
     to a filter control and another way on a sheet that has no controls - the
     first column's heading, which is "Period" on screen and the period's own
     name on paper. */
  .sr-screen-only { display: none !important; }
  .sr-print-only  { display: inline !important; }

  /* The main table's own heading block - "Sales Report - Daily View" and
     "Period: ... to ..." - is now said better and said first by the masthead,
     so on paper it is the same two facts printed twice. The submitted log's
     heading stays: it is what tells the two tables apart. */
  .report-table-container > div:first-child { display: none !important; }

  /* ---- the sheet ------------------------------------------------------
     The reference document is a masthead, one table, a totals row and a
     sign-off. Everything else on this page is either a restatement of that
     table's own Total row (the KPI band, the End of Day figures, the period
     table's aggregate rows) or a different report that happens to share the
     page (the submitted log, the analytics pair). So the sheet is the
     line-item table and nothing else, for every period - which is what makes
     a weekly, monthly, semi-annual or annual report the same document as the
     daily one, only longer. */
  .sr-view-lines .summary-cards,
  .sr-view-lines .eod-card,
  .sr-view-lines .admin-table-wrap,
  .sr-view-lines .additional-stats { display: none !important; }

  /* ---- line-item table, laid out as the reference ---- */
  .sr-print-lines { display: block !important; }
  .sr-print-lines table {
    width: 100% !important; border-collapse: collapse !important;
    font-size: 7.5pt; color: var(--rep-ink);
  }
  /* Repeats the column names at the top of every sheet - without it a busy
     day's second page is a block of unlabelled numerals. */
  .sr-print-lines thead { display: table-header-group; }
  .sr-print-lines tfoot { display: table-footer-group; }
  .sr-print-lines tr { page-break-inside: avoid; break-inside: avoid; }
  .sr-print-lines th, .sr-print-lines td {
    border: 1px solid var(--rep-rule); padding: 4px 6px; text-align: left;
  }
  .sr-print-lines thead th {
    background: var(--rep-head); font-weight: 700; font-size: 7pt; text-align: center;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .sr-print-lines tfoot td {
    background: var(--rep-band); font-weight: 700;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  /* Figures right-aligned and tabular so the decimal points line up down the
     column; the two narrow columns are pinned so a long customer name takes
     the slack instead of squeezing the time and the order number. */
  .sr-print-lines .sr-c-num {
    text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;
  }
  .sr-print-lines thead .sr-c-num { text-align: center; }
  .sr-print-lines .sr-c-time  { white-space: nowrap; width: 9%; }
  .sr-print-lines .sr-c-order { white-space: nowrap; width: 14%; }
  /* Weekly and longer: the first column carries "Oct 5, 2026 9:14 AM", so it
     takes the width back off Order No., which is a fixed-length reference. */
  .sr-lines-dated .sr-c-time  { width: 15%; }
  .sr-lines-dated .sr-c-order { width: 12%; }
  .sr-print-lines .sr-print-empty { text-align: center; padding: 14px 6px; }

  /* Flatten the shell so the document starts at the paper edge instead of
     inheriting the app's centred, padded, rounded card. body.admin carries a
     96px left gutter for the fixed sidebar (admin-flat.css) - no sidebar is
     painted on paper, so the gutter has no job here. */
  html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
  body.admin { display: block !important; }
  main.container {
    max-width: none !important; width: auto !important;
    padding: 0 !important; margin: 0 !important; min-height: 0 !important;
  }
  /* Padding, not @page margin - see the note above. Left/right holds on every
     sheet; top/bottom apply to the first and last only, which is why the
     vertical value is the larger of the two. */
  .admin-dashboard {
    background: #fff !important; color: var(--rep-ink) !important;
    max-width: none !important; margin: 0 !important; padding: 12mm 10mm !important;
    box-shadow: none !important; border: none !important;
  }
  /* A card caught mid-fade prints at partial opacity - on paper that is just
     a faint page. */
  .admin-enter { animation: none !important; opacity: 1 !important; transform: none !important; }

  /* ---- masthead ---- */
  .sr-print-doc { display: block !important; color: var(--rep-ink); }
  .sr-print-masthead {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 24px;
    background: var(--rep-mast); padding: 12px 14px; margin-bottom: 10px;
    /* print-color-adjust is what stops the browser dropping the band to save
       ink - without it the masthead prints white. */
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .sr-print-biz { font-size: 7.5pt; line-height: 1.5; }
  .sr-print-name { font-size: 16pt; font-weight: 800; letter-spacing: -.01em; margin-bottom: 2px; line-height: 1.1; }
  .sr-print-mail { text-decoration: underline; }
  .sr-print-heading { text-align: right; }
  .sr-print-title { font-size: 12pt; font-weight: 800; letter-spacing: .06em; }
  .sr-print-period { font-size: 8pt; margin-top: 3px; }

  /* ---- the KPI row, as one ruled band rather than five raised cards ---- */
  .summary-cards {
    display: grid !important; grid-template-columns: repeat(5, 1fr) !important;
    gap: 0 !important; margin: 0 0 10px !important;
    border: 1px solid var(--rep-rule) !important;
    page-break-inside: avoid; break-inside: avoid;
  }
  .summary-cards .summary-card {
    background: none !important; box-shadow: none !important;
    border: 0 !important; border-right: 1px solid var(--rep-rule) !important;
    border-radius: 0 !important; padding: 5px 8px !important; text-align: left !important;
  }
  .summary-cards .summary-card:last-child { border-right: 0 !important; }
  .summary-cards .summary-card .stat-number {
    font-size: 11pt !important; font-weight: 800 !important;
    margin: 0 !important; color: var(--rep-ink) !important; line-height: 1.25 !important;
  }
  .summary-cards .summary-card .stat-label {
    font-size: 6.5pt !important; font-weight: 700 !important; letter-spacing: .06em;
    text-transform: uppercase; color: var(--rep-ink) !important;
  }

  /* ---- section headings above each table / card ---- */
  .admin-table-wrap, .eod-card, .additional-stats .admin-card {
    background: none !important; box-shadow: none !important;
    border: 0 !important; border-radius: 0 !important; overflow: visible !important;
  }
  .admin-table-wrap { margin: 0 0 10px !important; }
  .admin-table-wrap > div:first-child,
  .eod-card .eod-head {
    padding: 0 0 4px !important; border: 0 !important; border-bottom: 0 !important;
  }
  .admin-table-wrap h3, .eod-card h3, .additional-stats h4 {
    font-size: 9pt !important; font-weight: 800 !important; letter-spacing: .04em;
    text-transform: uppercase; color: var(--rep-ink) !important; margin: 0 !important;
  }
  .admin-table-wrap > div:first-child p, .eod-card .eod-head p {
    font-size: 7.5pt !important; color: var(--rep-ink) !important; margin: 2px 0 0 !important;
  }

  /* ---- the tables are the report ---- */
  .admin-table-wrap div[style*="overflow"] {
    overflow: visible !important; border: 0 !important; border-radius: 0 !important;
  }
  .admin-table-wrap table {
    width: 100% !important; min-width: 0 !important;
    font-size: 7.5pt !important; border-collapse: collapse !important;
  }
  /* table-header-group is what repeats the column headings at the top of every
     printed sheet - without it pages 2+ are unlabelled rows. */
  .admin-table-wrap thead { display: table-header-group !important; }
  .admin-table-wrap tfoot { display: table-footer-group !important; }
  .admin-table-wrap tr { page-break-inside: avoid; break-inside: avoid; }
  .admin-table-wrap tbody tr:hover { background: none !important; }
  .admin-table-wrap th, .admin-table-wrap td {
    padding: 4px 6px !important; border: 1px solid var(--rep-rule) !important;
    color: var(--rep-ink) !important; background: none !important; font-size: 7.5pt !important;
  }
  .admin-table-wrap thead th {
    background: var(--rep-head) !important; font-weight: 700 !important;
    font-size: 7pt !important; text-transform: none;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .admin-table-wrap tfoot td {
    background: var(--rep-band) !important; font-weight: 700 !important;
    border-top: 1px solid var(--rep-rule) !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .admin-table-empty { padding: 10px 6px !important; font-size: 7.5pt !important; }
  /* The day links in the submitted log are screen pills; on paper they are
     just the date. */
  .eod-day-link {
    display: inline !important; padding: 0 !important; background: none !important;
    box-shadow: none !important; color: var(--rep-ink) !important; font-size: 7.5pt !important;
  }

  /* ---- End of Day: prints as the day's record, not as the form ---- */
  .eod-card {
    page-break-inside: avoid; break-inside: avoid; margin: 0 0 10px !important; padding: 0 !important;
  }
  .eod-pill {
    border: 1px solid var(--rep-rule) !important; background: none !important;
    color: var(--rep-ink) !important; font-size: 6.5pt !important; padding: 1px 6px !important;
  }
  .eod-pill i { display: none !important; }
  .eod-figures {
    grid-template-columns: repeat(6, 1fr) !important; gap: 0 !important;
    background: none !important; border: 1px solid var(--rep-rule) !important;
  }
  .eod-figure {
    background: none !important; padding: 5px 8px !important;
    border-right: 1px solid var(--rep-rule) !important;
  }
  .eod-figure:last-child { border-right: 0 !important; }
  .eod-figure-label {
    font-size: 6.5pt !important; margin-bottom: 2px !important; color: var(--rep-ink) !important;
  }
  .eod-figure-value { font-size: 9pt !important; color: var(--rep-ink) !important; }
  .eod-body { padding: 6px 0 0 !important; }
  .eod-submitted p { font-size: 7.5pt !important; color: var(--rep-ink) !important; }
  .eod-snapshot {
    grid-template-columns: repeat(4, 1fr) !important; gap: 0 !important; margin: 6px 0 0 !important;
    border: 1px solid var(--rep-rule) !important;
  }
  .eod-snapshot > div {
    background: none !important; box-shadow: none !important; border-radius: 0 !important;
    border-right: 1px solid var(--rep-rule) !important; padding: 5px 8px !important;
  }
  .eod-snapshot > div:last-child { border-right: 0 !important; }
  .eod-snapshot span { font-size: 6.5pt !important; color: var(--rep-ink) !important; }
  .eod-snapshot strong { font-size: 9pt !important; color: var(--rep-ink) !important; }
  .eod-notes {
    background: none !important; box-shadow: none !important; border-radius: 0 !important;
    border: 1px solid var(--rep-rule) !important; padding: 5px 8px !important;
    margin: 6px 0 0 !important; font-size: 7.5pt !important; color: var(--rep-ink) !important;
  }

  /* ---- the two analytics cards, as one ruled pair ---- */
  .additional-stats {
    grid-template-columns: 1fr 1fr !important; gap: 10px !important; margin: 0 !important;
    page-break-inside: avoid; break-inside: avoid;
  }
  .additional-stats .admin-card {
    border: 1px solid var(--rep-rule) !important; padding: 6px 8px !important;
    font-size: 7.5pt !important; color: var(--rep-ink) !important;
  }
  .additional-stats .admin-card h4 { margin: 0 0 4px !important; }
  .additional-stats .admin-card span { color: var(--rep-ink) !important; font-size: 7.5pt !important; }
  .additional-stats .admin-card > div { margin-bottom: 2px !important; }

  /* ---- sign-off strip ---- */
  .sr-print-signoff {
    display: grid !important; grid-template-columns: 1fr 1fr 2fr; gap: 14px;
    margin-top: 12px; font-size: 7.5pt; color: var(--rep-ink);
    page-break-inside: avoid; break-inside: avoid;
  }
  .sr-print-col { display: flex; flex-direction: column; }
  .sr-print-label {
    background: var(--rep-band); padding: 3px 7px; font-weight: 700;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .sr-print-value {
    padding: 5px 7px 9px; border-bottom: 1px solid var(--rep-rule);
    margin-bottom: 7px; min-height: 15px;
  }
  .sr-print-col--notes .sr-print-note { padding: 6px 7px; line-height: 1.5; }

  a[href]:after { content: "" !important; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
