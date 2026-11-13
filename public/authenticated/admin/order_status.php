<?php
declare(strict_types=1);
/**
 * admin/order_status.php - one order's label, its status history, and the
 * manual override.
 *
 * The override is deliberately here and not in the queue's inline dropdown:
 * it is the escape hatch for fixing a mistake, it requires a written reason,
 * and it should feel like a separate, deliberate act rather than another
 * dropdown to click through. Everything it does is recorded in
 * order_status_history as source 'manual', next to the scans and the webhook.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';

require_login();
require_role(['admin', 'super-admin', 'owner', 'manager']);

$pdo = getConnection();
$orderId = (int)($_GET['id'] ?? 0);
$notice = null;
$noticeKind = 'ok';

/* --- manual override ---------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'manual_status') {
    $target = (string)($_POST['new_status'] ?? '');
    $reason = (string)($_POST['reason'] ?? '');
    $postedId = (int)($_POST['order_id'] ?? 0);

    $res = os_manual_set($pdo, $postedId, $target, (int)getUserId(),
        trim((string)(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''))) ?: null,
        $reason);

    $notice = $res['message'];
    $noticeKind = $res['ok'] ? 'ok' : 'err';

    // An override that skipped a serial still SUCCEEDED - os_manual_set() is
    // the escape hatch and does not refuse - but it must not look like an
    // ordinary status change. The warning is shown next to the result, and the
    // audit row records the override explicitly rather than burying it in the
    // reason text.
    if ($res['ok'] && !empty($res['warning'])) {
        $notice = $res['message'] . ' ' . $res['warning'];
        $noticeKind = 'warn';
    }

    if ($res['ok']) {
        logAudit(
            empty($res['warning']) ? 'order_status_manual' : 'order_status_manual_no_serial',
            'orders', $postedId,
            ['status' => $res['old']],
            ['status' => $res['new'], 'source' => 'manual', 'reason' => $reason,
             'serial_override' => $res['warning'] ?? null]
        );
    }
}

$stmt = $pdo->prepare("
    SELECT o.*, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,''))), ''), 'Guest') AS customer_name,
           u.email AS customer_email
    FROM orders o LEFT JOIN users u ON u.id = o.user_id
    WHERE o.id = ? LIMIT 1");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

/* --- the live feed -------------------------------------------------------
   The page answers its own poll rather than calling a new endpoint: same
   file, same require_login()/require_role() above, and - the point of doing
   it this way - the SAME renderers the first paint uses, so what appears
   after a scan cannot drift from what the page drew before one.

   This has to come before the header/navigation includes below: once those
   have run, the response is an HTML page and can no longer be JSON. */
if (($_GET['poll'] ?? '') === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    /* A ceiling on the heartbeat, sized for it: one tab polls every 4s, which
       is 150 requests in 10 minutes, and an admin may have several orders
       open at once. 900 leaves room for that and still refuses a script
       hammering the file. Same shape and reasoning as the support page's
       poll bucket in api/support.php. Required here rather than at the top of
       the file: only this branch uses it, and the HTML page below should not
       pay for an include it never calls. */
    require_once __DIR__ . '/../../../config/rate_limit.php';
    rl_guard('order_status_poll', 900, 600);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Order not found.']);
        exit;
    }

    $pMethod  = (string)($order['delivery_method'] ?: 'delivery');
    $pCurrent = (string)$order['status'];
    $pHistory = os_history($pdo, (int)$order['id']);
    $pNext    = os_next_scan_status($pCurrent, $pMethod);

    echo json_encode([
        'ok'           => true,
        'status'       => $pCurrent,
        'label'        => os_label($pCurrent, $pMethod),
        // What the client compares against to decide whether anything
        // actually moved: the status itself plus the id of the last history
        // row, so a change recorded with the same status (a manual override
        // re-stating it, say) still registers.
        'fingerprint'  => osp_fingerprint($pCurrent, $pHistory),
        'where_html'   => osp_where_html($pCurrent, $pMethod, $pNext),
        'history_html' => osp_history_html($pHistory, $pMethod),
    ]);
    exit;
}

$pageTitle = 'Order Status - TechWorld';
$activeNav = '';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';

$method  = $order ? (string)($order['delivery_method'] ?: 'delivery') : 'delivery';
$current = $order ? (string)$order['status'] : '';
$number  = $order ? ($order['invoice_no'] ?: ('ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT))) : '';
$history = $order ? os_history($pdo, (int)$order['id']) : [];
$token   = $order ? os_qr_token($pdo, (int)$order['id']) : null;
$nextStep = $order ? os_next_scan_status($current, $method) : ['next' => null, 'reason' => ''];

/** Badge colour per source, so the history reads at a glance. */
function osp_source_class(string $s): string {
    return ['scan' => 'src-scan', 'webhook' => 'src-webhook', 'manual' => 'src-manual',
            'courier' => 'src-courier', 'system' => 'src-system'][$s] ?? 'src-system';
}

/** Status + last history row: changes exactly when something happened. */
function osp_fingerprint(string $current, array $history): string {
    $last = $history ? end($history) : null;
    return $current . '|' . ($last ? (string)$last['id'] : '0');
}

/**
 * The body of the "Where it is" card - the next-step line and the ladder.
 * Both the first paint and the poll above call this, so there is one place
 * that decides how a step is drawn.
 */
function osp_where_html(string $current, string $method, array $nextStep): string {
    ob_start();
    ?>
        <p class="note">
          <?php if ($nextStep['next']): ?>
            The next scan moves it to <strong><?= htmlspecialchars(os_label((string)$nextStep['next'], $method)) ?></strong>.
          <?php else: ?>
            <?= htmlspecialchars((string)$nextStep['reason']) ?>
          <?php endif; ?>
        </p>
        <?php
          $ladder = os_ladder($method);
          $pos = array_search($current, $ladder, true);
        ?>
        <?php if ($current === 'cancelled'): ?>
          <div class="op-rung now" style="flex:1 1 100%;">Cancelled</div>
        <?php elseif ($current === 'packed'): ?>
          <?php /* Packed is no longer a rung, so an order still sitting on it
                   has no place on the ladder to light up. Say where it is
                   rather than drawing a ladder with nothing current on it. */ ?>
          <div class="op-ladder">
            <?php foreach ($ladder as $rung): ?>
              <?php $done = in_array($rung, ['awaiting_payment', 'preparing'], true); ?>
              <div class="op-rung<?= $done ? ' done' : '' ?>"><?= htmlspecialchars(os_label($rung, $method)) ?></div>
            <?php endforeach; ?>
          </div>
          <p class="note" style="margin:10px 0 0;">
            This order is marked <strong>Packed</strong>, a step that is no longer part of the
            process. Its next scan moves it to <strong><?= htmlspecialchars(os_label('ready', $method)) ?></strong>.
          </p>
        <?php else: ?>
          <div class="op-ladder">
            <?php foreach ($ladder as $i => $rung): ?>
              <?php
                $cls = 'op-rung';
                if ($pos !== false && $i < $pos) { $cls .= ' done'; }
                if ($rung === $current) { $cls .= ' now'; }
              ?>
              <div class="<?= $cls ?>"><?= htmlspecialchars(os_label($rung, $method)) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

/** The status-history list. Same renderer for the first paint and the poll. */
function osp_history_html(array $history, string $method): string {
    ob_start();
    ?>
        <?php if (empty($history)): ?>
          <p class="op-empty">
            Nothing recorded yet. History starts from the first change after scanning was introduced &mdash;
            changes made before that are in the general audit log.
          </p>
        <?php else: ?>
          <ul class="op-hist">
            <?php foreach (array_reverse($history) as $h): ?>
              <li>
                <span class="when"><?= htmlspecialchars(date('M j, g:i:s A', strtotime((string)$h['created_at']))) ?></span>
                <span class="what">
                  <?= $h['old_status'] ? htmlspecialchars(os_label((string)$h['old_status'], $method)) . ' &rarr; ' : '' ?>
                  <strong><?= htmlspecialchars(os_label((string)$h['new_status'], $method)) ?></strong>
                  <?php if ($h['actor_name'] || $h['actor_id']): ?>
                    <div class="who">by <?= htmlspecialchars((string)($h['actor_name'] ?: ('user #' . $h['actor_id']))) ?><?= $h['ip'] ? ' &middot; ' . htmlspecialchars((string)$h['ip']) : '' ?></div>
                  <?php endif; ?>
                  <?php if ($h['reason']): ?>
                    <div class="why">&ldquo;<?= htmlspecialchars((string)$h['reason']) ?>&rdquo;</div>
                  <?php endif; ?>
                </span>
                <span class="op-src <?= osp_source_class((string)$h['source']) ?>"><?= htmlspecialchars((string)$h['source']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}
?>
<style>
.op-wrap { max-width: 960px; margin: 0 auto; padding: 24px 20px 48px; }
.op-head { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; margin-bottom: 20px; }
.op-head h1 { color: var(--admin-text); font-size: 24px; font-weight: 800; margin: 0 0 4px; font-family: ui-monospace, Consolas, monospace; }
.op-head p { color: var(--admin-muted); font-size: 14px; margin: 0; }
.op-head .spacer { margin-left: auto; }
.op-btn { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; padding: 10px 18px; border-radius: var(--radius-sm); border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-text); font: inherit; font-weight: 700; font-size: 14px; text-decoration: none; cursor: pointer; }
.op-btn.primary { background: var(--admin-primary-2); border-color: transparent; color: #f2f3f4; }

.op-notice { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 14px; font-weight: 600; margin-bottom: 18px; }
.op-notice.ok { background: rgba(95,168,138,.16); color: var(--admin-success, #5fa88a); }
.op-notice.err { background: rgba(194,104,111,.16); color: var(--admin-danger, #c2686f); }
/* The change went through but skipped something it should not have - an
   override of the serial gate. Amber, not green: it must not read as a clean
   success, and not red either, because the status really did change. */
.op-notice.warn { background: rgba(224,168,54,.18); color: #8a5a00; }

.op-grid { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 18px; align-items: start; }
@media (max-width: 800px) { .op-grid { grid-template-columns: minmax(0, 1fr); } }

.op-card { background: var(--admin-surface-2); border: 1px solid var(--admin-border); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 18px; }
.op-card h2 { color: var(--admin-text); font-size: 16px; font-weight: 800; margin: 0 0 4px; }
.op-card .note { color: var(--admin-muted); font-size: 13px; line-height: 1.6; margin: 0 0 16px; }

.op-ladder { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 4px; }
.op-rung { flex: 1 1 90px; text-align: center; padding: 9px 6px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 700; background: var(--admin-surface); border: 1px solid var(--admin-border); color: var(--admin-muted); }
.op-rung.done { background: rgba(95,168,138,.16); border-color: transparent; color: var(--admin-success, #5fa88a); }
.op-rung.now { background: var(--admin-accent); border-color: transparent; color: #fff; }

.op-qr { text-align: center; }
.op-qr .box { width: 190px; height: 190px; margin: 0 auto 14px; background: #fff; padding: 10px; border-radius: 12px; }
.op-qr .box img, .op-qr .box canvas { width: 100% !important; height: 100% !important; }
.op-qr code { display: block; font-size: 10.5px; word-break: break-all; color: var(--admin-muted); margin-bottom: 14px; line-height: 1.5; }

.op-hist { list-style: none; margin: 0; padding: 0; }
.op-hist li { display: flex; gap: 14px; padding: 13px 0; border-bottom: 1px solid var(--admin-border); }
.op-hist li:last-child { border-bottom: none; }
.op-hist .when { color: var(--admin-muted); font-size: 12px; white-space: nowrap; min-width: 118px; font-variant-numeric: tabular-nums; }
.op-hist .what { flex: 1; min-width: 0; color: var(--admin-text); font-size: 13.5px; line-height: 1.55; }
.op-hist .who { color: var(--admin-muted); font-size: 12.5px; margin-top: 3px; }
.op-hist .why { color: var(--admin-muted); font-size: 12.5px; margin-top: 3px; font-style: italic; overflow-wrap: anywhere; }
.op-src { font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; padding: 3px 8px; border-radius: 999px; align-self: flex-start; white-space: nowrap; }
.src-scan { background: rgba(103,232,249,.16); color: #67e8f9; }
.src-webhook { background: rgba(95,168,138,.18); color: #5fa88a; }
.src-manual { background: rgba(214,162,58,.18); color: #d6a23a; }
.src-courier { background: rgba(167,118,147,.2); color: var(--admin-accent); }
.src-system { background: var(--admin-surface); color: var(--admin-muted); }
.op-empty { color: var(--admin-muted); font-size: 13.5px; line-height: 1.6; }

.op-form label { display: block; color: var(--admin-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; margin: 0 0 6px; }
.op-form select, .op-form textarea { width: 100%; padding: 11px 13px; border-radius: var(--radius-sm); border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-text); font: inherit; font-size: 14px; margin-bottom: 14px; }
.op-form textarea { min-height: 84px; resize: vertical; }
.op-warn { border-left: 3px solid #d6a23a; background: var(--admin-surface); padding: 12px 16px; border-radius: var(--radius-sm); color: var(--admin-muted); font-size: 13px; line-height: 1.6; margin-bottom: 16px; }

/* The live marker. Deliberately small and quiet: it is there to say the page
   keeps itself current, not to decorate the card. It dims while the tab is in
   the background, which is also when the polling stops. */
.op-live { display: inline-flex; align-items: center; gap: 6px; margin-left: 8px; vertical-align: middle; font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; color: var(--admin-success, #5fa88a); }
.op-live::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: opLivePulse 2.4s ease-in-out infinite; }
.op-live.is-idle { color: var(--admin-muted); }
.op-live.is-idle::before { animation: none; }
@keyframes opLivePulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
@media (prefers-reduced-motion: reduce) { .op-live::before { animation: none; } }

/* A changed card gets one short highlight, so a status that moved while the
   admin was looking elsewhere on the page is noticed. */
.op-flash { animation: opFlash 1.1s ease-out 1; }
@keyframes opFlash { from { background: rgba(95,168,138,.22); } to { background: var(--admin-surface-2); } }
@media (prefers-reduced-motion: reduce) { .op-flash { animation: none; } }
</style>

<main class="container">
<div class="op-wrap">

<?php if (!$order): ?>
  <div class="op-card"><p class="op-empty">No order with that id. Open this page from an order in the delivery or pickup queue.</p></div>
<?php else: ?>

  <?php if ($notice): ?>
    <div class="op-notice <?= $noticeKind ?>"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>

  <?php /* Filled in by the live script when the order moves while this page
           is open, so a scan at the counter announces itself here instead of
           only changing the ladder quietly. */ ?>
  <div class="op-notice ok" id="opLiveNotice" role="status" aria-live="polite" hidden></div>

  <div class="op-head">
    <div>
      <h1><?= htmlspecialchars($number) ?></h1>
      <p><?= htmlspecialchars((string)$order['customer_name']) ?>
        &middot; <?= $method === 'pickup' ? 'Store pickup' : 'Home delivery' ?>
        &middot; <?= htmlspecialchars(strtoupper((string)$order['payment_method'])) ?>
        &middot; <?= $order['payment_status'] === 'paid' ? 'Paid' : 'Unpaid (' . htmlspecialchars((string)$order['payment_status']) . ')' ?>
      </p>
    </div>
    <div class="spacer"></div>
    <button type="button" class="op-btn primary" onclick="openStickerModal(<?= (int)$order['id'] ?>)">
      <i class="fi fi-rr-qr-code" aria-hidden="true"></i> Print Sticker
    </button>
    <a class="op-btn" href="<?= WEB_ROOT ?>public/authenticated/admin/scan_orders.php">Scan page</a>
  </div>

  <div class="op-grid">
    <div>
      <?php /* data-* on the card is what the live script reads: where to poll,
               and what it is currently showing. */ ?>
      <div class="op-card" id="opLive"
           data-order-id="<?= (int)$order['id'] ?>"
           data-fingerprint="<?= htmlspecialchars(osp_fingerprint($current, $history)) ?>"
           data-status="<?= htmlspecialchars($current) ?>">
        <h2>Where it is <span class="op-live" id="opLiveDot" title="This page updates itself - no refresh needed">Live</span></h2>
        <div id="opWhereBody"><?= osp_where_html($current, $method, $nextStep) ?></div>
      </div>

      <div class="op-card">
        <h2>Status history</h2>
        <p class="note">Every change to this order, and what made it.</p>
        <div id="opHistoryBody"><?= osp_history_html($history, $method) ?></div>
      </div>

      <div class="op-card">
        <h2>Manual override</h2>
        <p class="note">For fixing mistakes only. Scanning is the normal way an order moves.</p>
        <div class="op-warn">
          This sets the status directly and can skip or reverse steps. It is recorded as a manual
          change against your name, with the reason you give, and the reason is shown in the history above.
        </div>
        <form method="POST" class="op-form" data-confirm="Change this order's status manually?" data-confirm-ok="Change status">
          <input type="hidden" name="action" value="manual_status">
          <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
          <label for="opNewStatus">New status</label>
          <select id="opNewStatus" name="new_status" required>
            <?php foreach (OS_ALL as $s): ?>
              <?php if ($s === 'shipped' && $method === 'pickup') { continue; } // never valid for a pickup order ?>
              <?php // Packed is no longer part of the process, so it is not
                    // offered as somewhere to put an order. It is still listed
                    // when an order is already sitting on it, so the current
                    // status is never missing from this list.
                    if ($s === 'packed' && $current !== 'packed') { continue; } ?>
              <option value="<?= $s ?>" <?= $s === $current ? 'disabled' : '' ?>>
                <?= htmlspecialchars(os_label($s, $method)) ?><?= $s === $current ? ' (current)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <label for="opReason">Reason (required)</label>
          <textarea id="opReason" name="reason" required minlength="5" placeholder="e.g. Scanned the wrong box - putting it back to Preparing"></textarea>
          <button type="submit" class="op-btn">Apply manual change</button>
        </form>
      </div>
    </div>

    <div>
      <div class="op-card op-qr">
        <h2 style="margin-bottom:14px;">Scan label</h2>
        <?php if ($token): ?>
          <div class="box" id="opQr" data-payload="<?= htmlspecialchars(os_scan_payload($token)) ?>"></div>
          <code><?= htmlspecialchars($token) ?></code>
          <button type="button" class="op-btn primary" style="width:100%;justify-content:center;"
                  onclick="openStickerModal(<?= (int)$order['id'] ?>)">Print Sticker</button>
        <?php else: ?>
          <p class="op-empty">Could not create a scan label for this order.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php endif; ?>
</div>
</main>

<script src="<?= WEB_ROOT ?>davidshimjs-qrcodejs-04f46c6/qrcode.js"></script>
<script>
(function () {
  var box = document.getElementById('opQr');
  if (!box || !window.QRCode) { return; }
  new QRCode(box, {
    text: box.getAttribute('data-payload'),
    width: 340, height: 340,
    colorDark: '#000000', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.H
  });
})();
</script>

<?php if ($order): ?>
<script>
/* ==========================================================================
   Live status.

   The admin scans an order at the counter (or on a phone, or another tab) and
   this page has to show it - previously it only did so on a manual refresh,
   which is the thing being fixed. It polls this same URL with &poll=1, which
   returns the two cards already rendered by the PHP above, and swaps them in
   only when the fingerprint (status + last history row) actually changes.

   Nothing here writes: the scan page and the manual override below are still
   the only things that move an order. This only looks.
   ====================================================================== */
(function () {
  var card = document.getElementById('opLive');
  if (!card || !window.fetch) { return; }

  var whereBody = document.getElementById('opWhereBody');
  var histBody  = document.getElementById('opHistoryBody');
  var notice    = document.getElementById('opLiveNotice');
  var dot       = document.getElementById('opLiveDot');

  var url   = '<?= WEB_ROOT ?>public/authenticated/admin/order_status.php?id='
            + encodeURIComponent(card.dataset.orderId) + '&poll=1';
  var INTERVAL = 4000;
  var timer = null, inFlight = false;

  function flash(el) {
    if (!el) { return; }
    el.classList.remove('op-flash');
    // Reading offsetWidth restarts the animation; without it a second change
    // in a row would not replay it.
    void el.offsetWidth;
    el.classList.add('op-flash');
  }

  function apply(data) {
    if (!data || !data.ok || data.fingerprint === card.dataset.fingerprint) { return; }

    var statusChanged = data.status !== card.dataset.status;
    card.dataset.fingerprint = data.fingerprint;
    card.dataset.status = data.status;

    // Server-rendered by the same functions that drew the first paint, and
    // every value in them went through htmlspecialchars there.
    whereBody.innerHTML = data.where_html;
    histBody.innerHTML = data.history_html;
    flash(card);

    if (statusChanged) {
      notice.textContent = 'Updated: this order is now ' + data.label + '.';
      notice.hidden = false;
    }
  }

  function tick() {
    if (inFlight || document.hidden) { return; }
    inFlight = true;
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(apply)
      // A failed poll is not worth reporting: the next one is four seconds
      // away, and the page on screen is still the last thing the server said.
      .catch(function () {})
      .finally(function () { inFlight = false; });
  }

  function start() {
    if (timer) { return; }
    timer = setInterval(tick, INTERVAL);
    dot.classList.remove('is-idle');
  }
  function stop() {
    if (timer) { clearInterval(timer); timer = null; }
    dot.classList.add('is-idle');
  }

  /* A background tab polls nothing. Coming back to it checks immediately,
     which is the common case: scan at the counter, walk back to the screen. */
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stop(); } else { start(); tick(); }
  });
  window.addEventListener('pagehide', stop);

  start();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/sticker_modal.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
