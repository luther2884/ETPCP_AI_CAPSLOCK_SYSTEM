<?php
// Customer homepage — same design and layout as guest homepage
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

require_login();

$pageTitle = 'TechWorld - Home';
$activeNav = 'home';

// Fetch homepage products (latest 2 from desktops and laptops)
$desktops = [];
$laptops = [];
try {
	$pdo = getConnection();
	$stmt_desktops = $pdo->prepare("SELECT * FROM products WHERE category_id = 7 AND status = 'active' ORDER BY created_at DESC LIMIT 2");
	$stmt_desktops->execute();
	$desktops = $stmt_desktops->fetchAll();

	$stmt_laptops = $pdo->prepare("SELECT * FROM products WHERE category_id = 8 AND status = 'active' ORDER BY created_at DESC LIMIT 2");
	$stmt_laptops->execute();
	$laptops = $stmt_laptops->fetchAll();
} catch (PDOException $e) {
	error_log('Homepage product fetch error: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js
         is deferred and would run a frame after first paint, which is exactly
         the flash this prevents. Resolution order is localStorage -> OS
         preference -> dark. There is no server-rendered preference to fall
         back on yet - `users` has no theme column; see the TODO in theme.js. */ ?>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<!-- Product Categories (same markup as guest) -->
<section class="categories">
  <div class="container">
    <div class="category-grid">
      <div class="category-item" onclick="location.href='<?= WEB_ROOT ?>public/authenticated/customer/products.php?category_id=1'">
        <div class="category-image"><img src="<?= WEB_ROOT ?>public/shared/img/Processor.png" alt="Processor" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/placeholder.png';"></div>
        <h3>PROCESSOR</h3>
      </div>

      <div class="category-item" onclick="location.href='<?= WEB_ROOT ?>public/authenticated/customer/products.php?category_id=2'">
        <div class="category-image"><img src="<?= WEB_ROOT ?>public/shared/img/Motherboard.png" alt="Motherboard" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/placeholder.png';"></div>
        <h3>MOTHERBOARD</h3>
      </div>

      <div class="category-item" onclick="location.href='<?= WEB_ROOT ?>public/authenticated/customer/products.php?category_id=3'">
        <div class="category-image"><img src="<?= WEB_ROOT ?>public/shared/img/Graphics Card.png" alt="Graphics Card" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/placeholder.png';"></div>
        <h3>GRAPHICS CARD</h3>
      </div>

      <div class="category-item" onclick="location.href='<?= WEB_ROOT ?>public/authenticated/customer/products.php?category_id=4'">
        <div class="category-image"><img src="<?= WEB_ROOT ?>public/shared/img/Memory.png" alt="Memory" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/placeholder.png';"></div>
        <h3>MEMORY</h3>
      </div>

      <div class="category-item" onclick="location.href='<?= WEB_ROOT ?>public/authenticated/customer/products.php?category_id=5'">
        <div class="category-image"><img src="<?= WEB_ROOT ?>public/shared/img/SSD.png" alt="SSD" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/placeholder.png';"></div>
        <h3>SOLID STATE DRIVES</h3>
      </div>

      <div class="category-item" onclick="location.href='<?= WEB_ROOT ?>public/authenticated/customer/products.php?category_id=6'">
        <div class="category-image"><img src="<?= WEB_ROOT ?>public/shared/img/PSU.png" alt="Power Supply" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/placeholder.png';"></div>
        <h3>POWER SUPPLY</h3>
      </div>
    </div>
  </div>
  
</section>

<!-- Product Sections (same look) -->
<section class="products">
  <div class="container">
    <div class="product-sections">
      <!-- DESKTOPS -->
      <div class="product-section desktop-section">
        <div class="section-header">
          <h2>DESKTOPS</h2>
          <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=desktops" class="see-more">SEE MORE</a>
        </div>
        <div class="product-grid">
          <div class="product-card">
            <div class="brand">TechWorld</div>
            <div class="product-image"><img src="<?= WEB_ROOT ?>public/shared/img/products/desktop1.svg" alt="Desktop PC"></div>
            <h3>Ryzen 7 5700G / A520 / 2x8GB DDR...</h3>
            <div class="rating"><i class="fi fi-rs-star" style="color: gold;"></i> No reviews</div>
            <div class="price">
              <span class="original-price">₱16,995.00</span>
              <span class="current-price">₱14,995.00</span>
            </div>
            <button class="buy-now" type="button" onclick="buyNowProduct('desktop-1')">BUY NOW</button>
          </div>

          <div class="product-card">
            <div class="brand">TechWorld</div>
            <div class="product-image"><img src="<?= WEB_ROOT ?>public/shared/img/products/desktop2.svg" alt="Desktop PC"></div>
            <h3>Ryzen 3 3200G / A520 / 2x8GB DDR4-3200 / 256GB SSD</h3>
            <div class="rating"><i class="fi fi-rs-star" style="color: gold;"></i> No reviews</div>
            <div class="price">
              <span class="original-price">₱11,495.00</span>
              <span class="current-price">₱10,995.00</span>
            </div>
            <button class="buy-now" type="button" onclick="buyNowProduct('desktop-2')">BUY NOW</button>
          </div>

          <?php if (!empty($desktops)): ?>
            <?php foreach ($desktops as $product): ?>
              <div class="product-card">
                <div class="brand">TechWorld</div>
                <div class="product-image">
                  <a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $product['id'] ?>">
                    <img src="<?= htmlspecialchars($product['image_url'] ? WEB_ROOT . $product['image_url'] : 'img/products/placeholder.svg') ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                  </a>
                </div>
                <h3><a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?></a></h3>
                <div class="rating"><i class="fi fi-rs-star" style="color: gold;"></i> No reviews</div>
                
                <div class="price-stock-section">
                  <div class="price">
                    <?php if (floatval($product['price']) > 0): ?>
                      <?php
                        $priceText = preg_replace('/[^0-9]/', '', (string)round($product['price']));
                        $digits = strlen($priceText);
                        $priceClass = 'current-price';
                        if ($digits >= 9) { $priceClass .= ' xxs'; }
                        elseif ($digits >= 8) { $priceClass .= ' xs'; }
                        elseif ($digits >= 5) { $priceClass .= ' small'; }
                      ?>
                      <span class="<?= $priceClass ?>"><?= formatCurrency($product['price']) ?></span>
                    <?php else: ?>
                      <span class="current-price">₱ —</span>
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
                          In Stock
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
                    <button class="add-to-cart-btn" onclick="addToCart(<?= (int)$product['id'] ?>)">
                      <i class="fi fi-rs-shopping-cart-add"></i>
                      Add to Cart
                    </button>
                    <button class="buy-now" type="button" onclick="buyNowDbProduct(<?= (int)$product['id'] ?>)">
                      <i class="fi fi-rr-shopping-bag"></i>
                      BUY NOW
                    </button>
                  <?php else: ?>
                    <button class="wishlist-action-btn" onclick="toggleWishlist(<?= (int)$product['id'] ?>)">
                      <i class="fi fi-rs-heart"></i>
                      Add to Wishlist
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="grid-column: 1 / -1; text-align: center; color: #94a3b8;">No desktops to display at the moment.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- LAPTOPS -->
      <div class="product-section laptop-section">
        <div class="section-header">
          <h2>LAPTOPS</h2>
          <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=laptops" class="see-more">SEE MORE</a>
        </div>
        <div class="product-grid">
          <div class="product-card">
            <div class="brand">LENOVO</div>
            <div class="product-image"><img src="<?= WEB_ROOT ?>public/shared/img/products/placeholder.svg" alt="Lenovo Laptop"></div>
            <div class="installment-badge">0% Installment</div>
            <h3>Lenovo V14 G4 ABP 14&quot; FHD Compa...</h3>
            <div class="rating"><i class="fi fi-rs-star" style="color: gold;"></i> No reviews</div>
            <div class="price">
              <span class="original-price">₱29,995.00</span>
              <span class="current-price">₱21,765.00</span>
            </div>
            <button class="buy-now" type="button" onclick="buyNowProduct('laptop-1')">BUY NOW</button>
          </div>

          <div class="product-card">
            <div class="brand">Acer</div>
            <div class="product-image"><img src="<?= WEB_ROOT ?>public/shared/img/products/acer-laptop.svg" alt="Acer Laptop"></div>
            <div class="installment-badge">0% Installment</div>
            <h3>Acer Aspire 3 A325-42 | 15.6" FHD IPS | AMD Ryzen 5 5500U | 8GB RAM | 512GB SSD</h3>
            <div class="rating"><i class="fi fi-rs-star" style="color: gold;"></i> No reviews</div>
            <div class="price">
              <span class="original-price">₱32,995.00</span>
              <span class="current-price">₱29,995.00</span>
            </div>
            <button class="buy-now" type="button" onclick="buyNowProduct('laptop-2')">BUY NOW</button>
          </div>

          <?php if (!empty($laptops)): ?>
            <?php foreach ($laptops as $product): ?>
              <div class="product-card">
                <div class="brand">TechWorld</div>
                <div class="product-image">
                  <a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $product['id'] ?>">
                    <img src="<?= htmlspecialchars($product['image_url'] ? WEB_ROOT . $product['image_url'] : 'img/products/placeholder.svg') ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                  </a>
                </div>
                <h3><a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?></a></h3>
                <div class="rating"><i class="fi fi-rs-star" style="color: gold;"></i> No reviews</div>
                
                <div class="price-stock-section">
                  <div class="price">
                    <?php if (floatval($product['price']) > 0): ?>
                      <?php
                        $priceText = preg_replace('/[^0-9]/', '', (string)round($product['price']));
                        $digits = strlen($priceText);
                        $priceClass = 'current-price';
                        if ($digits >= 9) { $priceClass .= ' xxs'; }
                        elseif ($digits >= 8) { $priceClass .= ' xs'; }
                        elseif ($digits >= 5) { $priceClass .= ' small'; }
                      ?>
                      <span class="<?= $priceClass ?>"><?= formatCurrency($product['price']) ?></span>
                    <?php else: ?>
                      <span class="current-price">₱ —</span>
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
                          In Stock
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
                    <button class="add-to-cart-btn" onclick="addToCart(<?= (int)$product['id'] ?>)">
                      <i class="fi fi-rs-shopping-cart-add"></i>
                      Add to Cart
                    </button>
                    <button class="buy-now" type="button" onclick="buyNowDbProduct(<?= (int)$product['id'] ?>)">
                      <i class="fi fi-rr-shopping-bag"></i>
                      BUY NOW
                    </button>
                  <?php else: ?>
                    <button class="wishlist-action-btn" onclick="toggleWishlist(<?= (int)$product['id'] ?>)">
                      <i class="fi fi-rs-heart"></i>
                      Add to Wishlist
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="grid-column: 1 / -1; text-align: center; color: #94a3b8;">No laptops to display at the moment.</p>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</section>

<style>
/* Enhanced Product Card Styles for Homepage */
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
    color: #00f5ff;
    font-size: 24px;
    font-weight: 800;
    display: block;
    text-shadow: 0 0 10px rgba(0, 245, 255, 0.3);
    background: linear-gradient(135deg, #00f5ff, #0080ff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Auto shrink for long prices */
.current-price.small { font-size: 20px; }
.current-price.xs { font-size: 18px; }
.current-price.xxs { font-size: 16px; }

.original-price {
    color: #94a3b8;
    font-size: 16px;
    text-decoration: line-through;
    margin-left: 8px;
    opacity: 0.8;
}

.stock-indicator {
    display: flex;
    align-items: center;
    font-size: 10px;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 50px;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.1);
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
    background: linear-gradient(45deg, rgba(255, 255, 255, 0.1), transparent, rgba(255, 255, 255, 0.05));
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
    color: #00bfff;
    border: none;
    animation: tech-pulse 4s ease-in-out infinite;
    box-shadow: none;
}

.stock-low {
    background: transparent;
    color: #00bfff;
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
        box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        border-color: #60a5fa;
    }
    100% { 
        box-shadow: 0 6px 25px rgba(59, 130, 246, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        border-color: #93c5fd;
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
.buy-now,
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
.buy-now::before,
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
.buy-now:hover::before,
.wishlist-action-btn:hover::before {
    left: 100%;
}

.add-to-cart-btn {
    background: linear-gradient(135deg, rgba(0, 245, 255, 0.2), rgba(0, 245, 255, 0.1));
    color: #00f5ff;
    border: 2px solid #00f5ff;
    box-shadow: 0 4px 15px rgba(0, 245, 255, 0.2);
}

.add-to-cart-btn:hover {
    background: linear-gradient(135deg, #00f5ff, #00d4ff);
    color: white;
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 6px 15px rgba(0, 245, 255, 0.3);
}

.buy-now {
    background: linear-gradient(135deg, #00f5ff, #0080ff, #6366f1);
    color: white;
    border: 2px solid transparent;
    box-shadow: 0 4px 15px rgba(0, 128, 255, 0.3);
    position: relative;
}

.buy-now:hover {
    background: linear-gradient(135deg, #0080ff, #6366f1, #8b5cf6);
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 8px 20px rgba(0, 128, 255, 0.4);
}

.wishlist-action-btn {
    background: linear-gradient(135deg, rgba(0, 245, 255, 0.1), rgba(30, 41, 59, 0.8));
    color: #00f5ff;
    border: 1px solid #00f5ff;
    width: 100%;
    box-shadow: 0 2px 8px rgba(0, 245, 255, 0.15);
    position: relative;
}

.wishlist-action-btn:hover {
    background: linear-gradient(135deg, rgba(0, 245, 255, 0.2), rgba(30, 41, 59, 0.9));
    color: #00f5ff;
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 6px 15px rgba(0, 245, 255, 0.3);
}

.wishlist-action-btn i {
    font-size: 12px;
    filter: drop-shadow(0 0 2px currentColor);
}

@media (max-width: 768px) {
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
}
</style>

<script>
function buyNowDbProduct(productId){
  // Go directly to checkout without adding to cart
  location.href = '<?= WEB_ROOT ?>public/authenticated/customer/checkout.php?selected=' + productId;
}

function buyNowProduct(productId){
  // Static demo product buttons redirect to products listing with search
  let productName = '';
  switch(productId){
    case 'laptop-1': productName = 'Lenovo V14 G4 ABP'; break;
    case 'laptop-2': productName = 'Acer Aspire 3 A325-42'; break;
    case 'desktop-1': productName = 'Ryzen 7 5700G'; break;
    case 'desktop-2': productName = 'Ryzen 3 3200G'; break;
    default: productName = productId;
  }
  const q = encodeURIComponent(productName.replace(/[^a-zA-Z0-9\s]/g,'').trim());
  location.href = '<?= WEB_ROOT ?>public/authenticated/customer/products.php?q=' + q;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>


