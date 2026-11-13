<?php
// customer/epayment_instructions.php - Payment instructions for GCash/Maya
// e-payment checkout orders. No live payment gateway is integrated yet, so
// this collects a customer-submitted transaction reference for staff to
// manually verify - the same maturity level as the existing onsite/pickup
// QR flow in payment_qr.php.
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$orderId = intval($_GET['id'] ?? $_POST['order_id'] ?? 0);
$userId = getUserId();

if (!$orderId) {
    header('Location: orders.php');
    exit;
}

$pdo = getConnection();

// Declared above the POST handler, which needs it too - the GET path below
// only re-reads it.
$channelNames = ['gcash' => 'GCash', 'paymaya' => 'Maya'];

/**
 * Accepted shape of a transaction reference, per payment channel.
 *
 * The field used to take any string up to 100 characters, which is not a
 * reference number - it is a text box. Staff match these against the
 * merchant app by eye, so a value that cannot possibly be a reference is
 * wasted time on both sides, and a truncated or padded one is worse: it looks
 * matchable and is not.
 *
 * GCash prints a 13-digit Ref. No. Maya's is alphanumeric and varies in
 * length, so it gets a band rather than a fixed count.
 *
 * If a real receipt ever shows a length outside these, widen the numbers
 * HERE - the form, the live counter and the server check all read this one
 * array.
 */
$refFormats = [
    'gcash' => [
        'digits_only' => true,
        'min'         => 13,
        'max'         => 13,
        'group'       => 4,                       // display only: 1234 5678 9012 3
        'placeholder' => '1234 5678 9012 3',
        'hint'        => 'The 13-digit Ref. No. on your GCash receipt.',
        'label'       => '13 digits',
    ],
    'paymaya' => [
        'digits_only' => false,
        'min'         => 10,
        'max'         => 20,
        'group'       => 0,
        'placeholder' => 'e.g. A1B2C3D4E5F6',
        'hint'        => 'The reference number on your Maya receipt (10-20 characters).',
        'label'       => '10-20 characters',
    ],
];
/** Spec for one channel, with a permissive fallback for anything unlisted. */
function refFormatFor(?string $method): array {
    global $refFormats;
    return $refFormats[$method ?? ''] ?? [
        'digits_only' => false, 'min' => 4, 'max' => 40, 'group' => 0,
        'placeholder' => 'Transaction reference', 'hint' => 'The reference number on your receipt.',
        'label' => '4-40 characters',
    ];
}

/**
 * Reduces a submitted reference to what actually gets stored and compared.
 * Spaces and dashes are how receipts print these, not part of the value - and
 * leaving them in would let "1234 5678 9012 3" and "1234567890123" both be
 * saved as different references for the same payment.
 */
function normalizeTxnRef(string $raw, array $fmt): string {
    $v = strtoupper(trim($raw));
    $v = preg_replace('/[\s\-]+/', '', $v);
    return $fmt['digits_only'] ? preg_replace('/\D/', '', $v) : preg_replace('/[^A-Z0-9]/', '', $v);
}

// Handle the customer submitting their GCash/Maya transaction reference number
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!check_csrf(false)) { echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }

    try {
        // The channel comes from the order, not from the request - the rule
        // applied has to be the one for the method actually being paid.
        // Fetched before validating for that reason.
        $st = $pdo->prepare("
            SELECT p.id AS payment_id, o.payment_method
            FROM orders o
            JOIN payments p ON p.order_id = o.id
            WHERE o.id = ? AND o.user_id = ? AND o.payment_method IN ('gcash','paymaya')
            LIMIT 1
        ");
        $st->execute([$orderId, $userId]);
        $row = $st->fetch();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit;
        }

        $postFormat  = refFormatFor($row['payment_method']);
        $postChannel = $channelNames[$row['payment_method']] ?? 'E-Payment';

        // Normalised first: the browser shows the GCash reference in groups of
        // four, and those spaces must not reach the database.
        $txnRef = normalizeTxnRef((string)($_POST['transaction_ref'] ?? ''), $postFormat);
        $refLen = strlen($txnRef);
        $unit   = $postFormat['digits_only'] ? ' digits' : ' characters';
        if ($refLen < $postFormat['min'] || $refLen > $postFormat['max']) {
            $expected = $postFormat['min'] === $postFormat['max']
                ? ('exactly ' . $postFormat['min'] . $unit)
                : ($postFormat['min'] . '-' . $postFormat['max'] . $unit);
            echo json_encode([
                'success' => false,
                'message' => 'Please enter a valid reference number - ' . $postChannel . ' uses ' . $expected . '.',
            ]);
            exit;
        }
        if ($postFormat['digits_only'] && !ctype_digit($txnRef)) {
            echo json_encode(['success' => false, 'message' => $postChannel . ' reference numbers are digits only.']);
            exit;
        }

        $upd = $pdo->prepare("UPDATE payments SET transaction_id = ?, payment_status = 'processing' WHERE id = ?");
        $upd->execute([$txnRef, $row['payment_id']]);

        echo json_encode(['success' => true, 'message' => 'Thanks! We will verify your payment shortly.']);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000 || stripos($e->getMessage(), 'duplicate') !== false) {
            echo json_encode(['success' => false, 'message' => 'This transaction reference has already been submitted for another order.']);
        } else {
            error_log('epayment_instructions.php update error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Could not save your reference. Please try again.']);
        }
    }
    exit;
}

// GET: load the order and show payment instructions
try {
    $stmt = $pdo->prepare("
        SELECT o.*, p.id AS payment_id, p.transaction_id, p.payment_status AS payment_record_status
        FROM orders o
        LEFT JOIN payments p ON p.order_id = o.id
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        header('Location: orders.php?error=order_not_found');
        exit;
    }

    if (!in_array($order['payment_method'], ['gcash', 'paymaya'], true)) {
        header('Location: order_success.php?id=' . $orderId);
        exit;
    }
} catch (PDOException $e) {
    error_log('Error fetching order: ' . $e->getMessage());
    header('Location: orders.php?error=database_error');
    exit;
}

$channelName = $channelNames[$order['payment_method']] ?? 'E-Payment';
$refFormat = refFormatFor($order['payment_method'] ?? null);

// Store's receiving account per channel - no live gateway integrated yet, so
// this is the merchant's own account details for the customer to send to.
$merchantAccounts = [
    'gcash'   => ['name' => 'TechWorld Store', 'number' => '0917-123-4567'],
    'paymaya' => ['name' => 'TechWorld Store', 'number' => '0917-123-4567'],
];
$merchant = $merchantAccounts[$order['payment_method']] ?? $merchantAccounts['gcash'];

$alreadySubmitted = !empty($order['transaction_id']);
$isVerified = ($order['payment_record_status'] ?? '') === 'completed';

$pageTitle = 'Complete Payment - Order #' . $order['invoice_no'];
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

<!-- E-Payment Instructions Page -->
<section class="payment-qr-page">
    <div class="container">
        <div class="payment-qr-container">
            <div class="payment-qr-card">
                <div class="payment-header">
                    <h1><i class="fi fi-rr-mobile"></i> Complete Your <?= htmlspecialchars($channelName) ?> Payment</h1>
                    <p class="order-info">Order #<?= htmlspecialchars($order['invoice_no']) ?></p>
                </div>

                <div class="payment-details">
                    <div class="amount-section">
                        <h3>Amount to Pay</h3>
                        <div class="amount-display"><?= formatCurrency($order['total_amount']) ?></div>
                    </div>
                </div>

                <?php if ($isVerified): ?>
                    <div class="payment-status paid">
                        <div class="status-icon"><i class="fi fi-rr-check-circle"></i></div>
                        <h3>Payment Verified</h3>
                        <p>We've confirmed your <?= htmlspecialchars($channelName) ?> payment. Your order is being prepared.</p>
                    </div>
                <?php else: ?>
                    <div class="payment-qr-section">
                        <h3>Scan to Pay via <?= htmlspecialchars($channelName) ?></h3>
                        <div class="qr-code-container">
                            <div id="payment-qrcode" style="width:250px;height:250px;border:1px solid #ccc;display:flex;align-items:center;justify-content:center;color:#666;background:white;border-radius:8px;margin:0 auto;">
                                <span>Loading QR Code...</span>
                            </div>
                        </div>

                        <div class="payment-code-manual">
                            <p>Send payment to:</p>
                            <div style="color:var(--text-primary);font-size:16px;margin-bottom:14px;">
                                <strong><?= htmlspecialchars($merchant['name']) ?></strong><br>
                                <?= htmlspecialchars($channelName) ?>: <?= htmlspecialchars($merchant['number']) ?>
                            </div>
                            <p>Include this reference in your payment note:</p>
                            <div class="payment-code"><?= htmlspecialchars($order['payment_reference'] ?? $order['invoice_no']) ?></div>
                        </div>
                    </div>

                    <div class="payment-instructions">
                        <h4>Payment Instructions:</h4>
                        <ol>
                            <li>Open your <?= htmlspecialchars($channelName) ?> app and scan the QR code above, or send manually to the number shown</li>
                            <li>Send exactly <strong><?= formatCurrency($order['total_amount']) ?></strong></li>
                            <li>Add the reference code above to the payment note/message</li>
                            <li>Enter the transaction reference number <?= htmlspecialchars($channelName) ?> gives you below</li>
                        </ol>
                    </div>

                    <div class="payment-validity" style="text-align:left;">
                        <?php if ($alreadySubmitted): ?>
                            <p style="color:var(--text-primary);margin:0 0 8px;">
                                <i class="fi fi-rr-clock" style="color:#f59e0b;"></i>
                                Reference <strong><?= htmlspecialchars($order['transaction_id']) ?></strong> submitted - awaiting verification by our staff.
                            </p>
                            <p style="color:var(--text-muted);font-size:13px;margin:0;">Submitted the wrong number? You can correct it below.</p>
                        <?php endif; ?>
                        <form id="epayReferenceForm" style="margin-top:12px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                            <label for="epayTransactionRef" style="display:block;color:var(--text-primary);font-size:14px;font-weight:600;margin-bottom:8px;">
                                <?= htmlspecialchars($channelName) ?> Transaction Reference Number
                            </label>
                            <?php
                            // maxlength counts the display spaces too, so the
                            // cap is the value length plus however many
                            // separators the grouping adds.
                            $refMaxDisplay = $refFormat['max'] + ($refFormat['group'] > 0
                                ? intdiv($refFormat['max'] - 1, $refFormat['group'])
                                : 0);
                            ?>
                            <input type="text" id="epayTransactionRef" name="transaction_ref"
                                   value="<?= htmlspecialchars($order['transaction_id'] ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($refFormat['placeholder']) ?>"
                                   maxlength="<?= (int)$refMaxDisplay ?>"
                                   <?= $refFormat['digits_only'] ? 'inputmode="numeric"' : '' ?>
                                   autocomplete="off" autocapitalize="characters" spellcheck="false"
                                   aria-describedby="epayRefHint"
                                   data-digits-only="<?= $refFormat['digits_only'] ? '1' : '0' ?>"
                                   data-min="<?= (int)$refFormat['min'] ?>"
                                   data-max="<?= (int)$refFormat['max'] ?>"
                                   data-group="<?= (int)$refFormat['group'] ?>"
                                   required
                                   style="width:100%;padding:12px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);color:var(--text-primary);font-size:15px;margin-bottom:6px;letter-spacing:.06em;">
                            <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:12px;">
                              <span id="epayRefHint" style="color:var(--text-muted);font-size:12.5px;line-height:1.45;"><?= htmlspecialchars($refFormat['hint']) ?></span>
                              <span id="epayRefCount" style="color:var(--text-muted);font-size:12.5px;white-space:nowrap;font-variant-numeric:tabular-nums;"></span>
                            </div>
                            <button type="submit" class="action-btn share-btn" style="width:100%;">
                                <i class="fi fi-rr-paper-plane"></i>
                                <?= $alreadySubmitted ? 'Update Reference Number' : 'Submit Reference Number' ?>
                            </button>
                        </form>
                        <p id="epayReferenceStatus" role="status" aria-live="polite" style="margin:10px 0 0;font-size:14px;"></p>
                    </div>
                <?php endif; ?>

                <div class="payment-actions">
                    <a href="orders.php" class="action-btn secondary-btn">
                        <i class="fi fi-rs-list"></i>
                        View All Orders
                    </a>
                    <a href="order_success.php?id=<?= (int)$order['id'] ?>" class="action-btn secondary-btn">
                        <i class="fi fi-rr-receipt"></i>
                        Order Details
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.payment-qr-page { padding: 40px 0; background: var(--page-bg-gradient); min-height: 100vh; }
.payment-qr-container { max-width: 600px; margin: 0 auto; padding: 0 20px; }
.payment-qr-card { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 40px; text-align: center; }
.payment-header h1 { color: var(--text-primary); font-size: 26px; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; gap: 12px; }
.payment-header .order-info { color: var(--text-muted); font-size: 16px; margin-bottom: 30px; }
.payment-details { margin-bottom: 30px; }
.amount-section h3 { color: var(--text-muted); font-size: 16px; margin-bottom: 10px; }
.amount-display { font-size: 36px; font-weight: 700; color: var(--accent-cyan); text-shadow: 0 0 20px var(--accent-cyan-bg-30); }
.payment-status { padding: 40px; border-radius: 12px; margin-bottom: 30px; }
.payment-status.paid { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); }
.status-icon { font-size: 48px; margin-bottom: 20px; }
.payment-status.paid .status-icon { color: #10b981; }
.payment-status h3 { color: var(--text-primary); font-size: 24px; margin-bottom: 10px; }
.payment-status p { color: var(--text-muted); font-size: 16px; margin-bottom: 0; }
.payment-qr-section { margin-bottom: 30px; }
.payment-qr-section h3 { color: var(--text-primary); font-size: 20px; margin-bottom: 20px; }
.qr-code-container { display: flex; justify-content: center; margin-bottom: 20px; }
.payment-code-manual { margin-bottom: 20px; text-align: center; }
.payment-code-manual p { color: var(--text-muted); font-size: 14px; margin-bottom: 10px; }
.payment-code { font-family: 'Courier New', monospace; font-size: 22px; font-weight: 700; color: var(--accent-cyan); background: var(--accent-cyan-bg); border: 2px solid var(--accent-cyan-bg-30); border-radius: 8px; padding: 12px 18px; letter-spacing: 2px; display: inline-block; }
.payment-validity { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.payment-instructions { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 12px; padding: 20px; margin-bottom: 30px; text-align: left; }
.payment-instructions h4 { color: var(--text-primary); font-size: 16px; margin-bottom: 15px; }
.payment-instructions ol { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0; padding-left: 20px; }
.payment-instructions li { margin-bottom: 8px; }
.payment-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.action-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s ease; border: none; cursor: pointer; }
.action-btn.secondary-btn { background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--text-primary); }
.action-btn.secondary-btn:hover { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.25); }
.action-btn.share-btn { background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue)); color: #fff; }
.action-btn.share-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3); }
@media (max-width: 768px) {
    .payment-qr-container { margin: 0 20px; }
    .payment-qr-card { padding: 30px 20px; }
    .amount-display { font-size: 28px; }
    .payment-code { font-size: 18px; padding: 10px 14px; }
    .payment-actions { grid-template-columns: 1fr; gap: 10px; }
}
</style>

<?php if (!$isVerified): ?>
<!-- Load QR Code Library for JavaScript generation (same library used by payment_qr.php) -->
<script src="<?= WEB_ROOT ?>davidshimjs-qrcodejs-04f46c6/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const qrCodeElement = document.getElementById('payment-qrcode');
    if (qrCodeElement) {
        // ASCII amount, deliberately. formatCurrency() prefixes a peso sign
        // (U+20B1), and this QR library picks its QR version from the CHARACTER
        // count while the encoder writes UTF-8 BYTES - so one 3-byte character
        // made it choose a version too small and throw "code length overflow".
        // The throw landed after the innerHTML='' below, which is why the box
        // rendered empty. The amount is scanned as plain text, so "PHP" reads
        // the same and encodes cleanly.
        const qrData = 'Pay <?= addslashes($channelName) ?>: <?= addslashes($merchant['number']) ?> (<?= addslashes($merchant['name']) ?>)\nAmount: PHP <?= addslashes(number_format((float)$order['total_amount'], 2)) ?>\nRef: <?= addslashes($order['payment_reference'] ?? $order['invoice_no']) ?>';
        qrCodeElement.innerHTML = '';
        new QRCode(qrCodeElement, {
            text: qrData,
            width: 250,
            height: 250,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    // --- Reference number field ------------------------------------------
    // Enforces the same rule the server does (see $refFormats at the top of
    // this file), read off the input's data-* attributes so there is one
    // source of truth rather than a copy of the numbers here.
    const refInput = document.getElementById('epayTransactionRef');
    const refCount = document.getElementById('epayRefCount');
    if (refInput) {
        const digitsOnly = refInput.dataset.digitsOnly === '1';
        const minLen = parseInt(refInput.dataset.min, 10) || 0;
        const maxLen = parseInt(refInput.dataset.max, 10) || 40;
        const group  = parseInt(refInput.dataset.group, 10) || 0;

        // The stored value: no spaces, no dashes, uppercase.
        function normalizeRef(v) {
            const up = String(v || '').toUpperCase();
            return (digitsOnly ? up.replace(/\D/g, '') : up.replace(/[^A-Z0-9]/g, '')).slice(0, maxLen);
        }
        // What the field shows. Grouping is readability only - a 13-digit run
        // is very hard to check against a receipt by eye.
        function formatRef(clean) {
            if (!group) { return clean; }
            return clean.replace(new RegExp('(.{' + group + '})', 'g'), '$1 ').trim();
        }

        function refIsValid() { return normalizeRef(refInput.value).length >= minLen; }

        function paintRef() {
            const clean = normalizeRef(refInput.value);
            const ok = clean.length >= minLen;
            refCount.textContent = clean.length + '/' + maxLen;
            refCount.style.color = ok ? '#34d399' : 'var(--text-muted)';
            refInput.style.borderColor = clean.length === 0
                ? 'rgba(255,255,255,.15)'
                : (ok ? 'rgba(52,211,153,.55)' : 'rgba(248,113,113,.55)');
        }

        refInput.addEventListener('input', function () {
            // Re-formatting moves the caret, so anchor it: count the value
            // characters before the caret, then put it back after that many.
            const before = normalizeRef(refInput.value.slice(0, refInput.selectionStart)).length;
            const clean = normalizeRef(refInput.value);
            refInput.value = formatRef(clean);
            if (document.activeElement === refInput) {
                let pos = 0, seen = 0;
                while (pos < refInput.value.length && seen < before) {
                    if (/[A-Z0-9]/i.test(refInput.value[pos])) { seen++; }
                    pos++;
                }
                refInput.setSelectionRange(pos, pos);
            }
            paintRef();
        });

        // Receipts get copied with stray spaces and line breaks; normalise
        // rather than rejecting a paste that is really correct.
        refInput.addEventListener('paste', function () {
            setTimeout(function () { refInput.dispatchEvent(new Event('input')); }, 0);
        });

        refInput.value = formatRef(normalizeRef(refInput.value));
        paintRef();
        window.__epayRefIsValid = refIsValid;
        window.__epayRefClean = function () { return normalizeRef(refInput.value); };
    }

    const form = document.getElementById('epayReferenceForm');
    const statusEl = document.getElementById('epayReferenceStatus');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');

            // Caught here as well as on the server: a round trip to be told
            // the number is too short is a worse way to learn it.
            if (window.__epayRefIsValid && !window.__epayRefIsValid()) {
                statusEl.textContent = 'Please enter the complete reference number from your receipt.';
                statusEl.style.color = '#f87171';
                const ri = document.getElementById('epayTransactionRef');
                if (ri) { ri.focus(); }
                return;
            }

            btn.disabled = true;
            statusEl.textContent = 'Submitting...';
            statusEl.style.color = '#c9a3b5';

            const body = new FormData(form);
            // Send the value without its display grouping, so what is stored
            // matches what staff read off the merchant app.
            if (window.__epayRefClean) { body.set('transaction_ref', window.__epayRefClean()); }

            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    statusEl.textContent = d.message || 'Reference submitted.';
                    statusEl.style.color = '#34d399';
                    btn.textContent = 'Update Reference Number';
                } else {
                    statusEl.textContent = d.message || 'Could not submit reference.';
                    statusEl.style.color = '#f87171';
                }
            })
            .catch(() => {
                statusEl.textContent = 'Network error. Please try again.';
                statusEl.style.color = '#f87171';
            })
            .finally(() => { btn.disabled = false; });
        });
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
