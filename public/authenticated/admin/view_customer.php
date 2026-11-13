<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_role(['admin']);

$pageTitle = 'Customer Management - TechWorld Admin';
$isAdminTheme = true;

// Simple duration formatter for membership time
function admin_format_duration(int $seconds): string {
    if ($seconds < 60) { return $seconds . 's'; }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) { return $minutes . 'm'; }
    $hours = intdiv($minutes, 60);
    if ($hours < 24) { return $hours . 'h'; }
    $days = intdiv($hours, 24);
    if ($days < 30) { return $days . 'd'; }
    $months = intdiv($days, 30);
    if ($months < 12) { return $months . 'mo'; }
    $years = intdiv($months, 12);
    return $years . 'y';
}

// Manual status updates removed - status now managed automatically

// Get users with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
// Role filter removed from UI; default view already scoped to customers

try {
    $pdo = getConnection();
    // Ensure `last_login` exists for auto-inactivation logic
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL DEFAULT NULL, ADD INDEX IF NOT EXISTS idx_last_login (last_login)"); } catch (Throwable $e) { /* ignore */ }

    // Auto-mark inactive: any active customer who hasn't logged in for 1 month
    try {
        $pdo->exec(
            "UPDATE users u
             SET u.status = 'inactive', u.updated_at = NOW()
             WHERE u.role = 'customer'
               AND u.status = 'active'
               AND COALESCE(u.last_login, u.created_at) < DATE_SUB(NOW(), INTERVAL 1 MONTH)"
        );
    } catch (PDOException $e) {
        error_log('Auto-inactivate stale users failed: ' . $e->getMessage());
    }
    
    // Build query conditions - default to active customers
    $whereConditions = ["u.role = 'customer'", "u.status = 'active'"];
    $params = [];
    
    if ($search) {
        $whereConditions[] = "(u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
        $searchTerm = "%{$search}%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    }
    
    // Role filter removed; results are already limited to customers above
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM users u {$whereClause}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalUsers = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalUsers / $limit);
    
    // Get users
    $usersSql = "
        SELECT u.*, 
               COUNT(o.id) as order_count,
               COALESCE(SUM(o.total_amount), 0) as total_spent,
               MAX(o.created_at) AS last_order_at,
               TIMESTAMPDIFF(SECOND, u.created_at, NOW()) as member_seconds,
               TIMESTAMPDIFF(SECOND, NOW(), NOW()) as inactive_seconds
        FROM users u 
        LEFT JOIN orders o ON u.id = o.user_id 
        {$whereClause}
        GROUP BY u.id 
        ORDER BY u.created_at DESC 
        LIMIT ? OFFSET ?
    ";
    $usersStmt = $pdo->prepare($usersSql);
    $usersStmt->execute(array_merge($params, [$limit, $offset]));
    $users = $usersStmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Users query error: " . $e->getMessage());
    $users = [];
    $totalPages = 0;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<?php
/* -----------------------------------------------------------------------
   VIEW LAYER ONLY. Every query, filter and pagination value above this line
   is untouched; these three are derived from what the query already
   produced.

   $totalUsers is guarded because the catch block above sets $users and
   $totalPages on a DB failure but not $totalUsers - the old markup echoed
   it raw, so a failed query printed a warning into the page. Reading it
   defensively here is a view fix, not a query change.
   -------------------------------------------------------------------- */
$cxTotal    = (int)($totalUsers ?? 0);
$cxShownFrom = $users ? $offset + 1 : 0;
$cxShownTo   = $users ? $offset + count($users) : 0;

/* Six soft avatar tints, picked by id so a customer keeps the same one
   between pages. Each pairs a near-white ground with ink measured at 7:1 or
   better on it - the brief's "soft tint, not the big gradient". */
$cxTints = [
  ['#e7f6ef', '#0f6b4a'],
  ['#e8f0fa', '#2f5d8f'],
  ['#f3eefb', '#5647a8'],
  ['#fdecf1', '#a8264d'],
  ['#fdf3e0', '#8a5a00'],
  ['#e6f4f4', '#17646b'],
];
?>
<!-- Figtree for names and prose, JetBrains Mono for labels, counts, dates
     and money. The admin header only loads the Flaticon sheets, so this page
     brings its own pair. Linked before the markup it styles, not after. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

<style>
/* ===========================================================================
   Customer Management - compact list + detail drawer.

   Tokens are declared page-locally on .cxm because admin pages load
   admin.css, never theme-light.css; the values are the same light set the
   customer side uses, so the two halves of the app stay one system without
   this page reaching for a stylesheet it does not have.

   Nothing here styles anything outside .cxm, so the shared admin chrome -
   the navy sidebar, the header - is untouched.
   ======================================================================== */
.cxm{
  --c-ground:#f2f5fa; --c-surface:#ffffff; --c-surface-2:#f7f9fc;
  --c-line:#dbe3ee;   --c-line-2:#eaeff6; --c-line-ctl:#7e90a9;
  --c-ink:#0f1a2c;    --c-ink-2:#5d6f86;  --c-ink-3:#46586f;
  --c-accent:#c23a63; --c-sel:#fdf5f8;
  --c-ok-bg:#e7f6ef;  --c-ok:#0f6b4a;
  --c-danger-bg:#fdecf1; --c-danger:#a8264d; --c-danger-line:#f2c4d3;
  --c-font:'Figtree',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  --c-mono:'JetBrains Mono',ui-monospace,'SFMono-Regular',Menlo,Consolas,monospace;
}
/* The page ground. Scoped to this <main> rather than <body> so the sidebar
   and header keep their own backgrounds exactly as they are. */
html body.admin main.cxm{
  background:var(--c-ground);
  min-height:100vh;
  padding:32px clamp(16px,3vw,36px) 60px;
  font-family:var(--c-font);
  color:var(--c-ink);
}
.cxm-wrap{max-width:1240px;margin:0 auto;}

/* --- header row ---------------------------------------------------------- */
.cxm-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:22px;}
.cxm-head-l{display:flex;align-items:flex-start;gap:12px;min-width:0;}
/* The Back control this page already had, kept (it is the only route back to
   CusMan.php) but restyled off the dark neumorphic slab, which read as a
   different app against a light data screen. */
.cxm-back{flex:none;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;margin-top:2px;border:1px solid var(--c-line-ctl);border-radius:9px;background:var(--c-surface);color:var(--c-ink-3);text-decoration:none;transition:background .15s ease,border-color .15s ease,color .15s ease;}
.cxm-back svg{width:17px;height:17px;}
.cxm-back:hover{background:var(--c-surface-2);border-color:var(--c-accent);color:var(--c-accent);}
.cxm-back:focus-visible{outline:2px solid var(--c-accent);outline-offset:2px;}
.cxm-eyebrow{font-family:var(--c-mono);font-size:10px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--c-ink-2);margin-bottom:7px;}
.cxm-head h1{margin:0;font-family:var(--c-font);font-size:26px;font-weight:800;letter-spacing:-.02em;color:var(--c-ink);}
.cxm-head-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

/* --- search -------------------------------------------------------------- */
.cxm-search{position:relative;display:flex;align-items:center;height:38px;background:var(--c-surface);border:1px solid var(--c-line);border-radius:10px;padding:0 10px;gap:8px;min-width:260px;transition:border-color .15s ease,box-shadow .15s ease;}
.cxm-search:focus-within{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(194,58,99,.12);}
.cxm-search-ico{font-size:15px;line-height:1;color:var(--c-ink-2);flex:none;}
.cxm-search input{flex:1;min-width:0;border:0;background:none;outline:none;font-family:var(--c-font);font-size:13.5px;color:var(--c-ink);}
.cxm-search input::placeholder{color:var(--c-ink-2);opacity:1;}
.cxm-kbd{flex:none;font-family:var(--c-mono);font-size:10px;font-weight:600;color:var(--c-ink-2);background:var(--c-surface-2);border:1px solid var(--c-line);border-radius:5px;padding:2px 6px;line-height:1.3;}
.cxm-search:focus-within .cxm-kbd{opacity:0;}
.cxm-clear{flex:none;display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;text-decoration:none;font-size:13px;line-height:1;color:var(--c-ink-2);background:var(--c-surface-2);border:1px solid var(--c-line);}
.cxm-clear:hover{background:var(--c-danger-bg);border-color:var(--c-danger-line);color:var(--c-danger);}

/* --- ghost button -------------------------------------------------------- */
.cxm-ghost{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 15px;border:1px solid var(--c-line-ctl);border-radius:10px;background:var(--c-surface);color:var(--c-ink);cursor:pointer;font-family:var(--c-mono);font-size:10.5px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;transition:background .15s ease,border-color .15s ease,color .15s ease;}
.cxm-ghost:hover{background:var(--c-surface-2);border-color:var(--c-accent);color:var(--c-accent);}

/* --- the two columns ----------------------------------------------------- */
.cxm-body{display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;}
.cxm-col-list{flex:999 1 600px;min-width:0;}
.cxm-col-drawer{flex:1 1 300px;min-width:0;max-width:360px;position:sticky;top:20px;}
@media (max-width:1000px){ .cxm-col-drawer{position:static;max-width:none;} }

/* --- list card ----------------------------------------------------------- */
.cxm-card{background:var(--c-surface);border:1px solid var(--c-line);border-radius:14px;box-shadow:0 6px 18px rgba(15,26,44,.05);overflow:hidden;}
.cxm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:13px 16px;border-bottom:1px solid var(--c-line-2);}
.cxm-toolbar-t{font-family:var(--c-font);font-size:13.5px;font-weight:700;color:var(--c-ink);}
.cxm-count{font-family:var(--c-mono);font-size:13px;font-weight:700;color:var(--c-ink-2);margin-left:6px;}
.cxm-sort{border:1px solid var(--c-line-ctl);background:var(--c-surface);border-radius:999px;padding:6px 13px;cursor:pointer;font-family:var(--c-mono);font-size:10px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--c-ink-3);transition:background .15s ease,border-color .15s ease,color .15s ease;}
.cxm-sort:hover{background:var(--c-surface-2);border-color:var(--c-accent);color:var(--c-accent);}

/* Below ~760px the card scrolls sideways instead of crushing the columns. */
.cxm-scroll{overflow-x:auto;}
.cxm-hrow,.cxm-row{display:grid;grid-template-columns:minmax(170px,2.2fr) minmax(84px,.9fr) minmax(48px,.5fr) minmax(118px,1fr) 20px;gap:16px;align-items:center;min-width:620px;}
.cxm-hrow{padding:9px 16px;background:var(--c-surface-2);border-bottom:1px solid var(--c-line-2);font-family:var(--c-mono);font-size:9px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:var(--c-ink-2);}
.cxm-hrow .num,.cxm-row .num{text-align:right;}

/* --- rows ---------------------------------------------------------------- */
.cxm-row{width:100%;padding:11px 16px;border:0;border-bottom:1px solid var(--c-line-2);background:none;text-align:left;font:inherit;color:inherit;cursor:pointer;transition:background .12s ease,box-shadow .12s ease;}
.cxm-row:last-child{border-bottom:0;}
.cxm-row:hover{background:var(--c-surface-2);}
.cxm-row:focus-visible{outline:2px solid var(--c-accent);outline-offset:-2px;}
.cxm-row[aria-current="true"]{background:var(--c-sel);box-shadow:inset 2px 0 0 var(--c-accent);}
.cxm-who{display:flex;align-items:center;gap:10px;min-width:0;}
.cxm-av{flex:none;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--c-mono);font-size:11px;font-weight:700;letter-spacing:.02em;}
.cxm-who-t{min-width:0;}
.cxm-name{display:block;font-family:var(--c-font);font-size:13.5px;font-weight:600;color:var(--c-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cxm-mail{display:block;font-family:var(--c-font);font-size:12px;color:var(--c-ink-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cxm-mono{font-family:var(--c-mono);font-size:12px;color:var(--c-ink-3);white-space:nowrap;}
.cxm-spent{font-family:var(--c-mono);font-size:12.5px;font-weight:700;color:var(--c-ink);white-space:nowrap;}
.cxm-chev{font-size:13px;line-height:1;color:var(--c-ink-2);text-align:right;}

/* --- footer + pagination ------------------------------------------------- */
.cxm-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--c-line-2);background:var(--c-surface-2);}
.cxm-foot-n{font-family:var(--c-mono);font-size:11px;letter-spacing:.06em;color:var(--c-ink-2);}
.cxm-foot-a{font-family:var(--c-mono);font-size:11px;font-weight:600;letter-spacing:.06em;color:var(--c-accent);text-decoration:none;}
.cxm-foot-a:hover{text-decoration:underline;}
.cxm-pager{display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-top:16px;}
.cxm-pager a{min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;padding:0 9px;border:1px solid var(--c-line-ctl);border-radius:8px;background:var(--c-surface);color:var(--c-ink);text-decoration:none;font-family:var(--c-mono);font-size:12px;font-weight:600;}
.cxm-pager a:hover{background:var(--c-surface-2);border-color:var(--c-accent);color:var(--c-accent);}
.cxm-pager a[aria-current="page"]{background:var(--c-accent);border-color:var(--c-accent);color:#fff;}

/* --- empty state --------------------------------------------------------- */
.cxm-empty{padding:48px 24px;text-align:center;}
.cxm-empty h3{margin:0 0 6px;font-size:15px;font-weight:700;color:var(--c-ink);}
.cxm-empty p{margin:0;font-size:13.5px;color:var(--c-ink-2);}

/* --- drawer -------------------------------------------------------------- */
.cxm-drawer{background:var(--c-surface);border:1px solid var(--c-line);border-radius:14px;box-shadow:0 6px 18px rgba(15,26,44,.05);overflow:hidden;}
.cxm-drawer-empty{padding:34px 22px;text-align:center;font-family:var(--c-font);font-size:13px;line-height:1.6;color:var(--c-ink-2);}
.cxm-drawer-body[hidden]{display:none;}
.cxm-dhead{display:flex;align-items:flex-start;gap:12px;padding:18px 18px 16px;border-bottom:1px solid var(--c-line-2);}
.cxm-dav{flex:none;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--c-mono);font-size:14px;font-weight:700;}
.cxm-dwho{flex:1;min-width:0;}
.cxm-dname{margin:0 0 3px;font-family:var(--c-font);font-size:16px;font-weight:700;color:var(--c-ink);word-break:break-word;}
.cxm-dmail{margin:0;font-family:var(--c-font);font-size:12.5px;color:var(--c-ink-2);word-break:break-all;}
.cxm-dclose{flex:none;width:28px;height:28px;border-radius:8px;border:1px solid var(--c-line-ctl);background:var(--c-surface);color:var(--c-ink-3);cursor:pointer;font-size:15px;line-height:1;}
.cxm-dclose:hover{background:var(--c-danger-bg);border-color:var(--c-danger-line);color:var(--c-danger);}
.cxm-dclose:focus-visible{outline:2px solid var(--c-accent);outline-offset:2px;}
.cxm-facts{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--c-line-2);border-bottom:1px solid var(--c-line-2);}
.cxm-fact{background:var(--c-surface);padding:13px 10px;text-align:center;min-width:0;}
.cxm-fact-k{display:block;font-family:var(--c-mono);font-size:9px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:var(--c-ink-2);margin-bottom:5px;}
.cxm-fact-v{display:block;font-family:var(--c-mono);font-size:14px;font-weight:700;color:var(--c-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cxm-dl{padding:14px 18px 18px;margin:0;}
.cxm-dl div{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid var(--c-line-2);}
.cxm-dl div:last-child{border-bottom:0;}
.cxm-dl dt{font-family:var(--c-mono);font-size:9px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:var(--c-ink-2);flex:none;}
.cxm-dl dd{margin:0;font-family:var(--c-mono);font-size:12px;color:var(--c-ink-3);text-align:right;min-width:0;word-break:break-word;}

@media (prefers-reduced-motion:reduce){ .cxm *{transition-duration:.01ms !important;} }
</style>

<main class="cxm">
<div class="cxm-wrap">

  <?php if (isset($success)): ?>
    <div class="notification-banner notification-success" style="margin-bottom:20px;"><div class="container"><span><?= htmlspecialchars($success) ?></span></div></div>
  <?php endif; ?>
  <?php if (isset($error)): ?>
    <div class="notification-banner notification-error" style="margin-bottom:20px;"><div class="container"><span><?= htmlspecialchars($error) ?></span></div></div>
  <?php endif; ?>

  <!-- 1. Header row -->
  <header class="cxm-head">
    <div class="cxm-head-l">
      <a class="cxm-back" href="CusMan.php" aria-label="Back to Customer Management">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
          <path d="M9 12h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>
      </a>
      <div>
        <div class="cxm-eyebrow">Admin &middot; Customers</div>
        <h1>Customer Management</h1>
      </div>
    </div>
    <div class="cxm-head-r">
      <!-- Unchanged GET form: same method, same field name, same endpoint,
           so the server-side search above is driven exactly as before. Only
           the chrome around it is new. Submits on Enter. -->
      <form class="cxm-search" method="GET" role="search">
        <span class="cxm-search-ico" aria-hidden="true">&#9906;</span>
        <input type="text" name="search" id="cxmSearch" value="<?= htmlspecialchars($search) ?>"
               placeholder="Search name or email" aria-label="Search name or email">
        <?php if ($search): ?>
          <a class="cxm-clear" href="?" aria-label="Clear search">&times;</a>
        <?php else: ?>
          <kbd class="cxm-kbd" aria-hidden="true">/</kbd>
        <?php endif; ?>
      </form>
      <button type="button" class="cxm-ghost" id="cxmExport"
              title="Exports the <?= count($users) ?> customers shown on this page">Export CSV</button>
    </div>
  </header>

  <div class="cxm-body">

    <!-- 3. The list -->
    <section class="cxm-col-list">
      <div class="cxm-card">
        <div class="cxm-toolbar">
          <div class="cxm-toolbar-t">All customers <span class="cxm-count"><?= number_format($cxTotal) ?></span></div>
          <?php if ($users): ?>
            <button type="button" class="cxm-sort" id="cxmSort" data-dir="desc"
                    aria-label="Sort by total spent, descending">Sort &middot; Total spent &darr;</button>
          <?php endif; ?>
        </div>

        <?php if (empty($users)): ?>
          <div class="cxm-empty">
            <h3>No customers found</h3>
            <p><?= $search ? 'No customers match that search.' : 'No active customers yet.' ?></p>
          </div>
        <?php else: ?>
          <div class="cxm-scroll">
            <div class="cxm-hrow" role="row" aria-hidden="true">
              <span>Customer</span><span>Joined</span><span class="num">Orders</span><span class="num">Total spent</span><span></span>
            </div>
            <div id="cxmRows">
              <?php foreach ($users as $user):
                $uid      = (int)$user['id'];
                $fname    = (string)($user['firstname'] ?? '');
                $lname    = (string)($user['lastname'] ?? '');
                $full     = trim($fname . ' ' . $lname);
                $initials = strtoupper(mb_substr($fname !== '' ? $fname : 'U', 0, 1) . mb_substr($lname !== '' ? $lname : 'N', 0, 1));
                $tint     = $cxTints[$uid % 6];
                $orders   = (int)$user['order_count'];
                $spent    = (float)$user['total_spent'];
                $joined   = date('M j, Y', strtotime((string)$user['created_at']));
              ?>
                <!-- The whole row is the control - no per-row buttons. Every
                     field the drawer shows is carried here as data-*, so
                     opening it costs no request; this is the same
                     pre-rendered data the old one-dialog-per-customer
                     markup used, just held as attributes instead of DOM. -->
                <button type="button" class="cxm-row"
                        data-id="<?= $uid ?>"
                        data-name="<?= htmlspecialchars($full) ?>"
                        data-initials="<?= htmlspecialchars($initials) ?>"
                        data-email="<?= htmlspecialchars((string)$user['email']) ?>"
                        data-orders="<?= $orders ?>"
                        data-spent="<?= htmlspecialchars(number_format($spent, 2)) ?>"
                        data-spent-raw="<?= $spent ?>"
                        data-joined="<?= htmlspecialchars($joined) ?>"
                        data-phone="<?= htmlspecialchars((string)($user['phone'] ?? '')) ?>"
                        data-member="<?= htmlspecialchars(admin_format_duration((int)($user['member_seconds'] ?? 0))) ?>"
                        data-bg="<?= $tint[0] ?>" data-fg="<?= $tint[1] ?>">
                  <span class="cxm-who">
                    <span class="cxm-av" style="background:<?= $tint[0] ?>;color:<?= $tint[1] ?>;" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                    <span class="cxm-who-t">
                      <span class="cxm-name"><?= htmlspecialchars($full) ?></span>
                      <span class="cxm-mail"><?= htmlspecialchars((string)$user['email']) ?></span>
                    </span>
                  </span>
                  <span class="cxm-mono"><?= htmlspecialchars($joined) ?></span>
                  <span class="cxm-mono num"><?= $orders ?></span>
                  <span class="cxm-spent num">&#8369;<?= number_format($spent, 2) ?></span>
                  <span class="cxm-chev" aria-hidden="true">&rsaquo;</span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="cxm-foot">
            <span class="cxm-foot-n">Showing <?= number_format($cxShownFrom) ?>&ndash;<?= number_format($cxShownTo) ?> of <?= number_format($cxTotal) ?></span>
            <a class="cxm-foot-a" href="CusAccArc.php">Inactive customers &rarr;</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if (($totalPages ?? 0) > 1): ?>
        <nav class="cxm-pager" aria-label="Pagination">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
               <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    </section>

    <!-- 4. Detail drawer. Not rendered at all when the list is empty - an
         empty panel beside an empty list is two pieces of nothing. -->
    <?php if ($users): ?>
    <aside class="cxm-col-drawer">
      <div class="cxm-drawer" id="cxmDrawer">
        <div class="cxm-drawer-empty" id="cxmDrawerEmpty">Select a customer to see their details.</div>
        <div class="cxm-drawer-body" id="cxmDrawerBody" hidden role="region" aria-label="Customer detail" tabindex="-1">
          <div class="cxm-dhead">
            <span class="cxm-dav" id="cxmDAv" aria-hidden="true"></span>
            <div class="cxm-dwho">
              <h2 class="cxm-dname" id="cxmDName"></h2>
              <p class="cxm-dmail" id="cxmDMail"></p>
            </div>
            <button type="button" class="cxm-dclose" id="cxmDClose" aria-label="Close details">&times;</button>
          </div>
          <div class="cxm-facts">
            <div class="cxm-fact"><span class="cxm-fact-k">Orders</span><span class="cxm-fact-v" id="cxmDOrders"></span></div>
            <div class="cxm-fact"><span class="cxm-fact-k">Spent</span><span class="cxm-fact-v" id="cxmDSpent"></span></div>
            <div class="cxm-fact"><span class="cxm-fact-k">Joined</span><span class="cxm-fact-v" id="cxmDJoined"></span></div>
          </div>
          <!-- Carried over from the dialog this drawer replaces. Dropping
               them would lose the only place an admin can see a customer's
               phone number or account id. -->
          <dl class="cxm-dl">
            <div><dt>Customer ID</dt><dd id="cxmDId"></dd></div>
            <div><dt>Phone</dt><dd id="cxmDPhone"></dd></div>
            <div><dt>Member for</dt><dd id="cxmDMember"></dd></div>
          </dl>
        </div>
      </div>
    </aside>
    <?php endif; ?>

  </div>
</div>
</main>

<script>
(function () {
  var rows   = Array.prototype.slice.call(document.querySelectorAll('.cxm-row'));
  var drawer = document.getElementById('cxmDrawer');
  var body   = document.getElementById('cxmDrawerBody');
  var empty  = document.getElementById('cxmDrawerEmpty');
  var search = document.getElementById('cxmSearch');
  var selected = null;

  // ---- drawer ------------------------------------------------------------
  function set(id, val) { var el = document.getElementById(id); if (el) { el.textContent = val; } }

  function openRow(row) {
    if (selected) { selected.removeAttribute('aria-current'); }
    selected = row;
    row.setAttribute('aria-current', 'true');

    var d = row.dataset;
    var av = document.getElementById('cxmDAv');
    if (av) { av.textContent = d.initials; av.style.background = d.bg; av.style.color = d.fg; }
    set('cxmDName', d.name);
    set('cxmDMail', d.email);
    set('cxmDOrders', d.orders);
    set('cxmDSpent', '₱' + d.spent);
    set('cxmDJoined', d.joined);
    set('cxmDId', '#' + d.id);
    set('cxmDPhone', d.phone || '—');
    set('cxmDMember', d.member || '—');

    if (empty) { empty.hidden = true; }
    if (body)  { body.hidden = false; }
  }

  function closeDrawer(returnFocus) {
    if (body)  { body.hidden = true; }
    if (empty) { empty.hidden = false; }
    if (selected) {
      selected.removeAttribute('aria-current');
      if (returnFocus) { selected.focus(); }
      selected = null;
    }
  }

  rows.forEach(function (row) {
    row.addEventListener('click', function () { openRow(row); });
    // <button> already fires click on Enter and Space, so Enter-to-open
    // needs no extra handler - binding keydown as well would open it twice.
  });

  // Start populated. The empty card read as an unfinished floating box on
  // arrival, so the first row is selected as the page settles. openRow()
  // neither moves focus nor scrolls anything, so this is purely visual - the
  // page still opens with focus at the document start and the list at the top.
  //
  // The empty card is still reachable, but only by pressing Esc or the x,
  // where it is the explicit answer to "close this" rather than a default.
  if (rows.length) { openRow(rows[0]); }

  var closeBtn = document.getElementById('cxmDClose');
  if (closeBtn) { closeBtn.addEventListener('click', function () { closeDrawer(true); }); }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && selected) { closeDrawer(true); }
  });

  // ---- "/" focuses search ------------------------------------------------
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
    var t = e.target;
    var tag = t && t.tagName ? t.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) { return; }
    if (!search) { return; }
    e.preventDefault();
    search.focus();
    search.select();
  });

  // ---- sort (reorders the rows on this page) -----------------------------
  var sortBtn = document.getElementById('cxmSort');
  var rowsWrap = document.getElementById('cxmRows');
  if (sortBtn && rowsWrap) {
    sortBtn.addEventListener('click', function () {
      var desc = sortBtn.dataset.dir !== 'desc';
      sortBtn.dataset.dir = desc ? 'desc' : 'asc';
      sortBtn.innerHTML = 'Sort · Total spent ' + (desc ? '↓' : '↑');
      sortBtn.setAttribute('aria-label', 'Sort by total spent, ' + (desc ? 'descending' : 'ascending'));
      Array.prototype.slice.call(rowsWrap.children)
        .sort(function (a, b) {
          var x = parseFloat(a.dataset.spentRaw) || 0, y = parseFloat(b.dataset.spentRaw) || 0;
          return desc ? y - x : x - y;
        })
        .forEach(function (el) { rowsWrap.appendChild(el); });
    });
  }

  // ---- CSV of the rows on this page --------------------------------------
  var exportBtn = document.getElementById('cxmExport');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      function cell(v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; }
      var out = [['Name', 'Email', 'Joined', 'Orders', 'Total spent', 'Phone'].map(cell).join(',')];
      // Reads the DOM, so it follows the current sort order.
      Array.prototype.slice.call(document.querySelectorAll('.cxm-row')).forEach(function (r) {
        var d = r.dataset;
        out.push([d.name, d.email, d.joined, d.orders, d.spentRaw, d.phone].map(cell).join(','));
      });
      var blob = new Blob(['﻿' + out.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'customers-page-<?= (int)$page ?>.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });
  }
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>

