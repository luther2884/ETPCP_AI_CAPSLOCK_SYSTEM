<?php
// customer/settings.php - Account Settings: addresses, security, preferences.
// Split out from profile.php (which stays focused on activity + store
// credit) per explicit request - Settings is its own page/tab now, not a
// card glued onto Profile.
require_once __DIR__ . '/../../../config/functions.php';

require_login();
require_role(['customer']);

$pageTitle = 'Settings - TechWorld';
$activeNav = 'settings';
$userId = getUserId();

$addresses = [];
try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT id, full_name, phone, region, postal, street, label, is_default FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
    $stmt->execute([$userId]);
    $addresses = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Settings address list query failed: ' . $e->getMessage());
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
  <!-- Figtree for headings, JetBrains Mono for eyebrows and small-caps
       labels - the pair the landing page and the other redesigned customer
       pages load. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<section class="account-page set-page">
  <div class="container">
    <div class="account-header">
      <div class="cx-eyebrow"><span aria-hidden="true"></span>Account</div>
      <h1 class="cx-title">Settings</h1>
      <p class="cx-lede">Manage your addresses, security, and preferences</p>
    </div>
    <div class="cx-rule" aria-hidden="true"><i></i></div>

    <div class="settings-layout">

      <!-- My Addresses -->
      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
          <div>
            <h2>My Addresses</h2>
            <span class="hint">Used to prefill delivery details at checkout</span>
          </div>
          <button type="button" class="btn-primary" onclick="openAddressModal()">Add Address</button>
        </div>

        <div id="addressList" class="address-list">
          <?php if (empty($addresses)): ?>
            <p class="hint" style="margin:0;">No saved addresses yet.</p>
          <?php else: ?>
            <?php foreach ($addresses as $addr): ?>
              <div class="address-row" data-id="<?= (int)$addr['id'] ?>">
                <div class="address-row-info">
                  <strong><?= htmlspecialchars($addr['full_name']) ?></strong>
                  <?php if ((int)$addr['is_default'] === 1): ?><span class="badge-default">Default</span><?php endif; ?>
                  <span class="address-label"><?= htmlspecialchars($addr['label'] ?: 'Home') ?></span>
                  <p><?= htmlspecialchars($addr['phone']) ?></p>
                  <p><?= htmlspecialchars(trim($addr['street'] . ', ' . $addr['region'] . ' ' . $addr['postal'], ', ')) ?></p>
                </div>
                <div class="address-row-actions">
                  <?php if ((int)$addr['is_default'] !== 1): ?>
                    <button type="button" class="btn-outline" onclick="setDefaultAddress(<?= (int)$addr['id'] ?>)">Set Default</button>
                  <?php endif; ?>
                  <button type="button" class="btn-outline" onclick='openAddressModal(<?= json_encode($addr) ?>)'>Edit</button>
                  <button type="button" class="btn-danger" onclick="deleteAddress(<?= (int)$addr['id'] ?>)">Delete</button>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Security -->
      <div class="card">
        <div class="card-header">
          <h2>Security</h2>
          <span class="hint">Password and account protection</span>
        </div>
        <div class="form-actions" style="justify-content:flex-start;">
          <button type="button" class="btn-primary" onclick="openChangePassword()">Change Password</button>
        </div>
      </div>

      <!-- Preferences -->
      <div class="card">
        <div class="card-header">
          <h2>Preferences</h2>
          <span class="hint">Profile info and display options</span>
        </div>
        <div class="preferences-row">
          <div>
            <strong>Profile Information</strong>
            <p class="hint" style="margin:2px 0 0;">Name, email, phone, and photo</p>
          </div>
          <a href="<?= WEB_ROOT ?>public/authenticated/customer/edit_profile.php" class="btn-outline">Edit Profile</a>
        </div>
        <div class="preferences-row">
          <div>
            <strong>Theme</strong>
            <p class="hint" style="margin:2px 0 0;">Choose a theme, or follow your device</p>
          </div>
          <?php /* The three-way. aria-pressed reflects the stored CHOICE, not
                   the rendered mode, so "Match system" reads as selected even
                   though it paints as light or dark. theme.js owns the state;
                   the sidebar's two-up is the fast path for the same setting.
                   TODO: this is localStorage-only - `users` has no theme
                   column, so it does not follow a customer across devices.
                   See the TODO block in assets/js/theme.js. */ ?>
          <div class="tw-theme-choice" role="group" aria-label="Theme">
            <button type="button" data-theme-choice="light"  aria-pressed="false">Light</button>
            <button type="button" data-theme-choice="dark"   aria-pressed="false">Dark</button>
            <button type="button" data-theme-choice="system" aria-pressed="false">Match system</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- Address Modal (add/edit) -->
<div id="addressModal" class="modal-backdrop" style="display:none">
  <div class="modal-dialog">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
        <div>
          <h2 id="addressModalTitle">Add Address</h2>
          <span class="hint">Saved to your address book</span>
        </div>
        <button type="button" class="btn-outline" onclick="closeAddressModal()">Close</button>
      </div>
      <form class="form-grid" id="addressForm" onsubmit="return submitAddressForm(event)">
        <input type="hidden" id="addr_id" value="">
        <div class="form-field full">
          <label>Full Name</label>
          <input type="text" id="addr_full_name" required>
        </div>
        <div class="form-field">
          <label>Phone</label>
          <input type="text" id="addr_phone" required>
        </div>
        <div class="form-field">
          <label>Label</label>
          <input type="text" id="addr_label" placeholder="Home, Work, etc.">
        </div>
        <div class="form-field">
          <label>Region / City</label>
          <input type="text" id="addr_region" required>
        </div>
        <div class="form-field">
          <label>Postal Code</label>
          <input type="text" id="addr_postal">
        </div>
        <div class="form-field full">
          <label>Street Address</label>
          <input type="text" id="addr_street" required>
        </div>
        <div class="form-field full" style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" id="addr_is_default" style="width:16px;height:16px;">
          <label style="margin:0;" for="addr_is_default">Set as default address</label>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-primary">Save Address</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div id="changePwdModal" class="modal-backdrop" style="display:none">
  <div class="modal-dialog">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
        <div>
          <h2>Change Password</h2>
          <span class="hint">Keep your account secure</span>
        </div>
        <button type="button" class="btn-outline" onclick="closeChangePassword()">Close</button>
      </div>
      <form id="changePwdForm" class="form-grid" action="<?= WEB_ROOT ?>public/authenticated/api/change_password.php" method="post" onsubmit="return changePasswordSubmit(event)">
        <?= csrf_field() ?>
        <div class="form-field full">
          <label for="cpw_current">Current Password</label>
          <input type="password" id="cpw_current" name="current_password" autocomplete="current-password" placeholder="Enter current password" required>
        </div>
        <div class="form-field">
          <label for="cpw_new">New Password</label>
          <input type="password" id="cpw_new" name="new_password" autocomplete="new-password" minlength="8" placeholder="At least 8 characters" required>
        </div>
        <div class="form-field">
          <label for="cpw_confirm">Confirm New Password</label>
          <input type="password" id="cpw_confirm" name="confirm_password" autocomplete="new-password" minlength="8" placeholder="Re-enter new password" required>
        </div>
        <!-- Rejections land here, beside the fields. A toast alone meant the
             reason scrolled away from the form it was about. -->
        <div class="form-field full">
          <p id="cpw_error" class="pwd-error" hidden></p>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-primary" id="cpw_submit">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
/* ===========================================================================
   Settings, in the landing page's visual language.

   PRESENTATION ONLY. Every id and handler the script at the bottom uses is
   untouched: #addressModal / #changePwdModal (opened and closed by setting
   style.display), #addressForm, #addressModalTitle, the #addr_* fields,
   #addressList and .address-row[data-id], plus the inline onclick handlers
   on every button.

   The shared .cx-* primitives (eyebrow, title, rule) live in
   customer-dark.css, which also paints the ground for .account-page.

   What changed: this page was cream cards (customer-dark.css gives
   .settings-layout a warm-vanilla treatment) carrying neumorphic shadows
   built for a light ground. They are now the landing panel - a navy gradient
   over a 1px blue hairline - with mono labels and pill controls. The extra
   .set-page on the section is what lets these rules outrank that cream block
   without !important.
   ======================================================================== */
.account-page { padding:40px 0 64px; background: var(--page-bg-gradient); min-height:100vh; }
.set-page .container { max-width: 1180px; }
.account-header { text-align:left; margin-bottom:0; }

.settings-layout { max-width:900px; margin:0 auto; display:flex; flex-direction:column; gap:16px; }

/* --- cards ---------------------------------------------------------------- */
body .account-page.set-page .settings-layout .card {
  padding: 24px;
  border: 1px solid var(--cx-line);
  border-radius: 20px;
  background: var(--cx-panel);
  box-shadow: 0 20px 48px rgba(3, 8, 16, .4);
}
.card-header { margin-bottom: 18px; }
body .account-page.set-page .settings-layout .card-header h2 {
  display: flex; align-items: center; gap: 10px;
  margin: 0 0 7px;
  font-family: var(--cx-font);
  font-size: 18px; font-weight: 800; letter-spacing: -.01em;
  color: #fff;
}
/* The same accent bar the tracking page puts in front of its section titles,
   so the two converted customer pages read as one system. */
body .account-page.set-page .settings-layout .card-header h2::before {
  content: ''; flex: none;
  width: 3px; height: 17px; border-radius: 2px;
  background: linear-gradient(180deg, var(--cx-cyan), var(--cx-violet));
}
/* .hint was 10px uppercase mono tracked out to .16em - the mono small-caps
   treatment this design uses for LABELS. But every .hint on this page is a
   sentence ("Used to prefill delivery details at checkout", "Name, email,
   phone, and photo"), and a sentence in letterspaced caps at 10px is the one
   thing on the page that genuinely cannot be read - caps strip the word
   shapes the eye uses to skim, and the tracking pulls the words apart.
   Sentences get sentence case in the body font; the mono small-caps stay on
   the things that really are labels (field labels, badges, buttons). */
body .account-page.set-page .settings-layout .card-header .hint,
body .account-page.set-page .settings-layout .hint,
.hint {
  font-family: var(--cx-font);
  font-size: 13.5px; line-height: 1.5; letter-spacing: 0;
  text-transform: none;
  color: var(--cx-ink-dim);
}

/* --- address rows --------------------------------------------------------- */
.address-list { display:flex; flex-direction:column; gap:10px; }
body .account-page.set-page .settings-layout .address-row {
  display:flex; align-items:flex-start; justify-content:space-between;
  gap:14px; flex-wrap:wrap;
  padding: 16px 18px;
  border: 1px solid var(--cx-line);
  border-radius: 16px;
  background: var(--cx-panel-soft);
  transition: border-color .2s ease, transform .2s var(--cx-ease);
}
body .account-page.set-page .settings-layout .address-row:hover {
  transform: translateY(-1px);
  border-color: var(--cx-line-hi);
}
.address-row-info { min-width: 0; }
body .account-page.set-page .settings-layout .address-row-info strong {
  font-family: var(--cx-font); font-size: 16px; font-weight: 700; color: #fff;
}
/* The phone number and the street address ARE the content of this row - the
   thing you scan to check you are editing the right one - and they were the
   dimmest, smallest text in the card. Up to 14px and one tone brighter. */
body .account-page.set-page .settings-layout .address-row-info p {
  margin: 6px 0 0;
  font-size: 14px; line-height: 1.55; color: var(--cx-ink-dim);
}
body .account-page.set-page .settings-layout .address-label,
body .account-page.set-page .settings-layout .badge-default {
  display:inline-block; margin-left:8px; padding:4px 11px;
  border: 1px solid transparent; border-radius:999px;
  font-family: var(--cx-mono); font-size:10px; font-weight:700;
  letter-spacing:.12em; text-transform:uppercase;
  vertical-align: middle;
}
body .account-page.set-page .settings-layout .address-label {
  background: rgba(103, 232, 249, .12); color: var(--cx-cyan);
  border-color: rgba(103, 232, 249, .38);
}
body .account-page.set-page .settings-layout .badge-default {
  background: rgba(74, 222, 128, .12); color: var(--cx-green);
  border-color: rgba(74, 222, 128, .4);
}
.address-row-actions { display:flex; gap:8px; flex-wrap:wrap; }

/* --- preference rows ------------------------------------------------------ */
body .account-page.set-page .settings-layout .preferences-row {
  display:flex; align-items:center; justify-content:space-between;
  gap:14px; flex-wrap:wrap;
  padding:15px 0;
  border-bottom: 1px solid var(--cx-line);
}
.preferences-row:last-child { border-bottom:none; padding-bottom:0; }
body .account-page.set-page .settings-layout .preferences-row strong {
  font-family: var(--cx-font); font-size: 16px; font-weight: 700; color: #fff;
}

/* --- the three-way theme switch ------------------------------------------- */
/* The control ships from theme-light.css section 6 as a 10px-radius rectangle
   because it is also used in the sidebar. Inside this page it sat among pill
   buttons, so it is re-shaped here (scoped to .set-page, so the sidebar's copy
   is untouched) and its label size comes up to match the buttons beside it.
   The pressed state already reads --tw-brand, which is the shared gradient. */
body .account-page.set-page .settings-layout .tw-theme-choice button {
  min-height: 42px;
  padding: 11px 20px;
  border-radius: 999px;
  /* Three segments of a single control: without a visible edge the two
     unpressed options read as plain words next to the pressed pill, and it
     stops looking like a choice you can make. */
  border: 1px solid var(--tw-line-ctl, rgba(120, 170, 230, .55));
  background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04);
  color: var(--cx-ink-dim);
  font-family: var(--cx-mono); font-size: 10.5px; font-weight: 600;
  letter-spacing: .12em; text-transform: uppercase;
}
/* :not([aria-pressed="true"]) is load-bearing, not tidiness. This rule and the
   pressed rule below were at equal specificity, so source order decided - and
   the pressed rule only needs to miss ONE property for this one to fill the
   gap. That is exactly what happened in light: the pressed rule there set
   `color: #fff` and a shadow but no background, so this hover's near-white
   fill reached the active segment while its ink stayed white. 1.05:1 - the
   selected theme button vanished under the pointer.

   Making the two selectors mutually exclusive means they can never blend,
   whatever either one declares later. */
body .account-page.set-page .settings-layout .tw-theme-choice button:not([aria-pressed="true"]):hover {
  background: rgba(103, 232, 249, .12);
  border-color: rgba(103, 232, 249, .45);
  color: #fff;
}
body .account-page.set-page .settings-layout .tw-theme-choice button[aria-pressed="true"] {
  background: var(--tw-brand-grad);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 8px 20px rgba(3, 8, 16, .35);
}
/* The active segment still answers the pointer - by deepening, never
   brightening. See the note on .btn-primary:hover below. */
body .account-page.set-page .settings-layout .tw-theme-choice button[aria-pressed="true"]:hover {
  filter: saturate(1.08) brightness(0.92);
}

/* --- form fields ---------------------------------------------------------- */
.form-grid { display:grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap:16px; }
.form-grid .full { grid-column: 1 / -1; }
/* 9.5px caps at .2em, same problem as the tracking page's fact-plate labels:
   the colour was fine, the size was not. */
.form-field label {
  display:block; margin-bottom:7px;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:600;
  letter-spacing:.14em; text-transform:uppercase; color: var(--cx-ink-faint);
}
.form-field input {
  width:100%; box-sizing:border-box;
  padding:13px 16px;
  border: 1px solid var(--cx-line);
  border-radius: 12px;
  background: rgba(6, 13, 24, .65);
  color: #fff;
  font-family: var(--cx-font); font-size:15px;
  transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.form-field input::placeholder { color: var(--cx-ink-faint); }
.form-field input:focus {
  outline:none;
  border-color: rgba(103, 232, 249, .55);
  background: rgba(6, 13, 24, .8);
  box-shadow: 0 0 0 3px rgba(103, 232, 249, .12);
}
.form-actions { grid-column: 1 / -1; display:flex; gap:10px; justify-content:flex-end; margin-top:6px; }

/* --- controls ------------------------------------------------------------- */
/* One pill shape for every control on the page, coloured by role. */
body .account-page.set-page .settings-layout .btn-primary,
.modal-dialog .btn-primary,
.btn-primary {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:13px 24px;
  border:none; border-radius:999px;
  /* The shared brand gradient rather than this page's own pink. White on
     #e0567a measured 3.63:1 - under 4.5:1 for a 10.5px mono label, in dark as
     much as in light - which is exactly the failure --tw-brand-grad exists to
     fix (theme-light.css, debt item 5). The tracking page's Search button was
     already moved for the same reason; these three (Add Address, Change
     Password, and Save in the modal) were the stragglers. */
  background: var(--tw-brand-grad);
  color:#fff; cursor:pointer;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:700;
  letter-spacing:.14em; text-transform:uppercase;
  box-shadow: 0 10px 26px rgba(3, 8, 16, .35);
  transition: transform .2s var(--cx-ease), box-shadow .2s ease, filter .2s ease;
}
/* DEEPEN on hover, never brighten. --tw-brand-grad's first stop is #cf3f66,
   chosen because white measures 4.61:1 on it - i.e. it sits directly on the
   4.5 floor with no headroom at all. brightness(1.08) took that to 3.69:1, so
   the hover state of every primary button failed AA. brightness(0.92) moves
   the fill away from the ink instead: 5.08:1 worst case across both stops. */
body .account-page.set-page .settings-layout .btn-primary:hover,
.btn-primary:hover {
  transform: translateY(-2px);
  filter: saturate(1.08) brightness(0.92);
  box-shadow: 0 16px 34px rgba(3, 8, 16, .45);
}
.btn-primary:active { transform: scale(.97); }

body .account-page.set-page .settings-layout .btn-outline,
.modal-dialog .btn-outline,
.btn-outline {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:11px 20px;
  /* --tw-line-ctl, not --tw-line - see the token's note in theme-light.css.
     A 4% wash with a 1.2:1 edge is a label floating in space, not a button. */
  border: 1px solid var(--tw-line-ctl, rgba(120, 170, 230, .55));
  border-radius:999px;
  background: rgba(var(--tw-veil-rgb, 255, 255, 255), .04);
  color: var(--cx-ink-dim); cursor:pointer; text-decoration:none;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:600;
  letter-spacing:.12em; text-transform:uppercase;
  transition: transform .18s var(--cx-ease), background .18s ease,
              border-color .18s ease, color .18s ease;
}
body .account-page.set-page .settings-layout .btn-outline:hover:not(:disabled),
.btn-outline:hover:not(:disabled) {
  transform: translateY(-1px);
  background: rgba(103, 232, 249, .12);
  border-color: rgba(103, 232, 249, .45);
  color:#fff;
}
.btn-outline:disabled { opacity:.45; cursor:not-allowed; }

body .account-page.set-page .settings-layout .btn-danger,
.btn-danger {
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:11px 20px;
  /* .72 rather than the .4 this started at: the edge is what makes Delete
     read as a control, and it has to clear 3:1 on the panel like the others. */
  border: 1px solid rgba(224, 86, 122, .72);
  border-radius:999px;
  background: rgba(224, 86, 122, .1);
  color: var(--cx-pink-lt); cursor:pointer;
  font-family: var(--cx-mono); font-size:10.5px; font-weight:600;
  letter-spacing:.12em; text-transform:uppercase;
  transition: transform .18s var(--cx-ease), background .18s ease, border-color .18s ease, color .18s ease;
}
body .account-page.set-page .settings-layout .btn-danger:hover,
.btn-danger:hover {
  transform: translateY(-1px);
  background: rgba(224, 86, 122, .22);
  border-color: rgba(224, 86, 122, .65);
  color:#fff;
}

/* --- modals --------------------------------------------------------------- */
/* Shown and hidden by style.display in the script, so display is never set
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
  margin: 0 0 6px;
  font-family: var(--cx-font); font-size:19px; font-weight:800;
  letter-spacing:-.01em; color:#fff;
}
.modal-dialog .form-actions { justify-content:center; }
/* The "set as default" checkbox row carries an inline flex style. */
.modal-dialog .form-field input[type="checkbox"] { accent-color: var(--cx-cyan); }
.modal-dialog .form-field input[type="checkbox"] + label {
  font-family: var(--cx-mono); font-size:10.5px; letter-spacing:.12em;
  text-transform:uppercase; color: var(--cx-ink-dim);
}

/* Tokens, not raw hexes. #ffc9d6 on a 12% #e0567a wash is the DARK danger
   pairing; in light mode that is pale pink ink on a near-white panel (~1.3:1),
   so the one message in this form that has to be read would have been the one
   that disappeared. --tw-danger / -bg / -line flip with [data-mode] (#ffc2d3
   on a 14% wash dark, #a8264d on #fdecf1 light) and are the same triple the
   rest of the theme uses for errors. Fallbacks are the dark values, for any
   page that renders this modal without shared/css/theme-light.css. */
.pwd-error {
  margin: 0;
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--tw-danger-bg, rgba(224, 86, 122, .12));
  border: 1px solid var(--tw-danger-line, rgba(224, 86, 122, .45));
  color: var(--tw-danger, #ffc9d6);
  font-size: 12.5px;
  line-height: 1.5;
}
.pwd-error[hidden] { display: none; }

@media (max-width: 600px) {
  .form-grid { grid-template-columns: 1fr; }
  .address-row-actions { width: 100%; }
  body .account-page.set-page .settings-layout .card { padding: 18px; }
}
@media (prefers-reduced-motion: reduce) {
  .btn-primary:hover, .btn-outline:hover, .btn-danger:hover,
  body .account-page.set-page .settings-layout .address-row:hover { transform: none; }
}
</style>

<script>
function openChangePassword(){
  const m = document.getElementById('changePwdModal');
  if(m){ m.style.display = 'flex'; }
}
function closeChangePassword(){
  const m = document.getElementById('changePwdModal');
  if(m){ m.style.display = 'none'; }
  // Never leave a typed password sitting in the DOM behind a hidden modal.
  const f = document.getElementById('changePwdForm');
  if(f){ f.reset(); }
  setPwdError('');
}

function setPwdError(msg){
  const box = document.getElementById('cpw_error');
  if(!box){ return; }
  box.textContent = msg || '';
  box.hidden = !msg;
}

async function changePasswordSubmit(e){
  e.preventDefault();

  const current = document.getElementById('cpw_current').value;
  const next    = document.getElementById('cpw_new').value;
  const confirm = document.getElementById('cpw_confirm').value;

  // Checked here only to save a round trip - change_password.php re-checks
  // every one of these, since nothing typed in a browser is evidence.
  if(!current || !next || !confirm){ setPwdError('Please fill in all three password fields.'); return false; }
  if(next.length < 8){ setPwdError('Your new password must be at least 8 characters.'); return false; }
  if(next !== confirm){ setPwdError('The new passwords do not match.'); return false; }
  if(next === current){ setPwdError('Your new password must be different from your current one.'); return false; }
  setPwdError('');

  if(!(await uiConfirm('Are you sure you want to change your password?'))){
    return false;
  }

  const form = document.getElementById('changePwdForm');
  const btn  = document.getElementById('cpw_submit');
  const originalLabel = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Updating...';

  try {
    const res = await fetch(form.action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({
        csrf_token: form.querySelector('input[name="csrf_token"]').value,
        current_password: current,
        new_password: next,
        confirm_password: confirm
      })
    });
    // The endpoint answers with a real status code (403/422/429/500), so a
    // non-OK response still carries the message that explains it.
    const data = await res.json().catch(() => ({ success: false, message: 'Unexpected server response.' }));

    if (data && data.success) {
      showNotification(data.message || 'Your password has been updated.', 'success');
      closeChangePassword();
    } else {
      const msg = (data && data.message) || 'Could not change your password.';
      setPwdError(msg);
      showNotification(msg, 'error');
    }
  } catch (err) {
    setPwdError('Network error. Please check your connection and try again.');
    showNotification('Network error. Please try again.', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = originalLabel;
  }
  return false;
}

function openAddressModal(addr){
  document.getElementById('addressForm').reset();
  document.getElementById('addr_id').value = '';
  document.getElementById('addressModalTitle').textContent = 'Add Address';
  if (addr && typeof addr === 'object') {
    document.getElementById('addressModalTitle').textContent = 'Edit Address';
    document.getElementById('addr_id').value = addr.id || '';
    document.getElementById('addr_full_name').value = addr.full_name || '';
    document.getElementById('addr_phone').value = addr.phone || '';
    document.getElementById('addr_label').value = addr.label || '';
    document.getElementById('addr_region').value = addr.region || '';
    document.getElementById('addr_postal').value = addr.postal || '';
    document.getElementById('addr_street').value = addr.street || '';
    document.getElementById('addr_is_default').checked = !!(addr.is_default && Number(addr.is_default) === 1);
  }
  document.getElementById('addressModal').style.display = 'flex';
}
function closeAddressModal(){
  document.getElementById('addressModal').style.display = 'none';
}

function submitAddressForm(e){
  e.preventDefault();
  const id = document.getElementById('addr_id').value;
  const payload = {
    full_name: document.getElementById('addr_full_name').value.trim(),
    phone: document.getElementById('addr_phone').value.trim(),
    label: document.getElementById('addr_label').value.trim() || 'Home',
    region: document.getElementById('addr_region').value.trim(),
    postal: document.getElementById('addr_postal').value.trim(),
    street: document.getElementById('addr_street').value.trim(),
    is_default: document.getElementById('addr_is_default').checked ? 1 : 0
  };
  const action = id ? 'update' : 'create';
  const url = '<?= WEB_ROOT ?>public/authenticated/api/addresses.php?action=' + action + (id ? '&id=' + encodeURIComponent(id) : '');
  fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify(payload) })
    .then(r=>r.json())
    .then(d=>{
      if (d && d.success) {
        showNotification('Address saved.', 'success');
        setTimeout(()=>window.location.reload(), 600);
      } else {
        showNotification((d && d.message) || 'Failed to save address.', 'error');
      }
    })
    .catch(()=>showNotification('Request error.', 'error'));
  return false;
}

function setDefaultAddress(id){
  fetch('<?= WEB_ROOT ?>public/authenticated/api/addresses.php?action=set_default&id=' + encodeURIComponent(id), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r=>r.json())
    .then(d=>{
      if (d && d.success) { showNotification('Default address updated.', 'success'); setTimeout(()=>window.location.reload(), 500); }
      else { showNotification((d && d.message) || 'Failed to update.', 'error'); }
    })
    .catch(()=>showNotification('Request error.', 'error'));
}

async function deleteAddress(id){
  if (!(await uiConfirm('Delete this address?'))) return;
  fetch('<?= WEB_ROOT ?>public/authenticated/api/addresses.php?action=delete&id=' + encodeURIComponent(id), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r=>r.json())
    .then(d=>{
      if (d && d.success) { showNotification('Address deleted.', 'success'); setTimeout(()=>window.location.reload(), 500); }
      else { showNotification((d && d.message) || 'Failed to delete.', 'error'); }
    })
    .catch(()=>showNotification('Request error.', 'error'));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
