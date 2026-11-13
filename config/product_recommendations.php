<?php
declare(strict_types=1);

/**
 * product_recommendations.php - "Similar Products" for a product detail page.
 *
 * Content-based, deliberately. This catalog has 4 customers who have ever
 * ordered and 43 of its 46 orders are single-item, so there is no co-purchase
 * graph to learn from - collaborative filtering would have nothing to chew on.
 * Everything here scores products against each other on their own attributes,
 * which works from the first day and stays explainable ("same socket, same
 * brand, similar price").
 *
 * Relevance is enforced as a HARD filter, not a soft score: a motherboard only
 * ever recommends motherboards. Category is a constraint, never a tiebreaker -
 * no amount of brand or price similarity can pull a headset into a
 * motherboard's carousel.
 *
 * Depends on product_tags (DBSQL/add_product_tags.sql), populated by
 * execution/backfill_product_tags.php.
 */

require_once __DIR__ . '/product_tagger.php';

/**
 * Similar products for $product, most similar first.
 *
 * Scoring, in order of influence:
 *   1. Same category          - hard filter, non-negotiable
 *   2. Shared tags            - weighted by tag key (socket 10 ... lighting 2)
 *   3. Price proximity        - closer price = more like-for-like
 *   4. In stock               - never lead with something unbuyable
 *
 * Falls back to price-nearest neighbours in the same category if the product
 * has no tags yet (a brand-new product added before the next backfill), so the
 * section never renders empty where the old RAND() query would have shown
 * something.
 *
 * @param array $product Needs at least: id, category_id, price.
 * @return array Product rows, each with a `similarity_score` and
 *               `shared_tags` (comma-separated 'key=value') for display/debug.
 */
function getSimilarProducts(PDO $pdo, array $product, int $limit = 4): array {
    $productId  = (int)($product['id'] ?? 0);
    $categoryId = (int)($product['category_id'] ?? 0);
    $price      = (float)($product['price'] ?? 0);

    if ($productId <= 0 || $categoryId <= 0) { return []; }
    $limit = max(1, min(24, $limit));

    try {
        $tagStmt = $pdo->prepare('SELECT tag_key, tag_value FROM product_tags WHERE product_id = ?');
        $tagStmt->execute([$productId]);
        $ownTags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$ownTags) {
            return pr_price_neighbours($pdo, $categoryId, $productId, $price, $limit);
        }

        // One placeholder per tag, matched as 'key=value' so a shared KEY with a
        // different VALUE scores nothing - an AM4 board must not get credit for
        // matching an LGA1700 board just because both state a socket.
        $matchTokens = [];
        $caseParts   = [];
        foreach ($ownTags as $i => $t) {
            $matchTokens[":t$i"] = $t['tag_key'] . '=' . $t['tag_value'];
        }
        // Weight each matched row by its own key.
        foreach (array_unique(array_column($ownTags, 'tag_key')) as $key) {
            $caseParts[] = "WHEN pt.tag_key = " . $pdo->quote($key) . " THEN " . pt_weight_for($key);
        }
        $weightCase = 'CASE ' . implode(' ', $caseParts) . ' ELSE 0 END';
        $inList     = implode(',', array_keys($matchTokens));

        $sql = "
            SELECT p.*, c.name AS category_name,
                   COALESCE(SUM($weightCase), 0) AS similarity_score,
                   GROUP_CONCAT(CONCAT(pt.tag_key, '=', pt.tag_value)
                                ORDER BY pt.tag_key SEPARATOR ',') AS shared_tags
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_tags pt
                   ON pt.product_id = p.id
                  AND CONCAT(pt.tag_key, '=', pt.tag_value) IN ($inList)
            WHERE p.category_id = :cat
              AND p.id <> :self
              AND p.status = 'active'
            GROUP BY p.id
            ORDER BY (p.stock > 0) DESC,
                     similarity_score DESC,
                     ABS(p.price - :price) ASC,
                     p.id ASC
            LIMIT $limit";

        $stmt = $pdo->prepare($sql);
        foreach ($matchTokens as $ph => $val) {
            $stmt->bindValue($ph, $val, PDO::PARAM_STR);
        }
        $stmt->bindValue(':cat', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':self', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':price', $price);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('getSimilarProducts failed for product ' . $productId . ': ' . $e->getMessage());
        // Never let a recommendation failure take the product page down.
        return pr_price_neighbours($pdo, $categoryId, $productId, $price, $limit);
    }
}

/**
 * Fallback: nearest products by price within the same category. Used when the
 * product has no tags yet, or when the scored query errors out.
 */
function pr_price_neighbours(PDO $pdo, int $categoryId, int $productId, float $price, int $limit): array {
    try {
        $limit = max(1, min(24, $limit));
        $stmt = $pdo->prepare("
            SELECT p.*, c.name AS category_name, 0 AS similarity_score, NULL AS shared_tags
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.category_id = :cat AND p.id <> :self AND p.status = 'active'
            ORDER BY (p.stock > 0) DESC, ABS(p.price - :price) ASC, p.id ASC
            LIMIT $limit");
        $stmt->bindValue(':cat', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':self', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':price', $price);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('pr_price_neighbours failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Display label for a tag key ('ram_type' -> 'Memory'). Falls back to the key
 * with underscores spaced out, so a newly added key still renders sensibly
 * instead of disappearing.
 */
function tagKeyLabel(string $key): string {
    $labels = [
        'brand' => 'Brand', 'price_tier' => 'Price', 'socket' => 'Socket',
        'ram_type' => 'Memory', 'ram_speed' => 'Speed', 'module' => 'Module',
        'form_factor' => 'Form factor', 'drive_type' => 'Drive',
        'capacity_band' => 'Capacity', 'interface' => 'Interface',
        'gpu_family' => 'GPU', 'cpu_family' => 'CPU', 'vram_type' => 'VRAM',
        'has_igpu' => 'Integrated graphics', 'psu_watt' => 'Wattage',
        'psu_rating' => 'Efficiency', 'modular' => 'Modular',
        'switch_type' => 'Switches', 'kit' => 'Kit',
        'audio_form' => 'Fit', 'audio_ch' => 'Audio', 'has_mic' => 'Microphone',
        'print_tech' => 'Print type', 'print_fn' => 'Functions',
        'duplex' => 'Duplex', 'ink_color' => 'Colour', 'ink_form' => 'Type',
        'net_device' => 'Device', 'wifi_class' => 'Wi-Fi', 'band' => 'Band',
        'cctv_device' => 'Device', 'cctv_form' => 'Camera type',
        'placement' => 'Placement', 'cooler_type' => 'Cooling',
        'screen_size' => 'Screen', 'laptop_ram' => 'RAM',
        'laptop_storage' => 'Storage', 'os' => 'OS', 'display' => 'Display',
        'connectivity' => 'Connection', 'lighting' => 'Lighting',
        'audience' => 'Best for', 'tower' => 'Tower', 'portable' => 'Portable',
    ];
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * Display form of a tag value. Most are already presentable ('AM4', 'DDR4');
 * the yes/no and lowercase ones are not.
 *
 * Keyed by tag_key first, because the same value means different things under
 * different keys - 'mid' is "Mid-range" under price_tier but "Mid tower" under
 * tower, and a single flat map would silently render one as the other.
 */
function tagValueLabel(string $value, string $key = ''): string {
    $perKey = [
        'price_tier' => ['budget' => 'Budget', 'mid' => 'Mid-range', 'premium' => 'Premium'],
        'tower'      => ['mid' => 'Mid tower', 'full' => 'Full tower'],
    ];
    if ($key !== '' && isset($perKey[$key][$value])) {
        return $perKey[$key][$value];
    }

    $labels = [
        'yes' => 'Yes', 'no' => 'No',
        'rgb' => 'RGB', 'gaming' => 'Gaming', 'wireless' => 'Wireless',
        'wired' => 'Wired', 'mechanical' => 'Mechanical', 'membrane' => 'Membrane',
        'laser' => 'Laser', 'inkjet' => 'Inkjet', 'dot-matrix' => 'Dot matrix',
        'all-in-one' => 'All-in-one', 'bottle' => 'Bottle',
        'cartridge' => 'Cartridge', 'toner' => 'Toner', 'black' => 'Black',
        'cyan' => 'Cyan', 'magenta' => 'Magenta', 'yellow' => 'Yellow',
        'tri-color' => 'Tri-colour', 'sodimm' => 'SO-DIMM', 'dimm' => 'DIMM',
        'air' => 'Air', 'liquid' => 'Liquid', 'indoor' => 'Indoor',
        'outdoor' => 'Outdoor', 'stereo' => 'Stereo', 'surround' => 'Surround',
        'over-ear' => 'Over-ear', 'in-ear' => 'In-ear', 'combo' => 'Combo',
        'router' => 'Router', 'switch' => 'Switch', 'extender' => 'Extender',
        'mesh' => 'Mesh', 'adapter' => 'Adapter', 'cabling' => 'Cabling',
        'camera' => 'Camera', 'recorder' => 'DVR / NVR', 'kit' => 'Kit',
        'bullet' => 'Bullet', 'dome' => 'Dome', 'flash' => 'Flash drive',
    ];
    return $labels[$value] ?? $value;
}

/**
 * Human-readable reason a product was recommended, e.g. "Same socket (AM4) -
 * ASUS - similar price". Shown under each card so the section explains itself
 * instead of looking arbitrary.
 *
 * Only the two highest-weighted shared tags are named; more than that reads as
 * noise on a product card.
 */
function describeSimilarity(array $candidate): string {
    $shared = (string)($candidate['shared_tags'] ?? '');
    if ($shared === '') { return 'Same category'; }

    $labels = [
        'socket' => 'Same socket', 'ram_type' => 'Same memory type',
        'form_factor' => 'Same form factor', 'drive_type' => 'Same drive type',
        'capacity_band' => 'Same capacity', 'print_tech' => 'Same print type',
        'ink_form' => 'Same type', 'ink_color' => 'Same colour',
        'net_device' => 'Same device type', 'cctv_device' => 'Same device type',
        'switch_type' => 'Same switch type', 'gpu_family' => 'Same GPU series',
        'cpu_family' => 'Same CPU series', 'psu_watt' => 'Similar wattage',
        'screen_size' => 'Same screen size', 'cooler_type' => 'Same cooler type',
        'price_tier' => 'Similar price', 'audience' => 'Also for gaming',
        'connectivity' => 'Same connection',
    ];

    $pairs = [];
    foreach (explode(',', $shared) as $token) {
        $parts = explode('=', $token, 2);
        if (count($parts) !== 2) { continue; }
        $pairs[] = ['key' => $parts[0], 'value' => $parts[1], 'w' => pt_weight_for($parts[0])];
    }
    if (!$pairs) { return 'Same category'; }

    usort($pairs, fn($a, $b) => $b['w'] <=> $a['w']);

    $out = [];
    foreach (array_slice($pairs, 0, 2) as $p) {
        if ($p['key'] === 'brand') {
            $out[] = $p['value'];
        } elseif (isset($labels[$p['key']])) {
            $out[] = $labels[$p['key']] . ' (' . $p['value'] . ')';
        } else {
            $out[] = ucfirst(str_replace('_', ' ', $p['key'])) . ': ' . $p['value'];
        }
    }
    return implode(' · ', $out);
}
