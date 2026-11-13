<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxTemplate.php';

/**
 * Fills Inventory-Template.xlsx with TechWorld's live inventory position.
 *
 * The template (Template.net "Inventory Template") is a four-tab workbook that
 * computes everything from two movement ledgers:
 *
 *   Stock IN  ──┐
 *               ├─► Stock Management  F=SUMIF(Stock IN), G=SUMIF(Stock OUT)
 *   Stock OUT ──┘                     H=F-G, I=IF(H<=0,"Out of Stock","Available")
 *                                               │
 *   Inventory Template  B,D,E,F,G = VLOOKUP ◄───┘   (dashboard + table)
 *
 * Every number in the finished file is produced by the template's OWN
 * formulas. This class types in only the four human-entered columns the
 * template leaves to the user - item name, item number, category, description -
 * plus the two ledgers. Nothing is pre-computed and pasted in as a value, so
 * the workbook adds up the way the designer built it to, and an admin who
 * edits a quantity sees every total follow.
 *
 * HOW THE LEDGERS ARE FILLED
 * --------------------------
 * The template's identity is  AVAILABLE = STOCK IN - STOCK OUT.
 * Inventory Control's identity is  available = on hand - committed
 * (committed = units held by orders in INV_OPEN_STATUSES). They are the same
 * shape, so:
 *
 *   STOCK IN   one opening-balance row per product, dated the export date,
 *              quantity = products.stock (what is physically on hand)
 *   STOCK OUT  one row per open order line, dated that order's own date,
 *              quantity = that line's quantity
 *
 * SUMIF over the Stock OUT rows for a product therefore returns exactly the
 * `committed` figure the page shows, and H = on hand - committed = the page's
 * `available`, for every row. The workbook and the screen cannot disagree.
 *
 * `stock_movements` was the obvious source for the two ledgers and is the
 * wrong one: it is an audit trail of manual adjustments, not a ledger that
 * starts from zero (one row in the whole table at the time of writing), so
 * SUM(in) - SUM(out) over it is nowhere near products.stock. Filling the
 * sheets from it would have produced a workbook whose every total was wrong.
 */
final class InventoryTemplateWorkbook
{
    // ---- Where the template keeps its data ---------------------------------
    // Verified against the shipped file, not guessed. The two "closing" rows
    // (1008 on the dashboard, 1000 on the ledgers) carry the table's bottom
    // border, and on the dashboard rows 1009-1010 are Template.net's own
    // footer/credit - which is why growth inserts ABOVE them (see grow()).
    private const DASH_FIRST = 13;     // 'Inventory Template' first data row
    private const DASH_LAST  = 1008;   // ...and its last, before the footer
    private const LEDG_FIRST = 5;      // 'Stock Management'/'Stock IN'/'Stock OUT'
    private const LEDG_LAST  = 1000;   // ...their closing row
    private const CAPACITY   = 996;    // rows available in each, as shipped
    // The dashboard's VLOOKUPs reach into 'Stock Management' as far as row
    // 1010 - ten rows past where that sheet actually ends. Harmless, but it is
    // a second region-end literal that has to move when the sheets grow.
    private const MGMT_END_IN_DASH = 1010;

    /**
     * @param array<int,array<string,mixed>> $items    rows from Inventory Control:
     *        id, name, category_name, description, stock, committed
     * @param array<int,array<string,mixed>> $outLines open order lines:
     *        product_id, quantity, moved_on ('Y-m-d')
     * @param string $templatePath absolute path to Inventory-Template.xlsx
     * @param string $asOf         'Y-m-d' stamped on the opening-balance rows
     * @return string the finished .xlsx
     */
    public static function build(array $items, array $outLines, string $templatePath, string $asOf): string
    {
        $x = XlsxTemplate::open($templatePath);

        $dash   = $x->sheetPath('Inventory Template');
        $mgmt   = $x->sheetPath('Stock Management');
        $stkIn  = $x->sheetPath('Stock IN');
        $stkOut = $x->sheetPath('Stock OUT');

        // One label per product, guaranteed distinct, because the template
        // keys EVERYTHING on the item name: VLOOKUP from the dashboard and
        // SUMIF from Stock Management both match on it. `products` has no
        // unique index on name, so two SKUs sharing one would quietly make
        // both rows report the first one's stock. The suffix only appears on
        // the collision, so in the normal case names are untouched.
        $labels = [];
        $seen   = [];
        foreach ($items as $it) {
            $name = trim((string)$it['name']);
            if ($name === '') { $name = 'Item ' . (int)$it['id']; }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $name .= ' (' . self::itemNumber((int)$it['id']) . ')';
            }
            $seen[$key] = true;
            $labels[(int)$it['id']] = $name;
        }

        // Stock OUT only carries lines whose product is in this export. A line
        // for a filtered-out (or inactive) product has no Stock Management row
        // to look up, so including it would put #N/A in the sheet - and its
        // quantity would belong to a product the report does not contain.
        $outLines = array_values(array_filter(
            $outLines,
            static fn($l) => isset($labels[(int)$l['product_id']])
        ));

        $need = max(count($items), count($outLines), self::CAPACITY);
        $add  = $need - self::CAPACITY;
        if ($add > 0) {
            self::grow($x, $dash,   self::DASH_LAST, $add, self::dashFixups($add));
            self::grow($x, $mgmt,   self::LEDG_LAST - 1, $add, self::ledgerFixups($add, true));
            self::grow($x, $stkIn,  self::LEDG_LAST - 1, $add, self::ledgerFixups($add, false));
            self::grow($x, $stkOut, self::LEDG_LAST - 1, $add, self::ledgerFixups($add, false));
        }

        self::fillManagement($x, $mgmt, $items, $labels, $add);
        self::fillDashboard($x, $dash, $items, $labels, $add);
        self::fillLedger($x, $stkIn, self::openingRows($items, $labels, $asOf), $add);
        self::fillLedger($x, $stkOut, self::outRows($outLines, $labels), $add);

        $x->forceFullRecalc();
        return $x->toString();
    }

    /** Stable, traceable stand-in for a SKU - `products` has no code column. */
    public static function itemNumber(int $productId): string
    {
        return sprintf('TW-%04d', $productId);
    }

    // ------------------------------------------------------------ ledgers --

    /** One opening-balance row per product: everything on hand, as of today. */
    private static function openingRows(array $items, array $labels, string $asOf): array
    {
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [$asOf, $labels[(int)$it['id']], (int)$it['stock']];
        }
        return $rows;
    }

    /** One row per open order line - real quantities on the order's own date. */
    private static function outRows(array $lines, array $labels): array
    {
        $rows = [];
        foreach ($lines as $l) {
            $rows[] = [(string)$l['moved_on'], $labels[(int)$l['product_id']], (int)$l['quantity']];
        }
        return $rows;
    }

    // ------------------------------------------------------------- filling --

    /** 'Stock Management' B..E - the four typed columns. F..I stay formulas. */
    private static function fillManagement(XlsxTemplate $x, string $part, array $items, array $labels, int $add): void
    {
        $d    = $x->dom($part);
        $last = self::LEDG_LAST + $add;
        $i    = 0;

        for ($r = self::LEDG_FIRST; $r <= $last; $r++) {
            $row = $x->row($d, $r);
            if ($row === null) { continue; }
            $it = $items[$i] ?? null;
            $i++;

            if ($it === null) {
                // Past the data: clear the template's four demo items so no
                // sample row survives into a real report.
                foreach (['B', 'C', 'D', 'E'] as $col) { $x->setCell($row, $col . $r, null); }
            } else {
                $x->setCell($row, 'B' . $r, $labels[(int)$it['id']]);
                $x->setCell($row, 'C' . $r, self::itemNumber((int)$it['id']));
                $x->setCell($row, 'D' . $r, trim((string)($it['category_name'] ?? '')) ?: 'Uncategorized');
                // Falls back to the item name when the catalogue has no
                // description - which is what the template itself does on its
                // own rows 7 and 8, and avoids the 0 that VLOOKUP returns for
                // an empty cell showing up in the Description column on three
                // sheets.
                $x->setCell($row, 'E' . $r, trim((string)($it['description'] ?? '')) ?: $labels[(int)$it['id']]);
            }
            $x->clearFormulaCache($row);
        }
    }

    /** 'Inventory Template' C - the item name. B, D, E, F, G stay VLOOKUPs. */
    private static function fillDashboard(XlsxTemplate $x, string $part, array $items, array $labels, int $add): void
    {
        $d    = $x->dom($part);
        $last = self::DASH_LAST + $add;
        $i    = 0;

        for ($r = self::DASH_FIRST; $r <= $last; $r++) {
            $row = $x->row($d, $r);
            if ($row === null) { continue; }
            $it = $items[$i] ?? null;
            $i++;
            $x->setCell($row, 'C' . $r, $it === null ? null : $labels[(int)$it['id']]);
            $x->clearFormulaCache($row);
        }

        // The dashboard's three tiles (AVAILABLE ITEMS, OUT OF STOCK ITEMS,
        // TOTAL STOCKS AVAILABLE) are COUNTIF/SUM over the table and are left
        // exactly as the template wrote them - but their cached results are
        // the demo file's, so they are dropped with everything else.
        foreach ([6, 8, 10] as $r) {
            $row = $x->row($d, $r);
            if ($row !== null) { $x->clearFormulaCache($row); }
        }
    }

    /** 'Stock IN' / 'Stock OUT': B date, C item name, F quantity. D/E are VLOOKUPs. */
    private static function fillLedger(XlsxTemplate $x, string $part, array $rows, int $add): void
    {
        $d    = $x->dom($part);
        $last = self::LEDG_LAST + $add;
        $i    = 0;

        for ($r = self::LEDG_FIRST; $r <= $last; $r++) {
            $row = $x->row($d, $r);
            if ($row === null) { continue; }
            $entry = $rows[$i] ?? null;
            $i++;

            if ($entry === null) {
                foreach (['B', 'C', 'F'] as $col) { $x->setCell($row, $col . $r, null); }
            } else {
                [$date, $name, $qty] = $entry;
                // The column is already formatted dd-mmm-yyyy by the template,
                // so the cell wants Excel's day serial, not a date string - a
                // string here would left-align as text and break any sort.
                $x->setCell($row, 'B' . $r, self::excelDate($date));
                $x->setCell($row, 'C' . $r, $name);
                $x->setCell($row, 'F' . $r, (int)$qty);
            }
            $x->clearFormulaCache($row);
        }
    }

    /** Days since 1899-12-30, which is what Excel's date serial counts. */
    private static function excelDate(string $ymd): int
    {
        $ts = strtotime($ymd . ' 00:00:00 UTC');
        if ($ts === false) { $ts = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC'); }
        return (int)floor($ts / 86400) + 25569;
    }

    // ------------------------------------------------------------- growth --

    /**
     * Make room for more than the 996 rows the template ships with.
     *
     * Never runs for a catalogue that fits, and that is deliberate: the common
     * path makes no structural change to the package at all, only cell values.
     *
     * Rows are cloned from $pattern and inserted directly after it, so the
     * rows BELOW it - the ledgers' bottom-bordered closing row, and the
     * dashboard's Template.net footer with its merge and two hyperlinks - keep
     * their styling and simply move down. $fixups then repairs the references
     * that named a row by number.
     */
    private static function grow(XlsxTemplate $x, string $part, int $pattern, int $add, array $fixups): void
    {
        $d  = $x->dom($part);
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        /** @var DOMElement $sheetData */
        $sheetData = $d->getElementsByTagNameNS($ns, 'sheetData')->item(0);
        $patternRow = $x->row($d, $pattern);
        if ($patternRow === null) { throw new RuntimeException('Template row ' . $pattern . ' missing in ' . $part); }

        // Everything below the pattern row shifts down by $add. Walk it in
        // reverse so a renumbered row can never collide with one not yet done.
        $below = [];
        foreach ($sheetData->getElementsByTagNameNS($ns, 'row') as $row) {
            /** @var DOMElement $row */
            if ((int)$row->getAttribute('r') > $pattern) { $below[] = $row; }
        }
        foreach (array_reverse($below) as $row) {
            self::renumber($row, (int)$row->getAttribute('r') + $add, $add);
        }

        // Then the new rows, in order, immediately after the pattern.
        $anchor = $patternRow;
        for ($n = 1; $n <= $add; $n++) {
            /** @var DOMElement $clone */
            $clone = $patternRow->cloneNode(true);
            self::renumber($clone, $pattern + $n, $add);
            $anchor = $anchor->nextSibling
                ? $sheetData->insertBefore($clone, $anchor->nextSibling)
                : $sheetData->appendChild($clone);
        }

        // The pattern row itself keeps its number but still refers to region
        // ends that just moved.
        self::renumber($patternRow, $pattern, $add);
        foreach ($sheetData->getElementsByTagNameNS($ns, 'row') as $row) {
            /** @var DOMElement $row */
            $r = (int)$row->getAttribute('r');
            if ($r < $pattern) { self::renumber($row, $r, $add); }
        }

        // DOM is flushed on save; the textual fixups below have to see that
        // flushed XML, so they run against the serialised part.
        $xml = $d->saveXML();
        if ($xml === false) { throw new RuntimeException('Could not serialise ' . $part); }
        foreach ($fixups as $from => $to) { $xml = str_replace($from, $to, $xml); }
        $x->set($part, $xml);
        // Drop the stale DOM so the fill pass re-parses the grown sheet.
        $x->dropDom($part);
    }

    /**
     * Move a row to $newR and repair the row numbers inside it.
     *
     * Three kinds of number appear in these formulas and only these three:
     *   - the formula's own row       IF(C1008="",...)      -> $newR
     *   - a ledger region end, 1000   'Stock IN'!$C$5:$C1000 -> 1000 + $add
     *   - the dashboard's view of the Stock Management end, 1010
     *
     * Handled in ONE pass with a callback, so a number can only be rewritten
     * once - two sequential str_replace passes would renumber 1000 to 1000+add
     * and then, on a row that is itself row 1000, renumber it again.
     */
    private static function renumber(DOMElement $row, int $newR, int $add): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $oldR  = (int)$row->getAttribute('r');
        $row->setAttribute('r', (string)$newR);

        foreach ($row->getElementsByTagNameNS($ns, 'c') as $c) {
            /** @var DOMElement $c */
            if (preg_match('/^([A-Z]+)\d+$/', $c->getAttribute('r'), $m)) {
                $c->setAttribute('r', $m[1] . $newR);
            }
        }
        foreach ($row->getElementsByTagNameNS($ns, 'f') as $f) {
            $f->textContent = (string)preg_replace_callback(
                '/(\$?[A-Z]{1,3}\$?)(\d+)/',
                static function (array $m) use ($oldR, $newR, $add): string {
                    $n = (int)$m[2];
                    if ($n === $oldR)             { return $m[1] . $newR; }
                    if ($n === self::LEDG_LAST)   { return $m[1] . (self::LEDG_LAST + $add); }
                    if ($n === self::MGMT_END_IN_DASH) { return $m[1] . (self::MGMT_END_IN_DASH + $add); }
                    return $m[0];
                },
                $f->textContent
            );
        }
    }

    /** Sheet-level references on 'Inventory Template' that name a row number. */
    private static function dashFixups(int $add): array
    {
        $last   = self::DASH_LAST;              // 1008 - last data row
        $footer = self::DASH_LAST + 1;          // 1009 - Template.net credit row
        $end    = self::MGMT_END_IN_DASH;       // 1010 - sheet end
        return [
            // <dimension>
            'ref="A1:H' . $end . '"' => 'ref="A1:H' . ($end + $add) . '"',
            // the credit row's merge, then its two hyperlinks
            'ref="B' . $footer . ':C' . $footer . '"' => 'ref="B' . ($footer + $add) . ':C' . ($footer + $add) . '"',
            'ref="B' . $footer . '"' => 'ref="B' . ($footer + $add) . '"',
            'ref="D' . $footer . '"' => 'ref="D' . ($footer + $add) . '"',
            // conditional formatting over the Item Number and Status columns
            'sqref="B13:B17 B' . $last . '"' => 'sqref="B13:B17 B' . ($last + $add) . '"',
            'sqref="B18:B' . ($last - 1) . '"' => 'sqref="B18:B' . ($last - 1 + $add) . '"',
            'sqref="G13:G' . $end . '"' => 'sqref="G13:G' . ($end + $add) . '"',
        ];
    }

    /** The same, for the three ledger sheets. */
    private static function ledgerFixups(int $add, bool $hasStatusFormatting): array
    {
        $l = self::LEDG_LAST;
        $fix = [
            'ref="A1:J' . $l . '"' => 'ref="A1:J' . ($l + $add) . '"',
            'ref="A1:I' . $l . '"' => 'ref="A1:I' . ($l + $add) . '"',
            'ref="A1:G' . $l . '"' => 'ref="A1:G' . ($l + $add) . '"',
            'ref="A1:F' . $l . '"' => 'ref="A1:F' . ($l + $add) . '"',
        ];
        if ($hasStatusFormatting) {
            $fix['sqref="I5:I' . $l . '"'] = 'sqref="I5:I' . ($l + $add) . '"';
        }
        return $fix;
    }
}
