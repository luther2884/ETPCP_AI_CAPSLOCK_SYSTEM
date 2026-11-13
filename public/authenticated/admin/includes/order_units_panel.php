<?php
declare(strict_types=1);

/**
 * includes/order_units_panel.php - the packing bench's serial-number panel,
 * shared by both order queues.
 *
 * WHY AN INCLUDE AND NOT TWO COPIES
 *
 * Home_Delivery_Orders.php and store_pickup_orders.php are separate pages with
 * separate queries, and the thing they have to agree about here is precisely
 * the thing that blocks fulfilment: whether every serialised line on an order
 * has a unit recorded. Two copies of that check would eventually disagree, and
 * the disagreement would show up as a queue saying an order is ready while the
 * scan refuses to move it. Both pages call the same three functions below, and
 * all three defer to config/product_units.php for the actual rule.
 *
 * Three entry points:
 *
 *   tw_units_attach()  hangs `serials` on every order item and `units_*` on
 *                      every order. One query per order, called once after the
 *                      queue has loaded its orders.
 *   tw_units_panel()   renders the panel for one order.
 *   tw_units_assets()  the CSS and JS, emitted once per page however many
 *                      times the panel is rendered.
 */

require_once __DIR__ . '/../../../../config/product_units.php';

/**
 * Attach unit data to a queue's already-loaded orders.
 *
 * Takes the array by reference and mutates it in place, which is how both
 * queues already handle their per-order enrichment (proof photos, address
 * snapshots). Each item gains `serials`, a plain list of strings that
 * tw_invoice_lines() picks up for the printed invoice, so the receipt and the
 * screen cannot show different serials.
 *
 * @param array<int,array<string,mixed>> $orders
 */
function tw_units_attach(PDO $pdo, array &$orders): void
{
    foreach ($orders as &$order) {
        $orderId = (int)($order['id'] ?? 0);
        $order['units_lines'] = [];
        $order['units_outstanding'] = [];

        if ($orderId <= 0) { continue; }

        try {
            $byItem = pu_units_for_order($pdo, $orderId);
            $order['units_outstanding'] = pu_unassigned_lines($pdo, $orderId);

            // Every item gains a `serials` key - empty for an accessory. Which
            // lines are SERIALISED comes from pu_unassigned_lines() above,
            // which requires tracking to be LIVE rather than merely flagged: a
            // flagged product nobody has entered serials for still sells on
            // quantity stock, and showing an empty serial slot for it would ask
            // staff for something that does not exist.
            $withSerials = [];
            foreach (($order['items'] ?? []) as $item) {
                $itemId = (int)($item['id'] ?? 0);
                $units = $byItem[$itemId] ?? [];

                // Serials for the invoice and the row, sold or reserved only:
                // a unit that has been returned is no longer what the customer
                // has, and printing it on their receipt would be wrong.
                $serials = [];
                foreach ($units as $u) {
                    if (in_array((string)$u['status'], ['reserved', 'sold'], true)) {
                        $serials[] = (string)$u['serial_number'];
                    }
                }
                $item['serials'] = $serials;
                // The same units as full rows. `serials` stays a plain list of
                // strings because that is what tw_invoice_lines() consumes for
                // the printed invoice; the panel needs unit_id and status too,
                // to offer "Put back" on a reserved unit and to withhold it
                // from a sold one.
                $item['unit_rows'] = array_values(array_filter($units, static fn($u) =>
                    in_array((string)$u['status'], ['reserved', 'sold'], true)));
                $withSerials[] = $item;
            }

            // Rebuild rather than mutate inside the loop: $item is a copy, so
            // assigning `serials` to it does not reach the original row.
            $order['items'] = $withSerials;
            $order['units_lines'] = $byItem;
        } catch (Throwable $e) {
            error_log('tw_units_attach order ' . $orderId . ': ' . $e->getMessage());
        }
    }
    // Break the dangling reference. Both queues learned this the hard way -
    // see the unset($order) note in Home_Delivery_Orders.php - and a `foreach
    // ($orders as $order)` further down the page would otherwise overwrite the
    // second-to-last order with a copy of the last.
    unset($order);
}

/**
 * The serial numbers already recorded for one order line, as display chips.
 * Returns '' when the line is not serialised, so callers can drop it inline.
 *
 * @param array<string,mixed> $item
 */
function tw_units_item_serials(array $item): string
{
    $serials = (array)($item['serials'] ?? []);
    if (!$serials) { return ''; }

    $out = '<div class="tw-unit-serials">';
    $out .= count($serials) > 1 ? 'Serials: ' : 'Serial: ';
    $parts = [];
    foreach ($serials as $s) {
        $parts[] = '<span class="tw-unit-serial">' . htmlspecialchars((string)$s) . '</span>';
    }
    $out .= implode(' ', $parts) . '</div>';
    return $out;
}

/**
 * The assignment panel for one order.
 *
 * Only rendered when the order actually has serialised lines, and only
 * actionable while the order is still in a state where its units can change.
 * An order already shipped or completed shows its serials read-only: the goods
 * have left, and letting staff re-point a serial afterwards would quietly
 * rewrite who received what.
 *
 * @param array<string,mixed> $order
 */
function tw_units_panel(array $order, bool $disabled = false): void
{
    $lines = (array)($order['units_lines'] ?? []);
    $outstanding = (array)($order['units_outstanding'] ?? []);

    // Nothing serialised on this order: no panel. The overwhelming majority of
    // orders in this shop are accessories, and an empty panel on every one of
    // them would be noise.
    $hasTracked = false;
    foreach (($order['items'] ?? []) as $item) {
        if (!empty($item['serials'])) { $hasTracked = true; break; }
    }
    if (!$hasTracked && !$outstanding) { return; }

    $orderId = (int)($order['id'] ?? 0);
    $status = (string)($order['status'] ?? '');
    // 'preparing' is the picking step and the only point at which the scan
    // demands serials, so it is the state this panel exists for. 'awaiting_
    // payment' is included because staff sometimes pre-pick a paid-pending
    // order, and blocking them would be arbitrary.
    $editable = !$disabled && in_array($status, ['awaiting_payment', 'preparing', 'packed', 'ready'], true);

    $outstandingByItem = [];
    foreach ($outstanding as $o) { $outstandingByItem[(int)$o['order_item_id']] = $o; }
    ?>
    <div class="tw-units-panel" data-order-id="<?= $orderId ?>">
      <div class="tw-units-head">
        <h4>Unit serial numbers</h4>
        <?php if ($outstanding): ?>
          <?php // The verb has to agree too: "1 line still needs", "2 lines
                // still need". Pluralising only the noun read as broken
                // English on the overwhelmingly common single-line order. ?>
          <span class="tw-units-flag tw-units-flag--need">
            <?= count($outstanding) ?> line<?= count($outstanding) === 1 ? ' still needs' : 's still need' ?> a serial
          </span>
        <?php else: ?>
          <span class="tw-units-flag tw-units-flag--ok">All units recorded</span>
        <?php endif; ?>
      </div>

      <?php if ($outstanding): ?>
        <p class="tw-units-note">
          This order cannot be set aside as Ready until every serialised line below has the serial of the
          unit you are actually packing.
        </p>
      <?php endif; ?>

      <div class="tw-units-lines">
        <?php foreach (($order['items'] ?? []) as $item): ?>
          <?php
            $itemId = (int)($item['id'] ?? 0);
            $need = $outstandingByItem[$itemId] ?? null;
            $assigned = (array)($item['serials'] ?? []);
            // Not serialised and nothing outstanding - an accessory line on a
            // mixed order. Skipped so the panel lists only what it is about.
            if (!$assigned && !$need) { continue; }
            $qty = (int)($item['quantity'] ?? 1);
          ?>
          <div class="tw-units-line" data-item-id="<?= $itemId ?>">
            <div class="tw-units-line-head">
              <span class="tw-units-line-name"><?= htmlspecialchars((string)($item['product_name'] ?? 'Item')) ?></span>
              <span class="tw-units-line-count">
                <?= count($assigned) ?> of <?= $qty ?> recorded
              </span>
            </div>

            <?php $unitRows = (array)($item['unit_rows'] ?? []); ?>
            <?php if ($unitRows): ?>
              <div class="tw-unit-serials">
                <?php foreach ($unitRows as $u): ?>
                  <span class="tw-unit-serial">
                    <?= htmlspecialchars((string)$u['serial_number']) ?>
                    <?php /* Put back is offered only for a RESERVED unit on an
                             editable order. A sold unit has physically left
                             with the customer - detaching that serial would
                             rewrite who received which unit, which is the one
                             record this feature exists to make trustworthy.
                             Those come back through the returns desk. */ ?>
                    <?php if ($editable && (string)$u['status'] === 'reserved'): ?>
                      <button type="button" class="tw-unit-release"
                              data-unit-id="<?= (int)$u['unit_id'] ?>"
                              data-keep-label
                              title="Put this unit back in stock"
                              aria-label="Put <?= htmlspecialchars((string)$u['serial_number']) ?> back in stock">&times;</button>
                    <?php endif; ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($editable): ?>
              <div class="tw-units-actions">
                <?php // The fast path: a barcode scanner typing into this field
                      // ends with Enter, which submits without a click. ?>
                <input type="search" class="tw-units-scan" placeholder="Scan or type the serial on the box"
                       autocomplete="off" spellcheck="false" aria-label="Serial number to assign">
                <button type="button" class="ad-btn ad-btn--primary tw-units-assign-scan">Record</button>

                <?php // Options are fetched on demand by tw_units_assets()'s JS. ?>
                <span class="tw-units-or">or</span>
                <select class="tw-units-pick" aria-label="Pick a unit from stock">
                  <option value="">Pick from stock…</option>
                </select>
                <button type="button" class="ad-btn ad-btn--secondary tw-units-assign-pick">Assign</button>
              </div>
              <p class="tw-units-msg" role="status" aria-live="polite"></p>
            <?php elseif (!$editable && $need): ?>
              <p class="tw-units-msg tw-units-msg--warn">
                <?= (int)$need['quantity'] - (int)$need['assigned'] ?> unit(s) were never recorded for this line.
              </p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

/**
 * The panel's CSS and JS. Emits once however many orders are on the page.
 *
 * The dropdown of available units is filled on demand rather than rendered
 * server-side: a queue page shows twenty orders, and inlining every in-stock
 * serial for every line would add hundreds of <option>s to a page where staff
 * will open at most one or two panels.
 */
function tw_units_assets(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    ?>
    <style>
      .tw-units-panel{margin:0 0 15px;padding:14px;border-radius:var(--radius-sm);
        background:var(--admin-surface);box-shadow:var(--neu-pressed);color:var(--admin-text);}
      .tw-units-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px;}
      .tw-units-head h4{margin:0;color:var(--admin-text);font-size:15px;}
      .tw-units-flag{font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;}
      .tw-units-flag--need{background:#fff4e0;color:#8a5a00;}
      .tw-units-flag--ok{background:#e7f6ef;color:#1f7a53;}
      .tw-units-note{margin:0 0 10px;font-size:13px;color:var(--admin-muted);line-height:1.5;}
      .tw-units-line{padding:10px 0;border-top:1px solid rgba(128,128,128,.18);}
      .tw-units-line:first-child{border-top:0;}
      .tw-units-line-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;}
      .tw-units-line-name{font-weight:600;}
      .tw-units-line-count{font-size:12px;color:var(--admin-muted);}
      .tw-unit-serials{margin:6px 0 0;display:flex;flex-wrap:wrap;gap:6px;align-items:center;
        font-size:12px;color:var(--admin-muted);}
      .tw-unit-serial{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
        font-size:12px;font-weight:700;color:var(--admin-text);
        background:rgba(128,128,128,.14);border-radius:4px;padding:2px 7px;
        display:inline-flex;align-items:center;gap:6px;}
      /* Put back. Deliberately quiet - it sits on every reserved serial, and a
         loud button there would read as the primary action on the panel when
         the primary action is recording a serial, not removing one. */
      .tw-unit-serial .tw-unit-release{
        background:none;border:0;padding:0 0 0 1px;margin:0;cursor:pointer;
        font:inherit;font-size:14px;line-height:1;color:var(--admin-muted);
        opacity:.65;transition:opacity .12s,color .12s;}
      .tw-unit-serial .tw-unit-release:hover{opacity:1;color:#a8264d;}
      .tw-unit-serial .tw-unit-release:focus-visible{outline:2px solid #a8264d;outline-offset:2px;opacity:1;}
      .tw-units-actions{margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
      .tw-units-actions input[type="search"],.tw-units-actions select{
        padding:8px 10px;border-radius:6px;border:1px solid rgba(128,128,128,.35);
        background:var(--admin-bg,#fff);color:var(--admin-text);font-size:13px;min-width:170px;}
      .tw-units-actions input[type="search"]{flex:1;min-width:200px;
        font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;}
      .tw-units-or{font-size:12px;color:var(--admin-muted);}
      .tw-units-msg{margin:8px 0 0;font-size:12.5px;line-height:1.5;min-height:0;}
      .tw-units-msg--ok{color:#1f7a53;}
      .tw-units-msg--err{color:#a8264d;}
      .tw-units-msg--warn{color:#8a5a00;}
      @media (max-width:720px){
        .tw-units-actions{flex-direction:column;align-items:stretch;}
        .tw-units-or{text-align:center;}
      }
    </style>
    <script>
    (function () {
      var ENDPOINT = '<?= WEB_ROOT ?>public/authenticated/api/order_units.php';
      var CSRF = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';

      function msg(line, text, kind) {
        var el = line.querySelector('.tw-units-msg');
        if (!el) { return; }
        el.textContent = text || '';
        el.className = 'tw-units-msg' + (kind ? ' tw-units-msg--' + kind : '');
      }

      /* Fill the "pick from stock" dropdown the first time it is touched. The
         queue shows many orders and staff open at most one panel, so loading
         every line's stock up front would be wasted work on every page view. */
      function loadOptions(panel) {
        if (panel.dataset.loaded === '1' || panel.dataset.loading === '1') { return; }
        panel.dataset.loading = '1';

        fetch(ENDPOINT + '?action=list&order_id=' + encodeURIComponent(panel.dataset.orderId), {
          credentials: 'same-origin'
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data || !data.ok) { return; }
            (data.lines || []).forEach(function (ln) {
              var line = panel.querySelector('.tw-units-line[data-item-id="' + ln.order_item_id + '"]');
              if (!line) { return; }
              var sel = line.querySelector('.tw-units-pick');
              if (!sel) { return; }

              /* Units already on THIS line are filtered out - they are shown as
                 chips above and re-assigning one would be a no-op. */
              var assignedIds = (ln.assigned || []).map(function (u) { return String(u.unit_id); });
              (ln.available || []).forEach(function (u) {
                if (assignedIds.indexOf(String(u.unit_id)) !== -1) { return; }
                var opt = document.createElement('option');
                opt.value = u.unit_id;
                opt.textContent = u.serial_number + (u.imei ? ' / ' + u.imei : '');
                sel.appendChild(opt);
              });
              if (sel.options.length === 1) {
                sel.options[0].textContent = 'No other units in stock';
                sel.disabled = true;
              }
            });
            panel.dataset.loaded = '1';
          })
          .catch(function () { /* the scan field still works */ })
          .finally(function () { panel.dataset.loading = '0'; });
      }

      function send(line, body, button) {
        /* data-keep-label is for buttons whose label is an icon, not a word -
           the tiny "put back" cross on a serial chip. Swapping that for
           "Saving…" stretches the chip and reflows the row mid-click. */
        var iconOnly = button && button.hasAttribute('data-keep-label');
        var was = button ? button.textContent : '';
        if (button) {
          button.disabled = true;
          if (!iconOnly) { button.textContent = 'Saving…'; }
          else { button.style.opacity = '.35'; }
        }

        body.append('csrf_token', CSRF);
        body.append('order_item_id', line.dataset.itemId);

        fetch(ENDPOINT, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.ok) {
              msg(line, data.message + ' Reloading…', 'ok');
              /* A full reload rather than patching the DOM: assigning a unit
                 changes the line's chips, the panel's headline, the stock
                 figures elsewhere on the page and whether the scan will now
                 let the order through. Re-rendering all of that by hand is how
                 the screen ends up disagreeing with the database. */
              setTimeout(function () { window.location.reload(); }, 700);
            } else {
              msg(line, (data && data.message) ? data.message : 'Could not save that.', 'err');
              if (button) {
                button.disabled = false;
                if (!iconOnly) { button.textContent = was; } else { button.style.opacity = ''; }
              }
            }
          })
          .catch(function () {
            msg(line, 'Network problem - nothing was saved.', 'err');
            if (button) {
              button.disabled = false;
              if (!iconOnly) { button.textContent = was; } else { button.style.opacity = ''; }
            }
          });
      }

      document.addEventListener('click', function (e) {
        var scanBtn = e.target.closest('.tw-units-assign-scan');
        if (scanBtn) {
          var line = scanBtn.closest('.tw-units-line');
          var input = line.querySelector('.tw-units-scan');
          var val = (input.value || '').trim();
          if (!val) { msg(line, 'Scan or type a serial number first.', 'err'); input.focus(); return; }
          var fd = new FormData();
          fd.append('action', 'scan');
          fd.append('identifier', val);
          send(line, fd, scanBtn);
          return;
        }

        var relBtn = e.target.closest('.tw-unit-release');
        if (relBtn) {
          var line0 = relBtn.closest('.tw-units-line');
          var fd0 = new FormData();
          fd0.append('action', 'release');
          fd0.append('unit_id', relBtn.getAttribute('data-unit-id'));
          send(line0, fd0, relBtn);
          return;
        }

        var pickBtn = e.target.closest('.tw-units-assign-pick');
        if (pickBtn) {
          var line2 = pickBtn.closest('.tw-units-line');
          var sel = line2.querySelector('.tw-units-pick');
          if (!sel || !sel.value) { msg(line2, 'Pick a unit from the list first.', 'err'); return; }
          var fd2 = new FormData();
          fd2.append('action', 'assign');
          fd2.append('unit_id', sel.value);
          send(line2, fd2, pickBtn);
        }
      });

      /* Enter in the scan field submits - a barcode scanner ends its output
         with a newline, so this is the whole interaction for staff who have
         one. */
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        var input = e.target.closest('.tw-units-scan');
        if (!input) { return; }
        e.preventDefault();
        var btn = input.closest('.tw-units-line').querySelector('.tw-units-assign-scan');
        if (btn) { btn.click(); }
      });

      /* Load the dropdowns when a panel is first interacted with, and when its
         containing details/accordion is opened. focusin covers keyboard users;
         pointerdown covers the mouse without waiting for a click to land. */
      ['pointerdown', 'focusin'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
          var panel = e.target.closest ? e.target.closest('.tw-units-panel') : null;
          if (panel) { loadOptions(panel); }
        }, true);
      });
    })();
    </script>
    <?php
}
