<?php
/**
 * One definition of "the sales figures for a date range".
 *
 * admin/sales_report.php renders those figures broken down by period, and
 * api/daily_sales_report.php freezes a single day's into daily_sales_reports
 * when the admin closes the day. Those two must agree exactly - a closing
 * report that disagrees with the report it was read off is worse than no
 * closing report - so the SQL that decides what counts as an in-store sale
 * lives here once and both call it.
 *
 * The API never trusts figures posted by the browser; it calls this and stores
 * what it computes itself.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Does this install have the detailed `payments` table?
 *
 * Mirrors the guard sales_report.php has always used: a deployment without the
 * table falls back to the coarser payment_method logic on `orders` instead of
 * failing. Cached per request - it is a schema fact, not data.
 */
function sfHasPaymentsTable(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) { return $has; }
    try {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'payments'"
        );
        $stmt->execute([$pdo->query('SELECT DATABASE()')->fetchColumn()]);
        $has = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('sfHasPaymentsTable: ' . $e->getMessage());
        $has = false;
    }
    return $has;
}

/**
 * Totals for orders dated within [$start, $end] inclusive.
 *
 * The three payment buckets follow live data, not the column names' promises:
 * p.payment_method is only ever populated for online payments, while in-store
 * pickup and cash-on-delivery are distinguishable only by p.payment_type. That
 * is the same reading sales_report.php's grouped query uses - see the long note
 * there for how it was arrived at.
 *
 * @return array{instore_sales:float,cod_sales:float,online_sales:float,total_sales:float,order_count:int}
 */
function sfSalesTotals(PDO $pdo, string $start, string $end): array
{
    $empty = [
        'instore_sales' => 0.0,
        'cod_sales'     => 0.0,
        'online_sales'  => 0.0,
        'total_sales'   => 0.0,
        'order_count'   => 0,
    ];

    try {
        if (sfHasPaymentsTable($pdo)) {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN p.payment_type = 'store_pickup'  THEN p.amount ELSE 0 END) AS instore_sales,
                    SUM(CASE WHEN p.payment_type = 'home_delivery' THEN p.amount ELSE 0 END) AS cod_sales,
                    SUM(CASE WHEN p.payment_method = 'online'      THEN p.amount ELSE 0 END) AS online_sales,
                    SUM(p.amount) AS total_sales,
                    COUNT(DISTINCT o.id) AS order_count
                FROM orders o
                INNER JOIN payments p ON o.id = p.order_id
                WHERE DATE(o.created_at) BETWEEN ? AND ?
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN payment_method IN ('store','onsite') THEN total_amount ELSE 0 END) AS instore_sales,
                    SUM(CASE WHEN payment_method = 'cod' THEN total_amount ELSE 0 END) AS cod_sales,
                    SUM(CASE WHEN payment_method IN ('online','gcash','paymaya') OR payment_method IS NULL THEN total_amount ELSE 0 END) AS online_sales,
                    SUM(total_amount) AS total_sales,
                    COUNT(*) AS order_count
                FROM orders
                WHERE DATE(created_at) BETWEEN ? AND ?
            ");
        }
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch();
        if (!$row) { return $empty; }

        return [
            'instore_sales' => (float)($row['instore_sales'] ?? 0),
            'cod_sales'     => (float)($row['cod_sales'] ?? 0),
            'online_sales'  => (float)($row['online_sales'] ?? 0),
            'total_sales'   => (float)($row['total_sales'] ?? 0),
            'order_count'   => (int)($row['order_count'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log('sfSalesTotals: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Completed refunds on orders dated within the range.
 *
 * Dated by the ORDER, not the return, so a rate built from this divides two
 * counts drawn from the same window. Degrades to zeroes where
 * return_requests (40_support_and_service.sql) is not installed.
 *
 * @return array{refund_count:int,refund_amount:float}
 */
function sfRefundTotals(PDO $pdo, string $start, string $end): array
{
    try {
        $t = $pdo->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'return_requests'"
        );
        $t->execute([$pdo->query('SELECT DATABASE()')->fetchColumn()]);
        if ($t->rowCount() === 0) {
            return ['refund_count' => 0, 'refund_amount' => 0.0];
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS c, COALESCE(SUM(r.refund_amount), 0) AS amt
            FROM return_requests r
            INNER JOIN orders o ON o.id = r.order_id
            WHERE r.return_type = 'refund'
              AND r.status = 'completed'
              AND DATE(o.created_at) BETWEEN ? AND ?
        ");
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch() ?: [];
        return [
            'refund_count'  => (int)($row['c'] ?? 0),
            'refund_amount' => (float)($row['amt'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log('sfRefundTotals: ' . $e->getMessage());
        return ['refund_count' => 0, 'refund_amount' => 0.0];
    }
}

/**
 * Everything one business day's closing report records, in one call.
 *
 * @return array{instore_sales:float,cod_sales:float,online_sales:float,total_sales:float,order_count:int,refund_count:int,refund_amount:float}
 */
function sfDayFigures(PDO $pdo, string $date): array
{
    return sfSalesTotals($pdo, $date, $date) + sfRefundTotals($pdo, $date, $date);
}
