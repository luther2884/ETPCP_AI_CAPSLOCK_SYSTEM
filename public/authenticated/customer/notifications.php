<?php
// customer/notifications.php - Notifications page
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Notifications - TechWorld';
$activeNav = 'notifications';
$userId = getUserId();

// This page paints its own ground (notifications-skin.css + the .tw-bg layers
// below) - suppress the customer-area site background video so it doesn't sit
// on top of it.
//
// It has to be suppressed rather than hidden in CSS. header.php renders the
// video at z-index:-2 with a near-white scrim (rgba(242,243,244,.35)) at
// z-index:-1, and sets body{background:transparent!important}. Nothing sets a
// background on <html>, so body's background propagates to the canvas and
// paints BEHIND both of them - no body background can cover that clip, however
// !important it is, which is why the dark ground was reading washed out. Same
// reason index.php, dashboard.php and ai_builder.php set this.
$suppressSiteBgVideo = true;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $count = markAllNotificationsAsRead($userId);
        $message = "Marked $count notifications as read";
        $messageType = 'success';
    } elseif ($action === 'mark_read' && isset($_POST['notification_id'])) {
        $notificationId = (int)$_POST['notification_id'];
        if (markNotificationAsRead($userId, $notificationId)) {
            $message = 'Notification marked as read';
            $messageType = 'success';
        } else {
            $message = 'Failed to mark notification as read';
            $messageType = 'error';
        }
    }
}

// Get notification preferences
try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM user_notification_preferences WHERE user_id = ?");
    $stmt->execute([$userId]);
    $preferences = $stmt->fetch();

    if (!$preferences) {
        // Create default preferences
        $stmt = $pdo->prepare("INSERT INTO user_notification_preferences (user_id) VALUES (?)");
        $stmt->execute([$userId]);

        // Fetch the newly created preferences
        $stmt = $pdo->prepare("SELECT * FROM user_notification_preferences WHERE user_id = ?");
    $stmt->execute([$userId]);
        $preferences = $stmt->fetch();
    }

    // Ensure all preference keys exist with default values
    $defaultPreferences = [
        'email_notifications' => 1,
        'push_notifications' => 1,
        'sms_notifications' => 0,
        'order_updates' => 1,
        'payment_alerts' => 1,
        'promotional_offers' => 1,
        'security_alerts' => 1,
        'system_updates' => 0
    ];

    // Merge with defaults to ensure all keys exist
    $preferences = array_merge($defaultPreferences, $preferences ?: []);

} catch (PDOException $e) {
    error_log('Error loading notification preferences: ' . $e->getMessage());
    $preferences = [
        'email_notifications' => 1,
        'push_notifications' => 1,
        'sms_notifications' => 0,
        'order_updates' => 1,
        'payment_alerts' => 1,
        'promotional_offers' => 1,
        'security_alerts' => 1,
        'system_updates' => 0
    ];
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

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">

  <!-- Figtree (titles, body copy) + JetBrains Mono (labels, chips, counts,
       timestamps, categories, pagination). Same pair the landing page uses. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
</head>
<body class="nfx">
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<!-- The homepage's own background layers, same markup guest/index.php and
     customer/index.php render (techworld-anim.css sections 3a-3c, ported into
     notifications-skin.css so this page links no extra sheet). Three layers
     because the grid and the two blobs animate independently - 26s linear,
     22s and 28s ease-in-out on opposite drifts - which body::before/::after
     alone cannot carry. Fixed, click-through, z-index 0, behind everything. -->
<div class="tw-bg tw-bg-grid" aria-hidden="true"></div>
<div class="tw-bg" aria-hidden="true">
  <div class="tw-bg-blob tw-bg-blob-pink"></div>
  <div class="tw-bg-blob tw-bg-blob-cyan"></div>
</div>

<section class="notifications-page">
  <div class="container">
    <!-- "Mark All as Read" moved up into this row. It keeps its
         .notifications-controls > .bulk-actions > .btn-secondary nesting,
         because markAllAsRead() finds the button with
         `document.querySelector('.notifications-controls .btn-secondary')`
         for its "All marked as read" feedback. Moving the button out of that
         wrapper would have left the selector matching nothing - the handler
         guards with `if (btn)`, so it would have failed silently rather than
         erroring. This is the only .notifications-controls in the document,
         so the selector still resolves to exactly this button. -->
    <div class="notifications-header">
      <div class="nf-head-main">
        <p class="nf-eyebrow" id="nfEyebrow">Customer account</p>
        <h1>Notifications</h1>
        <p>Stay updated with your orders, payments, and promotions</p>
      </div>
      <div class="notifications-controls">
        <div class="bulk-actions">
          <button class="btn-secondary" onclick="markAllAsRead()">
            <i class="fi fi-rr-check"></i>
            Mark All as Read
          </button>
        </div>
      </div>
    </div>

    <div class="notifications-layout">
      <!-- Notifications List -->
      <div class="notifications-main">
        <div class="nf-filterbar">
          <!-- Same six buttons, same order, same data-filter values, same
               click binding. The .nf-chip-count span is filled in by
               nfLoadChipCounts() below; it starts empty and collapses. -->
          <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all" aria-pressed="true">All <span class="nf-chip-count"></span></button>
            <button class="filter-tab" data-filter="order_status" aria-pressed="false">Orders <span class="nf-chip-count"></span></button>
            <button class="filter-tab" data-filter="payment" aria-pressed="false">Payments <span class="nf-chip-count"></span></button>
            <button class="filter-tab" data-filter="promotional" aria-pressed="false">Promotions <span class="nf-chip-count"></span></button>
            <button class="filter-tab" data-filter="security" aria-pressed="false">Security <span class="nf-chip-count"></span></button>
            <button class="filter-tab" data-filter="system" aria-pressed="false">System <span class="nf-chip-count"></span></button>
          </div>
        </div>

        <div class="notifications-list" id="notificationsList">
          <!-- Notifications will be loaded here via AJAX -->
          <div class="loading-state">
            <i class="fi fi-rr-spinner"></i>
            <p>Loading notifications...</p>
          </div>
        </div>

        <div class="nf-pagerow">
          <div class="nf-showing" id="nfShowing"></div>
          <div class="pagination" id="pagination">
            <!-- Pagination will be loaded here -->
          </div>
        </div>
      </div>

      <!-- Notification Preferences removed -->
  </div>
</section>

<style>
.notifications-page {
  padding: 40px 0;
  background: var(--page-bg-gradient);
  min-height: 100vh;
}

.notifications-header {
  text-align: center;
  margin-bottom: 40px;
}

.notifications-header h1 {
  /* Warm vanilla ink so the title belongs to the same family as the panels
     below it, instead of the generic light blue-white. */
  color: #fff4ef;
  font-size: 32px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}

/* The bell reads as part of the title, so it takes the same colour. */
.notifications-header h1 i { color: #fff4ef; }

.notifications-header p {
  color: rgba(255, 244, 239, 0.72);
  font-size: 16px;
}

  .notifications-layout {
  display: grid;
  grid-template-columns: 1fr; /* single column now that preferences are removed */
  gap: 40px;
  max-width: 1200px;
  margin: 0 auto;
}

.notifications-controls {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  flex-wrap: wrap;
  gap: 16px;
  /* Given the same vanilla surface as the list below so the two read as one
     block rather than loose chips floating on the page ground. */
  background: #fff4ef;
  border: 1px solid rgba(23, 72, 113, 0.14);
  border-radius: 12px;
  padding: 14px 16px;
}

.filter-tabs {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.filter-tab {
  background: #fff;
  border: 1px solid #e7d8d1;
  color: #55677c;
  padding: 8px 16px;
  border-radius: 20px;
  cursor: pointer;
  transition: all 0.3s ease;
  font-size: 14px;
}

.filter-tab:hover {
  background: rgba(160, 95, 128, 0.10);
  border-color: rgba(160, 95, 128, 0.45);
  color: #8c4f6e;
}

.filter-tab.active {
  background: rgba(160, 95, 128, 0.16);
  border-color: #a05f80;
  color: #8c4f6e;
  font-weight: 600;
}

.bulk-actions {
  display: flex;
  gap: 12px;
}

.btn-secondary {
  background: #fff;
  border: 1px solid #e7d8d1;
  color: #17324a;
  padding: 8px 16px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.btn-secondary:hover {
  background: rgba(160, 95, 128, 0.10);
  border-color: rgba(160, 95, 128, 0.45);
  color: #8c4f6e;
  transform: translateY(-1px);
}

.notifications-list {
  background: #fff4ef;
  border: 1px solid rgba(23, 72, 113, 0.14);
  border-radius: 12px;
  overflow: hidden;
}

.notification-item {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 20px;
  border-bottom: 1px solid rgba(23, 72, 113, 0.10);
  transition: all 0.3s ease;
  cursor: pointer;
  position: relative;
}

.notification-item:hover {
  background: rgba(23, 72, 113, 0.04);
}

.notification-item:last-child {
  border-bottom: none;
}

.notification-item.unread {
  background: rgba(160, 95, 128, 0.09);
  border-left: 4px solid #a05f80;
}

/* Visual feedback when an item turns read */
.notification-item.read-transition {
  transition: background-color 260ms ease, border-left-color 260ms ease;
  background: rgba(255, 255, 255, 0.0);
  /* falls back to the card's own vanilla once the tint clears */
  border-left-color: transparent;
}

/* Cyan dot indicator for unread */
.notification-item.unread::before {
  content: '';
  position: absolute;
  left: 12px;
  top: 26px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #a05f80;
}

.notification-icon {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}

.notification-icon.order_status {
  background: rgba(23, 72, 113, 0.14);
  color: #174871;
}

.notification-icon.payment {
  background: rgba(21, 128, 61, 0.14);
  color: #15803d;
}

.notification-icon.promotional {
  background: rgba(180, 122, 8, 0.14);
  color: #b47a08;
}

.notification-icon.security {
  background: rgba(185, 28, 28, 0.14);
  color: #b91c1c;
}

.notification-icon.system {
  background: rgba(160, 95, 128, 0.16);
  color: #8c4f6e;
}

.notification-content {
  flex: 1;
}

.notification-title {
  color: #17324a;
  font-weight: 600;
  font-size: 16px;
  margin-bottom: 4px;
}

.notification-message {
  color: #55677c;
  font-size: 14px;
  line-height: 1.5;
  margin-bottom: 8px;
}

.notification-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12px;
  color: #6b7c8f;
}

.notification-time {
  display: flex;
  align-items: center;
  gap: 4px;
}

.notification-actions {
  display: flex;
  gap: 8px;
  opacity: 0;
  transition: opacity 0.3s ease;
}

.notification-item:hover .notification-actions {
  opacity: 1;
}

.notification-action {
  background: none;
  border: none;
  color: #6b7c8f;
  cursor: pointer;
  padding: 4px;
  border-radius: 4px;
  transition: all 0.3s ease;
}

.notification-action:hover {
  background: rgba(23, 72, 113, 0.10);
  color: #17324a;
}

.loading-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-muted);
}

.loading-state i {
  font-size: 24px;
  margin-bottom: 12px;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 8px;
  margin-top: 24px;
}

.pagination button {
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--border-color);
  color: var(--text-muted);
  padding: 8px 12px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.pagination button:hover:not(:disabled) {
  background: var(--accent-cyan-bg);
  border-color: var(--accent-cyan);
  color: var(--accent-cyan);
}

.pagination button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.pagination .active {
  background: rgba(167, 118, 147, 0.15);
  border-color: var(--accent-cyan);
  color: var(--accent-cyan);
}

/* preferences panel removed */

.preferences-card h3 {
  color: var(--text-primary);
  font-size: 18px;
  margin-bottom: 24px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.preference-group {
  margin-bottom: 24px;
}

.preference-group h4 {
  color: var(--text-primary);
  font-size: 16px;
  margin-bottom: 12px;
}

.preference-item {
  margin-bottom: 12px;
}

.preference-item label {
  display: flex;
  align-items: center;
  gap: 12px;
  cursor: pointer;
  color: var(--text-muted);
  font-size: 14px;
}

.preference-item input[type="checkbox"] {
  display: none;
}

.checkmark {
  width: 20px;
  height: 20px;
  border: 2px solid rgba(255, 255, 255, 0.2);
  border-radius: 4px;
  position: relative;
  transition: all 0.3s ease;
}

.preference-item input[type="checkbox"]:checked + .checkmark {
  background: var(--accent-cyan);
  border-color: var(--accent-cyan);
}

.preference-item input[type="checkbox"]:checked + .checkmark::after {
  content: '✓';
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: #000;
  font-weight: bold;
  font-size: 12px;
}

.btn-primary {
  background: linear-gradient(45deg, var(--accent-cyan), var(--accent-blue));
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 12px 20px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  justify-content: center;
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(23, 72, 113, 0.3);
}

@media (max-width: 768px) {
  .notifications-layout {
    grid-template-columns: 1fr;
    gap: 24px;
  }

  .notifications-controls {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-tabs {
    justify-content: center;
  }
}
</style>

<!-- The redesign, linked LAST: after style.css, customer-dark.css and the
     uicons sheets that includes/header.php pulls in, and after this page's own
     <style> block above - so it wins on source order wherever specificity
     ties. Scoped to `body.nfx`; dropping that class from <body> reverts the
     page exactly. Presentation only; see the file header. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/assets/css/notifications-skin.css') ?>">

<script>
let currentPage = 1;
let currentFilter = 'all';
let isLoading = false;

// Load notifications
function loadNotifications(page = 1, filter = 'all') {
  if (isLoading) return;

  isLoading = true;
  currentPage = page;
  currentFilter = filter;

  const list = document.getElementById('notificationsList');
  list.innerHTML = '<div class="loading-state"><i class="fi fi-rr-spinner"></i><p>Loading notifications...</p></div>';

  const params = new URLSearchParams({
    action: 'list',
    page: page,
    limit: 20,
    audience: 'customer'
  });

  if (filter !== 'all') {
    params.append('type', filter);
  }

  fetch(`<?= WEB_ROOT ?>public/authenticated/api/notifications.php?${params.toString()}&audience=customer`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      renderNotifications(d.notifications);
      renderPagination(d.pagination);
      // Keep the chip totals and the unread eyebrow honest after a delete,
      // a mark-read or a mark-all - each of those already re-enters here.
      nfLoadChipCounts();
      nfLoadUnreadEyebrow();
    } else {
      list.innerHTML = '<div class="loading-state"><p>Failed to load notifications</p></div>';
    }
  })
  .catch(err => {
    console.error('Load notifications error:', err);
    list.innerHTML = '<div class="loading-state"><p>Error loading notifications</p></div>';
  })
  .finally(() => {
    isLoading = false;
  });
}

// Render notifications
function renderNotifications(notifications) {
  const list = document.getElementById('notificationsList');

  if (notifications.length === 0) {
    list.innerHTML = '<div class="loading-state"><p>No notifications found</p></div>';
    return;
  }

  // Rows are grouped by calendar day and each day is printed once as a
  // divider. The API's ordering is untouched - this only walks the array it
  // already returned, in the order it returned it, and starts a new group
  // whenever the day changes.
  let html = '';
  let openGroup = null;

  notifications.forEach(notif => {
    const day = nfDayKey(notif.created_at);
    if (day !== openGroup) {
      if (openGroup !== null) { html += '</div>'; }
      html += `<div class="nf-daygroup"><div class="nf-daylabel">${nfDayLabel(notif.created_at)}</div>`;
      openGroup = day;
    }
    html += nfRow(notif);
  });
  if (openGroup !== null) { html += '</div>'; }

  list.innerHTML = html;
}

// One row. Every handler, id and data value below is exactly what this
// template emitted before; the additions are the type class, the glyph, the
// unread dot, the category and the action link.
function nfRow(notif) {
  const data = (() => { try { return typeof notif.data === 'string' ? JSON.parse(notif.data || '{}') : (notif.data || {}); } catch(e){ return {}; } })();
  const link = getNotificationLink(notif, data);
  const onClick = link
    ? `onclick=\"openNotificationFromList(${notif.id}, '${encodeURIComponent(link)}', this, ${notif.is_read ? 'false' : 'true'})\"`
    : `onclick=\"markAsReadFromList(${notif.id}, this, ${notif.is_read ? 'false' : 'true'})\"`;
  const kind = nfKind(notif, data);
  return `
    <div class="notification-item ${notif.is_read ? '' : 'unread'} ${kind.cls}" ${onClick}>
      <div class="notification-icon ${notif.type}">
        <i class="fi fi-rr-${getNotificationIcon(notif.type)}"></i>
        <span class="nf-glyph" aria-hidden="true">${kind.glyph}</span>
      </div>
      <div class="notification-content">
        <div class="notification-title">${notif.title}<span class="nf-unread-dot" aria-hidden="true"></span>${notif.priority === 'high' || notif.priority === 'urgent' ? `<span class="priority-${notif.priority}">${notif.priority.toUpperCase()}</span>` : ''}</div>
        <div class="notification-message">${notif.message}</div>
        ${nfEtaChip(notif)}
        <div class="notification-meta">
          <div class="notification-time">
            <i class="fi fi-rr-clock"></i>
            ${nfStamp(notif.created_at)}
          </div>
          <span class="nf-cat">${kind.cat}</span>
          ${link ? `<a class="nf-action-link" href="${link}" onclick="event.stopPropagation()">${kind.action} &rarr;</a>` : ''}
        </div>
      </div>
      <div class="notification-actions">
        <button class="notification-action nf-act-read" onclick="event.stopPropagation(); markAsReadFromList(${notif.id}, this.closest('.notification-item'), ${notif.is_read ? 'false' : 'true'})" title="Mark as read" aria-label="Mark as read">
          <i class="fi fi-rr-check"></i>
        </button>
        <button class="notification-action nf-act-del" onclick="event.stopPropagation(); deleteNotification(${notif.id})" title="Delete" aria-label="Delete">
          <i class="fi fi-rr-trash"></i>
        </button>
      </div>
    </div>
  `;
}

/* The arrival estimate, attached live by api/notifications.php for any
   order_status row whose order can still be estimated (see
   notificationsAttachEta there). It is deliberately a separate line from the
   message rather than appended to it: the message is frozen at send time,
   this tightens as the order moves, and an order placed before the estimate
   existed has no eta in its stored text at all.

   Absent rather than empty when there is nothing to say, so one test covers
   "not an order", "cancelled" and "already delivered". */
function nfEtaChip(notif) {
  if (!notif || !notif.eta) { return ''; }
  const label = notif.eta_kind === 'pickup' ? 'Ready for pickup' : 'Est. arrival';
  const icon = notif.eta_kind === 'pickup' ? 'shop' : 'shipping-fast';
  return `<div class="nf-eta"><i class="fi fi-rr-${icon}" aria-hidden="true"></i>`
       + `<span class="nf-eta-label">${nfEsc(label)}</span>`
       + `<span class="nf-eta-value">${nfEsc(notif.eta)}</span></div>`;
}

/* The estimate is server-computed, but it still passes through innerHTML on
   the way in, so it is escaped like any other value rather than trusted
   because of where it came from. */
function nfEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[c]);
}

// Visual kind: accent class, glyph, category label and action-link wording.
// Purely presentational - it reads the notification, it never changes it.
function nfKind(notif, data) {
  const t = notif.type || '';
  const title = (notif.title || '').toLowerCase();

  // An explicit invoice URL is the reliable marker for the "Invoice Ready" /
  // "Payment Received" notifications, which carry type 'success'.
  if (data.invoice_url || t === 'payment') {
    return { cls: 'nf-t-payment', glyph: '₱', cat: 'Payments', action: 'View invoice' };
  }
  // There is no `cancelled` type in the notifications table - cancellations
  // arrive as order_status/warning with it stated in the title, so the title
  // is what has to be read here.
  if (title.indexOf('cancel') !== -1) {
    return { cls: 'nf-t-cancelled', glyph: '✕', cat: 'Orders', action: 'Reorder' };
  }
  if (t === 'promotional') {
    return { cls: 'nf-t-promo', glyph: '★', cat: 'Promotions', action: 'See offer' };
  }
  if (t === 'security') {
    return { cls: 'nf-t-security', glyph: '⚠', cat: 'Security', action: 'Review' };
  }
  /* A support reply is not an order, and labelling it "Orders / View order"
     sent the customer looking in the wrong place. Same blue-grey accent as
     System - this is a conversation, not something that needs action. */
  if (data.ticket_number || data.ticket_id || nfTicketFromTitle(notif.title)) {
    return { cls: 'nf-t-system', glyph: '✉', cat: 'Support', action: 'Open conversation' };
  }
  if (t === 'system') {
    return { cls: 'nf-t-system', glyph: '⚙', cat: 'System', action: 'Open' };
  }
  return { cls: 'nf-t-order', glyph: '▢', cat: 'Orders', action: 'View order' };
}

// Day bucketing for the dividers.
function nfDayKey(dateString) {
  const d = new Date(dateString);
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}
function nfDayLabel(dateString) {
  const d = new Date(dateString);
  const today = new Date();
  const yest = new Date();
  yest.setDate(today.getDate() - 1);
  if (nfDayKey(dateString) === nfDayKey(today)) { return 'Today'; }
  if (nfDayKey(dateString) === nfDayKey(yest)) { return 'Yesterday'; }
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

// Date AND time - many notifications land on the same day, so the date alone
// could not order them by eye. formatTime() above is left untouched.
function nfStamp(dateString) {
  const d = new Date(dateString);
  return `${d.toLocaleDateString()} &middot; ${d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`;
}

// Get notification icon based on type
function getNotificationIcon(type) {
  const icons = {
    'order_status': 'shopping-bag',
    'payment': 'credit-card',
    'promotional': 'gift',
    'security': 'shield-check',
    'system': 'settings'
  };
  return icons[type] || 'bell';
}

// Determine destination link for a notification
function getNotificationLink(notif, data) {
  try {
    // Explicit URL wins regardless of type - e.g. "Invoice Ready"/"Payment
    // Received" notifications (Delivery_Report.php) carry a direct
    // invoice_url, and were previously unclickable since their type
    // ('success') wasn't in the type whitelist below at all.
    /* An explicit destination set by whoever created the notification beats
       every guess below it. The live-tracking alert carries both an order_id
       and a url: without this it would match the order_status branch and land
       on the order summary instead of the map it is telling them to watch. */
    if (data.url) {
      return data.url;
    }
    if (data.invoice_url) {
      return data.invoice_url;
    }
    if (notif.type === 'order_status' || notif.type === 'payment' || notif.type === 'warning' || notif.type === 'info' || notif.type === 'success') {
      const orderId = data.order_id || data.id || null;
      if (orderId) {
        return `${'<?= WEB_ROOT ?>'}public/authenticated/customer/order_success.php?id=${encodeURIComponent(orderId)}`;
      }
      if (data.tracking_number || data.tracking_code) {
        const t = data.tracking_number || data.tracking_code;
        return `${'<?= WEB_ROOT ?>'}public/authenticated/customer/track_order.php?tracking=${encodeURIComponent(t)}`;
      }
    }
    if (notif.type === 'promotional' && data.product_id) {
      return `${'<?= WEB_ROOT ?>'}public/guest/product.php?id=${encodeURIComponent(data.product_id)}`;
    }
    /* A support reply. New ones carry ticket_number in their payload (see
       api/support.php). Older rows carry no payload at all - they predate it -
       so the ticket number is read out of the title they all share, "Reply to
       ticket TW-20261009-00015", rather than leaving every reply already in
       the bell unclickable. support.php accepts either the number or the id. */
    const ticketRef = data.ticket_number || data.ticket_id || nfTicketFromTitle(notif.title);
    if (ticketRef) {
      return `${'<?= WEB_ROOT ?>'}public/authenticated/customer/support.php?ticket=${encodeURIComponent(ticketRef)}`;
    }
  } catch(e) { /* ignore */ }
  return '';
}

// "Reply to ticket TW-20261009-00015" -> "TW-20261009-00015".
function nfTicketFromTitle(title) {
  const m = /\bTW-\d{8}-\d+\b/i.exec(title || '');
  return m ? m[0] : '';
}

// Optimistic handlers for list clicks (immediate UI + badge)
function markAsReadFromList(notificationId, el, wasUnread = true) {
  if (wasUnread && el) {
    el.classList.add('read-transition');
    el.classList.remove('unread');
    if (typeof decrementBadgeImmediate === 'function') { decrementBadgeImmediate(); }
  }
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=mark_read&audience=customer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'mark_read', notification_id: notificationId })
  })
  .then(r => r.json())
  .then(d => {
    if (d && d.success) {
      if (typeof updateNotificationBadge === 'function') { updateNotificationBadge(); }
    }
  });
}

function openNotificationFromList(id, encodedUrl, el, wasUnread = true) {
  const url = decodeURIComponent(encodedUrl);
  if (wasUnread && el) {
    el.classList.add('read-transition');
    el.classList.remove('unread');
    if (typeof decrementBadgeImmediate === 'function') { decrementBadgeImmediate(); }
  }
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=mark_read&audience=customer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'mark_read', notification_id: id })
  })
  .then(() => { if (typeof updateNotificationBadge === 'function') { updateNotificationBadge(); } })
  .finally(() => { window.location.href = url; });
}

// Format time
function formatTime(dateString) {
  const date = new Date(dateString);
  const now = new Date();
  const diff = now - date;

  if (diff < 60000) return 'Just now';
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
  return date.toLocaleDateString();
}

// Render pagination
function renderPagination(pagination) {
  const container = document.getElementById('pagination');

  // "SHOWING 1-10 OF 38", from the real offset and total the API returned.
  // Printed even when there is only one page, which is when knowing the total
  // is most useful.
  const showing = document.getElementById('nfShowing');
  if (showing) {
    const total = pagination.total || 0;
    if (total > 0) {
      const from = (pagination.page - 1) * pagination.limit + 1;
      const to = Math.min(pagination.page * pagination.limit, total);
      showing.textContent = `Showing ${from}–${to} of ${total}`;
    } else {
      showing.textContent = '';
    }
  }

  if (pagination.pages <= 1) {
    container.innerHTML = '';
    return;
  }

  let html = '';

  // Previous button
  html += `<button ${pagination.page === 1 ? 'disabled' : ''} onclick="loadNotifications(${pagination.page - 1}, '${currentFilter}')">
    <i class="fi fi-rr-arrow-left"></i>
  </button>`;

  // Page numbers
  for (let i = 1; i <= pagination.pages; i++) {
    if (i === pagination.page) {
      html += `<button class="active">${i}</button>`;
    } else {
      html += `<button onclick="loadNotifications(${i}, '${currentFilter}')">${i}</button>`;
    }
  }

  // Next button
  html += `<button ${pagination.page === pagination.pages ? 'disabled' : ''} onclick="loadNotifications(${pagination.page + 1}, '${currentFilter}')">
    <i class="fi fi-rr-arrow-right"></i>
  </button>`;

  container.innerHTML = html;
}

// Mark notification as read
function markAsRead(notificationId) {
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=mark_read&audience=customer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'mark_read', notification_id: notificationId })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      loadNotifications(currentPage, currentFilter);
    }
  });
}

// Mark all as read
function markAllAsRead() {
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=mark_all_read&audience=customer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'mark_all_read' })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      // local visual feedback
      const btn = document.querySelector('.notifications-controls .btn-secondary');
      const original = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fi fi-rr-check"></i> All marked as read';
      }
      // Transition existing list to read state smoothly
      document.querySelectorAll('#notificationsList .notification-item.unread').forEach(el => {
        el.classList.add('read-transition');
        el.classList.remove('unread');
      });
      // Also raise toast if available
      if (typeof showNotification === 'function') {
        showNotification(d.message || 'Marked all as read', 'success');
      }
      // Reload after the animation to reconcile pagination/status
      setTimeout(() => {
        loadNotifications(currentPage, currentFilter);
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
        // Ask header to reconcile bell badge if the component exists on page
        if (typeof updateNotificationBadge === 'function') { updateNotificationBadge(); }
      }, 280);
    }
  });
}

// Delete notification
async function deleteNotification(notificationId) {
  if (!(await uiConfirm('Delete this notification?'))) return;

  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=delete&audience=customer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'delete', notification_id: notificationId })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      loadNotifications(currentPage, currentFilter);
    }
  });
}

// --- Redesign: chip counts and the unread eyebrow -------------------------
// Both read the EXISTING list endpoint with its existing parameters and take
// pagination.total from the response - the same trick the sidebar badge uses
// (includes/sidebar.php, updateNotificationBadge). No new route, no new query,
// no PHP change. limit=1 keeps each response to a single row.
function nfCountFor(params) {
  const qs = new URLSearchParams(Object.assign({
    action: 'list', page: 1, limit: 1, audience: 'customer'
  }, params));
  return fetch(`<?= WEB_ROOT ?>public/authenticated/api/notifications.php?${qs.toString()}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(r => r.json())
    .then(d => (d && d.success && d.pagination) ? (d.pagination.total || 0) : null)
    .catch(() => null);
}

function nfLoadChipCounts() {
  document.querySelectorAll('.filter-tab').forEach(tab => {
    const filter = tab.dataset.filter;
    nfCountFor(filter === 'all' ? {} : { type: filter }).then(n => {
      const slot = tab.querySelector('.nf-chip-count');
      // A failed count leaves the chip reading as it did before - a wrong
      // number would be worse than none.
      if (slot && n !== null) { slot.textContent = n; }
    });
  });
}

function nfLoadUnreadEyebrow() {
  const el = document.getElementById('nfEyebrow');
  if (!el) return;
  nfCountFor({ unread_only: 'true' }).then(n => {
    if (n === null) return;
    el.textContent = n > 0 ? `${n} unread · Customer account` : 'All caught up · Customer account';
  });
}

// Filter tabs
document.addEventListener('DOMContentLoaded', function() {
  // Load initial notifications
  loadNotifications();
  nfLoadChipCounts();
  nfLoadUnreadEyebrow();

  // Filter tabs
  document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function() {
      document.querySelectorAll('.filter-tab').forEach(t => {
        t.classList.remove('active');
        t.setAttribute('aria-pressed', 'false');
      });
      this.classList.add('active');
      this.setAttribute('aria-pressed', 'true');
      loadNotifications(1, this.dataset.filter);
    });
  });

  // preferences removed
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>