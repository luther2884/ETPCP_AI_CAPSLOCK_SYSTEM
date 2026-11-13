<?php
// api/cart.php - Cart management API
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

$userId = getUserId();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'count':
            ob_clean();
            $count = getCartItemCount($userId);
            ob_clean();
            echo json_encode(['success' => true, 'count' => $count]);
            break;
            
        case 'items':
            ob_clean();
            $items = getCartItems($userId);
            ob_clean();
            echo json_encode(['success' => true, 'items' => $items]);
            break;
            
        case 'total':
            ob_clean();
            $total = getCartTotal($userId);
            ob_clean();
            echo json_encode(['success' => true, 'total' => $total]);
            break;
            
        default:
            ob_clean();
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    exit;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $productId = intval($input['product_id'] ?? 0);
            $quantity = intval($input['quantity'] ?? 1);
            
            if (!$productId || $quantity <= 0) {
                ob_clean();
                ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid product or quantity']);
                exit;
            }
            
            $result = addToCart($userId, $productId, $quantity);
            if ($result['success']) {
                if (function_exists('saveCartToDb')) {
                    $cart = capCartQuantitiesByStock($_SESSION['cart'][$userId] ?? []);
                    $_SESSION['cart'][$userId] = $cart;
                    saveCartToDb($userId, $cart);
                }
                ob_clean();
                $count = getCartItemCount($userId);
                ob_clean();
            echo json_encode([
                    'success' => true,
                    'message' => $result['message'] ?? 'Product added to cart',
                    'cart_count' => $count
                ]);
            } else {
                ob_clean();
                echo json_encode($result);
            }
            break;
            
        case 'update':
            $productId = intval($input['product_id'] ?? 0);
            $quantity = intval($input['quantity'] ?? 0);
            
            if (!$productId) {
                ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid product']);
                exit;
            }
            
            $result = updateCartItem($userId, $productId, $quantity);
            if ($result['success']) {
                if (function_exists('saveCartToDb')) {
                    $cart = capCartQuantitiesByStock($_SESSION['cart'][$userId] ?? []);
                    $_SESSION['cart'][$userId] = $cart;
                    saveCartToDb($userId, $cart);
                }
                $count = getCartItemCount($userId);
                $total = getCartTotal($userId);
                ob_clean();
            echo json_encode([
                    'success' => true,
                    'message' => 'Cart updated',
                    'cart_count' => $count,
                    'cart_total' => $total
                ]);
            } else {
                echo json_encode($result);
            }
            break;
            
        case 'remove':
            $productId = intval($input['product_id'] ?? 0);
            
            if (!$productId) {
                ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid product']);
                exit;
            }
            
            $result = removeFromCart($userId, $productId);
            if ($result['success']) {
                if (function_exists('saveCartToDb')) {
                    $cart = capCartQuantitiesByStock($_SESSION['cart'][$userId] ?? []);
                    $_SESSION['cart'][$userId] = $cart;
                    saveCartToDb($userId, $cart);
                }
                $count = getCartItemCount($userId);
                $total = getCartTotal($userId);
                ob_clean();
            echo json_encode([
                    'success' => true,
                    'message' => 'Product removed from cart',
                    'cart_count' => $count,
                    'cart_total' => $total
                ]);
            } else {
                echo json_encode($result);
            }
            break;
            
        case 'clear':
            $result = clearCart($userId);
            if (function_exists('clearCartInDb')) { 
                clearCartInDb($userId); 
            }
            if (function_exists('saveCartToDb')) {
                saveCartToDb($userId, []);
            }
            ob_clean();
            echo json_encode($result);
            break;
            
        default:
            ob_clean();
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    exit;
}

ob_clean();
echo json_encode(['success' => false, 'message' => 'Method not allowed']);

// Cart helper functions - using the one from config/functions.php

function getCartItems($userId) {
    if (!isset($_SESSION['cart'][$userId])) {
        return [];
    }
    
    $cart = $_SESSION['cart'][$userId];
    $items = [];
    
    try {
        $pdo = getConnection();
        foreach ($cart as $productId => $quantity) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if ($product) {
                $items[] = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'image_url' => $product['image_url'],
                    'quantity' => $quantity,
                    'subtotal' => $product['price'] * $quantity,
                    'stock' => $product['stock']
                ];
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting cart items: " . $e->getMessage());
    }
    
    return $items;
}

function getCartTotal($userId) {
    $items = getCartItems($userId);
    $total = 0;
    
    foreach ($items as $item) {
        $total += $item['subtotal'];
    }
    
    return $total;
}

function addToCart($userId, $productId, $quantity) {
    try {
        // Check if product exists and has sufficient stock
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if (!$product) {
            return ['success' => false, 'message' => 'Product not found'];
        }
        
        // Initialize cart if not exists
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        if (!isset($_SESSION['cart'][$userId])) {
            $_SESSION['cart'][$userId] = [];
        }
        
        // Check current quantity in cart
        $currentQuantity = $_SESSION['cart'][$userId][$productId] ?? 0;

        // Out-of-stock products can still be saved to the cart as a Wishlist
        // entry (shown in the Wishlist section on cart.php) - there's nothing
        // to reserve, so this just ensures a quantity of 1 is saved rather
        // than rejecting like a normal stock-checked add. It automatically
        // returns to the Cart section once the product is restocked.
        if ((int)$product['stock'] <= 0) {
            $_SESSION['cart'][$userId][$productId] = max($currentQuantity, 1);
            return ['success' => true, 'message' => "Saved to your Wishlist - you'll see it back in your cart once it's restocked"];
        }

        $newQuantity = $currentQuantity + $quantity;

        if ($newQuantity > $product['stock']) {
            return ['success' => false, 'message' => 'Insufficient stock available'];
        }

        $_SESSION['cart'][$userId][$productId] = $newQuantity;

        return ['success' => true, 'message' => 'Product added to cart'];
        
    } catch (PDOException $e) {
        error_log("Error adding to cart: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred'];
    }
}

function updateCartItem($userId, $productId, $quantity) {
    try {
        if (!isset($_SESSION['cart'][$userId][$productId])) {
            return ['success' => false, 'message' => 'Product not in cart'];
        }
        
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$userId][$productId]);
            if (function_exists('untagCartBundleProduct')) { untagCartBundleProduct($userId, $productId); }
            return ['success' => true, 'message' => 'Product removed from cart'];
        }
        
        // Check stock
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $stock = $stmt->fetchColumn();
        
        if ($stock === false) {
            return ['success' => false, 'message' => 'Product not found'];
        }
        
        if ($quantity > $stock) {
            return ['success' => false, 'message' => 'Insufficient stock available'];
        }
        
        $_SESSION['cart'][$userId][$productId] = $quantity;
        
        return ['success' => true, 'message' => 'Cart updated'];
        
    } catch (PDOException $e) {
        error_log("Error updating cart: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred'];
    }
}

function removeFromCart($userId, $productId) {
    if (!isset($_SESSION['cart'][$userId][$productId])) {
        return ['success' => false, 'message' => 'Product not in cart'];
    }

    unset($_SESSION['cart'][$userId][$productId]);
    if (function_exists('untagCartBundleProduct')) { untagCartBundleProduct($userId, $productId); }

    return ['success' => true, 'message' => 'Product removed from cart'];
}

function clearCart($userId) {
    if (!$userId) {
        return ['success' => false, 'message' => 'Invalid user'];
    }
    
    // Clear only the specific user's cart from session
    if (isset($_SESSION['cart'][$userId])) {
        unset($_SESSION['cart'][$userId]);
    }
    if (function_exists('clearCartBundles')) { clearCartBundles($userId); }

    return ['success' => true, 'message' => 'Cart cleared'];
}
?>