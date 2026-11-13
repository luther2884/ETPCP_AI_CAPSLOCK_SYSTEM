<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CRR'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Courier logout. This area has its own session bucket (TECHWRLD_CRR), so
// signing out here does not disturb a manager signed in to /admin/ in the
// same browser - and the way back is always this area's own login, never the
// Manager Portal one a rider has no business seeing.
logout();

header('Location: ' . WEB_ROOT . 'public/authenticated/courier/login.php');
exit;


