<?php
declare(strict_types=1);
/**
 * admin/delivery_note_print.php - printable delivery paperwork.
 *
 * Two views off the same data, chosen by ?view= (default: table for several
 * orders, note for one):
 *
 *   table - every selected order as one ROW of a single continuous table.
 *           This is what bulk printing wants: fifteen orders should be a
 *           two-page list you can read down, not fifteen separate sheets.
 *   note  - the full per-order Delivery Note document, one order per sheet.
 *           Right for handing a customer their copy.
 *
 * Nine fields the template asks for do not exist in this schema
 * (orders.subtotal / discount_amount / shipping_fee / amount_paid /
 * payment_due_date, order_items.tax_rate / discount_rate, products.sku, and
 * any business-details table). None warranted an ALTER: each is derived or
 * falls back below, and the derivations are marked where they happen.
 */

if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/business_info.php';

// Same gate as every other page in this folder. Drivers are excluded: a
// courier confirms deliveries, office staff issue the paperwork.
require_role(['admin', 'super-admin']);

$biz = business_info();

/** Money, the way the rest of the app writes it. */
function dn_money(float $n): string
{
    return formatCurrency(round($n, 2));
}

/**
 * MM.DD.YYYY per the template.
 *
 * Deliberately plain date()/strtotime(), matching the rest of this codebase:
 * PHP's configured timezone here does not agree with MySQL's, and formatting
 * straight from the stored string reproduces the true wall-clock value. See
 * the longer note in api/get_driver_location.php.
 */
function dn_date(?string $raw): string
{
    if (!$raw) { return '—'; }
    $ts = strtotime($raw);
    return $ts ? date('m.d.Y', $ts) : '—';
}

function dn_or_dash(?string $v): string
{
    $v = trim((string)$v);
    return $v === '' ? '—' : $v;
}

function dn_e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------- input
// Positive integers only, deduplicated, capped. Anything else is dropped
// rather than trusted.
// table for several, note for one - overridable with ?view=note|table.
$view = ($_GET['view'] ?? '') === 'note' ? 'note'
      : (($_GET['view'] ?? '') === 'table' ? 'table' : '');

$raw = (string)($_GET['ids'] ?? '');
$ids = [];
foreach (explode(',', $raw) as $chunk) {
    $chunk = trim($chunk);
    if ($chunk !== '' && ctype_digit($chunk) && (int)$chunk > 0) {
        $ids[(int)$chunk] = true;
    }
}
$ids = array_keys($ids);

const DN_MAX = 50;
$tooMany = count($ids) > DN_MAX;
if ($tooMany) { $ids = array_slice($ids, 0, DN_MAX); }

$orders = [];
$itemsByOrder = [];
$paidByOrder = [];
$skipped = [];

if ($ids) {
    try {
        $pdo = getConnection();
        $ph = implode(',', array_fill(0, count($ids), '?'));

        /* Status is re-checked here, never trusted from the caller.
           -----------------------------------------------------------------
           It used to be 'completed' alone, which made the note a receipt for
           a trip already made. Now that the sheet carries the rider's
           navigation block it has to exist BEFORE the trip, or the one thing
           it is for cannot happen - the rider would be handed the directions
           to a door they already found.

           'ready' and 'shipped' are the states where a delivery is actually
           in flight: picked and waiting for a rider, or out with one.
           'completed' stays, because the signed note is still the proof of
           handover it always was.

           Deliberately NOT opened further: an awaiting_payment or preparing
           order has nothing to deliver yet, and a cancelled one must never
           produce paperwork that looks like a live drop. */
        $dnPrintable = ['ready', 'shipped', 'completed'];
        $sph = implode(',', array_fill(0, count($dnPrintable), '?'));
        $stmt = $pdo->prepare("
            SELECT o.*,
                   u.firstname, u.lastname, u.email AS user_email,
                   u.phone AS user_phone, u.address AS user_address
            FROM orders o
            JOIN users u ON u.id = o.user_id
            WHERE o.id IN ($ph) AND o.status IN ($sph)
            ORDER BY o.created_at DESC
        ");
        $stmt->execute(array_merge($ids, $dnPrintable));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orders[(int)$row['id']] = $row;
        }
        $skipped = array_values(array_diff($ids, array_keys($orders)));

        if ($orders) {
            $oph = implode(',', array_fill(0, count($orders), '?'));
            $keys = array_keys($orders);

            // One query for every line on every document - not one per order.
            // LEFT JOIN so a since-deleted product still prints its line.
            $it = $pdo->prepare("
                SELECT oi.order_id, oi.quantity, oi.price, oi.total, p.name AS product_name
                FROM order_items oi
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id IN ($oph)
                ORDER BY oi.id ASC
            ");
            $it->execute($keys);
            foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $itemsByOrder[(int)$line['order_id']][] = $line;
            }

            // orders.amount_paid does not exist; settled payments are the
            // source, with the order's own paid flag as the fallback for
            // orders marked paid without a payments row (older COD records).
            $pay = $pdo->prepare("
                SELECT order_id, COALESCE(SUM(amount), 0) AS paid
                FROM payments
                WHERE order_id IN ($oph) AND payment_status = 'completed'
                GROUP BY order_id
            ");
            $pay->execute($keys);
            foreach ($pay->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $paidByOrder[(int)$r['order_id']] = (float)$r['paid'];
            }
        }
    } catch (Throwable $e) {
        error_log('delivery_note_print: ' . $e->getMessage());
        $orders = [];
    }
}

if ($view === '') { $view = count($orders) > 1 ? 'table' : 'note'; }
$pageTitle = ($view === 'table' ? 'Delivery Report' : 'Delivery Note') . ' - TechWorld';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= dn_e($pageTitle) ?></title>
<link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
<link rel="stylesheet" href="<?= asset_url('public/shared/css/delivery-note.css') ?>">
</head>
<body class="dn-body">

<div class="dn-toolbar">
  <button type="button" class="dn-print" onclick="window.print();">Print</button>
  <a class="dn-back" href="<?= WEB_ROOT ?>public/authenticated/admin/Delivery_Report.php">Back to Delivery Report</a>
  <?php if (count($orders) > 1): ?>
    <a class="dn-back" href="?ids=<?= dn_e(implode(',', array_keys($orders))) ?>&amp;view=<?= $view === 'table' ? 'note' : 'table' ?>">
      <?= $view === 'table' ? 'Switch to one note per page' : 'Switch to list view' ?>
    </a>
  <?php endif; ?>
  <span class="dn-note">
    <?= count($orders) ?> delivery note<?= count($orders) === 1 ? '' : 's' ?>
    <?php if ($skipped): ?>
      &middot; skipped <?= count($skipped) ?> (not completed or not found): <?= dn_e(implode(', ', $skipped)) ?>
    <?php endif; ?>
    <?php if ($tooMany): ?>
      &middot; limited to <?= DN_MAX ?> per print
    <?php endif; ?>
  </span>
</div>

<?php if (!$orders): ?>
  <div class="dn-empty">
    <h2 style="margin:0 0 8px;">Nothing to print</h2>
    <p style="margin:0 0 12px;">
      <?php if (!$ids): ?>
        No order was selected.
      <?php else: ?>
        None of the selected orders could be printed. A delivery note is issued
        once an order is <strong>ready</strong>, <strong>shipped</strong> or
        <strong>completed</strong> &mdash; before that there is nothing to send out,
        and a cancelled order never gets one.
      <?php endif; ?>
    </p>
    <a href="<?= WEB_ROOT ?>public/authenticated/admin/Delivery_Report.php">Back to Delivery Report</a>
  </div>
<?php else: ?>

<?php if ($view === 'table'): ?>
<?php
// One continuous table. Totals accumulate as the rows are written so the
// summary at the foot is the same arithmetic the rows show.
$sumTotal = 0.0; $sumPaid = 0.0; $sumQty = 0;
?>
<article class="dn-doc dn-doc--table">
  <header class="dn-head">
    <?php
    $logoFs = ($_SERVER['DOCUMENT_ROOT'] ?? '') . WEB_ROOT . $biz['logo'];
    if ($biz['logo'] && @is_file($logoFs)): ?>
      <img class="dn-logo" src="<?= WEB_ROOT . dn_e($biz['logo']) ?>" alt="<?= dn_e($biz['name']) ?>">
    <?php else: ?>
      <div class="dn-logo-text"><?= dn_e($biz['name']) ?></div>
    <?php endif; ?>
    <div class="dn-head-right">
      <h1 class="dn-title">Delivery Report</h1>
      <dl class="dn-meta">
        <dt>Deliveries:</dt><dd><?= count($orders) ?></dd>
        <dt>Printed:</dt><dd><?= dn_date(date('Y-m-d H:i:s')) ?></dd>
      </dl>
    </div>
  </header>

  <table class="dn-list">
    <thead>
      <tr>
        <th class="dn-w-doc">Document</th>
        <th class="dn-w-date">Delivered</th>
        <th class="dn-w-cust">Customer</th>
        <th class="dn-w-ship">Ship to</th>
        <th class="dn-w-items">Items</th>
        <th class="dn-c dn-w-qty">Qty</th>
        <th class="dn-r dn-w-amt">Total</th>
        <th class="dn-r dn-w-amt">Paid</th>
        <th class="dn-r dn-w-amt">Balance</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $oid => $o):
        $items = $itemsByOrder[$oid] ?? [];
        $isPickup = ($o['delivery_method'] ?? '') !== 'delivery';
        $total = (float)$o['total_amount'];
        $paid  = $paidByOrder[$oid] ?? 0.0;
        if ($paid <= 0 && ($o['payment_status'] ?? '') === 'paid') { $paid = $total; }
        $balance = max(0.0, $total - $paid);
        $qty = 0;
        foreach ($items as $l) { $qty += (int)$l['quantity']; }

        $sumTotal += $total; $sumPaid += $paid; $sumQty += $qty;

        $docNo = ($o['invoice_no'] ?? '') !== ''
            ? $o['invoice_no'] : 'DN-' . str_pad((string)$oid, 6, '0', STR_PAD_LEFT);
        $docDate = $isPickup
            ? ($o['pickup_verified_at'] ?? $o['updated_at'] ?? null)
            : ($o['delivery_completed_at'] ?? $o['updated_at'] ?? null);
        $shipTo = $isPickup ? 'Store Pickup' : dn_or_dash($o['delivery_full_address'] ?? '');
        $cust = trim(($o['firstname'] ?? '') . ' ' . ($o['lastname'] ?? ''));
    ?>
      <tr>
        <td class="dn-w-doc">
          <strong><?= dn_e($docNo) ?></strong>
          <span class="dn-sub"><?= dn_e($isPickup ? dn_or_dash($o['pickup_code'] ?? '') : dn_or_dash($o['tracking_code'] ?? '')) ?></span>
        </td>
        <td class="dn-w-date"><?= dn_date($docDate) ?></td>
        <td class="dn-w-cust">
          <?= dn_e($cust !== '' ? $cust : 'Customer') ?>
          <span class="dn-sub"><?= dn_e(dn_or_dash($o['user_email'] ?? '')) ?></span>
        </td>
        <td class="dn-w-ship"><?= dn_e($shipTo) ?></td>
        <td class="dn-w-items">
          <?php if (!$items): ?>
            &mdash;
          <?php else: foreach ($items as $l): ?>
            <span class="dn-item-line">
              <?= dn_e(($l['product_name'] ?? '') !== '' ? $l['product_name'] : 'Product unavailable') ?>
              &times;<?= (int)$l['quantity'] ?>
            </span>
          <?php endforeach; endif; ?>
        </td>
        <td class="dn-c dn-w-qty"><?= $qty ?></td>
        <td class="dn-r dn-w-amt"><?= dn_e(dn_money($total)) ?></td>
        <td class="dn-r dn-w-amt"><?= dn_e(dn_money($paid)) ?></td>
        <td class="dn-r dn-w-amt"><?= dn_e(dn_money($balance)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="5">Total &mdash; <?= count($orders) ?> deliveries</th>
        <th class="dn-c"><?= $sumQty ?></th>
        <th class="dn-r"><?= dn_e(dn_money($sumTotal)) ?></th>
        <th class="dn-r"><?= dn_e(dn_money($sumPaid)) ?></th>
        <th class="dn-r"><?= dn_e(dn_money(max(0.0, $sumTotal - $sumPaid))) ?></th>
      </tr>
    </tfoot>
  </table>

  <section class="dn-signs">
    <div class="dn-sign">
      <div class="dn-rule">
        <div class="dn-who">&nbsp;</div>
        <div class="dn-role">Received / checked by</div>
      </div>
    </div>
    <div class="dn-sign">
      <div class="dn-rule">
        <div class="dn-who"><?= dn_e($biz['owner']) ?></div>
        <div class="dn-role">Business signature</div>
      </div>
    </div>
  </section>
</article>

<?php else: ?>
<?php foreach ($orders as $oid => $o):
    $items = $itemsByOrder[$oid] ?? [];
    $isPickup = ($o['delivery_method'] ?? '') !== 'delivery';

    // Subtotal is not stored. order_items.total already holds the line
    // amount, and with no per-line tax or discount in this schema the
    // template's line formula reduces to it.
    $subtotal = 0.0;
    foreach ($items as $l) { $subtotal += (float)$l['total']; }

    $orderDiscount = 0.0;   // no orders.discount_amount in this schema
    $shippingCost  = 0.0;   // no orders.shipping_fee in this schema
    $total         = (float)$o['total_amount'];

    // The order's own total is authoritative; a drift means the lines and the
    // header disagree, which is worth knowing about but not worth changing
    // the printed figure over.
    if (abs($subtotal - ($total + $orderDiscount - $shippingCost)) > 0.01) {
        error_log(sprintf(
            'delivery_note_print: order %d line total %.2f differs from orders.total_amount %.2f',
            $oid, $subtotal, $total
        ));
    }

    $paid = $paidByOrder[$oid] ?? 0.0;
    if ($paid <= 0 && ($o['payment_status'] ?? '') === 'paid') { $paid = $total; }
    $balance = max(0.0, $total - $paid);

    $clientName = trim(($o['firstname'] ?? '') . ' ' . ($o['lastname'] ?? ''));
    $docNo = ($o['invoice_no'] ?? '') !== ''
        ? $o['invoice_no']
        : 'DN-' . str_pad((string)$oid, 6, '0', STR_PAD_LEFT);
    $docDate = $isPickup
        ? ($o['pickup_verified_at'] ?? $o['updated_at'] ?? null)
        : ($o['delivery_completed_at'] ?? $o['updated_at'] ?? null);
    $shipTo = $isPickup
        ? 'Store Pickup'
        : dn_or_dash($o['delivery_full_address'] ?? '');
    $tracking = $isPickup
        ? dn_or_dash($o['pickup_code'] ?? '')
        : dn_or_dash($o['tracking_code'] ?? '');

    $payLabel = $o['payment_method_display'] ?? $o['payment_method'] ?? '';
    $payRef   = trim((string)($o['payment_reference'] ?? ''));
?>
<article class="dn-doc">

  <header class="dn-head">
    <?php
    $logoFs = ($_SERVER['DOCUMENT_ROOT'] ?? '') . WEB_ROOT . $biz['logo'];
    if ($biz['logo'] && @is_file($logoFs)): ?>
      <img class="dn-logo" src="<?= WEB_ROOT . dn_e($biz['logo']) ?>" alt="<?= dn_e($biz['name']) ?>">
    <?php else: ?>
      <div class="dn-logo-text"><?= dn_e($biz['name']) ?></div>
    <?php endif; ?>

    <div class="dn-head-right">
      <h1 class="dn-title">Delivery Note</h1>
      <dl class="dn-meta">
        <dt>Challan no.</dt><dd><?= dn_e($docNo) ?></dd>
        <dt>Challan date:</dt><dd><?= dn_date($docDate) ?></dd>
        <?php // no orders.payment_due_date in this schema ?>
        <dt>Due:</dt><dd>&mdash;</dd>
      </dl>
    </div>
  </header>

  <section class="dn-parties">
    <div class="dn-party">
      <h2>From</h2>
      <div class="dn-name"><?= dn_e($biz['name']) ?></div>
      <p><?= dn_e($biz['owner']) ?></p>
      <p><?= dn_e($biz['email']) ?></p>
      <p><?= dn_e($biz['phone']) ?></p>
      <p><?= dn_e($biz['website']) ?></p>
      <p><?= dn_e($biz['address']) ?></p>
    </div>

    <div class="dn-party dn-right">
      <h2>Bill to</h2>
      <div class="dn-name"><?= dn_e($clientName !== '' ? $clientName : 'Customer') ?></div>
      <p><?= dn_e(dn_or_dash($o['user_email'] ?? '')) ?></p>
      <p><?= dn_e(dn_or_dash($o['delivery_phone'] ?? $o['user_phone'] ?? '')) ?></p>
      <p><?= dn_e(dn_or_dash($o['user_address'] ?? '')) ?></p>

      <div class="dn-ship">
        <h2>Ship to</h2>
        <p><?= dn_e($shipTo) ?></p>
        <p>Tracking #: <?= dn_e($tracking) ?></p>
      </div>
    </div>
  </section>

  <table class="dn-items">
    <thead>
      <tr>
        <th class="dn-desc">Description</th>
        <th class="dn-r">Rate</th>
        <th class="dn-c">Qty</th>
        <th class="dn-r">Tax, %</th>
        <th class="dn-r">Disc, %</th>
        <th class="dn-r">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="6">No line items recorded for this order.</td></tr>
      <?php else: foreach ($items as $l): ?>
        <tr>
          <td class="dn-desc">
            <?= dn_e($l['product_name'] ?? '') !== '' ? dn_e($l['product_name']) : 'Product unavailable' ?>
          </td>
          <td class="dn-r"><?= dn_e(dn_money((float)$l['price'])) ?></td>
          <td class="dn-c"><?= (int)$l['quantity'] ?></td>
          <?php // per-line tax and discount are not captured in this schema ?>
          <td class="dn-r">0.00</td>
          <td class="dn-r">0.00</td>
          <td class="dn-r"><?= dn_e(dn_money((float)$l['total'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <section class="dn-lower">
    <div class="dn-pay">
      <h2>Payment instruction</h2>
      <p><?= dn_e($payLabel !== '' ? strtoupper($payLabel) : 'Not recorded') ?></p>
      <?php if ($payRef !== ''): ?>
        <p>Reference: <?= dn_e($payRef) ?></p>
      <?php endif; ?>
    </div>

    <table class="dn-totals">
      <tr class="dn-strong"><th>Subtotal:</th><td><?= dn_e(dn_money($subtotal)) ?></td></tr>
      <tr><th>Discount:</th><td><?= dn_e(dn_money($orderDiscount)) ?></td></tr>
      <tr><th>Shipping Cost:</th><td><?= dn_e(dn_money($shippingCost)) ?></td></tr>
      <tr class="dn-strong"><th>Total:</th><td><?= dn_e(dn_money($total)) ?></td></tr>
      <tr><th>Amount paid:</th><td><?= dn_e(dn_money($paid)) ?></td></tr>
      <tr class="dn-due"><th>Balance Due:</th><td><?= dn_e(dn_money($balance)) ?></td></tr>
    </table>
  </section>

  <?php
    /* Rider navigation.
       -----------------------------------------------------------------
       This block used to be a bare "Notes:" heading over orders.notes, which
       meant the one piece of information a rider actually needs to find the
       door was printed without saying what it was for.

       The text is the customer's own, typed at checkout (checkout.php, "Order
       notes", optional) - nobody re-keys it here. A street address alone does
       not resolve a condo tower and unit, a gate in a compound, or an unnamed
       side street, so this is where that detail lands.

       The map pin is printed beside it when the customer dropped one
       (orders.delivery_latitude/longitude, added by DBSQL/add_address_geo_pin
       .sql). Coordinates beat address text whenever they disagree, which is
       the same reason dispatch.php prefers them for its Navigate link.

       Pickup orders have no route, so the whole block is skipped for them
       rather than printed empty. */
    $navNote = trim((string)($o['notes'] ?? ''));
    $navLat  = $o['delivery_latitude']  ?? null;
    $navLng  = $o['delivery_longitude'] ?? null;
    $hasPin  = ($navLat !== null && $navLat !== '' && $navLng !== null && $navLng !== '');
  ?>
  <?php if (!$isPickup): ?>
  <section class="dn-notes dn-nav">
    <h2>Delivery instructions &mdash; for the rider</h2>

    <div class="dn-nav-grid">
      <div class="dn-nav-cell">
        <span class="dn-nav-label">Deliver to</span>
        <p><?= dn_e(dn_or_dash($o['delivery_recipient_name'] ?? $clientName)) ?></p>
        <p><?= dn_e(dn_or_dash($o['delivery_phone'] ?? $o['user_phone'] ?? '')) ?></p>
      </div>
      <div class="dn-nav-cell">
        <span class="dn-nav-label">Address</span>
        <p><?= dn_e($shipTo) ?></p>
      </div>
      <div class="dn-nav-cell">
        <span class="dn-nav-label">Map pin</span>
        <?php if ($hasPin): ?>
          <p><?= dn_e(number_format((float)$navLat, 6)) ?>, <?= dn_e(number_format((float)$navLng, 6)) ?></p>
        <?php else: ?>
          <p class="dn-nav-none">Not pinned &mdash; navigate by the address above.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="dn-nav-note">
      <span class="dn-nav-label">Location details from the customer</span>
      <?php if ($navNote !== ''): ?>
        <p class="dn-nav-text"><?= dn_e($navNote) ?></p>
      <?php else: ?>
        <?php /* Said plainly rather than left as an em dash. A rider reading a
                 blank line cannot tell whether the customer gave no detail or
                 whether the sheet simply failed to print it, and those call for
                 different actions - one is "ring the bell", the other is "ring
                 the office". */ ?>
        <p class="dn-nav-none">The customer did not add any. If the address does not resolve on arrival, call the number above before leaving.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php else: ?>
  <section class="dn-notes">
    <h2>Notes</h2>
    <p><?= $navNote !== '' ? nl2br(dn_e($navNote)) : '&mdash;' ?></p>
  </section>
  <?php endif; ?>

  <section class="dn-signs">
    <div class="dn-sign">
      <div class="dn-rule">
        <div class="dn-who"><?= dn_e($clientName !== '' ? $clientName : 'Customer') ?></div>
        <div class="dn-role">Client signature</div>
      </div>
    </div>
    <div class="dn-sign">
      <div class="dn-rule">
        <div class="dn-who"><?= dn_e($biz['owner']) ?></div>
        <div class="dn-role">Business signature</div>
      </div>
    </div>
  </section>

</article>
<?php endforeach; ?>
<?php endif; ?>

<script>
// Print once everything - the logo included - has actually rendered, so the
// dialog never captures a half-drawn sheet.
window.addEventListener('load', function () {
  setTimeout(function () { window.print(); }, 250);
});
</script>
<?php endif; ?>

</body>
</html>
