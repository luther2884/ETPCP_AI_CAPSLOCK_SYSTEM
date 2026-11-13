<?php
declare(strict_types=1);
// register.php — Customer Registration with Email OTP Verification & Auto-Login

require_once __DIR__ . '/../../../config/functions.php';

$pdo = getConnection();

function isCommonPassword(string $password): bool {
  static $common = [
    'password', 'password1', 'password123', '12345678', '123456789', '1234567890',
    'qwerty123', 'qwertyuiop', 'letmein123', 'iloveyou1', 'admin1234', 'welcome123',
    'abc123456', 'football1', '111111111', '123123123', 'qazwsx123', '1q2w3e4r5t',
    'trustno11', 'monkey123', 'dragon123', 'master1234', 'passw0rd1', 'sunshine1',
    'princess1', 'superman1', 'starwars1', 'whatever1', 'freedom123',
  ];
  $lower = strtolower($password);
  if (in_array($lower, $common, true)) { return true; }
  if (preg_match('/^(.)\1+$/', $password)) { return true; } // all one repeated character
  if (preg_match('/(01234567|12345678|23456789|abcdefgh|qwertyui)/i', $password)) { return true; } // keyboard/sequential runs
  if (strpos($lower, 'password') !== false || strpos($lower, 'qwerty') !== false) { return true; }
  return false;
}

function otp_log(string $msg): void {
  try {
    $dir = __DIR__ . '/../../../storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $file = $dir . '/otp_debug.log';
    $line = '[' . date('Y-m-d H:i:s') . '] [SessionID:' . session_id() . '] ' . $msg . "\n";
    file_put_contents($file, $line, FILE_APPEND);
  } catch (Throwable $e) {}
}

function perform_register(PDO $pdo): array {
  if (function_exists('check_csrf') && !check_csrf(false)) {
    throw new RuntimeException('Security token invalid.');
  }

  $firstname = trim($_POST['firstname'] ?? '');
  $lastname  = trim($_POST['lastname'] ?? '');
  $email     = trim(strtolower($_POST['email'] ?? ''));
  $password  = $_POST['password'] ?? '';
  $confirm   = $_POST['confirm_password'] ?? '';

  if ($firstname === '' || $lastname === '' || $email === '' || $password === '' || $confirm === '') {
    throw new RuntimeException('All fields are required.');
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Please provide a valid email address.');
  }
  if ($password !== $confirm) {
    throw new RuntimeException('Passwords do not match.');
  }
  if (strlen($password) < 8) {
    throw new RuntimeException('Password must be at least 8 characters long.');
  }
  if (!preg_match('/(?=.*[a-z])/', $password)) {
    throw new RuntimeException('Password must contain at least one lowercase letter.');
  }
  if (!preg_match('/(?=.*[A-Z])/', $password)) {
    throw new RuntimeException('Password must contain at least one uppercase letter.');
  }
  if (!preg_match('/[0-9]/', $password) && !preg_match('/[^A-Za-z0-9]/', $password)) {
    throw new RuntimeException('Password must contain at least one number or symbol.');
  }
  $emailLocal = strstr($email, '@', true);
  if ($emailLocal === false) { $emailLocal = $email; }
  if (($emailLocal !== '' && strlen($emailLocal) >= 3 && stripos($password, $emailLocal) !== false)
      || ($email !== '' && stripos($password, $email) !== false)) {
    throw new RuntimeException('Password must not contain any part of your email address.');
  }
  if (isCommonPassword($password)) {
    throw new RuntimeException('Password is too common or easily guessed. Please choose a more unique password.');
  }

  // Check if email already registered in DB
  $ex = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
  $ex->execute([$email]);
  if ($ex->fetchColumn()) {
    throw new RuntimeException('Email is already registered.');
  }

  // Determine whether we have an active, unexpired pending registration for this email
  $hasActiveSession = !empty($_SESSION['pending_registration'])
      && strtolower($_SESSION['pending_registration']['email'] ?? '') === $email
      && !empty($_SESSION['pending_registration']['otp'])
      && isset($_SESSION['pending_registration']['expires_at'])
      && time() < (int)$_SESSION['pending_registration']['expires_at'];

  if ($hasActiveSession) {
    // ── Reuse the existing OTP: preserve everything already stored ──
    $otp      = (string)$_SESSION['pending_registration']['otp'];
    $passHash = $_SESSION['pending_registration']['password'];

    // Only update name/password in case user corrected them, but keep OTP & timing intact
    $_SESSION['pending_registration']['firstname'] = $firstname;
    $_SESSION['pending_registration']['lastname']  = $lastname;
    $_SESSION['pending_registration']['password']  = $passHash;

    $lastSent = (int)($_SESSION['pending_registration']['last_sent'] ?? 0);
    $canResend = (time() - $lastSent) >= 30;

    otp_log("PERFORM_REGISTER (REUSE) - Email: '$email', OTP: '$otp', canResend: " . ($canResend ? 'yes' : 'no'));

    if (!$canResend) {
      // Don't send another email yet — just show the OTP screen again
      return [
        'success'     => true,
        'require_otp' => true,
        'email'       => $email,
        'message'     => 'A code was already sent to ' . $email . '. Please check your inbox (including spam).'
      ];
    }

    // Update last_sent timestamp but keep OTP & expires_at untouched
    $_SESSION['pending_registration']['last_sent'] = time();

  } else {
    // ── Generate a brand-new OTP (first time or expired session) ──
    $otp      = sprintf('%06d', random_int(0, 999999));
    $passHash = password_hash($password, PASSWORD_DEFAULT);

    $_SESSION['pending_registration'] = [
      'firstname'  => $firstname,
      'lastname'   => $lastname,
      'email'      => $email,
      'password'   => $passHash,
      'role'       => 'customer',
      'otp'        => (string)$otp,
      'expires_at' => time() + 600, // 10 minutes
      'created_at' => time(),
      'last_sent'  => time(),
      'attempts'   => 0
    ];

    otp_log("PERFORM_REGISTER (NEW) - Email: '$email', OTP: '$otp'");
  }

  $fullName = trim("$firstname $lastname");
  $subject  = 'Your TechWorld Registration Verification Code';
  $body     = '<p>Hello ' . htmlspecialchars($fullName ?: $email) . ',</p>' .
    '<p>Thank you for creating an account with TechWorld! Please verify your email address using the 6-digit code below:</p>' .
    '<div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#0b1220;color:#a77693;padding:14px 18px;display:inline-block;border-radius:10px;margin:12px 0;">' . htmlspecialchars($otp) . '</div>' .
    '<p style="margin-top:16px;">This code will expire in 10 minutes.</p>' .
    '<p>If you did not initiate this request, you can ignore this email.</p>' .
    '<p>— TechWorld</p>';

  $sent = sendEmail($email, $fullName, $subject, $body);
  if (!$sent) {
    throw new RuntimeException('Failed to send verification email to ' . $email . '. Please check your email address or SMTP configuration.');
  }

  return [
    'success'     => true,
    'require_otp' => true,
    'email'       => $email,
    'message'     => 'Verification code sent to ' . $email . '.'
  ];
}

function handle_verify_otp(PDO $pdo): array {
  if (function_exists('check_csrf') && !check_csrf(false)) {
    throw new RuntimeException('Security token invalid.');
  }

  // If user is already logged in as customer (e.g. from parallel double-submit that succeeded), return success
  if (function_exists('isLoggedIn') && isLoggedIn() && function_exists('isCustomer') && isCustomer()) {
    $_SESSION['login_2fa_verified'] = true;
    $_SESSION['promo_modal_pending'] = true;
    otp_log("HANDLE_VERIFY_OTP - Customer already logged in, returning success.");
    return [
      'success'    => true,
      'auto_login' => true,
      'redirect'   => WEB_ROOT . 'public/authenticated/customer/index.php?register=success',
      'message'    => 'Email verified! Account created successfully.'
    ];
  }

  if (empty($_SESSION['pending_registration'])) {
    // Check if account was already created in DB for this recent registration session (race-condition protection)
    $recentEmail = $_SESSION['last_registered_email'] ?? '';
    if ($recentEmail !== '') {
      try {
        $st = $pdo->prepare("SELECT id, firstname, lastname, email, role FROM users WHERE email = ? LIMIT 1");
        $st->execute([$recentEmail]);
        $recentlyCreated = $st->fetch(PDO::FETCH_ASSOC);
        if ($recentlyCreated) {
          $_SESSION['user_id']            = (int)$recentlyCreated['id'];
          $_SESSION['logged_in']          = true;
          $_SESSION['user_role']          = 'customer';
          $_SESSION['user_name']          = trim($recentlyCreated['firstname'] . ' ' . $recentlyCreated['lastname']);
          $_SESSION['email']              = $recentlyCreated['email'];
          $_SESSION['login_2fa_verified'] = true;
          $_SESSION['promo_modal_pending'] = true;
          otp_log("HANDLE_VERIFY_OTP AUTO-SUCCESS - User was already created in DB! UserID: {$recentlyCreated['id']}");
          return [
            'success'    => true,
            'auto_login' => true,
            'redirect'   => WEB_ROOT . 'public/authenticated/customer/index.php?register=success',
            'message'    => 'Email verified! Account created successfully.'
          ];
        }
      } catch (Throwable $e) {}
    }

    otp_log("HANDLE_VERIFY_OTP FAIL - Session pending_registration is EMPTY!");
    throw new RuntimeException('Verification code has expired.');
  }

  $pending = &$_SESSION['pending_registration'];

  // Check expiration (10 minutes)
  if (isset($pending['expires_at']) && time() > (int)$pending['expires_at']) {
    otp_log("HANDLE_VERIFY_OTP FAIL - OTP expired (current time > expires_at)");
    unset($_SESSION['pending_registration']);
    throw new RuntimeException('Verification code has expired.');
  }

  $rawCode = $_POST['code'] ?? '';
  if (($rawCode === '' || $rawCode === null) && !empty($_POST['otp_digit']) && is_array($_POST['otp_digit'])) {
    $rawCode = implode('', $_POST['otp_digit']);
  }

  $submittedCode = preg_replace('/[^0-9]/', '', (string)$rawCode);
  $expectedCode  = preg_replace('/[^0-9]/', '', (string)($pending['otp'] ?? ''));

  otp_log("HANDLE_VERIFY_OTP ATTEMPT - Raw Code: " . var_export($rawCode, true) . ", Submitted: '$submittedCode', Expected: '$expectedCode', Session Email: '" . ($pending['email'] ?? '') . "'");

  if (strlen($submittedCode) !== 6) {
    otp_log("HANDLE_VERIFY_OTP FAIL - Submitted length is not 6: '$submittedCode'");
    throw new RuntimeException('Invalid verification code.');
  }

  // Compare submitted OTP code with stored session OTP code
  if ($expectedCode === '' || strlen($expectedCode) !== 6 || !hash_equals($expectedCode, $submittedCode)) {
    $pending['attempts'] = ((int)($pending['attempts'] ?? 0)) + 1;
    otp_log("HANDLE_VERIFY_OTP FAIL - Mismatch! Submitted: '$submittedCode', Expected: '$expectedCode', Attempt: {$pending['attempts']}");
    if ($pending['attempts'] >= 5) {
      unset($_SESSION['pending_registration']);
      throw new RuntimeException('Verification code has expired.');
    }
    throw new RuntimeException('Invalid verification code.');
  }

  otp_log("HANDLE_VERIFY_OTP SUCCESS - Submitted: '$submittedCode' matched Expected: '$expectedCode'");

  // Code matches! Re-check email uniqueness before creating account
  $ex = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
  $ex->execute([$pending['email']]);
  if ($ex->fetchColumn()) {
    unset($_SESSION['pending_registration']);
    throw new RuntimeException('This email is already registered.');
  }

  // Create active customer user account
  $ins = $pdo->prepare('INSERT INTO users (firstname, lastname, email, password, role, status, created_at) VALUES (?,?,?,?,?,?,NOW())');
  $success = $ins->execute([
    $pending['firstname'],
    $pending['lastname'],
    $pending['email'],
    $pending['password'],
    'customer',
    'active'
  ]);

  if (!$success) {
    throw new RuntimeException('Failed to create user account. Please try again.');
  }

  $newUserId = (int)$pdo->lastInsertId();
  $userEmail = $pending['email'];
  $userName  = trim($pending['firstname'] . ' ' . $pending['lastname']);

  // Record registered email in session for race-condition recovery
  $_SESSION['last_registered_email'] = $userEmail;

  // Clear pending registration state
  unset($_SESSION['pending_registration']);

  // Auto-login session state
  $_SESSION['user_id']            = $newUserId;
  $_SESSION['logged_in']          = true;
  $_SESSION['user_role']          = 'customer';
  $_SESSION['user_name']          = $userName;
  $_SESSION['email']              = $userEmail;
  $_SESSION['login_2fa_verified'] = true;
  $_SESSION['promo_modal_pending'] = true;

  return [
    'success'    => true,
    'auto_login' => true,
    'redirect'   => WEB_ROOT . 'public/authenticated/customer/index.php?register=success',
    'message'    => 'Email verified! Account created successfully.'
  ];
}

function handle_resend_otp(): array {
  if (function_exists('check_csrf') && !check_csrf(false)) {
    throw new RuntimeException('Security token invalid.');
  }

  if (empty($_SESSION['pending_registration'])) {
    throw new RuntimeException('OTP expired.');
  }

  $pending  = &$_SESSION['pending_registration'];
  $lastSent = (int)($pending['last_sent'] ?? 0);

  if ((time() - $lastSent) < 30) {
    $wait = 30 - (time() - $lastSent);
    throw new RuntimeException('Please wait ' . $wait . ' seconds before requesting a new code.');
  }

  if (!empty($pending['otp']) && isset($pending['expires_at']) && time() < (int)$pending['expires_at']) {
    $otp = (string)$pending['otp'];
  } else {
    $otp = sprintf('%06d', random_int(0, 999999));
    $pending['otp']        = (string)$otp;
    $pending['created_at'] = time();
  }
  $pending['expires_at'] = time() + 600;
  $pending['last_sent']  = time();
  $pending['attempts']   = 0;

  otp_log("HANDLE_RESEND_OTP - Email: '{$pending['email']}', Active OTP: '$otp'");

  $fullName = trim(($pending['firstname'] ?? '') . ' ' . ($pending['lastname'] ?? ''));
  $email    = $pending['email'];
  $subject  = 'Your TechWorld Registration Verification Code';
  $body     = '<p>Hello ' . htmlspecialchars($fullName ?: $email) . ',</p>' .
    '<p>Here is your 6-digit verification code:</p>' .
    '<div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#0b1220;color:#a77693;padding:14px 18px;display:inline-block;border-radius:10px;margin:12px 0;">' . htmlspecialchars($otp) . '</div>' .
    '<p style="margin-top:16px;">This code will expire in 10 minutes.</p>' .
    '<p>— TechWorld</p>';

  $sent = sendEmail($email, $fullName, $subject, $body);
  if (!$sent) {
    throw new RuntimeException('Failed to resend verification email.');
  }

  return [
    'success' => true,
    'message' => 'A new 6-digit verification code has been sent to ' . $email . '.'
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Suppress display_errors so PHP warnings never corrupt the JSON response
  ini_set('display_errors', '0');

  // Discard any output that may have accumulated (PHP notices, BOM, etc.)
  while (ob_get_level() > 0) { ob_end_clean(); }

  ob_start();
  header('Content-Type: application/json');
  try {
    $action = $_POST['action'] ?? '';
    if ($action === 'verify_otp') {
      $result = handle_verify_otp($pdo);
    } elseif ($action === 'resend_otp') {
      $result = handle_resend_otp();
    } else {
      $result = perform_register($pdo);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
    // Clear any stray output captured since ob_start()
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode($result);
  } catch (Throwable $e) {
    error_log('Registration error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  }
  exit;
}

// GET: standalone page
$pageTitle = 'Create Account - TechWorld';
$activeNav = '';
include __DIR__ . '/../../shared/includes/header.php';
include __DIR__ . '/../../shared/includes/navigation.php';
?>
<main class="auth-page" style="padding:40px 0;">
  <div class="container">
    <div class="modal-content" style="position:static;">
      <div class="auth-container">
        <div id="notification" class="notification" style="display:none;"></div>
        <div id="registerForm" class="auth-form active">
          <h2>Create Account</h2>
          <p>Join us today! Create your new account</p>

          <form id="registerFormElement" action="<?= defined('AUTH_REGISTER_URL') ? AUTH_REGISTER_URL : WEB_ROOT . 'public/authenticated/auth/register.php' ?>" method="POST">
            <input type="hidden" name="role" value="customer">

            <input type="text" name="firstname" placeholder="First Name" required>
            <input type="text" name="lastname" placeholder="Last Name" required>
            <input type="email" id="registerEmail" name="email" placeholder="email@address.com" required>

            <div class="password-field">
              <input type="password" id="registerPassword" name="password" placeholder="Password" required>
              <button type="button" class="password-toggle" onclick="togglePassword('registerPassword', this)">
                <i class="fi fi-rr-eye"></i>
              </button>
            </div>

            <ul class="password-rules" data-password-rules data-password-target="registerPassword" data-email-target="registerEmail">
              <li data-rule="length">At least 8 characters</li>
              <li data-rule="case">Upper &amp; lowercase letters</li>
              <li data-rule="charset">At least one number or symbol</li>
              <li data-rule="noemail">Doesn't contain your email</li>
              <li data-rule="unique">Not a common or easily guessed password</li>
            </ul>

            <div class="password-field">
              <input type="password" id="confirmPassword" name="confirm_password" placeholder="Confirm Password" required>
              <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', this)">
                <i class="fi fi-rr-eye"></i>
              </button>
            </div>

            <button type="submit" class="auth-btn" id="registerBtn">Create Account</button>
          </form>

          <p class="auth-link">Already have an account? <a href="<?= WEB_ROOT ?>public/guest/login.php">Sign in here</a></p>
          <p class="auth-link"><a href="<?= WEB_ROOT ?>index.php">← Back to Home</a></p>
        </div>

        <!-- Register Verify Code (shown after submitting registration) -->
        <div id="registerVerify" class="auth-form" style="display:none; text-align:center;">
          <h2 style="margin-bottom:8px;">Verify Your Email</h2>
          <p style="opacity:.9;margin-bottom:6px;">We sent a 6‑digit verification code to</p>
          <div style="display:inline-flex;align-items:center;justify-content:center;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
            <span id="registerVerifyEmail" style="font-weight:600;color:#a77693;word-break:break-all;font-size:15px;"></span>
            <button type="button" onclick="showRegister();" style="background:var(--bg-card-hover);border:1px solid var(--border-color);color:var(--text-primary);cursor:pointer;font-size:12px;padding:3px 10px;border-radius:6px;transition:0.2s;" title="Change email address">
              <i class="fi fi-rr-edit" style="margin-right:4px;"></i>Change Email
            </button>
          </div>

          <form id="registerVerifyForm" action="<?= defined('AUTH_REGISTER_URL') ? AUTH_REGISTER_URL : WEB_ROOT . 'public/authenticated/auth/register.php' ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify_otp">
            <input type="hidden" name="code" id="registerOtpHidden">
            <div class="otp-group" aria-label="6 digit verification code" role="group" style="display:flex;gap:10px;justify-content:center;margin:8px 0 16px">
              <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 1" maxlength="1" pattern="\d">
              <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 2" maxlength="1" pattern="\d">
              <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 3" maxlength="1" pattern="\d">
              <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 4" maxlength="1" pattern="\d">
              <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 5" maxlength="1" pattern="\d">
              <input class="otp-input" name="otp_digit[]" inputmode="numeric" aria-label="Digit 6" maxlength="1" pattern="\d">
            </div>
            <button type="submit" class="auth-btn" style="margin-top:14px;width:100%;">Verify Code & Log In</button>
          </form>
          <p id="regResendStatus" style="margin:6px 0 0;opacity:.9;"></p>
          <p class="auth-link" style="margin:8px 0 0;"><a href="#" id="registerResendLink">Resend code</a></p>
          <p class="auth-link" style="margin:6px 0 0;"><a href="#" onclick="showRegister();return false;">← Back to registration</a></p>
        </div>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../shared/includes/footer.php'; ?>
