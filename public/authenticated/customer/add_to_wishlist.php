<?php
// customer/add_to_wishlist.php - The standalone "add to wishlist" action no
// longer exists; wishlist entries are now derived automatically from cart
// items that go out of stock (see cart.php). Redirect any old/bookmarked
// links to the cart instead.
require_once __DIR__ . '/../../../config/functions.php';
require_login();
header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
exit;
