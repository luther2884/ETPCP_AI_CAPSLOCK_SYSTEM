<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/product_units.php';

require_role(['admin']);

/**
 * admin/product_units.php - entering and auditing the physical units behind a
 * product.
 *
 * Two views in one file, because they are the same subject at two zoom levels
 * and splitting them would mean two sets of counts that could disagree:
 *
 *   no ?product_id   the catalogue, with each product's unit state - which are
 *                    live, which are flagged but never onboarded, which are
 *                    plain quantity-tracked accessories.
 *   ?product_id=N    one product: its units, and the forms that add more.
 *
 * Super Admin reaches this page the same way it reaches every other admin
 * page - super-admin/switch_to_admin.php opens a real admin session with
 * sa_mode set - so require_role(['admin']) covers Manager and Owner both and
 * there is no second copy of this screen under super-admin/.
 */

$pageTitle = 'Unit Tracking - TechWorld Admin';
$activeNav = 'units';
$isAdminTheme = true;

$pdo = getConnection();

// Same gate every other admin write-capable page uses: an admin whose account
// is still awaiting Super Admin approval may look but not touch.
$currentUserStatus = 'active';
try {
    $st = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
    $st->execute([getUserId()]);
    $row = $st->fetch();
    if ($row) { $currentUserStatus = (string)($row['status'] ?? 'active'); }
} catch (Throwable $e) { /* assume active */ }
$actionsDisabled = (!isSuperAdmin() && $currentUserStatus !== 'active');

$productId = (int)($_GET['product_id'] ?? 0);

// ----------------------------------------------------------------- writes ----
// Plain POST then redirect, matching add_product.php rather than
// product_prices.php's AJAX: a bulk paste reports per-line results that have to
// survive the reload, and Post/Redirect/Get keeps a refresh from re-entering
// forty serials.
$flash = $_SESSION['pu_flash'] ?? null;
unset($_SESSION['pu_flash']);

// Whether this page was reached from the onboarding filter. Carried through
// every redirect below so that entering a product's serials returns to the
// backlog rather than dumping staff back in the full catalogue after each one.
$fromPending = (($_GET['from'] ?? $_POST['from'] ?? '') === 'pending') ? '&from=pending' : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postProductId = (int)($_POST['product_id'] ?? 0);

    if (!check_csrf()) {
        $_SESSION['pu_flash'] = ['type' => 'error', 'errors' => ['Your session expired. Try again.']];
        header('Location: ?product_id=' . $postProductId . $fromPending);
        exit;
    }
    if ($actionsDisabled) {
        $_SESSION['pu_flash'] = ['type' => 'error', 'errors' => ['Your admin account is awaiting Super Admin approval.']];
        header('Location: ?product_id=' . $postProductId . $fromPending);
        exit;
    }

    try {
        if ($action === 'add_units' && $postProductId > 0) {
            // Single entry and bulk entry go through the same pu_add_units(),
            // so "what counts as a duplicate" cannot differ between them.
            $mode = ($_POST['mode'] ?? 'single') === 'bulk' ? 'bulk' : 'single';
            $rows = $mode === 'bulk'
                ? pu_parse_bulk((string)($_POST['bulk_serials'] ?? ''))
                : [['serial' => (string)($_POST['serial_number'] ?? ''), 'imei' => (string)($_POST['imei'] ?? '')]];

            if (!$rows) {
                $_SESSION['pu_flash'] = ['type' => 'error', 'errors' => ['Enter at least one serial number.']];
            } else {
                $wasLive = pu_tracking_live($pdo, $postProductId);

                $pdo->beginTransaction();
                $result = pu_add_units($pdo, $postProductId, $rows);
                $pdo->commit();

                if ($result['added'] > 0) {
                    logAudit('product_units_add', 'product_units', $postProductId, null, [
                        'product_id' => $postProductId,
                        'added'      => $result['added'],
                        'rejected'   => count($result['errors']),
                        'stock'      => $result['stock'],
                    ]);
                }

                $_SESSION['pu_flash'] = [
                    'type'     => $result['added'] > 0 ? ($result['errors'] ? 'partial' : 'success') : 'error',
                    'added'    => $result['added'],
                    'stock'    => $result['stock'],
                    'errors'   => $result['errors'],
                    'warnings' => $result['warnings'],
                    // The switch-over: before this the product ran on the stock
                    // number someone typed, from here on it runs on the units.
                    // Said plainly, because it is the moment the figure on the
                    // storefront changes meaning.
                    'went_live' => (!$wasLive && $result['added'] > 0),
                ];
            }
            header('Location: ?product_id=' . $postProductId . $fromPending);
            exit;
        }

        if ($action === 'set_tracking' && $postProductId > 0) {
            $on = !empty($_POST['requires_serial']);
            $pdo->prepare('UPDATE products SET requires_serial = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$on ? 1 : 0, $postProductId]);
            logAudit('product_units_flag', 'products', $postProductId, null, ['requires_serial' => $on ? 1 : 0]);

            // Turning tracking OFF leaves the unit rows alone on purpose. They
            // are the shop's record of what it sold to whom, and the returns
            // desk still has to be able to look them up. What changes is that
            // products.stock stops being a mirror and becomes a typed number
            // again - so it is reset to the in-stock unit count rather than
            // left at whatever the mirror last wrote, which would be a figure
            // nobody chose.
            if (!$on) {
                $cnt = pu_in_stock_count($pdo, $postProductId);
                $pdo->prepare('UPDATE products SET stock = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$cnt, $postProductId]);
            }

            $_SESSION['pu_flash'] = [
                'type'   => 'success',
                'notice' => $on
                    ? 'Unit tracking switched on. It takes effect once this product has its first serial.'
                    : 'Unit tracking switched off. Existing unit records are kept for returns lookups.',
            ];
            header('Location: ?product_id=' . $postProductId . $fromPending);
            exit;
        }

        if ($action === 'remove_unit') {
            // Only an in-stock unit, and that restriction is the whole point:
            // a bulk paste with a typo in it has to be correctable, but a unit
            // that is reserved, sold or returned is part of an order's history
            // and deleting it would erase the proof this feature exists to
            // provide. Those get marked defective instead, from the lookup
            // page.
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $pdo->beginTransaction();
            $uStmt = $pdo->prepare('SELECT unit_id, product_id, serial_number, status FROM product_units WHERE unit_id = ? FOR UPDATE');
            $uStmt->execute([$unitId]);
            $unit = $uStmt->fetch(PDO::FETCH_ASSOC);

            if (!$unit) {
                $pdo->rollBack();
                $_SESSION['pu_flash'] = ['type' => 'error', 'errors' => ['That unit no longer exists.']];
            } elseif ($unit['status'] !== 'in_stock') {
                $pdo->rollBack();
                $_SESSION['pu_flash'] = ['type' => 'error', 'errors' => [
                    'Serial ' . $unit['serial_number'] . ' is ' . strtolower(pu_status_label((string)$unit['status']))
                    . ' and is part of an order record - it cannot be deleted.'
                ]];
            } else {
                $pdo->prepare('DELETE FROM product_units WHERE unit_id = ? AND status = \'in_stock\'')->execute([$unitId]);
                pu_recount_stock($pdo, (int)$unit['product_id']);
                $pdo->commit();
                logAudit('product_units_remove', 'product_units', $unitId, ['serial_number' => $unit['serial_number']], null);
                $_SESSION['pu_flash'] = ['type' => 'success', 'notice' => 'Serial ' . $unit['serial_number'] . ' removed.'];
            }
            header('Location: ?product_id=' . (int)($unit['product_id'] ?? $postProductId) . $fromPending);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $_) {} }
        error_log('product_units write error: ' . $e->getMessage());
        $_SESSION['pu_flash'] = ['type' => 'error', 'errors' => ['Server error - nothing was saved. Try again.']];
        header('Location: ?product_id=' . $postProductId . $fromPending);
        exit;
    }
}

// ------------------------------------------------------------------ reads ----
$search   = trim((string)($_GET['q'] ?? ''));
$statusIn = (string)($_GET['status'] ?? '');
if (!in_array($statusIn, PU_STATUSES, true)) { $statusIn = ''; }

// "Show only the products still waiting for their first serial."
//
// The onboarding banner used to end with "They are listed first below", which
// was the only thing it offered - and it was not even reliably true: the
// catalogue below is searchable and paginated, so on page 2, or after a
// search, those products are nowhere near the top. A banner reporting a
// 130-product job with no way to work it is a dead end, and an inaccurate
// one at that. This is that way.
$pendingOnly = !empty($_GET['pending']);

$product = null;
$units = [];
$counts = [];
$products = [];
$totalPages = 1;
$page = max(1, (int)($_GET['page'] ?? 1));
$loadError = null;
$backlog = ['count' => 0, 'stock' => 0, 'dormant' => 0];

try {
    $backlog = pu_onboarding_backlog($pdo);

    if ($productId > 0) {
        $pStmt = $pdo->prepare(
            'SELECT p.*, c.name AS category_name
               FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.id = ? LIMIT 1'
        );
        $pStmt->execute([$productId]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($product) {
            // One grouped query rather than five COUNT(*)s, so the chips can
            // never show a set of numbers that do not add up to the total.
            $cStmt = $pdo->prepare('SELECT status, COUNT(*) n FROM product_units WHERE product_id = ? GROUP BY status');
            $cStmt->execute([$productId]);
            foreach (PU_STATUSES as $s) { $counts[$s] = 0; }
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $counts[(string)$r['status']] = (int)$r['n']; }

            $where = 'WHERE pu.product_id = ?';
            $params = [$productId];
            if ($statusIn !== '') { $where .= ' AND pu.status = ?'; $params[] = $statusIn; }
            if ($search !== '') {
                $where .= ' AND (pu.serial_number LIKE ? OR pu.imei LIKE ?)';
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $uStmt = $pdo->prepare(
                "SELECT pu.*, o.id AS order_id, o.invoice_no, o.status AS order_status,
                        u.firstname, u.lastname
                   FROM product_units pu
              LEFT JOIN order_items oi ON oi.id = pu.order_item_id
              LEFT JOIN orders o       ON o.id = oi.order_id
              LEFT JOIN users u        ON u.id = o.user_id
                   $where
                   ORDER BY FIELD(pu.status,'in_stock','reserved','sold','returned','defective'), pu.serial_number
                   LIMIT 500"
            );
            $uStmt->execute($params);
            $units = $uStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $limit = 25;
        $offset = ($page - 1) * $limit;
        $where = 'WHERE 1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (p.name LIKE ? OR c.name LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        // The onboarding filter. Exactly the same predicate as
        // pu_onboarding_backlog()'s - flagged, no units, and something on the
        // shelf to actually enter - so the count in the banner and the length
        // of this list can never disagree. A product with stock 0 is excluded
        // from both: there is no serial to type for a unit you do not hold.
        if ($pendingOnly) {
            $where .= " AND p.requires_serial = 1 AND p.stock > 0 AND p.status = 'active'
                        AND NOT EXISTS (SELECT 1 FROM product_units pux WHERE pux.product_id = p.id)";
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $limit));

        /* Ordering.
           Normally: needs-attention first (flagged with no units), then the
           live tracked products, then the untracked remainder - someone
           opening this page cold is almost always here to clear the backlog.
           Filtered to the backlog: by how many units are waiting, biggest
           first, because entering 12 serials for one laptop model clears more
           of the job than entering 1. */
        $orderBy = $pendingOnly
            ? 'ORDER BY p.stock DESC, p.name ASC'
            : 'ORDER BY (p.requires_serial = 1 AND (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id) = 0) DESC,
                        p.requires_serial DESC,
                        p.name ASC';

        $listStmt = $pdo->prepare(
            "SELECT p.id, p.name, p.stock, p.status, p.requires_serial,
                    c.name AS category_name,
                    (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id) AS units_total,
                    (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id AND pu.status = 'in_stock') AS units_in_stock,
                    (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id AND pu.status = 'reserved') AS units_reserved,
                    (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id AND pu.status = 'sold') AS units_sold
               FROM products p LEFT JOIN categories c ON c.id = p.category_id
               $where
               $orderBy
               LIMIT $limit OFFSET $offset"
        );
        $listStmt->execute($params);
        $products = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('product_units read error: ' . $e->getMessage());
    $loadError = 'Could not load unit data.';
}

/** The three states a product can be in, named once. */
function pu_state(array $row): array
{
    if (empty($row['requires_serial'])) {
        return ['label' => 'Quantity only', 'chip' => 'ad-chip--inactive'];
    }
    if ((int)($row['units_total'] ?? 0) === 0) {
        return ['label' => 'Pending onboarding', 'chip' => 'ad-chip--reorder'];
    }
    return ['label' => 'Tracking live', 'chip' => 'ad-chip--active'];
}

/** Status chip class for one unit. */
function pu_chip(string $status): string
{
    switch ($status) {
        case 'in_stock':  return 'ad-chip--active';
        case 'reserved':  return 'ad-chip--reorder';
        case 'sold':      return 'ad-chip--inactive';
        case 'defective': return 'ad-chip--oversold';
        default:          return 'ad-chip--out';
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    /* The unit table is naturally wide (serial, IMEI, status, order, customer,
       dates), so this page gets the same widened container the order queues
       use rather than the 1200px default. */
    html body.admin main.container{max-width:min(1480px,96vw);}
    .pu-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    @media (max-width:980px){.pu-grid{grid-template-columns:1fr;}}
    .pu-counts{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;}
    .pu-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;}
    .pu-msg{border-radius:8px;padding:12px 14px;margin:0 0 16px;font-size:14px;line-height:1.55;}
    .pu-msg--success{background:#e7f6ef;color:#1f7a53;border:1px solid #b6e3ce;}
    .pu-msg--partial{background:#fff4e0;color:#8a5a00;border:1px solid #f0d9a8;}
    .pu-msg--error{background:#fdecf1;color:#a8264d;border:1px solid #f4c4d4;}
    .pu-msg ul{margin:8px 0 0;padding-left:20px;}
    .pu-msg li{margin:2px 0;}
    .pu-bare{background:none;border:0;padding:0;color:#a8264d;font:inherit;cursor:pointer;text-decoration:underline;}
  </style>

  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/product_management.php">Products</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <?php if ($product): ?>
          <?php // Back to the onboarding filter when that is where this was
                // opened from (see from=pending on the row links), so working
                // down the backlog does not re-filter after every product. ?>
          <a href="?<?= (($_GET['from'] ?? '') === 'pending') ? 'pending=1' : '' ?>">Unit Tracking</a>
          <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
          <span class="adm-crumb-cur" aria-current="page"><?= htmlspecialchars((string)$product['name']) ?></span>
        <?php else: ?>
          <span class="adm-crumb-cur" aria-current="page">Unit Tracking</span>
        <?php endif; ?>
      </nav>
    </div>
    <div class="adm-actions">
      <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/unit_lookup.php">Serial / IMEI lookup</a>
    </div>
  </div>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">

    <?php if ($actionsDisabled): ?>
      <div class="pu-msg pu-msg--partial">Your admin account is pending Super Admin approval, so units cannot be added or changed yet.</div>
    <?php endif; ?>

    <?php if ($loadError): ?>
      <div class="pu-msg pu-msg--error"><?= htmlspecialchars($loadError) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <?php $ft = (string)($flash['type'] ?? 'success'); ?>
      <div class="pu-msg pu-msg--<?= htmlspecialchars($ft === 'partial' ? 'partial' : ($ft === 'error' ? 'error' : 'success')) ?>">
        <?php if (!empty($flash['notice'])): ?>
          <?= htmlspecialchars((string)$flash['notice']) ?>
        <?php endif; ?>
        <?php if (!empty($flash['added'])): ?>
          <strong><?= (int)$flash['added'] ?> unit<?= (int)$flash['added'] === 1 ? '' : 's' ?> added.</strong>
          <?php if ($flash['stock'] !== null): ?>
            Stock is now <?= (int)$flash['stock'] ?>.
          <?php endif; ?>
          <?php if (!empty($flash['went_live'])): ?>
            <br>Unit tracking is now live for this product &mdash; from here on the stock shown on the site is the number of in-stock units, not a typed figure.
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($flash['errors'])): ?>
          <?php if (!empty($flash['added'])): ?><br><?php endif; ?>
          <?= count($flash['errors']) === 1 ? '' : '<strong>' . count($flash['errors']) . ' line(s) rejected:</strong>' ?>
          <ul>
            <?php foreach ($flash['errors'] as $msg): ?>
              <li><?= htmlspecialchars((string)$msg) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($flash['warnings'])): ?>
          <ul>
            <?php foreach ($flash['warnings'] as $msg): ?>
              <li><?= htmlspecialchars((string)$msg) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

<?php if (!$product): /* ------------------------------ catalogue view ----- */ ?>

    <h1 style="margin:0 0 6px;color:var(--admin-text);">Unit Tracking</h1>
    <p class="ad-hint" style="margin:0 0 18px;max-width:72ch;">
      Serialised products are tracked one physical unit at a time, so a return can be matched to the
      exact unit that was sold. Accessories stay on plain quantity stock.
    </p>

    <?php if ($pendingOnly): ?>
      <?php // Filtered view: say what is on screen and how to get back out. ?>
      <div class="pu-msg pu-msg--partial">
        <strong>Waiting for their first serial</strong> &mdash;
        <?= number_format($backlog['count']) ?> product<?= $backlog['count'] === 1 ? '' : 's' ?>,
        <?= number_format($backlog['stock']) ?> unit<?= $backlog['stock'] === 1 ? '' : 's' ?> to enter,
        the biggest first. Each one keeps selling on its quantity stock until you enter a serial for it.
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
          <a class="ad-btn ad-btn--secondary" href="?">Show all products</a>
        </div>
      </div>
    <?php elseif ($backlog['count'] > 0): ?>
      <?php /* The actionable version of this banner.
               It used to end with "They are listed first below", which was all
               it offered and was not dependably true - this list is searchable
               and paginated, so after a search or on page 2 they are not near
               the top. Now it links to a filter that shows exactly them. */ ?>
      <div class="pu-msg pu-msg--partial">
        <strong><?= number_format($backlog['count']) ?> product<?= $backlog['count'] === 1 ? '' : 's' ?> flagged for unit tracking
        <?= $backlog['count'] === 1 ? 'has' : 'have' ?> no serials on file yet</strong>
        &mdash; <?= number_format($backlog['stock']) ?> unit<?= $backlog['stock'] === 1 ? '' : 's' ?> of stock to enter.
        Each keeps selling on its quantity stock exactly as before; tracking starts for a product
        the moment you enter its first serial.
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <a class="ad-btn ad-btn--primary" href="?pending=1">Work through them</a>
          <?php if ($backlog['dormant'] > 0): ?>
            <?php /* Flagged, no units, and nothing on the shelf either. Not
                     counted above because there is no serial to type for a
                     unit you do not hold - they onboard themselves when stock
                     arrives. Mentioned so the number is accountable rather
                     than looking like it is missing some. */ ?>
            <span class="ad-hint" style="margin:0;">
              <?= number_format($backlog['dormant']) ?> more
              <?= $backlog['dormant'] === 1 ? 'is' : 'are' ?> flagged but hold no stock &mdash; nothing to enter until some arrives.
            </span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <form method="GET" class="ad-field" style="margin:0 0 16px;display:flex;gap:10px;align-items:center;">
      <?php // Carried through the search, or searching inside the onboarding
            // filter would silently drop back to the whole catalogue. ?>
      <?php if ($pendingOnly): ?><input type="hidden" name="pending" value="1"><?php endif; ?>
      <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
             placeholder="<?= $pendingOnly ? 'Search within these' : 'Search by product or category' ?>" style="flex:1;">
      <button type="submit" class="ad-btn ad-btn--primary">Search</button>
      <?php if ($search !== ''): ?>
        <a class="ad-btn ad-btn--secondary" href="?<?= $pendingOnly ? 'pending=1' : '' ?>">Clear</a>
      <?php endif; ?>
    </form>

    <div class="ad-card">
      <div class="ad-card-head">
        <h2 class="ad-card-title"><?= $pendingOnly ? 'Waiting for a first serial' : 'Products' ?></h2>
        <span class="ad-card-note"><?= $pendingOnly
          ? 'Most units waiting, first'
          : 'In stock / reserved / sold, counted from unit records' ?></span>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead>
            <tr>
              <th>ID</th><th>Product</th><th>Category</th>
              <th style="text-align:right;">Stock</th>
              <th style="text-align:right;">In stock</th>
              <th style="text-align:right;">Reserved</th>
              <th style="text-align:right;">Sold</th>
              <th>Tracking</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$products): ?>
            <tr><td colspan="9" class="ad-table-empty">
              <?php if ($pendingOnly && $search === ''): ?>
                Nothing left to onboard &mdash; every flagged product holding stock has its serials on file.
              <?php elseif ($pendingOnly): ?>
                No product waiting for a serial matches &ldquo;<?= htmlspecialchars($search) ?>&rdquo;.
              <?php else: ?>
                No products found.
              <?php endif; ?>
            </td></tr>
          <?php else: foreach ($products as $p): $state = pu_state($p); ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td style="font-weight:600;"><?= htmlspecialchars((string)$p['name']) ?></td>
              <td><?= htmlspecialchars((string)($p['category_name'] ?? '—')) ?></td>
              <td style="text-align:right;"><?= (int)$p['stock'] ?></td>
              <td style="text-align:right;"><?= (int)$p['units_in_stock'] ?: '—' ?></td>
              <td style="text-align:right;"><?= (int)$p['units_reserved'] ?: '—' ?></td>
              <td style="text-align:right;"><?= (int)$p['units_sold'] ?: '—' ?></td>
              <td><span class="ad-chip <?= $state['chip'] ?>"><?= htmlspecialchars($state['label']) ?></span></td>
              <td style="text-align:right;">
                <?php // from=pending sends the breadcrumb back to the filter,
                      // so working down the list does not mean re-filtering
                      // after every product. ?>
                <a class="ad-btn <?= $pendingOnly ? 'ad-btn--primary' : 'ad-btn--secondary' ?>"
                   href="?product_id=<?= (int)$p['id'] ?><?= $pendingOnly ? '&amp;from=pending' : '' ?>">
                  <?= $pendingOnly ? 'Enter serials' : 'Manage units' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination" style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap;">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?page=<?= $i ?>&amp;q=<?= urlencode($search) ?><?= $pendingOnly ? '&amp;pending=1' : '' ?>"
             class="ad-btn <?= $i === $page ? 'ad-btn--dark' : 'ad-btn--secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

<?php else: /* ----------------------------------------- one product view ---- */ ?>

    <h1 style="margin:0 0 4px;color:var(--admin-text);"><?= htmlspecialchars((string)$product['name']) ?></h1>
    <p class="ad-hint" style="margin:0 0 14px;">
      <?= htmlspecialchars((string)($product['category_name'] ?? 'Uncategorised')) ?>
      &middot; Product #<?= (int)$product['id'] ?>
      &middot; Stock on site: <strong><?= (int)$product['stock'] ?></strong>
    </p>

    <div class="pu-counts">
      <?php $state = pu_state($product + ['units_total' => array_sum($counts)]); ?>
      <span class="ad-chip <?= $state['chip'] ?>"><?= htmlspecialchars($state['label']) ?></span>
      <?php foreach (PU_STATUSES as $s): if (!$counts[$s]) { continue; } ?>
        <span class="ad-chip <?= pu_chip($s) ?>"><?= htmlspecialchars(pu_status_label($s)) ?>: <?= (int)$counts[$s] ?></span>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($product['requires_serial']) && array_sum($counts) === 0): ?>
      <div class="pu-msg pu-msg--partial">
        This product is flagged for unit tracking but has no serials yet, so it is still selling on its
        quantity stock of <strong><?= (int)$product['stock'] ?></strong>.
        Entering the first serial switches it over: the stock shown on the site becomes the number of
        in-stock units. Enter all <?= (int)$product['stock'] ?> before it next sells, or the storefront
        will offer fewer than you hold.
      </div>
    <?php endif; ?>

    <div class="pu-grid" style="margin-bottom:18px;">
      <!-- Single entry -->
      <div class="ad-card">
        <div class="ad-card-head">
          <h2 class="ad-card-title">Add one unit</h2>
          <span class="ad-card-note">Serial from the label on the unit</span>
        </div>
        <div class="ad-card-body ad-field">
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_units">
            <input type="hidden" name="mode" value="single">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <?php if ($fromPending !== ''): ?><input type="hidden" name="from" value="pending"><?php endif; ?>

            <label class="ad-label" for="pu_serial">Serial number</label>
            <input type="text" name="serial_number" id="pu_serial" maxlength="<?= PU_SERIAL_MAX ?>"
                   autocomplete="off" spellcheck="false" placeholder="e.g. PF3K8L21" required>

            <label class="ad-label" for="pu_imei" style="margin-top:12px;">IMEI <span style="font-weight:400;color:var(--ad-muted);">(only for cellular devices)</span></label>
            <input type="text" name="imei" id="pu_imei" inputmode="numeric" autocomplete="off"
                   placeholder="15 digits, or leave blank">
            <p class="ad-hint">Leave blank unless the unit has a cellular radio.</p>

            <div style="margin-top:14px;">
              <button type="submit" class="ad-btn ad-btn--primary" <?= $actionsDisabled ? 'disabled' : '' ?>>Add unit</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Bulk entry -->
      <div class="ad-card">
        <div class="ad-card-head">
          <h2 class="ad-card-title">Add many units</h2>
          <span class="ad-card-note">One serial per line, up to <?= number_format(PU_BULK_MAX) ?></span>
        </div>
        <div class="ad-card-body ad-field">
          <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_units">
            <input type="hidden" name="mode" value="bulk">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <?php if ($fromPending !== ''): ?><input type="hidden" name="from" value="pending"><?php endif; ?>

            <label class="ad-label" for="pu_bulk">Serial numbers</label>
            <textarea name="bulk_serials" id="pu_bulk" rows="7" spellcheck="false"
                      placeholder="PF3K8L21&#10;PF3K8L22&#10;PF3K8L23"></textarea>
            <p class="ad-hint">
              To include IMEIs, put one on each line after a comma or tab &mdash; <span class="pu-mono">PF3K8L21, 356938035643809</span>.
              Good lines are saved even if others are rejected, and every rejected line is listed back to you.
            </p>

            <div style="margin-top:14px;">
              <button type="submit" class="ad-btn ad-btn--primary" <?= $actionsDisabled ? 'disabled' : '' ?>>Add units</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- The flag -->
    <div class="ad-card" style="margin-bottom:18px;">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Tracking setting</h2>
      </div>
      <div class="ad-card-body">
        <form method="POST" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_tracking">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <?php if ($fromPending !== ''): ?><input type="hidden" name="from" value="pending"><?php endif; ?>
          <label style="display:flex;gap:8px;align-items:center;font-size:14px;cursor:pointer;">
            <input type="checkbox" name="requires_serial" value="1" <?= !empty($product['requires_serial']) ? 'checked' : '' ?>>
            Track this product per unit (serial number required)
          </label>
          <button type="submit" class="ad-btn ad-btn--secondary" <?= $actionsDisabled ? 'disabled' : '' ?>>Save setting</button>
          <span class="ad-hint" style="margin:0;">Switching off keeps existing unit records for returns lookups.</span>
        </form>
      </div>
    </div>

    <!-- Unit list -->
    <div class="ad-card">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Units</h2>
        <span class="ad-card-note"><?= count($units) ?> shown<?= count($units) >= 500 ? ' (first 500)' : '' ?></span>
      </div>
      <div class="ad-card-body ad-field" style="padding-bottom:0;">
        <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <?php if ($fromPending !== ''): ?><input type="hidden" name="from" value="pending"><?php endif; ?>
          <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
                 placeholder="Search serial or IMEI" style="flex:1;min-width:200px;">
          <select name="status">
            <option value="">All statuses</option>
            <?php foreach (PU_STATUSES as $s): ?>
              <option value="<?= $s ?>" <?= $statusIn === $s ? 'selected' : '' ?>><?= htmlspecialchars(pu_status_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="ad-btn ad-btn--primary">Filter</button>
          <?php if ($search !== '' || $statusIn !== ''): ?>
            <a class="ad-btn ad-btn--secondary" href="?product_id=<?= (int)$product['id'] ?>">Clear</a>
          <?php endif; ?>
        </form>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead>
            <tr>
              <th>Serial</th><th>IMEI</th><th>Status</th>
              <th>Order</th><th>Customer</th><th>Added</th><th>Sold</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$units): ?>
            <tr><td colspan="8" class="ad-table-empty">
              <?= ($search !== '' || $statusIn !== '') ? 'No units match that filter.' : 'No units recorded for this product yet.' ?>
            </td></tr>
          <?php else: foreach ($units as $u): ?>
            <tr>
              <td class="pu-mono" style="font-weight:600;"><?= htmlspecialchars((string)$u['serial_number']) ?></td>
              <td class="pu-mono"><?= $u['imei'] ? htmlspecialchars((string)$u['imei']) : '—' ?></td>
              <td><span class="ad-chip <?= pu_chip((string)$u['status']) ?>"><?= htmlspecialchars(pu_status_label((string)$u['status'])) ?></span></td>
              <td>
                <?php if (!empty($u['order_id'])): ?>
                  <?= htmlspecialchars((string)($u['invoice_no'] ?: ('#' . (int)$u['order_id']))) ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <?php $cust = trim((string)($u['firstname'] ?? '') . ' ' . (string)($u['lastname'] ?? '')); ?>
                <?= $cust !== '' ? htmlspecialchars($cust) : '—' ?>
              </td>
              <td><?= $u['date_added'] ? htmlspecialchars(date('j M Y', strtotime((string)$u['date_added']))) : '—' ?></td>
              <td><?= $u['date_sold'] ? htmlspecialchars(date('j M Y', strtotime((string)$u['date_sold']))) : '—' ?></td>
              <td style="text-align:right;">
                <?php if ($u['status'] === 'in_stock' && !$actionsDisabled): ?>
                  <form method="POST" style="display:inline;"
                        data-confirm="Remove serial <?= htmlspecialchars((string)$u['serial_number']) ?>? Use this only for a serial typed in by mistake."
                        data-confirm-ok="Remove">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove_unit">
                    <input type="hidden" name="unit_id" value="<?= (int)$u['unit_id'] ?>">
                    <?php if ($fromPending !== ''): ?><input type="hidden" name="from" value="pending"><?php endif; ?>
                    <button type="submit" class="pu-bare">Remove</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php endif; ?>

  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
