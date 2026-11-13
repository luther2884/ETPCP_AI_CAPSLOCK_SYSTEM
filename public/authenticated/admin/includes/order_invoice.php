<?php
/**
 * includes/order_invoice.php - the printed customer invoice.
 *
 * The document itself: its stylesheet, the hidden node it is built into, and
 * the browser-side builder. Included by every admin queue that prints an
 * invoice - store_pickup_orders.php and Home_Delivery_Orders.php - so the two
 * cannot drift apart and the one-page budget below is verified once.
 *
 * The caller must, before including this:
 *   require_once config/invoice.php;
 *   $invoiceData  = tw_invoice_payload($orders, 'pickup' | 'delivery');
 *   $invoiceStore = tw_invoice_store();
 *
 * Buttons call printOrderInvoice(<order id>).
 *
 * Include it AFTER includes/navigation.php. That pulls in scan_fab.php, which
 * defines its own .invoice-box rules for the scanner's receipt; this file has
 * to come later in the cascade to win.
 */

if (!isset($invoiceData) || !isset($invoiceStore)) {
    // Failing loudly beats printing a blank sheet at a counter.
    throw new RuntimeException(
        'order_invoice.php needs $invoiceData and $invoiceStore - build them with tw_invoice_payload() / tw_invoice_store() from config/invoice.php.'
    );
}
?>
<style>
/* Darkened versions of each status hue - the raw palette accent/success/
   danger tones only clear ~2.1-3.8:1 with white text (verified), well under
   WCAG AA 4.5:1. These are picked from the same hue families but dark
   enough that white badge text is actually readable. */
.status-badge { background: #6b7280; }
.status-preparing { background: #b45309; }
.status-packed { background: #174871; }
.status-ready { background: #6d6485; }
.status-shipped { background: #0f2d4d; }
.status-completed { background: #047857; }
.status-cancelled { background: #b91c1c; }

.invoice-box {
  background: #fff; color: #1e293b; border-radius: 8px; padding: 24px;
  font-family: Arial, Helvetica, sans-serif; max-width: 480px; margin: 0 auto;
}

/* ---- 1. seller ---- */
.invoice-header {
  display: flex; align-items: center; gap: 14px;
  padding-bottom: 16px; margin-bottom: 16px; border-bottom: 2px solid #1e293b;
}
.invoice-header img { width: 48px; height: 48px; object-fit: contain; flex-shrink: 0; }
.invoice-header-text { min-width: 0; }
.invoice-header .invoice-store-name { font-size: 18px; font-weight: 700; margin: 0; color: #0f172a; letter-spacing: .3px; }
.invoice-header .invoice-store-info { font-size: 11px; color: #64748b; margin: 2px 0 0; line-height: 1.5; }

/* ---- 2. document identity ---- */
.invoice-title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
.invoice-title-row h3 { margin: 0; font-size: 22px; letter-spacing: 1px; color: #0f172a; }
.invoice-title-row .invoice-meta { text-align: right; font-size: 12px; color: #475569; }
.invoice-title-row .invoice-meta div { margin-bottom: 2px; }
.invoice-title-row .invoice-meta strong { color: #0f172a; }

/* ---- 3. bill to / collect at ----
   Two columns so the person billed and the counter they collect from are read
   side by side rather than as one run-on block. */
.invoice-parties {
  display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
  margin-bottom: 16px; font-size: 12px; color: #64748b; line-height: 1.5;
}
.invoice-party { min-width: 0; }
.invoice-party-label {
  display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .6px;
  color: #94a3b8; font-weight: 700; margin-bottom: 3px;
}
.invoice-party strong { display: block; font-size: 13px; color: #0f172a; margin-bottom: 2px; }
.invoice-party-note { margin-top: 4px; font-style: italic; color: #94a3b8; }

/* ---- 4. line items ---- */
/* The items area reserves a floor height whatever the order holds, so the
   document keeps one shape: a single-product invoice leaves visible room for
   more lines rather than collapsing, and a long one simply grows downward. */
.invoice-items-wrap { min-height: 132px; margin-bottom: 18px; }
.invoice-items { width: 100%; border-collapse: collapse; font-size: 13px; }
.invoice-items th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
  color: #64748b; border-bottom: 2px solid #e2e8f0; padding: 6px 4px;
}
.invoice-items td { padding: 8px 4px; border-bottom: 1px solid #e2e8f0; color: #1e293b; }
/* Figures right-aligned and tabular, so decimal points line up down the
   column. Keyed on a class, not nth-child: the table gained a Discount column
   and positional rules would have silently re-aimed at the wrong one. */
.invoice-items .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.invoice-items-empty { text-align: center; color: #94a3b8; font-style: italic; padding: 14px 4px; }
/* Serial numbers of the units that went out on a line. Monospace so a
   transposed character is visible when the customer reads it back to staff,
   and small enough that adding them does not change the document's line
   budget (see the measured 29-line ceiling further down). */
.invoice-item-serials {
  margin-top: 2px; font-size: 10.5px; line-height: 1.4; color: #64748b;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  word-break: break-all;
}

/* ---- 5. totals ---- */
.invoice-totals { width: 100%; max-width: 260px; margin-left: auto; font-size: 13px; }
.invoice-totals .row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; color: #475569; }
.invoice-totals .row span:last-child { font-variant-numeric: tabular-nums; white-space: nowrap; }
.invoice-totals .row.grand-total {
  border-top: 2px solid #1e293b; margin-top: 6px; padding-top: 8px;
  font-size: 15px; font-weight: 700; color: #0f172a; letter-spacing: .3px;
}

/* ---- 6. payment ----
   Wraps rather than spreading: this row grew from two cells to as many as
   five, and justify-content:space-between on a flex row that cannot wrap
   turns five cells into five slivers. */
.invoice-footer-meta {
  margin-top: 20px; padding-top: 12px; border-top: 1px solid #e2e8f0;
  font-size: 12px; color: #64748b;
  display: flex; flex-wrap: wrap; gap: 10px 22px;
}
.invoice-footer-meta > div { min-width: 0; }
.invoice-footer-meta strong { color: #0f172a; }

/* ---- 7. terms, warranty, returns ---- */
.invoice-clauses {
  margin-top: 22px; padding-top: 14px; border-top: 1px solid #e2e8f0;
  font-size: 10.5px; color: #64748b; line-height: 1.55;
}
.invoice-clause + .invoice-clause { margin-top: 10px; }
.invoice-clause h4 {
  margin: 0 0 4px; font-size: 10px; text-transform: uppercase; letter-spacing: .6px;
  color: #0f172a; font-weight: 700;
}
.invoice-clause ul { margin: 0; padding-left: 16px; }
.invoice-clause li { margin-bottom: 2px; }

/* ---- 8. signatures ---- */
.invoice-signoff {
  display: grid; grid-template-columns: 1fr 1fr; gap: 28px;
  margin-top: 26px; font-size: 11px; color: #64748b;
}
.invoice-sign { display: flex; flex-direction: column; }
/* The rule is the line signed ON, so it needs clear air above it - the margin
   is the space the signature goes in. */
.invoice-sign-rule { display: block; border-top: 1px solid #0f172a; margin-top: 30px; }
.invoice-sign-label { display: block; margin-top: 4px; font-weight: 700; color: #0f172a; }
.invoice-sign-sub { display: block; color: #94a3b8; }

.invoice-thankyou { text-align: center; margin-top: 20px; font-size: 12px; color: #94a3b8; }

#invoicePrintArea { display: none; }

/* Top level, NOT inside @media print - it only applies to paged media anyway,
   and engines disagree about honouring a nested one. Same reasoning as
   scan_fab.php, which carries the identical invoice.

   margin:0 removes the browser's own print furniture: the page URL, title,
   date and page number are drawn INSIDE the @page margin box, so this receipt
   was going out with "localhost/TECHWRLD/.../store_pickup_orders.php" printed
   across the foot of a customer's invoice. The paper margin comes back as
   padding on the invoice below. */
@page { size: A4; margin: 0; }

@media print {
  /* twPrintInvoiceNode() moves #invoicePrintArea to <body> for the duration of
     the print and tags it .tw-print-root, so everything else is one selector
     away.

     The hook is the CLASS, never this page's own id. scan_fab.php is included
     on every admin page (from includes/navigation.php) and carries the same
     print mechanism for its own receipt; when both rules named their own id,
     each hid the other's print root and the sheet came out blank. Keyed on
     .tw-print-root the two rules agree instead of fighting, and any further
     print block added later joins them for free.

     display:none, not the visibility:hidden this used to use - a hidden
     element still occupies its box, which is what spilled a one-page receipt
     onto a second, blank sheet. The invoice also flows normally now instead of
     being position:fixed: Chrome repeats a fixed element on every sheet it
     generates and never paginates it, so an order with enough line items
     reprinted its header and lost the tail. */
  body.tw-printing > *:not(.tw-print-root) { display: none !important; }

  /* The admin sidebar is position:fixed, so the page clears it with a gutter
     on <body> ITSELF - admin-flat.css:205, `html body.admin{padding-left:96px}`.
     Hiding body's children does not remove that, and while printing the
     invoice IS a direct child of <body>, so it inherited the 96px and came out
     shoved right, with a margin on one side only. No sidebar is painted on
     paper, so the gutter has no job here. */
  html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
  }

  #invoicePrintArea { display: block; margin: 0; padding: 0; }

  /* Fill the sheet. The 480px cap is right for the on-screen dialog and far
     too narrow for A4 - it printed as a narrow column down the middle with
     the rest of the page blank. */
  #invoicePrintArea .invoice-box {
    max-width: none; width: auto; margin: 0; border-radius: 0;
    box-sizing: border-box; padding: 14mm 12mm;
  }

  /* --- A4 type scale ---------------------------------------------------
     One sheet per invoice is a hard requirement - every spilled second page
     is paper and toner spent on a footer. So this block is a budget, not a
     set of preferences: A4 at 96dpi is 1123px tall, the box padding below
     takes 2x10mm of it, and what is left has to hold the whole document with
     a realistic number of product lines still to come.

     Verified by rendering through Chrome's print-to-PDF and counting pages,
     not by eye - at the previous sizes even a ONE-line invoice came out on
     two sheets. Re-measure after changing anything here. */
  #invoicePrintArea .invoice-box { padding: 10mm 12mm; }
  #invoicePrintArea .invoice-header img { width: 54px; height: 54px; }
  #invoicePrintArea .invoice-header { gap: 14px; padding-bottom: 12px; margin-bottom: 12px; }
  #invoicePrintArea .invoice-store-name { font-size: 21px; }
  #invoicePrintArea .invoice-store-info { font-size: 11.5px; line-height: 1.45; }
  #invoicePrintArea .invoice-title-row { margin-bottom: 13px; }
  #invoicePrintArea .invoice-title-row h3 { font-size: 26px; }
  #invoicePrintArea .invoice-title-row .invoice-meta { font-size: 12px; }
  #invoicePrintArea .invoice-parties { font-size: 11.5px; gap: 18px; margin-bottom: 13px; line-height: 1.45; }
  #invoicePrintArea .invoice-party-label { font-size: 10px; }
  #invoicePrintArea .invoice-party strong { font-size: 14px; }

  /* The reserved item block, stated in mm because it is a share of the sheet
     rather than of a dialog. 26mm is about four further lines visible under a
     single product - enough that the page does not look truncated and that
     adding items changes nothing above the totals, without spending a fifth
     of the sheet on emptiness the way the old 55mm did. It is a MINIMUM: a
     long order grows past it and simply pushes the totals down. */
  #invoicePrintArea .invoice-items-wrap { min-height: 26mm; margin-bottom: 13px; }
  #invoicePrintArea .invoice-items { font-size: 12.5px; margin-bottom: 0; }
  #invoicePrintArea .invoice-items th { font-size: 10.5px; padding: 6px 6px; }
  #invoicePrintArea .invoice-items td { padding: 6px 6px; }
  #invoicePrintArea .invoice-item-serials { font-size: 9.5px; }

  #invoicePrintArea .invoice-totals { max-width: 300px; font-size: 12.5px; }
  #invoicePrintArea .invoice-totals .row { padding: 3px 0; }
  #invoicePrintArea .invoice-totals .row.grand-total { font-size: 16px; padding-top: 7px; margin-top: 4px; }
  #invoicePrintArea .invoice-footer-meta { font-size: 11.5px; margin-top: 14px; padding-top: 10px; gap: 8px 26px; }

  /* Reference text, deliberately the smallest thing on the sheet. It is read
     once, if ever, and at body size it is what pushed the invoice onto a
     second page. 10px on A4 is still comfortably legible. */
  #invoicePrintArea .invoice-clauses { font-size: 10px; margin-top: 14px; padding-top: 10px; line-height: 1.45; }
  #invoicePrintArea .invoice-clause h4 { font-size: 10.5px; margin-bottom: 3px; }
  #invoicePrintArea .invoice-clause + .invoice-clause { margin-top: 8px; }

  /* The rule still needs real air above it - it is the line someone signs on,
     and a signature does not fit in 12px. This is the one place the budget
     does not economise. */
  #invoicePrintArea .invoice-signoff { font-size: 11px; margin-top: 18px; gap: 34px; }
  #invoicePrintArea .invoice-sign-rule { margin-top: 30px; }
  #invoicePrintArea .invoice-thankyou { font-size: 11.5px; margin-top: 14px; }

  /* --- long orders -----------------------------------------------------
     Applied by the builder when an order runs past the 13 lines the normal
     scale fits. Only the things that repeat per row are squeezed hard - the
     row padding and the item type - because those are what multiply. The
     masthead, the totals and the signature lines are touched lightly or not
     at all: shrinking them saves a few pixels once while costing legibility
     on the parts a customer actually reads.

     The reserved item block goes to zero here. It exists so a SHORT order
     does not look truncated; an order this long has no shortage of rows.

     Measured ceiling: 29 product lines on one A4 sheet. The largest order in
     the database is 22, so there are seven lines of headroom. Past 29 the
     invoice does spill to a second sheet - if orders ever get that long,
     either tighten the per-row values here or let it spill, but do not
     assume: re-run the page count. */
  #invoicePrintArea .invoice-box--dense .invoice-items-wrap { min-height: 0; margin-bottom: 10px; }
  #invoicePrintArea .invoice-box--dense .invoice-items { font-size: 10px; }
  #invoicePrintArea .invoice-box--dense .invoice-items th { font-size: 9.5px; padding: 4px 5px; }
  #invoicePrintArea .invoice-box--dense .invoice-items td { padding: 1.5px 5px; }
  #invoicePrintArea .invoice-box--dense .invoice-item-serials { font-size: 8px; margin-top: 0; }
  #invoicePrintArea .invoice-box--dense .invoice-header img { width: 46px; height: 46px; }
  #invoicePrintArea .invoice-box--dense .invoice-header { padding-bottom: 9px; margin-bottom: 9px; }
  #invoicePrintArea .invoice-box--dense .invoice-store-name { font-size: 18px; }
  #invoicePrintArea .invoice-box--dense .invoice-title-row { margin-bottom: 10px; }
  #invoicePrintArea .invoice-box--dense .invoice-title-row h3 { font-size: 22px; }
  #invoicePrintArea .invoice-box--dense .invoice-parties { margin-bottom: 10px; }
  #invoicePrintArea .invoice-box--dense .invoice-totals .row { padding: 2px 0; }
  #invoicePrintArea .invoice-box--dense .invoice-footer-meta { margin-top: 10px; padding-top: 8px; }
  #invoicePrintArea .invoice-box--dense .invoice-clauses { font-size: 9px; margin-top: 10px; padding-top: 8px; }
  #invoicePrintArea .invoice-box--dense .invoice-clause h4 { font-size: 9.5px; }
  #invoicePrintArea .invoice-box--dense .invoice-signoff { margin-top: 12px; }
  #invoicePrintArea .invoice-box--dense .invoice-sign-rule { margin-top: 20px; }
  #invoicePrintArea .invoice-box--dense .invoice-thankyou { margin-top: 10px; }

  /* The mark is a flat-colour PNG; without this browsers drop it along with
     every other background-ish graphic and the header prints bare. */
  #invoicePrintArea .invoice-header img {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  #invoicePrintArea .invoice-items tr { page-break-inside: avoid; }
  /* Each of these is a unit that means nothing split in half: a totals column
     with TOTAL stranded on sheet two, a signature line without the name above
     it, a warranty clause cut mid-sentence. */
  #invoicePrintArea .invoice-totals,
  #invoicePrintArea .invoice-parties,
  #invoicePrintArea .invoice-footer-meta,
  #invoicePrintArea .invoice-clause,
  #invoicePrintArea .invoice-signoff { page-break-inside: avoid; break-inside: avoid; }
}
</style>

<div id="invoicePrintArea"></div>

<script>
var TW_INVOICE_DATA  = <?= json_encode($invoiceData, JSON_UNESCAPED_UNICODE) ?>;
var TW_INVOICE_STORE = <?= json_encode($invoiceStore, JSON_UNESCAPED_UNICODE) ?>;

function twFmtMoney(n) {
  var num = parseFloat(n);
  if (isNaN(num)) num = 0;
  return '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* Everything that reaches the invoice goes through here first.

   The payload is server-built from the order record, but it still carries
   customer-entered text - a name, an address, a product title - and it is
   assembled into the document by string concatenation. Without escaping, an
   apostrophe in a product name is cosmetic and a '<' is not. */
function twEsc(v) {
  return String(v === null || v === undefined ? '' : v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* A labelled clause block, printed only when there is something to put in it.
   An empty "Returns" heading over nothing is worse than no heading - it reads
   as a policy the seller declined to state. */
function twInvoiceClause(title, lines) {
  if (!lines || !lines.length) { return ''; }
  var out = '<div class="invoice-clause"><h4>' + title + '</h4><ul>';
  lines.forEach(function (ln) { out += '<li>' + twEsc(ln) + '</li>'; });
  return out + '</ul></div>';
}

function printOrderInvoice(orderId) {
  var order = TW_INVOICE_DATA[orderId];
  if (!order) { alert('Invoice data not available for this order.'); return; }
  var store = TW_INVOICE_STORE || {};

  /* One sheet per invoice is the requirement, and a long order is the only
     thing that can break it: at the normal A4 scale the document fits up to
     13 product lines, and live data already holds a 22-line order. Rather
     than let that spill onto a second sheet, an order past the threshold
     prints at a tighter scale - see .invoice-box--dense in the print block.
     The threshold is where the measurement said the page gives out, not a
     round number. */
  var dense = (order.items || []).length > 13;

  var html = '<div class="invoice-box' + (dense ? ' invoice-box--dense' : '') + '">';

  /* ---- 1. seller ---- */
  html += '<div class="invoice-header">';
  html += '<img src="<?= WEB_ROOT ?>public/shared/img/logo-mark.png" alt="">';
  html += '<div class="invoice-header-text">';
  html += '<p class="invoice-store-name">' + twEsc(store.name) + '</p>';
  html += '<p class="invoice-store-info">' + twEsc(store.address);
  if (store.phone || store.email) {
    html += '<br>' + twEsc(store.phone) + (store.phone && store.email ? ' &middot; ' : '') + twEsc(store.email);
  }
  if (store.tin) { html += '<br>TIN: ' + twEsc(store.tin); }
  html += '</p></div></div>';

  /* ---- 2. document identity ---- */
  html += '<div class="invoice-title-row">';
  html += '<h3>INVOICE</h3>';
  html += '<div class="invoice-meta">';
  html += '<div>Invoice #: <strong>' + twEsc(order.invoice_no) + '</strong></div>';
  html += '<div>Invoice Date: <strong>' + twEsc(order.created_at) + '</strong></div>';
  html += '<div>Order #: <strong>' + twEsc(order.order_no) + '</strong></div>';
  if (order.tracking_code) {
    html += '<div>Tracking: <strong>' + twEsc(order.tracking_code) + '</strong></div>';
  }
  html += '</div></div>';

  /* ---- 3. bill to / collection ---- */
  html += '<div class="invoice-parties">';
  html += '<div class="invoice-party"><span class="invoice-party-label">Bill To</span>';
  html += '<strong>' + twEsc(order.customer_name || 'Walk-in customer') + '</strong>';
  if (order.customer_email)  { html += '<div>' + twEsc(order.customer_email) + '</div>'; }
  if (order.billing_address) { html += '<div>' + twEsc(order.billing_address) + '</div>'; }
  html += '</div>';
  /* The second panel is whatever the order's queue made it: the store counter
     on a pickup invoice, the recipient and address on a delivery one. Printing
     a "Delivery Address" on a pickup sheet - or the store's own address on a
     delivery sheet - would be wrong on every row, so the page that knows which
     queue it is decides, in tw_invoice_payload(). */
  html += '<div class="invoice-party"><span class="invoice-party-label">'
        + twEsc(order.fulfil_label) + '</span>';
  html += '<strong>' + twEsc(order.fulfil_name) + '</strong>';
  (order.fulfil_lines || []).forEach(function (ln) {
    html += '<div>' + twEsc(ln) + '</div>';
  });
  if (order.fulfil_note) {
    html += '<div class="invoice-party-note">' + twEsc(order.fulfil_note) + '</div>';
  }
  html += '</div></div>';

  /* ---- 4. line items ---- */
  var itemsRows = '';
  (order.items || []).forEach(function (it) {
    /* Serial numbers under the product name, not in a column of their own: a
       line can carry several (quantity 2 = two serials) and a column would
       have to either wrap badly or clip, on a document whose whole design is
       built around a fixed line budget. Indented small print keeps one line
       one row. */
    var serialNote = '';
    if (it.serials && it.serials.length) {
      serialNote = '<div class="invoice-item-serials">'
        + (it.serials.length > 1 ? 'Serials: ' : 'Serial: ')
        + it.serials.map(twEsc).join(', ')
        + '</div>';
    }
    itemsRows += '<tr>'
      + '<td>' + twEsc(it.name) + serialNote + '</td>'
      + '<td class="num">' + twEsc(it.quantity) + '</td>'
      + '<td class="num">' + twFmtMoney(it.price) + '</td>'
      + '<td class="num">' + twFmtMoney(it.discount) + '</td>'
      + '<td class="num">' + twFmtMoney(it.total) + '</td>'
      + '</tr>';
  });
  if (!itemsRows) {
    itemsRows = '<tr><td colspan="5" class="invoice-items-empty">This order has no line items on record.</td></tr>';
  }
  /* The wrapper holds a floor height so a one-line order still reserves a
     visible block for its items, and an order that grows to a dozen lines
     pushes the totals down instead of changing the shape of the document.
     Without it a single-product invoice collapsed the middle of the page and
     the totals rode up under the header. */
  html += '<div class="invoice-items-wrap">';
  html += '<table class="invoice-items"><thead><tr>'
        + '<th>Product</th><th class="num">Qty</th><th class="num">Unit Price</th>'
        + '<th class="num">Discount</th><th class="num">Amount</th>'
        + '</tr></thead><tbody>' + itemsRows + '</tbody></table>';
  html += '</div>';

  /* ---- 5. totals ----
     Subtotal, the deductions between, then TOTAL. Every line shown takes part
     in the arithmetic, so the column adds up exactly as printed. */
  html += '<div class="invoice-totals">';
  html += '<div class="row"><span>Subtotal</span><span>' + twFmtMoney(order.subtotal) + '</span></div>';
  /* Charges above the goods, then deductions, then the total. Every figure
     here is derived in tw_invoice_totals() from the only two stored facts -
     the line totals and the order's own total_amount - so the column adds up
     exactly as printed for every order, whatever the stored columns say.
     A zero row is omitted rather than printed as PHP0.00: an invoice should
     not list charges that were never made. */
  if (order.vat !== null && order.vat !== undefined) {
    html += '<div class="row"><span>' + twEsc(order.vat_label) + '</span><span>'
          + twFmtMoney(order.vat) + '</span></div>';
  } else if (parseFloat(order.other_charge) > 0) {
    /* A gap that is NOT the configured VAT rate. Named for what is certain -
       that more was charged than the goods came to - rather than labelled VAT
       on an invoice that cannot support the claim. */
    html += '<div class="row"><span>Other Charges</span><span>'
          + twFmtMoney(order.other_charge) + '</span></div>';
  }
  if (parseFloat(order.deduction) > 0) {
    html += '<div class="row"><span>' + twEsc(order.deduction_label) + '</span><span>&minus;'
          + twFmtMoney(order.deduction) + '</span></div>';
  }
  html += '<div class="row grand-total"><span>TOTAL</span><span>' + twFmtMoney(order.total_amount) + '</span></div>';
  html += '</div>';

  /* ---- 6. payment ---- */
  html += '<div class="invoice-footer-meta">';
  html += '<div>Payment Method<br><strong>' + twEsc(order.payment_method) + '</strong></div>';
  html += '<div>Payment Status<br><strong>' + twEsc(order.payment_status) + '</strong></div>';
  html += '<div>Order Status<br><strong>' + twEsc(order.status) + '</strong></div>';
  if (order.payment_ref) {
    html += '<div>Reference<br><strong>' + twEsc(order.payment_ref) + '</strong></div>';
  }
  if (order.paid_at) {
    html += '<div>Paid On<br><strong>' + twEsc(order.paid_at) + '</strong></div>';
  }
  html += '</div>';

  /* ---- 7. terms, warranty, returns ---- */
  var clauses = twInvoiceClause('Notes / Terms and Conditions', store.terms)
              + twInvoiceClause('Warranty, Returns &amp; Refunds', store.policy);
  if (clauses) { html += '<div class="invoice-clauses">' + clauses + '</div>'; }

  /* ---- 8. signatures ---- */
  html += '<div class="invoice-signoff">';
  html += '<div class="invoice-sign"><span class="invoice-sign-rule"></span>'
        + '<span class="invoice-sign-label">Authorized Personnel</span>'
        + (store.issued_by ? '<span class="invoice-sign-sub">Issued by ' + twEsc(store.issued_by) + '</span>' : '')
        + '</div>';
  html += '<div class="invoice-sign"><span class="invoice-sign-rule"></span>'
        + '<span class="invoice-sign-label">Received By (Customer)</span>'
        + '<span class="invoice-sign-sub">Signature over printed name &amp; date</span>'
        + '</div>';
  html += '</div>';

  html += '<div class="invoice-thankyou">Thank you for shopping with TechWorld!</div>';
  html += '</div>';

  document.getElementById('invoicePrintArea').innerHTML = html;

  // Wait for the logo to finish loading before printing, otherwise the print
  // snapshot can be taken before the image has decoded and it prints blank.
  var logoImg = document.querySelector('#invoicePrintArea .invoice-header img');
  if (logoImg && !logoImg.complete) {
    var printed = false;
    var doPrint = function () { if (printed) return; printed = true; twPrintInvoiceNode(); };
    logoImg.addEventListener('load', doPrint);
    logoImg.addEventListener('error', doPrint);
    setTimeout(doPrint, 1500);
  } else {
    twPrintInvoiceNode();
  }
}

/* Print the invoice on its own sheet.

   The node is lifted to <body> for the duration of the print rather than
   printed where it sits. #invoicePrintArea is nested inside the admin page
   shell, and printing it in place meant pinning it with position:fixed -
   which Chrome stamps onto every sheet it generates and never paginates. As a
   direct child of <body>, with body.tw-printing collapsing its siblings, it
   flows and breaks like ordinary content.

   The comment node holds its place so it goes back exactly where it was;
   afterprint fires whether the dialog was confirmed or cancelled. */
function twPrintInvoiceNode() {
  var node = document.getElementById('invoicePrintArea');
  if (!node) { window.print(); return; }

  var slot = document.createComment('invoice-print-slot');
  node.parentNode.insertBefore(slot, node);
  document.body.appendChild(node);
  node.classList.add('tw-print-root');
  document.body.classList.add('tw-printing');

  // Undone on either signal, whichever the browser gives us, and never on a
  // timer: Chrome's preview stays open for as long as the operator reads it,
  // so a timed restore pulled the invoice back out of <body> mid-preview and
  // the sheet went blank in front of them.
  var mql = window.matchMedia ? window.matchMedia('print') : null;
  var onMql = function (e) { if (!e.matches) { restore(); } };
  var restored = false;
  function restore() {
    if (restored) { return; }
    restored = true;
    window.removeEventListener('afterprint', restore);
    if (mql && mql.removeEventListener) { mql.removeEventListener('change', onMql); }
    document.body.classList.remove('tw-printing');
    node.classList.remove('tw-print-root');
    if (slot.parentNode) { slot.parentNode.replaceChild(node, slot); }
  }
  window.addEventListener('afterprint', restore);
  if (mql && mql.addEventListener) { mql.addEventListener('change', onMql); }

  window.print();
}
</script>
