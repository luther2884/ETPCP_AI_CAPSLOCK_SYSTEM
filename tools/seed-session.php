<?php
/**
 * tools/seed-session.php - write the fixed preview session tools/shoot.js uses.
 *
 * Dev-only, run from the CLI. shoot.js sends a TECHWRLD_CUST=aibcust cookie so
 * it never has to drive the login + OTP screens; that only works while a
 * session file with that id exists and holds a logged-in customer.
 *
 * It does expire, and - worse - a request that arrives after it has gone gets
 * bounced to the guest login, which then WRITES a fresh empty session back to
 * the same id. From that point every capture is silently of the guest site
 * while still looking like a successful run. Re-seeding before a capture
 * session is the cheap way to be sure of what was photographed.
 *
 *   php tools/seed-session.php
 *
 * Reads nothing from the request and writes no application data: it only
 * populates the session array require_login() and getUserData() check.
 */
require_once __DIR__ . '/../config/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$pdo = getConnection();
$user = $pdo->query("SELECT id, firstname, lastname, email, role
                     FROM users WHERE role = 'customer' ORDER BY id LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
if (!$user) { exit("No customer account to seed from.\n"); }

$data = [
    'csrf_token'          => bin2hex(random_bytes(32)),
    'user_id'             => (int)$user['id'],
    'firstname'           => $user['firstname'],
    'lastname'            => $user['lastname'],
    'email'               => $user['email'],
    'username'            => $user['firstname'] . ' ' . $user['lastname'],
    'user_role'           => $user['role'],
    'logged_in'           => true,
    // require_login() sends a customer to the OTP screen without this.
    'login_2fa_verified'  => true,
];

// The default `php` serialize_handler's format: each entry is the key, a
// pipe, then the value through serialize(). Built by hand rather than with
// session_encode(), which would need a live session of its own and would
// overwrite the one functions.php has already started for this CLI run.
$blob = '';
foreach ($data as $k => $v) { $blob .= $k . '|' . serialize($v); }

$dir  = ini_get('session.save_path') ?: sys_get_temp_dir();
$path = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . 'sess_aibcust';
file_put_contents($path, $blob);

echo "seeded {$path} as {$user['firstname']} {$user['lastname']} (user {$user['id']}, {$user['role']})\n";
