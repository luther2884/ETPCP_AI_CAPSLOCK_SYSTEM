<?php
// api/admin_lock.php - Admin inactivity auto-lock endpoint.
//
// The lock state lives server-side ($_SESSION['admin_locked']), not just in
// client JS - a JS-only lock would be trivially bypassed by opening a new
// tab or reloading the page while the admin is away. header.php checks this
// same flag on every admin page load and renders the overlay pre-locked
// when it's set, so the lock actually holds across tabs/reloads, not just
// within the one tab that was idle.
//
// Password verification reuses the exact same check admin/login.php uses
// (password_verify against the users.password hash) - re-fetched fresh
// from the DB by the session's own user_id, never trusting anything about
// identity from the request itself.
//
// NOTE on Admin Mode: super-admin/switch_to_admin.php keeps the SUPER
// ADMIN's user_id in the session and only rewrites user_role to 'admin'.
// So in Admin Mode the password checked here is the Super Admin's own, which
// is correct - that is who is at the keyboard - but is not what the panel
// looks like it is asking for. The failure message below names the account so
// a rejected password reads as "wrong account" rather than "lock is broken".
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'lock':
        // Fired either by the client's inactivity timer elapsing or by the
        // admin pressing the sidebar lock button (see admin/includes/
        // navigation.php). Only the wording on the overlay differs, so the
        // reason is stored alongside the flag rather than handled separately -
        // a manual lock holds across tabs and reloads exactly like an idle one.
        $reason = ($_GET['reason'] ?? $_POST['reason'] ?? 'idle') === 'manual' ? 'manual' : 'idle';
        $_SESSION['admin_locked'] = true;
        $_SESSION['admin_lock_reason'] = $reason;
        echo json_encode(['success' => true, 'locked' => true, 'reason' => $reason]);
        break;

    case 'status':
        // Polled periodically so a tab that's been sitting open (not
        // reloaded) still picks up a lock triggered from another tab.
        echo json_encode([
            'success' => true,
            'locked' => !empty($_SESSION['admin_locked']),
            'reason' => ($_SESSION['admin_lock_reason'] ?? 'idle'),
        ]);
        break;

    case 'unlock':
        $password = (string)($_POST['password'] ?? '');
        if ($password === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter your password.']);
            break;
        }
        try {
            // verifyCurrentUserPassword() handles both kinds of account that
            // reach this screen: normal admins (bcrypt hash in users.password)
            // and the Super Admin, whose password is the SUPER_ADMIN_PASSWORD
            // constant and whose users row hash is NOT kept in sync with it.
            // Checking the hash alone - which is what this did - meant the
            // Super Admin could never unlock, correct password or not.
            $pdo = getConnection();
            $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([getUserId()]);
            $account = ['email' => (string)$stmt->fetchColumn()];
            if ($account['email'] === '' && isConstantSuperAdminSession()) {
                $account['email'] = SUPER_ADMIN_EMAIL;
            }

            if (verifyCurrentUserPassword($password)) {
                $_SESSION['admin_locked'] = false;
                unset($_SESSION['admin_lock_reason']);
                echo json_encode(['success' => true]);
            } else {
                // Naming the account is safe - it is the session's own - and
                // it is the difference between a user retrying the same wrong
                // password and reaching for the right one.
                $who = $account['email'] ?? '';
                $msg = $who !== ''
                    ? ('Incorrect password for ' . $who . '.')
                    : 'Incorrect password.';
                if (!empty($_SESSION['sa_mode'])) {
                    $msg .= ' You are in Admin Mode - use your Super Admin password, not the admin account\'s.';
                } else {
                    $msg .= ' Please try again.';
                }
                echo json_encode(['success' => false, 'message' => $msg]);
            }
        } catch (PDOException $e) {
            error_log('Admin unlock error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
