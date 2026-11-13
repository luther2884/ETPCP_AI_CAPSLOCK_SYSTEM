<?php
require_once __DIR__ . '/../../config/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Password reset successful • TechWorld</title>
	<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
</head>
<body class="auth-page" style="padding:40px 0;">
	<div class="container">
		<div class="modal-content" style="position:static;max-width:520px;margin:0 auto;">
			<div class="auth-container">
				<div class="auth-form active" style="text-align:center;">
					<h2>Woo hoo!</h2>
					<p>Your password has been reset successfully.</p>
					<a class="auth-btn" href="<?= WEB_ROOT ?>public/guest/index.php#login">Login</a>
				</div>
			</div>
		</div>
	</div>
</body>
</html>


