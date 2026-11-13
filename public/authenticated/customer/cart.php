<?php
// cart.php - Customer cart page (requires login)
require_once __DIR__ . '/../../../config/functions.php';
require_login();

$pageTitle = 'Shopping Cart - TechWorld';
$activeNav = 'cart';
$userId = getUserId();

// Load persistent cart from DB into session if available
if ($userId && function_exists('loadCartFromDb')) {
    if (!isset($_SESSION['cart'][$userId]) || empty($_SESSION['cart'][$userId])) {
        $dbCart = loadCartFromDb($userId);
        if (!empty($dbCart)) {
            $_SESSION['cart'][$userId] = $dbCart;
        }
    }
}

// Server-side fallbacks for remove/clear (non-JS or AJAX failures)
if (isset($_GET['remove'])) {
    $pid = (int)$_GET['remove'];
    if (isset($_SESSION['cart'][$userId][$pid])) {
        unset($_SESSION['cart'][$userId][$pid]);
        if (function_exists('untagCartBundleProduct')) { untagCartBundleProduct($userId, $pid); }
        // Sync with database
        if (function_exists('saveCartToDb')) {
            saveCartToDb($userId, $_SESSION['cart'][$userId]);
        }
    }
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
    exit;
}
if (isset($_GET['clear']) && $_GET['clear'] == '1') {
    if (isset($_SESSION['cart'][$userId])) { 
        unset($_SESSION['cart'][$userId]); 
    }
    // Also clear from database
    if (function_exists('clearCartInDb')) {
        clearCartInDb($userId);
    }
    if (function_exists('saveCartToDb')) {
        saveCartToDb($userId, []);
    }
    if (function_exists('clearCartBundles')) { clearCartBundles($userId); }
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
    exit;
}

if (!isset($_SESSION['cart'][$userId])) {
    $_SESSION['cart'][$userId] = [];
}

// Keep the persisted cart in sync with current stock on every visit.
// Quantities above available stock are capped down; items that go fully
// out of stock are NOT removed here - they're kept and rendered in the
// Wishlist section below, then routed back to the Cart section
// automatically (no extra bookkeeping needed) once the product is restocked.
if (!empty($_SESSION['cart'][$userId]) && function_exists('capCartQuantitiesByStock')) {
    $cappedCart = capCartQuantitiesByStock($_SESSION['cart'][$userId]);
    if ($cappedCart !== $_SESSION['cart'][$userId]) {
        $_SESSION['cart'][$userId] = $cappedCart;
        if (function_exists('saveCartToDb')) {
            saveCartToDb($userId, $cappedCart);
        }
    }
}

$cartItems = [];      // purchasable items (stock > 0)
$wishlistItems = [];  // out-of-stock items, waiting to return automatically
$cartTotal = 0;

if (!empty($_SESSION['cart'][$userId])) {
    try {
        $pdo = getConnection();
        $inIds = array_keys($_SESSION['cart'][$userId]);
        // Fetch products in one query for speed
        $placeholders = implode(',', array_fill(0, count($inIds), '?'));
        if (!empty($placeholders)) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND status = 'active'");
            $stmt->execute($inIds);
            $rows = $stmt->fetchAll();
        } else {
            $rows = [];
        }
        $idToProduct = [];
        foreach ($rows as $row) { $idToProduct[$row['id']] = $row; }
        foreach ($_SESSION['cart'][$userId] as $productId => $quantity) {
            if (!isset($idToProduct[$productId])) { continue; }
            $product = $idToProduct[$productId];
            $price = (float)($product['price'] ?? 0);
            $stock = (int)$product['stock'];
            $subtotal = $price * (int)$quantity;
            $entry = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $price,
                'image_url' => $product['image_url'],
                'quantity' => (int)$quantity,
                'subtotal' => $subtotal,
                'stock' => $stock
            ];
            if ($stock > 0) {
                $cartItems[] = $entry;
                $cartTotal += $subtotal;
            } else {
                $wishlistItems[] = $entry;
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching cart items: " . $e->getMessage());
    }
}

// Group in-stock cart items by bundle (e.g. an AI Builder "Add This Build
// to Cart" click) purely for display - each item below is still the exact
// same independently-removable .cart-item as before, just visually
// clustered under a shared header when it has a bundle tag.
$bundleMap = function_exists('getCartBundleMap') ? getCartBundleMap($userId) : [];
$bundleGroups = [];   // bundle_id => ['name' => ..., 'items' => [...]]
$ungroupedItems = [];
foreach ($cartItems as $item) {
    $tag = $bundleMap[$item['id']] ?? null;
    if ($tag) {
        $bid = $tag['bundle_id'];
        if (!isset($bundleGroups[$bid])) { $bundleGroups[$bid] = ['name' => $tag['bundle_name'], 'items' => [], 'subtotal' => 0]; }
        $bundleGroups[$bid]['items'][] = $item;
        $bundleGroups[$bid]['subtotal'] += $item['subtotal'];
    } else {
        $ungroupedItems[] = $item;
    }
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
  <!-- Same pair the landing page and the other redesigned customer pages
       load: Figtree for headings, JetBrains Mono for eyebrows and the
       small-caps labels below. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<section class="cart-page">
  <div class="container">
    <?php $inStockCount = array_sum(array_column($cartItems, 'quantity')); ?>
    <?php $wishlistCount = count($wishlistItems); ?>
    <div class="cart-header">
      <div class="cx-eyebrow"><span aria-hidden="true"></span>Your cart</div>
      <h1 class="cx-title">Shopping <em>Cart</em></h1>
      <p class="ck-meta">
        <span class="cx-chip"><i class="fi fi-rs-box" aria-hidden="true"></i>Total items <strong><?= (int)$inStockCount ?></strong></span>
        <?php if ($wishlistCount > 0): ?>
          <span class="cx-chip ck-chip--wish"><i class="fi fi-rs-heart" aria-hidden="true"></i><strong><?= (int)$wishlistCount ?></strong> item<?= $wishlistCount === 1 ? '' : 's' ?> waiting in Wishlist</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="cx-rule" aria-hidden="true"><i></i></div>

    <?php
      // Render the Wishlist items markup once, full width and same size as
      // the Cart Items card - shown above the cart layout so it's visible
      // immediately, never requiring a scroll past however many items are
      // in the main cart list.
      ob_start();
      if (!empty($wishlistItems)):
    ?>
        <div class="wishlist-section">
          <div class="wishlist-section-header">
            <div class="wishlist-section-title">
              <h2><i class="fi fi-rs-heart"></i> Wishlist <span class="wishlist-count">(<?= count($wishlistItems) ?>)</span></h2>
              <?php if (!empty($cartItems)): ?>
                <button type="button" class="wishlist-hide-btn" onclick="toggleWishlistPanel()">
                  <i class="fi fi-rs-cross"></i> Hide
                </button>
              <?php endif; ?>
            </div>
            <p>Out of stock - these move back to your cart automatically once restocked.</p>
          </div>
          <div class="wishlist-items-list">
            <?php foreach ($wishlistItems as $item): ?>
              <div class="wishlist-item" data-product-id="<?= (int)$item['id'] ?>">
                <div class="item-image">
                  <?php if (!empty($item['image_url'])): ?>
                    <img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                  <?php else: ?>
                    <div class="image-placeholder">IMG</div>
                  <?php endif; ?>
                </div>
                <div class="item-details">
                  <h3 class="item-name"><a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= (int)$item['id'] ?>"><?= htmlspecialchars($item['name']) ?></a></h3>
                  <p class="item-price"><?= formatCurrency($item['price']) ?> each</p>
                  <p class="out-of-stock-badge"><i class="fi fi-ss-exclamation-triangle"></i> Out of Stock</p>
                  <p class="item-desired-qty">Quantity saved: <strong><?= (int)$item['quantity'] ?></strong></p>
                </div>
                <div class="item-controls">
                  <button class="remove-item-btn" onclick="removeFromCart(<?= (int)$item['id'] ?>)" data-fallback="<?= WEB_ROOT ?>public/authenticated/customer/cart.php?remove=<?= (int)$item['id'] ?>" title="Remove from Wishlist"><i class="fi fi-rs-trash"></i></button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
    <?php
      endif;
      $wishlistSectionHtml = ob_get_clean();
    ?>

    <?php if (empty($cartItems) && empty($wishlistItems)): ?>
      <div class="empty-cart">
        <div class="empty-cart-icon"><i class="fi fi-rs-shopping-cart"></i></div>
        <h2>Your cart is empty</h2>
        <p>Looks like you haven't added any items to your cart yet.</p>
        <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="btn-primary">Browse Products</a>
      </div>
    <?php else: ?>
      <?php if (empty($cartItems)): ?>
        <div class="cart-empty-instock-note">
          <p>You have no items ready for checkout right now. Items below are waiting in your Wishlist until they're back in stock.</p>
        </div>
        <?= $wishlistSectionHtml ?>
      <?php else: ?>
      <div class="cart-layout">
        <div class="cart-items">
          <div class="cart-items-header">
            <h2>Items in your cart (<?= count($cartItems) ?>)</h2>
            <div class="cart-items-actions">
              <label class="select-all-label">
                <input type="checkbox" id="selectAll" checked onchange="onSelectAll(this)">
                <span>Select All</span>
              </label>
              <?php if (!empty($wishlistItems)): ?>
                <button type="button" class="wishlist-toggle-btn" id="wishlistToggleBtn" onclick="toggleWishlistPanel()">
                  <i class="fi fi-rs-heart"></i> Wishlist (<?= count($wishlistItems) ?>)
                </button>
              <?php endif; ?>
              <button class="clear-cart-btn" onclick="clearCart()" data-fallback="<?= WEB_ROOT ?>public/authenticated/customer/cart.php?clear=1">Clear Cart</button>
            </div>
          </div>
          <?php if (!empty($wishlistItems)): ?>
            <div class="wishlist-panel" id="wishlistPanel" style="display:none;">
              <?= $wishlistSectionHtml ?>
            </div>
          <?php endif; ?>
          <?php
            // Renders one .cart-item row - unchanged from before, just
            // factored out so both the bundle groups and the plain list
            // below can share it instead of duplicating the markup.
            $renderCartItem = function ($item) {
          ?>
              <!-- Rendered already selected, and the Order Summary below is
                   rendered to match. The page used to arrive with every row
                   unticked and the card at P0.00, waiting for JS to tick them;
                   when anything stopped that script running the cart just sat
                   there, and no amount of clicking moved the total.
                   onchange is an inline attribute for the same reason, and to
                   match the quantity controls right below: the browser binds
                   it while parsing, so it cannot be lost to a script that
                   failed somewhere else on the page. -->
              <div class="cart-item selected" data-product-id="<?= (int)$item['id'] ?>" data-stock="<?= (int)$item['stock'] ?>">
                <div class="item-select">
                  <input type="checkbox" class="select-item" aria-label="Select item" checked onchange="onSelectItem(this)">
                </div>
                <div class="item-image">
                  <?php if (!empty($item['image_url'])): ?>
                    <img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                  <?php else: ?>
                    <div class="image-placeholder">IMG</div>
                  <?php endif; ?>
                </div>
                <div class="item-details">
                  <h3 class="item-name"><a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= (int)$item['id'] ?>"><?= htmlspecialchars($item['name']) ?></a></h3>
                  <p class="item-price"><?= formatCurrency($item['price']) ?> each</p>
                  <p class="item-stock">Available: <strong><?= (int)$item['stock'] ?></strong></p>
                </div>
                <div class="item-controls">
                  <div class="quantity-control">
                    <button type="button" class="qty-btn minus" onclick="updateQuantity(<?= (int)$item['id'] ?>,-1)"><i class="fi fi-rs-minus"></i><span>-</span></button>
                    <input type="number" class="qty-input" value="<?= (int)$item['quantity'] ?>" min="0" max="<?= (int)$item['stock'] ?>" onchange="updateQuantityDirect(<?= (int)$item['id'] ?>, this.value)">
                    <button type="button" class="qty-btn plus" onclick="updateQuantity(<?= (int)$item['id'] ?>,1)" <?= $item['quantity'] >= $item['stock'] ? 'disabled' : '' ?>><i class="fi fi-rs-plus"></i><span>+</span></button>
                  </div>
                  <div class="item-subtotal"><?= formatCurrency($item['subtotal']) ?></div>
                  <button class="remove-item-btn" onclick="removeFromCart(<?= (int)$item['id'] ?>)" data-fallback="<?= WEB_ROOT ?>public/authenticated/customer/cart.php?remove=<?= (int)$item['id'] ?>"><i class="fi fi-rs-trash"></i></button>
                </div>
              </div>
          <?php
            };
          ?>
          <div class="items-list">
            <?php foreach ($bundleGroups as $bundleId => $group): ?>
              <div class="cart-bundle-group" data-bundle-id="<?= htmlspecialchars($bundleId) ?>">
                <div class="cart-bundle-header" onclick="toggleBundleGroup(this)">
                  <?php /* Tick/untick the whole build in one click, so nobody has
                           to check eight rows by hand. stopPropagation on the
                           label because the header itself collapses the group -
                           without it, selecting the build would also fold it
                           shut. onchange is inline for the same reason the row
                           checkboxes are: the parser binds it, so it survives a
                           script failing elsewhere on the page. */ ?>
                  <label class="cart-bundle-select" onclick="event.stopPropagation();"
                         title="Select or clear every item in this build">
                    <input type="checkbox" class="cart-bundle-select-box" checked onchange="onSelectBundle(this)">
                    <span>All</span>
                  </label>
                  <span class="cart-bundle-title">
                    <i class="fi fi-rs-angle-small-down cart-bundle-chevron"></i>
                    <i class="fi fi-rs-shopping-bag-add"></i> AI Build: <?= htmlspecialchars($group['name']) ?>
                    <span class="cart-bundle-count"><?= count($group['items']) ?> item<?= count($group['items']) === 1 ? '' : 's' ?> · <?= formatCurrency($group['subtotal']) ?></span>
                  </span>
                  <button type="button" class="cart-bundle-remove-btn" onclick="event.stopPropagation(); removeBundle('<?= htmlspecialchars($bundleId) ?>')">Remove Bundle</button>
                </div>
                <div class="cart-bundle-items">
                  <?php foreach ($group['items'] as $item) { $renderCartItem($item); } ?>
                </div>
              </div>
            <?php endforeach; ?>
            <?php foreach ($ungroupedItems as $item) { $renderCartItem($item); } ?>
          </div>
          <div class="cart-items-footer">
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="continue-shopping-btn">Continue Shopping</a>
          </div>
        </div>
        <div class="cart-summary">
          <div class="summary-card">
            <h3>Order Summary</h3>
            <!-- The card totals the SELECTED items, not the whole cart - that is
                 what the Proceed to Checkout button carries over. Filled by
                 cartRefreshSummary() on load and on every selection/quantity
                 change; the zeros are only what shows before it runs.

                 The list above the figures names those items. Without it the
                 card was three numbers with nothing to check them against -
                 and an out-of-stock row, which lives in the Wishlist and has
                 no checkbox, looked like a selection that had been ignored
                 rather than one that was never possible. -->
            <?php $summaryVat = phVatAdd($cartTotal); ?>
            <!-- Rendered from the same $cartItems the rows above use, matching
                 their server-rendered ticked state. JS rewrites this on every
                 change; it no longer has to run just to make the card correct
                 on arrival. -->
            <div class="summary-items" id="cartSummaryItems">
              <?php foreach ($cartItems as $item): ?>
                <div class="summary-item">
                  <span class="si-name" title="<?= htmlspecialchars($item['name']) ?>"><?= htmlspecialchars($item['name']) ?><span class="si-qty"> x<?= (int)$item['quantity'] ?></span></span>
                  <span class="si-amount"><?= formatCurrency($item['subtotal']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
            <p class="summary-empty" id="cartSummaryEmpty" <?= !empty($cartItems) ? 'hidden' : '' ?>>Tick the items you want to check out.</p>
            <?php if (!empty($wishlistItems)): ?>
              <!-- Says out loud why those rows can never join the list above:
                   they are out of stock, so they sit in the Wishlist without a
                   checkbox and cannot be part of a total. -->
              <p class="summary-note">
                <i class="fi fi-rs-heart" aria-hidden="true"></i>
                <?= count($wishlistItems) ?> out-of-stock item<?= count($wishlistItems) === 1 ? '' : 's' ?>
                in your Wishlist can't be checked out yet.
              </p>
            <?php endif; ?>
            <div class="summary-divider"></div>
            <div class="summary-line"><span id="cartSubtotalLabel">Subtotal (<?= count($cartItems) ?> item<?= count($cartItems) === 1 ? '' : 's' ?>)</span><span class="cart-subtotal"><?= formatCurrency($summaryVat['net']) ?></span></div>
            <div class="summary-line tax-line"><span>VAT (12%)</span><span class="tax-amount"><?= formatCurrency($summaryVat['vat']) ?></span></div>
            <div class="summary-divider"></div>
            <div class="summary-line total-line"><span>Total</span><span class="cart-total"><?= formatCurrency($summaryVat['gross']) ?></span></div>
            <div class="checkout-actions">
              <a onclick="Object.keys(cartPending||{}).forEach(function(i){cartCommitRemove(i);});"
                 href="<?= WEB_ROOT ?>public/authenticated/customer/checkout.php" class="checkout-btn" id="checkoutBtn"><i class="fi fi-rs-shopping-bag"></i> Proceed to Checkout</a>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<style>
/* ===========================================================================
   Shopping Cart, in the landing page's visual language.

   PRESENTATION ONLY. Every class, id, data-attribute and inline handler the
   scripts at the bottom of this page reach for is untouched - .cart-item,
   .qty-input, .qty-btn.minus/.plus, .item-price (its text is parsed for the
   price), .item-subtotal, .select-item, .selected, #selectAll,
   .cart-bundle-group/.cart-bundle-count/.expanded, #wishlistPanel,
   #wishlistToggleBtn, .cart-subtotal/.tax-amount/.cart-total and #checkoutBtn
   all still exist and still mean the same thing. In particular the summary
   card's line order is unchanged, because updateSummaryTotals() rewrites its
   first line by `.summary-line span`.

   The shared .cx-* primitives (eyebrow, title, rule, chip) live in
   customer-dark.css; the ground - dark navy radial, panning grid, drifting
   pink and cyan blobs - is already painted there for .cart-page.

   What changed is the furniture: the neumorphic light-theme cards this page
   shipped with (var(--neu-raised), pressed insets, a near-white highlight)
   never belonged on a dark ground. They are replaced by the landing panel -
   a navy gradient over a 1px blue hairline - with the landing accents on top.
   ======================================================================== */
.cart-page {
  padding: 40px 0 64px;
  background: var(--page-bg-gradient);
  min-height: 100vh;
}
.cart-page .container { max-width: 1320px; }

/* --- header --------------------------------------------------------------- */
.cart-header { margin-bottom: 0; text-align: left; }
.ck-meta { display: flex; flex-wrap: wrap; gap: 10px; margin: 18px 0 0; }
.ck-chip--wish { border-color: rgba(224, 86, 122, .34); background: rgba(224, 86, 122, .10); color: var(--cx-pink-lt); }
.ck-chip--wish i, .ck-chip--wish strong { color: var(--cx-pink-lt); }

/* --- layout --------------------------------------------------------------- */
.cart-layout { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 26px; align-items: start; }

/* --- the two big panels --------------------------------------------------- */
/* style.css forces .summary-card (and .cart-item/.wishlist-item below) to a
   white card under [data-theme="light"] with !important; customer-dark.css
   counters that at the same specificity. Matching it here - and coming later
   in source order - is what makes these the rules that land. */
.cart-items,
body .cart-page .summary-card,
.wishlist-section {
  padding: 26px;
  border: 1px solid var(--cx-line) !important;
  border-radius: 20px;
  background: var(--cx-panel) !important;
  box-shadow: 0 24px 60px rgba(3, 8, 16, .45) !important;
}
.cart-summary { position: sticky; top: 20px; height: fit-content; background: none; border: none; box-shadow: none; }

/* --- cart items panel head ------------------------------------------------ */
.cart-items-header {
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 14px;
  margin-bottom: 22px; padding-bottom: 18px;
  border-bottom: 1px solid var(--cx-line);
}
.cart-items-header h2 {
  margin: 0;
  font-family: var(--cx-font); font-size: 19px; font-weight: 800;
  letter-spacing: -.01em; color: #fff;
}
.cart-items-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }

/* --- the pill control set ------------------------------------------------- */
/* One shape for every secondary control on the page, coloured by role. */
.clear-cart-btn,
.wishlist-toggle-btn,
.select-all-label,
.wishlist-hide-btn,
.continue-shopping-btn,
.cart-bundle-remove-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 9px 16px;
  border: 1px solid var(--cx-line) !important;
  border-radius: 999px;
  background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04) !important;
  box-shadow: none !important;
  color: var(--cx-ink-dim);
  font-family: var(--cx-mono);
  font-size: 10px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
  text-decoration: none; cursor: pointer; white-space: nowrap;
  transition: background .18s ease, border-color .18s ease, color .18s ease, transform .18s var(--cx-ease);
}
.clear-cart-btn:hover,
.wishlist-toggle-btn:hover,
.select-all-label:hover,
.wishlist-hide-btn:hover,
.continue-shopping-btn:hover,
.cart-bundle-remove-btn:hover {
  transform: translateY(-1px);
  background: rgba(103, 232, 249, .12) !important;
  border-color: rgba(103, 232, 249, .45) !important;
  color: #fff;
}
.clear-cart-btn:active,
.wishlist-toggle-btn:active,
.select-all-label:active,
.continue-shopping-btn:active { transform: scale(.97); }

/* destructive and wishlist controls keep their own hue */
.clear-cart-btn { color: var(--cx-pink-lt); }
.clear-cart-btn:hover { background: rgba(224, 86, 122, .16) !important; border-color: rgba(224, 86, 122, .5) !important; color: #fff; }
.wishlist-toggle-btn { color: var(--cx-pink-lt); }
.wishlist-toggle-btn:hover { background: rgba(224, 86, 122, .16) !important; border-color: rgba(224, 86, 122, .5) !important; color: #fff; }
/* toggleWishlistPanel() toggles .active on this button - it now reads as a
   pressed pill rather than a neumorphic inset. */
.wishlist-toggle-btn.active {
  background: linear-gradient(120deg, rgba(224, 86, 122, .34), rgba(194, 24, 91, .26)) !important;
  border-color: rgba(224, 86, 122, .6) !important;
  color: #fff;
}
.select-all-label { user-select: none; }
.select-all-label input { width: 15px; height: 15px; cursor: pointer; accent-color: var(--cx-cyan); }
.select-all-label input:checked + span { color: #fff; }
.cart-bundle-remove-btn { padding: 7px 14px; font-size: 9.5px; }

.cart-items-footer { display: flex; justify-content: center; margin-top: 22px; }
.continue-shopping-btn { padding: 12px 24px; }

/* --- a cart row ----------------------------------------------------------- */
.items-list { display: flex; flex-direction: column; gap: 12px; }
body .cart-page .cart-item {
  position: relative; overflow: hidden;
  display: grid; grid-template-columns: 28px 92px minmax(0, 1fr) auto;
  gap: 18px; align-items: center;
  padding: 16px 18px;
  border: 1px solid var(--cx-line) !important;
  border-radius: 16px;
  background: var(--cx-panel-soft) !important;
  box-shadow: none !important;
  transition: transform .2s var(--cx-ease), border-color .2s ease,
              background .2s ease, box-shadow .2s ease;
}
body .cart-page .cart-item:hover {
  transform: translateY(-2px);
  border-color: var(--cx-line-hi) !important;
  box-shadow: 0 16px 36px rgba(3, 8, 16, .45) !important;
}
/* .selected is applied by the row click handler and by #selectAll; it now
   reads as a lit row with a cyan edge instead of a pressed neumorphic well. */
body .cart-page .cart-item.selected {
  border-color: rgba(103, 232, 249, .55) !important;
  background: linear-gradient(120deg, rgba(103, 232, 249, .12), rgba(103, 232, 249, .04)) !important;
  box-shadow: 0 0 0 1px rgba(103, 232, 249, .18), 0 16px 36px rgba(3, 8, 16, .45) !important;
}
body .cart-page .cart-item.selected::before {
  content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
  background: var(--cx-cyan);
}
.item-select { display: flex; align-items: center; justify-content: center; }
.item-select .select-item { width: 17px; height: 17px; cursor: pointer; accent-color: var(--cx-cyan); }

/* image well: a dark plate with a faint inner rim, as on the products grid */
body .cart-page .item-image {
  width: 92px; height: 92px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid rgba(var(--tw-line-rgb, 120, 170, 230), .12);
  border-radius: 14px; overflow: hidden;
  background:
    radial-gradient(70% 70% at 30% 20%, rgba(103, 232, 249, .10), transparent 70%),
    rgba(6, 13, 24, .65) !important;
  box-shadow: inset 0 1px 0 rgba(var(--tw-veil-rgb, 255, 255, 255), .05) !important;
}
body .cart-page .item-image img { max-width: 86%; max-height: 86%; object-fit: contain; }
.image-placeholder { font-family: var(--cx-mono); font-size: 10px; letter-spacing: .14em; color: var(--cx-ink-label); }

.item-details { display: flex; flex-direction: column; justify-content: center; gap: 6px; min-width: 0; }
.item-name { margin: 0; }
.item-name a {
  display: block; margin: 0;
  font-family: var(--cx-font); font-size: 16px; font-weight: 700;
  letter-spacing: -.01em; color: #fff; text-decoration: none;
  transition: color .18s ease;
}
.item-name a:hover { color: var(--cx-cyan); }
/* The price text is parsed by updateItemSubtotal() - restyled, never
   reworded. */
.item-price { margin: 0; font-family: var(--cx-mono); font-size: 12px; letter-spacing: .04em; color: var(--cx-ink-faint); }
.item-stock { margin: 0; font-family: var(--cx-mono); font-size: 10px; letter-spacing: .16em; text-transform: uppercase; color: var(--cx-ink-label); }
.item-stock strong { color: var(--cx-green); }

.item-controls { display: flex; flex-direction: column; align-items: flex-end; gap: 12px; min-width: 210px; }

/* stepper */
.quantity-control {
  display: flex; align-items: center; overflow: hidden;
  border: 1px solid var(--cx-line);
  border-radius: 999px;
  background: rgba(6, 13, 24, .6);
  box-shadow: none;
}
.qty-btn {
  width: 34px; height: 34px;
  display: flex; align-items: center; justify-content: center;
  border: none; background: transparent; color: var(--cx-ink-dim);
  cursor: pointer;
  transition: background .18s ease, color .18s ease, transform .12s var(--cx-ease);
}
.qty-btn:hover:not(:disabled) { background: rgba(103, 232, 249, .16); color: #fff; }
.qty-btn:active { transform: scale(.9); }
.qty-btn:disabled { opacity: .35; cursor: not-allowed; }
.qty-btn.minus { border-right: 1px solid var(--cx-line); }
.qty-btn.plus { border-left: 1px solid var(--cx-line); }
.qty-btn i { display: none; }
.qty-btn span { display: inline-block; font-weight: 700; font-size: 16px; line-height: 1; }
.qty-input {
  width: 52px; height: 34px;
  border: none; background: transparent;
  font-family: var(--cx-mono); font-size: 13px; font-weight: 700;
  color: #fff; text-align: center;
}
/* Chrome/Safari draw spinners that clip inside a 34px pill. */
.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.qty-input { -moz-appearance: textfield; appearance: textfield; }

/* Its text is parsed by updateSummary() - restyled, never reformatted. */
.item-subtotal {
  min-width: 118px; text-align: right;
  font-family: var(--cx-font); font-size: 18px; font-weight: 800;
  letter-spacing: -.01em; color: #fff;
}

.remove-item-btn {
  width: 34px; height: 34px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid transparent; border-radius: 50%;
  background: transparent; color: #8fa4bf; cursor: pointer;
  transition: background .18s ease, color .18s ease, border-color .18s ease, transform .12s var(--cx-ease);
}
.remove-item-btn:hover { background: rgba(224, 86, 122, .16); border-color: rgba(224, 86, 122, .45); color: var(--cx-pink-lt); }
.remove-item-btn:active { transform: scale(.9); }

/* --- Undo bar ------------------------------------------------------------
   Fixed above the floating cart button so the two never overlap. Dark values
   here; theme-light.css carries the light ones. */
.cart-undo-bar {
  position: fixed;
  left: 50%;
  transform: translateX(-50%);
  bottom: 28px;
  z-index: 1200;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 16px 12px 20px;
  border-radius: 14px;
  background: rgba(11, 22, 38, .96);
  border: 1px solid rgba(120, 170, 230, .22);
  box-shadow: 0 18px 42px rgba(3, 8, 16, .5);
  font-family: var(--cx-mono);
  font-size: 11px;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #cfe0f5;
}
.cart-undo-btn {
  border: 1px solid rgba(224, 86, 122, .45);
  background: transparent;
  color: var(--cx-pink-lt);
  font-family: inherit;
  font-size: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  font-weight: 700;
  padding: 7px 14px;
  border-radius: 999px;
  cursor: pointer;
  transition: background .18s ease, color .18s ease, border-color .18s ease;
}
.cart-undo-btn:hover { background: rgba(224, 86, 122, .16); color: #fff; }
@media (prefers-reduced-motion: reduce) { .cart-undo-bar { transition: none; } }

/* --- AI-build bundle group ------------------------------------------------ */
.cart-bundle-group {
  padding: 16px;
  border: 1px solid rgba(127, 106, 224, .38);
  border-radius: 18px;
  background: linear-gradient(140deg, rgba(127, 106, 224, .12), rgba(103, 232, 249, .05));
}
.cart-bundle-header {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  cursor: pointer; user-select: none;
  font-family: var(--cx-font); font-size: 14px; font-weight: 700; color: #c7bdf6;
}
.cart-bundle-title { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.cart-bundle-select { display: inline-flex; align-items: center; gap: 6px; margin-right: 10px;
  font-size: 12px; font-weight: 600; cursor: pointer; user-select: none; white-space: nowrap; }
.cart-bundle-select input { width: 15px; height: 15px; cursor: pointer; accent-color: var(--cx-cyan); }
.cart-bundle-header i { margin-right: 2px; }
.cart-bundle-chevron { transition: transform .2s ease; }
.cart-bundle-group.expanded .cart-bundle-chevron { transform: rotate(180deg); }
.cart-bundle-count {
  font-family: var(--cx-mono); font-size: 10px; font-weight: 600;
  letter-spacing: .14em; text-transform: uppercase; color: var(--cx-ink-faint);
}
.cart-bundle-remove-btn { color: #c7bdf6; }
.cart-bundle-remove-btn:hover { background: rgba(127, 106, 224, .26) !important; border-color: rgba(127, 106, 224, .7) !important; color: #fff; }
.cart-bundle-items { display: none; flex-direction: column; gap: 12px; margin-top: 16px; }
.cart-bundle-group.expanded .cart-bundle-items { display: flex; }

/* --- order summary -------------------------------------------------------- */
body .cart-page .summary-card { padding: 26px; }
.summary-card h3 {
  margin: 0 0 20px;
  font-family: var(--cx-font); font-size: 19px; font-weight: 800;
  letter-spacing: -.01em; color: #fff;
}
.summary-line {
  display: flex; justify-content: space-between; align-items: center; gap: 12px;
  margin-bottom: 13px;
  font-family: var(--cx-mono); font-size: 11px; letter-spacing: .1em;
  text-transform: uppercase; color: var(--cx-ink-faint);
}
.summary-line .cart-subtotal,
.summary-line .tax-amount { font-size: 13px; font-weight: 600; color: var(--cx-ink-dim); text-transform: none; letter-spacing: .02em; }
.summary-items { display: flex; flex-direction: column; gap: 10px; }
.summary-items:empty { display: none; }
.summary-item {
  display: flex; justify-content: space-between; align-items: baseline; gap: 12px;
  font-size: 12.5px; line-height: 1.35; color: var(--cx-ink-dim);
}
.summary-item .si-name {
  min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
/* The multiplier is the part people scan for, so it keeps the mono face the
   rest of the card's figures use. */
.summary-item .si-qty { font-family: var(--cx-mono); font-size: 10.5px; color: var(--cx-ink-faint); }
.summary-item .si-amount { font-family: var(--cx-mono); font-size: 12px; white-space: nowrap; color: var(--cx-ink-dim); }
.summary-empty {
  margin: 0; font-size: 12px; line-height: 1.5; color: var(--cx-ink-faint);
}
.summary-empty[hidden] { display: none; }
.summary-note {
  display: flex; align-items: flex-start; gap: 7px;
  margin: 12px 0 0; font-size: 11.5px; line-height: 1.5; color: var(--cx-ink-faint);
}
.summary-note i { margin-top: 1px; color: var(--cx-pink-lt); }
.summary-divider {
  height: 1px; margin: 18px 0;
  background: linear-gradient(90deg, rgba(120, 170, 230, .28), rgba(var(--tw-line-rgb, 120, 170, 230), 0));
}
.total-line { margin-bottom: 24px; color: #fff; }
.total-line span:first-child { font-size: 11px; letter-spacing: .18em; color: var(--cx-ink-faint); }
/* The one number the page is really about. */
.total-line .cart-total {
  font-family: var(--cx-font); font-size: 26px; font-weight: 800;
  letter-spacing: -.02em; text-transform: none;
  background: linear-gradient(100deg, var(--cx-pink-lt), var(--cx-cyan));
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.checkout-actions { display: flex; flex-direction: column; }
.checkout-btn {
  position: relative; overflow: hidden;
  display: flex; align-items: center; justify-content: center; gap: 10px;
  padding: 16px 20px; margin-bottom: 0;
  border: none; border-radius: 999px;
  background: linear-gradient(120deg, var(--cx-pink), #c2185b);
  color: #fff; text-decoration: none;
  font-family: var(--cx-mono); font-size: 11px; font-weight: 700;
  letter-spacing: .16em; text-transform: uppercase;
  box-shadow: 0 12px 30px rgba(224, 86, 122, .32);
  transition: transform .2s var(--cx-ease), box-shadow .2s ease, background .2s ease;
}
.checkout-btn:hover {
  transform: translateY(-2px);
  background: linear-gradient(120deg, var(--cx-pink-lt), var(--cx-pink));
  box-shadow: 0 18px 38px rgba(224, 86, 122, .45);
}
.checkout-btn:active { transform: scale(.98); }

/* --- wishlist ------------------------------------------------------------- */
.wishlist-panel { margin-bottom: 22px; }
.wishlist-section { margin-bottom: 26px; }
.wishlist-section-header { margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--cx-line); }
.wishlist-section-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.wishlist-section-header h2 {
  margin: 0; display: flex; align-items: center; gap: 10px;
  font-family: var(--cx-font); font-size: 19px; font-weight: 800;
  letter-spacing: -.01em; color: #fff;
}
.wishlist-section-header h2 i { color: var(--cx-pink-lt); }
.wishlist-count { font-family: var(--cx-mono); font-size: 11px; font-weight: 600; letter-spacing: .14em; color: var(--cx-ink-faint); }
.wishlist-section-header p { margin: 0; font-size: 13px; line-height: 1.6; color: var(--cx-ink-faint); }
.wishlist-hide-btn { padding: 7px 14px; font-size: 9.5px; }
.wishlist-items-list { display: flex; flex-direction: column; gap: 10px; }
body .cart-page .wishlist-item {
  display: grid; grid-template-columns: 76px minmax(0, 1fr) auto;
  gap: 18px; align-items: center;
  padding: 14px 18px;
  border: 1px solid var(--cx-line) !important;
  border-radius: 16px;
  background: var(--cx-panel-soft) !important;
  box-shadow: none !important;
  opacity: 1;
  transition: border-color .2s ease, background .2s ease;
}
body .cart-page .wishlist-item:hover { border-color: rgba(224, 86, 122, .34) !important; }
body .cart-page .wishlist-item .item-image { width: 76px; height: 76px; border-radius: 12px; }
body .cart-page .wishlist-item .item-image img { filter: grayscale(.55) brightness(.85); }
.wishlist-item .item-details { gap: 5px; }
.wishlist-item .item-name a { font-size: 15px; }
.wishlist-item .item-price { font-size: 12px; }
/* The out-of-stock flag reads as a badge now, matching the status pills on
   the orders page. */
.out-of-stock-badge {
  display: inline-flex; align-items: center; gap: 6px;
  margin: 2px 0; padding: 4px 10px;
  border: 1px solid rgba(224, 86, 122, .45); border-radius: 999px;
  background: rgba(224, 86, 122, .14);
  font-family: var(--cx-mono); font-size: 9px; font-weight: 600;
  letter-spacing: .16em; text-transform: uppercase; color: var(--cx-pink-lt);
  width: fit-content;
}
.item-desired-qty { margin: 0; font-family: var(--cx-mono); font-size: 10px; letter-spacing: .14em; text-transform: uppercase; color: var(--cx-ink-label); }
.item-desired-qty strong { color: var(--cx-ink-dim); }
.wishlist-item .item-controls { flex-direction: row; align-items: center; min-width: 0; }

/* --- notices and the empty state ------------------------------------------ */
.cart-empty-instock-note {
  margin-bottom: 26px; padding: 18px 22px;
  border: 1px solid rgba(252, 211, 77, .34); border-radius: 16px;
  background: rgba(252, 211, 77, .09);
  color: var(--cx-amber); font-size: 14px; line-height: 1.6;
}
.cart-empty-instock-note p { margin: 0; }

.empty-cart {
  max-width: 560px; margin: 10px auto 0; padding: 70px 40px;
  text-align: center;
  border: 1px solid var(--cx-line) !important;
  border-radius: 22px;
  background: var(--cx-panel) !important;
  box-shadow: 0 24px 60px rgba(3, 8, 16, .5) !important;
}
.empty-cart-icon {
  width: 92px; height: 92px; margin: 0 auto 26px;
  display: grid; place-items: center; border-radius: 50%;
  border: 1px solid rgba(224, 86, 122, .35);
  background: radial-gradient(circle at 30% 25%, rgba(224, 86, 122, .28), rgba(103, 232, 249, .10) 70%);
}
.empty-cart-icon i { font-size: 38px; color: var(--cx-pink-lt); margin: 0; }
.empty-cart h2 { margin-bottom: 12px; font-family: var(--cx-font); font-size: 26px; font-weight: 800; letter-spacing: -.02em; color: #fff; }
.empty-cart p { margin-bottom: 28px; color: var(--cx-ink-dim); font-size: 15px; line-height: 1.7; }
.empty-cart .btn-primary,
.btn-primary {
  display: inline-block; padding: 14px 30px;
  border: none; border-radius: 999px;
  background: linear-gradient(120deg, var(--cx-pink), #c2185b);
  color: #fff; text-decoration: none;
  font-family: var(--cx-mono); font-size: 11px; font-weight: 700;
  letter-spacing: .16em; text-transform: uppercase;
  box-shadow: 0 10px 26px rgba(224, 86, 122, .32);
  transition: transform .2s var(--cx-ease), box-shadow .2s ease, background .2s ease;
}
.btn-primary:hover {
  transform: translateY(-2px);
  background: linear-gradient(120deg, var(--cx-pink-lt), var(--cx-pink));
  box-shadow: 0 16px 34px rgba(224, 86, 122, .45);
}
.btn-primary:active { transform: scale(.97); }

/* --- entrance ------------------------------------------------------------- */
/* Rows are added and removed by script, so the animation is declared on the
   element rather than driven by a stagger variable - a row inserted later
   still arrives the same way. */
body .cart-page .cart-item,
body .cart-page .wishlist-item { animation: cx-rise .45s var(--cx-ease) backwards; }

/* --- narrow screens ------------------------------------------------------- */
@media (max-width: 1000px) {
  .cart-layout { grid-template-columns: 1fr; gap: 20px; }
  .cart-summary { order: -1; position: static; }
}
@media (max-width: 768px) {
  body .cart-page .cart-item {
    grid-template-columns: 28px 72px minmax(0, 1fr);
    grid-template-rows: auto auto;
    gap: 14px;
  }
  body .cart-page .item-image { width: 72px; height: 72px; }
  .item-controls {
    grid-column: 1 / -1;
    flex-direction: row; justify-content: space-between; align-items: center;
    min-width: 0;
  }
  .item-subtotal { min-width: 0; }
  .cart-items-header { flex-direction: column; align-items: stretch; }
  .cart-items-actions { justify-content: space-between; }
  body .cart-page .wishlist-item { grid-template-columns: 64px minmax(0, 1fr); grid-template-rows: auto auto; }
  body .cart-page .wishlist-item .item-image { width: 64px; height: 64px; }
  .wishlist-item .item-controls { grid-column: 1 / -1; justify-content: flex-end; }
  .cart-items, body .cart-page .summary-card, .wishlist-section { padding: 20px; }
}

@media (prefers-reduced-motion: reduce) {
  body .cart-page .cart-item,
  body .cart-page .wishlist-item { animation: none; }
  body .cart-page .cart-item:hover { transform: none; }
  .checkout-btn:hover, .btn-primary:hover { transform: none; }
}
</style>

<script>
// Lightweight client-side currency formatter to match PHP formatCurrency
function formatCurrency(amount){
  try{
    return new Intl.NumberFormat('en-PH',{ style:'currency', currency:'PHP', minimumFractionDigits:2 }).format(Number(amount||0));
  }catch(e){
    // Fallback
    const num = (Number(amount||0)).toFixed(2);
    return '₱'+num.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
}

function updateQuantity(productId, change){
  const input = document.querySelector(`[data-product-id="${productId}"] .qty-input`);
  if(!input) return;
  const curr = parseInt(input.value)||0;
  const stock = parseInt(document.querySelector(`[data-product-id="${productId}"]`).getAttribute('data-stock'))||0;
  let next = curr + change;
  
  // For minus button: allow going to 0 (which will delete the item)
  if(next < 0) next = 0;
  // For plus button: maximum quantity is stock limit
  if(stock > 0 && next > stock) next = stock;
  
  // If quantity becomes 0, remove the item
  if(next === 0) {
    removeFromCart(productId);
    return;
  }
  
  // Update input value immediately for better UX
  input.value = next;
  
  // Update the item subtotal immediately
  updateItemSubtotal(productId, next);
  
  // Update cart display
  cartRefreshSummary();
  
  // Update button states
  toggleQtyButtons(productId);
  
  // Send to server
  updateCartQuantity(productId,next);
}
function updateQuantityDirect(productId, val) { 
  let next = Math.max(0, parseInt(val) || 0); // Allow 0 quantity (will delete item)
  const stock = parseInt(document.querySelector(`[data-product-id="${productId}"]`).getAttribute('data-stock')) || 0;
  const finalQty = (stock > 0 && next > stock) ? stock : next;
  
  // If quantity becomes 0, remove the item
  if(finalQty === 0) {
    removeFromCart(productId);
    return;
  }
  
  // Update input value to the final quantity
  const input = document.querySelector(`[data-product-id="${productId}"] .qty-input`);
  if (input) input.value = finalQty;
  
  // Update the item subtotal immediately
  updateItemSubtotal(productId, finalQty);
  
  // Update cart display
  cartRefreshSummary();
  
  // Update button states
  toggleQtyButtons(productId);
  
  // Send to server
  updateCartQuantity(productId, finalQty); 
}
function updateCartQuantity(productId,quantity){
  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'update',product_id:productId,quantity})})
  .then(r=>r.json()).then(d=>{ if(d.success){ if(quantity===0){ const row=document.querySelector(`[data-product-id="${productId}"]`); const bundleGroup=row?row.closest('.cart-bundle-group'):null; if(row){ row.remove(); } if(bundleGroup){ updateBundleGroupSubtotal(bundleGroup); } if(document.querySelectorAll('.cart-item').length===0){ location.reload(); return; } } else { const inputEl=document.querySelector(`[data-product-id="${productId}"] .qty-input`); if(inputEl){ inputEl.value=quantity; } }
      // Rows, header chips and the summary card all come off the DOM, which
      // this handler has already put in sync with the server.
      //
      // d.cart_total is deliberately NOT used for the summary card: it is the
      // total of the WHOLE cart, so writing it here replaced the selected
      // subtotal with every item's, dropped VAT and Total onto that wrong
      // base, and - because the old call passed no count - reset the label to
      // "Subtotal (0 items)" on every +/- press. The chips are where a
      // whole-cart figure belongs, and cartRefreshSummary() computes those.
    cartRefreshSummary();
    toggleQtyButtons(productId);
      if(typeof updateCartCount==='function'){ updateCartCount(d.cart_count); }
      if(typeof showNotification==='function'){ showNotification(d.message,'success'); }
  } else { showNotification(d.message,'error'); } })
  .catch(()=>showNotification('An error occurred','error'));
}
/* ---- Remove, with a 10-second undo -------------------------------------
 *
 * Nothing is sent to the server while the window is open. The row is DETACHED
 * from the DOM and held, along with where it sat, so undo can put it back
 * exactly - and so every existing selector (cartRefreshSummary, syncSelectAll,
 * the empty-cart check) keeps working untouched, which flagging the row in
 * place would have broken.
 *
 * If the window elapses, the same request the old code sent goes out. If the
 * page is being closed first, it goes out immediately with keepalive so the
 * delete is never silently lost.
 */
var CART_UNDO_MS = 10000;
var cartPending = {};        /* productId -> { node, parent, next, wasSelected, timer, fallback } */

function cartPendingCount(){ return Object.keys(cartPending).length; }

async function removeFromCart(productId, skipConfirm){
  if(cartPending[productId]) return;            /* already on its way out */
  if(!skipConfirm && !(await uiConfirm('Remove this item from cart?'))) return;

  const row = document.querySelector(`[data-product-id="${productId}"]`);
  if(!row) return;
  const btn = row.querySelector('.remove-item-btn');
  const bundleGroup = row.closest('.cart-bundle-group');

  cartPending[productId] = {
    node: row,
    parent: row.parentNode,
    next: row.nextElementSibling,
    wasSelected: row.classList.contains('selected'),
    bundleGroup: bundleGroup,
    fallback: btn ? btn.getAttribute('data-fallback') : null,
    timer: setTimeout(function(){ cartCommitRemove(productId); }, CART_UNDO_MS)
  };

  row.remove();
  if(bundleGroup){ updateBundleGroupSubtotal(bundleGroup); }
  /* The tick goes with the row - otherwise the id stays in the stored
     selection and re-selects itself if the product comes back. */
  if(typeof setCartSelection === 'function'){ setCartSelection(productId, false); }
  cartRefreshSummary();
  syncSelectAll();
  syncBundleBoxes();
  updateCartCount();
  cartShowUndoBar();
}

/* Put it back. No server call was ever made, so this is purely local. */
function cartUndoRemove(productId){
  const e = cartPending[productId];
  if(!e) return;
  clearTimeout(e.timer);
  delete cartPending[productId];

  if(e.next && e.next.parentNode === e.parent){ e.parent.insertBefore(e.node, e.next); }
  else { e.parent.appendChild(e.node); }

  if(e.wasSelected && typeof applySelection === 'function'){ applySelection(e.node, true); }
  if(e.bundleGroup){ updateBundleGroupSubtotal(e.bundleGroup); }
  cartRefreshSummary();
  syncSelectAll();
  syncBundleBoxes();
  updateCartCount();
  cartShowUndoBar();
  showNotification('Item restored to your cart','success');
}

function cartUndoAll(){ Object.keys(cartPending).forEach(cartUndoRemove); }

/* The window elapsed (or the page is closing): actually delete it. */
function cartCommitRemove(productId, viaBeacon){
  const e = cartPending[productId];
  if(!e) return;
  clearTimeout(e.timer);
  delete cartPending[productId];

  const opts = {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body: JSON.stringify({ action:'remove', product_id: productId })
  };
  /* keepalive, not sendBeacon: the endpoint expects the X-Requested-With
     header and sendBeacon cannot set one. */
  if(viaBeacon){ opts.keepalive = true; }

  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php', opts)
    .then(r=>r.json())
    .then(d=>{
      if(d && d.success){
        if(typeof d.cart_count === 'number') updateCartCount(d.cart_count); else updateCartCount();
        /* Only now is the cart really empty - reloading earlier would have
           thrown away the undo before the window closed. */
        if(!cartPendingCount() && document.querySelectorAll('.cart-item').length===0){ location.reload(); }
      } else {
        /* The server refused. Put the row back rather than showing a cart
           that disagrees with the session. */
        cartPending[productId] = e;
        cartUndoRemove(productId);
        showNotification((d && d.message) || 'Failed to remove from cart','error');
      }
    })
    .catch(()=>{
      if(viaBeacon) return;                      /* page is going away anyway */
      cartPending[productId] = e;
      cartUndoRemove(productId);
      showNotification('An error occurred','error');
    })
    .finally(cartShowUndoBar);
}

/* One bar for however many are pending, with a live countdown. */
function cartShowUndoBar(){
  let bar = document.getElementById('cartUndoBar');
  const n = cartPendingCount();
  if(!n){ if(bar) bar.remove(); return; }

  if(!bar){
    bar = document.createElement('div');
    bar.id = 'cartUndoBar';
    bar.className = 'cart-undo-bar';
    bar.setAttribute('role','status');
    bar.innerHTML = '<span class="cart-undo-text"></span>'
      + '<button type="button" class="cart-undo-btn">Undo</button>';
    bar.querySelector('.cart-undo-btn').addEventListener('click', cartUndoAll);
    document.body.appendChild(bar);
  }
  const started = Date.now();
  const text = bar.querySelector('.cart-undo-text');
  clearInterval(bar._tick);
  function paint(){
    const left = Math.max(0, Math.ceil((CART_UNDO_MS - (Date.now() - started)) / 1000));
    const count = cartPendingCount();
    if(!count){ clearInterval(bar._tick); bar.remove(); return; }
    text.textContent = (count === 1 ? 'Item removed' : count + ' items removed') + ' · ' + left + 's';
  }
  paint();
  bar._tick = setInterval(paint, 250);
}

/* Closing the page must not lose the delete. */
window.addEventListener('pagehide', function(){
  Object.keys(cartPending).forEach(function(id){ cartCommitRemove(id, true); });
});
document.addEventListener('visibilitychange', function(){
  if(document.visibilityState === 'hidden'){
    Object.keys(cartPending).forEach(function(id){ cartCommitRemove(id, true); });
  }
});
function toggleBundleGroup(headerEl){
  const group = headerEl.closest('.cart-bundle-group');
  if(!group) return;
  group.classList.toggle('expanded');
}
async function removeBundle(bundleId){
  const group = document.querySelector(`.cart-bundle-group[data-bundle-id="${bundleId}"]`);
  if(!group) return;
  const ids = Array.from(group.querySelectorAll('.cart-item')).map(el => parseInt(el.getAttribute('data-product-id'))).filter(Number.isInteger);
  if(ids.length === 0) return;
  if(!(await uiConfirm(`Remove this entire bundle (${ids.length} item${ids.length === 1 ? '' : 's'}) from cart?`))) return;
  // Each removeFromCart call is its own request - skipConfirm avoids
  // popping a confirmation dialog per item since we already confirmed once
  // for the whole bundle above.
  ids.forEach(id => removeFromCart(id, true));
}
async function clearCart(){
  if(!(await uiConfirm('Clear your entire cart?'))) return;
  const btn = document.querySelector('.clear-cart-btn');
  const fallback = btn ? btn.getAttribute('data-fallback') : null;
  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'clear'})})
    .then(r=>r.json()).then(d=>{ if(d.success){ location.reload(); } else { if(fallback){ window.location.href = fallback; } else { showNotification(d.message,'error'); } } })
    .catch(()=>{ if(fallback){ window.location.href = fallback; } else { showNotification('An error occurred','error'); } });
}
function toggleWishlistPanel(){
  const panel = document.getElementById('wishlistPanel');
  const btn = document.getElementById('wishlistToggleBtn');
  if(!panel) return;
  const isHidden = panel.style.display === 'none' || getComputedStyle(panel).display === 'none';
  panel.style.display = isHidden ? 'block' : 'none';
  if(btn) btn.classList.toggle('active', isHidden);
}
// Update individual item subtotal
function updateItemSubtotal(productId, quantity) {
  const row = document.querySelector(`[data-product-id="${productId}"]`);
  if (!row) return;

  const priceText = row.querySelector('.item-price')?.textContent || '';
  const price = parseFloat(priceText.replace(/[^\d.]/g, '')) || 0;
  const itemSubtotal = quantity * price;
  const subtotalEl = row.querySelector('.item-subtotal');

  if (subtotalEl) {
    subtotalEl.textContent = formatCurrency(itemSubtotal);
  }

  updateBundleGroupSubtotal(row.closest('.cart-bundle-group'));
}

// Recomputes one bundle group's header (item count + subtotal) from
// whatever .cart-item rows are still inside it - called after any removal
// or quantity change to a bundled product, so "AI Build: X (N items ·
// amount)" always reflects the products that actually remain, priced off
// each row's own current subtotal rather than a stale server-rendered sum.
// A group that's been emptied out entirely is removed instead of showing
// "0 items".
function updateBundleGroupSubtotal(groupEl) {
  if (!groupEl) return;
  const items = groupEl.querySelectorAll('.cart-item');
  if (items.length === 0) {
    groupEl.remove();
    return;
  }
  let subtotal = 0;
  items.forEach(item => {
    const subEl = item.querySelector('.item-subtotal');
    if (subEl) { subtotal += parseFloat(subEl.textContent.replace(/[^\d.]/g, '')) || 0; }
  });
  const countEl = groupEl.querySelector('.cart-bundle-count');
  if (countEl) {
    countEl.textContent = `${items.length} item${items.length === 1 ? '' : 's'} · ${formatCurrency(subtotal)}`;
  }
}

// RENAMED from updateCartDisplay().
//
// main.js:663 declares its own `function updateCartDisplay(count)` - the cart
// BADGE updater - and main.js is loaded with `defer`. Deferred scripts run
// after the document is parsed, so it executed AFTER this inline block and
// silently replaced this function on `window`: both are plain function
// declarations, so the later one simply wins.
//
// Every call here then landed in the badge updater, which takes a count and
// only touches .cart-count elements. The Order Summary was never
// recalculated - ticking a checkbox toggled the row's class and changed
// nothing else, and quantity edits behaved the same way.
//
// The page-specific name means the collision cannot come back.
function cartRefreshSummary(){ 
  let subtotal = 0; 
  let selectedCount = 0;
  // Whole-cart quantity for the header chip, which answers a different
  // question than the summary card: what is in the cart, vs what you are
  // about to pay for.
  let cartQty = 0;
  const picked = [];   // what the summary card lists
  document.querySelectorAll('.cart-item').forEach(item => { 
    const selected = item.classList.contains('selected'); 
    const qty = parseInt(item.querySelector('.qty-input')?.value) || 0; 
    const priceText = item.querySelector('.item-price')?.textContent || '';
    const price = parseFloat(priceText.replace(/[^\d.]/g, '')) || 0; 
    const itemSubtotal = qty * price; 
    const st = item.querySelector('.item-subtotal'); 
    if (st) { 
      st.textContent = formatCurrency(itemSubtotal); 
    } 
    cartQty += qty;
    if (selected) { 
      subtotal += itemSubtotal; 
      selectedCount++;
      const nameEl = item.querySelector('.item-name');
      picked.push({
        name: nameEl ? nameEl.textContent.trim() : 'Item',
        qty: qty,
        amount: itemSubtotal
      });
    } 
  }); 
  updateCartChips(cartQty);
  renderSummaryItems(picked);
  updateSummaryTotals(subtotal, selectedCount); 
}
// Names what the card is totalling. Rebuilt from scratch each time rather
// than patched: the list is short, and reconciling rows against a previous
// render is how the counts and the figures drift apart.
function renderSummaryItems(picked){
  const list = document.getElementById('cartSummaryItems');
  const empty = document.getElementById('cartSummaryEmpty');
  if(!list) return;
  list.textContent = '';
  picked.forEach(entry => {
    const row = document.createElement('div');
    row.className = 'summary-item';

    const name = document.createElement('span');
    name.className = 'si-name';
    // textContent, not innerHTML - product names come from the database and
    // are escaped by PHP on the way in, so building nodes keeps them escaped
    // on the way back out.
    name.textContent = entry.name;
    name.title = entry.name;

    const qty = document.createElement('span');
    qty.className = 'si-qty';
    qty.textContent = ` x${entry.qty}`;
    name.appendChild(qty);

    const amount = document.createElement('span');
    amount.className = 'si-amount';
    amount.textContent = formatCurrency(entry.amount);

    row.appendChild(name);
    row.appendChild(amount);
    list.appendChild(row);
  });
  if(empty){ empty.hidden = picked.length > 0; }
}
// The "Total items" chip above the list. PHP renders it once, so without this
// it still showed the figure the page loaded with after a quantity change or
// a removal.
//
// The "Total amount" chip that used to sit beside it has been removed: the
// Order Summary is now the only place on the page that states an amount, and
// it states the SELECTED one, so two different totals can no longer sit here
// disagreeing with each other.
function updateCartChips(itemCount){
  const countEl = document.querySelector('.ck-meta .cx-chip:not(.ck-chip--wish) strong');
  if(countEl){ countEl.textContent = itemCount; }
}
// Single source of truth for the Order Summary card. Only cartRefreshSummary()
// calls it, and it always passes the SELECTED subtotal - anything that writes
// a different number here (the server's whole-cart total, say) makes the card
// disagree with both the ticked rows and the checkout page.
function updateSummaryTotals(subtotal, selectedCount = 0){
  const sub=document.querySelector('.cart-subtotal');
  const tot=document.querySelector('.cart-total');
  const taxEl=document.querySelector('.tax-amount');
  // Addressed by id: this used to take the first `.summary-line span` in the
  // document, which is the label only for as long as nothing else on the page
  // uses that class.
  const subtotalLine = document.getElementById('cartSubtotalLabel');

  // PH standard 12% VAT is added on top of the item subtotal - the same
  // rounding checkout.php's checkoutRecalcTotals() and phVatAdd() use, so the
  // two pages cannot drift by a centavo.
  const vat = Math.round(subtotal * 0.12 * 100) / 100;
  const total = Math.round((subtotal + vat) * 100) / 100;

  if(sub){ sub.textContent = formatCurrency(subtotal); }
  if(taxEl){ taxEl.textContent = formatCurrency(vat); }
  if(tot){ tot.textContent = formatCurrency(total); }
  if(subtotalLine){ subtotalLine.textContent = `Subtotal (${selectedCount} item${selectedCount === 1 ? '' : 's'})`; }
}
function toggleQtyButtons(productId){
  const row = document.querySelector(`[data-product-id="${productId}"]`);
  if(!row) return;
  const qty = parseInt(row.querySelector('.qty-input')?.value)||0;
  const stock = parseInt(row.getAttribute('data-stock'))||0;
  const minusBtn = row.querySelector('.qty-btn.minus');
  const plusBtn = row.querySelector('.qty-btn.plus');
  
  if(minusBtn) {
    // Minus button is always enabled (can go from 1 to 0 to delete item)
    minusBtn.disabled = false;
    minusBtn.style.opacity = '1';
  }
  if(plusBtn) {
    plusBtn.disabled = (stock > 0) ? qty >= stock : false;
    plusBtn.style.opacity = ((stock > 0) ? qty >= stock : false) ? '0.5' : '1';
  }
}

// Selection wiring, bound at parse time rather than inside DOMContentLoaded.
//
// Everything below used to live at the END of one DOMContentLoaded callback
// that began by restoring the stored selection. Anything that threw before it
// reached the bottom - and the restore step touches localStorage, JSON and a
// helper defined in footer.php, none of which this page controls - took the
// handlers down with it. The page then looked exactly like a working one
// except that ticking a box did nothing at all: no row highlight, and an
// Order Summary frozen at "Subtotal (0 items) / P0.00" however many items you
// ticked, because nothing was listening to recompute it.
//
// Delegated from `document` so it does not depend on the rows existing yet,
// and so rows added or replaced later (a bundle group re-render, a removal)
// stay live without rebinding. Registered before any restore work runs, so a
// failure there can no longer cost the page its interactivity.
document.addEventListener('click', function(e){
  const row = e.target.closest ? e.target.closest('.cart-item') : null;
  if(!row) return;
  const target = e.target;
  // The checkbox fires its own change event; the quantity controls, the remove
  // button and the product link all mean something other than "select".
  if(target.classList.contains('select-item')) return;
  if(target.closest('.item-controls') || target.closest('.qty-input') ||
     target.closest('a') || target.closest('button')) return;
  applySelection(row, !row.classList.contains('selected'));
});

// Returning to the cart with the back button restores it from the browser's
// back/forward cache: the DOM comes back exactly as it was left and
// DOMContentLoaded never fires again, so nothing recomputes. If the cart was
// changed elsewhere in between - another tab, the products page - the card
// sits there stating a total for a cart that no longer looks like that, and
// only a manual refresh corrects it. event.persisted is true only for that
// restore, so a normal load still goes through DOMContentLoaded alone.
window.addEventListener('pageshow', function(e){
  if(!e.persisted) return;
  syncSelectAll();
  cartRefreshSummary();
});

// Called straight from the checkbox's onchange attribute. Bound by the parser,
// so this path works even if every addEventListener on the page failed.
function onSelectItem(cb){
  const row = cb && cb.closest ? cb.closest('.cart-item') : null;
  if(!row) return;
  applySelection(row, !!cb.checked);
}

// Called straight from #selectAll's onchange attribute, same reason.
function onSelectAll(box){
  const checked = !!(box && box.checked);
  document.querySelectorAll('.cart-item').forEach(row => applySelection(row, checked, true));
  cartRefreshSummary();
  syncSelectAll();
  syncBundleBoxes();
}

// Called straight from a bundle header checkbox's onchange attribute.
// Selects or clears every row of that one build.
function onSelectBundle(box){
  const group = box && box.closest ? box.closest('.cart-bundle-group') : null;
  if(!group) return;
  const checked = !!box.checked;
  group.querySelectorAll('.cart-item').forEach(row => applySelection(row, checked, true));
  cartRefreshSummary();          // once, after the loop - not per row
  syncSelectAll();
  syncBundleBoxes();
}

// Reflects each build's rows back onto its header checkbox. `indeterminate` is
// the point: with some rows ticked the box shows a dash rather than claiming
// the whole build is either in or out.
function syncBundleBoxes(){
  document.querySelectorAll('.cart-bundle-group').forEach(group => {
    const box = group.querySelector('.cart-bundle-select-box');
    if(!box) return;
    const rows = Array.from(group.querySelectorAll('.cart-item'));
    const on = rows.filter(r => r.classList.contains('selected')).length;
    box.checked = rows.length > 0 && on === rows.length;
    box.indeterminate = on > 0 && on < rows.length;
  });
}

// The one place a row's selected state is written, so the class, the checkbox,
// the stored set and the Order Summary can never disagree.
function applySelection(row, isSelected, deferRefresh){
  row.classList.toggle('selected', isSelected);
  const cb = row.querySelector('.select-item');
  if(cb) cb.checked = isSelected;
  const pid = parseInt(row.getAttribute('data-product-id'));
  if(pid && typeof setCartSelection === 'function'){
    try{ setCartSelection(pid, isSelected); }catch(err){}
  }
  if(deferRefresh) return;      // Select All refreshes once, after the loop
  cartRefreshSummary();
  syncSelectAll();
  syncBundleBoxes();
}

function syncSelectAll(){
  const selectAll = document.getElementById('selectAll');
  if(!selectAll) return;
  const all = Array.from(document.querySelectorAll('.cart-item'));
  selectAll.checked = all.length > 0 && all.every(x => x.classList.contains('selected'));
}

// Initialize qty button states on load
document.addEventListener('DOMContentLoaded', function(){
  // Init qty button states and cart display
  document.querySelectorAll('.cart-item').forEach(item=>{
    const pid = parseInt(item.getAttribute('data-product-id'));
    if(pid) toggleQtyButtons(pid);
  });

  // Pre-select items that were added to the cart via "Add to Cart" (or
  // previously left checked), so the customer doesn't have to re-select
  // them before checking out.
  //
  // With NOTHING stored - a cart restored from the database on a new device,
  // or after the browser cleared its storage - everything is selected
  // instead. The old behaviour left every row unticked, and since the Order
  // Summary totals the selection, the card sat at P0.00 on arrival and looked
  // broken until you happened to tick something. A cart you are looking at is
  // a cart you mean to buy; unticking is the deliberate act, not ticking.
  //
  // Wrapped: this reads localStorage through a helper from another file. If it
  // fails the cart still has to be usable, so we fall back to selecting
  // everything rather than leaving the page in a state the UI cannot reach -
  // a cart with rows in it and none ticked.
  try{
    const storedSelection = (typeof getCartSelection === 'function') ? getCartSelection() : new Set();

    // The stored set has to be reconciled against the cart BEFORE it decides
    // anything, because setCartSelection() only ever ADDS - on "Add to Cart" -
    // and nothing prunes it. Ids stay behind after a checkout, after the
    // server-side ?remove=/?clear=1 fallbacks, after a removal in another tab,
    // and after capCartQuantitiesByStock() moves a sold-out row into the
    // Wishlist, where it has no .cart-item and so can never be selected.
    //
    // Left unpruned, a set full of ids that are no longer in the cart is still
    // non-empty: `size === 0` was false, so the select-everything fallback
    // below never fired, while `storedSelection.has(pid)` matched nothing
    // either. The result was a cart with items in it and not one row ticked.
    //
    // Intersecting first makes "a stored selection that matches nothing"
    // behave exactly like "no stored selection", which is the case the
    // fallback was written for.
    const cartRows = Array.from(document.querySelectorAll('.cart-item'));
    const cartIds = new Set(
      cartRows.map(el => parseInt(el.getAttribute('data-product-id'))).filter(Boolean)
    );
    const liveSelection = new Set(Array.from(storedSelection).filter(id => cartIds.has(id)));

    // Forget the ids that no longer match a row, so the set stops growing and
    // this cannot creep back over time.
    if(typeof setCartSelection === 'function'){
      storedSelection.forEach(id => { if(!cartIds.has(id)){ setCartSelection(id, false); } });
    }

    const selectByDefault = liveSelection.size === 0;
    cartRows.forEach(row=>{
      const pid = parseInt(row.getAttribute('data-product-id'));
      applySelection(row, !!pid && (selectByDefault || liveSelection.has(pid)), true);
    });
  }catch(err){
    // Leave the server's all-selected render exactly as it is.
  }

  syncSelectAll();
  syncBundleBoxes();   // header boxes must match whatever selection was restored
  cartRefreshSummary();
});


// Build checkout URL with only selected product IDs
const checkoutBtn = document.getElementById('checkoutBtn');
if(checkoutBtn){
  checkoutBtn.addEventListener('click', function(e){
    // Collect selected product IDs
    const ids = Array.from(document.querySelectorAll('.cart-item.selected'))
      .map(row => parseInt(row.getAttribute('data-product-id')))
      .filter(id => Number.isInteger(id));
    if(ids.length === 0){
      e.preventDefault();
      if(typeof showNotification === 'function'){
        showNotification('Please select at least one item to checkout','error');
      } else {
        alert('Please select at least one item to checkout');
      }
      return;
    }
    // Navigate with selected ids as query param
    e.preventDefault();
    const base = this.getAttribute('href');
    const url = base + (base.includes('?') ? '&' : '?') + 'selected=' + encodeURIComponent(ids.join(','));
    window.location.href = url;
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

