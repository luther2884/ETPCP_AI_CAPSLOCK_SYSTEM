<?php
// customer/wishlist.php - Wishlist is now shown inline inside the Cart page
// (out-of-stock cart items automatically appear in the Wishlist section
// there and return to Cart once restocked). Redirect any bookmarked/old
// links here to the merged page.
require_once __DIR__ . '/../../../config/functions.php';
require_login();
require_role(['customer']);
header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
exit;
