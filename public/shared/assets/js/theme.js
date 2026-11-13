/* ============================================================================
   theme.js - the Light / Dark switch.
   ----------------------------------------------------------------------------
   The anti-FOUC half of this does NOT live here. It is a blocking inline
   snippet pasted into each page ahead of any stylesheet, because this file is
   deferred and would run a frame too late. See theme-light.css for why the
   snippet had to be pasted into 28 pages rather than one header.

   This file only owns what happens AFTER first paint: the controls, their
   pressed state, and writing the choice down.

   ---------------------------------------------------------------------------
   TODO - cross-device persistence. The choice lives in localStorage only, so
   it does not follow a customer to another browser or device. Carrying it
   would need, and deliberately was NOT added in this pass:

       ALTER TABLE users ADD COLUMN theme ENUM('light','dark','system')
              NOT NULL DEFAULT 'system';

   plus a small authenticated endpoint (e.g. api/preferences.php?action=theme)
   that writes it, and one line in customer/includes/header.php to render the
   stored value into the anti-FOUC snippet as the fallback ahead of
   prefers-color-scheme. No migration and no new route were created here.
   ------------------------------------------------------------------------- */
(function () {
  'use strict';

  var KEY = 'tw-theme';                 // 'light' | 'dark' | 'system'
  var root = document.documentElement;
  var mql = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function save(v) {
    try { localStorage.setItem(KEY, v); } catch (e) { /* private mode - session only */ }
  }

  /** What 'system' currently resolves to. Dark is the fallback, as agreed. */
  function systemMode() {
    return (mql && mql.matches) ? 'light' : 'dark';
  }

  /** The mode actually being rendered. */
  function currentMode() {
    return root.getAttribute('data-mode') === 'light' ? 'light' : 'dark';
  }

  /* How long .tw-theming stays on. Must match the transition-duration in
     theme-light.css, or the class is pulled before the colours finish and the
     last frames snap. */
  var CROSSFADE_MS = 280;
  var fadeTimer = null;

  /**
   * Turns the crossfade on for the length of one switch, then off again.
   *
   * Deliberately NOT a permanent transition on every element: that would make
   * the whole page fade on first paint, and it would slow down every unrelated
   * hover and focus state that changes a colour. The class exists only while a
   * theme change is actually happening.
   */
  function crossfade() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    root.classList.add('tw-theming');
    clearTimeout(fadeTimer);
    // Re-armed rather than stacked, so rapid clicking cannot leave the class on.
    fadeTimer = setTimeout(function () { root.classList.remove('tw-theming'); }, CROSSFADE_MS + 40);
  }

  function apply(choice) {
    var mode = (choice === 'system') ? systemMode() : choice;
    // Armed BEFORE the attribute changes - a transition only animates a change
    // that happens while it is already in effect.
    crossfade();
    root.setAttribute('data-mode', mode);
    save(choice);
    sync();
  }

  /** Reflects the saved choice onto every control on the page. */
  function sync() {
    var choice = stored() || 'system';
    var mode = currentMode();

    // Sidebar segmented pill - two buttons, so it reflects the rendered mode
    // rather than the three-way choice.
    var segs = document.querySelectorAll('.tw-appearance-btn[data-mode-set]');
    for (var i = 0; i < segs.length; i++) {
      segs[i].setAttribute('aria-pressed', segs[i].getAttribute('data-mode-set') === mode ? 'true' : 'false');
    }

    // One button showing the INCOMING theme's glyph - the sidebar's brand row,
    // and the collapsed rail.
    var next = mode === 'dark' ? 'light' : 'dark';
    var icon = document.querySelector('.tw-appearance-icon');
    if (icon) {
      icon.textContent = next === 'light' ? '☀' : '☾';
      icon.setAttribute('data-mode-set', next);
      icon.setAttribute('title', 'Switch to ' + next + ' mode');
      icon.setAttribute('aria-label', 'Switch to ' + next + ' mode');
    }

    // Optional text label beside that button. It names the SAME incoming theme
    // the glyph does - naming the current one instead would leave the word and
    // the icon next to it disagreeing about which way the switch goes.
    //
    // A separate element rather than markup inside the button, because the line
    // above replaces the button's whole textContent - a label nested in there
    // would be wiped on the first change. It is aria-hidden in the markup, so it
    // does not double up on the button's own aria-label.
    var label = document.querySelector('.tw-appearance-label');
    if (label) {
      label.textContent = next === 'light' ? 'Light' : 'Dark';
    }

    // Settings three-way - reflects the CHOICE, so "Match system" can be seen
    // to be selected even though it renders as light or dark.
    var threes = document.querySelectorAll('.tw-theme-choice button[data-theme-choice]');
    for (var j = 0; j < threes.length; j++) {
      threes[j].setAttribute('aria-pressed', threes[j].getAttribute('data-theme-choice') === choice ? 'true' : 'false');
    }
  }

  document.addEventListener('click', function (e) {
    var seg = e.target.closest('.tw-appearance-btn[data-mode-set], .tw-appearance-icon[data-mode-set]');
    if (seg) { apply(seg.getAttribute('data-mode-set')); return; }

    var three = e.target.closest('.tw-theme-choice button[data-theme-choice]');
    if (three) { apply(three.getAttribute('data-theme-choice')); }
  });

  // Only follow the OS while the customer has actually chosen "Match system".
  if (mql) {
    var onChange = function () { if ((stored() || 'system') === 'system') { apply('system'); } };
    if (mql.addEventListener) { mql.addEventListener('change', onChange); }
    else if (mql.addListener) { mql.addListener(onChange); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sync);
  } else { sync(); }
})();
