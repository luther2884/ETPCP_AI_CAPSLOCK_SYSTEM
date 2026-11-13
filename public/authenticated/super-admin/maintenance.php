<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../config/functions.php';

require_role(['super-admin']);

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
if ($action !== 'security_cleanup') {
  echo json_encode(['success'=>false,'message'=>'Invalid action']);
  exit;
}

$ok = performSecurityMaintenance();
echo json_encode(['success'=>(bool)$ok]);
exit;
?>


