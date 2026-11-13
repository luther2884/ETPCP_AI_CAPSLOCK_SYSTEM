<?php
  // admin/notifications.php - Redirect admins to customer notifications page to focus on customer notifications only
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['admin']);

header('Location: ' . WEB_ROOT . 'public/authenticated/customer/notifications.php');
exit;
