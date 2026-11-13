<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
// Stock In / Stock Out / flagging. This page is the dashboard for them;
// the rules live in the module so this file stays a reporting view.
require_once __DIR__ . '/../../../config/inventory_stock.php';
require_role(['admin']);

$pageTitle = 'Inventory Control - TechWorld Admin';
$isAdminTheme = true;

/**
 * Inventory Control - the six functions this page is responsible for:
 *
 *   1. Real-time tracking   - on hand / committed / available, per SKU, live
 *   2. Alerts               - out of stock, below reorder point, oversold
 *   3. Demand monitoring    - 30-day velocity and days of cover per SKU
 *   4. Order integration    - what open orders have already claimed
 *   5. Loss prevention      - cancellations, returns, manual adjustments
 *   6. Reporting            - Excel export and print, honouring the filters
 *
 * HOW STOCK IS COUNTED HERE, and why it is not `stock - committed`.
 *
 * This shop deducts stock when the ORDER IS PLACED, not when it ships:
 * customer/process_order.php checks `stock >= quantity` and then runs
 * `UPDATE products SET stock = stock - ?` in the same transaction that writes
 * the order. Nothing adds it back at fulfilment - the only `stock + ?` writes
 * in the codebase are a manual restock (admin/product_prices.php) and the two
 * cancellation rollbacks.
 *
 * So `products.stock` is ALREADY net of every open order:
 *
 *     available to sell = products.stock
 *     physically on hand = products.stock + units on open orders not yet shipped
 *
 * This page used to compute `available = stock - committed`, which subtracted
 * those units a SECOND time. Every SKU whose open orders outnumbered its
 * remaining stock went negative and was reported as "Oversold" - six of them,
 * none real. Order #2 (Ryzen 3 3200G) is the worked example: 2 in stock, three
 * one-unit orders (two shipped weeks ago, one preparing), reported as -1
 * oversold when 2 were sitting on the shelf, sellable.
 *
 * `committed` is still computed and still shown - it answers "how much of what
 * I sold is still to go out" - but it is reporting, not arithmetic on what can
 * be sold.
 *
 * OVERSOLD still exists as a state, and now it means something: available < 0
 * can only happen if stock is edited below what is already promised, or if two
 * checkouts race past the line-149 availability check. That is a real alarm
 * worth a red chip; the old one fired on healthy stock.
 */

// ---------------------------------------------------------------- inputs ----
$search   = trim($_GET['search'] ?? '');
$lowOnly  = !empty($_GET['low']);
// The report is an .xlsx built on Inventory-Template.xlsx (see the Reporting
// section below). It replaced a flat CSV; `export=csv` is no longer answered,
// so an old bookmark lands on the page rather than silently downloading a file
// in the format this change removed.
$export   = ($_GET['export'] ?? '') === 'xlsx';

// Order statuses that hold physical stock the warehouse can no longer sell.
// 'completed' has already shipped and left; 'cancelled' released its units.
// 'awaiting_payment' counts: stock is deducted at checkout, before payment,
// and only cancelExpiredOnlinePaymentOrders() gives it back an hour later - so
// those units are genuinely unavailable in the meantime.
const INV_OPEN_STATUSES = ['awaiting_payment', 'preparing', 'packed', 'ready', 'shipped'];

$loadError = null;

try {
    $pdo = getConnection();
    $openIn = "'" . implode("','", INV_OPEN_STATUSES) . "'";

    // -- 4. Order integration: units claimed by open orders, per product ------
    $committedMap = [];
    $commitStmt = $pdo->query(
        "SELECT oi.product_id, SUM(oi.quantity) AS qty
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.status IN ($openIn)
         GROUP BY oi.product_id"
    );
    foreach ($commitStmt->fetchAll() as $r) {
        $committedMap[(int)$r['product_id']] = (int)$r['qty'];
    }

    // The same claim, line by line rather than summed, for the export's
    // "Stock OUT" ledger. Grouping it would lose the dates, and the template's
    // STOCK OUT sheet is a dated movement log - one row per order line is what
    // it is shaped for. Summed back up by the sheet's own SUMIF it returns
    // exactly $committedMap, so the two views cannot drift apart.
    // Only fetched for a download; a normal page view has no use for it.
    $openLines = [];
    if ($export) {
        $openLines = $pdo->query(
            "SELECT oi.product_id, oi.quantity, DATE(o.created_at) AS moved_on
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status IN ($openIn)
             ORDER BY o.created_at ASC, oi.id ASC"
        )->fetchAll();
    }

    // Open orders broken out by status, for the Order Integration card.
    $openOrders = $pdo->query(
        "SELECT o.status, COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(oi.quantity),0) AS units
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.status IN ($openIn)
         GROUP BY o.status
         ORDER BY FIELD(o.status, 'awaiting_payment','preparing','packed','ready','shipped')"
    )->fetchAll();

    // -- 3. Demand monitoring: 30-day units sold, per product ----------------
    // Cancelled orders are excluded - a cancelled order is not demand that the
    // warehouse has to cover, and counting it inflates every reorder figure.
    $soldMap = [];
    $demandStmt = $pdo->query(
        "SELECT oi.product_id, SUM(oi.quantity) AS sold_30d
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND o.status <> 'cancelled'
         GROUP BY oi.product_id"
    );
    foreach ($demandStmt->fetchAll() as $r) {
        $soldMap[(int)$r['product_id']] = (int)$r['sold_30d'];
    }

    // -- 1. Real-time tracking: the SKU list (filtered) -----------------------
    $where  = ["p.status = 'active'"];
    $params = [];
    if ($search !== '') {
        $where[]  = '(p.name LIKE ? OR c.name LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // No LIMIT when exporting - a report that silently stops at 200 rows is
    // worse than no report.
    $listLimit = $export ? '' : ' LIMIT 500';
    // p.description is for the export only - the template has a Description
    // column on three of its four sheets. It is not rendered on this page.
    $listStmt = $pdo->prepare(
        "SELECT p.id, p.name, p.stock, p.price, p.category_id, p.image_url,
                p.description,
                COALESCE(p.reorder_level, 5) AS reorder_level,
                c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         $whereSql
         ORDER BY p.stock ASC, p.name ASC$listLimit"
    );
    $listStmt->execute($params);
    $items = $listStmt->fetchAll();

    // Derive the per-SKU inventory position once, here, so the table, the
    // alerts, the demand card and the Excel export all read the same numbers.
    foreach ($items as &$it) {
        $pid = (int)$it['id'];
        $it['committed']  = $committedMap[$pid] ?? 0;
        // Not stock - committed: those units left `stock` at checkout. See the
        // note at the top of this file.
        $it['available']  = (int)$it['stock'];
        $it['sold_30d']   = $soldMap[$pid] ?? 0;
        $it['velocity']   = $it['sold_30d'] / 30;                 // units/day
        $it['days_cover'] = $it['velocity'] > 0
            ? $it['available'] / $it['velocity']
            : null;                                                // no demand
        $it['state']      = inv_state($it['available'], (int)$it['reorder_level']);
    }
    unset($it);

    if ($lowOnly) {
        $items = array_values(array_filter(
            $items,
            fn($x) => in_array($x['state'], ['oversold', 'out', 'low'], true)
        ));
    }

    // -- 2. Alerts: computed over the WHOLE catalog ---------------------------
    // Deliberately not derived from $items: that list is filtered and capped,
    // so alerts built from it would quietly go silent the moment an admin typed
    // into the search box - the exact moment they most need to be right.
    $alertRows = $pdo->query(
        "SELECT p.id, p.name, p.stock, COALESCE(p.reorder_level,5) AS reorder_level,
                COALESCE(oc.qty, 0) AS committed
         FROM products p
         LEFT JOIN (
             SELECT oi.product_id, SUM(oi.quantity) AS qty
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.status IN ($openIn)
             GROUP BY oi.product_id
         ) oc ON oc.product_id = p.id
         WHERE p.status = 'active'
         ORDER BY p.stock ASC, p.name ASC"
    )->fetchAll();

    $alerts = ['oversold' => [], 'out' => [], 'low' => []];
    foreach ($alertRows as $r) {
        $available = (int)$r['stock'];
        $state = inv_state($available, (int)$r['reorder_level']);
        if (isset($alerts[$state])) {
            $r['available'] = $available;
            $alerts[$state][] = $r;
        }
    }

    // -- KPI snapshot (whole catalog, never the filtered view) ---------------
    $kpi = $pdo->query(
        "SELECT COUNT(*) AS total_items,
                COALESCE(SUM(stock),0) AS total_units,
                COALESCE(SUM(stock * price),0) AS stock_value
         FROM products WHERE status = 'active'"
    )->fetch() ?: ['total_items'=>0,'total_units'=>0,'stock_value'=>0];

    $kpi['committed'] = array_sum($committedMap);
    // SUM(stock) is the sellable total already - see the file header.
    $kpi['available'] = (int)$kpi['total_units'];
    $kpi['low_sku']   = count($alerts['low']);
    $kpi['out_sku']   = count($alerts['out']);
    $kpi['oversold']  = count($alerts['oversold']);

    // -- Defective units, and the failure rate they imply ---------------------
    //
    // "Defective" is counted from the two places this app actually records a
    // fault, and only where someone confirmed it:
    //   * return_requests with reason='defective' - units, via return_items
    //   * warranty_claims - one unit per claim
    // Rejected requests/claims are excluded: a rejected claim is a report that
    // was examined and found not to be a fault, so counting it would inflate
    // the rate with the very cases that were checked and dismissed.
    //
    // Failure rate is defective units over UNITS SOLD, not over stock on hand -
    // a fault rate is a property of what shipped, and dividing by inventory
    // would make the rate move every time stock is replenished.
    //
    // Both tables ship in 40_support_and_service.sql but are probed first, the
    // same way the rest of this app guards optional tables, so an install
    // without them degrades this one card instead of failing the page.
    $defectRows      = [];   // per brand
    $defectiveUnits  = 0;
    $defectUnitsSold = 0;
    $failureRate     = null; // null = nothing sold yet, so no rate can be stated

    $__invHas = static function (PDO $pdo, string $table): bool {
        $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };

    try {
        $hasReturns  = $__invHas($pdo, 'return_requests') && $__invHas($pdo, 'return_items');
        $hasWarranty = $__invHas($pdo, 'warranty_claims');

        // Units sold per product - the denominator.
        $soldMap = [];
        foreach ($pdo->query(
            "SELECT oi.product_id, COALESCE(SUM(oi.quantity),0) AS qty
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.status <> 'cancelled'
             GROUP BY oi.product_id"
        )->fetchAll() as $r) {
            $soldMap[(int)$r['product_id']] = (int)$r['qty'];
        }

        // Defective units per product - the numerator, from both sources.
        $defectMap = [];
        if ($hasReturns) {
            foreach ($pdo->query(
                "SELECT oi.product_id, COALESCE(SUM(ri.quantity),0) AS qty
                 FROM return_requests rr
                 JOIN return_items ri ON ri.return_id = rr.id
                 JOIN order_items oi  ON oi.id = ri.order_item_id
                 WHERE rr.reason = 'defective' AND rr.status <> 'rejected'
                 GROUP BY oi.product_id"
            )->fetchAll() as $r) {
                $defectMap[(int)$r['product_id']] = ($defectMap[(int)$r['product_id']] ?? 0) + (int)$r['qty'];
            }
        }
        if ($hasWarranty) {
            foreach ($pdo->query(
                "SELECT product_id, COUNT(*) AS qty
                 FROM warranty_claims
                 WHERE status <> 'rejected'
                 GROUP BY product_id"
            )->fetchAll() as $r) {
                $defectMap[(int)$r['product_id']] = ($defectMap[(int)$r['product_id']] ?? 0) + (int)$r['qty'];
            }
        }

        // Roll both up by brand. The catalog has no brand column, so the brand
        // comes from product_tags where an admin or the backfill has recorded
        // one, and falls back to pt_extract_brand() on the name - the same
        // canonical function the tagger itself uses, so 'Hp' and 'HP' cannot
        // become two brands here while being one everywhere else.
        require_once __DIR__ . '/../../../config/product_tagger.php';

        $tagBrand = [];
        if ($__invHas($pdo, 'product_tags')) {
            foreach ($pdo->query(
                "SELECT product_id, tag_value FROM product_tags WHERE tag_key = 'brand'"
            )->fetchAll() as $r) {
                $tagBrand[(int)$r['product_id']] = (string)$r['tag_value'];
            }
        }

        $byBrand = [];
        foreach ($pdo->query("SELECT id, name FROM products")->fetchAll() as $prod) {
            $pid  = (int)$prod['id'];
            $sold = $soldMap[$pid]   ?? 0;
            $bad  = $defectMap[$pid] ?? 0;
            if ($sold === 0 && $bad === 0) { continue; }

            $brand = $tagBrand[$pid]
                ?? (function_exists('pt_extract_brand') ? pt_extract_brand((string)$prod['name']) : null)
                ?? 'Unbranded';

            if (!isset($byBrand[$brand])) { $byBrand[$brand] = ['brand' => $brand, 'sold' => 0, 'defective' => 0]; }
            $byBrand[$brand]['sold']      += $sold;
            $byBrand[$brand]['defective'] += $bad;

            $defectUnitsSold += $sold;
            $defectiveUnits  += $bad;
        }

        foreach ($byBrand as &$b) {
            $b['rate'] = $b['sold'] > 0 ? round(($b['defective'] / $b['sold']) * 100, 1) : null;
        }
        unset($b);

        // Worst first - the point of the breakdown is which brand to chase.
        // Brands with no sales have no rate, so they sort last rather than
        // ranking above a brand with a real, measured failure rate.
        uasort($byBrand, static function ($x, $y) {
            return [$y['rate'] ?? -1, $y['defective']] <=> [$x['rate'] ?? -1, $x['defective']];
        });
        $defectRows = array_values($byBrand);

        if ($defectUnitsSold > 0) {
            $failureRate = round(($defectiveUnits / $defectUnitsSold) * 100, 1);
        }
    } catch (Throwable $e) {
        error_log('Inventory defect KPI error: ' . $e->getMessage());
    }

    // -- 5. Loss prevention ---------------------------------------------------
    // Units that left the sellable pool without being sold. Each figure is a
    // different failure mode, so they are reported separately rather than
    // rolled into one meaningless "shrinkage" number.
    $loss = $pdo->query(
        "SELECT
           (SELECT COALESCE(SUM(oi.quantity),0)
              FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'cancelled'
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS cancelled_units_30d,
           (SELECT COUNT(*) FROM orders
             WHERE status = 'cancelled'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))    AS cancelled_orders_30d,
           (SELECT COUNT(*) FROM order_cancellation_requests
             WHERE status = 'pending')                                AS pending_cancel_requests,
           (SELECT COALESCE(SUM(ri.quantity),0)
              FROM return_items ri JOIN return_requests rr ON rr.id = ri.return_id
             WHERE rr.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS returned_units_30d,
           (SELECT COUNT(*) FROM stock_movements
             WHERE reference_type = 'manual'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))    AS adjustments_30d,
           (SELECT COALESCE(SUM(CASE WHEN movement_type = 'out' THEN -quantity ELSE quantity END),0)
              FROM stock_movements
             WHERE reference_type = 'manual'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))    AS adjustment_units_30d"
    )->fetch() ?: [];

    // Most recent manual adjustments - the audit trail for anything that moved
    // stock without an order behind it.
    $recentAdjustments = $pdo->query(
        "SELECT sm.id, sm.quantity, sm.movement_type, sm.notes, sm.created_at, p.name AS product_name
         FROM stock_movements sm
         LEFT JOIN products p ON p.id = sm.product_id
         WHERE sm.reference_type = 'manual'
         ORDER BY sm.created_at DESC
         LIMIT 8"
    )->fetchAll();

} catch (PDOException $e) {
    error_log('Inventory Control load error: ' . $e->getMessage());
    // Surfaced on the page, not just swallowed: this catch previously rendered a
    // perfectly normal-looking page of zeros and "No products found" when the
    // query failed, which is how a missing products.reorder_level column went
    // unnoticed - the page looked like an empty warehouse rather than a fault.
    $loadError = 'Inventory data could not be loaded. The database reported: ' . $e->getMessage();
    $items = [];
    $kpi = ['total_items'=>0,'total_units'=>0,'stock_value'=>0,'committed'=>0,
            'available'=>0,'low_sku'=>0,'out_sku'=>0,'oversold'=>0];
    $soldMap = $committedMap = $openOrders = $recentAdjustments = $openLines = [];
    $alerts = ['oversold'=>[], 'out'=>[], 'low'=>[]];
    $loss = [];
}

/**
 * Inventory state for a SKU, driven by AVAILABLE (on hand minus committed)
 * rather than raw stock. Order matters: oversold is checked first because a
 * negative available is also <= 0 and would otherwise read as merely "out".
 */
function inv_state(int $available, int $reorderLevel): string {
    if ($available < 0)                { return 'oversold'; }
    if ($available === 0)              { return 'out'; }
    if ($available <= $reorderLevel)   { return 'low'; }
    return 'ok';
}

function inv_state_label(string $state): string {
    return ['oversold' => 'Oversold', 'out' => 'Out of stock',
            'low' => 'Reorder', 'ok' => 'OK'][$state] ?? $state;
}

function inv_state_color(string $state): string {
    return ['oversold' => 'var(--admin-danger)', 'out' => 'var(--admin-danger)',
            'low' => '#b45309', 'ok' => 'var(--admin-success)'][$state] ?? 'var(--admin-muted)';
}

/** Days of cover, or an em dash when there is no demand to divide by. */
function inv_cover_label(?float $days): string {
    if ($days === null)  { return '—'; }
    if ($days < 0)       { return '0'; }
    if ($days >= 999)    { return '999+'; }
    return number_format($days, $days < 10 ? 1 : 0);
}

// ----------------------------------------------------- 6. Reporting: Excel ---
// The report is Inventory-Template.xlsx filled in, not a CSV. See
// shared/classes/InventoryTemplateWorkbook.php for the mapping; the short
// version is that this hands over the rows and the template's own formulas
// produce every total, so the workbook's Stock Available is the same
// "on hand minus committed" the table above shows.
//
// Streamed before any HTML is emitted, so the same filters the admin is
// looking at produce the file they download.
const INV_TEMPLATE_FILE = 'Inventory-Template.xlsx';

if ($export && $loadError === null) {
    require_once __DIR__ . '/../../shared/classes/InventoryTemplateWorkbook.php';

    try {
        $book = InventoryTemplateWorkbook::build(
            $items,
            $openLines,
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . INV_TEMPLATE_FILE,
            date('Y-m-d')
        );
    } catch (Throwable $e) {
        // Built entirely in memory first, so a failure here has emitted no
        // bytes and no download headers - the admin gets the page with an
        // explanation instead of a 0-byte or truncated .xlsx that Excel will
        // only describe as corrupt.
        error_log('Inventory Excel export error: ' . $e->getMessage());
        $loadError = 'The Excel report could not be generated: ' . $e->getMessage();
        $export = false;
    }

    if ($export) {
        $filename = 'inventory_control_' . date('Y-m-d_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($book));
        header('Cache-Control: no-store');
        echo $book;
        exit;
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<main class="container">
  <style>

    /* Status pill in the tracking table and the alert lists. */
    .inv-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;
              font-weight:700;letter-spacing:.02em;color:#fff;white-space:nowrap;}
    .inv-num{text-align:right;font-variant-numeric:tabular-nums;}
    .inv-section-head{padding:12px 16px;border-bottom:1px solid var(--admin-border);
                      color:var(--admin-text);font-weight:700;display:flex;
                      justify-content:space-between;align-items:center;gap:10px;}
    .inv-section-head small{font-weight:500;color:var(--admin-muted);}
    .inv-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;}
    .inv-kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;
            border-bottom:1px solid var(--admin-border);}
    .inv-kv:last-child{border-bottom:none;}
    .inv-kv span:last-child{font-weight:700;color:var(--admin-text);
                            font-variant-numeric:tabular-nums;}
    @media (max-width: 1100px){ .inv-grid-2{grid-template-columns:1fr;} }

    /* Print: drop the chrome so a printed copy is the report, not the app. */
    @media print{
      .admin-table-wrap, .admin-card { break-inside: avoid; }
    }
  </style>
  <?php
    // Moved up from the filter row so the header buttons can use it. Same
    // expression, same inputs - it just has to exist before it is printed now.
    // Carry the current filters into the export so the file matches the view.
    $exportQs = http_build_query(array_filter([
        'search' => $search, 'low' => $lowOnly ? '1' : null, 'export' => 'xlsx',
    ], fn($v) => $v !== null && $v !== ''));
  ?>
  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='product_management.php'; }">Products</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Inventory Control</span>
      </nav>
      <h1 class="adm-title">Inventory Control</h1>
    </div>
    <?php /* The links that used to sit in the filter row, moved up here. Both
             are plain anchors, so nothing about where they go or what they do
             changes - only where they sit. */ ?>
    <div class="adm-actions inv-no-print">
      <a href="receive_delivery.php" class="ad-btn ad-btn--primary">Receive Delivery</a>
      <a href="defective_pullout.php" class="ad-btn ad-btn--secondary">Defective / Pull-Out</a>
      <a href="stock_movements.php" class="ad-btn ad-btn--secondary">Movement History</a>
      <a href="inventory_reports.php" class="ad-btn ad-btn--secondary">Reports</a>
      <a href="?<?= htmlspecialchars($exportQs) ?>" class="ad-btn ad-btn--secondary">Export Excel</a>
      <a href="demand_forecast.php" class="ad-btn ad-btn--dark">Demand Forecast &amp; Reorder</a>
    </div>
  </div>
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:1400px;">
    <?php /* The <h1> moved into the header row above; this keeps the lede. */ ?>
    <p class="adm-lede">Real-time tracking, alerts, demand monitoring, order integration, loss prevention, and reporting.</p>

    <?php
      /* FLAGGED ITEMS - "Poorly Sold - For Pull-Out".
       *
       * Computed on page load, which is one of the two options the brief
       * allows. It is DERIVED from three facts the database already holds
       * (status = discontinued, stock > 0, date of last sale) rather than
       * stored in a column, so there is no scheduled job that can fail to run
       * and no stored flag that can go stale - see inv_flagged_items().
       *
       * The threshold is the Owner's setting; only they can change it, from
       * super-admin/pullout_approvals.php.
       */
      $invFlagged = [];
      $invSlowDays = INV_SLOW_MOVING_DEFAULT_DAYS;
      try {
          $invSlowDays = inv_slow_moving_days($pdo);
          $invFlagged  = inv_flagged_items($pdo, $invSlowDays);
      } catch (Throwable $e) {
          error_log('inventory_control flagged: ' . $e->getMessage());
      }
    ?>
    <div class="ad-card inv-flagged" style="margin-bottom:24px;">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Flagged Items &mdash; Poorly Sold / For Pull-Out</h2>
        <span class="ad-card-note">
          Discontinued, still holding stock, unsold for <?= (int)$invSlowDays ?>+ days
        </span>
      </div>
      <?php if (!$invFlagged): ?>
        <div class="ad-card-body">
          <p class="ad-hint" style="margin:0;">
            Nothing flagged. A product appears here once it is marked
            <strong>Discontinued</strong> on its Edit Product page, still has stock, and has had no
            sales for <?= (int)$invSlowDays ?> days.
          </p>
        </div>
      <?php else: ?>
        <div class="ad-table-wrap">
          <table class="ad-table">
            <thead><tr>
              <th>Product</th><th>Brand</th><th>Category</th>
              <th style="text-align:right;">Units left</th>
              <th style="text-align:right;">Days unsold</th>
              <th>Last sale</th><th>Added to stock</th><th class="inv-no-print"></th>
            </tr></thead>
            <tbody>
              <?php foreach ($invFlagged as $fi): ?>
                <tr>
                  <td style="font-weight:600;"><?= htmlspecialchars((string)$fi['name']) ?></td>
                  <td><?= htmlspecialchars((string)($fi['brand'] ?? '—')) ?></td>
                  <td><?= htmlspecialchars((string)($fi['category_name'] ?? '—')) ?></td>
                  <td style="text-align:right;font-weight:700;"><?= (int)$fi['stock'] ?></td>
                  <td style="text-align:right;"><?= (int)$fi['days_unsold'] ?></td>
                  <td style="font-size:12px;">
                    <?= $fi['last_sale_date']
                        ? htmlspecialchars(date('j M Y', strtotime((string)$fi['last_sale_date'])))
                        : '<em>never sold</em>' ?>
                  </td>
                  <td style="font-size:12px;"><?= htmlspecialchars(date('j M Y', strtotime((string)$fi['date_added']))) ?></td>
                  <td style="text-align:right;" class="inv-no-print">
                    <?php /* Starts the SAME approval flow as any other
                             pull-out, with the source pre-filled - the brief
                             asks for one flow, not a second one for slow
                             movers. */ ?>
                    <a class="ad-btn ad-btn--secondary"
                       href="defective_pullout.php?product_id=<?= (int)$fi['id'] ?>&amp;source=slow_moving">
                      Start pull-out
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($loadError !== null): ?>
      <div style="background:rgba(194,104,111,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-danger);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px;">
          <i class="fi fi-rr-exclamation-triangle" style="font-size:16px;color:var(--admin-danger);"></i>
          <?= htmlspecialchars($loadError) ?>
        </p>
      </div>
    <?php endif; ?>

    <!-- KPI snapshot. Always the whole catalog, never the filtered view: these
         are the numbers an admin glances at to decide whether to act at all. -->
    <style>
      /* Four fixed tracks, not auto-fit at minmax(150px,1fr). auto-fit packed
         as many 150px columns in as would fit, and "Stock Value" - a peso
         figure that can run to eight digits - simply did not fit one. A fixed
         count gives every card the same, predictable width instead of a width
         that depends on how many siblings happen to be on the row. */
      .inv-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px;}
      /* min-width:0 is what actually lets a card be narrower than its content
         wants; without it a long number forces the track wider and the row
         stops being even. min-height keeps both rows the same depth. */
      .inv-kpis .stat-card{padding:16px;text-align:left;min-width:0;min-height:86px;
        display:flex;flex-direction:column;justify-content:center;gap:4px;}
      .inv-kpis .stat-label{font-size:12px;line-height:1.3;}
      /* One fixed size for every card - tabular-nums so the digits align, and
         nowrap so a value never wraps into a second line and makes its card
         taller than the rest. */
      .inv-kpis .stat-number{font-size:22px;line-height:1.2;white-space:nowrap;
        font-variant-numeric:tabular-nums;}
      /* The money card carries a currency mark and the most digits, so it gets
         one step down rather than being allowed to overrun its card. */
      .inv-kpis .inv-kpi--money .stat-number{font-size:19px;}
      /* The defect card is a <button>, so it needs the browser's button
         defaults stripped before it can look like its neighbours. */
      .inv-kpis .inv-kpi--defect{font:inherit;color:inherit;text-align:left;border:0;
        width:100%;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease;}
      .inv-kpis .inv-kpi--defect:hover{transform:translateY(-2px);}
      .inv-kpis .inv-kpi--defect:focus-visible{outline:2px solid var(--admin-primary-2);outline-offset:2px;}
      .inv-kpis .inv-kpi-sub{font-size:11px;color:var(--admin-muted);white-space:nowrap;
        overflow:hidden;text-overflow:ellipsis;}

      /* modal */
      .inv-modal{position:fixed;inset:0;z-index:9000;}
      .inv-modal[hidden]{display:none;}
      .inv-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,16,.7);}
      .inv-modal-panel{position:relative;width:min(620px,calc(100% - 32px));max-height:86vh;
        margin:7vh auto;display:flex;flex-direction:column;overflow:hidden;
        background:var(--admin-surface);color:var(--admin-text);
        border:1px solid var(--admin-border);border-radius:14px;box-shadow:0 30px 80px rgba(0,0,0,.55);}
      .inv-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
        padding:16px 20px;border-bottom:1px solid var(--admin-border);}
      .inv-modal-head h3{margin:0;font-size:1.05rem;}
      .inv-modal-head p{margin:3px 0 0;font-size:12px;color:var(--admin-muted);}
      .inv-modal-close{width:32px;height:32px;flex:0 0 auto;font:inherit;cursor:pointer;
        color:var(--admin-text);background:var(--admin-surface-2);
        border:1px solid var(--admin-border);border-radius:8px;}
      .inv-modal-body{padding:16px 20px 20px;overflow:auto;}
      .inv-modal-empty,.inv-modal-note{font-size:12px;color:var(--admin-muted);}
      .inv-modal-note{margin:12px 0 0;line-height:1.5;}
      .inv-defect-table{width:100%;border-collapse:collapse;font-size:13px;}
      .inv-defect-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;
        color:var(--admin-muted);padding:0 10px 8px 0;border-bottom:1px solid var(--admin-border);}
      .inv-defect-table td{padding:9px 10px 9px 0;border-bottom:1px solid var(--admin-border);}
      .inv-defect-table tr:last-child td{border-bottom:0;}
      .inv-defect-table .num{text-align:right;padding-right:0;font-variant-numeric:tabular-nums;}
      .inv-rate{font-weight:700;}
      .inv-rate--warn{color:#b45309;}
      .inv-rate--bad{color:var(--admin-danger);}
      .inv-rate--none{color:var(--admin-muted);font-weight:400;}

      /* pager */
      .inv-pager{display:flex;align-items:center;justify-content:center;gap:6px;
        padding:12px;border-top:1px solid var(--admin-border);}
      .inv-page{display:inline-flex;align-items:center;justify-content:center;
        min-width:32px;height:32px;padding:0 9px;font-size:13px;line-height:1;
        color:var(--admin-text);background:var(--admin-surface);
        border:1px solid var(--admin-border);border-radius:8px;text-decoration:none;
        font-variant-numeric:tabular-nums;transition:border-color .15s ease,background .15s ease;}
      .inv-page:hover{border-color:var(--admin-primary-2);text-decoration:none;}
      .inv-page.is-current{background:var(--admin-primary-2);border-color:var(--admin-primary-2);
        color:#fff;font-weight:700;}
      .inv-page.is-disabled{opacity:.4;cursor:default;}

      @media (max-width:1100px){.inv-kpis{grid-template-columns:repeat(3,minmax(0,1fr));}}
      @media (max-width:820px){.inv-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}}
      @media (max-width:520px){.inv-kpis{grid-template-columns:minmax(0,1fr);}}
    </style>
    <?php
      /* Four tiles instead of eight. Every number below comes from the same
         $kpi array the eight tiles read - no new query, nothing dropped: the
         four that lost their own tile (Total SKUs, Units on Hand, Committed,
         Defective) now appear as sub-lines on the tile they explain.

         "Defective" keeps its own <button id="invDefectCard">, because the
         script further down binds the by-brand modal to that id. It is now a
         link-styled button inside the Stock Value sub-line rather than a tile
         of its own; the id, the aria wiring and the handler are untouched. */
      $invOut      = (int)($kpi['out_sku'] ?? 0);
      $invOversold = (int)($kpi['oversold'] ?? 0);
      $invAttention = $invOut + $invOversold;

      /* Shared by the alert strip below and the filter tabs further down: a
         URL onto this page's existing `search` / `low` params. */
      $invTabUrl = static function (bool $low) use ($search): string {
          $qs = array_filter([
              'search' => $search !== '' ? $search : null,
              'low'    => $low ? '1' : null,
          ], static fn($v) => $v !== null);
          return $qs ? '?' . http_build_query($qs) : '?';
      };
    ?>
    <div class="ad-kpis">
      <div class="ad-kpi">
        <div class="ad-kpi-label">Available to sell</div>
        <div class="ad-kpi-value"><?= number_format((int)($kpi['available'] ?? 0)) ?></div>
        <div class="ad-kpi-sub">
          <?= number_format((int)($kpi['committed'] ?? 0)) ?> more committed to open orders
        </div>
      </div>

      <div class="ad-kpi">
        <div class="ad-kpi-label">Needs reorder</div>
        <div class="ad-kpi-value<?= ((int)($kpi['low_sku'] ?? 0)) > 0 ? ' ad-kpi-value--warn' : '' ?>"><?= number_format((int)($kpi['low_sku'] ?? 0)) ?></div>
        <div class="ad-kpi-sub">At or below reorder level</div>
      </div>

      <div class="ad-kpi">
        <div class="ad-kpi-label">Out / oversold</div>
        <div class="ad-kpi-value<?= $invAttention > 0 ? ' ad-kpi-value--danger' : '' ?>"><?= number_format($invAttention) ?></div>
        <div class="ad-kpi-sub"><?= number_format($invOut) ?> out &middot; <?= number_format($invOversold) ?> oversold</div>
      </div>

      <div class="ad-kpi">
        <div class="ad-kpi-label">Stock value</div>
        <div class="ad-kpi-value">&#8369;<?= number_format((float)($kpi['stock_value'] ?? 0)) ?></div>
        <div class="ad-kpi-sub">
          <?= number_format((int)($kpi['total_items'] ?? 0)) ?> SKUs &middot;
          <button type="button" class="inv-defect-link" id="invDefectCard"
                  aria-haspopup="dialog" aria-controls="invDefectModal">
            <?= number_format($defectiveUnits) ?> defective<?php if ($failureRate !== null): ?> (<?= number_format($failureRate, 1) ?>%)<?php endif; ?>
          </button>
        </div>
      </div>
    </div>

    <?php /* Only when there is something to act on. "Show only these" is the
             Needs attention tab's own URL, so it ticks the same `low=1` filter
             the checkbox used to and reloads through the same code path. */ ?>
    <?php if ($invAttention > 0): ?>
      <div class="ad-alert inv-no-print">
        <span class="ad-alert-dot" aria-hidden="true"></span>
        <?php /* Oversold is named only when there is one. It is a rare state
                 now (stock edited below what is already promised, or a
                 checkout race) rather than the everyday reading it was when
                 this page subtracted committed units a second time. */ ?>
        <span>
          <?php if ($invOversold > 0): ?>
            <strong><?= number_format($invOversold) ?></strong> product<?= $invOversold === 1 ? '' : 's' ?> oversold,
            <strong><?= number_format($invOut) ?></strong> out of stock.
          <?php else: ?>
            <strong><?= number_format($invOut) ?></strong> product<?= $invOut === 1 ? '' : 's' ?> out of stock.
          <?php endif; ?>
        </span>
        <a href="<?= htmlspecialchars($invTabUrl(true)) ?>">Show only these</a>
      </div>
    <?php endif; ?>

    <!-- Defective units by brand -->
    <div class="inv-modal inv-no-print" id="invDefectModal" role="dialog" aria-modal="true"
         aria-labelledby="invDefectTitle" hidden>
      <div class="inv-modal-backdrop" data-inv-close></div>
      <div class="inv-modal-panel">
        <div class="inv-modal-head">
          <div>
            <h3 id="invDefectTitle">Defective Units by Brand</h3>
            <p>
              <?= number_format($defectiveUnits) ?> defective of <?= number_format($defectUnitsSold) ?> sold
              <?= $failureRate === null ? '' : ' &middot; ' . number_format($failureRate, 1) . '% overall' ?>
            </p>
          </div>
          <button type="button" class="inv-modal-close" data-inv-close aria-label="Close">&#10005;</button>
        </div>
        <div class="inv-modal-body">
          <?php if (empty($defectRows)): ?>
            <p class="inv-modal-empty">No sales or defect reports recorded yet.</p>
          <?php else: ?>
            <table class="inv-defect-table">
              <thead>
                <tr><th>Brand</th><th class="num">Defective</th><th class="num">Sold</th><th class="num">Failure rate</th></tr>
              </thead>
              <tbody>
                <?php foreach ($defectRows as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars((string)$row['brand']) ?></td>
                    <td class="num"><?= number_format((int)$row['defective']) ?></td>
                    <td class="num"><?= number_format((int)$row['sold']) ?></td>
                    <td class="num">
                      <?php if ($row['rate'] === null): ?>
                        <span class="inv-rate inv-rate--none">&mdash;</span>
                      <?php else: ?>
                        <?php // 5% is the line this page treats as "chase it";
                              // above 2% is worth watching. Colour only, so the
                              // number is still the thing being read. ?>
                        <span class="inv-rate <?= $row['rate'] >= 5 ? 'inv-rate--bad' : ($row['rate'] >= 2 ? 'inv-rate--warn' : '') ?>">
                          <?= number_format((float)$row['rate'], 1) ?>%
                        </span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="inv-modal-note">
              Counted from confirmed defective returns and warranty claims, against units sold.
              Rejected claims are excluded. A brand with no sales shows no rate rather than 0%.
            </p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <script>
    (function () {
      var card  = document.getElementById('invDefectCard');
      var modal = document.getElementById('invDefectModal');
      if (!card || !modal) return;

      // position:fixed is measured against the nearest transformed ancestor,
      // and this page's cards animate in on load - re-parenting to <body>
      // keeps the overlay pinned to the viewport regardless.
      if (modal.parentNode !== document.body) document.body.appendChild(modal);

      function open() {
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        var x = modal.querySelector('.inv-modal-close');
        if (x) x.focus();
      }
      function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
        card.focus();
      }

      card.addEventListener('click', open);
      Array.prototype.forEach.call(modal.querySelectorAll('[data-inv-close]'), function (el) {
        el.addEventListener('click', close);
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) close();
      });
    })();
    </script>

    <!-- 6. Reporting: filters carry through to both the Excel export and the print view. -->

    <!-- 1. Real-time tracking + 3. demand, per SKU ------------------------- -->
    <?php
    // Paged in PHP over the rows already fetched, not with a SQL LIMIT: the
    // query above is capped at 500 and every derived column (committed,
    // available, velocity, days cover, state) is computed in PHP afterwards, so
    // a SQL LIMIT would page over rows that had not been scored yet. Slicing
    // here keeps one code path and one definition of the sort order.
    $invPerPage    = 8;
    $invTotalItems = count($items);
    $invTotalPages = max(1, (int)ceil($invTotalItems / $invPerPage));
    // Clamped, so ?p=99 or ?p=-1 lands on a real page instead of an empty table.
    $invPage       = max(1, min($invTotalPages, (int)($_GET['p'] ?? 1)));
    $invPageItems  = array_slice($items, ($invPage - 1) * $invPerPage, $invPerPage);
    $invFirstRow   = $invTotalItems === 0 ? 0 : (($invPage - 1) * $invPerPage) + 1;
    $invLastRow    = min($invPage * $invPerPage, $invTotalItems);

    // Page links carry the current filters, so paging does not silently reset
    // the search box or the "needs attention only" checkbox.
    $invPageUrl = static function (int $n) use ($search, $lowOnly): string {
        $qs = array_filter([
            'search' => $search !== '' ? $search : null,
            'low'    => $lowOnly ? '1' : null,
            'p'      => $n > 1 ? (string)$n : null,
        ], static fn($v) => $v !== null);
        return '?' . http_build_query($qs);
    };

    // A window of at most 5 numbers around the current page, shifted to stay
    // full at either end - so page 1 shows 1-5 and the last page shows the
    // last five, rather than a half-empty strip.
    $invWindow = 5;
    $invFrom   = max(1, min($invPage - (int)floor($invWindow / 2), $invTotalPages - $invWindow + 1));
    $invFrom   = max(1, $invFrom);
    $invTo     = min($invTotalPages, $invFrom + $invWindow - 1);
    ?>
    <?php
      /* The filter row that used to sit above the table now lives in the
         table card's head, as tabs + search.

         The tabs are plain links onto the SAME query params the page already
         reads ($search / $lowOnly, read at the top of this file):
             All             -> no `low`
             Needs attention -> low=1
         There are only two, because those are the only two states the page
         has a parameter for - see the note returned with this change.

         The form still submits exactly what it submitted before: a `search`
         text field, and `low=1` when that filter is on. The checkbox became a
         hidden input because the tabs now own that choice; an unchecked
         checkbox and an absent hidden input both submit nothing, so the
         payload is identical either way. Apply is still a real submit button,
         just visually hidden, so Enter in the search box behaves as before. */
    ?>
    <div class="ad-card">
      <div class="ad-card-head">
        <div class="ad-tabs inv-no-print" role="tablist" aria-label="Inventory filter">
          <a class="ad-tab<?= $lowOnly ? '' : ' is-on' ?>" role="tab"
             aria-selected="<?= $lowOnly ? 'false' : 'true' ?>"
             href="<?= htmlspecialchars($invTabUrl(false)) ?>">All</a>
          <a class="ad-tab<?= $lowOnly ? ' is-on' : '' ?>" role="tab"
             aria-selected="<?= $lowOnly ? 'true' : 'false' ?>"
             href="<?= htmlspecialchars($invTabUrl(true)) ?>">
            Needs attention
            <?php if ($invAttention + (int)($kpi['low_sku'] ?? 0) > 0): ?>
              <span class="ad-tab-n"><?= number_format($invAttention + (int)($kpi['low_sku'] ?? 0)) ?></span>
            <?php endif; ?>
          </a>
        </div>

        <form method="GET" class="ad-search inv-no-print">
          <?php if ($lowOnly): ?>
            <input type="hidden" name="low" value="1">
          <?php endif; ?>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                 placeholder="Search product or category..." aria-label="Search product or category">
          <button type="submit" class="ad-sr">Apply</button>
        </form>
      </div>

      <div class="ad-card-head" style="border-bottom:1px solid var(--ad-line);">
        <h2 class="ad-card-title">Real-Time Tracking</h2>
        <span class="ad-card-note">
          <?php if ($invTotalItems === 0): ?>
            No SKUs
          <?php else: ?>
            <?= number_format($invFirstRow) ?>&ndash;<?= number_format($invLastRow) ?>
            of <?= number_format($invTotalItems) ?> SKU<?= $invTotalItems === 1 ? '' : 's' ?>
          <?php endif; ?>
          <?php if (!$export && $invTotalItems >= 500): ?>(capped at 500 &ndash; export for the full list)<?php endif; ?>
          &middot; Available = stock free to sell &middot; Committed = already sold, not yet delivered
          <?php if ($search !== '' || $lowOnly): ?>
            &middot; <a href="inventory_control.php" style="color:var(--ad-primary-hover);font-weight:700;">Clear</a>
          <?php endif; ?>
        </span>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th class="ad-num">Available</th>
              <th class="ad-num">Committed</th>
              <th class="ad-num">Sold 30d</th>
              <th class="ad-num">Days left</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
              /* Reorder level and Units/Day lost their own columns: the first
                 is what Status already encodes, the second is Sold 30d / 30.
                 Both are still derivable from the Excel export's figures.
                 Chip class per state, instead of the old inline pill colour. */
              $invChip = ['oversold' => 'ad-chip--oversold', 'out' => 'ad-chip--out',
                          'low' => 'ad-chip--reorder', 'ok' => 'ad-chip--active'];
            ?>
            <?php if (empty($invPageItems)): ?>
              <tr><td colspan="7" class="ad-table-empty">No products found.</td></tr>
            <?php else: foreach ($invPageItems as $it): ?>
              <tr>
                <td style="font-weight:600;color:var(--ad-ink);"><?= htmlspecialchars($it['name']) ?></td>
                <td style="color:var(--ad-muted);"><?= htmlspecialchars($it['category_name'] ?? 'Uncategorized') ?></td>
                <td class="ad-num<?= $it['available'] < 0 ? ' ad-neg' : '' ?>" style="<?= $it['available'] < 0 ? '' : 'font-weight:700;color:var(--ad-ink);' ?>"><?= (int)$it['available'] ?></td>
                <td class="ad-num" style="color:var(--ad-muted);"><?= (int)$it['committed'] ?></td>
                <td class="ad-num" style="color:var(--ad-muted);"><?= (int)$it['sold_30d'] ?></td>
                <td class="ad-num" style="color:var(--ad-muted);"><?= inv_cover_label($it['days_cover']) ?></td>
                <td><span class="ad-chip <?= $invChip[$it['state']] ?? 'ad-chip--inactive' ?>"><?= inv_state_label($it['state']) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($invTotalPages > 1): ?>
        <?php // inv-no-print: a pager is a screen control, and the print view
              // wants the rows, not a way to ask for more of them. ?>
        <nav class="inv-pager inv-no-print" aria-label="Real-time tracking pages">
          <?php if ($invPage > 1): ?>
            <a class="inv-page" href="<?= htmlspecialchars($invPageUrl($invPage - 1)) ?>" rel="prev" aria-label="Previous page">&#8249;</a>
          <?php else: ?>
            <?php // Rendered as a disabled span, not a link to nowhere: there is
                  // no previous page, so there should be nothing to click. ?>
            <span class="inv-page is-disabled" aria-hidden="true">&#8249;</span>
          <?php endif; ?>

          <?php for ($n = $invFrom; $n <= $invTo; $n++): ?>
            <?php if ($n === $invPage): ?>
              <span class="inv-page is-current" aria-current="page"><?= $n ?></span>
            <?php else: ?>
              <a class="inv-page" href="<?= htmlspecialchars($invPageUrl($n)) ?>"><?= $n ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($invPage < $invTotalPages): ?>
            <a class="inv-page" href="<?= htmlspecialchars($invPageUrl($invPage + 1)) ?>" rel="next" aria-label="Next page">&#8250;</a>
          <?php else: ?>
            <span class="inv-page is-disabled" aria-hidden="true">&#8250;</span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>

    <div class="inv-grid-2" style="margin-bottom:16px;">
      <!-- 2. Alerts ------------------------------------------------------- -->
      <div class="admin-card" style="padding:0;">
        <div class="inv-section-head">
          <span>Stock Alerts</span>
          <small>Whole catalog, ignores filters above</small>
        </div>
        <div class="inv-scroll" style="padding:16px;">
          <?php
            $alertOrder = [
              'oversold' => 'Oversold — stock is below what open orders already promise',
              'out'      => 'Out of stock — nothing available to sell',
              'low'      => 'At or below reorder level',
            ];
            $anyAlert = false;
          ?>
          <?php foreach ($alertOrder as $state => $heading): ?>
            <?php if (empty($alerts[$state])) { continue; } $anyAlert = true; ?>
            <div style="margin-bottom:14px;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <span class="ad-chip <?= ['oversold'=>'ad-chip--oversold','out'=>'ad-chip--out','low'=>'ad-chip--reorder'][$state] ?? 'ad-chip--inactive' ?>"><?= count($alerts[$state]) ?></span>
                <strong style="color:var(--admin-text);font-size:13px;"><?= htmlspecialchars($heading) ?></strong>
              </div>
              <?php foreach (array_slice($alerts[$state], 0, 12) as $a): ?>
                <div class="inv-kv" style="font-size:13px;color:var(--admin-muted);">
                  <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($a['name']) ?></span>
                  <span><?= (int)$a['available'] ?> avail · reorder at <?= (int)$a['reorder_level'] ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (count($alerts[$state]) > 12): ?>
                <div style="color:var(--admin-muted);font-size:12px;padding-top:6px;">
                  … and <?= number_format(count($alerts[$state]) - 12) ?> more
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$anyAlert): ?>
            <div style="color:var(--admin-muted);">No active alerts — every SKU is above its reorder level.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- 3. Demand monitoring -------------------------------------------- -->
      <div class="admin-card" style="padding:0;">
        <div class="inv-section-head">
          <span>Demand Monitoring</span>
          <small>Last 30 days, cancellations excluded</small>
        </div>
        <div class="inv-scroll" style="padding:16px;">
          <?php
            // Fastest movers, and of those, the ones that will run out soonest.
            $movers = $items;
            usort($movers, fn($a, $b) => $b['sold_30d'] <=> $a['sold_30d']);
            $movers = array_slice(array_filter($movers, fn($x) => $x['sold_30d'] > 0), 0, 10);

            $urgent = array_filter($items, fn($x) => $x['days_cover'] !== null && $x['days_cover'] < 14);
            usort($urgent, fn($a, $b) => $a['days_cover'] <=> $b['days_cover']);
            $urgent = array_slice($urgent, 0, 6);
          ?>
          <?php if ($urgent): ?>
            <div style="margin-bottom:16px;">
              <strong style="color:var(--admin-text);font-size:13px;">Runs out within 14 days at current pace</strong>
              <?php foreach ($urgent as $u): ?>
                <div class="inv-kv" style="font-size:13px;color:var(--admin-muted);">
                  <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($u['name']) ?></span>
                  <span style="color:#b45309;"><?= inv_cover_label($u['days_cover']) ?> days</span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <strong style="color:var(--admin-text);font-size:13px;">Top movers (30d)</strong>
          <?php if (empty($movers)): ?>
            <div style="color:var(--admin-muted);padding-top:6px;">No sales recorded in the last 30 days.</div>
          <?php else: foreach ($movers as $m): ?>
            <div class="inv-kv" style="font-size:13px;color:var(--admin-muted);">
              <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($m['name']) ?></span>
              <span><?= (int)$m['sold_30d'] ?> sold · <?= number_format($m['velocity'], 2) ?>/day</span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <div class="inv-grid-2">
      <!-- 4. Order integration -------------------------------------------- -->
      <div class="admin-card" style="padding:0;">
        <div class="inv-section-head">
          <span>Order Integration</span>
          <small>Open orders holding stock</small>
        </div>
        <div style="padding:16px;">
          <p style="margin:0 0 12px;color:var(--admin-muted);font-size:13px;">
            Units on orders that are placed but not yet completed. Stock is deducted
            the moment an order is placed, so these are already out of
            <strong>Available</strong> &mdash; this is what is still to be picked,
            packed and delivered.
          </p>
          <?php if (empty($openOrders)): ?>
            <div style="color:var(--admin-muted);">No open orders — nothing is holding stock.</div>
          <?php else: foreach ($openOrders as $o): ?>
            <div class="inv-kv">
              <span style="color:var(--admin-muted);text-transform:capitalize;"><?= htmlspecialchars($o['status']) ?></span>
              <span><?= number_format((int)$o['units']) ?> units · <?= number_format((int)$o['orders']) ?> order<?= (int)$o['orders'] === 1 ? '' : 's' ?></span>
            </div>
          <?php endforeach; endif; ?>
          <div class="inv-kv" style="border-top:2px solid var(--admin-border);margin-top:6px;padding-top:10px;">
            <span style="color:var(--admin-text);font-weight:700;">Total committed</span>
            <span><?= number_format((int)($kpi['committed'] ?? 0)) ?> units</span>
          </div>
          <?php if ((int)($kpi['oversold'] ?? 0) > 0): ?>
            <div style="margin-top:12px;padding:10px 12px;border-radius:10px;background:#fdecf1;border-left:3px solid #c23a63;color:#16233a;font-size:13px;">
              <strong><?= (int)$kpi['oversold'] ?> SKU<?= (int)$kpi['oversold'] === 1 ? '' : 's' ?> oversold.</strong>
              Stock has fallen below what open orders already promise — someone edited stock down,
              or two checkouts raced. Fulfil or cancel before taking more.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- 5. Loss prevention ---------------------------------------------- -->
      <div class="admin-card" style="padding:0;">
        <div class="inv-section-head">
          <span>Loss Prevention</span>
          <small>Last 30 days</small>
        </div>
        <div style="padding:16px;">
          <p style="margin:0 0 12px;color:var(--admin-muted);font-size:13px;">
            Units that left the sellable pool without a completed sale. Each
            line is a different failure mode, so they are tracked separately.
          </p>
          <div class="inv-kv">
            <span style="color:var(--admin-muted);">Cancelled order units</span>
            <span><?= number_format((int)($loss['cancelled_units_30d'] ?? 0)) ?>
              <span style="font-weight:400;color:var(--admin-muted);">(<?= number_format((int)($loss['cancelled_orders_30d'] ?? 0)) ?> orders)</span></span>
          </div>
          <div class="inv-kv">
            <span style="color:var(--admin-muted);">Cancellation requests pending</span>
            <span><?= number_format((int)($loss['pending_cancel_requests'] ?? 0)) ?></span>
          </div>
          <div class="inv-kv">
            <span style="color:var(--admin-muted);">Returned units</span>
            <span><?= number_format((int)($loss['returned_units_30d'] ?? 0)) ?></span>
          </div>
          <div class="inv-kv">
            <span style="color:var(--admin-muted);">Manual stock adjustments</span>
            <span><?= number_format((int)($loss['adjustments_30d'] ?? 0)) ?>
              <span style="font-weight:400;color:var(--admin-muted);">(<?= number_format((int)($loss['adjustment_units_30d'] ?? 0)) ?> units)</span></span>
          </div>

          <?php if (!empty($recentAdjustments)): ?>
            <div style="margin-top:14px;">
              <strong style="color:var(--admin-text);font-size:13px;">Recent adjustments</strong>
              <?php foreach ($recentAdjustments as $adj): ?>
                <div class="inv-kv" style="font-size:12.5px;color:var(--admin-muted);">
                  <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($adj['product_name'] ?? 'Deleted product') ?>
                    <?php if (!empty($adj['notes'])): ?>
                      — <?= htmlspecialchars($adj['notes']) ?>
                    <?php endif; ?>
                  </span>
                  <span><?= (int)$adj['quantity'] ?> · <?= htmlspecialchars(date('M j', strtotime((string)$adj['created_at']))) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="margin-top:12px;padding:10px 12px;border-radius:var(--radius-sm);background:var(--admin-surface);color:var(--admin-muted);font-size:12.5px;">
              No manual stock changes yet. Restocks and exact-stock corrections
              made in Product Management are recorded here, so any change with
              no order behind it can be traced back to who made it.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
