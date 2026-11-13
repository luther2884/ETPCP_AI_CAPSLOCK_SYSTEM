<?php
// api/admin_order_stream.php - Real-time "a customer just ordered" feed for
// the admin panel.
//
// WHY A STREAM AND NOT A POLL
// The badges in admin/includes/header.php already poll every 30s, which is
// fine for a number sitting in a corner but useless as an alert: an order
// placed one second after a tick is invisible for the next twenty-nine, and
// the admin is usually looking at a different page - or a different tab -
// anyway. This endpoint holds the connection open instead and pushes the
// order the moment the row lands, so nothing has to be refreshed or clicked
// for the alert to fire.
//
// HOW IT STAYS CHEAP
//   * session_write_close() runs before the loop. PHP's session file is
//     locked for the life of a request, so a request that lives for a minute
//     would otherwise block EVERY other request from the same admin and the
//     whole panel would hang behind this one connection. Identity is read
//     first, then the lock is dropped; nothing below needs to write session.
//   * One small indexed query (`WHERE id > ?` on the primary key) per tick -
//     the same work the 30s badge poll already did, minus a fresh PHP
//     bootstrap and session read on every single tick.
//   * The connection retires after AOS_MAX_LIFETIME and the browser's
//     EventSource reconnects on its own, so Apache gets its worker thread
//     back periodically instead of having it pinned forever.
//
// NOT MISSING ORDERS ACROSS RECONNECTS
// Each event's SSE id is the order id, so on reconnect the browser sends
// `Last-Event-ID` by itself and we resume from exactly there. An order placed
// during the gap between retiring and reconnecting is still delivered.
declare(strict_types=1);

// Same session bucket the admin pages use. Set explicitly rather than leaning
// on the Referer-based routing in functions.php: EventSource does send a
// Referer, but an alert feed should not be one missing header away from
// quietly authenticating against the customer session instead.
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// --- Identity, decided while we still hold the session lock ------------------
$isAdminUser = isLoggedIn() && isAdmin();
// A stream started before the screen auto-locked keeps running, deliberately:
// the inactivity lock is about the keyboard, not about the kitchen, and an
// order that arrives while the screen is locked is exactly the one worth
// hearing. So $_SESSION['admin_locked'] is intentionally not checked here.
session_write_close();

if (!$isAdminUser) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

// 1s, not 2: this is the floor on how late an alert can be, and since the
// panel now holds ONE stream per browser instead of one per tab (see the
// transport note in admin/includes/new_order_alert.php) the extra tick costs
// a single indexed primary-key lookup per second, for the whole browser.
const AOS_POLL_SECONDS      = 1;   // how often the DB is checked
const AOS_MAX_LIFETIME      = 55;  // retire the connection after this many seconds
const AOS_HEARTBEAT_SECONDS = 15;  // comment frame, so an idle stream is not reaped
const AOS_MAX_BATCH         = 20;  // orders sent per tick, oldest first

/** The one query this endpoint runs in its loop. `id > ?` rides the PK. */
function aos_fetch_since(PDO $pdo, int $sinceId): array
{
    $stmt = $pdo->prepare("
        SELECT o.id,
               o.invoice_no,
               o.total_amount,
               o.delivery_method,
               o.payment_method_display,
               o.payment_status,
               o.status,
               o.created_at,
               TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) AS customer
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.id > ?
        ORDER BY o.id ASC
        LIMIT " . AOS_MAX_BATCH . "
    ");
    $stmt->execute([$sinceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Shape one row into what the toast actually needs. Money is formatted here
 *  so the client never has to think about decimals or the peso sign. */
function aos_payload(array $row): array
{
    $customer = trim((string)$row['customer']);
    return [
        'id'         => (int)$row['id'],
        'invoice'    => (string)$row['invoice_no'],
        'customer'   => $customer !== '' ? $customer : 'A customer',
        // The real peso sign, not the HTML entity: this value is read into a
        // screen-reader live region and a desktop notification as plain text
        // as well as into markup, and "&#8369;1,234.50" is only correct in one
        // of the three. JSON_UNESCAPED_UNICODE + a utf-8 content type carry it.
        'amount'     => "\u{20B1}" . number_format((float)$row['total_amount'], 2),
        'method'     => (string)$row['payment_method_display'],
        'paid'       => $row['payment_status'] === 'paid',
        'status'     => (string)$row['status'],
        'fulfilment' => $row['delivery_method'] === 'pickup' ? 'pickup' : 'delivery',
        'created_at' => (string)$row['created_at'],
    ];
}

try {
    $pdo = getConnection();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database unavailable']);
    exit;
}

// --- Where to resume from ----------------------------------------------------
// Precedence: the browser's Last-Event-ID (a reconnect - resume exactly) >
// ?since= (a fresh page - baseline at whatever that page already rendered) >
// MAX(id) (no hint at all - start silent rather than replaying history as if
// it were new).
$since = null;
$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? '';
if ($lastEventId !== '' && ctype_digit($lastEventId)) {
    $since = (int)$lastEventId;
} elseif (isset($_GET['since']) && ctype_digit((string)$_GET['since'])) {
    $since = (int)$_GET['since'];
}
if ($since === null) {
    $since = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM orders")->fetchColumn();
}

// --- Polling fallback --------------------------------------------------------
// One JSON answer and done, for the case where the stream never establishes.
// Same payload shape, so the client swaps transports without changing a line
// of how it handles an order.
if (($_GET['mode'] ?? '') === 'poll') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $rows = aos_fetch_since($pdo, $since);
    echo json_encode([
        'success' => true,
        'orders'  => array_map('aos_payload', $rows),
        'cursor'  => $rows ? (int)end($rows)['id'] : $since,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- SSE ---------------------------------------------------------------------
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
while (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // if a proxy is ever put in front, do not buffer

@set_time_limit(0);
// Aborts are deliberately NOT ignored: the loop has to notice the browser
// going away, or every closed tab leaves a worker spinning until the lifetime
// cap. connection_aborted() only updates once a write is attempted, which is
// half of what the heartbeat below is for.
ignore_user_abort(false);

/** Write one SSE frame and push it past every buffer PHP and Apache own. */
function aos_send(string $event, array $data, ?int $id = null): void
{
    if ($id !== null) { echo 'id: ' . $id . "\n"; }
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

// Reconnect delay for the browser. Deliberately short: this stream retires
// itself every minute by design, so reconnecting is the normal path, not the
// error path.
echo "retry: 3000\n\n";
// Padding. Some buffering layers hold a response back until they have "enough"
// bytes; this gets the stream flowing immediately instead of the first real
// order being the thing that unblocks it.
echo ':' . str_repeat(' ', 2048) . "\n\n";
@flush();

aos_send('ready', ['cursor' => $since]);

$startedAt  = time();
$lastBeatAt = time();

while (!connection_aborted() && (time() - $startedAt) < AOS_MAX_LIFETIME) {
    try {
        $rows = aos_fetch_since($pdo, $since);
    } catch (Throwable $e) {
        // A dropped DB connection should end this stream rather than spin on
        // it - the browser reconnects in 3s and gets a fresh PDO with it.
        error_log('admin_order_stream: ' . $e->getMessage());
        break;
    }

    foreach ($rows as $row) {
        $since = (int)$row['id'];
        aos_send('new-order', aos_payload($row), $since);
        $lastBeatAt = time();
    }

    // The comment frame keeps the socket warm and is what makes a closed tab
    // detectable at all: connection_aborted() stays 0 until a write fails.
    if ((time() - $lastBeatAt) >= AOS_HEARTBEAT_SECONDS) {
        echo ": ping\n\n";
        @flush();
        $lastBeatAt = time();
    }

    sleep(AOS_POLL_SECONDS);
}

// Say this was a planned retirement, not a failure, so the client does not
// count it toward the "stream is broken, fall back to polling" tally.
aos_send('cycle', ['cursor' => $since]);
