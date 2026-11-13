<?php
declare(strict_types=1);

/**
 * product_tagger.php - derives structured tags for a product from data the
 * catalog already stores (name, description, price, category).
 *
 * Why this exists: the `tags`/`category_tags` pair tags CATEGORIES, so it can
 * only describe a product as coarsely as "this is a motherboard" - which the
 * category_id already says. Recommendations need to tell two motherboards
 * apart, so tags have to live on the product and be dimensional (socket=AM4)
 * rather than flat (gaming).
 *
 * Nothing here invents a spec. Every tag is traceable to a literal substring
 * of the product's own name/description, to its price relative to its
 * category, or to its stock level - the same discipline ai_builder_engine.php
 * follows when it parses specs out of the same text.
 *
 * Used by execution/backfill_product_tags.php (bulk) and by add_product.php /
 * product_management.php (single product, on save).
 */

/**
 * Brand aliases. The brand is almost always the first token of the product
 * name, but a handful are two words or appear in several spellings, so map
 * those to one canonical form - 'Hp' and 'HP' must not become two brands, or
 * brand affinity silently splits in half.
 */
const PT_BRAND_ALIASES = [
    'hp' => 'HP', 'h.p' => 'HP',
    'asus' => 'ASUS', 'msi' => 'MSI', 'amd' => 'AMD', 'intel' => 'Intel',
    'wd' => 'Western Digital', 'western' => 'Western Digital',
    'tp-link' => 'TP-Link', 'tplink' => 'TP-Link', 'tp' => 'TP-Link',
    'a4tech' => 'A4Tech', 'inno3d' => 'INNO3D', 'adata' => 'ADATA',
    'gigabyte' => 'Gigabyte', 'epson' => 'Epson', 'canon' => 'Canon',
    'brother' => 'Brother', 'lenovo' => 'Lenovo', 'acer' => 'Acer',
    'kingston' => 'Kingston', 'redragon' => 'Redragon', 'aula' => 'Aula',
    'antec' => 'Antec', 'rapoo' => 'Rapoo', 'edifier' => 'Edifier',
    'dahua' => 'Dahua', 'hikvision' => 'Hikvision', 'transcend' => 'Transcend',
    'apacer' => 'Apacer', 'seagate' => 'Seagate', 'corsair' => 'Corsair',
    'logitech' => 'Logitech', 'fantech' => 'Fantech', 'machenike' => 'Machenike',
    'deepcool' => 'DeepCool', 'segotep' => 'Segotep', 'inplay' => 'Inplay',
    'trendsonic' => 'TrendSonic', 'steelseries' => 'SteelSeries',
    'pny' => 'PNY', 'tenda' => 'Tenda', 'intex' => 'Intex', 'doss' => 'Doss',
    'viewplus' => 'ViewPlus', 'durathon' => 'Durathon', 'raiku' => 'RAIKU',
];

/**
 * Multi-word brands, matched against the start of the name before the
 * single-token path runs ('Cooler Master' must not become brand 'Cooler').
 */
const PT_BRAND_PREFIXES = [
    'cooler master' => 'Cooler Master',
    'value top'     => 'Value Top',
    'id-cooling'    => 'ID-Cooling',
    'western digital' => 'Western Digital',
    'hyper x'       => 'HyperX',
    'hyperx'        => 'HyperX',
    'power logic'   => 'Power Logic',
];

/**
 * Regex tag rules. Each entry: tag key, tag value, the pattern that proves it,
 * and the category ids it applies to (null = every category).
 *
 * Order matters within a key: the FIRST rule that matches for a given key
 * wins, so put the more specific pattern first (NVMe before SSD, Mini-ITX
 * before ATX - '/\bATX\b/' would otherwise swallow 'Micro-ATX').
 */
function pt_tag_rules(): array {
    return [
        // ---- Universal: how the thing connects, who it's aimed at ----
        ['key' => 'connectivity', 'value' => 'wireless',  're' => '/\b(wireless|bluetooth|\bBT\b|2\.4\s?G(?:Hz)?\b|wi-?fi)\b/i', 'cats' => null],
        ['key' => 'connectivity', 'value' => 'wired',     're' => '/\b(wired|USB|ethernet|RJ45|3\.5\s?mm)\b/i',                  'cats' => null],
        ['key' => 'lighting',     'value' => 'rgb',       're' => '/\b(A?RGB|LED)\b/i',                                          'cats' => null],
        ['key' => 'audience',     'value' => 'gaming',    're' => '/\bgaming\b|\bgamer\b/i',                                     'cats' => null],

        // ---- Processors (1) ----
        ['key' => 'cpu_family', 'value' => 'Ryzen 9',  're' => '/Ryzen\s*9\b/i',  'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Ryzen 7',  're' => '/Ryzen\s*7\b/i',  'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Ryzen 5',  're' => '/Ryzen\s*5\b/i',  'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Ryzen 3',  're' => '/Ryzen\s*3\b/i',  'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Core i9',  're' => '/\b(?:Core\s*)?i9[\s-]/i', 'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Core i7',  're' => '/\b(?:Core\s*)?i7[\s-]/i', 'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Core i5',  're' => '/\b(?:Core\s*)?i5[\s-]/i', 'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Core i3',  're' => '/\b(?:Core\s*)?i3[\s-]/i', 'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Celeron',  're' => '/\bCeleron\b/i',  'cats' => [1, 7, 8]],
        ['key' => 'cpu_family', 'value' => 'Athlon',   're' => '/\bAthlon\b/i',   'cats' => [1, 7, 8]],
        ['key' => 'has_igpu',   'value' => 'yes',      're' => '/\b\d{3,4}G\b|\bAPU\b|\bGraphics\b/i', 'cats' => [1]],

        // ---- Sockets: shared by Processors (1), Motherboards (2), Coolers (24) ----
        ['key' => 'socket', 'value' => 'AM5',     're' => '/\bAM5\b/i',          'cats' => [1, 2, 24]],
        ['key' => 'socket', 'value' => 'AM4',     're' => '/\bAM4\b/i',          'cats' => [1, 2, 24]],
        ['key' => 'socket', 'value' => 'LGA1700', 're' => '/\b(LGA\s?)?1700\b/i','cats' => [1, 2, 24]],
        ['key' => 'socket', 'value' => 'LGA1200', 're' => '/\b(LGA\s?)?1200\b/i','cats' => [1, 2, 24]],
        ['key' => 'socket', 'value' => 'LGA1151', 're' => '/\b(LGA\s?)?1151\b/i','cats' => [1, 2, 24]],
        ['key' => 'socket', 'value' => 'LGA1150', 're' => '/\b(LGA\s?)?1150\b/i','cats' => [1, 2, 24]],
        ['key' => 'socket', 'value' => 'LGA1155', 're' => '/\b(LGA\s?)?1155\b/i','cats' => [1, 2, 24]],
        ['key' => 'socket', 'value' => 'FM2+',    're' => '/\bFM2\+?\b/i',       'cats' => [1, 2, 24]],

        // ---- Memory type: Motherboards (2), Memory (4), Laptops (8), Desktops (7) ----
        ['key' => 'ram_type', 'value' => 'DDR5', 're' => '/\bDDR5\b/i', 'cats' => [2, 4, 7, 8]],
        ['key' => 'ram_type', 'value' => 'DDR4', 're' => '/\bDDR4\b/i', 'cats' => [2, 4, 7, 8]],
        ['key' => 'ram_type', 'value' => 'DDR3', 're' => '/\bDDR3\b/i', 'cats' => [2, 4, 7, 8]],
        ['key' => 'module',   'value' => 'sodimm', 're' => '/\bSO-?DIMM\b/i', 'cats' => [4]],
        ['key' => 'module',   'value' => 'dimm',   're' => '/\bDIMM\b/i',     'cats' => [4]],

        // ---- Form factor: Motherboards (2), PC Case (23) ----
        ['key' => 'form_factor', 'value' => 'Mini-ITX',  're' => '/Mini-?ITX/i',  'cats' => [2, 23]],
        ['key' => 'form_factor', 'value' => 'Micro-ATX', 're' => '/(Micro-?ATX|M-?ATX)/i', 'cats' => [2, 23]],
        ['key' => 'form_factor', 'value' => 'ATX',       're' => '/\bATX\b/i',    'cats' => [2, 23, 6]],
        ['key' => 'tower',       'value' => 'mid',       're' => '/\bMid[\s-]?Tower\b/i',  'cats' => [23]],
        ['key' => 'tower',       'value' => 'full',      're' => '/\bFull[\s-]?Tower\b/i', 'cats' => [23]],

        // ---- Graphics Cards (3) ----
        ['key' => 'gpu_family', 'value' => 'RTX 40', 're' => '/RTX\s?40\d{2}/i', 'cats' => [3, 7, 8]],
        ['key' => 'gpu_family', 'value' => 'RTX 30', 're' => '/RTX\s?30\d{2}/i', 'cats' => [3, 7, 8]],
        ['key' => 'gpu_family', 'value' => 'RTX 20', 're' => '/RTX\s?20\d{2}/i', 'cats' => [3, 7, 8]],
        ['key' => 'gpu_family', 'value' => 'GTX 16', 're' => '/GTX\s?16\d{2}/i', 'cats' => [3, 7, 8]],
        ['key' => 'gpu_family', 'value' => 'GTX 10', 're' => '/GTX\s?10\d{2}/i', 'cats' => [3, 7, 8]],
        ['key' => 'gpu_family', 'value' => 'GT',     're' => '/\bGT\s?\d{3,4}\b/i', 'cats' => [3, 7, 8]],
        ['key' => 'gpu_family', 'value' => 'Radeon', 're' => '/\b(Radeon|RX\s?\d{3,4})\b/i', 'cats' => [3, 7, 8]],
        ['key' => 'vram_type',  'value' => 'GDDR6X', 're' => '/GDDR6X/i', 'cats' => [3]],
        ['key' => 'vram_type',  'value' => 'GDDR6',  're' => '/GDDR6\b/i','cats' => [3]],
        ['key' => 'vram_type',  'value' => 'GDDR5',  're' => '/G?DDR5\b/i','cats' => [3]],

        // ---- Storage (5) ----
        ['key' => 'drive_type', 'value' => 'NVMe', 're' => '/\b(NVMe|M\.2)\b/i', 'cats' => [5]],
        ['key' => 'drive_type', 'value' => 'SSD',  're' => '/\bSSD\b/i',         'cats' => [5]],
        ['key' => 'drive_type', 'value' => 'HDD',  're' => '/\b(HDD|Hard\s?Disk)\b/i', 'cats' => [5]],
        ['key' => 'drive_type', 'value' => 'flash','re' => '/\b(Flash\s?Drive|USB\s?Stick|OTG)\b/i', 'cats' => [5]],
        ['key' => 'interface',  'value' => 'SATA', 're' => '/\bSATA\b/i',        'cats' => [5]],
        ['key' => 'portable',   'value' => 'yes',  're' => '/\b(Portable|External)\b/i', 'cats' => [5]],

        // ---- Power Supply (6) ----
        ['key' => 'psu_rating', 'value' => 'Platinum', 're' => '/\bPlatinum\b/i', 'cats' => [6]],
        ['key' => 'psu_rating', 'value' => 'Gold',     're' => '/\bGold\b/i',     'cats' => [6]],
        ['key' => 'psu_rating', 'value' => 'Bronze',   're' => '/\bBronze\b/i',   'cats' => [6]],
        ['key' => 'modular',    'value' => 'yes',      're' => '/\b(Modular|G\.M\b)/i', 'cats' => [6]],

        // ---- Keyboards & Combos (9) ----
        ['key' => 'switch_type', 'value' => 'mechanical', 're' => '/\bMechanical\b/i', 'cats' => [9]],
        ['key' => 'switch_type', 'value' => 'membrane',   're' => '/\bMembrane\b/i',   'cats' => [9]],
        ['key' => 'kit',         'value' => 'combo',      're' => '/\b(Combo|\+\s?Mouse|Keyboard and Mouse)\b/i', 'cats' => [9]],

        // ---- Headsets (12) / Speakers (11) ----
        ['key' => 'audio_form', 'value' => 'over-ear',  're' => '/\bOver[\s-]?Ear\b/i',  'cats' => [12]],
        ['key' => 'audio_form', 'value' => 'in-ear',    're' => '/\b(In[\s-]?Ear|Earbud|TWS)\b/i', 'cats' => [12]],
        ['key' => 'audio_ch',   'value' => 'stereo',    're' => '/\bStereo\b/i',         'cats' => [11, 12]],
        ['key' => 'audio_ch',   'value' => 'surround',  're' => '/\b(7\.1|5\.1|Surround)\b/i', 'cats' => [11, 12]],
        ['key' => 'has_mic',    'value' => 'yes',       're' => '/\b(Mic|Microphone|Headset)\b/i', 'cats' => [12]],

        // ---- Printers (28) ----
        ['key' => 'print_tech', 'value' => 'laser',      're' => '/\b(Laser|LaserJet)\b/i', 'cats' => [28]],
        ['key' => 'print_tech', 'value' => 'dot-matrix', 're' => '/\bDot\s?Matrix\b/i',     'cats' => [28]],
        ['key' => 'print_tech', 'value' => 'inkjet',     're' => '/\b(Ink\s?Tank|EcoTank|Smart\s?Tank|Inkjet|DeskJet)\b/i', 'cats' => [28]],
        ['key' => 'print_fn',   'value' => 'all-in-one', 're' => '/\b(All[\s-]?in[\s-]?One|MFP|AIO|DCP|Multifunction)\b/i', 'cats' => [28]],
        ['key' => 'duplex',     'value' => 'yes',        're' => '/\bDuplex\b|\bL2\d{3}D\b/i', 'cats' => [28]],

        // ---- Ink & Toner (29) ----
        ['key' => 'ink_color', 'value' => 'black',   're' => '/\bBlack\b|\bBK\b/i', 'cats' => [29]],
        ['key' => 'ink_color', 'value' => 'cyan',    're' => '/\bCyan\b/i',         'cats' => [29]],
        ['key' => 'ink_color', 'value' => 'magenta', 're' => '/\bMagenta\b/i',      'cats' => [29]],
        ['key' => 'ink_color', 'value' => 'yellow',  're' => '/\bYellow\b/i',       'cats' => [29]],
        ['key' => 'ink_color', 'value' => 'tri-color', 're' => '/\b(Tri-?colou?r|Colou?r)\b/i', 'cats' => [29]],
        ['key' => 'ink_form',  'value' => 'bottle',    're' => '/\bBottle\b/i',     'cats' => [29]],
        ['key' => 'ink_form',  'value' => 'cartridge', 're' => '/\bCartridge\b/i',  'cats' => [29]],
        ['key' => 'ink_form',  'value' => 'toner',     're' => '/\bToner\b/i',      'cats' => [29]],

        // ---- Networking (30) ----
        ['key' => 'net_device', 'value' => 'router',    're' => '/\bRouter\b/i',                'cats' => [30]],
        ['key' => 'net_device', 'value' => 'switch',    're' => '/\bSwitch\b/i',                'cats' => [30]],
        ['key' => 'net_device', 'value' => 'extender',  're' => '/\b(Extender|Repeater)\b/i',   'cats' => [30]],
        ['key' => 'net_device', 'value' => 'mesh',      're' => '/\bMesh\b/i',                  'cats' => [30]],
        ['key' => 'net_device', 'value' => 'adapter',   're' => '/\b(Adapter|Dongle|PCIe\s?WiFi)\b/i', 'cats' => [30]],
        ['key' => 'net_device', 'value' => 'cabling',   're' => '/\b(RJ45|Connector|Cable|Crimp)\b/i', 'cats' => [30]],
        ['key' => 'wifi_class', 'value' => 'AX',        're' => '/\b(AX\d{3,4}|WiFi\s?6)\b/i',  'cats' => [30]],
        ['key' => 'wifi_class', 'value' => 'AC',        're' => '/\bAC\d{3,4}\b/i',             'cats' => [30]],
        ['key' => 'band',       'value' => 'dual-band', 're' => '/\bDual[\s-]?Band\b/i',        'cats' => [30]],

        // ---- CCTV & Security (27) ----
        ['key' => 'cctv_device', 'value' => 'camera',   're' => '/\bCamera\b/i',            'cats' => [27]],
        ['key' => 'cctv_device', 'value' => 'recorder', 're' => '/\b(DVR|NVR)\b/i',         'cats' => [27]],
        ['key' => 'cctv_device', 'value' => 'kit',      're' => '/\bKit\b/i',               'cats' => [27]],
        ['key' => 'cctv_form',   'value' => 'bullet',   're' => '/\bBullet\b/i',            'cats' => [27]],
        ['key' => 'cctv_form',   'value' => 'dome',     're' => '/\bDome\b/i',              'cats' => [27]],
        ['key' => 'placement',   'value' => 'outdoor',  're' => '/\bOutdoor\b/i',           'cats' => [27]],
        ['key' => 'placement',   'value' => 'indoor',   're' => '/\bIndoor\b/i',            'cats' => [27]],

        // ---- CPU Cooler (24) ----
        ['key' => 'cooler_type', 'value' => 'liquid', 're' => '/\b(Liquid|AIO|Water)\b/i', 'cats' => [24]],
        ['key' => 'cooler_type', 'value' => 'air',    're' => '/\b(Air|Tower|Heatsink)\b/i','cats' => [24]],

        // ---- Laptops (8) / Desktops (7): OS and panel, both from the description ----
        ['key' => 'os',      'value' => 'Windows 11', 're' => '/Windows\s*11/i',        'cats' => [7, 8]],
        ['key' => 'os',      'value' => 'Windows 10', 're' => '/Windows\s*10/i',        'cats' => [7, 8]],
        ['key' => 'os',      'value' => 'DOS',        're' => '/\b(Free\s?DOS|No\s?OS)\b/i', 'cats' => [7, 8]],
        ['key' => 'display', 'value' => 'FHD',        're' => '/\b(FHD|1920\s?x\s?1080|Full\s?HD)\b/i', 'cats' => [7, 8, 25]],
        ['key' => 'display', 'value' => 'HD',         're' => '/\bHD\b/i',               'cats' => [7, 8, 25]],
    ];
}

/**
 * Numeric specs that need arithmetic rather than a literal match, so they
 * can't live in the regex table above. Each returns null when the product's
 * text doesn't state the spec - never a guess.
 *
 * Banded for the same reason as capacity: a 15.6" and a 15" laptop are the
 * same shelf, and an exact-value tag would match almost nothing.
 */
function pt_extract_numeric_tags(string $text, int $catId): array {
    $tags = [];

    // Screen size - laptops (8), desktops/AIOs (7), monitors (25).
    $inches = null;
    if (in_array($catId, [7, 8, 25], true)
        && preg_match('/(\d{2}(?:\.\d)?)\s*(?:"|inch|-inch|”)/iu', $text, $m)) {
        $inches = (float)$m[1];
    }
    // Laptops whose description is just the name repeated still state the size
    // in the model code - 'IdeaPad Slim 5 14ACH5', 'HP 15-EC2087AX', '15s-FQ'.
    // Restricted to 13-17 and to category 8, so it can't grab a generation
    // number ('A11M') or a monitor's refresh rate.
    if ($inches === null && $catId === 8
        && preg_match('/\b(1[3-7])s?(?:[A-Z]{2,}\d|[-\s][A-Z0-9]{4,}|\b)/', (string)$text, $m)) {
        $inches = (float)$m[1];
    }
    if ($inches !== null && $inches >= 10 && $inches <= 34) {
        if ($inches <= 13.9)      { $band = '13-inch'; }
        elseif ($inches <= 14.9)  { $band = '14-inch'; }
        elseif ($inches <= 16.4)  { $band = '15-16-inch'; }
        elseif ($inches <= 18)    { $band = '17-inch'; }
        else                      { $band = '20-inch+'; }
        $tags[] = ['key' => 'screen_size', 'value' => $band, 'confidence' => 85];
    }

    // PSU wattage (6). '80PLUS'/'80 PLUS' is a certification, not a rating, so
    // it must not be read as 80W - require 3-4 digits and a sane PSU range.
    if ($catId === 6) {
        $watts = null;
        if (preg_match('/\b(\d{3,4})\s*W\b/i', $text, $m)) {
            $watts = (int)$m[1];
        } elseif (preg_match('/\b(\d{3,4})\b(?!\s*PLUS)/i', $text, $m)) {
            $watts = (int)$m[1];
        } elseif (preg_match('/[A-Z]{1,4}-?(\d{3,4})\b/i', $text, $m)) {
            // Wattage baked into the model name ('GS700', 'CV550', 'B550').
            // Only safe because category 6 holds PSUs and nothing else - the
            // same pattern would read a board's B550 chipset as 550 watts.
            $watts = (int)$m[1];
        }
        if ($watts !== null && $watts >= 300 && $watts <= 2000) {
            if ($watts <= 450)      { $band = '<=450W'; }
            elseif ($watts <= 600)  { $band = '500-600W'; }
            elseif ($watts <= 750)  { $band = '650-750W'; }
            elseif ($watts <= 1000) { $band = '800-1000W'; }
            else                    { $band = '1000W+'; }
            $tags[] = ['key' => 'psu_watt', 'value' => $band, 'confidence' => 80];
        }
    }

    // Laptops state RAM and storage together ('8GB RAM, 512GB SSD'), so each
    // needs its own anchored pattern - the generic capacity_band extractor
    // would collapse the two into one meaningless number.
    if ($catId === 8) {
        if (preg_match('/(\d+)\s*GB\s*(?:DDR\d\s*)?RAM/i', $text, $m)) {
            $ram = (int)$m[1];
            $tags[] = ['key' => 'laptop_ram', 'value' => $ram . 'GB', 'confidence' => 85];
        }
        if (preg_match('/(\d+)\s*(GB|TB)\s*(SSD|HDD|NVMe|eMMC)/i', $text, $m)) {
            $size = (int)$m[1] * (strtoupper($m[2]) === 'TB' ? 1024 : 1);
            $band = $size <= 256 ? '256GB' : ($size <= 512 ? '512GB' : '1TB+');
            $tags[] = ['key' => 'laptop_storage', 'value' => $band, 'confidence' => 85];
        }
    }

    // Memory kit speed (4) - DDR4 3200, DDR5 5600.
    if ($catId === 4 && preg_match('/DDR[345]\s*[-\s]?(\d{4})\b/i', $text, $m)) {
        $mhz = (int)$m[1];
        if ($mhz >= 1000 && $mhz <= 9000) {
            $tags[] = ['key' => 'ram_speed', 'value' => $mhz . 'MHz', 'confidence' => 85];
        }
    }

    return $tags;
}

/** Per-key weight when scoring two products' shared tags (see pt_tag_weights). */
function pt_tag_weights(): array {
    return [
        // Hard compatibility - two AM4 boards are genuinely interchangeable.
        'socket' => 10, 'ram_type' => 8, 'form_factor' => 7, 'module' => 7,
        'interface' => 6, 'drive_type' => 8, 'print_tech' => 8, 'ink_form' => 8,
        'net_device' => 8, 'cctv_device' => 8, 'cooler_type' => 7,
        'switch_type' => 7, 'gpu_family' => 7, 'cpu_family' => 7,
        // Strong preference signals.
        'brand' => 6, 'price_tier' => 5, 'capacity_band' => 5, 'wifi_class' => 5,
        'psu_rating' => 5, 'vram_type' => 4, 'ink_color' => 4, 'cctv_form' => 4,
        'audio_form' => 4, 'audio_ch' => 4, 'print_fn' => 4, 'tower' => 4,
        'psu_watt' => 6, 'screen_size' => 6, 'laptop_ram' => 4,
        'laptop_storage' => 4, 'ram_speed' => 4, 'os' => 3, 'display' => 3,
        // Soft/nice-to-have.
        'connectivity' => 3, 'audience' => 3, 'lighting' => 2, 'band' => 2,
        'placement' => 3, 'portable' => 3, 'modular' => 2, 'duplex' => 2,
        'kit' => 2, 'has_mic' => 1, 'has_igpu' => 2,
    ];
}

/** Weight for a tag key, defaulting low so a new rule can't quietly dominate. */
function pt_weight_for(string $key): int {
    return pt_tag_weights()[$key] ?? 2;
}

/**
 * Canonical brand from a product name. Brand is the single highest-value
 * feature available here (someone buying Epson ink buys Epson ink again) and
 * this catalog puts it in the first token of ~every name.
 */
function pt_extract_brand(string $name): ?string {
    $clean = trim($name);
    if ($clean === '') { return null; }

    $lower = strtolower($clean);
    foreach (PT_BRAND_PREFIXES as $prefix => $canonical) {
        if (strpos($lower, $prefix) === 0) { return $canonical; }
    }

    $first = preg_split('/\s+/', $clean)[0] ?? '';
    $first = trim($first, ",.:;()[]");
    if ($first === '') { return null; }

    $key = strtolower($first);
    if (isset(PT_BRAND_ALIASES[$key])) { return PT_BRAND_ALIASES[$key]; }

    // Unknown brand: keep it only if it looks like a name rather than a spec
    // ('RJ45', '4CH', '8GB' are not brands).
    if (preg_match('/^\d/', $first) || strlen($first) < 3) { return null; }
    if (preg_match('/^(mini|cctv|value|standard|gaming|usb|pc|all)$/i', $first)) { return null; }
    // Product lines that lead the name but aren't the manufacturer.
    if (preg_match('/^(ryzen|core|celeron|athlon|wraith|radeon|geforce)$/i', $first)) { return null; }
    // Model-number shaped ('LC-8700BTXN', 'SE-214-XT'): letters and digits run
    // together. A real brand token is letters only.
    if (preg_match('/\d/', $first) && preg_match('/[A-Za-z]/', $first)) { return null; }

    // Preserve an existing all-caps or CamelCase spelling, else Title Case it.
    return preg_match('/[A-Z]/', substr($first, 1)) ? $first : ucfirst($key);
}

/**
 * Storage/memory capacity, normalised to GB then bucketed. Raw capacities are
 * too sparse to match on (a 480GB and a 500GB SSD are the same shelf), so the
 * tag is a band, not the number.
 */
function pt_extract_capacity_band(string $text, array $cats): ?string {
    if (!array_intersect($cats, [4, 5])) { return null; }

    $gb = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s*TB\b/i', $text, $m)) {
        $gb = (float)$m[1] * 1024;
    } elseif (preg_match('/(\d+)\s*GB\s*x\s*(\d+)/i', $text, $m)) {
        $gb = (float)$m[1] * (int)$m[2];   // '8GB x 2' kits
    } elseif (preg_match('/(\d+)\s*GB\b/i', $text, $m)) {
        $gb = (float)$m[1];
    }
    if ($gb === null || $gb <= 0) { return null; }

    if ($gb <= 8)    { return '<=8GB'; }
    if ($gb <= 16)   { return '16GB'; }
    if ($gb <= 32)   { return '32GB'; }
    if ($gb <= 256)  { return '128-256GB'; }
    if ($gb <= 512)  { return '256-512GB'; }
    if ($gb <= 1024) { return '1TB'; }
    return '2TB+';
}

/**
 * Price tier, computed against the product's OWN category rather than the
 * whole catalog - a PHP 5,000 motherboard and a PHP 5,000 headset sit at
 * opposite ends of their shelves, so a global budget/premium split would be
 * actively misleading.
 *
 * $categoryPrices: sorted list of active prices in that category.
 */
function pt_price_tier(float $price, array $categoryPrices): ?string {
    $n = count($categoryPrices);
    if ($n < 4) { return null; }   // too few to rank meaningfully

    $below = 0;
    foreach ($categoryPrices as $p) {
        if ($p < $price) { $below++; }
    }
    $percentile = $below / $n;

    if ($percentile < 0.33) { return 'budget'; }
    if ($percentile < 0.70) { return 'mid'; }
    return 'premium';
}

/**
 * All derived tags for one product.
 *
 * $product needs: id, name, description, price, category_id.
 * $categoryPrices is the price list for that product's category (pass [] to
 * skip the price_tier tag).
 *
 * Returns a list of ['key' => .., 'value' => .., 'confidence' => ..].
 */
function pt_extract_tags(array $product, array $categoryPrices = []): array {
    $catId = (int)($product['category_id'] ?? 0);
    $text  = (string)($product['name'] ?? '') . ' ' . (string)($product['description'] ?? '');
    $tags  = [];

    $brand = pt_extract_brand((string)($product['name'] ?? ''));
    if ($brand !== null) {
        $tags[] = ['key' => 'brand', 'value' => $brand, 'confidence' => 90];
    }

    $tier = pt_price_tier((float)($product['price'] ?? 0), $categoryPrices);
    if ($tier !== null) {
        // Fully deterministic from price - no guesswork, so full confidence.
        $tags[] = ['key' => 'price_tier', 'value' => $tier, 'confidence' => 100];
    }

    $band = pt_extract_capacity_band($text, [$catId]);
    if ($band !== null) {
        $tags[] = ['key' => 'capacity_band', 'value' => $band, 'confidence' => 85];
    }

    foreach (pt_extract_numeric_tags($text, $catId) as $numericTag) {
        $tags[] = $numericTag;
    }

    // First matching rule per key wins, so specific patterns (NVMe, Micro-ATX)
    // registered earlier beat the broader ones (SSD, ATX) below them.
    $claimed = [];
    foreach (pt_tag_rules() as $rule) {
        if ($rule['cats'] !== null && !in_array($catId, $rule['cats'], true)) { continue; }
        if (isset($claimed[$rule['key']])) { continue; }
        if (!preg_match($rule['re'], $text)) { continue; }
        $claimed[$rule['key']] = true;
        $tags[] = ['key' => $rule['key'], 'value' => $rule['value'], 'confidence' => 80];
    }

    return $tags;
}

/**
 * Writes a product's derived tags, leaving admin-entered rows (confidence 100
 * and not machine-written) alone. Returns the number of tags stored.
 *
 * Deletes only what this tagger owns, so re-running the backfill after a rule
 * change refreshes cleanly without wiping manual corrections.
 */
function pt_store_tags(PDO $pdo, int $productId, array $tags): int {
    $del = $pdo->prepare('DELETE FROM product_tags WHERE product_id = ? AND confidence < 100');
    $del->execute([$productId]);

    $ins = $pdo->prepare(
        'INSERT INTO product_tags (product_id, tag_key, tag_value, confidence)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE confidence = GREATEST(confidence, VALUES(confidence))'
    );
    $stored = 0;
    foreach ($tags as $t) {
        $ins->execute([$productId, $t['key'], $t['value'], $t['confidence']]);
        $stored++;
    }
    return $stored;
}

/** Sorted active prices per category id, for pt_price_tier(). */
function pt_category_price_map(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT category_id, price FROM products
         WHERE status = 'active' AND category_id IS NOT NULL
         ORDER BY category_id, price"
    )->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['category_id']][] = (float)$r['price'];
    }
    return $map;
}
