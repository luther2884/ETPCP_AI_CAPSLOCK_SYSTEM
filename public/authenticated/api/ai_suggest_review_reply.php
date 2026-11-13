<?php
// api/ai_suggest_review_reply.php - AI-drafted store reply to a customer
// review. Reuses the same provider config/client (config/ai_config.php,
// config/gemini_client.php) as the AI Builder and AI Add Product features -
// this is just a third, differently-shaped prompt against the same Gemini
// client, not a separate AI integration.
//
// Only ever DRAFTS a reply - the admin reviews/edits the returned text
// client-side (see reviews.php) before anything is saved through the
// separate save_reply POST handler there. Nothing here writes to the
// database.
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
    echo json_encode(['success' => false, 'message' => 'AI is not configured on this server (missing GEMINI_API_KEY). Please write a reply manually.']);
    exit;
}

$reviewId = (int)($_POST['review_id'] ?? 0);
if ($reviewId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid review.']);
    exit;
}

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("
        SELECT r.rating, r.title, r.comment, r.is_verified_purchase, p.name as product_name, u.firstname
        FROM reviews r
        JOIN products p ON p.id = r.product_id
        JOIN users u ON u.id = r.user_id
        WHERE r.id = ?
    ");
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('ai_suggest_review_reply lookup error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error looking up the review.']);
    exit;
}

if (!$review) {
    echo json_encode(['success' => false, 'message' => 'Review not found.']);
    exit;
}

$rating = (int)$review['rating'];
$sentiment = $rating >= 4 ? 'positive' : ($rating <= 2 ? 'negative' : 'mixed/neutral');

$systemPrompt = <<<PROMPT
Write ONE public reply, 2-4 sentences, as TechWorld's support team, to a customer's product review. Warm, professional, human - never robotic or generic. Use their first name if given, and thank them.

4-5 stars: genuine appreciation; optionally invite them back.
1-2 stars, or any real problem raised: apologize sincerely, no defensiveness or excuses, name their specific concern in their own terms, and invite them to contact TechWorld support. Never promise a refund, replacement or any outcome - staff decide that after review.
3 stars or no written comment: brief, friendly thanks for the rating and feedback.

Never invent details about the product, order or a resolution that wasn't in the review. Never mention AI.
PROMPT;

$userPrompt = "Customer: {$review['firstname']}\n"
    . "Rating: {$rating}/5 ({$sentiment})\n"
    . "Review title: " . ($review['title'] ?: '(none)') . "\n"
    . "Review comment: " . ($review['comment'] ?: '(no written comment, rating only)') . "\n"
    . "Product: {$review['product_name']}\n"
    . "Verified purchase: " . ($review['is_verified_purchase'] ? 'yes' : 'no') . "\n\n"
    . "Write the reply now.";

$schema = [
    'type' => 'OBJECT',
    'properties' => [
        'reply' => ['type' => 'STRING'],
    ],
    'required' => ['reply'],
];

// Through config/ai_guard.php: rate-limited, spend-capped, retried at most
// once, and routed to the cheap model tier - drafting two sentences of
// customer-service prose does not need the model that reasons over a catalog.
// Cached for a day, so re-drafting for the same review reuses the answer.
$result = ai_guarded_json('review_reply', $systemPrompt, $userPrompt, $schema, ['timeout' => 30]);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

$reply = trim((string)($result['data']['reply'] ?? ''));
if ($reply === '') {
    echo json_encode(['success' => false, 'message' => 'The AI did not return a usable reply. Please write one manually.']);
    exit;
}

echo json_encode(['success' => true, 'reply' => $reply]);
