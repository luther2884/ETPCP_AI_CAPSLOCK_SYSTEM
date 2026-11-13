<?php
declare(strict_types=1);

/**
 * backfill_product_tags.php - tags every active product in the catalog.
 *
 * Layer 3 (execution). Deterministic: same catalog in, same tags out. Safe to
 * re-run - it replaces only the rows it derived itself (confidence < 100), so
 * admin corrections survive.
 *
 * Usage (from the project root):
 *   php execution/backfill_product_tags.php            # write tags
 *   php execution/backfill_product_tags.php --dry-run  # report only, no writes
 *   php execution/backfill_product_tags.php --category=2
 *
 * See directives/product_tagging.md for when to run this.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/product_tagger.php';

$opts     = getopt('', ['dry-run', 'category::', 'verbose']);
$dryRun   = array_key_exists('dry-run', $opts);
$verbose  = array_key_exists('verbose', $opts);
$onlyCat  = isset($opts['category']) ? (int)$opts['category'] : null;

echo "== Product tag backfill ==\n";
echo $dryRun ? "Mode: DRY RUN (no writes)\n" : "Mode: WRITE\n";

try {
    $pdo = getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: cannot connect to the database: " . $e->getMessage() . "\n");
    exit(1);
}

// Price lists per category, so price_tier is a rank within the product's own
// shelf rather than against the whole catalog.
$priceMap = pt_category_price_map($pdo);

$sql = "SELECT p.id, p.name, p.description, p.price, p.category_id, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active'";
$params = [];
if ($onlyCat !== null) {
    $sql .= " AND p.category_id = ?";
    $params[] = $onlyCat;
}
$sql .= " ORDER BY p.category_id, p.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$products) {
    echo "No active products matched. Nothing to do.\n";
    exit(0);
}

$totalTags   = 0;
$untagged    = [];
$perCategory = [];
$keyCounts   = [];

if (!$dryRun) { $pdo->beginTransaction(); }

try {
    foreach ($products as $product) {
        $catId  = (int)($product['category_id'] ?? 0);
        $prices = $priceMap[$catId] ?? [];
        $tags   = pt_extract_tags($product, $prices);

        if (!$tags) {
            $untagged[] = $product['name'];
        }

        foreach ($tags as $t) {
            $keyCounts[$t['key']] = ($keyCounts[$t['key']] ?? 0) + 1;
        }

        if (!$dryRun) {
            pt_store_tags($pdo, (int)$product['id'], $tags);
        }

        $totalTags += count($tags);
        $label = $product['category_name'] ?? "category #$catId";
        $perCategory[$label]['products'] = ($perCategory[$label]['products'] ?? 0) + 1;
        $perCategory[$label]['tags']     = ($perCategory[$label]['tags'] ?? 0) + count($tags);

        if ($verbose) {
            $rendered = array_map(fn($t) => $t['key'] . '=' . $t['value'], $tags);
            printf("  #%-4d %-52s %s\n", $product['id'], substr($product['name'], 0, 52), implode(' ', $rendered));
        }
    }

    if (!$dryRun) { $pdo->commit(); }
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, "FATAL: backfill aborted, nothing was written: " . $e->getMessage() . "\n");
    exit(1);
}

$productCount = count($products);
printf("\nTagged %d products with %d tags (avg %.1f per product).\n",
    $productCount, $totalTags, $productCount > 0 ? $totalTags / $productCount : 0);

echo "\nPer category:\n";
foreach ($perCategory as $label => $s) {
    printf("  %-22s %3d products  %4d tags  (avg %.1f)\n",
        $label, $s['products'], $s['tags'], $s['products'] > 0 ? $s['tags'] / $s['products'] : 0);
}

echo "\nTag keys produced:\n";
arsort($keyCounts);
foreach ($keyCounts as $key => $n) {
    printf("  %-16s %4d\n", $key, $n);
}

// Products with no tags at all can never be recommended by similarity - they
// fall back to price proximity only, so they are worth naming explicitly.
if ($untagged) {
    printf("\n%d product(s) produced NO tags - review their names/descriptions:\n", count($untagged));
    foreach (array_slice($untagged, 0, 20) as $name) {
        echo "  - $name\n";
    }
    if (count($untagged) > 20) {
        printf("  ... and %d more\n", count($untagged) - 20);
    }
}

echo "\nDone.\n";
