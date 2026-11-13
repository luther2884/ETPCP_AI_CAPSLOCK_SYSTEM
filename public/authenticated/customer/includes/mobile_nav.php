<?php
/* ===========================================================================
   includes/mobile_nav.php - phone navigation for the customer side.
   Included once from includes/sidebar.php, which is on every customer page.

   WHAT IT IS
     under 768px   a sticky top bar, a fixed 5-column bottom tab bar, and a
                   BOTTOM SHEET behind the Menu tab
     768px and up  nothing. Every rule below lives inside one
                   @media (max-width: 767.98px) block, so the desktop sidebar
                   and the hover rail are untouched - not overridden, not
                   re-specified, simply not addressed.

   WHY A GUARD
     notifications.php and ai_builder.php include header.php/sidebar.php
     twice. Without the define() below they would render two top bars, two tab
     bars and two sheets, with duplicate ids breaking every getElementById.

   LINKS
     Rendered from $__sbNav, the same array the desktop <nav> loops. Adding a
     page to that array puts it in both places; there is no second list here
     to forget.

   WHY A SHEET AND NOT THE OLD LEFT DRAWER
     The drawer was a full-height column that had to hold every link, so its
     footer - theme switch and Log out - was pinned to the bottom with
     position, and on a short viewport it sat on top of the tail of the list.
     That is the overlap in the screenshots. The sheet carries only what the
     tab bar does not (Home, Products, AI Build and Orders are already tabs),
     which is six destinations; it has nothing to pin, every child below is in
     normal document flow, and the sheet itself is what scrolls.
   ======================================================================== */

if (!defined('TW_MOBILE_NAV')) :
    define('TW_MOBILE_NAV', 1);

    // Cart count comes from header.php, which runs before this on every page.
    // Null-coalesced rather than recomputed: this file must not add a query.
    $__mnCart  = isset($cartCount) ? (int)$cartCount : 0;
    $__mnEmail = (string)($_SESSION['email'] ?? '');
    $__mnHref  = static fn(string $p): string => WEB_ROOT . 'public/authenticated/customer/' . $p;

    /** Look a row up by key so the tab bar's and the sheet's hrefs come from
     *  the same array the desktop sidebar uses. Submenu children are searched
     *  too, because Support lives under Settings there. */
    $__mnFind = static function (string $key) use ($__sbNav) {
        foreach ($__sbNav as $group) {
            foreach ($group as $row) {
                if ($row['key'] === $key) { return $row; }
                foreach (($row['children'] ?? []) as $ch) {
                    if ($ch['key'] === $key) { return $ch; }
                }
            }
        }
        return null;
    };

    // The five bottom tabs. 'menu' is the sheet, not a link.
    $__mnTabs = [
        ['key' => 'home',       'label' => 'Home',     'icon' => 'fi fi-rr-home'],
        ['key' => 'products',   'label' => 'Products', 'icon' => 'fi fi-rr-apps'],
        ['key' => 'ai_builder', 'label' => 'AI Build', 'icon' => 'fi fi-rr-microchip', 'raised' => true],
        ['key' => 'orders',     'label' => 'Orders',   'icon' => 'fi fi-rr-box'],
        ['key' => 'menu',       'label' => 'Menu',     'icon' => 'fi fi-rr-menu-burger'],
    ];
    $__mnActive = $activeNav ?? '';

    /* The sheet's six tiles, in reading order. Keys only - every label and
       href still comes out of $__sbNav through $__mnFind, so this list can
       never point somewhere the sidebar does not. The four keys missing from
       it (home, products, ai_builder, orders) are the bottom tabs. */
    $__mnTileKeys = ['dashboard', 'notifications', 'track_order', 'cart', 'settings', 'support'];

    /* 24px line icons, drawn here rather than reused from $__sbNav's gifs: the
       sidebar rows are 20px animated gifs built for a dark panel, and six of
       them at 24px on a white sheet read as a muddle of little pictures. These
       are one stroke weight, one size, and they take their colour from the
       tile, so an active tile's icon turns pink along with its label. */
    $__mnTileIcon = [
        'dashboard'     => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
        'notifications' => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6.5-2.5 6.5-2.5 8.5h17c0-2-2.5-2-2.5-8.5"/><path d="M10.2 20.2a2.1 2.1 0 0 0 3.6 0"/>',
        'track_order'   => '<path d="M3 6.6h9.4a1 1 0 0 1 1 1v8.9H4a1 1 0 0 1-1-1V6.6Z"/><path d="M13.4 10.3h3.9a1 1 0 0 1 .82.43l2.68 3.77v2H13.4v-6.2Z"/><circle cx="7.4" cy="18.6" r="1.8"/><circle cx="17.2" cy="18.6" r="1.8"/>',
        'cart'          => '<circle cx="9.5" cy="19.6" r="1.6"/><circle cx="17.8" cy="19.6" r="1.6"/><path d="M2.6 3.6h2.3l2.5 11.1a1.6 1.6 0 0 0 1.56 1.25h8.2a1.6 1.6 0 0 0 1.56-1.25L20.3 8.1H6.1"/>',
        'settings'      => '<path d="M3.4 7.2h9.1M17.4 7.2h3.2M3.4 16.8h3.2M11.6 16.8h9"/><circle cx="15" cy="7.2" r="2.3"/><circle cx="9.2" cy="16.8" r="2.3"/>',
        'support'       => '<path d="M4.2 14.6v-2.4a7.8 7.8 0 0 1 15.6 0v2.4"/><path d="M6.1 13.9a1 1 0 0 1 1 1v3.2a1 1 0 0 1-1 1h-.6a2.3 2.3 0 0 1-2.3-2.3v-1.6a1.3 1.3 0 0 1 1.3-1.3h1.6Z"/><path d="M17.9 13.9a1 1 0 0 0-1 1v3.2a1 1 0 0 0 1 1h.6a2.3 2.3 0 0 0 2.3-2.3v-1.6a1.3 1.3 0 0 0-1.3-1.3h-1.6Z"/><path d="M19.8 18.6a2.4 2.4 0 0 1-2.4 2.4h-3.5"/>',
    ];
?>

<!-- ===== Top bar ======================================================= -->
<header class="tw-mnav-top" id="twMnavTop">
  <a class="tw-mnav-brand" href="<?= $__mnHref('index.php') ?>">
    <img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="" aria-hidden="true">
    <span>TechWorld</span>
  </a>
  <nav class="tw-mnav-actions" aria-label="Quick actions">
    <?php /* Opens the search field below, rather than being a link.

             It used to be <a href="products.php">, which meant the phone had
             no way to search at all: the only real search form - the one with
             a text box - lives in #customerSidebar, and this file sets that
             to display:none under 768px. products.php has no search field of
             its own either (its only name="q" is a hidden input that carries
             an existing query through the filter form), so tapping this
             landed you on the unfiltered product list with nothing to type
             into. */ ?>
    <button type="button" class="tw-mnav-btn" id="twMnavSearchBtn" aria-label="Search"
            aria-expanded="false" aria-controls="twMnavSearch">
      <i class="fi fi-bs-search" aria-hidden="true"></i>
    </button>
    <a class="tw-mnav-btn" href="<?= $__mnHref('notifications.php') ?>" aria-label="Notifications">
      <i class="fi fi-rr-bell" aria-hidden="true"></i>
      <?php /* Mirrors the sidebar badge's count. The existing poller updates
               #customerSidebarNotifBadge; the script below copies it here, so
               there is still only one thing fetching a count. */ ?>
      <span class="tw-mnav-badge" id="twMnavNotifBadge" <?= $__sbUnreadCount > 0 ? '' : 'hidden' ?>><?= $__sbUnreadCount > 99 ? '99+' : $__sbUnreadCount ?></span>
    </a>
    <a class="tw-mnav-btn" href="<?= $__mnHref('cart.php') ?>" aria-label="Cart">
      <i class="fi fi-rr-shopping-cart" aria-hidden="true"></i>
      <?php /* .cart-count is what main.js's updateCartDisplay() already looks
               for, so add-to-cart updates this for free. footer.php's own
               updater only knows #cartCount, which the script below mirrors. */ ?>
      <span class="tw-mnav-badge cart-count" id="twMnavCartBadge" <?= $__mnCart > 0 ? '' : 'hidden' ?>><?= $__mnCart > 99 ? '99+' : $__mnCart ?></span>
    </a>
  </nav>
</header>

<?php /* ===== Search =======================================================
         Deliberately the SAME contract as the sidebar's search form in
         includes/sidebar.php: GET to products.php with a field named `q`. So
         this reuses the smart/fuzzy search products.php already implements -
         it is not a second search system, and there is no backend change.

         Sits outside <header> because that bar is a fixed-height flex row; a
         second row inside it would be clipped. Sticky directly beneath it so
         the field stays put while the results scroll.                      */ ?>
<form class="tw-mnav-search" id="twMnavSearch" role="search" hidden
      action="<?= $__mnHref('products.php') ?>" method="get">
  <i class="fi fi-bs-search" aria-hidden="true"></i>
  <?php /* Prefilled with the current query so refining a search does not mean
           retyping it from scratch. */ ?>
  <input type="search" name="q" id="twMnavSearchInput"
         placeholder="Search products&hellip;" autocomplete="off"
         aria-label="Search products"
         value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>">
  <button type="button" class="tw-mnav-search-close" id="twMnavSearchClose"
          aria-label="Close search">
    <i class="fi fi-rr-cross-small" aria-hidden="true"></i>
  </button>
</form>

<!-- ===== Bottom tabs =================================================== -->
<nav class="tw-mnav-tabs" id="twMnavTabs" aria-label="Main">
  <?php foreach ($__mnTabs as $t): ?>
    <?php
      $isMenu   = $t['key'] === 'menu';
      $row      = $isMenu ? null : $__mnFind($t['key']);
      $isActive = !$isMenu && $__mnActive === $t['key'];
      $cls      = 'tw-mnav-tab' . ($isActive ? ' is-on' : '') . (!empty($t['raised']) ? ' tw-mnav-tab--raised' : '');
    ?>
    <?php if ($isMenu): ?>
      <button type="button" class="<?= $cls ?>" id="twMnavMenuBtn"
              aria-expanded="false" aria-controls="twMnavSheet">
        <span class="tw-mnav-tab-ico"><i class="<?= $t['icon'] ?>" aria-hidden="true"></i></span>
        <span class="tw-mnav-tab-lbl"><?= $t['label'] ?></span>
      </button>
    <?php else: ?>
      <a class="<?= $cls ?>" href="<?= $__mnHref($row['href'] ?? 'index.php') ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
        <span class="tw-mnav-tab-ico"><i class="<?= $t['icon'] ?>" aria-hidden="true"></i></span>
        <span class="tw-mnav-tab-lbl"><?= $t['label'] ?></span>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>

<!-- ===== Menu sheet ==================================================== -->
<div class="tw-mnav-scrim" id="twMnavScrim" hidden></div>
<aside class="tw-mnav-sheet" id="twMnavSheet" role="dialog" aria-modal="true"
       aria-label="Menu" tabindex="-1" hidden>

  <?php /* The grab handle. A real button rather than a decorative bar: it is
           what a thumb reaches for, so it closes the sheet on tap as well as
           on drag, and it is the first stop in the focus trap. */ ?>
  <button type="button" class="tw-sheet-grab" id="twMnavGrab" aria-label="Close menu">
    <span aria-hidden="true"></span>
  </button>

  <?php if ($__sbLoggedIn): ?>
  <div class="tw-sheet-id">
    <a class="tw-sheet-who" href="<?= $__mnHref('profile.php') ?>">
      <span class="tw-sheet-avatar">
        <?php if ($__sbAvatarRel): ?>
          <img src="<?= WEB_ROOT ?>public/authenticated/<?= $__sbAvatarRel ?>" alt="">
        <?php else: ?>
          <?= htmlspecialchars($__sbInitials) ?>
        <?php endif; ?>
      </span>
      <span class="tw-sheet-name">
        <b><?= htmlspecialchars($__sbName !== '' ? $__sbName : 'Your account') ?></b>
        <small><?= htmlspecialchars($__mnEmail) ?></small>
      </span>
    </a>
    <span class="tw-sheet-credit">
      <small>STORE CREDIT</small>
      <b><?= function_exists('formatCurrency') ? formatCurrency((float)$__sbStoreCredit) : ('&#8369;' . number_format((float)$__sbStoreCredit, 2)) ?></b>
    </span>
  </div>
  <?php endif; ?>

  <?php if ($__sbLoggedIn): ?>
  <div class="tw-sheet-tiles">
    <?php foreach ($__mnTileKeys as $__k): ?>
      <?php
        $__row = $__mnFind($__k);
        if (!$__row) { continue; }
        $__on = $__mnActive === $__k;
      ?>
      <a class="tw-sheet-tile<?= $__on ? ' is-on' : '' ?>"
         href="<?= $__mnHref($__row['href']) ?>"<?= $__on ? ' aria-current="page"' : '' ?>>
        <svg class="tw-sheet-tile-ico" viewBox="0 0 24 24" width="24" height="24" fill="none"
             stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true"><?= $__mnTileIcon[$__k] ?></svg>
        <span class="tw-sheet-tile-lbl"><?= htmlspecialchars($__row['label']) ?></span>
        <?php if ($__k === 'notifications'): ?>
          <span class="tw-sheet-tile-badge" id="twMnavRowNotifBadge" <?= $__sbUnreadCount > 0 ? '' : 'hidden' ?>><?= $__sbUnreadCount > 99 ? '99+' : $__sbUnreadCount ?></span>
        <?php elseif ($__k === 'cart'): ?>
          <span class="tw-sheet-tile-badge cart-count" id="twMnavTileCartBadge" <?= $__mnCart > 0 ? '' : 'hidden' ?>><?= $__mnCart > 99 ? '99+' : $__mnCart ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php /* The Settings three-way, markup for markup. theme.js already
           delegates on [data-theme-choice] and keeps aria-pressed in sync,
           so this needs no script of its own. */ ?>
  <div class="tw-theme-choice tw-sheet-theme" role="group" aria-label="Theme">
    <button type="button" data-theme-choice="light"  aria-pressed="false">Light</button>
    <button type="button" data-theme-choice="dark"   aria-pressed="false">Dark</button>
    <button type="button" data-theme-choice="system" aria-pressed="false">System</button>
  </div>

  <?php if ($__sbLoggedIn): ?>
    <?php /* The sidebar's own logout link - same href, same confirm hooks. */ ?>
    <a href="<?= WEB_ROOT ?>public/authenticated/auth/logout.php?ns=TECHWRLD_CUST" class="tw-sheet-logout"
       data-confirm="Are you sure you want to log out?" data-confirm-ok="Log out">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M14 3.5H6.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2H14"/><path d="M17.5 8.5 21 12l-3.5 3.5"/><path d="M21 12H9.5"/>
      </svg>
      Log out
    </a>
  <?php endif; ?>
</aside>

<style>
/* =========================================================================
   Everything is inside this one query. Above 767.98px not a single selector
   here matches, so the desktop sidebar and the hover rail are untouched.
   ====================================================================== */
@media (max-width: 767.98px) {

  /* --- Hide the desktop furniture -------------------------------------
     #customerSidebar beats .customer-sidebar in theme-light.css and
     customer-dark.css on specificity alone, so no !important is needed for
     the panel, its backdrop or its pull-tab.
     .floating-cart-btn is the one exception - see the note on it below. */
  #customerSidebar,
  .customer-sidebar-backdrop,
  .customer-menu-toggle { display: none; }

  /* The FAB sets `opacity/transform/pointer-events` inline via .show from
     footer.php's JS, and that class is added at runtime after this sheet is
     parsed. display:none on the element wins over those, but the rule has to
     out-specify `.floating-cart-btn.show` (0,2,0) - hence the doubled class
     rather than !important. Its job moves to the top bar's cart button. */
  .floating-cart-btn.floating-cart-btn { display: none; }

  /* Content gets the full width, clear of both bars. The bottom clearance is
     on <body> rather than on <main>: only two customer pages have a <main> at
     all (products.php and dashboard.php), so a rule written against <main>
     would silently do nothing on the other ten. Every page has a body, the
     footer is inside it, and padding there pushes the last element - the
     copyright line included - clear of the tab bar. Putting the same value on
     <main> as well would just stack two 84px gaps on the two pages that have
     one. 84px is the 56px bar plus 28px of air, and the safe-area inset is
     added on top for the home-indicator strip. */
  body {
    padding: 0 0 calc(84px + env(safe-area-inset-bottom));
  }
  main, .account-page, .container {
    padding-left: 16px;
    padding-right: 16px;
  }
  main { padding-top: 12px; }

  /* --- Top bar --------------------------------------------------------- */
  .tw-mnav-top {
    position: sticky; top: 0; z-index: 1400;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    height: 56px; padding: 0 12px;
    padding-top: env(safe-area-inset-top);
    box-sizing: content-box;
    background: var(--tw-mnav-bar, #0f1c30);
    border-bottom: 1px solid var(--tw-mnav-line, rgba(120,170,230,.16));
    backdrop-filter: saturate(140%) blur(8px);
  }
  /* 44px tall, so the tap target is the whole brand lockup rather than the
     26px logo it used to measure. */
  .tw-mnav-brand {
    display: flex; align-items: center; gap: 8px; text-decoration: none;
    min-width: 0; min-height: 44px;
  }
  .tw-mnav-brand img { width: 26px; height: 26px; flex: none; }
  .tw-mnav-brand span { font-size: 17px; font-weight: 800; color: var(--tw-mnav-ink, #fff); white-space: nowrap; }
  .tw-mnav-actions { display: flex; align-items: center; gap: 2px; }
  .tw-mnav-btn {
    position: relative; display: flex; align-items: center; justify-content: center;
    width: 44px; height: 44px; border-radius: 12px; text-decoration: none;
    color: var(--tw-mnav-ink, #fff); font-size: 17px;
  }
  .tw-mnav-btn:active { background: var(--tw-mnav-press, rgba(255,255,255,.08)); }
  /* The search control is a <button> now, not an <a>. Scoped to button so the
     anchor siblings keep exactly the styling they had. The :active background
     above still wins over this (0,2,0 beats 0,1,1). */
  button.tw-mnav-btn {
    background: none; border: 0; margin: 0; padding: 0;
    font: inherit; cursor: pointer; -webkit-appearance: none; appearance: none;
  }

  /* --- Search row ------------------------------------------------------ */
  .tw-mnav-search {
    position: sticky; top: calc(56px + env(safe-area-inset-top)); z-index: 1399;
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    background: var(--tw-mnav-bar, #0f1c30);
    border-bottom: 1px solid var(--tw-mnav-line, rgba(120,170,230,.16));
  }
  /* display:flex above would otherwise defeat the hidden attribute. */
  .tw-mnav-search[hidden] { display: none; }
  .tw-mnav-search > i {
    flex: none; font-size: 15px; opacity: .55;
    color: var(--tw-mnav-ink, #fff);
  }
  .tw-mnav-search input {
    flex: 1; min-width: 0; height: 40px; padding: 0 12px;
    border-radius: 10px;
    background: var(--tw-mnav-press, rgba(255,255,255,.08));
    border: 1px solid var(--tw-mnav-line, rgba(120,170,230,.16));
    color: var(--tw-mnav-ink, #fff);
    font: inherit; font-size: 16px;   /* 16px: anything smaller makes iOS zoom on focus */
    -webkit-appearance: none; appearance: none;
  }
  .tw-mnav-search input::placeholder { color: var(--tw-mnav-ink, #fff); opacity: .45; }
  .tw-mnav-search input:focus { outline: none; border-color: #c23a63; }
  /* The clear button Safari adds sits on a dark field and renders as a dark
     blob on dark; the close button beside it already does this job. */
  .tw-mnav-search input::-webkit-search-cancel-button { -webkit-appearance: none; }
  .tw-mnav-search-close {
    flex: none; width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    background: none; border: 0; padding: 0;
    color: var(--tw-mnav-ink, #fff); font-size: 17px; cursor: pointer;
  }
  .tw-mnav-search-close:active { background: var(--tw-mnav-press, rgba(255,255,255,.08)); }
  .tw-mnav-badge {
    position: absolute; top: 4px; right: 4px; min-width: 18px; height: 18px;
    padding: 0 5px; border-radius: 9px;
    background: #c23a63; color: #fff;
    border: 2px solid var(--tw-mnav-bar, #0f1c30);
    font-size: 10.5px; font-weight: 700; line-height: 14px; text-align: center;
  }
  .tw-mnav-badge[hidden] { display: none; }

  /* --- Bottom tabs ------------------------------------------------------ */
  .tw-mnav-tabs {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 1400;
    display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
    background: var(--tw-mnav-bar, #0f1c30);
    border-top: 1px solid var(--tw-mnav-line, rgba(120,170,230,.16));
    padding-bottom: env(safe-area-inset-bottom);
  }
  .tw-mnav-tab {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px;
    min-height: 56px; min-width: 0; padding: 6px 2px; border: 0; background: none; cursor: pointer;
    text-decoration: none; color: var(--tw-mnav-idle, #93a7c2); font: inherit;
  }
  .tw-mnav-tab-ico {
    display: flex; align-items: center; justify-content: center;
    width: 40px; height: 26px; border-radius: 999px; font-size: 17px;
  }
  .tw-mnav-tab-lbl { font-size: 11px; font-weight: 600; }
  .tw-mnav-tab.is-on { color: var(--tw-mnav-on-ink, #ffc2d3); }
  .tw-mnav-tab.is-on .tw-mnav-tab-ico { background: var(--tw-mnav-on-bg, rgba(224,86,122,.16)); }

  /* AI Build: a raised tile, lifted out of the bar. */
  .tw-mnav-tab--raised .tw-mnav-tab-ico {
    width: 52px; height: 52px; margin-top: -22px; border-radius: 16px;
    background: linear-gradient(135deg, #c23a63, #6b5bc4);
    color: #fff; font-size: 20px;
    box-shadow: 0 8px 20px rgba(194,58,99,.4);
  }
  .tw-mnav-tab--raised.is-on .tw-mnav-tab-ico { background: linear-gradient(135deg, #c23a63, #6b5bc4); }

  /* --- Menu sheet -------------------------------------------------------
     Nothing inside the sheet is positioned. The grab handle, the profile row,
     the tile grid, the theme switch and Log out are five block children
     stacked by the sheet's own column gap, so none of them can land on top of
     another however tall the identity line or however long the address. The
     sheet is what scrolls when they do not all fit. */
  .tw-mnav-scrim {
    position: fixed; inset: 0; z-index: 1500;
    background: var(--tw-mnav-scrim, rgba(0,0,0,.55));
    opacity: 0; transition: opacity 260ms ease-out;
  }
  .tw-mnav-scrim[hidden] { display: none; }
  .tw-mnav-scrim.is-on { opacity: 1; }

  .tw-mnav-sheet {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 1501;
    display: flex; flex-direction: column; gap: 14px;
    max-height: 88vh; overflow-y: auto; overscroll-behavior: contain;
    padding: 10px 16px calc(20px + env(safe-area-inset-bottom));
    border-radius: 28px 28px 0 0;
    background: var(--tw-sheet-bg, #0f1c30);
    color: var(--tw-sheet-ink, #d6e2f1);
    box-shadow: 0 -18px 50px rgba(0,0,0,.4);
    /* Transform only - no height animation, nothing that forces layout. */
    transform: translateY(100%);
    transition: transform 260ms ease-out;
  }
  /* The dialog is focusable so it can take focus on open; it is not a
     control, so it does not draw a ring for having it. */
  .tw-mnav-sheet:focus { outline: none; }
  .tw-mnav-sheet[hidden] { display: none; }
  .tw-mnav-sheet.is-on { transform: translateY(0); }
  /* While a finger is on the sheet the transform is written frame by frame
     from JS, so the transition has to be out of the way or every move trails
     260ms behind the thumb. */
  .tw-mnav-sheet.is-dragging { transition: none; }

  /* Grab handle: the 40x5 bar the design calls for, centred in a button that
     spans the sheet, so what reads as a small bar is in fact a full-width,
     28px-tall target. */
  .tw-sheet-grab {
    flex: none; display: flex; align-items: center; justify-content: center;
    width: 100%; height: 28px; padding: 0; margin: 0;
    border: 0; background: none; cursor: grab; touch-action: none;
  }
  .tw-sheet-grab span {
    display: block; width: 40px; height: 5px; border-radius: 3px;
    background: var(--tw-sheet-grab, rgba(147,167,194,.5));
  }

  /* Profile row */
  .tw-sheet-id { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .tw-sheet-who {
    display: flex; align-items: center; gap: 10px;
    flex: 1 1 auto; min-width: 0; min-height: 44px;
    text-decoration: none; color: inherit;
  }
  .tw-sheet-avatar {
    flex: none; width: 44px; height: 44px; border-radius: 50%; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #c23a63, #4a5fc0); color: #fff;
    font-size: 15px; font-weight: 800; letter-spacing: .02em;
  }
  .tw-sheet-avatar img { width: 100%; height: 100%; object-fit: cover; }
  .tw-sheet-name { display: flex; flex-direction: column; min-width: 0; }
  .tw-sheet-name b { font-size: 16px; font-weight: 800; color: var(--tw-sheet-ink, #d6e2f1); }
  .tw-sheet-name small {
    font-size: 12px; color: var(--tw-sheet-muted, #93a7c2);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .tw-sheet-credit {
    flex: none; display: flex; flex-direction: column; align-items: flex-end; gap: 2px;
    padding: 7px 10px; border-radius: 12px;
    background: var(--tw-sheet-tile, rgba(20,36,60,.9));
    border: 1px solid var(--tw-sheet-tile-line, rgba(120,170,230,.16));
  }
  .tw-sheet-credit small {
    font-family: 'JetBrains Mono', ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 9px; font-weight: 600; letter-spacing: .1em;
    color: var(--tw-sheet-muted, #93a7c2);
  }
  .tw-sheet-credit b { font-size: 14px; font-weight: 800; color: var(--tw-sheet-credit, #ff9ab5); }

  /* Tile grid */
  .tw-sheet-tiles {
    display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px;
  }
  .tw-sheet-tile {
    position: relative;      /* only so the badge has something to sit in */
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
    min-width: 0; height: 92px; padding: 8px 6px; border-radius: 16px;
    text-decoration: none; text-align: center;
    background: var(--tw-sheet-tile, rgba(20,36,60,.9));
    border: 1px solid var(--tw-sheet-tile-line, rgba(120,170,230,.16));
    color: var(--tw-sheet-tile-ink, #d6e2f1);
  }
  .tw-sheet-tile-ico { flex: none; }
  .tw-sheet-tile-lbl { font-size: 13px; font-weight: 700; line-height: 1.15; }
  .tw-sheet-tile.is-on {
    background: var(--tw-sheet-on, rgba(224,86,122,.16));
    border-color: var(--tw-sheet-on-line, rgba(255,154,181,.4));
    color: var(--tw-sheet-on-ink, #ffc2d3);
  }
  .tw-sheet-tile-badge {
    position: absolute; top: 6px; right: 6px;
    min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px;
    background: #c23a63; color: #fff;
    font-size: 11px; font-weight: 800; line-height: 20px; text-align: center;
  }
  .tw-sheet-tile-badge[hidden] { display: none; }

  /* Theme switch. The design draws a 38px pill; the buttons are 44px here so
     they clear the tap-target floor the same brief sets, with the 4px track
     padding kept - the switch reads as the same control, six pixels taller. */
  .tw-sheet-theme {
    display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0;
    padding: 4px; border-radius: 14px;
    background: var(--tw-sheet-track, rgba(20,36,60,.9));
    border: 1px solid var(--tw-sheet-tile-line, rgba(120,170,230,.16));
  }
  /* theme-light.css styles every .tw-theme-choice button through
     `html[data-mode="dark"] .tw-theme-choice button` (0,2,2) and paints the
     pressed one with the shared brand gradient. Both are written here at a
     depth that reaches past them, so the switch inside the sheet is the
     segmented control the design draws rather than the Settings page's pill
     row - and the pressed segment is a flat fill, not a gradient. */
  html .tw-mnav-sheet .tw-sheet-theme button {
    min-width: 0; height: 44px; border: 0; border-radius: 10px; cursor: pointer;
    background: none; color: var(--tw-sheet-seg-ink, #93a7c2);
    font: inherit; font-size: 13px; font-weight: 700;
  }
  /* The design's active fill for dark is #e0567a, which carries white at
     3.63:1 - under the 4.5:1 the same brief asks for on 13px text. #c23a63 is
     the accent one step down, already the badge fill throughout the sheet,
     and carries white at 6.4:1. */
  html .tw-mnav-sheet .tw-sheet-theme button[aria-pressed="true"] {
    background: var(--tw-sheet-seg-on, #c23a63);
    background-image: none;
    border-color: transparent;
    color: var(--tw-sheet-seg-on-ink, #fff);
    box-shadow: var(--tw-sheet-seg-shadow, none);
  }

  /* Log out */
  .tw-sheet-logout {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; height: 48px; border-radius: 14px; text-decoration: none;
    font-size: 15px; font-weight: 800;
    color: var(--tw-sheet-danger, #ff8fa8);
    border: 1px solid var(--tw-sheet-danger-line, rgba(255,143,168,.45));
    background: none;
  }

  body.tw-mnav-open { overflow: hidden; }

  @media (prefers-reduced-motion: reduce) {
    .tw-mnav-sheet, .tw-mnav-scrim { transition: none; }
  }
}

/* Light values. Same single breakpoint, so desktop light mode is untouched. */
@media (max-width: 767.98px) {
  html[data-mode="light"] {
    --tw-mnav-bar: rgba(255,255,255,.92);
    --tw-mnav-line: #e3e9f2;
    --tw-mnav-ink: #16233a;
    --tw-mnav-muted: #5d6f86;
    --tw-mnav-idle: #46586f;
    --tw-mnav-on-bg: #fdecf1;
    --tw-mnav-on-ink: #c23a63;
    --tw-mnav-press: rgba(22,35,58,.06);
    --tw-mnav-scrim: rgba(15,26,44,.42);

    --tw-sheet-bg: #ffffff;
    --tw-sheet-ink: #16233a;
    --tw-sheet-muted: #5d6f86;
    --tw-sheet-grab: #cbd5e3;
    --tw-sheet-tile: #f4f7fb;
    --tw-sheet-tile-line: #e3e9f2;
    --tw-sheet-tile-ink: #2b3a52;
    --tw-sheet-on: #fdecf1;
    --tw-sheet-on-line: #f0b6c7;
    --tw-sheet-on-ink: #a8264d;
    --tw-sheet-credit: #c23a63;
    --tw-sheet-track: #f4f7fb;
    --tw-sheet-seg-ink: #46586f;
    --tw-sheet-seg-on: #ffffff;
    --tw-sheet-seg-on-ink: #16233a;
    --tw-sheet-seg-shadow: 0 1px 3px rgba(22,35,58,.18);
    --tw-sheet-danger: #b4234a;
    --tw-sheet-danger-line: #f0b6c7;
  }
}

/* Hide the phone furniture everywhere else, so a stray element can never
   appear on the desktop layout even for a frame before the query applies. */
@media (min-width: 768px) {
  /* .tw-mnav-search belongs here too, and its [hidden] rule is not enough on
     its own: once opened on a phone the attribute is gone, so rotating to
     landscape or widening past 768px would leave a stray search row sitting
     above the desktop layout. */
  .tw-mnav-top, .tw-mnav-tabs, .tw-mnav-sheet, .tw-mnav-scrim,
  .tw-mnav-search { display: none; }
}
</style>

<script>
(function () {
  /* The sheet. Open/close is a class toggle on two elements; everything else
     here is the behaviour a dialog owes you - scroll lock, Esc, a focus trap,
     focus returned to the button that opened it - plus drag-to-dismiss. */
  var btn   = document.getElementById('twMnavMenuBtn');
  var sheet = document.getElementById('twMnavSheet');
  var scrim = document.getElementById('twMnavScrim');
  var grab  = document.getElementById('twMnavGrab');
  if (!btn || !sheet || !scrim) { return; }

  var FOCUSABLE = 'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])';
  var lastFocus = null;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function open() {
    lastFocus = document.activeElement;
    sheet.hidden = false;
    scrim.hidden = false;
    // Next frame, so the browser has the element laid out at translateY(100%)
    // before the class that animates it to 0 lands - otherwise it just appears.
    requestAnimationFrame(function () {
      sheet.classList.add('is-on');
      scrim.classList.add('is-on');
    });
    document.body.classList.add('tw-mnav-open');
    btn.setAttribute('aria-expanded', 'true');
    /* Focus the dialog, not its first control. Moving focus straight to the
       grab handle made the browser paint a focus ring around it on a tap,
       which read as a stray outlined box across the top of the sheet; and a
       dialog that takes focus itself is what a screen reader expects, so the
       label is announced before the first tile. Tab from here still lands on
       the handle, where the trap below keeps it. */
    sheet.focus();
  }

  function close() {
    sheet.classList.remove('is-on', 'is-dragging');
    sheet.style.transform = '';
    scrim.classList.remove('is-on');
    document.body.classList.remove('tw-mnav-open');
    btn.setAttribute('aria-expanded', 'false');
    // Wait out the transition before hiding, or it vanishes instead of sliding.
    var done = function () {
      sheet.hidden = true;
      scrim.hidden = true;
      sheet.removeEventListener('transitionend', done);
    };
    if (reduced) {
      done();
    } else {
      sheet.addEventListener('transitionend', done);
      setTimeout(done, 400);   // belt and braces if transitionend never fires
    }
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }

  btn.addEventListener('click', function () {
    (sheet.classList.contains('is-on') ? close : open)();
  });
  scrim.addEventListener('click', close);
  if (grab) { grab.addEventListener('click', close); }

  /* Drag to dismiss. Pointer events rather than touch events, so a mouse and
     a stylus work the same way. Only downward movement is honoured - dragging
     up would peel the sheet off the bottom edge and show the page behind it.
     More than 80px closes; less springs back. A drag that starts while the
     sheet is scrolled is left to the scroller, so a flick through a long list
     never turns into a dismissal. */
  var startY = null, dy = 0;
  function dragStart(e) {
    if (!sheet.classList.contains('is-on')) { return; }
    var onGrab = grab && (e.target === grab || grab.contains(e.target));
    if (sheet.scrollTop > 0 && !onGrab) { return; }
    startY = e.clientY; dy = 0;
    sheet.classList.add('is-dragging');
  }
  function dragMove(e) {
    if (startY === null) { return; }
    dy = Math.max(0, e.clientY - startY);
    sheet.style.transform = 'translateY(' + dy + 'px)';
  }
  function dragEnd() {
    if (startY === null) { return; }
    startY = null;
    sheet.classList.remove('is-dragging');
    sheet.style.transform = '';
    if (dy > 80) { close(); }
    dy = 0;
  }
  sheet.addEventListener('pointerdown', dragStart);
  sheet.addEventListener('pointermove', dragMove);
  sheet.addEventListener('pointerup', dragEnd);
  sheet.addEventListener('pointercancel', dragEnd);

  document.addEventListener('keydown', function (e) {
    if (!sheet.classList.contains('is-on')) { return; }
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') { return; }
    // Focus trap: wrap at both ends rather than letting Tab walk out into the
    // page behind the scrim, which cannot be clicked anyway.
    var items = sheet.querySelectorAll(FOCUSABLE);
    if (!items.length) { return; }
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  /* Badge mirroring.
     Both counts already have exactly one updater each, and neither knows
     about these new elements: the notification poller writes
     #customerSidebarNotifBadge, and footer.php's updateCartCount() writes
     #cartCount by id. Rather than add a second fetch for the same numbers,
     watch those two and copy. main.js's own updateCartDisplay() also matches
     .cart-count, so the cart badges are covered twice over - harmless, since
     both write the same value. */
  function mirror(srcId, targets) {
    var src = document.getElementById(srcId);
    if (!src || !window.MutationObserver) { return; }
    var apply = function () {
      var txt = (src.textContent || '').trim();
      var n = parseInt(txt, 10);
      var show = txt !== '' && !isNaN(n) ? n > 0 : false;
      // The sidebar badge hides itself with an inline style, the cart one too.
      if (src.style && src.style.display === 'none') { show = false; }
      targets.forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) { return; }
        el.textContent = txt;
        el.hidden = !show;
      });
    };
    new MutationObserver(apply).observe(src, {
      childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['style']
    });
    apply();
  }
  mirror('customerSidebarNotifBadge', ['twMnavNotifBadge', 'twMnavRowNotifBadge']);
  mirror('cartCount', ['twMnavCartBadge', 'twMnavTileCartBadge']);

  /* The scroll-to-top button gets out of the way of whatever is under it.
     main.js parks it bottom-right; tw-mobile.css lifts it clear of the tab
     bar, but on a two-column product grid - and on the cart, checkout and
     settings cards - there is always some text or some control in that
     corner. The alternative the brief offers, a permanent 64px dead lane down
     the right of the grid, costs a fifth of a 360px screen on every row to
     keep one button clear on a few.

     The test is three points inside the button put through
     elementsFromPoint, which sees past the button itself. A wrapper <div>
     whose text all lives in children is not a hit - only an element with text
     of its own, or a control, counts - so the button only ducks when it is
     actually covering something you would want to read or press. */
  var fab = null;
  function hasOwnText(el) {
    for (var i = 0; i < el.childNodes.length; i++) {
      var n = el.childNodes[i];
      if (n.nodeType === 3 && n.textContent.trim() !== '') { return true; }
    }
    return false;
  }
  function fabDuck() {
    if (window.innerWidth >= 1100) { return; }
    if (!fab) { fab = document.querySelector('.scroll-to-top'); }
    if (!fab) { return; }
    var r = fab.getBoundingClientRect();
    if (r.width === 0 || !document.elementsFromPoint) { return; }
    /* Point sampling finds what is directly under the button; a rect test on
       the children of whatever it lands in finds what is only under a corner
       of it. Sampling alone missed a one-line caption clipping the button's
       edge ("12% VAT added" on checkout), and a rect test over the whole
       document on every scroll frame is far too much work for the question.
       Together they are both exact and cheap: elementsFromPoint narrows the
       page down to a handful of boxes, and only those boxes' own children get
       measured. */
    var pts = [
      [r.left + r.width / 2, r.top + r.height / 2],
      [r.left + 2, r.top + 2],
      [r.right - 2, r.top + 2],
      [r.left + 2, r.bottom - 2],
      [r.right - 2, r.bottom - 2]
    ];
    var overlapsFab = function (el) {
      var b = el.getBoundingClientRect();
      return b.width > 0 && b.height > 0 &&
        Math.min(r.right, b.right) - Math.max(r.left, b.left) > 2 &&
        Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top) > 2;
    };
    var counts = function (el) {
      if (el === fab || fab.contains(el) || el.closest('#twMnavTabs, .tw-mnav-top')) { return false; }
      var s = getComputedStyle(el);
      if (s.visibility === 'hidden' || s.opacity === '0') { return false; }
      return /^(a|button|input|select|textarea|img)$/i.test(el.tagName) || hasOwnText(el);
    };

    var hit = false;
    for (var p = 0; p < pts.length && !hit; p++) {
      var stack = document.elementsFromPoint(pts[p][0], pts[p][1]);
      for (var i = 0; i < stack.length && !hit; i++) {
        var el = stack[i];
        if (el === fab || fab.contains(el)) { continue; }
        if (el === document.body || el === document.documentElement) { break; }
        if (el.closest('#twMnavTabs, .tw-mnav-top')) { continue; }
        if (counts(el)) { hit = true; break; }
        // A wrapper with no text of its own: measure what it holds.
        for (var c = 0; c < el.children.length; c++) {
          var kid = el.children[c];
          if (counts(kid) && overlapsFab(kid)) { hit = true; break; }
        }
      }
    }
    fab.classList.toggle('tw-fab-duck', hit);
  }
  var ticking = false;
  function onScroll() {
    if (ticking) { return; }
    ticking = true;
    requestAnimationFrame(function () { fabDuck(); ticking = false; });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  onScroll();

  /* ---- Search ----------------------------------------------------------
     Opens the field under the top bar. Submitting is left entirely to the
     form's own GET action (products.php?q=...), so the existing search runs
     exactly as it does from the desktop sidebar. */
  var sBtn   = document.getElementById('twMnavSearchBtn');
  var sForm  = document.getElementById('twMnavSearch');
  var sInput = document.getElementById('twMnavSearchInput');
  var sClose = document.getElementById('twMnavSearchClose');

  function setSearch(open) {
    sForm.hidden = !open;
    sBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) { return; }
    sInput.focus();
    /* Caret to the end of any prefilled query. Without this the whole value
       is selected on some mobile browsers, so the first keystroke wipes the
       search being refined. */
    var v = sInput.value;
    sInput.value = '';
    sInput.value = v;
  }

  if (sBtn && sForm && sInput) {
    sBtn.addEventListener('click', function () { setSearch(sForm.hidden); });

    if (sClose) {
      sClose.addEventListener('click', function () { setSearch(false); sBtn.focus(); });
    }

    /* An empty submit would reload products.php with ?q= and read as the
       button being broken all over again - keep the field open instead. */
    sForm.addEventListener('submit', function (e) {
      if (sInput.value.trim() === '') { e.preventDefault(); sInput.focus(); }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !sForm.hidden) { setSearch(false); sBtn.focus(); }
    });
  }
})();
</script>

<?php endif; /* TW_MOBILE_NAV */ ?>
