

<?php
// customer/checkout.php - Checkout page
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Checkout - TechWorld';
$userId = getUserId();
// Store credit is spendable value the customer already owns (issued when
// they previously cancelled a paid order and chose credit over a cash
// refund) - applying it here reduces what they need to pay through their
// chosen payment method, same as a gift card would.
$storeCreditBalance = getStoreCreditBalance($userId);
// Prefill user details - optimized for immediate display
$user = getUserData();
$fullName = '';
if ($user) {
    $first = trim($user['firstname'] ?? '');
    $last  = trim($user['lastname'] ?? '');
    $fullName = trim($first . ' ' . $last);
}

// Get default address from user_addresses table
$defaultAddressName = $fullName ?: 'Your name';
$defaultAddressPhone = $user['phone'] ?? '';
// Empty string, NOT the prompt. This used to default to the literal
// "Enter your delivery address", which applyAddress() then wrote into the
// hidden #address field - so a first-time customer with no saved address
// passed the "address is required" check and could place a home-delivery
// order whose address line was the placeholder itself. The prompt is now
// display text only, never a value.
$defaultAddressText = $user['address'] ?? '';

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1");
    $stmt->execute([$userId]);
    $defaultAddress = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($defaultAddress) {
        $defaultAddressName = $defaultAddress['full_name'];
        $defaultAddressPhone = $defaultAddress['phone'];
        $defaultAddressText = $defaultAddress['street'] . ', ' . $defaultAddress['region'] . ($defaultAddress['postal'] ? ', ' . $defaultAddress['postal'] : '');
    }
} catch (Exception $e) {
    // Fall back to user table address if user_addresses query fails
    error_log('Checkout address loading error: ' . $e->getMessage());
}

// Get cart items from session
$cartItems = [];
$cartTotal = 0;

// Parse selected IDs from query (comma-separated integers)
$selectedIds = [];
if (!empty($_GET['selected'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', $_GET['selected'])));
    $selectedIds = array_values(array_unique($selectedIds));
}

// Initialize cart if it doesn't exist
if (!isset($_SESSION['cart'][$userId])) {
    $_SESSION['cart'][$userId] = [];
}

try {
    $pdo = getConnection();
    $cartSession = $_SESSION['cart'][$userId];
    
    // If selection provided, add selected products to cart if not already there
    if (!empty($selectedIds)) {
        foreach ($selectedIds as $productId) {
            if (!isset($cartSession[$productId])) {
                // Add product to cart with quantity 1
                $_SESSION['cart'][$userId][$productId] = 1;
            }
        }
        $productIds = $selectedIds;
    } else {
        // If no selection, use all cart items
        $productIds = array_keys($cartSession);
    }
    
    // Update cart session after potential additions
    $cartSession = $_SESSION['cart'][$userId];
    
    if (empty($productIds)) {
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
        exit;
    }
    
    // Fetch products from database
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    if (empty($placeholders)) {
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, name, price, image_url, stock FROM products WHERE id IN ($placeholders) AND status = 'active'");
    $stmt->execute($productIds);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build cart items array
    foreach ($products as $product) {
        $quantity = $cartSession[$product['id']];
        $cartItems[] = [
            'id' => $product['id'],
            'product_id' => $product['id'],
            'name' => $product['name'],
            'price' => $product['price'],
            'image_url' => $product['image_url'],
            'stock' => $product['stock'],
            'quantity' => $quantity
        ];
        $cartTotal += $product['price'] * $quantity;
    }
    
    if (empty($cartItems)) {
        header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
        exit;
    }
    
} catch (PDOException $e) {
    error_log('Error loading cart: ' . $e->getMessage());
    header('Location: ' . WEB_ROOT . 'public/authenticated/customer/cart.php');
    exit;
}

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
<?php /* class="loading" was on a second, stray <body> further down this file -
         left over from when the page had no <head> and that orphan tag was the
         only body element it had. Now that there is a real one, the class moves
         here so the JS that removes it (further down, on first paint) still
         finds it on document.body. */ ?>
<body class="loading">
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?php /* The page's own CSS, above the markup it styles.

        It used to sit at the bottom of <body>, after the content. On a page
        this size that is not a cosmetic detail: the rules arrived 179KB after
        the elements they size, and Chrome paints each one as it parses it, so
        the page showed a frame of unstyled markup - default form controls,
        full-size images, link-blue text - before snapping into place. The
        shared sheets were already in by then, which is why only this page's
        own furniture looked wrong.

        It stays in <body> rather than moving to <head> on purpose:
        theme-light.css is linked from sidebar.php, and rules below rely on
        landing after it. Sitting here keeps that order exactly as it was. */ ?>
<style>
.checkout-page {
  padding: 40px 0;
  background: var(--page-bg-gradient);
  min-height: 100vh;
  opacity: 1;
  transition: opacity 0.3s ease;
}

.checkout-page.loading {
  opacity: 0.7;
}

/* Immediate display animations */
.checkout-header {
  animation: fadeInUp 0.4s ease-out;
}

.checkout-layout {
  animation: fadeInUp 0.6s ease-out;
}

.order-summary {
  animation: fadeInUp 0.8s ease-out;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Skeleton loading states */
.skeleton {
  background: linear-gradient(90deg, var(--border-color) 25%, rgba(255,255,255,0.2) 50%, var(--border-color) 75%);
  background-size: 200% 100%;
  animation: loading 1.5s infinite;
}

@keyframes loading {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

.skeleton-text {
  height: 16px;
  border-radius: 4px;
  margin-bottom: 8px;
}

.skeleton-text.short {
  width: 60%;
}

.skeleton-text.medium {
  width: 80%;
}

.skeleton-text.long {
  width: 100%;
}

.skeleton-image {
  width: 60px;
  height: 60px;
  border-radius: 8px;
}

/* Immediate display for critical elements */
.delivery-address-display,
.service-options,
.order-summary {
  will-change: transform;
  transform: translateZ(0);
}

/* ===========================================================================
   Checkout chrome.

   One local token block drives the whole page, re-declared under
   html[data-mode="light"]. That is the only way the two modes stay in step:
   the dark values are literal rgba washes over navy, and a light mode built
   by overriding individual rules instead of the tokens drifts the moment one
   rule is added. Nothing here leaks outside .checkout-page, so dark mode
   elsewhere is untouched.

   Contrast: every ink value below is one of theme-light.css's own tokens,
   which carry measured ratios (--tw-ink 14.8:1, --tw-ink-dim 7.6:1,
   --tw-ink-faint 4.88:1 worst case in light). The two literals that are not
   tokens - the eyebrow/total pink and the step rail - are set per mode and
   measured in the verification script.
   ======================================================================== */
.checkout-page{
  /* surfaces */
  --co-card:       linear-gradient(180deg, rgba(20,36,60,.82), rgba(11,22,38,.9));
  --co-card-line:  rgba(120,170,230,.16);
  --co-inset:      rgba(120,170,230,.07);
  /* The brief asked for rgba(120,170,230,.18) here. That measures 1.37:1 on
     this card - and on a tile the border and the radio dot ARE the control,
     with nothing else to say an unpicked option is pickable, which is the
     3:1 WCAG 1.4.11 asks for. theme-light.css already carries the answer and
     the reasoning: --tw-line-ctl is "the EDGE OF A CONTROL, as opposed to
     --tw-line, which is the edge of a card", set to the shallowest value that
     clears 3:1 on the worst ground. The .18 wash is kept where it IS a card
     edge - see --co-card-line above. */
  --co-tile-line:  var(--tw-line-ctl, rgba(120,170,230,.55));
  --co-sel-bg:     rgba(224,86,122,.10);
  --co-sel-line:   #e0567a;
  --co-thumb:      rgba(236,242,250,.92);
  /* ink */
  --co-ink:        var(--tw-ink, #e8eef7);
  --co-ink-strong: var(--tw-ink-strong, #ffffff);
  --co-ink-dim:    var(--tw-ink-dim, #a9bcd4);
  --co-ink-faint:  var(--tw-ink-faint, #7f95b3);
  /* accents */
  --co-hot:        #ff9ab5;
  --co-grad:       linear-gradient(90deg, #ff9ab5, #e0567a, #67e8f9);
  /* The pink stop is #d9355f, not the #e0567a used everywhere else on this
     page. White on #e0567a measures 3.63:1 and the label is 14.5px/800 -
     under 18.66px bold, so the 3:1 large-text allowance does not apply and
     4.5 does. #d9355f is the same hue and saturation walked down 7% in
     lightness, the shallowest step that clears it (4.54:1). The violet stop
     already passed at 5.36:1. */
  --co-btn:        linear-gradient(135deg, #d9355f, #6b5bc4);
  --co-ok:         #4ade80;
  --co-focus:      #67e8f9;
  --co-chip-bg:    rgba(120,170,230,.12);
  --co-vat-bg:     rgba(103,232,249,.05);
  --co-vat-line:   rgba(103,232,249,.18);

  padding: 32px 0 56px;
  background: var(--page-bg-gradient);
  min-height: 100vh;
  opacity: 1;
  transition: opacity 0.3s ease;
  color: var(--co-ink);
}
html[data-mode="light"] .checkout-page{
  --co-card:       linear-gradient(180deg, #ffffff, #f8fafd);
  --co-card-line:  #dbe3ee;
  --co-inset:      #f2f5fa;
  --co-tile-line:  var(--tw-line-ctl, #7e90a9);   /* 3.09:1, same reasoning */
  --co-sel-bg:     rgba(194,58,99,.06);
  --co-sel-line:   #c23a63;
  --co-thumb:      #f2f5fa;
  --co-hot:        #a8264d;   /* 6.6:1 on #f8fafd - the dark pink is 2.1:1 */
  --co-grad:       linear-gradient(90deg, #c23a63, #a8264d, #2f5d8f);
  --co-btn:        linear-gradient(135deg, #c23a63, #5a4bb0);
  --co-ok:         #0f6b4a;
  --co-focus:      #2f5d8f;
  --co-chip-bg:    #eef2f8;
  --co-vat-bg:     #f4f8fc;
  --co-vat-line:   #dbe7f2;
}

/* ---- masthead ---------------------------------------------------------- */
.checkout-header{
  max-width: 1200px;
  margin: 0 auto 28px;
  padding: 0 4px;
}
.header-navigation{ margin-bottom: 18px; }

.header-back-btn{
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: transparent;
  border: 1px solid var(--co-card-line);
  color: var(--co-ink-dim);
  text-decoration: none;
  padding: 8px 14px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  transition: border-color .2s ease, color .2s ease;
}
.header-back-btn:hover{
  border-color: var(--co-sel-line);
  color: var(--co-ink-strong);
  text-decoration: none;
}
.header-back-btn i{ font-size: 13px; }

.co-masthead{
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  flex-wrap: wrap;
}
.co-eyebrow{
  font-family: var(--tw-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  font-size: 11.5px;
  font-weight: 700;
  letter-spacing: .18em;
  text-transform: uppercase;
  color: var(--co-hot);
  margin: 0 0 6px;
}
.checkout-header h1{
  font-size: clamp(34px, 6vw, 48px);
  font-weight: 900;
  letter-spacing: -.03em;
  line-height: 1.02;
  color: var(--co-ink-strong);
  margin: 0;
}
/* Gradient on the second half of the word only. background-clip:text has no
   fallback colour of its own, so the slice keeps a real `color` underneath
   for engines that ignore it. */
.co-title-grad{
  color: var(--co-sel-line);
  background: var(--co-grad);
  -webkit-background-clip: text;
  background-clip: text;
}
@supports ((-webkit-background-clip: text) or (background-clip: text)){
  .co-title-grad{ color: transparent; }
}
.co-sub{
  color: var(--co-ink-dim);
  font-size: 14.5px;
  margin: 8px 0 0;
}

/* The step rail. Numbers and the tick are decorative; the state a screen
   reader gets is aria-current plus the visually-hidden " - done". */
.co-steps{
  display: flex;
  align-items: center;
  gap: 10px;
  list-style: none;
  margin: 0;
  padding: 0;
  flex-wrap: wrap;
}
.co-step{
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--co-ink-faint);
}
.co-step + .co-step::before{
  content: '';
  width: 18px;
  height: 1px;
  background: var(--co-tile-line);
  margin-right: 3px;
}
.co-step-dot{
  width: 21px;
  height: 21px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 10.5px;
  font-weight: 800;
  border: 1.5px solid var(--co-tile-line);
}
.co-step.is-done{ color: var(--co-ok); }
.co-step.is-done .co-step-dot{
  border-color: var(--co-ok);
  color: var(--co-ok);
  font-size: 9px;
}
.co-step.is-active{ color: var(--co-ink-strong); }
.co-step.is-active .co-step-dot{
  border-color: var(--co-sel-line);
  background: var(--co-sel-line);
  color: #fff;
}
.co-sr{
  position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}

/* ---- two columns ------------------------------------------------------- */
/* flex, not grid: the summary has to be able to drop below the form and go
   full width, which a fixed 400px grid track cannot do without a second
   breakpoint. min-width:0 on both is what stops a long product name forcing
   the page wider than the viewport at 360px. */
.checkout-layout{
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 22px;
  max-width: 1200px;
  margin: 0 auto;
}
.checkout-form{
  flex: 999 1 520px;
  min-width: 0;
  padding: 0;
  border-radius: 0;
  /* Drives the card numbers. A card with display:none generates no box and
     so does not increment this - which is exactly why the address card can
     come and go without leaving a hole in the sequence. */
  counter-reset: co-step;
}
.order-summary{
  flex: 1 1 320px;
  min-width: 0;
  position: sticky;
  top: 20px;
}

/* ---- cards ------------------------------------------------------------- */
.co-card{
  background: var(--co-card);
  border: 1px solid var(--co-card-line);
  border-radius: 18px;
  padding: 22px;
  box-shadow: none;
}
.form-section{ margin-bottom: 16px; }
.checkout-form .form-section:last-of-type{ margin-bottom: 16px; }

.co-card > h3,
.summary-card h3{
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 15px;
  font-weight: 800;
  letter-spacing: -.01em;
  color: var(--co-ink-strong);
  margin: 0 0 16px;
}
.co-card--num > h3::before{
  counter-increment: co-step;
  content: counter(co-step);
  flex: 0 0 auto;
  width: 23px;
  height: 23px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--co-chip-bg);
  border: 1px solid var(--co-card-line);
  color: var(--co-ink-dim);
  font-size: 11.5px;
  font-weight: 800;
}
.section-icon-gif{ width: 22px; height: 22px; object-fit: contain; flex-shrink: 0; }
.required-mark{ color: var(--co-sel-line); font-weight: 700; }
.co-optional{
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--co-ink-faint);
  background: var(--co-chip-bg);
  border-radius: 999px;
  padding: 2px 9px;
}
.co-field-label{
  font-family: var(--tw-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: var(--co-ink-faint);
  margin: 0 0 8px;
}

/* Kept from before: the red pulse on a required group the user tried to skip. */
@keyframes requiredFlash {
  0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
  15%, 55% { box-shadow: 0 0 0 4px rgba(239, 68, 68, .55); }
}
.required-flash{ animation: requiredFlash 900ms ease; border-radius: 18px; }
@media (prefers-reduced-motion: reduce){
  .required-flash{ animation-duration: .01ms; }
}

/* ---- option tiles ----------------------------------------------------- */
.service-options{ display: flex; flex-direction: column; gap: 10px; }
.service-option{ position: relative; }

/* Visually hidden, NOT display:none and NOT opacity:0 with pointer-events
   stripped: the input has to stay in the tab order and keep firing :focus-
   visible, because the ring below is the only thing a keyboard user gets. */
.service-option input[type="radio"]{
  position: absolute;
  width: 1px; height: 1px;
  margin: 0; padding: 0; border: 0;
  clip: rect(0 0 0 0);
  clip-path: inset(50%);
  overflow: hidden;
  white-space: nowrap;
}

.service-option label{
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  background: transparent;
  border: 1.5px solid var(--co-tile-line);
  border-radius: 14px;
  box-shadow: none;
  cursor: pointer;
  transition: border-color .18s ease, background-color .18s ease;
}
.service-option label:hover{ border-color: var(--co-ink-faint); }

/* Two selectors for one state: :has() for the real input, .active for the
   class the page's own setActiveOption() sets. Either alone would be a
   regression - :has() misses nothing but is newer, and .active is what the
   existing JS drives. */
.service-option:has(input:checked) > label,
.service-option.active > label{
  background: var(--co-sel-bg);
  border-color: var(--co-sel-line);
}
.service-option:has(input:focus-visible) > label{
  outline: 2px solid var(--co-focus);
  outline-offset: 2px;
}

/* The radio dot, drawn rather than native so it can live inside the tile. */
.co-dot{
  flex: 0 0 auto;
  width: 17px; height: 17px;
  margin-top: 2px;
  border-radius: 50%;
  border: 1.5px solid var(--co-tile-line);
  position: relative;
  transition: border-color .18s ease;
}
.co-dot::after{
  content: '';
  position: absolute;
  inset: 3px;
  border-radius: 50%;
  background: var(--co-sel-line);
  transform: scale(0);
  transition: transform .18s cubic-bezier(.2,.8,.3,1);
}
.service-option:has(input:checked) .co-dot,
.service-option.active .co-dot{ border-color: var(--co-sel-line); }
.service-option:has(input:checked) .co-dot::after,
.service-option.active .co-dot::after{ transform: scale(1); }
@media (prefers-reduced-motion: reduce){
  .co-dot::after{ transition: none; }
}

.co-tile-body{ min-width: 0; flex: 1 1 auto; display: block; }
.co-tile-head{
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 3px;
}
.method-name{
  display: block;
  font-weight: 700;
  font-size: 14px;
  color: var(--co-ink-strong);
}
.method-desc{
  display: block;
  font-size: 13px;
  color: var(--co-ink-dim);
  line-height: 1.45;
}
.method-note{
  display: block;
  font-size: 12px;
  color: var(--co-ink-faint);
  margin-top: 7px;
  padding-left: 9px;
  border-left: 2px solid var(--co-tile-line);
}

/* Tile tags and the GCash/Maya/Card chips. */
.co-tag{
  flex: 0 0 auto;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--co-ink-dim);
  background: var(--co-chip-bg);
  border-radius: 999px;
  padding: 3px 9px;
  white-space: nowrap;
}
.co-tag--free{ color: var(--co-ok); background: rgba(74,222,128,.12); }
html[data-mode="light"] .co-tag--free{ background: #e7f6ef; }
.co-chips{ display: inline-flex; gap: 4px; flex-wrap: wrap; }
.co-chip{
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--co-ink-dim);
  background: var(--co-chip-bg);
  border-radius: 4px;
  padding: 3px 6px;
}

/* ---- e-payment channel picker ----------------------------------------- */
.co-epay{
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed var(--co-card-line);
}
.co-epay-row{ display: flex; gap: 8px; flex-wrap: wrap; }
.epay-channel-option{
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 13px;
  border-radius: 10px;
  border: 1.5px solid var(--co-tile-line);
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--co-ink);
  transition: border-color .18s ease, background-color .18s ease;
}
.epay-channel-option:hover{ border-color: var(--co-ink-faint); }
.epay-channel-option.active{
  background: var(--co-sel-bg);
  border-color: var(--co-sel-line);
}
.epay-channel-option:focus-within{
  outline: 2px solid var(--co-focus);
  outline-offset: 2px;
}
.co-hint{ color: var(--co-ink-faint); font-size: 12.5px; margin: 10px 0 0; line-height: 1.5; }
.co-notice{
  display: none;
  color: var(--tw-warn, #fcd34d);
  background: var(--tw-warn-bg, rgba(251,191,36,.12));
  border: 1px solid var(--tw-warn-line, rgba(251,191,36,.34));
  border-radius: 10px;
  padding: 10px 12px;
  font-size: 12.5px;
  line-height: 1.5;
  margin: 10px 0 0;
}

/* ---- address card ----------------------------------------------------- */
.delivery-address-display{
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 14px;
  background: var(--co-inset);
  border: 1px solid var(--co-card-line);
  box-shadow: none;
  border-radius: 14px;
  padding: 14px 16px;
}
.addr-left{ min-width: 0; max-width: 100%; }
.addr-name{ color: var(--co-ink-strong); font-weight: 800; font-size: 14.5px; margin-bottom: 3px; }
.addr-phone{ color: var(--co-ink-dim); font-size: 13px; margin-bottom: 5px; }
.addr-text{ color: var(--co-ink-dim); font-size: 13.5px; line-height: 1.45; overflow-wrap: anywhere; }
.addr-pin{ color: var(--co-ok) !important; font-weight: 600; }
.addr-right{ display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.addr-badge{
  border: 1px solid var(--co-card-line);
  color: var(--co-ink-faint);
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}
/* No address yet. The panel is outlined in the danger colour and the prompt
   line is coloured to match, so the thing that is missing is the thing that
   is marked - not just the section around it. */
.delivery-address-display.addr-missing{ box-shadow: none; border: 1.5px solid var(--admin-danger,#c2686f); }
.delivery-address-display.addr-missing #addrText1Disp{ color: var(--admin-danger,#c2686f); font-weight: 600; }
.addr-change.is-add{ border-color: var(--co-sel-line); color: var(--co-sel-line); font-weight: 700; }
/* Set after a blocked submit, cleared as soon as an address is chosen. */
#deliveryAddressSection.addr-error{ outline: 2px solid var(--admin-danger,#c2686f); outline-offset: 4px; border-radius: 18px; }
#deliveryAddressSection.addr-error .form-section-title,
#deliveryAddressSection.addr-error h3{ color: var(--admin-danger,#c2686f); }
.addr-change{
  background: transparent;
  box-shadow: none;
  border: 1px solid var(--co-card-line);
  color: var(--co-ink);
  padding: 7px 13px;
  border-radius: 9px;
  font: inherit;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  pointer-events: auto;
  z-index: 10;
  position: relative;
  transition: border-color .18s ease, color .18s ease;
}
.addr-change:hover{ border-color: var(--co-sel-line); color: var(--co-ink-strong); }
.addr-change:active{ transform: scale(0.97); }

/* ---- notes + store credit --------------------------------------------- */
.co-textarea{
  width: 100%;
  box-sizing: border-box;
  min-height: 92px;
  resize: vertical;
  background: var(--co-inset);
  border: 1.5px solid var(--co-tile-line);
  border-radius: 12px;
  padding: 12px 14px;
  color: var(--co-ink);
  font: inherit;
  font-size: 13.5px;
  line-height: 1.5;
  transition: border-color .18s ease, box-shadow .18s ease;
}
.co-textarea::placeholder{ color: var(--co-ink-faint); }
.co-textarea:focus{
  outline: none;
  border-color: var(--co-sel-line);
  box-shadow: 0 0 0 3px rgba(224,86,122,.22);
}
html[data-mode="light"] .co-textarea:focus{ box-shadow: 0 0 0 3px rgba(194,58,99,.20); }

.co-check{
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
  cursor: pointer;
  font-size: 13.5px;
  color: var(--co-ink);
}

/* ---- "already paid, nothing to choose" lock ---------------------------- */
/* .service-options sets display:flex, which outranks the UA sheet's
   [hidden]{display:none} - without these two rules the tiles stay on screen
   while the lock panel is up, and vice versa. */
.service-options[hidden],
.co-paid-lock[hidden],
.required-mark[hidden]{ display: none !important; }

.co-paid-lock{
  display: flex;
  align-items: flex-start;
  gap: 11px;
  background: var(--tw-ok-bg, rgba(52,211,153,.10));
  border: 1px solid var(--tw-ok-line, rgba(52,211,153,.32));
  border-radius: 14px;
  padding: 14px 16px;
  color: var(--co-ink);
}
.co-paid-lock > i{
  color: var(--tw-ok, #34d399);
  font-size: 17px;
  line-height: 1;
  margin-top: 1px;
  flex-shrink: 0;
}
.co-paid-lock strong{
  display: block;
  font-size: 13.5px;
  color: var(--co-ink-strong);
}
.co-paid-lock p{
  margin: 4px 0 0;
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--co-ink-faint);
}
.co-paid-lock em{ font-style: normal; color: var(--co-ink); }
.co-check input{ width: 17px; height: 17px; cursor: pointer; accent-color: var(--co-sel-line); flex: 0 0 auto; }
.co-check:focus-within{ outline: 2px solid var(--co-focus); outline-offset: 3px; border-radius: 6px; }

/* ---- policy ----------------------------------------------------------- */
.co-policy{
  background: var(--co-card);
  border: 1px solid var(--co-card-line);
  border-radius: 18px;
  margin-bottom: 16px;
  overflow: hidden;
}
.co-policy > summary{
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 15px 22px;
  cursor: pointer;
  font-size: 13.5px;
  font-weight: 700;
  color: var(--co-ink);
  list-style: none;
}
.co-policy > summary::-webkit-details-marker{ display: none; }
.co-policy > summary:focus-visible{ outline: 2px solid var(--co-focus); outline-offset: -2px; }
.co-policy-chev{ margin-left: auto; font-size: 15px; transition: transform .2s ease; color: var(--co-ink-faint); }
.co-policy[open] .co-policy-chev{ transform: rotate(180deg); }
@media (prefers-reduced-motion: reduce){ .co-policy-chev{ transition: none; } }
.co-policy-body{ padding: 0 22px 18px; }
.co-policy-body p{ color: var(--co-ink-dim); font-size: 13px; line-height: 1.6; margin: 0; }

/* ---- summary ---------------------------------------------------------- */
.summary-card h3{ font-size: 15px; }
.order-items{ margin-bottom: 16px; }

.order-item{
  display: flex;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--co-card-line);
}
.order-item:first-child{ padding-top: 0; }
.order-item:last-child{ border-bottom: none; padding-bottom: 0; }

/* A light tile under the thumb, in both modes: product cut-outs are shot on
   white and vanish into a navy card. */
.item-image{
  position: relative;
  flex: 0 0 auto;
  width: 52px;
  height: 52px;
  border-radius: 11px;
  overflow: visible;
  background: var(--co-thumb);
  border: 1px solid var(--co-card-line);
  display: flex;
  align-items: center;
  justify-content: center;
}
.item-image img{
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 10px;
}
.image-placeholder{ color: #5d6f86; font-size: 10px; font-weight: 800; letter-spacing: .06em; }
.item-qty-badge{
  position: absolute;
  top: -6px; right: -6px;
  min-width: 19px; height: 19px;
  padding: 0 5px;
  box-sizing: border-box;
  border-radius: 999px;
  background: var(--co-sel-line);
  color: #fff;
  font-size: 10.5px;
  font-weight: 800;
  line-height: 19px;
  text-align: center;
}

.item-details{ min-width: 0; flex: 1 1 auto; }
.item-details h4{
  color: var(--co-ink-strong);
  font-size: 13px;
  font-weight: 700;
  line-height: 1.35;
  margin: 0 0 8px;
}
.item-row{ display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.item-price{
  color: var(--co-ink-strong);
  font-weight: 800;
  font-size: 13.5px;
  margin-left: auto;
  white-space: nowrap;
}

.quantity-control{
  display: flex;
  align-items: center;
  background: var(--co-inset);
  border-radius: 9px;
  border: 1px solid var(--co-card-line);
  overflow: hidden;
  width: fit-content;
  margin: 0;
  flex: 0 0 auto;
}
.qty-btn{
  width: 28px; height: 28px;
  border: none;
  background: transparent;
  color: var(--co-ink);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color .18s ease, color .18s ease;
}
.qty-btn.minus{ border-right: 1px solid var(--co-card-line); }
.qty-btn.plus{ border-left: 1px solid var(--co-card-line); }
.qty-btn span{ display: inline-block; font-weight: 800; font-size: 14px; line-height: 1; }
.qty-btn:disabled{ opacity: .35; cursor: not-allowed; }
.qty-btn:not(:disabled):hover{ background: var(--co-sel-bg); color: var(--co-sel-line); }
.qty-btn:focus-visible{ outline: 2px solid var(--co-focus); outline-offset: -2px; }
.qty-input{
  width: 36px; height: 28px;
  border: none;
  background: transparent;
  color: var(--co-ink-strong);
  text-align: center;
  font: inherit;
  font-size: 12.5px;
  font-weight: 700;
}
.qty-input::-webkit-inner-spin-button,
.qty-input::-webkit-outer-spin-button{ -webkit-appearance: none; margin: 0; }
.qty-input:focus-visible{ outline: 2px solid var(--co-focus); outline-offset: -2px; }

/* Matches cart.php's .remove-item-btn so the two pages offer the same
   affordance in the same place - a quiet glyph that only colours on hover. */
.checkout-remove-btn{
  width: 26px; height: 26px;
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid transparent;
  border-radius: 50%;
  background: transparent;
  color: var(--co-ink-faint);
  cursor: pointer;
  margin: 0;
  transition: background-color .18s ease, color .18s ease, border-color .18s ease, transform .12s ease;
}
.checkout-remove-btn:hover{
  background: var(--co-sel-bg);
  border-color: var(--co-sel-line);
  color: var(--co-sel-line);
}
.checkout-remove-btn:active{ transform: scale(.9); }
.checkout-remove-btn:focus-visible{ outline: 2px solid var(--co-focus); outline-offset: 1px; }
.checkout-remove-btn i{ font-size: 12px; line-height: 1; }

/* ---- totals ----------------------------------------------------------- */
.order-totals{ margin-bottom: 16px; }
.total-line{
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  padding: 6px 0;
  color: var(--co-ink-dim);
  font-size: 13px;
}
.total-line > span:last-child{ white-space: nowrap; font-weight: 700; color: var(--co-ink); }

/* Only one of the two fulfilment rows is ever shown, chosen off the real radio
   through their common ancestor - no JS, so it cannot disagree with the tile
   that is actually selected.

   The fallback comes FIRST and needs no @supports of its own: an engine without
   :has() simply never matches the two rules below it and is left with the
   delivery row showing. Delivery is the right default to strand it on - it is
   the one fulfilment that has a fee worth mentioning, so the worst case is a
   true statement shown a moment early rather than a missing one. The @supports
   wrapper is what lets the modern path start from "neither", which is correct
   while no choice has been made yet. */
.co-fee-row--delivery{ display: flex; }
.co-fee-row--pickup{ display: none; }

@supports selector(:has(*)) {
  .co-fee-row{ display: none; }
  .checkout-layout:has(#delivery_home:checked) .co-fee-row--delivery{ display: flex; }
  .checkout-layout:has(#delivery_pickup:checked) .co-fee-row--pickup{ display: flex; }
}
.co-fee-note{ color: var(--co-ink-faint) !important; font-weight: 600 !important; font-size: 12px; }
.co-fee-note--free{ color: var(--co-ok) !important; font-weight: 800 !important; }

.co-vat{
  margin: 10px 0 2px;
  padding: 11px 13px;
  background: var(--co-vat-bg);
  border: 1px solid var(--co-vat-line);
  border-radius: 12px;
}
.co-vat-line{
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  font-size: 13px;
  color: var(--co-ink-dim);
}
.co-vat-line > span:last-child{ font-weight: 700; color: var(--co-ink); white-space: nowrap; }
.co-vat-caption{
  font-family: var(--tw-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: var(--co-ink-faint);
  margin: 7px 0 0;
}

.total-divider{ height: 1px; background: var(--co-card-line); margin: 10px 0; }
.total-line.final-total{ align-items: baseline; padding: 2px 0; }
.total-line.final-total > span:first-child{
  color: var(--co-ink-strong);
  font-size: 14px;
  font-weight: 800;
}
.total-line.final-total > span:last-child{
  color: var(--co-hot);
  font-size: 26px;
  font-weight: 900;
  letter-spacing: -.02em;
}
#checkoutCreditLine > span:last-child{ color: var(--co-ok); }

/* ---- submit ----------------------------------------------------------- */
.checkout-actions{ display: block; margin-top: 4px; }
.checkout-btn{
  width: 100%;
  height: 52px;
  background: var(--co-btn);
  color: #fff;
  border: none;
  border-radius: 13px;
  padding: 0 18px;
  font: inherit;
  font-size: 14.5px;
  font-weight: 800;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  min-width: 0;
  pointer-events: auto;
  z-index: 10;
  position: relative;
  transition: filter .18s ease, transform .12s ease;
}
.checkout-btn:hover{ filter: brightness(1.08); transform: none; box-shadow: none; }
.checkout-btn:active{ transform: scale(.985); }
.checkout-btn:focus-visible{ outline: 2px solid var(--co-focus); outline-offset: 3px; }
.checkout-btn i{ font-size: 15px; }

.checkout-btn:disabled,
.checkout-btn.is-disabled{
  background: var(--co-inset);
  border: 1px solid var(--co-card-line);
  color: var(--co-ink-faint);
  cursor: not-allowed;
  box-shadow: none;
  transform: none;
  opacity: 1;
}
/* Looks disabled but stays clickable (no native `disabled` attribute) so a
   click can still tell the user what's missing instead of doing nothing. */
.checkout-btn.is-disabled:hover{ transform: none; box-shadow: none; filter: none; }

.security-badge{
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  margin-top: 11px;
  color: var(--co-ink-faint);
  font-size: 11.5px;
  font-weight: 600;
}
.security-badge i{ font-size: 13px; color: var(--co-ok); }

/* ---- narrow ----------------------------------------------------------- */
/* The summary un-sticks once it is below the form rather than beside it: a
   sticky element in a single column just hovers over the content under it. */
@media (max-width: 900px){
  .order-summary{ position: static; }
}
@media (max-width: 600px){
  .checkout-page{ padding: 20px 0 40px; }
  .co-card{ padding: 17px; border-radius: 15px; }
  .co-policy > summary{ padding: 14px 17px; }
  .co-policy-body{ padding: 0 17px 15px; }
  .co-masthead{ align-items: flex-start; }
  .co-steps{ gap: 7px; }
  .co-step + .co-step::before{ width: 12px; }
  .total-line.final-total > span:last-child{ font-size: 23px; }
  .header-navigation{ margin-bottom: 14px; }
}
/* At 360px the tag would otherwise push the title into a two-line wrap on
   every tile; dropping it to its own line keeps both readable. */
@media (max-width: 400px){
  .co-tile-head{ gap: 5px; }
  .co-tag, .co-chips{ order: 3; }
  .method-name{ flex: 1 1 100%; }
}

/* --- Address dialogs: pop-in ------------------------------------------------
   Both address dialogs (#addressModal, and #addrFormModal stacked above it)
   used to swap on display:none/block, so a second card simply appeared over
   the first with nothing to say a new layer had arrived.

   Backdrop fades; the card rises 8px and scales 0.96 -> 1. Modals are not
   anchored to a trigger, so the origin stays centred rather than following a
   button. transform + opacity only, both GPU-composited.

   Tokens are style.css's own: --ease-out is cubic-bezier(0.23, 1, 0.32, 1),
   --duration-base 200ms, --duration-fast 150ms. Exit is the faster one and
   retraces the same path - by the time you dismiss, you have already decided,
   and waiting on the animation is the only thing it could add.

   display is still toggled in JS (addrFormOpen/Close, addrClose) because the
   card must not be tabbable while hidden; .is-open lands one frame later so
   the browser has a start value to transition from. */
.tw-modal {
  opacity: 0;
  transition: opacity var(--duration-fast) var(--ease-out);
}
.tw-modal.is-open { opacity: 1; }

.tw-modal-card {
  opacity: 0;
  transform: translateY(8px) scale(0.96);
  transition:
    opacity var(--duration-base) var(--ease-out),
    transform var(--duration-base) var(--ease-out);
  will-change: transform;
}
.tw-modal.is-open .tw-modal-card {
  opacity: 1;
  transform: none;
}
/* Leaving: card first and quickly, so the backdrop does not linger over a
   card that has already gone. */
.tw-modal:not(.is-open) .tw-modal-card {
  transition:
    opacity var(--duration-fast) var(--ease-out),
    transform var(--duration-fast) var(--ease-out);
}

/* Fewer and gentler, not none: the fade still says "a layer arrived", the
   movement - the part that actually causes trouble - is dropped. */
@media (prefers-reduced-motion: reduce) {
  .tw-modal-card {
    transform: none;
    transition: opacity var(--duration-fast) var(--ease-out);
  }
  .tw-modal:not(.is-open) .tw-modal-card,
  .tw-modal.is-open .tw-modal-card {
    transform: none;
    transition: opacity var(--duration-fast) var(--ease-out);
  }
}


/* ===========================================================================
   LEGACY-CASCADE OVERRIDES
   ---------------------------------------------------------------------------
   Everything above styles this page on its own terms. This block exists only
   to win against rules that reach in from outside it, and it is separated so
   the two concerns are never confused.

   THE LEAK, measured rather than guessed (Chrome, CSS.getMatchedStylesForNode
   on the checked tile, data-mode="dark"):

     1. assets/js/main.js:26 sets data-theme="light" on <body> - unconditional,
        with no reference to data-mode. header.php:54 does the same on <html>.
        So in DARK mode every [data-theme="light"] rule in style.css is live.
     2. [data-theme="light"] therefore matches <body> at (0,1,0), which outranks
        customer-dark.css's own `body` block at (0,0,1). Its dark tokens lose:
            --bg-card-hover   rgba(103,232,249,.10)  ->  #e6e9eb   (style.css:104)
            --shadow-color    rgba(0,0,0,.45)        ->  rgba(163,150,138,.35)
        theme-light.css's `section.checkout-page` block (9i-1b) re-declares nine
        tokens to rescue exactly this, but not these two - that is the gap.
     3. customer-dark.css:117 then paints the selected tile with the first:
            [data-theme="light"] body :is(... .service-option.active)
              { background: var(--bg-card-hover) !important }
        = an OPAQUE #e6e9eb. The tile's own rgba(224,86,122,.10) composites over
        it to rgb(233,217,223) - the "#e9d9df" reported - while the text stays
        light. Measured, not inferred.
     4. The same counter block paints every card with --shadow-color, which is
        why the glow is warm beige rather than black.

   WHY !important IS UNAVOIDABLE ON SOME OF THESE
   The leaking declarations are themselves !important (style.css:3398/3474 and
   customer-dark.css:106/117). No specificity beats !important, so the only
   non-!important routes are editing those two shared sheets - which would
   change every other customer page - or stopping the unconditional
   data-theme, which is a JS change. Both are out of scope here.

   It is used as narrowly as possible: where a leaking rule reads a TOKEN, the
   token is re-declared instead (no !important needed, because
   html[data-mode=...] section.checkout-page is (0,3,2) against (0,1,0)); only
   the handful of properties written literally with !important are answered in
   kind. Each one is marked [!] below with the rule it answers.
   ======================================================================== */

/* ---- tokens: no !important needed, these only need to out-specify (0,1,0) */
html[data-mode="dark"] section.checkout-page{
  --bg-card-hover:  transparent;              /* kills the #e6e9eb tile fill  */
  --bg-product-img: var(--co-thumb);          /* keeps the light thumb plate  */
  --shadow-color:   rgba(0,0,0,.28);          /* kills the warm beige glow    */
  --border-color:   rgba(120,170,230,.16);
}
html[data-mode="light"] section.checkout-page{
  --bg-card-hover:  transparent;
  --bg-product-img: var(--co-thumb);
  --shadow-color:   rgba(20,50,95,.06);
  --border-color:   #e3e9f2;
}

/* ---- BUG 2: cards. [!] answers customer-dark.css:106 + style.css:3398 ---- */
html[data-mode="dark"] section.checkout-page :is(.form-section, .summary-card){
  background: linear-gradient(180deg, rgba(20,36,60,.82), rgba(11,22,38,.9)) !important;
  border: 1px solid rgba(120,170,230,.16) !important;
  box-shadow: 0 12px 30px rgba(0,0,0,.28) !important;
}
html[data-mode="light"] section.checkout-page :is(.form-section, .summary-card){
  background: #ffffff !important;
  background-image: none !important;
  border: 1px solid #e3e9f2 !important;
  box-shadow: 0 4px 18px rgba(20,50,95,.06) !important;
}
/* The form COLUMN is a layout wrapper, not a card - it was being given a card
   surface of its own, which is the pale film behind the real cards. */
section.checkout-page .checkout-form{
  background: none !important;
  border: 0 !important;
  box-shadow: none !important;
  padding: 0 !important;
}
/* The policy <details> is not in any legacy list, so it needs no !important -
   it is here only so the three card surfaces stay defined in one place. */
html[data-mode="dark"] section.checkout-page .co-policy{
  background: linear-gradient(180deg, rgba(20,36,60,.82), rgba(11,22,38,.9));
  border: 1px solid rgba(120,170,230,.16);
  box-shadow: 0 12px 30px rgba(0,0,0,.28);
}
html[data-mode="light"] section.checkout-page .co-policy{
  background: #ffffff;
  border: 1px solid #e3e9f2;
  box-shadow: 0 4px 18px rgba(20,50,95,.06);
}

/* ---- BUG 1: the tile. [!] answers customer-dark.css:106/117 + style.css:3474
   The wrapper carries no surface at all - the <label> inside it is the tile,
   and always was. Stripping the wrapper is what lets the label's translucent
   pink sit on the card instead of on an opaque light-grey rectangle. */
section.checkout-page :is(.service-option, .service-option.active, .service-option:hover){
  background: none !important;
  border: 0 !important;
  box-shadow: none !important;
}
html[data-mode="dark"] section.checkout-page .service-option > label{
  background: transparent;
  border: 1.5px solid var(--tw-line-ctl, rgba(120,170,230,.55));
}
html[data-mode="dark"] section.checkout-page .service-option:has(input:checked) > label,
html[data-mode="dark"] section.checkout-page .service-option.active > label{
  background: rgba(224,86,122,.10);
  border: 1.5px solid #e0567a;
}
html[data-mode="light"] section.checkout-page .service-option > label{
  background: #ffffff;
  border: 1px solid var(--tw-line-ctl, #7e90a9);
}
html[data-mode="light"] section.checkout-page .service-option > label:hover{
  border-color: #c9d4e3;
}
html[data-mode="light"] section.checkout-page .service-option:has(input:checked) > label,
html[data-mode="light"] section.checkout-page .service-option.active > label{
  background: #fdf2f5;
  border: 1.5px solid #c23a63;
}
html[data-mode="light"] section.checkout-page .service-option:has(input:checked) .co-dot,
html[data-mode="light"] section.checkout-page .service-option.active .co-dot{ border-color: #c23a63; }
html[data-mode="light"] section.checkout-page .co-dot::after{ background: #c23a63; }

/* Tile text. [!] answers customer-dark.css:134/139, which force these to
   --text-primary / --text-secondary with !important. */
html[data-mode="dark"] section.checkout-page .method-name{ color: #ffffff !important; }
html[data-mode="dark"] section.checkout-page .method-desc{ color: #c9d6e6 !important; }
html[data-mode="dark"] section.checkout-page .method-note{ color: #fcd9a0 !important; }
html[data-mode="light"] section.checkout-page .method-name{ color: #16233a !important; }
html[data-mode="light"] section.checkout-page .method-desc{ color: #46586f !important; }
html[data-mode="light"] section.checkout-page .method-note{ color: #8a5a00 !important; }

/* The delivery note is a soft amber advisory in both modes - it was inheriting
   the light treatment from theme-light.css:2219 in dark too. */
section.checkout-page .method-note{
  border-left: 0;
  border-radius: 8px;
  padding: 6px 10px;
  margin-top: 8px;
}
html[data-mode="dark"] section.checkout-page .method-note{
  background: rgba(251,191,36,.10);
  border: 1px solid rgba(251,191,36,.25);
}
html[data-mode="light"] section.checkout-page .method-note{
  background: #fff7e6;
  border: 1px solid #f2dcae;
}

/* ---- address box + summary rows. [!] same counter block ------------------ */
html[data-mode="dark"] section.checkout-page .delivery-address-display{
  background: rgba(120,170,230,.07) !important;
  border: 1px solid rgba(120,170,230,.16) !important;
  box-shadow: none !important;
}
html[data-mode="light"] section.checkout-page .delivery-address-display{
  background: #f7f9fc !important;
  border: 1px solid #e3e9f2 !important;
  box-shadow: none !important;
}
/* The summary item row was getting a full card treatment - border on all four
   sides plus a shadow - on top of the single rule this design wants. That
   doubled edge is the "double border". */
section.checkout-page .order-item{
  background: none !important;
  box-shadow: none !important;
  border: 0 !important;
  border-bottom: 1px solid var(--co-card-line) !important;
}
section.checkout-page .order-item:last-child{ border-bottom: 0 !important; }

/* ---- BUG 3: the page bloom ------------------------------------------------
   Dark was pink .20 / cyan .13 at blur(30px) - roughly double the light side,
   which already carries the dashboard's values. Brought to parity. The grid
   stays at rgba(120,170,230,.05). No !important: (0,3,2) over (0,1,1). */
html[data-mode="dark"] section.checkout-page::after{
  background-image:
    radial-gradient(42vw 42vw at 82% 6%, rgba(224,86,122,.10), rgba(224,86,122,0) 68%),
    radial-gradient(38vw 38vw at 12% 88%, rgba(56,189,248,.09), rgba(56,189,248,0) 68%);
  filter: blur(34px);
}

/* The grand total lost its pink in DARK only. theme-light.css:2168
     section.checkout-page .summary-card .total-line span { color: var(--text-secondary) }
   is (0,3,2) and mode-agnostic; light has a counter for it at 2232 but dark
   never got one, so the total rendered #a9bcd4 like any other summary label.
   It still passed the contrast probe at 10:1, which is exactly why a measured
   check is not a substitute for looking at the thing. (0,4,2), no !important. */
html[data-mode="dark"] section.checkout-page .total-line.final-total > span:last-child{
  color: #ff9ab5;
}

/* The item row wrapped its remove button onto a second line for some products
   and not others - the price is nowrap, so a name of the wrong length tipped
   one row over and left the summary looking ragged. Nothing here needs to
   wrap: the row is 92px of stepper + the price + a 26px button inside a
   320px card. */
section.checkout-page .item-row{ flex-wrap: nowrap; }
section.checkout-page .item-row .item-price{ min-width: 0; }

/* Nothing on this page should carry a light halo: the legacy sheets put a
   text-shadow on headings and an inset highlight on panels. */
section.checkout-page :is(h1, h3, .co-eyebrow, .item-details h4, .method-name){
  text-shadow: none !important;
}
</style>
<style>
  /* Card and chrome */
  .tw-addr-modal > .tw-modal-card{
    background: var(--tw-surface) !important;
    border-color: var(--tw-line) !important;
    box-shadow: 0 24px 60px rgba(2,6,16,.28) !important;
  }
  .tw-addr-modal #addrModalTitle{ color: var(--tw-ink-strong) !important; }
  .tw-addr-modal [style*="border-bottom"], .tw-addr-modal [style*="border-top"]{
    border-color: var(--tw-line) !important;
  }
  .tw-addr-modal button[aria-label="Close"]{ color: var(--tw-ink-faint) !important; }
  .tw-addr-modal button[aria-label="Close"]:hover{ color: var(--tw-ink) !important; }

  /* Body */
  .tw-addr-modal #addrEmptyNote{ color: var(--tw-ink-dim) !important; }
  .tw-addr-modal #addrAddBtn{
    border: 1px dashed var(--tw-line) !important;
    color: var(--tw-accent) !important;
  }
  .tw-addr-modal #addrAddBtn:hover{ border-color: var(--tw-accent) !important; }

  /* Footer. Cancel is quiet; Confirm moves onto the page accent.
     Not a contrast fix - the original #041016 on #22c55e measures 8.4:1 and
     was perfectly legible. It is a consistency one: every other primary action
     on this page is the accent rose, and a lone green button read as a
     different kind of control. White on the accent is 5.1:1. */
  .tw-addr-modal button[onclick="addrClose()"]:not([aria-label]){
    border-color: var(--tw-line) !important;
    color: var(--tw-ink) !important;
  }
  .tw-addr-modal button[onclick="addrConfirm()"]{
    background: var(--tw-accent) !important;
    color: #fff !important;
  }

  /* Saved address rows */
  .tw-addr-modal .addr-row{ border-bottom-color: var(--tw-line-soft) !important; }
  .tw-addr-modal .addr-row .nm{ color: var(--tw-ink-strong) !important; }
  .tw-addr-modal .addr-row .ph{ color: var(--tw-ink-dim) !important; }
  .tw-addr-modal .addr-row .tx{ color: var(--tw-ink-dim) !important; }
  .tw-addr-modal .addr-row .edit{ color: var(--tw-accent) !important; }
  .tw-addr-modal .addr-row .def{
    border-color: var(--tw-accent) !important;
    color: var(--tw-accent) !important;
  }
  .tw-addr-modal .addr-row .pinned{
    border-color: rgba(16,185,129,.45) !important;
    color: #0e7a4f !important;                     /* 4.9:1 on white */
  }
  .tw-addr-modal .addr-row .del{
    border-color: var(--tw-line) !important;
    color: #a02424 !important;
  }

  /* Form fields and the suggestion panel */
  .tw-addr-modal .addr-form input,
  .tw-addr-modal .addr-form textarea,
  .tw-addr-modal .addr-form select{
    background: var(--tw-surface) !important;
    border-color: var(--tw-line) !important;
    color: var(--tw-ink) !important;
  }
  .tw-addr-modal .addr-form ::placeholder{ color: var(--tw-ink-faint) !important; }
  .tw-addr-modal .suggest-panel{
    background: var(--tw-surface) !important;
    border-color: var(--tw-line) !important;
    box-shadow: 0 10px 24px rgba(2,6,16,.22) !important;
  }
  .tw-addr-modal .suggest-status,
  .tw-addr-modal .field-clear{ color: var(--tw-ink-faint) !important; }

  /* Dark mode keeps the deep card it was designed for - only the literals are
     replaced, so the two modes are driven by the same rules. */
  html[data-mode="dark"] .tw-addr-modal > .tw-modal-card{
    background: var(--tw-surface-2) !important;
    box-shadow: 0 24px 60px rgba(0,0,0,.5) !important;
  }
  html[data-mode="dark"] .tw-addr-modal .addr-form input,
  html[data-mode="dark"] .tw-addr-modal .addr-form textarea,
  html[data-mode="dark"] .tw-addr-modal .suggest-panel{
    background: rgba(255,255,255,.06) !important;
  }
  html[data-mode="dark"] .tw-addr-modal .addr-row .pinned{ color: #4ade80 !important; }
  html[data-mode="dark"] .tw-addr-modal .addr-row .del{ color: #fca5a5 !important; }

  /* ---- the Add New Address form (#addrFormModal) ---- */
  .tw-addr-modal #addrFormTitle{ color: var(--tw-ink-strong) !important; }
  .tw-addr-modal #addrFormCloseBtn{
    border-color: var(--tw-line) !important;
    color: var(--tw-ink) !important;
  }
  .tw-addr-modal .addr-form{ border-bottom-color: var(--tw-line) !important; }

  /* The pin block: its own well, so it reads as one required control rather
     than a map loose in the form. */
  .tw-addr-modal .addr-pin{
    background: var(--tw-surface-2) !important;
    border-color: var(--tw-line) !important;
  }
  .tw-addr-modal .addr-pin > div > div > div:first-child{ color: var(--tw-ink-strong) !important; }
  .tw-addr-modal .pin-hint{ color: var(--tw-ink-dim) !important; }
  .tw-addr-modal .pin-locate,
  .tw-addr-modal .pin-find{
    background: var(--tw-surface) !important;
    border-color: var(--tw-line) !important;
    color: var(--tw-ink) !important;
  }
  .tw-addr-modal .pin-locate:hover,
  .tw-addr-modal .pin-find:hover{ border-color: var(--tw-accent) !important; }
  /* The "Required - tap the map" line and the * beside the heading. #f87171 is
     a dark-mode red: 2.9:1 on white, which is the wrong ratio for the one
     message telling you the form cannot be submitted yet. */
  .tw-addr-modal .pin-readout{ color: #a02424 !important; }
  .tw-addr-modal .addr-pin span[title="Required"]{ color: #a02424 !important; }
  .tw-addr-modal .pin-clear{
    border-color: var(--tw-line) !important;
    color: #a02424 !important;
  }
  /* Leaflet paints its own ground behind the tiles while they load. */
  .tw-addr-modal .pin-map{ background: var(--tw-surface-2) !important; }

  /* "Label As:", the Home/Work pills, and the default checkbox row */
  .tw-addr-modal .addr-form > div[style*="margin:6px 0 8px"],
  .tw-addr-modal .addr-form > label{ color: var(--tw-ink-dim) !important; }
  .tw-addr-modal .lbl{
    border-color: var(--tw-line) !important;
    color: var(--tw-ink-dim) !important;
  }
  .tw-addr-modal .lbl.active{
    border-color: var(--tw-accent) !important;
    color: var(--tw-accent) !important;
    font-weight: 700 !important;
  }

  /* Submit / Cancel */
  .tw-addr-modal .addr-form .save{
    background: var(--tw-accent) !important;
    color: #fff !important;
  }
  .tw-addr-modal .addr-form .cancel{
    border-color: var(--tw-line) !important;
    color: var(--tw-ink) !important;
  }

  html[data-mode="dark"] .tw-addr-modal .addr-pin,
  html[data-mode="dark"] .tw-addr-modal .pin-map{ background: rgba(255,255,255,.03) !important; }
  html[data-mode="dark"] .tw-addr-modal .pin-locate,
  html[data-mode="dark"] .tw-addr-modal .pin-find{ background: rgba(255,255,255,.06) !important; }
  /* Missing-field marks. The border is what answers "where"; .addr-form-msg
     above the buttons answers "what". */
  .tw-addr-modal .addr-form .field-invalid{
    border-color: #a02424 !important;
    box-shadow: 0 0 0 3px rgba(160,36,36,.12) !important;
  }
  .tw-addr-modal .addr-form .addr-pin.field-invalid{
    border-color: #a02424 !important;
    box-shadow: 0 0 0 3px rgba(160,36,36,.10) !important;
  }
  .tw-addr-modal .addr-form-msg{ color: #a02424 !important; font-weight: 600; }
  html[data-mode="dark"] .tw-addr-modal .addr-form .field-invalid,
  html[data-mode="dark"] .tw-addr-modal .addr-form .addr-pin.field-invalid{
    border-color: #fca5a5 !important;
    box-shadow: 0 0 0 3px rgba(252,165,165,.14) !important;
  }
  html[data-mode="dark"] .tw-addr-modal .addr-form-msg{ color: #fca5a5 !important; }

  html[data-mode="dark"] .tw-addr-modal .pin-readout,
  html[data-mode="dark"] .tw-addr-modal .addr-pin span[title="Required"],
  html[data-mode="dark"] .tw-addr-modal .pin-clear{ color: #fca5a5 !important; }
</style>

<script src="<?= WEB_ROOT ?>public/shared/assets/js/address-suggestions.js"></script>
<!-- DeliveryRoute.geocode() only - the house Nominatim wrapper, with its
     sessionStorage cache and one-request-a-second etiquette already handled.
     Its routing half needs Leaflet, which the address form loads on demand;
     this file is safe to load without it. -->
<script src="<?= WEB_ROOT ?>public/shared/assets/js/delivery-route.js"></script>

<?php /* The stray second <body class="loading"> stood here. Its class moved to
         the real <body> above; the tag itself is gone, so the document now has
         exactly one. */ ?>
<section class="checkout-page">
  <div class="container">

    <!-- Masthead. The back link, the eyebrow/title, and the progress rail.
         Numbering of the cards below is a CSS counter, not markup, so a card
         that JS hides (the address card, on Store Pickup) drops out of the
         sequence on its own - display:none elements do not increment a CSS
         counter, so the visible cards always read 1, 2, 3 with no gap. -->
    <div class="checkout-header">
      <div class="header-navigation">
        <a href="<?= WEB_ROOT ?>public/authenticated/customer/cart.php" class="header-back-btn">
          <i class="fi fi-rr-arrow-left"></i>
          <span>Back to cart</span>
        </a>
      </div>
      <div class="co-masthead">
        <div class="co-masthead-text">
          <p class="co-eyebrow">Secure checkout</p>
          <h1>Check<span class="co-title-grad">out</span></h1>
          <p class="co-sub">Review your order and complete your purchase</p>
        </div>
        <!-- An ordered list, because it is one: three steps in sequence. The
             state is in the markup (aria-current) rather than colour alone. -->
        <ol class="co-steps" aria-label="Checkout progress">
          <li class="co-step is-done">
            <span class="co-step-dot" aria-hidden="true"><i class="fi fi-rr-check"></i></span>
            <span class="co-step-label">Cart<span class="co-sr"> - done</span></span>
          </li>
          <li class="co-step is-active" aria-current="step">
            <span class="co-step-dot" aria-hidden="true">2</span>
            <span class="co-step-label">Checkout</span>
          </li>
          <li class="co-step">
            <span class="co-step-dot" aria-hidden="true">3</span>
            <span class="co-step-label">Done</span>
          </li>
        </ol>
      </div>
    </div>

    <div class="checkout-layout">
      <!-- Order Form -->
      <div class="checkout-form">
        <form id="checkoutForm" action="<?= WEB_ROOT ?>public/authenticated/customer/process_order.php" method="POST">
          <?= csrf_field() ?>
          <?php if (!empty($selectedIds)): ?>
            <input type="hidden" name="selected_ids" id="checkoutSelectedIds" value="<?= htmlspecialchars(implode(',', $selectedIds)) ?>">
          <?php endif; ?>

          <!-- Delivery Method -->
          <div class="form-section co-card co-card--num">
            <h3>Delivery method <span class="required-mark" title="Required">*</span></h3>
            <div class="service-options" id="deliveryMethodGroup">
              <div class="service-option" onclick="selectDeliveryMethod('delivery')">
                <input type="radio" name="delivery_choice" value="delivery" id="delivery_home" required>
                <label for="delivery_home">
                  <span class="co-dot" aria-hidden="true"></span>
                  <span class="co-tile-body">
                    <span class="co-tile-head">
                      <span class="method-name">Home Delivery</span>
                      <span class="co-tag">Fee paid to rider</span>
                    </span>
                    <span class="method-desc">We'll deliver your order to your address</span>
                    <span class="method-note">Note: Delivery fee will be shouldered by customer</span>
                  </span>
                </label>
              </div>
              <div class="service-option" onclick="selectDeliveryMethod('pickup')">
                <input type="radio" name="delivery_choice" value="pickup" id="delivery_pickup" required>
                <label for="delivery_pickup">
                  <span class="co-dot" aria-hidden="true"></span>
                  <span class="co-tile-body">
                    <span class="co-tile-head">
                      <span class="method-name">Store Pickup</span>
                      <span class="co-tag co-tag--free">Free</span>
                    </span>
                    <span class="method-desc">Pick up your order at our store</span>
                  </span>
                </label>
              </div>
            </div>
          </div>

          <!-- Delivery Address. Its own card, shown/hidden by
               updateCheckoutState() through this exact id - so it stays a
               section with an inline display:none, not a nested block. -->
          <div class="form-section co-card co-card--num" id="deliveryAddressSection" style="display: none;">
            <h3>Deliver to <span class="required-mark" title="Required">*</span></h3>
            <div class="delivery-address-display">
              <div class="addr-left">
                <p class="co-field-label">Deliver to</p>
                <div class="addr-name" id="addrNameDisp"><?= htmlspecialchars($defaultAddressName) ?></div>
                <div class="addr-phone" id="addrPhoneDisp"><?= htmlspecialchars($defaultAddressPhone) ?></div>
                <div class="addr-text" id="addrText1Disp"></div>
                <div class="addr-text" id="addrText2Disp"><?= htmlspecialchars($defaultAddressText) ?></div>
                <!-- Only shown when the selected address carries a map pin, so
                     the customer can see the rider has exact coordinates and
                     is not relying on the street line being spelled right. -->
                <div class="addr-text addr-pin" id="addrPinDisp" style="display:none;color:#4ade80;font-size:13px;margin-top:4px">&#128205; Exact location pinned on the map</div>
              </div>
              <div class="addr-right">
                <span class="addr-badge">Default</span>
                <button type="button" class="addr-change" id="changeAddressBtn">Change</button>
              </div>
            </div>
            <input type="hidden" id="full_name" name="full_name" value="<?= htmlspecialchars($defaultAddressName) ?>">
            <input type="hidden" id="phone" name="phone" value="<?= htmlspecialchars($defaultAddressPhone) ?>">
            <input type="hidden" id="address" name="address" value="<?= htmlspecialchars($defaultAddressText) ?>">
            <input type="hidden" id="address_line1" name="address_line1" value="">
            <input type="hidden" id="address_line2" name="address_line2" value="">
            <!-- Map pin for the selected address, filled by applyAddress().
                 Blank means "no pin" - process_order.php then stores NULL and
                 every downstream map falls back to geocoding the text. -->
            <input type="hidden" id="delivery_latitude" name="delivery_latitude" value="">
            <input type="hidden" id="delivery_longitude" name="delivery_longitude" value="">
            <input type="hidden" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
          </div>

          <!-- Payment Method -->
          <div class="form-section co-card co-card--num">
            <h3>Payment method <span class="required-mark" id="paymentRequiredMark" title="Required">*</span></h3>
            <div class="service-options" id="paymentMethodGroup">
              <div class="service-option" onclick="selectPaymentChoice('cash')">
                <input type="radio" name="payment_choice" value="cash" id="payment_cash" required>
                <label for="payment_cash">
                  <span class="co-dot" aria-hidden="true"></span>
                  <span class="co-tile-body">
                    <span class="co-tile-head">
                      <span class="method-name" id="cashMethodName">Cash on Delivery</span>
                      <span class="co-tag">Cash</span>
                    </span>
                    <span class="method-desc" id="cashMethodDesc">We'll deliver to your address and you pay when you receive your order</span>
                  </span>
                </label>
              </div>
              <div class="service-option" onclick="selectPaymentChoice('online')">
                <input type="radio" name="payment_choice" value="online" id="payment_online" required>
                <label for="payment_online">
                  <span class="co-dot" aria-hidden="true"></span>
                  <span class="co-tile-body">
                    <span class="co-tile-head">
                      <span class="method-name">Online Payment (E-Payment)</span>
                      <span class="co-chips" aria-hidden="true">
                        <span class="co-chip">GCash</span><span class="co-chip">Maya</span><span class="co-chip">Card</span>
                      </span>
                    </span>
                    <span class="method-desc">Pay online via GCash, Maya, or card - no cash needed</span>
                  </span>
                </label>
              </div>
            </div>

            <?php if ($storeCreditBalance > 0): ?>
            <!-- Shown INSTEAD of the two tiles above once the applied credit
                 covers the whole total. There is nothing left to charge, so
                 there is nothing to choose: asking anyway produced orders
                 stamped "Cash on Delivery" that a rider was then sent to
                 collect PHP 0.00 for. checkoutApplyCreditLock() toggles this
                 against the tiles, and the form posts payment_method =
                 'store_credit' - process_order.php re-proves that against the
                 ledger before trusting it. -->
            <div class="co-paid-lock" id="paymentCreditLock" hidden>
              <i class="fi fi-rs-check-circle" aria-hidden="true"></i>
              <div>
                <strong>Paid in full with store credit</strong>
                <p>
                  <span id="paymentCreditLockAmount"></span> of your credit covers this order,
                  so there's nothing left to pay and no payment method to choose.
                  Would rather save it for later? Uncheck <em>Apply my store credit</em> below.
                </p>
              </div>
            </div>
            <?php endif; ?>

            <!-- E-Payment channel picker - only shown when Online Payment is selected -->
            <div id="epaymentChannelSection" class="co-epay" style="display:none;">
              <div class="co-field-label">Choose e-payment channel</div>
              <div class="co-epay-row">
                <label class="epay-channel-option active" data-channel="gcash" onclick="selectEpaymentChannel('gcash')">
                  <input type="radio" name="epay_channel" value="gcash" id="epay_gcash" checked style="accent-color:#00aaff;">
                  <span>GCash</span>
                </label>
                <label class="epay-channel-option" data-channel="paymaya" onclick="selectEpaymentChannel('paymaya')">
                  <input type="radio" name="epay_channel" value="paymaya" id="epay_paymaya" style="accent-color:#00aaff;">
                  <span>Maya</span>
                </label>
                <label class="epay-channel-option" data-channel="xendit" onclick="selectEpaymentChannel('xendit')">
                  <input type="radio" name="epay_channel" value="xendit" id="epay_xendit" style="accent-color:#00aaff;">
                  <span>Card / Other (Xendit)</span>
                </label>
              </div>
              <p id="epayHintManual" class="co-hint">After placing your order, you'll see a QR code and reference number to complete payment.</p>
              <p id="epayHintXendit" class="co-hint" style="display:none;">After placing your order, you'll be taken to a secure Xendit checkout page to pay by card, e-wallet, or other supported methods.</p>

            </div>

            <!-- Hidden inputs that get updated dynamically from the two choices above -->
            <input type="hidden" name="payment_method" value="" id="payment_method">
            <input type="hidden" name="delivery_method" value="" id="delivery_method">
          </div>

          <?php if ($storeCreditBalance > 0): ?>
          <!-- Store Credit - only shown when the customer actually has a balance to spend -->
          <div class="form-section co-card co-card--num">
            <h3>Store credit</h3>
            <label class="co-check" for="useStoreCreditToggle">
              <input type="checkbox" id="useStoreCreditToggle" checked>
              <span>Apply my store credit - <strong><?= formatCurrency($storeCreditBalance) ?></strong> available</span>
            </label>
            <input type="hidden" name="use_store_credit" id="useStoreCreditInput" value="0">
          </div>
          <?php endif; ?>

          <!-- Order Notes -->
          <div class="form-section co-card co-card--num">
            <h3>Order notes <span class="co-optional">optional</span></h3>
            <textarea class="co-textarea" name="order_notes" placeholder="Any special instructions for your order..."></textarea>
          </div>

          <!-- Cancellation & Refund Policy - shown last, right before the
               submit button, so it's the last thing read before placing the
               order rather than buried in a modal only seen after ordering
               (see order_success.php's Cancel Order modal for the same
               refund_method choice at cancellation time). Text matches
               process_cancel_order.php's actual behavior exactly: only
               orders paid online (gcash/paymaya/xendit) have anything to
               refund, and get a choice between original payment method or
               instant +5% store credit; every cancellation goes through
               admin review regardless of payment method.

               Collapsed by default now, but the paragraphs keep their ids and
               their inline display:none - selectPaymentChoice() flips exactly
               one of them on, and losing either would blank the policy. -->
          <details class="co-policy">
            <summary>
              <i class="fi fi-rr-shield-check" aria-hidden="true"></i>
              <span>Cancellation &amp; refund policy</span>
              <i class="fi fi-rr-angle-small-down co-policy-chev" aria-hidden="true"></i>
            </summary>
            <div class="co-policy-body">
              <p id="cancelPolicyCash" style="display:none;">You can request to cancel this order any time before it's delivered/picked up. Since payment is only collected on delivery/pickup, nothing is charged until then - a cancellation request just needs admin review and approval.</p>
              <p id="cancelPolicyOnline" style="display:none;">You can request to cancel this order any time before it's delivered/picked up. Cancellation requests go through admin review; once approved, you'll choose between a refund to your original payment method, or instant store credit with a 5% bonus.</p>
              <!-- Neither of the two above fits an order settled from the
                   ledger: nothing was charged to a card, but the credit WAS
                   spent, so "nothing is charged until then" would be wrong.
                   Order_Cancel_Request.php returns credit_applied on approval. -->
              <p id="cancelPolicyCredit" style="display:none;">You can request to cancel this order any time before it's delivered/picked up. Cancellation requests go through admin review; once approved, the store credit you spent on this order goes straight back to your balance.</p>
            </div>
          </details>
        </form>
      </div>

      <!-- Order Summary -->
      <div class="order-summary">
        <div class="summary-card co-card">
          <h3>Order summary</h3>

          <!-- Cart Items -->
          <div class="order-items">
            <?php foreach ($cartItems as $item): ?>
            <div class="order-item" data-product-id="<?= (int)$item['id'] ?>" data-price="<?= (float)$item['price'] ?>" data-stock="<?= (int)$item['stock'] ?>" style="animation: fadeInUp 0.6s ease-out <?= (array_search($item, $cartItems) * 0.1) ?>s both;">
              <div class="item-image">
                <?php if (!empty($item['image_url'])): ?>
                  <img src="<?= WEB_ROOT . htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" loading="eager">
                <?php else: ?>
                  <div class="image-placeholder">IMG</div>
                <?php endif; ?>
                <!-- Visual echo of the quantity input below, nothing more -
                     aria-hidden so the count is not announced twice. -->
                <span class="item-qty-badge" aria-hidden="true"><?= (int)$item['quantity'] ?></span>
              </div>
              <div class="item-details">
                <h4><?= htmlspecialchars($item['name']) ?></h4>
                <div class="item-row">
                  <div class="quantity-control">
                    <button type="button" class="qty-btn minus" onclick="checkoutAdjustQty(<?= (int)$item['id'] ?>, -1)"
                            aria-label="Decrease quantity, or remove at 1"><span>-</span></button>
                    <input type="number" class="qty-input" value="<?= (int)$item['quantity'] ?>" min="1" max="<?= (int)$item['stock'] ?>" onchange="checkoutSetQty(<?= (int)$item['id'] ?>, this.value)">
                    <button type="button" class="qty-btn plus" onclick="checkoutAdjustQty(<?= (int)$item['id'] ?>, 1)" <?= $item['quantity'] >= $item['stock'] ? 'disabled' : '' ?>><span>+</span></button>
                  </div>
                  <span class="item-price"><?= formatCurrency($item['price'] * $item['quantity']) ?></span>
                  <button type="button" class="checkout-remove-btn"
                          onclick="checkoutConfirmRemove(<?= (int)$item['id'] ?>)"
                          aria-label="Remove <?= htmlspecialchars($item['name']) ?> from this order"
                          title="Remove from this order"><i class="fi fi-rs-trash"></i></button>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Order Totals.
               phVatAdd() (config/functions.php) is the system's own VAT
               helper and the only source used here: net is the sum of the
               line prices, vat is 12% ADDED on top, gross is what is
               charged. Nothing is recomputed in this view - the same three
               values the page has always printed, relabelled so the
               direction is unambiguous. checkoutRecalcTotals() mirrors the
               identical arithmetic client-side for live quantity edits. -->
          <?php $vatBreakdown = phVatAdd($cartTotal); ?>
          <div class="order-totals">
            <div class="total-line">
              <span>Subtotal (VAT-exclusive) &middot; <span id="checkoutItemCount"><?= count($cartItems) ?></span> items</span>
              <span id="checkoutSubtotal"><?= formatCurrency($vatBreakdown['net']) ?></span>
            </div>

            <!-- Fulfilment line. Static copy, no figure: this system has no
                 delivery-fee column or calculation anywhere, so quoting a
                 number here would be inventing a price. Which of the two
                 shows is driven off the real radio with :has(), so it needs
                 no JS and cannot fall out of step with the choice. -->
            <div class="total-line co-fee-row co-fee-row--delivery">
              <span>Delivery fee</span>
              <span class="co-fee-note">Paid to rider</span>
            </div>
            <div class="total-line co-fee-row co-fee-row--pickup">
              <span>Store pickup</span>
              <span class="co-fee-note co-fee-note--free">Free</span>
            </div>

            <div class="co-vat">
              <div class="co-vat-line">
                <span>VAT (12%)</span>
                <span id="checkoutVat"><?= formatCurrency($vatBreakdown['vat']) ?></span>
              </div>
              <p class="co-vat-caption">12% VAT added</p>
            </div>

            <div class="total-divider"></div>
            <div class="total-line final-total">
              <span>Total</span>
              <span id="checkoutTotal"><?= formatCurrency($vatBreakdown['gross']) ?></span>
            </div>
            <?php if ($storeCreditBalance > 0): ?>
            <div class="total-line" id="checkoutCreditLine" style="display:none;">
              <span>Store credit applied</span>
              <span id="checkoutCreditApplied">-<?= formatCurrency(0) ?></span>
            </div>
            <div class="total-divider" id="checkoutCreditDivider" style="display:none;"></div>
            <div class="total-line final-total" id="checkoutDueLine" style="display:none;">
              <span>Amount due</span>
              <span id="checkoutAmountDue"><?= formatCurrency($vatBreakdown['gross']) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Submit. Sits here, outside <form>, and is tied back to it with
               form="checkoutForm" - the same id, class, aria-disabled and
               click handlers as when it lived inside the form, so it is the
               same successful-submit button in a different place. -->
          <div class="checkout-actions">
            <button type="submit" form="checkoutForm" class="checkout-btn is-disabled" id="checkoutBtn" aria-disabled="true">
              <i class="fi fi-rr-shopping-bag"></i>
              Complete Order
            </button>
          </div>

          <!-- Security Badge -->
          <div class="security-badge">
            <i class="fi fi-rr-shield-check"></i>
            <span>Your payment details are encrypted</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
// Immediate display optimizations
document.addEventListener('DOMContentLoaded', function(){
  console.log('DOM Content Loaded - Initializing checkout page');
  
  // Remove loading class immediately for instant display
  document.body.classList.remove('loading');
  
  // Initialize UI components first
  initializeUI();
  
  // Preload critical data
  preloadCriticalData();
});

// Fallback initialization in case DOMContentLoaded already fired
if (document.readyState === 'loading') {
  // Still loading, wait for DOMContentLoaded
} else {
  // Already loaded, initialize immediately
  console.log('DOM already loaded - Initializing immediately');
  document.body.classList.remove('loading');
  initializeUI();
  preloadCriticalData();
}

function preloadCriticalData() {
  // Preload address book data
  if (typeof addressStorageKey !== 'undefined') {
    try {
      var stored = localStorage.getItem(addressStorageKey);
      if (stored) {
        addressBook = JSON.parse(stored) || [];
        if (addressBook.length > 0) {
          // Apply the default address immediately
          var defaultAddr = addressBook.find(a => a.isDefault) || addressBook[0];
          if (defaultAddr) {
            applyAddress(defaultAddr);
          }
          renderAddrList();
        }
      }
    } catch(e) {
      addressBook = [];
    }
  }
  
  // Do not auto-select service option from previous preference.
}

function initializeUI() {
  console.log('Initializing UI components');
  
  // Address modal UI
  var btn = document.getElementById('changeAddressBtn');
  console.log('Change address button found:', !!btn);
  if(btn){ 
    btn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      console.log('Change address button clicked');
      try { initAddressBook(); } catch(_e) {}
      var m = document.getElementById('addressModal');
      if(m) {
        twModalShow(m);
        console.log('Address modal opened');
      } else {
        console.error('Address modal not found');
      }
    }); 
    console.log('Change address button event listener added');
  } else {
    console.error('Change address button not found');
  }
  
  // Add event listener for checkout button
  var checkoutBtn = document.getElementById('checkoutBtn');
  console.log('Checkout button found:', !!checkoutBtn);
  if (checkoutBtn) {
    checkoutBtn.addEventListener('click', function(e) {
      e.preventDefault();
      console.log('Checkout button clicked');
      completeOrderCOD();
    });
    console.log('Checkout button event listener added');
  } else {
    console.error('Checkout button not found');
  }
  
  // Initialize address book
  try {
    initAddressBook();
  } catch(e) {
    console.error('Error initializing address book:', e);
  }
  
  // Start with no service selected; keep checkout disabled until a choice is made
  updateCheckoutState();
}

// Provide a basic notifier if the global helper is unavailable
if (typeof window.showNotification !== 'function') {
  window.showNotification = function(message, type){
    try { console[(type==='error'?'error':'log')](message); } catch(_){}
    alert(message || 'An unexpected error occurred.');
  };
}

// Test function to verify buttons are working
function testButtons() {
  console.log('Testing buttons...');
  
  var changeBtn = document.getElementById('changeAddressBtn');
  var checkoutBtn = document.getElementById('checkoutBtn');
  
  console.log('Change button exists:', !!changeBtn);
  console.log('Checkout button exists:', !!checkoutBtn);
  
  if (changeBtn) {
    console.log('Change button style:', window.getComputedStyle(changeBtn).pointerEvents);
    console.log('Change button disabled:', changeBtn.disabled);
  }
  
  if (checkoutBtn) {
    console.log('Checkout button style:', window.getComputedStyle(checkoutBtn).pointerEvents);
    console.log('Checkout button disabled:', checkoutBtn.disabled);
  }
  
  // Test clicking programmatically
  if (changeBtn) {
    console.log('Testing change button click...');
    changeBtn.click();
  }
  
  if (checkoutBtn) {
    console.log('Testing checkout button click...');
    checkoutBtn.click();
  }
}

// Make test function available globally
window.testButtons = testButtons;

// --- Dialog show/hide ------------------------------------------------------
// display and the .is-open class do different jobs. display keeps a hidden
// dialog out of the tab order; .is-open drives the CSS transition. They have
// to be set on separate frames in each direction:
//   opening - display first, then .is-open next frame, or the browser has no
//             start value to animate from and the card simply appears.
//   closing - .is-open first, then display once the transition has run, or
//             the exit is cut off at frame one.
var TW_MODAL_EXIT_MS = 150;   // matches --duration-fast in the stylesheet

function twModalIsOpen(el){ return !!el && el.classList.contains('is-open'); }

/* Page-behind scroll lock. ------------------------------------------------
 *
 * The overlay is position:fixed with its own overflow-y:auto, so the wheel
 * scrolls the DIALOG while the pointer is over the card. Anywhere else - the
 * dimmed margin around it - the wheel reaches <body> and the checkout page
 * scrolls underneath, which is what made the background move behind the Add
 * New Address form.
 *
 * Reference-counted rather than a boolean: these dialogs STACK (the picker
 * stays mounted under the form), so closing the form must not unlock the page
 * while the picker is still open. The lock lifts only when the last .tw-modal
 * closes.
 *
 * The padding-right compensation replaces the width the scrollbar was taking.
 * Without it, hiding the scrollbar widens the viewport and the whole page
 * jumps sideways as the dialog opens. */
function twSyncScrollLock(){
  var anyOpen = !!document.querySelector('.tw-modal.is-open');
  var b = document.body;
  if(anyOpen && !b.__twLocked){
    var sbw = window.innerWidth - document.documentElement.clientWidth;
    b.__twPrevOverflow = b.style.overflow;
    b.__twPrevPadRight = b.style.paddingRight;
    b.style.overflow = 'hidden';
    if(sbw > 0){ b.style.paddingRight = sbw + 'px'; }
    b.__twLocked = true;
  } else if(!anyOpen && b.__twLocked){
    b.style.overflow = b.__twPrevOverflow || '';
    b.style.paddingRight = b.__twPrevPadRight || '';
    b.__twLocked = false;
  }
}

function twModalShow(el){
  if(!el) return;
  if(el.__twHideTimer){ clearTimeout(el.__twHideTimer); el.__twHideTimer = null; }
  el.style.display = 'block';
  // Double rAF: one frame to let the browser apply display and compute the
  // closed styles, a second to flip the class so a transition actually runs.
  requestAnimationFrame(function(){
    requestAnimationFrame(function(){ el.classList.add('is-open'); twSyncScrollLock(); });
  });
}

function twModalHide(el, onHidden){
  if(!el) return;
  // Already hidden: run the callback anyway so callers can rely on it.
  if(el.style.display === 'none'){ if(onHidden) onHidden(); return; }
  el.classList.remove('is-open');
  twSyncScrollLock();   // re-checked: a stacked dialog underneath keeps the lock
  if(el.__twHideTimer){ clearTimeout(el.__twHideTimer); }
  // Timer rather than transitionend: transitionend never fires if the element
  // is hidden mid-flight or the user has transitions off, and the dialog would
  // then be stuck visible but inert.
  el.__twHideTimer = setTimeout(function(){
    el.__twHideTimer = null;
    el.style.display = 'none';
    twSyncScrollLock();
    if(onHidden) onHidden();
  }, TW_MODAL_EXIT_MS);
}

function addrClose(){
  twModalHide(document.getElementById('addressModal'));
  console.log('Address modal closed');
  // The picker is the form's parent step - closing it must not leave the
  // form dialog floating over the checkout page on its own.
  addrFormClose();
}

// --- Add/Edit dialog -------------------------------------------------------
// Opened by showAddrForm(), which mounts the form template into #addrFormHost.
// The picker stays open underneath so Cancel reads as "back", not "start over".
function addrFormOpen(isEdit){
  var m = document.getElementById('addrFormModal');
  if(!m) return;
  var t = document.getElementById('addrFormTitle');
  if(t){ t.textContent = isEdit ? 'Edit Address' : 'Add New Address'; }
  twModalShow(m);
  // Land on the first field rather than wherever the last dialog left focus.
  // preventScroll matters here: without it the browser jumps the scroll
  // container to the field mid-animation and the pop reads as a lurch.
  var first = m.querySelector('.addr-form .fn');
  if(first){ try { first.focus({ preventScroll: true }); } catch(_e){ first.focus(); } }
}

function addrFormClose(){
  var m = document.getElementById('addrFormModal');
  if(!m) return;
  // Emptying the host is deferred until the exit has played - tearing the
  // card's contents out on frame one would animate an empty box.
  twModalHide(m, function(){
    // Drop the form entirely instead of hiding it: it owns a Leaflet map, a
    // geolocation watcher and its own autocomplete listeners, and reopening
    // always builds a fresh one from the template.
    var host = document.getElementById('addrFormHost');
    if(host){ host.innerHTML = ''; }
  });
}
function addrConfirm(){
  var selected = document.querySelector('input[name="pick_addr_radio"]:checked');
  if(!selected){
    // if no selection, try currently open form (unsaved)
    var form = document.querySelector('#addrFormHost .addr-form');
    if(form){
      var fn=form.querySelector('.fn'); var ph=form.querySelector('.ph'); var reg=form.querySelector('.region'); var pc=form.querySelector('.postal'); var st=form.querySelector('.street');
      var name=fn?fn.value.trim():''; var phone=ph?ph.value.trim():''; var region=reg?reg.value.trim():''; var postal=pc?pc.value.trim():''; var street=st?st.value.trim():'';
      var phoneDigits = phone.replace(/\D/g,'').replace(/^63/,'');
      if(!name||phoneDigits.length!==10||!region||!street){ showNotification('Please complete the address form with a valid 10-digit PH phone number','error'); return; }
      var unsavedPin = form.__pinCtrl ? form.__pinCtrl.getPin() : null;
      // Same requirement as Submit - Confirm must not be a way around it.
      if(!unsavedPin){ showNotification('Please pin your exact location on the map','error'); return; }
      var text = street + "\n" + region + (postal?(', '+postal):'');
      applyAddress({name:name, phone:phone, text:text, lat: unsavedPin.lat, lng: unsavedPin.lng});
      addrClose();
      return;
    }
    // else use default or first
    if(addressBook.length){
      var def = addressBook.find(a=>a.isDefault) || addressBook[0];
      applyAddress(def);
      addrClose();
      return;
    }
    showNotification('Please select or add an address','error');
    return;
  }
  var row = selected.closest('.addr-row');
  var name = row.querySelector('input.name').value.trim();
  var phone = row.querySelector('input.phone').value.trim();
  var text = row.querySelector('input.text').value.trim();
  var lat = row.querySelector('input.lat').value.trim();
  var lng = row.querySelector('input.lng').value.trim();
  if(!name || !phone || !text){ showNotification('Please complete the selected address','error'); return; }
  applyAddress({name, phone, text, lat: lat === '' ? null : parseFloat(lat), lng: lng === '' ? null : parseFloat(lng)});
  addrClose();
}

var ADDR_PROMPT = 'Enter your delivery address';

/* Is there actually a deliverable address on the page right now? */
function hasDeliveryAddress(){
  var ad = document.getElementById('address');
  return !!(ad && ad.value && ad.value.trim());
}

/* Switches the panel between "you have an address" and "you have none".
 *
 * The button reads Add rather than Change when there is nothing to change - a
 * first-time customer reads "Change" as "something is already set", which is
 * the very misunderstanding that let an order reach the server with a
 * placeholder for an address. The Default badge hides for the same reason:
 * there is no default yet to be default. */
function reflectAddressState(){
  var has = hasDeliveryAddress();
  var btn = document.getElementById('changeAddressBtn');
  if(btn){
    btn.textContent = has ? 'Change' : 'Add';
    btn.classList.toggle('is-add', !has);
  }
  var badge = document.querySelector('.delivery-address-display .addr-badge');
  if(badge){ badge.style.display = has ? '' : 'none'; }
  var panel = document.querySelector('.delivery-address-display');
  if(panel){ panel.classList.toggle('addr-missing', !has); }
  if(has){
    var sec = document.getElementById('deliveryAddressSection');
    if(sec){ sec.classList.remove('addr-error'); }
  }
}

function applyAddress(a){
  var parts = (a.text||'').split(/\n/);
  var line1 = parts[0] || '';
  var line2 = parts.slice(1).join(' ').trim();
  var dn=document.getElementById('addrNameDisp'); if(dn) dn.textContent=a.name;
  var dp=document.getElementById('addrPhoneDisp'); if(dp) dp.textContent=a.phone;
  // Display falls back to the prompt; the hidden field below never does.
  var d1=document.getElementById('addrText1Disp'); if(d1) d1.textContent = line1 || ADDR_PROMPT;
  var d2=document.getElementById('addrText2Disp'); if(d2) d2.textContent=line2;
  var n=document.getElementById('full_name'); if(n) n.value=a.name;
  var p=document.getElementById('phone'); if(p) p.value=a.phone;
  var ad=document.getElementById('address'); if(ad) ad.value=(line1 && line2) ? (line1+', '+line2) : (line1||line2);
  var a1=document.getElementById('address_line1'); if(a1) a1.value=line1;
  var a2=document.getElementById('address_line2'); if(a2) a2.value=line2;
  // The map pin travels with the order, not just with the address book - see
  // the hidden inputs in the checkout form and the snapshot in
  // process_order.php. Blank when unpinned so the server stores NULL rather
  // than a leftover coordinate from a previously selected address.
  var hasPin = (a.lat != null && a.lng != null && !isNaN(a.lat) && !isNaN(a.lng));
  var gl=document.getElementById('delivery_latitude'); if(gl) gl.value = hasPin ? a.lat : '';
  var gg=document.getElementById('delivery_longitude'); if(gg) gg.value = hasPin ? a.lng : '';
  var pinNote=document.getElementById('addrPinDisp');
  if(pinNote){ pinNote.style.display = hasPin ? '' : 'none'; }
  reflectAddressState();
}

// In-page address book with default, add, delete, edit
var addressBook = [];
var addressStorageKey = 'tw_addrbook_'+<?= json_encode((string)$userId ?: 'guest') ?>;
function initAddressBook(){
  // try load from storage first
  try {
    var stored = localStorage.getItem(addressStorageKey);
    if(stored){ addressBook = JSON.parse(stored) || []; }
  } catch(e){ addressBook = []; }
  if(!Array.isArray(addressBook)) addressBook = [];
  
  // seed from current user values as default if empty
  if(addressBook.length===0){
    var seed = { id: Date.now(), name: <?= json_encode($defaultAddressName) ?>, phone: <?= json_encode($defaultAddressPhone) ?>, region: '', postal: '', street: '', text: <?= json_encode($defaultAddressText) ?>, label: 'Home', isDefault: true };
    if(seed.name || seed.phone || seed.text){ 
      addressBook = [seed]; 
      saveAddressStore();
      // Apply the default address immediately
      applyAddress(seed);
    }
  } else {
    // Apply the default address from existing address book
    var defaultAddr = addressBook.find(a => a.isDefault) || addressBook[0];
    if(defaultAddr) {
      applyAddress(defaultAddr);
    }
  }
  
  // Load from backend if available
  fetch('<?= WEB_ROOT ?>public/authenticated/api/addresses.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' }})
    .then(r=>r.ok?r.json():Promise.reject())
    .then(d=>{ 
      if(d && d.success){ 
        addressBook = d.addresses.map(function(a){ 
          return { 
            id: parseInt(a.id), 
            name: a.full_name, 
            phone: a.phone, 
            region: a.region, 
            postal: a.postal, 
            street: a.street, 
            label: a.label, 
            lat: (a.latitude == null || a.latitude === '') ? null : parseFloat(a.latitude),
            lng: (a.longitude == null || a.longitude === '') ? null : parseFloat(a.longitude),
            isDefault: a.is_default==1, 
            text: (a.street+'\n'+a.region+(a.postal?(', '+a.postal):'')) 
          }; 
        }); 
        renderAddrList();
        saveAddressStore();

        // Apply the default address from backend. If the server confirms there are
        // NO saved addresses, a stale localStorage cache from a previous visit must
        // not keep overriding the display - fall back to the live profile data.
        var defaultAddr = addressBook.find(a => a.isDefault) || addressBook[0];
        if(defaultAddr) {
          applyAddress(defaultAddr);
        } else {
          applyAddress({ name: <?= json_encode($defaultAddressName) ?>, phone: <?= json_encode($defaultAddressPhone) ?>, text: <?= json_encode($defaultAddressText) ?> });
        }
      } else {
        renderAddrList(); 
      } 
    })
    .catch(()=>{ 
      renderAddrList(); 
    });
    
  // Guarded: initAddressBook() runs on every click of the Change button, so
  // an unguarded bind stacks a new listener each time and one tap of "Add New
  // Address" would rebuild the form once per previous open.
  var addBtn = document.getElementById('addrAddBtn');
  if(addBtn && !addBtn.__wired){
    addBtn.__wired = true;
    addBtn.addEventListener('click', function(){ showAddrForm(); });
  }

  var formCloseBtn = document.getElementById('addrFormCloseBtn');
  if(formCloseBtn && !formCloseBtn.__wired){
    formCloseBtn.__wired = true;
    formCloseBtn.addEventListener('click', function(){ addrFormClose(); renderAddrList(); });
  }

  // Escape unwinds one layer at a time - the form first, then the picker -
  // so it never discards a half-typed address and closes the whole thing.
  if(!document.__addrEscWired){
    document.__addrEscWired = true;
    document.addEventListener('keydown', function(e){
      if(e.key !== 'Escape') return;
      var fm = document.getElementById('addrFormModal');
      if(twModalIsOpen(fm)){ addrFormClose(); renderAddrList(); return; }
      var am = document.getElementById('addressModal');
      if(twModalIsOpen(am)){ addrClose(); }
    });
  }
}

var lastCreatedId = null;
function renderAddrList(){
  var list = document.getElementById('addrList'); if(!list) return;
  list.innerHTML='';
  addressBook.forEach(function(a, i){
    var tpl = document.getElementById('addrRowTpl').content.cloneNode(true);
    var row = tpl.querySelector('.addr-row');
    var radio = row.querySelector('input[name="pick_addr_radio"]');
    radio.value = String(a.id);
    row.querySelector('.nm').textContent = a.name || '';
    row.querySelector('.ph').textContent = a.phone ? ' ('+a.phone+')' : '';
    var text1 = a.street || '';
    var text2 = (a.region || '') + (a.postal? (', '+a.postal) : '');
    row.querySelector('.tx').textContent = (text1 && text2) ? (text1+' | '+text2) : (text1||text2);
    row.querySelector('input.name').value = a.name || '';
    row.querySelector('input.phone').value = a.phone || '';
    row.querySelector('input.text').value = ((text1 && text2) ? (text1+'\n'+text2) : (text1||text2));
    var hasPin = (a.lat != null && a.lng != null);
    row.querySelector('input.lat').value = hasPin ? a.lat : '';
    row.querySelector('input.lng').value = hasPin ? a.lng : '';
    if(hasPin){ row.querySelector('.pinned').style.display = 'inline-block'; }
    if(a.isDefault){
      row.querySelector('.def').style.display='inline-block';
      radio.checked = true;
    }
    row.querySelector('.del').addEventListener('click', function(){ deleteAddress(a.id); });
    row.querySelector('.edit').addEventListener('click', function(){ editAddress(a.id); });
    list.appendChild(tpl);
  });
  var emptyNote = document.getElementById('addrEmptyNote');
  if(emptyNote){ emptyNote.style.display = addressBook.length ? 'none' : 'block'; }

  // auto-select newly created address
  if(lastCreatedId){
    var r = list.querySelector('input[name="pick_addr_radio"][value="'+String(lastCreatedId)+'"]');
    if(r){ r.checked = true; }
    lastCreatedId = null;
  }
  saveAddressStore();
}

// Generic suggestion-dropdown controller shared by the region and barangay
// autocomplete fields: handles open/close animation, ARIA state, rendering a
// list of selectable rows, and Up/Down/Enter/Escape keyboard navigation.
function createSuggestController(input, panel, statusEl){
  var selectHandlers = [];
  var activeIndex = -1;

  function open(){
    panel.style.display = 'block';
    requestAnimationFrame(function(){ panel.style.opacity = '1'; panel.style.transform = 'translateY(0)'; });
    input.setAttribute('aria-expanded', 'true');
  }
  function close(){
    panel.style.opacity = '0';
    panel.style.transform = 'translateY(-4px)';
    input.setAttribute('aria-expanded', 'false');
    setTimeout(function(){ if(input.getAttribute('aria-expanded') === 'false'){ panel.style.display = 'none'; } }, 150);
    activeIndex = -1;
  }
  function setActive(idx){
    var rows = panel.querySelectorAll('[role="option"]');
    rows.forEach(function(r, i){ r.style.background = (i === idx) ? 'rgba(96,165,250,.15)' : 'transparent'; });
    activeIndex = idx;
    if(rows[idx]){ rows[idx].scrollIntoView({ block: 'nearest' }); }
  }
  function setStatus(text){ statusEl.textContent = text || ''; }
  function clear(){ panel.innerHTML = ''; selectHandlers = []; activeIndex = -1; }
  // rows: array of { html, onSelect }
  function renderRows(rows){
    clear();
    if(!rows.length){ close(); return; }
    rows.forEach(function(row, idx){
      var el = document.createElement('div');
      el.setAttribute('role', 'option');
      el.setAttribute('tabindex', '-1');
      el.className = 'suggest-item';
      el.style.cssText = 'padding:10px 12px;cursor:pointer;color:var(--tw-ink, #e5e7eb);font-size:14px;border-bottom:1px solid rgba(var(--tw-veil-rgb, 255, 255, 255), .05)';
      el.innerHTML = row.html;
      el.addEventListener('mouseenter', function(){ setActive(idx); });
      el.addEventListener('mousedown', function(e){ e.preventDefault(); row.onSelect(); });
      panel.appendChild(el);
      selectHandlers.push(row.onSelect);
    });
    open();
  }
  input.addEventListener('keydown', function(e){
    if(panel.style.display === 'none' || !selectHandlers.length) return;
    if(e.key === 'ArrowDown'){ e.preventDefault(); setActive(Math.min(activeIndex + 1, selectHandlers.length - 1)); }
    else if(e.key === 'ArrowUp'){ e.preventDefault(); setActive(Math.max(activeIndex - 1, 0)); }
    else if(e.key === 'Enter'){ if(activeIndex >= 0 && selectHandlers[activeIndex]){ e.preventDefault(); selectHandlers[activeIndex](); } }
    else if(e.key === 'Escape'){ close(); }
  });
  document.addEventListener('mousedown', function(e){
    if(!panel.contains(e.target) && e.target !== input){ close(); }
  });
  return { open: open, close: close, setStatus: setStatus, renderRows: renderRows };
}

// Wires the autocomplete fields for one address form instance: region search
// + drill-down region categories + "Use My Current Location", and the barangay
// recommendations for whichever city that lands on. Street is a plain input.
function wireAddressAutocomplete(form, updateSaveButtonState, pinCtrl){
  var region = form.querySelector('.region');
  var postal = form.querySelector('.postal');
  var street = form.querySelector('.street');
  var barangay = form.querySelector('.barangay');
  var barangayPanel = form.querySelector('.barangay-suggest');
  var barangayStatus = form.querySelector('.barangay-status');
  var barangayClear = form.querySelector('.barangay-clear');
  var regionPanel = form.querySelector('.region-suggest');
  var regionStatus = form.querySelector('.region-status');
  var regionClear = form.querySelector('.region-clear');
  var streetClear = form.querySelector('.street-clear');

  var regionCtrl = createSuggestController(region, regionPanel, regionStatus);
  var barangayCtrl = createSuggestController(barangay, barangayPanel, barangayStatus);
  // Barangays for the currently-picked city, fetched once per city and then
  // filtered in the browser - typing must not fire a request per keystroke.
  var barangayList = [];

  function toggleClear(input, btn){ btn.style.display = input.value.trim() ? 'block' : 'none'; }
  toggleClear(region, regionClear);
  toggleClear(street, streetClear);
  toggleClear(barangay, barangayClear);

  // The region input stays the single source of truth for the location line;
  // the barangay dropdown just prefixes it. Keeping the city/region part in a
  // dataset attribute means re-picking a barangay rewrites the prefix instead
  // of stacking a second one on top.
  function composeRegionValue(){
    var base = region.dataset.cityLine || region.value.trim();
    var brgy = currentBarangay();
    region.value = brgy ? (brgy + ', ' + base) : base;
    toggleClear(region, regionClear);
    updateSaveButtonState();
  }

  function currentBarangay(){ return barangay.value.trim(); }

  // Fetches the picked city's barangays once and keeps them in memory. Nothing
  // is rendered here - the dropdown below only appears on focus or typing.
  function loadBarangays(locationId, preselect){
    barangayList = [];
    barangayStatus.textContent = '';
    AddressSuggestionService.getBarangays(locationId).then(function(list){
      barangayList = list || [];
      if(preselect){ barangay.value = preselect; toggleClear(barangay, barangayClear); }
      composeRegionValue();
    }).catch(function(){
      // A failed fetch just means no recommendations; the field still types.
      barangayList = [];
    });
  }

  // Recommendations for what is in the box: everything for an empty field,
  // prefix matches first as they type. Capped so the panel stays a short list
  // rather than 169 rows of Caloocan.
  function renderBarangaySuggestions(){
    var q = barangay.value.trim().toLowerCase();
    if(!barangayList.length){
      barangayStatus.textContent = region.dataset.locationId
        ? 'No barangay list for this city - type it in.'
        : 'Pick a city above to see barangay suggestions.';
      barangayCtrl.renderRows([]);
      return;
    }
    var matches = barangayList.filter(function(b){
      return !q || b.toLowerCase().indexOf(q) !== -1;
    }).sort(function(a, b){
      if(!q) return 0;
      var ai = a.toLowerCase().indexOf(q), bi = b.toLowerCase().indexOf(q);
      return ai - bi;
    }).slice(0, 12);
    barangayStatus.textContent = matches.length ? '' : 'No barangay matches that.';
    barangayCtrl.renderRows(matches.map(function(b){
      return {
        html: '<i class="fi fi-rr-marker" style="margin-right:8px;color:#f97316"></i>' + b,
        onSelect: function(){
          barangay.value = b;
          toggleClear(barangay, barangayClear);
          composeRegionValue();
          barangayCtrl.close();
        }
      };
    }));
  }

  function applyLocation(loc, regionName){
    region.dataset.cityLine = loc.name + ', ' + regionName;
    region.value = region.dataset.cityLine;
    region.dataset.locationId = loc.id;
    postal.value = loc.postal || '';
    barangay.value = '';
    toggleClear(barangay, barangayClear);
    toggleClear(region, regionClear);
    toggleClear(street, streetClear);
    updateSaveButtonState();
    // The curated PH dataset carries coordinates for its cities; hand them to
    // the pin map so the first "Open map" lands on the right city instead of
    // the middle of Manila. Cities without coordinates simply skip this.
    if(pinCtrl && loc.lat != null && loc.lng != null){ pinCtrl.setCenterHint(loc.lat, loc.lng); }
    loadBarangays(loc.id);
  }

  barangay.addEventListener('focus', renderBarangaySuggestions);
  barangay.addEventListener('input', function(){
    toggleClear(barangay, barangayClear);
    composeRegionValue();
    renderBarangaySuggestions();
  });
  barangayClear.addEventListener('click', function(){
    barangay.value = '';
    toggleClear(barangay, barangayClear);
    composeRegionValue();
    barangayCtrl.close();
  });

  // Editing a saved address: its region line already reads
  // "Barangay X, City, Region", so split the barangay back off, find the city
  // again and repopulate the dropdown with that barangay selected.
  (function restoreExisting(){
    var value = region.value.trim();
    if(!value) return;
    var parts = value.split(',').map(function(x){ return x.trim(); });
    var maybeBrgy = parts.length >= 3 ? parts.shift() : null;
    var cityLine = parts.join(', ');
    region.dataset.cityLine = cityLine;
    var cityName = parts[0] || '';
    if(!cityName) return;
    AddressSuggestionService.searchLocations(cityName).then(function(results){
      var hit = results.find(function(r){ return r.location.name.toLowerCase() === cityName.toLowerCase(); });
      if(!hit) return;
      region.dataset.locationId = hit.location.id;
      loadBarangays(hit.location.id, maybeBrgy);
    }).catch(function(){ /* leave the field as typed */ });
  })();

  function renderRegionCategories(){
    regionStatus.textContent = 'Loading regions…';
    AddressSuggestionService.getRegions().then(function(regions){
      regionStatus.textContent = '';
      var rows = regions.map(function(r){
        return {
          html: '<i class="fi fi-rr-marker" style="margin-right:8px;color:#f97316"></i>' + r.name,
          onSelect: function(){ renderRegionCities(r.id, r.name); }
        };
      });
      rows.unshift({
        html: '<i class="fi fi-rr-marker" style="margin-right:8px;color:#f97316"></i><strong>Use My Current Location</strong>',
        onSelect: useCurrentLocation
      });
      regionCtrl.renderRows(rows);
    }).catch(function(){
      regionStatus.textContent = 'Could not load regions. Please try again.';
    });
  }

  function renderRegionCities(regionId, regionName){
    regionStatus.textContent = 'Loading locations…';
    AddressSuggestionService.getLocationsByRegion(regionId).then(function(locations){
      regionStatus.textContent = locations.length ? '' : 'No locations found for this region.';
      var rows = locations.map(function(loc){
        return {
          html: loc.name + '<span style="color:var(--text-muted);font-size:12px;margin-left:6px">' + loc.postal + '</span>',
          onSelect: function(){ applyLocation(loc, regionName); regionCtrl.close(); }
        };
      });
      rows.unshift({
        html: '<i class="fi fi-rr-arrow-left" style="margin-right:8px"></i>Back to regions',
        onSelect: renderRegionCategories
      });
      regionCtrl.renderRows(rows);
    }).catch(function(){
      regionStatus.textContent = 'Could not load locations. Please try again.';
    });
  }

  function searchRegionInput(query){
    regionStatus.textContent = 'Searching…';
    AddressSuggestionService.searchLocations(query).then(function(results){
      regionStatus.textContent = results.length ? '' : 'No matches found.';
      var rows = results.map(function(r){
        return {
          html: r.label + '<span style="color:var(--text-muted);font-size:12px;margin-left:6px">' + r.location.postal + '</span>',
          onSelect: function(){ applyLocation(r.location, r.region_name); regionCtrl.close(); }
        };
      });
      regionCtrl.renderRows(rows);
    }).catch(function(){
      regionStatus.textContent = 'Location search is unavailable right now.';
    });
  }
  var debouncedRegionSearch = AddressSuggestionService.debounce(searchRegionInput, 300);

  function useCurrentLocation(){
    regionStatus.textContent = 'Getting your location…';
    AddressSuggestionService.getCurrentPosition().then(function(pos){
      regionStatus.textContent = 'Looking up your address…';
      return AddressSuggestionService.reverseGeocode(pos.lat, pos.lng);
    }).then(function(result){
      regionStatus.textContent = '';
      applyLocation(result.location, result.region_name);
      regionCtrl.close();
      showNotification('Location detected: ' + result.location.name + ', ' + result.region_name, 'success');
    }).catch(function(err){
      regionStatus.textContent = '';
      var msg = (err && err.message) ? err.message : 'Could not detect your location. Please select it manually.';
      showNotification(msg, 'error');
    });
  }

  region.addEventListener('focus', function(){
    if(!region.value.trim()){ renderRegionCategories(); }
  });
  region.addEventListener('input', function(){
    toggleClear(region, regionClear);
    updateSaveButtonState();
    var q = region.value.trim();
    if(!q){ renderRegionCategories(); return; }
    if(q.length >= 1){ debouncedRegionSearch(q); }
  });
  regionClear.addEventListener('click', function(){
    region.value = '';
    delete region.dataset.locationId;
    delete region.dataset.cityLine;
    barangay.value = '';
    barangayList = [];
    toggleClear(barangay, barangayClear);
    barangayStatus.textContent = '';
    toggleClear(region, regionClear);
    updateSaveButtonState();
    region.focus();
    renderRegionCategories();
  });

  // Street / building / house number is typed by hand, like the postal code
  // next to it: an exact house number is not something a suggestion list can
  // know, and the landmark lookup that used to sit here mostly answered
  // "No matches found." while the customer was still mid-sentence.
  street.addEventListener('input', function(){
    toggleClear(street, streetClear);
    updateSaveButtonState();
  });
  streetClear.addEventListener('click', function(){
    street.value = '';
    toggleClear(street, streetClear);
    updateSaveButtonState();
    street.focus();
  });
}

// Philippines-only phone number: locks input to a "+63 " prefix so
// customers can only type the 10-digit local number after it.
function toPHPhone(raw){
  var digits = (raw||'').replace(/\D/g,'');
  if(digits.indexOf('63')===0) digits = digits.slice(2);
  if(digits.indexOf('0')===0) digits = digits.slice(1);
  digits = digits.slice(0,10);
  return '+63' + (digits ? ' '+digits : ' ');
}
function bindPHPhoneInput(input){
  input.addEventListener('input', function(){ input.value = toPHPhone(input.value); });
  input.addEventListener('focus', function(){ if(!input.value) input.value = '+63 '; });
  input.addEventListener('keydown', function(e){
    // Block backspace/delete from eating into the "+63 " prefix.
    if((e.key==='Backspace' || e.key==='Delete') && input.selectionStart<=4 && input.selectionEnd<=4){
      e.preventDefault();
    }
  });
}

// ---------------------------------------------------------------------------
// Exact-location pin
// ---------------------------------------------------------------------------
// Leaflet + OpenStreetMap, the same keyless stack track_order.php and the
// courier board already use. Loaded on demand rather than in <head>: the map
// lives inside a modal inside a form that most checkouts never open, and the
// tiles are a real download on mobile data.
var leafletPromise = null;
function ensureLeaflet(){
  if(window.L){ return Promise.resolve(window.L); }
  if(leafletPromise){ return leafletPromise; }
  leafletPromise = new Promise(function(resolve, reject){
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    css.integrity = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
    css.crossOrigin = '';
    document.head.appendChild(css);

    var js = document.createElement('script');
    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    js.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
    js.crossOrigin = '';
    js.onload = function(){ resolve(window.L); };
    js.onerror = function(){ leafletPromise = null; reject(new Error('map-unavailable')); };
    document.head.appendChild(js);
  });
  return leafletPromise;
}

// Metro Manila. Only ever used as the opening view of an empty map - never
// saved as a pin, because a default coordinate is exactly the kind of
// confidently-wrong data this feature exists to prevent.
var PIN_FALLBACK = { lat: 14.5995, lng: 120.9842, zoom: 11 };

/**
 * Wires the .addr-pin block inside one address form.
 * Returns { getPin(), setCenterHint(lat,lng) }; getPin() is the single source
 * of truth the save handler reads, and returns null when nothing is pinned.
 */
/**
 * Wires the .addr-pin block inside one address form.
 * `onChange` is the form's updateSaveButtonState - the pin is a required
 * field now, so every change to it has to re-evaluate Submit.
 */
function wireAddressPin(form, existing, onChange){
  var wrap      = form.querySelector('.addr-pin');
  if(!wrap){ return { getPin: function(){ return null; }, setCenterHint: function(){}, activate: function(){} }; }
  var mapEl     = wrap.querySelector('.pin-map');
  var readout   = wrap.querySelector('.pin-readout');
  var clearBtn  = wrap.querySelector('.pin-clear');
  var locateBtn = wrap.querySelector('.pin-locate');
  var findBtn   = wrap.querySelector('.pin-find');
  var hint      = wrap.querySelector('.pin-hint');

  var map = null, marker = null;
  var pin = null;                 // {lat, lng} or null - what actually gets saved
  var centerHint = null;          // city coords from the region picker, for first open

  if(existing && existing.lat != null && existing.lng != null){
    pin = { lat: Number(existing.lat), lng: Number(existing.lng) };
  }

  function describe(){
    if(!pin){
      readout.textContent = 'Required - tap the map to pin your exact location.';
      readout.style.color = '#f87171';
      clearBtn.style.display = 'none';
      hint.textContent = 'Tap the map to drop a pin on your gate, then drag it to fine-tune.';
    } else {
      readout.textContent = 'Pinned at ' + pin.lat.toFixed(5) + ', ' + pin.lng.toFixed(5) + ' - drag the marker to adjust.';
      readout.style.color = '#4ade80';
      clearBtn.style.display = '';
      hint.textContent = 'The rider navigates here, whatever the street line says.';
    }
    // Submit depends on this now.
    if(onChange){ onChange(); }
  }

  function placeMarker(lat, lng, zoom){
    pin = { lat: lat, lng: lng };
    if(map){
      if(marker){
        marker.setLatLng([lat, lng]);
      } else {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        // Dragging is the whole point: the geocoded guess lands on the street,
        // the customer nudges it onto their actual gate.
        marker.on('dragend', function(){
          var p = marker.getLatLng();
          pin = { lat: p.lat, lng: p.lng };
          describe();
        });
      }
      map.setView([lat, lng], zoom || Math.max(map.getZoom(), 17));
    }
    describe();
  }

  // Cached, not just guarded on `map`: activate() and "Use my location" can
  // both ask for the map while the first build is still inside
  // ensureLeaflet(). Two L.map() calls on one container is a hard Leaflet
  // error, so every caller shares one promise.
  var mapPromise = null;
  function buildMap(){
    if(map){ return Promise.resolve(map); }
    if(mapPromise){ return mapPromise; }
    readout.textContent = 'Loading map...';
    mapPromise = ensureLeaflet().then(function(){
      var start = pin || centerHint || PIN_FALLBACK;
      map = L.map(mapEl, { scrollWheelZoom: false }).setView(
        [start.lat, start.lng],
        pin ? 17 : (centerHint ? 14 : PIN_FALLBACK.zoom)
      );
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);
      // Tap anywhere to pin - faster than dragging a marker across the screen.
      map.on('click', function(e){ placeMarker(e.latlng.lat, e.latlng.lng, map.getZoom()); });
      if(pin){ placeMarker(pin.lat, pin.lng, 17); }
      describe();
      return map;
    }).catch(function(){
      // Let a later attempt retry rather than caching the failure forever.
      mapPromise = null;
      // The pin is required, so a dead map is a blocked form. Say what to do
      // about it rather than just reporting the failure.
      readout.textContent = 'The map could not load. Check your connection and reopen this form.';
      readout.style.color = '#f87171';
      return null;
    });
    return mapPromise;
  }

  // The map is always mounted now, so callers just wait for the one build.
  function withMap(fn){
    return buildMap().then(function(m){ if(m){ fn(m); } });
  }

  locateBtn.addEventListener('click', function(){
    readout.textContent = 'Getting your location...';
    readout.style.color = '#9ca3af';
    AddressSuggestionService.getCurrentPosition().then(function(pos){
      return withMap(function(){ placeMarker(pos.lat, pos.lng, 18); });
    }).catch(function(err){
      readout.textContent = (err && err.message) ? err.message : 'Could not get your location.';
      readout.style.color = '#f87171';
    });
  });

  // --- "Find typed address" ------------------------------------------------
  // Nominatim's free-text search is all-or-nothing, and the everything-at-once
  // query these fields compose reliably returns zero rows:
  //   "Blk 12 Lot 3 Sampaguita St., Barangay 171, Caloocan,
  //    National Capital Region (NCR), 1425, Philippines"   -> []
  //   "Sampaguita St., Caloocan, National Capital Region, Philippines"
  //                                                        -> 14.72897, 121.02558
  // The barangay and the postal code are what kill it - PH barangay names are
  // sparse in OSM and a postal code on its own matches a different city
  // entirely ("1425, Philippines" lands in Quezon City). So instead of one
  // shot, walk from most specific to least and stop at the first hit. Landing
  // on the right street, or even the right city, is a usable starting point:
  // the customer drags the pin the last few metres, which is the job.

  // "(NCR)" and friends hurt matching and carry nothing.
  function stripParens(v){ return String(v || '').replace(/\s*\([^)]*\)/g, ' ').replace(/\s{2,}/g, ' ').trim(); }

  // "Blk 12 Lot 3 Sampaguita St." -> "Sampaguita St.". House, block, lot and
  // unit numbers are not in OSM; the street they sit on is.
  function streetNameOnly(v){
    var out = String(v || '').trim();
    var prefix = /^(blk\.?|block|lot|unit|rm\.?|room|door|house|no\.?|#)\s*[\w-]+[\s,.]*/i;
    for(var i = 0; i < 4 && prefix.test(out); i++){ out = out.replace(prefix, ''); }
    return out.replace(/^[\d-]+\s+/, '').trim();
  }

  function geocodeLadder(){
    var streetRaw = ((form.querySelector('.street') || {}).value || '').trim();
    var regionEl  = form.querySelector('.region');
    var brgyEl    = form.querySelector('.barangay');
    var regionRaw = stripParens(regionEl ? regionEl.value : '');
    // composeRegionValue() prefixes the barangay onto the visible region line,
    // but the bare "City, Region" stays on the dataset - and that is the half
    // the geocoder can actually match.
    var cityLine  = stripParens((regionEl && regionEl.dataset.cityLine) || regionRaw);
    var brgy      = stripParens(brgyEl ? brgyEl.value : '');
    var streetOnly = streetNameOnly(streetRaw);

    function join(){
      return Array.prototype.slice.call(arguments)
        .map(function(v){ return String(v || '').trim(); })
        .filter(Boolean)
        .concat('Philippines')
        .join(', ');
    }

    var rungs = [];
    if(streetRaw && cityLine){ rungs.push({ q: join(streetRaw, cityLine),  precision: 'street' }); }
    if(streetOnly && streetOnly !== streetRaw && cityLine){ rungs.push({ q: join(streetOnly, cityLine), precision: 'street' }); }
    if(brgy && cityLine){ rungs.push({ q: join(brgy, cityLine), precision: 'barangay' }); }
    if(cityLine){ rungs.push({ q: join(cityLine), precision: 'city' }); }
    // Last resort when no city was ever picked. Deliberately no bare-postal
    // rung: it matches confidently and wrongly.
    if(!rungs.length && regionRaw){ rungs.push({ q: join(regionRaw), precision: 'city' }); }
    // Same query twice in a row helps nobody and costs a request.
    var seen = {};
    return rungs.filter(function(r){ if(seen[r.q]) return false; seen[r.q] = 1; return true; });
  }

  var PRECISION_NOTE = {
    street:   'Found the street - drag the marker onto your exact gate.',
    barangay: 'Found your barangay, not the street - drag the marker onto your gate.',
    city:     'Only the city could be matched - drag the marker onto your gate.'
  };

  findBtn.addEventListener('click', function(){
    var rungs = geocodeLadder();
    if(!rungs.length){
      readout.textContent = 'Fill in the address fields above first.';
      readout.style.color = '#f87171';
      return;
    }
    if(typeof DeliveryRoute === 'undefined'){
      readout.textContent = 'Address lookup is unavailable - tap the map to pin it yourself.';
      readout.style.color = '#f87171';
      return;
    }
    readout.textContent = 'Looking up that address...';
    readout.style.color = '#9ca3af';
    findBtn.disabled = true;

    // Sequential, stopping at the first hit. Nominatim asks for at most one
    // request a second; DeliveryRoute.geocode() caches hits in sessionStorage,
    // so a repeat lookup of the same address resolves without a request.
    function attempt(i){
      if(i >= rungs.length){ return Promise.resolve(null); }
      return DeliveryRoute.geocode(rungs[i].q).then(function(hit){
        if(hit){ return { hit: hit, precision: rungs[i].precision }; }
        if(i + 1 >= rungs.length){ return null; }
        return new Promise(function(r){ setTimeout(r, 1100); }).then(function(){ return attempt(i + 1); });
      });
    }

    attempt(0).then(function(found){
      findBtn.disabled = false;
      if(!found){
        readout.textContent = 'That address could not be found - tap the map to pin it yourself.';
        readout.style.color = '#f87171';
        return;
      }
      // A city-level hit is a wide area, so open out rather than diving to
      // street zoom on a point that is only roughly right.
      var zoom = found.precision === 'street' ? 17 : (found.precision === 'barangay' ? 15 : 13);
      return withMap(function(){
        placeMarker(found.hit.lat, found.hit.lng, zoom);
        readout.textContent = PRECISION_NOTE[found.precision];
        readout.style.color = '#fbbf24';
      });
    }).catch(function(){
      findBtn.disabled = false;
      readout.textContent = 'Address lookup failed - tap the map to pin it yourself.';
      readout.style.color = '#f87171';
    });
  });

  // Drops the pin so a new one can be placed. Submit goes back to disabled
  // until there is one - describe() calls onChange().
  clearBtn.addEventListener('click', function(){
    pin = null;
    if(marker && map){ map.removeLayer(marker); marker = null; }
    describe();
  });

  describe();

  return {
    getPin: function(){ return pin; },
    // Builds the map. Called once the dialog is actually visible: Leaflet
    // measures its container on creation, and a container inside a
    // display:none dialog measures zero, which is how you get grey tiles.
    activate: function(){
      buildMap().then(function(m){
        if(m){ setTimeout(function(){ m.invalidateSize(); }, 60); }
      });
    },
    // Called by the region picker when a city is chosen, so an un-pinned map
    // moves to that city instead of sitting over the middle of Manila.
    setCenterHint: function(lat, lng){
      if(lat == null || lng == null) { return; }
      centerHint = { lat: Number(lat), lng: Number(lng) };
      if(map && !pin){ map.setView([centerHint.lat, centerHint.lng], 14); }
    }
  };
}

function showAddrForm(existing){
  // Mounts into the dedicated dialog (#addrFormModal), not into the address
  // list: inline, this form turned the picker into a page-tall column and
  // pushed its Confirm footer out of the clipped card.
  var host = document.getElementById('addrFormHost');
  if(!host){ return; }
  host.innerHTML = '';   // only ever one form alive at a time
  var tpl = document.getElementById('addrFormTpl').content.cloneNode(true);
  var form = tpl.querySelector('.addr-form');
  var fn = form.querySelector('.fn');
  var ph = form.querySelector('.ph');
  var region = form.querySelector('.region');
  var postal = form.querySelector('.postal');
  var street = form.querySelector('.street');
  var lblButtons = form.querySelectorAll('.lbl');
  var asDefault = form.querySelector('.asDefault');
  var activeLabel = 'Home';
  lblButtons.forEach(function(b){ b.addEventListener('click', function(){ lblButtons.forEach(bb=>{bb.classList.remove('active'); bb.style.borderColor='rgba(255,255,255,.2)';}); b.classList.add('active'); b.style.borderColor='#a77693'; activeLabel = b.dataset.label; }); });
  bindPHPhoneInput(ph);

  /* What is still missing, in the order the fields appear.
   *
   * One list, used by both the click handler and the live clearing below, so
   * the marks can never disagree with whether the form actually submits. */
  function addrFormProblems(){
    var phDigits = ph.value.replace(/\D/g,'').replace(/^63/,'');
    // The map pin is a required field. Read it off the form rather than a
    // closure variable: wireAddressPin() runs after this function is defined
    // and calls straight back into it, so the reference has to be late-bound.
    var hasPin = !!(form.__pinCtrl && form.__pinCtrl.getPin());
    var out = [];
    if(!fn.value.trim())        { out.push({el: fn,     label: 'your full name'}); }
    if(phDigits.length !== 10)  { out.push({el: ph,     label: 'a 10-digit mobile number after +63'}); }
    if(!region.value.trim())    { out.push({el: region, label: 'your region, city and barangay'}); }
    if(!postal.value.trim())    { out.push({el: postal, label: 'your postal code'}); }
    if(!street.value.trim())    { out.push({el: street, label: 'your street, building or house no.'}); }
    if(!hasPin)                 { out.push({el: null,   label: 'a pin on the map', pin: true}); }
    return out;
  }

  /* Marks the offending fields and says what is missing.
   *
   * Red borders alone leave "which one is wrong" to be worked out from colour;
   * a sentence alone leaves "where" unanswered on a form this long. Both, plus
   * focus on the first offender, answers both questions at once.
   *
   * The message is ONE line above the Submit row rather than per-field text:
   * .fn/.ph and .postal/.street live in CSS grids, so a message injected next
   * to them would become a grid item and shove the layout apart. */
  function showAddrFormErrors(problems){
    form.querySelectorAll('.field-invalid').forEach(function(el){ el.classList.remove('field-invalid'); });
    var pinBox = form.querySelector('.addr-pin');
    if(pinBox){ pinBox.classList.remove('field-invalid'); }

    problems.forEach(function(p){
      if(p.pin){ if(pinBox){ pinBox.classList.add('field-invalid'); } }
      else if(p.el){ p.el.classList.add('field-invalid'); }
    });

    var note = form.querySelector('.addr-form-msg');
    if(note){
      // Dot-separated, not "a, b and c": two of the labels contain commas of
      // their own ("your region, city and barangay"), so a prose list reads as
      // "...barangay and a pin" and the items run together.
      note.textContent = 'Still needed: ' + problems.map(function(p){ return p.label; }).join(' · ');
      note.hidden = false;
    }

    var first = problems[0];
    if(first && first.el){
      first.el.scrollIntoView({behavior:'smooth', block:'center'});
      try{ first.el.focus({preventScroll:true}); }catch(_e){ first.el.focus(); }
    } else if(first && first.pin && pinBox){
      pinBox.scrollIntoView({behavior:'smooth', block:'center'});
    }
  }

  /* Clears a mark the moment its field is filled, so the red does not outlive
   * the problem. The Submit button is NEVER disabled - a greyed button says
   * "you cannot continue" without saying why, which is the thing being fixed
   * here; clicking it is how you find out what is missing. */
  function updateSaveButtonState(){
    var stillWrong = addrFormProblems();
    var wrongEls = stillWrong.map(function(p){ return p.el; });
    var pinStillWrong = stillWrong.some(function(p){ return p.pin; });

    form.querySelectorAll('.field-invalid').forEach(function(el){
      if(el.classList.contains('addr-pin')){
        if(!pinStillWrong){ el.classList.remove('field-invalid'); }
      } else if(wrongEls.indexOf(el) === -1){
        el.classList.remove('field-invalid');
      }
    });

    var note = form.querySelector('.addr-form-msg');
    if(note && !note.hidden && stillWrong.length === 0){ note.hidden = true; }
  }
  fn.addEventListener('input', updateSaveButtonState);
  ph.addEventListener('input', updateSaveButtonState);
  postal.addEventListener('input', updateSaveButtonState);
  region.addEventListener('input', updateSaveButtonState);
  street.addEventListener('input', updateSaveButtonState);

  if(existing){
    fn.value=existing.name; ph.value=toPHPhone(existing.phone); region.value=existing.region||''; postal.value=existing.postal||''; street.value=existing.street||''; activeLabel = existing.label||'Home';
    lblButtons.forEach(function(b){ if(b.dataset.label===activeLabel){ b.classList.add('active'); b.style.borderColor='#a77693'; } });
    asDefault.checked = !!existing.isDefault;
  } else {
    ph.value = '+63 ';
  }
  // Wired after pre-filling `existing` values so the clear (x) buttons'
  // initial visibility reflects any pre-existing region/street text.
  // Built before the autocomplete so the region picker can hand it a city to
  // centre on the moment one is chosen.
  var pinCtrl = wireAddressPin(form, existing, function(){ updateSaveButtonState(); });
  form.__pinCtrl = pinCtrl;

  if(typeof AddressSuggestionService !== 'undefined'){
    wireAddressAutocomplete(form, updateSaveButtonState, pinCtrl);
  }
  updateSaveButtonState();
  form.querySelector('.cancel').addEventListener('click', function(){ addrFormClose(); renderAddrList(); });
  form.querySelector('.save').addEventListener('click', function(){
    // Single gate. The three separate checks that used to live here reported
    // one problem at a time, so a form missing three things took three clicks
    // to find that out.
    var problems = addrFormProblems();
    if(problems.length){
      showAddrFormErrors(problems);
      return;
    }
    var name=fn.value.trim(), phone=ph.value.trim();
    var reg=region.value.trim(), pc=postal.value.trim(), st=street.value.trim();
    var text = reg + '\n' + st + (pc? (', '+pc) : '');
    // null when nothing is pinned - stored as-is so "no pin" stays distinct
    // from a stale coordinate.
    var pinned = pinCtrl.getPin();
    
    if(existing){ 
      existing.name=name; 
      existing.phone=phone; 
      existing.region=reg; 
      existing.postal=pc; 
      existing.street=st; 
      existing.text=text; 
      existing.label=activeLabel; 
      existing.lat = pinned ? pinned.lat : null;
      existing.lng = pinned ? pinned.lng : null;
      existing.isDefault = asDefault.checked ? true : existing.isDefault; 
      saveAddressRemote('update', existing); 
    } else { 
      var id = Date.now(); 
      var obj = { 
        id, 
        name, 
        phone, 
        region: reg, 
        postal: pc, 
        street: st, 
        text, 
        label: activeLabel, 
        lat: pinned ? pinned.lat : null,
        lng: pinned ? pinned.lng : null,
        isDefault: asDefault.checked || addressBook.length===0
      }; 
      addressBook.push(obj); 
      lastCreatedId = id; 
      saveAddressRemote('create', obj); 
    }
    
    if(asDefault.checked){
      var last = addressBook[addressBook.length-1];
      addressBook.forEach(a=>{ a.isDefault = (a===last); });
      // Apply the new default address immediately
      applyAddress(last);
    }
    
    // Save to localStorage immediately
    saveAddressStore();
    // Back to the picker, with the new address rendered and selected.
    addrFormClose();
    renderAddrList();
    
    // Show success message
    showNotification('Address saved with your exact location pinned.', 'success');
  });
  host.appendChild(tpl);
  addrFormOpen(!!existing);
  // After the dialog is displayed, never before: Leaflet sizes itself from
  // the container, and a container in a display:none dialog measures zero.
  pinCtrl.activate();
}

function editAddress(id){
  var idx = addressBook.findIndex(a=>a.id===id); if(idx<0) return;
  showAddrForm(addressBook[idx]);
}

async function deleteAddress(id){
  if(!(await uiConfirm('Delete this address?'))) return;
  var idx = addressBook.findIndex(a=>a.id===id); if(idx<0) return;
  var removed = addressBook.splice(idx,1);
  if(addressBook.length>0 && !addressBook.some(a=>a.isDefault)) {
    addressBook[0].isDefault=true;
    // Apply the new default address
    applyAddress(addressBook[0]);
  }
  // Save changes immediately
  saveAddressStore();
  renderAddrList();
  showNotification('Address deleted successfully!', 'success');
}

function saveAddressStore(){
  try { localStorage.setItem(addressStorageKey, JSON.stringify(addressBook)); } catch(e){}
}

function saveAddressRemote(kind, addr){
  var url = '<?= WEB_ROOT ?>public/authenticated/api/addresses.php?action=' + (kind==='create'?'create':'update'+(addr.id?('&id='+encodeURIComponent(addr.id)):'') );
  var payload = { full_name: addr.name, phone: addr.phone, region: addr.region||'', postal: addr.postal||'', street: addr.street||'', label: addr.label||'Home', is_default: !!addr.isDefault, latitude: (addr.lat == null ? null : addr.lat), longitude: (addr.lng == null ? null : addr.lng) };
  
  fetch(url, { method: 'POST', headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' }, body: JSON.stringify(payload) })
    .then(r=>r.json())
    .then(function(d){
      // Success is d.success alone. Only 'create' returns an id, so the old
      // `d.success && d.id` test made every successful EDIT fall through to
      // the error branch and report a failure that had not happened.
      if(d && d.success){
        if(d.id){ addr.id = parseInt(d.id); }
        saveAddressStore();
        // Apply the address immediately if it's the default
        if(addr.isDefault) {
          applyAddress(addr);
        }
      } else {
        console.error('Failed to save address:', d);
        // Show what the server actually objected to - a missing map pin now
        // comes back as a specific 422, and "please try again" would send the
        // customer round the same loop forever.
        showNotification((d && d.message) ? d.message : 'Failed to save address. Please try again.', 'error');
      }
    })
    .catch(function(error) {
      console.error('Address save error:', error);
      showNotification('Network error. Address saved locally only.', 'error');
      // Still save locally even if remote fails
      saveAddressStore();
    });
}


// Delivery Method and Payment Method are two independent choices - the
// hidden `payment_method`/`delivery_method` form fields are derived from
// whichever combination of the two is currently selected.
function setActiveOption(groupId, inputId) {
  var group = document.getElementById(groupId);
  if (group) {
    group.querySelectorAll('.service-option').forEach(function(el) { el.classList.remove('active'); });
  }
  var radio = document.getElementById(inputId);
  if (radio) {
    radio.checked = true;
    var parent = radio.closest('.service-option');
    if (parent) { parent.classList.add('active'); }
  }
}

function selectDeliveryMethod(method) {
  setActiveOption('deliveryMethodGroup', method === 'delivery' ? 'delivery_home' : 'delivery_pickup');
  document.getElementById('delivery_method').value = method;

  // The "Cash" payment option's label depends on how the order is fulfilled.
  var cashName = document.getElementById('cashMethodName');
  var cashDesc = document.getElementById('cashMethodDesc');
  if (method === 'pickup') {
    if (cashName) cashName.textContent = 'Pay at Store';
    if (cashDesc) cashDesc.textContent = 'Pay with cash when you pick up your order at our store';
  } else {
    if (cashName) cashName.textContent = 'Cash on Delivery';
    if (cashDesc) cashDesc.textContent = "We'll deliver to your address and you pay when you receive your order";
  }

  recomputePaymentMethod();
  updateCheckoutState();
}

function selectPaymentChoice(choice) {
  setActiveOption('paymentMethodGroup', choice === 'cash' ? 'payment_cash' : 'payment_online');
  var epaySection = document.getElementById('epaymentChannelSection');
  if (epaySection) { epaySection.style.display = (choice === 'online') ? 'block' : 'none'; }

  checkoutShowCancelPolicy(choice);

  recomputePaymentMethod();
  updateCheckoutState();
  // Re-run so the credit lock re-evaluates against the method just picked.
  if (typeof checkoutRecalcTotals === 'function') { checkoutRecalcTotals(); }
}

// Exactly one of the three policy paragraphs is ever visible. 'credit' is
// not a payment_choice the customer can click - it is the state the credit
// lock puts the page into.
function checkoutShowCancelPolicy(which) {
  var paras = { cash: 'cancelPolicyCash', online: 'cancelPolicyOnline', credit: 'cancelPolicyCredit' };
  Object.keys(paras).forEach(function(key){
    var el = document.getElementById(paras[key]);
    if (el) { el.style.display = (key === which) ? 'block' : 'none'; }
  });
}

function selectEpaymentChannel(channel) {
  document.querySelectorAll('.epay-channel-option').forEach(function(el) {
    el.classList.toggle('active', el.getAttribute('data-channel') === channel);
  });
  var radio = document.getElementById('epay_' + channel);
  if (radio) { radio.checked = true; }

  var manualHint = document.getElementById('epayHintManual');
  var xenditHint = document.getElementById('epayHintXendit');
  if (manualHint) { manualHint.style.display = (channel === 'xendit') ? 'none' : 'block'; }
  if (xenditHint) { xenditHint.style.display = (channel === 'xendit') ? 'block' : 'none'; }

  recomputePaymentMethod();
}

// Combines the Delivery Method + Payment Method (+ e-payment channel, if
// applicable) choices into the single `payment_method` value process_order.php
// expects, and updates the submit button's label to match.
function recomputePaymentMethod() {
  var deliveryChoice = document.querySelector('input[name="delivery_choice"]:checked');
  var paymentChoice = document.querySelector('input[name="payment_choice"]:checked');
  var paymentMethodInput = document.getElementById('payment_method');
  if (!paymentMethodInput) { return; }

  var isPickup = deliveryChoice && deliveryChoice.value === 'pickup';

  // Credit settling the whole order overrides the tiles - they are hidden and
  // disabled in that state, so there is no payment_choice to read, and this is
  // the only writer of payment_method.
  if (CHECKOUT_CREDIT_COVERS_ALL) {
    paymentMethodInput.value = 'store_credit';
    updateCheckoutButton('store_credit', isPickup);
    return;
  }
  if (!paymentChoice) { return; }

  var method;
  if (paymentChoice.value === 'cash') {
    method = isPickup ? 'store' : 'cod';
  } else {
    var channelEl = document.querySelector('input[name="epay_channel"]:checked');
    method = channelEl ? channelEl.value : 'gcash';
  }
  paymentMethodInput.value = method;
  updateCheckoutButton(method, isPickup);
}

function updateCheckoutButton(paymentMethod, isPickup) {
  // isPickup is no longer read here: "Continue to payment" is the same label
  // whichever way the order is fulfilled, and the pickup case is already
  // carried by paymentMethod === 'store'. The parameter stays so every
  // caller's signature is untouched.
  const checkoutBtn = document.getElementById('checkoutBtn');
  if (!checkoutBtn) { return; }

  // The total already on screen, not a second calculation of it: this is the
  // element checkoutRecalcTotals() writes, so the label can never quote a
  // figure the summary disagrees with.
  const totalEl = document.getElementById('checkoutTotal');
  const amount = totalEl ? (' \u00B7 ' + totalEl.textContent.trim()) : '';

  if (paymentMethod === 'store_credit') {
    // No amount appended: the total on screen is the pre-credit figure, and
    // what is actually due here is zero.
    checkoutBtn.innerHTML = '<i class="fi fi-rr-wallet"></i> Place order · Paid with store credit';
  } else if (paymentMethod === 'cod') {
    checkoutBtn.innerHTML = '<i class="fi fi-rr-money"></i> Place order' + amount;
  } else if (paymentMethod === 'store') {
    checkoutBtn.innerHTML = '<i class="fi fi-rr-shop"></i> Place order \u00B7 Pay at store' + amount;
  } else if (paymentMethod === 'gcash') {
    checkoutBtn.innerHTML = '<i class="fi fi-rr-mobile"></i> Continue to payment' + amount;
  } else if (paymentMethod === 'paymaya') {
    checkoutBtn.innerHTML = '<i class="fi fi-rr-mobile"></i> Continue to payment' + amount;
  } else if (paymentMethod === 'xendit') {
    checkoutBtn.innerHTML = '<i class="fi fi-rr-credit-card"></i> Continue to payment' + amount;
  } else {
    checkoutBtn.innerHTML = '<i class="fi fi-rr-shopping-bag"></i> Place order' + amount;
  }
}

function updateCheckoutState(){
  var deliveryChosen = document.querySelector('input[name="delivery_choice"]:checked');
  var paymentChosen = document.querySelector('input[name="payment_choice"]:checked');
  var checkoutBtn = document.getElementById('checkoutBtn');
  if (checkoutBtn) {
    var ready = !!(deliveryChosen && (paymentChosen || CHECKOUT_CREDIT_COVERS_ALL));
    checkoutBtn.classList.toggle('is-disabled', !ready);
    checkoutBtn.setAttribute('aria-disabled', ready ? 'false' : 'true');
  }

  var deliveryAddrSection = document.getElementById('deliveryAddressSection');
  if (deliveryAddrSection) {
    deliveryAddrSection.style.display = (deliveryChosen && deliveryChosen.value === 'delivery') ? 'block' : 'none';
  }
}

// Flashes a red pulse around a required group and scrolls it into view so
// the user immediately sees what still needs filling in.
function flashRequiredGroup(el) {
  if (!el) return;
  el.classList.remove('required-flash');
  void el.offsetWidth; // restart animation if already flashing
  el.classList.add('required-flash');
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Checks the two choices that gate the Complete Order button. If something
// required is missing, tells the user what and highlights it; returns
// whether checkout is actually ready to submit.
function checkRequiredCheckoutChoices() {
  var deliveryChosen = document.querySelector('input[name="delivery_choice"]:checked');
  // Credit covering the whole order is itself the answer to "how are you
  // paying?" - there is no tile to tick, so none is demanded.
  var paymentChosen = document.querySelector('input[name="payment_choice"]:checked') || CHECKOUT_CREDIT_COVERS_ALL;
  if (deliveryChosen && paymentChosen) { return true; }

  var missingLabels = [];
  if (!paymentChosen) { missingLabels.push('payment method'); flashRequiredGroup(document.getElementById('paymentMethodGroup')); }
  if (!deliveryChosen) { missingLabels.push('delivery method'); flashRequiredGroup(document.getElementById('deliveryMethodGroup')); }

  showNotification('Please select a ' + missingLabels.reverse().join(' and a ') + ' before completing your order.', 'error');
  return false;
}

// Quantity controls for Buy Now / checkout items
function checkoutFormatCurrency(amount){
  try{
    return new Intl.NumberFormat('en-PH',{ style:'currency', currency:'PHP', minimumFractionDigits:2 }).format(Number(amount||0));
  }catch(e){
    const num = (Number(amount||0)).toFixed(2);
    return '₱'+num.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
}

function checkoutRecalcTotals(){
  let net = 0;
  document.querySelectorAll('.order-item').forEach(function(row){
    const price = parseFloat(row.getAttribute('data-price')) || 0;
    const qty = parseInt(row.querySelector('.qty-input').value, 10) || 0;
    const priceEl = row.querySelector('.item-price');
    if (priceEl) { priceEl.textContent = checkoutFormatCurrency(price * qty); }
    // Display only - the badge on the thumb is a second view of the same qty
    // input read on the line above, so it cannot drift from it.
    const badgeEl = row.querySelector('.item-qty-badge');
    if (badgeEl) { badgeEl.textContent = qty; }
    net += price * qty;
  });
  const vat = Math.round(net * 0.12 * 100) / 100;
  const total = Math.round((net + vat) * 100) / 100;
  const subEl = document.getElementById('checkoutSubtotal'); if (subEl) subEl.textContent = checkoutFormatCurrency(net);
  const vatEl = document.getElementById('checkoutVat'); if (vatEl) vatEl.textContent = checkoutFormatCurrency(vat);
  const totEl = document.getElementById('checkoutTotal'); if (totEl) totEl.textContent = checkoutFormatCurrency(total);
  checkoutRecalcCredit(total);
  // The submit label carries the total, so it has to be re-rendered when the
  // total moves. Idempotent: re-derives payment_method from the same two
  // radios and writes the same value back.
  //
  // recomputePaymentMethod() returns early until a payment method is picked,
  // which on first load left the button showing the markup's placeholder
  // ("Complete Order", no total). Fall through to updateCheckoutButton's
  // default branch in that case so the label is right from first paint.
  if (CHECKOUT_CREDIT_COVERS_ALL || document.querySelector('input[name="payment_choice"]:checked')) {
    if (typeof recomputePaymentMethod === 'function') { recomputePaymentMethod(); }
  } else if (typeof updateCheckoutButton === 'function') {
    updateCheckoutButton(null, false);
  }
}

// Store credit is capped client-side purely for display (never trusted as
// the real amount) - process_order.php independently re-clamps to
// min(balance, order total) server-side before touching the ledger, since
// the cart/quantities/total here can never be trusted from the client.
var CHECKOUT_STORE_CREDIT_BALANCE = <?= json_encode(round((float)$storeCreditBalance, 2)) ?>;

// True while the applied credit leaves nothing to pay. Read by
// recomputePaymentMethod(), updateCheckoutState() and
// checkRequiredCheckoutChoices() - the three places that otherwise insist on
// a payment tile being ticked. Only checkoutApplyCreditLock() writes it.
var CHECKOUT_CREDIT_COVERS_ALL = false;

// Swaps the payment-method card between "pick one" and "already paid".
// Called on every recalc, so it has to be idempotent in both directions:
// raising the cart quantity past the balance has to hand the tiles back.
function checkoutApplyCreditLock(coversAll, applied){
  var wasLocked = CHECKOUT_CREDIT_COVERS_ALL;
  CHECKOUT_CREDIT_COVERS_ALL = !!coversAll;

  var group = document.getElementById('paymentMethodGroup');
  var lock = document.getElementById('paymentCreditLock');
  var amountEl = document.getElementById('paymentCreditLockAmount');
  var mark = document.getElementById('paymentRequiredMark');
  var epaySection = document.getElementById('epaymentChannelSection');

  if (group) { group.hidden = CHECKOUT_CREDIT_COVERS_ALL; }
  if (lock) { lock.hidden = !CHECKOUT_CREDIT_COVERS_ALL; }
  if (mark) { mark.hidden = CHECKOUT_CREDIT_COVERS_ALL; }
  if (amountEl) { amountEl.textContent = checkoutFormatCurrency(applied); }

  // Disabled as well as hidden. A disabled control is barred from constraint
  // validation, so the `required` on these radios can't block a submit over a
  // question the customer is no longer being asked - and a hidden-but-enabled
  // radio would still be reachable by keyboard.
  ['payment_cash', 'payment_online'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) { el.disabled = CHECKOUT_CREDIT_COVERS_ALL; }
  });

  if (CHECKOUT_CREDIT_COVERS_ALL) {
    if (epaySection) { epaySection.style.display = 'none'; }
    checkoutShowCancelPolicy('credit');
  } else if (wasLocked) {
    // Coming back out of the lock: restore whatever the tiles say now,
    // including the e-payment channel picker if Online is still ticked.
    var choice = document.querySelector('input[name="payment_choice"]:checked');
    if (epaySection) { epaySection.style.display = (choice && choice.value === 'online') ? 'block' : 'none'; }
    checkoutShowCancelPolicy(choice ? choice.value : null);
  }

  if (wasLocked !== CHECKOUT_CREDIT_COVERS_ALL) { updateCheckoutState(); }
}
function checkoutRecalcCredit(total){
  const toggle = document.getElementById('useStoreCreditToggle');
  const hiddenInput = document.getElementById('useStoreCreditInput');
  if (!toggle || !hiddenInput) return;

  const applied = toggle.checked ? Math.min(CHECKOUT_STORE_CREDIT_BALANCE, total) : 0;
  hiddenInput.value = applied.toFixed(2);

  const creditLine = document.getElementById('checkoutCreditLine');
  const creditDivider = document.getElementById('checkoutCreditDivider');
  const dueLine = document.getElementById('checkoutDueLine');
  const creditAppliedEl = document.getElementById('checkoutCreditApplied');
  const dueEl = document.getElementById('checkoutAmountDue');
  const show = applied > 0;
  if (creditLine) creditLine.style.display = show ? 'flex' : 'none';
  if (creditDivider) creditDivider.style.display = show ? 'block' : 'none';
  if (dueLine) dueLine.style.display = show ? 'flex' : 'none';
  if (creditAppliedEl) creditAppliedEl.textContent = '-' + checkoutFormatCurrency(applied);
  const amountDue = Math.max(0, total - applied);
  if (dueEl) dueEl.textContent = checkoutFormatCurrency(amountDue);

  // Nothing left to pay means nothing left to choose. The cent of tolerance
  // is for the VAT rounding in phVatAdd() - an amount due of PHP 0.004 is
  // zero, and must not leave the customer staring at a payment form for it.
  checkoutApplyCreditLock(applied > 0 && amountDue <= 0.009, applied);
}
document.addEventListener('DOMContentLoaded', function(){
  const toggle = document.getElementById('useStoreCreditToggle');
  if (toggle) {
    toggle.addEventListener('change', function(){ checkoutRecalcTotals(); });
  }
  // Run once on load, unconditionally. This used to sit inside the `if
  // (toggle)` above, so it only ran for customers who happen to hold store
  // credit - everyone else reached the page with the submit label never
  // rendered. checkoutRecalcTotals() is otherwise only triggered reactively
  // by a quantity change, so without this the credit line stayed hidden and
  // the button kept the markup's placeholder text.
  checkoutRecalcTotals();
});

function checkoutUpdateButtons(row){
  const qty = parseInt(row.querySelector('.qty-input').value, 10) || 0;
  const stock = parseInt(row.getAttribute('data-stock'), 10) || 0;
  const minusBtn = row.querySelector('.qty-btn.minus');
  const plusBtn = row.querySelector('.qty-btn.plus');
  // Minus stays enabled at 1: pressing it there removes the line entirely
  // (see checkoutAdjustQty), so disabling it would block that.
  if (minusBtn) { minusBtn.disabled = false; }
  if (plusBtn) { plusBtn.disabled = qty >= stock; }
}

function checkoutPersistQty(productId, quantity){
  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'update', product_id: productId, quantity: quantity })
  })
  .then(r => r.json())
  .then(function(d){
    if (!d.success) {
      showNotification(d.message || 'Could not update quantity', 'error');
    }
  })
  .catch(function(){
    showNotification('Network error updating quantity', 'error');
  });
}

function checkoutSetQty(productId, rawValue){
  const row = document.querySelector('.order-item[data-product-id="'+productId+'"]');
  if (!row) return;
  const input = row.querySelector('.qty-input');
  const stock = parseInt(row.getAttribute('data-stock'), 10) || 1;
  let qty = parseInt(rawValue, 10);
  if (!qty || qty < 1) { qty = 1; }
  if (qty > stock) {
    qty = stock;
    showNotification('Only ' + stock + ' in stock', 'error');
  }
  input.value = qty;
  checkoutUpdateButtons(row);
  checkoutRecalcTotals();
  checkoutPersistQty(productId, qty);
}

function checkoutAdjustQty(productId, delta){
  const row = document.querySelector('.order-item[data-product-id="'+productId+'"]');
  if (!row) return;
  const current = parseInt(row.querySelector('.qty-input').value, 10) || 1;
  // Stepping below 1 means "I don't want this after all" - drop the line
  // rather than clamping at 1, which left no way to remove it from here.
  if (current + delta < 1) { checkoutRemoveItem(productId); return; }
  checkoutSetQty(productId, current + delta);
}

// Asks first, then removes. cart.php's removeFromCart() puts the same
// question behind uiConfirm() for the same action, and this page already
// loads that dialog (it is what the address delete uses), so the two stay
// consistent rather than one page silently dropping a line.
async function checkoutConfirmRemove(productId){
  if (typeof uiConfirm === 'function') {
    if (!(await uiConfirm('Remove this item from your order?'))) { return; }
  }
  checkoutRemoveItem(productId);
}

// Removes one product from the order summary.
//
// quantity 0 is already the cart API's own "remove" signal (api/cart.php's
// updateCartItem unsets the row on $quantity <= 0), so no new endpoint is
// needed - checkoutPersistQty(id, 0) is the whole server side.
//
// selected_ids has to be kept in step or process_order.php would re-add the
// product it was just told to drop, and the ?selected= query string with it,
// so a refresh does not resurrect the line either.
function checkoutRemoveItem(productId){
  const row = document.querySelector('.order-item[data-product-id="'+productId+'"]');
  if (!row) return;

  checkoutPersistQty(productId, 0);
  row.remove();

  const hidden = document.getElementById('checkoutSelectedIds');
  if (hidden && hidden.value) {
    const kept = hidden.value.split(',')
      .map(function(v){ return v.trim(); })
      .filter(function(v){ return v && v !== String(productId); });
    hidden.value = kept.join(',');
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.has('selected')) {
        if (kept.length) { url.searchParams.set('selected', kept.join(',')); }
        else { url.searchParams.delete('selected'); }
        window.history.replaceState({}, '', url);
      }
    } catch (e) { /* older browsers: the hidden field alone still holds */ }
  }

  // Nothing left to check out - checkout.php itself redirects to the cart
  // when it has no items, so match that rather than leaving an empty form.
  if (!document.querySelectorAll('.order-item').length) {
    showNotification('Your order is empty', 'error');
    window.location.href = '<?= WEB_ROOT ?>public/authenticated/customer/cart.php';
    return;
  }

  checkoutRecalcTotals();
  showNotification('Item removed from this order', 'success');
}

// Form validation and AJAX submission
document.getElementById('checkoutForm').addEventListener('submit', function(e) {
  e.preventDefault();
  console.log('Form submit event triggered');
  
  if (!checkRequiredCheckoutChoices()) { return; }

  const deliveryChoice = document.querySelector('input[name="delivery_choice"]:checked');
  const paymentChoice = document.querySelector('input[name="payment_choice"]:checked');
  const isPickup = deliveryChoice && deliveryChoice.value === 'pickup';
  const requiredFields = isPickup ? ['full_name', 'email'] : ['full_name', 'email', 'phone', 'address'];
  const fieldLabels = { full_name: 'full name', email: 'email', phone: 'phone number', address: 'delivery address' };

  let isValid = true;
  let missingFields = [];

  requiredFields.forEach(fieldName => {
    const field = document.getElementById(fieldName);
    if (!field || !field.value || !field.value.trim()) {
      isValid = false;
      missingFields.push(fieldLabels[fieldName] || fieldName);
    }
  });

  if (!isValid) {
    if (!isPickup) {
      var addrSec = document.getElementById('deliveryAddressSection');
      // .addr-error persists until an address is supplied - flashRequiredGroup
      // only animates once, so on a long page the customer can scroll back and
      // find nothing marked. reflectAddressState() clears it.
      if (addrSec && !hasDeliveryAddress()) { addrSec.classList.add('addr-error'); }
      flashRequiredGroup(addrSec);
      // Send them straight into the address book - the panel is where the
      // problem is fixed, and expecting someone to find "Add" for themselves
      // after an error is a step they should not have to take.
      if (!hasDeliveryAddress()) {
        var chg = document.getElementById('changeAddressBtn');
        if (chg) { setTimeout(function(){ chg.click(); }, 320); }
      }
    }
    showNotification('Please fill in your ' + missingFields.join(', ') + '.', 'error');
    return;
  }

  // Submit form via AJAX
  const formData = new FormData(this);
  const submitBtn = document.getElementById('checkoutBtn');
  const originalText = submitBtn.innerHTML;

  console.log('Submitting form to:', this.action);
  console.log('Form data entries:');
  for (let [key, value] of formData.entries()) {
    console.log(key, ':', value);
  }

  // Debug: log the resolved delivery/payment method hidden input values
  const paymentMethod = document.getElementById('payment_method');
  const deliveryMethod = document.getElementById('delivery_method');

  console.log('Delivery choice:', deliveryChoice ? deliveryChoice.value : 'none');
  console.log('Payment choice:', paymentChoice ? paymentChoice.value : 'none');
  console.log('Payment method value:', paymentMethod ? paymentMethod.value : 'not found');
  console.log('Delivery method value:', deliveryMethod ? deliveryMethod.value : 'not found');
  
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="fi fi-rr-spinner"></i> Processing Order...';
  
  fetch(this.action, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
    body: formData
  })
  .then(async (r) => {
    const ct = r.headers.get('content-type') || '';
    const txt = await r.text();
    try {
      if (ct.includes('application/json')) return JSON.parse(txt);
      return JSON.parse(txt); // some servers omit the header
    } catch(_) {
      return { success: false, message: txt && txt.trim() ? txt : 'Unexpected server response' };
    }
  })
  .then(d => {
    if (d.success) {
      const submitBtn = document.getElementById('checkoutBtn');
      if (submitBtn) {
        submitBtn.innerHTML = '<i class="fi fi-rr-check"></i> Redirecting to confirmation...';
      }
      // Redirect to order_success.php instead of order_confirmation.php
      window.location.href = d.redirect || '<?= WEB_ROOT ?>public/authenticated/customer/order_success.php?id=' + d.order_id;
    } else {
      var msg = (d && d.message) ? d.message : 'Order failed. Please try again.';
      try{ showNotification(msg, 'error'); }catch(_){}
      try{ alert(msg); }catch(_){}
    }
  })
  .catch(err => {
    console.error('Order submission error:', err);
    try{ showNotification('An error occurred. Please try again.', 'error'); }catch(_){}
    try{ alert('An error occurred. Please try again.'); }catch(_){}
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
});

// Initialize default service option on page load
document.addEventListener('DOMContentLoaded', function() {
  // Do not auto-select; keep disabled until customer chooses
  updateCheckoutState();
  // Label the address button correctly on first paint. applyAddress() also
  // calls this, but only once it has an address to apply - a brand-new
  // customer has none, and that is exactly the case that must not render a
  // "Change" button over an empty panel.
  reflectAddressState();
});

// Ensure a delivery method and payment method are selected when clicking the primary submit
function completeOrderCOD(){
  try{
    console.log('Complete order function called');

    // Delivery Method and Payment Method are required selections - do not
    // silently pick a default for the user; tell them what's missing and
    // highlight it instead.
    if (!checkRequiredCheckoutChoices()) {
      console.log('Delivery/payment method not selected yet');
      return;
    }

    // Validate the delivery address fields before submission
    const currentDelivery = document.querySelector('input[name="delivery_choice"]:checked');
    const isPickupMode = currentDelivery && currentDelivery.value === 'pickup';
    const requiredFields = isPickupMode ? ['full_name', 'email'] : ['full_name', 'email', 'phone', 'address'];
    const fieldLabels = { full_name: 'full name', email: 'email', phone: 'phone number', address: 'delivery address' };
    let isValid = true;
    let missingFields = [];

    requiredFields.forEach(fieldName => {
      const field = document.getElementById(fieldName);
      if (!field || !field.value || !field.value.trim()) {
        isValid = false;
        missingFields.push(fieldLabels[fieldName] || fieldName);
      }
    });

    if (!isValid) {
      console.log('Missing fields:', missingFields);
      if (!isPickupMode) { flashRequiredGroup(document.getElementById('deliveryAddressSection')); }
      showNotification('Please fill in your ' + missingFields.join(', ') + '.', 'error');
      return;
    }

    console.log('Form validation passed, submitting...');
    
    // Submit form programmatically to avoid any event wiring issues
    var form = document.getElementById('checkoutForm');
    if (form) {
      // Trigger the form's submit event
      var submitEvent = new Event('submit', { cancelable: true, bubbles: true });
      form.dispatchEvent(submitEvent);
    } else {
      console.error('Checkout form not found');
      showNotification('Form not found. Please refresh the page.', 'error');
    }
  }catch(e){ 
    console.error('Complete order error:', e);
    showNotification('An error occurred. Please try again.', 'error');
  }
}
</script>

<!-- Address Modal -->
<!-- Closes only from the x or Cancel: a stray click on the backdrop used to
     discard a half-typed address with no undo, which is the one thing in
     this dialog that costs real work to redo. -->
<!-- Layout note: the card is a flex column capped at the viewport height, and
     ONLY the middle section scrolls. The header and the Confirm/Cancel footer
     stay put, so the primary action is always reachable no matter how many
     addresses are saved. The old version was a plain block with
     `margin:60px auto` and `overflow:hidden` on the card, which silently
     clipped everything past the fold - the footer buttons included. -->
<?php /* ---------------------------------------------------------------
   The address modal is built entirely from inline style attributes, and every
   colour in them is a dark-theme literal - #0f172a for the card, #9ca3af for
   secondary text, rgba(255,255,255,...) for the rules. In light mode that put
   a near-black panel with grey-on-grey text over a white page.

   An inline style can only be beaten by !important, which is exactly what it
   is for. Rather than unpick several dozen style attributes, this block
   retargets the colours - and only the colours - onto the theme tokens, so the
   modal follows data-mode like the rest of the page. Layout stays inline.
   --------------------------------------------------------------- */ ?>
<div id="addressModal" class="tw-modal tw-addr-modal" role="dialog" aria-modal="true" aria-labelledby="addrModalTitle" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;padding:24px 16px;overflow-y:auto">
  <div class="tw-modal-card" style="max-width:720px;margin:0 auto;max-height:calc(100vh - 48px);display:flex;flex-direction:column;background:#0f172a;border:1px solid var(--border-color);border-radius:12px;box-shadow:0 24px 60px rgba(0,0,0,.5)">
    <div style="flex:0 0 auto;padding:16px 20px;border-bottom:1px solid rgba(var(--tw-veil-rgb, 255, 255, 255), .08);display:flex;justify-content:space-between;align-items:center;border-radius:12px 12px 0 0">
      <div id="addrModalTitle" style="color:var(--tw-ink, #e5e7eb);font-weight:700">My Address</div>
      <button type="button" onclick="addrClose()" aria-label="Close" style="background:transparent;color:#9ca3af;border:0;font-size:20px;cursor:pointer">×</button>
    </div>
    <div style="flex:1 1 auto;min-height:0;overflow-y:auto;padding:16px 20px">
      <div id="addrList"></div>
      <div id="addrEmptyNote" style="display:none;color:#9ca3af;font-size:14px;padding:10px 0">No saved addresses yet. Add one below.</div>
      <div style="margin-top:12px">
        <button type="button" id="addrAddBtn" style="background:transparent;border:1px dashed rgba(255,255,255,.2);color:#c9a3b5;padding:10px 12px;border-radius:8px;cursor:pointer">+ Add New Address</button>
      </div>
    </div>
    <div style="flex:0 0 auto;padding:14px 20px;border-top:1px solid rgba(var(--tw-veil-rgb, 255, 255, 255), .08);display:flex;gap:12px;justify-content:flex-end;border-radius:0 0 12px 12px">
      <button type="button" onclick="addrClose()" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:var(--tw-ink, #e5e7eb);padding:10px 14px;border-radius:8px;cursor:pointer">Cancel</button>
      <button type="button" onclick="addrConfirm()" style="background:#22c55e;border:0;color:#041016;padding:10px 16px;border-radius:8px;cursor:pointer">Confirm</button>
    </div>
  </div>
  <template id="addrRowTpl">
    <div class="addr-row" style="display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid rgba(var(--tw-veil-rgb, 255, 255, 255), .06)">
      <input type="radio" name="pick_addr_radio" style="margin-top:4px">
      <div style="flex:1">
        <div style="display:flex;gap:8px;align-items:center">
          <div class="nm" style="color:var(--tw-ink, #e5e7eb);font-weight:700"></div>
          <div class="ph" style="color:#9ca3af;font-size:13px"></div>
          <span class="def" style="display:none;border:1px solid rgba(255,255,255,.18);color:#ef4444;border-radius:6px;padding:2px 6px;font-size:12px">Default</span>
          <!-- Shown only when the address carries a map pin, so it is obvious
               at a glance which ones the rider can navigate to precisely. -->
          <span class="pinned" style="display:none;border:1px solid rgba(34,197,94,.45);color:#4ade80;border-radius:6px;padding:2px 6px;font-size:12px">&#128205; Pinned</span>
          <a class="edit" href="javascript:void(0)" style="margin-left:auto;color:#a77693">Edit</a>
        </div>
        <div class="tx" style="color:#cbd5e1;font-size:14px;margin-top:4px"></div>
        <input type="hidden" class="name">
        <input type="hidden" class="phone">
        <input type="hidden" class="text">
        <input type="hidden" class="lat">
        <input type="hidden" class="lng">
      </div>
      <button class="del" style="background:transparent;border:1px solid rgba(255,255,255,.18);color:#fca5a5;padding:6px 10px;border-radius:8px;cursor:pointer">Delete</button>
    </div>
  </template>
  <template id="addrFormTpl">
    <div class="addr-form" style="padding:12px 0;border-bottom:1px dashed var(--border-color)">
      <div style="display:grid;grid-template-columns:1fr 200px;gap:10px;margin-bottom:10px">
        <input class="fn" placeholder="Full Name" aria-label="Full name" style="padding:10px;border-radius:8px;background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb)">
        <input class="ph" type="tel" inputmode="numeric" maxlength="14" placeholder="+63 9XX XXX XXXX" aria-label="Phone number" style="padding:10px;border-radius:8px;background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb)">
      </div>

      <!-- Region / City / Postal / Barangay - autocomplete with region drill-down + "Use My Current Location" -->
      <div class="addr-field" style="position:relative;margin-bottom:10px">
        <input class="region" placeholder="Region, Province, City, Barangay" aria-label="Region, province, city or barangay" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="" style="width:100%;padding:10px 34px 10px 10px;border-radius:8px;background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb)">
        <button type="button" class="field-clear region-clear" aria-label="Clear region" title="Clear" style="display:none;position:absolute;right:4px;top:50%;transform:translateY(-50%);width:30px;height:30px;background:transparent;border:0;color:var(--text-muted);cursor:pointer;font-size:18px;line-height:1;border-radius:50%">×</button>
        <div class="suggest-panel region-suggest" role="listbox" aria-label="Location suggestions" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);background:#111a2e;border:1px solid rgba(255,255,255,.12);border-radius:10px;box-shadow:0 10px 24px rgba(0,0,0,.35);max-height:280px;overflow-y:auto;z-index:20;opacity:0;transform:translateY(-4px);transition:opacity .15s ease,transform .15s ease"></div>
        <div class="suggest-status region-status" role="status" aria-live="polite" style="font-size:12px;color:var(--text-muted);margin-top:4px"></div>
      </div>

      <!-- Barangay - one line, same suggestion dropdown as the fields above:
           it recommends the barangays of the city picked above as you type,
           and stays typeable for a city this dataset has no list for. -->
      <div class="addr-field" style="position:relative;margin-bottom:10px">
        <input class="barangay" placeholder="Barangay" aria-label="Barangay" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="" style="width:100%;padding:10px 34px 10px 10px;border-radius:8px;background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb)">
        <button type="button" class="field-clear barangay-clear" aria-label="Clear barangay" title="Clear" style="display:none;position:absolute;right:4px;top:50%;transform:translateY(-50%);width:30px;height:30px;background:transparent;border:0;color:var(--text-muted);cursor:pointer;font-size:18px;line-height:1;border-radius:50%">×</button>
        <div class="suggest-panel barangay-suggest" role="listbox" aria-label="Barangay suggestions" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);background:#111a2e;border:1px solid rgba(255,255,255,.12);border-radius:10px;box-shadow:0 10px 24px rgba(0,0,0,.35);max-height:280px;overflow-y:auto;z-index:20;opacity:0;transform:translateY(-4px);transition:opacity .15s ease,transform .15s ease"></div>
        <div class="suggest-status barangay-status" role="status" aria-live="polite" style="font-size:12px;color:var(--text-muted);margin-top:4px"></div>
      </div>

      <div style="display:grid;grid-template-columns:160px 1fr;gap:10px;margin-bottom:10px">
        <input class="postal" placeholder="Postal Code" aria-label="Postal code" style="padding:10px;border-radius:8px;background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb)">

        <!-- Street / Building / House No. - autocomplete for streets and landmarks -->
        <div class="addr-field" style="position:relative">
          <input class="street" placeholder="Street Name, Building, House No." aria-label="Street name, building, house number" autocomplete="off" style="width:100%;padding:10px 34px 10px 10px;border-radius:8px;background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb)">
          <button type="button" class="field-clear street-clear" aria-label="Clear street" title="Clear" style="display:none;position:absolute;right:4px;top:50%;transform:translateY(-50%);width:30px;height:30px;background:transparent;border:0;color:var(--text-muted);cursor:pointer;font-size:18px;line-height:1;border-radius:50%">×</button>
        </div>
      </div>

      <!-- Exact location pin -------------------------------------------
           The fields above are free text. "Sampaguita St." typed as
           "Samapguita" geocodes to the wrong street or to nothing, and the
           rider is left circling the barangay. A pin is spelling-proof: the
           coordinates ride along with the order and the Navigate button
           targets the point instead of the sentence.
           REQUIRED: Submit stays disabled until a pin exists, and
           api/addresses.php rejects a write without one. The map is always
           on - there is nothing to opt into, so there is no toggle. -->
      <div class="addr-pin" style="margin:6px 0 12px;border:1px solid var(--border-color);border-radius:10px;overflow:hidden;background:rgba(var(--tw-veil-rgb, 255, 255, 255), .03)">
        <div style="display:flex;gap:10px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;padding:10px 12px">
          <div style="min-width:0">
            <div style="color:var(--tw-ink, #e5e7eb);font-size:14px;font-weight:600">Pin exact location <span style="color:#f87171" title="Required">*</span></div>
            <div class="pin-hint" style="color:#9ca3af;font-size:12px;margin-top:2px;line-height:1.45">Tap the map to drop a pin on your gate, then drag it to fine-tune.</div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0">
            <button type="button" class="pin-locate" style="background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb);padding:7px 10px;border-radius:8px;cursor:pointer;font-size:12.5px;white-space:nowrap">Use my location</button>
            <button type="button" class="pin-find" style="background:rgba(var(--tw-veil-rgb, 255, 255, 255), .06);border:1px solid var(--border-color);color:var(--tw-ink, #e5e7eb);padding:7px 10px;border-radius:8px;cursor:pointer;font-size:12.5px;white-space:nowrap">Find typed address</button>
          </div>
        </div>
        <div class="pin-map-wrap">
          <div class="pin-map" style="height:260px;background:#0b1424"></div>
          <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:8px 12px 10px">
            <div class="pin-readout" role="status" aria-live="polite" style="color:#9ca3af;font-size:12px;min-height:16px"></div>
            <button type="button" class="pin-clear" style="display:none;background:transparent;border:1px solid rgba(255,255,255,.18);color:#fca5a5;padding:6px 10px;border-radius:8px;cursor:pointer;font-size:12px">Move pin again</button>
          </div>
        </div>
      </div>

      <div style="color:#cbd5e1;font-size:14px;margin:6px 0 8px">Label As:</div>
      <div class="labels" style="display:flex;gap:10px;margin-bottom:10px">
        <button type="button" class="lbl btn home active" data-label="Home" style="background:transparent;border:1px solid #a77693;color:#c9a3b5;padding:8px 12px;border-radius:8px;cursor:pointer">Home</button>
        <button type="button" class="lbl btn work" data-label="Work" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:var(--tw-ink, #e5e7eb);padding:8px 12px;border-radius:8px;cursor:pointer">Work</button>
      </div>
      <label style="display:flex;align-items:center;gap:8px;color:#cbd5e1;font-size:14px;margin-bottom:10px"><input type="checkbox" class="asDefault"> Set as Default Address</label>
      <?php /* Filled by showAddrFormErrors() on a blocked submit, hidden again
               as soon as nothing is missing. Sits above the buttons so it is
               already in view when the click that produced it lands. */ ?>
      <div class="addr-form-msg" role="alert" hidden style="font-size:13px;margin:2px 0 8px"></div>
      <div style="display:flex;gap:8px;margin-top:2px">
        <button class="save" style="background:#a77693;border:0;color:#041016;padding:8px 12px;border-radius:8px;cursor:pointer">Submit</button>
        <button class="cancel" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:var(--tw-ink, #e5e7eb);padding:8px 12px;border-radius:8px;cursor:pointer">Cancel</button>
      </div>
    </div>
  </template>
</div>

<!-- Add / Edit Address Modal ------------------------------------------------
     The form used to be appended straight into #addrList, so opening it grew
     the picker from a short list into a tall column - name, phone, region,
     barangay, postal, street, the map, labels and buttons - pushing the
     Confirm footer off the bottom of a clipped card.
     It is its own dialog now, stacked above the picker (z-index 1100). The
     picker stays mounted underneath, which is what makes Cancel feel like
     going back a step rather than starting over.
     Same deliberate rule as the picker: closes from x or Cancel only, never
     from a backdrop click, because a half-typed address costs real work to
     redo. -->
<div id="addrFormModal" class="tw-modal tw-addr-modal" role="dialog" aria-modal="true" aria-labelledby="addrFormTitle" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1100;padding:24px 16px;overflow-y:auto">
  <div class="tw-modal-card" style="max-width:720px;margin:0 auto;max-height:calc(100vh - 48px);display:flex;flex-direction:column;background:#0f172a;border:1px solid var(--border-color);border-radius:12px;box-shadow:0 24px 60px rgba(0,0,0,.55)">
    <?php /* Header holds the Back button alone, on the left where the title
             used to sit. The title moved below the rule, into the scrolling
             body - so the bar reads purely as "the way out of this step", and
             the heading sits with the form it names. */ ?>
    <div style="flex:0 0 auto;padding:16px 20px;border-bottom:1px solid rgba(var(--tw-veil-rgb, 255, 255, 255), .08);display:flex;justify-content:flex-start;align-items:center;border-radius:12px 12px 0 0">
      <!-- Reads "Back", and is: the picker stays mounted underneath, so this
           returns to My Address rather than dismissing the whole flow. -->
      <button type="button" id="addrFormCloseBtn" aria-label="Back to My Address" style="display:inline-flex;align-items:center;gap:6px;background:transparent;border:1px solid rgba(255,255,255,.2);color:var(--tw-ink, #e5e7eb);padding:7px 12px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">&#8592; Back</button>
    </div>
    <!-- The only scrolling region. The title leads it, and the form's own
         Submit/Cancel row scrolls with it - both belong to the form rather
         than to the dialog chrome. -->
    <div style="flex:0 0 auto;padding:16px 20px 0">
      <?php /* Kept as #addrFormTitle: showAddrForm() rewrites this text to
               "Edit Address" when editing, and aria-labelledby on the dialog
               points at this id. Moving it must not change either. */ ?>
      <div id="addrFormTitle" style="color:var(--tw-ink, #e5e7eb);font-weight:700;font-size:17px">Add New Address</div>
    </div>
    <div id="addrFormHost" style="flex:1 1 auto;min-height:0;overflow-y:auto;padding:4px 20px 16px"></div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

