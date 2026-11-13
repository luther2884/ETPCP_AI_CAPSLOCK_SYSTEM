<?php
// Support API Endpoints
declare(strict_types=1);
header('Content-Type: application/json');
// No Access-Control-Allow-Origin. This used to send `*`, which on an endpoint
// that reads and writes support threads meant any site on the internet could
// script a logged-in customer's browser into reading their conversation and
// posting as them - the browser attaches the session cookie, and `*` tells it
// the response may be handed to the calling page. Every caller is same-origin
// (customer/support.php, admin/support_dashboard.php), so the header bought
// nothing and cost that. The OPTIONS preflight branch went with it: without
// CORS headers there is no cross-origin request to preflight.

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/rate_limit.php';

// Spam throttle. create_ticket now takes its identity from the session rather
// than from the request body, so this is no longer the only thing standing
// between a bot and the tickets table - but a signed-in customer can still
// hold the button down, and 20 requests per 10 minutes per IP is far above any
// real use and far below a flood.
//
// Staff get their own bucket and a far higher ceiling. 20/10min is a sensible
// limit on a contact form and a crippling one on an inbox: the admin support
// dashboard spends three requests just loading, one more per ticket opened and
// two per reply sent, so a single person working a queue would be rate-limited
// out of their own job inside a few minutes. The ceiling still exists because
// a hijacked admin session should not be able to mass-message customers.
// supportIsStaff() is declared further down - PHP hoists top-level functions.
if (($_GET['action'] ?? '') === 'poll') {
    /* The live-update heartbeat gets its own bucket, and it has to.
       Both screens poll every few seconds while a conversation is open, which
       on the customer's 120-per-10-minutes ceiling is the entire budget spent
       on heartbeats - a customer would have been 429'd out of their own ticket
       within minutes of opening it, and the "realtime" updates would have
       stopped dead with them.

       Sized for the interval rather than guessed: 3s polling is 20 requests a
       minute, so 900 per 10 minutes leaves room for a few tabs open at once
       and still refuses a script hammering the endpoint. It is a far higher
       ceiling than the others because what it guards is far cheaper - two
       indexed aggregates and, at most, the handful of messages written since
       the client's last id. Nothing here writes. */
    rl_guard('support_poll', 900, 600);
} elseif (supportIsStaff()) {
    rl_guard('support_api_staff', 300, 600);
} else {
    /* 20/10min was right when customer/support.php was a send-only contact
       form: one request per visit. It now carries a two-way conversation -
       list the tickets, open a thread, mark it read, send, re-read - so a
       customer answering a few replies used to be locked out of their own
       support thread mid-sentence. Same reasoning that gave staff 300.

       The ceiling still exists, and the thing it actually has to stop -
       flooding the inbox with new tickets - is now guarded separately and far
       more tightly inside createSupportTicket(), where it belongs. Reading and
       replying to your own thread is cheap; opening tickets is not. */
    rl_guard('support_api', 120, 600);
}

// The two ENUMs on support_tickets. Whitelisted rather than passed through:
// MySQL turns an unknown ENUM value into '' on write, which would quietly put
// a ticket into a state no filter can find again.
/* The four states the two support screens show, plus the two legacy values
   already stored on live rows. 'in_progress' and 'closed' are not offered in
   either UI any more - they are accepted so existing tickets stay valid and
   are displayed as Pending and Resolved respectively. See
   DBSQL/add_support_ticket_conversation.sql. */
const SUPPORT_STATUSES   = ['open', 'pending', 'in_progress', 'resolved', 'closed', 'reopened'];

/* A resolved thread is read-only. Listed once here because three places need
   the same answer: the composer lock, the send guard, and the badge. */
const SUPPORT_CLOSED_STATUSES = ['resolved', 'closed'];

/* How a filter value expands to the stored values it covers.
   The admin inbox offers the four states the feature defines; two older values
   are still on live rows and are displayed as Pending and Resolved, so asking
   for Pending has to return both 'pending' and 'in_progress' or a ticket
   disappears from the filter whose badge it is wearing. Anything absent here
   matches only itself. */
const SUPPORT_STATUS_GROUPS = [
    'pending'  => ['pending', 'in_progress'],
    'resolved' => ['resolved', 'closed'],
];
const SUPPORT_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

try {
    $pdo = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'create_ticket':
            if ($method === 'POST') {
                createSupportTicket($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_tickets':
            if ($method === 'GET') {
                getSupportTickets($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_ticket':
            if ($method === 'GET') {
                getSupportTicket($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'update_ticket':
            if ($method === 'PUT') {
                updateSupportTicket($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'archive_ticket':
            // POST, not PUT: it is its own action rather than a field on
            // update_ticket, because archiving has a precondition that
            // changing a status does not (the ticket must already be
            // resolved) and because it must not be reachable by a GET.
            if ($method === 'POST') {
                archiveSupportTicket($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'mark_read':
            // POST, like every other mutating action here: it writes a read
            // marker, and a GET that changes state is reachable by a prefetch
            // or a link.
            if ($method === 'POST') {
                markTicketRead($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'add_message':
            if ($method === 'POST') {
                addTicketMessage($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'poll':
            // The live-update heartbeat both support screens call on a timer.
            // Read-only and deliberately tiny - see supportPoll().
            if ($method === 'GET') {
                supportPoll($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_faq':
            if ($method === 'GET') {
                getFAQ($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'search_faq':
            if ($method === 'GET') {
                searchFAQ($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_faq_categories':
            if ($method === 'GET') {
                getFAQCategories($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        case 'add_faq':
            if ($method === 'POST') {
                addFAQ($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

/**
 * The customer contact form on customer/support.php.
 *
 * This had never successfully run. Three separate faults, all of which had to
 * go for a message to send (the table has held 0 rows since it was created):
 *   1. the page POSTed to WEB_ROOT . 'api/support.php' - no such file, and no
 *      rewrite to it, so the fetch 404ed and the page showed its catch-all
 *      "An error occurred. Please try again.";
 *   2. the INSERT below named category/order_number/phone and read back
 *      ticket_number, four columns support_tickets did not have until
 *      DBSQL/add_support_ticket_fields.sql;
 *   3. the submitter was whoever the request body said they were.
 */
function createSupportTicket($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = []; }

    // WHO IS ASKING comes from the session, never from the payload. This used
    // to call getOrCreateUser(), which looked the submitter up by the email in
    // the form and CREATED a customer account when it found none - so an
    // unauthenticated POST could mint an account on any address it liked, and
    // a signed-in customer who mistyped their email filed their ticket under a
    // stranger. Support threads need an account to reply into; that account is
    // the one already signed in.
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Please sign in to send us a message, so we can reply to you.'
        ]);
        return;
    }
    $user_id = getUserId();

    // check_csrf() only ever looks at $_POST, which PHP leaves empty for a
    // JSON body, so the token is copied across rather than compared here - one
    // place decides whether a token is valid. Same handling as
    // api/change_password.php.
    if (isset($input['csrf_token']) && is_string($input['csrf_token'])) {
        $_POST['csrf_token'] = $input['csrf_token'];
    }
    if (!check_csrf()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your session has expired. Please refresh the page and try again.'
        ]);
        return;
    }

    // The form's topic dropdown. Whitelisted rather than trusted: it decides a
    // stored value, and the human subject line is derived from it here so the
    // admin inbox cannot be fed an arbitrary 255-character heading.
    $topics = [
        'product_inquiry'  => 'Product Inquiry',
        'order_support'    => 'Order Support',
        'technical_issue'  => 'Technical Issue',
        'warranty_claim'   => 'Warranty Claim',
        'return_request'   => 'Return Request',
        'billing_question' => 'Billing Question',
        'other'            => 'Other',
    ];
    $category = (string)($input['subject'] ?? $input['category'] ?? '');
    if (!isset($topics[$category])) { $category = 'other'; }

    /* Every limit below is also on the form (maxlength, minlength, the order
       picker), and every one of them is re-decided here. The form's version is
       a courtesy to whoever is typing; this is the rule, because nothing stops
       a client posting straight to this endpoint. Each rejection names the
       `field` it is about so the page can put the complaint on the right box
       instead of only in a banner. */

    $message = trim((string)($input['message'] ?? ''));
    if ($message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'field' => 'message', 'message' => 'Please tell us what you need help with.']);
        return;
    }
    /* A floor as well as a ceiling. "fix it" and "???" are tickets nobody can
       act on: they cost a notification, a queue slot and a round trip to ask
       what was meant. 15 characters is below any real question and above a
       keyboard mash. */
    if (mb_strlen($message) < 15) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'field'   => 'message',
            'message' => 'Please tell us a little more - at least 15 characters, so we can act on it.'
        ]);
        return;
    }
    /* 2000 characters is what the form's counter promises. mb_strlen, not
       strlen: a message in Filipino or full of emoji is measured the way it
       was typed. The column is TEXT - 65535 BYTES - which 2000 characters
       cannot overflow even at 4 bytes each, so this ceiling is about what a
       person can read, not what MySQL can hold. */
    if (mb_strlen($message) > 2000) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'field'   => 'message',
            'message' => 'That message is too long. Please keep it under 2000 characters.'
        ]);
        return;
    }

    /* Phone: optional, but when it is given it has to be a phone number. It
       used to be stored as mb_substr($phone, 0, 30) and nothing else, so
       whatever was typed became the number the support team calls back on.
       7-15 digits covers 09171234567, +63 917 123 4567 and a landline with an
       area code, and refuses prose. */
    $phone = trim((string)($input['phone'] ?? ''));
    if ($phone !== '') {
        $phoneDigits = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^[0-9+()\s-]{7,20}$/', $phone)
            || strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'field'   => 'phone',
                'message' => 'That does not look like a phone number. Leave it blank if you would rather we emailed you.'
            ]);
            return;
        }
    }

    /* The order this is about, and the one field a customer could previously
       put anything in: the form had a free-text "Order Number" box, and the
       tickets table has rows like "41412341241241" to show for it - a number
       belonging to no order, which support then has to chase. The form now
       offers only this customer's own orders, and this resolves whatever
       arrives against the orders table BY user_id: an order that is not
       theirs is refused, exactly as a made-up one is.

       `order_id` is what the picker sends. `order_number` is still read so an
       older cached copy of the page keeps working - it goes through the same
       ownership check, matching whichever reference the customer has in front
       of them (the row id, the invoice number, or the tracking code). */
    $orderNumber = null;
    $orderRef = trim((string)($input['order_id'] ?? $input['order_number'] ?? ''));
    if ($orderRef !== '') {
        if (mb_strlen($orderRef) > 50) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'field'   => 'order_number',
                'message' => 'We could not find that order on your account. Please pick it from the list.'
            ]);
            return;
        }
        $orderStmt = $pdo->prepare(
            'SELECT id, invoice_no
               FROM orders
              WHERE user_id = ?
                AND (id = ? OR invoice_no = ? OR tracking_code = ?)
              LIMIT 1'
        );
        // The id slot is bound as a real integer, or as -1 when the
        // reference is not digits: MySQL would otherwise coerce the string
        // for that comparison ('12abc' -> 12), matching an order the customer
        // never actually named. The two text columns take the raw reference.
        $orderIdRef = ctype_digit($orderRef) ? (int)$orderRef : -1;
        $orderStmt->execute([$user_id, $orderIdRef, $orderRef, $orderRef]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'field'   => 'order_number',
                'message' => 'We could not find that order on your account. Please pick it from the list, or leave it blank.'
            ]);
            return;
        }
        // Stored as the reference the customer sees on the order itself, so
        // the admin inbox and customer/orders.php say the same thing.
        $orderNumber = ($order['invoice_no'] ?? '') !== '' ? $order['invoice_no'] : '#' . (int)$order['id'];
    }

    /* Opening tickets is the expensive action - each one lands in the admin
       inbox, writes an audit row, sends a notification and an email - so it
       keeps a tight ceiling of its own even though the general support budget
       above is now generous enough for a conversation. Five in ten minutes is
       far past any real person filing a complaint and far short of useful for
       flooding the queue.

       Deliberately AFTER the validation above, not before it: this counts
       tickets opened, not forms rejected. Charging a customer's five attempts
       to a mistyped phone number locked them out of support for ten minutes
       over a typo. Raw request volume is still capped - rl_guard('support_api',
       120, 600) at the top of this file charges every call, valid or not, so
       hammering this endpoint with junk is throttled there, where it costs a
       parse and nothing else. */
    rl_guard('support_ticket_create', 5, 600,
        'You have opened several tickets just now. Please wait a few minutes, or reply on an existing ticket instead.');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO support_tickets
            (user_id, subject, category, order_number, phone, message, status, priority)
            VALUES (?, ?, ?, ?, ?, ?, 'open', 'medium')
        ");
        // Both values are already validated and within their columns
        // (order_number varchar(50), phone varchar(30)), so there is nothing
        // left to truncate here - what reaches the row is what was checked.
        $stmt->execute([
            $user_id,
            $topics[$category],
            $category,
            $orderNumber,
            $phone !== '' ? $phone : null,
            $message,
        ]);

        $ticket_id = (int)$pdo->lastInsertId();

        // Derived from the row's own id, so it is unique without a counter
        // table and readable on the phone: TW-20261006-00042.
        $ticket_number = 'TW-' . date('Ymd') . '-' . str_pad((string)$ticket_id, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE support_tickets SET ticket_number = ? WHERE id = ?")
            ->execute([$ticket_number, $ticket_id]);

        // The opening message is also the first row of the thread, so the
        // conversation view has somewhere to start rather than special-casing
        // the ticket's own `message` column as "message zero".
        $pdo->prepare("
            INSERT INTO support_ticket_messages
            (ticket_id, user_id, message, is_internal)
            VALUES (?, ?, ?, FALSE)
        ")->execute([$ticket_id, $user_id, $message]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // The reason goes to the log, not to the browser: the old version
        // echoed $e->getMessage(), which handed every visitor the table and
        // column names behind a failed query.
        error_log('Create support ticket error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'We could not send your message right now. Please try again.']);
        return;
    }

    // Everything past the commit is best-effort - the ticket exists, and a
    // failed courtesy email must not report the message as unsent.
    try {
        logAudit('support_ticket_created', 'support_tickets', $ticket_id, null, [
            'ticket_number' => $ticket_number,
            'category'      => $category,
        ]);
    } catch (Throwable $e) { error_log('Support ticket audit failed: ' . $e->getMessage()); }

    try {
        createNotification(
            $user_id,
            'system',
            'We got your message',
            'Your support request ' . $ticket_number . ' (' . $topics[$category] . ') has been received. We will reply within 24 hours.',
            ['ticket_id' => $ticket_id, 'ticket_number' => $ticket_number]
        );
    } catch (Throwable $e) { error_log('Support ticket notification failed: ' . $e->getMessage()); }

    try {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        // The account's address, not the one typed into the form: the
        // confirmation is for the person whose ticket this now is.
        $email = (string)$stmt->fetchColumn();
        if ($email !== '') { sendSupportNotification($email, $ticket_number, $topics[$category]); }
    } catch (Throwable $e) { error_log('Support ticket email failed: ' . $e->getMessage()); }

    echo json_encode([
        'success'       => true,
        'message'       => 'Support ticket created successfully',
        'ticket_number' => $ticket_number,
        'ticket_id'     => $ticket_id,
    ]);
}

/**
 * Staff = the people who answer tickets: admin, manager, super-admin.
 *
 * Deliberately not requireFAQAdmin(): the ticket endpoints are not staff-only.
 * A customer reads and replies to their OWN thread through the same actions,
 * so this answers "may this session act on ANY ticket" - and every endpoint
 * below pairs a false with an owner check rather than a flat refusal.
 */
/**
 * Unread messages for one side of a thread.
 *
 * "Unread" is every message the OTHER side wrote after this side last opened
 * the ticket. Internal notes never count toward the customer's badge - they
 * are not shown to the customer at all, so a badge for one would point at
 * nothing.
 *
 * A NULL read marker means the side has never opened the thread, in which
 * case everything the other side wrote is unread.
 */
function supportUnreadCount(PDO $pdo, int $ticketId, int $ticketOwnerId, bool $forStaff, int $lastReadId): int
{
    // id > marker, never created_at > marker: TIMESTAMP resolves to the second,
    // so a reply written in the same second as the reader's own last action
    // compares as already-read and the badge never lights. Ids are exact.
    $sql = "SELECT COUNT(*) FROM support_ticket_messages
            WHERE ticket_id = ? AND id > ?";
    $params = [$ticketId, $lastReadId];

    if ($forStaff) {
        // What the customer wrote.
        $sql .= " AND user_id = ?";
        $params[] = $ticketOwnerId;
    } else {
        // What anyone other than the customer wrote, minus internal notes -
        // the customer is never shown those, so a badge for one would point at
        // a message that is not there.
        $sql .= " AND user_id <> ? AND is_internal = 0";
        $params[] = $ticketOwnerId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Who wrote a message, for the bubble side.
 *
 * Derived from the ticket's owner rather than users.role: the owner IS the
 * customer on their own thread by definition, and a role that changes later
 * (a customer hired as staff) must not retroactively move their old messages
 * to the other side of the conversation.
 */
function supportSenderSide(int $authorId, int $ticketOwnerId): string
{
    return $authorId === $ticketOwnerId ? 'customer' : 'admin';
}

/** A resolved ticket takes no new messages from either side until reopened. */
function supportThreadIsLocked(string $status): bool
{
    return in_array($status, SUPPORT_CLOSED_STATUSES, true);
}

function supportIsStaff(): bool
{
    return isLoggedIn() && (isAdmin() || isSuperAdmin());
}

/**
 * Copy a JSON body's CSRF token where check_csrf() can see it.
 *
 * check_csrf() only ever reads $_POST, which PHP leaves empty for a JSON
 * request body. Same handling as createSupportTicket() above, so one place
 * still decides whether a token is valid.
 */
function supportCheckCsrf(array $input): bool
{
    if (isset($input['csrf_token']) && is_string($input['csrf_token'])) {
        $_POST['csrf_token'] = $input['csrf_token'];
    }
    return check_csrf();
}

function getSupportTickets($pdo) {
    // Listing tickets means reading other people's support threads, so this
    // needs a session. It used to need none: the endpoint filtered by whatever
    // user_id the query string asked for and asked for nothing by default, so
    // a bare ?action=get_tickets handed every customer's subject lines, names
    // and email addresses to any caller.
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in.']);
        return;
    }

    // Staff may scope the list to one customer; a customer is scoped to
    // themselves whatever the query string asks for.
    $user_id = supportIsStaff() ? ($_GET['user_id'] ?? null) : getUserId();

    $status   = $_GET['status']   ?? null;
    $priority = $_GET['priority'] ?? null;
    // The admin inbox has always sent &priority=, and this read it nowhere -
    // so picking a priority filtered nothing and the list looked broken.
    if ($status   !== null && !in_array($status,   SUPPORT_STATUSES,   true)) { $status   = null; }
    if ($priority !== null && !in_array($priority, SUPPORT_PRIORITIES, true)) { $priority = null; }

    // Clamped: LIMIT is bound as an int, so a huge value is not an injection,
    // but it is a way to ask the server to render every ticket ever filed.
    $limit  = max(1, min(200, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where_conditions = [];
    $params = [];

    if ($user_id) {
        $where_conditions[] = "st.user_id = ?";
        $params[] = $user_id;
    }

    if ($status) {
        /* Filter by what the UI SHOWS, not by the single stored string.
           Both screens display 'in_progress' as Pending and 'closed' as
           Resolved, so a plain `status = ?` meant a ticket badged "Pending"
           was missing from the Pending filter - the row was stored as
           in_progress. Grouping them here keeps the badge and the filter
           telling the same story, and it is the only place that mapping has
           to live. Statuses with no legacy twin match just themselves. */
        $statusGroup = SUPPORT_STATUS_GROUPS[$status] ?? [$status];
        $where_conditions[] = 'st.status IN (' . implode(',', array_fill(0, count($statusGroup), '?')) . ')';
        foreach ($statusGroup as $s) { $params[] = $s; }
    }

    if ($priority) {
        $where_conditions[] = "st.priority = ?";
        $params[] = $priority;
    }

    /* ARCHIVED IS A SEPARATE AXIS from status, so it is scoped separately and
       the default is "not archived". An archived ticket is still a real ticket
       with a real status - leaving it in the inbox is the thing the button
       exists to stop. `archived=1` asks for the archive instead of the inbox;
       nothing asks for both at once, because a list mixing them would need a
       column to tell them apart. */
    //
    // STAFF ONLY. Archive is a property of the STAFF INBOX, not of the
    // ticket's existence - so it must not touch what a customer sees. A
    // customer looking at their own support history has to keep seeing every
    // ticket they ever filed; silently dropping one because an admin tidied
    // their queue would look to them like the shop lost their complaint.
    if (supportIsStaff()) {
        $archived = isset($_GET['archived']) ? (string)$_GET['archived'] : '0';
        $where_conditions[] = $archived === '1' ? 'st.is_archived = 1' : 'st.is_archived = 0';
    }

    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "
        SELECT
            st.id,
            st.ticket_number,
            st.subject,
            st.category,
            st.status,
            st.priority,
            st.is_archived,
            st.archived_at,
            st.created_at,
            st.updated_at,
            st.user_id,
            st.customer_last_read_id,
            st.admin_last_read_id,
            u.firstname,
            u.lastname,
            u.email
        FROM support_tickets st
        JOIN users u ON st.user_id = u.id
        $where_clause
        /* WORK-QUEUE ORDER: what still needs attention floats to the top.
           Was `created_at DESC`, which buried an urgent open ticket under a
           week of resolved ones.

           Two keys, in this order, and the order between them is the decision:

             1. still open?  resolved/closed sink to the bottom. A resolved
                ticket needs nothing from anybody, so it loses to an open one
                whatever its priority - an urgent ticket that is already dealt
                with is not work.
             2. priority     urgent, high, medium, low.

           FIELD() rather than a CASE ladder for the priority rank: the enum
           has exactly these four values, so FIELD returns 1-4 and ASC puts
           urgent first. (A value outside the list would return 0 and sort
           first - impossible here, the column is an ENUM.)

           created_at DESC stays as the final tiebreak, so within one bucket
           the list reads as it always did: newest first. */
        ORDER BY
            CASE WHEN st.status IN ('resolved', 'closed') THEN 1 ELSE 0 END ASC,
            FIELD(st.priority, 'urgent', 'high', 'medium', 'low') ASC,
            st.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Unread badge for whichever side is asking. Computed per ticket rather
       than in the list query because the two sides count different messages -
       see supportUnreadCount(). A page of 50 tickets is 50 cheap COUNTs
       against (ticket_id, created_at), which the migration indexes. */
    $listForStaff = supportIsStaff();
    foreach ($tickets as &$__t) {
        $__t['unread_count'] = supportUnreadCount(
            $pdo,
            (int)$__t['id'],
            (int)$__t['user_id'],
            $listForStaff,
            (int)($listForStaff ? ($__t['admin_last_read_id'] ?? 0) : ($__t['customer_last_read_id'] ?? 0))
        );
        $__t['is_locked'] = supportThreadIsLocked((string)$__t['status']) ? 1 : 0;
        // Read markers are internal bookkeeping; the client only needs the count.
        unset($__t['customer_last_read_id'], $__t['admin_last_read_id']);
    }
    unset($__t);

    // Get total count
    $count_sql = "SELECT COUNT(*) FROM support_tickets st $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute(array_slice($params, 0, -2));
    $total = $count_stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'tickets' => $tickets,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset
    ]);
}

/**
 * Live-update heartbeat for both support screens.
 *
 * Called on a timer, so the whole design goal is to be cheap enough to call
 * on a timer and to hand back the smallest thing that still lets a screen
 * update itself:
 *
 *  - `digest`  - a short string that changes whenever ANYTHING the caller can
 *                see changes: a new ticket, a new message, a status change.
 *                The client keeps the last one and only re-renders its ticket
 *                list when it differs, so the expensive list query runs when
 *                something actually happened rather than every few seconds.
 *  - `thread`  - with ?ticket_id, only the messages written after ?after_id,
 *                plus the thread's current status. That is what lets a reply
 *                be appended to an open conversation instead of re-fetching
 *                and re-rendering it, which would throw away the reader's
 *                scroll position and anything half-typed in the composer.
 *
 * Polling, not WebSockets or SSE, and that is a deliberate call for this
 * stack. A WebSocket needs a long-lived server process that Apache/mod_php
 * does not provide. SSE is worse than it looks here: PHP's session files are
 * locked for the life of a request, so one hanging stream blocks every other
 * request from that same session - the page would freeze itself - and each
 * open stream also holds an Apache worker hostage. A few indexed aggregates
 * every three seconds costs less and cannot wedge the site.
 *
 * Read-only: nothing in here writes. Marking a thread read stays an explicit
 * mark_read, so merely having a tab open never clears an unread badge.
 */
function supportPoll($pdo)
{
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in.']);
        return;
    }

    $isStaff = supportIsStaff();
    $me      = (int)getUserId();

    /* Scope: staff see every thread, a customer only their own. The scope is
       applied to the digest and the unread total as well as to the thread, so
       a customer's heartbeat can never reveal that someone else's ticket moved. */
    $scopeSql    = $isStaff ? '' : ' WHERE st.user_id = ?';
    $scopeParams = $isStaff ? [] : [$me];

    /* Ticket count + newest ticket activity. add_message and every status
       change both touch support_tickets.updated_at, so these two plus the
       newest message id below cover all three kinds of change. UNIX_TIMESTAMP
       so the value is a plain integer rather than a locale-formatted string. */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c,
               COALESCE(MAX(UNIX_TIMESTAMP(st.updated_at)), 0) AS u,
               /* A fingerprint of every visible ticket's status.
                  updated_at alone is not enough, and the reason is the same
                  one-second resolution that bites everywhere else in this
                  feature: an admin resolving a ticket in the same second as
                  the previous activity leaves MAX(updated_at) unchanged, the
                  digest identical, and the status pill in the other side's
                  list stale until something else happened to move. XOR-ing a
                  checksum of id:status changes the instant any ticket's state
                  does, at no extra cost - it rides along with the scan this
                  query already does.

                  is_archived is in the checksum for exactly the same reason.
                  Archiving does not touch `status` and does not change the row
                  COUNT either, so without it the only thing moving was
                  MAX(updated_at) - and one archive in the same second as the
                  previous activity would leave the digest identical and the
                  other side's inbox still showing the ticket. */
               COALESCE(BIT_XOR(CRC32(CONCAT(st.id, ':', st.status, ':', st.is_archived))), 0) AS s
        FROM support_tickets st
        $scopeSql
    ");
    $stmt->execute($scopeParams);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'u' => 0, 's' => 0];

    /* Newest visible message id. Carried in the digest because updated_at is
       a TIMESTAMP and resolves only to the second: two messages in the same
       second would leave the digest unchanged and the second one would never
       be picked up. The id cannot tie. Internal notes are excluded for a
       customer so a staff-only note does not make their list flicker. */
    $msgSql = "
        SELECT COALESCE(MAX(m.id), 0)
        FROM support_ticket_messages m
        JOIN support_tickets st ON m.ticket_id = st.id
    ";
    $msgParams = [];
    if (!$isStaff) {
        $msgSql .= " WHERE st.user_id = ? AND m.is_internal = 0";
        $msgParams[] = $me;
    }
    $stmt = $pdo->prepare($msgSql);
    $stmt->execute($msgParams);
    $maxMessageId = (int)$stmt->fetchColumn();

    /* Unread across every visible thread, as ONE query rather than the
       per-ticket COUNT the list endpoint runs. At a poll every few seconds the
       per-ticket version would be fifty round trips a tick; this is one, and
       it is the same rule - messages the other side wrote above this side's
       read marker, internal notes excluded for the customer. */
    if ($isStaff) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM support_ticket_messages m
            JOIN support_tickets st ON m.ticket_id = st.id
            WHERE m.id > st.admin_last_read_id
              AND m.user_id = st.user_id
        ");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM support_ticket_messages m
            JOIN support_tickets st ON m.ticket_id = st.id
            WHERE st.user_id = ?
              AND m.id > st.customer_last_read_id
              AND m.user_id <> st.user_id
              AND m.is_internal = 0
        ");
        $stmt->execute([$me]);
    }
    $unreadTotal = (int)$stmt->fetchColumn();

    $response = [
        'success'      => true,
        'digest'       => $agg['c'] . ':' . $agg['u'] . ':' . $agg['s'] . ':' . $maxMessageId,
        'unread_total' => $unreadTotal,
    ];

    // ---------------------------------------------------------------- thread
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($ticketId > 0) {
        $afterId = max(0, (int)($_GET['after_id'] ?? 0));

        $stmt = $pdo->prepare("SELECT id, user_id, status FROM support_tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ticket not found']);
            return;
        }
        // Same ownership rule as get_ticket. Without it the heartbeat would be
        // a second, quieter way to read any conversation on the site.
        if (!$isStaff && (int)$ticket['user_id'] !== $me) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not your ticket']);
            return;
        }

        $sql = "
            SELECT stm.id, stm.user_id, stm.message, stm.is_internal, stm.created_at,
                   u.firstname, u.lastname
            FROM support_ticket_messages stm
            JOIN users u ON stm.user_id = u.id
            WHERE stm.ticket_id = ? AND stm.id > ?
        ";
        if (!$isStaff) { $sql .= " AND stm.is_internal = 0"; }
        // id order, not created_at: these are appended straight to the bottom
        // of an open thread, so they have to arrive in true send order.
        $sql .= " ORDER BY stm.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ticketId, $afterId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ownerId = (int)$ticket['user_id'];
        foreach ($messages as &$__m) {
            $__m['sender'] = supportSenderSide((int)$__m['user_id'], $ownerId);
        }
        unset($__m);

        $response['thread'] = [
            'ticket_id' => (int)$ticket['id'],
            'status'    => $ticket['status'],
            // So a composer can close the instant the other side resolves the
            // ticket, without the client re-deciding what "resolved" means.
            'is_locked' => supportThreadIsLocked((string)$ticket['status']) ? 1 : 0,
            'messages'  => $messages,
        ];
    }

    echo json_encode($response);
}

function getSupportTicket($pdo) {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in.']);
        return;
    }

    $ticket_id = $_GET['id'] ?? null;

    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ticket ID is required']);
        return;
    }

    // Get ticket details
    $stmt = $pdo->prepare("
        SELECT
            st.*,
            u.firstname,
            u.lastname,
            u.email,
            u.phone
        FROM support_tickets st
        JOIN users u ON st.user_id = u.id
        WHERE st.id = ?
    ");

    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        return;
    }

    // A thread is readable by the customer who opened it and by staff, nobody
    // else. Without this, ?id=1,2,3... walks every support conversation on the
    // site - name, email, phone and the whole message history.
    $isStaff = supportIsStaff();
    if (!$isStaff && (int)$ticket['user_id'] !== (int)getUserId()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not your ticket']);
        return;
    }

    // Get ticket messages. is_internal is the staff-only note: it exists so
    // support can write "customer has called twice, escalate" on the thread,
    // which is exactly the sentence that must not be served to the customer.
    $sql = "
        SELECT
            stm.*,
            u.firstname,
            u.lastname,
            u.email,
            u.role
        FROM support_ticket_messages stm
        JOIN users u ON stm.user_id = u.id
        WHERE stm.ticket_id = ?
    ";
    if (!$isStaff) { $sql .= " AND stm.is_internal = 0"; }
    /* id is the tiebreaker, and it matters. created_at is a TIMESTAMP, so it
       resolves to the second: two messages written inside the same second -
       an admin answering the moment a customer's line lands, which is exactly
       what a live conversation looks like - compare as equal, and `ORDER BY
       created_at` alone then returns them in whatever order the storage engine
       feels like. The thread came out scrambled, with a reply sitting above
       the message it answered. Ids are monotonic, so ties break into true
       send order. Same resolution trap the read markers already avoid by
       comparing ids - see supportUnreadCount(). */
    $sql .= " ORDER BY stm.created_at ASC, stm.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticket_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Which side of the conversation each message belongs on. Decided here,
       once, so the two screens cannot disagree about who said what. */
    $ownerId = (int)$ticket['user_id'];
    foreach ($messages as &$__m) {
        $__m['sender'] = supportSenderSide((int)$__m['user_id'], $ownerId);
    }
    unset($__m);

    // Whether the composer is open. The UI reads this instead of re-deriving
    // the rule from the status string.
    $ticket['is_locked'] = supportThreadIsLocked((string)$ticket['status']) ? 1 : 0;

    echo json_encode([
        'success'  => true,
        'ticket'   => $ticket,
        'messages' => $messages,
        'viewer'   => $isStaff ? 'admin' : 'customer'
    ]);
}

function updateSupportTicket($pdo) {
    // Changing a ticket's state is a staff action - it is what "resolved"
    // means on the admin inbox. A customer closing their own ticket is a
    // different feature with different wording; it is not this endpoint, and
    // until it exists a customer cannot reach this at all.
    if (!supportIsStaff()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Staff access required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = []; }

    if (!supportCheckCsrf($input)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
        return;
    }

    $ticket_id = $input['ticket_id'] ?? null;

    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ticket ID is required']);
        return;
    }

    // resolution_notes used to be in this list and there is no such column on
    // support_tickets - sending it threw "Unknown column" and came back as a
    // 500 with no hint of which field was at fault. Dropped rather than added:
    // nothing in either UI collects it.
    $update_fields = [];
    $params = [];

    if (isset($input['status'])) {
        if (!in_array($input['status'], SUPPORT_STATUSES, true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unknown status']);
            return;
        }
        $update_fields[] = "status = ?";
        $params[] = $input['status'];

        /* MOVING A TICKET BACK TO LIVE WORK UN-ARCHIVES IT.
           
           Archive is only ever set on a resolved ticket, and the inbox hides
           archived rows - so reopening an archived ticket without this would
           produce the one state this feature must never create: live work,
           with a customer waiting, invisible in the inbox. Handled here rather
           than in the UI that happens to do the reopening, so no caller can
           skip it. Re-resolving does not re-archive: that is a decision for
           whoever presses Archive. */
        if (!in_array($input['status'], SUPPORT_CLOSED_STATUSES, true)) {
            $update_fields[] = "is_archived = 0";
            $update_fields[] = "archived_at = NULL";
        }
    }

    if (isset($input['priority'])) {
        if (!in_array($input['priority'], SUPPORT_PRIORITIES, true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unknown priority']);
            return;
        }
        $update_fields[] = "priority = ?";
        $params[] = $input['priority'];
    }

    if (array_key_exists('assigned_to', $input)) {
        $update_fields[] = "assigned_to = ?";
        $params[] = $input['assigned_to'] !== null ? (int)$input['assigned_to'] : null;
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
        return;
    }

    // Read the thread first: the customer has to be told their ticket closed,
    // and after the UPDATE there is no record of what it closed from.
    $before = $pdo->prepare("SELECT user_id, ticket_number, subject, status FROM support_tickets WHERE id = ?");
    $before->execute([$ticket_id]);
    $ticket = $before->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        return;
    }

    $params[] = $ticket_id;

    $sql = "UPDATE support_tickets SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);

    if ($stmt->execute($params)) {
        // Tell the customer, but only when the state actually moved - saving
        // the same status twice is an admin's double-click, not news.
        $newStatus = $input['status'] ?? null;
        if ($newStatus !== null && $newStatus !== $ticket['status']) {
            $wording = [
                'open'        => 'has been reopened',
                'pending'     => 'is being worked on',
                'in_progress' => 'is being worked on',
                'resolved'    => 'has been marked resolved',
                'closed'      => 'has been closed',
                'reopened'    => 'has been reopened - you can reply again',
            ];
            addNotification(
                (int)$ticket['user_id'],
                'Support ticket ' . $ticket['ticket_number'],
                'Your ticket "' . $ticket['subject'] . '" ' . ($wording[$newStatus] ?? 'was updated') . '.',
                $newStatus === 'resolved' ? 'success' : 'info'
            );
        }
        echo json_encode(['success' => true, 'message' => 'Ticket updated successfully', 'status' => $newStatus]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update ticket']);
    }
}

/**
 * Mark a thread read for whichever side is asking.
 *
 * Called when a conversation is opened. Writing NOW() rather than the newest
 * message's timestamp on purpose: a message that lands between the read and
 * the write should still count as unread, and NOW() is the moment the reader
 * actually stopped looking.
 */
/**
 * Archive or un-archive one resolved ticket.
 *
 * Archiving takes a ticket out of the inbox without deleting it. The whole
 * point is that it is NOT destructive, so:
 *
 *   - the status is left exactly as it was. 'resolved' and 'closed' are
 *     different stored values that both display as Resolved, and overwriting
 *     either one would make un-archiving guess where to put the ticket back.
 *   - un-archive is the same endpoint with archived=false, so a mis-click is
 *     one click to undo rather than a database job.
 *   - archived_at is cleared on the way back out, so the column can never
 *     claim a date for a ticket that is not archived.
 *
 * ONLY A RESOLVED TICKET CAN BE ARCHIVED. Archiving live work would hide a
 * customer waiting for a reply, which is the one outcome a support inbox must
 * not allow. SUPPORT_CLOSED_STATUSES is the same constant the composer lock
 * and the badge use, so "is this resolved" is answered in one place rather
 * than re-derived here.
 */
function archiveSupportTicket($pdo) {
    if (!supportIsStaff()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Staff access required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = []; }

    if (!supportCheckCsrf($input)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
        return;
    }

    $ticket_id = (int)($input['ticket_id'] ?? 0);
    if ($ticket_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ticket ID is required']);
        return;
    }

    // Default true so a body of just {ticket_id} archives; false un-archives.
    $wantArchived = array_key_exists('archived', $input) ? (bool)$input['archived'] : true;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, ticket_number, status, is_archived FROM support_tickets WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ticket not found']);
            return;
        }

        // Checked under the row lock, not from whatever the list was showing
        // when the button was drawn: a colleague may have reopened the ticket
        // in the meantime, and archiving it then would bury a live thread.
        if ($wantArchived && !in_array((string)$ticket['status'], SUPPORT_CLOSED_STATUSES, true)) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Only a resolved ticket can be archived. This one is '
                           . str_replace('_', ' ', (string)$ticket['status']) . ' - resolve it first.'
            ]);
            return;
        }

        if ((int)$ticket['is_archived'] === ($wantArchived ? 1 : 0)) {
            $pdo->rollBack();
            echo json_encode([
                'success' => true,
                'message' => $wantArchived ? 'That ticket is already archived.' : 'That ticket is not archived.',
                'is_archived' => $wantArchived
            ]);
            return;
        }

        $pdo->prepare(
            'UPDATE support_tickets
                SET is_archived = ?, archived_at = ?, updated_at = NOW()
              WHERE id = ?'
        )->execute([$wantArchived ? 1 : 0, $wantArchived ? date('Y-m-d H:i:s') : null, $ticket_id]);

        $pdo->commit();

        logAudit(
            $wantArchived ? 'support_ticket_archived' : 'support_ticket_unarchived',
            'support_tickets',
            $ticket_id,
            ['is_archived' => (int)$ticket['is_archived']],
            ['is_archived' => $wantArchived ? 1 : 0, 'ticket_number' => $ticket['ticket_number']]
        );

        echo json_encode([
            'success' => true,
            'message' => $wantArchived
                ? 'Ticket ' . $ticket['ticket_number'] . ' archived.'
                : 'Ticket ' . $ticket['ticket_number'] . ' is back in the inbox.',
            'is_archived' => $wantArchived
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $_) {} }
        error_log('archiveSupportTicket: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not archive that ticket. Try again.']);
    }
}

function markTicketRead($pdo) {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in.']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = []; }

    if (!supportCheckCsrf($input)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
        return;
    }

    $ticket_id = (int)($input['ticket_id'] ?? 0);
    if ($ticket_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field 'ticket_id' is required"]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, user_id FROM support_tickets WHERE id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        return;
    }

    // Same rule as reading the thread: the owner or staff, nobody else. Without
    // it this would be a way to probe which ticket ids exist.
    $isStaff = supportIsStaff();
    if (!$isStaff && (int)$ticket['user_id'] !== (int)getUserId()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not your ticket']);
        return;
    }

    /* Advance to the newest message that EXISTS right now rather than to a
       clock reading. A message inserted between this SELECT and the UPDATE
       keeps an id above the marker and so stays unread, which is the answer
       the reader would expect - they never saw it. */
    $newest = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM support_ticket_messages WHERE ticket_id = ?");
    $newest->execute([$ticket_id]);
    $upTo = (int)$newest->fetchColumn();

    $col = $isStaff ? 'admin_last_read_id' : 'customer_last_read_id';
    // GREATEST so a stale tab marking an old point cannot rewind the marker
    // and resurrect messages the reader has already seen.
    $pdo->prepare("UPDATE support_tickets SET {$col} = GREATEST({$col}, ?) WHERE id = ?")
        ->execute([$upTo, $ticket_id]);

    echo json_encode(['success' => true, 'unread_count' => 0]);
}

function addTicketMessage($pdo) {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in.']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = []; }

    if (!supportCheckCsrf($input)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
        return;
    }

    $ticket_id = $input['ticket_id'] ?? null;
    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field 'ticket_id' is required"]);
        return;
    }

    // WHO IS WRITING comes from the session, never from the payload - the same
    // correction createSupportTicket() got. This used to require a user_id in
    // the body and insert it verbatim, so any caller could post a message into
    // any thread signed as anyone.
    $author_id = (int)getUserId();
    $isStaff   = supportIsStaff();

    $stmt = $pdo->prepare("SELECT id, user_id, ticket_number, subject, status FROM support_tickets WHERE id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        return;
    }
    if (!$isStaff && (int)$ticket['user_id'] !== $author_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not your ticket']);
        return;
    }

    $message = trim((string)($input['message'] ?? ''));
    if ($message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Please write a message first.']);
        return;
    }
    // TEXT is 65535 BYTES, not characters - same ceiling createSupportTicket()
    // guards, and for the same reason: MySQL truncates rather than refuses.
    if (strlen($message) > 20000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'That message is too long. Please shorten it a little.']);
        return;
    }

    /* A resolved thread is closed to BOTH sides until an admin reopens it.
       Enforced here, not only by disabling the textarea: the composer is
       client-side and a resolved ticket must not take a message from a stale
       tab, a replayed request, or a second browser left open on the thread. */
    if (supportThreadIsLocked((string)$ticket['status'])) {
        http_response_code(409);
        echo json_encode([
            'success'   => false,
            'code'      => 'thread_locked',
            'status'    => $ticket['status'],
            'message'   => $isStaff
                ? 'This ticket is resolved. Reopen it to add another message.'
                : 'This ticket has been marked resolved, so it is no longer accepting replies. Please open a new ticket if you still need help.',
        ]);
        return;
    }

    // Only staff can write a note the customer will not see. A customer asking
    // for one would be asking to hide a message from themselves.
    $is_internal = $isStaff && !empty($input['is_internal']) ? 1 : 0;

    $stmt = $pdo->prepare("
        INSERT INTO support_ticket_messages
        (ticket_id, user_id, message, is_internal)
        VALUES (?, ?, ?, ?)
    ");

    if ($stmt->execute([$ticket_id, $author_id, $message, $is_internal])) {
        /* Nudge the thread so the inbox sorts and reads as live, and move the
           sender's own read marker to now in the same statement - a message
           you just wrote is not unread to you, and leaving it would light the
           author's own badge the moment they sent it. */
        $newId   = (int)$pdo->lastInsertId();
        $readCol = $isStaff ? 'admin_last_read_id' : 'customer_last_read_id';
        $pdo->prepare("UPDATE support_tickets SET updated_at = NOW(), {$readCol} = ? WHERE id = ?")
            ->execute([$newId, $ticket_id]);

        // The customer has no ticket inbox to check - customer/support.php is
        // a send-only contact form - so a reply that sits in the database is a
        // reply they never receive. The notification bell is the only place
        // they will see it, which is why the text travels with it.
        if ($isStaff && !$is_internal) {
            /* createNotification(), not addNotification(): the latter writes
               only title/message/type, so the reply arrived in the bell with
               nothing identifying the ticket it came from - the customer read
               the words and then had to go hunting for the thread. The payload
               below is what customer/notifications.php turns into a link, and
               `url` is the explicit destination its getNotificationLink()
               honours before any of its guesses. */
            createNotification(
                (int)$ticket['user_id'],
                'info',
                'Reply to ticket ' . $ticket['ticket_number'],
                $message,
                [
                    'ticket_id'     => (int)$ticket_id,
                    'ticket_number' => $ticket['ticket_number'],
                    'url'           => WEB_ROOT . 'public/authenticated/customer/support.php?ticket='
                                       . rawurlencode((string)$ticket['ticket_number']),
                ]
            );
        }
        echo json_encode(['success' => true, 'message' => 'Message added successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to add message']);
    }
}

/**
 * Admin guard for the FAQ write endpoints.
 *
 * The rest of this file deliberately takes writes without a session check -
 * it serves the public contact form and trusts a user_id in the body (see the
 * rate-limit note at the top). That is not acceptable for the FAQ: these rows
 * are published to every customer, so anyone who could POST here could publish
 * text on the storefront. These two actions require a real admin session.
 *
 * The session is the admin one because functions.php routes /api/ requests by
 * Referer into TECHWRLD_ADM - see the session_name() block at the top of it.
 */
function requireFAQAdmin(): void
{
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
}

function getFAQCategories($pdo) {
    requireFAQAdmin();

    $stmt = $pdo->query("
        SELECT id, name
        FROM faq_categories
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");
    echo json_encode(['success' => true, 'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function addFAQ($pdo) {
    requireFAQAdmin();

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    // The token travels in the JSON body, not $_POST, so check_csrf() (which
    // reads $_POST) cannot see it - compare it here the same way it does.
    $token = (string)($input['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Reload the page and try again.']);
        return;
    }

    $categoryId = (int)($input['category_id'] ?? 0);
    $question   = trim((string)($input['question'] ?? ''));
    $answer     = trim((string)($input['answer'] ?? ''));
    $sortOrder  = (int)($input['sort_order'] ?? 0);
    $isActive   = !empty($input['is_active']) ? 1 : 0;

    if ($categoryId <= 0 || $question === '' || $answer === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Category, question and answer are all required.']);
        return;
    }

    // faq_items.category_id is a FK, so a bad id would fail as a 500 with a
    // constraint message. Check it first and say something useful instead.
    $catStmt = $pdo->prepare('SELECT id FROM faq_categories WHERE id = ? LIMIT 1');
    $catStmt->execute([$categoryId]);
    if (!$catStmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'That FAQ category no longer exists.']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO faq_items (category_id, question, answer, sort_order, is_active)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$categoryId, $question, $answer, $sortOrder, $isActive]);

    echo json_encode([
        'success' => true,
        'message' => 'FAQ added.',
        'id'      => (int)$pdo->lastInsertId(),
    ]);
}

function getFAQ($pdo) {
    $category_id = $_GET['category_id'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $where_conditions = ["fi.is_active = 1"];
    $params = [];
    
    if ($category_id) {
        $where_conditions[] = "fi.category_id = ?";
        $params[] = $category_id;
    }
    
    if ($search) {
        $where_conditions[] = "(fi.question LIKE ? OR fi.answer LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT 
            fi.*,
            fc.name as category_name
        FROM faq_items fi
        JOIN faq_categories fc ON fi.category_id = fc.id
        $where_clause
        ORDER BY fc.sort_order, fi.sort_order
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get categories
    $stmt = $pdo->prepare("SELECT * FROM faq_categories WHERE is_active = 1 ORDER BY sort_order");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'faqs' => $faqs,
        'categories' => $categories
    ]);
}

function searchFAQ($pdo) {
    $query = $_GET['q'] ?? '';
    
    if (empty($query)) {
        echo json_encode(['success' => true, 'faqs' => []]);
        return;
    }
    
    $search_term = "%$query%";
    
    $sql = "
        SELECT 
            fi.*,
            fc.name as category_name
        FROM faq_items fi
        JOIN faq_categories fc ON fi.category_id = fc.id
        WHERE fi.is_active = 1 
        AND (fi.question LIKE ? OR fi.answer LIKE ?)
        ORDER BY 
            CASE 
                WHEN fi.question LIKE ? THEN 1
                ELSE 2
            END,
            fi.view_count DESC
        LIMIT 20
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$search_term, $search_term, $search_term]);
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Update view count for found items
    if (!empty($faqs)) {
        $ids = array_column($faqs, 'id');
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("UPDATE faq_items SET view_count = view_count + 1 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    }
    
    echo json_encode([
        'success' => true,
        'faqs' => $faqs,
        'query' => $query
    ]);
}

// getOrCreateUser() used to live here. It looked a submitter up by the email
// in the request body and CREATED a customer account when it found none, which
// let an unauthenticated POST mint an account on any address it liked.
// createSupportTicket() takes its user from the session now, so nothing called
// it; removed rather than left loaded for the next caller.
//
// NOTE: api/warranty.php:524 still has its own copy, reached the same way (an
// unauthenticated POST to action=create_claim). Same hole, different endpoint -
// not touched here because it is a separate flow with its own form.

function sendSupportNotification($email, $ticket_number, $subject) {
    // Implement email sending here
    // For now, just log the notification
    error_log("Support notification sent to $email for ticket $ticket_number: $subject");
}
?>
