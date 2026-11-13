<?php
// FAQ Page
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
$pageTitle = 'Frequently Asked Questions - TechWorld';
$activeNav = 'faq';
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
      <h1><i class="fi fi-rr-interrogation" style="margin-right: 10px; color: #0A1F33;"></i>Frequently Asked Questions</h1>
      <p>Find answers to common questions about our products, services, and policies.</p>
    </div>

    <div class="faq-content">
      <!-- Search Box -->
      <div class="search-section">
        <div class="search-box">
          <i class="fi fi-rr-search"></i>
          <input type="text" id="faqSearch" placeholder="Search FAQs...">
        </div>
      </div>

      <!-- FAQ Categories -->
      <div class="faq-categories">
        <button class="category-btn active" data-category="all">All Questions</button>
        <button class="category-btn" data-category="orders">Orders & Shipping</button>
        <button class="category-btn" data-category="products">Products</button>
        <button class="category-btn" data-category="returns">Returns & Exchanges</button>
        <button class="category-btn" data-category="warranty">Warranty</button>
        <button class="category-btn" data-category="technical">Technical Support</button>
      </div>

      <!-- FAQ Items -->
      <div class="faq-list">
        <!-- Orders & Shipping -->
        <div class="faq-item" data-category="orders">
          <div class="faq-question">
            <h3>How long does shipping take?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Standard shipping takes 3-5 business days within Metro Manila and 5-7 business days for provincial areas. Express shipping (1-2 days) is available for an additional fee. International shipping takes 7-14 business days depending on the destination.</p>
          </div>
        </div>

        <div class="faq-item" data-category="orders">
          <div class="faq-question">
            <h3>What are your shipping rates?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>We offer free shipping for orders over ₱5,000 within Metro Manila. Standard shipping rates are ₱150 for Metro Manila and ₱250 for provincial areas. Express shipping is ₱300 for Metro Manila and ₱400 for provincial areas.</p>
          </div>
        </div>

        <div class="faq-item" data-category="orders">
          <div class="faq-question">
            <h3>Can I track my order?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Yes! Once your order ships, you'll receive a tracking number via email and SMS. You can track your package using our website or the courier's tracking system.</p>
          </div>
        </div>

        <div class="faq-item" data-category="orders">
          <div class="faq-question">
            <h3>Do you ship internationally?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Yes, we ship to most countries worldwide. International shipping rates vary by destination and weight. Please contact us for a shipping quote to your specific location.</p>
          </div>
        </div>

        <!-- Products -->
        <div class="faq-item" data-category="products">
          <div class="faq-question">
            <h3>Are your products genuine and authentic?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Absolutely! All our products are 100% genuine and come directly from authorized distributors and manufacturers. We provide certificates of authenticity and warranty cards with every purchase.</p>
          </div>
        </div>

        <div class="faq-item" data-category="products">
          <div class="faq-question">
            <h3>Do you offer product recommendations?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Yes! Our technical experts can help you choose the right components for your needs. Contact our support team with your requirements and budget, and we'll provide personalized recommendations.</p>
          </div>
        </div>

        <div class="faq-item" data-category="products">
          <div class="faq-question">
            <h3>Can I request a specific brand or model?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>If you don't see a specific product on our website, contact us and we'll do our best to source it for you. We have access to a wide range of suppliers and can often fulfill special requests.</p>
          </div>
        </div>

        <!-- Returns & Exchanges -->
        <div class="faq-item" data-category="returns">
          <div class="faq-question">
            <h3>What is your return policy?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>We offer a 30-day return policy for most items. Items must be in original condition with all packaging and accessories. Some items like software and consumables may have different return policies.</p>
          </div>
        </div>

        <div class="faq-item" data-category="returns">
          <div class="faq-question">
            <h3>How do I return an item?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Contact our support team at techworldcomputerstore@gmail.com with your order number and reason for return. We'll provide you with a prepaid return label and instructions for packaging and shipping.</p>
          </div>
        </div>

        <div class="faq-item" data-category="returns">
          <div class="faq-question">
            <h3>How long does it take to process a refund?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Once we receive and inspect your returned item, refunds are processed within 5-7 business days. The refund will be credited to your original payment method.</p>
          </div>
        </div>

        <!-- Warranty -->
        <div class="faq-item" data-category="warranty">
          <div class="faq-question">
            <h3>What warranty do you provide?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>We provide manufacturer warranties ranging from 1-5 years depending on the product. Processors and motherboards typically have 3-year warranties, while memory has lifetime warranties. All warranty terms are clearly stated on product pages.</p>
          </div>
        </div>

        <div class="faq-item" data-category="warranty">
          <div class="faq-question">
            <h3>How do I claim warranty service?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Contact our warranty department at techworldcomputerstore@gmail.com with your order number, product details, and description of the issue. We'll guide you through the warranty claim process and arrange for repair or replacement.</p>
          </div>
        </div>

        <div class="faq-item" data-category="warranty">
          <div class="faq-question">
            <h3>Do you offer extended warranties?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Yes! We offer extended warranty options ranging from 1-3 additional years. Extended warranties include accidental damage protection and priority support. Contact us for pricing and details.</p>
          </div>
        </div>

        <!-- Technical Support -->
        <div class="faq-item" data-category="technical">
          <div class="faq-question">
            <h3>Do you provide technical support?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Yes! Our technical support team can help with installation, configuration, and troubleshooting. We provide support via email, phone, and live chat. Premium support is available for complex issues.</p>
          </div>
        </div>

        <div class="faq-item" data-category="technical">
          <div class="faq-question">
            <h3>Can you help me build a custom PC?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Absolutely! We offer custom PC building services. Our experts will help you select compatible components, assemble your system, and install the operating system. Contact us for a consultation and quote.</p>
          </div>
        </div>

        <div class="faq-item" data-category="technical">
          <div class="faq-question">
            <h3>Do you provide installation services?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>Yes! We offer installation services for components like CPUs, RAM, storage drives, and graphics cards. We also provide software installation and system optimization services. Fees vary by service type.</p>
          </div>
        </div>

        <div class="faq-item" data-category="technical">
          <div class="faq-question">
            <h3>What if my product is defective?</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>If you receive a defective product, contact us immediately. We'll arrange for a replacement or refund at no cost to you. Defective items are covered under our warranty policy and return policy.</p>
          </div>
        </div>
      </div>

      <!-- Contact Support -->
      <div class="contact-support">
        <div class="support-card">
          <div class="card-header">
            <i class="fi fi-rr-headset"></i>
            <h2>Still Need Help?</h2>
          </div>
          <div class="card-content">
            <p>Can't find the answer you're looking for? Our support team is here to help!</p>
            <div class="contact-options">
              <a href="<?= WEB_ROOT ?>public/authenticated/customer/support.php" class="contact-btn">
                <i class="fi fi-rr-envelope"></i>
                <span>Email Support</span>
              </a>
              <a href="tel:+63212345678" class="contact-btn">
                <i class="fi fi-rr-phone-call"></i>
                <span>Call Us</span>
              </a>
              <a href="<?= WEB_ROOT ?>public/authenticated/customer/support.php" class="contact-btn">
                <i class="fi fi-rr-message"></i>
                <span>Live Chat</span>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  </section>

  <!-- FAQ Page CSS -->
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

  .faq-content {
    max-width: 800px;
    margin: 0 auto;
  }

  .search-section {
    margin-bottom: 30px;
  }

  .search-box {
    position: relative;
    max-width: 500px;
    margin: 0 auto;
  }

  .search-box i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #6B7280;
    font-size: 18px;
  }

  .search-box input {
    width: 100%;
    padding: 15px 15px 15px 50px;
    border: 2px solid #DCDCDD;
    border-radius: 15px;
    background: #fff;
    color: #0A1F33;
    font-size: 1rem;
    transition: border-color 0.3s ease, background 0.3s ease;
  }

  .search-box input:focus {
    outline: none;
    border-color: #8B4C6C;
    background: #fff;
  }

  .search-box input::placeholder {
    color: #6B7280;
  }

  .faq-categories {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 40px;
    justify-content: center;
  }

  .category-btn {
    padding: 10px 20px;
    border: 2px solid #DCDCDD;
    border-radius: 25px;
    background: #fff;
    color: #6B7280;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    font-weight: 500;
  }

  .category-btn:hover,
  .category-btn.active {
    border-color: #8B4C6C;
    background: rgba(139, 76, 108, 0.08);
    color: #8B4C6C;
  }

  .faq-list {
    margin-bottom: 50px;
  }

  .faq-item {
    background: #fff;
    border: 1px solid #DCDCDD;
    border-radius: 15px;
    margin-bottom: 15px;
    overflow: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 4px 14px rgba(10, 31, 51, 0.06);
  }

  .faq-item:hover {
    border-color: rgba(139, 76, 108, 0.4);
  }

  .faq-item.hidden {
    display: none;
  }

  .faq-question {
    padding: 20px 25px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background 0.3s ease;
  }

  .faq-question:hover {
    background: rgba(139, 76, 108, 0.05);
  }

  .faq-question h3 {
    color: #0A1F33;
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
  }

  .faq-question i {
    color: #8B4C6C;
    font-size: 18px;
    transition: transform 0.3s ease;
  }

  .faq-item.active .faq-question i {
    transform: rotate(45deg);
  }

  .faq-answer {
    padding: 0 25px;
    max-height: 0;
    overflow: hidden;
    transition: all 0.3s ease;
  }

  .faq-item.active .faq-answer {
    padding: 0 25px 25px;
    max-height: 200px;
  }

  .faq-answer p {
    color: #6B7280;
    line-height: 1.6;
    margin: 0;
  }

  .contact-support {
    margin-top: 50px;
  }

  .support-card {
    background: #fff;
    border: 1px solid #DCDCDD;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(10, 31, 51, 0.08);
  }

  .card-header {
    background: linear-gradient(135deg, #16324f, #0A1F33);
    padding: 25px 30px;
    display: flex;
    align-items: center;
    gap: 15px;
  }

  .card-header i {
    font-size: 24px;
    color: white;
  }

  .card-header h2 {
    color: white;
    font-size: 1.5rem;
    margin: 0;
  }

  .card-content {
    padding: 30px;
    text-align: center;
  }

  .card-content p {
    color: #6B7280;
    margin-bottom: 25px;
    font-size: 1.1rem;
  }

  .contact-options {
    display: flex;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
  }

  .contact-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 25px;
    background: rgba(139, 76, 108, 0.08);
    border: 2px solid #8B4C6C;
    border-radius: 10px;
    color: #8B4C6C;
    text-decoration: none;
    transition: all 0.3s ease;
    font-weight: 600;
  }

  .contact-btn:hover {
    background: #8B4C6C;
    color: white;
    transform: translateY(-2px);
  }

  .contact-btn i {
    font-size: 16px;
  }

  @media (max-width: 768px) {
    .page-header h1 {
      font-size: 2rem;
    }
    
    .faq-categories {
      flex-direction: column;
      align-items: center;
    }
    
    .category-btn {
      width: 200px;
      text-align: center;
    }
    
    .contact-options {
      flex-direction: column;
      align-items: center;
    }
    
    .contact-btn {
      width: 200px;
      justify-content: center;
    }
  }
  </style>

  <script>
  // FAQ functionality
  document.addEventListener('DOMContentLoaded', function() {
    const faqList = document.querySelector('.faq-list');
    const categoryBtns = document.querySelectorAll('.category-btn');
    const searchInput = document.getElementById('faqSearch');
    
    let allFAQs = [];
    let currentCategory = 'all';
    let useDatabase = false;

    // Initialize FAQ functionality
    initializeFAQs();

    function initializeFAQs() {
      // First, make existing static FAQs clickable
      attachFAQEventListeners();
      attachCategoryEventListeners();
      
      // Try to load from database, fallback to static content
      loadFAQsFromDatabase();
    }

    function loadFAQsFromDatabase() {
      fetch('<?= WEB_ROOT ?>api/support.php?action=get_faq')
        .then(response => response.json())
        .then(result => {
          if (result.success && result.faqs && result.faqs.length > 0) {
            allFAQs = result.faqs;
            useDatabase = true;
            renderFAQs(allFAQs);
            updateCategoryButtons(result.categories);
          } else {
            // Use static content
            useDatabase = false;
            console.log('Using static FAQ content');
          }
        })
        .catch(error => {
          console.error('Error loading FAQs from database, using static content:', error);
          useDatabase = false;
        });
    }

    function renderFAQs(faqs) {
      if (!useDatabase) return;
      
      faqList.innerHTML = '';
      
      faqs.forEach(faq => {
        const faqItem = document.createElement('div');
        faqItem.className = 'faq-item';
        faqItem.setAttribute('data-category', faq.category_name.toLowerCase().replace(/\s+/g, '_'));
        
        faqItem.innerHTML = `
          <div class="faq-question">
            <h3>${faq.question}</h3>
            <i class="fi fi-rr-plus"></i>
          </div>
          <div class="faq-answer">
            <p>${faq.answer}</p>
          </div>
        `;
        
        faqList.appendChild(faqItem);
      });
      
      // Re-attach event listeners
      attachFAQEventListeners();
    }

    function updateCategoryButtons(categories) {
      if (!useDatabase) return;
      
      const categoriesContainer = document.querySelector('.faq-categories');
      categoriesContainer.innerHTML = '<button class="category-btn active" data-category="all">All Questions</button>';
      
      categories.forEach(category => {
        const btn = document.createElement('button');
        btn.className = 'category-btn';
        btn.setAttribute('data-category', category.name.toLowerCase().replace(/\s+/g, '_'));
        btn.textContent = category.name;
        categoriesContainer.appendChild(btn);
      });
      
      // Re-attach category event listeners
      attachCategoryEventListeners();
    }

    function attachFAQEventListeners() {
      const faqItems = document.querySelectorAll('.faq-item');
      
      faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        if (question && !question.hasAttribute('data-listener-attached')) {
          question.setAttribute('data-listener-attached', 'true');
          question.addEventListener('click', () => {
            const isActive = item.classList.contains('active');
            
            // Close all other items
            faqItems.forEach(otherItem => {
              if (otherItem !== item) {
                otherItem.classList.remove('active');
              }
            });
            
            // Toggle current item
            item.classList.toggle('active', !isActive);
          });
        }
      });
    }

    function attachCategoryEventListeners() {
      const categoryBtns = document.querySelectorAll('.category-btn');
      
      categoryBtns.forEach(btn => {
        if (!btn.hasAttribute('data-listener-attached')) {
          btn.setAttribute('data-listener-attached', 'true');
          btn.addEventListener('click', () => {
            const category = btn.getAttribute('data-category');
            currentCategory = category;
            
            // Update active button
            categoryBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            // Filter FAQs
            filterFAQs(category);
          });
        }
      });
    }

    function filterFAQs(category) {
      if (!useDatabase) {
        // Filter static content
        const faqItems = document.querySelectorAll('.faq-item');
        faqItems.forEach(item => {
          const itemCategory = item.getAttribute('data-category');
          if (category === 'all' || itemCategory === category) {
            item.style.display = 'block';
          } else {
            item.style.display = 'none';
          }
        });
        return;
      }
      
      let filteredFAQs = allFAQs;
      
      if (category !== 'all') {
        const categoryName = category.replace(/_/g, ' ');
        filteredFAQs = allFAQs.filter(faq => 
          faq.category_name.toLowerCase() === categoryName
        );
      }
      
      renderFAQs(filteredFAQs);
    }

    // Search functionality
    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        
        if (searchTerm.length >= 2) {
          if (useDatabase) {
            // Search in database
            fetch(`<?= WEB_ROOT ?>api/support.php?action=search_faq&q=${encodeURIComponent(searchTerm)}`)
              .then(response => response.json())
              .then(result => {
                if (result.success) {
                  renderFAQs(result.faqs);
                }
              })
              .catch(error => {
                console.error('Error searching FAQs:', error);
                // Fallback to local search
                searchStaticFAQs(searchTerm);
              });
          } else {
            // Search in static content
            searchStaticFAQs(searchTerm);
          }
        } else if (searchTerm.length === 0) {
          // Reset to current category
          filterFAQs(currentCategory);
        }
      });
    }

    function searchStaticFAQs(searchTerm) {
      const faqItems = document.querySelectorAll('.faq-item');
      faqItems.forEach(item => {
        const question = item.querySelector('.faq-question h3').textContent.toLowerCase();
        const answer = item.querySelector('.faq-answer p').textContent.toLowerCase();
        
        if (question.includes(searchTerm) || answer.includes(searchTerm)) {
          item.style.display = 'block';
        } else {
          item.style.display = 'none';
        }
      });
    }

    // Make contact buttons clickable
    const contactBtns = document.querySelectorAll('.contact-btn');
    contactBtns.forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const btnText = btn.textContent.trim();
        
        if (btnText.includes('Email Support')) {
          window.location.href = '<?= WEB_ROOT ?>public/authenticated/customer/support.php';
        } else if (btnText.includes('Call Us')) {
          window.location.href = 'tel:+63212345678';
        } else if (btnText.includes('Live Chat')) {
          window.location.href = '<?= WEB_ROOT ?>public/authenticated/customer/support.php';
        }
      });
    });
  });
  </script>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
