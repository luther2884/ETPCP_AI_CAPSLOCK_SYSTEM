<?php
/**
 * config/invoice.php - the printed customer invoice, as data.
 *
 * One builder for every queue that prints one. store_pickup_orders.php and
 * Home_Delivery_Orders.php print the same document and differ only in who the
 * goods go to, so the difference is a parameter here rather than a second copy
 * of the template: a change to the money, the escaping or the one-page budget
 * lands on both queues at once.
 *
 * The markup, CSS and print mechanism live in
 * public/authenticated/admin/includes/order_invoice.php, which consumes what
 * these functions return.
 *
 * Company details, tax status and the policy boilerplate come from
 * config/business_info.php.
 */

require_once __DIR__ . '/business_info.php';

if (!function_exists('tw_invoice_store')) {
    /**
     * Seller identity and boilerplate. Identical for every order on a page,
     * so it is built once and shipped once.
     *
     * @return array<string,mixed>
     */
    function tw_invoice_store(): array
    {
        $biz = function_exists('business_info') ? business_info() : [];

        return [
            'name'      => (string)($biz['legal_name'] ?? $biz['name'] ?? 'TechWorld Computer Store'),
            'address'   => (string)($biz['address'] ?? ''),
            'phone'     => (string)($biz['phone'] ?? ''),
            'email'     => (string)($biz['email'] ?? ''),
            'tin'       => (string)($biz['tin'] ?? ''),
            'terms'     => array_values((array)($biz['invoice_terms'] ?? [])),
            'policy'    => array_values((array)($biz['invoice_policy'] ?? [])),
            // Who printed it. The signature line stays blank to be signed by
            // hand; this names the terminal the document came off.
            'issued_by' => trim((string)($_SESSION['firstname'] ?? '') . ' ' . (string)($_SESSION['lastname'] ?? ''))
                             ?: (string)($_SESSION['username'] ?? ''),
        ];
    }
}

if (!function_exists('tw_invoice_lines')) {
    /**
     * Product lines for one order.
     *
     * Reads oi_unit_price / oi_line_total when the caller's query provides
     * them - the price the customer was actually charged. Both admin queues
     * select `oi.*, p.price`, and PDO's associative fetch lets that bare
     * p.price overwrite oi.price in the row, so the unaliased `price` key is
     * TODAY'S catalogue price. Reprinting an older order off that key produced
     * an invoice that disagreed with what was paid.
     *
     * @param  array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    function tw_invoice_lines(array $items): array
    {
        return array_map(static function ($it) {
            $qty  = (int)($it['quantity'] ?? 0);
            $unit = (float)($it['oi_unit_price'] ?? $it['price'] ?? 0);
            $line = (float)($it['oi_line_total'] ?? ($unit * $qty));
            return [
                'name'     => (string)($it['product_name'] ?? 'Item'),
                'quantity' => $qty,
                // Serial numbers of the physical units that went out on this
                // line, printed under the product name. This is what makes the
                // invoice itself proof of which unit the customer received -
                // without it the only record is in the database, and the
                // customer has no copy of it.
                //
                // Read from a key the CALLER attaches (the order queues do it
                // from pu_units_for_order()) rather than queried here: this
                // function is a pure mapper over the rows it is handed, and
                // the queues already have the whole order in memory.
                // Untracked products simply have no such key and print as
                // they always did.
                'serials'  => array_values(array_filter(array_map(
                    static fn($s) => trim((string)$s),
                    (array)($it['serials'] ?? [])
                ), static fn($s) => $s !== '')),
                'price'    => $unit,
                // Anything the line sold for below unit x qty. Normally zero;
                // the column exists so a per-line deduction cannot hide inside
                // the order total.
                'discount' => max(0.0, ($unit * $qty) - $line),
                'total'    => $line,
            ];
        }, $items);
    }
}

if (!function_exists('tw_invoice_totals')) {
    /**
     * The totals column, derived so that it always adds up as printed.
     *
     * Only two numbers here are stored facts: the sum of the line totals, and
     * the order's own total_amount. Everything between them is the gap, and
     * the gap is derived rather than read from a column:
     *
     *  - Gap POSITIVE (total above subtotal) - the order was charged more than
     *    its goods. Every such order in this database is exactly subtotal x
     *    1.12, i.e. 12% VAT added on top, so when the gap matches the
     *    configured rate it is labelled VAT and otherwise "Other Charges"
     *    rather than claiming a tax figure that does not check out.
     *
     *  - Gap NEGATIVE (total below subtotal) - something was taken off. The
     *    stored credit_applied is NOT used for the figure, only for the label:
     *    live data has it overshooting, a PHP1.00 order carrying PHP1.12 of
     *    credit and settling at PHP0.00, and printing that hands the customer
     *    three numbers that do not subtract.
     *
     * Deriving both directions means subtotal + charges - deductions = TOTAL
     * for every order, whatever the columns say. Before this, the 72 orders
     * priced at subtotal x 1.12 printed an invoice whose own column did not
     * reconcile: PHP550.00 of goods, no charges shown, PHP616.00 due.
     *
     * @param  array<int,array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    function tw_invoice_totals(array $lines, float $total, float $creditApplied): array
    {
        $subtotal = 0.0;
        foreach ($lines as $l) { $subtotal += (float)$l['total']; }

        $gap     = round($total - $subtotal, 2);
        $biz     = function_exists('business_info') ? business_info() : [];
        $vatRate = $biz['vat_rate'] ?? null;

        $vat        = null;
        $vatLabel   = null;
        $otherCharge = 0.0;
        $deduction  = 0.0;
        $dedLabel   = 'Discount';

        if ($gap > 0.004) {
            // Charged above the goods. Call it VAT only if it actually is the
            // configured rate on the subtotal - within a centavo, since the
            // stored total is rounded money.
            if ($vatRate !== null && $vatRate > 0
                && abs($gap - round($subtotal * (float)$vatRate, 2)) < 0.011) {
                $vat      = $gap;
                $vatLabel = 'VAT (' . rtrim(rtrim(number_format((float)$vatRate * 100, 2, '.', ''), '0'), '.') . '%)';
            } else {
                $otherCharge = $gap;
            }
        } elseif ($gap < -0.004) {
            $deduction = -$gap;
            $dedLabel  = $creditApplied > 0 ? 'Store Credit Applied' : 'Discount';
        }

        return [
            'subtotal'        => $subtotal,
            'vat'             => $vat,
            'vat_label'       => $vatLabel,
            'other_charge'    => $otherCharge,
            'deduction'       => $deduction,
            'deduction_label' => $dedLabel,
            'total'           => $total,
        ];
    }
}

if (!function_exists('tw_invoice_payload')) {
    /**
     * Build the per-order invoice payload for a page of orders.
     *
     * @param array<int,array<string,mixed>> $orders Rows carrying an 'items' key
     * @param string $mode 'pickup' or 'delivery' - decides the second address
     *                     panel only; everything else is identical
     * @return array<int,array<string,mixed>> keyed by order id
     */
    function tw_invoice_payload(array $orders, string $mode = 'pickup'): array
    {
        $store = tw_invoice_store();
        $out   = [];

        foreach ($orders as $order) {
            $lines  = tw_invoice_lines((array)($order['items'] ?? []));
            $totals = tw_invoice_totals(
                $lines,
                (float)($order['total_amount'] ?? 0),
                (float)($order['credit_applied'] ?? 0)
            );

            // payment_status is the payment record's own word; the order
            // status is about fulfilment and answers a different question.
            $payStatus = (string)($order['payment_status'] ?? '');
            if ($payStatus === '') { $payStatus = (string)($order['epay_status'] ?? ''); }
            if ($payStatus === '') { $payStatus = 'pending'; }

            /* The one real difference between the two queues. A pickup invoice
               names the counter the customer collects from; a delivery invoice
               names the person and address the goods go to. Printing a
               "Delivery Address" on a pickup sheet - or the store's own
               address on a delivery one - would be wrong on every row. */
            if ($mode === 'delivery') {
                $recipient = trim((string)($order['delivery_name'] ?? ''));
                if ($recipient === '') {
                    $recipient = trim((string)($order['firstname'] ?? '') . ' ' . (string)($order['lastname'] ?? ''));
                }
                $fulfilLines = [];
                $phone = trim((string)($order['delivery_phone'] ?? ''));
                if ($phone !== '') { $fulfilLines[] = $phone; }
                $addr = trim((string)($order['address'] ?? ''));
                if ($addr !== '') { $fulfilLines[] = $addr; }

                $fulfilLabel = 'Deliver To';
                $fulfilName  = $recipient !== '' ? $recipient : 'Recipient on file';
                $fulfilNote  = 'Home delivery — please have a valid ID ready on handover.';
                if (!empty($order['assigned_driver_name'])) {
                    $fulfilNote = 'Home delivery — rider: ' . trim((string)$order['assigned_driver_name'])
                                . '. Please have a valid ID ready on handover.';
                }
            } else {
                $fulfilLabel = 'Collect At';
                $fulfilName  = $store['name'];
                $fulfilLines = $store['address'] !== '' ? [$store['address']] : [];
                $fulfilNote  = 'Store pickup — bring this invoice and a valid ID.';
            }

            $out[(int)$order['id']] = [
                'invoice_no'      => (string)($order['invoice_no'] ?? ('#' . (int)$order['id'])),
                'order_no'        => 'ORD-' . str_pad((string)(int)$order['id'], 5, '0', STR_PAD_LEFT),
                'customer_name'   => trim((string)($order['firstname'] ?? '') . ' ' . (string)($order['lastname'] ?? '')),
                'customer_email'  => (string)($order['email'] ?? ''),
                'billing_address' => (string)($order['address'] ?? ''),
                'created_at'      => date('F j, Y', strtotime((string)$order['created_at'])),
                'payment_method'  => (string)($order['payment_method_display'] ?? '') !== ''
                                       ? (string)$order['payment_method_display']
                                       : (function_exists('getPaymentMethodLabel')
                                           ? getPaymentMethodLabel((string)($order['payment_method'] ?? 'N/A'))
                                           : (string)($order['payment_method'] ?? 'N/A')),
                'payment_status'  => strtoupper($payStatus),
                'payment_ref'     => (string)($order['payment_reference'] ?? $order['epay_transaction_id'] ?? ''),
                'paid_at'         => !empty($order['paid_at'])
                                       ? date('M j, Y g:i A', strtotime((string)$order['paid_at'])) : '',
                'status'          => strtoupper((string)($order['status'] ?? '')),
                'tracking_code'   => (string)($order['tracking_code'] ?? ''),

                'fulfil_label'    => $fulfilLabel,
                'fulfil_name'     => $fulfilName,
                'fulfil_lines'    => $fulfilLines,
                'fulfil_note'     => $fulfilNote,

                'subtotal'        => $totals['subtotal'],
                'vat'             => $totals['vat'],
                'vat_label'       => $totals['vat_label'],
                'other_charge'    => $totals['other_charge'],
                'deduction'       => $totals['deduction'],
                'deduction_label' => $totals['deduction_label'],
                'total_amount'    => $totals['total'],
                'items'           => $lines,
            ];
        }

        return $out;
    }
}
