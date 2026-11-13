<?php
// Returns Policy Page
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
$pageTitle = 'Returns Policy - TechWorld';
$activeNav = 'returns';
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
      <h1><i class="fi fi-rr-undo" style="margin-right: 10px; color: #0A1F33;"></i>Returns & Exchanges</h1>
      <p>Our hassle-free return policy ensures you're completely satisfied with your purchase.</p>
    </div>

    <div class="returns-content">
      <!-- Return Policy Overview -->
      <div class="policy-section">
        <div class="section-header">
          <i class="fi fi-rr-shield-check"></i>
          <h2>30-Day Return Policy</h2>
        </div>
        <div class="section-content">
          <div class="policy-grid">
            <div class="policy-item">
              <i class="fi fi-rr-calendar" style="color: #10b981;"></i>
              <h3>30 Days</h3>
              <p>Return window from delivery date</p>
            </div>
            <div class="policy-item">
              <i class="fi fi-rr-refresh" style="color: #0A1F33;"></i>
              <h3>Free Returns</h3>
              <p>No restocking fees for eligible items</p>
            </div>
            <div class="policy-item">
              <i class="fi fi-rr-credit-card" style="color: #8B4C6C;"></i>
              <h3>Full Refund</h3>
              <p>Original payment method within 5-7 days</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Return Process -->
      <div class="policy-section">
        <div class="section-header">
          <i class="fi fi-rr-list-check"></i>
          <h2>How to Return an Item</h2>
        </div>
        <div class="section-content">
          <div class="process-steps">
            <div class="step">
              <div class="step-number">1</div>
              <div class="step-content">
                <h3>Contact Support</h3>
                <p>Email us at <strong>techworldcomputerstore@gmail.com</strong> with your order number and reason for return.</p>
              </div>
            </div>
            <div class="step">
              <div class="step-number">2</div>
              <div class="step-content">
                <h3>Get Return Label</h3>
                <p>We'll send you a prepaid return shipping label within 24 hours.</p>
              </div>
            </div>
            <div class="step">
              <div class="step-number">3</div>
              <div class="step-content">
                <h3>Package & Ship</h3>
                <p>Pack the item securely in original packaging and ship using our label.</p>
              </div>
            </div>
            <div class="step">
              <div class="step-number">4</div>
              <div class="step-content">
                <h3>Receive Refund</h3>
                <p>Once we receive and inspect the item, we'll process your refund.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Return Conditions -->
      <div class="policy-section">
        <div class="section-header">
          <i class="fi fi-rr-rules"></i>
          <h2>Return Conditions</h2>
        </div>
        <div class="section-content">
          <div class="conditions-grid">
            <div class="condition-card eligible">
              <div class="card-header">
                <i class="fi fi-rr-check-circle"></i>
                <h3>Eligible for Return</h3>
              </div>
              <ul>
                <li>Items in original condition</li>
                <li>Original packaging included</li>
                <li>All accessories and manuals</li>
                <li>Within 30 days of delivery</li>
                <li>Defective or damaged items</li>
                <li>Wrong item received</li>
              </ul>
            </div>
            <div class="condition-card not-eligible">
              <div class="card-header">
                <i class="fi fi-rr-cross-circle"></i>
                <h3>Not Eligible for Return</h3>
              </div>
              <ul>
                <li>Items damaged by customer</li>
                <li>Missing original packaging</li>
                <li>Software with opened seal</li>
                <li>Custom or personalized items</li>
                <li>Items past 30-day window</li>
                <li>Used consumables</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Exchange Policy -->
      <div class="policy-section">
        <div class="section-header">
          <i class="fi fi-rr-refresh"></i>
          <h2>Exchange Policy</h2>
        </div>
        <div class="section-content">
          <div class="exchange-info">
            <div class="exchange-item">
              <i class="fi fi-rr-arrows-exchange"></i>
              <div>
                <h3>Size/Color Exchanges</h3>
                <p>Free exchanges for different sizes or colors within 30 days.</p>
              </div>
            </div>
            <div class="exchange-item">
              <i class="fi fi-rr-upgrade"></i>
              <div>
                <h3>Upgrade Exchanges</h3>
                <p>Exchange for a higher-end model by paying the price difference.</p>
              </div>
            </div>
            <div class="exchange-item">
              <i class="fi fi-rr-time-past"></i>
              <div>
                <h3>Processing Time</h3>
                <p>Exchanges are processed within 3-5 business days after receiving your return.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Special Cases -->
      <div class="policy-section">
        <div class="section-header">
          <i class="fi fi-rr-exclamation-triangle"></i>
          <h2>Special Cases</h2>
        </div>
        <div class="section-content">
          <div class="special-cases">
            <div class="case-item">
              <h3><i class="fi fi-rr-truck"></i> Damaged in Shipping</h3>
              <p>If your item arrives damaged, contact us immediately. We'll arrange for a replacement or full refund at no cost to you.</p>
            </div>
            <div class="case-item">
              <h3><i class="fi fi-rr-box-open"></i> Wrong Item Shipped</h3>
              <p>If you receive the wrong item, we'll send the correct one immediately and arrange pickup of the incorrect item.</p>
            </div>
            <div class="case-item">
              <h3><i class="fi fi-rr-microchip"></i> Defective Products</h3>
              <p>Defective items are covered under warranty. We'll replace or refund defective products within the warranty period.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Contact Information -->
      <div class="policy-section">
        <div class="section-header">
          <i class="fi fi-rr-headset"></i>
          <h2>Need Help with Returns?</h2>
        </div>
        <div class="section-content">
          <div class="contact-options">
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/support.php" class="contact-option">
              <i class="fi fi-rr-envelope"></i>
              <div>
                <h3>Email Support</h3>
                <p>techworldcomputerstore@gmail.com</p>
              </div>
            </a>
            <a href="tel:+639955867973" class="contact-option">
              <i class="fi fi-rr-phone-call"></i>
              <div>
                <h3>Phone Support</h3>
                <p>0995 586 7973</p>
              </div>
            </a>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/support.php" class="contact-option">
              <i class="fi fi-rr-message"></i>
              <div>
                <h3>Live Chat</h3>
                <p>Available 24/7</p>
              </div>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  </section>

  <!-- Returns Page CSS -->
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

  .returns-content {
    max-width: 1000px;
    margin: 0 auto;
  }

  .policy-section {
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

  .policy-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
  }

  .policy-item {
    text-align: center;
    padding: 20px;
    background: #fff;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
  }

  .policy-item i {
    font-size: 32px;
    margin-bottom: 15px;
  }

  .policy-item h3 {
    color: #0A1F33;
    margin-bottom: 10px;
    font-size: 1.2rem;
  }

  .policy-item p {
    color: #6B7280;
    margin: 0;
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

  .conditions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
  }

  .condition-card {
    padding: 25px;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
  }

  .condition-card.eligible {
    background: rgba(16, 185, 129, 0.1);
    border-color: #10b981;
  }

  .condition-card.not-eligible {
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

  .eligible .card-header i {
    color: #10b981;
  }

  .not-eligible .card-header i {
    color: #ef4444;
  }

  .card-header h3 {
    color: #0A1F33;
    margin: 0;
    font-size: 1.2rem;
  }

  .condition-card ul {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  .condition-card li {
    padding: 8px 0;
    color: #6B7280;
    border-bottom: 1px solid #DCDCDD;
  }

  .condition-card li:last-child {
    border-bottom: none;
  }

  .exchange-info {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .exchange-item {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    padding: 20px;
    background: #fff;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
  }

  .exchange-item i {
    font-size: 24px;
    color: #0A1F33;
    margin-top: 5px;
  }

  .exchange-item h3 {
    color: #0A1F33;
    margin-bottom: 8px;
    font-size: 1.2rem;
  }

  .exchange-item p {
    color: #6B7280;
    margin: 0;
    line-height: 1.6;
  }

  .special-cases {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .case-item {
    padding: 20px;
    background: #fff;
    border-radius: 15px;
    border: 1px solid #DCDCDD;
  }

  .case-item h3 {
    color: #0A1F33;
    margin-bottom: 12px;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .case-item h3 i {
    color: #f59e0b;
  }

  .case-item p {
    color: #6B7280;
    margin: 0;
    line-height: 1.6;
  }

  .contact-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
  }

  .contact-option {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: #fff;
    border: 1px solid #DCDCDD;
    border-radius: 15px;
    text-decoration: none;
    color: #0A1F33;
    transition: all 0.3s ease;
  }

  .contact-option:hover {
    background: rgba(139, 76, 108, 0.08);
    border-color: #8B4C6C;
    transform: translateY(-2px);
  }

  .contact-option i {
    font-size: 24px;
    color: #8B4C6C;
  }

  .contact-option h3 {
    color: #0A1F33;
    margin-bottom: 5px;
    font-size: 1.1rem;
  }

  .contact-option p {
    color: #6B7280;
    margin: 0;
  }

  @media (max-width: 768px) {
    .page-header h1 {
      font-size: 2rem;
    }
    
    .section-content {
      padding: 20px;
    }
    
    .step {
      flex-direction: column;
      text-align: center;
    }
    
    .exchange-item {
      flex-direction: column;
      text-align: center;
    }
  }
  </style>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
