<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/config/functions.php';

header('Content-Type: application/json');

$loggedIn = isLoggedIn();
$role = getUserRole();
$userData = null;

if ($loggedIn) {
    // Minimal safe subset for UI greeting
    $user = getUserData();
    if ($user) {
        $userData = [
            'id' => (int)$user['id'],
            'firstname' => $user['firstname'] ?? '',
            'lastname' => $user['lastname'] ?? '',
            'email' => $user['email'] ?? ''
        ];
    }
}

// Only super-admins can enable admin registration requests; default false for public UI
$canRequestAdmin = ($role === 'super-admin');

echo json_encode([
    'success' => true,
    'loggedIn' => $loggedIn,
    'userRole' => $role,
    'userData' => $userData,
    'canRequestAdmin' => $canRequestAdmin,
]);
exit;
?>
