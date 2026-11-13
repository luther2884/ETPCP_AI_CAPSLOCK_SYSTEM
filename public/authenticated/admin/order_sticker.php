<?php
declare(strict_types=1);
/**
 * admin/order_sticker.php - the printable label.
 *
 * Sized for a 2.25 x 1.25 inch thermal label (the common Zebra/Brother stock),
 * which is also what a sheet of address labels gives you. @page is set to that
 * size with no margin, so "Print" goes straight to a correctly-sized sticker
 * without anyone fighting the print dialog.
 *
 * The QR carries the order's qr_token and nothing else. That token is what
 * api/order_scan.php resolves - the order id is never in the code, so a label
 * cannot be forged by guessing a number, and a photographed label gives away
 * nothing about how many orders the store has.
 *
 * ?id=N        one label
 * ?ids=1,2,3   a sheet of them, one per page - for printing a batch after
 *              a picking run
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';

require_login();
require_role(['admin', 'super-admin', 'owner', 'manager']);

$pdo = getConnection();

$ids = [];
if (!empty($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $v) {
        $n = (int)trim($v);
        if ($n > 0) { $ids[] = $n; }
    }
} elseif (!empty($_GET['id'])) {
    $ids[] = (int)$_GET['id'];
}
$ids = array_values(array_unique($ids));
// A runaway ?ids= should not try to render thousands of labels.
$ids = array_slice($ids, 0, 100);

// Rendered inside includes/sticker_modal.php's preview iframe, which supplies
// its own Print/Close buttons - so the page's own toolbar would be a duplicate.
$embed = !empty($_GET['embed']);

$labels = [];
foreach ($ids as $id) {
    $stmt = $pdo->prepare("
        SELECT o.id, o.invoice_no, o.status, o.delivery_method, o.created_at,
               COALESCE(NULLIF(TRIM(o.delivery_recipient_name), ''),
                        NULLIF(TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,''))), ''),
                        'Guest') AS customer_name
        FROM orders o LEFT JOIN users u ON u.id = o.user_id
        WHERE o.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { continue; }

    // Minted on first print, then stable for the life of the order - the
    // sticker applied at packing is the same one scanned at handover.
    $token = os_qr_token($pdo, (int)$row['id']);
    if (!$token) { continue; }

    $row['token'] = $token;
    $row['payload'] = os_scan_payload($token);
    $row['number'] = $row['invoice_no'] ?: ('ORD-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT));
    $labels[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Order label<?= count($labels) === 1 ? ' ' . htmlspecialchars($labels[0]['number']) : 's' ?></title>
<style>
  /* 2.25in x 1.25in thermal label stock. */
  @page { size: 2.25in 1.25in; margin: 0; }

  * { box-sizing: border-box; }
  body { margin: 0; background: #e9edf2; font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif; color: #10151c; }
  /* Embedded in the preview: no page chrome, no outer padding, and the white
     ground comes from the modal so the label sits on the colour it prints on. */
  body.embed { background: #fff; }
  body.embed .sheet { padding: 0; gap: 12px; justify-content: center; }

  .toolbar { padding: 16px 20px; background: #fff; border-bottom: 1px solid #d5dce4; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  .toolbar h1 { font-size: 15px; margin: 0 auto 0 0; font-weight: 800; }
  .toolbar button, .toolbar a { min-height: 40px; padding: 10px 20px; border-radius: 8px; border: 1px solid #c3ccd7; background: #fff; color: #10151c; font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
  .toolbar button.primary { background: #174871; border-color: transparent; color: #fff; }

  .sheet { padding: 24px; display: flex; flex-wrap: wrap; gap: 20px; }

  /* The label itself. Fixed physical size so what is on screen is what comes
     out of the printer. */
  .label {
    width: 2.25in; height: 1.25in;
    background: #fff; color: #000;
    padding: 0.07in 0.09in;
    display: grid; grid-template-columns: 0.95in 1fr; gap: 0.08in; align-items: center;
    border: 1px solid #c3ccd7;   /* screen only - removed for print below */
    page-break-after: always; break-after: page;
  }
  .label:last-child { page-break-after: auto; break-after: auto; }

  .qr { width: 0.95in; height: 0.95in; display: flex; align-items: center; justify-content: center; }
  .qr img, .qr canvas { width: 100% !important; height: 100% !important; display: block; }

  .info { min-width: 0; display: flex; flex-direction: column; justify-content: center; }
  .num { font-family: ui-monospace, Consolas, 'Courier New', monospace; font-size: 12.5pt; font-weight: 700; line-height: 1.1; letter-spacing: -0.2px; overflow-wrap: anywhere; }
  .cust { font-size: 8.5pt; font-weight: 600; margin-top: 2px; line-height: 1.2;
          /* Long names must not push the label's other lines off the sticker. */
          display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .meta { font-size: 6.5pt; margin-top: 3px; text-transform: uppercase; letter-spacing: .4px; color: #333; }
  .brand { font-size: 6pt; letter-spacing: 1px; text-transform: uppercase; color: #555; margin-top: 2px; }

  .empty { padding: 40px 24px; color: #52606f; font-size: 15px; }

  /* The scan page offers "Scan a sticker, or paste its code" - but the code
     lived only in a data-attribute, so there was nothing to paste. This strip
     makes it obtainable, which is also the fallback when a handheld scanner
     dies mid-shift. Screen only: it must never appear on a printed label. */
  .scancode { margin-top: 6px; display: flex; gap: 6px; align-items: center; }
  .scancode code { flex: 1 1 auto; min-width: 0; font-family: ui-monospace, Consolas, monospace;
    font-size: 8.5px; line-height: 1.25; color: #5b6774;
    background: #f2f5f9; border: 1px solid #dfe5ed; border-radius: 4px;
    padding: 3px 5px; overflow-wrap: anywhere; user-select: all; }
  .scancode button { flex: 0 0 auto; padding: 3px 8px; font: inherit; font-size: 10px;
    font-weight: 700; border: 1px solid #c3ccd7; border-radius: 4px; background: #fff;
    color: #10151c; cursor: pointer; }
  .scancode button:hover { background: #f7f9fc; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { padding: 0; gap: 0; display: block; }
    .label { border: none; margin: 0; }
    .scancode { display: none; }
  }
</style>
</head>
<body class="<?= $embed ? 'embed' : '' ?>">

<?php if (!$embed): ?>
<div class="toolbar">
  <h1><?= count($labels) ?> label<?= count($labels) === 1 ? '' : 's' ?> ready</h1>
  <a href="javascript:history.back()">Back</a>
  <button type="button" class="primary" onclick="window.print()">Print</button>
</div>
<?php endif; ?>

<?php if (empty($labels)): ?>
  <div class="empty">No order found for that id. Open a label from the order queue.</div>
<?php else: ?>
  <div class="sheet">
    <?php foreach ($labels as $l): ?>
      <div class="label">
        <div class="qr" data-payload="<?= htmlspecialchars($l['payload']) ?>"></div>
        <div class="info">
          <div class="num"><?= htmlspecialchars($l['number']) ?></div>
          <div class="cust"><?= htmlspecialchars($l['customer_name']) ?></div>
          <div class="meta">
            <?= $l['delivery_method'] === 'pickup' ? 'Store Pickup' : 'Delivery' ?>
            &middot; <?= htmlspecialchars(date('M j', strtotime((string)$l['created_at']))) ?>
          </div>
          <div class="brand">TechWorld</div>
          <?php /* The same string the QR encodes. os_parse_scan() strips the
                   TWO: prefix, so this pastes straight into the scan page's
                   manual field. */ ?>
          <div class="scancode">
            <code><?= htmlspecialchars($l['payload']) ?></code>
            <button type="button" class="copy-code" data-code="<?= htmlspecialchars($l['payload']) ?>">Copy</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Rendered in the browser with the QR library already vendored in this repo,
     so a label prints with no network round-trip and no external service. -->
<script src="<?= WEB_ROOT ?>davidshimjs-qrcodejs-04f46c6/qrcode.js"></script>
<script>
(function () {
  var boxes = document.querySelectorAll('.qr');
  if (!window.QRCode) {
    // Never print a label with an empty square where the code should be.
    boxes.forEach(function (b) { b.textContent = 'QR unavailable'; b.style.fontSize = '7pt'; });
    return;
  }
  boxes.forEach(function (box) {
    new QRCode(box, {
      text: box.getAttribute('data-payload'),
      width: 256, height: 256,        // oversampled, then scaled by CSS, so it
      colorDark: '#000000',           // stays crisp at 203dpi thermal output
      colorLight: '#ffffff',
      // Highest error correction: these stickers get scuffed, taped over and
      // scanned at odd angles on a warehouse floor.
      correctLevel: QRCode.CorrectLevel.H
    });
  });

  // Copy the scan code, for pasting into the scan page's manual field.
  // Delegated, so it works for every label on a multi-label sheet.
  //
  // navigator.clipboard is unavailable on a plain-HTTP origin in most
  // browsers, and this admin panel is served over http on the shop's LAN -
  // so the selection fallback is the path that will usually run, not an
  // afterthought. Either way the code ends up on the clipboard.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.copy-code');
    if (!btn) { return; }
    var code = btn.getAttribute('data-code') || '';
    var done = function () {
      var was = btn.textContent;
      btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = was; }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(code).then(done, function () { selectFallback(btn, done); });
    } else {
      selectFallback(btn, done);
    }
  });

  function selectFallback(btn, done) {
    var el = btn.parentNode.querySelector('code');
    if (!el) { return; }
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    var copied = false;
    try { copied = document.execCommand('copy'); } catch (err) { copied = false; }
    // Left selected on failure, so it can still be copied by hand rather than
    // the button silently doing nothing.
    if (copied) { sel.removeAllRanges(); done(); }
  }

  // Straight to the print dialog for anyone who opens this page with
  // ?autoprint=1 directly (a bookmark, or a link from outside the admin UI).
  // The in-page preview never uses it - includes/sticker_modal.php shows the
  // label first and prints on the operator's say-so.
  if (new URLSearchParams(location.search).get('autoprint') === '1') {
    setTimeout(function () { window.print(); }, 350);
  }
})();
</script>
</body>
</html>
