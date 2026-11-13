<?php
// auth/profile_upload.php - Handle profile photo upload/remove
// Load core app functions (session, DB, helpers)
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['success'=>false,'message'=>'Login required']); exit; }

$userId = getUserId();
$action = $_POST['action'] ?? '';

// Choose target directory based on role (support all roles)
$isCustomerRole = function_exists('isCustomer') && isCustomer();
$dir = $isCustomerRole
    ? (__DIR__ . '/../customer/img/profiles')
    : (__DIR__ . '/../img/profiles');
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
// Validate target directory writability for clearer errors
if (!is_dir($dir)) {
    echo json_encode(['success'=>false,'message'=>'Target folder not found: ' . $dir]);
    exit;
}
if (!is_writable($dir)) {
    echo json_encode(['success'=>false,'message'=>'Target folder is not writable: ' . $dir]);
    exit;
}

function removeExistingFiles($dir, $userId){
    foreach (['jpg','jpeg','png','webp'] as $ext){
        $f = $dir . '/user_' . $userId . '.' . $ext;
        if (file_exists($f)) { @unlink($f); }
    }
}

try {
    if ($action === 'remove') {
        removeExistingFiles($dir, $userId);
        // Clear DB-backed avatar as well to keep sources consistent
        if (function_exists('clearUserProfilePhoto')) { 
            $cleared = @clearUserProfilePhoto($userId);
            if (!$cleared) {
                echo json_encode(['success'=>false,'message'=>'Failed to clear database record']);
                exit;
            }
        }
        // Clear session cache
        unset($_SESSION['profile_photo_rel']);
        echo json_encode(['success'=>true]);
        exit;
    }

    // Upload
    if (!isset($_FILES['photo'])) {
        echo json_encode(['success'=>false,'message'=>'No file uploaded (missing field).']);
        exit;
    }
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $err = (int)$_FILES['photo']['error'];
        $msg = 'Upload failed';
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $msg = 'Image too large (server limit).'; }
        elseif ($err === UPLOAD_ERR_PARTIAL) { $msg = 'Upload was not completed. Please try again.'; }
        elseif ($err === UPLOAD_ERR_NO_FILE) { $msg = 'No file selected.'; }
        elseif ($err === UPLOAD_ERR_NO_TMP_DIR) { $msg = 'Server temp folder missing.'; }
        elseif ($err === UPLOAD_ERR_CANT_WRITE) { $msg = 'Server cannot write file (permissions).'; }
        elseif ($err === UPLOAD_ERR_EXTENSION) { $msg = 'Upload blocked by a PHP extension.'; }
        echo json_encode(['success'=>false,'message'=>$msg]);
        exit;
    }

    $file = $_FILES['photo'];
    if ($file['size'] > 3 * 1024 * 1024) { echo json_encode(['success'=>false,'message'=>'File too large (max 3MB)']); exit; }

    // Detect mime/type robustly even if fileinfo is missing
    $mime = null; $ext = null;
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/jpg'   => 'jpg',
        'image/png'   => 'png',
        'image/x-png' => 'png',
        'image/webp'  => 'webp'
    ];
    if (function_exists('finfo_open')) {
        $f = @finfo_open(FILEINFO_MIME_TYPE);
        if ($f) { $mime = @finfo_file($f, $file['tmp_name']); @finfo_close($f); }
    }
    if (!$mime && function_exists('exif_imagetype')) {
        $type = @exif_imagetype($file['tmp_name']);
        $map = [IMAGETYPE_JPEG=>'image/jpeg', IMAGETYPE_PNG=>'image/png', IMAGETYPE_WEBP=>'image/webp'];
        $mime = $map[$type] ?? null;
    }
    if (!$mime && function_exists('getimagesize')) {
        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? null;
    }
    if ($mime && isset($allowed[$mime])) {
        $ext = $allowed[$mime];
    }
    // Fallback to extension check
    if (!$ext) {
        $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($origExt, ['jpg','jpeg','png','webp'])) {
            $ext = $origExt === 'jpeg' ? 'jpg' : $origExt;
        }
    }
    if (!$ext) { echo json_encode(['success'=>false,'message'=>'Invalid image type. Use JPG, PNG or WEBP.']); exit; }
    // Read uploaded bytes
    $bytes = @file_get_contents($file['tmp_name']);
    if ($bytes === false) { echo json_encode(['success'=>false,'message'=>'Failed to read uploaded file']); exit; }

    // Persist strictly to customer-specific filesystem path (no DB blob)
    removeExistingFiles($dir, $userId);
    $target = $dir . '/user_' . $userId . '.' . $ext;
    // Ensure tmp file is recognized as upload and fallback to rename if needed (dev env)
    $moved = false;
    if (is_uploaded_file($file['tmp_name'])) {
        $moved = @move_uploaded_file($file['tmp_name'], $target);
    }
    if (!$moved) {
        $moved = @rename($file['tmp_name'], $target);
    }
    if (!$moved) {
        echo json_encode(['success'=>false,'message'=>'Failed to save file to: ' . $target]);
        exit;
    }
    $relative = $isCustomerRole
        ? ('customer/img/profiles/user_' . $userId . '.' . $ext)
        : ('img/profiles/user_' . $userId . '.' . $ext);
    
    // Store image data in database for better reliability
    if (function_exists('setUserProfilePhotoBytes')) {
        $success = @setUserProfilePhotoBytes($userId, $bytes, $mime);
        if ($success) {
            // Clear session cache to force refresh
            unset($_SESSION['profile_photo_rel']);
            echo json_encode(['success'=>true,'path'=>$relative]);
            exit;
        }
    }
    
    // Fallback to filesystem-only storage
    if (function_exists('setUserProfilePhotoPath')) { @setUserProfilePhotoPath($userId, $relative); }
    $_SESSION['profile_photo_rel'] = $relative;
    echo json_encode(['success'=>true,'path'=>$relative]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}

?>


