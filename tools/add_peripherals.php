<?php
// Add all peripherals and remaining products
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
    
    // Keyboards and Combos
    $keyboards = [
        ['name' => 'Aula Wind F2088 pro Keyboard', 'price' => 1295.00, 'stock' => 2, 'description' => 'Aula Wind F2088 Pro mechanical keyboard, gaming keyboard with RGB lighting.'],
        ['name' => 'Machenike K500B-B87 M. Keyboard', 'price' => 1995.00, 'stock' => 1, 'description' => 'Machenike K500B-B87 mechanical keyboard, compact design.'],
        ['name' => 'Aula L2098 M.keyboard', 'price' => 995.00, 'stock' => 3, 'description' => 'Aula L2098 mechanical keyboard, budget gaming keyboard.'],
        ['name' => 'Aula T640 M.Keyboard', 'price' => 1195.00, 'stock' => 3, 'description' => 'Aula T640 mechanical keyboard, gaming keyboard.'],
        ['name' => 'Aula F2058 M.Keyboard', 'price' => 1095.00, 'stock' => 1, 'description' => 'Aula F2058 mechanical keyboard, compact gaming keyboard.'],
        ['name' => 'Aula S2022 M.Keyboard', 'price' => 1295.00, 'stock' => 2, 'description' => 'Aula S2022 mechanical keyboard, gaming keyboard.'],
        ['name' => 'Aula F3018 M.Keyboard', 'price' => 1495.00, 'stock' => 2, 'description' => 'Aula F3018 mechanical keyboard, RGB gaming keyboard.'],
        ['name' => 'Aula F2066-II M.Keyboard', 'price' => 1195.00, 'stock' => 1, 'description' => 'Aula F2066-II mechanical keyboard, gaming keyboard.'],
        ['name' => 'Aula AC203 Combo keyboard/Mouse', 'price' => 795.00, 'stock' => 9, 'description' => 'Aula AC203 keyboard and mouse combo, budget combo set.'],
        ['name' => 'Aula F3061 Mini Keyboard', 'price' => 995.00, 'stock' => 1, 'description' => 'Aula F3061 mini keyboard, compact design.'],
        ['name' => 'Rapoo 8000M wireless combo keyboard/Mouse', 'price' => 1295.00, 'stock' => 2, 'description' => 'Rapoo 8000M wireless keyboard and mouse combo, wireless set.'],
        ['name' => 'Rapoo X1800S wireless combo keyboard/Mouse', 'price' => 1495.00, 'stock' => 2, 'description' => 'Rapoo X1800S wireless keyboard and mouse combo, wireless set.'],
        ['name' => 'Rapoo NK2600 Wired keyboard', 'price' => 695.00, 'stock' => 5, 'description' => 'Rapoo NK2600 wired keyboard, basic keyboard.'],
        ['name' => 'Rapoo NX1600 Wired Combo keyboard/Mouse', 'price' => 895.00, 'stock' => 5, 'description' => 'Rapoo NX1600 wired keyboard and mouse combo, wired set.'],
        ['name' => 'Rapoo NX1700 wired USB Combo keyboard/Mouse', 'price' => 995.00, 'stock' => 4, 'description' => 'Rapoo NX1700 wired USB keyboard and mouse combo, USB set.'],
        ['name' => 'Fantech Maxpower MK853 M.Keyboard', 'price' => 1295.00, 'stock' => 2, 'description' => 'Fantech Maxpower MK853 mechanical keyboard, gaming keyboard.'],
        ['name' => 'Redragon Lakshmi K606R M.keyboard', 'price' => 1495.00, 'stock' => 2, 'description' => 'Redragon Lakshmi K606R mechanical keyboard, RGB gaming keyboard.'],
        ['name' => 'Redragon KUMARA K552RGB-1 M.KEYBOARD', 'price' => 1995.00, 'stock' => 1, 'description' => 'Redragon KUMARA K552RGB-1 mechanical keyboard, RGB gaming keyboard.'],
        ['name' => 'Asus RA04 TUF Gaming K1 Gaming Keyboard', 'price' => 2495.00, 'stock' => 3, 'description' => 'ASUS TUF Gaming K1 gaming keyboard, military-grade durability.'],
        ['name' => 'A4tech FKS11 FStyler wired Keyboard', 'price' => 595.00, 'stock' => 5, 'description' => 'A4tech FKS11 FStyler wired keyboard, basic keyboard.'],
        ['name' => 'A4tech FBK11 FStyler wireless Keyboard', 'price' => 795.00, 'stock' => 4, 'description' => 'A4tech FBK11 FStyler wireless keyboard, wireless keyboard.'],
        ['name' => 'Delux KM36U M.Keyboard', 'price' => 1295.00, 'stock' => 1, 'description' => 'Delux KM36U mechanical keyboard, compact design.'],
        ['name' => 'Intex IT-1017RB usb Keyboard', 'price' => 495.00, 'stock' => 2, 'description' => 'Intex IT-1017RB USB keyboard, basic USB keyboard.'],
        ['name' => 'A4tech KRS-8572 Combo keyboard/Mouse', 'price' => 695.00, 'stock' => 2, 'description' => 'A4tech KRS-8572 keyboard and mouse combo, basic combo.'],
        ['name' => 'Aula AC101 Wired Combo keyboard/Mouse', 'price' => 795.00, 'stock' => 8, 'description' => 'Aula AC101 wired keyboard and mouse combo, wired combo.'],
        ['name' => 'Aula AK205 Wired Keyboard', 'price' => 695.00, 'stock' => 3, 'description' => 'Aula AK205 wired keyboard, basic wired keyboard.'],
        ['name' => 'Redragon Mitra K551-1 M.Keyboard', 'price' => 1795.00, 'stock' => 2, 'description' => 'Redragon Mitra K551-1 mechanical keyboard, gaming keyboard.'],
        ['name' => 'Redragon Mitra K551RGB-1 M.Keyboard', 'price' => 1995.00, 'stock' => 2, 'description' => 'Redragon Mitra K551RGB-1 mechanical keyboard, RGB gaming keyboard.'],
        ['name' => 'Redragon Dark avenger K568RGB-1 M.Keyboard', 'price' => 2195.00, 'stock' => 1, 'description' => 'Redragon Dark Avenger K568RGB-1 mechanical keyboard, RGB gaming keyboard.'],
        ['name' => 'Redragon Gaming essentials K551RGB-BA 2in1 keyboard/Mouse Combo', 'price' => 2495.00, 'stock' => 2, 'description' => 'Redragon Gaming Essentials K551RGB-BA 2-in-1 keyboard and mouse combo, RGB gaming set.'],
        ['name' => 'Redragon S107 keyboard/Mouse Combo set', 'price' => 1295.00, 'stock' => 2, 'description' => 'Redragon S107 keyboard and mouse combo set, gaming combo.'],
        ['name' => 'Redragon SHRAPNEL K589RGB M. keyboard', 'price' => 2295.00, 'stock' => 2, 'description' => 'Redragon SHRAPNEL K589RGB mechanical keyboard, RGB gaming keyboard.'],
        ['name' => 'Redragon Dyausz K509RGB M. KEYBOARD', 'price' => 1995.00, 'stock' => 2, 'description' => 'Redragon Dyausz K509RGB mechanical keyboard, RGB gaming keyboard.'],
        ['name' => 'A4tech KRS-85 Keyboard', 'price' => 595.00, 'stock' => 6, 'description' => 'A4tech KRS-85 keyboard, basic keyboard.'],
        ['name' => 'Machenike CKM500 wireless Keyboard', 'price' => 1795.00, 'stock' => 4, 'description' => 'Machenike CKM500 wireless keyboard, wireless keyboard.'],
        ['name' => 'Machenike K520-B108 M. Keyboard', 'price' => 2195.00, 'stock' => 5, 'description' => 'Machenike K520-B108 mechanical keyboard, gaming keyboard.']
    ];
    
    // Laptop Holders
    $laptopHolders = [
        ['name' => 'Aula Wind F61 Laptop Holder', 'price' => 795.00, 'stock' => 3, 'description' => 'Aula Wind F61 laptop holder, adjustable laptop stand.']
    ];
    
    // Speakers
    $speakers = [
        ['name' => 'Aula N-189 speaker', 'price' => 1295.00, 'stock' => 5, 'description' => 'Aula N-189 speaker, computer speaker with good sound quality.'],
        ['name' => 'Aula N-107 speaker', 'price' => 995.00, 'stock' => 4, 'description' => 'Aula N-107 speaker, basic computer speaker.'],
        ['name' => 'Mini Speaker A10 wireless', 'price' => 695.00, 'stock' => 10, 'description' => 'Mini Speaker A10 wireless, portable wireless speaker.'],
        ['name' => 'Doss DS-1209 BT Speaker', 'price' => 1495.00, 'stock' => 3, 'description' => 'Doss DS-1209 Bluetooth speaker, wireless speaker with good sound.'],
        ['name' => 'Edifier G1000 BT Speaker', 'price' => 1995.00, 'stock' => 2, 'description' => 'Edifier G1000 Bluetooth speaker, gaming speaker with RGB lighting.'],
        ['name' => 'Edifier MP260 BT Speaker', 'price' => 1795.00, 'stock' => 2, 'description' => 'Edifier MP260 Bluetooth speaker, portable speaker.'],
        ['name' => 'Edifier R19U M.Speaker', 'price' => 1295.00, 'stock' => 2, 'description' => 'Edifier R19U multimedia speaker, computer speaker.'],
        ['name' => 'Asimom DS-1198 SPEAKER', 'price' => 995.00, 'stock' => 2, 'description' => 'Asimom DS-1198 speaker, basic computer speaker.'],
        ['name' => 'Aula N521 gaming speaker', 'price' => 1495.00, 'stock' => 4, 'description' => 'Aula N521 gaming speaker, gaming speaker with RGB lighting.'],
        ['name' => 'Aula N69 gaming speaker', 'price' => 1195.00, 'stock' => 6, 'description' => 'Aula N69 gaming speaker, gaming speaker.']
    ];
    
    // Add all products
    $totalInserted = 0;
    
    echo "Adding Keyboards...\n";
    $totalInserted += addProducts($pdo, $keyboards, 'Keyboards', $categoryMap);
    
    echo "\nAdding Laptop Holders...\n";
    $totalInserted += addProducts($pdo, $laptopHolders, 'Laptop Holders', $categoryMap);
    
    echo "\nAdding Speakers...\n";
    $totalInserted += addProducts($pdo, $speakers, 'Speakers', $categoryMap);
    
    echo "\nSuccessfully added $totalInserted products with reviews!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
