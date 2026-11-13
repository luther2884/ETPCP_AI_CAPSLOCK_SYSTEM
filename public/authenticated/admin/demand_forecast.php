<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/demand_forecast.php';
require_role(['admin']);

$pageTitle = 'Demand Forecast & Reorder - TechWorld Admin';
$isAdminTheme = true;

/**
 * Demand Forecasting & Smart Reorder Suggestions.
 *
 * Answers "order how many, and when?" instead of "stock is low". Every number
 * shown is derived live from order_items by config/demand_forecast.php - see
 * directives/demand_forecasting.md for the model and its guards.
 */

$saveMsg = null;

// ---- Settings save (PRG, so a refresh never re-submits) --------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    if (!check_csrf(false)) {
        header('Location: demand_forecast.php?saved=csrf');
        exit;
    }
    try {
        $pdo = getConnection();
        df_save_settings($pdo, [
            'forecast_window_days'   => (int)($_POST['forecast_window_days'] ?? 7),
            'forecast_horizon_days'  => (int)($_POST['forecast_horizon_days'] ?? 7),
            'safety_stock_days'      => (int)($_POST['safety_stock_days'] ?? 3),
            'default_lead_time_days' => (int)($_POST['default_lead_time_days'] ?? 7),
            'max_stock_days'         => (int)($_POST['max_stock_days'] ?? 60),
            'min_order_qty'          => (int)($_POST['min_order_qty'] ?? 1),
            'trend_weighting'        => isset($_POST['trend_weighting']) ? 1 : 0,
        ]);
        logAudit('update', 'inventory_settings', 0, null, ['forecast' => 'settings updated']);
        header('Location: demand_forecast.php?saved=ok');
        exit;
    } catch (Throwable $e) {
        error_log('Forecast settings save failed: ' . $e->getMessage());
        header('Location: demand_forecast.php?saved=err');
        exit;
    }
}

$search   = trim($_GET['search'] ?? '');
$onlyMode = ($_GET['only'] ?? 'reorder') === 'all' ? 'all' : 'reorder';
$export   = ($_GET['export'] ?? '') === 'csv';

$loadError = null;
$rows = [];
$settings = DF_DEFAULTS;
$dataDays = 1;

try {
    $pdo = getConnection();
    // The full catalog is always computed; $onlyMode filters the display, so
    // the summary totals below stay true regardless of what is on screen.
    $result   = df_forecast_catalog($pdo, ['search' => $search]);
    $rows     = $result['rows'];
    $settings = $result['settings'];
    $dataDays = $result['data_days'];
} catch (Throwable $e) {
    error_log('Demand forecast load error: ' . $e->getMessage());
    $loadError = 'Forecast could not be built. The database reported: ' . $e->getMessage();
}

$toOrder    = array_values(array_filter($rows, fn($r) => $r['suggested'] > 0));
$sufficient = array_values(array_filter($rows, fn($r) => $r['suggested'] === 0));
$visible    = $onlyMode === 'all' ? $rows : $toOrder;

$totalUnits = array_sum(array_column($toOrder, 'suggested'));
$totalValue = array_sum(array_column($toOrder, 'order_value'));
$urgentCnt  = count(array_filter($rows, fn($r) => in_array($r['status'], ['urgent', 'oversold'], true)));

// ---- Reporting: purchase-order CSV ----------------------------------------
if ($export && $loadError === null) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reorder_plan_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Product', 'Category', 'Sold (' . $settings['forecast_window_days'] . 'd)',
                   'Units/Day', 'On Hand', 'Committed', 'Available',
                   'Forecast (' . $settings['forecast_horizon_days'] . 'd)',
                   'Lead Time (d)', 'Safety Stock', 'Required', 'Max Stock',
                   'Suggested Reorder', 'Unit Price', 'Order Value', 'Status', 'Reason']);
    foreach ($visible as $r) {
        fputcsv($out, [
            $r['name'], $r['category_name'] ?? 'Uncategorized', $r['sold_window'],
            number_format($r['velocity'], 3, '.', ''), $r['stock'], $r['committed'], $r['available'],
            number_format($r['forecast'], 2, '.', ''), $r['lead_time_days'],
            number_format($r['safety_stock'], 2, '.', ''), number_format($r['required'], 2, '.', ''),
            $r['max_stock'], $r['suggested'],
            number_format($r['price'], 2, '.', ''), number_format($r['order_value'], 2, '.', ''),
            df_status_label($r['status']), $r['reason'],
        ]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<main class="container">
  <style>
    

    .df-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;
             font-weight:700;color:#fff;white-space:nowrap;}
    .df-num{text-align:right;font-variant-numeric:tabular-nums;}

    /* One card per product needing action - the layout the spec asks for:
       sold / stock / forecast / suggested / status, read top to bottom. */
    .df-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px;}
    .df-card{background:var(--admin-surface);border:1px solid var(--admin-border);
             border-radius:var(--radius-sm);padding:16px;color:var(--admin-text);
             border-left:4px solid var(--admin-border);}
    .df-card h4{margin:0 0 10px;font-size:14.5px;line-height:1.35;}
    .df-row{display:flex;justify-content:space-between;gap:10px;padding:5px 0;font-size:13px;}
    .df-row span:first-child{color:var(--admin-muted);}
    .df-row span:last-child{font-weight:600;font-variant-numeric:tabular-nums;}
    .df-suggest{display:flex;justify-content:space-between;align-items:center;gap:10px;
                margin-top:10px;padding-top:10px;border-top:1px solid var(--admin-border);}
    .df-suggest .qty{font-size:24px;font-weight:800;line-height:1;}
    .df-why{margin-top:8px;font-size:11.5px;color:var(--admin-muted);line-height:1.45;}

    .df-settings{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:14px;align-items:end;}
    .df-settings label{display:block;font-size:12px;color:var(--admin-muted);margin-bottom:5px;}
    .df-settings input[type=number], .df-settings select{width:100%;}

    
  </style>

    <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="inventory_control.php">Inventory</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Demand Forecast</span>
      </nav>
    </div>
  </div>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:1400px;">
    <div class="dashboard-welcome" style="margin-bottom:20px;padding:20px;">
      <h1 style="font-size:1.6rem;">Demand Forecast &amp; Smart Reorder</h1>
      <p>Forecasts each product's demand from its real sales rate, then suggests how many units to reorder — capped so a busy week can't trigger a year of stock.</p>
    </div>

    <?php if ($loadError !== null): ?>
      <div style="background:rgba(194,104,111,0.14);border-left:4px solid var(--admin-danger);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;">
        <p style="margin:0;color:var(--admin-text);font-weight:600;"><?= htmlspecialchars($loadError) ?></p>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['saved'])): ?>
      <?php
        $savedMap = [
          'ok'   => ['rgba(45,140,90,.16)', 'var(--admin-success)', 'Forecast settings saved. All figures below have been recalculated.'],
          'csrf' => ['rgba(194,104,111,.14)', 'var(--admin-danger)', 'Security token invalid — please try saving again.'],
          'err'  => ['rgba(194,104,111,.14)', 'var(--admin-danger)', 'Settings could not be saved.'],
        ];
        $sv = $savedMap[$_GET['saved']] ?? null;
      ?>
      <?php if ($sv): ?>
        <div class="df-no-print" style="background:<?= $sv[0] ?>;border-left:4px solid <?= $sv[1] ?>;border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:20px;color:var(--admin-text);font-size:14px;">
          <?= htmlspecialchars($sv[2]) ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Summary ------------------------------------------------------------ -->
    <div class="quick-stats" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));margin-bottom:20px;">
      <div class="stat-card" style="padding:16px;text-align:left;">
        <div class="stat-label">Products to Reorder</div>
        <div class="stat-number" style="font-size:26px;color:<?= count($toOrder) > 0 ? '#b45309' : 'inherit' ?>;"><?= number_format(count($toOrder)) ?></div>
      </div>
      <div class="stat-card" style="padding:16px;text-align:left;">
        <div class="stat-label">Urgent / Oversold</div>
        <div class="stat-number" style="font-size:26px;color:<?= $urgentCnt > 0 ? 'var(--admin-danger)' : 'inherit' ?>;"><?= number_format($urgentCnt) ?></div>
      </div>
      <div class="stat-card" style="padding:16px;text-align:left;">
        <div class="stat-label">Units to Order</div>
        <div class="stat-number" style="font-size:26px;"><?= number_format($totalUnits) ?></div>
      </div>
      <div class="stat-card" style="padding:16px;text-align:left;">
        <div class="stat-label">Estimated Order Value</div>
        <div class="stat-number" style="font-size:26px;">₱<?= number_format($totalValue) ?></div>
      </div>
      <div class="stat-card" style="padding:16px;text-align:left;">
        <div class="stat-label">Stock Sufficient</div>
        <div class="stat-number" style="font-size:26px;"><?= number_format(count($sufficient)) ?></div>
      </div>
    </div>

    <!-- Settings ----------------------------------------------------------- -->
    <div class="admin-card df-no-print" style="padding:16px;margin-bottom:20px;">
      <h3 style="margin:0 0 4px;font-size:1.05rem;">Forecast Settings</h3>
      <p style="margin:0 0 14px;color:var(--admin-muted);font-size:13px;">
        Every suggestion below is recalculated from these. Set lead time and
        safety buffer to 0 for a plain "forecast minus stock" figure.
      </p>
      <form method="POST" class="df-settings">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <div>
          <label for="fw">Sales history window</label>
          <select id="fw" name="forecast_window_days" class="admin-form-input">
            <?php foreach (DF_ALLOWED_WINDOWS as $w): ?>
              <option value="<?= $w ?>" <?= (int)$settings['forecast_window_days'] === $w ? 'selected' : '' ?>><?= $w ?> days</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="fh">Forecast horizon (days)</label>
          <input id="fh" type="number" min="1" max="90" name="forecast_horizon_days" value="<?= (int)$settings['forecast_horizon_days'] ?>" class="admin-form-input">
        </div>
        <div>
          <label for="ss">Safety stock (days)</label>
          <input id="ss" type="number" min="0" max="60" name="safety_stock_days" value="<?= (int)$settings['safety_stock_days'] ?>" class="admin-form-input">
        </div>
        <div>
          <label for="lt">Default lead time (days)</label>
          <input id="lt" type="number" min="0" max="120" name="default_lead_time_days" value="<?= (int)$settings['default_lead_time_days'] ?>" class="admin-form-input">
        </div>
        <div>
          <label for="ms">Max stock (days of demand)</label>
          <input id="ms" type="number" min="7" max="365" name="max_stock_days" value="<?= (int)$settings['max_stock_days'] ?>" class="admin-form-input">
        </div>
        <div>
          <label for="mo">Minimum order qty</label>
          <input id="mo" type="number" min="1" max="500" name="min_order_qty" value="<?= (int)$settings['min_order_qty'] ?>" class="admin-form-input">
        </div>
        <div>
          <label style="margin-bottom:9px;">Trend weighting</label>
          <label style="display:flex;align-items:center;gap:7px;color:var(--admin-text);font-size:13px;">
            <input type="checkbox" name="trend_weighting" value="1" <?= $settings['trend_weighting'] ? 'checked' : '' ?>>
            Adjust for rising/falling sales
          </label>
        </div>
        <div>
          <button type="submit" class="action-btn admin-quick-link admin-quick-link--accent" style="border:none;cursor:pointer;width:100%;">Save &amp; Recalculate</button>
        </div>
      </form>
      <p style="margin:14px 0 0;color:var(--admin-muted);font-size:12px;line-height:1.55;">
        <strong>Store has <?= (int)$dataDays ?> day<?= $dataDays === 1 ? '' : 's' ?> of order history.</strong>
        <?php if ($dataDays < (int)$settings['forecast_window_days']): ?>
          That is shorter than the <?= (int)$settings['forecast_window_days'] ?>-day window, so sales rates are
          divided by <?= (int)$dataDays ?> days rather than <?= (int)$settings['forecast_window_days'] ?> —
          dividing by the full window would halve every rate and under-order everything.
        <?php else: ?>
          Enough to fill the <?= (int)$settings['forecast_window_days'] ?>-day window.
        <?php endif; ?>
      </p>
    </div>

    <!-- Filters ------------------------------------------------------------ -->
    <form method="GET" class="df-no-print" style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
      <input type="text" name="search" placeholder="Search product or category..." value="<?= htmlspecialchars($search) ?>" class="admin-form-input">
      <select name="only" class="admin-form-input" onchange="this.form.submit()">
        <option value="reorder" <?= $onlyMode === 'reorder' ? 'selected' : '' ?>>Needs reorder only</option>
        <option value="all" <?= $onlyMode === 'all' ? 'selected' : '' ?>>All products</option>
      </select>
      <button type="submit" class="action-btn admin-quick-link admin-quick-link--accent" style="border:none;cursor:pointer;">Apply</button>
      <?php if ($search): ?>
        <a href="demand_forecast.php" class="action-btn" style="background:var(--admin-surface);color:var(--admin-text);padding:8px 12px;border-radius:var(--radius-sm);text-decoration:none;box-shadow:var(--neu-raised-sm);">Clear</a>
      <?php endif; ?>
      <span style="flex:1;"></span>
      <?php $qs = http_build_query(array_filter(['search'=>$search,'only'=>$onlyMode,'export'=>'csv'])); ?>
      <a href="?<?= htmlspecialchars($qs) ?>" class="action-btn" style="background:var(--admin-surface);color:var(--admin-text);padding:8px 12px;border-radius:var(--radius-sm);text-decoration:none;box-shadow:var(--neu-raised-sm);">Export Reorder Plan</a>
      <a href="#" onclick="window.print();return false;" class="action-btn" style="background:var(--admin-surface);color:var(--admin-text);padding:8px 12px;border-radius:var(--radius-sm);text-decoration:none;box-shadow:var(--neu-raised-sm);">Print</a>
    </form>

    <!-- Recommendation cards ---------------------------------------------- -->
    <?php if (empty($visible)): ?>
      <div class="admin-card" style="padding:28px;text-align:center;color:var(--admin-muted);">
        <?php if ($onlyMode === 'reorder'): ?>
          No reorders needed. Every product has enough stock to cover its forecast demand,
          lead time and safety buffer.
        <?php else: ?>
          No products matched.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="df-cards" style="margin-bottom:20px;">
        <?php foreach (array_slice($visible, 0, 60) as $r): ?>
          <div class="df-card" style="border-left-color:<?= df_status_color($r['status']) ?>;">
            <h4><?= htmlspecialchars($r['name']) ?></h4>
            <div class="df-row">
              <span>Sold</span>
              <span><?= (int)$r['sold_window'] ?> units in <?= (int)$r['window_days'] ?> days</span>
            </div>
            <div class="df-row">
              <span>Average sales rate</span>
              <span><?= number_format($r['velocity'], 2) ?> units/day</span>
            </div>
            <div class="df-row">
              <span>Current stock</span>
              <span>
                <?= (int)$r['stock'] ?> units
                <?php if ($r['committed'] > 0): ?>
                  <span style="font-weight:400;color:var(--admin-muted);">(<?= (int)$r['available'] ?> free)</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="df-row">
              <span>Forecast <?= (int)$r['horizon_days'] ?>-day demand</span>
              <span><?= number_format($r['forecast'], 1) ?> units<?php
                  if (abs($r['trend'] - 1.0) > 0.01) {
                      echo ' <span style="font-weight:400;color:var(--admin-muted);">('
                         . ($r['trend'] > 1 ? '↑' : '↓') . ' ' . number_format($r['trend'], 2) . '× trend)</span>';
                  }
              ?></span>
            </div>
            <div class="df-row">
              <span>Days of cover</span>
              <span><?= df_cover_label($r['days_cover']) ?></span>
            </div>
            <div class="df-suggest">
              <div>
                <div style="font-size:11px;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.04em;">Suggested reorder</div>
                <div class="qty" style="color:<?= $r['suggested'] > 0 ? df_status_color($r['status']) : 'var(--admin-success)' ?>;">
                  <?= (int)$r['suggested'] ?> <span style="font-size:13px;font-weight:600;">units</span>
                </div>
              </div>
              <span class="df-pill" style="background:<?= df_status_color($r['status']) ?>;"><?= df_status_label($r['status']) ?></span>
            </div>
            <?php if ($r['suggested'] > 0): ?>
              <div class="df-why">
                <?= number_format($r['forecast'], 1) ?> forecast
                + <?= number_format($r['velocity'] * $r['lead_time_days'], 1) ?> lead time (<?= (int)$r['lead_time_days'] ?>d)
                + <?= number_format($r['safety_stock'], 1) ?> safety
                − <?= (int)$r['available'] ?> available
                = <strong><?= (int)$r['suggested'] ?></strong>
                · ≈₱<?= number_format($r['order_value']) ?>
                <br><?= htmlspecialchars($r['reason']) ?>
              </div>
            <?php else: ?>
              <div class="df-why"><?= htmlspecialchars($r['reason']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($visible) > 60): ?>
        <p style="color:var(--admin-muted);font-size:13px;margin-bottom:20px;">
          Showing the 60 most urgent of <?= number_format(count($visible)) ?>. Export the reorder plan for the full list.
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Full detail table --------------------------------------------------- -->
    <?php if (!empty($visible)): ?>
    <div class="admin-table-wrap">
      <div style="padding:12px 16px;border-bottom:1px solid var(--admin-border);color:var(--admin-text);font-weight:700;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <span>Forecast Detail</span>
        <small style="font-weight:500;color:var(--admin-muted);">
          Window <?= (int)$settings['forecast_window_days'] ?>d · horizon <?= (int)$settings['forecast_horizon_days'] ?>d ·
          lead <?= (int)$settings['default_lead_time_days'] ?>d · safety <?= (int)$settings['safety_stock_days'] ?>d ·
          max <?= (int)$settings['max_stock_days'] ?>d
        </small>
      </div>
      <div style="overflow:auto;max-height:600px;">
        <table>
          <thead>
            <tr>
              <th style="text-align:left;">Product</th>
              <th style="text-align:left;">Category</th>
              <th class="df-num">Sold (<?= (int)$settings['forecast_window_days'] ?>d)</th>
              <th class="df-num">Units/Day</th>
              <th class="df-num">On Hand</th>
              <th class="df-num">Available</th>
              <th class="df-num">Forecast</th>
              <th class="df-num">Required</th>
              <th class="df-num">Max</th>
              <th class="df-num">Suggested</th>
              <th style="text-align:left;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($visible as $r): ?>
              <tr<?= $r['suggested'] > 0 ? ' style="background:rgba(194,104,111,0.07);"' : '' ?>>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td style="color:var(--admin-muted);"><?= htmlspecialchars($r['category_name'] ?? 'Uncategorized') ?></td>
                <td class="df-num"><?= (int)$r['sold_window'] ?></td>
                <td class="df-num" style="color:var(--admin-muted);"><?= number_format($r['velocity'], 2) ?></td>
                <td class="df-num"><?= (int)$r['stock'] ?></td>
                <td class="df-num" style="<?= $r['available'] < 0 ? 'color:var(--admin-danger);font-weight:700;' : '' ?>"><?= (int)$r['available'] ?></td>
                <td class="df-num" style="color:var(--admin-muted);"><?= number_format($r['forecast'], 1) ?></td>
                <td class="df-num" style="color:var(--admin-muted);"><?= number_format($r['required'], 1) ?></td>
                <td class="df-num" style="color:var(--admin-muted);"><?= (int)$r['max_stock'] ?></td>
                <td class="df-num" style="font-weight:700;"><?= (int)$r['suggested'] ?></td>
                <td><span class="df-pill" style="background:<?= df_status_color($r['status']) ?>;"><?= df_status_label($r['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
