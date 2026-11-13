<?php
declare(strict_types=1);
// Ends a Super Admin's "act as Admin" session and returns them to the Owner
// panel. Lives under admin/ on purpose: session_name() routing keys off the
// URL (config/functions.php), so this is the only place the TECHWRLD_ADM
// session can be cleared. The Super Admin's own TECHWRLD_SA session was never
// touched by the switch, so they land back on the Owner dashboard still
// signed in - unless it has since expired, in which case require_role() there
// sends them to the Owner login, which is the correct outcome.
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Only ever clears a session this feature created. A real admin hitting this
// URL is simply sent back to their dashboard with their session untouched.
if (empty($_SESSION['sa_mode'])) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/admin/dashboard.php');
    exit;
}

// Same teardown as logout() minus the audit entry - this is a mode change,
// not a sign-out, and the Owner session it returns to is still live.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/dashboard.php');
exit;
