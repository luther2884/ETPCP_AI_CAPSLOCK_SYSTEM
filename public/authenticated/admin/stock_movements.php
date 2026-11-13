<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/inventory_stock.php';

require_role(['admin']);

/**
 * admin/stock_movements.php - the one movement log.
 *
 * Every state change the inventory module makes is written here by
 * inv_log_movement(), which is the only writer - so this page is the whole
 * history rather than one of several partial views of it. Reads through
 * inv_movements(), the same function the reports use, so the history and the
 * reports can never disagree about what happened.
 *
 * Filters are the ones the brief lists: date range, product, brand, movement
 * type, user - plus a free-text box over serial / reference / product name,
 * because "where did serial X go" is the question this page gets asked.
 */

$pageTitle = 'Stock Movement History - TechWorld Admin';
$activeNav = 'inventory';
$isAdminTheme = true;

$pdo = getConnection();

$filters = [
    'from'       => trim((string)($_GET['from'] ?? '')),
    'to'         => trim((string)($_GET['to'] ?? '')),
    'product_id' => (int)($_GET['product_id'] ?? 0),
    'brand'      => trim((string)($_GET['brand'] ?? '')),
    'type'       => trim((string)($_GET['type'] ?? '')),
    'user_id'    => (int)($_GET['user_id'] ?? 0),
    'search'     => trim((string)($_GET['q'] ?? '')),
];
// A bad date would be bound straight into the comparison and silently match
// nothing, which reads as "no history" rather than "bad filter".
foreach (['from', 'to'] as $k) {
    if ($filters[$k] !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $filters[$k]);
        if (!$d || $d->format('Y-m-d') !== $filters[$k]) { $filters[$k] = ''; }
    }
}
if (!isset(INV_MOVEMENT_LABELS[$filters['type']])) { $filters['type'] = ''; }

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;

$rows = []; $total = 0; $brands = []; $users = []; $productList = []; $loadError = null;
try {
    $res = inv_movements($pdo, $filters + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);
    $rows  = $res['rows'];
    $total = $res['total'];

    $brands = inv_brands($pdo);
    $users  = inv_movement_users($pdo);
    // Only products that actually appear in the log - a 445-item dropdown of
    // which 20 have ever moved is a worse filter than a short accurate one.
    $productList = $pdo->query(
        "SELECT DISTINCT p.id, p.name FROM stock_movements sm JOIN products p ON p.id = sm.product_id ORDER BY p.name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('stock_movements page: ' . $e->getMessage());
    $loadError = 'Could not load the movement history.';
}

$totalPages = max(1, (int)ceil($total / $perPage));

/** Keep every active filter on a pager link. */
function sm_url(int $p): string
{
    global $filters;
    $qs = array_filter([
        'from' => $filters['from'], 'to' => $filters['to'],
        'product_id' => $filters['product_id'] ?: '', 'brand' => $filters['brand'],
        'type' => $filters['type'], 'user_id' => $filters['user_id'] ?: '',
        'q' => $filters['search'], 'page' => $p,
    ], static fn($v) => $v !== '' && $v !== 0);
    return '?' . http_build_query($qs);
}

/** Chip class per movement type: in green, out red, neutral grey. */
function sm_chip(string $t): string
{
    if (in_array($t, INV_MOVEMENT_IN, true))   { return 'ad-chip--active'; }
    if ($t === 'defective' || $t === 'disposed') { return 'ad-chip--oversold'; }
    if (in_array($t, INV_MOVEMENT_OUT, true))  { return 'ad-chip--out'; }
    return 'ad-chip--inactive';
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    html body.admin main.container{max-width:min(1500px,96vw);}
    .sm-filters{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px;align-items:end;}
    @media (max-width:1200px){.sm-filters{grid-template-columns:repeat(4,minmax(0,1fr));}}
    @media (max-width:760px){.sm-filters{grid-template-columns:1fr 1fr;}}
    .sm-mono{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;}
    .sm-qty{text-align:right;font-variant-numeric:tabular-nums;font-weight:700;}
    .sm-reason{font-size:12px;color:var(--admin-muted);max-width:320px;}
    .sm-sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;}
    .sm-sum-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;padding:14px 16px;}
    .sm-sum-n{font-size:1.5rem;font-weight:800;color:var(--admin-text);line-height:1.1;}
    .sm-sum-l{font-size:.74rem;color:var(--admin-muted);margin-top:3px;}
  </style>

  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/inventory_control.php">Inventory</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Movement History</span>
      </nav>
    </div>
    <div class="adm-actions">
      <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/receive_delivery.php">Receive delivery</a>
      <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/inventory_reports.php">Inventory reports</a>
    </div>
  </div>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">
    <h1 style="margin:0 0 6px;color:var(--admin-text);">Stock Movement History</h1>
    <p class="ad-hint" style="margin:0 0 18px;max-width:80ch;">
      Every Stock In, Sold, Defective, Pull-Out, Returned to Supplier, Disposed and Customer Return
      movement, per unit where there is one.
    </p>

    <?php if ($loadError): ?>
      <div style="background:#fdecf1;color:#a8264d;border:1px solid #f4c4d4;border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:14px;">
        <?= htmlspecialchars($loadError) ?>
      </div>
    <?php endif; ?>

    <form method="GET" class="ad-card ad-field" style="margin-bottom:18px;">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Filters</h2>
        <span class="ad-card-note"><?= number_format($total) ?> movement<?= $total === 1 ? '' : 's' ?> match</span>
      </div>
      <div class="ad-card-body">
        <div class="sm-filters">
          <div>
            <label class="ad-label" for="sm_from">From</label>
            <input type="date" name="from" id="sm_from" value="<?= htmlspecialchars($filters['from']) ?>">
          </div>
          <div>
            <label class="ad-label" for="sm_to">To</label>
            <input type="date" name="to" id="sm_to" value="<?= htmlspecialchars($filters['to']) ?>">
          </div>
          <div>
            <label class="ad-label" for="sm_type">Movement</label>
            <select name="type" id="sm_type">
              <option value="">All</option>
              <?php foreach (INV_MOVEMENT_LABELS as $k => $label): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ad-label" for="sm_product">Product</label>
            <select name="product_id" id="sm_product">
              <option value="">All</option>
              <?php foreach ($productList as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $filters['product_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ad-label" for="sm_brand">Brand</label>
            <select name="brand" id="sm_brand">
              <option value="">All</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= htmlspecialchars((string)$b) ?>" <?= $filters['brand'] === $b ? 'selected' : '' ?>><?= htmlspecialchars((string)$b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ad-label" for="sm_user">User</label>
            <select name="user_id" id="sm_user">
              <option value="">All</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filters['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars(trim((string)$u['name']) ?: ('user #' . (int)$u['id'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="ad-label" for="sm_q">Serial / reference</label>
            <input type="search" name="q" id="sm_q" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="serial, DR or PO no.">
          </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
          <button type="submit" class="ad-btn ad-btn--primary">Apply filters</button>
          <a class="ad-btn ad-btn--secondary" href="?">Clear</a>
        </div>
      </div>
    </form>

    <div class="ad-card">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Movements</h2>
        <span class="ad-card-note">
          <?php if ($total): ?>
            showing <?= number_format(($page - 1) * $perPage + 1) ?>&ndash;<?= number_format(min($page * $perPage, $total)) ?>
            of <?= number_format($total) ?>
          <?php endif; ?>
        </span>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead><tr>
            <th>Date</th><th>Movement</th><th>Product</th><th>Brand</th><th>Serial / IMEI</th>
            <th class="sm-qty">Qty</th><th>Reason</th><th>Reference</th><th>User</th>
          </tr></thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9" class="ad-table-empty">
                <?= array_filter($filters) ? 'No movements match those filters.' : 'No movements recorded yet.' ?>
              </td></tr>
            <?php else: foreach ($rows as $m): ?>
              <tr>
                <td style="white-space:nowrap;font-size:12.5px;"><?= htmlspecialchars(date('j M Y H:i', strtotime((string)$m['created_at']))) ?></td>
                <td><span class="ad-chip <?= sm_chip((string)$m['movement_type']) ?>">
                  <?= htmlspecialchars(INV_MOVEMENT_LABELS[(string)$m['movement_type']] ?? (string)$m['movement_type']) ?>
                </span></td>
                <td style="font-weight:600;"><?= htmlspecialchars((string)($m['product_name'] ?? '(deleted product)')) ?></td>
                <td style="font-size:12.5px;"><?= htmlspecialchars((string)($m['brand'] ?? '—')) ?></td>
                <td class="sm-mono"><?= $m['serial_number'] ? htmlspecialchars((string)$m['serial_number']) : '—' ?></td>
                <td class="sm-qty"><?= (int)$m['quantity'] ?></td>
                <td class="sm-reason"><?= $m['reason'] ? htmlspecialchars((string)$m['reason']) : '—' ?></td>
                <td class="sm-mono"><?= $m['reference_no'] ? htmlspecialchars((string)$m['reference_no']) : '—' ?></td>
                <td style="font-size:12.5px;">
                  <?= htmlspecialchars(trim((string)($m['user_name'] ?? '')) ?: 'system') ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination" style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
        <?php for ($i = max(1, $page - 4); $i <= min($totalPages, $page + 4); $i++): ?>
          <a href="<?= htmlspecialchars(sm_url($i)) ?>" class="ad-btn <?= $i === $page ? 'ad-btn--dark' : 'ad-btn--secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
