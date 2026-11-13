<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_role(['admin']);

// Streams an uploaded Analytics file (xlsx/pbix) by DB id. Files live in
// storage/analytics_uploads/ (outside the web root) and are only ever reachable
// through this admin-gated endpoint - never by a direct/static URL.
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare('SELECT original_name, stored_name, file_type FROM analytics_uploads WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    $path = __DIR__ . '/../../../storage/analytics_uploads/' . $row['stored_name'];
    $realBase = realpath(__DIR__ . '/../../../storage/analytics_uploads');
    $realPath = realpath($path);
    if (!$realBase || !$realPath || strpos($realPath, $realBase) !== 0 || !is_file($realPath)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    $mime = $row['file_type'] === 'xlsx'
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($row['original_name']) . '"');
    header('Content-Length: ' . filesize($realPath));
    readfile($realPath);
    exit;
} catch (Throwable $e) {
    error_log('analytics_download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
}
