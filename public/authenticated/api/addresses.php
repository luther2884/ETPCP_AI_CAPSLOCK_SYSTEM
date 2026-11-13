<?php
// api/addresses.php - User delivery addresses CRUD
require_once __DIR__ . '/../../../config/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$userId = getUserId();
$action = $_GET['action'] ?? '';

/**
 * Map-pin columns are additive (DBSQL/add_address_geo_pin.sql). Checked once
 * per request rather than assumed, so an install that hasn't run the
 * migration yet keeps saving addresses instead of erroring on every write -
 * same defensive shape process_order.php uses for its own added columns.
 */
function addressGeoColumnsExist(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) { return $has; }
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM user_addresses LIKE 'l%tude'")->fetchAll(PDO::FETCH_COLUMN);
        $has = in_array('latitude', $cols, true) && in_array('longitude', $cols, true);
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * A pin is either two valid numbers or nothing at all. Half a coordinate is
 * worse than none - it would put the rider on the equator or the prime
 * meridian - so both are dropped together. PH sits inside roughly
 * 4-22 N / 112-127 E; anything outside that is a bad payload, not a delivery.
 */
function normalizeGeoPin($lat, $lng): array {
    if ($lat === null || $lng === null || $lat === '' || $lng === '') { return [null, null]; }
    if (!is_numeric($lat) || !is_numeric($lng)) { return [null, null]; }
    $lat = (float)$lat;
    $lng = (float)$lng;
    if ($lat < 4.0 || $lat > 22.0 || $lng < 112.0 || $lng > 127.0) { return [null, null]; }
    return [round($lat, 7), round($lng, 7)];
}

try{
    $pdo = getConnection();
    $hasGeo = addressGeoColumnsExist($pdo);

    switch($action){
        case 'list':
            $geoSelect = $hasGeo ? ', latitude, longitude' : '';
            $st = $pdo->prepare("SELECT id, full_name, phone, region, postal, street, label, is_default$geoSelect FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
            $st->execute([$userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true, 'addresses'=>$rows]);
            break;
        case 'create':
            $in = json_decode(file_get_contents('php://input'), true) ?? [];
            $name = trim($in['full_name'] ?? '');
            $phone = trim($in['phone'] ?? '');
            $region = trim($in['region'] ?? '');
            $postal = trim($in['postal'] ?? '');
            $street = trim($in['street'] ?? '');
            $label = trim($in['label'] ?? 'Home');
            $isDefault = !empty($in['is_default']) ? 1 : 0;
            [$lat, $lng] = normalizeGeoPin($in['latitude'] ?? null, $in['longitude'] ?? null);
            if(!$name || !$phone || !$region || !$street){
                http_response_code(422);
                echo json_encode(['success'=>false,'message'=>'Missing fields']);
                exit;
            }
            // The map pin is required (the form disables Submit without one),
            // enforced here too so a disabled button is not the only thing
            // standing between a rider and an unfindable address. Only when
            // the columns exist - an install that hasn't run
            // DBSQL/add_address_geo_pin.sql cannot store a pin and must not
            // be locked out of saving addresses entirely.
            if($hasGeo && ($lat === null || $lng === null)){
                http_response_code(422);
                echo json_encode(['success'=>false,'message'=>'Please pin your exact location on the map']);
                exit;
            }
            if($isDefault){ $pdo->prepare('UPDATE user_addresses SET is_default=0 WHERE user_id=?')->execute([$userId]); }
            if($hasGeo){
                $st = $pdo->prepare('INSERT INTO user_addresses(user_id, full_name, phone, region, postal, street, label, is_default, latitude, longitude) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $st->execute([$userId,$name,$phone,$region,$postal,$street,$label,$isDefault,$lat,$lng]);
            } else {
                $st = $pdo->prepare('INSERT INTO user_addresses(user_id, full_name, phone, region, postal, street, label, is_default) VALUES(?,?,?,?,?,?,?,?)');
                $st->execute([$userId,$name,$phone,$region,$postal,$street,$label,$isDefault]);
            }
            echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
            break;
        case 'update':
            $id = (int)($_GET['id'] ?? 0);
            $in = json_decode(file_get_contents('php://input'), true) ?? [];
            if($id<=0){ http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid id']); break; }
            $name = trim($in['full_name'] ?? '');
            $phone = trim($in['phone'] ?? '');
            $region = trim($in['region'] ?? '');
            $postal = trim($in['postal'] ?? '');
            $street = trim($in['street'] ?? '');
            $label = trim($in['label'] ?? 'Home');
            $isDefault = !empty($in['is_default']) ? 1 : 0;
            [$lat, $lng] = normalizeGeoPin($in['latitude'] ?? null, $in['longitude'] ?? null);
            if($hasGeo && ($lat === null || $lng === null)){
                http_response_code(422);
                echo json_encode(['success'=>false,'message'=>'Please pin your exact location on the map']);
                break;
            }
            if($isDefault){ $pdo->prepare('UPDATE user_addresses SET is_default=0 WHERE user_id=?')->execute([$userId]); }
            if($hasGeo){
                // A write that reaches here always carries a valid pin (checked
                // above), so this only ever overwrites one pin with another.
                $st = $pdo->prepare('UPDATE user_addresses SET full_name=?, phone=?, region=?, postal=?, street=?, label=?, is_default=?, latitude=?, longitude=? WHERE id=? AND user_id=?');
                $st->execute([$name,$phone,$region,$postal,$street,$label,$isDefault,$lat,$lng,$id,$userId]);
            } else {
                $st = $pdo->prepare('UPDATE user_addresses SET full_name=?, phone=?, region=?, postal=?, street=?, label=?, is_default=? WHERE id=? AND user_id=?');
                $st->execute([$name,$phone,$region,$postal,$street,$label,$isDefault,$id,$userId]);
            }
            echo json_encode(['success'=>true]);
            break;
        case 'delete':
            $id = (int)($_GET['id'] ?? 0);
            if($id<=0){ http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid id']); break; }
            $st = $pdo->prepare('DELETE FROM user_addresses WHERE id=? AND user_id=?');
            $st->execute([$id,$userId]);
            echo json_encode(['success'=>true]);
            break;
        case 'set_default':
            $id = (int)($_GET['id'] ?? 0);
            if($id<=0){ http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid id']); break; }
            $pdo->prepare('UPDATE user_addresses SET is_default=0 WHERE user_id=?')->execute([$userId]);
            $pdo->prepare('UPDATE user_addresses SET is_default=1 WHERE id=? AND user_id=?')->execute([$id,$userId]);
            echo json_encode(['success'=>true]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'Invalid action']);
    }
} catch(Throwable $e){
    error_log('addresses api: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}

// Table is created via DBSQL scripts; no DDL here


