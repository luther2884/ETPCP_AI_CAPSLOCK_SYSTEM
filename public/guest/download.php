<?php
// download.php - Downloads page for guests with PWA functionality
require_once '../../config/functions.php';

$pageTitle = 'Downloads - TechWorld';


include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<!-- Downloads Section -->
<section class="downloads-section">
    <div class="container">
        <div class="downloads-header">
            <h1>Downloads</h1>
            <p>Access our latest resources, guides, and tools to enhance your tech experience</p>
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
    
    // Comprehensive PWA diagnostics
    async function diagnosePWA() {
        const diagnostics = {
            protocol: window.location.protocol,
            host: window.location.host,
            serviceWorker: false,
            manifest: false,
            icons: false,
            installable: false,
            reason: []
        };
        
        // Check service worker
        if ('serviceWorker' in navigator) {
            try {
                const reg = await navigator.serviceWorker.getRegistration();
                diagnostics.serviceWorker = !!reg;
                if (reg) {
                    diagnostics.serviceWorkerActive = !!reg.active;
                    diagnostics.serviceWorkerScope = reg.scope;
                }
            } catch (e) {
                diagnostics.reason.push('Service worker check failed: ' + e.message);
            }
        } else {
            diagnostics.reason.push('Service worker not supported');
        }
        
        // Check manifest
        try {
            const manifestUrl = window.WEB_ROOT + 'public/shared/manifest.php';
            const response = await fetch(manifestUrl);
            if (response.ok) {
                const manifest = await response.json();
                diagnostics.manifest = true;
                diagnostics.manifestName = manifest.name;
                diagnostics.manifestStartUrl = manifest.start_url;
                diagnostics.manifestScope = manifest.scope;
                
                // Check icons
                if (manifest.icons && manifest.icons.length > 0) {
                    diagnostics.icons = true;
                    diagnostics.iconCount = manifest.icons.length;
                } else {
                    diagnostics.reason.push('No icons in manifest');
                }
            } else {
                diagnostics.reason.push('Manifest not accessible: ' + response.status);
            }
        } catch (e) {
            diagnostics.reason.push('Manifest fetch failed: ' + e.message);
        }
        
        // Check if installable
        if (diagnostics.serviceWorker && diagnostics.manifest && diagnostics.icons) {
            // On HTTP, browsers may not allow installation
            if (diagnostics.protocol === 'http:' && !diagnostics.host.includes('localhost') && !diagnostics.host.includes('127.0.0.1')) {
                diagnostics.reason.push('⚠️ HTTP (not HTTPS) - Browsers require HTTPS for PWA installation (except localhost)');
                diagnostics.installable = false;
            } else {
                diagnostics.installable = true;
            }
        }
        
        console.log('🔍 PWA Diagnostics:', diagnostics);
        return diagnostics;
    }
    
    // Run diagnostics on load
    diagnosePWA();
    
    // Listen for beforeinstallprompt event (for native prompt support)
    window.addEventListener('beforeinstallprompt', (e) => {
        console.log('✅✅✅ beforeinstallprompt event fired! Installation is possible!');
        // Prevent the mini-infobar from appearing on mobile
        e.preventDefault();
        // Stash the event so it can be triggered later
        deferredPrompt = e;
        // Update button to show it's ready
        if (installBtn) {
            installBtn.innerHTML = '<i class="fi fi-rs-download"></i><span>Install App Now</span>';
            installBtn.style.background = 'linear-gradient(45deg, #10b981, #059669)';
            installBtn.disabled = false;
        }
    });
    
    // Also listen for why install might not be available
    window.addEventListener('appinstalled', () => {
        console.log('✅✅✅ App was successfully installed!');
    });

    // Handle install button click - DIRECT INSTALLATION
    if (installBtn) {
        installBtn.addEventListener('click', async () => {
            console.log('🚀 Install button clicked - Starting installation process...');
            
            // Disable button to prevent multiple clicks
            installBtn.disabled = true;
            installBtn.innerHTML = '<i class="fi fi-rs-loading"></i><span>Preparing installation...</span>';
            
            // Step 1: Verify manifest is accessible
            try {
                const manifestUrl = window.WEB_ROOT + 'public/shared/manifest.php';
                const manifestResponse = await fetch(manifestUrl);
                if (!manifestResponse.ok) {
                    throw new Error('Manifest not accessible');
                }
                const manifest = await manifestResponse.json();
                console.log('✅ Manifest loaded:', manifest.name);
            } catch (err) {
                console.error('❌ Manifest error:', err);
            }
            
            // Step 2: Ensure service worker is registered and active
            if ('serviceWorker' in navigator) {
                try {
                    let registration = await navigator.serviceWorker.getRegistration();
                    if (!registration) {
                        console.log('📦 Registering service worker...');
                        const swUrl = window.WEB_ROOT + 'public/sw.js';
                        registration = await navigator.serviceWorker.register(swUrl);
                        console.log('✅ Service worker registered:', registration.scope);
                    } else {
                        console.log('✅ Service worker already registered');
                    }
                    
                    // Wait for service worker to be ready and active
                    await navigator.serviceWorker.ready;
                    console.log('✅ Service worker is ready');
                    
                    // Ensure it's active
                    if (registration.installing) {
                        console.log('⏳ Service worker is installing, waiting...');
                        await new Promise((resolve) => {
                            registration.installing.addEventListener('statechange', function() {
                                if (this.state === 'activated') {
                                    console.log('✅ Service worker activated');
                                    resolve();
                                }
                            });
                        });
                    }
                } catch (err) {
                    console.error('❌ Service worker error:', err);
                }
            }
            
            // Step 3: Run diagnostics
            const diagnostics = await diagnosePWA();
            
            // Step 4: If we have the prompt, use it immediately
            if (deferredPrompt) {
                console.log('🎯 Triggering install prompt NOW...');
                try {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    
                    if (outcome === 'accepted') {
                        console.log('✅✅✅ User accepted - Installation starting!');
                        if (installContainer) installContainer.style.display = 'none';
                        if (installedMessage) installedMessage.style.display = 'block';
                        if (installBtn) {
                            installBtn.innerHTML = '<i class="fi fi-rs-check-circle"></i><span>Installing...</span>';
                        }
                    } else {
                        console.log('❌ User dismissed the install prompt');
                        installBtn.disabled = false;
                        installBtn.innerHTML = '<i class="fi fi-rs-download"></i><span>Install App</span>';
                    }
                    
                    deferredPrompt = null;
                    return;
                } catch (error) {
                    console.error('❌ Error showing install prompt:', error);
                    console.error('Error details:', error.message, error.stack);
                }
            } else {
                console.warn('⚠️ No deferredPrompt available');
                console.log('Diagnostics:', diagnostics);
                if (diagnostics.reason.length > 0) {
                    console.error('❌ Installation blocked:', diagnostics.reason.join(', '));
                }
            }
            
            // Step 5: Wait longer for the prompt (sometimes it's delayed on HTTP/IP)
            if (!deferredPrompt) {
                console.log('⏳ Waiting for install prompt (up to 8 seconds)...');
                installBtn.innerHTML = '<i class="fi fi-rs-loading"></i><span>Waiting for browser...</span>';
                
                const promptWait = new Promise((resolve) => {
                    const timeout = setTimeout(() => {
                        console.log('⏱️ Timeout waiting for prompt after 8 seconds');
                        resolve(null);
                    }, 8000); // Increased to 8 seconds
                    
                    const handler = (e) => {
                        clearTimeout(timeout);
                        window.removeEventListener('beforeinstallprompt', handler);
                        e.preventDefault();
                        console.log('✅✅✅ Prompt received while waiting!');
                        resolve(e);
                    };
                    
                    window.addEventListener('beforeinstallprompt', handler, { once: true });
                });
                
                const prompt = await promptWait;
                if (prompt) {
                    deferredPrompt = prompt;
                    console.log('🎯 Got prompt, triggering now...');
                    try {
                        prompt.prompt();
                        const { outcome } = await prompt.userChoice;
                        if (outcome === 'accepted') {
                            console.log('✅✅✅ Installation accepted!');
                            if (installContainer) installContainer.style.display = 'none';
                            if (installedMessage) installedMessage.style.display = 'block';
                            installBtn.innerHTML = '<i class="fi fi-rs-check-circle"></i><span>Installing...</span>';
                        } else {
                            console.log('❌ User dismissed');
                            installBtn.disabled = false;
                            installBtn.innerHTML = '<i class="fi fi-rs-download"></i><span>Install App</span>';
                        }
                        deferredPrompt = null;
                        return;
                    } catch (err) {
                        console.error('❌ Error on delayed prompt:', err);
                    }
                }
            }
            
            // Final check - if still no prompt, show why
            if (!deferredPrompt) {
                console.error('❌❌❌ INSTALLATION FAILED - No prompt available');
                console.error('📋 Full diagnostics:', diagnostics);
                
                // Show user-friendly message
                if (diagnostics.protocol === 'http:' && !diagnostics.host.includes('localhost')) {
                    console.error('🔒 ROOT CAUSE: HTTP protocol detected. Browsers require HTTPS for PWA installation.');
                    console.error('💡 SOLUTION: Use HTTPS or access via localhost/127.0.0.1');
                }
                
                if (installBtn) {
                    installBtn.disabled = false;
                    installBtn.innerHTML = '<i class="fi fi-rs-download"></i><span>Install App</span>';
                    installBtn.style.background = 'linear-gradient(45deg, #00f5ff, #0080ff)';
                }
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

// Removed showInstallInstructions - we want direct installation only
</script>

<?php
include __DIR__ . '/includes/footer.php';
?>
