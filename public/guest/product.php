<?php
// product.php - Individual product detail page
require_once __DIR__ . '/../../config/functions.php';

$productId = intval($_GET['id'] ?? 0);

if (!$productId) {
    header('Location: ' . WEB_ROOT . 'public/guest/products.php');
    exit;
}

$product = getProduct($productId);
// Real average rating + review count for the header stars (the lower
// "Customer Reviews" section already fetches the same data live via JS -
// this just makes the header consistent with it instead of the old
// order-count-based fake "4/5 stars for anything that's ever sold" display).
$productAvgRating = 0.0;
$productReviewCount = 0;
try {
    $pdo = getConnection();
    $ratingStmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as review_count FROM reviews WHERE product_id = ? AND is_approved = 1");
    $ratingStmt->execute([$productId]);
    $ratingRow = $ratingStmt->fetch(PDO::FETCH_ASSOC);
    $productReviewCount = (int)($ratingRow['review_count'] ?? 0);
    $productAvgRating = $productReviewCount > 0 ? round((float)$ratingRow['avg_rating'], 1) : 0.0;
} catch (Throwable $e) {
    $productAvgRating = 0.0;
    $productReviewCount = 0;
}

// Same two rules the backend enforces (api/reviews.php) - checked here too
// so the "Write a Review" button/form only appears when it would actually
// be allowed, instead of inviting a click that's just going to fail.
$customerHasPurchased = false;
$customerAlreadyReviewed = false;
if (function_exists('isLoggedIn') && isLoggedIn()) {
    try {
        $uid = getUserId();
        $pdo = getConnection();
        $purchStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'completed'");
        $purchStmt->execute([$uid, $productId]);
        $customerHasPurchased = $purchStmt->fetchColumn() > 0;

        $revStmt = $pdo->prepare("SELECT 1 FROM reviews WHERE user_id = ? AND product_id = ? LIMIT 1");
        $revStmt->execute([$uid, $productId]);
        $customerAlreadyReviewed = (bool)$revStmt->fetchColumn();
    } catch (Throwable $e) {
        $customerHasPurchased = false;
        $customerAlreadyReviewed = false;
    }
}

if (!$product) {
    header('Location: ' . WEB_ROOT . 'public/guest/products.php?error=product_not_found');
    exit;
}

$pageTitle = htmlspecialchars($product['name']) . ' - TechWorld';

// Similar products - scored on shared product_tags within the SAME category,
// so a motherboard only ever suggests motherboards. Replaces an ORDER BY
// RAND() pick, which was category-correct but otherwise arbitrary: it could
// put a PHP 20,000 LGA1700 board under a PHP 2,300 AM4 one.
// See config/product_recommendations.php.
require_once __DIR__ . '/../../config/product_recommendations.php';
$relatedProducts = [];
if ($product['category_id']) {
    try {
        $pdo = getConnection();
        $relatedProducts = getSimilarProducts($pdo, $product, 4);
    } catch (Throwable $e) {
        error_log("Error fetching related products: " . $e->getMessage());
    }
}

// The product's own tags, shown as a spec chip row under the description.
// Same rows that drive the ranking above, so what a shopper reads is exactly
// what the recommender matched on - no second, drifting source of truth.
$productTags = [];
try {
    $pdo = getConnection();
    $ptStmt = $pdo->prepare(
        'SELECT tag_key, tag_value FROM product_tags WHERE product_id = ? ORDER BY tag_key'
    );
    $ptStmt->execute([$productId]);
    $productTags = $ptStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Error fetching product tags: ' . $e->getMessage());
}

// Embedded in the customer page (customer/product.php), related cards must
// stay inside the authenticated area rather than bouncing a logged-in
// customer out to the guest detail page.
$relatedBase = defined('EMBEDDED_PRODUCT_DETAIL')
    ? WEB_ROOT . 'public/authenticated/customer/product.php'
    : WEB_ROOT . 'public/guest/product.php';

// No top header bar or nav bar on this page (same as the rest of the guest
// area) - emit only the shared assets the header used to load, plus the
// floating Login button. Skipped entirely when embedded (quick-view).
if (!defined('EMBEDDED_PRODUCT_DETAIL')):
?>
<script>document.documentElement.setAttribute('data-theme','light');</script>
<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>
<link rel="icon" type="image/x-icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
<script src="<?= asset_url('public/shared/assets/js/main.js') ?>"></script>

<!-- Floating Login / Sign In - same as guest/index.php & guest/products.php.
     openLoginModal() + the modal markup come from includes/footer.php. -->
<a class="guest-login-fab" href="<?= WEB_ROOT ?>public/guest/login.php" onclick="event.preventDefault(); openLoginModal();">
  <i class="fi fi-rr-sign-in-alt" aria-hidden="true"></i> Login / Sign In
</a>
<?php
endif;
?>


<!-- The page's own CSS, deliberately ahead of its markup: it used to sit
     after it, so the browser painted the whole page unstyled first. -->
<style>
/* =========================================================================
   Product detail page (.pdp)

   ABOVE the markup on purpose: this block used to sit after it, and the page
   painted once in the browser's default styling before these rules arrived.

   Every colour is a token on .pdp. The base values are the LIGHT ones, which
   is also what the standalone guest page needs - it sets data-theme="light"
   and no data-mode at all, so a dark-only or data-mode-only sheet would leave
   it unstyled. html[data-mode="light"] restates them for the customer area,
   html[data-mode="dark"] swaps them.

   The dark-on-dark bug this replaces: the old sheet hardcoded light colours
   (var(--text-primary) and friends) with no theme rules anywhere, so inside
   the customer area - which runs data-mode="dark" - the title, description,
   specs and "Product Features" were near-black text on a near-black ground.
   ====================================================================== */
.pdp {
  --pdp-page:   #f2f5fa;
  --pdp-card:   #ffffff;
  --pdp-border: #e3e9f2;
  --pdp-ink:    #16233a;
  --pdp-body:   #2b3a52;
  --pdp-muted:  #5d6f86;
  --pdp-line:   #eef2f7;
  --pdp-input:  #d3dbe6;
  --pdp-accent: #c23a63;      /* buttons and links */
  --pdp-accent-ink: #c23a63;  /* accent used as text */
  --pdp-star:   #a86f00;
  /* The same gold, one step down, for star glyphs set as 12-13px TEXT (the
     filter chips). #a86f00 measures 4.25:1 there - under the 4.5 bar. */
  --pdp-star-sm: #8a5b00;
  --pdp-plate:  #f4f7fb;      /* image wells on similar cards */
  --pdp-save-bg: #fdecf1;
  --pdp-save-fg: #a8264d;
  --pdp-ok:     #0f6b45;
  --pdp-oos-bg: #fdecef;
  --pdp-oos-fg: #a3263f;
  --pdp-chip-bg: #f2f5fa;

  --pdp-font: inherit;
  --pdp-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;

  background: var(--pdp-page);
  color: var(--pdp-body);
  /* Clears the mobile tab bar at the bottom of the customer area. */
  padding: 0 0 clamp(40px, 9vw, 96px);
}
html[data-mode="light"] .pdp {
  --pdp-page:   #f2f5fa;
  --pdp-card:   #ffffff;
  --pdp-border: #e3e9f2;
  --pdp-ink:    #16233a;
  --pdp-body:   #2b3a52;
  --pdp-muted:  #5d6f86;
  --pdp-line:   #eef2f7;
  --pdp-input:  #d3dbe6;
  --pdp-accent: #c23a63;
  --pdp-accent-ink: #c23a63;
  --pdp-star:   #a86f00;
  --pdp-star-sm: #8a5b00;
  --pdp-plate:  #f4f7fb;
  --pdp-save-bg: #fdecf1;
  --pdp-save-fg: #a8264d;
  --pdp-ok:     #0f6b45;
  --pdp-oos-bg: #fdecef;
  --pdp-oos-fg: #a3263f;
  --pdp-chip-bg: #f2f5fa;
}
html[data-mode="dark"] .pdp {
  --pdp-page:   #0a1424;
  --pdp-card:   #0f1c30;
  --pdp-border: #22344f;
  --pdp-ink:    #eef3fa;
  --pdp-body:   #d6e2f1;
  --pdp-muted:  #8ea3bd;
  --pdp-line:   #1a2a42;
  --pdp-input:  #2c4262;
  --pdp-accent: #d9355f;      /* buttons keep white text on this */
  --pdp-accent-ink: #ff9ab5;  /* the readable accent for TEXT on dark */
  --pdp-star:   #fcd34d;
  --pdp-star-sm: #fcd34d;
  --pdp-plate:  #142338;
  --pdp-save-bg: rgba(224, 86, 122, .16);
  --pdp-save-fg: #ffc2d3;
  --pdp-ok:     #4ade80;
  --pdp-oos-bg: rgba(224, 86, 122, .14);
  --pdp-oos-fg: #ffb3c4;
  --pdp-chip-bg: #142338;
}

.pdp *, .pdp *::before, .pdp *::after { box-sizing: border-box; }
.pdp img { max-width: 100%; }
.pdp-wrap { max-width: 1340px; margin: 0 auto; padding: 0 20px; }

/* ---- breadcrumb ------------------------------------------------------- */
.pdp-breadcrumb { padding: 18px 0 2px; }
.pdp-breadcrumb .container,
.pdp-breadcrumb .pdp-wrap { display: flex; flex-wrap: wrap; gap: 7px; align-items: center; }
.pdp-breadcrumb a,
.pdp-breadcrumb span { font-size: 13px; color: var(--pdp-muted); text-decoration: none; }
.pdp-breadcrumb a:hover { color: var(--pdp-accent-ink); text-decoration: underline; }
.pdp-breadcrumb .pdp-crumb-current { color: var(--pdp-ink); font-weight: 600; }

/* ---- 1. top ----------------------------------------------------------- */
.pdp-top {
  display: grid;
  grid-template-columns: 1.05fr 1fr;
  gap: 40px;
  padding: 22px 0 10px;
  align-items: start;
}

.pdp-gallery { position: relative; }
.pdp-stage {
  position: relative;
  aspect-ratio: 1 / 1;
  background: var(--pdp-card);
  border: 1px solid var(--pdp-border);
  border-radius: 20px;
  padding: 20px;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.pdp-stage img { width: 100%; height: 100%; object-fit: contain; display: block; }
.pdp-stage .pdp-noimg {
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  color: var(--pdp-muted); font-size: 14px;
}
.pdp-stage .pdp-noimg i { font-size: 34px; }
/* Discount chip. Rendered only when a real previous price exists - see the
   $pdpOldPrice slot in the PHP above, which is null until the catalogue
   actually carries one. */
.pdp-discount {
  position: absolute; top: 14px; left: 14px; z-index: 2;
  background: #c23a63; color: #fff;
  font-size: 13px; font-weight: 800; letter-spacing: .01em;
  padding: 7px 12px; border-radius: 999px;
}

/* ---- right column ----------------------------------------------------- */
.pdp-eyebrow {
  font-family: var(--pdp-mono);
  font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
  color: var(--pdp-muted); margin: 0 0 10px;
}
.pdp .pdp-title {
  margin: 0 0 12px;
  font-size: 32px; font-weight: 800; line-height: 1.18; letter-spacing: -.02em;
  color: var(--pdp-ink);
  text-wrap: balance;
}
.pdp-ratingrow {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  margin: 0 0 18px; font-size: 14px; color: var(--pdp-muted);
}
.pdp-ratingrow .stars { display: inline-flex; gap: 2px; }
.pdp-ratingrow .stars i { color: var(--pdp-star); font-size: 15px; }
.pdp-rating-value { color: var(--pdp-ink); font-weight: 700; }
.pdp-seereviews { color: var(--pdp-accent-ink); font-weight: 600; text-decoration: none; }
.pdp-seereviews:hover { text-decoration: underline; }

/* ---- buy card --------------------------------------------------------- */
.pdp-buy {
  background: var(--pdp-card);
  border: 1px solid var(--pdp-border);
  border-radius: 18px;
  padding: 22px;
}
.pdp-priceline { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.pdp-price {
  font-size: 36px; font-weight: 900; line-height: 1;
  color: var(--pdp-ink); font-variant-numeric: tabular-nums;
}
.pdp-oldprice {
  font-size: 17px; color: var(--pdp-muted); text-decoration: line-through;
  font-variant-numeric: tabular-nums;
}
.pdp-save {
  font-size: 12.5px; font-weight: 800;
  padding: 5px 10px; border-radius: 999px;
  background: var(--pdp-save-bg); color: var(--pdp-save-fg);
}
.pdp-vat { margin: 10px 0 0; font-size: 13px; color: var(--pdp-muted); }

.pdp-stockline {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  margin: 16px 0 0; font-size: 14px; font-weight: 600; color: var(--pdp-body);
}
.pdp-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--pdp-ok); flex: 0 0 auto; }
.pdp-oos-strip {
  margin: 16px 0 0; padding: 12px 14px; border-radius: 12px;
  background: var(--pdp-oos-bg); color: var(--pdp-oos-fg);
  font-size: 13.5px; line-height: 1.5;
}
.pdp-oos-strip strong { display: block; font-size: 14.5px; margin-bottom: 2px; }

/* One row on desktop - qty, Add to cart, Buy now, wishlist. nowrap because
   at this card width the heart otherwise wrapped to a line of its own; the
   phone layout below re-wraps it deliberately. */
.pdp-actions { display: flex; gap: 10px; align-items: stretch; margin: 16px 0 0; flex-wrap: nowrap; }
.pdp-actions .pdp-btn { min-width: 0; }
.pdp-qty {
  display: flex; align-items: center;
  width: 132px; height: 52px; flex: 0 0 auto;
  border: 1px solid var(--pdp-input); border-radius: 12px;
  background: var(--pdp-card); overflow: hidden;
}
.pdp-qty button {
  width: 42px; height: 100%; border: 0; background: transparent; cursor: pointer;
  color: var(--pdp-ink); font-size: 19px; font-weight: 700; line-height: 1;
}
.pdp-qty button:hover { background: var(--pdp-line); }
.pdp-qty input {
  width: 48px; height: 100%; border: 0; background: transparent; text-align: center;
  color: var(--pdp-ink); font: inherit; font-size: 16px; font-weight: 700;
  font-variant-numeric: tabular-nums;
  -moz-appearance: textfield;
}
.pdp-qty input::-webkit-outer-spin-button,
.pdp-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

.pdp-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  height: 52px; padding: 0 14px; border-radius: 12px;
  font: inherit; font-size: 15px; font-weight: 700; cursor: pointer;
  border: 1px solid transparent; text-decoration: none;
  /* The label is two words; letting it wrap inside a 52px button cropped it. */
  white-space: nowrap;
}
.pdp-btn-cart {
  flex: 1 1 120px;
  background: transparent; border-color: var(--pdp-accent); color: var(--pdp-accent-ink);
}
.pdp-btn-cart:hover { background: var(--pdp-save-bg); }
.pdp-btn-buy {
  flex: 1 1 120px;
  background: linear-gradient(135deg, #d9355f, #6b5bc4); color: #fff;
}
.pdp-btn-buy:hover { filter: brightness(1.06); }
.pdp-btn-wish {
  width: 52px; flex: 0 0 52px; padding: 0;
  background: transparent; border-color: var(--pdp-input); color: var(--pdp-accent-ink);
  font-size: 18px;
}
.pdp-btn-wish:hover { border-color: var(--pdp-accent); background: var(--pdp-save-bg); }
.pdp-btn-wide { flex: 1 1 200px; background: var(--pdp-accent); color: #fff; }
.pdp-btn-wide:hover { filter: brightness(1.06); }
.pdp-btn-dead {
  flex: 1 1 160px; cursor: not-allowed;
  background: var(--pdp-line); color: var(--pdp-muted); border-color: var(--pdp-border);
}
html[data-mode="dark"] .pdp .pdp-btn-dead { background: #16263d; color: #93a7c0; }

.pdp-perks {
  display: flex; flex-wrap: wrap; gap: 14px 22px;
  margin: 18px 0 0; padding: 16px 0 0; border-top: 1px solid var(--pdp-line);
}
.pdp .pdp-perks .pdp-perk {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 0; border: 0; background: transparent; box-shadow: none;
  font: inherit; font-size: 13px; color: var(--pdp-body); cursor: pointer;
  min-height: 44px;
}
.pdp .pdp-perks .pdp-perk i { color: var(--pdp-accent-ink); font-size: 15px; }
.pdp .pdp-perks .pdp-perk.selected { color: var(--pdp-accent-ink); font-weight: 700; }

/* ---- spec tiles ------------------------------------------------------- */
.pdp-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 16px 0 0; }
.pdp-tile {
  background: var(--pdp-card); border: 1px solid var(--pdp-border);
  border-radius: 14px; padding: 13px 15px;
}
.pdp-tile-k {
  display: block; font-family: var(--pdp-mono);
  font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase;
  color: var(--pdp-muted); margin-bottom: 5px;
}
.pdp-tile-v { display: block; font-size: 15px; font-weight: 700; color: var(--pdp-ink); }

/* ---- 2. details row --------------------------------------------------- */
.pdp-details {
  display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px;
  padding: 34px 0 0;
}
.pdp-panel {
  background: var(--pdp-card); border: 1px solid var(--pdp-border);
  border-radius: 18px; padding: 22px;
}
.pdp-panel h2, .pdp-panel h3 {
  margin: 0 0 14px; font-size: 17px; font-weight: 800; color: var(--pdp-ink);
}
/* No tab JS exists on this page, so the sections stack with the same
   underlined heading treatment the tabs would have had. */
.pdp-sec + .pdp-sec { margin-top: 26px; }
.pdp .pdp-sec-title {
  display: inline-block; margin: 0 0 14px; padding-bottom: 8px;
  font-size: 15px; font-weight: 800; color: var(--pdp-ink);
  border-bottom: 2px solid var(--pdp-accent);
}
.pdp-spectable { margin: 0; }
.pdp .pdp-spectable > div {
  display: grid; grid-template-columns: 160px 1fr; gap: 14px;
  padding: 11px 0; border-bottom: 1px solid var(--pdp-line);
}
.pdp .pdp-spectable > div:last-child { border-bottom: 0; }
.pdp .pdp-spectable dt { font-size: 13.5px; font-weight: 500; color: var(--pdp-muted); }
.pdp .pdp-spectable dd { margin: 0; font-size: 14px; font-weight: 600; color: var(--pdp-ink); }
@media (max-width: 520px) {
  .pdp .pdp-spectable > div { grid-template-columns: 1fr; gap: 2px; }
}
.pdp .pdp-prose { margin: 0; font-size: 15px; line-height: 1.7; color: var(--pdp-body); }

.pdp-likes { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
.pdp-likes li { display: flex; align-items: flex-start; gap: 12px; font-size: 14.5px; color: var(--pdp-body); line-height: 1.5; }
.pdp-check {
  flex: 0 0 24px; width: 24px; height: 24px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  background: rgba(21, 127, 82, .12); color: var(--pdp-ok); font-size: 11px;
}
html[data-mode="dark"] .pdp .pdp-check { background: rgba(74, 222, 128, .16); }

/* ---- 3. similar ------------------------------------------------------- */
.pdp-similar { padding: 34px 0 0; }
.pdp-sec-head {
  display: flex; align-items: flex-end; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin: 0 0 16px;
}
.pdp-sec-head h2 { margin: 0; font-size: 24px; font-weight: 800; color: var(--pdp-ink); letter-spacing: -.01em; }
.pdp-viewall { font-size: 14px; font-weight: 600; color: var(--pdp-accent-ink); text-decoration: none; white-space: nowrap; }
.pdp-viewall:hover { text-decoration: underline; }

.pdp-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.pdp-card {
  background: var(--pdp-card); border: 1px solid var(--pdp-border);
  border-radius: 16px; padding: 10px;
  display: flex; flex-direction: column; gap: 9px;
}
.pdp-card-plate {
  aspect-ratio: 1 / 1; background: var(--pdp-plate); border-radius: 12px;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.pdp-card-plate img { width: 100%; height: 100%; object-fit: contain; }
.pdp-card-plate .pdp-noimg { color: var(--pdp-muted); font-size: 12px; }
.pdp .pdp-card-brand {
  font-family: var(--pdp-mono); font-size: 10px; font-weight: 600;
  letter-spacing: .12em; text-transform: uppercase; color: var(--pdp-muted);
}
.pdp-card-name { margin: 0; font-size: 15px; font-weight: 700; line-height: 1.35; }
.pdp .pdp-card-name a {
  color: var(--pdp-ink); text-decoration: none;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.pdp .pdp-card-name a:hover { color: var(--pdp-accent-ink); }
.pdp .pdp-card-price { font-size: 18px; font-weight: 800; color: var(--pdp-accent-ink); font-variant-numeric: tabular-nums; }
.pdp .pdp-card-why { font-size: 12px; line-height: 1.45; color: var(--pdp-muted); }

/* ---- 4. reviews ------------------------------------------------------- */
.pdp-reviews { padding: 34px 0 0; }
.pdp-reviews-grid { display: grid; grid-template-columns: 320px 1fr; gap: 20px; align-items: start; }

.pdp-summary { background: var(--pdp-card); border: 1px solid var(--pdp-border); border-radius: 18px; padding: 22px; }
.pdp-avg { display: flex; align-items: baseline; gap: 10px; }
.pdp-avg .rating-number { font-size: 48px; font-weight: 900; line-height: 1; color: var(--pdp-ink); font-variant-numeric: tabular-nums; }
.pdp-avg-out { font-size: 13px; color: var(--pdp-muted); }
.pdp-summary .stars { display: inline-flex; gap: 3px; margin: 10px 0 4px; }
.pdp-summary .stars i { color: var(--pdp-star); font-size: 16px; }
.pdp-summary .review-count { display: block; font-size: 13px; color: var(--pdp-muted); }

.pdp-bars { margin: 16px 0 0; display: flex; flex-direction: column; gap: 7px; }
.pdp-bar { display: grid; grid-template-columns: 38px 1fr 28px; align-items: center; gap: 9px; font-size: 12.5px; color: var(--pdp-body); }
.pdp-bar-track { height: 8px; border-radius: 999px; background: var(--pdp-line); overflow: hidden; }
.pdp-bar-fill { display: block; height: 100%; border-radius: 999px; background: var(--pdp-star); width: 0; }
.pdp-bar-n { text-align: right; font-variant-numeric: tabular-nums; }

.pdp-note {
  margin: 16px 0 0; padding: 12px 14px; border-radius: 12px;
  background: var(--pdp-chip-bg); border: 1px solid var(--pdp-border);
  font-size: 13px; line-height: 1.55; color: var(--pdp-body);
}
.pdp-summary .write-review-btn {
  display: block; width: 100%; margin: 16px 0 0; height: 46px;
  border: 0; border-radius: 12px; background: var(--pdp-accent); color: #fff;
  font: inherit; font-size: 15px; font-weight: 700; cursor: pointer;
}
.pdp-summary .write-review-btn:hover { filter: brightness(1.06); }

.pdp-filter { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 14px; }
.pdp-filter .review-filter-btn {
  height: 36px; padding: 0 14px; border-radius: 999px;
  border: 1px solid var(--pdp-border); background: var(--pdp-card);
  color: var(--pdp-body); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
}
.pdp-filter .review-filter-btn .rfb-star { color: var(--pdp-star-sm); }
.pdp-filter .review-filter-btn.active { background: var(--pdp-ink); border-color: var(--pdp-ink); color: #fff; }
.pdp-filter .review-filter-btn.active .rfb-star { color: #fff; }
html[data-mode="dark"] .pdp .pdp-filter .review-filter-btn.active { background: #d9355f; border-color: #d9355f; }

.pdp-reviews-list { display: flex; flex-direction: column; gap: 14px; }
.pdp .review-item {
  background: var(--pdp-card); border: 1px solid var(--pdp-border);
  border-radius: 16px; padding: 18px;
}
.pdp .review-head { display: flex; align-items: flex-start; gap: 12px; }
.pdp .review-avatar {
  flex: 0 0 44px; width: 44px; height: 44px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--pdp-chip-bg); border: 1px solid var(--pdp-border);
  color: var(--pdp-ink); font-size: 15px; font-weight: 800;
}
.pdp .reviewer-info { flex: 1; min-width: 0; }
.pdp .reviewer-name { font-size: 15px; font-weight: 800; color: var(--pdp-ink); display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pdp .verified-badge {
  font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
  background: rgba(21, 127, 82, .12); color: var(--pdp-ok); white-space: nowrap;
}
html[data-mode="dark"] .pdp .verified-badge { background: rgba(74, 222, 128, .16); }
.pdp .review-date { font-size: 12.5px; color: var(--pdp-muted); white-space: nowrap; }
.pdp .review-rating { display: inline-flex; gap: 2px; margin: 8px 0 0; }
.pdp .review-rating i, .pdp .review-item .stars i { color: var(--pdp-star); font-size: 14px; }
.pdp .review-title { margin: 10px 0 0; font-size: 15px; font-weight: 700; color: var(--pdp-ink); }
.pdp .review-comment { margin: 8px 0 0; font-size: 15px; line-height: 1.6; color: var(--pdp-body); overflow-wrap: anywhere; }

.pdp .review-photos { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0 0; }
.pdp .review-photo { width: 88px; height: 88px; border-radius: 12px; overflow: hidden; border: 1px solid var(--pdp-border); display: block; }
.pdp .review-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pdp .review-add-photo { margin: 10px 0 0; }
.pdp .review-add-photo-btn {
  min-height: 44px; padding: 0 14px; border-radius: 10px; cursor: pointer;
  border: 1px dashed var(--pdp-input); background: transparent;
  color: var(--pdp-accent-ink); font: inherit; font-size: 13px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
}
/* The reply used to carry its colours inline - a mauve that measured 3.4:1
   on the light card and 4.2:1 on the dark one. Tokens instead. */
.pdp .review-store-reply {
  margin: 12px 0 0; padding: 12px 14px; border-radius: 12px;
  background: var(--pdp-chip-bg); border-left: 3px solid var(--pdp-accent);
}
.pdp .review-store-reply .rsr-head {
  font-size: 12.5px; font-weight: 700; color: var(--pdp-accent-ink); margin-bottom: 5px;
}
.pdp .review-store-reply .rsr-when { font-weight: 500; color: var(--pdp-muted); }
.pdp .review-store-reply .rsr-body { font-size: 13.5px; line-height: 1.6; color: var(--pdp-body); }

.pdp .review-footer {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  flex-wrap: wrap; margin: 14px 0 0; padding: 12px 0 0; border-top: 1px solid var(--pdp-line);
}
.pdp .review-helpful { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pdp .review-helpful-q { font-size: 13px; color: var(--pdp-muted); }
.pdp .helpful-btn {
  min-height: 44px; padding: 0 14px; border-radius: 10px; cursor: pointer;
  border: 1px solid var(--pdp-border); background: var(--pdp-card);
  color: var(--pdp-body); font: inherit; font-size: 13px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 7px;
}
.pdp .helpful-btn:hover { border-color: var(--pdp-accent); color: var(--pdp-accent-ink); }
.pdp .no-reviews { padding: 26px; text-align: center; color: var(--pdp-muted); font-size: 14px; background: var(--pdp-card); border: 1px solid var(--pdp-border); border-radius: 16px; }
.pdp .review-gate-note { margin: 0; font-size: 13px; color: var(--pdp-body); }

/* ---- review form ------------------------------------------------------ */
.pdp .review-form {
  margin: 18px 0 0; padding: 22px;
  background: var(--pdp-card); border: 1px solid var(--pdp-border); border-radius: 18px;
}
.pdp .review-form h3 { margin: 0 0 16px; font-size: 17px; font-weight: 800; color: var(--pdp-ink); }
.pdp .review-form label { display: block; margin: 0 0 6px; font-size: 13px; font-weight: 600; color: var(--pdp-body); }
.pdp .review-form input[type="text"],
.pdp .review-form textarea {
  width: 100%; padding: 11px 13px; border-radius: 12px;
  border: 1px solid var(--pdp-input); background: var(--pdp-page); color: var(--pdp-ink);
  font: inherit; font-size: 14px;
}
.pdp .review-form input[type="text"]:focus,
.pdp .review-form textarea:focus { outline: 2px solid var(--pdp-accent); outline-offset: 1px; }
.pdp .rating-input, .pdp .title-input, .pdp .comment-input, .pdp .review-photo-input { margin: 0 0 16px; }
.pdp .star-rating { display: inline-flex; gap: 6px; cursor: pointer; }
.pdp .star-rating i { color: var(--pdp-star); font-size: 24px; }
.pdp .review-photo-hint { margin: 7px 0 0; font-size: 12px; color: var(--pdp-muted); }
.pdp .review-photo-preview-grid { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0 0; }
.pdp .review-photo-preview { position: relative; width: 72px; height: 72px; border-radius: 10px; overflow: hidden; border: 1px solid var(--pdp-border); }
.pdp .review-photo-preview img { width: 100%; height: 100%; object-fit: cover; }
.pdp .review-photo-remove { position: absolute; top: 2px; right: 2px; width: 22px; height: 22px; border-radius: 50%; border: 0; background: rgba(0,0,0,.6); color: #fff; cursor: pointer; font-size: 12px; line-height: 1; }
.pdp .review-form-error { margin: 0 0 14px; padding: 11px 13px; border-radius: 12px; background: var(--pdp-oos-bg); color: var(--pdp-oos-fg); font-size: 13.5px; }
.pdp .form-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.pdp .submit-review-btn, .pdp .cancel-btn {
  min-height: 46px; padding: 0 20px; border-radius: 12px; cursor: pointer;
  font: inherit; font-size: 15px; font-weight: 700; border: 1px solid transparent;
}
.pdp .submit-review-btn { background: var(--pdp-accent); color: #fff; }
.pdp .cancel-btn { background: transparent; border-color: var(--pdp-input); color: var(--pdp-body); }

/* ---- 5. responsive ---------------------------------------------------- */
@media (max-width: 1100px) {
  .pdp-top { grid-template-columns: 1fr; gap: 26px; }
  .pdp-details { grid-template-columns: 1fr; }
  .pdp-grid { grid-template-columns: repeat(3, 1fr); }
  .pdp-stage { aspect-ratio: 4 / 3; }
  .pdp-reviews-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .pdp-wrap { padding: 0 16px; }
  .pdp-title { font-size: 26px; }
  .pdp-price { font-size: 30px; }
  .pdp-grid { grid-template-columns: repeat(2, 1fr); }
  /* qty + wishlist on one row, Add to cart + Buy now on the next */
  .pdp-actions { gap: 10px; flex-wrap: wrap; }
  .pdp-qty { flex: 1 1 auto; width: auto; min-width: 132px; }
  .pdp-btn-cart, .pdp-btn-buy, .pdp-btn-wide, .pdp-btn-dead { flex: 1 1 100%; }
  .pdp-btn-wish { order: 2; }
  .pdp-btn-cart { order: 3; }
  .pdp-btn-buy { order: 4; }
  .pdp .review-footer { align-items: flex-start; }

  /* 44px tap targets on a phone. These are inline text links, so the box is
     grown with padding rather than by moving the text: the layout looks the
     same, the thumb gets something to hit. */
  /* Horizontal padding with a matching negative margin: the hit box grows
     past 44px in both axes, the words stay exactly where they were. */
  .pdp-breadcrumb a { padding: 13px 8px; margin: 0 -8px; line-height: 1.4; }
  .pdp-breadcrumb span { padding: 13px 0; line-height: 1.4; }
  .pdp-seereviews, .pdp-viewall {
    display: inline-flex; align-items: center; min-height: 44px;
  }
  .pdp .pdp-card-name a { padding: 3px 0; }
}
@media (prefers-reduced-motion: reduce) {
  .pdp * { transition: none !important; }
}
</style>

<style>
/* Floating Login / Sign In - fixed top-right, mirrors guest/index.php and
   guest/products.php. */
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
</style>

<?php
/* ---- view helpers for the markup below ---------------------------------
   Presentation only: these read the data already loaded above and decide
   what the page is allowed to show. Nothing here queries anything. */

// Tag lookup by key, so the eyebrow and the spec tiles can ask for a
// specific attribute instead of guessing at array positions.
$pdpTagMap = [];
foreach ($productTags as $pt) { $pdpTagMap[$pt['tag_key']] = $pt['tag_value']; }

$pdpBrand  = $pdpTagMap['brand'] ?? '';
$pdpScreen = $pdpTagMap['screen_size'] ?? '';
$pdpCat    = $product['category_name'] ?? '';

// Eyebrow: "LAPTOPS · LENOVO" - only the parts that exist.
$pdpEyebrow = array_values(array_filter([
    $pdpCat !== '' ? mb_strtoupper($pdpCat) : '',
    $pdpBrand !== '' ? mb_strtoupper(tagValueLabel($pdpBrand, 'brand')) : '',
]));

/* The previous price, when the catalogue carries one. It does not today:
   `products` has a single `price` column and no sale/compare-at price, so
   this stays null and the discount chip, struck price and "Save" chip are
   not rendered at all. The markup for all three is kept below, gated on this
   variable, so the day a real old price exists they appear with no redesign.

   What used to be here: mt_rand(0,1) picked whether to show a discount, the
   "old" price was price * 1.25, and the badge always read "20% OFF" - a sale
   that changed on every reload and had no number behind it. */
$pdpOldPrice = null;
$pdpSave     = ($pdpOldPrice !== null && $pdpOldPrice > (float)$product['price'])
    ? $pdpOldPrice - (float)$product['price'] : null;
$pdpPercent  = ($pdpSave !== null && $pdpOldPrice > 0)
    ? (int)round(($pdpSave / $pdpOldPrice) * 100) : null;

$pdpStock = (int)$product['stock'];
$pdpInStock = $pdpStock > 0;
?>

<div class="pdp">
<div class="pdp-wrap">

<?php if (!defined('EMBEDDED_PRODUCT_DETAIL')): ?>
  <nav class="pdp-breadcrumb" aria-label="Breadcrumb">
    <div class="pdp-wrap" style="padding:0;">
      <a href="<?= WEB_ROOT ?>public/guest/index.php">Home</a><span>/</span>
      <a href="<?= WEB_ROOT ?>public/guest/products.php">Products</a>
      <?php if ($pdpCat): ?>
        <span>/</span>
        <a href="<?= WEB_ROOT ?>public/guest/products.php?category_id=<?= (int)$product['category_id'] ?>"><?= htmlspecialchars($pdpCat) ?></a>
      <?php endif; ?>
      <span>/</span><span class="pdp-crumb-current"><?= htmlspecialchars($product['name']) ?></span>
    </div>
  </nav>
<?php endif; ?>

  <!-- ================= 1. TOP ================= -->
  <div class="pdp-top">

    <div class="pdp-gallery">
      <?php /* One image per product is all the catalogue has (products.image_url),
               so there is no thumbnail rail: a rail of one thumb is a control
               with nothing to switch to. #mainProductImage is kept - it is the
               id the rest of the page's script addresses. */ ?>
      <div class="pdp-stage">
        <?php if ($pdpPercent !== null): ?>
          <span class="pdp-discount">&minus;<?= $pdpPercent ?>%</span>
        <?php endif; ?>
        <?php if ($product['image_url']): ?>
          <img src="<?= WEB_ROOT . htmlspecialchars($product['image_url']) ?>"
               alt="<?= htmlspecialchars($product['name']) ?>" id="mainProductImage">
        <?php else: ?>
          <div class="pdp-noimg"><i class="fi fi-rs-picture"></i><span>No image</span></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="pdp-buycol">
      <?php if ($pdpEyebrow): ?>
        <p class="pdp-eyebrow"><?= htmlspecialchars(implode(' · ', $pdpEyebrow)) ?></p>
      <?php endif; ?>

      <h1 class="pdp-title"><?= htmlspecialchars($product['name']) ?></h1>

      <div class="pdp-ratingrow">
        <span class="stars">
          <?php
            $productFilledStars = $productReviewCount > 0 ? (int)round($productAvgRating) : 0;
            for ($i = 1; $i <= 5; $i++) {
                echo $i <= $productFilledStars ? '<i class="fi fi-ss-star"></i>' : '<i class="fi fi-rs-star"></i>';
            }
          ?>
        </span>
        <?php if ($productReviewCount > 0): ?>
          <span class="pdp-rating-value"><?= number_format($productAvgRating, 1) ?></span>
          <span>&middot; <?= (int)$productReviewCount ?> <?= $productReviewCount === 1 ? 'review' : 'reviews' ?></span>
        <?php else: ?>
          <span>No reviews yet</span>
        <?php endif; ?>
        <a href="#reviews" class="pdp-seereviews">See reviews &darr;</a>
      </div>

      <!-- buy card -->
      <div class="pdp-buy">
        <div class="pdp-priceline">
          <?php if ((float)$product['price'] > 0): ?>
            <span class="pdp-price"><?= formatCurrency($product['price']) ?></span>
          <?php else: ?>
            <span class="pdp-price">&#8369; &mdash;</span>
          <?php endif; ?>
          <?php if ($pdpOldPrice !== null): ?>
            <span class="pdp-oldprice"><?= formatCurrency($pdpOldPrice) ?></span>
          <?php endif; ?>
          <?php if ($pdpSave !== null): ?>
            <span class="pdp-save">Save <?= formatCurrency($pdpSave) ?></span>
          <?php endif; ?>
        </div>
        <p class="pdp-vat">VAT added at checkout &middot; Prices in PHP</p>

        <?php if ($pdpInStock): ?>
          <p class="pdp-stockline"><span class="pdp-dot" aria-hidden="true"></span>
            In stock &middot; <?= $pdpStock ?> left</p>

          <div class="pdp-actions">
            <div class="pdp-qty">
              <button type="button" onclick="changeQuantity(-1)" aria-label="Decrease quantity">&minus;</button>
              <input type="number" id="quantity" value="1" min="1" max="<?= max(1, $pdpStock) ?>" aria-label="Quantity">
              <button type="button" onclick="changeQuantity(1)" aria-label="Increase quantity">+</button>
            </div>
            <?php /* addToCartFromDetail() has existed in this file since the
                     page was written (it reads #quantity and calls the shared
                     addToCart) but nothing ever called it - there was no Add
                     to Cart button in the markup, only Buy Now. Same function,
                     untouched; it finally has its button. */ ?>
            <button type="button" class="pdp-btn pdp-btn-cart add-to-cart-btn" onclick="addToCartFromDetail()">
              <i class="fi fi-rs-shopping-cart-add" aria-hidden="true"></i> Add to cart
            </button>
            <button type="button" class="pdp-btn pdp-btn-buy buy-now-btn" onclick="buyNowFromDetail()">Buy now</button>
            <button type="button" class="pdp-btn pdp-btn-wish" onclick="notifyWhenAvailable()"
                    aria-label="Add to wishlist" title="Add to wishlist">
              <i class="fi fi-rs-heart" aria-hidden="true"></i>
            </button>
          </div>
        <?php else: ?>
          <div class="pdp-oos-strip">
            <strong>Out of stock</strong>
            Save it to your wishlist and check back soon.
          </div>
          <div class="pdp-actions">
            <button type="button" class="pdp-btn pdp-btn-dead" disabled>Unavailable</button>
            <button type="button" class="pdp-btn pdp-btn-wide" onclick="notifyWhenAvailable()">
              <i class="fi fi-rs-heart" aria-hidden="true"></i> Add to wishlist
            </button>
          </div>
        <?php endif; ?>

        <?php /* The perks are the two fulfilment options the store actually
                 offers, and they are still the real selectPaymentMethod()
                 controls rather than decoration. A warranty perk is not here:
                 nothing in the catalogue records one. */ ?>
        <div class="pdp-perks">
          <button type="button" class="pdp-perk payment-option clickable" onclick="selectPaymentMethod('cod')">
            <i class="fi fi-rs-truck-side" aria-hidden="true"></i><span>Home delivery (COD)</span>
          </button>
          <button type="button" class="pdp-perk payment-option clickable" onclick="selectPaymentMethod('store')">
            <i class="fi fi-rs-shop" aria-hidden="true"></i><span>Store pickup</span>
          </button>
        </div>
      </div>

      <?php /* Spec tiles: whatever product_tags actually holds for this
               product, up to four. Most carry two or three. */ ?>
      <?php $pdpTiles = array_slice($productTags, 0, 4); ?>
      <?php if ($pdpTiles): ?>
        <div class="pdp-tiles">
          <?php foreach ($pdpTiles as $pt): ?>
            <div class="pdp-tile">
              <span class="pdp-tile-k"><?= htmlspecialchars(tagKeyLabel($pt['tag_key'])) ?></span>
              <span class="pdp-tile-v"><?= htmlspecialchars(tagValueLabel($pt['tag_value'], $pt['tag_key'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ================= 2. DETAILS ================= -->
  <div class="pdp-details">
    <div class="pdp-panel">
      <?php /* Stacked sections, not tabs: there is no tab script on this page
               and inventing one would be new JS. The underline that would have
               marked the active tab marks each section head instead. */ ?>
      <?php if ($productTags): ?>
      <section class="pdp-sec">
        <h2 class="pdp-sec-title">Specifications</h2>
        <?php /* A <dl>, not a <table>, and for one reason: shared/style.css
                 carries `[data-theme="light"] table td, table th { color:#0f172a
                 !important }`. The customer area runs data-theme="light" for
                 that sheet while painting itself dark, so every cell in a real
                 table came out near-black on a near-black card - the same
                 dark-on-dark bug this redesign is here to kill - and the only
                 way to win against an !important is another one. A definition
                 list is the honest markup for label/value pairs anyway. */ ?>
        <dl class="pdp-spectable">
          <?php foreach ($productTags as $pt): ?>
            <div>
              <dt><?= htmlspecialchars(tagKeyLabel($pt['tag_key'])) ?></dt>
              <dd><?= htmlspecialchars(tagValueLabel($pt['tag_value'], $pt['tag_key'])) ?></dd>
            </div>
          <?php endforeach; ?>
          <?php if ($pdpCat): ?>
            <div><dt>Category</dt><dd><?= htmlspecialchars($pdpCat) ?></dd></div>
          <?php endif; ?>
          <div><dt>Availability</dt><dd><?= $pdpInStock ? $pdpStock . ' in stock' : 'Out of stock' ?></dd></div>
        </dl>
      </section>
      <?php endif; ?>

      <section class="pdp-sec">
        <h2 class="pdp-sec-title">Description</h2>
        <p class="pdp-prose"><?= nl2br(htmlspecialchars($product['description'] ?: 'No description available.')) ?></p>
      </section>

      <section class="pdp-sec">
        <h2 class="pdp-sec-title">Shipping &amp; returns</h2>
        <p class="pdp-prose">
          Delivered to your address with cash on delivery, or reserved for pickup at the
          store. Track every order from My Orders; a delivery is confirmed with a photo
          at handover.
        </p>
      </section>
    </div>

    <div class="pdp-panel">
      <h2>Why you'll like it</h2>
      <ul class="pdp-likes">
        <li><span class="pdp-check"><i class="fi fi-ss-check"></i></span> High-quality components</li>
        <li><span class="pdp-check"><i class="fi fi-ss-check"></i></span> 1-year warranty</li>
        <li><span class="pdp-check"><i class="fi fi-ss-check"></i></span> Free technical support</li>
        <li><span class="pdp-check"><i class="fi fi-ss-check"></i></span> Fast shipping available</li>
      </ul>
    </div>
  </div>

  <!-- ================= 3. SIMILAR ================= -->
  <?php if (!empty($relatedProducts)): ?>
  <section class="pdp-similar">
    <div class="pdp-sec-head">
      <div>
        <?php
          // Why these four are here, from the tags the recommender matched on.
          $pdpSimEyebrow = array_values(array_filter([
              $pdpBrand !== '' ? 'SAME BRAND' : '',
              $pdpScreen !== '' ? mb_strtoupper(tagValueLabel($pdpScreen, 'screen_size')) : '',
          ]));
        ?>
        <?php if ($pdpSimEyebrow): ?>
          <p class="pdp-eyebrow"><?= htmlspecialchars(implode(' · ', $pdpSimEyebrow)) ?></p>
        <?php endif; ?>
        <h2>Similar products</h2>
      </div>
      <?php if ($product['category_id']): ?>
        <a class="pdp-viewall"
           href="<?= htmlspecialchars(defined('EMBEDDED_PRODUCT_DETAIL')
               ? WEB_ROOT . 'public/authenticated/customer/products.php'
               : WEB_ROOT . 'public/guest/products.php') ?>?category_id=<?= (int)$product['category_id'] ?>">
          View all <?= htmlspecialchars(mb_strtolower($pdpCat ?: 'products')) ?> &rarr;
        </a>
      <?php endif; ?>
    </div>

    <div class="pdp-grid">
      <?php foreach ($relatedProducts as $relatedProduct): ?>
        <article class="pdp-card">
          <div class="pdp-card-plate">
            <?php if ($relatedProduct['image_url']): ?>
              <img src="<?= WEB_ROOT . htmlspecialchars($relatedProduct['image_url']) ?>"
                   alt="<?= htmlspecialchars($relatedProduct['name']) ?>" loading="lazy">
            <?php else: ?>
              <span class="pdp-noimg">No image</span>
            <?php endif; ?>
          </div>
          <?php
            /* `products` has no brand column - the brand reaches here inside
               shared_tags ("brand=Lenovo,screen_size=14-inch"), the same
               string describeSimilarity() reads. Pulled out when it is there,
               and the line is simply absent when it is not. */
            $pdpCardBrand = '';
            foreach (explode(',', (string)($relatedProduct['shared_tags'] ?? '')) as $pair) {
                if (strpos($pair, 'brand=') === 0) { $pdpCardBrand = substr($pair, 6); break; }
            }
          ?>
          <?php if ($pdpCardBrand !== ''): ?>
            <span class="pdp-card-brand"><?= htmlspecialchars(mb_strtoupper(tagValueLabel($pdpCardBrand, 'brand'))) ?></span>
          <?php endif; ?>
          <h3 class="pdp-card-name">
            <a href="<?= htmlspecialchars($relatedBase) ?>?id=<?= (int)$relatedProduct['id'] ?>">
              <?= htmlspecialchars($relatedProduct['name']) ?>
            </a>
          </h3>
          <span class="pdp-card-price"><?= formatCurrency($relatedProduct['price']) ?></span>
          <?php // Why this product is here - keeps the section from reading as a random shelf. ?>
          <span class="pdp-card-why"><?= htmlspecialchars(describeSimilarity($relatedProduct)) ?></span>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ================= 4. REVIEWS ================= -->
  <section class="pdp-reviews" id="reviews">
    <div class="pdp-sec-head"><div><h2>Customer reviews</h2></div></div>

    <div class="pdp-reviews-grid">
      <aside class="pdp-summary">
        <?php /* .rating-overview / .rating-number / .stars i / .review-count are
                 the hooks renderReviews() writes into - names unchanged. */ ?>
        <div class="rating-overview">
          <div class="pdp-avgwrap">
            <div class="pdp-avg">
              <span class="rating-number">0.0</span>
              <span class="pdp-avg-out">out of 5</span>
            </div>
            <div class="stars">
              <i class="fi fi-rs-star"></i><i class="fi fi-rs-star"></i><i class="fi fi-rs-star"></i><i class="fi fi-rs-star"></i><i class="fi fi-rs-star"></i>
            </div>
            <span class="review-count">0 reviews</span>
          </div>
        </div>

        <?php /* One bar per star. The counts come from the same summary the
                 filter chips already read (api/reviews.php returns five_star
                 .. one_star); renderReviews() fills both in one pass. */ ?>
        <div class="pdp-bars">
          <?php foreach ([5,4,3,2,1] as $s): ?>
            <div class="pdp-bar">
              <span><?= $s ?> &#9733;</span>
              <span class="pdp-bar-track"><span class="pdp-bar-fill" data-bar="<?= $s ?>"></span></span>
              <span class="pdp-bar-n" data-barcount="<?= $s ?>">0</span>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if (isLoggedIn() && $customerHasPurchased && !$customerAlreadyReviewed): ?>
          <button class="write-review-btn" onclick="showReviewForm()">Write a review</button>
        <?php elseif (isLoggedIn() && $customerAlreadyReviewed): ?>
          <p class="pdp-note">You've already reviewed this product.</p>
        <?php elseif (isLoggedIn() && !$customerHasPurchased): ?>
          <p class="pdp-note">Bought this? Once your order is delivered, you can rate it from My Orders.</p>
        <?php else: ?>
          <p class="pdp-note">Bought this? Once your order is delivered, you can rate it from My Orders.</p>
        <?php endif; ?>
      </aside>

      <div>
        <div class="pdp-filter" id="reviewsFilter">
          <button type="button" class="review-filter-btn active" data-rating="0" onclick="filterReviewsByRating(0, this)">All</button>
          <?php foreach ([5,4,3,2,1] as $s): ?>
            <button type="button" class="review-filter-btn" data-rating="<?= $s ?>" onclick="filterReviewsByRating(<?= $s ?>, this)">
              <?= $s ?> <span class="rfb-star">&#9733;</span><span class="rfb-count" data-star="<?= $s ?>"></span>
            </button>
          <?php endforeach; ?>
        </div>

        <div class="pdp-reviews-list reviews-list">
          <div class="no-reviews"><p>No reviews yet. Be the first to review this product!</p></div>
        </div>

        <?php if (isLoggedIn() && $customerHasPurchased && !$customerAlreadyReviewed): ?>
        <div class="review-form" id="reviewForm" style="display: none;">
          <h3>Write your review</h3>
          <form id="submitReviewForm">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

            <div class="rating-input">
              <label>Rating:</label>
              <div class="star-rating" id="starRating">
                <i class="fi fi-rs-star" data-rating="1"></i>
                <i class="fi fi-rs-star" data-rating="2"></i>
                <i class="fi fi-rs-star" data-rating="3"></i>
                <i class="fi fi-rs-star" data-rating="4"></i>
                <i class="fi fi-rs-star" data-rating="5"></i>
              </div>
              <input type="hidden" name="rating" id="ratingValue" value="0">
            </div>

            <div class="title-input">
              <label for="title">Review title (optional):</label>
              <input type="text" name="title" id="title" placeholder="Summarize your experience...">
            </div>

            <div class="comment-input">
              <label for="comment">Your review:</label>
              <textarea name="comment" id="comment" rows="5" placeholder="Share your experience with this product... (at least 10 characters)"></textarea>
            </div>

            <div class="review-photo-input">
              <label for="review_image">Add photos (optional):</label>
              <input type="file" name="review_image[]" id="review_image" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
              <p class="review-photo-hint">Up to 5 photos, JPG/PNG/WEBP/GIF, up to 5&nbsp;MB each. You can add more photos later too.</p>
              <div id="reviewImagePreview" class="review-photo-preview-grid" style="display:none;"></div>
            </div>

            <!-- Explicit, always-visible validation message - not relying
                 on the browser's native "required" tooltip (easy to miss,
                 and it silently blocks the submit event from ever firing
                 at all, so no JS - including the toast below - runs). -->
            <div id="reviewFormError" class="review-form-error" style="display:none;"></div>

            <div class="form-actions">
              <button type="submit" class="submit-review-btn">Submit review</button>
              <button type="button" class="cancel-btn" onclick="hideReviewForm()">Cancel</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

</div>
</div>

<script>
function changeQuantity(change) {
    const quantityInput = document.getElementById('quantity');
    const currentValue = parseInt(quantityInput.value);
    const newValue = Math.max(1, Math.min(<?php echo $product['stock']; ?>, currentValue + change));
    quantityInput.value = newValue;
}

function addToCartFromDetail() {
    if (!<?php echo isLoggedIn() ? 'true' : 'false'; ?>) {
        openLoginModal();
        return;
    }
    
    const quantity = parseInt(document.getElementById('quantity').value);
    addToCart(<?php echo $product['id']; ?>, quantity);
}

function buyNowFromDetail() {
    if (!<?php echo isLoggedIn() ? 'true' : 'false'; ?>) {
        openLoginModal();
        return;
    }
    
    const quantity = parseInt(document.getElementById('quantity').value);
    addToCart(<?php echo $product['id']; ?>, quantity, function() {
        window.location.href = '<?= WEB_ROOT ?>public/authenticated/customer/checkout.php';
    });
}

function notifyWhenAvailable() {
    if (!<?php echo isLoggedIn() ? 'true' : 'false'; ?>) {
        openLoginModal();
        return;
    }

    // Out-of-stock products are saved to the cart as a Wishlist entry (see
    // cart.php) and move back to the cart automatically once restocked.
    addToCart(<?php echo $product['id']; ?>, 1);
}

function showReviewForm() {
    document.getElementById('reviewForm').style.display = 'block';
    document.getElementById('reviewForm').scrollIntoView({ behavior: 'smooth' });
}

// Arriving via a "Rate this product" link from My Orders (#reviews hash) -
// open the form immediately instead of making them click twice.
document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash === '#reviews' && document.getElementById('reviewForm')) {
        showReviewForm();
    }
});

function hideReviewForm() {
    document.getElementById('reviewForm').style.display = 'none';
    document.getElementById('submitReviewForm').reset();
}

// Current viewer's user id (0 when logged out) - lets renderReviews() show
// the "Add photos" control only on your own review.
const CURRENT_USER_ID = <?php echo (int)(isLoggedIn() && function_exists('getUserId') ? getUserId() : 0); ?>;
const REVIEW_API = '<?= WEB_ROOT ?>public/authenticated/api/reviews.php';
const ASSET_BASE = '<?= WEB_ROOT ?>';
const REVIEW_MAX_IMAGES = <?php echo (int)(defined('REVIEW_MAX_IMAGES') ? REVIEW_MAX_IMAGES : 5); ?>;
let currentReviewRating = 0; // 0 = all, 1..5 = star filter

function filterReviewsByRating(rating, btn) {
    currentReviewRating = rating;
    document.querySelectorAll('#reviewsFilter .review-filter-btn').forEach(function (b) {
        b.classList.toggle('active', b === btn);
    });
    loadReviews();
}

function loadReviews() {
    // Was reading this from the review form's hidden product_id input, but
    // that form only renders for a logged-in customer who has purchased
    // and not yet reviewed this product - for anyone else (every guest,
    // every non-purchaser, anyone who already reviewed) that input doesn't
    // exist at all, so this threw a TypeError immediately and the Customer
    // Reviews section never loaded real data for them. <?= (int)$product['id'] ?>
    // comes straight from PHP and is always available regardless of who's viewing.
    const productId = <?= (int)$product['id'] ?>;
    const reviewsList = document.querySelector('.reviews-list');

    if (!productId) return;

    let url = `<?= WEB_ROOT ?>public/authenticated/api/reviews.php?action=list&product_id=${productId}`;
    if (currentReviewRating > 0) { url += `&rating=${currentReviewRating}`; }

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            renderReviews(data.reviews, data.summary);
        }
    })
    .catch(err => {
        console.error('Error loading reviews:', err);
    });
}

function renderReviews(reviews, summary) {
    const reviewsList = document.querySelector('.reviews-list');
    const ratingOverview = document.querySelector('.rating-overview');
    
    if (!reviewsList) return;
    
    // Update rating overview
    if (summary && ratingOverview) {
        // AVG(rating) comes back from PDO/MySQL as a numeric STRING (e.g.
        // "5.0000"), not a JS number, since MySQL's AVG() returns a DECIMAL
        // type and PDO always stringifies those to avoid precision loss.
        // Without this Number(...) coercion, avgRating.toFixed(1) below
        // throws a TypeError the moment a product has at least one review
        // (summary.average_rating is only null/falsy at zero reviews, which
        // is why this only broke once a review actually existed) - silently
        // aborting this whole function before it ever reaches the code
        // below that renders the actual review list, leaving the page
        // stuck on its static "No reviews yet" placeholder forever.
        const avgRating = Number(summary.average_rating) || 0;
        const totalReviews = Number(summary.total_reviews) || 0;
        
        document.querySelector('.rating-number').textContent = avgRating.toFixed(1);
        document.querySelector('.review-count').textContent = `${totalReviews} review${totalReviews !== 1 ? 's' : ''}`;
        
        // Update stars
        const stars = ratingOverview.querySelectorAll('.stars i');
        stars.forEach((star, index) => {
            if (index < Math.floor(avgRating)) {
                star.className = 'fi fi-ss-star';
            } else if (index < avgRating) {
                star.className = 'fi fi-rs-star-half';
            } else {
                star.className = 'fi fi-rs-star';
            }
        });

        // Per-star counts on the filter buttons (always the product-wide totals)
        const starKey = { 5: 'five_star', 4: 'four_star', 3: 'three_star', 2: 'two_star', 1: 'one_star' };
        /* The five bars in the summary card, from the same summary object
           the filter counts below already use - no second request. */
        const starKeys = { 5: 'five_star', 4: 'four_star', 3: 'three_star', 2: 'two_star', 1: 'one_star' };
        document.querySelectorAll('.pdp-bar-fill').forEach(el => {
            const star = el.getAttribute('data-bar');
            const n = parseInt(summary[starKeys[star]] || 0, 10);
            el.style.width = (totalReviews > 0 ? Math.round((n / totalReviews) * 100) : 0) + '%';
        });
        document.querySelectorAll('.pdp-bar-n').forEach(el => {
            const star = el.getAttribute('data-barcount');
            el.textContent = parseInt(summary[starKeys[star]] || 0, 10);
        });

        document.querySelectorAll('#reviewsFilter .rfb-count').forEach(el => {
            const n = Number(summary[starKey[el.dataset.star]]) || 0;
            el.textContent = ` (${n})`;
        });
    }

    // Render reviews
    if (reviews.length === 0) {
        const msg = currentReviewRating > 0
            ? `No ${currentReviewRating}-star reviews yet.`
            : 'No reviews yet. Be the first to review this product!';
        reviewsList.innerHTML = `<div class="no-reviews"><p>${msg}</p></div>`;
        return;
    }
    
    reviewsList.innerHTML = reviews.map(review => `
        <div class="review-item">
            <div class="review-head">
                <div class="review-avatar" aria-hidden="true">${initialsOf(review.firstname, review.lastname)}</div>
                <div class="reviewer-info">
                    <div class="reviewer-name">${review.firstname} ${review.lastname}${review.is_verified_purchase ? '<span class="verified-badge">&#10003; Verified purchase</span>' : ''}</div>
                    <div class="review-rating">${generateStars(review.rating)}</div>
                </div>
                <div class="review-date">${formatDate(review.created_at)}</div>
            </div>
            ${review.title ? `<div class="review-title">${review.title}</div>` : ''}
            <div class="review-comment">${review.comment}</div>
            ${(() => {
                const imgs = Array.isArray(review.images) ? review.images : [];
                const isOwn = CURRENT_USER_ID && Number(review.user_id) === CURRENT_USER_ID;
                const gallery = imgs.length ? `
            <div class="review-photos">
                ${imgs.map(im => `
                <a class="review-photo" href="${ASSET_BASE}${im.image_path}" target="_blank" rel="noopener">
                    <img src="${ASSET_BASE}${im.image_path}" alt="Customer photo" loading="lazy">
                </a>`).join('')}
            </div>` : '';
                const addBtn = (isOwn && imgs.length < REVIEW_MAX_IMAGES) ? `
            <div class="review-add-photo">
                <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple style="display:none" onchange="addReviewPhoto(${review.id}, this)">
                <button type="button" class="review-add-photo-btn" onclick="this.previousElementSibling.click()">
                    <i class="fi fi-rr-camera"></i> ${imgs.length ? 'Add more photos' : 'Add a photo'}
                </button>
            </div>` : '';
                return gallery + addBtn;
            })()}
            ${review.admin_reply ? `
            <div class="review-store-reply">
                <div class="rsr-head">
                    <i class="fi fi-rr-store"></i> TechWorld Store Response${review.admin_reply_at ? ` <span class="rsr-when">&middot; ${formatDateTime(review.admin_reply_at)}</span>` : ''}
                </div>
                <div class="rsr-body">${review.admin_reply}</div>
            </div>` : ''}
            <div class="review-footer">
                <div class="review-helpful">
                    <span class="review-helpful-q">Was this helpful?</span>
                    <button class="helpful-btn" onclick="markHelpful(${review.id})">
                        <i class="fi fi-rr-thumbs-up"></i>
                        Helpful (${review.helpful_count})
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

// Initials for the review avatar. Presentation only - the name itself is
// still printed next to it.
function initialsOf(first, last) {
    const a = (first || '').trim().charAt(0);
    const b = (last || '').trim().charAt(0);
    return ((a + b) || '?').toUpperCase();
}

function generateStars(rating) {
    let stars = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= rating) {
            stars += '<i class="fi fi-ss-star"></i>';
        } else {
            stars += '<i class="fi fi-rs-star"></i>';
        }
    }
    return stars;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function formatDateTime(dateString) {
    // MySQL DATETIME ("YYYY-MM-DD HH:MM:SS") has no zone - treat it as local
    // by swapping the space for "T" so Date() doesn't parse it as UTC.
    const date = new Date(String(dateString).replace(' ', 'T'));
    if (isNaN(date)) return formatDate(dateString);
    return date.toLocaleString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });
}

// Add one or more photos to an existing own review (the "Add photos" button
// rendered by renderReviews). The server enforces the REVIEW_MAX_IMAGES cap.
function addReviewPhoto(reviewId, inputEl) {
    const files = Array.from(inputEl.files || []);
    if (!files.length) return;
    if (files.some(f => f.size > 5 * 1024 * 1024)) {
        if (typeof showNotification === 'function') showNotification('Each photo must be 5 MB or smaller.', 'error');
        inputEl.value = '';
        return;
    }
    const btn = inputEl.nextElementSibling;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fi fi-rr-spinner"></i> Uploading...'; }
    const fd = new FormData();
    fd.append('review_id', reviewId);
    files.forEach(f => fd.append('review_image[]', f));
    fetch(REVIEW_API + '?action=add_image', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (typeof showNotification === 'function') {
            showNotification(data.message || (data.success ? 'Photo added.' : 'Could not add photo.'), data.success ? 'success' : 'error');
        }
        loadReviews();
    })
    .catch(() => {
        if (typeof showNotification === 'function') showNotification('An error occurred uploading the photo.', 'error');
        loadReviews();
    });
}

// Live preview for the photos picked in the "Write a Review" form (up to
// REVIEW_MAX_IMAGES). "Remove" on any thumbnail rebuilds the input's
// FileList without that file.
document.addEventListener('DOMContentLoaded', function () {
    const photoInput = document.getElementById('review_image');
    const preview = document.getElementById('reviewImagePreview');
    if (!photoInput || !preview) return;

    function renderPreview() {
        let files = Array.from(photoInput.files || []);
        if (files.length > REVIEW_MAX_IMAGES) {
            if (typeof showNotification === 'function') showNotification('You can attach up to ' + REVIEW_MAX_IMAGES + ' photos.', 'error');
            files = files.slice(0, REVIEW_MAX_IMAGES);
            const dt = new DataTransfer();
            files.forEach(f => dt.items.add(f));
            photoInput.files = dt.files;
        }
        if (!files.length) { preview.style.display = 'none'; preview.innerHTML = ''; return; }
        preview.innerHTML = files.map((f, i) =>
            '<div class="review-photo-preview" data-idx="' + i + '">'
            + '<img src="' + URL.createObjectURL(f) + '" alt="Selected photo">'
            + '<button type="button" class="review-photo-remove" aria-label="Remove photo">&times;</button>'
            + '</div>'
        ).join('');
        preview.style.display = 'flex';
        preview.querySelectorAll('.review-photo-remove').forEach(function (b) {
            b.onclick = function () {
                const idx = parseInt(this.closest('[data-idx]').dataset.idx, 10);
                const dt = new DataTransfer();
                Array.from(photoInput.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
                photoInput.files = dt.files;
                renderPreview();
            };
        });
    }
    photoInput.addEventListener('change', renderPreview);
});

function markHelpful(reviewId) {
    fetch('<?= WEB_ROOT ?>public/authenticated/api/reviews.php?action=helpful', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ review_id: reviewId, is_helpful: true })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadReviews(); // Reload to update helpful counts
        }
    });
}

function selectPaymentMethod(method) {
    // Remove active class from all payment options
    document.querySelectorAll('.payment-option').forEach(option => {
        option.classList.remove('active');
    });
    
    // Add active class to selected option
    event.target.closest('.payment-option').classList.add('active');
    
    // Store selected payment method
    localStorage.setItem('selectedPaymentMethod', method);
    
    // Show confirmation message
    const methodName = method === 'cod' ? 'Cash on Delivery' : 'Pay at Store';
    showNotification(`Payment method selected: ${methodName}`, 'success');
    
    // Update add to cart button text if needed
    updateAddToCartButton(method);
}

function updateAddToCartButton(paymentMethod) {
    const addToCartBtn = document.querySelector('.add-to-cart-btn');
    const buyNowBtn = document.querySelector('.buy-now-btn');
    
    if (paymentMethod === 'cod') {
        if (addToCartBtn) addToCartBtn.textContent = 'Add to Cart (COD)';
        if (buyNowBtn) buyNowBtn.textContent = 'Buy Now (COD)';
    } else if (paymentMethod === 'store') {
        if (addToCartBtn) addToCartBtn.textContent = 'Add to Cart (Store Pickup)';
        if (buyNowBtn) buyNowBtn.textContent = 'Buy Now (Store Pickup)';
    }
}

// Star rating functionality
document.addEventListener('DOMContentLoaded', function() {
    // Load reviews on page load
    loadReviews();
    
    const starRating = document.getElementById('starRating');
    const ratingValue = document.getElementById('ratingValue');
    
    if (starRating && ratingValue) {
        const stars = starRating.querySelectorAll('i');
        
        stars.forEach((star, index) => {
            star.addEventListener('click', function() {
                const rating = index + 1;
                ratingValue.value = rating;
                
                stars.forEach((s, i) => {
                    if (i < rating) {
                        s.classList.remove('fi-rs-star');
                        s.classList.add('fi-ss-star', 'active');
                    } else {
                        s.classList.remove('fi-ss-star', 'active');
                        s.classList.add('fi-rs-star');
                    }
                });
            });
            
            star.addEventListener('mouseenter', function() {
                const hoverRating = index + 1;
                stars.forEach((s, i) => {
                    if (i < hoverRating) {
                        s.style.color = '#fbbf24';
                    } else {
                        s.style.color = '#374151';
                    }
                });
            });
        });
        
        starRating.addEventListener('mouseleave', function() {
            const currentRating = parseInt(ratingValue.value);
            stars.forEach((s, i) => {
                if (i < currentRating) {
                    s.style.color = '#fbbf24';
                } else {
                    s.style.color = '#374151';
                }
            });
        });
    }
    
    // Review form submission
    const reviewForm = document.getElementById('submitReviewForm');
    if (reviewForm) {
        reviewForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const rating = parseInt(formData.get('rating'));
            const comment = (formData.get('comment') || '').trim();
            const errorBox = document.getElementById('reviewFormError');

            let errorMsg = null;
            if (rating === 0) {
                errorMsg = 'Please select a rating (click a star) before submitting.';
            } else if (comment.length < 10) {
                errorMsg = 'Please write a review of at least 10 characters.';
            }

            if (errorMsg) {
                errorBox.textContent = errorMsg;
                errorBox.style.display = 'block';
                errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                if (typeof showNotification === 'function') { showNotification(errorMsg, 'error'); }
                return;
            }
            errorBox.style.display = 'none';

            const photoInput = document.getElementById('review_image');
            const photoFiles = photoInput ? Array.from(photoInput.files || []) : [];
            if (photoFiles.length > REVIEW_MAX_IMAGES) {
                errorBox.textContent = 'You can attach up to ' + REVIEW_MAX_IMAGES + ' photos.';
                errorBox.style.display = 'block';
                if (typeof showNotification === 'function') { showNotification('Too many photos (max ' + REVIEW_MAX_IMAGES + ').', 'error'); }
                return;
            }
            if (photoFiles.some(f => f.size > 5 * 1024 * 1024)) {
                errorBox.textContent = 'Each photo must be 5 MB or smaller.';
                errorBox.style.display = 'block';
                if (typeof showNotification === 'function') { showNotification('Photo too large (max 5 MB each).', 'error'); }
                return;
            }

            const submitBtn = this.querySelector('.submit-review-btn');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            // Multipart (not JSON) so the optional photo rides along in the
            // same request. formData already holds product_id/rating/title/
            // comment/review_image from the form fields.
            fetch('<?= WEB_ROOT ?>public/authenticated/api/reviews.php?action=submit', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    errorBox.style.display = 'none';
                    showNotification('Review submitted successfully!', 'success');
                    hideReviewForm();
                    reviewForm.reset();
                    // Reload reviews section
                    loadReviews();
                } else {
                    const msg = data.message || 'Failed to submit review';
                    errorBox.textContent = msg;
                    errorBox.style.display = 'block';
                    if (typeof showNotification === 'function') { showNotification(msg, 'error'); }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorBox.textContent = 'An error occurred. Please try again.';
                errorBox.style.display = 'block';
                if (typeof showNotification === 'function') { showNotification('An error occurred', 'error'); }
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
        });
    }
});
</script>

<?php if (!defined('EMBEDDED_PRODUCT_DETAIL')) {
    include __DIR__ . '/includes/footer.php';
} ?>