<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Require manager
require_role(['manager', 'admin']);

$pageTitle = 'Manager Dashboard - TechWorld';
$activeNav = '';
$isAdminTheme = true;
// Determine if current manager is approved
$pdo = getConnection();
$currentUserStatus = 'active';
try {
  $st = $pdo->prepare('SELECT status, role FROM users WHERE id = ? LIMIT 1');
  $st->execute([getUserId()]);
  $row = $st->fetch();
  if ($row) { $currentUserStatus = $row['status'] ?? 'active'; }
} catch (Throwable $e) { /* ignore and assume active */ }
$actionsDisabled = (!isSuperAdmin() && $currentUserStatus !== 'active');

// Quick-stats totals for the dashboard cards below.
$dashTotalOrders = 0;
$dashTotalProducts = 0;
$dashTotalCustomers = 0;
$dashTotalRevenue = 0.0;
try {
    $dashTotalOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $dashTotalProducts = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $dashTotalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
    // Matches the revenue definition already used in sales_report.php /
    // reports_analytics.php (sum of order totals, all orders).
    $dashTotalRevenue = (float)$pdo->query('SELECT SUM(total_amount) FROM orders')->fetchColumn();
} catch (Throwable $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Dashboard panels.
//
// Every figure below is read live - nothing here is sample data. Each query is
// wrapped on its own so one missing table (payments, order_items) degrades that
// single panel to an empty state instead of blanking the page. Cancelled orders
// are excluded from money everywhere, matching reports_analytics.php.
$dashTrend        = [];   // [['d' => 'Y-m-d', 'total' => float], ...] last 30 days
$dashDeltaRevenue = null; // % vs the previous 30 days, null when there is no base
$dashDeltaOrders  = null;
$dashStatusMix    = [];   // ['completed' => 12, ...]
$dashTopProducts  = [];
$dashRecentOrders = [];
$dashAovNow       = 0.0;

try {
    // 30-day revenue series, zero-filled in PHP so days with no orders still
    // draw a point - a gap-free line is the whole readability of a sparkline.
    $trendStmt = $pdo->query("
        SELECT DATE(created_at) AS d, SUM(total_amount) AS total
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
          AND status <> 'cancelled'
        GROUP BY DATE(created_at)
    ");
    $byDay = [];
    foreach ($trendStmt->fetchAll() as $r) { $byDay[$r['d']] = (float)$r['total']; }
    for ($i = 29; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i day"));
        $dashTrend[] = ['d' => $day, 'total' => $byDay[$day] ?? 0.0];
    }

    // This 30 days vs the 30 before it. One row, two windows.
    $cmpStmt = $pdo->query("
        SELECT
          SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN total_amount ELSE 0 END) AS rev_now,
          SUM(CASE WHEN created_at <  DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN total_amount ELSE 0 END) AS rev_prev,
          SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN 1 ELSE 0 END) AS ord_now,
          SUM(CASE WHEN created_at <  DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN 1 ELSE 0 END) AS ord_prev
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
          AND status <> 'cancelled'
    ");
    $cmp = $cmpStmt->fetch() ?: [];
    // Guarded: a first month of trading has no baseline, and 'up 100%' from
    // zero would be a lie. null renders as a dash instead.
    $pct = static function ($now, $prev) {
        $prev = (float)$prev;
        return $prev > 0 ? round((((float)$now - $prev) / $prev) * 100, 1) : null;
    };
    $dashDeltaRevenue = $pct($cmp['rev_now'] ?? 0, $cmp['rev_prev'] ?? 0);
    $dashDeltaOrders  = $pct($cmp['ord_now'] ?? 0, $cmp['ord_prev'] ?? 0);
    $dashAovNow = ((int)($cmp['ord_now'] ?? 0) > 0)
        ? (float)$cmp['rev_now'] / (int)$cmp['ord_now']
        : 0.0;

    foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM orders GROUP BY status")->fetchAll() as $r) {
        $dashStatusMix[(string)$r['status']] = (int)$r['c'];
    }
} catch (Throwable $e) {
    error_log('Dashboard trend error: ' . $e->getMessage());
}

try {
    $topStmt = $pdo->query("
        SELECT p.name,
               SUM(oi.quantity) AS sold,
               SUM(oi.quantity * oi.price) AS revenue
        FROM order_items oi
        INNER JOIN orders o   ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        WHERE o.status <> 'cancelled'
          AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY p.id, p.name
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $dashTopProducts = $topStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('Dashboard top products error: ' . $e->getMessage());
}

try {
    $recentStmt = $pdo->query("
        SELECT o.id, o.total_amount, o.status, o.created_at,
               u.firstname, u.lastname
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        ORDER BY o.created_at DESC
        LIMIT 7
    ");
    $dashRecentOrders = $recentStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('Dashboard recent orders error: ' . $e->getMessage());
}

$dashTrendMax = 0.0;
foreach ($dashTrend as $p) { if ($p['total'] > $dashTrendMax) { $dashTrendMax = $p['total']; } }

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container dash-compact" style="padding: 12px 0;">
  <style>
    /* ------------------------------------------------------------------
       Lightweight finance-dashboard styling for the manager dashboard.

       The look: a flat page with no shadows, hairline-bordered panels on a
       ground the same colour as the page, small uppercase labels over large
       tabular numerals, and exactly one accent colour used for the trend and
       for positive movement. Density comes from restraint, not from cramming -
       the panels are quiet so the numbers carry the page.

       Everything is scoped to .dash-compact, so no other admin page changes.
       ------------------------------------------------------------------ */
    html body.admin main.container.dash-compact{max-width:min(1280px,96vw);}

    /* Flat page: no shadows at all. Every neumorphic shadow in admin.css is
       drawn from these three tokens (each one a dark shadow bottom-right plus
       a white highlight top-left), so emptying them here removes the lot -
       panels, tiles, buttons and their hover/active states - without chasing
       individual box-shadow declarations. Custom properties inherit, so every
       descendant that draws one picks up the empty value. */
    main.container.dash-compact{
      --neu-raised:none;
      --neu-raised-sm:none;
      --neu-pressed:none;
      --fin-accent:var(--accent-cyan,#06b6d4);
      --fin-up:#10b981;
      --fin-down:#ef4444;
      /* one radius and one hairline for every panel on the page */
      --fin-radius:14px;
      --fin-line:1px solid var(--admin-border);
    }
    .dash-compact .dashboard-welcome,
    .dash-compact .action-btn,
    .dash-compact .fin-kpi,
    .dash-compact .fin-panel{box-shadow:none;}

    .dash-compact .admin-dashboard{margin-top:22px !important;}

    /* Banner: quieter than the shared one - on a page whose job is numbers, the
       greeting should not be the loudest thing on screen. */
    .dash-compact .dashboard-welcome{
      background:transparent;border:0;padding:0;margin-bottom:22px;
    }
    .dash-compact .dashboard-welcome h1{font-size:26px;letter-spacing:-.02em;margin:0 0 4px;}
    .dash-compact .dashboard-welcome p{font-size:14px;color:var(--admin-muted);margin:0;}

    /* ---- drag-to-rearrange ---- */
    .dash-compact .fin-layoutbar{
      display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-bottom:8px;
      font-size:11px;color:var(--admin-muted);
    }
    .dash-compact .fin-reset{
      font:inherit;font-size:11px;font-weight:600;color:var(--fin-accent);
      background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;
    }
    /* The grip is the only draggable part: dragging the whole card would swallow
       text selection and the links inside the panels. It stays invisible until
       the card is hovered or the grip is focused, so the page reads clean. */
    .dash-compact .fin-grip{
      flex:0 0 auto;width:20px;height:20px;padding:0;margin-right:-2px;
      display:inline-flex;align-items:center;justify-content:center;
      font:inherit;font-size:13px;line-height:1;color:var(--admin-muted);
      background:none;border:0;border-radius:5px;cursor:grab;
      opacity:0;transition:opacity .15s ease,background .15s ease;
    }
    .dash-compact .fin-kpi:hover .fin-grip,
    .dash-compact .fin-panel:hover .fin-grip,
    .dash-compact .fin-grip:focus-visible{opacity:1;}
    .dash-compact .fin-grip:hover{background:var(--admin-surface-2);}
    .dash-compact .fin-grip:active{cursor:grabbing;}
    .dash-compact .fin-panel-head .fin-grip{margin-top:1px;}
    /* the card being dragged, and the gap it will drop into */
    .dash-compact .is-dragging{opacity:.4;}
    .dash-compact .is-dropzone{outline:2px dashed var(--fin-accent);outline-offset:3px;}
    /* touch devices: a grip that only appears on hover can never be reached */
    @media (hover:none){
      .dash-compact .fin-grip{opacity:1;}
    }

    /* ---- KPI row ---- */
    .dash-compact .fin-kpis{
      display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px;
    }
    .dash-compact .fin-kpi{
      padding:18px 20px;border:var(--fin-line);border-radius:var(--fin-radius);
      background:var(--admin-surface);display:flex;flex-direction:column;gap:6px;
    }
    /* Label left, icon right. The icon anchors the tile without competing with
       the numeral - which is why it sits up here rather than above the figure
       the way the old stat tiles had it. */
    .dash-compact .fin-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:10px;}
    /* margin-right:auto, not space-between's own distribution: the row holds
       three items now (grip, label, icon) and space-between would spread all
       three evenly instead of keeping the grip next to its label. */
    .dash-compact .fin-kpi-label{
      font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
      color:var(--admin-muted);margin-right:auto;
    }
    .dash-compact .fin-kpi-icon{
      width:28px;height:28px;flex:0 0 auto;object-fit:contain;display:block;
    }
    /* tabular-nums is what lets a column of figures line up digit for digit -
       without it the KPI row jitters as the numbers change */
    .dash-compact .fin-kpi-value{
      font-size:28px;font-weight:700;letter-spacing:-.02em;line-height:1.1;
      font-variant-numeric:tabular-nums;
    }
    .dash-compact .fin-kpi-delta{font-size:12px;font-weight:600;font-variant-numeric:tabular-nums;}
    .dash-compact .fin-kpi-delta span{font-weight:400;color:var(--admin-muted);}
    .dash-compact .fin-kpi-delta.is-up{color:var(--fin-up);}
    .dash-compact .fin-kpi-delta.is-down{color:var(--fin-down);}
    .dash-compact .fin-kpi-delta.is-flat{color:var(--admin-muted);font-weight:400;}

    /* ---- panel grid: chart + status on top, orders + top products below ---- */
    .dash-compact .fin-grid{
      display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;
    }
    .dash-compact .fin-panel{
      padding:18px 20px;border:var(--fin-line);border-radius:var(--fin-radius);
      background:var(--admin-surface);min-width:0;
    }
    /* the chart and the orders table earn two of the three columns; the two
       list panels take one each, so each row reads as 2 + 1 */
    .dash-compact .fin-panel--chart,
    .dash-compact .fin-panel--wide{grid-column:span 2;}

    .dash-compact .fin-panel-head{
      display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;
    }
    /* same reason as .fin-kpi-label above - the title block claims the slack so
       the grip stays beside it and the link stays at the right edge */
    .dash-compact .fin-panel-head > div{margin-right:auto;min-width:0;}
    .dash-compact .fin-panel-head h2{font-size:15px;font-weight:700;margin:0;letter-spacing:-.01em;}
    .dash-compact .fin-panel-head p{font-size:12px;color:var(--admin-muted);margin:2px 0 0;}
    .dash-compact .fin-link{
      font-size:12px;font-weight:600;color:var(--fin-accent);text-decoration:none;white-space:nowrap;
    }
    .dash-compact .fin-link:hover{text-decoration:underline;}
    .dash-compact .fin-empty{
      font-size:13px;color:var(--admin-muted);margin:0;padding:24px 0;text-align:center;
    }

    /* ---- revenue sparkline ---- */
    /* colour lives on the wrapper so the SVG's currentColor picks it up for
       both the stroke and the gradient fill */
    .dash-compact .fin-chart{height:150px;color:var(--fin-accent);}
    .dash-compact .fin-chart svg{width:100%;height:100%;display:block;}
    .dash-compact .fin-chart-axis{
      display:flex;justify-content:space-between;margin-top:8px;
      font-size:11px;color:var(--admin-muted);font-variant-numeric:tabular-nums;
    }

    /* ---- status bars ---- */
    .dash-compact .fin-bars{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:13px;}
    .dash-compact .fin-bar-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;}
    .dash-compact .fin-bar-name{font-size:13px;}
    .dash-compact .fin-bar-num{font-size:13px;font-weight:600;font-variant-numeric:tabular-nums;}
    .dash-compact .fin-bar-num em{font-style:normal;font-weight:400;color:var(--admin-muted);margin-left:6px;}
    .dash-compact .fin-bar-track{height:6px;border-radius:99px;background:var(--admin-surface-2);overflow:hidden;}
    .dash-compact .fin-bar-fill{height:100%;border-radius:99px;background:var(--fin-accent);}
    /* status colours reuse the same palette the order pages use, so a status
       means the same colour wherever it appears in the admin */
    .dash-compact .fin-bar-fill.status-completed{background:#10b981;}
    .dash-compact .fin-bar-fill.status-cancelled{background:#ef4444;}
    .dash-compact .fin-bar-fill.status-pending{background:#f59e0b;}
    .dash-compact .fin-bar-fill.status-processing{background:#3b82f6;}
    .dash-compact .fin-bar-fill.status-shipped,
    .dash-compact .fin-bar-fill.status-ready{background:#a77693;}

    /* ---- recent orders table ---- */
    .dash-compact .fin-table-wrap{overflow-x:auto;}
    .dash-compact .fin-table{width:100%;border-collapse:collapse;font-size:13px;}
    .dash-compact .fin-table th{
      text-align:left;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
      color:var(--admin-muted);padding:0 10px 9px 0;border-bottom:var(--fin-line);white-space:nowrap;
    }
    .dash-compact .fin-table td{padding:10px 10px 10px 0;border-bottom:var(--fin-line);white-space:nowrap;}
    .dash-compact .fin-table tr:last-child td{border-bottom:0;}
    .dash-compact .fin-table .num{text-align:right;padding-right:0;}
    .dash-compact .fin-table .mono{font-variant-numeric:tabular-nums;}
    .dash-compact .fin-table .muted{color:var(--admin-muted);}
    .dash-compact .fin-pill{
      display:inline-block;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;
      background:var(--admin-surface-2);color:var(--admin-muted);
    }
    .dash-compact .fin-pill.status-completed{background:rgba(16,185,129,.14);color:#10b981;}
    .dash-compact .fin-pill.status-cancelled{background:rgba(239,68,68,.14);color:#ef4444;}
    .dash-compact .fin-pill.status-pending{background:rgba(245,158,11,.14);color:#f59e0b;}
    .dash-compact .fin-pill.status-processing{background:rgba(59,130,246,.14);color:#3b82f6;}
    .dash-compact .fin-pill.status-ready{background:rgba(167,118,147,.18);color:#a77693;}

    /* ---- top products ---- */
    .dash-compact .fin-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;}
    .dash-compact .fin-list li{
      display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:var(--fin-line);
    }
    .dash-compact .fin-list li:last-child{border-bottom:0;}
    .dash-compact .fin-rank{
      flex:0 0 auto;width:20px;font-size:11px;font-weight:700;color:var(--admin-muted);
      font-variant-numeric:tabular-nums;
    }
    /* min-width:0 so a long product name ellipsises instead of pushing the
       price out of the panel */
    .dash-compact .fin-list-body{display:flex;flex-direction:column;gap:1px;min-width:0;flex:1;}
    .dash-compact .fin-list-name{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .dash-compact .fin-list-sub{font-size:11px;color:var(--admin-muted);}
    .dash-compact .fin-list-num{font-size:13px;font-weight:600;white-space:nowrap;}

    @media (max-width: 1100px){
      .dash-compact .fin-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}
      .dash-compact .fin-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
      .dash-compact .fin-panel--chart,
      .dash-compact .fin-panel--wide{grid-column:span 2;}
    }
    @media (max-width: 680px){
      .dash-compact .fin-kpis,
      .dash-compact .fin-grid{grid-template-columns:minmax(0,1fr);}
      .dash-compact .fin-panel--chart,
      .dash-compact .fin-panel--wide{grid-column:span 1;}
    }
  </style>
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">
    <div class="dashboard-welcome">
      <h1>Manager Dashboard</h1>
      <p>Welcome, <?= htmlspecialchars($_SESSION['firstname'] ?? '') ?>! Manage your store operations efficiently.</p>
      <?php if ($actionsDisabled): ?>
        <div style="margin-top:10px;color:var(--admin-text);background:rgba(194,104,111,0.14);border:1px solid var(--admin-danger);border-radius:var(--radius-sm);padding:8px 14px;display:inline-block;font-weight:700;">⚠ Your manager account is pending Owner approval. Actions are temporarily disabled.</div>
      <?php endif; ?>
    </div>

    <?php
    // KPI row. Each tile is a number with its own 30-day delta - the delta is
    // what makes a figure mean anything, so it sits with the number rather than
    // in a separate panel. $delta is null when there is no prior period to
    // compare against, and renders as a dash instead of a fake +100%.
    // Icons are the same set the stat tiles used before this rework, so a figure
    // keeps the mark it has always had here: sales-logs for money, orders for
    // orders, total-users for customers. Avg order value is the one new figure,
    // and credit.gif is the closest thing in the set to "money per order".
    // 'id' is the key the saved layout is written against, so it must stay
    // stable - renaming a label is safe, renaming an id silently resets that
    // admin's arrangement back to default.
    $kpis = [
        ['id' => 'revenue',   'label' => 'Total revenue',   'icon' => 'sales-logs.gif',  'value' => '&#8369;' . number_format($dashTotalRevenue, 2), 'delta' => $dashDeltaRevenue],
        ['id' => 'orders',    'label' => 'Orders',          'icon' => 'orders.gif',      'value' => number_format($dashTotalOrders),                 'delta' => $dashDeltaOrders],
        ['id' => 'aov',       'label' => 'Avg order value', 'icon' => 'credit.gif',      'value' => '&#8369;' . number_format($dashAovNow, 2),       'delta' => null],
        ['id' => 'customers', 'label' => 'Customers',       'icon' => 'total-users.gif', 'value' => number_format($dashTotalCustomers),              'delta' => null],
    ];
    ?>
    <div class="fin-layoutbar">
      <span class="fin-layoutbar-hint">Drag <span aria-hidden="true">⠿</span> to rearrange</span>
      <button type="button" class="fin-reset" id="finResetLayout" hidden>Reset layout</button>
    </div>
    <div class="fin-kpis" data-sortable="kpis">
      <?php foreach ($kpis as $k): ?>
        <div class="fin-kpi" data-card-id="<?= htmlspecialchars($k['id']) ?>" data-card-name="<?= htmlspecialchars($k['label']) ?>">
          <div class="fin-kpi-top">
            <button type="button" class="fin-grip" aria-label="Move <?= htmlspecialchars($k['label']) ?>"><span aria-hidden="true">⠿</span></button>
            <span class="fin-kpi-label"><?= htmlspecialchars($k['label']) ?></span>
            <img class="fin-kpi-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/<?= $k['icon'] ?>" alt="" aria-hidden="true">
          </div>
          <div class="fin-kpi-value"><?= $k['value'] ?></div>
          <?php if ($k['delta'] === null): ?>
            <div class="fin-kpi-delta is-flat">&mdash; no prior period</div>
          <?php else: ?>
            <div class="fin-kpi-delta <?= $k['delta'] >= 0 ? 'is-up' : 'is-down' ?>">
              <?= $k['delta'] >= 0 ? '&uarr;' : '&darr;' ?> <?= abs($k['delta']) ?>% <span>vs prev 30 days</span>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="fin-grid" data-sortable="panels">
      <!-- Revenue trend -->
      <section data-card-id="revenue-trend" data-card-name="Revenue" class="fin-panel fin-panel--chart">
        <header class="fin-panel-head">
          <button type="button" class="fin-grip" aria-label="Move Revenue"><span aria-hidden="true">⠿</span></button>
          <div>
            <h2>Revenue</h2>
            <p>Last 30 days, cancelled orders excluded</p>
          </div>
          <a class="fin-link" href="<?= WEB_ROOT ?>public/authenticated/admin/reports_analytics.php?view=analytics">Analytics &rarr;</a>
        </header>
        <?php if ($dashTrendMax > 0): ?>
          <?php
          // Inline SVG rather than a chart library: it is one path, it needs no
          // JS, and it inherits currentColor so it themes for free. The viewBox
          // is 0 0 300 80 with preserveAspectRatio="none", so the path stretches
          // to whatever width the panel gets; non-scaling-stroke keeps the line
          // an even weight despite that stretch.
          $n = count($dashTrend);
          $pts = [];
          foreach ($dashTrend as $i => $pt) {
              $x = $n > 1 ? ($i / ($n - 1)) * 300 : 0;
              $y = 80 - (($pt['total'] / $dashTrendMax) * 68) - 6;
              $pts[] = round($x, 2) . ',' . round($y, 2);
          }
          $line = 'M' . implode(' L', $pts);
          $area = $line . ' L300,80 L0,80 Z';
          ?>
          <div class="fin-chart">
            <svg viewBox="0 0 300 80" preserveAspectRatio="none" role="img"
                 aria-label="Revenue over the last 30 days, peaking at <?= number_format($dashTrendMax, 2) ?> pesos">
              <defs>
                <linearGradient id="finRevFill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="currentColor" stop-opacity=".22"/>
                  <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
                </linearGradient>
              </defs>
              <path d="<?= $area ?>" fill="url(#finRevFill)"/>
              <path d="<?= $line ?>" fill="none" stroke="currentColor" stroke-width="1.6"
                    vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>
            </svg>
          </div>
          <footer class="fin-chart-axis">
            <span><?= date('M j', strtotime($dashTrend[0]['d'])) ?></span>
            <span>peak &#8369;<?= number_format($dashTrendMax, 0) ?></span>
            <span><?= date('M j', strtotime($dashTrend[$n - 1]['d'])) ?></span>
          </footer>
        <?php else: ?>
          <p class="fin-empty">No revenue recorded in the last 30 days.</p>
        <?php endif; ?>
      </section>

      <!-- Order status mix -->
      <section data-card-id="order-status" data-card-name="Order status" class="fin-panel">
        <header class="fin-panel-head">
          <button type="button" class="fin-grip" aria-label="Move Order status"><span aria-hidden="true">⠿</span></button>
          <div><h2>Order status</h2><p>All time</p></div>
        </header>
        <?php $statusTotal = array_sum($dashStatusMix); ?>
        <?php if ($statusTotal > 0): ?>
          <ul class="fin-bars">
            <?php arsort($dashStatusMix); foreach ($dashStatusMix as $status => $count): ?>
              <?php $share = ($count / $statusTotal) * 100; ?>
              <li>
                <div class="fin-bar-row">
                  <span class="fin-bar-name"><?= htmlspecialchars(ucfirst((string)$status)) ?></span>
                  <span class="fin-bar-num"><?= number_format($count) ?><em><?= number_format($share, 0) ?>%</em></span>
                </div>
                <div class="fin-bar-track"><div class="fin-bar-fill status-<?= htmlspecialchars(strtolower((string)$status)) ?>" style="width:<?= round($share, 2) ?>%"></div></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="fin-empty">No orders yet.</p>
        <?php endif; ?>
      </section>

      <!-- Recent orders -->
      <section data-card-id="recent-orders" data-card-name="Recent orders" class="fin-panel fin-panel--wide">
        <header class="fin-panel-head">
          <button type="button" class="fin-grip" aria-label="Move Recent orders"><span aria-hidden="true">⠿</span></button>
          <div><h2>Recent orders</h2><p>Newest first</p></div>
          <a class="fin-link" href="<?= WEB_ROOT ?>public/authenticated/admin/OrdMan.php">All orders &rarr;</a>
        </header>
        <?php if (!empty($dashRecentOrders)): ?>
          <div class="fin-table-wrap">
            <table class="fin-table">
              <thead>
                <tr><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th class="num">Amount</th></tr>
              </thead>
              <tbody>
                <?php foreach ($dashRecentOrders as $o): ?>
                  <?php $who = trim(($o['firstname'] ?? '') . ' ' . ($o['lastname'] ?? '')); ?>
                  <tr>
                    <td class="mono">#<?= (int)$o['id'] ?></td>
                    <td><?= htmlspecialchars($who !== '' ? $who : 'Guest') ?></td>
                    <td class="muted"><?= date('M j, Y', strtotime((string)$o['created_at'])) ?></td>
                    <td><span class="fin-pill status-<?= htmlspecialchars(strtolower((string)$o['status'])) ?>"><?= htmlspecialchars(ucfirst((string)$o['status'])) ?></span></td>
                    <td class="num mono">&#8369;<?= number_format((float)$o['total_amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="fin-empty">No orders yet.</p>
        <?php endif; ?>
      </section>

      <!-- Top products -->
      <section data-card-id="top-products" data-card-name="Top products" class="fin-panel">
        <header class="fin-panel-head">
          <button type="button" class="fin-grip" aria-label="Move Top products"><span aria-hidden="true">⠿</span></button>
          <div><h2>Top products</h2><p>By revenue, last 30 days &middot; <?= number_format($dashTotalProducts) ?> in catalog</p></div>
        </header>
        <?php if (!empty($dashTopProducts)): ?>
          <ul class="fin-list">
            <?php foreach ($dashTopProducts as $i => $prod): ?>
              <li>
                <span class="fin-rank"><?= $i + 1 ?></span>
                <span class="fin-list-body">
                  <span class="fin-list-name" title="<?= htmlspecialchars((string)$prod['name']) ?>"><?= htmlspecialchars((string)$prod['name']) ?></span>
                  <span class="fin-list-sub"><?= number_format((int)$prod['sold']) ?> sold</span>
                </span>
                <span class="fin-list-num mono">&#8369;<?= number_format((float)$prod['revenue'], 0) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="fin-empty">No sales in the last 30 days.</p>
        <?php endif; ?>
      </section>
    </div>

    <?php /* --------------------------------------------------------------
       Drag-to-rearrange.

       Saved per admin, in this browser, under a key that includes the user id
       so two managers sharing a machine don't inherit each other's layout.
       There is no preferences table in this schema, so this is browser-local:
       the arrangement follows the person on this computer, not across devices.
       Swapping to a server-side store later only means replacing readLayout /
       writeLayout - nothing else here knows where the order came from.

       The order is applied SYNCHRONOUSLY, right here in the markup rather than
       on DOMContentLoaded, for two reasons: the cards never visibly jump from
       default order into saved order, and the entrance animation in
       includes/header.php - which numbers cards on DOMContentLoaded - sees the
       final order and staggers along it instead of across a shuffle.
       -------------------------------------------------------------- */ ?>
    <script>
    (function () {
      var KEY = 'techwrld.dashboardLayout.<?= (int)(getUserId() ?? 0) ?>';

      function readLayout() {
        try { return JSON.parse(localStorage.getItem(KEY)) || {}; }
        catch (e) { return {}; }
      }
      function writeLayout(obj) {
        try { localStorage.setItem(KEY, JSON.stringify(obj)); } catch (e) { /* private mode */ }
      }
      function idsOf(box) {
        return Array.prototype.map.call(box.children, function (el) { return el.getAttribute('data-card-id'); });
      }
      function saveOrder(box) {
        var all = readLayout();
        all[box.getAttribute('data-sortable')] = idsOf(box);
        writeLayout(all);
        reflectReset();
      }

      var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-sortable]'));

      // ---- apply the saved order (runs now, before first paint) ----
      var saved = readLayout();
      boxes.forEach(function (box) {
        var want = saved[box.getAttribute('data-sortable')];
        if (!Array.isArray(want)) return;
        want.forEach(function (id) {
          // Cards added to the dashboard after a layout was saved simply aren't
          // in the list; they keep their markup position and are appended to the
          // saved ones rather than vanishing.
          var el = box.querySelector(':scope > [data-card-id="' + id + '"]');
          if (el) box.appendChild(el);
        });
      });

      function isDefault() {
        var all = readLayout();
        return !boxes.some(function (box) { return Array.isArray(all[box.getAttribute('data-sortable')]); });
      }
      function reflectReset() {
        var btn = document.getElementById('finResetLayout');
        if (btn) btn.hidden = isDefault();
      }

      // ---- drag ----
      function wire() {
        reflectReset();

        var btn = document.getElementById('finResetLayout');
        if (btn) {
          btn.addEventListener('click', function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
            location.reload();
          });
        }

        var dragged = null;

        boxes.forEach(function (box) {
          Array.prototype.forEach.call(box.children, function (card) {
            var grip = card.querySelector('.fin-grip');
            if (!grip) return;

            // The card carries draggable, but only from the moment the pointer
            // goes down on its grip - otherwise the whole card is draggable and
            // selecting text or clicking a link inside it starts a drag.
            grip.addEventListener('pointerdown', function () { card.draggable = true; });
            card.addEventListener('dragend', function () {
              card.draggable = false;
              card.classList.remove('is-dragging');
              boxes.forEach(function (b) {
                Array.prototype.forEach.call(b.children, function (c) { c.classList.remove('is-dropzone'); });
              });
              dragged = null;
            });

            card.addEventListener('dragstart', function (e) {
              dragged = card;
              card.classList.add('is-dragging');
              e.dataTransfer.effectAllowed = 'move';
              // Firefox ignores a drag that sets no data.
              try { e.dataTransfer.setData('text/plain', card.getAttribute('data-card-id')); } catch (err) {}
            });

            card.addEventListener('dragover', function (e) {
              // Only within the same container: a KPI tile dropped into the
              // panel grid would be laid out as a full panel.
              if (!dragged || dragged === card || dragged.parentNode !== box) return;
              e.preventDefault();
              e.dataTransfer.dropEffect = 'move';
              card.classList.add('is-dropzone');
            });
            card.addEventListener('dragleave', function () { card.classList.remove('is-dropzone'); });

            card.addEventListener('drop', function (e) {
              if (!dragged || dragged === card || dragged.parentNode !== box) return;
              e.preventDefault();
              card.classList.remove('is-dropzone');
              // Insert before or after depending on which side of the target's
              // midpoint the pointer is - dropping on the right half should put
              // the card after, not before.
              var r = card.getBoundingClientRect();
              var after = (e.clientX - r.left) > r.width / 2;
              box.insertBefore(dragged, after ? card.nextSibling : card);
              saveOrder(box);
            });

            // Keyboard equivalent - a drag-only control is unusable without a
            // pointer, and the grip is already a focusable button.
            grip.addEventListener('keydown', function (e) {
              var back = (e.key === 'ArrowLeft' || e.key === 'ArrowUp');
              var fwd  = (e.key === 'ArrowRight' || e.key === 'ArrowDown');
              if (!back && !fwd) return;
              e.preventDefault();
              if (back && card.previousElementSibling) {
                box.insertBefore(card, card.previousElementSibling);
              } else if (fwd && card.nextElementSibling) {
                box.insertBefore(card.nextElementSibling, card);
              } else { return; }
              saveOrder(box);
              grip.focus();
            });
          });
        });
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
      } else {
        wire();
      }
    })();
    </script>

  </div>
</main>

<?php // The floating QR scanner that used to live here is now in
      // includes/scan_fab.php, rendered from includes/navigation.php so it
      // appears on every admin page rather than only this one. ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
