<?php
declare(strict_types=1);

/**
 * set_email_settings.php - change the Gmail (SMTP) account the system sends
 * verification codes, password resets and order mail from.
 *
 * Layer 3 (execution). The browser cannot reach this and should not: the whole
 * point of the page it replaces (tools/set_email_settings.php) was to write
 * SMTP credentials, and .htaccess blocks tools/ from the web precisely so a
 * form that reconfigures where the store's mail comes from is not hanging off
 * the public internet. This runs from a terminal on the machine instead, and
 * refuses to run any other way.
 *
 * Usage (from the project root):
 *   php execution/set_email_settings.php --show
 *   php execution/set_email_settings.php --username=you@gmail.com
 *   php execution/set_email_settings.php --username=you@gmail.com --from-name="TechWorld Support"
 *   php execution/set_email_settings.php --test=someone@example.com
 *
 * The password is asked for on the prompt rather than taken as a flag, so the
 * Gmail App Password never lands in the shell history. (--password= exists for
 * a scripted run; prefer the prompt.)
 *
 * GMAIL NEEDS AN APP PASSWORD, not the account's own password: turn on 2-Step
 * Verification on that Google account, then create one at
 * https://myaccount.google.com/apppasswords. It is 16 characters; spaces in it
 * are ignored here.
 *
 * What it writes: one row in `email_settings` with is_active = 1, after
 * clearing the flag on every other row - exactly what getActiveEmailSettings()
 * in config/functions.php reads, and what sendEmail() prefers over the SMTP_*
 * values in .env.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}

require_once __DIR__ . '/../config/functions.php';

$opts = getopt('', [
    'show', 'help', 'test::',
    'username::', 'password::', 'from-email::', 'from-name::',
    'provider::', 'host::', 'port::', 'secure::',
]);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1800), "\n";
    exit(0);
}

$pdo = getConnection();
ensureEmailSettingsTable();

/** The active row, with the password reduced to a shape rather than a value. */
function show_current(PDO $pdo): void {
    $row = getActiveEmailSettings();
    if (!$row) {
        echo "No active row in email_settings.\n";
        echo "Mail would fall back to the SMTP_* values in .env, then to PHP mail().\n";
        return;
    }
    $pw = (string)($row['password'] ?? '');
    printf(
        "Currently sending as:\n"
        . "  account   : %s\n"
        . "  from       : %s <%s>\n"
        . "  provider   : %s (host %s, port %s, %s)\n"
        . "  password   : %s\n"
        . "  row id     : %s, last changed %s\n",
        $row['username'] ?: '(none)',
        $row['from_name'] ?: '(none)', $row['from_email'] ?: '(none)',
        $row['provider'], $row['host'] ?: 'preset', $row['port'] ?: 'preset', $row['secure'] ?: 'none',
        $pw === '' ? '(not set)' : str_repeat('*', min(16, strlen($pw))) . ' (' . strlen($pw) . ' chars)',
        $row['id'], $row['updated_at'] ?? '?'
    );
}

/* ---- --show ---------------------------------------------------------- */
if (isset($opts['show']) || (!isset($opts['username']) && !isset($opts['test']))) {
    show_current($pdo);
    if (!isset($opts['show'])) {
        echo "\nTo change the account:\n";
        echo "  php execution/set_email_settings.php --username=newaccount@gmail.com\n";
        echo "To send a test message with the current settings:\n";
        echo "  php execution/set_email_settings.php --test=you@example.com\n";
    }
    exit(0);
}

/* ---- --test ---------------------------------------------------------- */
// Deliberately separate from the change above: proving the new account works
// is its own step, and a test is a real email to a real person.
if (isset($opts['test']) && !isset($opts['username'])) {
    $to = (string)$opts['test'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { exit("--test needs a valid email address.\n"); }
    show_current($pdo);
    echo "\nSending a test message to {$to} ...\n";
    $ok = sendEmail($to, '', 'TechWorld SMTP test',
        '<p>This is a test from <strong>execution/set_email_settings.php</strong>.</p>'
        . '<p>If you are reading it, verification codes will send from this account.</p>',
        'Test from execution/set_email_settings.php');
    echo $ok
        ? "Sent. Check the inbox (and spam).\n"
        : "FAILED. Run with SMTP_DEBUG=2 in .env and check the PHP error log for the SMTP conversation.\n";
    exit($ok ? 0 : 1);
}

/* ---- change the account ---------------------------------------------- */
$username = trim((string)$opts['username']);
if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
    exit("--username must be the full Gmail address, e.g. --username=store@gmail.com\n");
}

$password = isset($opts['password']) ? (string)$opts['password'] : '';
if ($password === '') {
    // No shell history, and no password sitting in a process listing.
    echo "Gmail App Password for {$username} (16 chars, from https://myaccount.google.com/apppasswords): ";
    $password = trim((string)fgets(STDIN));
}
// Google prints app passwords in groups of four; people paste them that way.
$password = str_replace(' ', '', $password);
if ($password === '') { exit("No password given - nothing was changed.\n"); }

$provider  = (string)($opts['provider'] ?? 'gmail');
$fromEmail = (string)($opts['from-email'] ?? $username);
$fromName  = (string)($opts['from-name'] ?? 'TechWorld');
$host      = isset($opts['host']) ? (string)$opts['host'] : null;
$port      = isset($opts['port']) ? (int)$opts['port'] : 587;
$secure    = (string)($opts['secure'] ?? 'tls');

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    exit("--from-email must be a valid address.\n");
}
// Gmail rewrites the From: header to the authenticated account unless the
// other address is a verified alias, so a mismatch usually means mail that
// looks like it came from somewhere the recipient did not expect.
if ($provider === 'gmail' && strcasecmp($fromEmail, $username) !== 0) {
    echo "Note: from-email ({$fromEmail}) is not the Gmail account ({$username}).\n";
    echo "      Gmail will rewrite it unless it is a verified alias on that account.\n";
}

echo "\nBefore:\n";
show_current($pdo);

try {
    $pdo->beginTransaction();
    // One active row at a time: getActiveEmailSettings() takes the newest
    // active one, and leaving an old row active is how a change silently
    // fails to take effect.
    $pdo->exec("UPDATE email_settings SET is_active = 0");
    $st = $pdo->prepare("INSERT INTO email_settings
        (provider, host, port, username, password, secure, from_email, from_name, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $st->execute([$provider, $host ?: null, $port ?: null, $username, $password,
                  $secure ?: null, $fromEmail, $fromName]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    exit("Could not save: " . $e->getMessage() . "\n");
}

echo "\nAfter:\n";
show_current($pdo);
echo "\nThe old row is kept with is_active = 0, so this is reversible.\n";
echo "Now send a test before trusting it:\n";
echo "  php execution/set_email_settings.php --test=you@example.com\n";
