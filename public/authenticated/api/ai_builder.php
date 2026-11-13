<?php
// api/ai_builder.php - AI Builder recommendation/save-build API. All
// recommendation logic lives in config/ai_builder_engine.php, which reads
// directly from the existing products/categories tables - this endpoint is
// just the HTTP wrapper (auth, input validation, JSON in/out) around it.
declare(strict_types=1);
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/ai_builder_engine.php';
require_once __DIR__ . '/../../../config/ai_builder_ai.php';
require_once __DIR__ . '/../../../config/ai_builder_budget.php';
// Option filtering: the same use/target feasibility rules the wizard greys
// out its tiles with, enforced here where devtools cannot reach them.
require_once __DIR__ . '/../../../config/ai_builder_filter.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
if (!isCustomer()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'AI Builder is available to customer accounts only']);
    exit;
}

$userId = getUserId();
$pdo = getConnection();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    switch ($action) {
        case 'recommend':
            handle_recommend($pdo, $input);
            break;
        case 'save_build':
            handle_save_build($pdo, $userId, $input);
            break;
        case 'list_saved_builds':
            handle_list_saved_builds($pdo, $userId);
            break;
        case 'get_saved_build':
            handle_get_saved_build($pdo, $userId, (int)($_GET['id'] ?? 0));
            break;
        case 'delete_saved_build':
            handle_delete_saved_build($pdo, $userId, (int)($input['id'] ?? 0));
            break;
        case 'add_build_to_cart':
            handle_add_build_to_cart($pdo, $userId, $input);
            break;
        case 'ai_test':
            handle_ai_test();
            break;
        case 'ai_recommend':
            handle_ai_recommend($pdo, $input);
            break;
        case 'list_candidates':
            handle_list_candidates($pdo, $input);
            break;
        case 'replace_component':
            handle_replace_component($pdo, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('ai_builder.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong generating your recommendation. Please try again.']);
}

/**
 * Shared wizard-input parsing/whitelisting for both the deterministic
 * ('recommend') and Gemini-backed ('ai_recommend') endpoints - keeps the
 * same allow-lists in one place instead of duplicated per handler.
 * Returns null (with an error already echoed) when the budget is invalid.
 */
function aib_parse_wizard_input(array $input): ?array {
    $budgetMin = (float)($input['budget_min'] ?? 0);
    $budgetMax = (float)($input['budget_max'] ?? 0);
    if ($budgetMax <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid budget.']);
        return null;
    }
    if ($budgetMin > $budgetMax) { $budgetMin = $budgetMax; }

    $uses = array_values(array_filter((array)($input['intended_use'] ?? []), 'is_string'));
    $allowedUses = ['gaming', 'office', 'school', 'programming', 'video_editing', 'photo_editing', 'streaming', '3d_rendering', 'general'];
    $uses = array_values(array_intersect($uses, $allowedUses));

    $performance = $input['performance'] ?? 'balanced';
    if (!in_array($performance, ['budget', 'balanced', 'performance', 'high_end'], true)) { $performance = 'balanced'; }

    $requirements = array_values(array_filter((array)($input['requirements'] ?? []), 'is_string'));
    $allowedReqs = ['wifi', 'bluetooth', 'rgb', 'large_storage', 'fast_ssd', 'high_ram', 'dedicated_gpu', 'quiet', 'upgradeable'];
    $requirements = array_values(array_intersect($requirements, $allowedReqs));

    $baseProductId = !empty($input['base_product_id']) ? (int)$input['base_product_id'] : null;
    $brand = trim((string)($input['brand'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));

    // ---------------------------------------------------------------------
    // Feasibility. Everything above this point checks TYPES; this checks
    // whether what was asked for can be built at all.
    //
    // The wizard hides impossible options, but hiding is a courtesy, not a
    // control - the browser can be bypassed with one fetch(). These are the
    // same figures the wizard was given (config/ai_builder_budget.php is the
    // only place either side gets them), so a combination can never be
    // offered in the UI and refused here, or vice versa.
    // ---------------------------------------------------------------------
    $pdo = getConnection();

    if ($baseProductId) {
        // Building on a desktop the customer already owns: the budget buys
        // add-ons, not a whole PC, so the whole-build floor is the wrong test
        // entirely. It still has to buy at least one thing we stock.
        $addon = aib_min_addon_price($pdo);
        if ($addon['ok'] && $budgetMax < $addon['min']) {
            echo json_encode([
                'success' => false,
                'code' => 'budget_below_addon_minimum',
                'min_budget' => $addon['min'],
                'message' => 'The cheapest add-on we have in stock is about ' . aib_money($addon['min'])
                           . '. Please enter at least that much.',
            ]);
            return null;
        }
    } else {
        $floor = aib_min_build_price($pdo);
        if (!$floor['ok']) {
            echo json_encode([
                'success' => false,
                'code' => 'no_complete_build_possible',
                'message' => $floor['reason'] ?: 'We cannot assemble a complete PC from current stock.',
            ]);
            return null;
        }
        if ($budgetMax < $floor['total']) {
            echo json_encode([
                'success' => false,
                'code' => 'budget_below_minimum_build',
                'min_budget' => $floor['total'],
                'message' => 'The lowest complete PC we can build right now starts at about '
                           . aib_money($floor['total']) . '. Please enter at least ' . aib_money($floor['total']) . '.',
            ]);
            return null;
        }

        // The budget clears a working PC, but does it clear the performance
        // target that was asked for? This is the check that stops a
        // "high-end gaming PC" being assembled for a budget that cannot
        // afford a high-end graphics card.
        $primaryUse = aib_primary_use($uses);
        $target = aib_min_budget_for($pdo, $primaryUse, $performance);
        if ($target['ok'] && $budgetMax < $target['min']) {
            echo json_encode([
                'success' => false,
                'code' => 'budget_below_target_minimum',
                'min_budget' => $target['min'],
                'performance' => $performance,
                'intended_use' => $primaryUse,
                'message' => 'A ' . aib_target_label($performance) . ' ' . aib_use_label($primaryUse)
                           . ' build starts at about ' . aib_money($target['min'])
                           . ' with our current stock. Your budget of ' . aib_money($budgetMax)
                           . ' is below that - lower the performance target, or raise the budget to '
                           . aib_money($target['min']) . '.',
            ]);
            return null;
        }

        // -----------------------------------------------------------------
        // STEP 4 - the server-side half of option filtering.
        //
        // The two checks above ask "can this budget buy a working PC" and
        // "can it reach the performance target for the most demanding use".
        // Neither catches a brief that contradicts itself in the other
        // direction - a budget that clears the target floor but cannot
        // genuinely serve a use that was ticked (PHP 10,000 + Gaming came
        // back as an integrated-graphics PC with no graphics card, because
        // the "budget" target forces no driver part at all).
        //
        // aibf_validate_combo() re-derives, per request, exactly what the
        // wizard greyed out - so a combination can never be offered in the
        // UI and refused here, or re-enabled in devtools and accepted here.
        // Every selected use is tested, not just the primary one: "gaming +
        // office" has to be able to game.
        // -----------------------------------------------------------------
        $combo = aibf_validate_combo($pdo, $budgetMax, $uses, $performance);
        if (!$combo['ok']) {
            echo json_encode([
                'success'       => false,
                'code'          => $combo['code'],
                'min_budget'    => $combo['min_budget'],
                'performance'   => $combo['performance'],
                'intended_use'  => $combo['use'],
                'message'       => $combo['message'],
            ]);
            return null;
        }
    }

    return [
        'budget_min' => $budgetMin,
        'budget_max' => $budgetMax,
        'intended_use' => $uses,
        'performance' => $performance,
        'requirements' => $requirements,
        'base_product_id' => $baseProductId,
        'brand' => $brand !== '' ? substr($brand, 0, 60) : null,
        'message' => $message !== '' ? substr($message, 0, 800) : null,
    ];
}

function handle_recommend(PDO $pdo, array $input): void {
    $parsed = aib_parse_wizard_input($input);
    if ($parsed === null) { return; }

    $result = aib_generate_recommendation($pdo, $parsed);
    echo json_encode(aib_serialize_result($result));
}

function handle_ai_test(): void {
    // Costs real API credit, so it never runs on a bare GET - a prefetch, a
    // crawler or a stray link must not be able to spend money. The call is
    // additionally rate-limited, spend-capped and cached by config/ai_guard.php.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && ($_GET['run'] ?? '') !== '1') {
        echo json_encode([
            'success' => false, 'configured' => ai_is_configured(), 'model' => GEMINI_MODEL,
            'message' => 'Connection test not run. Re-request with run=1 (or POST) to actually call the AI service.',
        ]);
        return;
    }
    $test = aib_ai_connection_test();
    echo json_encode([
        'success' => $test['success'],
        'configured' => $test['configured'],
        'model' => $test['model'],
        'message' => $test['success'] ? ('Connected. Model replied: "' . $test['message'] . '"') : $test['message'],
    ]);
}

/**
 * Primary AI Builder recommendation endpoint. Uses Gemini when a base PC
 * wasn't selected; if a base PC *was* selected, or if Gemini is unavailable/
 * fails for any reason, falls back to the deterministic engine
 * (config/ai_builder_engine.php) so the AI Builder keeps working even
 * without an API key. Both paths are normalized to the same "builds[]"
 * response shape via aib_ai_shape_from_deterministic() so the frontend has
 * one rendering path regardless of source.
 */
function handle_ai_recommend(PDO $pdo, array $input): void {
    $parsed = aib_parse_wizard_input($input);
    if ($parsed === null) { return; }

    if ($parsed['base_product_id']) {
        // An existing pre-built desktop bundles CPU/motherboard/RAM/PSU/case
        // non-swappably - the deterministic engine already models that
        // correctly; the AI path has no equivalent concept, so this case
        // intentionally never goes through Gemini.
        $det = aib_generate_recommendation($pdo, $parsed);
        echo json_encode([
            'success' => true,
            'source' => 'deterministic_base_pc',
            'model' => null,
            'builds' => [aib_ai_shape_from_deterministic($det)],
        ]);
        return;
    }

    $ai = aib_ai_generate_recommendation($pdo, $parsed);
    if ($ai['success']) {
        echo json_encode(['success' => true, 'source' => 'gemini', 'model' => $ai['model'], 'builds' => $ai['builds']]);
        return;
    }

    // Gemini unavailable/failed - fall back to the deterministic engine
    // rather than leaving the customer with an error. Surface why, honestly.
    echo json_encode([
        'success' => true,
        'source' => 'fallback_deterministic',
        'model' => null,
        'fallback_reason' => $ai['error'],
        'builds' => aib_generate_fallback_builds($pdo, $parsed),
    ]);
}

function handle_list_candidates(PDO $pdo, array $input): void {
    $categoryId = (int)($input['category_id'] ?? $_GET['category_id'] ?? 0);
    if ($categoryId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid category.']);
        return;
    }
    $rows = aib_get_candidates($pdo, $categoryId);
    $candidates = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'price' => (float)$r['price'],
            'stock' => (int)$r['stock'],
            'image_url' => $r['image_url'],
            'specs' => $r['specs'],
        ];
    }, $rows);
    echo json_encode(['success' => true, 'category_id' => $categoryId, 'candidates' => $candidates]);
}

/**
 * Swaps one component within a build the customer is configuring. Always
 * re-verifies the new product against the live database (never trusts a
 * client-supplied price/spec/availability) and re-runs the same backend
 * compatibility check used everywhere else, so a manual swap can't silently
 * produce an incompatible or fictitious build either.
 */
function handle_replace_component(PDO $pdo, array $input): void {
    $currentIds = array_values(array_filter(array_map('intval', (array)($input['current_product_ids'] ?? []))));
    $oldId = (int)($input['old_product_id'] ?? 0);
    $newId = (int)($input['new_product_id'] ?? 0);

    if ($newId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid replacement product.']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, name, description, price, stock, category_id, image_url, status FROM products WHERE id = ?");
    $stmt->execute([$newId]);
    $newProduct = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$newProduct || $newProduct['status'] !== 'active' || (int)$newProduct['stock'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'That product is no longer available.']);
        return;
    }
    $newProduct['specs'] = aib_parse_specs($newProduct);

    $updatedIds = array_values(array_diff($currentIds, [$oldId]));
    $updatedIds[] = $newId;

    $compat = aib_validate_component_set($pdo, $updatedIds);

    $total = 0.0;
    if (!empty($updatedIds)) {
        $placeholders = implode(',', array_fill(0, count($updatedIds), '?'));
        $priceStmt = $pdo->prepare("SELECT SUM(price) FROM products WHERE id IN ($placeholders)");
        $priceStmt->execute($updatedIds);
        $total = (float)$priceStmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'product' => $newProduct,
        'updated_product_ids' => $updatedIds,
        'total_price' => round($total, 2),
        'compatibility_status' => $compat['status'],
        'compatibility_issues' => $compat['issues'],
        'notes' => $compat['notes'],
    ]);
}

/** Strips the recommendation result down to plain JSON-safe data (product rows already are, but keep this centralized). */
function aib_serialize_result(array $result): array {
    $result['success'] = true;
    return $result;
}

/**
 * How many builds this user has saved.
 *
 * Returned by every handler that changes the number, so the header count is
 * re-rendered from the server's own tally rather than being incremented
 * client-side - a client counter drifts the moment a save is rejected, or a
 * build is deleted in another tab.
 */
function aib_saved_count(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_builder_saved_builds WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function handle_save_build(PDO $pdo, int $userId, array $input): void {
    aib_ensure_tables($pdo);

    // Expects the unified build shape (from either 'ai_recommend' or the
    // deterministic-adapted equivalent) under $input['build']: components as
    // a flat list of {product:{id,...}}, plus an optional base_product.
    $build = (array)($input['build'] ?? []);
    $components = (array)($build['components'] ?? []);
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        $title = (is_string($build['build_name'] ?? null) && $build['build_name'] !== '') ? $build['build_name'] : ('My PC Build - ' . date('M j, Y'));
    }

    // Persist only product ids - re-fetching each live product (name,
    // current price, stock) at view time keeps a saved build honest if the
    // catalog changes later, rather than freezing a point-in-time copy.
    $productIds = [];
    foreach ($components as $entry) {
        if (!empty($entry['product']['id'])) { $productIds[] = (int)$entry['product']['id']; }
    }
    $productIds = array_values(array_unique($productIds));
    $baseProductId = !empty($build['base_product']['id']) ? (int)$build['base_product']['id'] : null;

    if (empty($productIds) && !$baseProductId) {
        echo json_encode(['success' => false, 'message' => 'Nothing to save - generate a recommendation first.']);
        return;
    }

    $snapshot = [
        'product_ids' => $productIds,
        'base_product_id' => $baseProductId,
        'build_name' => is_string($build['build_name'] ?? null) ? $build['build_name'] : null,
    ];

    $stmt = $pdo->prepare("INSERT INTO ai_builder_saved_builds (user_id, title, budget_min, budget_max, intended_use, performance_preference, requirements, components_json, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $userId,
        $title,
        (float)($input['budget_min'] ?? 0),
        (float)($build['budget_max'] ?? $input['budget_max'] ?? 0),
        implode(',', (array)($input['intended_use'] ?? [])),
        (string)($input['performance'] ?? 'balanced'),
        implode(',', (array)($input['requirements'] ?? [])),
        json_encode($snapshot),
        (float)($build['total_price'] ?? 0),
    ]);
    // Captured immediately after the insert - logAudit() below performs its
    // own insert (into audit_log) on the same connection, which would
    // otherwise overwrite lastInsertId() before it's read for the response.
    $newId = (int)$pdo->lastInsertId();

    logAudit('ai_builder_save_build', 'ai_builder_saved_builds', $newId, null, ['title' => $title]);
    echo json_encode(['success' => true, 'message' => 'Build saved.', 'id' => $newId, 'saved_count' => aib_saved_count($pdo, $userId)]);
}

function handle_list_saved_builds(PDO $pdo, int $userId): void {
    aib_ensure_tables($pdo);
    $stmt = $pdo->prepare("SELECT id, title, budget_min, budget_max, intended_use, performance_preference, total_price, created_at FROM ai_builder_saved_builds WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'builds' => $rows, 'saved_count' => count($rows)]);
}

function handle_get_saved_build(PDO $pdo, int $userId, int $id): void {
    aib_ensure_tables($pdo);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid build.']);
        return;
    }
    $stmt = $pdo->prepare("SELECT * FROM ai_builder_saved_builds WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$id, $userId]);
    $build = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$build) {
        echo json_encode(['success' => false, 'message' => 'Build not found.']);
        return;
    }

    $snapshot = json_decode($build['components_json'], true) ?: [];
    $productIds = (array)($snapshot['product_ids'] ?? []);
    $baseProductId = !empty($snapshot['base_product_id']) ? (int)$snapshot['base_product_id'] : null;

    $components = [];
    foreach ($productIds as $pid) {
        $pStmt = $pdo->prepare("SELECT id, name, description, price, stock, category_id, image_url, status FROM products WHERE id = ?");
        $pStmt->execute([(int)$pid]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($product) {
            $product['specs'] = aib_parse_specs($product);
            $stillAvailable = $product['status'] === 'active' && (int)$product['stock'] > 0;
            $components[] = ['status' => $stillAvailable ? 'ok' : 'no_longer_available', 'product' => $product];
        } else {
            $components[] = ['status' => 'no_longer_available', 'reason' => 'This product has since been removed from the catalog.', 'product_id' => (int)$pid];
        }
    }

    $baseProduct = null;
    if ($baseProductId) {
        $bStmt = $pdo->prepare("SELECT id, name, description, price, stock, category_id, image_url, status FROM products WHERE id = ?");
        $bStmt->execute([$baseProductId]);
        $baseProduct = $bStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $build['build_name'] = is_string($snapshot['build_name'] ?? null) && $snapshot['build_name'] !== '' ? $snapshot['build_name'] : $build['title'];
    $build['components'] = $components;
    $build['base_product'] = $baseProduct;
    unset($build['components_json']);
    echo json_encode(['success' => true, 'build' => $build]);
}

function handle_delete_saved_build(PDO $pdo, int $userId, int $id): void {
    aib_ensure_tables($pdo);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid build.']);
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM ai_builder_saved_builds WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $ok = $stmt->rowCount() > 0;
    echo json_encode([
        'success'     => $ok,
        'message'     => $ok ? 'Build deleted.' : 'Build not found.',
        'saved_count' => aib_saved_count($pdo, $userId),
    ]);
}

/**
 * Adds every currently-available component in a submitted build to the
 * customer's cart in one request, re-verifying stock/status per item right
 * before adding (never trusts a client-side price/availability snapshot).
 * addToCart() itself lives only inside api/cart.php (which can't be
 * required here - it's a script with top-level exit calls, not a library),
 * so this mirrors its session-cart logic directly and reuses the shared
 * saveCartToDb()/capCartQuantitiesByStock() helpers from functions.php so
 * the cart stays consistent with the rest of the app (DB-persisted cart,
 * stock-capped quantities).
 */
function handle_add_build_to_cart(PDO $pdo, int $userId, array $input): void {
    $productIds = array_values(array_filter(array_map('intval', (array)($input['product_ids'] ?? []))));
    if (empty($productIds)) {
        echo json_encode(['success' => false, 'message' => 'No components to add.']);
        return;
    }

    if (!isset($_SESSION['cart'][$userId])) {
        $_SESSION['cart'][$userId] = [];
    }

    $added = [];
    $addedIds = [];
    $skipped = [];
    foreach ($productIds as $productId) {
        $stmt = $pdo->prepare("SELECT id, name, price, stock, status FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product || $product['status'] !== 'active' || (int)$product['stock'] < 1) {
            $skipped[] = $product['name'] ?? "Product #$productId";
            continue;
        }
        $currentQuantity = $_SESSION['cart'][$userId][$productId] ?? 0;
        if ($currentQuantity + 1 > (int)$product['stock']) {
            $skipped[] = $product['name'];
            continue;
        }
        $_SESSION['cart'][$userId][$productId] = $currentQuantity + 1;
        $added[] = $product['name'];
        $addedIds[] = (int)$product['id'];
    }

    if (!empty($added) && function_exists('saveCartToDb')) {
        $cart = capCartQuantitiesByStock($_SESSION['cart'][$userId]);
        $_SESSION['cart'][$userId] = $cart;
        saveCartToDb($userId, $cart);
    }

    // Tag the successfully-added items as one named bundle so cart.php can
    // group them under a shared header - purely visual, each item still
    // has its own quantity/remove controls and can be removed on its own.
    $bundleId = null;
    if (!empty($addedIds)) {
        $bundleName = trim((string)($input['bundle_name'] ?? '')) ?: 'AI Builder Build';
        $bundleId = tagCartBundle($userId, $addedIds, substr($bundleName, 0, 150));
    }

    echo json_encode([
        'success' => !empty($added),
        'added_count' => count($added),
        'added' => $added,
        'skipped' => $skipped,
        'bundle_id' => $bundleId,
        'cart_count' => function_exists('getCartItemCount') ? getCartItemCount($userId) : null,
        'message' => count($added) . ' item(s) added to cart' . (!empty($skipped) ? ', ' . count($skipped) . ' could not be added (out of stock or unavailable)' : '') . '.',
    ]);
}
