<?php
// admin/includes/new_order_alert.php
//
// The alert that replaced the sign-in chime. A sound used to play once when
// an admin logged in, which told them something they already knew. This
// plays when a customer actually places an order - the one moment the panel
// has something to say that the admin could not have known.
//
// Real-time, not polled: api/admin_order_stream.php holds an SSE connection
// open and pushes the order the moment the row lands, so an admin deep in
// another page (or another tab) hears it without anything being refreshed.
// See that file for why it is a stream and how it stays cheap.
//
// Included from admin/includes/header.php, so every admin page gets it.

if (!function_exists('isAdmin') || !isLoggedIn() || !isAdmin()) { return; }

// Baseline. Everything already in the table at page load is history, not
// news - without this the first connection would announce the entire order
// book. The stream only ever sends ids above this one.
$__noaSince = 0;
try {
    $__noaSince = (int)getConnection()->query("SELECT COALESCE(MAX(id), 0) FROM orders")->fetchColumn();
} catch (Throwable $e) {
    // A failed baseline must not take the page down with it. 0 would replay
    // history, so on failure the client is told to ask the server for its
    // own baseline instead (see the `since` handling in the stream).
    $__noaSince = -1;
}
?>
<div class="noa-stack" id="noaStack" role="region" aria-label="New order alerts">
  <div class="noa-bar" id="noaBar" hidden>
    <span class="noa-bar-count" id="noaBarCount">0 new</span>
    <button type="button" class="noa-bar-btn" id="noaMute" aria-pressed="false" title="Mute the new-order sound">
      <i class="fi fi-rr-volume" aria-hidden="true"></i>
    </button>
    <button type="button" class="noa-bar-btn" id="noaClear" title="Dismiss all">
      <i class="fi fi-rr-cross" aria-hidden="true"></i>
    </button>
  </div>
</div>

<!-- Screen readers get the order through a live region rather than through
     the cards: the cards animate in and out and would be announced at the
     wrong moment, if at all. -->
<p class="noa-sr" id="noaLive" aria-live="assertive" aria-atomic="true"></p>

<style>
  .noa-stack{
    position:fixed;right:18px;bottom:18px;z-index:2600;
    display:flex;flex-direction:column;align-items:flex-end;gap:8px;
    max-width:min(360px, calc(100vw - 36px));pointer-events:none;
  }
  .noa-stack > *{pointer-events:auto;}

  /* Only appears once there is something to count - a permanent mute button
     in the corner of every page would be clutter the other 99% of the time. */
  .noa-bar{
    display:flex;align-items:center;gap:6px;
    padding:4px 4px 4px 12px;border-radius:999px;
    background:var(--admin-surface-2);box-shadow:var(--neu-raised-sm);
    color:var(--admin-muted);font-size:12px;font-weight:700;
    letter-spacing:.03em;text-transform:uppercase;
  }
  .noa-bar[hidden]{display:none;}
  .noa-bar-btn{
    display:flex;align-items:center;justify-content:center;
    width:26px;height:26px;border:none;border-radius:50%;cursor:pointer;
    background:transparent;color:var(--admin-muted);font-size:12px;
  }
  .noa-bar-btn:hover{background:var(--admin-surface);color:var(--admin-text);}
  .noa-bar-btn[aria-pressed="true"]{color:var(--admin-danger);}

  .noa-card{
    position:relative;
    display:flex;gap:10px;align-items:flex-start;width:100%;
    /* Left padding leaves room for the dot below. */
    padding:12px 12px 12px 22px;border-radius:12px;
    background:var(--admin-surface-2);box-shadow:var(--neu-raised);
    border:1px solid var(--admin-border);
    color:var(--admin-text);font-size:13px;line-height:1.45;
    animation:noa-in .28s cubic-bezier(.2,.8,.3,1) both;
  }
  /* A dot, not a border bar: the bar reads as a severity stripe, which is
     not what a new order is. Decorative only - the card already says what
     it is in text, so nothing is lost to a screen reader here. */
  .noa-card::before{
    content:'';position:absolute;left:9px;top:17px;
    width:6px;height:6px;border-radius:50%;background:#c23a63;
  }
  .noa-card.noa-leaving{animation:noa-out .2s ease-in both;}

  /* Queue-page refresh bar - slim, above the table, never auto-acting. */
  .noa-refresh{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin:0 0 12px;padding:9px 14px;border-radius:10px;
    background:var(--admin-surface-2);border:1px solid var(--admin-border);
    font-size:13px;font-weight:600;color:var(--admin-text);
  }
  .noa-refresh-n{display:inline-flex;align-items:center;gap:8px;}
  .noa-refresh-n::before{
    content:'';width:6px;height:6px;border-radius:50%;background:#c23a63;flex:none;
  }
  .noa-refresh-btn{
    border:1px solid var(--admin-border);background:var(--admin-surface);
    color:var(--admin-text);font:inherit;font-weight:700;font-size:12.5px;
    padding:6px 12px;border-radius:8px;cursor:pointer;
  }
  .noa-refresh-btn:hover{background:var(--admin-surface-2);}

  /* The row a toast's View link points at. Soft flash, then back to normal -
     long enough to find the row, short enough not to look like a state. */
  .noa-flash{
    animation:noa-flash 2s ease-out 1;
  }
  @keyframes noa-flash{
    0%,70%{background:#fdecf1;}
    100%{background:transparent;}
  }
  @media (prefers-reduced-motion: reduce){
    .noa-flash{animation:none;outline:2px solid #c23a63;outline-offset:-2px;}
  }

  /* Shown when ?highlight names an order that is not in the current view. */
  .noa-missing{
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    margin:0 0 12px;padding:9px 14px;border-radius:10px;
    background:var(--admin-surface-2);border:1px solid var(--admin-border);
    font-size:13px;color:var(--admin-text);
  }
  .noa-missing a{font-weight:700;color:var(--accent-cyan,#174871);}
  @keyframes noa-in{from{opacity:0;transform:translateY(10px) scale(.97);}to{opacity:1;transform:none;}}
  @keyframes noa-out{to{opacity:0;transform:translateX(12px);}}
  @media (prefers-reduced-motion: reduce){
    .noa-card,.noa-card.noa-leaving{animation:none;}
  }

  .noa-icon{
    flex:0 0 auto;width:30px;height:30px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,var(--admin-accent),var(--admin-primary-2));
    color:#fff;font-size:13px;
  }
  .noa-body{min-width:0;flex:1 1 auto;display:flex;flex-direction:column;gap:2px;}
  .noa-title{font-weight:700;font-size:13px;}
  .noa-meta{color:var(--admin-muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .noa-pills{display:flex;flex-wrap:wrap;gap:5px;margin-top:4px;}
  .noa-pill{
    padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;
    background:var(--admin-surface);color:var(--admin-muted);
  }
  .noa-pill--paid{background:rgba(16,185,129,.14);color:#0f9d6e;}
  .noa-pill--unpaid{background:rgba(245,158,11,.14);color:#b57505;}
  .noa-open{
    margin-top:8px;align-self:flex-start;
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 12px;border-radius:var(--radius-sm, 10px);
    background:var(--admin-primary-2);color:#f2f3f4;
    font:inherit;font-size:12px;font-weight:700;text-decoration:none;
  }
  .noa-open:hover{filter:brightness(1.12);color:#f2f3f4;text-decoration:none;}
  .noa-close{
    flex:0 0 auto;display:flex;align-items:center;justify-content:center;
    width:24px;height:24px;border:none;border-radius:50%;cursor:pointer;
    background:transparent;color:var(--admin-muted);font-size:11px;
  }
  .noa-close:hover{background:var(--admin-surface);color:var(--admin-text);}

  .noa-sr{
    position:absolute;width:1px;height:1px;margin:-1px;padding:0;
    overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;
  }
  @media (max-width: 560px){ .noa-stack{right:10px;bottom:10px;left:10px;max-width:none;} }
</style>

<script>
(function () {
  var WEB_ROOT   = '<?= WEB_ROOT ?>';
  var STREAM_URL = WEB_ROOT + 'public/authenticated/api/admin_order_stream.php';
  var MUTE_KEY   = 'techwrld.admin.newOrderMuted';
  var MAX_CARDS  = 4;      // beyond this the oldest card retires; the count keeps rising
  var CARD_TTL   = 30000;  // a card that nobody touches steps aside after 30s
  var POLL_MS    = 10000;  // fallback cadence, only if the stream will not hold

  var stack = document.getElementById('noaStack');
  var bar   = document.getElementById('noaBar');
  var count = document.getElementById('noaBarCount');
  var live  = document.getElementById('noaLive');
  var mute  = document.getElementById('noaMute');
  var clear = document.getElementById('noaClear');
  if (!stack) { return; }

  var cursor  = <?= (int)$__noaSince ?>;   // -1 means "server, pick the baseline"
  var unseen  = 0;
  var baseTitle = document.title;
  var muted   = false;
  try { muted = localStorage.getItem(MUTE_KEY) === '1'; } catch (e) {}

  // ---------------------------------------------------------------------
  // Sound
  //
  // Synthesised rather than loaded from a file: it is two notes, it has to
  // be able to fire at any moment without a network round-trip, and an
  // alert that silently fails because an mp3 404'd is worse than no alert.
  // ---------------------------------------------------------------------
  var AudioCtx = window.AudioContext || window.webkitAudioContext;
  var ctx = null;

  function audio() {
    if (!AudioCtx) { return null; }
    if (!ctx) { try { ctx = new AudioCtx(); } catch (e) { return null; } }
    // Browsers hand back a suspended context until the page has been
    // interacted with. resume() is a no-op once it is already running.
    if (ctx.state === 'suspended') { ctx.resume().catch(function () {}); }
    return ctx;
  }

  // ---------------------------------------------------------------------
  // The sound file, with the synthesised chime behind it.
  //
  // A file is warmer than an oscillator and is what the shop actually chose,
  // but it is also the part that can fail: a 404, a codec the browser will
  // not take, or a play() the autoplay policy rejects. Any of those falls
  // through to tone() below, so the alert is never silent - which was the
  // original reason this file synthesised its own sound in the first place.
  //
  // Preloaded once at startup, not per order, so the first order of the day
  // is not the one that waits on a network round trip.
  // ---------------------------------------------------------------------
  var WAV_URL   = WEB_ROOT + 'public/shared/assets/sounds/mixkit-gold-coin-prize-1999.wav';
  var wav       = null;
  var wavBroken = false;

  try {
    wav = new Audio(WAV_URL);
    wav.preload = 'auto';
    wav.volume  = 0.8;
    // 'error' covers a missing file or an unplayable one. Either way we stop
    // reaching for it and let the oscillator carry the alert.
    wav.addEventListener('error', function () { wavBroken = true; });
  } catch (e) { wavBroken = true; }

  // A page the admin has not clicked yet cannot make sound at all, so the
  // first gesture anywhere unlocks the context. Capture phase and once, so
  // it never interferes with whatever they actually clicked.
  function unlock() {
    audio();
    // Priming the element on the same gesture: a muted play/pause is enough
    // to mark it user-activated, so the first real order plays immediately
    // instead of being refused and dropping to the fallback tone.
    if (wav && !wavBroken) {
      try {
        var wasMuted = wav.muted;
        wav.muted = true;
        var p = wav.play();
        if (p && typeof p.then === 'function') {
          p.then(function () { wav.pause(); wav.currentTime = 0; wav.muted = wasMuted; })
           .catch(function () { wav.muted = wasMuted; });
        } else { wav.pause(); wav.currentTime = 0; wav.muted = wasMuted; }
      } catch (e) {}
    }
    document.removeEventListener('pointerdown', unlock, true);
    document.removeEventListener('keydown', unlock, true);
  }
  document.addEventListener('pointerdown', unlock, true);
  document.addEventListener('keydown', unlock, true);

  function note(ac, freq, startAt, length, peak) {
    var osc = ac.createOscillator();
    var gain = ac.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(freq, startAt);
    // Shaped envelope rather than a raw on/off, which clicks.
    gain.gain.setValueAtTime(0.0001, startAt);
    gain.gain.exponentialRampToValueAtTime(peak, startAt + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, startAt + length);
    osc.connect(gain).connect(ac.destination);
    osc.start(startAt);
    osc.stop(startAt + length + 0.02);
  }

  function chime() {
    if (muted) { return; }
    // Same gesture unlock as the oscillator: until the admin has touched the
    // page, neither an <audio> element nor an AudioContext may make a sound.
    if (!wavBroken && wav) {
      try {
        wav.currentTime = 0;
        var p = wav.play();
        if (p && typeof p.catch === 'function') {
          // Blocked or failed mid-play - fall back for this and every later
          // alert rather than retrying a thing the browser has refused.
          p.catch(function () { wavBroken = true; tone(); });
        }
        return;
      } catch (e) { wavBroken = true; }
    }
    tone();
  }

  function tone() {
    if (muted) { return; }
    var ac = audio();
    if (!ac || ac.state !== 'running') { return; }
    var t = ac.currentTime;
    // Rising major sixth, twice. Rising reads as "something arrived" rather
    // than "something failed"; repeating it is what carries across a room.
    note(ac, 880.00, t,        0.16, 0.22);
    note(ac, 1318.51, t + 0.13, 0.26, 0.20);
    note(ac, 880.00, t + 0.42, 0.16, 0.18);
    note(ac, 1318.51, t + 0.55, 0.30, 0.17);
  }

  // ---------------------------------------------------------------------
  // Attention outside this page
  // ---------------------------------------------------------------------
  function paintTitle() {
    document.title = unseen > 0 ? '(' + unseen + ') ' + baseTitle : baseTitle;
  }

  function desktopNote(order) {
    // Only if the admin has already granted it. No prompt is raised here -
    // a permission dialog thrown at someone mid-task is its own interruption.
    if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
    if (!document.hidden) { return; }
    try {
      var n = new Notification('New order - ' + order.amount, {
        body: order.customer + ' . ' + order.invoice,
        tag: 'techwrld-order-' + order.id,
        icon: WEB_ROOT + 'public/shared/img/Logo.ico'
      });
      n.onclick = function () { window.focus(); n.close(); };
    } catch (e) {}
  }

  window.addEventListener('focus', function () { unseen = 0; paintTitle(); });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { unseen = 0; paintTitle(); }
  });

  // ---------------------------------------------------------------------
  // Cards
  // ---------------------------------------------------------------------
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function retire(card) {
    if (!card || card.dataset.leaving) { return; }
    card.dataset.leaving = '1';
    card.classList.add('noa-leaving');
    setTimeout(function () {
      if (card.parentNode) { card.parentNode.removeChild(card); }
      syncBar();
    }, 220);
  }

  // ---------------------------------------------------------------------
  // Queue-page refresh bar
  //
  // Only on the two pages that show a table of these orders. The table is
  // NEVER reloaded automatically: the admin may be halfway through assigning
  // a rider or typing a tracking number, and pulling the rows out from under
  // that is worse than a slightly stale list. This just says how many have
  // landed and offers the reload.
  //
  // Reload keeps the current query string, so the filter, search and page the
  // admin was on all survive it.
  // ---------------------------------------------------------------------
  var QUEUE_PAGES = ['home_delivery_orders.php', 'store_pickup_orders.php'];
  var onQueuePage = QUEUE_PAGES.indexOf(
    (location.pathname.split('/').pop() || '').toLowerCase()
  ) !== -1;
  var queueNew = 0;
  var queueBar = null;

  function queueBarShow() {
    if (!onQueuePage) { return; }
    queueNew++;
    if (!queueBar) {
      queueBar = document.createElement('div');
      queueBar.className = 'noa-refresh';
      queueBar.innerHTML =
        '<span class="noa-refresh-n"></span>' +
        '<button type="button" class="noa-refresh-btn">Refresh list</button>';
      queueBar.querySelector('.noa-refresh-btn').addEventListener('click', function () {
        location.reload();   // same URL = same filter, same page
      });
      // Above the table, inside the page's own container so it inherits the
      // width rather than floating over the content.
      var anchor = document.querySelector('.hdo-table-wrap, table');
      if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(queueBar, anchor);
      } else {
        (document.querySelector('main') || document.body).prepend(queueBar);
      }
    }
    queueBar.querySelector('.noa-refresh-n').textContent =
      queueNew === 1 ? '1 new order' : queueNew + ' new orders';
  }

  function syncBar() {
    var n = stack.querySelectorAll('.noa-card').length;
    bar.hidden = n === 0;
    count.textContent = n + ' new';
  }

  function card(order) {
    var el = document.createElement('div');
    el.className = 'noa-card';
    // The destination depends on how the order is being fulfilled; sending a
    // pickup order to the delivery queue would just mean a second click.
    // The queue for this order's fulfilment method, plus the order itself so
    // that page can find the row, flash it and open its existing modal. The
    // id already rides along in the stream payload, so nothing server-side
    // had to change to carry it.
    var href = WEB_ROOT + 'public/authenticated/admin/' +
      (order.fulfilment === 'pickup' ? 'store_pickup_orders.php' : 'Home_Delivery_Orders.php') +
      '?highlight=' + encodeURIComponent(order.id);
    el.innerHTML =
      '<span class="noa-icon"><i class="fi fi-rr-shopping-bag" aria-hidden="true"></i></span>' +
      '<span class="noa-body">' +
        '<span class="noa-title">New order ' + esc(order.invoice) + '</span>' +
        '<span class="noa-meta">' + esc(order.customer) + ' &middot; ' + esc(order.amount) + '</span>' +
        '<span class="noa-pills">' +
          '<span class="noa-pill">' + (order.fulfilment === 'pickup' ? 'Store pickup' : 'Home delivery') + '</span>' +
          '<span class="noa-pill ' + (order.paid ? 'noa-pill--paid' : 'noa-pill--unpaid') + '">' +
            esc(order.method) + (order.paid ? ' &middot; paid' : ' &middot; unpaid') +
          '</span>' +
        '</span>' +
        '<a class="noa-open" href="' + href + '">View</a>' +
      '</span>' +
      '<button type="button" class="noa-close" aria-label="Dismiss"><i class="fi fi-rr-cross" aria-hidden="true"></i></button>';

    el.querySelector('.noa-close').addEventListener('click', function () { retire(el); });
    stack.appendChild(el);

    while (stack.querySelectorAll('.noa-card').length > MAX_CARDS) {
      retire(stack.querySelector('.noa-card'));
    }
    // Hovering a card is someone reading it - do not pull it away mid-read.
    var ttl = setTimeout(function () { retire(el); }, CARD_TTL);
    el.addEventListener('mouseenter', function () { clearTimeout(ttl); });
    syncBar();
    return el;
  }

  function announce(order) {
    card(order);
    unseen += 1;
    paintTitle();
    chime();
    queueBarShow();
    desktopNote(order);
    live.textContent = 'New order from ' + order.customer + ', ' + order.amount + '.';
    // The sidebar counters are the other half of the same news; refreshing
    // them here means the badge and the toast never disagree.
    ['updatePendingOrdersBadge', 'updateHomeDeliveryBadge', 'updateStorePickupBadge'].forEach(function (fn) {
      if (typeof window[fn] === 'function') { try { window[fn](); } catch (e) {} }
    });
  }

  mute.setAttribute('aria-pressed', muted ? 'true' : 'false');
  mute.innerHTML = '<i class="fi fi-rr-volume' + (muted ? '-slash' : '') + '" aria-hidden="true"></i>';
  mute.addEventListener('click', function () {
    muted = !muted;
    try { localStorage.setItem(MUTE_KEY, muted ? '1' : '0'); } catch (e) {}
    mute.setAttribute('aria-pressed', muted ? 'true' : 'false');
    mute.title = muted ? 'Unmute the new-order sound' : 'Mute the new-order sound';
    mute.innerHTML = '<i class="fi fi-rr-volume' + (muted ? '-slash' : '') + '" aria-hidden="true"></i>';
    if (!muted) { chime(); } // confirm it is actually audible, right now
  });
  clear.addEventListener('click', function () {
    Array.prototype.forEach.call(stack.querySelectorAll('.noa-card'), retire);
    unseen = 0;
    paintTitle();
  });

  // ---------------------------------------------------------------------
  // Transport: ONE stream per browser, shared with every other admin tab
  //
  // This used to open an EventSource per tab, which is what stopped the alert
  // being real-time. A browser allows only six concurrent HTTP/1.1
  // connections per origin, and an SSE connection holds one open for its
  // entire life. With five or six admin tabs open - an ordinary day in this
  // panel: both order queues, the dashboard, a product page, a report - every
  // slot is taken by a stream that never finishes, and from then on nothing
  // else to this origin gets through either: the badge polls, the next page
  // load and the seventh tab's own stream all queue behind them. The access
  // log for this install peaked at 14 of these at once. The alert stopped
  // being instant exactly when the admin had the panel most open.
  //
  // So exactly one tab holds the connection - the "leader", elected through a
  // short localStorage lease - and relays each order to the rest over a
  // BroadcastChannel. Every other tab holds NO connection at all and shows
  // the toast the moment the leader does. The lease is renewed on a timer and
  // expires on its own, so a leader that is closed, crashes or is frozen by
  // the browser is replaced within a couple of seconds by whichever tab
  // notices first.
  // ---------------------------------------------------------------------
  var LEASE_KEY  = 'techwrld.admin.noa.leader';
  var CURSOR_KEY = 'techwrld.admin.noa.cursor';
  var CHANNEL    = 'techwrld-admin-noa';
  var LEASE_TTL  = 6000;   // a lease older than this is up for grabs
  var RENEW_MS   = 2000;   // leader renews, followers check, on this beat
  var TAB_ID     = String(Date.now()) + '-' + Math.random().toString(36).slice(2, 10);

  // Both of these can be missing (private windows, old browsers, storage
  // blocked by policy). Without them there is no way to coordinate tabs, so
  // the old behaviour - every tab streams for itself - is the fallback. It is
  // worse for the connection pool but it is never silent.
  var canShare = (function () {
    if (typeof BroadcastChannel !== 'function') { return false; }
    try {
      var k = LEASE_KEY + '.probe';
      localStorage.setItem(k, '1');
      localStorage.removeItem(k);
      return true;
    } catch (e) { return false; }
  })();

  var chan = null;
  if (canShare) {
    try { chan = new BroadcastChannel(CHANNEL); } catch (e) { chan = null; canShare = false; }
  }

  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  // The cursor is shared so a NEW leader resumes where the old one stopped
  // rather than from its own page-load baseline - that is what used to make a
  // reconnecting tab re-announce orders it had already shown, or miss ones
  // that landed during the handover.
  function sharedCursor() {
    var v = parseInt(lsGet(CURSOR_KEY) || '', 10);
    return isNaN(v) ? cursor : Math.max(cursor, v);
  }
  function setSharedCursor(id) {
    if (typeof id !== 'number' || isNaN(id)) { return; }
    cursor = Math.max(cursor, id);
    lsSet(CURSOR_KEY, String(cursor));
  }

  // Orders already shown IN THIS TAB. Per tab, not shared: every tab has to
  // show every order, and the only thing being guarded against here is the
  // same order arriving twice down two paths (a stream replay after a
  // handover, say).
  var seen = Object.create(null);

  function handleOrder(order, fromStream) {
    if (!order || typeof order.id === 'undefined') { return; }
    var key = String(order.id);
    if (seen[key]) { return; }
    seen[key] = 1;
    if (fromStream) {
      setSharedCursor(parseInt(order.id, 10));
      // Relay before rendering: the other tabs should not wait on this tab's
      // animation work to hear about it.
      if (chan) { try { chan.postMessage({ type: 'order', order: order }); } catch (e) {} }
    }
    announce(order);
  }

  if (chan) {
    chan.onmessage = function (ev) {
      var m = ev && ev.data;
      if (!m) { return; }
      if (m.type === 'order') { handleOrder(m.order, false); }
      // A leader stepping down on its way out: take over now instead of
      // waiting for its lease to go stale.
      else if (m.type === 'vacated') { setTimeout(electionTick, 0); }
    };
  }

  // ---- leader election ------------------------------------------------
  var isLeader = !canShare;   // no coordination available: everyone streams
  var es = null;
  var failures = 0;
  var pollTimer = null;

  function leaseHolder() {
    var raw = lsGet(LEASE_KEY);
    if (!raw) { return null; }
    try {
      var l = JSON.parse(raw);
      if (!l || !l.id || typeof l.at !== 'number') { return null; }
      return (Date.now() - l.at) > LEASE_TTL ? null : l;   // stale = nobody
    } catch (e) { return null; }
  }

  function writeLease() {
    lsSet(LEASE_KEY, JSON.stringify({ id: TAB_ID, at: Date.now() }));
  }

  function electionTick() {
    if (!canShare) { return; }
    var held = leaseHolder();

    if (isLeader) {
      // Another tab took the lease from under us (our renew was late enough
      // to look dead). Yield rather than run a second connection.
      if (held && held.id !== TAB_ID) { stepDown(); return; }
      writeLease();
      return;
    }

    if (!held) {
      writeLease();
      // Re-read: if two tabs wrote in the same instant, last write wins and
      // only one of them sees its own id come back.
      var now = leaseHolder();
      if (now && now.id === TAB_ID) { becomeLeader(); }
    }
  }

  function becomeLeader() {
    if (isLeader) { return; }
    isLeader = true;
    failures = 0;
    connect();
  }

  function stepDown() {
    isLeader = false;
    if (es) { try { es.close(); } catch (e) {} es = null; }
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // ---- the connection itself (leader only) ----------------------------
  function startPolling() {
    if (pollTimer) { return; }
    pollTimer = setInterval(function () {
      if (!isLeader) { return; }
      fetch(STREAM_URL + '?mode=poll&since=' + sharedCursor(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.success) { return; }
          setSharedCursor(d.cursor);
          (d.orders || []).forEach(function (o) { handleOrder(o, true); });
        })
        .catch(function () {});
    }, POLL_MS);
  }

  function connect() {
    if (!isLeader) { return; }
    if (es) { try { es.close(); } catch (e) {} es = null; }
    if (!window.EventSource) { startPolling(); return; }
    try {
      es = new EventSource(STREAM_URL + '?since=' + sharedCursor());
    } catch (e) {
      startPolling();
      return;
    }

    es.addEventListener('ready', function (ev) {
      failures = 0;
      try { setSharedCursor(JSON.parse(ev.data).cursor); } catch (e) {}
    });

    es.addEventListener('new-order', function (ev) {
      var order;
      try { order = JSON.parse(ev.data); } catch (e) { return; }
      handleOrder(order, true);
    });

    // The stream retires itself roughly every minute on purpose, so this is
    // the expected end - not a reason to start distrusting the transport.
    es.addEventListener('cycle', function (ev) {
      try { setSharedCursor(JSON.parse(ev.data).cursor); } catch (e) {}
    });

    es.onerror = function () {
      // EventSource reconnects by itself and carries Last-Event-ID with it,
      // so a single error is not worth acting on. Only a stream that cannot
      // hold at all gets demoted to polling.
      if (es && es.readyState === EventSource.CLOSED) {
        failures += 1;
        if (failures >= 3) { stepDownToPolling(); }
        else { setTimeout(function () { if (isLeader) { connect(); } }, 3000); }
      }
    };
  }

  function stepDownToPolling() {
    if (es) { try { es.close(); } catch (e) {} es = null; }
    startPolling();
  }

  // Closing the stream when the tab is hidden would defeat the point - the
  // admin being on another tab is exactly when they need to be told. It is
  // only closed when the page is actually going away, so the PHP worker is
  // released immediately instead of waiting out its lifetime cap - and the
  // lease is handed back in the same breath, so another tab picks the stream
  // up right away rather than after the lease times out.
  window.addEventListener('pagehide', function () {
    if (isLeader && canShare) {
      try { localStorage.removeItem(LEASE_KEY); } catch (e) {}
      if (chan) { try { chan.postMessage({ type: 'vacated' }); } catch (e) {} }
    }
    stepDown();
    if (chan) { try { chan.close(); } catch (e) {} }
  });

  // Seed the shared cursor from this page's baseline before anything can
  // connect, so the first leader never replays history as news.
  setSharedCursor(cursor);

  if (canShare) {
    electionTick();                       // claim it now if nobody holds it
    setInterval(electionTick, RENEW_MS);  // renew as leader, watch as follower
  } else {
    connect();
  }
})();
</script>
