<?php
// Add all remaining products in one comprehensive script
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
    
    // Memory DIMM
    $memoryDIMM = [
        ['name' => 'Hp 8GB PC4 3200 U-Dimm Memory', 'price' => 1995.00, 'stock' => 2, 'description' => 'HP 8GB DDR4-3200 U-DIMM memory module, reliable performance.'],
        ['name' => 'TeamGroup T.Force 8GB DDR4 3200 DIMM Memory', 'price' => 1795.00, 'stock' => 5, 'description' => 'TeamGroup T-Force 8GB DDR4-3200 DIMM memory, gaming optimized.'],
        ['name' => 'Transcend 16GB DDR4 3200 I-DIMM Memory', 'price' => 3295.00, 'stock' => 5, 'description' => 'Transcend 16GB DDR4-3200 I-DIMM memory, high capacity.'],
        ['name' => 'Transcend 4GB DDR4 2666 U-DIMM Memory', 'price' => 995.00, 'stock' => 10, 'description' => 'Transcend 4GB DDR4-2666 U-DIMM memory, basic capacity.'],
        ['name' => 'Transcend 4GB DDR4 3200 U-DIMM Memory', 'price' => 1195.00, 'stock' => 10, 'description' => 'Transcend 4GB DDR4-3200 U-DIMM memory, faster speed.'],
        ['name' => 'Tean Group Elite 8GB DDR4 2666 DIMM Memory', 'price' => 1595.00, 'stock' => 6, 'description' => 'Team Group Elite 8GB DDR4-2666 DIMM memory, reliable performance.'],
        ['name' => 'G.Skill 16GBx2 DDR4 3600 DIMM Memory', 'price' => 5995.00, 'stock' => 3, 'description' => 'G.Skill 32GB (16GBx2) DDR4-3600 DIMM memory kit, high performance.'],
        ['name' => 'Kingston 8GB DDR4 3200 DIMM Memory', 'price' => 1995.00, 'stock' => 9, 'description' => 'Kingston 8GB DDR4-3200 DIMM memory, trusted brand.'],
        ['name' => 'Kingston Fury 8GB DDR4 2666 DIMM Memory', 'price' => 1795.00, 'stock' => 10, 'description' => 'Kingston Fury 8GB DDR4-2666 DIMM memory, gaming series.'],
        ['name' => 'Hyper X fury 16GB DDR4 3200 I-DIMM Memory', 'price' => 3295.00, 'stock' => 10, 'description' => 'HyperX Fury 16GB DDR4-3200 I-DIMM memory, high performance.'],
        ['name' => 'Hyper X 16GB DDR4 2666 DIMM Memory', 'price' => 2995.00, 'stock' => 8, 'description' => 'HyperX 16GB DDR4-2666 DIMM memory, reliable performance.'],
        ['name' => 'Hyper X 16GB DDR4 2400 DIMM Memory', 'price' => 2795.00, 'stock' => 2, 'description' => 'HyperX 16GB DDR4-2400 DIMM memory, basic speed.'],
        ['name' => 'Hyper X HX432C16FB4/16 16GB DDR4 3200 DIMM MEMORY', 'price' => 3295.00, 'stock' => 10, 'description' => 'HyperX HX432C16FB4/16 16GB DDR4-3200 DIMM memory, specific model.'],
        ['name' => 'Hyper X HX426C16FB3/8 8GB DDR4 2666 DIMM Memory', 'price' => 1795.00, 'stock' => 8, 'description' => 'HyperX HX426C16FB3/8 8GB DDR4-2666 DIMM memory, specific model.'],
        ['name' => 'GSkill Aegis 4GB DDR4 2400 DIMM Memory', 'price' => 995.00, 'stock' => 10, 'description' => 'G.Skill Aegis 4GB DDR4-2400 DIMM memory, budget option.'],
        ['name' => 'ADATA 16GB DDR4 3200 SO-DIMM Memory', 'price' => 2995.00, 'stock' => 3, 'description' => 'ADATA 16GB DDR4-3200 SO-DIMM memory, laptop memory.'],
        ['name' => 'ADATA 16GB DDR4 3200 U-DIMM Memory', 'price' => 2995.00, 'stock' => 2, 'description' => 'ADATA 16GB DDR4-3200 U-DIMM memory, desktop memory.'],
        ['name' => 'Dahua 8GB DDR4 3200 DIMM Memory', 'price' => 1795.00, 'stock' => 10, 'description' => 'Dahua 8GB DDR4-3200 DIMM memory, surveillance grade.']
    ];
    
    // Memory SODIMM
    $memorySODIMM = [
        ['name' => 'Kingston 8GB DDR4 2400 SODIMM Memory', 'price' => 1795.00, 'stock' => 5, 'description' => 'Kingston 8GB DDR4-2400 SO-DIMM memory, laptop memory.'],
        ['name' => 'Kingston 16GB DDR4 2666 SODIMM Memory', 'price' => 2995.00, 'stock' => 10, 'description' => 'Kingston 16GB DDR4-2666 SO-DIMM memory, high capacity laptop memory.'],
        ['name' => 'Kingston 8GB DDR4 2666 SODIMM Memory', 'price' => 1995.00, 'stock' => 9, 'description' => 'Kingston 8GB DDR4-2666 SO-DIMM memory, laptop memory.'],
        ['name' => 'Crucial 4GB DDR4 2400 SODIMM Memory', 'price' => 995.00, 'stock' => 1, 'description' => 'Crucial 4GB DDR4-2400 SO-DIMM memory, basic laptop memory.'],
        ['name' => 'Hp 16GB DDR4 2666 SODIMM Memory', 'price' => 2995.00, 'stock' => 2, 'description' => 'HP 16GB DDR4-2666 SO-DIMM memory, laptop memory.'],
        ['name' => 'Kingmax 4GB DDR3 1600 SODIMM Memory', 'price' => 795.00, 'stock' => 2, 'description' => 'Kingmax 4GB DDR3-1600 SO-DIMM memory, older laptop memory.'],
        ['name' => 'Transcend 8GB DDR4 2666 SODIMM Memory', 'price' => 1995.00, 'stock' => 4, 'description' => 'Transcend 8GB DDR4-2666 SO-DIMM memory, laptop memory.'],
        ['name' => 'Transcend 4GB DDR4 3200 SODIMM Memory', 'price' => 1195.00, 'stock' => 5, 'description' => 'Transcend 4GB DDR4-3200 SO-DIMM memory, laptop memory.'],
        ['name' => 'Kingston 8GB DDR4 3200 SODIMM Memory', 'price' => 2195.00, 'stock' => 5, 'description' => 'Kingston 8GB DDR4-3200 SO-DIMM memory, laptop memory.'],
        ['name' => 'Kingston 16GB DDR4 3200 SODIMM Memory', 'price' => 3295.00, 'stock' => 1, 'description' => 'Kingston 16GB DDR4-3200 SO-DIMM memory, high capacity laptop memory.'],
        ['name' => 'Apacer 8GB DDR4 2400 SODIMM Memory', 'price' => 1795.00, 'stock' => 4, 'description' => 'Apacer 8GB DDR4-2400 SO-DIMM memory, laptop memory.'],
        ['name' => 'Apacer 4GB DDR4 2400 SODIMM Memory', 'price' => 995.00, 'stock' => 5, 'description' => 'Apacer 4GB DDR4-2400 SO-DIMM memory, laptop memory.'],
        ['name' => 'Apacer 4GB DDR4 2666 SODIMM Memory', 'price' => 1195.00, 'stock' => 10, 'description' => 'Apacer 4GB DDR4-2666 SO-DIMM memory, laptop memory.']
    ];
    
    // Power Supplies
    $powerSupplies = [
        ['name' => 'Antec ATOM B550 Bronze PSU', 'price' => 2995.00, 'stock' => 2, 'description' => 'Antec ATOM B550 550W 80+ Bronze PSU, reliable power supply.'],
        ['name' => 'Antec 550W 80PLUS PSU', 'price' => 2495.00, 'stock' => 3, 'description' => 'Antec 550W 80+ PSU, basic power supply.'],
        ['name' => 'Antec NeoEco Ne 650 G.M psu', 'price' => 3995.00, 'stock' => 2, 'description' => 'Antec NeoEco NE650G-M 650W 80+ Gold PSU, modular design.'],
        ['name' => 'Antec NeoEco Ne 750 G.M psu', 'price' => 4995.00, 'stock' => 2, 'description' => 'Antec NeoEco NE750G-M 750W 80+ Gold PSU, modular design.'],
        ['name' => 'Antec NeoEco Ne 850 G.M psu', 'price' => 5995.00, 'stock' => 2, 'description' => 'Antec NeoEco NE850G-M 850W 80+ Gold PSU, high wattage.'],
        ['name' => 'Huntkey GS700 PSU', 'price' => 2995.00, 'stock' => 2, 'description' => 'Huntkey GS700 700W PSU, budget power supply.'],
        ['name' => 'Huntkey GS600 PSU', 'price' => 2495.00, 'stock' => 2, 'description' => 'Huntkey GS600 600W PSU, budget power supply.'],
        ['name' => 'Huntkey GS500 PSU', 'price' => 1995.00, 'stock' => 5, 'description' => 'Huntkey GS500 500W PSU, entry-level power supply.'],
        ['name' => 'Cooler Master 750 Bronze PSU', 'price' => 3995.00, 'stock' => 2, 'description' => 'Cooler Master 750W 80+ Bronze PSU, reliable power supply.'],
        ['name' => 'Cooler Master 650 Bronze PSU', 'price' => 3495.00, 'stock' => 1, 'description' => 'Cooler Master 650W 80+ Bronze PSU, reliable power supply.'],
        ['name' => 'Cooler Master 550 Bronze PSU', 'price' => 2995.00, 'stock' => 1, 'description' => 'Cooler Master 550W 80+ Bronze PSU, reliable power supply.'],
        ['name' => 'Power logic ATX PM-600 PSU', 'price' => 1995.00, 'stock' => 3, 'description' => 'Power Logic ATX PM-600 600W PSU, budget power supply.'],
        ['name' => 'Corsair CV550 ATX PSU', 'price' => 2995.00, 'stock' => 1, 'description' => 'Corsair CV550 550W ATX PSU, reliable power supply.'],
        ['name' => 'Orion ATX-700W PSU', 'price' => 2495.00, 'stock' => 3, 'description' => 'Orion ATX-700W PSU, budget power supply.'],
        ['name' => 'LC-8700BTXN Standard PSU', 'price' => 1995.00, 'stock' => 2, 'description' => 'LC-8700BTXN 700W Standard PSU, basic power supply.']
    ];
    
    // Add all products
    $totalInserted = 0;
    
    echo "Adding Memory DIMM...\n";
    $totalInserted += addProducts($pdo, $memoryDIMM, 'Memory DIMM', $categoryMap);
    
    echo "\nAdding Memory SODIMM...\n";
    $totalInserted += addProducts($pdo, $memorySODIMM, 'Memory SODIMM', $categoryMap);
    
    echo "\nAdding Power Supplies...\n";
    $totalInserted += addProducts($pdo, $powerSupplies, 'Power Supply', $categoryMap);
    
    echo "\nSuccessfully added $totalInserted products with reviews!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
