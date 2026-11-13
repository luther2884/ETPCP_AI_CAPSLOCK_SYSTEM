<?php
// Add final remaining products (Headsets, Mice, Mousepads, CPU Coolers, Watches)
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getConnection();
    
    // Get category IDs
    $stmt = $pdo->query("SELECT id, name FROM categories");
    $categoryMap = [];
    while ($row = $stmt->fetch()) {
        $categoryMap[$row['name']] = $row['id'];
    }
    
    // Sample reviews data
    $sampleReviews = [
        ['rating' => 5, 'comment' => 'Excellent product! Works perfectly and great value for money.'],
        ['rating' => 4, 'comment' => 'Very good quality, fast shipping. Would recommend to others.'],
        ['rating' => 5, 'comment' => 'Amazing performance! Exceeded my expectations.'],
        ['rating' => 4, 'comment' => 'Good product, works as advertised. Minor issues but overall satisfied.'],
        ['rating' => 5, 'comment' => 'Perfect! Exactly what I needed. Great customer service too.'],
        ['rating' => 3, 'comment' => 'Decent product for the price. Could be better but acceptable.'],
        ['rating' => 4, 'comment' => 'Solid build quality and good performance. Happy with purchase.'],
        ['rating' => 5, 'comment' => 'Outstanding! Best purchase I\'ve made in a while.'],
        ['rating' => 4, 'comment' => 'Good value for money. Works well for my needs.'],
        ['rating' => 5, 'comment' => 'Fantastic product! Highly recommended to everyone.']
    ];
    
    // Function to add reviews for a product
    function addReviews($pdo, $productId, $reviews) {
        global $sampleReviews;
        $numReviews = rand(3, 8);
        
        for ($i = 0; $i < $numReviews; $i++) {
            $review = $sampleReviews[array_rand($sampleReviews)];
            $stmt = $pdo->prepare("INSERT INTO product_reviews (product_id, user_name, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([
                $productId,
                'Customer ' . ($i + 1),
                $review['rating'],
                $review['comment']
            ]);
        }
    }
    
    // Function to add products
    function addProducts($pdo, $products, $categoryName, $categoryMap) {
        global $sampleReviews;
        $stmt = $pdo->prepare("
            INSERT INTO products (name, description, price, stock, category_id, image_url, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        
        $inserted = 0;
        foreach ($products as $product) {
            try {
                $stmt->execute([
                    $product['name'],
                    $product['description'],
                    $product['price'],
                    $product['stock'],
                    $categoryMap[$categoryName],
                    'img/products/placeholder.svg'
                ]);
                $productId = $pdo->lastInsertId();
                addReviews($pdo, $productId, $sampleReviews);
                $inserted++;
                echo "Added $categoryName: " . $product['name'] . " - ₱" . number_format($product['price'], 2) . " (Stock: " . $product['stock'] . ")\n";
            } catch (PDOException $e) {
                echo "Error adding " . $product['name'] . ": " . $e->getMessage() . "\n";
            }
        }
        return $inserted;
    }
    
    // Headsets
    $headsets = [
        ['name' => 'Aula F606 headset', 'price' => 1295.00, 'stock' => 3, 'description' => 'Aula F606 gaming headset, comfortable gaming headset with good sound quality.'],
        ['name' => 'Aula S600 Headset', 'price' => 995.00, 'stock' => 3, 'description' => 'Aula S600 headset, basic gaming headset.'],
        ['name' => 'Aula S605 Headset', 'price' => 1195.00, 'stock' => 2, 'description' => 'Aula S605 headset, gaming headset with microphone.'],
        ['name' => 'Rapoo H100 wired Headset', 'price' => 695.00, 'stock' => 4, 'description' => 'Rapoo H100 wired headset, basic wired headset.'],
        ['name' => 'Rapoo H150S Stereo Headset', 'price' => 895.00, 'stock' => 3, 'description' => 'Rapoo H150S stereo headset, stereo headset with good sound.'],
        ['name' => 'Rapoo H120 stereo Headset', 'price' => 795.00, 'stock' => 5, 'description' => 'Rapoo H120 stereo headset, basic stereo headset.'],
        ['name' => 'AULA F606 Headset', 'price' => 1295.00, 'stock' => 2, 'description' => 'AULA F606 headset, gaming headset with RGB lighting.'],
        ['name' => 'AULA S503 Headset', 'price' => 1095.00, 'stock' => 5, 'description' => 'AULA S503 headset, gaming headset with microphone.'],
        ['name' => 'Aula S505 Headset', 'price' => 1195.00, 'stock' => 5, 'description' => 'Aula S505 headset, gaming headset with good sound quality.'],
        ['name' => 'Hp H500Gs Headset', 'price' => 1495.00, 'stock' => 2, 'description' => 'HP H500Gs headset, business headset with noise cancellation.'],
        ['name' => 'Edifier USB K800 Headset', 'price' => 1995.00, 'stock' => 2, 'description' => 'Edifier USB K800 headset, USB headset with good sound quality.'],
        ['name' => 'Redragon Ares H120 Headset', 'price' => 1295.00, 'stock' => 3, 'description' => 'Redragon Ares H120 headset, gaming headset with RGB lighting.'],
        ['name' => 'Steelseries 61504 Artis 5 Headset', 'price' => 3995.00, 'stock' => 2, 'description' => 'SteelSeries Arctis 5 headset, premium gaming headset with RGB lighting.'],
        ['name' => 'Edifier K830 Headphone/Headset', 'price' => 1795.00, 'stock' => 2, 'description' => 'Edifier K830 headphone/headset, versatile audio device.'],
        ['name' => 'Edifier G2II Headset', 'price' => 1495.00, 'stock' => 2, 'description' => 'Edifier G2II headset, gaming headset with good sound quality.'],
        ['name' => 'A4tech Bloody G520 headset', 'price' => 1995.00, 'stock' => 2, 'description' => 'A4tech Bloody G520 headset, gaming headset with RGB lighting.'],
        ['name' => 'A4tech Bloody G500 headset', 'price' => 1795.00, 'stock' => 3, 'description' => 'A4tech Bloody G500 headset, gaming headset with good sound quality.'],
        ['name' => 'Redragon Lamia2 H320RGB-1 Headset', 'price' => 2195.00, 'stock' => 2, 'description' => 'Redragon Lamia2 H320RGB-1 headset, RGB gaming headset.'],
        ['name' => 'Edifier W800BT Plus Headset Black', 'price' => 2495.00, 'stock' => 2, 'description' => 'Edifier W800BT Plus headset black, wireless headset with good battery life.'],
        ['name' => 'Edifier W800BT Plus Headset White', 'price' => 2495.00, 'stock' => 2, 'description' => 'Edifier W800BT Plus headset white, wireless headset with good battery life.'],
        ['name' => 'Redragon Minos H210 Headset', 'price' => 1295.00, 'stock' => 2, 'description' => 'Redragon Minos H210 headset, gaming headset with microphone.'],
        ['name' => 'RAPOO Vpro VH200 Headset', 'price' => 1495.00, 'stock' => 2, 'description' => 'Rapoo Vpro VH200 headset, gaming headset with good sound quality.'],
        ['name' => 'Red dragon Mento H270-RGB Headset', 'price' => 1995.00, 'stock' => 1, 'description' => 'Redragon Mento H270-RGB headset, RGB gaming headset.'],
        ['name' => 'Lenovo H300 Headset L02', 'price' => 1295.00, 'stock' => 2, 'description' => 'Lenovo H300 headset L02, business headset.'],
        ['name' => 'Logitech H151 Stereo Headset', 'price' => 995.00, 'stock' => 4, 'description' => 'Logitech H151 stereo headset, basic stereo headset.'],
        ['name' => 'Logitech H150 Stereo Headset', 'price' => 895.00, 'stock' => 4, 'description' => 'Logitech H150 stereo headset, basic stereo headset.'],
        ['name' => 'Logitech H111 Stereo Headset', 'price' => 795.00, 'stock' => 4, 'description' => 'Logitech H111 stereo headset, basic stereo headset.'],
        ['name' => 'Plantronics Poly 3220 Headset', 'price' => 2995.00, 'stock' => 2, 'description' => 'Plantronics Poly 3220 headset, business headset with noise cancellation.'],
        ['name' => 'A4tech HS-7P Headset', 'price' => 695.00, 'stock' => 10, 'description' => 'A4tech HS-7P headset, basic headset.'],
        ['name' => 'A4tech HS-50 Stereo Headset', 'price' => 795.00, 'stock' => 5, 'description' => 'A4tech HS-50 stereo headset, basic stereo headset.'],
        ['name' => 'A4tech HU-30 USB Headset', 'price' => 995.00, 'stock' => 5, 'description' => 'A4tech HU-30 USB headset, USB headset.'],
        ['name' => 'A4tech HS-5P HEADSET', 'price' => 695.00, 'stock' => 10, 'description' => 'A4tech HS-5P headset, basic headset.'],
        ['name' => 'RAIKU S300 STANDARD HEADSET BROWN BOX', 'price' => 1295.00, 'stock' => 7, 'description' => 'Raiku S300 standard headset brown box, standard headset.']
    ];
    
    // Mice
    $mice = [
        ['name' => 'Aula F806 Mouse', 'price' => 695.00, 'stock' => 5, 'description' => 'Aula F806 gaming mouse, optical gaming mouse.'],
        ['name' => 'Aula F812 Mouse', 'price' => 795.00, 'stock' => 5, 'description' => 'Aula F812 gaming mouse, gaming mouse with RGB lighting.'],
        ['name' => 'Aula S12 Mouse', 'price' => 595.00, 'stock' => 5, 'description' => 'Aula S12 mouse, basic optical mouse.'],
        ['name' => 'Logitech M185 Wireless mouse', 'price' => 995.00, 'stock' => 1, 'description' => 'Logitech M185 wireless mouse, wireless optical mouse.'],
        ['name' => 'Logitech M187 Wireless Mouse', 'price' => 1195.00, 'stock' => 3, 'description' => 'Logitech M187 wireless mouse, wireless optical mouse.'],
        ['name' => 'Rapoo N1162 Wired mouse', 'price' => 395.00, 'stock' => 5, 'description' => 'Rapoo N1162 wired mouse, basic wired mouse.'],
        ['name' => 'Rapoo N6000 Wired mouse white', 'price' => 495.00, 'stock' => 5, 'description' => 'Rapoo N6000 wired mouse white, wired optical mouse.'],
        ['name' => 'Rapoo M10 Plus wireless mouse royal blue+black', 'price' => 695.00, 'stock' => 2, 'description' => 'Rapoo M10 Plus wireless mouse royal blue+black, wireless mouse.'],
        ['name' => 'Rapoo M10 Plus wireless mouse white+gray', 'price' => 695.00, 'stock' => 2, 'description' => 'Rapoo M10 Plus wireless mouse white+gray, wireless mouse.'],
        ['name' => 'Rapoo M10 Plus wireless mouse White+red', 'price' => 695.00, 'stock' => 2, 'description' => 'Rapoo M10 Plus wireless mouse white+red, wireless mouse.'],
        ['name' => 'Rapoo M10 Plus wireless mouse black', 'price' => 695.00, 'stock' => 1, 'description' => 'Rapoo M10 Plus wireless mouse black, wireless mouse.'],
        ['name' => 'Rapoo M100 Silent wireless mouse', 'price' => 795.00, 'stock' => 3, 'description' => 'Rapoo M100 silent wireless mouse, silent wireless mouse.'],
        ['name' => 'Rapoo V16RGB Wired Gaming Mouse', 'price' => 1295.00, 'stock' => 5, 'description' => 'Rapoo V16RGB wired gaming mouse, RGB gaming mouse.'],
        ['name' => 'Rapoo V310 laser wired gaming mouse', 'price' => 1495.00, 'stock' => 1, 'description' => 'Rapoo V310 laser wired gaming mouse, laser gaming mouse.'],
        ['name' => 'Rapoo M20 wireless mouse', 'price' => 595.00, 'stock' => 5, 'description' => 'Rapoo M20 wireless mouse, basic wireless mouse.'],
        ['name' => 'Rapoo B20 wireless mouse', 'price' => 695.00, 'stock' => 4, 'description' => 'Rapoo B20 wireless mouse, wireless optical mouse.'],
        ['name' => 'Rapoo V280 wired Gaming mouse', 'price' => 1195.00, 'stock' => 5, 'description' => 'Rapoo V280 wired gaming mouse, gaming mouse with good precision.'],
        ['name' => 'Rapoo N1200 Silent wired mouse', 'price' => 795.00, 'stock' => 3, 'description' => 'Rapoo N1200 silent wired mouse, silent wired mouse.'],
        ['name' => 'Redragon Neptune gaming mouse', 'price' => 1295.00, 'stock' => 2, 'description' => 'Redragon Neptune gaming mouse, gaming mouse with RGB lighting.'],
        ['name' => 'Asus P304 TUF Gaming M5 Gaming mouse', 'price' => 1995.00, 'stock' => 4, 'description' => 'ASUS TUF Gaming M5 gaming mouse, gaming mouse with military-grade durability.'],
        ['name' => 'Machenike M3 Gaming Mouse', 'price' => 1495.00, 'stock' => 1, 'description' => 'Machenike M3 gaming mouse, gaming mouse with good precision.'],
        ['name' => 'Delux M700(A725) Gaming Mouse', 'price' => 1295.00, 'stock' => 1, 'description' => 'Delux M700(A725) gaming mouse, gaming mouse with adjustable DPI.'],
        ['name' => 'Aula Wind S11 Pro Mouse', 'price' => 995.00, 'stock' => 5, 'description' => 'Aula Wind S11 Pro mouse, gaming mouse with good performance.'],
        ['name' => 'Aula AM103 mouse', 'price' => 495.00, 'stock' => 9, 'description' => 'Aula AM103 mouse, basic optical mouse.'],
        ['name' => 'Aula AM201 mouse', 'price' => 595.00, 'stock' => 2, 'description' => 'Aula AM201 mouse, optical mouse.'],
        ['name' => 'Aula wind F805 Gaming mouse', 'price' => 1095.00, 'stock' => 4, 'description' => 'Aula Wind F805 gaming mouse, gaming mouse with RGB lighting.'],
        ['name' => 'Gamdias Zeus E3 Mouse', 'price' => 1295.00, 'stock' => 1, 'description' => 'Gamdias Zeus E3 mouse, gaming mouse with good precision.'],
        ['name' => 'Redragon Phaser M609 Mouse', 'price' => 1195.00, 'stock' => 5, 'description' => 'Redragon Phaser M609 mouse, gaming mouse with adjustable DPI.'],
        ['name' => 'Redragon Gainer M610 Mouse', 'price' => 1295.00, 'stock' => 3, 'description' => 'Redragon Gainer M610 mouse, gaming mouse with good performance.'],
        ['name' => 'Redragon Ranger Lite M910-KS Mouse', 'price' => 1495.00, 'stock' => 3, 'description' => 'Redragon Ranger Lite M910-KS mouse, gaming mouse with RGB lighting.'],
        ['name' => 'Redragon Cobra M711 Mouse', 'price' => 1395.00, 'stock' => 3, 'description' => 'Redragon Cobra M711 mouse, gaming mouse with good precision.'],
        ['name' => 'Redragon Cobra M711W Mouse', 'price' => 1495.00, 'stock' => 3, 'description' => 'Redragon Cobra M711W mouse, wireless gaming mouse.'],
        ['name' => 'Redragon Griffin M607 Mouse', 'price' => 1195.00, 'stock' => 4, 'description' => 'Redragon Griffin M607 mouse, gaming mouse with good performance.'],
        ['name' => 'Reddragon Predator M612-RGB MOUSE', 'price' => 1595.00, 'stock' => 3, 'description' => 'Redragon Predator M612-RGB mouse, RGB gaming mouse.'],
        ['name' => 'Redragon Mirage M690 Mouse', 'price' => 1295.00, 'stock' => 5, 'description' => 'Redragon Mirage M690 mouse, gaming mouse with good precision.'],
        ['name' => 'Redragon Pegasus M705 Mouse', 'price' => 1395.00, 'stock' => 4, 'description' => 'Redragon Pegasus M705 mouse, gaming mouse with adjustable DPI.'],
        ['name' => 'Redragon Storm M808W-RGB Mouse', 'price' => 1995.00, 'stock' => 1, 'description' => 'Redragon Storm M808W-RGB mouse, wireless RGB gaming mouse.'],
        ['name' => 'Redragon Storm M808-RGB Mouse', 'price' => 1795.00, 'stock' => 3, 'description' => 'Redragon Storm M808-RGB mouse, RGB gaming mouse.'],
        ['name' => 'Redragon Stormrage M718 RGB Mouse', 'price' => 2195.00, 'stock' => 5, 'description' => 'Redragon Stormrage M718 RGB mouse, RGB gaming mouse with good performance.'],
        ['name' => 'A4tech OP-200X USB Mouse', 'price' => 395.00, 'stock' => 2, 'description' => 'A4tech OP-200X USB mouse, basic USB mouse.'],
        ['name' => 'A4tech G11-760N wireless mouse', 'price' => 695.00, 'stock' => 5, 'description' => 'A4tech G11-760N wireless mouse, wireless optical mouse.'],
        ['name' => 'A4tech OP-330 USB mouse', 'price' => 495.00, 'stock' => 7, 'description' => 'A4tech OP-330 USB mouse, USB optical mouse.'],
        ['name' => 'A4tech OP-720 USB mouse', 'price' => 595.00, 'stock' => 14, 'description' => 'A4tech OP-720 USB mouse, USB optical mouse.'],
        ['name' => 'A4tech G3-280N Wireless mouse', 'price' => 795.00, 'stock' => 2, 'description' => 'A4tech G3-280N wireless mouse, wireless optical mouse.'],
        ['name' => 'A4tech FG12 Wireless Mouse', 'price' => 895.00, 'stock' => 2, 'description' => 'A4tech FG12 wireless mouse, wireless optical mouse.'],
        ['name' => 'A4tech FG15C Air 2 Wireless mouse', 'price' => 995.00, 'stock' => 2, 'description' => 'A4tech FG15C Air 2 wireless mouse, wireless optical mouse.'],
        ['name' => 'A4tech FG16C Air Wireless mouse', 'price' => 1095.00, 'stock' => 2, 'description' => 'A4tech FG16C Air wireless mouse, wireless optical mouse.'],
        ['name' => 'Toshiba U25 USB mouse', 'price' => 495.00, 'stock' => 10, 'description' => 'Toshiba U25 USB mouse, USB optical mouse.'],
        ['name' => 'A4tech FB35 Styler wireless Mouse (Icy white)', 'price' => 1195.00, 'stock' => 3, 'description' => 'A4tech FB35 Styler wireless mouse icy white, wireless optical mouse.'],
        ['name' => 'A4tech FB35 Styler wireless Mouse (Midnight green)', 'price' => 1195.00, 'stock' => 2, 'description' => 'A4tech FB35 Styler wireless mouse midnight green, wireless optical mouse.'],
        ['name' => 'Delux DLM-102 Usb Mouse', 'price' => 395.00, 'stock' => 1, 'description' => 'Delux DLM-102 USB mouse, USB optical mouse.'],
        ['name' => 'Delux DLM-362B USB MOUSE', 'price' => 495.00, 'stock' => 4, 'description' => 'Delux DLM-362B USB mouse, USB optical mouse.'],
        ['name' => 'A4tech FBK30 Wireless mouse', 'price' => 795.00, 'stock' => 4, 'description' => 'A4tech FBK30 wireless mouse, wireless optical mouse.'],
        ['name' => 'Machenike M721 gaming wireless mouse', 'price' => 1495.00, 'stock' => 3, 'description' => 'Machenike M721 gaming wireless mouse, wireless gaming mouse.'],
        ['name' => 'Machenike M840 gaming wired mouse', 'price' => 1295.00, 'stock' => 3, 'description' => 'Machenike M840 gaming wired mouse, wired gaming mouse.']
    ];
    
    // Mousepads
    $mousepads = [
        ['name' => 'Aula F-X5 Mousepad', 'price' => 295.00, 'stock' => 3, 'description' => 'Aula F-X5 mousepad, gaming mousepad with good surface.'],
        ['name' => 'Steelseries Mousepad XXL', 'price' => 1295.00, 'stock' => 2, 'description' => 'SteelSeries mousepad XXL, large gaming mousepad.'],
        ['name' => 'Steelseries Mousepad XL', 'price' => 995.00, 'stock' => 4, 'description' => 'SteelSeries mousepad XL, extra large gaming mousepad.'],
        ['name' => 'Steelseries Mousepad Large', 'price' => 795.00, 'stock' => 2, 'description' => 'SteelSeries mousepad large, large gaming mousepad.'],
        ['name' => 'Steelseries Mousepad Small', 'price' => 495.00, 'stock' => 10, 'description' => 'SteelSeries mousepad small, small gaming mousepad.'],
        ['name' => 'Philips Momentum SPL7104/00 Mousepad', 'price' => 695.00, 'stock' => 2, 'description' => 'Philips Momentum SPL7104/00 mousepad, gaming mousepad.'],
        ['name' => 'Razer Galiathus Mousepad', 'price' => 1295.00, 'stock' => 1, 'description' => 'Razer Goliathus mousepad, premium gaming mousepad.'],
        ['name' => 'Fantech Vigil MP292 Mousepad', 'price' => 495.00, 'stock' => 7, 'description' => 'Fantech Vigil MP292 mousepad, gaming mousepad.'],
        ['name' => 'Reddragon Pluto P026 Mousepad', 'price' => 595.00, 'stock' => 5, 'description' => 'Redragon Pluto P026 mousepad, gaming mousepad.'],
        ['name' => 'Redragon Pluto P026 Gaming Mousepad', 'price' => 695.00, 'stock' => 5, 'description' => 'Redragon Pluto P026 gaming mousepad, gaming mousepad with good surface.']
    ];
    
    // CPU Coolers
    $cpuCoolers = [
        ['name' => 'Antec Symphony 240 ARGB', 'price' => 3995.00, 'stock' => 2, 'description' => 'Antec Symphony 240 ARGB liquid cooler, 240mm AIO with RGB lighting.'],
        ['name' => 'Antec Symphony 360 ARGB White', 'price' => 4995.00, 'stock' => 2, 'description' => 'Antec Symphony 360 ARGB white liquid cooler, 360mm AIO with RGB lighting.'],
        ['name' => 'Antec Symphony 360 ARGB Black', 'price' => 4995.00, 'stock' => 2, 'description' => 'Antec Symphony 360 ARGB black liquid cooler, 360mm AIO with RGB lighting.'],
        ['name' => 'Antec Kuhler H2O-K240 RGB', 'price' => 3495.00, 'stock' => 2, 'description' => 'Antec Kuhler H2O-K240 RGB liquid cooler, 240mm AIO with RGB lighting.'],
        ['name' => 'Antec Prizm X 120ARGB 3+C', 'price' => 1995.00, 'stock' => 3, 'description' => 'Antec Prizm X 120ARGB 3+C fan, 120mm RGB fan with controller.'],
        ['name' => 'Antec A30 Neo ARGB', 'price' => 1295.00, 'stock' => 5, 'description' => 'Antec A30 Neo ARGB CPU cooler, tower cooler with RGB lighting.'],
        ['name' => 'Antec Prizm 120 ARGB 3+2+C', 'price' => 1795.00, 'stock' => 3, 'description' => 'Antec Prizm 120 ARGB 3+2+C fan, 120mm RGB fan with controller.'],
        ['name' => 'Antec Prizm 120 ARGB 5+C', 'price' => 1995.00, 'stock' => 3, 'description' => 'Antec Prizm 120 ARGB 5+C fan, 120mm RGB fan with controller.'],
        ['name' => 'Antec Fusion 120 ARGB 5+C', 'price' => 2195.00, 'stock' => 1, 'description' => 'Antec Fusion 120 ARGB 5+C fan, 120mm RGB fan with controller.'],
        ['name' => 'Antec A30 Bloody tiger', 'price' => 1095.00, 'stock' => 4, 'description' => 'Antec A30 Bloody Tiger CPU cooler, tower cooler with good performance.'],
        ['name' => 'Antec A40 Pro Bloody tiger', 'price' => 1295.00, 'stock' => 5, 'description' => 'Antec A40 Pro Bloody Tiger CPU cooler, tower cooler with good performance.'],
        ['name' => 'Antec C400 Copper tiger', 'price' => 1495.00, 'stock' => 2, 'description' => 'Antec C400 Copper Tiger CPU cooler, tower cooler with copper heat pipes.'],
        ['name' => 'Antec A400 RGB', 'price' => 1195.00, 'stock' => 5, 'description' => 'Antec A400 RGB CPU cooler, tower cooler with RGB lighting.'],
        ['name' => 'Huntkey GX122 New moon Fan', 'price' => 495.00, 'stock' => 3, 'description' => 'Huntkey GX122 New Moon fan, 120mm case fan.'],
        ['name' => 'Huntkey Frozen 200 cpu cooler', 'price' => 1295.00, 'stock' => 2, 'description' => 'Huntkey Frozen 200 CPU cooler, tower cooler with good performance.'],
        ['name' => 'Huntkey Frozen 400 cpu cooler', 'price' => 1495.00, 'stock' => 2, 'description' => 'Huntkey Frozen 400 CPU cooler, tower cooler with good performance.'],
        ['name' => 'Cooler Master Hyper 212 ARGB CPU fan', 'price' => 1995.00, 'stock' => 2, 'description' => 'Cooler Master Hyper 212 ARGB CPU fan, tower cooler with RGB lighting.'],
        ['name' => 'Inplay seaview 120M ARGB Fan', 'price' => 695.00, 'stock' => 2, 'description' => 'Inplay Seaview 120M ARGB fan, 120mm RGB case fan.'],
        ['name' => 'Ocypus Lota A40 BK CPU Cooler', 'price' => 1295.00, 'stock' => 1, 'description' => 'Ocypus Lota A40 BK CPU cooler, tower cooler with good performance.']
    ];
    
    // Smart Watches
    $smartWatches = [
        ['name' => 'Kieslect Kr Pro Ltd silver', 'price' => 2995.00, 'stock' => 1, 'description' => 'Kieslect Kr Pro Ltd silver smartwatch, premium smartwatch with health tracking.'],
        ['name' => 'Kieslect Kr Pro Ltd Gray', 'price' => 2995.00, 'stock' => 1, 'description' => 'Kieslect Kr Pro Ltd gray smartwatch, premium smartwatch with health tracking.'],
        ['name' => 'Kieslect Kr2 Black', 'price' => 1995.00, 'stock' => 1, 'description' => 'Kieslect Kr2 black smartwatch, smartwatch with health tracking.'],
        ['name' => 'Kieslect Ks Pro Black', 'price' => 2495.00, 'stock' => 1, 'description' => 'Kieslect Ks Pro black smartwatch, premium smartwatch with health tracking.'],
        ['name' => 'Kieslect Ks Pro Silver', 'price' => 2495.00, 'stock' => 1, 'description' => 'Kieslect Ks Pro silver smartwatch, premium smartwatch with health tracking.'],
        ['name' => 'Kieslect Ks2 Space Gray', 'price' => 1795.00, 'stock' => 2, 'description' => 'Kieslect Ks2 space gray smartwatch, smartwatch with health tracking.'],
        ['name' => 'Kieslect Balancs Black', 'price' => 1295.00, 'stock' => 3, 'description' => 'Kieslect Balance black smartwatch, fitness smartwatch with health tracking.']
    ];
    
    // Add all products
    $totalInserted = 0;
    
    echo "Adding Headsets...\n";
    $totalInserted += addProducts($pdo, $headsets, 'Headsets', $categoryMap);
    
    echo "\nAdding Mice...\n";
    $totalInserted += addProducts($pdo, $mice, 'Mice', $categoryMap);
    
    echo "\nAdding Mousepads...\n";
    $totalInserted += addProducts($pdo, $mousepads, 'Mousepads', $categoryMap);
    
    echo "\nAdding CPU Coolers...\n";
    $totalInserted += addProducts($pdo, $cpuCoolers, 'CPU Coolers', $categoryMap);
    
    echo "\nAdding Smart Watches...\n";
    $totalInserted += addProducts($pdo, $smartWatches, 'Smart Watches', $categoryMap);
    
    echo "\nSuccessfully added $totalInserted products with reviews!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
