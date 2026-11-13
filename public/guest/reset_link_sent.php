<?php
require_once __DIR__ . '/../../config/functions.php';
$email = $_GET['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Check your email • TechWorld</title>
	<link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
</head>
<body class="auth-page" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:0;">
    <div class="container" style="width:100%;display:flex;align-items:center;justify-content:center;">
        <div class="modal-content" style="position:static;max-width:520px;margin:0 auto;padding:28px 26px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.25);">
            <div class="auth-container">
                <div class="auth-form active" style="text-align:center;">
                    <div style="width:68px;height:68px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#174871,#a77693);color:#0b1220;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="m22 7-10 6L2 7"/></svg>
                    </div>
                    <h2 style="margin-bottom:8px;">Check your email</h2>
                    <p style="opacity:.9;margin-bottom:4px;">We sent a 6‑digit verification code to</p>
                    <p style="font-weight:600;color:var(--accent-cyan);margin-bottom:14px;"><?= htmlspecialchars($email ?: 'your email') ?></p>
                    <p style="opacity:.85;margin-bottom:20px;">Enter the code on the next screen to create a new password.</p>

                    <a class="auth-btn" href="<?= WEB_ROOT ?>public/guest/verify_code.php" style="display:inline-block;margin-bottom:10px;">Enter code</a>
                    <p id="resend-status" style="margin:6px 0 0;opacity:.9;"></p>
                    <p class="auth-link" style="margin:10px 0 0;"><a href="#" id="resend-link">Resend code</a></p>
                    <p class="auth-link" style="margin:6px 0 0;"><a href="<?= WEB_ROOT ?>public/guest/index.php#login">Back to login</a></p>
                </div>
            </div>
        </div>
    </div>
<script>
(function(){
    var resendLink = document.getElementById('resend-link');
    var statusEl = document.getElementById('resend-status');
    if (!resendLink) return;
    var email = '<?= htmlspecialchars($email, ENT_QUOTES) ?>';
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    function setStatus(msg, color){
        statusEl.textContent = msg || '';
        statusEl.style.color = color || '';
    }
    resendLink.addEventListener('click', function(e){
        e.preventDefault();
        if (!email) { setStatus('Missing email.', '#f87171'); return; }
        resendLink.style.pointerEvents = 'none';
        setStatus('Sending code…', '#c9a3b5');
        fetch('<?= WEB_ROOT ?>public/guest/send_code.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({ email: email, csrf_token: csrf }).toString()
        })
        .then(function(r){ return r.json().catch(function(){ return { success:false, message:'Unexpected response' }; }); })
        .then(function(res){
            if (res && res.success) {
                setStatus('A new code was sent. Check your inbox.', '#34d399');
            } else {
                setStatus(res && res.message ? res.message : 'Could not resend code. Try again.', '#f87171');
            }
        })
        .catch(function(){ setStatus('Network error. Please try again.', '#f87171'); })
        .finally(function(){ setTimeout(function(){ resendLink.style.pointerEvents = ''; }, 1200); });
    });
})();
</script>
</body>
</html>


