<?php
require_once __DIR__ . '/../../config/functions.php';

$errorMessage = null;
$statusMessage = null;

// "Back to login" - abandon the half-finished login. This used to point at
// authenticated/auth/logout.php, which calls session_start() under PHP's
// default session name before functions.php can route it, so it cleared the
// default bucket and left this page's TECHWRLD_CUST session (logged_in +
// user_id, set before the 2FA step) intact. The guest homepage then saw a
// signed-in customer and bounced straight back, which is why the link looked
// dead. Clearing the pending keys here - the same set the expiry branch below
// clears - drops the half-login in the right session with no stray audit entry
// for a logout that never happened.
if (isset($_GET['cancel'])) {
    unset($_SESSION['login_2fa_code'], $_SESSION['login_2fa_expires'], $_SESSION['login_2fa_verified'], $_SESSION['login_2fa_last_sent']);
    unset($_SESSION['logged_in'], $_SESSION['user_id'], $_SESSION['firstname'], $_SESSION['lastname'], $_SESSION['email'], $_SESSION['user_role']);
    header('Location: ' . WEB_ROOT . 'public/guest/index.php?modal=login');
    exit;
}

// Ensure user initiated login and OTP is not expired
if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ' . WEB_ROOT . 'public/guest/index.php?modal=login');
    exit;
}

// Check if OTP has expired before processing
if (isset($_SESSION['login_2fa_expires']) && time() > (int)$_SESSION['login_2fa_expires']) {
    // Clear expired session data and redirect to login
    unset($_SESSION['login_2fa_code'], $_SESSION['login_2fa_expires'], $_SESSION['login_2fa_verified'], $_SESSION['login_2fa_last_sent']);
    unset($_SESSION['logged_in'], $_SESSION['user_id'], $_SESSION['firstname'], $_SESSION['lastname'], $_SESSION['email'], $_SESSION['user_role']);
    header('Location: ' . WEB_ROOT . 'public/guest/index.php?modal=login&expired=1');
    exit;
}

// Handle resend via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    header('Content-Type: application/json');
    if (!check_csrf(false)) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }
    try{
        $now = time();
        $lastSent = (int)($_SESSION['login_2fa_last_sent'] ?? 0);
        if ($now - $lastSent < 10) {
            echo json_encode(['success'=>false,'message'=>'Please wait a few seconds before requesting a new code.']);
            exit;
        }
        // Reuse the existing unexpired code instead of minting a new one, so an
        // already-delivered email doesn't get silently invalidated by a resend.
        $hasUnexpired = isset($_SESSION['login_2fa_expires']) && $now < (int)$_SESSION['login_2fa_expires'] && !empty($_SESSION['login_2fa_code']);
        if ($hasUnexpired) {
            $code = (string)$_SESSION['login_2fa_code'];
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['login_2fa_code'] = $code;
            $_SESSION['login_2fa_expires'] = $now + (5 * 60);
        }
        $_SESSION['login_2fa_last_sent'] = $now;
        $email = $_SESSION['email'] ?? '';
        $fullName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . $host . rtrim(WEB_ROOT, '/');
        $subject = 'Your TechWorld login verification code';
        $body = '<p>Hello ' . htmlspecialchars($fullName ?: $email) . ',</p>' .
                '<div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#123554;color:#a77693;padding:14px 18px;display:inline-block;border-radius:10px">' . htmlspecialchars($code) . '</div>' .
                '<p style="margin-top:16px;">This code expires in 5 minutes.</p>' .
                '<p>You can enter it here: <a href="' . htmlspecialchars($baseUrl . '/public/guest/verify_login_code.php') . '" target="_blank" rel="noopener">' . htmlspecialchars($baseUrl . '/public/guest/verify_login_code.php') . '</a></p>' .
                '<p>— TechWorld</p>';
        @sendEmail($email, $fullName, $subject, $body);
        echo json_encode(['success'=>true,'message'=>'A new code was sent.']);
    }catch(Throwable $e){ echo json_encode(['success'=>false,'message'=>'Could not resend code.']); }
    exit;
}

// Handle verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf(false)) { $errorMessage = 'Invalid request.'; }
    else {
        $code = trim($_POST['code'] ?? '');
        $expected = $_SESSION['login_2fa_code'] ?? null;
        $expires = (int)($_SESSION['login_2fa_expires'] ?? 0);
        if (!preg_match('/^\d{6}$/', $code)) {
            $errorMessage = 'Enter the 6-digit code we emailed you.';
        } elseif (!$expected || time() > $expires) {
            // Clear expired session data and redirect to login
            unset($_SESSION['login_2fa_code'], $_SESSION['login_2fa_expires'], $_SESSION['login_2fa_verified']);
            unset($_SESSION['logged_in'], $_SESSION['user_id'], $_SESSION['firstname'], $_SESSION['lastname'], $_SESSION['email'], $_SESSION['user_role']);
            header('Location: ' . WEB_ROOT . 'public/guest/index.php?modal=login&expired=1');
            exit;
        } elseif ($code !== $expected) {
            $errorMessage = 'Invalid code. Please try again.';
        } else {
            $_SESSION['login_2fa_verified'] = true;
            $_SESSION['promo_modal_pending'] = true;
            unset($_SESSION['login_2fa_code'], $_SESSION['login_2fa_expires'], $_SESSION['login_2fa_last_sent']);
            // Set remember-2FA cookie for 5 minutes
            try{
                $pdo = getConnection();
                $st = $pdo->prepare('SELECT password FROM users WHERE id = ?');
                $st->execute([ (int)($_SESSION['user_id'] ?? 0) ]);
                $hash = (string)$st->fetchColumn();
                if ($hash) { setRemember2faCookie((int)$_SESSION['user_id'], $hash, 300); }
            } catch (Throwable $e) { /* ignore */ }
            $redirect = rtrim(getUserHomepage('customer'), '/');
            if (strpos($redirect, '?') === false) { $redirect .= '?login=success'; }
            else { $redirect .= '&login=success'; }
            header('Location: ' . $redirect);
            exit;
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
    <title>Verify Login • TechWorld</title>
    <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
    <meta name="csrf-token" content="<?= csrf_token() ?>">

    <?php /* Figtree + JetBrains Mono, the pair the landing page loads - this
             screen sits in the middle of that page's login, so it should not
             arrive in a different typeface. */ ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

    <style>
    /* =====================================================================
       This page used to render plain white, and the reason is worth writing
       down: `.auth-page` has no rules in style.css at all, and `.modal-content`
       is only styled as `.modal > .modal-content` - a parent this card does not
       have. So the markup asked for two classes that style nothing, got the
       browser's default white, and the OTP boxes (white text on a white-alpha
       fill, written for a dark ground) all but vanished on it.

       The palette below is the landing page's own - the same values
       techworld-anim.css declares on .tw-home - copied rather than linked:
       that sheet is 100KB of hero, carousel and bento rules for a page this
       one shares nothing with but its colours. Names are kept identical so the
       lineage is obvious if the palette ever moves.

       Deliberately dark in both OS themes. The login modal this screen
       continues is on the dark landing ground, and a one-minute code screen
       that flashes white between two dark pages is the thing being fixed.
       ================================================================== */
    body.auth-page {
      --tw-bg: #060d18;
      --tw-bg-2: #0a1424;
      --tw-bg-3: #16283f;
      --tw-ink: #e8eef7;
      --tw-ink-dim: #a9bcd4;
      --tw-ink-faint: #7f95b3;
      --tw-cyan: #67e8f9;
      --tw-pink: #e0567a;
      --tw-line: rgba(120, 170, 230, .12);
      --tw-panel-grad: linear-gradient(180deg, rgba(20, 36, 60, .85), rgba(12, 23, 39, .85));
      --tw-font: 'Figtree', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      --tw-mono: 'JetBrains Mono', ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;

      /* color-scheme, not just a background: it is what makes the scrollbar,
         the caret and the browser's own autofill render dark too. */
      color-scheme: dark;
      background: radial-gradient(1200px 700px at 70% -10%, var(--tw-bg-3) 0%, var(--tw-bg-2) 45%, var(--tw-bg) 100%);
      background-attachment: fixed;
      /* style.css gives every body a 20s bgDrift that pans background-position.
         That is for its own oversized gradient; here it would slide the glow
         out from behind the card while someone is typing a code. The shorthand
         below replaces it (it is not an extra animation on top of it).

         This is the receiving half of the login handoff: .auth-handoff in
         style.css paints these exact values over the modal before navigating,
         so the ground is already on screen when this document arrives and only
         the card has to appear. Fading the whole body would re-fade a
         background that never changed - and a body-wide opacity animation
         creates a containing block that breaks background-attachment: fixed
         mid-fade. So the body still animates nothing; the entrance lives on
         the card below. */
      animation: none;
      color: var(--tw-ink);
      font-family: var(--tw-font);
    }

    /* The card. Same panel treatment as the landing page's own surfaces -
       a translucent gradient over the ground rather than a flat grey block,
       so it reads as part of the same site. */
    body.auth-page .modal-content {
      background: var(--tw-panel-grad);
      border: 1px solid var(--tw-line);
      border-radius: 18px;
      box-shadow: 0 30px 80px rgba(0, 0, 0, .55);
      padding: 30px 28px;
      backdrop-filter: blur(6px);
      /* Settles in rather than cutting in: the overlay that covered the
         navigation had its own centred card, and this one rising the last few
         pixels into its place is what makes the two screens read as one step.
         `both` holds the opening frame, so the card cannot flash at full
         opacity before the animation starts. */
      animation: vlcCardIn .42s cubic-bezier(.22, .61, .36, 1) both;
    }

    @keyframes vlcCardIn {
      from { opacity: 0; transform: translateY(12px) scale(.985); }
      to   { opacity: 1; transform: none; }
    }

    /* The OTP boxes are the point of the screen, so they arrive last and
       individually - a quick left-to-right settle that also happens to walk
       the eye to the first box it should type in. */
    body.auth-page .auth-form .otp-input {
      animation: vlcDigitIn .34s cubic-bezier(.22, .61, .36, 1) both;
      animation-delay: .2s;
    }
    /* Staggered off nth-child rather than a --i custom property on each input,
       so the markup stays six plain inputs with nothing to keep in sync. */
    body.auth-page .auth-form .otp-input:nth-child(2) { animation-delay: .245s; }
    body.auth-page .auth-form .otp-input:nth-child(3) { animation-delay: .29s; }
    body.auth-page .auth-form .otp-input:nth-child(4) { animation-delay: .335s; }
    body.auth-page .auth-form .otp-input:nth-child(5) { animation-delay: .38s; }
    body.auth-page .auth-form .otp-input:nth-child(6) { animation-delay: .425s; }

    @keyframes vlcDigitIn {
      from { opacity: 0; transform: translateY(8px); }
      to   { opacity: 1; transform: none; }
    }

    body.auth-page h2 {
      color: var(--tw-ink);
      font-weight: 800;
      letter-spacing: -.01em;
    }
    body.auth-page .auth-form p { color: var(--tw-ink-dim); }
    /* The address the code went to - the one thing on this screen worth
       checking before typing, so it gets the accent and the mono face. */
    body.auth-page .vlc-email {
      color: var(--tw-cyan);
      font-family: var(--tw-mono);
      font-size: 13.5px;
      overflow-wrap: anywhere;
    }
    body.auth-page #resend-status { color: var(--tw-ink-faint); font-size: 13px; min-height: 1.2em; }

    body.auth-page .auth-link a { color: var(--tw-cyan); text-decoration: none; font-weight: 600; }
    body.auth-page .auth-link a:hover { text-decoration: underline; }

    body.auth-page .notification {
      border-radius: 12px;
      padding: 11px 14px;
      font-size: 13.5px;
      line-height: 1.5;
    }

    .otp-group{display:flex;gap:8px;justify-content:center;margin:14px 0 2px}
    /* Scoped under .auth-form, not left as a bare `.otp-input`: style.css styles
       `.auth-form input` (0,1,1) and that beat the plain class (0,1,0), so these
       boxes were being handed --input-text (#17324a) - a dark navy typed onto a
       dark fill, i.e. digits you cannot see - along with its width:100% and
       18px padding. Matching the sheet's specificity puts the intended box back. */
    body.auth-page .auth-form .otp-input{
      width:46px;height:54px;border-radius:12px;
      margin:0;padding:0;
      border:1px solid rgba(120,170,230,.22);
      background:rgba(255,255,255,.04);
      color:#ffffff;
      -webkit-text-fill-color:#ffffff; /* beats autofill/UA overrides on WebKit */
      caret-color:var(--tw-cyan,#67e8f9);
      font-family:var(--tw-mono);font-size:22px;font-weight:700;
      text-align:center;outline:none;
      transition:border-color .15s,box-shadow .15s,background .15s;
    }
    /* A box with a digit in it looks different from one waiting for one -
       at six boxes, that is how you find your place without counting. */
    body.auth-page .auth-form .otp-input:not(:placeholder-shown){ background:rgba(103,232,249,.08); border-color:rgba(103,232,249,.35); }
    body.auth-page .auth-form .otp-input:focus{ border-color:var(--tw-cyan,#67e8f9); box-shadow:0 0 0 3px rgba(103,232,249,.18); }
    @media (max-width:420px){ .otp-group{gap:6px} body.auth-page .auth-form .otp-input{width:42px;height:50px;font-size:20px} }
    @media (prefers-reduced-motion: reduce){
      body.auth-page .auth-form .otp-input{transition:none}
      /* The entrance is garnish; the dark ground is the fix. Drop the movement,
         keep everything at its final state. */
      body.auth-page .modal-content,
      body.auth-page .auth-form .otp-input { animation: none; }
    }
    </style>
    </head>
<body class="auth-page" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:0;">
    <div class="container" style="width:100%;display:flex;align-items:center;justify-content:center;">
        <?php /* Layout stays inline; everything visual moved into the sheet
                 above, where it can be read next to the rest of the palette
                 (and where an inline box-shadow could not override it). */ ?>
        <div class="modal-content" style="position:static;max-width:520px;margin:0 auto;">
            <div class="auth-container">
                <div class="auth-form active" style="text-align:center;">
                    <h2 style="margin-bottom:8px;">Verify your login</h2>
                    <p style="opacity:.9;margin-bottom:4px;">We sent a 6‑digit code to</p>
                    <p class="vlc-email" style="font-weight:600;margin-bottom:14px;"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></p>
                    <?php if($errorMessage): ?><div class="notification" style="margin-bottom:10px;color:#fca5a5;border:1px solid rgba(248,113,113,.45);background:rgba(248,113,113,.12);"><?= htmlspecialchars($errorMessage) ?></div><?php endif; ?>
                    <form id="verify-login-form" method="POST" action="" style="margin-top:10px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="code" id="otpHidden">
                        <div class="otp-group" aria-label="6 digit verification code" role="group">
                            <input class="otp-input" placeholder=" " inputmode="numeric" autocomplete="one-time-code" aria-label="Digit 1" maxlength="1" pattern="\d">
                            <input class="otp-input" placeholder=" " inputmode="numeric" aria-label="Digit 2" maxlength="1" pattern="\d">
                            <input class="otp-input" placeholder=" " inputmode="numeric" aria-label="Digit 3" maxlength="1" pattern="\d">
                            <input class="otp-input" placeholder=" " inputmode="numeric" aria-label="Digit 4" maxlength="1" pattern="\d">
                            <input class="otp-input" placeholder=" " inputmode="numeric" aria-label="Digit 5" maxlength="1" pattern="\d">
                            <input class="otp-input" placeholder=" " inputmode="numeric" aria-label="Digit 6" maxlength="1" pattern="\d">
                        </div>
                        <button type="submit" class="auth-btn" style="display:none;">Verify</button>
                    </form>
                    <p id="resend-status" style="margin:6px 0 0;opacity:.9;"></p>
                    <p class="auth-link" style="margin:6px 0 0;"><a href="#" id="resend-link">Resend code</a></p>
                    <p class="auth-link" style="margin:6px 0 0;"><a href="?cancel=1">Back to login</a></p>
                </div>
            </div>
        </div>
    </div>
<script>
(function(){
  // OTP inputs behavior
  var form = document.getElementById('verify-login-form');
  var hidden = document.getElementById('otpHidden');
  var inputs = Array.prototype.slice.call(document.querySelectorAll('.otp-input'));
  function onlyDigit(ch){ return /\d/.test(ch); }
  function setHidden(){ hidden.value = inputs.map(function(i){ return i.value || ''; }).join(''); }
  inputs.forEach(function(inp, idx){
    inp.addEventListener('input', function(e){
      var v = inp.value;
      // Normalize non-ASCII numerals to ASCII 0-9
      v = v.replace(/[\u0660-\u0669]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x0660+48); });
      v = v.replace(/[\u06F0-\u06F9]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x06F0+48); });
      v = v.replace(/[^0-9]/g,'');
      inp.value = v.slice(0,1);
      if(v && idx < inputs.length-1){ inputs[idx+1].focus(); inputs[idx+1].select(); }
      setHidden();
      
      // Auto-submit when all 6 digits are entered
      var allFilled = inputs.every(function(input) { return input.value.length === 1; });
      if(allFilled && form) {
        setTimeout(function() { form.submit(); }, 100); // Small delay to ensure all inputs are processed
      }
    });
    inp.addEventListener('keydown', function(e){
      if(e.key === 'Backspace' && !inp.value && idx>0){ inputs[idx-1].focus(); inputs[idx-1].select(); }
      if(e.key === 'ArrowLeft' && idx>0){ e.preventDefault(); inputs[idx-1].focus(); }
      if(e.key === 'ArrowRight' && idx<inputs.length-1){ e.preventDefault(); inputs[idx+1].focus(); }
    });
    inp.addEventListener('paste', function(e){
      var txt = (e.clipboardData || window.clipboardData).getData('text');
      if(!txt) return;
      e.preventDefault();
      var digits = txt
        .replace(/[\u0660-\u0669]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x0660+48); })
        .replace(/[\u06F0-\u06F9]/g, function(d){ return String.fromCharCode(d.charCodeAt(0)-0x06F0+48); })
        .replace(/[^0-9]/g,'')
        .slice(0,6)
        .split('');
      for(var i=0;i<inputs.length;i++){ inputs[i].value = digits[i] || ''; }
      setHidden();
      var next = digits.length >= 6 ? inputs[inputs.length-1] : inputs[digits.length] || inputs[inputs.length-1];
      next.focus(); next.select();
      
      // Auto-submit if 6 digits were pasted
      if(digits.length === 6 && form) {
        setTimeout(function() { form.submit(); }, 100);
      }
    });
  });
  if(inputs[0]) inputs[0].focus();
  if(form){ form.addEventListener('submit', function(){ setHidden(); }); }

  var link=document.getElementById('resend-link');
  var statusEl=document.getElementById('resend-status');
  if(!link) return;
  var csrf=document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  function setStatus(t,c){ statusEl.textContent=t||''; statusEl.style.color=c||''; }
  link.addEventListener('click',function(e){
    e.preventDefault(); link.style.pointerEvents='none'; setStatus('Sending code…','#c9a3b5');
    fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({action:'resend',csrf_token:csrf}).toString()})
      .then(r=>r.json()).then(d=>{ if(d&&d.success){ setStatus('A new code was sent.','#34d399'); } else { setStatus((d&&d.message)||'Could not resend code.','#f87171'); } })
      .catch(()=>setStatus('Network error.','#f87171'))
      .finally(()=>setTimeout(()=>{link.style.pointerEvents='';},1200));
  });
})();
</script>
</body>
</html>


