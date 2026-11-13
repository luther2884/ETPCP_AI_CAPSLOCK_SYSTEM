<?php
// logout.php
declare(strict_types=1);
// Allow switching session namespaces via ?ns= query (e.g., ns=TECHWRLD_SA)
$ns = isset($_GET['ns']) ? preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)$_GET['ns'])) : '';
if ($ns !== '') { session_name($ns); }
session_start();

// WEB_ROOT will be defined by config/database.php
require_once dirname(__DIR__, 3) . '/config/functions.php';

if (function_exists('logout')) {
  logout();
} else {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest';
if ($isAjax) {
  header('Content-Type: application/json');
  echo json_encode(['success'=>true,'message'=>'Logged out.']); exit;
}

// Optional redirect path (relative to WEB_ROOT) to send user after logout
$redirect = trim((string)($_GET['redirect'] ?? ''));
if ($redirect !== '') {
  // Basic safety: only allow relative paths under the app
  if (strpos($redirect, 'http://') === false && strpos($redirect, 'https://') === false && str_starts_with($redirect, 'public/')) {
    header('Location: ' . WEB_ROOT . $redirect); exit;
  }
}

header('Location: ' . WEB_ROOT . 'index.php?logout=success'); exit;
