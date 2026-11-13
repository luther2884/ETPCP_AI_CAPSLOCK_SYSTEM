<?php
// api/owner_lock.php - Owner (Super Admin) panel inactivity auto-lock endpoint.
//
// This is the sibling of api/admin_lock.php, and deliberately a SEPARATE file
// rather than a role flag on that one. The two panels do not share a session:
// admin/ runs in the TECHWRLD_ADM cookie bucket and super-admin/ in
// TECHWRLD_SA (see the session_name() routing at the top of
// config/functions.php). A single endpoint would have to pick one bucket to
// session_start(), and whichever it picked would silently read and write the
// wrong panel's state for the other. Two endpoints, two buckets, no guessing.
//
// The session_name() call below is explicit rather than left to that routing,
// for the same reason admin_lock.php's is: the routing resolves api/ requests
// through HTTP_REFERER, and a request with no Referer (privacy settings, some
// proxies, a `fetch` from a page served with a strict referrer policy) would
// fall through to the customer bucket and fail the isSuperAdmin() check below
// with a 403 that looks exactly like a broken lock.
//
// The lock state lives server-side ($_SESSION['owner_locked']), not just in
// client JS - a JS-only lock would be trivially bypassed by opening a new tab
// or reloading the page while the owner is away. super-admin/includes/
// header.php checks this same flag on every owner page load and renders the
// overlay pre-locked when it is set, so the lock holds across tabs and
// reloads, not just within the one tab that happened to be idle.
//
// WHY NOT $_SESSION['admin_locked'] IN THIS BUCKET: Admin Mode. A Super Admin
// can drive the admin panel via super-admin/switch_to_admin.php, which writes
// a TECHWRLD_ADM session for the same person. Both buckets can therefore be
// live in one browser at once. Keeping the keys distinct means the two locks
// are genuinely independent - locking the Owner panel does not strand the
// admin tab the same person is working in, and vice versa - and it keeps a
// future reader from assuming one flag governs both.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

// isSuperAdmin() covers both role spellings this project uses ('super-admin'
// and 'owner') - see config/functions.php. An admin or a customer who reaches
// this URL gets the same 403 as a logged-out request.
if (!isLoggedIn() || !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Owner access required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'lock':
        // Fired either by the client's inactivity timer elapsing or by the
        // owner pressing the sidebar lock button (see super-admin/includes/
        // navigation.php). Only the wording on the overlay differs, so the
        // reason is stored alongside the flag rather than handled separately -
        // a manual lock holds across tabs and reloads exactly like an idle one.
        $reason = ($_GET['reason'] ?? $_POST['reason'] ?? 'idle') === 'manual' ? 'manual' : 'idle';
        $_SESSION['owner_locked'] = true;
        $_SESSION['owner_lock_reason'] = $reason;
        echo json_encode(['success' => true, 'locked' => true, 'reason' => $reason]);
        break;

    case 'status':
        // Polled periodically so a tab that has been sitting open (not
        // reloaded) still picks up a lock triggered from another tab.
        echo json_encode([
            'success' => true,
            'locked' => !empty($_SESSION['owner_locked']),
            'reason' => ($_SESSION['owner_lock_reason'] ?? 'idle'),
        ]);
        break;

    case 'unlock':
        $password = (string)($_POST['password'] ?? '');
        if ($password === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter your password.']);
            break;
        }
        try {
            // verifyCurrentUserPassword() is what makes this work for the
            // Super Admin at all. That account signs in against the
            // SUPER_ADMIN_PASSWORD constant (super-admin/login_check.php), and
            // the hash in its users row is NOT kept in sync with it -
            // ensureSuperAdminExists() only writes a hash when it first
            // creates the row. A plain password_verify() against that row
            // would reject the correct password every time. The helper checks
            // the row hash first (for an owner-role account created normally)
            // and falls back to a constant-time compare against the constant
            // for the constant-auth Super Admin session.
            $pdo = getConnection();
            $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([getUserId()]);
            $email = (string)$stmt->fetchColumn();
            if ($email === '' && isConstantSuperAdminSession()) {
                $email = SUPER_ADMIN_EMAIL;
            }

            if (verifyCurrentUserPassword($password)) {
                $_SESSION['owner_locked'] = false;
                unset($_SESSION['owner_lock_reason']);
                echo json_encode(['success' => true]);
            } else {
                // Naming the account is safe - it is the session's own - and
                // it is the difference between retrying the same wrong
                // password and reaching for the right one.
                echo json_encode([
                    'success' => false,
                    'message' => $email !== ''
                        ? ('Incorrect password for ' . $email . '. Please try again.')
                        : 'Incorrect password. Please try again.',
                ]);
            }
        } catch (PDOException $e) {
            error_log('Owner unlock error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
