<?php
/* includes/ai-configurator.php
   The page's focal section: full-bleed band, mock session on the left,
   generated build with running total on the right.

   It breaks out of .tw-main's gutter with negative margins, so include it
   as a direct child of <main class="tw-main"> — not inside .tw-wrap.

   Optional before including:
     $aiPrompt, $aiReply  – the two chat bubbles
     $aiChecks – ['k' =>, 'v' =>, 'w' => '72%']
     $aiBuild  – ['k' =>, 'v' =>, 'p' =>]
     $aiTotal, $aiMatch, $aiUnder, $aiDraw, $aiName
*/
require_once __DIR__ . '/section-head.php';

$aiPrompt = $aiPrompt ?? '₱35,000 budget. 1080p gaming plus Premiere Pro edits, quiet if possible.';
$aiReply  = $aiReply  ?? 'Editing likes cores, gaming likes clocks — a Ryzen 7 with 32GB and a 1440p-capable GPU splits the difference. Here\'s the build:';
$aiName   = $aiName   ?? 'Creator / 1080p Hybrid';
$aiMatch  = $aiMatch  ?? '98% MATCH';
$aiTotal  = $aiTotal  ?? '₱34,780';
$aiUnder  = $aiUnder  ?? '₱220 UNDER BUDGET';
$aiDraw   = $aiDraw   ?? 'EST. 412W DRAW';

$aiChecks = $aiChecks ?? [
  ['k' => 'Socket & chipset', 'v' => 'AM4 / B550', 'w' => '100%'],
  ['k' => 'PSU headroom',     'v' => '+138W',      'w' => '72%'],
  ['k' => 'Case clearance',   'v' => '162mm OK',   'w' => '88%'],
  ['k' => 'Thermal budget',   'v' => '68°C peak',  'w' => '64%'],
];
$aiBuild = $aiBuild ?? [
  ['k' => 'CPU',  'v' => 'Ryzen 7 5700G',    'p' => '₱9,480'],
  ['k' => 'MOBO', 'v' => 'B550M-A Pro',      'p' => '₱5,200'],
  ['k' => 'RAM',  'v' => '32GB DDR4 3200',   'p' => '₱4,100'],
  ['k' => 'GPU',  'v' => 'RTX 3050 8GB',     'p' => '₱11,500'],
  ['k' => 'SSD',  'v' => '1TB NVMe Gen3',    'p' => '₱2,800'],
  ['k' => 'PSU',  'v' => '550W 80+ Bronze',  'p' => '₱1,700'],
];
$partCount = count($aiBuild);
?>
<section class="tw-band" id="ai">
  <span class="tw-band__glow-a" aria-hidden="true"></span>
  <span class="tw-band__glow-b" aria-hidden="true"></span>

  <div class="tw-band__inner">
    <?php tw_section_head('03', 'THE CONFIGURATOR', 'Build smarter <em>with AI</em>', null, true); ?>
    <p class="tw-band__lede">Tell it a budget and a workload. It returns a compatible parts list with thermals, wattage and bottlenecks already checked.</p>

    <div class="tw-ai">

      <div class="tw-chatcard">
        <div class="tw-chatcard__bar">
          <span>SESSION · LIVE</span>
          <span class="tw-live"><i aria-hidden="true"></i>ONLINE</span>
        </div>

        <div class="tw-bubble tw-bubble--me"><?= htmlspecialchars($aiPrompt) ?></div>
        <div class="tw-bubble tw-bubble--ai"><?= htmlspecialchars($aiReply) ?></div>
        <div class="tw-typing" aria-hidden="true"><i></i><i></i><i></i></div>

        <div class="tw-checks">
          <div class="tw-checks__t">COMPATIBILITY CHECKS</div>
          <?php foreach ($aiChecks as $c): ?>
            <div>
              <div class="tw-check__row">
                <span><span class="tw-tick" aria-hidden="true">&#10003;</span><?= htmlspecialchars($c['k']) ?></span>
                <span class="tw-check__val"><?= htmlspecialchars($c['v']) ?></span>
              </div>
              <div class="tw-meter"><i style="width: <?= htmlspecialchars($c['w']) ?>"></i></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="tw-buildframe">
        <div class="tw-build">
          <div class="tw-build__head">
            <div>
              <div class="tw-build__kicker">GENERATED BUILD</div>
              <div class="tw-build__name"><?= htmlspecialchars($aiName) ?></div>
            </div>
            <div class="tw-match"><?= htmlspecialchars($aiMatch) ?></div>
          </div>

          <div class="tw-parts">
            <?php foreach ($aiBuild as $b): ?>
              <div class="tw-part">
                <span class="tw-part__k"><?= htmlspecialchars($b['k']) ?></span>
                <span class="tw-part__v"><?= htmlspecialchars($b['v']) ?></span>
                <span class="tw-part__p"><?= htmlspecialchars($b['p']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="tw-build__foot">
            <div>
              <div class="tw-build__kicker">TOTAL · <?= $partCount ?> PARTS</div>
              <div class="tw-total"><?= htmlspecialchars($aiTotal) ?></div>
            </div>
            <div style="text-align:right">
              <div class="tw-note"><?= htmlspecialchars($aiUnder) ?></div>
              <div class="tw-note tw-note--mute"><?= htmlspecialchars($aiDraw) ?></div>
            </div>
          </div>

          <form class="tw-build__btns" method="post" action="cart-add-build.php">
            <button class="tw-btn-addall" type="submit">
              Add all <?= $partCount ?> parts to cart
              <span class="tw-shine" aria-hidden="true"></span>
            </button>
            <button class="tw-btn-tweak" type="button">TWEAK</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
