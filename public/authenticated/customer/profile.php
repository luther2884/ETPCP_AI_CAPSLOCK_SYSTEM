<?php
// customer/profile.php - Profile page UI
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Profile - TechWorld';
$activeNav = 'profile';
$user = getUserData();
$userId = getUserId();

// Basic order stats for dashboard cards
$ordersTotal = 0; $ordersPending = 0; $ordersProcessing = 0; $ordersReady = 0; $ordersCompleted = 0; $ordersCancelled = 0;
try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE user_id = ? GROUP BY status");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $ordersTotal += (int)$r['cnt'];
        $status = $r['status'];
        if ($status === 'pending') $ordersPending = (int)$r['cnt'];
        elseif ($status === 'processing') $ordersProcessing = (int)$r['cnt'];
        elseif ($status === 'ready') $ordersReady = (int)$r['cnt'];
        elseif ($status === 'completed') $ordersCompleted = (int)$r['cnt'];
        elseif ($status === 'cancelled') $ordersCancelled = (int)$r['cnt'];
    }
} catch (Throwable $e) {
    error_log('Profile stats query failed: ' . $e->getMessage());
}

// Store credit balance + full ledger history - lives here (not the
// dashboard) since this is the account/settings page, and the sidebar's
// Settings link already pointed customers into this same page.
$storeCreditBalance = 0;
$storeCreditHistory = [];
try {
    $storeCreditBalance = getStoreCreditBalance($userId);
    $historyStmt = $pdo->prepare("SELECT type, amount, balance_after, source, notes, created_at FROM store_credits WHERE user_id = ? ORDER BY id DESC LIMIT 20");
    $historyStmt->execute([$userId]);
    $storeCreditHistory = $historyStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Profile store credit query failed: ' . $e->getMessage());
}

// Detect existing profile image - only if user actually has one
$profileImageRel = null;
if (function_exists('userHasAnyProfilePhoto') && userHasAnyProfilePhoto($userId)) {
    $profileImageRel = resolveProfilePhotoPath($userId);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php /* Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js
         is deferred and would run a frame after first paint, which is exactly
         the flash this prevents. Resolution order is localStorage -> OS
         preference -> dark. There is no server-rendered preference to fall
         back on yet - `users` has no theme column; see the TODO in theme.js. */ ?>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">
  <!-- Figtree for headings, JetBrains Mono for eyebrows, figures and
       small-caps labels - the pair the landing page and the other redesigned
       customer pages load. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<section class="account-page pro-page">
  <div class="container">
    <div class="account-header">
      <div class="cx-eyebrow"><span aria-hidden="true"></span>Account</div>
      <h1 class="cx-title">Profile</h1>
      <p class="cx-lede">Manage your personal information and account security</p>
    </div>
    <div class="cx-rule" aria-hidden="true"><i></i></div>

    <div class="profile-layout">
      <!-- Left: Profile Card -->
      <aside class="profile-card">
        <div class="avatar-lg" id="avatarContainer">
          <?php $initials = strtoupper(substr($user['firstname'],0,1) . substr($user['lastname'],0,1)); ?>
          <?php if ($profileImageRel): ?>
            <img id="profilePhoto" src="<?= WEB_ROOT . 'public/authenticated/' . $profileImageRel ?>" alt="Profile photo">
          <?php else: ?>
            <span id="profileInitials"><?= $initials ?></span>
          <?php endif; ?>
        </div>
        <!-- Photo actions removed on view-only profile; use Edit Profile page to change photo -->
        <h3 class="name"><?= htmlspecialchars($user['firstname'].' '.$user['lastname']) ?></h3>
        <p class="email"><?= htmlspecialchars($user['email']) ?></p>
        <span class="since">Member since <?= date('M Y', strtotime($user['created_at'])) ?></span>

        <div class="divider"></div>

        <div class="quick-info">
          <div>
            <span class="label">Role</span>
            <?php $roleLabel = ($user['role'] === 'customer') ? 'Member' : ucfirst(str_replace('-', ' ', $user['role'])); ?>
            <span class="value"><?= htmlspecialchars($roleLabel) ?></span>
          </div>
          <div>
            <span class="label">Status</span>
            <span class="value status-<?= htmlspecialchars($user['status']) ?>"><?= htmlspecialchars(ucfirst($user['status'])) ?></span>
          </div>
        </div>

        <div class="profile-actions">
          <a href="<?= WEB_ROOT ?>public/authenticated/customer/edit_profile.php" class="btn-primary">Edit Profile</a>
        </div>
      </aside>

      <!-- Right: Dashboard Cards -->
      <main class="profile-main">
        <div class="card">
          <div class="card-header">
            <h2>Your Activity</h2>
            <span class="hint">Quick glance at your account</span>
          </div>
          <div class="stats-grid">
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php">
              <div class="stat-value"><?= (int)$ordersTotal ?></div>
              <div class="stat-label">Total Orders</div>
            </a>
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php?status=pending">
              <div class="stat-value"><?= (int)$ordersPending ?></div>
              <div class="stat-label">Pending</div>
            </a>
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php?status=processing">
              <div class="stat-value"><?= (int)$ordersProcessing ?></div>
              <div class="stat-label">Processing</div>
            </a>
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php?status=ready">
              <div class="stat-value"><?= (int)$ordersReady ?></div>
              <div class="stat-label">Ready for Pickup</div>
            </a>
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php?status=completed">
              <div class="stat-value"><?= (int)$ordersCompleted ?></div>
              <div class="stat-label">Completed</div>
            </a>
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/orders.php?status=cancelled">
              <div class="stat-value"><?= (int)$ordersCancelled ?></div>
              <div class="stat-label">Cancelled</div>
            </a>
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/cart.php">
              <div class="stat-value" id="profileCartCount">—</div>
              <div class="stat-label">Cart Items</div>
            </a>
            <a class="stat-card" href="<?= WEB_ROOT ?>public/authenticated/customer/support.php">
              <div class="stat-value"><i class="fi fi-rr-headset"></i></div>
              <div class="stat-label">Support</div>
            </a>
          </div>
        </div>

        <!-- Store Credit - balance + ledger history. The sidebar's Store
             Credit link (includes/sidebar.php) points here
             (profile.php#storeCredit), so the id is a scroll anchor and has
             to stay. Nothing scripted reads anything inside this block, so
             the table of inline-styled cells it used to be is now real
             markup: a balance hero and a statement list. Every value is the
             same one it printed before - balance, date, description, signed
             amount, running balance - and the queries above are untouched. -->
        <div class="card sc-card" id="storeCredit">
          <div class="card-header">
            <h2>Store Credit</h2>
            <span class="hint">Balance from cancelled orders, ready to use on your next purchase</span>
          </div>

          <div class="sc-balance <?= $storeCreditBalance > 0 ? 'is-active' : 'is-empty' ?>">
            <div class="sc-balance__glow" aria-hidden="true"></div>
            <div class="sc-balance__mark" aria-hidden="true"><i class="fi fi-rr-wallet"></i></div>
            <div class="sc-balance__body">
              <span class="sc-balance__label">Available balance</span>
              <span class="sc-balance__figure"><?= formatCurrency($storeCreditBalance) ?></span>
            </div>
            <span class="sc-balance__state"><?= $storeCreditBalance > 0 ? 'Ready to use' : 'No balance yet' ?></span>
          </div>

          <?php if (empty($storeCreditHistory)): ?>
            <div class="sc-empty">
              <div class="sc-empty__mark" aria-hidden="true"><i class="fi fi-rr-receipt"></i></div>
              <p>No store credit activity yet.</p>
              <span>Credit from a cancelled order will appear here.</span>
            </div>
          <?php else: ?>
            <div class="sc-ledger">
              <div class="sc-ledger__head">
                <span>Activity</span>
                <span><?= count($storeCreditHistory) ?> <?= count($storeCreditHistory) === 1 ? 'entry' : 'entries' ?></span>
              </div>
              <ol class="sc-list">
                <?php foreach ($storeCreditHistory as $entry): ?>
                  <?php $isCredit = $entry['type'] === 'credit'; ?>
                  <li class="sc-entry <?= $isCredit ? 'is-credit' : 'is-debit' ?>">
                    <span class="sc-entry__icon" aria-hidden="true">
                      <i class="fi <?= $isCredit ? 'fi-rr-arrow-small-down' : 'fi-rr-arrow-small-up' ?>"></i>
                    </span>
                    <div class="sc-entry__body">
                      <span class="sc-entry__desc"><?= htmlspecialchars($entry['notes'] ?: ucfirst(str_replace('_', ' ', $entry['source']))) ?></span>
                      <span class="sc-entry__date"><?= date('M j, Y g:i A', strtotime($entry['created_at'])) ?></span>
                    </div>
                    <div class="sc-entry__figures">
                      <span class="sc-entry__amount"><?= $isCredit ? '+' : '-' ?><?= formatCurrency($entry['amount']) ?></span>
                      <span class="sc-entry__balance">Balance <?= formatCurrency($entry['balance_after']) ?></span>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ol>
            </div>
          <?php endif; ?>
        </div>

      </main>
    </div>
  </div>
</section>

<style>
/* ===========================================================================
   Profile, in the landing page's visual language.

   PRESENTATION ONLY. #avatarContainer, #profilePhoto, #profileInitials and
   #profileCartCount are all still here and still mean the same thing to the
   script below, and every stat tile still links where it did.

   The shared .cx-* primitives (eyebrow, title, rule) live in
   customer-dark.css, which also paints this page's ground via .account-page.

   What changed: the cards were warm vanilla (customer-dark.css gives
   .profile-layout that treatment) carrying neumorphic shadows built for a
   light ground. They are now the landing panel - a navy gradient over a 1px
   blue hairline - with mono labels, cyan and pink accents and the landing CTA
   gradient. The extra .pro-page on the section is what lets these rules
   outrank that vanilla block without !important.
   ======================================================================== */
.account-page { padding:40px 0 64px; background: var(--page-bg-gradient); min-height:100vh; }
.pro-page .container { max-width: 1240px; }
.account-header { text-align:left; margin-bottom:0; }

.profile-layout { display:grid; grid-template-columns: 330px minmax(0, 1fr); gap:18px; align-items:start; }

/* --- identity card -------------------------------------------------------- */
/* style.css lists .profile-card and .stat-card in its [data-theme="light"]
   block and forces them to `background:#ffffff !important; color:#0f172a
   !important` - a white card for a white page. This page sets data-theme
   "light" (includes/header.php) but is painted on the dark ground, and the
   rules below carried no !important, so the fill was overridden while the ink
   underneath it was not: the aside and the activity tiles came out white with
   white text on them, effectively blank. The specificity here already wins
   (0,4,1 vs 0,2,1); !important is added only to answer theirs. The explicit
   colour keeps any text that does not set its own from inheriting #0f172a. */
body .account-page.pro-page .profile-layout .profile-card {
  position: relative; overflow: hidden;
  height:fit-content; text-align:center;
  padding:30px 26px;
  border: 1px solid var(--cx-line) !important;
  border-radius: 22px;
  background: var(--cx-panel) !important;
  color: #fff !important;
  box-shadow: 0 20px 48px rgba(3, 8, 16, .4) !important;
}
/* A pink bloom behind the avatar, so the card has a light source. */
body .account-page.pro-page .profile-layout .profile-card::before {
  content: ''; position: absolute; left: 50%; top: -90px;
  width: 260px; height: 260px; transform: translateX(-50%);
  background: radial-gradient(circle, rgba(224, 86, 122, .22), transparent 66%);
  pointer-events: none;
}
.profile-card > * { position: relative; z-index: 1; }
.avatar-lg {
  width:112px; height:112px; margin:0 auto 16px;
  display:flex; align-items:center; justify-content:center; overflow:hidden;
  border-radius:50%;
  border: 2px solid rgba(255, 255, 255, .18);
  background: linear-gradient(140deg, var(--cx-pink), var(--cx-violet) 55%, var(--cx-cyan));
  color:#fff;
  font-family: var(--cx-font); font-size:38px; font-weight:800; letter-spacing:-.02em;
  box-shadow: 0 14px 36px rgba(224, 86, 122, .32);
}
.avatar-lg img { width:100%; height:100%; object-fit:cover; display:block; }
.photo-actions { display:flex; gap:8px; justify-content:center; margin-bottom:12px; }
body .account-page.pro-page .profile-layout .profile-card .name {
  margin:0 0 6px;
  font-family: var(--cx-font); font-size:20px; font-weight:800;
  letter-spacing:-.02em; color:#fff;
}
body .account-page.pro-page .profile-layout .profile-card .email {
  margin:0 0 10px; font-size:13px; color: var(--cx-ink-faint); overflow-wrap:anywhere;
}
body .account-page.pro-page .profile-layout .profile-card .since {
  display:inline-block; padding:5px 13px;
  border: 1px solid rgba(103, 232, 249, .35); border-radius:999px;
  background: rgba(103, 232, 249, .1);
  font-family: var(--cx-mono); font-size:9px; font-weight:600;
  letter-spacing:.16em; text-transform:uppercase; color: var(--cx-cyan);
}
.profile-card .divider {
  height:1px; margin:22px 0;
  background: linear-gradient(90deg, rgba(120,170,230,0), rgba(var(--tw-line-rgb, 120, 170, 230), .3), rgba(var(--tw-line-rgb, 120, 170, 230), 0));
}
.quick-info { display:flex; flex-direction:column; gap:10px; text-align:left; }
.quick-info > div {
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  padding:12px 15px;
  border: 1px solid var(--cx-line); border-radius:14px;
  background: var(--cx-panel-soft);
}
body .account-page.pro-page .profile-layout .quick-info .label {
  display:block;
  font-family: var(--cx-mono); font-size:9px; font-weight:600;
  letter-spacing:.2em; text-transform:uppercase; color: var(--cx-ink-label);
}
body .account-page.pro-page .profile-layout .quick-info .value {
  font-family: var(--cx-font); font-size:14px; font-weight:700; color:#fff;
}
body .account-page.pro-page .profile-layout .quick-info .value.status-active { color: var(--cx-green); }
body .account-page.pro-page .profile-layout .quick-info .value.status-inactive { color: var(--cx-pink-lt); }
.profile-actions { margin-top:18px; display:flex; justify-content:center; }
.profile-actions .btn-primary { width:100%; }

/* --- right-hand cards ----------------------------------------------------- */
body .account-page.pro-page .profile-layout .card,
body .account-page.pro-page .profile-main .card {
  margin-bottom:16px; padding:24px;
  border: 1px solid var(--cx-line);
  border-radius: 20px;
  background: var(--cx-panel);
  box-shadow: 0 20px 48px rgba(3, 8, 16, .4);
}
.card-header { margin-bottom:18px; }
body .account-page.pro-page .profile-layout .card-header h2 {
  margin:0 0 6px;
  font-family: var(--cx-font); font-size:18px; font-weight:800;
  letter-spacing:-.01em; color:#fff;
}
body .account-page.pro-page .profile-layout .card-header .hint,
body .account-page.pro-page .profile-layout .hint,
.hint {
  font-family: var(--cx-mono); font-size:10px; letter-spacing:.16em;
  text-transform:uppercase; color: var(--cx-ink-label); line-height:1.7;
}

/* --- activity tiles ------------------------------------------------------- */
.stats-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap:12px; }
body .account-page.pro-page .profile-layout .stat-card {
  position:relative; overflow:hidden;
  padding:18px;
  border: 1px solid var(--cx-line) !important;
  border-radius:16px;
  background: var(--cx-panel-soft) !important;
  color: #fff !important;
  text-decoration:none;
  box-shadow:none !important;
  transition: transform .2s var(--cx-ease), border-color .2s ease, box-shadow .2s ease, background .2s ease;
}
/* Fixed-size bloom over the whole tile. The old box (80% wide, offset off
   the right edge) was still tinted where it ended, so overflow:hidden cut it
   into a visible vertical edge - the same seam fixed on support.php. */
body .account-page.pro-page .profile-layout .stat-card::after {
  content:''; position:absolute; inset:0;
  background: radial-gradient(180px 140px at 88% 0%, rgba(103,232,249,.14), transparent 70%);
  pointer-events:none;
}
body .account-page.pro-page .profile-layout .stat-card:hover {
  transform: translateY(-3px);
  background: rgba(103, 232, 249, .07) !important;
  border-color: rgba(103, 232, 249, .42) !important;
  box-shadow: 0 16px 34px rgba(3, 8, 16, .45) !important;
}
body .account-page.pro-page .profile-layout .stat-value {
  margin-bottom:8px;
  font-family: var(--cx-font); font-size:28px; font-weight:800;
  letter-spacing:-.02em; line-height:1; color:#fff;
}
body .account-page.pro-page .profile-layout .stat-card .stat-value i { font-size:24px; color: var(--cx-cyan); }
body .account-page.pro-page .profile-layout .stat-label {
  font-family: var(--cx-mono); font-size:9px; font-weight:600;
  letter-spacing:.2em; text-transform:uppercase; color: var(--cx-ink-label);
}

/* --- store credit --------------------------------------------------------- */
/* The balance hero. One figure, given the room to actually be the headline of
   the section, on the same pink-to-cyan gradient the landing page reserves for
   its accent word. */
.sc-balance {
  position: relative; overflow: hidden;
  display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
  margin-bottom: 22px;
  padding: 24px 26px;
  border: 1px solid rgba(103, 232, 249, .28);
  border-radius: 20px;
  background:
    linear-gradient(135deg, rgba(224, 86, 122, .12), rgba(103, 232, 249, .08) 60%, transparent),
    var(--cx-panel-soft);
}
/* A slow aurora behind the figure, so the panel has a light source rather
   than a flat tint. */
.sc-balance__glow {
  position: absolute; left: -10%; top: -120%;
  width: 56%; height: 320%;
  background: radial-gradient(circle, rgba(224, 86, 122, .3), transparent 62%);
  filter: blur(26px);
  pointer-events: none;
  animation: sc-drift 18s ease-in-out infinite alternate;
}
@keyframes sc-drift {
  from { transform: translateX(0) scale(1); }
  to   { transform: translateX(58%) scale(1.14); }
}
.sc-balance__mark {
  position: relative; z-index: 1;
  width: 56px; height: 56px; flex: none;
  display: grid; place-items: center;
  border: 1px solid rgba(103, 232, 249, .38);
  border-radius: 18px;
  background: rgba(6, 13, 24, .5);
  font-size: 22px; color: var(--cx-cyan);
}
.sc-balance__body { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 0; }
.sc-balance__label {
  font-family: var(--cx-mono); font-size: 9.5px; font-weight: 600;
  letter-spacing: .22em; text-transform: uppercase; color: var(--cx-ink-faint);
}
.sc-balance__figure {
  font-family: var(--cx-font);
  font-size: clamp(30px, 4.4vw, 42px); font-weight: 900;
  letter-spacing: -.03em; line-height: 1;
  background: linear-gradient(100deg, var(--cx-pink-lt), var(--cx-cyan));
  -webkit-background-clip: text; background-clip: text;
  color: transparent;
  overflow-wrap: anywhere;
}
.sc-balance__state {
  position: relative; z-index: 1;
  padding: 6px 14px;
  border: 1px solid rgba(74, 222, 128, .4); border-radius: 999px;
  background: rgba(74, 222, 128, .1);
  font-family: var(--cx-mono); font-size: 9px; font-weight: 600;
  letter-spacing: .18em; text-transform: uppercase; color: var(--cx-green);
  white-space: nowrap;
}
/* At zero the panel stops celebrating: the glow, the gradient figure and the
   green pill all stand down. */
.sc-balance.is-empty { border-color: var(--cx-line); background: var(--cx-panel-soft); }
.sc-balance.is-empty .sc-balance__glow { display: none; }
.sc-balance.is-empty .sc-balance__mark { border-color: var(--cx-line); color: var(--cx-ink-label); }
.sc-balance.is-empty .sc-balance__figure { background: none; -webkit-text-fill-color: currentColor; color: var(--cx-ink-dim); }
.sc-balance.is-empty .sc-balance__state { border-color: var(--cx-line); background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04); color: var(--cx-ink-label); }

/* --- the statement -------------------------------------------------------- */
.sc-ledger__head {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding-bottom: 12px; margin-bottom: 4px;
  border-bottom: 1px solid var(--cx-line);
  font-family: var(--cx-mono); font-size: 9px; font-weight: 600;
  letter-spacing: .22em; text-transform: uppercase; color: var(--cx-ink-label);
}
.sc-list { list-style: none; margin: 0; padding: 0; }
.sc-entry {
  position: relative;
  display: grid; grid-template-columns: 38px minmax(0, 1fr) auto;
  align-items: center; gap: 15px;
  padding: 15px 14px;
  border: 1px solid transparent; border-radius: 16px;
  transition: background .2s var(--cx-ease), border-color .2s ease, transform .2s var(--cx-ease);
}
.sc-entry:hover {
  transform: translateX(3px);
  border-color: var(--cx-line);
  background: var(--cx-panel-soft);
}
/* A hairline rail through the icon discs turns the list into one continuous
   statement instead of separate rows. */
.sc-entry:not(:last-child)::before {
  content: ''; position: absolute; z-index: 0;
  left: 32px; top: calc(50% + 19px); bottom: -8px; width: 2px;
  background: var(--cx-line);
}
.sc-entry__icon {
  position: relative; z-index: 1;
  width: 38px; height: 38px;
  display: grid; place-items: center;
  border: 1px solid transparent; border-radius: 50%;
  font-size: 15px;
}
.is-credit .sc-entry__icon { border-color: rgba(74, 222, 128, .4); background: rgba(74, 222, 128, .12); color: var(--cx-green); }
.is-debit  .sc-entry__icon { border-color: rgba(224, 86, 122, .42); background: rgba(224, 86, 122, .13); color: var(--cx-pink-lt); }
.sc-entry__body { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.sc-entry__desc {
  font-family: var(--cx-font); font-size: 14px; font-weight: 600;
  color: #fff; overflow-wrap: anywhere;
}
.sc-entry__date {
  font-family: var(--cx-mono); font-size: 10px; letter-spacing: .1em;
  color: var(--cx-ink-label);
}
.sc-entry__figures { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; text-align: right; }
/* The sign carries the meaning, so it keeps a colour of its own - moved off
   the old #22c55e/#ef4444 onto the page's own green and pink. */
.sc-entry__amount {
  font-family: var(--cx-mono); font-size: 15px; font-weight: 700;
  letter-spacing: .01em; white-space: nowrap;
}
.is-credit .sc-entry__amount { color: var(--cx-green); }
.is-debit  .sc-entry__amount { color: var(--cx-pink-lt); }
.sc-entry__balance {
  font-family: var(--cx-mono); font-size: 10px; letter-spacing: .1em;
  color: var(--cx-ink-label); white-space: nowrap;
}

/* --- nothing to show yet -------------------------------------------------- */
.sc-empty {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 40px 24px; text-align: center;
  border: 1px dashed rgba(var(--tw-line-rgb, 120, 170, 230), .26);
  border-radius: 18px;
  background: rgba(var(--tw-veil-rgb, 255, 255, 255), .02);
}
.sc-empty__mark {
  width: 56px; height: 56px; margin-bottom: 6px;
  display: grid; place-items: center;
  border: 1px solid var(--cx-line); border-radius: 50%;
  background: rgba(6, 13, 24, .5);
  font-size: 22px; color: var(--cx-ink-label);
}
.sc-empty p { margin: 0; font-family: var(--cx-font); font-size: 15px; font-weight: 700; color: #fff; }
.sc-empty span {
  font-family: var(--cx-mono); font-size: 10px; letter-spacing: .14em;
  text-transform: uppercase; color: var(--cx-ink-label);
}

@media (max-width: 560px) {
  .sc-balance { gap: 14px; padding: 20px; }
  .sc-balance__state { order: 3; }
  .sc-entry { grid-template-columns: 38px minmax(0, 1fr); row-gap: 10px; }
  .sc-entry__figures { grid-column: 2; align-items: flex-start; text-align: left; }
  .sc-entry:not(:last-child)::before { display: none; }
}

/* --- controls ------------------------------------------------------------- */
body .account-page.pro-page .profile-layout .btn-primary,
.modal-dialog .btn-primary,
.btn-primary {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:14px 24px;
  border:none; border-radius:999px;
  background: linear-gradient(120deg, var(--cx-pink), #c2185b);
  color:#fff; cursor:pointer; text-decoration:none;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:700;
  letter-spacing:.16em; text-transform:uppercase;
  box-shadow: 0 10px 26px rgba(224, 86, 122, .3);
  transition: transform .2s var(--cx-ease), box-shadow .2s ease, background .2s ease;
}
body .account-page.pro-page .profile-layout .btn-primary:hover,
.btn-primary:hover {
  transform: translateY(-2px);
  background: linear-gradient(120deg, var(--cx-pink-lt), var(--cx-pink));
  box-shadow: 0 16px 34px rgba(224, 86, 122, .44);
}
.btn-primary:active { transform: scale(.97); }
.btn-outline {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:11px 20px;
  border: 1px solid var(--cx-line); border-radius:999px;
  background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04);
  color: var(--cx-ink-dim); cursor:pointer; text-decoration:none;
  font-family: var(--cx-mono); font-size:10px; font-weight:600;
  letter-spacing:.14em; text-transform:uppercase;
  transition: transform .18s var(--cx-ease), background .18s ease, border-color .18s ease, color .18s ease;
}
.btn-outline:hover { transform: translateY(-1px); background: rgba(103,232,249,.12); border-color: rgba(103,232,249,.45); color:#fff; }
.btn-danger {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:11px 20px;
  border: 1px solid rgba(224, 86, 122, .4); border-radius:999px;
  background: rgba(224, 86, 122, .1);
  color: var(--cx-pink-lt); cursor:pointer;
  font-family: var(--cx-mono); font-size:10px; font-weight:600;
  letter-spacing:.14em; text-transform:uppercase;
  transition: background .18s ease, border-color .18s ease, color .18s ease;
}
.btn-danger:hover { background: rgba(224, 86, 122, .22); border-color: rgba(224, 86, 122, .65); color:#fff; }

/* --- form fields (used by the modals below) ------------------------------- */
.form-grid { display:grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap:16px; }
.form-grid .full { grid-column: 1 / -1; }
.form-field label {
  display:block; margin-bottom:7px;
  font-family: var(--cx-mono); font-size:9.5px; font-weight:600;
  letter-spacing:.2em; text-transform:uppercase; color: var(--cx-ink-label);
}
.form-field input, .form-field textarea {
  width:100%; box-sizing:border-box;
  padding:13px 16px;
  border: 1px solid var(--cx-line); border-radius:12px;
  background: rgba(6, 13, 24, .65); color:#fff;
  font-family: var(--cx-font); font-size:14px;
  transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.form-field textarea { resize:vertical; }
.form-field input:focus, .form-field textarea:focus {
  outline:none;
  border-color: rgba(103, 232, 249, .55);
  background: rgba(6, 13, 24, .8);
  box-shadow: 0 0 0 3px rgba(103, 232, 249, .12);
}
.form-actions { grid-column: 1 / -1; display:flex; gap:10px; justify-content:flex-end; margin-top:6px; }

/* --- modals --------------------------------------------------------------- */
/* Opened and closed by style.display in the scripts, so display is never set
   here - only the look. */
.modal-backdrop {
  position: fixed; inset: 0; z-index: 1000;
  align-items:center; justify-content:center;
  background: rgba(3, 8, 16, .72);
  -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
}
.modal-dialog { width: min(600px, 92vw); }
.modal-dialog .card {
  padding: 26px;
  border: 1px solid var(--cx-line);
  border-radius: 22px;
  background: radial-gradient(900px 500px at 70% -20%, #16283f 0%, #0a1424 50%, #060d18 100%);
  box-shadow: 0 40px 90px rgba(0, 0, 0, .6);
}
.modal-dialog .card-header h2 {
  margin:0 0 6px;
  font-family: var(--cx-font); font-size:19px; font-weight:800;
  letter-spacing:-.01em; color:#fff;
}
.modal-dialog .form-actions { justify-content:center; }

@media (max-width: 992px) { .profile-layout { grid-template-columns: 1fr; } }
@media (max-width: 600px) {
  .form-grid { grid-template-columns: 1fr; }
  body .account-page.pro-page .profile-layout .card,
  body .account-page.pro-page .profile-layout .profile-card { padding: 18px; }
}
@media (prefers-reduced-motion: reduce) {
  body .account-page.pro-page .profile-layout .stat-card:hover,
  .btn-primary:hover, .btn-outline:hover, .sc-entry:hover { transform: none; }
  .sc-balance__glow { animation: none; }
}
</style>

<script>
function previewSelectedPhoto(input){
  const file = input.files && input.files[0];
  if(!file) return;
  if(file.size > 3 * 1024 * 1024){ showNotification('Image too large. Max 3MB.', 'error'); input.value=''; return; }
  const url = URL.createObjectURL(file);
  let img = document.getElementById('profilePhoto');
  const initialsEl = document.getElementById('profileInitials');
  if(!img){ img = document.createElement('img'); img.id='profilePhoto'; document.getElementById('avatarContainer').innerHTML=''; document.getElementById('avatarContainer').appendChild(img); }
  if(initialsEl) initialsEl.remove();
  img.src = url;
  const saveBtn = document.getElementById('savePhotoBtn');
  if(saveBtn){ saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
}

// Auto-upload immediately after selection for better UX
function handlePhotoSelected(input){
  previewSelectedPhoto(input);
  // Small delay to ensure preview renders before upload starts
  setTimeout(()=>{ uploadProfilePhoto(new Event('submit')); }, 50);
}

function setHeaderAvatar(url, initials){
  const buildImg = function(src){ const im = document.createElement('img'); im.alt='Profile'; if(typeof src === 'string'){ im.src = src; } im.decoding='async'; im.loading='eager'; return im; };
  const buildSpan = function(text){ const sp = document.createElement('span'); sp.textContent = text || ''; return sp; };
  const updateEl = function(el){ if(!el) return; while(el.firstChild){ el.removeChild(el.firstChild); } if(url && typeof url === 'string'){ el.appendChild(buildImg(url)); } else { el.appendChild(buildSpan(initials||'')); } };
  updateEl(document.getElementById('avatarToggle'));
  updateEl(document.querySelector('#auth-section .header-avatar'));
  document.querySelectorAll('.header-avatar').forEach(updateEl);
}

function uploadProfilePhoto(e){
  e.preventDefault();
  const form = document.getElementById('photoForm');
  const file = document.getElementById('photoInput').files[0];
  if(!file){ document.getElementById('photoInput').click(); return false; }
  const fd = new FormData(form);
  fetch(form.action, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r=>r.json())
    .then(d=>{
      if(d && d.success && typeof d.path === 'string'){
        const base = (window.WEB_ROOT || '<?= WEB_ROOT ?>') + 'public/authenticated/';
        const path = d.path ? (base + d.path) : null;
        const persistedUrl = path ? (path + (path.indexOf('?') === -1 ? '?t=' : '&t=') + Date.now()) : null;
        // Update large avatar preview with persisted image path
        const img = document.getElementById('profilePhoto');
        if(img && persistedUrl){ img.src = persistedUrl; }
        // Update header avatar (strong, direct DOM update + event broadcast)
        if(persistedUrl){
          setHeaderAvatar(persistedUrl);
          window.dispatchEvent(new CustomEvent('profile-photo-updated', { detail: { url: persistedUrl } }));
          setTimeout(()=>{ setHeaderAvatar(persistedUrl); }, 120);
        }

        // Reset Save button state
        const saveBtn = document.getElementById('savePhotoBtn');
        if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = 'Saved'; setTimeout(()=>{ saveBtn.textContent='Save'; }, 1500); }

        showNotification('Profile photo updated.', 'success');
      }
      else { showNotification((d && d.message) || 'Upload failed.', 'error'); }
    })
    .catch(()=>showNotification('Upload error.','error'));
  return false;
}

async function removeProfilePhoto(){
  if(!(await uiConfirm('Remove your profile photo?'))) return;
  const form = document.getElementById('photoForm');
  const fd = new FormData();
  fd.append('action','remove');
  fetch(form.action, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r=>r.json())
    .then(d=>{
      if(d.success){
        // Revert to initials
        const container = document.getElementById('avatarContainer');
        container.innerHTML = '<span id="profileInitials"><?= $initials ?></span>';
        // Also update header avatar to initials
        setHeaderAvatar(null, '<?= $initials ?>');
        // Reset Save button state
        const saveBtn = document.getElementById('savePhotoBtn');
        if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = 'Save'; }
        showNotification('Profile photo removed.','success');
      } else {
        showNotification(d.message || 'Remove failed.','error');
      }
    })
    .catch(()=>showNotification('Request error.','error'));
}

// Fetch live cart count
document.addEventListener('DOMContentLoaded', function(){
  fetch('<?= WEB_ROOT ?>public/authenticated/api/cart.php?action=count', { headers: { 'X-Requested-With':'XMLHttpRequest' }})
    .then(r=>r.json()).then(d=>{ if(d.success && typeof d.count !== 'undefined'){ const el=document.getElementById('profileCartCount'); if(el) el.textContent=d.count; }})
    .catch(()=>{});
});

function scrollToEdit(e){
  e.preventDefault();
  const el = document.getElementById('editProfile');
  if(!el) return;
  el.scrollIntoView({ behavior:'smooth', block:'start' });
  // brief highlight
  el.style.boxShadow = '0 0 0 2px rgba(167,118,147,0.5)';
  setTimeout(()=>{ el.style.boxShadow=''; }, 1200);
}

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

