<?php
// public/authenticated/admin/register.php — Manager self-registration disabled; accounts are created by Owner only
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Redirect to Manager Portal login page
header('Location: ' . WEB_ROOT . 'public/authenticated/admin/index.php');
exit;
