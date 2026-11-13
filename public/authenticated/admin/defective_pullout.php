<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/inventory_stock.php';

require_role(['admin']);

/**
 * admin/defective_pullout.php - Stock Out.
 *
 * The Manager's half of the pull-out flow: pick the faulty units by serial,
 * say where the defect came from, and raise the request. The units leave
 * sellable stock the moment the request is raised - not when it is approved -
 * because a faulty unit must not stay buyable while an approval sits in a
 * queue.
 *
 * The Owner's half (approve / reject / final action) is a separate page in the
 * Owner's own panel: super-admin/pullout_approvals.php. It has to be there and
 * not here, because switch_to_admin.php deliberately rewrites user_role to
 * 'admin' for an Owner working in the admin panel - so isSuperAdmin() is false
 * on this page by design, and an approval button here could not tell an Owner
 * from a Manager.
 */

$pageTitle = 'Defective / Pull-Out - TechWorld Admin';
$activeNav = 'inventory';
$isAdminTheme = true;

$pdo = getConnection();

$currentUserStatus = 'active';
try {
    $st = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
    $st->execute([getUserId()]);
    if ($row = $st->fetch()) { $currentUserStatus = (string)($row['status'] ?? 'active'); }
} catch (Throwable $e) { /* assume active */ }
$actionsDisabled = (!isSuperAdmin() && $currentUserStatus !== 'active');

$flash = $_SESSION['dp_flash'] ?? null;
unset($_SESSION['dp_flash']);

$productId = (int)($_GET['product_id'] ?? 0);
$prefillSource = (string)($_GET['source'] ?? '');
if (!isset(INV_PULLOUT_SOURCES[$prefillSource])) { $prefillSource = ''; }

/* ----------------------------------------------------------------- POST ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back = 'defective_pullout.php' . ($productId ? '?product_id=' . $productId : '');

    if (!check_csrf()) {
        $_SESSION['dp_flash'] = ['type' => 'error', 'errors' => ['Your session expired. Try again.']];
        header('Location: ' . $back); exit;
    }
    if ($actionsDisabled) {
        $_SESSION['dp_flash'] = ['type' => 'error', 'errors' => ['Your admin account is awaiting Super Admin approval.']];
        header('Location: ' . $back); exit;
    }

    try {
        if ($action === 'raise_pullout') {
            $unitIds = array_map('intval', (array)($_POST['unit_ids'] ?? []));
            $result = inv_create_pullout(
                $pdo,
                $unitIds,
                (string)($_POST['source'] ?? ''),
                (string)($_POST['reason'] ?? ''),
                (int)getUserId()
            );
            if ($result['ok']) {
                logAudit('pullout_requested', 'pull_out_requests', (int)$result['pull_out_id'], null, [
                    'reference_no' => $result['reference_no'],
                    'units'        => $result['count'],
                    'source'       => $_POST['source'] ?? '',
                ]);
                $_SESSION['dp_flash'] = ['type' => 'success', 'notice' => $result['message']];
            } else {
                $_SESSION['dp_flash'] = ['type' => 'error', 'errors' => $result['errors']];
            }
            header('Location: defective_pullout.php'); exit;
        }

        if ($action === 'return_to_stock') {
            $res = inv_return_unit_to_stock(
                $pdo, (int)($_POST['unit_id'] ?? 0), (int)getUserId(),
                (string)($_POST['restore_reason'] ?? '')
            );
            if ($res['ok']) {
                logAudit('unit_returned_to_stock', 'product_units', (int)($_POST['unit_id'] ?? 0), null,
                    ['reason' => $_POST['restore_reason'] ?? '']);
            }
            $_SESSION['dp_flash'] = $res['ok']
                ? ['type' => 'success', 'notice' => $res['message']]
                : ['type' => 'error', 'errors' => [$res['message']]];
            header('Location: defective_pullout.php'); exit;
        }
    } catch (Throwable $e) {
        error_log('defective_pullout: ' . $e->getMessage());
        $_SESSION['dp_flash'] = ['type' => 'error', 'errors' => ['Server error - nothing was changed.']];
        header('Location: defective_pullout.php'); exit;
    }
}

/* ----------------------------------------------------------------- READS --- */
$trackedProducts = [];
$pickUnits       = [];
$defectiveUnits  = [];
$myRequests      = [];
$loadError       = null;

try {
    // Products that actually have units to pull out.
    $trackedProducts = $pdo->query(
        "SELECT p.id, p.name, p.brand,
                (SELECT COUNT(*) FROM product_units pu
                  WHERE pu.product_id = p.id AND pu.status IN ('in_stock','sold','returned')) AS pickable
           FROM products p
          WHERE EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id)
          ORDER BY p.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($productId > 0) {
        /* Units that can be pulled out.
           'reserved' is excluded: that unit is promised to a live order, and
           pulling it out from under the customer is a different problem that
           has to start by releasing the order line. inv_create_pullout()
           refuses it too, with that explanation. */
        $u = $pdo->prepare(
            "SELECT pu.unit_id, pu.serial_number, pu.imei, pu.status, pu.date_added, pu.date_sold,
                    o.invoice_no, o.user_id AS customer_id,
                    TRIM(CONCAT(COALESCE(cu.firstname,''), ' ', COALESCE(cu.lastname,''))) AS customer_name,
                    s.name AS supplier_name, d.dr_number
               FROM product_units pu
          LEFT JOIN order_items oi ON oi.id = pu.order_item_id
          LEFT JOIN orders o       ON o.id = oi.order_id
          LEFT JOIN users cu       ON cu.id = o.user_id
          LEFT JOIN stock_deliveries d ON d.id = pu.delivery_id
          LEFT JOIN suppliers s    ON s.id = d.supplier_id
              WHERE pu.product_id = ?
                AND pu.status IN ('in_stock','sold','returned')
                AND NOT EXISTS (SELECT 1 FROM pull_out_items pi WHERE pi.product_unit_id = pu.unit_id)
              ORDER BY FIELD(pu.status,'in_stock','returned','sold'), pu.serial_number"
        );
        $u->execute([$productId]);
        $pickUnits = $u->fetchAll(PDO::FETCH_ASSOC);
    }

    // Already defective and sitting here - with whether a request covers them.
    $defectiveUnits = $pdo->query(
        "SELECT pu.unit_id, pu.serial_number, pu.status, p.name AS product_name, p.brand,
                r.reference_no, r.status AS request_status, r.final_action
           FROM product_units pu
           JOIN products p ON p.id = pu.product_id
      LEFT JOIN pull_out_items pi ON pi.product_unit_id = pu.unit_id
      LEFT JOIN pull_out_requests r ON r.id = pi.pull_out_id
          WHERE pu.status = 'defective'
          ORDER BY p.name, pu.serial_number
          LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);

    $myRequests = $pdo->query(
        "SELECT r.*,
                (SELECT COUNT(*) FROM pull_out_items i WHERE i.pull_out_id = r.id) AS unit_count,
                TRIM(CONCAT(COALESCE(rq.firstname,''), ' ', COALESCE(rq.lastname,''))) AS requested_by_name,
                TRIM(CONCAT(COALESCE(rv.firstname,''), ' ', COALESCE(rv.lastname,''))) AS reviewed_by_name
           FROM pull_out_requests r
      LEFT JOIN users rq ON rq.id = r.requested_by
      LEFT JOIN users rv ON rv.id = r.reviewed_by
          ORDER BY FIELD(r.status,'pending','approved','rejected'), r.created_at DESC
          LIMIT 40"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('defective_pullout read: ' . $e->getMessage());
    $loadError = 'Could not load the pull-out data.';
}

/** Status chip class for a unit. */
function dp_chip(string $s): string
{
    switch ($s) {
        case 'in_stock':  return 'ad-chip--active';
        case 'returned':  return 'ad-chip--out';
        case 'sold':      return 'ad-chip--inactive';
        case 'defective': return 'ad-chip--oversold';
        default:          return 'ad-chip--reorder';
    }
}
/** Status chip class for a request. */
function dp_req_chip(string $s): string
{
    return $s === 'approved' ? 'ad-chip--active' : ($s === 'rejected' ? 'ad-chip--oversold' : 'ad-chip--reorder');
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    html body.admin main.container{max-width:min(1400px,96vw);}
    .dp-msg{border-radius:8px;padding:12px 14px;margin:0 0 18px;font-size:14px;line-height:1.55;}
    .dp-msg--success{background:#e7f6ef;color:#1f7a53;border:1px solid #b6e3ce;}
    .dp-msg--error{background:#fdecf1;color:#a8264d;border:1px solid #f4c4d4;}
    .dp-msg ul{margin:8px 0 0;padding-left:20px;} .dp-msg li{margin:2px 0;}
    .dp-mono{font-family:ui-monospace,Consolas,monospace;font-size:13px;}
    .dp-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    @media (max-width:860px){.dp-grid{grid-template-columns:1fr;}}
    .dp-steps{margin:0 0 18px;padding:14px 16px;border-radius:10px;background:var(--admin-surface);
      box-shadow:var(--neu-pressed);font-size:13px;line-height:1.7;color:var(--admin-muted);}
    .dp-steps strong{color:var(--admin-text);}
    .dp-bare{background:none;border:0;padding:0;color:#a8264d;font:inherit;cursor:pointer;text-decoration:underline;}
    .dp-sel-count{font-size:12px;color:var(--admin-muted);}
  </style>

  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/inventory_control.php">Inventory</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Defective / Pull-Out</span>
      </nav>
    </div>
    <div class="adm-actions">
      <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/unit_lookup.php">Serial / IMEI lookup</a>
      <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/stock_movements.php">Movement history</a>
    </div>
  </div>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">
    <h1 style="margin:0 0 6px;color:var(--admin-text);">Defective / Pull-Out</h1>

    <div class="dp-steps">
      <strong>How this works.</strong>
      You pick the faulty units by serial and say where the defect came from.
      They are marked <strong>Defective</strong> and leave sellable stock <strong>immediately</strong> &mdash;
      before any approval, so nobody can buy one in the meantime.
      The request then goes to the <strong>Super Admin (Owner)</strong> to approve or reject,
      and after approval the Owner records what finally happened:
      Returned to Supplier, Replaced, or Disposed.
      <?php if (!empty($_SESSION['sa_mode'])): ?>
        <br>You are in Admin Mode &mdash; approve from
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/exit_admin_mode.php">your Owner panel</a>.
      <?php endif; ?>
    </div>

    <?php if ($actionsDisabled): ?>
      <div class="dp-msg dp-msg--error">Your admin account is pending Super Admin approval, so pull-outs cannot be raised yet.</div>
    <?php endif; ?>
    <?php if ($loadError): ?><div class="dp-msg dp-msg--error"><?= htmlspecialchars($loadError) ?></div><?php endif; ?>

    <?php if ($flash): ?>
      <div class="dp-msg dp-msg--<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>">
        <?php if (!empty($flash['notice'])): ?><?= htmlspecialchars((string)$flash['notice']) ?><?php endif; ?>
        <?php if (!empty($flash['errors'])): ?>
          <strong>Nothing was changed:</strong>
          <ul><?php foreach ($flash['errors'] as $e): ?><li><?= htmlspecialchars((string)$e) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Step 1: pick the product -->
    <form method="GET" class="ad-card ad-field" style="margin-bottom:18px;">
      <div class="ad-card-head"><h2 class="ad-card-title">1. Pick the product</h2></div>
      <div class="ad-card-body" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:260px;">
          <label class="ad-label" for="dp_product">Product</label>
          <select name="product_id" id="dp_product">
            <option value="">&mdash; choose a product &mdash;</option>
            <?php foreach ($trackedProducts as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $productId === (int)$p['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$p['name']) ?><?= $p['brand'] ? ' (' . htmlspecialchars((string)$p['brand']) . ')' : '' ?>
                &mdash; <?= (int)$p['pickable'] ?> unit<?= (int)$p['pickable'] === 1 ? '' : 's' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($prefillSource !== ''): ?>
          <input type="hidden" name="source" value="<?= htmlspecialchars($prefillSource) ?>">
        <?php endif; ?>
        <button type="submit" class="ad-btn ad-btn--primary">Show units</button>
      </div>
    </form>

    <!-- Step 2: pick the units and raise it -->
    <?php if ($productId > 0): ?>
      <form method="POST" class="ad-card ad-field" style="margin-bottom:18px;" id="dpForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="raise_pullout">
        <div class="ad-card-head">
          <h2 class="ad-card-title">2. Pick the units and describe the defect</h2>
          <span class="ad-card-note dp-sel-count" id="dpCount">none selected</span>
        </div>
        <div class="ad-card-body">
          <?php if (!$pickUnits): ?>
            <p class="ad-hint" style="margin:0;">
              No units of this product are available to pull out. Units that are reserved for a live
              order, already defective, or already on a pull-out are not listed.
            </p>
          <?php else: ?>
            <div class="ad-table-wrap" style="margin-bottom:16px;">
              <table class="ad-table">
                <thead><tr>
                  <th style="width:34px;"><input type="checkbox" id="dpAll" aria-label="Select all units"></th>
                  <th>Serial</th><th>IMEI</th><th>Status</th><th>Came from</th><th>Sold to</th>
                </tr></thead>
                <tbody>
                  <?php foreach ($pickUnits as $u): ?>
                    <tr>
                      <td><input type="checkbox" class="dp-unit" name="unit_ids[]" value="<?= (int)$u['unit_id'] ?>"
                                 aria-label="Select <?= htmlspecialchars((string)$u['serial_number']) ?>"></td>
                      <td class="dp-mono" style="font-weight:600;"><?= htmlspecialchars((string)$u['serial_number']) ?></td>
                      <td class="dp-mono"><?= $u['imei'] ? htmlspecialchars((string)$u['imei']) : '—' ?></td>
                      <td><span class="ad-chip <?= dp_chip((string)$u['status']) ?>"><?= htmlspecialchars(pu_status_label((string)$u['status'])) ?></span></td>
                      <td style="font-size:12px;">
                        <?php if (!empty($u['dr_number'])): ?>
                          <?= htmlspecialchars((string)($u['supplier_name'] ?? 'supplier')) ?>
                          &middot; <?= htmlspecialchars((string)$u['dr_number']) ?>
                        <?php else: ?>&mdash;<?php endif; ?>
                      </td>
                      <td style="font-size:12px;">
                        <?php if (!empty($u['invoice_no'])): ?>
                          <?= htmlspecialchars(trim((string)$u['customer_name']) ?: 'customer') ?>
                          &middot; <?= htmlspecialchars((string)$u['invoice_no']) ?>
                        <?php else: ?>&mdash;<?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="dp-grid">
              <div>
                <label class="ad-label" for="dp_source">Where was the defect found? *</label>
                <select name="source" id="dp_source" required>
                  <option value="">&mdash; choose &mdash;</option>
                  <?php foreach (INV_PULLOUT_SOURCES as $k => $label): ?>
                    <option value="<?= htmlspecialchars($k) ?>" <?= $prefillSource === $k ? 'selected' : '' ?>>
                      <?= htmlspecialchars($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="ad-hint">
                  A <strong>Customer return</strong> is checked against the order record &mdash; a unit
                  that was never sold is refused.
                </p>
              </div>
              <div>
                <label class="ad-label" for="dp_reason">Defect / reason *</label>
                <textarea name="reason" id="dp_reason" rows="4" required
                          placeholder="e.g. Screen has dead pixels out of the box; refuses to power on."></textarea>
              </div>
            </div>

            <div style="margin-top:16px;">
              <button type="submit" class="ad-btn ad-btn--primary" <?= $actionsDisabled ? 'disabled' : '' ?>
                      data-confirm="Mark the selected units Defective and raise a pull-out request? They leave sellable stock immediately."
                      data-confirm-ok="Raise pull-out">
                Raise pull-out request
              </button>
            </div>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>

    <!-- Requests -->
    <div class="ad-card" style="margin-bottom:18px;">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Pull-out requests</h2>
        <span class="ad-card-note">Pending first</span>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead><tr><th>Reference</th><th>Raised</th><th>Source</th><th style="text-align:right;">Units</th>
            <th>Status</th><th>Final action</th><th>Raised by</th><th>Decided by</th></tr></thead>
          <tbody>
            <?php if (!$myRequests): ?>
              <tr><td colspan="8" class="ad-table-empty">No pull-out requests yet.</td></tr>
            <?php else: foreach ($myRequests as $r): ?>
              <tr>
                <td class="dp-mono" style="font-weight:600;"><?= htmlspecialchars((string)$r['reference_no']) ?></td>
                <td><?= htmlspecialchars(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars(INV_PULLOUT_SOURCES[(string)$r['source']] ?? (string)$r['source']) ?></td>
                <td style="text-align:right;"><?= (int)$r['unit_count'] ?></td>
                <td><span class="ad-chip <?= dp_req_chip((string)$r['status']) ?>"><?= htmlspecialchars(ucfirst((string)$r['status'])) ?></span></td>
                <td style="font-size:12px;">
                  <?= $r['final_action']
                      ? htmlspecialchars(INV_FINAL_ACTIONS[(string)$r['final_action']] ?? (string)$r['final_action'])
                      : ((string)$r['status'] === 'approved' ? '<em>awaiting the Owner</em>' : '&mdash;') ?>
                </td>
                <td style="font-size:12px;"><?= htmlspecialchars(trim((string)$r['requested_by_name']) ?: '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars(trim((string)$r['reviewed_by_name']) ?: '—') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Defective on hand -->
    <div class="ad-card">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Defective units on hand</h2>
        <span class="ad-card-note"><?= count($defectiveUnits) ?> unit<?= count($defectiveUnits) === 1 ? '' : 's' ?>, out of sellable stock</span>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead><tr><th>Serial</th><th>Product</th><th>Brand</th><th>On request</th><th></th></tr></thead>
          <tbody>
            <?php if (!$defectiveUnits): ?>
              <tr><td colspan="5" class="ad-table-empty">No defective units on hand.</td></tr>
            <?php else: foreach ($defectiveUnits as $u): ?>
              <tr>
                <td class="dp-mono" style="font-weight:600;"><?= htmlspecialchars((string)$u['serial_number']) ?></td>
                <td><?= htmlspecialchars((string)$u['product_name']) ?></td>
                <td><?= htmlspecialchars((string)($u['brand'] ?? '—')) ?></td>
                <td style="font-size:12px;">
                  <?php if (!empty($u['reference_no'])): ?>
                    <?= htmlspecialchars((string)$u['reference_no']) ?>
                    <span class="ad-chip <?= dp_req_chip((string)$u['request_status']) ?>"><?= htmlspecialchars(ucfirst((string)$u['request_status'])) ?></span>
                  <?php else: ?>
                    <em>not on a request</em>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <?php /* The deliberate way back out of Defective - a unit
                           marked faulty by mistake, or one the Owner rejected
                           the pull-out for. Refused by the domain layer while
                           a request is still pending. */ ?>
                  <?php if (!$actionsDisabled && (empty($u['request_status']) || (string)$u['request_status'] === 'rejected')): ?>
                    <form method="POST" style="display:inline;"
                          data-confirm="Put serial <?= htmlspecialchars((string)$u['serial_number']) ?> back into sellable stock?"
                          data-confirm-ok="Return to stock">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="return_to_stock">
                      <input type="hidden" name="unit_id" value="<?= (int)$u['unit_id'] ?>">
                      <input type="hidden" name="restore_reason" value="Marked defective in error or pull-out rejected">
                      <button type="submit" class="dp-bare">Return to stock</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
(function () {
  var form = document.getElementById('dpForm');
  if (!form) { return; }
  var all = document.getElementById('dpAll');
  var count = document.getElementById('dpCount');

  function boxes() { return Array.prototype.slice.call(form.querySelectorAll('.dp-unit')); }
  function sync() {
    var n = boxes().filter(function (b) { return b.checked; }).length;
    if (count) { count.textContent = n === 0 ? 'none selected' : n + ' unit' + (n === 1 ? '' : 's') + ' selected'; }
    if (all) {
      var total = boxes().length;
      all.checked = n > 0 && n === total;
      // Mixed selection reads as indeterminate rather than as "none".
      all.indeterminate = n > 0 && n < total;
    }
  }

  if (all) {
    all.addEventListener('change', function () {
      boxes().forEach(function (b) { b.checked = all.checked; });
      sync();
    });
  }
  form.addEventListener('change', function (e) {
    if (e.target.classList.contains('dp-unit')) { sync(); }
  });

  // Caught here so the confirm dialog never fires on an empty selection.
  form.addEventListener('submit', function (e) {
    if (!boxes().some(function (b) { return b.checked; })) {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (window.uiAlert) { window.uiAlert('Select at least one unit by serial number.'); }
      else { alert('Select at least one unit by serial number.'); }
    }
  }, true);

  sync();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
