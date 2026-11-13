<?php
// customer/invoice.php - Invoice page for home delivery orders
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Invoice - TechWorld';
$userId = getUserId();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/dashboard.php');
    exit;
}

try {
    $pdo = getConnection();
    
    // Get order and basic user details (include user address for fallback)
    $orderStmt = $pdo->prepare("SELECT o.*, u.firstname, u.lastname, u.email, u.phone, u.address AS user_address 
                                FROM orders o 
                                JOIN users u ON u.id = o.user_id 
                                WHERE o.id = ? AND o.user_id = ?");
    $orderStmt->execute([$orderId, $userId]);
    $order = $orderStmt->fetch();
    
    if (!$order) {
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/dashboard.php');
        exit;
    }
    
    // Only show invoice for completed home delivery orders
    if (($order['delivery_method'] ?? 'pickup') !== 'delivery' || ($order['status'] ?? '') !== 'completed') {
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/order_confirmation.php?id=' . $orderId);
        exit;
    }

    // Cash on Delivery receipt detail - the driver records the exact cash
    // tendered and any change given back in Delivery_Report.php, stored as
    // JSON in this payment's notes (payments.amount itself stays the order
    // total/actual revenue, not the raw cash handed over).
    $codReceipt = null;
    if (($order['payment_method'] ?? '') === 'cod' && ($order['payment_status'] ?? '') === 'paid') {
        $payStmt = $pdo->prepare("SELECT amount, notes, payment_date FROM payments WHERE order_id = ? AND payment_status = 'completed' ORDER BY id DESC LIMIT 1");
        $payStmt->execute([$orderId]);
        $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);
        if ($payRow) {
            $notesData = json_decode((string)($payRow['notes'] ?? ''), true);
            if (is_array($notesData) && isset($notesData['amount_tendered'])) {
                $codReceipt = [
                    'amount_tendered' => (float)$notesData['amount_tendered'],
                    'change_given' => (float)($notesData['change_given'] ?? 0),
                    'payment_date' => $payRow['payment_date'],
                ];
            }
        }
    }

    // Get order items
    $itemsStmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url 
                                FROM order_items oi 
                                JOIN products p ON p.id = oi.product_id 
                                WHERE oi.order_id = ?");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll();

    // Serial numbers of the physical units this order went out with, so the
    // customer's own copy of the invoice records which unit they received.
    // Without it the only record is in the shop's database, and a customer
    // bringing a laptop back a week later has nothing in hand to match it to.
    //
    // Reserved and sold units only - a unit already taken back on a return is
    // no longer what the customer has, and reprinting it here would be wrong.
    $itemSerials = [];
    try {
        foreach (pu_units_for_order($pdo, $orderId) as $itemId => $units) {
            foreach ($units as $u) {
                if (in_array((string)$u['status'], ['reserved', 'sold'], true)) {
                    $itemSerials[(int)$itemId][] = (string)$u['serial_number'];
                }
            }
        }
    } catch (Throwable $e) {
        // Never fatal: an invoice without serials is still a valid invoice.
        error_log('invoice serials for order ' . $orderId . ': ' . $e->getMessage());
    }
    
    // Resolve billing/delivery address - prefer the snapshot taken at
    // checkout time (delivery_recipient_name/delivery_phone/
    // delivery_full_address), which is what the customer actually picked
    // for THIS order. Only orders placed before that snapshot existed
    // (columns NULL) fall back to the customer's current saved address -
    // best effort, may not match what was actually chosen back then.
    if (!empty($order['delivery_full_address'])) {
        $billName = trim($order['delivery_recipient_name'] ?: ($order['firstname'] . ' ' . $order['lastname']));
        $billPhone = $order['delivery_phone'] ?: ($order['phone'] ?? '');
        $billAddress = $order['delivery_full_address'];
    } else {
        $addrStmt = $pdo->prepare("
            SELECT full_name, phone, region, postal, street, is_default
            FROM user_addresses
            WHERE user_id = ?
            ORDER BY is_default DESC, id DESC
            LIMIT 1
        ");
        $addrStmt->execute([$userId]);
        $addr = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $billName = trim($addr['full_name'] ?? ($order['firstname'] . ' ' . $order['lastname']));
        $billPhone = $addr['phone'] ?? ($order['phone'] ?? '');
        $billAddress = '';
        if (!empty($addr)) {
            $parts = [
                trim($addr['street'] ?? ''),
                trim($addr['region'] ?? '')
            ];
            $postal = trim($addr['postal'] ?? '');
            if ($postal !== '') { $parts[] = $postal; }
            $billAddress = implode(', ', array_filter($parts));
        } else {
            // Fallback to user's single address field if present
            $billAddress = trim((string)($order['user_address'] ?? ''));
        }
    }
    
    // Calculate safe totals to avoid undefined keys
    $computedSubtotal = 0.0;
    foreach ($orderItems as $it) {
        $computedSubtotal += ((float)($it['price'] ?? 0)) * ((int)($it['quantity'] ?? 0));
    }
    $shipping = isset($order['shipping']) ? (float)$order['shipping'] : 0.0;
    // Prefer explicit total if present, otherwise fall back to total_amount, else compute
    if (isset($order['total'])) {
        $total = (float)$order['total'];
    } elseif (isset($order['total_amount'])) {
        $total = (float)$order['total_amount'];
    } else {
        $total = $computedSubtotal + $shipping;
    }
    // PH standard 12% VAT is added on top of the item subtotal; break the
    // stored (already VAT-inclusive) total back down for display.
    $vatBreakdown = phVatBreakdown($total);
    $subtotal = $vatBreakdown['net'];
    $tax = $vatBreakdown['vat'];
    
} catch (PDOException $e) {
    error_log('Error loading invoice: ' . $e->getMessage());
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/dashboard.php');
    exit;
}

// Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js is
// deferred and would run a frame after first paint, which is exactly the
// flash this prevents. Resolution order is localStorage -> OS preference ->
// dark. There is no server-rendered preference to fall back on yet - `users`
// has no theme column; see the TODO in theme.js.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?php
// Document framing: this page is reached for completed home-delivery orders,
// which may or may not already be settled. A settled order is a receipt
// (money changed hands); an unsettled one is still an invoice (money owed).
$receiptNo  = $order['invoice_no'] ?? str_pad($order['id'], 6, '0', STR_PAD_LEFT);
$isPaid     = ($order['payment_status'] ?? '') === 'paid';
$docTitle   = $isPaid ? 'MONEY RECEIPT' : 'INVOICE';
$docNoLabel = $isPaid ? 'RECEIPT NUMBER' : 'INVOICE NUMBER';
$payLabel   = getPaymentMethodLabel($order['payment_method'] ?? 'cod');
$itemCount  = 0;
foreach ($orderItems as $it) { $itemCount += (int)($it['quantity'] ?? 0); }
?>
<section class="invoice-page">
  <div class="container">

    <div class="invoice-actions">
      <button onclick="window.print()" class="btn-primary">
        <i class="fi fi-rr-print"></i>
        Print <?= $isPaid ? 'Receipt' : 'Invoice' ?>
      </button>
      <a href="<?= WEB_ROOT ?>public/authenticated/customer/order_confirmation.php?id=<?= $orderId ?>" class="btn-secondary">
        <i class="fi fi-rr-arrow-left"></i>
        Back to Order
      </a>
    </div>

    <article class="receipt">

      <!-- Masthead band -->
      <header class="receipt-band">
        <h1 class="receipt-title"><?= htmlspecialchars($docTitle) ?></h1>
        <div class="receipt-brand">
          <span class="receipt-logo">
            <img src="<?= WEB_ROOT ?>public/shared/img/logo-lockup.png"
                 alt="TechWorld Computer Store">
          </span>
        </div>
      </header>

      <div class="receipt-body">

        <!-- Document meta -->
        <div class="receipt-meta">
          <p><span><?= $docNoLabel ?>:</span> <strong>#<?= htmlspecialchars($receiptNo) ?></strong></p>
          <p><span>DATE ISSUED:</span> <?= date('F j, Y', strtotime($order['created_at'])) ?></p>
          <p><span>DELIVERY TYPE:</span> Home Delivery</p>
          <p><span>TRACKING CODE:</span> <?= htmlspecialchars($order['tracking_code'] ?? '') ?></p>
          <p><span>PAYMENT METHOD:</span> <?= htmlspecialchars($payLabel) ?></p>
          <p class="receipt-stamp">
            <span class="stamp <?= $isPaid ? 'stamp-paid' : 'stamp-due' ?>">
              <?= $isPaid ? 'PAID' : 'DUE ON DELIVERY' ?>
            </span>
          </p>
        </div>

        <!-- Parties -->
        <div class="receipt-parties">
          <div class="party">
            <h2>RECEIPT FROM</h2>
            <p class="party-name">TechWorld Computer Store</p>
            <p>Unit 48C, 5th Street, Brgy. 104<br>
               East Grace Park, Caloocan, Philippines<br>
               0995 586 7973</p>
          </div>

          <div class="party">
            <h2>RECEIPT TO</h2>
            <p class="party-name"><?= htmlspecialchars($billName) ?></p>
            <p><?= nl2br(htmlspecialchars($billAddress)) ?><br>
               <?= htmlspecialchars($billPhone) ?><br>
               <?= htmlspecialchars($order['email']) ?></p>
          </div>
        </div>

        <!-- Line items -->
        <table class="receipt-table">
          <thead>
            <tr>
              <th class="col-desc">ITEM DESCRIPTION</th>
              <th class="col-num">RATE</th>
              <th class="col-num">QTY</th>
              <th class="col-num">TOTAL</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orderItems as $item): ?>
            <tr>
              <td class="col-desc">
                <span class="item-cell">
                  <?php if (!empty($item['image_url'])): ?>
                    <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" class="item-thumb">
                  <?php endif; ?>
                  <span class="item-name">
                    <?= htmlspecialchars($item['name']) ?>
                    <?php $sn = $itemSerials[(int)$item['id']] ?? []; ?>
                    <?php if ($sn): ?>
                      <span class="item-serials">
                        <?= count($sn) > 1 ? 'Serials: ' : 'Serial: ' ?><?= htmlspecialchars(implode(', ', $sn)) ?>
                      </span>
                    <?php endif; ?>
                  </span>
                </span>
              </td>
              <td class="col-num"><?= formatCurrency($item['price']) ?></td>
              <td class="col-num"><?= (int)$item['quantity'] ?></td>
              <td class="col-num"><?= formatCurrency($item['price'] * $item['quantity']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- Sums. The stored total is VAT-inclusive, so the subtotal shown
             here is the net it was broken back down to - do not add a
             separate shipping line without reworking phVatBreakdown above. -->
        <div class="receipt-sums">
          <div class="sum-row">
            <span>ITEMS</span>
            <span><?= $itemCount ?> unit<?= $itemCount === 1 ? '' : 's' ?></span>
          </div>
          <div class="sum-row">
            <span>SUB TOTAL</span>
            <span><?= formatCurrency($subtotal) ?></span>
          </div>
          <div class="sum-row">
            <span>VAT (12%)</span>
            <span><?= formatCurrency($tax) ?></span>
          </div>
        </div>

        <div class="receipt-total">
          <span>TOTAL</span>
          <span><?= formatCurrency($total) ?></span>
        </div>

        <?php if ($codReceipt): ?>
        <!-- Cash actually collected, as recorded by the driver. -->
        <div class="receipt-note receipt-note-paid">
          <h3>PAYMENT RECEIVED</h3>
          <div class="note-grid">
            <div><span>Amount Due</span><strong><?= formatCurrency($total) ?></strong></div>
            <div><span>Cash Tendered</span><strong><?= formatCurrency($codReceipt['amount_tendered']) ?></strong></div>
            <?php if ($codReceipt['change_given'] > 0): ?>
            <div><span>Change Given</span><strong><?= formatCurrency($codReceipt['change_given']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($codReceipt['payment_date'])): ?>
            <div><span>Received On</span><strong><?= date('M j, Y g:i A', strtotime($codReceipt['payment_date'])) ?></strong></div>
            <?php endif; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="receipt-note">
          <h3>PAYMENT TERMS</h3>
          <p>Payment is due upon delivery. Please prepare the exact amount for our delivery rider.
             <strong>Cash on Delivery</strong> carries no additional fees.</p>
        </div>
        <?php endif; ?>

      </div>

      <!-- Footer -->
      <footer class="receipt-foot">
        <div class="foot-contact">
          <p>(+63) 995 586 7973 &nbsp;|&nbsp; techworldcomputerstore@gmail.com</p>
          <p>Unit 48C, 5th Street, Brgy. 104, East Grace Park, Caloocan</p>
        </div>
        <div class="foot-thanks">Thank you!</div>
      </footer>

    </article>
  </div>
</section>

<style>
/* ---------------------------------------------------------------------
   Receipt document.

   Deliberately NOT themed: a receipt is paper. It renders as dark ink on
   white in both light and dark mode so what you see on screen is what
   comes out of the printer, and so the charcoal bands keep their contrast
   either way. Only the page around it follows the site theme.
   --------------------------------------------------------------------- */
.invoice-page {
  padding: 40px 0 60px;
  background: var(--page-bg-gradient);
}

.invoice-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  max-width: 820px;
  margin: 0 auto 24px;
}

/* "Back to Order" has no .btn-secondary rule on this page, so it fell back to
   a default blue underlined link - unreadable on the dark ground. Same ghost
   button as the dashboard's "Track an order". Light mode keeps the plain link. */
html:not([data-mode="light"]) .invoice-actions .btn-secondary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 12px;
  background: rgba(20, 36, 60, .7);
  border: 1px solid rgba(120, 170, 230, .24);
  color: #cfe0f5;
  font-weight: 600;
  text-decoration: none;
}
html:not([data-mode="light"]) .invoice-actions .btn-secondary:hover {
  background: rgba(103, 232, 249, .12);
}

.receipt {
  --ink: #2f2f2f;
  --ink-band: #3a3a3a;
  --ink-soft: #6c6c6c;
  --rule: #e3e3e3;
  --paper: #ffffff;

  max-width: 820px;
  margin: 0 auto;
  background: var(--paper);
  color: var(--ink);
  font-family: 'Figtree', system-ui, -apple-system, sans-serif;
  border-radius: 4px;
  overflow: hidden;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
}

/* --- Masthead band --- */
.receipt-band {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  background: var(--ink-band);
  padding: 30px 44px;
}

.receipt-title {
  margin: 0;
  color: #fff;
  font-size: 30px;
  font-weight: 800;
  letter-spacing: 0.06em;
  line-height: 1.1;
  text-shadow: none;
}

.receipt-brand {
  display: flex;
  align-items: center;
  gap: 12px;
}

.receipt-logo {
  display: grid;
  place-items: center;
  width: 158px;
  height: 94px;
  padding: 8px 12px;
  border: 2px solid rgba(255, 255, 255, 0.85);
  border-radius: 10px;
  background: #fff;
  flex: none;
}

.receipt-logo img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

/* --- Body --- */
.receipt-body {
  padding: 34px 44px 10px;
}

.receipt-meta {
  text-align: right;
  margin-bottom: 34px;
}

.receipt-meta p {
  margin: 0 0 9px;
  font-size: 12.5px;
  letter-spacing: 0.02em;
  color: var(--ink);
  text-shadow: none;
}

.receipt-meta p span {
  color: var(--ink-soft);
  font-weight: 600;
  letter-spacing: 0.08em;
}

.stamp {
  display: inline-block;
  margin-top: 4px;
  padding: 4px 12px;
  border: 1.5px solid currentColor;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.16em;
}

.stamp-paid { color: #2e7d59; }
.stamp-due  { color: #9a6a16; }

/* --- Parties --- */
.receipt-parties {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 28px 40px;
  margin-bottom: 32px;
}

.party h2 {
  margin: 0 0 10px;
  font-size: 11.5px;
  font-weight: 800;
  letter-spacing: 0.14em;
  color: var(--ink);
  text-shadow: none;
}

.party p {
  margin: 0;
  font-size: 13px;
  line-height: 1.6;
  color: var(--ink-soft);
  text-shadow: none;
}

.party .party-name {
  color: var(--ink);
  font-weight: 600;
  margin-bottom: 2px;
}

/* --- Line items --- */
.receipt-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 18px;
}

.receipt-table thead th {
  background: var(--ink-band);
  color: #fff;
  font-size: 11.5px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-align: left;
  padding: 13px 16px;
  text-shadow: none;
}

.receipt-table thead th.col-num { text-align: right; }

.receipt-table tbody td {
  padding: 15px 16px;
  font-size: 13px;
  color: var(--ink);
  border-bottom: 1px solid var(--rule);
  text-shadow: none;
  vertical-align: middle;
}

.receipt-table tbody td.col-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.receipt-table tbody td.col-desc { width: 55%; }

.item-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.item-name { line-height: 1.4; }
/* Serial numbers under the product name. Monospace so a transposed character
   is visible when the customer reads it back to staff over the phone, and
   small enough not to compete with the product name. */
.item-serials { display: block; margin-top: 3px; font-size: 11px; color: #64748b;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  word-break: break-all; }

.item-thumb {
  width: 34px;
  height: 34px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid var(--rule);
  flex: none;
}

/* --- Sums --- */
.receipt-sums {
  margin-left: auto;
  width: min(320px, 100%);
}

.sum-row {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  padding: 7px 16px;
  font-size: 12.5px;
  letter-spacing: 0.06em;
  color: var(--ink-soft);
  text-shadow: none;
}

.sum-row span:last-child {
  color: var(--ink);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.receipt-total {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 24px;
  margin: 16px 0 0 auto;
  width: min(320px, 100%);
  background: var(--ink-band);
  color: #fff;
  padding: 16px;
  font-weight: 800;
  letter-spacing: 0.12em;
}

.receipt-total span:last-child {
  font-size: 19px;
  letter-spacing: 0;
  font-variant-numeric: tabular-nums;
}

/* --- Note panels --- */
.receipt-note {
  margin-top: 30px;
  padding: 18px 20px;
  border: 1px solid var(--rule);
  border-left: 3px solid var(--ink-band);
  background: #fafafa;
}

.receipt-note h3 {
  margin: 0 0 10px;
  font-size: 11.5px;
  font-weight: 800;
  letter-spacing: 0.14em;
  color: var(--ink);
  text-shadow: none;
}

.receipt-note p {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.7;
  color: var(--ink-soft);
  text-shadow: none;
}

.receipt-note-paid { border-left-color: #2e7d59; }

.note-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px 24px;
}

.note-grid div {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.note-grid span {
  font-size: 11px;
  letter-spacing: 0.08em;
  color: var(--ink-soft);
}

.note-grid strong {
  font-size: 14px;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}

/* --- Footer --- */
.receipt-foot {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  margin-top: 34px;
  padding: 20px 44px 30px;
  border-top: 1px solid var(--rule);
}

.foot-contact p {
  margin: 0 0 3px;
  font-size: 11.5px;
  color: var(--ink-soft);
  text-shadow: none;
}

.foot-thanks {
  font-family: Georgia, 'Times New Roman', serif;
  font-style: italic;
  font-weight: 700;
  font-size: 22px;
  color: var(--ink);
  white-space: nowrap;
}

@media (max-width: 640px) {
  .receipt-band,
  .receipt-body { padding-left: 22px; padding-right: 22px; }
  .receipt-foot { padding-left: 22px; padding-right: 22px; flex-direction: column; align-items: flex-start; }
  .receipt-title { font-size: 23px; }
  .receipt-logo { width: 118px; height: 72px; }
  .receipt-parties { grid-template-columns: 1fr; }
  .receipt-meta { text-align: left; }
  .receipt-sums,
  .receipt-total { width: 100%; }
}

/* ---------------------------------------------------------------------
   Print. The customer shell (sidebar, footer, floating buttons) is hidden
   and the sidebar's layout offset on body > * is unwound, so the receipt
   sits alone on the sheet. print-color-adjust keeps the charcoal bands -
   without it browsers drop the backgrounds and the headers lose their
   contrast against white.
   --------------------------------------------------------------------- */
/* Top level, NOT nested in @media print below: @page only applies to paged
   media anyway, and engines disagree about honouring a nested one.

   margin:0 is what removes the browser's own print furniture - the page URL,
   title, date and page number are drawn by the browser INSIDE the @page margin
   box, so leaving no margin leaves nowhere to draw them. The paper margin comes
   back as padding on .receipt in the print block below. */
@page { size: A4; margin: 0; }

@media print {
  html, body { background: #fff !important; }

  .customer-sidebar,
  .customer-sidebar-backdrop,
  .customer-menu-toggle,
  .main-footer,
  .floating-cart-btn,
  .scroll-to-top,
  .header,
  .site-bg-video,
  .site-bg-video-scrim,
  .tw-bg,
  .invoice-actions { display: none !important; }

  body > * {
    margin-left: 0 !important;
    padding-left: 0 !important;
    width: auto !important;
  }

  .invoice-page { padding: 0 !important; background: #fff !important; }
  .invoice-page .container { max-width: none !important; padding: 0 !important; }

  .receipt {
    max-width: none !important;
    margin: 0 !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    /* The paper margin, as padding rather than @page margin - see the note
       above. Left/right hold on every sheet; top/bottom apply to the first
       and last only, which is why the vertical value is the larger one. The
       masthead band is deliberately left full-bleed, so the padding sits on
       the sections inside it instead. */
    padding: 0 0 14mm !important;
  }
  .receipt-band { padding-left: 14mm !important; padding-right: 14mm !important; }
  .receipt-body { padding: 12mm 14mm 0 !important; }
  .receipt-foot { padding: 20px 14mm 0 !important; }

  .receipt-band,
  .receipt-table thead th,
  .receipt-total,
  .receipt-note,
  .receipt-logo {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  .receipt-table tr { page-break-inside: avoid; }
  .receipt-note,
  .receipt-foot { page-break-inside: avoid; }
}

/* Keep the site footer flush under the receipt page. */
.invoice-page + .main-footer { margin-top: 0; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
