<?php
// download.php - Downloads page for authenticated customers with PWA functionality
require_once '../../../config/functions.php';
require_login();

$pageTitle = 'Downloads - TechWorld';


?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js
         is deferred and would run a frame after first paint, which is exactly
         the flash this prevents. Resolution order is localStorage -> OS
         preference -> dark. There is no server-rendered preference to fall
         back on yet - `users` has no theme column; see the TODO in theme.js. */ ?>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
  <link rel="manifest" href="<?= WEB_ROOT ?>public/shared/manifest.json">
  <link rel="stylesheet" href="<?= WEB_ROOT ?>public/shared/style.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
  <meta name="theme-color" content="#00f5ff">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="TechWorld">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<!-- Downloads Section -->
<section class="downloads-section">
    <div class="container">
        <div class="downloads-header">
            <h1>Downloads</h1>
            <p>Access our latest resources, guides, and exclusive member content</p>
        </div>

        <!-- PWA Download Section -->
        <div class="pwa-section">
            <div class="pwa-card">
                
                
                <div class="pwa-icon">
                    <i class="fi fi-rs-mobile"></i>
                </div>
                <div class="pwa-content">
                    <h2>Install TechWorld App</h2>
                    <p>Get the full TechWorld experience on your device with our Progressive Web App</p>
                    <div class="pwa-features">
                        <div class="feature">
                            <i class="fi fi-rs-check"></i>
                            <span>Offline Access</span>
                        </div>
                        <div class="feature">
                            <i class="fi fi-rs-check"></i>
                            <span>Push Notifications</span>
                        </div>
                        <div class="feature">
                            <i class="fi fi-rs-check"></i>
                            <span>Fast Loading</span>
                        </div>
                        <div class="feature">
                            <i class="fi fi-rs-check"></i>
                            <span>Native App Feel</span>
                        </div>
                    </div>
                    
                    <div id="pwa-install-container">
                        <button id="pwa-install-btn" class="pwa-install-btn">
                            <i class="fi fi-rs-download"></i>
                            <span>Install App</span>
                        </button>
                    </div>
                    
                    <div id="pwa-installed-message" style="display: none; margin-top: 20px; padding: 15px; background: rgba(0, 245, 255, 0.1); border-radius: 10px; color: #00f5ff; text-align: center;">
                        <i class="fi fi-rs-check-circle"></i> App is installed!
                    </div>
                    
                </div>
            </div>
        </div>

    </div>
</section>


<style>
.downloads-section {
    padding: 60px 0;
    min-height: 80vh;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
}

.downloads-header {
    text-align: center;
    margin-bottom: 50px;
}

.downloads-header h1 {
    color: #e0e6ed;
    font-size: 48px;
    margin-bottom: 20px;
    background: linear-gradient(45deg, #00f5ff, #0080ff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.downloads-header p {
    color: #94a3b8;
    font-size: 18px;
    max-width: 600px;
    margin: 0 auto;
}

.pwa-section {
    margin-bottom: 60px;
}

.pwa-card {
    background: linear-gradient(135deg, rgba(0, 245, 255, 0.1) 0%, rgba(0, 128, 255, 0.1) 100%);
    border: 2px solid rgba(0, 245, 255, 0.3);
    border-radius: 25px;
    padding: 40px;
    display: flex;
    align-items: center;
    gap: 30px;
    position: relative;
    overflow: hidden;
}

.pwa-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(45deg, rgba(0, 245, 255, 0.05), rgba(0, 128, 255, 0.05));
    z-index: 1;
}

.pwa-icon {
    width: 120px;
    height: 120px;
    background: linear-gradient(45deg, #00f5ff, #0080ff);
    border-radius: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
    z-index: 2;
}

.pwa-icon i {
    font-size: 48px;
    color: white;
}

.pwa-content {
    flex: 1;
    position: relative;
    z-index: 2;
}

.pwa-content h2 {
    color: #e0e6ed;
    font-size: 32px;
    margin-bottom: 15px;
    font-weight: 700;
}

.pwa-content p {
    color: #94a3b8;
    font-size: 18px;
    margin-bottom: 25px;
    line-height: 1.6;
}

.pwa-features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.feature {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #cbd5e1;
    font-size: 14px;
    font-weight: 500;
}

.feature i {
    color: #00f5ff;
    font-size: 16px;
}

.pwa-install-btn,
.pwa-open-btn {
    background: linear-gradient(45deg, #00f5ff, #0080ff);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 15px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex !important;
    align-items: center;
    gap: 10px;
}

/* Override global hide rule for download page */
#pwa-install-container {
    display: block !important;
}

#pwa-install-btn {
    display: flex !important;
}

.pwa-install-btn:hover,
.pwa-open-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(0, 128, 255, 0.3);
}


@media (max-width: 768px) {
    .downloads-header h1 {
        font-size: 36px;
    }
    
    .pwa-card {
        flex-direction: column;
        text-align: center;
    }
    
    .pwa-features {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// PWA Install Button Handler
let deferredPrompt;
const installBtn = document.getElementById('pwa-install-btn');
const installContainer = document.getElementById('pwa-install-container');
const installedMessage = document.getElementById('pwa-installed-message');

// Check if app is already installed
function isPWAInstalled() {
    return window.matchMedia('(display-mode: standalone)').matches || 
           window.navigator.standalone === true ||
           document.referrer.includes('android-app://');
}

// Check if app is installed and show/hide accordingly
if (isPWAInstalled()) {
    // App is already installed - hide button, show message
    if (installContainer) installContainer.style.display = 'none';
    if (installedMessage) installedMessage.style.display = 'block';
} else {
    // App is not installed - show button, hide message
    if (installContainer) installContainer.style.display = 'block';
    if (installedMessage) installedMessage.style.display = 'none';
    
    // Listen for beforeinstallprompt event (for native prompt support)
    window.addEventListener('beforeinstallprompt', (e) => {
        // Prevent the mini-infobar from appearing on mobile
        e.preventDefault();
        // Stash the event so it can be triggered later
        deferredPrompt = e;
    });

    // Handle install button click
    if (installBtn) {
        installBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                // Use native install prompt if available
                try {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    
                    if (outcome === 'accepted') {
                        console.log('User accepted the install prompt');
                        if (installContainer) installContainer.style.display = 'none';
                        if (installedMessage) installedMessage.style.display = 'block';
                    } else {
                        console.log('User dismissed the install prompt');
                    }
                    
                    deferredPrompt = null;
                } catch (error) {
                    console.error('Error showing install prompt:', error);
                    showInstallInstructions();
                }
            } else {
                // Fallback: show instructions for manual installation
                showInstallInstructions();
            }
        });
    }

    // Listen for app installed event
    window.addEventListener('appinstalled', () => {
        console.log('PWA was installed');
        if (installContainer) installContainer.style.display = 'none';
        if (installedMessage) installedMessage.style.display = 'block';
        deferredPrompt = null;
    });
}

// Function to show installation instructions
function showInstallInstructions() {
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const isAndroid = /Android/.test(navigator.userAgent);
    
    let instructions = '';
    if (isIOS) {
        instructions = 'To install the app on iOS:\n\n' +
                      '1. Tap the Share button (square with arrow) at the bottom\n' +
                      '2. Scroll down and tap "Add to Home Screen"\n' +
                      '3. Tap "Add" to confirm';
    } else if (isAndroid) {
        instructions = 'To install the app on Android:\n\n' +
                      '1. Tap the menu (⋮) in the top right of your browser\n' +
                      '2. Select "Add to Home screen" or "Install app"\n' +
                      '3. Tap "Install" or "Add" to confirm';
    } else {
        instructions = 'To install the app:\n\n' +
                      'Desktop: Look for the install icon in your browser\'s address bar\n\n' +
                      'Android: Tap menu (⋮) → "Add to Home screen" or "Install app"\n\n' +
                      'iOS: Tap Share → "Add to Home Screen"';
    }
    
    alert(instructions);
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
</body>
</html>
