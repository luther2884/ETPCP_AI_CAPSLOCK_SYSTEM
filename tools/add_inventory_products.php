<?php
// Add comprehensive inventory with real prices and reviews
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getConnection();
    
    // First, let's add categories if they don't exist
    $categories = [
        ['name' => 'Processors', 'description' => 'CPU Processors'],
        ['name' => 'Mini PCs', 'description' => 'Mini Personal Computers'],
        ['name' => 'Motherboards', 'description' => 'Computer Motherboards'],
        ['name' => 'Graphics Cards', 'description' => 'GPU Graphics Cards'],
        ['name' => 'Memory DIMM', 'description' => 'Desktop RAM Memory'],
        ['name' => 'Memory SODIMM', 'description' => 'Laptop RAM Memory'],
        ['name' => 'Power Supply', 'description' => 'PSU Power Supplies'],
        ['name' => 'Keyboards', 'description' => 'Keyboards and Combos'],
        ['name' => 'Laptop Holders', 'description' => 'Laptop Stands and Holders'],
        ['name' => 'Speakers', 'description' => 'Computer Speakers'],
        ['name' => 'Headsets', 'description' => 'Gaming and Office Headsets'],
        ['name' => 'Mice', 'description' => 'Computer Mice and Gaming Mice'],
        ['name' => 'Mousepads', 'description' => 'Gaming and Office Mousepads'],
        ['name' => 'CPU Coolers', 'description' => 'CPU Fans and Coolers'],
        ['name' => 'Smart Watches', 'description' => 'Smart Watches and Fitness Trackers']
    ];
    
    foreach ($categories as $category) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name, description) VALUES (?, ?)");
        $stmt->execute([$category['name'], $category['description']]);
    }
    
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
    
    // Create product_reviews table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
            comment TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        )
    ");
    
    // Processors and Mini PCs
    $processors = [
        ['name' => 'INTEL NUC Celeron NUC7CJYSN Mini PC', 'price' => 8995.00, 'stock' => 2, 'category' => 'Mini PCs', 'description' => 'Intel NUC Mini PC with Celeron processor, perfect for office work and media streaming.'],
        ['name' => 'INTEL NUC CORE i5 10th Gen A Mini PC Kit', 'price' => 18995.00, 'stock' => 1, 'category' => 'Mini PCs', 'description' => 'Intel NUC Mini PC Kit with 10th Gen Core i5 processor, ideal for compact computing needs.'],
        ['name' => 'INTEL NUC i5 Mini PC kit', 'price' => 15995.00, 'stock' => 1, 'category' => 'Mini PCs', 'description' => 'Intel NUC i5 Mini PC kit, compact and powerful solution for space-constrained environments.'],
        ['name' => 'INTEL NUC i3 Mini PC kit', 'price' => 12995.00, 'stock' => 2, 'category' => 'Mini PCs', 'description' => 'Intel NUC i3 Mini PC kit, budget-friendly mini PC for basic computing tasks.'],
        ['name' => 'Machenike Machreator Mini PC 2 i5-12450H', 'price' => 24995.00, 'stock' => 1, 'category' => 'Mini PCs', 'description' => 'Machenike Machreator Mini PC with Intel i5-12450H processor, gaming-ready mini PC.'],
        ['name' => 'Machenike Machreator Mini PC 2 i7-12650H', 'price' => 29995.00, 'stock' => 1, 'category' => 'Mini PCs', 'description' => 'Machenike Machreator Mini PC with Intel i7-12650H processor, high-performance mini PC.'],
        
        // Intel Processors
        ['name' => 'INTEL CELERON G1840 PROCESSOR', 'price' => 1295.00, 'stock' => 3, 'category' => 'Processors', 'description' => 'Intel Celeron G1840 dual-core processor, entry-level CPU for basic computing.'],
        ['name' => 'INTEL PENTIUM G3250 PROCESSOR', 'price' => 1995.00, 'stock' => 3, 'category' => 'Processors', 'description' => 'Intel Pentium G3250 dual-core processor, budget-friendly CPU with good performance.'],
        ['name' => 'INTEL PENTIUM G3260 PROCESSOR', 'price' => 2295.00, 'stock' => 3, 'category' => 'Processors', 'description' => 'Intel Pentium G3260 dual-core processor, reliable CPU for everyday computing.'],
        ['name' => 'INTEL CORE I3-12100 PROCESSOR', 'price' => 5995.00, 'stock' => 3, 'category' => 'Processors', 'description' => 'Intel Core i3-12100 12th Gen processor, 4 cores, excellent single-thread performance.'],
        ['name' => 'INTEL CORE I3-10105 PROCESSOR', 'price' => 4995.00, 'stock' => 5, 'category' => 'Processors', 'description' => 'Intel Core i3-10105 10th Gen processor, 4 cores, great value for money.'],
        ['name' => 'INTEL CORE I5-11400F PROCESSOR', 'price' => 8995.00, 'stock' => 2, 'category' => 'Processors', 'description' => 'Intel Core i5-11400F 11th Gen processor, 6 cores, no integrated graphics.'],
        ['name' => 'INTEL CORE I5-12400F PROCESSOR', 'price' => 9995.00, 'stock' => 2, 'category' => 'Processors', 'description' => 'Intel Core i5-12400F 12th Gen processor, 6 cores, excellent gaming performance.'],
        ['name' => 'INTEL CORE I5-12400 PROCESSOR', 'price' => 10995.00, 'stock' => 2, 'category' => 'Processors', 'description' => 'Intel Core i5-12400 12th Gen processor, 6 cores, with integrated graphics.'],
        ['name' => 'INTEL CORE I5-9400F PROCESSOR', 'price' => 7995.00, 'stock' => 2, 'category' => 'Processors', 'description' => 'Intel Core i5-9400F 9th Gen processor, 6 cores, reliable performance.'],
        ['name' => 'INTEL CORE I5-13400 PROCESSOR', 'price' => 12995.00, 'stock' => 2, 'category' => 'Processors', 'description' => 'Intel Core i5-13400 13th Gen processor, 10 cores, hybrid architecture.'],
        
        // AMD Processors
        ['name' => 'AMD Ryzen 7 5700G PROCESSOR', 'price' => 12995.00, 'stock' => 3, 'category' => 'Processors', 'description' => 'AMD Ryzen 7 5700G processor, 8 cores, 16 threads, with integrated Radeon graphics.'],
        ['name' => 'AMD Ryzen 5 5600G PROCESSOR', 'price' => 8995.00, 'stock' => 1, 'category' => 'Processors', 'description' => 'AMD Ryzen 5 5600G processor, 6 cores, 12 threads, with integrated Radeon graphics.'],
        ['name' => 'AMD Ryzen 5 5500 PROCESSOR', 'price' => 6995.00, 'stock' => 2, 'category' => 'Processors', 'description' => 'AMD Ryzen 5 5500 processor, 6 cores, 12 threads, excellent gaming performance.'],
        ['name' => 'AMD Ryzen 5 3600 PROCESSOR', 'price' => 5995.00, 'stock' => 3, 'category' => 'Processors', 'description' => 'AMD Ryzen 5 3600 processor, 6 cores, 12 threads, great value for money.'],
        ['name' => 'AMD Ryzen 5 2600 PROCESSOR', 'price' => 4995.00, 'stock' => 3, 'category' => 'Processors', 'description' => 'AMD Ryzen 5 2600 processor, 6 cores, 12 threads, budget-friendly option.'],
        ['name' => 'AMD Ryzen 3 3200G PROCESSOR', 'price' => 3995.00, 'stock' => 1, 'category' => 'Processors', 'description' => 'AMD Ryzen 3 3200G processor, 4 cores, 4 threads, with integrated graphics.'],
        ['name' => 'AMD Ryzen 5 4600G PROCESSOR', 'price' => 7995.00, 'stock' => 5, 'category' => 'Processors', 'description' => 'AMD Ryzen 5 4600G processor, 6 cores, 12 threads, with integrated Radeon graphics.'],
        ['name' => 'AMD Athlon 200GE PROCESSOR', 'price' => 2295.00, 'stock' => 1, 'category' => 'Processors', 'description' => 'AMD Athlon 200GE processor, 2 cores, 4 threads, entry-level CPU.'],
        ['name' => 'AMD Ryzen 3 PRO 4350G Tray type Processor', 'price' => 5995.00, 'stock' => 7, 'category' => 'Processors', 'description' => 'AMD Ryzen 3 PRO 4350G processor, 4 cores, 8 threads, business-grade CPU.']
    ];
    
    // Insert processors and mini PCs
    $stmt = $pdo->prepare("
        INSERT INTO products (name, description, price, stock, category_id, image_url, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'active')
    ");
    
    $inserted = 0;
    foreach ($processors as $product) {
        try {
            $stmt->execute([
                $product['name'],
                $product['description'],
                $product['price'],
                $product['stock'],
                $categoryMap[$product['category']],
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
    
    echo "\nSuccessfully added $inserted processors and mini PCs with reviews!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
