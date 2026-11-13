<?php
declare(strict_types=1);
/**
 * config/product_validation.php - the rules for product prices and quantities.
 *
 * WHY THIS EXISTS
 *
 * The database cannot be relied on to reject bad numbers. It runs without
 * STRICT_TRANS_TABLES:
 *
 *     @@sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION
 *
 * so writing 999999999999.99 into `price DECIMAL(10,2)` raises warning 1264 and
 * then stores 99999999.99 anyway. The same happens to `stock INT`: 99999999999
 * lands as 2147483647. Nothing surfaces; a mistyped price becomes a wrong price
 * that looks deliberate. Every guard against that has to live here, in PHP,
 * before the write.
 *
 * TWO STRENGTHS OF REJECTION
 *
 *   error   - the value is impossible, or the column physically cannot hold it.
 *             The save is blocked outright.
 *   warning - the value is possible but looks like a typo. The save is blocked
 *             only until the person confirms they meant it. Never a hard limit,
 *             so a genuinely expensive product or a genuinely large delivery is
 *             always able to get through.
 *
 * Both are returned, never thrown, so a caller can collect every problem on a
 * form in one pass instead of stopping at the first.
 *
 * Nothing in this file touches the database, the session or $_POST - it is a
 * pure function of its arguments, which is what makes it testable from the CLI
 * (see the harness described in the plan) and reusable by add, edit and the
 * AJAX price/stock endpoints alike.
 */

/** Largest value `price DECIMAL(10,2)` can hold. Above this MySQL clamps. */
const PV_PRICE_MAX = 99999999.99;

/** Largest value `stock INT(11)` can hold. Above this MySQL clamps. */
const PV_STOCK_MAX = 2147483647;

/**
 * Above this a price is questioned, not refused.
 *
 * Roughly 8x the dearest product in the catalogue (PHP 62,995 at the time of
 * writing), so a real high-end workstation passes without comment, while an
 * extra zero typed onto any existing product - 62,995 becoming 629,950 - is
 * caught.
 */
const PV_PRICE_WARN = 500000.0;

/** Above this a quantity is questioned, not refused. */
const PV_QTY_WARN = 10000;

/** Fallback reference price for the "Nx your dearest product" wording, used
 *  when the caller does not supply the catalogue's real maximum. */
const PV_PRICE_REFERENCE = 62995.0;

/** Peso amounts, formatted the same way in every message. */
function pv_peso($n): string
{
    return "\u{20B1}" . number_format((float)$n, 2);
}

/** The shape every check below returns. */
function pv_result(bool $ok, $value, ?string $error = null, ?string $warning = null): array
{
    return ['ok' => $ok, 'value' => $value, 'error' => $error, 'warning' => $warning];
}

/**
 * Is this string an integer, written plainly?
 *
 * Deliberately stricter than is_numeric(): "12.9" must NOT pass. The old code
 * cast it with (int), silently turning a typed 12.9 into 12, and "abc" into 0.
 * Both are cases where the person sees a number they did not enter.
 */
function pv_is_plain_integer(string $s): bool
{
    return (bool)preg_match('/^[+-]?\d+$/', $s);
}

/**
 * Validate a price.
 *
 * @param mixed $raw     whatever arrived from the form
 * @param float $refMax  dearest product in the catalogue, for the warning text
 * @return array{ok:bool,value:string,error:?string,warning:?string}
 *         value is normalised to a 2-decimal string ready for the column.
 */
function pv_price($raw, float $refMax = PV_PRICE_REFERENCE): array
{
    $s = trim((string)$raw);

    if ($s === '') {
        return pv_result(false, '', 'Enter a price, for example 1250 or 1250.50.');
    }
    if (!is_numeric($s)) {
        return pv_result(false, $s, 'That is not a number. Enter a price like 1250 or 1250.50.');
    }

    $v = (float)$s;

    if ($v < 0) {
        return pv_result(false, $s, 'Price cannot be negative.');
    }
    // The field ships defaulted to 0.00, so an untouched form is the likeliest
    // way a product ends up free. Nothing in the catalogue is.
    if ($v == 0.0) {
        return pv_result(false, $s, "Price must be more than \u{20B1}0.");
    }

    // Checked on the typed text, not the float: 1250.555 must be refused rather
    // than quietly rounded to 1250.56 on the way into DECIMAL(10,2).
    if (strpos($s, '.') !== false) {
        $decimals = strlen(substr($s, strpos($s, '.') + 1));
        if ($decimals > 2) {
            return pv_result(false, $s, 'Price can have at most 2 decimal places, for example 1250.50.');
        }
    }

    // The clamp guard. Without this the row is written with a different number
    // than the one submitted.
    if ($v > PV_PRICE_MAX) {
        return pv_result(false, $s, 'That price is too large to store. The most this system can hold is ' . pv_peso(PV_PRICE_MAX) . '.');
    }

    $normalised = number_format($v, 2, '.', '');

    if ($v > PV_PRICE_WARN) {
        $refMax = $refMax > 0 ? $refMax : PV_PRICE_REFERENCE;
        $times  = $v / $refMax;
        $warning = 'That is ' . pv_peso($v) . ' — about ' . number_format($times, 0)
                 . "\u{00D7} your most expensive product (" . pv_peso($refMax) . ').';
        return pv_result(true, $normalised, null, $warning);
    }

    return pv_result(true, $normalised);
}

/**
 * Validate an absolute stock figure ("set stock to exactly this").
 *
 * @return array{ok:bool,value:int,error:?string,warning:?string}
 */
function pv_stock($raw): array
{
    $s = trim((string)$raw);

    if ($s === '') {
        return pv_result(false, 0, 'Enter a stock quantity, or 0 if there is none yet.');
    }
    if (!pv_is_plain_integer($s)) {
        return pv_result(false, 0, 'Stock must be a whole number of units, for example 25.');
    }

    $v = (int)$s;

    if ($v < 0) {
        return pv_result(false, $v, 'Stock cannot be negative.');
    }
    // Compared as a string against the limit's digits, because (int) on a value
    // past PHP_INT_MAX saturates and would sneak past a plain > comparison.
    if (strlen(ltrim($s, '+')) > strlen((string)PV_STOCK_MAX) || $v > PV_STOCK_MAX) {
        return pv_result(false, $v, 'That stock quantity is too large to store. The most this system can hold is ' . number_format(PV_STOCK_MAX) . '.');
    }

    if ($v > PV_QTY_WARN) {
        return pv_result(true, $v, null, number_format($v) . ' units is far more than this shop normally holds.');
    }

    return pv_result(true, $v);
}

/**
 * Validate a restock quantity - the number of units being ADDED.
 *
 * Takes the current stock so it can refuse a delta that would overflow the
 * column, and so the "that looks like a lot" warning can be relative to what
 * the product already holds.
 *
 * @return array{ok:bool,value:int,error:?string,warning:?string}
 */
function pv_restock_qty($raw, int $currentStock = 0): array
{
    $s = trim((string)$raw);

    if ($s === '') {
        return pv_result(false, 0, 'Enter how many units you are adding.');
    }
    if (!pv_is_plain_integer($s)) {
        return pv_result(false, 0, 'Enter a whole number of units, for example 25.');
    }

    $v = (int)$s;

    if ($v < 1) {
        return pv_result(false, $v, 'Enter at least 1 unit. To reduce stock, use "Set exact" instead.');
    }
    if (strlen(ltrim($s, '+')) > strlen((string)PV_STOCK_MAX)
        || $v > PV_STOCK_MAX
        || $v > PV_STOCK_MAX - max(0, $currentStock)) {
        return pv_result(false, $v, 'That would take total stock past what this system can hold (' . number_format(PV_STOCK_MAX) . ').');
    }

    // Relative check first - it is the one that catches a trailing zero on a
    // normal-sized delivery, which the absolute threshold would miss entirely.
    if ($currentStock > 0 && $v > $currentStock * 20 && $v > 100) {
        return pv_result(true, $v, null, 'Adding ' . number_format($v) . ' units to a product that currently has '
            . number_format($currentStock) . '.');
    }
    if ($v > PV_QTY_WARN) {
        return pv_result(true, $v, null, 'Adding ' . number_format($v) . ' units at once is far more than this shop normally receives.');
    }

    return pv_result(true, $v);
}

/**
 * Fold a set of pv_* results into errors, plus the warnings still awaiting
 * confirmation.
 *
 * A warning becomes an error when its confirm box was not ticked, so the
 * confirmation is enforced on the server. The browser-side copy of the same
 * rule only saves a round trip; it is not what makes it true.
 *
 * @param array $checks   field name => pv_* result
 * @param array $confirms $_POST, read for "confirm_<field>"
 * @return array{errors:array<string,string>,warnings:array<string,string>,values:array<string,mixed>}
 */
function pv_collect(array $checks, array $confirms): array
{
    $errors = [];
    $warnings = [];
    $values = [];

    foreach ($checks as $field => $r) {
        $values[$field] = $r['value'];

        if (!empty($r['error'])) {
            $errors[$field] = $r['error'];
            continue;
        }
        if (!empty($r['warning'])) {
            $warnings[$field] = $r['warning'];
            if (empty($confirms['confirm_' . $field])) {
                $errors[$field] = $r['warning'] . ' Tick the box below to confirm it is correct.';
            }
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'values' => $values];
}
