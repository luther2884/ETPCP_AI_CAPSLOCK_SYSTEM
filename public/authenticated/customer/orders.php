<?php
// customer/orders.php - My Orders page
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/order_status.php';

require_login();
require_role(['customer']);

// ?partial=1 - the tab bar / pager / search XHR. Read before anything else
// so the branch further down is obvious; see the include near the end of this
// PHP block for what it actually does.
$isPartial = (($_GET['partial'] ?? '') === '1');

$pageTitle = 'My Orders - TechWorld';
$activeNav = 'orders';
$userId = getUserId();

/* =========================================================================
 * TAB RULES - defined once, in two forms.
 *
 * Every order belongs to exactly one tab. Two things need to know which:
 * the SQL that selects a tab's page of rows, and the PHP that decides what
 * to render on a card. If those two ever disagree, a card shows buttons for
 * a tab it isn't in - so they are written side by side here, from the same
 * sentences, and a test asserts they agree for every order in the database
 * (see the verification note at the bottom of this block).
 *
 * PRECEDENCE, applied top down. Only the first two can ever steal a row;
 * below them the lifecycle states are mutually exclusive by `status`.
 *
 *   return_refund  a return request exists, or the money went back
 *   cancelled      cancelled, or a cancellation request was approved
 *   completed      status = completed
 *   to_receive     out for delivery, or waiting at the counter uncollected
 *   to_ship        paid for and being prepared, not yet handed over
 *   to_pay         awaiting payment, or an online order still unpaid
 *
 * Two schema facts drive the awkward bits:
 *   - `orders.status` has no 'returned' value, so Return/Refund can only
 *     come from return_requests + payment_status = 'refunded'.
 *   - `pickup_code` is NOT NULL on every order including deliveries, so it
 *     cannot tell a pickup apart. `pickup_verified_at IS NULL` is the real
 *     "not collected yet" signal, and that is what To Receive uses.
 * ===================================================================== */

const ORD_TABS = ['all', 'to_pay', 'to_ship', 'to_receive', 'completed', 'return_refund', 'cancelled'];

const ORD_TAB_LABELS = [
    'all'           => 'All',
    'to_pay'        => 'To Pay',
    'to_ship'       => 'To Ship',
    'to_receive'    => 'To Receive',
    'completed'     => 'Completed',
    'return_refund' => 'Return/Refund',
    'cancelled'     => 'Cancelled',
];

/** Tabs that carry a count badge. Completed and Cancelled deliberately do
 *  not - they only grow, so a number on them is noise rather than news. */
const ORD_BADGE_TABS = ['all', 'to_pay', 'to_ship', 'to_receive', 'return_refund'];

/** Online methods, where "unpaid" is a thing the customer must act on. COD
 *  and onsite sit at payment_status='pending' by design until handover, so
 *  they must never be pulled into To Pay. */
const ORD_ONLINE_METHODS = ['gcash', 'paymaya', 'xendit'];

/**
 * The SQL half of the rules above: one predicate per tab, each written
 * against `o`. Kept as plain strings with no parameters so the same text can
 * be dropped into the list query, the total count and the badge GROUP BY.
 *
 * The CASE in ord_tab_case_sql() applies them in precedence order, which is
 * what guarantees one order lands in exactly one tab.
 */
function ord_tab_predicates(): array
{
    $online = "'" . implode("','", ORD_ONLINE_METHODS) . "'";
    return [
        'return_refund' => "(EXISTS (SELECT 1 FROM return_requests rr WHERE rr.order_id = o.id)
                             OR o.payment_status = 'refunded')",
        'cancelled'     => "(o.status = 'cancelled'
                             OR EXISTS (SELECT 1 FROM order_cancellation_requests ocr
                                        WHERE ocr.order_id = o.id AND ocr.status = 'approved'))",
        'completed'     => "(o.status = 'completed')",
        'to_receive'    => "((o.delivery_method = 'delivery' AND o.status = 'shipped')
                             OR (o.delivery_method = 'pickup' AND o.status = 'ready'
                                 AND o.pickup_verified_at IS NULL))",
        'to_ship'       => "((o.delivery_method = 'delivery' AND o.status IN ('preparing','packed','ready'))
                             OR (o.delivery_method = 'pickup' AND o.status IN ('preparing','packed')))",
        'to_pay'        => "(o.status = 'awaiting_payment'
                             OR (o.payment_method IN ($online) AND o.payment_status = 'pending'))",
    ];
}

/**
 * A CASE expression returning the tab key for each row, in precedence order.
 * Used by the badge GROUP BY so all seven counts come from one query, and by
 * the verification test so the SQL's answer can be compared with PHP's.
 */
function ord_tab_case_sql(): string
{
    $sql = "CASE\n";
    foreach (ord_tab_predicates() as $tab => $pred) {
        $sql .= "    WHEN " . $pred . " THEN '" . $tab . "'\n";
    }
    // Nothing matched: an order in a state no tab claims. Surfaced as its own
    // bucket rather than silently dropped, so it still appears under All and
    // shows up in testing instead of vanishing.
    return $sql . "    ELSE 'other'\n  END";
}

/**
 * The PHP half of the rules. Same sentences, same order.
 *
 * $o needs `status`, `payment_status`, `payment_method`, `delivery_method`,
 * `pickup_verified_at`, plus the two booleans the list query computes per
 * row (has_return, has_approved_cancel) so this never queries by itself.
 */
function ord_tab_for_order(array $o): string
{
    $hasReturn         = !empty($o['has_return']);
    $hasApprovedCancel = !empty($o['has_approved_cancel']);
    $status            = (string)($o['status'] ?? '');
    $payStatus         = (string)($o['payment_status'] ?? '');
    $payMethod         = (string)($o['payment_method'] ?? '');
    $delivery          = (string)($o['delivery_method'] ?? 'delivery');
    $pickedUp          = !empty($o['pickup_verified_at']);

    if ($hasReturn || $payStatus === 'refunded')              { return 'return_refund'; }
    if ($status === 'cancelled' || $hasApprovedCancel)        { return 'cancelled'; }
    if ($status === 'completed')                              { return 'completed'; }
    if (($delivery === 'delivery' && $status === 'shipped')
        || ($delivery === 'pickup' && $status === 'ready' && !$pickedUp)) { return 'to_receive'; }
    if (($delivery === 'delivery' && in_array($status, ['preparing','packed','ready'], true))
        || ($delivery === 'pickup' && in_array($status, ['preparing','packed'], true))) { return 'to_ship'; }
    if ($status === 'awaiting_payment'
        || (in_array($payMethod, ORD_ONLINE_METHODS, true) && $payStatus === 'pending')) { return 'to_pay'; }

    return 'other';
}

try {
    $pdo = getConnection();

    // Opportunistic sweep for online-payment orders left unpaid over an
    // hour - see cancelExpiredOnlinePaymentOrders() in config/functions.php.
    // Keeps the countdown below from ever actually reaching 0 on this page.
    cancelExpiredOnlinePaymentOrders();

    // If viewing one order
    $viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;
    if ($viewId) {
        $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $orderStmt->execute([$viewId, $userId]);
        $order = $orderStmt->fetch();
        if ($order) {
            $itemsStmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
            $itemsStmt->execute([$viewId]);
            $orderItems = $itemsStmt->fetchAll();

            // Serial numbers of the units this order went out with. The
            // customer needs these to hand: it is what they read back to staff
            // when they call about a fault, and what proves which unit is
            // theirs if they bring it in. Reserved and sold only - a unit
            // already taken back on a return is no longer theirs.
            $orderItemSerials = [];
            try {
                foreach (pu_units_for_order($pdo, $viewId) as $oiId => $units) {
                    foreach ($units as $u) {
                        if (in_array((string)$u['status'], ['reserved', 'sold'], true)) {
                            $orderItemSerials[(int)$oiId][] = (string)$u['serial_number'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Never fatal - the order page is still useful without them.
                error_log('order serials for order ' . $viewId . ': ' . $e->getMessage());
            }
        }
    }

    /* ---- Tab, search and page, all from the URL so refresh and Back work ---- */
    $ordTab = (string)($_GET['tab'] ?? 'all');
    if (!in_array($ordTab, ORD_TABS, true)) { $ordTab = 'all'; }

    $ordQ = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($ordQ) > 100) { $ordQ = mb_substr($ordQ, 0, 100); }

    $ordPage    = max(1, (int)($_GET['page'] ?? 1));
    $ordPerPage = 10;

    /* ---- One WHERE, three consumers --------------------------------------
       The list, the total behind "Showing X-Y of N" and the pager are all
       driven by user + tab + search. The badge counts use user + search only
       (a tab's own badge must not be filtered by that tab), which is why the
       search clause is built separately and reused.
       -------------------------------------------------------------------- */
    $ordWhere  = ['o.user_id = ?'];
    $ordParams = [$userId];

    // Search: the order's own reference, or any product in it. invoice_no is
    // the real column - there is no `order_number` on this table. Prepared
    // throughout; the % wrappers are bound as values, never concatenated.
    $ordSearchSql = '';
    $ordSearchParams = [];
    if ($ordQ !== '') {
        $ordSearchSql = "(o.invoice_no LIKE ?
                          OR EXISTS (SELECT 1 FROM order_items oi
                                     JOIN products p ON p.id = oi.product_id
                                     WHERE oi.order_id = o.id AND p.name LIKE ?))";
        $like = '%' . $ordQ . '%';
        $ordSearchParams = [$like, $like];
        $ordWhere[]  = $ordSearchSql;
        $ordParams[] = $like;
        $ordParams[] = $like;
    }

    if ($ordTab !== 'all') {
        // A tab's rows are the ones the CASE assigns to it - not merely the
        // ones its own predicate matches. Those differ for an order that two
        // predicates claim, and using the CASE is what keeps this page's
        // counts and rows consistent with ord_tab_for_order().
        $ordWhere[]  = '(' . ord_tab_case_sql() . ') = ?';
        $ordParams[] = $ordTab;
    }
    $ordWhereSql = implode("\n          AND ", $ordWhere);

    // Total for this tab + search, for the pager and the "N orders" line.
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $ordWhereSql");
    $cntStmt->execute($ordParams);
    $ordTotal = (int)$cntStmt->fetchColumn();

    $ordPages  = max(1, (int)ceil($ordTotal / $ordPerPage));
    $ordPage   = min($ordPage, $ordPages);
    $ordOffset = ($ordPage - 1) * $ordPerPage;

    /* ---- The page of orders ------------------------------------------------
       seconds_until_expiry is unchanged and still computed entirely in MySQL
       (never via PHP's strtotime()/time()) - this server's PHP default
       timezone doesn't match the DB's real local time, so any PHP-side date
       math here would drift by hours. Matches
       cancelExpiredOnlinePaymentOrders()'s own "created_at + 24 hour" cutoff,
       so the countdown always agrees with when the order gets swept.

       has_return / has_approved_cancel are carried on the row so
       ord_tab_for_order() can decide a card's tab without a query per card.
       ---------------------------------------------------------------------- */
    $listStmt = $pdo->prepare("
        SELECT o.*,
               TIMESTAMPDIFF(SECOND, NOW(), o.created_at + INTERVAL 24 HOUR) AS seconds_until_expiry,
               TIMESTAMPDIFF(SECOND, o.created_at, NOW()) AS seconds_since_created,
               EXISTS (SELECT 1 FROM return_requests rr WHERE rr.order_id = o.id) AS has_return,
               EXISTS (SELECT 1 FROM order_cancellation_requests ocr
                       WHERE ocr.order_id = o.id AND ocr.status = 'approved') AS has_approved_cancel
        FROM orders o
        WHERE $ordWhereSql
        ORDER BY o.created_at DESC
        LIMIT $ordPerPage OFFSET $ordOffset
    ");
    $listStmt->execute($ordParams);
    $orders = $listStmt->fetchAll();

    /* ---- Badge counts: one GROUP BY, not one query per tab ---------------- */
    $badgeWhere  = ['o.user_id = ?'];
    $badgeParams = [$userId];
    if ($ordSearchSql !== '') {
        $badgeWhere[] = $ordSearchSql;
        foreach ($ordSearchParams as $p) { $badgeParams[] = $p; }
    }
    $badgeStmt = $pdo->prepare(
        "SELECT (" . ord_tab_case_sql() . ") AS tab_key, COUNT(*) AS n
         FROM orders o
         WHERE " . implode("\n           AND ", $badgeWhere) . "
         GROUP BY tab_key"
    );
    $badgeStmt->execute($badgeParams);
    $ordCounts = array_fill_keys(ORD_TABS, 0);
    foreach ($badgeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = (string)$row['tab_key'];
        if (isset($ordCounts[$k])) { $ordCounts[$k] = (int)$row['n']; }
        $ordCounts['all'] += (int)$row['n'];
    }

    /* ---- Items for the cards on this page, in one query ------------------- */
    $ordItems = [];
    $ordIds = array_map(static fn($o) => (int)$o['id'], $orders);
    if ($ordIds) {
        $in = implode(',', array_fill(0, count($ordIds), '?'));
        $itStmt = $pdo->prepare(
            "SELECT oi.order_id, oi.product_id, oi.quantity, oi.price, oi.total,
                    p.name, p.image_url, p.stock, p.status AS product_status
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id IN ($in)"
        );
        $itStmt->execute($ordIds);
        foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $ordItems[(int)$it['order_id']][] = $it;
        }
    }

    /* ---- Which of those products this customer has already rated ---------- */
    $ordRated = [];
    $prodIds = [];
    foreach ($ordItems as $rows) {
        foreach ($rows as $r) { $prodIds[(int)$r['product_id']] = true; }
    }
    if ($prodIds) {
        $pin = implode(',', array_fill(0, count($prodIds), '?'));
        $rvStmt = $pdo->prepare("SELECT product_id, rating FROM reviews WHERE user_id = ? AND product_id IN ($pin)");
        $rvStmt->execute(array_merge([$userId], array_keys($prodIds)));
        foreach ($rvStmt->fetchAll(PDO::FETCH_ASSOC) as $rv) {
            $ordRated[(int)$rv['product_id']] = (int)$rv['rating'];
        }
    }
} catch (PDOException $e) {
    error_log('Error loading orders: ' . $e->getMessage());
    $orders = [];
    $ordItems = [];
    $ordRated = [];
    $ordCounts = array_fill_keys(ORD_TABS, 0);
    $ordTotal = 0; $ordPages = 1; $ordPage = 1; $ordPerPage = 10;
    $ordTab = 'all'; $ordQ = '';
}

/** A pager/tab link that keeps whatever else is already set. */
function ord_url(array $over = []): string
{
    $qs = array_merge(['tab' => $_GET['tab'] ?? 'all', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? 1], $over);
    $qs = array_filter($qs, static fn($v) => $v !== '' && $v !== null);
    if (($qs['tab'] ?? '') === 'all') { unset($qs['tab']); }
    if ((int)($qs['page'] ?? 1) <= 1)  { unset($qs['page']); }
    return '?' . http_build_query($qs);
}

/* ?partial=1 - everything above has already run, so render only the live
   region and stop: no <head>, no header/sidebar, no page CSS. The browser
   swaps this straight into #ordPanel, so changing tab, paging or searching
   updates the list in place instead of reloading the page.

   The X-Ord-Partial header is the client's proof that it got the partial and
   not something else. If the session has lapsed, require_login() above sends
   a 302 to the login page and fetch() follows it transparently - a 200 full
   of login markup. Without this header the script would cheerfully paste that
   into the panel; with it, it falls back to a real navigation instead. */
if ($isPartial) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Ord-Partial: 1');
    header('X-Ord-Total: ' . (int)$ordTotal);
    include __DIR__ . '/includes/orders_results.php';
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js
         is deferred and would run a frame after first paint, which is exactly
         the flash this prevents. Resolution order is localStorage -> OS
         preference -> dark. There is no server-rendered preference to fall
         back on yet - `users` has no theme column; see the TODO in theme.js. */ ?>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
  <!-- Same pair the landing page and the other redesigned customer pages
       load: Figtree for headings, JetBrains Mono for eyebrows, codes and
       small-caps labels. Without them the mono labels below fall back to the
       system monospace and the page reads as a different product. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?php /* The page's own CSS, above the markup it styles.

        It used to sit at the bottom of <body>, after the order list. On this
        page that is not a cosmetic detail: 46 orders render a 246KB document,
        so the rules arrived ~150KB after the cards they size. Chrome paints
        each card as it parses, so for the better part of a second the list
        showed plain underlined tabs, a browser-default search box and product
        images at full natural size - the shared sheets were already in, which
        is why only this page's furniture looked unstyled.

        It stays in <body> rather than moving to <head> on purpose: theme-light.css
        is linked from sidebar.php, and several rules below rely on landing after
        it. Sitting here keeps that order exactly as it was. */ ?>
<style>
/* ===========================================================================
   My Orders, in the landing page's visual language.

   The ground (dark navy radial + panning grid + drifting pink/cyan blobs) is
   already painted for every account page by customer-dark.css; nothing here
   repaints it. What this block does is bring the page's own furniture up to
   the same language the homepage and the products listing use:

     - mono, tracked-out eyebrows and column labels (JetBrains Mono)
     - Figtree 900 headline with a pink-to-cyan gradient on the accent word
     - the traveling-light hairline under the header
     - panel surfaces: the landing page's --tw-panel-grad over a 1px blue
       hairline border, at 16-18px radius
     - the landing accents - #e0567a/#ff9ab5 pink, #67e8f9 cyan, #7f6ae0
       violet - instead of the generic status colours this page shipped with

   Every colour below is copied from the landing palette in
   techworld-anim.css rather than invented, so the two pages stay one system.
   ======================================================================== */
.ord-page {
    --ord-font: 'Figtree', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    --ord-mono: 'JetBrains Mono', ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
    --ord-line: rgba(var(--tw-line-rgb, 120, 170, 230), .14);
    --ord-line-hi: rgba(var(--tw-line-rgb, 120, 170, 230), .32);
    --ord-panel: linear-gradient(180deg, rgba(20, 36, 60, .85), rgba(12, 23, 39, .85));
    /* One declaration drives both the column labels and every row, so they can
       never drift out of alignment. */
    /* The actions track is minmax(0, auto), not bare `auto`: an unpaid order
       carries three controls, and a track that always sized to their
       max-content would squeeze the four columns to its left. With a 0 floor
       the track gives way first and the buttons wrap inside it instead. */
    --ord-grid: minmax(0, 1.05fr) minmax(0, .72fr) minmax(0, 1.15fr) minmax(0, .7fr) minmax(0, auto);
}

.account-page { padding:40px 0 64px; background: var(--page-bg-gradient); min-height:100vh; }
.ord-page .container { max-width: 1240px; }

/* --- header --------------------------------------------------------------- */
.ord-eyebrow {
    display: flex; align-items: center; gap: 9px;
    font-family: var(--ord-mono);
    font-size: 10.5px; letter-spacing: .28em; text-transform: uppercase;
    color: var(--tw-accent, var(--tw-pink-lt, #ff9ab5));
    margin-bottom: 12px;
}
.ord-eyebrow span { width: 7px; height: 7px; border-radius: 50%; background: #e0567a; flex: none; }
.ord-head h1 {
    margin: 0;
    font-family: var(--ord-font);
    font-size: clamp(30px, 3.6vw, 46px);
    font-weight: 900; letter-spacing: -.03em; line-height: 1.05;
    color: var(--odd-ink, #fff);
    text-shadow: 0 2px 14px rgba(3, 8, 16, .55);
}
/* The accent word, on the same pink-to-cyan gradient the landing band uses. */
.ord-head h1 em {
    font-style: normal;
    background: linear-gradient(100deg, #ff9ab5, #67e8f9);
    -webkit-background-clip: text; background-clip: text;
    color: transparent;
}
.ord-lede {
    margin: 10px 0 0;
    color: var(--odd-ink-soft, #b9cbe2); font-size: 15px; line-height: 1.6;
    text-shadow: 0 1px 3px rgba(3, 8, 16, .5);
}
/* The landing page's traveling-light divider, element-for-element. */
.ord-rule { position: relative; height: 1px; margin: 22px 0 26px; background: rgba(var(--tw-line-rgb, 120, 170, 230), .16); overflow: hidden; }
.ord-rule i {
    position: absolute; inset: 0; width: 40%;
    background: linear-gradient(90deg, transparent, #67e8f9, transparent);
    animation: ord-sweep 3.6s ease-in-out infinite;
}
@keyframes ord-sweep { from { transform: translateX(-100%); } to { transform: translateX(100%); } }

/* --- stats strip ---------------------------------------------------------- */
.ord-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}
.ord-stat {
    position: relative; overflow: hidden;
    padding: 17px 19px;
    border: 1px solid var(--ord-line);
    border-radius: 16px;
    background: var(--ord-panel);
}
/* A cool bloom in the corner so the tiles read as lit panels rather than flat
   fills - the same trick the landing bento uses. */
.ord-stat::after {
    content: ''; position: absolute; right: -25%; top: -60%;
    width: 70%; height: 180%;
    background: radial-gradient(circle, rgba(103, 232, 249, .12), transparent 62%);
    pointer-events: none;
}
.ord-stat b {
    display: block;
    font-family: var(--ord-font);
    font-size: clamp(22px, 2.2vw, 28px); font-weight: 800;
    letter-spacing: -.02em; line-height: 1; color: var(--odd-ink, #fff);
}
.ord-stat span {
    display: block; margin-top: 8px;
    font-family: var(--ord-mono);
    font-size: 9.5px; letter-spacing: .22em; text-transform: uppercase;
    color: var(--cx-ink-faint);
}
/* The money figure is the one number worth the gradient. */
.ord-stat--spend b {
    background: linear-gradient(100deg, #ff9ab5, #67e8f9);
    -webkit-background-clip: text; background-clip: text;
    color: transparent;
}

/* --- the order list ------------------------------------------------------- */
.ord-list { display: flex; flex-direction: column; gap: 10px; }
.ord-cols {
    display: grid; grid-template-columns: var(--ord-grid);
    gap: 16px; align-items: center;
    padding: 0 20px 4px;
    font-family: var(--ord-mono);
    font-size: 9.5px; letter-spacing: .22em; text-transform: uppercase;
    color: var(--cx-ink-label);
}
.ord-row {
    position: relative; overflow: hidden;
    display: grid; grid-template-columns: var(--ord-grid);
    gap: 16px; align-items: center;
    padding: 16px 20px 16px 23px;
    border: 1px solid var(--ord-line);
    border-radius: 16px;
    background: var(--ord-panel);
    transition: transform .22s cubic-bezier(.2, .85, .2, 1),
                border-color .22s ease, box-shadow .22s ease;
}
/* The status reads twice: as the badge, and as the edge of the row - so a
   list of orders can be scanned down the left side alone. */
.ord-row::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--ord-accent, var(--tw-accent, #67e8f9));
}
.ord-row:hover {
    transform: translateY(-2px);
    border-color: var(--ord-line-hi);
    box-shadow: 0 18px 40px rgba(3, 8, 16, .5);
}
.ord-row.is-preparing { --ord-accent: var(--tw-warn, #fcd34d); }
.ord-row.is-packed    { --ord-accent: #7f6ae0; }
.ord-row.is-ready     { --ord-accent: var(--tw-ok, #9df3d0); }
.ord-row.is-shipped   { --ord-accent: var(--tw-accent, #67e8f9); }
.ord-row.is-completed { --ord-accent: #4ade80; }
.ord-row.is-cancelled { --ord-accent: #e0567a; }

.ord-cell { min-width: 0; }
/* Per-cell labels for the stacked phone layout; the .ord-cols head carries
   them on wide screens. */
.ord-k { display: none; }
.ord-code {
    font-family: var(--ord-mono);
    font-size: 14px; font-weight: 700; letter-spacing: .02em;
    color: var(--odd-ink, #fff);
    overflow-wrap: anywhere;
}
.ord-date {
    font-family: var(--ord-mono);
    font-size: 12px; letter-spacing: .06em;
    color: var(--cx-ink-faint);
}
.ord-amount {
    font-family: var(--ord-font);
    font-size: 18px; font-weight: 800; letter-spacing: -.01em;
    color: var(--odd-ink, #fff); white-space: nowrap;
}
.ord-badges { display: flex; flex-wrap: wrap; gap: 6px; }

/* --- status badges -------------------------------------------------------- */
/* Matches orders.status's real ENUM values (preparing/packed/ready/shipped/
   completed/cancelled), recoloured to the landing palette. status-unpaid is
   the payment flag, which used to be an inline style on the markup. */
.ord-page .status-badge {
    display: inline-flex; align-items: center;
    padding: 5px 11px;
    border: 1px solid transparent; border-radius: 999px;
    font-family: var(--ord-mono);
    font-size: 9.5px; font-weight: 600; letter-spacing: .16em;
    text-transform: uppercase; white-space: nowrap;
}
.ord-page .status-preparing { background: rgba(252, 211, 77, .12); color: var(--tw-warn, var(--tw-warn, #fcd34d)); border-color: rgba(252, 211, 77, .38); }
.ord-page .status-packed    { background: rgba(127, 106, 224, .16); color: var(--odd-violet, #b9adf4); border-color: rgba(127, 106, 224, .45); }
.ord-page .status-ready     { background: rgba(157, 243, 208, .12); color: var(--tw-ok, var(--tw-ok, #9df3d0)); border-color: rgba(157, 243, 208, .38); }
.ord-page .status-shipped   { background: rgba(103, 232, 249, .12); color: var(--tw-accent, var(--tw-cyan, #67e8f9)); border-color: rgba(103, 232, 249, .40); }
.ord-page .status-completed { background: rgba(74, 222, 128, .12); color: var(--odd-green, #4ade80); border-color: rgba(74, 222, 128, .38); }
.ord-page .status-cancelled { background: rgba(224, 86, 122, .14); color: var(--tw-accent, var(--tw-pink-lt, #ff9ab5)); border-color: rgba(224, 86, 122, .42); }
.ord-page .status-unpaid    { background: rgba(224, 86, 122, .18); color: var(--tw-accent, var(--tw-pink-lt, #ff9ab5)); border-color: rgba(224, 86, 122, .55); }

/* --- actions -------------------------------------------------------------- */
.ord-cell--act { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; justify-content: flex-end; }
.ord-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 15px;
    /* --tw-line-ctl rather than a .24 hairline: this button's fill is a 4%
       wash, so the border is the only thing that says "button". At .24 it
       measured 1.56:1 on the row card in dark and 1.59:1 in light. See the
       token's note in theme-light.css. */
    border: 1px solid var(--tw-line-ctl, rgba(120, 170, 230, .55));
    border-radius: 999px;
    background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04);
    color: var(--odd-ink-pale, #dbe6f5);
    font-family: var(--ord-mono);
    font-size: 10px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    text-decoration: none; white-space: nowrap;
    transition: transform .18s cubic-bezier(.2, .85, .2, 1),
                background .18s ease, border-color .18s ease, color .18s ease, box-shadow .18s ease;
}
.ord-btn:hover {
    transform: translateY(-1px);
    background: rgba(103, 232, 249, .12);
    border-color: rgba(103, 232, 249, .45);
    color: #fff;
}
/* The one primary action on the page, on the shared brand gradient.
   It was on the old landing pink: white measured 3.63:1 on #e0567a at rest and
   1.99:1 on #ff9ab5 once hovered - so hovering the page's most important
   button made its label harder to read, in BOTH themes. That is the failure
   --tw-brand-grad exists to fix (theme-light.css, debt item 5). Hover brightens
   with a filter instead of swapping to lighter stops, which is what let the
   ratio collapse: a filter cannot push the fill past the ink. */
.ord-btn--pay {
    background: var(--tw-brand-grad);
    border-color: transparent; color: #fff;
    box-shadow: 0 8px 20px rgba(3, 8, 16, .3);
}
.ord-btn--pay:hover {
    /* The background is restated, not inherited: .ord-btn:hover is (0,2,0) and
       .ord-btn--pay only (0,1,0), so leaving it off here would let the generic
       cyan hover fill repaint the primary button. */
    background: var(--tw-brand-grad);
    /* Deepen, never brighten - white on this gradient's first stop is 4.61:1,
       which is the 4.5 floor exactly. brightness(1.08) took it to 3.69:1. */
    filter: saturate(1.08) brightness(0.92);
    border-color: transparent; color: #fff;
    box-shadow: 0 12px 28px rgba(3, 8, 16, .42);
}
.ord-btn--qr {
    background: rgba(127, 106, 224, .16);
    border-color: rgba(127, 106, 224, .45);
    color: var(--odd-violet-2, #c7bdf6);
}
.ord-btn--qr:hover { background: rgba(127, 106, 224, .28); border-color: rgba(127, 106, 224, .7); color: #fff; }

/* The live countdown next to an unpaid order. Its text is written by the
   script at the bottom of this page. */
.pay-countdown {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 11px;
    border: 1px solid rgba(252, 211, 77, .35);
    border-radius: 999px;
    background: rgba(252, 211, 77, .10);
    font-family: var(--ord-mono);
    font-size: 10px; font-weight: 600; letter-spacing: .08em;
    color: var(--tw-warn, var(--tw-warn, #fcd34d)); white-space: nowrap;
}
.pay-countdown::before {
    content: ''; width: 6px; height: 6px; border-radius: 50%;
    background: currentColor; flex: none;
    animation: ord-blink 2s ease-in-out infinite;
}
@keyframes ord-blink { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
/* Its lapsed state, set by the script at the bottom of the page: no longer a
   countdown but a warning, so it drops the amber for the page's alert pink. */
.pay-countdown.is-expired {
    border-color: rgba(224, 86, 122, .55);
    background: rgba(224, 86, 122, .16);
    color: var(--tw-accent, var(--tw-pink-lt, #ff9ab5));
}

/* --- empty state ---------------------------------------------------------- */
/* customer-dark.css gives .empty-card a warm cream treatment at
   `body .account-page .empty-card` (0,3,0). The extra .ord-page here lands at
   (0,3,1) and outranks it without !important, so this page's empty state is
   a blue panel like everything else on it. */
body .account-page.ord-page .empty-card {
    text-align: center;
    max-width: 560px; margin: 10px auto 0;
    padding: 70px 40px;
    border: 1px solid var(--ord-line);
    border-radius: 22px;
    background: var(--ord-panel);
    box-shadow: 0 24px 60px rgba(3, 8, 16, .5);
}
body .account-page.ord-page .empty-card .empty-icon {
    width: 92px; height: 92px; margin: 0 auto 26px;
    display: grid; place-items: center;
    border-radius: 50%;
    border: 1px solid rgba(224, 86, 122, .35);
    background: radial-gradient(circle at 30% 25%, rgba(224, 86, 122, .28), rgba(103, 232, 249, .10) 70%);
}
body .account-page.ord-page .empty-card .empty-icon i { font-size: 38px; color: var(--tw-accent, var(--tw-pink-lt, #ff9ab5)); margin: 0; }
body .account-page.ord-page .empty-card h2 {
    margin-bottom: 12px;
    font-family: var(--ord-font); font-size: 26px; font-weight: 800; letter-spacing: -.02em;
    color: var(--odd-ink, #fff);
}
body .account-page.ord-page .empty-card p { margin-bottom: 28px; color: var(--tw-ink-dim, var(--tw-ink-dim, #a9bcd4)); font-size: 15px; line-height: 1.7; }
body .account-page.ord-page .empty-card .btn-primary {
    display: inline-block;
    padding: 14px 30px;
    border: none; border-radius: 999px;
    background: linear-gradient(120deg, #e0567a, #c2185b);
    color: #fff; text-decoration: none;
    font-family: var(--ord-mono); font-size: 11px; font-weight: 700;
    letter-spacing: .16em; text-transform: uppercase;
    box-shadow: 0 10px 26px rgba(224, 86, 122, .32);
    transition: transform .2s cubic-bezier(.2, .85, .2, 1), box-shadow .2s ease, background .2s ease;
}
body .account-page.ord-page .empty-card .btn-primary:hover {
    transform: translateY(-2px);
    background: linear-gradient(120deg, #ff9ab5, #e0567a);
    box-shadow: 0 16px 34px rgba(224, 86, 122, .45);
}

/* --- single-order detail panel (?view=) ----------------------------------- */
.ord-page .order-detail {
    margin-top: 26px;
    padding: 26px;
    border: 1px solid var(--ord-line);
    border-radius: 18px;
    background: var(--ord-panel);
}
.ord-page .order-detail h2 {
    margin: 0 0 14px;
    font-family: var(--ord-font); font-size: 24px; font-weight: 800; letter-spacing: -.02em;
    color: var(--odd-ink, #fff);
}
.ord-page .order-meta {
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px 20px;
    margin-bottom: 20px;
    font-family: var(--ord-mono); font-size: 11px; letter-spacing: .08em;
    color: var(--cx-ink-faint);
}
.ord-page .order-meta strong:not(.status-badge) { color: var(--odd-ink, #fff); }
.ord-page .order-items { display: flex; flex-direction: column; gap: 10px; }
/* Serial numbers of the units in this order. Monospace chips so a transposed
   character is visible when the customer reads one back over the phone. */
.ord-page .ord-serials {
    margin-top: 5px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
    font-size: 11.5px; color: var(--cx-ink-label, #a9bcd4);
}
.ord-page .ord-serials span {
    font-family: var(--ord-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
    font-weight: 700; color: inherit;
    background: rgba(var(--tw-veil-rgb, 255, 255, 255), .08);
    border-radius: 4px; padding: 2px 7px; word-break: break-all;
}
/* style.css forces .order-item to a white card under [data-theme="light"]
   with !important, and customer-dark.css counters that at (0,3,1) - also
   !important. Matching that specificity here (and coming later in source
   order) is what makes this rule the one that lands. */
body .account-page.ord-page .order-item {
    display: flex; align-items: center; gap: 14px;
    padding: 12px;
    border: 1px solid var(--ord-line) !important;
    border-radius: 14px;
    background: rgba(255, 255, 255, .035) !important;
}
.ord-page .order-item .img {
    width: 58px; height: 58px; flex: none;
    display: grid; place-items: center;
    border-radius: 10px; overflow: hidden;
    background: rgba(var(--tw-veil-rgb, 255, 255, 255), .06);
}
.ord-page .order-item img { width: 100%; height: 100%; object-fit: cover; }
.ord-page .order-item .image-placeholder { font-family: var(--ord-mono); font-size: 10px; letter-spacing: .12em; color: var(--cx-ink-label); }
.ord-page .order-item .meta { flex: 1; min-width: 0; }
.ord-page .order-item .title { color: var(--odd-ink, #fff); font-weight: 700; font-size: 14px; margin-bottom: 3px; }
.ord-page .order-item .sub { font-family: var(--ord-mono); font-size: 11px; letter-spacing: .06em; color: var(--cx-ink-faint); }
.ord-page .order-item .line-total { font-family: var(--ord-font); font-weight: 800; font-size: 15px; color: var(--tw-accent, var(--tw-cyan, #67e8f9)); }

/* --- entrance ------------------------------------------------------------- */
/* Rows arrive in sequence rather than all at once, which is how the landing
   sections introduce themselves. Capped at 12 steps in the markup so a long
   history never waits on a long stagger. */
.ord-stat, .ord-row {
    animation: ord-rise .5s cubic-bezier(.2, .85, .2, 1) backwards;
    animation-delay: calc(var(--i, 0) * 45ms);
}
@keyframes ord-rise {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: none; }
}

/* --- narrow screens ------------------------------------------------------- */
/* Below the breakpoint each row becomes a stacked card: the column head hides
   and every cell shows its own mono label instead, so nothing depends on a
   header row that is no longer there. */
@media (max-width: 1000px) {
    .ord-cols { display: none; }
    .ord-row {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 18px 18px 18px 21px;
    }
    .ord-k {
        display: block; margin-bottom: 5px;
        font-family: var(--ord-mono);
        font-size: 9px; letter-spacing: .22em; text-transform: uppercase;
        color: var(--cx-ink-label);
    }
    .ord-cell--act { justify-content: flex-start; padding-top: 4px; }
    .ord-amount { font-size: 20px; }
}
@media (max-width: 560px) {
    .ord-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ord-btn { flex: 1 1 auto; justify-content: center; }
}

@media (prefers-reduced-motion: reduce) {
    .ord-rule i, .pay-countdown::before { animation: none; }
    .ord-stat, .ord-row { animation: none; }
    .ord-row:hover { transform: none; }
}

/* =========================================================================
   My Orders: status tabs and order cards.
   ---------------------------------------------------------------------
   Everything here is written against the existing --tw-* tokens, so dark
   mode keeps whatever customer-dark.css already paints. The literal light
   values from the brief live under html[data-mode="light"] only - there is
   no bare light declaration anywhere in this block, which is what stops it
   leaking into dark.

   Contrast, measured on the light values (WCAG AA needs 4.5:1 for body text):
     #16233a on #ffffff  13.9:1   active tab
     #46586f on #ffffff   6.9:1   idle tab, muted text
     #a8264d on #fdecf1   6.4:1   To Pay pill
     #8a5a00 on #fdf3e0   5.4:1   To Ship pill
     #1f4f86 on #e6f0fb   7.0:1   Shipped pill
     #17663f on #e5f6ee   6.0:1   Completed pill
     #4f3aa3 on #efeafc   7.6:1   Return/Refund pill
     #46586f on #f1f3f6   6.5:1   Cancelled pill
     #ffffff on #c23a63   5.0:1   To Pay badge
   ====================================================================== */

/* ---- Tab bar ---------------------------------------------------------- */
.ord-tabs {
  display: flex;
  gap: 4px;
  align-items: center;
  margin: 0 0 18px;
  padding: 6px;
  border-radius: 14px;
  background: var(--tw-panel-2, rgba(255,255,255,.04));
  border: 1px solid var(--tw-line, rgba(120,170,230,.18));
  /* Sideways on a narrow screen, with no visible scrollbar - the bar is
     already short enough that a track under it would be most of its height. */
  overflow-x: auto;
  overflow-y: hidden;
  scrollbar-width: none;
  -ms-overflow-style: none;
  position: sticky;
  top: 0;
  z-index: 20;
}
.ord-tabs::-webkit-scrollbar { display: none; }

.ord-tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  flex: 0 0 auto;
  padding: 9px 14px;
  border-radius: 10px;
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  text-decoration: none;
  color: var(--tw-ink-dim, #a9bcd4);
  transition: background .18s ease, color .18s ease;
}
.ord-tab:hover { color: var(--tw-ink, #e8eef7); text-decoration: none; }
.ord-tab.is-on { background: var(--tw-panel-3, rgba(255,255,255,.10)); color: var(--tw-ink, #e8eef7); }

.ord-tab-n {
  min-width: 20px;
  padding: 0 6px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 700;
  line-height: 19px;
  text-align: center;
  background: var(--tw-panel-3, rgba(255,255,255,.12));
  color: inherit;
}
/* The one badge the brief colours explicitly - money owed is the only count
   on this bar that is a task rather than a tally. */
.ord-tab-n--pay { background: #c23a63; color: #fff; }

/* ---- Toolbar: count on the left, search on the right ------------------ */
.ord-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin: 0 0 16px;
}
.ord-showing { margin: 0; font-size: 14px; color: var(--tw-ink-dim, #a9bcd4); }
.ord-showing strong { color: var(--tw-ink, #e8eef7); font-weight: 800; }

.ord-search { position: relative; display: flex; align-items: center; }
.ord-search i {
  position: absolute; left: 12px; font-size: 14px; pointer-events: none;
  color: var(--tw-ink-faint, #7f95b3);
}
.ord-search input {
  width: 260px; max-width: 100%;
  padding: 10px 32px 10px 34px;
  border-radius: 12px;
  font: inherit; font-size: 14px;
  background: var(--tw-panel-2, rgba(255,255,255,.04));
  border: 1px solid var(--tw-line, rgba(120,170,230,.18));
  color: var(--tw-ink, #e8eef7);
}
.ord-search input::placeholder { color: var(--tw-ink-faint, #7f95b3); }
.ord-search-clear {
  position: absolute; right: 10px; font-size: 18px; line-height: 1;
  text-decoration: none; color: var(--tw-ink-faint, #7f95b3);
}

/* ---- Order card ------------------------------------------------------- */
.ord-cards { display: flex; flex-direction: column; gap: 14px; }

.ordc {
  border-radius: 16px;
  overflow: hidden;
  background: var(--tw-panel-2, rgba(255,255,255,.04));
  border: 1px solid var(--tw-line, rgba(120,170,230,.18));
  box-shadow: 0 1px 2px rgba(10,20,36,.04), 0 8px 24px rgba(10,20,36,.06);
}

.ordc-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; flex-wrap: wrap;
  padding: 14px 18px;
  border-bottom: 1px solid var(--tw-line, rgba(120,170,230,.18));
}
.ordc-head-l, .ordc-head-r { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ordc-no {
  font-family: 'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace;
  font-weight: 700; font-size: 13.5px;
  color: var(--tw-ink, #e8eef7);
}
.ordc-date { font-size: 13px; color: var(--tw-ink-dim, #a9bcd4); }
.ordc-chip {
  padding: 3px 9px; border-radius: 999px;
  font-size: 11.5px; font-weight: 600; letter-spacing: .02em;
  background: var(--tw-panel-3, rgba(255,255,255,.08));
  color: var(--tw-ink-dim, #a9bcd4);
  border: 1px solid var(--tw-line, rgba(120,170,230,.18));
}
.ordc-note { font-size: 12.5px; font-weight: 600; color: var(--tw-ink-dim, #a9bcd4); }

/* Status pill. One class per tab so the colours are set once. */
.ordp {
  padding: 5px 12px; border-radius: 999px;
  font-size: 12px; font-weight: 700; white-space: nowrap;
  border: 1px solid transparent;
}

/* ---- Items ------------------------------------------------------------ */
.ordc-body { padding: 6px 18px; }
.ordc-item {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 0;
  border-bottom: 1px solid var(--tw-line-soft, rgba(120,170,230,.10));
}
.ordc-item:last-child { border-bottom: 0; }
.ordc-thumb {
  flex: 0 0 auto; width: 64px; height: 64px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 10px; overflow: hidden;
  background: var(--tw-panel-3, rgba(255,255,255,.06));
  border: 1px solid var(--tw-line, rgba(120,170,230,.18));
}
.ordc-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ordc-item-main { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1 1 auto; }
.ordc-item-name {
  font-size: 14px; font-weight: 600; text-decoration: none;
  color: var(--tw-ink, #e8eef7);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.ordc-item-name:hover { text-decoration: underline; }
.ordc-item-sub { font-size: 12.5px; color: var(--tw-ink-dim, #a9bcd4); }
.ordc-item-price { font-size: 14px; font-weight: 700; white-space: nowrap; color: var(--tw-ink, #e8eef7); }
.ordc-rate, .ordc-rated { font-size: 12.5px; font-weight: 600; white-space: nowrap; }
.ordc-rate { text-decoration: none; color: var(--accent-cyan, #67e8f9); }
.ordc-rate:hover { text-decoration: underline; }
.ordc-rated { color: var(--tw-ink-faint, #7f95b3); }
.ordc-noitems, .ordc-pickup { margin: 12px 0; font-size: 13px; color: var(--tw-ink-dim, #a9bcd4); }
.ordc-pickup b {
  font-family: 'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace;
  letter-spacing: .12em; color: var(--tw-ink, #e8eef7);
}

/* ---- Footer ----------------------------------------------------------- */
.ordc-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; flex-wrap: wrap;
  padding: 14px 18px;
  border-top: 1px solid var(--tw-line, rgba(120,170,230,.18));
  background: var(--tw-panel-3, rgba(255,255,255,.03));
}
.ordc-total { display: flex; align-items: baseline; gap: 8px; }
.ordc-total-k { font-size: 13px; color: var(--tw-ink-dim, #a9bcd4); }
.ordc-total b { font-size: 20px; font-weight: 800; color: #c23a63; }
.ordc-acts { display: flex; gap: 8px; flex-wrap: wrap; }

.ordb {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 9px 16px; border-radius: 10px;
  font-size: 13.5px; font-weight: 700; text-decoration: none;
  border: 1px solid var(--tw-line, rgba(120,170,230,.22));
  color: var(--tw-ink, #e8eef7);
  background: transparent;
  transition: background .18s ease, border-color .18s ease;
}
.ordb:hover { text-decoration: none; background: var(--tw-panel-3, rgba(255,255,255,.08)); }
.ordb--on { background: #c23a63; border-color: #c23a63; color: #fff; }
.ordb--on:hover { background: #a8264d; border-color: #a8264d; color: #fff; }
.ordb.is-off { opacity: .45; pointer-events: none; }

/* ---- Pager and empty state -------------------------------------------- */
.ord-pager { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 22px; }
.ord-pager-n { font-size: 13px; color: var(--tw-ink-dim, #a9bcd4); }

.ord-empty {
  text-align: center; padding: 56px 24px; border-radius: 16px;
  border: 1px dashed var(--tw-line, rgba(120,170,230,.28));
  background: var(--tw-panel-2, rgba(255,255,255,.03));
}
.ord-empty-icon { font-size: 30px; color: var(--tw-ink-faint, #7f95b3); }
.ord-empty h2 { margin: 12px 0 6px; font-size: 19px; color: var(--tw-ink, #e8eef7); }
.ord-empty p { margin: 0 0 18px; font-size: 14px; color: var(--tw-ink-dim, #a9bcd4); }

/* ---- Narrow screens ---------------------------------------------------- */
@media (max-width: 640px) {
  /* Head, footer and each item stack rather than shrink - the product name
     and the total are the two things that must stay readable. */
  .ordc-head, .ordc-foot { flex-direction: column; align-items: flex-start; }
  .ordc-acts { width: 100%; }
  .ordc-acts .ordb { flex: 1 1 0; }
  .ordc-item { flex-wrap: wrap; }
  .ordc-item-price { margin-left: 78px; }
  .ord-toolbar { flex-direction: column; align-items: stretch; }
  .ord-search input { width: 100%; }
}

/* =========================================================================
   Light mode only. Dark inherits everything above untouched.
   ====================================================================== */
html[data-mode="light"] .ord-tabs {
  background: #ffffff;
  border-color: #dbe3ee;
  box-shadow: 0 1px 2px rgba(22,35,58,.04);
}
html[data-mode="light"] .ord-tab { color: #46586f; }
html[data-mode="light"] .ord-tab:hover { background: #f2f5fa; color: #16233a; }
html[data-mode="light"] .ord-tab.is-on { background: #16233a; color: #ffffff; }
html[data-mode="light"] .ord-tab-n { background: #f1f3f6; color: #46586f; }
html[data-mode="light"] .ord-tab.is-on .ord-tab-n { background: rgba(255,255,255,.18); color: #ffffff; }
html[data-mode="light"] .ord-tab-n--pay,
html[data-mode="light"] .ord-tab.is-on .ord-tab-n--pay { background: #c23a63; color: #ffffff; }

html[data-mode="light"] .ord-showing { color: #46586f; }
html[data-mode="light"] .ord-showing strong { color: #16233a; }
html[data-mode="light"] .ord-search input {
  background: #ffffff; border-color: #dbe3ee; color: #16233a;
}
html[data-mode="light"] .ord-search input::placeholder { color: #46586f; }
html[data-mode="light"] .ord-search i,
html[data-mode="light"] .ord-search-clear { color: #46586f; }

html[data-mode="light"] .ordc {
  background: #ffffff; border-color: #e3e9f2;
  box-shadow: 0 1px 2px rgba(22,35,58,.04), 0 8px 24px rgba(22,35,58,.06);
}
html[data-mode="light"] .ordc-head,
html[data-mode="light"] .ordc-foot { border-color: #e3e9f2; }
html[data-mode="light"] .ordc-foot { background: #f8fafd; }
html[data-mode="light"] .ordc-no { color: #16233a; }
html[data-mode="light"] .ordc-date,
html[data-mode="light"] .ordc-note,
html[data-mode="light"] .ordc-item-sub,
html[data-mode="light"] .ordc-total-k,
html[data-mode="light"] .ordc-noitems,
html[data-mode="light"] .ordc-pickup,
html[data-mode="light"] .ord-pager-n { color: #46586f; }
html[data-mode="light"] .ordc-chip { background: #f2f5fa; border-color: #e3e9f2; color: #46586f; }
html[data-mode="light"] .ordc-item { border-color: #eef2f8; }
html[data-mode="light"] .ordc-thumb { background: #f2f5fa; border-color: #e3e9f2; }
html[data-mode="light"] .ordc-item-name,
html[data-mode="light"] .ordc-item-price,
html[data-mode="light"] .ordc-pickup b { color: #16233a; }
html[data-mode="light"] .ordc-rate { color: #1f4f86; }
html[data-mode="light"] .ordc-rated { color: #46586f; }
html[data-mode="light"] .ordb { color: #16233a; border-color: #dbe3ee; background: #ffffff; }
html[data-mode="light"] .ordb:hover { background: #f2f5fa; }
html[data-mode="light"] .ordb--on { background: #c23a63; border-color: #c23a63; color: #ffffff; }
html[data-mode="light"] .ordb--on:hover { background: #a8264d; border-color: #a8264d; }

html[data-mode="light"] .ord-empty { background: #ffffff; border-color: #dbe3ee; }
html[data-mode="light"] .ord-empty h2 { color: #16233a; }
html[data-mode="light"] .ord-empty p,
html[data-mode="light"] .ord-empty-icon { color: #46586f; }

/* Pills, exactly the values in the brief: background / text / border. */
html[data-mode="light"] .ordp--pay     { background: #fdecf1; color: #a8264d; border-color: #f6c9d6; }
html[data-mode="light"] .ordp--ship    { background: #fdf3e0; color: #8a5a00; border-color: #f2dcae; }
html[data-mode="light"] .ordp--shipped { background: #e6f0fb; color: #1f4f86; border-color: #c4d9f2; }
html[data-mode="light"] .ordp--done    { background: #e5f6ee; color: #17663f; border-color: #bfe6d1; }
html[data-mode="light"] .ordp--return  { background: #efeafc; color: #4f3aa3; border-color: #d8cff5; }
html[data-mode="light"] .ordp--cancel  { background: #f1f3f6; color: #46586f; border-color: #dbe3ee; }

/* Dark equivalents of the same six, tinted from the existing palette so the
   pills read as the same system rather than as light chips on a dark card. */
html:not([data-mode="light"]) .ordp--pay     { background: rgba(194,58,99,.16);  color: #f1a8bf; border-color: rgba(194,58,99,.42); }
html:not([data-mode="light"]) .ordp--ship    { background: rgba(252,211,77,.12); color: #fcd34d; border-color: rgba(252,211,77,.38); }
html:not([data-mode="light"]) .ordp--shipped { background: rgba(96,165,250,.14); color: #93c5fd; border-color: rgba(96,165,250,.40); }
html:not([data-mode="light"]) .ordp--done    { background: rgba(52,211,153,.14); color: #6ee7b7; border-color: rgba(52,211,153,.40); }
html:not([data-mode="light"]) .ordp--return  { background: rgba(127,106,224,.16);color: #b9adf4; border-color: rgba(127,106,224,.45); }
html:not([data-mode="light"]) .ordp--cancel  { background: rgba(148,163,184,.14);color: #cbd5e1; border-color: rgba(148,163,184,.34); }

/* --- the live region while a tab / page / search request is in flight -----
   Same dim-and-lock products.php uses for its filter XHR
   (.products-main.pf-busy), so the two lists behave the same way. No spinner:
   the partial is a few KB off localhost and lands well inside the 300ms where
   a spinner would only flash. The fade is suppressed under reduced motion
   along with everything else on this page. */
.ord-panel { transition: opacity .18s ease; }
.ord-panel.ord-busy { opacity: .45; pointer-events: none; }
@media (prefers-reduced-motion: reduce) {
    .ord-panel { transition: none; }
}
</style>

<section class="account-page ord-page">
    <div class="container">
        <div class="ord-head">
            <div class="ord-eyebrow"><span aria-hidden="true"></span>Order history</div>
            <h1>My <em>Orders</em></h1>
            <p class="ord-lede">Track and review your purchases</p>
        </div>
        <div class="ord-rule" aria-hidden="true"><i></i></div>

        <?php /* The live region. Everything inside is re-rendered by
                includes/orders_results.php alone for ?partial=1, so the tab
                bar, the count line and the list can be swapped in place.
                The id is what the script at the bottom targets. */ ?>
        <div id="ordPanel" class="ord-panel">
            <?php include __DIR__ . '/includes/orders_results.php'; ?>
        </div>

            <?php if (!empty($order)): ?>
            <div class="order-detail">
                <div class="ord-eyebrow"><span aria-hidden="true"></span>Order detail</div>
                <h2>Order #<?= htmlspecialchars($order['invoice_no']) ?></h2>
                <div class="order-meta">
                    <span>Status: <strong class="status-badge status-<?= $order['status'] ?>"><?= ucfirst($order['status']) ?></strong></span>
                    <span>Date: <?= date('M j, Y H:i', strtotime($order['created_at'])) ?></span>
                    <span>Total: <strong><?= formatCurrency($order['total_amount']) ?></strong></span>
                </div>
                <?php if (!empty($orderItems)): ?>
                <div class="order-items">
                    <?php foreach ($orderItems as $it): ?>
                    <div class="order-item">
                        <div class="img">
                            <?php if (!empty($it['image_url'])): ?>
                                <img src="<?= WEB_ROOT . htmlspecialchars($it['image_url']) ?>" alt="<?= htmlspecialchars($it['name']) ?>">
                            <?php else: ?>
                                <div class="image-placeholder">IMG</div>
                            <?php endif; ?>
                        </div>
                        <div class="meta">
                            <div class="title"><?= htmlspecialchars($it['name']) ?></div>
                            <div class="sub">Qty: <?= (int)$it['quantity'] ?> × <?= formatCurrency($it['price']) ?></div>
                            <?php $itSn = $orderItemSerials[(int)$it['id']] ?? []; ?>
                            <?php if ($itSn): ?>
                                <div class="ord-serials">
                                    <?= count($itSn) > 1 ? 'Serials:' : 'Serial:' ?>
                                    <?php foreach ($itSn as $sn): ?>
                                        <span><?= htmlspecialchars($sn) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="line-total"><?= formatCurrency($it['total']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
    </div>
</section>


<script>
/* ---- Live "time left to pay" countdowns ----------------------------------
   Starts from a seconds-remaining value already computed server-side in MySQL
   (see the seconds_until_expiry query above) - ticks down locally from there,
   no repeated server round-trips needed. Once it hits 0 the order hasn't
   actually been cancelled yet (that only happens server-side, next time this
   or another page loads and runs cancelExpiredOnlinePaymentOrders()) - this
   just tells the customer to refresh rather than pretending to remove the row.

   It is a named, re-runnable function rather than a one-shot DOMContentLoaded
   body because the list is now swapped in place: after a tab change the old
   pills are gone but their intervals would keep ticking against detached
   nodes, and the new pills would have no countdown at all. ordTimers is what
   makes re-running it safe. */
var ordTimers = [];

function ordFormatCountdown(seconds) {
    if (seconds <= 0) { return 'Expired - refresh'; }
    var h = Math.floor(seconds / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    var s = seconds % 60;
    // The window is 24 hours, so this cannot stay minutes-only - a fresh order
    // would have read "Pay within 1439:59". Above an hour it switches to
    // "23h 59m"; under an hour it falls back to mm:ss, where counting seconds
    // is genuinely useful.
    if (h > 0) { return 'Pay within ' + h + 'h ' + m + 'm'; }
    return 'Pay within ' + m + ':' + (s < 10 ? '0' : '') + s;
}

function ordInitCountdowns(root) {
    ordTimers.forEach(clearInterval);
    ordTimers = [];
    (root || document).querySelectorAll('.pay-countdown').forEach(function (el) {
        var remaining = parseInt(el.getAttribute('data-seconds-left'), 10) || 0;
        el.textContent = ordFormatCountdown(remaining);
        var timer = setInterval(function () {
            remaining -= 1;
            el.textContent = ordFormatCountdown(remaining);
            // Once it lapses the pill is no longer a countdown but a warning,
            // so it drops the amber for the page's alert pink.
            if (remaining <= 0) {
                clearInterval(timer);
                el.classList.add('is-expired');
            }
        }, 1000);
        ordTimers.push(timer);
    });
}

/* ---- Tabs, pager and search without a page reload -------------------------
   Clicking a tab used to be an ordinary link, so every click threw the whole
   246KB document away and rebuilt it. Instead we ask orders.php for the same
   URL with &partial=1, which renders only the live region
   (includes/orders_results.php), and swap that into #ordPanel. The address bar
   is kept in sync with history.pushState, so back/forward, refresh and
   copy-pasted links behave exactly as they did before.

   Every control stays a real <a href> or a real GET <form>: with JS off, or if
   a request fails, the page still works the way it always did. */
(function () {
    var ORD_URL = '<?= WEB_ROOT ?>public/authenticated/customer/orders.php';
    var panel = document.getElementById('ordPanel');
    if (!panel) { return; }

    var ordRequest = 0;         // request id - stale responses are discarded
    var ordController = null;   // aborts the in-flight request when a new one starts
    var ordSearchTimer = null;

    // Only the keys this page owns, in a stable order, with the defaults
    // dropped - the same shape ord_url() produces server-side, so a URL built
    // here and one rendered into an href are always the same string.
    function ordQuery(over) {
        var p = new URLSearchParams(location.search);
        Object.keys(over || {}).forEach(function (k) {
            if (over[k] === null || over[k] === '') { p.delete(k); } else { p.set(k, over[k]); }
        });
        if (p.get('tab') === 'all') { p.delete('tab'); }
        if (parseInt(p.get('page'), 10) <= 1) { p.delete('page'); }
        var out = new URLSearchParams();
        ['tab', 'q', 'page'].forEach(function (k) { if (p.get(k)) { out.set(k, p.get(k)); } });
        return out.toString();
    }

    // The href the server already rendered is the single source of truth for
    // where a control goes, so the client never re-derives the rules.
    function ordParamFromHref(el, key) {
        var href = el.getAttribute('href') || '';
        var m = href.match(new RegExp('[?&]' + key + '=([^&]*)'));
        return m ? decodeURIComponent(m[1]) : null;
    }

    function ordLoad(query, options) {
        options = options || {};
        var token = ++ordRequest;
        if (ordController) { ordController.abort(); }
        ordController = ('AbortController' in window) ? new AbortController() : null;

        panel.classList.add('ord-busy');
        panel.setAttribute('aria-busy', 'true');

        // Where the caret was, so live search does not lose it on the swap.
        var active = document.activeElement;
        var searchFocused = !!(active && active.matches && active.matches('.ord-search input[name="q"]'));
        var caret = searchFocused ? active.selectionStart : null;

        var partial = new URLSearchParams(query);
        partial.set('partial', '1');

        fetch(ORD_URL + '?' + partial.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: ordController ? ordController.signal : undefined
        })
        .then(function (response) {
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            // A lapsed session 302s to the login page and fetch follows it
            // silently, so "200 OK" is not enough on its own - only the
            // partial sends this header.
            if (response.headers.get('X-Ord-Partial') !== '1') { throw new Error('not the partial'); }
            return response.text();
        })
        .then(function (html) {
            if (token !== ordRequest) { return; }   // a newer click already won
            panel.innerHTML = html;
            panel.classList.remove('ord-busy');
            panel.removeAttribute('aria-busy');

            if (options.push !== false) {
                window.history.pushState({ ord: query }, '', ORD_URL + (query ? '?' + query : ''));
            }
            ordInitCountdowns(panel);

            if (searchFocused) {
                var input = panel.querySelector('.ord-search input[name="q"]');
                if (input) {
                    input.focus();
                    if (caret !== null) { try { input.setSelectionRange(caret, caret); } catch (e) {} }
                }
            }
            if (options.scroll) {
                var top = panel.getBoundingClientRect().top + window.pageYOffset - 90;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        })
        .catch(function (error) {
            if (error && error.name === 'AbortError') { return; }
            console.error('Orders request failed:', error);
            panel.classList.remove('ord-busy');
            panel.removeAttribute('aria-busy');
            // Last resort: the old full navigation, so the customer still gets
            // the tab they asked for.
            window.location.href = ORD_URL + (query ? '?' + query : '');
        });
    }

    // Delegated, because the controls themselves are replaced on every swap.
    // Scoped to the three things this owns - the card buttons are links to
    // other pages and must keep navigating normally.
    panel.addEventListener('click', function (e) {
        var tab = e.target.closest('.ord-tab');
        if (tab) {
            e.preventDefault();
            if (tab.classList.contains('is-on')) { return; }
            ordLoad(ordQuery({ tab: ordParamFromHref(tab, 'tab') || 'all', page: null }));
            return;
        }
        var pageLink = e.target.closest('.ord-pager .ordb');
        if (pageLink) {
            e.preventDefault();
            if (pageLink.classList.contains('is-off')) { return; }
            ordLoad(ordQuery({ page: ordParamFromHref(pageLink, 'page') }), { scroll: true });
            return;
        }
        var clear = e.target.closest('.ord-search-clear');
        if (clear) {
            e.preventDefault();
            ordLoad(ordQuery({ q: null, page: null }));
        }
    });

    // Live search. Debounced so a word is one request, not one per keystroke;
    // any new search starts the list over at page 1.
    // (main.js ships a debounce() helper, but it exposes no way to cancel a
    //  pending call - which Enter below has to do - so the timer is explicit.)
    panel.addEventListener('input', function (e) {
        if (!e.target.matches('.ord-search input[name="q"]')) { return; }
        var value = e.target.value;
        clearTimeout(ordSearchTimer);
        ordSearchTimer = setTimeout(function () {
            ordLoad(ordQuery({ q: value, page: null }));
        }, 300);
    });

    // Enter in the search box: go now rather than waiting out the debounce.
    panel.addEventListener('submit', function (e) {
        if (!e.target.matches('.ord-search')) { return; }
        e.preventDefault();
        clearTimeout(ordSearchTimer);
        var input = e.target.querySelector('input[name="q"]');
        ordLoad(ordQuery({ q: input ? input.value : '', page: null }));
    });

    // Back/forward: re-render from the URL without pushing a new entry.
    window.addEventListener('popstate', function () {
        ordLoad(ordQuery({}), { push: false });
    });
}());

document.addEventListener('DOMContentLoaded', function () { ordInitCountdowns(document); });
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
</body>
</html>
