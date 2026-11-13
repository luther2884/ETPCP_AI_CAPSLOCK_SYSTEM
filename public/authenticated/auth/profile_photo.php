<?php
// auth/profile_photo.php - Serve a user's profile photo from DB or legacy path
// This endpoint is used by the profile page after upload to render the image URL.

require_once __DIR__ . '/../../../config/functions.php';

// Allow images to be cached briefly, but ensure updates can bypass via cache buster in URL
header('Cache-Control: public, max-age=300, must-revalidate');

// Require a user id
$userId = isset($_GET['u']) ? (int)$_GET['u'] : 0;
if ($userId <= 0) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

try {
    $pdo = getConnection();
    // Prefer DB blob if present
    $stmt = $pdo->prepare('SELECT photo_blob, photo_mime, photo_path FROM user_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if ($row && !empty($row['photo_blob'])) {
        $mime = $row['photo_mime'] ?: 'image/jpeg';
        header('Content-Type: ' . $mime);
        // Some drivers return a stream resource; handle both string and stream
        $blob = $row['photo_blob'];
        if (is_resource($blob)) {
            fpassthru($blob);
        } else {
            echo $blob;
        }
        exit;
    }

    // Fallback: if a stored relative path exists, serve that file
    $relative = $row['photo_path'] ?? null;
    if ($relative) {
        $absolute = realpath(__DIR__ . '/../' . $relative);
        if ($absolute && is_file($absolute)) {
            $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
            header('Content-Type: ' . $mime);
            readfile($absolute);
            exit;
        }
    }

    // Legacy fallback: scan filesystem under public/authenticated/img/profiles
    $base = __DIR__ . '/../img/profiles';
    foreach (['jpg','jpeg','png','webp'] as $ext) {
        $candidate = $base . '/user_' . $userId . '.' . $ext;
        if (is_file($candidate)) {
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
            header('Content-Type: ' . $mime);
            readfile($candidate);
            exit;
        }
    }
} catch (Throwable $e) {
    // fall through to 404
}

http_response_code(404);
echo 'Not found';
?>

<?php
// Serve DB-stored profile photo securely
require_once __DIR__ . '/../../../config/functions.php';

if (!isset($_GET['u'])) { http_response_code(400); exit; }
$userId = (int)$_GET['u'];
if ($userId <= 0) { http_response_code(400); exit; }

try {
    $pdo = getConnection();
    $st = $pdo->prepare('SELECT photo_blob, photo_mime, photo_updated_at FROM user_profiles WHERE user_id = ? AND photo_blob IS NOT NULL');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); exit; }

    $mime = $row['photo_mime'] ?: 'image/jpeg';
    $blob = $row['photo_blob'];
    if (is_resource($blob)) {
        $data = stream_get_contents($blob);
    } else {
        $data = (string)$blob;
    }
    header('Content-Type: ' . $mime);
    if (!empty($row['photo_updated_at'])) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($row['photo_updated_at'])) . ' GMT');
    }
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Length: ' . strlen($data));
    echo $data;
} catch (Throwable $e) {
    http_response_code(500);
}
?>


