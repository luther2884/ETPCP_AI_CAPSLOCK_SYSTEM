<?php
// includes/sidebar.php - persistent left navigation panel (hamburger drawer).
// Replaces the old top nav bar (includes/navigation.php) and the
// dashboard-only sidebar - this single component is included on every
// customer-facing page so it's identical wherever you navigate. The toggle
// button lives here (not in includes/header.php) as a pull-tab fixed to the
// screen edge: it shares the sidebar's transform/transition so it visually
// slides along with the panel, like pulling a tab out of a folder.
if (!function_exists('getUserId')) { require_once __DIR__ . '/../../../../config/functions.php'; }

$__sbUid = function_exists('getUserId') ? getUserId() : ($_SESSION['user_id'] ?? null);
$__sbAvatarRel = null;
if (!empty($_SESSION['profile_photo_rel'])) {
    $__sbAvatarRel = $_SESSION['profile_photo_rel'];
} elseif ($__sbUid && function_exists('resolveProfilePhotoPath')) {
    if (function_exists('userHasAnyProfilePhoto') && userHasAnyProfilePhoto($__sbUid)) {
        $__sbAvatarRel = resolveProfilePhotoPath($__sbUid);
        if ($__sbAvatarRel) { $_SESSION['profile_photo_rel'] = $__sbAvatarRel; }
    }
}
$__sbInitials = strtoupper(substr($_SESSION['firstname'] ?? 'U', 0, 1) . substr($_SESSION['lastname'] ?? 'N', 0, 1));
$__sbName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
$__sbRole = $_SESSION['user_role'] ?? 'customer';
// isLoggedIn() (session user_id) is the canonical auth check used by
// require_login() throughout this app - $_SESSION['logged_in'] is a
// separate, less consistently-set flag and isn't safe to gate on here.
$__sbLoggedIn = function_exists('isLoggedIn') ? isLoggedIn() : isset($_SESSION['user_id']);
$__sbUnreadCount = ($__sbUid && function_exists('getUnreadNotificationCount')) ? (int)getUnreadNotificationCount($__sbUid) : 0;
// Store credit balance - shown in every page's sidebar (not just the
// dashboard) so it's actually discoverable instead of only showing up at
// checkout or in a one-time notification. Only relevant for customers.
$__sbStoreCredit = ($__sbUid && $__sbRole === 'customer' && function_exists('getStoreCreditBalance')) ? getStoreCreditBalance($__sbUid) : 0.0;
?>

<?php /* ---------------------------------------------------------------
     STYLESHEETS FIRST, THEN THE MARKUP. The order of these two blocks is
     load-bearing, not cosmetic.

     This <link> and <style> used to sit BELOW the panel markup, after
     </aside>. Every stylesheet the customer area uses is inside <body>
     already (see the note at the top of header.php), and a stylesheet only
     blocks rendering of the content that comes AFTER it - so the ~125 lines
     of sidebar markup above it were parsed and painted with no rules at all
     whenever the sheet was not already warm in cache. That is the reload
     people saw 'sometimes': the .ico masthead and every <img class=
     "nav-icon-gif"> drawn at natural size, the search field as a raw
     browser input, the brand row and theme toggle as bare text. The inline
     the root background in header.php had already removed the white canvas
     behind it, which is why the flash was dark but still garbled rather than
     simply blank. (That rule is now theme-aware - it was #060d18 in both
     modes, which was itself the black flash on light-mode navigation.)

     Moving them above the markup means the browser has the rules before it
     has anything to draw, so there is no unstyled frame to catch.

     What must NOT change: theme-light.css still has to land after
     style.css and customer-dark.css (both linked in header.php, which is
     included before this file) so it can beat their tokens on source order
     - see that file's own header. It does: header.php runs first. And this
     <style> still follows the <link>, exactly as before.

     The behaviour <script> at the bottom of this file stays where it is -
     it calls getElementById on the panel, so it has to run after it.
     -------------------------------------------------------------------- */ ?>
<?php /* The theme system. theme-light.css is linked here rather than in
         header.php so it lands AFTER style.css and customer-dark.css and can
         beat their tokens on specificity alone - see that file's header for
         why it redeclares customer-dark.css's 54 names at
         html[data-mode="light"] body. The anti-FOUC half is already inline
         on every page; this script only owns the controls. */ ?>
<link rel="stylesheet" href="<?= asset_url('public/shared/css/theme-light.css') ?>">
<script src="<?= asset_url('public/shared/assets/js/theme.js') ?>" defer></script>
<style>
/* Pull-tab toggle: fixed to the screen edge, aligned with the header (its
   "top" is set by JS to the header's vertical center, not the page middle),
   and sharing the sidebar's own transform + transition so the two move as
   one piece - the tab rides along the sidebar's edge as it slides
   open/closed, like pulling a tab out of a folder. Sits above both the
   backdrop (1999) and the sidebar (2000) so it's never covered mid-drag. */
.customer-menu-toggle{position:fixed;top:40px;left:0;z-index:2001;width:34px;height:56px;border-radius:0 14px 14px 0;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-left:none;box-shadow:4px 0 16px rgba(0,0,0,.25);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;transform:translateY(-50%);transition:transform 160ms var(--ease-drawer,cubic-bezier(0.32,0.72,0,1)),background 160ms ease}
@media (hover:hover) and (pointer:fine){.customer-menu-toggle:hover{background:rgba(255,255,255,.22)}}
.customer-menu-toggle:active{transform:translateY(-50%) scale(0.95)}
body.customer-menu-open .customer-menu-toggle{transform:translateY(-50%) translateX(min(280px,85vw))}
body.customer-menu-open .customer-menu-toggle:active{transform:translateY(-50%) translateX(min(280px,85vw)) scale(0.95)}
.customer-menu-toggle-bar{width:16px;height:2px;border-radius:2px;background:#fff;transition:transform 200ms var(--ease-in-out,cubic-bezier(0.77,0,0.175,1)),opacity 160ms var(--ease-out,cubic-bezier(0.23,1,0.32,1))}
.customer-menu-toggle[aria-expanded="true"] .customer-menu-toggle-bar:nth-child(1){transform:translateY(6px) rotate(45deg)}
.customer-menu-toggle[aria-expanded="true"] .customer-menu-toggle-bar:nth-child(2){opacity:0}
.customer-menu-toggle[aria-expanded="true"] .customer-menu-toggle-bar:nth-child(3){transform:translateY(-6px) rotate(-45deg)}
@media (prefers-reduced-motion: reduce){.customer-menu-toggle,.customer-menu-toggle-bar{transition-duration:.01ms!important}}

.customer-sidebar-backdrop{position:fixed;inset:0;z-index:1999;background:rgba(0,0,0,.5);opacity:0;pointer-events:none;transition:opacity 220ms ease}
body.customer-menu-open .customer-sidebar-backdrop{opacity:1;pointer-events:auto}
/* Dark-navy sidebar. Was a light neumorphic panel (--bg-card #eef0f1 etc,
   the site's shared light-mode tokens) sitting next to the now-dark
   landing page. Every rule below this point already reads --bg-card/
   --text-primary/--text-muted/--border-color/--neu-raised(-sm)/--neu-
   pressed via var(...) - redefining those tokens LOCALLY on .customer-
   sidebar (not at :root, so nothing else sitewide changes - other pages'
   cards, the admin sidebar, etc. keep the original light values) makes
   every existing button/search/profile/submenu rule resolve to dark-navy
   automatically, without rewriting each one individually.
   Three tiers, one color family: page (#0A1F33) < this panel (#0B2135,
   the same navy-2 the page's own gradient already ends on) < buttons/
   search/profile (#123655, lighter still, reusing the site's existing
   --accent-blue #174871 hue rather than inventing a new one) - so buttons
   read as "raised" off the panel the same way they did in the light
   version, just recolored. The neumorphic light/dark shadow pair is
   re-tuned too: the original (a near-white 0.9-alpha highlight) was
   designed for a light card and would look like a glitch on navy, so this
   uses a soft dark shadow + a much fainter light-side highlight instead. */
.customer-sidebar{
  --bg-card: #123655;
  --bg-card-hover: #17436A;
  --text-primary: #F2F2F3;
  --text-muted: #9FB0C3;
  --border-color: rgba(255,255,255,.12);
  --neu-shadow-dark: rgba(0,0,0,.5);
  --neu-shadow-light: rgba(255,255,255,.05);
  --neu-raised: 5px 5px 10px var(--neu-shadow-dark), -5px -5px 10px var(--neu-shadow-light);
  --neu-raised-sm: 3px 3px 6px var(--neu-shadow-dark), -3px -3px 6px var(--neu-shadow-light);
  --neu-pressed: inset 3px 3px 6px var(--neu-shadow-dark), inset -3px -3px 6px var(--neu-shadow-light);
  position:fixed;top:12px;left:12px;bottom:12px;z-index:2000;width:260px;max-width:85vw;display:flex;flex-direction:column;background:var(--cs-slab,#0B2135);border:1px solid var(--cs-slab-line,rgba(255,255,255,.08));border-radius:16px;box-shadow:var(--cs-slab-shadow,0 8px 28px rgba(0,0,0,.4));overflow:hidden;padding:18px;transform:translateX(-120%);transition:transform 160ms var(--ease-drawer,cubic-bezier(0.32,0.72,0,1))}
body.customer-menu-open .customer-sidebar{transform:translateX(0)}
body.customer-menu-open{overflow:hidden}
html{scrollbar-gutter:stable}
/* Active nav item: the pressed-shadow alone reads faintly on a dark
   surface (inset dark-on-dark is subtler than the light version's
   inset light-on-light), so the active row also gets a soft mauve tint -
   the same accent already used for its text/icon-glow elsewhere on the
   page, not a new color, and applied ONLY to .active so the rest of the
   buttons stay neutral navy. */
.customer-sidebar-nav a.active,.customer-sidebar-link.active{background:rgba(139,76,108,.22)!important}
.customer-sidebar-brand{display:flex;align-items:center;gap:10px;padding-bottom:16px;margin-bottom:12px;border-bottom:1px solid var(--border-color,rgba(255,255,255,.1));text-decoration:none;flex-shrink:0}
.customer-sidebar-logo{width:38px;height:38px;flex-shrink:0}
.customer-sidebar-brand-text{color:var(--text-primary,#17324a);font-size:15px;font-weight:800}
/* Appearance switch in the brand row.
   theme-light.css declares .tw-appearance-icon as display:none and only
   reveals it inside the collapsed rail, so this has to opt back in - and with
   enough specificity to win, since that sheet is linked AFTER this <style>.
   #id + two classes clears both its base rule and its rail rule. */
#customerSidebar .customer-sidebar-brand .tw-appearance-icon{
  display:inline-flex;margin:0;width:30px;height:30px;font-size:14px;flex-shrink:0;
}
/* The word sits left of the button and takes the slack, so the pair is pushed
   to the right edge as one unit - margin-left:auto moved here from the button
   for that reason. It names the incoming theme, same as the glyph. */
#customerSidebar .customer-sidebar-brand .tw-appearance-label{
  margin-left:auto;font-size:11px;font-weight:600;letter-spacing:.04em;
  color:var(--text-muted,#6b7f96);white-space:nowrap;
}

/* Search - same neumorphic pill treatment as .customer-sidebar-nav a below,
   just with a submit button + text input inside instead of a link, so it
   reads as part of the same list rather than a bolted-on header leftover.
   Same hover/active/focus-swap language too (raised-on-hover, pressed-in
   while you're actually typing) - the base pill matched nav items already,
   but sitting static with no reaction on hover/focus was what still made
   it read as a plain input bolted on rather than one of the buttons. */
.customer-sidebar-search{display:flex;align-items:center;gap:10px;padding:9px 10px;margin-bottom:12px;border-radius:var(--radius-sm,10px);background:var(--bg-card,#eef0f1);box-shadow:var(--neu-raised-sm);flex-shrink:0;transition:box-shadow var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1))}
.customer-sidebar-search:hover{box-shadow:var(--neu-raised)}
.customer-sidebar-search:focus-within{box-shadow:var(--neu-pressed)}
.customer-sidebar-search-icon{display:flex;align-items:center;justify-content:center;width:20px;height:20px;flex-shrink:0;background:none;border:none;padding:0;margin:0;color:var(--text-muted,#94a3b8);font-size:14px;cursor:pointer;transition:color var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1))}
.customer-sidebar-search:focus-within .customer-sidebar-search-icon{color:var(--accent-cyan,#a05f80)}
.customer-sidebar-search-input{flex:1;min-width:0;border:none;background:transparent;outline:none;font:inherit;font-size:13.5px;color:var(--text-primary,#17324a)}
.customer-sidebar-search-input::placeholder{color:var(--text-muted,#94a3b8)}
@media (prefers-reduced-motion: reduce){.customer-sidebar-search,.customer-sidebar-search-icon{transition-duration:.01ms!important}}

/* overflow-x:hidden explicitly - leaving it unset while overflow-y:auto is
   set makes the browser auto-resolve overflow-x to auto too (per the CSS
   overflow spec), so the 2px hover translateX() on nav links below (a
   later, purely decorative "lift toward the cursor" polish) was enough to
   trigger a real horizontal scrollbar on hover, since the link's box then
   extended 2px past this container's own edge. Nothing here is meant to
   scroll sideways, so hidden is correct, not just a workaround. */
.customer-sidebar-nav{position:relative;flex:1;min-height:0;overflow-y:auto;overflow-x:hidden}
/* The list still scrolls (wheel, touch, keyboard) - only the bar itself is
   hidden. It was the browser's default light one, a white stripe down a
   #0B2135 panel, and no amount of colouring it made it belong there. */
.customer-sidebar-nav{scrollbar-width:none;-ms-overflow-style:none}
.customer-sidebar-nav::-webkit-scrollbar{width:0;height:0;display:none}
/* Store credit balance pill - same neumorphic button treatment as the nav
   links, with a mauve accent (icon + border + amount text) so it reads as
   "your money," not just another nav item. .has-balance adds a soft glow
   only when there's actually something to see, so a ₱0.00 state stays
   visually quiet. */
/* gap:12px, not 10 - the nav links below use 12, so at 10 the Store Credit
   icon's label started 2px left of every other label in the list and the row
   read as slightly out of column. */
.customer-sidebar-credit{display:flex;align-items:center;gap:12px;padding:9px 10px;margin-bottom:12px;border-radius:var(--radius-sm,10px);color:var(--text-primary,#17324a);text-decoration:none;font-size:14px;font-weight:600;background:var(--bg-card,#eef0f1);border:1px solid rgba(139,76,108,.3);box-shadow:var(--neu-raised-sm);flex-shrink:0;transition:transform var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1)),box-shadow var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1))}
.customer-sidebar-credit i{font-size:16px;color:var(--accent-cyan,#a05f80);flex-shrink:0;width:20px;text-align:center}
.customer-sidebar-credit:hover{background:var(--bg-card-hover,#17436A);box-shadow:var(--neu-raised),0 0 10px rgba(23,114,190,.25);transform:translateX(2px)}
.customer-sidebar-credit:active{transform:scale(0.97)}
.customer-sidebar-credit.has-balance{border-color:rgba(179,123,152,.6);box-shadow:var(--neu-raised-sm),0 0 10px rgba(179,123,152,.3)}
.customer-sidebar-credit-amount{margin-left:auto;color:var(--accent-cyan,#a05f80);font-weight:800;white-space:nowrap}
@media (prefers-reduced-motion: reduce){.customer-sidebar-credit{transition-duration:.01ms!important}}

.customer-sidebar-profile{display:flex;align-items:center;gap:10px;padding-top:16px;padding-bottom:8px;margin-top:12px;border-top:1px solid var(--border-color,rgba(255,255,255,.1));flex-shrink:0;text-decoration:none;color:inherit;border-radius:var(--radius-sm,10px);transition:background var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1))}
.customer-sidebar-profile:hover{background:var(--bg-card-hover,#e6e9eb)}
.customer-sidebar-profile:active{transform:scale(0.98)}
@media (prefers-reduced-motion: reduce){.customer-sidebar-profile{transition-duration:.01ms!important}}
.customer-sidebar-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--accent-cyan,#a77693),var(--accent-blue,#174871));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;overflow:hidden;flex-shrink:0}
.customer-sidebar-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.customer-sidebar-identity{min-width:0;display:flex;flex-direction:column;gap:1px}
.customer-sidebar-identity strong{color:var(--text-primary,#e0e6ed);font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.customer-sidebar-identity span{color:var(--text-muted,#94a3b8);font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.customer-sidebar-nav ul,.customer-sidebar-fixed-list{list-style:none;margin:0;padding:0}
.customer-sidebar-nav li,.customer-sidebar-fixed-list li{margin-bottom:8px}
.customer-sidebar-section-label{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted,#94a3b8);margin:14px 6px 8px}
/* Neumorphic menu items: raised pill by default, raises further on hover,
   presses inward (shadow swap) for the active/current page - same system
   admin.css uses for the admin/super-admin sidebars, so all three panels
   share one visual language. .customer-sidebar-link is the same treatment
   for links that live outside .customer-sidebar-nav (the fixed Manager/
   Owner/Security block above Logout). */
.customer-sidebar-nav a,.customer-sidebar-link{position:relative;display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:var(--radius-sm,10px);color:var(--text-primary,#17324a);text-decoration:none;font-size:14px;font-weight:600;background:var(--bg-card,#eef0f1);border:1px solid transparent;box-shadow:var(--neu-raised-sm);transition:transform var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1)),box-shadow var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1)),color var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1)),border-color var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1))}
.nav-icon-gif{width:20px;height:20px;object-fit:contain;flex-shrink:0;opacity:.82;filter:brightness(.9);transition:opacity var(--duration-fast,150ms) ease,filter var(--duration-fast,150ms) ease}
.customer-sidebar-nav a:hover .nav-icon-gif,.customer-sidebar-link:hover .nav-icon-gif,.customer-sidebar-nav a.active .nav-icon-gif,.customer-sidebar-link.active .nav-icon-gif{opacity:1;filter:brightness(1.08)}
/* AI Builder's nav row uses a flaticon glyph (no matching GIF icon exists
   yet in shared/img/icons) - sized/centered to match the 20px GIF icons it
   sits beside in this same list, both collapsed and expanded. */
.customer-sidebar-nav a i.fi-rr-microchip{width:20px;flex-shrink:0;text-align:center;font-size:16px;opacity:.82;transition:opacity var(--duration-fast,150ms) ease}
.customer-sidebar-nav a:hover i.fi-rr-microchip,.customer-sidebar-nav a.active i.fi-rr-microchip{opacity:1}
/* Slight lift + a very soft blue glow on hover so buttons feel responsive
   without turning into a bright/solid highlight. */
.customer-sidebar-nav a:hover,.customer-sidebar-link:hover{background:var(--bg-card-hover,#17436A);box-shadow:var(--neu-raised),0 0 10px rgba(23,114,190,.25);transform:translateX(2px)}
.customer-sidebar-nav a:active,.customer-sidebar-link:active{transform:scale(0.97)}
/* Active page: mauve/pink accent border + soft glow layered on the existing
   pressed-shadow + cyan text, so the current page reads clearly at a glance
   even in the collapsed icon-only rail. */
.customer-sidebar-nav a.active,.customer-sidebar-link.active{box-shadow:var(--neu-pressed),0 0 14px rgba(179,123,152,.3);border-color:rgba(179,123,152,.35);color:var(--accent-cyan,#a05f80)}
@media (prefers-reduced-motion: reduce){.customer-sidebar-nav a{transition-duration:.01ms!important}}
/* Logout: standalone link at the very bottom, below the profile block -
   same treatment as the admin/super-admin sidebars' .admin-sidebar-logout. */
.customer-sidebar-logout{display:flex;align-items:center;gap:10px;padding:9px 10px;margin-top:10px;border-radius:var(--radius-sm,10px);color:#ef4444;text-decoration:none;font-size:14px;font-weight:600;flex-shrink:0;transition:background var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1)),transform var(--duration-fast,150ms) var(--ease-out,cubic-bezier(0.23,1,0.32,1))}
.customer-sidebar-logout:hover{background:rgba(239,68,68,.12)}
.customer-sidebar-logout:active{transform:scale(0.97)}
.customer-sidebar-divider{height:1px;background:var(--border-color,rgba(255,255,255,.1));margin:12px 4px}
/* Submenu (Desktop/Laptop under Products): indented, with a visible left
   connector line (locked-palette midgray, not the near-transparent
   --border-color token which barely showed against the card background),
   smaller/muted text - same active/hover treatment as top-level items via
   the shared .customer-sidebar-nav a rules above. */
.customer-sidebar-submenu-item{margin-left:20px;padding-left:14px;border-left:2px solid rgba(255,255,255,.15);margin-bottom:9px}
.customer-sidebar-submenu-item:last-child{margin-bottom:0}
.customer-sidebar-submenu-item a{font-size:13px;color:var(--text-muted,#94a3b8);padding-top:7px;padding-bottom:7px}
.customer-sidebar-submenu-item a.active{color:var(--accent-cyan,#a05f80)}
/* On touch/small screens (outside the hover-capable media query below) the
   submenu just sits open/always-visible, same as before this was nested -
   there's no hover to reveal it, and the off-canvas drawer already has
   plenty of room. margin-top separates it from its parent row now that
   rows have more breathing room overall. */
.customer-sidebar-submenu{list-style:none;margin:14px 0 6px;padding:0}
.customer-sidebar-badge{margin-left:auto;flex-shrink:0;background:#c8324f;color:#fff;font-size:10.5px;font-weight:700;line-height:1;padding:3px 6px;border-radius:999px;min-width:16px;text-align:center}

.customer-sidebar-label{max-width:160px;opacity:1;overflow:hidden;white-space:nowrap;transition:max-width 200ms ease,opacity 150ms ease}
@media (prefers-reduced-motion: reduce){.customer-sidebar-label{transition-duration:.01ms!important}}

/* ---------- Collapsed-by-default, hover-to-expand icon rail ----------
   Hover-capable pointer devices only (@media hover:hover) - on touch/small
   screens the original pull-tab + off-canvas drawer above still applies
   unchanged, since hover has no equivalent there. On hover-capable devices
   the panel is instead always visible at ~68px (icon-only) and expands to
   260px in place on hover/focus, via #customerSidebar id selectors so it
   reliably wins over the base .customer-sidebar rules regardless of source
   order. */
@media (hover:hover) and (pointer:fine) {
  .customer-menu-toggle{display:none}
  #customerSidebar{transform:translateX(0);width:68px;padding:16px 10px;transition:width 200ms ease,padding 200ms ease}
  #customerSidebar.is-hover-expanded{width:260px;padding:18px}
  #customerSidebar .customer-sidebar-brand{justify-content:center;gap:0}
  #customerSidebar.is-hover-expanded .customer-sidebar-brand{justify-content:flex-start;gap:10px}
  /* Hidden in the 68px rail, like the brand text beside it: two circles stacked
     in that width would crowd the logo, and the row is only 68px wide. It comes
     back the moment the panel expands, which is also when the title it sits
     beside becomes visible. */
  #customerSidebar .customer-sidebar-brand .tw-appearance-icon,
  #customerSidebar .customer-sidebar-brand .tw-appearance-label{display:none}
  #customerSidebar.is-hover-expanded .customer-sidebar-brand .tw-appearance-icon{display:inline-flex}
  #customerSidebar.is-hover-expanded .customer-sidebar-brand .tw-appearance-label{display:inline}
  /* Collapsed rail: just the search icon, centered like every other
     icon-only row; the text input is squeezed to nothing rather than
     hidden outright so focusing it via Tab still works and still triggers
     the panel's existing focusin-expand behavior. */
  #customerSidebar .customer-sidebar-search{justify-content:center;padding-left:0;padding-right:0;gap:0;cursor:pointer}
  #customerSidebar.is-hover-expanded .customer-sidebar-search{justify-content:flex-start;padding-left:10px;padding-right:10px;gap:10px;cursor:default}
  #customerSidebar .customer-sidebar-search-input{max-width:0;opacity:0;pointer-events:none}
  #customerSidebar.is-hover-expanded .customer-sidebar-search-input{max-width:160px;opacity:1;pointer-events:auto;transition:max-width 200ms ease,opacity 150ms ease}
  #customerSidebar .customer-sidebar-profile{justify-content:center;gap:0}
  #customerSidebar.is-hover-expanded .customer-sidebar-profile{justify-content:flex-start;gap:10px}
  #customerSidebar .customer-sidebar-credit{justify-content:center;padding-left:0;padding-right:0;gap:0;font-size:0}
  #customerSidebar.is-hover-expanded .customer-sidebar-credit{justify-content:flex-start;padding-left:10px;padding-right:10px;gap:12px;font-size:14px}
  /* The amount carries margin-left:auto in its base rule, to sit at the row's
     right edge while the panel is open. That margin HAS to be reset for the
     rail, because a flex item's auto margin absorbs ALL the free space before
     justify-content is allowed to distribute any - so `justify-content:center`
     on the row above was being cancelled silently and the icon pinned hard
     left, while the zero-width amount held the right edge. Every other row
     here centres correctly because none of them has an auto margin; the
     Notifications badge is the only other one that does, and it sidesteps the
     problem by going position:absolute in the rail. */
  #customerSidebar .customer-sidebar-credit-amount{margin-left:0}
  #customerSidebar.is-hover-expanded .customer-sidebar-credit-amount{margin-left:auto}
  /* font-size:0 kills the literal whitespace character sitting between
     each <img class="nav-icon-gif"> and its <span class="customer-sidebar-
     label"> in the markup - that whitespace is itself a flex item next to
     the icon, so even with justify-content:center it could nudge the icon
     a few px off true-center depending on font metrics. The label span
     already collapses to max-width:0 here regardless, so zeroing the
     font-size on the row doesn't affect anything visible. */
  #customerSidebar .customer-sidebar-nav a,#customerSidebar .customer-sidebar-link{justify-content:center;padding-left:0;padding-right:0;gap:0;font-size:0}
  #customerSidebar.is-hover-expanded .customer-sidebar-nav a,#customerSidebar.is-hover-expanded .customer-sidebar-link{justify-content:flex-start;padding-left:10px;padding-right:10px;gap:10px;font-size:14px}
  #customerSidebar .customer-sidebar-logout{justify-content:center;padding-left:0;padding-right:0;gap:0}
  #customerSidebar.is-hover-expanded .customer-sidebar-logout{justify-content:flex-start;padding-left:10px;padding-right:10px;gap:10px}
  #customerSidebar .customer-sidebar-submenu-item{margin-left:0;padding-left:0;border-left:none}
  #customerSidebar.is-hover-expanded .customer-sidebar-submenu-item{margin-left:20px;padding-left:14px;border-left:2px solid rgba(255,255,255,.15)}
  /* Submenu (Desktop/Laptop under Products, Support under Settings) stays
     collapsed until its own parent row is hovered/focused - not just
     whenever the sidebar itself is expanded - so Products/Settings behave
     like real expandable groups instead of always spilling their children
     into the list. Stays open without hovering when the current page is
     inside that group (.has-active, set server-side) so a user doesn't
     lose sight of where they are. */
  #customerSidebar .customer-sidebar-submenu{max-height:0;overflow:hidden;margin-top:0;transition:max-height 220ms ease,margin-top 220ms ease}
  #customerSidebar .customer-sidebar-has-submenu:hover .customer-sidebar-submenu,
  #customerSidebar .customer-sidebar-has-submenu:focus-within .customer-sidebar-submenu,
  #customerSidebar .customer-sidebar-has-submenu.has-active .customer-sidebar-submenu{max-height:200px;margin-top:14px}
  @media (prefers-reduced-motion: reduce){#customerSidebar .customer-sidebar-submenu{transition-duration:.01ms!important}}
  #customerSidebar .customer-sidebar-label{max-width:0;opacity:0}
  #customerSidebar.is-hover-expanded .customer-sidebar-label{max-width:160px;opacity:1}
  #customerSidebar .customer-sidebar-section-label{max-height:0;opacity:0;margin:0;overflow:hidden}
  #customerSidebar.is-hover-expanded .customer-sidebar-section-label{max-height:40px;opacity:1;margin:14px 6px 8px}
  #customerSidebar .customer-sidebar-badge{position:absolute;top:8px;right:6px;margin-left:0;min-width:8px;height:8px;padding:0;font-size:0}
  /* margin-left:auto is restored with position:static. The rail rule above
     zeroes it (correct - the badge is out of flow there), but that rule also
     matches while expanded, so without this the badge came back into flow
     hard against the "Notifications" label instead of at the row's right
     edge, which is what its base rule asks for. */
  #customerSidebar.is-hover-expanded .customer-sidebar-badge{position:static;margin-left:auto;min-width:16px;height:auto;padding:3px 6px;font-size:10.5px}
  /* Scrim behind the floating expanded panel - reuses the same backdrop
     element the mobile drawer uses, just triggered by a different state.
     Non-blocking (pointer-events:none): this is a hover preview, not a
     modal, so it shouldn't trap clicks on the page underneath. */
  body.customer-sidebar-hover-expanded .customer-sidebar-backdrop{opacity:1}
  .customer-sidebar-backdrop{pointer-events:none}
}
</style>

<button type="button" class="customer-menu-toggle" id="customerMenuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="customerSidebar">
  <span class="customer-menu-toggle-bar"></span>
  <span class="customer-menu-toggle-bar"></span>
  <span class="customer-menu-toggle-bar"></span>
</button>
<div class="customer-sidebar-backdrop" id="customerSidebarBackdrop"></div>
<aside class="customer-sidebar" id="customerSidebar">
  <!-- Masthead only, deliberately not a link. It used to point at the
       customer dashboard, mirroring the admin/super-admin sidebars, but
       Home already sits first in the nav list directly below it, so the
       logo was a second, unlabelled route to a destination the list
       already names. A <div> rather than a disabled <a> so it is not in
       the tab order at all and shows no pointer cursor. -->
  <div class="customer-sidebar-brand">
    <img src="<?= WEB_ROOT ?>public/shared/img/Logo.ico" alt="TechWorld" class="customer-sidebar-logo">
    <span class="customer-sidebar-label customer-sidebar-brand-text">TechWorld</span>
    <?php /* Appearance. One button, sitting with the title rather than down in
             the list - the theme is a property of the whole panel, so the panel's
             own header is where it belongs, and it is reachable without scrolling
             the nav. theme.js rewrites the glyph, title and data-mode-set to the
             INCOMING theme on every change, so it always offers the other one.
             The three-way (Light / Dark / Match system) still lives in Settings. */ ?>
    <span class="tw-appearance-label customer-sidebar-label" aria-hidden="true">Light</span>
    <button type="button" class="tw-appearance-icon" data-mode-set="light"
            title="Switch to light mode" aria-label="Switch to light mode">&#9728;</button>
  </div>

  <!-- Site search - moved here from the top header (includes/header.php),
       which no longer has one. Same existing search implementation: same
       action/method/field name as before (products.php?q=...), so it's
       reusing the exact backend search products.php already does, not a
       new system. -->
  <form class="customer-sidebar-search" action="<?= WEB_ROOT ?>public/authenticated/customer/products.php" method="get" role="search">
    <button type="submit" class="customer-sidebar-search-icon" aria-label="Search">
      <i class="fi fi-bs-search" aria-hidden="true"></i>
    </button>
    <input type="text" name="q" class="customer-sidebar-search-input" placeholder="Search...">
  </form>

<?php
/* ---------------------------------------------------------------------------
   THE NAVIGATION LIST - one definition, two surfaces.

   This used to be ~20 lines of hand-written <li><a> markup. The mobile menu
   added below needs the same links, and a second hand-written copy of them is
   a copy that drifts: add a page to one list and the other silently keeps
   sending people to the old set. So the links live here, once, and both the
   desktop <nav> and the mobile drawer render from this array.

   Nothing about the links themselves changed - every href, label, icon,
   $activeNav key and badge is exactly what it was, and the desktop markup
   this produces is byte-for-byte what it produced before (verified by
   diffing the rendered sidebar on three pages).

   Per entry:
     key        the $activeNav value that makes this row active
     label      visible text
     href       relative to public/authenticated/customer/
     gif        icon in public/shared/img/icons/, OR
     icon       a Flaticon class, for the one row that uses one
     activeKeys rows whose parent should show as active (submenu parents)
     children   submenu rows, desktop only
     badge      'notifications' - the one row with a live count
     mobile     false = desktop only. The cart lives in the mobile top bar,
                so repeating it in the drawer would be a second way to the
                same place in a menu that is trying to be short.
   --------------------------------------------------------------------- */
$__sbNav = [
    'shop' => [
        ['key' => 'home', 'label' => 'Home', 'href' => 'index.php', 'gif' => 'home.gif'],
        ['key' => 'products', 'label' => 'Products', 'href' => 'products.php', 'gif' => 'product.gif',
         'activeKeys' => ['products', 'desktops', 'laptops'],
         'children' => [
             ['key' => 'desktops', 'label' => 'Desktop', 'href' => 'products.php?category=desktops', 'gif' => 'desktop.gif'],
             ['key' => 'laptops',  'label' => 'Laptop',  'href' => 'products.php?category=laptops',  'gif' => 'laptop.gif'],
         ]],
        ['key' => 'ai_builder', 'label' => 'AI Builder', 'href' => 'ai_builder.php', 'icon' => 'fi fi-rr-microchip'],
    ],
    'account' => [
        ['key' => 'dashboard',   'label' => 'Dashboard',      'href' => 'dashboard.php',   'gif' => 'dashboard.gif'],
        ['key' => 'orders',      'label' => 'My Orders',      'href' => 'orders.php',      'gif' => 'my-orders.gif'],
        ['key' => 'notifications','label' => 'Notifications', 'href' => 'notifications.php','gif' => 'notifications.gif', 'badge' => 'notifications'],
        ['key' => 'cart',        'label' => 'Shopping Cart',  'href' => 'cart.php',        'gif' => 'shopping-cart.gif', 'mobile' => false],
        ['key' => 'track_order', 'label' => 'Order Tracking', 'href' => 'track_order.php', 'gif' => 'track-order.gif'],
        ['key' => 'settings',    'label' => 'Settings',       'href' => 'settings.php',    'gif' => 'settings.gif',
         'activeKeys' => ['settings', 'support'],
         'children' => [
             ['key' => 'support', 'label' => 'Support', 'href' => 'support.php', 'gif' => 'customer-service.gif'],
         ]],
    ],
];

if (!function_exists('sb_nav_icon')) {
    /** The icon half of a row: a gif for almost every row, a Flaticon glyph
     *  for AI Builder. Emitted exactly as it was written by hand. */
    function sb_nav_icon(array $it): string {
        if (!empty($it['icon'])) {
            return '<i class="' . $it['icon'] . '" aria-hidden="true"></i>';
        }
        return '<img class="nav-icon-gif" src="' . WEB_ROOT . 'public/shared/img/icons/' . $it['gif'] . '" alt="" aria-hidden="true">';
    }

    /** The badge span, for the one row that carries one. Markup, id and the
     *  inline display rule are unchanged - the polling JS still finds it. */
    function sb_nav_badge(array $it, int $unread): string {
        if (($it['badge'] ?? '') !== 'notifications') { return ''; }
        return '<span class="customer-sidebar-badge" id="customerSidebarNotifBadge" data-count="' . $unread . '"'
             . ' style="' . ($unread > 0 ? '' : 'display:none;') . '">' . ($unread > 99 ? '99+' : $unread) . '</span>';
    }

    /**
     * One desktop row, including its submenu when it has one.
     *
     * $pad carries the row's indentation. The two groups sit at different
     * depths in the markup this replaces - shop rows at 6 spaces, account
     * rows at 8 - and reproducing that exactly is what lets the rendered
     * desktop sidebar stay byte-for-byte identical to the hand-written
     * version, which is the only way to be sure nothing moved.
     */
    function sb_nav_row(array $it, string $active, int $unread, string $pad = '      '): string {
        $href = WEB_ROOT . 'public/authenticated/customer/' . $it['href'];
        $link = '<a href="' . $href . '" class="' . ($active === $it['key'] ? 'active' : '') . '">'
              . sb_nav_icon($it) . ' <span class="customer-sidebar-label">' . $it['label'] . '</span>'
              . sb_nav_badge($it, $unread) . '</a>';

        if (empty($it['children'])) {
            return $pad . '<li>' . $link . '</li>' . "\n";
        }

        $hasActive = in_array($active, $it['activeKeys'] ?? [], true) ? ' has-active' : '';
        $out  = $pad . '<li class="customer-sidebar-has-submenu' . $hasActive . '">' . "\n";
        $out .= $pad . '  ' . $link . "\n";
        $out .= $pad . '  <ul class="customer-sidebar-submenu">' . "\n";
        foreach ($it['children'] as $ch) {
            $chHref = WEB_ROOT . 'public/authenticated/customer/' . $ch['href'];
            $out .= $pad . '    <li class="customer-sidebar-submenu-item"><a href="' . $chHref . '" class="'
                  . ($active === $ch['key'] ? 'active' : '') . '">' . sb_nav_icon($ch)
                  . ' <span class="customer-sidebar-label">' . $ch['label'] . '</span></a></li>' . "\n";
        }
        $out .= $pad . '  </ul>' . "\n";
        $out .= $pad . '</li>' . "\n";
        return $out;
    }
}

$__sbActive = $activeNav ?? '';
?>

  <nav class="customer-sidebar-nav">
    <ul>
<?php foreach ($__sbNav['shop'] as $__it) { echo sb_nav_row($__it, $__sbActive, $__sbUnreadCount); } ?>
    </ul>

    <?php if ($__sbLoggedIn): ?>
      <div class="customer-sidebar-divider"></div>
      <div class="customer-sidebar-section-label">Account</div>

      <ul>
<?php foreach ($__sbNav['account'] as $__it) { echo sb_nav_row($__it, $__sbActive, $__sbUnreadCount, '        '); } ?>
      </ul>
    <?php endif; ?>

  </nav>

  <?php if ($__sbLoggedIn): ?>
    <?php if (in_array($__sbRole, ['admin', 'super-admin', 'manager', 'owner'], true)): ?>
      <div class="customer-sidebar-divider"></div>
      <!-- Manager/Owner/Security shortcuts sit above the profile block,
           outside the scrollable nav - fixed footer area, same as the
           super-admin sidebar's own controls (super-admin/includes/navigation.php). -->
      <ul class="customer-sidebar-fixed-list">
        <li><a href="<?= WEB_ROOT ?>public/authenticated/admin/dashboard.php" class="customer-sidebar-link"><i class="fi fi-rr-settings"></i> <span class="customer-sidebar-label">Manager</span></a></li>
        <?php if (in_array($__sbRole, ['super-admin', 'owner'], true)): ?>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/super-admin/dashboard.php" class="customer-sidebar-link"><i class="fi fi-bs-crown"></i> <span class="customer-sidebar-label">Owner</span></a></li>
          <li><a href="<?= WEB_ROOT ?>public/authenticated/super-admin/security.php" class="customer-sidebar-link"><i class="fi fi-rs-shield-check"></i> <span class="customer-sidebar-label">Security</span></a></li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>


    <?php if ($__sbRole === 'customer'): ?>
    <!-- Store credit balance - links to profile.php's Store Credit card
         (full transaction history lives there, same page the Settings link
         below already points into), always visible so customers don't have
         to stumble onto checkout or a notification to find it. Zero
         balance still shows (as ₱0.00) rather than hiding, so the sidebar
         always makes clear this exists as a feature. -->
    <a href="<?= WEB_ROOT ?>public/authenticated/customer/profile.php#storeCredit" class="customer-sidebar-credit<?= $__sbStoreCredit > 0 ? ' has-balance' : '' ?>">
      <img class="nav-icon-gif" src="<?= WEB_ROOT ?>public/shared/img/icons/credit.gif" alt="" aria-hidden="true">
      <span class="customer-sidebar-label">Store Credit</span>
      <span class="customer-sidebar-credit-amount customer-sidebar-label"><?= htmlspecialchars(formatCurrency($__sbStoreCredit)) ?></span>
    </a>
    <?php endif; ?>

    <!-- Profile block sits at the very bottom of the panel, directly above
         Logout - clickable, goes to the same Profile page as the "Profile"
         nav link above. -->
    <a href="<?= WEB_ROOT ?>public/authenticated/customer/profile.php" class="customer-sidebar-profile">
      <div class="customer-sidebar-avatar" id="customerSidebarAvatar">
        <?php if ($__sbAvatarRel): ?>
          <img src="<?= WEB_ROOT ?>public/authenticated/<?= $__sbAvatarRel ?>" alt="Profile">
        <?php else: ?>
          <span><?= htmlspecialchars($__sbInitials) ?></span>
        <?php endif; ?>
      </div>
      <div class="customer-sidebar-identity">
        <strong class="customer-sidebar-label"><?= htmlspecialchars($__sbName !== '' ? $__sbName : ($_SESSION['username'] ?? 'Account')) ?></strong>
        <span class="customer-sidebar-label"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></span>
      </div>
    </a>

    <div class="customer-sidebar-divider"></div>
    <a href="<?= WEB_ROOT ?>public/authenticated/auth/logout.php?ns=TECHWRLD_CUST" class="customer-sidebar-logout" data-confirm="Are you sure you want to log out?" data-confirm-ok="Log out" >
      <i class="fi fi-bs-sign-out"></i> <span class="customer-sidebar-label">Logout</span>
    </a>
  <?php endif; ?>
</aside>


<script>
(function () {
  var toggleBtn = document.getElementById('customerMenuToggle');
  var sidebar = document.getElementById('customerSidebar');
  var backdrop = document.getElementById('customerSidebarBackdrop');
  if (!toggleBtn || !sidebar || !backdrop) return;

  // Keep the tab lined up with the header's own vertical center (not the
  // page middle). Measured from the live header instead of a hardcoded
  // pixel value so it stays correct across the header's responsive
  // breakpoints. The CSS "top:40px" is just a pre-JS fallback.
  // Clamped to at least half the tab's own height (+ a small margin) below
  // the viewport top - the header is now just a thin empty strip (search/
  // logo/notifications moved out of it), short enough that centering the
  // tab on it would otherwise push the tab's top edge above the viewport
  // entirely, making it unclickable.
  function alignToggleWithHeader() {
    var header = document.querySelector('.header');
    if (!header) return;
    var rect = header.getBoundingClientRect();
    if (rect.height > 0) {
      var center = rect.top + rect.height / 2;
      var minCenter = (toggleBtn.offsetHeight || 56) / 2 + 10;
      toggleBtn.style.top = Math.max(center, minCenter) + 'px';
    }
  }
  alignToggleWithHeader();
  window.addEventListener('resize', alignToggleWithHeader);
  window.addEventListener('load', alignToggleWithHeader);

  function isOpen() { return document.body.classList.contains('customer-menu-open'); }

  function openMenu(focusFirst) {
    document.body.classList.add('customer-menu-open');
    toggleBtn.setAttribute('aria-expanded', 'true');
    toggleBtn.setAttribute('aria-label', 'Close navigation menu');
    if (focusFirst) {
      var firstLink = sidebar.querySelector('a');
      if (firstLink) firstLink.focus();
    }
  }

  function closeMenu(returnFocus) {
    document.body.classList.remove('customer-menu-open');
    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.setAttribute('aria-label', 'Open navigation menu');
    if (returnFocus) toggleBtn.focus();
  }

  toggleBtn.addEventListener('click', function () {
    if (isOpen()) { closeMenu(false); } else { openMenu(true); }
  });

  backdrop.addEventListener('click', function () { closeMenu(false); });

  // Hovering the toggle button opens the menu instantly. Moving the pointer
  // off the tab and the sidebar panel (including its nav list) closes it
  // again automatically, after a short grace delay so crossing the gap
  // between the tab and the panel doesn't cause a flicker. A real click on
  // the tab, the backdrop, or Escape still work as immediate closes too.
  // Only wired up on hover-capable pointers (matches the CSS's own
  // `@media (hover:hover) and (pointer:fine)` gating, where this tab is
  // display:none anyway and the hover-expand rail takes over instead) -
  // touch browsers synthesize a mouseenter right before the click on a tap
  // for mouse-only-site compatibility, which was racing this same listener:
  // it opened the menu on that synthesized mouseenter, then the click
  // handler's isOpen() check saw it as already open and immediately closed
  // it again, so a real tap on this button silently did nothing.
  var hoverCapable = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var hoverCloseTimer = null;
  function cancelHoverClose() {
    if (hoverCloseTimer) { clearTimeout(hoverCloseTimer); hoverCloseTimer = null; }
  }
  function scheduleHoverClose() {
    cancelHoverClose();
    hoverCloseTimer = setTimeout(function () { closeMenu(false); }, 200);
  }

  if (hoverCapable) {
    toggleBtn.addEventListener('mouseenter', function () {
      cancelHoverClose();
      if (!isOpen()) { openMenu(false); }
    });
    toggleBtn.addEventListener('mouseleave', scheduleHoverClose);
    sidebar.addEventListener('mouseenter', cancelHoverClose);
    sidebar.addEventListener('mouseleave', scheduleHoverClose);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) { closeMenu(true); }
  });

  // Hover-to-expand icon rail (hover-capable devices only - see the
  // @media (hover:hover) CSS block, where the panel is collapsed to ~68px
  // by default). Hovering the panel expands it in place; leaving it
  // schedules a collapse after a short grace delay so moving between icons
  // doesn't flicker. Also responds to keyboard focus entering/leaving the
  // panel, so tabbing through it doesn't strand a keyboard user on
  // icon-only, unlabeled links.
  var hoverExpandTimer = null;
  function cancelHoverExpandClose() {
    if (hoverExpandTimer) { clearTimeout(hoverExpandTimer); hoverExpandTimer = null; }
  }
  function expandSidebarOnHover() {
    cancelHoverExpandClose();
    sidebar.classList.add('is-hover-expanded');
    document.body.classList.add('customer-sidebar-hover-expanded');
  }
  function scheduleSidebarHoverCollapse() {
    cancelHoverExpandClose();
    hoverExpandTimer = setTimeout(function () {
      sidebar.classList.remove('is-hover-expanded');
      document.body.classList.remove('customer-sidebar-hover-expanded');
    }, 180);
  }
  sidebar.addEventListener('mouseenter', expandSidebarOnHover);
  sidebar.addEventListener('mouseleave', scheduleSidebarHoverCollapse);
  sidebar.addEventListener('focusin', expandSidebarOnHover);
  sidebar.addEventListener('focusout', function (e) {
    if (!sidebar.contains(e.relatedTarget)) { scheduleSidebarHoverCollapse(); }
  });

  // Keep the panel open across a navigation.
  //
  // The panel opens on mouseenter - which is the one event that cannot fire
  // on the page you land on. The pointer was already inside the panel when
  // that document began loading, so it never *enters* it, and
  // is-hover-expanded is a runtime class that no page inherits. The result
  // was that clicking any nav link dropped the panel back to the 68px rail
  // even though the cursor had not moved off it.
  //
  // So: remember that it was open at the moment of the click, re-apply that
  // on the next page, then let the first real pointer movement confirm the
  // cursor is in fact still inside - if it has moved away while the page was
  // loading, collapse exactly as a mouseleave would have.
  var HOVER_KEY = 'tw.customerSidebar.hoverExpanded';
  function rememberHoverExpanded(on) {
    try {
      if (on) { sessionStorage.setItem(HOVER_KEY, '1'); }
      else { sessionStorage.removeItem(HOVER_KEY); }
    } catch (e) { /* private mode / storage disabled - hover still works */ }
  }

  // Leaving the panel is the one gesture that should close it, so it also
  // clears the memo; a later navigation then starts collapsed as before.
  sidebar.addEventListener('mouseleave', function () { rememberHoverExpanded(false); });

  sidebar.addEventListener('click', function (e) {
    var link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!link || !sidebar.contains(link)) { return; }
    var href = link.getAttribute('href') || '';
    // Submenu toggles and new tabs leave this page standing - nothing to carry.
    if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) { return; }
    if (link.target && link.target !== '' && link.target !== '_self') { return; }
    rememberHoverExpanded(sidebar.classList.contains('is-hover-expanded'));
  });

  // Collapse only on PROOF of where the pointer is - a real coordinate from a
  // real mouse event, measured against the panel's own rect.
  //
  // An earlier version asked `sidebar.matches(':hover')` on a 350ms timer,
  // on the assumption that a browser recomputes hover against the last known
  // pointer position on the first frame after a navigation. Chromium does
  // not: it leaves :hover unset on the element under a STATIONARY cursor
  // until the pointer next moves. So on every navigation that check read
  // false while the cursor was sitting on the panel, and collapsed it under
  // the user's own hand. There is no delay that fixes that - the answer never
  // arrives until the pointer moves - so the timer is gone entirely.
  //
  // The trade this makes: if the pointer leaves the panel WHILE the next page
  // is loading and is then held perfectly still, the panel stays open until
  // the next mouse movement, because nothing has told us it left. That is the
  // strictly better failure - it resolves the instant the mouse twitches,
  // whereas the timer version mis-collapsed on every single navigation.
  function settle(ev) {
    document.removeEventListener('mousemove', settle, true);
    var r = sidebar.getBoundingClientRect();
    var inside = ev.clientX >= r.left && ev.clientX <= r.right &&
                 ev.clientY >= r.top && ev.clientY <= r.bottom;
    if (!inside) { rememberHoverExpanded(false); scheduleSidebarHoverCollapse(); }
    // If it IS inside, do nothing: that same movement gives the browser its
    // hover state, so the ordinary mouseleave handler takes over from here.
  }
  function armPointerCheck() {
    document.removeEventListener('mousemove', settle, true);
    document.addEventListener('mousemove', settle, true);
  }

  (function restoreHoverExpanded() {
    // Only the collapsed-rail breakpoint has anything to restore; the
    // touch/small-screen drawer is driven by the toggle button instead.
    if (!window.matchMedia || !window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
      rememberHoverExpanded(false);
      return;
    }
    var memo = false;
    try { memo = sessionStorage.getItem(HOVER_KEY) === '1'; } catch (e) {}
    // The memo describes ONE click that just happened, not a standing
    // preference, so it is consumed on read. Left in place it would also fire
    // on the next ordinary reload, bookmark hit or redirect - re-opening the
    // panel long after the click it was recorded for, with the cursor
    // somewhere else entirely. A click on another sidebar link writes it
    // again; nothing else should.
    rememberHoverExpanded(false);

    // :hover still answers for an ordinary reload with the cursor parked on
    // the panel - in the browsers where it answers at all. Where it does not
    // (Chromium, stationary cursor) this simply returns and the panel stays
    // in the rail until the pointer moves, which is the safe direction to
    // fail: closed, not stuck open.
    var hovered = false;
    try { hovered = sidebar.matches(':hover'); } catch (e) {}
    if (!memo && !hovered) { return; }

    // This script runs inline while the document is still parsing, so the
    // class lands before first paint - the panel is simply already open
    // rather than animating itself open on arrival.
    expandSidebarOnHover();

    // The panel now stays exactly as the click left it, and closes on the
    // first pointer movement that proves the cursor is elsewhere - or on an
    // ordinary mouseleave once the cursor has moved at all.
    armPointerCheck();
  })();

  // Back/forward restores the old document from the bfcache exactly as it
  // was - is-hover-expanded still on the panel, the one-shot mousemove
  // listener above already spent - and no mouseenter or mouseleave fires.
  // Without this, going Back from a page you reached by clicking a sidebar
  // link reliably lands you with the panel stuck open.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) { return; }
    if (!sidebar.classList.contains('is-hover-expanded')) { return; }
    armPointerCheck();
  });

  // Leaving the window entirely (alt-tab, another monitor) fires no
  // mouseleave in some browsers, so the panel can be left open behind the
  // switch. Re-check when the tab comes back.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') { return; }
    if (!sidebar.classList.contains('is-hover-expanded')) { return; }
    armPointerCheck();
  });

  // Warm the next page while the pointer is still travelling toward the
  // link, so the click lands on something already in the HTTP cache and the
  // new document paints almost immediately. Each URL is fetched at most
  // once, and only plain in-app GET links qualify.
  var prefetched = Object.create(null);
  var saveData = false;
  try {
    var conn = navigator.connection;
    saveData = !!(conn && (conn.saveData || /2g/.test(conn.effectiveType || '')));
  } catch (e) {}

  function prefetchLink(link) {
    if (saveData || !link) { return; }
    var href = link.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return; }
    if (link.target && link.target !== '' && link.target !== '_self') { return; }
    var url;
    try { url = new URL(link.href, location.href); } catch (e) { return; }
    if (url.origin !== location.origin) { return; }
    // Logout ends the session - never request it just because it was grazed.
    if (/logout/i.test(url.pathname)) { return; }
    var key = url.href;
    if (prefetched[key] || key === location.href) { return; }
    prefetched[key] = true;
    var tag = document.createElement('link');
    tag.rel = 'prefetch';
    tag.href = key;
    (document.head || document.documentElement).appendChild(tag);
  }

  Array.prototype.forEach.call(sidebar.querySelectorAll('a[href]'), function (link) {
    link.addEventListener('mouseenter', function () { prefetchLink(link); });
    link.addEventListener('focus', function () { prefetchLink(link); });
  });
  // Groups low in the list (Settings, in practice) expand their children
  // below the fold of the nav's own scroll area, so the thing the hover just
  // revealed was off-screen until the customer scrolled for it. Nudge that
  // scroller by exactly the amount the submenu overflows by - never more, so
  // the parent row itself stays in view and the list doesn't jump.
  var navList = sidebar.querySelector('.customer-sidebar-nav');
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealTimer = null;

  function revealSubmenu(group) {
    var submenu = group.querySelector('.customer-sidebar-submenu');
    if (!navList || !submenu) return;
    if (revealTimer) { clearTimeout(revealTimer); }
    // Waits out the 220ms max-height transition, otherwise the submenu is
    // still collapsed and measures as zero-height.
    revealTimer = setTimeout(function () {
      var navRect = navList.getBoundingClientRect();
      var subRect = submenu.getBoundingClientRect();
      var hiddenBelow = subRect.bottom - navRect.bottom;
      if (hiddenBelow <= 0) return;
      var top = navList.scrollTop + hiddenBelow + 8; // 8px so it isn't flush
      if (typeof navList.scrollTo === 'function') {
        navList.scrollTo({ top: top, behavior: reduceMotion ? 'auto' : 'smooth' });
      } else {
        navList.scrollTop = top;
      }
    }, 240);
  }

  Array.prototype.forEach.call(
    sidebar.querySelectorAll('.customer-sidebar-has-submenu'),
    function (group) {
      group.addEventListener('mouseenter', function () { revealSubmenu(group); });
      group.addEventListener('focusin', function () { revealSubmenu(group); });
      group.addEventListener('mouseleave', function () {
        if (revealTimer) { clearTimeout(revealTimer); revealTimer = null; }
      });
    }
  );

  // React to profile photo updates from profile.php without a full reload.
  window.addEventListener('profile-photo-updated', function (e) {
    try {
      var url = e && e.detail && e.detail.url;
      if (!url) return;
      var avatar = document.getElementById('customerSidebarAvatar');
      if (!avatar) return;
      while (avatar.firstChild) { avatar.removeChild(avatar.firstChild); }
      var img = document.createElement('img');
      img.alt = 'Profile';
      img.src = url;
      img.decoding = 'async';
      img.loading = 'eager';
      avatar.appendChild(img);
    } catch (_) {}
  });
})();

// Keeps the sidebar's Notifications badge in sync with what the customer
// has actually read - notifications.php (and the notification bell, if it's
// ever wired back in) already call these two functions defensively
// (`typeof x === 'function'`) after marking a notification read, but until
// now neither function existed anywhere the sidebar could see, so the badge
// count never moved until the next full page load. Global (not inside the
// IIFE above) so those existing calls can find them.
function decrementBadgeImmediate(by) {
  by = by || 1;
  var badge = document.getElementById('customerSidebarNotifBadge');
  if (!badge) return;
  var current = parseInt(badge.getAttribute('data-count') || badge.textContent, 10) || 0;
  var next = Math.max(0, current - by);
  badge.setAttribute('data-count', String(next));
  if (next <= 0) {
    badge.style.display = 'none';
  } else {
    badge.textContent = next > 99 ? '99+' : String(next);
    badge.style.display = '';
  }
}

function updateNotificationBadge() {
  var badge = document.getElementById('customerSidebarNotifBadge');
  if (!badge || typeof window.WEB_ROOT !== 'string') return;
  fetch(window.WEB_ROOT + 'public/authenticated/api/notifications.php?action=list&unread_only=true&limit=1&audience=customer', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.success || !d.pagination) return;
      var count = d.pagination.total || 0;
      badge.setAttribute('data-count', String(count));
      if (count <= 0) {
        badge.style.display = 'none';
      } else {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.style.display = '';
      }
    })
    .catch(function () { /* leave the optimistic count as-is on network error */ });
}

/* ---- new-notification chime ---------------------------------------------
   updateNotificationBadge() above existed but nothing ever called it on a
   timer, so a notification that arrived while the customer sat on a page went
   completely unannounced until they happened to navigate. This watches for
   the newest unread id to move, then refreshes the badge and plays the sound.

   Lives in the sidebar because the sidebar is included on every customer
   page, so one copy covers the whole customer area.
   ------------------------------------------------------------------------ */
(function () {
  if (typeof window.WEB_ROOT !== 'string') { return; }

  /* 3s, matching the support thread's cadence, so a notification is heard
     about as soon as it exists rather than up to ten seconds later.
     notifications.php carries no rate limit, and the poll is one indexed
     count, so this costs little; it still pauses entirely on a hidden tab. */
  var POLL_MS   = 3000;
  // The filename really does contain a space; it has to be encoded or the
  // request 404s and the sound silently never plays.
  var SOUND_URL = window.WEB_ROOT + 'public/shared/assets/sounds/Customer%20Notif.wav';

  /* How much louder than the raw file. Measured, not guessed: the clip peaks
     at -1.0 dBFS but its RMS is only -26.4 dBFS - a short transient spike
     that is quiet on average, and perceived loudness follows RMS, not peak.
     There is only x1.12 of headroom, so simply turning it up would clip, and
     an <audio> element's volume is capped at 1.0 regardless.

     So it goes through a compressor first, which pulls the spike down and
     lets the body of the sound come up, and then this makeup gain. Net
     effect is around +7 dB of perceived loudness - a little over twice as
     loud to the ear - with a soft limiter after it so nothing can hard-clip.

     Safe to raise if you want it louder still: the limiter saturates rather
     than clipping, so the cost of a bigger number is a slightly thicker
     sound, not a distorted one. */
  var GAIN      = 6.0;

  var lastSeenId   = null;    // null = baseline not taken yet
  var inFlight     = false;
  var audio        = null;
  var primed       = false;   // the muted warm-up has succeeded
  var pendingChime = false;   // a chime the autoplay policy refused, still owed

  var actx = null;            // Web Audio context, for the boosted path
  var abuf = null;            // the decoded clip
  var adead = false;          // Web Audio unavailable - use the plain element

  function el() {
    if (!audio) {
      audio = new Audio(SOUND_URL);
      audio.preload = 'auto';
    }
    return audio;
  }

  /* An AudioContext may be constructed before any user gesture - it simply
     starts 'suspended' - and decodeAudioData works fine on a suspended one.
     So the clip is fetched and decoded up front and is ready the instant a
     notification lands. */
  function ensureAudioGraph() {
    if (adead || actx) { return actx; }
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) { adead = true; return null; }
    try {
      actx = new AC();
      fetch(SOUND_URL, { credentials: 'same-origin' })
        .then(function (r) { return r.arrayBuffer(); })
        .then(function (buf) {
          return new Promise(function (resolve, reject) {
            // Callback form as well as the promise: older Safari only has the
            // callback signature and returns undefined.
            var p = actx.decodeAudioData(buf, resolve, reject);
            if (p && typeof p.then === 'function') { p.then(resolve, reject); }
          });
        })
        .then(function (decoded) { abuf = decoded; })
        .catch(function () { adead = true; });   // fall back to the element
    } catch (e) { adead = true; actx = null; }
    return actx;
  }

  /* tanh saturation curve for the limiter, built once and reused.

     Deliberately NOT normalised by tanh(k). Dividing by it to keep unity at
     v=1 pushes the curve's asymptote to 1/tanh(k) = 1.056, i.e. +0.5 dBFS -
     a limiter whose own ceiling is above full scale, which is no ceiling at
     all. Plain tanh tops out at exactly 1.0 however hard it is driven.

     The shape earns its keep twice over: near zero tanh(v*k) is about v*k,
     so quiet material - which is most of this clip - comes up by a further
     ~5 dB, while anything loud bends into the ceiling instead of hitting it. */
  var clipCurve = null;
  function softClipCurve() {
    if (clipCurve) { return clipCurve; }
    var n = 2048, k = 1.8;
    clipCurve = new Float32Array(n);
    for (var i = 0; i < n; i++) {
      var v = (i * 2) / (n - 1) - 1;          // -1 .. 1
      clipCurve[i] = Math.tanh(v * k);
    }
    return clipCurve;
  }

  /* The loud path. Returns false if it could not play, so chime() can fall
     back to the plain element rather than going silent. */
  function playBoosted() {
    var c = ensureAudioGraph();
    if (!c || !abuf) { return false; }

    // Suspended until the page has been interacted with. resume() is async,
    // so the state will not have flipped yet on this tick - report failure
    // and let the caller queue the chime for the next gesture.
    if (c.state !== 'running') { try { c.resume(); } catch (e) {} return false; }

    try {
      var src  = c.createBufferSource();
      src.buffer = abuf;

      /* Squash the transient spike hard so the quiet body of the clip can be
         lifted. Measured on this file: threshold -22 / ratio 20 is what lets
         the makeup gain below raise perceived level instead of being
         cancelled out by the compressor (an earlier -24/6 setting netted
         +0.2 dB - all gain, no gain). Attack is 1ms rather than the usual
         3ms because the loudest moment of this clip is its very first,
         and a slower attack would wave it straight through. */
      var comp = c.createDynamicsCompressor();
      comp.threshold.value = -22;
      comp.knee.value      = 8;
      comp.ratio.value     = 20;
      comp.attack.value    = 0.001;
      comp.release.value   = 0.25;

      var gain = c.createGain();
      gain.gain.value = GAIN;

      /* Soft limiter, and it is the reason GAIN can be turned up safely.
         Even a 1ms attack lets the first few samples of the transient past
         uncompressed, and at these gains that lands well above full scale
         (+14 dBFS at GAIN 6) - which the hardware would hard-clip into a
         nasty click. tanh saturates smoothly towards 1 instead, so loud
         peaks compress into the ceiling rather than shattering on it. */
      var shaper = c.createWaveShaper();
      shaper.curve = softClipCurve();
      shaper.oversample = '4x';

      src.connect(comp); comp.connect(gain); gain.connect(shaper);
      shaper.connect(c.destination);
      src.start(0);
      return true;
    } catch (e) {
      return false;
    }
  }

  /* Play it, and if the browser refuses, remember that we owe one.

     Chrome rejects play() with NotAllowedError on any page the user has not
     interacted with yet - confirmed directly: the clip loads fine
     (canplaythrough, 1.17s) and the refusal is purely the autoplay policy.
     No page can get round that, so the previous version's `p.catch(noop)`
     meant a notification arriving on an untouched tab made no sound AND left
     nothing owing - the cue was simply lost.

     Now a blocked chime is queued and flushed on the customer's very next
     click or keypress, so they still get the audible cue the moment they
     touch the page. One flag, not a counter: three missed notifications
     should produce one chime, not three. */
  function chime() {
    // Compressed-and-boosted first; the bare element is only the fallback
    // for browsers without Web Audio, where it plays at the file's own level.
    if (playBoosted()) { return; }
    try {
      var a = el();
      a.currentTime = 0;
      a.volume = 1;
      var p = a.play();
      if (p && typeof p.catch === 'function') {
        p.catch(function () { pendingChime = true; });
      }
    } catch (e) { pendingChime = true; }
  }

  /* Any gesture satisfies the autoplay policy from here on, so this both
     flushes anything owed and primes the element.

     Deliberately NOT {once:true}: priming can itself be refused, and the
     old code set `unlocked = true` before finding out, so one failed attempt
     disabled the sound for the rest of the page's life. These listeners stay
     attached - they are passive and cost nothing - so a later chime can
     always be flushed. */
  function onGesture() {
    /* Lift the suspension first. resume() only succeeds inside a gesture,
       and it is what makes the boosted path usable for every later chime -
       without it playBoosted() keeps reporting failure and the sound drops
       to the quiet fallback for the rest of the page's life. */
    var c = ensureAudioGraph();
    if (c && c.state !== 'running') {
      try {
        var r = c.resume();
        // Flush on resolve, so the queued chime goes out the loud path.
        if (r && typeof r.then === 'function') {
          r.then(function () {
            if (pendingChime) { pendingChime = false; chime(); }
          }).catch(function () {});
          return;
        }
      } catch (e) {}
    }

    if (pendingChime) {
      pendingChime = false;
      chime();
      return;            // the flush IS the priming
    }
    if (primed) { return; }
    try {
      var a = el();
      a.muted = true;
      var p = a.play();
      var reset = function () { primed = true; a.pause(); a.currentTime = 0; a.muted = false; };
      if (p && typeof p.then === 'function') {
        p.then(reset).catch(function () { a.muted = false; });   // leave primed false, try again next time
      } else { reset(); }
    } catch (e) {}
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, onGesture, { passive: true });
  });

  /* A small clickable card, bottom-right, that takes them to the
     notification. Styles are inlined rather than added to a stylesheet
     because this element only exists when one of these fires, and the
     sidebar is included on every customer page - a rule set loaded on all of
     them for something transient is not worth the weight. */
  function toast(notif) {
    try {
      var old = document.getElementById('twNotifToast');
      if (old) { old.remove(); }

      var box = document.createElement('a');
      box.id = 'twNotifToast';
      box.href = window.WEB_ROOT + 'public/authenticated/customer/notifications.php';
      box.setAttribute('role', 'status');
      box.style.cssText = [
        'position:fixed', 'right:18px', 'bottom:18px', 'z-index:2000',
        'max-width:340px', 'display:flex', 'gap:10px', 'align-items:flex-start',
        'padding:12px 14px', 'border-radius:12px', 'text-decoration:none',
        'background:#12263c', 'color:#eaf2fa',
        'border:1px solid rgba(120,170,230,.3)',
        'box-shadow:0 14px 34px rgba(0,0,0,.4)',
        'font:600 13px/1.45 inherit',
        'opacity:0', 'transform:translateY(8px)',
        'transition:opacity .25s ease, transform .25s ease'
      ].join(';');

      // textContent throughout - a notification title is data, not markup.
      var icon = document.createElement('span');
      icon.textContent = '🔔';
      icon.style.cssText = 'flex:none;font-size:15px;line-height:1.3';

      var text = document.createElement('span');
      var t = document.createElement('strong');
      t.textContent = (notif && notif.title) ? notif.title : 'New notification';
      t.style.cssText = 'display:block;margin-bottom:2px';
      var sub = document.createElement('span');
      sub.textContent = 'Tap to view';
      sub.style.cssText = 'opacity:.72;font-weight:500';
      text.appendChild(t); text.appendChild(sub);

      box.appendChild(icon); box.appendChild(text);
      document.body.appendChild(box);

      // Next frame, so the transition has a start state to animate from.
      requestAnimationFrame(function () {
        box.style.opacity = '1';
        box.style.transform = 'translateY(0)';
      });

      setTimeout(function () {
        box.style.opacity = '0';
        box.style.transform = 'translateY(8px)';
        setTimeout(function () { if (box.parentNode) { box.remove(); } }, 300);
      }, 6000);
    } catch (e) {}
  }

  function poll() {
    // A hidden tab does not need to chime, and polling one just spends
    // requests on a page nobody is looking at.
    if (inFlight || document.hidden) { return; }
    inFlight = true;

    fetch(window.WEB_ROOT + 'public/authenticated/api/notifications.php?action=list&unread_only=true&limit=1&audience=customer', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.success) { return; }
        var list = d.notifications || [];
        var newestId = list.length ? (parseInt(list[0].id, 10) || 0) : 0;

        /* First poll only records where things stand. Without this baseline
           every page load would replay the sound for notifications the
           customer has already been told about - which is how a useful alert
           turns into one people learn to ignore. Only arrivals that happen
           while they are on the page make a sound. */
        if (lastSeenId === null) { lastSeenId = newestId; return; }

        if (newestId > lastSeenId) {
          lastSeenId = newestId;
          if (typeof updateNotificationBadge === 'function') { updateNotificationBadge(); }
          chime();
          /* A seen signal as well as a heard one. The sound is the thing that
             was asked for, but it is the one part the page is not allowed to
             guarantee - on a tab the customer has not clicked yet the browser
             refuses it outright. The toast always appears, so "you have a new
             notification" never depends on whether audio was permitted. */
          toast(list[0]);
        }
      })
      .catch(function () { /* a dropped poll retries on the next tick */ })
      .then(function () { inFlight = false; });
  }

  // Fetch and decode the clip now, so the first chime is instant rather than
  // waiting on a download at the moment it is needed.
  ensureAudioGraph();

  setInterval(poll, POLL_MS);
  // Catch up as soon as the tab is looked at again.
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
  poll();
})();
</script>

<?php
/* Phone navigation. Included last so $__sbNav and the $__sb* identity
   variables above are already defined - it renders from the same array
   the desktop <nav> just used, and adds nothing above 767.98px. Its own
   define() guard makes it idempotent on the two pages that include this
   file twice (notifications.php, ai_builder.php). */
include __DIR__ . '/mobile_nav.php';
?>
