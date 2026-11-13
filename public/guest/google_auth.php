<?php
// public/guest/google_auth.php — "Continue with Google" handler for the
// customer auth modal.
//
// The browser gets a Google ID token from Google Identity Services (the
// button rendered in auth-modal.php) and POSTs it here. This endpoint never
// trusts the email the browser claims - it verifies the token with Google
// first, then decides one of two things:
//
//   * the email already belongs to a customer account -> start the exact
//     same half-login the password form starts (session + emailed 6-digit
//     code) and send the browser to verify_login_code.php, so Google
//     sign-in ends in the same OTP step every other login does.
//   * the email is not registered -> answer with not_registered so the modal
//     can say so and switch to the registration view (prefilled with the
//     Google name/email) where the normal registration + OTP flow takes over.
//
// Deliberately no account is created here: registration stays the one place
// that creates users, with its own password rules and its own OTP.
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once __DIR__ . '/../../config/functions.php';

if (!defined('WEB_ROOT')) { die('WEB_ROOT not defined.'); }

/**
 * Verify a Google ID token and return its claims.
 *
 * Uses Google's tokeninfo endpoint rather than verifying the JWT signature
 * locally: this project has no google/apiclient dependency (see the note in
 * config/env.php about keeping the dependency layer minimal), and tokeninfo
 * is Google's own supported verification endpoint. It validates the
 * signature and expiry for us; we still check issuer and audience here,
 * because tokeninfo happily describes a token minted for somebody else's
 * client id.
 *
 * @throws RuntimeException when the token is missing, unreachable or invalid
 */
function google_verify_id_token(string $idToken, string $clientId): array {
  if ($idToken === '' || strlen($idToken) > 8192 || !preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $idToken)) {
    throw new RuntimeException('Google sign-in failed. Please try again.');
  }

  $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $response = curl_exec($ch);
  $curlErrno = curl_errno($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($curlErrno !== 0) {
    error_log('google_auth: could not reach Google tokeninfo (curl errno ' . $curlErrno . ')');
    throw new RuntimeException('Could not reach Google. Please check your connection and try again.');
  }
  if ($httpCode !== 200) {
    throw new RuntimeException('Google sign-in failed. Please try again.');
  }

  $claims = json_decode((string)$response, true);
  if (!is_array($claims)) {
    throw new RuntimeException('Google sign-in failed. Please try again.');
  }

  $iss = (string)($claims['iss'] ?? '');
  if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') {
    throw new RuntimeException('Google sign-in failed. Please try again.');
  }
  if (!hash_equals($clientId, (string)($claims['aud'] ?? ''))) {
    error_log('google_auth: token audience mismatch (expected ' . $clientId . ')');
    throw new RuntimeException('Google sign-in failed. Please try again.');
  }
  if ((int)($claims['exp'] ?? 0) <= time()) {
    throw new RuntimeException('Your Google sign-in expired. Please try again.');
  }

  $email = strtolower(trim((string)($claims['email'] ?? '')));
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Your Google account has no usable email address.');
  }
  // tokeninfo returns the booleans as the strings "true"/"false".
  $verified = (string)($claims['email_verified'] ?? '');
  if ($verified !== 'true' && $verified !== '1') {
    throw new RuntimeException('Your Google email address is not verified.');
  }

  return [
    'email'      => $email,
    'given_name' => trim((string)($claims['given_name'] ?? '')),
    'family_name'=> trim((string)($claims['family_name'] ?? '')),
  ];
}

/**
 * Mail the customer a fresh 6-digit login code, reusing an unexpired one and
 * honouring the same short throttle window the password login uses so a
 * double submission doesn't invalidate a code already in the inbox.
 */
function google_send_login_code(array $user): void {
  $now = time();
  $hasUnexpired = isset($_SESSION['login_2fa_expires']) && $now < (int)$_SESSION['login_2fa_expires'] && !empty($_SESSION['login_2fa_code']);
  $lastSent = (int)($_SESSION['login_2fa_last_sent'] ?? 0);
  $throttleWindow = 10; // seconds

  if (!$hasUnexpired) {
    $_SESSION['login_2fa_code'] = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['login_2fa_expires'] = $now + (5 * 60);
  }

  if ($now - $lastSent < $throttleWindow) { return; }
  $_SESSION['login_2fa_last_sent'] = $now; // mark before sending to prevent races

  $code = (string)($_SESSION['login_2fa_code'] ?? '');
  $fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $baseUrl = $scheme . $host . rtrim(WEB_ROOT, '/');
  $subject = 'Your TechWorld login verification code';
  $body = '<p>Hello ' . htmlspecialchars($fullName ?: $user['email']) . ',</p>' .
          '<div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#123554;color:#a77693;padding:14px 18px;display:inline-block;border-radius:10px">' . htmlspecialchars($code) . '</div>' .
          '<p style="margin-top:16px;">This code expires in 5 minutes.</p>' .
          '<p>You can enter it here: <a href="' . htmlspecialchars($baseUrl . '/public/guest/verify_login_code.php') . '" target="_blank" rel="noopener">' . htmlspecialchars($baseUrl . '/public/guest/verify_login_code.php') . '</a></p>' .
          '<p>If you did not request this, you can ignore this email.</p>' .
          '<p>— TechWorld</p>';
  @sendEmail($user['email'], $fullName, $subject, $body);
}

function perform_google_auth(PDO $pdo): array {
  if (function_exists('check_csrf') && !check_csrf(false)) { throw new RuntimeException('Security token invalid.'); }

  $clientId = trim((string)(getenv('GOOGLE_CLIENT_ID') ?: ''));
  if ($clientId === '') { throw new RuntimeException('Google sign-in is not configured.'); }

  $claims = google_verify_id_token((string)($_POST['credential'] ?? ''), $clientId);
  $email = $claims['email'];

  $st = $pdo->prepare('SELECT id, firstname, lastname, email, role, status FROM users WHERE email = ? LIMIT 1');
  $st->execute([$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  // Not registered -> hand the modal everything it needs to open the
  // registration view already filled in with the Google profile.
  if (!$u) {
    return [
      'success'        => false,
      'not_registered' => true,
      'email'          => $email,
      'firstname'      => $claims['given_name'],
      'lastname'       => $claims['family_name'],
      'message'        => $email . ' is not registered yet. Please create your account to continue.',
    ];
  }

  // Staff/courier emails exist in the same users table but have their own
  // portals. Unlike the password form - which answers identically for a bad
  // password, a wrong portal and an unknown address so accounts can't be
  // enumerated - there's nothing to hide here: Google already proved this
  // visitor owns the mailbox. Sending them to registration instead would
  // just dead-end on "Email is already registered".
  if ($u['role'] !== 'customer') {
    throw new RuntimeException('This email belongs to a staff account. Please use your own portal to sign in.');
  }
  if ($u['status'] === 'pending' || $u['status'] === 'denied') {
    throw new RuntimeException($u['status'] === 'pending' ? 'Your account is pending approval.' : 'Your account is not active.');
  }

  // Same reactivation + last_login bookkeeping the password login does.
  try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL DEFAULT NULL, ADD INDEX IF NOT EXISTS idx_last_login (last_login)");
  } catch (Throwable $e) { /* ignore if not supported */ }
  try {
    $upd = $pdo->prepare("UPDATE users SET status = 'active', last_login = NOW(), updated_at = NOW() WHERE id = ?");
    $upd->execute([(int)$u['id']]);
    $u['status'] = 'active';
  } catch (Throwable $e) { /* ignore, session will still proceed */ }

  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['firstname'] = $u['firstname'];
  $_SESSION['lastname'] = $u['lastname'];
  $_SESSION['email'] = $u['email'];
  $_SESSION['user_role'] = $u['role'];
  $_SESSION['logged_in'] = true;
  $_SESSION['login_2fa_verified'] = false;

  try { google_send_login_code($u); } catch (Throwable $e) { /* ignore mailing issues; user can resend */ }

  // Carry over whatever the visitor put in the cart before signing in,
  // exactly as the password login does.
  $guestKey = 'guest';
  if (!isset($_SESSION['cart'])) { $_SESSION['cart'] = []; }
  $guestCart = $_SESSION['cart'][$guestKey] ?? [];
  $hadGuestCart = !empty($guestCart);

  if (function_exists('loadCartFromDb') && function_exists('saveCartToDb')) {
    $dbCart = loadCartFromDb($_SESSION['user_id']);
    if ($hadGuestCart) {
      $import = [];
      foreach ($guestCart as $pid => $qty) {
        $pid = (int)$pid; $qty = (int)$qty; if ($pid<=0||$qty<=0) continue;
        $import[$pid] = ($import[$pid] ?? 0) + $qty;
      }
      $merged = capCartQuantitiesByStock(mergeCarts($dbCart, $import));
      $_SESSION['cart'][$_SESSION['user_id']] = $merged;
      saveCartToDb($_SESSION['user_id'], $merged);
      unset($_SESSION['cart'][$guestKey]);
    } else {
      $_SESSION['cart'][$_SESSION['user_id']] = is_array($dbCart) ? $dbCart : [];
    }
  }

  $maskedEmail = $u['email'];
  if (strpos($maskedEmail, '@') !== false) {
    list($local,$domain) = explode('@',$maskedEmail,2);
    $localMasked = substr($local,0,1) . str_repeat('*', max(0, strlen($local)-2)) . substr($local,-1);
    $maskedEmail = $localMasked . '@' . $domain;
  }

  return [
    'success'              => true,
    'require_verification' => true,
    'email'                => $maskedEmail,
    'message'              => 'Enter the 6-digit code we emailed you.',
    'redirect'             => WEB_ROOT . 'public/guest/verify_login_code.php',
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $pdo = getConnection();
  try { echo json_encode(perform_google_auth($pdo)); }
  catch (Throwable $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
  exit;
}

// Nothing to show on GET - this is a POST-only endpoint.
header('Location: ' . WEB_ROOT . 'public/guest/index.php?modal=login');
exit;
