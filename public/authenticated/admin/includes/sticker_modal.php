<?php
/**
 * admin/includes/sticker_modal.php - in-page preview of an order's printable
 * label, with Print.
 *
 * Every "Print Sticker" button used to open order_sticker.php in a new tab,
 * which meant leaving the page, printing, then finding your way back - and on
 * a packing bench that is a lot of stray tabs. This shows the same label in a
 * modal so it can be checked before any paper is used.
 *
 * The preview is the real sticker page in an <iframe>, not a re-implementation
 * of it. That matters: order_sticker.php already owns the 2.25 x 1.25in @page
 * rule, the QR rendering and the layout, and printing the iframe prints
 * exactly that document. A second copy of the label markup here would be a
 * second thing to keep in step, and what you previewed could drift from what
 * came out of the printer.
 *
 * Usage - include once per page, then:
 *     <button type="button" onclick="openStickerModal(<?= $id ?>)">Print Sticker</button>
 */
if (!defined('STICKER_MODAL_INCLUDED')) {
    define('STICKER_MODAL_INCLUDED', true);
?>
<dialog class="stk-modal" id="stickerModal" aria-labelledby="stkTitle">
  <div class="stk-panel">
    <div class="stk-head">
      <h2 id="stkTitle">Order label</h2>
      <button type="button" class="stk-x" id="stkClose" aria-label="Close">&times;</button>
    </div>

    <div class="stk-body">
      <p class="stk-note">Check the label, then print. The sticker is sized for 2.25&Prime; &times; 1.25&Prime; label stock.</p>
      <div class="stk-frame-wrap">
        <!-- Loaded on open, not on page load: a queue page can have 20 of
             these buttons and none of them should cost a request until used. -->
        <iframe id="stkFrame" title="Label preview" scrolling="no"></iframe>
        <div class="stk-loading" id="stkLoading">Loading label&hellip;</div>
      </div>
    </div>

    <div class="stk-actions">
      <button type="button" class="stk-btn stk-ghost" id="stkCancel">Close</button>
      <button type="button" class="stk-btn stk-go" id="stkPrint">Print</button>
    </div>
  </div>
</dialog>

<style>
/* A <dialog> in the top layer, for the same reason the confirm dialog is one:
   these buttons sit inside other modals and inside a table row menu, and the
   preview has to come out on top of both without a z-index argument. */
.stk-modal {
  position: fixed; inset: 0;
  width: 100%; height: 100%; max-width: 100%; max-height: 100%;
  margin: 0; padding: 24px; border: none; box-sizing: border-box;
  background: transparent; color: inherit; overflow: auto;
}
.stk-modal[open] { display: flex; align-items: center; justify-content: center; }
.stk-modal::backdrop { background: rgba(15, 45, 77, .72); backdrop-filter: blur(4px); }

.stk-panel {
  width: 100%; max-width: 460px; margin: auto; box-sizing: border-box;
  background: var(--admin-surface-2, #16233a);
  color: var(--admin-text, #e8eef6);
  border-radius: 18px; box-shadow: 0 24px 60px rgba(0, 0, 0, .45);
  overflow: hidden;
  animation: stk-in .16s ease-out;
}
@keyframes stk-in { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }

.stk-head { display: flex; align-items: center; gap: 12px; padding: 18px 20px 0; }
.stk-head h2 { margin: 0; font-size: 17px; font-weight: 800; flex: 1; }
.stk-x { width: 32px; height: 32px; border: none; border-radius: 50%; background: transparent;
         color: var(--admin-muted, #a9b7c6); font: inherit; font-size: 22px; line-height: 1;
         cursor: pointer; display: flex; align-items: center; justify-content: center; }
.stk-x:hover { background: rgba(255, 255, 255, .07); color: var(--admin-text, #e8eef6); }

.stk-body { padding: 14px 20px 4px; }
.stk-note { margin: 0 0 14px; font-size: 13px; line-height: 1.55; color: var(--admin-muted, #a9b7c6); }

/* White ground behind the preview - the label is printed on white, so showing
   it on the panel's dark surface would misrepresent it. */
.stk-frame-wrap { position: relative; background: #fff; border-radius: 10px; padding: 10px; min-height: 150px; }
#stkFrame { width: 100%; height: 150px; border: 0; display: block; }
.stk-loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
               color: #52606f; font-size: 13px; background: #fff; border-radius: 10px; }
.stk-loading[hidden] { display: none; }

.stk-actions { display: flex; gap: 10px; padding: 16px 20px 20px; flex-wrap: wrap; }
.stk-btn { flex: 1 1 110px; min-height: 44px; padding: 11px 16px; border: none; border-radius: 10px;
           font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; text-align: center;
           display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
.stk-ghost { background: transparent; color: var(--admin-muted, #a9b7c6);
             border: 1px solid var(--admin-border, rgba(255,255,255,.16)); }
.stk-ghost:hover { color: var(--admin-text, #e8eef6); }
.stk-go { background: var(--admin-primary-2, #174871); color: #f2f3f4; }
.stk-go:disabled { opacity: .55; cursor: not-allowed; }

@media (prefers-reduced-motion: reduce) { .stk-panel { animation: none; } }
@media (max-width: 420px) { .stk-actions { flex-direction: column-reverse; } .stk-btn { width: 100%; } }
</style>

<script>
(function () {
  var dlg     = document.getElementById('stickerModal');
  var frame   = document.getElementById('stkFrame');
  var loading = document.getElementById('stkLoading');
  var btnPrint= document.getElementById('stkPrint');
  if (!dlg || !frame) { return; }

  var BASE = '<?= WEB_ROOT ?>public/authenticated/admin/order_sticker.php';
  var modal = typeof dlg.showModal === 'function';
  var lastStandaloneUrl = '';

  /**
   * @param {number|string} orderId  one order, or a comma-separated list for a batch
   */
  window.openStickerModal = function (orderId) {
    var ids = String(orderId);
    var qs = (ids.indexOf(',') !== -1 ? 'ids=' : 'id=') + encodeURIComponent(ids);

    // embed=1 drops the sticker page's own toolbar - the modal supplies the
    // buttons. No autoprint: the whole point is to look before printing.
    frame.src = BASE + '?' + qs + '&embed=1';
    // Held for the print fallback below, which needs the standalone URL.
    lastStandaloneUrl = BASE + '?' + qs;

    loading.hidden = false;
    btnPrint.disabled = true;

    if (modal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
  };

  frame.addEventListener('load', function () {
    if (!frame.src) { return; }
    loading.hidden = true;
    btnPrint.disabled = false;
    // Grow to whatever the label sheet actually needs, so a batch of five
    // scrolls inside the preview rather than being clipped to one label.
    try {
      var h = frame.contentDocument.body.scrollHeight;
      if (h > 0) { frame.style.height = Math.min(h + 8, 420) + 'px'; }
    } catch (e) { /* same-origin, but never let a sizing hiccup break Print */ }
  });

  // Prints the iframe's document, not this page - so the label's own @page
  // size applies and nothing from the admin page comes along with it.
  btnPrint.addEventListener('click', function () {
    try {
      frame.contentWindow.focus();
      frame.contentWindow.print();
    } catch (e) {
      // Very old engines refuse cross-frame print(). Nothing is shown for the
      // button to fall back to any more, so open the standalone label - which
      // prints itself on load - rather than failing silently.
      if (lastStandaloneUrl) {
        window.open(lastStandaloneUrl + '&autoprint=1', '_blank', 'noopener');
      }
    }
  });

  function close() {
    if (modal) { if (dlg.open) { dlg.close(); } } else { dlg.removeAttribute('open'); }
    // Release the preview so a closed modal is not holding a document, and so
    // reopening always re-reads the order (a status may have changed).
    frame.src = 'about:blank';
    frame.style.height = '150px';
  }

  document.getElementById('stkClose').addEventListener('click', close);
  document.getElementById('stkCancel').addEventListener('click', close);
  dlg.addEventListener('click', function (e) { if (e.target === dlg) { close(); } });
  dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
})();
</script>
<?php } ?>
