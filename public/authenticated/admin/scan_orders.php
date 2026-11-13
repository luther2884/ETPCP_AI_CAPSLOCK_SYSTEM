<?php
declare(strict_types=1);
/**
 * admin/scan_orders.php - the packing-bench scanner.
 *
 * Replaces picking a status from a dropdown with scanning the sticker on the
 * box. A scan never changes anything on its own: it looks the order up, shows
 * what the next step would be, and waits for Confirm. All the rules live
 * server-side in config/order_status.php - this page cannot advance an order
 * the API would refuse.
 *
 * Two input paths, both ending in the same lookup:
 *   USB barcode scanner - behaves as a keyboard, types the code and presses
 *     Enter. A hidden-but-focused input catches it wherever the operator is
 *     looking, which is what makes a handheld scanner usable without clicking
 *     into a box first.
 *   Phone camera - BarcodeDetector where the browser has it (Chrome/Android),
 *     falling back to jsQR over a canvas everywhere else.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';

require_login();
require_role(['admin', 'super-admin', 'owner', 'manager']);

$pageTitle = 'Scan Orders - TechWorld';
$activeNav = 'scan_orders';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<style>
.sc-wrap { max-width: 720px; margin: 0 auto; padding: 24px 20px 48px; }
.sc-wrap h1 { color: var(--admin-text); font-size: 24px; font-weight: 800; margin: 0 0 6px; }
.sc-lead { color: var(--admin-muted); font-size: 14px; line-height: 1.6; margin: 0 0 22px; max-width: 62ch; }

.sc-card { background: var(--admin-surface-2); border: 1px solid var(--admin-border); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 18px; }

.sc-modes { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.sc-mode { flex: 1 1 150px; min-height: 44px; padding: 11px 16px; border-radius: var(--radius-sm); border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-muted); font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
.sc-mode.active { background: var(--admin-primary-2); border-color: transparent; color: #f2f3f4; }

.sc-manual { display: flex; gap: 10px; flex-wrap: wrap; }
.sc-manual input { flex: 1 1 260px; min-height: 46px; padding: 12px 14px; border-radius: var(--radius-sm); border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-text); font: inherit; font-size: 15px; font-family: ui-monospace, Consolas, monospace; }
.sc-manual button { min-height: 46px; padding: 12px 24px; border: none; border-radius: var(--radius-sm); background: var(--admin-primary-2); color: #f2f3f4; font: inherit; font-weight: 700; cursor: pointer; }

#scVideoWrap { position: relative; display: none; border-radius: var(--radius-sm); overflow: hidden; background: #0b1220; aspect-ratio: 4 / 3; }
#scVideo { width: 100%; height: 100%; object-fit: cover; display: block; }
/* Aiming frame - people centre the code in it without being told to. */
#scVideoWrap::after { content: ""; position: absolute; inset: 18% 22%; border: 3px solid rgba(255,255,255,.75); border-radius: 12px; pointer-events: none; }
.sc-hint { color: var(--admin-muted); font-size: 12.5px; margin-top: 10px; line-height: 1.6; }

.sc-status { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: none; line-height: 1.55; }
.sc-status.show { display: block; }
.sc-status.ok { background: rgba(95,168,138,.16); color: var(--admin-success, #5fa88a); }
.sc-status.warn { background: rgba(214,162,58,.16); color: #d6a23a; }
.sc-status.err { background: rgba(194,104,111,.16); color: var(--admin-danger, #c2686f); }

/* Confirmation. Deliberately large and slow to dismiss - it is the last thing
   between a scan and a real status change. */
.sc-confirm { display: none; }
.sc-confirm.show { display: block; }
.sc-order-no { font-family: ui-monospace, Consolas, monospace; font-size: 22px; font-weight: 800; color: var(--admin-text); }
.sc-cust { color: var(--admin-muted); font-size: 14px; margin-top: 4px; }
.sc-move { display: flex; align-items: center; justify-content: center; gap: 18px; flex-wrap: wrap; margin: 22px 0; }
.sc-chip { padding: 10px 18px; border-radius: 999px; font-size: 14px; font-weight: 700; border: 1px solid var(--admin-border); color: var(--admin-muted); background: var(--admin-surface); }
.sc-chip.next { background: var(--admin-accent); border-color: transparent; color: #fff; }
.sc-arrow { color: var(--admin-muted); font-size: 20px; }
.sc-meta { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-bottom: 18px; }
.sc-tag { font-size: 11.5px; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; background: var(--admin-surface); border: 1px solid var(--admin-border); color: var(--admin-muted); }
.sc-actions { display: flex; gap: 12px; flex-wrap: wrap; }
.sc-actions button { flex: 1 1 150px; min-height: 50px; padding: 14px; border: none; border-radius: var(--radius-sm); font: inherit; font-size: 15px; font-weight: 800; cursor: pointer; }
.sc-go { background: var(--admin-success, #5fa88a); color: #fff; }
.sc-go:disabled { opacity: .55; cursor: not-allowed; }
.sc-cancel { background: transparent; color: var(--admin-muted); border: 1px solid var(--admin-border) !important; }

.sc-log h2 { color: var(--admin-text); font-size: 15px; font-weight: 800; margin: 0 0 12px; }
.sc-log ul { list-style: none; margin: 0; padding: 0; }
.sc-log li { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--admin-border); font-size: 13.5px; color: var(--admin-text); }
.sc-log li:last-child { border-bottom: none; }
.sc-log .t { color: var(--admin-muted); font-size: 12px; white-space: nowrap; }
.sc-log .empty { color: var(--admin-muted); font-size: 13.5px; }
@media (max-width: 767px) { .sc-wrap { padding: 16px 16px 40px; } }
</style>

<main class="container">
<div class="sc-wrap">
  <h1>Scan Orders</h1>
  <p class="sc-lead">
    Scan the sticker on a box to move it to its next step. Each scan advances one step only &mdash;
    Preparing &rarr; Packed &rarr; Ready &rarr; <span title="Pickup orders complete here instead">Shipped or Collected</span>.
    Nothing changes until you press Confirm.
  </p>

  <div id="scStatus" class="sc-status"></div>

  <!-- Confirmation. Shown in place of the scanner so the operator cannot
       scan a second box while a decision is pending. -->
  <div class="sc-card sc-confirm" id="scConfirm">
    <div class="sc-order-no" id="scOrderNo">&mdash;</div>
    <div class="sc-cust" id="scCust"></div>
    <div class="sc-meta" id="scMeta"></div>
    <div class="sc-move">
      <span class="sc-chip" id="scFrom">&mdash;</span>
      <span class="sc-arrow">&rarr;</span>
      <span class="sc-chip next" id="scTo">&mdash;</span>
    </div>
    <div class="sc-actions">
      <button type="button" class="sc-cancel" id="scCancelBtn">Cancel</button>
      <button type="button" class="sc-go" id="scConfirmBtn">Confirm</button>
    </div>
  </div>

  <div class="sc-card" id="scScanner">
    <div class="sc-modes">
      <button type="button" class="sc-mode active" id="scModeKeyboard"><i class="fi fi-rr-qr-code" aria-hidden="true"></i> Handheld / manual</button>
      <button type="button" class="sc-mode" id="scModeCamera"><i class="fi fi-rr-camera" aria-hidden="true"></i> Phone camera</button>
    </div>

    <div id="scKeyboardPane">
      <form class="sc-manual" id="scForm" autocomplete="off">
        <input type="text" id="scInput" placeholder="Scan a sticker, or paste its code" aria-label="Order label code" spellcheck="false">
        <button type="submit">Look up</button>
      </form>
      <p class="sc-hint">
        A USB barcode scanner types the code and presses Enter by itself &mdash; just scan, the box below stays focused.
      </p>
    </div>

    <div id="scVideoWrap"><video id="scVideo" playsinline muted></video></div>
    <p class="sc-hint" id="scCamHint" style="display:none;">
      Point the camera at the sticker. The camera needs HTTPS or localhost to start.
    </p>
  </div>

  <div class="sc-card sc-log">
    <h2>Scanned this session</h2>
    <ul id="scLog"><li class="empty">Nothing scanned yet.</li></ul>
  </div>
</div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jsQR/1.4.0/jsQR.min.js"></script>
<script>
(function () {
  var API = '<?= WEB_ROOT ?>public/authenticated/api/order_scan.php';

  var elStatus  = document.getElementById('scStatus');
  var elConfirm = document.getElementById('scConfirm');
  var elScanner = document.getElementById('scScanner');
  var elInput   = document.getElementById('scInput');
  var elForm    = document.getElementById('scForm');
  var elLog     = document.getElementById('scLog');
  var btnGo     = document.getElementById('scConfirmBtn');
  var btnCancel = document.getElementById('scCancelBtn');
  var video     = document.getElementById('scVideo');
  var videoWrap = document.getElementById('scVideoWrap');

  var pending = null;      // {code, from} awaiting Confirm
  var busy    = false;
  var stream  = null;
  var rafId   = null;
  var lastSeen = { code: '', at: 0 };

  function say(msg, kind) {
    elStatus.textContent = msg;
    elStatus.className = 'sc-status show ' + (kind || 'warn');
  }
  function clearSay() { elStatus.className = 'sc-status'; }

  function showScanner() {
    elConfirm.classList.remove('show');
    elScanner.style.display = '';
    pending = null;
    focusInput();
  }
  function focusInput() {
    // Keeps the handheld scanner working without the operator clicking first.
    if (elScanner.style.display !== 'none' && document.getElementById('scKeyboardPane').style.display !== 'none') {
      try { elInput.focus(); } catch (e) {}
    }
  }

  /* ---- lookup: read-only, fills the confirmation screen ---- */
  function lookup(code) {
    if (busy || !code) { return; }
    busy = true;
    clearSay();

    fetch(API + '?action=lookup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        elInput.value = '';
        if (!d.ok) {
          say(d.message || 'That label cannot be used right now.', d.code === 'cooldown' ? 'warn' : 'err');
          if (d.order) { say((d.order.number || '') + ': ' + (d.message || ''), 'warn'); }
          focusInput();
          return;
        }
        pending = { code: code, from: d.current.key };
        document.getElementById('scOrderNo').textContent = d.order.number;
        document.getElementById('scCust').textContent = d.order.customer;
        document.getElementById('scFrom').textContent = d.current.label;
        document.getElementById('scTo').textContent = d.next.label;

        var meta = document.getElementById('scMeta');
        meta.innerHTML = '';
        [
          d.order.delivery_method === 'pickup' ? 'Store pickup' : 'Home delivery',
          (d.order.payment_method || '').toUpperCase(),
          d.order.payment_status === 'paid' ? 'Paid' : 'Unpaid'
        ].forEach(function (t) {
          var s = document.createElement('span');
          s.className = 'sc-tag'; s.textContent = t; meta.appendChild(s);
        });

        elScanner.style.display = 'none';
        elConfirm.classList.add('show');
        btnGo.disabled = false;
        btnGo.focus();
      })
      .catch(function () {
        busy = false;
        say('Could not reach the server. Check the connection and scan again.', 'err');
        focusInput();
      });
  }

  /* ---- confirm: the only thing that writes ---- */
  btnGo.addEventListener('click', function () {
    if (!pending || busy) { return; }
    busy = true;
    btnGo.disabled = true;

    fetch(API + '?action=advance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: pending.code, from: pending.from })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (d.ok) {
          say(d.message, 'ok');
          addLog(d.order.number, d.new.label);
        } else {
          say(d.message || 'The order was not updated.', 'err');
        }
        showScanner();
      })
      .catch(function () {
        busy = false;
        btnGo.disabled = false;
        say('Could not reach the server. The order was NOT updated.', 'err');
      });
  });

  btnCancel.addEventListener('click', function () { clearSay(); showScanner(); });

  function addLog(number, label) {
    var empty = elLog.querySelector('.empty');
    if (empty) { empty.remove(); }
    var li = document.createElement('li');
    var left = document.createElement('span');
    left.textContent = number + ' → ' + label;
    var right = document.createElement('span');
    right.className = 't';
    right.textContent = new Date().toLocaleTimeString();
    li.appendChild(left); li.appendChild(right);
    elLog.insertBefore(li, elLog.firstChild);
  }

  /* ---- keyboard / handheld ---- */
  elForm.addEventListener('submit', function (e) {
    e.preventDefault();
    lookup(elInput.value.trim());
  });
  // Re-focus whenever attention drifts, so the next scan is never swallowed.
  document.addEventListener('click', function (e) {
    if (!elConfirm.classList.contains('show') && !e.target.closest('button, a, input')) { focusInput(); }
  });
  focusInput();

  /* ---- camera ---- */
  var modeKb = document.getElementById('scModeKeyboard');
  var modeCam = document.getElementById('scModeCamera');
  var paneKb = document.getElementById('scKeyboardPane');
  var camHint = document.getElementById('scCamHint');

  modeKb.addEventListener('click', function () {
    modeKb.classList.add('active'); modeCam.classList.remove('active');
    paneKb.style.display = ''; videoWrap.style.display = 'none'; camHint.style.display = 'none';
    stopCamera(); focusInput();
  });
  modeCam.addEventListener('click', function () {
    modeCam.classList.add('active'); modeKb.classList.remove('active');
    paneKb.style.display = 'none'; videoWrap.style.display = 'block'; camHint.style.display = 'block';
    startCamera();
  });

  function startCamera() {
    if (stream) { return; }
    // Was "This browser cannot open the camera", which blamed the browser for
    // what is nearly always the origin - and then offered a handheld scanner
    // as the only remedy for something a different URL fixes.
    var camIssue = window.TWCamera ? window.TWCamera.problem()
                 : ((!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)
                    ? { message: 'This browser cannot open the camera.' } : null);
    if (camIssue) {
      say(((window.TWCamera && window.TWCamera.text()) || camIssue.message) +
          ' You can also use a handheld scanner instead.', 'err');
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      .then(function (s) {
        stream = s;
        video.srcObject = s;
        return video.play();
      })
      .then(function () { detectLoop(); })
      .catch(function () {
        say('Could not start the camera. Allow camera access, or use a handheld scanner.', 'err');
      });
  }

  function stopCamera() {
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    video.srcObject = null;
  }

  // Native decoder where it exists (much faster, and hardware-accelerated on
  // Android); jsQR over a canvas everywhere else.
  var detector = null;
  if (window.BarcodeDetector) {
    try { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; }
  }
  var canvas = document.createElement('canvas');
  var ctx = canvas.getContext('2d', { willReadFrequently: true });

  function onDetected(text) {
    var now = Date.now();
    // The same code sits in frame for many frames; only act on it once.
    if (text === lastSeen.code && now - lastSeen.at < 3000) { return; }
    lastSeen = { code: text, at: now };
    lookup(text);
  }

  function detectLoop() {
    if (!stream) { return; }
    rafId = requestAnimationFrame(detectLoop);
    if (busy || elConfirm.classList.contains('show')) { return; }
    if (video.readyState !== video.HAVE_ENOUGH_DATA) { return; }

    if (detector) {
      detector.detect(video)
        .then(function (codes) { if (codes && codes.length) { onDetected(codes[0].rawValue); } })
        .catch(function () { detector = null; });   // fall through to jsQR next frame
      return;
    }
    if (typeof jsQR !== 'function') { return; }
    canvas.width = video.videoWidth; canvas.height = video.videoHeight;
    if (!canvas.width || !canvas.height) { return; }
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
    var found = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
    if (found && found.data) { onDetected(found.data); }
  }

  window.addEventListener('pagehide', stopCamera);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
