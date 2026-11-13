<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

require_role(['super-admin']);

$pageTitle = 'Sales Logs - TechWorld';
$activeNav = 'sales';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';

$pdo = getConnection();

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

// Use total_amount column from orders table
$stmt = $pdo->prepare("SELECT MONTH(created_at) as m, SUM(total_amount) as total FROM orders WHERE YEAR(created_at) = ? AND status IN ('completed','packed','ready','shipped','preparing') GROUP BY MONTH(created_at) ORDER BY m");
$stmt->execute([$year]);
$rows = $stmt->fetchAll();
$byMonth = array_fill(1, 12, 0.0);
foreach ($rows as $r) { $byMonth[(int)$r['m']] = (float)$r['total']; }

$totalYear = array_sum($byMonth);

// Get detailed monthly data for table
$monthlyData = [];
$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
for ($m = 1; $m <= 12; $m++) {
    $monthlyData[] = [
        'month' => $monthNames[$m],
        'amount' => $byMonth[$m],
        'percentage' => $totalYear > 0 ? round(($byMonth[$m] / $totalYear) * 100, 1) : 0
    ];
}

// Get payment method breakdown
$paymentStmt = $pdo->prepare("SELECT payment_method, SUM(total_amount) as total FROM orders WHERE YEAR(created_at) = ? AND status IN ('completed','packed','ready','shipped','preparing') GROUP BY payment_method");
$paymentStmt->execute([$year]);
$paymentData = $paymentStmt->fetchAll();
?>
<main class="container" style="padding: 30px 0;">
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:1200px;">
    <h2 style="margin:0 0 15px 0;color:var(--admin-text);">Yearly Sales Logs</h2>
    <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:20px;">
      <label class="admin-form-label" style="margin:0;">Year</label>
      <input type="number" name="year" value="<?= $year ?>" min="2000" max="2100" class="admin-form-input" style="width:120px;" />
      <button class="action-btn admin-quick-link" style="border:none;cursor:pointer;">Load</button>
      <div style="margin-left:auto;font-weight:700;color:var(--admin-text);">Total <?= htmlspecialchars((string)$year) ?>: <?= formatCurrency($totalYear) ?></div>
    </form>
    <!-- Excel-like Table -->
    <div style="margin-bottom:30px;">
      <h3 style="margin-bottom:15px;color:var(--admin-text);">Monthly Sales Breakdown</h3>
      <div class="admin-table-wrap" style="overflow:auto;">
        <table style="font-size:14px;">
          <thead>
            <tr>
              <th style="text-align:left;">Month</th>
              <th style="text-align:right;">Sales Amount</th>
              <th style="text-align:right;">Percentage</th>
              <th style="text-align:center;">Visual</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($monthlyData as $data): ?>
            <tr>
              <td><?= htmlspecialchars($data['month']) ?></td>
              <td style="text-align:right;font-weight:700;"><?= formatCurrency($data['amount']) ?></td>
              <td style="text-align:right;"><?= $data['percentage'] ?>%</td>
              <td>
                <div class="admin-progress-track">
                  <div class="admin-progress-fill" style="--progress-pct:<?= $data['percentage'] ?>;"></div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td style="text-align:right;"><?= formatCurrency($totalYear) ?></td>
              <td style="text-align:right;">100%</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Charts Section -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-bottom:30px;">
      <!-- Bar Chart -->
      <div class="admin-card" style="padding:20px;">
        <h3 style="margin:0 0 20px 0;">Monthly Sales Bar Chart</h3>
        <div style="height:300px;display:flex;align-items:flex-end;gap:8px;padding:20px 0;">
          <?php
          $maxAmount = max($byMonth);
          foreach ($byMonth as $month => $amount):
            $height = $maxAmount > 0 ? ($amount / $maxAmount) * 100 : 0;
            $monthName = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][$month-1];
          ?>
          <div style="display:flex;flex-direction:column;align-items:center;flex:1;height:100%;justify-content:flex-end;">
            <?php $barStyle = $amount > 0 ? "height:{$height}%;min-height:4px;" : 'height:0;'; ?>
            <div style="background:var(--accent-cyan);width:100%;<?= $barStyle ?> border-radius:4px 4px 0 0;position:relative;" title="<?= formatCurrency($amount) ?>">
              <?php if ($amount > 0): ?>
              <div style="position:absolute;top:-22px;left:50%;transform:translateX(-50%);font-size:11px;color:var(--admin-muted);white-space:nowrap;"><?= formatCurrency($amount) ?></div>
              <?php endif; ?>
            </div>
            <div style="margin-top:8px;font-size:11px;color:var(--admin-muted);text-align:center;"><?= $monthName ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Pie Chart -->
      <div class="admin-card" style="padding:20px;">
        <h3 style="margin:0 0 20px 0;">Payment Method Distribution</h3>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php
          $colors = ['#a77693','#174871','#5fa88a','#d9a441','#c2686f','#8b7ec8'];
          $colorIndex = 0;
          foreach ($paymentData as $payment):
            $percentage = $totalYear > 0 ? round(($payment['total'] / $totalYear) * 100, 1) : 0;
            $color = $colors[$colorIndex % count($colors)];
            $colorIndex++;
          ?>
          <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:16px;height:16px;background:<?= $color ?>;border-radius:3px;flex-shrink:0;"></div>
            <div style="flex:1;color:var(--admin-text);"><?= htmlspecialchars(ucfirst($payment['payment_method'])) ?></div>
            <div style="color:var(--admin-muted);font-weight:700;"><?= formatCurrency($payment['total']) ?> (<?= $percentage ?>%)</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Monthly Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
      <?php $names = [1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      for ($m=1;$m<=12;$m++): ?>
        <div class="stat-card" style="padding:16px;">
          <div style="font-size:16px;font-weight:600;margin-bottom:8px;color:var(--admin-muted);"><?= $names[$m] ?></div>
          <div style="font-size:22px;font-weight:800;color:var(--admin-text);"><?= formatCurrency($byMonth[$m]) ?></div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>


