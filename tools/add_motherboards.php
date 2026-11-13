<?php
// Add motherboards with real prices and reviews
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getConnection();
    
    // Get category ID for motherboards
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = 'Motherboards'");
    $stmt->execute();
    $categoryId = $stmt->fetchColumn();
    
    if (!$categoryId) {
        echo "Motherboards category not found. Creating it...\n";
        $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES ('Motherboards', 'Computer Motherboards')");
        $stmt->execute();
        $categoryId = $pdo->lastInsertId();
    }
    
    // Sample reviews data
    $sampleReviews = [
        ['rating' => 5, 'comment' => 'Excellent motherboard! Works perfectly and great value for money.'],
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
        $numReviews = rand(3, 8); // Random number of reviews between 3-8
        
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
    
    // Motherboards with realistic Philippine prices
    $motherboards = [
        // Gigabyte Motherboards
        ['name' => 'Gigabyte B550M DS3H Motherboard', 'price' => 4995.00, 'stock' => 1, 'description' => 'Gigabyte B550M DS3H micro-ATX motherboard, AMD B550 chipset, supports Ryzen processors.'],
        ['name' => 'Gigabyte B450M DS3H Motherboard', 'price' => 3995.00, 'stock' => 1, 'description' => 'Gigabyte B450M DS3H micro-ATX motherboard, AMD B450 chipset, budget-friendly option.'],
        ['name' => 'Gigabyte B450M S2H V2 Motherboard', 'price' => 3295.00, 'stock' => 1, 'description' => 'Gigabyte B450M S2H V2 micro-ATX motherboard, AMD B450 chipset, entry-level board.'],
        ['name' => 'Gigabyte AB350M-HD3 Motherboard', 'price' => 2995.00, 'stock' => 2, 'description' => 'Gigabyte AB350M-HD3 micro-ATX motherboard, AMD B350 chipset, supports Ryzen processors.'],
        ['name' => 'Gigabyte A320M-S2H V2 Motherboard', 'price' => 2295.00, 'stock' => 1, 'description' => 'Gigabyte A320M-S2H V2 micro-ATX motherboard, AMD A320 chipset, basic motherboard.'],
        ['name' => 'Gigabyte B760M H DDR4 Motherboard', 'price' => 5995.00, 'stock' => 5, 'description' => 'Gigabyte B760M H DDR4 micro-ATX motherboard, Intel B760 chipset, supports 12th/13th Gen Intel.'],
        ['name' => 'Gigabyte A320M-S2H Motherboard', 'price' => 2495.00, 'stock' => 5, 'description' => 'Gigabyte A320M-S2H micro-ATX motherboard, AMD A320 chipset, budget motherboard.'],
        
        // MSI Motherboards
        ['name' => 'MSI B450M Mortar Max Motherboard', 'price' => 4995.00, 'stock' => 2, 'description' => 'MSI B450M Mortar Max micro-ATX motherboard, AMD B450 chipset, gaming-focused features.'],
        ['name' => 'MSI H110M PRO-VH Motherboard', 'price' => 1995.00, 'stock' => 4, 'description' => 'MSI H110M PRO-VH micro-ATX motherboard, Intel H110 chipset, supports 6th/7th Gen Intel.'],
        ['name' => 'MSI H110M PRO-VD Motherboard', 'price' => 1895.00, 'stock' => 4, 'description' => 'MSI H110M PRO-VD micro-ATX motherboard, Intel H110 chipset, basic Intel motherboard.'],
        ['name' => 'MSI B150M BAZOOKA Motherboard', 'price' => 2995.00, 'stock' => 2, 'description' => 'MSI B150M BAZOOKA micro-ATX motherboard, Intel B150 chipset, supports 6th Gen Intel.'],
        ['name' => 'MSI H310M PRO-VDH PLUS Motherboard', 'price' => 3295.00, 'stock' => 5, 'description' => 'MSI H310M PRO-VDH PLUS micro-ATX motherboard, Intel H310 chipset, supports 8th/9th Gen Intel.'],
        ['name' => 'MSI A320M PRO-VD/S Motherboard', 'price' => 2295.00, 'stock' => 2, 'description' => 'MSI A320M PRO-VD/S micro-ATX motherboard, AMD A320 chipset, basic AMD motherboard.'],
        ['name' => 'MSI BAZOOKA B350M Motherboard', 'price' => 3995.00, 'stock' => 2, 'description' => 'MSI BAZOOKA B350M micro-ATX motherboard, AMD B350 chipset, supports Ryzen processors.'],
        ['name' => 'MSI B350 PRO-VD PLUS Motherboard', 'price' => 3495.00, 'stock' => 4, 'description' => 'MSI B350 PRO-VD PLUS micro-ATX motherboard, AMD B350 chipset, budget Ryzen motherboard.'],
        ['name' => 'MSI TOMAWAK B350 Motherboard', 'price' => 4995.00, 'stock' => 1, 'description' => 'MSI TOMAWAK B350 ATX motherboard, AMD B350 chipset, gaming motherboard.'],
        ['name' => 'MSI B360M GAMING PLUS Motherboard', 'price' => 4995.00, 'stock' => 2, 'description' => 'MSI B360M GAMING PLUS micro-ATX motherboard, Intel B360 chipset, gaming features.'],
        ['name' => 'MSI B360 GAMING PRO CARBON Motherboard', 'price' => 6995.00, 'stock' => 2, 'description' => 'MSI B360 GAMING PRO CARBON ATX motherboard, Intel B360 chipset, premium gaming board.'],
        ['name' => 'MSI Z370 GAMING PLUS Motherboard', 'price' => 7995.00, 'stock' => 2, 'description' => 'MSI Z370 GAMING PLUS ATX motherboard, Intel Z370 chipset, overclocking support.'],
        ['name' => 'MSI B450M PRO -VDH PLUS Motherboard', 'price' => 3995.00, 'stock' => 1, 'description' => 'MSI B450M PRO-VDH PLUS micro-ATX motherboard, AMD B450 chipset, business motherboard.'],
        ['name' => 'MSI B450M PRO-M2 V2 Motherboard', 'price' => 3495.00, 'stock' => 2, 'description' => 'MSI B450M PRO-M2 V2 micro-ATX motherboard, AMD B450 chipset, compact design.'],
        ['name' => 'MSI B450 TOMAHAWK MAX Motherboard', 'price' => 5995.00, 'stock' => 1, 'description' => 'MSI B450 TOMAHAWK MAX ATX motherboard, AMD B450 chipset, popular gaming motherboard.'],
        ['name' => 'MSI B350 TOMAHAWK Motherboard', 'price' => 4995.00, 'stock' => 1, 'description' => 'MSI B350 TOMAHAWK ATX motherboard, AMD B350 chipset, gaming motherboard.'],
        ['name' => 'MSI PRO SERIES PRO H510M-B Motherboard', 'price' => 3995.00, 'stock' => 1, 'description' => 'MSI PRO H510M-B micro-ATX motherboard, Intel H510 chipset, business motherboard.'],
        ['name' => 'MSI MAG B550M BAZOOKA Motherboard', 'price' => 5995.00, 'stock' => 2, 'description' => 'MSI MAG B550M BAZOOKA micro-ATX motherboard, AMD B550 chipset, gaming motherboard.'],
        ['name' => 'MSI PRO H610M-E DDR4 Motherboard', 'price' => 4495.00, 'stock' => 2, 'description' => 'MSI PRO H610M-E DDR4 micro-ATX motherboard, Intel H610 chipset, supports 12th Gen Intel.'],
        
        // ASUS Motherboards
        ['name' => 'Asus TUF B450M-Pro II Motherboard', 'price' => 4995.00, 'stock' => 1, 'description' => 'ASUS TUF B450M-PRO II micro-ATX motherboard, AMD B450 chipset, military-grade components.'],
        ['name' => 'Aorus B450 AORUS M Motherboard', 'price' => 5995.00, 'stock' => 2, 'description' => 'Gigabyte AORUS B450 AORUS M micro-ATX motherboard, AMD B450 chipset, gaming motherboard.'],
        ['name' => 'Asus Prime B365M-A Motherboard', 'price' => 3995.00, 'stock' => 1, 'description' => 'ASUS Prime B365M-A micro-ATX motherboard, Intel B365 chipset, supports 8th/9th Gen Intel.'],
        ['name' => 'Asus TUF Z370-PLUS Motherboard', 'price' => 6995.00, 'stock' => 2, 'description' => 'ASUS TUF Z370-PLUS ATX motherboard, Intel Z370 chipset, gaming motherboard.'],
        ['name' => 'ASUS A68HM-K Motherboard', 'price' => 1995.00, 'stock' => 2, 'description' => 'ASUS A68HM-K micro-ATX motherboard, AMD A68H chipset, FM2+ socket.'],
        ['name' => 'ASUS H110M-D Motherboard', 'price' => 2295.00, 'stock' => 2, 'description' => 'ASUS H110M-D micro-ATX motherboard, Intel H110 chipset, supports 6th/7th Gen Intel.'],
        ['name' => 'ASUS EZ-B150M-V5 Motherboard', 'price' => 2995.00, 'stock' => 2, 'description' => 'ASUS EZ-B150M-V5 micro-ATX motherboard, Intel B150 chipset, supports 6th Gen Intel.'],
        ['name' => 'ASUS PRIME B250M-D Motherboard', 'price' => 3495.00, 'stock' => 2, 'description' => 'ASUS PRIME B250M-D micro-ATX motherboard, Intel B250 chipset, supports 7th Gen Intel.'],
        ['name' => 'ASUS EX-H310M-V3 R2.0 Motherboard', 'price' => 3295.00, 'stock' => 5, 'description' => 'ASUS EX-H310M-V3 R2.0 micro-ATX motherboard, Intel H310 chipset, supports 8th/9th Gen Intel.'],
        ['name' => 'PRIME A320M-K Motherboard', 'price' => 2295.00, 'stock' => 5, 'description' => 'ASUS PRIME A320M-K micro-ATX motherboard, AMD A320 chipset, basic AMD motherboard.'],
        ['name' => 'ROG STRIX B360-F GAMING Motherboard', 'price' => 6995.00, 'stock' => 2, 'description' => 'ASUS ROG STRIX B360-F GAMING ATX motherboard, Intel B360 chipset, gaming motherboard.'],
        ['name' => 'TUF B360M-E GAMING Motherboard', 'price' => 4995.00, 'stock' => 2, 'description' => 'ASUS TUF B360M-E GAMING micro-ATX motherboard, Intel B360 chipset, gaming motherboard.'],
        ['name' => 'ASUS PRIME B365-A Motherboard', 'price' => 4495.00, 'stock' => 1, 'description' => 'ASUS PRIME B365-A ATX motherboard, Intel B365 chipset, supports 8th/9th Gen Intel.'],
        ['name' => 'ASUS TUF B365- PLUS GAMING Motherboard', 'price' => 5995.00, 'stock' => 2, 'description' => 'ASUS TUF B365-PLUS GAMING ATX motherboard, Intel B365 chipset, gaming motherboard.'],
        ['name' => 'PRIME Z370-A Motherboard', 'price' => 7995.00, 'stock' => 2, 'description' => 'ASUS PRIME Z370-A ATX motherboard, Intel Z370 chipset, overclocking support.'],
        ['name' => 'ASUS ROG STRIX Z370-G GAMING (WIFI) Motherboard', 'price' => 9995.00, 'stock' => 2, 'description' => 'ASUS ROG STRIX Z370-G GAMING (WIFI) micro-ATX motherboard, Intel Z370 chipset, WiFi included.'],
        ['name' => 'ASUS ROG STRIX Z370-F GAMING Motherboard', 'price' => 8995.00, 'stock' => 1, 'description' => 'ASUS ROG STRIX Z370-F GAMING ATX motherboard, Intel Z370 chipset, premium gaming board.'],
        ['name' => 'ASUS TUF B450M-PLUS GAMING Motherboard', 'price' => 4995.00, 'stock' => 2, 'description' => 'ASUS TUF B450M-PLUS GAMING micro-ATX motherboard, AMD B450 chipset, gaming motherboard.'],
        ['name' => 'ASUS TUF B450M-PRO GAMING Motherboard', 'price' => 5995.00, 'stock' => 2, 'description' => 'ASUS TUF B450M-PRO GAMING micro-ATX motherboard, AMD B450 chipset, gaming motherboard.'],
        ['name' => 'ASUS TUF GAMING B450M - PRO II Motherboard', 'price' => 5995.00, 'stock' => 1, 'description' => 'ASUS TUF GAMING B450M-PRO II micro-ATX motherboard, AMD B450 chipset, gaming motherboard.'],
        ['name' => 'ASUS EX-H510M-V3 Motherboard', 'price' => 3995.00, 'stock' => 1, 'description' => 'ASUS EX-H510M-V3 micro-ATX motherboard, Intel H510 chipset, supports 10th/11th Gen Intel.'],
        ['name' => 'ASUS PRIME B450- A II Motherboard', 'price' => 4995.00, 'stock' => 1, 'description' => 'ASUS PRIME B450-A II ATX motherboard, AMD B450 chipset, updated version.'],
        ['name' => 'ASUS PRIME A520M-K Motherboard', 'price' => 2995.00, 'stock' => 2, 'description' => 'ASUS PRIME A520M-K micro-ATX motherboard, AMD A520 chipset, budget AMD motherboard.'],
        ['name' => 'ASUS PRIME A520M-K Motherboard', 'price' => 2995.00, 'stock' => 1, 'description' => 'ASUS PRIME A520M-K micro-ATX motherboard, AMD A520 chipset, basic AMD motherboard.'],
        ['name' => 'ASUS PRIME H610M-K D4 Motherboard', 'price' => 3995.00, 'stock' => 5, 'description' => 'ASUS PRIME H610M-K D4 micro-ATX motherboard, Intel H610 chipset, supports 12th Gen Intel.'],
        
        // Other brands
        ['name' => 'BIOSTAR A75MG Motherboard', 'price' => 1995.00, 'stock' => 2, 'description' => 'BIOSTAR A75MG micro-ATX motherboard, AMD A75 chipset, FM1 socket.'],
        ['name' => 'GIGABYTE H110M - S2 Motherboard', 'price' => 2295.00, 'stock' => 1, 'description' => 'Gigabyte H110M-S2 micro-ATX motherboard, Intel H110 chipset, supports 6th/7th Gen Intel.'],
        ['name' => 'GIGABYTE H110M-S2 Motherboard', 'price' => 2295.00, 'stock' => 2, 'description' => 'Gigabyte H110M-S2 micro-ATX motherboard, Intel H110 chipset, basic Intel motherboard.'],
        ['name' => 'GIGABYTE H110M-H Motherboard', 'price' => 2195.00, 'stock' => 2, 'description' => 'Gigabyte H110M-H micro-ATX motherboard, Intel H110 chipset, compact design.'],
        ['name' => 'GIGABYTE B150M-D3H Motherboard', 'price' => 2995.00, 'stock' => 1, 'description' => 'Gigabyte B150M-D3H micro-ATX motherboard, Intel B150 chipset, supports 6th Gen Intel.'],
        ['name' => 'DURATHON 2 H110M4-C2 SERIES Motherboard', 'price' => 1995.00, 'stock' => 2, 'description' => 'DURATHON H110M4-C2 micro-ATX motherboard, Intel H110 chipset, budget motherboard.'],
        ['name' => 'GIGABYTE Z270M-D3H Motherboard', 'price' => 4995.00, 'stock' => 1, 'description' => 'Gigabyte Z270M-D3H micro-ATX motherboard, Intel Z270 chipset, supports 6th/7th Gen Intel.'],
        ['name' => 'GIGABYTE B460M POWER Motherboard', 'price' => 4995.00, 'stock' => 2, 'description' => 'Gigabyte B460M POWER micro-ATX motherboard, Intel B460 chipset, supports 10th Gen Intel.'],
        ['name' => 'GIGABYTE A320M-DS2 Motherboard', 'price' => 2295.00, 'stock' => 2, 'description' => 'Gigabyte A320M-DS2 micro-ATX motherboard, AMD A320 chipset, basic AMD motherboard.'],
        ['name' => 'GIGABYTE H610M K DDR4 Motherboard', 'price' => 3995.00, 'stock' => 4, 'description' => 'Gigabyte H610M K DDR4 micro-ATX motherboard, Intel H610 chipset, supports 12th Gen Intel.'],
        ['name' => 'ASROCK B550M-HDV Motherboard', 'price' => 3995.00, 'stock' => 1, 'description' => 'ASRock B550M-HDV micro-ATX motherboard, AMD B550 chipset, budget B550 motherboard.']
    ];
    
    // Insert motherboards
    $stmt = $pdo->prepare("
        INSERT INTO products (name, description, price, stock, category_id, image_url, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'active')
    ");
    
    $inserted = 0;
    foreach ($motherboards as $product) {
        try {
            $stmt->execute([
                $product['name'],
                $product['description'],
                $product['price'],
                $product['stock'],
                $categoryId,
                'img/products/placeholder.svg'
            ]);
            $productId = $pdo->lastInsertId();
            addReviews($pdo, $productId, $sampleReviews);
            $inserted++;
            echo "Added: " . $product['name'] . " - ₱" . number_format($product['price'], 2) . " (Stock: " . $product['stock'] . ")\n";
        } catch (PDOException $e) {
            echo "Error adding " . $product['name'] . ": " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nSuccessfully added $inserted motherboards with reviews!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
