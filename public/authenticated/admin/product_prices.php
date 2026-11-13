<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/product_validation.php';
// Serialised products: their stock is a count of unit records, not a typed
// number, so the three stock actions below refuse to write it.
require_once __DIR__ . '/../../../config/product_units.php';

require_role(['admin']);

$pageTitle = 'Product Information Management - TechWorld';
$activeNav = '';
$isAdminTheme = true;

// Handle AJAX price update
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    if (!check_csrf(false)) { echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']); exit; }
    
    $action = (string)($_POST['action'] ?? 'update_price');
    $productId = (int)($_POST['product_id'] ?? 0);
    if (!in_array($action, ['get_product']) && $productId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid product']); exit; }

    try {
        $pdo = getConnection();
        if ($action === 'get_product') {
            $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.id=? LIMIT 1');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }
            echo json_encode(['success'=>true,'product'=>$product]); exit;
        }

        if ($action === 'update_product') {
            $name = trim((string)($_POST['name'] ?? ''));
            $price = (string)($_POST['price'] ?? '');
            $stock = (string)($_POST['stock'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            // Was: is_numeric() only - which let a negative price through, and
            // let "12.9" become 12 via the cast below. Both go through the
            // shared rules now.
            if ($name === '' || $categoryId <= 0) {
                echo json_encode(['success'=>false,'message'=>'Enter a name and choose a category.']); exit;
            }
            $pc = pv_price($price);
            $sc = pv_stock($stock);
            if (!empty($pc['error'])) { echo json_encode(['success'=>false,'message'=>$pc['error']]); exit; }
            if (!empty($sc['error'])) { echo json_encode(['success'=>false,'message'=>$sc['error']]); exit; }
            if ((!empty($pc['warning']) && empty($_POST['confirm_price']))
                || (!empty($sc['warning']) && empty($_POST['confirm_stock']))) {
                echo json_encode(['success'=>false,'needs_confirm'=>true,
                    'message'=>($pc['warning'] ?? '') . ' ' . ($sc['warning'] ?? '')]); exit;
            }
            $price = $pc['value'];
            $stock = $sc['value'];
            // Name, price and category stay editable for a serialised product;
            // only its stock is off limits, because that number is a count of
            // its unit records. Refused rather than quietly dropped, so nobody
            // believes they changed a figure that did not move.
            $ppLive = pu_tracking_live($pdo, $productId);
            if ($ppLive) {
                $curStmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
                $curStmt->execute([$productId]);
                $curStock = (int)$curStmt->fetchColumn();
                if ((int)$stock !== $curStock) {
                    echo json_encode(['success'=>false,'message'=>'This product is tracked by serial number - its stock (' . $curStock . ') is the number of units on hand. Add or remove units on the Unit Tracking page to change it.']); exit;
                }
            }
            $beforeStmt = $pdo->prepare('SELECT name, price, stock, category_id FROM products WHERE id = ?');
            $beforeStmt->execute([$productId]);
            $before = $beforeStmt->fetch();
            $imageRel = null;
            if (isset($_FILES['image']) && !empty($_FILES['image']['name'])) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success'=>false,'message'=>'Upload error']); exit; }
                $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
                finfo_close($finfo);
                if (!isset($allowed[$mime])) { echo json_encode(['success'=>false,'message'=>'Unsupported image type']); exit; }
                $ext = $allowed[$mime];
                $targetDir = __DIR__ . '/../../shared/img/products/';
                if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
                $safeName = 'p_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
                $absTarget = $targetDir . $safeName;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $absTarget)) { echo json_encode(['success'=>false,'message'=>'Failed to save image']); exit; }
                $imageRel = 'public/shared/img/products/' . $safeName;
            }
            $fields = 'name=?, price=?, stock=?, category_id=?, updated_at=NOW()';
            $params = [$name, number_format((float)$price,2,'.',''), max(0,(int)$stock), $categoryId, $productId];
            if ($imageRel) { $fields = 'name=?, price=?, stock=?, category_id=?, image_url=?, updated_at=NOW()'; $params = [$name, number_format((float)$price,2,'.',''), max(0,(int)$stock), $categoryId, $imageRel, $productId]; }
            $stmt = $pdo->prepare("UPDATE products SET $fields WHERE id=?");
            $ok = $stmt->execute($params);
            if ($ok) {
                logAudit('update', 'products', $productId, $before ?: null, [
                    'name' => $name, 'price' => $price, 'stock' => $stock, 'category_id' => $categoryId
                ]);
                // "Set exact" moves stock without an order behind it, same as a
                // restock, so it belongs in the movement log too. The direction
                // matters: reports_analytics.php sums 'in' as +qty and 'out' as
                // -qty, so a correction downward must be recorded as 'out' or
                // the net movement figure climbs when stock actually fell.
                $beforeStock = (int)($before['stock'] ?? 0);
                $delta = (int)$stock - $beforeStock;
                if ($delta !== 0) {
                    addStockMovement(
                        $productId,
                        $delta > 0 ? 'in' : 'out',
                        abs($delta),
                        'manual',
                        null,
                        'Stock set to ' . (int)$stock . ' by ' . trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''))
                    );
                }
            }
            echo json_encode(['success'=>(bool)$ok, 'message'=>$ok?'Product updated':'Update failed', 'imageUrl'=>$imageRel ? (WEB_ROOT.$imageRel) : null]); exit;
        }

        if ($action === 'update_price') {
            $newPrice = (string)($_POST['price'] ?? '');
            // Was: is_numeric() only, so a negative price saved cleanly.
            $pc = pv_price($newPrice);
            if (!empty($pc['error'])) { echo json_encode(['success' => false, 'message' => $pc['error']]); exit; }
            if (!empty($pc['warning']) && empty($_POST['confirm_price'])) {
                echo json_encode(['success'=>false,'needs_confirm'=>true,'message'=>$pc['warning']]); exit;
            }
            $newPrice = $pc['value'];
            $stmt = $pdo->prepare('SELECT id, name, price FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }
            $stmt = $pdo->prepare('UPDATE products SET price = ?, updated_at = NOW() WHERE id = ?');
            $ok = $stmt->execute([number_format((float)$newPrice, 2, '.', ''), $productId]);
            if ($ok) {
                logAudit('update_price', 'products', $productId, ['price' => $product['price']], ['price' => $newPrice]);
                echo json_encode(['success' => true, 'message' => 'Price updated', 'price' => formatCurrency((float)$newPrice)]); exit;
            }
            echo json_encode(['success' => false, 'message' => 'Update failed']); exit;
        }

        /**
         * Restock - add units rather than retype a total.
         *
         * The admin sends only the quantity received. Two reasons this is a
         * separate action rather than a variant of update_stock:
         *
         *  - No arithmetic for the person. Typing "the new total" means reading
         *    the current figure, adding, and typing the sum; every step is a
         *    chance to fumble a digit.
         *  - No lost updates. `stock = stock + ?` is resolved by the database,
         *    so two people restocking the same product at once both count.
         *    Reading a value and writing back a total silently discards one of
         *    them.
         */
        if ($action === 'restock') {
            // A mirrored product's stock is a count of its serial records, so a
            // figure typed here would be overwritten by the next unit change.
            // Refused with the one instruction that actually works rather than
            // accepted and silently reverted. config/product_units.php.
            if (pu_tracking_live($pdo, $productId)) {
                echo json_encode(['success'=>false,'message'=>'This product is tracked by serial number - its stock is the number of units on hand. Add or remove units on the Unit Tracking page instead.']); exit;
            }
            $stmt = $pdo->prepare('SELECT stock, name FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $row = $stmt->fetch();
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }
            $before = (int)$row['stock'];

            $check = pv_restock_qty($_POST['qty'] ?? '', $before);
            if (!empty($check['error'])) {
                echo json_encode(['success'=>false,'message'=>$check['error']]); exit;
            }
            // A quantity that is merely unusual still needs a yes. The browser
            // asks first; this is what makes the answer binding.
            if (!empty($check['warning']) && empty($_POST['confirm_qty'])) {
                echo json_encode(['success'=>false,'needs_confirm'=>true,'message'=>$check['warning']]); exit;
            }
            $qty = (int)$check['value'];

            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare('UPDATE products SET stock = stock + ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$qty, $productId]);
                // Read back inside the transaction so the figure returned is the
                // one this update produced.
                $re = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
                $re->execute([$productId]);
                $after = (int)$re->fetchColumn();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Restock failed for product ' . $productId . ': ' . $e->getMessage());
                echo json_encode(['success'=>false,'message'=>'Could not save the restock. Try again.']); exit;
            }

            logAudit('restock', 'products', $productId, ['stock'=>$before], ['stock'=>$after, 'added'=>$qty]);
            // Feeds inventory_control.php's adjustments panel and the net
            // movement figures on reports_analytics.php - both of which had
            // nothing to show because no admin path ever wrote here.
            addStockMovement($productId, 'in', $qty, 'manual', null,
                'Restocked by ' . trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')));

            echo json_encode([
                'success' => true,
                'message' => 'Added ' . number_format($qty) . ' - stock is now ' . number_format($after) . '.',
                'stock'   => $after,
                'before'  => $before,
                'added'   => $qty,
            ]); exit;
        }
        if ($action === 'update_stock') {
            // A mirrored product's stock is a count of its serial records, so a
            // figure typed here would be overwritten by the next unit change.
            // Refused with the one instruction that actually works rather than
            // accepted and silently reverted. config/product_units.php.
            if (pu_tracking_live($pdo, $productId)) {
                echo json_encode(['success'=>false,'message'=>'This product is tracked by serial number - its stock is the number of units on hand. Add or remove units on the Unit Tracking page instead.']); exit;
            }
            $newStock = (string)($_POST['stock'] ?? '');
            $sc = pv_stock($newStock);
            if (!empty($sc['error'])) { echo json_encode(['success' => false, 'message' => $sc['error']]); exit; }
            if (!empty($sc['warning']) && empty($_POST['confirm_stock'])) {
                echo json_encode(['success'=>false,'needs_confirm'=>true,'message'=>$sc['warning']]); exit;
            }
            $newStock = (string)$sc['value'];
            $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $oldStock = $stmt->fetchColumn();
            $stmt = $pdo->prepare('UPDATE products SET stock = ?, updated_at = NOW() WHERE id = ?');
            $ok = $stmt->execute([max(0,(int)$newStock), $productId]);
            if ($ok) {
                logAudit('update_stock', 'products', $productId, ['stock' => $oldStock], ['stock' => (int)$newStock]);
            }
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Stock updated' : 'Update failed', 'stock' => (int)$newStock]); exit;
        }

        if ($action === 'update_image') {
            if (!isset($_FILES['image']) || empty($_FILES['image']['name'])) { echo json_encode(['success' => false, 'message' => 'No image uploaded']); exit; }
            $file = $_FILES['image'];
            if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success' => false, 'message' => 'Upload error']); exit; }
            $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowed[$mime])) { echo json_encode(['success' => false, 'message' => 'Unsupported image type']); exit; }
            $ext = $allowed[$mime];
            $targetDir = __DIR__ . '/../../shared/img/products/';
            if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
            $safeName = 'p_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
            $absTarget = $targetDir . $safeName;
            if (!move_uploaded_file($file['tmp_name'], $absTarget)) { echo json_encode(['success' => false, 'message' => 'Failed to save image']); exit; }
            $rel = 'public/shared/img/products/' . $safeName;
            $stmt = $pdo->prepare('UPDATE products SET image_url = ?, updated_at = NOW() WHERE id = ?');
            $ok = $stmt->execute([$rel, $productId]);
            if ($ok) {
                logAudit('update_image', 'products', $productId, null, ['image_url' => $rel]);
            }
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Image updated' : 'Update failed', 'imageUrl' => WEB_ROOT . $rel]); exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action']); exit;
    } catch (Throwable $e) {
        error_log('Product ajax error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']); exit;
    }
}

// Pagination & search
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; $offset = ($page - 1) * $limit;
$search = trim((string)($_GET['q'] ?? ''));

try {
    $pdo = getConnection();
    $where = 'WHERE 1=1';
    $params = [];
    if ($search !== '') { $where .= ' AND (p.name LIKE ? OR c.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON p.category_id = c.id $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $limit));

    $sql = "SELECT p.id, p.name, p.price, p.stock, p.status, c.name AS category_name
            FROM products p LEFT JOIN categories c ON p.category_id = c.id
            $where ORDER BY p.id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Load products error: ' . $e->getMessage());
    $products = []; $totalPages = 1; $total = 0; $page = 1;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
    <div class="adm-head">
      <div>
        <nav class="adm-crumb" aria-label="Breadcrumb">
          <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='product_management.php'; }">Products</a>
          <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
          <span class="adm-crumb-cur" aria-current="page">Prices</span>
        </nav>
      </div>
    </div>
    <div class="admin-dashboard" style="background:transparent;border:none;box-shadow:none;color:inherit;margin:0 auto;padding:0;max-width:1200px;">
        <h1 style="margin:0 0 15px;color:var(--admin-text);">Product Information Management</h1>
        <form method="GET" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;">
            <input type="text" name="q" placeholder="Search by product or category" class="admin-form-input" style="flex:1;">
            <button type="submit" class="action-btn admin-quick-link" style="border:none;cursor:pointer;">Search</button>
        </form>

        <div class="admin-table-wrap table-responsive" style="overflow:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$products): ?>
                        <tr><td colspan="6" class="admin-table-empty">No products found.</td></tr>
                    <?php else: foreach ($products as $p): ?>
                        <tr class="editable-row" data-id="<?= (int)$p['id'] ?>">
                            <td><?= (int)$p['id'] ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
                            <td><span class="admin-badge admin-badge--pending"><?= htmlspecialchars($p['category_name'] ?? '—') ?></span></td>
                            <td>
                                <?= formatCurrency((float)$p['price']) ?>
                            </td>
                            <td>
                                <?= (int)$p['stock'] ?>
                            </td>
                            <td><span class="admin-badge <?= $p['status'] === 'active' ? 'admin-badge--active' : 'admin-badge--danger' ?>"><?= htmlspecialchars($p['status']) ?></span></td>

                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination" style="display:flex;gap:8px;justify-content:center;margin-top:15px;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>" style="padding:8px 12px;border-radius:var(--radius-sm);text-decoration:none;<?= $i === $page ? 'background:var(--admin-primary-2);color:#f2f3f4;box-shadow:var(--neu-pressed);' : 'background:var(--admin-surface);color:var(--admin-text);box-shadow:var(--neu-raised-sm);' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
<form id="csrfForm" style="display:none;" method="post">
    <?= csrf_field() ?>
</form>
<?php
// The receipt shown before any price or stock figure is written. See the
// include for why the validation rules alone cannot catch a plain typo.
include_once __DIR__ . '/includes/product_confirm.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  // Double-click to open modal
  document.querySelectorAll('tr.editable-row').forEach(function(row){
    row.addEventListener('dblclick', function(){ openEditorModal(this.getAttribute('data-id')); });
    row.addEventListener('click', function(){ openEditorModal(this.getAttribute('data-id')); });
    row.style.cursor = 'pointer';
  });

  function openEditorModal(productId){
    if (!productId) return;
    const fd = new FormData(); fd.append('csrf_token', getCsrf()); fd.append('action','get_product'); fd.append('product_id', productId);
    fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
      .then(r=>r.json()).then(d=>{
        if(!d.success || !d.product){ alert(d.message||'Failed to load product'); return; }
        fillAndShowModal(d.product);
      }).catch(()=>alert('Network error'));
  }

  function fillAndShowModal(p){
    const m = document.getElementById('editProductModal');
    if(!m) return;
    m.querySelector('input[name="product_id"]').value = p.id || '';
    m.querySelector('input[name="name"]').value = p.name || '';
    m.querySelector('input[name="price"]').value = (p.price||0);
    m.querySelector('input[name="stock"]').value = (p.stock||0);
    // Stock on hand when the modal opened - the base the "4 -> 29" line adds to,
    // and what the warning thresholds are measured against.
    m.dataset.currentStock = (p.stock||0);
    // The product as it stands right now, kept so the confirmation can print
    // each field as old -> new. Read from here rather than from the inputs,
    // which by save time hold the new values.
    m.dataset.was = JSON.stringify({
      name: p.name || '',
      price: p.price || 0,
      stock: parseInt(p.stock || 0, 10) || 0,
      category_id: String(p.category_id || '')
    });
    m.querySelector('input[name="qty"]').value = '';
    m.querySelector('input[name="stock_mode"][value="add"]').checked = true;
    syncStockMode();
    m.querySelector('select[name="category_id"]').value = (p.category_id||'');
    const img = m.querySelector('.modal-image');
    if (img) { img.src = (p.image_url ? (window.WEB_ROOT || '') + p.image_url : ''); img.style.display = p.image_url ? 'block' : 'none'; }
    m.style.display = 'block';
  }

  document.getElementById('modalCloseBtn')?.addEventListener('click', ()=>{ document.getElementById('editProductModal').style.display='none'; });
  document.getElementById('editProductForm')?.addEventListener('submit', function(e){
    e.preventDefault();
    const m = document.getElementById('editProductModal');
    const fd = new FormData(this);
    fd.append('csrf_token', getCsrf());

    // Adding stock is its own action: the server does `stock = stock + ?`, so
    // the quantity must not be folded into an absolute update_product write.
    const adding = stockMode() === 'add';
    if (adding) {
      fd.delete('stock');
      fd.append('action','restock');
    } else {
      fd.delete('qty');
      fd.append('action','update_product');
    }

    const send = (extra) => {
      const body = fd;
      if (extra) { body.append(extra, '1'); }
      return fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body })
        .then(r=>r.json());
    };

    const post = () => send(null).then(d=>{
      if (d.success) { location.reload(); return; }
      // A value that is allowed but unusual: a second, different question -
      // the receipt said what would be saved, this says why it looks odd.
      // The server refuses again without the confirmation, so declining here
      // genuinely cancels the save.
      if (d.needs_confirm) {
        uiConfirm((d.message || 'That value looks unusual.').trim() + '\n\nSave it anyway?', {
          kind: 'danger', okText: 'Save it anyway'
        }).then(yes=>{
          if (!yes) { return; }
          send(adding ? 'confirm_qty' : 'confirm_price').then(d2=>{
            if (d2.success) { location.reload(); }
            else { uiAlert(d2.message || 'Update failed'); }
          }).catch(()=>uiAlert('Network error'));
        });
        return;
      }
      uiAlert(d.message || 'Update failed');
    }).catch(()=>uiAlert('Network error'));

    // The receipt, before anything is sent. Every value is read here at submit
    // time so it reflects what is in the boxes, not what was loaded into them.
    const was = (() => { try { return JSON.parse(m.dataset.was || '{}'); } catch (err) { return {}; } })();
    const f = this;
    const nameNow = pcText(f.querySelector('input[name="name"]').value);
    const priceNow = f.querySelector('input[name="price"]').value;
    const catSel = f.querySelector('select[name="category_id"]');
    const catNow = catSel && catSel.selectedIndex >= 0 ? catSel.options[catSel.selectedIndex].text : '';
    const catWas = (() => {
      if (!catSel) { return ''; }
      const o = catSel.querySelector('option[value="' + (was.category_id || '') + '"]');
      return o ? o.text : '';
    })();
    const fileEl = f.querySelector('input[type=file][name="image"]');
    const newImage = fileEl && fileEl.files && fileEl.files[0] ? fileEl.files[0].name : '';

    const rows = [
      { label: 'Product', value: nameNow, was: was.name },
      { label: 'Price',   value: pcPeso(priceNow), was: pcPeso(was.price) }
    ];

    // Stock reads differently per mode, and conflating the two is exactly the
    // mistake worth catching: "25" means +25 in one and "there are 25" in the
    // other. Both are spelled out, with the total they land on.
    const current = parseInt(was.stock || 0, 10) || 0;
    if (adding) {
      const qty = parseInt(f.querySelector('input[name="qty"]').value, 10) || 0;
      rows.push({
        label: 'Stock',
        value: 'Add ' + pcUnits(qty),
        note: pcUnits(current) + ' on hand, ' + pcUnits(current + qty) + ' after this.'
      });
    } else {
      const exact = parseInt(f.querySelector('input[name="stock"]').value, 10) || 0;
      rows.push({
        label: 'Stock',
        value: pcUnits(exact),
        was: pcUnits(current),
        note: exact === current ? '' : 'Set to an exact figure, not added to the current one.'
      });
    }

    rows.push({ label: 'Category', value: catNow, was: catWas });
    if (newImage) { rows.push({ label: 'Image', value: newImage, note: 'Replaces the current photo.' }); }

    pcReview({
      title: 'Save these changes?',
      message: 'Check the highlighted rows - these are the values that will be written.',
      rows: rows
    }).then(ok => { if (ok) { post(); } });
  });
  function getCsrf(){ return document.querySelector('#csrfForm input[name=csrf_token]')?.value || ''; }

  /* --- Stock: add-a-quantity vs set-a-total --------------------------------
     Only one of the two inputs is in the DOM's "active" mode at a time, and
     the submit handler below reads the mode to decide which action to post.
     The running total is the whole point of the default mode: the admin types
     what arrived and sees the result without doing the sum. */
  function stockMode(){
    const el = document.querySelector('input[name="stock_mode"]:checked');
    return el ? el.value : 'add';
  }
  function syncStockMode(){
    const add = stockMode() === 'add';
    const addWrap = document.getElementById('stockAddWrap');
    const setWrap = document.getElementById('stockSetWrap');
    if (addWrap) addWrap.style.display = add ? '' : 'none';
    if (setWrap) setWrap.style.display = add ? 'none' : '';
    // Only the visible input may be required, or the browser refuses to submit
    // a form whose blocking field is hidden - with no visible explanation.
    const qty = document.querySelector('input[name="qty"]');
    const stk = document.querySelector('input[name="stock"]');
    if (qty) qty.required = add;
    if (stk) stk.required = !add;
    updateStockPreview();
  }
  function updateStockPreview(){
    const out = document.getElementById('stockPreview');
    const m = document.getElementById('editProductModal');
    if (!out || !m) return;
    const current = parseInt(m.dataset.currentStock || '0', 10) || 0;
    const raw = (document.querySelector('input[name="qty"]')?.value || '').trim();
    if (raw === '') { out.textContent = 'In stock: ' + current.toLocaleString(); out.classList.remove('is-warn'); return; }
    const qty = parseInt(raw, 10);
    if (!Number.isFinite(qty) || qty < 1) { out.textContent = 'Enter a whole number of units.'; out.classList.add('is-warn'); return; }
    out.innerHTML = 'In stock: <b>' + current.toLocaleString() + '</b> &rarr; <b>' + (current + qty).toLocaleString() + '</b>';
    // Mirrors pv_restock_qty()'s thresholds so the browser can say the same
    // thing sooner. The server still decides - see the needs_confirm branch.
    const unusual = (current > 0 && qty > current * 20 && qty > 100) || qty > 10000;
    out.classList.toggle('is-warn', unusual);
  }
  document.addEventListener('change', function(e){
    if (e.target && e.target.name === 'stock_mode') { syncStockMode(); }
  });
  document.addEventListener('input', function(e){
    if (e.target && e.target.name === 'qty') { updateStockPreview(); }
  });
  function rowEls(id){
    return {
      display: document.querySelector('.display-price[data-id="'+id+'"]'),
      input: document.querySelector('.edit-price[data-id="'+id+'"]'),
      edit: document.querySelector('.edit-btn[data-id="'+id+'"]'),
      save: document.querySelector('.save-btn[data-id="'+id+'"]'),
      cancel: document.querySelector('.cancel-btn[data-id="'+id+'"]'),
      stockInput: document.querySelector('.edit-stock[data-id="'+id+'"]'),
      saveStock: document.querySelector('.save-stock-btn[data-id="'+id+'"]'),
      imageForm: document.querySelector('.image-form[data-id="'+id+'"]'),
      uploadBtn: document.querySelector('.upload-image-btn')
    };
  }
  function toEdit(id){ const els = rowEls(id); if(!els.display) return; els.display.style.display='none'; els.input.style.display='inline-block'; els.edit.style.display='none'; els.save.style.display='inline-block'; els.cancel.style.display='inline-block'; }
  function toView(id){ const els = rowEls(id); if(!els.display) return; els.display.style.display='inline'; els.input.style.display='none'; els.edit.style.display='inline-block'; els.save.style.display='none'; els.cancel.style.display='none'; }

  document.querySelectorAll('.edit-btn').forEach(btn=>btn.addEventListener('click', ()=>toEdit(btn.dataset.id)));
  document.querySelectorAll('.cancel-btn').forEach(btn=>btn.addEventListener('click', ()=>toView(btn.dataset.id)));
  document.querySelectorAll('.save-btn').forEach(btn=>btn.addEventListener('click', ()=>{
    const id = btn.dataset.id; const els = rowEls(id); const value = els.input.value;
    const fd = new FormData(); fd.append('csrf_token', getCsrf()); fd.append('action','update_price'); fd.append('product_id', id); fd.append('price', value);
    const original = btn.textContent; btn.disabled = true; btn.textContent = 'Saving...';
    fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(r=>r.json()).then(d=>{
        if(d.success){ els.display.textContent = d.price; toView(id); }
        else { alert(d.message || 'Failed to update price'); }
      })
      .catch(()=>alert('Network error'))
      .finally(()=>{ btn.disabled = false; btn.textContent = original; });
  }));

  // Stock editing
  document.querySelectorAll('.save-stock-btn').forEach(btn=>btn.addEventListener('click', ()=>{
    const id = btn.dataset.id; const els = rowEls(id); if(!els || !els.stockInput) return; const value = els.stockInput.value;
    const fd = new FormData(); fd.append('csrf_token', getCsrf()); fd.append('action','update_stock'); fd.append('product_id', id); fd.append('stock', value);
    const original = btn.textContent; btn.disabled = true; btn.textContent = 'Saving...';
    fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      .then(r=>r.json()).then(d=>{ if(!d.success){ alert(d.message||'Failed to update stock'); } else { location.reload(); } })
      .catch(()=>alert('Network error'))
      .finally(()=>{ btn.disabled=false; btn.textContent = original; });
  }));

  // Image upload
  document.querySelectorAll('.image-form').forEach(form=>{
    const id = form.getAttribute('data-id');
    const input = form.querySelector('input[type=file]');
    const btn = form.querySelector('.upload-image-btn');
    if (btn) btn.addEventListener('click', ()=> input && input.click());
    if (input) input.addEventListener('change', ()=>{
      if (!input.files || !input.files[0]) return;
      const fd = new FormData();
      fd.append('csrf_token', getCsrf());
      fd.append('action','update_image');
      fd.append('product_id', id);
      fd.append('image', input.files[0]);
      btn.disabled = true; const original = btn.textContent; btn.textContent = 'Uploading...';
      fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r=>r.json()).then(d=>{ if (!d.success){ alert(d.message||'Failed to update image'); } else { location.reload(); } })
        .catch(()=>alert('Network error'))
        .finally(()=>{ btn.disabled=false; btn.textContent = original; input.value=''; });
    });
  });
});
</script>
<style>
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(15,45,77,.6);backdrop-filter:blur(3px)}
.modal .content{background:var(--admin-surface-2);color:var(--admin-text);width:95%;max-width:620px;margin:5% auto;border:none;border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden}
.modal .header{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--admin-border)}
.modal .header h3{color:var(--admin-text)}
.modal .body{padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal .footer{padding:12px 16px;border-top:1px solid var(--admin-border);text-align:right}
.modal .field{display:flex;flex-direction:column;gap:6px;color:var(--admin-muted);font-size:12px;font-weight:600}
.modal input,.modal select{background:var(--admin-surface);color:var(--admin-text);border:none;box-shadow:var(--neu-pressed);border-radius:var(--radius-sm);padding:9px}
.modal .actions{display:flex;gap:8px;justify-content:flex-end}
.modal .primary{background:var(--admin-primary-2);color:#f2f3f4;border:none;border-radius:var(--radius-sm);padding:10px 14px;box-shadow:var(--neu-raised-sm);cursor:pointer}
.modal .secondary{background:var(--admin-surface);color:var(--admin-text);border:none;border-radius:var(--radius-sm);padding:10px 14px;box-shadow:var(--neu-raised-sm);cursor:pointer}
.modal .preview{display:flex;flex-direction:column;gap:8px}
.modal .preview img{width:120px;height:120px;object-fit:cover;border-radius:var(--radius-sm);box-shadow:var(--neu-raised-sm)}

/* Stock mode switch. Two plain radios rather than a dropdown: there are only
   two choices and both stay visible, so the one in effect is never a guess. */
.modal .stock-modes{display:flex;gap:14px;margin-bottom:6px}
.modal .stock-mode{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;
  color:var(--admin-muted);cursor:pointer;text-transform:none}
.modal .stock-mode input{box-shadow:none;padding:0;margin:0;accent-color:var(--admin-primary-2)}
/* The running total. Neutral until a quantity is typed, so it reads as a
   result rather than as an instruction. */
.modal .stock-preview{margin:6px 0 0;font-size:12px;font-weight:600;color:var(--admin-muted);min-height:1em}
.modal .stock-preview b{color:var(--admin-text)}
.modal .stock-preview.is-warn{color:#b45309}
</style>

<div class="modal" id="editProductModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="content">
    <div class="header">
      <h3 id="modalTitle" style="margin:0;">Edit Product</h3>
      <button type="button" id="modalCloseBtn" class="secondary" aria-label="Close">Close</button>
    </div>
    <form id="editProductForm" enctype="multipart/form-data">
      <input type="hidden" name="product_id" value="">
      <div class="body">
        <div class="field" style="grid-column:1/-1;">
          <label>Name</label>
          <input type="text" name="name" required>
        </div>
        <div class="field">
          <label for="mp_price">Price</label>
          <?php // min is 0.01: a product for sale at zero is a mistake, and the
                // server says so too. No max - the ceiling is a server rule that
                // explains itself rather than an input that refuses to type. ?>
          <input type="number" id="mp_price" name="price" step="0.01" min="0.01" inputmode="decimal" required>
        </div>

        <?php /* Stock has two modes. "Add stock" is the default because it is
                 what happens most - a delivery arrives and the count goes up -
                 and because it removes the arithmetic: the admin types only
                 what arrived, and the line underneath shows the result. "Set
                 exact" is kept for stocktake corrections, where the true figure
                 is known and the difference is not. */ ?>
        <div class="field">
          <label>Stock</label>
          <div class="stock-modes" role="group" aria-label="How to change stock">
            <label class="stock-mode">
              <input type="radio" name="stock_mode" value="add" checked> Add stock
            </label>
            <label class="stock-mode">
              <input type="radio" name="stock_mode" value="set"> Set exact
            </label>
          </div>

          <div id="stockAddWrap">
            <input type="number" id="mp_qty" name="qty" min="1" step="1" inputmode="numeric" placeholder="Units received">
            <p class="stock-preview" id="stockPreview"></p>
          </div>

          <div id="stockSetWrap" style="display:none;">
            <input type="number" id="mp_stock" name="stock" min="0" step="1" inputmode="numeric">
            <p class="stock-preview">The exact number of units on hand.</p>
          </div>
        </div>
        <div class="field" style="grid-column:1/-1;">
          <label>Category</label>
          <select name="category_id" required>
            <?php
              try {
                $cats = getCategories();
                foreach ($cats as $c) {
                  echo '<option value="'.(int)$c['id'].'">'.htmlspecialchars($c['name'])."</option>";
                }
              } catch (Throwable $e) {}
            ?>
          </select>
        </div>
        <div class="field preview">
          <label>Current Image</label>
          <img class="modal-image" src="" alt="Preview" style="display:none;">
        </div>
        <div class="field">
          <label>Upload New Image</label>
          <input type="file" name="image" accept="image/*">
        </div>
      </div>
      <div class="footer actions">
        <button type="button" class="secondary" id="modalCancelBtn">Cancel</button>
        <button type="submit" class="primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('modalCancelBtn')?.addEventListener('click', ()=>{ document.getElementById('editProductModal').style.display='none'; });
// Close modal on outside click
document.getElementById('editProductModal')?.addEventListener('click', function(e){ if(e.target === this) this.style.display='none'; });
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>


