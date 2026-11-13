<?php
// products.php - Customer Products listing page (requires login)
require_once '../../../config/functions.php';
require_login();

$pageTitle = 'Products - TechWorld';

// This page shows the landing page's own ground (the radial + the fixed grid
// and blob layers customer-dark.css paints through body::before/body::after),
// so the customer-area background video must not sit on top of it.
//
// It has to be suppressed here rather than hidden in CSS: header.php renders
// the video at z-index:-2 with a near-white scrim (rgba(242,243,244,.35)) at
// z-index:-1 AND sets body{background:transparent!important}. Nothing sets a
// background on <html>, so body's background propagates to the canvas and
// paints BEHIND both of them - no body background can cover that, however
// !important. Verified: with the section made transparent but the video left
// in, the whole page washes out to pale mauve. Same reason index.php,
// dashboard.php, notifications.php and ai_builder.php set this.
$suppressSiteBgVideo = true;

// Get filter parameters
// category_id is multi-select: ?category_id[]=7&category_id[]=3 (native PHP
// array GET parsing, matches checkbox name="category_id[]"). $category (a
// separate, single string param like ?category=desktops) is unrelated -
// used by the main left sidebar's Desktop/Laptop links - and keeps working
// exactly as before, ANDed with category_id same as it always was.
$categoryIds = array_values(array_unique(array_filter(array_map('intval', (array)($_GET['category_id'] ?? [])))));
$category = $_GET['category'] ?? null;
// Price range. Blank means "no bound", so an empty box never filters anything.
$minPrice = (isset($_GET['min_price']) && $_GET['min_price'] !== '') ? (float)$_GET['min_price'] : null;
$maxPrice = (isset($_GET['max_price']) && $_GET['max_price'] !== '') ? (float)$_GET['max_price'] : null;
$activeNav = in_array($category, ['desktops', 'laptops'], true) ? $category : 'products';
$search = $_GET['q'] ?? '';
$sortBy = $_GET['sort'] ?? 'newest';
$page = max(1, intval($_GET['page'] ?? 1));
// Set by the sidebar's XHR (see the short-circuit further down): render the
// results region on its own instead of the whole page.
$isPartial = (($_GET['partial'] ?? '') === '1');
// 6 per page: two rows of three on the wide grid, so the pager is reachable
// without a long scroll. $totalPages is derived from this, so the 1/2/3/Next
// strip under the grid follows automatically.
$limit = 6;
$offset = ($page - 1) * $limit;

// Get categories for filter sidebar
$categories = getCategories();

// Build query conditions
$whereConditions = ["p.status = 'active'"];
$sqlParams = [];

if (!empty($categoryIds)) {
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $whereConditions[] = "p.category_id IN ($placeholders)";
    $sqlParams = array_merge($sqlParams, $categoryIds);
}

if ($category) {
    $whereConditions[] = "c.name LIKE ?";
    $sqlParams[] = '%' . $category . '%';
}

// Bound the price the same way, as bound parameters. This adds a condition to
// the query the page already builds - it does not change the schema, the data,
// or any .sql file. The count query below reuses $whereConditions/$sqlParams,
// so pagination stays correct without further work.
if ($minPrice !== null) {
    $whereConditions[] = "p.price >= ?";
    $sqlParams[] = $minPrice;
}
if ($maxPrice !== null) {
    $whereConditions[] = "p.price <= ?";
    $sqlParams[] = $maxPrice;
}

// Initialize variables
$searchType = 'normal';
$suggestions = [];
$products = [];

// Build ORDER BY clause
$orderBy = "p.created_at DESC";
switch ($sortBy) {
    case 'price_low':
        $orderBy = "p.price ASC";
        break;
    case 'price_high':
        $orderBy = "p.price DESC";
        break;
    case 'name':
        $orderBy = "p.name ASC";
        break;
    case 'newest':
    default:
        $orderBy = "p.created_at DESC";
        break;
}

// Smart search function
function performSmartSearch($pdo, $query, $limit) {
    $searchTerm = '%' . $query . '%';
    
    // First try exact search
    $sql = "SELECT p.*, c.name as category_name,
            CASE 
                WHEN p.name LIKE ? THEN 1
                WHEN p.description LIKE ? THEN 2
                WHEN c.name LIKE ? THEN 3
                ELSE 4
            END as relevance_score
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.status = 'active' 
            AND (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)
            ORDER BY relevance_score, p.created_at DESC
            LIMIT ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
    $exactResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($exactResults)) {
        return [
            'results' => $exactResults,
            'search_type' => 'exact'
        ];
    }
    
    // If no exact results, try fuzzy search
    $allSql = "SELECT p.*, c.name as category_name FROM products p 
               LEFT JOIN categories c ON p.category_id = c.id 
               WHERE p.status = 'active'";
    $allStmt = $pdo->prepare($allSql);
    $allStmt->execute();
    $allProducts = $allStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $fuzzyResults = [];
    $queryLower = strtolower($query);
    
    foreach ($allProducts as $product) {
        $name = strtolower($product['name']);
        $description = strtolower($product['description'] ?? '');
        $category = strtolower($product['category_name'] ?? '');
        
        // Calculate similarity scores
        $nameScore = calculateSimilarity($queryLower, $name);
        $descScore = calculateSimilarity($queryLower, $description);
        $catScore = calculateSimilarity($queryLower, $category);
        
        $bestScore = max($nameScore, $descScore, $catScore);
        
        if ($bestScore > 0.6) {
            $product['similarity_score'] = $bestScore;
            $fuzzyResults[] = $product;
        }
    }
    
    usort($fuzzyResults, function($a, $b) {
        return $b['similarity_score'] <=> $a['similarity_score'];
    });
    
    $fuzzyResults = array_slice($fuzzyResults, 0, $limit);
    
    if (!empty($fuzzyResults)) {
        return [
            'results' => $fuzzyResults,
            'search_type' => 'fuzzy'
        ];
    }
    
    // Get suggestions for no results
    $suggestions = getSearchSuggestions($pdo, $query);
    
    return [
        'results' => [],
        'search_type' => 'no_results',
        'suggestions' => $suggestions
    ];
}

function calculateSimilarity($str1, $str2) {
    $maxLen = max(strlen($str1), strlen($str2));
    if ($maxLen == 0) return 1;
    
    $distance = levenshtein($str1, $str2);
    return 1 - ($distance / $maxLen);
}

function getSearchSuggestions($pdo, $query) {
    $suggestions = [];
    
    // Get popular categories
    $catSql = "SELECT c.name, COUNT(p.id) as product_count 
               FROM categories c 
               LEFT JOIN products p ON c.id = p.category_id AND p.status = 'active'
               GROUP BY c.id, c.name 
               HAVING product_count > 0
               ORDER BY product_count DESC 
               LIMIT 5";
    $stmt = $pdo->prepare($catSql);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($categories as $cat) {
        if (calculateSimilarity(strtolower($query), strtolower($cat['name'])) > 0.3) {
            $suggestions[] = [
                'text' => $cat['name'],
                'type' => 'category',
                'count' => $cat['product_count']
            ];
        }
    }
    
    return array_slice($suggestions, 0, 5);
}

try {
    $pdo = getConnection();
    
    if ($search) {
        // Use smart search for better typo handling
        $searchResults = performSmartSearch($pdo, $search, $limit);
        $products = $searchResults['results'];
        $searchType = $searchResults['search_type'];
        $suggestions = $searchResults['suggestions'] ?? [];
        $totalProducts = count($products);
        $totalPages = ceil($totalProducts / $limit);
    } else {
        // Regular product listing
        $countSql = "SELECT COUNT(*) FROM products p 
                     LEFT JOIN categories c ON p.category_id = c.id 
                     WHERE " . implode(' AND ', $whereConditions);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($sqlParams);
        $totalProducts = $countStmt->fetchColumn();
        $totalPages = ceil($totalProducts / $limit);

        // Get products
        $safeLimit = (int)$limit;
        $safeOffset = (int)$offset;
        $sql = "SELECT p.*, c.name as category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE " . implode(' AND ', $whereConditions) . "
                ORDER BY {$orderBy}
                LIMIT {$safeLimit} OFFSET {$safeOffset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($sqlParams);
        $products = $stmt->fetchAll();
    }

    // Real average rating + review count, batched in one query regardless
    // of which path above produced $products (regular listing or smart
    // search) - replaces the old order-count-based fake "4/5 stars for any
    // product that's ever sold" display with the product's actual reviews.
    if (!empty($products)) {
        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $ratingStmt = $pdo->prepare("SELECT product_id, AVG(rating) as avg_rating, COUNT(*) as review_count
                                      FROM reviews WHERE product_id IN ($placeholders) AND is_approved = 1
                                      GROUP BY product_id");
        $ratingStmt->execute($productIds);
        $ratingsByProduct = [];
        foreach ($ratingStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ratingsByProduct[$r['product_id']] = ['avg_rating' => round((float)$r['avg_rating'], 1), 'review_count' => (int)$r['review_count']];
        }
        foreach ($products as &$p) {
            $rating = $ratingsByProduct[$p['id']] ?? ['avg_rating' => 0.0, 'review_count' => 0];
            $p['avg_rating'] = $rating['avg_rating'];
            $p['review_count'] = $rating['review_count'];
        }
        unset($p);
    }

} catch (PDOException $e) {
    error_log("Error fetching products: " . $e->getMessage());
    $products = [];
    $totalProducts = 0;
    $totalPages = 1;
}

// Selected categories' names (for the active-filter chips + breadcrumb) -
// one small IN(...) query covering however many are selected.
$selectedCategories = [];
if (!empty($categoryIds)) {
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE id IN ($placeholders) ORDER BY name");
    $catStmt->execute($categoryIds);
    $selectedCategories = $catStmt->fetchAll();
}

// Current category name for breadcrumb
$currentCategoryName = '';
if (!empty($selectedCategories)) {
    $currentCategoryName = implode(', ', array_column($selectedCategories, 'name'));
} elseif ($category) {
    $currentCategoryName = ucfirst($category);
}

// ?partial=1 - the filter sidebar's XHR. Everything above has already run, so
// render only the results region and stop: no <head>, no header/sidebar, no
// filter form. The browser swaps this straight into <main class="products-main">
// so picking a category updates the grid in place instead of reloading.
if ($isPartial) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Total-Products: ' . (int)$totalProducts);
    include __DIR__ . '/includes/products_results.php';
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js
         is deferred and would run a frame after first paint, which is exactly
         the flash this prevents. Resolution order is localStorage -> OS
         preference -> dark. There is no server-rendered preference to fall
         back on yet - `users` has no theme column; see the TODO in theme.js. */ ?>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">

  <!-- Figtree + JetBrains Mono. Every other authenticated customer page
       (dashboard.php, cart.php, profile.php, ...) links this pair; this page
       never did, so every font-family: 'JetBrains Mono' already written below
       for .pf-section-title / .pf-price-row input / .pf-apply / .pf-clear was
       silently falling back to the OS's generic monospace, and .pf-cat-label
       (no font-family of its own) was inheriting the sitewide Manrope instead
       of the landing's Figtree. Same pair guest/index.php and dashboard.php
       link. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<!-- Breadcrumb -->
<div class="breadcrumb">
    <div class="container">
        <a href="<?php echo WEB_ROOT; ?>public/authenticated/customer/index.php">Home</a>
        <span>/</span>
        <a href="<?php echo WEB_ROOT; ?>public/authenticated/customer/products.php">Products</a>
        <?php if ($currentCategoryName): ?>
            <span>/</span>
            <span><?php echo htmlspecialchars($currentCategoryName); ?></span>
        <?php endif; ?>
    </div>
</div>

<!-- Products Section -->
<section class="products-listing account-page">
    <div class="container">
        <!-- Phone toolbar. Under 768px the filter column is off screen, so
             this is the way back to it; above that it is display:none and the
             column sits where it always has, so the toolbar never becomes a
             second set of controls competing with the first.

             It opens the SAME <form id="pfFilterForm"> below, moved on screen
             by CSS alone. Nothing is duplicated, so every field name, the
             submit handler and the in-place XHR filtering are untouched. -->
        <div class="pf-toolbar" id="pfToolbar">
            <button type="button" class="pf-tb-btn" id="pfOpenSheet"
                    aria-expanded="false" aria-controls="pfSheet">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M3.5 7h9.8M17.8 7h2.7M3.5 17h3.2M11.2 17h9.3"/><circle cx="15.5" cy="7" r="2.2"/><circle cx="8.9" cy="17" r="2.2"/>
                </svg>
                Filter
                <span class="pf-tb-n" id="pfToolbarCount" <?= empty($categoryIds) ? 'hidden' : '' ?>><?= count($categoryIds) ?></span>
            </button>
            <button type="button" class="pf-tb-btn" id="pfOpenSort"
                    aria-expanded="false" aria-controls="pfSheet">
                <span id="pfToolbarSort">Sort</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m6 9 6 6 6-6"/>
                </svg>
            </button>
            <span class="pf-tb-items"><span id="pfToolbarItems"><?= (int)$totalProducts ?></span> items</span>
        </div>
        <div class="pf-scrim" id="pfScrim" hidden></div>

        <div class="products-layout">
            <!-- Sidebar Filters -->
            <aside class="filters-sidebar" id="pfSheet">
                <?php /* The grab handle and the sticky action bar at the foot
                         of this panel are both display:none above 767.98px, so
                         the desktop filter column renders exactly as before -
                         same markup order, nothing inserted into its flow. */ ?>
                <button type="button" class="pf-grab" id="pfGrab" aria-label="Close filters">
                    <span aria-hidden="true"></span>
                </button>
                <form method="GET" id="pfFilterForm">
                    <input type="hidden" name="category" value="<?= htmlspecialchars($category ?? '') ?>">
                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">

                    <div class="filter-section pf-section">
                        <button type="button" class="pf-section-head" data-pf-toggle aria-expanded="true">
                            <span class="pf-section-title">Price Range</span>
                            <i class="fi fi-rr-angle-small-down pf-chevron"></i>
                        </button>
                        <div class="pf-section-body"><div class="pf-section-inner">
                            <div class="pf-price-row">
                                <input type="number" name="min_price" min="0" step="1" placeholder="MIN"
                                       value="<?= $minPrice !== null ? htmlspecialchars((string)$minPrice) : '' ?>">
                                <input type="number" name="max_price" min="0" step="1" placeholder="MAX"
                                       value="<?= $maxPrice !== null ? htmlspecialchars((string)$maxPrice) : '' ?>">
                            </div>
                            <!-- The category checkboxes submit on change; these two
                                 are typed, so they need an explicit submit. -->
                            <button type="submit" class="pf-apply">Apply Filters</button>
                        </div></div>
                    </div>
                    <div class="filter-section pf-section">
                        <button type="button" class="pf-section-head" data-pf-toggle aria-expanded="true">
                            <span class="pf-section-title">Sort By</span>
                            <i class="fi fi-rr-angle-small-down pf-chevron"></i>
                        </button>
                        <div class="pf-section-body"><div class="pf-section-inner">
                            <div class="pf-select-wrap">
                                <select id="sortSelect" onchange="updateSort(this.value)">
                                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest</option>
                                    <option value="price_low" <?php echo $sortBy === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                                    <option value="price_high" <?php echo $sortBy === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name A-Z</option>
                                </select>
                                <i class="fi fi-rr-angle-small-down pf-select-chevron"></i>
                            </div>
                        </div></div>
                    </div>
                    <div class="filter-section pf-section">
                        <button type="button" class="pf-section-head" data-pf-toggle aria-expanded="true">
                            <span class="pf-section-title">
                                Categories
                                <span class="pf-badge" id="pfCatBadge" <?= empty($categoryIds) ? 'hidden' : '' ?>><?= count($categoryIds) ?></span>
                            </span>
                            <i class="fi fi-rr-angle-small-down pf-chevron"></i>
                        </button>
                        <div class="pf-section-body"><div class="pf-section-inner">
                            <!-- Outside the .pf-section-head <button>: an <a> cannot
                                 be nested inside a button. Always in the DOM (so the
                                 in-place filtering can show/hide it) but hidden unless
                                 a filter is actually applied, so it is never a dead
                                 control. -->
                            <a class="pf-clear" id="pfClear" data-pf-clear href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" <?= empty($categoryIds) ? 'hidden' : '' ?>>Clear</a>
                            <ul class="category-filter">
                                <li><a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" data-pf-clear class="pf-all <?php echo empty($categoryIds) && !$category ? 'active' : ''; ?>">All Products</a></li>
                                <?php foreach ($categories as $i => $cat): ?>
                                    <li>
                                        <label class="pf-cat-label <?= in_array((int)$cat['id'], $categoryIds, true) ? 'active' : '' ?>">
                                            <input type="checkbox" name="category_id[]" value="<?= (int)$cat['id'] ?>" <?= in_array((int)$cat['id'], $categoryIds, true) ? 'checked' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div></div>
                    </div>
                </form>

                <?php /* Sheet footer, phone only. "Clear" carries the same
                         href and the same data-pf-clear hook the existing
                         Clear link does, so the delegated handler already
                         wired at DOMContentLoaded picks it up - there is no
                         second clearing path. "Show results" only closes the
                         sheet: filtering has already been applied in place by
                         then, so there is nothing left for it to submit. */ ?>
                <div class="pf-sheet-actions">
                    <a class="pf-sheet-clear" data-pf-clear
                       href="<?= WEB_ROOT ?>public/authenticated/customer/products.php">Clear</a>
                    <button type="button" class="pf-sheet-done" id="pfSheetDone">Show results</button>
                </div>
            </aside>

            <!-- Products Grid -->
            <main class="products-main">
                <?php include __DIR__ . '/includes/products_results.php'; ?>
            </main>
        </div>
    </div>
</section>

<script>
/* ---- Filtering without a page reload -------------------------------------
   Ticking a category used to call form.submit(), so every click threw the
   whole document away and rebuilt it - that was the reload. Instead we ask
   products.php for the same URL with &partial=1, which renders only the
   results region (includes/products_results.php), and swap that into
   <main class="products-main">. The address bar is kept in sync with
   history.pushState so back/forward, refresh and copy-pasted links behave
   exactly as they did before. With JS off, Apply Filters still submits the
   form the old way. */

const PF_URL = '<?php echo WEB_ROOT; ?>public/authenticated/customer/products.php';
let pfRequest = 0;          // request id - stale responses are discarded
let pfController = null;    // aborts the in-flight request when a new one starts

// The filter form (the sort <select> carries no name, so it is read
// separately) reduced to a query string. Empty values are dropped so the URL stays clean.
function pfBuildParams(overrides) {
    const form = document.getElementById('pfFilterForm');
    const params = new URLSearchParams();
    new FormData(form).forEach(function (value, key) {
        if (String(value).trim() !== '') params.append(key, value);
    });
    const sortSelect = document.getElementById('sortSelect');
    if (sortSelect) {
        params.delete('sort');
        if (sortSelect.value) params.set('sort', sortSelect.value);
    }
    Object.keys(overrides || {}).forEach(function (key) {
        const value = overrides[key];
        if (value === null || value === '') params.delete(key); else params.set(key, value);
    });
    return params;
}

// Mirrors the current form state back into the sidebar chrome the server would
// otherwise have re-rendered: count badge, Clear link, highlighted labels and
// the "All Products" row.
function pfSyncSidebar() {
    const boxes = Array.prototype.slice.call(
        document.querySelectorAll('#pfFilterForm input[name="category_id[]"]'));
    const checkedCount = boxes.filter(function (b) { return b.checked; }).length;

    boxes.forEach(function (b) {
        const label = b.closest('.pf-cat-label');
        if (label) label.classList.toggle('active', b.checked);
    });

    const badge = document.getElementById('pfCatBadge');
    if (badge) {
        badge.textContent = checkedCount;
        badge.hidden = checkedCount === 0;
    }
    const clear = document.getElementById('pfClear');
    if (clear) clear.hidden = checkedCount === 0;

    const all = document.querySelector('.category-filter .pf-all');
    if (all) {
        const form = document.getElementById('pfFilterForm');
        const hiddenCategory = form ? form.querySelector('input[name="category"]') : null;
        const hasCategory = hiddenCategory && hiddenCategory.value !== '';
        all.classList.toggle('active', checkedCount === 0 && !hasCategory);
    }

    // The phone toolbar reads the same two numbers off the page the server
    // would have re-rendered: how many categories are ticked, and how many
    // products the current result set holds. The count comes out of
    // .results-count inside the swapped region rather than from a second
    // request for a number the page already has.
    const tbCount = document.getElementById('pfToolbarCount');
    if (tbCount) {
        tbCount.textContent = checkedCount;
        tbCount.hidden = checkedCount === 0;
    }
    const tbItems = document.getElementById('pfToolbarItems');
    const resultsCount = document.querySelector('.products-main .results-count');
    if (tbItems && resultsCount) {
        const n = (resultsCount.textContent || '').match(/\d+/);
        if (n) tbItems.textContent = n[0];
    }
    const tbSort = document.getElementById('pfToolbarSort');
    const sortSel = document.getElementById('sortSelect');
    if (tbSort && sortSel && sortSel.selectedIndex >= 0) {
        tbSort.textContent = sortSel.options[sortSel.selectedIndex].text;
    }
}

// Fetches the results region for `params` and swaps it in.
function pfLoad(params, options) {
    options = options || {};
    const main = document.querySelector('.products-main');
    if (!main) return;

    const token = ++pfRequest;
    if (pfController) pfController.abort();
    pfController = ('AbortController' in window) ? new AbortController() : null;

    main.classList.add('pf-busy');
    main.setAttribute('aria-busy', 'true');

    const query = params.toString();
    const partial = new URLSearchParams(query);
    partial.set('partial', '1');

    fetch(PF_URL + '?' + partial.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: pfController ? pfController.signal : undefined
    })
    .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.text();
    })
    .then(function (html) {
        if (token !== pfRequest) return;   // a newer click already won
        main.innerHTML = html;
        main.classList.remove('pf-busy');
        main.removeAttribute('aria-busy');

        if (options.push !== false) {
            window.history.pushState({ pf: query }, '', PF_URL + (query ? '?' + query : ''));
        }
        pfSyncSidebar();
        if (options.scroll) {
            const top = main.getBoundingClientRect().top + window.pageYOffset - 90;
            window.scrollTo({ top: top, behavior: 'smooth' });
        }
    })
    .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        console.error('Filter request failed:', error);
        main.classList.remove('pf-busy');
        main.removeAttribute('aria-busy');
        // Last resort: fall back to the old full navigation so the user still
        // gets the filter they asked for.
        window.location.href = PF_URL + (query ? '?' + query : '');
    });
}

// Any filter change starts the listing over at page 1.
function pfApplyFilters(scroll) {
    const params = pfBuildParams();
    params.delete('page');
    pfLoad(params, { scroll: !!scroll });
}

function updateSort(sortValue) {
    pfApplyFilters(false);
}

/* ---- Faceted filter sidebar (categories chips/collapse/see-all) ---- */

document.addEventListener('DOMContentLoaded', function () {
    // Collapsible section headers (Categories / Sort By)
    document.querySelectorAll('[data-pf-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            const body = this.parentElement.querySelector('.pf-section-body');
            if (body) body.classList.toggle('pf-collapsed', expanded);
        });
    });

    const form = document.getElementById('pfFilterForm');
    if (!form) return;

    // Ticking / unticking a category applies immediately, in place.
    form.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'category_id[]') {
            pfSyncSidebar();
            pfApplyFilters(false);
        }
    });

    // Apply Filters (and Enter inside a price box) - same path, no reload.
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        pfApplyFilters(false);
    });

    // "All Products" / "Clear": wipe the selection without leaving the page.
    document.querySelectorAll('[data-pf-clear]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            form.querySelectorAll('input[name="category_id[]"]').forEach(function (b) {
                b.checked = false;
            });
            const min = form.querySelector('input[name="min_price"]');
            const max = form.querySelector('input[name="max_price"]');
            if (min) min.value = '';
            if (max) max.value = '';
            const hiddenCategory = form.querySelector('input[name="category"]');
            if (hiddenCategory) hiddenCategory.value = '';
            pfSyncSidebar();
            pfApplyFilters(false);
        });
    });

    // Pagination lives inside the swapped markup, so it is delegated.
    const main = document.querySelector('.products-main');
    if (main) {
        main.addEventListener('click', function (e) {
            const link = e.target.closest('.pagination a.pagination-btn');
            if (!link || link.classList.contains('active')) return;
            e.preventDefault();
            const page = new URL(link.href, window.location.origin).searchParams.get('page');
            pfLoad(pfBuildParams({ page: page }), { scroll: true });
        });
    }

    // Back/forward: re-render from the URL without pushing a new entry.
    window.addEventListener('popstate', function () {
        const params = new URLSearchParams(window.location.search);
        pfSyncFormFromParams(params);
        pfLoad(params, { push: false });
    });
});

// Puts the sidebar controls back in step with a URL (used on back/forward).
function pfSyncFormFromParams(params) {
    const form = document.getElementById('pfFilterForm');
    if (!form) return;
    const selected = params.getAll('category_id[]').map(String);
    form.querySelectorAll('input[name="category_id[]"]').forEach(function (b) {
        b.checked = selected.indexOf(b.value) !== -1;
    });
    const min = form.querySelector('input[name="min_price"]');
    const max = form.querySelector('input[name="max_price"]');
    if (min) min.value = params.get('min_price') || '';
    if (max) max.value = params.get('max_price') || '';
    const hiddenCategory = form.querySelector('input[name="category"]');
    if (hiddenCategory) hiddenCategory.value = params.get('category') || '';
    const sortSelect = document.getElementById('sortSelect');
    if (sortSelect) sortSelect.value = params.get('sort') || 'newest';
    pfSyncSidebar();
}

function buyNow(productId) {
    // Go directly to checkout without adding to cart
    window.location.href = 'checkout.php?selected=' + productId;
}

function addToCart(productId, quantity = 1, callback = null) {
    fetch('<?php echo WEB_ROOT; ?>public/authenticated/api/cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            action: 'add',
            product_id: productId,
            quantity: quantity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof data.cart_count === 'number') updateCartCount(data.cart_count); else updateCartCount();
            showNotification('Product added to cart!', 'success');
            if (callback) callback();
        } else {
            showNotification(data.message || 'Failed to add to cart', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

/* ---- The filter sheet ----------------------------------------------------
   Phone only. There is no second filter UI: this opens the one
   <aside class="filters-sidebar"> the desktop layout already renders, which
   CSS parks off the bottom of the screen under 768px and slides up on a class.
   So the form, its names, its submit handler and the in-place XHR filtering
   are exactly what they were - the panel just arrives from a different edge.

   Esc, the scrim, the grab handle and "Show results" all close it; focus is
   trapped while it is open and handed back to the button that opened it. */
(function () {
    const sheet = document.getElementById('pfSheet');
    const scrim = document.getElementById('pfScrim');
    const openF = document.getElementById('pfOpenSheet');
    const openS = document.getElementById('pfOpenSort');
    const grab  = document.getElementById('pfGrab');
    const done  = document.getElementById('pfSheetDone');
    if (!sheet || !scrim || !openF) return;

    const FOCUSABLE = 'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])';
    let lastFocus = null;

    function open(opener) {
        lastFocus = opener || document.activeElement;
        scrim.hidden = false;
        requestAnimationFrame(function () {
            document.body.classList.add('pf-sheet-open');
            scrim.classList.add('is-on');
        });
        [openF, openS].forEach(function (b) { if (b) b.setAttribute('aria-expanded', 'true'); });
        const first = sheet.querySelector(FOCUSABLE);
        if (first) first.focus();
    }

    function close() {
        document.body.classList.remove('pf-sheet-open');
        scrim.classList.remove('is-on');
        [openF, openS].forEach(function (b) { if (b) b.setAttribute('aria-expanded', 'false'); });
        const hide = function () { scrim.hidden = true; scrim.removeEventListener('transitionend', hide); };
        scrim.addEventListener('transitionend', hide);
        setTimeout(hide, 400);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    openF.addEventListener('click', function () { open(openF); });
    if (openS) {
        // Sort lives in the same panel, so the Sort pill opens the same sheet
        // and scrolls its Sort By section into view rather than cloning the
        // <select> into a second control with the same id.
        openS.addEventListener('click', function () {
            open(openS);
            const sel = document.getElementById('sortSelect');
            if (sel) {
                const sec = sel.closest('.pf-section');
                if (sec && sec.scrollIntoView) sec.scrollIntoView({ block: 'start' });
            }
        });
    }
    scrim.addEventListener('click', close);
    if (grab) grab.addEventListener('click', close);
    if (done) done.addEventListener('click', close);

    document.addEventListener('keydown', function (e) {
        if (!document.body.classList.contains('pf-sheet-open')) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') return;
        const items = sheet.querySelectorAll(FOCUSABLE);
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    // The panel is a plain aside again above 767.98px, so a sheet left open
    // when the viewport grows would otherwise keep the scroll lock on.
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 768 && document.body.classList.contains('pf-sheet-open')) close();
    });

    // First paint: fill the toolbar from the form the server just rendered.
    pfSyncSidebar();
})();

</script>

<style>
/* Same solid page background used on Profile/Orders/Edit Profile, so the
   customer area reads consistently instead of switching between the plain
   gradient here and there. */
/* No background of its own - this section must let the page ground through.
   customer-dark.css already paints the landing page's FULL ground for the
   customer area: the same radial on body, plus the 120px panning grid
   (body::before) and the pink/cyan blobs (body::after), both fixed at
   z-index:0. But it ALSO paints that radial opaquely onto .products-listing
   /.account-page itself, and `body > *:not(script):not(style)` lifts this
   section to z-index:1 - so the section was covering the grid and the blobs
   with a flat copy of the radial. That is the whole difference between this
   page's ground and the landing page's.

   Transparent here (matching how notifications-skin.css already does it for
   its own wrappers), with !important because the rule it answers -
   `body .products-listing { ... !important }` in customer-dark.css - is
   itself !important. */
.account-page { padding:40px 0; min-height:100vh; }
body .products-listing.account-page { background: transparent !important; }

/* ---- Faceted filter sidebar additions (pf- prefix) ----
   Page-scoped on purpose: .filters-sidebar/.filter-section/.category-filter
   are shared classes in style.css also used by guest/products.php, so new
   interactive pieces live under their own pf- prefixed classes here rather
   than touching the shared rules. Every color below reuses exactly the
   tokens/values .category-filter already uses (var(--bg-card),
   var(--text-primary), var(--text-secondary), var(--border-color), and the
   mauve accent rgba(167,118,147,*)/#a77693 that already drives the
   "active" state) - no new colors introduced anywhere. */


.pf-section-head{display:flex;align-items:center;justify-content:space-between;width:100%;background:none;border:none;padding:0 0 20px;cursor:pointer;text-align:left}
/* customer-dark.css wins both of these with !important (15px / Figtree 700 -
   see its .pf-section-title notes); the size here is the no-stylesheet
   fallback and is kept close to it rather than left at the old 18px. */
.pf-section-title{color:#e8eef7;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px}
/* 12px/22px, up from 11px/20px: the count rides inside .pf-section-title,
   which went from 10px to 15px, and an 11px pill next to it read as a
   leftover rather than part of the heading. */
.pf-badge{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:#a77693;color:#fff;font-size:12px;font-weight:800}
/* Literal ink here and below, not var(--text-*): main.js stamps
   data-theme="light" on <body> unconditionally, and style.css's
   [data-theme="light"] block (0,1,0) outranks customer-dark.css's `body`
   (0,0,1) - so every var(--text-primary)/(--text-secondary)/(--text-muted)
   on this page resolves to style.css's LIGHT palette (dark navy) while the
   panel stays dark. That is why the section labels, MIN/MAX and "Newest"
   were reading as dark-on-dark. Light mode is answered by theme-light.css's
   html[data-mode="light"] rules, which carry !important and win over these.
   Full write-up in customer-dark.css above .pf-section-title. */
.pf-chevron{color:#a9bcd4;font-size:14px;transition:transform 220ms ease}
.pf-section-head[aria-expanded="false"] .pf-chevron{transform:rotate(-90deg)}

/* Between-section breathing room, and an inset the box never had: neither
   style.css's shared .filter-section rule nor customer-dark.css's
   background/border override ever set padding, so the section title, the
   price inputs, the sort dropdown and the category list all sat flush
   against the box's own left/right/top edges - only each row's own small
   padding (.pf-cat-label, .category-filter a, input padding) gave any
   inset at all, and the header/inputs got none. 20px on all sides fixes
   that uniformly for all three sections; .pf-section-head's own bottom
   padding (below) still adds the extra gap under the header on top of it.
   Overrides style.css's shared .filter-section{margin-bottom:30px} for
   this page only - later in the cascade wins on a specificity tie, and
   guest/products.php never includes this block (it has its own separate
   inline <style>, with the same unpadded .filter-section left as-is), so
   nothing here reaches that page. */
.filter-section{margin-bottom:34px;padding:20px}

/* Collapse by animating the grid row track, not a guessed max-height. The
   old max-height:600px paired with overflow:hidden silently clipped the tail
   of the category list - 20 rows at ~43px each is ~860px - so the last third
   of the categories was invisible AND unreachable, with no scrollbar to hint
   at it. A 0fr/1fr row measures the content itself, so the panel always opens
   to exactly the height it needs however many categories exist. */
.pf-section-body{display:grid;grid-template-rows:1fr;transition:grid-template-rows 220ms ease,opacity 220ms ease;opacity:1}
.pf-section-body.pf-collapsed{grid-template-rows:0fr;opacity:0}
/* The clipper has to be the inner wrapper, not the grid itself, or a 0fr row
   still paints its overflowing children. overflow:clip + a clip-margin keeps
   the inputs' focus outlines from being shaved off at the panel edge, which
   plain overflow:hidden would do; hidden stays first as the old-browser
   fallback. */
.pf-section-inner{min-height:0;overflow:hidden;overflow:clip;overflow-clip-margin:4px}

/* Category names and "All Products" are regular readable copy, same role as
   product names on the landing card - Figtree, not the mono reserved for
   eyebrows/labels/prices, and not the sitewide Manrope they were silently
   inheriting (neither .pf-cat-label nor .category-filter a set a
   font-family of their own anywhere in the cascade). Both also had no
   font-size, so they rendered at the browser default (16px) - one shared
   14px here (customer-home.css's .category-slots .slot-label, the sidebar's
   own equivalent list-row text, already uses the same value) is what makes
   them actually read as the same size instead of only sharing a font. */
/* 15px, up from 14px, and the resting ink moved off --text-secondary onto
   --text-primary: the category names are the panel's longest list and its
   main read, so they now sit at the same size and weight of ink as the
   section headings above them rather than a step below. Both tokens are
   theme-aware, so this holds in light mode too. */
.pf-cat-label,.category-filter a{font-family:'Figtree',system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px}
.pf-cat-label{display:flex;align-items:center;gap:12px;color:#e8eef7;padding:10px 12px;border-radius:8px;cursor:pointer;transition:background .15s ease,color .15s ease}
.pf-cat-label:hover{background:var(--bg-card-hover,rgba(0,0,0,.03))}
.pf-cat-label.active{background:rgba(167,118,147,.1);color:#a77693;font-weight:600}
.pf-cat-label input{accent-color:#a77693;width:16px;height:16px;cursor:pointer}

/* Row-to-row gap in the checkbox list. Overrides style.css's shared
   .category-filter li{margin-bottom:8px}, page-scoped the same way as above -
   guest's <li><a>...</a></li> rows (no .pf-cat-label) are unaffected. */
.category-filter li{margin-bottom:12px}

/* "All Products" pill: extra separation from CLEAR above and the checklist
   below, on top of the row gap it already gets from .category-filter li. */
.pf-all{margin:14px 0 16px}

.pf-select-wrap{position:relative}
.pf-select-wrap .pf-select-chevron{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:#a9bcd4;font-size:12px;transition:transform 200ms ease}
.pf-select-wrap select:focus + .pf-select-chevron{transform:translateY(-50%) rotate(180deg)}

@media (prefers-reduced-motion: reduce){.pf-chevron,.pf-section-body,.pf-select-chevron{transition-duration:.01ms!important}}

/* Form controls don't inherit font-family/font-size from the page by
   default (a <select> falls back to the browser's own UI font absent an
   explicit rule), so "Newest" was rendering in neither this page's font nor
   its size - visibly mismatched against .pf-cat-label/.category-filter a
   right above it. Same Figtree family as those two.

   Sized at 15px rather than the old 11px: it was matched to .pf-price-row
   input on the argument that MIN/MAX is the control it sits closest to, but
   both were too small to read and both have now gone up together. "Price:
   High to Low" is the longest string in the panel and the one most often
   actually read, so it is the last place to be saving pixels. */
#sortSelect{width:100%;padding:11px 36px 11px 12px;border-radius:var(--radius-sm,10px);
  background:var(--bg-card-solid);color:#e8eef7;border:none;box-shadow:var(--neu-pressed);appearance:none;-webkit-appearance:none;-moz-appearance:none;
  font-family:'Figtree',system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;
  position:relative;z-index:1;cursor:pointer;outline:none;transition:outline var(--duration-fast) var(--ease-out)}
#sortSelect:focus{outline:2px solid var(--accent-cyan);outline-offset:1px}
#sortSelect option{background:#0c1a2c;color:#e8eef7;font-family:inherit;font-size:inherit}

/* Custom chevron */
.filter-section select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23cbd5e1' viewBox='0 0 16 16'%3E%3Cpath d='M4.646 6.646a.5.5 0 0 1 .708 0L8 9.293l2.646-2.647a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 12px center;background-size:16px}

/* The image well is the card's primary link now (the hover-only eye button
   it replaced was a 40px target that only existed on hover, so it was
   invisible on touch and to keyboard users). Same-tab navigation, matching
   the title link right below it - opening a catalog item in a new tab was
   never asked for by the click. */
.product-image{text-decoration:none;color:inherit}
.product-image:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:-2px}

/* Products header */
/* In-place filtering: while the new results are on the wire the current ones
   dim slightly and stop taking clicks. Short and subtle on purpose - the swap
   is usually quicker than the eye, and a spinner would flash more than the old
   reload did. */
.products-main{transition:opacity 160ms ease}
.products-main.pf-busy{opacity:.45;pointer-events:none}
@media (prefers-reduced-motion:reduce){.products-main{transition:none}}

.products-header{display:flex;align-items:end;justify-content:space-between;gap:12px;margin-bottom:14px}
.products-header h1{margin:0;color:var(--text-primary);font-size:18px}
.results-count{margin:0;color:var(--text-muted);font-size:13px}

@media (max-width: 1024px){
  .products-layout{grid-template-columns:1fr}
  .filters-sidebar{position:relative;top:auto}
}
/* Search Suggestions Styles */
.search-suggestion {
    background: rgba(23, 72, 113, 0.1);
    border: 1px solid rgba(23, 72, 113, 0.3);
    border-radius: 8px;
    padding: 12px 16px;
    margin: 16px 0;
    color: var(--text-primary);
}

.search-suggestions {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 8px;
    padding: 16px;
    margin: 16px 0;
}

.search-suggestions p {
    color: #fbbf24;
    margin-bottom: 12px;
    font-weight: 600;
}

.suggestion-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.suggestion-btn {
    background: rgba(23, 72, 113, 0.2);
    border: 1px solid rgba(23, 72, 113, 0.4);
    color: var(--accent-blue);
    padding: 8px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.suggestion-btn:hover {
    background: rgba(23, 72, 113, 0.3);
    border-color: #174871;
    color: var(--accent-blue);
    transform: translateY(-1px);
}

.suggestion-count {
    background: rgba(23, 72, 113, 0.3);
    color: var(--accent-blue);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

/* Responsive design for suggestions */
@media (max-width: 768px) {
    .suggestion-buttons {
        flex-direction: column;
    }
    
    .suggestion-btn {
        justify-content: center;
    }
}

/* Enhanced Product Card Styles */

/* --- Level out the product cards ---
   Cards in a grid row already stretch to the tallest card, but the rows
   inside them (title / rating / price / buttons) drifted out of alignment
   because product names wrap to 1 or 2 lines and the strike-through
   "original price" only shows on some cards. Make each card a flex column,
   reserve a fixed 2-line slot for the title and a fixed slot for the price,
   then let margin-top:auto pin the action buttons to the bottom of every
   card so they all line up. */
.products-grid { align-items: stretch; }
.product-card { display: flex; flex-direction: column; height: 100%; }
.product-info { display: flex; flex-direction: column; flex: 1; }
.product-title { margin: 0; }
.product-title a {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 2.8em;            /* 2 lines at line-height 1.4 */
}
.product-price { min-height: 2.9em; display: flex; flex-direction: column; justify-content: center; }
.product-actions { margin-top: auto; }

/* --- Electric border (replaces the plain 1px card border) ---
   A mauve border matched to the site accent (--accent-cyan #a05f80). It
   sits on every card as a soft 2px line + halo; on hover it's run through
   the animated #eb-turbulence SVG filter (defined once just above the
   grid) so it crackles like a live wire and the halo "charges up". The
   filter only runs on the hovered card - the grid renders 12 at once, so
   filtering them all would jank. Move `filter:url(#eb-turbulence)` up to
   `.product-card::after` (drop the :hover) to make it always-on. */
.products-grid .product-card{
  --eb:#a9819a;                       /* electric line - muted, desaturated mauve */
  --eb-light:#c4aab8;                 /* soft highlight */
  --eb-dim:rgba(160,95,128,0.24);     /* faint resting border */
  --eb-glow:rgba(160,95,128,0.22);    /* gentle halo */
  border:1.5px solid var(--eb-dim) !important;
  overflow:visible;
  isolation:isolate;
  box-shadow:0 6px 18px rgba(23,50,74,0.09),
             0 0 14px -10px var(--eb-glow) !important;
  transition:box-shadow .45s cubic-bezier(.22,1,.36,1),
             transform .3s ease, border-color .4s ease;
}
/* the product image used the card's `overflow:hidden` to clip its rounded
   top corners - restore that on the image itself now the card shows overflow */
.product-card .product-image{ border-radius:15px 15px 0 0; }
/* Hover is a plain border-color stroke - same idiom as the "Why Choose"
   bento cells (.tw-bento__cell:hover) and the landing page's own featured
   cards (.th-product-card, fixed the same way). This card used to also run
   a second ::after ring through the #eb-turbulence SVG displacement filter,
   which jittered the ring's edge into a jagged "lightning" outline on
   hover; that ring is removed, and the border-color change below is what
   is left doing the job. */
.products-grid .product-card:hover{
  border-color:var(--eb) !important;
  box-shadow:0 12px 30px rgba(23,50,74,0.13),
             0 0 22px -10px var(--eb-glow) !important;
}

.price-stock-section {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    gap: 15px;
    position: relative;
}

.product-price {
    flex: 1;
}

.current-price {
    color: var(--accent-cyan);
    font-size: 24px;
    font-weight: 800;
    display: block;
}

/* Auto shrink for long prices */
.current-price.small { font-size: 20px; }
.current-price.xs { font-size: 18px; }
.current-price.xxs { font-size: 16px; }

.original-price {
    color: var(--text-muted);
    font-size: 16px;
    text-decoration: line-through;
    margin-left: 8px;
    opacity: 0.8;
}

.stock-indicator {
    display: flex;
    align-items: center;
    font-size: 10px; /* smaller to fit alongside long prices */
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 50px;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.25), inset 0 1px 0 var(--border-color);
    min-height: 28px;
    border: 2px solid transparent;
    backdrop-filter: blur(10px);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    flex: 0 0 auto; /* keep width to content and stay on same line */
}

.stock-indicator::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.6s ease-in-out;
}

.stock-indicator::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    right: 2px;
    bottom: 2px;
    border-radius: 50px;
    background: linear-gradient(45deg, var(--border-color), transparent, rgba(255, 255, 255, 0.05));
    pointer-events: none;
}

.stock-indicator:hover::before {
    left: 100%;
}

.stock-indicator i {
    margin-right: 6px;
    font-size: 12px;
    filter: drop-shadow(0 0 4px currentColor) drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
    position: relative;
    z-index: 2;
}

.stock-available {
    background: transparent;
    color: var(--accent-blue);
    border: none;
    animation: tech-pulse 4s ease-in-out infinite;
    box-shadow: none;
}

.stock-low {
    background: transparent;
    color: var(--accent-blue);
    border: none;
    animation: pulse-warning 2s infinite, tech-scan 3s linear infinite;
    position: relative;
    box-shadow: none;
}

.stock-out {
    background: linear-gradient(135deg, #dc2626, #ef4444, #dc2626);
    color: white;
    border: 2px solid #f87171;
    animation: glow-red 2s ease-in-out infinite alternate, tech-warning 2s ease-in-out infinite;
    position: relative;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

@keyframes glow-blue {
    0% { 
        box-shadow: 0 4px 15px rgba(23, 72, 113, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        border-color: #a77693;
    }
    100% { 
        box-shadow: 0 6px 25px rgba(23, 72, 113, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        border-color: #c9a3b5;
    }
}

@keyframes glow-red {
    0% { 
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        border-color: #f87171;
    }
    100% { 
        box-shadow: 0 6px 25px rgba(239, 68, 68, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        border-color: #fca5a5;
    }
}

@keyframes tech-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.02); }
}

@keyframes tech-scan {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

@keyframes tech-warning {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

@keyframes pulse-warning {
    0%, 100% { 
        transform: scale(1);
        opacity: 1;
    }
    50% { 
        transform: scale(1.05);
        opacity: 0.9;
    }
}

@keyframes flash {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

.product-actions {
    display: flex;
    gap: 12px;
    margin-top: 15px;
}

.add-to-cart-btn,
.buy-now-btn,
.wishlist-action-btn {
    flex: 1;
    padding: 10px 8px;
    border: none;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-decoration: none;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    position: relative;
    overflow: hidden;
}

.add-to-cart-btn::before,
.buy-now-btn::before,
.wishlist-action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.6s;
}

.add-to-cart-btn:hover::before,
.buy-now-btn:hover::before,
.wishlist-action-btn:hover::before {
    left: 100%;
}

.add-to-cart-btn {
    background: linear-gradient(135deg, var(--accent-cyan-bg-20), var(--accent-cyan-bg));
    color: var(--accent-cyan);
    border: 2px solid var(--accent-cyan);
    box-shadow: 0 4px 15px var(--accent-cyan-bg-20);
}

.add-to-cart-btn:hover {
    background: linear-gradient(135deg, var(--accent-cyan), #c99bb0);
    color: white;
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 6px 15px var(--accent-cyan-bg-30);
}

.buy-now-btn {
    background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
    color: white;
    border: 2px solid transparent;
    box-shadow: 0 4px 15px rgba(23, 72, 113, 0.3);
    position: relative;
}

.buy-now-btn:hover {
    background: linear-gradient(135deg, var(--accent-blue), #0f2d4d);
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 8px 20px rgba(23, 72, 113, 0.4);
}

.wishlist-action-btn {
    background: linear-gradient(135deg, var(--accent-cyan-bg), var(--bg-card-alpha-80));
    color: var(--accent-cyan);
    border: 1px solid var(--accent-cyan);
    width: 100%;
    box-shadow: 0 2px 8px rgba(167, 118, 147, 0.15);
    position: relative;
}

.wishlist-action-btn:hover {
    background: linear-gradient(135deg, var(--accent-cyan-bg-20), var(--bg-card-hover));
    color: var(--accent-cyan);
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 6px 15px var(--accent-cyan-bg-30);
}

.wishlist-action-btn i {
    font-size: 12px;
    filter: drop-shadow(0 0 2px currentColor);
}

@media (max-width: 768px) {
    .price-stock-section {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .stock-indicator {
        align-self: flex-end;
    }
    
    .product-actions {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<?php
include __DIR__ . '/includes/footer.php';
?>
</body>
</html>
<?php
?>


