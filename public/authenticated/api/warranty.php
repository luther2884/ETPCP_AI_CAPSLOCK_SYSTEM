<?php
// Warranty API Endpoints
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
rl_guard('warranty_api', 20, 600);

try {
    $pdo = getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'register':
            if ($method === 'POST') {
                registerWarranty($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'claim':
            if ($method === 'POST') {
                createWarrantyClaim($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_registrations':
            if ($method === 'GET') {
                getWarrantyRegistrations($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_claims':
            if ($method === 'GET') {
                getWarrantyClaims($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'get_claim':
            if ($method === 'GET') {
                getWarrantyClaim($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'update_claim':
            if ($method === 'PUT') {
                updateWarrantyClaim($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            }
            break;
            
        case 'check_warranty':
            if ($method === 'GET') {
                checkWarrantyStatus($pdo);
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

function registerWarranty($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['customer_name', 'customer_email', 'order_number', 'product_serial', 'product_model', 'purchase_date'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }
    
    // Get or create user
    $user_id = getOrCreateUser($pdo, $input);
    
    if (!$user_id) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create user record']);
        return;
    }
    
    // Check if serial number already exists
    $stmt = $pdo->prepare("SELECT id FROM warranty_registrations WHERE serial_number = ?");
    $stmt->execute([$input['product_serial']]);
    if ($stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Serial number already registered']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get product and order information
        $product_id = null;
        $order_id = null;
        
        if (!empty($input['order_number'])) {
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE invoice_no = ?");
            $stmt->execute([$input['order_number']]);
            $order_id = $stmt->fetchColumn();
        }
        
        // Try to find product by model name
        $stmt = $pdo->prepare("SELECT id FROM products WHERE name LIKE ? LIMIT 1");
        $stmt->execute(["%{$input['product_model']}%"]);
        $product_id = $stmt->fetchColumn();
        
        // Calculate warranty dates
        $purchase_date = new DateTime($input['purchase_date']);
        $warranty_start = $purchase_date;
        $warranty_end = clone $purchase_date;
        $warranty_end->add(new DateInterval('P3Y')); // Default 3 years
        
        // Insert warranty registration
        $stmt = $pdo->prepare("
            INSERT INTO warranty_registrations 
            (user_id, order_id, product_id, serial_number, purchase_date, warranty_start_date, warranty_end_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        
        $stmt->execute([
            $user_id,
            $order_id,
            $product_id,
            $input['product_serial'],
            $input['purchase_date'],
            $warranty_start->format('Y-m-d'),
            $warranty_end->format('Y-m-d')
        ]);
        
        $registration_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // Send confirmation email
        sendWarrantyRegistrationConfirmation($input['customer_email'], $input['product_serial'], $warranty_end->format('Y-m-d'));
        
        echo json_encode([
            'success' => true,
            'message' => 'Warranty registered successfully',
            'registration_id' => $registration_id,
            'warranty_end_date' => $warranty_end->format('Y-m-d')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to register warranty: ' . $e->getMessage()]);
    }
}

function createWarrantyClaim($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['user_id', 'product_id', 'issue_description'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if product is under warranty
        $stmt = $pdo->prepare("
            SELECT wr.*, p.name as product_name
            FROM warranty_registrations wr
            JOIN products p ON wr.product_id = p.id
            WHERE wr.user_id = ? AND wr.product_id = ? AND wr.status = 'active'
            AND wr.warranty_end_date >= CURDATE()
            ORDER BY wr.warranty_end_date DESC
            LIMIT 1
        ");
        
        $stmt->execute([$input['user_id'], $input['product_id']]);
        $warranty = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$warranty) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product is not under warranty or warranty has expired']);
            return;
        }
        
        // Insert warranty claim
        $stmt = $pdo->prepare("
            INSERT INTO warranty_claims 
            (user_id, order_id, product_id, issue_description, status, priority) 
            VALUES (?, ?, ?, ?, 'pending', ?)
        ");
        
        $priority = $input['priority'] ?? 'medium';
        $stmt->execute([
            $input['user_id'],
            $warranty['order_id'],
            $input['product_id'],
            $input['issue_description'],
            $priority
        ]);
        
        $claim_id = $pdo->lastInsertId();
        
        // Get the claim number
        $stmt = $pdo->prepare("SELECT claim_number FROM warranty_claims WHERE id = ?");
        $stmt->execute([$claim_id]);
        $claim_number = $stmt->fetchColumn();
        
        $pdo->commit();
        
        // Send notification email
        sendWarrantyClaimNotification($input['user_id'], $claim_number, $warranty['product_name']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Warranty claim created successfully',
            'claim_number' => $claim_number,
            'claim_id' => $claim_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create warranty claim: ' . $e->getMessage()]);
    }
}

function getWarrantyRegistrations($pdo) {
    $user_id = $_GET['user_id'] ?? null;
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $where_conditions = [];
    $params = [];
    
    if ($user_id) {
        $where_conditions[] = "wr.user_id = ?";
        $params[] = $user_id;
    }
    
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $sql = "
        SELECT 
            wr.*,
            p.name as product_name,
            p.image_url,
            u.firstname,
            u.lastname,
            u.email
        FROM warranty_registrations wr
        LEFT JOIN products p ON wr.product_id = p.id
        JOIN users u ON wr.user_id = u.id
        $where_clause
        ORDER BY wr.registration_date DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM warranty_registrations wr $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute(array_slice($params, 0, -2));
    $total = $count_stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'registrations' => $registrations,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function getWarrantyClaims($pdo) {
    $user_id = $_GET['user_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $where_conditions = [];
    $params = [];
    
    if ($user_id) {
        $where_conditions[] = "wc.user_id = ?";
        $params[] = $user_id;
    }
    
    if ($status) {
        $where_conditions[] = "wc.status = ?";
        $params[] = $status;
    }
    
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $sql = "
        SELECT 
            wc.*,
            p.name as product_name,
            p.image_url,
            u.firstname,
            u.lastname,
            u.email
        FROM warranty_claims wc
        JOIN products p ON wc.product_id = p.id
        JOIN users u ON wc.user_id = u.id
        $where_clause
        ORDER BY wc.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM warranty_claims wc $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute(array_slice($params, 0, -2));
    $total = $count_stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'claims' => $claims,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function getWarrantyClaim($pdo) {
    $claim_id = $_GET['id'] ?? null;
    
    if (!$claim_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Claim ID is required']);
        return;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            wc.*,
            p.name as product_name,
            p.image_url,
            u.firstname,
            u.lastname,
            u.email,
            u.phone
        FROM warranty_claims wc
        JOIN products p ON wc.product_id = p.id
        JOIN users u ON wc.user_id = u.id
        WHERE wc.id = ?
    ");
    
    $stmt->execute([$claim_id]);
    $claim = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$claim) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Warranty claim not found']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'claim' => $claim
    ]);
}

function updateWarrantyClaim($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $claim_id = $input['claim_id'] ?? null;
    
    if (!$claim_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Claim ID is required']);
        return;
    }
    
    $allowed_fields = ['status', 'priority', 'assigned_to', 'resolution_notes', 'resolution_type'];
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
    
    // Add resolved_at and resolved_by if status is completed
    if (isset($input['status']) && $input['status'] === 'completed') {
        $update_fields[] = "resolved_at = NOW()";
        $update_fields[] = "resolved_by = ?";
        $params[] = $input['resolved_by'] ?? null;
    }
    
    $params[] = $claim_id;
    
    $sql = "UPDATE warranty_claims SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    
    if ($stmt->execute($params)) {
        echo json_encode(['success' => true, 'message' => 'Warranty claim updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update warranty claim']);
    }
}

function checkWarrantyStatus($pdo) {
    $serial_number = $_GET['serial_number'] ?? null;
    $user_id = $_GET['user_id'] ?? null;
    
    if (!$serial_number && !$user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Serial number or user ID is required']);
        return;
    }
    
    $where_conditions = [];
    $params = [];
    
    if ($serial_number) {
        $where_conditions[] = "wr.serial_number = ?";
        $params[] = $serial_number;
    }
    
    if ($user_id) {
        $where_conditions[] = "wr.user_id = ?";
        $params[] = $user_id;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT 
            wr.*,
            p.name as product_name,
            p.image_url,
            CASE 
                WHEN wr.warranty_end_date < CURDATE() THEN 'expired'
                WHEN wr.status = 'voided' THEN 'voided'
                ELSE 'active'
            END as warranty_status
        FROM warranty_registrations wr
        LEFT JOIN products p ON wr.product_id = p.id
        $where_clause
        ORDER BY wr.warranty_end_date DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $warranties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'warranties' => $warranties
    ]);
}

function getOrCreateUser($pdo, $input) {
    // Check if user exists by email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$input['customer_email']]);
    $user_id = $stmt->fetchColumn();
    
    if ($user_id) {
        return $user_id;
    }
    
    // Create new user
    $stmt = $pdo->prepare("
        INSERT INTO users (firstname, lastname, email, password, role, status) 
        VALUES (?, ?, ?, ?, 'customer', 'active')
    ");
    
    $name_parts = explode(' ', $input['customer_name'], 2);
    $firstname = $name_parts[0];
    $lastname = $name_parts[1] ?? '';
    
    // Generate a random password (user will need to reset it)
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    
    if ($stmt->execute([$firstname, $lastname, $input['customer_email'], $password])) {
        return $pdo->lastInsertId();
    }
    
    return false;
}

function sendWarrantyRegistrationConfirmation($email, $serial_number, $warranty_end_date) {
    // Implement email sending here
    error_log("Warranty registration confirmation sent to $email for serial $serial_number, expires $warranty_end_date");
}

function sendWarrantyClaimNotification($user_id, $claim_number, $product_name) {
    // Implement email sending here
    error_log("Warranty claim notification sent for claim $claim_number, product $product_name");
}
?>
