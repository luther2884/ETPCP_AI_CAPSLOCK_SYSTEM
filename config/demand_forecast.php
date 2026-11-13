<?php
declare(strict_types=1);

/**
 * demand_forecast.php - product demand forecasting and smart reorder
 * suggestions.
 *
 * The point of this file is to answer "order how many, and when?" rather than
 * "stock is low". A low-stock flag says a number crossed a line; a reorder
 * suggestion says how many units to buy, derived from how fast the product
 * actually sells.
 *
 * The calculation, per product:
 *
 *   velocity   = units sold in the window / EFFECTIVE days in the window
 *   forecast   = velocity x horizon days x trend factor
 *   required   = forecast + (velocity x lead time) + (velocity x safety days)
 *   suggestion = required - available          (clamped, then capped)
 *
 * `available` is `products.stock`, full stop - the same definition
 * inventory_control.php uses, and for the reason written out in that file's
 * header: this shop deducts stock when the ORDER IS PLACED
 * (customer/process_order.php), so `stock` is already net of every open order.
 *
 * This used to be `stock - committed`, which took those units out twice. It
 * over-ordered every product with open orders against it, and any SKU whose
 * open orders outnumbered its remaining stock came out negative and was
 * labelled "Oversold" while sitting on healthy stock.
 *
 * Two guards keep suggestions honest:
 *   - EFFECTIVE days, not nominal. A 30-day window over a store with 16 days
 *     of order history divides by 16, not 30. Dividing by the nominal window
 *     halves every velocity and silently under-orders everything.
 *   - A max-stock ceiling. A suggestion is never allowed to push stock past
 *     `max_stock_days` of demand, so a single busy week cannot trigger a
 *     year of inventory.
 *
 * Nothing is persisted. Forecasts are computed from order_items on every
 * read, so they cannot drift from the orders they describe.
 */

/** Defaults, used when inventory_settings has no row for a key. */
const DF_DEFAULTS = [
    'forecast_window_days'   => 7,
    'forecast_horizon_days'  => 7,
    'safety_stock_days'      => 3,
    'default_lead_time_days' => 7,
    'max_stock_days'         => 60,
    'min_order_qty'          => 1,
    'trend_weighting'        => 1,
];

/** Sales-history windows an admin may choose between. */
const DF_ALLOWED_WINDOWS = [7, 14, 30];

/** Order statuses whose units are committed but not yet shipped out. */
const DF_OPEN_STATUSES = ['preparing', 'packed', 'ready', 'shipped'];

/**
 * Reads the forecast settings, falling back to DF_DEFAULTS for anything
 * missing so a partially-seeded table can never produce a division by zero.
 */
function df_get_settings(PDO $pdo): array {
    $settings = DF_DEFAULTS;
    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM inventory_settings')
                    ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (array_key_exists($r['setting_key'], $settings)) {
                $settings[$r['setting_key']] = (int)$r['setting_value'];
            }
        }
    } catch (Throwable $e) {
        error_log('df_get_settings failed, using defaults: ' . $e->getMessage());
    }

    // Clamp everything into a sane range. A window of 0 divides by zero; a
    // horizon of 3650 suggests a decade of stock.
    if (!in_array($settings['forecast_window_days'], DF_ALLOWED_WINDOWS, true)) {
        $settings['forecast_window_days'] = 7;
    }
    $settings['forecast_horizon_days']  = max(1, min(90,  $settings['forecast_horizon_days']));
    $settings['safety_stock_days']      = max(0, min(60,  $settings['safety_stock_days']));
    $settings['default_lead_time_days'] = max(0, min(120, $settings['default_lead_time_days']));
    $settings['max_stock_days']         = max(7, min(365, $settings['max_stock_days']));
    $settings['min_order_qty']          = max(1, min(500, $settings['min_order_qty']));
    $settings['trend_weighting']        = $settings['trend_weighting'] ? 1 : 0;

    return $settings;
}

function df_save_settings(PDO $pdo, array $values): void {
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($values as $key => $value) {
        if (!array_key_exists($key, DF_DEFAULTS)) { continue; }
        $stmt->execute([$key, (string)(int)$value]);
    }
}

/**
 * How many days of order history the store actually has.
 *
 * This is the denominator guard: asking for a 30-day rate from a store that
 * opened 16 days ago must divide by 16. Returns at least 1.
 */
function df_data_days(PDO $pdo): int {
    try {
        $first = $pdo->query(
            "SELECT MIN(created_at) FROM orders WHERE status <> 'cancelled'"
        )->fetchColumn();
        if (!$first) { return 1; }
        $days = (int)floor((time() - strtotime((string)$first)) / 86400) + 1;
        return max(1, $days);
    } catch (Throwable $e) {
        error_log('df_data_days failed: ' . $e->getMessage());
        return 1;
    }
}

/**
 * Units sold per product inside a window ending now, keyed by product id.
 * Cancelled orders are excluded - a cancelled order is not demand the
 * warehouse has to cover, and counting it inflates every suggestion.
 */
function df_units_sold(PDO $pdo, int $windowDays, int $offsetDays = 0): array {
    $sql = "SELECT oi.product_id, SUM(oi.quantity) AS qty
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.status <> 'cancelled'
              AND o.created_at <  DATE_SUB(NOW(), INTERVAL :off DAY)
              AND o.created_at >= DATE_SUB(NOW(), INTERVAL :total DAY)
            GROUP BY oi.product_id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':off',   $offsetDays,               PDO::PARAM_INT);
    $stmt->bindValue(':total', $offsetDays + $windowDays, PDO::PARAM_INT);
    $stmt->execute();

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['product_id']] = (int)$r['qty'];
    }
    return $map;
}

/** Units committed to open orders, per product. */
function df_committed(PDO $pdo): array {
    $in = "'" . implode("','", DF_OPEN_STATUSES) . "'";
    $rows = $pdo->query(
        "SELECT oi.product_id, SUM(oi.quantity) AS qty
         FROM order_items oi JOIN orders o ON o.id = oi.order_id
         WHERE o.status IN ($in)
         GROUP BY oi.product_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['product_id']] = (int)$r['qty'];
    }
    return $map;
}

/**
 * Trend factor from the current window against the one immediately before it.
 *
 * Returns 1.0 when flat, >1 when accelerating, <1 when slowing. Deliberately
 * clamped to 0.5-1.5: with this much sales data a single large order can
 * double a window, and an unclamped ratio would turn that into a 5x forecast.
 * Returns 1.0 when the prior window is empty - going from 0 to 3 units is not
 * an infinite trend, it is one sale.
 */
function df_trend_factor(int $current, int $previous): float {
    if ($previous <= 0 || $current <= 0) { return 1.0; }
    return max(0.5, min(1.5, $current / $previous));
}

/**
 * Builds the forecast and reorder suggestion for one product.
 *
 * $product needs: id, name, stock, price, reorder_level, and optionally
 * lead_time_days / max_stock_level (NULL falls back to the global settings).
 */
function df_forecast_product(array $product, array $ctx): array {
    $settings   = $ctx['settings'];
    $window     = (int)$settings['forecast_window_days'];
    $horizon    = (int)$settings['forecast_horizon_days'];
    $safetyDays = (int)$settings['safety_stock_days'];

    $pid       = (int)$product['id'];
    $stock     = (int)$product['stock'];
    $committed = $ctx['committed'][$pid] ?? 0;
    // Not $stock - $committed: see the header. $committed stays in the output
    // below as reporting - what is sold and still to go out.
    $available = $stock;

    $soldWindow = $ctx['sold_current'][$pid] ?? 0;
    $soldPrior  = $ctx['sold_prior'][$pid]   ?? 0;

    // Effective days: never divide by more history than the store has, and
    // never by more than the product has been listed.
    $effectiveDays = min($window, max(1, $ctx['data_days']));
    if (!empty($product['created_at'])) {
        $listedDays = (int)floor((time() - strtotime((string)$product['created_at'])) / 86400) + 1;
        $effectiveDays = min($effectiveDays, max(1, $listedDays));
    }

    $velocity = $soldWindow / $effectiveDays;          // units per day

    $trend = $settings['trend_weighting']
        ? df_trend_factor($soldWindow, $soldPrior)
        : 1.0;

    $forecast = $velocity * $horizon * $trend;

    $leadTime = $product['lead_time_days'] !== null
        ? (int)$product['lead_time_days']
        : (int)$settings['default_lead_time_days'];

    // What must be on hand to cover the horizon, the wait for delivery, and a
    // buffer on top.
    $leadDemand   = $velocity * $leadTime;
    $safetyStock  = $velocity * $safetyDays;
    $required     = $forecast + $leadDemand + $safetyStock;

    // Overstock ceiling: the larger of an explicit per-product cap and the
    // global "this many days of demand" rule. A product with no sales gets no
    // demand-derived ceiling, so fall back to its reorder level.
    $maxByDays = $velocity > 0
        ? (int)ceil($velocity * (int)$settings['max_stock_days'])
        : (int)$product['reorder_level'] * 2;
    $maxStock = $product['max_stock_level'] !== null
        ? (int)$product['max_stock_level']
        : $maxByDays;
    $maxStock = max($maxStock, (int)$product['reorder_level']);

    $suggested = (int)ceil($required - $available);

    $reason = '';
    if ($suggested <= 0) {
        $suggested = 0;
        $reason = $velocity > 0
            ? 'Stock covers forecast demand, lead time and buffer.'
            : 'No sales in the window - nothing to forecast.';
    } else {
        // Never order past the ceiling. headroom can go negative if a product
        // is already over its cap, in which case nothing is suggested.
        $headroom = $maxStock - $available;
        if ($headroom <= 0) {
            $suggested = 0;
            $reason = 'Already at or above the maximum stock level (' . $maxStock . ').';
        } elseif ($suggested > $headroom) {
            $suggested = $headroom;
            $reason = 'Capped at the maximum stock level (' . $maxStock . ' units) to avoid overstocking.';
        } else {
            $minQty = (int)$settings['min_order_qty'];
            if ($suggested < $minQty) {
                $suggested = min($minQty, $headroom);
                $reason = 'Rounded up to the minimum order quantity.';
            } else {
                $reason = 'Covers ' . $horizon . '-day demand, ' . $leadTime
                        . '-day lead time and a ' . $safetyDays . '-day buffer.';
            }
        }
    }

    // Days of cover on what is available right now.
    $daysCover = $velocity > 0 ? $available / $velocity : null;

    if ($available < 0) {
        $status = 'oversold';
    } elseif ($suggested > 0) {
        $status = ($daysCover !== null && $daysCover <= $leadTime) ? 'urgent' : 'reorder';
    } else {
        $status = 'sufficient';
    }

    return [
        'product_id'      => $pid,
        'name'            => $product['name'],
        'category_name'   => $product['category_name'] ?? null,
        'price'           => (float)$product['price'],
        'stock'           => $stock,
        'committed'       => $committed,
        'available'       => $available,
        'reorder_level'   => (int)$product['reorder_level'],
        'sold_window'     => $soldWindow,
        'sold_prior'      => $soldPrior,
        'window_days'     => $window,
        'effective_days'  => $effectiveDays,
        'velocity'        => $velocity,
        'trend'           => $trend,
        'horizon_days'    => $horizon,
        'forecast'        => $forecast,
        'lead_time_days'  => $leadTime,
        'safety_stock'    => $safetyStock,
        'required'        => $required,
        'max_stock'       => $maxStock,
        'suggested'       => $suggested,
        'days_cover'      => $daysCover,
        'status'          => $status,
        'reason'          => $reason,
        'order_value'     => $suggested * (float)$product['price'],
    ];
}

/**
 * Forecast for the whole active catalog, most urgent first.
 *
 * $filters: ['search' => string, 'only' => 'reorder'|'all', 'category_id' => int]
 */
function df_forecast_catalog(PDO $pdo, array $filters = []): array {
    $settings = df_get_settings($pdo);
    $window   = (int)$settings['forecast_window_days'];

    $ctx = [
        'settings'     => $settings,
        'data_days'    => df_data_days($pdo),
        'committed'    => df_committed($pdo),
        'sold_current' => df_units_sold($pdo, $window, 0),
        // The window immediately before this one, for the trend factor.
        'sold_prior'   => df_units_sold($pdo, $window, $window),
    ];

    $where  = ["p.status = 'active'"];
    $params = [];
    if (!empty($filters['search'])) {
        $where[]  = '(p.name LIKE ? OR c.name LIKE ?)';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }
    if (!empty($filters['category_id'])) {
        $where[]  = 'p.category_id = ?';
        $params[] = (int)$filters['category_id'];
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.stock, p.price, p.created_at, p.category_id,
                COALESCE(p.reorder_level, 5) AS reorder_level,
                p.lead_time_days, p.max_stock_level,
                c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $out[] = df_forecast_product($product, $ctx);
    }

    if (($filters['only'] ?? '') === 'reorder') {
        $out = array_values(array_filter($out, fn($r) => $r['suggested'] > 0));
    }

    // Most actionable first: oversold, then urgent, then plain reorders, then
    // whatever sells fastest among the products that need nothing.
    $rank = ['oversold' => 0, 'urgent' => 1, 'reorder' => 2, 'sufficient' => 3];
    usort($out, function ($a, $b) use ($rank) {
        $byStatus = $rank[$a['status']] <=> $rank[$b['status']];
        if ($byStatus !== 0) { return $byStatus; }
        if ($a['status'] === 'sufficient') { return $b['velocity'] <=> $a['velocity']; }
        return $b['suggested'] <=> $a['suggested'];
    });

    return ['rows' => $out, 'settings' => $settings, 'data_days' => $ctx['data_days']];
}

function df_status_label(string $status): string {
    return [
        'oversold'   => 'Oversold',
        'urgent'     => 'Reorder Urgently',
        'reorder'    => 'Reorder Recommended',
        'sufficient' => 'Stock Sufficient',
    ][$status] ?? $status;
}

function df_status_color(string $status): string {
    return [
        'oversold'   => 'var(--admin-danger)',
        'urgent'     => 'var(--admin-danger)',
        'reorder'    => '#b45309',
        'sufficient' => 'var(--admin-success)',
    ][$status] ?? 'var(--admin-muted)';
}

/** Days of cover, or an em dash when there is no demand to divide by. */
function df_cover_label(?float $days): string {
    if ($days === null) { return '—'; }
    if ($days < 0)      { return '0'; }
    if ($days >= 999)   { return '999+'; }
    return number_format($days, $days < 10 ? 1 : 0);
}
