<?php
declare(strict_types=1);

/**
 * execution/prewarm_ai_builder.php - fill the AI Builder response cache ahead
 * of a live demo, so the demo itself makes no API call at all.
 *
 * Why this exists: on the Gemini free tier the builder gets a small number of
 * requests per day, and a 429 during a presentation drops the page to the
 * rule-based fallback. Every successful answer is cached for 7 days keyed on
 * the exact prompt (see ai_feature_policy()), so running the scenarios you
 * intend to show, in advance, converts them into cache hits that cost nothing
 * and cannot be rate-limited.
 *
 * IMPORTANT: the cache key embeds the catalog. Editing a product's price or
 * stock after pre-warming invalidates the entry and the scenario goes live
 * again - so run this AFTER the catalog is final, and do not touch products
 * between pre-warming and the demo.
 *
 * Usage:
 *   php execution/prewarm_ai_builder.php            # warm the scenarios below
 *   php execution/prewarm_ai_builder.php --status   # report only, no API calls
 */

chdir(dirname(__DIR__));
require 'config/env.php';
require 'config/database.php';
require 'config/ai_builder_ai.php';

/**
 * The scenarios to warm. Each must match EXACTLY what the wizard will submit -
 * a different budget or a different use is a different prompt and therefore a
 * different cache key. Edit this list to match your demo script.
 */
const PREWARM_SCENARIOS = [
    ['label' => 'Office / balanced / 45k',    'budget_min' => 20000, 'budget_max' => 45000,  'uses' => ['office'],  'performance' => 'balanced'],
    ['label' => 'Gaming / performance / 80k', 'budget_min' => 40000, 'budget_max' => 80000,  'uses' => ['gaming'],  'performance' => 'performance'],
    ['label' => 'School / budget / 30k',      'budget_min' => 15000, 'budget_max' => 30000,  'uses' => ['school'],  'performance' => 'budget'],
];

$statusOnly = in_array('--status', $argv, true);
$pdo = getConnection();

printf("model      : %s\n", GEMINI_MODEL);
$policy = ai_feature_policy('builder');
printf("cache ttl  : %d s (%.1f days)\nmax output : %d\n\n", $policy['cache_ttl'], $policy['cache_ttl'] / 86400, $policy['max_output']);

$warm = 0;
$cold = 0;

foreach (PREWARM_SCENARIOS as $s) {
    $input = [
        'budget_min'   => (float)$s['budget_min'],
        'budget_max'   => (float)$s['budget_max'],
        'intended_use' => $s['uses'],
        'performance'  => $s['performance'],
    ];

    if ($statusOnly) {
        // Report without spending a request: rebuild the exact prompt the
        // guard would hash, then look the key up directly.
        printf("%-28s %s\n", $s['label'], aib_prewarm_is_cached($pdo, $input) ? 'CACHED' : 'not cached');
        continue;
    }

    $t = microtime(true);
    $r = aib_ai_generate_recommendation($pdo, $input);
    $elapsed = microtime(true) - $t;

    if ($r['success']) {
        $warm++;
        printf("%-28s OK    %d builds  %5.1fs\n", $s['label'], count($r['builds']), $elapsed);
    } else {
        $cold++;
        printf("%-28s FAIL  %5.1fs  %s\n", $s['label'], $elapsed, $r['error']);
    }

    // Free-tier request-per-minute limits are low; do not burst.
    if ($s !== end(PREWARM_SCENARIOS)) { sleep(15); }
}

if (!$statusOnly) {
    printf("\nwarmed %d, failed %d\n", $warm, $cold);
    if ($cold > 0) {
        echo "Re-run later for the failed ones - a failure is never cached, so\n";
        echo "retrying costs nothing extra and the successes above are kept.\n";
    }
}

/**
 * Whether this scenario already has a live cache entry, without calling the
 * API. Mirrors the key construction in ai_guarded_json().
 */
function aib_prewarm_is_cached(PDO $pdo, array $input): bool {
    $policy = ai_feature_policy('builder');
    $catalog = aib_ai_build_catalog($pdo, (float)$input['budget_max']);

    $customer = [
        'budget_min' => (float)$input['budget_min'],
        'budget_max' => (float)$input['budget_max'],
        'intended_use' => array_values($input['intended_use']),
        'performance_preference' => (string)$input['performance'],
        'preferred_brand' => null,
        'other_requirements' => [],
        'additional_message' => null,
    ];
    require_once 'config/ai_builder_filter.php';
    $customer['hard_limits'] = aibf_hard_limits($pdo, [
        'budget_max'   => (float)$input['budget_max'],
        'intended_use' => $customer['intended_use'],
        'performance'  => $customer['performance_preference'],
    ]);

    $userPrompt = "CUSTOMER_INFORMATION_JSON:\n" . json_encode($customer, JSON_UNESCAPED_SLASHES)
        . "\n\nAVAILABLE_PRODUCTS_JSON:\n" . json_encode(['products' => $catalog['products']], JSON_UNESCAPED_SLASHES)
        . "\n\nReturn your recommendation using the required JSON schema only.";

    $key = hash('sha256', implode("\0", [
        'builder', $policy['model'], aib_ai_system_prompt(), $userPrompt,
        json_encode(aib_ai_response_schema()), '', '',
    ]));

    $stmt = $pdo->prepare("SELECT 1 FROM ai_response_cache WHERE cache_key = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$key]);
    return $stmt->fetchColumn() !== false;
}
