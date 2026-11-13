<?php
// scan/verify.php - QR scanner for pickup verification
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['driver', 'admin', 'super-admin']);

$pageTitle = 'QR Scanner - Order Pickup Verification';
$userData = getUserData();

include __DIR__ . '/../../shared/includes/header.php';
?>

<section class="scanner-page">
    <div class="container">
        <div class="scanner-container">
            <div class="scanner-header">
                <h1>Order Pickup Scanner</h1>
                <p>Scan QR codes or enter pickup codes manually</p>
                <div class="user-info">
                    Logged in as:
                    <strong><?php echo htmlspecialchars($userData['firstname'] . ' ' . $userData['lastname']); ?></strong>
                    <span class="role-badge"><?php echo ucfirst($userData['role']); ?></span>
                </div>
            </div>

            <div class="scanner-content">
                <!-- Scanner Methods -->
                <div class="scanner-methods">
                    <button class="method-btn active" onclick="switchMethod('camera')" id="cameraBtn">
                        <i class="fi fi-rs-camera"></i>
                        Camera Scanner
                    </button>
                    <button class="method-btn" onclick="switchMethod('manual')" id="manualBtn">
                        <i class="fi fi-rs-keyboard"></i>
                        Manual Entry
                    </button>
                </div>

                <!-- Camera Scanner Section -->
                <div class="scanner-section" id="cameraSection">
                    <div class="camera-container">
                        <div class="camera-viewport">
                            <div id="reader" class="qr-reader"></div>
                            <div class="scanner-overlay">
                                <div class="scanner-frame"></div>
                                <p class="scanner-instruction">Position QR code within the frame</p>
                            </div>
                        </div>

                        <div class="camera-controls">
                            <button class="control-btn" id="startBtn" onclick="startScanner()">
                                <i class="fi fi-rs-play"></i>
                                Start Scanner
                            </button>
                            <button class="control-btn" id="stopBtn" onclick="stopScanner()" style="display: none;">
                                <i class="fi fi-rs-pause"></i>
                                Stop Scanner
                            </button>
                            <button class="control-btn" id="switchCameraBtn" onclick="switchCamera()"
                                style="display: none;">
                                <i class="fi fi-rs-refresh"></i>
                                Switch Camera
                            </button>
                        </div>

                        <div class="scanner-status" id="scannerStatus">
                            Click "Start Scanner" to begin scanning QR codes
                        </div>
                    </div>
                </div>

                <!-- Manual Entry Section -->
                <div class="scanner-section" id="manualSection" style="display: none;">
                    <div class="manual-entry">
                        <h3>Manual Pickup Code Entry</h3>
                        <p>Enter the order details or pickup code manually</p>

                        <form class="manual-form" id="manualForm">
                            <div class="input-group">
                                <label>Pickup Code (6 digits)</label>
                                <input type="text" id="pickupCode" placeholder="123456" maxlength="6"
                                    pattern="[0-9]{6}">
                            </div>

                            <div class="input-group">
                                <label>Order ID (optional)</label>
                                <input type="number" id="orderId" placeholder="Enter order ID">
                            </div>

                            <button type="submit" class="verify-btn">
                                <i class="fi fi-rs-check"></i>
                                Verify Pickup Code
                            </button>
                        </form>

                        <div class="quick-paste">
                            <p>Or paste QR code data:</p>
                            <textarea id="qrData"
                                placeholder="Paste QR code content here (e.g., order:123:456789)"></textarea>
                            <button class="paste-btn" onclick="processQRData()">
                                <i class="fi fi-rs-paste"></i>
                                Process Data
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Scan Results -->
                <div class="scan-results" id="scanResults" style="display: none;">
                    <div class="results-container">
                        <div class="result-header">
                            <div class="result-status" id="resultStatus">
                                <div class="status-icon" id="statusIcon"></div>
                                <h3 id="statusMessage"></h3>
                            </div>
                        </div>

                        <div class="order-details" id="orderDetails">
                            <!-- Order details will be populated here -->
                        </div>

                        <div class="result-actions">
                            <button class="action-btn confirm-btn" id="confirmBtn" onclick="confirmPickup()"
                                style="display: none;">
                                <i class="fi fi-rs-check-circle"></i>
                                Confirm Pickup
                            </button>
                            <button class="action-btn cancel-btn" onclick="clearResults()">
                                <i class="fi fi-rs-cross"></i>
                                Clear Results
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Recent Scans -->
                <div class="recent-scans">
                    <h3>Recent Verifications</h3>
                    <div class="scans-list" id="recentScans">
                        <div class="empty-scans">
                            <i class="fi fi-rs-document"></i>
                            <p>No recent verifications</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    .scanner-page {
        padding: 40px 0;
        background: var(--page-bg-gradient);
        min-height: 100vh;
    }

    .scanner-container {
        max-width: 1000px;
        margin: 0 auto;
    }

    .scanner-header {
        text-align: center;
        margin-bottom: 40px;
    }

    .scanner-header h1 {
        color: #e0e6ed;
        font-size: 32px;
        margin-bottom: 10px;
    }

    .scanner-header p {
        color: #94a3b8;
        font-size: 16px;
        margin-bottom: 20px;
    }

    .user-info {
        color: #94a3b8;
        font-size: 14px;
    }

    .user-info strong {
        color: #e0e6ed;
    }

    .role-badge {
        background: linear-gradient(45deg, #a77693, #174871);
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 10px;
    }

    .scanner-content {
        display: flex;
        flex-direction: column;
        gap: 30px;
    }

    .scanner-methods {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-bottom: 20px;
    }

    .method-btn {
        background: rgba(30, 41, 59, 0.8);
        border: 2px solid rgba(255, 255, 255, 0.1);
        color: #94a3b8;
        padding: 15px 25px;
        border-radius: 12px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 16px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .method-btn:hover {
        border-color: rgba(167, 118, 147, 0.3);
        color: #a77693;
    }

    .method-btn.active {
        background: rgba(167, 118, 147, 0.1);
        border-color: #a77693;
        color: #a77693;
    }

    .scanner-section {
        background: rgba(30, 41, 59, 0.8);
        border-radius: 15px;
        padding: 40px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .camera-container {
        max-width: 600px;
        margin: 0 auto;
    }

    .camera-viewport {
        position: relative;
        background: #000;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 20px;
        min-height: 400px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .qr-reader {
        width: 100%;
        height: 400px;
    }

    /* Ensure Html5Qrcode video/canvas are visible and cover the reader area */
    #reader video,
    #reader canvas {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
        display: block !important;
    }

    .scanner-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }

    .scanner-frame {
        width: 250px;
        height: 250px;
        border: 3px solid #a77693;
        border-radius: 12px;
        position: relative;
        margin-bottom: 20px;
    }

    .scanner-frame::before,
    .scanner-frame::after {
        content: '';
        position: absolute;
        width: 30px;
        height: 30px;
        border: 3px solid #a77693;
    }

    .scanner-frame::before {
        top: -3px;
        left: -3px;
        border-right: none;
        border-bottom: none;
    }

    .scanner-frame::after {
        bottom: -3px;
        right: -3px;
        border-left: none;
        border-top: none;
    }

    .scanner-instruction {
        color: white;
        font-weight: 600;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.8);
        margin: 0;
    }

    .camera-controls {
        display: flex;
        justify-content: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .control-btn {
        background: linear-gradient(45deg, #a77693, #174871);
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .control-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(23, 72, 113, 0.3);
    }

    .scanner-status {
        text-align: center;
        padding: 15px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        color: #94a3b8;
        font-size: 14px;
    }

    .manual-entry {
        max-width: 500px;
        margin: 0 auto;
        text-align: center;
    }

    .manual-entry h3 {
        color: #e0e6ed;
        font-size: 24px;
        margin-bottom: 10px;
    }

    .manual-entry p {
        color: #94a3b8;
        margin-bottom: 30px;
    }

    .manual-form {
        display: flex;
        flex-direction: column;
        gap: 20px;
        margin-bottom: 30px;
    }

    .input-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .input-group label {
        color: #e0e6ed;
        font-weight: 600;
        text-align: left;
    }

    .input-group input {
        padding: 15px;
        background: rgba(255, 255, 255, 0.05);
        border: 2px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #e0e6ed;
        font-size: 16px;
    }

    .input-group input:focus {
        outline: none;
        border-color: #a77693;
        background: rgba(255, 255, 255, 0.1);
    }

    .verify-btn,
    .paste-btn {
        background: linear-gradient(45deg, #a77693, #174871);
        color: white;
        border: none;
        padding: 15px 25px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.3s ease;
    }

    .verify-btn:hover,
    .paste-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(23, 72, 113, 0.3);
    }

    .quick-paste {
        padding-top: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .quick-paste p {
        color: #94a3b8;
        margin-bottom: 15px;
    }

    .quick-paste textarea {
        width: 100%;
        height: 80px;
        padding: 15px;
        background: rgba(255, 255, 255, 0.05);
        border: 2px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        color: #e0e6ed;
        resize: vertical;
        margin-bottom: 15px;
        font-family: monospace;
    }

    .scan-results {
        background: rgba(30, 41, 59, 0.9);
        border-radius: 15px;
        padding: 30px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .result-header {
        text-align: center;
        margin-bottom: 30px;
    }

    .result-status {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 15px;
    }

    .status-icon {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 36px;
    }

    .status-icon.success {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .status-icon.error {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }

    .status-icon.warning {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }

    #statusMessage {
        color: #e0e6ed;
        font-size: 24px;
        margin: 0;
    }

    .order-details {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 30px;
    }

    .result-actions {
        display: flex;
        justify-content: center;
        gap: 15px;
    }

    .action-btn {
        padding: 15px 25px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
    }

    .confirm-btn {
        background: linear-gradient(45deg, #10b981, #059669);
        color: white;
    }

    .confirm-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
    }

    .cancel-btn {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .cancel-btn:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    .recent-scans {
        background: rgba(30, 41, 59, 0.6);
        border-radius: 15px;
        padding: 30px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .recent-scans h3 {
        color: #e0e6ed;
        margin-bottom: 20px;
        font-size: 20px;
    }

    .empty-scans {
        text-align: center;
        color: #94a3b8;
        padding: 40px 20px;
    }

    .empty-scans i {
        font-size: 48px;
        margin-bottom: 15px;
        display: block;
    }

    @keyframes spin {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    @media (max-width: 768px) {
        .scanner-container {
            margin: 0 20px;
        }

        .scanner-methods {
            flex-direction: column;
            align-items: center;
        }

        .method-btn {
            width: 100%;
            max-width: 300px;
            justify-content: center;
        }

        .camera-controls {
            flex-direction: column;
            align-items: center;
        }

        .result-actions {
            flex-direction: column;
            align-items: center;
        }

        .action-btn {
            width: 100%;
            max-width: 300px;
            justify-content: center;
        }
    }
</style>

<!-- Load QR Scanner Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>

<script>
    let html5QrCode;
    let isScanning = false;
    let currentOrderData = null;

    function switchMethod(method) {
        // Update button states
        document.querySelectorAll('.method-btn').forEach(btn => btn.classList.remove('active'));
        document.getElementById(method + 'Btn').classList.add('active');

        // Show/hide sections
        document.getElementById('cameraSection').style.display = method === 'camera' ? 'block' : 'none';
        document.getElementById('manualSection').style.display = method === 'manual' ? 'block' : 'none';

        // Stop scanner if switching away from camera
        if (method !== 'camera' && isScanning) {
            stopScanner();
        }
    }

    async function startScanner() {
        if (isScanning) return;

        // Camera access requires a secure context (HTTPS or localhost).
        // TWCamera adds the https URL of this page to the message, so the
        // person holding the phone has something to act on rather than a rule
        // to read - see shared/includes/camera-support.php.
        var camIssue = window.TWCamera ? window.TWCamera.problem()
                     : (!window.isSecureContext ? { message: 'Camera blocked: this page must be served over HTTPS or accessed via localhost.' } : null);
        if (camIssue) {
            document.getElementById('scannerStatus').textContent =
                (window.TWCamera && window.TWCamera.text()) || camIssue.message;
            return;
        }

        try {
            if (typeof Html5Qrcode === 'undefined') {
                document.getElementById('scannerStatus').textContent = 'QR scanner library failed to load.';
                console.error('Html5Qrcode is undefined. Check CDN or network.');
                return;
            }

            // Try a lightweight getUserMedia probe to ensure permission prompt appears early
            try {
                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    const probeStream = await navigator.mediaDevices.getUserMedia({ video: true });
                    probeStream.getTracks().forEach(t => t.stop());
                }
            } catch (probeErr) {
                console.warn('getUserMedia probe failed or was blocked:', probeErr);
                // continue to attempt using the library which will give more detailed errors
            }

            html5QrCode = new Html5Qrcode("reader");

            let cameras = [];
            try {
                cameras = await Html5Qrcode.getCameras();
            } catch (camErr) {
                console.warn('Html5Qrcode.getCameras() failed:', camErr);
            }

            // Fallback to navigator.enumerateDevices if the library didn't return cameras
            if (!cameras || cameras.length === 0) {
                if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                    const devices = await navigator.mediaDevices.enumerateDevices();
                    const videoInputs = devices.filter(d => d.kind === 'videoinput');
                    console.log('Enumerated video inputs:', videoInputs);
                    cameras = videoInputs.map(d => ({ id: d.deviceId, label: d.label || d.deviceId }));
                }
            }

            if (!cameras || cameras.length === 0) {
                document.getElementById('scannerStatus').textContent = 'No camera found on this device.';
                console.error('No cameras detected via Html5Qrcode.getCameras or enumerateDevices');
                return;
            }

            // Prefer the back/environment camera on mobile devices when available
            const preferred = cameras.find(c => /back|rear|environment/i.test(c.label));
            const cameraId = preferred ? preferred.id : cameras[cameras.length - 1].id;
            console.log('Using cameraId:', cameraId, 'available cameras:', cameras);

            await html5QrCode.start(
                cameraId,
                {
                    fps: 10,
                    qrbox: { width: 250, height: 250 },
                    aspectRatio: 1.0
                },
                onScanSuccess,
                onScanFailure
            );

            isScanning = true;
            document.getElementById('startBtn').style.display = 'none';
            document.getElementById('stopBtn').style.display = 'inline-flex';
            document.getElementById('switchCameraBtn').style.display = 'inline-flex';
            document.getElementById('scannerStatus').textContent = 'Scanner active - Point camera at QR code';

        } catch (err) {
            console.error('Camera start error:', err);
            let msg = 'Unable to start camera.';
            const errStr = (err && (err.name || err.message)) ? (err.name || err.message) : String(err);

            if (errStr.includes('NotAllowedError') || errStr.toLowerCase().includes('permission')) {
                msg = 'Camera permission denied. Check the site permissions (padlock icon) in your browser address bar.';
            } else if (errStr.includes('NotFoundError')) {
                msg = 'No camera device found.';
            } else if (errStr.includes('NotReadableError') || errStr.toLowerCase().includes('active')) {
                msg = 'Camera is already in use by another app or browser tab.';
            } else if (errStr.toLowerCase().includes('notsecure') || errStr.toLowerCase().includes('insecure')) {
                msg = 'Insecure context: camera access requires HTTPS or localhost.';
            } else {
                msg = 'Unable to start camera: ' + errStr;
            }

            document.getElementById('scannerStatus').textContent = msg;
        }
    }

    async function stopScanner() {
        if (html5QrCode && isScanning) {
            try {
                await html5QrCode.stop();
                html5QrCode.clear();
            } catch (err) {
                console.error('Error stopping scanner:', err);
            }
        }

        isScanning = false;
        html5QrCode = null;
        document.getElementById('startBtn').style.display = 'inline-flex';
        document.getElementById('stopBtn').style.display = 'none';
        document.getElementById('switchCameraBtn').style.display = 'none';
        document.getElementById('scannerStatus').textContent = 'Scanner stopped';
    }

    function switchCamera() {
        // This would cycle through available cameras
        showNotification('Camera switching feature coming soon!', 'info');
    }

    function onScanSuccess(decodedText, decodedResult) {
        console.log('QR Code scanned:', decodedText);
        processScannedData(decodedText);
        stopScanner();
    }

    function onScanFailure(error) {
        // Handle scan failures silently - too many false positives (fires every frame with no QR in view)
    }

    function processScannedData(data) {
        // Flexible parsing for QR data.
        // Supported formats:
        // - "order:ORDER_ID:PICKUP_CODE"
        // - A URL containing ?order_id=123
        // - Plain pickup code (6 digits) or any text containing a 6-digit code

        let orderId = null;
        let pickupCode = null;

        // 1) order:ORDER_ID:PICKUP_CODE
        if (data.startsWith('order:')) {
            const parts = data.split(':');
            if (parts.length >= 3) {
                orderId = parts[1];
                pickupCode = parts[2];
            }
        }

        // 2) Try parsing as URL with order_id param
        if (!orderId) {
            try {
                const parsed = new URL(data);
                const params = parsed.searchParams;
                if (params.has('order_id')) orderId = params.get('order_id');
                else if (params.has('id')) orderId = params.get('id');
            } catch (e) {
                // not a URL
            }
        }

        // 3) Try to extract a 6-digit pickup code from the scanned text
        if (!pickupCode) {
            const match = data.match(/(\d{6})/);
            if (match) pickupCode = match[1];
        }

        if (!orderId && !pickupCode) {
            showScanResult('error', 'Invalid QR Code', 'The scanned QR code does not contain a recognizable order or pickup code.');
            return;
        }

        // Proceed to verify. The API accepts either order_id or pickup_code.
        // If AUTO_CONFIRM_ON_SCAN is enabled, the scan will automatically confirm pickup after verification.
        verifyPickupCode(orderId || null, pickupCode || null, false, true);
    }

    function processQRData() {
        const qrData = document.getElementById('qrData').value.trim();
        if (!qrData) {
            showNotification('Please paste QR code data', 'error');
            return;
        }

        processScannedData(qrData);
    }

    // Manual form submission
    document.getElementById('manualForm').addEventListener('submit', function (e) {
        e.preventDefault();

        const pickupCode = document.getElementById('pickupCode').value.trim();
        const orderId = document.getElementById('orderId').value.trim();

        if (!pickupCode && !orderId) {
            showNotification('Please enter either pickup code or order ID', 'error');
            return;
        }

        verifyPickupCode(orderId || null, pickupCode || null);
    });

    // If you want the scanner to automatically confirm the pickup after verification, set this to true.
    const AUTO_CONFIRM_ON_SCAN = true;

    function verifyPickupCode(orderId, pickupCode, confirm = false, fromScan = false) {
        // Show loading state
        showScanResult('loading', confirm ? 'Confirming pickup...' : 'Verifying...', confirm ? 'Confirming pickup...' : 'Checking pickup code validity...');

        // API call to verify pickup (robust handling)
        fetch('<?php echo WEB_ROOT; ?>public/authenticated/api/verify_pickup.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                order_id: orderId,
                pickup_code: pickupCode,
                confirm: confirm
            })
        })
            .then(async response => {
                const text = await response.text();
                const contentType = response.headers.get('content-type') || '';
                if (!response.ok) {
                    console.error('Verify API returned error status', response.status, text);
                    try {
                        const obj = JSON.parse(text);
                        throw new Error(obj.message || 'Server error');
                    } catch (e) {
                        showScanResult('error', 'System Error', 'Server error: ' + (text ? text.substring(0, 200) : response.status));
                        return null;
                    }
                }

                if (!contentType.includes('application/json')) {
                    console.warn('Verify API returned non-JSON response:', text);
                }

                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Failed to parse JSON from verify API:', e, text);
                    showScanResult('error', 'System Error', 'Invalid server response. See console for details.');
                    return null;
                }
            })
            .then(data => {
                if (!data) return;
                if (data.success) {
                    currentOrderData = data.order;
                    if (confirm) {
                        showScanResult('success', 'Pickup Completed', 'Order has been successfully picked up.');
                        displayOrderDetails(data.order);
                        document.getElementById('confirmBtn').style.display = 'none';
                        addToRecentScans(data.order);
                    } else {
                        showScanResult('success', 'Valid Pickup Code', 'Order found and ready for pickup.');
                        displayOrderDetails(data.order);
                        document.getElementById('confirmBtn').style.display = 'inline-flex';

                        if (fromScan && AUTO_CONFIRM_ON_SCAN) {
                            setTimeout(() => verifyPickupCode(orderId, pickupCode, true, false), 500);
                        }
                    }
                } else {
                    showScanResult('error', 'Verification Failed', data.message || 'Order not found or invalid code.');
                    document.getElementById('confirmBtn').style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error during verifyPickupCode fetch:', error);
                showScanResult('error', 'System Error', error.message || 'Unable to verify pickup code.');
            });
    }

    function showScanResult(type, title, message) {
        const resultsSection = document.getElementById('scanResults');
        const statusIcon = document.getElementById('statusIcon');
        const statusMessage = document.getElementById('statusMessage');

        // Set icon based on type
        statusIcon.className = `status-icon ${type}`;
        statusIcon.style.animation = '';
        if (type === 'success') statusIcon.innerHTML = '<i class="fi fi-ss-check-circle"></i>';
        else if (type === 'error') statusIcon.innerHTML = '<i class="fi fi-ss-cross-circle"></i>';
        else if (type === 'warning') statusIcon.innerHTML = '<i class="fi fi-ss-exclamation-triangle"></i>';
        else if (type === 'loading') {
            statusIcon.innerHTML = '<i class="fi fi-rs-spinner"></i>';
            statusIcon.style.animation = 'spin 1s linear infinite';
        }

        statusMessage.textContent = message || title;
        resultsSection.style.display = 'block';
        resultsSection.scrollIntoView({ behavior: 'smooth' });
    }

    function displayOrderDetails(order) {
        const detailsContainer = document.getElementById('orderDetails');
        if (!order) return;

        // Store credit is spent at checkout and already deducted from
        // total_amount - so credit that covered the whole order leaves nothing
        // to collect here, and the operator needs to see that before pressing
        // Confirm Pickup rather than wonder where the payment went.
        const creditApplied = parseFloat(order.credit_applied) || 0;
        const creditRow = creditApplied > 0
            ? `<div class="info-row"><span class="label">Store Credit:</span><span class="value">-${formatCurrency(creditApplied)}</span></div>`
            : '';
        const collectRow = (parseFloat(order.total_amount) || 0) <= 0.009
            ? `<div class="info-row"><span class="label">To Collect:</span><span class="value">Nothing &ndash; paid with store credit</span></div>`
            : '';

        detailsContainer.innerHTML = `
            <div class="order-info-grid">
                <div class="info-row"><span class="label">Order ID:</span><span class="value">#${order.invoice_no}</span></div>
                <div class="info-row"><span class="label">Customer:</span><span class="value">${order.customer_name}</span></div>
                <div class="info-row"><span class="label">Order Date:</span><span class="value">${new Date(order.created_at).toLocaleDateString()}</span></div>
                ${creditRow}
                <div class="info-row"><span class="label">Total Amount:</span><span class="value total">${formatCurrency(order.total_amount)}</span></div>
                <div class="info-row"><span class="label">Payment Method:</span><span class="value">${(order.payment_method || '').toUpperCase()}</span></div>
                ${collectRow}
                <div class="info-row"><span class="label">Pickup Code:</span><span class="value pickup-code">${order.pickup_code}</span></div>
            </div>
        `;
    }

    async function confirmPickup() {
        if (!currentOrderData) { showNotification('No order data available', 'error'); return; }

        if (!(await uiConfirm('Confirm pickup for Order #' + currentOrderData.invoice_no + '?'))) return;

        fetch('<?php echo WEB_ROOT; ?>public/authenticated/api/verify_pickup.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ order_id: currentOrderData.id, pickup_code: currentOrderData.pickup_code, confirm: true })
        })
            .then(async res => { const txt = await res.text(); try { return JSON.parse(txt); } catch (e) { throw new Error('Invalid server response'); } })
            .then(data => {
                if (data.success) {
                    showNotification('Pickup confirmed successfully!', 'success');
                    showScanResult('success', 'Pickup Completed', 'Order has been successfully picked up.');
                    document.getElementById('confirmBtn').style.display = 'none';
                    addToRecentScans(currentOrderData);
                    currentOrderData = null;
                } else showNotification(data.message || 'Unable to confirm pickup', 'error');
            })
            .catch(err => { console.error('Confirm pickup error:', err); showNotification('Unable to confirm pickup. Please try again.', 'error'); });
    }

    function clearResults() {
        document.getElementById('scanResults').style.display = 'none';
        currentOrderData = null;
        document.getElementById('manualForm').reset();
        document.getElementById('qrData').value = '';
    }

    function addToRecentScans(order) {
        const recentScans = document.getElementById('recentScans');
        const emptyScans = recentScans.querySelector('.empty-scans'); if (emptyScans) emptyScans.remove();

        const scanItem = document.createElement('div');
        scanItem.className = 'recent-scan-item';
        scanItem.innerHTML = `
            <div class="scan-info">
                <div class="scan-header"><strong>Order #${order.invoice_no}</strong><span class="scan-time">${new Date().toLocaleTimeString()}</span></div>
                <div class="scan-details"><span>Customer: ${order.customer_name}</span><span>Amount: ${formatCurrency(order.total_amount)}</span></div>
            </div>
            <div class="scan-status completed"><i class="fi fi-ss-check-circle"></i> Completed</div>
        `;

        if (!document.querySelector('style[data-recent-scans]')) {
            const style = document.createElement('style');
            style.setAttribute('data-recent-scans', 'true');
            style.textContent = `
            .recent-scan-item{display:flex;justify-content:space-between;align-items:center;padding:15px;background:rgba(255,255,255,0.05);border-radius:8px;margin-bottom:10px;border-left:4px solid #10b981}
            .scan-header strong{color:#e0e6ed}
            .scan-time{color:#94a3b8;font-size:12px}
            .scan-details{display:flex;gap:20px;color:#94a3b8;font-size:14px}
            .scan-status{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600}.scan-status.completed{color:#10b981}
            `;
            document.head.appendChild(style);
        }

        recentScans.insertBefore(scanItem, recentScans.firstChild);
        const items = recentScans.querySelectorAll('.recent-scan-item'); if (items.length > 5) items[items.length - 1].remove();
    }

    // Format currency function
    function formatCurrency(amount) {
        return '₱' + new Intl.NumberFormat('en-PH').format(parseFloat(amount));
    }

    // Simple notification fallback if not defined elsewhere in the shared layout
    if (typeof showNotification !== 'function') {
        function showNotification(message, type) {
            console.log(`[${type}] ${message}`);
            alert(message);
        }
    }

    // Initialize page
    document.addEventListener('DOMContentLoaded', function () {
        // Auto-focus on pickup code input when manual section is active
        document.getElementById('pickupCode').addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    });
</script>

<?php
include __DIR__ . '/../../shared/includes/footer.php';
?>