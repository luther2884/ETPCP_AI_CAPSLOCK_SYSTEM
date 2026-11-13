<?php
/* includes/category-carousel.php
   3D coverflow category picker. Centered head, controls stay right.
   Needs assets/js/techworld.js (the [data-tw-carousel] wrapper wires itself).

   Optional before including:
     $categories – array of ['label' =>, 'slot' =>, 'href' =>, 'img' =>]
     $autoSpeed  – ms between auto-advances (default 3400)
*/
require_once __DIR__ . '/section-head.php';

$categories = $categories ?? [
  ['label' => 'CCTV & SECURITY', 'slot' => 'CCTV KIT'],
  ['label' => 'PROCESSORS',      'slot' => 'CPU'],
  ['label' => 'GRAPHICS CARDS',  'slot' => 'GPU'],
  ['label' => 'MOTHERBOARDS',    'slot' => 'MOBO'],
  ['label' => 'SPEAKERS',        'slot' => 'AUDIO'],
  ['label' => 'CPU COOLERS',     'slot' => 'COOLER'],
  ['label' => 'SWITCHES',        'slot' => 'NETWORK'],
  ['label' => 'MONITORS',        'slot' => 'DISPLAY'],
];
$autoSpeed = $autoSpeed ?? 3400;
$total = count($categories);
?>
<section class="tw-sec" id="catalog" data-tw-carousel data-speed="<?= (int) $autoSpeed ?>">

  <div class="tw-head-row">
    <span></span>
    <?php tw_section_head('01', 'THE CATALOG', 'Explore computer components',
      "Pick a category the way you'd pick a game — glide to the one you want."); ?>
    <div class="tw-head-row__ctrl">
      <span class="tw-counter">01 / <?= str_pad($total, 2, '0', STR_PAD_LEFT) ?></span>
      <button class="tw-nav-btn tw-nav-btn--prev" type="button" aria-label="Previous category">&larr;</button>
      <button class="tw-nav-btn tw-nav-btn--next" type="button" aria-label="Next category">&rarr;</button>
    </div>
  </div>

  <div class="tw-stage">
    <div class="tw-stage__glow" aria-hidden="true"></div>
    <?php foreach ($categories as $c): ?>
      <div class="tw-tile" data-label="<?= htmlspecialchars($c['label']) ?>" style="--d:0;--ad:0">
        <div class="tw-tile__inner">
          <?php if (!empty($c['img'])): ?>
            <img src="<?= htmlspecialchars($c['img']) ?>" alt="<?= htmlspecialchars($c['label']) ?>">
          <?php else: ?>
            <span class="tw-tile__label"><?= htmlspecialchars($c['slot']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="tw-active-label">
    <span aria-hidden="true"></span>
    <b><?= htmlspecialchars($categories[0]['label']) ?></b>
    <span aria-hidden="true"></span>
  </div>

  <div class="tw-progress">
    <?php foreach ($categories as $k => $c): ?>
      <span<?= $k === 0 ? ' class="is-active"' : '' ?> role="button" tabindex="0"
        aria-label="<?= htmlspecialchars($c['label']) ?>"></span>
    <?php endforeach; ?>
  </div>
</section>
