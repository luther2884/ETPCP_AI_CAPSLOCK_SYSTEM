/* techworld.js — landing-page interaction layer for the neon "gaming rig"
   redesign (public/guest/index.php + public/authenticated/customer/index.php).

   Deliberately tiny. Pure CSS owns every looping/ambient animation; JS here
   only does the two things CSS can't:
     1. the category coverflow's active index (arrows, dots, drag, auto-advance)
     2. a scroll-reveal fallback (adds .is-in) for browsers without
        CSS `animation-timeline: view()`

   No dependencies, no build step. Loaded with `defer` before </body>.
   Everything is guarded so a page missing one of these widgets is fine. */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------------ *
   * 1. Category coverflow
   * ------------------------------------------------------------------ *
   * Each card's transform is a pure function of its signed distance `d`
   * from the active index (see .tw-cf-card in techworld-anim.css, which
   * reads the --d custom property). We only ever set --d / a11y state
   * here; the browser tweens the rest.                                  */
  function initCoverflow() {
    var root = document.getElementById('twCoverflow');
    if (!root) return;

    var stage = root.querySelector('[data-cf-stage]');
    var cards = stage ? Array.prototype.slice.call(stage.querySelectorAll('.tw-cf-card')) : [];
    var dots = Array.prototype.slice.call(root.querySelectorAll('.tw-cf-dot'));
    var prevBtn = root.querySelector('[data-cf-prev]');
    var nextBtn = root.querySelector('[data-cf-next]');
    var nameEl = root.querySelector('[data-cf-name]');
    var n = cards.length;
    if (!n) return;

    var active = 0;
    var timer = null;
    var DELAY = 3400;

    function offset(i) {
      var d = ((i - active) % n + n) % n;
      if (d > n / 2) d -= n;
      return d;
    }

    function render() {
      cards.forEach(function (card, i) {
        var d = offset(i);
        var far = Math.abs(d) > 3;
        card.style.setProperty('--d', d);
        card.classList.toggle('is-active', d === 0);
        card.hidden = false;                 // keep in flow; CSS fades far ones
        card.setAttribute('aria-hidden', d === 0 ? 'false' : 'true');
        card.tabIndex = d === 0 ? 0 : -1;
        card.style.pointerEvents = far ? 'none' : '';
        card.style.visibility = far ? 'hidden' : '';
      });
      dots.forEach(function (dot, i) {
        var on = i === active;
        dot.classList.toggle('is-active', on);
        dot.setAttribute('aria-current', on ? 'true' : 'false');
      });
      if (nameEl) nameEl.textContent = cards[active].getAttribute('data-name') || '';
    }

    function goTo(i) { active = ((i % n) + n) % n; render(); }
    function next() { goTo(active + 1); }
    function prev() { goTo(active - 1); }

    function start() {
      if (reduceMotion || timer) return;
      timer = window.setInterval(next, DELAY);
    }
    function stop() { if (timer) { window.clearInterval(timer); timer = null; } }

    cards.forEach(function (card, i) {
      card.addEventListener('click', function () {
        if (card.__dragged) { card.__dragged = false; return; }
        if (i === active && card.dataset.url) { window.location.href = card.dataset.url; }
        else { goTo(i); }
      });
    });
    dots.forEach(function (dot, i) {
      dot.addEventListener('click', function () { goTo(i); });
    });
    if (prevBtn) prevBtn.addEventListener('click', function () { prev(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { next(); });

    root.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
    });

    // pause on hover / focus / when tab hidden
    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', start);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop(); else start();
    });

    // drag / swipe
    var dragging = false, startX = 0, dragCard = null;
    stage.addEventListener('pointerdown', function (e) {
      dragging = true; startX = e.clientX;
      dragCard = e.target.closest('.tw-cf-card');
      stop();
    });
    stage.addEventListener('pointermove', function (e) {
      if (dragging && dragCard && Math.abs(e.clientX - startX) > 12) dragCard.__dragged = true;
    });
    window.addEventListener('pointerup', function (e) {
      if (!dragging) return;
      dragging = false;
      var dx = e.clientX - startX;
      if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); }
      dragCard = null;
      start();
    });

    render();
    start();
  }

  /* ------------------------------------------------------------------ *
   * 2. Scroll-reveal fallback
   * ------------------------------------------------------------------ *
   * Elements marked [data-tw-reveal] get `.is-in` when they scroll into
   * view. Browsers that support CSS scroll-driven animations handle the
   * reveal themselves (see @supports block in the stylesheet); this is
   * only the fallback for the rest. IntersectionObserver is ~universal;
   * if even that is missing we just show everything.                    */
  function initReveal() {
    var els = Array.prototype.slice.call(document.querySelectorAll('[data-tw-reveal]'));
    if (!els.length) return;

    var cssHandlesIt = window.CSS && CSS.supports &&
      (CSS.supports('animation-timeline: view()') || CSS.supports('(animation-timeline: view())'));
    if (cssHandlesIt && !reduceMotion) return;

    if (reduceMotion || !('IntersectionObserver' in window)) {
      els.forEach(function (el) { el.classList.add('is-in'); });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

    els.forEach(function (el) { io.observe(el); });
  }

  function boot() { initCoverflow(); initReveal(); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
