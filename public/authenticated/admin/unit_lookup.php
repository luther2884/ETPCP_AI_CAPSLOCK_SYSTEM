<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/product_units.php';
// For os_label() on the order-status row. functions.php does not pull this in,
// so without it the status would render as the raw enum value.
require_once __DIR__ . '/../../../config/order_status.php';

require_role(['admin']);

/**
 * admin/unit_lookup.php - the returns counter.
 *
 * Someone walks in with a laptop and a complaint. Staff type the serial off
 * its underside (or its IMEI) and this page answers the only question that
 * matters: did WE sell THIS unit to THIS person, and when?
 *
 * It is deliberately one screen for two jobs the panel asked for separately -
 * "view/search units by serial or IMEI" and "a returns lookup" - because they
 * are the same keystrokes. Splitting them would mean staff searching twice.
 *
 * WHY THE ANSWER IS A REFUSAL AS OFTEN AS AN ACCEPTANCE
 *
 * The verification rules live in pu_accept_return(); this page only renders
 * what they decide. The four refusals it can report are all real counter
 * situations:
 *
 *   not on file      never passed through this shop, or predates unit tracking
 *   still in stock   recorded as on our own shelf - so it was not sold, and
 *                    the likeliest explanation is that it walked out unpaid
 *   reserved         promised to an order nobody has collected yet
 *   wrong customer   sold, but to somebody else
 *
 * A returned unit does NOT go back into sellable stock. 'returned' and
 * 'defective' both sit outside the in-stock count, so the mirror drops it and
 * the storefront stops offering it - nothing here inspects a unit, and
 * promising an uninspected gadget to the next customer is how this feature
 * would create the problem it was built to solve.
 */

$pageTitle = 'Serial / IMEI Lookup - TechWorld Admin';
$activeNav = 'units';
$isAdminTheme = true;

$pdo = getConnection();

$currentUserStatus = 'active';
try {
    $st = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
    $st->execute([getUserId()]);
    $row = $st->fetch();
    if ($row) { $currentUserStatus = (string)($row['status'] ?? 'active'); }
} catch (Throwable $e) { /* assume active */ }
$actionsDisabled = (!isSuperAdmin() && $currentUserStatus !== 'active');

$flash = $_SESSION['pul_flash'] ?? null;
unset($_SESSION['pul_flash']);

// ----------------------------------------------------------------- accept ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));

    if (!check_csrf()) {
        $_SESSION['pul_flash'] = ['ok' => false, 'message' => 'Your session expired. Search again.'];
        header('Location: ?');
        exit;
    }
    if ($actionsDisabled) {
        $_SESSION['pul_flash'] = ['ok' => false, 'message' => 'Your admin account is awaiting Super Admin approval.'];
        header('Location: ?q=' . urlencode($identifier));
        exit;
    }

    $newStatus = (string)($_POST['new_status'] ?? '');
    // The customer the screen showed when staff pressed the button. Posted back
    // and re-verified inside pu_accept_return() rather than trusted: the point
    // is to catch the unit's ownership having changed between the lookup and
    // the acceptance, and a value this page merely echoes proves nothing on
    // its own.
    $expectedCustomerId = (int)($_POST['expected_customer_id'] ?? 0);

    if (empty($_POST['identity_verified'])) {
        $_SESSION['pul_flash'] = ['ok' => false, 'message' => 'Confirm you have checked the customer\'s ID before accepting the return.'];
        header('Location: ?q=' . urlencode($identifier));
        exit;
    }

    $result = pu_accept_return($pdo, $identifier, $newStatus, $expectedCustomerId);

    if ($result['ok']) {
        logAudit('unit_return_accepted', 'product_units',
            (int)($result['unit']['unit_id'] ?? 0), null, [
                'serial_number' => $result['unit']['serial_number'] ?? '',
                'new_status'    => $newStatus,
                'order_id'      => $result['unit']['order_id'] ?? null,
                'customer_id'   => $result['unit']['customer_id'] ?? null,
            ]);
    } else {
        // A refused return is the interesting audit line, not the noise: it is
        // the record that somebody presented a unit this shop could not match.
        logAuditFailure('unit_return_refused', 'product_units', null,
            $result['message'], null, ['identifier' => $identifier]);
    }

    $_SESSION['pul_flash'] = ['ok' => $result['ok'], 'message' => $result['message']];
    header('Location: ?q=' . urlencode($identifier));
    exit;
}

// ----------------------------------------------------------------- search ----
$query = trim((string)($_GET['q'] ?? ''));
$unit = null;
$partials = [];
$searched = ($query !== '');

if ($searched) {
    try {
        $unit = pu_find_unit($pdo, $query);

        // No exact hit: offer near misses rather than a dead end. Staff
        // mistype long serials, and a partial match is almost always the unit
        // they meant. Only when there is no exact match, so a successful
        // lookup is never cluttered by a list.
        if (!$unit) {
            $like = '%' . $query . '%';
            $pStmt = $pdo->prepare(
                "SELECT pu.unit_id, pu.serial_number, pu.imei, pu.status, p.name AS product_name
                   FROM product_units pu
                   JOIN products p ON p.id = pu.product_id
                  WHERE pu.serial_number LIKE ? OR pu.imei LIKE ?
                  ORDER BY pu.serial_number
                  LIMIT 25"
            );
            $pStmt->execute([$like, $like]);
            $partials = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('unit_lookup search error: ' . $e->getMessage());
    }
}

/** Status chip class, same mapping as the units page. */
function pul_chip(string $status): string
{
    switch ($status) {
        case 'in_stock':  return 'ad-chip--active';
        case 'reserved':  return 'ad-chip--reorder';
        case 'sold':      return 'ad-chip--inactive';
        case 'defective': return 'ad-chip--oversold';
        default:          return 'ad-chip--out';
    }
}

// Can this unit be taken back at all? Mirrors pu_accept_return()'s own gate, so
// the form is only offered when pressing the button could actually work.
$returnable = $unit && $unit['status'] === 'sold' && !empty($unit['order_id']);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    html body.admin main.container{max-width:min(1100px,96vw);}
    .pul-search{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .pul-search input[type="search"]{flex:1;min-width:240px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;}
    .pul-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;}
    .pul-msg{border-radius:8px;padding:12px 14px;margin:0 0 16px;font-size:14px;line-height:1.55;}
    .pul-msg--ok{background:#e7f6ef;color:#1f7a53;border:1px solid #b6e3ce;}
    .pul-msg--no{background:#fdecf1;color:#a8264d;border:1px solid #f4c4d4;}
    .pul-dl{display:grid;grid-template-columns:170px 1fr;gap:10px 18px;margin:0;font-size:14px;}
    .pul-dl dt{color:var(--ad-muted);font-weight:600;}
    .pul-dl dd{margin:0;color:var(--ad-ink);font-weight:600;}
    @media (max-width:620px){.pul-dl{grid-template-columns:1fr;gap:2px 0;}.pul-dl dd{margin-bottom:10px;}}
    .pul-verdict{border-radius:8px;padding:14px;margin:0 0 16px;font-size:14px;line-height:1.55;}
    .pul-verdict--yes{background:#e7f6ef;color:#1f7a53;border:1px solid #b6e3ce;}
    .pul-verdict--no{background:#fff4e0;color:#8a5a00;border:1px solid #f0d9a8;}
  </style>

  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/product_management.php">Products</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php">Unit Tracking</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Serial / IMEI Lookup</span>
      </nav>
    </div>
  </div>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">

    <h1 style="margin:0 0 6px;color:var(--admin-text);">Serial / IMEI Lookup</h1>
    <p class="ad-hint" style="margin:0 0 18px;max-width:74ch;">
      Type the serial number or IMEI from the unit in front of you. This shows what it is, who bought it,
      on which order and when &mdash; and whether a return can be accepted.
    </p>

    <?php if ($flash): ?>
      <div class="pul-msg pul-msg--<?= !empty($flash['ok']) ? 'ok' : 'no' ?>">
        <?= htmlspecialchars((string)($flash['message'] ?? '')) ?>
      </div>
    <?php endif; ?>

    <div class="ad-card" style="margin-bottom:18px;">
      <div class="ad-card-body ad-field">
        <form method="GET" class="pul-search">
          <input type="search" name="q" value="<?= htmlspecialchars($query) ?>" autofocus
                 autocomplete="off" spellcheck="false"
                 placeholder="Serial number or 15-digit IMEI">
          <button type="submit" class="ad-btn ad-btn--primary">Look up</button>
          <?php if ($searched): ?><a class="ad-btn ad-btn--secondary" href="?">Clear</a><?php endif; ?>
        </form>
      </div>
    </div>

    <?php if ($searched && !$unit): ?>
      <div class="pul-verdict pul-verdict--no">
        <strong>No unit on file with that serial number or IMEI.</strong><br>
        Either it did not come from this shop, or it was sold before unit tracking was switched on for
        its product. Do not accept it as a verified return on this basis alone &mdash; check the
        customer's order history instead.
      </div>

      <?php if ($partials): ?>
        <div class="ad-card">
          <div class="ad-card-head">
            <h2 class="ad-card-title">Close matches</h2>
            <span class="ad-card-note">Serials containing &ldquo;<?= htmlspecialchars($query) ?>&rdquo;</span>
          </div>
          <div class="ad-table-wrap">
            <table class="ad-table">
              <thead><tr><th>Serial</th><th>IMEI</th><th>Product</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($partials as $p): ?>
                  <tr>
                    <td class="pul-mono" style="font-weight:600;"><?= htmlspecialchars((string)$p['serial_number']) ?></td>
                    <td class="pul-mono"><?= $p['imei'] ? htmlspecialchars((string)$p['imei']) : '—' ?></td>
                    <td><?= htmlspecialchars((string)$p['product_name']) ?></td>
                    <td><span class="ad-chip <?= pul_chip((string)$p['status']) ?>"><?= htmlspecialchars(pu_status_label((string)$p['status'])) ?></span></td>
                    <td style="text-align:right;">
                      <a class="ad-btn ad-btn--secondary" href="?q=<?= urlencode((string)$p['serial_number']) ?>">Open</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($unit): ?>
      <?php
        $customerName = trim((string)($unit['firstname'] ?? '') . ' ' . (string)($unit['lastname'] ?? ''));
        $orderLabel = (string)($unit['invoice_no'] ?: ($unit['order_id'] ? 'ORD-' . str_pad((string)(int)$unit['order_id'], 5, '0', STR_PAD_LEFT) : ''));
      ?>

      <!-- The verdict, before the detail: it is what staff need in the first second. -->
      <?php if ($returnable): ?>
        <div class="pul-verdict pul-verdict--yes">
          <strong>This unit was sold by us.</strong>
          Confirm the person in front of you is <strong><?= htmlspecialchars($customerName !== '' ? $customerName : 'the customer on order ' . $orderLabel) ?></strong>
          before accepting the return.
        </div>
      <?php elseif ($unit['status'] === 'in_stock'): ?>
        <div class="pul-verdict pul-verdict--no">
          <strong>Do not accept this return.</strong>
          This unit is recorded as still being in our own stock, so there is no sale behind it.
          Escalate to the Owner before taking it in.
        </div>
      <?php elseif ($unit['status'] === 'reserved'): ?>
        <div class="pul-verdict pul-verdict--no">
          <strong>Not yet handed over.</strong>
          This unit is reserved for order <?= htmlspecialchars($orderLabel !== '' ? $orderLabel : '(unknown)') ?>,
          which has not been collected or delivered, so it cannot have been returned.
        </div>
      <?php elseif (in_array((string)$unit['status'], PU_RETURN_STATUSES, true)): ?>
        <div class="pul-verdict pul-verdict--no">
          <strong>Already taken back.</strong>
          This unit is marked <?= htmlspecialchars(strtolower(pu_status_label((string)$unit['status']))) ?> and is not in sellable stock.
        </div>
      <?php else: ?>
        <div class="pul-verdict pul-verdict--no">
          <strong>Sold, but not linked to an order.</strong>
          There is no order record behind this unit, so there is nothing to verify the customer against.
        </div>
      <?php endif; ?>

      <div class="ad-card" style="margin-bottom:18px;">
        <div class="ad-card-head">
          <h2 class="ad-card-title">Unit</h2>
          <span class="ad-chip <?= pul_chip((string)$unit['status']) ?>"><?= htmlspecialchars(pu_status_label((string)$unit['status'])) ?></span>
        </div>
        <div class="ad-card-body">
          <dl class="pul-dl">
            <dt>Serial number</dt>
            <dd class="pul-mono"><?= htmlspecialchars((string)$unit['serial_number']) ?></dd>

            <dt>IMEI</dt>
            <dd class="pul-mono"><?= $unit['imei'] ? htmlspecialchars((string)$unit['imei']) : '—' ?></dd>

            <dt>Product</dt>
            <dd>
              <?= htmlspecialchars((string)$unit['product_name']) ?>
              <?php if (!empty($unit['category_name'])): ?>
                <span style="font-weight:400;color:var(--ad-muted);">&middot; <?= htmlspecialchars((string)$unit['category_name']) ?></span>
              <?php endif; ?>
            </dd>

            <dt>Customer</dt>
            <dd>
              <?php if ($customerName !== ''): ?>
                <?= htmlspecialchars($customerName) ?>
                <?php if (!empty($unit['email'])): ?>
                  <span style="font-weight:400;color:var(--ad-muted);">&middot; <?= htmlspecialchars((string)$unit['email']) ?></span>
                <?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </dd>

            <dt>Order number</dt>
            <dd><?= $orderLabel !== '' ? htmlspecialchars($orderLabel) : '—' ?></dd>

            <dt>Order placed</dt>
            <dd><?= !empty($unit['order_placed_at']) ? htmlspecialchars(date('j M Y, g:ia', strtotime((string)$unit['order_placed_at']))) : '—' ?></dd>

            <dt>Date sold</dt>
            <dd><?= !empty($unit['date_sold']) ? htmlspecialchars(date('j M Y, g:ia', strtotime((string)$unit['date_sold']))) : '—' ?></dd>

            <dt>Order status</dt>
            <dd>
              <?php if (!empty($unit['order_status'])): ?>
                <?= htmlspecialchars(function_exists('os_label')
                      ? os_label((string)$unit['order_status'], (string)($unit['delivery_method'] ?? 'delivery'))
                      : ucfirst((string)$unit['order_status'])) ?>
              <?php else: ?>—<?php endif; ?>
            </dd>

            <dt>Added to stock</dt>
            <dd style="font-weight:400;"><?= !empty($unit['date_added']) ? htmlspecialchars(date('j M Y', strtotime((string)$unit['date_added']))) : '—' ?></dd>
          </dl>

          <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
            <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php?product_id=<?= (int)$unit['product_id'] ?>">
              All units of this product
            </a>
            <?php if (!empty($unit['order_id'])): ?>
              <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/<?=
                   ((string)($unit['delivery_method'] ?? 'delivery') === 'pickup' ? 'store_pickup_orders.php' : 'Home_Delivery_Orders.php')
                 ?>">Open the order queue</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($returnable): ?>
        <div class="ad-card">
          <div class="ad-card-head">
            <h2 class="ad-card-title">Accept this return</h2>
            <span class="ad-card-note">The unit will not go back into sellable stock</span>
          </div>
          <div class="ad-card-body ad-field">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="identifier" value="<?= htmlspecialchars((string)$unit['serial_number']) ?>">
              <input type="hidden" name="expected_customer_id" value="<?= (int)($unit['customer_id'] ?? 0) ?>">

              <label class="ad-label" for="pul_status">Condition</label>
              <select name="new_status" id="pul_status" required>
                <option value="returned">Returned &mdash; back in the building, needs checking</option>
                <option value="defective">Defective &mdash; known faulty</option>
              </select>
              <p class="ad-hint">
                Either way the unit stops being offered on the site. Nothing here puts it back on the
                shelf &mdash; re-add it as a new unit once it has been checked.
              </p>

              <label style="display:flex;gap:9px;align-items:flex-start;margin-top:14px;font-size:14px;cursor:pointer;">
                <input type="checkbox" name="identity_verified" value="1" required style="margin-top:3px;">
                <span>I have checked the customer's ID and they are
                  <strong><?= htmlspecialchars($customerName !== '' ? $customerName : 'the customer on order ' . $orderLabel) ?></strong>,
                  the person this unit was sold to.</span>
              </label>

              <div style="margin-top:16px;">
                <button type="submit" class="ad-btn ad-btn--primary" <?= $actionsDisabled ? 'disabled' : '' ?>
                        data-confirm="Accept serial <?= htmlspecialchars((string)$unit['serial_number']) ?> back from <?= htmlspecialchars($customerName) ?>?"
                        data-confirm-ok="Accept return">
                  Accept return
                </button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
