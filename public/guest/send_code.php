<?php
// public/guest/send_code.php — Endpoint to send password reset code
require_once __DIR__ . '/../../config/functions.php';

// Optional: enable SMTP debug via env when troubleshooting (0=off, 2=client/server)
// putenv('SMTP_DEBUG=2');

// Ensure password_resets table exists (idempotent) and attempts columns present
try {
	$pdo = getConnection();
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

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
	header('Location: ' . WEB_ROOT . 'public/guest/forgot_password.php');
	exit;
}

if (!check_csrf(false)) {
	if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }
	header('Location: ' . WEB_ROOT . 'public/guest/forgot_password.php?err=invalid');
	exit;
}

$email = trim(strtolower($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Please enter a valid email address.']); exit; }
	header('Location: ' . WEB_ROOT . 'public/guest/forgot_password.php?err=email');
	exit;
}

try {
	$pdo = getConnection();
	$st = $pdo->prepare('SELECT id, firstname, lastname FROM users WHERE email = ? AND status IN ("active", "pending")');
	$st->execute([$email]);
	$user = $st->fetch();

	if ($user) {
		$now = time();
		$lastSent = (int)($_SESSION['pw_reset_last_sent'] ?? 0);

		// Check if an unexpired token was created less than 60 seconds ago in DB
		$chk = $pdo->prepare('SELECT id FROM password_resets WHERE user_id = ? AND used_at IS NULL AND created_at > (NOW() - INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1');
		$chk->execute([$user['id']]);
		$hasRecentToken = (bool)$chk->fetchColumn();

		// Only generate a new OTP and send an email if a code wasn't sent in the last 60 seconds
		if (!$hasRecentToken && ($now - $lastSent >= 60)) {
			// Clean up old expired tokens (keep recent unused ones intact)
			try { $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND (expires_at < NOW() OR created_at < (NOW() - INTERVAL 1 MINUTE))')->execute([$user['id']]); } catch (Throwable $e) {}

			// Generate 6-digit code, store hashed
			$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
			$codeHash = password_hash($code, PASSWORD_DEFAULT);
			// Let MySQL compute expiry using its own clock to avoid timezone drift
			try {
				$ins = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at, attempts) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE, 0)');
				$ins->execute([$user['id'], $codeHash]);
			} catch (Throwable $e) {
				$ins = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)');
				$ins->execute([$user['id'], $codeHash]);
			}

			$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
			$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
			$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
			$baseUrl = $scheme . $host . rtrim(WEB_ROOT, '/');
			$subject = 'Your TechWorld password reset code';
			$body = '<p>Hello ' . htmlspecialchars($fullName ?: $email) . ',</p>' .
				'<div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#123554;color:#a77693;padding:14px 18px;display:inline-block;border-radius:10px">' . htmlspecialchars($code) . '</div>' .
				'<p style="margin-top:16px;">This code expires in 15 minutes.</p>' .
				'<p>You can enter it here: <a href="' . htmlspecialchars($baseUrl . '/public/guest/verify_code.php') . '" target="_blank" rel="noopener">' . htmlspecialchars($baseUrl . '/public/guest/verify_code.php') . '</a></p>' .
				'<p>If you did not request this, you can ignore this email.</p>' .
				'<p>— TechWorld</p>';
			@sendEmail($email, $fullName, $subject, $body);

			$_SESSION['pw_reset_last_sent'] = $now;
		}
		// Store email to streamline next step
		$_SESSION['pw_reset_email'] = $email;
	}

	// Always respond generically to prevent email enumeration
	if ($isAjax) {
		header('Content-Type: application/json');
		echo json_encode(['success'=>true,'message'=>'If the email exists, a code has been sent.']);
		exit;
	}
	header('Location: ' . WEB_ROOT . 'public/guest/reset_link_sent.php?email=' . urlencode($email));
	exit;
} catch (Throwable $e) {
	if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Something went wrong. Please try again later.']); exit; }
	header('Location: ' . WEB_ROOT . 'public/guest/forgot_password.php?err=general');
	exit;
}


