<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

// Super-admin only
require_role(['super-admin']);

$pageTitle = 'Security Management Panel - TechWorld';
$activeNav = '';
include __DIR__ . '/../../shared/includes/header.php';
include __DIR__ . '/../../shared/includes/navigation.php';
?>
<main class="container" style="padding:30px 0;">
  <div class="security-panel" style="background:#fff;color:#111;margin:0 auto;padding:30px;border-radius:10px;max-width:1400px;box-shadow:0 2px 10px rgba(0,0,0,0.1)">
    <div class="panel-header" style="background:linear-gradient(135deg,#dc3545 0%, #c82333 100%);color:#fff;padding:20px;border-radius:10px;margin-bottom:30px;text-align:center;">
      <h1>Security Management Panel</h1>
      <p>Configure security settings and perform maintenance</p>
    </div>

    <div class="stats-overview" style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:30px;">
      <div class="stat-item" style="background:linear-gradient(135deg,#17a2b8 0%, #138496 100%);color:#fff;padding:20px;border-radius:10px;text-align:center;">
        <div class="stat-number" style="font-size:28px;font-weight:bold;margin-bottom:5px;">—</div>
        <div>Active Admins</div>
      </div>
      <div class="stat-item" style="background:linear-gradient(135deg,#17a2b8 0%, #138496 100%);color:#fff;padding:20px;border-radius:10px;text-align:center;">
        <div class="stat-number" style="font-size:28px;font-weight:bold;margin-bottom:5px;">—</div>
        <div>Pending Requests</div>
      </div>
      <div class="stat-item" style="background:linear-gradient(135deg,#17a2b8 0%, #138496 100%);color:#fff;padding:20px;border-radius:10px;text-align:center;">
        <div class="stat-number" style="font-size:28px;font-weight:bold;margin-bottom:5px;">—</div>
        <div>Recent Events</div>
      </div>
      <div class="stat-item" style="background:linear-gradient(135deg,#17a2b8 0%, #138496 100%);color:#fff;padding:20px;border-radius:10px;text-align:center;">
        <div class="stat-number" style="font-size:28px;font-weight:bold;margin-bottom:5px;">100%</div>
        <div>System Health</div>
      </div>
    </div>

    <div class="maintenance-section" style="background:#fff3cd;border:1px solid #ffeaa7;border-radius:8px;padding:20px;">
      <h3>System Maintenance</h3>
      <p>Run routine security cleanup tasks (registration attempts, expired sessions).</p>
      <button type="button" class="btn-maintenance" style="background:#ffc107;color:#212529;padding:10px 20px;border:none;border-radius:5px;font-weight:bold;cursor:pointer;margin-right:10px;" onclick="performMaintenance()">Run Security Cleanup</button>
    </div>

    <div style="text-align:center;margin-top:30px;">
      <a class="action-btn" style="background:#007bff;color:#fff;padding:12px 20px;border-radius:8px;display:inline-block;margin:5px;text-decoration:none;" href="<?= WEB_ROOT ?>super-admin/dashboard.php">Back to Dashboard</a>
      <a class="action-btn" style="background:#dc3545;color:#fff;padding:12px 20px;border-radius:8px;display:inline-block;margin:5px;text-decoration:none;" href="<?= WEB_ROOT ?>public/authenticated/auth/logout.php?redirect=public/authenticated/super-admin/">Logout</a>
    </div>
  </div>
</main>

<script>
async function performMaintenance(){
  if(!(await uiConfirm('This will clean up old registration attempts and expired sessions. Continue?'))) return;
  fetch('<?= WEB_ROOT ?>super-admin/maintenance.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams({ action: 'security_cleanup' })
  }).then(r=>r.json()).then(data=>{
    alert(data.success ? 'Security cleanup completed successfully!' : ('Maintenance failed: '+(data.message||'Unknown error')));
    if(data.success) location.reload();
  }).catch(()=>alert('An error occurred during maintenance.'));
}
</script>

<?php include __DIR__ . '/../../shared/includes/footer.php'; ?>


