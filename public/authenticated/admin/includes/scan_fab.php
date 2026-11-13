<?php
/**
 * Global QR scanner - the floating scan button, its modal, styles and script.
 *
 * Extracted verbatim from dashboard.php so it can be on EVERY admin page
 * instead of only that one. It is pulled in by includes/navigation.php, which
 * every admin page already includes, so no page has to opt in and no page can
 * be forgotten.
 *
 * It renders at the end of navigation.php, outside <aside>, for the same
 * reason the Order Management chooser there does: the sidebar is
 * position:fixed with overflow:hidden and would clip anything inside it. The
 * button and the modal overlay are both position:fixed, so they sit correctly
 * regardless of where in the page the include happens to run.
 *
 * $actionsDisabled greys the button out for an account that is not active.
 * dashboard.php computes it before including navigation; every other page does
 * not, so it is resolved here when missing rather than assumed - defaulting to
 * "enabled" would hand a suspended account a working scanner.
 */
if (!isset($actionsDisabled)) {
    $__scanStatus = 'active';
    try {
        $__scanSt = getConnection()->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
        $__scanSt->execute([getUserId()]);
        $__scanRow = $__scanSt->fetch();
        if ($__scanRow) { $__scanStatus = $__scanRow['status'] ?? 'active'; }
    } catch (Throwable $e) { /* ignore and assume active, as dashboard.php does */ }
    $actionsDisabled = (!isSuperAdmin() && $__scanStatus !== 'active');
}
?>
<button type="button" class="scan-fab" id="scanFabBtn" title="Scan a customer pickup QR or an order sticker" aria-label="Scan a customer pickup QR or an order sticker" <?= $actionsDisabled ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>>
  <img src="<?= WEB_ROOT ?>public/shared/img/icons/qr-code.gif" alt="" aria-hidden="true" class="scan-fab-icon">
</button>

<!-- QR Scan Modal - scans onsite "Pay at Store" order QR codes (payload: order:{id}:{pickup_code}),
     the same payload/endpoint public/authenticated/scan/verify.php uses. -->
<div id="scanModal" class="scan-modal-overlay" style="display:none;" onclick="if(event.target===this) closeScanModal()">
  <div class="scan-modal">
    <div class="scan-modal-header">
      <h3><i class="fi fi-rr-qr-code"></i> Scan Customer QR Code</h3>
      <button type="button" class="scan-modal-close" onclick="closeScanModal()" aria-label="Close">&times;</button>
    </div>
    <div class="scan-modal-body">
      <div id="scanScannerSection">
        <div class="camera-viewport">
          <div id="reader" class="qr-reader"></div>
          <div class="scanner-overlay">
            <div class="scanner-frame"></div>
          </div>
        </div>
        <div class="camera-controls">
          <button type="button" class="control-btn" id="scanStartBtn" onclick="scanStart()">
            <i class="fi fi-rs-play"></i> Start Scanner
          </button>
          <button type="button" class="control-btn" id="scanStopBtn" onclick="scanStop()" style="display:none;">
            <i class="fi fi-rs-pause"></i> Stop Scanner
          </button>
        </div>
        <div class="scanner-status" id="scanStatus">Click "Start Scanner" and allow camera access</div>

        <div class="manual-entry">
          <p>Or enter the 6-digit pickup code manually:</p>
          <form id="scanManualForm">
            <input type="text" id="scanPickupCode" placeholder="123456" maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
            <button type="submit" class="control-btn">Verify</button>
          </form>
        </div>
      </div>

      <button type="button" class="control-btn back-to-scanner-btn" id="scanBackBtn" style="display:none;" onclick="scanBackToScanner()">
        <i class="fi fi-rs-angle-left"></i> Back to Scanner
      </button>

      <div class="scan-result" id="scanResult" style="display:none;"></div>
    </div>
  </div>
</div>

<style>
.scan-fab {
  position: fixed;
  right: 30px;
  bottom: 30px;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  /* Light surface (was a dark mauve->navy gradient) so the qr-code.gif's
     white canvas can be dropped with mix-blend-mode:multiply like every
     other admin icon - on the old dark fill the icon's black linework
     would have vanished instead. Mauve ring keeps it reading as a distinct
     action button. */
  background: var(--admin-surface, #eef0f1);
  color: var(--admin-text, #17324a);
  font-size: 26px;
  border: 2px solid var(--accent-cyan, #a05f80);
  cursor: pointer;
  box-shadow: 0 8px 22px rgba(23, 72, 113, 0.28);
  z-index: 900;
  transition: transform var(--duration-fast) var(--ease-out), box-shadow var(--duration-fast) var(--ease-out);
}
.scan-fab-icon {
  width: 32px;
  height: 32px;
  object-fit: contain;
  pointer-events: none;
  mix-blend-mode: multiply;
}
.scan-fab:hover {
  transform: translateY(-3px) scale(1.05);
  box-shadow: 0 12px 28px rgba(23, 72, 113, 0.4);
}
.scan-fab:active {
  transform: scale(0.96);
}
@media (max-width: 768px) {
  .scan-fab {
    right: 20px;
    bottom: 20px;
    width: 52px;
    height: 52px;
    font-size: 22px;
  }
}

.scan-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.7);
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.scan-modal {
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
  background: #0f172a;
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 16px;
}
.scan-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.scan-modal-header h3 {
  color: #e5e7eb;
  font-size: 18px;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.scan-modal-close {
  background: transparent;
  border: none;
  color: #94a3b8;
  font-size: 24px;
  cursor: pointer;
  line-height: 1;
}
.scan-modal-close:hover { color: #e5e7eb; }
.scan-modal-body { padding: 20px 22px; }
.camera-viewport {
  position: relative;
  background: #000;
  border-radius: 12px;
  overflow: hidden;
  min-height: 280px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.qr-reader { width: 100%; min-height: 280px; }
#reader video, #reader canvas { width: 100% !important; object-fit: cover !important; }
.scanner-overlay {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  pointer-events: none;
}
.scanner-frame {
  width: 200px; height: 200px;
  border: 3px solid #a77693;
  border-radius: 12px;
}
.camera-controls {
  display: flex; justify-content: center; gap: 12px;
  margin: 16px 0;
}
.control-btn {
  background: linear-gradient(45deg, #a77693, #174871);
  color: #fff; border: none; padding: 10px 18px;
  border-radius: 8px; cursor: pointer; font-weight: 600;
  display: flex; align-items: center; gap: 8px;
}
.back-to-scanner-btn {
  width: 100%; justify-content: center;
  background: rgba(255,255,255,.08); color: #e5e7eb;
  border: 1px solid rgba(255,255,255,.15);
  margin-bottom: 14px;
}
.back-to-scanner-btn:hover { background: rgba(255,255,255,.14); }
.scanner-status {
  text-align: center; color: #94a3b8; font-size: 13px;
  padding: 10px; background: rgba(255,255,255,.04); border-radius: 8px;
}
.manual-entry {
  margin-top: 20px; padding-top: 16px;
  border-top: 1px solid rgba(255,255,255,.08);
  text-align: center;
}
.manual-entry p { color: #94a3b8; font-size: 13px; margin-bottom: 10px; }
.manual-entry form { display: flex; gap: 8px; justify-content: center; }
.manual-entry input {
  flex: 1; max-width: 160px; padding: 10px; text-align: center;
  background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
  border-radius: 8px; color: #e5e7eb; font-size: 16px; letter-spacing: 2px;
}
.scan-result {
  margin-top: 18px; padding: 16px; border-radius: 10px;
  background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
  color: #e5e7eb; font-size: 14px;
}
.scan-result.success { border-color: rgba(16,185,129,.4); background: rgba(16,185,129,.08); }
.scan-result.error { border-color: rgba(239,68,68,.4); background: rgba(239,68,68,.08); }
.scan-result h4 { margin: 0 0 8px; font-size: 15px; }
.scan-result .info-row { display:flex; justify-content:space-between; padding:4px 0; color:#cbd5e1; font-size:13px; }
.scan-result .info-row .label { color:#94a3b8; }
.payment-collect { margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,.08); }
.payment-collect label { display:block; color:#94a3b8; font-size:13px; margin-bottom:6px; }
.payment-collect input {
  width:100%; padding:10px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
  border-radius: 8px; color: #e5e7eb; font-size: 16px; margin-bottom: 10px; box-sizing: border-box;
}
.payment-error { color:#ef4444; font-size:13px; margin-bottom:10px; }
.confirm-payment-btn { width:100%; justify-content:center; background: linear-gradient(45deg, #10b981, #059669); margin-top: 4px; }
.confirm-payment-btn:disabled { background: rgba(255,255,255,.08); color: #64748b; cursor: not-allowed; }
.payment-status-badge { font-weight:700; }
.payment-status-badge.paid { color:#22c55e; }
.payment-status-badge.pending { color:#f59e0b; }
.invoice-box {
  background: #fff; color: #1e293b; border-radius: 8px; padding: 24px;
  font-family: Arial, Helvetica, sans-serif;
}
.invoice-header {
  display: flex; align-items: center; gap: 14px;
  padding-bottom: 16px; margin-bottom: 16px; border-bottom: 2px solid #1e293b;
}
.invoice-header img { width: 48px; height: 48px; object-fit: contain; flex-shrink: 0; }
.invoice-header .invoice-store-name { font-size: 20px; font-weight: 700; margin: 0; color: #0f172a; }
.invoice-header .invoice-store-info { font-size: 11px; color: #64748b; margin: 2px 0 0; line-height: 1.5; }
.invoice-title-row {
  display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;
}
.invoice-title-row h3 { margin: 0; font-size: 22px; letter-spacing: 1px; color: #0f172a; }
.invoice-title-row .invoice-meta { text-align: right; font-size: 12px; color: #475569; }
.invoice-title-row .invoice-meta div { margin-bottom: 2px; }
.invoice-title-row .invoice-meta strong { color: #0f172a; }
.invoice-billto { font-size: 12px; color: #64748b; margin-bottom: 16px; }
.invoice-billto strong { display: block; font-size: 14px; color: #0f172a; margin-top: 2px; }
.invoice-items { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 13px; }
.invoice-items th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
  color: #64748b; border-bottom: 2px solid #e2e8f0; padding: 6px 4px;
}
.invoice-items td { padding: 8px 4px; border-bottom: 1px solid #e2e8f0; color: #1e293b; }
.invoice-items th:last-child, .invoice-items td:last-child,
.invoice-items th:nth-child(2), .invoice-items td:nth-child(2) { text-align: right; }
.invoice-totals { width: 100%; max-width: 260px; margin-left: auto; font-size: 13px; }
.invoice-totals .row { display: flex; justify-content: space-between; padding: 4px 0; color: #475569; }
.invoice-totals .row.grand-total {
  border-top: 2px solid #1e293b; margin-top: 6px; padding-top: 8px;
  font-size: 15px; font-weight: 700; color: #0f172a;
}
.invoice-footer-meta {
  margin-top: 20px; padding-top: 12px; border-top: 1px solid #e2e8f0;
  font-size: 12px; color: #64748b; display: flex; justify-content: space-between;
}
.invoice-thankyou { text-align: center; margin-top: 20px; font-size: 12px; color: #94a3b8; }
/* Top level, NOT inside @media print - it only applies to paged media anyway,
   and engines disagree about honouring a nested one. Same reasoning as
   reports_analytics.php, which solved this first.

   margin:0 is what removes the browser's own print furniture. The
   "localhost/TECHWRLD/public/authenticated/admin/dashboard.php" footer, and the
   page title, date and page number beside it, are drawn by the browser INSIDE
   the @page margin box - this invoice prints from whatever admin page the scan
   FAB is sitting on, so that was the dashboard's URL landing on a customer's
   receipt. No margin leaves nowhere to draw them. The paper margin comes back
   as padding on the invoice below. */
@media screen { body.tw-printing > .tw-print-root { display: none; } }

@page { size: A4; margin: 0; }

@media print {
  /* scanPrintInvoice() moves #scanInvoicePrint to <body> for the duration of
     the print and tags it .tw-print-root, so everything else is one selector
     away.

     The hook is the CLASS, never this receipt's own id. This file is included
     on every admin page, and pages like store_pickup_orders.php carry the same
     print mechanism for their own receipt; when both rules named their own id,
     each hid the other's print root and the sheet came out blank. Keyed on
     .tw-print-root the two rules agree instead of fighting.

     display:none, not the visibility:hidden this used to use - a hidden
     element still occupies its box, and the dashboard sitting behind the modal
     was what pushed a one-page receipt onto a second, blank sheet. The invoice
     also flows normally now instead of being position:fixed: Chrome repeats a
     fixed element on every sheet it generates and never paginates it, so an
     order with enough line items reprinted its header and lost the tail. */
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

  #scanInvoicePrint {
    display: block; width: auto; margin: 0; border-radius: 0;
    box-sizing: border-box;
    /* Padding, not @page margin - see the note above. */
    padding: 14mm 12mm;
  }

  /* --- A4 type scale ---------------------------------------------------
     The sizes above are tuned for the ~420px-wide modal panel. Printed at
     those values the receipt reads as grey fine print marooned in the middle
     of the sheet, so every step of the scale goes up roughly a third for
     paper. Nothing here changes the on-screen dialog. */
  #scanInvoicePrint .invoice-header img { width: 74px; height: 74px; }
  #scanInvoicePrint .invoice-header { gap: 18px; padding-bottom: 20px; margin-bottom: 20px; }
  #scanInvoicePrint .invoice-store-name { font-size: 27px; }
  #scanInvoicePrint .invoice-store-info { font-size: 13px; }
  #scanInvoicePrint .invoice-title-row { margin-bottom: 20px; }
  #scanInvoicePrint .invoice-title-row h3 { font-size: 32px; }
  #scanInvoicePrint .invoice-title-row .invoice-meta { font-size: 14px; }
  #scanInvoicePrint .invoice-billto { font-size: 14px; margin-bottom: 20px; }
  #scanInvoicePrint .invoice-billto strong { font-size: 17px; }
  #scanInvoicePrint .invoice-items { font-size: 15px; margin-bottom: 20px; }
  #scanInvoicePrint .invoice-items th { font-size: 12.5px; padding: 9px 6px; }
  #scanInvoicePrint .invoice-items td { padding: 11px 6px; }
  #scanInvoicePrint .invoice-totals { max-width: 320px; font-size: 15px; }
  #scanInvoicePrint .invoice-totals .row { padding: 6px 0; }
  #scanInvoicePrint .invoice-totals .row.grand-total { font-size: 19px; padding-top: 10px; }
  #scanInvoicePrint .invoice-footer-meta { font-size: 14px; margin-top: 26px; padding-top: 16px; }
  #scanInvoicePrint .invoice-thankyou { font-size: 14px; margin-top: 26px; }

  /* The mark is a flat-colour PNG; without this browsers drop it along with
     every other background-ish graphic and the header prints bare. */
  #scanInvoicePrint .invoice-header img {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  #scanInvoicePrint .invoice-items tr { page-break-inside: avoid; }
  #scanInvoicePrint .invoice-totals,
  #scanInvoicePrint .invoice-footer-meta { page-break-inside: avoid; }
}
</style>

<!-- Load QR Scanner Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<script>
(function(){
  var html5QrCode = null;
  var isScanning = false;
  var currentOrder = null;

  /* Print the invoice on its own sheet.

     The node is lifted to <body> for the duration of the print rather than
     printed where it sits. #scanInvoicePrint lives inside a fixed-position,
     scrolling modal overlay, and printing it in place meant pinning it with
     position:fixed - which Chrome stamps onto every sheet it generates and
     never paginates. As a direct child of <body>, with body.tw-printing
     collapsing its siblings, it flows and breaks like ordinary content.

     The comment node holds its place so it goes back exactly where it was;
     afterprint fires whether the dialog was confirmed or cancelled. */
  window.scanPrintInvoice = function(){
    var node = document.getElementById('scanInvoicePrint');
    if (!node) { window.print(); return; }

    var slot = document.createComment('scan-invoice-slot');
    node.parentNode.insertBefore(slot, node);
    document.body.appendChild(node);
    node.classList.add('tw-print-root');
    document.body.classList.add('tw-printing');

    // Undone on either signal, whichever the browser gives us, and never on a
    // timer: Chrome's preview stays open for as long as the operator reads it,
    // so a timed restore pulled the receipt back out of <body> mid-preview and
    // the sheet went blank in front of them.
    var mql = window.matchMedia ? window.matchMedia('print') : null;
    var restored = false;
    var onMql = function (e) { if (!e.matches) { restore(); } };
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
  };

  window.openScanModal = function(){
    document.getElementById('scanModal').style.display = 'flex';
  };
  window.closeScanModal = function(){
    document.getElementById('scanModal').style.display = 'none';
    scanStop();
    var resultEl = document.getElementById('scanResult');
    resultEl.style.display = 'none';
    resultEl.className = 'scan-result';
    document.getElementById('scanBackBtn').style.display = 'none';
    document.getElementById('scanScannerSection').style.display = '';
    currentOrder = null;
  };

  // Lets staff dismiss the scan modal with Escape instead of hunting for the close button.
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && document.getElementById('scanModal').style.display !== 'none') {
      closeScanModal();
    }
  });

  // Returns from the scan result view back to the live scanner, so staff
  // can process the next customer without closing/reopening the modal.
  window.scanBackToScanner = function(){
    var resultEl = document.getElementById('scanResult');
    resultEl.style.display = 'none';
    resultEl.className = 'scan-result';
    resultEl.innerHTML = '';
    document.getElementById('scanBackBtn').style.display = 'none';
    document.getElementById('scanScannerSection').style.display = '';
    currentOrder = null;
    scanStart();
  };

  window.scanStart = async function(){
    if (isScanning) return;
    var statusEl = document.getElementById('scanStatus');

    // Correct before, but it stated the rule without saying what to do about
    // it. TWCamera's text carries the https URL of this page, which is the
    // actual remedy - see shared/includes/camera-support.php.
    var camIssue = window.TWCamera ? window.TWCamera.problem()
                 : (!window.isSecureContext ? { message: 'Camera blocked: this page must be served over HTTPS or accessed via localhost.' } : null);
    if (camIssue) {
      statusEl.textContent = (window.TWCamera && window.TWCamera.text()) || camIssue.message;
      return;
    }
    if (typeof Html5Qrcode === 'undefined') {
      statusEl.textContent = 'QR scanner library failed to load.';
      return;
    }

    try {
      html5QrCode = new Html5Qrcode('reader');
      var cameras = [];
      try { cameras = await Html5Qrcode.getCameras(); } catch (e) {}
      if (!cameras || cameras.length === 0) {
        statusEl.textContent = 'No camera found on this device.';
        return;
      }
      var isVirtual = function(c){ return /obs|virtual|snap camera|manycam|droidcam|iriun|epoccam/i.test(c.label); };
      var preferred = cameras.find(function(c){ return /back|rear|environment/i.test(c.label) && !isVirtual(c); });
      if (!preferred) preferred = cameras.find(function(c){ return !isVirtual(c); });
      var cameraId = preferred ? preferred.id : cameras[cameras.length - 1].id;

      await html5QrCode.start(
        cameraId,
        { fps: 10, qrbox: { width: 220, height: 220 }, aspectRatio: 1.0 },
        function onScanSuccess(decodedText){
          scanProcessData(decodedText);
          scanStop();
        },
        function onScanFailure(){ /* fires every frame with no QR in view - ignore */ }
      );

      isScanning = true;
      document.getElementById('scanStartBtn').style.display = 'none';
      document.getElementById('scanStopBtn').style.display = 'inline-flex';
      statusEl.textContent = 'Scanner active - point camera at QR code';
    } catch (err) {
      console.error('Camera start error:', err);
      var errStr = (err && (err.name || err.message)) ? (err.name || err.message) : String(err);
      var msg = 'Unable to start camera: ' + errStr;
      if (errStr.includes('NotAllowedError')) msg = 'Camera permission denied. Check site permissions in your browser address bar.';
      else if (errStr.includes('NotFoundError')) msg = 'No camera device found.';
      else if (errStr.includes('NotReadableError')) msg = 'Camera is already in use by another app or tab.';
      statusEl.textContent = msg;
    }
  };

  window.scanStop = async function(){
    if (html5QrCode && isScanning) {
      try { await html5QrCode.stop(); html5QrCode.clear(); } catch (e) {}
    }
    isScanning = false;
    html5QrCode = null;
    var startBtn = document.getElementById('scanStartBtn');
    var stopBtn = document.getElementById('scanStopBtn');
    if (startBtn) startBtn.style.display = 'inline-flex';
    if (stopBtn) stopBtn.style.display = 'none';
    var statusEl = document.getElementById('scanStatus');
    if (statusEl) statusEl.textContent = 'Click "Start Scanner" and allow camera access';
  };

  function scanProcessData(data){
    // An order sticker (admin/order_sticker.php) carries "TWO:<64 hex>" - the
    // order's qr_token. That is a different job from the customer's pickup QR
    // this scanner was built for: it advances the order one step along its
    // status ladder instead of collecting payment. Checked first, and the
    // pickup paths below are untouched, so one button now serves both and
    // staff do not have to remember which scanner to open.
    var statusToken = scanParseStatusToken(data);
    if (statusToken) { scanStatusLookup(statusToken); return; }

    var orderId = null, pickupCode = null;
    if (data.indexOf('order:') === 0) {
      var parts = data.split(':');
      if (parts.length >= 3) { orderId = parts[1]; pickupCode = parts[2]; }
    }
    if (!orderId) {
      try {
        var parsed = new URL(data);
        if (parsed.searchParams.has('order_id')) orderId = parsed.searchParams.get('order_id');
        else if (parsed.searchParams.has('id')) orderId = parsed.searchParams.get('id');
      } catch (e) {}
    }
    if (!pickupCode) {
      var match = data.match(/(\d{6})/);
      if (match) pickupCode = match[1];
    }
    if (!orderId && !pickupCode) {
      scanShowResult('error', 'Invalid QR Code', 'The scanned QR code does not contain a recognizable order or pickup code.');
      return;
    }
    scanVerify(orderId, pickupCode, false);
  }

  /* ---- order sticker -> status advance ---------------------------------- */

  // Same shapes os_parse_scan() accepts server-side. Returning null here just
  // means "not a status label", and the pickup paths get their turn.
  function scanParseStatusToken(raw){
    var v = String(raw || '').trim();
    if (v.slice(0, 4).toUpperCase() === 'TWO:') { v = v.slice(4); }
    var m = v.match(/[?&]t=([a-fA-F0-9]{64})/);
    if (m) { v = m[1]; }
    v = v.toLowerCase();
    return /^[a-f0-9]{64}$/.test(v) ? v : null;
  }

  var scanStatusToken = null;   // token awaiting Confirm
  var scanStatusFrom  = null;   // the status the confirm screen is showing

  function scanStatusLookup(token){
    scanShowResult('loading', 'Reading label...', '');
    fetch('<?= WEB_ROOT ?>public/authenticated/api/order_scan.php?action=lookup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: token })
    })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          // A cancelled/completed order, or one still awaiting payment, comes
          // back here with the reason already written for a human.
          scanShowResult('error', d.order ? ('Order ' + d.order.number) : 'Cannot scan this label',
            d.message || 'This order cannot be moved right now.');
          return;
        }
        scanStatusToken = token;
        scanStatusFrom  = d.current.key;
        scanShowStatusConfirm(d);
      })
      .catch(function(){
        scanShowResult('error', 'System Error', 'Unable to read that label. Check the connection and scan again.');
      });
  }

  function scanShowStatusConfirm(d){
    scanEnterResultView();
    var el = document.getElementById('scanResult');
    el.className = 'scan-result success';

    var html = '<h4>Confirm status change</h4>';
    html += '<div class="info-row"><span class="label">Order</span><span>' + d.order.number + '</span></div>';
    html += '<div class="info-row"><span class="label">Customer</span><span>' + d.order.customer + '</span></div>';
    html += '<div class="info-row"><span class="label">Type</span><span>' + (d.order.delivery_method === 'pickup' ? 'Store Pickup' : 'Home Delivery') + '</span></div>';
    html += '<div class="info-row"><span class="label">Payment</span><span>' + scanPaymentMethodLabel(d.order.payment_method) + ' &middot; ' + String(d.order.payment_status || '').toUpperCase() + '</span></div>';
    html += '<div class="info-row" style="font-weight:700;color:#e5e7eb;"><span class="label">' + d.current.label + '</span><span>&rarr; ' + d.next.label + '</span></div>';
    html += '<div class="payment-collect">';
    html += '<button type="button" class="control-btn confirm-payment-btn" id="scanStatusGo" onclick="scanStatusAdvance()">Confirm &mdash; move to ' + d.next.label + '</button>';
    html += '</div>';
    el.innerHTML = html;
    el.style.display = 'block';
  }

  // The only call here that writes. `from` is sent back so the server refuses
  // if the order moved while this screen was up.
  window.scanStatusAdvance = function(){
    if (!scanStatusToken) { return; }
    var btn = document.getElementById('scanStatusGo');
    if (btn) { btn.disabled = true; btn.textContent = 'Updating...'; }

    fetch('<?= WEB_ROOT ?>public/authenticated/api/order_scan.php?action=advance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: scanStatusToken, from: scanStatusFrom })
    })
      .then(function(r){ return r.json(); })
      .then(function(d){
        scanStatusToken = null; scanStatusFrom = null;
        if (d.ok) {
          scanShowResult('success', d.new.label, d.message);
        } else {
          scanShowResult('error', 'Not updated', d.message || 'The order was not changed.');
        }
      })
      .catch(function(){
        scanStatusToken = null; scanStatusFrom = null;
        scanShowResult('error', 'System Error', 'Could not reach the server. The order was NOT updated.');
      });
  };

  function fmtMoney(n){
    var num = parseFloat(n);
    if (isNaN(num)) num = 0;
    return '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  window.scanVerify = function(orderId, pickupCode, confirmPayment, amountPaid, discountPercent){
    scanShowResult('loading', confirmPayment ? 'Confirming payment...' : 'Verifying...', '');

    fetch('<?= WEB_ROOT ?>public/authenticated/api/verify_pickup.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ order_id: orderId, pickup_code: pickupCode, confirm: !!confirmPayment, amount_paid: amountPaid, discount_percent: discountPercent })
    })
      .then(function(r){ return r.text(); })
      .then(function(text){
        var data;
        try { data = JSON.parse(text); } catch (e) {
          scanShowResult('error', 'System Error', 'Invalid server response.');
          return;
        }
        if (data.success) {
          if (confirmPayment) {
            scanShowInvoice(data.invoice);
          } else {
            currentOrder = data.order;
            scanShowOrderConfirm(data.order);
          }
        } else {
          scanShowResult('error', 'Verification Failed', data.message || 'Order not found or invalid code.');
        }
      })
      .catch(function(err){
        console.error('Verify pickup error:', err);
        scanShowResult('error', 'System Error', 'Unable to verify pickup code.');
      });
  };

  // Stops the camera (if running) and swaps the live scanner view for the
  // result view, revealing the "Back to Scanner" button.
  function scanEnterResultView(){
    scanStop();
    document.getElementById('scanScannerSection').style.display = 'none';
    document.getElementById('scanBackBtn').style.display = 'inline-flex';
  }

  function scanShowResult(type, title, message){
    scanEnterResultView();
    var el = document.getElementById('scanResult');
    el.className = 'scan-result ' + (type === 'loading' ? '' : type);
    var html = '<h4>' + title + '</h4>';
    if (message) html += '<p style="color:#94a3b8;font-size:13px;margin:0 0 8px;">' + message + '</p>';
    el.innerHTML = html;
    el.style.display = 'block';
  }

  function scanPaymentMethodLabel(pm){
    switch (String(pm || '').toLowerCase()) {
      case 'onsite': return 'Pay at Store';
      case 'cod': return 'Cash on Delivery';
      case 'gcash': return 'GCash';
      case 'paymaya': return 'Maya';
      case 'xendit': return 'Online Payment (Xendit)';
      default: return String(pm || '').toUpperCase();
    }
  }

  function scanShowOrderConfirm(order){
    var el = document.getElementById('scanResult');
    el.className = 'scan-result success';

    var itemsHtml = '';
    (order.items || []).forEach(function(it){
      itemsHtml += '<div class="info-row"><span class="label">' + (it.product_name || ('Product #' + it.product_id)) + ' x' + it.quantity + '</span><span>' + fmtMoney(it.total) + '</span></div>';
    });

    // Store Pickup orders can be either paid in cash at the counter (onsite)
    // or already paid online (gcash/paymaya/xendit) - these two need very
    // different confirmation UIs and gating.
    var paymentMethodLower = String(order.payment_method || '').toLowerCase();
    var isPrepaid = ['gcash', 'paymaya', 'xendit'].indexOf(paymentMethodLower) !== -1;
    var isPaid = order.payment_status === 'paid';

    // Store credit is spent at checkout and already deducted from
    // total_amount, so credit that covered the whole order leaves nothing to
    // collect here - no amount to key in, no change to count, and no gateway
    // payment to wait for. That case gets a plain Complete button instead of
    // the cash drawer UI. Takes precedence over isPrepaid for the same reason
    // the server does: a ₱0.00 order was never charged to the gateway.
    var orderTotal = parseFloat(order.total_amount) || 0;
    var creditApplied = parseFloat(order.credit_applied) || 0;
    var nothingToCollect = orderTotal <= 0.009;

    var html = '<h4>' + (nothingToCollect ? 'Order Found - Confirm Pickup' : 'Order Found - Confirm &amp; Collect Payment') + '</h4>';
    html += '<div class="info-row"><span class="label">Order</span><span>#' + (order.invoice_no || '') + '</span></div>';
    html += '<div class="info-row"><span class="label">Customer</span><span>' + (order.customer_name || '') + '</span></div>';
    html += '<div class="info-row"><span class="label">Payment Method</span><span>' + (nothingToCollect ? 'Store Credit' : scanPaymentMethodLabel(order.payment_method)) + '</span></div>';
    if (nothingToCollect) {
      html += '<div class="info-row"><span class="label">Payment Status</span><span class="payment-status-badge paid">PAID</span></div>';
    } else if (isPrepaid) {
      html += '<div class="info-row"><span class="label">Payment Status</span><span class="payment-status-badge ' + (isPaid ? 'paid' : 'pending') + '">' + (isPaid ? 'PAID' : 'PENDING') + '</span></div>';
    }
    html += '<div class="info-row"><span class="label">Status</span><span>' + ((order.status || '').toUpperCase()) + '</span></div>';
    html += '<div class="info-row"><span class="label">Pickup Code</span><span>' + (order.pickup_code || '') + '</span></div>';
    if (itemsHtml) html += '<div style="margin:10px 0;border-top:1px solid rgba(255,255,255,.08);padding-top:8px;">' + itemsHtml + '</div>';
    if (creditApplied > 0) {
      // total_amount is already net of credit, so the gross is reconstructed
      // here - a bare "-₱500" under a ₱0.00 subtotal reads like a mistake.
      html += '<div class="info-row"><span class="label">Order total</span><span>' + fmtMoney(orderTotal + creditApplied) + '</span></div>';
      html += '<div class="info-row"><span class="label">Store credit applied</span><span style="color:#34d399;">-' + fmtMoney(creditApplied) + '</span></div>';
      html += '<div class="info-row"><span class="label">Balance</span><span>' + fmtMoney(orderTotal) + '</span></div>';
    } else {
      html += '<div class="info-row"><span class="label">Subtotal</span><span>' + fmtMoney(order.total_amount) + '</span></div>';
    }

    html += '<div class="payment-collect">';
    if (nothingToCollect) {
      // Paid in full with store credit. One button, nothing to key in.
      html += '<div class="info-row" style="font-weight:700;color:#e5e7eb;"><span class="label">Amount Due</span><span>' + fmtMoney(0) + '</span></div>';
      html += '<div class="info-row"><span class="label">To collect</span><span style="color:#34d399;font-weight:700;">Nothing - paid with store credit</span></div>';
      html += '<button type="button" class="control-btn confirm-payment-btn" id="scanConfirmBtn" onclick="scanConfirmPayment()">Complete Order</button>';
    } else if (isPrepaid) {
      // Already paid online - nothing to collect, just confirm release of the order.
      // The server independently re-checks payment_status before completing the
      // order regardless of what this button's disabled state says.
      html += '<div class="info-row" style="font-weight:700;color:#e5e7eb;"><span class="label">Amount Due</span><span>' + fmtMoney(order.total_amount) + '</span></div>';
      if (!isPaid) {
        html += '<div class="payment-error" style="display:block;">This order has not been paid yet. The system will automatically detect confirmed payment before this can be completed - please try again once payment clears.</div>';
      }
      html += '<button type="button" class="control-btn confirm-payment-btn" id="scanConfirmBtn" onclick="scanConfirmPayment()"' + (isPaid ? '' : ' disabled') + '>Confirm Payment &amp; Complete Order</button>';
    } else {
      html += '<label for="scanDiscountPercent">Promo Discount (%)</label>';
      html += '<input type="number" id="scanDiscountPercent" step="0.01" min="0" max="100" placeholder="0">';
      html += '<div class="info-row"><span class="label">Discount</span><span id="scanDiscountAmount">' + fmtMoney(0) + '</span></div>';
      html += '<div class="info-row" style="font-weight:700;color:#e5e7eb;"><span class="label">Amount Due</span><span id="scanAmountDue">' + fmtMoney(order.total_amount) + '</span></div>';
      html += '<label for="scanAmountPaid" style="margin-top:10px;">Customer Pays</label>';
      html += '<input type="number" id="scanAmountPaid" step="0.01" min="0" placeholder="0.00">';
      html += '<div class="info-row"><span class="label">Change</span><span id="scanChangeDisplay">' + fmtMoney(0) + '</span></div>';
      html += '<div id="scanPaymentError" class="payment-error" style="display:none;"></div>';
      html += '<button type="button" class="control-btn confirm-payment-btn" id="scanConfirmBtn" onclick="scanConfirmPayment()">Confirm Payment &amp; Complete Order</button>';
    }
    html += '</div>';

    el.innerHTML = html;
    el.style.display = 'block';

    if (!isPrepaid && !nothingToCollect) {
      document.getElementById('scanDiscountPercent').addEventListener('input', scanRecalcAmounts);
      var amountInput = document.getElementById('scanAmountPaid');
      amountInput.addEventListener('input', scanRecalcAmounts);
      amountInput.focus();
    }
  }

  function scanDiscountPercentValue(){
    var v = parseFloat(document.getElementById('scanDiscountPercent').value);
    return (!isNaN(v) && v >= 0 && v <= 100) ? v : 0;
  }

  function scanRecalcAmounts(){
    if (!currentOrder) return;
    var total = parseFloat(currentOrder.total_amount) || 0;
    var pct = scanDiscountPercentValue();
    var discountAmt = Math.round(total * pct / 100 * 100) / 100;
    var due = Math.round((total - discountAmt) * 100) / 100;

    document.getElementById('scanDiscountAmount').textContent = fmtMoney(discountAmt);
    document.getElementById('scanAmountDue').textContent = fmtMoney(due);

    var paid = parseFloat(document.getElementById('scanAmountPaid').value);
    var change = (!isNaN(paid) && paid >= due) ? (paid - due) : 0;
    document.getElementById('scanChangeDisplay').textContent = fmtMoney(change);
  }

  window.scanConfirmPayment = async function(){
    if (!currentOrder) return;

    var paymentMethodLower = String(currentOrder.payment_method || '').toLowerCase();
    var isPrepaid = ['gcash', 'paymaya', 'xendit'].indexOf(paymentMethodLower) !== -1;
    var nothingToCollect = (parseFloat(currentOrder.total_amount) || 0) <= 0.009;

    if (nothingToCollect) {
      // Settled with store credit at checkout - no amount, no discount, no change.
      if (!(await uiConfirm('Complete Order #' + (currentOrder.invoice_no || currentOrder.id) + '? It was paid in full with store credit, so there is nothing to collect.'))) return;
      scanVerify(currentOrder.id, currentOrder.pickup_code, true);
      return;
    }

    if (isPrepaid) {
      // Already paid online - nothing to validate, just confirm release of the order.
      if (!(await uiConfirm('Confirm release of Order #' + (currentOrder.invoice_no || currentOrder.id) + '? This will mark it as picked up and completed.'))) return;
      scanVerify(currentOrder.id, currentOrder.pickup_code, true);
      return;
    }

    var amountInput = document.getElementById('scanAmountPaid');
    var errEl = document.getElementById('scanPaymentError');
    var raw = amountInput ? amountInput.value.trim() : '';
    var total = parseFloat(currentOrder.total_amount) || 0;
    var pct = scanDiscountPercentValue();
    var discountAmt = Math.round(total * pct / 100 * 100) / 100;
    var due = Math.round((total - discountAmt) * 100) / 100;
    var paid = parseFloat(raw);

    errEl.style.display = 'none';
    if (!raw || isNaN(paid)) {
      errEl.textContent = 'Enter the amount paid by the customer.';
      errEl.style.display = 'block';
      return;
    }
    if (paid < due) {
      errEl.textContent = 'Amount paid cannot be less than the amount due (' + fmtMoney(due) + ').';
      errEl.style.display = 'block';
      return;
    }

    if (!(await uiConfirm('Confirm payment of ' + fmtMoney(paid) + ' (change: ' + fmtMoney(paid - due) + ') for Order #' + (currentOrder.invoice_no || currentOrder.id) + '?'))) return;

    scanVerify(currentOrder.id, currentOrder.pickup_code, true, paid, pct);
  };

  function scanShowInvoice(invoice){
    var el = document.getElementById('scanResult');
    el.className = 'scan-result success';
    currentOrder = null;

    if (!invoice) {
      el.innerHTML = '<h4>Order Confirmed and Payment Successfully Processed.</h4>';
      el.style.display = 'block';
      return;
    }

    var itemsRows = '';
    (invoice.items || []).forEach(function(it){
      itemsRows += '<tr><td>' + (it.product_name || ('Product #' + it.product_id)) + '</td><td>' + it.quantity + '</td><td>' + fmtMoney(it.price) + '</td><td>' + fmtMoney(it.total) + '</td></tr>';
    });

    var hasDiscount = invoice.discount_type && invoice.discount_type !== 'none';

    var html = '<h4>Order Confirmed and Payment Successfully Processed.</h4>';
    html += '<div id="scanInvoicePrint" class="invoice-box">';

    html += '<div class="invoice-header">';
    html += '<img src="<?= WEB_ROOT ?>public/shared/img/logo-mark.png" alt="TechWorld">';
    html += '<div><p class="invoice-store-name">TechWorld Computer Store</p><p class="invoice-store-info">Unit 48C, 5th Street, Brgy. 104, East Grace Park, Caloocan, Philippines<br>0995 586 7973 &middot; techworldcomputerstore@gmail.com</p></div>';
    html += '</div>';

    html += '<div class="invoice-title-row">';
    html += '<h3>INVOICE</h3>';
    html += '<div class="invoice-meta">';
    html += '<div>Invoice #: <strong>' + (invoice.invoice_no || '') + '</strong></div>';
    html += '<div>Date: <strong>' + (invoice.processed_at || '') + '</strong></div>';
    html += '</div></div>';

    html += '<div class="invoice-billto"><span>Billed To</span><strong>' + (invoice.customer_name || '') + '</strong></div>';

    html += '<table class="invoice-items"><thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Amount</th></tr></thead><tbody>' + itemsRows + '</tbody></table>';

    html += '<div class="invoice-totals">';
    if (hasDiscount) {
      html += '<div class="row"><span>Subtotal</span><span>' + fmtMoney(invoice.subtotal) + '</span></div>';
      html += '<div class="row"><span>Discount (' + (invoice.discount_label || '') + ')</span><span>-' + fmtMoney(invoice.discount_amount) + '</span></div>';
    }
    html += '<div class="row grand-total"><span>Total</span><span>' + fmtMoney(invoice.total_amount) + '</span></div>';
    // Store credit was taken at checkout, so on a credit-settled order it is
    // the line that explains why ₱0.00 was collected at the counter.
    if ((parseFloat(invoice.credit_applied) || 0) > 0) {
      html += '<div class="row"><span>Paid by store credit</span><span>' + fmtMoney(invoice.credit_applied) + '</span></div>';
    }
    html += '<div class="row"><span>Amount Paid</span><span>' + fmtMoney(invoice.amount_paid) + '</span></div>';
    html += '<div class="row"><span>Change</span><span>' + fmtMoney(invoice.change) + '</span></div>';
    html += '</div>';

    html += '<div class="invoice-footer-meta">';
    html += '<div>Payment Method<br><strong>' + (invoice.payment_method || '') + '</strong></div>';
    html += '<div>Processed By<br><strong>' + (invoice.processed_by || '') + '</strong></div>';
    html += '</div>';

    html += '<div class="invoice-thankyou">Thank you for shopping with TechWorld!</div>';
    html += '</div>';
    html += '<button type="button" class="control-btn" style="margin-top:12px;width:100%;justify-content:center;" onclick="scanPrintInvoice()"><i class="fi fi-rs-print"></i> Print Invoice</button>';

    el.innerHTML = html;
    el.style.display = 'block';
  }

  var manualForm = document.getElementById('scanManualForm');
  if (manualForm) {
    manualForm.addEventListener('submit', function(e){
      e.preventDefault();
      var code = document.getElementById('scanPickupCode').value.trim();
      // A handheld scanner types into this box too, and it may be pointed at
      // either kind of label - so accept an order token here as well.
      var token = scanParseStatusToken(code);
      if (token) { scanStatusLookup(token); return; }
      if (!/^\d{6}$/.test(code)) {
        scanShowResult('error', 'Invalid Code', 'Enter a 6-digit pickup code, or scan an order sticker.');
        return;
      }
      scanVerify(null, code, false);
    });
  }

  var fabBtn = document.getElementById('scanFabBtn');
  if (fabBtn) { fabBtn.addEventListener('click', openScanModal); }
})();
</script>
