<?php
// public/authenticated/super-admin/requests.php — Owner Employee Accounts
// (Manager + Delivery Personnel) & 10-Min Passcode Invitation Portal
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Require Owner / Super-Admin
require_role(['owner', 'super-admin']);

$pdo = getConnection();
$message = $_GET['message'] ?? '';
$messageType = $_GET['messageType'] ?? 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('check_csrf') && !check_csrf(false)) {
        header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/requests.php?message=Invalid+security+token.&messageType=error');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Action 1: Owner creates a new Employee account - either a Manager
    // (role='admin') or a Delivery Personnel account (role='driver'), picked
    // via the account_type radio in the form below. Same 10-minute passcode
    // invitation flow either way (manager_invitations + setup_password.php
    // are both already role-agnostic - this used to be two near-identical
    // pages, one per role; logistics_accounts.php has been folded in here).
    if ($action === 'create_employee') {
        $firstname       = trim($_POST['firstname'] ?? '');
        $lastname        = trim($_POST['lastname'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $accountType     = ($_POST['account_type'] ?? '') === 'driver' ? 'driver' : 'admin';
        $isDriverAccount = $accountType === 'driver';
        $roleLabel       = $isDriverAccount ? 'Delivery Personnel' : 'Manager';

        if ($firstname === '' || $lastname === '' || $email === '') {
            $msg = 'All fields are required to create an Employee account.';
            $type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid email address.';
            $type = 'error';
        } else {
            try {
                // Check if email already exists
                $st = $pdo->prepare("SELECT id, role, status FROM users WHERE email = ? LIMIT 1");
                $st->execute([$email]);
                $existing = $st->fetch();

                if ($existing) {
                    $msg = 'Email address "' . htmlspecialchars($email) . '" is already registered in the system.';
                    $type = 'error';
                } else {
                    $pdo->beginTransaction();

                    // Create employee user record with pending status and dummy hash
                    $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("INSERT INTO users (firstname, lastname, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
                    $ins->execute([$firstname, $lastname, $email, $dummyHash, $accountType]);
                    $userId = (int)$pdo->lastInsertId();

                    // Generate 64-char token expiring in 10 minutes
                    $token = bin2hex(random_bytes(32));
                    $inv = $pdo->prepare("INSERT INTO manager_invitations (user_id, token, expires_at, created_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE, NOW())");
                    $inv->execute([$userId, $token]);

                    $pdo->commit();

                    // Build 10-minute setup URL
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $setupUrl = $scheme . $host . rtrim(WEB_ROOT, '/') . '/public/guest/setup_password.php?token=' . $token;

                    // Branding differs slightly by account type - blue for
                    // Delivery Personnel (matches the Delivery Portal login
                    // page), pink/cyan for Manager (matches the rest of the
                    // Owner/Manager panels).
                    $accentColor = $isDriverAccount ? '#38bdf8' : '#a77693';
                    $gradient = $isDriverAccount ? 'linear-gradient(135deg,#38bdf8,#0369a1)' : 'linear-gradient(135deg,#a77693,#174871)';
                    $portalNote = $isDriverAccount ? " You'll use this account to sign in to the Delivery Portal and confirm deliveries." : '';

                    $subject = 'TechWorld - ' . $roleLabel . ' Account Passcode Setup (10-Minute Limit)';
                    $htmlBody = "
                        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#0f172a;color:#e2e8f0;border-radius:12px;'>
                            <h2 style='color:{$accentColor};margin-top:0;'>{$roleLabel} Account Invitation</h2>
                            <p>Hello <strong>" . htmlspecialchars($firstname . ' ' . $lastname) . "</strong>,</p>
                            <p>An Owner has created a {$roleLabel} account for you on <strong>TechWorld</strong>.{$portalNote}</p>
                            <p style='background:rgba(239,68,68,0.15);border:1px solid #ef4444;color:#f87171;padding:12px;border-radius:8px;font-size:14px;'>
                                ⚠️ <strong>Important:</strong> For security reasons, you have <strong>10 minutes</strong> from now to set your private passcode.
                            </p>
                            <p style='text-align:center;margin:30px 0;'>
                                <a href='" . htmlspecialchars($setupUrl) . "' style='background:{$gradient};color:#0b1220;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;'>Set Your Passcode Now →</a>
                            </p>
                            <p style='font-size:12px;opacity:.7;'>If the 10-minute window expires, please ask your System Owner to re-issue a setup link.</p>
                        </div>
                    ";

                    $emailSent = sendEmail($email, $firstname . ' ' . $lastname, $subject, $htmlBody);

                    $msg = $roleLabel . ' account created for ' . htmlspecialchars($email) . '! Invitation email sent (valid for 10 minutes).';
                    $type = 'success';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Error creating employee account: ' . $e->getMessage());
                $msg = 'Failed to create ' . $roleLabel . ' account: ' . $e->getMessage();
                $type = 'error';
            }
        }
    }
    // Action 2: Owner Resends 10-Min Setup Link
    elseif ($action === 'resend_invitation') {
        $userId = (int)($_POST['user_id'] ?? 0);
        try {
            $st = $pdo->prepare("SELECT id, firstname, lastname, email, role FROM users WHERE id = ? AND role IN ('manager','admin','driver') LIMIT 1");
            $st->execute([$userId]);
            $u = $st->fetch();

            if ($u) {
                $isDriverAccount = $u['role'] === 'driver';
                $roleLabel = $isDriverAccount ? 'Delivery Personnel' : 'Manager';
                $accentColor = $isDriverAccount ? '#38bdf8' : '#a77693';
                $gradient = $isDriverAccount ? 'linear-gradient(135deg,#38bdf8,#0369a1)' : 'linear-gradient(135deg,#a77693,#174871)';

                // Delete old invitations for this user
                $pdo->prepare("DELETE FROM manager_invitations WHERE user_id = ?")->execute([$userId]);

                // Generate new token expiring in 10 minutes
                $token = bin2hex(random_bytes(32));
                $inv = $pdo->prepare("INSERT INTO manager_invitations (user_id, token, expires_at, created_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE, NOW())");
                $inv->execute([$userId, $token]);

                // Build setup URL
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $setupUrl = $scheme . $host . rtrim(WEB_ROOT, '/') . '/public/guest/setup_password.php?token=' . $token;

                $subject = 'TechWorld - New ' . $roleLabel . ' Passcode Setup Link (10-Minute Limit)';
                $htmlBody = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#0f172a;color:#e2e8f0;border-radius:12px;'>
                        <h2 style='color:{$accentColor};margin-top:0;'>New Passcode Setup Link</h2>
                        <p>Hello <strong>" . htmlspecialchars(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) . "</strong>,</p>
                        <p>Your System Owner has generated a new passcode setup link for your {$roleLabel} account.</p>
                        <p style='background:rgba(239,68,68,0.15);border:1px solid #ef4444;color:#f87171;padding:12px;border-radius:8px;font-size:14px;'>
                            ⏱ This new setup link is valid for <strong>10 minutes</strong>.
                        </p>
                        <p style='text-align:center;margin:30px 0;'>
                            <a href='" . htmlspecialchars($setupUrl) . "' style='background:{$gradient};color:#0b1220;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;'>Set Your Passcode Now →</a>
                        </p>
                    </div>
                ";

                sendEmail($u['email'], $u['firstname'] . ' ' . $u['lastname'], $subject, $htmlBody);

                $msg = 'New 10-minute setup link sent to ' . htmlspecialchars($u['email']) . '!';
                $type = 'success';
            } else {
                $msg = 'Employee account not found.';
                $type = 'error';
            }
        } catch (Throwable $e) {
            error_log('Error resending invitation: ' . $e->getMessage());
            $msg = 'Could not resend setup link.';
            $type = 'error';
        }
    }
    // Action 3: Toggle Employee Status (Active / Inactive)
    elseif ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        try {
            $upd = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role IN ('manager','admin','driver')");
            $upd->execute([$newStatus, $userId]);
            $msg = 'Employee account status updated to ' . strtoupper($newStatus) . '.';
            $type = 'success';
        } catch (Throwable $e) {
            $msg = 'Failed to update status.';
            $type = 'error';
        }
    }

    header('Location: ' . WEB_ROOT . 'public/authenticated/super-admin/requests.php?' . http_build_query(['message' => $msg, 'messageType' => $type]));
    exit;
}

// Fetch list of Employees (Managers + Delivery Personnel)
$employees = [];
try {
    $st = $pdo->query("
        SELECT u.id, u.firstname, u.lastname, u.email, u.status, u.created_at, u.role,
               mi.expires_at, mi.token,
               (mi.expires_at IS NOT NULL AND mi.expires_at > NOW()) AS is_link_active
        FROM users u
        LEFT JOIN manager_invitations mi ON u.id = mi.user_id
        WHERE u.role IN ('manager','admin','driver')
        ORDER BY u.created_at DESC
    ");
    $employees = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Error fetching employee accounts: ' . $e->getMessage());
}

$pageTitle = 'Employee Accounts - Owner Panel';
$activeNav = 'requests';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<main class="container" style="padding:30px 0;">
  <?php if (!empty($message)): ?>
    <div class="notification-banner notification-<?= htmlspecialchars($messageType) ?>" style="margin-bottom:20px;">
      <div class="container">
        <span><?= htmlspecialchars($message) ?></span>
      </div>
    </div>
  <?php endif; ?>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:1100px;">

    <!-- Welcome Banner -->
    <div class="dashboard-welcome">
      <h2 style="margin:0 0 6px 0;">Employee Account Management</h2>
      <p>As System Owner, create Manager and Delivery Personnel accounts and issue 10-minute secure passcode setup links.</p>
    </div>

    <!-- Section 1: Create New Employee Account Form -->
    <div class="admin-card" style="padding:24px;margin-bottom:35px;">
      <h3 style="margin-top:0;font-size:18px;margin-bottom:15px;display:flex;align-items:center;gap:8px;">
        <i class="fi fi-bs-user-add" style="color:var(--accent-cyan);"></i> Create New Employee Account
      </h3>
      <p style="font-size:13px;margin-bottom:20px;">Choose the account type and enter the new employee's details below. An email containing a <strong>10-minute setup link</strong> will be automatically sent to them to set their account passcode.</p>

      <form method="POST" action="" style="display:flex;flex-direction:column;gap:18px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_employee">

        <div>
          <label class="admin-form-label">Account Type *</label>
          <div style="display:flex;gap:12px;margin-top:6px;">
            <label style="display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:var(--radius-sm);background:var(--admin-surface);box-shadow:var(--neu-raised-sm);cursor:pointer;font-weight:600;font-size:14px;">
              <input type="radio" name="account_type" value="manager" checked style="accent-color:var(--accent-cyan);">
              Manager Account
            </label>
            <label style="display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:var(--radius-sm);background:var(--admin-surface);box-shadow:var(--neu-raised-sm);cursor:pointer;font-weight:600;font-size:14px;">
              <input type="radio" name="account_type" value="driver" style="accent-color:var(--accent-cyan);">
              Delivery Personnel Account
            </label>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1.5fr auto;gap:15px;align-items:end;">
          <div>
            <label class="admin-form-label">First Name *</label>
            <input type="text" name="firstname" placeholder="First Name" required class="admin-form-input" style="width:100%;box-sizing:border-box;">
          </div>

          <div>
            <label class="admin-form-label">Last Name *</label>
            <input type="text" name="lastname" placeholder="Last Name" required class="admin-form-input" style="width:100%;box-sizing:border-box;">
          </div>

          <div>
            <label class="admin-form-label">Email Address *</label>
            <input type="email" name="email" placeholder="employee@example.com" required class="admin-form-input" style="width:100%;box-sizing:border-box;">
          </div>

          <button type="submit" class="action-btn admin-quick-link" style="font-weight:700;white-space:nowrap;border:none;cursor:pointer;">
            Create & Send 10-Min Link →
          </button>
        </div>
      </form>
    </div>

    <!-- Section 2: Employee Accounts List Table -->
    <h3 style="font-size:18px;margin-bottom:15px;display:flex;align-items:center;gap:8px;">
      <i class="fi fi-bs-list-check" style="color:var(--accent-cyan);"></i> Registered Employee Accounts (<?= count($employees) ?>)
    </h3>

    <?php if (empty($employees)): ?>
      <div class="admin-card" style="padding:25px;text-align:center;color:var(--admin-muted);">No Employee accounts found in system.</div>
    <?php else: ?>
      <div class="admin-table-wrap" style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Email</th>
              <th>Status</th>
              <th>Created Date</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $m): ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? '')) ?></td>
                <td>
                  <?php if ($m['role'] === 'driver'): ?>
                    <span class="admin-badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;">Delivery Personnel</span>
                  <?php else: ?>
                    <span class="admin-badge" style="background:rgba(167,118,147,0.15);color:#a77693;">Manager</span>
                  <?php endif; ?>
                </td>
                <td style="color:var(--admin-text);"><?= htmlspecialchars($m['email'] ?? '') ?></td>
                <td>
                  <?php if ($m['status'] === 'active'): ?>
                    <span class="admin-badge admin-badge--active">Active</span>
                  <?php elseif ($m['is_link_active']): ?>
                    <span class="admin-badge admin-badge--pending">⏱ Link Active (10m)</span>
                  <?php else: ?>
                    <span class="admin-badge admin-badge--danger">⚠️ Link Expired</span>
                  <?php endif; ?>
                </td>
                <td style="color:var(--admin-muted);font-size:13px;"><?= htmlspecialchars($m['created_at'] ?? '') ?></td>
                <td style="text-align:right;">
                  <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <?php if ($m['status'] !== 'active'): ?>
                      <form method="POST" action="" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend_invitation">
                        <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="action-btn" style="background:var(--admin-primary-2);color:#f2f3f4;padding:6px 12px;border:none;border-radius:var(--radius-sm);font-size:12px;cursor:pointer;box-shadow:var(--neu-raised-sm);">
                          🔄 Resend 10-Min Link
                        </button>
                      </form>
                    <?php endif; ?>

                    <form method="POST" action="" style="margin:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                      <input type="hidden" name="new_status" value="<?= $m['status'] === 'active' ? 'inactive' : 'active' ?>">
                      <button type="submit" class="action-btn" style="background:<?= $m['status'] === 'active' ? 'var(--admin-danger)' : 'var(--admin-success)' ?>;color:#f2f3f4;padding:6px 12px;border:none;border-radius:var(--radius-sm);font-size:12px;cursor:pointer;box-shadow:var(--neu-raised-sm);">
                        <?= $m['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
