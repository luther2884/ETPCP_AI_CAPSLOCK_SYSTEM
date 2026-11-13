<?php
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$userId = getUserId();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/orders.php');
    exit;
}

try {
    $pdo = getConnection();
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $st->execute([$orderId, $userId]);
    $order = $st->fetch();
    if (!$order) { header('Location: orders.php'); exit; }

    // Load order items (for quick inline list)
    $itemsStmt = $pdo->prepare("SELECT oi.*, p.name, p.image_url FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll();
} catch (Throwable $e) { header('Location: orders.php'); exit; }

$pageTitle = 'Onsite Checkout - Order #' . ($order['invoice_no'] ?: str_pad($order['id'],6,'0',STR_PAD_LEFT));
// Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js is
// deferred and would run a frame after first paint, which is exactly the
// flash this prevents. Resolution order is localStorage -> OS preference ->
// dark. There is no server-rendered preference to fall back on yet - `users`
// has no theme column; see the TODO in theme.js.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<section class="confirmation-page">
  <div class="container" style="position: relative;">
    <div class="confirmation-header">
      <h1>Order QR Code</h1>
      <p>Order #<?php echo htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'],6,'0',STR_PAD_LEFT)); ?> • <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></p>
    </div>

    <div class="order-details-card" style="text-align:center;">
      <div style="background:var(--bg-card-alpha-80);border:1px solid var(--border-color);border-radius:12px;padding:16px;margin-bottom:16px;">
        <div style="color:var(--text-muted);font-size:14px;margin-bottom:6px;">Order #<?php echo htmlspecialchars($order['invoice_no'] ?: str_pad($order['id'],6,'0',STR_PAD_LEFT)); ?></div>
        <div style="color:#22c55e;font-weight:700;font-size:20px;letter-spacing:2px;"><?php echo htmlspecialchars($order['pickup_code'] ?: ($order['tracking_code'] ?: '')); ?></div>
        <div style="color:var(--text-muted);font-size:11px;margin-top:6px;text-transform:uppercase;"><?php echo strtoupper($order['status'] ?? 'preparing'); ?></div>
      </div>

      <div class="qr-code-container" style="display:flex;justify-content:center;margin:12px 0 16px 0;">
        <div id="onsite-qrcode" style="width:250px;height:250px;background:#fff;border-radius:8px;display:flex;align-items:center;justify-content:center;">
          <span style="color:#666;font-size:12px;">Loading QR...</span>
        </div>
      </div>

      <div style="color:var(--text-muted);font-size:12px;margin-top:8px;">
        <strong>For Store Staff:</strong> Scan this QR to view order details.<br>
        <strong>For Customer:</strong> Show this QR at the counter.
      </div>

      <?php
        // Expiry: enforce/show 1 hour window for onsite
        $expiresAt = $order['payment_code_expires_at'] ?: date('Y-m-d H:i:s', strtotime('+60 minutes'));
      ?>
      <div class="countdown" id="countdown" style="text-align:center;color:#f59e0b;font-weight:600;font-size:14px;margin-top:10px;">
        Expires in: <span id="timeLeft"></span>
      </div>
    </div>

    <div class="order-items-section" style="margin-top:16px;">
      <h3 style="color:var(--text-primary);font-size:16px;margin-bottom:10px;">Order Items</h3>
      <div style="background:var(--bg-card-alpha-80);border-radius:12px;padding:16px;border:1px solid var(--border-color);">
        <?php foreach ($orderItems as $item): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.08);">
          <div style="color:var(--text-primary);flex:1;"><?= htmlspecialchars($item['name']) ?></div>
          <div style="color:var(--text-muted);">x<?= (int)$item['quantity'] ?></div>
          <div style="color:var(--accent-cyan);font-weight:600;"><?= formatCurrency($item['price'] * $item['quantity']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="display:flex;gap:12px;justify-content:center;margin-top:16px;">
      <a href="<?php echo WEB_ROOT ?>public/authenticated/customer/orders.php" class="btn-primary" style="background:var(--bg-card-hover);color:var(--text-primary);padding:12px 20px;border-radius:8px;text-decoration:none;border:1px solid var(--border-color);">View My Orders</a>
      <a href="<?php echo WEB_ROOT ?>public/authenticated/customer/payment_qr.php?order_id=<?php echo (int)$orderId; ?>" class="btn-primary" style="background:#174871;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;border:1px solid #174871;">Payment QR</a>
    </div>
  </div>
</section>

<script src="<?php echo WEB_ROOT; ?>davidshimjs-qrcodejs-04f46c6/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var el = document.getElementById('onsite-qrcode');
  if (!el) return;
  // Encode guest order details URL for staff scanning on LAN
  var orderUrl = 'http://10.32.134.104/TECHWRLD_V2/public/guest/order_details_fixed.php?order_id=<?php echo (int)$orderId; ?>';
  el.innerHTML = '';
  new QRCode(el, { text: orderUrl, width: 250, height: 250, colorDark: '#000', colorLight: '#fff', correctLevel: QRCode.CorrectLevel.M });

  // Countdown to expiry (1 hour window)
  var expiryTime = new Date('<?php echo $expiresAt; ?>').getTime();
  function tick(){
    var now = Date.now();
    var left = expiryTime - now;
    var out = document.getElementById('timeLeft');
    if (!out) return;
    if (left <= 0) { out.textContent = 'EXPIRED'; return; }
    var h = Math.floor(left / (1000*60*60));
    var m = Math.floor((left % (1000*60*60)) / (1000*60));
    var s = Math.floor((left % (1000*60)) / 1000);
    out.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    setTimeout(tick, 1000);
  }
  tick();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


