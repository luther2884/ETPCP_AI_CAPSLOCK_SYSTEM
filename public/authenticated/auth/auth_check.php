<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/functions.php';

header('Content-Type: application/json');

$loggedIn = isLoggedIn();
$role = getUserRole();
$userData = null;

if ($loggedIn) {
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