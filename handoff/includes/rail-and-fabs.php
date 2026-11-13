<?php
/* includes/rail-and-fabs.php
   Left icon rail + floating scroll-top / cart buttons.
   Include once, after animated-background.php and BEFORE <main class="tw-main">.

   Optional before including:
     $railItems  – array of ['label' =>, 'href' =>, 'icon' =>]  (icon = image path, optional)
     $cartCount  – integer badge on the cart button
*/
$railItems = $railItems ?? [
  ['label' => 'Search',      'href' => 'search.php'],
  ['label' => 'Home',        'href' => 'index.php'],
  ['label' => 'Deals',       'href' => 'deals.php'],
  ['label' => 'Processors',  'href' => 'category.php?c=cpu'],
  ['label' => 'Storage',     'href' => 'category.php?c=storage'],
  ['label' => 'Monitors',    'href' => 'category.php?c=monitors'],
  ['label' => 'Peripherals', 'href' => 'category.php?c=peripherals'],
  ['label' => 'Networking',  'href' => 'category.php?c=network'],
  ['label' => 'Accessories', 'href' => 'category.php?c=accessories'],
  ['label' => 'Support',     'href' => 'support.php'],
];
$cartCount = $cartCount ?? 0;
?>
<nav class="tw-rail" aria-label="Categories">
  <span class="tw-rail__logo" aria-hidden="true"><i></i><i></i><i></i><i></i></span>

  <?php foreach ($railItems as $r): ?>
    <a class="tw-rail__btn" href="<?= htmlspecialchars($r['href']) ?>" title="<?= htmlspecialchars($r['label']) ?>">
      <?php if (!empty($r['icon'])): ?>
        <img src="<?= htmlspecialchars($r['icon']) ?>" alt="<?= htmlspecialchars($r['label']) ?>">
      <?php else: ?>
        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <rect x="2.5" y="2.5" width="15" height="15" rx="3" stroke="#67e8f9" stroke-width="1.6"/>
        </svg>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>

  <span style="flex:1"></span>
  <span class="tw-rail__avatar" aria-hidden="true"><div></div></span>
</nav>

<div class="tw-fabs">
  <a class="tw-fab-top" href="#top" aria-label="Back to top">&uarr;</a>
  <a class="tw-fab-cart" href="cart.php" aria-label="Cart">
    <i aria-hidden="true"></i>
    <b><?= (int) $cartCount ?></b>
  </a>
</div>
