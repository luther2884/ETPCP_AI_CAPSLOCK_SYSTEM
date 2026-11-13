<?php
// public/authenticated/admin/index.php — Professional Manager Portal Login
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// If already logged in, route by role to designated dashboard
if (isLoggedIn()) {
	if (isAdmin() || getUserRole() === 'manager') {
		header('Location: ' . WEB_ROOT . 'public/authenticated/admin/dashboard.php');
		exit;
	}
	if (isSuperAdmin() || getUserRole() === 'owner') {
		header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/dashboard.php');
		exit;
	}
	if (isCustomer()) {
		header('Location: ' . WEB_ROOT . 'public/authenticated/customer/index.php');
		exit;
	}
}

$pageTitle = 'Manager Portal Login - TechWorld';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
	<title><?= htmlspecialchars($pageTitle) ?></title>
	<link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
	<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
	<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
	<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
	<script>window.WEB_ROOT = '<?= WEB_ROOT ?>';</script>

	<style>
		body.manager-login-bg {
			background: radial-gradient(circle at 50% 20%, #1e293b 0%, #0f172a 60%, #090d16 100%);
			color: #e2e8f0;
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			font-family: 'Inter', system-ui, -apple-system, sans-serif;
			margin: 0;
		}

		.mgr-header {
			background: rgba(15, 23, 42, 0.85);
			backdrop-filter: blur(12px);
			border-bottom: 1px solid rgba(255, 255, 255, 0.08);
			padding: 14px 0;
		}
		.mgr-header-inner {
			display: flex;
			align-items: center;
			justify-content: space-between;
			max-width: 1200px;
			margin: 0 auto;
			padding: 0 20px;
		}
		.mgr-logo {
			display: flex;
			align-items: center;
			gap: 12px;
			text-decoration: none;
		}
		.mgr-logo img {
			height: 36px;
			width: auto;
		}
		.mgr-logo h2 {
			color: #fff;
			font-size: 20px;
			font-weight: 800;
			letter-spacing: 0.5px;
			margin: 0;
		}
		.mgr-portal-badge {
			background: rgba(167, 118, 147, 0.12);
			color: #a77693;
			border: 1px solid rgba(167, 118, 147, 0.3);
			padding: 6px 14px;
			border-radius: 20px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.5px;
			display: flex;
			align-items: center;
			gap: 6px;
		}

		.mgr-main-container {
			flex: 1;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 40px 20px;
		}

		.mgr-login-card {
			width: 100%;
			max-width: 440px;
			background: rgba(15, 23, 42, 0.92);
			border: 1px solid rgba(255, 255, 255, 0.12);
			border-radius: 20px;
			padding: 40px 32px;
			box-shadow: 0 25px 60px rgba(0, 0, 0, 0.65);
			backdrop-filter: blur(20px);
			position: relative;
			overflow: hidden;
		}
		.mgr-login-card::before {
			content: '';
			position: absolute;
			top: 0;
			left: 0;
			right: 0;
			height: 4px;
			background: linear-gradient(90deg, #00c6ff, #0072ff, #a77693);
		}

		.mgr-card-header {
			text-align: center;
			margin-bottom: 30px;
		}
		.mgr-card-header h2 {
			font-size: 26px;
			font-weight: 800;
			color: #f8fafc;
			margin: 0 0 8px 0;
			letter-spacing: -0.3px;
		}
		.mgr-card-header p {
			color: #94a3b8;
			font-size: 14px;
			margin: 0;
		}

		.mgr-input-group {
			position: relative;
			margin-bottom: 20px;
		}
		.mgr-input-icon {
			position: absolute;
			left: 14px;
			top: 50%;
			transform: translateY(-50%);
			color: #64748b;
			font-size: 16px;
			pointer-events: none;
			transition: color 0.2s ease;
		}
		.mgr-input {
			width: 100% !important;
			height: 50px !important;
			background: rgba(30, 41, 59, 0.7) !important;
			border: 1.5px solid #334155 !important;
			border-radius: 10px !important;
			padding: 0 44px 0 42px !important;
			color: #f8fafc !important;
			font-size: 14px !important;
			box-sizing: border-box !important;
			transition: border-color 0.2s, box-shadow 0.2s !important;
		}
		.mgr-input:focus {
			border-color: #a77693 !important;
			outline: none !important;
			box-shadow: 0 0 0 3px rgba(167, 118, 147, 0.15) !important;
			background: rgba(30, 41, 59, 0.95) !important;
		}
		.mgr-input:focus + .mgr-input-icon,
		.mgr-input-group:focus-within .mgr-input-icon {
			color: #a77693;
		}

		.mgr-pw-toggle {
			position: absolute !important;
			right: 12px !important;
			top: 50% !important;
			transform: translateY(-50%) !important;
			background: transparent !important;
			border: none !important;
			color: #64748b !important;
			cursor: pointer !important;
			padding: 6px !important;
			font-size: 16px !important;
			display: flex !important;
			align-items: center !important;
			justify-content: center !important;
			transition: color 0.2s ease !important;
		}
		.mgr-pw-toggle:hover {
			color: #a77693 !important;
		}

		.mgr-submit-btn {
			width: 100% !important;
			height: 50px !important;
			background: linear-gradient(135deg, #00c6ff 0%, #0072ff 100%) !important;
			color: #fff !important;
			font-weight: 700 !important;
			font-size: 15px !important;
			border: none !important;
			border-radius: 10px !important;
			cursor: pointer !important;
			letter-spacing: 0.5px !important;
			box-shadow: 0 10px 25px rgba(0, 114, 255, 0.35) !important;
			transition: transform 0.2s, box-shadow 0.2s !important;
			margin-top: 10px !important;
		}
		.mgr-submit-btn:hover {
			transform: translateY(-2px) !important;
			box-shadow: 0 14px 30px rgba(0, 114, 255, 0.5) !important;
		}

		.mgr-footer-links {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-top: 20px;
			font-size: 13px;
		}
		.mgr-footer-links a {
			color: #38bdf8;
			text-decoration: none;
			transition: color 0.2s;
		}
		.mgr-footer-links a:hover {
			color: #a77693;
			text-decoration: underline;
		}

		.mgr-footer {
			background: rgba(15, 23, 42, 0.9);
			border-top: 1px solid rgba(255, 255, 255, 0.06);
			padding: 16px 0;
			text-align: center;
			font-size: 13px;
			color: #64748b;
		}

		.notification-banner {
			padding: 12px 16px;
			border-radius: 8px;
			font-size: 13px;
			font-weight: 600;
			margin-bottom: 20px;
			text-align: center;
		}
		.notification-error {
			background: rgba(239, 68, 68, 0.15);
			color: #f87171;
			border: 1px solid #ef4444;
		}
		.notification-success {
			background: rgba(34, 197, 94, 0.15);
			color: #4ade80;
			border: 1px solid #22c55e;
		}
	</style>
</head>
<body class="manager-login-bg">

	<!-- Modern Top Bar -->
	<header class="mgr-header">
		<div class="mgr-header-inner">
			<a href="<?= WEB_ROOT ?>public/guest/index.php" class="mgr-logo">
				<img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="TechWorld Logo">
				<h2>TECHWORLD</h2>
			</a>
			<div class="mgr-portal-badge">
				<span>🛡️</span> MANAGER PORTAL
			</div>
		</div>
	</header>

	<!-- Main Login Card -->
	<main class="mgr-main-container">
		<div class="mgr-login-card">
			<div class="mgr-card-header">
				<h2>Manager Sign In</h2>
				<p>Enter your account credentials to access management console</p>
			</div>

			<div id="notification" style="display:none;"></div>

			<form id="adminLoginForm" action="<?= WEB_ROOT ?>public/authenticated/admin/login.php" method="POST">
				<?= csrf_field() ?>

				<div class="mgr-input-group">
					<input type="email" name="email" class="mgr-input" placeholder="Manager Email Address" required autocomplete="email">
					<i class="fi fi-rr-envelope mgr-input-icon"></i>
				</div>

				<div class="mgr-input-group">
					<input type="password" id="adminLoginPassword" name="password" class="mgr-input" placeholder="Manager Passcode" required autocomplete="current-password">
					<i class="fi fi-rr-lock mgr-input-icon"></i>
					<button type="button" id="toggleAdminLoginPw" class="mgr-pw-toggle" onclick="togglePassword('adminLoginPassword', this)" title="Show/Hide Password">
						<i class="fi fi-rr-eye"></i>
					</button>
				</div>

				<button type="submit" class="mgr-submit-btn">SIGN IN TO DASHBOARD →</button>

				<div class="mgr-footer-links" style="justify-content:center;">
					<a href="<?= WEB_ROOT ?>public/guest/index.php" style="color:#94a3b8;">← Back to Store</a>
				</div>
			</form>
		</div>
	</main>

	<!-- Clean Footer -->
	<footer class="mgr-footer">
		<div class="container">
			&copy; <?= date('Y') ?> TechWorld • Authorized Store Manager Access
		</div>
	</footer>

	<script>
		function showNotification(message, type = 'error') {
			const notification = document.getElementById('notification');
			if (!notification) return;
			notification.textContent = message;
			notification.className = `notification-banner notification-${type}`;
			notification.style.display = 'block';
			setTimeout(() => { notification.style.display = 'none'; }, 5000);
		}

		function togglePassword(inputId, button) {
			const input = document.getElementById(inputId);
			const icon = button.querySelector('i');
			if (!input) return;
			if (input.type === 'password') {
				input.type = 'text';
				if (icon) icon.className = 'fi fi-rr-eye-crossed';
				button.title = 'Hide Passcode';
			} else {
				input.type = 'password';
				if (icon) icon.className = 'fi fi-rr-eye';
				button.title = 'Show Passcode';
			}
		}

		document.addEventListener('DOMContentLoaded', function(){
			const form = document.getElementById('adminLoginForm');
			if(!form) return;

			form.addEventListener('submit', function(e){
				e.preventDefault();
				const fd = new FormData(form);
				const btn = form.querySelector('button[type=submit]');
				const original = btn.textContent;
				btn.disabled = true;
				btn.textContent = 'Verifying Credentials...';

				fetch('<?= WEB_ROOT ?>public/authenticated/admin/login.php', {
					method:'POST',
					headers:{'X-Requested-With':'XMLHttpRequest'},
					body: fd
				})
				.then(r => r.json())
				.then(d => {
					if (d.success) {
						const fallback = '<?= WEB_ROOT ?>public/authenticated/admin/dashboard.php';
						location.href = d.redirect || fallback;
					} else {
						showNotification(d.message || 'Invalid Manager credentials.', 'error');
					}
				})
				.catch(() => showNotification('An error occurred while connecting. Please try again.', 'error'))
				.finally(() => {
					btn.disabled = false;
					btn.textContent = original;
				});
			});
		});
	</script>
</body>
</html>
