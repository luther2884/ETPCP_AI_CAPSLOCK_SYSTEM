<?php
// Shared header.php (minimal shared version)
require_once __DIR__ . '/../../../config/functions.php';
// WEB_ROOT will be defined by config/functions.php
?>
<!-- Anti-FOUC theme script -->
<script>document.documentElement.setAttribute('data-theme','light');</script>
<!-- Shared head assets -->
<script>(function () { try { var d = document; var hasFavicon = [].some.call(d.querySelectorAll('link[rel~="icon"]'), function (l) { return /logo\.ico$/i.test(l.getAttribute('href') || '') }); if (!hasFavicon) { var l = d.createElement('link'); l.rel = 'icon'; l.type = 'image/x-icon'; l.href = '<?= WEB_ROOT ?>public/shared/img/Logo.ico'; d.head && d.head.appendChild(l); var s = d.createElement('link'); s.rel = 'shortcut icon'; s.href = '<?= WEB_ROOT ?>public/shared/img/Logo.ico'; d.head && d.head.appendChild(s); } } catch (e) { } })();</script>

<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>
<script src="<?= WEB_ROOT ?>public/shared/assets/js/main.js"></script>

<?php /* In-page confirm()/alert() replacements - see the file itself for how to
         use data-confirm and uiConfirm(). Pages on this header (scan/verify,
         super-admin/security) call uiConfirm(), so it has to be here too. */ ?>
<?php include_once __DIR__ . '/ui-dialog.php'; ?>

<!-- Header -->
<header class="header">
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

        <button class="mobile-search-toggle" onclick="toggleMobileSearch()" aria-label="Toggle search">
            <i class="fi fi-bs-search"></i>
        </button>

        <div id="auth-section" style="display:flex;align-items:center;gap:10px;"></div>
    </div>
</header>

<div class="mobile-search-overlay" id="mobileSearchOverlay" onclick="closeMobileSearch()"></div>
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
    function toggleMobileSearch() {
        const overlay = document.getElementById('mobileSearchOverlay');
        const container = document.getElementById('mobileSearchContainer');
        if (container.classList.contains('active')) closeMobileSearch(); else openMobileSearch();
    }
    function openMobileSearch() { document.getElementById('mobileSearchOverlay').classList.add('active'); document.getElementById('mobileSearchContainer').classList.add('active'); document.body.style.overflow = 'hidden'; setTimeout(() => { const i = document.querySelector('#mobileSearchContainer input'); if (i) i.focus(); }, 100); }
    function closeMobileSearch() { document.getElementById('mobileSearchOverlay').classList.remove('active'); document.getElementById('mobileSearchContainer').classList.remove('active'); document.body.style.overflow = 'auto'; }
</script>

<?php /* window.TWCamera - see shared/includes/camera-support.php. The scan
         pages that use this header open a camera. */ ?>
<?php include_once __DIR__ . '/camera-support.php'; ?>
