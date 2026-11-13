<?php
/**
 * shared/includes/ui-dialog.php - in-page replacements for window.confirm()
 * and window.alert().
 *
 * The browser's own dialogs are titled with the origin ("localhost says…"),
 * can't be styled, look like a phishing warning, and block the whole tab.
 * This provides the same two decisions in the site's own chrome.
 *
 * Two ways to use it, matching the two shapes already in this codebase:
 *
 *   1. Declarative, for links and forms that used
 *      onclick="return confirm('…')":
 *
 *        <a href="…" data-confirm="Delete this address?">Delete</a>
 *        <form … data-confirm="Cancel this order?">
 *
 *      A delegated listener intercepts the click/submit, asks, and then
 *      replays the original action only if the answer was yes. Nothing else
 *      about the element changes.
 *
 *   2. Programmatic, for the `if (!confirm(…)) return;` guard inside a
 *      handler. Mark the function `async` and await it - the rest of the body
 *      is untouched:
 *
 *        async function removeItem(id) {
 *          if (!(await uiConfirm('Remove this item from cart?'))) return;
 *          …
 *        }
 *
 * window.alert is replaced outright, since alert() has no return value to
 * preserve; uiAlert() is available when you want to wait for the dismissal.
 *
 * It is a real <dialog> opened with showModal(), which puts it in the
 * browser's top layer. That is what guarantees it centers on the viewport on
 * every page in the system: the top layer is painted outside the normal box
 * tree, so no ancestor transform/filter/backdrop-filter, no stacking context
 * and no page stylesheet can reposition or clip it. The plain fixed-overlay
 * version this replaced kept getting pulled into whichever header it was
 * included inside, and rendered as a strip across the top of the page.
 */
?>
<dialog class="uidlg" id="uiDialog" role="alertdialog" aria-labelledby="uiDialogTitle" aria-describedby="uiDialogBody">
  <div class="uidlg-panel">
    <div class="uidlg-head">
      <button type="button" class="uidlg-x" id="uiDialogClose" aria-label="Close">&times;</button>
      <div class="uidlg-icon" id="uiDialogIcon" aria-hidden="true"><i class="fi fi-rr-interrogation"></i></div>
    </div>
    <div class="uidlg-content">
      <h2 class="uidlg-title" id="uiDialogTitle">Are you sure?</h2>
      <p class="uidlg-body" id="uiDialogBody"></p>
      <!-- Review mode only: the values about to be written, laid out so they
           can be read against what was typed. Empty and hidden otherwise. -->
      <dl class="uidlg-review" id="uiDialogReview" hidden></dl>
      <div class="uidlg-actions">
        <button type="button" class="uidlg-btn uidlg-btn-ghost" id="uiDialogCancel">Cancel</button>
        <button type="button" class="uidlg-btn uidlg-btn-go" id="uiDialogOk">Confirm</button>
      </div>
    </div>
  </div>
</dialog>

<style>
/* Geometry mirrors admin.css's .admin-nav-modal / .admin-nav-modal-dialog
   pair - the centering this was asked to match: the outer element fills the
   viewport and centers a fixed-width card inside it.

   Here the <dialog> itself is that outer element. The UA's own dialog styles
   (a shrink-to-fit box with `margin:auto` and a 2px inset border) have to be
   cleared first, or it renders as a small bordered box. */
.uidlg{
  position:fixed;
  inset:0;
  width:100%; height:100%;
  max-width:100%; max-height:100%;
  margin:0; padding:24px; border:none; box-sizing:border-box;
  background:transparent; color:inherit;
  overflow:auto; overscroll-behavior:contain;
}
/* A <dialog> is display:none until it is open, so this is the only display
   rule needed - there is no `hidden` attribute to coordinate with any more. */
.uidlg[open]{
  display:flex; align-items:center; justify-content:center;
}
/* The scrim. ::backdrop is in the top layer too, so it covers the sidebar,
   sticky headers and the page-level modals without a z-index race. The navy
   matches the deep ground the dashboard's cards float on. */
.uidlg::backdrop{
  background:rgba(9,26,45,.72);
  backdrop-filter:blur(4px);
}
/* The card is a deliberately self-contained surface - it does NOT pick up the
   --admin- or --bg- tokens. It is painted in the customer dashboard's palette
   instead: the warm vanilla panel (#fff4ef) the stat cards, orders table and
   quick actions all use, hairline navy borders, deep navy ink, and the same
   drop shadow + inner top highlight. That way the card reads as one family on
   every page in the system rather than shifting per area.

   Per kind, two colours drive everything: --uidlg-band (the wash behind the
   icon, one step warmer/tinted) and --uidlg-accent (the icon disc). */
.uidlg-panel{
  --uidlg-band:#fae7dd;      /* the .action-card hover tint */
  --uidlg-accent:#a05f80;    /* the dashboard mauve */
  width:100%; max-width:340px; margin:auto; box-sizing:border-box;
  text-align:center; overflow:hidden;
  max-height:calc(100vh - 48px); overflow-y:auto;
  background:#fff4ef; color:#17324a;
  border:1px solid rgba(23,72,113,.14);
  border-radius:22px;
  box-shadow:0 18px 44px rgba(3,8,16,.5), inset 0 1px 0 rgba(255,255,255,.85);
  animation:uidlg-in .16s ease-out;
}
/* Rose and mint are the dashboard's own cancelled/completed status colours,
   opened up into washes light enough to carry black-ish text above them. */
.uidlg[data-kind="danger"] .uidlg-panel{ --uidlg-band:#f9dde5; --uidlg-accent:#e0567a; }
.uidlg[data-kind="info"]   .uidlg-panel{ --uidlg-band:#d9f0e4; --uidlg-accent:#2fb98d; }
/* Review is not a warning - nothing has gone wrong and nothing is being
   destroyed. Periwinkle keeps it distinct from both the rose danger wash and
   the mint of a plain notice. */
.uidlg[data-kind="review"] .uidlg-panel{ --uidlg-band:#e2e3f7; --uidlg-accent:#7f6ae0; }
@keyframes uidlg-in{ from{ opacity:0; transform:translateY(8px) scale(.98);} to{ opacity:1; transform:none; } }

/* The band. The icon hangs off its bottom edge, so the band itself needs no
   bottom padding and the content below reserves the overhang instead. */
.uidlg-head{
  position:relative; height:86px;
  background:var(--uidlg-band);
  border-bottom:1px solid rgba(23,72,113,.08);
}
.uidlg-x{
  position:absolute; top:8px; right:8px;
  width:30px; height:30px; padding:0; border:none; border-radius:50%;
  background:transparent; color:#17324a; opacity:.5;
  font:inherit; font-size:21px; line-height:1; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:opacity .15s ease, background-color .15s ease;
}
.uidlg-x:hover{ opacity:1; background:rgba(23,72,113,.08); }
.uidlg-icon{
  position:absolute; left:50%; bottom:-27px; transform:translateX(-50%);
  width:54px; height:54px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  font-size:22px; color:#fff;
  background:var(--uidlg-accent);
  /* the ring is the panel colour, not white - it reads as the disc punching
     through the band rather than as a stray white outline */
  box-shadow:0 0 0 5px #fff4ef, 0 6px 16px rgba(3,8,16,.18);
}
.uidlg-content{ padding:44px 26px 26px; }
.uidlg-title{ margin:0 0 10px; font-size:27px; font-weight:800; letter-spacing:-.02em; color:#17324a; }
.uidlg-body{
  margin:0 0 22px; font-size:13px; line-height:1.6;
  color:#55677c; overflow-wrap:anywhere; white-space:pre-line;
}
/* ---- Review mode -----------------------------------------------------
   A yes/no question can be centred and small. A "check this is right"
   question cannot: the whole job is comparing labels to values, and that
   only works left-aligned, in a column, with room for a long product name.
   So review mode widens the card and squares up the text. */
.uidlg[data-mode="review"] .uidlg-panel{ max-width:460px; }
.uidlg[data-mode="review"] .uidlg-title{ font-size:22px; }
.uidlg[data-mode="review"] .uidlg-body{ margin-bottom:14px; }
.uidlg-review{
  display:grid; grid-template-columns:auto 1fr; gap:0;
  margin:0 0 20px; text-align:left;
  border:1px solid rgba(23,72,113,.14); border-radius:14px;
  background:rgba(255,255,255,.6); overflow:hidden;
}
.uidlg-review[hidden]{ display:none; }
.uidlg-review dt,
.uidlg-review dd{
  margin:0; padding:9px 14px; font-size:13px; line-height:1.5;
  border-top:1px solid rgba(23,72,113,.08);
}
.uidlg-review dt:first-of-type,
.uidlg-review dt:first-of-type + dd{ border-top:none; }
.uidlg-review dt{
  color:#55677c; font-weight:600; white-space:nowrap;
  background:rgba(23,72,113,.035);
}
.uidlg-review dd{
  color:#17324a; font-weight:700; overflow-wrap:anywhere;
}
/* A row that is actually changing is the one worth reading twice, so it is
   the only row that gets colour. Rows left alone stay quiet on purpose -
   highlighting everything would highlight nothing. */
.uidlg-review .uidlg-rv-changed{ background:rgba(224,86,122,.07); }
.uidlg-review .uidlg-rv-was{
  display:inline; color:#8a7f88; font-weight:600; text-decoration:line-through;
  text-decoration-thickness:1px; margin-right:6px;
}
.uidlg-review .uidlg-rv-arrow{ color:#a05f80; margin-right:6px; font-weight:700; }
.uidlg-review .uidlg-rv-note{
  display:block; margin-top:3px; font-size:11.5px; font-weight:600;
  color:#b4603a; text-transform:none; letter-spacing:0;
}
.uidlg-review .uidlg-rv-empty{ color:#8899a8; font-weight:600; font-style:italic; }

.uidlg-actions{ display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
.uidlg-btn{
  min-width:118px; min-height:40px; padding:10px 24px; border:none; border-radius:999px;
  cursor:pointer; font:inherit; font-size:13.5px; font-weight:700;
  transition:filter .15s ease, transform .15s ease, background-color .15s ease, box-shadow .3s ease;
}
.uidlg-btn:hover{ filter:brightness(1.05); }
.uidlg-btn:active{ transform:scale(.98); }
.uidlg-btn-ghost{
  background:transparent; color:#55677c;
  border:1px solid rgba(23,72,113,.18);
}
.uidlg-btn-ghost:hover{ color:#17324a; background:rgba(23,72,113,.05); }
/* The dashboard's .btn-primary gradient carries the action on every kind -
   the colour signal is the band, so tinting the button per kind too would
   read as two competing accents. */
.uidlg-btn-go{ background:linear-gradient(120deg, #e0567a, #7f6ae0); color:#fff; }
.uidlg-btn-go:hover{ box-shadow:0 12px 26px rgba(224,86,122,.42); }
/* Alerts have nothing to cancel. */
.uidlg[data-mode="alert"] .uidlg-btn-ghost{ display:none; }
@media (prefers-reduced-motion: reduce){ .uidlg-panel{ animation:none; } }
@media (max-width:420px){
  .uidlg-actions{ flex-direction:column-reverse; }
  .uidlg-btn{ width:100%; }
}

/* Fallback for engines without <dialog>.showModal() (pre-2022 Safari). No top
   layer there, so it is an ordinary fixed overlay again and needs its own
   scrim plus a z-index above the sidebar (2000) and the page modals (9999). */
.uidlg.uidlg-fallback[open]{
  z-index:100000;
  background:rgba(15,45,77,.72);
  -webkit-backdrop-filter:blur(4px);
  backdrop-filter:blur(4px);
}
</style>


<script>
(function () {
  var root = document.getElementById('uiDialog');
  if (!root || window.uiConfirm) { return; }   // include-once guard

  var titleEl  = document.getElementById('uiDialogTitle');
  var bodyEl   = document.getElementById('uiDialogBody');
  var reviewEl = document.getElementById('uiDialogReview');
  var okBtn    = document.getElementById('uiDialogOk');
  var cancelBtn= document.getElementById('uiDialogCancel');
  var iconEl   = document.getElementById('uiDialogIcon');
  var closeBtn = document.getElementById('uiDialogClose');
  var settle   = null;      // resolver for the dialog currently on screen
  var lastFocus = null;
  var prevOverflow = '';    // page scroll state to put back on close

  var canTopLayer = typeof root.showModal === 'function';
  if (!canTopLayer) { root.classList.add('uidlg-fallback'); }

  /* The include lands wherever each area's header happens to end. Moving it to
     the end of <body> costs nothing and keeps the fallback path - which has no
     top layer to protect it - out of any header's containing block. */
  function reparent() {
    if (document.body && root.parentNode !== document.body) {
      document.body.appendChild(root);
    }
  }
  if (document.body) { reparent(); }
  else { document.addEventListener('DOMContentLoaded', reparent); }

  var ICONS = {
    ask:    'fi fi-rr-interrogation',
    danger: 'fi fi-rr-exclamation-triangle',
    info:   'fi fi-rr-info',
    review: 'fi fi-rr-check-double'
  };

  /* Build the review table.
     Every value is written with textContent, never innerHTML: these rows carry
     product names and prices typed by a person moments ago, and the dialog
     whose entire purpose is "confirm this is what you meant" must show exactly
     those characters rather than interpret them as markup.

     Row shape: {label, value, was, note}. `was` present and different from
     `value` marks the row as changed and prints `old -> new`. */
  function renderReview(rows) {
    reviewEl.textContent = '';
    if (!rows || !rows.length) { reviewEl.hidden = true; return; }

    rows.forEach(function (row) {
      var value   = row.value == null ? '' : String(row.value);
      var had     = Object.prototype.hasOwnProperty.call(row, 'was') && row.was != null;
      var was     = had ? String(row.was) : '';
      var changed = had && was !== value;

      var dt = document.createElement('dt');
      dt.textContent = row.label == null ? '' : String(row.label);

      var dd = document.createElement('dd');
      if (changed) {
        var oldSpan = document.createElement('span');
        oldSpan.className = 'uidlg-rv-was';
        oldSpan.textContent = was;
        var arrow = document.createElement('span');
        arrow.className = 'uidlg-rv-arrow';
        arrow.textContent = '→';
        dd.appendChild(oldSpan);
        dd.appendChild(arrow);
      }
      if (value === '') {
        var blank = document.createElement('span');
        blank.className = 'uidlg-rv-empty';
        blank.textContent = row.emptyText || 'Not set';
        dd.appendChild(blank);
      } else {
        dd.appendChild(document.createTextNode(value));
      }
      if (row.note) {
        var note = document.createElement('span');
        note.className = 'uidlg-rv-note';
        note.textContent = String(row.note);
        dd.appendChild(note);
      }

      if (changed) {
        dt.classList.add('uidlg-rv-changed');
        dd.classList.add('uidlg-rv-changed');
      }
      reviewEl.appendChild(dt);
      reviewEl.appendChild(dd);
    });
    reviewEl.hidden = false;
  }

  /* Words that mean the action destroys something - used to pick the red
     treatment and a truthful button label without every call site having to
     say so. */
  function kindFor(message) {
    return /\b(delete|remove|clear|cancel|archive|revoke|destroy|discard|wipe)\b/i.test(message || '')
      ? 'danger' : 'ask';
  }

  function close(result) {
    if (canTopLayer) {
      // close() on an already-closed dialog is a no-op, but guard anyway so
      // the `cancel` handler below cannot re-enter this.
      if (root.open) { root.close(); }
    } else {
      root.removeAttribute('open');
    }
    // Restore whatever the page had set (the mobile search overlay uses this
    // too), rather than blindly clearing it.
    document.body.style.overflow = prevOverflow;
    prevOverflow = '';
    document.removeEventListener('keydown', onKey, true);
    var done = settle; settle = null;
    if (lastFocus && typeof lastFocus.focus === 'function') { try { lastFocus.focus(); } catch (e) {} }
    lastFocus = null;
    if (done) { done(result); }
  }

  function onKey(e) {
    // Escape on a modal <dialog> fires `cancel` instead (handled below); this
    // still covers the fallback path, and Enter everywhere.
    if (e.key === 'Escape') { e.preventDefault(); close(false); }
    else if (e.key === 'Enter') {
      // Review mode deliberately does NOT take Enter as yes. It opens from a
      // form the admin was just typing in, where Enter is how the form was
      // submitted in the first place - a held or repeated key would confirm
      // the very check the dialog exists to force. Let Enter act on whichever
      // button has focus (Cancel, by default) instead.
      if (root.dataset.mode === 'review') { return; }
      e.preventDefault();
      close(true);
    }
  }

  function open(opts) {
    // A second dialog while one is open would strand the first promise.
    if (settle) { close(false); }
    var mode = opts.mode || 'confirm';
    var kind = opts.kind || (mode === 'alert' ? 'info'
                          : mode === 'review' ? 'review'
                          : kindFor(opts.message));

    root.dataset.mode = mode;
    root.dataset.kind = kind;
    iconEl.innerHTML = '<i class="' + (ICONS[kind] || ICONS.ask) + '"></i>';
    titleEl.textContent = opts.title || (mode === 'alert' ? 'Heads up'
                                       : mode === 'review' ? 'Check before saving'
                                       : 'Are you sure?');
    bodyEl.textContent = opts.message || '';
    // An empty paragraph still occupies its bottom margin; review mode leans
    // on the table and often has nothing to say above it.
    bodyEl.hidden = !opts.message;
    renderReview(mode === 'review' ? opts.rows : null);
    okBtn.textContent = opts.okText || (mode === 'alert' ? 'OK'
                                      : mode === 'review' ? 'Yes, save it'
                                      : (kind === 'danger' ? 'Yes, continue' : 'Confirm'));
    cancelBtn.textContent = opts.cancelText || (mode === 'review' ? 'Go back and edit' : 'Cancel');

    lastFocus = document.activeElement;
    reparent();
    prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';   // the page must not scroll behind it
    if (canTopLayer) { root.showModal(); } else { root.setAttribute('open', ''); }
    document.addEventListener('keydown', onKey, true);
    // Focus the safe choice, so a stray Enter/Space cannot confirm a delete.
    (mode === 'alert' ? okBtn : cancelBtn).focus();

    return new Promise(function (resolve) { settle = resolve; });
  }

  okBtn.addEventListener('click', function () { close(true); });
  cancelBtn.addEventListener('click', function () { close(false); });
  /* The corner X is the same answer as Cancel - and the only way out of an
     alert other than OK, so it is not hidden in that mode. */
  closeBtn.addEventListener('click', function () { close(false); });
  /* The dialog element is the scrim and the card is its child, so a click
     whose target is the element itself landed outside the card. */
  root.addEventListener('click', function (e) { if (e.target === root) { close(false); } });
  /* Escape is handled by the browser on a modal dialog: it fires `cancel` and
     would otherwise close without ever settling the promise. */
  root.addEventListener('cancel', function (e) { e.preventDefault(); close(false); });

  /* ---- public API ------------------------------------------------------ */
  window.uiConfirm = function (message, opts) {
    opts = opts || {};
    opts.message = message; opts.mode = 'confirm';
    return open(opts);
  };
  window.uiAlert = function (message, opts) {
    opts = opts || {};
    opts.message = message; opts.mode = 'alert';
    return open(opts);
  };

  /* uiReview - "is this right?" rather than "are you sure?".
     For saves where the risk is not that the admin clicked the wrong button
     but that they typed the wrong number: a product going into the catalogue,
     a price being changed. It prints the values back and waits.

       await uiReview({
         title: 'Add this product?',
         rows: [{ label: 'Price', value: '₱1,250.00', was: '₱1,150.00' }]
       })

     `was` on a row prints `old -> new` and tints the row; leave it off for a
     field that is not changing, or when there is no previous value. */
  window.uiReview = function (opts) {
    opts = opts || {};
    opts.mode = 'review';
    return open(opts);
  };

  /* window.alert has no return value to preserve, so it can be swapped
     outright. Anything that genuinely needs to wait for the dismissal should
     await uiAlert() instead. */
  window.alert = function (message) { window.uiAlert(String(message)); };

  /* ---- declarative [data-confirm] -------------------------------------- */
  /* Replaces onclick="return confirm('…')" on links, buttons and forms:
     swallow the interaction, ask, then replay the original action verbatim
     only if the answer was yes. The replay sets a flag so this handler lets
     the second pass through untouched. */
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el || el.__uiConfirmed) { return; }
    if (el.tagName === 'FORM') { return; }        // handled by submit below
    e.preventDefault();
    e.stopPropagation();
    window.uiConfirm(el.getAttribute('data-confirm'), {
      okText: el.getAttribute('data-confirm-ok') || undefined
    }).then(function (ok) {
      if (!ok) { return; }
      el.__uiConfirmed = true;
      if (el.tagName === 'A' && el.href) { window.location.href = el.href; }
      else { el.click(); }
      el.__uiConfirmed = false;
    });
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-confirm]');
    if (!form || form.__uiConfirmed) { return; }
    e.preventDefault();
    window.uiConfirm(form.getAttribute('data-confirm'), {
      okText: form.getAttribute('data-confirm-ok') || undefined
    }).then(function (ok) {
      if (!ok) { return; }
      form.__uiConfirmed = true;
      // requestSubmit keeps validation and the submitter button; submit()
      // is the fallback for older engines.
      if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
      else { form.submit(); }
    });
  }, true);
})();
</script>
