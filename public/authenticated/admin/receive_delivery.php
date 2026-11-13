<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/inventory_stock.php';

require_role(['admin']);

/**
 * admin/receive_delivery.php - Stock In.
 *
 * Records a supplier delivery and turns it into stock. Reached from Inventory
 * Control; the Owner reaches it the same way as every other admin page, by
 * switching into Admin Mode (super-admin/switch_to_admin.php), so
 * require_role(['admin']) covers Manager and Owner both.
 *
 * WHY THE FORM DOES NOT ASK FOR A QUANTITY ON A SERIALISED LINE
 *
 * For a product with requires_serial = 1 the count of serials entered IS the
 * quantity received. Asking for both invites a delivery that says 5 but lists
 * 4 serials, and then something has to decide which number is the truth.
 * The form shows one field or the other, chosen from the product itself, and
 * inv_receive_delivery() applies the same rule server-side.
 */

$pageTitle = 'Receive Delivery - TechWorld Admin';
$activeNav = 'inventory';
$isAdminTheme = true;

$pdo = getConnection();

// Same approval gate every write-capable admin page uses.
$currentUserStatus = 'active';
try {
    $st = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
    $st->execute([getUserId()]);
    if ($row = $st->fetch()) { $currentUserStatus = (string)($row['status'] ?? 'active'); }
} catch (Throwable $e) { /* assume active */ }
$actionsDisabled = (!isSuperAdmin() && $currentUserStatus !== 'active');

$flash = $_SESSION['rd_flash'] ?? null;
unset($_SESSION['rd_flash']);

/* ----------------------------------------------------------------- POST ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!check_csrf()) {
        $_SESSION['rd_flash'] = ['type' => 'error', 'errors' => ['Your session expired. Try again.']];
        header('Location: receive_delivery.php'); exit;
    }
    if ($actionsDisabled) {
        $_SESSION['rd_flash'] = ['type' => 'error', 'errors' => ['Your admin account is awaiting Super Admin approval.']];
        header('Location: receive_delivery.php'); exit;
    }

    try {
        if ($action === 'add_supplier') {
            $name = trim((string)($_POST['supplier_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                $_SESSION['rd_flash'] = ['type' => 'error', 'errors' => ['Enter a supplier name (150 characters maximum).']];
            } else {
                try {
                    $pdo->prepare('INSERT INTO suppliers (name, contact_person, phone, email) VALUES (?, ?, ?, ?)')
                        ->execute([
                            $name,
                            trim((string)($_POST['contact_person'] ?? '')) ?: null,
                            trim((string)($_POST['phone'] ?? '')) ?: null,
                            trim((string)($_POST['email'] ?? '')) ?: null,
                        ]);
                    logAudit('supplier_added', 'suppliers', (int)$pdo->lastInsertId(), null, ['name' => $name]);
                    $_SESSION['rd_flash'] = ['type' => 'success', 'notice' => 'Supplier "' . $name . '" added.'];
                } catch (PDOException $e) {
                    $_SESSION['rd_flash'] = ['type' => 'error', 'errors' =>
                        [(int)($e->errorInfo[1] ?? 0) === 1062
                            ? 'A supplier called "' . $name . '" already exists.'
                            : 'Could not add that supplier.']];
                }
            }
            header('Location: receive_delivery.php'); exit;
        }

        if ($action === 'receive') {
            /* The posted items arrive as items[n][...]. Rebuilt into the shape
               inv_receive_delivery() takes, with the serial textarea parsed by
               the same pu_parse_bulk() the Unit Tracking page uses - so "one
               per line, optional IMEI after a comma or tab" means the same
               thing in both places. */
            $lines = [];
            foreach ((array)($_POST['items'] ?? []) as $raw) {
                $pid = (int)($raw['product_id'] ?? 0);
                if ($pid <= 0) { continue; }
                $lines[] = [
                    'product_id' => $pid,
                    'quantity'   => (int)($raw['quantity'] ?? 0),
                    'serials'    => pu_parse_bulk((string)($raw['serials'] ?? '')),
                ];
            }

            $result = inv_receive_delivery(
                $pdo,
                [
                    'supplier_id'   => $_POST['supplier_id'] ?? null,
                    'dr_number'     => (string)($_POST['dr_number'] ?? ''),
                    'delivery_date' => (string)($_POST['delivery_date'] ?? ''),
                    'notes'         => (string)($_POST['notes'] ?? ''),
                ],
                $lines,
                (int)getUserId()
            );

            if ($result['ok']) {
                logAudit('delivery_received', 'stock_deliveries', (int)$result['delivery_id'], null, [
                    'dr_number'   => $_POST['dr_number'] ?? '',
                    'units_added' => $result['units_added'],
                    'qty_added'   => $result['qty_added'],
                ]);
                $_SESSION['rd_flash'] = ['type' => 'success', 'notice' => $result['message']];
            } else {
                // The whole delivery is rejected together - see the note in
                // inv_receive_delivery() - so the form is re-shown with every
                // problem listed rather than a half-saved DR.
                $_SESSION['rd_flash'] = ['type' => 'error', 'errors' => $result['errors']];
            }
            header('Location: receive_delivery.php'); exit;
        }
    } catch (Throwable $e) {
        error_log('receive_delivery: ' . $e->getMessage());
        $_SESSION['rd_flash'] = ['type' => 'error', 'errors' => ['Server error - nothing was saved.']];
        header('Location: receive_delivery.php'); exit;
    }
}

/* ----------------------------------------------------------------- READS --- */
$suppliers = [];
$products  = [];
$recent    = [];
$loadError = null;
try {
    $suppliers = $pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    // Discontinued products are deliberately absent: you do not receive more
    // of a line you are pulling out.
    $products = $pdo->query(
        "SELECT p.id, p.name, p.brand, p.requires_serial, p.stock, c.name AS category_name
           FROM products p LEFT JOIN categories c ON c.id = p.category_id
          WHERE p.status = 'active'
          ORDER BY p.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $recent = $pdo->query(
        "SELECT d.id, d.dr_number, d.delivery_date, d.created_at,
                s.name AS supplier_name,
                TRIM(CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,''))) AS received_by_name,
                (SELECT COALESCE(SUM(di.quantity),0) FROM stock_delivery_items di WHERE di.delivery_id = d.id) AS total_qty,
                (SELECT COUNT(DISTINCT di.product_id) FROM stock_delivery_items di WHERE di.delivery_id = d.id) AS line_count
           FROM stock_deliveries d
      LEFT JOIN suppliers s ON s.id = d.supplier_id
      LEFT JOIN users u     ON u.id = d.received_by
          ORDER BY d.delivery_date DESC, d.id DESC
          LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('receive_delivery read: ' . $e->getMessage());
    $loadError = 'Could not load the delivery form.';
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    html body.admin main.container{max-width:min(1360px,96vw);}
    .rd-msg{border-radius:8px;padding:12px 14px;margin:0 0 18px;font-size:14px;line-height:1.55;}
    .rd-msg--success{background:#e7f6ef;color:#1f7a53;border:1px solid #b6e3ce;}
    .rd-msg--error{background:#fdecf1;color:#a8264d;border:1px solid #f4c4d4;}
    .rd-msg ul{margin:8px 0 0;padding-left:20px;} .rd-msg li{margin:2px 0;}

    .rd-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;}
    @media (max-width:900px){.rd-grid{grid-template-columns:1fr 1fr;}}
    @media (max-width:560px){.rd-grid{grid-template-columns:1fr;}}

    /* One item row. The quantity field and the serial textarea occupy the same
       slot - only one is ever shown, decided by the chosen product. */
    .rd-item{border:1px solid var(--admin-border);border-radius:10px;padding:14px;margin-bottom:12px;
      background:var(--admin-surface);}
    .rd-item-head{display:grid;grid-template-columns:1fr 160px 40px;gap:12px;align-items:end;}
    @media (max-width:720px){.rd-item-head{grid-template-columns:1fr;}}
    .rd-serial-wrap{margin-top:12px;}
    .rd-serial-wrap textarea{width:100%;min-height:92px;font-family:ui-monospace,Consolas,monospace;font-size:13px;}
    .rd-badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;
      background:rgba(128,128,128,.16);color:var(--admin-muted);margin-left:8px;}
    .rd-badge--serial{background:#fff4e0;color:#8a5a00;}
    .rd-drop{background:none;border:0;color:#a8264d;font:inherit;font-size:18px;line-height:1;cursor:pointer;padding:6px;}
    .rd-count{font-size:12px;color:var(--admin-muted);margin-top:6px;}
  </style>

  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/inventory_control.php">Inventory</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Receive Delivery</span>
      </nav>
    </div>
    <div class="adm-actions">
      <a class="ad-btn ad-btn--secondary" href="<?= WEB_ROOT ?>public/authenticated/admin/stock_movements.php">Movement history</a>
    </div>
  </div>

  <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:100%;">
    <h1 style="margin:0 0 6px;color:var(--admin-text);">Receive Delivery</h1>
    <p class="ad-hint" style="margin:0 0 18px;max-width:76ch;">
      Record a supplier delivery. Serialised products need one serial number per unit received &mdash;
      the count of serials is the quantity. Everything received goes straight into sellable stock.
    </p>

    <?php if ($actionsDisabled): ?>
      <div class="rd-msg rd-msg--error">Your admin account is pending Super Admin approval, so deliveries cannot be recorded yet.</div>
    <?php endif; ?>
    <?php if ($loadError): ?>
      <div class="rd-msg rd-msg--error"><?= htmlspecialchars($loadError) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="rd-msg rd-msg--<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>">
        <?php if (!empty($flash['notice'])): ?><?= htmlspecialchars((string)$flash['notice']) ?><?php endif; ?>
        <?php if (!empty($flash['errors'])): ?>
          <strong><?= count($flash['errors']) === 1 ? 'Nothing was saved:' : count($flash['errors']) . ' problems &mdash; nothing was saved:' ?></strong>
          <ul><?php foreach ($flash['errors'] as $e): ?><li><?= htmlspecialchars((string)$e) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- The delivery -->
    <form method="POST" id="rdForm" class="ad-card ad-field" style="margin-bottom:20px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="receive">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Delivery details</h2>
        <span class="ad-card-note">Received by <?= htmlspecialchars(trim((string)($_SESSION['firstname'] ?? '') . ' ' . (string)($_SESSION['lastname'] ?? '')) ?: 'you') ?></span>
      </div>
      <div class="ad-card-body">
        <div class="rd-grid">
          <div>
            <label class="ad-label" for="rd_supplier">Supplier</label>
            <select name="supplier_id" id="rd_supplier">
              <option value="">&mdash; not recorded &mdash;</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars((string)$s['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="ad-hint">Add one below if it is missing.</p>
          </div>
          <div>
            <label class="ad-label" for="rd_dr">DR number *</label>
            <input type="text" name="dr_number" id="rd_dr" maxlength="60" required
                   autocomplete="off" placeholder="e.g. DR-10492">
          </div>
          <div>
            <label class="ad-label" for="rd_date">Delivery date *</label>
            <input type="date" name="delivery_date" id="rd_date" required
                   value="<?= htmlspecialchars(date('Y-m-d')) ?>" max="<?= htmlspecialchars(date('Y-m-d')) ?>">
          </div>
          <div>
            <label class="ad-label" for="rd_notes">Notes</label>
            <input type="text" name="notes" id="rd_notes" maxlength="255" placeholder="optional">
          </div>
        </div>

        <div style="margin-top:20px;display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;">
          <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--admin-text);">Items received</h3>
          <button type="button" class="ad-btn ad-btn--secondary" id="rdAddItem">+ Add item</button>
        </div>

        <div id="rdItems" style="margin-top:12px;"></div>

        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <button type="submit" class="ad-btn ad-btn--primary" <?= $actionsDisabled ? 'disabled' : '' ?>>Record delivery</button>
          <span class="ad-hint" style="margin:0;">A delivery is saved whole &mdash; if any line has a problem, nothing is recorded.</span>
        </div>
      </div>
    </form>

    <!-- Supplier -->
    <form method="POST" class="ad-card ad-field" style="margin-bottom:20px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_supplier">
      <div class="ad-card-head"><h2 class="ad-card-title">Add a supplier</h2></div>
      <div class="ad-card-body">
        <div class="rd-grid">
          <div>
            <label class="ad-label" for="sp_name">Name *</label>
            <input type="text" name="supplier_name" id="sp_name" maxlength="150" placeholder="e.g. Asus Philippines">
          </div>
          <div>
            <label class="ad-label" for="sp_contact">Contact person</label>
            <input type="text" name="contact_person" id="sp_contact" maxlength="150">
          </div>
          <div>
            <label class="ad-label" for="sp_phone">Phone</label>
            <input type="text" name="phone" id="sp_phone" maxlength="40">
          </div>
          <div>
            <label class="ad-label" for="sp_email">Email</label>
            <input type="email" name="email" id="sp_email" maxlength="190">
          </div>
        </div>
        <div style="margin-top:14px;">
          <button type="submit" class="ad-btn ad-btn--secondary" <?= $actionsDisabled ? 'disabled' : '' ?>>Add supplier</button>
        </div>
      </div>
    </form>

    <!-- Recent -->
    <div class="ad-card">
      <div class="ad-card-head">
        <h2 class="ad-card-title">Recent deliveries</h2>
        <span class="ad-card-note">Last <?= count($recent) ?></span>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead><tr><th>Date</th><th>DR number</th><th>Supplier</th><th>Received by</th>
            <th style="text-align:right;">Lines</th><th style="text-align:right;">Units</th></tr></thead>
          <tbody>
            <?php if (!$recent): ?>
              <tr><td colspan="6" class="ad-table-empty">No deliveries recorded yet.</td></tr>
            <?php else: foreach ($recent as $d): ?>
              <tr>
                <td><?= htmlspecialchars(date('j M Y', strtotime((string)$d['delivery_date']))) ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars((string)$d['dr_number']) ?></td>
                <td><?= htmlspecialchars((string)($d['supplier_name'] ?? '—')) ?></td>
                <td><?= htmlspecialchars(trim((string)$d['received_by_name']) ?: '—') ?></td>
                <td style="text-align:right;"><?= (int)$d['line_count'] ?></td>
                <td style="text-align:right;font-weight:700;"><?= number_format((int)$d['total_qty']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
(function () {
  /* Products, with the one fact the row needs: whether it is serialised.
     Inlined rather than fetched - this page already queries them for the
     dropdown, and a second round trip per added row would be slower than the
     whole list is large. */
  var PRODUCTS = <?= json_encode(array_map(static function ($p) {
      return [
          'id'  => (int)$p['id'],
          'n'   => (string)$p['name'],
          'b'   => (string)($p['brand'] ?? ''),
          's'   => (int)$p['requires_serial'] === 1 ? 1 : 0,
          'st'  => (int)$p['stock'],
      ];
  }, $products), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  var items = document.getElementById('rdItems');
  var addBtn = document.getElementById('rdAddItem');
  if (!items || !addBtn) { return; }

  var seq = 0;
  var optionsHtml = '<option value="">— choose a product —</option>' + PRODUCTS.map(function (p) {
    return '<option value="' + p.id + '" data-serial="' + p.s + '">'
         + esc(p.n) + (p.b ? ' (' + esc(p.b) + ')' : '') + '</option>';
  }).join('');

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function addRow() {
    var i = seq++;
    var row = document.createElement('div');
    row.className = 'rd-item';
    row.innerHTML =
      '<div class="rd-item-head">' +
        '<div>' +
          '<label class="ad-label">Product</label>' +
          '<select name="items[' + i + '][product_id]" class="rd-product" required>' + optionsHtml + '</select>' +
          '<div class="rd-kind"></div>' +
        '</div>' +
        '<div class="rd-qty-wrap">' +
          '<label class="ad-label">Quantity</label>' +
          '<input type="number" name="items[' + i + '][quantity]" min="1" step="1" value="1" class="rd-qty">' +
        '</div>' +
        '<div><button type="button" class="rd-drop" title="Remove this item" aria-label="Remove this item">&times;</button></div>' +
      '</div>' +
      '<div class="rd-serial-wrap" hidden>' +
        '<label class="ad-label">Serial numbers &mdash; one per unit, one per line</label>' +
        '<textarea name="items[' + i + '][serials]" spellcheck="false" ' +
          'placeholder="PF3K8L21&#10;PF3K8L22"></textarea>' +
        '<p class="ad-hint">To record an IMEI too, put it after a comma on the same line. ' +
           'The number of lines here is the quantity received.</p>' +
        '<div class="rd-count"></div>' +
      '</div>';
    items.appendChild(row);
    sync(row);
  }

  /* Show the quantity field OR the serial box, never both - the server applies
     the same rule, so the form cannot offer a combination it would reject. */
  function sync(row) {
    var sel = row.querySelector('.rd-product');
    var opt = sel.options[sel.selectedIndex];
    var isSerial = opt && opt.getAttribute('data-serial') === '1';

    row.querySelector('.rd-serial-wrap').hidden = !isSerial;
    row.querySelector('.rd-qty-wrap').hidden = !!isSerial;
    // Disabled, not just hidden: a hidden input still posts, and a quantity
    // arriving alongside serials is exactly the contradiction being avoided.
    row.querySelector('.rd-qty').disabled = !!isSerial;
    row.querySelector('.rd-serial-wrap').querySelector('textarea').disabled = !isSerial;

    var kind = row.querySelector('.rd-kind');
    kind.innerHTML = sel.value
      ? (isSerial ? '<span class="rd-badge rd-badge--serial">Serialised &mdash; needs a serial per unit</span>'
                  : '<span class="rd-badge">Quantity stock</span>')
      : '';
    countSerials(row);
  }

  function countSerials(row) {
    var ta = row.querySelector('textarea');
    var out = row.querySelector('.rd-count');
    if (!ta || !out || row.querySelector('.rd-serial-wrap').hidden) { if (out) out.textContent = ''; return; }
    var n = ta.value.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; }).length;
    out.textContent = n === 0 ? '' : n + ' serial' + (n === 1 ? '' : 's') + ' entered';
  }

  addBtn.addEventListener('click', addRow);
  items.addEventListener('change', function (e) {
    var row = e.target.closest('.rd-item');
    if (row && e.target.classList.contains('rd-product')) { sync(row); }
  });
  items.addEventListener('input', function (e) {
    var row = e.target.closest('.rd-item');
    if (row && e.target.tagName === 'TEXTAREA') { countSerials(row); }
  });
  items.addEventListener('click', function (e) {
    if (!e.target.closest('.rd-drop')) { return; }
    var row = e.target.closest('.rd-item');
    if (row) { row.remove(); }
    if (!items.querySelector('.rd-item')) { addRow(); }
  });

  // Refuse an empty submit in the browser too, so the round trip is saved.
  document.getElementById('rdForm').addEventListener('submit', function (e) {
    var chosen = Array.prototype.filter.call(items.querySelectorAll('.rd-product'), function (s) { return s.value; });
    if (!chosen.length) {
      e.preventDefault();
      if (window.uiAlert) { window.uiAlert('Add at least one item to the delivery.'); }
      else { alert('Add at least one item to the delivery.'); }
    }
  });

  addRow();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
