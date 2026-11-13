<?php
// public/guest/login.php — JSON login handler; GET redirects to modal on home
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once __DIR__ . '/../../config/functions.php';

if (!defined('WEB_ROOT')) { die('WEB_ROOT not defined.'); }

$pdo = getConnection();

function perform_login(PDO $pdo): array {
  if (function_exists('check_csrf') && !check_csrf(false)) { throw new RuntimeException('Security token invalid.'); }
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';
  if ($email === '' || $password === '') { throw new RuntimeException('Email and password are required.'); }

  $st = $pdo->prepare('SELECT id, firstname, lastname, email, password, role, status FROM users WHERE email = ? LIMIT 1');
  $st->execute([$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u || !password_verify($password, $u['password'])) { throw new RuntimeException('Invalid email or password.'); }
  // Allow inactive customers to login and reactivate automatically; block pending/denied
  if ($u['role'] === 'customer') {
    if ($u['status'] === 'pending' || $u['status'] === 'denied') {
      throw new RuntimeException($u['status'] === 'pending' ? 'Your account is pending approval.' : 'Your account is not active.');
    }
  } else {
    // For non-customer roles, keep existing behavior
    if ($u['status'] !== 'active') {
      throw new RuntimeException($u['status'] === 'pending' ? 'Your account is pending approval.' : 'Your account is not active.');
    }
  }

  // Ensure last_login column exists and update status/last_login for customers
  try {
    // MySQL 8 supports IF NOT EXISTS
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL DEFAULT NULL, ADD INDEX IF NOT EXISTS idx_last_login (last_login)");
  } catch (Throwable $e) { /* ignore if not supported */ }
  if ($u['role'] === 'customer') {
    try {
      $upd = $pdo->prepare("UPDATE users SET status = 'active', last_login = NOW(), updated_at = NOW() WHERE id = ?");
      $upd->execute([(int)$u['id']]);
      $u['status'] = 'active';
    } catch (Throwable $e) { /* ignore, session will still proceed */ }
  }

  // Wrong portal for this account. The message is deliberately identical to
  // the one a bad password gets above: naming the role ("Manager and Owner
  // accounts...", "Delivery personnel accounts...") told anyone who typed an
  // email into this form what kind of account it is, and which portal to
  // attack next. Staff and delivery accounts are enumerable that way without
  // ever knowing a password, so this form now answers the same for a wrong
  // password, a wrong portal and an address that does not exist.
  //
  // Logistics/Delivery Personnel have their own dedicated portal
  // (admin/logistics_login.php) now, separate from this customer-facing
  // login - besides being the right access-control boundary, it also keeps
  // their session in the same bucket Delivery_Report.php expects (see that
  // file's own comments on TECHWRLD_ADM vs TECHWRLD_CUST), which logging in
  // here would not.
  if ($u['role'] !== 'customer') {
    throw new RuntimeException('Invalid email or password.');
  }

  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['firstname'] = $u['firstname'];
  $_SESSION['lastname'] = $u['lastname'];
  $_SESSION['email'] = $u['email'];
  $_SESSION['user_role'] = $u['role'];
  $_SESSION['logged_in'] = true;
  $_SESSION['login_2fa_verified'] = false;

  // Generate and email 6-digit login verification code for customers
  if ($u['role'] === 'customer') {
    try {
      $now = time();
      $hasUnexpired = isset($_SESSION['login_2fa_expires']) && $now < (int)$_SESSION['login_2fa_expires'];
      $lastSent = (int)($_SESSION['login_2fa_last_sent'] ?? 0);
      $throttleWindow = 10; // seconds: guard against accidental double submissions

      // Reuse existing unexpired code; otherwise create a new one
      if (!$hasUnexpired) {
        $_SESSION['login_2fa_code'] = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['login_2fa_expires'] = $now + (5 * 60);
      }

      // Only send an email if we haven't sent one very recently
      if ($now - $lastSent >= $throttleWindow) {
        $_SESSION['login_2fa_last_sent'] = $now; // mark before sending to prevent races
        $code = (string)($_SESSION['login_2fa_code'] ?? '');
        $fullName = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . $host . rtrim(WEB_ROOT, '/');
        $subject = 'Your TechWorld login verification code';
        $body = '<p>Hello ' . htmlspecialchars($fullName ?: $u['email']) . ',</p>' .
                '<div style="font-size:28px;font-weight:700;letter-spacing:6px;background:#123554;color:#a77693;padding:14px 18px;display:inline-block;border-radius:10px">' . htmlspecialchars($code) . '</div>' .
                '<p style="margin-top:16px;">This code expires in 5 minutes.</p>' .
                '<p>You can enter it here: <a href="' . htmlspecialchars($baseUrl . '/public/guest/verify_login_code.php') . '" target="_blank" rel="noopener">' . htmlspecialchars($baseUrl . '/public/guest/verify_login_code.php') . '</a></p>' .
                '<p>If you did not request this, you can ignore this email.</p>' .
                '<p>— TechWorld</p>';
        @sendEmail($u['email'], $fullName, $subject, $body);
      }
    } catch (Throwable $e) { /* ignore mailing issues; user can resend */ }
  }

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

  // If customer and a valid remember-2FA cookie exists, bypass verification
  if ($u['role'] === 'customer' && function_exists('checkRemember2faCookie') && checkRemember2faCookie((int)$u['id'])) {
    $_SESSION['login_2fa_verified'] = true;
    $_SESSION['promo_modal_pending'] = true;
    $redirect = getUserHomepage('customer');
    return ['success' => true, 'message' => 'Login successful.', 'redirect' => $redirect];
  }

  // Otherwise, ask for verification via modal (AJAX friendly)
  if ($u['role'] === 'customer') {
    $maskedEmail = $u['email'];
    if (strpos($maskedEmail, '@') !== false) {
      list($local,$domain) = explode('@',$maskedEmail,2);
      $localMasked = substr($local,0,1) . str_repeat('*', max(0, strlen($local)-2)) . substr($local,-1);
      $maskedEmail = $localMasked . '@' . $domain;
    }
    // Provide an explicit redirect to the verification page so clients can navigate consistently
    $verifyUrl = (function_exists('WEB_ROOT') || defined('WEB_ROOT'))
      ? (WEB_ROOT . 'public/guest/verify_login_code.php')
      : '/public/guest/verify_login_code.php';
    return [
      'success' => true,
      'require_verification' => true,
      'email' => $maskedEmail,
      'message' => 'Enter the 6-digit code we emailed you.',
      'redirect' => $verifyUrl
    ];
  }

  // Non-customer roles
  $redirect = function_exists('getUserHomepage') ? getUserHomepage($u['role']) : 'public/authenticated/customer/index.php';
  return ['success' => true, 'message' => 'Login successful.', 'redirect' => $redirect];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try { echo json_encode(perform_login($pdo)); } catch (Throwable $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
  exit;
}

// GET requests: use modal on home page instead of standalone page
$target = WEB_ROOT . 'public/guest/index.php?modal=login';
header('Location: ' . $target);
exit;
