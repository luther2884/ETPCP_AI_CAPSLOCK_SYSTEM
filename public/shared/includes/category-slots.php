<?php
// category-slots.php — Static slot definitions mapped to category names

// Normalizes a category name to a consistent key
function normalizeCategoryName($name) {
    $n = strtolower(trim($name ?? ''));
    $map = [
        'processor' => 'processor',
        'processors' => 'processor',
        'mini pcs' => 'processor',
        'mini pc' => 'processor',
        'motherboard' => 'motherboard',
        'motherboards' => 'motherboard',
        'graphics card' => 'videocard',
        'graphics cards' => 'videocard',
        'gpu' => 'videocard',
        'video card' => 'videocard',
        'memory' => 'memory',
        'ram' => 'memory',
        'power supply' => 'psu',
        'psu' => 'psu',
    ];
    return $map[$n] ?? $n;
}

$CATEGORY_SLOTS = [
    // PROCESSORS / MINI PCs
    'processor' => [
        ['label' => 'INTEL NUC Celeron NUC7CJYSN Mini PC', 'qty' => 2],
        ['label' => 'INTEL NUC CORE i5 10th Gen A Mini PC Kit', 'qty' => 1],
        ['label' => 'INTEL NUC i5 Mini PC kit', 'qty' => 1],
        ['label' => 'INTEL NUC i3 Mini PC kit', 'qty' => 2],
        ['label' => 'INTEL CELERON G1840 PROCESSOR', 'qty' => 3],
        ['label' => 'INTEL PENTIUM G3250 PROCESSOR', 'qty' => 3],
        ['label' => 'INTEL PENTIUM G3260 PROCESSOR', 'qty' => 3],
        ['label' => 'INTEL CORE i3-12100 PROCESSOR', 'qty' => 3],
        ['label' => 'INTEL CORE i3-10105 PROCESSOR', 'qty' => 5],
        ['label' => 'INTEL CORE i5-11400F PROCESSOR', 'qty' => 2],
        ['label' => 'INTEL CORE i5-12400F PROCESSOR', 'qty' => 2],
        ['label' => 'INTEL CORE i5-12400 PROCESSOR', 'qty' => 2],
        ['label' => 'INTEL CORE i5-9400F PROCESSOR', 'qty' => 2],
        ['label' => 'INTEL CORE i5-13400 PROCESSOR', 'qty' => 2],
        ['label' => 'Machenike Machreator Mini PC 2 i5-12450H', 'qty' => 1],
        ['label' => 'Machenike Machreator Mini PC 2 i7-12650H', 'qty' => 1],
        ['label' => 'AMD Ryzen 7 5700G PROCESSOR', 'qty' => 3],
        ['label' => 'AMD Ryzen 5 5600G PROCESSOR', 'qty' => 1],
        ['label' => 'AMD Ryzen 5 5500 PROCESSOR', 'qty' => 2],
        ['label' => 'AMD Ryzen 5 3600 PROCESSOR', 'qty' => 3],
        ['label' => 'AMD Ryzen 5 2600 PROCESSOR', 'qty' => 3],
        ['label' => 'AMD Ryzen 3 3200G PROCESSOR', 'qty' => 1],
        ['label' => 'AMD Ryzen 5 4600G PROCESSOR', 'qty' => 5],
        ['label' => 'AMD Athlon 200GE PROCESSOR', 'qty' => 1],
        ['label' => 'AMD Ryzen 3 PRO 4350G Tray type Processor', 'qty' => 7],
    ],

    // MOTHERBOARD
    'motherboard' => [
        ['label' => 'Gigabyte B550M DS3H Motherboard', 'qty' => 1],
        ['label' => 'Gigabyte B450M DS3H Motherboard', 'qty' => 1],
        ['label' => 'Gigabyte B450M S2H V2 Motherboard', 'qty' => 1],
        ['label' => 'Gigabyte AB350M-HD3 Motherboard', 'qty' => 2],
        ['label' => 'Gigabyte A320M-S2H V2 Motherboard', 'qty' => 1],
        ['label' => 'Gigabyte B760M H DDR4 Motherboard', 'qty' => 5],
        ['label' => 'Gigabyte A320M-S2H Motherboard', 'qty' => 5],
        ['label' => 'MSI B450M Mortar Max Motherboard', 'qty' => 2],
        ['label' => 'Asus TUF B450M-Pro II Motherboard', 'qty' => 1],
        ['label' => 'Aorus B450 AORUS M Motherboard', 'qty' => 2],
        ['label' => 'Asus Prime B365M-A Motherboard', 'qty' => 1],
        ['label' => 'Asus TUF Z370-PLUS Motherboard', 'qty' => 2],
        ['label' => 'BIOSTAR A75MG Motherboard', 'qty' => 2],
        ['label' => 'ASUS A68HM-K Motherboard', 'qty' => 2],
        ['label' => 'MSI H110M PRO-VH Motherboard', 'qty' => 4],
        ['label' => 'MSI H110M PRO-VD Motherboard', 'qty' => 4],
        ['label' => 'GIGABYTE H110M-S2 Motherboard', 'qty' => 1],
        ['label' => 'ASUS H110M-D Motherboard', 'qty' => 2],
        ['label' => 'GIGABYTE H110M-S2 Motherboard', 'qty' => 2],
        ['label' => 'GIGABYTE H110M-H Motherboard', 'qty' => 2],
        ['label' => 'GIGABYTE B150M-D3H Motherboard', 'qty' => 1],
        ['label' => 'DURATHON 2 H110M4-C2 SERIES Motherboard', 'qty' => 2],
        ['label' => 'ASUS EZ-B150M-V5 Motherboard', 'qty' => 2],
        ['label' => 'MSI B150M BAZOOKA Motherboard', 'qty' => 2],
        ['label' => 'GIGABYTE Z270M-D3H Motherboard', 'qty' => 1],
        ['label' => 'ASUS PRIME B250M-D Motherboard', 'qty' => 2],
        ['label' => 'ASUS EX-H310M-V3 R2.0 Motherboard', 'qty' => 5],
        ['label' => 'MSI H310M PRO-VDH PLUS Motherboard', 'qty' => 5],
        ['label' => 'MSI A320M PRO-VD/S Motherboard', 'qty' => 2],
        ['label' => 'GIGABYTE A320M-DS2 Motherboard', 'qty' => 2],
        ['label' => 'PRIME A320M-K Motherboard', 'qty' => 5],
        ['label' => 'MSI BAZOOKA B350M Motherboard', 'qty' => 2],
        ['label' => 'MSI B350 PRO-VD PLUS Motherboard', 'qty' => 4],
        ['label' => 'MSI TOMAWAK B350 Motherboard', 'qty' => 1],
        ['label' => 'MSI B360M GAMING PLUS Motherboard', 'qty' => 2],
        ['label' => 'MSI B360 GAMING PRO CARBON Motherboard', 'qty' => 2],
        ['label' => 'ROG STRIX B360-F GAMING Motherboard', 'qty' => 2],
        ['label' => 'TUF B360M-E GAMING Motherboard', 'qty' => 2],
        ['label' => 'MSI Z370 GAMING PLUS Motherboard', 'qty' => 2],
        ['label' => 'ASUS PRIME B365-A Motherboard', 'qty' => 1],
        ['label' => 'ASUS TUF B365-PLUS GAMING Motherboard', 'qty' => 2],
        ['label' => 'PRIME Z370-A Motherboard', 'qty' => 2],
        ['label' => 'MSI B450M PRO-VDH PLUS Motherboard', 'qty' => 1],
        ['label' => 'ASUS ROG STRIX Z370-G GAMING (WIFI) Motherboard', 'qty' => 2],
        ['label' => 'ASUS ROG STRIX Z370-F GAMING Motherboard', 'qty' => 1],
        ['label' => 'MSI B450M PRO-M2 V2 Motherboard', 'qty' => 2],
        ['label' => 'ASUS TUF B450M-PLUS GAMING Motherboard', 'qty' => 2],
        ['label' => 'ASUS TUF B450M-PRO GAMING Motherboard', 'qty' => 2],
        ['label' => 'ASUS TUF GAMING B450M-PRO II Motherboard', 'qty' => 1],
        ['label' => 'MSI B450 TOMAHAWK MAX Motherboard', 'qty' => 1],
        ['label' => 'MSI B350 TOMAHAWK Motherboard', 'qty' => 1],
        ['label' => 'MSI B450 TOMAHAWK MAX Motherboard', 'qty' => 1],
        ['label' => 'ASUS EX-H510M-V3 Motherboard', 'qty' => 1],
        ['label' => 'GIGABYTE B460M POWER Motherboard', 'qty' => 2],
        ['label' => 'ASUS PRIME B450-A II Motherboard', 'qty' => 1],
        ['label' => 'MSI PRO SERIES PRO H510M-B Motherboard', 'qty' => 1],
        ['label' => 'ASUS PRIME A520M-K Motherboard', 'qty' => 2],
        ['label' => 'MSI MAG B550M BAZOOKA Motherboard', 'qty' => 2],
        ['label' => 'ASROCK B550M-HDV Motherboard', 'qty' => 1],
        ['label' => 'ASUS PRIME A520M-K Motherboard', 'qty' => 1],
        ['label' => 'ASUS PRIME H610M-K D4 Motherboard', 'qty' => 5],
        ['label' => 'MSI PRO H610M-E DDR4 Motherboard', 'qty' => 2],
        ['label' => 'GIGABYTE H610M K DDR4 Motherboard', 'qty' => 4],
    ],

    // VIDEOCARD
    'videocard' => [
        ['label' => 'Gigabyte RX570', 'qty' => 1],
        ['label' => 'Gigabyte GTX1050Ti', 'qty' => 3],
        ['label' => 'PowerColor RX 6700 XT 12GB GDDR6', 'qty' => 1],
        ['label' => 'Asus TUF GTX 1660Ti 6GB OC GDDR6', 'qty' => 2],
        ['label' => 'Asus TUF GTX 1650 4GB OC GDDR6', 'qty' => 2],
        ['label' => 'ASUS Phoenix GTX 1660 6GB OC GDDR5', 'qty' => 1],
        ['label' => 'MSI Ventus RTX 2060 6GB GDDR6', 'qty' => 1],
        ['label' => 'Asus GTX 1050Ti 4GB DDR5', 'qty' => 2],
        ['label' => 'INNO3D GTX 1050Ti 4GB DDR5', 'qty' => 2],
        ['label' => 'INNO3D GTX 1660 6GB GDDR5', 'qty' => 1],
        ['label' => 'INNO3D GTX 1660 Super 6GB GDDR6', 'qty' => 1],
        ['label' => 'INNO3D GTX 1650 4GB GDDR6', 'qty' => 1],
        ['label' => 'Gigabyte RTX 4070 12GB GDDR6X', 'qty' => 1],
        ['label' => 'Gigabyte RTX 4060Ti 8GB GDDR6', 'qty' => 1],
        ['label' => 'Gigabyte RTX 4060 8GB GDDR6', 'qty' => 1],
        ['label' => 'Gigabyte RTX 4060Ti 16GB GDDR6', 'qty' => 1],
        ['label' => 'MSI GT 710 2GB DDR3', 'qty' => 2],
        ['label' => 'INNO3D GT 730 2GB SDDR3', 'qty' => 4],
        ['label' => 'INNO3D GT 1030 2GB GDDR5', 'qty' => 2],
        ['label' => 'PALIT GTX 1050Ti 4GB GDDR5', 'qty' => 2],
        ['label' => 'INNO3D GTX 1660 Super 6GB GDDR6', 'qty' => 1],
        ['label' => 'Palit RTX 3050 8GB GDDR6', 'qty' => 2],
        ['label' => 'Palit GTX 1050Ti 4GB DDR5', 'qty' => 3],
        ['label' => 'Asus GTX 1050 2GB GDDR5', 'qty' => 1],
        ['label' => 'Afox GT 730 4096MB DDR3', 'qty' => 2],
        ['label' => 'Asus GT 710 2GB DDR3', 'qty' => 2],
    ],

    // MEMORY (DIMM + SO-DIMM)
    'memory' => [
        ['label' => 'Hp 8GB PC4 3200 U-Dimm Memory', 'qty' => 2],
        ['label' => 'TeamGroup T.Force 8GB DDR4 3200 DIMM Memory', 'qty' => 5],
        ['label' => 'Transcend 16GB DDR4 3200 U-DIMM Memory', 'qty' => 5],
        ['label' => 'Transcend 4GB DDR4 2666 U-DIMM Memory', 'qty' => 10],
        ['label' => 'Transcend 4GB DDR4 3200 U-DIMM Memory', 'qty' => 10],
        ['label' => 'Team Group Elite 8GB DDR4 2666 DIMM Memory', 'qty' => 6],
        ['label' => 'G.Skill 16GBx2 DDR4 3600 DIMM Memory', 'qty' => 3],
        ['label' => 'Kingston 8GB DDR4 3200 DIMM Memory', 'qty' => 9],
        ['label' => 'Kingston Fury 8GB DDR4 2666 DIMM Memory', 'qty' => 10],
        ['label' => 'HyperX Fury 16GB DDR4 3200 U-DIMM Memory', 'qty' => 10],
        ['label' => 'HyperX 16GB DDR4 2666 DIMM Memory', 'qty' => 8],
        ['label' => 'HyperX 16GB DDR4 2400 DIMM Memory', 'qty' => 2],
        ['label' => 'HyperX HX432C16FB4/16 16GB DDR4 3200 DIMM Memory', 'qty' => 10],
        ['label' => 'HyperX HX426C16FB3/8 8GB DDR4 2666 DIMM Memory', 'qty' => 8],
        ['label' => 'G.Skill Aegis 4GB DDR4 2400 DIMM Memory', 'qty' => 10],
        ['label' => 'ADATA 16GB DDR4 3200 SO-DIMM Memory', 'qty' => 3],
        ['label' => 'ADATA 16GB DDR4 3200 U-DIMM Memory', 'qty' => 2],
        ['label' => 'Dahua 8GB DDR4 3200 DIMM Memory', 'qty' => 10],
        // SO-DIMM subset
        ['label' => 'Kingston 8GB DDR4 2400 SODIMM Memory', 'qty' => 5],
        ['label' => 'Kingston 16GB DDR4 2666 SODIMM Memory', 'qty' => 10],
        ['label' => 'Kingston 8GB DDR4 2666 SODIMM Memory', 'qty' => 9],
        ['label' => 'Crucial 4GB DDR4 2400 SODIMM Memory', 'qty' => 1],
        ['label' => 'HP 16GB DDR4 2666 SODIMM Memory', 'qty' => 2],
        ['label' => 'Kingmax 4GB DDR3 1600 SODIMM Memory', 'qty' => 2],
        ['label' => 'Transcend 8GB DDR4 2666 SODIMM Memory', 'qty' => 4],
        ['label' => 'Transcend 4GB DDR4 3200 SODIMM Memory', 'qty' => 5],
        ['label' => 'Kingston 8GB DDR4 3200 SODIMM Memory', 'qty' => 5],
        ['label' => 'Kingston 16GB DDR4 3200 SODIMM Memory', 'qty' => 1],
        ['label' => 'Apacer 8GB DDR4 2400 SODIMM Memory', 'qty' => 4],
        ['label' => 'Apacer 4GB DDR4 2400 SODIMM Memory', 'qty' => 5],
        ['label' => 'Apacer 4GB DDR4 2666 SODIMM Memory', 'qty' => 10],
    ],

    // PSU
    'psu' => [
        ['label' => 'Antec ATOM B550 Bronze PSU', 'qty' => 2],
        ['label' => 'Antec 550W 80PLUS PSU', 'qty' => 3],
        ['label' => 'Antec NeoEco NE 650 G.M PSU', 'qty' => 2],
        ['label' => 'Antec NeoEco NE 750 G.M PSU', 'qty' => 2],
        ['label' => 'Antec NeoEco NE 850 G.M PSU', 'qty' => 2],
        ['label' => 'Huntkey GS700 PSU', 'qty' => 2],
        ['label' => 'Huntkey GS600 PSU', 'qty' => 2],
        ['label' => 'Huntkey GS500 PSU', 'qty' => 5],
        ['label' => 'Cooler Master 750 Bronze PSU', 'qty' => 2],
        ['label' => 'Cooler Master 650 Bronze PSU', 'qty' => 1],
        ['label' => 'Cooler Master 550 Bronze PSU', 'qty' => 1],
        ['label' => 'Power Logic ATX PM-600 PSU', 'qty' => 3],
        ['label' => 'Corsair CV550 ATX PSU', 'qty' => 1],
        ['label' => 'Orion ATX-700W PSU', 'qty' => 3],
        ['label' => 'LC-8700BTXN Standard PSU', 'qty' => 2],
    ],
];

function renderCategorySlots($categoryName) {
    global $CATEGORY_SLOTS;
    $key = normalizeCategoryName($categoryName);
    $items = $CATEGORY_SLOTS[$key] ?? [];
    if (empty($items)) { return; }
    echo '<div class="filter-section" style="display:none">';
    echo '<ul>';
    foreach ($items as $slot) {
        $label = htmlspecialchars($slot['label']);
        $qty = (int)($slot['qty'] ?? 0);
        echo '<li class="slot-item">';
        echo '<span class="slot-label">' . $label . '</span>';
        echo '<span class="slot-qty">' . $qty . '</span>';
        echo '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

function renderAllCategorySlots($limitPerCategory = 12) {
    global $CATEGORY_SLOTS;
    if (empty($CATEGORY_SLOTS)) { return; }
    echo '<div class="filter-section" style="display:none">';
    foreach ($CATEGORY_SLOTS as $groupName => $items) {
        if (empty($items)) { continue; }
        $title = ucwords(str_replace('-', ' ', $groupName));
        echo '<div class="slot-group">';
        echo '<div class="slot-group-title">' . htmlspecialchars($title) . '</div>';
        echo '<ul>';
        $shown = 0;
        foreach ($items as $slot) {
            if ($shown >= $limitPerCategory) { break; }
            $label = htmlspecialchars($slot['label']);
            $qty = (int)($slot['qty'] ?? 0);
            echo '<li class="slot-item">';
            echo '<span class="slot-label">' . $label . '</span>';
            echo '<span class="slot-qty">' . $qty . '</span>';
            echo '</li>';
            $shown++;
        }
        echo '</ul>';
        echo '</div>';
    }
    echo '</div>';
}

?>


