<?php
// cart.php - Shopping cart page
require_once '../../config/functions.php';

// Guest users can view cart but need to login to checkout

$pageTitle = 'Shopping Cart - TechWorld';
$isLoggedIn = isLoggedIn();
$userId = $isLoggedIn ? getUserId() : 'guest';

// Initialize cart if not exists
if (!isset($_SESSION['cart'][$userId])) {
    $_SESSION['cart'][$userId] = [];
}

// Handle cart actions for guest users
if (!$isLoggedIn) {
    // Handle remove item
    if (isset($_GET['remove'])) {
        $pid = (int)$_GET['remove'];
        if (isset($_SESSION['cart'][$userId][$pid])) {
            unset($_SESSION['cart'][$userId][$pid]);
        }
        header('Location: ' . WEB_ROOT . 'public/guest/cart.php');
        exit;
    }
    
    // Handle clear cart
    if (isset($_GET['clear']) && $_GET['clear'] == '1') {
        $_SESSION['cart'][$userId] = [];
        header('Location: ' . WEB_ROOT . 'public/guest/cart.php');
        exit;
    }
    
    // Handle update quantity
    if (isset($_GET['update']) && isset($_GET['qty'])) {
        $pid = (int)$_GET['update'];
        $qty = (int)$_GET['qty'];
        
        if ($qty <= 0) {
            unset($_SESSION['cart'][$userId][$pid]);
        } else {
            // Check stock before updating
            try {
                $pdo = getConnection();
                $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = ? AND status = 'active'");
                $stmt->execute([$pid]);
                $stock = $stmt->fetchColumn();
                
                if ($stock !== false && $qty <= $stock) {
                    $_SESSION['cart'][$userId][$pid] = $qty;
                }
            } catch (PDOException $e) {
                error_log("Error checking stock: " . $e->getMessage());
            }
        }
        header('Location: ' . WEB_ROOT . 'public/guest/cart.php');
        exit;
    }
}

// Get cart items with product details
$cartItems = [];
$cartTotal = 0;

if (!empty($_SESSION['cart'][$userId])) {
    try {
        $pdo = getConnection();
        foreach ($_SESSION['cart'][$userId] as $productId => $quantity) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if ($product) {
                $subtotal = $product['price'] * $quantity;
                $cartItems[] = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'image_url' => $product['image_url'],
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'stock' => $product['stock']
                ];
                $cartTotal += $subtotal;
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching cart items: " . $e->getMessage());
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<!-- Cart Page -->
<section class="cart-page">
    <div class="container">
        <div class="cart-header">
            <h1>Shopping Cart</h1>
            <p>Review your items before checkout</p>
        </div>
        
        <?php if (empty($cartItems)): ?>
            <!-- Empty Cart -->
            <div class="empty-cart">
                <div class="empty-cart-icon">
                    <i class="fi fi-rs-shopping-cart"></i>
                </div>
                <h2>Your cart is empty</h2>
                <p>Looks like you haven't added any items to your cart yet.</p>
                <a href="<?php echo WEB_ROOT; ?>products.php" class="btn-primary">Continue Shopping</a>
            </div>
        <?php else: ?>
            <!-- Cart Items -->
            <div class="cart-layout">
                <div class="cart-items">
                    <div class="cart-items-header">
                        <h2>Items in your cart (<?php echo count($cartItems); ?>)</h2>
                        <button class="clear-cart-btn" onclick="clearCart()">Clear Cart</button>
                    </div>
                    
                    <div class="items-list">
                        <?php foreach ($cartItems as $item): ?>
                            <div class="cart-item" data-product-id="<?php echo $item['id']; ?>">
                                <div class="item-image">
                                    <?php if ($item['image_url']): ?>
                                        <img src="<?php echo WEB_ROOT . htmlspecialchars($item['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['name']); ?>">
                                    <?php else: ?>
                                        <div class="image-placeholder">IMG</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="item-details">
                                    <h3 class="item-name">
                                        <a href="<?= WEB_ROOT ?>product.php?id=<?php echo $item['id']; ?>">
                                            <?php echo htmlspecialchars($item['name']); ?>
                                        </a>
                                    </h3>
                                    <p class="item-price"><?php echo formatCurrency($item['price']); ?> each</p>
                                    
                                    <?php if ($item['stock'] <= 0): ?>
                                        <div class="stock-warning out-of-stock">
                                            <i class="fi fi-ss-exclamation-triangle"></i>
                                            Out of stock
                                        </div>
                                    <?php elseif ($item['quantity'] > $item['stock']): ?>
                                        <div class="stock-warning">
                                            <i class="fi fi-ss-exclamation-triangle"></i>
                                            Only <?php echo $item['stock']; ?> available (you have <?php echo $item['quantity']; ?> in cart)
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="item-controls">
                                    <div class="quantity-control">
                                        <button class="qty-btn minus" onclick="updateQuantity(<?php echo $item['id']; ?>, -1)">
                                            <i class="fi fi-rs-minus"></i>
                                        </button>
                                        <input type="number" class="qty-input" value="<?php echo $item['quantity']; ?>" 
                                               min="0" max="<?php echo $item['stock']; ?>" 
                                               onchange="updateQuantityDirect(<?php echo $item['id']; ?>, this.value)">
                                        <button class="qty-btn plus" onclick="updateQuantity(<?php echo $item['id']; ?>, 1)" 
                                                <?php echo $item['quantity'] >= $item['stock'] ? 'disabled' : ''; ?>>
                                            <i class="fi fi-rs-plus"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="item-subtotal">
                                        <?php echo formatCurrency($item['subtotal']); ?>
                                    </div>
                                    
                                    <button class="remove-item-btn" onclick="removeFromCart(<?php echo $item['id']; ?>)">
                                        <i class="fi fi-rs-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Cart Summary -->
                <div class="cart-summary">
                    <div class="summary-card">
                        <h3>Order Summary</h3>
                        
                        <?php $vatBreakdown = phVatAdd($cartTotal); ?>
                        <div class="summary-line">
                            <span>Subtotal (<?php echo count($cartItems); ?> items)</span>
                            <span class="cart-subtotal"><?php echo formatCurrency($vatBreakdown['net']); ?></span>
                        </div>


                        <div class="summary-line tax-line">
                            <span>VAT (12%)</span>
                            <span class="tax-amount"><?php echo formatCurrency($vatBreakdown['vat']); ?></span>
                        </div>

                        <div class="summary-divider"></div>

                        <div class="summary-line total-line">
                            <span>Total</span>
                            <span class="cart-total"><?php echo formatCurrency($vatBreakdown['gross']); ?></span>
                        </div>
                        
                        <!-- Checkout Buttons -->
                        <div class="checkout-actions">
                            <a href="<?= WEB_ROOT ?>checkout.php" class="checkout-btn" id="checkoutBtn">
                                <i class="fi fi-rs-shopping-bag"></i>
                                Proceed to Checkout
                            </a>
                            
                            <a href="<?php echo WEB_ROOT; ?>products.php" class="continue-shopping-btn">
                                Continue Shopping
                            </a>
                        </div>
                        
                        <!-- Payment Methods -->
                        <div class="payment-info">
                            <h4>We Accept</h4>
                            <div class="payment-methods">
                                <button class="payment-method clickable" onclick="selectPaymentMethod('cod')">
                                    <i class="fi fi-rs-money-bill-wave"></i>
                                    <span>Cash on Delivery</span>
                                </button>
                                <button class="payment-method clickable" onclick="selectPaymentMethod('store')">
                                    <i class="fi fi-rs-shop"></i>
                                    <span>Pay at Store</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Security Info -->
                        <div class="security-info">
                            <div class="security-badge">
                                <i class="fi fi-rs-shield-check"></i>
                                <span>Secure Checkout</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.cart-page {
    padding: 40px 0;
    background: var(--page-bg-gradient);
    min-height: 100vh;
}

.cart-header {
    text-align: center;
    margin-bottom: 40px;
}

.cart-header h1 {
    color: var(--text-primary);
    font-size: 32px;
    margin-bottom: 10px;
}

.cart-header p {
    color: var(--text-muted);
    font-size: 16px;
}

.empty-cart {
    text-align: center;
    background: var(--bg-card-alpha-80);
    border-radius: 20px;
    padding: 80px 40px;
    border: 1px solid var(--border-color);
    max-width: 500px;
    margin: 0 auto;
}

.empty-cart-icon i {
    font-size: 80px;
    color: var(--text-muted);
    margin-bottom: 30px;
}

.empty-cart h2 {
    color: var(--text-primary);
    margin-bottom: 15px;
    font-size: 28px;
}

.empty-cart p {
    color: var(--text-muted);
    margin-bottom: 30px;
    font-size: 16px;
    line-height: 1.6;
}

.btn-primary {
    background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
    color: white;
    padding: 15px 30px;
    border: none;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    display: inline-block;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3);
}

.cart-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 40px;
}

.cart-items {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    padding: 30px;
    border: 1px solid var(--border-color);
}

.cart-items-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.cart-items-header h2 {
    color: var(--text-primary);
    font-size: 24px;
}

.clear-cart-btn {
    background: transparent;
    border: 2px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s ease;
}

.clear-cart-btn:hover {
    background: rgba(239, 68, 68, 0.1);
}

.items-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.cart-item {
    display: grid;
    grid-template-columns: 100px 1fr auto;
    gap: 20px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.cart-item:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: var(--accent-cyan-bg-20);
}

.item-image {
    width: 100px;
    height: 100px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.item-image img {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
}

.image-placeholder {
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 600;
}

.item-details {
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.item-name a {
    color: var(--text-primary);
    text-decoration: none;
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
    display: block;
    line-height: 1.4;
}

.item-name a:hover {
    color: var(--accent-cyan);
}

.item-price {
    color: var(--text-muted);
    font-size: 16px;
    margin-bottom: 10px;
}

.stock-warning {
    background: rgba(245, 158, 11, 0.1);
    color: #f59e0b;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
}

.stock-warning.out-of-stock {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

.item-controls {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 15px;
    min-width: 200px;
}

.quantity-control {
    display: flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    overflow: hidden;
}

.qty-btn {
    width: 35px;
    height: 35px;
    border: none;
    background: transparent;
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.qty-btn:hover:not(:disabled) {
    background: var(--accent-cyan-bg);
    color: var(--accent-cyan);
}

.qty-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.qty-input {
    width: 50px;
    height: 35px;
    border: none;
    background: transparent;
    color: var(--text-primary);
    text-align: center;
    font-size: 14px;
    font-weight: 600;
}

.qty-input:focus {
    outline: none;
    background: var(--accent-cyan-bg);
}

.item-subtotal {
    color: var(--accent-cyan);
    font-size: 18px;
    font-weight: 700;
}

.remove-item-btn {
    background: transparent;
    border: none;
    color: #ef4444;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.remove-item-btn:hover {
    background: rgba(239, 68, 68, 0.1);
}

.cart-summary {
    background: var(--bg-card-alpha-80);
    border-radius: 15px;
    border: 1px solid var(--border-color);
    height: fit-content;
    position: sticky;
    top: 20px;
}

.summary-card {
    padding: 30px;
}

.summary-card h3 {
    color: var(--text-primary);
    font-size: 24px;
    margin-bottom: 25px;
}

.summary-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    color: var(--text-muted);
}

.summary-line span:last-child {
    font-weight: 600;
}

.tax-line {
    margin-bottom: 20px;
}

.summary-divider {
    height: 1px;
    background: var(--border-color);
    margin: 20px 0;
}

.total-line {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 30px;
}

.cart-total {
    color: var(--accent-cyan) !important;
    font-size: 22px !important;
}

.checkout-actions {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-bottom: 30px;
}

.checkout-btn {
    background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
    color: white;
    padding: 15px 20px;
    border: none;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.3s ease;
}

.checkout-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3);
}

.continue-shopping-btn {
    background: transparent;
    color: var(--text-muted);
    padding: 12px 20px;
    border: 2px solid var(--border-color);
    border-radius: 10px;
    text-decoration: none;
    text-align: center;
    transition: all 0.3s ease;
}

.continue-shopping-btn:hover {
    border-color: var(--accent-cyan-bg-30);
    color: var(--accent-cyan);
}

.payment-info {
    margin-bottom: 25px;
    padding-top: 25px;
    border-top: 1px solid var(--border-color);
}

.payment-info h4 {
    color: var(--text-primary);
    font-size: 16px;
    margin-bottom: 15px;
}

.payment-methods {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.payment-method {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-muted);
    font-size: 14px;
    background: transparent;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    padding: 10px 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 500;
}

.payment-method:hover {
    background: var(--accent-cyan-bg);
    border-color: var(--accent-cyan);
    color: var(--text-primary);
    transform: translateY(-1px);
}

.payment-method.active {
    background: var(--accent-cyan-bg-20);
    border-color: var(--accent-cyan);
    color: var(--accent-cyan);
    box-shadow: 0 2px 8px var(--accent-cyan-bg-30);
}

.payment-method i {
    color: var(--accent-cyan);
    font-size: 16px;
    transition: all 0.3s ease;
}

.payment-method:hover i,
.payment-method.active i {
    color: var(--accent-cyan);
    transform: scale(1.1);
}

.security-info {
    text-align: center;
}

.security-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #10b981;
    font-size: 14px;
    font-weight: 600;
}

.security-badge i {
    font-size: 16px;
}

@media (max-width: 768px) {
    .cart-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .cart-summary {
        order: -1;
        position: static;
    }
    
    .cart-item {
        grid-template-columns: 80px 1fr;
        grid-template-rows: auto auto;
        gap: 15px;
    }
    
    .item-controls {
        grid-column: 1 / -1;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        min-width: auto;
    }
    
    .cart-items-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
}
</style>

<script>
// Guest cart functions - simplified for non-logged in users
function updateQuantity(productId, change) {
    const currentInput = document.querySelector(`[data-product-id="${productId}"] .qty-input`);
    if (!currentInput) return;
    
    const currentValue = parseInt(currentInput.value) || 0;
    const newValue = Math.max(0, currentValue + change);
    
    updateCartQuantity(productId, newValue);
}

function updateQuantityDirect(productId, newValue) {
    const value = Math.max(0, parseInt(newValue) || 0);
    updateCartQuantity(productId, value);
}

function updateCartQuantity(productId, quantity) {
    // For guest users, update the UI and redirect to update cart
    const currentInput = document.querySelector(`[data-product-id="${productId}"] .qty-input`);
    if (currentInput) {
        currentInput.value = quantity;
    }
    
    if (quantity === 0) {
        // Remove item by redirecting to cart with remove parameter
        window.location.href = '<?= WEB_ROOT ?>public/guest/cart.php?remove=' + productId;
    } else {
        // Update cart by redirecting with update parameters
        window.location.href = '<?= WEB_ROOT ?>public/guest/cart.php?update=' + productId + '&qty=' + quantity;
    }
}

async function removeFromCart(productId) {
    if ((await uiConfirm('Are you sure you want to remove this item from your cart?'))) {
        window.location.href = '<?= WEB_ROOT ?>public/guest/cart.php?remove=' + productId;
    }
}

async function clearCart() {
    if ((await uiConfirm('Are you sure you want to clear your entire cart?'))) {
        window.location.href = '<?= WEB_ROOT ?>public/guest/cart.php?clear=1';
    }
}

function updateCartDisplay() {
    // Recalculate totals
    let subtotal = 0;
    document.querySelectorAll('.cart-item').forEach(item => {
        const quantity = parseInt(item.querySelector('.qty-input').value) || 0;
        const priceText = item.querySelector('.item-price').textContent;
        const price = parseFloat(priceText.replace(/[^\d.]/g, '')) || 0;
        
        const itemSubtotal = price * quantity;
        const subtotalElement = item.querySelector('.item-subtotal');
        if (subtotalElement) {
            subtotalElement.textContent = formatCurrency(itemSubtotal);
        }
        subtotal += itemSubtotal;
    });
    
    // Update summary - PH standard 12% VAT is added on top of the item subtotal
    const subtotalElement = document.querySelector('.cart-subtotal');
    const taxElement = document.querySelector('.tax-amount');
    const totalElement = document.querySelector('.cart-total');
    const vat = Math.round(subtotal * 0.12 * 100) / 100;
    const total = Math.round((subtotal + vat) * 100) / 100;
    if (subtotalElement) subtotalElement.textContent = formatCurrency(subtotal);
    if (taxElement) taxElement.textContent = formatCurrency(vat);
    if (totalElement) totalElement.textContent = formatCurrency(total);
}

function formatCurrency(amount) {
    try {
        return new Intl.NumberFormat('en-PH', { 
            style: 'currency', 
            currency: 'PHP', 
            minimumFractionDigits: 2 
        }).format(Number(amount || 0));
    } catch (e) {
        // Fallback
        const num = (Number(amount || 0)).toFixed(2);
        return '₱' + num.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
}

function showNotification(message, type) {
    // Simple notification for guest users
    alert(message);
}

// Check for stock issues on page load
document.addEventListener('DOMContentLoaded', function() {
    const stockWarnings = document.querySelectorAll('.stock-warning.out-of-stock');
    if (stockWarnings.length > 0) {
        const checkoutBtn = document.getElementById('checkoutBtn');
        if (checkoutBtn) {
            checkoutBtn.style.opacity = '0.5';
            checkoutBtn.style.pointerEvents = 'none';
            checkoutBtn.innerHTML = '<i class="fi fi-rs-exclamation-triangle"></i> Stock Issues - Cannot Checkout';
        }
    }
    
    // Initialize cart display
    updateCartDisplay();
});

function selectPaymentMethod(method) {
    // Remove active class from all payment methods
    document.querySelectorAll('.payment-method').forEach(option => {
        option.classList.remove('active');
    });
    
    // Add active class to selected option
    event.target.closest('.payment-method').classList.add('active');
    
    // Store selected payment method
    localStorage.setItem('selectedPaymentMethod', method);
    
    // Show confirmation message
    const methodName = method === 'cod' ? 'Cash on Delivery' : 'Pay at Store';
    showNotification(`Payment method selected: ${methodName}`, 'success');
    
    // Update checkout button text
    updateCheckoutButton(method);
}

function updateCheckoutButton(paymentMethod) {
    const checkoutBtn = document.querySelector('.checkout-btn');
    
    if (checkoutBtn) {
        if (paymentMethod === 'cod') {
            checkoutBtn.innerHTML = '<i class="fi fi-rs-shopping-cart"></i> Proceed to Checkout (COD)';
        } else if (paymentMethod === 'store') {
            checkoutBtn.innerHTML = '<i class="fi fi-rs-shopping-cart"></i> Proceed to Checkout (Store Pickup)';
        }
    }
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>