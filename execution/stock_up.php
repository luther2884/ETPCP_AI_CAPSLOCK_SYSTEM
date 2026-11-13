<?php
declare(strict_types=1);

/**
 * stock_up.php - top every active product up to a target stock level.
 *
 * Layer 3 (execution). Built for filling a dev/demo shelf so the PC
 * configurator and the AI recommender have something to work with: both only
 * ever consider products with `status = 'active' AND stock > 0`, so a shelf
 * sitting at 1-4 units makes most builds come back "few in stock" or
 * unavailable.
 *
 * WHY THIS IS NOT "UPDATE products SET stock = 50"
 *
 * For a product with requires_serial = 1 that already has unit rows,
 * products.stock is a MIRROR of COUNT(*) over product_units WHERE
 * status = 'in_stock' - see config/product_units.php. pu_recount_stock() runs
 * after every unit mutation anywhere in the app, so a number written straight
 * into products.stock survives only until the next sale, pull-out or delivery
 * touches that product, and then silently reverts. Admin's own Edit Product
 * form disables the Stock field for exactly these products.
 *
 * So this script goes through inv_receive_delivery() - the same function
 * admin/receive_delivery.php calls - which per product either:
 *   requires_serial = 1  inserts one product_units row per unit (serial
 *                        generated here), then recounts the mirror
 *   otherwise            adds to products.stock directly
 * and logs a stock_in row in stock_movements either way. The result is a shelf
 * that is consistent with the inventory module rather than beside it.
 *
 * Usage (from the project root):
 *   php execution/stock_up.php                         # dry run, target 50, PC parts
 *   php execution/stock_up.php --apply                 # actually write it
 *   php execution/stock_up.php --target=100 --apply
 *   php execution/stock_up.php --all --apply           # every category, not just PC parts
 *   php execution/stock_up.php --category="Graphics Cards" --apply
 *   php execution/stock_up.php --id=412 --target=25 --apply
 *
 * Nothing is written without --apply. The script only ever ADDS stock: a
 * product already at or above the target is skipped, never reduced.
 *
 * Serials for tracked products are generated as DEV-<productId>-<n> and are
 * checked against product_units before use, so re-running is safe - it picks
 * up after the highest number already on file rather than colliding.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/product_units.php';
require_once __DIR__ . '/../config/inventory_stock.php';

$opts = getopt('', ['target::', 'category::', 'id::', 'all', 'apply',
                    'user::', 'dr::', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php execution/stock_up.php [--target=N] [--category=NAME] [--id=N]\n"
       . "       [--all] [--user=N] [--dr=TEXT] [--apply]\n\n"
       . "  --target=N      stock level to bring each product up to (default 50)\n"
       . "  --category=NAME one category by name (repeatable via comma: \"Memory,Storage\")\n"
       . "  --id=N          one product id (comma-separated for several)\n"
       . "  --all           every active category, not just the PC-build ones\n"
       . "  --user=N        staff id recorded on the movements (default: lowest admin)\n"
       . "  --dr=TEXT       delivery receipt number (default: DEV-<date>-<time>)\n"
       . "  --apply         write it; without this the script only reports\n";
    exit(0);
}

$target = isset($opts['target']) ? (int)$opts['target'] : 50;
$apply  = isset($opts['apply']);

if ($target < 1 || $target > 100000) {
    fwrite(STDERR, "--target must be between 1 and 100000.\n");
    exit(1);
}

/* The categories the configurator actually draws from. A build needs one of
   each of these, so topping up anything else does not change what it can
   offer - which is why this, not --all, is the default. */
const SU_PC_CATEGORIES = [
    'Processors', 'Motherboards', 'Memory', 'Graphics Cards',
    'Storage', 'Power Supply', 'PC Case', 'CPU Cooler',
];

$pdo = getConnection();

/* ---- who the movements are recorded against ---------------------------- */
$userId = isset($opts['user']) ? (int)$opts['user'] : 0;
if ($userId <= 0) {
    $userId = (int)$pdo->query(
        "SELECT id FROM users WHERE role IN ('admin','super_admin') ORDER BY id LIMIT 1"
    )->fetchColumn();
}
if ($userId <= 0) {
    fwrite(STDERR, "No admin user to record the delivery against. Pass --user=N.\n");
    exit(1);
}

/* ---- which products ----------------------------------------------------- */
$where  = ["p.status = 'active'", 'p.stock < ?'];
$params = [$target];

if (isset($opts['id']) && $opts['id'] !== false) {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)$opts['id']))));
    if (!$ids) { fwrite(STDERR, "--id needs at least one product id.\n"); exit(1); }
    $where[] = 'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params  = array_merge($params, $ids);
} else {
    $cats = null;
    if (isset($opts['category']) && $opts['category'] !== false) {
        $cats = array_values(array_filter(array_map('trim', explode(',', (string)$opts['category']))));
    } elseif (!isset($opts['all'])) {
        $cats = SU_PC_CATEGORIES;
    }
    if ($cats) {
        $where[] = 'c.name IN (' . implode(',', array_fill(0, count($cats), '?')) . ')';
        $params  = array_merge($params, $cats);
    }
}

$sql = 'SELECT p.id, p.name, p.stock, p.requires_serial, c.name AS category
          FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY c.name, p.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nothing to do - every matching product is already at {$target} or above.\n";
    exit(0);
}

/* ---- build the delivery lines ------------------------------------------- */
/* Serial suffixes continue from what is already on file for the product, so a
   second run does not generate a serial it inserted on the first. */
$nextSuffix = $pdo->prepare(
    "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(serial_number, '-', -1) AS UNSIGNED)), 0)
       FROM product_units
      WHERE product_id = ? AND serial_number LIKE CONCAT('DEV-', ?, '-%')"
);

$lines = [];
$plan  = [];
$totalUnits = 0;

foreach ($rows as $r) {
    $id   = (int)$r['id'];
    $need = $target - (int)$r['stock'];
    if ($need <= 0) { continue; }

    /* requires_serial alone is not enough to decide: a product flagged for
       serials that nobody has entered any for is still a plain number (see
       pu_tracking_live). But inv_receive_delivery() branches on the FLAG, not
       on whether tracking is live - so the flag is what this has to follow,
       otherwise a quantity line gets rejected as "needs a serial number for
       every unit received". */
    $serialised = !empty($r['requires_serial']);

    if ($serialised) {
        $nextSuffix->execute([$id, $id]);
        $start = (int)$nextSuffix->fetchColumn() + 1;
        $serials = [];
        for ($n = 0; $n < $need; $n++) {
            $serials[] = ['serial' => sprintf('DEV-%d-%d', $id, $start + $n), 'imei' => ''];
        }
        $lines[] = ['product_id' => $id, 'serials' => $serials];
    } else {
        $lines[] = ['product_id' => $id, 'quantity' => $need];
    }

    $plan[] = [$r['category'] ?? '-', $r['name'], (int)$r['stock'], $need, $serialised];
    $totalUnits += $need;
}

/* ---- report ------------------------------------------------------------- */
printf("%-18s %-44s %6s %6s %s\n", 'CATEGORY', 'PRODUCT', 'NOW', '+ADD', 'MODE');
foreach ($plan as $p) {
    printf("%-18s %-44s %6d %6d %s\n",
        mb_strimwidth((string)$p[0], 0, 18, ''),
        mb_strimwidth((string)$p[1], 0, 44, '…'),
        $p[2], $p[3], $p[4] ? 'serials' : 'quantity');
}
printf("\n%d product(s), %d unit(s) to add, bringing each to %d.\n",
    count($plan), $totalUnits, $target);

if (!$apply) {
    echo "\nDry run - nothing was written. Re-run with --apply to record it.\n";
    exit(0);
}

/* ---- write -------------------------------------------------------------- */
$dr = isset($opts['dr']) && $opts['dr'] !== false
    ? (string)$opts['dr']
    : 'DEV-' . date('Ymd-His');

$res = inv_receive_delivery($pdo, [
    'supplier_id'   => null,
    'dr_number'     => $dr,
    'delivery_date' => date('Y-m-d'),
    'notes'         => 'Bulk stock top-up to ' . $target . ' via execution/stock_up.php',
], $lines, $userId);

if (!$res['ok']) {
    /* inv_receive_delivery is all-or-nothing by design, so there is nothing
       half-written to clean up - just report every complaint it made. */
    fwrite(STDERR, "\nNothing was written. The delivery was rejected:\n");
    foreach ($res['errors'] as $e) { fwrite(STDERR, '  - ' . $e . "\n"); }
    exit(1);
}

echo "\n" . $res['message'] . "\n";
printf("DR %s (delivery #%d) - %d serialised unit(s), %d quantity unit(s).\n",
    $dr, (int)$res['delivery_id'], (int)$res['units_added'], (int)$res['qty_added']);
echo "Recorded in stock_deliveries / stock_movements, visible on admin/stock_movements.php.\n";
