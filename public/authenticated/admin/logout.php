<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Capture role before logout() clears the session - drivers (Logistics/
// Delivery Personnel) land back on their own login page, not the Manager
// Portal login they were never shown in the first place.
$__wasDriver = (getUserRole() === 'driver');

// An explicit ?return=logistics wins over the role guess above - it is how
// the Delivery Portal's "use a different account" link gets a signed-in
// manager (or a courier handing the phone over) back to the courier form
// rather than to whichever login their own role implies.
$__returnTo = ($_GET['return'] ?? '') === 'logistics' ? 'logistics_login.php' : null;

logout();

header('Location: ' . WEB_ROOT . 'public/authenticated/admin/'
    . ($__returnTo ?? ($__wasDriver ? 'logistics_login.php' : 'index.php')));
exit;


