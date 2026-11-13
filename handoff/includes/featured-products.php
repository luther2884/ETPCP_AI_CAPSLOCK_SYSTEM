<?php
/* includes/featured-products.php
   FOUR product cards. Centered head; the "VIEW ALL" link stays right.

   Badge system — one per card, or none:
     'badge' => 'new'    → cyan  NEW
     'badge' => 'value'  → pink  BEST VALUE   (also makes it the feature card)
     'badge' => 'stock'  → green IN STOCK

   Stock tone: 'stockTone' => 'low' | 'ok' | null (default cyan)

   Feed from your DB instead of the sample array, e.g.:
     $products = [];
     $res = $conn->query("SELECT name, kind, spec, price, stock, image
                          FROM products WHERE featured = 1 LIMIT 4");
     while ($r = $res->fetch_assoc()) {
       $products[] = [
         'kind'  => $r['kind'],
         'name'  => $r['name'],
         'spec'  => $r['spec'],
         'price' => '₱' . number_format($r['price']),
         'stock' => $r['stock'] . ' LEFT',
         'img'   => $r['image'],
         'badge' => $r['stock'] <= 3 ? 'value' : null,
       ];
     }
*/
require_once __DIR__ . '/section-head.php';

$totalSkus = $totalSkus ?? '1,240';
$products = $products ?? [
  ['kind' => 'ALL-IN-ONE', 'name' => 'ViewPlus AIZ-14450HX All-in-One',
   'spec' => "i5-14450HX · 16GB · 512GB M.2\n23.8\" IPS · WIN 11 PRO",
   'price' => '₱37,999', 'stock' => '4 LEFT', 'badge' => 'new'],
  ['kind' => 'ALL-IN-ONE', 'name' => 'ViewPlus AIZ-11900H All-in-One',
   'spec' => "i9-11900H · 16GB · 512GB M.2\n23.8\" IPS · WIN 11 PRO",
   'price' => '₱39,999', 'stock' => '4 LEFT'],
  ['kind' => 'DESKTOP', 'name' => 'Ryzen 7 5700G Desktop PC',
   'spec' => "RYZEN 7 5700G · A520M · 16GB\n256GB SSD · 550W BRONZE",
   'price' => '₱14,995', 'stock' => '3 LEFT', 'stockTone' => 'low', 'badge' => 'value'],
  ['kind' => 'LAPTOP', 'name' => 'Lenovo IdeaPad Slim 5 14ACH5',
   'spec' => "RYZEN 7 PRO · 16GB · 1TB SSD\n14\" 2.8K IPS · WIN 10",
   'price' => '₱47,995', 'stock' => '7 IN STOCK', 'stockTone' => 'ok', 'badge' => 'stock'],
];

$badgeText = ['new' => 'NEW', 'value' => 'BEST VALUE', 'stock' => 'IN STOCK'];
?>
<section class="tw-sec--lg" id="featured">

  <div class="tw-head-row">
    <span></span>
    <?php tw_section_head('02', 'SELECTED BUILDS', 'Featured products',
      'Hand-picked from our latest desktops and laptops.'); ?>
    <a class="tw-link-mono" href="products.php">VIEW ALL <?= htmlspecialchars($totalSkus) ?> &rarr;</a>
  </div>

  <div class="tw-grid-4">
    <?php foreach ($products as $k => $p):
      $badge = $p['badge'] ?? null;
      $tone  = $p['stockTone'] ?? null;
    ?>
      <article class="tw-card<?= $badge === 'value' ? ' tw-card--feature' : '' ?>"
               style="animation-delay: <?= $k * 0.08 ?>s">
        <span class="tw-card__edge" aria-hidden="true"></span>

        <div class="tw-card__media">
          <?php if (!empty($p['img'])): ?>
            <img src="<?= htmlspecialchars($p['img']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
          <?php else: ?>
            <span class="tw-card__ph">PRODUCT SHOT</span>
          <?php endif; ?>
          <?php if ($badge && isset($badgeText[$badge])): ?>
            <span class="tw-badge tw-badge--<?= $badge ?>"><?= $badgeText[$badge] ?></span>
          <?php endif; ?>
        </div>

        <div class="tw-card__kind"><?= htmlspecialchars($p['kind']) ?></div>
        <h3 class="tw-card__name"><?= htmlspecialchars($p['name']) ?></h3>
        <div class="tw-card__spec"><?= nl2br(htmlspecialchars($p['spec'])) ?></div>

        <div class="tw-card__foot">
          <div class="tw-card__price"><?= htmlspecialchars($p['price']) ?></div>
          <div class="tw-card__stock<?= $tone ? ' tw-card__stock--' . $tone : '' ?>">
            <?= htmlspecialchars($p['stock']) ?>
          </div>
        </div>

        <form class="tw-card__btns" method="post" action="cart-add.php">
          <input type="hidden" name="product" value="<?= htmlspecialchars($p['name']) ?>">
          <button class="tw-btn-outline" type="submit" name="action" value="add">ADD TO CART</button>
          <button class="tw-btn-buy" type="submit" name="action" value="buy">BUY</button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</section>
