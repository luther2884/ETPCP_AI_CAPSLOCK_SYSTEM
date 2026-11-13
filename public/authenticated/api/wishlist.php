<?php
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

try {
    $pdo = getConnection();

    // Count endpoint
    if (($_GET['action'] ?? '') === 'count') {
        if (!isLoggedIn()) {
            echo json_encode(['success' => true, 'count' => 0]);
            exit;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = ?');
        $stmt->execute([getUserId()]);
        echo json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
        exit;
    }

    // Toggle endpoint
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? ($_POST['action'] ?? '');
    $productId = (int)($input['product_id'] ?? ($_POST['product_id'] ?? 0));

    if ($action !== 'toggle' || $productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Please login first']);
        exit;
    }

    // Check existing
    $stmt = $pdo->prepare('SELECT id FROM wishlists WHERE user_id = ? AND product_id = ?');
    $stmt->execute([getUserId(), $productId]);
    $existingId = $stmt->fetchColumn();

    $added = false;
    if ($existingId) {
        $del = $pdo->prepare('DELETE FROM wishlists WHERE id = ?');
        $del->execute([$existingId]);
        logAudit('wishlist_removed', 'wishlists', $existingId, null, ['product_id' => $productId]);
    } else {
        $ins = $pdo->prepare('INSERT INTO wishlists (user_id, product_id) VALUES (?, ?)');
        $ins->execute([getUserId(), $productId]);
        $added = true;
        logAudit('wishlist_added', 'wishlists', $pdo->lastInsertId(), null, ['product_id' => $productId]);
    }

    // Return updated count
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = ?');
    $cnt->execute([getUserId()]);
    $count = (int)$cnt->fetchColumn();

    echo json_encode(['success' => true, 'added' => $added, 'wishlist_count' => $count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}


