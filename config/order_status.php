<?php
declare(strict_types=1);

/**
 * config/order_status.php - the one place that knows what an order status is,
 * what may follow it, and who is allowed to make that happen.
 *
 * Before this file, `orders.status` was written from nine different places
 * (two admin queues, the orders API, the courier board, the pickup QR page,
 * the cancellation queue, the delivery report and two helpers in
 * functions.php), each with its own idea of what a legal move was. Two of the
 * six statuses - `packed` and `ready` - had never been used by a single real
 * order, because staff picking from a dropdown skip the middle.
 *
 * THE LADDERS
 *
 * There is no single status ladder: it forks on orders.delivery_method,
 * because a pickup order is never shipped.
 *
 *   delivery: awaiting_payment -> preparing -> ready -> shipped -> completed
 *   pickup:   awaiting_payment -> preparing -> ready -> completed
 *
 * `cancelled` is terminal and sits outside both ladders.
 *
 * `packed` was a rung on both ladders and is not one any more: it cost a
 * second scan at the counter between picking an order and setting it aside,
 * and the two scans always happened together. Preparing now goes straight to
 * Ready. The value is still in OS_ALL and in the column's ENUM - orders put
 * there by the queue dropdowns still exist and still have to display, and
 * os_next_scan_status() still answers a scan on one of them - but nothing
 * moves an order INTO it any more.
 *
 * WHO MOVES WHAT
 *
 *   awaiting_payment -> preparing   payment only. Never a scan. Set by the
 *                                   Xendit webhook, or by an admin verifying
 *                                   a payment by hand.
 *   preparing -> ready              a QR scan (os_advance_by_scan).
 *   ready -> shipped   (delivery)   a QR scan - handing to the driver.
 *   ready -> completed (pickup)     a QR scan - the customer collecting.
 *   shipped -> completed (delivery) the courier's existing delivery-proof
 *                                   flow, which already requires a photo.
 *   anything -> cancelled           the cancellation queue, or the unpaid
 *                                   sweep in functions.php.
 *
 * AWAITING_PAYMENT
 *
 * Orders paid online (gcash/paymaya/xendit) now start at `awaiting_payment`
 * rather than `preparing`: nobody picks stock for an order that has not been
 * paid for. Stock is still reserved at checkout, and the existing one-hour
 * sweep (cancelExpiredOnlinePaymentOrders() in functions.php) still cancels
 * and restores it if payment never lands. COD and pay-at-store orders are
 * unaffected and still start at `preparing`, because for those the money
 * arrives at handover, not before.
 */

require_once __DIR__ . '/database.php';
// Per-unit stock. The scan is where a tracked order's serial numbers are
// demanded (preparing -> ready) and where its units become sold.
require_once __DIR__ . '/product_units.php';
// The store warranty starts at the same moment a unit becomes sold - handover.
require_once __DIR__ . '/warranty.php';
// ...and that is also when the sale is written to the stock movement log, so
// the inventory history is complete rather than missing every sale.
require_once __DIR__ . '/inventory_stock.php';

/** Every value orders.status may hold, in ladder order. */
const OS_ALL = ['awaiting_payment', 'preparing', 'packed', 'ready', 'shipped', 'completed', 'cancelled'];

/** Payment methods where the money must land before anyone picks stock. */
const OS_PREPAID_METHODS = ['gcash', 'paymaya', 'xendit'];

/** How long the same order is locked out of another scan-driven change.
 *  Long enough to swallow a double-trigger from one label, short enough that
 *  staff working a real queue never stand waiting on it. */
const OS_SCAN_COOLDOWN_SECONDS = 10;

/** How a change was made. Stored on every history row. */
const OS_SOURCES = ['scan', 'webhook', 'manual', 'system', 'courier'];

/**
 * Statuses that assert the goods have been picked off the shelf or have left.
 * Reaching one of these without a serial recorded is the thing the gate in
 * os_advance_by_scan() exists to prevent, and the thing os_manual_set() has to
 * flag when a human overrides it.
 */
const OS_PICKED_STATUSES = ['packed', 'ready', 'shipped', 'completed'];

/**
 * The ladder for a fulfilment method. `cancelled` is deliberately absent:
 * it is reachable from anywhere and is never "the next step".
 */
function os_ladder(string $deliveryMethod): array {
    return $deliveryMethod === 'pickup'
        ? ['awaiting_payment', 'preparing', 'ready', 'completed']
        : ['awaiting_payment', 'preparing', 'ready', 'shipped', 'completed'];
}

/**
 * Human label. `ready` is the only one that reads differently per method, and
 * both admin queues already spelled it that way ("Ready for Delivery" /
 * "Ready for Pickup") - this keeps that wording rather than inventing a third.
 */
function os_label(string $status, string $deliveryMethod = 'delivery'): string {
    if ($status === 'ready') {
        return $deliveryMethod === 'pickup' ? 'Ready for Pickup' : 'Ready for Delivery';
    }
    $labels = [
        'awaiting_payment' => 'Awaiting Payment',
        'preparing' => 'Preparing',
        'packed' => 'Packed',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/** Where an order should start, given how it will be paid for. */
function os_initial_status(string $paymentMethod): string {
    return in_array($paymentMethod, OS_PREPAID_METHODS, true) ? 'awaiting_payment' : 'preparing';
}

/**
 * The next status a SCAN may move this order to, or null with a reason why
 * not. This is the whole "never skip, never go backward" rule, in one place.
 *
 * Returns ['next' => string|null, 'reason' => string|null].
 */
function os_next_scan_status(string $current, string $deliveryMethod): array {
    if ($current === 'cancelled') {
        return ['next' => null, 'reason' => 'This order was cancelled. Scanning cannot reopen it.'];
    }
    if ($current === 'completed') {
        return ['next' => null, 'reason' => 'This order is already completed. There is nothing further to scan.'];
    }
    // Payment is a different axis entirely - a scan must never stand in for
    // money arriving.
    if ($current === 'awaiting_payment') {
        return ['next' => null, 'reason' => 'This order has not been paid yet. It moves to Preparing automatically once payment succeeds.'];
    }
    // The courier app owns this step: it requires a delivery-proof photo,
    // which a barcode scan at the counter cannot provide.
    if ($current === 'shipped') {
        return ['next' => null, 'reason' => 'This order is out for delivery. The driver completes it from the courier board with a proof photo.'];
    }

    /* 'packed' is no longer a rung, but it is still a value an order can be
       sitting on - the queue dropdowns can set it, and older orders were put
       there when it was part of the ladder. Such an order is not stranded:
       its next scan is Ready, which is exactly where the old ladder sent it
       from Packed. Handled here rather than in the ladder so that nothing
       moves an order into Packed while every order already in it can still
       move out. */
    if ($current === 'packed') {
        return ['next' => 'ready', 'reason' => null];
    }

    $ladder = os_ladder($deliveryMethod);
    $i = array_search($current, $ladder, true);
    if ($i === false || !isset($ladder[$i + 1])) {
        return ['next' => null, 'reason' => 'No further step is available for this order.'];
    }
    return ['next' => $ladder[$i + 1], 'reason' => null];
}

/* =========================================================================
 * Schema. Additive and idempotent, the same "at first use" bootstrap the
 * rest of this codebase uses - no existing column or row is rewritten.
 * ===================================================================== */

function os_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) { return; }

    // 1. Widen the status enum. Checked first so the ALTER (which rebuilds the
    //    table) only ever runs once, not on every request.
    $col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos((string)$col['Type'], 'awaiting_payment') === false) {
        $pdo->exec("ALTER TABLE orders MODIFY COLUMN status
            ENUM('awaiting_payment','preparing','packed','ready','shipped','completed','cancelled')
            NOT NULL DEFAULT 'preparing'");
    }

    // 2. The scan token. Separate from tracking_code (which the customer is
    //    shown) and from pickup_code (6 digits of mt_rand - guessable) and
    //    from order_hash (a checkout de-duplication key, not a secret).
    $has = $pdo->query("SHOW COLUMNS FROM orders LIKE 'qr_token'")->fetch(PDO::FETCH_ASSOC);
    if (!$has) {
        $pdo->exec("ALTER TABLE orders
            ADD COLUMN qr_token CHAR(64) NULL DEFAULT NULL,
            ADD COLUMN qr_token_issued_at TIMESTAMP NULL DEFAULT NULL,
            ADD UNIQUE KEY uq_orders_qr_token (qr_token)");
    }

    // 3. Status history. Every change, whatever made it.
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_status_history (
        id BIGINT NOT NULL AUTO_INCREMENT,
        order_id INT(11) NOT NULL,
        old_status VARCHAR(30) NULL,
        new_status VARCHAR(30) NOT NULL,
        source VARCHAR(20) NOT NULL,
        actor_id INT(11) NULL,
        actor_name VARCHAR(120) NULL,
        reason VARCHAR(500) NULL,
        ip VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_order_created (order_id, created_at),
        KEY idx_created (created_at),
        CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/* =========================================================================
 * Tokens
 * ===================================================================== */

/**
 * The order's scan token, minting one on first use.
 *
 * 32 random bytes from random_bytes() - the same CSPRNG generateTrackingCode()
 * uses, but 256 bits instead of 32, because this one authorises a state
 * change rather than just naming an order. A token is per-order and stable:
 * the sticker printed at packing time is still the sticker scanned at handover.
 */
function os_qr_token(PDO $pdo, int $orderId): ?string {
    os_ensure_schema($pdo);

    $stmt = $pdo->prepare("SELECT qr_token FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }
    if (!empty($row['qr_token'])) { return (string)$row['qr_token']; }

    // Retry on the (astronomically unlikely) unique-key collision rather than
    // handing back a token that was never stored.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $token = bin2hex(random_bytes(32));
        try {
            $upd = $pdo->prepare("UPDATE orders SET qr_token = ?, qr_token_issued_at = NOW() WHERE id = ? AND qr_token IS NULL");
            $upd->execute([$token, $orderId]);
            if ($upd->rowCount() > 0) { return $token; }
            // Lost a race with a concurrent request - read back what it wrote.
            $stmt->execute([$orderId]);
            $again = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($again['qr_token'])) { return (string)$again['qr_token']; }
        } catch (PDOException $e) {
            if ((int)$e->getCode() !== 23000) { throw $e; }
        }
    }
    return null;
}

/** Look up an order from a scanned token. Null when the token is unknown. */
function os_order_by_token(PDO $pdo, string $token): ?array {
    os_ensure_schema($pdo);
    // Tokens are fixed-length hex; anything else cannot match, so reject it
    // before touching the database.
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return null; }

    $stmt = $pdo->prepare("
        SELECT o.id, o.invoice_no, o.status, o.delivery_method, o.payment_method, o.payment_status,
               o.total_amount, o.tracking_code, o.created_at,
               CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS customer_name
        FROM orders o LEFT JOIN users u ON u.id = o.user_id
        WHERE o.qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * What a scanner can put in a QR code. Kept deliberately short - a long
 * payload forces a denser QR, which scans worse on a small sticker.
 */
function os_scan_payload(string $token): string {
    return 'TWO:' . $token;
}

/** Pulls the token back out of whatever the scanner produced. */
function os_parse_scan(string $raw): ?string {
    $raw = trim($raw);
    if (stripos($raw, 'TWO:') === 0) { $raw = substr($raw, 4); }
    // A phone camera pointed at a printed URL may return the whole link.
    if (preg_match('/[?&]t=([a-f0-9]{64})/i', $raw, $m)) { $raw = $m[1]; }
    $raw = strtolower(trim($raw));
    return preg_match('/^[a-f0-9]{64}$/', $raw) ? $raw : null;
}

/* =========================================================================
 * History
 * ===================================================================== */

function os_record_history(PDO $pdo, int $orderId, ?string $old, string $new, string $source, ?int $actorId, ?string $actorName = null, ?string $reason = null): void {
    os_ensure_schema($pdo);
    $stmt = $pdo->prepare("INSERT INTO order_status_history
        (order_id, old_status, new_status, source, actor_id, actor_name, reason, ip)
        VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $orderId, $old, $new, $source, $actorId,
        $actorName !== null ? substr($actorName, 0, 120) : null,
        $reason !== null ? substr($reason, 0, 500) : null,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
}

function os_history(PDO $pdo, int $orderId): array {
    os_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Seconds since this order's last recorded change, or null if it has none. */
function os_seconds_since_last_change(PDO $pdo, int $orderId): ?int {
    os_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM order_status_history
                           WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (int)$v;
}

/* =========================================================================
 * The state changes
 * ===================================================================== */

/**
 * Moves an order one step along its ladder, from a scanned token.
 *
 * Everything that makes this safe happens inside one transaction:
 *
 *   - SELECT ... FOR UPDATE on the order row, so two scanners hitting Confirm
 *     at the same instant serialise rather than both reading `packed` and both
 *     writing `ready`.
 *   - The status is re-read under that lock and the ladder re-evaluated, so
 *     the decision is made on what is true now, not on what the confirmation
 *     screen said a moment ago.
 *   - $expectedFrom, sent back from the confirmation screen, must still match.
 *     If anything moved in between, the scan is refused rather than applied to
 *     a different starting point than the operator was shown.
 *   - The UPDATE itself carries `AND status = ?`, so even if all of the above
 *     were somehow bypassed the write only lands on the expected row state.
 *
 * Returns ['ok'=>bool, 'message'=>string, 'order'=>array|null,
 *          'old'=>?string, 'new'=>?string, 'code'=>string].
 */
function os_advance_by_scan(PDO $pdo, string $token, int $actorId, ?string $actorName, ?string $expectedFrom = null): array {
    os_ensure_schema($pdo);

    $fail = fn(string $code, string $msg, ?array $order = null) =>
        ['ok' => false, 'code' => $code, 'message' => $msg, 'order' => $order, 'old' => null, 'new' => null];

    $token = os_parse_scan($token) ?? '';
    if ($token === '') { return $fail('bad_token', 'That code is not a TechWorld order label.'); }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT o.id, o.invoice_no, o.status, o.delivery_method, o.payment_method, o.payment_status,
                   CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS customer_name
            FROM orders o LEFT JOIN users u ON u.id = o.user_id
            WHERE o.qr_token = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$token]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $pdo->rollBack();
            return $fail('unknown', 'No order matches this label.');
        }

        $orderId = (int)$order['id'];
        $current = (string)$order['status'];
        $method  = (string)($order['delivery_method'] ?: 'delivery');

        // The operator confirmed a specific move. If the order is no longer
        // where it was when they were shown that screen, do nothing.
        if ($expectedFrom !== null && $expectedFrom !== $current) {
            $pdo->rollBack();
            return $fail('changed', 'This order moved to ' . os_label($current, $method)
                . ' while you were confirming. Scan it again to see the current step.', $order);
        }

        $step = os_next_scan_status($current, $method);
        if ($step['next'] === null) {
            $pdo->rollBack();
            return $fail('no_step', (string)$step['reason'], $order);
        }

        // Cooldown. Deliberately inside the lock and checked against the
        // history table rather than a session or a cache, so it holds across
        // devices - two staff with two phones are the case it exists for.
        $since = os_seconds_since_last_change($pdo, $orderId);
        if ($since !== null && $since < OS_SCAN_COOLDOWN_SECONDS) {
            $pdo->rollBack();
            $wait = OS_SCAN_COOLDOWN_SECONDS - $since;
            return $fail('cooldown', 'This order was just updated ' . $since . ' second(s) ago. '
                . 'Wait ' . $wait . ' more second(s) before scanning it again.', $order);
        }

        $next = (string)$step['next'];

        // SERIAL NUMBERS BEFORE AN ORDER MAY LEAVE PREPARING.
        //
        // preparing -> ready is the picking step: somebody takes the goods off
        // the shelf and sets them aside. For a serialised product that is the
        // moment, and the only moment, when the physical unit is in a human's
        // hands and its label can be read. If the order is allowed past here
        // without a serial recorded, nothing later in the process ever asks
        // again, and the shop has sold a laptop it cannot identify - which is
        // the entire problem this feature exists to solve.
        //
        // `packed` is not the gate even though it sounds like it: it is a dead
        // rung (see the ladder notes at the top of this file) that no order is
        // moved into any more, so gating it would gate nothing.
        //
        // Only lines whose product's tracking is actually LIVE are demanded -
        // pu_unassigned_lines() applies that rule - so an order for a flagged
        // but un-onboarded product is not stuck here.
        if ($current === 'preparing' && $next === 'ready') {
            $missing = pu_unassigned_lines($pdo, $orderId);
            if ($missing) {
                $pdo->rollBack();
                // Wording lives in pu_missing_serials_sentence() because the
                // scan's confirmation screen says the same thing before the
                // operator presses Confirm - see api/order_scan.php.
                return $fail('needs_serial', pu_missing_serials_sentence($missing), $order);
            }
        }

        $upd = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?");
        $upd->execute([$next, $orderId, $current]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            return $fail('race', 'Another scan updated this order first. Scan again to see where it is now.', $order);
        }

        os_record_history($pdo, $orderId, $current, $next, 'scan', $actorId, $actorName, null);

        // The units are SOLD once the goods leave the building, which is a
        // different rung on each ladder: handing to the rider ('shipped') for
        // a delivery, the customer walking out with it ('completed') for a
        // pickup. Until then they stay 'reserved', because an order can still
        // be cancelled and a cancelled order's units have to go back on the
        // shelf.
        //
        // Inside the transaction, so date_sold and the status change share one
        // timestamp and one fate - the returns desk reads that date as the
        // date of sale, and a unit marked sold against an order that rolled
        // back would be unreturnable.
        if (($method === 'pickup' && $next === 'completed') || ($method !== 'pickup' && $next === 'shipped')) {
            pu_mark_order_sold($pdo, $orderId);
            // The customer now has the goods, so the 7-day store warranty
            // starts today. Same moment, same transaction, so a serial's sale
            // date and its warranty start can never disagree. Only fills a
            // window still NULL, so a re-scan cannot restart the clock.
            warranty_start_for_order($pdo, $orderId);
            // The sale itself, in the movement log. Idempotent, so a re-scan
            // cannot double-count it.
            inv_log_sold_for_order($pdo, $orderId, $actorId);
        }

        $pdo->commit();

        // Reaching 'shipped' is the moment an order becomes deliverable, and
        // the admin queue used to run the rider queue right here when staff
        // picked "Shipped" from its dropdown. That dropdown is gone, so the
        // scan has to do it instead - otherwise an order would sit unassigned
        // until someone happened to open the delivery queue or the courier
        // board. Deliberately after the commit: the status change is the
        // important part and must not be rolled back if assignment throws.
        if ($next === 'shipped' && function_exists('autoAssignPendingDeliveries')) {
            try { autoAssignPendingDeliveries(); }
            catch (Throwable $e) { error_log('os_advance_by_scan auto-assign: ' . $e->getMessage()); }
        }

        $order['status'] = $next;
        return ['ok' => true, 'code' => 'advanced', 'order' => $order, 'old' => $current, 'new' => $next,
                'message' => ($order['invoice_no'] ?: ('Order #' . $orderId)) . ' is now ' . os_label($next, $method) . '.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('os_advance_by_scan: ' . $e->getMessage());
        return $fail('error', 'Could not update the order. Please try again.');
    }
}

/**
 * Admin-only escape hatch for fixing mistakes: set any status directly, with a
 * reason that is required rather than optional. Deliberately does NOT enforce
 * the ladder - the entire point is to undo a wrong move - but it is recorded
 * as source 'manual' with the reason, so the history shows plainly that a
 * human overrode the process and why.
 */
function os_manual_set(PDO $pdo, int $orderId, string $newStatus, int $actorId, ?string $actorName, string $reason): array {
    os_ensure_schema($pdo);

    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Give a reason for the manual change (at least 5 characters).'];
    }
    if (!in_array($newStatus, OS_ALL, true)) {
        return ['ok' => false, 'message' => 'That is not a valid order status.'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, invoice_no, status, delivery_method FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) { $pdo->rollBack(); return ['ok' => false, 'message' => 'Order not found.']; }

        $current = (string)$order['status'];
        if ($current === $newStatus) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The order is already ' . os_label($newStatus, (string)$order['delivery_method']) . '.'];
        }

        // SERIALS, WHEN A HUMAN OVERRIDES THE GATE.
        //
        // Deliberately a warning and not a refusal. This function is the
        // escape hatch for undoing a wrong move - a gate here would be a trap
        // with no way out of it, and staff would work around it by cancelling
        // and re-cutting orders, which is far worse than a recorded override.
        //
        // What it must not be is SILENT. Moving an order to packed/ready/
        // shipped/completed asserts the goods were picked, and doing that with
        // no serial on a serialised line is exactly what this feature exists
        // to catch. So the shortfall is appended to the reason stored on the
        // history row - permanently, next to who did it and why - and returned
        // to the caller as a warning to put on screen.
        $warning = null;
        $reasonStored = $reason;
        if (in_array($newStatus, OS_PICKED_STATUSES, true)) {
            $missingManual = pu_unassigned_lines($pdo, $orderId);
            if ($missingManual) {
                $shortfall = [];
                foreach ($missingManual as $ml) {
                    $shortfall[] = $ml['product_name'] . ' ('
                        . ((int)$ml['quantity'] - (int)$ml['assigned']) . ' of ' . (int)$ml['quantity'] . ')';
                }
                $warning = 'No serial number is recorded for: ' . implode('; ', $shortfall)
                         . '. This override has been logged against your name.';
                $reasonStored = $reason . ' [OVERRIDE: no serial recorded for '
                              . implode('; ', $shortfall) . ']';
            }
        }

        $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?")
            ->execute([$newStatus, $orderId, $current]);
        os_record_history($pdo, $orderId, $current, $newStatus, 'manual', $actorId, $actorName, $reasonStored);

        // Keep the unit records in step with a hand-set status, or this escape
        // hatch becomes the way they drift. Deliberately NOT gated on serials
        // the way the scan is: this function exists to undo a wrong move, and
        // a gate here would be a trap with no way out of it.
        //
        //   shipped / completed  the goods left - the units are sold
        //   cancelled            the order is off - the units go back on the
        //                        shelf, matching the `stock = stock + qty`
        //                        that the cancellation queues already do
        $methodManual = (string)($order['delivery_method'] ?: 'delivery');
        if ($newStatus === 'cancelled') {
            pu_release_for_order($pdo, $orderId);
        } elseif ($newStatus === 'completed' || ($methodManual !== 'pickup' && $newStatus === 'shipped')) {
            pu_mark_order_sold($pdo, $orderId);
            warranty_start_for_order($pdo, $orderId);
            inv_log_sold_for_order($pdo, $orderId, $actorId);
        }

        $pdo->commit();

        return ['ok' => true, 'old' => $current, 'new' => $newStatus,
                'message' => 'Status changed to ' . os_label($newStatus, (string)$order['delivery_method']) . '.',
                // Null unless the override skipped a serial. Callers render it
                // alongside the success message rather than instead of it -
                // the change DID happen, and saying otherwise would be a lie.
                'warning' => $warning];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('os_manual_set: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not change the status. Please try again.'];
    }
}

/**
 * The automatic trigger behind "online orders must be paid before anyone picks
 * stock": once money has actually landed, release the order into the queue.
 *
 * Only ever moves awaiting_payment -> preparing. An order already further
 * along is left exactly where it is, which is what makes this safe to call
 * from the webhook (which can be retried) and from the admin's manual
 * payment-verified action alike.
 *
 * Returns true only when it actually moved something.
 */
function os_release_paid_order(PDO $pdo, int $orderId, string $source = 'webhook', ?int $actorId = null, ?string $actorName = null): bool {
    os_ensure_schema($pdo);
    try {
        $upd = $pdo->prepare("UPDATE orders SET status = 'preparing', updated_at = NOW()
                              WHERE id = ? AND status = 'awaiting_payment'");
        $upd->execute([$orderId]);
        if ($upd->rowCount() === 0) { return false; }

        os_record_history($pdo, $orderId, 'awaiting_payment', 'preparing', $source, $actorId, $actorName,
            'Payment confirmed - released for preparing');
        return true;
    } catch (Throwable $e) {
        error_log('os_release_paid_order: ' . $e->getMessage());
        return false;
    }
}
