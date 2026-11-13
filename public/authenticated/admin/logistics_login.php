<?php
// public/authenticated/admin/logistics_login.php — Logistics/Delivery
// Personnel login (separate from the Manager/Owner logins - see the role
// restriction in perform_logistics_login() below, which rejects every role
// except 'driver'). Deliberately lives under /admin/ so it lands in the
// same TECHWRLD_ADM session bucket Delivery_Report.php expects (see
// config/functions.php's URI-based session_name() selection) - this file
// is otherwise fully separate from admin/login.php: different page,
// different form, different credentials accepted.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// MOVED. Riders/couriers now live in public/authenticated/courier/, with
// their own URL and their own session bucket - nothing rider-facing belongs
// under /admin/ any more. This stub only exists so old bookmarks, saved
// phone shortcuts and the printed QR cards keep working.
header('Location: ' . WEB_ROOT . 'public/authenticated/courier/login.php', true, 301);
exit;

$pdo = getConnection();

function perform_logistics_login(PDO $pdo): array {
  if (function_exists('check_csrf') && !check_csrf(false)) {
    throw new RuntimeException('Security token invalid.');
  }

  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($email === '' || $password === '') {
    throw new RuntimeException('Email and password are required.');
  }

  $st = $pdo->prepare('SELECT id, firstname, lastname, email, password, role, status FROM users WHERE email = ? LIMIT 1');
  $st->execute([$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if (!$u || !password_verify($password, $u['password'])) {
    throw new RuntimeException('Invalid email or password.');
  }

  if ($u['status'] !== 'active') {
    throw new RuntimeException($u['status'] === 'pending' ? 'Your account is pending setup. Check your email for the invitation link.' : 'Your account is not active.');
  }

  // Only 'driver' (Logistics/Delivery Personnel) accounts may authenticate
  // here - this is the whole point of a separate portal: an admin/manager/
  // owner/customer credential is rejected outright, same as this role is
  // already rejected by admin/login.php and guest/login.php.
  if ($u['role'] !== 'driver') {
    throw new RuntimeException('This account cannot login here. Please use the login page for your role.');
  }

  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['firstname'] = $u['firstname'];
  $_SESSION['lastname'] = $u['lastname'];
  $_SESSION['email'] = $u['email'];
  $_SESSION['user_role'] = $u['role'];
  $_SESSION['logged_in'] = true;

  return [
    'success' => true,
    'message' => 'Login successful.',
    'redirect' => WEB_ROOT . 'public/authenticated/admin/Delivery_Report.php',
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try {
    echo json_encode(perform_logistics_login($pdo));
  } catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  }
  exit;
}

// Already signed in? Say so - do not bounce.
//
// This used to redirect every signed-in role away, which made this URL
// unreachable to anyone holding a session: a courier could not open their
// own login page to hand the phone to the next rider, and a manager could
// not get to the courier form at all. Both roles share the one TECHWRLD_ADM
// session cookie, so "sign in as someone else" is a real, routine need here.
//
// The page now renders either way. When a session exists it shows who is
// signed in, with one button to carry on to their own page and one to sign
// out and use the form below.
$__sessionOwner = null;
if (isLoggedIn()) {
    $__name = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
    $__roleLabels = [
        'driver'      => 'Delivery Portal',
        'admin'       => 'Manager',
        'manager'     => 'Manager',
        'super-admin' => 'Owner',
        'owner'       => 'Owner',
        'customer'    => 'Customer',
    ];
    $__role = (string)getUserRole();
    $__sessionOwner = [
        'name'  => $__name !== '' ? $__name : ($_SESSION['email'] ?? 'this account'),
        'role'  => $__roleLabels[$__role] ?? $__role,
        'home'  => getUserHomepage($__role),
        'isDriver' => $__role === 'driver',
    ];
}

$pageTitle = 'Delivery Personnel Login - TechWorld';
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
		/* Same visual system as the Manager Portal login (admin/index.php's
		   .mgr-* classes) - page-scoped here too since that page keeps its
		   own copy rather than a shared stylesheet. Only the accent color
		   (blue/teal instead of the manager cyan/blue gradient) and copy
		   change, so the two portals still read as visually related but
		   distinguishable at a glance. */
		body.logistics-login-bg {
			background: radial-gradient(circle at 50% 20%, #0f2a3a 0%, #0a1a24 60%, #06111a 100%);
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
		.mgr-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
		.mgr-logo img { height: 36px; width: auto; }
		.mgr-logo h2 { color: #fff; font-size: 20px; font-weight: 800; letter-spacing: 0.5px; margin: 0; }
		.mgr-portal-badge {
			background: rgba(56, 189, 248, 0.12);
			color: #38bdf8;
			border: 1px solid rgba(56, 189, 248, 0.3);
			padding: 6px 14px;
			border-radius: 20px;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.5px;
			display: flex;
			align-items: center;
			gap: 6px;
		}
		.mgr-main-container { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
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
			top: 0; left: 0; right: 0;
			height: 4px;
			background: linear-gradient(90deg, #38bdf8, #0ea5e9, #0284c7);
		}
		.mgr-card-header { text-align: center; margin-bottom: 30px; }
		.mgr-card-header h2 { font-size: 26px; font-weight: 800; color: #f8fafc; margin: 0 0 8px 0; letter-spacing: -0.3px; }
		.mgr-card-header p { color: #94a3b8; font-size: 14px; margin: 0; }
		/* "already signed in" notice - only rendered when a session exists */
		.mgr-signed-in {
			background: rgba(56, 189, 248, 0.08);
			border: 1px solid rgba(56, 189, 248, 0.28);
			border-radius: 12px;
			padding: 14px 16px;
			margin-bottom: 22px;
		}
		.mgr-signed-in-who { margin: 0 0 12px; color: #cbd5e1; font-size: 13px; line-height: 1.5; }
		.mgr-signed-in-who strong { color: #f8fafc; }
		.mgr-signed-in-who span { color: #94a3b8; }
		.mgr-signed-in-who i { color: #38bdf8; margin-right: 6px; }
		.mgr-signed-in-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
		.mgr-signed-in-go {
			flex: 1 1 auto; text-align: center;
			background: rgba(56, 189, 248, 0.16);
			border: 1px solid rgba(56, 189, 248, 0.4);
			color: #e0f2fe; text-decoration: none;
			padding: 10px 14px; border-radius: 8px;
			font-size: 13px; font-weight: 700;
		}
		.mgr-signed-in-go:hover { background: rgba(56, 189, 248, 0.26); color: #fff; }
		.mgr-signed-in-switch { color: #94a3b8; text-decoration: none; font-size: 12.5px; }
		.mgr-signed-in-switch:hover { color: #38bdf8; text-decoration: underline; }

		.mgr-input-group { position: relative; margin-bottom: 20px; }
		.mgr-input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 16px; pointer-events: none; transition: color 0.2s ease; }
		.mgr-input {
			width: 100% !important; height: 50px !important;
			background: rgba(30, 41, 59, 0.7) !important;
			border: 1.5px solid #334155 !important;
			border-radius: 10px !important;
			padding: 0 44px 0 42px !important;
			color: #f8fafc !important; font-size: 14px !important;
			box-sizing: border-box !important;
			transition: border-color 0.2s, box-shadow 0.2s !important;
		}
		.mgr-input:focus {
			border-color: #38bdf8 !important; outline: none !important;
			box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15) !important;
			background: rgba(30, 41, 59, 0.95) !important;
		}
		.mgr-input:focus + .mgr-input-icon, .mgr-input-group:focus-within .mgr-input-icon { color: #38bdf8; }
		.mgr-pw-toggle {
			position: absolute !important; right: 12px !important; top: 50% !important; transform: translateY(-50%) !important;
			background: transparent !important; border: none !important; color: #64748b !important; cursor: pointer !important;
			padding: 6px !important; font-size: 16px !important; display: flex !important; align-items: center !important; justify-content: center !important;
			transition: color 0.2s ease !important;
		}
		.mgr-pw-toggle:hover { color: #38bdf8 !important; }
		.mgr-submit-btn {
			width: 100% !important; height: 50px !important;
			background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%) !important;
			color: #fff !important; font-weight: 700 !important; font-size: 15px !important;
			border: none !important; border-radius: 10px !important; cursor: pointer !important;
			letter-spacing: 0.5px !important;
			box-shadow: 0 10px 25px rgba(14, 165, 233, 0.35) !important;
			transition: transform 0.2s, box-shadow 0.2s !important;
			margin-top: 10px !important;
		}
		.mgr-submit-btn:hover { transform: translateY(-2px) !important; box-shadow: 0 14px 30px rgba(14, 165, 233, 0.5) !important; }
		.mgr-footer-links { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; font-size: 13px; }
		.mgr-footer-links a { color: #38bdf8; text-decoration: none; transition: color 0.2s; }
		.mgr-footer-links a:hover { color: #7dd3fc; text-decoration: underline; }
		.mgr-footer { background: rgba(15, 23, 42, 0.9); border-top: 1px solid rgba(255, 255, 255, 0.06); padding: 16px 0; text-align: center; font-size: 13px; color: #64748b; }
		.notification-banner { padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 20px; text-align: center; }
		.notification-error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid #ef4444; }

		/* Mobile (<=480px): the header row (logo + "DELIVERY PORTAL" badge)
		   is wide enough at full size to overflow a 320-375px phone in a
		   single row - let it wrap and shrink instead. The login card itself
		   loses its large desktop gutters so it sits naturally on a small
		   screen without wasting vertical space, while inputs/button stay at
		   their existing 50px touch-friendly height (already compliant). */
		@media (max-width: 600px) {
			.mgr-header-inner { flex-wrap: wrap; justify-content: center; gap: 8px; padding: 0 16px; text-align: center; }
			.mgr-logo h2 { font-size: 17px; }
			.mgr-logo img { height: 30px; }
			.mgr-portal-badge { font-size: 11px; padding: 5px 12px; }
			/* Card top-aligned rather than dead-centered so it doesn't jump
			   under the keyboard, and edge-to-edge with no wasted gutter. */
			.mgr-main-container { padding: 24px 14px 32px; align-items: flex-start; }
			.mgr-login-card { padding: 26px 18px; border-radius: 16px; max-width: 100%; }
			.mgr-card-header { margin-bottom: 22px; }
			.mgr-card-header h2 { font-size: 21px; }
			.mgr-card-header p { font-size: 13px; }
			.mgr-input, .mgr-submit-btn { height: 52px !important; }
			.mgr-footer { padding: 14px 0; }
		}
	</style>
</head>
<body class="logistics-login-bg">

	<header class="mgr-header">
		<div class="mgr-header-inner">
			<a href="<?= WEB_ROOT ?>public/guest/index.php" class="mgr-logo">
				<img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="TechWorld Logo">
				<h2>TECHWORLD</h2>
			</a>
			<div class="mgr-portal-badge">
				<span>🚚</span> DELIVERY PORTAL
			</div>
		</div>
	</header>

	<main class="mgr-main-container">
		<div class="mgr-login-card">
			<div class="mgr-card-header">
				<h2>Delivery Personnel Sign In</h2>
				<p>Enter your account credentials to access the delivery portal</p>
			</div>

			<?php if ($__sessionOwner): ?>
				<!-- A session already exists. Shown instead of a silent redirect
				     so this URL stays reachable - see the note at the top of
				     this file. The form below stays usable: signing in with
				     different courier credentials simply replaces the session. -->
				<div class="mgr-signed-in">
					<p class="mgr-signed-in-who">
						<i class="fi fi-rr-user"></i>
						Signed in as <strong><?= htmlspecialchars($__sessionOwner['name']) ?></strong>
						<span>(<?= htmlspecialchars($__sessionOwner['role']) ?>)</span>
					</p>
					<div class="mgr-signed-in-actions">
						<a class="mgr-signed-in-go" href="<?= htmlspecialchars($__sessionOwner['home']) ?>">
							<?= $__sessionOwner['isDriver'] ? 'Continue to Courier Dispatch' : 'Continue to my dashboard' ?> →
						</a>
						<a class="mgr-signed-in-switch" href="<?= WEB_ROOT ?>public/authenticated/admin/logout.php?return=logistics">
							Sign out &amp; use another account
						</a>
					</div>
				</div>
			<?php endif; ?>

			<div id="notification" style="display:none;"></div>

			<form id="logisticsLoginForm" action="<?= WEB_ROOT ?>public/authenticated/admin/logistics_login.php" method="POST">
				<?= csrf_field() ?>

				<div class="mgr-input-group">
					<input type="email" name="email" class="mgr-input" placeholder="Email Address" required autocomplete="email">
					<i class="fi fi-rr-envelope mgr-input-icon"></i>
				</div>

				<div class="mgr-input-group">
					<input type="password" id="logisticsLoginPassword" name="password" class="mgr-input" placeholder="Password" required autocomplete="current-password">
					<i class="fi fi-rr-lock mgr-input-icon"></i>
					<button type="button" id="toggleLogisticsLoginPw" class="mgr-pw-toggle" onclick="togglePassword('logisticsLoginPassword', this)" title="Show/Hide Password">
						<i class="fi fi-rr-eye"></i>
					</button>
				</div>

				<button type="submit" class="mgr-submit-btn">SIGN IN →</button>

				<div class="mgr-footer-links" style="justify-content:center;">
					<a href="<?= WEB_ROOT ?>public/guest/index.php" style="color:#94a3b8;">← Back to Store</a>
				</div>
			</form>
		</div>
	</main>

	<footer class="mgr-footer">
		<div class="container">
			&copy; <?= date('Y') ?> TechWorld • Authorized Delivery Personnel Access
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
				button.title = 'Hide Password';
			} else {
				input.type = 'password';
				if (icon) icon.className = 'fi fi-rr-eye';
				button.title = 'Show Password';
			}
		}

		document.addEventListener('DOMContentLoaded', function(){
			const form = document.getElementById('logisticsLoginForm');
			if(!form) return;

			form.addEventListener('submit', function(e){
				e.preventDefault();
				const fd = new FormData(form);
				const btn = form.querySelector('button[type=submit]');
				const original = btn.textContent;
				btn.disabled = true;
				btn.textContent = 'Verifying Credentials...';

				fetch('<?= WEB_ROOT ?>public/authenticated/admin/logistics_login.php', {
					method:'POST',
					headers:{'X-Requested-With':'XMLHttpRequest'},
					body: fd
				})
				.then(r => r.json())
				.then(d => {
					if (d.success) {
						const fallback = '<?= WEB_ROOT ?>public/authenticated/admin/Delivery_Report.php';
						location.href = d.redirect || fallback;
					} else {
						showNotification(d.message || 'Invalid credentials.', 'error');
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
