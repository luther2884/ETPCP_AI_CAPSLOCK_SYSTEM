<?php
// auth/update_profile.php - Update basic account information

require_once __DIR__ . '/../../../config/functions.php';

// Ensure errors are logged, not echoed into JSON responses (dev safe)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['success'=>false,'message'=>'Login required']); exit; }

// CSRF check (lenient on localhost in helper)
if (!check_csrf()) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid session. Please refresh and try again.']); exit; }

$userId = getUserId();

// Collect and sanitize inputs
$firstname = trim($_POST['firstname'] ?? '');
$lastname  = trim($_POST['lastname'] ?? '');
$email     = trim($_POST['email'] ?? '');
$phone     = trim($_POST['phone'] ?? '');
$address   = trim($_POST['address'] ?? '');

// Basic validation
$errors = [];
if ($firstname === '') { $errors['firstname'] = 'First name is required'; }
if ($lastname === '')  { $errors['lastname']  = 'Last name is required'; }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Valid email is required'; }
if (strlen($phone) > 0 && !preg_match('/^[+0-9\s\-()]{6,30}$/', $phone)) { $errors['phone'] = 'Invalid phone number'; }

if (!empty($errors)) { http_response_code(422); echo json_encode(['success'=>false,'errors'=>$errors,'message'=>'Please correct the highlighted fields.']); exit; }

try {
    $pdo = getConnection();

    // Uniqueness check for email if changed
    $curStmt = $pdo->prepare('SELECT email, firstname, lastname, address, phone FROM users WHERE id = ?');
    $curStmt->execute([$userId]);
    $current = $curStmt->fetch();
    if (!$current) { echo json_encode(['success'=>false,'message'=>'User not found']); exit; }

    if (strcasecmp($current['email'], $email) !== 0) {
        $uniq = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $uniq->execute([$email, $userId]);
        if ((int)$uniq->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'errors'=>['email'=>'Email is already in use'], 'message'=>'Email already in use']);
            exit;
        }
    }

    // Update
    $upd = $pdo->prepare('UPDATE users SET firstname = ?, lastname = ?, email = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?');
    $upd->execute([$firstname, $lastname, $email, $phone ?: null, $address ?: null, $userId]);

    // Audit
    if (function_exists('logAudit')) {
        logAudit('update_profile', 'users', $userId, $current, [
            'firstname'=>$firstname,'lastname'=>$lastname,'email'=>$email,'phone'=>$phone,'address'=>$address
        ]);
    }

    // Optionally notify
    if (function_exists('addNotification')) {
        addNotification($userId, 'Profile Updated', 'Your account information was updated successfully.', 'security');
    }

    // Refresh session cache so UI reflects changes immediately
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $_SESSION['firstname'] = $firstname;
    $_SESSION['lastname']  = $lastname;
    // If app uses username field for display, keep a sensible fallback
    if (empty($_SESSION['username'])) {
        $_SESSION['username'] = trim($firstname . ' ' . $lastname);
    }

    echo json_encode([
        'success' => true,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'displayName' => trim($firstname . ' ' . $lastname)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('update_profile error: ' . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Server error']);
}

?>


