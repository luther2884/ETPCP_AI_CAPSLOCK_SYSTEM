<?php 
// WEB_ROOT will be defined by config/functions.php
require_once __DIR__ . '/../../../config/functions.php';
?>
<!-- Navigation -->
<nav class="navbar">
  <div class="container">
    <!-- Mobile Navigation Toggle -->
    <button class="mobile-nav-toggle" onclick="toggleMobileNav()" aria-label="Toggle navigation menu">
      <i class="fi fi-bs-menu-burger"></i>
    </button>
    
    <!-- Desktop Navigation -->
    <ul class="nav-links">
      <li><a href="<?= WEB_ROOT ?>public/guest/index.php" class="<?= ($activeNav ?? '') === 'home' ? 'active' : '' ?>">HOME</a></li>
      <li><a href="<?= WEB_ROOT ?>public/guest/products.php" class="<?= ($activeNav ?? '') === 'products' ? 'active' : '' ?>">PRODUCTS</a></li>
      <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=desktops" class="<?= ($activeNav ?? '') === 'desktops' ? 'active' : '' ?>">DESKTOP</a></li>
      <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=laptops" class="<?= ($activeNav ?? '') === 'laptops' ? 'active' : '' ?>">LAPTOP</a></li>
    </ul>
  </div>
</nav>

<!-- Mobile Navigation Overlay -->
<div class="mobile-nav-overlay" id="mobileNavOverlay" onclick="closeMobileNav()"></div>

<!-- Mobile Navigation Menu -->
<div class="mobile-nav-menu" id="mobileNavMenu">
  <button class="mobile-nav-close" onclick="closeMobileNav()" aria-label="Close navigation menu">
    <i class="fi fi-bs-cross"></i>
  </button>
  
  <div class="mobile-nav-header">
    <h3>Navigation</h3>
  </div>
  
  <ul class="mobile-nav-links">
    <li><a href="<?= WEB_ROOT ?>public/guest/index.php" class="<?= ($activeNav ?? '') === 'home' ? 'active' : '' ?>" onclick="closeMobileNav()">HOME</a></li>
    <li><a href="<?= WEB_ROOT ?>public/guest/products.php" class="<?= ($activeNav ?? '') === 'products' ? 'active' : '' ?>" onclick="closeMobileNav()">PRODUCTS</a></li>
    <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=desktops" class="<?= ($activeNav ?? '') === 'desktops' ? 'active' : '' ?>" onclick="closeMobileNav()">DESKTOP</a></li>
    <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=laptops" class="<?= ($activeNav ?? '') === 'laptops' ? 'active' : '' ?>" onclick="closeMobileNav()">LAPTOP</a></li>
  </ul>
</div>

<script>
// Mobile Navigation Functions
function toggleMobileNav() {
  const overlay = document.getElementById('mobileNavOverlay');
  const menu = document.getElementById('mobileNavMenu');
  
  if (menu.classList.contains('active')) {
    closeMobileNav();
  } else {
    openMobileNav();
  }
}

function openMobileNav() {
  const overlay = document.getElementById('mobileNavOverlay');
  const menu = document.getElementById('mobileNavMenu');
  
  overlay.classList.add('active');
  menu.classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeMobileNav() {
  const overlay = document.getElementById('mobileNavOverlay');
  const menu = document.getElementById('mobileNavMenu');
  
  overlay.classList.remove('active');
  menu.classList.remove('active');
  document.body.style.overflow = 'auto';
}

// Close mobile nav on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeMobileNav();
  }
});

// Close mobile nav when clicking outside
document.addEventListener('click', function(e) {
  const menu = document.getElementById('mobileNavMenu');
  const toggle = document.querySelector('.mobile-nav-toggle');
  
  if (menu && menu.classList.contains('active') && 
      !menu.contains(e.target) && 
      !toggle.contains(e.target)) {
    closeMobileNav();
  }
});
</script>