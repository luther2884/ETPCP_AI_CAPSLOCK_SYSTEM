/* techworld.js — the only JS in the package.
   Two jobs: drive the coverflow index, and provide a scroll-reveal
   fallback for browsers without animation-timeline: view().
   Load with:  <script src="assets/js/techworld.js" defer></script>   */

(function () {
  "use strict";

  /* ---------- 1. Category coverflow ------------------------------------ */
  function initCarousel(root) {
    var stage = root.querySelector(".tw-stage");
    if (!stage) return;

    var tiles = Array.prototype.slice.call(stage.querySelectorAll(".tw-tile"));
    var dots = Array.prototype.slice.call(root.querySelectorAll(".tw-progress span"));
    var label = root.querySelector(".tw-active-label b");
    var counter = root.querySelector(".tw-counter");
    var prev = root.querySelector(".tw-nav-btn--prev");
    var next = root.querySelector(".tw-nav-btn--next");
    var n = tiles.length;
    if (!n) return;

    var i = 0;
    var paused = false;
    var speed = parseInt(root.getAttribute("data-speed"), 10) || 3400;

    function pad(v) { return String(v).padStart(2, "0"); }

    function render() {
      for (var k = 0; k < n; k++) {
        var d = k - i;
        if (d > n / 2) d -= n;
        if (d < -n / 2) d += n;
        var ad = Math.abs(d);
        var t = tiles[k];
        t.style.setProperty("--d", d);
        t.style.setProperty("--ad", ad);
        t.setAttribute("data-far", ad > 3 ? 3 : ad);
        t.classList.toggle("is-active", ad === 0);
        t.setAttribute("aria-hidden", ad === 0 ? "false" : "true");
      }
      for (var j = 0; j < dots.length; j++) dots[j].classList.toggle("is-active", j === i);
      if (label) label.textContent = tiles[i].getAttribute("data-label") || "";
      if (counter) counter.textContent = pad(i + 1) + " / " + pad(n);
    }

    function go(k) { i = ((k % n) + n) % n; render(); }

    if (prev) prev.addEventListener("click", function () { go(i - 1); });
    if (next) next.addEventListener("click", function () { go(i + 1); });

    tiles.forEach(function (t, k) { t.addEventListener("click", function () { go(k); }); });
    dots.forEach(function (d, k) { d.addEventListener("click", function () { go(k); }); });

    root.addEventListener("mouseenter", function () { paused = true; });
    root.addEventListener("mouseleave", function () { paused = false; });
    root.addEventListener("focusin", function () { paused = true; });

    root.addEventListener("keydown", function (e) {
      if (e.key === "ArrowLeft") go(i - 1);
      if (e.key === "ArrowRight") go(i + 1);
    });

    var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (!reduce) setInterval(function () { if (!paused) go(i + 1); }, speed);

    render();
  }

  /* ---------- 2. Scroll-reveal fallback -------------------------------- */
  function initReveal() {
    var supported = CSS && CSS.supports && CSS.supports("animation-timeline", "view()");
    if (supported || !("IntersectionObserver" in window)) return;

    var targets = document.querySelectorAll(".tw-card, .tw-meter i, .tw-engine__track i, .tw-bars span");
    if (!targets.length) return;

    // neutralise the CSS start state, then animate on entry
    Array.prototype.forEach.call(targets, function (el) {
      el.style.animation = "none";
      el.style.opacity = el.classList.contains("tw-card") ? "0" : "1";
      if (!el.classList.contains("tw-card")) el.style.transform = "scale(0)";
    });

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        var el = e.target;
        el.style.transition = "opacity .7s cubic-bezier(.2,.8,.2,1), transform .9s cubic-bezier(.2,.8,.2,1)";
        el.style.opacity = "1";
        el.style.transform = "none";
        io.unobserve(el);
      });
    }, { threshold: 0.18 });

    Array.prototype.forEach.call(targets, function (el) { io.observe(el); });
  }

  document.addEventListener("DOMContentLoaded", function () {
    Array.prototype.forEach.call(document.querySelectorAll("[data-tw-carousel]"), initCarousel);
    initReveal();
  });
})();
