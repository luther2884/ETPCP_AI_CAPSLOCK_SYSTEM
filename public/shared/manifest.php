<?php
// Dynamic manifest.json generator that works with both domain and IP addresses
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Get WEB_ROOT if available
if (!defined('WEB_ROOT')) {
    $dbConfigPath = __DIR__ . '/../../config/database.php';
    if (file_exists($dbConfigPath)) {
        require_once $dbConfigPath;
    }
}

// Calculate WEB_ROOT if still not defined
if (!defined('WEB_ROOT')) {
    $docRootFs = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
    $projectRootFs = str_replace('\\','/', dirname(__DIR__));
    $webRoot = '/';
    if ($docRootFs && strpos($projectRootFs, $docRootFs) === 0) {
        $webRoot = substr($projectRootFs, strlen($docRootFs));
        if ($webRoot === '') { $webRoot = '/'; }
        else { $webRoot = rtrim($webRoot, '/') . '/'; }
    }
} else {
    $webRoot = WEB_ROOT;
}

// Ensure WEB_ROOT ends with / for proper path construction
$webRoot = rtrim($webRoot, '/') . '/';

// Build absolute URLs for proper PWA support
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $protocol . '://' . $host;

// Build paths
$startUrl = $baseUrl . $webRoot . 'public/guest/index.php';
$scope = $baseUrl . $webRoot . 'public/';
$manifestId = $baseUrl . $webRoot . 'public/';

// Icon paths - use absolute URLs
$iconBase = $baseUrl . $webRoot . 'public/shared/assets/images/icons/';

$manifest = [
    'name' => 'TechWorld E-commerce',
    'short_name' => 'TechWorld',
    'description' => 'Premium Computer Components & Systems - Shop desktop PCs, laptops, processors, graphics cards and more',
    'start_url' => $startUrl,
    'id' => $manifestId,
    'display' => 'standalone',
    'background_color' => '#0f172a',
    'theme_color' => '#00f5ff',
    'orientation' => 'any',
    'scope' => $scope,
    'lang' => 'en',
    'dir' => 'ltr',
    'categories' => ['shopping', 'business', 'productivity'],
    'display_override' => ['window-controls-overlay', 'standalone'],
    'icons' => [
        [
            'src' => $iconBase . 'icon-72.png',
            'sizes' => '72x72',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $iconBase . 'icon-96.png',
            'sizes' => '96x96',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $iconBase . 'icon-128.png',
            'sizes' => '128x128',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $iconBase . 'icon-144.png',
            'sizes' => '144x144',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $iconBase . 'icon-152.png',
            'sizes' => '152x152',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $iconBase . 'icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => $iconBase . 'icon-384.png',
            'sizes' => '384x384',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $iconBase . 'icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ],
    'related_applications' => [],
    'prefer_related_applications' => false,
    'shortcuts' => [
        [
            'name' => 'Shop Products',
            'short_name' => 'Shop',
            'description' => 'Browse all products',
            'url' => $baseUrl . $webRoot . 'public/guest/products.php',
            'icons' => [
                [
                    'src' => $baseUrl . $webRoot . 'public/shared/img/Logo.ico',
                    'sizes' => '96x96'
                ]
            ]
        ],
        [
            'name' => 'Downloads',
            'short_name' => 'Downloads',
            'description' => 'Download resources and install PWA',
            'url' => $baseUrl . $webRoot . 'public/guest/download.php',
            'icons' => [
                [
                    'src' => $baseUrl . $webRoot . 'public/shared/img/Logo.ico',
                    'sizes' => '96x96'
                ]
            ]
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>


