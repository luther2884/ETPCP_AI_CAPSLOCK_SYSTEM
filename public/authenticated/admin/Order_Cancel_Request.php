<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
// Per-unit stock: an approved cancellation has to release the specific units
// it reserved, not just the quantity.
require_once __DIR__ . '/../../../config/product_units.php';

// Require admin or super-admin
require_role(['admin', 'super-admin']);

// Additional validation to ensure user is properly authenticated
if (!isLoggedIn() || !getUserId()) {
    error_log("User not properly authenticated for admin cancellation requests");
    error_log("Session data: " . print_r($_SESSION, true));
    $_SESSION['error_message'] = 'Authentication error. Please log in again.';
    header('Location: ' . WEB_ROOT . 'public/guest/login.php');
    exit;
}

// Debug session information
error_log("Admin session - User ID: " . getUserId() . ", Role: " . getUserRole());

$pageTitle = 'Order Cancellation Requests - TechWorld';
$activeNav = 'order_cancel_requests';
$isAdminTheme = true;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $adminNotes = trim($_POST['admin_notes'] ?? '');
    
    if ($requestId > 0 && in_array($action, ['approve', 'reject'])) {
        try {
            $pdo = getConnection();
            
            // Get request details
            $requestStmt = $pdo->prepare("
                SELECT ocr.*, o.invoice_no, o.status as order_status, o.total_amount, o.delivery_method,
                       o.credit_applied,
                       u.firstname, u.lastname, u.email
                FROM order_cancellation_requests ocr
                JOIN orders o ON o.id = ocr.order_id
                JOIN users u ON u.id = ocr.user_id
                WHERE ocr.id = ? AND ocr.status = 'pending'
            ");
            $requestStmt->execute([$requestId]);
            $request = $requestStmt->fetch();
            
            if (!$request) {
                $_SESSION['error_message'] = 'Cancellation request not found or already processed.';
            } else {
                // Determine new status string
                $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
                
                // Begin transaction for consistency
                $pdo->beginTransaction();
                
                // Update cancellation request
                $adminId = getUserId();
                error_log("Processing cancellation request #{$requestId} with admin ID: {$adminId}");
                
                // Validate admin ID exists in users table
                if (!$adminId) {
                    throw new Exception("Admin user ID not found in session");
                }
                
                // Verify admin exists in database
                $adminCheckStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role IN ('admin', 'super-admin')");
                $adminCheckStmt->execute([$adminId]);
                $adminUser = $adminCheckStmt->fetch();
                if (!$adminUser) {
                    error_log("Admin user validation failed - User ID: {$adminId}");
                    throw new Exception("Admin user not found or invalid role. Please ensure you are logged in as an admin.");
                }
                error_log("Admin user validated - ID: {$adminUser['id']}, Role: {$adminUser['role']}");
                
                // Claiming the request is the atomic gate for everything that
                // follows. `AND status = 'pending'` re-proves inside the
                // transaction what the SELECT above checked outside it: that
                // read takes no row lock, so two admins hitting Approve at the
                // same moment both saw 'pending', and both would have run the
                // `stock = stock + quantity` restore - putting the units back
                // twice. Only the request that actually flips the row (1 row
                // affected) proceeds; the loser stops here and changes nothing.
                $updateStmt = $pdo->prepare("
                    UPDATE order_cancellation_requests 
                    SET status = ?, admin_notes = ?, admin_id = ?, processed_at = NOW()
                    WHERE id = ? AND status = 'pending'
                ");
                $updateStmt->execute([$newStatus, $adminNotes, $adminId, $requestId]);
                if ($updateStmt->rowCount() !== 1) {
                    throw new Exception('This cancellation request was just processed by someone else.');
                }
                
                // If approved, update order status
                $creditIssued = null; // set below if store credit was issued, used in the notification text
                if ($action === 'approve') {
                    $orderUpdateStmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
                    $orderUpdateStmt->execute([$request['order_id']]);

                    // Restore inventory (schema uses products.stock)
                    $inventoryStmt = $pdo->prepare("
                        UPDATE products p
                        JOIN order_items oi ON p.id = oi.product_id
                        SET p.stock = p.stock + oi.quantity
                        WHERE oi.order_id = ?
                    ");
                    $inventoryStmt->execute([$request['order_id']]);

                    // Put the specific physical units back on the shelf too.
                    // For a serialised product the unit rows are the truth and
                    // products.stock is a mirror of them, so the quantity
                    // restore above is not enough on its own - without this the
                    // mirror would be recomputed back down on the next unit
                    // change and the restored stock would silently vanish.
                    //
                    // pu_release_for_order() recounts the mirror itself, which
                    // overwrites what the statement above just wrote for those
                    // products. That is the intended order of precedence.
                    // Untracked products are untouched and keep the +quantity.
                    //
                    // Units the returns desk has already marked returned or
                    // defective are deliberately NOT swept back into stock: a
                    // late cancellation says nothing about a unit's condition.
                    pu_release_for_order($pdo, (int)$request['order_id']);

                    // Give back any store credit that was spent on this order.
                    // This is a separate axis from refund_method below, which
                    // only governs the CASH part: credit was already taken off
                    // the customer's ledger at checkout, so cancelling an order
                    // that only restocked the shelf would quietly keep their
                    // money. At face value, no +5% bonus - that incentive is for
                    // converting a cash refund into credit, and this was already
                    // credit. Same rule cancelPickupOrderAndRestoreStock() applies
                    // on a pickup expiry; zeroing credit_applied keeps it one-shot.
                    $creditSpent = round((float)($request['credit_applied'] ?? 0), 2);
                    if ($creditSpent > 0) {
                        $orderNumberForReturn = $request['invoice_no'] ?: ('ORD-' . str_pad((string)$request['order_id'], 6, '0', STR_PAD_LEFT));
                        $returnId = addStoreCredit(
                            (int)$request['user_id'],
                            $creditSpent,
                            'cancellation_credit',
                            (int)$request['order_id'],
                            $requestId,
                            "Store credit returned - order {$orderNumberForReturn} cancelled"
                        );
                        if ($returnId === false) {
                            throw new Exception('Failed to return the store credit spent on this order.');
                        }
                        $pdo->prepare("UPDATE orders SET credit_applied = 0 WHERE id = ?")->execute([$request['order_id']]);
                    }

                    // Money handling for orders that were actually paid online (COD/onsite
                    // orders have refund_method = NULL - nothing was collected yet, so
                    // there's nothing to refund or credit here).
                    if (!empty($request['refund_method'])) {
                        $orderNumberForCredit = $request['invoice_no'] ?: ('ORD-' . str_pad((string)$request['order_id'], 6, '0', STR_PAD_LEFT));
                        if ($request['refund_method'] === 'store_credit') {
                            // Instant, no separate admin action needed - this is the whole
                            // point of the incentive: faster for the customer, no manual
                            // gateway step for staff.
                            $creditBonusPct = 5;
                            $creditAmount = round((float)$request['total_amount'] * (1 + $creditBonusPct / 100), 2);
                            $creditId = addStoreCredit(
                                (int)$request['user_id'],
                                $creditAmount,
                                'cancellation_credit',
                                (int)$request['order_id'],
                                $requestId,
                                "Cancellation credit for order {$orderNumberForCredit} (+{$creditBonusPct}% bonus)"
                            );
                            if ($creditId === false) {
                                throw new Exception('Failed to issue store credit for the cancelled order.');
                            }
                            $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?")->execute([$request['order_id']]);
                            $pdo->prepare("UPDATE order_cancellation_requests SET refund_status = 'completed' WHERE id = ?")->execute([$requestId]);
                            $creditIssued = $creditAmount;
                        } else {
                            // 'original_payment' - actually sending the money back has to
                            // happen in the GCash/Maya/Xendit merchant dashboard, which this
                            // approval can't do by itself. Leaves a clear "pending" refund on
                            // this page (see the Refunds queue below) until staff mark it sent.
                            $pdo->prepare("UPDATE order_cancellation_requests SET refund_status = 'pending' WHERE id = ?")->execute([$requestId]);
                        }
                    }
                }

                $pdo->commit();

                // Create notification for customer (outside transaction to ensure it's not rolled back)
                $orderNumber = $request['invoice_no'] ?: 'ORD-' . str_pad($request['order_id'], 6, '0', STR_PAD_LEFT);
                logAudit(
                    $action === 'approve' ? 'order_cancellation_approved' : 'order_cancellation_rejected',
                    'orders',
                    (int)$request['order_id'],
                    ['status' => $request['order_status']],
                    ['invoice_no' => $orderNumber, 'reason' => $request['reason'], 'admin_notes' => $adminNotes]
                );

                if ($action === 'approve') {
                    // Notification for approved cancellation
                    error_log("Creating approval notification for user #{$request['user_id']}");
                    $moneyNote = '';
                    // Credit spent on the order is returned regardless of
                    // refund_method, so this note stacks with the ones below
                    // rather than replacing them (a part-credit, part-GCash
                    // order gets both halves explained).
                    if (!empty($creditSpent) && $creditSpent > 0) {
                        $moneyNote .= ' ' . formatCurrency($creditSpent) . ' in store credit used on this order has been returned to your balance.';
                    }
                    if ($creditIssued !== null) {
                        $moneyNote .= ' ' . formatCurrency($creditIssued) . ' in store credit has been added to your account and is ready to use.';
                    } elseif (($request['refund_method'] ?? '') === 'original_payment') {
                        $moneyNote .= ' Your refund of ' . formatCurrency($request['total_amount']) . ' to your original payment method is being processed.';
                    }
                    $notificationResult = createNotification(
                        $request['user_id'],
                        'order_status',
                        'Cancellation Request Approved - ' . $orderNumber,
                        'Your cancellation request for order ' . $orderNumber . ' has been approved. Your order has been cancelled. Reason: ' . $request['reason'] . '. Admin Notes: ' . $adminNotes . $moneyNote,
                        [
                            'order_id' => $request['order_id'],
                            'order_number' => $orderNumber,
                            'cancellation_reason' => $request['reason'],
                            'admin_notes' => $adminNotes,
                            'action' => 'approved'
                        ],
                        'high'
                    );
                    
                    if (!$notificationResult['success']) {
                        error_log("Failed to create approval notification for user #{$request['user_id']}: " . $notificationResult['message']);
                    } else {
                        error_log("Successfully created approval notification for user #{$request['user_id']}, notification ID: " . $notificationResult['notification_id']);
                    }
                } else {
                    // Notification for denied cancellation
                    error_log("Creating denial notification for user #{$request['user_id']}");
                    $notificationResult = createNotification(
                        $request['user_id'],
                        'order_status',
                        'Cancellation Request Denied - ' . $orderNumber,
                        'Your cancellation request for order ' . $orderNumber . ' has been denied. Your order will continue as planned. Admin Notes: ' . $adminNotes,
                        [
                            'order_id' => $request['order_id'],
                            'order_number' => $orderNumber,
                            'cancellation_reason' => $request['reason'],
                            'admin_notes' => $adminNotes,
                            'action' => 'denied'
                        ],
                        'medium'
                    );
                    
                    if (!$notificationResult['success']) {
                        error_log("Failed to create denial notification for user #{$request['user_id']}: " . $notificationResult['message']);
                    } else {
                        error_log("Successfully created denial notification for user #{$request['user_id']}, notification ID: " . $notificationResult['notification_id']);
                    }
                }
                
                $_SESSION['success_message'] = ($action === 'approve')
                    ? 'Cancellation request approved and order cancelled successfully.'
                    : 'Cancellation request rejected.';
                
                // Log the action
                error_log("Cancellation request #{$requestId} {$newStatus} by admin #" . getUserId());
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Error processing cancellation request: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            $_SESSION['error_message'] = 'An error occurred while processing the request. Error: ' . $e->getMessage();
        }
    } elseif ($requestId > 0 && $action === 'mark_refund_sent') {
        // Staff confirm they've actually sent the cash refund through their
        // GCash/Maya/Xendit merchant dashboard - this is what finally closes
        // out an 'original_payment' refund choice, since sending real money
        // isn't something this app can safely automate on its own.
        try {
            $pdo = getConnection();
            $reqStmt = $pdo->prepare("
                SELECT ocr.id, ocr.order_id, ocr.refund_method, ocr.refund_status, o.total_amount, o.invoice_no
                FROM order_cancellation_requests ocr
                JOIN orders o ON o.id = ocr.order_id
                WHERE ocr.id = ? AND ocr.status = 'approved' AND ocr.refund_method = 'original_payment' AND ocr.refund_status = 'pending'
            ");
            $reqStmt->execute([$requestId]);
            $refundRequest = $reqStmt->fetch();

            if (!$refundRequest) {
                $_SESSION['error_message'] = 'Refund request not found or already marked as sent.';
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE order_cancellation_requests SET refund_status = 'completed' WHERE id = ?")->execute([$requestId]);
                $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = ?")->execute([$refundRequest['order_id']]);
                $pdo->commit();

                if (function_exists('logAudit')) {
                    logAudit('cancellation_refund_sent', 'orders', (int)$refundRequest['order_id'], null, ['amount' => $refundRequest['total_amount']]);
                }
                $orderNumber = $refundRequest['invoice_no'] ?: ('ORD-' . str_pad((string)$refundRequest['order_id'], 6, '0', STR_PAD_LEFT));
                $_SESSION['success_message'] = "Refund for order {$orderNumber} marked as sent.";
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Mark refund sent error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Failed to update refund status.';
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get cancellation requests
try {
    $pdo = getConnection();
    
    // Get filter parameters
    // Pending is the working queue - the only status with decisions still to
    // make - so it is what the page opens on. Approved and rejected are
    // history and stay one click away.
    $statusFilter = $_GET['status'] ?? 'pending';
    if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
        $statusFilter = 'pending';
    }
    $search = trim($_GET['search'] ?? '');
    
    // Build query
    $whereConditions = [];
    $params = [];
    
    if ($statusFilter !== 'all') {
        $whereConditions[] = "ocr.status = ?";
        $params[] = $statusFilter;
    }
    
    if (!empty($search)) {
        $whereConditions[] = "(o.invoice_no LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
        $searchTerm = "%{$search}%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // The list renders as a 4-across card grid, 5 rows deep, so one page is
    // 20 requests. Count the filtered set first, then clamp the page to it.
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM order_cancellation_requests ocr
        JOIN orders o ON o.id = ocr.order_id
        JOIN users u ON u.id = ocr.user_id
        {$whereClause}
    ");
    $countStmt->execute($params);
    $totalRequests = (int)$countStmt->fetchColumn();

    $perPage = 20;
    $totalPages = max(1, (int)ceil($totalRequests / $perPage));
    $page = max(1, (int)($_GET['page'] ?? 1));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;

    $query = "
        SELECT ocr.*, o.invoice_no, o.status as order_status, o.total_amount, o.delivery_method, o.created_at as order_date,
               u.firstname, u.lastname, u.email, u.phone
        FROM order_cancellation_requests ocr
        JOIN orders o ON o.id = ocr.order_id
        JOIN users u ON u.id = ocr.user_id
        {$whereClause}
        ORDER BY ocr.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();
    
    // Get statistics
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM order_cancellation_requests
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
} catch (Throwable $e) {
    error_log("Error fetching cancellation requests: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $_SESSION['error_message'] = 'An error occurred while loading cancellation requests. Error: ' . $e->getMessage();
    $requests = [];
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    $totalRequests = 0;
    $page = 1;
    $totalPages = 1;
    $offset = 0;
    $statusFilter = $statusFilter ?? 'pending';
    $search = $search ?? '';
}

// Pager links have to carry the active filter and search, otherwise page 2
// silently resets to "all requests".
if (!function_exists('cancelPageUrl')) {
    function cancelPageUrl(int $n): string {
        global $statusFilter, $search;
        $qs = ['page' => $n];
        $qs['status'] = $statusFilter ?? 'pending';
        if (($search ?? '') !== '') { $qs['search'] = $search; }
        return '?' . http_build_query($qs);
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<main class="container">
  <style>
    /* Status tabs. Same pill family as the nav and the dispatch board's own
       filter tabs, so this reads as one system rather than a new control. */
    .cancel-tabs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
    .cancel-tab{display:inline-flex;align-items:center;gap:7px;
      padding:9px 15px;min-height:40px;border-radius:999px;text-decoration:none;
      background:var(--admin-surface);box-shadow:var(--neu-raised-sm);
      color:var(--admin-muted);font-size:13px;font-weight:700;
      transition:filter var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out);}
    .cancel-tab:hover{filter:brightness(1.07);color:var(--admin-text);text-decoration:none;}
    .cancel-tab:active{transform:scale(.98);}
    /* The selected tab gets a solid fill rather than the inset shadow the rest
       of the admin uses: these surfaces are the same colour as the page, so an
       inset alone is not enough contrast to answer "which one am I on". */
    .cancel-tab.is-current{background:var(--admin-primary-2);color:#f2f3f4;box-shadow:var(--neu-pressed);}
    .cancel-tab-count{display:inline-flex;align-items:center;justify-content:center;
      min-width:20px;height:20px;padding:0 6px;border-radius:999px;
      background:rgba(169,183,198,.22);color:inherit;font-size:11px;font-weight:700;}
    .cancel-tab.is-current .cancel-tab-count{background:rgba(255,255,255,.22);}
    @media (max-width: 560px){
      .cancel-tabs{width:100%;}
      .cancel-tab{flex:1;justify-content:center;padding:9px 10px;}
    }


    @media (min-width: 1700px){
      main.container{position:relative;}
    }

    /* --- Pickup queue grid -------------------------------------------------
       The queue used to be one full-detail card per order stacked vertically,
       which ran several screens deep. It is now a 4-across scan grid - order
       number, customer, date, total - and everything else moved into a dialog
       opened from the card. 20 cards (4 x 5) per page. */
    /* The theme caps .container at 1200px, which left each of the four cards
       under 280px and forced small type. body.admin outranks `.admin .container`. */
    html body.admin main.container{max-width:min(1560px,96vw);}

    .pickup-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;}
    .pickup-card{display:flex;flex-direction:column;gap:8px;text-align:left;width:100%;min-width:0;
      padding:24px 22px;border:none;cursor:pointer;font:inherit;
      background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);
      transition:transform var(--duration-fast) var(--ease-out),box-shadow var(--duration-fast) var(--ease-out);}
    .pickup-card:hover{transform:translateY(-3px);}
    .pickup-card:active{transform:translateY(0);box-shadow:var(--neu-pressed);}
    .pickup-card:focus-visible{outline:2px solid var(--admin-primary-2);outline-offset:2px;}
    .pickup-card > span:not(.pickup-card-top){min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .pickup-card-top{display:flex;align-items:center;justify-content:space-between;gap:10px;min-width:0;}
    .pickup-card-id{font-size:22px;font-weight:800;color:var(--admin-text);letter-spacing:.2px;
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .pickup-card-badge{flex:0 0 auto;padding:5px 11px;border-radius:999px;color:#fff;
      font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;line-height:1.4;}
    .pickup-card-name{font-size:17px;font-weight:600;color:var(--admin-text);}
    .pickup-card-date{font-size:15px;color:var(--admin-muted);}
    .pickup-card-total{margin-top:8px;padding-top:12px;border-top:1px solid var(--admin-border);
      font-size:17px;font-weight:700;color:var(--admin-text);}
    @media (max-width: 1200px){ .pickup-grid{grid-template-columns:repeat(3,minmax(0,1fr));} }
    @media (max-width: 900px){ .pickup-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
    @media (max-width: 560px){ .pickup-grid{grid-template-columns:minmax(0,1fr);} }

    .pickup-filter{display:flex;align-items:center;gap:10px;margin:0;}
    .pickup-filter label{color:var(--admin-muted);font-size:15px;font-weight:700;}
    .pickup-filter select{min-width:210px;font-size:15px;}

    /* --- Page numbers ------------------------------------------------------ */
    .pickup-pager{display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin-top:26px;}
    .pickup-page{min-width:44px;height:44px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;
      border-radius:var(--radius-sm);background:var(--admin-surface-2);color:var(--admin-text);
      box-shadow:var(--neu-raised-sm);text-decoration:none;font-weight:700;font-size:15px;
      transition:filter var(--duration-fast) var(--ease-out),transform var(--duration-fast) var(--ease-out);}
    .pickup-page:hover{filter:brightness(1.08);text-decoration:none;color:var(--admin-text);}
    .pickup-page:active{transform:scale(0.97);box-shadow:var(--neu-pressed);}
    .pickup-page.is-current{background:var(--admin-primary-2);color:#f2f3f4;box-shadow:var(--neu-pressed);}
    .pickup-page.is-disabled{opacity:.4;pointer-events:none;}
    .pickup-page-gap{color:var(--admin-muted);padding:0 2px;}

    /* --- Order dialog ------------------------------------------------------ */
    .pickup-modal{position:fixed;inset:0;z-index:3000;background:rgba(8,12,20,.62);
      display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto;}
    .pickup-modal[hidden]{display:none;}
    .pickup-modal-panel{width:100%;max-width:820px;background:var(--admin-surface-2);color:var(--admin-text);
      border-radius:var(--radius-xl);box-shadow:var(--neu-raised);padding:24px;text-align:left;}
    .pickup-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;
      margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--admin-border);flex-wrap:wrap;}
    .pickup-modal-close{background:none;border:none;cursor:pointer;font-size:28px;line-height:1;
      color:var(--admin-muted);padding:0 4px;}
    .pickup-modal-close:hover{color:var(--admin-text);}

    /* This page badges with .admin-badge (which carries its own colours and a
       dot), not the .status-* pills, so the card badge must not force white. */
    .pickup-card-badge{color:var(--admin-text);padding:4px 11px;}
  </style>
    <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='OrdMan.php'; }">Orders</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Cancellation Requests</span>
      </nav>
    </div>
  </div>
  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">

    <!-- Header -->
    <div class="dashboard-welcome" style="text-align:left;">
      <h1 style="display:flex;align-items:center;gap:12px;">
        <i class="fi fi-rr-cross" style="color:var(--admin-danger);"></i>
        Order Cancellation Requests
      </h1>
      <p>Review and manage customer order cancellation requests.</p>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
      <div style="background:rgba(95,168,138,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-success);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-check" style="font-size:16px;color:var(--admin-success);"></i>
          <?= htmlspecialchars($_SESSION['success_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
      <div style="background:rgba(194,104,111,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-danger);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-exclamation-triangle" style="font-size:16px;color:var(--admin-danger);"></i>
          <?= htmlspecialchars($_SESSION['error_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <?php /* The four KPI tiles are gone: every one of their numbers is already
             on a tab below, and the tabs are what you act on. Nothing else
             read $stats, so the query behind it is untouched. */ ?>

    <div class="ad-card">
      <div class="ad-card-head">
        <?php
          $__tabs = [
            'pending'  => ['Pending',  (int)($stats['pending'] ?? 0)],
            'approved' => ['Approved', (int)($stats['approved'] ?? 0)],
            'rejected' => ['Rejected', (int)($stats['rejected'] ?? 0)],
            'all'      => ['All',      (int)($stats['total'] ?? 0)],
          ];
        ?>
        <?php /* Tabs rather than a dropdown: four mutually exclusive states
                 with counts already to hand, and which one you are in should
                 be visible without opening anything. Real links, so each is
                 bookmarkable and works with the browser's back button; the
                 search box keeps its own value when you switch. */ ?>
        <div class="ad-tabs" role="tablist" aria-label="Filter by status">
          <?php foreach ($__tabs as $__key => [$__label, $__count]):
            $__isOn = ($statusFilter === $__key);
            $__qs = ['status' => $__key];
            if (($search ?? '') !== '') { $__qs['search'] = $search; }
          ?>
            <a class="ad-tab<?= $__isOn ? ' is-on' : '' ?>"
               href="?<?= http_build_query($__qs) ?>"
               role="tab" aria-selected="<?= $__isOn ? 'true' : 'false' ?>">
              <?= $__label ?>
              <span class="ad-chip <?= $__key === 'approved' ? 'ocr-count--ok' : 'ocr-count' ?>"><?= $__count ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <?php /* Same GET form, same two fields. The hidden `status` still
                 carries the active tab so searching does not throw you back to
                 the default, and Filter is still a real submit - just visually
                 hidden, so Enter in the box behaves exactly as it did. */ ?>
        <form method="GET" class="ad-search">
          <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                 placeholder="Order no. or customer, then Enter"
                 aria-label="Search by order number, customer name, or email">
          <button type="submit" class="ad-sr">Filter</button>
        </form>
      </div>

      <div class="ad-card-head">
        <h2 class="ad-card-title">Cancellation Requests</h2>
        <?php if ($search !== ''): ?>
          <span class="ad-card-note">Matching &ldquo;<?= htmlspecialchars($search) ?>&rdquo;</span>
        <?php endif; ?>
      </div>

      <?php if (empty($requests)): ?>
        <div class="ad-empty">
          <div class="ad-empty-icon" aria-hidden="true"><i class="fi fi-rr-check"></i></div>
          <h3>All caught up</h3>
          <p>No <?= $statusFilter === 'all' ? '' : htmlspecialchars($statusFilter) . ' ' ?>cancellation requests<?= $search !== '' ? ' match that search' : ' to review right now' ?>.</p>
          <?php if ($statusFilter !== 'approved' && (int)($stats['approved'] ?? 0) > 0): ?>
            <a class="ad-empty-link" href="?status=approved">See <?= (int)$stats['approved'] ?> approved &rsaquo;</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div style="padding:20px;">
          <div class="pickup-grid">
            <?php foreach ($requests as $request): ?>
              <?php
                $orderLabel = $request['invoice_no'] ?: 'ORD-' . str_pad((string)$request['order_id'], 6, '0', STR_PAD_LEFT);
                $badgeClass = ['pending' => 'admin-badge--pending', 'approved' => 'admin-badge--active', 'rejected' => 'admin-badge--danger'][$request['status']] ?? 'admin-badge--pending';
              ?>
              <button type="button" class="pickup-card" onclick="openRequestModal(<?= (int)$request['id'] ?>)"
                      aria-haspopup="dialog" aria-controls="requestModal-<?= (int)$request['id'] ?>">
                <span class="pickup-card-top">
                  <span class="pickup-card-id"><?= htmlspecialchars($orderLabel) ?></span>
                  <span class="admin-badge <?= $badgeClass ?> pickup-card-badge"><?= ucfirst($request['status']) ?></span>
                </span>
                <span class="pickup-card-name"><?= htmlspecialchars($request['firstname'] . ' ' . $request['lastname']) ?></span>
                <span class="pickup-card-date"><?= date('M j, Y g:i A', strtotime($request['created_at'])) ?></span>
                <span class="pickup-card-total">Total Amount: <?= formatCurrency($request['total_amount']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>

          <?php if ($totalPages > 1): ?>
            <?php
              // Windowed numbers: first, last, and the two either side of the
              // current page, so a long queue never grows an endless pager row.
              $window = [1, $totalPages, $page];
              for ($i = $page - 2; $i <= $page + 2; $i++) { $window[] = $i; }
              $window = array_values(array_unique(array_filter($window, fn($n) => $n >= 1 && $n <= $totalPages)));
              sort($window);
            ?>
            <nav class="pickup-pager" aria-label="Request pages">
              <a class="pickup-page<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= cancelPageUrl(max(1, $page - 1)) ?>" aria-label="Previous page">&#8249;</a>
              <?php $prev = 0; foreach ($window as $n): ?>
                <?php if ($prev && $n > $prev + 1): ?><span class="pickup-page-gap">&hellip;</span><?php endif; ?>
                <a class="pickup-page<?= $n === $page ? ' is-current' : '' ?>" href="<?= cancelPageUrl($n) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
                <?php $prev = $n; ?>
              <?php endforeach; ?>
              <a class="pickup-page<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= cancelPageUrl(min($totalPages, $page + 1)) ?>" aria-label="Next page">&#8250;</a>
            </nav>
          <?php endif; ?>
        </div>

        <!-- One dialog per card: everything the table's narrow columns used to
             carry - reason, notes, refund state - plus the approve/reject and
             refund actions. -->
        <?php foreach ($requests as $request): ?>
          <?php
            $orderLabel = $request['invoice_no'] ?: 'ORD-' . str_pad((string)$request['order_id'], 6, '0', STR_PAD_LEFT);
            $badgeClass = ['pending' => 'admin-badge--pending', 'approved' => 'admin-badge--active', 'rejected' => 'admin-badge--danger'][$request['status']] ?? 'admin-badge--pending';
          ?>
          <div class="pickup-modal" id="requestModal-<?= (int)$request['id'] ?>" role="dialog" aria-modal="true"
               aria-label="Cancellation request <?= htmlspecialchars($orderLabel) ?>" hidden
               onclick="if (event.target === this) closeRequestModal(<?= (int)$request['id'] ?>)">
            <div class="pickup-modal-panel">
              <div class="pickup-modal-head">
                <div>
                  <h3 style="margin:0;color:var(--admin-text);"><?= htmlspecialchars($orderLabel) ?></h3>
                  <p style="margin:5px 0 0 0;">
                    <?= htmlspecialchars($request['firstname'] . ' ' . $request['lastname']) ?>
                    (<?= htmlspecialchars($request['email']) ?>)
                  </p>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                  <span class="admin-badge <?= $badgeClass ?>" style="text-transform:uppercase;"><?= ucfirst($request['status']) ?></span>
                  <button type="button" class="pickup-modal-close" onclick="closeRequestModal(<?= (int)$request['id'] ?>)" aria-label="Close">&times;</button>
                </div>
              </div>

              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:18px;color:var(--admin-text);">
                <div>
                  <strong>Requested:</strong><br>
                  <span style="color:var(--admin-muted);"><?= date('M j, Y g:i A', strtotime($request['created_at'])) ?></span>
                </div>
                <div>
                  <strong>Order Total:</strong><br>
                  <?= formatCurrency($request['total_amount']) ?>
                </div>
                <div>
                  <strong>Fulfillment:</strong><br>
                  <span style="color:var(--admin-muted);"><?= ucfirst((string)$request['delivery_method']) ?></span>
                </div>
                <div>
                  <strong>Refund / Credit:</strong><br>
                  <?php if (empty($request['refund_method'])): ?>
                    <span style="color:var(--admin-muted);font-size:12px;">Not paid online</span>
                  <?php elseif ($request['refund_method'] === 'store_credit'): ?>
                    <span class="admin-badge admin-badge--active" style="text-transform:none;">Store credit issued</span>
                  <?php else: ?>
                    <span class="admin-badge <?= $request['refund_status'] === 'completed' ? 'admin-badge--active' : 'admin-badge--pending' ?>" style="text-transform:none;">
                      Original payment - <?= $request['refund_status'] === 'completed' ? 'Refund sent' : 'Refund pending' ?>
                    </span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($request['processed_at'])): ?>
                <div>
                  <strong>Processed:</strong><br>
                  <span style="color:var(--admin-muted);"><?= date('M j, Y g:i A', strtotime($request['processed_at'])) ?></span>
                </div>
                <?php endif; ?>
              </div>

              <div style="margin-bottom:18px;padding:14px;background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);color:var(--admin-text);">
                <h4 style="margin:0 0 8px 0;color:var(--admin-text);">Reason</h4>
                <div style="font-weight:600;"><?= htmlspecialchars($request['reason']) ?></div>
                <?php if (!empty($request['custom_reason'])): ?>
                  <div style="font-size:13px;color:var(--admin-muted);margin-top:6px;"><?= htmlspecialchars($request['custom_reason']) ?></div>
                <?php endif; ?>
                <?php if (!empty($request['additional_notes'])): ?>
                  <div style="font-size:13px;color:var(--admin-muted);margin-top:6px;font-style:italic;"><?= htmlspecialchars($request['additional_notes']) ?></div>
                <?php endif; ?>
              </div>

              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <?php if ($request['status'] === 'pending'): ?>
                  <button onclick="openActionModal(<?= (int)$request['id'] ?>, 'approve', '<?= htmlspecialchars($orderLabel) ?>')"
                          style="background:var(--admin-success);color:#f2f3f4;padding:9px 16px;border:none;border-radius:var(--radius-sm);cursor:pointer;font-size:13px;font-weight:600;">
                    <i class="fi fi-rr-check" style="margin-right:4px;"></i>
                    Approve
                  </button>
                  <button onclick="openActionModal(<?= (int)$request['id'] ?>, 'reject', '<?= htmlspecialchars($orderLabel) ?>')"
                          style="background:var(--admin-danger);color:#f2f3f4;padding:9px 16px;border:none;border-radius:var(--radius-sm);cursor:pointer;font-size:13px;font-weight:600;">
                    <i class="fi fi-rr-cross" style="margin-right:4px;"></i>
                    Reject
                  </button>
                <?php elseif ($request['status'] === 'approved' && $request['refund_method'] === 'original_payment' && $request['refund_status'] === 'pending'): ?>
                  <form method="POST" style="margin:0;" data-confirm="Confirm you have actually sent this refund through your GCash/Maya/Xendit dashboard?" data-confirm-ok="Yes, refund sent">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_refund_sent">
                    <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                    <button type="submit" style="background:var(--admin-success);color:#f2f3f4;padding:9px 16px;border:none;border-radius:var(--radius-sm);cursor:pointer;font-size:13px;font-weight:600;">
                      <i class="fi fi-rr-check" style="margin-right:4px;"></i>
                      Mark Refund Sent
                    </button>
                  </form>
                <?php else: ?>
                  <span style="color:var(--admin-muted);font-size:13px;">No further action needed for this request.</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    
  </div>
</main>

<script>
// One dialog per request is rendered up front (20 per page), so opening is
// just a visibility toggle. The approve/reject action modal sits above these
// at a higher z-index and posts exactly as it did from the old table.
function openRequestModal(requestId) {
  var modal = document.getElementById('requestModal-' + requestId);
  if (!modal) return;
  modal.hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeRequestModal(requestId) {
  var modal = document.getElementById('requestModal-' + requestId);
  if (!modal) return;
  modal.hidden = true;
  if (!document.querySelector('.pickup-modal:not([hidden])')) {
    document.body.style.overflow = '';
  }
}

document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  var open = document.querySelector('.pickup-modal:not([hidden])');
  if (open) { open.hidden = true; document.body.style.overflow = ''; }
});
</script>

<!-- Action Modal -->
<div id="actionModal" class="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,45,77,0.72);z-index:9999;backdrop-filter:blur(4px);">
  <div class="modal-container" style="position:relative;max-width:500px;margin:5% auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;">

    <!-- Modal Header -->
    <div class="modal-header" style="padding:24px 32px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <h2 style="color:var(--admin-text);font-size:24px;font-weight:600;margin:0;">
        <i class="fi fi-rr-exclamation-triangle" style="margin-right:12px;color:var(--admin-danger);"></i>
        <span id="modalTitle">Process Request</span>
      </h2>
      <button onclick="closeActionModal()" style="background:transparent;border:none;color:var(--admin-muted);font-size:24px;cursor:pointer;padding:8px;border-radius:var(--radius-sm);transition:all 0.2s ease;">
        <i class="fi fi-rr-cross"></i>
      </button>
    </div>

    <!-- Modal Content -->
    <!-- position/max-width/height reset explicitly - an unscoped
         .modal-content rule in shared/style.css (written for the
         login/register modal) otherwise wins: position:absolute +
         max-width:450px + height:fit-content pulls this out of the
         header/content flow entirely and shrinks it to a small floating
         box instead of filling the container below the header. -->
    <div class="modal-content" style="position:static;margin:0;background:transparent;border-radius:0;border:none;box-shadow:none;animation:none;width:100%;max-width:none;height:auto;max-height:none;padding:32px;">

      <div style="background:rgba(194,104,111,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-danger);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px;">
          <i class="fi fi-rr-exclamation-triangle" style="font-size:16px;color:var(--admin-danger);"></i>
          <span id="modalWarning">Are you sure you want to process this request?</span>
        </p>
      </div>

      <div style="background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;">
        <h4 style="color:var(--admin-text);font-size:16px;margin-bottom:8px;">Order Details</h4>
        <p style="color:var(--admin-muted);font-size:14px;margin:0;">Order #<span id="modalOrderNumber"></span></p>
      </div>

      <form id="actionForm" method="POST">
        <input type="hidden" name="action" id="modalAction">
        <input type="hidden" name="request_id" id="modalRequestId">

        <div class="form-group" style="margin-bottom:20px;">
          <label for="admin_notes" class="admin-form-label" style="font-size:14px;">
            Admin Notes <span style="color:var(--admin-danger);">*</span>
          </label>
          <textarea id="admin_notes" name="admin_notes" rows="3" required class="admin-form-input" style="width:100%;box-sizing:border-box;resize:vertical;" placeholder="Please provide notes about your decision..."></textarea>
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
          <button type="button" onclick="closeActionModal()" style="background:var(--admin-surface);color:var(--admin-text);box-shadow:var(--neu-raised-sm);border:none;padding:12px 24px;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;transition:all 0.2s ease;">
            Cancel
          </button>
          <button type="submit" id="modalSubmitBtn" class="admin-quick-link" style="border:none;padding:12px 24px;cursor:pointer;font-weight:600;margin:0;">
            Process Request
          </button>
        </div>
      </form>

    </div>

  </div>
</div>

<style>
.modal-overlay {
  animation: fadeIn 0.3s ease-out;
}

.modal-container {
  animation: slideIn 0.3s ease-out;
}

.modal-overlay.closing {
  animation: fadeOut 0.3s ease-in;
}

.modal-container.closing {
  animation: slideOut 0.3s ease-in;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes fadeOut {
  from { opacity: 1; }
  to { opacity: 0; }
}

@keyframes slideIn {
  from { 
    opacity: 0;
    transform: translateY(-50px) scale(0.95);
  }
  to { 
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@keyframes slideOut {
  from { 
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  to { 
    opacity: 0;
    transform: translateY(-50px) scale(0.95);
  }
}

.modal-header button:hover {
  background: var(--dropdown-hover);
  color: var(--admin-text);
}

button:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

@media (max-width: 768px) {
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .filters-section form {
    flex-direction: column;
    align-items: stretch;
  }
  
  .modal-container {
    margin: 2% auto;
    max-width: 95%;
  }
  
  .modal-content {
    padding: 24px;
  }
  
  .modal-header {
    padding: 20px 24px;
  }
}
</style>

<script>
function openActionModal(requestId, action, orderNumber) {
  const modal = document.getElementById('actionModal');
  const modalTitle = document.getElementById('modalTitle');
  const modalWarning = document.getElementById('modalWarning');
  const modalOrderNumber = document.getElementById('modalOrderNumber');
  const modalAction = document.getElementById('modalAction');
  const modalRequestId = document.getElementById('modalRequestId');
  const modalSubmitBtn = document.getElementById('modalSubmitBtn');
  
  modalRequestId.value = requestId;
  modalAction.value = action;
  modalOrderNumber.textContent = orderNumber;
  
  if (action === 'approve') {
    modalTitle.textContent = 'Approve Cancellation';
    modalWarning.textContent = 'Are you sure you want to approve this cancellation request? This will cancel the order and restore inventory.';
    modalSubmitBtn.textContent = 'Approve Request';
    // AA-safe darkened variant - the raw palette success/danger tones only
    // clear ~2.5-3.8:1 with white text, well under WCAG AA 4.5:1.
    modalSubmitBtn.style.background = '#047857';
  } else {
    modalTitle.textContent = 'Reject Cancellation';
    modalWarning.textContent = 'Are you sure you want to reject this cancellation request? The order will remain active.';
    modalSubmitBtn.textContent = 'Reject Request';
    modalSubmitBtn.style.background = '#b91c1c';
  }
  
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
  
  // Add animation classes
  modal.classList.remove('closing');
  modal.classList.add('opening');
}

function closeActionModal() {
  const modal = document.getElementById('actionModal');
  
  // Add closing animation
  modal.classList.add('closing');
  
  // Hide modal after animation
  setTimeout(() => {
    modal.style.display = 'none';
    modal.classList.remove('closing');
    document.body.style.overflow = 'auto';
    
    // Reset form
    document.getElementById('actionForm').reset();
  }, 300);
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
  const modal = document.getElementById('actionModal');
  if (event.target === modal) {
    closeActionModal();
  }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    const modal = document.getElementById('actionModal');
    if (modal.style.display === 'block') {
      closeActionModal();
    }
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
