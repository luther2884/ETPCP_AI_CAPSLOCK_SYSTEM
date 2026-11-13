<?php
/* includes/why-techworld.php
   Asymmetric bento: one wide card with animated engine meters, plus three
   smaller cards each carrying a real visual (bar chart, cart mark, big number).

   Optional before including:
     $engineRows – ['k' =>, 'v' =>, 'w' => '84%']
     $invBars    – ['h' => '72%', 'tone' => 'cyan'|'pink'|null]
     $skuCount   – headline number for the inventory card
*/
require_once __DIR__ . '/section-head.php';

$engineRows = $engineRows ?? [
  ['k' => 'SOCKET',    'v' => 'PASS',        'w' => '100%'],
  ['k' => 'MEMORY',    'v' => '3200 XMP',    'w' => '84%'],
  ['k' => 'CLEARANCE', 'v' => '162 / 165mm', 'w' => '92%'],
  ['k' => 'WATTAGE',   'v' => '412 / 550W',  'w' => '75%'],
];
$invBars = $invBars ?? [
  ['h' => '38%'], ['h' => '56%'], ['h' => '44%'],
  ['h' => '72%', 'tone' => 'cyan'], ['h' => '88%', 'tone' => 'pink'],
  ['h' => '64%', 'tone' => 'cyan'], ['h' => '50%'],
  ['h' => '76%', 'tone' => 'pink'], ['h' => '42%'],
];
$skuCount = $skuCount ?? '1,240';
?>
<section class="tw-sec--xl">
  <?php tw_section_head('04', 'WHY TECHWORLD', 'Why choose TechWorld'); ?>

  <div class="tw-bento">

    <div class="tw-bento__cell tw-bento__cell--wide">
      <span class="tw-bento__glow" aria-hidden="true"></span>
      <div style="position:relative">
        <div class="tw-bento__kicker">COMPATIBILITY ENGINE</div>
        <div class="tw-bento__title">Every part cross-checked before it reaches your cart</div>
        <p class="tw-bento__body">Socket, chipset, RAM profile, case clearance and PSU headroom are validated as a set — not part by part.</p>
      </div>
      <div class="tw-engine">
        <?php foreach ($engineRows as $e): ?>
          <div class="tw-engine__row">
            <span class="tw-engine__k"><?= htmlspecialchars($e['k']) ?></span>
            <span class="tw-engine__track"><i style="width: <?= htmlspecialchars($e['w']) ?>"></i></span>
            <span class="tw-engine__v"><?= htmlspecialchars($e['v']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="tw-bento__cell tw-bento__cell--pink">
      <div class="tw-bento__kicker">LIVE INVENTORY</div>
      <div class="tw-bars" aria-hidden="true">
        <?php foreach ($invBars as $b): ?>
          <span class="<?= !empty($b['tone']) ? 'is-' . $b['tone'] : '' ?>"
                style="height: <?= htmlspecialchars($b['h']) ?>"></span>
        <?php endforeach; ?>
      </div>
      <div>
        <div class="tw-bignum"><?= htmlspecialchars($skuCount) ?></div>
        <div class="tw-bento__copy" style="margin-top:6px">SKUs counted off the shelf, not off a spreadsheet.</div>
      </div>
    </div>

    <div class="tw-bento__cell">
      <div class="tw-bento__kicker">ONE-CLICK CARTS</div>
      <div style="display:flex;align-items:center;gap:14px">
        <span class="tw-cartmark" aria-hidden="true"><i></i><b>6</b></span>
        <span class="tw-partlist">CPU · GPU<br>MOBO · RAM<br>SSD · PSU</span>
      </div>
      <div class="tw-bento__copy">Send a whole configuration to the cart in one action.</div>
    </div>

    <div class="tw-bento__cell tw-bento__cell--pink">
      <div class="tw-bento__kicker">METRO DELIVERY</div>
      <div class="tw-hours"><b>48</b><span>HOURS</span></div>
      <div class="tw-hairline" aria-hidden="true"><i></i></div>
      <div class="tw-bento__copy">Caloocan pickup same day, Metro Manila inside two days.</div>
    </div>
  </div>
</section>
