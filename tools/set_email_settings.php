<?php
// One-time setup page to store SMTP credentials in DB
// IMPORTANT: Use locally only, then delete this file.
require_once __DIR__ . '/../config/functions.php';

$pdo = getConnection();
$saved = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf(false)) {
        $error = 'Invalid request.';
    } else {
        $provider   = trim($_POST['provider'] ?? 'gmail');
        $host       = trim($_POST['host'] ?? '');
        $port       = (int)($_POST['port'] ?? 587);
        $username   = trim($_POST['username'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $secure     = trim($_POST['secure'] ?? 'tls');
        $fromEmail  = trim($_POST['from_email'] ?? '');
        $fromName   = trim($_POST['from_name'] ?? 'TechWorld');

        if (!$username || !$password || !$fromEmail) {
            $error = 'Username, password, and from email are required.';
        } else {
            ensureEmailSettingsTable();
            // Deactivate previous rows
            try { $pdo->exec("UPDATE email_settings SET is_active = 0"); } catch (Throwable $e) {}
            $st = $pdo->prepare("INSERT INTO email_settings
                (provider, host, port, username, password, secure, from_email, from_name, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $st->execute([$provider, $host ?: null, $port ?: null, $username, $password, $secure ?: null, $fromEmail, $fromName]);
            $saved = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Settings Setup</title>
    <link rel="stylesheet" href="<?= WEB_ROOT ?>public/shared/style.css">
</head>
<body class="auth-page" style="padding:40px 0;">
    <div class="container">
        <div class="modal-content" style="position:static;max-width:620px;margin:0 auto;">
            <div class="auth-container">
                <div class="auth-form active">
                    <h2>Configure Email (SMTP)</h2>
                    <?php if ($saved): ?>
                        <div class="notification">Saved. Try the Forgot Password flow now. Delete this file when done: tools/set_email_settings.php</div>
                    <?php elseif ($error): ?>
                        <div class="notification"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= csrf_field() ?>
                        <label>Provider</label>
                        <select name="provider">
                            <option value="gmail">Gmail</option>
                            <option value="mailtrap">Mailtrap</option>
                            <option value="custom">Custom</option>
                        </select>
                        <input type="text" name="host" placeholder="SMTP Host (leave blank for Gmail/Mailtrap preset)">
                        <input type="number" name="port" value="587" placeholder="Port">
                        <input type="email" name="username" placeholder="SMTP Username (Gmail address)" required>
                        <input type="text" name="password" placeholder="SMTP Password (App Password)" required>
                        <input type="text" name="secure" value="tls" placeholder="Security (tls/ssl)">
                        <input type="email" name="from_email" placeholder="From Email" required>
                        <input type="text" name="from_name" value="TechWorld" placeholder="From Name">
                        <button type="submit" class="auth-btn">Save Settings</button>
                    </form>
                    <p class="auth-link"><a href="<?= WEB_ROOT ?>public/guest/forgot_password.php">Go to Forgot Password</a></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


