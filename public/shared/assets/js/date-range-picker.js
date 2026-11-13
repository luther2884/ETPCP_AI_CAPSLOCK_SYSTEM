/**
 * date-range-picker.js — reusable two-pane date range picker.
 *
 * Drop-in markup contract (see sales_report.php for a live example):
 *   <div class="drp" data-drp
 *        data-drp-start="2025-01-10" data-drp-end="2025-01-16"
 *        data-drp-start-name="start_date" data-drp-end-name="end_date"
 *        data-drp-activity="[&quot;2025-01-10&quot;,...]">
 *     <button type="button" class="drp-trigger" data-drp-trigger>
 *       <i class="fi fi-rr-calendar"></i>
 *       <span class="drp-trigger-label" data-drp-label></span>
 *     </button>
 *     <input type="hidden" name="start_date" data-drp-hidden-start>
 *     <input type="hidden" name="end_date" data-drp-hidden-end>
 *   </div>
 *
 * Everything else (the popover: presets, dual calendar, manual inputs,
 * footer) is built by this script. Hidden input names are read from the
 * markup so the widget can be dropped into any existing GET/POST form
 * without the backend needing to know a picker is involved - it just sees
 * the same start_date/end_date fields it always did.
 */
(function () {
  'use strict';

  var WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
  var MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function toISO(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
  function parseISO(s) {
    if (!s) return null;
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) return null;
    var d = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
    return isNaN(d.getTime()) ? null : d;
  }
  function sameDay(a, b) { return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addDays(d, n) { var r = new Date(d); r.setDate(r.getDate() + n); return r; }
  function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
  function formatLabel(d) { return MONTH_NAMES[d.getMonth()].slice(0, 3) + ' ' + d.getDate() + ', ' + d.getFullYear(); }

  function DateRangePicker(root) {
    this.root = root;
    this.trigger = root.querySelector('[data-drp-trigger]');
    this.labelEl = root.querySelector('[data-drp-label]');
    this.hiddenStart = root.querySelector('[data-drp-hidden-start]');
    this.hiddenEnd = root.querySelector('[data-drp-hidden-end]');

    // A missing/empty start+end means "no filter applied yet" (e.g. audit_logs.php,
    // where date_from/date_to are independent optional filters, not a required
    // range) - kept as null rather than silently defaulting to "today", so the
    // trigger honestly shows a placeholder instead of implying a filter is active.
    var today = startOfDay(new Date());
    this.appliedStart = parseISO(root.getAttribute('data-drp-start'));
    this.appliedEnd = parseISO(root.getAttribute('data-drp-end'));
    this.pendingStart = this.appliedStart;
    this.pendingEnd = this.appliedEnd;
    this.hoverDate = null;

    try { this.activity = JSON.parse(root.getAttribute('data-drp-activity') || '[]'); } catch (e) { this.activity = []; }
    this.activitySet = {};
    for (var i = 0; i < this.activity.length; i++) { this.activitySet[this.activity[i]] = true; }

    this.viewMonth = new Date((this.appliedStart || today).getFullYear(), (this.appliedStart || today).getMonth(), 1);

    this._buildPopover();
    this._updateLabel();
    this._bindGlobal();

    this.trigger.setAttribute('aria-haspopup', 'dialog');
    this.trigger.setAttribute('aria-expanded', 'false');
    this.trigger.addEventListener('click', this.toggle.bind(this));
  }

  DateRangePicker.prototype._buildPopover = function () {
    var pop = document.createElement('div');
    pop.className = 'drp-popover';
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-modal', 'false');
    pop.setAttribute('aria-label', 'Select date range');

    // Keep the popover's own controls to itself. The Year/Month/Day <select>s
    // live inside the host form, so their native change/input events would
    // bubble out as if a form field had been edited - and a page that submits
    // on change (reports_analytics.php's Analytics tab) would reload with the
    // *old* hidden values the moment a select was touched, before Apply ever
    // ran. Only _apply()'s own change events, dispatched on the hidden inputs
    // outside this popover, are meant to escape the widget.
    pop.addEventListener('change', function (e) { e.stopPropagation(); });
    pop.addEventListener('input', function (e) { e.stopPropagation(); });

    var body = document.createElement('div');
    body.className = 'drp-popover-body';

    // Presets sidebar
    var presets = document.createElement('div');
    presets.className = 'drp-presets';
    presets.setAttribute('role', 'list');
    this._presetButtons = [];
    var self = this;
    this._presetDefs().forEach(function (def) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'drp-preset';
      btn.textContent = def.label;
      btn.addEventListener('click', function () { self._applyPreset(def); });
      presets.appendChild(btn);
      self._presetButtons.push({ btn: btn, def: def });
    });
    body.appendChild(presets);

    // Calendars
    var cals = document.createElement('div');
    cals.className = 'drp-calendars';
    this.leftMonthEl = this._buildMonthSkeleton('prev');
    this.rightMonthEl = this._buildMonthSkeleton('next');
    cals.appendChild(this.leftMonthEl.el);
    cals.appendChild(this.rightMonthEl.el);
    body.appendChild(cals);

    pop.appendChild(body);

    // Manual entry - Year/Month/Day select dropdowns, click-to-choose like
    // the calendar day grid, nothing typed.
    var manual = document.createElement('div');
    manual.className = 'drp-manual';
    this.startFields = this._buildDateSelectRow('Start');
    this.endFields = this._buildDateSelectRow('End');
    manual.appendChild(this.startFields.row);
    manual.appendChild(this.endFields.row);
    pop.appendChild(manual);

    this.startFields.year.addEventListener('change', function () { self._onManualSelectChange('start'); });
    this.startFields.month.addEventListener('change', function () { self._onManualSelectChange('start'); });
    this.startFields.day.addEventListener('change', function () { self._onManualSelectChange('start'); });
    this.endFields.year.addEventListener('change', function () { self._onManualSelectChange('end'); });
    this.endFields.month.addEventListener('change', function () { self._onManualSelectChange('end'); });
    this.endFields.day.addEventListener('change', function () { self._onManualSelectChange('end'); });

    // Footer
    var footer = document.createElement('div');
    footer.className = 'drp-footer';
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'drp-btn drp-btn-cancel';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', function () { self.close(true); });
    var applyBtn = document.createElement('button');
    applyBtn.type = 'button';
    applyBtn.className = 'drp-btn drp-btn-apply';
    applyBtn.textContent = 'Apply';
    applyBtn.addEventListener('click', function () { self._apply(); });
    footer.appendChild(cancelBtn);
    footer.appendChild(applyBtn);
    pop.appendChild(footer);

    this.root.appendChild(pop);
    this.popover = pop;

    this.leftMonthEl.prevBtn.addEventListener('click', function () { self._shiftMonth(-1); });
    this.rightMonthEl.nextBtn.addEventListener('click', function () { self._shiftMonth(1); });

    this._renderCalendar();
  };

  DateRangePicker.prototype._buildMonthSkeleton = function (kind) {
    var el = document.createElement('div');
    el.className = 'drp-month';

    var head = document.createElement('div');
    head.className = 'drp-month-head';
    var prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'drp-nav drp-nav-prev';
    prevBtn.setAttribute('aria-label', 'Previous month');
    prevBtn.innerHTML = '<i class="fi fi-rr-angle-left"></i>';
    var title = document.createElement('strong');
    var nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'drp-nav drp-nav-next';
    nextBtn.setAttribute('aria-label', 'Next month');
    nextBtn.innerHTML = '<i class="fi fi-rr-angle-right"></i>';
    head.appendChild(prevBtn);
    head.appendChild(title);
    head.appendChild(nextBtn);
    el.appendChild(head);

    var wk = document.createElement('div');
    wk.className = 'drp-weekdays';
    WEEKDAYS.forEach(function (w) {
      var s = document.createElement('span');
      s.textContent = w;
      wk.appendChild(s);
    });
    el.appendChild(wk);

    var grid = document.createElement('div');
    grid.className = 'drp-days';
    grid.setAttribute('role', 'grid');
    el.appendChild(grid);

    return { el: el, title: title, grid: grid, prevBtn: prevBtn, nextBtn: nextBtn };
  };

  function daysInMonth(year, month0) { return new Date(year, month0 + 1, 0).getDate(); }

  // Builds one "Start"/"End" row of Year/Month/Day <select> dropdowns - the
  // manual-entry equivalent of picking a day off the calendar grid: click and
  // choose, nothing typed. The day list is rebuilt to the real day count for
  // whatever year+month is currently selected, so an invalid date (e.g. Feb 30)
  // can never be produced.
  DateRangePicker.prototype._buildDateSelectRow = function (labelText) {
    var row = document.createElement('div');
    row.className = 'drp-manual-row';

    var label = document.createElement('span');
    label.className = 'drp-manual-label';
    label.textContent = labelText;
    row.appendChild(label);

    var year = document.createElement('select');
    year.className = 'drp-select drp-select-year';
    year.setAttribute('aria-label', labelText + ' year');
    var thisYear = new Date().getFullYear();
    for (var y = thisYear - 6; y <= thisYear + 1; y++) {
      var yo = document.createElement('option');
      yo.value = String(y);
      yo.textContent = String(y);
      year.appendChild(yo);
    }

    var month = document.createElement('select');
    month.className = 'drp-select drp-select-month';
    month.setAttribute('aria-label', labelText + ' month');
    MONTH_NAMES.forEach(function (name, idx) {
      var mo = document.createElement('option');
      mo.value = String(idx);
      mo.textContent = name;
      month.appendChild(mo);
    });

    var day = document.createElement('select');
    day.className = 'drp-select drp-select-day';
    day.setAttribute('aria-label', labelText + ' day');

    row.appendChild(year);
    row.appendChild(month);
    row.appendChild(day);

    return { row: row, year: year, month: month, day: day };
  };

  // Rebuilds a row's Day <select> options to match its Year/Month selects
  // (so e.g. switching to February never leaves "30" selected), then sets
  // all three selects to reflect the given date.
  DateRangePicker.prototype._fillDateSelectRow = function (fields, d) {
    var y = d.getFullYear(), m = d.getMonth(), day = d.getDate();
    fields.year.value = String(y);
    fields.month.value = String(m);

    var count = daysInMonth(y, m);
    if (fields.day.childElementCount !== count) {
      fields.day.innerHTML = '';
      for (var i = 1; i <= count; i++) {
        var opt = document.createElement('option');
        opt.value = String(i);
        opt.textContent = String(i);
        fields.day.appendChild(opt);
      }
    }
    fields.day.value = String(Math.min(day, count));
  };

  DateRangePicker.prototype._presetDefs = function () {
    var self = this;
    return [
      { label: 'Today', range: function () { var t = startOfDay(new Date()); return [t, t]; } },
      { label: 'Yesterday', range: function () { var y = addDays(startOfDay(new Date()), -1); return [y, y]; } },
      { label: 'This week', range: function () { var t = startOfDay(new Date()); var dow = (t.getDay() + 6) % 7; return [addDays(t, -dow), t]; } },
      { label: 'Last week', range: function () { var t = startOfDay(new Date()); var dow = (t.getDay() + 6) % 7; var thisMon = addDays(t, -dow); return [addDays(thisMon, -7), addDays(thisMon, -1)]; } },
      { label: 'This month', range: function () { var t = new Date(); return [new Date(t.getFullYear(), t.getMonth(), 1), startOfDay(t)]; } },
      { label: 'Last month', range: function () { var t = new Date(); var first = new Date(t.getFullYear(), t.getMonth() - 1, 1); var last = new Date(t.getFullYear(), t.getMonth(), 0); return [first, last]; } },
      { label: 'This year', range: function () { var t = new Date(); return [new Date(t.getFullYear(), 0, 1), startOfDay(t)]; } },
      { label: 'Last year', range: function () { var t = new Date(); return [new Date(t.getFullYear() - 1, 0, 1), new Date(t.getFullYear() - 1, 11, 31)]; } },
      { label: 'All time', range: function () { return [new Date(2020, 0, 1), startOfDay(new Date())]; } }
    ];
  };

  DateRangePicker.prototype._applyPreset = function (def) {
    var range = def.range();
    this.pendingStart = range[0];
    this.pendingEnd = range[1];
    this.viewMonth = new Date(this.pendingStart.getFullYear(), this.pendingStart.getMonth(), 1);
    this._renderCalendar();
    this._syncManualInputs();
  };

  DateRangePicker.prototype._shiftMonth = function (n) {
    this.viewMonth = addMonths(this.viewMonth, n);
    this._renderCalendar();
  };

  DateRangePicker.prototype._renderCalendar = function () {
    this._renderMonth(this.leftMonthEl, this.viewMonth);
    this._renderMonth(this.rightMonthEl, addMonths(this.viewMonth, 1));

    var today = startOfDay(new Date());
    this.leftMonthEl.prevBtn.disabled = false;
    this.rightMonthEl.nextBtn.disabled = false;

    var activePreset = this._matchingPreset();
    this._presetButtons.forEach(function (p) {
      p.btn.classList.toggle('is-active', activePreset === p.def);
    });
  };

  DateRangePicker.prototype._matchingPreset = function () {
    for (var i = 0; i < this._presetDefs().length; i++) {
      var def = this._presetDefs()[i];
      var r = def.range();
      if (sameDay(r[0], this.pendingStart) && sameDay(r[1], this.pendingEnd)) return this._presetDefs()[i];
    }
    return null;
  };

  DateRangePicker.prototype._renderMonth = function (m, monthDate) {
    m.title.textContent = MONTH_NAMES[monthDate.getMonth()] + ' ' + monthDate.getFullYear();
    m.grid.innerHTML = '';

    var firstOfMonth = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
    var leadDow = (firstOfMonth.getDay() + 6) % 7; // Monday = 0
    var gridStart = addDays(firstOfMonth, -leadDow);
    var today = startOfDay(new Date());
    var self = this;

    for (var i = 0; i < 42; i++) {
      var d = addDays(gridStart, i);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'drp-day';
      btn.textContent = d.getDate();
      btn.setAttribute('role', 'gridcell');
      var iso = toISO(d);
      btn.dataset.iso = iso;

      if (d.getMonth() !== monthDate.getMonth()) btn.classList.add('is-muted');
      if (sameDay(d, today)) { btn.classList.add('is-today'); btn.setAttribute('aria-current', 'date'); }
      if (this.activitySet[iso]) btn.classList.add('has-data');

      var isStart = sameDay(d, this.pendingStart);
      var isEnd = sameDay(d, this.pendingEnd);
      var inRange = this.pendingStart && this.pendingEnd && d > this.pendingStart && d < this.pendingEnd;
      if (isStart) { btn.classList.add('is-start'); btn.setAttribute('aria-selected', 'true'); }
      if (isEnd) { btn.classList.add('is-end'); btn.setAttribute('aria-selected', 'true'); }
      if (inRange) btn.classList.add('is-in-range', 'is-range-start', 'is-range-end');
      if (isStart && this.pendingEnd && this.pendingEnd > this.pendingStart) btn.classList.add('is-range-start');
      if (isEnd && this.pendingStart && this.pendingStart < this.pendingEnd) btn.classList.add('is-range-end');

      btn.addEventListener('click', function () { self._onDayClick(parseISO(this.dataset.iso)); });
      btn.addEventListener('keydown', function (e) { self._onDayKeydown(e, this); });

      m.grid.appendChild(btn);
    }
  };

  DateRangePicker.prototype._onDayKeydown = function (e, cell) {
    var deltas = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    if (deltas.hasOwnProperty(e.key)) {
      e.preventDefault();
      var d = addDays(parseISO(cell.dataset.iso), deltas[e.key]);
      if (d.getMonth() !== this.viewMonth.getMonth() && d.getMonth() !== addMonths(this.viewMonth, 1).getMonth()) {
        this.viewMonth = new Date(d.getFullYear(), d.getMonth(), 1);
      }
      this._renderCalendar();
      var iso = toISO(d);
      var next = this.popover.querySelector('.drp-day[data-iso="' + iso + '"]');
      if (next) next.focus();
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      this._onDayClick(parseISO(cell.dataset.iso));
    }
  };

  DateRangePicker.prototype._onDayClick = function (d) {
    if (this._rangeComplete || !this.pendingStart) {
      this.pendingStart = d;
      this.pendingEnd = d;
      this._rangeComplete = false;
    } else if (d < this.pendingStart) {
      this.pendingEnd = this.pendingStart;
      this.pendingStart = d;
      this._rangeComplete = true;
    } else {
      this.pendingEnd = d;
      this._rangeComplete = true;
    }
    this._renderCalendar();
    this._syncManualInputs();
  };

  // Fired on any Year/Month/Day select change in either row - reads all three
  // selects for that row (rebuilding the Day options first, in case Year/Month
  // changed the day count) and applies it exactly like a calendar day click.
  DateRangePicker.prototype._onManualSelectChange = function (which) {
    var fields = which === 'start' ? this.startFields : this.endFields;
    var y = parseInt(fields.year.value, 10);
    var m = parseInt(fields.month.value, 10);
    var count = daysInMonth(y, m);
    if (fields.day.childElementCount !== count) {
      var keep = Math.min(parseInt(fields.day.value, 10) || 1, count);
      fields.day.innerHTML = '';
      for (var i = 1; i <= count; i++) {
        var opt = document.createElement('option');
        opt.value = String(i);
        opt.textContent = String(i);
        fields.day.appendChild(opt);
      }
      fields.day.value = String(keep);
    }
    var d = new Date(y, m, parseInt(fields.day.value, 10));

    if (which === 'start') { this.pendingStart = d; if (!this.pendingEnd || this.pendingEnd < d) this.pendingEnd = d; }
    else { this.pendingEnd = d; if (!this.pendingStart || this.pendingStart > d) this.pendingStart = d; }
    this._rangeComplete = true;
    this.viewMonth = new Date(this.pendingStart.getFullYear(), this.pendingStart.getMonth(), 1);
    this._renderCalendar();
    this._syncManualInputs();
  };

  DateRangePicker.prototype._syncManualInputs = function () {
    var today = startOfDay(new Date());
    this._fillDateSelectRow(this.startFields, this.pendingStart || today);
    this._fillDateSelectRow(this.endFields, this.pendingEnd || today);
  };

  DateRangePicker.prototype._updateLabel = function () {
    this.labelEl.textContent = (this.appliedStart && this.appliedEnd)
      ? formatLabel(this.appliedStart) + ' – ' + formatLabel(this.appliedEnd)
      : (this.labelEl.getAttribute('data-drp-placeholder') || 'All time');
  };

  DateRangePicker.prototype._apply = function () {
    this.appliedStart = this.pendingStart;
    this.appliedEnd = this.pendingEnd;
    this.hiddenStart.value = this.appliedStart ? toISO(this.appliedStart) : '';
    this.hiddenEnd.value = this.appliedEnd ? toISO(this.appliedEnd) : '';
    this._updateLabel();
    this.close(false);
    // Setting .value from script never fires 'change' on its own, so announce it:
    // pages without a submit button of their own (reports_analytics.php's Analytics
    // tab) listen for this to reload themselves as soon as a range is applied.
    // Harmless on pages that do have a submit button - nothing listens there.
    this.hiddenStart.dispatchEvent(new Event('change', { bubbles: true }));
    this.hiddenEnd.dispatchEvent(new Event('change', { bubbles: true }));
  };

  DateRangePicker.prototype.toggle = function () {
    if (this.root.classList.contains('is-open')) this.close(true);
    else this.open();
  };

  DateRangePicker.prototype.open = function () {
    this.pendingStart = this.appliedStart;
    this.pendingEnd = this.appliedEnd;
    this._rangeComplete = true;
    var anchor = this.appliedStart || startOfDay(new Date());
    this.viewMonth = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    this._renderCalendar();
    this._syncManualInputs();
    this.root.classList.add('is-open');
    this.trigger.setAttribute('aria-expanded', 'true');
    var firstDay = this.popover.querySelector('.drp-day.is-start') || this.popover.querySelector('.drp-day:not(.is-muted)');
    if (firstDay) firstDay.focus();
  };

  DateRangePicker.prototype.close = function (revert) {
    if (revert) {
      this.pendingStart = this.appliedStart;
      this.pendingEnd = this.appliedEnd;
    }
    this.root.classList.remove('is-open');
    this.trigger.setAttribute('aria-expanded', 'false');
    this.trigger.focus();
  };

  DateRangePicker.prototype._bindGlobal = function () {
    var self = this;
    document.addEventListener('click', function (e) {
      if (self.root.classList.contains('is-open') && !self.root.contains(e.target)) self.close(true);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.root.classList.contains('is-open')) self.close(true);
    });
  };

  function initAll() {
    var nodes = document.querySelectorAll('.drp[data-drp]');
    for (var i = 0; i < nodes.length; i++) {
      if (!nodes[i]._drpInstance) nodes[i]._drpInstance = new DateRangePicker(nodes[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  window.DateRangePicker = DateRangePicker;
})();
