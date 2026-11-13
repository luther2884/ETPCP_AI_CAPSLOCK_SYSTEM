<?php
// api/reviews.php - Review management API
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$userId = getUserId();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $pdo = getConnection();
    
    switch ($action) {
        case 'submit':
            ensureReviewsUniqueConstraint();
            ensureReviewImageColumn();
            ensureReviewImagesTable();
            handleSubmitReview($pdo, $userId);
            break;
        case 'add_image':
            ensureReviewImageColumn();
            ensureReviewImagesTable();
            handleAddReviewImage($pdo, $userId);
            break;
        case 'list':
            ensureReviewImageColumn();
            ensureReviewImagesTable();
            handleListReviews($pdo);
            break;
        case 'helpful':
            handleMarkHelpful($pdo, $userId);
            break;
        case 'delete':
            handleDeleteReview($pdo, $userId);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Review API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

function handleSubmitReview($pdo, $userId) {
    global $method;
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    // Accept either JSON (legacy) or multipart/form-data (the review form
    // now sends multipart so it can carry an optional photo in the same
    // request).
    $isJson = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    if ($isJson) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $input = $_POST;
    }
    $productId = (int)($input['product_id'] ?? 0);
    $rating = (int)($input['rating'] ?? 0);
    $title = trim($input['title'] ?? '');
    $comment = trim($input['comment'] ?? '');
    
    // Validation
    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        return;
    }
    
    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5']);
        return;
    }
    
    if (empty($comment)) {
        echo json_encode(['success' => false, 'message' => 'Review comment is required']);
        return;
    }
    
    if (strlen($comment) < 10) {
        echo json_encode(['success' => false, 'message' => 'Review must be at least 10 characters long']);
        return;
    }
    
    // Check if product exists
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$productId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        return;
    }
    
    // Check if user has purchased and received this product. 'completed' is
    // the only terminal/fulfilled status in the current orders.status enum
    // (preparing, packed, ready, shipped, completed, cancelled) - there is
    // no separate 'delivered' status, so checking for it was always dead
    // code. This check now actually gates submission (previously it was
    // computed but only used to set the is_verified_purchase flag, so any
    // logged-in user could review any product regardless of purchase).
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items oi
                          JOIN orders o ON o.id = oi.order_id
                          WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'completed'");
    $stmt->execute([$userId, $productId]);
    $hasPurchased = $stmt->fetchColumn() > 0;

    if (!$hasPurchased) {
        echo json_encode(['success' => false, 'message' => 'You can only review products you have purchased and received.']);
        return;
    }

    // Check if user already reviewed this product
    $stmt = $pdo->prepare("SELECT id FROM reviews WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You have already reviewed this product']);
        return;
    }

    // Insert review. is_verified_purchase is always true here now that
    // purchase is a hard requirement, but the column/flag stays (still used
    // for display and consistent with the existing schema).
    try {
        $stmt = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, title, comment, is_verified_purchase)
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$productId, $userId, $rating, $title, $comment, $hasPurchased]);
    } catch (PDOException $e) {
        // Backed by a UNIQUE KEY (user_id, product_id) - see ensureReviewsUniqueConstraint()
        // in config/functions.php. Catches the race where two submits for
        // the same product slip past the SELECT check above at once.
        if ((int)$e->errorInfo[1] === 1062) {
            echo json_encode(['success' => false, 'message' => 'You have already reviewed this product']);
            return;
        }
        throw $e;
    }
    
    $reviewId = $pdo->lastInsertId();

    // Optional photos uploaded with the review (multipart only), up to
    // REVIEW_MAX_IMAGES. A bad image doesn't fail the whole review - the text
    // is already saved; the count that made it in is reported instead.
    $files = normalizeUploadedFiles('review_image');
    $savedCount = 0;
    $imageWarning = null;
    foreach ($files as $file) {
        if ($savedCount >= REVIEW_MAX_IMAGES) { break; }
        [$savedPath, $err] = saveReviewImageFile($file, (int)$reviewId);
        if ($savedPath) {
            $ins = $pdo->prepare("INSERT INTO review_images (review_id, image_path) VALUES (?, ?)");
            $ins->execute([$reviewId, $savedPath]);
            $savedCount++;
        } elseif ($imageWarning === null) {
            $imageWarning = $err;
        }
    }

    // Log audit
    logAudit('review_submitted', 'reviews', $reviewId, null, [
        'product_id' => $productId,
        'rating' => $rating,
        'is_verified_purchase' => $hasPurchased,
        'image_count' => $savedCount
    ]);

    echo json_encode([
        'success' => true,
        'message' => $imageWarning
            ? 'Review submitted, but a photo could not be attached: ' . $imageWarning
            : 'Review submitted successfully!',
        'review_id' => $reviewId,
        'image_count' => $savedCount,
        'is_verified_purchase' => $hasPurchased
    ]);
}

// Normalizes $_FILES[$key] into a flat list of single-file arrays, whether
// the form posted one file (name="review_image") or several
// (name="review_image[]"). Entries with no file selected are dropped.
function normalizeUploadedFiles(string $key): array {
    if (empty($_FILES[$key])) { return []; }
    $f = $_FILES[$key];
    $out = [];
    if (is_array($f['name'])) {
        $count = count($f['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            if (($f['name'][$i] ?? '') === '') { continue; }
            $out[] = [
                'name' => $f['name'][$i],
                'type' => $f['type'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $f['size'][$i] ?? 0,
            ];
        }
    } elseif (!empty($f['name'])) {
        $out[] = $f;
    }
    return $out;
}

// Attach one or more photos to an existing review the caller owns, as long
// as the review stays at or under REVIEW_MAX_IMAGES total. Used by the
// "Add photos" button rendered on your own review.
function handleAddReviewImage($pdo, $userId) {
    global $method;
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $reviewId = (int)($_POST['review_id'] ?? 0);
    if ($reviewId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
        return;
    }
    $files = normalizeUploadedFiles('review_image');
    if (empty($files)) {
        echo json_encode(['success' => false, 'message' => 'No photo was selected.']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ? AND user_id = ?");
    $stmt->execute([$reviewId, $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Review not found or not owned by you']);
        return;
    }

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM review_images WHERE review_id = ?");
    $cntStmt->execute([$reviewId]);
    $existing = (int)$cntStmt->fetchColumn();
    $slotsLeft = REVIEW_MAX_IMAGES - $existing;
    if ($slotsLeft <= 0) {
        echo json_encode(['success' => false, 'message' => 'This review already has the maximum of ' . REVIEW_MAX_IMAGES . ' photos.']);
        return;
    }

    $savedCount = 0;
    $warning = null;
    foreach ($files as $file) {
        if ($savedCount >= $slotsLeft) { $warning = 'Only ' . $slotsLeft . ' more photo(s) could be added (limit ' . REVIEW_MAX_IMAGES . ').'; break; }
        [$savedPath, $err] = saveReviewImageFile($file, $reviewId);
        if ($savedPath) {
            $ins = $pdo->prepare("INSERT INTO review_images (review_id, image_path) VALUES (?, ?)");
            $ins->execute([$reviewId, $savedPath]);
            $savedCount++;
        } elseif ($warning === null) {
            $warning = $err;
        }
    }

    if ($savedCount === 0) {
        echo json_encode(['success' => false, 'message' => $warning ?: 'The photo could not be added.']);
        return;
    }

    logAudit('review_image_added', 'reviews', $reviewId, null, ['added' => $savedCount]);
    echo json_encode([
        'success' => true,
        'message' => $savedCount === 1 ? 'Photo added to your review.' : $savedCount . ' photos added to your review.',
        'added' => $savedCount,
        'warning' => $warning,
    ]);
}

// Validates + stores one review photo. Returns [relativePath, null] on
// success or [null, errorMessage] on failure.
function saveReviewImageFile(array $file, int $reviewId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [null, 'The photo upload failed. Please try again.'];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return [null, 'The photo must be 5 MB or smaller.'];
    }
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $mime = function_exists('finfo_file') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) ?: '') : ($file['type'] ?? '');
    if (!isset($allowed[$ext]) || ($mime && !in_array($mime, $allowed, true))) {
        return [null, 'Only JPG, PNG, WEBP, or GIF images are allowed.'];
    }
    $dir = __DIR__ . '/../../../public/shared/img/review_photos/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [null, 'Could not save the photo. Please try again.'];
    }
    $name = 'review_' . $reviewId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        return [null, 'Could not save the photo. Please try again.'];
    }
    return ['public/shared/img/review_photos/' . $name, null];
}

function handleListReviews($pdo) {
    $productId = (int)($_GET['product_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
    $sort = $_GET['sort'] ?? 'newest'; // newest, oldest, highest, lowest, helpful
    $rating = (int)($_GET['rating'] ?? 0); // 0 = all, 1..5 = that star only

    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        return;
    }
    if ($rating < 1 || $rating > 5) { $rating = 0; }

    $offset = ($page - 1) * $limit;

    // The star filter narrows the list + its pagination total, but never the
    // summary/breakdown (which must always reflect the whole product).
    $ratingSql = $rating > 0 ? ' AND r.rating = ?' : '';
    $ratingSqlPlain = $rating > 0 ? ' AND rating = ?' : '';
    
    // Build sort clause
    $sortClause = '';
    switch ($sort) {
        case 'oldest':
            $sortClause = 'ORDER BY r.created_at ASC';
            break;
        case 'highest':
            $sortClause = 'ORDER BY r.rating DESC, r.created_at DESC';
            break;
        case 'lowest':
            $sortClause = 'ORDER BY r.rating ASC, r.created_at DESC';
            break;
        case 'helpful':
            $sortClause = 'ORDER BY r.helpful_count DESC, r.created_at DESC';
            break;
        default: // newest
            $sortClause = 'ORDER BY r.created_at DESC';
    }
    
    // Get reviews. u.username doesn't exist in the users table (only
    // firstname/lastname) - referencing it here made this whole query throw
    // on every single call, for every product, unconditionally (caught by
    // the outer try/catch as a generic 500 "Server error"), which is why
    // the Customer Reviews section always showed the static "No reviews
    // yet" placeholder regardless of how many reviews actually existed.
    $stmt = $pdo->prepare("SELECT r.*, u.firstname, u.lastname,
                           (SELECT COUNT(*) FROM review_helpfulness rh WHERE rh.review_id = r.id AND rh.is_helpful = TRUE) as helpful_count
                           FROM reviews r 
                           JOIN users u ON u.id = r.user_id 
                           WHERE r.product_id = ? AND r.is_approved = TRUE $ratingSql
                           $sortClause
                           LIMIT ? OFFSET ?");
    $stmt->execute($rating > 0 ? [$productId, $rating, $limit, $offset] : [$productId, $limit, $offset]);
    $reviews = $stmt->fetchAll();

    // Attach each review's photos (0..REVIEW_MAX_IMAGES) from review_images.
    if ($reviews) {
        $ids = array_map(static fn($r) => (int)$r['id'], $reviews);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $imgStmt = $pdo->prepare("SELECT id, review_id, image_path FROM review_images WHERE review_id IN ($ph) ORDER BY id ASC");
        $imgStmt->execute($ids);
        $byReview = [];
        foreach ($imgStmt->fetchAll() as $img) {
            $byReview[(int)$img['review_id']][] = ['id' => (int)$img['id'], 'image_path' => $img['image_path']];
        }
        foreach ($reviews as &$r) {
            $r['images'] = $byReview[(int)$r['id']] ?? [];
        }
        unset($r);
    }

    // Get total count (respects the star filter, for pagination)
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE product_id = ? AND is_approved = TRUE" . $ratingSqlPlain);
    $countStmt->execute($rating > 0 ? [$productId, $rating] : [$productId]);
    $total = $countStmt->fetchColumn();
    
    // Get rating summary
    $summaryStmt = $pdo->prepare("SELECT 
                                  AVG(rating) as average_rating,
                                  COUNT(*) as total_reviews,
                                  SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                                  SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                                  SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                                  SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                                  SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
                                  FROM reviews WHERE product_id = ? AND is_approved = TRUE");
    $summaryStmt->execute([$productId]);
    $summary = $summaryStmt->fetch();
    
    echo json_encode([
        'success' => true,
        'reviews' => $reviews,
        'summary' => $summary,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function handleMarkHelpful($pdo, $userId) {
    global $method;
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $reviewId = (int)($input['review_id'] ?? 0);
    $isHelpful = (bool)($input['is_helpful'] ?? true);
    
    if ($reviewId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
        return;
    }
    
    // Check if review exists
    $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ? AND is_approved = TRUE");
    $stmt->execute([$reviewId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Review not found']);
        return;
    }
    
    // Insert or update helpfulness
    $stmt = $pdo->prepare("INSERT INTO review_helpfulness (review_id, user_id, is_helpful) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE is_helpful = VALUES(is_helpful)");
    $stmt->execute([$reviewId, $userId, $isHelpful]);
    
    // Update helpful count
    $updateStmt = $pdo->prepare("UPDATE reviews SET helpful_count = (
        SELECT COUNT(*) FROM review_helpfulness 
        WHERE review_id = ? AND is_helpful = TRUE
    ) WHERE id = ?");
    $updateStmt->execute([$reviewId, $reviewId]);
    
    echo json_encode(['success' => true, 'message' => 'Helpfulness updated']);
}

function handleDeleteReview($pdo, $userId) {
    global $method;
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $reviewId = (int)($input['review_id'] ?? 0);
    
    if ($reviewId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
        return;
    }
    
    // Check if user owns this review
    $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ? AND user_id = ?");
    $stmt->execute([$reviewId, $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Review not found or not owned by you']);
        return;
    }

    // Remove the review's photo files + rows first (the review_images table
    // self-heal doesn't guarantee an ON DELETE CASCADE FK exists).
    try {
        $imgStmt = $pdo->prepare("SELECT image_path FROM review_images WHERE review_id = ?");
        $imgStmt->execute([$reviewId]);
        foreach ($imgStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $abs = __DIR__ . '/../../../' . $path;
            if (is_file($abs)) { @unlink($abs); }
        }
        $pdo->prepare("DELETE FROM review_images WHERE review_id = ?")->execute([$reviewId]);
    } catch (Throwable $e) {
        error_log('Delete review images error: ' . $e->getMessage());
    }

    // Delete review
    $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
    $stmt->execute([$reviewId]);

    echo json_encode(['success' => true, 'message' => 'Review deleted successfully']);
}
?>
