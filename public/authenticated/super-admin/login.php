<?php
// public/authenticated/super-admin/login.php — simple super-admin only login with in-code constants
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Super admin credentials (dev/local usage). Defined once in
// config/functions.php so this form, the AJAX handler in login_check.php and
// the admin lock's re-authentication all check the same value - they used to
// carry separate copies.
$SUPER_ADMIN_EMAIL = SUPER_ADMIN_EMAIL;
$SUPER_ADMIN_PASSWORD = SUPER_ADMIN_PASSWORD;

// If already logged in as super-admin, go straight to dashboard
if (isLoggedIn() && isSuperAdmin()) {
	header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/dashboard.php');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = trim($_POST['email'] ?? '');
	$password = $_POST['password'] ?? '';
	if ($email === $SUPER_ADMIN_EMAIL && $password === $SUPER_ADMIN_PASSWORD) {
		// Set session as a logged-in super admin (no DB lookup needed).
		// user_role must be 'super-admin' - that's the only value the users.role
		// enum actually has, and what 5 of the 7 super-admin pages check for via
		// require_role(['super-admin']). This used to be set to 'owner', which
		// isn't a real role value anywhere else, so Audit Logs/Sales Logs/
		// Maintenance/Notifications/Security all bounced back to the guest
		// landing page for anyone who logged in through this form.
		// The real users.id for this account, NOT 0. The old placeholder made
		// every later lookup keyed on the session's user id find nothing -
		// which is what broke the admin inactivity lock: it fetched the
		// password hash for user 0, got nothing, and rejected every password
		// including the correct one.
		ensureSuperAdminExists();
		$_SESSION['user_id'] = superAdminUserId();
		$_SESSION['firstname'] = 'System';
		$_SESSION['lastname'] = 'Owner';
		$_SESSION['email'] = $SUPER_ADMIN_EMAIL;
		$_SESSION['user_role'] = 'super-admin';
		$_SESSION['logged_in'] = true;
		// This session was authenticated against the constant above, not
		// against a users.password hash - verifyCurrentUserPassword() needs to
		// know that to re-check the password later.
		$_SESSION['sa_constant_auth'] = true;
		header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/dashboard.php');
		exit;
	}
	$error = 'Invalid owner credentials.';
}

$pageTitle = 'Owner Login - TechWorld';
$activeNav = '';
include __DIR__ . '/../../shared/includes/header.php';
include __DIR__ . '/../../shared/includes/navigation.php';
?>
<main class="auth-page" style="padding:40px 0;">
	<div class="container">
		<div class="modal-content" style="position:static;">
			<div class="auth-container">
				<div id="notification" class="notification" style="<?= isset($error)?'':'display:none;' ?>;">
					<?= isset($error)?htmlspecialchars($error):'' ?>
				</div>
				<div class="auth-form active">
					<h2>Owner Login</h2>
					<p>Enter the configured owner credentials</p>
					<form method="POST" action="">
						<div class="input-group">
							<input type="email" name="email" placeholder="Email" required autocomplete="email">
						</div>
                        <div class="password-field" style="position:relative;display:flex;align-items:center;">
                            <input type="password" id="saPassword" name="password" placeholder="Password" required autocomplete="current-password" style="padding-right:44px;position:relative;z-index:1;">
                            <button type="button" class="password-toggle" onclick="togglePassword('saPassword', this)" style="position:absolute;right:10px;top:52%;transform:translateY(-50%);width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:transparent;border:none;color:#94a3b8;">
								<i class="fi fi-rr-eye"></i>
							</button>
						</div>
						<button type="submit" class="auth-btn">Login</button>
					</form>
				</div>
			</div>
		</div>
	</div>
</main>
<?php include __DIR__ . '/../../shared/includes/footer.php'; ?>


