<?php
// Customer Service Support Page
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_CUST'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
$pageTitle = 'Customer Service - TechWorld';
$activeNav = 'support';

// This page is deliberately NOT behind require_login(): the contact details,
// business hours and Quick Help links are worth showing to anyone. Only the
// message form needs an account - api/support.php files the ticket against the
// session user so there is somewhere to reply to - so the form is prefilled and
// shown to a signed-in customer and replaced by a sign-in prompt otherwise. It
// used to render for everyone and then fail at submit time.
$supportUser = null;
if (isLoggedIn()) {
    try {
        $stmt = getConnection()->prepare('SELECT firstname, lastname, email, phone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([getUserId()]);
        $supportUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        // Prefill is a convenience; the form still works typed by hand.
        error_log('Support page prefill failed: ' . $e->getMessage());
    }
}
$supportName = $supportUser
    ? trim(($supportUser['firstname'] ?? '') . ' ' . ($supportUser['lastname'] ?? ''))
    : '';

/* The orders this account actually placed, for the "Order" picker below.
   That field used to be a free-text box, and what it collected was things
   like "41412341241241" - a number belonging to no order, which the support
   team then has to chase the customer about. Offering only the customer's own
   orders makes a wrong one impossible to type; api/support.php re-checks
   ownership on submit, because a picker is a convenience and not a control.
   Capped at 50 and newest first: this is a picker, not an order history -
   customer/orders.php is that. */
$supportOrders = [];
if ($supportUser) {
    try {
        $stmt = getConnection()->prepare(
            'SELECT id, invoice_no, status, total_amount, created_at
               FROM orders
              WHERE user_id = ?
           ORDER BY created_at DESC
              LIMIT 50'
        );
        $stmt->execute([getUserId()]);
        $supportOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // The picker degrades to "Not about a specific order" - the form
        // still sends, and a customer can say the order number in the message.
        error_log('Support order picker failed: ' . $e->getMessage());
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
  
  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">

  <!-- Figtree for headings, JetBrains Mono for eyebrows and small-caps
       labels - the pair the landing page and the other redesigned customer
       pages load. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
</head>
<body>
  <?php include __DIR__ . '/includes/header.php'; ?>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <section class="account-page">
  <div class="container">
    <div class="page-header">
      <div class="cx-eyebrow"><span aria-hidden="true"></span>Support</div>
      <h1 class="cx-title"><i class="fi fi-rr-headset" aria-hidden="true"></i>Customer <em>Service</em></h1>
      <p class="cx-lede">We're here to help you with any questions or concerns. Get in touch with our support team.</p>
    </div>
    <div class="cx-rule" aria-hidden="true"><i></i></div>

    <div class="support-grid">
      <!-- Contact Information -->
      <div class="support-card">
        <div class="card-header">
          <i class="fi fi-rr-phone-call"></i>
          <h3>Contact Information</h3>
        </div>
        <div class="card-content">
          <div class="contact-item">
            <i class="fi fi-rr-envelope" aria-hidden="true"></i>
            <div>
              <strong>Email Support</strong>
              <p>techworldcomputerstore@gmail.com</p>
              <small>Response within 24 hours</small>
            </div>
          </div>
          <div class="contact-item">
            <i class="fi fi-rr-phone-call" aria-hidden="true"></i>
            <div>
              <strong>Phone Support</strong>
              <p>0995 586 7973</p>
              <small>Mon - Sat: 9AM-5PM, Sun: Closed</small>
            </div>
          </div>
          <div class="contact-item">
            <i class="fi fi-rr-clock" aria-hidden="true"></i>
            <div>
              <strong>Live Chat</strong>
              <p>Available 24/7</p>
              <small>Click the chat icon in bottom right</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Support Form -->
      <div class="support-card">
        <div class="card-header">
          <i class="fi fi-rr-comment-alt"></i>
          <h3>Send us a Message</h3>
        </div>
        <div class="card-content">
          <?php if (!$supportUser): ?>
            <p class="support-signin-note">
              Please <a href="<?= WEB_ROOT ?>public/guest/login.php">sign in</a> to send us a message,
              so we have somewhere to send the reply. You can still reach us on the phone number and
              email address listed here.
            </p>
          <?php else: ?>
          <form id="supportForm" class="support-form">
            <?= csrf_field() ?>
            <?php /* Name and email are prefilled from the account and read-only:
                     the ticket is filed against the SIGNED-IN user whatever is
                     typed here, so an editable box that changes nothing is a box
                     that lies.

                     Every field below carries its own ceiling: maxlength here,
                     a matching check in the submit handler, and the real one in
                     api/support.php. The browser attribute is the courtesy; the
                     endpoint is the rule, since nothing stops a client posting
                     straight to it. */ ?>
            <div class="form-group">
              <label for="name">Full Name *</label>
              <input type="text" id="name" name="name" required readonly maxlength="100"
                     autocomplete="name"
                     value="<?= htmlspecialchars($supportName) ?>">
            </div>
            <div class="form-group">
              <label for="email">Email Address *</label>
              <input type="email" id="email" name="email" required readonly maxlength="150"
                     autocomplete="email"
                     value="<?= htmlspecialchars((string)($supportUser['email'] ?? '')) ?>">
            </div>
            <div class="form-group">
              <label for="phone">Phone Number</label>
              <?php /* Optional, and the one editable identity field - "call me
                       on this one instead" is a normal thing to want. Letters
                       are refused as they are typed rather than at submit, so
                       the limit teaches instead of scolding. */ ?>
              <input type="tel" id="phone" name="phone" maxlength="20" inputmode="tel"
                     autocomplete="tel" pattern="[0-9+()\s-]{7,20}"
                     aria-describedby="phoneHint"
                     value="<?= htmlspecialchars((string)($supportUser['phone'] ?? '')) ?>">
              <p class="field-hint" id="phoneHint">Optional &middot; digits, spaces and <code>+ ( ) -</code> only, e.g. 09171234567</p>
              <p class="field-error" id="phoneError" role="alert" hidden></p>
            </div>
            <div class="form-group">
              <label for="subject">Subject *</label>
              <select id="subject" name="subject" required>
                <option value="">Select a topic</option>
                <option value="product_inquiry">Product Inquiry</option>
                <option value="order_support">Order Support</option>
                <option value="technical_issue">Technical Issue</option>
                <option value="warranty_claim">Warranty Claim</option>
                <option value="return_request">Return Request</option>
                <option value="billing_question">Billing Question</option>
                <option value="other">Other</option>
              </select>
              <p class="field-error" id="subjectError" role="alert" hidden></p>
            </div>
            <div class="form-group">
              <label for="order_number">Order (if applicable)</label>
              <select id="order_number" name="order_number" aria-describedby="orderHint">
                <option value="">Not about a specific order</option>
                <?php foreach ($supportOrders as $o): ?>
                  <?php
                    $oRef   = ($o['invoice_no'] ?? '') !== '' ? $o['invoice_no'] : '#' . (int)$o['id'];
                    $oWhen  = $o['created_at'] ? date('M j, Y', strtotime((string)$o['created_at'])) : '';
                    $oTotal = '₱' . number_format((float)($o['total_amount'] ?? 0), 2);
                    $oStat  = ucfirst(str_replace('_', ' ', (string)($o['status'] ?? '')));
                  ?>
                  <?php /* One line, no indentation inside the tag: an option's
                           text is read back in the confirmation dialog, and
                           leading whitespace there is whitespace in the label. */ ?>
                  <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($oRef) ?> &middot; <?= htmlspecialchars($oWhen) ?> &middot; <?= htmlspecialchars($oTotal) ?> &middot; <?= htmlspecialchars($oStat) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field-hint" id="orderHint">
                <?php if ($supportOrders): ?>
                  Pick the order this is about so we do not have to ask. Your <?= count($supportOrders) ?> most recent order<?= count($supportOrders) === 1 ? '' : 's' ?> <?= count($supportOrders) === 1 ? 'is' : 'are' ?> listed.
                <?php else: ?>
                  No orders on this account yet &ndash; leave this as it is.
                <?php endif; ?>
              </p>
            </div>
            <div class="form-group">
              <label for="message">Message *</label>
              <textarea id="message" name="message" rows="5" required minlength="15" maxlength="2000"
                        aria-describedby="messageHint"
                        placeholder="Please describe your issue or question in detail..."></textarea>
              <p class="field-hint" id="messageHint">
                <span id="messageCount">0</span>/2000 characters &middot; at least 15, so we can act on it
              </p>
              <p class="field-error" id="messageError" role="alert" hidden></p>
            </div>
            <p class="form-status" id="formStatus" role="status" aria-live="polite" hidden></p>
            <button type="submit" class="submit-btn">
              <i class="fi fi-rr-paper-plane"></i>
              Send Message
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick Help -->
      <div class="support-card">
        <div class="card-header">
          <i class="fi fi-rr-interrogation"></i>
          <h3>Quick Help</h3>
        </div>
        <div class="card-content">
          <div class="help-links">
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/faq.php" class="help-link">
              <i class="fi fi-rr-interrogation"></i>
              <span>Frequently Asked Questions</span>
            </a>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/returns.php" class="help-link">
              <i class="fi fi-rr-undo"></i>
              <span>Return Policy</span>
            </a>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/warranty.php" class="help-link">
              <i class="fi fi-rr-shield-check"></i>
              <span>Warranty Information</span>
            </a>
            <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="help-link">
              <i class="fi fi-rr-shopping-cart"></i>
              <span>Browse Products</span>
            </a>
          </div>
        </div>
      </div>

      <!-- Business Hours -->
      <div class="support-card">
        <div class="card-header">
          <i class="fi fi-rr-calendar"></i>
          <h3>Business Hours</h3>
        </div>
        <div class="card-content">
          <div class="hours-list">
            <div class="hours-item">
              <span>Monday - Friday</span>
              <span>9:00 AM - 6:00 PM</span>
            </div>
            <div class="hours-item">
              <span>Saturday</span>
              <span>9:00 AM - 2:00 PM</span>
            </div>
            <div class="hours-item">
              <span>Sunday</span>
              <span>Closed</span>
            </div>
            <div class="hours-item highlight">
              <span>Emergency Support</span>
              <span>24/7 via Email</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php /* ------------------------------------------------------------------
         My Support Tickets.

         Only for a signed-in customer, for the same reason the message form is:
         a ticket belongs to an account. Everything above this point - the
         contact details, hours and quick help - is unchanged and still shows
         to everyone.

         This is what turns the page from a send-only contact form into a
         conversation: until now a customer could file a ticket and had no way
         to see the reply except the notification bell.
         ------------------------------------------------------------------ */ ?>
    <?php if ($supportUser): ?>
    <section class="tickets-section" id="myTickets">
      <div class="cx-rule" aria-hidden="true"><i></i></div>
      <div class="tickets-head">
        <h2 class="tickets-title"><i class="fi fi-rr-comments" aria-hidden="true"></i> My Support Tickets</h2>
        <button type="button" class="tickets-refresh" id="ticketsRefresh">
          <i class="fi fi-rr-refresh" aria-hidden="true"></i> Refresh
        </button>
      </div>
      <p class="tickets-lede">Open a ticket to read the replies and continue the conversation.</p>
      <div id="ticketsList" class="tickets-list">
        <p class="tickets-empty">Loading your tickets&hellip;</p>
      </div>
    </section>
    <?php endif; ?>
  </div>
  </section>

  <?php if ($supportUser): ?>
  <?php /* The last read-through before a ticket exists. A sent ticket cannot
           be unsent, it notifies the support team, and the mistakes that
           actually happen here - the wrong order picked, a message sent
           half-typed, the same message sent twice because nothing looked like
           it worked - are all ones a customer can only catch by being shown
           what they are about to send. Built from the form's own values when
           it opens; nothing is filled in here. */ ?>
  <div class="confirm-overlay" id="sendConfirm" role="dialog" aria-modal="true"
       aria-labelledby="sendConfirmTitle" hidden>
    <div class="confirm-panel">
      <h3 id="sendConfirmTitle">Send this to Customer Service?</h3>
      <p class="confirm-lede">
        This opens a support ticket our team replies to. Have a last look &ndash;
        you can go back and edit anything.
      </p>
      <dl class="confirm-list" id="sendConfirmList"></dl>
      <div class="confirm-actions">
        <button type="button" class="confirm-btn is-ghost" id="sendConfirmCancel">Go back and edit</button>
        <button type="button" class="confirm-btn is-go" id="sendConfirmGo">Yes, send it</button>
      </div>
    </div>
  </div>

  <?php /* The conversation itself. One dialog reused for whichever ticket is
           opened, rather than one per ticket: a customer with twenty tickets
           would otherwise ship twenty copies of this markup on every page
           load. Hidden until a ticket is chosen. */ ?>
  <div class="chat-overlay" id="chatOverlay" role="dialog" aria-modal="true"
       aria-labelledby="chatTitle" hidden>
    <div class="chat-panel">
      <div class="chat-head">
        <div class="chat-head-text">
          <h3 id="chatTitle">Ticket</h3>
          <p id="chatSubtitle"></p>
        </div>
        <button type="button" class="chat-close" id="chatClose" aria-label="Close conversation">
          <i class="fi fi-rr-cross-small" aria-hidden="true"></i>
        </button>
      </div>

      <div class="chat-thread" id="chatThread"></div>

      <div class="chat-foot" id="chatFoot">
        <div class="chat-locked" id="chatLocked" hidden>
          <i class="fi fi-rr-check-circle" aria-hidden="true"></i>
          <span>This ticket has been marked resolved, so it is no longer accepting
                replies. Your full conversation stays here. If you still need help,
                please open a new ticket above.</span>
        </div>
        <form class="chat-composer" id="chatComposer">
          <textarea id="chatInput" rows="2" placeholder="Write a message&hellip;"
                    aria-label="Your message"></textarea>
          <button type="submit" class="chat-send" id="chatSend" aria-label="Send message">
            <i class="fi fi-rr-paper-plane" aria-hidden="true"></i>
          </button>
        </form>
        <p class="chat-feedback" id="chatFeedback" role="status" aria-live="polite"></p>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Support Page CSS -->
  <style>
  /* =========================================================================
     Customer Service, in the landing page's visual language.

     PRESENTATION ONLY. #supportForm, .submit-btn and every field name the
     submit handler reads from FormData (name, email, phone, subject,
     order_number, message) are untouched, as is the POST to
     api/support.php?action=create_ticket.

     The shared .cx-* primitives (eyebrow, title, rule) live in
     customer-dark.css, which also paints this page's ground via
     .account-page.

     What changed: the cards, fields and the submit button were built for a
     light ground - pale surfaces, hard-coded near-black icons, a flat blue
     button. They are now the landing panel (navy gradient over a 1px blue
     hairline) with mono labels, cyan accents and the landing CTA gradient.
     ====================================================================== */
  .account-page { padding: 40px 0 64px; background: var(--page-bg-gradient); min-height: 100vh; }
  .account-page .container { max-width: 1240px; }

  /* --- header ------------------------------------------------------------ */
  .page-header { text-align: left; margin-bottom: 0; }
  .page-header h1 { display: flex; align-items: center; gap: 14px; }
  .page-header h1 i { font-size: .75em; color: var(--cx-cyan); }

  /* --- grid -------------------------------------------------------------- */
  .support-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
    gap: 18px;
    align-items: start;
  }

  /* Above three columns the four cards used to land 3-up, dropping Business
     Hours alone onto a second row and leaving two empty columns beside it -
     a full card's worth of dead space, made worse by how tall the message
     form is. Placing them explicitly instead: the form spans both rows in
     the middle, and the two short cards stack under Contact Information on
     the left, so the column heights roughly match. Source order is unchanged
     (the markup and tab order still read Contact, Message, Quick Help,
     Hours); only the painted position moves. Below this width the grid falls
     back to the auto-fit behaviour above. */
  @media (min-width: 1180px) {
    .support-grid {
      grid-template-columns: 1fr 1.1fr 1fr;
      align-items: start;
    }
    .support-card:nth-child(1) { grid-column: 1; grid-row: 1; }
    .support-card:nth-child(2) { grid-column: 2; grid-row: 1 / span 2; }
    .support-card:nth-child(3) { grid-column: 3; grid-row: 1; }
    .support-card:nth-child(4) { grid-column: 1; grid-row: 2; }
  }

  /* --- cards ------------------------------------------------------------- */
  .support-card {
    position: relative; overflow: hidden;
    border: 1px solid var(--cx-line);
    border-radius: 20px;
    background: var(--cx-panel);
    box-shadow: 0 20px 48px rgba(3, 8, 16, .4);
    transition: transform .22s var(--cx-ease), border-color .22s ease, box-shadow .22s ease;
  }
  .support-card:hover {
    transform: translateY(-2px);
    border-color: var(--cx-line-hi);
    box-shadow: 0 26px 58px rgba(3, 8, 16, .5);
  }
  /* A cool bloom in the corner, so the cards read as lit panels rather than
     flat fills - the same device the landing bento uses. */
  .support-card::after {
    content: ''; position: absolute; inset: 0;
    /* Was a 60%-wide box offset off the right edge. On a tall card the
       circle grew with the box, so it was still tinted where the box ended
       and overflow:hidden sliced it - a straight vertical seam down every
       card, worst on the message form now that it spans two rows. Covering
       the whole card with a fixed-size gradient instead keeps the bloom the
       same size at any height and lets it fade out before any edge. */
    background: radial-gradient(420px 320px at 86% 0%, rgba(103, 232, 249, .12), transparent 72%);
    pointer-events: none;
  }
  .card-header {
    display: flex; align-items: center; gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--cx-line);
    background: rgba(var(--tw-veil-rgb, 255, 255, 255), .02);
  }
  .card-header i {
    width: 38px; height: 38px; flex: none;
    display: grid; place-items: center;
    border: 1px solid rgba(103, 232, 249, .3);
    border-radius: 12px;
    background: rgba(103, 232, 249, .1);
    font-size: 16px; color: var(--cx-cyan);
  }
  .card-header h3 {
    margin: 0;
    font-family: var(--cx-font);
    font-size: 16px; font-weight: 800; letter-spacing: -.01em;
    color: #fff;
  }
  .card-content { position: relative; z-index: 1; padding: 22px 24px 24px; }

  /* --- contact list ------------------------------------------------------ */
  .contact-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--cx-line);
  }
  .contact-item:first-child { padding-top: 0; }
  .contact-item:last-child { padding-bottom: 0; border-bottom: none; }
  .contact-item i {
    width: 34px; height: 34px; flex: none;
    display: grid; place-items: center;
    border-radius: 50%; font-size: 14px;
  }
  /* One hue per channel, all three from the landing palette. */
  .contact-item:nth-of-type(1) i { background: rgba(103, 232, 249, .12); color: var(--cx-cyan); }
  .contact-item:nth-of-type(2) i { background: rgba(224, 86, 122, .14); color: var(--cx-pink-lt); }
  .contact-item:nth-of-type(3) i { background: rgba(74, 222, 128, .12); color: var(--cx-green); }
  .contact-item strong {
    display: block; margin-bottom: 4px;
    font-family: var(--cx-mono); font-size: 9.5px; font-weight: 600;
    letter-spacing: .2em; text-transform: uppercase; color: var(--cx-ink-label);
  }
  .contact-item p {
    margin: 0 0 3px;
    font-family: var(--cx-font); font-size: 15px; font-weight: 600;
    color: #fff; overflow-wrap: anywhere;
  }
  .contact-item small { font-size: 12px; line-height: 1.5; color: var(--cx-ink-faint); }

  /* --- form -------------------------------------------------------------- */
  .support-form { display: flex; flex-direction: column; gap: 15px; }
  .form-group { display: flex; flex-direction: column; }
  .form-group label {
    margin-bottom: 7px;
    font-family: var(--cx-mono); font-size: 9.5px; font-weight: 600;
    letter-spacing: .2em; text-transform: uppercase; color: var(--cx-ink-label);
  }
  .form-group input,
  .form-group select,
  .form-group textarea {
    width: 100%; box-sizing: border-box;
    padding: 13px 16px;
    border: 1px solid var(--cx-line);
    border-radius: 12px;
    background: rgba(6, 13, 24, .65);
    color: #fff;
    font-family: var(--cx-font); font-size: 14px;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
  }
  .form-group textarea { resize: vertical; min-height: 120px; line-height: 1.6; }
  /* Prefilled from the account and not editable - dimmed so it reads as a
     fact about you rather than a box you forgot to fill in. */
  .form-group input[readonly] {
    color: var(--cx-ink-dim);
    cursor: default;
  }
  .form-group input[readonly]:focus {
    border-color: var(--cx-line);
    box-shadow: none;
  }
  /* Shown instead of the form when nobody is signed in. */
  .support-signin-note {
    margin: 0;
    padding: 16px 18px;
    border: 1px solid var(--cx-line);
    border-radius: 14px;
    background: var(--cx-panel-soft);
    color: var(--cx-ink-dim);
    font-size: 14px; line-height: 1.6;
  }
  .support-signin-note a { color: var(--cx-cyan); font-weight: 700; }
  .form-group input::placeholder,
  .form-group textarea::placeholder { color: var(--cx-ink-label); }
  .form-group select option { background: var(--tw-bg, #0a1424); color: #fff; }
  .form-group input:focus,
  .form-group select:focus,
  .form-group textarea:focus {
    outline: none;
    border-color: rgba(103, 232, 249, .55);
    background: rgba(6, 13, 24, .8);
    box-shadow: 0 0 0 3px rgba(103, 232, 249, .12);
  }

  /* The rules above are the intended look, but they never landed. This page
     runs under data-theme="light" (set by includes/header.php), and two
     sheets force form controls to a white fill for that theme with
     !important: style.css `[data-theme="light"] .form-group input` and
     customer-dark.css `[data-theme="light"] body :is(.form-group input, ...)`.
     Neither knows this page is painted on the dark ground, so the inputs came
     out white cards with #0f172a text sitting on a navy panel.

     Re-stating them scoped to .account-page restores the dark fill:
     `body .account-page .form-group input` is (0,2,2), which outranks
     style.css's (0,2,1) outright and ties customer-dark.css's (0,2,2) - and
     this block is later in the document than both, so the tie falls here.
     !important is needed only because the rules being overridden use it. */
  body .account-page .form-group input,
  body .account-page .form-group select,
  body .account-page .form-group textarea {
    background-color: rgba(6, 13, 24, .65) !important;
    color: #fff !important;
    border: 1px solid var(--cx-line) !important;
  }
  body .account-page .form-group input:focus,
  body .account-page .form-group select:focus,
  body .account-page .form-group textarea:focus {
    background-color: rgba(6, 13, 24, .8) !important;
    border-color: rgba(103, 232, 249, .55) !important;
  }
  body .account-page .form-group input::placeholder,
  body .account-page .form-group textarea::placeholder {
    color: var(--cx-ink-label) !important;
  }

  /* The <select> needs two more things the inputs do not. style.css styles
     bare `select` neumorphically - `box-shadow: var(--neu-pressed)`, a pair
     of inset shadows tuned for a pale surface. Left on the dark fill the
     dark inset read as a heavy black ring and the light inset as a halo, so
     the topic picker looked nothing like the fields above it. That rule also
     supplies the dropdown chevron as a background-image; the fill above was
     first written with the `background` shorthand, which reset it and left
     the control with no arrow at all. Hence background-color there, and an
     explicitly restated chevron here in a tone that reads on the dark fill
     rather than style.css's mauve. */
  body .account-page .form-group select {
    box-shadow: none !important;
    padding-right: 42px !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239db1cb' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 15px center !important;
    background-size: 13px !important;
  }
  body .account-page .form-group select:focus {
    box-shadow: 0 0 0 3px rgba(103, 232, 249, .12) !important;
  }
  /* The handler swaps this button's innerHTML for a sending state and
     disables it, so both states are styled. */
  .submit-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 9px;
    margin-top: 4px; padding: 15px 26px;
    border: none; border-radius: 999px;
    background: linear-gradient(120deg, var(--cx-pink), #c2185b);
    color: #fff; cursor: pointer;
    font-family: var(--cx-mono); font-size: 10.5px; font-weight: 700;
    letter-spacing: .16em; text-transform: uppercase;
    box-shadow: 0 12px 30px rgba(224, 86, 122, .3);
    transition: transform .2s var(--cx-ease), box-shadow .2s ease, background .2s ease, opacity .2s ease;
  }
  .submit-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    background: linear-gradient(120deg, var(--cx-pink-lt), var(--cx-pink));
    box-shadow: 0 18px 38px rgba(224, 86, 122, .45);
  }
  .submit-btn:active:not(:disabled) { transform: scale(.98); }
  .submit-btn:disabled { opacity: .6; cursor: progress; }

  /* --- field limits, errors and the send result ---------------------------
     The hint sits under the field it describes and stays put; the error takes
     its place only when there is one, so the form does not jump as you fill
     it in. */
  .field-hint {
    margin: 6px 2px 0; font-size: .76rem; line-height: 1.45;
    color: var(--cx-ink-label);
  }
  .field-hint code {
    font-family: var(--cx-mono); font-size: .95em;
    padding: 1px 4px; border-radius: 4px; background: rgba(255,255,255,.07);
  }
  .field-error {
    margin: 6px 2px 0; font-size: .78rem; line-height: 1.45;
    color: #fca5a5; font-weight: 600;
  }
  .field-error[hidden] { display: none; }
  body .account-page .form-group input.is-invalid,
  body .account-page .form-group select.is-invalid,
  body .account-page .form-group textarea.is-invalid {
    border-color: rgba(248,113,113,.75);
    box-shadow: 0 0 0 3px rgba(248,113,113,.14);
  }
  .form-status {
    margin: 2px 0 0; padding: 11px 14px; border-radius: 12px;
    font-size: .85rem; line-height: 1.5;
    border: 1px solid rgba(34,211,238,.35); background: rgba(34,211,238,.08);
    color: #cbeffb;
  }
  .form-status[hidden] { display: none; }
  .form-status.is-error {
    border-color: rgba(248,113,113,.4); background: rgba(248,113,113,.1);
    color: #fecaca;
  }

  /* --- "send this?" confirmation -----------------------------------------
     Same overlay shape as the conversation dialog below, deliberately: it is
     the same page's second modal and should not read as a different system.
     Its job is the last read-through - a ticket cannot be unsent, and the one
     thing a customer cannot fix afterwards is a message they sent half-typed. */
  .confirm-overlay {
    position: fixed; inset: 0; z-index: 1300;
    display: flex; align-items: center; justify-content: center; padding: 18px;
    background: rgba(4,10,20,.74); backdrop-filter: blur(6px);
  }
  .confirm-overlay[hidden] { display: none; }
  .confirm-panel {
    width: min(560px, 100%); max-height: min(84vh, 720px); overflow-y: auto;
    border-radius: 18px; padding: 22px;
    background: linear-gradient(180deg, #16293f, #0b1627);
    border: 1px solid rgba(80,160,220,.24);
    box-shadow: 0 30px 80px rgba(0,0,0,.55);
  }
  .confirm-panel h3 { margin: 0; font-size: 1.1rem; color: #fff; }
  .confirm-lede {
    margin: 7px 0 16px; font-size: .86rem; line-height: 1.55;
    color: var(--cx-ink-label);
  }
  .confirm-list { margin: 0; display: flex; flex-direction: column; gap: 11px; }
  .confirm-list > div {
    display: grid; grid-template-columns: 112px 1fr; gap: 12px; align-items: baseline;
  }
  .confirm-list dt {
    font-family: var(--cx-mono); font-size: 9.5px; font-weight: 700;
    letter-spacing: .18em; text-transform: uppercase; color: var(--cx-ink-label);
  }
  .confirm-list dd {
    margin: 0; font-size: .9rem; line-height: 1.55; color: #e8f1f8;
    overflow-wrap: anywhere;
  }
  /* The message keeps its line breaks here: it is being proof-read, and a
     paragraph the customer typed as three lines should look like three. */
  .confirm-list dd.is-message {
    white-space: pre-wrap;
    max-height: 190px; overflow-y: auto;
    padding: 10px 12px; border-radius: 11px;
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.09);
  }
  .confirm-actions {
    display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;
    margin-top: 20px;
  }
  .confirm-btn {
    padding: 12px 20px; border-radius: 999px; cursor: pointer;
    font-family: var(--cx-mono); font-size: 10px; font-weight: 700;
    letter-spacing: .16em; text-transform: uppercase;
    transition: transform .18s var(--cx-ease), filter .18s ease;
  }
  .confirm-btn:hover { transform: translateY(-1px); }
  .confirm-btn:disabled { opacity: .6; cursor: progress; transform: none; }
  .confirm-btn.is-ghost {
    background: rgba(255,255,255,.06); color: #cfe3f1;
    border: 1px solid rgba(255,255,255,.16);
  }
  .confirm-btn.is-go {
    border: none; color: #fff;
    background: linear-gradient(120deg, var(--cx-pink), #c2185b);
    box-shadow: 0 12px 30px rgba(224,86,122,.3);
  }
  @media (max-width: 520px) {
    .confirm-list > div { grid-template-columns: 1fr; gap: 3px; }
    .confirm-actions .confirm-btn { flex: 1 1 auto; }
  }
  @media (prefers-reduced-motion: reduce) {
    .confirm-btn:hover { transform: none; }
  }

  /* --- quick help -------------------------------------------------------- */
  .help-links { display: flex; flex-direction: column; gap: 9px; }
  .help-link {
    display: flex; align-items: center; gap: 13px;
    padding: 14px 16px;
    border: 1px solid var(--cx-line);
    border-radius: 14px;
    background: var(--cx-panel-soft);
    color: var(--cx-ink-dim); text-decoration: none;
    font-family: var(--cx-font); font-size: 14px; font-weight: 600;
    transition: transform .18s var(--cx-ease), background .18s ease,
                border-color .18s ease, color .18s ease;
  }
  .help-link i { font-size: 15px; color: var(--cx-cyan); transition: transform .18s var(--cx-ease); }
  .help-link:hover {
    transform: translateX(3px);
    background: rgba(103, 232, 249, .1);
    border-color: rgba(103, 232, 249, .4);
    color: #fff;
  }
  .help-link:hover i { transform: scale(1.1); }

  /* --- business hours ---------------------------------------------------- */
  .hours-list { display: flex; flex-direction: column; }
  .hours-item {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 13px 0;
    border-bottom: 1px solid var(--cx-line);
    font-family: var(--cx-mono); font-size: 11px; letter-spacing: .08em;
    color: var(--cx-ink-faint);
  }
  .hours-item span:last-child { color: #fff; font-weight: 600; }
  .hours-item:last-child { border-bottom: none; padding-bottom: 0; }
  .hours-item.highlight {
    margin-top: 12px; padding: 14px 16px;
    border: 1px solid rgba(74, 222, 128, .38);
    border-radius: 14px;
    background: rgba(74, 222, 128, .09);
    color: var(--cx-green);
  }
  .hours-item.highlight span:last-child { color: var(--cx-green); }

  @media (max-width: 768px) {
    .support-grid { grid-template-columns: 1fr; }
    .card-header { padding: 16px 18px; }
    .card-content { padding: 18px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .support-card:hover, .help-link:hover, .submit-btn:hover:not(:disabled) { transform: none; }
  }

  /* ======================================================================
     MY SUPPORT TICKETS + CONVERSATION
     ----------------------------------------------------------------------
     Added for the two-way ticket conversation. Everything above is the
     original page and is untouched. The palette is the page's own (--cx-*
     tokens from customer-dark.css) so the new block reads as part of the
     same design rather than a bolted-on chat widget.
     ====================================================================== */
  .tickets-section { margin-top: 34px; }
  .tickets-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap; margin-bottom: 6px;
  }
  .tickets-title {
    display: flex; align-items: center; gap: 12px; margin: 0;
    font-size: 1.5rem; font-weight: 800;
  }
  .tickets-title i { color: var(--cx-cyan); font-size: .8em; }
  .tickets-lede { margin: 0 0 18px; color: var(--cx-ink-label); font-size: .95rem; }
  .tickets-refresh {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px; border-radius: 10px; cursor: pointer;
    background: rgba(255,255,255,.04); color: var(--cx-ink-label);
    border: 1px solid rgba(255,255,255,.12);
    font: inherit; font-size: .85rem;
  }
  .tickets-refresh:hover { color: var(--cx-cyan); border-color: var(--cx-cyan); }

  .tickets-list { display: grid; gap: 12px; }
  .tickets-empty { color: var(--cx-ink-label); font-style: italic; margin: 0; }

  .ticket-row {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    padding: 16px 18px; border-radius: 14px; cursor: pointer;
    background: linear-gradient(180deg, rgba(23,42,69,.72), rgba(12,24,42,.72));
    border: 1px solid rgba(80,160,220,.16);
    transition: border-color .2s ease, transform .2s ease;
  }
  .ticket-row:hover { border-color: var(--cx-cyan); transform: translateY(-1px); }
  .ticket-row:focus-visible { outline: 2px solid var(--cx-cyan); outline-offset: 2px; }
  .ticket-row-main { min-width: 0; flex: 1 1 260px; }
  .ticket-row-subject {
    margin: 0 0 4px; font-size: 1.02rem; font-weight: 700; color: #fff;
    overflow-wrap: anywhere;
  }
  .ticket-row-meta {
    margin: 0; font-size: .82rem; color: var(--cx-ink-label);
    font-family: 'JetBrains Mono', monospace;
  }
  .ticket-row-side { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

  .ticket-status {
    display: inline-flex; align-items: center;
    padding: 5px 11px; border-radius: 999px;
    font-size: .72rem; font-weight: 800; letter-spacing: .08em;
    text-transform: uppercase;
  }
  .ticket-status.is-open     { background: rgba(59,130,246,.18);  color: #93c5fd; }
  .ticket-status.is-pending  { background: rgba(245,158,11,.18);  color: #fbbf24; }
  .ticket-status.is-resolved { background: rgba(16,185,129,.18);  color: #6ee7b7; }
  .ticket-status.is-reopened { background: rgba(168,85,247,.18);  color: #d8b4fe; }

  /* The unread count. Red and circular because it has one job: be noticed
     from across the page without being read. */
  .ticket-unread {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 22px; height: 22px; padding: 0 7px;
    border-radius: 999px; background: #ef4444; color: #fff;
    font-size: .74rem; font-weight: 800; line-height: 1;
  }
  .ticket-row.has-unread { border-color: rgba(239,68,68,.55); }

  /* ---- conversation dialog ---------------------------------------------- */
  .chat-overlay {
    position: fixed; inset: 0; z-index: 1200;
    display: flex; align-items: center; justify-content: center; padding: 18px;
    background: rgba(4,10,20,.74); backdrop-filter: blur(6px);
  }
  .chat-overlay[hidden] { display: none; }
  .chat-panel {
    display: flex; flex-direction: column;
    width: min(760px, 100%); max-height: min(78vh, 760px);
    border-radius: 18px; overflow: hidden;
    background: linear-gradient(180deg, #16293f, #0b1627);
    border: 1px solid rgba(80,160,220,.24);
    box-shadow: 0 30px 80px rgba(0,0,0,.55);
  }
  .chat-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 14px; padding: 16px 18px;
    border-bottom: 1px solid rgba(255,255,255,.08);
  }
  .chat-head-text { min-width: 0; }
  .chat-head h3 { margin: 0; font-size: 1.08rem; color: #fff; overflow-wrap: anywhere; }
  .chat-head p {
    margin: 3px 0 0; font-size: .8rem; color: var(--cx-ink-label);
    font-family: 'JetBrains Mono', monospace;
  }
  .chat-close {
    flex-shrink: 0; width: 34px; height: 34px; border-radius: 9px; cursor: pointer;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12);
    color: var(--cx-ink-label); display: grid; place-items: center; font-size: 1.1rem;
  }
  .chat-close:hover { color: #fff; border-color: var(--cx-cyan); }

  /* The scrolling log. flex:1 so it takes whatever height is left after the
     header and composer, which is what keeps the composer pinned in view on a
     long thread instead of being pushed off the bottom of the panel. */
  .chat-thread {
    flex: 1; min-height: 160px; overflow-y: auto;
    display: flex; flex-direction: column; gap: 12px;
    padding: 18px; scroll-behavior: smooth;
  }
  .chat-row { display: flex; flex-direction: column; max-width: 80%; }
  /* The customer is reading their own thread, so THEIR messages sit right -
     the opposite of the admin dashboard, where the admin's do. Each side sees
     itself on the right, which is what every messaging app does and what makes
     the thread readable without parsing the names. */
  .chat-row.is-customer { align-self: flex-end;   align-items: flex-end; }
  .chat-row.is-admin    { align-self: flex-start; align-items: flex-start; }
  .chat-meta {
    display: flex; gap: 8px; align-items: baseline; margin: 0 4px 4px;
    font-size: .72rem; color: var(--cx-ink-label);
    font-family: 'JetBrains Mono', monospace;
  }
  .chat-meta strong { color: #fff; font-weight: 700; }
  .chat-bubble {
    padding: 10px 14px; border-radius: 15px; font-size: .94rem; line-height: 1.55;
    white-space: pre-wrap; overflow-wrap: anywhere;
  }
  .chat-row.is-customer .chat-bubble {
    background: linear-gradient(135deg, #22d3ee, #0ea5e9);
    color: #04202b; border-bottom-right-radius: 5px; font-weight: 500;
  }
  .chat-row.is-admin .chat-bubble {
    background: rgba(255,255,255,.07); color: #e8f1f8;
    border: 1px solid rgba(255,255,255,.1); border-bottom-left-radius: 5px;
  }
  .chat-day {
    align-self: center; font-size: .7rem; letter-spacing: .1em;
    text-transform: uppercase; color: var(--cx-ink-label);
    font-family: 'JetBrains Mono', monospace;
  }
  .chat-empty { margin: auto; color: var(--cx-ink-label); font-style: italic; }

  .chat-foot { padding: 14px 18px 16px; border-top: 1px solid rgba(255,255,255,.08); }
  .chat-locked {
    display: flex; gap: 10px; align-items: flex-start;
    padding: 12px 14px; border-radius: 11px;
    background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.32);
    color: #d6f5e8; font-size: .86rem; line-height: 1.5;
  }
  .chat-locked[hidden] { display: none; }
  .chat-locked i { color: #34d399; margin-top: 2px; }

  .chat-composer { display: flex; gap: 10px; align-items: flex-end; }
  .chat-composer[hidden] { display: none; }
  .chat-composer textarea {
    flex: 1; resize: none; min-height: 44px; max-height: 140px;
    padding: 11px 14px; border-radius: 12px;
    background: rgba(255,255,255,.05); color: #fff;
    border: 1px solid rgba(255,255,255,.14);
    font: inherit; font-size: .94rem; line-height: 1.5;
  }
  .chat-composer textarea:focus {
    outline: none; border-color: var(--cx-cyan);
    box-shadow: 0 0 0 3px rgba(34,211,238,.16);
  }
  .chat-composer textarea:disabled,
  .chat-send:disabled { opacity: .45; cursor: not-allowed; }
  .chat-send {
    flex-shrink: 0; width: 46px; height: 46px; border-radius: 12px; border: none;
    cursor: pointer; display: grid; place-items: center; font-size: 1.05rem;
    background: linear-gradient(135deg, #22d3ee, #0ea5e9); color: #04202b;
  }
  .chat-send:not(:disabled):hover { filter: brightness(1.08); }
  .chat-feedback { margin: 8px 2px 0; min-height: 1.1em; font-size: .82rem; color: var(--cx-ink-label); }
  .chat-feedback.is-error { color: #fca5a5; }

  @media (max-width: 620px) {
    .chat-panel { max-height: 88vh; }
    .chat-row { max-width: 92%; }
  }
  @media (prefers-reduced-motion: reduce) {
    .ticket-row:hover { transform: none; }
    .chat-thread { scroll-behavior: auto; }
  }
  </style>

  <script>
  /* ========================================================================
     The message form: limits, a confirmation, and one send.

     Three things sit between a typed form and a ticket now:

       1. Limits on every field, so the data that reaches the support team is
          usable. Name and email are read-only (the ticket is filed against
          the signed-in account whatever is typed), phone accepts only phone
          characters, the order is picked from this customer's own orders
          rather than typed, and the message has a floor as well as a ceiling
          - "fix it" is not something anyone can act on.
       2. A confirmation step showing exactly what is about to be sent.
       3. One in-flight send at a time, with the result reported in the page
          rather than in an alert() the browser can suppress.

     None of this is security: api/support.php re-checks every one of these,
     owns the rate limit, and is the thing that actually decides. This is here
     so a customer finds out at the field, not after a round trip.
     ===================================================================== */
  (function () {
    var form = document.getElementById('supportForm');
    if (!form) { return; }

    var phoneEl   = document.getElementById('phone');
    var subjectEl = document.getElementById('subject');
    var orderEl   = document.getElementById('order_number');
    var messageEl = document.getElementById('message');
    var countEl   = document.getElementById('messageCount');
    var statusEl  = document.getElementById('formStatus');
    var submitBtn = form.querySelector('.submit-btn');

    var overlay   = document.getElementById('sendConfirm');
    var listEl    = document.getElementById('sendConfirmList');
    var cancelBtn = document.getElementById('sendConfirmCancel');
    var goBtn     = document.getElementById('sendConfirmGo');

    var MSG_MIN = 15, MSG_MAX = 2000;
    var sending = false;

    // ---- small helpers ---------------------------------------------------
    function setError(el, slotId, msg) {
      var slot = document.getElementById(slotId);
      if (slot) { slot.textContent = msg || ''; slot.hidden = !msg; }
      if (el) { el.classList.toggle('is-invalid', !!msg); }
      return !msg;
    }
    function setStatus(msg, isError) {
      statusEl.textContent = msg || '';
      statusEl.hidden = !msg;
      statusEl.classList.toggle('is-error', !!isError);
    }

    // ---- live limits -----------------------------------------------------
    // The counter is the promise the hint makes, so it updates as you type
    // and on paste, not only on blur.
    function updateCount() {
      var n = messageEl.value.length;
      countEl.textContent = String(n);
      if (n >= MSG_MIN) { setError(messageEl, 'messageError', ''); }
    }
    messageEl.addEventListener('input', updateCount);
    updateCount();

    /* Phone keeps only the characters a phone number is made of. Done on
       input rather than at submit: a pasted "+63 (917) 123-4567" survives
       intact, while "call me" simply never appears in the box. The caret is
       put back where it was, or typing in the middle of a number jumps to
       the end on every keystroke. */
    phoneEl.addEventListener('input', function () {
      var before = this.value;
      var caret  = this.selectionStart;
      var after  = before.replace(/[^0-9+()\s-]/g, '').slice(0, 20);
      if (after !== before) {
        this.value = after;
        var delta = before.length - after.length;
        try { this.setSelectionRange(caret - delta, caret - delta); } catch (err) {}
      }
      setError(phoneEl, 'phoneError', '');
    });

    // ---- validation ------------------------------------------------------
    // Returns the first invalid field so focus can go there - a customer
    // should never have to hunt for which box the complaint is about.
    function validate() {
      var firstBad = null;

      var subjectOk = setError(subjectEl, 'subjectError',
        subjectEl.value ? '' : 'Please choose what this is about.');
      if (!subjectOk && !firstBad) { firstBad = subjectEl; }

      var phone = phoneEl.value.trim();
      var digits = phone.replace(/\D/g, '');
      var phoneMsg = '';
      if (phone !== '' && (digits.length < 7 || digits.length > 15)) {
        phoneMsg = 'That does not look like a phone number. Leave it blank if you would rather we emailed you.';
      }
      if (!setError(phoneEl, 'phoneError', phoneMsg) && !firstBad) { firstBad = phoneEl; }

      var msg = messageEl.value.trim();
      var msgMsg = '';
      if (msg.length < MSG_MIN) {
        msgMsg = 'Please tell us a little more - at least ' + MSG_MIN + ' characters, so we can act on it.';
      } else if (msg.length > MSG_MAX) {
        msgMsg = 'That message is too long. Please keep it under ' + MSG_MAX + ' characters.';
      }
      if (!setError(messageEl, 'messageError', msgMsg) && !firstBad) { firstBad = messageEl; }

      return firstBad;
    }

    // ---- the confirmation dialog ----------------------------------------
    function row(label, value, isMessage) {
      var wrap = document.createElement('div');
      var dt = document.createElement('dt');
      dt.textContent = label;
      var dd = document.createElement('dd');
      // textContent throughout: this is the customer's own typing being read
      // back to them, and it is never markup.
      dd.textContent = value;
      if (isMessage) { dd.className = 'is-message'; }
      wrap.appendChild(dt); wrap.appendChild(dd);
      return wrap;
    }

    function openConfirm() {
      var orderText = orderEl.options[orderEl.selectedIndex].text.trim();
      listEl.textContent = '';
      listEl.appendChild(row('From', document.getElementById('name').value));
      listEl.appendChild(row('Reply to', document.getElementById('email').value));
      if (phoneEl.value.trim()) { listEl.appendChild(row('Phone', phoneEl.value.trim())); }
      listEl.appendChild(row('Subject', subjectEl.options[subjectEl.selectedIndex].text));
      listEl.appendChild(row('Order', orderEl.value ? orderText : 'Not about a specific order'));
      listEl.appendChild(row('Message', messageEl.value.trim(), true));
      overlay.hidden = false;
      goBtn.focus();
    }

    function closeConfirm() {
      overlay.hidden = true;
      if (!sending) { submitBtn.focus(); }
    }

    cancelBtn.addEventListener('click', closeConfirm);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay && !sending) { closeConfirm(); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !overlay.hidden && !sending) { closeConfirm(); }
    });

    // ---- submit ----------------------------------------------------------
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (sending) { return; }
      setStatus('');
      var bad = validate();
      if (bad) {
        bad.focus();
        setStatus('Please fix the highlighted field before sending.', true);
        return;
      }
      openConfirm();
    });

    goBtn.addEventListener('click', function () {
      if (sending) { return; }
      sending = true;

      var originalText = submitBtn.innerHTML;
      goBtn.disabled = cancelBtn.disabled = submitBtn.disabled = true;
      goBtn.textContent = 'Sending…';
      submitBtn.innerHTML = '<i class="fi fi-rr-spinner"></i> Sending...';

      var data = {
        csrf_token: form.querySelector('[name="csrf_token"]').value,
        // name/email are not identity - the endpoint files the ticket against
        // the session user - they are posted so the payload matches what the
        // form shows.
        name: document.getElementById('name').value,
        email: document.getElementById('email').value,
        phone: phoneEl.value.trim(),
        subject: subjectEl.value,
        // The id of one of this customer's own orders, or ''. The endpoint
        // resolves it to the order's reference and refuses one that is not
        // theirs - which is what the old free-text box could not do.
        order_id: orderEl.value,
        message: messageEl.value.trim()
      };

      // The real path. This used to be '<?= WEB_ROOT ?>api/support.php', i.e.
      // /api/support.php - a file that does not exist, with no rewrite to it. Every
      // submit 404ed, response.json() threw on the HTML error page, and the catch
      // below reported the generic "An error occurred. Please try again." The
      // endpoint has always lived under public/authenticated/api/.
      fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=create_ticket', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(data)
      })
      // The endpoint answers with a real status code (401/403/422/429/500) and a
      // message explaining it, so a non-OK response still carries something worth
      // showing. Parsing still has to survive a non-JSON body - that is precisely
      // what hid the 404 for so long.
      .then(function (response) {
        return response.json().catch(function () {
          return {
            success: false,
            message: 'Unexpected response from the server (HTTP ' + response.status + ').'
          };
        });
      })
      .then(function (result) {
        closeConfirm();
        if (result.success) {
          form.reset();
          updateCount();
          setStatus('Sent. Your ticket number is ' + (result.ticket_number || '') +
                    ' - we reply within 24 hours. The conversation is open below, and' +
                    ' you can reopen it any time under My Support Tickets.');
          // The list below is this page's own; refresh it so the new ticket
          // is there to open rather than appearing only on the next reload.
          if (typeof window.twSupportReloadTickets === 'function') { window.twSupportReloadTickets(); }

          /* Straight into the conversation it just created.
             The endpoint returns the new row's id for exactly this, so there
             is no second lookup by ticket number. Guarded on both the id and
             the hook: if either is missing the send still succeeded, and the
             status line above already says where to find the thread - this
             must not turn a sent ticket into a visible error. */
          if (result.ticket_id && typeof window.twSupportOpenTicket === 'function') {
            window.twSupportOpenTicket(result.ticket_id);
          }
        } else {
          // A 422 names the field it rejected, so the complaint lands on the
          // box that caused it instead of only in the banner.
          var byField = {
            phone: [phoneEl, 'phoneError'],
            order_number: [orderEl, null],
            message: [messageEl, 'messageError'],
            subject: [subjectEl, 'subjectError']
          }[result.field];
          if (byField && byField[1]) {
            setError(byField[0], byField[1], result.message || '');
            byField[0].focus();
          }
          setStatus(result.message || 'We could not send your message. Please try again.', true);
        }
      })
      .catch(function (error) {
        console.error('Error:', error);
        closeConfirm();
        setStatus('Network error. Please check your connection and try again.', true);
      })
      .finally(function () {
        sending = false;
        goBtn.disabled = cancelBtn.disabled = submitBtn.disabled = false;
        goBtn.textContent = 'Yes, send it';
        submitBtn.innerHTML = originalText;
      });
    });
  })();
  </script>

  <?php if ($supportUser): ?>
  <script>
  /* ======================================================================
     MY SUPPORT TICKETS - list + two-way conversation
     ----------------------------------------------------------------------
     Self-contained. The contact form handler above is not touched: filing a
     NEW ticket still posts to create_ticket exactly as it did, and this block
     only adds reading and replying to tickets that already exist.
     ====================================================================== */
  (function () {
    const API = '<?= WEB_ROOT ?>public/authenticated/api/support.php';
    const CSRF = <?= json_encode(csrf_token()) ?>;

    const listEl     = document.getElementById('ticketsList');
    const overlay    = document.getElementById('chatOverlay');
    const threadEl   = document.getElementById('chatThread');
    const titleEl    = document.getElementById('chatTitle');
    const subtitleEl = document.getElementById('chatSubtitle');
    const composer   = document.getElementById('chatComposer');
    const inputEl    = document.getElementById('chatInput');
    const sendBtn    = document.getElementById('chatSend');
    const lockedEl   = document.getElementById('chatLocked');
    const feedbackEl = document.getElementById('chatFeedback');
    const refreshBtn = document.getElementById('ticketsRefresh');

    // Nothing to wire up if the markup is not on the page (signed out).
    if (!listEl || !overlay) { return; }

    let openTicket = null;
    /* Highest message id already on screen for the open thread. The live
       heartbeat asks for "anything after this", so new replies can be appended
       to the bottom instead of re-fetching and re-rendering the conversation -
       a re-render would throw away the reader's scroll position and whatever
       they had half-typed in the composer. */
    let lastMsgId = 0;
    // Last lock state applied to the composer, so the poll can tell an actual
    // change from the steady state and only touch the DOM when it matters.
    let lockedNow = false;

    function esc(v) {
      return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c]));
    }

    /* The four states the customer is shown. in_progress and closed are legacy
       values still on older rows; they are mapped rather than printed raw. */
    const STATUS_LABEL = {
      open: 'Open', pending: 'Pending', in_progress: 'Pending',
      resolved: 'Resolved', closed: 'Resolved', reopened: 'Reopened'
    };
    const label = st => STATUS_LABEL[st] || String(st || '').replace(/_/g, ' ');
    const statusClass = st => 'is-' + label(st).toLowerCase();

    function when(value) {
      const d = new Date(value);
      return isNaN(d) ? '' : d.toLocaleString([], {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit'
      });
    }

    function post(action, body) {
      return fetch(`${API}?action=${action}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(Object.assign({ csrf_token: CSRF }, body))
      }).then(r => r.json().catch(() => ({
        success: false, message: `Unexpected response from the server (HTTP ${r.status}).`
      })));
    }

    // ---------------------------------------------------------------- list
    function loadTickets() {
      return fetch(`${API}?action=get_tickets&limit=50`, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(r => r.json().catch(() => ({ success: false })))
        .then(res => {
          if (!res.success) {
            listEl.innerHTML = '<p class="tickets-empty">We could not load your tickets just now. Please refresh the page.</p>';
            return;
          }
          renderTickets(res.tickets || []);
          // Returned so the deep-link handler can match against the same rows
          // rather than fetching the list a second time.
          return res.tickets || [];
        })
        .catch(() => {
          listEl.innerHTML = '<p class="tickets-empty">We could not reach the server. Please check your connection.</p>';
        });
    }

    function renderTickets(tickets) {
      if (!tickets.length) {
        listEl.innerHTML = '<p class="tickets-empty">You have not opened any tickets yet. Send us a message above and it will appear here.</p>';
        return;
      }
      listEl.innerHTML = tickets.map(t => {
        const unread = Number(t.unread_count || 0);
        return `
          <div class="ticket-row${unread ? ' has-unread' : ''}" role="button" tabindex="0"
               data-ticket-id="${esc(t.id)}">
            <div class="ticket-row-main">
              <p class="ticket-row-subject">${esc(t.subject)}</p>
              <p class="ticket-row-meta">${esc(t.ticket_number)} &middot; ${esc(when(t.created_at))}</p>
            </div>
            <div class="ticket-row-side">
              ${unread ? `<span class="ticket-unread" title="${unread} new message${unread === 1 ? '' : 's'}">${unread}</span>` : ''}
              <span class="ticket-status ${statusClass(t.status)}">${esc(label(t.status))}</span>
            </div>
          </div>`;
      }).join('');
    }

    // ------------------------------------------------------- conversation
    function openConversation(ticketId) {
      feedbackEl.textContent = '';
      feedbackEl.className = 'chat-feedback';
      threadEl.innerHTML = '<p class="chat-empty">Loading the conversation&hellip;</p>';
      overlay.hidden = false;
      document.body.style.overflow = 'hidden';

      fetch(`${API}?action=get_ticket&id=${encodeURIComponent(ticketId)}`, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(r => r.json().catch(() => ({ success: false })))
        .then(res => {
          if (!res.success) {
            threadEl.innerHTML = `<p class="chat-empty">${esc(res.message || 'This conversation could not be opened.')}</p>`;
            composer.hidden = true;
            return;
          }
          openTicket = res.ticket;
          renderConversation(res.ticket, res.messages || []);
          // A thread being read updates on the quick cadence.
          schedulePoll();

          /* Opening it is what marks it read. Fire-and-forget - a failed mark
             must not stop the customer reading the reply they came for - and
             the list is refreshed afterwards so the badge clears. */
          post('mark_read', { ticket_id: res.ticket.id })
            .then(() => loadTickets())
            .catch(() => {});
        })
        .catch(() => {
          threadEl.innerHTML = '<p class="chat-empty">We could not reach the server.</p>';
          composer.hidden = true;
        });
    }

    /* One message bubble. Extracted so the first render and the live append
       below cannot drift apart - two copies of this template would. */
    function bubbleHtml(m) {
      const mine = m.sender === 'customer';
      return `
        <div class="chat-row ${mine ? 'is-customer' : 'is-admin'}" data-msg-id="${esc(m.id)}">
          <div class="chat-meta">
            <strong>${mine ? 'You' : 'TechWorld Support'}</strong>
            <span>${esc(when(m.created_at))}</span>
          </div>
          <div class="chat-bubble">${esc(m.message)}</div>
        </div>`;
    }

    /* The composer follows the server's own lock flag rather than this script
       re-deciding what "resolved" means. Both the textarea and the send button
       are disabled, and the box is hidden entirely so there is no dead control
       to click at. The history above stays exactly as it was - nothing is
       removed when a ticket closes.

       Split out of renderConversation so the heartbeat can close the composer
       the instant an admin resolves the ticket, without re-rendering the
       conversation underneath someone who is reading it. */
    function applyLock(locked) {
      lockedNow = locked;
      lockedEl.hidden = !locked;
      composer.hidden = locked;
      inputEl.disabled = locked;
      sendBtn.disabled = locked;
    }

    function renderConversation(ticket, messages) {
      titleEl.textContent = ticket.subject || 'Ticket';
      subtitleEl.textContent = `${ticket.ticket_number} \u00b7 ${label(ticket.status)}`;

      threadEl.innerHTML = messages.length
        ? messages.map(bubbleHtml).join('')
        : '<p class="chat-empty">No messages on this ticket yet.</p>';

      // Where the thread currently ends, so the heartbeat can ask only for
      // what came after it.
      lastMsgId = messages.reduce((hi, m) => Math.max(hi, Number(m.id) || 0), 0);

      const locked = Number(ticket.is_locked) === 1;
      applyLock(locked);
      if (!locked) { inputEl.value = ''; }

      scrollToLatest();
    }

    /* Replies that landed while the thread was open, appended to the bottom.
       Appending rather than re-rendering is the whole point of the heartbeat
       carrying only new messages: a re-render would discard the reader's
       scroll position and anything half-typed in the composer. */
    function appendMessages(list) {
      if (!list || !list.length) { return; }

      // The first real message replaces the "no messages yet" placeholder.
      const placeholder = threadEl.querySelector('.chat-empty');
      if (placeholder) { placeholder.remove(); }

      const follow = isNearBottom();
      list.forEach(m => {
        const id = Number(m.id) || 0;
        /* Belt and braces against a double delivery - a poll overlapping the
           re-read that a send already does - putting one reply on screen
           twice. */
        if (id && threadEl.querySelector(`[data-msg-id="${id}"]`)) { return; }
        threadEl.insertAdjacentHTML('beforeend', bubbleHtml(m));
        if (id > lastMsgId) { lastMsgId = id; }
      });

      /* Only follow the conversation down if they were already at the bottom.
         Yanking someone to the newest message while they are reading back
         through the history is the thing chat UIs most often get wrong. */
      if (follow) { scrollToLatest(); }
    }

    // Within about a bubble's height of the bottom counts as "following along".
    function isNearBottom() {
      return threadEl.scrollHeight - threadEl.scrollTop - threadEl.clientHeight < 80;
    }

    /* Newest message in view. scrollTop rather than scrollIntoView: the thread
       is a scroll container inside a fixed dialog, and scrollIntoView would
       scroll the PAGE behind the overlay too. */
    function scrollToLatest() {
      threadEl.scrollTop = threadEl.scrollHeight;
    }

    function closeConversation() {
      overlay.hidden = true;
      document.body.style.overflow = '';
      openTicket = null;
      lastMsgId = 0;
      // Back to the slow cadence - nothing is being read any more.
      schedulePoll();
    }

    // ------------------------------------------------------------- sending
    function sendMessage() {
      if (!openTicket) { return; }
      const text = inputEl.value.trim();
      if (!text) { inputEl.focus(); return; }

      inputEl.disabled = true;
      sendBtn.disabled = true;
      feedbackEl.className = 'chat-feedback';
      feedbackEl.textContent = 'Sending\u2026';

      post('add_message', { ticket_id: openTicket.id, message: text })
        .then(res => {
          if (!res.success) {
            feedbackEl.className = 'chat-feedback is-error';
            feedbackEl.textContent = res.message || 'We could not send that message.';
            /* Refused because the ticket was resolved while this was open.
               Re-read it so the composer closes and the customer sees the real
               state instead of a box that silently rejects everything. */
            if (res.code === 'thread_locked') {
              openConversation(openTicket.id);
              loadTickets();
              return;
            }
            inputEl.disabled = false;
            sendBtn.disabled = false;
            return;
          }
          feedbackEl.textContent = '';
          inputEl.value = '';
          inputEl.disabled = false;
          sendBtn.disabled = false;
          // Re-read rather than appending locally, so what is on screen is
          // what the database holds - including the server's timestamp.
          const id = openTicket.id;
          fetch(`${API}?action=get_ticket&id=${encodeURIComponent(id)}`, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          })
            .then(r => r.json().catch(() => ({ success: false })))
            .then(r2 => {
              if (r2.success) {
                openTicket = r2.ticket;
                renderConversation(r2.ticket, r2.messages || []);
              }
              loadTickets();
            })
            .catch(() => {});
          inputEl.focus();
        })
        .catch(() => {
          feedbackEl.className = 'chat-feedback is-error';
          feedbackEl.textContent = 'Network error. Please try again.';
          inputEl.disabled = false;
          sendBtn.disabled = false;
        });
    }

    // --------------------------------------------------------------- wiring
    listEl.addEventListener('click', e => {
      const row = e.target.closest('.ticket-row');
      if (row) { openConversation(row.dataset.ticketId); }
    });
    // role="button" promises Enter and Space; a <div> gives neither for free.
    listEl.addEventListener('keydown', e => {
      if (e.key !== 'Enter' && e.key !== ' ') { return; }
      const row = e.target.closest('.ticket-row');
      if (row) { e.preventDefault(); openConversation(row.dataset.ticketId); }
    });

    document.getElementById('chatClose').addEventListener('click', closeConversation);
    overlay.addEventListener('click', e => { if (e.target === overlay) { closeConversation(); } });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !overlay.hidden) { closeConversation(); }
    });

    composer.addEventListener('submit', e => { e.preventDefault(); sendMessage(); });
    // Enter sends, Shift+Enter makes a new line - the convention every chat
    // box uses. Without it the only way to send is the button.
    inputEl.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    if (refreshBtn) { refreshBtn.addEventListener('click', loadTickets); }

    /* A ticket filed through the form above belongs in the list immediately,
       so the customer sees where the reply will arrive. This used to listen
       for the form's submit and reload 1.2s later; submit now only opens the
       confirmation dialog, so that timer fired while the ticket still did not
       exist. The send handler calls this instead, once the endpoint has
       answered with a ticket number. */
    window.twSupportReloadTickets = loadTickets;

    /* Open a ticket's conversation from outside this block.
       Used by the send handler so that confirming "Yes, send it" drops the
       customer straight into the thread it just created, instead of telling
       them to go and find it in the list below. Exposed as a hook rather than
       merging the two scripts: the form block stays responsible for sending
       and this one stays the only thing that knows how the dialog works. */
    window.twSupportOpenTicket = openConversation;

    /* ------------------------------------------------------------- realtime
       Replies appear on their own, without a refresh. Both support screens
       call the same heartbeat; see supportPoll() in api/support.php for why
       this polls rather than using WebSockets or SSE on this stack.

       Two cadences, because the two situations are not the same: a
       conversation open on screen has to feel immediate, while a page sitting
       on the ticket list only needs its unread badges to keep up. */
    const POLL_OPEN_MS = 3000;
    const POLL_IDLE_MS = 10000;
    let lastDigest   = null;
    let pollInFlight = false;
    let pollTimer    = null;

    function pollOnce() {
      /* Never two at once - a slow response would otherwise stack up requests
         behind it - and never while the tab is hidden, which spends the
         request budget on a page nobody is looking at. */
      if (pollInFlight || document.hidden) { return Promise.resolve(); }
      pollInFlight = true;

      let url = `${API}?action=poll`;
      if (openTicket) {
        url += `&ticket_id=${encodeURIComponent(openTicket.id)}&after_id=${lastMsgId}`;
      }

      return fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(r => r.json().catch(() => null))
        .then(res => {
          if (!res || !res.success) { return; }

          // The open conversation first - this is the part that has to feel live.
          const th = res.thread;
          if (th && openTicket && Number(th.ticket_id) === Number(openTicket.id)) {
            const arrived = th.messages || [];
            appendMessages(arrived);

            /* They are looking straight at it, so an arriving reply is already
               read. Without this the ticket's own badge would light for a
               message sitting open on screen. */
            if (arrived.length) {
              post('mark_read', { ticket_id: openTicket.id }).catch(() => {});
            }

            if (openTicket.status !== th.status) {
              openTicket.status    = th.status;
              openTicket.is_locked = th.is_locked;
              subtitleEl.textContent = `${openTicket.ticket_number} · ${label(th.status)}`;
            }

            /* Resolved or reopened by an admin while this sat open: open or
               close the composer in place. The history is left alone. */
            const locked = Number(th.is_locked) === 1;
            if (locked !== lockedNow) {
              applyLock(locked);
              feedbackEl.className = 'chat-feedback';
              feedbackEl.textContent = locked
                ? 'Support marked this ticket resolved. The conversation is now closed.'
                : 'This ticket has been reopened - you can reply again.';
            }
          }

          /* One short string answers "has anything I can see changed?", so the
             heavier list query only runs when something actually moved rather
             than on every tick. */
          if (res.digest !== lastDigest) {
            const firstTick = lastDigest === null;
            lastDigest = res.digest;
            /* On the very first tick the loadTickets() below is already in
               flight for the same data, so re-running it would only duplicate
               that request. */
            if (!firstTick) { loadTickets(); }
          }
        })
        .catch(() => {})   // a dropped heartbeat is not worth reporting; the next one retries
        .finally(() => { pollInFlight = false; });
    }

    function schedulePoll() {
      clearTimeout(pollTimer);
      pollTimer = setTimeout(
        () => { pollOnce().finally(schedulePoll); },
        openTicket ? POLL_OPEN_MS : POLL_IDLE_MS
      );
    }

    // Catch up the moment the tab is looked at again, rather than waiting out
    // whatever was left on the timer.
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) { pollOnce().finally(schedulePoll); }
    });

    /* Arriving from the notification bell: ?ticket=TW-20261009-00015 (or a
       numeric id) opens that conversation as soon as the list it lives in has
       loaded. Matched against the tickets this customer already fetched, so
       the deep link can only ever open a thread that is theirs - no extra
       endpoint and no id to guess at. */
    function openTicketFromQuery(tickets) {
      const ref = new URLSearchParams(window.location.search).get('ticket');
      if (!ref) { return; }
      const want = String(ref).trim().toLowerCase();
      const hit = (tickets || []).find(t =>
        String(t.id) === want || String(t.ticket_number || '').toLowerCase() === want);
      if (hit) {
        openConversation(hit.id);
      } else {
        // Their ticket list is capped at 50; say so rather than doing nothing.
        listEl.insertAdjacentHTML('beforebegin',
          '<p class="tickets-empty">That conversation is not in your recent tickets.</p>');
      }
      // The link has done its job; drop it so a refresh does not reopen the
      // dialog the customer just closed.
      history.replaceState({}, '', window.location.pathname);
    }

    loadTickets().then(openTicketFromQuery);
    pollOnce().finally(schedulePoll);
  })();
  </script>
  <?php endif; ?>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
