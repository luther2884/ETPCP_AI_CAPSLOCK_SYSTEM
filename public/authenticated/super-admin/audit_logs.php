<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';

require_role(['super-admin']);

$pageTitle = 'Audit Logs - TechWorld';
$activeNav = 'audit';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<link rel="stylesheet" href="<?= asset_url('public/shared/css/date-range-picker.css') ?>">
<script src="<?= WEB_ROOT ?>public/shared/assets/js/date-range-picker.js" defer></script>
<?php

$pdo = getConnection();

// Distinct dates that actually have audit-log activity, for the date-range
// picker's "has data" dot indicator under calendar days.
$activityDates = [];
try {
    $actStmt = $pdo->prepare("SELECT DISTINCT DATE(created_at) as d FROM audit_logs WHERE user_id > 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 2 YEAR)");
    $actStmt->execute();
    $activityDates = array_column($actStmt->fetchAll(), 'd');
} catch (PDOException $e) {
    error_log("Audit logs activity-dates query error: " . $e->getMessage());
}

// Simple filters
$limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
$action = trim($_GET['action'] ?? '');
$userId = isset($_GET['user']) ? (int)$_GET['user'] : null;
// Date filter - lets the owner pin down exactly what an admin did on a
// specific day (or date range), instead of just scrolling recent-first.
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$where = ['al.user_id > 0']; // Exclude User #0 entries
$params = [];
if ($action !== '') { $where[] = 'al.action = ?'; $params[] = $action; }
// Restrict to admin/super-admin users only
$where[] = "u.role IN ('admin','super-admin')";
// Optional: still allow narrowing to a specific admin id if provided
if ($userId) { $where[] = 'al.user_id = ?'; $params[] = $userId; }
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'al.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'al.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT al.*, u.firstname, u.lastname FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id $whereSql ORDER BY al.created_at " . strtoupper($sort) . " LIMIT $limit");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Function to format action descriptions
function formatActionDescription($log) {
    $action = $log['action'];
    $tableName = $log['table_name'];
    $recordId = $log['record_id'];
    $newValues = $log['new_values'] ? json_decode($log['new_values'], true) : null;
    $oldValues = $log['old_values'] ? json_decode($log['old_values'], true) : null;
    
    switch ($action) {
        case 'product_price_updated':
        case 'update_price':
            if ($newValues && isset($newValues['price'])) {
                if ($oldValues && isset($oldValues['price'])) {
                    return "Changed the price of product ID {$recordId} from " . formatCurrency($oldValues['price']) . ' to ' . formatCurrency($newValues['price']);
                }
                return "Changed the price of product ID {$recordId} to " . formatCurrency($newValues['price']);
            }
            return "Updated product ID {$recordId}";

        case 'update_stock':
            if ($newValues && isset($newValues['stock'])) {
                if ($oldValues && isset($oldValues['stock'])) {
                    return "Changed stock of product ID {$recordId} from {$oldValues['stock']} to {$newValues['stock']}";
                }
                return "Changed stock of product ID {$recordId} to {$newValues['stock']}";
            }
            return "Updated stock for product ID {$recordId}";

        case 'update_image':
            return "Replaced the image for product ID {$recordId}";
            
        case 'product_created':
            if ($newValues && isset($newValues['name'])) {
                return "Added a new product: {$newValues['name']} (ID: {$recordId})";
            }
            return "Added a new product (ID: {$recordId})";
            
        case 'order_status_update':
            if ($newValues && isset($newValues['status'])) {
                $status = ucfirst($newValues['status']);
                $invoiceNo = $newValues['invoice_no'] ?? "Order #{$recordId}";
                return "Updated status of {$invoiceNo} to {$status}";
            }
            return "Updated order ID {$recordId}";
            
        case 'order_created':
            if ($newValues && isset($newValues['invoice_no'])) {
                return "Created new order: {$newValues['invoice_no']}";
            }
            return "Created order ID {$recordId}";
            
        case 'order_completed':
            if ($newValues && isset($newValues['invoice_no'])) {
                return "Completed order: {$newValues['invoice_no']}";
            }
            return "Completed order ID {$recordId}";
            
        case 'admin_request_approved':
            return "Approved admin request ID {$recordId}";

        case 'admin_request_denied':
            return "Denied admin request ID {$recordId}";

        case 'order_cancellation_approved':
            $invoiceNo = $newValues['invoice_no'] ?? "Order #{$recordId}";
            return "Approved cancellation request for {$invoiceNo}";

        case 'order_cancellation_rejected':
            $invoiceNo = $newValues['invoice_no'] ?? "Order #{$recordId}";
            return "Rejected cancellation request for {$invoiceNo}";

        case 'delivery_proof_uploaded':
            $count = $newValues['file_count'] ?? 1;
            return "Uploaded {$count} delivery proof photo(s) for order ID {$recordId}";
            
        case 'user_role_updated':
            if ($newValues && isset($newValues['role'])) {
                return "Updated user role to {$newValues['role']} for user ID {$recordId}";
            }
            return "Updated user role for user ID {$recordId}";
            
        case 'logout':
            return "Logged out";
            
        case 'login':
            return "Logged in";
            
        default:
            // Generic actions ('create'/'update'/'delete') read a lot more
            // usefully once the table name (and, if present, a name/title
            // from the logged values) is folded in, rather than showing the
            // bare verb alone.
            $verb = ucfirst(str_replace('_', ' ', $action));
            if (!$tableName) { return $verb; }
            $label = $newValues['name'] ?? $newValues['title'] ?? null;
            $what = $label ? "\"{$label}\" in {$tableName}" : "{$tableName} ID {$recordId}";
            return "{$verb} {$what}";
    }
}

// Turns a raw field key like "category_id" into "Category id" for display.
function humanizeFieldLabel($key) {
    $label = ucfirst(str_replace('_', ' ', (string)$key));
    return str_replace(' id', ' ID', $label);
}

// Formats a single logged value for a human reader - currency for
// price-like fields, "N item(s)" for stock, Yes/No for booleans, etc.
function humanizeFieldValue($key, $value) {
    if ($value === null || $value === '') { return '(empty)'; }
    if (is_bool($value)) { return $value ? 'Yes' : 'No'; }
    if (is_array($value)) { return implode(', ', array_map('strval', $value)); }
    if (in_array($key, ['price', 'total_amount', 'total', 'amount'], true) && is_numeric($value)) {
        return function_exists('formatCurrency') ? formatCurrency((float)$value) : ('₱' . number_format((float)$value, 2));
    }
    if ($key === 'stock' && is_numeric($value)) {
        $n = (int)$value;
        return $n . ' item' . ($n === 1 ? '' : 's');
    }
    return (string)$value;
}

// Builds a plain-English bullet list of exactly what changed for a log
// entry ("Stock: 1 item -> 10 items"), instead of showing the raw JSON
// that's actually stored - the owner shouldn't need to read code/JSON to
// know what an admin did.
function formatValuesDiff($log) {
    $old = $log['old_values'] ? json_decode($log['old_values'], true) : null;
    $new = $log['new_values'] ? json_decode($log['new_values'], true) : null;
    $old = is_array($old) ? $old : [];
    $new = is_array($new) ? $new : [];
    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
    if (!$keys) { return []; }

    $lines = [];
    foreach ($keys as $key) {
        $hasOld = array_key_exists($key, $old);
        $hasNew = array_key_exists($key, $new);
        $label = humanizeFieldLabel($key);
        if ($hasOld && $hasNew) {
            if ($old[$key] == $new[$key]) { continue; } // unchanged, don't clutter the list
            $lines[] = "{$label}: " . humanizeFieldValue($key, $old[$key]) . ' → ' . humanizeFieldValue($key, $new[$key]);
        } elseif ($hasNew) {
            $lines[] = "{$label}: " . humanizeFieldValue($key, $new[$key]);
        } else {
            $lines[] = "{$label}: " . humanizeFieldValue($key, $old[$key]) . ' (removed)';
        }
    }
    return $lines;
}
?>
<main class="container" style="padding: 30px 0;">
  <div class="admin-dashboard" style="background:transparent;color:inherit;margin:0 auto;padding:30px;border-radius:12px;max-width:1200px;border:1px solid rgba(148,163,184,0.12);box-shadow:0 10px 30px rgba(0,0,0,0.35)">
    <h2 style="margin:0 0 15px 0;">Audit Logs</h2>
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:15px;">
      <input type="text" name="action" value="<?= htmlspecialchars($action) ?>" placeholder="Filter by action" class="input" style="width:auto;" />
      <input type="number" name="user" value="<?= $userId ?: '' ?>" placeholder="Admin ID (optional)" class="input" style="width:auto;" />
      <div class="drp" data-drp
           data-drp-start="<?= htmlspecialchars($dateFrom) ?>"
           data-drp-end="<?= htmlspecialchars($dateTo) ?>"
           data-drp-activity="<?= htmlspecialchars(json_encode($activityDates)) ?>">
        <button type="button" class="drp-trigger" data-drp-trigger>
          <i class="fi fi-rr-calendar"></i>
          <span class="drp-trigger-label" data-drp-label></span>
        </button>
        <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" data-drp-hidden-start>
        <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" data-drp-hidden-end>
      </div>
      <select name="sort" class="input" style="width:auto;">
        <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Newest first</option>
        <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>Oldest first</option>
      </select>
      <select name="limit" class="input" style="width:auto;">
        <?php foreach ([50,100,150,200] as $opt): ?>
          <option value="<?= $opt ?>" <?= $limit===$opt?'selected':'' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-accent">Apply</button>
      <?php if ($action !== '' || $userId || $dateFrom !== '' || $dateTo !== '' || $sort !== 'desc'): ?>
        <a href="?" style="color:#94a3b8;font-size:13px;">Clear filters</a>
      <?php endif; ?>
    </form>
    <div style="overflow:auto;border:1px solid #1f2937;border-radius:8px;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#0f172a;color:#e2e8f0;">
            <th style="padding:10px;border-bottom:1px solid #1f2937;text-align:left;">Time</th>
            <th style="padding:10px;border-bottom:1px solid #1f2937;text-align:left;">User</th>
            <th style="padding:10px;border-bottom:1px solid #1f2937;text-align:left;">Action</th>
            <th style="padding:10px;border-bottom:1px solid #1f2937;text-align:left;">Table</th>
            <th style="padding:10px;border-bottom:1px solid #1f2937;text-align:left;">Record</th>
            <th style="padding:10px;border-bottom:1px solid #1f2937;text-align:left;">Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <?php
            $loggedAt = strtotime((string)$log['created_at']);
            $loggedAtDisplay = $loggedAt ? date('M j, Y g:i:s A', $loggedAt) : $log['created_at'];
          ?>
          <tr>
            <td style="padding:8px;border-bottom:1px solid #1f2937;white-space:nowrap;" title="<?= htmlspecialchars((string)$log['created_at']) ?>"><?= htmlspecialchars($loggedAtDisplay) ?></td>
            <td style="padding:8px;border-bottom:1px solid #1f2937;"><?= htmlspecialchars(trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? '')) ?: ('User #' . (int)$log['user_id'])) ?></td>
            <td style="padding:8px;border-bottom:1px solid #1f2937;"><?= htmlspecialchars(formatActionDescription($log)) ?></td>
            <td style="padding:8px;border-bottom:1px solid #1f2937;"><?= htmlspecialchars($log['table_name'] ?: '-') ?></td>
            <td style="padding:8px;border-bottom:1px solid #1f2937;"><?= htmlspecialchars((string)($log['record_id'] ?? '-')) ?></td>
            <td style="padding:8px;border-bottom:1px solid #1f2937;max-width:520px;">
              <?php $diffLines = formatValuesDiff($log); ?>
              <?php if ($diffLines): ?>
              <details>
                <summary style="cursor:pointer;color:#a77693;">View</summary>
                <ul style="margin:8px 0 0;padding-left:18px;background:#0b1220;color:#e2e8f0;border-radius:6px;padding:10px 10px 10px 26px;list-style:disc;">
                  <?php foreach ($diffLines as $line): ?>
                    <li style="margin-bottom:4px;"><?= htmlspecialchars($line) ?></li>
                  <?php endforeach; ?>
                </ul>
              </details>
              <?php else: ?>
                <span style="color:#64748b;">-</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>


