<?php
require_once __DIR__ . '/../../config/functions.php';
// Ensure password_resets table exists (idempotent)
try {
            $pdo = getConnection();
            // Ensure attempts columns exist (idempotent)
            try { $pdo->exec("ALTER TABLE password_resets ADD COLUMN attempts INT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already exists */ }
            try { $pdo->exec("ALTER TABLE password_resets ADD COLUMN last_attempt_at DATETIME NULL"); } catch (Throwable $e) { /* already exists */ }
	$pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
		id INT(11) NOT NULL AUTO_INCREMENT,
		user_id INT(11) NOT NULL,
		token VARCHAR(128) NOT NULL,
		expires_at DATETIME NOT NULL,
		used_at DATETIME DEFAULT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_token (token),
		KEY idx_user_id (user_id),
		KEY idx_expires_at (expires_at)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* ignore */ }

$statusMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!check_csrf(false)) {
		$errorMessage = 'Invalid request. Please refresh and try again.';
	} else {
		$email = trim(strtolower($_POST['email'] ?? ''));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$errorMessage = 'Please enter a valid email address.';
		} else {
			try {
				$pdo = getConnection();
				// Find user by email (only customers are allowed to reset password via email)
				$st = $pdo->prepare('SELECT id, firstname, lastname, role FROM users WHERE email = ? AND status IN ("active", "pending")');
				$st->execute([$email]);
				$user = $st->fetch();

				if ($user && $user['role'] !== 'customer') {
					$errorMessage = 'Password reset via email is available for Customer accounts only. Manager and Owner accounts must contact the System Owner.';
				} elseif ($user && $user['role'] === 'customer') {
					$now = time();
					$lastSent = (int)($_SESSION['pw_reset_last_sent'] ?? 0);

					// Check if an unexpired token was created less than 60 seconds ago in DB
					$chk = $pdo->prepare('SELECT id FROM password_resets WHERE user_id = ? AND used_at IS NULL AND created_at > (NOW() - INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1');
					$chk->execute([$user['id']]);
					$hasRecentToken = (bool)$chk->fetchColumn();

					// Only generate a new OTP and send an email if a code wasn't sent in the last 60 seconds
					if (!$hasRecentToken && ($now - $lastSent >= 60)) {
						// Clean up old expired tokens (keep recent unused ones intact)
						try { $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND (expires_at < NOW() OR created_at < (NOW() - INTERVAL 15 MINUTE))')->execute([$user['id']]); } catch (Throwable $e) {}

						// Generate 6-digit code (hash stored), valid for 15 minutes
						$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
						$expires = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');
						$codeHash = password_hash($code, PASSWORD_DEFAULT);

						try {
							$ins = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at, attempts) VALUES (?, ?, ?, 0)');
							$ins->execute([$user['id'], $codeHash, $expires]);
						} catch (Throwable $e) {
							$ins = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
							$ins->execute([$user['id'], $codeHash, $expires]);
						}

						$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
						$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
						$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
						$baseUrl = $scheme . $host . rtrim(WEB_ROOT, '/');
						$subject = 'Your TechWorld password reset code';
						$body = '<p>Hello ' . htmlspecialchars($fullName ?: $email) . ',</p>' .
							'<p>Use the verification code below to reset your password. This code expires in 15 minutes.</p>' .
							'<div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#123554;color:#a77693;padding:14px 18px;display:inline-block;border-radius:10px">' . htmlspecialchars($code) . '</div>' .
							'<p style="margin-top:16px;">Or open the reset page and enter the code: <a href="' . htmlspecialchars($baseUrl . '/public/guest/reset_password.php') . '" target="_blank" rel="noopener">' . htmlspecialchars($baseUrl . '/public/guest/reset_password.php') . '</a></p>' .
							'<p>If you did not request this, you can ignore this email.</p>' .
							'<p>— TechWorld</p>';
						sendEmail($email, $fullName, $subject, $body);

						$_SESSION['pw_reset_last_sent'] = $now;
					}
					// Remember email for verification step
					$_SESSION['pw_reset_email'] = $email;
				}
                // Always respond generically to avoid account enumeration
                header('Location: ' . WEB_ROOT . 'public/guest/reset_link_sent.php?email=' . urlencode($email));
                exit;
			} catch (Throwable $e) {
				$errorMessage = 'Something went wrong. Please try again later.';
			}
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Forgot Password • TechWorld</title>
	<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
</head>
<body class="auth-page" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:0;">
    <div class="container" style="width:100%;display:flex;align-items:center;justify-content:center;">
		<div class="modal-content" style="position:static;max-width:520px;margin:0 auto;">
			<div class="auth-container">
				<div id="notification" class="notification" style="<?= $statusMessage || $errorMessage ? '' : 'display:none;' ?>">
					<?= $statusMessage ? htmlspecialchars($statusMessage) : '' ?>
					<?= $errorMessage ? htmlspecialchars($errorMessage) : '' ?>
				</div>
                <div class="auth-form active">
                    <h2>Forgot Password</h2>
                    <p>Enter your email to receive a verification code</p>
					<form method="POST" action="">
						<?= csrf_field() ?>
						<input type="email" name="email" placeholder="Email Address" required autocomplete="email">
                        <button type="submit" class="auth-btn">Send Code</button>
					</form>
					<p class="auth-link"><a href="<?= WEB_ROOT ?>public/guest/index.php#login">Back to login</a></p>
				</div>
			</div>
		</div>
	</div>
</body>
</html>


