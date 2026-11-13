<?php
// scanner.php - In-browser QR scanner for mobile devices
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Scanner - TechWorld</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: 
                linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.6)),
                url('../../shared/img/bg.jpg') center center/cover no-repeat fixed;
            min-height: 100vh;
            line-height: 1.6;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            max-width: 500px;
            width: 100%;
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(20px);
            padding: 40px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        
        h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .scanner-container {
            margin: 30px 0;
            position: relative;
        }
        
        #qr-reader {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }
        
        .instructions {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 25px;
            line-height: 1.8;
        }
        
        .instructions p {
            margin-bottom: 10px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(107, 114, 128, 0.8);
            color: white;
            padding: 15px 25px;
            text-decoration: none;
            border-radius: 25px;
            margin-top: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }
        
        .back-btn:hover {
            background: rgba(107, 114, 128, 1);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        
        .status {
            margin: 20px 0;
            padding: 15px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .status.success {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
        
        .status.error {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .status.info {
            background: rgba(23, 72, 113, 0.2);
            color: #174871;
            border: 1px solid rgba(23, 72, 113, 0.3);
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 30px 20px;
                margin: 10px;
            }
            
            h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>QR Scanner</h1>
        
        <div class="scanner-container">
            <div id="qr-reader"></div>
        </div>
        
        <div class="instructions">
            <p><strong>Instructions:</strong></p>
            <p>1. Allow camera access when prompted</p>
            <p>2. Point your camera at a QR code</p>
            <p>3. The scanner will automatically detect and process the code</p>
        </div>
        
        <div id="status"></div>
        
        <a href="javascript:history.back()" class="back-btn">
            <span>←</span> Back
        </a>
    </div>

    <!-- HTML5 QR Code Scanner -->
    <?php /* window.TWCamera - this page has no header include of its own, so
             the camera diagnosis is pulled in directly. Must come before the
             script below, which asks it what is wrong. */ ?>
    <?php include_once __DIR__ . '/../shared/includes/camera-support.php'; ?>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        let html5QrcodeScanner = null;
        
        function onScanSuccess(decodedText, decodedResult) {
            console.log(`Code scanned = ${decodedText}`, decodedResult);
            
            // Show success status
            const statusDiv = document.getElementById('status');
            statusDiv.innerHTML = '<div class="status success">✅ QR Code detected! Redirecting...</div>';
            
            // Check if the decoded text is a URL
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                // Redirect to the scanned URL
                setTimeout(() => {
                    window.location.href = decodedText;
                }, 1000);
            } else {
                // Try to construct URL from the decoded text
                const baseUrl = window.location.origin + window.location.pathname.replace('scanner.php', '');
                const targetUrl = baseUrl + 'order_details.php?order_id=' + decodedText;
                
                setTimeout(() => {
                    window.location.href = targetUrl;
                }, 1000);
            }
        }
        
        function onScanFailure(error) {
            // Handle scan failure, usually better to ignore
            console.log(`QR error = ${error}`);
        }
        
        function startScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
            }
            
            html5QrcodeScanner = new Html5QrcodeScanner(
                "qr-reader",
                { 
                    fps: 10, 
                    qrbox: { width: 250, height: 250 },
                    aspectRatio: 1.0
                },
                false
            );
            
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }
        
        // Start scanner when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const statusDiv = document.getElementById('status');
            statusDiv.innerHTML = '<div class="status info">📷 Initializing camera...</div>';
            
            // "Not supported on this device" was wrong in the usual case and
            // sent people looking for a phone setting. Over plain http on a LAN
            // address the API is absent by browser rule; TWCamera says which of
            // the two reasons applies and offers the https URL that works.
            var camIssue = window.TWCamera ? window.TWCamera.problem() : null;
            if (!camIssue && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                startScanner();
            } else {
                var box = document.createElement('div');
                box.className = 'status error';
                if (window.TWCamera) { window.TWCamera.render(box); }
                else { box.textContent = 'Camera not available on this connection.'; }
                statusDiv.textContent = '';
                statusDiv.appendChild(box);
            }
        });
        
        // Clean up when page unloads
        window.addEventListener('beforeunload', function() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
            }
        });
    </script>
</body>
</html>
