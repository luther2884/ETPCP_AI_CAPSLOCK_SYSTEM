<?php
/* index.php — reference assembly of the landing page.
   Copy the structure into your own index.php; keep your existing
   header/footer includes and DB code. */

$cartCount = 16;                 // however you count the session cart

// Optional: feed the hero chips from the DB
// $heroChips = [];
// $res = $conn->query("SELECT name, price, image FROM products ORDER BY featured DESC LIMIT 3");
// while ($r = $res->fetch_assoc()) {
//   $heroChips[] = ['name' => $r['name'], 'price' => '₱' . number_format($r['price']), 'img' => $r['image']];
// }

$heroImage = 'assets/img/hero-build.png';   // omit the line for a striped placeholder

include __DIR__ . '/includes/header.php';   // opens <body class="tw-page"> + background + rail + <main class="tw-main">
?>

<?php include __DIR__ . '/includes/hero-section.php'; ?>
<?php include __DIR__ . '/includes/category-carousel.php'; ?>
<?php include __DIR__ . '/includes/featured-products.php'; ?>
<?php include __DIR__ . '/includes/ai-configurator.php'; ?>
<?php include __DIR__ . '/includes/why-techworld.php'; ?>
<?php include __DIR__ . '/includes/site-footer.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
