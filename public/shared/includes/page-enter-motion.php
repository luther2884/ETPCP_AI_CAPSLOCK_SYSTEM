<?php /* ---------------------------------------------------------------------
   Page-entry motion, shared by the admin and super-admin panels.

   Every sidebar destination in both panels is a full page load, so
   "navigating to a tab" is simply a fresh document - which means the whole
   effect can be a CSS entrance animation that plays on load, staggered down
   the page so the cards arrive in sequence rather than all at once.

   This file exists rather than the block living in one panel's header,
   because it was in admin/includes/header.php first and super-admin needed
   the same thing. Two copies would have drifted the moment either panel
   gained a new card class.

   Include it once, just inside <body>, from each panel's header.

   SAFETY: the hidden state (opacity:0) lives ONLY inside the keyframe, never
   in a base rule. If animations don't run - unsupported, or the element never
   gets tagged - the content simply renders normally instead of staying
   invisible. That is the difference between a decoration and a way to lose
   the page.
   --------------------------------------------------------------------- */ ?>
<style>
  @keyframes adminEnterUp{
    from{opacity:0;transform:translate3d(0,14px,0);}
    to{opacity:1;transform:none;}
  }
  /* --enter-i is the stagger index, set per element by the script below.
     will-change is dropped when the animation ends so long pages don't hold
     a compositor layer per card for the life of the document. */
  .admin-enter{
    animation:adminEnterUp .44s cubic-bezier(.22,.61,.36,1) both;
    animation-delay:calc(var(--enter-i, 0) * 45ms);
    will-change:opacity, transform;
  }
  .admin-enter-done{will-change:auto;}
  /* Motion here is pure decoration - with reduced motion the page just appears. */
  @media (prefers-reduced-motion: reduce){
    .admin-enter{animation:none;}
  }
</style>
<script>
(function () {
  // Bail before touching the DOM if the viewer asked for less motion.
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // The card-ish things across both panels. Order matters only in that the
  // list is deduped by DOM order below, so a page using several of these still
  // animates top-to-bottom rather than group-by-group.
  var SELECTOR = [
    // shared / admin
    '.dashboard-welcome',
    '.fin-kpi', '.fin-panel',
    '.quick-stats > *', '.stats-grid > *', '.summary-cards > *',
    '.dashboard-stats > *', '.feedback-grid > *', '.pickup-grid > *', '.grid-2 > *',
    '.admin-grid > *',
    '.stat-card', '.summary-card', '.admin-card',
    // .admin-dashboard is excluded deliberately: promotional_carousel.php and
    // add_product.php spell their page wrapper `admin-dashboard card`, so a bare
    // .card would match the WHOLE page and the dedupe below would then discard
    // every real card inside it - one slab fading in instead of a sequence.
    '.card:not(.admin-dashboard)',
    '.sc-card', '.reviews-wrap', '.customers-section', '.section-title',
    '.chart-container', '.report-tile',
    '.admin-table-wrap', '.table-responsive', '.report-table-container',
    '.date-filter',
    // super-admin
    '.security-panel', '.maintenance-section', '.stats-overview > *', '.stat-item',
    '.aiu-card', '.aiu-grid > *', '.aiu-table-wrap'
  ].join(',');

  function run() {
    var main = document.querySelector('main') || document.body;

    var nodes = Array.prototype.slice.call(main.querySelectorAll(SELECTOR));

    // Skip anything parked out of the page flow: the report cards sit in a
    // hidden stash until the modal borrows them, and an entrance animation
    // queued there would fire later, when the card is opened, not now.
    nodes = nodes.filter(function (el) { return !el.closest('.report-stash, .report-modal'); });
    if (!nodes.length) return;

    // Drop any element that already has an animating ancestor in the list -
    // a card inside an animating grid would otherwise fade twice, and the
    // nested transform makes the parent's motion look like it stutters.
    nodes = nodes.filter(function (el) {
      return !nodes.some(function (other) { return other !== el && other.contains(el); });
    });

    // Sort into document order, then cap the stagger: past ~12 cards the tail
    // of a long page would sit blank for most of a second waiting its turn.
    nodes.sort(function (a, b) {
      return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
    });

    nodes.forEach(function (el, i) {
      el.style.setProperty('--enter-i', Math.min(i, 12));
      el.classList.add('admin-enter');
      el.addEventListener('animationend', function once() {
        el.classList.add('admin-enter-done');
        el.removeEventListener('animationend', once);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
</script>
