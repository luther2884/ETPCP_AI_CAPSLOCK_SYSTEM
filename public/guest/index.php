<?php
// index.php — guest homepage. Mirrors the authenticated customer homepage
// (public/authenticated/customer/index.php and its .th-* design system in
// public/shared/css/customer-home.css) so logged-out visitors see the same
// hero / PS5 category carousel / AI Builder promo / featured products /
// "Why Choose" layout. The only differences for guests: the header keeps its
// Login / Sign In button, and anything that needs an account (buying, the AI
// Builder) opens the login modal instead of acting.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once '../../config/functions.php';

// A signed-in customer has their own homepage - send them there rather than
// showing the logged-out version.
if (isLoggedIn() && function_exists('isCustomer') && isCustomer()) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/index.php');
    exit;
}

$pageTitle = 'TechWorld - Home';
$activeNav = 'home';

// Featured products - same query shape as the customer homepage: newest
// active Desktops + Laptops (by category name, with a category_id fallback),
// merged and capped at 6.
$desktops = [];
$laptops = [];
try {
    $pdo = getConnection();

    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p
                           LEFT JOIN categories c ON p.category_id = c.id
                           WHERE c.name = 'Desktops' AND p.status = 'active'
                           ORDER BY p.created_at DESC LIMIT 4");
    $stmt->execute();
    $desktops = $stmt->fetchAll();

    // id 513 (Lenovo IdeaPad 5 14IHU5) is excluded from this homepage
    // feature grid only (still fully purchasable via the normal
    // catalog/search) - mirrors the same exclusion on the customer homepage.
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p
                           LEFT JOIN categories c ON p.category_id = c.id
                           WHERE c.name = 'Laptops' AND p.status = 'active' AND p.id != 513
                           ORDER BY p.created_at DESC LIMIT 4");
    $stmt->execute();
    $laptops = $stmt->fetchAll();

    if (empty($desktops)) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE category_id = 7 AND status = 'active' ORDER BY created_at DESC LIMIT 4");
        $stmt->execute();
        $desktops = $stmt->fetchAll();
    }
    if (empty($laptops)) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE category_id = 8 AND status = 'active' AND id != 513 ORDER BY created_at DESC LIMIT 4");
        $stmt->execute();
        $laptops = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('Guest homepage product fetch error: ' . $e->getMessage());
}

$featuredProducts = array_slice(array_merge($desktops, $laptops), 0, 4);
$heroPriceSkus = array_slice($featuredProducts, 0, 3);

// Category selector (PS5-style carousel) - same build as the customer
// homepage, only the tile links point at the guest products page.
//
// Every category is shown. There used to be an explicit skip for "Storage"
// here, which is why the carousel counted 18 of the catalog's 19 - the
// counter reads count($carouselCategories), so it reported 18 and looked
// self-consistent rather than obviously wrong.
$carouselCategories = [];
try {
    $catPdo = getConnection();
    foreach (getCategories() as $cat) {
        $img = null;
        if (!empty($cat['image_url'])) {
            $rel = preg_replace('#^img/#', 'public/shared/img/', $cat['image_url']);
            if (is_file($_SERVER['DOCUMENT_ROOT'] . WEB_ROOT . $rel)) {
                $img = WEB_ROOT . $rel;
            }
        }
        if (!$img) {
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
            'id'    => (int)$cat['id'],
            'name'  => $cat['name'],
            'image' => $img,
            'url'   => WEB_ROOT . 'public/guest/products.php?category_id=' . (int)$cat['id'],
        ];
    }
} catch (PDOException $e) {
    error_log('Guest homepage category carousel fetch error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TechWorld - Home</title>

  <link rel="icon" type="image/x-icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico">
  <link rel="shortcut icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico">

  <?php /* Anti-FOUC for the Light/Dark switch. Blocking and inline, ahead of
           every stylesheet, because theme.js is deferred and would run a frame
           too late - the page would paint in the wrong theme and snap. Same
           storage key and the same dark-is-the-fallback rule as theme.js, so
           the choice made here is the choice the customer area reads after
           login. */ ?>
  <script>(function(){try{var c=localStorage.getItem('tw-theme')||'system';var m=(c==='system')?((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark'):c;document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

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

  <?php /* The Light/Dark palette. This is the same sheet the customer sidebar
           links, and it is what gives --tw-* and --th-* their per-mode values -
           the two palettes this page actually renders from (techworld-anim.css
           uses --tw-*, customer-home.css uses --th-*). Linked AFTER both so its
           html[data-mode=...] values win on source order. */ ?>
  <link rel="stylesheet" href="<?= asset_url('public/shared/css/theme-light.css') ?>">
  <script src="<?= asset_url('public/shared/assets/js/theme.js') ?>" defer></script>

  <script>document.documentElement.setAttribute('data-theme','light');</script>
  <script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>

  <!-- All landing-page styling now lives in techworld-anim.css (linked
       above, last). No page-specific <style> block. -->
</head>
<body>
  <!-- Animated background: three fixed, non-interactive layers behind all
       content (.tw-home is the position:relative / z-index:1 wrapper). -->
  <div class="tw-bg tw-bg-grid" aria-hidden="true"></div>
  <div class="tw-bg" aria-hidden="true">
    <div class="tw-bg-blob tw-bg-blob-pink"></div>
    <div class="tw-bg-blob tw-bg-blob-cyan"></div>
  </div>

  <!-- No top header / nav bar on this page (matches the authenticated
       customer homepage). The only chrome is this floating Login button;
       openLoginModal() + the login modal markup come from includes/footer.php. -->
  <?php /* Theme switch. It carries .tw-appearance-icon so theme.js's existing
           delegated click handler AND its sync() - which rewrites the glyph,
           title and data-mode-set to the INCOMING theme - both pick it up with
           no changes to theme.js. The second class is only for placing it: the
           base class is display:none outside the customer rail. */ ?>
  <button type="button" class="tw-appearance-icon tw-theme-fab" data-mode-set="light"
          title="Switch to light mode" aria-label="Switch to light mode">&#9728;</button>

  <a class="tw-login-fab" href="<?= WEB_ROOT ?>public/guest/login.php" onclick="event.preventDefault(); openLoginModal();">
    <i class="fi fi-rr-sign-in-alt" aria-hidden="true"></i> Login / Sign In
  </a>

  <style>
    /* .tw-appearance-icon is display:none by default - it only shows inside the
       customer sidebar's collapsed rail - so the landing copy has to opt back
       in and place itself. Sits immediately left of .tw-login-fab, matching its
       top offset, radius and blur so the two read as one pair of controls. */
    .tw-theme-fab{
      /* right: the login pill sits at right:18px and is about 175px wide, so
         190px put this flush against it with no gap. 208px leaves a clear
         ~15px of air between the two. */
      position:fixed;top:16px;right:208px;z-index:1200;display:inline-flex;
      width:42px;height:42px;margin:0;border-radius:999px;
      -webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);
      font-size:17px;
    }
    @media (max-width:640px){
      /* The login pill shrinks on small screens, so the switch follows it in
         rather than keeping a desktop offset and overlapping. */
      .tw-theme-fab{right:auto;left:18px;}
    }
  </style>

  <div class="tw-home">

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
              <a href="<?= WEB_ROOT ?>public/guest/products.php" class="th-btn th-btn-primary">Explore Products <span class="th-btn-chip">&rarr;</span></a>
              <button type="button" class="th-btn th-btn-secondary" onclick="openLoginModal()">Login / Sign In</button>
              <button type="button" class="th-btn th-btn-secondary" onclick="openLoginModal()">Try AI Builder</button>
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
                  <a href="<?= WEB_ROOT ?>public/guest/product.php?id=<?= $sku['id'] ?>" class="th-price-chip">
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

    <!-- Product Categories: PS5-style category selector (identical to the
         customer homepage; tiles link to the guest products page). -->
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

        <!-- Electric-border filter for .th-product-card:hover::after (defined
             in customer-home.css) - one shared, animated turbulence filter. -->
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
                  <a href="<?= WEB_ROOT ?>public/guest/product.php?id=<?= $product['id'] ?>">
                    <img src="<?= htmlspecialchars($product['image_url'] ? WEB_ROOT . $product['image_url'] : WEB_ROOT . 'public/shared/img/products/placeholder.svg') ?>" alt="<?= htmlspecialchars($product['name']) ?>" onerror="this.onerror=null;this.src='<?= WEB_ROOT ?>public/shared/img/products/placeholder.svg';">
                  </a>
                </div>
                <?php if (!empty($product['category_name'])): ?>
                  <div class="tw-card__kind"><?= htmlspecialchars($product['category_name']) ?></div>
                <?php endif; ?>
                <h3><a href="<?= WEB_ROOT ?>public/guest/product.php?id=<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?></a></h3>
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
                  <div class="stock-indicator-wrap">
                    <?php if ($product['stock'] > 0): ?>
                      <?php if ($product['stock'] <= 5): ?>
                        <span class="stock-indicator stock-low"><i class="fi fi-rr-exclamation-triangle"></i> <?= (int)$product['stock'] ?> left</span>
                      <?php else: ?>
                        <span class="stock-indicator stock-available"><i class="fi fi-rr-check-circle"></i> <?= (int)$product['stock'] ?> Available</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="stock-indicator stock-out"><i class="fi fi-rr-cross-circle"></i> Out of Stock</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="product-actions">
                  <a class="pa-btn view tw-btn-outline" href="<?= WEB_ROOT ?>public/guest/product.php?id=<?= $product['id'] ?>">
                    <i class="fi fi-rr-eye"></i> View Details
                  </a>
                  <button type="button" class="pa-btn signin tw-btn-buy" onclick="openLoginModal()">
                    <i class="fi fi-rr-shopping-bag"></i> Sign in to Buy
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="grid-column: 1 / -1; text-align: center; color: var(--th-midgray);">No featured products to display at the moment.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- AI configurator. Display-only for now: the build, prices and checks
         are static sample data, not the products table. The CTA opens the
         login modal, matching every other guest CTA on this page. -->
    <?php
      $twAiCta = '<button class="tw-btn-addall" type="button" onclick="openLoginModal()">Build mine with AI<span class="tw-shine" aria-hidden="true"></span></button>';
      include __DIR__ . '/../shared/includes/ai-configurator.php';
    ?>

    <!-- Why Choose TechWorld - the bento ported from handoff/. Replaces the
         old .th-section version that stood here; same slot, new treatment. -->
    <?php include __DIR__ . '/../shared/includes/why-techworld.php'; ?>

  </div><!-- /.tw-home -->

  <script>
    // PS5-style category selector (Explore Computer Components) - same
    // implementation as the customer homepage: every slide's position is a
    // signed circular "distance from active", recomputed each change.
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

      function goTo(index) { active = ((index % n) + n) % n; render(); }
      function next() { goTo(active + 1); }
      function prev() { goTo(active - 1); }

      slides.forEach(function (slide) {
        slide.addEventListener('click', function () {
          if (slide.__thDragged) { slide.__thDragged = false; return; }
          var i = parseInt(slide.dataset.index, 10);
          if (i === active) { window.location.href = slide.dataset.url; }
          else { goTo(i); }
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

      var dragging = false, startX = 0, dragSlide = null;
      stage.addEventListener('pointerdown', function (e) {
        dragging = true; startX = e.clientX;
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

    // Decorative mouse-parallax on the category panel (desktop/mouse only,
    // off under prefers-reduced-motion) - same as the customer homepage.
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

  <script src="<?= asset_url('public/shared/assets/js/main.js') ?>"></script>
  <?php include __DIR__ . '/includes/footer.php'; ?>

  <!-- Landing-page interaction layer: coverflow index + scroll-reveal
       fallback only. All looping motion is pure CSS. -->
  <script src="<?= asset_url('public/shared/assets/js/techworld.js') ?>" defer></script>
</body>
</html>
