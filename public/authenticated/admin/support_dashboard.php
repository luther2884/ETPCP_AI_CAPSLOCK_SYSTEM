<?php
// Admin Support Dashboard
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Check if user is admin (you can implement proper authentication here)
$pageTitle = 'Support Dashboard - TechWorld Admin';
$activeNav = 'support';
?>
<?php
// This page used to hand-roll its own <!DOCTYPE html><html><head> and never
// linked admin.css, so it always rendered with raw browser defaults plus its
// own hardcoded dark-theme colors - none of the admin light palette ever
// applied here. Switched to the same header/navigation include pattern every
// other admin page uses, which loads style.css + admin.css and sets
// data-theme="light".
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

  <main class="container">
      <div class="adm-head">
        <div>
          <nav class="adm-crumb" aria-label="Breadcrumb">
            <a href="javascript:void(0)" onclick="if(history.length>1){history.back();}else{window.location.href='CusMan.php';}" title="Back">Customers</a>
            <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
            <span class="adm-crumb-cur" aria-current="page">Support</span>
          </nav>
        </div>
      </div>
    <div class="page-header">
      <h1><i class="fi fi-rr-headset" style="margin-right: 10px; color: #174871;"></i>Support Dashboard</h1>
      <p>Manage support tickets, warranty claims, and return requests.</p>
    </div>

    <!-- Dashboard Stats -->
    <div class="dashboard-stats">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fi fi-rr-ticket"></i>
        </div>
        <div class="stat-content">
          <h3 id="totalTickets">0</h3>
          <p>Total Tickets</p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fi fi-rr-clock"></i>
        </div>
        <div class="stat-content">
          <h3 id="openTickets">0</h3>
          <p>Open Tickets</p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fi fi-rr-shield-check"></i>
        </div>
        <div class="stat-content">
          <h3 id="warrantyClaims">0</h3>
          <p>Warranty Claims</p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fi fi-rr-undo"></i>
        </div>
        <div class="stat-content">
          <h3 id="returnRequests">0</h3>
          <p>Return Requests</p>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="admin-tabs">
      <button class="tab-btn active" data-tab="tickets">
        <i class="fi fi-rr-ticket"></i>
        Support Tickets
      </button>
      <button class="tab-btn" data-tab="warranty">
        <i class="fi fi-rr-shield-check"></i>
        Warranty Claims
      </button>
      <button class="tab-btn" data-tab="returns">
        <i class="fi fi-rr-undo"></i>
        Return Requests
      </button>
      <button class="tab-btn" data-tab="faq">
        <i class="fi fi-rr-interrogation"></i>
        FAQ Management
      </button>
    </div>

    <!-- Support Tickets Tab -->
    <div class="tab-content active" id="tickets-tab">
      <div class="content-header">
        <h2>Support Tickets</h2>
        <div class="filters">
          <!-- The four states this feature defines. 'in_progress' and 'closed'
               are no longer offered: they are legacy stored values that the UI
               displays as Pending and Resolved, and the endpoint folds them
               into those two filters, so picking "Pending" still finds an older
               in_progress ticket. Offering both sets would have given two
               options that look different and return overlapping rows. -->
          <select id="ticketStatusFilter">
            <option value="">All Status</option>
            <option value="open">Open</option>
            <option value="pending">Pending</option>
            <option value="resolved">Resolved</option>
            <option value="reopened">Reopened</option>
            <?php /* Not a status - archived is its own axis (see the note in
                     getSupportTickets). It sits in this select because to the
                     person using the page it is "which pile am I looking at",
                     which is the same question the rest of this list answers. */ ?>
            <option value="archived">Archived</option>
          </select>
          <select id="ticketPriorityFilter">
            <option value="">All Priority</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>
      </div>
      <div class="tickets-list" id="ticketsList">
        <!-- Tickets will be loaded here -->
      </div>
    </div>

    <!-- Warranty Claims Tab -->
    <div class="tab-content" id="warranty-tab">
      <div class="content-header">
        <h2>Warranty Claims</h2>
        <div class="filters">
          <select id="warrantyStatusFilter">
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
          </select>
        </div>
      </div>
      <div class="warranty-list" id="warrantyList">
        <!-- Warranty claims will be loaded here -->
      </div>
    </div>

    <!-- Return Requests Tab -->
    <div class="tab-content" id="returns-tab">
      <div class="content-header">
        <h2>Return Requests</h2>
        <div class="filters">
          <select id="returnStatusFilter">
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
          </select>
        </div>
      </div>
      <div class="returns-list" id="returnsList">
        <!-- Return requests will be loaded here -->
      </div>
    </div>

    <!-- FAQ Management Tab -->
    <div class="tab-content" id="faq-tab">
      <div class="content-header">
        <h2>FAQ Management</h2>
        <button class="btn-primary" onclick="showAddFAQModal()">
          <i class="fi fi-rr-plus"></i>
          Add FAQ
        </button>
      </div>
      <div class="faq-list" id="adminFaqList">
        <!-- FAQs will be loaded here -->
      </div>
    </div>
  </main>

  <!-- Ticket Detail Modal -->
  <div class="modal" id="ticketModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="modalTitle">Ticket Details</h3>
        <button class="modal-close" onclick="closeModal('ticketModal')">
          <i class="fi fi-rr-cross"></i>
        </button>
      </div>
      <div class="modal-body" id="modalBody">
        <!-- Ticket details will be loaded here -->
      </div>
      <input type="hidden" id="ticketCsrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    </div>
  </div>

  <!-- Add FAQ Modal -->
  <div class="modal" id="faqModal">
    <div class="modal-content" style="max-width:640px;">
      <div class="modal-header">
        <h3>Add FAQ</h3>
        <button class="modal-close" onclick="closeModal('faqModal')" aria-label="Close">
          <i class="fi fi-rr-cross"></i>
        </button>
      </div>
      <div class="modal-body">
        <form id="faqForm" novalidate>
          <?php /* The token is read out of this field by the submit handler and
                   sent in the JSON body - the API compares it the same way
                   check_csrf() does. */ ?>
          <input type="hidden" id="faqCsrf" value="<?= htmlspecialchars(csrf_token()) ?>">

          <div class="faq-field">
            <label for="faqCategory">Category</label>
            <select id="faqCategory" required>
              <option value="">Loading categories…</option>
            </select>
          </div>

          <div class="faq-field">
            <label for="faqQuestion">Question</label>
            <input type="text" id="faqQuestion" maxlength="255" required
                   placeholder="How long does delivery take?">
          </div>

          <div class="faq-field">
            <label for="faqAnswer">Answer</label>
            <textarea id="faqAnswer" rows="6" required
                      placeholder="Orders within Metro Manila arrive in 2-3 working days…"></textarea>
          </div>

          <div class="faq-field-row">
            <div class="faq-field">
              <label for="faqSort">Sort order</label>
              <input type="number" id="faqSort" value="0" min="0" step="1">
              <small>Lower numbers appear first.</small>
            </div>
            <label class="faq-check">
              <input type="checkbox" id="faqActive" checked>
              <span>Publish immediately</span>
            </label>
          </div>

          <div class="faq-error" id="faqError" role="alert" hidden></div>

          <div class="faq-actions">
            <button type="button" class="btn-secondary" onclick="closeModal('faqModal')">Cancel</button>
            <button type="submit" class="btn-primary" id="faqSubmit">Save FAQ</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Admin Dashboard CSS -->
  <style>
  /* Page-scoped sticky footer */
  html, body { height: 100%; }
  body { min-height: 100vh; display: flex; flex-direction: column; }
  body > .container { flex: 1; }
  .page-header {
    text-align: center;
    margin: 60px 0 40px;
    padding: 40px 20px;
    background: linear-gradient(135deg, rgba(23, 72, 113, 0.1), rgba(167, 118, 147, 0.1));
    border-radius: 20px;
    border: 1px solid var(--admin-border);
  }

  .page-header h1 {
    font-size: 2.5rem;
    margin-bottom: 15px;
    background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  .page-header p {
    font-size: 1.1rem;
    color: var(--admin-muted);
    max-width: 600px;
    margin: 0 auto;
  }

  .dashboard-stats {
    display: grid;
    /* Two fixed tracks, not auto-fit: with four cards auto-fit put all of them
       on one row as soon as the container could fit 4 x 200px. A fixed count is
       what guarantees the 2 x 2 block. */
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 40px;
  }

  .stat-card {
    background: var(--admin-surface-2);
    box-shadow: var(--neu-raised);
    border: none;
    border-radius: 15px;
    padding: 25px;
    display: flex;
    align-items: center;
    /* Pins the icon to the left edge: without it a later change to the row (an
       added badge, say) could re-distribute the items and float it inward. */
    justify-content: flex-start;
    gap: 20px;
    /* One height for all four. Grid already stretches cards to the tallest in
       each row; this makes the two ROWS match each other too. */
    min-height: 110px;
    transition: transform 0.3s ease;
  }

  .stat-card:hover {
    transform: translateY(-5px);
  }

  .stat-icon {
    /* flex:0 0 60px, not width alone - as a flex item the circle was allowed to
       shrink, so a card with a longer label rendered a slightly narrower,
       egg-shaped icon than its neighbours. */
    flex: 0 0 60px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .stat-icon i {
    font-size: 24px;
    color: white;
  }

  /* min-width:0 lets a long label ellipsise inside the card instead of forcing
     the flex row wider and pushing the icons out of alignment card to card. */
  .stat-content { flex: 1 1 auto; min-width: 0; text-align: left; }
  .stat-content h3 {
    font-size: 2rem;
    line-height: 1.15;
    color: var(--admin-text);
    margin: 0 0 5px 0;
    font-variant-numeric: tabular-nums;
  }

  .stat-content p {
    color: var(--admin-muted);
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .admin-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 30px;
    border-bottom: 1px solid var(--admin-border);
  }

  .tab-btn {
    padding: 15px 25px;
    border: none;
    background: transparent;
    color: var(--admin-muted);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1rem;
  }

  .tab-btn:hover,
  .tab-btn.active {
    color: var(--accent-cyan);
    border-bottom-color: var(--accent-cyan);
  }

  .tab-content {
    display: none;
  }

  .tab-content.active {
    display: block;
  }

  .content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--admin-border);
  }

  .content-header h2 {
    color: var(--admin-text);
    margin: 0;
  }

  .filters {
    display: flex;
    gap: 15px;
  }

  .filters select {
    padding: 10px 15px;
    border: none;
    border-radius: 8px;
    background: var(--admin-surface);
    box-shadow: var(--neu-pressed);
    color: var(--admin-text);
    font-size: 0.9rem;
  }

  .btn-primary {
    background: linear-gradient(135deg, var(--accent-cyan), var(--accent-blue));
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    transition: transform 0.3s ease;
  }

  .btn-primary:hover {
    transform: translateY(-2px);
  }

  .ticket-item,
  .warranty-item,
  .return-item,
  .faq-item {
    background: var(--admin-surface-2);
    box-shadow: var(--neu-raised-sm);
    border: none;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 15px;
    cursor: pointer;
    transition: all 0.3s ease, box-shadow 0.3s ease;
  }

  .ticket-item:hover,
  .warranty-item:hover,
  .return-item:hover,
  .faq-item:hover {
    box-shadow: var(--neu-raised);
    transform: translateY(-2px);
  }

  .item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
  }

  .item-title {
    color: var(--admin-text);
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
  }

  .item-meta {
    display: flex;
    gap: 15px;
    color: var(--admin-muted);
    font-size: 0.9rem;
  }

  .status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
  }

  .status-open { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
  .status-in_progress { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
  .status-resolved { background: rgba(16, 185, 129, 0.2); color: #10b981; }
  .status-closed { background: rgba(107, 114, 128, 0.2); color: #6b7280; }
  /* The two states this feature adds. The four above are shared with the
     warranty and returns tabs, so they are untouched. */
  .status-pending  { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
  .status-reopened { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
  .status-pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
  .status-approved { background: rgba(16, 185, 129, 0.2); color: #10b981; }
  .status-rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
  .status-completed { background: rgba(16, 185, 129, 0.2); color: #10b981; }

  .priority-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
  }

  .priority-low { background: rgba(16, 185, 129, 0.2); color: #10b981; }
  .priority-medium { background: rgba(23, 72, 113, 0.2); color: #174871; }
  .priority-high { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
  .priority-urgent { background: rgba(239, 68, 68, 0.2); color: #ef4444; }

  .item-description {
    color: var(--admin-muted);
    line-height: 1.6;
    margin: 0;
  }

  .modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(5px);
  }

  /* A column, not one long scrolling box.

     This was `max-height:80vh; overflow-y:auto` on the shell itself, with the
     conversation ALSO scrolling inside it at max-height:360px - two nested
     scrollbars, and the composer plus the Send/Resolve buttons pushed off the
     bottom of the outer one. Scrolling the thread did not bring them back,
     so on a short window the actions were simply unreachable.

     Now the shell is a fixed-height flex column: the header stays put, the
     body is the single outer scroller, and the action bar is sticky to the
     bottom of it (see .ticket-actions) so Send is always on screen. */
  /* Addressed as `#ticketModal > .modal-content`, and it has to be.

     public/shared/style.css:1359 declares `.modal > .modal-content` with
     `max-width:450px; height:fit-content`. That selector is (0,2,0); a bare
     `.modal-content` here is (0,1,0) and therefore LOSES on specificity no
     matter that this block is parsed later. That is why the panel stayed
     450px wide while the 94vh height - which happened to be written with
     three classes - did apply: tall and narrow, exactly as screenshotted.
     `max-width:none` is what actually releases the 450px clamp.

     Scoped by id rather than widened for every .modal, so the small
     #faqModal keeps style.css's 450px dialog proportions. style.css's
     `position:absolute; inset:0; margin:auto` centring is left alone - it
     centres on both axes, which is what we want. */
  #ticketModal > .modal-content {
    background: var(--admin-surface);
    padding: 0;
    border-radius: 20px;
    width: min(1100px, 96vw);
    max-width: none;
    max-height: 94vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;          /* the thread scrolls; the shell must not */
    border: 1px solid var(--admin-border);
  }

  .modal-header {
    flex: none;
    padding: 20px 26px;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
  }

  .modal-header h3 {
    color: var(--admin-text);
    margin: 0;
  }

  .modal-close {
    background: none;
    border: none;
    color: var(--admin-muted);
    font-size: 20px;
    cursor: pointer;
    padding: 5px;
  }

  /* The one scroll region. Padding is referenced by .ticket-actions' negative
     margins below, so the sticky bar can bleed to the panel edges - keep the
     two in step if either changes. */
  .modal-body {
    flex: 1 1 auto;
    min-height: 0;             /* or a flex child refuses to shrink and scroll */
    overflow-y: auto;
    padding: 22px 26px;
  }

  /* ---- ticket view: the conversation is the ONLY scroller -----------------
     Scoped to .is-ticket, which showTicketDetails puts on the modal and the
     warranty/return views take off again. #ticketModal is shared with those,
     and their content is plain prose of unpredictable length - making the
     body un-scrollable for everyone would simply clip them with no way to
     reach the rest.

     The chain has to be unbroken for a middle child to scroll: every
     ancestor between the fixed-height shell and .chat-thread needs
     `min-height:0`, or a flex item refuses to shrink below its content and
     the overflow pushes the composer out of the panel instead of scrolling.
     That is one `min-height:0` per level - shell, body, details. */
  /* Beats style.css's `height: fit-content`, which would otherwise let the
     panel shrink to its content and leave the thread nothing to fill. */
  #ticketModal.is-ticket > .modal-content { height: 94vh; }
  .modal.is-ticket .modal-body {
    overflow: hidden;              /* nothing scrolls here */
    display: flex; flex-direction: column; min-height: 0;
    /* The action bar provides the bottom edge, so the body must not add one
       underneath it. */
    padding-bottom: 0;
  }
  .modal.is-ticket .ticket-details {
    flex: 1 1 auto; min-height: 0;
    display: flex; flex-direction: column;
  }
  /* Everything that is not the thread keeps its natural height... */
  .modal.is-ticket .ticket-details > h4,
  .modal.is-ticket .ticket-facts,
  .modal.is-ticket .reply-box,
  .modal.is-ticket .chat-locked,
  .modal.is-ticket .ticket-actions { flex: 0 0 auto; }
  /* ...and the thread takes whatever is left. Higher specificity than both
     the base rule and the narrow-window one below, so its max-height caps
     cannot clamp it back down. */
  .modal.is-ticket .chat-thread {
    /* basis 160px so it has a sensible preferred size, grow to take the
       leftover space, and shrink when there is none. min-height:0 keeps it
       the part that gives way on a very short window - the thread getting
       small is a far better failure than the composer and the Send button
       being clipped out of a panel that cannot scroll. */
    flex: 1 1 160px;
    min-height: 0;
    max-height: none;
  }

  /* ---- ticket header facts ------------------------------------------------
     Was eight stacked <p> lines - about a third of the panel spent on
     "Subject:", "Customer:", "Category:" before the conversation even
     started, which is most of why there was no room left for the thread.
     A label/value grid says the same thing in half the height and scans
     down the labels instead of being read as prose. */
  .ticket-facts {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 7px 16px;
    align-items: baseline;
    margin: 0 0 18px;
  }
  .ticket-facts dt {
    color: var(--admin-muted);
    font-size: .8rem; font-weight: 600;
    white-space: nowrap;
  }
  .ticket-facts dd {
    margin: 0; color: var(--admin-text);
    font-size: .9rem; overflow-wrap: anywhere;
  }
  /* On a wide panel the facts run as TWO label/value pairs per row. Six
     stacked rows left most of the enlarged width empty while squeezing the
     conversation underneath; this halves the header's height and spends the
     space on the thread, which is the part worth looking at. */
  @media (min-width: 900px) {
    .ticket-facts {
      grid-template-columns: auto minmax(0, 1fr) auto minmax(0, 1fr);
      column-gap: 30px;
    }
  }
  /* Add-FAQ form */
  .faq-field { margin-bottom: 18px; }
  .faq-field label {
    display: block; margin-bottom: 6px;
    font-size: 13px; font-weight: 600; color: var(--admin-text);
  }
  .faq-field input[type="text"],
  .faq-field input[type="number"],
  .faq-field select,
  .faq-field textarea {
    width: 100%; padding: 10px 12px; font: inherit; font-size: 14px;
    color: var(--admin-text); background: var(--admin-surface-2);
    border: 1px solid var(--admin-border); border-radius: 8px;
  }
  .faq-field textarea { resize: vertical; min-height: 110px; }
  .faq-field small { display: block; margin-top: 4px; font-size: 11px; color: var(--admin-muted); }
  .faq-field-row { display: flex; gap: 20px; align-items: flex-start; }
  .faq-field-row .faq-field { flex: 0 0 140px; }
  .faq-check { display: flex; align-items: center; gap: 8px; margin-top: 28px; font-size: 13px; cursor: pointer; }
  .faq-error {
    margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; font-size: 13px;
    background: rgba(239, 68, 68, .12); color: #ef4444; border: 1px solid rgba(239, 68, 68, .3);
  }
  .faq-actions { display: flex; justify-content: flex-end; gap: 10px; }
  .btn-secondary {
    padding: 10px 18px; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
    color: var(--admin-text); background: var(--admin-surface-2);
    border: 1px solid var(--admin-border); border-radius: 8px;
  }
  .btn-secondary:hover { border-color: var(--admin-muted); }

  @media (max-width: 768px) {
    .dashboard-stats {
      grid-template-columns: 1fr;
    }
    
    .admin-tabs {
      flex-wrap: wrap;
    }
    
    .content-header {
      flex-direction: column;
      gap: 20px;
      align-items: flex-start;
    }
    
    .filters {
      flex-direction: column;
      width: 100%;
    }

    .ticket-actions { flex-direction: column; align-items: stretch; }
    .ticket-actions .btn-primary,
    .ticket-actions .btn-muted { justify-content: center; }
  }

  /* ---- ticket thread + reply composer -------------------------------------
     The modal used to be a read-only dump: ticket fields, then every message
     in one undifferentiated stack. Support cannot work a queue from that -
     there was no way to answer the customer and no way to say a ticket was
     done. Below: who said what, a box to reply in, and the two state changes
     that matter (resolve, reopen). */
  .ticket-thread {
    display: flex; flex-direction: column; gap: 10px;
    max-height: 320px; overflow-y: auto;
    padding: 4px 2px; margin: 0 0 18px;
  }
  .thread-msg {
    background: var(--admin-surface-2);
    border-radius: 10px; padding: 10px 12px;
    border-left: 3px solid var(--admin-border);
  }
  /* Staff replies sit to the right and carry the accent, so a long thread
     reads as a conversation rather than a list. */
  .thread-msg.is-staff { border-left-color: var(--accent-cyan); margin-left: 28px; }
  .thread-msg.is-internal {
    border-left-color: #f59e0b;
    background: rgba(245, 158, 11, 0.08);
  }
  .thread-who {
    display: flex; justify-content: space-between; align-items: baseline;
    gap: 10px; font-size: .82rem; color: var(--admin-muted); margin-bottom: 4px;
  }
  .thread-who strong { color: var(--admin-text); font-size: .9rem; }
  /* pre-wrap, because a customer describing a fault writes in paragraphs and
     collapsing them turns the report into one run-on line. */
  .thread-body { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.55; }
  .thread-empty { color: var(--admin-muted); font-style: italic; margin: 0 0 18px; }

  .reply-box { margin: 0 0 16px; }
  .reply-box textarea {
    width: 100%; min-height: 96px; resize: vertical;
    padding: 10px 12px; border-radius: 10px;
    border: 1px solid var(--admin-border);
    background: var(--admin-surface); color: var(--admin-text);
    font: inherit; line-height: 1.5;
  }
  /* The internal-note toggle.

     This was `display:flex` with no width, i.e. a block-level box spanning the
     full width of the modal. A <label> is clickable across its whole box, so
     that left an invisible full-width strip directly under the textarea - the
     short text sat on the left and the rest of the row, empty and unmarked,
     toggled "the customer will never see this" on any stray click. It is also
     the next tab stop after the textarea, so Tab-then-Space while composing
     flips it too.

     inline-flex + fit-content shrinks the target to the words themselves, and
     the armed state below makes an armed toggle impossible to miss. */
  .reply-opts {
    display: inline-flex; align-items: center; gap: 8px;
    width: fit-content; max-width: 100%;
    margin-top: 8px; padding: 6px 10px;
    border: 1px solid transparent; border-radius: 8px;
    font-size: .85rem; color: var(--admin-muted); cursor: pointer;
  }
  .reply-opts:hover { background: var(--admin-surface-2); }
  .reply-opts input { cursor: pointer; margin: 0; }
  /* Armed = this message will be invisible to the customer. Amber, the same
     colour the INTERNAL NOTE tag uses in the thread above, so the warning and
     the result it produces read as the same thing. */
  .reply-opts.is-armed {
    color: #f59e0b;
    border-color: rgba(245, 158, 11, .55);
    background: rgba(245, 158, 11, .12);
  }
  /* The send button changes colour and wording with the mode, so the control
     that actually sends says which of the two things it is about to do. */
  .btn-primary.is-internal-mode {
    background: #f59e0b; color: #241500;
  }
  /* Pinned to the foot of the scrolling body, so Send reply and Mark as
     resolved are on screen no matter how long the thread is. The negative
     margins bleed it to the panel edges and cancel .modal-body's padding -
     those two values have to stay in step. */
  /* Sits flush at the foot of the panel. The negative side margins cancel
     .modal-body's horizontal padding so the bar spans the full width - keep
     those two in step. There is deliberately NO negative bottom margin: on a
     flex column a negative margin-bottom on the last item does not push it
     into the parent's padding, it just releases space below it, which is
     what left a white strip under the bar. The body drops its bottom padding
     in ticket mode instead (see .modal.is-ticket .modal-body). */
  .ticket-actions {
    position: sticky; bottom: 0; z-index: 2;
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    margin: 18px -26px 0; padding: 14px 26px;
    background: var(--panel-bg);
    border-top: 1px solid var(--admin-border);
  }
  .btn-muted {
    background: var(--admin-surface-2); color: var(--admin-text);
    border: 1px solid var(--admin-border);
    padding: 12px 20px; border-radius: 8px; cursor: pointer;
    display: flex; align-items: center; gap: 8px; font-size: .9rem;
  }
  .btn-muted:hover { background: var(--admin-surface); }
  .ticket-actions button[disabled] { opacity: .55; cursor: default; }
  .ticket-feedback { font-size: .85rem; margin-left: auto; }
  .ticket-feedback.is-error { color: #ef4444; }
  .ticket-feedback.is-ok { color: #10b981; }

  /* ---- bubble conversation ------------------------------------------------
     Replaces the flat stack the thread used to be. The customer sits left, the
     admin right, which is the arrangement every messaging app has trained
     people to read - you can tell who is talking without reading the names.
     The names and timestamps are still printed, because a support thread is a
     record someone may have to quote later, and "the message above yours" is
     not a citation. */
  /* Chat surfaces.

     The admin palette deliberately gives every surface ONE colour -
     --admin-bg, --admin-surface and --admin-surface-2 are all #eef0f1 in
     light and #123554 in dark, because shape is meant to come from the
     neumorphic shadow pair rather than from contrast. That is right for
     cards and wrong for a conversation: the thread's background and the
     customer's bubble resolved to the same colour, so the bubble dissolved
     into the panel behind it and the thread read as a wall of text.

     These four are the only new colours, and they are taken from the palette
     that already exists rather than invented: the outgoing bubble is
     --accent-blue (#174871), the same navy as the Send button, so "ours"
     looks like the control that produced it. Dark values are defined too, so
     this still holds if the admin area ever gets the theme toggle the
     customer side has. */
  :root, [data-theme="dark"] {
    /* What the modal panel ACTUALLY is, which is not ours to pick:
       style.css:3410 forces `[data-theme="light"] .modal-content
       { background:#ffffff !important }`, and !important beats even an id
       selector. Anything that has to sit flush against the panel - the
       action bar below - must match that white, or it reads as a grey band
       glued to a white card. Dark keeps the admin surface. */
    --panel-bg:     var(--admin-surface);
    --chat-ground:  #0d2a44;
    --chat-in:      #1a4467;
    --chat-in-edge: rgba(242, 243, 244, .14);
    --chat-out:     #2f6f9e;
    --chat-out-ink: #eaf4fb;
  }
  /* A white bubble on this ground is only ~1.3:1, so the bubble's EDGE is
     what defines it rather than its fill. Pushing the ground darker instead
     would buy separation at the cost of the names and timestamps printed on
     it - --admin-muted drops below 4.5:1 by about #d8dee4 - and legible text
     matters more than a crisper rectangle. */
  [data-theme="light"] {
    --panel-bg:     #ffffff;
    --chat-ground:  #dee3e8;
    --chat-in:      #ffffff;
    /* .30, not the .08 --admin-border carries: at that alpha the outline was
       the same tone as the ground (1.05:1) and drew no edge at all. This
       reads against the white fill (1.81:1) and the ground (1.40:1) both. */
    --chat-in-edge: rgba(23, 50, 74, .30);
    --chat-out:     #174871;
    --chat-out-ink: #f2f3f4;
  }

  .chat-thread {
    display: flex; flex-direction: column; gap: 12px;
    /* Was a flat 360px, which on this modal showed about two messages and
       hid the rest. Proportional to the window, with a floor so it is still
       a conversation on a short screen and a ceiling so the composer below
       never gets pushed out of reach. */
    min-height: 180px;
    max-height: min(48vh, 480px);
    overflow-y: auto;
    padding: 14px; margin: 0 0 18px;
    background: var(--chat-ground);
    border: 1px solid var(--admin-border); border-radius: 12px;
    scroll-behavior: smooth;
  }
  .chat-row { display: flex; flex-direction: column; max-width: 76%; }
  .chat-row.is-customer { align-self: flex-start; align-items: flex-start; }
  .chat-row.is-admin    { align-self: flex-end;   align-items: flex-end; }

  .chat-meta {
    display: flex; gap: 8px; align-items: baseline;
    font-size: .74rem; color: var(--admin-muted); margin: 0 4px 4px;
  }
  .chat-meta strong { color: var(--admin-text); font-size: .8rem; font-weight: 700; }

  .chat-bubble {
    padding: 9px 13px; border-radius: 14px;
    line-height: 1.5; font-size: .92rem;
    /* pre-wrap: a customer describing a fault writes in paragraphs, and
       collapsing them turns the report into one run-on line. */
    white-space: pre-wrap; overflow-wrap: anywhere;
  }
  .chat-row.is-customer .chat-bubble {
    background: var(--chat-in); color: var(--admin-text);
    border: 1px solid var(--chat-in-edge); border-bottom-left-radius: 4px;
  }
  /* Navy with light ink, not the mauve --accent-cyan on near-black it used to
     be: that pairing sat at roughly 3:1 and the timestamps above it were
     unreadable against the thread. */
  .chat-row.is-admin .chat-bubble {
    background: var(--chat-out); color: var(--chat-out-ink);
    border: 1px solid transparent; border-bottom-right-radius: 4px;
  }
  /* An internal note is staff-only and must never be mistaken for something
     the customer can see, so it refuses the admin bubble's colour entirely. */
  .chat-row.is-internal .chat-bubble {
    background: rgba(245, 158, 11, 0.14); color: var(--admin-text);
    border: 1px dashed #f59e0b; border-bottom-right-radius: 14px;
  }
  .chat-note-tag {
    font-size: .68rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; color: #f59e0b;
  }
  .chat-empty { color: var(--admin-muted); font-style: italic; margin: auto; }

  /* Why the composer is shut, said in words rather than by a greyed-out box
     with no explanation. */
  .chat-locked {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 14px; margin: 0 0 16px;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.35); border-radius: 10px;
    color: var(--admin-text); font-size: .88rem;
  }
  .chat-locked i { color: #10b981; }

  .reply-box textarea[disabled] { opacity: .5; cursor: not-allowed; }

  /* ---- narrow windows -----------------------------------------------------
     The screenshot that prompted this was ~780px wide: the panel kept its
     desktop padding, the two action buttons would not fit on one row, and
     "Mark as resolved" ran past the panel edge. Below 720px the modal takes
     the whole window and the buttons stack full-width, which is also a much
     bigger tap target. */
  @media (max-width: 720px) {
    /* Same id-scoped selectors as above - a bare .modal-content would lose
       to style.css here too. */
    #ticketModal > .modal-content {
      width: 100vw; max-width: none; max-height: 100vh;
      border-radius: 0; border: none;
    }
    #ticketModal.is-ticket > .modal-content { height: 100vh; }
    .modal-header { padding: 16px; }
    .modal-header h3 { font-size: 1.02rem; }
    .modal-body { padding: 16px; }

    .ticket-facts { grid-template-columns: 1fr; gap: 2px; }
    .ticket-facts dt { font-size: .74rem; }
    .ticket-facts dd { margin-bottom: 8px; }

    .chat-row { max-width: 88%; }
    .chat-thread { padding: 10px; }

    /* On a window this short there IS no leftover space to give the thread -
       the facts alone fill the panel, and `flex:1 1 160px; min-height:0`
       duly collapsed the conversation to a 25px sliver, which is the one
       thing in here nobody can do without. So below this width the body
       goes back to scrolling and the thread takes a fixed, usable height.
       That re-introduces the second scrollbar, deliberately: an unreachable
       facts block beats an invisible conversation. The action bar is still
       sticky, so Send stays pinned either way. */
    .modal.is-ticket .modal-body { overflow-y: auto; }
    .modal.is-ticket .chat-thread {
      flex: 0 0 auto;
      min-height: 240px;
      max-height: 46vh;
    }

    /* Matches .modal-body's 16px padding above. */
    .ticket-actions { margin: 16px -16px 0; padding: 12px 16px; }
  }

  /* Keep the two buttons on one row between 521px and 768px.

     The page already has its own `@media (max-width: 768px)` block further
     up which stacks every .ticket-actions - it predates this modal and still
     governs the rest of the dashboard, so it is left alone. But inside a
     fixed-height panel two full-width bars spend ~55px of height that the
     conversation needs, and at 700px the buttons fit side by side with room
     to spare. Scoped to .modal.is-ticket (0,3,0) so it outranks that rule
     without disturbing it anywhere else; below 521px it stops applying and
     the page's stacking takes over again, which is right on a phone. */
  @media (min-width: 521px) and (max-width: 768px) {
    .modal.is-ticket .ticket-actions { flex-direction: row; align-items: center; }
    .modal.is-ticket .ticket-actions .btn-primary,
    .modal.is-ticket .ticket-actions .btn-muted { width: auto; }
  }

  /* Unread count on a row in the inbox list. */
  .unread-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 20px; padding: 0 6px;
    border-radius: 999px; background: #ef4444; color: #fff;
    font-size: .72rem; font-weight: 800; line-height: 1;
  }
  .ticket-item.has-unread { border-left: 3px solid #ef4444; }

  /* Archive / restore on a resolved row.
     The row itself is clickable (it opens the ticket), so this button has to
     read as a separate target: its own line, right-aligned, quiet until
     hovered. Deliberately not a primary-looking button - archiving is tidying
     up, not the main action on the page. */
  .ticket-row-actions { display: flex; justify-content: flex-end; margin-top: 8px; }
  .ticket-archive-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px; font: inherit; font-size: .78rem; font-weight: 600;
    color: var(--admin-muted); background: transparent;
    border: 1px solid var(--admin-border); border-radius: 6px; cursor: pointer;
    transition: color .12s, border-color .12s, background .12s;
  }
  .ticket-archive-btn:hover:not(:disabled) {
    color: var(--admin-text); border-color: var(--admin-muted);
    background: var(--admin-nav-hover-tint, rgba(128,128,128,.08));
  }
  .ticket-archive-btn:focus-visible { outline: 2px solid #174871; outline-offset: 2px; }
  .ticket-archive-btn:disabled { opacity: .55; cursor: default; }
  .ticket-archived-note { font-size: .72rem; color: var(--admin-muted); font-style: italic; }
  </style>

  <script>
  // Admin Dashboard JavaScript
  document.addEventListener('DOMContentLoaded', function() {
    loadDashboardStats();
    loadTickets();
    
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const tab = btn.getAttribute('data-tab');
        switchTab(tab);
      });
    });
    
    /* Archive / restore, delegated from the list container.
       Delegated rather than bound per row because renderTickets() replaces the
       whole innerHTML on every poll, which would drop per-row listeners. */
    document.getElementById('ticketsList').addEventListener('click', function (e) {
      const btn = e.target.closest('[data-ticket-archive]');
      if (!btn) { return; }
      // The row itself opens the ticket modal; this button must not.
      e.stopPropagation();
      e.preventDefault();
      archiveTicket(btn.getAttribute('data-ticket-archive'),
                    btn.getAttribute('data-archive-to') === '1',
                    btn);
    });

    // Filter changes
    document.getElementById('ticketStatusFilter').addEventListener('change', loadTickets);
    document.getElementById('ticketPriorityFilter').addEventListener('change', loadTickets);
    document.getElementById('warrantyStatusFilter').addEventListener('change', loadWarrantyClaims);
    document.getElementById('returnStatusFilter').addEventListener('change', loadReturnRequests);

    /* Start the live-update heartbeat so customer replies reach the inbox and
       the open thread without a refresh. The first tick only primes the
       digest, since loadTickets() above is already fetching the list. */
    adminPollOnce().finally(scheduleAdminPoll);
  });

  function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.classList.remove('active');
    });
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    
    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => {
      content.classList.remove('active');
    });
    document.getElementById(`${tab}-tab`).classList.add('active');
    
    // Load appropriate data
    switch(tab) {
      case 'tickets':
        loadTickets();
        break;
      case 'warranty':
        loadWarrantyClaims();
        break;
      case 'returns':
        loadReturnRequests();
        break;
      case 'faq':
        loadFAQs();
        break;
    }
  }

  function loadDashboardStats() {
    // Load support tickets count
    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=get_tickets&limit=1')
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          document.getElementById('totalTickets').textContent = result.total;
        }
      });
    
    // Load open tickets count
    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=get_tickets&status=open&limit=1')
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          document.getElementById('openTickets').textContent = result.total;
        }
      });
    
    // Load warranty claims count
    fetch('<?= WEB_ROOT ?>public/authenticated/api/warranty.php?action=get_claims&limit=1')
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          document.getElementById('warrantyClaims').textContent = result.total;
        }
      });
    
    // Load return requests count
    fetch('<?= WEB_ROOT ?>public/authenticated/api/returns.php?action=get_returns&limit=1')
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          document.getElementById('returnRequests').textContent = result.total;
        }
      });
  }

  function loadTickets() {
    const status = document.getElementById('ticketStatusFilter').value;
    const priority = document.getElementById('ticketPriorityFilter').value;
    
    let url = '<?= WEB_ROOT ?>public/authenticated/api/support.php?action=get_tickets&limit=50';
    /* 'archived' is a pile, not a status. Sending it as &status= would be
       rejected by the endpoint's whitelist and silently return the whole
       unfiltered inbox, which looks like the filter is broken. */
    if (status === 'archived') {
      url += '&archived=1';
    } else if (status) {
      url += `&status=${status}`;
    }
    if (priority) url += `&priority=${priority}`;
    
    fetch(url)
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          renderTickets(result.tickets);
        }
      });
  }

  function renderTickets(tickets) {
    const container = document.getElementById('ticketsList');
    container.innerHTML = '';

    if (!tickets.length) {
      const status = document.getElementById('ticketStatusFilter');
      const label = status.value ? status.options[status.selectedIndex].text.toLowerCase() : '';
      container.innerHTML = `<p class="thread-empty">No ${label ? esc(label) + ' ' : ''}tickets right now.</p>`;
      return;
    }

    tickets.forEach(ticket => {
      const unread = Number(ticket.unread_count || 0);
      const ticketElement = document.createElement('div');
      ticketElement.className = 'ticket-item' + (unread > 0 ? ' has-unread' : '');
      ticketElement.onclick = () => showTicketDetails(ticket.id);

      /* The button only exists on a resolved ticket, and "resolved" means the
         two stored values the badge displays as Resolved - 'resolved' and the
         legacy 'closed'. Deriving it from the badge the row is already wearing
         keeps the button and the badge from disagreeing; the endpoint re-checks
         it under a row lock anyway. */
      const isResolved = ticket.status === 'resolved' || ticket.status === 'closed';
      const archived = Number(ticket.is_archived) === 1;

      ticketElement.innerHTML = `
        <div class="item-header">
          <h3 class="item-title">${esc(ticket.subject)}</h3>
          <div class="item-meta">
            ${unread > 0
              ? `<span class="unread-badge" title="${unread} unread message${unread === 1 ? '' : 's'} from the customer">${unread}</span>`
              : ''}
            <span class="status-badge ${statusClass(ticket.status)}">${esc(statusLabel(ticket.status))}</span>
            <span class="priority-badge priority-${esc(ticket.priority)}">${esc(ticket.priority)}</span>
            <span>${esc(ticket.ticket_number)}</span>
          </div>
        </div>
        <div class="item-description">
          <strong>${esc(ticket.firstname)} ${esc(ticket.lastname)}</strong> (${esc(ticket.email)})<br>
          Category: ${esc(String(ticket.category).replace('_', ' '))}<br>
          Created: ${new Date(ticket.created_at).toLocaleDateString()}
          ${archived && ticket.archived_at
            ? `<br><span class="ticket-archived-note">Archived ${new Date(ticket.archived_at).toLocaleDateString()}</span>`
            : ''}
        </div>
        ${isResolved ? `
          <div class="ticket-row-actions">
            <button type="button" class="ticket-archive-btn"
                    data-ticket-archive="${ticket.id}"
                    data-archive-to="${archived ? '0' : '1'}">
              <i class="fi fi-rr-${archived ? 'inbox' : 'box'}" aria-hidden="true"></i>
              ${archived ? 'Restore to inbox' : 'Archive'}
            </button>
          </div>` : ''}
      `;
      
      container.appendChild(ticketElement);
    });
  }

  function loadWarrantyClaims() {
    const status = document.getElementById('warrantyStatusFilter').value;
    
    let url = '<?= WEB_ROOT ?>public/authenticated/api/warranty.php?action=get_claims&limit=50';
    if (status) url += `&status=${status}`;
    
    fetch(url)
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          renderWarrantyClaims(result.claims);
        }
      });
  }

  function renderWarrantyClaims(claims) {
    const container = document.getElementById('warrantyList');
    container.innerHTML = '';
    
    claims.forEach(claim => {
      const claimElement = document.createElement('div');
      claimElement.className = 'warranty-item';
      claimElement.onclick = () => showWarrantyDetails(claim.id);
      
      claimElement.innerHTML = `
        <div class="item-header">
          <h3 class="item-title">${claim.product_name}</h3>
          <div class="item-meta">
            <span class="status-badge status-${claim.status}">${claim.status.replace('_', ' ')}</span>
            <span>${claim.claim_number}</span>
          </div>
        </div>
        <div class="item-description">
          <strong>${claim.firstname} ${claim.lastname}</strong> (${claim.email})<br>
          Issue: ${claim.issue_description.substring(0, 100)}...<br>
          Created: ${new Date(claim.created_at).toLocaleDateString()}
        </div>
      `;
      
      container.appendChild(claimElement);
    });
  }

  function loadReturnRequests() {
    const status = document.getElementById('returnStatusFilter').value;
    
    let url = '<?= WEB_ROOT ?>public/authenticated/api/returns.php?action=get_returns&limit=50';
    if (status) url += `&status=${status}`;
    
    fetch(url)
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          renderReturnRequests(result.returns);
        }
      });
  }

  function renderReturnRequests(returns) {
    const container = document.getElementById('returnsList');
    container.innerHTML = '';
    
    returns.forEach(returnReq => {
      const returnElement = document.createElement('div');
      returnElement.className = 'return-item';
      returnElement.onclick = () => showReturnDetails(returnReq.id);
      
      returnElement.innerHTML = `
        <div class="item-header">
          <h3 class="item-title">Order ${returnReq.invoice_no}</h3>
          <div class="item-meta">
            <span class="status-badge status-${returnReq.status}">${returnReq.status.replace('_', ' ')}</span>
            <span>${returnReq.return_number}</span>
          </div>
        </div>
        <div class="item-description">
          <strong>${returnReq.firstname} ${returnReq.lastname}</strong> (${returnReq.email})<br>
          Reason: ${returnReq.reason.replace('_', ' ')}<br>
          Type: ${returnReq.return_type}<br>
          Created: ${new Date(returnReq.created_at).toLocaleDateString()}
        </div>
      `;
      
      container.appendChild(returnElement);
    });
  }

  function loadFAQs() {
    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=get_faq')
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          renderFAQs(result.faqs);
        }
      });
  }

  function renderFAQs(faqs) {
    const container = document.getElementById('adminFaqList');
    container.innerHTML = '';
    
    faqs.forEach(faq => {
      const faqElement = document.createElement('div');
      faqElement.className = 'faq-item';
      
      faqElement.innerHTML = `
        <div class="item-header">
          <h3 class="item-title">${faq.question}</h3>
          <div class="item-meta">
            <span>${faq.category_name}</span>
            <span>Views: ${faq.view_count}</span>
          </div>
        </div>
        <div class="item-description">
          ${faq.answer.substring(0, 150)}...
        </div>
      `;
      
      container.appendChild(faqElement);
    });
  }

  // Customer-written text goes into innerHTML all over this page. Subjects and
  // messages are typed by whoever filed the ticket, so without this a ticket
  // whose subject is a <script> tag runs it in the admin's session.
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* The four states this feature shows, keyed by what is actually stored.
     'in_progress' and 'closed' are legacy values still sitting on older rows
     (see DBSQL/add_support_ticket_conversation.sql, which widened the ENUM
     rather than rewriting them); they are folded onto Pending and Resolved so
     no ticket ever renders a raw database value like "in_progress".

     Both of these were called from renderTickets() and renderTicketModal()
     but never defined, which threw ReferenceError and took down the whole
     render - the admin ticket list stayed empty and the ticket modal never
     filled in. */
  const TICKET_STATUS_KEY = {
    open: 'open',
    pending: 'pending',
    in_progress: 'pending',
    resolved: 'resolved',
    closed: 'resolved',
    reopened: 'reopened'
  };
  const TICKET_STATUS_LABEL = {
    open: 'Open', pending: 'Pending', resolved: 'Resolved', reopened: 'Reopened'
  };

  function statusLabel(status) {
    const key = TICKET_STATUS_KEY[status];
    // Unknown value: show it readably rather than printing nothing.
    return key ? TICKET_STATUS_LABEL[key] : String(status || '').replace(/_/g, ' ');
  }

  /* Returns the `status-*` class the stylesheet above defines - status-open,
     status-pending, status-resolved, status-reopened. The customer page uses
     an `is-*` prefix for the same four states; the two stylesheets are
     separate, so the prefixes are not interchangeable. */
  function statusClass(status) {
    return 'status-' + (TICKET_STATUS_KEY[status] || String(status || '').replace(/[^a-z_]/gi, ''));
  }

  // The ticket currently open in the modal, so the reply box and the state
  // buttons know what they are acting on without re-reading the DOM.
  let currentTicket = null;
  /* Live-update bookkeeping for the open thread: the newest message already
     on screen, and the lock state the modal was built with. The heartbeat
     compares against these so it can append a new reply instead of rebuilding
     the modal - a rebuild would wipe whatever the admin had half-typed. */
  let currentTicketLastMsgId = 0;
  let currentTicketLocked = false;

  /* One message bubble. Extracted from renderTicketModal so the first render
     and the live append share a single template. */
  function ticketBubbleHtml(msg) {
    const mine = msg.sender === 'admin';
    const internal = Number(msg.is_internal) === 1;
    const who = `${esc(msg.firstname || '')} ${esc(msg.lastname || '')}`.trim()
              || (mine ? 'Support' : 'Customer');
    return `
      <div class="chat-row ${mine ? 'is-admin' : 'is-customer'}${internal ? ' is-internal' : ''}"
           data-msg-id="${esc(msg.id)}">
        <div class="chat-meta">
          <strong>${who}${mine ? ' &middot; Support' : ''}</strong>
          ${internal ? '<span class="chat-note-tag">Internal note</span>' : ''}
          <span>${new Date(msg.created_at).toLocaleString()}</span>
        </div>
        <div class="chat-bubble">${esc(msg.message)}</div>
      </div>`;
  }

  function showTicketDetails(ticketId) {
    fetch(`<?= WEB_ROOT ?>public/authenticated/api/support.php?action=get_ticket&id=${ticketId}`)
      .then(response => response.json())
      .then(result => {
        if (!result.success) {
          alert(result.message || 'Could not open that ticket.');
          return;
        }
        currentTicket = result.ticket;
        const modal = document.getElementById('ticketModal');
        // Switches the shared modal into the layout where only the thread
        // scrolls; showWarrantyDetails/showReturnDetails take it off again.
        modal.classList.add('is-ticket');
        renderTicketModal(result.ticket, result.messages || []);
        modal.style.display = 'block';
        // An open thread updates on the quick cadence.
        scheduleAdminPoll();

        /* Opening the thread is what "read" means here. Fire-and-forget: the
           badge is a convenience and a failed mark must not stop the admin
           reading the ticket they just opened. The list refreshes on success
           so the row's badge clears without a manual reload. */
        fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=mark_read', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({
            ticket_id: ticketId,
            csrf_token: document.getElementById('ticketCsrf').value
          })
        }).then(r => r.json()).then(res => { if (res && res.success) { loadTickets(); } })
          .catch(() => {});
      });
  }

  function renderTicketModal(ticket, messages) {
    /* The server decides whether the thread is open - see
       supportThreadIsLocked() in api/support.php. Read from the flag rather
       than re-deriving the rule from the status string here, so the composer
       and the endpoint can never disagree about whether a message is allowed. */
    const locked = Number(ticket.is_locked) === 1;

    /* NOT ticket.message plus the messages array.

       createSupportTicket() writes the customer's opening text to BOTH
       support_tickets.message and the first support_ticket_messages row, so
       rendering the column and then the thread printed the opening message
       twice on every ticket - verified against the live rows. The thread
       already starts where it should; the column is the same text. */
    const bubbles = messages.map(ticketBubbleHtml).join('');

    // Where the thread ends and whether it is closed, so the live heartbeat
    // can append what arrives next and notice a state change.
    currentTicketLastMsgId = messages.reduce((hi, m) => Math.max(hi, Number(m.id) || 0), 0);
    currentTicketLocked = locked;

    document.getElementById('modalTitle').textContent = `Ticket ${ticket.ticket_number}`;
    document.getElementById('modalBody').innerHTML = `
      <div class="ticket-details">
        <h4>Ticket Information</h4>
        <dl class="ticket-facts">
          <dt>Subject</dt><dd>${esc(ticket.subject)}</dd>
          <dt>Customer</dt><dd>${esc(ticket.firstname)} ${esc(ticket.lastname)} (${esc(ticket.email)})</dd>
          ${ticket.phone ? `<dt>Contact</dt><dd>${esc(ticket.phone)}</dd>` : ''}
          ${ticket.order_number ? `<dt>Order</dt><dd>${esc(ticket.order_number)}</dd>` : ''}
          <dt>Category</dt><dd>${esc(String(ticket.category).replace('_', ' '))}</dd>
          <dt>Status</dt>
          <dd><span class="status-badge ${statusClass(ticket.status)}">${esc(statusLabel(ticket.status))}</span></dd>
          <dt>Priority</dt>
          <dd><span class="priority-badge priority-${esc(ticket.priority)}">${esc(ticket.priority)}</span></dd>
          <dt>Created</dt><dd>${new Date(ticket.created_at).toLocaleString()}</dd>
        </dl>

        <h4>Conversation</h4>
        <div class="chat-thread" id="chatThread">
          ${bubbles || '<p class="chat-empty">No messages on this ticket yet.</p>'}
        </div>

        ${locked ? `
          <div class="chat-locked">
            <i class="fi fi-rr-check-circle"></i>
            <span>This ticket is resolved, so the conversation is closed. The full
                  history stays on the ticket. Reopen it to continue talking.</span>
          </div>` : `
          <h4>Reply to ${esc(ticket.firstname)}</h4>
          <div class="reply-box">
            <textarea id="ticketReply" rows="4"
                      placeholder="Write your reply. The customer sees it in their ticket and gets a notification."></textarea>
            <label class="reply-opts" id="ticketInternalRow">
              <input type="checkbox" id="ticketInternal">
              <span>Internal note &ndash; kept on the ticket,
                    <strong>never shown to the customer</strong></span>
            </label>
          </div>`}

        <div class="ticket-actions">
          ${locked ? '' : `
            <button class="btn-primary" id="ticketSend" onclick="sendTicketReply()">
              <i class="fi fi-rr-paper-plane"></i> <span id="ticketSendLabel">Send reply</span>
            </button>`}
          ${locked
            ? `<button class="btn-muted" onclick="setTicketStatus('reopened')">
                 <i class="fi fi-rr-refresh"></i> Reopen ticket
               </button>
               <?php /* Only on a resolved ticket, which is what `locked` means
                        here - it comes from the server's own
                        supportThreadIsLocked(), the same flag the composer
                        lock uses. Reopening clears the archive flag server
                        side, so these two buttons cannot leave a ticket both
                        live and hidden. */ ?>
               <button class="btn-muted" id="ticketArchiveBtn"
                       onclick="archiveTicket(${Number(ticket.id)}, ${Number(ticket.is_archived) === 1 ? 'false' : 'true'}, this)">
                 <i class="fi fi-rr-${Number(ticket.is_archived) === 1 ? 'inbox' : 'box'}"></i>
                 ${Number(ticket.is_archived) === 1 ? 'Restore to inbox' : 'Archive ticket'}
               </button>`
            : `<button class="btn-muted" onclick="setTicketStatus('resolved')">
                 <i class="fi fi-rr-check"></i> Mark as resolved
               </button>`}
          <span class="ticket-feedback" id="ticketFeedback"></span>
        </div>
      </div>
    `;

    scrollThreadToBottom();

    /* Keep the send button and the toggle saying the same thing. Bound on
       every render because the modal body is rebuilt from scratch each time,
       which also means the checkbox starts unticked on every open and after
       every send - an internal note can never carry over into the next
       reply. */
    const internalBox = document.getElementById('ticketInternal');
    if (internalBox) {
      internalBox.addEventListener('change', syncReplyMode);
      syncReplyMode();
    }
  }

  /* Put the newest message in view.

     Deferred to an animation frame, and that is the whole point of it being
     a function. renderTicketModal runs while the modal is still
     display:none - showTicketDetails only sets display:block afterwards - and
     a display:none element has no layout at all, so scrollHeight and
     clientHeight are both 0 and the old `thread.scrollTop = thread.scrollHeight`
     was a silent no-op. Every ticket therefore opened at the TOP of its
     thread, which on a long one is the oldest message. Two frames: the first
     lets the browser lay the now-visible modal out, the second measures it.

     scrollTop rather than scrollIntoView: the thread is a scroll container
     inside the modal, and scrollIntoView would move the page behind it too. */
  function scrollThreadToBottom() {
    const thread = document.getElementById('chatThread');
    if (!thread) { return; }
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        thread.scrollTop = thread.scrollHeight;
      });
    });
  }

  /* Reflect "this will be invisible to the customer" in the three places the
     admin is actually looking: the toggle, the textarea's placeholder, and
     the button they are about to press. The old UI said it once, in grey, in
     a line of small text - and then sent silently either way. */
  function syncReplyMode() {
    const box = document.getElementById('ticketInternal');
    if (!box) { return; }
    const on  = box.checked;

    const row = document.getElementById('ticketInternalRow');
    if (row) { row.classList.toggle('is-armed', on); }

    const btn = document.getElementById('ticketSend');
    if (btn) { btn.classList.toggle('is-internal-mode', on); }

    const lbl = document.getElementById('ticketSendLabel');
    if (lbl) { lbl.textContent = on ? 'Save internal note' : 'Send reply'; }

    const ta = document.getElementById('ticketReply');
    if (ta) {
      ta.placeholder = on
        ? 'This note stays on the ticket. The customer will NOT see it.'
        : 'Write your reply. The customer sees it in their ticket and gets a notification.';
    }
  }

  function ticketFeedback(text, kind) {
    const el = document.getElementById('ticketFeedback');
    if (!el) { return; }
    el.textContent = text;
    el.className = 'ticket-feedback' + (kind ? ' is-' + kind : '');
  }

  function sendTicketReply() {
    if (!currentTicket) { return; }
    const box = document.getElementById('ticketReply');
    // The composer is not rendered on a resolved ticket, so there is nothing
    // to read. Guarded rather than assumed: the button is gone too, but a
    // stale onclick from a half-rendered modal would otherwise throw here.
    if (!box) { return; }
    const message = box.value.trim();
    if (!message) {
      ticketFeedback('Write a message first.', 'error');
      box.focus();
      return;
    }

    /* Read the mode ONCE, here, and use this same value for the request and
       for the confirmation - so what the admin is told is necessarily what
       was actually sent, even if the box is clicked while the request is in
       flight. */
    const internal = !!(document.getElementById('ticketInternal') || {}).checked;

    // Disabled while in flight: a reply sent twice is two notifications to the
    // customer saying the same thing.
    const btn = document.getElementById('ticketSend');
    btn.disabled = true;
    ticketFeedback(internal ? 'Saving note...' : 'Sending...', '');

    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=add_message', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        ticket_id: currentTicket.id,
        message: message,
        is_internal: internal,
        csrf_token: document.getElementById('ticketCsrf').value
      })
    })
      .then(r => r.json())
      .then(result => {
        btn.disabled = false;
        if (!result.success) {
          ticketFeedback(result.message || 'Could not send that.', 'error');
          /* The server refused because the ticket was resolved somewhere else
             while this modal sat open. Re-read it so the composer closes and
             the admin is looking at the ticket's real state rather than a
             dead textarea. */
          if (result.code === 'thread_locked') {
            loadTickets();
            showTicketDetails(currentTicket.id);
          }
          return;
        }
        box.value = '';
        /* Name the outcome. "Sent." was identical for both modes, so a reply
           that had silently become an internal note looked exactly like one
           the customer had received - which is how two replies ended up
           invisible on TW-20261008-00014 with nothing on screen saying so. */
        ticketFeedback(
          internal
            ? 'Internal note saved - the customer cannot see this.'
            : 'Reply sent to the customer.',
          'ok'
        );
        // Re-read the thread rather than appending locally, so what the modal
        // shows is what the database holds.
        showTicketDetails(currentTicket.id);
      })
      .catch(() => {
        btn.disabled = false;
        ticketFeedback('Could not reach the server.', 'error');
      });
  }

  /* Archive or restore one ticket.
     `wantArchived` is passed explicitly rather than toggled from what the row
     was showing, so a stale list (the poll redraws every few seconds) cannot
     turn a restore into a re-archive. */
  function archiveTicket(ticketId, wantArchived, btn) {
    const label = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.textContent = wantArchived ? 'Archiving…' : 'Restoring…'; }

    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=archive_ticket', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        ticket_id: Number(ticketId),
        archived: !!wantArchived,
        csrf_token: document.getElementById('ticketCsrf').value
      })
    })
      .then(r => r.json())
      .then(result => {
        if (!result.success) {
          if (btn) { btn.disabled = false; btn.innerHTML = label; }
          // The endpoint refuses a ticket that is no longer resolved, and that
          // reason is worth reading rather than swallowing.
          if (window.uiAlert) { window.uiAlert(result.message || 'Could not archive that ticket.'); }
          else { alert(result.message || 'Could not archive that ticket.'); }
          return;
        }
        // Re-read rather than removing the row locally: the ticket has moved
        // between two piles, and the counters key off the same data.
        loadTickets();
        loadDashboardStats();
        // If this was pressed inside the open ticket, redraw it so the button
        // flips to its opposite rather than lying about the current state.
        if (currentTicket && Number(currentTicket.id) === Number(ticketId)) {
          showTicketDetails(currentTicket.id);
        }
      })
      .catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = label; }
        if (window.uiAlert) { window.uiAlert('Could not reach the server.'); }
        else { alert('Could not reach the server.'); }
      });
  }

  function setTicketStatus(status) {
    if (!currentTicket) { return; }
    ticketFeedback('Saving...', '');

    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=update_ticket', {
      method: 'PUT',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        ticket_id: currentTicket.id,
        status: status,
        csrf_token: document.getElementById('ticketCsrf').value
      })
    })
      .then(r => r.json())
      .then(result => {
        if (!result.success) {
          ticketFeedback(result.message || 'Could not update the ticket.', 'error');
          return;
        }
        // The list and the counters both key off status, so both are stale now.
        loadTickets();
        loadDashboardStats();
        showTicketDetails(currentTicket.id);
      })
      .catch(() => ticketFeedback('Could not reach the server.', 'error'));
  }

  /* ======================================================================
     REALTIME - customer replies land in the open thread and in the inbox
     without a refresh.
     ----------------------------------------------------------------------
     Same heartbeat the customer page calls; see supportPoll() in
     api/support.php for why this polls rather than using WebSockets or SSE
     on this stack. Quick cadence while a ticket is open and being read,
     slower when the dashboard is just sitting on the list.
     ====================================================================== */
  const ADMIN_POLL_OPEN_MS = 3000;
  const ADMIN_POLL_IDLE_MS = 10000;
  let adminDigest = null;
  let adminPollInFlight = false;
  let adminPollTimer = null;

  /* Is the modal actually showing a TICKET conversation?
     #ticketModal is shared with the warranty and return views, and those do
     not clear currentTicket - so checking currentTicket alone would have this
     appending chat bubbles while the admin is looking at a warranty claim.
     #chatThread only exists in the ticket render, which makes it the honest
     test. */
  function ticketThreadEl() {
    const modal = document.getElementById('ticketModal');
    if (!currentTicket || !modal || modal.style.display !== 'block') { return null; }
    return document.getElementById('chatThread');
  }

  function adminPollOnce() {
    // Never two in flight, and never on a tab nobody is looking at.
    if (adminPollInFlight || document.hidden) { return Promise.resolve(); }
    adminPollInFlight = true;

    const thread = ticketThreadEl();
    let url = '<?= WEB_ROOT ?>public/authenticated/api/support.php?action=poll';
    if (thread) {
      url += `&ticket_id=${encodeURIComponent(currentTicket.id)}&after_id=${currentTicketLastMsgId}`;
    }

    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(r => r.json().catch(() => null))
      .then(res => {
        if (!res || !res.success) { return; }

        const th = res.thread;
        if (th && thread && Number(th.ticket_id) === Number(currentTicket.id)) {
          const arrived = th.messages || [];

          if (arrived.length) {
            arrived.forEach(m => {
              const id = Number(m.id) || 0;
              // Guard a double delivery - a poll overlapping the re-read that
              // sending already does - from duplicating a bubble.
              if (id && thread.querySelector(`[data-msg-id="${id}"]`)) { return; }
              const empty = thread.querySelector('.chat-empty');
              if (empty) { empty.remove(); }
              thread.insertAdjacentHTML('beforeend', ticketBubbleHtml(m));
              if (id > currentTicketLastMsgId) { currentTicketLastMsgId = id; }
            });
            /* Always jump to the new message, rather than only when already
               near the bottom. A reply that lands off-screen is the thing
               this whole feature exists to surface. */
            thread.scrollTop = thread.scrollHeight;

            /* The admin is reading the thread, so an arriving customer message
               is already read - otherwise the row's badge would light for a
               message open on screen. */
            fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=mark_read', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
              body: JSON.stringify({
                ticket_id: currentTicket.id,
                csrf_token: document.getElementById('ticketCsrf').value
              })
            }).catch(() => {});
          }

          /* Resolved or reopened somewhere else while this modal sat open.
             The composer and the action buttons both change shape, so this is
             the one case that needs a full re-render rather than an append. */
          if ((Number(th.is_locked) === 1) !== currentTicketLocked) {
            showTicketDetails(currentTicket.id);
          }
        }

        // One short string stands in for "has anything changed?", so the list
        // query runs on an actual change rather than every tick.
        if (res.digest !== adminDigest) {
          const firstTick = adminDigest === null;
          adminDigest = res.digest;
          // The initial loadTickets() on DOMContentLoaded is already fetching
          // this, so the first tick only primes the digest.
          if (!firstTick) { loadTickets(); }
        }
      })
      .catch(() => {})   // a dropped heartbeat retries on the next tick
      .finally(() => { adminPollInFlight = false; });
  }

  function scheduleAdminPoll() {
    clearTimeout(adminPollTimer);
    adminPollTimer = setTimeout(
      () => { adminPollOnce().finally(scheduleAdminPoll); },
      ticketThreadEl() ? ADMIN_POLL_OPEN_MS : ADMIN_POLL_IDLE_MS
    );
  }

  // Catch up as soon as the tab is looked at again.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { adminPollOnce().finally(scheduleAdminPoll); }
  });

  function showWarrantyDetails(claimId) {
    fetch(`<?= WEB_ROOT ?>public/authenticated/api/warranty.php?action=get_claim&id=${claimId}`)
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          const claim = result.claim;
          
          document.getElementById('modalTitle').textContent = `Warranty Claim ${claim.claim_number}`;
          document.getElementById('modalBody').innerHTML = `
            <div class="claim-details">
              <h4>Claim Information</h4>
              <p><strong>Product:</strong> ${claim.product_name}</p>
              <p><strong>Customer:</strong> ${claim.firstname} ${claim.lastname} (${claim.email})</p>
              <p><strong>Status:</strong> <span class="status-badge status-${claim.status}">${claim.status.replace('_', ' ')}</span></p>
              <p><strong>Priority:</strong> <span class="priority-badge priority-${claim.priority}">${claim.priority}</span></p>
              <p><strong>Created:</strong> ${new Date(claim.created_at).toLocaleString()}</p>
              
              <h4>Issue Description</h4>
              <p>${claim.issue_description}</p>
              
              ${claim.resolution_notes ? `
                <h4>Resolution Notes</h4>
                <p>${claim.resolution_notes}</p>
              ` : ''}
            </div>
          `;
          
          /* Back to the normal scrolling body: this view is prose of
             unpredictable length, and the ticket layout would clip it. */
          document.getElementById('ticketModal').classList.remove('is-ticket');
          document.getElementById('ticketModal').style.display = 'block';
        }
      });
  }

  function showReturnDetails(returnId) {
    fetch(`<?= WEB_ROOT ?>public/authenticated/api/returns.php?action=get_return&id=${returnId}`)
      .then(response => response.json())
      .then(result => {
        if (result.success) {
          const returnReq = result.return;
          const items = result.items;
          
          document.getElementById('modalTitle').textContent = `Return Request ${returnReq.return_number}`;
          document.getElementById('modalBody').innerHTML = `
            <div class="return-details">
              <h4>Return Information</h4>
              <p><strong>Order:</strong> ${returnReq.invoice_no}</p>
              <p><strong>Customer:</strong> ${returnReq.firstname} ${returnReq.lastname} (${returnReq.email})</p>
              <p><strong>Reason:</strong> ${returnReq.reason.replace('_', ' ')}</p>
              <p><strong>Type:</strong> ${returnReq.return_type}</p>
              <p><strong>Status:</strong> <span class="status-badge status-${returnReq.status}">${returnReq.status.replace('_', ' ')}</span></p>
              <p><strong>Created:</strong> ${new Date(returnReq.created_at).toLocaleString()}</p>
              
              <h4>Description</h4>
              <p>${returnReq.description}</p>
              
              <h4>Items</h4>
              <div class="return-items">
                ${items.map(item => `
                  <div class="return-item">
                    <strong>${item.product_name}</strong><br>
                    Quantity: ${item.quantity} / ${item.original_quantity}<br>
                    Condition: ${item.condition}
                  </div>
                `).join('')}
              </div>
            </div>
          `;
          
          /* Back to the normal scrolling body: this view is prose of
             unpredictable length, and the ticket layout would clip it. */
          document.getElementById('ticketModal').classList.remove('is-ticket');
          document.getElementById('ticketModal').style.display = 'block';
        }
      });
  }

  function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    // Nothing is being read any more, so drop back to the slow cadence.
    scheduleAdminPoll();
  }

  // ---- Add FAQ -------------------------------------------------------------
  let faqCategoriesLoaded = false;

  function showAddFAQModal() {
    const form = document.getElementById('faqForm');
    form.reset();
    document.getElementById('faqActive').checked = true;
    setFaqError('');
    document.getElementById('faqModal').style.display = 'block';

    // Fetched once per page load, not per open - the category list barely
    // changes and re-fetching on every open just adds a flicker.
    if (!faqCategoriesLoaded) { loadFAQCategories(); }
    else { document.getElementById('faqQuestion').focus(); }
  }

  function setFaqError(msg) {
    const box = document.getElementById('faqError');
    box.textContent = msg || '';
    box.hidden = !msg;
  }

  function loadFAQCategories() {
    const sel = document.getElementById('faqCategory');
    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=get_faq_categories')
      .then(r => r.json())
      .then(result => {
        if (!result.success || !Array.isArray(result.categories) || !result.categories.length) {
          sel.innerHTML = '<option value="">No categories available</option>';
          setFaqError('No FAQ categories exist yet, so an FAQ cannot be filed under one.');
          return;
        }
        sel.innerHTML = '<option value="">Select a category…</option>';
        result.categories.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c.id;
          opt.textContent = c.name;
          sel.appendChild(opt);
        });
        faqCategoriesLoaded = true;
        document.getElementById('faqQuestion').focus();
      })
      .catch(() => {
        sel.innerHTML = '<option value="">Could not load categories</option>';
        setFaqError('Could not reach the server. Check your connection and try again.');
      });
  }

  document.getElementById('faqForm').addEventListener('submit', function (e) {
    e.preventDefault();
    setFaqError('');

    const payload = {
      csrf_token: document.getElementById('faqCsrf').value,
      category_id: parseInt(document.getElementById('faqCategory').value, 10) || 0,
      question: document.getElementById('faqQuestion').value.trim(),
      answer: document.getElementById('faqAnswer').value.trim(),
      sort_order: parseInt(document.getElementById('faqSort').value, 10) || 0,
      is_active: document.getElementById('faqActive').checked
    };

    // Checked here as well as on the server: this saves a round trip, it does
    // not replace the server's validation.
    if (!payload.category_id) { setFaqError('Pick a category.'); return; }
    if (!payload.question)    { setFaqError('Enter the question.'); return; }
    if (!payload.answer)      { setFaqError('Enter the answer.'); return; }

    const btn = document.getElementById('faqSubmit');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('<?= WEB_ROOT ?>public/authenticated/api/support.php?action=add_faq', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(r => r.json())
      .then(result => {
        if (!result.success) {
          setFaqError(result.message || 'Could not save the FAQ.');
          return;
        }
        closeModal('faqModal');
        loadFAQs();   // the list re-reads from the server, so it shows what was actually stored
      })
      .catch(() => setFaqError('Could not reach the server. Check your connection and try again.'))
      .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Save FAQ';
      });
  });

  // Close modal when clicking outside
  window.onclick = function(event) {
    // Every .modal on the page, not just the ticket one - the FAQ modal needs
    // the same click-outside-to-close behaviour.
    if (event.target.classList && event.target.classList.contains('modal')) {
      event.target.style.display = 'none';
    }
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal').forEach(function (m) {
      if (m.style.display === 'block') { m.style.display = 'none'; }
    });
  });
  </script>

  <?php include __DIR__ . '/includes/footer.php'; ?>
