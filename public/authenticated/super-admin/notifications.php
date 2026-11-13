<?php
// super-admin/notifications.php - Redirect to customer notifications page to focus solely on customer notifications
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['super-admin']);

header('Location: ' . WEB_ROOT . 'public/authenticated/customer/notifications.php');
exit;
