<?php
/**
 * admin/includes/product_confirm.php - the "read it back before you save it"
 * step for product data.
 *
 * WHY
 *
 * config/product_validation.php already stops values that are impossible
 * (negative, non-numeric, too big for the column) and questions values that
 * are merely suspicious (a price 8x the dearest product). What neither can
 * catch is the ordinary typo: 1250 typed as 1520, a price put in the stock
 * box, the right number saved against the wrong product. Those are all
 * perfectly plausible values. The only thing that catches them is a person
 * reading the figures back before they are written.
 *
 * So this is not a second validation layer and not an "are you sure?" - it is
 * a receipt. It prints what is about to be saved, with the previous value
 * beside anything that is changing, and waits for a yes.
 *
 * It renders nothing on its own; it adds helpers on top of the uiReview()
 * dialog in shared/includes/ui-dialog.php (already included by the admin
 * header, so every admin page has it).
 *
 * The confirmation is a usability guard, not a security one. Everything it
 * covers is still validated and authorised server-side - a POST that skips
 * the dialog entirely is rejected on exactly the same rules as one that
 * does not.
 */
?>
<script>
(function () {
  if (window.pcReview) { return; }   // include-once guard

  /** Peso, formatted the same way pv_peso() formats it in PHP. */
  window.pcPeso = function (n) {
    var v = parseFloat(n);
    if (!isFinite(v)) { return String(n == null ? '' : n); }
    return '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  /** Whole units, with thousands separators. */
  window.pcUnits = function (n) {
    var v = parseInt(n, 10);
    if (!isFinite(v)) { return String(n == null ? '' : n); }
    return v.toLocaleString('en-PH') + (Math.abs(v) === 1 ? ' unit' : ' units');
  };

  /** Trim and collapse whitespace, so the dialog shows the string that will
   *  actually be stored rather than the one with a stray trailing space. */
  window.pcText = function (s) {
    return String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
  };

  /**
   * Show the receipt. Thin wrapper over uiReview() that fails OPEN: if the
   * dialog is somehow unavailable (the include missing on a page, a JS error
   * earlier in the document), the save proceeds rather than becoming
   * impossible. A confirmation step that can silently brick saving is worse
   * than no confirmation step.
   */
  window.pcReview = function (opts) {
    if (typeof window.uiReview !== 'function') { return Promise.resolve(true); }
    return window.uiReview(opts);
  };

  /**
   * Put the receipt in front of a normal form submit.
   *
   * @param form      the <form> element
   * @param buildRows () => ({title, intro, rows, okText}) read at submit time,
   *                  so it sees the final values, not the ones at page load.
   *                  Return null to let the submit through unquestioned.
   *
   * Capture phase, so it runs before any other submit handler on the page.
   * The replay sets a flag and uses requestSubmit(), which keeps native
   * validation and the submitter button - plain submit() would skip both,
   * and skipping validation here would mean the dialog shows values the
   * browser was about to reject anyway.
   */
  window.pcGuardForm = function (form, buildRows) {
    if (!form || form.__pcGuarded) { return; }
    form.__pcGuarded = true;

    form.addEventListener('submit', function (e) {
      if (form.__pcConfirmed) { form.__pcConfirmed = false; return; }
      // Let the browser's own required/min/step messages happen first; there
      // is no point reviewing a form that cannot be submitted.
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) { return; }

      var spec;
      try { spec = buildRows(); } catch (err) { spec = null; }
      if (!spec) { return; }

      e.preventDefault();
      e.stopPropagation();

      window.pcReview(spec).then(function (ok) {
        if (!ok) { return; }
        form.__pcConfirmed = true;
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
        else { form.submit(); }
      });
    }, true);
  };
})();
</script>
