<?php 
// WEB_ROOT will be defined by config/functions.php
require_once __DIR__ . '/../../../../config/functions.php';
?>
<!-- Navigation -->
<nav class="navbar">
  <div class="container">
    <ul class="nav-links">
      <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/index.php" class="<?= ($activeNav ?? '') === 'home' ? 'active' : '' ?>">HOME</a></li>
      <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="<?= ($activeNav ?? '') === 'products' ? 'active' : '' ?>">PRODUCTS</a></li>
      <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=desktops" class="<?= ($activeNav ?? '') === 'desktops' ? 'active' : '' ?>">DESKTOP</a></li>
      <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?category=laptops" class="<?= ($activeNav ?? '') === 'laptops' ? 'active' : '' ?>">LAPTOP</a></li>
      <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/download.php" class="<?= ($activeNav ?? '') === 'download' ? 'active' : '' ?>">DOWNLOADS</a></li>
      <?php if (!empty($_SESSION['logged_in'])): ?>
        <?php $role = $_SESSION['user_role'] ?? 'customer'; ?>
        <?php if ($role === 'admin' || $role === 'super-admin' || $role === 'manager' || $role === 'owner'): ?>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/admin/dashboard.php">MANAGER</a></li>
        <?php endif; ?>
        <?php if ($role === 'super-admin' || $role === 'owner'): ?>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/super-admin/dashboard.php">OWNER</a></li>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/super-admin/security.php">SECURITY</a></li>
        <?php endif; ?>
      <?php endif; ?>
    </ul>
  </div>
</nav>