<?php
// product.php - Customer product detail page (requires login)
require_once '../../../config/functions.php';
require_login();

$productId = intval($_GET['id'] ?? 0);
if (!$productId) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/products.php');
    exit;
}

$product = getProduct($productId);
if (!$product) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/products.php?error=product_not_found');
    exit;
}

$pageTitle = htmlspecialchars($product['name']) . ' - TechWorld';

// Similar products are built by the embedded guest/product.php below (it
// assigns $relatedProducts itself via getSimilarProducts), so this page no
// longer runs its own query - the duplicate here only ever got overwritten.

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
  <title><?= $pageTitle ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<!-- Same solid page background used on Profile/Orders/Products/Support, so
     this page reads consistently instead of switching back to the plain
     gradient. Wraps the breadcrumb + the embedded guest/product.php markup
     (product-detail, related products, etc.) without needing to touch that
     shared file at all - it's just an ancestor in the rendered HTML. -->
<section class="account-page">
<style>
/* Same solid page background used on Profile/Orders/Products/Support - but
   with padding:0 here, unlike those pages. .product-detail/.related-products/
   .reviews-section (embedded from guest/product.php below) each already
   carry their own 40-60px vertical padding, since they're designed to sit
   directly under the header on the standalone guest page. Keeping this
   wrapper's own 40px top+bottom padding on top of that just stacked an
   extra, unwanted gap above the breadcrumb and below the reviews section. */
.account-page { padding:0; background: var(--page-bg-gradient); min-height:100vh; }
</style>

<!-- Breadcrumb. Carries the .pdp class as well as .pdp-breadcrumb so the
     page's own tokens (defined on .pdp, in guest/product.php's sheet) reach
     it: this sits outside the .pdp wrapper, above the embedded markup. -->
<nav class="pdp pdp-breadcrumb" aria-label="Breadcrumb">
    <div class="pdp-wrap">
        <a href="<?= WEB_ROOT ?>public/authenticated/customer/index.php">Home</a>
        <span>/</span>
        <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php">Products</a>
        <?php if ($product['category_name']): ?>
            <span>/</span>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category_id=<?= $product['category_id'] ?>">
                <?= htmlspecialchars($product['category_name']) ?>
            </a>
        <?php endif; ?>
        <span>/</span>
        <span class="pdp-crumb-current"><?= htmlspecialchars($product['name']) ?></span>
    </div>
</nav>

<?php
// Reuse the guest product detail markup without duplicating header/footer
define('EMBEDDED_PRODUCT_DETAIL', true);
include __DIR__ . '/../../guest/product.php';
?>

</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>


