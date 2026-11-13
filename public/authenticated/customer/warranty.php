<?php
// Warranty Information Page
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/warranty.php';
$pageTitle = 'Warranty Information - TechWorld';
$activeNav = 'warranty';

/* The signed-in customer's own warranties.
 *
 * This page has no require_login() - it is a public information page that
 * happens to live under customer/ - so the list is conditional and is scoped
 * to getUserId() and nothing else. A logged-out visitor reads the policy and
 * is invited to sign in; they are never shown a row.
 *
 * warranty_expire_due() is the opportunistic sweep: status is a stored column
 * (warranty_claims and the admin screens read it), so something has to retire
 * a window that has run out. One indexed UPDATE on a page that is already
 * querying, which is how cancelExpiredOnlinePaymentOrders() handles the same
 * problem rather than asking for a cron job on XAMPP.
 */
$myWarranties = [];
$warrantyLoadError = false;
if (isLoggedIn()) {
    try {
        $wpdo = getConnection();
        warranty_expire_due($wpdo);
        $myWarranties = warranty_for_user($wpdo, (int)getUserId());
    } catch (Throwable $e) {
        error_log('warranty page load: ' . $e->getMessage());
        $warrantyLoadError = true;
    }
}
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

  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $pageTitle ?></title>
  <?php /* In <head>, not left to includes/header.php: that include sits in
           <body>, and a favicon declared that late loses the race against the
           browser's default /favicon.ico request - which under XAMPP is
           XAMPP's own icon, and it sticks once cached. */ ?>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
  
  <!-- Icon packs -->
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
  
  <link rel="stylesheet" href="<?= WEB_ROOT ?>style.css">
</head>
<body>
  <?php include __DIR__ . '/includes/header.php'; ?>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <section class="account-page">
  <style>
  .account-page { padding:40px 0; background: var(--page-bg-gradient); min-height:100vh; }
  </style>

  <div class="container">
    <div class="page-header">
      <h1><i class="fi fi-rr-shield-check" style="margin-right: 10px; color: #0A1F33;"></i>Warranty Information</h1>
      <p>Comprehensive warranty coverage for all your tech purchases. Your peace of mind is our priority.</p>
    </div>

    <div class="warranty-content">
      <!-- Warranty Overview -->
      <div class="warranty-section">
        <div class="section-header">
          <i class="fi fi-rr-shield-check"></i>
          <h2>Warranty Coverage</h2>
        </div>
        <div class="section-content">
          <div class="warranty-grid">
            <div class="warranty-item">
              <i class="fi fi-rr-microchip" style="color: #10b981;"></i>
              <h3>Processors</h3>
              <p><strong>3 Years</strong> manufacturer warranty</p>
              <small>Coverage for manufacturing defects</small>
            </div>
            <div class="warranty-item">
              <i class="fi fi-rr-memory-card" style="color: #0A1F33;"></i>
              <h3>Motherboards</h3>
              <p><strong>3 Years</strong> manufacturer warranty</p>
              <small>Coverage for component failures</small>
            </div>
            <div class="warranty-item">
              <i class="fi fi-rr-graphics-card" style="color: #8B4C6C;"></i>
              <h3>Graphics Cards</h3>
              <p><strong>3 Years</strong> manufacturer warranty</p>
              <small>Coverage for GPU and memory issues</small>
            </div>
            <div class="warranty-item">
              <i class="fi fi-rr-memory" style="color: #f59e0b;"></i>
              <h3>Memory (RAM)</h3>
              <p><strong>Lifetime</strong> manufacturer warranty</p>
              <small>Coverage for memory defects</small>
            </div>
            <div class="warranty-item">
              <i class="fi fi-rr-hard-disk" style="color: #ef4444;"></i>
              <h3>Storage (SSD/HDD)</h3>
              <p><strong>5 Years</strong> manufacturer warranty</p>
              <small>Coverage for data integrity issues</small>
            </div>
            <div class="warranty-item">
              <i class="fi fi-rr-plug" style="color: #8B4C6C;"></i>
              <h3>Power Supplies</h3>
              <p><strong>5-10 Years</strong> manufacturer warranty</p>
              <small>Varies by brand and model</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Warranty Terms -->
      <div class="warranty-section">
        <div class="section-header">
          <i class="fi fi-rr-document"></i>
          <h2>Warranty Terms & Conditions</h2>
        </div>
        <div class="section-content">
          <div class="terms-grid">
            <div class="term-card covered">
              <div class="card-header">
                <i class="fi fi-rr-check-circle"></i>
                <h3>What's Covered</h3>
              </div>
              <ul>
                <li>Manufacturing defects</li>
                <li>Component failures under normal use</li>
                <li>Hardware malfunctions</li>
                <li>Performance degradation</li>
                <li>Physical defects in materials</li>
                <li>Software compatibility issues</li>
              </ul>
            </div>
            <div class="term-card not-covered">
              <div class="card-header">
                <i class="fi fi-rr-cross-circle"></i>
                <h3>What's Not Covered</h3>
              </div>
              <ul>
                <li>Physical damage from accidents</li>
                <li>Water or liquid damage</li>
                <li>Overclocking damage</li>
                <li>Modifications or alterations</li>
                <li>Normal wear and tear</li>
                <li>Software issues not related to hardware</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Warranty Process -->
      <div class="warranty-section">
        <div class="section-header">
          <i class="fi fi-rr-list-check"></i>
          <h2>Warranty Claim Process</h2>
        </div>
        <div class="section-content">
          <div class="process-steps">
            <div class="step">
              <div class="step-number">1</div>
              <div class="step-content">
                <h3>Contact Support</h3>
                <p>Email us at <strong>techworldcomputerstore@gmail.com</strong> with your order number, product details, and description of the issue.</p>
              </div>
            </div>
            <div class="step">
              <div class="step-number">2</div>
              <div class="step-content">
                <h3>Provide Documentation</h3>
                <p>Submit proof of purchase, photos of the issue, and any relevant documentation we request.</p>
              </div>
            </div>
            <div class="step">
              <div class="step-number">3</div>
              <div class="step-content">
                <h3>Assessment & Approval</h3>
                <p>Our technical team will assess your claim and approve it if it meets warranty criteria.</p>
              </div>
            </div>
            <div class="step">
              <div class="step-number">4</div>
              <div class="step-content">
                <h3>Repair or Replacement</h3>
                <p>We'll either repair the item or provide a replacement, depending on the issue and availability.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Extended Warranty -->
      <div class="warranty-section">
        <div class="section-header">
          <i class="fi fi-rr-plus-circle"></i>
          <h2>Extended Warranty Options</h2>
        </div>
        <div class="section-content">
          <div class="extended-warranty">
            <div class="warranty-option">
              <div class="option-header">
                <h3>Standard Extended</h3>
                <div class="price">+1 Year - ₱500</div>
              </div>
              <ul>
                <li>Additional 1 year coverage</li>
                <li>Same terms as manufacturer warranty</li>
                <li>Free shipping for warranty claims</li>
              </ul>
            </div>
            <div class="warranty-option">
              <div class="option-header">
                <h3>Premium Extended</h3>
                <div class="price">+2 Years - ₱800</div>
              </div>
              <ul>
                <li>Additional 2 years coverage</li>
                <li>Accidental damage protection</li>
                <li>Priority support</li>
                <li>Free shipping for warranty claims</li>
              </ul>
            </div>
            <div class="warranty-option">
              <div class="option-header">
                <h3>Ultimate Protection</h3>
                <div class="price">+3 Years - ₱1,200</div>
              </div>
              <ul>
                <li>Additional 3 years coverage</li>
                <li>Full accidental damage protection</li>
                <li>24/7 priority support</li>
                <li>Free shipping and handling</li>
                <li>Loaner device during repair</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <?php /* WAS A REGISTRATION FORM.

               It asked the customer to retype their name, email, order number,
               serial number, product model and purchase date - every one of
               which the shop already knows the moment they check out. It also
               never worked: it POSTed to `<?= WEB_ROOT ?>api/warranty.php`,
               and the endpoint is at public/authenticated/api/warranty.php, so
               every submission 404'd. warranty_registrations has zero rows on
               this database, which is that bug's receipt.

               Registration is automatic now - see warranty_register_for_order()
               in config/warranty.php, called from checkout - so the form had
               nothing left to do. This section shows what the customer
               actually has instead. */ ?>
      <!-- Your Warranties -->
      <div class="warranty-section">
        <div class="section-header">
          <i class="fi fi-rr-shield-check"></i>
          <h2>Your Warranties</h2>
        </div>
        <div class="section-content">
          <p class="wr-lead">
            Nothing to register &mdash; every item you buy is covered automatically.
            Your <strong><?= (int)WARRANTY_STORE_DAYS ?>-day store warranty</strong> starts the day the item
            reaches you, not the day you order, so none of it is spent while your order is still with us.
          </p>

          <?php if (!isLoggedIn()): ?>
            <div class="wr-empty">
              <i class="fi fi-rr-lock" aria-hidden="true"></i>
              <p>Sign in to see the warranties on your purchases.</p>
              <a class="wr-btn" href="<?= AUTH_LOGIN_URL ?>">Sign in</a>
            </div>
          <?php elseif ($warrantyLoadError): ?>
            <div class="wr-empty">
              <p>We could not load your warranties just now. Please refresh the page.</p>
            </div>
          <?php elseif (!$myWarranties): ?>
            <div class="wr-empty">
              <i class="fi fi-rr-box-open" aria-hidden="true"></i>
              <p>No warranties yet. They appear here automatically as soon as you place an order.</p>
              <a class="wr-btn" href="<?= WEB_ROOT ?>public/authenticated/customer/products.php">Browse products</a>
            </div>
          <?php else: ?>
            <ul class="wr-list">
              <?php foreach ($myWarranties as $w): ?>
                <?php $d = warranty_describe($w); ?>
                <li class="wr-item wr-item--<?= htmlspecialchars($d['state']) ?>">
                  <div class="wr-thumb">
                    <?php if (!empty($w['image_url'])): ?>
                      <img src="<?= WEB_ROOT . htmlspecialchars((string)$w['image_url']) ?>"
                           alt="<?= htmlspecialchars((string)($w['product_name'] ?? 'Product')) ?>">
                    <?php else: ?>
                      <span aria-hidden="true">&#128230;</span>
                    <?php endif; ?>
                  </div>

                  <div class="wr-main">
                    <div class="wr-name"><?= htmlspecialchars((string)($w['product_name'] ?? 'Product')) ?></div>
                    <div class="wr-meta">
                      <?php if (!empty($w['invoice_no'])): ?>
                        Order <?= htmlspecialchars((string)$w['invoice_no']) ?> &middot;
                      <?php endif; ?>
                      Purchased <?= htmlspecialchars(date('j M Y', strtotime((string)$w['purchase_date']))) ?>
                      <?php if ((int)($w['quantity'] ?? 1) > 1 && empty($w['serial_number'])): ?>
                        &middot; qty <?= (int)$w['quantity'] ?>
                      <?php endif; ?>
                    </div>
                    <?php /* The serial is the whole point for a tracked item:
                             it is what the returns desk matches the unit
                             against, so the customer needs it to hand. */ ?>
                    <?php if (!empty($w['serial_number'])): ?>
                      <div class="wr-serial">
                        <span class="wr-serial-label">Serial</span>
                        <span class="wr-serial-no"><?= htmlspecialchars((string)$w['serial_number']) ?></span>
                      </div>
                    <?php endif; ?>
                    <div class="wr-detail"><?= htmlspecialchars($d['detail']) ?></div>
                  </div>

                  <div class="wr-state">
                    <span class="wr-pill wr-pill--<?= htmlspecialchars($d['state']) ?>">
                      <?= htmlspecialchars($d['label']) ?>
                    </span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="wr-foot">
              Something wrong with an item that is still covered? Open a
              <a href="<?= WEB_ROOT ?>public/authenticated/customer/support.php">support ticket</a>
              and quote the serial number above.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Contact Information -->
      <div class="warranty-section">
        <div class="section-header">
          <i class="fi fi-rr-headset"></i>
          <h2>Warranty Support</h2>
        </div>
        <div class="section-content">
          <div class="support-info">
            <a href="mailto:techworldcomputerstore@gmail.com" class="support-item clickable">
              <i class="fi fi-rr-envelope" style="color: #0A1F33;"></i>
              <div>
                <h3>Warranty Claims</h3>
                <p>techworldcomputerstore@gmail.com</p>
                <small>Response within 24 hours</small>
              </div>
            </a>
            <a href="tel:+639955867973" class="support-item clickable">
              <i class="fi fi-rr-phone-call" style="color: #8B4C6C;"></i>
              <div>
                <h3>Phone Support</h3>
                <p>0995 586 7973</p>
                <small>Mon - Sat: 9AM-5PM</small>
              </div>
            </a>
            <div class="support-item">
              <i class="fi fi-rr-clock" style="color: #6b7280;"></i>
              <div>
                <h3>Processing Time</h3>
                <p>5-10 business days</p>
                <small>For warranty claims</small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  </section>

  <!-- Warranty Page CSS -->
  <style>
  .page-header {
    text-align: center;
    margin: 60px 0 40px;
    padding: 40px 20px;
    background: linear-gradient(135deg, rgba(10, 31, 51, 0.06), rgba(139, 76, 108, 0.08));
    border-radius: 20px;
    border: 1px solid #DCDCDD;
  }

  .page-header h1 {
    font-size: 2.5rem;
    margin-bottom: 15px;
    background: linear-gradient(45deg, #0A1F33, #8B4C6C);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  .page-header p {
    font-size: 1.1rem;
    color: #6B7280;
    max-width: 600px;
    margin: 0 auto;
  }

  .warranty-content {
    max-width: 1000px;
    margin: 0 auto;
  }

  .warranty-section {
    margin-bottom: 50px;
    background: #fff;
    border: 1px solid #DCDCDD;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(10, 31, 51, 0.08);
  }

  .section-header {
    background: linear-gradient(135deg, #16324f, #0A1F33);
    padding: 25px 30px;
    display: flex;
    align-items: center;
    gap: 15px;
  }

  .section-header i {
    font-size: 24px;
    color: white;
  }

  .section-header h2 {
    color: white;
    font-size: 1.5rem;
    margin: 0;
  }

  .section-content {
    padding: 30px;
  }

  .warranty-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
  }

  .warranty-item {
    text-align: center;
    padding: 25px;
    background: #fff;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
    transition: transform 0.3s ease;
  }

  .warranty-item:hover {
    transform: translateY(-5px);
  }

  .warranty-item i {
    font-size: 36px;
    margin-bottom: 15px;
  }

  .warranty-item h3 {
    color: #0A1F33;
    margin-bottom: 10px;
    font-size: 1.2rem;
  }

  .warranty-item p {
    color: #6B7280;
    margin-bottom: 8px;
    font-size: 1.1rem;
  }

  .warranty-item small {
    color: #6B7280;
    font-size: 0.9rem;
  }

  .terms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
  }

  .term-card {
    padding: 25px;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
  }

  .term-card.covered {
    background: rgba(16, 185, 129, 0.1);
    border-color: #10b981;
  }

  .term-card.not-covered {
    background: rgba(239, 68, 68, 0.1);
    border-color: #ef4444;
  }

  .card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
  }

  .card-header i {
    font-size: 20px;
  }

  .covered .card-header i {
    color: #10b981;
  }

  .not-covered .card-header i {
    color: #ef4444;
  }

  .card-header h3 {
    color: #0A1F33;
    margin: 0;
    font-size: 1.2rem;
  }

  .term-card ul {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  .term-card li {
    padding: 8px 0;
    color: #6B7280;
    border-bottom: 1px solid #DCDCDD;
  }

  .term-card li:last-child {
    border-bottom: none;
  }

  .process-steps {
    display: flex;
    flex-direction: column;
    gap: 25px;
  }

  .step {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    padding: 20px;
    background: #fff;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
  }

  .step-number {
    background: linear-gradient(135deg, #16324f, #0A1F33);
    color: white;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1.2rem;
    flex-shrink: 0;
  }

  .step-content h3 {
    color: #0A1F33;
    margin-bottom: 8px;
    font-size: 1.2rem;
  }

  .step-content p {
    color: #6B7280;
    margin: 0;
    line-height: 1.6;
  }

  .extended-warranty {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
  }

  .warranty-option {
    padding: 25px;
    background: #fff;
    border: 1px solid #DCDCDD;
    border-radius: 15px;
    transition: transform 0.3s ease, border-color 0.3s ease;
  }

  .warranty-option:hover {
    transform: translateY(-5px);
    border-color: #8B4C6C;
  }

  .option-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
  }

  .option-header h3 {
    color: #0A1F33;
    margin: 0;
    font-size: 1.2rem;
  }

  .price {
    color: #8B4C6C;
    font-weight: bold;
    font-size: 1.1rem;
  }

  .warranty-option ul {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  .warranty-option li {
    padding: 6px 0;
    color: #6B7280;
    border-bottom: 1px solid #DCDCDD;
  }

  .warranty-option li:last-child {
    border-bottom: none;
  }

  .warranty-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
  }

  .form-group {
    display: flex;
    flex-direction: column;
  }

  .form-group label {
    color: #0A1F33;
    margin-bottom: 8px;
    font-weight: 600;
  }

  .form-group input {
    padding: 12px 15px;
    border: 2px solid #DCDCDD;
    border-radius: 10px;
    background: #fff;
    color: #0A1F33;
    font-size: 1rem;
    transition: border-color 0.3s ease, background 0.3s ease;
  }

  .form-group input:focus {
    outline: none;
    border-color: #8B4C6C;
    background: #fff;
  }

  .submit-btn {
    background: #8B4C6C;
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 10px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    max-width: 300px;
    margin: 0 auto;
  }

  .submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(139, 76, 108, 0.35);
  }

  .support-info {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .support-item {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    padding: 20px;
    background: #fff;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
  }

  .support-item.clickable {
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    transition: all 0.3s ease;
  }

  .support-item.clickable:hover {
    background: rgba(139, 76, 108, 0.08);
    border-color: #8B4C6C;
    transform: translateY(-2px);
  }

  .support-item i {
    font-size: 24px;
    margin-top: 5px;
  }

  .support-item h3 {
    color: #0A1F33;
    margin-bottom: 8px;
    font-size: 1.2rem;
  }

  .support-item p {
    color: #6B7280;
    margin: 0 0 5px 0;
    font-size: 1.1rem;
  }

  .support-item small {
    color: #6B7280;
    font-size: 0.9rem;
  }

  @media (max-width: 768px) {
    .page-header h1 {
      font-size: 2rem;
    }
    
    .section-content {
      padding: 20px;
    }
    
    .form-row {
      grid-template-columns: 1fr;
    }
    
    .step {
      flex-direction: column;
      text-align: center;
    }
    
    .support-item {
      flex-direction: column;
      text-align: center;
    }
  }

  /* ---- Your Warranties ------------------------------------------------
     Matches this page's existing cards, which use a hardcoded light palette
     (#fff / #DCDCDD / #0A1F33) rather than theme tokens - so these do too,
     instead of mixing two colour systems inside one card. */
  .wr-lead { margin: 0 0 22px; color: #42566b; line-height: 1.6; font-size: .97rem; }

  .wr-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
  .wr-item {
    display: grid; grid-template-columns: 56px 1fr auto; gap: 16px; align-items: center;
    padding: 14px 16px; border: 1px solid #DCDCDD; border-radius: 14px; background: #fcfdfe;
  }
  /* A 4px edge carries the state as well as the pill, so it is not colour in
     one small place only. */
  .wr-item--active  { border-left: 4px solid #1f7a53; }
  .wr-item--pending { border-left: 4px solid #8a5a00; }
  .wr-item--expired { border-left: 4px solid #9aa7b4; background: #f7f8fa; }
  .wr-item--voided  { border-left: 4px solid #a8264d; background: #f7f8fa; }

  .wr-thumb {
    width: 56px; height: 56px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
    border: 1px solid #DCDCDD; background: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
  }
  .wr-thumb img { width: 100%; height: 100%; object-fit: cover; }

  .wr-main { min-width: 0; }
  .wr-name { font-weight: 700; color: #0A1F33; font-size: .96rem; line-height: 1.35; }
  .wr-meta { font-size: .78rem; color: #6b7c8d; margin-top: 3px; }
  .wr-detail { font-size: .8rem; color: #42566b; margin-top: 6px; line-height: 1.5; }

  /* Monospace so a transposed character is visible when the customer reads it
     back to staff over the phone - it is what the returns desk matches on. */
  .wr-serial { margin-top: 6px; display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
  .wr-serial-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: #8a99a8; font-weight: 700; }
  .wr-serial-no {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: .8rem; font-weight: 700; color: #0A1F33;
    background: #eef2f6; border-radius: 5px; padding: 2px 8px; word-break: break-all;
    user-select: all;
  }

  .wr-state { flex-shrink: 0; }
  .wr-pill {
    display: inline-block; white-space: nowrap; padding: 5px 12px; border-radius: 20px;
    font-size: .76rem; font-weight: 800;
  }
  .wr-pill--active  { background: #e7f6ef; color: #1f7a53; }
  .wr-pill--pending { background: #fff4e0; color: #8a5a00; }
  .wr-pill--expired { background: #eef2f6; color: #5b6a78; }
  .wr-pill--voided  { background: #fdecf1; color: #a8264d; }

  .wr-empty { text-align: center; padding: 34px 20px; color: #6b7c8d; }
  .wr-empty i { font-size: 2rem; display: block; margin-bottom: 10px; color: #9aa7b4; }
  .wr-empty p { margin: 0 0 16px; font-size: .92rem; }
  .wr-btn {
    display: inline-block; padding: 10px 22px; border-radius: 10px;
    background: linear-gradient(135deg, #16324f, #0A1F33); color: #fff;
    text-decoration: none; font-weight: 700; font-size: .88rem;
  }
  .wr-btn:hover { color: #fff; opacity: .92; }

  .wr-foot { margin: 20px 0 0; font-size: .83rem; color: #6b7c8d; line-height: 1.6; }
  .wr-foot a { color: #16324f; font-weight: 700; }

  @media (max-width: 620px) {
    .wr-item { grid-template-columns: 44px 1fr; }
    .wr-thumb { width: 44px; height: 44px; }
    /* The pill drops under the text rather than squeezing the name to nothing. */
    .wr-state { grid-column: 2; margin-top: 4px; }
  }

  </style>

  <?php /* The submit handler that lived here is gone with the form it drove.
           It POSTed to an endpoint that does not exist (see the note on the
           Your Warranties section above) and, now that the form is not
           rendered, getElementById('warrantyForm') returns null and the
           listener binding would throw on page load. */ ?>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
