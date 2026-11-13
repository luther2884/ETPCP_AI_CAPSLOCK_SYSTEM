<?php
// customer/ai_builder.php - AI Builder: a 6-step wizard that recommends a
// compatible PC build from TechWorld's existing product catalog based on
// budget, intended use, performance preference, and optional requirements.
// All recommendation logic lives in config/ai_builder_engine.php and is
// called through api/ai_builder.php; this page is purely the wizard UI.
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/ai_builder_engine.php';
require_once __DIR__ . '/../../../config/ai_builder_budget.php';
// Option filtering (which tier/use each budget can actually reach).
require_once __DIR__ . '/../../../config/ai_builder_filter.php';

require_login();
require_role(['customer']);

$pageTitle = 'AI Builder - TechWorld';
$activeNav = 'ai_builder';

$pdo = getConnection();
// Pre-built Desktops a customer can pick as an existing base machine (spec
// section 3) - only genuinely in-stock, active ones, same rule the engine
// itself uses everywhere else.
$baseDesktops = aib_get_candidates($pdo, AIB_CATEGORY_DESKTOP);

// What the wizard is allowed to offer. Computed here, server-side, from
// the same config/ai_builder_budget.php the API enforces with - so the
// figure shown next to the budget box and the figure the server refuses
// below are the same number by construction, not by two implementations
// happening to agree.
$aibBudgetRules = aib_budget_rules($pdo);
// The use x performance floor matrix, every figure priced from the live
// products table by config/ai_builder_filter.php. Handed to the browser so
// the wizard can grey out the options this budget cannot reach, and
// re-derived per request by the API so greying out is never the only check.
$aibFilterRules = aibf_rules_payload($pdo);
// category_id -> name, so the frontend can label any component (from either
// the deterministic or Gemini-backed recommender) without a second lookup.
$categoryNames = $pdo->query("SELECT id, name FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);

// How many builds this customer has saved, shown beside "View My Saved
// Builds" in the header. Display only - the panel itself still loads over the
// existing list_saved_builds endpoint. The table is created lazily by
// aib_ensure_tables() in the API, so a missing table just means zero.
$savedBuildCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_builder_saved_builds WHERE user_id = ?");
    $stmt->execute([getUserId()]);
    $savedBuildCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $savedBuildCount = 0;
}

// This page loads its own background video below instead of the sitewide
// one header.php normally injects - suppress that one so both don't load
// and play at once.
$suppressSiteBgVideo = true;
// Theme anti-FOUC. Blocking and inline on purpose: assets/js/theme.js is
// deferred and would run a frame after first paint, which is exactly the
// flash this prevents. Resolution order is localStorage -> OS preference ->
// dark. There is no server-rendered preference to fall back on yet - `users`
// has no theme column; see the TODO in theme.js.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){try{var c=localStorage.getItem('tw-theme');var m=(c==='light'||c==='dark')?c:((window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches)?'light':'dark');document.documentElement.setAttribute('data-mode',m);}catch(e){document.documentElement.setAttribute('data-mode','dark');}})();</script>

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" href="<?= WEB_ROOT ?>public/shared/img/Logo.ico" type="image/x-icon">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

  <link rel="stylesheet" href="<?= asset_url('public/shared/style.css') ?>">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-rounded/css/uicons-regular-rounded.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-bold-straight/css/uicons-bold-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css">
  <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/uicons-regular-straight/css/uicons-regular-straight.css">
</head>
<body>
<?php
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<!-- The redesign's scope class. These pages have no literal <body> tag (the
     browser opens one implicitly at the first body content, which the sidebar
     above has already emitted), so the class goes on here rather than in
     markup. Everything in ai-builder-skin.css is prefixed `body.aibx`, so the
     sheet can never reach another page. -->
<script>
document.body.classList.add('aibx', 'aibx-entering');
// The entrance only plays once per page load. See section Y of ai-builder-skin.css.
document.addEventListener('DOMContentLoaded', function () {
  setTimeout(function () { document.body.classList.remove('aibx-entering'); }, 1600);
});
</script>

<!-- Figtree (headings, prose) + JetBrains Mono (labels, captions, pills,
     counts, money). Same two families the landing page uses. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap">

<!-- The background video that used to sit here (and its scrim) is gone. It
     needed a replacement rather than a plain deletion: the transparency rule
     below existed only so the fixed z-index:-2 video showed through, so
     dropping the video on its own would have left the page with no background
     at all - style.css's opaque light default, under a dark-themed panel.
     $suppressSiteBgVideo above still stops header.php injecting the sitewide
     video, so no video loads on this page now. -->
<style>
/* The page's own ground, replacing the video. Same radial the two landing
   pages paint via body:has(.tw-home) in techworld-anim.css, kept as a literal
   because this page does not load that stylesheet. background-attachment:fixed
   so it behaves like the fixed video did and does not scroll with content. */
body{
  background:radial-gradient(1200px 700px at 70% -10%, #16283f 0%, #0a1424 45%, #060d18 100%) !important;
  background-attachment:fixed;
  background-repeat:no-repeat;
}
</style>
<style>
/* Dark-navy theme to match the customer homepage (.th-home in
   customer-home.css). The rest of the customer area is a light theme, but
   the homepage paints its own opaque navy gradient with light text and
   translucent-white cards - this page now does the same so the AI Builder
   reads as part of that same surface instead of a washed-out light panel
   floating over the background video. Every .aib-* rule below already reads
   its colours through var() tokens, so re-pointing the tokens here recolours
   the whole page in one block; the handful of rules that need a real
   (non-transparent) fill are set explicitly after it. */
.aib-wrap{
  --bg-card:rgba(255,255,255,0.06);
  --bg-card-solid:rgba(255,255,255,0.09);
  --bg-card-hover:rgba(255,255,255,0.14);
  --bg-product-img:rgba(255,255,255,0.08);
  --border-color:rgba(255,255,255,0.14);
  --border-color-hover:rgba(179,123,152,0.65);
  --text-primary:#F2F2F3;
  --text-secondary:#DCDCDD;
  --text-muted:#AEB7C2;
  --accent-cyan:#C58FAC;
  --accent-cyan-bg:rgba(179,123,152,0.18);
  --accent-cyan-bg-30:rgba(179,123,152,0.34);
  --accent-blue:#3E7CB1;
  --shadow-color:rgba(0,0,0,0.4);

  /* Transparent so the background video (+ its scrim, set above) shows
     through instead of being covered by an opaque navy fill. */
  background:transparent;
  min-height:100vh;
}
/* Panel + result cards: translucent white on navy, same as .th-product-card
   / .th-why-card on the homepage. */
.aib-wrap .aib-panel,
.aib-wrap .aib-saved-panel{
  background:linear-gradient(160deg,rgba(255,255,255,0.08),rgba(255,255,255,0.03));
  border:1px solid rgba(255,255,255,0.12);
  box-shadow:0 10px 34px rgba(0,0,0,0.35);
}
/* Form controls: faint translucent fill, visible border, light text. The
   native <option> list can't be translucent, so it gets a solid navy fill.
   box-shadow:none clears the neumorphic inset the global <select> rule adds. */
.aib-wrap .aib-field select,
.aib-wrap .aib-field input[type="text"],
.aib-wrap .aib-field input[type="number"],
.aib-wrap .aib-field textarea,
.aib-wrap .aib-dd-btn,
.aib-wrap .aib-custom-inputs input,
.aib-wrap .aib-save-row input{
  background:rgba(255,255,255,0.06);
  border:1px solid rgba(255,255,255,0.18);
  color:#F2F2F3;
  box-shadow:none;
}
.aib-wrap .aib-field select:focus,
.aib-wrap .aib-field input:focus,
.aib-wrap .aib-field textarea:focus,
.aib-wrap .aib-dd-btn:focus{ border-color:#C58FAC; outline:2px solid rgba(197,143,172,0.35); outline-offset:1px; }
.aib-wrap .aib-field select option{ background:#0B2135; color:#F2F2F3; }
.aib-wrap .aib-field ::placeholder,
.aib-wrap .aib-save-row input::placeholder{ color:rgba(242,242,243,0.45); }
/* Floating dropdown panels need a solid fill so page content doesn't show
   through them. */
.aib-wrap .aib-dd-panel,
.aib-wrap .aib-replace-panel{ background:#12283F; border-color:rgba(255,255,255,0.14); }
/* Primary button: solid mauve like the homepage's .th-btn-primary. */
.aib-wrap .aib-btn-primary{ background:#8B4C6C; }
.aib-wrap .aib-btn-primary:hover{ background:#A15C80; }

/* ---- Landing-page visual language ---------------------------------------
   This page loads none of the landing stylesheets, so there are no --tw-*
   tokens here and every colour below is a literal copied from that palette.

   1. Panel surface. It was rgba(255,255,255,0.08) over the background video,
      which left it barely separated from the footage. Now an opaque dark
      fill, with the pink-to-cyan gradient border technique the active
      carousel tile uses: fill clipped to the padding box, gradient to the
      border box. No mask is needed here - unlike the carousel tile this
      panel holds no image a mask would erase. */
.aib-wrap .aib-panel,
.aib-wrap .aib-saved-panel{
  border:1px solid transparent;
  background-image:
    linear-gradient(160deg, rgba(13,26,44,0.94), rgba(9,19,33,0.96)),
    linear-gradient(140deg, rgba(255,154,181,0.55), rgba(224,86,122,0.45) 45%, rgba(103,232,249,0.5));
  background-origin:padding-box, border-box;
  background-clip:padding-box, border-box;
  box-shadow:0 18px 48px rgba(0,0,0,0.45);
}

/* 2. Required fields read as required before the asterisk is parsed: the
      label takes the accent colour and the control gets an accent left edge.
      Keyed off :has(.aib-required), so no markup and no validation logic
      changes - the same asterisk that was already there drives it. Browsers
      without :has() simply render these fields as they do today. */
.aib-wrap .aib-field:has(.aib-required) label{ color:#ff9ab5; }
.aib-wrap .aib-field:has(.aib-required) > select,
.aib-wrap .aib-field:has(.aib-required) > input[type="text"],
.aib-wrap .aib-field:has(.aib-required) > textarea,
.aib-wrap .aib-field:has(.aib-required) .aib-dd-btn{
  border-left:2px solid rgba(224,86,122,0.7);
}

/* 3. Primary CTA. Was a flat #8B4C6C. Now the pink-to-violet gradient the
      landing page's own primary CTA (.tw-btn-addall) uses, with a matching
      glow. :disabled is left to the existing rule so the "not ready yet"
      state still reads. */
.aib-wrap .aib-btn-primary:not(:disabled){
  background:linear-gradient(120deg,#e0567a,#c2185b);
  box-shadow:0 10px 26px rgba(127,106,224,0.38);
}
.aib-wrap .aib-btn-primary:not(:disabled):hover{
  background:linear-gradient(120deg,#e0567a,#c2185b);
  box-shadow:0 18px 40px rgba(127,106,224,0.5);
}
.aib-wrap .aib-link-btn{ color:#C58FAC; }
/* Status-banner text tuned up for the dark panel (the defaults are dark
   greens/ambers/reds meant for a white background). */
.aib-wrap .aib-result-banner.ok{ color:#7FD9A6; }
.aib-wrap .aib-result-banner.partial,
.aib-wrap .aib-source-badge.fallback{ color:#E6C67A; }
.aib-wrap .aib-result-banner.issues{ color:#F1A6A6; }
.aib-wrap .aib-budget-box.over b{ color:#F1A6A6; }
.aib-wrap .aib-budget-box.remaining b{ color:#7FD9A6; }
.aib-wrap .aib-required{ color:#EF9A9A; }
.aib-wrap .aib-suggested-badge.ok{ background:rgba(46,160,90,0.22); color:#8FE0B4; }
.aib-wrap .aib-suggested-badge.partial{ background:rgba(214,158,46,0.22); color:#EBCF8C; }
.aib-wrap .aib-suggested-badge.issues,
.aib-wrap .aib-component.unavailable .aib-component-name{ color:#F3B0B0; }
.aib-wrap .aib-suggested-badge.issues{ background:rgba(214,58,58,0.22); }
/* The footer below this page is the warm vanilla one every other customer
   page now ends with (customer-dark.css paints it via
   `body:has(... .aib-wrap) .main-footer`). This block used to force a navy
   surface here; the tokens are re-pointed at the light palette instead, so
   anything in the footer still reading them - the heart icon's --accent-cyan,
   for one - matches that ground rather than the old dark one. */
.aib-wrap ~ .main-footer{
  --bg-secondary:#fff4ef;
  --text-primary:#17324a;
  --text-secondary:#3d5875;
  --text-muted:#55677c;
  --border-color:rgba(23,72,113,0.14);
  --accent-cyan:#a05f80;
}

.aib-wrap{ padding:28px 0 60px; }
.aib-wrap .container{ max-width:960px; margin:0 auto; padding:0 20px; }
.aib-intro{ text-align:center; margin-bottom:26px; }
.aib-intro h1{ font-size:clamp(24px,3vw,32px); color:var(--text-primary); display:flex; align-items:center; justify-content:center; gap:10px; margin-bottom:10px; }
.aib-intro p{ color:var(--text-secondary); font-size:15px; line-height:1.6; max-width:620px; margin:0 auto 14px; }
.aib-link-btn{ background:none; border:none; color:var(--accent-cyan); font-size:13.5px; font-weight:600; cursor:pointer; text-decoration:underline; padding:4px; }

.aib-saved-panel{ background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:18px 20px; margin-bottom:22px; box-shadow:0 8px 24px var(--shadow-color); }
.aib-saved-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.aib-saved-head h3{ font-size:16px; color:var(--text-primary); margin:0; }
.aib-close-btn{ background:none; border:none; font-size:20px; line-height:1; cursor:pointer; color:var(--text-muted); }
.aib-saved-list{ display:flex; flex-direction:column; gap:10px; }
.aib-saved-item{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 12px; border:1px solid var(--border-color); border-radius:var(--radius-md); background:var(--bg-card-solid); flex-wrap:wrap; }
.aib-saved-item-info b{ display:block; color:var(--text-primary); font-size:14px; }
.aib-saved-item-info span{ color:var(--text-muted); font-size:12.5px; }
.aib-saved-item-actions{ display:flex; gap:8px; }
.aib-muted{ color:var(--text-muted); font-size:13.5px; }

.aib-panel{ background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:28px 26px; box-shadow:0 8px 24px var(--shadow-color); }
.aib-step h2{ font-size:20px; color:var(--text-primary); margin-bottom:6px; }
.aib-hint{ color:var(--text-muted); font-size:13.5px; margin-bottom:16px; }
.aib-required{ color:#a02424; font-weight:700; }

.aib-field{ margin-bottom:18px; }
.aib-field label{ display:block; font-size:13.5px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
.aib-field select, .aib-field input[type="text"], .aib-field textarea{ width:100%; padding:10px 12px; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-card-solid); color:var(--text-primary); font-size:14px; font-family:inherit; }
.aib-field textarea{ resize:vertical; }

.aib-form-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:6px 18px; }

.aib-custom-inputs{ display:flex; align-items:center; gap:10px; }
.aib-custom-inputs input{ flex:1; padding:10px 12px; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-card-solid); color:var(--text-primary); font-size:14px; }
.aib-custom-inputs span{ color:var(--text-muted); font-size:13px; }

.aib-dd{ position:relative; }
.aib-dd-btn{ width:100%; text-align:left; padding:10px 12px; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-card-solid); color:var(--text-primary); font-size:14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
.aib-dd-btn::after{ content:'▾'; color:var(--text-muted); font-size:11px; margin-left:8px; }
.aib-dd-btn:hover{ border-color:var(--accent-cyan); }
.aib-dd-panel{ position:absolute; top:calc(100% + 6px); left:0; right:0; z-index:20; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-sm); box-shadow:0 10px 26px var(--shadow-color); padding:8px; max-height:260px; overflow-y:auto; }
.aib-dd-item{ display:flex; align-items:center; gap:9px; padding:8px 8px; border-radius:6px; font-size:13.5px; color:var(--text-secondary); cursor:pointer; }
.aib-dd-item:hover{ background:var(--bg-card-hover); }
.aib-dd-item input{ accent-color:var(--accent-cyan); }
.aib-dd-item i{ color:var(--text-muted); width:16px; text-align:center; }

.aib-nav{ display:flex; justify-content:space-between; align-items:center; margin-top:24px; }
.aib-btn-primary{ background:var(--accent-cyan); color:#fff; border:none; padding:12px 26px; border-radius:var(--radius-md); font-size:14px; font-weight:700; cursor:pointer; transition:transform .15s ease, box-shadow .15s ease; }
.aib-btn-primary:hover{ transform:translateY(-2px); box-shadow:0 10px 22px var(--accent-cyan-bg-30); }
.aib-btn-primary:disabled{ opacity:.5; cursor:not-allowed; transform:none; box-shadow:none; }
.aib-btn-secondary{ background:transparent; color:var(--text-secondary); border:1.5px solid var(--border-color); padding:11px 22px; border-radius:var(--radius-md); font-size:14px; font-weight:600; cursor:pointer; }
.aib-btn-secondary:hover{ border-color:var(--accent-cyan); color:var(--accent-cyan); }

.aib-loading{ text-align:center; padding:50px 10px; }
.aib-spinner{ width:52px; height:52px; border:4px solid var(--border-color); border-top-color:var(--accent-cyan); border-radius:50%; margin:0 auto 22px; animation:aibSpin 1s linear infinite; }
@keyframes aibSpin{ to{ transform:rotate(360deg); } }
.aib-loading p{ color:var(--text-secondary); font-size:15px; font-weight:600; }

.aib-result-banner{ display:flex; align-items:flex-start; gap:12px; padding:14px 16px; border-radius:var(--radius-md); margin-bottom:20px; font-size:13.5px; line-height:1.6; }
.aib-result-banner.ok{ background:rgba(46,160,90,0.12); border:1px solid rgba(46,160,90,0.35); color:#1e7a45; }
.aib-result-banner.partial{ background:rgba(214,158,46,0.12); border:1px solid rgba(214,158,46,0.4); color:#8a6414; }
.aib-result-banner.issues{ background:rgba(214,58,58,0.12); border:1px solid rgba(214,58,58,0.4); color:#a02424; }
.aib-result-banner i{ font-size:18px; margin-top:1px; }
.aib-result-banner ul{ margin:6px 0 0; padding-left:18px; }

.aib-budget-summary{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:22px; }
.aib-budget-box{ padding:14px; border-radius:var(--radius-md); background:var(--bg-card-solid); border:1px solid var(--border-color); text-align:center; }
.aib-budget-box span{ display:block; font-size:11.5px; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
.aib-budget-box b{ font-size:16px; color:var(--text-primary); }
.aib-budget-box.over b{ color:#a02424; }
.aib-budget-box.remaining b{ color:#1e7a45; }

.aib-summary-box{ background:var(--accent-cyan-bg); border:1px solid var(--accent-cyan-bg-30); border-radius:var(--radius-md); padding:14px 16px; margin-bottom:22px; font-size:13.5px; line-height:1.65; color:var(--text-secondary); }
.aib-summary-box b{ color:var(--text-primary); }

.aib-component{ display:flex; gap:14px; padding:16px; border:1px solid var(--border-color); border-radius:var(--radius-md); margin-bottom:12px; background:var(--bg-card-solid); }
.aib-component img{ width:64px; height:64px; object-fit:contain; border-radius:var(--radius-sm); background:var(--bg-product-img); flex-shrink:0; }
.aib-component-body{ flex:1; min-width:0; }
.aib-component-role{ font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--accent-cyan); font-weight:700; margin-bottom:3px; }
.aib-component-name{ font-size:14.5px; font-weight:700; color:var(--text-primary); margin-bottom:4px; }
.aib-component-explain{ font-size:12.5px; color:var(--text-muted); line-height:1.55; margin-bottom:8px; }
.aib-component-foot{ display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.aib-component-price{ font-size:15px; font-weight:700; color:var(--text-primary); }
.aib-component.unavailable{ border-color:rgba(214,58,58,0.3); background:rgba(214,58,58,0.05); }
.aib-component.unavailable .aib-component-name{ color:#a02424; }
.aib-component.skipped, .aib-component.included{ opacity:.85; }
.aib-add-btn{ background:var(--accent-blue); color:#fff; border:none; padding:7px 16px; border-radius:var(--radius-sm); font-size:12.5px; font-weight:700; cursor:pointer; }
.aib-add-btn:hover{ opacity:.88; }

.aib-result-actions{ display:flex; flex-wrap:wrap; gap:10px; margin-top:24px; }
.aib-save-row{ display:flex; gap:10px; margin-top:14px; flex-wrap:wrap; }
/* Restores the `hidden` attribute. A UA stylesheet gives [hidden] display:none,
   but any author `display` beats it - so the rule above left this row on screen
   permanently, and "Save This Build" (which only flips .hidden) looked like a
   dead button. The attribute selector is (0,2,0), so it outranks the rule above
   it without !important. */
.aib-save-row[hidden]{ display:none; }
.aib-save-row input{ flex:1; min-width:180px; padding:10px 12px; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-card-solid); color:var(--text-primary); font-size:13.5px; }

.aib-source-badge{ display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:999px; font-size:11.5px; font-weight:700; margin-bottom:16px; }
.aib-source-badge.ai{ background:var(--accent-cyan-bg); color:var(--accent-cyan); }
.aib-source-badge.fallback{ background:rgba(214,158,46,0.12); color:#8a6414; }

.aib-suggested-title{ font-size:15px; font-weight:700; color:var(--text-primary); margin:28px 0 12px; }
.aib-suggested-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
.aib-suggested-card{ border:1.5px solid var(--border-color); border-radius:var(--radius-md); padding:16px; background:var(--bg-card-solid); display:flex; flex-direction:column; gap:8px; }
.aib-suggested-card.active{ border-color:var(--accent-cyan); background:var(--accent-cyan-bg); }
.aib-suggested-card b{ font-size:14px; color:var(--text-primary); }
.aib-suggested-card p{ font-size:12px; color:var(--text-muted); margin:0; line-height:1.5; }
.aib-suggested-price{ font-size:15px; font-weight:700; color:var(--text-primary); }
.aib-suggested-badge{ font-size:10.5px; font-weight:700; padding:3px 8px; border-radius:999px; align-self:flex-start; }
.aib-suggested-badge.ok{ background:rgba(46,160,90,0.14); color:#1e7a45; }
.aib-suggested-badge.partial{ background:rgba(214,158,46,0.14); color:#8a6414; }
.aib-suggested-badge.issues{ background:rgba(214,58,58,0.14); color:#a02424; }

.aib-replace-panel{ margin-top:10px; padding:12px; border-radius:var(--radius-sm); background:var(--bg-card); border:1px solid var(--border-color); max-height:260px; overflow-y:auto; }
.aib-replace-item{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 6px; border-bottom:1px solid var(--border-color); font-size:12.5px; }
.aib-replace-item:last-child{ border-bottom:none; }
.aib-replace-item-name{ color:var(--text-primary); font-weight:600; flex:1; }
.aib-replace-item-price{ color:var(--text-muted); white-space:nowrap; }
.aib-replace-pick-btn{ background:var(--accent-blue); color:#fff; border:none; padding:5px 12px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer; white-space:nowrap; }
.aib-change-btn{ background:transparent; color:var(--text-secondary); border:1.5px solid var(--border-color); padding:7px 14px; border-radius:var(--radius-sm); font-size:12.5px; font-weight:600; cursor:pointer; }
.aib-change-btn:hover{ border-color:var(--accent-cyan); color:var(--accent-cyan); }

@media (max-width:640px){
  .aib-budget-summary{ grid-template-columns:1fr; }
}
</style>

<!-- The redesign, linked LAST: after style.css, customer-dark.css and the
     uicons sheets that includes/header.php pulls in, and after this page's own
     two <style> blocks above - so it wins on source order wherever specificity
     ties, and needs !important only where those sheets already used it.
     Presentation only; see the file header. -->
<link rel="stylesheet" href="<?= asset_url('public/shared/assets/css/ai-builder-skin.css') ?>">

<div class="aibx-bootscan" aria-hidden="true"></div>

<div class="aib-wrap">
  <div class="container">
    <header class="aib-intro">
      <div class="aibx-head-main">
        <p class="aibx-eyebrow"><span class="aibx-dot" aria-hidden="true"></span>AI Builder · Compatibility checked</p>
        <h1>Tell us what you need.<span class="aibx-h1-b">We'll spec the build.</span></h1>
        <p class="aibx-lede">Not sure which components to choose? Let our AI-powered configuration system recommend compatible products based on your budget, needs, and intended use.</p>
        <p class="aibx-head-actions">
          <button type="button" class="aib-link-btn" id="aibSavedToggle">View My Saved Builds &rarr;</button>
          <span class="aibx-saved-count"><?= $savedBuildCount ?> build<?= $savedBuildCount === 1 ? '' : 's' ?> saved</span>
        </p>
      </div>
      <!-- Step rail. States are set by ai-builder-skin.js from which fields
           are actually filled; these are the load-time defaults. -->
      <ol class="aibx-steprail" aria-label="Your progress">
        <li data-state="current"><span class="aibx-steprail-num">1</span><span class="aibx-steprail-label">Basics</span></li>
        <li data-state="todo"><span class="aibx-steprail-num">2</span><span class="aibx-steprail-label">Budget</span></li>
        <li data-state="todo"><span class="aibx-steprail-num">3</span><span class="aibx-steprail-label">The work</span></li>
        <li data-state="todo"><span class="aibx-steprail-num">4</span><span class="aibx-steprail-label">Extras</span></li>
      </ol>
    </header>

    <div class="aib-saved-panel" id="aibSavedPanel" hidden>
      <div class="aib-saved-head">
        <h3>Saved Builds</h3>
        <button type="button" class="aib-close-btn" id="aibSavedClose">&times;</button>
      </div>
      <div id="aibSavedList" class="aib-saved-list"></div>
    </div>

    <div class="aib-panel">
      <!-- Two columns: the form card, and a sticky rail holding the live
           brief and the CTA. The rail hides itself when step 1 is hidden (the
           loading and result steps), so those keep the full width. -->
      <div class="aibx-grid">
      <section class="aib-step" data-step="1">


        <!-- Wizard step 1 of 4. Wrapped in .aibx-card so every step
             carries the same card treatment as the rail. The section
             inside is untouched - same fields, same ids, same classes -
             so ai-builder-skin.js and the page's own handlers keep
             reading them exactly as before. Hidden steps stay in the
             DOM (hidden, not removed) for the same reason: the live
             brief in the rail is computed from these real inputs. -->
        <div class="aibx-card aibx-wiz-card" data-wiz-step="1">
          <!-- ===== STEP 01 - question 1 ================================== -->
          <div class="aibx-sec" data-aibx-for="basics">
            <div class="aibx-sec-head">
              <div>
                <p class="aibx-sec-cap">Step 01 &middot; The basics</p>
                <h2 class="aibx-sec-q">Start from scratch, or build on a desktop you own?</h2>
                <p class="aibx-sec-sub">Fill in what applies - the AI will figure out the rest.</p>
              </div>
              <span class="aibx-flag aibx-flag-opt">Optional</span>
            </div>

          <?php if (!empty($baseDesktops)): ?>
          <div class="aib-field">
            <label for="aibBasePc" class="aibx-sr">Already own one of our pre-built desktops?</label>
            <select id="aibBasePc" class="aibx-native">
              <option value="">No - build a new PC from scratch</option>
              <?php foreach ($baseDesktops as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?> — <?= formatCurrency((float)$d['price']) ?></option>
              <?php endforeach; ?>
            </select>
            <!-- The two choice cards and the model rows are drawn in here from
                 the <select> above, option by option, by ai-builder-skin.js. -->
            <div id="aibxBaseSkin"></div>
            <p class="aib-hint" style="margin:6px 0 0;">We'll treat its CPU, motherboard, RAM, PSU, and case as already included, and only recommend genuine add-ons (like a GPU) on top of it.</p>
          </div>
          <?php endif; ?>
          </div>
          <div class="aibx-wiz-nav">
            <span class="aibx-wiz-count">Step 1 of 4 &middot; Basics</span>
            <span class="aibx-wiz-actions">
            <button type="button" class="aib-btn-primary aibx-wiz-next" data-wiz-go="2">
              Next <i class="fi fi-rr-angle-small-right" aria-hidden="true"></i>
            </button>
            </span>
            <span class="aibx-wiz-why" hidden></span>
          </div>
        </div>

        <!-- Wizard step 2 of 4. Wrapped in .aibx-card so every step
             carries the same card treatment as the rail. The section
             inside is untouched - same fields, same ids, same classes -
             so ai-builder-skin.js and the page's own handlers keep
             reading them exactly as before. Hidden steps stay in the
             DOM (hidden, not removed) for the same reason: the live
             brief in the rail is computed from these real inputs. -->
        <div class="aibx-card aibx-wiz-card" data-wiz-step="2" hidden>
          <!-- ===== STEP 02 - question 2 ================================== -->
          <div class="aibx-sec" data-aibx-for="budget">
            <div class="aibx-sec-head">
              <div>
                <p class="aibx-sec-cap">Step 02 &middot; Budget</p>
                <h2 class="aibx-sec-q">What are you spending?</h2>
              </div>
              <span class="aibx-flag aibx-flag-req">Required</span>
            </div>

            <div class="aib-field">
              <label for="aibBudgetPreset" class="aibx-sr">Budget <span class="aib-required">*</span></label>
              <?php
                // Preset ranges, filtered against the real floor. A range whose
                // TOP is still below the cheapest complete PC we can assemble
                // cannot produce anything, so it is not offered at all - the
                // list is built here rather than written into the markup so it
                // tracks stock instead of going stale.
                $aibPresets = [
                    [20000, 30000], [30000, 40000], [40000, 50000],
                    [50000, 70000], [70000, 150000],
                ];
                $aibMinBuild = $aibBudgetRules['min_build'] ?? 0;
                $aibHiddenPresets = 0;
              ?>
              <select id="aibBudgetPreset" class="aibx-native">
                <option value="">Select a budget range...</option>
                <?php foreach ($aibPresets as [$lo, $hi]): ?>
                  <?php if ($aibMinBuild && $hi < $aibMinBuild) { $aibHiddenPresets++; continue; } ?>
                  <option value="<?= (int)$lo ?>,<?= (int)$hi ?>">
                    ₱<?= number_format($lo) ?> - <?= $hi >= 150000 ? '₱' . number_format($lo) . '+' : '₱' . number_format($hi) ?>
                  </option>
                <?php endforeach; ?>
                <option value="custom">Custom amount...</option>
              </select>
              <div id="aibxBudgetSkin"></div>
              <!-- The Min/Max pair is always on screen now, behind an "OR EXACT"
                   label, instead of appearing only for "Custom amount...".
                   #aibCustomInputs keeps its id and its `hidden` attribute - the
                   page's own JS still toggles it, the CSS just overrides the
                   display - and "Custom amount..." focuses Min instead. -->
              <div class="aibx-orexact">
                <span class="aibx-orexact-lab">Or exact</span>
                <div class="aib-custom-inputs" id="aibCustomInputs" hidden>
                  <input type="number" id="aibBudgetMin" placeholder="Min budget (₱)" min="0" step="500" aria-label="Min budget (₱)">
                  <span>to</span>
                  <input type="number" id="aibBudgetMax" placeholder="Max budget (₱)" min="0" step="500" aria-label="Max budget (₱)">
                </div>
              </div>

              <?php // Live feasibility message. Filled by aibUpdateBudgetGate()
                    // from the same server-computed floor, and the thing Next is
                    // gated on for a from-scratch build. ?>
              <p class="aib-budget-note" id="aibBudgetNote" hidden></p>
              <?php if ($aibMinBuild): ?>
                <p class="aib-hint" id="aibBudgetFloorHint" style="margin:6px 0 0;">
                  The lowest complete PC we can build right now starts at about
                  <strong><?= aib_money((float)$aibMinBuild) ?></strong>.
                </p>
              <?php endif; ?>
            </div>
          </div>
          <div class="aibx-wiz-nav">
            <span class="aibx-wiz-count">Step 2 of 4 &middot; Budget</span>
            <span class="aibx-wiz-actions">
            <button type="button" class="aibx-wiz-back" data-wiz-go="1">
              <i class="fi fi-rs-angle-left" aria-hidden="true"></i> Back
            </button>
            <button type="button" class="aib-btn-primary aibx-wiz-next" data-wiz-go="3">
              Next <i class="fi fi-rr-angle-small-right" aria-hidden="true"></i>
            </button>
            </span>
            <span class="aibx-wiz-why" hidden></span>
          </div>
        </div>

        <!-- Wizard step 3 of 4. Wrapped in .aibx-card so every step
             carries the same card treatment as the rail. The section
             inside is untouched - same fields, same ids, same classes -
             so ai-builder-skin.js and the page's own handlers keep
             reading them exactly as before. Hidden steps stay in the
             DOM (hidden, not removed) for the same reason: the live
             brief in the rail is computed from these real inputs. -->
        <div class="aibx-card aibx-wiz-card" data-wiz-step="3" hidden>
          <!-- ===== STEP 03 - questions 3 and 4 =========================== -->
          <div class="aibx-sec" data-aibx-for="work">
            <div class="aibx-sec-head">
              <div>
                <p class="aibx-sec-cap">Step 03 &middot; The work</p>
                <h2 class="aibx-sec-q">What will it be doing?</h2>
              </div>
              <span class="aibx-flag aibx-flag-req">Required</span>
            </div>

            <div class="aib-field">
              <label for="aibPerfSelect" class="aibx-sr">Performance preference <span class="aib-required">*</span></label>
              <span class="aibx-sublabel" aria-hidden="true">Performance target</span>
              <select id="aibPerfSelect" class="aibx-native">
                <option value="">Select performance preference...</option>
                <option value="balanced">Balanced - good mix of price and performance</option>
                <option value="budget">Budget - most economical parts that still work</option>
                <option value="performance">Performance - higher-tier parts</option>
                <option value="high_end">High-End - strongest parts your budget allows</option>
              </select>
              <div id="aibxPerfSkin"></div>
              <?php // Filled by aibUpdateWorkOptions() when a target is out of
                    // reach of the entered budget. Says that something is
                    // missing and why - an option that simply vanishes with no
                    // explanation reads as a bug. ?>
              <p class="aib-hint aib-locked-note" id="aibPerfLockedNote" hidden></p>
            </div>

            <div class="aib-field">
              <label class="aibx-sr">Intended use <span class="aib-required">*</span></label>
              <span class="aibx-sublabel" aria-hidden="true">Intended use</span>
              <div class="aib-dd aibx-native" id="aibUseDd">
                <button type="button" class="aib-dd-btn" id="aibUseDdBtn">Select intended use(s)...</button>
                <div class="aib-dd-panel" id="aibUseDdPanel" hidden>
                  <label class="aib-dd-item"><input type="checkbox" value="gaming"><i class="fi fi-rr-gamepad"></i> Gaming</label>
                  <label class="aib-dd-item"><input type="checkbox" value="office"><i class="fi fi-rr-briefcase"></i> Office / Productivity</label>
                  <label class="aib-dd-item"><input type="checkbox" value="school"><i class="fi fi-rr-graduation-cap"></i> School / Student</label>
                  <label class="aib-dd-item"><input type="checkbox" value="programming"><i class="fi fi-rr-terminal"></i> Programming</label>
                  <label class="aib-dd-item"><input type="checkbox" value="video_editing"><i class="fi fi-rr-video-camera"></i> Video Editing</label>
                  <label class="aib-dd-item"><input type="checkbox" value="photo_editing"><i class="fi fi-rr-camera"></i> Photo Editing</label>
                  <label class="aib-dd-item"><input type="checkbox" value="streaming"><i class="fi fi-rr-podcast"></i> Streaming</label>
                  <label class="aib-dd-item"><input type="checkbox" value="3d_rendering"><i class="fi fi-rr-cube"></i> 3D Rendering</label>
                  <label class="aib-dd-item"><input type="checkbox" value="general"><i class="fi fi-rr-desktop"></i> General Use</label>
                </div>
              </div>
              <div id="aibxUseSkin"></div>
              <?php // Filled by aibfApplyUseFilter(): why some uses are greyed
                    // out at this budget, or - when none fit - what the budget
                    // IS good for. An option that is simply dead with no
                    // explanation reads as a broken page. ?>
              <p class="aib-hint aib-locked-note" id="aibUseLockedNote" hidden></p>
            </div>
          </div>
          <div class="aibx-wiz-nav">
            <span class="aibx-wiz-count">Step 3 of 4 &middot; The work</span>
            <span class="aibx-wiz-actions">
            <button type="button" class="aibx-wiz-back" data-wiz-go="2">
              <i class="fi fi-rs-angle-left" aria-hidden="true"></i> Back
            </button>
            <button type="button" class="aib-btn-primary aibx-wiz-next" data-wiz-go="4">
              Next <i class="fi fi-rr-angle-small-right" aria-hidden="true"></i>
            </button>
            </span>
            <span class="aibx-wiz-why" hidden></span>
          </div>
        </div>

        <!-- Wizard step 4 of 4. Wrapped in .aibx-card so every step
             carries the same card treatment as the rail. The section
             inside is untouched - same fields, same ids, same classes -
             so ai-builder-skin.js and the page's own handlers keep
             reading them exactly as before. Hidden steps stay in the
             DOM (hidden, not removed) for the same reason: the live
             brief in the rail is computed from these real inputs. -->
        <div class="aibx-card aibx-wiz-card" data-wiz-step="4" hidden>
          <!-- ===== STEP 04 - questions 5 and 6 =========================== -->
          <div class="aibx-sec" data-aibx-for="extras">
            <div class="aibx-sec-head">
              <div>
                <p class="aibx-sec-cap">Step 04 &middot; Anything else</p>
                <h2 class="aibx-sec-q">Any must-haves we should know about?</h2>
              </div>
              <span class="aibx-flag aibx-flag-opt">Optional</span>
            </div>

            <div class="aibx-extras">
              <div class="aib-field">
                <label class="aibx-sr">Specific requirements <span class="aib-hint" style="display:inline;">(optional)</span></label>
                <span class="aibx-sublabel" aria-hidden="true">Must-haves</span>
                <div class="aib-dd aibx-native" id="aibReqDd">
                  <button type="button" class="aib-dd-btn" id="aibReqDdBtn">Select requirement(s)...</button>
                  <div class="aib-dd-panel" id="aibReqDdPanel" hidden>
                    <label class="aib-dd-item"><input type="checkbox" value="wifi"> Wi-Fi</label>
                    <label class="aib-dd-item"><input type="checkbox" value="bluetooth"> Bluetooth</label>
                    <label class="aib-dd-item"><input type="checkbox" value="rgb"> RGB</label>
                    <label class="aib-dd-item"><input type="checkbox" value="large_storage"> Large Storage</label>
                    <label class="aib-dd-item"><input type="checkbox" value="fast_ssd"> Fast SSD</label>
                    <label class="aib-dd-item"><input type="checkbox" value="high_ram"> High RAM</label>
                    <label class="aib-dd-item"><input type="checkbox" value="dedicated_gpu"> Dedicated GPU</label>
                    <label class="aib-dd-item"><input type="checkbox" value="quiet"> Quiet PC</label>
                    <label class="aib-dd-item"><input type="checkbox" value="upgradeable"> Upgradeable PC</label>
                  </div>
                </div>
                <div id="aibxReqSkin"></div>
              </div>

              <div class="aib-field">
                <label for="aibBrand">Preferred brand <span class="aib-hint" style="display:inline;">(optional)</span></label>
                <input type="text" id="aibBrand" placeholder="e.g. AMD, Intel, ASUS">
              </div>
            </div>

            <div class="aib-field">
              <label for="aibMessage">Anything else? <span class="aib-hint" style="display:inline;">(optional)</span></label>
              <textarea id="aibMessage" rows="3" placeholder="Tell us more in your own words - e.g. &quot;I mostly play competitive shooters and stream on the side&quot;"></textarea>
            </div>
          </div>
          <div class="aibx-wiz-nav">
            <span class="aibx-wiz-count">Step 4 of 4 &middot; Extras</span>
            <span class="aibx-wiz-actions">
            <button type="button" class="aibx-wiz-back" data-wiz-go="3">
              <i class="fi fi-rs-angle-left" aria-hidden="true"></i> Back
            </button>
            <button type="button" class="aib-btn-primary aibx-wiz-build" id="aibWizBuild" disabled>
              Build My PC
            </button>
            </span>
          </div>
        </div>

        <!-- The "* Required" legend that used to sit here is gone: it applied
             to the whole single-page form, and now that the form is one step
             per card the required marks are already visible beside the fields
             of whichever step is on screen. The per-field <span class="aib-required">*</span>
             marks themselves are untouched. -->
      </section>

      <aside class="aibx-rail">
        <div class="aibx-card">
          <span class="aibx-scan" aria-hidden="true"></span>
          <p class="aibx-card-cap">Your brief</p>
          <!-- Every value here is read off the real inputs by
               ai-builder-skin.js; nothing is stored twice. -->
          <div aria-live="polite">
            <div class="aibx-brief-row" data-k="base"><span class="aibx-brief-k">Base</span><span class="aibx-brief-v" data-empty="1">&mdash;</span></div>
            <div class="aibx-brief-row" data-k="budget"><span class="aibx-brief-k">Budget</span><span class="aibx-brief-v" data-empty="1">&mdash;</span></div>
            <div class="aibx-brief-row" data-k="use"><span class="aibx-brief-k">Use</span><span class="aibx-brief-v" data-empty="1">&mdash;</span></div>
            <div class="aibx-brief-row" data-k="target"><span class="aibx-brief-k">Target</span><span class="aibx-brief-v" data-empty="1">&mdash;</span></div>
            <div class="aibx-brief-row" data-k="extras"><span class="aibx-brief-k">Extras</span><span class="aibx-brief-v" data-empty="1">&mdash;</span></div>
          </div>
          <p class="aibx-ready">Ready to build &middot; 0 of 4 answered</p>
          <div class="aibx-bar"><span class="aibx-bar-fill"></span></div>
          <button type="button" class="aib-btn-primary" id="aibGenerate" disabled>Build My PC</button>
          <p class="aibx-cta-missing" hidden></p>
          <p class="aibx-cta-note">~15 seconds &middot; nothing charged</p>
        </div>

        <div class="aibx-card">
          <p class="aibx-card-cap">How it picks</p>
          <ul class="aibx-how">
            <li>Every part is checked against the others &mdash; socket, wattage, clearance, RAM generation.</li>
            <li>Only items we currently have in stock in Caloocan are recommended.</li>
            <li>You get three options at your budget, with the trade-off of each spelled out.</li>
          </ul>
        </div>
      </aside>
      </div><!-- /.aibx-grid -->

      <section class="aib-step" data-step="2" hidden>
        <div class="aib-loading">
          <div class="aib-spinner"></div>
          <p id="aibLoadingMsg">Analyzing your requirements...</p>
        </div>
      </section>

      <section class="aib-step" data-step="3" hidden>
        <div id="aibResult"></div>
      </section>
    </div>
  </div>
</div>

<script>
const AIB_CATEGORY_NAMES = <?= json_encode($categoryNames) ?>;
const AIB_API = window.WEB_ROOT + 'public/authenticated/api/ai_builder.php';
const AIB_PLACEHOLDER_IMG = window.WEB_ROOT + 'public/shared/img/products/placeholder.svg';

/* What the catalogue can actually support, computed server-side by
   config/ai_builder_budget.php and handed over whole. The browser never
   works any of this out for itself - it only reads these numbers - which is
   what keeps the wizard and the API in agreement. The API re-derives the
   same figures per request, so editing this object in devtools changes what
   is shown and nothing about what is accepted. */
const AIB_RULES = <?= json_encode($aibBudgetRules, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

/* Option filtering: use x performance target -> the real minimum budget for
   that combination, priced from live stock by config/ai_builder_filter.php.
   Same contract as AIB_RULES above - read-only, and re-derived server-side on
   every request, so devtools can change what is drawn and nothing about what
   is accepted. */
const AIB_FILTER = <?= json_encode($aibFilterRules, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

const aibState = {
  basePcId: null,
  budgetMin: null,
  budgetMax: null,
  uses: [],
  performance: null,
  requirements: [],
  brand: '',
  message: '',
};

/** Peso formatting that matches aib_money() on the server, so the two never
 *  quote the same floor differently. */
function aibFloorMoney(n) {
  return '₱' + Math.round(Number(n) || 0).toLocaleString('en-PH');
}

/**
 * Is the entered budget enough to build anything at all?
 *
 * Only applies to a from-scratch build. "I own a TechWorld desktop" buys
 * add-ons, so it is tested against the cheapest single part instead - a
 * customer topping up an existing PC must not be told they need PHP 8,395.
 */
function aibBudgetFeasibility() {
  const max = Number(aibState.budgetMax) || 0;
  const onOwnedPc = !!aibState.basePcId;
  const floor = onOwnedPc ? AIB_RULES.min_addon : AIB_RULES.min_build;

  if (!floor) { return { ok: true, floor: 0 }; }        // nothing to enforce
  if (!max) { return { ok: false, floor: floor, empty: true }; }
  if (max < floor) {
    return {
      ok: false,
      floor: floor,
      message: onOwnedPc
        ? 'The cheapest add-on we have in stock is about ' + aibFloorMoney(floor) + '. Please enter at least ' + aibFloorMoney(floor) + '.'
        : 'The lowest complete PC we can build right now starts at about ' + aibFloorMoney(floor) + '. Please enter at least ' + aibFloorMoney(floor) + '.'
    };
  }
  return { ok: true, floor: floor };
}

/** Shows the message and disables Next on the budget step while the budget
 *  cannot buy anything. Re-run on every change to budget or base PC. */
function aibUpdateBudgetGate() {
  const note = document.getElementById('aibBudgetNote');
  const card = document.querySelector('[data-wiz-step="2"]');
  const next = card ? card.querySelector('.aibx-wiz-next') : null;
  const f = aibBudgetFeasibility();

  if (note) {
    if (!f.ok && f.message) {
      note.textContent = f.message;
      note.hidden = false;
    } else {
      note.hidden = true;
      note.textContent = '';
    }
  }
  if (next) {
    // An empty budget is already blocked by the wizard's own completeness
    // check; this only adds the "too low to build" case, so an empty field
    // is not given a second, louder complaint.
    next.disabled = !f.ok && !f.empty;
  }
  const hint = document.getElementById('aibBudgetFloorHint');
  if (hint) { hint.hidden = !!aibState.basePcId; }
}

/* ----------------------------------------------------------------------
   Step 3: only offer targets the budget can actually reach.

   The floor for a performance target depends on which use drives the
   build - "high-end" means a top-percentile graphics card for gaming but
   a top-percentile processor for office work, and those cost different
   amounts. So this re-runs when the budget changes AND when the selected
   uses change.
   -------------------------------------------------------------------- */

/** The most demanding selected use, using the engine's own precedence list. */
function aibPrimaryUse() {
  const order = AIB_RULES.use_precedence || [];
  for (const use of order) {
    if (aibState.uses.indexOf(use) !== -1) { return use; }
  }
  return 'general';
}

/** Minimum budget for a target under the current use, or null if unknown. */
function aibTargetFloor(target) {
  const byUse = AIB_RULES.by_use || {};
  const forUse = byUse[aibPrimaryUse()] || byUse['general'] || {};
  const v = forUse[target];
  return (v === null || v === undefined) ? null : Number(v);
}

/* ----------------------------------------------------------------------
   OPTION FILTERING (step 3)

   Each step only offers choices the previous answers can actually pay for:
   the budget decides which performance targets are reachable, and the budget
   plus the target decides which intended uses are reachable. Every limit is
   read out of AIB_FILTER, which was priced from live stock server-side -
   nothing is worked out or guessed here.

   WHAT CHANGED, AND WHY: aibUpdateWorkOptions() used to HIDE an unreachable
   performance target (opt.hidden = true). It now DISABLES it instead, with
   the reason printed on the tile ("Needs PHP 13,595+"). An option that
   vanishes tells the customer nothing - they cannot see that high-end exists,
   let alone what it would take to afford it. Everything else the function did
   is kept: the same floors, the same clearing of an answer that has just
   become invalid, the same notification, the same final call to
   aibUpdateGenerateButton(), and the same exemption for a build on a desktop
   the customer already owns.
   -------------------------------------------------------------------- */

/** Cheapest performance target, i.e. the one assumed before one is chosen. */
function aibfCheapestTarget() {
  const t = AIB_FILTER.targets || [];
  return t.length ? t[0] : 'budget';
}

/** The target currently chosen, or the cheapest one while none is. */
function aibfSelectedTarget() {
  const sel = document.getElementById('aibPerfSelect');
  const v = sel ? sel.value : '';
  return v || aibfCheapestTarget();
}

/** Minimum budget for one use at one target, or null when unknown. */
function aibfUseFloor(use, target) {
  const row = (AIB_FILTER.matrix || {})[use];
  if (!row) { return null; }
  const v = row[target];
  return (v === null || v === undefined) ? null : Number(v);
}

/**
 * Minimum budget for a performance target.
 *
 * With no use ticked yet this is the cheapest use that reaches the target
 * (the most generous honest answer - the customer can still pick that use).
 * Once uses are ticked it is the dearest of them, because the build has to
 * serve all of them. Falls back to the pre-existing aibTargetFloor() if the
 * matrix has nothing to say, so the old behaviour is never lost.
 */
function aibfTargetFloor(target) {
  let floor = null;
  for (const use of aibState.uses) {
    const f = aibfUseFloor(use, target);
    if (f !== null && (floor === null || f > floor)) { floor = f; }
  }
  if (floor !== null) { return floor; }

  const global = (AIB_FILTER.target_floors || {})[target];
  if (global !== null && global !== undefined) { return Number(global); }
  return aibTargetFloor(target);
}

/** "Needs PHP 13,595+", worded by the server so both sides read the same. */
function aibfNeedNote(floor) {
  const tpl = (AIB_FILTER.notes || {}).need_more || 'Needs %s+';
  return tpl.replace('%s', aibFloorMoney(floor));
}

/** Writes (or clears) the short reason line inside a skin tile. */
function aibfSetTileNote(tile, text) {
  if (!tile) { return; }
  let why = tile.querySelector('.aibx-tile-why');
  if (!text) { if (why) { why.remove(); } return; }
  if (!why) {
    why = document.createElement('span');
    why.className = 'aibx-tile-why';
    tile.appendChild(why);
  }
  why.textContent = text;
}

/**
 * Paints one option as available or locked, everywhere it appears: the real
 * control the payload is read from, and the tile ai-builder-skin.js drew over
 * it. A disabled <button> fires no click, so a locked tile cannot be pressed;
 * a disabled <option> cannot be chosen with the keyboard either.
 */
function aibfPaintOption(control, tile, locked, note) {
  if (control) {
    control.disabled = locked;
    control.hidden = false;           // locked is greyed out, never removed
    if (locked && note) { control.title = note; } else { control.removeAttribute('title'); }
  }
  if (tile) {
    tile.disabled = locked;
    tile.hidden = false;
    tile.classList.toggle('is-locked', locked);
    tile.setAttribute('aria-disabled', locked ? 'true' : 'false');
    if (locked && note) { tile.title = note; } else { tile.removeAttribute('title'); }
    aibfSetTileNote(tile, locked ? note : '');
  }
}

/** True when nothing should be locked at all (no budget yet, or add-ons). */
function aibfFilteringOff() {
  return !!aibState.basePcId || !(Number(aibState.budgetMax) > 0);
}

/**
 * Performance targets: lock the ones this budget cannot reach.
 * @return {boolean} true if the chosen target had to be cleared.
 */
function aibfPaintTargets() {
  const sel = document.getElementById('aibPerfSelect');
  const note = document.getElementById('aibPerfLockedNote');
  if (!sel) { return false; }

  const off = aibfFilteringOff();
  const budget = Number(aibState.budgetMax) || 0;

  let lockedCount = 0;
  let clearedLabel = null;
  let clearedFloor = null;

  Array.prototype.forEach.call(sel.options, function (opt) {
    if (!opt.value) { return; }
    const floor = aibfTargetFloor(opt.value);
    const locked = !off && floor !== null && budget < floor;
    const tile = document.querySelector('#aibxPerfSkin [data-aibx-value="' + opt.value + '"]');

    aibfPaintOption(opt, tile, locked, locked ? aibfNeedNote(floor) : '');

    if (locked) {
      lockedCount++;
      // The selected target just became unreachable - drop it rather than
      // submitting something the server is going to refuse anyway.
      if (sel.value === opt.value) {
        clearedLabel = (AIB_RULES.target_labels || {})[opt.value] || opt.value;
        clearedFloor = floor;
        sel.value = '';
        aibState.performance = null;
      }
    }
  });

  if (note) {
    if (lockedCount > 0) {
      note.textContent = 'Greyed-out targets need more than your budget of '
        + aibFloorMoney(budget) + '. Each one shows what it would take.';
      note.hidden = false;
    } else {
      note.hidden = true;
      note.textContent = '';
    }
  }

  if (clearedLabel) {
    // Tell them what changed and why. Silently unselecting looks like the
    // page lost their answer.
    showNotification(
      'We cleared the "' + clearedLabel + '" target: a ' + clearedLabel + ' build needs about '
      + aibFloorMoney(clearedFloor) + ', which is above your budget of '
      + aibFloorMoney(aibState.budgetMax) + '.',
      'info'
    );
    // Repaint the skin's pressed state and re-run the page's own gating -
    // kept from the original function. Safe to dispatch from in here: the
    // handlers it wakes call aibUpdateWorkOptions(), which aibfSettling
    // short-circuits until this pass is finished.
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }
  return false;
}

/**
 * Intended use: lock the uses this budget cannot genuinely serve at the
 * chosen performance target, and untick any that no longer fit.
 * @return {boolean} true if a ticked use had to be cleared.
 */
function aibfPaintUses() {
  const panel = document.getElementById('aibUseDdPanel');
  const note = document.getElementById('aibUseLockedNote');
  if (!panel) { return false; }

  const off = aibfFilteringOff();
  const budget = Number(aibState.budgetMax) || 0;
  const target = aibfSelectedTarget();
  const labels = AIB_FILTER.use_labels || {};

  const boxes = Array.prototype.slice.call(panel.querySelectorAll('input[type="checkbox"]'));
  const tiles = document.querySelectorAll('#aibxUseSkin .aibx-tile');

  let lockedCount = 0;
  let openCount = 0;
  let cheapestLocked = null;
  const cleared = [];
  const affordable = [];

  boxes.forEach(function (cb, i) {
    const floor = aibfUseFloor(cb.value, target);
    // A use we cannot build at all (floor null) is locked too, but with no
    // amount attached - no budget unlocks a part we do not stock.
    const locked = !off && (floor === null || budget < floor);
    const why = floor === null
      ? 'Not available from current stock'
      : (locked ? aibfNeedNote(floor) : '');

    /* Match the tile by the value it mirrors, falling back to checkbox order
       for a skin build that predates data-aibx-value on these tiles. */
    const tile = document.querySelector('#aibxUseSkin [data-aibx-value="' + cb.value + '"]')
      || tiles[i] || null;
    aibfPaintOption(cb, tile, locked, why);

    const row = cb.closest('.aib-dd-item');
    if (row) {
      row.classList.toggle('is-locked', locked);
      let rowWhy = row.querySelector('.aib-dd-why');
      if (locked) {
        if (!rowWhy) {
          rowWhy = document.createElement('span');
          rowWhy.className = 'aib-dd-why';
          row.appendChild(rowWhy);
        }
        rowWhy.textContent = why;
      } else if (rowWhy) { rowWhy.remove(); }
    }

    if (locked) {
      lockedCount++;
      if (floor !== null && (cheapestLocked === null || floor < cheapestLocked)) { cheapestLocked = floor; }
      if (cb.checked) {
        // Clear an answer that no longer fits, and let the page's own
        // dropdown handler update aibState.uses, the button label and the
        // Build gate exactly as it does for a real click.
        cb.checked = false;
        cleared.push(labels[cb.value] || cb.value);
        cb.dispatchEvent(new Event('change', { bubbles: true }));
      }
    } else {
      openCount++;
      affordable.push(labels[cb.value] || cb.value);
    }
  });

  if (note) {
    if (off || lockedCount === 0) {
      note.hidden = true;
      note.textContent = '';
    } else if (openCount === 0) {
      // Nothing fits at all - say what WOULD, rather than leaving a dead step.
      note.textContent = cheapestLocked !== null
        ? 'No intended use fits ' + aibFloorMoney(budget) + ' at this performance target. '
          + 'The cheapest one starts at about ' + aibFloorMoney(cheapestLocked)
          + ' - raise your budget, or choose a lower performance target.'
        : 'We cannot build for any of these uses from current stock. Please try again later.';
      note.hidden = false;
    } else {
      note.textContent = 'Your budget of ' + aibFloorMoney(budget) + ' is best for '
        + affordable.slice(0, 3).join(', ')
        + '. Raise it to unlock the greyed-out uses - each one shows what it needs.';
      note.hidden = false;
    }
  }

  if (cleared.length) {
    showNotification(
      'We cleared ' + cleared.join(' and ') + ' from your intended use: a '
      + ((AIB_RULES.target_labels || {})[target] || target) + ' build for that needs more than '
      + aibFloorMoney(budget) + '.',
      'info'
    );
    return true;
  }
  return false;
}

/**
 * Re-check every later step against the earlier answers.
 *
 * Called on every change to the base PC, the budget and the uses (the
 * existing call sites, unchanged) and now also on a change of performance
 * target. Clearing an answer changes what the OTHER step can offer - dropping
 * gaming lowers the target floors - so it settles in a short bounded loop
 * instead of leaving one step a repaint behind. aibfSettling stops the
 * synthetic change events it dispatches from re-entering it.
 */
let aibfSettling = false;
function aibUpdateWorkOptions() {
  if (aibfSettling) { return; }
  aibfSettling = true;
  try {
    let passes = 0;
    let changed = true;
    while (changed && passes < 3) {
      const t = aibfPaintTargets();
      const u = aibfPaintUses();
      changed = t || u;
      passes++;
    }
  } finally {
    aibfSettling = false;
  }

  if (typeof aibUpdateGenerateButton === 'function') { aibUpdateGenerateButton(); }
}
let aibBuilds = [];       // all builds returned by ai_recommend (primary + alternates)
let aibActiveBuild = 0;   // index into aibBuilds currently shown in detail
let aibSource = null;     // 'gemini' | 'fallback_deterministic' | 'deterministic_base_pc'
let aibModel = null;
let aibFallbackReason = null;

function aibMoney(n) {
  n = Number(n) || 0;
  return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function aibShowStep(step) {
  document.querySelectorAll('.aib-step').forEach(el => { el.hidden = (parseInt(el.dataset.step, 10) !== step); });
  window.scrollTo({ top: document.querySelector('.aib-panel').offsetTop - 20, behavior: 'smooth' });
}

// --- Single requirements form (Step 1) ---
const basePcSelect = document.getElementById('aibBasePc');
if (basePcSelect) {
  basePcSelect.addEventListener('change', () => {
    aibState.basePcId = basePcSelect.value || null;
    // Switching between "from scratch" and "I own one" changes which floor
    // applies - a whole PC vs a single add-on - so the gate is re-run.
    aibUpdateBudgetGate();
    aibUpdateWorkOptions();
  });
}

// Budget, Performance preference, and Intended use are required - "Build My
// PC" starts disabled and only re-enables once all three are actually set,
// re-checked after every change to any of them. Requirements/brand/message
// stay optional and don't affect this.
function aibRequiredFieldsFilled() {
  const budgetOk = !!aibState.budgetMax && aibState.budgetMax > 0
    && (!aibState.budgetMin || aibState.budgetMin <= aibState.budgetMax);
  const performanceOk = !!aibState.performance;
  const usesOk = aibState.uses.length > 0;
  return budgetOk && performanceOk && usesOk;
}
function aibUpdateGenerateButton() {
  document.getElementById('aibGenerate').disabled = !aibRequiredFieldsFilled();
}

document.getElementById('aibBudgetPreset').addEventListener('change', function () {
  const customInputs = document.getElementById('aibCustomInputs');
  if (this.value === 'custom') {
    customInputs.hidden = false;
    aibState.budgetMin = null;
    aibState.budgetMax = null;
  } else if (this.value) {
    customInputs.hidden = true;
    const [min, max] = this.value.split(',').map(Number);
    aibState.budgetMin = min;
    aibState.budgetMax = max;
  } else {
    customInputs.hidden = true;
    aibState.budgetMin = null;
    aibState.budgetMax = null;
  }
  aibUpdateGenerateButton();
  aibUpdateBudgetGate();
  aibUpdateWorkOptions();
});
const aibMinInput = document.getElementById('aibBudgetMin');
const aibMaxInput = document.getElementById('aibBudgetMax');
function aibSyncCustomBudget() {
  aibState.budgetMin = parseFloat(aibMinInput.value) || 0;
  aibState.budgetMax = parseFloat(aibMaxInput.value) || 0;
  aibUpdateGenerateButton();
  aibUpdateBudgetGate();
  aibUpdateWorkOptions();
}
aibMinInput.addEventListener('input', aibSyncCustomBudget);
aibMaxInput.addEventListener('input', aibSyncCustomBudget);

document.getElementById('aibPerfSelect').addEventListener('change', function () {
  aibState.performance = this.value;
  aibUpdateGenerateButton();
});
// Second, separate listener: the chosen performance target decides which
// intended uses are affordable, so the use step is re-filtered after the
// answer above is recorded. Kept apart from that handler so its original
// behaviour is untouched.
document.getElementById('aibPerfSelect').addEventListener('change', function () {
  aibUpdateWorkOptions();
});

// Compact "dropdown with checkboxes" multi-select, used for both Intended
// Use and Requirements - a button that toggles a small floating checklist,
// closes on an outside click, and keeps its own state array in sync.
function aibSetupDropdown(ddId, btnId, panelId, stateArray, placeholder) {
  const btn = document.getElementById(btnId);
  const panel = document.getElementById(panelId);
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    document.querySelectorAll('.aib-dd-panel').forEach(p => { if (p !== panel) { p.hidden = true; } });
    panel.hidden = !panel.hidden;
  });
  panel.querySelectorAll('input[type="checkbox"]').forEach(cb => {
    cb.addEventListener('change', () => {
      const idx = stateArray.indexOf(cb.value);
      if (cb.checked && idx === -1) { stateArray.push(cb.value); }
      else if (!cb.checked && idx !== -1) { stateArray.splice(idx, 1); }
      const labels = Array.from(panel.querySelectorAll('input:checked')).map(c => c.closest('.aib-dd-item').textContent.trim());
      btn.textContent = labels.length === 0 ? placeholder : (labels.length <= 2 ? labels.join(', ') : labels.length + ' selected');
      aibUpdateGenerateButton();
      // The use decides which part the performance target is measured on, so
      // the reachable targets change with it.
      if (stateArray === aibState.uses) { aibUpdateWorkOptions(); }
    });
  });
}
aibSetupDropdown('aibUseDd', 'aibUseDdBtn', 'aibUseDdPanel', aibState.uses, 'Select intended use(s)...');
aibSetupDropdown('aibReqDd', 'aibReqDdBtn', 'aibReqDdPanel', aibState.requirements, 'Select requirement(s)...');

/* First paint. Deferred to load so ai-builder-skin.js has already drawn the
   tiles this locks - it builds them on DOMContentLoaded. */
window.addEventListener('load', function () {
  aibUpdateBudgetGate();
  aibUpdateWorkOptions();
});
document.addEventListener('click', () => { document.querySelectorAll('.aib-dd-panel').forEach(p => { p.hidden = true; }); });

document.getElementById('aibBrand').addEventListener('input', function () { aibState.brand = this.value; });
document.getElementById('aibMessage').addEventListener('input', function () { aibState.message = this.value; });
document.getElementById('aibGenerate').addEventListener('click', () => {
  // The button is disabled until aibRequiredFieldsFilled() is true, but this
  // stays as a defensive fallback (e.g. state somehow out of sync) with
  // specific messages for whichever field is actually missing.
  if (!aibState.budgetMax || aibState.budgetMax <= 0) {
    showNotification('Please choose or enter a budget first.', 'error');
    return;
  }
  if (aibState.budgetMin && aibState.budgetMin > aibState.budgetMax) {
    showNotification('Minimum budget cannot be higher than the maximum.', 'error');
    return;
  }
  if (!aibState.performance) {
    showNotification('Select a performance preference.', 'error');
    return;
  }
  if (aibState.uses.length === 0) {
    showNotification('Select at least one intended use.', 'error');
    return;
  }
  aibGenerate();
});

// --- Step 5: Analysis + API call ---
const AIB_LOADING_MESSAGES = [
  'Analyzing your requirements...',
  'Checking component compatibility...',
  'Finding the best products within your budget...',
  'Optimizing your PC build...',
];
function aibGenerate() {
  aibShowStep(2);
  const msgEl = document.getElementById('aibLoadingMsg');
  let msgIndex = 0;
  msgEl.textContent = AIB_LOADING_MESSAGES[0];
  const msgTimer = setInterval(() => {
    msgIndex = (msgIndex + 1) % AIB_LOADING_MESSAGES.length;
    msgEl.textContent = AIB_LOADING_MESSAGES[msgIndex];
  }, 900);

  const minDisplayMs = 2400;
  const startedAt = Date.now();

  fetch(AIB_API + '?action=ai_recommend', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      base_product_id: aibState.basePcId,
      budget_min: aibState.budgetMin,
      budget_max: aibState.budgetMax,
      intended_use: aibState.uses,
      performance: aibState.performance,
      requirements: aibState.requirements,
      brand: aibState.brand,
      message: aibState.message,
    }),
  })
    .then(r => r.json())
    .then(data => {
      const elapsed = Date.now() - startedAt;
      const wait = Math.max(0, minDisplayMs - elapsed);
      setTimeout(() => {
        clearInterval(msgTimer);
        if (!data.success || !data.builds || data.builds.length === 0) {
          showNotification(data.message || 'Could not generate a recommendation.', 'error');
          aibShowStep(1);
          return;
        }
        aibBuilds = data.builds;
        aibActiveBuild = 0;
        aibSource = data.source;
        aibModel = data.model;
        aibFallbackReason = data.fallback_reason || null;
        aibRenderGrid();
        aibShowStep(3);
      }, wait);
    })
    .catch(err => {
      clearInterval(msgTimer);
      console.error(err);
      showNotification('Something went wrong generating your build. Please try again.', 'error');
      aibShowStep(1);
    });
}

// --- Step 6: Result rendering (unified build shape from either Gemini or
// the deterministic-engine fallback - see aib_ai_shape_from_deterministic()
// and aib_ai_validate_build() in config/ai_builder_ai.php) ---
function aibEsc(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

function aibLabelFor(entry) {
  if (entry.label) { return entry.label; }
  const catId = entry.product && entry.product.category_id;
  return (catId && AIB_CATEGORY_NAMES[catId]) || 'Component';
}

function aibComponentCard(entry, buildIdx) {
  const p = entry.product;
  const img = p.image_url ? (window.WEB_ROOT + p.image_url) : AIB_PLACEHOLDER_IMG;
  return `<div class="aib-component" data-product-id="${p.id}">
    <img src="${img}" alt="" onerror="this.src='${AIB_PLACEHOLDER_IMG}'">
    <div class="aib-component-body">
      <div class="aib-component-role">${aibEsc(aibLabelFor(entry))}</div>
      <div class="aib-component-name">${aibEsc(p.name)}</div>
      <div class="aib-component-explain">${aibEsc(entry.reason || '')}</div>
      <div class="aib-component-foot">
        <span class="aib-component-price">${aibMoney(p.price)}</span>
        <div style="display:flex; gap:8px;">
          <button type="button" class="aib-add-btn" onclick="addToCart(${p.id})">Add to Cart</button>
          <button type="button" class="aib-change-btn" onclick="aibToggleReplace(this, ${buildIdx}, ${p.id}, ${p.category_id})">Change</button>
        </div>
      </div>
      <div class="aib-replace-panel" hidden></div>
    </div>
  </div>`;
}

function aibMissingItemCard(text) {
  return `<div class="aib-component unavailable">
    <div class="aib-component-body">
      <div class="aib-component-role">Not Included</div>
      <div class="aib-component-explain">${aibEsc(text)}</div>
    </div>
  </div>`;
}

function aibSourceBadge() {
  if (aibSource === 'gemini') {
    return `<div class="aib-source-badge ai"><i class="fi fi-rr-sparkles"></i> AI-generated recommendation${aibModel ? ' (' + aibEsc(aibModel) + ')' : ''}</div>`;
  }
  return `<div class="aib-source-badge fallback"><i class="fi fi-rr-settings-sliders"></i> Rule-based recommendation${aibFallbackReason ? ' - AI unavailable: ' + aibEsc(aibFallbackReason) : ''}</div>`;
}

function aibSuggestedCard(b, idx) {
  const statusLabel = b.compatibility_status === 'ok' ? 'Compatible' : (b.compatibility_status === 'partial' ? 'Mostly Compatible' : 'Issues Found');
  const budgetLine = b.over_budget ? 'Over budget by ' + aibMoney(b.total_price - b.budget_max) : aibMoney(b.remaining) + ' under budget';
  return `<div class="aib-suggested-card">
    <span class="aib-suggested-badge ${b.compatibility_status}">${statusLabel}</span>
    <b>${aibEsc(b.build_name)}</b>
    <p>${aibEsc(b.purpose_summary || '')}</p>
    <span class="aib-suggested-price">${aibMoney(b.total_price)}</span>
    <p>${budgetLine}</p>
    <button type="button" class="aib-btn-primary" style="padding:9px 16px; font-size:13px;" onclick="aibShowDetail(${idx})">Configure</button>
  </div>`;
}

// Entry point after a recommendation comes back: a grid of every suggested
// build (at least 3 whenever the catalog allows it), PCWorth-AI-Builder
// style - "Configure" on a card opens the full detail/replace view.
function aibRenderGrid() {
  const el = document.getElementById('aibResult');
  let html = aibSourceBadge();

  html += `<div class="aib-suggested-title" style="margin-top:0;">Suggested Build${aibBuilds.length > 1 ? 's' : ''} for Your Budget</div>`;
  html += '<div class="aib-suggested-grid">' + aibBuilds.map((b, idx) => aibSuggestedCard(b, idx)).join('') + '</div>';

  html += `<div class="aib-result-actions">
    <button type="button" class="aib-btn-secondary" id="aibAdjustBtn">Adjust &amp; Regenerate</button>
    <button type="button" class="aib-btn-secondary" id="aibStartOverBtn0">Start New Build</button>
  </div>`;

  el.innerHTML = html;
  document.getElementById('aibAdjustBtn').addEventListener('click', () => aibShowStep(1));
  document.getElementById('aibStartOverBtn0').addEventListener('click', aibResetWizard);
}

function aibShowDetail(idx) {
  aibRenderDetail(idx);
  window.scrollTo({ top: document.querySelector('.aib-panel').offsetTop - 20, behavior: 'smooth' });
}

// Full single-build view: compatibility banner, budget breakdown,
// component-by-component list with "Change" pickers, cart/save actions.
function aibRenderDetail(idx) {
  aibActiveBuild = idx;
  const el = document.getElementById('aibResult');
  const build = aibBuilds[idx];
  let html = `<button type="button" class="aib-btn-secondary" id="aibBackToGridBtn" style="margin-bottom:18px;">&larr; Back to Suggested Builds</button>`;
  html += aibSourceBadge();

  if (build.compatibility_status === 'ok') {
    html += `<div class="aib-result-banner ok"><i class="fi fi-rr-check-circle"></i><div><b>All selected components are compatible.</b></div></div>`;
  } else if (build.compatibility_status === 'partial') {
    html += `<div class="aib-result-banner partial"><i class="fi fi-rr-info"></i><div><b>Mostly compatible - a few details couldn't be fully verified.</b>
      <ul>${(build.notes || []).map(n => `<li>${aibEsc(n)}</li>`).join('')}</ul></div></div>`;
  } else {
    html += `<div class="aib-result-banner issues"><i class="fi fi-rr-triangle-warning"></i><div><b>Compatibility issues found:</b>
      <ul>${(build.compatibility_issues || []).map(n => `<li>${aibEsc(n)}</li>`).join('')}</ul></div></div>`;
  }

  html += `<div class="aib-budget-summary">
    <div class="aib-budget-box"><span>Estimated Total</span><b id="aibTotalPriceBox">${aibMoney(build.total_price)}</b></div>
    <div class="aib-budget-box"><span>Your Budget</span><b>${aibMoney(build.budget_max)}</b></div>
    <div class="aib-budget-box ${build.over_budget ? 'over' : 'remaining'}" id="aibRemainingBox"><span>${build.over_budget ? 'Over Budget By' : 'Remaining'}</span><b>${aibMoney(Math.abs(build.remaining))}</b></div>
  </div>`;

  html += `<div class="aib-summary-box"><b>${aibEsc(build.build_name)}</b>${build.purpose_summary ? ' - ' + aibEsc(build.purpose_summary) : ''}<br><br>${aibEsc(build.explanation)}</div>`;

  if (build.base_product) {
    html += `<div class="aib-component">
      <img src="${build.base_product.image_url ? (window.WEB_ROOT + build.base_product.image_url) : AIB_PLACEHOLDER_IMG}" alt="" onerror="this.src='${AIB_PLACEHOLDER_IMG}'">
      <div class="aib-component-body">
        <div class="aib-component-role">Base PC</div>
        <div class="aib-component-name">${aibEsc(build.base_product.name)}</div>
        <div class="aib-component-foot"><span class="aib-component-price">${aibMoney(build.base_product.price)}</span></div>
      </div>
    </div>`;
  }

  html += '<div id="aibComponentList">' + build.components.map(entry => aibComponentCard(entry, aibActiveBuild)).join('') + '</div>';

  if (build.missing_items && build.missing_items.length > 0) {
    html += build.missing_items.map(m => aibMissingItemCard(m)).join('');
  }

  html += `<div class="aib-result-actions">
    <button type="button" class="aib-btn-primary" id="aibAddAllBtn">Add This Build to Cart</button>
    <button type="button" class="aib-btn-secondary" id="aibSaveBtn">Save This Build</button>
    <button type="button" class="aib-btn-secondary" id="aibStartOverBtn">Start New Build</button>
  </div>
  <div class="aib-save-row" id="aibSaveRow" hidden>
    <input type="text" id="aibSaveTitle" placeholder="Name this build (optional)">
    <button type="button" class="aib-add-btn" id="aibSaveConfirmBtn">Save</button>
  </div>`;

  el.innerHTML = html;

  document.getElementById('aibBackToGridBtn').addEventListener('click', aibRenderGrid);
  document.getElementById('aibAddAllBtn').addEventListener('click', () => aibAddBuildToCart(build));
  document.getElementById('aibSaveBtn').addEventListener('click', () => {
    const row = document.getElementById('aibSaveRow');
    // A toggle, so a second click closes it again rather than doing nothing.
    row.hidden = !row.hidden;
    if (!row.hidden) {
      // Put the caret where the next action is, and make sure the row is on
      // screen - it sits below the button, at the bottom of a long result.
      const title = document.getElementById('aibSaveTitle');
      if (title) { title.focus(); }
      row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  });
  document.getElementById('aibSaveConfirmBtn').addEventListener('click', () => aibSaveBuild(build));
  document.getElementById('aibStartOverBtn').addEventListener('click', aibResetWizard);
}

// --- Configure: replace one component within the active build ---
function aibToggleReplace(btn, buildIdx, productId, categoryId) {
  const panel = btn.closest('.aib-component-body').querySelector('.aib-replace-panel');
  const alreadyOpen = !panel.hidden;
  document.querySelectorAll('.aib-replace-panel').forEach(p => { p.hidden = true; p.innerHTML = ''; });
  if (alreadyOpen) { return; }

  panel.hidden = false;
  panel.innerHTML = '<p class="aib-muted">Loading alternatives...</p>';

  fetch(AIB_API + '?action=list_candidates&category_id=' + categoryId)
    .then(r => r.json())
    .then(res => {
      if (!res.success || !res.candidates || res.candidates.length === 0) {
        panel.innerHTML = '<p class="aib-muted">No alternatives available in this category right now.</p>';
        return;
      }
      panel.innerHTML = res.candidates.map(c => `
        <div class="aib-replace-item">
          <span class="aib-replace-item-name">${aibEsc(c.name)}${c.id === productId ? ' (current)' : ''}</span>
          <span class="aib-replace-item-price">${aibMoney(c.price)}</span>
          ${c.id === productId ? '' : `<button type="button" class="aib-replace-pick-btn" onclick="aibPerformReplace(${buildIdx}, ${productId}, ${c.id})">Select</button>`}
        </div>`).join('');
    })
    .catch(() => { panel.innerHTML = '<p class="aib-muted">Could not load alternatives.</p>'; });
}

function aibPerformReplace(buildIdx, oldProductId, newProductId) {
  const build = aibBuilds[buildIdx];
  const currentIds = build.components.map(c => c.product.id);

  fetch(AIB_API + '?action=replace_component', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ current_product_ids: currentIds, old_product_id: oldProductId, new_product_id: newProductId }),
  })
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showNotification(res.message || 'Could not swap this component.', 'error');
        return;
      }
      const idx = build.components.findIndex(c => c.product.id === oldProductId);
      if (idx !== -1) {
        build.components[idx].product = res.product;
        build.components[idx].reason = 'Manually selected replacement.';
      }
      build.total_price = res.total_price;
      build.remaining = round2(build.budget_max - res.total_price);
      build.over_budget = res.total_price > build.budget_max + 0.01;
      build.compatibility_status = res.compatibility_status;
      build.compatibility_issues = res.compatibility_issues;
      build.notes = res.notes;
      showNotification('Component replaced.', 'success');
      aibRenderDetail(buildIdx);
    })
    .catch(() => showNotification('Could not swap this component.', 'error'));
}

function round2(n) { return Math.round(n * 100) / 100; }

function aibAddBuildToCart(build) {
  const ids = [];
  if (build.base_product) { ids.push(build.base_product.id); }
  build.components.forEach(c => { if (c.product) { ids.push(c.product.id); } });
  if (ids.length === 0) {
    showNotification('Nothing available to add to cart.', 'error');
    return;
  }
  fetch(AIB_API + '?action=add_build_to_cart', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ product_ids: ids, bundle_name: build.build_name }),
  })
    .then(r => r.json())
    .then(res => {
      if (res.cart_count !== null && res.cart_count !== undefined) { updateCartCount(res.cart_count); }
      showNotification(res.message, res.success ? 'success' : 'error');
    })
    .catch(() => showNotification('Could not add the build to your cart.', 'error'));
}

/* Re-renders the "N builds saved" chip in the page header.
 *
 * The count is printed server-side on page load and nothing updated it after
 * that, so saving a build left it reading its load-time value until a reload.
 * Every handler that changes the number now returns saved_count, and this is
 * the only thing that writes it - so the chip always shows the server's tally
 * rather than a number the client has been incrementing on its own. */
function aibSetSavedCount(n) {
  if (n === null || n === undefined) { return; }
  document.querySelectorAll('.aibx-saved-count').forEach(el => {
    el.textContent = n + ' build' + (Number(n) === 1 ? '' : 's') + ' saved';
  });
}

function aibSaveBuild(build) {
  const title = document.getElementById('aibSaveTitle').value.trim();
  fetch(AIB_API + '?action=save_build', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      title: title,
      budget_min: aibState.budgetMin,
      budget_max: aibState.budgetMax,
      intended_use: aibState.uses,
      performance: aibState.performance,
      requirements: aibState.requirements,
      build: build,
    }),
  })
    .then(r => r.json())
    .then(res => {
      showNotification(res.message, res.success ? 'success' : 'error');
      aibSetSavedCount(res.saved_count);
      if (res.success) {
        const row = document.getElementById('aibSaveRow');
        row.hidden = true;
        document.getElementById('aibSaveTitle').value = '';
        // The panel, if it happens to be open, is now out of date too.
        if (!document.getElementById('aibSavedPanel').hidden) { aibLoadSavedBuilds(); }
      }
    })
    .catch(() => showNotification('Could not save this build.', 'error'));
}

function aibResetWizard() {
  aibState.basePcId = null;
  aibState.budgetMin = null;
  aibState.budgetMax = null;
  aibState.uses = [];
  aibState.performance = null;
  aibState.requirements = [];
  aibState.brand = '';
  aibState.message = '';
  if (basePcSelect) { basePcSelect.value = ''; }
  document.getElementById('aibBudgetPreset').value = '';
  document.getElementById('aibCustomInputs').hidden = true;
  document.getElementById('aibPerfSelect').value = '';
  document.querySelectorAll('.aib-dd-panel input[type="checkbox"]').forEach(cb => { cb.checked = false; });
  document.getElementById('aibUseDdBtn').textContent = 'Select intended use(s)...';
  document.getElementById('aibReqDdBtn').textContent = 'Select requirement(s)...';
  document.getElementById('aibBrand').value = '';
  document.getElementById('aibMessage').value = '';
  aibMinInput.value = ''; aibMaxInput.value = '';
  aibUpdateGenerateButton();
  aibShowStep(1);
}

// --- Saved builds panel ---
const aibSavedPanel = document.getElementById('aibSavedPanel');
document.getElementById('aibSavedToggle').addEventListener('click', () => {
  aibSavedPanel.hidden = !aibSavedPanel.hidden;
  if (!aibSavedPanel.hidden) { aibLoadSavedBuilds(); }
});
document.getElementById('aibSavedClose').addEventListener('click', () => { aibSavedPanel.hidden = true; });

function aibLoadSavedBuilds() {
  const listEl = document.getElementById('aibSavedList');
  listEl.innerHTML = '<p class="aib-muted">Loading...</p>';
  fetch(AIB_API + '?action=list_saved_builds')
    .then(r => r.json())
    .then(res => {
      aibSetSavedCount(res.saved_count);
      if (!res.success || !res.builds || res.builds.length === 0) {
        listEl.innerHTML = '<p class="aib-muted">No saved builds yet. Generate a build and click "Save This Build" to keep it here.</p>';
        return;
      }
      listEl.innerHTML = res.builds.map(b => `
        <div class="aib-saved-item">
          <div class="aib-saved-item-info">
            <b>${aibEsc(b.title)}</b>
            <span>${aibMoney(b.total_price)} · ${new Date(b.created_at).toLocaleDateString()}</span>
          </div>
          <div class="aib-saved-item-actions">
            <button type="button" class="aib-add-btn" data-add-build="${b.id}"
                    title="Add every still-available product in this build to your cart">Add All</button>
            <button type="button" class="aib-btn-secondary" data-view-build="${b.id}">View</button>
            <button type="button" class="aib-btn-secondary" data-delete-build="${b.id}">Delete</button>
          </div>
        </div>`).join('');
      listEl.querySelectorAll('[data-add-build]').forEach(btn => {
        btn.addEventListener('click', () => aibAddSavedBuildToCart(btn.dataset.addBuild, btn));
      });
      listEl.querySelectorAll('[data-view-build]').forEach(btn => {
        btn.addEventListener('click', () => aibViewSavedBuild(btn.dataset.viewBuild));
      });
      listEl.querySelectorAll('[data-delete-build]').forEach(btn => {
        btn.addEventListener('click', () => aibDeleteSavedBuild(btn.dataset.deleteBuild));
      });
    })
    .catch(() => { listEl.innerHTML = '<p class="aib-muted">Could not load saved builds.</p>'; });
}

function aibDeleteSavedBuild(id) {
  fetch(AIB_API + '?action=delete_saved_build', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: id }),
  })
    .then(r => r.json())
    .then(res => {
      showNotification(res.message, res.success ? 'success' : 'error');
      aibSetSavedCount(res.saved_count);
      aibLoadSavedBuilds();
    })
    .catch(() => showNotification('Could not delete this build.', 'error'));
}

/* Every product in a saved build that can still be bought.
 *
 * Shared by the detail view's "Add Available Items to Cart" and the shortcut
 * on each row of the Saved Builds panel, so the two can never disagree about
 * what "all the products in this build" means. Components whose status is not
 * 'ok' are skipped - a saved build can contain items that have since gone out
 * of stock or inactive, and those must not be silently added. */
function aibSavedBuildIds(build) {
  const ids = [];
  if (build.base_product) { ids.push(build.base_product.id); }
  (build.components || []).forEach(entry => {
    if (entry.status === 'ok' && entry.product) { ids.push(entry.product.id); }
  });
  return ids;
}

function aibAddIdsToCart(ids, bundleName) {
  if (!ids.length) {
    showNotification('Nothing in this build is available to add right now.', 'error');
    return Promise.resolve();
  }
  return fetch(AIB_API + '?action=add_build_to_cart', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ product_ids: ids, bundle_name: bundleName }),
  })
    .then(r => r.json())
    .then(res => {
      if (res.cart_count !== null && res.cart_count !== undefined) { updateCartCount(res.cart_count); }
      showNotification(res.message, res.success ? 'success' : 'error');
    })
    .catch(() => showNotification('Could not add the build to your cart.', 'error'));
}

/* The shortcut: add a saved build straight from its row, without opening it.
 * The build still has to be fetched - the list response carries only the
 * summary columns, not components_json - so the button shows its own progress
 * rather than sitting inert during the round trip. */
function aibAddSavedBuildToCart(id, btn) {
  const label = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Adding...'; }
  fetch(AIB_API + '?action=get_saved_build&id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showNotification(res.message || 'Build not found.', 'error');
        return;
      }
      return aibAddIdsToCart(aibSavedBuildIds(res.build), res.build.build_name || res.build.title);
    })
    .catch(() => showNotification('Could not load this build.', 'error'))
    .finally(() => { if (btn) { btn.disabled = false; btn.textContent = label; } });
}

function aibViewSavedBuild(id) {
  fetch(AIB_API + '?action=get_saved_build&id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(res => {
      if (!res.success) { showNotification(res.message || 'Build not found.', 'error'); return; }
      aibSavedPanel.hidden = true;
      aibRenderSavedBuild(res.build);
      aibShowStep(3);
    })
    .catch(() => showNotification('Could not load this build.', 'error'));
}

function aibRenderSavedBuild(build) {
  const el = document.getElementById('aibResult');
  let html = `<div class="aib-result-banner ok"><i class="fi fi-rr-bookmark"></i><div><b>Saved build:</b> ${aibEsc(build.build_name || build.title)}</div></div>`;
  html += `<div class="aib-budget-summary">
    <div class="aib-budget-box"><span>Saved Total</span><b>${aibMoney(build.total_price)}</b></div>
    <div class="aib-budget-box"><span>Budget</span><b>${aibMoney(build.budget_max)}</b></div>
    <div class="aib-budget-box"><span>Saved On</span><b>${new Date(build.created_at).toLocaleDateString()}</b></div>
  </div>`;

  const ids = [];
  if (build.base_product) {
    ids.push(build.base_product.id);
    html += `<div class="aib-component">
      <img src="${build.base_product.image_url ? (window.WEB_ROOT + build.base_product.image_url) : AIB_PLACEHOLDER_IMG}" alt="" onerror="this.src='${AIB_PLACEHOLDER_IMG}'">
      <div class="aib-component-body">
        <div class="aib-component-role">Base PC</div>
        <div class="aib-component-name">${aibEsc(build.base_product.name)}</div>
        <div class="aib-component-foot"><span class="aib-component-price">${aibMoney(build.base_product.price)}</span></div>
      </div>
    </div>`;
  }

  (build.components || []).forEach(entry => {
    const label = entry.product ? ((AIB_CATEGORY_NAMES[entry.product.category_id]) || 'Component') : 'Component';
    if (entry.status === 'ok' && entry.product) {
      ids.push(entry.product.id);
      const p = entry.product;
      const img = p.image_url ? (window.WEB_ROOT + p.image_url) : AIB_PLACEHOLDER_IMG;
      html += `<div class="aib-component">
        <img src="${img}" alt="" onerror="this.src='${AIB_PLACEHOLDER_IMG}'">
        <div class="aib-component-body">
          <div class="aib-component-role">${aibEsc(label)}</div>
          <div class="aib-component-name">${aibEsc(p.name)}</div>
          <div class="aib-component-foot">
            <span class="aib-component-price">${aibMoney(p.price)}</span>
            <button type="button" class="aib-add-btn" onclick="addToCart(${p.id})">Add to Cart</button>
          </div>
        </div>
      </div>`;
    } else {
      html += `<div class="aib-component unavailable">
        <div class="aib-component-body">
          <div class="aib-component-role">${aibEsc(label)}</div>
          <div class="aib-component-name">No Longer Available</div>
          <div class="aib-component-explain">${aibEsc(entry.reason || 'This product is no longer active or in stock.')}</div>
        </div>
      </div>`;
    }
  });

  html += `<div class="aib-result-actions">
    <button type="button" class="aib-btn-primary" id="aibAddSavedBtn">Add Available Items to Cart</button>
    <button type="button" class="aib-btn-secondary" id="aibStartOverBtn2">Start New Build</button>
  </div>`;

  el.innerHTML = html;
  document.getElementById('aibAddSavedBtn').addEventListener('click', () => {
    aibAddIdsToCart(ids, build.build_name || build.title);
  });
  document.getElementById('aibStartOverBtn2').addEventListener('click', aibResetWizard);
}
</script>

<!-- The redesign's skin controller. Loaded AFTER the script above so every
     listener in it is already bound: the skin only ever sets .value/.checked
     on those same controls and dispatches a real 'change', which is exactly
     what a click on the native control does. It reads nothing and sends
     nothing - aibState and the fetch() above are untouched. -->
<script src="<?= asset_url('public/shared/assets/js/ai-builder-skin.js') ?>"></script>


<style>
/* Wizard chrome. Everything else on this page is untouched - these rules only
   cover the card wrapper the four existing sections now sit inside, and the
   Back/Next row under each. Colours, radii and shadows all come from the same
   tokens the rest of the builder uses. */
body.aibx .aibx-wiz-card { margin-bottom: 18px; }
/* The section inside already carries the page's own spacing; the card adds the
   frame, so drop the section's outer margin to avoid doubling it. */
body.aibx .aibx-wiz-card > .aibx-sec { margin: 0; }

body.aibx .aibx-wiz-nav {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; flex-wrap: wrap;
  margin-top: 18px; padding-top: 16px;
  border-top: 1px solid var(--aibx-line, rgba(255,255,255,.10));
}
body.aibx .aibx-wiz-count {
  font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
  color: var(--aibx-dim, #93a4b8);
}
body.aibx .aibx-wiz-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; }
body.aibx .aibx-wiz-nav .aib-btn-primary { margin: 0; width: auto; min-width: 140px; }

body.aibx .aibx-wiz-back {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 16px; min-height: 44px;
  background: transparent; border: 1px solid var(--aibx-line, rgba(255,255,255,.14));
  border-radius: 10px; cursor: pointer;
  color: var(--aibx-dim, #93a4b8); font: inherit; font-size: 14px; font-weight: 600;
}
body.aibx .aibx-wiz-back:hover { color: var(--aibx-ink, #e8eef6); }

/* ---------------------------------------------------------------------------
 * Step 03 is the tall one: four performance tiles, each with a line of
 * description, over nine intended-use tiles - two stacked grids in a column
 * only ~500px wide. At the shared tile size that put Back/Next below the fold,
 * so the step had to be scrolled just to be left.
 *
 * Scoped to step 3's card, and compaction only: same tiles, same labels, same
 * hints, same handlers - tighter padding and type, and the use grid pinned to
 * three columns so titles like "Office / Productivity" still fit on one line
 * (four columns forces every row to two lines, which is taller, not shorter).
 * ------------------------------------------------------------------------- */
body.aibx .aibx-wiz-card[data-wiz-step="3"] > .aibx-sec { padding: 17px 24px; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-sec-head { margin-bottom: 11px; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-sublabel { margin: 0 0 7px; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aib-field + .aib-field { margin-top: 14px; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aib-hint { margin-top: 7px; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-wiz-nav { margin-top: 14px; padding-top: 13px; }

body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tiles-perf { gap: 8px; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tiles-use {
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 7px;
}
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tile {
  padding: 10px 12px;
  border-radius: 11px;
}
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tile-key { font-size: 10px; margin-bottom: 3px; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tile-desc { font-size: 11px; line-height: 1.35; }
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tile-title {
  font-size: 12.5px;
  line-height: 1.2;
  margin-bottom: 3px;
}
/* The hint is a one-line gloss ("1080p - 4K", "Browsing, media"). Keeping it
   to one line is most of the saving: a second line of it costs a row of
   height three times over. */
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tile-hint {
  font-size: 8.5px;
  line-height: 1.3;
  letter-spacing: .08em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
/* The locked reason is the exception - it must stay readable, so it wraps. */
body.aibx .aibx-wiz-card[data-wiz-step="3"] .aibx-tile-why { margin-top: 5px; white-space: normal; }
/* The shared narrow-screen section padding is less specific than the rules
   above, so it has to be restated here or step 3 keeps desktop side padding. */
@media (max-width: 560px) {
  body.aibx .aibx-wiz-card[data-wiz-step="3"] > .aibx-sec { padding: 16px; }
}

/* The step rail becomes navigation: completed steps can be jumped back to. */
body.aibx .aibx-steprail li[data-wiz-clickable] { cursor: pointer; }
body.aibx .aibx-steprail li[data-wiz-clickable]:hover .aibx-steprail-label { text-decoration: underline; }

@media (max-width: 640px) {
  body.aibx .aibx-wiz-nav { flex-direction: column-reverse; align-items: stretch; }
  body.aibx .aibx-wiz-actions { width: 100%; }
  body.aibx .aibx-wiz-actions > * { flex: 1; justify-content: center; }
  body.aibx .aibx-wiz-count,
  body.aibx .aibx-wiz-why { text-align: center; }
}
</style>

<script>
/* ---------------------------------------------------------------------------
 * Step-by-step flow.
 *
 * Purely a presentation layer over the existing form: the four .aibx-sec
 * blocks, their fields, ids and classes are exactly as they were, and every
 * hidden step stays in the DOM. Nothing here reads or writes aibState, and the
 * build itself still runs through the rail's #aibGenerate handler - so the
 * validation, the live brief, ai-builder-skin.js and the API call all behave
 * as before.
 * ------------------------------------------------------------------------- */
(function () {
  var cards = Array.prototype.slice.call(document.querySelectorAll('.aibx-wiz-card'));
  if (!cards.length) { return; }

  var railItems = Array.prototype.slice.call(document.querySelectorAll('.aibx-steprail li'));
  var generate = document.getElementById('aibGenerate');
  var wizBuild = document.getElementById('aibWizBuild');
  var panel = document.querySelector('.aib-panel');
  var current = 1;

  function show(step, focus) {
    step = Math.min(cards.length, Math.max(1, step));
    current = step;
    cards.forEach(function (card) {
      card.hidden = (parseInt(card.dataset.wizStep, 10) !== step);
    });
    paintRail();
    if (focus !== false) {
      /* Bring the top of the step into view ONLY when it is not already
       * there. Scrolling unconditionally to the panel meant clicking Next
       * from the top of the page dragged the reader DOWN - past the heading
       * and the step rail - because .aib-panel starts below the hero. So:
       * scroll up if the step's top has gone above the viewport, scroll down
       * only if it is off the bottom, and otherwise leave the page alone. */
      var target = cards[step - 1] || panel;
      var rect = target.getBoundingClientRect();
      var margin = 20;
      if (rect.top < margin) {
        window.scrollTo({ top: rect.top + window.pageYOffset - margin, behavior: 'smooth' });
      } else if (rect.top > window.innerHeight - 80) {
        window.scrollTo({ top: rect.top + window.pageYOffset - margin, behavior: 'smooth' });
      }
      var first = cards[step - 1].querySelector('input, select, textarea, button');
      if (first) { try { first.focus({ preventScroll: true }); } catch (e) {} }
    }
  }

  /* The rail shows position in the wizard, and the wizard owns that outright.
   *
   * ai-builder-skin.js used to write data-state here too, from which fields
   * are filled, on every input event - so answering a question re-pointed the
   * rail at the next UNANSWERED step even though the customer had not moved.
   * The flag below tells the skin to leave position alone; it still publishes
   * completeness through data-aibx-step-done, which is what Next is gated on.
   * One writer for the attribute, instead of two racing on it. */
  var railEl = document.querySelector('.aibx-steprail');
  if (railEl) { railEl.dataset.aibxWiz = '1'; }

  function paintRail() {
    railItems.forEach(function (li, i) {
      var n = i + 1;
      li.setAttribute('data-state', n === current ? 'current' : (n < current ? 'done' : 'todo'));
      if (n < current) { li.setAttribute('data-wiz-clickable', '1'); }
      else { li.removeAttribute('data-wiz-clickable'); }
    });
  }

  /* Is the step the user is on actually answered?
   *
   * The answer comes from ai-builder-skin.js's stepDone array, published on
   * .aib-wrap as data-aibx-step-done ("1011" etc). Re-deriving it here would
   * mean two definitions of "complete" that drift the first time a field
   * moves between steps - the rail would say done and Next would disagree.
   * If the skin script has not run (or failed), stepDone is absent and this
   * returns true, so a broken skin can never lock someone out of the wizard. */
  function stepComplete(step) {
    var wrap = document.querySelector('.aib-wrap');
    var flags = wrap && wrap.dataset ? wrap.dataset.aibxStepDone : '';
    if (!flags) { return true; }
    return flags.charAt(step - 1) !== '0';
  }

  function missingFor(step) {
    var wrap = document.querySelector('.aib-wrap');
    try {
      var m = JSON.parse((wrap && wrap.dataset.aibxMissing) || '{}');
      return m[step] || [];
    } catch (e) { return []; }
  }

  /* Keeps Next in step with the answers, so the block is visible before it is
   * clicked rather than only being explained afterwards. */
  function refreshWizNav() {
    cards.forEach(function (card) {
      var n = parseInt(card.dataset.wizStep, 10);
      var next = card.querySelector('.aibx-wiz-next');
      if (!next) { return; }
      var ok = stepComplete(n);
      next.disabled = !ok;
      next.setAttribute('aria-disabled', ok ? 'false' : 'true');
      var why = card.querySelector('.aibx-wiz-why');
      if (!why) { return; }
      var names = missingFor(n);
      if (!ok && names.length) {
        why.textContent = 'Add ' + names.join(' and ') + ' to continue';
        why.hidden = false;
      } else {
        why.hidden = true;
      }
    });
  }
  /* updateRail() in the skin fires on input/change and rewrites the dataset.
   *
   * Deferred with setTimeout(0), NOT run inline: these listeners were first
   * registered in the CAPTURE phase, which runs BEFORE the skin's own handler,
   * so refreshWizNav read the previous dataset - filling the budget flipped
   * the flags to "1100" while Next stayed disabled. Queuing a task means the
   * skin has always finished writing by the time this reads it, whichever
   * phase or order the listeners end up in. */
  function scheduleWizRefresh() { setTimeout(refreshWizNav, 0); }
  setTimeout(refreshWizNav, 0);
  document.addEventListener('input', scheduleWizRefresh);
  document.addEventListener('change', scheduleWizRefresh);

  document.addEventListener('click', function (e) {
    var go = e.target.closest('[data-wiz-go]');
    if (!go) { return; }
    e.preventDefault();
    var target = parseInt(go.dataset.wizGo, 10);

    /* Back is always allowed - only going FORWARD is gated, and only on the
     * step being left. Someone stepping back to fix an answer must not be
     * blocked by the step they are standing on. */
    if (target > current && !stepComplete(current)) {
      var names = missingFor(current);
      if (typeof showNotification === 'function') {
        showNotification(names.length
          ? 'Add ' + names.join(' and ') + ' before continuing'
          : 'Finish this step before continuing', 'error');
      }
      var card = cards[current - 1];
      var firstEmpty = card && card.querySelector('select:not([disabled]), input:not([type=hidden]):not([disabled]), textarea');
      if (firstEmpty) { try { firstEmpty.focus(); } catch (err) {} }
      if (card) { card.classList.remove('aibx-shake'); void card.offsetWidth; card.classList.add('aibx-shake'); }
      refreshWizNav();
      return;
    }
    show(target, true);
  });

  /* Jump back to a completed step from the rail. Forward is deliberately not
   * allowed - Next is the way forward, so the steps are seen in order. */
  railItems.forEach(function (li, i) {
    li.addEventListener('click', function () {
      if (li.hasAttribute('data-wiz-clickable')) { show(i + 1, true); }
    });
  });

  /* Last step's button is a proxy for the rail CTA - same handler, same
   * enable/disable rule, so there is only ever one build path. */
  if (wizBuild && generate) {
    wizBuild.addEventListener('click', function () { generate.click(); });
    var syncBuild = function () {
      wizBuild.disabled = generate.disabled;
      wizBuild.textContent = generate.textContent.trim() || 'Build My PC';
    };
    syncBuild();
    new MutationObserver(syncBuild).observe(generate, {
      attributes: true, attributeFilter: ['disabled'], childList: true, characterData: true, subtree: true
    });
  }

  /* ai-builder-skin.js repaints the rail on input; put ours back after it. */
  document.addEventListener('input', function () { setTimeout(paintRail, 0); }, true);
  document.addEventListener('change', function () { setTimeout(paintRail, 0); }, true);

  /* If the page refuses a submit, the existing skin marks the short sections
   * with data-aibx-error. Jump to the first one so the reader can actually
   * see what it is complaining about instead of an error on a hidden step. */
  if (generate) {
    generate.addEventListener('click', function () {
      setTimeout(function () {
        var step1 = document.querySelector('.aib-step[data-step="1"]');
        if (!step1 || step1.hidden) { return; }
        var bad = document.querySelector('.aibx-wiz-card .aibx-sec[data-aibx-error]');
        if (!bad) { return; }
        var card = bad.closest('.aibx-wiz-card');
        if (card) { show(parseInt(card.dataset.wizStep, 10), true); }
      }, 10);
    });
  }

  show(1, false);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
