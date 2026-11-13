<?php
// AJAX handler for super admin login (hardcoded credentials)
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Shared with login.php via config/functions.php - see the note there.
$SUPER_ADMIN_EMAIL = SUPER_ADMIN_EMAIL;
$SUPER_ADMIN_PASSWORD = SUPER_ADMIN_PASSWORD;

header('Content-Type: application/json');

try {
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';
  if ($email === '' || $password === '') { throw new RuntimeException('Email and password are required.'); }

  if ($email !== $SUPER_ADMIN_EMAIL || $password !== $SUPER_ADMIN_PASSWORD) {
    throw new RuntimeException('Invalid super admin credentials.');
  }

  // Real users.id, not the 0 placeholder - see the note in login.php.
  ensureSuperAdminExists();
  $_SESSION['user_id'] = superAdminUserId();
  $_SESSION['firstname'] = 'Super';
  $_SESSION['lastname'] = 'Admin';
  $_SESSION['email'] = $SUPER_ADMIN_EMAIL;
  $_SESSION['user_role'] = 'super-admin';
  $_SESSION['logged_in'] = true;
  $_SESSION['sa_constant_auth'] = true;

  echo json_encode(['success'=>true,'redirect'=> WEB_ROOT . 'public/authenticated/super-admin/dashboard.php']);
} catch (Throwable $e) {
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}


