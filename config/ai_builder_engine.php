<?php
declare(strict_types=1);

/**
 * AI Builder recommendation engine - operates entirely on the existing
 * `products`/`categories` tables (see TWDB1.sql). This catalog has no
 * dedicated spec columns, so component attributes (socket, chipset, form
 * factor, RAM type/capacity, GPU VRAM, PSU wattage, etc.) are parsed from
 * each product's existing name/description text via aib_parse_specs().
 * Nothing here invents a product or a spec value that isn't traceable back
 * to that text - if a category has zero matching products (this catalog
 * currently has none for Storage/Case/Cooler/Wi-Fi adapter), the engine
 * reports that slot as unavailable rather than fabricating one.
 *
 * Category IDs (see `categories` table): 1=Processors, 2=Motherboards,
 * 3=Graphics Cards, 4=Memory, 5=Storage, 6=Power Supply, 7=Desktops.
 */

const AIB_CATEGORY_CPU = 1;
const AIB_CATEGORY_MOTHERBOARD = 2;
const AIB_CATEGORY_GPU = 3;
const AIB_CATEGORY_RAM = 4;
const AIB_CATEGORY_STORAGE = 5;
const AIB_CATEGORY_PSU = 6;
const AIB_CATEGORY_DESKTOP = 7;

/** Roles a component fills in a build, in a stable display order. */
const AIB_ROLES = ['cpu', 'motherboard', 'ram', 'gpu', 'storage', 'psu', 'case', 'cooler'];

const AIB_ROLE_LABELS = [
    'cpu' => 'CPU',
    'motherboard' => 'Motherboard',
    'ram' => 'RAM',
    'gpu' => 'Graphics Card',
    'storage' => 'Storage',
    'psu' => 'Power Supply',
    'case' => 'PC Case',
    'cooler' => 'CPU Cooler',
];

/**
 * Ensures the (small, additive-only) tables this feature needs exist.
 * Idempotent, same "CREATE TABLE IF NOT EXISTS at first use" pattern
 * already used elsewhere in this codebase (see forgot_password.php's
 * password_resets bootstrap) - no existing table/column is touched.
 */
function aib_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_builder_saved_builds (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        title VARCHAR(150) NOT NULL,
        budget_min DECIMAL(10,2) NOT NULL DEFAULT 0,
        budget_max DECIMAL(10,2) NOT NULL DEFAULT 0,
        intended_use VARCHAR(255) NOT NULL,
        performance_preference VARCHAR(20) NOT NULL,
        requirements VARCHAR(255) NULL,
        components_json TEXT NOT NULL,
        total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        CONSTRAINT fk_aib_saved_builds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* =========================================================================
 * Spec parsing - regex over each product's own name + description. Every
 * value returned here traces back to text already stored on that product;
 * nothing is guessed for a product that doesn't mention it (the field is
 * simply left null, and callers must treat null as "unknown", not "no").
 * ===================================================================== */

/**
 * LGA1151 physically covers two BIOS/microcode-incompatible generations -
 * 6th/7th gen CPUs on 100/200-series chipsets (H110/B150/H170/Z170/B250/
 * Z270/H270...) vs 8th/9th gen CPUs on 300-series chipsets (H310/B360/H370/
 * B365/Z370/Z390). Same socket name, but a 9400F will not boot on an H110
 * board. Since aib_cpu_motherboard_compatible() only compares the socket
 * string, both generations resolving to the literal string "LGA1151" would
 * make the engine recommend physically incompatible pairings. Bump the
 * 300-series/8th-9th-gen side to a distinct "LGA1151V2" token so the plain
 * string-equality check in that function keeps the two generations apart.
 */
function aib_disambiguate_lga1151(string $socket, string $text): string {
    if ($socket !== 'LGA1151') { return $socket; }
    if (preg_match('/\b(8th|9th)[\s-]?Gen|Coffee[\s-]?Lake|\bB360\b|\bH310\b|\bB365\b|\bZ370\b|\bZ390\b|\bH370\b/i', $text)) {
        return 'LGA1151V2';
    }
    return $socket;
}

function aib_parse_cpu_specs(array $product): array {
    $text = $product['name'] . ' ' . $product['description'];

    $socket = null;
    if (preg_match('/\bLGA\s?(\d{3,4})\b/i', $text, $m)) {
        $socket = aib_disambiguate_lga1151('LGA' . $m[1], $text);
    } elseif (preg_match('/\bAM4\b/i', $text)) {
        $socket = 'AM4';
    } elseif (preg_match('/\bAM5\b/i', $text)) {
        $socket = 'AM5';
    } elseif (preg_match('/\bFM2\+?\b/i', $text)) {
        $socket = 'FM2+';
    }

    $brand = stripos($text, 'AMD') !== false ? 'AMD' : (stripos($text, 'Intel') !== false ? 'Intel' : null);

    $hasIntegratedGraphics = null;
    if (preg_match('/no integrated graphics/i', $text)) {
        $hasIntegratedGraphics = false;
    } elseif (preg_match('/integrated|UHD\s?\d+|Vega\s?\d+/i', $text)) {
        $hasIntegratedGraphics = true;
    }

    $cores = null;
    if (preg_match('/(\d+)[\s-]*core/i', $text, $m)) {
        $cores = (int)$m[1];
    }

    // Public, model-identified TDP tiers (well-known figures for these named
    // chips, not a guessed/invented spec) used only to size the PSU - kept
    // conservative (rounded up) since exact silicon binning isn't knowable
    // from the catalog text.
    $tdp = 65; // typical modern desktop non-K/non-X TDP baseline
    if (preg_match('/Ryzen\s?7|i7-|I7-/i', $text)) { $tdp = 95; }
    if (preg_match('/Ryzen\s?5\s?3600|Ryzen\s?5\s?5600G|Ryzen\s?5\s?4600G/i', $text)) { $tdp = 65; }
    if (preg_match('/13400|12400/i', $text)) { $tdp = 65; }
    if (preg_match('/9400F|11400F/i', $text)) { $tdp = 65; }
    if (preg_match('/G1840|G3250|G3260|200GE/i', $text)) { $tdp = 35; }

    return [
        'socket' => $socket,
        'brand' => $brand,
        'has_igpu' => $hasIntegratedGraphics,
        'cores' => $cores,
        'tdp_watts' => $tdp,
    ];
}

function aib_parse_motherboard_specs(array $product): array {
    $text = $product['name'] . ' ' . $product['description'];

    $socket = null;
    if (preg_match('/\bLGA\s?(\d{3,4})\b/i', $text, $m)) {
        $socket = aib_disambiguate_lga1151('LGA' . $m[1], $text);
    } elseif (preg_match('/\bAM4\b/i', $text)) {
        $socket = 'AM4';
    } elseif (preg_match('/\bAM5\b/i', $text)) {
        $socket = 'AM5';
    } elseif (preg_match('/\bFM2\+?\b/i', $text)) {
        $socket = 'FM2+';
    }

    $chipset = null;
    if (preg_match('/\b([A-Z]\d{2,3})\s+chipset/i', $text, $m)) {
        $chipset = strtoupper($m[1]);
    }

    $ramType = null;
    if (preg_match('/DDR5/i', $text)) { $ramType = 'DDR5'; }
    elseif (preg_match('/DDR4/i', $text)) { $ramType = 'DDR4'; }
    elseif (preg_match('/DDR3/i', $text)) { $ramType = 'DDR3'; }

    $formFactor = null;
    if (preg_match('/Mini-ITX/i', $text)) { $formFactor = 'Mini-ITX'; }
    elseif (preg_match('/Micro-ATX/i', $text)) { $formFactor = 'Micro-ATX'; }
    elseif (preg_match('/\bATX\b/i', $text)) { $formFactor = 'ATX'; }

    $hasWifi = (bool)preg_match('/wi-?fi/i', $text);

    return [
        'socket' => $socket,
        'chipset' => $chipset,
        'ram_type' => $ramType,
        'form_factor' => $formFactor,
        'has_wifi' => $hasWifi,
    ];
}

function aib_parse_ram_specs(array $product): array {
    $text = $product['name'] . ' ' . $product['description'];

    $ramType = null;
    if (preg_match('/DDR5/i', $text)) { $ramType = 'DDR5'; }
    elseif (preg_match('/DDR4/i', $text)) { $ramType = 'DDR4'; }
    elseif (preg_match('/DDR3/i', $text)) { $ramType = 'DDR3'; }

    // SO-DIMM/SODIMM = laptop memory - must be excluded from a desktop
    // build even though it shares the same category as desktop DIMMs.
    $isLaptopModule = (bool)preg_match('/SO-?DIMM/i', $text);

    $capacityGb = null;
    if (preg_match('/(\d+)\s*GB\s*x\s*(\d+)/i', $text, $m)) {
        $capacityGb = (int)$m[1] * (int)$m[2];
    } elseif (preg_match('/(\d+)\s*GB/i', $text, $m)) {
        $capacityGb = (int)$m[1];
    }

    $speedMhz = null;
    if (preg_match('/DDR[345]\s+(\d{3,4})/i', $text, $m)) {
        $speedMhz = (int)$m[1];
    }

    return [
        'ram_type' => $ramType,
        'is_laptop_module' => $isLaptopModule,
        'capacity_gb' => $capacityGb,
        'speed_mhz' => $speedMhz,
    ];
}

/**
 * GPU performance tier (1 = weakest, higher = stronger) and a public,
 * model-identified typical board power figure, both keyed off the specific
 * named GPU model already in the product's own name - not invented data,
 * just well-known facts about that exact named product.
 */
function aib_parse_gpu_specs(array $product): array {
    $text = $product['name'] . ' ' . $product['description'];

    $vramGb = null;
    if (preg_match('/(\d+)\s*GB/i', $text, $m)) {
        $vramGb = (int)$m[1];
    }

    // Ordered weakest -> strongest; matched by distinctive substring.
    $tiers = [
        ['re' => '/GT\s?710/i', 'tier' => 1, 'tdp' => 20],
        ['re' => '/GT\s?730/i', 'tier' => 2, 'tdp' => 25],
        ['re' => '/GT\s?1030/i', 'tier' => 3, 'tdp' => 30],
        ['re' => '/GTX\s?1050(?!\s?Ti)/i', 'tier' => 4, 'tdp' => 75],
        ['re' => '/GTX\s?1050\s?Ti/i', 'tier' => 5, 'tdp' => 75],
        ['re' => '/RX\s?570/i', 'tier' => 6, 'tdp' => 150],
        ['re' => '/GTX\s?1650/i', 'tier' => 6, 'tdp' => 75],
        ['re' => '/GTX\s?1660(?!\s?(Ti|Super))/i', 'tier' => 7, 'tdp' => 120],
        ['re' => '/GTX\s?1660\s?Ti/i', 'tier' => 8, 'tdp' => 120],
        ['re' => '/GTX\s?1660\s?Super/i', 'tier' => 8, 'tdp' => 125],
        ['re' => '/RTX\s?2060/i', 'tier' => 9, 'tdp' => 160],
        ['re' => '/RTX\s?3050/i', 'tier' => 9, 'tdp' => 130],
        ['re' => '/RX\s?6700\s?XT/i', 'tier' => 11, 'tdp' => 230],
        ['re' => '/RTX\s?4060(?!\s?Ti)/i', 'tier' => 10, 'tdp' => 115],
        ['re' => '/RTX\s?4060\s?Ti/i', 'tier' => 11, 'tdp' => 160],
        ['re' => '/RTX\s?4070/i', 'tier' => 13, 'tdp' => 200],
    ];
    $tier = 0;
    $tdp = 75;
    foreach ($tiers as $t) {
        if (preg_match($t['re'], $text)) {
            $tier = $t['tier'];
            $tdp = $t['tdp'];
            break;
        }
    }

    return [
        'vram_gb' => $vramGb,
        'tier' => $tier,
        'tdp_watts' => $tdp,
    ];
}

function aib_parse_psu_specs(array $product): array {
    $text = $product['name'] . ' ' . $product['description'];

    $watts = null;
    if (preg_match('/(\d{3,4})\s?W\b/i', $text, $m)) {
        $watts = (int)$m[1];
    } elseif (preg_match_all('/(?<!\d)(\d{3,4})(?!\d)/', $product['name'], $all)) {
        // Most of the catalog names a PSU by its model number, and the model
        // number IS the wattage: "Ne 650", "GS600", "CV550", "PM-600",
        // "750 Bronze". Without this, only the two names spelled "550W" /
        // "700W" parsed - and when both sold out, every build failed on
        // "no PSU big enough". Name only (a description can mention other
        // numbers), and only a plausible PSU rating, so a part number like
        // "LC-8700" stays unknown instead of becoming an 8700W supply.
        foreach ($all[1] as $n) {
            $n = (int)$n;
            if ($n >= 300 && $n <= 1600) { $watts = $n; break; }
        }
    }

    $efficiency = null;
    if (preg_match('/80\s?PLUS\s?Gold/i', $text)) { $efficiency = '80 PLUS Gold'; }
    elseif (preg_match('/80\s?PLUS\s?Bronze/i', $text)) { $efficiency = '80 PLUS Bronze'; }
    elseif (preg_match('/80\s?PLUS/i', $text)) { $efficiency = '80 PLUS'; }

    $modular = null;
    if (preg_match('/fully modular/i', $text)) { $modular = 'Fully Modular'; }
    elseif (preg_match('/non-modular/i', $text)) { $modular = 'Non-Modular'; }

    return [
        'watts' => $watts,
        'efficiency' => $efficiency,
        'modular' => $modular,
    ];
}

/** Storage: type (SATA SSD / NVMe SSD / HDD) and capacity, parsed the same
 * name+description-regex way as every other category here. */
function aib_parse_storage_specs(array $product): array {
    $text = $product['name'] . ' ' . $product['description'];

    $type = null;
    if (preg_match('/\bNVMe\b/i', $text)) { $type = 'NVMe SSD'; }
    elseif (preg_match('/\bSSD\b/i', $text)) { $type = 'SATA SSD'; }
    elseif (preg_match('/\bHDD\b/i', $text)) { $type = 'HDD'; }

    $capacityGb = null;
    if (preg_match('/(\d+)\s*TB/i', $text, $m)) {
        $capacityGb = (int)$m[1] * 1000;
    } elseif (preg_match('/(\d+)\s*GB/i', $text, $m)) {
        $capacityGb = (int)$m[1];
    }

    return [
        'type' => $type,
        'capacity_gb' => $capacityGb,
    ];
}

/** PC Case: no hard compatibility gate - every case in this catalog is an
 * ATX/Micro-ATX mid-tower, which fits every motherboard form factor
 * actually stocked (Micro-ATX and smaller). Selection is budget-only, same
 * as the pre-fix PSU-without-wattage-filter would have been. */
function aib_parse_case_specs(array $product): array {
    return [];
}

/**
 * CPU Cooler: which sockets it physically mounts to, and the max CPU TDP
 * it's rated for - both stated outright in the product's own description
 * (e.g. "Supports Intel LGA1700 ... AMD AM4/AM5 ... rated up to 220W"),
 * not invented. LGA1151/LGA1151V2 are the same physical mounting holes
 * (the v1/v2 split is a CPU/motherboard BIOS-compatibility distinction,
 * not a mechanical one) so a cooler listing "LGA1151" support fits both.
 */
function aib_parse_cooler_specs(array $product): array {
    $text = $product['name'] . ' ' . $product['description'];

    $sockets = [];
    foreach (['LGA1150', 'LGA1151', 'LGA1200', 'LGA1700', 'AM4', 'AM5'] as $s) {
        if (preg_match('/\b' . $s . '\b/i', $text)) { $sockets[] = $s; }
    }

    $maxTdp = null;
    if (preg_match('/rated up to (\d{2,4})\s?W/i', $text, $m)) {
        $maxTdp = (int)$m[1];
    }

    return [
        'supported_sockets' => $sockets,
        'max_tdp_watts' => $maxTdp,
    ];
}

/** Dispatches to the right parser for a product's category. */
function aib_parse_specs(array $product): array {
    switch ((int)$product['category_id']) {
        case AIB_CATEGORY_CPU: return aib_parse_cpu_specs($product);
        case AIB_CATEGORY_MOTHERBOARD: return aib_parse_motherboard_specs($product);
        case AIB_CATEGORY_RAM: return aib_parse_ram_specs($product);
        case AIB_CATEGORY_GPU: return aib_parse_gpu_specs($product);
        case AIB_CATEGORY_PSU: return aib_parse_psu_specs($product);
        default: return [];
    }
}

/** Dispatches by explicit role rather than category id, for categories
 * (Storage/PC Case/CPU Cooler) whose id isn't a compile-time constant -
 * see aib_category_id_by_name(). Falls back to the id-based dispatch above
 * for the original five roles so existing call sites are unaffected. */
function aib_parse_specs_for_role(?string $role, array $product): array {
    switch ($role) {
        case 'storage': return aib_parse_storage_specs($product);
        case 'case': return aib_parse_case_specs($product);
        case 'cooler': return aib_parse_cooler_specs($product);
        default: return aib_parse_specs($product);
    }
}

/**
 * Resolves a category id by name, for categories that (unlike the original
 * seven) don't have a fixed id known ahead of time - PC Case/CPU Cooler may
 * get different auto-increment ids on a fresh import vs. this live DB.
 * Returns null if the category doesn't exist at all in this database
 * (handled the same "report unavailable, never invent a product" way as
 * every other missing-category case in this file).
 */
function aib_category_id_by_name(PDO $pdo, string $name): ?int {
    static $cache = [];
    if (array_key_exists($name, $cache)) { return $cache[$name]; }
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $cache[$name] = ($id !== false ? (int)$id : null);
}

/** Whether a CPU's socket mounts in a given cooler (LGA1151/LGA1151V2 treated as the same physical socket - see aib_parse_cooler_specs). */
function aib_cooler_compatible(array $cpuSpecs, array $coolerSpecs): ?bool {
    if (empty($cpuSpecs['socket']) || empty($coolerSpecs['supported_sockets'])) { return null; }
    $normalized = str_replace('V2', '', $cpuSpecs['socket']);
    return in_array($normalized, $coolerSpecs['supported_sockets'], true);
}

/**
 * Fetches in-stock, active products for a category, each with its parsed
 * specs attached under $product['specs']. Barebone "Mini PC"/"NUC" kits are
 * excluded from the CPU pool - they're complete mini systems, not a
 * standalone socketed processor a motherboard could pair with.
 */
function aib_get_candidates(PDO $pdo, int $categoryId, ?string $role = null): array {
    $stmt = $pdo->prepare("SELECT id, name, description, price, stock, category_id, image_url FROM products WHERE category_id = ? AND status = 'active' AND stock > 0 ORDER BY price ASC");
    $stmt->execute([$categoryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($categoryId === AIB_CATEGORY_CPU) {
        $rows = array_values(array_filter($rows, function ($p) {
            return !preg_match('/mini pc|\bnuc\b|\bkit\b/i', $p['name']);
        }));
    }
    if ($categoryId === AIB_CATEGORY_RAM) {
        // Desktop builds only - SO-DIMM laptop memory doesn't fit a desktop board.
        $rows = array_values(array_filter($rows, function ($p) {
            return !preg_match('/SO-?DIMM/i', $p['name'] . ' ' . $p['description']);
        }));
    }

    foreach ($rows as &$row) {
        $row['specs'] = aib_parse_specs_for_role($role, $row);
    }
    unset($row);

    return $rows;
}

/** Distinct CPU sockets actually represented among in-stock motherboards - used to make sure a CPU is never recommended for a socket nothing in the catalog can pair it with. */
function aib_available_motherboard_sockets(PDO $pdo): array {
    $sockets = [];
    foreach (aib_get_candidates($pdo, AIB_CATEGORY_MOTHERBOARD) as $m) {
        if (!empty($m['specs']['socket'])) { $sockets[$m['specs']['socket']] = true; }
    }
    return array_keys($sockets);
}

/** Whether category $categoryId currently has any in-stock active product at all. */
function aib_category_has_stock(PDO $pdo, int $categoryId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM products WHERE category_id = ? AND status = 'active' AND stock > 0 LIMIT 1");
    $stmt->execute([$categoryId]);
    return (bool)$stmt->fetchColumn();
}

/* =========================================================================
 * Compatibility checks - each returns true/false, or null when the
 * relevant spec is missing from a product's text (unknown, not "no").
 * ===================================================================== */

function aib_cpu_mobo_compatible(array $cpuSpecs, array $moboSpecs): ?bool {
    if (!$cpuSpecs['socket'] || !$moboSpecs['socket']) { return null; }
    return $cpuSpecs['socket'] === $moboSpecs['socket'];
}

function aib_ram_mobo_compatible(array $ramSpecs, array $moboSpecs): ?bool {
    if (!$ramSpecs['ram_type'] || !$moboSpecs['ram_type']) { return null; }
    return $ramSpecs['ram_type'] === $moboSpecs['ram_type'];
}

/* =========================================================================
 * Use-case knowledge: which roles matter most for each intended use (used
 * both for initial budget allocation and, reversed, for the order in which
 * components get downgraded if the build is over budget), and whether that
 * use case calls for a dedicated GPU at all.
 * ===================================================================== */

// storage/case/cooler deliberately sit last in every profile - see the
// downgrade loop in aib_generate_recommendation(), which downgrades in
// reverse priority order: a build over budget trims the case or cooler to
// a cheaper compatible option long before it would ever touch the CPU/GPU.
const AIB_USE_CASE_PRIORITY = [
    'gaming'         => ['gpu', 'cpu', 'ram', 'motherboard', 'psu', 'storage', 'cooler', 'case'],
    'video_editing'  => ['cpu', 'ram', 'gpu', 'motherboard', 'psu', 'storage', 'cooler', 'case'],
    '3d_rendering'   => ['cpu', 'ram', 'gpu', 'motherboard', 'psu', 'storage', 'cooler', 'case'],
    'streaming'      => ['cpu', 'gpu', 'ram', 'motherboard', 'psu', 'storage', 'cooler', 'case'],
    'photo_editing'  => ['cpu', 'gpu', 'ram', 'motherboard', 'psu', 'storage', 'cooler', 'case'],
    'programming'    => ['cpu', 'ram', 'motherboard', 'psu', 'gpu', 'storage', 'cooler', 'case'],
    'office'         => ['cpu', 'ram', 'motherboard', 'psu', 'gpu', 'storage', 'cooler', 'case'],
    'school'         => ['cpu', 'ram', 'motherboard', 'psu', 'gpu', 'storage', 'cooler', 'case'],
    'general'        => ['cpu', 'ram', 'motherboard', 'psu', 'gpu', 'storage', 'cooler', 'case'],
];

// Target share of budget per role for each primary use case - a starting
// point for *which price point* to aim for in each category, not a hard
// cap (the later budget-optimization pass enforces the hard cap). Shares
// don't need to sum to 100% - the downgrade loop reconciles any overage,
// starting from the lowest-priority role above.
const AIB_USE_CASE_ALLOCATION = [
    'gaming'         => ['cpu' => 0.22, 'motherboard' => 0.10, 'ram' => 0.10, 'gpu' => 0.40, 'psu' => 0.10, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    'video_editing'  => ['cpu' => 0.32, 'motherboard' => 0.10, 'ram' => 0.18, 'gpu' => 0.28, 'psu' => 0.08, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    '3d_rendering'   => ['cpu' => 0.30, 'motherboard' => 0.10, 'ram' => 0.20, 'gpu' => 0.28, 'psu' => 0.08, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    'streaming'      => ['cpu' => 0.28, 'motherboard' => 0.10, 'ram' => 0.15, 'gpu' => 0.30, 'psu' => 0.10, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    'photo_editing'  => ['cpu' => 0.28, 'motherboard' => 0.10, 'ram' => 0.18, 'gpu' => 0.28, 'psu' => 0.10, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    'programming'    => ['cpu' => 0.38, 'motherboard' => 0.17, 'ram' => 0.25, 'psu' => 0.13, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    'office'         => ['cpu' => 0.40, 'motherboard' => 0.18, 'ram' => 0.22, 'psu' => 0.13, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    'school'         => ['cpu' => 0.40, 'motherboard' => 0.18, 'ram' => 0.22, 'psu' => 0.13, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
    'general'        => ['cpu' => 0.38, 'motherboard' => 0.18, 'ram' => 0.22, 'psu' => 0.15, 'storage' => 0.08, 'case' => 0.05, 'cooler' => 0.05],
];

// Precedence when several uses are selected at once - the most demanding
// selected use drives allocation/priority (a gamer who also does office
// work still needs the GPU-first strategy).
const AIB_USE_CASE_PRECEDENCE = ['gaming', '3d_rendering', 'video_editing', 'streaming', 'photo_editing', 'programming', 'office', 'school', 'general'];

const AIB_GPU_REQUIRING_USES = ['gaming', 'video_editing', '3d_rendering', 'streaming', 'photo_editing'];

function aib_primary_use(array $selectedUses): string {
    foreach (AIB_USE_CASE_PRECEDENCE as $use) {
        if (in_array($use, $selectedUses, true)) { return $use; }
    }
    return 'general';
}

/**
 * Picks one candidate from a price-sorted (ascending) list, aiming for the
 * given percentile band of AFFORDABLE options (price <= $maxPrice) so that
 * "performance" preference actually changes which tier gets picked instead
 * of always grabbing the cheapest thing that fits.
 */
function aib_pick_by_percentile(array $sortedCandidates, float $maxPrice, float $percentileLow, float $percentileHigh): ?array {
    $affordable = array_values(array_filter($sortedCandidates, function ($c) use ($maxPrice) {
        return (float)$c['price'] <= $maxPrice;
    }));
    if (empty($affordable)) { return null; }

    $count = count($affordable);
    $lowIdx = (int)floor($percentileLow * ($count - 1));
    $highIdx = (int)floor($percentileHigh * ($count - 1));
    $highIdx = max($lowIdx, $highIdx);
    return $affordable[$highIdx]; // top of the requested band = best value within it
}

function aib_performance_band(string $performance): array {
    switch ($performance) {
        case 'budget': return [0.0, 0.30];
        case 'performance': return [0.60, 0.90];
        case 'high_end': return [0.85, 1.0];
        case 'balanced':
        default: return [0.30, 0.65];
    }
}

/**
 * The full pipeline described in the AI Builder spec: Product Catalog
 * Analysis -> Component Requirement Detection -> Compatibility Checking ->
 * Budget Optimization -> Product Ranking -> Recommended Configuration.
 *
 * $input keys: budget_min, budget_max (float), intended_use (string[]),
 * performance (string), requirements (string[]), base_product_id (?int).
 */
function aib_generate_recommendation(PDO $pdo, array $input): array {
    aib_ensure_tables($pdo);

    $budgetMax = (float)($input['budget_max'] ?? 0);
    $budgetMin = (float)($input['budget_min'] ?? 0);
    $uses = array_values(array_filter((array)($input['intended_use'] ?? [])));
    if (empty($uses)) { $uses = ['general']; }
    $performance = $input['performance'] ?? 'balanced';
    $requirements = array_values(array_filter((array)($input['requirements'] ?? [])));
    $baseProductId = !empty($input['base_product_id']) ? (int)$input['base_product_id'] : null;

    $primaryUse = aib_primary_use($uses);
    $priorityOrder = AIB_USE_CASE_PRIORITY[$primaryUse];
    $allocation = AIB_USE_CASE_ALLOCATION[$primaryUse];
    [$pLow, $pHigh] = aib_performance_band($performance);

    $components = []; // role => normalized result entry
    $notes = [];
    $baseProduct = null;
    $includedRoles = [];

    // --- Starting from an existing pre-built PC (spec section 3) ---
    if ($baseProductId) {
        $stmt = $pdo->prepare("SELECT id, name, description, price, stock, category_id, image_url FROM products WHERE id = ? AND category_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$baseProductId, AIB_CATEGORY_DESKTOP]);
        $baseProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($baseProduct) {
            // A purchased pre-built desktop already bundles CPU, motherboard,
            // RAM, PSU, and a case - those aren't independently swappable
            // parts here, so they're marked included rather than re-picked.
            // Only genuinely add-on components (GPU, storage) get evaluated
            // against what the base machine is missing.
            foreach (['cpu', 'motherboard', 'ram', 'psu', 'case', 'cooler'] as $role) {
                $includedRoles[$role] = true;
                $components[$role] = [
                    'role' => $role,
                    'status' => 'included',
                    'reason' => 'Already included in your selected ' . $baseProduct['name'] . '.',
                ];
            }
        }
    }

    $runningTotal = $baseProduct ? (float)$baseProduct['price'] : 0.0;

    // --- CPU ---
    if (!isset($includedRoles['cpu'])) {
        $cpuCandidates = aib_get_candidates($pdo, AIB_CATEGORY_CPU);

        // Never recommend a CPU for a socket nothing in the motherboard
        // category can actually pair with (this catalog's older LGA1150
        // chips are exactly this case - no LGA1150 board is stocked).
        $availableSockets = aib_available_motherboard_sockets($pdo);
        $pairable = array_values(array_filter($cpuCandidates, function ($c) use ($availableSockets) {
            return $c['specs']['socket'] && in_array($c['specs']['socket'], $availableSockets, true);
        }));
        if (!empty($pairable)) { $cpuCandidates = $pairable; }

        $needsGpuByUse = !empty(array_intersect($uses, AIB_GPU_REQUIRING_USES)) || in_array('dedicated_gpu', $requirements, true);
        if (!$needsGpuByUse) {
            // Office/school/general: prefer a CPU with integrated graphics so
            // a discrete GPU isn't needed at all, freeing that budget for
            // CPU/RAM instead.
            $withIgpu = array_values(array_filter($cpuCandidates, fn($c) => $c['specs']['has_igpu'] === true));
            if (!empty($withIgpu)) { $cpuCandidates = $withIgpu; }
        }
        $cpuBudget = $budgetMax * ($allocation['cpu'] ?? 0.25);
        $picked = aib_pick_by_percentile($cpuCandidates, max($cpuBudget, $runningTotal > 0 ? 0 : $budgetMax), $pLow, $pHigh)
            ?? aib_pick_by_percentile($cpuCandidates, $budgetMax, $pLow, $pHigh)
            ?? (!empty($cpuCandidates) ? $cpuCandidates[0] : null);
        if ($picked) {
            $components['cpu'] = ['role' => 'cpu', 'status' => 'ok', 'product' => $picked];
            $runningTotal += (float)$picked['price'];
        } else {
            $components['cpu'] = ['role' => 'cpu', 'status' => 'unavailable'];
        }
    }
    $cpuSpecs = $components['cpu']['product']['specs'] ?? null;

    // --- Motherboard (must match CPU socket) ---
    if (!isset($includedRoles['motherboard'])) {
        if ($cpuSpecs && $cpuSpecs['socket']) {
            $moboCandidates = aib_get_candidates($pdo, AIB_CATEGORY_MOTHERBOARD);
            $compatible = array_values(array_filter($moboCandidates, function ($m) use ($cpuSpecs) {
                return aib_cpu_mobo_compatible($cpuSpecs, $m['specs']) === true;
            }));
            // Soft preference: Wi-Fi requirement and "upgradeable" nudge
            // toward boards that mention it, without excluding others.
            if (in_array('wifi', $requirements, true) || in_array('bluetooth', $requirements, true)) {
                $wifiBoards = array_values(array_filter($compatible, fn($m) => $m['specs']['has_wifi']));
                if (!empty($wifiBoards)) { $compatible = $wifiBoards; }
            }
            $moboBudget = $budgetMax * ($allocation['motherboard'] ?? 0.15);
            $picked = aib_pick_by_percentile($compatible, $moboBudget, $pLow, $pHigh)
                ?? aib_pick_by_percentile($compatible, $budgetMax, $pLow, $pHigh)
                ?? (!empty($compatible) ? $compatible[0] : null);
            if ($picked) {
                $components['motherboard'] = ['role' => 'motherboard', 'status' => 'ok', 'product' => $picked];
                $runningTotal += (float)$picked['price'];
            } else {
                $components['motherboard'] = ['role' => 'motherboard', 'status' => 'incompatible', 'reason' => 'No motherboard in the catalog matches the ' . $cpuSpecs['socket'] . ' socket of the recommended CPU.'];
                $notes[] = 'Could not find a compatible motherboard for the selected CPU socket (' . $cpuSpecs['socket'] . ').';
            }
        } else {
            $components['motherboard'] = ['role' => 'motherboard', 'status' => 'unavailable', 'reason' => 'CPU socket could not be determined, so compatible motherboards could not be verified.'];
        }
    }
    $moboSpecs = $components['motherboard']['product']['specs'] ?? null;

    // --- RAM (must match motherboard's RAM type) ---
    if (!isset($includedRoles['ram'])) {
        if ($moboSpecs && $moboSpecs['ram_type']) {
            $ramCandidates = aib_get_candidates($pdo, AIB_CATEGORY_RAM);
            $compatible = array_values(array_filter($ramCandidates, function ($r) use ($moboSpecs) {
                return aib_ram_mobo_compatible($r['specs'], $moboSpecs) === true;
            }));
            $minCapacity = 8;
            if (in_array('high_ram', $requirements, true)) { $minCapacity = 16; }
            if (in_array($primaryUse, ['video_editing', '3d_rendering', 'programming'], true)) { $minCapacity = max($minCapacity, 16); }
            if (in_array($performance, ['performance', 'high_end'], true)) { $minCapacity = max($minCapacity, 16); }
            $capacityFiltered = array_values(array_filter($compatible, fn($r) => ($r['specs']['capacity_gb'] ?? 0) >= $minCapacity));
            if (!empty($capacityFiltered)) { $compatible = $capacityFiltered; }

            $ramBudget = $budgetMax * ($allocation['ram'] ?? 0.15);
            $picked = aib_pick_by_percentile($compatible, $ramBudget, $pLow, $pHigh)
                ?? aib_pick_by_percentile($compatible, $budgetMax, $pLow, $pHigh)
                ?? (!empty($compatible) ? $compatible[0] : null);
            if ($picked) {
                $components['ram'] = ['role' => 'ram', 'status' => 'ok', 'product' => $picked];
                $runningTotal += (float)$picked['price'];
            } else {
                $components['ram'] = ['role' => 'ram', 'status' => 'incompatible', 'reason' => 'No ' . $moboSpecs['ram_type'] . ' desktop memory module found in the catalog to match the recommended motherboard.'];
                $notes[] = 'Could not find compatible RAM (' . $moboSpecs['ram_type'] . ') for the selected motherboard.';
            }
        } else {
            $components['ram'] = ['role' => 'ram', 'status' => 'unavailable', 'reason' => 'Motherboard RAM type could not be determined, so compatible memory could not be verified.'];
        }
    }

    // --- GPU (only when the use case, requirement, or CPU demands it) ---
    if (!isset($includedRoles['gpu'])) {
        $needsGpu = !empty(array_intersect($uses, AIB_GPU_REQUIRING_USES))
            || in_array('dedicated_gpu', $requirements, true)
            || ($cpuSpecs && $cpuSpecs['has_igpu'] === false);
        if ($needsGpu) {
            $gpuCandidates = aib_get_candidates($pdo, AIB_CATEGORY_GPU);
            usort($gpuCandidates, fn($a, $b) => $a['price'] <=> $b['price']);
            $gpuBudget = $budgetMax - $runningTotal + ($allocation['gpu'] ?? 0.30) * $budgetMax;
            $picked = aib_pick_by_percentile($gpuCandidates, max($gpuBudget, 0), $pLow, $pHigh)
                ?? aib_pick_by_percentile($gpuCandidates, $budgetMax, $pLow, $pHigh)
                ?? (!empty($gpuCandidates) ? $gpuCandidates[0] : null);
            if ($picked) {
                $components['gpu'] = ['role' => 'gpu', 'status' => 'ok', 'product' => $picked];
                $runningTotal += (float)$picked['price'];
            } else {
                $components['gpu'] = ['role' => 'gpu', 'status' => 'unavailable'];
            }
        } else {
            $components['gpu'] = [
                'role' => 'gpu',
                'status' => 'skipped',
                'reason' => 'Not needed - the recommended CPU includes integrated graphics and your selected use doesn\'t require a dedicated GPU, so that budget went to CPU/RAM instead.',
            ];
        }
    }
    $gpuSpecs = $components['gpu']['product']['specs'] ?? null;

    // --- PSU (sized to CPU + GPU estimated draw, with headroom) ---
    if (!isset($includedRoles['psu'])) {
        $estimatedLoad = ($cpuSpecs['tdp_watts'] ?? 65) + ($gpuSpecs['tdp_watts'] ?? 0) + 100; // +100W baseline for board/RAM/fans
        $requiredWatts = (int)(ceil($estimatedLoad * 1.3 / 50) * 50);
        $psuCandidates = aib_get_candidates($pdo, AIB_CATEGORY_PSU);
        $sufficient = array_values(array_filter($psuCandidates, function ($p) use ($requiredWatts) {
            return $p['specs']['watts'] !== null && $p['specs']['watts'] >= $requiredWatts;
        }));
        usort($sufficient, fn($a, $b) => $a['price'] <=> $b['price']);
        $picked = !empty($sufficient) ? $sufficient[0] : null;
        if ($picked) {
            $components['psu'] = ['role' => 'psu', 'status' => 'ok', 'product' => $picked, 'required_watts' => $requiredWatts];
            $runningTotal += (float)$picked['price'];
        } else {
            $components['psu'] = ['role' => 'psu', 'status' => 'unavailable', 'reason' => 'No PSU in the catalog with at least the estimated ' . $requiredWatts . 'W required for this build.', 'required_watts' => $requiredWatts];
            $notes[] = 'Could not find a PSU rated for the estimated ' . $requiredWatts . 'W this build needs.';
        }
    }

    // --- Storage (capacity/type picked by use case, no hard compatibility
    // gate - every drive in this catalog is a standard SATA/NVMe/HDD
    // desktop drive) ---
    if (!isset($includedRoles['storage'])) {
        $storageCandidates = aib_get_candidates($pdo, AIB_CATEGORY_STORAGE, 'storage');
        if (!empty($storageCandidates)) {
            $minCapacity = 480;
            if (in_array('large_storage', $requirements, true)) { $minCapacity = 1000; }
            if (in_array($primaryUse, ['video_editing', '3d_rendering', 'photo_editing'], true)) { $minCapacity = max($minCapacity, 1000); }
            $capacityFiltered = array_values(array_filter($storageCandidates, fn($s) => ($s['specs']['capacity_gb'] ?? 0) >= $minCapacity));
            if (!empty($capacityFiltered)) { $storageCandidates = $capacityFiltered; }

            // Prefer an SSD (OS/boot speed) over an HDD unless the customer
            // explicitly asked for bulk capacity.
            if (!in_array('large_storage', $requirements, true)) {
                $ssdOnly = array_values(array_filter($storageCandidates, fn($s) => ($s['specs']['type'] ?? '') !== 'HDD'));
                if (!empty($ssdOnly)) { $storageCandidates = $ssdOnly; }
            }

            $storageBudget = $budgetMax * ($allocation['storage'] ?? 0.08);
            $picked = aib_pick_by_percentile($storageCandidates, $storageBudget, $pLow, $pHigh)
                ?? aib_pick_by_percentile($storageCandidates, $budgetMax, $pLow, $pHigh)
                ?? $storageCandidates[0];
            $components['storage'] = ['role' => 'storage', 'status' => 'ok', 'product' => $picked];
            $runningTotal += (float)$picked['price'];
        } else {
            $components['storage'] = ['role' => 'storage', 'status' => 'unavailable', 'reason' => "We couldn't find a suitable Storage product in the current TechWorld catalog. Please check the product catalog for available alternatives."];
        }
    }

    // --- CPU Cooler (must physically mount to the CPU's socket, and be
    // rated for at least its TDP) ---
    if (!isset($includedRoles['cooler'])) {
        $coolerCategoryId = aib_category_id_by_name($pdo, 'CPU Cooler');
        $coolerCandidates = $coolerCategoryId ? aib_get_candidates($pdo, $coolerCategoryId, 'cooler') : [];
        if ($cpuSpecs && $cpuSpecs['socket'] && !empty($coolerCandidates)) {
            $compatible = array_values(array_filter($coolerCandidates, fn($c) => aib_cooler_compatible($cpuSpecs, $c['specs']) === true));
            $requiredTdp = $cpuSpecs['tdp_watts'] ?? 65;
            $sufficient = array_values(array_filter($compatible, fn($c) => ($c['specs']['max_tdp_watts'] ?? 0) >= $requiredTdp));
            if (!empty($sufficient)) { $compatible = $sufficient; }

            $coolerBudget = $budgetMax * ($allocation['cooler'] ?? 0.05);
            $picked = aib_pick_by_percentile($compatible, $coolerBudget, $pLow, $pHigh)
                ?? aib_pick_by_percentile($compatible, $budgetMax, $pLow, $pHigh)
                ?? (!empty($compatible) ? $compatible[0] : null);
            if ($picked) {
                $components['cooler'] = ['role' => 'cooler', 'status' => 'ok', 'product' => $picked, 'required_tdp_watts' => $requiredTdp];
                $runningTotal += (float)$picked['price'];
            } else {
                $components['cooler'] = ['role' => 'cooler', 'status' => 'incompatible', 'reason' => 'No CPU Cooler in the catalog is both rated for the recommended CPU\'s ' . $requiredTdp . 'W TDP and mounts to its ' . $cpuSpecs['socket'] . ' socket.'];
                $notes[] = 'Could not find a compatible CPU cooler for the selected CPU.';
            }
        } else {
            $components['cooler'] = ['role' => 'cooler', 'status' => 'unavailable', 'reason' => $cpuSpecs && $cpuSpecs['socket'] ? "We couldn't find a suitable CPU Cooler in the current TechWorld catalog." : 'CPU socket could not be determined, so a compatible cooler could not be verified.'];
        }
    }

    // --- PC Case (budget-only pick - see aib_parse_case_specs) ---
    if (!isset($includedRoles['case'])) {
        $caseCategoryId = aib_category_id_by_name($pdo, 'PC Case');
        $caseCandidates = $caseCategoryId ? aib_get_candidates($pdo, $caseCategoryId, 'case') : [];
        if (!empty($caseCandidates)) {
            $caseBudget = $budgetMax * ($allocation['case'] ?? 0.05);
            $picked = aib_pick_by_percentile($caseCandidates, $caseBudget, $pLow, $pHigh)
                ?? aib_pick_by_percentile($caseCandidates, $budgetMax, $pLow, $pHigh)
                ?? $caseCandidates[0];
            $components['case'] = ['role' => 'case', 'status' => 'ok', 'product' => $picked];
            $runningTotal += (float)$picked['price'];
        } else {
            $components['case'] = ['role' => 'case', 'status' => 'unavailable', 'reason' => "We couldn't find a suitable PC Case in the current TechWorld catalog. Please check the product catalog for available alternatives."];
        }
    }

    // --- Budget optimization: downgrade lowest-priority roles first.
    // Downgrading the CPU or motherboard can invalidate an already-picked
    // dependent (a cheaper CPU may need a different socket motherboard,
    // which may in turn need different RAM) - each downgrade below cascades
    // through those dependents instead of just swapping one product and
    // leaving the rest stale, which is what let an incompatible CPU/
    // motherboard pair slip through in earlier testing.
    $downgradeOrder = array_reverse($priorityOrder);
    $maxAttempts = 8;
    while ($runningTotal > $budgetMax && $maxAttempts-- > 0) {
        $downgraded = false;
        foreach ($downgradeOrder as $role) {
            if (empty($components[$role]['product'])) { continue; }
            $current = $components[$role]['product'];
            $cheaper = aib_find_cheaper_compatible($pdo, $role, $current, $components, $requirements);
            if (!$cheaper || (float)$cheaper['price'] >= (float)$current['price']) { continue; }

            $runningTotal -= (float)$current['price'];
            $runningTotal += (float)$cheaper['price'];
            $components[$role]['product'] = $cheaper;
            $components[$role]['downgraded'] = true;
            $downgraded = true;

            if ($role === 'cpu') {
                $runningTotal = aib_resync_after_cpu_change($pdo, $components, $cheaper['specs'], $requirements, $runningTotal);
            } elseif ($role === 'motherboard') {
                $runningTotal = aib_resync_ram_after_mobo_change($pdo, $components, $cheaper['specs'], $runningTotal);
            }
            break; // re-check total before downgrading further
        }
        if (!$downgraded) { break; }
    }

    $overBudget = $runningTotal > $budgetMax + 0.01;
    if ($overBudget) {
        $notes[] = 'Even the most economical compatible combination available in the catalog (' . formatCurrency($runningTotal) . ') exceeds your ' . formatCurrency($budgetMax) . ' budget by ' . formatCurrency($runningTotal - $budgetMax) . '.';
    }

    // --- Final compatibility validation pass ---
    $compatibilityIssues = [];
    if (!empty($components['cpu']['product']) && !empty($components['motherboard']['product'])) {
        $ok = aib_cpu_mobo_compatible($components['cpu']['product']['specs'], $components['motherboard']['product']['specs']);
        if ($ok === false) { $compatibilityIssues[] = 'CPU socket does not match motherboard socket.'; }
        if ($ok === null) { $notes[] = 'CPU/motherboard socket compatibility could not be fully verified from the available product descriptions.'; }
    }
    if (!empty($components['motherboard']['product']) && !empty($components['ram']['product'])) {
        $ok = aib_ram_mobo_compatible($components['ram']['product']['specs'], $components['motherboard']['product']['specs']);
        if ($ok === false) { $compatibilityIssues[] = 'RAM type does not match motherboard supported memory.'; }
        if ($ok === null) { $notes[] = 'RAM/motherboard memory-type compatibility could not be fully verified from the available product descriptions.'; }
    }
    // A required slot (cpu/motherboard/ram/psu - not the always-optional
    // gpu, and not storage/case/cooler, which this catalog simply doesn't
    // stock at all) ending up unavailable/incompatible after the budget
    // downgrade cascade is itself a compatibility gap worth surfacing, even
    // though it's not a *wrong pairing* the way a socket mismatch is.
    foreach (['cpu', 'motherboard', 'ram', 'psu'] as $requiredRole) {
        $status = $components[$requiredRole]['status'] ?? null;
        if (in_array($status, ['unavailable', 'incompatible'], true)) {
            $compatibilityIssues[] = $components[$requiredRole]['reason'] ?? (AIB_ROLE_LABELS[$requiredRole] . ' could not be matched from the current catalog.');
        }
    }

    $compatibilityStatus = !empty($compatibilityIssues) ? 'issues' : (!empty($notes) ? 'partial' : 'ok');

    // --- Per-component explanations ---
    foreach ($components as $role => &$entry) {
        if (($entry['status'] ?? '') === 'ok' && !empty($entry['product'])) {
            $entry['explanation'] = aib_explain_component($role, $entry, $primaryUse, $performance, $requirements);
        }
    }
    unset($entry);

    $result = [
        'success' => true,
        'primary_use' => $primaryUse,
        'selected_uses' => $uses,
        'performance' => $performance,
        'requirements' => $requirements,
        'base_product' => $baseProduct,
        'components' => $components,
        'total_price' => round($runningTotal, 2),
        'budget_min' => $budgetMin,
        'budget_max' => $budgetMax,
        'remaining' => round($budgetMax - $runningTotal, 2),
        'over_budget' => $overBudget,
        'compatibility_status' => $compatibilityStatus,
        'compatibility_issues' => $compatibilityIssues,
        'notes' => $notes,
        'summary' => aib_build_summary($primaryUse, $performance, $budgetMax, $runningTotal, $overBudget),
    ];

    return $result;
}

/**
 * After the CPU changes (e.g. during budget downgrading), re-validates and,
 * if needed, re-picks the motherboard for the new socket (cascading further
 * into RAM if the motherboard itself changes), and re-checks the PSU still
 * covers the new CPU's estimated power draw. Returns the updated running
 * total. $components is mutated in place.
 */
function aib_resync_after_cpu_change(PDO $pdo, array &$components, array $newCpuSpecs, array $requirements, float $runningTotal): float {
    if (!empty($components['motherboard']['product'])) {
        $currentMobo = $components['motherboard']['product'];
        if (aib_cpu_mobo_compatible($newCpuSpecs, $currentMobo['specs']) !== true) {
            $moboCandidates = aib_get_candidates($pdo, AIB_CATEGORY_MOTHERBOARD);
            $compatible = array_values(array_filter($moboCandidates, fn($m) => aib_cpu_mobo_compatible($newCpuSpecs, $m['specs']) === true));
            usort($compatible, fn($a, $b) => $a['price'] <=> $b['price']);
            $runningTotal -= (float)$currentMobo['price'];
            if (!empty($compatible)) {
                $newMobo = $compatible[0]; // cheapest compatible - we're actively cutting cost here
                $runningTotal += (float)$newMobo['price'];
                $components['motherboard']['product'] = $newMobo;
                $components['motherboard']['status'] = 'ok';
                $components['motherboard']['downgraded'] = true;
                $runningTotal = aib_resync_ram_after_mobo_change($pdo, $components, $newMobo['specs'], $runningTotal);
            } else {
                $components['motherboard']['product'] = null;
                $components['motherboard']['status'] = 'incompatible';
                $components['motherboard']['reason'] = 'No motherboard in the catalog matches the ' . ($newCpuSpecs['socket'] ?? 'required') . ' socket of the recommended CPU.';
                // No motherboard means RAM can't be verified either - drop it
                // rather than leave a RAM pick compatible with a board that
                // no longer exists in this build.
                if (!empty($components['ram']['product'])) {
                    $runningTotal -= (float)$components['ram']['product']['price'];
                    $components['ram']['product'] = null;
                    $components['ram']['status'] = 'incompatible';
                    $components['ram']['reason'] = 'No compatible motherboard was found for the recommended CPU, so RAM compatibility could not be determined.';
                }
            }
        }
    }

    if (!empty($components['psu']['product'])) {
        $gpuSpecs = $components['gpu']['product']['specs'] ?? null;
        $estimatedLoad = ($newCpuSpecs['tdp_watts'] ?? 65) + ($gpuSpecs['tdp_watts'] ?? 0) + 100;
        $requiredWatts = (int)(ceil($estimatedLoad * 1.3 / 50) * 50);
        $components['psu']['required_watts'] = $requiredWatts;
        $currentPsu = $components['psu']['product'];
        if (($currentPsu['specs']['watts'] ?? 0) < $requiredWatts) {
            $psuCandidates = aib_get_candidates($pdo, AIB_CATEGORY_PSU);
            $sufficient = array_values(array_filter($psuCandidates, fn($p) => ($p['specs']['watts'] ?? 0) >= $requiredWatts));
            usort($sufficient, fn($a, $b) => $a['price'] <=> $b['price']);
            if (!empty($sufficient)) {
                $runningTotal -= (float)$currentPsu['price'];
                $runningTotal += (float)$sufficient[0]['price'];
                $components['psu']['product'] = $sufficient[0];
            }
        }
    }

    return $runningTotal;
}

/** After the motherboard changes, re-validates/re-picks RAM to match its RAM type. */
function aib_resync_ram_after_mobo_change(PDO $pdo, array &$components, array $newMoboSpecs, float $runningTotal): float {
    if (empty($components['ram']['product'])) { return $runningTotal; }
    $currentRam = $components['ram']['product'];
    if (aib_ram_mobo_compatible($currentRam['specs'], $newMoboSpecs) === true) { return $runningTotal; }

    $ramCandidates = aib_get_candidates($pdo, AIB_CATEGORY_RAM);
    $compatible = array_values(array_filter($ramCandidates, fn($r) => aib_ram_mobo_compatible($r['specs'], $newMoboSpecs) === true));
    usort($compatible, fn($a, $b) => $a['price'] <=> $b['price']);
    $runningTotal -= (float)$currentRam['price'];
    if (!empty($compatible)) {
        $runningTotal += (float)$compatible[0]['price'];
        $components['ram']['product'] = $compatible[0];
        $components['ram']['status'] = 'ok';
        $components['ram']['downgraded'] = true;
    } else {
        $components['ram']['product'] = null;
        $components['ram']['status'] = 'incompatible';
        $components['ram']['reason'] = 'No ' . ($newMoboSpecs['ram_type'] ?? '') . ' desktop memory module found in the catalog to match the recommended motherboard.';
    }
    return $runningTotal;
}

/** Looks for a cheaper product for $role that still passes the same compatibility rules as the current pick. */
function aib_find_cheaper_compatible(PDO $pdo, string $role, array $current, array $components, array $requirements): ?array {
    switch ($role) {
        case 'cpu':
            $candidates = aib_get_candidates($pdo, AIB_CATEGORY_CPU);
            $availableSockets = aib_available_motherboard_sockets($pdo);
            $pairable = array_values(array_filter($candidates, fn($c) => $c['specs']['socket'] && in_array($c['specs']['socket'], $availableSockets, true)));
            if (!empty($pairable)) { $candidates = $pairable; }
            break;
        case 'motherboard':
            $candidates = aib_get_candidates($pdo, AIB_CATEGORY_MOTHERBOARD);
            $cpuSpecs = $components['cpu']['product']['specs'] ?? null;
            if ($cpuSpecs) {
                $candidates = array_values(array_filter($candidates, fn($m) => aib_cpu_mobo_compatible($cpuSpecs, $m['specs']) === true));
            }
            break;
        case 'ram':
            $candidates = aib_get_candidates($pdo, AIB_CATEGORY_RAM);
            $moboSpecs = $components['motherboard']['product']['specs'] ?? null;
            if ($moboSpecs) {
                $candidates = array_values(array_filter($candidates, fn($r) => aib_ram_mobo_compatible($r['specs'], $moboSpecs) === true));
            }
            break;
        case 'gpu':
            $candidates = aib_get_candidates($pdo, AIB_CATEGORY_GPU);
            break;
        case 'psu':
            $required = $current['specs']['watts'] ?? 0;
            $candidates = aib_get_candidates($pdo, AIB_CATEGORY_PSU);
            $candidates = array_values(array_filter($candidates, fn($p) => ($p['specs']['watts'] ?? 0) >= $required));
            break;
        case 'storage':
            $candidates = aib_get_candidates($pdo, AIB_CATEGORY_STORAGE, 'storage');
            $minCapacity = $current['specs']['capacity_gb'] ?? 0;
            $candidates = array_values(array_filter($candidates, fn($s) => ($s['specs']['capacity_gb'] ?? 0) >= $minCapacity));
            break;
        case 'case':
            $caseCategoryId = aib_category_id_by_name($pdo, 'PC Case');
            $candidates = $caseCategoryId ? aib_get_candidates($pdo, $caseCategoryId, 'case') : [];
            break;
        case 'cooler':
            $coolerCategoryId = aib_category_id_by_name($pdo, 'CPU Cooler');
            $candidates = $coolerCategoryId ? aib_get_candidates($pdo, $coolerCategoryId, 'cooler') : [];
            $cpuSpecs = $components['cpu']['product']['specs'] ?? null;
            $requiredTdp = $cpuSpecs['tdp_watts'] ?? ($current['specs']['max_tdp_watts'] ?? 0);
            if ($cpuSpecs) {
                $candidates = array_values(array_filter($candidates, fn($c) => aib_cooler_compatible($cpuSpecs, $c['specs']) === true && ($c['specs']['max_tdp_watts'] ?? 0) >= $requiredTdp));
            }
            break;
        default:
            return null;
    }
    usort($candidates, fn($a, $b) => $a['price'] <=> $b['price']);
    foreach ($candidates as $c) {
        if ((float)$c['price'] < (float)$current['price']) { return $c; }
    }
    return null;
}

function aib_explain_component(string $role, array $entry, string $primaryUse, string $performance, array $requirements): string {
    $useLabel = str_replace('_', ' ', $primaryUse);
    $product = $entry['product'];

    if (!empty($entry['downgraded'])) {
        $base = "Switched to a more affordable compatible option so the total build stays within your budget. ";
    } else {
        $base = '';
    }

    switch ($role) {
        case 'cpu':
            return $base . "Selected as a {$performance}-tier processor that fits your budget while keeping enough headroom for the other components your {$useLabel} build needs.";
        case 'motherboard':
            return $base . "Chosen because its socket matches the recommended CPU (" . ($product['specs']['socket'] ?? 'compatible socket') . ") and its " . ($product['specs']['ram_type'] ?? 'memory type') . " support matches the recommended RAM.";
        case 'ram':
            return $base . "Provides " . ($product['specs']['capacity_gb'] ?? '') . "GB of " . ($product['specs']['ram_type'] ?? '') . " memory matching your motherboard, sized for " . $useLabel . " workloads.";
        case 'gpu':
            return $base . "Recommended because {$useLabel} was selected as an intended use, and graphics performance has the biggest impact on that experience within your remaining budget.";
        case 'psu':
            return $base . "Sized with headroom above the estimated power draw of the recommended CPU and GPU (~" . ($entry['required_watts'] ?? '') . "W minimum) for stable operation.";
        case 'storage':
            return $base . "Provides " . ($product['specs']['capacity_gb'] ?? '') . "GB of " . ($product['specs']['type'] ?? 'storage') . " for your OS, games, and files.";
        case 'cooler':
            return $base . "Mounts to the recommended CPU's " . ($product['specs']['supported_sockets'][0] ?? 'socket') . "-compatible bracket and is rated for at least the ~" . ($entry['required_tdp_watts'] ?? '') . "W it needs to dissipate.";
        case 'case':
            return $base . "A mid-tower case sized to fit the recommended motherboard, chosen within your remaining budget.";
        default:
            return $base;
    }
}

/**
 * Backend-authoritative compatibility check for an arbitrary set of product
 * ids (used by the Gemini-based recommender and by component-swap rechecks -
 * neither of those trusts the AI's own claim of compatibility, only this).
 * Re-fetches each product fresh from the DB (never trusts a client-supplied
 * snapshot) and runs the same socket/RAM-type/PSU-wattage checks the
 * deterministic engine uses. At most one product per role (cpu/motherboard/
 * ram/gpu/psu) is considered - if the set contains more than one, only the
 * first of each is checked and a note is added.
 */
function aib_validate_component_set(PDO $pdo, array $productIds): array {
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    $issues = [];
    $notes = [];
    $unavailableIds = [];

    if (empty($productIds)) {
        return ['status' => 'ok', 'issues' => $issues, 'notes' => $notes, 'unavailable_ids' => $unavailableIds];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name, description, price, stock, category_id, image_url, status FROM products WHERE id IN ($placeholders)");
    $stmt->execute($productIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $foundIds = array_column($rows, 'id');
    foreach ($productIds as $pid) {
        if (!in_array($pid, $foundIds, true)) { $unavailableIds[] = $pid; }
    }

    $byRole = ['cpu' => [], 'motherboard' => [], 'ram' => [], 'gpu' => [], 'psu' => []];
    $roleByCategory = [
        AIB_CATEGORY_CPU => 'cpu', AIB_CATEGORY_MOTHERBOARD => 'motherboard',
        AIB_CATEGORY_RAM => 'ram', AIB_CATEGORY_GPU => 'gpu', AIB_CATEGORY_PSU => 'psu',
    ];
    foreach ($rows as $row) {
        if ($row['status'] !== 'active' || (int)$row['stock'] <= 0) { $unavailableIds[] = (int)$row['id']; continue; }
        $role = $roleByCategory[(int)$row['category_id']] ?? null;
        if ($role === null) { continue; }
        $row['specs'] = aib_parse_specs($row);
        $byRole[$role][] = $row;
    }
    foreach ($byRole as $role => $items) {
        if (count($items) > 1) { $notes[] = 'More than one ' . AIB_ROLE_LABELS[$role] . ' was selected - only the first is used for compatibility checks.'; }
    }

    $cpu = $byRole['cpu'][0] ?? null;
    $mobo = $byRole['motherboard'][0] ?? null;
    $ram = $byRole['ram'][0] ?? null;
    $gpu = $byRole['gpu'][0] ?? null;
    $psu = $byRole['psu'][0] ?? null;

    if ($cpu && $mobo) {
        $ok = aib_cpu_mobo_compatible($cpu['specs'], $mobo['specs']);
        if ($ok === false) { $issues[] = 'CPU socket (' . ($cpu['specs']['socket'] ?? 'unknown') . ') does not match motherboard socket (' . ($mobo['specs']['socket'] ?? 'unknown') . ').'; }
        if ($ok === null) { $notes[] = 'CPU/motherboard socket compatibility could not be fully verified from the available product descriptions.'; }
    }
    if ($mobo && $ram) {
        $ok = aib_ram_mobo_compatible($ram['specs'], $mobo['specs']);
        if ($ok === false) { $issues[] = 'RAM type (' . ($ram['specs']['ram_type'] ?? 'unknown') . ') does not match motherboard supported memory (' . ($mobo['specs']['ram_type'] ?? 'unknown') . ').'; }
        if ($ok === null) { $notes[] = 'RAM/motherboard memory-type compatibility could not be fully verified from the available product descriptions.'; }
    }
    if ($cpu || $gpu) {
        $estimatedLoad = ($cpu['specs']['tdp_watts'] ?? 65) + ($gpu['specs']['tdp_watts'] ?? 0) + 100;
        $requiredWatts = (int)(ceil($estimatedLoad * 1.3 / 50) * 50);
        if ($psu) {
            $watts = $psu['specs']['watts'] ?? null;
            if ($watts !== null && $watts < $requiredWatts) {
                $issues[] = 'The selected PSU (' . $watts . 'W) may be insufficient for this build\'s estimated ' . $requiredWatts . 'W power draw.';
            } elseif ($watts === null) {
                $notes[] = 'Could not determine the selected PSU\'s wattage from its description, so its sufficiency could not be verified.';
            }
        } else {
            $notes[] = 'No power supply is included in this build - power requirements (~' . $requiredWatts . 'W estimated) could not be verified.';
        }
    }

    if (!empty($unavailableIds)) {
        $notes[] = count($unavailableIds) . ' selected item(s) are no longer active or in stock.';
    }

    $status = !empty($issues) ? 'issues' : (!empty($notes) ? 'partial' : 'ok');
    return ['status' => $status, 'issues' => $issues, 'notes' => $notes, 'unavailable_ids' => array_values(array_unique($unavailableIds))];
}

function aib_build_summary(string $primaryUse, string $performance, float $budgetMax, float $total, bool $overBudget): string {
    $useLabel = str_replace('_', ' ', $primaryUse);
    $perfLabel = str_replace('_', '-', $performance);
    $perfPhrase = $perfLabel === 'performance' ? 'a performance-focused tier' : "a {$perfLabel} tier";
    $base = "This configuration was optimized for {$useLabel} at {$perfPhrase} while " . ($overBudget ? "staying as close as possible to" : "staying within") . " your " . formatCurrency($budgetMax) . " budget.";
    if (in_array($primaryUse, AIB_GPU_REQUIRING_USES, true)) {
        $base .= ' The graphics card received the largest share of the budget since it has the greatest effect on ' . $useLabel . ' performance.';
    } else {
        $base .= ' The CPU and RAM received the largest share of the budget since they matter most for ' . $useLabel . ' workloads.';
    }
    return $base;
}
