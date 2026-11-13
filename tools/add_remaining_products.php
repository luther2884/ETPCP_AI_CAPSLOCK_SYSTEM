<?php
// Add remaining products (Video Cards, Memory, PSU, Peripherals, etc.)
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
    
    // Video Cards
    $videocards = [
        ['name' => 'Gigabyte RX570', 'price' => 8995.00, 'stock' => 1, 'description' => 'Gigabyte Radeon RX 570 4GB GDDR5 graphics card, excellent for 1080p gaming.'],
        ['name' => 'Gigabyte GTX1050Ti', 'price' => 6995.00, 'stock' => 3, 'description' => 'Gigabyte GeForce GTX 1050 Ti 4GB GDDR5 graphics card, budget gaming card.'],
        ['name' => 'PowerColor RX 6700 XT 12GB GDDR6', 'price' => 24995.00, 'stock' => 1, 'description' => 'PowerColor Radeon RX 6700 XT 12GB GDDR6 graphics card, high-end gaming performance.'],
        ['name' => 'Asus TUF GTX 1660Ti 6GB OC GDDR6', 'price' => 12995.00, 'stock' => 2, 'description' => 'ASUS TUF GeForce GTX 1660 Ti 6GB OC GDDR6 graphics card, great 1080p gaming.'],
        ['name' => 'Asus TUF GTX 1650 4GB OC GDDR6', 'price' => 8995.00, 'stock' => 2, 'description' => 'ASUS TUF GeForce GTX 1650 4GB OC GDDR6 graphics card, entry-level gaming.'],
        ['name' => 'ASUS Phoenix GTX 1660 6GB OC GDDR5', 'price' => 10995.00, 'stock' => 1, 'description' => 'ASUS Phoenix GeForce GTX 1660 6GB OC GDDR5 graphics card, compact design.'],
        ['name' => 'MSI Ventus RTX 2060 6GB GDDR6', 'price' => 18995.00, 'stock' => 1, 'description' => 'MSI Ventus GeForce RTX 2060 6GB GDDR6 graphics card, ray tracing support.'],
        ['name' => 'Asus GTX 1050Ti 4GB DDR5', 'price' => 6995.00, 'stock' => 2, 'description' => 'ASUS GeForce GTX 1050 Ti 4GB DDR5 graphics card, reliable budget option.'],
        ['name' => 'INNO3D GTX 1050Ti 4GB DDR5', 'price' => 6495.00, 'stock' => 2, 'description' => 'INNO3D GeForce GTX 1050 Ti 4GB DDR5 graphics card, compact design.'],
        ['name' => 'INNO3D GTX 1660 6GB GDDR5', 'price' => 10995.00, 'stock' => 1, 'description' => 'INNO3D GeForce GTX 1660 6GB GDDR5 graphics card, good performance.'],
        ['name' => 'INNO3D GTX 1660 Super 6GB GDDR6', 'price' => 11995.00, 'stock' => 1, 'description' => 'INNO3D GeForce GTX 1660 Super 6GB GDDR6 graphics card, enhanced performance.'],
        ['name' => 'INNO3D GTX 1650 4GB GDDR6', 'price' => 7995.00, 'stock' => 1, 'description' => 'INNO3D GeForce GTX 1650 4GB GDDR6 graphics card, entry-level gaming.'],
        ['name' => 'Gigabyte RTX 4070 12GB GDDR6X', 'price' => 39995.00, 'stock' => 1, 'description' => 'Gigabyte GeForce RTX 4070 12GB GDDR6X graphics card, high-end gaming and content creation.'],
        ['name' => 'Gigabyte RTX 4060Ti 8GB GDDR6', 'price' => 24995.00, 'stock' => 1, 'description' => 'Gigabyte GeForce RTX 4060 Ti 8GB GDDR6 graphics card, excellent 1440p gaming.'],
        ['name' => 'Gigabyte RTX 4060 8GB GDDR6', 'price' => 18995.00, 'stock' => 1, 'description' => 'Gigabyte GeForce RTX 4060 8GB GDDR6 graphics card, great 1080p gaming.'],
        ['name' => 'Gigabyte RTX 4060Ti 16GB GDDR6', 'price' => 29995.00, 'stock' => 1, 'description' => 'Gigabyte GeForce RTX 4060 Ti 16GB GDDR6 graphics card, high VRAM for content creation.'],
        ['name' => 'MSI GT 710 2GB DDR3', 'price' => 1995.00, 'stock' => 2, 'description' => 'MSI GeForce GT 710 2GB DDR3 graphics card, basic display adapter.'],
        ['name' => 'INNO3D GT 730 2GB SDDR3', 'price' => 2295.00, 'stock' => 4, 'description' => 'INNO3D GeForce GT 730 2GB DDR3 graphics card, entry-level graphics.'],
        ['name' => 'INNO3D GT 1030 2GB GDDR5', 'price' => 3295.00, 'stock' => 2, 'description' => 'INNO3D GeForce GT 1030 2GB GDDR5 graphics card, basic gaming capable.'],
        ['name' => 'PALIT GTX 1050Ti 4GB GDDR5', 'price' => 6995.00, 'stock' => 2, 'description' => 'Palit GeForce GTX 1050 Ti 4GB GDDR5 graphics card, reliable budget gaming.'],
        ['name' => 'Palit RTX 3050 8GB GDDR6', 'price' => 15995.00, 'stock' => 2, 'description' => 'Palit GeForce RTX 3050 8GB GDDR6 graphics card, entry-level ray tracing.'],
        ['name' => 'Palit GTX 1050Ti 4GB DDR5', 'price' => 6995.00, 'stock' => 3, 'description' => 'Palit GeForce GTX 1050 Ti 4GB DDR5 graphics card, popular budget option.'],
        ['name' => 'Asus GTX 1050 2GB GDDR5', 'price' => 5995.00, 'stock' => 1, 'description' => 'ASUS GeForce GTX 1050 2GB GDDR5 graphics card, basic gaming card.'],
        ['name' => 'Afox GT 730 4096MB DDR3', 'price' => 1995.00, 'stock' => 2, 'description' => 'Afox GeForce GT 730 4GB DDR3 graphics card, basic display adapter.'],
        ['name' => 'Asus GT 710 2GB DDR3', 'price' => 1995.00, 'stock' => 2, 'description' => 'ASUS GeForce GT 710 2GB DDR3 graphics card, basic graphics solution.']
    ];
    
    // Insert video cards
    $stmt = $pdo->prepare("
        INSERT INTO products (name, description, price, stock, category_id, image_url, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'active')
    ");
    
    $inserted = 0;
    foreach ($videocards as $product) {
        try {
            $stmt->execute([
                $product['name'],
                $product['description'],
                $product['price'],
                $product['stock'],
                $categoryMap['Graphics Cards'],
                'img/products/placeholder.svg'
            ]);
            $productId = $pdo->lastInsertId();
            addReviews($pdo, $productId, $sampleReviews);
            $inserted++;
            echo "Added Video Card: " . $product['name'] . " - ₱" . number_format($product['price'], 2) . " (Stock: " . $product['stock'] . ")\n";
        } catch (PDOException $e) {
            echo "Error adding " . $product['name'] . ": " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nSuccessfully added $inserted video cards with reviews!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
