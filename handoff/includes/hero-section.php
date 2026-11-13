<?php
/* includes/hero-section.php
   Animated hero + brand marquee + animated divider.
   Include inside <main class="tw-main">.

   Optional before including:
     $heroImage  – hero product photo path (omit for the striped placeholder)
     $heroChips  – array of ['name' =>, 'price' =>, 'img' =>]  (3 looks best)
     $heroStats  – array of ['n' =>, 'label' =>]
     $brands     – array of brand names for the marquee
*/
$heroImage = $heroImage ?? null;
$heroChips = $heroChips ?? [
  ['name' => 'ViewPlus AIZ-14450HX', 'price' => '₱37,999'],
  ['name' => 'ViewPlus AIZ-11900H',  'price' => '₱39,999'],
  ['name' => 'Ryzen 7 5700G',        'price' => '₱14,995'],
];
$heroStats = $heroStats ?? [
  ['n' => '1,240+', 'label' => 'Products in stock'],
  ['n' => '16K',    'label' => 'Builds configured'],
  ['n' => '48h',    'label' => 'Metro Manila delivery'],
];
$brands = $brands ?? ['AMD Ryzen', 'Intel Core', 'NVIDIA RTX', 'Dahua CCTV', 'Lenovo', 'ASUS'];
?>
<section class="tw-hero">
  <div>
    <div class="tw-eyebrow">
      <span class="tw-dot" aria-hidden="true"></span>
      <span>Smart technology, smarter choices</span>
    </div>

    <h1 class="tw-h1">
      <span class="tw-word">BUILD</span>
      <span class="tw-word">YOUR</span>
      <br>
      <span class="tw-word tw-word--grad">NEXT RIG</span><span class="tw-caret" aria-hidden="true"></span>
    </h1>

    <p class="tw-lede">Discover the right components for your needs with intelligent product recommendations powered by AI.</p>

    <div class="tw-cta-row">
      <a class="tw-btn tw-btn--primary" href="#featured">
        <span>Explore Products</span>
        <span class="tw-btn__arrow" aria-hidden="true">&rarr;</span>
        <span class="tw-shine" aria-hidden="true"></span>
      </a>
      <a class="tw-btn tw-btn--ghost" href="#ai">Try AI Builder</a>
    </div>

    <div class="tw-stats">
      <?php foreach ($heroStats as $s): ?>
        <div>
          <div class="tw-stat__n"><?= htmlspecialchars($s['n']) ?></div>
          <div class="tw-stat__l"><?= htmlspecialchars($s['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="tw-visual">
    <div class="tw-visual__glow" aria-hidden="true"></div>
    <div class="tw-ring tw-ring--outer" aria-hidden="true"><span class="tw-ring__bead"></span></div>
    <div class="tw-ring tw-ring--inner" aria-hidden="true"><span class="tw-ring__bead"></span></div>

    <div class="tw-shot<?= $heroImage ? '' : ' tw-shot--empty' ?>">
      <?php if ($heroImage): ?>
        <img src="<?= htmlspecialchars($heroImage) ?>" alt="Featured PC build">
      <?php else: ?>
        <span class="tw-shot__label">DROP HERO<br>PC BUILD SHOT</span>
      <?php endif; ?>
      <span class="tw-scan" aria-hidden="true"></span>
    </div>

    <div class="tw-chips">
      <?php foreach ($heroChips as $c): ?>
        <div class="tw-chip">
          <?php if (!empty($c['img'])): ?>
            <img class="tw-chip__thumb" src="<?= htmlspecialchars($c['img']) ?>" alt="">
          <?php else: ?>
            <span class="tw-chip__thumb" aria-hidden="true"></span>
          <?php endif; ?>
          <span class="tw-chip__name"><?= htmlspecialchars($c['name']) ?></span>
          <span class="tw-chip__price"><?= htmlspecialchars($c['price']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="tw-marquee" aria-hidden="true">
  <div class="tw-marquee__track">
    <?php foreach (array_merge($brands, $brands) as $b) echo '<span>' . htmlspecialchars($b) . '</span>'; ?>
  </div>
</section>

<div class="tw-divider" aria-hidden="true"><span></span></div>
