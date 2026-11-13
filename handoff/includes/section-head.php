<?php
/* includes/section-head.php
   The repeating CENTERED section head: numbered mono eyebrow + gradient
   rule, heading, optional subheading.

   Call it as a function so each section is one line:

     tw_section_head('01', 'THE CATALOG', 'Explore computer components',
                     "Pick a category the way you'd pick a game.");

   Pass $html = true on the title to allow the <em> gradient span:
     tw_section_head('03', 'THE CONFIGURATOR', 'Build smarter <em>with AI</em>', null, true);
*/
if (!function_exists('tw_section_head')) {
  function tw_section_head($num, $kicker, $title, $sub = null, $titleIsHtml = false) {
    ?>
    <div class="tw-head">
      <div class="tw-head__eyebrow">
        <b><?= htmlspecialchars($num) ?> &mdash; <?= htmlspecialchars($kicker) ?></b>
        <span class="tw-head__rule" aria-hidden="true"></span>
      </div>
      <h2><?= $titleIsHtml ? $title : htmlspecialchars($title) ?></h2>
      <?php if ($sub): ?><p><?= htmlspecialchars($sub) ?></p><?php endif; ?>
    </div>
    <?php
  }
}
