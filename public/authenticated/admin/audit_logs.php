<?php
declare(strict_types=1);
/**
 * Audit Logs - the plain-language record of what has been done in the system.
 *
 * Reads `audit_logs` and renders it for a manager, not an engineer: every
 * action slug, column name and stored value passes through
 * config/audit_language.php first, so nothing on this screen is a code name.
 *
 * Three things this page is careful about, because they are the difference
 * between a log and a log people trust:
 *
 *  1. WHO really did it. The Admin panel can be driven by an Owner working as
 *     a Manager (super-admin/switch_to_admin.php). Those rows carry the
 *     Owner's own user id plus an acting_as marker, and are badged here so an
 *     Admin can see the Owner made the change even though it happened on the
 *     Admin side.
 *  2. WHAT changed, before and after. audit_changes() diffs the two JSON
 *     snapshots and drops the fields that did not actually move, so the one
 *     thing that changed is not buried in a full record dump.
 *  3. What did NOT happen. Rows marked failed are phrased as attempts
 *     ("tried to update an order") and visibly marked, so an action that never
 *     completed can never be read as one that did.
 *
 * The listing is paginated and resolves ids in batches: one query for the page
 * of rows, then one each for the people, orders and products those rows
 * mention. It never queries per row.
 */
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/audit_language.php';
require_role(['admin']);

$pageTitle = 'Audit Logs - TechWorld Admin';
$isAdminTheme = true;
$activeNav = 'audit';

$pdo = getConnection();

// Newer installs have the actor-context columns; older ones do not. Resolved
// once here so the query below can be built either way rather than failing.
$hasContext = function_exists('auditHasContextColumns') ? auditHasContextColumns() : false;

// ---------------------------------------------------------------------------
// Filters. All optional, all carried in the query string so a filtered view
// can be bookmarked or handed to someone else.
// ---------------------------------------------------------------------------
// The free-text search (q), the actor picker (who) and the system-activity
// toggle are gone. Each of them was a way to range over the whole audit table
// from this screen - `who` enumerated every account that had ever done
// anything, `q` matched against the stored JSON of every row, and the toggle
// surfaced the background jobs. This page shows one thing now: what Managers
// did. Area and date range remain; they only narrow that set.
$fArea   = trim((string)($_GET['area'] ?? ''));
$fFrom   = trim((string)($_GET['from'] ?? ''));
$fTo     = trim((string)($_GET['to'] ?? ''));
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;

$where  = [];
$params = [];

// ---------------------------------------------------------------------------
// What this screen is allowed to show.
//
// An ALLOW-list, not a set of exclusions, and not a filter: no query string
// reaches it. A row appears only if it is one of these two things.
//
//   1. A Manager's own action. Roles admin / manager.
//
//   2. An Owner working THROUGH this panel - super-admin/switch_to_admin.php
//      sets acting_as, so the row is an action on the Manager's side affecting
//      the Manager's orders. It stays visible and keeps its "acting as Manager"
//      badge, which is the whole point of recording the capacity.
//
// Everything else is withheld: customers, riders, the background jobs, and the
// Owner's own work in the Super Admin panel. Written as an allow-list on
// purpose - with exclusions, any role added to the system later would leak into
// this page by default, whereas here it stays out until somebody decides it
// belongs.
//
// NOTE: this governs DISPLAY only. logAudit() still records every action by
// every user; nothing below changes what is written. The full trail remains in
// the audit_logs table for security and investigation - it simply is not
// readable from this screen.
//
// The role comes from the row's own actor_role snapshot where present, falling
// back to the account's current role only for rows written before that column
// existed. Those older rows cannot prove whether an Owner was acting as a
// Manager, so they fail clause 2 and stay hidden: for a confidentiality rule
// the safe default is to withhold rather than guess.
// ---------------------------------------------------------------------------
$managerRoles = ['admin', 'manager'];
$ownerRoles   = ['super-admin', 'superadmin', 'owner'];

if ($hasContext) {
    $roleExpr   = "COALESCE(NULLIF(a.actor_role,''), u.role)";
    $actingExpr = "NULLIF(a.acting_as,'')";
} else {
    // Without the context columns an Owner acting as a Manager is
    // indistinguishable from an Owner in their own panel, so clause 2 can never
    // be satisfied and only real Manager accounts are listed.
    $roleExpr   = 'u.role';
    $actingExpr = 'NULL';
}

$managerIn = implode(',', array_fill(0, count($managerRoles), '?'));
$ownerIn   = implode(',', array_fill(0, count($ownerRoles), '?'));

$where[] = "( $roleExpr IN ($managerIn)"
         . " OR ($roleExpr IN ($ownerIn) AND $actingExpr IN ($managerIn)) )";
foreach ($managerRoles as $r) { $params[] = $r; }
foreach ($ownerRoles as $r)   { $params[] = $r; }
foreach ($managerRoles as $r) { $params[] = $r; }

// Staff sign-outs. logout() no longer records them, but the rows written before
// that change would otherwise still be listed.
$where[] = "a.action <> 'logout'";

// The background jobs no longer need excluding by hand: they run with no
// user_id, so no role matches and the allow-list above already drops them.

// "Area" is a reading-level grouping, not a column - it comes from the action
// map. Translate the chosen area back into the entries that belong to it.
//
// Matching on the action slug alone would be wrong: 'update' is a generic slug
// shared by products, inventory settings and promo banners, so filtering to
// Products on action='update' would drag Inventory and Marketing rows in with
// it. Keys that name a table are therefore matched as an (action, table) pair,
// and only table-less keys match on the action by itself.
if ($fArea !== '') {
    $clauses = [];
    foreach (AUDIT_ACTIONS as $key => $meta) {
        if ($meta[0] !== $fArea) { continue; }
        if (strpos($key, '|') !== false) {
            [$slug, $table] = explode('|', $key, 2);
            $clauses[] = '(a.action = ? AND a.table_name = ?)';
            $params[]  = $slug;
            $params[]  = $table;
        } else {
            $clauses[] = 'a.action = ?';
            $params[]  = $key;
        }
    }
    // No action maps to this area - match nothing rather than everything.
    $where[] = $clauses ? ('(' . implode(' OR ', $clauses) . ')') : '1 = 0';
}

if ($fFrom !== '') { $where[] = 'a.created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')   { $where[] = 'a.created_at <= ?'; $params[] = $fTo . ' 23:59:59'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total, for the pager.
$countSql = "SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $whereSql";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRows  = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// The page of entries. `actor_current_role` is the account's role TODAY, used
// only when the row predates the actor_role snapshot column.
$contextCols = $hasContext
    ? 'a.actor_role, a.acting_as, a.status, a.detail,'
    : "NULL AS actor_role, NULL AS acting_as, 'success' AS status, NULL AS detail,";

$sql = "SELECT a.id, a.user_id, $contextCols
               a.action, a.table_name, a.record_id, a.old_values, a.new_values, a.created_at,
               TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.lastname,''))) AS actor_name,
               u.role AS actor_current_role
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        $whereSql
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// Batched id -> name lookups.
//
// Collected from BOTH the record_id column and the ids buried in the JSON
// payloads (a rider id, a product id), so "Rider: 4" can be rendered as a
// name. Three queries for the whole page, regardless of how many rows.
// ---------------------------------------------------------------------------
$peopleIds = [];
$orderIds  = [];
$productIds = [];

foreach ($rows as $r) {
    $payload = audit_decode($r['old_values']) + audit_decode($r['new_values']);
    foreach (AUDIT_PERSON_FIELDS as $pf) {
        if (!empty($payload[$pf])) { $peopleIds[] = (int)$payload[$pf]; }
    }
    if (!empty($payload['product_id'])) { $productIds[] = (int)$payload['product_id']; }

    $rid = $r['record_id'] !== null ? (int)$r['record_id'] : 0;
    if ($rid > 0) {
        if ($r['table_name'] === 'orders')   { $orderIds[]   = $rid; }
        if ($r['table_name'] === 'products') { $productIds[] = $rid; }
        if ($r['table_name'] === 'users')    { $peopleIds[]  = $rid; }
    }
}

/** One IN(...) lookup, or an empty map when there is nothing to look up. */
function audit_lookup(PDO $pdo, string $sqlTemplate, array $ids): array {
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) { return []; }
    $sql = str_replace('{IN}', implode(',', array_fill(0, count($ids), '?')), $sqlTemplate);
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $label]) { $out[(int)$id] = (string)$label; }
    return $out;
}

$lookups = [
    'people'   => audit_lookup($pdo, "SELECT id, TRIM(CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,''))) FROM users WHERE id IN ({IN})", $peopleIds),
    'orders'   => audit_lookup($pdo, "SELECT id, COALESCE(NULLIF(invoice_no,''), CONCAT('#', id)) FROM orders WHERE id IN ({IN})", $orderIds),
    'products' => audit_lookup($pdo, "SELECT id, name FROM products WHERE id IN ({IN})", $productIds),
];

// The "Who" dropdown is gone, and so is the query that filled it: naming
// every account that had ever acted was itself a way to read the wider log.

// Areas, in the order the action map declares them.
$areaOptions = [];
foreach (AUDIT_ACTIONS as $meta) {
    if (!in_array($meta[0], $areaOptions, true)) { $areaOptions[] = $meta[0]; }
}
sort($areaOptions);

/** Keeps the current filters when moving between pages. */
function audit_page_url(int $target): string {
    $qs = $_GET;
    $qs['p'] = $target;
    return '?' . http_build_query($qs);
}

/** "2 hours ago" / "Yesterday, 3:04 PM" - the times a person reads at a glance,
 *  with the exact timestamp always available as a tooltip. */
function audit_when(string $ts): array {
    $t   = strtotime($ts);
    $now = time();
    $diff = $now - $t;
    if ($diff < 60)     { $rel = 'Just now'; }
    elseif ($diff < 3600)  { $m = (int)floor($diff / 60);   $rel = $m . ' minute'  . ($m === 1 ? '' : 's') . ' ago'; }
    elseif ($diff < 86400) { $h = (int)floor($diff / 3600); $rel = $h . ' hour'    . ($h === 1 ? '' : 's') . ' ago'; }
    elseif ($diff < 172800){ $rel = 'Yesterday, ' . date('g:i A', $t); }
    elseif ($diff < 604800){ $rel = date('l, g:i A', $t); }
    else                   { $rel = date('j M Y, g:i A', $t); }
    return [$rel, date('l, j F Y \a\t g:i:s A', $t)];
}

include __DIR__ . '/includes/header.php';
?>
<?php include __DIR__ . '/includes/navigation.php'; ?>

<?php // .container is what every other admin page wraps its content in
      // (max-width + auto margins, from style.css); .audit-page only adds this
      // screen's own spacing on top of it. ?>
<main class="container audit-page">
  <header class="audit-head">
    <div>
      <h1><i class="fi fi-rr-time-past" aria-hidden="true"></i> Audit Logs</h1>
      <p class="audit-sub">
        What Managers have done on this side of the system &mdash; who did it, what
        they changed, and when. Includes work done here by an Owner. Newest first.
      </p>
    </div>
    <div class="audit-count">
      <strong><?= number_format($totalRows) ?></strong>
      <span><?= $totalRows === 1 ? 'entry' : 'entries' ?></span>
    </div>
  </header>

  <form class="audit-filters" method="get" action="">
    <div class="audit-field">
      <label for="af-area">Part of the business</label>
      <select id="af-area" name="area">
        <option value="">Everything</option>
        <?php foreach ($areaOptions as $area): ?>
          <option value="<?= htmlspecialchars($area) ?>" <?= $fArea === $area ? 'selected' : '' ?>><?= htmlspecialchars($area) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="audit-field">
      <label for="af-from">From</label>
      <input type="date" id="af-from" name="from" value="<?= htmlspecialchars($fFrom) ?>">
    </div>
    <div class="audit-field">
      <label for="af-to">To</label>
      <input type="date" id="af-to" name="to" value="<?= htmlspecialchars($fTo) ?>">
    </div>
    <div class="audit-actions">
      <button type="submit" class="action-btn">Apply</button>
      <a class="action-btn btn-muted" href="?">Clear</a>
    </div>
  </form>

  <?php if (!$rows): ?>
    <div class="audit-empty">
      <i class="fi fi-rr-search" aria-hidden="true"></i>
      <h2>Nothing to show</h2>
      <p>No Manager activity matches these filters. Try widening the date range or choosing a different part of the business.</p>
    </div>
  <?php else: ?>
    <ol class="audit-list">
      <?php foreach ($rows as $r):
        $d = audit_describe($r, $lookups);
        [$relTime, $exactTime] = audit_when((string)$r['created_at']);
      ?>
      <li class="audit-entry<?= $d['failed'] ? ' is-failed' : '' ?>">
        <span class="audit-icon" aria-hidden="true"><i class="<?= htmlspecialchars($d['icon']) ?>"></i></span>

        <div class="audit-body">
          <p class="audit-line">
            <strong class="audit-actor"><?= htmlspecialchars($d['actor_name']) ?></strong>
            <span class="audit-role"><?= htmlspecialchars($d['actor_role']) ?></span>
            <?php if ($d['acting_as'] !== null): ?>
              <?php // The requirement this page exists for: an Owner working
                    // through the Manager panel must be visible as the Owner. ?>
              <span class="audit-acting" title="This person was working from the <?= htmlspecialchars($d['acting_as']) ?> side of the system">
                <i class="fi fi-bs-user-gear" aria-hidden="true"></i>
                acting as <?= htmlspecialchars($d['acting_as']) ?>
              </span>
            <?php endif; ?>
            <?= htmlspecialchars($d['phrase']) ?><?php if ($d['subject'] !== null): ?>
              &mdash; <span class="audit-subject"><?= htmlspecialchars($d['subject']) ?></span>
            <?php endif; ?>
          </p>

          <?php if ($d['failed']): ?>
            <p class="audit-failed-note">
              <i class="fi fi-rr-exclamation" aria-hidden="true"></i>
              This did not go through&hellip; nothing was changed.
              <?php if (!empty($d['detail'])): ?><span><?= htmlspecialchars((string)$d['detail']) ?></span><?php endif; ?>
            </p>
          <?php elseif (!empty($d['detail'])): ?>
            <p class="audit-detail"><?= htmlspecialchars((string)$d['detail']) ?></p>
          <?php endif; ?>

          <?php if ($d['changes']): ?>
            <ul class="audit-changes">
              <?php foreach ($d['changes'] as $c): ?>
                <li>
                  <span class="audit-field-name"><?= htmlspecialchars($c['field']) ?></span>
                  <?php if ($c['from'] !== null): ?>
                    <span class="audit-from"><?= htmlspecialchars($c['from']) ?></span>
                    <i class="fi fi-rr-arrow-right audit-arrow" aria-hidden="true"></i>
                    <span class="audit-to"><?= htmlspecialchars($c['to']) ?></span>
                  <?php else: ?>
                    <span class="audit-to"><?= htmlspecialchars($c['to']) ?></span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <time class="audit-time" datetime="<?= htmlspecialchars((string)$r['created_at']) ?>" title="<?= htmlspecialchars($exactTime) ?>">
          <?= htmlspecialchars($relTime) ?>
        </time>
      </li>
      <?php endforeach; ?>
    </ol>

    <?php if ($totalPages > 1): ?>
      <nav class="audit-pager" aria-label="Audit log pages">
        <a class="action-btn btn-muted<?= $page <= 1 ? ' is-disabled' : '' ?>"
           href="<?= $page > 1 ? htmlspecialchars(audit_page_url($page - 1)) : '#' ?>">Previous</a>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
        <a class="action-btn btn-muted<?= $page >= $totalPages ? ' is-disabled' : '' ?>"
           href="<?= $page < $totalPages ? htmlspecialchars(audit_page_url($page + 1)) : '#' ?>">Next</a>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</main>

<style>
/* Scoped to .audit-page so these rules cannot reach the other admin screens.
   Colours come from the admin token set the rest of the panel uses, so this
   page follows the light/dark theme without defining a palette of its own. */
/* Vertical rhythm matches the other admin pages (main.container, padding:30px 0).
   The horizontal padding is this page's own: .container's 20px is not enough to
   clear the sidebar on its own - see the rail rule below. */
.audit-page { padding: 30px 20px 60px; }


.audit-head { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; flex-wrap:wrap; margin-bottom:22px; }
.audit-head h1 { display:flex; align-items:center; gap:10px; margin:0 0 6px; font-size:26px; font-weight:800; color:var(--admin-text); }
.audit-head h1 i { color:var(--accent-cyan); }
.audit-sub { margin:0; max-width:62ch; color:var(--admin-text-muted,#6b7280); font-size:14px; line-height:1.55; }
.audit-count { text-align:right; }
.audit-count strong { display:block; font-size:24px; font-weight:800; color:var(--admin-text); }
.audit-count span { font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--admin-text-muted,#6b7280); }

.audit-filters { display:flex; flex-wrap:wrap; gap:14px 16px; align-items:flex-end;
  padding:18px 20px; margin-bottom:22px; border-radius:var(--radius-md,14px);
  background:var(--admin-surface-2); border:1px solid var(--admin-border); }
.audit-field { display:flex; flex-direction:column; gap:6px; min-width:160px; flex:1 1 160px; }
.audit-field label { font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--admin-text-muted,#6b7280); }
.audit-field input, .audit-field select { padding:9px 12px; border-radius:10px; font-size:14px;
  border:1px solid var(--admin-border); background:var(--admin-surface); color:var(--admin-text); }
.audit-actions { display:flex; gap:8px; }

.audit-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:10px; }
.audit-entry { display:grid; grid-template-columns:40px 1fr auto; gap:14px; align-items:start;
  padding:16px 18px; border-radius:var(--radius-md,14px);
  background:var(--admin-surface-2); border:1px solid var(--admin-border); }
/* A failed attempt is marked structurally, not only in the wording, so it
   cannot be skim-read as a completed change. */
.audit-entry.is-failed { border-left:4px solid #dc2626; background:rgba(220,38,38,.05); }

.audit-icon { display:flex; align-items:center; justify-content:center; width:40px; height:40px;
  border-radius:50%; background:var(--admin-surface); border:1px solid var(--admin-border); }
.audit-icon i { font-size:16px; color:var(--accent-cyan); }
.audit-entry.is-failed .audit-icon i { color:#dc2626; }

.audit-body { min-width:0; }
.audit-line { margin:0; font-size:14.5px; line-height:1.6; color:var(--admin-text); }
.audit-actor { font-weight:800; }
.audit-role { display:inline-block; padding:1px 8px; margin:0 2px; border-radius:999px; font-size:11px;
  font-weight:700; text-transform:uppercase; letter-spacing:.04em;
  background:var(--admin-surface); border:1px solid var(--admin-border); color:var(--admin-text-muted,#6b7280); }
/* The "acting as" badge deliberately looks different from the plain role
   chip: it is the one thing on the row that changes who is accountable. */
.audit-acting { display:inline-flex; align-items:center; gap:5px; padding:1px 9px; margin:0 2px;
  border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.02em;
  background:rgba(217,119,6,.14); border:1px solid rgba(217,119,6,.45); color:#b45309; }
.audit-subject { font-weight:700; }

.audit-detail { margin:6px 0 0; font-size:13px; color:var(--admin-text-muted,#6b7280); }
.audit-failed-note { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin:8px 0 0;
  font-size:13px; font-weight:700; color:#b91c1c; }
.audit-failed-note span { font-weight:500; }

.audit-changes { list-style:none; margin:10px 0 0; padding:0; display:flex; flex-direction:column; gap:6px; }
.audit-changes li { display:flex; flex-wrap:wrap; align-items:center; gap:8px; font-size:13px; }
.audit-field-name { min-width:120px; font-weight:700; color:var(--admin-text-muted,#6b7280); }
.audit-from { padding:2px 8px; border-radius:6px; text-decoration:line-through;
  background:rgba(220,38,38,.10); color:#b91c1c; }
.audit-to { padding:2px 8px; border-radius:6px; background:rgba(16,185,129,.12); color:#047857; font-weight:600; }
.audit-arrow { font-size:11px; color:var(--admin-text-muted,#6b7280); }

.audit-time { font-size:12px; white-space:nowrap; color:var(--admin-text-muted,#6b7280); cursor:help; }

.audit-empty { text-align:center; padding:60px 20px; border-radius:var(--radius-md,14px);
  background:var(--admin-surface-2); border:1px dashed var(--admin-border); }
.audit-empty i { font-size:32px; color:var(--admin-text-muted,#6b7280); }
.audit-empty h2 { margin:12px 0 6px; font-size:18px; color:var(--admin-text); }
.audit-empty p { margin:0; color:var(--admin-text-muted,#6b7280); font-size:14px; }

.audit-pager { display:flex; align-items:center; justify-content:center; gap:16px; margin-top:24px; font-size:13px; color:var(--admin-text-muted,#6b7280); }
.audit-pager .is-disabled { opacity:.45; pointer-events:none; }

@media (max-width: 720px) {
  /* Narrow screens never get the rail, so no left clearance is needed here. */
  .audit-page { padding:20px 16px 48px; }
  /* The timestamp moves under the text rather than shrinking the one column
     that has to stay readable. */
  .audit-entry { grid-template-columns:32px 1fr; }
  .audit-icon { width:32px; height:32px; }
  .audit-time { grid-column:2; }
  .audit-field-name { min-width:0; width:100%; }
}

/* Clearing the sidebar.
   On a pointer device the drawer stops being a drawer: admin.css pins it open
   as a 72px rail at left:12px (its @media (hover:hover) and (pointer:fine)
   block), and it is position:fixed, so it OVERLAYS the page rather than
   reserving any space. Pages with narrow content get away with .container's
   auto margins centring them clear of it; this page's list is full-width, so
   the left edge of every entry ran underneath the rail.
   12px offset + 72px rail + 20px breathing room = 104px.

   Deliberately the LAST rule in this stylesheet. Nothing in admin.css turns
   the rail off below a given width, so a desktop window dragged narrower than
   720px still has it - and the max-width block above, having equal specificity,
   would otherwise win on source order and take the clearance away at exactly
   the width where the content is tightest. Touch devices never match this
   query, and there the sidebar is off-canvas until opened, so no space is
   reserved for it. */
@media (hover: hover) and (pointer: fine) {
  .audit-page { padding-left: 104px; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
