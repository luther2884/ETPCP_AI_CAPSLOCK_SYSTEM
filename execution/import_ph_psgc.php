<?php
declare(strict_types=1);
/**
 * execution/import_ph_psgc.php - Loads the official PSGC location list into
 * ph_cities / ph_barangays (see DBSQL/add_ph_psgc_locations.sql).
 *
 * Usage:
 *   php execution/import_ph_psgc.php                 # download from PSGC
 *   php execution/import_ph_psgc.php path/to/json    # use a local dump dir
 *                                                    # (regions.json, provinces.json,
 *                                                    #  cities.json, brgys.json)
 *
 * Idempotent: rows are REPLACEd by PSGC code, so re-running refreshes the data
 * without duplicating it. Postal codes and coordinates are not in PSGC - those
 * are merged in from the curated list in config/ph_locations.php, which stays
 * the source of truth for them.
 */

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/ph_locations.php';

$SOURCES = [
    'regions'   => 'https://psgc.gitlab.io/api/regions/',
    'provinces' => 'https://psgc.gitlab.io/api/provinces/',
    'cities'    => 'https://psgc.gitlab.io/api/cities-municipalities/',
    'brgys'     => 'https://psgc.gitlab.io/api/barangays/',
];

$localDir = $argv[1] ?? null;

function loadSet(string $key, string $url, ?string $localDir): array {
    if ($localDir !== null) {
        $path = rtrim($localDir, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
        if (!is_file($path)) { throw new RuntimeException("Missing local file: {$path}"); }
        fwrite(STDOUT, "reading {$path}\n");
        $raw = file_get_contents($path);
    } else {
        fwrite(STDOUT, "downloading {$url}\n");
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: TechWorld-PSGC-Import\r\n"]]);
        $raw = file_get_contents($url, false, $ctx);
    }
    if ($raw === false) { throw new RuntimeException("Failed to load {$key}"); }
    $data = json_decode($raw, true);
    if (!is_array($data)) { throw new RuntimeException("Invalid JSON for {$key}"); }
    return $data;
}

try {
    $pdo = getConnection();

    // Schema first, so a fresh clone only needs this one command.
    $sqlFile = __DIR__ . '/../DBSQL/add_ph_psgc_locations.sql';
    if (is_file($sqlFile)) {
        // Strip whole-line comments BEFORE splitting: the file opens with a
        // comment block, and splitting first leaves that block glued to the
        // first CREATE TABLE, so a naive "starts with --" check skipped it.
        $sqlText = (string)file_get_contents($sqlFile);
        $sqlText = preg_replace('/^\s*--.*$/m', '', $sqlText);
        foreach (array_filter(array_map('trim', explode(';', $sqlText))) as $stmt) {
            if ($stmt === '') { continue; }
            $pdo->exec($stmt);
        }
    }

    $regions   = loadSet('regions', $SOURCES['regions'], $localDir);
    $provinces = loadSet('provinces', $SOURCES['provinces'], $localDir);
    $cities    = loadSet('cities', $SOURCES['cities'], $localDir);
    $brgys     = loadSet('brgys', $SOURCES['brgys'], $localDir);

    $regionNameByCode = [];
    foreach ($regions as $r) { $regionNameByCode[$r['code']] = $r['name']; }
    $provinceNameByCode = [];
    foreach ($provinces as $p) { $provinceNameByCode[$p['code']] = $p['name']; }

    // Postal/lat/lng from the curated list, matched on name. PSGC spells cities
    // "City of Malolos" / "City of Caloocan", so compare on a normalized form.
    // Explicitly the curated arrays, not getPhLocations(): that reads from
    // these very tables once they are populated, so a re-run would otherwise
    // "enrich" every row from itself instead of from the hand-checked source.
    $curated = [];
    foreach (getPhCuratedLocations() as $loc) {
        $curated[phNormalizeCityName($loc['name'])] = $loc;
    }

    $pdo->beginTransaction();

    $cityStmt = $pdo->prepare(
        'REPLACE INTO ph_cities (code, name, province_name, region_name, island_group, is_city, postal, lat, lng)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $cityCount = 0;
    $matchedCurated = 0;
    foreach ($cities as $c) {
        $regionName = $regionNameByCode[$c['regionCode']] ?? '';
        // NCR cities carry no provinceCode - the region is the meaningful
        // parent there, and it is what the address line should read.
        $provinceName = $c['provinceCode'] ? ($provinceNameByCode[$c['provinceCode']] ?? $regionName) : $regionName;

        $key = phNormalizeCityName($c['name']);
        $extra = $curated[$key] ?? null;
        if ($extra) { $matchedCurated++; }

        $cityStmt->execute([
            $c['code'],
            $c['name'],
            $provinceName,
            $regionName,
            $c['islandGroupCode'] ?? '',
            !empty($c['isCity']) ? 1 : 0,
            $extra['postal'] ?? null,
            $extra['lat'] ?? null,
            $extra['lng'] ?? null,
        ]);
        $cityCount++;
    }

    // Barangays: the parent is whichever of these PSGC filled in. Manila's are
    // filed under its sub-municipalities but still carry the city code, so
    // cityCode is checked first and they land under Manila as expected.
    $brgyStmt = $pdo->prepare('REPLACE INTO ph_barangays (code, name, city_code) VALUES (?,?,?)');
    $brgyCount = 0;
    $orphans = 0;
    foreach ($brgys as $b) {
        $parent = $b['cityCode'] ?: ($b['municipalityCode'] ?: ($b['subMunicipalityCode'] ?: ''));
        if ($parent === '') { $orphans++; continue; }
        $brgyStmt->execute([$b['code'], $b['name'], $parent]);
        $brgyCount++;
    }

    // ---- Local refinements on top of PSGC -------------------------------
    // Caloocan is one PSGC city but two physically separate halves (Quezon
    // City and Valenzuela sit between them) with their own postal ranges and
    // their own barangay numbering - District 1 (South) is barangays 1-162,
    // District 2 (North) is 163-188. Delivery needs to know which half, so the
    // single row is replaced by the two districts and its barangays are split
    // between them by number.
    $CALOOCAN = '137501000';
    $calRow = $pdo->prepare('SELECT * FROM ph_cities WHERE code = ?');
    $calRow->execute([$CALOOCAN]);
    if ($cal = $calRow->fetch(PDO::FETCH_ASSOC)) {
        $halves = [
            ['code' => '137501S', 'name' => 'Caloocan (South)', 'postal' => '1400', 'lat' => 14.648800, 'lng' => 120.967300, 'from' => 1,   'to' => 162],
            ['code' => '137501N', 'name' => 'Caloocan (North)', 'postal' => '1420', 'lat' => 14.756600, 'lng' => 121.045000, 'from' => 163, 'to' => 188],
        ];
        foreach ($halves as $h) {
            $cityStmt->execute([
                $h['code'], $h['name'], $cal['province_name'], $cal['region_name'],
                $cal['island_group'], 1, $h['postal'], $h['lat'], $h['lng'],
            ]);
        }

        $move = $pdo->prepare('UPDATE ph_barangays SET city_code = ? WHERE code = ?');
        $picks = $pdo->prepare('SELECT code, name FROM ph_barangays WHERE city_code = ?');
        $picks->execute([$CALOOCAN]);
        $moved = 0;
        foreach ($picks->fetchAll(PDO::FETCH_ASSOC) as $b) {
            // Barangay names in Caloocan are the numbers themselves.
            if (!preg_match('/(\d+)/', $b['name'], $m)) { continue; }
            $n = (int)$m[1];
            $target = ($n >= 163) ? '137501N' : '137501S';
            $move->execute([$target, $b['code']]);
            $moved++;
        }
        // Drop the undivided row so the picker offers the two halves only.
        $pdo->prepare('DELETE FROM ph_cities WHERE code = ?')->execute([$CALOOCAN]);
        fwrite(STDOUT, "split Caloocan into North/South ({$moved} barangays reassigned)
");
    }

    $pdo->commit();

    fwrite(STDOUT, "\nimported {$cityCount} cities/municipalities ({$matchedCurated} enriched with postal/coordinates)\n");
    fwrite(STDOUT, "imported {$brgyCount} barangays" . ($orphans ? " ({$orphans} skipped with no parent)" : '') . "\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
