<?php
// products.php - Products listing page
require_once '../../config/functions.php';

$pageTitle = 'Products - TechWorld';

// Get filter parameters
$categoryId = $_GET['category_id'] ?? null;
$category = $_GET['category'] ?? null;
$search = $_GET['q'] ?? ''; // Changed from 'search' to 'q' to match the search bar form
$sortBy = $_GET['sort'] ?? 'newest';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

// Get categories for filter sidebar
$categories = getCategories();

// Build query conditions
$whereConditions = ["p.status = 'active'"];
$sqlParams = [];

if ($categoryId) {
    $whereConditions[] = "p.category_id = ?";
    $sqlParams[] = $categoryId;
}

if ($category) {
    $whereConditions[] = "c.name LIKE ?";
    $sqlParams[] = '%' . $category . '%';
}

// Price range, same as the customer listing. Blank means "no bound", so an
// empty box never filters. Bound parameters, appended to the conditions this
// page already builds - no schema, data or .sql file is touched.
$minPrice = (isset($_GET['min_price']) && $_GET['min_price'] !== '') ? (float)$_GET['min_price'] : null;
$maxPrice = (isset($_GET['max_price']) && $_GET['max_price'] !== '') ? (float)$_GET['max_price'] : null;
if ($minPrice !== null) {
    $whereConditions[] = "p.price >= ?";
    $sqlParams[] = $minPrice;
}
if ($maxPrice !== null) {
    $whereConditions[] = "p.price <= ?";
    $sqlParams[] = $maxPrice;
}

// Initialize variables
$searchType = 'normal';
$suggestions = [];
$products = [];

// Build ORDER BY clause
$orderBy = "p.created_at DESC";
switch ($sortBy) {
    case 'price_low':
        $orderBy = "p.price ASC";
        break;
    case 'price_high':
        $orderBy = "p.price DESC";
        break;
    case 'name':
        $orderBy = "p.name ASC";
        break;
    case 'newest':
    default:
        $orderBy = "p.created_at DESC";
        break;
}

// Helper function for similarity calculation
function calculateSimilarity($str1, $str2) {
    $maxLen = max(strlen($str1), strlen($str2));
    if ($maxLen == 0) return 1;
    
    $distance = levenshtein($str1, $str2);
    return 1 - ($distance / $maxLen);
}

// Smart search function
function performSmartSearch($pdo, $query, $limit) {
    $searchTerm = '%' . $query . '%';
    
    // First try exact search
    $sql = "SELECT p.*, c.name as category_name,
            CASE 
                WHEN p.name LIKE ? THEN 1
                WHEN p.description LIKE ? THEN 2
                WHEN c.name LIKE ? THEN 3
                ELSE 4
            END as relevance_score
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.status = 'active' 
            AND (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)
            ORDER BY relevance_score, p.created_at DESC
            LIMIT ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
    $exactResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($exactResults)) {
        return [
            'results' => $exactResults,
            'search_type' => 'exact'
        ];
    }
    
    // If no exact results, try fuzzy search
    $allSql = "SELECT p.*, c.name as category_name FROM products p 
               LEFT JOIN categories c ON p.category_id = c.id 
               WHERE p.status = 'active'";
    $allStmt = $pdo->prepare($allSql);
    $allStmt->execute();
    $allProducts = $allStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $fuzzyResults = [];
    $queryLower = strtolower($query);
    
    foreach ($allProducts as $product) {
        $name = strtolower($product['name']);
        $description = strtolower($product['description'] ?? '');
        $category = strtolower($product['category_name'] ?? '');
        
        // Calculate similarity scores
        $nameScore = calculateSimilarity($queryLower, $name);
        $descScore = calculateSimilarity($queryLower, $description);
        $catScore = calculateSimilarity($queryLower, $category);
        
        $bestScore = max($nameScore, $descScore, $catScore);
        
        if ($bestScore > 0.6) {
            $product['similarity_score'] = $bestScore;
            $fuzzyResults[] = $product;
        }
    }
    
    usort($fuzzyResults, function($a, $b) {
        return $b['similarity_score'] <=> $a['similarity_score'];
    });
    
    $fuzzyResults = array_slice($fuzzyResults, 0, $limit);
    
    if (!empty($fuzzyResults)) {
        return [
            'results' => $fuzzyResults,
            'search_type' => 'fuzzy'
        ];
    }
    
    // Get suggestions for no results
    $suggestions = getSearchSuggestions($pdo, $query);
    
    return [
        'results' => [],
        'search_type' => 'no_results',
        'suggestions' => $suggestions
    ];
}

function getSearchSuggestions($pdo, $query) {
    $suggestions = [];
    
    // Get popular categories
    $catSql = "SELECT c.name, COUNT(p.id) as product_count 
               FROM categories c 
               LEFT JOIN products p ON c.id = p.category_id AND p.status = 'active'
               GROUP BY c.id, c.name 
               HAVING product_count > 0
               ORDER BY product_count DESC 
               LIMIT 5";
    $stmt = $pdo->prepare($catSql);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($categories as $cat) {
        if (calculateSimilarity(strtolower($query), strtolower($cat['name'])) > 0.3) {
            $suggestions[] = [
                'text' => $cat['name'],
                'type' => 'category',
                'count' => $cat['product_count']
            ];
        }
    }
    
    return array_slice($suggestions, 0, 5);
}

try {
    $pdo = getConnection();
    
    if ($search) {
        // Use smart search for better typo handling
        $searchResults = performSmartSearch($pdo, $search, $limit);
        $products = $searchResults['results'];
        $searchType = $searchResults['search_type'];
        $suggestions = $searchResults['suggestions'] ?? [];
        $totalProducts = count($products);
        $totalPages = ceil($totalProducts / $limit);
    } else {
        // Regular product listing
        $countSql = "SELECT COUNT(*) FROM products p 
                     LEFT JOIN categories c ON p.category_id = c.id 
                     WHERE " . implode(' AND ', $whereConditions);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($sqlParams);
        $totalProducts = $countStmt->fetchColumn();
        $totalPages = ceil($totalProducts / $limit);

        // Get products
        $safeLimit = (int)$limit;
    $safeOffset = (int)$offset;
    $sql = "SELECT p.*, c.name as category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE " . implode(' AND ', $whereConditions) . "
            ORDER BY {$orderBy}
            LIMIT {$safeLimit} OFFSET {$safeOffset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($sqlParams);
    $products = $stmt->fetchAll();
    }

    // Real average rating + review count, batched in one query regardless
    // of which path above produced $products (regular listing or smart
    // search) - replaces the old order-count-based fake "4/5 stars for any
    // product that's ever sold" display with the product's actual reviews.
    if (!empty($products)) {
        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $ratingStmt = $pdo->prepare("SELECT product_id, AVG(rating) as avg_rating, COUNT(*) as review_count
                                      FROM reviews WHERE product_id IN ($placeholders) AND is_approved = 1
                                      GROUP BY product_id");
        $ratingStmt->execute($productIds);
        $ratingsByProduct = [];
        foreach ($ratingStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ratingsByProduct[$r['product_id']] = ['avg_rating' => round((float)$r['avg_rating'], 1), 'review_count' => (int)$r['review_count']];
        }
        foreach ($products as &$p) {
            $rating = $ratingsByProduct[$p['id']] ?? ['avg_rating' => 0.0, 'review_count' => 0];
            $p['avg_rating'] = $rating['avg_rating'];
            $p['review_count'] = $rating['review_count'];
        }
        unset($p);
    }

} catch (PDOException $e) {
    error_log("Error fetching products: " . $e->getMessage());
    $products = [];
    $totalProducts = 0;
    $totalPages = 1;
}

// Get current category name for breadcrumb
$currentCategoryName = '';
if ($categoryId) {
    $catStmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $catStmt->execute([$categoryId]);
    $currentCategoryName = $catStmt->fetchColumn();
} elseif ($category) {
    $currentCategoryName = ucfirst($category);
}

// No top header bar or nav bar on this page (same as guest/index.php) -
// just the shared assets the header used to load, plus the floating Login
// button below. Navigation here is the breadcrumb + the filter sidebar.
?>
<script>document.documentElement.setAttribute('data-theme','light');</script>
<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>
<link rel="icon" type="image/x-icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
<!-- The customer pages get this through their header include; this page has no
     header, so it is linked directly. After style.css, so it wins on source
     order where specificity ties. Its rules are keyed to .products-listing,
     which this page's wrapper already carries. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/css/customer-dark.css') ?>">
<script src="<?= asset_url('public/shared/assets/js/main.js') ?>"></script>

<!-- Floating Login / Sign In - same as guest/index.php. openLoginModal()
     and the modal markup come from includes/footer.php. -->
<a class="guest-login-fab" href="<?= WEB_ROOT ?>public/guest/login.php" onclick="event.preventDefault(); openLoginModal();">
  <i class="fi fi-rr-sign-in-alt" aria-hidden="true"></i> Login / Sign In
</a>

<!-- Breadcrumb -->
<div class="breadcrumb">
    <div class="container">
        <a href="<?php echo WEB_ROOT; ?>public/guest/index.php">Home</a>
        <span>/</span>
        <a href="<?php echo WEB_ROOT; ?>public/guest/products.php">Products</a>
        <?php if ($currentCategoryName): ?>
            <span>/</span>
            <span><?php echo htmlspecialchars($currentCategoryName); ?></span>
        <?php endif; ?>
    </div>
</div>

<!-- Products Section -->
<section class="products-listing">
    <div class="container">
        <div class="products-layout">
            <!-- Sidebar Filters -->
            <aside class="filters-sidebar">
                <div class="filter-section">
                    <h3>Categories</h3>
                    <ul class="category-filter">
                        <li><a href="<?= WEB_ROOT ?>public/guest/products.php" class="<?php echo !$categoryId && !$category ? 'active' : ''; ?>">All Products</a></li>
                        <?php foreach ($categories as $cat): ?>
                            <li>
                                <a href="<?= WEB_ROOT ?>public/guest/products.php?category_id=<?php echo $cat['id']; ?>" 
                                   class="<?php echo $categoryId == $cat['id'] ? 'active' : ''; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="filter-section">
                    <h3>Price Range</h3>
                    <!-- GET form so the existing category/sort links keep
                         working unchanged; the current category and sort ride
                         along as hidden fields so applying a price does not
                         drop them. -->
                    <form method="GET" class="pf-price-form">
                        <?php if (!empty($categoryId)): ?>
                            <input type="hidden" name="category_id" value="<?= (int)$categoryId ?>">
                        <?php endif; ?>
                        <?php if (!empty($category)): ?>
                            <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                        <?php endif; ?>
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
                        <div class="pf-price-row">
                            <input type="number" name="min_price" min="0" step="1" placeholder="MIN"
                                   value="<?= $minPrice !== null ? htmlspecialchars((string)$minPrice) : '' ?>">
                            <input type="number" name="max_price" min="0" step="1" placeholder="MAX"
                                   value="<?= $maxPrice !== null ? htmlspecialchars((string)$maxPrice) : '' ?>">
                        </div>
                        <button type="submit" class="pf-apply">Apply Filters</button>
                    </form>
                </div>

                <div class="filter-section">
                    <h3>Sort By</h3>
                    <select id="sortSelect" onchange="updateSort(this.value)">
                        <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                        <option value="price_low" <?php echo $sortBy === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_high" <?php echo $sortBy === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                        <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name A-Z</option>
                    </select>
                </div>

                
            </aside>
            
            <!-- Products Grid -->
            <main class="products-main">
                <div class="products-header">
                    <div class="pf-eyebrow"><span aria-hidden="true"></span>THE CATALOG</div>
                    <h1>
                        <?php if ($search): ?>
                            Search Results for "<?php echo htmlspecialchars($search); ?>"
                        <?php elseif ($currentCategoryName): ?>
                            <?php echo htmlspecialchars($currentCategoryName); ?>
                        <?php else: ?>
                            All Products
                        <?php endif; ?>
                    </h1>
                    <p class="results-count"><?php echo $totalProducts; ?> products found</p>
                </div>
                
                <?php if (empty($products)): ?>
                    <div class="no-products">
                        <div class="no-products-icon">📦</div>
                        <h3>No products found</h3>
                        <p>Try adjusting your filters or search terms</p>
                        <a href="<?= WEB_ROOT ?>public/guest/products.php" class="btn-primary">View All Products</a>
                    </div>
                <?php else: ?>
                    <!-- Electric-border filter (referenced by .product-card:hover::after). -->
                    <svg class="eb-defs" aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute;pointer-events:none">
                      <defs>
                        <filter id="eb-turbulence" x="-30%" y="-30%" width="160%" height="160%">
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n1" seed="1"/>
                          <feOffset in="n1" dx="0" dy="0" result="o1">
                            <animate attributeName="dy" values="700; 0" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n2" seed="1"/>
                          <feOffset in="n2" dx="0" dy="0" result="o2">
                            <animate attributeName="dy" values="0; -700" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n3" seed="2"/>
                          <feOffset in="n3" dx="0" dy="0" result="o3">
                            <animate attributeName="dx" values="490; 0" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n4" seed="2"/>
                          <feOffset in="n4" dx="0" dy="0" result="o4">
                            <animate attributeName="dx" values="0; -490" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feComposite in="o1" in2="o2" result="yMove"/>
                          <feComposite in="o3" in2="o4" result="xMove"/>
                          <feBlend in="yMove" in2="xMove" mode="color-dodge" result="blended"/>
                          <feDisplacementMap in="SourceGraphic" in2="blended" scale="12"/>
                        </filter>
                      </defs>
                    </svg>
                    <div class="products-grid">
                        <?php
                          // BEST VALUE = cheapest of the products already
                          // fetched for this page. A pass over $products, not
                          // another query. Zero-priced rows are skipped so a
                          // missing price cannot win.
                          $pvBestId = null; $pvMin = null;
                          foreach ($products as $pvP) {
                              $pvPrice = (float)$pvP['price'];
                              if ($pvPrice > 0 && ($pvMin === null || $pvPrice < $pvMin)) {
                                  $pvMin = $pvPrice; $pvBestId = (int)$pvP['id'];
                              }
                          }
                        ?>
                        <?php foreach ($products as $product): ?>
                            <?php
                              // One badge per card, first match wins:
                              // NEW > BEST VALUE > IN STOCK. Same rules the
                              // customer listing and the homepage cards use.
                              $pvStock   = (int)$product['stock'];
                              $pvReorder = (int)($product['reorder_level'] ?? 5);
                              $pvIsNew   = !empty($product['created_at']) && strtotime($product['created_at']) > strtotime('-30 days');
                              $pvIsValue = $pvBestId !== null && (int)$product['id'] === $pvBestId;
                              $pvInStock = $pvStock > $pvReorder * 2;
                            ?>
                            <div class="product-card" data-product-id="<?php echo $product['id']; ?>">
                                <div class="product-image">
                                    <?php if ($pvIsValue): ?>
                                      <span class="tw-badge tw-badge--value">BEST VALUE</span>
                                    <?php elseif ($pvInStock): ?>
                                      <span class="tw-badge tw-badge--stock">IN STOCK</span>
                                    <?php elseif ($pvIsNew): ?>
                                      <span class="tw-badge tw-badge--new">NEW</span>
                                    <?php endif; ?>
                                    <?php if ($product['image_url']): ?>
                                        <img src="<?php echo WEB_ROOT . htmlspecialchars($product['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                                             loading="lazy">
                                    <?php else: ?>
                                        <img src="<?php echo WEB_ROOT; ?>public/shared/img/products/placeholder.svg" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                                             loading="lazy">
                                    <?php endif; ?>
                                    
                                    <div class="product-overlay">
                                        <button class="quick-view-btn" onclick="quickView(<?php echo $product['id']; ?>)">
                                            <i class="fi fi-rs-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="product-info">
                                    <div class="product-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></div>
                                    <h3 class="product-title">
                                        <a href="<?= WEB_ROOT ?>public/guest/product.php?id=<?php echo $product['id']; ?>">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </a>
                                    </h3>
                                    <div class="product-rating">
                                        <?php
                                            $reviewCount = (int)($product['review_count'] ?? 0);
                                            $avgRating = (float)($product['avg_rating'] ?? 0);
                                            $filledStars = $reviewCount > 0 ? (int)round($avgRating) : 0;
                                            for ($i = 1; $i <= 5; $i++) {
                                                echo $i <= $filledStars ? '<i class="fi fi-ss-star"></i>' : '<i class="fi fi-rs-star"></i>';
                                            }
                                            if ($reviewCount === 0) {
                                                echo '<span class="rating-text">No reviews yet</span>';
                                            } else {
                                                echo '<span class="rating-text">' . number_format($avgRating, 1) . ' (' . $reviewCount . ' ' . ($reviewCount === 1 ? 'review' : 'reviews') . ')</span>';
                                            }
                                        ?>
                                    </div>
                                    
                                    <div class="price-stock-section">
                                        <div class="product-price">
                                            <?php if (floatval($product['price']) > 0): ?>
                                                <span class="current-price"><?php echo formatCurrency($product['price']); ?></span>
                                                <?php if (mt_rand(0, 1)): // Random discount display ?>
                                                    <span class="original-price"><?php echo formatCurrency($product['price'] * 1.2); ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="current-price">₱ -</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stock-indicator">
                                            <?php if ($product['stock'] > 0): ?>
                                                <?php if ($product['stock'] <= 5): ?>
                                                    <span class="stock-low">
                                                        <i class="fi fi-rr-exclamation-triangle"></i>
                                                        <?php echo $product['stock']; ?> left
                                                    </span>
                                                <?php else: ?>
                                                    <span class="stock-available">
                                                        <i class="fi fi-rr-check-circle"></i>
                                                    <?php echo $product['stock']; ?> AVAILABLE
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="stock-out">
                                                    <i class="fi fi-rr-cross-circle"></i>
                                                    Out of Stock
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="product-actions">
                                        <?php if ($product['stock'] > 0): ?>
                                            <button class="buy-now-btn" onclick="buyNow(<?php echo $product['id']; ?>)">
                                                <i class="fi fi-rr-shopping-bag"></i>
                                                Buy Now
                                            </button>
                                        <?php else: ?>
                                            <button class="wishlist-action-btn" onclick="addToCart(<?php echo $product['id']; ?>)">
                                                <i class="fi fi-rs-heart"></i>
                                                Add to Wishlist
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php
                            $currentUrl = $_SERVER['REQUEST_URI'];
                            $urlParts = parse_url($currentUrl);
                            parse_str($urlParts['query'] ?? '', $urlParams);
                            ?>
                            
                            <?php if ($page > 1): ?>
                                <a href="<?= WEB_ROOT ?>public/guest/products.php?<?php echo http_build_query(array_merge($urlParams, ['page' => $page - 1])); ?>" class="pagination-btn">
                                    <i class="fi fi-rs-angle-left"></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="<?= WEB_ROOT ?>public/guest/products.php?<?php echo http_build_query(array_merge($urlParams, ['page' => $i])); ?>" 
                                   class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="<?= WEB_ROOT ?>public/guest/products.php?<?php echo http_build_query(array_merge($urlParams, ['page' => $page + 1])); ?>" class="pagination-btn">
                                    Next <i class="fi fi-rs-angle-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>
</section>

<style>
/* Floating Login / Sign In - fixed top-right, mirrors guest/index.php's
   button (and the customer area's floating cart button). */
.guest-login-fab {
    position: fixed;
    top: 18px;
    right: 20px;
    z-index: 1200;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 20px;
    border-radius: 999px;
    border: 1px solid rgba(23, 72, 113, 0.18);
    background: rgba(255, 255, 255, 0.9);
    -webkit-backdrop-filter: blur(14px);
    backdrop-filter: blur(14px);
    color: var(--text-primary);
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    text-decoration: none;
    cursor: pointer;
    box-shadow: 0 10px 26px rgba(23, 50, 74, 0.14);
    transition: transform .18s var(--ease-out, ease), box-shadow .18s ease, background .18s ease, color .18s ease;
}
.guest-login-fab:hover {
    transform: translateY(-2px);
    background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
    border-color: transparent;
    color: #fff;
    box-shadow: 0 14px 32px rgba(23, 72, 113, 0.32);
}
.guest-login-fab:active { transform: scale(0.97); }
.guest-login-fab i { font-size: 14px; }
@media (max-width: 600px) {
    .guest-login-fab { top: 12px; right: 12px; padding: 9px 14px; font-size: 12px; }
}

.breadcrumb {
    background: var(--bg-card);
    border-bottom: 1px solid var(--border-color);
    padding: 15px 0;
    font-size: 14px;
}

.breadcrumb a {
    color: var(--accent-cyan);
    text-decoration: none;
}

.breadcrumb span {
    margin: 0 10px;
    color: var(--text-muted);
}

/* Same cream page ground the authenticated customer products page uses
   (.account-page in that file), so the two read identically. */
.products-listing {
    padding: 40px 0;
    min-height: 100vh;
    background: var(--page-bg-gradient);
}

.products-layout {
    display: grid;
    grid-template-columns: 250px 1fr;
    gap: 40px;
}

.filters-sidebar {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 30px;
    height: fit-content;
    border: 1px solid var(--border-color);
}

.filter-section {
    margin-bottom: 30px;
}

.filter-section h3 {
    color: var(--text-primary);
    margin-bottom: 15px;
    font-size: 18px;
}

.category-filter {
    list-style: none;
}

.category-filter li {
    margin-bottom: 8px;
}

.category-filter a {
    color: var(--text-muted);
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 8px;
    display: block;
    transition: all 0.3s ease;
}

.category-filter a:hover,
.category-filter a.active {
    background: var(--accent-cyan-bg);
    color: var(--accent-cyan);
}

#sortSelect {
    width: 100%;
    padding: 10px 12px;
    background: var(--bg-card-solid);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm, 8px);
    color: var(--text-primary);
}
#sortSelect:focus { outline: 2px solid var(--accent-cyan); outline-offset: 1px; }
#sortSelect option { background: var(--bg-card-solid); color: var(--text-primary); }

.products-main {
    min-height: 400px;
}

.products-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 18px;
}

.products-header h1 {
    color: var(--text-primary);
    font-size: 20px;
    margin: 0;
}

.results-count {
    color: var(--text-muted);
    font-size: 13px;
    margin: 0;
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 30px;
    margin-bottom: 40px;
}

.product-card {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
}

.product-card:hover {
    transform: translateY(-5px);
    border-color: var(--accent-cyan-bg-30);
}

/* --- Electric border --- identical to the authenticated customer products
   page: a muted mauve border (matched to --accent-cyan #a05f80) that on
   hover is displaced by the animated #eb-turbulence SVG filter so it
   crackles, with a soft mauve halo. The filter runs only on the hovered
   card. Move filter:url(#eb-turbulence) up to .product-card::after (drop
   the :hover) to make it always-on. */
.products-grid .product-card {
    --eb: #a9819a;
    --eb-light: #c4aab8;
    --eb-dim: rgba(160, 95, 128, 0.24);
    --eb-glow: rgba(160, 95, 128, 0.22);
    border: 1.5px solid var(--eb-dim) !important;
    overflow: visible;
    isolation: isolate;
    box-shadow: 0 6px 18px rgba(23, 50, 74, 0.09),
                0 0 14px -10px var(--eb-glow) !important;
    transition: box-shadow .45s cubic-bezier(.22,1,.36,1),
                transform .3s ease, border-color .4s ease;
}
.product-card .product-image { border-radius: 15px 15px 0 0; }
.products-grid .product-card::after {
    content: "";
    position: absolute;
    inset: -2px;
    border: 1.5px solid var(--eb-light);
    border-radius: 16px;
    pointer-events: none;
    opacity: 0;
    transition: opacity .4s ease;
    z-index: 4;
}
.products-grid .product-card:hover {
    border-color: var(--eb) !important;
    box-shadow: 0 12px 30px rgba(23, 50, 74, 0.13),
                0 0 22px -10px var(--eb-glow) !important;
}
.products-grid .product-card:hover::after {
    opacity: .7;
    filter: url(#eb-turbulence);
}
@media (prefers-reduced-motion: reduce) {
    .products-grid .product-card:hover::after { filter: none; }
}

/* Level out the cards - equal internal rhythm regardless of title length,
   action button pinned to the bottom. */
.products-grid { align-items: stretch; }
.products-grid .product-card { display: flex; flex-direction: column; height: 100%; }
.product-info { display: flex; flex-direction: column; flex: 1; }
.product-title { margin: 0; }
.product-title a {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.8em;
}
.product-actions { margin-top: auto; }

.product-image {
    position: relative;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.product-image img {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
    transition: transform 0.3s ease;
}

.product-card:hover .product-image img {
    transform: scale(1.05);
}

.product-overlay {
    position: absolute;
    top: 10px;
    right: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.product-card:hover .product-overlay {
    opacity: 1;
}

.quick-view-btn,
.wishlist-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, 0.9);
    color: #333;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.quick-view-btn:hover {
    background: var(--accent-cyan);
    color: white;
}

.wishlist-btn:hover {
    background: var(--accent-blue);
    color: white;
}

.product-info {
    padding: 20px;
}

.product-category {
    color: var(--accent-cyan);
    font-size: 12px;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.product-title a {
    color: var(--text-primary);
    text-decoration: none;
    font-size: 16px;
    font-weight: 600;
    line-height: 1.4;
    display: block;
    margin-bottom: 10px;
}

.product-title a:hover {
    color: var(--accent-cyan);
}

.product-rating {
    display: flex;
    align-items: center;
    gap: 2px;
    margin-bottom: 15px;
}

.product-rating i {
    color: #fbbf24;
    font-size: 14px;
}

.rating-text {
    color: var(--text-muted);
    font-size: 12px;
    margin-left: 5px;
}

/* Enhanced Product Card Styles */
.price-stock-section {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    gap: 15px;
    position: relative;
}

.product-price {
    flex: 1;
}

.current-price {
    color: var(--accent-cyan);
    font-size: 24px;
    font-weight: 800;
    display: block;
}

.original-price {
    color: var(--text-muted);
    font-size: 16px;
    text-decoration: line-through;
    margin-left: 8px;
    opacity: 0.8;
}

.stock-indicator {
    display: flex;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
    padding: 8px 18px;
    border-radius: 50px;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.25), inset 0 1px 0 var(--border-color);
    min-height: 28px;
    border: 2px solid transparent;
    backdrop-filter: blur(10px);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.stock-indicator::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.6s ease-in-out;
}

.stock-indicator::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    right: 2px;
    bottom: 2px;
    border-radius: 50px;
    background: linear-gradient(45deg, var(--border-color), transparent, rgba(255, 255, 255, 0.05));
    pointer-events: none;
}

.stock-indicator:hover::before {
    left: 100%;
}

.stock-indicator i {
    margin-right: 6px;
    font-size: 12px;
    filter: drop-shadow(0 0 4px currentColor) drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
    position: relative;
    z-index: 2;
}

.stock-available {
    background: transparent;
    color: var(--accent-blue);
    border: none;
    animation: tech-pulse 4s ease-in-out infinite;
    box-shadow: none;
}

.stock-low {
    background: transparent;
    color: var(--accent-blue);
    border: none;
    animation: pulse-warning 2s infinite, tech-scan 3s linear infinite;
    position: relative;
    box-shadow: none;
}

.stock-out {
    background: linear-gradient(135deg, #dc2626, #ef4444, #dc2626);
    color: white;
    border: 2px solid #f87171;
    animation: glow-red 2s ease-in-out infinite alternate, tech-warning 2s ease-in-out infinite;
    position: relative;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

@keyframes glow-blue {
    0% { 
        box-shadow: 0 4px 15px rgba(23, 72, 113, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        border-color: #a77693;
    }
    100% { 
        box-shadow: 0 6px 25px rgba(23, 72, 113, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        border-color: #c9a3b5;
    }
}

@keyframes glow-red {
    0% { 
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        border-color: #f87171;
    }
    100% { 
        box-shadow: 0 6px 25px rgba(239, 68, 68, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        border-color: #fca5a5;
    }
}

@keyframes tech-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.02); }
}

@keyframes tech-scan {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

@keyframes tech-warning {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

@keyframes pulse-warning {
    0%, 100% { 
        transform: scale(1);
        opacity: 1;
    }
    50% { 
        transform: scale(1.05);
        opacity: 0.9;
    }
}

@keyframes flash {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

.product-actions {
    display: flex;
    gap: 12px;
    margin-top: 15px;
}

.add-to-cart-btn,
.buy-now-btn,
.wishlist-action-btn {
    flex: 1;
    padding: 10px 8px;
    border: none;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-decoration: none;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    position: relative;
    overflow: hidden;
}

.add-to-cart-btn::before,
.buy-now-btn::before,
.wishlist-action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.6s;
}

.add-to-cart-btn:hover::before,
.buy-now-btn:hover::before,
.wishlist-action-btn:hover::before {
    left: 100%;
}

.add-to-cart-btn {
    background: linear-gradient(135deg, var(--accent-cyan-bg-20), var(--accent-cyan-bg));
    color: var(--accent-cyan);
    border: 2px solid var(--accent-cyan);
    box-shadow: 0 4px 15px var(--accent-cyan-bg-20);
}

.add-to-cart-btn:hover {
    background: linear-gradient(135deg, var(--accent-cyan), #c99bb0);
    color: white;
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 6px 15px var(--accent-cyan-bg-30);
}

.buy-now-btn {
    background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
    color: white;
    border: 2px solid transparent;
    box-shadow: 0 4px 15px rgba(23, 72, 113, 0.3);
    position: relative;
}

.buy-now-btn:hover {
    background: linear-gradient(135deg, var(--accent-blue), #0f2d4d);
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 8px 20px rgba(23, 72, 113, 0.4);
}

.wishlist-action-btn {
    background: linear-gradient(135deg, var(--accent-cyan-bg), var(--bg-card-alpha-80));
    color: var(--accent-cyan);
    border: 1px solid var(--accent-cyan);
    width: 100%;
    box-shadow: 0 2px 8px rgba(167, 118, 147, 0.15);
    position: relative;
}

.wishlist-action-btn:hover {
    background: linear-gradient(135deg, var(--accent-cyan-bg-20), var(--bg-card-hover));
    color: var(--accent-cyan);
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 6px 15px var(--accent-cyan-bg-30);
}

.wishlist-action-btn i {
    font-size: 12px;
    filter: drop-shadow(0 0 2px currentColor);
}

.no-products {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.no-products-icon {
    font-size: 64px;
    margin-bottom: 20px;
}

.no-products h3 {
    font-size: 24px;
    margin-bottom: 10px;
    color: var(--text-primary);
}

.no-products p {
    font-size: 16px;
    margin-bottom: 30px;
}

.btn-primary {
    background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    text-decoration: none;
    display: inline-block;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(23, 72, 113, 0.3);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 40px;
}

.pagination-btn {
    padding: 10px 16px;
    background: var(--bg-card-alpha-80);
    color: var(--text-primary);
    text-decoration: none;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 5px;
}

.pagination-btn:hover {
    background: var(--accent-cyan-bg);
    color: var(--accent-cyan);
    border-color: var(--accent-cyan-bg-30);
}

.pagination-btn.active {
    background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
    color: white;
}

@media (max-width: 768px) {
    .products-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .filters-sidebar {
        order: 2;
    }
    
    .products-main {
        order: 1;
    }
    
    .products-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
    }
    
    .price-stock-section {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .stock-indicator {
        align-self: flex-end;
    }
    
    .product-actions {
        flex-direction: column;
        gap: 8px;
    }

    /* The results heading: eyebrow, h1 and results count are three items of
       one flex row spread with space-between, which has no room to spread on a
       phone - the title ended up running across "THE CATALOG". Stack them in
       source order (eyebrow above the title, count under it), left-aligned.

       This page carries no tw-mobile.css of its own - that sheet is linked from
       the customer footer include, which this page does not use - so the same
       fix lives here as well as there. Keep the two in step.

       The second rule only exists to outrank customer-dark.css's
       `body:has(.products-listing) .products-header { align-items: flex-end }`,
       which is one element heavier than a plain descendant selector and in a
       column would right-align the whole stack. It is a separate rule, not part
       of a comma list, because a browser without :has() discards an entire
       selector list that mentions it - and the first rule has to survive that. */
    .products-listing .products-header {
        flex-direction: column;
        align-items: flex-start;
        justify-content: flex-start;
        gap: 4px;
    }
    body:has(.products-listing) .products-header { align-items: flex-start; }
    .products-listing .products-header > * { max-width: 100%; min-width: 0; }
    .products-listing .pf-eyebrow { margin-bottom: 0; }
    /* customer-dark.css sizes this h1 at clamp(30px, 3.6vw, 46px) in weight
       900, which lands on 30px at every phone width - too big for the column
       and the other half of why the row collided. Matched at that sheet's own
       specificity so it actually applies. */
    .products-listing .products-header h1 { overflow-wrap: break-word; }
    body:has(.products-listing) .products-header h1 { font-size: clamp(18px, 5vw, 22px); }
}
</style>

<script>
function updateSort(sortValue) {
    const url = new URL(window.location);
    url.searchParams.set('sort', sortValue);
    url.searchParams.delete('page'); // Reset to first page
    window.location.href = url.toString();
}

function quickView(productId) {
    if (!<?php echo isLoggedIn() ? 'true' : 'false'; ?>) {
        if (typeof openLoginModal === 'function') { openLoginModal(); }
        return;
    }
    window.location.href = 'product.php?id=' + productId;
}

function buyNow(productId) {
    // Add to cart and redirect to checkout
    addToCart(productId, 1, function() {
        window.location.href = 'checkout.php';
    });
}

// Override the main addToCart function with auth check + callback support
function addToCart(productId, quantity = 1, callback = null) {
    if (!<?php echo isLoggedIn() ? 'true' : 'false'; ?>) {
        if (typeof openLoginModal === 'function') { openLoginModal(); }
        return;
    }
    
    fetch('<?php echo WEB_ROOT; ?>public/authenticated/api/cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            action: 'add',
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartCount(data.cart_count);
            showNotification(data.message || 'Product added to cart!', 'success');
            if (callback) callback();
        } else {
            showNotification(data.message || 'Failed to add to cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>