<?php
require_once __DIR__ . '/../../config/functions.php';

$pdo = getConnection();
$errorMessage = null;
$statusMessage = null;

// Expire existing reset authorization if past due
if (isset($_SESSION['pw_reset_expires_at']) && time() > (int)$_SESSION['pw_reset_expires_at']) {
	unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_expires_at']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!check_csrf(false)) {
		$errorMessage = 'Invalid request.';
	} else {
        // Use remembered email from session to avoid re-typing
        $email = trim(strtolower($_SESSION['pw_reset_email'] ?? ''));
		$code = trim($_POST['code'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please start again and enter your email.';
		} elseif (!preg_match('/^\d{6}$/', $code)) {
			$errorMessage = 'Enter the 6-digit code we emailed you.';
		} else {
			$pdo->beginTransaction();
			try {
				// Resolve user id
				$st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
				$st->execute([$email]);
				$userId = $st->fetchColumn();
				if (!$userId) { $pdo->rollBack(); $errorMessage = 'Invalid email or code.'; }
				else {
					// Look at recent, unused reset entries to handle out-of-order email delivery
					$sel = $pdo->prepare('SELECT id, token, attempts, expires_at FROM password_resets WHERE user_id = ? AND used_at IS NULL AND created_at > (NOW() - INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 10 FOR UPDATE');
					$sel->execute([$userId]);
					$rows = $sel->fetchAll();
					if (!$rows) { $pdo->rollBack(); $errorMessage = 'The code is invalid or expired.'; }
					else {
						$attempts = (int)($rows[0]['attempts'] ?? 0);
						if ($attempts >= 5) {
							$pdo->rollBack();
							$errorMessage = 'Too many attempts. Request a new code.';
						} else {
							$matchedRow = null;
							foreach ($rows as $r) {
								if (password_verify($code, $r['token'])) { $matchedRow = $r; break; }
							}
							if (!$matchedRow) {
								// increment attempts on the latest row to throttle brute force
								try { $inc = $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1, last_attempt_at = NOW() WHERE id = ?'); $inc->execute([$rows[0]['id']]); } catch (Throwable $e) {}
								$pdo->commit();
								$errorMessage = 'Invalid code. Please try again.';
							} elseif (strtotime($matchedRow['expires_at']) <= time()) {
								$pdo->rollBack();
								$errorMessage = 'The code is invalid or expired.';
							} else {
								// Mark used and grant short-lived authorization (15 minutes)
								$mark = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
								$mark->execute([$matchedRow['id']]);
								$pdo->commit();
								$_SESSION['pw_reset_user_id'] = (int)$userId;
								$_SESSION['pw_reset_expires_at'] = time() + (15 * 60);
								// If the request is AJAX, return JSON so modal can progress without full page load
								if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
									header('Content-Type: application/json');
									echo json_encode(['success' => true, 'redirect' => WEB_ROOT . 'public/guest/reset_password.php']);
									exit;
								}
								header('Location: ' . WEB_ROOT . 'public/guest/reset_password.php');
								exit;
							}
						}
					}
				}
			} catch (Throwable $e) {
				if ($pdo->inTransaction()) { $pdo->rollBack(); }
				$errorMessage = 'Something went wrong. Please try again.';
			}
		}
	}
	if ($errorMessage && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
		header('Content-Type: application/json');
		echo json_encode(['success' => false, 'message' => $errorMessage]);
		exit;
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Verify code • TechWorld</title>
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
					<h2>Enter verification code</h2>
					<p>Check your email for the 6‑digit code</p>
                    <form id="verify-reset-form" method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="code" id="resetPageOtpHidden">
                        <div class="otp-group" aria-label="6 digit verification code" role="group" style="display:flex;gap:10px;justify-content:center;margin:8px 0 12px">
                            <input class="otp-input" inputmode="numeric" aria-label="Digit 1" maxlength="1" pattern="\d">
                            <input class="otp-input" inputmode="numeric" aria-label="Digit 2" maxlength="1" pattern="\d">
                            <input class="otp-input" inputmode="numeric" aria-label="Digit 3" maxlength="1" pattern="\d">
                            <input class="otp-input" inputmode="numeric" aria-label="Digit 4" maxlength="1" pattern="\d">
                            <input class="otp-input" inputmode="numeric" aria-label="Digit 5" maxlength="1" pattern="\d">
                            <input class="otp-input" inputmode="numeric" aria-label="Digit 6" maxlength="1" pattern="\d">
                        </div>
                        <button type="submit" class="auth-btn" style="display:none;">Continue</button>
                    </form>
					<p class="auth-link"><a href="<?= WEB_ROOT ?>public/guest/forgot_password.php">Resend code</a></p>
					<p class="auth-link"><a href="<?= WEB_ROOT ?>public/guest/index.php#login">Back to login</a></p>
				</div>
			</div>
		</div>
	</div>
</body>
<script>
(function(){
  function wireOtp(containerSelector, hiddenSelector){
    var inputs = Array.prototype.slice.call(document.querySelectorAll(containerSelector+' .otp-input'));
    var hidden = document.querySelector(hiddenSelector);
    if(!inputs.length || !hidden) return;
    function setHidden(){ hidden.value = inputs.map(function(i){ return i.value || ''; }).join(''); }
    inputs.forEach(function(inp, idx){
      inp.addEventListener('input', function(){
        var v = inp.value
          .replace(/[\u0660-\u0669]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x0660+48); })
          .replace(/[\u06F0-\u06F9]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x06F0+48); })
          .replace(/[^0-9]/g,'');
        inp.value = v.slice(0,1);
        if(v && idx<inputs.length-1){ inputs[idx+1].focus(); inputs[idx+1].select(); }
        setHidden();
        
        // Auto-submit when all 6 digits are entered
        var allFilled = inputs.every(function(input) { return input.value.length === 1; });
        if(allFilled) {
          var form = document.getElementById('verify-reset-form');
          if(form) {
            setTimeout(function() { form.submit(); }, 100); // Small delay to ensure all inputs are processed
          }
        }
      });
      inp.addEventListener('keydown', function(e){
        if(e.key==='Backspace' && !inp.value && idx>0){ inputs[idx-1].focus(); inputs[idx-1].select(); }
        if(e.key==='ArrowLeft' && idx>0){ e.preventDefault(); inputs[idx-1].focus(); }
        if(e.key==='ArrowRight' && idx<inputs.length-1){ e.preventDefault(); inputs[idx+1].focus(); }
      });
      inp.addEventListener('paste', function(e){
        var txt=(e.clipboardData||window.clipboardData).getData('text')||''; if(!txt) return; e.preventDefault();
        var digits = txt
          .replace(/[\u0660-\u0669]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x0660+48); })
          .replace(/[\u06F0-\u06F9]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x06F0+48); })
          .replace(/[^0-9]/g,'')
          .slice(0,6).split('');
        for(var i=0;i<inputs.length;i++){ inputs[i].value = digits[i] || ''; }
        setHidden();
        (inputs[Math.min(digits.length,6)-1]||inputs[0]).focus();
        
        // Auto-submit if 6 digits were pasted
        if(digits.length === 6) {
          var form = document.getElementById('verify-reset-form');
          if(form) {
            setTimeout(function() { form.submit(); }, 100);
          }
        }
      });
    });
    inputs[0].focus();
  }
  wireOtp('.otp-group','#resetPageOtpHidden');
})();
</script>
</html>


