<?php
// Guest header.php
require_once __DIR__ . '/../../../config/functions.php';
// WEB_ROOT will be defined by config/functions.php
?>
<!-- Anti-FOUC theme script -->
<script>document.documentElement.setAttribute('data-theme','light');</script>
<!-- Shared head assets (ensure styles load on all guest pages) -->
<script>(function(){try{var d=document;var hasFavicon=[].some.call(d.querySelectorAll('link[rel~="icon"]'),function(l){return /logo\.ico$/i.test(l.getAttribute('href')||'')});if(!hasFavicon){var l=d.createElement('link');l.rel='icon';l.type='image/x-icon';l.href='<?= WEB_ROOT ?>public/shared/img/Logo.ico';d.head&&d.head.appendChild(l);var s=d.createElement('link');s.rel='shortcut icon';s.href='<?= WEB_ROOT ?>public/shared/img/Logo.ico';d.head&&d.head.appendChild(s);}}catch(e){}})();</script>

<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>
<script src="<?= asset_url('public/shared/assets/js/main.js') ?>"></script>

<!-- Header -->
<header class="header">
  <!-- Inert unless a page (currently only guest/index.php) initializes
       Vanta.js on it - harmless empty div on every other guest page. -->
  <div id="heroVanta" class="hero-vanta" aria-hidden="true"></div>
  <div class="container">
    <div class="logo">
      <a href="<?= WEB_ROOT ?>public/guest/index.php" class="logo-link">
        <img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="TechWorld Logo" class="logo-img">
        <h2>TECHWORLD</h2>
      </a>
    </div>

    <form class="search-bar" action="<?= WEB_ROOT ?>public/guest/products.php" method="get">
      <input type="text" name="q" placeholder="Search products..." autocomplete="off">
      <button type="submit" aria-label="Search">
        <i class="fi fi-bs-search"></i>
      </button>
    </form>
    
    <!-- Mobile Search Toggle -->
    <button class="mobile-search-toggle" onclick="toggleMobileSearch()" aria-label="Toggle search">
      <i class="fi fi-bs-search"></i>
    </button>

    <div id="auth-section" style="display:flex;align-items:center;gap:10px;">
      <!-- Fallback link if JS is off (still opens modal when JS is on) -->
      <a class="login-btn" href="<?= WEB_ROOT ?>public/guest/login.php" onclick="event.preventDefault(); openLoginModal();">
        Login / Sign In
      </a>
    </div>
  </div>
</header>

<!-- Mobile Search Overlay -->
<div class="mobile-search-overlay" id="mobileSearchOverlay" onclick="closeMobileSearch()"></div>

<!-- Mobile Search Container -->
<div class="mobile-search-container" id="mobileSearchContainer">
  <div class="mobile-search-header">
    <h3>Search Products</h3>
    <button class="mobile-search-close" onclick="closeMobileSearch()" aria-label="Close search">
      <i class="fi fi-bs-cross"></i>
    </button>
  </div>
  
  <form class="mobile-search-form" action="<?= WEB_ROOT ?>public/guest/products.php" method="get">
    <input type="text" name="q" placeholder="Search products..." autocomplete="off" autofocus>
    <button type="submit" aria-label="Search">
      <i class="fi fi-bs-search"></i>
    </button>
  </form>
</div>

<script>
// Mobile Search Functions
function toggleMobileSearch() {
  const overlay = document.getElementById('mobileSearchOverlay');
  const container = document.getElementById('mobileSearchContainer');
  
  if (container.classList.contains('active')) {
    closeMobileSearch();
  } else {
    openMobileSearch();
  }
}

function openMobileSearch() {
  const overlay = document.getElementById('mobileSearchOverlay');
  const container = document.getElementById('mobileSearchContainer');
  
  overlay.classList.add('active');
  container.classList.add('active');
  document.body.style.overflow = 'hidden';
  
  // Focus on input after animation
  setTimeout(() => {
    const input = container.querySelector('input');
    if (input) input.focus();
  }, 100);
}

function closeMobileSearch() {
  const overlay = document.getElementById('mobileSearchOverlay');
  const container = document.getElementById('mobileSearchContainer');
  
  overlay.classList.remove('active');
  container.classList.remove('active');
  document.body.style.overflow = 'auto';
}

// Close mobile search on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeMobileSearch();
  }
});
</script>
<?php /* In-page confirm()/alert() replacements - see the file itself for
         how to use data-confirm and uiConfirm(). Included here so every page
         in this area gets them without a per-page include. */ ?>
<?php include_once __DIR__ . '/../../shared/includes/ui-dialog.php'; ?>
