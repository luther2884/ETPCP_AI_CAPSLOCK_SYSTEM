<?php
declare(strict_types=1);

/**
 * config/product_units.php - the one place that knows what a tracked unit is,
 * what may happen to it, and how it keeps `products.stock` honest.
 *
 * Everything that touches `product_units` goes through this file: the admin
 * units page, the returns desk, checkout's reservation step, the scan that
 * marks an order ready, and the three cancellation rollbacks. The point is the
 * same as config/order_status.php's: one rule, written once, rather than six
 * pages each with their own idea of what a legal move is.
 *
 * THE STATUSES, AND WHAT SELLABLE MEANS
 *
 *   in_stock   on the shelf, sellable. The ONLY status that counts toward
 *              products.stock.
 *   reserved   claimed by an order that exists but has not been handed over.
 *              Taken at checkout, released by a cancellation.
 *   sold       handed over. date_sold is set. This is what the returns desk
 *              matches against.
 *   returned   physically back in the building. NOT sellable: a returned unit
 *              needs inspection before anyone promises it to a customer
 *              again, and nothing in this system does that inspection yet.
 *   defective  back in the building and known broken. Never sellable.
 *
 * WHY RESERVATION HAPPENS AT CHECKOUT
 *
 * customer/process_order.php deducts `products.stock` when the ORDER IS
 * PLACED, not when it ships, behind a `SELECT ... FOR UPDATE ... ORDER BY id`
 * that exists because this shop really did oversell into negative stock once.
 * If units were only consumed at picking, the mirror would disagree with
 * products.stock for every order between checkout and picking, and the
 * oversell protection would have to be rebuilt on top of this table.
 *
 * So checkout reserves units in the same transaction and the same product-id
 * order as the stock deduction it already does. Staff then confirm - or swap -
 * the physical unit at picking, which is the only step a human can do that a
 * computer cannot: read the label on the box actually in their hand.
 *
 * "TRACKING IS LIVE" vs `requires_serial`
 *
 * requires_serial = 1 is an INTENTION. Tracking is only LIVE once the product
 * has unit rows. DBSQL/add_product_unit_tracking.sql flags 144 products across
 * six categories, and all but a handful have had no serials typed in yet -
 * had the flag alone switched the mirror on, those products would have
 * reported stock 0 and failed every checkout the moment the migration ran.
 *
 * pu_tracking_live() is that distinction, and every caller asks it rather than
 * reading requires_serial directly. Entering a product's FIRST serial is the
 * switch-over, and admin/product_units.php warns before it saves.
 *
 * VALIDATION LIVES HERE, NOT IN THE DATABASE
 *
 * Same reason config/product_validation.php exists: this server runs without
 * STRICT_TRANS_TABLES, so an over-long serial is silently truncated rather
 * than refused. These checks return the pv_result() shape - ok / value /
 * error / warning, never thrown - so a bulk paste of forty serials can report
 * every bad line in one pass instead of stopping at the first.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/product_validation.php';

/** Longest value `product_units.serial_number VARCHAR(100)` can hold. */
const PU_SERIAL_MAX = 100;

/** An IMEI is exactly this many digits. Not a range - the standard is fixed. */
const PU_IMEI_DIGITS = 15;

/** Every value product_units.status may hold. */
const PU_STATUSES = ['in_stock', 'reserved', 'sold', 'returned', 'defective'];

/** The statuses that mean "this unit is physically back with us, not sellable". */
const PU_RETURN_STATUSES = ['returned', 'defective'];

/** How many serials one bulk paste may carry. Above this the request is
 *  almost certainly a mistake (a pasted spreadsheet column, a log file), and
 *  the page would in any case time out building the per-line report. */
const PU_BULK_MAX = 500;

/** Human label for a status, for chips and tables. */
function pu_status_label(string $status): string
{
    $labels = [
        'in_stock'  => 'In stock',
        'reserved'  => 'Reserved',
        'sold'      => 'Sold',
        'returned'  => 'Returned',
        'defective' => 'Defective',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Validate one serial number.
 *
 * Deliberately permissive about SHAPE - every vendor in this catalogue uses a
 * different format (Lenovo 'PF3K8L21', Gigabyte 'SN2130607001', INNO3D
 * 'I3D73020A01'), so anything beyond "printable, not absurd" would start
 * rejecting real units. What it is strict about is the things that cause
 * silent damage: length past the column, and characters that are invisible on
 * screen but make two serials fail to match later.
 *
 * @return array{ok:bool,value:string,error:?string,warning:?string}
 */
function pu_validate_serial($raw): array
{
    // Strip the whole Unicode space family, not just ASCII whitespace: a
    // serial pasted out of a PDF or a spreadsheet routinely arrives wrapped in
    // a non-breaking space, which looks identical on screen and makes an exact
    // lookup miss.
    $s = preg_replace('/^[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+$/u', '', (string)$raw);
    $s = $s === null ? '' : $s;

    if ($s === '') {
        return pv_result(false, '', 'Enter the serial number printed on the unit.');
    }

    // Control characters and zero-width marks anywhere INSIDE the value. These
    // survive a trim, are invisible in the table, and turn a correct serial
    // into one that never matches at the returns desk.
    if (preg_match('/[\x{0000}-\x{001F}\x{007F}\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', $s)) {
        return pv_result(false, $s, 'That serial contains an invisible or control character - retype it rather than pasting it.');
    }

    // Counted in characters, not bytes, but the column is measured in
    // characters too (utf8mb4 VARCHAR(100)), so these agree.
    if (mb_strlen($s) > PU_SERIAL_MAX) {
        return pv_result(false, $s, 'That serial is too long to store - the most this system can hold is ' . PU_SERIAL_MAX . ' characters.');
    }

    // Short enough to almost certainly be a partial scan or a slip. Warned
    // rather than refused: some older peripherals really do carry 4-character
    // serials, and a hard limit here would make them unenterable.
    if (mb_strlen($s) < 5) {
        return pv_result(true, $s, null, 'That serial is only ' . mb_strlen($s) . ' characters - check it is the full number.');
    }

    return pv_result(true, $s);
}

/**
 * Validate an IMEI. Optional: an empty value is OK and means "no IMEI", which
 * is the normal case for everything in this catalogue that is not cellular.
 *
 * Exactly 15 digits, as the panel asked. Separators that appear on a device
 * label or an invoice (spaces and hyphens) are stripped before counting
 * rather than refused - the person reading the label is copying what they see.
 *
 * @return array{ok:bool,value:?string,error:?string,warning:?string}
 */
function pu_validate_imei($raw): array
{
    $s = trim((string)$raw);

    if ($s === '') {
        return pv_result(true, null);
    }

    // Label and invoice formatting only. Anything else is left in place so it
    // trips the digits check below with an honest message.
    $digits = preg_replace('/[\s\-]+/', '', $s);
    $digits = $digits === null ? '' : $digits;

    if (!preg_match('/^\d+$/', $digits)) {
        return pv_result(false, $s, 'An IMEI is digits only. Leave it blank if this unit has no cellular radio.');
    }
    if (strlen($digits) !== PU_IMEI_DIGITS) {
        return pv_result(false, $s, 'An IMEI is exactly ' . PU_IMEI_DIGITS . ' digits - that one has ' . strlen($digits) . '.');
    }

    return pv_result(true, $digits);
}

/**
 * Is this product flagged for unit tracking at all?
 *
 * Accepts either a product row or an id, because half the callers already have
 * the row in hand and re-querying it would be wasteful.
 *
 * @param array<string,mixed>|int $product
 */
function pu_requires_serial(PDO $pdo, $product): bool
{
    if (is_array($product)) {
        // A row selected before this column existed (or by a SELECT that did
        // not ask for it) must read as "not tracked" rather than crash.
        return !empty($product['requires_serial']);
    }
    $stmt = $pdo->prepare('SELECT requires_serial FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$product]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Is unit tracking LIVE for this product - flagged AND onboarded?
 *
 * This is the question every caller actually wants. See the header: a flagged
 * product with no units behaves exactly as it did before this feature existed,
 * so that turning the flag on for 144 products did not take 138 of them out of
 * stock and break their checkout.
 */
function pu_tracking_live(PDO $pdo, int $productId): bool
{
    $stmt = $pdo->prepare(
        'SELECT p.requires_serial,
                EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id) AS has_units
           FROM products p WHERE p.id = ? LIMIT 1'
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (!empty($row['requires_serial']) && !empty($row['has_units'])) : false;
}

/**
 * Recompute `products.stock` from the unit rows. THE mirror.
 *
 * Only ever touches a product whose tracking is live. For an untracked product
 * - or a flagged one nobody has entered serials for - products.stock is still
 * a number a human maintains, and overwriting it with COUNT(*) = 0 would
 * silently empty the shelf.
 *
 * Call this inside the caller's transaction, after any unit mutation.
 *
 * @return int|null the new stock figure, or null if the product is not mirrored
 */
function pu_recount_stock(PDO $pdo, int $productId): ?int
{
    if (!pu_tracking_live($pdo, $productId)) {
        return null;
    }

    // One statement, so the count and the write cannot be separated by another
    // transaction's commit. The subquery reads the same rows this transaction
    // has already locked.
    $pdo->prepare(
        "UPDATE products p
            SET p.stock = (SELECT COUNT(*) FROM product_units pu
                            WHERE pu.product_id = p.id AND pu.status = 'in_stock'),
                p.updated_at = NOW()
          WHERE p.id = ?"
    )->execute([$productId]);

    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    return (int)$stmt->fetchColumn();
}

/**
 * How many units of this product are on the shelf right now.
 *
 * Reads the unit rows rather than products.stock, so it is correct even for a
 * product mid-onboarding whose mirror has not been switched on yet.
 */
function pu_in_stock_count(PDO $pdo, int $productId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_units WHERE product_id = ? AND status = 'in_stock'");
    $stmt->execute([$productId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Add units to a product from a list of serials.
 *
 * Takes the whole batch and reports on every line, because the single-entry
 * form and the bulk paste are the same operation with a different line count -
 * one code path, so they cannot disagree about what a duplicate is.
 *
 * Partial success is deliberate: nineteen good serials out of twenty are
 * entered and the twentieth is reported. Refusing the batch would mean
 * retyping all twenty to fix one typo, and staff would work around it by
 * entering them one at a time.
 *
 * @param array<int,array{serial:string,imei:string}> $rows
 * @return array{added:int,errors:array<int,string>,warnings:array<int,string>,stock:?int}
 */
function pu_add_units(PDO $pdo, int $productId, array $rows): array
{
    $errors = [];
    $warnings = [];
    $added = 0;

    if (count($rows) > PU_BULK_MAX) {
        return [
            'added' => 0,
            'errors' => ['That is ' . number_format(count($rows)) . ' lines. Enter at most ' . number_format(PU_BULK_MAX) . ' at a time.'],
            'warnings' => [],
            'stock' => null,
        ];
    }

    $prodStmt = $pdo->prepare('SELECT id, name, stock, requires_serial FROM products WHERE id = ? LIMIT 1');
    $prodStmt->execute([$productId]);
    $product = $prodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return ['added' => 0, 'errors' => ['That product no longer exists.'], 'warnings' => [], 'stock' => null];
    }

    // Duplicates WITHIN the batch, caught before any INSERT. The UNIQUE index
    // would catch them too, but one line at a time and with a message about
    // the database rather than about the paste.
    $seenSerials = [];
    $seenImeis = [];

    $insert = $pdo->prepare(
        "INSERT INTO product_units (product_id, serial_number, imei, status, date_added)
         VALUES (?, ?, ?, 'in_stock', NOW())"
    );

    foreach ($rows as $i => $row) {
        $lineNo = $i + 1;
        $label = count($rows) > 1 ? "Line {$lineNo}: " : '';

        $sv = pu_validate_serial($row['serial'] ?? '');
        if (!$sv['ok']) {
            $errors[] = $label . $sv['error'];
            continue;
        }
        $serial = (string)$sv['value'];

        $iv = pu_validate_imei($row['imei'] ?? '');
        if (!$iv['ok']) {
            $errors[] = $label . $iv['error'];
            continue;
        }
        $imei = $iv['value'] !== null ? (string)$iv['value'] : null;

        // Case-insensitively, because the column collates utf8mb4_unicode_ci -
        // 'pf3k8l21' and 'PF3K8L21' are the SAME serial to the UNIQUE index,
        // and the in-batch check has to agree with it or the INSERT fails
        // after this check passed.
        $serialKey = mb_strtolower($serial);
        if (isset($seenSerials[$serialKey])) {
            $errors[] = $label . 'serial ' . $serial . ' is repeated on line ' . $seenSerials[$serialKey] . ' of this batch.';
            continue;
        }
        if ($imei !== null && isset($seenImeis[$imei])) {
            $errors[] = $label . 'IMEI ' . $imei . ' is repeated on line ' . $seenImeis[$imei] . ' of this batch.';
            continue;
        }

        // Against what is already on file. Checked explicitly rather than left
        // to the UNIQUE index so the message can name the product the serial
        // is already on - which is the thing whoever is typing needs to know.
        $dupe = pu_find_unit($pdo, $serial);
        if ($dupe) {
            $errors[] = $label . 'serial ' . $serial . ' is already on file for '
                      . $dupe['product_name'] . ' (' . pu_status_label((string)$dupe['status']) . ').';
            continue;
        }
        if ($imei !== null) {
            $dupeImei = pu_find_unit($pdo, $imei);
            if ($dupeImei) {
                $errors[] = $label . 'IMEI ' . $imei . ' is already on file for ' . $dupeImei['product_name'] . '.';
                continue;
            }
        }

        try {
            $insert->execute([$productId, $serial, $imei]);
        } catch (PDOException $e) {
            // The UNIQUE index firing here means another session inserted the
            // same serial between the check above and this INSERT. Rare, and
            // reported honestly rather than swallowed.
            if (($e->errorInfo[1] ?? 0) === 1062) {
                $errors[] = $label . 'serial ' . $serial . ' was entered by someone else a moment ago.';
                continue;
            }
            throw $e;
        }

        $seenSerials[$serialKey] = $lineNo;
        if ($imei !== null) { $seenImeis[$imei] = $lineNo; }
        if ($sv['warning']) { $warnings[] = $label . $sv['warning']; }
        $added++;
    }

    $stock = null;
    if ($added > 0) {
        // The first serial for a flagged product is the switch-over moment:
        // before this, pu_tracking_live() was false and the product ran on
        // products.stock; now the mirror takes over. The admin page warns
        // about this before submitting, and reports the new figure after.
        $stock = pu_recount_stock($pdo, $productId);
    }

    return ['added' => $added, 'errors' => $errors, 'warnings' => $warnings, 'stock' => $stock];
}

/**
 * Parse a bulk textarea into rows.
 *
 * One serial per line, as the panel asked. An optional IMEI may follow after a
 * comma or a tab, so a two-column paste out of a spreadsheet works without a
 * separate screen for it.
 *
 * @return array<int,array{serial:string,imei:string}>
 */
function pu_parse_bulk(string $text): array
{
    $rows = [];
    // Normalise CRLF/CR first - a paste from Notepad or Excel on Windows
    // arrives with \r\n, and splitting on \n alone leaves a trailing \r on
    // every serial. The serial validator would reject all of them as
    // containing a control character, which is correct but baffling.
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    foreach (explode("\n", $text) as $line) {
        if (trim($line) === '') { continue; }
        // Tab first so a spreadsheet paste splits on the real separator, then
        // comma for hand-typed input. A serial containing a comma would split
        // wrongly, but no vendor format in this catalogue uses one.
        $parts = preg_split('/[\t,]/', $line, 2);
        $rows[] = [
            'serial' => trim((string)($parts[0] ?? '')),
            'imei'   => trim((string)($parts[1] ?? '')),
        ];
    }
    return $rows;
}

/**
 * Find a unit by serial number OR IMEI, with everything the returns desk needs
 * to decide: the product, the customer, the order, and when it was sold.
 *
 * One query over both identifiers, because whoever is standing at the counter
 * is reading a number off a label and does not know or care which kind it is.
 *
 * @return array<string,mixed>|null
 */
function pu_find_unit(PDO $pdo, string $identifier): ?array
{
    $id = trim($identifier);
    if ($id === '') { return null; }

    // An IMEI copied off a label or an invoice may carry separators. The
    // serial branch matches the raw text, the IMEI branch the stripped
    // digits, so both spellings of the same IMEI find the unit.
    $imeiDigits = preg_replace('/[\s\-]+/', '', $id);
    $imeiDigits = $imeiDigits === null ? '' : $imeiDigits;

    $stmt = $pdo->prepare(
        "SELECT pu.unit_id, pu.product_id, pu.serial_number, pu.imei, pu.status,
                pu.order_item_id, pu.date_added, pu.date_sold,
                p.name  AS product_name,
                p.image_url,
                p.requires_serial,
                c.name  AS category_name,
                oi.order_id,
                oi.quantity    AS line_quantity,
                oi.price       AS line_unit_price,
                o.invoice_no,
                o.tracking_code,
                o.status       AS order_status,
                o.payment_status,
                o.delivery_method,
                o.created_at   AS order_placed_at,
                o.user_id      AS customer_id,
                u.firstname, u.lastname, u.email
           FROM product_units pu
           JOIN products p         ON p.id = pu.product_id
      LEFT JOIN categories c       ON c.id = p.category_id
      LEFT JOIN order_items oi     ON oi.id = pu.order_item_id
      LEFT JOIN orders o           ON o.id = oi.order_id
      LEFT JOIN users u            ON u.id = o.user_id
          WHERE pu.serial_number = ?
             OR (pu.imei IS NOT NULL AND pu.imei = ?)
          LIMIT 1"
    );
    $stmt->execute([$id, $imeiDigits]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * The units already attached to an order, keyed by order_item_id.
 *
 * Used by the order queues, the invoice and the receipt. One query for the
 * whole order rather than one per line.
 *
 * @return array<int,array<int,array<string,mixed>>>
 */
function pu_units_for_order(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        "SELECT pu.unit_id, pu.order_item_id, pu.serial_number, pu.imei, pu.status, pu.date_sold
           FROM product_units pu
           JOIN order_items oi ON oi.id = pu.order_item_id
          WHERE oi.order_id = ?
          ORDER BY pu.order_item_id, pu.serial_number"
    );
    $stmt->execute([$orderId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['order_item_id']][] = $row;
    }
    return $out;
}

/**
 * Units on the shelf that could be assigned to this order line - plus any
 * already attached to it, so the picking screen can offer a swap.
 *
 * @return array<int,array<string,mixed>>
 */
function pu_assignable_units(PDO $pdo, int $productId, int $orderItemId): array
{
    $stmt = $pdo->prepare(
        "SELECT unit_id, serial_number, imei, status, order_item_id
           FROM product_units
          WHERE product_id = ?
            AND (status = 'in_stock' OR order_item_id = ?)
          ORDER BY (order_item_id = ?) DESC, status, serial_number"
    );
    $stmt->execute([$productId, $orderItemId, $orderItemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Reserve units for one order line, at checkout.
 *
 * MUST be called inside the caller's transaction, after that transaction has
 * already locked the product row (process_order.php holds it via SELECT ...
 * FOR UPDATE). `FOR UPDATE` here locks the unit rows themselves, so two
 * checkouts cannot reserve the same physical unit.
 *
 * Which units it picks does not matter and is not asked: any in-stock unit of
 * the right product satisfies the order, and the human at picking confirms or
 * swaps to the one actually in their hand. Oldest first (unit_id ASC), so
 * stock rotates rather than the newest arrivals going out first.
 *
 * Returns the number reserved. Fewer than asked for means the shelf is short,
 * and the caller must treat that as the sale failing - exactly as it treats a
 * zero-row stock deduction.
 */
function pu_reserve_for_order_item(PDO $pdo, int $productId, int $orderItemId, int $quantity): int
{
    if ($quantity < 1) { return 0; }

    $sel = $pdo->prepare(
        "SELECT unit_id FROM product_units
          WHERE product_id = ? AND status = 'in_stock'
          ORDER BY unit_id ASC
          LIMIT {$quantity}
          FOR UPDATE"
    );
    $sel->execute([$productId]);
    $unitIds = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));

    if (!$unitIds) { return 0; }

    $in = implode(',', array_fill(0, count($unitIds), '?'));
    // `AND status = 'in_stock'` is re-proved inside the UPDATE even though the
    // rows are locked: it costs nothing and makes the statement correct on its
    // own terms rather than only in the presence of the lock above.
    $upd = $pdo->prepare(
        "UPDATE product_units
            SET status = 'reserved', order_item_id = ?
          WHERE unit_id IN ($in) AND status = 'in_stock'"
    );
    $upd->execute(array_merge([$orderItemId], $unitIds));

    return $upd->rowCount();
}

/**
 * Attach a specific unit to an order line at picking, replacing whatever was
 * reserved for it.
 *
 * This is the step the panel asked for: staff read the serial off the box in
 * their hand and record THAT unit against the order, rather than whichever one
 * checkout happened to reserve. The swapped-out unit goes back on the shelf.
 *
 * Status is left at 'reserved' here, not set to 'sold'. The unit is not sold
 * until the order is actually handed over, which is the scan that moves it to
 * `ready` - pu_mark_order_sold() below. An order can still be cancelled
 * between the two, and a cancelled order's units must go back on the shelf.
 *
 * @return array{ok:bool,message:string}
 */
function pu_assign_unit(PDO $pdo, int $orderItemId, int $unitId): array
{
    // Runs standalone from the API endpoint, but is also callable from inside a
    // caller's transaction. beginTransaction() THROWS when one is already open,
    // so ownership is decided before opening one and only the owner commits.
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }

    try {
        // The order line, and what it is for. Locked so two staff assigning
        // the same line at once cannot both succeed.
        $liStmt = $pdo->prepare(
            "SELECT oi.id, oi.order_id, oi.product_id, oi.quantity, o.status AS order_status
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.id = ? FOR UPDATE"
        );
        $liStmt->execute([$orderItemId]);
        $line = $liStmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'That order line no longer exists.'];
        }
        if (in_array((string)$line['order_status'], ['cancelled', 'completed'], true)) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'This order is ' . $line['order_status'] . ' - its units cannot be changed.'];
        }

        $uStmt = $pdo->prepare('SELECT unit_id, product_id, serial_number, status, order_item_id FROM product_units WHERE unit_id = ? FOR UPDATE');
        $uStmt->execute([$unitId]);
        $unit = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'That unit no longer exists.'];
        }

        // The wrong model entirely. Worth its own message: it is the mistake a
        // mistyped or mis-scanned serial actually produces.
        if ((int)$unit['product_id'] !== (int)$line['product_id']) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' is a different product from this order line.'];
        }
        // Already on this line - nothing to do, and reporting it as an error
        // would be wrong.
        if ((int)($unit['order_item_id'] ?? 0) === $orderItemId) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => true, 'message' => 'Serial ' . $unit['serial_number'] . ' is already assigned to this line.'];
        }
        // Promised to a DIFFERENT order, or sold, or returned. Never silently
        // taken: that would hand one customer's unit to another.
        if ($unit['status'] !== 'in_stock') {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' is ' . strtolower(pu_status_label((string)$unit['status'])) . ' and cannot be assigned.'];
        }

        // Make room if the line is already full. Oldest reservation first, so
        // repeated swaps replace what was swapped in earliest rather than
        // thrashing one slot.
        $heldStmt = $pdo->prepare(
            "SELECT unit_id FROM product_units
              WHERE order_item_id = ? AND status = 'reserved'
              ORDER BY unit_id ASC FOR UPDATE"
        );
        $heldStmt->execute([$orderItemId]);
        $held = array_map('intval', $heldStmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($held) >= (int)$line['quantity']) {
            $release = $pdo->prepare(
                "UPDATE product_units SET status = 'in_stock', order_item_id = NULL WHERE unit_id = ?"
            );
            $release->execute([$held[0]]);
        }

        $pdo->prepare(
            "UPDATE product_units SET status = 'reserved', order_item_id = ? WHERE unit_id = ? AND status = 'in_stock'"
        )->execute([$orderItemId, $unitId]);

        // Net zero in the usual case - one unit off the shelf, one back on -
        // but it has to run anyway: the first assignment on a line whose
        // reservation failed at checkout is a real decrement.
        pu_recount_stock($pdo, (int)$line['product_id']);

        if ($own) { $pdo->commit(); }
        return ['ok' => true, 'message' => 'Serial ' . $unit['serial_number'] . ' assigned to this order.'];
    } catch (Throwable $e) {
        if ($own) { try { $pdo->rollBack(); } catch (Throwable $_) {} }
        error_log('pu_assign_unit error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not assign that unit. Try again.'];
    }
}

/**
 * Which lines of this order still need a serial before it can go out.
 *
 * Only lines whose product's tracking is actually LIVE are counted - a flagged
 * but un-onboarded product has no units to assign, and demanding one would
 * make its orders unfulfillable. See the header.
 *
 * @return array<int,array{order_item_id:int,product_id:int,product_name:string,quantity:int,assigned:int}>
 */
function pu_unassigned_lines(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        "SELECT oi.id AS order_item_id, oi.product_id, oi.quantity, p.name AS product_name,
                (SELECT COUNT(*) FROM product_units pu
                  WHERE pu.order_item_id = oi.id
                    AND pu.status IN ('reserved','sold')) AS assigned
           FROM order_items oi
           JOIN products p ON p.id = oi.product_id
          WHERE oi.order_id = ?
            AND p.requires_serial = 1
            AND EXISTS (SELECT 1 FROM product_units x WHERE x.product_id = p.id)
          ORDER BY oi.id"
    );
    $stmt->execute([$orderId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)$row['assigned'] < (int)$row['quantity']) {
            $out[] = [
                'order_item_id' => (int)$row['order_item_id'],
                'product_id'    => (int)$row['product_id'],
                'product_name'  => (string)$row['product_name'],
                'quantity'      => (int)$row['quantity'],
                'assigned'      => (int)$row['assigned'],
            ];
        }
    }
    return $out;
}

/**
 * Detach one reserved unit from an order line and put it back on the shelf.
 *
 * The counterpart to pu_assign_unit(). Without it there was no way back from a
 * wrong assignment except cancelling the whole order: pu_assign_unit() can
 * swap one unit for another, but it cannot leave a line with fewer units than
 * it started with, so a line whose units were auto-reserved at checkout could
 * never be emptied.
 *
 * RESERVED ONLY. A `sold` unit is not detachable here, and that is the point:
 * sold means the goods physically left with the customer, and re-pointing that
 * serial afterwards would rewrite who received which unit - exactly the record
 * this whole feature exists to be able to trust. A sold unit comes back
 * through the returns desk (pu_accept_return) or a cancellation
 * (pu_release_for_order), both of which say why.
 *
 * @return array{ok:bool,message:string}
 */
function pu_release_unit(PDO $pdo, int $orderItemId, int $unitId): array
{
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }

    try {
        $liStmt = $pdo->prepare(
            "SELECT oi.id, oi.product_id, o.status AS order_status
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.id = ? FOR UPDATE"
        );
        $liStmt->execute([$orderItemId]);
        $line = $liStmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'That order line no longer exists.'];
        }
        if (in_array((string)$line['order_status'], ['cancelled', 'completed'], true)) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'This order is ' . $line['order_status'] . ' - its units cannot be changed.'];
        }

        $uStmt = $pdo->prepare('SELECT unit_id, serial_number, status, order_item_id FROM product_units WHERE unit_id = ? FOR UPDATE');
        $uStmt->execute([$unitId]);
        $unit = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'That unit no longer exists.'];
        }
        // Checked against THIS line, so a stale screen cannot detach a unit
        // that has since been moved to somebody else's order.
        if ((int)($unit['order_item_id'] ?? 0) !== $orderItemId) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' is no longer on this order line.'];
        }
        if ($unit['status'] !== 'reserved') {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' is ' . strtolower(pu_status_label((string)$unit['status'])) . ' and cannot be put back.'];
        }

        $pdo->prepare(
            "UPDATE product_units SET status = 'in_stock', order_item_id = NULL
              WHERE unit_id = ? AND status = 'reserved'"
        )->execute([$unitId]);

        pu_recount_stock($pdo, (int)$line['product_id']);

        if ($own) { $pdo->commit(); }
        return ['ok' => true, 'message' => 'Serial ' . $unit['serial_number'] . ' put back in stock.'];
    } catch (Throwable $e) {
        if ($own) { try { $pdo->rollBack(); } catch (Throwable $_) {} }
        error_log('pu_release_unit error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not put that unit back. Try again.'];
    }
}

/**
 * The "this order still needs serials" sentence, worded once.
 *
 * Two places tell an operator this: the scan's confirmation screen
 * (api/order_scan.php, before they press Confirm) and the scan itself
 * (os_advance_by_scan, which is the check that actually enforces it). They had
 * better say the same thing - a confirmation screen that phrases the problem
 * differently from the refusal reads like two different problems.
 *
 * @param array<int,array{product_name:string,quantity:int,assigned:int}> $lines
 *        as returned by pu_unassigned_lines()
 */
function pu_missing_serials_sentence(array $lines): string
{
    if (!$lines) { return ''; }

    $parts = [];
    foreach ($lines as $line) {
        $need = (int)$line['quantity'] - (int)$line['assigned'];
        $parts[] = $line['product_name'] . ' (' . $need . ' of ' . (int)$line['quantity'] . ')';
    }

    return 'This order needs a serial number recorded before it can be set aside: '
         . implode('; ', $parts)
         . '. Open the order in its queue and assign the units you are packing.';
}

/**
 * Mark this order's reserved units as sold. Called when the order is handed
 * over - the scan that moves it off `preparing`.
 *
 * date_sold is what the returns desk reads, so it is set from the same NOW()
 * as the status change rather than being backfilled later.
 *
 * No recount: a reserved unit was already off the shelf, so the mirror does
 * not move.
 */
function pu_mark_order_sold(PDO $pdo, int $orderId): int
{
    $stmt = $pdo->prepare(
        "UPDATE product_units pu
           JOIN order_items oi ON oi.id = pu.order_item_id
            SET pu.status = 'sold', pu.date_sold = NOW()
          WHERE oi.order_id = ? AND pu.status = 'reserved'"
    );
    $stmt->execute([$orderId]);
    return $stmt->rowCount();
}

/**
 * Put an order's units back on the shelf. Called by all three cancellation
 * paths, alongside the `stock = stock + quantity` they already do.
 *
 * Only takes back units that are reserved or sold. A unit the returns desk has
 * already marked returned or defective is NOT swept back into stock by a late
 * cancellation - it is in the building, broken or unchecked, and the
 * cancellation says nothing about its condition.
 *
 * The recount is what keeps the mirror right; the caller's own
 * `stock = stock + quantity` is then overwritten for tracked products, which
 * is correct - for them the unit rows are the truth.
 */
function pu_release_for_order(PDO $pdo, int $orderId): int
{
    // The products to recount, read BEFORE the update: afterwards the units no
    // longer point at this order and there is no way back to the list.
    $pidStmt = $pdo->prepare(
        "SELECT DISTINCT pu.product_id
           FROM product_units pu
           JOIN order_items oi ON oi.id = pu.order_item_id
          WHERE oi.order_id = ? AND pu.status IN ('reserved','sold')"
    );
    $pidStmt->execute([$orderId]);
    $productIds = array_map('intval', $pidStmt->fetchAll(PDO::FETCH_COLUMN));

    $upd = $pdo->prepare(
        "UPDATE product_units pu
           JOIN order_items oi ON oi.id = pu.order_item_id
            SET pu.status = 'in_stock', pu.order_item_id = NULL, pu.date_sold = NULL
          WHERE oi.order_id = ? AND pu.status IN ('reserved','sold')"
    );
    $upd->execute([$orderId]);
    $released = $upd->rowCount();

    foreach ($productIds as $pid) {
        pu_recount_stock($pdo, $pid);
    }
    return $released;
}

/**
 * Accept a unit back from a customer.
 *
 * THE VERIFICATION THE PANEL ASKED FOR. The unit is only accepted if it is
 * linked to an order belonging to the customer presenting it - which is the
 * whole point of this feature: a unit that was never sold, or was sold to
 * somebody else, is refused with a reason rather than quietly taken in.
 *
 * The returned unit does NOT go back into sellable stock. 'returned' and
 * 'defective' are both outside the in_stock count, so the mirror drops it and
 * the storefront stops offering it. Putting a just-returned gadget straight
 * back on the shelf would promise a customer a unit nobody has inspected.
 *
 * @param int $expectedCustomerId the customer at the counter, 0 to skip the
 *                                ownership check (an over-the-counter return
 *                                with no account behind it)
 * @return array{ok:bool,message:string,unit:?array<string,mixed>}
 */
function pu_accept_return(PDO $pdo, string $identifier, string $newStatus, int $expectedCustomerId = 0): array
{
    if (!in_array($newStatus, PU_RETURN_STATUSES, true)) {
        return ['ok' => false, 'message' => 'A return can only be recorded as returned or defective.', 'unit' => null];
    }

    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }

    try {
        $unit = pu_find_unit($pdo, $identifier);
        if (!$unit) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'No unit on file with that serial number or IMEI. This did not come from us, or it was never recorded.', 'unit' => null];
        }

        // Never sold. The commonest real case is a unit stolen or swapped from
        // the shop floor, so it is refused rather than accepted.
        if ($unit['status'] === 'in_stock') {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' is recorded as still being in our stock - it was never sold. Do not accept this return.', 'unit' => $unit];
        }
        if ($unit['status'] === 'reserved') {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' is reserved for an order that has not been handed over yet, so it cannot have been returned.', 'unit' => $unit];
        }
        if (in_array((string)$unit['status'], PU_RETURN_STATUSES, true)) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' was already taken back on ' . date('j M Y', strtotime((string)($unit['date_sold'] ?: 'now'))) . ' and is marked ' . strtolower(pu_status_label((string)$unit['status'])) . '.', 'unit' => $unit];
        }
        if (empty($unit['order_id'])) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' is marked sold but is not linked to any order, so there is nothing to verify it against.', 'unit' => $unit];
        }

        // The ownership check.
        if ($expectedCustomerId > 0 && (int)$unit['customer_id'] !== $expectedCustomerId) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $unit['serial_number'] . ' was sold to a different customer (order ' . ($unit['invoice_no'] ?: ('#' . $unit['order_id'])) . '). Do not accept this return.', 'unit' => $unit];
        }

        $pdo->prepare('UPDATE product_units SET status = ? WHERE unit_id = ? AND status = \'sold\'')
            ->execute([$newStatus, (int)$unit['unit_id']]);

        // Keeps the mirror right for the no-longer-sellable unit. In practice a
        // no-op, since a sold unit was not in the in_stock count either - but
        // it is the one statement that guarantees that stays true.
        pu_recount_stock($pdo, (int)$unit['product_id']);

        if ($own) { $pdo->commit(); }

        $unit['status'] = $newStatus;
        return [
            'ok' => true,
            'message' => 'Serial ' . $unit['serial_number'] . ' accepted back and marked ' . strtolower(pu_status_label($newStatus)) . '.',
            'unit' => $unit,
        ];
    } catch (Throwable $e) {
        if ($own) { try { $pdo->rollBack(); } catch (Throwable $_) {} }
        error_log('pu_accept_return error: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not record that return. Try again.', 'unit' => null];
    }
}

/**
 * The onboarding backlog: products flagged for tracking that have no units yet
 * AND actually have something on the shelf to enter.
 *
 * `p.stock > 0 AND p.status = 'active'` is the whole subtlety here, and leaving
 * it out was a real defect. The first version of this counted every flagged
 * product with no unit rows, which reported 138 when only 130 of them could be
 * acted on - the other 8 are active products with an empty shelf. There is no
 * serial to type for a product you hold none of; it onboards itself the moment
 * stock arrives and somebody enters it. Counting those 8 made the banner
 * overstate the job and, worse, made it impossible to ever reach zero: working
 * the list perfectly would still have left 8 behind with nothing to do about
 * them, which trains staff to ignore the banner.
 *
 * `dormant` is returned separately rather than discarded, so the page can still
 * account for them if it wants to without putting them in the number that
 * represents work.
 *
 * @return array{count:int,stock:int,dormant:int}
 */
function pu_onboarding_backlog(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT
            SUM(p.stock > 0 AND p.status = 'active')                               AS n,
            COALESCE(SUM(CASE WHEN p.stock > 0 AND p.status = 'active'
                              THEN p.stock ELSE 0 END), 0)                         AS stk,
            SUM(NOT (p.stock > 0 AND p.status = 'active'))                         AS dormant
           FROM products p
          WHERE p.requires_serial = 1
            AND NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id)"
    )->fetch(PDO::FETCH_ASSOC);

    return [
        'count'   => (int)($row['n'] ?? 0),
        'stock'   => (int)($row['stk'] ?? 0),
        'dormant' => (int)($row['dormant'] ?? 0),
    ];
}
