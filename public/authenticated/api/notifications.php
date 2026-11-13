<?php
// api/notifications.php - Notification API endpoints
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/delivery_estimate.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$userId = getUserId();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $pdo = getConnection();
    
    switch ($action) {
        case 'list':
            handleListNotifications($pdo, $userId);
            break;
        case 'mark_read':
            handleMarkAsRead($pdo, $userId);
            break;
        case 'mark_all_read':
            handleMarkAllAsRead($pdo, $userId);
            break;
        case 'delete':
            handleDeleteNotification($pdo, $userId);
            break;
        case 'preferences':
            handleNotificationPreferences($pdo, $userId);
            break;
        case 'count':
            handleNotificationCount($pdo, $userId);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Notification API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

function handleListNotifications($pdo, $userId) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
    $type = $_GET['type'] ?? '';
    $audience = $_GET['audience'] ?? '';
    $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
    
    // If not a customer, suppress admin/super-admin notifications entirely
    $isCustomerAudience = ($audience === 'customer') || (function_exists('getUserRole') && getUserRole() === 'customer');
    if (!$isCustomerAudience) {
        echo json_encode([
            'success' => true,
            'notifications' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'pages' => 0
            ]
        ]);
        return;
    }

    $offset = ($page - 1) * $limit;
    
    $where = ['user_id = ?'];
    $params = [$userId];

    // Exclude admin-privilege notifications from customer feeds (role or explicit audience)
    if ((function_exists('getUserRole') && getUserRole() === 'customer') || $audience === 'customer') {
        $where[] = "title NOT IN ('Admin Access Approved','Admin Access Denied')";
    }
    
    if ($type) {
        $where[] = 'type = ?';
        $params[] = $type;
    }
    
    if ($unread_only) {
        $where[] = 'is_read = FALSE';
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get notifications
    $stmt = $pdo->prepare("SELECT id, type, title, message, data, is_read, priority, 
                           created_at, read_at, expires_at
                           FROM notifications 
                           WHERE $whereClause 
                           AND (expires_at IS NULL OR expires_at > NOW())
                           ORDER BY created_at DESC 
                           LIMIT ? OFFSET ?");
    
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $whereClause AND (expires_at IS NULL OR expires_at > NOW())");
    $countStmt->execute(array_slice($params, 0, -2));
    $total = $countStmt->fetchColumn();
    
    // If customer has no notifications yet, create a friendly welcome notification (one-time)
    if ($isCustomerAudience && (int)$total === 0 && $page === 1) {
        try {
            // Guard: only once per session and only if a welcome row truly doesn't exist
            $sessionKey = 'tw_welcome_notif_created_' . (int)$userId;
            $alreadyCreatedThisSession = isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true;
            $existsStmt = $pdo->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND title = 'Welcome to TechWorld' LIMIT 1");
            $existsStmt->execute([$userId]);
            $welcomeExists = (bool)$existsStmt->fetchColumn();

            if (!$alreadyCreatedThisSession && !$welcomeExists && function_exists('createNotification')) {
                createNotification(
                    $userId,
                    'system',
                    'Welcome to TechWorld',
                    'We\'ll keep you posted here with order updates, payments, and offers.',
                    ['getting_started' => true],
                    'low'
                );
                $_SESSION[$sessionKey] = true;

                // Recompute list and count after creating the welcome notification
                $stmt = $pdo->prepare("SELECT id, type, title, message, data, is_read, priority, 
                                       created_at, read_at, expires_at
                                       FROM notifications 
                                       WHERE $whereClause 
                                       AND (expires_at IS NULL OR expires_at > NOW())
                                       ORDER BY created_at DESC 
                                       LIMIT ? OFFSET ?");
                $stmt->execute($params);
                $notifications = $stmt->fetchAll();
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $whereClause AND (expires_at IS NULL OR expires_at > NOW())");
                $countStmt->execute(array_slice($params, 0, -2));
                $total = $countStmt->fetchColumn();
            }
        } catch (Throwable $e) { /* ignore */ }
    }
    
    /* Arrival estimate, attached live rather than read back out of the
       notification row. Two reasons it is computed here and not just baked in
       at send time: orders placed before this feature existed have no eta in
       their payload at all, and an estimate anchored on the order can tighten
       as the order moves (a shipped order loses the preparation days), so a
       string frozen at "order placed" would go stale in the bell.
       One query for the whole page of notifications, not one per row. */
    $notifications = notificationsAttachEta($pdo, $userId, $notifications);

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function handleMarkAsRead($pdo, $userId) {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // Accept both POST and GET with action=mark_read to support sendBeacon fallback
    if ($requestMethod !== 'POST') {
        // Allow GET with query param notification_id for reliability during navigation
        $notificationId = isset($_GET['notification_id']) ? (int)$_GET['notification_id'] : 0;
        if ($notificationId <= 0) {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            return;
        }
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $notificationId = (int)($input['notification_id'] ?? 0);
    }
    
    if ($notificationId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE notifications 
                           SET is_read = TRUE, read_at = NOW() 
                           WHERE id = ? AND user_id = ?");
    $stmt->execute([$notificationId, $userId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
    }
}

function handleMarkAllAsRead($pdo, $userId) {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE notifications 
                           SET is_read = TRUE, read_at = NOW() 
                           WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$userId]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'All notifications marked as read',
        'updated_count' => $stmt->rowCount()
    ]);
}

function handleDeleteNotification($pdo, $userId) {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = (int)($input['notification_id'] ?? 0);
    
    if ($notificationId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notificationId, $userId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Notification deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
    }
}

function handleNotificationPreferences($pdo, $userId) {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM user_notification_preferences WHERE user_id = ?");
        $stmt->execute([$userId]);
        $preferences = $stmt->fetch();
        
        if (!$preferences) {
            // Create default preferences
            $stmt = $pdo->prepare("INSERT INTO user_notification_preferences 
                                   (user_id, email_notifications, push_notifications, sms_notifications,
                                    order_updates, payment_alerts, promotional_offers, security_alerts, system_updates)
                                   VALUES (?, 1, 1, 0, 1, 1, 1, 1, 0)");
            $stmt->execute([$userId]);
            
            $stmt = $pdo->prepare("SELECT * FROM user_notification_preferences WHERE user_id = ?");
            $stmt->execute([$userId]);
            $preferences = $stmt->fetch();
        }
        
        // Ensure all keys exist with defaults
        $defaultPreferences = [
            'email_notifications' => 1,
            'push_notifications' => 1,
            'sms_notifications' => 0,
            'order_updates' => 1,
            'payment_alerts' => 1,
            'promotional_offers' => 1,
            'security_alerts' => 1,
            'system_updates' => 0
        ];
        
        $preferences = array_merge($defaultPreferences, $preferences ?: []);
        
        echo json_encode(['success' => true, 'preferences' => $preferences]);
        
    } elseif ($requestMethod === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $stmt = $pdo->prepare("INSERT INTO user_notification_preferences 
                               (user_id, email_notifications, push_notifications, sms_notifications,
                                order_updates, payment_alerts, promotional_offers, security_alerts, system_updates)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE
                               email_notifications = VALUES(email_notifications),
                               push_notifications = VALUES(push_notifications),
                               sms_notifications = VALUES(sms_notifications),
                               order_updates = VALUES(order_updates),
                               payment_alerts = VALUES(payment_alerts),
                               promotional_offers = VALUES(promotional_offers),
                               security_alerts = VALUES(security_alerts),
                               system_updates = VALUES(system_updates),
                               updated_at = NOW()");
        
        $stmt->execute([
            $userId,
            isset($input['email_notifications']) ? (int)$input['email_notifications'] : 1,
            isset($input['push_notifications']) ? (int)$input['push_notifications'] : 1,
            isset($input['sms_notifications']) ? (int)$input['sms_notifications'] : 0,
            isset($input['order_updates']) ? (int)$input['order_updates'] : 1,
            isset($input['payment_alerts']) ? (int)$input['payment_alerts'] : 1,
            isset($input['promotional_offers']) ? (int)$input['promotional_offers'] : 1,
            isset($input['security_alerts']) ? (int)$input['security_alerts'] : 1,
            isset($input['system_updates']) ? (int)$input['system_updates'] : 0
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Preferences updated']);
    }
}

function handleNotificationCount($pdo, $userId) {
    // Apply same exclusions as list() for customer audience to keep counts consistent
    $audience = $_GET['audience'] ?? '';
    $excludeAdminPriv = ((function_exists('getUserRole') && getUserRole() === 'customer') || $audience === 'customer');

    // If not customer, return zero counts (hide admin notifications entirely)
    if (!$excludeAdminPriv) {
        echo json_encode(['success' => true, 'counts' => [
            'total' => 0,
            'unread' => 0,
            'order_updates' => 0,
            'payment_alerts' => 0,
            'promotional' => 0,
            'security_alerts' => 0
        ]]);
        return;
    }

    $where = ['user_id = ?','(expires_at IS NULL OR expires_at > NOW())'];
    $params = [$userId];
    if ($excludeAdminPriv) {
        $where[] = "title NOT IN ('Admin Access Approved','Admin Access Denied')";
    }
    $whereClause = implode(' AND ', $where);

    $sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN is_read = FALSE THEN 1 ELSE 0 END) as unread,
              SUM(CASE WHEN type = 'order_status' AND is_read = FALSE THEN 1 ELSE 0 END) as order_updates,
              SUM(CASE WHEN type = 'payment' AND is_read = FALSE THEN 1 ELSE 0 END) as payment_alerts,
              SUM(CASE WHEN type = 'promotional' AND is_read = FALSE THEN 1 ELSE 0 END) as promotional,
              SUM(CASE WHEN type = 'security' AND is_read = FALSE THEN 1 ELSE 0 END) as security_alerts
            FROM notifications WHERE $whereClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $counts = $stmt->fetch();
    
    echo json_encode(['success' => true, 'counts' => $counts]);
}

/**
 * Adds an `eta` (and `eta_kind`) to every order_status notification whose
 * order can still be estimated. Leaves every other row untouched, and leaves
 * `eta` absent - not empty - when there is nothing to say, so the UI can test
 * for it directly.
 *
 * Orders are fetched in one IN() query keyed by the order_ids found in the
 * notifications' data payloads, scoped to this user so a crafted payload
 * cannot read somebody else's delivery address.
 */
function notificationsAttachEta($pdo, $userId, array $notifications): array {
    if (empty($notifications) || !function_exists('deliveryEstimateForOrder')) {
        return $notifications;
    }

    $orderIds = [];
    foreach ($notifications as $n) {
        if (($n['type'] ?? '') !== 'order_status') { continue; }
        $data = [];
        if (!empty($n['data'])) {
            $decoded = json_decode((string)$n['data'], true);
            if (is_array($decoded)) { $data = $decoded; }
        }
        $oid = (int)($data['order_id'] ?? 0);
        if ($oid > 0) { $orderIds[$oid] = true; }
    }
    if (empty($orderIds)) { return $notifications; }

    try {
        $ids = array_keys($orderIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, status, delivery_method, payment_method, payment_status,
                                      paid_at, created_at, delivery_full_address,
                                      delivery_latitude, delivery_longitude,
                                      delivery_completed_at, pickup_verified_at,
                                      CURDATE() as db_today
                               FROM orders
                               WHERE id IN ($ph) AND user_id = ?");
        $stmt->execute(array_merge($ids, [(int)$userId]));

        $estimates = [];
        foreach ($stmt->fetchAll() as $row) {
            $est = deliveryEstimateForOrder($row);
            if ($est) {
                $estimates[(int)$row['id']] = [
                    // strip_tags/decode because the estimate's own strings
                    // carry &ndash; for the page; JSON consumers want the
                    // character, and the UI inserts this as text.
                    'eta' => html_entity_decode(strip_tags($est['short']), ENT_QUOTES, 'UTF-8'),
                    'eta_kind' => $est['kind'],
                ];
            }
        }
        if (empty($estimates)) { return $notifications; }

        foreach ($notifications as &$n) {
            if (($n['type'] ?? '') !== 'order_status') { continue; }
            $data = [];
            if (!empty($n['data'])) {
                $decoded = json_decode((string)$n['data'], true);
                if (is_array($decoded)) { $data = $decoded; }
            }
            $oid = (int)($data['order_id'] ?? 0);
            if ($oid > 0 && isset($estimates[$oid])) {
                $n['eta'] = $estimates[$oid]['eta'];
                $n['eta_kind'] = $estimates[$oid]['eta_kind'];
            }
        }
        unset($n);
    } catch (Throwable $e) {
        error_log('Notification ETA enrichment failed: ' . $e->getMessage());
    }

    return $notifications;
}

?>
