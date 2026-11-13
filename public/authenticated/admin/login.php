<?php
// public/authenticated/admin/login.php — Admin-only login handler
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

$pdo = getConnection();

function perform_admin_login(PDO $pdo): array {
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
    throw new RuntimeException($u['status'] === 'pending' ? 'Your account is pending approval.' : 'Your account is not active.'); 
  }

  // ROLE RESTRICTION: Allow manager accounts to login here
  if ($u['role'] !== 'admin' && $u['role'] !== 'manager') {
    if ($u['role'] === 'customer') {
      throw new RuntimeException('Customer accounts cannot login here. Please use the customer login page.');
    }
    if ($u['role'] === 'super-admin' || $u['role'] === 'owner') {
      throw new RuntimeException('Owner accounts cannot login here. Please use the owner login page.');
    }
    throw new RuntimeException('This account cannot login here. Please use the designated portal for your role.');
  }

  $_SESSION['user_id'] = (int)$u['id'];
  $_SESSION['firstname'] = $u['firstname'];
  $_SESSION['lastname'] = $u['lastname'];
  $_SESSION['email'] = $u['email'];
  $_SESSION['user_role'] = $u['role'];
  $_SESSION['logged_in'] = true;

  // Redirect to admin dashboard
  $redirect = WEB_ROOT . 'public/authenticated/admin/dashboard.php';
    
  return ['success' => true, 'message' => 'Admin login successful.', 'redirect' => $redirect];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try { 
    echo json_encode(perform_admin_login($pdo)); 
  } catch (Throwable $e) { 
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); 
  }
  exit;
}

// If already logged in as admin/super-admin, redirect to dashboard
if (isLoggedIn() && (isAdmin() || isSuperAdmin())) {
    $redirect = isSuperAdmin() 
      ? WEB_ROOT . 'public/authenticated/super-admin/dashboard.php'
      : WEB_ROOT . 'public/authenticated/admin/dashboard.php';
    header('Location: ' . $redirect);
    exit;
}

// If logged in as customer, redirect to customer area
if (isLoggedIn() && getUserRole() === 'customer') {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/index.php');
    exit;
}

header('Location: ' . WEB_ROOT . 'public/authenticated/admin/index.php');
exit;
