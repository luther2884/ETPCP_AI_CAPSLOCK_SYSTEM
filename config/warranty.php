<?php
declare(strict_types=1);

/**
 * config/warranty.php - the store warranty, in one place.
 *
 * Every item a customer buys gets a warranty row automatically, at purchase,
 * with no form to fill in. There is nothing for the customer to register and
 * nothing for them to mistype.
 *
 * THE TWO MOMENTS, AND WHY THEY ARE NOT THE SAME ONE
 *
 *   purchase  (checkout)  the row is CREATED. warranty_start_date and
 *                         warranty_end_date are left NULL - "not started".
 *   handover  (the goods   the clock STARTS. start = that day,
 *              leave)      end = start + warranty_days.
 *
 * Collapsing those into one moment was the obvious first design and it is
 * wrong on this shop's own numbers: completed delivery orders average 28.9
 * hours from order to handover, and the slowest took 421 - 17.5 days. A 7-day
 * warranty anchored at checkout would have expired eight days before that box
 * arrived. Pickup orders average 0.1 hours, so for them the two moments are
 * effectively the same and nothing is lost by separating them.
 *
 * WHAT COUNTS AS HANDOVER is deliberately the same event that marks a tracked
 * unit sold, so a serial's sale date and its warranty start can never disagree:
 * 'shipped' for a delivery, 'completed' for a pickup. See pu_mark_order_sold()
 * in config/product_units.php - every call site of that calls
 * warranty_start_for_order() beside it.
 *
 * ONE ROW PER THING THE CUSTOMER CAN BRING BACK
 *
 *   unit-tracked product  one row per physical unit, carrying that unit's
 *                         serial number, so a 2-laptop line gets 2 warranties
 *                         and each names the machine it covers.
 *   everything else       one row per order line. There is no serial to put on
 *                         it and no way to tell two identical cables apart, so
 *                         the line is the finest grain that means anything.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/product_units.php';

/** Length of the store warranty, in days. The row stores its own
 *  `warranty_days`, so changing this affects new registrations only - an
 *  existing warranty keeps the term it was sold under. */
const WARRANTY_STORE_DAYS = 7;

/**
 * Create the warranty rows for one order. Called at checkout.
 *
 * Idempotent, and it has to be: checkout is not the only thing that can reach
 * it (a backfill, a retry, an admin re-running it), and a customer must never
 * end up with two warranties for one laptop. The `uniq_product_unit` index is
 * the hard guard for tracked units; the untracked case is guarded by the
 * existence check below, inside the caller's transaction.
 *
 * MUST be called with the order's items already written. Safe to call inside
 * an open transaction - it opens none of its own.
 *
 * @return int how many rows it created
 */
function warranty_register_for_order(PDO $pdo, int $orderId): int
{
    $orderStmt = $pdo->prepare(
        'SELECT id, user_id, DATE(created_at) AS purchase_date FROM orders WHERE id = ? LIMIT 1'
    );
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { return 0; }

    $userId       = (int)$order['user_id'];
    $purchaseDate = (string)$order['purchase_date'];

    $itemStmt = $pdo->prepare(
        'SELECT oi.id AS order_item_id, oi.product_id, oi.quantity
           FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id'
    );
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) { return 0; }

    // Units already claimed for this order, keyed by line. At checkout these
    // are the ones reservation just took; at a later backfill they may already
    // be sold. Either way they are the units this customer is getting.
    $unitsByItem = [];
    $uStmt = $pdo->prepare(
        "SELECT pu.unit_id, pu.serial_number, pu.order_item_id
           FROM product_units pu
           JOIN order_items oi ON oi.id = pu.order_item_id
          WHERE oi.order_id = ? AND pu.status IN ('reserved','sold')"
    );
    $uStmt->execute([$orderId]);
    foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $unitsByItem[(int)$u['order_item_id']][] = $u;
    }

    $insert = $pdo->prepare(
        "INSERT INTO warranty_registrations
            (user_id, order_id, order_item_id, product_unit_id, product_id,
             serial_number, purchase_date, registration_date,
             warranty_start_date, warranty_end_date, warranty_days, source, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NULL, NULL, ?, 'auto_purchase', 'active')"
    );

    // Does a registration already exist for this line / unit?
    $haveUnit = $pdo->prepare('SELECT 1 FROM warranty_registrations WHERE product_unit_id = ? LIMIT 1');
    $haveLine = $pdo->prepare(
        'SELECT 1 FROM warranty_registrations WHERE order_item_id = ? AND product_unit_id IS NULL LIMIT 1'
    );

    $created = 0;

    foreach ($items as $item) {
        $itemId    = (int)$item['order_item_id'];
        $productId = (int)$item['product_id'];
        $units     = $unitsByItem[$itemId] ?? [];

        if ($units) {
            // Serialised: one warranty per unit, naming the unit.
            foreach ($units as $u) {
                $haveUnit->execute([(int)$u['unit_id']]);
                if ($haveUnit->fetchColumn()) { continue; }
                try {
                    $insert->execute([
                        $userId, $orderId, $itemId, (int)$u['unit_id'], $productId,
                        (string)$u['serial_number'], $purchaseDate, WARRANTY_STORE_DAYS,
                    ]);
                    $created++;
                } catch (PDOException $e) {
                    // 1062 = another request got there first. The row exists,
                    // which is the outcome wanted; anything else is real.
                    if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
                }
            }
            continue;
        }

        /* Not serialised (or a tracked product whose units were not reserved -
           a flagged-but-un-onboarded one, see pu_tracking_live()). One row for
           the line: two identical cables cannot be told apart, so the line is
           the finest grain that means anything, and quantity is on the line
           already. */
        $haveLine->execute([$itemId]);
        if ($haveLine->fetchColumn()) { continue; }
        try {
            $insert->execute([
                $userId, $orderId, $itemId, null, $productId,
                null, $purchaseDate, WARRANTY_STORE_DAYS,
            ]);
            $created++;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
        }
    }

    return $created;
}

/**
 * Start the clock on an order's warranties. Called when the goods are handed
 * over - the same moment pu_mark_order_sold() runs.
 *
 * Only ever fills a window that is still NULL, so a second handover scan, a
 * re-delivery or an admin correction cannot silently extend or restart a
 * warranty that is already running.
 *
 * Safe inside an open transaction. Dates are computed by MySQL from its own
 * CURDATE(), so the start day is the database's day rather than PHP's - the
 * two can differ around midnight and only one of them is what every other
 * date on the order was written against.
 *
 * @return int how many warranties started
 */
function warranty_start_for_order(PDO $pdo, int $orderId): int
{
    $stmt = $pdo->prepare(
        "UPDATE warranty_registrations wr
            JOIN order_items oi ON oi.id = wr.order_item_id
             SET wr.warranty_start_date = CURDATE(),
                 wr.warranty_end_date   = DATE_ADD(CURDATE(), INTERVAL wr.warranty_days DAY)
           WHERE oi.order_id = ?
             AND wr.warranty_start_date IS NULL"
    );
    $stmt->execute([$orderId]);
    return $stmt->rowCount();
}

/**
 * Mark warranties expired once their last day has passed.
 *
 * Status is stored rather than derived because warranty_claims and the admin
 * screens read the column. Called opportunistically from the customer's
 * warranty page: it is one indexed UPDATE on a page that is already querying,
 * which is the same approach cancelExpiredOnlinePaymentOrders() takes rather
 * than requiring a cron job on XAMPP.
 *
 * A warranty that has not started has no end date and is left alone.
 */
function warranty_expire_due(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "UPDATE warranty_registrations
            SET status = 'expired'
          WHERE status = 'active'
            AND warranty_end_date IS NOT NULL
            AND warranty_end_date < CURDATE()"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * One customer's warranties, newest purchase first, with everything the page
 * needs to describe each one in words.
 *
 * `days_left` is computed by the database for the same reason the start date
 * is: so "3 days left" is counted from the same today every other date on the
 * page was.
 *
 * @return array<int,array<string,mixed>>
 */
function warranty_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT wr.id,
                wr.serial_number,
                wr.purchase_date,
                wr.warranty_start_date,
                wr.warranty_end_date,
                wr.warranty_days,
                wr.status,
                wr.source,
                wr.order_id,
                wr.order_item_id,
                oi.quantity,
                p.name  AS product_name,
                p.image_url,
                o.invoice_no,
                o.status AS order_status,
                o.delivery_method,
                /* NULL until the warranty starts; negative never happens
                   because warranty_expire_due() moves those to 'expired',
                   but GREATEST keeps a stale row from printing '-2 days'. */
                CASE WHEN wr.warranty_end_date IS NULL THEN NULL
                     ELSE GREATEST(0, DATEDIFF(wr.warranty_end_date, CURDATE()))
                END AS days_left
           FROM warranty_registrations wr
      LEFT JOIN order_items oi ON oi.id = wr.order_item_id
      LEFT JOIN products p     ON p.id  = wr.product_id
      LEFT JOIN orders o       ON o.id  = wr.order_id
          WHERE wr.user_id = ?
          ORDER BY wr.purchase_date DESC, wr.id DESC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * How one warranty should read on screen: a state and a sentence.
 *
 * Centralised so the customer page, any admin view and a future email all
 * describe the same row the same way.
 *
 * @param array<string,mixed> $w a row from warranty_for_user()
 * @return array{state:string,label:string,detail:string}
 */
function warranty_describe(array $w): array
{
    $status = (string)($w['status'] ?? 'active');
    $days   = (int)WARRANTY_STORE_DAYS;
    if (isset($w['warranty_days'])) { $days = (int)$w['warranty_days']; }

    if ($status === 'voided') {
        return ['state' => 'voided', 'label' => 'Voided',
                'detail' => 'This warranty is no longer valid.'];
    }

    // Not handed over yet - the clock has not been started.
    if (empty($w['warranty_start_date'])) {
        $isPickup = ($w['delivery_method'] ?? '') === 'pickup';
        return [
            'state'  => 'pending',
            'label'  => 'Starts on handover',
            'detail' => 'Your ' . $days . '-day store warranty begins the day you '
                      . ($isPickup ? 'collect this item' : 'receive this item')
                      . ', so none of it is spent while the order is still with us.',
        ];
    }

    if ($status === 'expired' || (isset($w['days_left']) && (int)$w['days_left'] <= 0
            && !empty($w['warranty_end_date']) && strtotime((string)$w['warranty_end_date']) < strtotime(date('Y-m-d')))) {
        return ['state' => 'expired', 'label' => 'Expired',
                'detail' => 'The ' . $days . '-day store warranty ended on '
                          . date('j M Y', strtotime((string)$w['warranty_end_date'])) . '.'];
    }

    $left = (int)($w['days_left'] ?? 0);
    return [
        'state'  => 'active',
        'label'  => $left === 0 ? 'Last day' : $left . ' day' . ($left === 1 ? '' : 's') . ' left',
        'detail' => 'Covered until ' . date('j M Y', strtotime((string)$w['warranty_end_date'])) . '.',
    ];
}
