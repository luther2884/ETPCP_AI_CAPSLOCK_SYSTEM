<?php
// public/guest/setup_password.php — 10-minute expiration passcode setup for
// newly invited staff. Serves BOTH invited roles: Managers (users.role
// 'admin') and Delivery Personnel ('driver'). Every role-facing string on
// the page comes from $roleLabel / $portalUrl / $portalCta below.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once __DIR__ . '/../../config/functions.php';

$pdo = getConnection();
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errorMessage = '';
$formError = '';
$successMessage = '';
$validInvitation = null;

if (!empty($token)) {
    try {
        // Clean expired tokens first
        $pdo->exec("DELETE FROM manager_invitations WHERE expires_at < NOW()");

        // Check if token exists and is valid
        $stmt = $pdo->prepare("
            SELECT mi.*, u.firstname, u.lastname, u.email, u.role
            FROM manager_invitations mi
            JOIN users u ON mi.user_id = u.id
            WHERE mi.token = ? AND mi.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $validInvitation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$validInvitation) {
            $errorMessage = 'This passcode setup link has expired (10-minute time limit) or is invalid. Please contact the Owner to issue a new setup link.';
        }
    } catch (Throwable $e) {
        error_log('Error checking invitation token: ' . $e->getMessage());
        $errorMessage = 'An error occurred while validating your setup link.';
    }
} else {
    $errorMessage = 'No setup token provided. Please use the link sent to your email.';
}

// This page serves BOTH invited roles - super-admin/requests.php creates a
// manager_invitations row for managers and for delivery personnel alike, and
// brands its email accordingly ($roleLabel there). Only this page was still
// hard-coded to "Manager", so a driver following their link was told to set
// a Manager passcode and then sent to the admin portal. Same two labels as
// requests.php uses, derived from the same users.role value.
$isDriverAccount = (($validInvitation['role'] ?? '') === 'driver');
$roleLabel       = $isDriverAccount ? 'Delivery Personnel' : 'Manager';
// Riders sign in at courier/login.php (its own TECHWRLD_CRR session bucket);
// admin/logistics_login.php is only a 301 stub for old bookmarks.
$portalUrl       = WEB_ROOT . ($isDriverAccount ? 'public/authenticated/courier/login.php' : 'public/authenticated/admin/');
$portalCta       = $isDriverAccount ? 'Go to Delivery Portal' : 'Go to Manager Portal';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validInvitation) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm)) {
        $formError = 'All passcode fields are required.';
    } elseif ($password !== $confirm) {
        $formError = 'Passcodes do not match. Please enter matching passcodes.';
    } elseif (strlen($password) < 8) {
        $formError = 'Passcode must be at least 8 characters long.';
    } elseif (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
        $formError = 'Passcode must contain at least one uppercase letter, one lowercase letter, and one number.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Update user's password and activate account
            $upd = $pdo->prepare("UPDATE users SET password = ?, status = 'active', updated_at = NOW() WHERE id = ?");
            $upd->execute([$hash, (int)$validInvitation['user_id']]);

            // Remove invitation token so it cannot be reused
            $del = $pdo->prepare("DELETE FROM manager_invitations WHERE user_id = ?");
            $del->execute([(int)$validInvitation['user_id']]);

            $successMessage = 'Your passcode has been set successfully! You can now log in to the ' . $roleLabel . ' portal.';
            $validInvitation = null; // Prevent showing form again
        } catch (Throwable $e) {
            error_log('Error setting ' . $roleLabel . ' passcode: ' . $e->getMessage());
            $formError = 'Could not update your passcode. Please try again.';
        }
    }
}

$pageTitle = 'Set ' . $roleLabel . ' Passcode • TechWorld';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= htmlspecialchars($pageTitle) ?></title>
	<link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
	<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
	<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
</head>
<body class="auth-page" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:#0b1220;color:var(--text-primary);">
    <div class="container" style="width:100%;max-width:520px;margin:0 auto;">
		<div class="modal-content" style="position:static;max-width:520px;margin:0 auto;background:linear-gradient(180deg,rgba(20,30,48,0.95),rgba(15,23,42,0.98));border:1px solid rgba(255,255,255,0.08);border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,0.6);">
			<div class="auth-container" style="padding:40px 30px;">
				
				<?php if ($successMessage): ?>
					<div style="text-align: center; padding: 10px 0;">
						<div style="width:64px;height:64px;border-radius:50%;margin:0 auto 16px;background:rgba(34,197,94,0.15);border:1px solid #22c55e;display:flex;align-items:center;justify-content:center;color:#22c55e;font-size:32px;">✓</div>
						<h2 style="color:#22c55e;margin-bottom:10px;">Passcode Activated!</h2>
						<p style="color:var(--text-muted);margin-bottom:24px;"><?= htmlspecialchars($successMessage) ?></p>
						<a href="<?= htmlspecialchars($portalUrl) ?>" class="auth-btn" style="display:inline-block;text-decoration:none;padding:14px 24px;text-align:center;"><?= htmlspecialchars($portalCta) ?></a>
					</div>
				<?php elseif (!$validInvitation): ?>
					<div style="text-align: center; padding: 10px 0;">
						<div style="width:64px;height:64px;border-radius:50%;margin:0 auto 16px;background:rgba(239,68,68,0.15);border:1px solid #ef4444;display:flex;align-items:center;justify-content:center;color:#ef4444;font-size:32px;">✕</div>
						<h2 style="color:#ef4444;margin-bottom:10px;">Setup Link Error</h2>
						<p style="color:var(--text-muted);margin-bottom:24px;"><?= htmlspecialchars($errorMessage) ?></p>
						<a href="<?= WEB_ROOT ?>public/guest/index.php" class="auth-btn" style="display:inline-block;text-decoration:none;padding:14px 24px;background:#334155;color:#fff;">Return to Home</a>
					</div>
				<?php else: ?>
					<div class="auth-form active">
						<h2>Set <?= htmlspecialchars($roleLabel) ?> Passcode</h2>
						<p>Hello <strong><?= htmlspecialchars(($validInvitation['firstname'] ?? '') . ' ' . ($validInvitation['lastname'] ?? '')) ?></strong>! Set your private passcode below to activate your <?= htmlspecialchars($roleLabel) ?> account.</p>
						<p style="font-size:12px;color:var(--accent-cyan);margin-bottom:20px;text-align:center;">⏱ This setup link will expire 10 minutes from creation.</p>

						<div id="formAlert" class="notification error" style="<?= $formError ? '' : 'display:none;' ?>;margin-bottom:20px;">
							<?= htmlspecialchars($formError) ?>
						</div>

						<form id="passcodeForm" method="POST" action="">
							<input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
							<?= csrf_field() ?>

							<div class="password-field">
								<input type="password" id="pw" name="password" placeholder="New Passcode" required autocomplete="new-password">
								<button type="button" class="password-toggle" onclick="togglePassword('pw', this)"><i class="fi fi-rr-eye"></i></button>
							</div>

							<div class="password-field">
								<input type="password" id="cpw" name="confirm_password" placeholder="Confirm Passcode" required autocomplete="new-password">
								<button type="button" class="password-toggle" onclick="togglePassword('cpw', this)"><i class="fi fi-rr-eye"></i></button>
							</div>

							<button type="submit" class="auth-btn" style="margin-top:20px;">SET PASSCODE & ACTIVATE ACCOUNT</button>
						</form>
					</div>
				<?php endif; ?>

			</div>
		</div>
	</div>

<script src="<?= WEB_ROOT ?>public/shared/assets/js/main.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('passcodeForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        const pw = document.getElementById('pw').value;
        const cpw = document.getElementById('cpw').value;
        const alertBox = document.getElementById('formAlert');

        if (pw.length < 8) {
            e.preventDefault();
            alertBox.style.display = 'block';
            alertBox.textContent = 'Passcode must be at least 8 characters long.';
            return false;
        }
        if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(pw)) {
            e.preventDefault();
            alertBox.style.display = 'block';
            alertBox.textContent = 'Passcode must contain at least one uppercase letter, one lowercase letter, and one number.';
            return false;
        }
        if (pw !== cpw) {
            e.preventDefault();
            alertBox.style.display = 'block';
            alertBox.textContent = 'Passcodes do not match. Please re-enter matching passcodes.';
            return false;
        }
    });
});
</script>
</body>
</html>
