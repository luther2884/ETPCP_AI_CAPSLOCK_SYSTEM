<?php
declare(strict_types=1);
// Switches a signed-in Super Admin into the Admin panel, acting as an Admin.
//
// Each panel has its own session bucket (see the session_name() routing at the
// top of config/functions.php: TECHWRLD_SA / TECHWRLD_ADM / TECHWRLD_CUST), so
// this cannot simply rewrite $_SESSION['user_role'] - the admin panel reads a
// different session entirely. Instead it closes the Super Admin session
// (leaving it intact on disk, which is what makes switching back a no-op
// login-wise) and opens a second session in the admin bucket, flagged with
// sa_mode so the admin panel knows to offer the way back.
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

require_role(['owner', 'super-admin']);

// POST + CSRF: this is a privilege-context change, so it must not be
// reachable from a link, an <img> tag or a cross-site form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf(false)) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/dashboard.php');
    exit;
}

// A locked Owner panel must not be able to walk out of itself. This is the
// one route that turns this session into a WORKING session in another panel,
// and it would do so without ever asking for the password the lock exists to
// demand (see api/owner_lock.php and includes/header.php).
//
// The form that posts here lives on the owner dashboard, which the lock
// overlay makes `inert`, so this is not reachable by clicking while locked -
// but "the button is covered" is a client-side fact, and the whole reason the
// lock flag is server-side is that client-side facts do not hold for a
// re-submitted POST or a second tab. Bounce back to the dashboard, which
// renders the lock screen and asks for the password.
if (!empty($_SESSION['owner_locked'])) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/dashboard.php');
    exit;
}

// Carry the Super Admin's own identity across, so the admin panel's sidebar
// shows who is actually driving it rather than a borrowed admin account.
$identity = [
    'user_id'   => getUserId(),
    'firstname' => $_SESSION['firstname'] ?? 'Super',
    'lastname'  => $_SESSION['lastname'] ?? 'Admin',
    'email'     => $_SESSION['email'] ?? '',
    // Carried across so the admin panel's inactivity lock knows this identity
    // authenticates against SUPER_ADMIN_PASSWORD rather than a users.password
    // hash - without it, re-authentication in Admin Mode has no way to check
    // the password it is actually being given.
    'sa_constant_auth' => !empty($_SESSION['sa_constant_auth']),
];

// Keep the Super Admin session file; only stop writing to it for this request.
session_write_close();

// Open the admin bucket. Anything already in it (a real admin signed in on
// this browser) is replaced - there is only one TECHWRLD_ADM cookie per
// browser, so this behaves exactly as logging in as an admin here would.
session_name('TECHWRLD_ADM');
// Force a brand-new session id before starting. session_write_close() keeps
// the id in memory, and session files are keyed by id alone - the session
// NAME only decides which cookie carries it. Without this, session_start()
// below would reopen the Super Admin's own session file under the admin
// cookie and the $_SESSION reset would wipe the session we are relying on to
// still be there when they switch back.
session_id(session_create_id());
session_start();
$_SESSION = [];

$_SESSION['user_id']   = $identity['user_id'];
$_SESSION['firstname'] = $identity['firstname'];
$_SESSION['lastname']  = $identity['lastname'];
$_SESSION['email']     = $identity['email'];
$_SESSION['user_role'] = 'admin';
$_SESSION['logged_in'] = true;
// The marker the admin panel checks to show the "Return to Super Admin" bar,
// and that exit_admin_mode.php requires before it will clear this session.
$_SESSION['sa_mode']   = true;
$_SESSION['sa_constant_auth'] = $identity['sa_constant_auth'];

header('Location: ' . WEB_ROOT . 'public/authenticated/admin/dashboard.php');
exit;
