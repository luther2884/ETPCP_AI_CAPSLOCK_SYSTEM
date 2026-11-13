<?php
declare(strict_types=1);

/**
 * config/rate_limit.php - a small, general-purpose request throttle for
 * endpoints that are cheap to call and expensive to be spammed on.
 *
 * config/ai_guard.php has its own, richer limiter because AI calls are
 * metered in money and have to be accounted per token. This one is for
 * everything else: public form endpoints, search, and anything a bot could
 * hammer. It counts hits in a table and refuses past a threshold.
 *
 * Deliberately generous by default. The job here is to stop a flood, not to
 * get in a real customer's way - a limit a human can reach during normal use
 * is a bug, not a security control.
 *
 * Usage, as the first thing an endpoint does after loading config:
 *
 *   require_once __DIR__ . '/../../../config/rate_limit.php';
 *   rl_guard('support_write', 20, 600);   // 20 requests per 10 minutes per IP
 *
 * rl_guard() emits a 429 JSON body and exits when the limit is exceeded.
 * rl_check() is the same test without the exit, for callers that want to
 * shape their own response.
 */

require_once __DIR__ . '/database.php';

/** Identity a limit is counted against: the logged-in user if there is one, else the IP. */
function rl_identity(): string {
    if (!empty($_SESSION['user_id'])) { return 'u:' . (int)$_SESSION['user_id']; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return 'ip:' . substr((string)$ip, 0, 45);
}

function rl_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_hits (
        id BIGINT NOT NULL AUTO_INCREMENT,
        bucket VARCHAR(40) NOT NULL,
        identity VARCHAR(60) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_bucket_identity_created (bucket, identity, created_at),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * Records this hit and reports whether the caller is over $max in the last
 * $windowSeconds.
 *
 * Fails OPEN: if the limiter itself errors (table missing, DB hiccup), the
 * request is allowed through. A throttle that can take the site down when it
 * breaks is worse than the flood it prevents - and unlike the AI caps, going
 * over here costs nothing but database time.
 *
 * Returns ['allowed' => bool, 'used' => int, 'max' => int, 'retry_after' => int].
 */
function rl_check(string $bucket, int $max, int $windowSeconds = 60): array {
    $open = ['allowed' => true, 'used' => 0, 'max' => $max, 'retry_after' => 0];
    try {
        $pdo = getConnection();
        rl_ensure_table($pdo);
        $identity = rl_identity();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_hits
                               WHERE bucket = ? AND identity = ? AND created_at >= (NOW() - INTERVAL ? SECOND)");
        $stmt->execute([$bucket, $identity, $windowSeconds]);
        $used = (int)$stmt->fetchColumn();

        // The hit is recorded either way, so a client that keeps hammering
        // while blocked keeps its own window full rather than sliding out of it.
        $pdo->prepare("INSERT INTO rate_limit_hits (bucket, identity) VALUES (?, ?)")->execute([$bucket, $identity]);

        // Opportunistic prune, ~1 request in 50, so the table stays small
        // without needing a cron job.
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        }

        return ['allowed' => $used < $max, 'used' => $used, 'max' => $max, 'retry_after' => $windowSeconds];
    } catch (Throwable $e) {
        error_log('rate_limit check failed (allowing request): ' . $e->getMessage());
        return $open;
    }
}

/**
 * rl_check() plus the standard refusal: HTTP 429, Retry-After, a JSON body,
 * and exit. For endpoints that already speak JSON.
 */
function rl_guard(string $bucket, int $max, int $windowSeconds = 60, string $message = 'Too many requests. Please slow down and try again shortly.'): void {
    $r = rl_check($bucket, $max, $windowSeconds);
    if ($r['allowed']) { return; }

    if (!headers_sent()) {
        http_response_code(429);
        header('Retry-After: ' . (int)$r['retry_after']);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'rate_limited' => true, 'message' => $message]);
    exit;
}
