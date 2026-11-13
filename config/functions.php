<?php
// includes/functions.php - Core application functions

if (session_status() !== PHP_SESSION_ACTIVE) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Shared/AJAX endpoints (api/, scan/) live outside admin/ and super-admin/,
    // so they'd otherwise always fall into the customer session bucket even when
    // called from an admin page - use the Referer to route them correctly instead.
    if (strpos($uri, '/public/authenticated/api/') !== false || strpos($uri, '/public/authenticated/scan/') !== false) {
        $uri = $_SERVER['HTTP_REFERER'] ?? $uri;
    }
    if (strpos($uri, '/public/authenticated/super-admin/') !== false) {
        @session_name('TECHWRLD_SA');
    } elseif (strpos($uri, '/public/authenticated/courier/') !== false) {
        // Riders/couriers are their own area with their own bucket. Checked
        // BEFORE the admin branch: courier/ is a sibling of admin/, not a
        // child, but keeping the order explicit stops a future path like
        // admin/courier/ from silently falling into the wrong one. Without a
        // branch here the whole area would land in TECHWRLD_CUST and share a
        // session with whatever shopper is signed in in the same browser -
        // see loginUrlForArea() below for why that matters.
        @session_name('TECHWRLD_CRR');
    } elseif (strpos($uri, '/public/authenticated/admin/') !== false) {
        @session_name('TECHWRLD_ADM');
    } else {
        @session_name('TECHWRLD_CUST');
    }
    session_start();
}
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/database.php';
// Per-unit (serial/IMEI) stock. Needed by the two cancellation rollbacks below
// (cancelPickupOrderAndRestoreStock and cancelExpiredOnlinePaymentOrders),
// which have to release the specific units an order claimed and not just the
// quantity. Safe to pull in here: product_units.php depends only on
// database.php and product_validation.php, never back on this file.
require_once __DIR__ . '/product_units.php';
// PHPMailer (lazy-loaded in sendEmail)

// Appends a `?v=<mtime>` cache-buster to a WEB_ROOT-relative asset path, so
// browsers automatically fetch a fresh copy whenever the file changes on
// disk instead of serving a stale cached CSS/JS file after a normal reload
// (style.css/admin.css had no cache-busting at all, so edits could go
// unnoticed until a hard refresh - this makes that automatic going forward).
function asset_url($relativePath) {
    $fsPath = ($_SERVER['DOCUMENT_ROOT'] ?? '') . WEB_ROOT . $relativePath;
    $mtime = @filemtime($fsPath);
    return WEB_ROOT . $relativePath . ($mtime ? '?v=' . $mtime : '');
}

// CSRF Protection Functions
function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

// Is this request being served by a local/dev environment (localhost or a
// private/reserved server IP)? Extracted from check_csrf()'s $strict=false
// bypass logic (unchanged behavior there) so other dev-only features - e.g.
// the Live Delivery Tracking GPS simulator - can reuse the same check
// instead of re-implementing it.
function isLocalDevEnvironment(): bool {
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';

    if ($serverName === 'localhost' || $serverName === '127.0.0.1') {
        return true;
    }

    // Check if accessing via private/local IP address (192.168.x.x, 10.x.x.x, 172.16-31.x.x, 127.x.x.x)
    // filter_var with FILTER_FLAG_NO_PRIV_RANGE returns false for private IPs
    if (filter_var($serverName, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        if (filter_var($serverName, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true; // It's a private/reserved IP
        }
    }

    // Also check SERVER_ADDR for additional safety
    if (filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        if (filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true; // Server is on a private IP
        }
    }

    return false;
}

function check_csrf($strict = true) {
    // Allow bypassing CSRF check for local development environments
    if (!$strict && isLocalDevEnvironment()) {
        return true;
    }

    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// Best-effort city-level geocode of free-text address input (e.g. a
// user_addresses.region value) against the curated PH locations dataset
// already used for address autocomplete (config/ph_locations.php /
// api/address_suggestions.php's search_locations action - same scoring
// logic, duplicated here rather than shared so this file doesn't have to
// require that API file's own auth/header side effects). Returns the best
// match's centroid, or null if nothing scores above zero. City-centroid
// precision only - there is no per-order geocoded street address anywhere
// in this system today, so this is the customer delivery-address pin's
// best available accuracy for the Live Delivery Tracking map.
function geocodeApproxFromRegionText(string $regionText): ?array {
    $regionText = trim($regionText);
    if ($regionText === '') { return null; }

    require_once __DIR__ . '/ph_locations.php';

    $matchScore = function ($haystack, $needle) {
        $haystack = mb_strtolower(trim((string)$haystack));
        $needle = mb_strtolower(trim((string)$needle));
        if ($needle === '') { return 0; }
        if (strpos($haystack, $needle) === 0) { return 3; }
        if (strpos($haystack, $needle) !== false) { return 2; }
        if (function_exists('levenshtein') && mb_strlen($needle) >= 3) {
            foreach (preg_split('/[\s,]+/', $haystack) as $word) {
                if ($word === '') { continue; }
                $maxLen = max(mb_strlen($word), mb_strlen($needle));
                $dist = levenshtein(substr($word, 0, 255), substr($needle, 0, 255));
                if ($maxLen > 0 && $dist <= 2 && ($dist / $maxLen) <= 0.4) {
                    return 1;
                }
            }
        }
        return 0;
    };

    $regionNameById = [];
    foreach (getPhRegions() as $r) { $regionNameById[$r['id']] = $r['name']; }

    // Free-text addresses are written as either "City, Province" or "City,
    // RegionName" (province and region name don't always match, e.g. Baguio
    // City's province is Benguet but its region is North Luzon) - score
    // against both combinations and keep whichever scores higher, so either
    // format matches.
    $best = null;
    $bestScore = 0;
    foreach (getPhLocations() as $loc) {
        $regionName = $regionNameById[$loc['region_id']] ?? '';
        $searchableProvince = $loc['name'] . ', ' . $loc['province'];
        $searchableRegion = $loc['name'] . ', ' . $regionName;
        $score = max($matchScore($searchableProvince, $regionText), $matchScore($searchableRegion, $regionText));
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $loc;
        }
    }

    return $best ? ['lat' => (float)$best['lat'], 'lng' => (float)$best['lng'], 'label' => $best['name']] : null;
}

// Authentication Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getUserRole() {
    return $_SESSION['user_role'] ?? null;
}

function isCustomer() {
    return getUserRole() === 'customer';
}

function isDriver() {
    return getUserRole() === 'driver';
}

function isAdmin() {
    $role = getUserRole();
    return $role === 'admin' || $role === 'manager';
}

function isSuperAdmin() {
    $role = getUserRole();
    return $role === 'super-admin' || $role === 'owner';
}

function isManager() {
    return isAdmin();
}

function isOwner() {
    return isSuperAdmin();
}

// Which login page an unauthenticated request should be bounced to. Each
// panel has its own login page AND its own session bucket (see the
// session_name() routing at the top of this file: TECHWRLD_SA / TECHWRLD_ADM
// / TECHWRLD_CUST). So when an Owner or Manager session lapses (idle GC,
// server restart, lost cookie), the request must return to that panel's
// login - not the customer one. Previously every area fell back to the
// customer login (public/guest/login.php -> guest/index.php); if a customer
// happened to be signed in in the same browser, guest/index.php's
// "logged-in customer -> their homepage" redirect silently forwarded the
// Owner straight into that customer's account.
function loginUrlForArea() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // AJAX endpoints under api/ and scan/ live outside the panel folders -
    // route them by Referer, same as the session_name() logic does.
    if (strpos($uri, '/public/authenticated/api/') !== false || strpos($uri, '/public/authenticated/scan/') !== false) {
        $uri = $_SERVER['HTTP_REFERER'] ?? $uri;
    }
    if (strpos($uri, '/public/authenticated/super-admin/') !== false) {
        return WEB_ROOT . 'public/authenticated/super-admin/index.php';
    }
    if (strpos($uri, '/public/authenticated/courier/') !== false) {
        return WEB_ROOT . 'public/authenticated/courier/login.php';
    }
    if (strpos($uri, '/public/authenticated/admin/') !== false) {
        return WEB_ROOT . 'public/authenticated/admin/index.php';
    }
    return WEB_ROOT . 'public/guest/login.php';
}

function require_login() {
    if (!isLoggedIn()) {
        header('Location: ' . loginUrlForArea());
        exit;
    }
    // Enforce post-login verification for customers
    $role = getUserRole();
    if ($role === 'customer') {
        $verified = isset($_SESSION['login_2fa_verified']) && $_SESSION['login_2fa_verified'] === true;
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $onVerifyPage = (strpos($uri, '/public/guest/verify_login_code.php') !== false);
        // If not verified in-session, see if a valid remember-2FA cookie is present
        if (!$verified && function_exists('checkRemember2faCookie')) {
            if (checkRemember2faCookie((int)getUserId())) {
                $_SESSION['login_2fa_verified'] = true;
                $verified = true;
            }
        }
        if (!$verified && !$onVerifyPage) {
            header('Location: ' . WEB_ROOT . 'public/guest/verify_login_code.php');
            exit;
        }
    }
}

function require_role($roles) {
    require_login();
    if (!in_array(getUserRole(), $roles)) {
        // Wrong role for this panel. Bounce to the panel's own login rather
        // than the site root - index.php forwards to guest/index.php, which
        // then forwards a signed-in customer into their account, so an
        // Owner/Manager session with a missing or stale role would land in a
        // random customer's account (see loginUrlForArea()).
        $areaLogin = loginUrlForArea();
        if ($areaLogin === WEB_ROOT . 'public/guest/login.php') {
            $areaLogin = WEB_ROOT . 'index.php?error=access_denied';
        }
        header('Location: ' . $areaLogin);
        exit;
    }
}

function getUserData() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([getUserId()]);
    return $stmt->fetch();
}

function getUserHomepage($role) {
    switch ($role) {
        case 'owner':
        case 'super-admin':
            return WEB_ROOT . 'public/authenticated/super-admin/dashboard.php';
        case 'manager':
        case 'admin':
            return WEB_ROOT . 'public/authenticated/admin/dashboard.php';
        case 'driver':
            // Riders/couriers live in their own area (public/authenticated/
            // courier/), not under admin/ - separate URL, separate session
            // bucket, separate login. Their landing page is the dispatch
            // board, their primary task, not the QR pickup scanner
            // (scan/verify.php), which remains reachable directly by URL.
            return WEB_ROOT . 'public/authenticated/courier/dispatch.php';
        case 'customer':
        default:
            return WEB_ROOT . 'public/authenticated/customer/index.php';
    }
}

// ===== Remember 2FA (5m) Helpers =====
// Sign the cookie value with HMAC using application secret derived from DB credentials + salt
function remember2faCookieName($userId){ return 'tw_r2fa_' . (int)$userId; }
function remember2faSecret(){
    // Derive a stable secret without exposing credentials
    $salt = 'twlocal_salt_v1';
    $dbDsn = defined('DB_NAME') ? DB_NAME : 'techworld';
    return hash('sha256', $salt . '|' . $dbDsn);
}
function makeRemember2faValue($userId, $passwordHash, $expiresTs){
    $data = $userId . '|' . $expiresTs . '|' . substr(hash('sha256',$passwordHash),0,16);
    $sig = hash_hmac('sha256', $data, remember2faSecret());
    return $data . '|' . $sig;
}
function setRemember2faCookie($userId, $passwordHash, $ttlSeconds = 300){
    $exp = time() + (int)$ttlSeconds;
    $val = makeRemember2faValue((int)$userId, (string)$passwordHash, $exp);
    // Cookie for customer area only
    $path = rtrim(WEB_ROOT, '/') . '/';
    setcookie(remember2faCookieName($userId), $val, [
        'expires' => $exp,
        'path' => $path,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
function clearRemember2faCookie($userId){
    $path = rtrim(WEB_ROOT, '/') . '/';
    setcookie(remember2faCookieName($userId), '', [
        'expires' => time() - 3600,
        'path' => $path,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
function checkRemember2faCookie($userId){
    $name = remember2faCookieName((int)$userId);
    if (empty($_COOKIE[$name])) { return false; }
    $parts = explode('|', $_COOKIE[$name]);
    if (count($parts) !== 4) { return false; }
    list($u, $exp, $pwSig, $sig) = $parts;
    if ((int)$u !== (int)$userId) { return false; }
    if (time() > (int)$exp) { return false; }
    $data = $u . '|' . $exp . '|' . $pwSig;
    $calc = hash_hmac('sha256', $data, remember2faSecret());
    if (!hash_equals($calc, $sig)) { return false; }
    // Verify current password hash matches the signature fragment
    try{
        $pdo = getConnection();
        $st = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $st->execute([(int)$userId]);
        $hash = (string)$st->fetchColumn();
        return substr(hash('sha256', $hash),0,16) === $pwSig;
    }catch(Throwable $e){ return false; }
}

function logout() {
    $userId = getUserId();
    if ($userId) {
        // Staff sign-outs are not audited. A manager or owner ending their
        // session is routine housekeeping, not a change to anything, and at
        // one row per sign-out it crowded out the entries that do matter.
        // Customer sign-outs are still recorded - they sit alongside that
        // account's orders and are useful when tracing one.
        [, $__logoutRole, ] = function_exists('auditActorContext') ? auditActorContext() : [null, null, null];
        if (!in_array((string)$__logoutRole, ['admin', 'manager', 'super-admin', 'superadmin', 'owner'], true)) {
            logAudit('logout', null, null);
        }
        // Clear the user's cart in session only; keep DB cart for persistence across logout
        if (isset($_SESSION['cart'][$userId])) {
            unset($_SESSION['cart'][$userId]);
        }
    }

    // Fully clear session and cookie
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Admin Request Functions
function createAdminApprovalRequest($userId, $message) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("INSERT INTO admin_requests (user_id, request_message) VALUES (?, ?)");
        $success = $stmt->execute([$userId, $message]);
        
        if ($success) {
            logAudit('admin_request_created', 'admin_requests', $pdo->lastInsertId());
            
            // Notify super admins
            notifySuperAdmins('New Admin Request', 'A new admin access request has been submitted and requires review.');
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error creating admin request: " . $e->getMessage());
        return false;
    }
}

function getPendingAdminRequests() {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("
            SELECT ar.*, u.firstname, u.lastname, u.email 
            FROM admin_requests ar 
            JOIN users u ON ar.user_id = u.id 
            WHERE ar.status = 'pending' 
            ORDER BY ar.created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting pending admin requests: " . $e->getMessage());
        return [];
    }
}

function approveAdminRequest($requestId, $reviewedBy) {
    try {
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        // Get request details
        $stmt = $pdo->prepare("SELECT user_id FROM admin_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            $pdo->rollback();
            return false;
        }
        
        // Update user role and status
        $stmt = $pdo->prepare("UPDATE users SET role = 'admin', status = 'active' WHERE id = ?");
        $stmt->execute([$request['user_id']]);
        
        // Update admin request
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$reviewedBy, $requestId]);
        
        $pdo->commit();
        
        // Log and notify
        logAudit('admin_request_approved', 'admin_requests', $requestId);
        addNotification($request['user_id'], 'Admin Access Approved', 'Your admin access request has been approved. You can now login with admin privileges.');
        
        return true;
    } catch (PDOException $e) {
        $pdo->rollback();
        error_log("Error approving admin request: " . $e->getMessage());
        return false;
    }
}

function denyAdminRequest($requestId, $reviewedBy, $notes = '') {
    try {
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        // Get request details
        $stmt = $pdo->prepare("SELECT user_id FROM admin_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            $pdo->rollback();
            return false;
        }
        
        // Update user status
        $stmt = $pdo->prepare("UPDATE users SET status = 'denied' WHERE id = ?");
        $stmt->execute([$request['user_id']]);
        
        // Update admin request
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'denied', reviewed_by = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$reviewedBy, $notes, $requestId]);
        
        $pdo->commit();
        
        // Log and notify
        logAudit('admin_request_denied', 'admin_requests', $requestId);
        $message = 'Your admin access request has been denied.' . ($notes ? ' Reason: ' . $notes : '');
        addNotification($request['user_id'], 'Admin Access Denied', $message);
        
        return true;
    } catch (PDOException $e) {
        $pdo->rollback();
        error_log("Error denying admin request: " . $e->getMessage());
        return false;
    }
}

// Notification Functions
function addNotification($userId, $title, $message, $type = 'info') {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $title, $message, $type]);
    } catch (PDOException $e) {
        error_log("Error adding notification: " . $e->getMessage());
        return false;
    }
}

function getUnreadNotifications($userId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting notifications: " . $e->getMessage());
        return [];
    }
}

function getUnreadNotificationCount($userId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error getting notification count: " . $e->getMessage());
        return 0;
    }
}

function notifySuperAdmins($title, $message, $type = 'info') {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'super-admin' AND status = 'active'");
        $stmt->execute();
        $superAdmins = $stmt->fetchAll();
        
        foreach ($superAdmins as $admin) {
            addNotification($admin['id'], $title, $message, $type);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error notifying super admins: " . $e->getMessage());
        return false;
    }
}

// Audit Log Functions

/**
 * Who is doing this, and in what capacity.
 *
 * The Admin panel can be driven by a Super Admin working "as an Admin"
 * (super-admin/switch_to_admin.php). That flow deliberately keeps the SUPER
 * ADMIN's own user_id in the admin session and only rewrites user_role to
 * 'admin', so user_id already names the right person - but nothing recorded
 * that they were operating from the Admin side. The audit screen has to be
 * able to say "the Owner did this, from the Manager side", so it is captured
 * here rather than guessed at read time.
 *
 * actor_role is the person's REAL role, resolved from the users table, not
 * the session's user_role (which sa_mode overwrites with 'admin'). It is
 * stored per row because roles change and accounts get deleted; a log that
 * re-derives "who they were" from today's users table would quietly rewrite
 * its own history.
 *
 * @return array{0:?int,1:?string,2:?string} [user id, real role, acting-as role]
 */
function auditActorContext() {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $uid = function_exists('getUserId') ? getUserId() : ($_SESSION['user_id'] ?? null);
    $uid = $uid ? (int)$uid : null;
    $sessionRole = $_SESSION['user_role'] ?? null;
    // Only set when this session is a Super Admin driving the admin panel.
    $actingAs = !empty($_SESSION['sa_mode']) ? ($sessionRole ?: 'admin') : null;

    $realRole = $sessionRole;
    if ($uid) {
        try {
            $st = getConnection()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $dbRole = $st->fetchColumn();
            if ($dbRole !== false && $dbRole !== null && $dbRole !== '') { $realRole = (string)$dbRole; }
        } catch (Throwable $e) {
            // Fall back to the session's idea of the role; an audit row with a
            // slightly less precise role beats no audit row at all.
        }
    }
    // Acting as yourself is not worth recording as a distinct capacity.
    if ($actingAs !== null && $actingAs === $realRole) { $actingAs = null; }

    $cache = [$uid, $realRole ? (string)$realRole : null, $actingAs];
    return $cache;
}

/**
 * Do the audit context columns exist on this database?
 *
 * They were added after the table shipped (see DBSQL/10_users_and_auth.sql).
 * Checked once per request so an installation that has not run the newer
 * schema still records audit rows with the original columns instead of
 * throwing on every logged action.
 */
function auditHasContextColumns() {
    static $has = null;
    if ($has !== null) { return $has; }
    try {
        $has = (bool)getConnection()->query("SHOW COLUMNS FROM audit_logs LIKE 'actor_role'")->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * Record one action in the audit trail.
 *
 * $status is 'success' or 'failed'. It defaults to success because every
 * existing caller writes its audit row only after the operation it describes
 * has actually gone through - so the default preserves that meaning. Pass
 * 'failed' (with $detail saying why) when recording an ATTEMPT, so the audit
 * screen can show it as something that did not complete rather than as a
 * change that took effect.
 */
function logAudit($action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null, $status = 'success', $detail = null) {
    try {
        $pdo = getConnection();
        $status = ($status === 'failed') ? 'failed' : 'success';

        if (!auditHasContextColumns()) {
            // Original schema - keep the pre-existing behaviour exactly.
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            return $stmt->execute([
                getUserId(),
                $action,
                $tableName,
                $recordId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        }

        [$uid, $actorRole, $actingAs] = auditActorContext();
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, actor_role, acting_as, action, status, table_name, record_id, old_values, new_values, detail, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            $uid,
            $actorRole,
            $actingAs,
            $action,
            $status,
            $tableName,
            $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $detail !== null ? mb_substr((string)$detail, 0, 255) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log("Error logging audit: " . $e->getMessage());
        return false;
    }
}

/** Convenience wrapper for recording an attempt that did NOT go through. */
function logAuditFailure($action, $tableName = null, $recordId = null, $detail = null, $oldValues = null, $newValues = null) {
    return logAudit($action, $tableName, $recordId, $oldValues, $newValues, 'failed', $detail);
}

// Order Functions
function generateInvoiceNumber() {
    return 'INV-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateTrackingCode() {
    return 'TW-' . strtoupper(bin2hex(random_bytes(4)));
}

function generatePickupCode() {
    return str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Cancel a pickup order due to QR expiry and restore stock atomically
 */
function cancelPickupOrderAndRestoreStock(int $orderId): bool {
    try {
        $pdo = getConnection();
        $pdo->beginTransaction();
        // Load order. SELECT * rather than a column list so credit_applied can
        // be read with a ?? default on schemas that predate it.
        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) { $pdo->rollBack(); return false; }
        if (($order['delivery_method'] ?? '') !== 'pickup') { $pdo->rollBack(); return false; }
        if (($order['status'] ?? '') === 'cancelled') { $pdo->rollBack(); return true; }

        // Fetch items
        $iStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $iStmt->execute([$orderId]);
        $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

        // Restore stock per item
        foreach ($items as $it) {
            $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")
                ->execute([(int)$it['quantity'], (int)$it['product_id']]);
            // Stock movement log.
            //
            // This used to write movement_type = 'restock', which that column's
            // ENUM does not accept - it is ENUM('in','out','adjustment'). The
            // server runs without STRICT_TRANS_TABLES, so the INSERT raised a
            // warning rather than an error and the try/catch below swallowed
            // it, which means every pickup-expiry rollback since this code was
            // written logged nothing at all. 'in' is the value the column has
            // always had for stock arriving; DBSQL/add_product_unit_tracking.sql
            // additionally widens the ENUM to accept 'restock' so a future
            // caller cannot reintroduce the same silent failure.
            try {
                $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes) VALUES (?, 'in', ?, 'order', ?, 'Pickup expired - rollback')")
                    ->execute([(int)$it['product_id'], (int)$it['quantity'], $orderId]);
            } catch (Throwable $_e) {}
        }

        // Put the specific physical units back on the shelf. For a serialised
        // product the unit rows are the truth and products.stock is a mirror of
        // them, so the quantity restore above is not enough on its own.
        // pu_release_for_order() recomputes that mirror, deliberately
        // overwriting the += for tracked products only.
        pu_release_for_order($pdo, $orderId);

        // Give back any store credit that was spent on this order. The credit
        // is deducted from the customer's ledger at checkout, so an expiry
        // rollback that only restocks the shelf would quietly keep their
        // money - the stock came back, the credit has to as well. Zeroing
        // credit_applied makes a second pass through here a no-op.
        $creditApplied = round((float)($order['credit_applied'] ?? 0), 2);
        if ($creditApplied > 0) {
            $refundId = addStoreCredit(
                (int)$order['user_id'], $creditApplied, 'cancellation_credit', $orderId, null,
                "Store credit returned - pickup hold on order #$orderId expired"
            );
            if ($refundId === false) { $pdo->rollBack(); return false; }
            $pdo->prepare("UPDATE orders SET credit_applied = 0 WHERE id = ?")->execute([$orderId]);
        }

        // Mark order cancelled
        $uStmt = $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $uStmt->execute([$orderId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        try { getConnection()->rollBack(); } catch (Throwable $_) {}
        error_log('cancelPickupOrderAndRestoreStock error: ' . $e->getMessage());
        return false;
    }
}

// ===== Super Admin credentials =================================================
// The Super Admin signs in against these in-code constants, NOT against a
// users.password hash - see super-admin/login.php and login_check.php, which
// compare the posted password to SUPER_ADMIN_PASSWORD directly.
//
// They were previously copy-pasted into both of those files and again into
// ensureSuperAdminExists() below. Defined once here so the login form and
// anything that re-checks the password (the admin inactivity lock) can never
// disagree about what the Super Admin's password actually is.
if (!defined('SUPER_ADMIN_EMAIL'))    { define('SUPER_ADMIN_EMAIL', 'superadmin@local'); }
if (!defined('SUPER_ADMIN_PASSWORD')) { define('SUPER_ADMIN_PASSWORD', 'superadmin123'); }

/**
 * The users.id of the Super Admin row, or 0 if it cannot be resolved.
 *
 * The login forms used to put 0 in $_SESSION['user_id'] ("no DB lookup
 * needed"). Nothing in the users table has id 0, so every later lookup keyed
 * on that id silently found nothing - which is how the admin lock ended up
 * rejecting the correct password: it fetched a hash for user 0, got false,
 * and failed every comparison.
 */
function superAdminUserId(): int {
    try {
        $stmt = getConnection()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([SUPER_ADMIN_EMAIL]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('superAdminUserId: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Is the current session the constant-credential Super Admin?
 *
 * True both on the super-admin panel and in Admin Mode, where
 * switch_to_admin.php rewrites user_role to 'admin' but carries the same
 * identity across. The email check is the fallback for sessions that were
 * already open before sa_constant_auth started being set.
 */
function isConstantSuperAdminSession(): bool {
    if (!empty($_SESSION['sa_constant_auth'])) { return true; }
    return isset($_SESSION['email']) && $_SESSION['email'] === SUPER_ADMIN_EMAIL;
}

/**
 * Verify a password against whoever is signed in, for re-authentication
 * prompts (currently the admin inactivity lock).
 *
 * Two kinds of account reach this:
 *   - Normal admins/managers, whose password is a bcrypt hash in users.password.
 *   - The Super Admin, whose password is SUPER_ADMIN_PASSWORD above and whose
 *     users row hash is NOT kept in sync with it (ensureSuperAdminExists()
 *     only writes the hash when it first creates the row). Checking that row's
 *     hash for this account is therefore meaningless - the value it holds is
 *     not the password anyone signs in with.
 *
 * Fails closed: any error, or an unrecognised session, returns false.
 */
function verifyCurrentUserPassword(string $password): bool {
    if ($password === '') { return false; }

    $userId = (int)(getUserId() ?? 0);
    if ($userId > 0) {
        try {
            $stmt = getConnection()->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $hash = (string)$stmt->fetchColumn();
            if ($hash !== '' && password_verify($password, $hash)) { return true; }
        } catch (Throwable $e) {
            error_log('verifyCurrentUserPassword: ' . $e->getMessage());
            return false;
        }
    }

    // hash_equals: constant-time, so a wrong password cannot be narrowed down
    // by how long the comparison takes.
    if (isConstantSuperAdminSession() && hash_equals(SUPER_ADMIN_PASSWORD, $password)) {
        return true;
    }

    return false;
}

// ===== Rider duty + automatic delivery assignment =====
//
// Deliveries are assigned by the system, not picked from a dropdown. A rider
// qualifies when both things are true:
//
//   ON DUTY   they tapped "Go on duty" AND the dispatch board has sent a
//             heartbeat recently (users.driver_on_duty + driver_last_seen)
//   FREE      they have no shipped, assigned, not-yet-completed delivery
//
// The heartbeat is what makes the toggle safe: a rider who finishes for the
// day and closes the app stops being dispatchable on its own, instead of
// sitting at the head of the queue holding orders nobody is delivering.
//
// There is no cron in this project, so assignment runs opportunistically from
// the moments that actually change the answer - an order reaching 'shipped',
// a rider going on duty, a delivery being completed - plus a sweep on the
// pages that already look at this data. Same pattern as
// cancelExpiredOnlinePaymentOrders() above.

/** How long a rider stays "online" after their last heartbeat. Long enough to
 *  survive a tunnel, a locked screen or a dropped signal between drops; short
 *  enough that a rider who went home frees the queue within one order's
 *  worth of time. */
if (!defined('DRIVER_HEARTBEAT_TIMEOUT_MINUTES')) {
    define('DRIVER_HEARTBEAT_TIMEOUT_MINUTES', 10);
}

/** Deliveries one rider may hold at once. The brief is "if they are not on a
 *  delivery", i.e. one - but it is a constant rather than a hardcoded 1 so
 *  batching two or three drops later is a one-line change. */
if (!defined('DRIVER_MAX_ACTIVE_DELIVERIES')) {
    define('DRIVER_MAX_ACTIVE_DELIVERIES', 1);
}

/** Records that a rider's dispatch board is still open. Cheap enough to call
 *  on every heartbeat and every dispatch page load. */
function touchDriverHeartbeat(int $driverId): void {
    try {
        getConnection()
            ->prepare("UPDATE users SET driver_last_seen = NOW() WHERE id = ? AND role = 'driver'")
            ->execute([$driverId]);
    } catch (Throwable $e) {
        error_log('touchDriverHeartbeat error: ' . $e->getMessage());
    }
}

/**
 * Turns a rider's duty toggle on or off. Going ON duty also stamps the
 * heartbeat, so the rider is immediately dispatchable rather than waiting for
 * the first interval to fire.
 */
function setDriverDuty(int $driverId, bool $onDuty): bool {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare(
            "UPDATE users SET driver_on_duty = ?, driver_last_seen = " . ($onDuty ? 'NOW()' : 'driver_last_seen') .
            " WHERE id = ? AND role = 'driver'"
        );
        $stmt->execute([$onDuty ? 1 : 0, $driverId]);
        if (function_exists('logAudit')) {
            try { logAudit($onDuty ? 'driver_on_duty' : 'driver_off_duty', 'users', $driverId, null, []); }
            catch (Throwable $_e) {}
        }
        return true;
    } catch (Throwable $e) {
        error_log('setDriverDuty error: ' . $e->getMessage());
        return false;
    }
}

/** Is this rider on duty with a live heartbeat? */
function driverIsOnline(int $driverId): bool {
    try {
        $stmt = getConnection()->prepare("
            SELECT 1 FROM users
            WHERE id = ? AND role = 'driver' AND driver_on_duty = 1
              AND driver_last_seen IS NOT NULL
              AND driver_last_seen >= (NOW() - INTERVAL " . (int)DRIVER_HEARTBEAT_TIMEOUT_MINUTES . " MINUTE)
            LIMIT 1
        ");
        $stmt->execute([$driverId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('driverIsOnline error: ' . $e->getMessage());
        return false;
    }
}

/** How many deliveries this rider is currently out on. */
function driverActiveDeliveryCount(int $driverId): int {
    try {
        $stmt = getConnection()->prepare("
            SELECT COUNT(*) FROM orders
            WHERE assigned_driver_id = ? AND delivery_method = 'delivery'
              AND status = 'shipped' AND delivery_completed_at IS NULL
        ");
        $stmt->execute([$driverId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('driverActiveDeliveryCount error: ' . $e->getMessage());
        return PHP_INT_MAX; // fail closed: never hand work to a rider we can't read
    }
}

/**
 * Riders who could take a delivery right now, fairest first.
 *
 * Ordering is round-robin by last assignment: the rider whose most recent
 * delivery was handed out longest ago goes to the front, so work spreads
 * across the roster instead of always landing on whoever has the lowest id.
 * A rider who has never been assigned anything sorts first (NULL).
 *
 * @return array<int,array{id:int,name:string}>
 */
function availableDrivers(): array {
    try {
        $sql = "
            SELECT u.id,
                   CONCAT(u.firstname, ' ', u.lastname) AS name,
                   (SELECT MAX(o.id) FROM orders o WHERE o.assigned_driver_id = u.id) AS last_assigned
            FROM users u
            WHERE u.role = 'driver'
              AND u.status = 'active'
              AND u.driver_on_duty = 1
              AND u.driver_last_seen IS NOT NULL
              AND u.driver_last_seen >= (NOW() - INTERVAL " . (int)DRIVER_HEARTBEAT_TIMEOUT_MINUTES . " MINUTE)
              AND (
                    SELECT COUNT(*) FROM orders o2
                    WHERE o2.assigned_driver_id = u.id AND o2.delivery_method = 'delivery'
                      AND o2.status = 'shipped' AND o2.delivery_completed_at IS NULL
                  ) < " . (int)DRIVER_MAX_ACTIVE_DELIVERIES . "
            ORDER BY last_assigned IS NOT NULL, last_assigned ASC, u.id ASC
        ";
        return getConnection()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('availableDrivers error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Assign waiting deliveries to whoever is free, oldest order first.
 *
 * Safe to call from anywhere and as often as you like: it only ever touches
 * orders that still have no rider, and the UPDATE re-asserts that condition,
 * so two simultaneous callers cannot hand the same order to two riders.
 *
 * @return int how many orders were assigned
 */
function autoAssignPendingDeliveries(int $limit = 25): int {
    try {
        $pdo = getConnection();

        $waiting = $pdo->query("
            SELECT id FROM orders
            WHERE delivery_method = 'delivery'
              AND status = 'shipped'
              AND assigned_driver_id IS NULL
              AND delivery_completed_at IS NULL
            ORDER BY created_at ASC
            LIMIT " . max(1, $limit)
        )->fetchAll(PDO::FETCH_COLUMN);

        if (!$waiting) { return 0; }

        $assigned = 0;
        foreach ($waiting as $orderId) {
            // Re-read the roster each time round: the rider who just took the
            // previous order is no longer free, and their position in the
            // round-robin has moved.
            $candidates = availableDrivers();
            if (!$candidates) { break; }   // nobody free - the rest keep waiting
            $driver = $candidates[0];

            $upd = $pdo->prepare("
                UPDATE orders SET assigned_driver_id = ?, updated_at = NOW()
                WHERE id = ? AND assigned_driver_id IS NULL AND status = 'shipped'
            ");
            $upd->execute([(int)$driver['id'], (int)$orderId]);
            if ($upd->rowCount() === 0) { continue; }   // someone else got there first

            $assigned++;
            if (function_exists('logAudit')) {
                try {
                    logAudit('delivery_auto_assigned', 'orders', (int)$orderId, null, [
                        'driver_id' => (int)$driver['id'],
                        'driver_name' => $driver['name'],
                    ]);
                } catch (Throwable $_e) {}
            }
            // Tell the rider they have work, using the same notification
            // channel every other order event on this system uses.
            try {
                createNotification(
                    (int)$driver['id'],
                    'order_status',
                    'New delivery assigned',
                    'Order #' . $orderId . ' has been assigned to you. Open your dispatch board to start.',
                    ['order_id' => (int)$orderId, 'action' => 'delivery_assigned'],
                    'high'
                );
            } catch (Throwable $_e) {}
        }
        return $assigned;
    } catch (Throwable $e) {
        error_log('autoAssignPendingDeliveries error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * The pull counterpart to autoAssignPendingDeliveries(): a rider takes a
 * delivery for themselves.
 *
 * WHY THIS EXISTS. The push model above can only hand an order to a rider that
 * availableDrivers() calls free, and "free" means holding fewer than
 * DRIVER_MAX_ACTIVE_DELIVERIES (1) orders that are still `shipped` with no
 * delivery_completed_at. An order that is never completed therefore occupies
 * that single slot permanently, and a rider in that state can never be handed
 * anything again - so a shipped order can sit unassigned forever while riders
 * are on duty, appearing on nobody's board. That is not recoverable by
 * retrying: availableDrivers() returns empty, autoAssignPendingDeliveries()
 * breaks out of its loop, and nothing changes.
 *
 * Claiming fixes that by inverting who decides. The rider is holding the
 * parcel - possession is the authorisation, and it is a far better signal than
 * a round-robin guess made at the moment the order shipped. So this
 * deliberately does NOT apply the capacity cap: refusing the rider who has the
 * box in their hand would be absurd, and the cap's real job is to stop the
 * system pushing work at someone, not to stop someone taking it.
 *
 * Three ways a claim is allowed, all re-checked under a row lock so two riders
 * scanning the same label cannot both win:
 *   - the order has no rider at all
 *   - the order is already this rider's (a no-op, so the caller can claim
 *     unconditionally without checking first)
 *   - the order is held by a rider who is off duty or whose heartbeat has gone
 *     stale - a takeover. The displaced rider is told, and it is audited.
 *
 * A claim never changes orders.status: it only decides who delivers. The status
 * still moves through the courier board's own handlers (complete_delivery /
 * record_payment), which require a proof photo and the exact cash.
 *
 * Returns ['ok'=>bool, 'code'=>string, 'message'=>string, 'takeover'=>bool,
 *          'previous_driver_id'=>?int].
 */
function claimDeliveryForDriver(int $orderId, int $driverId): array {
    $fail = fn(string $code, string $msg) =>
        ['ok' => false, 'code' => $code, 'message' => $msg, 'takeover' => false, 'previous_driver_id' => null];

    if ($orderId <= 0 || $driverId <= 0) {
        return $fail('bad_input', 'Invalid order or rider.');
    }

    $pdo = getConnection();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, invoice_no, status, delivery_method, assigned_driver_id
            FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            $pdo->rollBack();
            return $fail('unknown', 'Order not found.');
        }

        $number = $order['invoice_no'] ?: ('ORD-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT));
        if ($order['delivery_method'] !== 'delivery') {
            $pdo->rollBack();
            return $fail('not_delivery', $number . ' is not a Home Delivery order.');
        }
        // Only an order actually out for delivery can be taken. 'completed' is
        // finished, and anything earlier has not left the store.
        if ($order['status'] !== 'shipped') {
            $pdo->rollBack();
            return $fail('not_shipped', $number . ' is not out for delivery (' . $order['status'] . ').');
        }

        $holder = (int)($order['assigned_driver_id'] ?? 0);
        if ($holder === $driverId) {
            $pdo->commit();
            return ['ok' => true, 'code' => 'already_yours', 'message' => $number . ' is already yours.',
                    'takeover' => false, 'previous_driver_id' => null];
        }
        // A rider who is on duty right now keeps their order - this is the one
        // case a claim is refused, so claiming can never quietly steal live work.
        if ($holder > 0 && driverIsOnline($holder)) {
            $pdo->rollBack();
            return $fail('held', $number . ' is being delivered by another rider who is on duty. Ask dispatch to reassign it.');
        }

        $upd = $pdo->prepare("
            UPDATE orders SET assigned_driver_id = ?, updated_at = NOW()
            WHERE id = ? AND status = 'shipped' AND delivery_method = 'delivery'
              AND " . ($holder > 0 ? "assigned_driver_id = ?" : "assigned_driver_id IS NULL"));
        $upd->execute($holder > 0 ? [$driverId, $orderId, $holder] : [$driverId, $orderId]);
        if ($upd->rowCount() === 0) {
            // Another rider claimed it between the lock and the write, or the
            // order moved on. Either way this rider did not get it.
            $pdo->rollBack();
            return $fail('race', 'Another rider took ' . $number . ' first. Scan it again to see where it stands.');
        }

        $pdo->commit();

        if (function_exists('logAudit')) {
            try {
                logAudit('delivery_claimed', 'orders', $orderId,
                    ['assigned_driver_id' => $holder ?: null],
                    ['assigned_driver_id' => $driverId, 'takeover' => $holder > 0]);
            } catch (Throwable $_e) {}
        }

        // Taking an order off someone must never be silent, even when they are
        // off duty - they may still think it is theirs.
        if ($holder > 0) {
            try {
                createNotification($holder, 'order_status', 'Delivery reassigned',
                    'Order ' . $number . ' was taken over by another rider while you were off duty.',
                    ['order_id' => $orderId, 'action' => 'delivery_reassigned'], 'normal');
            } catch (Throwable $_e) {}
        }

        return ['ok' => true, 'code' => $holder > 0 ? 'taken_over' : 'claimed',
                'message' => $number . ' is now assigned to you.',
                'takeover' => $holder > 0, 'previous_driver_id' => $holder ?: null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('claimDeliveryForDriver error: ' . $e->getMessage());
        return $fail('error', 'Could not take that delivery. Please try again.');
    }
}

/** Deliveries shipped but still waiting for a rider - drives the admin's
 *  "N waiting" readout. */
function pendingUnassignedDeliveryCount(): int {
    try {
        return (int)getConnection()->query("
            SELECT COUNT(*) FROM orders
            WHERE delivery_method = 'delivery' AND status = 'shipped'
              AND assigned_driver_id IS NULL AND delivery_completed_at IS NULL
        ")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// Auto-create super admin if none exists
function ensureSuperAdminExists() {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([SUPER_ADMIN_EMAIL]);
        $count = $stmt->fetchColumn();

        if ((int)$count === 0) {
            // Note: this hash is only ever written when the row is first
            // created, and the ON DUPLICATE clause deliberately leaves it
            // alone afterwards. It is therefore not a reliable copy of the
            // Super Admin's real password - SUPER_ADMIN_PASSWORD is.
            // verifyCurrentUserPassword() above is built around that.
            $hashedPassword = password_hash(SUPER_ADMIN_PASSWORD, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, email, password, role, status) VALUES (?, ?, ?, ?, 'super-admin', 'active') ON DUPLICATE KEY UPDATE role = 'super-admin', status = 'active'");
            $stmt->execute(['Super', 'Admin', SUPER_ADMIN_EMAIL, $hashedPassword]);

            error_log('Default super admin created: ' . SUPER_ADMIN_EMAIL);
        }
    } catch (PDOException $e) {
        error_log("Error ensuring super admin exists: " . $e->getMessage());
    }
}

// ===== User Profile helpers (avatar path persisted in DB) =====
function ensureUserProfilesTable() {
    try {
        $pdo = getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_profiles (
            user_id INT PRIMARY KEY,
            photo_path VARCHAR(255) NULL,
            /* Optional DB-backed photo storage */
            photo_blob LONGBLOB NULL,
            photo_mime VARCHAR(64) NULL,
            photo_updated_at TIMESTAMP NULL,
            bio TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Backward-compatible column adds (works for MySQL/MariaDB without IF NOT EXISTS)
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $missing = [];
        $columns = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user_profiles'");
        $columns->execute([$dbName]);
        $existing = array_map('strtolower', array_column($columns->fetchAll(), 'COLUMN_NAME'));
        $needBlob = !in_array('photo_blob', $existing, true);
        $needMime = !in_array('photo_mime', $existing, true);
        $needUpdated = !in_array('photo_updated_at', $existing, true);
        if ($needBlob) { try { $pdo->exec("ALTER TABLE user_profiles ADD COLUMN photo_blob LONGBLOB NULL"); } catch (Throwable $e) { /* ignore */ } }
        if ($needMime) { try { $pdo->exec("ALTER TABLE user_profiles ADD COLUMN photo_mime VARCHAR(64) NULL"); } catch (Throwable $e) { /* ignore */ } }
        if ($needUpdated) { try { $pdo->exec("ALTER TABLE user_profiles ADD COLUMN photo_updated_at TIMESTAMP NULL"); } catch (Throwable $e) { /* ignore */ } }
    } catch (Throwable $e) {
        error_log('Ensure user_profiles table failed: ' . $e->getMessage());
    }
}

function getUserProfilePhotoPath($userId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare('SELECT photo_path FROM user_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? ($row['photo_path'] ?: null) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function setUserProfilePhotoPath($userId, $relativePathOrNull) {
    try {
        $pdo = getConnection();
        // Upsert
        $stmt = $pdo->prepare('INSERT INTO user_profiles (user_id, photo_path) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path)');
        return $stmt->execute([$userId, $relativePathOrNull]);
    } catch (Throwable $e) {
        error_log('setUserProfilePhotoPath error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Store photo bytes directly in DB and record mime type.
 * Also clears legacy file path to ensure resolver prefers DB.
 */
function setUserProfilePhotoBytes($userId, $binaryContents, $mimeType) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare('INSERT INTO user_profiles (user_id, photo_blob, photo_mime, photo_updated_at, photo_path) VALUES (?, ?, ?, NOW(), NULL)
                               ON DUPLICATE KEY UPDATE photo_blob = VALUES(photo_blob), photo_mime = VALUES(photo_mime), photo_updated_at = VALUES(photo_updated_at), photo_path = VALUES(photo_path)');
        // Bind parameters explicitly, using LOB for the binary content
        $stmt->bindValue(1, (int)$userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $binaryContents, PDO::PARAM_LOB);
        $stmt->bindValue(3, (string)$mimeType, PDO::PARAM_STR);
        return $stmt->execute();
    } catch (Throwable $e) {
        error_log('setUserProfilePhotoBytes error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check whether a user has a DB-stored photo blob.
 */
function userHasProfilePhotoBlob($userId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare('SELECT photo_blob IS NOT NULL AS has_blob FROM user_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row && (int)$row['has_blob'] === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Clear profile photo from both DB blob and legacy path.
 */
function clearUserProfilePhoto($userId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare('UPDATE user_profiles SET photo_blob = NULL, photo_mime = NULL, photo_updated_at = NULL, photo_path = NULL WHERE user_id = ?');
        return $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log('clearUserProfilePhoto error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has any profile photo (DB blob, DB path, or filesystem)
 */
function userHasAnyProfilePhoto($userId) {
    // Check DB blob first
    if (userHasProfilePhotoBlob($userId)) {
        return true;
    }
    
    // Check DB path
    $dbPath = getUserProfilePhotoPath($userId);
    if ($dbPath) {
        $fullPath = __DIR__ . '/../public/authenticated/' . $dbPath;
        if (file_exists($fullPath)) {
            return true;
        }
    }
    
    // Check filesystem for user-specific files
    $basename = 'user_' . $userId;
    
    // Check customer directory
    $custDir = __DIR__ . '/../public/authenticated/customer/img/profiles';
    if (is_dir($custDir)) {
        foreach (['jpg','jpeg','png','webp'] as $ext) {
            if (file_exists($custDir . '/' . $basename . '.' . $ext)) {
                return true;
            }
        }
    }
    
    // Check shared directory only if explicitly linked in DB
    $profileDir = __DIR__ . '/../public/authenticated/img/profiles';
    if (is_dir($profileDir) && $dbPath) {
        foreach (['jpg','jpeg','png','webp'] as $ext) {
            $candidate = $profileDir . '/' . $basename . '.' . $ext;
            if (file_exists($candidate) && strpos($dbPath, $basename . '.' . $ext) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

function resolveProfilePhotoPath($userId) {
    // Prefer DB blob endpoint when available
    if (userHasProfilePhotoBlob($userId)) {
        return 'auth/profile_photo.php?u=' . urlencode((string)$userId);
    }
    // Next, DB path
    $path = getUserProfilePhotoPath($userId);
    if ($path) { 
        // Verify the file actually exists and belongs to this user
        $fullPath = __DIR__ . '/../public/authenticated/' . $path;
        if (file_exists($fullPath)) {
            return $path;
        }
    }
    // Customer-specific filesystem path first when the current session is a customer
    $basename = 'user_' . $userId;
    if (function_exists('isCustomer') && isCustomer()) {
        $custDir = __DIR__ . '/../public/authenticated/customer/img/profiles';
        if (is_dir($custDir)) {
            foreach (['jpg','jpeg','png','webp'] as $ext) {
                $candidate = $custDir . '/' . $basename . '.' . $ext;
                if (file_exists($candidate)) {
                    return 'customer/img/profiles/' . $basename . '.' . $ext; // relative under public/authenticated/
                }
            }
        }
    }
    // Fallback by scanning legacy shared directory - but only if explicitly set in DB
    $profileDir = __DIR__ . '/../public/authenticated/img/profiles';
    if (is_dir($profileDir)) {
        foreach (['jpg','jpeg','png','webp'] as $ext) {
            $candidate = $profileDir . '/' . $basename . '.' . $ext;
            if (file_exists($candidate)) {
                // Only return if this file is explicitly linked to the user in the database
                $dbPath = getUserProfilePhotoPath($userId);
                if ($dbPath && strpos($dbPath, $basename . '.' . $ext) !== false) {
                    return 'img/profiles/' . $basename . '.' . $ext; // relative under public/authenticated/
                }
            }
        }
    }
    return null;
}

// Call this on every page load
ensureSuperAdminExists();
ensureUserProfilesTable();
ensureEmailSettingsTable();
// Ensure notifications table supports extended schema and types used by app
if (!function_exists('ensureNotificationsSchema')) {
function ensureNotificationsSchema(){
    try{
        $pdo = getConnection();
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        // Add missing columns if needed
        $colsStmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications'");
        $colsStmt->execute([$db]);
        $cols = $colsStmt->fetchAll();
        $by = [];
        foreach($cols as $c){ $by[strtolower($c['COLUMN_NAME'])] = $c; }
        // data JSON
        if (!isset($by['data'])) { try { $pdo->exec("ALTER TABLE notifications ADD COLUMN data JSON NULL"); } catch (Throwable $e) { /* ignore */ } }
        // priority ENUM
        if (!isset($by['priority'])) { try { $pdo->exec("ALTER TABLE notifications ADD COLUMN priority ENUM('low','medium','high','urgent') DEFAULT 'medium'"); } catch (Throwable $e) { /* ignore */ } }
        // expires_at DATETIME
        if (!isset($by['expires_at'])) { try { $pdo->exec("ALTER TABLE notifications ADD COLUMN expires_at DATETIME NULL"); } catch (Throwable $e) { /* ignore */ } }
        // read_at DATETIME
        if (!isset($by['read_at'])) { try { $pdo->exec("ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL"); } catch (Throwable $e) { /* ignore */ } }
        // Ensure is_read exists
        if (!isset($by['is_read'])) { try { $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* ignore */ } }
        // Ensure 'type' ENUM includes our app-specific values
        if (isset($by['type'])) {
            $colType = strtolower($by['type']['COLUMN_TYPE'] ?? '');
            $needsUpdate = (strpos($colType, "'order_status'") === false) || (strpos($colType, "'payment'") === false) || (strpos($colType, "'promotional'") === false) || (strpos($colType, "'security'") === false) || (strpos($colType, "'system'") === false);
            if ($needsUpdate) {
                try{
                    $pdo->exec("ALTER TABLE notifications MODIFY COLUMN type ENUM('order_status','payment','promotional','security','system','info','success','warning','error') NOT NULL DEFAULT 'order_status'");
                } catch (Throwable $e) {
                    // Fallback: keep existing but allow inserts by defaulting to 'info' when needed
                }
            }
        }
    } catch (Throwable $e) {
        error_log('ensureNotificationsSchema error: ' . $e->getMessage());
    }
}
}
ensureNotificationsSchema();

// Backs the "one review per customer per product" rule with an actual DB
// constraint, not just the SELECT-then-INSERT check in api/reviews.php
// (which alone is racy - two near-simultaneous submits could both pass the
// check before either INSERT lands). Only added if no duplicate rows exist
// yet, so it can never fail against real data; if duplicates are somehow
// already present it's skipped and logged rather than erroring.
if (!function_exists('ensureReviewsUniqueConstraint')) {
function ensureReviewsUniqueConstraint(){
    try{
        $pdo = getConnection();
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'unique_user_product_review'");
        $stmt->execute([$db]);
        if ($stmt->fetchColumn()) { return; } // already added

        $dupes = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT user_id, product_id FROM reviews GROUP BY user_id, product_id HAVING COUNT(*) > 1) d")->fetchColumn();
        if ($dupes > 0) {
            error_log("ensureReviewsUniqueConstraint: $dupes duplicate (user_id, product_id) pair(s) already exist in reviews - skipping constraint until cleaned up.");
            return;
        }

        $pdo->exec("ALTER TABLE reviews ADD UNIQUE KEY unique_user_product_review (user_id, product_id)");
    } catch (Throwable $e) {
        error_log('ensureReviewsUniqueConstraint error: ' . $e->getMessage());
    }
}
}

// Self-heals the `reviews.image_path` column (see DBSQL/add_review_image.sql)
// so the "photo on a review" feature works even on a DB where that migration
// hasn't been run. Cheap no-op once the column exists.
if (!function_exists('ensureReviewImageColumn')) {
function ensureReviewImageColumn(){
    static $done = false;
    if ($done) { return; }
    $done = true;
    try{
        $pdo = getConnection();
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'image_path'");
        $stmt->execute([$db]);
        if ($stmt->fetchColumn()) { return; }
        $pdo->exec("ALTER TABLE reviews ADD COLUMN image_path VARCHAR(255) NULL AFTER comment");
    } catch (Throwable $e) {
        error_log('ensureReviewImageColumn error: ' . $e->getMessage());
    }
}
}

// Self-heals the `review_images` table (see DBSQL/add_review_images_table.sql)
// that backs multi-photo reviews - a review can carry up to REVIEW_MAX_IMAGES
// photos. Also migrates any legacy single `reviews.image_path` value into the
// table on first run. Cheap no-op once the table exists and is populated.
if (!defined('REVIEW_MAX_IMAGES')) { define('REVIEW_MAX_IMAGES', 5); }
if (!function_exists('ensureReviewImagesTable')) {
function ensureReviewImagesTable(){
    static $done = false;
    if ($done) { return; }
    $done = true;
    try{
        $pdo = getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS review_images (
            id INT(11) NOT NULL AUTO_INCREMENT,
            review_id INT(11) NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_review_id (review_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Migrate the legacy one-photo column into the table once.
        $pdo->exec("INSERT INTO review_images (review_id, image_path)
                    SELECT r.id, r.image_path FROM reviews r
                    WHERE r.image_path IS NOT NULL AND r.image_path <> ''
                      AND NOT EXISTS (SELECT 1 FROM review_images ri
                                      WHERE ri.review_id = r.id AND ri.image_path = r.image_path)");
    } catch (Throwable $e) {
        error_log('ensureReviewImagesTable error: ' . $e->getMessage());
    }
}
}

// Product and Category Functions
function getCategories() {
    try {
        $pdo = getConnection();
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting categories: " . $e->getMessage());
        return [];
    }
}

function getProducts($categoryId = null, $limit = null) {
    try {
        $pdo = getConnection();
        $sql = "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status = 'active'";
        $params = [];
        
        if ($categoryId) {
            $sql .= " AND p.category_id = ?";
            $params[] = $categoryId;
        }
        
        $sql .= " ORDER BY p.created_at DESC";
        
        if ($limit) {
            $safeLimit = (int)$limit;
            $sql .= " LIMIT {$safeLimit}";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting products: " . $e->getMessage());
        return [];
    }
}

function getProduct($id) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ? AND p.status = 'active'");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting product: " . $e->getMessage());
        return null;
    }
}

// Stock movement function
function addStockMovement($productId, $type, $quantity, $referenceType = null, $referenceId = null, $notes = null) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$productId, $type, $quantity, $referenceType, $referenceId, $notes]);
    } catch (PDOException $e) {
        error_log("Error adding stock movement: " . $e->getMessage());
        return false;
    }
}

// Utility Functions
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

/**
 * Adds PH standard 12% VAT on top of a net (pre-tax) amount, the way most
 * ecommerce checkouts compute it: gross total = net item subtotal + VAT.
 */
function phVatAdd($netAmount, $rate = 0.12) {
    $net = round((float)$netAmount, 2);
    $vat = round($net * $rate, 2);
    $gross = round($net + $vat, 2);
    return ['net' => $net, 'vat' => $vat, 'gross' => $gross];
}

/**
 * Splits an already-finalized VAT-inclusive amount (e.g. a stored order total
 * that had VAT added at checkout) back into its net and VAT components, for
 * display on order confirmations/invoices where only the final total is stored.
 */
function phVatBreakdown($grossAmount, $rate = 0.12) {
    $gross = round((float)$grossAmount, 2);
    $net = round($gross / (1 + $rate), 2);
    $vat = round($gross - $net, 2);
    return ['net' => $net, 'vat' => $vat, 'gross' => $gross];
}

/**
 * Generate a QR code file with multiple fallback methods.
 * Tries Endroid QR Code (SVG), then phpqrcode (PNG), then creates a simple fallback.
 */
function generateQrCodeFile(string $data, string $targetDirectoryAbsolute, string $fileBaseName = null, int $size = 250) : string {
    // Ensure directory exists
    if (!is_dir($targetDirectoryAbsolute)) {
        @mkdir($targetDirectoryAbsolute, 0755, true);
    }

    // Determine filename
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($fileBaseName ?: ('qr_' . time())));
    
    // Try multiple methods
    $methods = [
        ['ext' => 'svg', 'method' => 'endroid_svg'],
        ['ext' => 'png', 'method' => 'phpqrcode_png'],
        ['ext' => 'html', 'method' => 'fallback_html']
    ];
    
    foreach ($methods as $method) {
        $absolutePath = rtrim($targetDirectoryAbsolute, '\\/') . '/' . $slug . '.' . $method['ext'];
        
        try {
            if ($method['method'] === 'endroid_svg') {
                // Try Endroid QR Code (SVG)
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($autoload)) {
                    require_once $autoload;
                    $builder = \Endroid\QrCode\Builder\Builder::create()
                        ->data($data)
                        ->size($size)
                        ->margin(10)
                        ->writer(new \Endroid\QrCode\Writer\SvgWriter())
                        ->build();
                    $builder->saveToFile($absolutePath);
                    if (file_exists($absolutePath)) {
                        return convertToWebPath($absolutePath);
                    }
                }
            } elseif ($method['method'] === 'phpqrcode_png') {
                // Try phpqrcode (PNG) - only if GD is available
                if (function_exists('imagecreate') && file_exists(__DIR__ . '/../phpqrcode-master/phpqrcode-master/phpqrcode.php')) {
                    require_once __DIR__ . '/../phpqrcode-master/phpqrcode-master/phpqrcode.php';
                    QRcode::png($data, $absolutePath, 'L', 10, 2);
                    if (file_exists($absolutePath)) {
                        return convertToWebPath($absolutePath);
                    }
                }
            } elseif ($method['method'] === 'fallback_html') {
                // Create a simple HTML fallback with the data
                $html = '<!DOCTYPE html><html><head><title>QR Code Data</title></head><body style="font-family:Arial;text-align:center;padding:20px;"><h2>Order QR Code</h2><div style="border:2px solid #333;padding:20px;margin:20px;background:#f9f9f9;"><pre>' . htmlspecialchars($data) . '</pre></div><p>Show this page to the cashier</p></body></html>';
                @file_put_contents($absolutePath, $html);
                if (file_exists($absolutePath)) {
                    return convertToWebPath($absolutePath);
                }
            }
        } catch (\Throwable $e) {
            error_log('QR generation method ' . $method['method'] . ' failed: ' . $e->getMessage());
            continue;
        }
    }
    
    // If all methods fail, return a simple data URL
    return 'data:text/plain;base64,' . base64_encode($data);
}

/**
 * Convert absolute file path to web-relative path
 */
function convertToWebPath(string $absolutePath) : string {
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $normalized = str_replace('\\', '/', $absolutePath);
    
    if (strpos($normalized, $projectRoot . '/public/') !== false) {
        return 'public/' . ltrim(substr($normalized, strlen($projectRoot . '/public/')), '/');
    } else {
        return ltrim(str_replace($projectRoot . '/', '', $normalized), '/');
    }
}

/**
 * Lookup payment method display name from payment_methods by code
 */
function getPaymentMethodDisplayNameByCode($code) {
    try {
        if ($code === null || $code === '') { return null; }
        $pdo = getConnection();
        // Self-heal common misconfiguration where display_name values are swapped
        try {
            $check = $pdo->query("SELECT code, display_name FROM payment_methods WHERE code IN ('home_delivery','store_pickup')")->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($check) {
                $needFix = false;
                if (isset($check['home_delivery']) && stripos($check['home_delivery'], 'in-store') !== false) { $needFix = true; }
                if (isset($check['store_pickup']) && stripos($check['store_pickup'], 'cash on delivery') !== false) { $needFix = true; }
                if ($needFix) {
                    $upd = $pdo->prepare("UPDATE payment_methods SET display_name = CASE code WHEN 'home_delivery' THEN 'Cash on Delivery' WHEN 'store_pickup' THEN 'In-Store Payment' ELSE display_name END WHERE code IN ('home_delivery','store_pickup')");
                    $upd->execute();
                }
            }
        } catch (Throwable $_e) { /* ignore */ }
        $stmt = $pdo->prepare("SELECT display_name FROM payment_methods WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['display_name'] ?? null;
    } catch (Throwable $e) {
        error_log('getPaymentMethodDisplayNameByCode error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Lookup payment method NAME (service name) from payment_methods by code
 */
function getPaymentMethodNameByCode($code) {
    try {
        if ($code === null || $code === '') { return null; }
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT name FROM payment_methods WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['name'] ?? null;
    } catch (Throwable $e) {
        error_log('getPaymentMethodNameByCode error: ' . $e->getMessage());
        return null;
    }
}

function getPaymentMethodLabel($paymentMethod) {
    $method = strtolower((string)$paymentMethod);
    // Prefer display_name from payment_methods when possible
    // Map legacy codes to canonical payment_methods codes
    $canonicalCode = null;
    if ($method === 'cod') { $canonicalCode = 'home_delivery'; }
    elseif ($method === 'store' || $method === 'onsite') { $canonicalCode = 'store_pickup'; }
    elseif ($method === 'online') { $canonicalCode = 'online_payment'; }

    if ($canonicalCode) {
        $display = getPaymentMethodDisplayNameByCode($canonicalCode);
        if ($display) { return $display; }
    } else {
        // If method already matches a canonical code, try lookup directly
        $display = getPaymentMethodDisplayNameByCode($method);
        if ($display) { return $display; }
    }

    // Fallbacks
    switch ($method) {
        case 'cod':
            return 'Cash on Delivery';
        case 'store':
        case 'onsite':
            return 'In-Store Payment';
        case 'online':
            return 'Online Payment';
        case 'gcash':
            return 'GCash';
        case 'paymaya':
            return 'Maya';
        case 'xendit':
            return 'Online Payment (Xendit)';
        case 'store_credit':
            return 'Store Credit';
        default:
            return ucfirst($paymentMethod);
    }
}

// ===== Email helper (PHPMailer) =====
/**
 * Ensure DB table for SMTP/email settings exists.
 * Schema allows storing Gmail or Mailtrap credentials and From info.
 */
function ensureEmailSettingsTable(){
    try{
        $pdo = getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(32) NOT NULL DEFAULT 'custom',
            host VARCHAR(255) NULL,
            port INT NULL,
            username VARCHAR(255) NULL,
            password VARCHAR(255) NULL,
            secure VARCHAR(16) NULL,
            from_email VARCHAR(255) NULL,
            from_name VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Ensure password_resets.token column can hold full password_hash values.
 */
function ensurePasswordResetsSchema(){
    try{
        $pdo = getConnection();
        // Attempt to widen column; safe if already 255
        $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN token VARCHAR(255) NOT NULL");
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Load the active email settings from DB if any. Returns associative array or null.
 */
function getActiveEmailSettings(){
    try{
        $pdo = getConnection();
        $st = $pdo->query("SELECT * FROM email_settings WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e){ return null; }
}

function sendEmail($toEmail, $toName, $subject, $htmlBody, $altBody = '') {
    try {
        // Load PHPMailer directly (avoid vendor/autoload.php platform check on PHP < 8.1)
        $phpmailerBase = __DIR__ . '/../vendor/phpmailer/phpmailer/src';
        if (is_dir($phpmailerBase)) {
            require_once $phpmailerBase . '/Exception.php';
            require_once $phpmailerBase . '/PHPMailer.php';
            require_once $phpmailerBase . '/SMTP.php';
        } else {
            $legacyBase = __DIR__ . '/../PHPMailer-PHPMailer-d9e3b36/src';
            require_once $legacyBase . '/Exception.php';
            require_once $legacyBase . '/PHPMailer.php';
            require_once $legacyBase . '/SMTP.php';
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        // Optional debug level via env (0=off, 2=client/server)
        $debug = (int)(getenv('SMTP_DEBUG') ?: 0);
        if ($debug > 0) {
            $mail->SMTPDebug = $debug;
            // Direct debug output to error_log to avoid echoing to client before headers
            $mail->Debugoutput = function($str, $level) { error_log('SMTP DEBUG ' . $level . ': ' . $str); };
        }
        // Prefer DB-configured SMTP if available; else env; else mail()
        $smtp = getActiveEmailSettings();
        if ($smtp && ($smtp['host'] || $smtp['provider'] === 'gmail' || $smtp['provider'] === 'mailtrap')) {
            // Use SMTP transport when DB has a configured provider/host
            $mail->isSMTP();
            // Provider presets (Gmail/Mailtrap) when host not set
            $host = $smtp['host'];
            $port = (int)($smtp['port'] ?: 0);
            $secure = $smtp['secure'];
            if (!$host && $smtp['provider'] === 'gmail') { $host = 'smtp.gmail.com'; $port = $port ?: 587; $secure = $secure ?: 'tls'; }
            if (!$host && $smtp['provider'] === 'mailtrap') { $host = 'smtp.mailtrap.io'; $port = $port ?: 587; $secure = $secure ?: 'tls'; }
            $mail->Host = $host;
            $mail->Port = $port ?: 587;
            $mail->SMTPAuth = (bool)($smtp['username'] ?? '');
            if ($mail->SMTPAuth) {
                $mail->Username = $smtp['username'];
                $mail->Password = $smtp['password'];
            }
            if ($secure) { $mail->SMTPSecure = $secure; }
        } elseif (getenv('SMTP_HOST')) {
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST');
            $mail->Port = getenv('SMTP_PORT') ?: 587;
            $mail->SMTPAuth = (bool)getenv('SMTP_USERNAME');
            if ($mail->SMTPAuth) { $mail->Username = getenv('SMTP_USERNAME'); $mail->Password = getenv('SMTP_PASSWORD'); }
            if (getenv('SMTP_SECURE')) { $mail->SMTPSecure = getenv('SMTP_SECURE'); }
        } else {
            $mail->isMail();
        }

        // Allow self-signed certs for localhost if explicitly enabled
        if ((getenv('SMTP_ALLOW_SELF_SIGNED') ?: '0') === '1') {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ];
        }

        $fromEmail = ($smtp['from_email'] ?? null) ?: (getenv('MAIL_FROM') ?: 'no-reply@techworld.local');
        $fromName  = ($smtp['from_name'] ?? null) ?: (getenv('MAIL_FROM_NAME') ?: 'TechWorld');
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('sendEmail failed: ' . $e->getMessage());
        // Also persist the message to a local .eml for troubleshooting on dev
        try {
            $dir = dirname(__DIR__) . '/storage/mail';
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $fname = $dir . '/failed-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/i', '_', (string)$toEmail) . '.eml';
            $raw = "To: $toEmail\nSubject: $subject\nContent-Type: text/html; charset=UTF-8\n\n" . $htmlBody;
            @file_put_contents($fname, $raw);
        } catch (Throwable $ignore) { }
        return false;
    }
}

// Widen token column if needed (run once per request harmlessly)
ensurePasswordResetsSchema();

// ===== Persistent Cart (DB-backed) =====
function ensureUserCartsTable(){
    try{
        $pdo = getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_carts (
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, product_id),
            CONSTRAINT fk_user_carts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_carts_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ensureUserCartsTable error: ' . $e->getMessage());
    }
}

function loadCartFromDb($userId){
    if(!$userId) return [];
    try{
        $pdo = getConnection();
        $st = $pdo->prepare('SELECT product_id, quantity FROM user_carts WHERE user_id = ?');
        $st->execute([$userId]);
        $rows = $st->fetchAll();
        $cart = [];
        foreach($rows as $r){ $cart[(int)$r['product_id']] = (int)$r['quantity']; }
        return $cart;
    } catch (Throwable $e){ error_log('loadCartFromDb error: '.$e->getMessage()); return []; }
}

function saveCartToDb($userId, array $cart){
    if(!$userId) return false;
    try{
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        // First, clear all existing cart items for this user
        $clear = $pdo->prepare('DELETE FROM user_carts WHERE user_id = ?');
        $clear->execute([$userId]);
        
        // If cart is empty, we're done
        if (empty($cart)) {
            $pdo->commit();
            return true;
        }
        
        // Insert new cart items (only positive quantities)
        $insert = $pdo->prepare('INSERT INTO user_carts (user_id, product_id, quantity) VALUES (?, ?, ?)');
        foreach($cart as $pid => $qty){
            $pid = (int)$pid; 
            $qty = (int)$qty;
            if($qty > 0){ 
                $insert->execute([$userId, $pid, $qty]);
            }
        }
        
        $pdo->commit();
        return true;
    } catch (Throwable $e){ 
        if(isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack(); 
        }
        error_log('saveCartToDb error: '.$e->getMessage()); 
        return false; 
    }
}

function mergeCarts(array $base, array $incoming){
    foreach($incoming as $pid=>$qty){ $pid=(int)$pid; $qty=(int)$qty; if($pid>0 && $qty>0){ $base[$pid] = ($base[$pid] ?? 0) + $qty; } }
    return $base;
}

function capCartQuantitiesByStock(array $cart){
    if (empty($cart)) return $cart;
    try{
        $pdo = getConnection();
        $ids = array_map('intval', array_keys($cart));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, stock, status FROM products WHERE id IN ($placeholders)");
        $st->execute($ids);
        $productsById = [];
        foreach($st->fetchAll() as $row){ $productsById[(int)$row['id']] = $row; }
        foreach($cart as $pid=>$qty){
            $product = $productsById[(int)$pid] ?? null;
            // Product deleted or deactivated entirely - it can never come back
            // on its own, so drop it from the cart.
            if (!$product || $product['status'] !== 'active') { unset($cart[$pid]); continue; }
            $max = (int)$product['stock'];
            // Out-of-stock (but still active) items are kept as-is, not removed -
            // cart.php shows them in its Wishlist section and brings them back to
            // the cart automatically once the product is restocked.
            if ($max <= 0) { continue; }
            if ($qty > $max) { $cart[$pid] = $max; }
        }
    }catch(Throwable $e){ /* ignore, keep as-is */ }
    return $cart;
}

function clearCartInDb($userId){
    if(!$userId) return false;
    try{ $pdo = getConnection(); $st = $pdo->prepare('DELETE FROM user_carts WHERE user_id = ?'); return $st->execute([$userId]); }
    catch(Throwable $e){ error_log('clearCartInDb error: '.$e->getMessage()); return false; }
}

// --- Cart bundle grouping (e.g. "Add This Build to Cart" from the AI
// Builder) - purely a DISPLAY grouping aid. A cart item's actual quantity/
// removal never depends on this table; it only tells cart.php which rows
// to visually cluster under a shared bundle header, so each item can still
// be removed individually like any normal cart line.
function ensureCartBundleTable(){
    try{
        $pdo = getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS cart_item_bundles (
            user_id INT(11) NOT NULL,
            product_id INT(11) NOT NULL,
            bundle_id VARCHAR(40) NOT NULL,
            bundle_name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, product_id),
            CONSTRAINT fk_cart_item_bundles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch(Throwable $e){ error_log('ensureCartBundleTable error: '.$e->getMessage()); }
}

/** Tags a fresh set of cart product ids as one named bundle. Each call mints its own bundle_id - re-adding the same build again creates a distinct group rather than merging into the old one. */
function tagCartBundle($userId, array $productIds, $bundleName){
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (empty($productIds) || !$userId) { return null; }
    ensureCartBundleTable();
    $bundleId = 'b_' . bin2hex(random_bytes(6));
    try{
        $pdo = getConnection();
        $stmt = $pdo->prepare("INSERT INTO cart_item_bundles (user_id, product_id, bundle_id, bundle_name) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE bundle_id = VALUES(bundle_id), bundle_name = VALUES(bundle_name), created_at = CURRENT_TIMESTAMP");
        foreach ($productIds as $pid) { $stmt->execute([$userId, $pid, $bundleId, $bundleName]); }
    } catch(Throwable $e){ error_log('tagCartBundle error: '.$e->getMessage()); return null; }
    return $bundleId;
}

/** Removes one product's bundle tag - call whenever it leaves the cart so a stale bundle label never outlives the item it described. Safe to call even if the table doesn't exist yet (nothing has ever been bundled). */
function untagCartBundleProduct($userId, $productId){
    if (!$userId) { return; }
    try{
        $pdo = getConnection();
        $pdo->prepare("DELETE FROM cart_item_bundles WHERE user_id = ? AND product_id = ?")->execute([$userId, (int)$productId]);
    } catch(Throwable $e){ /* table may not exist yet - nothing to clean up */ }
}

/** Clears every bundle tag for a user (mirrors clearCart()). */
function clearCartBundles($userId){
    if (!$userId) { return; }
    try{
        $pdo = getConnection();
        $pdo->prepare("DELETE FROM cart_item_bundles WHERE user_id = ?")->execute([$userId]);
    } catch(Throwable $e){ /* nothing to clean up */ }
}

/** product_id => ['bundle_id'=>, 'bundle_name'=>] for a user's currently-tagged cart items. */
function getCartBundleMap($userId){
    if (!$userId) { return []; }
    try{
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT product_id, bundle_id, bundle_name FROM cart_item_bundles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['product_id']] = ['bundle_id' => $row['bundle_id'], 'bundle_name' => $row['bundle_name']];
        }
        return $map;
    } catch(Throwable $e){ return []; }
}

// Call on bootstrap
ensureUserCartsTable();

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    // Singular at a count of 1 - this read "1 days ago" / "1 hours ago" /
    // "1 minutes ago" everywhere timeAgo() is used. Wording only; the
    // thresholds, the return type and the date fallback are unchanged.
    $plural = function ($n, $unit) { return $n . ' ' . $unit . ($n == 1 ? '' : 's') . ' ago'; };

    if ($time < 60) return 'just now';
    if ($time < 3600) return $plural(floor($time/60), 'minute');
    if ($time < 86400) return $plural(floor($time/3600), 'hour');
    if ($time < 2592000) return $plural(floor($time/86400), 'day');
    return date('M j, Y', strtotime($datetime));
}

// Cart and Wishlist Helpers
if (!function_exists('getCartItemCount')) {
function getCartItemCount($userId) {
    if (!$userId) {
        return 0;
    }
    
    // Get count from database for consistency with header display
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT SUM(quantity) FROM user_carts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $count = (int)$stmt->fetchColumn();
        return $count;
    } catch (Exception $e) {
        error_log('getCartItemCount error: ' . $e->getMessage());
        // Fallback to session if database fails
        if (!isset($_SESSION['cart'][$userId]) || !is_array($_SESSION['cart'][$userId])) {
            return 0;
        }
        return array_sum($_SESSION['cart'][$userId]);
    }
}
}

if (!function_exists('getCartTotalAmount')) {
function getCartTotalAmount($userId) {
    if (!$userId || empty($_SESSION['cart'][$userId])) {
        return 0.0;
    }
    $total = 0.0;
    try {
        $pdo = getConnection();
        $productIds = array_keys($_SESSION['cart'][$userId]);
        if (empty($productIds)) return 0.0;
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $pdo->prepare("SELECT id, price FROM products WHERE id IN ($placeholders) AND status = 'active'");
        $stmt->execute($productIds);
        $prices = [];
        foreach ($stmt->fetchAll() as $row) { $prices[$row['id']] = (float)$row['price']; }
        foreach ($_SESSION['cart'][$userId] as $pid => $qty) {
            $price = $prices[$pid] ?? 0.0;
            $total += $price * (int)$qty;
        }
    } catch (PDOException $e) {
        error_log('Error computing cart total: ' . $e->getMessage());
    }
    return $total;
}
}

// Add to cart (server-side helper shared by pages and APIs)
if (!function_exists('addProductToCart')) {
function addProductToCart($userId, $productId, $quantity = 1) {
    $productId = (int)$productId;
    $quantity = (int)$quantity;
    if (!$userId || $productId <= 0 || $quantity <= 0) {
        return ['success' => false, 'message' => 'Invalid request'];
    }
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT id, stock, status FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            return ['success' => false, 'message' => 'Product not found'];
        }
        if (!isset($_SESSION['cart'][$userId])) { $_SESSION['cart'][$userId] = []; }
        $current = (int)($_SESSION['cart'][$userId][$productId] ?? 0);
        // Out-of-stock products can still be saved as a Wishlist entry (see
        // cart.php's Wishlist section) instead of being rejected - there's
        // nothing to reserve, so just ensure a quantity of 1 is saved.
        if ((int)$product['stock'] <= 0) {
            $_SESSION['cart'][$userId][$productId] = max($current, 1);
        } else {
            $newQty = $current + $quantity;
            if ($newQty > (int)$product['stock']) {
                return ['success' => false, 'message' => 'Insufficient stock available'];
            }
            $_SESSION['cart'][$userId][$productId] = $newQty;
        }
        // Persist immediately so quantities survive logout/login
        if (function_exists('saveCartToDb')) {
            saveCartToDb($userId, $_SESSION['cart'][$userId]);
        }
        return ['success' => true];
    } catch (PDOException $e) {
        error_log('addProductToCart error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error'];
    }
}
}

// Wishlist add (idempotent)
if (!function_exists('addWishlistItem')) {
function addWishlistItem($userId, $productId) {
    $productId = (int)$productId;
    if (!$userId || $productId <= 0) {
        return ['success' => false, 'message' => 'Invalid request'];
    }
    try {
        $pdo = getConnection();
        // Ensure product exists
        $chk = $pdo->prepare('SELECT id FROM products WHERE id = ? AND status = "active"');
        $chk->execute([$productId]);
        if (!$chk->fetchColumn()) { return ['success' => false, 'message' => 'Product not found']; }
        // Check existing
        $sel = $pdo->prepare('SELECT id FROM wishlists WHERE user_id = ? AND product_id = ?');
        $sel->execute([$userId, $productId]);
        if ($sel->fetchColumn()) { return ['success' => true]; }
        // Insert
        $ins = $pdo->prepare('INSERT INTO wishlists (user_id, product_id) VALUES (?, ?)');
        $ins->execute([$userId, $productId]);
        return ['success' => true];
    } catch (PDOException $e) {
        error_log('addWishlistItem error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error'];
    }
}

// Auto-cancels online-payment orders (gcash/paymaya/xendit) left unpaid for
// over 24 hours since being placed - customers get a full day to finish
// paying. NOTE: this is deliberately NO LONGER the same window as the
// onsite/pickup codes, which stay at 1 hour (process_order.php:249/371) -
// those are collect-in-store codes, a different thing from an online
// payment link. After the window the order is treated as abandoned: stock is
// restored and the order cancelled, instead of silently holding inventory
// hostage forever. Even a real 'expired'/'failed' webhook from the gateway
// itself (see api/webhooks/xendit.php) never restores stock or cancels the
// order - only orders.payment_status changes there - so this is the only
// place that actually closes the loop.
// No real cron job in this environment - called opportunistically from
// admin pages/endpoints that already touch this data (api/orders.php,
// Home_Delivery_Orders.php, store_pickup_orders.php) instead. Cheap to run
// on every page load: a single indexed SELECT when nothing has expired yet.
// Returns how many orders were cancelled.
function cancelExpiredOnlinePaymentOrders(): int {
    try {
        $pdo = getConnection();

        $candidates = $pdo->query("
            SELECT id FROM orders
            WHERE payment_method IN ('gcash','paymaya','xendit')
              AND payment_status = 'pending'
              AND status NOT IN ('cancelled','completed')
              AND created_at <= NOW() - INTERVAL 24 HOUR
        ")->fetchAll(PDO::FETCH_COLUMN);

        $cancelledCount = 0;
        foreach ($candidates as $orderId) {
            try {
                $pdo->beginTransaction();

                // Re-check fresh, locked - a payment could have completed
                // (webhook, or admin manual verify) in the moment between
                // the scan above and now.
                $orderStmt = $pdo->prepare("
                    SELECT id, user_id, invoice_no, payment_method
                    FROM orders
                    WHERE id = ? AND payment_status = 'pending' AND status NOT IN ('cancelled','completed')
                    FOR UPDATE
                ");
                $orderStmt->execute([$orderId]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    $pdo->rollBack();
                    continue;
                }

                $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'expired', updated_at = NOW() WHERE id = ?")
                    ->execute([$orderId]);

                $pdo->prepare("
                    UPDATE products p
                    JOIN order_items oi ON p.id = oi.product_id
                    SET p.stock = p.stock + oi.quantity
                    WHERE oi.order_id = ?
                ")->execute([$orderId]);

                // And the specific physical units this order had claimed. An
                // online order sits in awaiting_payment holding reserved units;
                // when payment never lands they have to go back on the shelf,
                // or the shop can never sell that laptop again. Recomputes the
                // mirror for tracked products, deliberately overwriting the +=
                // above for those.
                pu_release_for_order($pdo, $orderId);

                $pdo->prepare("UPDATE payments SET payment_status = 'cancelled' WHERE order_id = ? AND payment_status IN ('pending','processing')")
                    ->execute([$orderId]);

                $pdo->commit();

                $orderNumber = $order['invoice_no'] ?: ('ORD-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT));
                logAudit('order_auto_cancelled_unpaid', 'orders', $orderId, null, [
                    'invoice_no' => $orderNumber,
                    'payment_method' => $order['payment_method'],
                    'reason' => 'unpaid_1_hour',
                ]);
                createNotification(
                    (int)$order['user_id'],
                    'order_status',
                    'Order Cancelled - ' . $orderNumber,
                    'Your order ' . $orderNumber . ' was cancelled because payment was not completed within 24 hours of placing it. Feel free to place a new order whenever you\'re ready.',
                    ['order_id' => $orderId, 'order_number' => $orderNumber, 'action' => 'auto_cancelled_unpaid'],
                    'high'
                );

                $cancelledCount++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('cancelExpiredOnlinePaymentOrders: failed to cancel order ' . $orderId . ': ' . $e->getMessage());
            }
        }

        return $cancelledCount;
    } catch (Throwable $e) {
        error_log('cancelExpiredOnlinePaymentOrders: scan failed: ' . $e->getMessage());
        return 0;
    }
}

// ===== NOTIFICATION SYSTEM FUNCTIONS =====

/**
 * Create a notification for a user
 */
function createNotification($userId, $type, $title, $message, $data = null, $priority = 'medium', $expiresAt = null) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("INSERT INTO notifications 
                               (user_id, type, title, message, data, priority, expires_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId, 
            $type, 
            $title, 
            $message, 
            $data ? json_encode($data) : null, 
            $priority, 
            $expiresAt
        ]);
        return ['success' => true, 'notification_id' => $pdo->lastInsertId()];
    } catch (Exception $e) {
        error_log('Create notification error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create notification'];
    }
}

/**
 * Tell the customer their rider has started live tracking.
 *
 * Called from every place tracking can be switched on - the rider's own
 * "Start live tracking" button (courier/dispatch.php), the first GPS fix his
 * device sends (api/update_driver_location.php) and the admin equivalent
 * (admin/Delivery_Report.php). From the customer's side those are all the
 * same event, so they share one function rather than three copies of the
 * wording.
 *
 * Idempotent, and it has to be: update_driver_location.php runs on every
 * fix the rider's phone sends, and a rider who stops and restarts tracking
 * would otherwise notify the customer again each time. The guard is a marker
 * written into the notification's own `data` - `tracking_key` - so the check
 * needs no extra table and cannot drift from the row it is guarding.
 *
 * Deliberately never throws: this is a courtesy message hanging off a
 * delivery action, and a notification failure must not fail the rider's
 * request or lose his GPS fix.
 */
function notifyCustomerTrackingStarted(PDO $pdo, int $orderId): bool
{
    try {
        if ($orderId <= 0) { return false; }

        $stmt = $pdo->prepare("SELECT user_id, invoice_no FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || empty($order['user_id'])) { return false; }

        $userId     = (int)$order['user_id'];
        $trackKey   = 'trk-' . $orderId;
        $invoiceRef = (string)($order['invoice_no'] ?? '');

        // Already told them about this order's tracking? Then nothing to do.
        $dupe = $pdo->prepare(
            "SELECT 1 FROM notifications
              WHERE user_id = ? AND data LIKE ? LIMIT 1"
        );
        $dupe->execute([$userId, '%"tracking_key":"' . $trackKey . '"%']);
        if ($dupe->fetchColumn()) { return false; }

        createNotification(
            $userId,
            'order_status',
            'Your delivery is on the way',
            'Your rider has started live tracking for order ' . $invoiceRef
                . '. Tap to follow the delivery on the map.',
            [
                'order_id'     => $orderId,
                'invoice_no'   => $invoiceRef,
                'tracking_key' => $trackKey,
                /* An explicit destination. Without it getNotificationLink()
                   in customer/notifications.php would see `order_id` and send
                   them to the order summary, which is not where the map is. */
                'url'          => WEB_ROOT . 'public/authenticated/customer/track_order.php?id=' . $orderId,
            ],
            'high'
        );
        return true;
    } catch (Throwable $e) {
        error_log('notifyCustomerTrackingStarted failed for order ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Create notification from template
 */
function createNotificationFromTemplate($userId, $type, $event, $variables = []) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT title_template, message_template 
                               FROM notification_templates 
                               WHERE type = ? AND event = ? AND is_active = TRUE");
        $stmt->execute([$type, $event]);
        $template = $stmt->fetch();
        
        if (!$template) {
            return ['success' => false, 'message' => 'Template not found'];
        }
        
        // Replace variables in template
        $title = $template['title_template'];
        $message = $template['message_template'];
        
        // Support both {var} and #{var} placeholders used by seeded templates
        foreach ($variables as $key => $value) {
            $plain = '{' . $key . '}';
            $hash  = '#{' . $key . '}';
            $title = str_replace([$plain, $hash], (string)$value, $title);
            $message = str_replace([$plain, $hash], (string)$value, $message);
        }

        /* Anything the caller did not supply is dropped rather than shipped.
           Templates carry optional slots - order_placed's {eta} is only filled
           when the order has an address we can estimate to, and {reason} is
           only filled on a cancellation that has one - and a customer reading
           "Total: P784.00. {eta}" in their bell is worse than reading nothing
           there. Tidy up the punctuation and doubled spaces the removal leaves
           behind, so the sentence still ends in a single full stop. */
        $strip = static function (string $text): string {
            $text = preg_replace('/#?\{[A-Za-z0-9_]+\}/', '', $text);
            $text = preg_replace('/[ 	]{2,}/', ' ', $text);
            $text = preg_replace('/\s+([.,;:!?])/', '$1', $text);
            $text = preg_replace('/([.,;:])(?:\s*\1)+/', '$1', $text);
            /* A removed value can leave its own label stranded at the end -
               order_cancelled is "Reason: {reason}" and order_shipped is
               "Tracking: {tracking_number}", so an unsupplied value used to
               end the message on a colon. Only a trailing label is dropped;
               one in the middle of a sentence is left alone. */
            $text = preg_replace('/\s*\b[A-Za-z][A-Za-z ]{0,20}:\s*$/', '', $text);
            return trim($text);
        };
        $title = $strip($title);
        $message = $strip($message);

        return createNotification($userId, $type, $title, $message, $variables);
    } catch (Exception $e) {
        error_log('Create notification from template error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create notification from template'];
    }
}

/**
 * Send order status notification
 */
function sendOrderStatusNotification($userId, $orderId, $status, $additionalData = []) {
    $variables = array_merge([
        'order_id' => $orderId,
        'status' => $status
    ], $additionalData);

    // Idempotency guard: if a recent notification exists for same order+status, update it instead of duplicating
    try {
        $pdo = getConnection();
        // Find latest matching notification (within 24h) for this user/order/status
        $checkSql = "SELECT id, data FROM notifications 
                     WHERE user_id = ? 
                       AND type = 'order_status' 
                       AND JSON_EXTRACT(data, '$.order_id') = ? 
                       AND JSON_EXTRACT(data, '$.status') = ? 
                       AND (expires_at IS NULL OR expires_at > NOW()) 
                       AND created_at >= (NOW() - INTERVAL 1 DAY)
                     ORDER BY created_at DESC LIMIT 1";
        $check = $pdo->prepare($checkSql);
        $check->execute([(int)$userId, (int)$orderId, (string)$status]);
        $existing = $check->fetch();

        if ($existing && isset($existing['id'])) {
            // If tracking number provided now, merge it into existing data JSON (idempotent)
            if (isset($additionalData['tracking_number'])) {
                $currentData = [];
                try { $currentData = $existing['data'] ? json_decode($existing['data'], true) ?: [] : []; } catch (Throwable $e) { $currentData = []; }
                if (empty($currentData['tracking_number'])) {
                    $currentData['tracking_number'] = $additionalData['tracking_number'];
                    $upd = $pdo->prepare("UPDATE notifications SET data = ? WHERE id = ?");
                    $upd->execute([json_encode($currentData), (int)$existing['id']]);
                }
            }
            return ['success' => true, 'notification_id' => (int)$existing['id'], 'deduped' => true];
        }
    } catch (Throwable $e) {
        // On any DB that doesn't support JSON_EXTRACT, silently fall back to creating
    }

    return createNotificationFromTemplate($userId, 'order_status', 'order_' . $status, $variables);
}

/**
 * Send payment notification
 */
function sendPaymentNotification($userId, $orderId, $event, $amount = null, $additionalData = []) {
    $variables = array_merge([
        'order_id' => $orderId,
        'amount' => $amount ? formatCurrency($amount) : ''
    ], $additionalData);
    
    return createNotificationFromTemplate($userId, 'payment', 'payment_' . $event, $variables);
}

/**
 * Send promotional notification
 */
function sendPromotionalNotification($userId, $event, $variables = []) {
    return createNotificationFromTemplate($userId, 'promotional', $event, $variables);
}

/**
 * Send security notification
 */
function sendSecurityNotification($userId, $event, $variables = []) {
    return createNotificationFromTemplate($userId, 'security', $event, $variables);
}

/**
 * Get user notification count
 */
function getUserNotificationCount($userId, $unreadOnly = false) {
    try {
        $pdo = getConnection();
        $where = 'user_id = ? AND (expires_at IS NULL OR expires_at > NOW())';
        $params = [$userId];
        // Keep count consistent with customer feed exclusions
        if ((function_exists('getUserRole') && getUserRole() === 'customer')) {
            $where .= " AND title NOT IN ('Admin Access Approved','Admin Access Denied')";
        }
        
        if ($unreadOnly) {
            $where .= ' AND is_read = FALSE';
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log('Get notification count error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($userId, $notificationId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("UPDATE notifications 
                               SET is_read = TRUE, read_at = NOW() 
                               WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log('Mark notification as read error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for user
 */
function markAllNotificationsAsRead($userId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("UPDATE notifications 
                               SET is_read = TRUE, read_at = NOW() 
                               WHERE user_id = ? AND is_read = FALSE");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log('Mark all notifications as read error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Clean expired notifications
 */
function cleanExpiredNotifications() {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE expires_at IS NOT NULL AND expires_at < NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log('Clean expired notifications error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Notify all admins about a new order
 */
function notifyAdminsNewOrder($orderId, $customerName, $invoiceNo, $total, $deliveryMethod, $paymentMethod) {
    try {
        $pdo = getConnection();
        
        // Get all admin users
        $stmt = $pdo->prepare("SELECT id, firstname, lastname, email FROM users WHERE role = 'admin' AND status = 'active'");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            // Create notification data
            $notificationData = [
                'order_id' => $orderId,
                'customer_name' => $customerName,
                'invoice_no' => $invoiceNo,
                'total' => $total,
                'delivery_method' => $deliveryMethod,
                'payment_method' => $paymentMethod,
                'order_type' => 'new_order'
            ];
            
            // Create notification
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, data, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $admin['id'],
                'new_order',
                'New Order Received',
                "New order #{$invoiceNo} from {$customerName} - Total: " . formatCurrency($total),
                json_encode($notificationData)
            ]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('Admin notification error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create a payment record for an order
 * @param int $orderId Order ID
 * @param int $userId User ID
 * @param string $paymentMethod Payment method (cod, onsite, online, etc.)
 * @param string $paymentType Payment type (home_delivery, store_pickup, online_payment)
 * @param float $amount Payment amount
 * @param string $status Payment status (default: pending)
 * @param string $transactionId Optional transaction ID
 * @param array $gatewayResponse Optional gateway response data
 * @return int|false Payment ID on success, false on failure
 */
function createPayment($orderId, $userId, $paymentMethod, $paymentType, $amount, $status = 'pending', $transactionId = null, $gatewayResponse = null) {
    try {
        $pdo = getConnection();

        $stmt = $pdo->prepare("
            INSERT INTO payments
            (order_id, user_id, payment_method, payment_type, payment_status, amount, transaction_id, notes, payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $paymentDate = ($status === 'completed') ? date('Y-m-d H:i:s') : null;
        $notes = $gatewayResponse ? json_encode($gatewayResponse) : null;

        $stmt->execute([
            $orderId, $userId, $paymentMethod, $paymentType, $status, $amount,
            $transactionId, $notes, $paymentDate
        ]);

        return $pdo->lastInsertId();

    } catch (Exception $e) {
        error_log('Payment creation error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update payment status
 * @param int $paymentId Payment ID
 * @param string $status New status
 * @param int $processedBy User ID who processed the payment
 * @param string $notes Optional notes
 * @return bool Success status
 */
function updatePaymentStatus($paymentId, $status, $processedBy = null, $notes = null) {
    try {
        $pdo = getConnection();
        
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET payment_status = ?, processed_by = ?, notes = ?, 
                payment_date = CASE WHEN ? = 'completed' THEN NOW() ELSE payment_date END,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$status, $processedBy, $notes, $status, $paymentId]);
        
        return $stmt->rowCount() > 0;
        
    } catch (Exception $e) {
        error_log('Payment status update error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Store credit ledger helpers.
 *
 * Every credit/debit is its own row in `store_credits` (never a single
 * mutable balance column) so the full history is always reconstructable -
 * same append-only pattern the `payments` table already uses. Store credit
 * is a liability (money the platform still owes the customer, redeemable
 * only for a future purchase here), not revenue - it is issued when a
 * customer cancels a paid order and opts into credit instead of a cash
 * refund, and consumed when they redeem it against a new order.
 */

/**
 * Current store credit balance for a user.
 * @param int $userId
 * @return float
 */
function getStoreCreditBalance($userId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT balance_after FROM store_credits WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $balance = $stmt->fetchColumn();
        return $balance !== false ? (float)$balance : 0.00;
    } catch (Exception $e) {
        error_log('Store credit balance lookup error: ' . $e->getMessage());
        return 0.00;
    }
}

/**
 * Issue store credit to a user (e.g. a cancelled paid order, converted to
 * credit at the customer's request, plus any incentive bonus).
 * @param int $userId
 * @param float $amount Must be > 0
 * @param string $source 'cancellation_credit' or 'admin_adjustment'
 * @param int|null $orderId The cancelled order this credit came from
 * @param int|null $cancellationRequestId
 * @param string|null $notes
 * @return int|false New store_credits row id, or false on failure
 */
function addStoreCredit($userId, $amount, $source, $orderId = null, $cancellationRequestId = null, $notes = null) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) { return false; }
    try {
        $pdo = getConnection();
        $newBalance = getStoreCreditBalance($userId) + $amount;
        $stmt = $pdo->prepare("
            INSERT INTO store_credits (user_id, type, amount, balance_after, source, order_id, cancellation_request_id, notes)
            VALUES (?, 'credit', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $amount, $newBalance, $source, $orderId, $cancellationRequestId, $notes]);
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('Store credit issue error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Redeem (spend) store credit, e.g. applied toward a new order's total.
 * Fails rather than going negative - callers must clamp the requested
 * amount to the available balance before calling this.
 * @param int $userId
 * @param float $amount Must be > 0 and <= current balance
 * @param string $source 'redemption' or 'admin_adjustment'
 * @param int|null $orderId The new order this credit was applied to
 * @param string|null $notes
 * @return int|false New store_credits row id, or false on failure/insufficient balance
 */
function deductStoreCredit($userId, $amount, $source, $orderId = null, $notes = null) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) { return false; }
    try {
        $pdo = getConnection();
        $currentBalance = getStoreCreditBalance($userId);
        if ($amount > $currentBalance + 0.001) {
            error_log("Store credit deduction rejected: user #$userId requested $amount, balance is $currentBalance");
            return false;
        }
        $newBalance = round($currentBalance - $amount, 2);
        $stmt = $pdo->prepare("
            INSERT INTO store_credits (user_id, type, amount, balance_after, source, order_id, notes)
            VALUES (?, 'debit', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $amount, $newBalance, $source, $orderId, $notes]);
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('Store credit deduction error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get payment methods configuration
 * @param bool $activeOnly Only return active payment methods
 * @return array Payment methods
 */
function getPaymentMethods($activeOnly = true) {
    try {
        $pdo = getConnection();
        
        $sql = "SELECT * FROM payment_methods";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log('Get payment methods error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get payment by order ID
 * @param int $orderId Order ID
 * @return array|false Payment data or false if not found
 */
function getPaymentByOrderId($orderId) {
    try {
        $pdo = getConnection();
        
        $stmt = $pdo->prepare("
            SELECT p.*, pm.display_name as payment_method_name, pm.type as payment_method_type
            FROM payments p
            LEFT JOIN payment_methods pm ON p.payment_method = pm.code
            WHERE p.order_id = ?
            ORDER BY p.created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$orderId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: false;
        
    } catch (Exception $e) {
        error_log('Get payment by order ID error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get payments by user ID
 * @param int $userId User ID
 * @param int $limit Limit results
 * @param int $offset Offset for pagination
 * @return array Payments
 */
function getPaymentsByUserId($userId, $limit = 10, $offset = 0) {
    try {
        $pdo = getConnection();
        
        $stmt = $pdo->prepare("
            SELECT p.*, pm.display_name as payment_method_name, pm.type as payment_method_type,
                   o.invoice_no, o.tracking_code, o.status as order_status
            FROM payments p
            LEFT JOIN payment_methods pm ON p.payment_method = pm.code
            LEFT JOIN orders o ON p.order_id = o.id
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$userId, $limit, $offset]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log('Get payments by user ID error: ' . $e->getMessage());
        return [];
    }
}
}
