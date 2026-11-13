<?php
// index.php — Customer homepage mirroring guest homepage, protected
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once '../../../config/functions.php';
require_login();

$pageTitle = 'TechWorld - Home';
$activeNav = 'home';
$userId = getUserId();

// This page paints its own dark neon ground (techworld-anim.css) - suppress
// the customer-area site background video so it doesn't sit behind it.
$suppressSiteBgVideo = true;

// Load persistent cart from DB into session if available
if ($userId && function_exists('loadCartFromDb')) {
    if (!isset($_SESSION['cart'][$userId]) || empty($_SESSION['cart'][$userId])) {
        $dbCart = loadCartFromDb($userId);
        if (!empty($dbCart)) {
            $_SESSION['cart'][$userId] = $dbCart;
        }
    }
}

// Promotional carousel modal. Shown once per login session: the flag below is
// set the first time it renders, so refreshing the homepage or coming back to
// it later in the same session won't pop it again. Signing out clears the
// session, so the next login shows it afresh. Rows come from the same
// promotional_images table the admin Promotional Carousel page manages.
$promoSlides = [];
$showPromoModal = false;
if (empty($_SESSION['promo_carousel_seen'])) {
    try {
        require_once __DIR__ . '/../../shared/classes/PromotionalImages.php';
        $promoSlides = (new PromotionalImages())->getActiveImages();
    } catch (Throwable $e) {
        // A missing table or a DB hiccup must never block the homepage - the
        // modal just doesn't appear.
        error_log('Promotional carousel fetch error: ' . $e->getMessage());
        $promoSlides = [];
    }
    if (!empty($promoSlides)) {
        $showPromoModal = true;
        $_SESSION['promo_carousel_seen'] = true;
    }
}
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

  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TechWorld - Home</title>

  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico">
  <link rel="shortcut icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico">

  <!-- Icon packs -->
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">

  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <!-- Restored: defines every .th-* rule (hero, category carousel, AI panel,
       product cards). ~60 .th-* elements are still in this page's markup, so
       dropping this link left all of them unstyled. Linked BEFORE
       techworld-anim.css so the newer .tw-* rules still win on source order. -->
  <link rel="stylesheet" href="<?= asset_url('public/shared/css/customer-home.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('public/shared/css/th-hero-anim.css') ?>">

  <!-- Neon "gaming rig" landing redesign: Figtree everywhere, JetBrains Mono
       for eyebrows / prices / stat + small-caps labels. Linked (not @import)
       so the sheet stays link/script-tags only. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <!-- Linked LAST so its .tw-* rules win on source order over style.css. -->
  <link rel="stylesheet" href="<?= asset_url('public/shared/assets/css/techworld-anim.css') ?>">

  <style>
  /* Enhanced Product Card Styles for Customer Index */
  .price-stock-section {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      gap: 15px;
      position: relative;
  }

  .product-price {
      flex: 1;
  }

  /* Calm, solid price color - was a glowing gradient-clipped text effect
     repeated on every product card, which read as harsh/painful rather
     than premium. Plain, readable, matches the shared style.css treatment. */
  .current-price {
      color: var(--accent-cyan);
      font-size: 24px;
      font-weight: 800;
      display: block;
  }

  /* Auto shrink for long prices */
  .current-price.small { font-size: 20px; }
  .current-price.xs { font-size: 18px; }
  .current-price.xxs { font-size: 16px; }

  .original-price {
      color: var(--text-muted);
      font-size: 16px;
      text-decoration: line-through;
      margin-left: 8px;
      opacity: 0.8;
  }

  .stock-indicator {
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px; /* smaller to keep on one line */
      font-weight: 700;
      padding: 6px 14px;
      border-radius: 50px;
      white-space: nowrap;
      position: relative;
      overflow: hidden;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      box-shadow: 0 4px 15px rgba(var(--tw-shadow-rgb, 0, 0, 0), 0.25), inset 0 1px 0 var(--border-color);
      min-height: 28px;
      border: 2px solid transparent;
      backdrop-filter: blur(10px);
      text-shadow: 0 1px 2px rgba(var(--tw-shadow-rgb, 0, 0, 0), 0.3);
      text-align: center;
      max-width: 100%;
  }

  .stock-indicator::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(var(--tw-veil-rgb, 255, 255, 255), 0.3), transparent);
      transition: left 0.6s ease-in-out;
  }

  .stock-indicator::after {
      content: '';
      position: absolute;
      top: 2px;
      left: 2px;
      right: 2px;
      bottom: 2px;
      border-radius: 50px;
      background: linear-gradient(45deg, var(--border-color), transparent, rgba(var(--tw-veil-rgb, 255, 255, 255), 0.05));
      pointer-events: none;
  }

  .stock-indicator:hover::before {
      left: 100%;
  }

  .stock-indicator i {
      margin-right: 6px;
      font-size: 12px;
      filter: drop-shadow(0 0 4px currentColor) drop-shadow(0 1px 2px rgba(var(--tw-shadow-rgb, 0, 0, 0), 0.5));
      position: relative;
      z-index: 2;
  }

  .stock-available {
      background: transparent;
      color: #00bfff;
      border: none;
      animation: tech-pulse 4s ease-in-out infinite;
      box-shadow: none;
  }

  .stock-low {
      background: transparent;
      color: #00bfff;
      border: none;
      animation: pulse-warning 2s infinite, tech-scan 3s linear infinite;
      position: relative;
      box-shadow: none;
  }

  /* Was a bright red gradient pill wearing the shared .stock-indicator's
     own box-shadow/border/::before/::after (a hover shimmer sweep + a
     diagonal-gradient inset overlay meant for a flat-color badge) PLUS a
     growing/shrinking glow animation on top - stacked together they read
     as an outer glowing ring around a harder-edged inner rectangle rather
     than one badge. Self-contained now: a single flat translucent-red-on-
     navy fill, no animation, and the shared pseudo-elements switched off
     for this variant specifically (.stock-available/.stock-low keep them
     unchanged - this only targets .stock-out). */
  .stock-out {
      background: rgba(230, 0, 38, 0.14);
      color: #ffb3c1;
      border: 1px solid rgba(230, 0, 38, 0.45);
      animation: none;
      position: relative;
      box-shadow: 0 4px 12px rgba(230, 0, 38, 0.15);
  }
  .stock-out::before,
  .stock-out::after {
      display: none;
  }

  @keyframes glow-blue {
      0% { 
          box-shadow: 0 4px 15px rgba(23, 72, 113, 0.4), inset 0 1px 0 rgba(var(--tw-veil-rgb, 255, 255, 255), 0.2);
          border-color: #a77693;
      }
      100% { 
          box-shadow: 0 6px 25px rgba(23, 72, 113, 0.7), inset 0 1px 0 rgba(var(--tw-veil-rgb, 255, 255, 255), 0.3);
          border-color: #c9a3b5;
      }
  }

  @keyframes glow-red {
      0% { 
          box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4), inset 0 1px 0 rgba(var(--tw-veil-rgb, 255, 255, 255), 0.2);
          border-color: #f87171;
      }
      100% { 
          box-shadow: 0 6px 25px rgba(239, 68, 68, 0.7), inset 0 1px 0 rgba(var(--tw-veil-rgb, 255, 255, 255), 0.3);
          border-color: #fca5a5;
      }
  }

  @keyframes tech-pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.02); }
  }

  @keyframes tech-scan {
      0% { background-position: -200% 0; }
      100% { background-position: 200% 0; }
  }

  @keyframes tech-warning {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.8; }
  }

  @keyframes pulse-warning {
      0%, 100% { 
          transform: scale(1);
          opacity: 1;
      }
      50% { 
          transform: scale(1.05);
          opacity: 0.9;
      }
  }

  @keyframes flash {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.3; }
  }

  .product-actions {
    display: flex;
    gap: 12px;
    margin-top: 15px;
  }

  .add-to-cart-btn,
  .buy-now-btn,
  .wishlist-action-btn {
      flex: 1;
      padding: 10px 8px;
      border: none;
      border-radius: 8px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      text-decoration: none;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      position: relative;
      overflow: hidden;
  }

  .add-to-cart-btn::before,
  .buy-now-btn::before,
  .wishlist-action-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(var(--tw-veil-rgb, 255, 255, 255), 0.2), transparent);
      transition: left 0.6s;
  }

  .add-to-cart-btn:hover::before,
  .buy-now-btn:hover::before,
  .wishlist-action-btn:hover::before {
      left: 100%;
  }

  .add-to-cart-btn {
      background: linear-gradient(135deg, var(--accent-cyan-bg-20), var(--accent-cyan-bg));
      color: var(--accent-cyan);
      border: 2px solid var(--accent-cyan);
      box-shadow: 0 4px 15px var(--accent-cyan-bg-20);
  }

  .add-to-cart-btn:hover {
      background: linear-gradient(135deg, var(--accent-cyan), #c99bb0);
      color: white;
      transform: translateY(-2px) scale(1.02);
      box-shadow: 0 6px 15px var(--accent-cyan-bg-30);
  }

  .buy-now-btn {
      background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
      color: white;
      border: 2px solid transparent;
      box-shadow: 0 4px 15px rgba(23, 72, 113, 0.3);
      position: relative;
      transition: transform var(--duration-fast, 150ms) var(--ease-out), box-shadow var(--duration-fast, 150ms) var(--ease-out), background var(--duration-fast, 150ms) var(--ease-out);
  }

  .buy-now-btn:hover {
      background: linear-gradient(135deg, var(--accent-blue), #0f2d4d);
      transform: translateY(-2px) scale(1.02);
      box-shadow: 0 8px 20px rgba(23, 72, 113, 0.4);
  }

  .buy-now-btn:active {
      transform: scale(0.97);
  }

  /* Override global .buy-now so it matches the compact buttons here */
  .product-actions .buy-now {
      flex: 1;
      padding: 10px 8px;
      border: none;
      border-radius: 8px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      transition: transform var(--duration-fast, 150ms) var(--ease-out), box-shadow var(--duration-fast, 150ms) var(--ease-out), background var(--duration-fast, 150ms) var(--ease-out);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      text-decoration: none;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      position: relative;
      overflow: hidden;
      background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
      color: #fff;
      box-shadow: 0 4px 15px rgba(23, 72, 113, 0.3);
  }
  .product-actions .buy-now::before { content:''; position:absolute; inset:0; left:-100%; background:linear-gradient(90deg,transparent,rgba(var(--tw-veil-rgb, 255, 255, 255), .2),transparent); transition:left .6s; }
  .product-actions .buy-now:hover::before { left:100%; }
  .product-actions .buy-now:hover {
      transform: translateY(-2px) scale(1.02);
      background: linear-gradient(135deg, var(--accent-blue), #0f2d4d);
      box-shadow: 0 8px 20px rgba(23, 72, 113, 0.4);
  }
  .product-actions .buy-now:active {
      transform: scale(0.97);
  }

  /* Ensure consistent heights across action buttons */
  .product-actions .add-to-cart-btn,
  .product-actions .buy-now,
  .product-actions .wishlist-action-btn { min-height: 40px; }

  .wishlist-action-btn {
      background: linear-gradient(135deg, var(--accent-cyan-bg), var(--bg-card-alpha-80));
      color: var(--accent-cyan);
      border: 1px solid var(--accent-cyan);
      width: 100%;
      box-shadow: 0 2px 8px rgba(167, 118, 147, 0.15);
      position: relative;
  }

  .wishlist-action-btn:hover {
      background: linear-gradient(135deg, var(--accent-cyan-bg-20), rgba(30, 41, 59, 0.9));
      color: var(--accent-cyan);
      transform: translateY(-2px) scale(1.02);
      box-shadow: 0 6px 15px var(--accent-cyan-bg-30);
  }

  .wishlist-action-btn i {
      font-size: 12px;
      filter: drop-shadow(0 0 2px currentColor);
  }

  .installment-badge {
      background: linear-gradient(45deg, #10b981, #06d6a0);
      color: white;
      padding: 6px 12px;
      border-radius: 15px;
      font-size: 10px;
      font-weight: bold;
      display: inline-block;
      margin-bottom: 15px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
  }
  </style>
</head>
<body>
  <!-- Animated background: three fixed, non-interactive layers behind all
       content (.tw-home is the position:relative / z-index:1 wrapper). -->
  <div class="tw-bg tw-bg-grid" aria-hidden="true"></div>
  <div class="tw-bg" aria-hidden="true">
    <div class="tw-bg-blob tw-bg-blob-pink"></div>
    <div class="tw-bg-blob tw-bg-blob-cyan"></div>
  </div>

  <?php include __DIR__ . '/includes/header.php'; ?>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <?php if ($showPromoModal): ?>
  <!-- Promotional carousel. Restored from the pre-redesign homepage, rebuilt on
       the current neon palette. Renders only on the first homepage view of a
       login session (see $showPromoModal above), so it never nags on refresh. -->
  <style>
    /* The .tw-* palette lives on .tw-home and this dialog is a sibling of it,
       so the handful of hues it needs are re-declared locally rather than
       inherited. Same values as techworld-anim.css. */
    .promo-modal{
      --pm-line:rgba(120, 170, 230, .16);
      --pm-ink:#e8eef7; --pm-ink-dim:#a9bcd4;
      --pm-cyan:#67e8f9; --pm-pink:#e0567a;
      position:fixed; inset:0; z-index:3000; display:none;
      align-items:center; justify-content:center; padding:24px;
      /* No scrim - the homepage keeps its colour and shows straight through.
         backdrop-filter alone blurs whatever sits behind the overlay, so the
         page reads as out-of-focus depth rather than a dimmed sheet. */
      background:transparent; backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px);
      font-family:'Figtree',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
    }
    .promo-modal.is-open{display:flex; animation:promoFade .35s ease both;}
    @keyframes promoFade{from{opacity:0}to{opacity:1}}

    .promo-dialog{
      position:relative; width:min(1100px,100%); max-height:88vh;
      display:flex; flex-direction:column; overflow:hidden;
      /* No fill of its own - .promo-bar and .promo-foot are left unpainted so
         the blurred homepage shows through them; only the image stage below is
         opaque. Border and radius are the page's own (--tw-line / --tw-radius),
         which is all that's needed to read as one of its panels. */
      background:transparent;
      border:1px solid rgba(var(--tw-line-rgb, 120, 170, 230), .16); border-radius:18px;
      box-shadow:0 40px 90px rgba(var(--tw-shadow-rgb, 0, 0, 0), .45);
      animation:promoRise .45s cubic-bezier(.2,.85,.2,1) both;
    }
    @keyframes promoRise{from{opacity:0;transform:translateY(18px) scale(.97)}to{opacity:1;transform:none}}
    @media (prefers-reduced-motion: reduce){
      .promo-modal.is-open,.promo-dialog{animation:none;}
    }

    /* Eyebrow strip: label on the left, slide counter on the right. */
    /* Transparent by design: this strip is a window onto the blurred page. */
    .promo-bar{
      display:flex; align-items:center; gap:10px; background:transparent;
      padding:14px 18px 12px; border-bottom:1px solid var(--pm-line);
    }
    .promo-eyebrow{
      font-family:'JetBrains Mono',ui-monospace,Consolas,monospace;
      font-size:10.5px; letter-spacing:.28em; text-transform:uppercase;
      color:var(--tw-accent, #ff9ab5); margin:0;
    }
    /* The little gradient rule the page puts before every section eyebrow. */
    .promo-rule{width:46px; height:2px; border-radius:2px; background:linear-gradient(90deg,var(--pm-pink),var(--pm-cyan));}

    /* Stage. Fixed aspect so slides of different pixel sizes don't make the
       dialog jump height as the carousel advances. */
    .promo-stage{position:relative; aspect-ratio:16/9; background:var(--tw-bg, #060d18); overflow:hidden;}
    .promo-slide{
      position:absolute; inset:0; opacity:0; visibility:hidden;
      transition:opacity .55s ease, visibility .55s;
      display:flex; align-items:center; justify-content:center;
    }
    .promo-slide.is-active{opacity:1; visibility:visible;}
    .promo-slide img{width:100%; height:100%; object-fit:contain; display:block;}

    /* Caption sits over the foot of the image; the gradient keeps the text
       legible whatever the artwork behind it is. */
    .promo-caption{
      position:absolute; left:0; right:0; bottom:0; padding:44px 22px 18px;
      background:linear-gradient(180deg,transparent,rgba(4,9,17,.9));
      text-align:left; pointer-events:none;
    }
    .promo-caption h3{margin:0 0 4px; font-size:19px; font-weight:800; color:var(--pm-ink);}
    .promo-caption p{margin:0; font-size:13.5px; line-height:1.45; color:var(--pm-ink-dim);}

    .promo-nav{
      position:absolute; top:50%; transform:translateY(-50%);
      width:42px; height:42px; border-radius:50%; cursor:pointer;
      display:grid; place-items:center; font-size:17px;
      background:rgba(6,13,24,.66); color:var(--pm-ink);
      border:1px solid var(--pm-line); backdrop-filter:blur(8px);
      transition:background .2s ease, border-color .2s ease, transform .2s ease;
    }
    .promo-nav:hover{background:rgba(var(--tw-cyan-rgb, 103, 232, 249), .18); border-color:var(--pm-cyan); transform:translateY(-50%) scale(1.06);}
    .promo-nav--prev{left:14px;} .promo-nav--next{right:14px;}

    .promo-close{
      position:absolute; top:10px; right:12px; width:34px; height:34px;
      border-radius:50%; cursor:pointer; display:grid; place-items:center;
      font-size:20px; line-height:1; background:rgba(6,13,24,.66); color:var(--pm-ink-dim);
      border:1px solid var(--pm-line); transition:color .2s ease, border-color .2s ease, background .2s ease;
    }
    .promo-close:hover{color:var(--pm-ink); border-color:var(--pm-pink); background:rgba(var(--tw-pink-rgb, 224, 86, 122), .2);}

    /* Transparent for the same reason as .promo-bar. */
    .promo-foot{
      display:flex; align-items:center; justify-content:space-between; gap:16px;
      background:transparent; padding:12px 18px 14px; border-top:1px solid var(--pm-line);
    }
    .promo-dots{display:flex; gap:8px;}
    .promo-dot{
      width:8px; height:8px; padding:0; border-radius:50%; cursor:pointer;
      background:rgba(169,188,212,.32); border:none;
      transition:background .2s ease, transform .2s ease, box-shadow .2s ease;
    }
    .promo-dot:hover{background:rgba(var(--tw-cyan-rgb, 103, 232, 249), .6);}
    .promo-dot.is-active{background:var(--pm-cyan); transform:scale(1.25); box-shadow:0 0 12px rgba(var(--tw-cyan-rgb, 103, 232, 249), .5);}
    .promo-shop{
      text-decoration:none; font-size:13px; font-weight:700; padding:9px 18px;
      border-radius:999px; color:#fff; white-space:nowrap;
      background:linear-gradient(90deg,var(--pm-pink),var(--tw-magenta, #c2185b));
      box-shadow:0 8px 20px rgba(var(--tw-pink-rgb, 224, 86, 122), .28); transition:filter .2s ease, transform .2s ease;
    }
    .promo-shop:hover{filter:brightness(1.08); transform:translateY(-1px); color:#fff; text-decoration:none;}

    @media (max-width:640px){
      .promo-modal{padding:12px;}
      .promo-stage{aspect-ratio:4/3;}
      .promo-nav{width:36px; height:36px;}
      .promo-caption{padding:34px 16px 14px;}
      .promo-caption h3{font-size:16px;}
      .promo-caption p{font-size:12.5px;}
      .promo-foot{flex-direction:column; align-items:stretch; gap:10px;}
      .promo-dots{justify-content:center;}
      .promo-shop{text-align:center;}
    }
  </style>

  <div class="promo-modal" id="promoModal" role="dialog" aria-modal="true" aria-label="Current promotions">
    <div class="promo-dialog">
      <div class="promo-bar">
        <span class="promo-rule" aria-hidden="true"></span>
        <p class="promo-eyebrow">Featured Promotions</p>
      </div>

      <div class="promo-stage">
        <?php foreach ($promoSlides as $i => $slide): ?>
          <div class="promo-slide<?= $i === 0 ? ' is-active' : '' ?>">
            <img src="<?= WEB_ROOT . htmlspecialchars((string)$slide['image_path']) ?>"
                 alt="<?= htmlspecialchars((string)($slide['alt_text'] ?? $slide['title'] ?? '')) ?>"
                 <?= $i === 0 ? '' : 'loading="lazy"' ?>>
            <?php if (!empty($slide['title']) || !empty($slide['description'])): ?>
              <div class="promo-caption">
                <?php if (!empty($slide['title'])): ?><h3><?= htmlspecialchars((string)$slide['title']) ?></h3><?php endif; ?>
                <?php if (!empty($slide['description'])): ?><p><?= htmlspecialchars((string)$slide['description']) ?></p><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if (count($promoSlides) > 1): ?>
          <button type="button" class="promo-nav promo-nav--prev" data-promo-step="-1" aria-label="Previous promotion"><i class="fi fi-bs-angle-left"></i></button>
          <button type="button" class="promo-nav promo-nav--next" data-promo-step="1" aria-label="Next promotion"><i class="fi fi-bs-angle-right"></i></button>
        <?php endif; ?>
        <button type="button" class="promo-close" id="promoClose" aria-label="Close promotions">&times;</button>
      </div>

      <div class="promo-foot">
        <div class="promo-dots">
          <?php foreach ($promoSlides as $i => $slide): ?>
            <button type="button" class="promo-dot<?= $i === 0 ? ' is-active' : '' ?>" data-promo-go="<?= $i ?>" aria-label="Go to promotion <?= $i + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
        <a class="promo-shop" href="<?= WEB_ROOT ?>public/authenticated/customer/products.php">Shop Products</a>
      </div>
    </div>
  </div>

  <script>
  (function () {
    var modal = document.getElementById('promoModal');
    if (!modal) return;

    var slides = modal.querySelectorAll('.promo-slide');
    var dots = modal.querySelectorAll('.promo-dot');
    var index = 0;
    var timer = null;
    // The carousel advances on its own; the arrows and dots just jump ahead of
    // it. Anyone who has asked for less motion keeps the manual controls only.
    var autoplayOk = slides.length > 1 &&
      !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function show(next) {
      index = (next + slides.length) % slides.length;
      for (var i = 0; i < slides.length; i++) {
        slides[i].classList.toggle('is-active', i === index);
        if (dots[i]) { dots[i].classList.toggle('is-active', i === index); }
      }
    }

    // Restarted rather than left running after a manual jump, so the slide the
    // viewer just picked gets a full interval before it moves on.
    function restartAutoplay() {
      if (timer) { clearInterval(timer); timer = null; }
      if (autoplayOk) { timer = setInterval(function () { show(index + 1); }, 5000); }
    }

    function openModal() {
      modal.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      restartAutoplay();
    }

    function closeModal() {
      modal.classList.remove('is-open');
      document.body.style.overflow = '';
      if (timer) { clearInterval(timer); timer = null; }
    }

    modal.addEventListener('click', function (e) {
      var step = e.target.closest('[data-promo-step]');
      var go = e.target.closest('[data-promo-go]');
      if (step) { show(index + parseInt(step.getAttribute('data-promo-step'), 10)); restartAutoplay(); return; }
      if (go) { show(parseInt(go.getAttribute('data-promo-go'), 10)); restartAutoplay(); return; }
      if (e.target.closest('#promoClose') || e.target === modal) { closeModal(); }
    });

    document.addEventListener('keydown', function (e) {
      if (!modal.classList.contains('is-open')) return;
      if (e.key === 'Escape') { closeModal(); }
      else if (e.key === 'ArrowLeft') { show(index - 1); restartAutoplay(); }
      else if (e.key === 'ArrowRight') { show(index + 1); restartAutoplay(); }
    });

    // Short beat after load so the homepage paints first.
    setTimeout(openModal, 700);
  })();
  </script>
  <?php endif; ?>


  <div class="tw-home">

  <?php
    // Fetch products for the homepage - get the same products as products page.
    // Moved to run before the hero (was previously further down the file) so
    // the hero's floating price strip can show real SKUs/prices - same
    // queries, same variables, just executed earlier since PHP has no
    // rendering side effects until it echoes something.
    $desktops = [];
    $laptops = [];
    try {
        $pdo = getConnection();

        // Get desktop products (category name 'Desktops')
        $stmt_desktops = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p
                                       LEFT JOIN categories c ON p.category_id = c.id
                                       WHERE c.name = 'Desktops' AND p.status = 'active'
                                       ORDER BY p.created_at DESC LIMIT 4");
        $stmt_desktops->execute();
        $desktops = $stmt_desktops->fetchAll();

        // Get laptop products (category name 'Laptops') - id 513 (Lenovo
        // IdeaPad 5 14IHU5) is excluded from this homepage feature grid only
        // (still fully purchasable via the normal catalog/search).
        $stmt_laptops = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p
                                      LEFT JOIN categories c ON p.category_id = c.id
                                      WHERE c.name = 'Laptops' AND p.status = 'active' AND p.id != 513
                                      ORDER BY p.created_at DESC LIMIT 4");
        $stmt_laptops->execute();
        $laptops = $stmt_laptops->fetchAll();

        // If no products found by category name, try by category_id as fallback
        if (empty($desktops)) {
            $stmt_desktops = $pdo->prepare("SELECT * FROM products WHERE category_id = 7 AND status = 'active' ORDER BY created_at DESC LIMIT 4");
            $stmt_desktops->execute();
            $desktops = $stmt_desktops->fetchAll();
        }

        if (empty($laptops)) {
            $stmt_laptops = $pdo->prepare("SELECT * FROM products WHERE category_id = 8 AND status = 'active' AND id != 513 ORDER BY created_at DESC LIMIT 4");
            $stmt_laptops->execute();
            $laptops = $stmt_laptops->fetchAll();
        }

    } catch (PDOException $e) {
        error_log("Homepage product fetch error: " . $e->getMessage());
    }

    // Featured Products - reuses the same $desktops/$laptops data already
    // fetched above (no new query), just merged and capped at 6.
    $featuredProducts = array_slice(array_merge($desktops, $laptops), 0, 4);

    // 2-3 SKUs for the hero's floating price strip - reuses the same data.
    $heroPriceSkus = array_slice($featuredProducts, 0, 3);

    // Category selector (PS5-style carousel) - reuses the existing
    // categories table via the same getCategories() helper products.php's
    // filter sidebar already calls (already ORDER BY name ASC, which is
    // also the exact alphabetical order the selector is meant to browse
    // in).
    //
    // Every category is shown. There used to be an explicit skip for
    // "Storage" here, left from when the selector was specced against a
    // shorter catalog - it outlived that spec and just hid a real category
    // with 35 products in it. The counter reads count($carouselCategories),
    // so the carousel reported 18 of 19 and looked self-consistent rather
    // than obviously wrong. The guest landing page carried the same skip and
    // has been cleared too; the two pages stay in step.
    $carouselCategories = [];
    try {
        $catPdo = getConnection();
        foreach (getCategories() as $cat) {
            $img = null;
            if (!empty($cat['image_url'])) {
                // Stored as "img/Name.png", relative to public/shared/.
                $rel = preg_replace('#^img/#', 'public/shared/img/', $cat['image_url']);
                if (is_file($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT . $rel)) {
                    $img = WEB_ROOT . $rel;
                }
            }
            if (!$img) {
                // A few categories (Keyboards & Combos, Laptop Stands,
                // Speakers, Headsets) don't have a dedicated category
                // thumbnail uploaded yet - fall back to that category's own
                // active product images instead of a stock/external
                // picture. Several products' image_url rows point at files
                // that no longer exist on disk, so scan candidates (instead
                // of trusting just the first by id) for the first one that
                // actually resolves; the <img onerror> below still covers
                // the rare case where none do.
                $stmt = $catPdo->prepare("SELECT image_url FROM products WHERE category_id = ? AND status = 'active' AND image_url IS NOT NULL AND image_url <> '' ORDER BY id ASC LIMIT 40");
                $stmt->execute([$cat['id']]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $prodImg) {
                    if (is_file($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT . $prodImg)) {
                        $img = WEB_ROOT . $prodImg;
                        break;
                    }
                }
                if (!$img) {
                    $img = WEB_ROOT . 'public/shared/img/products/placeholder.svg';
                }
            }
            $carouselCategories[] = [
                'id' => (int)$cat['id'],
                'name' => $cat['name'],
                'description' => trim((string)($cat['description'] ?? '')),
                'image' => $img,
                'url' => WEB_ROOT . 'public/authenticated/customer/products.php?category_id=' . (int)$cat['id'],
            ];
        }
    } catch (PDOException $e) {
        error_log('Homepage category carousel fetch error: ' . $e->getMessage());
    }
  ?>

  <!-- Hero -->
  <div class="container">
    <section class="th-hero">
      <div class="th-hero-bg" aria-hidden="true">
        <div class="th-blob th-blob-1"></div>
        <div class="th-blob th-blob-2"></div>
      </div>
      <!-- Brand mark. A direct child of .th-hero, NOT of .th-hero-inner:
           that inner element is the two-column grid, so centring the brand
           inside it only centred it over the copy column. Out here it
           centres against the full section width. -->
      <div class="th-cat-brand">
        <img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="" class="th-cat-brand-logo">
        <span class="th-cat-brand-name">TechWorld</span>
      </div>

      <div class="th-hero-inner">
        <div class="th-hero-copy">
          <span class="th-hero-label">Smart Technology, Smarter Choices</span>
          <h1 class="th-hero-title th-display"><span>Build Your</span><span class="th-accent-line">Next PC<i class="thx-caret" aria-hidden="true"></i></span></h1>
          <p class="th-hero-sub">Discover the right components for your needs with intelligent product recommendations powered by AI.</p>
          <div class="th-hero-ctas">
            <!-- "Try AI Builder" sits here as the secondary CTA alongside
                 the AI configurator band further down: that band demonstrates
                 the feature, this button is the shortcut for visitors who
                 already know they want it. Explore Products keeps the primary
                 fill. (The old "Build Smarter With AI" promo section this
                 used to reference was removed - the band supersedes it.) -->
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="th-btn th-btn-primary">Explore Products <span class="th-btn-chip">&rarr;</span></a>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/ai_builder.php" class="th-btn th-btn-secondary">Try AI Builder</a>
          </div>
          <div class="thx-stats">
            <div class="thx-stat"><span class="thx-stat-num">1,200+</span><span class="thx-stat-label">Products in Stock</span></div>
            <div class="thx-stat"><span class="thx-stat-num">16K</span><span class="thx-stat-label">Builds Configured</span></div>
            <div class="thx-stat"><span class="thx-stat-num">48h</span><span class="thx-stat-label">Metro Manila Delivery</span></div>
          </div>
        </div>
        <div class="th-hero-media">
          <div class="th-hero-media-glow" aria-hidden="true"></div>
          <span class="thx-scan" aria-hidden="true"></span>
          <img src="<?= WEB_ROOT ?>public/shared/img/Desktop 1.png" alt="Modern gaming PC build" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/Desktop 2.png';">
          <?php if (!empty($heroPriceSkus)): ?>
            <div class="th-price-strip">
              <?php foreach ($heroPriceSkus as $sku): ?>
                <a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $sku['id'] ?>" class="th-price-chip">
                  <?php /* The thumbnail is phone-only: under 768px these chips
                           come out of their floating position and become a
                           scroll-snap row of cards under the render, where a
                           40px picture is what tells them apart at a glance.
                           On desktop they are small glass pills beside the
                           image and have carried no thumbnail since the hero
                           was redrawn, so the rules below hide it from 768px
                           up rather than changing that layout. $sku is a
                           `SELECT p.*` row the hero already has - no extra
                           query, and lazily fetched. */ ?>
                  <?php if (!empty($sku['image_url'])): ?>
                    <img class="th-pc-thumb" src="<?= WEB_ROOT . htmlspecialchars($sku['image_url']) ?>"
                         alt="" aria-hidden="true" loading="lazy">
                  <?php endif; ?>
                  <span class="th-pc-name"><?= htmlspecialchars(mb_strimwidth($sku['name'], 0, 13, '…')) ?></span>
                  <span class="th-pc-price"><?= formatCurrency($sku['price']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <!-- Brand marquee, full hero width. The list is repeated verbatim four
           times: translateX(-50%) shifts the track by half its width, so two
           copies would shift by less than the visible lane and expose a gap
           mid-loop. All four must stay identical for the seam to hide. The
           whole block is aria-hidden, so the brands are never announced. -->
      <div class="thx-marquee" aria-hidden="true">
        <div class="thx-marquee-track">
          <span class="thx-marquee-group">
            <span class="thx-marquee-item">Lenovo</span>
            <span class="thx-marquee-item">Asus</span>
            <span class="thx-marquee-item">AMD Ryzen</span>
            <span class="thx-marquee-item">Intel Core</span>
            <span class="thx-marquee-item">NVIDIA RTX</span>
            <span class="thx-marquee-item">Dahua CCTV</span>
          </span>
          <span class="thx-marquee-group">
            <span class="thx-marquee-item">Lenovo</span>
            <span class="thx-marquee-item">Asus</span>
            <span class="thx-marquee-item">AMD Ryzen</span>
            <span class="thx-marquee-item">Intel Core</span>
            <span class="thx-marquee-item">NVIDIA RTX</span>
            <span class="thx-marquee-item">Dahua CCTV</span>
          </span>
          <span class="thx-marquee-group">
            <span class="thx-marquee-item">Lenovo</span>
            <span class="thx-marquee-item">Asus</span>
            <span class="thx-marquee-item">AMD Ryzen</span>
            <span class="thx-marquee-item">Intel Core</span>
            <span class="thx-marquee-item">NVIDIA RTX</span>
            <span class="thx-marquee-item">Dahua CCTV</span>
          </span>
          <span class="thx-marquee-group">
            <span class="thx-marquee-item">Lenovo</span>
            <span class="thx-marquee-item">Asus</span>
            <span class="thx-marquee-item">AMD Ryzen</span>
            <span class="thx-marquee-item">Intel Core</span>
            <span class="thx-marquee-item">NVIDIA RTX</span>
            <span class="thx-marquee-item">Dahua CCTV</span>
          </span>
        </div>
      </div>
      <!-- Traveling-light divider. This is the ported .tw-hairline element,
           not a border: the sweep needs a real inner <i> to animate, which a
           border-bottom cannot provide. Sits OUTSIDE .thx-marquee because that
           block is overflow:hidden with an edge mask, which would clip and fade
           the sweep at both ends. -->
      <div class="tw-hairline thx-hero-rule" aria-hidden="true"><i></i></div>
    </section>
  </div>

  <!-- Product Categories: PS5-style category selector. Active category is
       dramatically larger/brighter than the side previews; neighbors preview
       smaller on each side; the carousel loops. Data comes from
       $carouselCategories (built above from the real categories table +
       existing product/category images - see the PHP block right after
       $heroPriceSkus). Selection-only: no description/CTA panel - the
       category name under each card and the active card's own scale/glow
       are the only "info" shown, so this stays compact above the hero. -->
  <section class="th-section th-cat-first-section">
    <div class="container">
      <div class="th-cat-panel">
        <div class="th-cat-blob th-cat-blob-1" aria-hidden="true"></div>
        <div class="th-cat-blob th-cat-blob-2" aria-hidden="true"></div>
        <div class="th-cat-carousel-spotlight" aria-hidden="true"></div>

        <div class="tw-head-row">
          <span aria-hidden="true"></span>
          <div class="th-section-head th-cat-head">
            <div class="tw-head__eyebrow">
              <b>01 &mdash; THE CATALOG</b>
              <span class="tw-head__rule" aria-hidden="true"></span>
            </div>
            <h2>Explore Computer Components</h2>
            <p>Pick a category the way you'd pick a game &mdash; glide to the one you want.</p>
          </div>
          <!-- Counter + arrows live in the head row's right cell so the heading stays
               optically centred. The arrows are the SAME buttons that used to flank
               the stage - same ids, same classes - so the carousel JS still binds to
               them; only their position in the DOM changed. The total is the real
               category count, not a hardcoded number. -->
          <div class="tw-head-row__ctrl">
            <span class="tw-counter" id="thCatCounter" aria-live="polite" aria-atomic="true">01 / <?= str_pad((string)count($carouselCategories), 2, '0', STR_PAD_LEFT) ?></span>
            <button type="button" class="th-cat-arrow th-cat-arrow-prev" id="thCatPrev" aria-label="Previous category">
              <i class="fi fi-rs-angle-left" aria-hidden="true"></i>
            </button>
            <button type="button" class="th-cat-arrow th-cat-arrow-next" id="thCatNext" aria-label="Next category">
              <i class="fi fi-rs-angle-right" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <?php if (!empty($carouselCategories)): ?>
        <div class="th-cat-carousel" id="thCatCarousel" role="region" aria-roledescription="carousel" aria-label="Product categories" tabindex="0">
          <div class="th-cat-stage" id="thCatStage">
            <?php foreach ($carouselCategories as $i => $cat): ?>
            <button type="button"
                    class="th-cat-slide"
                    data-index="<?= $i ?>"
                    data-url="<?= htmlspecialchars($cat['url']) ?>"
                    aria-current="<?= $i === 0 ? 'true' : 'false' ?>"
                    aria-label="<?= htmlspecialchars($cat['name']) ?>">
              <span class="th-cat-slide-frame">
                <img src="<?= htmlspecialchars($cat['image']) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/products/placeholder.svg';">
              </span>
              <span class="th-cat-slide-name"><?= htmlspecialchars($cat['name']) ?></span>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Active category name. Text is set by render() from the active
             slide's own .th-cat-slide-name, so it reads the same data that
             builds the tiles rather than a second copy of the category list.
             aria-hidden: the active tile already announces its name through
             aria-current/aria-label, so exposing this too would say it twice. -->
        <div class="tw-active-label" aria-hidden="true">
          <span></span>
          <b id="thCatActiveLabel"><?= htmlspecialchars($carouselCategories[0]['name'] ?? '') ?></b>
          <span></span>
        </div>

        <div class="th-cat-dots" id="thCatDots">
          <?php foreach ($carouselCategories as $i => $cat): ?>
            <button type="button" class="th-cat-dot<?= $i === 0 ? ' is-active' : '' ?>" data-index="<?= $i ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Go to <?= htmlspecialchars($cat['name']) ?>"></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Featured Products -->
  <section class="th-section">
    <div class="container">
      <div class="th-section-head">
        <div class="tw-head__eyebrow">
          <b>02 &mdash; SELECTED BUILDS</b>
          <span class="tw-head__rule" aria-hidden="true"></span>
        </div>
        <h2>Featured Products</h2>
        <p>Hand-picked picks from our latest desktops and laptops.</p>
      </div>
      <!-- Electric-border filter: displaces a hollow border with animated
           turbulence so it crackles. Referenced by .th-product-card:hover::after. -->
      <svg class="eb-defs" aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute;pointer-events:none">
        <defs>
          <filter id="eb-turbulence" x="-30%" y="-30%" width="160%" height="160%">
            <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n1" seed="1"/>
            <feOffset in="n1" dx="0" dy="0" result="o1">
              <animate attributeName="dy" values="700; 0" dur="6s" repeatCount="indefinite" calcMode="linear"/>
            </feOffset>
            <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n2" seed="1"/>
            <feOffset in="n2" dx="0" dy="0" result="o2">
              <animate attributeName="dy" values="0; -700" dur="6s" repeatCount="indefinite" calcMode="linear"/>
            </feOffset>
            <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n3" seed="2"/>
            <feOffset in="n3" dx="0" dy="0" result="o3">
              <animate attributeName="dx" values="490; 0" dur="6s" repeatCount="indefinite" calcMode="linear"/>
            </feOffset>
            <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n4" seed="2"/>
            <feOffset in="n4" dx="0" dy="0" result="o4">
              <animate attributeName="dx" values="0; -490" dur="6s" repeatCount="indefinite" calcMode="linear"/>
            </feOffset>
            <feComposite in="o1" in2="o2" result="yMove"/>
            <feComposite in="o3" in2="o4" result="xMove"/>
            <feBlend in="yMove" in2="xMove" mode="color-dodge" result="blended"/>
            <feDisplacementMap in="SourceGraphic" in2="blended" scale="10"/>
          </filter>
        </defs>
      </svg>
      <div class="th-product-grid">
        <?php if (!empty($featuredProducts)): ?>
          <?php
            // BEST VALUE = the cheapest of the products already fetched into
            // $featuredProducts. A pass over that array, not a second query -
            // nothing here touches the database. Zero-priced rows are skipped so
            // a missing price cannot win.
            $twBestValueId = null; $twMinPrice = null;
            foreach ($featuredProducts as $twP) {
                $twPrice = (float)$twP['price'];
                if ($twPrice > 0 && ($twMinPrice === null || $twPrice < $twMinPrice)) {
                    $twMinPrice = $twPrice; $twBestValueId = (int)$twP['id'];
                }
            }
          ?>
          <?php foreach ($featuredProducts as $product): ?>
            <div class="th-product-card">
              <div class="th-brand">TechWorld</div>
              <?php
                // Badge conditions, from columns that exist. One badge per card;
                // first match wins, in the order NEW > BEST VALUE > IN STOCK.
                // reorder_level comes from an optional migration, hence the ?? 5.
                // "comfortably above" is read as more than twice the reorder point,
                // so a card sitting just over it does not claim a healthy badge.
                $twStock   = (int)$product['stock'];
                $twReorder = (int)($product['reorder_level'] ?? 5);
                $twIsNew   = !empty($product['created_at']) && strtotime($product['created_at']) > strtotime('-30 days');
                $twIsValue = $twBestValueId !== null && (int)$product['id'] === $twBestValueId;
                $twInStock = $twStock > $twReorder * 2;
              ?>
              <div class="th-pimg">
                <?php if ($twIsValue): ?>
                  <span class="tw-badge tw-badge--value">BEST VALUE</span>
                <?php elseif ($twInStock): ?>
                  <span class="tw-badge tw-badge--stock">IN STOCK</span>
                <?php elseif ($twIsNew): ?>
                  <span class="tw-badge tw-badge--new">NEW</span>
                <?php endif; ?>
                <a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $product['id'] ?>">
                  <img src="<?= htmlspecialchars($product['image_url'] ? WEB_ROOT . $product['image_url'] : WEB_ROOT . 'public/shared/img/products/placeholder.svg') ?>" alt="<?= htmlspecialchars($product['name']) ?>" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/products/placeholder.svg';">
                </a>
              </div>
              <?php if (!empty($product['category_name'])): ?>
                <div class="tw-card__kind"><?= htmlspecialchars($product['category_name']) ?></div>
              <?php endif; ?>
              <h3><a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?></a></h3>
              <div class="th-rating"><i class="fi fi-rs-star" style="color: gold;"></i> No reviews</div>

              <div class="price-stock-section">
                <div class="price">
                  <?php if (floatval($product['price']) > 0): ?>
                    <span class="current-price"><?= formatCurrency($product['price']) ?></span>
                  <?php else: ?>
                    <span class="current-price">₱ —</span>
                  <?php endif; ?>
                </div>
                <div class="tw-card__spec"><?= $twStock > 0 ? $twStock . ' LEFT' : 'OUT OF STOCK' ?></div>
                <div class="stock-indicator">
                  <?php if ($product['stock'] > 0): ?>
                    <?php if ($product['stock'] <= 5): ?>
                      <span class="stock-low"><i class="fi fi-rr-exclamation-triangle"></i> <?php echo $product['stock']; ?> left</span>
                    <?php else: ?>
                      <span class="stock-available"><i class="fi fi-rr-check-circle"></i> <?php echo $product['stock']; ?> AVAILABLE</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="stock-out"><i class="fi fi-rr-cross-circle"></i> Out of Stock</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="product-actions">
                <?php if ($product['stock'] > 0): ?>
                  <button class="add-to-cart-btn tw-btn-outline" onclick="addToCart(<?= (int)$product['id'] ?>)">
                    <i class="fi fi-rs-shopping-cart-add"></i> Add to Cart
                  </button>
                  <button class="buy-now tw-btn-buy" type="button" onclick="buyNowDbProduct(<?= (int)$product['id'] ?>)">
                    <i class="fi fi-rr-shopping-bag"></i> BUY NOW
                  </button>
                <?php else: ?>
                  <button class="wishlist-action-btn" onclick="addToCart(<?= (int)$product['id'] ?>)">
                    <i class="fi fi-rs-heart"></i> Add to Wishlist
                  </button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="grid-column: 1 / -1; text-align: center; color: var(--th-text); opacity:.7;">No featured products to display at the moment.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- AI configurator. Display-only for now: the build, prices and checks
       are static sample data, not the products table. The CTA points at the
       real AI Builder. -->
  <?php
    $twAiCta = '<a class="tw-btn-addall" href="' . WEB_ROOT . 'public/authenticated/customer/ai_builder.php">Build mine with AI<span class="tw-shine" aria-hidden="true"></span></a>';
    include __DIR__ . '/../../shared/includes/ai-configurator.php';
  ?>

  <!-- Why Choose TechWorld - the bento ported from handoff/. Replaces the
       old .th-section version that stood here; same slot, new treatment. -->
  <?php include __DIR__ . '/../../shared/includes/why-techworld.php'; ?>

  </div><!-- /.tw-home -->

  <?php include __DIR__ . '/../../shared/includes/auth-modal.php'; ?>

  <script>
    // Initialize cart count from server-side data (already rendered in header)
    // No need to override with sessionStorage

    document.addEventListener('DOMContentLoaded', function() {
      // Auth form submit and verification handlers are cleanly provided by footer.php
    });
    function showForgot(){
      document.getElementById('loginForm').style.display='none';
      document.getElementById('registerForm').style.display='none';
      document.getElementById('verifyForm').style.display='none';
      document.getElementById('forgotForm').style.display='block';
    }
    function showLogin(){
      document.getElementById('forgotForm').style.display='none';
      document.getElementById('registerForm').style.display='none';
      document.getElementById('verifyForm').style.display='none';
      document.getElementById('loginForm').style.display='block';
    }
    function showRegister(){
      document.getElementById('forgotForm').style.display='none';
      document.getElementById('loginForm').style.display='none';
      document.getElementById('verifyForm').style.display='none';
      document.getElementById('registerForm').style.display='block';
    }

    // Disabled closing modal when clicking outside (must use modal buttons)

    function buyNowDbProduct(productId) {
      // Go directly to checkout without adding to cart
      window.location.href = '<?= WEB_ROOT ?>public/authenticated/customer/checkout.php?selected=' + productId;
    }

    // PS5-style category selector (Explore Computer Components). Every
    // slide's position is a signed "distance from active" (circular, so it
    // wraps both ways) recomputed on every change - that's what makes both
    // arrow-stepping and jumping straight to a far category (dot/slide
    // click) animate smoothly instead of needing separate wrap-around logic.
    (function () {
      var stage = document.getElementById('thCatStage');
      if (!stage) return;
      var slides = Array.prototype.slice.call(stage.querySelectorAll('.th-cat-slide'));
      var n = slides.length;
      if (!n) return;

      var dots = Array.prototype.slice.call(document.querySelectorAll('#thCatDots .th-cat-dot'));
      var prevBtn = document.getElementById('thCatPrev');
      var nextBtn = document.getElementById('thCatNext');
      var carousel = document.getElementById('thCatCarousel');
      var active = 0;
      var counterEl = document.getElementById('thCatCounter');
      var labelEl = document.getElementById('thCatActiveLabel');
      function pad2(v) { return (v < 10 ? '0' : '') + v; }

      function maxVisible() {
        var w = window.innerWidth;
        if (w <= 768) return 1;
        if (w <= 1100) return 2;
        return 3;
      }

      // Shortest signed distance from i to active around an n-item circle.
      function circularOffset(i) {
        var diff = ((i - active) % n + n) % n;
        if (diff > n / 2) diff -= n;
        return diff;
      }

      function render() {
        var mv = maxVisible();
        slides.forEach(function (slide, i) {
          var off = circularOffset(i);
          var abs = Math.abs(off);
          var isActive = off === 0;
          var hidden = abs > mv;

          slide.setAttribute('aria-current', isActive ? 'true' : 'false');
          slide.setAttribute('aria-hidden', hidden ? 'true' : 'false');
          slide.tabIndex = hidden ? -1 : 0;
          slide.style.pointerEvents = hidden ? 'none' : '';
          slide.classList.toggle('is-active', isActive);

          // Active is dramatically larger/brighter than previews - not a
          // subtle 10-20px bump - so it unmistakably reads as "selected",
          // like the focused cover on a console home screen.
          var scale, opacity, z;
          if (abs === 0) { scale = 1.4; opacity = 1; z = 10; }
          else if (abs === 1) { scale = 0.5; opacity = 0.72; z = 8; }
          else if (abs === 2) { scale = 0.38; opacity = 0.5; z = 6; }
          else if (abs === 3) { scale = 0.28; opacity = 0.28; z = 4; }
          else { scale = 0.22; opacity = 0; z = 1; }
          if (hidden) opacity = 0;

          slide.style.setProperty('--o', off);
          slide.style.setProperty('--s', scale);
          slide.style.setProperty('--op', opacity);
          slide.style.setProperty('--z', z);
        });

        if (labelEl && slides[active]) {
          var nm = slides[active].querySelector('.th-cat-slide-name');
          if (nm) { labelEl.textContent = nm.textContent; }
        }

        dots.forEach(function (dot, i) {
          var isActive = i === active;
          dot.classList.toggle('is-active', isActive);
          dot.setAttribute('aria-current', isActive ? 'true' : 'false');
        });

        // Position counter in the head row - same source of truth as the
        // dots, so it can never drift from the carousel's real state.
        if (counterEl) {
          counterEl.textContent = pad2(active + 1) + ' / ' + pad2(n);
        }
      }

      function goTo(index) {
        active = ((index % n) + n) % n;
        render();
      }
      function next() { goTo(active + 1); }
      function prev() { goTo(active - 1); }

      slides.forEach(function (slide) {
        slide.addEventListener('click', function () {
          if (slide.__thDragged) { slide.__thDragged = false; return; }
          var i = parseInt(slide.dataset.index, 10);
          if (i === active) {
            window.location.href = slide.dataset.url;
          } else {
            goTo(i);
          }
        });
      });

      dots.forEach(function (dot) {
        dot.addEventListener('click', function () { goTo(parseInt(dot.dataset.index, 10)); });
      });

      if (prevBtn) prevBtn.addEventListener('click', prev);
      if (nextBtn) nextBtn.addEventListener('click', next);

      if (carousel) {
        carousel.addEventListener('keydown', function (e) {
          if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
          else if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
          else if (e.key === 'Home') { e.preventDefault(); goTo(0); }
          else if (e.key === 'End') { e.preventDefault(); goTo(n - 1); }
        });
      }

      // Swipe/drag - pointer events cover touch + mouse in one listener set.
      var dragging = false, startX = 0, dragSlide = null;
      stage.addEventListener('pointerdown', function (e) {
        dragging = true;
        startX = e.clientX;
        dragSlide = e.target.closest('.th-cat-slide');
      });
      stage.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        if (Math.abs(e.clientX - startX) > 12 && dragSlide) { dragSlide.__thDragged = true; }
      });
      window.addEventListener('pointerup', function (e) {
        if (!dragging) return;
        dragging = false;
        var dx = e.clientX - startX;
        if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); }
        dragSlide = null;
      });

      window.addEventListener('resize', render);

      render();
    })();

    // Optional, purely decorative mouse-parallax on the category panel -
    // desktop/mouse only (hover:hover + pointer:fine, same gate the
    // sidebar's own hover-expand uses) and off entirely under
    // prefers-reduced-motion. Nudges two independent CSS custom-property
    // pairs a few px toward the cursor: --px/--py move the big ambient
    // spotlight behind the whole carousel (see .th-cat-carousel-spotlight
    // in customer-home.css), --ix/--iy move just the active product image
    // via the standalone `translate` property, which composes alongside
    // (never fights) the image's own `transform`-based float animation.
    // Does not touch the carousel's own active/index state or transforms.
    (function () {
      var panel = document.querySelector('.th-cat-panel');
      if (!panel) return;
      var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
      var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!canHover || reduced) return;

      var raf = null;
      function onMove(e) {
        if (raf) return;
        raf = requestAnimationFrame(function () {
          raf = null;
          var rect = panel.getBoundingClientRect();
          var nx = ((e.clientX - rect.left) / rect.width) * 2 - 1;
          var ny = ((e.clientY - rect.top) / rect.height) * 2 - 1;
          nx = Math.max(-1, Math.min(1, nx));
          ny = Math.max(-1, Math.min(1, ny));
          panel.style.setProperty('--px', (nx * 8).toFixed(1) + 'px');
          panel.style.setProperty('--py', (ny * 8).toFixed(1) + 'px');
          panel.style.setProperty('--ix', (nx * 3).toFixed(1) + 'px');
          panel.style.setProperty('--iy', (ny * 3).toFixed(1) + 'px');
        });
      }
      function onLeave() {
        panel.style.setProperty('--px', '0px');
        panel.style.setProperty('--py', '0px');
        panel.style.setProperty('--ix', '0px');
        panel.style.setProperty('--iy', '0px');
      }
      panel.addEventListener('mousemove', onMove);
      panel.addEventListener('mouseleave', onLeave);
    })();

  </script>
  <?php include __DIR__ . '/includes/footer.php'; ?>

  <!-- Landing-page interaction layer: coverflow index + scroll-reveal
       fallback only. All looping motion is pure CSS. -->
  <script src="<?= asset_url('public/shared/assets/js/techworld.js') ?>" defer></script>
</body>
</html>


