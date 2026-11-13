<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_SA'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/inventory_stock.php';

// Owner only. This is the approval the whole pull-out flow exists to require.
require_role(['owner', 'super-admin']);

/**
 * super-admin/pullout_approvals.php - the Owner's half of the pull-out flow.
 *
 * WHY THIS PAGE IS HERE AND NOT IN THE ADMIN PANEL
 *
 * super-admin/switch_to_admin.php deliberately rewrites user_role to 'admin'
 * when an Owner works in the admin panel, so isSuperAdmin() is false on every
 * admin/ page by design. An approve button over there could not tell an Owner
 * from a Manager, which would make the approval meaningless. The Owner
 * approves from their own panel, where they are actually the Owner - the same
 * arrangement requests.php already uses for employee accounts.
 *
 * This page also owns the slow-moving threshold, for the same reason: the
 * brief puts that setting in the Owner's hands, and this is where the Owner is.
 */

$pageTitle = 'Pull-Out Approvals - TechWorld Owner';
$activeNav = 'pullouts';

$pdo = getConnection();
$message     = $_GET['message'] ?? '';
$messageType = $_GET['messageType'] ?? 'info';

/* ----------------------------------------------------------------- POST ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $redirect = WEB_ROOT . 'public/authenticated/super-admin/pullout_approvals.php';

    if (!check_csrf(false)) {
        header('Location: ' . $redirect . '?message=' . urlencode('Invalid security token.') . '&messageType=error');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    // isSuperAdmin() is the real check and it is true here - the pages under
    // super-admin/ keep the Owner's own role. Passed into the domain layer,
    // which re-checks it rather than trusting the caller.
    $isOwner = isSuperAdmin();
    $msg = ''; $type = 'error';

    try {
        if ($action === 'decide') {
            $res = inv_decide_pullout(
                $pdo,
                (int)($_POST['pull_out_id'] ?? 0),
                (string)($_POST['decision'] ?? ''),
                (int)getUserId(),
                (string)($_POST['review_notes'] ?? ''),
                $isOwner
            );
            $msg = $res['message']; $type = $res['ok'] ? 'success' : 'error';
            if ($res['ok']) {
                logAudit('pullout_' . (string)($_POST['decision'] ?? ''), 'pull_out_requests',
                    (int)($_POST['pull_out_id'] ?? 0), null, ['notes' => $_POST['review_notes'] ?? '']);
            }
        } elseif ($action === 'final_action') {
            $res = inv_record_final_action(
                $pdo,
                (int)($_POST['pull_out_id'] ?? 0),
                (string)($_POST['final_action'] ?? ''),
                (int)getUserId(),
                (string)($_POST['final_notes'] ?? ''),
                $isOwner
            );
            $msg = $res['message']; $type = $res['ok'] ? 'success' : 'error';
            if ($res['ok']) {
                logAudit('pullout_final_action', 'pull_out_requests', (int)($_POST['pull_out_id'] ?? 0), null,
                    ['final_action' => $_POST['final_action'] ?? '', 'notes' => $_POST['final_notes'] ?? '']);
            }
        } elseif ($action === 'set_slow_days') {
            $res = inv_set_slow_moving_days($pdo, (int)($_POST['slow_moving_days'] ?? 0), $isOwner);
            $msg = $res['message']; $type = $res['ok'] ? 'success' : 'error';
            if ($res['ok']) {
                logAudit('slow_moving_days_set', 'inventory_settings', null, null,
                    ['slow_moving_days' => (int)($_POST['slow_moving_days'] ?? 0)]);
            }
        } else {
            $msg = 'Unknown action.';
        }
    } catch (Throwable $e) {
        error_log('pullout_approvals: ' . $e->getMessage());
        $msg = 'Server error - nothing was changed.';
    }

    header('Location: ' . $redirect . '?message=' . urlencode($msg) . '&messageType=' . urlencode($type));
    exit;
}

/* ----------------------------------------------------------------- READS --- */
$pending = [];
$awaitingFinal = [];
$history = [];
$slowDays = INV_SLOW_MOVING_DEFAULT_DAYS;
$counts = [];
$loadError = null;

try {
    $slowDays = inv_slow_moving_days($pdo);
    $counts   = inv_dashboard_counts($pdo);

    $sql = "SELECT r.*,
                   (SELECT COUNT(*) FROM pull_out_items i WHERE i.pull_out_id = r.id) AS unit_count,
                   TRIM(CONCAT(COALESCE(rq.firstname,''), ' ', COALESCE(rq.lastname,''))) AS requested_by_name,
                   TRIM(CONCAT(COALESCE(rv.firstname,''), ' ', COALESCE(rv.lastname,''))) AS reviewed_by_name,
                   TRIM(CONCAT(COALESCE(fa.firstname,''), ' ', COALESCE(fa.lastname,''))) AS final_by_name
              FROM pull_out_requests r
         LEFT JOIN users rq ON rq.id = r.requested_by
         LEFT JOIN users rv ON rv.id = r.reviewed_by
         LEFT JOIN users fa ON fa.id = r.final_action_by
             WHERE %s
             ORDER BY r.created_at DESC LIMIT %d";

    $pending       = $pdo->query(sprintf($sql, "r.status = 'pending'", 50))->fetchAll(PDO::FETCH_ASSOC);
    $awaitingFinal = $pdo->query(sprintf($sql, "r.status = 'approved' AND r.final_action IS NULL", 50))->fetchAll(PDO::FETCH_ASSOC);
    $history       = $pdo->query(sprintf($sql, "r.status <> 'pending' AND (r.status = 'rejected' OR r.final_action IS NOT NULL)", 40))->fetchAll(PDO::FETCH_ASSOC);

    // The units on every request on screen, in one query rather than one per
    // card - a 50-request queue would otherwise be 50 round trips.
    $ids = array_map(static fn($r) => (int)$r['id'], array_merge($pending, $awaitingFinal, $history));
    $unitsByRequest = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $u = $pdo->prepare(
            "SELECT i.pull_out_id, i.serial_number, i.remarks, p.name AS product_name, p.brand,
                    pu.status AS unit_status
               FROM pull_out_items i
               JOIN products p ON p.id = i.product_id
          LEFT JOIN product_units pu ON pu.unit_id = i.product_unit_id
              WHERE i.pull_out_id IN ($in)
              ORDER BY p.name, i.serial_number"
        );
        $u->execute($ids);
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unitsByRequest[(int)$row['pull_out_id']][] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('pullout_approvals read: ' . $e->getMessage());
    $loadError = 'Could not load the pull-out queue.';
    $unitsByRequest = [];
}

/** One request's unit list, as a compact serial summary. */
function pa_units_html(array $units): string
{
    if (!$units) { return '<em>no units on this request</em>'; }
    $out = [];
    foreach ($units as $u) {
        $out[] = '<span class="pa-serial">' . htmlspecialchars((string)$u['serial_number']) . '</span>'
               . ' <span class="pa-unit-prod">' . htmlspecialchars((string)$u['product_name']) . '</span>';
    }
    return implode('<br>', $out);
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    .pa-note{border-radius:8px;padding:12px 14px;margin:0 0 18px;font-size:14px;line-height:1.55;}
    .pa-note.success{background:rgba(95,168,138,.16);color:var(--admin-success,#5fa88a);}
    .pa-note.error{background:rgba(194,104,111,.16);color:var(--admin-danger,#c2686f);}
    .pa-note.info{background:rgba(128,128,128,.12);color:var(--admin-muted);}

    .pa-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px;}
    .pa-kpi{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;padding:18px;}
    .pa-kpi-n{font-size:1.9rem;font-weight:800;color:var(--admin-text);line-height:1.1;}
    .pa-kpi-l{font-size:.78rem;color:var(--admin-muted);margin-top:4px;}

    .pa-req{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;
      padding:18px;margin-bottom:14px;}
    .pa-req-head{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:baseline;
      border-bottom:1px solid var(--admin-border);padding-bottom:10px;margin-bottom:12px;}
    .pa-ref{font-family:ui-monospace,Consolas,monospace;font-weight:800;color:var(--admin-text);font-size:1rem;}
    .pa-meta{font-size:.78rem;color:var(--admin-muted);}
    .pa-reason{font-size:.88rem;color:var(--admin-text);line-height:1.6;margin:0 0 12px;
      padding:10px 12px;background:rgba(128,128,128,.08);border-radius:8px;}
    .pa-units{font-size:.8rem;color:var(--admin-muted);line-height:1.9;margin-bottom:14px;}
    .pa-serial{font-family:ui-monospace,Consolas,monospace;font-weight:700;color:var(--admin-text);
      background:rgba(128,128,128,.14);border-radius:4px;padding:1px 7px;}
    .pa-unit-prod{font-size:.76rem;}
    .pa-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
    .pa-actions textarea,.pa-actions select{padding:8px 10px;border-radius:7px;
      border:1px solid var(--admin-border);background:var(--admin-bg,#fff);color:var(--admin-text);font:inherit;font-size:13px;}
    .pa-actions textarea{min-width:260px;min-height:44px;flex:1;}
    .pa-btn{padding:10px 18px;border:none;border-radius:8px;font:inherit;font-weight:700;font-size:13px;cursor:pointer;}
    .pa-btn--ok{background:#1f7a53;color:#fff;}
    .pa-btn--no{background:#a8264d;color:#fff;}
    .pa-btn--go{background:var(--admin-primary-2,#174871);color:#fff;}
    .pa-empty{color:var(--admin-muted);font-size:.9rem;padding:22px 0;text-align:center;}
    .pa-chip{display:inline-block;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:12px;}
    .pa-chip--ok{background:rgba(95,168,138,.2);color:#5fa88a;}
    .pa-chip--no{background:rgba(194,104,111,.2);color:#c2686f;}
  </style>

  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/super-admin/dashboard.php">Owner</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Pull-Out Approvals</span>
      </nav>
    </div>
  </div>

  <h1 style="margin:0 0 6px;color:var(--admin-text);">Pull-Out Approvals</h1>
  <p style="margin:0 0 20px;color:var(--admin-muted);font-size:.9rem;line-height:1.6;max-width:78ch;">
    Managers raise pull-outs; the units are already out of sellable stock by the time a request
    reaches you. Approving it lets you record what finally happened to the units.
  </p>

  <?php if ($message !== ''): ?>
    <div class="pa-note <?= htmlspecialchars($messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info')) ?>">
      <?= htmlspecialchars((string)$message) ?>
    </div>
  <?php endif; ?>
  <?php if ($loadError): ?><div class="pa-note error"><?= htmlspecialchars($loadError) ?></div><?php endif; ?>

  <div class="pa-cards">
    <div class="pa-kpi"><div class="pa-kpi-n"><?= (int)($counts['pullouts_pending'] ?? 0) ?></div>
      <div class="pa-kpi-l">Awaiting your approval</div></div>
    <div class="pa-kpi"><div class="pa-kpi-n"><?= count($awaitingFinal) ?></div>
      <div class="pa-kpi-l">Approved, final action not recorded</div></div>
    <div class="pa-kpi"><div class="pa-kpi-n"><?= (int)($counts['defective_on_hand'] ?? 0) ?></div>
      <div class="pa-kpi-l">Defective units on hand</div></div>
    <div class="pa-kpi"><div class="pa-kpi-n"><?= (int)($counts['flagged_items'] ?? 0) ?></div>
      <div class="pa-kpi-l">Slow-moving items flagged</div></div>
  </div>

  <!-- 1. Pending approval -->
  <h2 style="font-size:1.05rem;color:var(--admin-text);margin:0 0 12px;">Awaiting approval</h2>
  <?php if (!$pending): ?>
    <div class="pa-req"><div class="pa-empty">Nothing is waiting for you.</div></div>
  <?php else: foreach ($pending as $r): ?>
    <div class="pa-req">
      <div class="pa-req-head">
        <div>
          <div class="pa-ref"><?= htmlspecialchars((string)$r['reference_no']) ?></div>
          <div class="pa-meta">
            <?= (int)$r['unit_count'] ?> unit<?= (int)$r['unit_count'] === 1 ? '' : 's' ?>
            &middot; <?= htmlspecialchars(INV_PULLOUT_SOURCES[(string)$r['source']] ?? (string)$r['source']) ?>
            &middot; raised by <?= htmlspecialchars(trim((string)$r['requested_by_name']) ?: 'a manager') ?>
            on <?= htmlspecialchars(date('j M Y, g:ia', strtotime((string)$r['created_at']))) ?>
          </div>
        </div>
      </div>
      <p class="pa-reason"><?= nl2br(htmlspecialchars((string)$r['reason'])) ?></p>
      <div class="pa-units"><?= pa_units_html($unitsByRequest[(int)$r['id']] ?? []) ?></div>

      <form method="POST" class="pa-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="decide">
        <input type="hidden" name="pull_out_id" value="<?= (int)$r['id'] ?>">
        <textarea name="review_notes" placeholder="Notes (optional) - kept on the record"></textarea>
        <button type="submit" name="decision" value="approved" class="pa-btn pa-btn--ok"
                data-confirm="Approve <?= htmlspecialchars((string)$r['reference_no']) ?>?"
                data-confirm-ok="Approve">Approve</button>
        <?php /* Rejecting does NOT put the units back on the shelf - they were
                 reported faulty by someone holding them. A Manager returns one
                 to stock deliberately from the Defective / Pull-Out page. */ ?>
        <button type="submit" name="decision" value="rejected" class="pa-btn pa-btn--no"
                data-confirm="Reject <?= htmlspecialchars((string)$r['reference_no']) ?>? The units stay marked Defective."
                data-confirm-ok="Reject">Reject</button>
      </form>
    </div>
  <?php endforeach; endif; ?>

  <!-- 2. Approved, final action outstanding -->
  <h2 style="font-size:1.05rem;color:var(--admin-text);margin:28px 0 12px;">Approved &mdash; record the final action</h2>
  <?php if (!$awaitingFinal): ?>
    <div class="pa-req"><div class="pa-empty">Nothing approved is waiting on a final action.</div></div>
  <?php else: foreach ($awaitingFinal as $r): ?>
    <div class="pa-req">
      <div class="pa-req-head">
        <div>
          <div class="pa-ref"><?= htmlspecialchars((string)$r['reference_no']) ?></div>
          <div class="pa-meta">
            <?= (int)$r['unit_count'] ?> unit<?= (int)$r['unit_count'] === 1 ? '' : 's' ?>
            &middot; approved by <?= htmlspecialchars(trim((string)$r['reviewed_by_name']) ?: 'you') ?>
            on <?= htmlspecialchars(date('j M Y', strtotime((string)$r['reviewed_at']))) ?>
          </div>
        </div>
        <span class="pa-chip pa-chip--ok">Approved</span>
      </div>
      <p class="pa-reason"><?= nl2br(htmlspecialchars((string)$r['reason'])) ?></p>
      <div class="pa-units"><?= pa_units_html($unitsByRequest[(int)$r['id']] ?? []) ?></div>

      <form method="POST" class="pa-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="final_action">
        <input type="hidden" name="pull_out_id" value="<?= (int)$r['id'] ?>">
        <select name="final_action" required aria-label="Final action">
          <option value="">&mdash; what happened to the units? &mdash;</option>
          <?php foreach (INV_FINAL_ACTIONS as $k => $label): ?>
            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <textarea name="final_notes" placeholder="RMA number, disposal note, etc. (optional)"></textarea>
        <button type="submit" class="pa-btn pa-btn--go"
                data-confirm="Record the final action for <?= htmlspecialchars((string)$r['reference_no']) ?>? The units become Pulled Out, which is final."
                data-confirm-ok="Record">Record final action</button>
      </form>
    </div>
  <?php endforeach; endif; ?>

  <!-- 3. Closed -->
  <h2 style="font-size:1.05rem;color:var(--admin-text);margin:28px 0 12px;">Closed</h2>
  <div class="ad-card">
    <div class="ad-table-wrap">
      <table class="ad-table">
        <thead><tr><th>Reference</th><th>Source</th><th style="text-align:right;">Units</th>
          <th>Decision</th><th>Final action</th><th>Approver</th><th>When</th></tr></thead>
        <tbody>
          <?php if (!$history): ?>
            <tr><td colspan="7" class="ad-table-empty">Nothing closed yet.</td></tr>
          <?php else: foreach ($history as $r): ?>
            <tr>
              <td style="font-family:ui-monospace,Consolas,monospace;font-weight:600;"><?= htmlspecialchars((string)$r['reference_no']) ?></td>
              <td style="font-size:12px;"><?= htmlspecialchars(INV_PULLOUT_SOURCES[(string)$r['source']] ?? (string)$r['source']) ?></td>
              <td style="text-align:right;"><?= (int)$r['unit_count'] ?></td>
              <td><span class="pa-chip <?= (string)$r['status'] === 'approved' ? 'pa-chip--ok' : 'pa-chip--no' ?>"><?= htmlspecialchars(ucfirst((string)$r['status'])) ?></span></td>
              <td style="font-size:12px;"><?= $r['final_action'] ? htmlspecialchars(INV_FINAL_ACTIONS[(string)$r['final_action']] ?? '') : '&mdash;' ?></td>
              <td style="font-size:12px;"><?= htmlspecialchars(trim((string)$r['reviewed_by_name']) ?: '—') ?></td>
              <td style="font-size:12px;"><?= htmlspecialchars(date('j M Y', strtotime((string)($r['final_action_at'] ?: $r['reviewed_at'])))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 4. The setting the brief reserves for the Owner -->
  <h2 style="font-size:1.05rem;color:var(--admin-text);margin:28px 0 12px;">Slow-moving threshold</h2>
  <div class="pa-req">
    <p style="margin:0 0 14px;color:var(--admin-muted);font-size:.86rem;line-height:1.6;max-width:72ch;">
      A product is flagged <strong>&ldquo;Poorly Sold &ndash; For Pull-Out&rdquo;</strong> when it is marked
      <strong>Discontinued</strong>, still has stock, and has had no sales for this many days.
      Only you can change this.
    </p>
    <form method="POST" class="pa-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_slow_days">
      <div>
        <label for="pa_days" style="display:block;font-size:.78rem;font-weight:700;color:var(--admin-muted);margin-bottom:5px;">Days without a sale</label>
        <input type="number" name="slow_moving_days" id="pa_days" min="1" max="3650" required
               value="<?= (int)$slowDays ?>"
               style="padding:9px 12px;border-radius:7px;border:1px solid var(--admin-border);background:var(--admin-bg,#fff);color:var(--admin-text);font:inherit;width:120px;">
      </div>
      <button type="submit" class="pa-btn pa-btn--go">Save threshold</button>
      <span style="font-size:.78rem;color:var(--admin-muted);">Currently <?= (int)$slowDays ?> days.</span>
    </form>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
