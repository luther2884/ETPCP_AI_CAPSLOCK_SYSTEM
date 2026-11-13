<?php
// api/ai_add_product.php - AI Add Product image-analysis endpoint. Reuses
// the same provider config/auth (config/ai_config.php, config/gemini_client.php)
// as the AI Builder - this is just a second, differently-shaped prompt
// against the same Gemini client, not a separate AI integration.
//
// Analyzes an uploaded product photo and suggests a name (only when the
// admin didn't already type one), description, and category - constrained
// to the store's *existing* categories (config/functions.php's
// getCategories()) so whatever comes back is always immediately compatible
// with the real products table, the AI Builder's category-based matching,
// and cart/checkout - nothing here ever invents a new category or writes
// to the database itself. The admin reviews/edits the result client-side
// (see add_product.php) before any product is actually saved through the
// normal, unchanged Add Product form submission.
//
// Also gates on relevance: if the photo clearly isn't a TechWorld-type
// product (desktop/laptop, parts/accessories, printer, CCTV/access
// control - see $systemPrompt below), the model flags in_scope=false and
// this returns success=false with out_of_scope=true instead of a made-up
// name/description/category, so an admin who uploads an unrelated photo
// gets told so plainly rather than getting a confidently wrong result.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/ai_guard.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !in_array(getUserRole(), ['admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!ai_is_configured()) {
    echo json_encode(['success' => false, 'message' => 'AI is not configured on this server (missing GEMINI_API_KEY). Please fill in the product details manually.']);
    exit;
}

if (empty($_FILES['image']['name']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please choose a product image first.']);
    exit;
}

$file = $_FILES['image'];
// Same whitelist/size ballpark as the real upload handler below in
// add_product.php - this call never persists the file, just reads it.
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowedMime, true)) {
    echo json_encode(['success' => false, 'message' => 'Unsupported image type. Use JPG, PNG, or WEBP.']);
    exit;
}
if ($file['size'] > 8 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Image is too large (max 8MB).']);
    exit;
}
$imageBytes = file_get_contents($file['tmp_name']);
if ($imageBytes === false) {
    echo json_encode(['success' => false, 'message' => 'Could not read the uploaded image. Please try again.']);
    exit;
}

$providedName = trim((string)($_POST['name'] ?? ''));

$categories = getCategories();
$categoryNames = array_values(array_unique(array_map(fn($c) => (string)$c['name'], $categories)));
if (empty($categoryNames)) {
    echo json_encode(['success' => false, 'message' => 'No product categories exist yet to classify into. Please add a category first.']);
    exit;
}

$categoryListText = '- ' . implode("\n- ", $categoryNames);
$systemPrompt = <<<PROMPT
You catalog products for TechWorld, which sells desktop/laptop computers, PC parts, printers and ink/toner, networking gear, tech peripherals and accessories, and CCTV/access-control equipment.

in_scope: does the photo literally show a product of that kind? Judge the subject of the photo, not the category list - food, people, animals, vehicles, furniture or any other unrelated object is NOT in scope even though the schema still forces a category_name.

If in_scope is false: out_of_scope_reason = one plain sentence naming what the photo actually shows (e.g. "This looks like a photo of food, not a computer/tech product."). Still fill the other fields with your best literal guess; they will be discarded.

If in_scope is true: out_of_scope_reason = "". Identify the product precisely - brand, model name/number, and a key spec such as capacity or size where visible - not a generic label. If the photo is unclear or you are unsure of the exact model, still give your single best guess and open description with "Possibly a ..."; an admin reviews every field before anything is saved.

category_name MUST be exactly one of (best single match, never a new one):
{$categoryListText}

description: 1-3 plain sentences on what the product is and its key visible features/specs, suitable for a product page. Never mention the photo or that you are an AI.
PROMPT;

$userPrompt = $providedName !== ''
    ? "The admin already named this product \"{$providedName}\" - use that context to help identify it, but judge in_scope and category/description from what the photo actually shows."
    : "Identify this product from the photo.";

$schema = [
    'type' => 'OBJECT',
    'properties' => [
        'in_scope' => ['type' => 'BOOLEAN'],
        'out_of_scope_reason' => ['type' => 'STRING'],
        'name' => ['type' => 'STRING'],
        'description' => ['type' => 'STRING'],
        'category_name' => ['type' => 'STRING', 'enum' => $categoryNames],
    ],
    'required' => ['in_scope', 'out_of_scope_reason', 'name', 'description', 'category_name'],
];

// Through config/ai_guard.php, which applies the rate limits, the spend caps
// and the retry ceiling. Cached for 7 days keyed partly on a hash of the image
// bytes, so re-analysing the same photo (a mis-click, a retry, a second admin
// adding the same product) costs nothing.
$result = ai_guarded_json('add_product', $systemPrompt, $userPrompt, $schema,
    ['timeout' => 45, 'image' => ['mime_type' => $mime, 'data' => $imageBytes]]);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

$data = $result['data'];

if (empty($data['in_scope'])) {
    $reason = trim((string)($data['out_of_scope_reason'] ?? ''));
    echo json_encode([
        'success' => false,
        'out_of_scope' => true,
        'message' => 'This photo doesn\'t look like a TechWorld product (desktop/laptop, computer parts and accessories, printer, or CCTV/access control equipment).' . ($reason !== '' ? ' ' . $reason : '') . ' Please upload a relevant product photo, or fill in the details manually.',
    ]);
    exit;
}

// Never trust the AI's own id/name for the category - re-resolve by exact
// (case-insensitive) name against the real, current categories table, the
// same "backend-authoritative" pattern the AI Builder uses for product ids.
$resolvedCategoryId = null;
$resolvedCategoryName = null;
foreach ($categories as $c) {
    if (strcasecmp((string)$c['name'], (string)($data['category_name'] ?? '')) === 0) {
        $resolvedCategoryId = (int)$c['id'];
        $resolvedCategoryName = (string)$c['name'];
        break;
    }
}

echo json_encode([
    'success' => true,
    // The admin's own typed name always wins if they gave one - the AI is
    // only ever asked to fill in what's actually missing.
    'name' => $providedName !== '' ? $providedName : trim((string)($data['name'] ?? '')),
    'description' => trim((string)($data['description'] ?? '')),
    'category_id' => $resolvedCategoryId,
    'category_name' => $resolvedCategoryName,
]);
