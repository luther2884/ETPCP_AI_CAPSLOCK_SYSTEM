<?php
// tools/seed_products_from_list.php
// One-time seeder to create product cards from the user's list and map them to categories

require_once __DIR__ . '/../config/functions.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
}

$confirmed = ($_GET['confirm'] ?? $_POST['confirm'] ?? '') === '1';
if (!$confirmed) {
    echo "Seeder is dry-running (no DB writes). Append ?confirm=1 to actually write.\n\n";
}

/**
 * Data model: category_name => [ [label, qty], ... ]
 */
$DATA = [
    // PROCESSORS / MINI PCs
    'Processors' => [
        ['INTEL NUC Celeron NUC7CJYSN Mini PC', 2],
        ['INTEL NUC CORE i5 10th Gen A Mini PC Kit', 1],
        ['INTEL NUC i5 Mini PC kit', 1],
        ['INTEL NUC i3 Mini PC kit', 2],
        ['INTEL CELERON G1840 PROCESSOR', 3],
        ['INTEL PENTIUM G3250 PROCESSOR', 3],
        ['INTEL PENTIUM G3260 PROCESSOR', 3],
        ['INTEL CORE I3-12100 PROCESSOR', 3],
        ['INTEL CORE I3-10105 PROCESSOR', 5],
        ['INTEL CORE I5-11400F PROCESSOR', 2],
        ['INTEL CORE I5-12400F PROCESSOR', 2],
        ['INTEL CORE I5-12400 PROCESSOR', 2],
        ['INTEL CORE I5-9400F PROCESSOR', 2],
        ['INTEL CORE I5-13400 PROCESSOR', 2],
        ['Machenike Machreator Mini PC 2 i5-12450H', 1],
        ['Machenike Machreator Mini PC 2 i7-12650H', 1],
        ['AMD Ryzen 7 5700G PROCESSOR', 3],
        ['AMD Ryzen 5 5600G PROCESSOR', 1],
        ['AMD Ryzen 5 5500 PROCESSOR', 2],
        ['AMD Ryzen 5 3600 PROCESSOR', 3],
        ['AMD Ryzen 5 2600 PROCESSOR', 3],
        ['AMD Ryzen 3 3200G PROCESSOR', 1],
        ['AMD Ryzen 5 4600G PROCESSOR', 5],
        ['AMD Athlon 200GE PROCESSOR', 1],
        ['AMD Ryzen 3 PRO 4350G Tray type Processor', 7],
    ],

    'Motherboards' => [
        ['Gigabyte B550M DS3H Motherboard', 1],
        ['Gigabyte B450M DS3H Motherboard', 1],
        ['Gigabyte B450M S2H V2 Motherboard', 1],
        ['Gigabyte AB350M-HD3 Motherboard', 2],
        ['Gigabyte A320M-S2H V2 Motherboard', 1],
        ['Gigabyte B760M H DDR4 Motherboard', 5],
        ['Gigabyte A320M-S2H Motherboard', 5],
        ['MSI B450M Mortar Max Motherboard', 2],
        ['Asus TUF B450M-Pro II Motherboard', 1],
        ['Aorus B450 AORUS M Motherboard', 2],
        ['Asus Prime B365M-A Motherboard', 1],
        ['Asus TUF Z370-PLUS Motherboard', 2],
        ['BIOSTAR A75MG Motherboard', 2],
        ['ASUS A68HM-K Motherboard', 2],
        ['MSI H110M PRO-VH Motherboard', 4],
        ['MSI H110M PRO-VD Motherboard', 4],
        ['GIGABYTE H110M - S2 Motherboard', 1],
        ['ASUS H110M-D Motherboard', 2],
        ['GIGABYTE H110M-S2 Motherboard', 2],
        ['GIGABYTE H110M-H Motherboard', 2],
        ['GIGABYTE B150M-D3H Motherboard', 1],
        ['DURATHON 2 H110M4-C2 SERIES Motherboard', 2],
        ['ASUS EZ-B150M-V5 Motherboard', 2],
        ['MSI B150M BAZOOKA Motherboard', 2],
        ['GIGABYTE Z270M-D3H Motherboard', 1],
        ['ASUS PRIME B250M-D Motherboard', 2],
        ['ASUS EX-H310M-V3 R2.0 Motherboard', 5],
        ['MSI H310M PRO-VDH PLUS Motherboard', 5],
        ['MSI A320M PRO-VD/S Motherboard', 2],
        ['GIGABYTE A320M-DS2 Motherboard', 2],
        ['PRIME A320M-K Motherboard', 5],
        ['MSI BAZOOKA B350M Motherboard', 2],
        ['MSI B350 PRO-VD PLUS Motherboard', 4],
        ['MSI TOMAWAK B350 Motherboard', 1],
        ['MSI B360M GAMING PLUS Motherboard', 2],
        ['MSI B360 GAMING PRO CARBON Motherboard', 2],
        ['ROG STRIX B360-F GAMING Motherboard', 2],
        ['TUF B360M-E GAMING Motherboard', 2],
        ['MSI Z370 GAMING PLUS Motherboard', 2],
        ['ASUS PRIME B365-A Motherboard', 1],
        ['ASUS TUF B365- PLUS GAMING Motherboard', 2],
        ['PRIME Z370-A Motherboard', 2],
        ['MSI B450M PRO -VDH PLUS Motherboard', 1],
        ['ASUS ROG STRIX Z370-G GAMING (WIFI) Motherboard', 2],
        ['ASUS ROG STRIX Z370-F GAMING Motherboard', 1],
        ['MSI B450M PRO-M2 V2 Motherboard', 2],
        ['ASUS TUF B450M-PLUS GAMING Motherboard', 2],
        ['ASUS TUF B450M-PRO GAMING Motherboard', 2],
        ['ASUS TUF GAMING B450M - PRO II Motherboard', 1],
        ['MSI B450 TOMAHAWK MAX Motherboard', 1],
        ['MSI B350 TOMAHAWK Motherboard', 1],
        ['MSI B450 TOMAHAWK MAX Motherboard', 1],
        ['ASUS EX-H510M-V3 Motherboard', 1],
        ['GIGABYTE B460M POWER Motherboard', 2],
        ['ASUS PRIME B450- A II Motherboard', 1],
        ['MSI PRO SERIES PRO H510M-B Motherboard', 1],
        ['ASUS PRIME A520M-K Motherboard', 2],
        ['MSI MAG B550M BAZOOKA Motherboard', 2],
        ['ASROCK B550M-HDV Motherboard', 1],
        ['ASUS PRIME A520M-K Motherboard', 1],
        ['ASUS PRIME H610M-K D4 Motherboard', 5],
        ['MSI PRO H610M-E DDR4 Motherboard', 2],
        ['GIGABYTE H610M K DDR4 Motherboard', 4],
    ],

    'Graphics Cards' => [
        ['Gigabyte RX570', 1],
        ['Gigabyte GTX1050Ti', 3],
        ['PowerColor RX 6700 XT 12GB GDDR6', 1],
        ['Asus TUF GTX 1660Ti 6GB OC GDDR6', 2],
        ['Asus TUF GTX 1650 4GB OC GDDR6', 2],
        ['ASUS Phoenix GTX 1660 6GB OC GDDR5', 1],
        ['MSI Ventus RTX 2060 6GB GDDR6', 1],
        ['Asus GTX 1050Ti 4GB DDR5', 2],
        ['INNO3D GTX 1050Ti 4GB DDR5', 2],
        ['INNO3D GTX 1660 6GB GDDR5', 1],
        ['INNO3D GTX 1660 Super 6GB GDDR6', 1],
        ['INNO3D GTX 1650 4GB GDDR6', 1],
        ['Gigabyte RTX 4070 12GB GDDR6X', 1],
        ['Gigabyte RTX 4060Ti 8GB GDDR6', 1],
        ['Gigabyte RTX 4060 8GB GDDR6', 1],
        ['Gigabyte RTX 4060Ti 16GB GDDR6', 1],
        ['MSI GT 710 2GB DDR3', 2],
        ['INNO3D GT 730 2GB SDDR3', 4],
        ['INNO3D GT 1030 2GB GDDR5', 2],
        ['PALIT GTX 1050Ti 4GB GDDR5', 2],
        ['INNO3D GTX 1660 Super 6GB GDDR6', 1],
        ['Palit RTX 3050 8GB GDDR6', 2],
        ['Palit GTX 1050Ti 4GB DDR5', 3],
        ['Asus GTX 1050 2GB GDDR5', 1],
        ['Afox GT 730 4096MB DDR3', 2],
        ['Asus GT 710 2GB DDR3', 2],
    ],

    'Memory' => [
        ['Hp 8GB PC4 3200 U-Dimm Memory', 2],
        ['TeamGroup T.Force 8GB DDR4 3200 DIMM Memory', 5],
        ['Transcend 16GB DDR4 3200 U-DIMM Memory', 5],
        ['Transcend 4GB DDR4 2666 U-DIMM Memory', 10],
        ['Transcend 4GB DDR4 3200 U-DIMM Memory', 10],
        ['Team Group Elite 8GB DDR4 2666 DIMM Memory', 6],
        ['G.Skill 16GBx2 DDR4 3600 DIMM Memory', 3],
        ['Kingston 8GB DDR4 3200 DIMM Memory', 9],
        ['Kingston Fury 8GB DDR4 2666 DIMM Memory', 10],
        ['Hyper X fury 16GB DDR4 3200 U-DIMM Memory', 10],
        ['Hyper X 16GB DDR4 2666 DIMM Memory', 8],
        ['Hyper X 16GB DDR4 2400 DIMM Memory', 2],
        ['Hyper X HX432C16FB4/16 16GB DDR4 3200 DIMM MEMORY', 10],
        ['Hyper X HX426C16FB3/8 8GB DDR4 2666 DIMM Memory', 8],
        ['GSkill Aegis 4GB DDR4 2400 DIMM Memory', 10],
        ['ADATA 16GB DDR4 3200 SO-DIMM Memory', 3],
        ['ADATA 16GB DDR4 3200 U-DIMM Memory', 2],
        ['Dahua 8GB DDR4 3200 DIMM Memory', 10],
        // SO-DIMM
        ['Kingston 8GB DDR4 2400 SODIMM Memory', 5],
        ['Kingston 16GB DDR4 2666 SODIMM Memory', 10],
        ['Kingston 8GB DDR4 2666 SODIMM Memory', 9],
        ['Crucial 4GB DDR4 2400 SODIMM Memory', 1],
        ['Hp 16GB DDR4 2666 SODIMM Memory', 2],
        ['Kingmax 4GB DDR3 1600 SODIMM Memory', 2],
        ['Transcend 8GB DDR4 2666 SODIMM Memory', 4],
        ['Transcend 4GB DDR4 3200 SODIMM Memory', 5],
        ['Kingston 8GB DDR4 3200 SODIMM Memory', 5],
        ['Kingston 16GB DDR4 3200 SODIMM Memory', 1],
        ['Apacer 8GB DDR4 2400 SODIMM Memory', 4],
        ['Apacer 4GB DDR4 2400 SODIMM Memory', 5],
        ['Apacer 4GB DDR4 2666 SODIMM Memory', 10],
    ],

    'Power Supply' => [
        ['Antec ATOM B550 Bronze PSU', 2],
        ['Antec 550W 80PLUS PSU', 3],
        ['Antec NeoEco Ne 650 G.M psu', 2],
        ['Antec NeoEco Ne 750 G.M psu', 2],
        ['Antec NeoEco Ne 850 G.M psu', 2],
        ['Huntkey GS700 PSU', 2],
        ['Huntkey GS600 PSU', 2],
        ['Huntkey GS500 PSU', 5],
        ['Cooler Master 750 Bronze PSU', 2],
        ['Cooler Master 650 Bronze PSU', 1],
        ['Cooler Master 550 Bronze PSU', 1],
        ['Power logic ATX PM-600 PSU', 3],
        ['Corsair CV550 ATX PSU', 1],
        ['Orion ATX-700W PSU', 3],
        ['LC-8700BTXN Standard PSU', 2],
    ],

    // Keyboards & Combos
    'Keyboards & Combos' => [
        ['Aula Wind F2088 pro Keyboard', 2],
        ['Machenike K500B-B87 Mechanical Keyboard', 1],
        ['Aula L2098 Mechanical Keyboard', 3],
        ['Aula T640 Mechanical Keyboard', 3],
        ['Aula F2058 Mechanical Keyboard', 1],
        ['Aula S2022 Mechanical Keyboard', 2],
        ['Aula F3018 Mechanical Keyboard', 2],
        ['Aula F2066-II Mechanical Keyboard', 1],
        ['Aula AC203 Combo keyboard/Mouse', 9],
        ['Aula F3061 Mini Keyboard', 1],
        ['Rapoo 8000M wireless combo keyboard/Mouse', 2],
        ['Rapoo X1800S wireless combo keyboard/Mouse', 2],
        ['Rapoo NK2600 Wired keyboard', 5],
        ['Rapoo NX1600 Wired Combo keyboard/Mouse', 5],
        ['Rapoo NX1700 wired USB Combo keyboard/Mouse', 4],
        ['Fantech Maxpower MK853 Mechanical Keyboard', 2],
        ['Redragon Lakshmi K606R Mechanical Keyboard', 2],
        ['Redragon KUMARA K552RGB-1 Mechanical Keyboard', 1],
        ['Asus RA04 TUF Gaming K1 Gaming Keyboard', 3],
        ['A4tech FKS11 FStyler wired Keyboard', 5],
        ['A4tech FBK11 FStyler wireless Keyboard', 4],
        ['Delux KM36U Mechanical Keyboard', 1],
        ['Intex IT-1017RB USB Keyboard', 2],
        ['A4tech KRS-8572 Combo keyboard/Mouse', 2],
        ['Aula AC101 Wired Combo keyboard/Mouse', 8],
        ['Aula AK205 Wired Keyboard', 3],
        ['Redragon Mitra K551-1 Mechanical Keyboard', 2],
        ['Redragon Mitra K551RGB-1 Mechanical Keyboard', 2],
        ['Redragon Dark Avenger K568RGB-1 Mechanical Keyboard', 1],
        ['Redragon Gaming essentials K551RGB-BA 2in1 keyboard/Mouse Combo', 2],
        ['Redragon S107 keyboard/Mouse Combo set', 2],
        ['Redragon SHRAPNEL K589RGB Mechanical Keyboard', 2],
        ['Redragon Dyausz K509RGB Mechanical Keyboard', 2],
        ['A4tech KRS-85 Keyboard', 6],
        ['Machenike CKM500 wireless Keyboard', 4],
        ['Machenike K520-B108 Mechanical Keyboard', 5],
    ],

    // Laptop Stands
    'Laptop Stands' => [
        ['Aula Wind F61 Laptop Holder', 3],
    ],

    // Speakers
    'Speakers' => [
        ['Aula N-189 Speaker', 5],
        ['Aula N-107 Speaker', 4],
        ['Mini Speaker A10 wireless', 10],
        ['Doss DS-1209 BT Speaker', 3],
        ['Edifier G1000 BT Speaker', 2],
        ['Edifier MP260 BT Speaker', 2],
        ['Edifier R19U Multimedia Speaker', 2],
        ['Asimom DS-1198 Speaker', 2],
        ['Aula N521 Gaming Speaker', 4],
        ['Aula N69 Gaming Speaker', 6],
    ],

    // Headsets
    'Headsets' => [
        ['Aula F606 Headset', 5],
        ['Aula S600 Headset', 3],
        ['Aula S605 Headset', 2],
        ['Rapoo H100 wired Headset', 4],
        ['Rapoo H150S Stereo Headset', 3],
        ['Rapoo H120 Stereo Headset', 5],
        ['AULA S503 Headset', 2],
        ['Aula S505 Headset', 5],
        ['HP H500GS Headset', 2],
        ['Edifier USB K800 Headset', 2],
        ['Redragon Ares H120 Headset', 3],
        ['Steelseries 61504 Arctis 5 Headset', 2],
        ['Edifier K830 Headphone/Headset', 2],
        ['Edifier G2II Headset', 2],
        ['A4tech Bloody G520 Headset', 2],
        ['A4tech Bloody G500 Headset', 3],
        ['Redragon Lamia2 H320RGB-1 Headset', 2],
        ['Edifier W800BT Plus Headset Black', 2],
        ['Edifier W800BT Plus Headset White', 2],
        ['Redragon Minos H210 Headset', 2],
        ['RAPOO Vpro VH200 Headset', 2],
        ['Redragon Mento H270-RGB Headset', 1],
        ['Lenovo H300 Headset L02', 2],
        ['Logitech H151 Stereo Headset', 4],
        ['Logitech H150 Stereo Headset', 4],
        ['Logitech H111 Stereo Headset', 4],
        ['Plantronics Poly 3220 Headset', 2],
        ['A4tech HS-7P Headset', 10],
        ['A4tech HS-50 Stereo Headset', 5],
        ['A4tech HU-30 USB Headset', 5],
        ['A4tech HS-5P Headset', 10],
        ['RAIKU S300 Standard Headset Brown Box', 7],
    ],
];

function ensureCategory(PDO $pdo, string $name): int {
    $sel = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $sel->execute([$name]);
    $id = $sel->fetchColumn();
    if ($id) { return (int)$id; }
    $ins = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
    $ins->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function upsertProduct(PDO $pdo, int $categoryId, string $name, int $stock): void {
    $sel = $pdo->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $sel->execute([$name]);
    $id = $sel->fetchColumn();
    if ($id) {
        $upd = $pdo->prepare('UPDATE products SET stock = stock + ?, status = "active" WHERE id = ?');
        $upd->execute([$stock, $id]);
        echo "UPDATED: {$name} (+{$stock})\n";
        return;
    }
    $ins = $pdo->prepare('INSERT INTO products (name, description, price, image_url, category_id, status, stock, created_at) VALUES (?,?,?,?,?,"active",?,NOW())');
    $ins->execute([$name, null, 0, null, $categoryId, $stock]);
    echo "CREATED: {$name} (stock {$stock})\n";
}

try {
    $pdo = getConnection();
    $pdo->beginTransaction();

    foreach ($DATA as $categoryName => $items) {
        $categoryId = ensureCategory($pdo, $categoryName);
        foreach ($items as [$label, $qty]) {
            if ($confirmed) {
                upsertProduct($pdo, $categoryId, $label, (int)$qty);
            } else {
                echo "DRY-RUN: {$categoryName} -> {$label} (qty {$qty})\n";
            }
        }
    }

    if ($confirmed) { $pdo->commit(); echo "\nDONE.\n"; }
    else { $pdo->rollBack(); echo "\nDry run complete. No changes applied.\n"; }
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}


