<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/product_validation.php';
require_role(['admin']);

$pageTitle = 'Edit Product - TechWorld Admin';
$isAdminTheme = true;

$productId = max(0, (int)($_GET['id'] ?? 0));
if ($productId <= 0) { header('Location: ' . WEB_ROOT . 'public/authenticated/admin/products.php'); exit; }

// Field-keyed; '_general' holds what belongs to no single input. Same shape
// as add_product.php so the two forms behave identically.
$errors = [];
$warnings = [];
$success = '';

try { $pdo = getConnection(); } catch (Throwable $e) { $pdo = null; $errors['_general'][] = 'Database connection error.'; }

// Load product
$product = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) { header('Location: ' . WEB_ROOT . 'public/authenticated/admin/products.php'); exit; }
    } catch (Throwable $e) { $errors['_general'][] = 'Failed to load product.'; }
}

// Load categories
$categories = function_exists('getCategories') ? (getCategories() ?: []) : [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!check_csrf(false)) { $errors['_general'][] = 'Invalid security token.'; }

    $name = trim($_POST['name'] ?? ($product['name'] ?? ''));
    $description = trim($_POST['description'] ?? ($product['description'] ?? ''));
    $price = (string)($_POST['price'] ?? ($product['price'] ?? '0'));
    // Raw string, so a rejected value is redisplayed as typed rather than as
    // whatever (int) turned it into.
    $stock = (string)($_POST['stock'] ?? ($product['stock'] ?? 0));
    $categoryId = (int)($_POST['category_id'] ?? ($product['category_id'] ?? 0));

    if ($name === '') { $errors['name'] = 'Enter a product name.'; }
    if (mb_strlen($name) > 255) { $errors['name'] = 'Product name is too long (maximum 255 characters).'; }
    if ($categoryId <= 0) { $errors['category_id'] = 'Choose a category.'; }

    // Dearest product in the catalogue, only used to word the price warning.
    $refMaxPrice = PV_PRICE_REFERENCE;
    try { $refMaxPrice = (float)($pdo->query('SELECT MAX(price) FROM products')->fetchColumn() ?: PV_PRICE_REFERENCE); }
    catch (Throwable $e) { /* keep the default */ }

    // Is this product's stock a mirror of its unit records?
    //
    // When tracking is LIVE, products.stock is maintained by
    // pu_recount_stock() from the serial rows, and a number typed into this
    // form would be overwritten by the next unit change - so the field is
    // read-only and the submitted value is ignored rather than saved and
    // silently reverted. Stock is changed for such a product by adding or
    // removing units on the Unit Tracking page.
    $puLive = $pdo ? pu_tracking_live($pdo, $productId) : false;

    $puChecks = ['price' => pv_price($price, $refMaxPrice)];
    if (!$puLive) { $puChecks['stock'] = pv_stock($stock); }

    $checked = pv_collect($puChecks, $_POST);
    $errors   = array_merge($errors, $checked['errors']);
    $warnings = $checked['warnings'];

    // The new flag. Only the intention is stored here; whether tracking is
    // live still depends on the product having unit rows (see
    // config/product_units.php). Turning it on therefore changes nothing on
    // the storefront until serials are entered.
    $requiresSerial = !empty($_POST['requires_serial']) ? 1 : 0;

    /* Status, including the new Discontinued value.
       Discontinued is what drives the slow-moving flag, and because the
       storefront, the AI recommender and the PC configurator all filter
       `status = 'active'`, choosing it also takes the product out of all three
       at once - see DBSQL/add_inventory_stock_control.sql. */
    $statusIn = (string)($_POST['product_status'] ?? ($product['status'] ?? 'active'));
    if (!in_array($statusIn, ['active', 'inactive', 'discontinued'], true)) { $statusIn = 'active'; }
    /* Stamped the first time the product becomes Discontinued, and cleared if
       it comes back, so the date never describes a status it no longer has.
       Computed here rather than as a SQL CASE expression - one bound value is
       easier to read than a three-placeholder conditional, and it keeps the
       two UPDATE variants below identical in shape. */
    $discontinuedAt = null;
    if ($statusIn === 'discontinued') {
        $discontinuedAt = !empty($product['discontinued_at'])
            ? (string)$product['discontinued_at']
            : date('Y-m-d H:i:s');
    }

    $brandIn = trim((string)($_POST['brand'] ?? ($product['brand'] ?? '')));
    if (mb_strlen($brandIn) > 60) { $errors['brand'] = 'Brand is too long (60 characters maximum).'; }

    // Handle optional new image
    $imageRel = $product['image_url'] ?? null;
    $oldImagePath = null;
    if (!empty($_FILES['image']['name'])) {
        $file = $_FILES['image'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowed[$mime])) {
                $errors['_general'][] = 'Unsupported image type. Use JPG, PNG, or WEBP.';
            } else {
                $ext = $allowed[$mime];
                $targetDir = __DIR__ . '/../../shared/img/';
                if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
                $safeName = 'p_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
                $absTarget = $targetDir . $safeName;
                if (!move_uploaded_file($file['tmp_name'], $absTarget)) {
                    $errors['_general'][] = 'Failed to save uploaded image.';
                } else {
                    // Store old image path for cleanup after successful update
                    if (!empty($product['image_url'])) {
                        $oldImagePath = __DIR__ . '/../../' . $product['image_url'];
                    }
                    $imageRel = 'public/shared/img/' . $safeName;
                }
            }
        } else {
            $errors['_general'][] = 'Image upload error.';
        }
    }

    if (!$errors && $pdo) {
        try {
            // `stock=?` is left out of the statement entirely for a mirrored
            // product rather than written with its current value: the mirror is
            // recomputed by pu_recount_stock() and writing to it from here,
            // even harmlessly, is how the two sources of truth start to drift.
            if ($puLive) {
                $stmt = $pdo->prepare('UPDATE products SET name=?, brand=?, description=?, price=?, category_id=?, image_url=?, requires_serial=?, status=?, discontinued_at=?, updated_at=NOW() WHERE id=?');
                $ok = $stmt->execute([
                    $name,
                    $brandIn !== '' ? $brandIn : null,
                    $description,
                    $checked['values']['price'],
                    $categoryId,
                    $imageRel,
                    $requiresSerial,
                    $statusIn,
                    $discontinuedAt,
                    $productId
                ]);
            } else {
                $stmt = $pdo->prepare('UPDATE products SET name=?, brand=?, description=?, price=?, stock=?, category_id=?, image_url=?, requires_serial=?, status=?, discontinued_at=?, updated_at=NOW() WHERE id=?');
                $ok = $stmt->execute([
                    $name,
                    $brandIn !== '' ? $brandIn : null,
                    $description,
                    // Validated by pv_price()/pv_stock(): normalised and proven to
                    // fit the column, so MySQL cannot silently clamp either one.
                    $checked['values']['price'],
                    $checked['values']['stock'],
                    $categoryId,
                    $imageRel,
                    $requiresSerial,
                    $statusIn,
                    $discontinuedAt,
                    $productId
                ]);
            }
            if ($ok) {
                logAudit('update', 'products', $productId, null, ['name'=>$name,'brand'=>$brandIn,'price'=>$price,'stock'=>$stock,'requires_serial'=>$requiresSerial,'status'=>$statusIn]);
                $success = 'Product updated successfully.';
                
                // Clean up old image file if new image was uploaded
                if ($oldImagePath && file_exists($oldImagePath)) {
                    @unlink($oldImagePath);
                }
                
                // Reload updated product
                $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
            } else {
                $errors['_general'][] = 'Update failed.';
            }
        } catch (Throwable $e) {
            $errors['_general'][] = 'Server error while updating product.';
        }
    }
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
        <span class="adm-crumb-cur" aria-current="page">Edit</span>
      </nav>
    </div>
  </div>
  <div class="admin-dashboard card" style="margin:0 auto;max-width:900px;">
    <h1 class="section-title" style="margin-bottom:12px;">Edit Product</h1>
    <?php
      // Same two helpers add_product.php defines; guarded so whichever page
      // loads first wins and the other reuses them.
      if (!function_exists('pv_field_error')) {
          function pv_field_error(array $errors, string $field): string {
              if (empty($errors[$field]) || is_array($errors[$field])) { return ''; }
              return '<p class="field-error"><i class="fi fi-rr-exclamation" aria-hidden="true"></i>'
                   . htmlspecialchars($errors[$field]) . '</p>';
          }
          function pv_field_warn(array $warnings, string $field, array $post): string {
              if (empty($warnings[$field])) { return ''; }
              $checked = !empty($post['confirm_' . $field]) ? ' checked' : '';
              return '<label class="field-warn"><input type="checkbox" name="confirm_' . $field . '" value="1"' . $checked . '>'
                   . '<span>' . htmlspecialchars($warnings[$field]) . ' <strong>Tick to confirm this is correct.</strong></span></label>';
          }
          function pv_invalid(array $errors, string $field): string {
              return (!empty($errors[$field]) && !is_array($errors[$field])) ? ' is-invalid' : '';
          }
      }
      $generalErrors = $errors['_general'] ?? [];
    ?>
    <?php if ($generalErrors): ?>
      <div class="notification notification-error" style="display:block;margin-bottom:12px;">
        <?= htmlspecialchars(implode(' ', $generalErrors)) ?>
      </div>
    <?php endif; ?>
    <?php if ($errors && !$generalErrors): ?>
      <div class="notification notification-error" style="display:block;margin-bottom:12px;">
        Please check the highlighted fields below.
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div style="background:#102a23;color:#bbf7d0;border:1px solid #065f46;padding:10px;border-radius:8px;margin-bottom:12px;">
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="display:grid;gap:12px;" id="editProductPageForm">
      <?= csrf_field() ?>
      <div class="field">
        <label for="ep_name">Name</label>
        <input class="input<?= pv_invalid($errors, 'name') ?>" type="text" name="name" id="ep_name"
               maxlength="255" value="<?= htmlspecialchars((string)($_POST['name'] ?? $product['name'] ?? '')) ?>" required>
        <?= pv_field_error($errors, 'name') ?>
      </div>
      <div class="field">
        <label>Description</label>
        <textarea class="textarea" name="description" id="ep_description" rows="4"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
      </div>
      <div class="grid-2">
        <div>
          <label for="ep_price">Price</label>
          <input class="input<?= pv_invalid($errors, 'price') ?>" type="number" step="0.01" min="0.01"
                 name="price" id="ep_price" inputmode="decimal"
                 value="<?= htmlspecialchars((string)($_POST['price'] ?? number_format((float)($product['price'] ?? 0), 2, '.', ''))) ?>" required>
          <?= pv_field_error($errors, 'price') ?>
          <?= pv_field_warn($warnings, 'price', $_POST) ?>
        </div>
        <div>
          <label for="ep_stock">Stock</label>
          <?php
            // Re-read rather than reusing $puLive from the POST branch: on a
            // plain GET that variable was never set.
            $epLive = ($pdo && $productId) ? pu_tracking_live($pdo, $productId) : false;
          ?>
          <?php if ($epLive): ?>
            <?php // Read-only, and genuinely disabled rather than just styled:
                  // this product's stock is a count of its serial records, so a
                  // number typed here would be overwritten by the next unit
                  // change. `disabled` keeps it out of the POST entirely. ?>
            <input class="input" type="number" id="ep_stock" disabled
                   value="<?= (int)($product['stock'] ?? 0) ?>">
            <p class="ad-hint">
              Counted from this product's serial numbers &mdash;
              <a href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php?product_id=<?= (int)$productId ?>">add or remove units</a>
              to change it.
            </p>
          <?php else: ?>
            <input class="input<?= pv_invalid($errors, 'stock') ?>" type="number" min="0" step="1"
                   name="stock" id="ep_stock" inputmode="numeric"
                   value="<?= htmlspecialchars((string)($_POST['stock'] ?? (int)($product['stock'] ?? 0))) ?>" required>
            <?= pv_field_error($errors, 'stock') ?>
            <?= pv_field_warn($warnings, 'stock', $_POST) ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid-2">
        <div>
          <label for="ep_brand">Brand</label>
          <?php // Every inventory report groups by brand, so it is a real
                // field rather than something parsed out of the name. ?>
          <input class="input" type="text" name="brand" id="ep_brand" maxlength="60"
                 value="<?= htmlspecialchars((string)($_POST['brand'] ?? ($product['brand'] ?? ''))) ?>"
                 placeholder="e.g. Asus">
        </div>
        <div>
          <label for="ep_status">Status</label>
          <?php $epStatus = (string)($_POST['product_status'] ?? ($product['status'] ?? 'active')); ?>
          <select class="select" name="product_status" id="ep_status">
            <option value="active"       <?= $epStatus === 'active' ? 'selected' : '' ?>>Active &mdash; on sale</option>
            <option value="inactive"     <?= $epStatus === 'inactive' ? 'selected' : '' ?>>Inactive &mdash; hidden</option>
            <option value="discontinued" <?= $epStatus === 'discontinued' ? 'selected' : '' ?>>Discontinued &mdash; for pull-out</option>
          </select>
          <?php /* Worth spelling out: Discontinued is not just a label. The
                   storefront, the AI recommender and the PC configurator all
                   list `status = 'active'` only, so choosing it removes the
                   product from all three at once - and starts the
                   slow-moving clock. */ ?>
          <p class="ad-hint">
            <strong>Discontinued</strong> takes the product off the storefront, out of the AI
            recommendations and out of the PC configurator, and starts the slow-moving
            count towards &ldquo;Poorly Sold &ndash; For Pull-Out&rdquo;.
          </p>
        </div>
      </div>

      <div class="field">
        <?php $epFlag = !empty($_POST) ? !empty($_POST['requires_serial']) : !empty($product['requires_serial']); ?>
        <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer;font-weight:600;">
          <input type="checkbox" name="requires_serial" value="1" <?= $epFlag ? 'checked' : '' ?> style="margin-top:3px;">
          <span>Track this product per unit (serial number required)
            <span style="display:block;font-weight:400;font-size:12px;color:var(--ad-muted);margin-top:2px;">
              For gadgets with a serial number on the box &mdash; laptops, GPUs, motherboards. Leave off for
              accessories like cables and ink. Tracking starts once the product has its first serial;
              stock then becomes the number of units on hand.
            </span>
          </span>
        </label>
      </div>
      <div class="field">
        <label for="ep_category">Category</label>
        <select class="select<?= pv_invalid($errors, 'category_id') ?>" name="category_id" id="ep_category" required>
          <option value="">Select category</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)($product['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= pv_field_error($errors, 'category_id') ?>
      </div>
      <div>
        <label>Image</label>
        <?php if (!empty($product['image_url'])): ?>
          <div style="margin:6px 0;">
            <img src="<?= WEB_ROOT . htmlspecialchars($product['image_url']) ?>" alt="Current" style="width:90px;height:90px;object-fit:cover;border-radius:6px;border:1px solid #1f2937;">
          </div>
        <?php endif; ?>
        <input type="file" name="image" id="ep_image" accept="image/*">
        <small style="color:#94a3b8;display:block;">Optional. JPG, PNG, WEBP. Uploading replaces the current image.</small>
      </div>
      <div>
        <button type="submit" class="btn btn-accent">Save Changes</button>
        <a href="<?= WEB_ROOT ?>public/authenticated/admin/products.php" class="btn btn-muted" style="margin-left:8px;">Back to Products</a>
      </div>
    </form>
  </div>
</main>
<?php
// The receipt shown before the change is written. Unlike Add, this one has a
// previous value for every field, so each row that is actually changing is
// printed as old -> new and tinted - the rows left alone stay quiet.
include_once __DIR__ . '/includes/product_confirm.php';
?>
<script>
(function () {
  var form = document.getElementById('editProductPageForm');
  if (!form) { return; }

  // What the product looked like when the page was rendered. Passed as data
  // rather than re-read from the inputs, which by then hold the new values.
  var was = <?= json_encode([
      'name'     => (string)($product['name'] ?? ''),
      'price'    => number_format((float)($product['price'] ?? 0), 2, '.', ''),
      'stock'    => (int)($product['stock'] ?? 0),
      'category' => (function () use ($categories, $product) {
          foreach ($categories as $c) {
              if ((int)$c['id'] === (int)($product['category_id'] ?? 0)) { return (string)$c['name']; }
          }
          return '';
      })(),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }

  pcGuardForm(form, function () {
    var name  = pcText(val('ep_name'));
    var price = val('ep_price');
    var stock = val('ep_stock');
    var catEl = document.getElementById('ep_category');
    var category = catEl && catEl.selectedIndex > 0 ? catEl.options[catEl.selectedIndex].text : '';
    var fileEl = document.getElementById('ep_image');
    var newImage = fileEl && fileEl.files && fileEl.files[0] ? fileEl.files[0].name : '';

    var rows = [
      { label: 'Name',     value: name,            was: was.name },
      { label: 'Price',    value: pcPeso(price),   was: pcPeso(was.price) },
      { label: 'Stock',    value: pcUnits(stock),  was: pcUnits(was.stock) },
      { label: 'Category', value: category,        was: was.category }
    ];
    // Only worth a row when one is actually being uploaded - "unchanged" is
    // the answer the other 95% of the time and would just be noise.
    if (newImage) {
      rows.push({ label: 'Image', value: newImage, note: 'Replaces the current photo.' });
    }

    var changed = rows.filter(function (r) {
      return !Object.prototype.hasOwnProperty.call(r, 'was') || String(r.was) !== String(r.value);
    }).length;

    return {
      title: changed ? 'Save these changes?' : 'Nothing has changed',
      message: changed
        ? 'Check the highlighted rows - these are the values that will be written.'
        : 'None of the values differ from what is already saved. You can save anyway.',
      okText: changed ? 'Yes, save it' : 'Save anyway',
      rows: rows
    };
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


