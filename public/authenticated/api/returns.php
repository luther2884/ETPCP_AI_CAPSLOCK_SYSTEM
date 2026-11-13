<?php
// Returns API Endpoints
declare(strict_types=1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/rate_limit.php';

// Spam throttle. This endpoint takes writes without a session check of its
// own (it trusts a user_id in the request body), so until that is tightened
// it is the sort of thing a bot can flood with junk rows. 20 requests per 10
// minutes per IP is far above any real customer's use and far below a flood.
rl_guard('returns_api', 20, 600);

try {
    $pdo = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'create':
            if ($method === 'POST') {
                createReturnRequest($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_returns':
            if ($method === 'GET') {
                getReturnRequests($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_return':
            if ($method === 'GET') {
                getReturnRequest($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'update_return':
            if ($method === 'PUT') {
                updateReturnRequest($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_eligible_orders':
            if ($method === 'GET') {
                getEligibleOrdersForReturn($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function createReturnRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['user_id', 'order_id', 'reason', 'description', 'return_type'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if order exists and belongs to user
        $stmt = $pdo->prepare("
            SELECT o.*, u.firstname, u.lastname, u.email
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE o.id = ? AND o.user_id = ?
        ");
        
        $stmt->execute([$input['order_id'], $input['user_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Order not found or does not belong to user']);
            return;
        }
        
        // Check if order is within return window (30 days)
        $order_date = new DateTime($order['created_at']);
        $return_deadline = clone $order_date;
        $return_deadline->add(new DateInterval('P30D'));
        
        if (new DateTime() > $return_deadline) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Order is outside the 30-day return window']);
            return;
        }
        
        // Check if there's already a return request for this order
        $stmt = $pdo->prepare("SELECT id FROM return_requests WHERE order_id = ? AND status != 'completed'");
        $stmt->execute([$input['order_id']]);
        if ($stmt->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Return request already exists for this order']);
            return;
        }
        
        // Insert return request
        $stmt = $pdo->prepare("
            INSERT INTO return_requests 
            (user_id, order_id, reason, description, return_type, exchange_product_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $exchange_product_id = $input['exchange_product_id'] ?? null;
        $stmt->execute([
            $input['user_id'],
            $input['order_id'],
            $input['reason'],
            $input['description'],
            $input['return_type'],
            $exchange_product_id
        ]);
        
        $return_id = $pdo->lastInsertId();
        
        // Get the return number
        $stmt = $pdo->prepare("SELECT return_number FROM return_requests WHERE id = ?");
        $stmt->execute([$return_id]);
        $return_number = $stmt->fetchColumn();
        
        // Add return items
        if (!empty($input['items'])) {
            foreach ($input['items'] as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO return_items 
                    (return_id, order_item_id, quantity, condition, notes) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $return_id,
                    $item['order_item_id'],
                    $item['quantity'],
                    $item['condition'] ?? 'good',
                    $item['notes'] ?? null
                ]);
            }
        }
        
        $pdo->commit();
        
        // Send notification email
        sendReturnRequestNotification($order['email'], $return_number, $order['invoice_no']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Return request created successfully',
            'return_number' => $return_number,
            'return_id' => $return_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create return request: ' . $e->getMessage()]);
    }
}

function getReturnRequests($pdo) {
    $user_id = $_GET['user_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $where_conditions = [];
    $params = [];
    
    if ($user_id) {
        $where_conditions[] = "rr.user_id = ?";
        $params[] = $user_id;
    }
    
    if ($status) {
        $where_conditions[] = "rr.status = ?";
        $params[] = $status;
    }
    
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $sql = "
        SELECT 
            rr.*,
            o.invoice_no,
            o.total_amount,
            o.created_at as order_date,
            u.firstname,
            u.lastname,
            u.email
        FROM return_requests rr
        JOIN orders o ON rr.order_id = o.id
        JOIN users u ON rr.user_id = u.id
        $where_clause
        ORDER BY rr.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM return_requests rr $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute(array_slice($params, 0, -2));
    $total = $count_stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'returns' => $returns,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function getReturnRequest($pdo) {
    $return_id = $_GET['id'] ?? null;
    
    if (!$return_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Return ID is required']);
        return;
    }
    
    // Get return request details
    $stmt = $pdo->prepare("
        SELECT 
            rr.*,
            o.invoice_no,
            o.total_amount,
            o.created_at as order_date,
            u.firstname,
            u.lastname,
            u.email,
            u.phone
        FROM return_requests rr
        JOIN orders o ON rr.order_id = o.id
        JOIN users u ON rr.user_id = u.id
        WHERE rr.id = ?
    ");
    
    $stmt->execute([$return_id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Return request not found']);
        return;
    }
    
    // Get return items
    $stmt = $pdo->prepare("
        SELECT 
            ri.*,
            oi.quantity as original_quantity,
            oi.price,
            p.name as product_name,
            p.image_url
        FROM return_items ri
        JOIN order_items oi ON ri.order_item_id = oi.id
        JOIN products p ON oi.product_id = p.id
        WHERE ri.return_id = ?
    ");
    
    $stmt->execute([$return_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'return' => $return,
        'items' => $items
    ]);
}

function updateReturnRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $return_id = $input['return_id'] ?? null;
    
    if (!$return_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Return ID is required']);
        return;
    }
    
    $allowed_fields = ['status', 'return_shipping_label', 'tracking_number', 'received_at', 'refund_amount', 'refund_method', 'notes'];
    $update_fields = [];
    $params = [];
    
    foreach ($allowed_fields as $field) {
        if (isset($input[$field])) {
            $update_fields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
        return;
    }
    
    // Add processed_at and processed_by if status is completed
    if (isset($input['status']) && $input['status'] === 'completed') {
        $update_fields[] = "processed_at = NOW()";
        $update_fields[] = "processed_by = ?";
        $params[] = $input['processed_by'] ?? null;
    }
    
    $params[] = $return_id;
    
    $sql = "UPDATE return_requests SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    
    if ($stmt->execute($params)) {
        echo json_encode(['success' => true, 'message' => 'Return request updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update return request']);
    }
}

function getEligibleOrdersForReturn($pdo) {
    $user_id = $_GET['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }
    
    // Get orders that are eligible for return (within 30 days and completed)
    $sql = "
        SELECT 
            o.id,
            o.invoice_no,
            o.total_amount,
            o.created_at,
            oi.id as order_item_id,
            oi.quantity,
            oi.price,
            p.name as product_name,
            p.image_url,
            CASE 
                WHEN rr.id IS NOT NULL THEN 'return_requested'
                ELSE 'eligible'
            END as return_status
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN return_requests rr ON o.id = rr.order_id AND rr.status != 'completed'
        WHERE o.user_id = ? 
        AND o.status = 'completed'
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY o.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by order
    $grouped_orders = [];
    foreach ($orders as $order) {
        $order_id = $order['id'];
        if (!isset($grouped_orders[$order_id])) {
            $grouped_orders[$order_id] = [
                'id' => $order['id'],
                'invoice_no' => $order['invoice_no'],
                'total_amount' => $order['total_amount'],
                'created_at' => $order['created_at'],
                'items' => []
            ];
        }
        
        $grouped_orders[$order_id]['items'][] = [
            'order_item_id' => $order['order_item_id'],
            'quantity' => $order['quantity'],
            'price' => $order['price'],
            'product_name' => $order['product_name'],
            'image_url' => $order['image_url'],
            'return_status' => $order['return_status']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'orders' => array_values($grouped_orders)
    ]);
}

function sendReturnRequestNotification($email, $return_number, $invoice_no) {
    // Implement email sending here
    error_log("Return request notification sent to $email for return $return_number, order $invoice_no");
}
?>
