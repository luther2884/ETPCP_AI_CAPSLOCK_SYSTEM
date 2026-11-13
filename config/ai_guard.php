<?php
declare(strict_types=1);

/**
 * config/ai_guard.php - the single cost-control gate every paid AI call in
 * this project must pass through.
 *
 * Gemini is the only metered, billable API this codebase talks to. (Xendit
 * bills per real payment, and the OSM/OSRM map services used by the delivery
 * maps are keyless and free.) So every guard here is about one thing: making
 * it impossible for a bug, a bot, or a bored user to run up a Gemini bill.
 *
 * What a call goes through, in order:
 *
 *   1. Per-process call ceiling   - a hard stop against any loop or recursion
 *                                   that would keep calling within one request.
 *   2. Response cache             - identical prompt + model => reuse the
 *                                   stored answer, no upstream call at all.
 *   3. In-flight lock             - one outstanding call per user+feature, so
 *                                   a double-click or a retry storm can't
 *                                   multiply into concurrent billable calls.
 *   4. Rate limits                - per user, per minute / hour / day.
 *   5. Usage (spend) caps         - per user per day, and site-wide per day
 *                                   and per month. A hard stop, not a warning.
 *   6. Capped retries             - transient failures only (429 / 5xx /
 *                                   timeout), a fixed small number of attempts,
 *                                   exponential backoff, then give up.
 *   7. Usage accounting           - real token counts from the API response,
 *                                   costed and logged for every attempt.
 *   8. Abnormal-usage alerting    - notifies the owner when the day's spend or
 *                                   call volume crosses a threshold.
 *
 * Every limit is a named constant below and can be overridden from .env
 * without editing code.
 *
 * IMPORTANT - the price table: AI_PRICE_INPUT_PER_M / AI_PRICE_OUTPUT_PER_M
 * are what turn tokens into money everywhere in this system (the caps, the alerts, the owner
 * dashboard, the growth projection). It ships with Gemini Flash-class list
 * prices. VERIFY THEM against https://ai.google.dev/pricing for the model you
 * actually run and correct AI_PRICE_* in .env if they differ - every cost
 * figure this system shows is only as accurate as these two numbers.
 */

require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/gemini_client.php';
require_once __DIR__ . '/database.php';

/* =========================================================================
 * Configuration. Every value is .env-overridable; the defaults are sized for
 * a small storefront, deliberately on the conservative side - a limit that is
 * too low shows a clear "try again later" message, a limit that is too high
 * shows up on a credit card.
 * ===================================================================== */

/** Reads a numeric .env override, falling back to $default. */
function ai_cfg(string $key, float $default): float {
    $raw = getenv($key);
    return ($raw === false || $raw === '') ? $default : (float)$raw;
}

if (!defined('AI_MAX_CALLS_PER_REQUEST')) {
    // No single HTTP request legitimately needs more than a couple of AI
    // calls. This is the backstop against runaway loops/recursion (item 5):
    // it is checked before anything else and cannot be bypassed by a caller.
    define('AI_MAX_CALLS_PER_REQUEST', (int)ai_cfg('AI_MAX_CALLS_PER_REQUEST', 3));
}
if (!defined('AI_MAX_ATTEMPTS')) {
    // Total attempts per call, NOT extra retries. 2 = one initial try plus at
    // most one retry. Capped hard at 3 in ai_guarded_json() regardless of .env.
    define('AI_MAX_ATTEMPTS', (int)ai_cfg('AI_MAX_ATTEMPTS', 2));
}

// Per-user rate limits.
if (!defined('AI_RATE_PER_MIN'))   { define('AI_RATE_PER_MIN',   (int)ai_cfg('AI_RATE_PER_MIN', 3)); }
if (!defined('AI_RATE_PER_HOUR'))  { define('AI_RATE_PER_HOUR',  (int)ai_cfg('AI_RATE_PER_HOUR', 15)); }
if (!defined('AI_RATE_PER_DAY'))   { define('AI_RATE_PER_DAY',   (int)ai_cfg('AI_RATE_PER_DAY', 40)); }
// Site-wide call ceiling per day - a second line of defence if many accounts
// are each individually under their own limit (a bot signing up en masse).
if (!defined('AI_GLOBAL_CALLS_PER_DAY')) { define('AI_GLOBAL_CALLS_PER_DAY', (int)ai_cfg('AI_GLOBAL_CALLS_PER_DAY', 400)); }

// Spend caps, in USD. These are the numbers that actually bound the bill.
if (!defined('AI_USER_DAILY_USD'))     { define('AI_USER_DAILY_USD',     ai_cfg('AI_USER_DAILY_USD', 0.25)); }
if (!defined('AI_GLOBAL_DAILY_USD'))   { define('AI_GLOBAL_DAILY_USD',   ai_cfg('AI_GLOBAL_DAILY_USD', 3.00)); }
if (!defined('AI_GLOBAL_MONTHLY_USD')) { define('AI_GLOBAL_MONTHLY_USD', ai_cfg('AI_GLOBAL_MONTHLY_USD', 40.00)); }

// Alert when the day crosses this fraction of the daily cap (item 9).
if (!defined('AI_ALERT_AT_PCT')) { define('AI_ALERT_AT_PCT', ai_cfg('AI_ALERT_AT_PCT', 0.7)); }

// USD per 1,000,000 tokens. See the warning in the file header.
if (!defined('AI_PRICE_INPUT_PER_M'))  { define('AI_PRICE_INPUT_PER_M',  ai_cfg('AI_PRICE_INPUT_PER_M', 0.30)); }
if (!defined('AI_PRICE_OUTPUT_PER_M')) { define('AI_PRICE_OUTPUT_PER_M', ai_cfg('AI_PRICE_OUTPUT_PER_M', 2.50)); }

/**
 * Cheap model for work that does not need the main one (item 8). Defaults to
 * the SAME model as GEMINI_MODEL on purpose: pointing a feature at a model id
 * that doesn't exist on this account would break that feature outright, which
 * is a worse outcome than paying flash rates. Set GEMINI_MODEL_CHEAP in .env
 * (e.g. a flash-lite tier) to actually take the discount - ai_guarded_json()
 * falls back to the main model automatically if the cheap one is rejected.
 */
if (!defined('GEMINI_MODEL_CHEAP')) {
    define('GEMINI_MODEL_CHEAP', (string)(getenv('GEMINI_MODEL_CHEAP') ?: GEMINI_MODEL));
}

/**
 * Which model each feature gets, and how long its answers stay cacheable.
 *
 * cache_ttl of 0 means "never cache". A feature is only cacheable when the
 * same inputs genuinely deserve the same answer - see ai_guarded_json().
 */
function ai_feature_policy(string $feature): array {
    $policies = [
        // Reasoning over a 400-product catalog to produce three costed,
        // compatible builds. This is the one job that needs the main model,
        // and the only one that needs a large output budget.
        //
        // 24576, not 8192: on Gemini 3.x the model's internal reasoning is
        // billed to maxOutputTokens as well (gemini_generate_json() adds
        // thoughtsTokenCount into 'output_tokens' for exactly this reason).
        // At 8192 the three measured runs below all came back at 8174-8175
        // output tokens - i.e. pinned to the ceiling - so the JSON was cut
        // off mid-object and failed to parse, surfacing to the customer as
        // "the AI returned a response that could not be understood" even
        // though the call itself was a clean HTTP 200. Thinking alone can
        // take several thousand tokens before the first character of the
        // answer is emitted, so the budget has to cover reasoning + three
        // full builds, not just the builds.
        //
        // cache_ttl 7d, not 6h: the cache key hashes the full user prompt and
        // the prompt embeds the catalog, so the moment any price, stock level
        // or product row changes the key changes with it and the old entry can
        // never be served. A long TTL therefore cannot go stale here - it only
        // decides how long an *unchanged* catalog keeps reusing one answer.
        // On the free tier that is the difference between a demo that costs a
        // request and one that costs nothing.
        'builder'      => ['model' => GEMINI_MODEL,       'cache_ttl' => 604800, 'max_output' => 24576], // 7d
        // Vision: identify a product from a photo. Flash-class handles it, and
        // the answer is a name plus three sentences.
        'add_product'  => ['model' => GEMINI_MODEL,       'cache_ttl' => 604800, 'max_output' => 512],   // 7d - same photo, same answer
        // Short, formulaic customer-service prose. No reasoning depth needed.
        'review_reply' => ['model' => GEMINI_MODEL_CHEAP, 'cache_ttl' => 86400,  'max_output' => 400],
        // "Is the key working" - cheapest model, tiny answer, cached hard so
        // repeat loads of the status page cost nothing.
        'conn_test'    => ['model' => GEMINI_MODEL_CHEAP, 'cache_ttl' => 300,    'max_output' => 256],
    ];
    return $policies[$feature] ?? ['model' => GEMINI_MODEL, 'cache_ttl' => 0, 'max_output' => 2048];
}

/* =========================================================================
 * Storage
 * ===================================================================== */

/**
 * Creates the two tables this module owns. Same additive
 * "CREATE TABLE IF NOT EXISTS at first use" bootstrap the rest of this
 * codebase uses (see aib_ensure_tables()); no existing table is touched.
 */
function ai_guard_ensure_tables(PDO $pdo): void {
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage_log (
        id BIGINT NOT NULL AUTO_INCREMENT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_id INT(11) NULL,
        user_role VARCHAR(32) NULL,
        feature VARCHAR(40) NOT NULL,
        model VARCHAR(80) NOT NULL,
        prompt_tokens INT(11) NOT NULL DEFAULT 0,
        output_tokens INT(11) NOT NULL DEFAULT 0,
        est_cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
        attempts TINYINT(4) NOT NULL DEFAULT 1,
        outcome VARCHAR(24) NOT NULL,
        detail VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_user_created (user_id, created_at),
        KEY idx_feature_created (feature, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_response_cache (
        cache_key CHAR(64) NOT NULL,
        feature VARCHAR(40) NOT NULL,
        model VARCHAR(80) NOT NULL,
        response_json MEDIUMTEXT NOT NULL,
        hits INT(11) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (cache_key),
        KEY idx_expires (expires_at),
        KEY idx_feature (feature)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/** Cost in USD for a token count, from the configured price table. */
function ai_cost_usd(int $promptTokens, int $outputTokens): float {
    return ($promptTokens / 1000000) * AI_PRICE_INPUT_PER_M
         + ($outputTokens / 1000000) * AI_PRICE_OUTPUT_PER_M;
}

/** Best-effort client IP, for spotting a single abusive source in the log. */
function ai_client_ip(): ?string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) ? substr($ip, 0, 45) : null;
}

function ai_log_usage(PDO $pdo, array $row): void {
    try {
        ai_guard_ensure_tables($pdo);
        $stmt = $pdo->prepare("INSERT INTO ai_usage_log
            (user_id, user_role, feature, model, prompt_tokens, output_tokens, est_cost_usd, attempts, outcome, detail, ip)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $row['user_id'] ?? null,
            $row['user_role'] ?? null,
            $row['feature'],
            $row['model'],
            (int)($row['prompt_tokens'] ?? 0),
            (int)($row['output_tokens'] ?? 0),
            round((float)($row['est_cost_usd'] ?? 0), 6),
            (int)($row['attempts'] ?? 1),
            $row['outcome'],
            isset($row['detail']) ? substr((string)$row['detail'], 0, 255) : null,
            ai_client_ip(),
        ]);
    } catch (Throwable $e) {
        // Accounting must never take down the feature it is measuring.
        error_log('ai_log_usage failed: ' . $e->getMessage());
    }
}

/* =========================================================================
 * Limits
 * ===================================================================== */

/**
 * Current usage snapshot. Only rows that actually reached the API ('ok' and
 * 'error') count against limits - a blocked or cache-served request cost
 * nothing, so charging it against the user's allowance would be wrong.
 */
function ai_usage_snapshot(PDO $pdo, ?int $userId): array {
    ai_guard_ensure_tables($pdo);
    $billable = "outcome IN ('ok','error')";

    $q = function (string $where, array $args) use ($pdo, $billable) {
        $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(est_cost_usd),0) s FROM ai_usage_log WHERE $billable AND $where");
        $stmt->execute($args);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 's' => 0];
        return ['calls' => (int)$r['c'], 'usd' => (float)$r['s']];
    };

    return [
        'user_minute' => $userId ? $q("user_id = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)", [$userId]) : ['calls' => 0, 'usd' => 0.0],
        'user_hour'   => $userId ? $q("user_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)", [$userId])   : ['calls' => 0, 'usd' => 0.0],
        'user_day'    => $userId ? $q("user_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)", [$userId])    : ['calls' => 0, 'usd' => 0.0],
        'global_day'  => $q("created_at >= (NOW() - INTERVAL 1 DAY)", []),
        'global_month'=> $q("created_at >= (NOW() - INTERVAL 30 DAY)", []),
    ];
}

/**
 * Returns null when the call may proceed, or a customer-safe refusal message.
 * Order matters: the site-wide caps are checked last so an individual abuser
 * is told they are the problem before everyone else is.
 */
function ai_check_limits(array $u): ?string {
    if ($u['global_month']['usd'] >= AI_GLOBAL_MONTHLY_USD) {
        return 'The AI assistant has reached its monthly usage budget for this store and is paused until it resets.';
    }
    if ($u['global_day']['usd'] >= AI_GLOBAL_DAILY_USD) {
        return 'The AI assistant has reached its daily usage budget for this store. Please try again tomorrow.';
    }
    if ($u['global_day']['calls'] >= AI_GLOBAL_CALLS_PER_DAY) {
        return 'The AI assistant has reached its daily request limit for this store. Please try again tomorrow.';
    }
    if ($u['user_day']['usd'] >= AI_USER_DAILY_USD) {
        return 'You have used your AI allowance for today. It resets in 24 hours.';
    }
    if ($u['user_day']['calls'] >= AI_RATE_PER_DAY) {
        return 'You have reached the daily limit of ' . AI_RATE_PER_DAY . ' AI requests. Please try again tomorrow.';
    }
    if ($u['user_hour']['calls'] >= AI_RATE_PER_HOUR) {
        return 'You have made a lot of AI requests in the last hour. Please try again later.';
    }
    if ($u['user_minute']['calls'] >= AI_RATE_PER_MIN) {
        return 'Please wait a moment before generating another AI result.';
    }
    return null;
}

/**
 * Item 9. Fires at most once per day per threshold, by checking whether an
 * alert notification for today already exists - notifySuperAdmins() writes one
 * row per owner, so the guard has to be on the message itself.
 */
function ai_check_abnormal_usage(PDO $pdo, array $u): void {
    try {
        $triggers = [];
        if (AI_GLOBAL_DAILY_USD > 0 && $u['global_day']['usd'] >= AI_GLOBAL_DAILY_USD * AI_ALERT_AT_PCT) {
            $triggers[] = sprintf('spend is $%.4f of the $%.2f daily cap', $u['global_day']['usd'], AI_GLOBAL_DAILY_USD);
        }
        if (AI_GLOBAL_CALLS_PER_DAY > 0 && $u['global_day']['calls'] >= AI_GLOBAL_CALLS_PER_DAY * AI_ALERT_AT_PCT) {
            $triggers[] = sprintf('%d of %d daily requests used', $u['global_day']['calls'], AI_GLOBAL_CALLS_PER_DAY);
        }
        if (AI_GLOBAL_MONTHLY_USD > 0 && $u['global_month']['usd'] >= AI_GLOBAL_MONTHLY_USD * AI_ALERT_AT_PCT) {
            $triggers[] = sprintf('30-day spend is $%.2f of the $%.2f cap', $u['global_month']['usd'], AI_GLOBAL_MONTHLY_USD);
        }
        if (empty($triggers)) { return; }

        $title = 'AI usage is unusually high';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE title = ? AND created_at >= CURDATE()");
        $stmt->execute([$title]);
        if ((int)$stmt->fetchColumn() > 0) { return; }   // already alerted today

        if (function_exists('notifySuperAdmins')) {
            notifySuperAdmins(
                $title,
                'TechWorld AI usage crossed its alert threshold: ' . implode('; ', $triggers)
                . '. Requests are blocked automatically once a cap is reached. Review Owner Panel > AI Usage.',
                'warning'
            );
        }
        error_log('AI USAGE ALERT: ' . implode('; ', $triggers));
    } catch (Throwable $e) {
        error_log('ai_check_abnormal_usage failed: ' . $e->getMessage());
    }
}

/* =========================================================================
 * The gate
 * ===================================================================== */

/** Transient failures are worth one more try; everything else is not. */
function ai_is_retryable(array $result): bool {
    $code = (int)($result['http_code'] ?? 0);
    if (!empty($result['transport_error'])) { return true; }   // timeout / connection reset
    return $code === 429 || $code === 500 || $code === 502 || $code === 503 || $code === 504;
}

/**
 * The only function the rest of the app should call to reach Gemini.
 *
 * $feature  - key in ai_feature_policy(); decides model and cache lifetime.
 * $opts     - ['timeout' => int, 'image' => ['mime_type'=>..,'data'=>..],
 *              'cache' => bool (default: policy), 'cache_salt' => string]
 *
 * Returns the same ['success','data','error'] contract gemini_generate_json()
 * always returned, plus 'cached' and 'blocked' flags, so existing call sites
 * keep working unchanged.
 */
function ai_guarded_json(string $feature, string $systemPrompt, string $userPrompt, array $schema, array $opts = []): array {
    static $callsThisRequest = 0;

    // --- 1. Infinite-loop backstop (item 5) ---------------------------------
    // Checked first and unconditionally. Nothing a caller passes can raise it,
    // so no loop, recursion or retry path can bill more than this per request.
    if ($callsThisRequest >= AI_MAX_CALLS_PER_REQUEST) {
        error_log('ai_guarded_json: per-request call ceiling hit for feature=' . $feature);
        return ['success' => false, 'data' => null, 'blocked' => true,
                'error' => 'Too many AI requests were attempted while handling this page. Please try again.'];
    }
    $callsThisRequest++;

    if (!ai_is_configured()) {
        return ['success' => false, 'data' => null, 'error' => 'The AI service is not configured on this server.'];
    }

    $policy   = ai_feature_policy($feature);
    $model    = $policy['model'];
    $timeout  = (int)($opts['timeout'] ?? 30);
    $image    = $opts['image'] ?? null;
    $cacheTtl = array_key_exists('cache', $opts) && !$opts['cache'] ? 0 : (int)$policy['cache_ttl'];
    $maxOut   = (int)($opts['max_output'] ?? $policy['max_output']);

    $pdo    = getConnection();
    $userId = function_exists('getUserId') ? getUserId() : null;
    $role   = function_exists('getUserRole') ? getUserRole() : null;

    // --- 2. Cache (item 6) --------------------------------------------------
    // The key covers everything that could change the answer, including the
    // image bytes, so a hit is only ever an identical question.
    $cacheKey = hash('sha256', implode("\0", [
        $feature, $model, $systemPrompt, $userPrompt,
        json_encode($schema), (string)($opts['cache_salt'] ?? ''),
        $image ? hash('sha256', (string)$image['data']) : '',
    ]));

    if ($cacheTtl > 0) {
        try {
            ai_guard_ensure_tables($pdo);
            $stmt = $pdo->prepare("SELECT response_json FROM ai_response_cache WHERE cache_key = ? AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$cacheKey]);
            $hit = $stmt->fetchColumn();
            if ($hit !== false) {
                $pdo->prepare("UPDATE ai_response_cache SET hits = hits + 1 WHERE cache_key = ?")->execute([$cacheKey]);
                ai_log_usage($pdo, ['user_id' => $userId, 'user_role' => $role, 'feature' => $feature,
                                    'model' => $model, 'outcome' => 'cache', 'attempts' => 0]);
                $decoded = json_decode((string)$hit, true);
                if (is_array($decoded)) {
                    return ['success' => true, 'data' => $decoded, 'error' => null, 'cached' => true];
                }
            }
        } catch (Throwable $e) {
            error_log('ai cache read failed: ' . $e->getMessage());  // fall through to a live call
        }
    }

    // --- 3. In-flight lock (items 1 and 5) ----------------------------------
    // Stops a double-submit or a client retry loop turning into N concurrent
    // billable calls for the same user. Session-based: the same browser can
    // only have one AI call of a given feature outstanding at a time.
    $lockKey = 'ai_inflight_' . $feature;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $since = $_SESSION[$lockKey] ?? 0;
        // Stale locks self-clear: a fatal mid-call would otherwise lock the
        // feature out for this session permanently.
        if ($since && (time() - (int)$since) < max(10, $timeout + 5)) {
            ai_log_usage($pdo, ['user_id' => $userId, 'user_role' => $role, 'feature' => $feature,
                                'model' => $model, 'outcome' => 'blocked_inflight', 'attempts' => 0]);
            return ['success' => false, 'data' => null, 'blocked' => true,
                    'error' => 'An AI request is already running. Please wait for it to finish.'];
        }
        $_SESSION[$lockKey] = time();
    }

    try {
        // --- 4 & 5. Rate limits and spend caps (items 2 and 3) --------------
        $usage = ai_usage_snapshot($pdo, $userId);
        $refusal = ai_check_limits($usage);
        if ($refusal !== null) {
            ai_log_usage($pdo, ['user_id' => $userId, 'user_role' => $role, 'feature' => $feature,
                                'model' => $model, 'outcome' => 'blocked_limit', 'detail' => $refusal, 'attempts' => 0]);
            ai_check_abnormal_usage($pdo, $usage);
            return ['success' => false, 'data' => null, 'blocked' => true, 'error' => $refusal];
        }

        // --- 6. Capped retries (item 4) -------------------------------------
        // Bounded by a constant, min 1 / max 3 attempts no matter what .env
        // says, and only for transient upstream failures.
        $maxAttempts = max(1, min(3, AI_MAX_ATTEMPTS));
        $result = ['success' => false, 'data' => null, 'error' => 'The AI service is unavailable.'];
        $attempt = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = gemini_generate_json($systemPrompt, $userPrompt, $schema, $timeout, $image, $model, $maxOut);

            // A cheap model that this account doesn't have access to would
            // otherwise silently disable the feature. Fall back to the main
            // model once, and say so in the log.
            if (!$result['success'] && $model !== GEMINI_MODEL
                && in_array((int)($result['http_code'] ?? 0), [400, 404], true)) {
                error_log("ai_guarded_json: model '$model' rejected for feature=$feature; falling back to " . GEMINI_MODEL);
                $model = GEMINI_MODEL;
                $result = gemini_generate_json($systemPrompt, $userPrompt, $schema, $timeout, $image, $model, $maxOut);
            }

            if ($result['success'] || !ai_is_retryable($result) || $attempt >= $maxAttempts) { break; }
            // Exponential backoff, and never long enough to blow the request
            // timeout: 1s, then 2s.
            usleep((int)(1000000 * (2 ** ($attempt - 1))));
        }

        // --- 7. Accounting --------------------------------------------------
        $promptTokens = (int)($result['prompt_tokens'] ?? 0);
        $outputTokens = (int)($result['output_tokens'] ?? 0);
        $cost = ai_cost_usd($promptTokens, $outputTokens);

        ai_log_usage($pdo, [
            'user_id' => $userId, 'user_role' => $role, 'feature' => $feature, 'model' => $model,
            'prompt_tokens' => $promptTokens, 'output_tokens' => $outputTokens, 'est_cost_usd' => $cost,
            'attempts' => $attempt > $maxAttempts ? $maxAttempts : $attempt,
            'outcome' => $result['success'] ? 'ok' : 'error',
            'detail' => $result['success'] ? null : ($result['error'] ?? null),
        ]);

        // --- 8. Store in cache ----------------------------------------------
        if ($result['success'] && $cacheTtl > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO ai_response_cache (cache_key, feature, model, response_json, expires_at)
                    VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                    ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), expires_at = VALUES(expires_at)");
                $stmt->execute([$cacheKey, $feature, $model, json_encode($result['data']), $cacheTtl]);
                // Opportunistic sweep; cheap, indexed, and keeps the table from
                // growing without a cron job to prune it.
                $pdo->exec("DELETE FROM ai_response_cache WHERE expires_at < (NOW() - INTERVAL 1 DAY)");
            } catch (Throwable $e) {
                error_log('ai cache write failed: ' . $e->getMessage());
            }
        }

        // --- 9. Alerting ----------------------------------------------------
        $usage['global_day']['usd']   += $cost;
        $usage['global_day']['calls'] += 1;
        $usage['global_month']['usd'] += $cost;
        ai_check_abnormal_usage($pdo, $usage);

        return $result;
    } finally {
        // Always released, including on an exception, so a failure can never
        // wedge the feature shut for this session.
        if (session_status() === PHP_SESSION_ACTIVE) { unset($_SESSION[$lockKey]); }
    }
}

/* =========================================================================
 * Reporting - backs the Owner Panel's AI Usage page.
 * ===================================================================== */

/** Totals for the dashboard: today, 30 days, and the top users/features. */
function ai_usage_report(PDO $pdo): array {
    ai_guard_ensure_tables($pdo);
    $billable = "outcome IN ('ok','error')";

    $one = function (string $sql, array $args = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $all = function (string $sql, array $args = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    return [
        'today' => $one("SELECT COUNT(*) calls, COALESCE(SUM(est_cost_usd),0) usd,
                                COALESCE(SUM(prompt_tokens),0) tin, COALESCE(SUM(output_tokens),0) tout
                         FROM ai_usage_log WHERE $billable AND created_at >= CURDATE()"),
        'month' => $one("SELECT COUNT(*) calls, COALESCE(SUM(est_cost_usd),0) usd
                         FROM ai_usage_log WHERE $billable AND created_at >= (NOW() - INTERVAL 30 DAY)"),
        'cache' => $one("SELECT COUNT(*) served FROM ai_usage_log WHERE outcome = 'cache' AND created_at >= (NOW() - INTERVAL 30 DAY)"),
        'blocked' => $one("SELECT COUNT(*) blocked FROM ai_usage_log WHERE outcome LIKE 'blocked%' AND created_at >= (NOW() - INTERVAL 30 DAY)"),
        'by_feature' => $all("SELECT feature, COUNT(*) calls, COALESCE(SUM(est_cost_usd),0) usd
                              FROM ai_usage_log WHERE $billable AND created_at >= (NOW() - INTERVAL 30 DAY)
                              GROUP BY feature ORDER BY usd DESC"),
        'by_day' => $all("SELECT DATE(created_at) d, COUNT(*) calls, COALESCE(SUM(est_cost_usd),0) usd
                          FROM ai_usage_log WHERE $billable AND created_at >= (NOW() - INTERVAL 14 DAY)
                          GROUP BY DATE(created_at) ORDER BY d DESC"),
        'top_users' => $all("SELECT l.user_id, COALESCE(CONCAT(u.firstname,' ',u.lastname), CONCAT('User #', l.user_id)) name,
                                    COUNT(*) calls, COALESCE(SUM(l.est_cost_usd),0) usd
                             FROM ai_usage_log l LEFT JOIN users u ON u.id = l.user_id
                             WHERE $billable AND l.created_at >= (NOW() - INTERVAL 30 DAY)
                             GROUP BY l.user_id, name ORDER BY usd DESC LIMIT 10"),
        'limits' => [
            'user_per_min' => AI_RATE_PER_MIN, 'user_per_hour' => AI_RATE_PER_HOUR, 'user_per_day' => AI_RATE_PER_DAY,
            'global_calls_day' => AI_GLOBAL_CALLS_PER_DAY,
            'user_usd_day' => AI_USER_DAILY_USD, 'global_usd_day' => AI_GLOBAL_DAILY_USD,
            'global_usd_month' => AI_GLOBAL_MONTHLY_USD,
            'price_in' => AI_PRICE_INPUT_PER_M, 'price_out' => AI_PRICE_OUTPUT_PER_M,
            'max_attempts' => AI_MAX_ATTEMPTS,
        ],
    ];
}

/**
 * Item 11. Projects the monthly bill as the user base grows, from this
 * install's own measured cost per call (falling back to a modelled cost when
 * there isn't enough history yet).
 *
 * The assumption that drives everything: what fraction of customers use the
 * AI Builder in a month, and how often. Those are $adoption and $callsEach -
 * deliberately explicit rather than hidden in the maths.
 */
function ai_cost_projection(PDO $pdo, array $userCounts = [100, 500, 1000, 5000, 10000], float $adoption = 0.25, float $callsEach = 2.0): array {
    ai_guard_ensure_tables($pdo);

    $stmt = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(est_cost_usd),0) s FROM ai_usage_log
                         WHERE outcome = 'ok' AND created_at >= (NOW() - INTERVAL 30 DAY)");
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 's' => 0];

    $measured = ((int)$r['c'] >= 5) ? ((float)$r['s'] / (int)$r['c']) : null;
    // Fallback model: the AI Builder's own shape - the full catalog as input
    // (~20k tokens) plus three builds of prose out (~3k tokens).
    $modelled = ai_cost_usd(20000, 3000);
    $perCall  = $measured ?? $modelled;

    // Cache hits cost nothing, so the effective rate is what actually bills.
    $hitRate = 0.0;
    $c = $pdo->query("SELECT
            SUM(outcome='cache') hits, SUM(outcome IN ('ok','cache')) total
            FROM ai_usage_log WHERE created_at >= (NOW() - INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC);
    if ($c && (int)$c['total'] > 0) { $hitRate = (int)$c['hits'] / (int)$c['total']; }

    $rows = [];
    foreach ($userCounts as $n) {
        $calls = $n * $adoption * $callsEach;
        $billableCalls = $calls * (1 - $hitRate);
        $uncapped = $billableCalls * $perCall;
        $rows[] = [
            'users' => $n,
            'calls_per_month' => (int)round($calls),
            'billable_calls' => (int)round($billableCalls),
            'uncapped_usd' => round($uncapped, 2),
            // What the caps in this file actually allow you to be billed,
            // whatever the traffic. This is the number that matters.
            'capped_usd' => round(min($uncapped, AI_GLOBAL_MONTHLY_USD), 2),
        ];
    }

    return [
        'per_call_usd' => round($perCall, 6),
        'per_call_source' => $measured !== null ? 'measured from this install' : 'modelled (no usage history yet)',
        'cache_hit_rate' => round($hitRate, 3),
        'adoption' => $adoption,
        'calls_each' => $callsEach,
        'monthly_cap_usd' => AI_GLOBAL_MONTHLY_USD,
        'rows' => $rows,
    ];
}
