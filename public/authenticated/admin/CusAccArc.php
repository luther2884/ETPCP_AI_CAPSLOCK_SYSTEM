<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_role(['admin']);

$pageTitle = 'Customer Account Archive - TechWorld Admin';
$isAdminTheme = true;

// Pagination + search
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

try {
  $pdo = getConnection();
  try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL DEFAULT NULL, ADD INDEX IF NOT EXISTS idx_last_login (last_login)"); } catch (Throwable $e) { /* ignore */ }

  // Count inactive customers
  $where = "WHERE u.role = 'customer' AND u.status = 'inactive'";
  $params = [];
  if ($search !== '') {
    $where .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = [$searchTerm, $searchTerm, $searchTerm];
  }

  $countSql = "SELECT COUNT(*) FROM users u {$where}";
  $stCount = $pdo->prepare($countSql);
  $stCount->execute($params);
  $totalUsers = (int)$stCount->fetchColumn();
  $totalPages = (int)ceil($totalUsers / $limit);

  $sql = "
    SELECT u.*,
           COALESCE(u.last_login, u.created_at) AS last_seen
    FROM users u
    {$where}
    ORDER BY COALESCE(u.last_login, u.created_at) DESC
    LIMIT ? OFFSET ?
  ";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge($params, [$limit, $offset]));
  $users = $st->fetchAll();
} catch (Throwable $e) {
  error_log('Archive list error: ' . $e->getMessage());
  $users = [];
  $totalPages = 0;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<main class="container">
  <div class="admin-dashboard" style="background:transparent;color:inherit;margin:0 auto;padding:30px;border-radius:12px;max-width:1400px;border:1px solid rgba(148,163,184,0.12);">
    <div class="adm-head">
      <div>
        <nav class="adm-crumb" aria-label="Breadcrumb">
          <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='CusMan.php'; }">Customers</a>
          <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
          <span class="adm-crumb-cur" aria-current="page">Account Archive</span>
        </nav>
      </div>
    </div>
    <div class="welcome-message" style="text-align:center;margin-bottom:30px;padding:20px;background:linear-gradient(135deg,#6b7280 0%,#374151 100%);color:#fff;border-radius:10px;">
      <h1 style="margin-bottom:10px;">Customer Account Archive</h1>
      <p>Inactive customer accounts (auto-archived after 1 month of inactivity)</p>
    </div>

    <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h2 style="margin:0;">Archived Users (<?= $totalUsers ?>)</h2>
      <form method="GET" style="display:flex;gap:10px;align-items:center;">
        <input type="text" name="search" placeholder="Search users..." value="<?= htmlspecialchars($search) ?>" style="padding:8px;border-radius:6px;border:1px solid #ccc;">
        <button type="submit" style="background:#374151;color:#fff;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;">Search</button>
        <?php if ($search): ?>
          <a href="?" style="background:#6b7280;color:#fff;padding:8px 12px;border-radius:6px;text-decoration:none;">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($users)): ?>
      <div style="background:#0b1220;border:1px solid #1f2937;color:#e5e7eb;padding:40px;border-radius:8px;text-align:center;">
        <h3>No archived users</h3>
        <p>Inactive accounts will appear here automatically after 1 month of no logins.</p>
      </div>
    <?php else: ?>
      <div class="users-list" style="display:flex;flex-direction:column;gap:15px;">
        <?php foreach ($users as $user): ?>
          <div class="user-card" style="background:#0b1220;border:1px solid #1f2937;color:#e5e7eb;padding:20px;border-radius:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
              <div>
                <h3 style="margin:0;color:#e5e7eb;"><?= htmlspecialchars($user['firstname'] . ' ' . $user['lastname']) ?></h3>
                <p style="margin:5px 0 0 0;opacity:.8;"><?= htmlspecialchars($user['email']) ?></p>
              </div>
              <span class="status-badge status-inactive" style="padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;text-transform:uppercase;">inactive</span>
            </div>
            <div class="user-details" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
              <div><strong>User ID:</strong><br>#<?= $user['id'] ?></div>
              <div><strong>Joined:</strong><br><?= date('M j, Y', strtotime($user['created_at'])) ?></div>
              <div><strong>Last Login:</strong><br><?= $user['last_login'] ? date('M j, Y g:i a', strtotime($user['last_login'])) : '—' ?></div>
              <div><strong>Phone:</strong><br><?= htmlspecialchars($user['phone'] ?? 'N/A') ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="pagination" style="display:flex;justify-content:center;gap:10px;margin-top:30px;">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
               style="padding:8px 12px;background:<?= $i === $page ? '#374151' : '#1f2937' ?>;color:#fff;text-decoration:none;border-radius:4px;"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>


