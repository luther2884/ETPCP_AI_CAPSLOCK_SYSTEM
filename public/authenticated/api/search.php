<?php
// api/search.php - Smart search with typo handling and suggestions
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/rate_limit.php';

header('Content-Type: application/json');

// Public, unauthenticated, and every action runs LIKE queries across the
// catalog - cheap to call, expensive to serve, so it is worth a ceiling.
// Set high on purpose: the admin products page deliberately fires this on
// every keystroke with no debounce, and typing must never be throttled.
rl_guard('search_api', 240, 60);

$action = $_GET['action'] ?? 'search';
$query = trim($_GET['q'] ?? '');
$limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));

if (empty($query)) {
    echo json_encode(['success' => false, 'message' => 'Search query required']);
    exit;
}

try {
    $pdo = getConnection();
    
    switch ($action) {
        case 'search':
            handleSmartSearch($pdo, $query, $limit);
            break;
        case 'suggestions':
            handleSuggestions($pdo, $query);
            break;
        case 'autocomplete':
            handleAutocomplete($pdo, $query);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Search API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Search error']);
}

function handleSmartSearch($pdo, $query, $limit) {
    // First, try exact search
    $exactResults = performExactSearch($pdo, $query, $limit);
    
    if (!empty($exactResults)) {
        echo json_encode([
            'success' => true,
            'results' => $exactResults,
            'search_type' => 'exact',
            'query' => $query,
            'total' => count($exactResults)
        ]);
        return;
    }
    
    // If no exact results, try fuzzy search
    $fuzzyResults = performFuzzySearch($pdo, $query, $limit);
    
    if (!empty($fuzzyResults)) {
        echo json_encode([
            'success' => true,
            'results' => $fuzzyResults,
            'search_type' => 'fuzzy',
            'query' => $query,
            'suggestion' => $fuzzyResults[0]['suggested_query'] ?? null,
            'total' => count($fuzzyResults)
        ]);
        return;
    }
    
    // If still no results, provide suggestions
    $suggestions = getSearchSuggestions($pdo, $query);
    
    echo json_encode([
        'success' => true,
        'results' => [],
        'search_type' => 'no_results',
        'query' => $query,
        'suggestions' => $suggestions,
        'total' => 0
    ]);
}

function performExactSearch($pdo, $query, $limit) {
    $searchTerm = '%' . $query . '%';
    
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
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function performFuzzySearch($pdo, $query, $limit) {
    // Get all products and categories for fuzzy matching
    $sql = "SELECT p.*, c.name as category_name FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.status = 'active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
        
        // Get the best match
        $bestScore = max($nameScore, $descScore, $catScore);
        
        // Only include if similarity is above threshold (60%)
        if ($bestScore > 0.6) {
            $product['similarity_score'] = $bestScore;
            $product['suggested_query'] = getSuggestedQuery($query, $name, $description, $category);
            $fuzzyResults[] = $product;
        }
    }
    
    // Sort by similarity score
    usort($fuzzyResults, function($a, $b) {
        return $b['similarity_score'] <=> $a['similarity_score'];
    });
    
    return array_slice($fuzzyResults, 0, $limit);
}

function calculateSimilarity($str1, $str2) {
    // Use Levenshtein distance for similarity calculation
    $maxLen = max(strlen($str1), strlen($str2));
    if ($maxLen == 0) return 1;
    
    $distance = levenshtein($str1, $str2);
    return 1 - ($distance / $maxLen);
}

function getSuggestedQuery($original, $name, $description, $category) {
    // Find the closest match and suggest it
    $suggestions = [];
    
    // Extract words from product name
    $nameWords = explode(' ', $name);
    foreach ($nameWords as $word) {
        if (strlen($word) > 3 && calculateSimilarity($original, $word) > 0.5) {
            $suggestions[] = $word;
        }
    }
    
    // Extract words from category
    if (calculateSimilarity($original, $category) > 0.5) {
        $suggestions[] = $category;
    }
    
    return !empty($suggestions) ? $suggestions[0] : null;
}

function getSearchSuggestions($pdo, $query) {
    // Get popular search terms and similar products
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
    
    // Get popular product names
    $prodSql = "SELECT name, COUNT(*) as search_count 
                FROM products 
                WHERE status = 'active' 
                GROUP BY name 
                ORDER BY search_count DESC 
                LIMIT 10";
    $stmt = $pdo->prepare($prodSql);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($products as $prod) {
        if (calculateSimilarity(strtolower($query), strtolower($prod['name'])) > 0.3) {
            $suggestions[] = [
                'text' => $prod['name'],
                'type' => 'product',
                'count' => $prod['search_count']
            ];
        }
    }
    
    return array_slice($suggestions, 0, 5);
}

function handleSuggestions($pdo, $query) {
    $suggestions = getSearchSuggestions($pdo, $query);
    
    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions,
        'query' => $query
    ]);
}

function handleAutocomplete($pdo, $query) {
    $searchTerm = '%' . $query . '%';
    
    $sql = "SELECT DISTINCT p.name, c.name as category_name
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.status = 'active' 
            AND (p.name LIKE ? OR c.name LIKE ?)
            ORDER BY p.name ASC
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$searchTerm, $searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'suggestions' => $results,
        'query' => $query
    ]);
}
?>
