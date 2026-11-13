add_to_cart.php
<?php
require_once __DIR__ . '/../../../config/functions.php';
require_login();

$productId = (int)($_GET['id'] ?? 0);
$qty = (int)($_GET['qty'] ?? 1);
$redirect = $_GET['redirect'] ?? (WEB_ROOT . 'public/authenticated/customer/products.php');

$res = addProductToCart(getUserId(), $productId, $qty);
if ($res['success']) {
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '?error=' . urlencode($res['message'] ?? 'Unable to add to cart'));
exit;
