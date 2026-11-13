<?php
declare(strict_types=1);

/**
 * config/inventory_stock.php - Stock In, Stock Out and the pull-out approval
 * flow, in one place.
 *
 * This is the third layer of the inventory module, and it sits ON TOP of the
 * two that already exist rather than beside them:
 *
 *   config/product_units.php   owns what a physical unit is and keeps
 *                              products.stock a mirror of the in-stock unit
 *                              count. Every function here that changes a
 *                              unit's status calls pu_recount_stock()
 *                              afterwards, so the storefront, the PC
 *                              configurator and the AI recommender see the
 *                              right number without knowing this file exists.
 *   admin/inventory_control.php the dashboard that reports on all of it.
 *
 * WHO MAY DO WHAT - the rule, in one sentence each
 *
 *   Admin (Manager)       receives deliveries, marks units defective, raises
 *                         pull-out requests.
 *   Super Admin (Owner)   approves or rejects a pull-out, records its final
 *                         action, and changes the slow-moving threshold.
 *
 * Enforced by require_role() on the pages AND re-checked here by the two
 * functions that must never run for a Manager (inv_decide_pullout and
 * inv_set_slow_moving_days), because a page-level check is one redirect away
 * from being the only thing standing between a Manager and the Owner's
 * approval.
 *
 * EVERY STATE CHANGE IS LOGGED. inv_log_movement() is the only writer to
 * stock_movements in this file, so the history cannot have a gap that a
 * second code path quietly created.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/product_units.php';

/** Where a defect was found. Matches pull_out_requests.source. */
const INV_PULLOUT_SOURCES = [
    'bad_order_delivery' => 'Bad order on delivery',
    'found_in_stock'     => 'Found in stock',
    'customer_return'    => 'Customer return',
    'slow_moving'        => 'Slow-moving / Discontinued',
];

/** What finally happened to an approved pull-out. */
const INV_FINAL_ACTIONS = [
    'returned_to_supplier' => 'Returned to Supplier',
    'replaced'             => 'Replaced',
    'disposed'             => 'Disposed',
];

/** The movement types this module records, and how they read. */
const INV_MOVEMENT_LABELS = [
    'stock_in'             => 'Stock In',
    'sold'                 => 'Sold',
    'defective'            => 'Defective',
    'pull_out'             => 'Pull-Out',
    'returned_to_supplier' => 'Returned to Supplier',
    'disposed'             => 'Disposed',
    'customer_return'      => 'Customer Return',
    // Already on live rows before this module existed.
    'in'                   => 'Stock In (legacy)',
    'out'                  => 'Stock Out (legacy)',
    'adjustment'           => 'Adjustment',
    'restock'              => 'Restock',
];

/** Movement types that ADD sellable stock. Used by the summary report. */
const INV_MOVEMENT_IN = ['stock_in', 'in', 'restock'];

/** Movement types that REMOVE sellable stock. */
const INV_MOVEMENT_OUT = ['sold', 'defective', 'pull_out', 'returned_to_supplier', 'disposed', 'out'];

/** Default days-without-a-sale before a Discontinued product is flagged. */
const INV_SLOW_MOVING_DEFAULT_DAYS = 90;

/* =========================================================================
 * Settings
 * ====================================================================== */

/** How many days without a sale before a Discontinued product is flagged. */
function inv_slow_moving_days(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM inventory_settings WHERE setting_key = 'slow_moving_days' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        $n = (int)$v;
        // A zero or negative threshold would flag everything the moment it was
        // discontinued, which is not a setting anyone means to choose.
        return $n > 0 ? $n : INV_SLOW_MOVING_DEFAULT_DAYS;
    } catch (Throwable $e) {
        return INV_SLOW_MOVING_DEFAULT_DAYS;
    }
}

/**
 * Change the slow-moving threshold. SUPER ADMIN ONLY.
 *
 * The role is checked here and not only on the page: this setting decides what
 * the whole shop treats as dead stock, and the brief puts it in the Owner's
 * hands specifically.
 *
 * @return array{ok:bool,message:string}
 */
function inv_set_slow_moving_days(PDO $pdo, int $days, bool $isOwner): array
{
    if (!$isOwner) {
        return ['ok' => false, 'message' => 'Only the Super Admin (Owner) can change the slow-moving threshold.'];
    }
    if ($days < 1 || $days > 3650) {
        return ['ok' => false, 'message' => 'Enter a number of days between 1 and 3650.'];
    }
    $pdo->prepare(
        "INSERT INTO inventory_settings (setting_key, setting_value, description)
         VALUES ('slow_moving_days', ?, 'Days without a sale before a Discontinued product holding stock is flagged \"Poorly Sold - For Pull-Out\". Super Admin only.')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([(string)$days]);

    return ['ok' => true, 'message' => 'Slow-moving threshold set to ' . $days . ' days.'];
}

/* =========================================================================
 * The movement log - the only writer
 * ====================================================================== */

/**
 * Record one stock movement.
 *
 * Called for every state change in this file. `serial_number` is snapshotted
 * rather than joined at read time because the history has to stay readable
 * after a unit row is deleted - a movement that can no longer say which serial
 * moved is not a record of anything.
 *
 * @param array{
 *   product_id:int, movement_type:string, quantity?:int, product_unit_id?:?int,
 *   serial_number?:?string, user_id?:?int, reason?:?string, reference_no?:?string,
 *   reference_type?:?string, reference_id?:?int, notes?:?string
 * } $m
 */
function inv_log_movement(PDO $pdo, array $m): void
{
    $type = (string)($m['movement_type'] ?? '');
    if (!isset(INV_MOVEMENT_LABELS[$type])) {
        // A typo'd type would be silently coerced to '' by MySQL on this
        // server (no STRICT_TRANS_TABLES), producing a row the history cannot
        // categorise. Refused loudly instead.
        throw new InvalidArgumentException('Unknown stock movement type: ' . $type);
    }

    $pdo->prepare(
        "INSERT INTO stock_movements
            (product_id, product_unit_id, serial_number, movement_type, quantity,
             user_id, reason, reference_no, reference_type, reference_id, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([
        (int)$m['product_id'],
        isset($m['product_unit_id']) ? ($m['product_unit_id'] !== null ? (int)$m['product_unit_id'] : null) : null,
        $m['serial_number'] ?? null,
        $type,
        (int)($m['quantity'] ?? 1),
        isset($m['user_id']) ? ($m['user_id'] !== null ? (int)$m['user_id'] : null) : null,
        $m['reason'] ?? null,
        $m['reference_no'] ?? null,
        $m['reference_type'] ?? null,
        isset($m['reference_id']) ? ($m['reference_id'] !== null ? (int)$m['reference_id'] : null) : null,
        $m['notes'] ?? null,
    ]);
}

/* =========================================================================
 * 1. STOCK IN - deliveries
 * ====================================================================== */

/**
 * Receive a delivery: header, lines, units and movements, in one transaction.
 *
 * SERIALISED vs QUANTITY lines are both accepted in one delivery, because a
 * real delivery note mixes them - two laptops and a box of cables arrive
 * together. Which treatment a line gets is decided by the product, not by the
 * form:
 *
 *   products.requires_serial = 1  every unit needs its own serial. The count
 *                                 of serials IS the quantity, so the form does
 *                                 not ask for a quantity it could contradict.
 *   otherwise                     a quantity, added to products.stock.
 *
 * Partial success is deliberately NOT allowed here (unlike adding units on the
 * Unit Tracking page): a delivery is a document that either was or was not
 * received, and half a DR in the system is worse than none. Any bad line
 * rolls the whole thing back and reports every problem at once.
 *
 * @param array{supplier_id:?int,dr_number:string,delivery_date:string,notes:?string} $header
 * @param array<int,array{product_id:int,quantity:int,serials:array<int,array{serial:string,imei:string}>}> $lines
 * @return array{ok:bool,delivery_id:?int,errors:array<int,string>,units_added:int,qty_added:int,message:string}
 */
function inv_receive_delivery(PDO $pdo, array $header, array $lines, int $userId): array
{
    $errors = [];

    $dr = trim((string)($header['dr_number'] ?? ''));
    if ($dr === '')              { $errors[] = 'Enter the delivery receipt (DR) number.'; }
    if (mb_strlen($dr) > 60)     { $errors[] = 'That DR number is too long (60 characters maximum).'; }

    $date = trim((string)($header['delivery_date'] ?? ''));
    $d = $date !== '' ? DateTime::createFromFormat('Y-m-d', $date) : false;
    if (!$d || $d->format('Y-m-d') !== $date) {
        $errors[] = 'Choose a valid delivery date.';
    } elseif ($d > new DateTime('today +1 day')) {
        // Tomorrow is allowed for a timezone edge; next month is a typo.
        $errors[] = 'That delivery date is in the future.';
    }

    $supplierId = !empty($header['supplier_id']) ? (int)$header['supplier_id'] : null;
    if ($supplierId !== null) {
        $chk = $pdo->prepare('SELECT 1 FROM suppliers WHERE id = ? LIMIT 1');
        $chk->execute([$supplierId]);
        if (!$chk->fetchColumn()) { $errors[] = 'That supplier no longer exists.'; }
    }

    if (!$lines) { $errors[] = 'Add at least one item to the delivery.'; }

    if ($errors) {
        return ['ok' => false, 'delivery_id' => null, 'errors' => $errors,
                'units_added' => 0, 'qty_added' => 0, 'message' => ''];
    }

    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }

    try {
        $pdo->prepare(
            'INSERT INTO stock_deliveries (supplier_id, dr_number, delivery_date, received_by, notes, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$supplierId, $dr, $date, $userId, ($header['notes'] ?? null) ?: null]);
        $deliveryId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO stock_delivery_items (delivery_id, product_id, quantity) VALUES (?, ?, ?)'
        );
        $unitStmt = $pdo->prepare(
            "INSERT INTO product_units (product_id, serial_number, imei, status, delivery_id, date_added)
             VALUES (?, ?, ?, 'in_stock', ?, NOW())"
        );

        $unitsAdded = 0;
        $qtyAdded   = 0;
        $seenSerial = [];   // duplicates within this one delivery
        $seenImei   = [];

        foreach ($lines as $i => $line) {
            $lineNo    = $i + 1;
            $productId = (int)($line['product_id'] ?? 0);

            $pStmt = $pdo->prepare('SELECT id, name, requires_serial FROM products WHERE id = ? LIMIT 1');
            $pStmt->execute([$productId]);
            $product = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                $errors[] = "Line {$lineNo}: that product does not exist.";
                continue;
            }

            if (!empty($product['requires_serial'])) {
                $serials = $line['serials'] ?? [];
                if (!$serials) {
                    $errors[] = "Line {$lineNo} ({$product['name']}): this product is tracked per unit, so it needs a serial number for every unit received.";
                    continue;
                }

                foreach ($serials as $k => $row) {
                    $label = "Line {$lineNo} serial " . ($k + 1) . ': ';

                    $sv = pu_validate_serial($row['serial'] ?? '');
                    if (!$sv['ok']) { $errors[] = $label . $sv['error']; continue; }
                    $serial = (string)$sv['value'];

                    $iv = pu_validate_imei($row['imei'] ?? '');
                    if (!$iv['ok']) { $errors[] = $label . $iv['error']; continue; }
                    $imei = $iv['value'] !== null ? (string)$iv['value'] : null;

                    // Case-insensitively, to agree with the column's
                    // utf8mb4_unicode_ci UNIQUE index.
                    $key = mb_strtolower($serial);
                    if (isset($seenSerial[$key])) {
                        $errors[] = $label . 'serial ' . $serial . ' is repeated on this delivery (first seen on line ' . $seenSerial[$key] . ').';
                        continue;
                    }
                    if ($imei !== null && isset($seenImei[$imei])) {
                        $errors[] = $label . 'IMEI ' . $imei . ' is repeated on this delivery.';
                        continue;
                    }

                    // Against everything already on file, so the message can
                    // name the product the serial is already against.
                    $dupe = pu_find_unit($pdo, $serial);
                    if ($dupe) {
                        $errors[] = $label . 'serial ' . $serial . ' is already on file for '
                                  . $dupe['product_name'] . ' (' . pu_status_label((string)$dupe['status']) . ').';
                        continue;
                    }
                    if ($imei !== null && pu_find_unit($pdo, $imei)) {
                        $errors[] = $label . 'IMEI ' . $imei . ' is already on file.';
                        continue;
                    }

                    $unitStmt->execute([$productId, $serial, $imei, $deliveryId]);
                    $unitId = (int)$pdo->lastInsertId();

                    $seenSerial[$key] = $lineNo;
                    if ($imei !== null) { $seenImei[$imei] = $lineNo; }
                    $unitsAdded++;

                    inv_log_movement($pdo, [
                        'product_id'      => $productId,
                        'product_unit_id' => $unitId,
                        'serial_number'   => $serial,
                        'movement_type'   => 'stock_in',
                        'quantity'        => 1,
                        'user_id'         => $userId,
                        'reason'          => 'Delivery received',
                        'reference_no'    => $dr,
                        'reference_type'  => 'delivery',
                        'reference_id'    => $deliveryId,
                    ]);
                }

                // The serial count IS the quantity for a tracked line - the
                // form never asks for one, so the two cannot disagree.
                $lineQty = count($serials);
                $itemStmt->execute([$deliveryId, $productId, $lineQty]);
                // pu_recount_stock() sets products.stock from the unit rows,
                // which is what makes the storefront and configurator correct.
                pu_recount_stock($pdo, $productId);
                continue;
            }

            // Quantity-only line.
            $qty = (int)($line['quantity'] ?? 0);
            $qc  = pv_restock_qty((string)$qty, 0);
            if (!$qc['ok']) {
                $errors[] = "Line {$lineNo} ({$product['name']}): " . $qc['error'];
                continue;
            }

            $itemStmt->execute([$deliveryId, $productId, $qty]);
            $pdo->prepare('UPDATE products SET stock = stock + ?, updated_at = NOW() WHERE id = ?')
                ->execute([$qty, $productId]);
            $qtyAdded += $qty;

            inv_log_movement($pdo, [
                'product_id'     => $productId,
                'movement_type'  => 'stock_in',
                'quantity'       => $qty,
                'user_id'        => $userId,
                'reason'         => 'Delivery received',
                'reference_no'   => $dr,
                'reference_type' => 'delivery',
                'reference_id'   => $deliveryId,
            ]);
        }

        if ($errors) {
            // All or nothing - see the note above.
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'delivery_id' => null, 'errors' => $errors,
                    'units_added' => 0, 'qty_added' => 0, 'message' => ''];
        }

        if ($own) { $pdo->commit(); }

        $bits = [];
        if ($unitsAdded) { $bits[] = $unitsAdded . ' serialised unit' . ($unitsAdded === 1 ? '' : 's'); }
        if ($qtyAdded)   { $bits[] = $qtyAdded . ' unit' . ($qtyAdded === 1 ? '' : 's') . ' of quantity stock'; }

        return [
            'ok' => true, 'delivery_id' => $deliveryId, 'errors' => [],
            'units_added' => $unitsAdded, 'qty_added' => $qtyAdded,
            'message' => 'Delivery ' . $dr . ' received - ' . (implode(' and ', $bits) ?: 'no items') . '.',
        ];
    } catch (PDOException $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        // 1062 on uniq_supplier_dr: this DR has already been received.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'delivery_id' => null,
                    'errors' => ['Delivery ' . $dr . ' has already been received from this supplier.'],
                    'units_added' => 0, 'qty_added' => 0, 'message' => ''];
        }
        error_log('inv_receive_delivery: ' . $e->getMessage());
        return ['ok' => false, 'delivery_id' => null, 'errors' => ['Could not save the delivery. Nothing was recorded.'],
                'units_added' => 0, 'qty_added' => 0, 'message' => ''];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('inv_receive_delivery: ' . $e->getMessage());
        return ['ok' => false, 'delivery_id' => null, 'errors' => ['Could not save the delivery. Nothing was recorded.'],
                'units_added' => 0, 'qty_added' => 0, 'message' => ''];
    }
}

/* =========================================================================
 * 2. STOCK OUT - defective and pull-out
 * ====================================================================== */

/** Next pull-out reference. PO-YYYYMMDD-XXXX. */
function inv_next_pullout_ref(PDO $pdo): string
{
    for ($i = 0; $i < 8; $i++) {
        $ref = 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('SELECT 1 FROM pull_out_requests WHERE reference_no = ? LIMIT 1');
        $stmt->execute([$ref]);
        if (!$stmt->fetchColumn()) { return $ref; }
    }
    // Astronomically unlikely; a timestamp suffix is still unique enough.
    return 'PO-' . date('Ymd-His') . '-' . random_int(100, 999);
}

/**
 * Raise a pull-out request for specific units, and take them out of sellable
 * stock immediately.
 *
 * ORDER OF EVENTS MATTERS, and it is the brief's: the unit becomes Defective
 * and leaves sellable stock STRAIGHT AWAY (step b), and only then is the
 * request created Pending Approval (step c). A faulty unit must not stay
 * sellable while an approval sits in a queue - the customer who buys it in the
 * meantime is the whole problem being solved.
 *
 * A customer-return source is verified against the order record before the
 * unit is accepted - pu_find_unit() resolves the serial to its order and
 * customer, exactly as the returns desk does.
 *
 * @param array<int,int> $unitIds
 * @return array{ok:bool,pull_out_id:?int,reference_no:?string,errors:array<int,string>,count:int,message:string}
 */
function inv_create_pullout(PDO $pdo, array $unitIds, string $source, string $reason, int $userId, array $remarks = []): array
{
    $errors = [];

    if (!isset(INV_PULLOUT_SOURCES[$source])) { $errors[] = 'Choose where the defect was found.'; }
    $reason = trim($reason);
    if (mb_strlen($reason) < 5)  { $errors[] = 'Describe the defect (at least 5 characters).'; }
    if (mb_strlen($reason) > 2000) { $errors[] = 'That defect description is too long.'; }

    $unitIds = array_values(array_unique(array_map('intval', array_filter($unitIds))));
    if (!$unitIds) { $errors[] = 'Pick at least one unit by serial number.'; }

    if ($errors) {
        return ['ok' => false, 'pull_out_id' => null, 'reference_no' => null,
                'errors' => $errors, 'count' => 0, 'message' => ''];
    }

    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }

    try {
        $in = implode(',', array_fill(0, count($unitIds), '?'));
        $sel = $pdo->prepare(
            "SELECT pu.unit_id, pu.product_id, pu.serial_number, pu.status, pu.order_item_id,
                    p.name AS product_name
               FROM product_units pu JOIN products p ON p.id = pu.product_id
              WHERE pu.unit_id IN ($in) FOR UPDATE"
        );
        $sel->execute($unitIds);
        $units = $sel->fetchAll(PDO::FETCH_ASSOC);

        if (count($units) !== count($unitIds)) {
            $errors[] = 'One or more of those units no longer exists.';
        }

        foreach ($units as $u) {
            $st = (string)$u['status'];
            // Already gone, or already on a pull-out.
            if ($st === 'pulled_out') {
                $errors[] = 'Serial ' . $u['serial_number'] . ' has already been pulled out.';
                continue;
            }
            if ($st === 'reserved') {
                $errors[] = 'Serial ' . $u['serial_number'] . ' is reserved for a customer order. Release it from that order first.';
                continue;
            }
            $onPullout = $pdo->prepare('SELECT 1 FROM pull_out_items WHERE product_unit_id = ? LIMIT 1');
            $onPullout->execute([(int)$u['unit_id']]);
            if ($onPullout->fetchColumn()) {
                $errors[] = 'Serial ' . $u['serial_number'] . ' is already on another pull-out request.';
                continue;
            }

            /* CUSTOMER RETURN: verify the serial against the order record
               before accepting it, as the brief requires. A unit that was
               never sold cannot have come back from a customer, and accepting
               it would let a unit that walked off the shop floor be written
               off as a return. */
            if ($source === 'customer_return' && !in_array($st, ['sold', 'returned'], true)) {
                $errors[] = 'Serial ' . $u['serial_number'] . ' is recorded as ' . strtolower(pu_status_label($st))
                          . ', not sold - so it cannot be a customer return. Check it on the Serial / IMEI Lookup page.';
                continue;
            }
            if ($source === 'customer_return' && empty($u['order_item_id'])) {
                $errors[] = 'Serial ' . $u['serial_number'] . ' is not linked to any order, so there is nothing to verify the return against.';
                continue;
            }
        }

        if ($errors) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'pull_out_id' => null, 'reference_no' => null,
                    'errors' => $errors, 'count' => 0, 'message' => ''];
        }

        $ref = inv_next_pullout_ref($pdo);
        $pdo->prepare(
            "INSERT INTO pull_out_requests (reference_no, requested_by, source, reason, status, created_at)
             VALUES (?, ?, ?, ?, 'pending', NOW())"
        )->execute([$ref, $userId, $source, $reason]);
        $pullOutId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO pull_out_items (pull_out_id, product_unit_id, product_id, serial_number, remarks)
             VALUES (?, ?, ?, ?, ?)'
        );
        $markStmt = $pdo->prepare("UPDATE product_units SET status = 'defective' WHERE unit_id = ?");

        $touchedProducts = [];
        foreach ($units as $u) {
            $unitId = (int)$u['unit_id'];

            // (b) Defective and out of sellable stock, immediately.
            $markStmt->execute([$unitId]);
            $touchedProducts[(int)$u['product_id']] = true;

            $itemStmt->execute([
                $pullOutId, $unitId, (int)$u['product_id'], (string)$u['serial_number'],
                ($remarks[$unitId] ?? null) ?: null,
            ]);

            inv_log_movement($pdo, [
                'product_id'      => (int)$u['product_id'],
                'product_unit_id' => $unitId,
                'serial_number'   => (string)$u['serial_number'],
                'movement_type'   => $source === 'customer_return' ? 'customer_return' : 'defective',
                'quantity'        => 1,
                'user_id'         => $userId,
                'reason'          => INV_PULLOUT_SOURCES[$source] . ' - ' . $reason,
                'reference_no'    => $ref,
                'reference_type'  => 'pull_out',
                'reference_id'    => $pullOutId,
            ]);

            // (c) The request itself is the pending step.
            inv_log_movement($pdo, [
                'product_id'      => (int)$u['product_id'],
                'product_unit_id' => $unitId,
                'serial_number'   => (string)$u['serial_number'],
                'movement_type'   => 'pull_out',
                'quantity'        => 1,
                'user_id'         => $userId,
                'reason'          => 'Pull-out requested (' . INV_PULLOUT_SOURCES[$source] . ') - awaiting Owner approval',
                'reference_no'    => $ref,
                'reference_type'  => 'pull_out',
                'reference_id'    => $pullOutId,
            ]);
        }

        // Defective units are no longer in_stock, so the mirror has to move -
        // this is what hides them from the storefront and the configurator.
        foreach (array_keys($touchedProducts) as $pid) { pu_recount_stock($pdo, $pid); }

        if ($own) { $pdo->commit(); }

        $n = count($units);
        return [
            'ok' => true, 'pull_out_id' => $pullOutId, 'reference_no' => $ref, 'errors' => [],
            'count' => $n,
            'message' => $ref . ' raised for ' . $n . ' unit' . ($n === 1 ? '' : 's')
                       . '. They are already out of sellable stock and the request is awaiting Super Admin approval.',
        ];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('inv_create_pullout: ' . $e->getMessage());
        return ['ok' => false, 'pull_out_id' => null, 'reference_no' => null,
                'errors' => ['Could not raise the pull-out. Nothing was changed.'], 'count' => 0, 'message' => ''];
    }
}

/**
 * Approve or reject a pull-out. SUPER ADMIN (OWNER) ONLY.
 *
 * Re-checked here rather than trusting the page, for the reason given in the
 * file header: this is the approval the whole flow exists to require.
 *
 * ON REJECT the units stay Defective. They were reported faulty by a Manager
 * who had them in their hands, and a rejection means "do not pull this out" -
 * not "this unit is fine after all". Putting a unit the shop believes is
 * broken back on the shelf has to be a deliberate separate act, which is what
 * inv_return_unit_to_stock() is for.
 *
 * @return array{ok:bool,message:string}
 */
function inv_decide_pullout(PDO $pdo, int $pullOutId, string $decision, int $ownerId, string $notes, bool $isOwner): array
{
    if (!$isOwner) {
        return ['ok' => false, 'message' => 'Only the Super Admin (Owner) can approve or reject a pull-out.'];
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Choose approve or reject.'];
    }

    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }

    try {
        $stmt = $pdo->prepare('SELECT * FROM pull_out_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$pullOutId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'That pull-out request no longer exists.'];
        }
        // Re-proved under the row lock: two Owners pressing Approve at the same
        // moment both saw 'pending', and only the one that flips the row wins.
        if ((string)$req['status'] !== 'pending') {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'This request was already '
                . $req['status'] . ' by someone else.'];
        }

        $upd = $pdo->prepare(
            "UPDATE pull_out_requests
                SET status = ?, reviewed_by = ?, review_notes = ?, reviewed_at = NOW()
              WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$decision, $ownerId, trim($notes) ?: null, $pullOutId]);
        if ($upd->rowCount() !== 1) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'This request was already processed by someone else.'];
        }

        // Logged per unit, so the history shows the decision against every
        // serial it covered rather than only against the request.
        $items = $pdo->prepare('SELECT product_unit_id, product_id, serial_number FROM pull_out_items WHERE pull_out_id = ?');
        $items->execute([$pullOutId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            inv_log_movement($pdo, [
                'product_id'      => (int)$it['product_id'],
                'product_unit_id' => $it['product_unit_id'] !== null ? (int)$it['product_unit_id'] : null,
                'serial_number'   => $it['serial_number'],
                'movement_type'   => 'pull_out',
                'quantity'        => 1,
                'user_id'         => $ownerId,
                'reason'          => 'Pull-out ' . $decision . ' by Super Admin'
                                   . (trim($notes) !== '' ? ' - ' . trim($notes) : ''),
                'reference_no'    => (string)$req['reference_no'],
                'reference_type'  => 'pull_out',
                'reference_id'    => $pullOutId,
            ]);
        }

        if ($own) { $pdo->commit(); }

        return ['ok' => true, 'message' => $decision === 'approved'
            ? $req['reference_no'] . ' approved. Record the final action next.'
            : $req['reference_no'] . ' rejected. The units stay marked Defective.'];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('inv_decide_pullout: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not record that decision. Try again.'];
    }
}

/**
 * Record what finally happened to an APPROVED pull-out, and retire its units.
 *
 * Only after approval, and only once. The unit becomes 'pulled_out' - the
 * terminal state - because at this point it has physically left the building
 * (to the supplier, as a replacement, or to disposal).
 *
 * @return array{ok:bool,message:string}
 */
function inv_record_final_action(PDO $pdo, int $pullOutId, string $action, int $userId, string $notes, bool $isOwner): array
{
    if (!$isOwner) {
        return ['ok' => false, 'message' => 'Only the Super Admin (Owner) can record the final action.'];
    }
    if (!isset(INV_FINAL_ACTIONS[$action])) {
        return ['ok' => false, 'message' => 'Choose returned to supplier, replaced or disposed.'];
    }

    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }

    try {
        $stmt = $pdo->prepare('SELECT * FROM pull_out_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$pullOutId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'That pull-out request no longer exists.'];
        }
        if ((string)$req['status'] !== 'approved') {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'A final action can only be recorded on an approved request. This one is ' . $req['status'] . '.'];
        }
        if (!empty($req['final_action'])) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'The final action for ' . $req['reference_no'] . ' is already recorded as '
                . (INV_FINAL_ACTIONS[(string)$req['final_action']] ?? $req['final_action']) . '.'];
        }

        $pdo->prepare(
            'UPDATE pull_out_requests
                SET final_action = ?, final_action_by = ?, final_action_at = NOW(), final_action_notes = ?
              WHERE id = ? AND final_action IS NULL'
        )->execute([$action, $userId, trim($notes) ?: null, $pullOutId]);

        $items = $pdo->prepare('SELECT product_unit_id, product_id, serial_number FROM pull_out_items WHERE pull_out_id = ?');
        $items->execute([$pullOutId]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);

        $retire = $pdo->prepare("UPDATE product_units SET status = 'pulled_out' WHERE unit_id = ?");
        // 'replaced' is still a Stock Out of THIS unit - the replacement
        // arrives as its own delivery with its own serial, which is the only
        // way the two can be told apart later.
        $movement = $action === 'disposed' ? 'disposed'
                  : ($action === 'returned_to_supplier' ? 'returned_to_supplier' : 'pull_out');

        $touched = [];
        foreach ($rows as $it) {
            if ($it['product_unit_id'] !== null) {
                $retire->execute([(int)$it['product_unit_id']]);
                $touched[(int)$it['product_id']] = true;
            }
            inv_log_movement($pdo, [
                'product_id'      => (int)$it['product_id'],
                'product_unit_id' => $it['product_unit_id'] !== null ? (int)$it['product_unit_id'] : null,
                'serial_number'   => $it['serial_number'],
                'movement_type'   => $movement,
                'quantity'        => 1,
                'user_id'         => $userId,
                'reason'          => INV_FINAL_ACTIONS[$action]
                                   . (trim($notes) !== '' ? ' - ' . trim($notes) : ''),
                'reference_no'    => (string)$req['reference_no'],
                'reference_type'  => 'pull_out',
                'reference_id'    => $pullOutId,
            ]);
        }
        foreach (array_keys($touched) as $pid) { pu_recount_stock($pdo, $pid); }

        if ($own) { $pdo->commit(); }
        return ['ok' => true, 'message' => $req['reference_no'] . ' recorded as ' . INV_FINAL_ACTIONS[$action] . '.'];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('inv_record_final_action: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not record the final action. Try again.'];
    }
}

/**
 * Put a defective unit back into sellable stock.
 *
 * The deliberate way out of 'defective' - used when a unit was marked faulty
 * by mistake, or when a rejected pull-out means the Owner judged it saleable
 * after all. Refuses a unit that is on a live pull-out, because that request
 * has not been decided yet.
 *
 * @return array{ok:bool,message:string}
 */
function inv_return_unit_to_stock(PDO $pdo, int $unitId, int $userId, string $reason): array
{
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $stmt = $pdo->prepare(
            'SELECT pu.unit_id, pu.product_id, pu.serial_number, pu.status
               FROM product_units pu WHERE pu.unit_id = ? FOR UPDATE'
        );
        $stmt->execute([$unitId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'That unit no longer exists.'];
        }
        if (!in_array((string)$u['status'], ['defective', 'returned'], true)) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $u['serial_number'] . ' is '
                . strtolower(pu_status_label((string)$u['status'])) . ', so it cannot be returned to stock from here.'];
        }

        $live = $pdo->prepare(
            "SELECT r.reference_no FROM pull_out_items i
               JOIN pull_out_requests r ON r.id = i.pull_out_id
              WHERE i.product_unit_id = ? AND r.status = 'pending' LIMIT 1"
        );
        $live->execute([$unitId]);
        if ($ref = $live->fetchColumn()) {
            if ($own) { $pdo->rollBack(); }
            return ['ok' => false, 'message' => 'Serial ' . $u['serial_number'] . ' is on pull-out '
                . $ref . ', which is still awaiting approval. That has to be decided first.'];
        }

        $pdo->prepare("UPDATE product_units SET status = 'in_stock', order_item_id = NULL, date_sold = NULL WHERE unit_id = ?")
            ->execute([$unitId]);
        pu_recount_stock($pdo, (int)$u['product_id']);

        inv_log_movement($pdo, [
            'product_id'      => (int)$u['product_id'],
            'product_unit_id' => $unitId,
            'serial_number'   => (string)$u['serial_number'],
            'movement_type'   => 'stock_in',
            'quantity'        => 1,
            'user_id'         => $userId,
            'reason'          => 'Returned to sellable stock - ' . (trim($reason) ?: 'no reason given'),
            'reference_type'  => 'unit_restore',
            'reference_id'    => $unitId,
        ]);

        if ($own) { $pdo->commit(); }
        return ['ok' => true, 'message' => 'Serial ' . $u['serial_number'] . ' is back in sellable stock.'];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('inv_return_unit_to_stock: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not return that unit to stock. Try again.'];
    }
}

/* =========================================================================
 * 3. Slow-moving / discontinued flagging
 * ====================================================================== */

/**
 * Products flagged "Poorly Sold - For Pull-Out".
 *
 * DERIVED, NOT STORED. The flag is a function of three facts the database
 * already holds - status = 'discontinued', stock > 0, and the date of the last
 * sale - so computing it on read is both cheaper and safer than storing a
 * column that a missed cron run leaves lying. "The check can run on page load
 * of the Inventory Control dashboard" is satisfied by calling this; there is
 * no job that can fail to run, and the answer cannot be stale.
 *
 * Last sale is taken from order_items joined to orders, counting only orders
 * that were not cancelled - a cancelled order is not a sale and must not keep
 * dead stock looking alive.
 *
 * @return array<int,array<string,mixed>>
 */
function inv_flagged_items(PDO $pdo, ?int $days = null): array
{
    $days = $days ?? inv_slow_moving_days($pdo);

    $stmt = $pdo->prepare(
        "SELECT p.id,
                p.name,
                p.brand,
                p.stock,
                p.price,
                p.image_url,
                p.discontinued_at,
                c.name AS category_name,
                (SELECT MAX(DATE(o.created_at))
                   FROM order_items oi JOIN orders o ON o.id = oi.order_id
                  WHERE oi.product_id = p.id AND o.status <> 'cancelled') AS last_sale_date,
                /* NULL last sale means never sold. The dashboard needs a
                   number to sort on, so 'days since' falls back to how long
                   the product has existed - which is the honest answer to
                   'how long has this been sitting there'. */
                COALESCE(
                    DATEDIFF(CURDATE(), (SELECT MAX(DATE(o.created_at))
                        FROM order_items oi JOIN orders o ON o.id = oi.order_id
                       WHERE oi.product_id = p.id AND o.status <> 'cancelled')),
                    DATEDIFF(CURDATE(), DATE(p.created_at))
                ) AS days_unsold,
                DATE(p.created_at) AS date_added,
                (SELECT MIN(DATE(pu.date_added)) FROM product_units pu
                  WHERE pu.product_id = p.id AND pu.status = 'in_stock') AS oldest_unit_date
           FROM products p
      LEFT JOIN categories c ON c.id = p.category_id
          WHERE p.status = 'discontinued'
            AND p.stock > 0
            AND COALESCE(
                    DATEDIFF(CURDATE(), (SELECT MAX(DATE(o.created_at))
                        FROM order_items oi JOIN orders o ON o.id = oi.order_id
                       WHERE oi.product_id = p.id AND o.status <> 'cancelled')),
                    DATEDIFF(CURDATE(), DATE(p.created_at))
                ) >= ?
          ORDER BY days_unsold DESC, p.stock DESC, p.name ASC"
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================================
 * 4. Sold movements - so the history is complete
 * ====================================================================== */

/**
 * Log 'sold' movements for one order's items.
 *
 * Called at handover, beside pu_mark_order_sold(). Idempotent: it skips any
 * line that already has a sold movement against this order, so a re-scan or a
 * backfill cannot double-count the history.
 *
 * @return int movements written
 */
function inv_log_sold_for_order(PDO $pdo, int $orderId, ?int $userId = null): int
{
    $o = $pdo->prepare('SELECT invoice_no FROM orders WHERE id = ? LIMIT 1');
    $o->execute([$orderId]);
    $invoice = (string)($o->fetchColumn() ?: ('ORD-' . $orderId));

    $items = $pdo->prepare(
        'SELECT oi.id, oi.product_id, oi.quantity FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id'
    );
    $items->execute([$orderId]);

    $already = $pdo->prepare(
        "SELECT 1 FROM stock_movements
          WHERE movement_type = 'sold' AND reference_type = 'order'
            AND reference_id = ? AND product_id = ? LIMIT 1"
    );

    // Units carry the serials; a quantity line has none.
    $unitsFor = $pdo->prepare(
        "SELECT unit_id, serial_number FROM product_units WHERE order_item_id = ? AND status IN ('sold','reserved')"
    );

    $written = 0;
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $already->execute([$orderId, (int)$it['product_id']]);
        if ($already->fetchColumn()) { continue; }

        $unitsFor->execute([(int)$it['id']]);
        $units = $unitsFor->fetchAll(PDO::FETCH_ASSOC);

        if ($units) {
            foreach ($units as $u) {
                inv_log_movement($pdo, [
                    'product_id'      => (int)$it['product_id'],
                    'product_unit_id' => (int)$u['unit_id'],
                    'serial_number'   => (string)$u['serial_number'],
                    'movement_type'   => 'sold',
                    'quantity'        => 1,
                    'user_id'         => $userId,
                    'reason'          => 'Sold to customer',
                    'reference_no'    => $invoice,
                    'reference_type'  => 'order',
                    'reference_id'    => $orderId,
                ]);
                $written++;
            }
            continue;
        }

        inv_log_movement($pdo, [
            'product_id'     => (int)$it['product_id'],
            'movement_type'  => 'sold',
            'quantity'       => (int)$it['quantity'],
            'user_id'        => $userId,
            'reason'         => 'Sold to customer',
            'reference_no'   => $invoice,
            'reference_type' => 'order',
            'reference_id'   => $orderId,
        ]);
        $written++;
    }

    return $written;
}

/* =========================================================================
 * 5. Reads for the history page and the reports
 * ====================================================================== */

/**
 * The movement history, filtered.
 *
 * One query for every filter the brief lists (date range, product, brand,
 * movement type, user), so the history page and the reports read the same
 * rows through the same code and cannot disagree.
 *
 * @param array{from?:string,to?:string,product_id?:int,brand?:string,type?:string,user_id?:int,limit?:int,offset?:int} $f
 * @return array{rows:array<int,array<string,mixed>>,total:int}
 */
function inv_movements(PDO $pdo, array $f = []): array
{
    $where  = [];
    $params = [];

    if (!empty($f['from'])) { $where[] = 'DATE(sm.created_at) >= ?'; $params[] = $f['from']; }
    if (!empty($f['to']))   { $where[] = 'DATE(sm.created_at) <= ?'; $params[] = $f['to']; }
    if (!empty($f['product_id'])) { $where[] = 'sm.product_id = ?'; $params[] = (int)$f['product_id']; }
    if (!empty($f['brand']))      { $where[] = 'p.brand = ?';       $params[] = $f['brand']; }
    if (!empty($f['user_id']))    { $where[] = 'sm.user_id = ?';    $params[] = (int)$f['user_id']; }
    if (!empty($f['type']) && isset(INV_MOVEMENT_LABELS[$f['type']])) {
        $where[] = 'sm.movement_type = ?'; $params[] = $f['type'];
    }
    if (!empty($f['search'])) {
        $where[] = '(sm.serial_number LIKE ? OR sm.reference_no LIKE ? OR p.name LIKE ?)';
        $like = '%' . $f['search'] . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM stock_movements sm LEFT JOIN products p ON p.id = sm.product_id $sql"
    );
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $limit  = max(1, min(500, (int)($f['limit'] ?? 100)));
    $offset = max(0, (int)($f['offset'] ?? 0));

    $stmt = $pdo->prepare(
        "SELECT sm.id, sm.created_at, sm.movement_type, sm.quantity, sm.serial_number,
                sm.reason, sm.reference_no, sm.reference_type, sm.reference_id, sm.notes,
                sm.product_id, p.name AS product_name, p.brand,
                sm.user_id,
                TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,''))) AS user_name,
                u.role AS user_role
           FROM stock_movements sm
      LEFT JOIN products p ON p.id = sm.product_id
      LEFT JOIN users u    ON u.id = sm.user_id
           $sql
          ORDER BY sm.created_at DESC, sm.id DESC
          LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
}

/** Distinct brands that actually appear on products, for the filter dropdowns. */
function inv_brands(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand <> '' ORDER BY brand"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Staff who have actually recorded a movement, for the user filter. */
function inv_movement_users(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT u.id, TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,''))) AS name, u.role
           FROM stock_movements sm JOIN users u ON u.id = sm.user_id
          ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Headline counts for the Inventory Control and Reports dashboards. */
function inv_dashboard_counts(PDO $pdo): array
{
    $monthStart = date('Y-m-01');
    $out = [
        'received_this_month'  => 0,
        'defective_this_month' => 0,
        'flagged_items'        => 0,
        'pullouts_pending'     => 0,
        'defective_on_hand'    => 0,
    ];
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_movements
                             WHERE movement_type IN ('stock_in','in','restock') AND DATE(created_at) >= ?");
        $s->execute([$monthStart]);
        $out['received_this_month'] = (int)$s->fetchColumn();

        $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_movements
                             WHERE movement_type IN ('defective','customer_return') AND DATE(created_at) >= ?");
        $s->execute([$monthStart]);
        $out['defective_this_month'] = (int)$s->fetchColumn();

        $out['flagged_items'] = count(inv_flagged_items($pdo));

        $out['pullouts_pending'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM pull_out_requests WHERE status = 'pending'")->fetchColumn();

        $out['defective_on_hand'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM product_units WHERE status = 'defective'")->fetchColumn();
    } catch (Throwable $e) {
        error_log('inv_dashboard_counts: ' . $e->getMessage());
    }
    return $out;
}
