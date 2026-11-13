/* ============================================================================
   ai-builder-skin.js — the visible layer of the AI Builder redesign.
   ----------------------------------------------------------------------------
   WHAT THIS IS NOT: it is not a form. It reads nothing, validates nothing and
   sends nothing. Every control it draws is a skin over a control that already
   exists on the page, and the only thing it ever does to that control is set
   `.value` / `.checked` and dispatch a real `change` event — exactly what a
   mouse click on the native control would do. ai_builder.php's own listeners
   (`aibBudgetPreset` change, `aibPerfSelect` change, the two checkbox
   dropdowns, `aibState`, `aibGenerate()`) run untouched, so the JSON body
   posted to api/ai_builder.php is byte-for-byte what it was before.

   Two-way by design:
     skin click  -> native.value = x; native.dispatchEvent(new Event('change'))
     native set  -> the `value` / `checked` property is wrapped per element, so
                    the page's own aibResetWizard() (which assigns .value = ''
                    WITHOUT dispatching) still repaints the skin. The wrapper
                    never dispatches anything itself, so there is no loop and
                    no extra state write.

   Every option label, value and order is read out of the DOM at build time.
   Nothing is hard-coded except the extra prose the redesign adds (the tile
   hints and the two choice-card notes), keyed by option value.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.querySelector('.aib-wrap');
  if (!root) { return; }

  /* ---- extra copy the redesign adds. Keys are the REAL option values. ---- */
  var USE_HINTS = {
    gaming:        '1080p – 4K',
    office:        'Docs, sheets, calls',
    school:        'Coursework, research',
    programming:   'Compile, containers',
    video_editing: 'Timeline scrub, export',
    photo_editing: 'RAW, colour accurate',
    streaming:     'Encode + play',
    '3d_rendering': 'Viewport + render',
    general:       'Browsing, media'
  };

  var $ = function (id) { return document.getElementById(id); };
  var el = function (tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  };

  /* Fire the same event a real user interaction fires. `bubbles` matters
     because some listeners could be delegated; the page's are direct, but a
     bubbling change is what a native control emits anyway. */
  function fireChange(node) {
    node.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function fireInput(node) {
    node.dispatchEvent(new Event('input', { bubbles: true }));
  }

  /* Wrap one element's `value` (or `checked`) property so programmatic writes
     from the page's own code repaint the skin. Reads and writes still go
     through the native accessors, so the element behaves identically — the
     wrapper adds a callback and nothing else, and never dispatches an event
     of its own (that would double-write aibState).

     Callbacks are kept in a per-element registry so wrapping the same
     property twice adds a second listener instead of replacing the first. */
  var OBSERVED = '__aibxObserved';
  function observeProp(node, prop, onSet) {
    if (!node) { return; }
    if (!node[OBSERVED]) {
      Object.defineProperty(node, OBSERVED, { value: {}, enumerable: false });
    }
    var reg = node[OBSERVED];
    if (reg[prop]) { reg[prop].push(onSet); return; }

    var proto = Object.getPrototypeOf(node);
    var desc = null;
    while (proto && !desc) {
      desc = Object.getOwnPropertyDescriptor(proto, prop);
      proto = Object.getPrototypeOf(proto);
    }
    if (!desc || !desc.set) { return; }

    reg[prop] = [onSet];
    Object.defineProperty(node, prop, {
      configurable: true,
      enumerable: desc.enumerable,
      get: function () { return desc.get.call(this); },
      set: function (v) {
        desc.set.call(this, v);
        reg[prop].forEach(function (fn) { fn(); });
      }
    });
  }

  var repaints = [];                       /* every skin's redraw function */
  function repaintAll() { repaints.forEach(function (fn) { fn(); }); }

  /* ---------------------------------------------------------------------- */
  /* 1. Question 1 — base PC. Two choice cards + a nested model list.        */
  /* ---------------------------------------------------------------------- */
  var basePc = $('aibBasePc');            /* absent when no desktop is in stock */
  var baseCards = null;
  var modelPanel = null;

  function buildBasePc() {
    if (!basePc) { return; }
    var host = $('aibxBaseSkin');
    if (!host) { return; }

    var opts = Array.prototype.slice.call(basePc.options);
    var scratch = opts[0];                          /* value "" */
    var models = opts.slice(1);                     /* the real product ids */

    var cards = el('div', 'aibx-choicecards');
    cards.setAttribute('role', 'radiogroup');
    cards.setAttribute('aria-label', 'Already own one of our pre-built desktops?');

    function card(title, note) {
      var b = el('button', 'aibx-choicecard');
      b.type = 'button';
      b.setAttribute('role', 'radio');
      b.setAttribute('aria-checked', 'false');
      b.appendChild(el('span', 'aibx-choicecard-t', title));
      b.appendChild(el('span', 'aibx-choicecard-n', note));
      var dot = el('span', 'aibx-radio');
      dot.setAttribute('aria-hidden', 'true');
      b.appendChild(dot);
      return b;
    }

    /* The "new build" card's title is the real option's own text. */
    var cNew = card(scratch.text.replace(/^No\s*-\s*/i, '').replace(/^b/, 'B'),
                    'Every part chosen for you, nothing assumed.');
    var cOwn = card('I own a TechWorld desktop',
                    'Pick your model — we keep its CPU, board, RAM, PSU and case, and recommend add-ons only.');
    cards.appendChild(cNew);
    cards.appendChild(cOwn);

    modelPanel = el('div', 'aibx-modelpanel');
    modelPanel.id = 'aibxModelPanel';
    modelPanel.hidden = true;
    modelPanel.appendChild(el('p', 'aibx-modelpanel-cap', 'Which model do you own?'));
    var rows = el('div', 'aibx-modelrows');
    rows.setAttribute('role', 'radiogroup');
    rows.setAttribute('aria-label', 'Which model do you own?');

    models.forEach(function (opt) {
      /* Option text is "<name> — <price>". Split on the em dash the PHP
         writes; if it is ever not there, the whole label becomes the name. */
      var parts = opt.text.split('—');
      var name = (parts[0] || opt.text).trim();
      var price = (parts[1] || '').trim();
      var b = el('button', 'aibx-modelrow');
      b.type = 'button';
      b.setAttribute('role', 'radio');
      b.setAttribute('aria-checked', 'false');
      b.dataset.aibxValue = opt.value;
      b.appendChild(el('span', 'aibx-modelrow-n', name));
      if (price) { b.appendChild(el('span', 'aibx-modelrow-p', price)); }
      b.addEventListener('click', function () {
        basePc.value = opt.value;
        fireChange(basePc);
        repaintAll();
      });
      rows.appendChild(b);
    });
    modelPanel.appendChild(rows);

    /* Which branch the customer is on, tracked separately from basePc.value.
       It has to be: "I own a desktop" is a real choice the moment it is
       clicked, but it sets no value until a model row is picked, so the value
       alone cannot tell "own, nothing picked yet" apart from "from scratch". */
    var baseBranch = basePc.value ? 'own' : 'new';

    cNew.addEventListener('click', function () {
      baseBranch = 'new';
      basePc.value = '';
      fireChange(basePc);
      repaintAll();
    });
    cOwn.addEventListener('click', function () {
      /* Opening the panel alone still selects no MODEL — the first row does.
         The card itself is checked straight away, though: it is the branch. */
      baseBranch = 'own';
      paint();
      var first = rows.querySelector('.aibx-modelrow');
      if (first) { first.focus(); }
    });
    cOwn.setAttribute('aria-expanded', 'false');
    cOwn.setAttribute('aria-controls', 'aibxModelPanel');

    host.appendChild(cards);
    host.appendChild(modelPanel);
    baseCards = { cNew: cNew, cOwn: cOwn, rows: rows };

    function paint() {
      /* A chosen model always implies the 'own' branch, however the value got
         set - restored state, or the hidden <select> changed from elsewhere. */
      if (basePc.value) { baseBranch = 'own'; }
      var owns = baseBranch === 'own';

      baseCards.cNew.setAttribute('aria-checked', owns ? 'false' : 'true');
      baseCards.cOwn.setAttribute('aria-checked', owns ? 'true' : 'false');
      /* Assigned both ways. This used to only ever set hidden = false, so once
         the panel had been opened, picking "from scratch" left it on screen
         asking which model you own. */
      modelPanel.hidden = !owns;
      baseCards.cOwn.setAttribute('aria-expanded', owns ? 'true' : 'false');
      Array.prototype.forEach.call(baseCards.rows.children, function (r) {
        r.setAttribute('aria-checked', r.dataset.aibxValue === basePc.value ? 'true' : 'false');
      });
    }
    repaints.push(paint);
    basePc.addEventListener('change', paint);
    observeProp(basePc, 'value', paint);
    paint();
  }

  /* ---------------------------------------------------------------------- */
  /* 2. Question 2 — budget. Six pills over the real <select>.               */
  /* ---------------------------------------------------------------------- */
  var budget = $('aibBudgetPreset');
  var minInput = $('aibBudgetMin');
  var maxInput = $('aibBudgetMax');

  function buildBudget() {
    var host = $('aibxBudgetSkin');
    if (!budget || !host) { return; }

    var wrap = el('div', 'aibx-pills');
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Budget');

    Array.prototype.slice.call(budget.options).forEach(function (opt) {
      if (!opt.value) { return; }                 /* skip the placeholder */
      var b = el('button', 'aibx-pill', opt.text);
      b.type = 'button';
      b.setAttribute('aria-pressed', 'false');
      b.dataset.aibxValue = opt.value;
      b.addEventListener('click', function () {
        budget.value = opt.value;
        fireChange(budget);
        /* "Custom amount..." focuses the Min field, which is always visible
           now, instead of revealing a hidden row. */
        if (opt.value === 'custom' && minInput) { minInput.focus(); }
        repaintAll();
      });
      wrap.appendChild(b);
    });
    host.appendChild(wrap);

    function paint() {
      Array.prototype.forEach.call(wrap.children, function (b) {
        b.setAttribute('aria-pressed', b.dataset.aibxValue === budget.value ? 'true' : 'false');
      });
    }
    repaints.push(paint);
    budget.addEventListener('change', paint);
    observeProp(budget, 'value', paint);
    paint();
  }

  /* ---------------------------------------------------------------------- */
  /* 3. Question 3 — performance. Four tiles over the real <select>.         */
  /* ---------------------------------------------------------------------- */
  var perf = $('aibPerfSelect');

  function buildPerf() {
    var host = $('aibxPerfSkin');
    if (!perf || !host) { return; }

    var wrap = el('div', 'aibx-tiles-perf');
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Performance preference');

    Array.prototype.slice.call(perf.options).forEach(function (opt) {
      if (!opt.value) { return; }
      /* Option text is "<Label> - <description>". Both halves are kept: the
         label becomes the mono key, the description stays underneath. */
      var idx = opt.text.indexOf(' - ');
      var key = idx > -1 ? opt.text.slice(0, idx) : opt.text;
      var desc = idx > -1 ? opt.text.slice(idx + 3) : '';
      var b = el('button', 'aibx-tile');
      b.type = 'button';
      b.setAttribute('aria-pressed', 'false');
      b.dataset.aibxValue = opt.value;
      b.appendChild(el('span', 'aibx-tile-key', key));
      if (desc) {
        b.appendChild(el('span', 'aibx-tile-desc', desc.charAt(0).toUpperCase() + desc.slice(1)));
      }
      b.addEventListener('click', function () {
        perf.value = opt.value;
        fireChange(perf);
        repaintAll();
      });
      wrap.appendChild(b);
    });
    host.appendChild(wrap);

    function paint() {
      Array.prototype.forEach.call(wrap.children, function (b) {
        b.setAttribute('aria-pressed', b.dataset.aibxValue === perf.value ? 'true' : 'false');
      });
    }
    repaints.push(paint);
    perf.addEventListener('change', paint);
    observeProp(perf, 'value', paint);
    paint();
  }

  /* ---------------------------------------------------------------------- */
  /* 4 + 5. Questions 4 and 5 — the two checkbox dropdowns, re-drawn as      */
  /*        multi-select tiles / chips over the real checkboxes.            */
  /* ---------------------------------------------------------------------- */
  function buildCheckSkin(panelId, hostId, wrapCls, itemCls, label, render) {
    var panel = $(panelId);
    var host = $(hostId);
    if (!panel || !host) { return null; }

    var boxes = Array.prototype.slice.call(panel.querySelectorAll('input[type="checkbox"]'));
    var wrap = el('div', wrapCls);
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', label);

    var buttons = boxes.map(function (cb) {
      /* The label text is the checkbox's own row, minus the icon element. */
      var row = cb.closest('.aib-dd-item');
      var text = row ? row.textContent.trim() : cb.value;
      var b = el('button', itemCls);
      b.type = 'button';
      b.setAttribute('aria-pressed', cb.checked ? 'true' : 'false');
      /* Name the tile after the checkbox it mirrors, the same way the budget
         pills and performance tiles already do. Added so option filtering in
         ai_builder.php can find the tile for a value instead of relying on
         these being drawn in checkbox order; nothing else reads it, and the
         click handler below still works off the checkbox itself. */
      b.dataset.aibxValue = cb.value;
      render(b, text, cb.value);
      b.addEventListener('click', function () {
        cb.checked = !cb.checked;
        fireChange(cb);
        repaintAll();
      });
      /* The page's own reset unchecks these without dispatching. */
      observeProp(cb, 'checked', function () {
        b.setAttribute('aria-pressed', cb.checked ? 'true' : 'false');
      });
      cb.addEventListener('change', function () {
        b.setAttribute('aria-pressed', cb.checked ? 'true' : 'false');
      });
      wrap.appendChild(b);
      return b;
    });
    host.appendChild(wrap);

    function paint() {
      boxes.forEach(function (cb, i) {
        buttons[i].setAttribute('aria-pressed', cb.checked ? 'true' : 'false');
      });
    }
    repaints.push(paint);
    paint();
    return { boxes: boxes, paint: paint };
  }

  var useSkin = null;
  var reqSkin = null;

  function buildUses() {
    useSkin = buildCheckSkin('aibUseDdPanel', 'aibxUseSkin', 'aibx-tiles-use', 'aibx-tile',
      'Intended use', function (b, text, value) {
        b.appendChild(el('span', 'aibx-tile-title', text));
        if (USE_HINTS[value]) { b.appendChild(el('span', 'aibx-tile-hint', USE_HINTS[value])); }
      });
  }
  function buildReqs() {
    reqSkin = buildCheckSkin('aibReqDdPanel', 'aibxReqSkin', 'aibx-chips', 'aibx-chip',
      'Specific requirements', function (b, text) {
        b.textContent = text;
      });
  }

  /* ---------------------------------------------------------------------- */
  /* 6. The rail — brief, progress, CTA note. Read-only; it never writes.    */
  /* ---------------------------------------------------------------------- */
  var brand = $('aibBrand');
  var message = $('aibMessage');
  var generate = $('aibGenerate');

  function selectedLabels(skin) {
    if (!skin) { return []; }
    return skin.boxes.filter(function (cb) { return cb.checked; }).map(function (cb) {
      var row = cb.closest('.aib-dd-item');
      return row ? row.textContent.trim() : cb.value;
    });
  }

  function peso(n) {
    return '₱' + Number(n).toLocaleString('en-PH', { maximumFractionDigits: 0 });
  }

  /* Mirrors aibRequiredFieldsFilled() in ai_builder.php — same three checks,
     same precedence — but only to label the UI. The button's own disabled
     state is still set by that function, never by this one. */
  function budgetText() {
    var mn = parseFloat(minInput && minInput.value) || 0;
    var mx = parseFloat(maxInput && maxInput.value) || 0;
    if (mx > 0) { return (mn > 0 ? peso(mn) + ' – ' : 'Up to ') + peso(mx); }
    if (budget && budget.value && budget.value !== 'custom') {
      var sel = budget.options[budget.selectedIndex];
      return sel ? sel.text : '';
    }
    return '';
  }

  function setRow(key, value) {
    var v = root.querySelector('.aibx-brief-row[data-k="' + key + '"] .aibx-brief-v');
    if (!v) { return; }
    if (value) {
      v.textContent = value;
      v.removeAttribute('data-empty');
    } else {
      v.textContent = '—';
      v.setAttribute('data-empty', '1');
    }
  }

  function updateRail() {
    var baseText = '';
    if (basePc) {
      baseText = basePc.value
        ? (basePc.options[basePc.selectedIndex].text.split('—')[0] || '').trim()
        : 'New build';
    } else {
      baseText = 'New build';
    }
    var bText = budgetText();
    var uses = selectedLabels(useSkin);
    var perfText = perf && perf.value ? (perf.options[perf.selectedIndex].text.split(' - ')[0]) : '';
    var reqs = selectedLabels(reqSkin);

    setRow('base', baseText);
    setRow('budget', bText);
    setRow('use', uses.join(', '));
    setRow('target', perfText);
    setRow('extras', reqs.length ? reqs.length + (reqs.length === 1 ? ' must-have' : ' must-haves')
                                 : (brand && brand.value.trim() ? brand.value.trim() : ''));

    /* "N of 4 answered" counts the four questions the rail summarises. */
    var answered = [!!bText, uses.length > 0, !!perfText,
                    reqs.length > 0 || !!(brand && brand.value.trim()) || !!(message && message.value.trim())]
      .filter(Boolean).length;
    var ready = root.querySelector('.aibx-ready');
    var fill = root.querySelector('.aibx-bar-fill');
    if (ready) { ready.textContent = 'Ready to build · ' + answered + ' of 4 answered'; }
    /* scaleX, not width - the bar is composited rather than relaid out on
       every keystroke. See .aibx-bar-fill in ai-builder-skin.css. */
    if (fill) { fill.style.transform = 'scaleX(' + (answered / 4) + ')'; }

    /* Step rail: done / current / todo, driven by the same fields. */
    var stepDone = [
      true,                      /* 1 Basics — always answerable, defaults to "new build" */
      !!bText,
      !!perfText && uses.length > 0,
      reqs.length > 0 || !!(brand && brand.value.trim()) || !!(message && message.value.trim())
    ];
    /* Published for the wizard nav in ai_builder.php, which gates its Next
       button on it. updateRail() already runs on every input/change, so this
       stays current without a second listener - and, more importantly, the
       wizard does NOT get to invent its own idea of "is this step complete".
       One definition, right here, used by the rail and by Next alike. */
    root.dataset.aibxStepDone = stepDone.map(function (d) { return d ? '1' : '0'; }).join('');
    root.dataset.aibxMissing = JSON.stringify({
      2: bText ? [] : ['a budget'],
      3: [].concat(perfText ? [] : ['a performance target'],
                   uses.length ? [] : ['at least one intended use']),
      4: []
    });

    /* Rail position.
     *
     * Only when nothing else owns the rail. ai_builder.php's wizard marks it
     * data-aibx-wiz once it takes over, because the two disagree about what
     * "current" means: the wizard means the step being SHOWN, this means the
     * first step not yet answered. Both writing it produced the bug where
     * answering the budget on step 2 jumped the highlight to step 3 while the
     * customer was still looking at step 2 - this runs on every input event,
     * the wizard's repaint only runs on navigation, so this one won.
     *
     * The flag is read on every call rather than cached, so it still works if
     * the wizard initialises after the first repaint. */
    var rail = root.querySelector('.aibx-steprail');
    if (rail && !rail.dataset.aibxWiz) {
      var firstOpen = stepDone.indexOf(false);
      rail.querySelectorAll('li').forEach(function (li, i) {
        li.setAttribute('data-state', stepDone[i] ? 'done' : (i === firstOpen ? 'current' : 'todo'));
      });
    }

    /* Name what is still missing under a disabled CTA. */
    var missing = [];
    if (!bText) { missing.push('budget'); }
    if (!perfText) { missing.push('performance'); }
    if (!uses.length) { missing.push('intended use'); }
    var note = root.querySelector('.aibx-cta-missing');
    if (note) {
      if (generate && generate.disabled && missing.length) {
        note.textContent = 'Still needed: ' + missing.join(' · ');
        note.hidden = false;
      } else {
        note.hidden = true;
      }
    }
  }

  /* ---------------------------------------------------------------------- */
  /* 7. Error styling, applied only after the page rejected a submit.        */
  /* ---------------------------------------------------------------------- */
  function wireErrorState() {
    if (!generate) { return; }
    /* Runs after the page's own click handler (added first, so it fires
       first). If the wizard is still on step 1 a moment later, the page
       refused the submit — mark the sections that are short. */
    generate.addEventListener('click', function () {
      setTimeout(function () {
        var step1 = root.querySelector('.aib-step[data-step="1"]');
        if (!step1 || step1.hidden) { return; }
        var uses = selectedLabels(useSkin);
        root.querySelectorAll('.aibx-sec').forEach(function (sec) {
          var bad = (sec.dataset.aibxFor === 'budget' && !budgetText())
                 || (sec.dataset.aibxFor === 'work' && (!(perf && perf.value) || !uses.length));
          if (bad) { sec.setAttribute('data-aibx-error', '1'); }
          else { sec.removeAttribute('data-aibx-error'); }
        });
      }, 0);
    });
  }

  /* ---------------------------------------------------------------------- */
  /* 8. Boot                                                                 */
  /* ---------------------------------------------------------------------- */
  buildBasePc();
  buildBudget();
  buildPerf();
  buildUses();
  buildReqs();
  wireErrorState();

  /* Hide the native controls only now that a working skin is on screen, and
     take them (and anything focusable inside them) out of the tab order so
     each question has exactly one tab stop — the skin's. The elements stay in
     the DOM with their ids, values and listeners intact. */
  root.classList.add('aibx-jsready');
  root.querySelectorAll('.aibx-native').forEach(function (n) {
    n.setAttribute('aria-hidden', 'true');
    n.setAttribute('tabindex', '-1');
    n.querySelectorAll('button, input, select, textarea, a[href]').forEach(function (f) {
      f.setAttribute('tabindex', '-1');
    });
  });

  ['change', 'input'].forEach(function (evt) {
    root.addEventListener(evt, function () { setTimeout(updateRail, 0); }, true);
  });
  /* aibResetWizard() assigns .value = '' without dispatching; the property
     wrappers above repaint the controls, and this repaints the rail. */
  [basePc, budget, perf, minInput, maxInput, brand, message].forEach(function (n) {
    if (n) { observeProp(n, 'value', function () { setTimeout(updateRail, 0); }); }
  });
  updateRail();
})();
