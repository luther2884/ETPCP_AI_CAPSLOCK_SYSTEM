<?php
// api/change_password.php - Change the signed-in user's own password.
//
// The Change Password form in customer/settings.php used to be a stub: it
// confirmed, showed "Sample only. Wire to backend to change password." and
// threw the three fields away. This is the backend it was waiting for.
//
// Deliberately scoped to the CALLER's own account - the user id comes from
// the session, never from the payload, so this endpoint cannot be pointed at
// someone else's account no matter what is posted.
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// JSON body (what settings.php sends) or a plain form post, so this still
// works from a normal <form> if anything ever posts to it directly.
//
// Read BEFORE the CSRF check, because check_csrf() only ever looks at $_POST -
// which PHP leaves empty for a JSON body. The token is copied across so
// check_csrf() stays the one place that decides whether a token is valid,
// rather than this file growing a second comparison that could drift from it.
$payload = [];
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) { $payload = []; }
    if (isset($payload['csrf_token']) && is_string($payload['csrf_token'])) {
        $_POST['csrf_token'] = $payload['csrf_token'];
    }
} else {
    $payload = $_POST;
}

// Strict: unlike the address endpoints next door, this one changes a
// credential, so the local-dev bypass in check_csrf(false) is not offered.
if (!check_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
    exit;
}

$userId = getUserId();

$current = (string)($payload['current_password'] ?? '');
$new     = (string)($payload['new_password'] ?? '');
$confirm = (string)($payload['confirm_password'] ?? '');

// ---------------------------------------------------------------------------
// Throttle. Current-password verification is an oracle: without a limit, a
// hijacked session (or a borrowed unlocked laptop) could brute-force the old
// password here at full speed. Session-scoped rather than per-account on
// purpose - it is the session doing the guessing, and it keeps a third party
// from locking a real customer out by burning their attempts.
// ---------------------------------------------------------------------------
const PW_CHANGE_MAX_ATTEMPTS = 5;
const PW_CHANGE_WINDOW       = 900; // 15 minutes

$now = time();
if (!isset($_SESSION['pw_change_attempts']) || ($_SESSION['pw_change_attempts_reset'] ?? 0) < $now) {
    $_SESSION['pw_change_attempts'] = 0;
    $_SESSION['pw_change_attempts_reset'] = $now + PW_CHANGE_WINDOW;
}
if ($_SESSION['pw_change_attempts'] >= PW_CHANGE_MAX_ATTEMPTS) {
    $waitMinutes = max(1, (int)ceil((($_SESSION['pw_change_attempts_reset'] ?? $now) - $now) / 60));
    http_response_code(429);
    logAuditFailure('password_change_throttled', 'users', $userId, 'Too many incorrect current-password attempts');
    echo json_encode([
        'success' => false,
        'message' => "Too many incorrect attempts. Please wait about {$waitMinutes} minute(s) and try again."
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Validation. Same floor as the reset-password flow (public/guest/
// reset_password.php) so a password that is acceptable there is acceptable
// here - one rule, not two that can drift apart.
// ---------------------------------------------------------------------------
$error = null;
if ($current === '' || $new === '' || $confirm === '') {
    $error = 'Please fill in all three password fields.';
} elseif (strlen($new) < 8) {
    $error = 'Your new password must be at least 8 characters.';
} elseif ($new !== $confirm) {
    $error = 'The new passwords do not match.';
} elseif ($new === $current) {
    $error = 'Your new password must be different from your current one.';
}

if ($error !== null) {
    // Not a failed credential check, so it does not burn a throttle attempt -
    // a typo in the confirm field should not count toward a brute-force limit.
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

try {
    $pdo = getConnection();

    $stmt = $pdo->prepare('SELECT password, email, firstname, lastname FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Account not found. Please sign in again.']);
        exit;
    }

    if (!password_verify($current, (string)$user['password'])) {
        $_SESSION['pw_change_attempts']++;
        $remaining = PW_CHANGE_MAX_ATTEMPTS - (int)$_SESSION['pw_change_attempts'];
        // Logged as a failure so a run of these is visible in the audit trail -
        // with no password material in it, obviously.
        logAuditFailure('password_change_failed', 'users', $userId, 'Current password did not match');
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Your current password is incorrect.'
                . ($remaining > 0 && $remaining <= 2 ? " {$remaining} attempt(s) left before this is locked for a while." : '')
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $userId]);

    // Any outstanding "forgot password" link is now stale - leaving it live
    // would let whoever triggered it set the password again afterwards.
    // Same cleanup reset_password.php does; table is optional on old installs.
    try {
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    } catch (Throwable $e) { /* table may not exist yet */ }

    $pdo->commit();

    // The guessing budget is spent on the OLD password; a successful change
    // makes it meaningless.
    unset($_SESSION['pw_change_attempts'], $_SESSION['pw_change_attempts_reset']);

    // New session id, same session data: if anyone else had a copy of this
    // session cookie, changing the password should cost them their foothold.
    // The caller stays signed in - only the identifier moves.
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }

    // Never record the passwords themselves, only that the change happened.
    logAudit('password_changed', 'users', $userId, null, ['changed_at' => date('Y-m-d H:i:s')]);

    // Tell the account owner out of band. If this was not them, this message
    // is how they find out.
    try {
        createNotification(
            $userId,
            'security',
            'Your password was changed',
            'The password on your TechWorld account was changed on ' . date('F j, Y \a\t g:i A') . '. If this was not you, reset your password immediately and contact support.',
            null,
            'high'
        );
    } catch (Throwable $e) { error_log('Password change notification failed: ' . $e->getMessage()); }

    try {
        if (!empty($user['email'])) {
            $name = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
            sendEmail(
                $user['email'],
                $name !== '' ? $name : $user['email'],
                'Your TechWorld password was changed',
                '<p>Hi ' . htmlspecialchars($name !== '' ? $name : 'there') . ',</p>'
                . '<p>The password on your TechWorld account was changed on <strong>' . date('F j, Y \a\t g:i A') . '</strong>.</p>'
                . '<p>If you made this change, no action is needed. If you did not, reset your password immediately and contact us.</p>'
            );
        }
    } catch (Throwable $e) { /* email is best-effort, never fails the change */ }

    echo json_encode(['success' => true, 'message' => 'Your password has been updated.']);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Change password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not change your password right now. Please try again.']);
}
