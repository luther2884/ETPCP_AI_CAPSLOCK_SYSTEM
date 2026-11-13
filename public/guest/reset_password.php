<?php
require_once __DIR__ . '/../../config/functions.php';

$pdo = getConnection();
$valid = true; // access gated by verification session
$errorMessage = null;
$statusMessage = null;

// No pre-validation on page load; user provides code and email

/**
 * Establishes a full logged-in session for a user who just proved control
 * of their account via the emailed verification code - shared by both the
 * "Skip for now" path and the "successfully changed password" path below,
 * so neither one makes the customer log in again with credentials they
 * either just changed or chose not to touch. Returns true on success.
 */
function establishResetLoginSession(PDO $pdo, int $userId, string $email): bool {
	$st = $pdo->prepare('SELECT id, firstname, lastname, email, role, status, password FROM users WHERE id = ? AND email = ?');
	$st->execute([$userId, $email]);
	$u = $st->fetch();
	if (!$u || ($u['status'] ?? '') !== 'active') { return false; }

	$_SESSION['user_id'] = (int)$u['id'];
	$_SESSION['firstname'] = $u['firstname'] ?? '';
	$_SESSION['lastname'] = $u['lastname'] ?? '';
	$_SESSION['email'] = $u['email'] ?? $email;
	$_SESSION['user_role'] = $u['role'] ?? 'customer';
	$_SESSION['logged_in'] = true;
	$_SESSION['login_2fa_verified'] = true; // bypass verify_login_code.php
	$_SESSION['promo_modal_pending'] = true;

	// Optionally load cart from DB into session
	if (function_exists('loadCartFromDb')) {
		$cart = loadCartFromDb((int)$u['id']);
		if (!isset($_SESSION['cart'])) { $_SESSION['cart'] = []; }
		$_SESSION['cart'][(int)$u['id']] = is_array($cart) ? $cart : [];
	}

	// Clean up short-lived reset session flags
	unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_expires_at']);

	// Remember 2FA for 5 minutes as part of trusted session after reset/skip
	if (function_exists('setRemember2faCookie') && !empty($u['password'])) {
		try { setRemember2faCookie((int)$u['id'], (string)$u['password'], 300); } catch (Throwable $e) { /* ignore */ }
	}

	return true;
}

// Allow user to skip password reset and continue to dashboard without login 2FA
// Only if we have an authorized password reset session identifying the user
if (isset($_GET['skip'])) {
	$userIdFromReset = (int)($_SESSION['pw_reset_user_id'] ?? 0);
	$emailFromReset = trim(strtolower($_SESSION['pw_reset_email'] ?? ''));
	if ($userIdFromReset > 0 && filter_var($emailFromReset, FILTER_VALIDATE_EMAIL)) {
		try {
			if (establishResetLoginSession($pdo, $userIdFromReset, $emailFromReset)) {
				header('Location: ' . WEB_ROOT . 'public/authenticated/customer/index.php');
				exit;
			}
		} catch (Throwable $e) { /* fall through to page */ }
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!check_csrf(false)) {
		$errorMessage = 'Invalid request.';
	} else {
        // Only accept if verification session is present
        // Use email saved during request phase
        $email = trim(strtolower($_SESSION['pw_reset_email'] ?? ''));
		$new = $_POST['password'] ?? '';
		$confirm = $_POST['confirm_password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please start again and enter your email.';
        } elseif (strlen($new) < 8) {
			$errorMessage = 'Password must be at least 8 characters.';
		} elseif ($new !== $confirm) {
			$errorMessage = 'Passwords do not match.';
        } elseif (!isset($_SESSION['pw_reset_user_id']) || time() > (int)($_SESSION['pw_reset_expires_at'] ?? 0)) {
            $errorMessage = 'Your session expired. Please verify the code again.';
        } else {
			$pdo->beginTransaction();
            // Resolve user based on email and authorized session
            $getUser = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $getUser->execute([$email]);
            $userId = $getUser->fetchColumn();
            if (!$userId) { $pdo->rollBack(); $errorMessage = 'Invalid email.'; goto render; }
            if ((int)$userId !== (int)$_SESSION['pw_reset_user_id']) { $pdo->rollBack(); $errorMessage = 'Invalid session. Verify again.'; goto render; }
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $upd->execute([$hash, $userId]);
            // Invalidate any outstanding reset tokens for this user
            try { $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]); } catch (Throwable $e) {}
            $pdo->commit();

            // Log the user straight in with their new password - same
            // session-establishment "Skip for now" already uses, so they
            // never have to re-enter credentials right after just proving
            // ownership of the account and choosing a new password.
            try {
                if (establishResetLoginSession($pdo, (int)$userId, $email)) {
                    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/index.php');
                    exit;
                }
            } catch (Throwable $e) { /* fall through to the manual-login success page below */ }

            unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_expires_at']);
            header('Location: ' . WEB_ROOT . 'public/guest/reset_success.php');
            exit;
		}
	}
}
render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Reset Password • TechWorld</title>
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
					<h2>Reset Password</h2>
                    <p>Set a new password for your account</p>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <!-- Email removed; retrieved from session -->
					<div class="password-field">
						<input type="password" id="pw" name="password" placeholder="New Password" required autocomplete="new-password">
						<button type="button" class="password-toggle" data-pair="cpw" onclick="togglePassword('pw', this)"><i class="fi fi-rr-eye"></i></button>
					</div>
					<div class="password-field">
						<input type="password" id="cpw" name="confirm_password" placeholder="Confirm Password" required autocomplete="new-password">
						<button type="button" class="password-toggle" data-pair="pw" onclick="togglePassword('cpw', this)"><i class="fi fi-rr-eye"></i></button>
					</div>
						<button type="submit" class="auth-btn">Update Password</button>
					</form>
					<p class="auth-link" style="margin-top:10px;">
						<a href="?skip=1" style="display:inline-block;padding:12px 18px;border:2px solid rgba(167,118,147,.5);border-radius:10px;color:var(--accent-cyan);text-decoration:none;">Skip for now</a>
					</p>
					<p class="auth-link"><a href="<?= WEB_ROOT ?>public/guest/index.php#login">Back to login</a></p>
				</div>
			</div>
		</div>
	</div>
<script src="<?= WEB_ROOT ?>public/shared/assets/js/main.js"></script>
</body>
</html>


