<?php
// Customer header.php
require_once __DIR__ . '/../../../../config/functions.php';
// WEB_ROOT will be defined by config/functions.php
?>
<!-- First thing this header emits, and deliberately inline.

     Every stylesheet the customer area uses is linked from inside <body>
     (below), so until they load the document has no styling at all and the
     browser paints its default white canvas. That white frame between one
     page and the next is what reads as the app "reloading" on every click.
     A single declaration inline here is applied while the document is still
     being parsed, so the first paint is already the dark ground.

     The view-transition rules live here for the same reason: for the page
     being navigated *to*, the at-rule has to be seen before that document
     first renders. They were previously in the sidebar's stylesheet, far
     enough down the body that the browser had already begun rendering and
     skipped the transition. Chromium-only; other browsers ignore it and
     navigate exactly as before. -->
<style>
  /* The root canvas, painted before anything else. body is
     `background:transparent !important` just below, and the fixed background
     video only covers the viewport once it has decoded a frame - so on every
     navigation this colour IS the page for a moment.

     It used to be #060d18 unconditionally, which is right in dark mode and a
     black flash in light: the cross-document view transition below cross-fades
     for 140ms, so the new document's near-black root wiped across the screen
     each time you clicked a section in the sidebar. theme-light.css never
     overrode it, because nothing there targets a bare `html` background.

     data-mode is set on <html> by a blocking inline script in every page's
     <head> (all 25 customer pages carry it), so it is already correct here,
     before first paint. With JS off the attribute is absent and this falls
     back to the dark value, which is the same fallback the theme script
     itself uses. */
  html { background: #060d18; }
  html[data-mode="light"] { background: #f2f5fa; }
  @view-transition { navigation: auto; }
  ::view-transition-old(root),
  ::view-transition-new(root) { animation-duration: 140ms; }
  /* The panel is the same element either side of a navigation, so it holds
     still while only the content cross-fades. */
  #customerSidebar { view-transition-name: customer-sidebar; }
  ::view-transition-group(customer-sidebar) { animation-duration: 0s; }
  @media (prefers-reduced-motion: reduce) {
    ::view-transition-group(*),
    ::view-transition-old(*),
    ::view-transition-new(*) { animation: none !important; }
  }
</style>
<!-- Anti-FOUC theme script -->
<script>document.documentElement.setAttribute('data-theme','light');</script>
<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>

<?php if (empty($suppressSiteBgVideo)): ?>
<!-- Site background video - customer area only. Fixed behind everything
     (z-index:-2) with body's own background made transparent so it shows
     through. The source clip's raw color (saturated purple/magenta) is too
     harsh against the light UI, so it's desaturated/dimmed via filter
     rather than just hidden behind a heavy scrim again - that made it
     invisible instead of just calmer. Pages that load their own background
     video (e.g. ai_builder.php) set $suppressSiteBgVideo = true before
     including this file, so the two videos never load/play at once. -->
<style>
body{ background:transparent !important; }
.site-bg-video{ position:fixed; inset:0; width:100%; height:100%; object-fit:cover; z-index:-2; pointer-events:none; filter:saturate(.4) brightness(.85) contrast(.92); }
.site-bg-video-scrim{ position:fixed; inset:0; z-index:-1; pointer-events:none; background:rgba(242,243,244,.35); }
@media (prefers-reduced-motion: reduce){ .site-bg-video{ display:none; } }

/* --- the flash on navigation -------------------------------------------
   Each page is its own document, so this <video> is re-created and re-
   decoded on every click. Until it has a frame, the root colour IS the
   page - which is why light mode showed a flat panel that then jumped to
   the clip. Two changes remove it:

   1. LIGHT MODE DOES NOT SHOW THE CLIP AT ALL. It was already dimmed to
      near-nothing there - saturate(.4) brightness(.85) under a 35% light
      scrim - and the comment above concedes the raw colour is "too harsh
      against the light UI". With it gone the ground is a stable #f2f5fa on
      every page, so there is nothing left to flash. The script below also
      never downloads the 4MB clip in light mode.

   2. In DARK mode it fades in rather than popping, so the moment it decodes
      is a reveal rather than a cut. */
html[data-mode="light"] .site-bg-video,
html[data-mode="light"] .site-bg-video-scrim{ display:none; }
.site-bg-video{ opacity:0; transition:opacity .4s ease; }
.site-bg-video.is-ready{ opacity:1; }
@media (prefers-reduced-motion: reduce){ .site-bg-video{ transition:none; } }
</style>
<!-- autoplay/preload are deliberately absent: the script below decides
     whether this is wanted at all before a byte is fetched. -->
<video class="site-bg-video" muted loop playsinline preload="none" aria-hidden="true">
  <source src="<?= WEB_ROOT ?>public/shared/assets/video/site-background.mp4" type="video/mp4">
</video>
<div class="site-bg-video-scrim"></div>
<script>
(function () {
  var v = document.querySelector('.site-bg-video');
  if (!v) { return; }
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function start() {
    // Light mode never loads it; reduced-motion already hides it in CSS, so
    // fetching 4MB for a display:none element would be pure waste.
    if (reduced) { return; }
    if (document.documentElement.getAttribute('data-mode') === 'light') { return; }
    if (v.dataset.started) { return; }
    v.dataset.started = '1';
    v.preload = 'auto';
    v.addEventListener('canplay', function () {
      v.classList.add('is-ready');
      var p = v.play();
      if (p && p.catch) { p.catch(function () {}); }   // autoplay policy
    }, { once: true });
    v.load();
  }
  start();
  // Switching to dark from the theme control should still bring it in,
  // without waiting for the next navigation.
  if (window.MutationObserver) {
    new MutationObserver(start).observe(document.documentElement,
      { attributes: true, attributeFilter: ['data-mode'] });
  }
})();
</script>
<?php endif; ?>

<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
<!-- Dark re-theme for the customer area. Must come after style.css: it wins
     on source order where specificity ties, and carries explicit counters for
     that file's [data-theme="light"] !important blocks. It guards itself
     against the two pages that paint their own ground (.tw-home landing,
     .aib-wrap AI Builder), so including it here is safe for every page that
     uses this header. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/css/customer-dark.css') ?>">
<link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
<link rel="preconnect" href="https://cdn-uicons.flaticon.com" crossorigin>
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
<!-- defer: this is 37KB and sat mid-body with no attribute, so the parser
     stopped dead here and nothing below it - sidebar included - could be
     built until it had downloaded and run. Everything it does is already
     inside DOMContentLoaded handlers, and the globals it defines are only
     reached from onclick attributes, so deferring changes no behaviour. -->
<script src="<?= asset_url('public/shared/assets/js/main.js') ?>" defer></script>

<!-- No top <header> on customer pages anymore. Logo/name, search, and
     notifications used to live here but all three were removed per request
     - search moved into the sidebar (above Home, see includes/
     sidebar.php), the TechWorld brand is already shown in the sidebar too,
     and notifications already have their own sidebar nav item with an
     unread badge, so nothing here was actually lost. It was kept for a
     while as an empty spacer bar (the landing page's category section used
     to measure its height for a min-height calc, and the sidebar's pull-
     tab toggle button aligned to it), but that's gone now too - the
     category section's calc no longer factors in a header height (see
     .th-cat-panel in customer-home.css) and the toggle button falls back
     to its CSS default position, same as it already does on every other
     customer page that never had this header to begin with. -->

<?php if (function_exists('isLoggedIn') && isLoggedIn()):
    // Still needed here for the floating cart button below - this used to
    // be computed alongside the notification bell markup that got removed
    // from the header, but the cart button itself (unrelated to that
    // removal) still reads it.
    $uid = function_exists('getUserId') ? getUserId() : ($_SESSION['user_id'] ?? null);
    $cartCount = 0;
    if ($uid) {
        try {
            $pdo = getConnection();
            $stmt = $pdo->prepare("SELECT SUM(quantity) FROM user_carts WHERE user_id = ?");
            $stmt->execute([$uid]);
            $cartCount = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $cartCount = 0;
        }
    }
?>
<style>
.floating-cart-btn{position:fixed;right:22px;bottom:26px;width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#8B4C6C,#0A1F33);color:#0b1220;font-size:22px;box-shadow:0 10px 28px rgba(139,76,108,.35),0 4px 10px rgba(0,0,0,.35);z-index:1500;text-decoration:none;opacity:0;transform:scale(.4) translateY(20px);pointer-events:none;transition:transform 320ms cubic-bezier(0.34,1.56,0.64,1),opacity 220ms ease,box-shadow 200ms ease}
.floating-cart-btn.show{opacity:1;transform:scale(1) translateY(0);pointer-events:auto}
.floating-cart-btn:hover{box-shadow:0 14px 34px rgba(139,76,108,.5),0 6px 14px rgba(0,0,0,.4)}
.floating-cart-btn.pop{animation:floatingCartPop 420ms cubic-bezier(0.34,1.56,0.64,1)}
@keyframes floatingCartPop{0%{transform:scale(1)}35%{transform:scale(1.22)}100%{transform:scale(1)}}
/* GIF ships with a solid white square canvas around the glyph - mix-blend-mode
   multiply drops the white so only the black/teal linework shows on top of
   the circular badge behind it, instead of a visible white box. */
.floating-cart-icon{width:38px;height:38px;object-fit:contain;mix-blend-mode:multiply}
.floating-cart-badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#E60026;color:#fff;font-size:11px;line-height:20px;text-align:center;font-weight:700;border:2px solid rgba(11,18,32,.95)}
@media (prefers-reduced-motion: reduce){.floating-cart-btn,.floating-cart-btn.pop{transition-duration:.01ms!important;animation-duration:.01ms!important}}
</style>
<?php /* Always rendered shown. It used to carry .show only when the cart had
         something in it, so an empty cart meant no cart button at all - which
         reads as the control having gone missing rather than as "your cart is
         empty", and leaves no way in to a page that also holds saved-for-later
         items. The BADGE still appears only when there is a count. */ ?>
<a href="<?= WEB_ROOT ?>public/authenticated/customer/cart.php" class="floating-cart-btn show" id="floatingCartBtn" title="View Cart">
  <img class="floating-cart-icon" src="<?= WEB_ROOT ?>public/shared/img/icons/shopping-cart.gif" alt="">
  <span class="floating-cart-badge" id="cartCount" style="display: <?= ($cartCount > 0 ? 'inline-flex' : 'none') ?>;"><?= (int)$cartCount ?></span>
</a>
<?php endif; ?>
<?php /* In-page confirm()/alert() replacements - see the file itself for
         how to use data-confirm and uiConfirm(). Included here so every page
         in this area gets them without a per-page include. */ ?>
<?php include_once __DIR__ . '/../../../shared/includes/ui-dialog.php'; ?>
