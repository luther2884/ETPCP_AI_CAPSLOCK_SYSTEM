<?php
declare(strict_types=1);

/**
 * reorder_report.php - prints the current reorder plan.
 *
 * Layer 3 (execution). Read-only: it computes and prints, it never writes
 * stock or orders. Same engine as the admin page, so the numbers always agree.
 *
 * Usage (from the project root):
 *   php execution/reorder_report.php
 *   php execution/reorder_report.php --window=30 --horizon=14
 *   php execution/reorder_report.php --lead=0 --safety=0   # plain forecast-minus-stock
 *   php execution/reorder_report.php --all                 # include sufficient stock
 *   php execution/reorder_report.php --csv > plan.csv
 *
 * Overrides apply to this run only; they are never saved back to
 * inventory_settings. See directives/demand_forecasting.md.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/demand_forecast.php';

$opts = getopt('', ['window::', 'horizon::', 'lead::', 'safety::', 'max::',
                    'all', 'csv', 'search::', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php execution/reorder_report.php [--window=7|14|30] [--horizon=N]\n"
       . "       [--lead=N] [--safety=N] [--max=N] [--search=TEXT] [--all] [--csv]\n";
    exit(0);
}

$asCsv     = isset($opts['csv']);
$includeOk = isset($opts['all']);
$search    = isset($opts['search']) ? (string)$opts['search'] : '';

try {
    $pdo = getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: cannot connect to the database: " . $e->getMessage() . "\n");
    exit(1);
}

// Run-only overrides: patch the settings the engine reads, without persisting.
$settings = df_get_settings($pdo);
$map = ['window' => 'forecast_window_days', 'horizon' => 'forecast_horizon_days',
        'lead' => 'default_lead_time_days', 'safety' => 'safety_stock_days',
        'max' => 'max_stock_days'];
$overridden = [];
foreach ($map as $flag => $key) {
    if (isset($opts[$flag]) && $opts[$flag] !== false) {
        $settings[$key] = (int)$opts[$flag];
        $overridden[] = "$key=" . (int)$opts[$flag];
    }
}

$window = in_array((int)$settings['forecast_window_days'], DF_ALLOWED_WINDOWS, true)
    ? (int)$settings['forecast_window_days'] : 7;
$settings['forecast_window_days'] = $window;

$ctx = [
    'settings'     => $settings,
    'data_days'    => df_data_days($pdo),
    'committed'    => df_committed($pdo),
    'sold_current' => df_units_sold($pdo, $window, 0),
    'sold_prior'   => df_units_sold($pdo, $window, $window),
];

$sql    = "SELECT p.id, p.name, p.stock, p.price, p.created_at, p.category_id,
                  COALESCE(p.reorder_level, 5) AS reorder_level,
                  p.lead_time_days, p.max_stock_level, c.name AS category_name
           FROM products p LEFT JOIN categories c ON c.id = p.category_id
           WHERE p.status = 'active'";
$params = [];
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $rows[] = df_forecast_product($p, $ctx);
}

$rank = ['oversold' => 0, 'urgent' => 1, 'reorder' => 2, 'sufficient' => 3];
usort($rows, fn($a, $b) => [$rank[$a['status']], -$a['suggested']]
                       <=> [$rank[$b['status']], -$b['suggested']]);

$plan = $includeOk ? $rows : array_values(array_filter($rows, fn($r) => $r['suggested'] > 0));

if ($asCsv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product', 'Category', "Sold ({$window}d)", 'Units/Day', 'On Hand',
                   'Available', 'Forecast', 'Required', 'Max Stock', 'Suggested',
                   'Unit Price', 'Order Value', 'Status']);
    foreach ($plan as $r) {
        fputcsv($out, [$r['name'], $r['category_name'] ?? '', $r['sold_window'],
                       number_format($r['velocity'], 3, '.', ''), $r['stock'], $r['available'],
                       number_format($r['forecast'], 2, '.', ''), number_format($r['required'], 2, '.', ''),
                       $r['max_stock'], $r['suggested'], number_format($r['price'], 2, '.', ''),
                       number_format($r['order_value'], 2, '.', ''), df_status_label($r['status'])]);
    }
    fclose($out);
    exit(0);
}

echo "== Reorder plan ==\n";
printf("Window %dd · horizon %dd · lead %dd · safety %dd · max %dd · min qty %d · trend %s\n",
    $window, $settings['forecast_horizon_days'], $settings['default_lead_time_days'],
    $settings['safety_stock_days'], $settings['max_stock_days'],
    $settings['min_order_qty'], $settings['trend_weighting'] ? 'on' : 'off');
if ($overridden) {
    echo "Overridden for this run only: " . implode(', ', $overridden) . "\n";
}
printf("Order history available: %d day%s%s\n\n", $ctx['data_days'],
    $ctx['data_days'] === 1 ? '' : 's',
    $ctx['data_days'] < $window
        ? " (shorter than the window - rates divide by {$ctx['data_days']}, not $window)"
        : '');

if (!$plan) {
    echo "Nothing to reorder. Every product covers its forecast demand, lead time and buffer.\n";
    exit(0);
}

printf("%-46s %6s %7s %7s %8s %7s  %s\n",
    'PRODUCT', 'SOLD', 'ON HAND', 'AVAIL', 'FORECAST', 'ORDER', 'STATUS');
echo str_repeat('-', 108) . "\n";
foreach ($plan as $r) {
    printf("%-46s %6d %7d %7d %8.1f %7d  %s\n",
        mb_strimwidth($r['name'], 0, 46, '...'), $r['sold_window'], $r['stock'],
        $r['available'], $r['forecast'], $r['suggested'], df_status_label($r['status']));
}

$units = array_sum(array_column($plan, 'suggested'));
$value = array_sum(array_column($plan, 'order_value'));
echo str_repeat('-', 108) . "\n";
printf("%d product%s · %d units · estimated PHP %s\n",
    count($plan), count($plan) === 1 ? '' : 's', $units, number_format($value, 2));
