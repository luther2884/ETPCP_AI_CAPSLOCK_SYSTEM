<?php
// shared/includes/notification_bell.php - Real-time notification bell component
if (!function_exists('getUserNotificationCount')) {
    function getUserNotificationCount($userId, $unreadOnly = false) {
        return 0; // Fallback if function not available
    }
}

$notificationCount = 0;
if (isLoggedIn()) {
    $notificationCount = getUserNotificationCount(getUserId(), true);
}

// Always point to customer notifications page
$notificationsUrl = WEB_ROOT . 'public/authenticated/customer/notifications.php';
?>

<!-- Notification Bell -->
<div class="notification-bell" id="notificationBell">
  <button class="bell-button" onclick="toggleNotificationDropdown()">
    <i class="fi fi-rr-bell"></i>
    <?php if ($notificationCount > 0): ?>
      <span class="notification-badge" id="notificationBadge"><?= $notificationCount ?></span>
    <?php endif; ?>
  </button>
  
  <div class="notification-dropdown" id="notificationDropdown">
    <div class="dropdown-header">
      <h4>Notifications</h4>
      <button class="mark-all-read" onclick="markAllNotificationsAsRead()">
        <i class="fi fi-rr-check"></i>
        Mark all as read
      </button>
    </div>
    
    <div class="dropdown-content" id="notificationDropdownContent">
      <div class="loading-notifications">
        <i class="fi fi-rr-spinner"></i>
        <p>Loading notifications...</p>
      </div>
    </div>
    
    <div class="dropdown-footer">
      <a href="<?= $notificationsUrl ?>" class="view-all-link">
        <i class="fi fi-rr-arrow-right"></i>
        View all notifications
      </a>
    </div>
  </div>
</div>

<style>
.notification-bell {
  position: relative;
  display: inline-block;
}

.bell-button {
  /* Match the Cart icon's .action-btn exactly - same size, shape and
     margin so the two header icons sit at the same visual level. */
  background: rgba(255, 255, 255, 0.1);
  border: none;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  padding: 8px;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  position: relative;
}

.bell-button:hover {
  background: rgba(139, 76, 108, 0.18);
  transform: scale(1.1);
}

/* Match cart count style. Solid brand red - single consistent "live/alert"
   accent for badges, matching the search icon, instead of the old
   cyan->blue gradient mixed inconsistently across the header. */
.notification-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  background: #E60026;
  color: #ffffff;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: bold;
  border: 2px solid rgba(16, 24, 39, 0.9);
  box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
  z-index: 1;
}

/* Smooth scale-out when dismissed */
.notification-badge.hide-out {
  animation: badgeHide 220ms ease forwards;
}

@keyframes badgeHide {
  0% { transform: scale(1); opacity: 1; }
  100% { transform: scale(0); opacity: 0; }
}

/* No pulse to mimic cart badge; remove if previously applied */

/* Neumorphic raised card - same surface + shadow system as the rest of the
   site (.filters-sidebar, .avatar-dropdown) instead of a dark glass panel,
   so the popup reads as "part of" the page it pops out of. */
.notification-dropdown {
  position: absolute;
  top: 100%;
  right: 0;
  width: 350px;
  background: var(--bg-card);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-lg, 16px);
  box-shadow: var(--neu-raised);
  z-index: 1000;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition: all 0.3s ease;
}

.notification-dropdown.show {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

.dropdown-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-color);
}

.dropdown-header h4 {
  color: var(--text-primary);
  font-size: 16px;
  margin: 0;
}

.mark-all-read {
  background: none;
  border: none;
  color: #8B4C6C;
  font-size: 12px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 4px;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 4px;
}

.mark-all-read:hover {
  background: rgba(139, 76, 108, 0.1);
}

.dropdown-content {
  max-height: 400px;
  overflow-y: auto;
}

.notification-item-dropdown {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 20px;
  border-bottom: 1px solid var(--border-color);
  transition: all 0.3s ease;
  cursor: pointer;
  position: relative;
}

.notification-item-dropdown:hover {
  background: rgba(139, 76, 108, 0.06);
}

.notification-item-dropdown.unread {
  background: rgba(139, 76, 108, 0.05);
  border-left: 3px solid #8B4C6C;
}

/* Mauve dot indicator for unread */
.notification-item-dropdown.unread::before {
  content: '';
  position: absolute;
  left: 8px;
  top: 18px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #8B4C6C;
}

/* Smooth read transition */
.notification-item-dropdown.read-transition {
  transition: background-color 240ms ease, border-left-color 240ms ease;
  background: rgba(255,255,255,0);
  border-left-color: transparent;
}

/* Visual feedback when an item turns read */
.notification-item-dropdown.read-transition {
  transition: background-color 260ms ease, border-left-color 260ms ease;
  background: rgba(255, 255, 255, 0.0);
  border-left-color: transparent;
}

.notification-icon-small {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}

.notification-icon-small.order_status {
  background: rgba(23, 72, 113, 0.2);
  color: #5b9bd5;
}

.notification-icon-small.payment {
  background: rgba(16, 185, 129, 0.2);
  color: #10b981;
}

.notification-icon-small.promotional {
  background: rgba(245, 158, 11, 0.2);
  color: #f59e0b;
}

.notification-icon-small.security {
  background: rgba(239, 68, 68, 0.2);
  color: #ef4444;
}

.notification-icon-small.system {
  background: rgba(139, 76, 108, 0.18);
  color: #8B4C6C;
}

.notification-content-small {
  flex: 1;
}

.notification-title-small {
  color: var(--text-primary);
  font-weight: 600;
  font-size: 14px;
  margin-bottom: 2px;
  line-height: 1.3;
}

.notification-message-small {
  color: var(--text-muted);
  font-size: 12px;
  line-height: 1.4;
  margin-bottom: 4px;
}

.notification-time-small {
  color: var(--text-muted);
  font-size: 11px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.loading-notifications {
  text-align: center;
  padding: 40px 20px;
  color: var(--text-muted);
}

.loading-notifications i {
  font-size: 20px;
  margin-bottom: 8px;
  animation: spin 1s linear infinite;
}

.dropdown-footer {
  padding: 12px 20px;
  border-top: 1px solid var(--border-color);
}

.view-all-link {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #8B4C6C;
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
  padding: 8px 16px;
  border-radius: 6px;
  transition: all 0.3s ease;
}

.view-all-link:hover {
  background: rgba(139, 76, 108, 0.1);
}

.empty-notifications {
  text-align: center;
  padding: 40px 20px;
  color: var(--text-muted);
}

.empty-notifications i {
  font-size: 32px;
  margin-bottom: 12px;
  color: var(--text-muted);
}

@media (max-width: 768px) {
  .notification-dropdown {
    width: 300px;
    right: -50px;
  }
}
</style>

<script>
let notificationDropdownOpen = false;

// Toggle notification dropdown
function toggleNotificationDropdown() {
  const dropdown = document.getElementById('notificationDropdown');
  const bell = document.getElementById('notificationBell');
  
  if (notificationDropdownOpen) {
    dropdown.classList.remove('show');
    notificationDropdownOpen = false;
  } else {
    dropdown.classList.add('show');
    notificationDropdownOpen = true;
    loadNotificationDropdown();
  }
}

// Load notifications in dropdown
function loadNotificationDropdown() {
  const content = document.getElementById('notificationDropdownContent');
  content.innerHTML = '<div class="loading-notifications"><i class="fi fi-rr-spinner"></i><p>Loading notifications...</p></div>';
  
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=list&limit=5&audience=customer', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(d => {
    if (d.success && d.notifications.length > 0) {
      content.innerHTML = d.notifications.map(notif => {
        const data = (() => { try { return typeof notif.data === 'string' ? JSON.parse(notif.data || '{}') : (notif.data || {}); } catch(e){ return {}; } })();
        const link = getNotificationLink(notif, data);
        const onClick = link
          ? `onclick=\"openNotificationQuick(${notif.id}, '${encodeURIComponent(link)}', this, ${notif.is_read ? 'false' : 'true'})\"`
          : `onclick=\"markNotificationAsRead(${notif.id}, this, ${notif.is_read ? 'false' : 'true'})\"`;
        return `
        <div class="notification-item-dropdown ${notif.is_read ? '' : 'unread'}" ${onClick}>
          <div class="notification-icon-small ${notif.type}">
            <i class="fi fi-rr-${getNotificationIcon(notif.type)}"></i>
          </div>
          <div class="notification-content-small">
            <div class="notification-title-small">${notif.title}</div>
            <div class="notification-message-small">${notif.message}</div>
            <div class="notification-time-small">
              <i class="fi fi-rr-clock"></i>
              ${formatTime(notif.created_at)}
            </div>
          </div>
        </div>`;
      }).join('');
    } else {
      content.innerHTML = '<div class="empty-notifications"><i class="fi fi-rr-bell"></i><p>No notifications</p></div>';
      // Immediately reconcile the badge if the list is empty
      updateNotificationBadge();
    }
  })
  .catch(err => {
    console.error('Load notification dropdown error:', err);
    content.innerHTML = '<div class="empty-notifications"><p>Error loading notifications</p></div>';
  });
}

// Mark notification as read
function markNotificationAsRead(notificationId, el, wasUnread = true) {
  if (wasUnread && el) {
    el.classList.add('read-transition');
    el.classList.remove('unread');
    decrementBadgeImmediate();
  }
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=mark_read&audience=customer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'mark_read', notification_id: notificationId })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      loadNotificationDropdown();
      updateNotificationBadge();
    }
  });
}

// Mark all notifications as read
function markAllNotificationsAsRead() {
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=mark_all_read&audience=customer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'mark_all_read' })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      // Button feedback
      const btn = document.querySelector('#notificationDropdown .mark-all-read');
      const original = btn ? btn.innerHTML : '';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fi fi-rr-check"></i> All marked as read';
      }

      // Smoothly flip unread items to read state
      const items = document.querySelectorAll('#notificationDropdown .notification-item-dropdown.unread');
      items.forEach(el => {
        el.classList.add('read-transition');
        el.classList.remove('unread');
      });

      // Animate badge hide then remove
      const badge = document.getElementById('notificationBadge');
      if (badge) {
        badge.classList.add('hide-out');
        setTimeout(() => badge.remove(), 240);
      }

      // Refresh list and badge after animation completes
      setTimeout(() => {
        loadNotificationDropdown();
        updateNotificationBadge();
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = original;
        }
      }, 280);
    }
  });
}

// Update notification badge
function updateNotificationBadge() {
  fetch('<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=count&audience=customer', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      const badge = document.getElementById('notificationBadge');
      if (d.counts.unread > 0) {
        if (!badge) {
          const bellButton = document.querySelector('.bell-button');
          bellButton.innerHTML += '<span class="notification-badge" id="notificationBadge">' + d.counts.unread + '</span>';
        } else {
          badge.textContent = d.counts.unread;
        }
      } else {
        if (badge) {
          badge.remove();
        }
      }
    }
  });
}

// Get notification icon
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

// Reuse linking logic like on notifications page
function getNotificationLink(notif, data) {
  try {
    // Explicit URL wins regardless of type - e.g. "Invoice Ready"/"Payment
    // Received" notifications (Delivery_Report.php) carry a direct
    // invoice_url, and were previously unclickable since their type
    // ('success') wasn't in the type whitelist below at all.
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
  } catch(e) { /* ignore */ }
  return '';
}

function openNotificationQuick(id, encodedUrl, el, wasUnread = true) {
  const url = decodeURIComponent(encodedUrl);
  if (wasUnread && el) {
    el.classList.add('read-transition');
    el.classList.remove('unread');
    decrementBadgeImmediate();
  }
  // Ensure backend marks read before navigation to avoid stale badge on next page.
  // Prefer sendBeacon (reliable during navigation), fallback to fetch with keepalive.
  (function markReadReliable(){
    const endpoint = '<?= WEB_ROOT ?>public/authenticated/api/notifications.php?action=mark_read&audience=customer';
    const payload = JSON.stringify({ notification_id: id });
    let sent = false;
    try {
      if (navigator && typeof navigator.sendBeacon === 'function') {
        const blob = new Blob([payload], { type: 'application/json' });
        sent = navigator.sendBeacon(endpoint, blob);
      }
    } catch(_){}
    if (!sent) {
      try {
        fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ action: 'mark_read', notification_id: id }),
          keepalive: true
        }).catch(()=>{});
      } catch(_){}
    }
  })();
  try { if (typeof updateNotificationBadge === 'function') updateNotificationBadge(); } catch(_){}
  try { if (typeof loadNotificationDropdown === 'function') loadNotificationDropdown(); } catch(_){}
  setTimeout(() => { window.location.href = url; }, 220);
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

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  const bell = document.getElementById('notificationBell');
  const dropdown = document.getElementById('notificationDropdown');
  
  if (!bell.contains(e.target) && notificationDropdownOpen) {
    dropdown.classList.remove('show');
    notificationDropdownOpen = false;
  }
});

// Auto-refresh notification badge every 20 seconds for responsiveness
setInterval(updateNotificationBadge, 20000);
// Also sync badge once on initial page load to avoid stale server-side count
document.addEventListener('DOMContentLoaded', updateNotificationBadge);

// Optimistic badge decrement with animation; re-added after inline handler changes
function decrementBadgeImmediate(){
  const badge = document.getElementById('notificationBadge');
  if (!badge) return;
  const current = parseInt(badge.textContent || '0', 10) || 0;
  if (current <= 1) {
    badge.classList.add('hide-out');
    setTimeout(() => { if (badge && badge.parentNode) badge.remove(); }, 220);
  } else {
    badge.textContent = String(current - 1);
  }
}
</script>
