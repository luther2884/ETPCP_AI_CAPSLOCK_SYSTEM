<?php
declare(strict_types=1);

/**
 * XlsxTemplate - open a .xlsx, edit cells in place, write it back out.
 *
 * WHY THIS EXISTS AND NOT PhpSpreadsheet
 * --------------------------------------
 * composer.json requires phpoffice/phpspreadsheet and it is installed, but its
 * Xlsx *reader* needs ext-zip (ZipArchive), and this XAMPP has `;extension=zip`
 * commented out in php.ini - verified at runtime, not assumed. So the template
 * cannot be opened with it, and turning the extension on is a change to the
 * server, not to this feature.
 *
 * Rebuilding the workbook from scratch with PhpSpreadsheet's writer (which uses
 * ZipStream and would work) was the alternative, and it is the wrong one: the
 * template carries a theme, 46 cell formats, conditional formatting, a chart,
 * merges, hyperlinks, comments, print setup and an external link. Re-creating
 * all of that by hand would be an imitation of the template, and the brief is
 * that the output follow the template exactly.
 *
 * So this class edits the template IN PLACE. Every part of the package is
 * copied through byte-for-byte except the few sheet XMLs that receive data.
 * Anything not named in an edit is exactly what the designer shipped.
 *
 * ZIP, BY HAND
 * ------------
 * Without ZipArchive the container has to be read and written directly. That is
 * less alarming than it sounds for this case: these are ordinary deflated
 * entries, zlib (gzinflate/gzdeflate) is available, and the format is fixed.
 * Only what xlsx actually uses is implemented - no encryption, no zip64, no
 * multi-disk. Those are rejected loudly rather than mis-parsed.
 */
final class XlsxTemplate
{
    /** @var array<string,string> part name => uncompressed bytes, in package order */
    private array $parts = [];

    /** @var array<string,DOMDocument> lazily parsed XML parts, keyed by part name */
    private array $dom = [];

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private function __construct() {}

    // ---------------------------------------------------------------- read --

    /** @throws RuntimeException if the file is missing, truncated or not a plain zip */
    public static function open(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Template not readable: ' . $path);
        }

        // End of central directory. It is the last structure in the file, but a
        // zip comment may trail it, so scan backwards over the largest comment
        // the format allows (0xFFFF) plus the 22-byte record itself. A match is
        // only accepted when its own comment-length field accounts for exactly
        // the bytes that follow - otherwise the same four bytes occurring
        // inside a deflate stream could be mistaken for the record.
        $len  = strlen($raw);
        $min  = max(0, $len - (0xFFFF + 22));
        $eocd = null;
        for ($i = $len - 22; $i >= $min; $i--) {
            if (substr($raw, $i, 4) !== "PK\x05\x06") { continue; }
            $commentLen = unpack('v', substr($raw, $i + 20, 2))[1];
            if ($i + 22 + $commentLen === $len) { $eocd = $i; break; }
        }
        if ($eocd === null) {
            throw new RuntimeException('Not a zip archive: ' . $path);
        }
        $end = unpack('vdisk/vcdDisk/vdiskEntries/ventries/Vsize/Voffset/vcomment', substr($raw, $eocd + 4, 18));
        if ($end['disk'] !== 0 || $end['cdDisk'] !== 0) {
            throw new RuntimeException('Split zip archives are not supported: ' . $path);
        }

        $self = new self();
        $p = $end['offset'];
        for ($i = 0; $i < $end['entries']; $i++) {
            if (substr($raw, $p, 4) !== "PK\x01\x02") {
                throw new RuntimeException('Corrupt central directory in ' . $path);
            }
            $cd = unpack(
                'vmadeBy/vextract/vflag/vmethod/vmTime/vmDate/Vcrc/VcSize/VuSize/vnameLen/vextraLen/vcommentLen/vdiskNo/vintAttr/VextAttr/VlocalOffset',
                substr($raw, $p + 4, 42)
            );
            $name = substr($raw, $p + 46, $cd['nameLen']);
            $p += 46 + $cd['nameLen'] + $cd['extraLen'] + $cd['commentLen'];

            if ($cd['flag'] & 0x0001) {
                throw new RuntimeException('Encrypted zip entry: ' . $name);
            }
            if ($cd['cSize'] === 0xFFFFFFFF || $cd['uSize'] === 0xFFFFFFFF) {
                throw new RuntimeException('Zip64 entries are not supported: ' . $name);
            }

            // Directory entries carry no data and xlsx does not need them.
            if ($name === '' || substr($name, -1) === '/') { continue; }

            // The local header's extra field is frequently a DIFFERENT length
            // from the central directory's, so the data offset has to come from
            // the local header itself - not from the central directory's copy.
            $lo = $cd['localOffset'];
            if (substr($raw, $lo, 4) !== "PK\x03\x04") {
                throw new RuntimeException('Corrupt local header for ' . $name);
            }
            $lh = unpack('vnameLen/vextraLen', substr($raw, $lo + 26, 4));
            $data = substr($raw, $lo + 30 + $lh['nameLen'] + $lh['extraLen'], $cd['cSize']);

            if ($cd['method'] === 8) {
                $out = @gzinflate($data);
                if ($out === false) { throw new RuntimeException('Could not inflate ' . $name); }
            } elseif ($cd['method'] === 0) {
                $out = $data;
            } else {
                throw new RuntimeException('Unsupported compression method ' . $cd['method'] . ' for ' . $name);
            }
            if (strlen($out) !== $cd['uSize'] || crc32($out) !== $cd['crc']) {
                throw new RuntimeException('Checksum mismatch on ' . $name);
            }
            $self->parts[$name] = $out;
        }

        if (!isset($self->parts['xl/workbook.xml'])) {
            throw new RuntimeException('Not an xlsx workbook: ' . $path);
        }
        return $self;
    }

    // ----------------------------------------------------------- raw parts --

    public function has(string $part): bool { return isset($this->parts[$part]); }

    public function get(string $part): string
    {
        if (!isset($this->parts[$part])) { throw new RuntimeException('No such part: ' . $part); }
        return $this->parts[$part];
    }

    public function set(string $part, string $bytes): void { $this->parts[$part] = $bytes; }

    /** Drops a part and every reference to it, so the package stays consistent. */
    public function remove(string $part): void
    {
        unset($this->parts[$part], $this->dom[$part]);

        // [Content_Types].xml override.
        $ct = $this->parts['[Content_Types].xml'] ?? null;
        if ($ct !== null) {
            $this->parts['[Content_Types].xml'] = preg_replace(
                '#<Override\s+PartName="/' . preg_quote($part, '#') . '"[^>]*/>#',
                '',
                $ct
            ) ?? $ct;
        }

        // Any relationship pointing at it, wherever that relationship lives.
        //
        // Guessing the owning _rels from the part's own path does not work:
        // xl/calcChain.xml is declared in xl/_rels/workbook.xml.rels, named
        // after the part that REFERENCES it, not after the part or its folder.
        // So every .rels in the package is checked, and each Target is
        // resolved against its own base directory before comparing. A
        // relationship left pointing at a part that is gone is exactly the
        // kind of dangling reference Excel reports as a damaged file.
        foreach ($this->parts as $name => $xml) {
            if (substr($name, -5) !== '.rels') { continue; }
            $base = dirname(dirname($name));           // xl/_rels/x.rels -> xl
            $base = ($base === '.' || $base === '') ? '' : $base . '/';
            $this->parts[$name] = (string)preg_replace_callback(
                '#<Relationship\b[^>]*/>#',
                static function (array $m) use ($base, $part): string {
                    if (!preg_match('#\sTarget="([^"]+)"#', $m[0], $t)) { return $m[0]; }
                    if (preg_match('#\sTargetMode="External"#', $m[0]))  { return $m[0]; }
                    $target = $t[1];
                    $resolved = $target[0] === '/' ? ltrim($target, '/') : $base . $target;
                    // Collapse ../ so ../drawings/d.xml from xl/worksheets
                    // compares equal to xl/drawings/d.xml.
                    while (strpos($resolved, '../') !== false) {
                        $resolved = (string)preg_replace('#[^/]+/\.\./#', '', $resolved, 1);
                    }
                    return $resolved === $part ? '' : $m[0];
                },
                $xml
            );
        }
    }

    // ----------------------------------------------------------------- DOM --

    /** Parsed view of an XML part. Mutations are flushed by save()/output(). */
    public function dom(string $part): DOMDocument
    {
        if (!isset($this->dom[$part])) {
            $d = new DOMDocument();
            $d->preserveWhiteSpace = false;   // sheets ship as one line anyway
            $d->formatOutput = false;
            if (!$d->loadXML($this->get($part), LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Could not parse ' . $part);
            }
            $this->dom[$part] = $d;
        }
        return $this->dom[$part];
    }

    /**
     * Forget the parsed copy of a part, so the next dom() re-reads the bytes.
     * Needed after a caller has serialised a DOM itself and written the result
     * back with set() - without this the stale DOM would be flushed over it.
     */
    public function dropDom(string $part): void
    {
        if (isset($this->dom[$part])) { unset($this->rowIndex[spl_object_id($this->dom[$part])]); }
        unset($this->dom[$part]);
    }

    private function flush(): void
    {
        foreach ($this->dom as $part => $d) {
            $xml = $d->saveXML();
            if ($xml === false) { throw new RuntimeException('Could not serialise ' . $part); }
            $this->parts[$part] = $xml;
        }
        $this->dom = [];
        $this->rowIndex = [];
    }

    /**
     * Part path of a worksheet, looked up by the tab name the user sees.
     *
     * Resolved through workbook.xml -> workbook.xml.rels rather than assuming
     * "Stock IN" is worksheets/sheet4.xml. The two only line up by coincidence
     * of how this file was authored, and a template re-saved with the tabs in
     * a different order would otherwise write Stock IN data into Stock OUT -
     * silently, and with every total still looking plausible.
     */
    public function sheetPath(string $tabName): string
    {
        $wb = $this->get('xl/workbook.xml');
        if (!preg_match('#<sheet\b[^>]*\bname="' . preg_quote(htmlspecialchars($tabName, ENT_XML1), '#') . '"[^>]*\br:id="([^"]+)"#', $wb, $m)) {
            throw new RuntimeException('Template has no sheet named "' . $tabName . '"');
        }
        $rels = $this->get('xl/_rels/workbook.xml.rels');
        if (!preg_match('#<Relationship\b[^>]*\bId="' . preg_quote($m[1], '#') . '"[^>]*\bTarget="([^"]+)"#', $rels, $t)) {
            throw new RuntimeException('Template sheet "' . $tabName . '" has no relationship target');
        }
        $target = ltrim($t[1], '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    // -------------------------------------------------------- sheet helpers --

    /** @var array<int,array<int,DOMElement>> row number => element, per sheet DOM */
    private array $rowIndex = [];

    /**
     * The <row> with this number, or null.
     *
     * Indexed on first use and cached per document. The obvious version -
     * getElementsByTagNameNS('row') and compare @r - is a live NodeList
     * rebuilt on every call, and these sheets carry a thousand rows each: one
     * export walked ~4 million nodes and took nearly two minutes. The index
     * makes the same export take about a second.
     */
    public function row(DOMDocument $sheet, int $r): ?DOMElement
    {
        $key = spl_object_id($sheet);
        if (!isset($this->rowIndex[$key])) {
            $map = [];
            $data = $sheet->getElementsByTagNameNS(self::NS, 'sheetData')->item(0);
            if ($data !== null) {
                for ($n = $data->firstChild; $n !== null; $n = $n->nextSibling) {
                    if ($n instanceof DOMElement && $n->localName === 'row') {
                        $map[(int)$n->getAttribute('r')] = $n;
                    }
                }
            }
            $this->rowIndex[$key] = $map;
        }
        return $this->rowIndex[$key][$r] ?? null;
    }

    /** The <c> at this reference inside a row, or null. A row holds ~10 cells. */
    public function cell(DOMElement $row, string $ref): ?DOMElement
    {
        for ($n = $row->firstChild; $n !== null; $n = $n->nextSibling) {
            if ($n instanceof DOMElement && $n->localName === 'c' && $n->getAttribute('r') === $ref) {
                return $n;
            }
        }
        return null;
    }

    /**
     * Replace a cell's content, keeping its style (`s`) and therefore its
     * border, fill, font, alignment and number format - which is the whole
     * point of filling a template rather than generating a sheet.
     *
     * $value: string => inline string, int|float => number, null => blank.
     *
     * Inline strings (t="inlineStr") rather than entries in sharedStrings.xml:
     * the shared string table is referenced by numeric index from five sheets,
     * so appending to it means every index stays valid but the file grows a
     * second, parallel way of saying the same thing. Inline is self-contained,
     * is part of the same spec version Excel already reads here, and leaves
     * sharedStrings.xml byte-identical to the template.
     */
    public function setCell(DOMElement $row, string $ref, $value): void
    {
        $c = $this->cell($row, $ref);
        if ($c === null) {
            // The template pre-styles every cell in its data region, so a
            // missing one means the reference is wrong - fail rather than
            // silently write an unstyled cell into the middle of the table.
            throw new RuntimeException('Template has no cell ' . $ref . ' in row ' . $row->getAttribute('r'));
        }
        while ($c->firstChild) { $c->removeChild($c->firstChild); }
        $c->removeAttribute('t');

        if ($value === null || $value === '') { return; }

        $doc = $c->ownerDocument;
        if (is_int($value) || is_float($value)) {
            $v = $doc->createElementNS(self::NS, 'v');
            $v->appendChild($doc->createTextNode(
                is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (string)$value
            ));
            $c->appendChild($v);
            return;
        }

        // XML 1.0 has no way to encode most control characters, not even as a
        // numeric entity - a single stray 0x01 in a product description would
        // make the whole package unopenable. Product text is free-form and
        // arrives from imports and paste, so it is filtered rather than
        // trusted. Tab, newline and carriage return are the three that are
        // legal and meaningful, and they survive.
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        if ($value === null || $value === '') { return; }   // null = invalid UTF-8

        $c->setAttribute('t', 'inlineStr');
        $is = $doc->createElementNS(self::NS, 'is');
        $t  = $doc->createElementNS(self::NS, 't');
        // xml:space, or Excel trims a value that legitimately starts or ends
        // with a space (product names are TRIMmed upstream, but descriptions
        // are free text typed by an admin).
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($doc->createTextNode($value));
        $is->appendChild($t);
        $c->appendChild($is);
    }

    /**
     * Drop the cached result from every formula cell in a row.
     *
     * The template ships each formula with the value it last evaluated to for
     * the demo data ("IT-50-1001", 100, "Available"...). Those caches are what
     * a reader shows before it recalculates, so leaving them means the file
     * briefly - or, in a viewer that does not calculate at all, permanently -
     * displays the template's sample numbers next to the real ones.
     */
    public function clearFormulaCache(DOMElement $row): void
    {
        for ($c = $row->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!($c instanceof DOMElement) || $c->localName !== 'c') { continue; }
            $hasFormula = false;
            $cached = [];
            for ($n = $c->firstChild; $n !== null; $n = $n->nextSibling) {
                if (!($n instanceof DOMElement)) { continue; }
                if ($n->localName === 'f') { $hasFormula = true; }
                elseif ($n->localName === 'v') { $cached[] = $n; }
            }
            if (!$hasFormula) { continue; }
            foreach ($cached as $v) { $c->removeChild($v); }
            // t="str" describes the cached value, not the formula; with the
            // cache gone it would claim a string result for =F5-G5.
            if ($c->getAttribute('t') === 'str') { $c->removeAttribute('t'); }
        }
    }

    /**
     * Tell Excel to recalculate everything on open.
     *
     * Required, not cosmetic: every number this export produces comes out of
     * the template's own VLOOKUP/SUMIF formulas, and with no cached values left
     * (see clearFormulaCache) the workbook has nothing to show until it
     * calculates. Also removes <fileRecoveryPr repairLoad="1"/>, which the
     * template carries from whatever repaired it and which asks Excel to treat
     * every copy as a damaged file.
     */
    public function forceFullRecalc(): void
    {
        $wb = $this->get('xl/workbook.xml');
        $wb = str_replace('<fileRecoveryPr repairLoad="1"/>', '', $wb);
        if (preg_match('#<calcPr\b[^>]*/>#', $wb, $m)) {
            $tag = $m[0];
            $new = preg_replace('#\s*/>$#', '', $tag);
            foreach (['fullCalcOnLoad' => '1', 'forceFullCalc' => '1'] as $attr => $val) {
                $new = preg_replace('#\s+' . $attr . '="[^"]*"#', '', (string)$new);
                $new .= ' ' . $attr . '="' . $val . '"';
            }
            $wb = str_replace($tag, $new . '/>', $wb);
        } else {
            $wb = str_replace('</workbook>', '<calcPr fullCalcOnLoad="1" forceFullCalc="1"/></workbook>', $wb);
        }
        $this->set('xl/workbook.xml', $wb);

        // calcChain.xml is a cache of the order in which to evaluate formulas.
        // It is optional, Excel rebuilds it, and a stale one that disagrees
        // with the sheets is the usual cause of "we found a problem with some
        // content" on open - so it goes rather than being maintained.
        if ($this->has('xl/calcChain.xml')) { $this->remove('xl/calcChain.xml'); }
    }

    // --------------------------------------------------------------- write --

    /** The finished .xlsx as a byte string. */
    public function toString(): string
    {
        $this->flush();

        $now  = getdate();
        $time = ($now['hours'] << 11) | ($now['minutes'] << 5) | ($now['seconds'] >> 1);
        $date = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

        $local = '';
        $central = '';
        foreach ($this->parts as $name => $data) {
            $crc   = crc32($data);
            $uSize = strlen($data);

            // gzdeflate emits a raw deflate stream, which is exactly what zip
            // method 8 stores. Entries that do not shrink are stored instead,
            // so a tiny part never comes out larger than it went in.
            $comp   = (string)gzdeflate($data, 9);
            $method = 8;
            if ($comp === '' || strlen($comp) >= $uSize) { $comp = $data; $method = 0; }
            $cSize  = strlen($comp);

            $offset = strlen($local);
            $local .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, $method, $time, $date, $crc, $cSize, $uSize, strlen($name), 0)
                    . $name . $comp;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $method, $time, $date, $crc, $cSize, $uSize, strlen($name), 0, 0, 0, 0, 0, $offset)
                      . $name;
        }

        return $local . $central
             . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($this->parts), count($this->parts), strlen($central), strlen($local), 0);
    }
}
