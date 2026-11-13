<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../config/ai_config.php';
require_once __DIR__ . '/../../../config/product_validation.php';
require_role(['admin']);

$pageTitle = 'Add Product - TechWorld Admin';
$isAdminTheme = true;

// Load categories for select
$categories = getCategories();

// Field-keyed, so each message can be printed under the input it is about
// rather than joined into one sentence at the top of the card. '_general'
// holds the ones that belong to no single field (CSRF, image, DB failure).
$errors = [];
$warnings = [];
$success = '';
$aiAvailable = ai_is_configured();

// Dearest product in the catalogue, used only to word the "that is Nx your
// most expensive product" warning. Failure here must not stop a product being
// added, so it falls back to the constant.
$refMaxPrice = PV_PRICE_REFERENCE;
try {
    $refMaxPrice = (float)(getConnection()->query('SELECT MAX(price) FROM products')->fetchColumn() ?: PV_PRICE_REFERENCE);
} catch (Throwable $e) { /* keep the default */ }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!check_csrf(false)) { $errors['_general'][] = 'Invalid security token.'; }

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (string)($_POST['price'] ?? '');
    // Kept as the raw string so the field can be redisplayed exactly as typed
    // when it is rejected - re-rendering (int)"12.9" as 12 would show a number
    // the person never entered.
    $stock = (string)($_POST['stock'] ?? '0');
    $categoryId = (int)($_POST['category_id'] ?? 0);

    // Name can be left blank while filling the form - the AI Add Product
    // flow (see the "Analyze with AI" button below) fills it in from the
    // image before this ever submits. It's still required at actual save
    // time, same as every other product - this is never a nullable field.
    if ($name === '') { $errors['name'] = 'Enter a product name, or use "Analyze with AI" above to generate one from the photo.'; }
    if (mb_strlen($name) > 255) { $errors['name'] = 'Product name is too long (maximum 255 characters).'; }
    if ($categoryId <= 0) { $errors['category_id'] = 'Choose a category.'; }

    // Stock defaults to 0 when left empty: a catalogue entry can be created
    // before the stock arrives, and the Restock flow handles the arrival.
    if (trim($stock) === '') { $stock = '0'; }

    // Numbers go through the shared rules. A warning only blocks the save while
    // its confirm box is unticked - see config/product_validation.php.
    $checked = pv_collect([
        'price' => pv_price($price, $refMaxPrice),
        'stock' => pv_stock($stock),
    ], $_POST);
    $errors   = array_merge($errors, $checked['errors']);
    $warnings = $checked['warnings'];

    // Handle image upload (required)
    $storedPath = null;
    if (empty($_FILES['image']['name'])) {
        $errors['_general'][] = 'Product image is required.';
    } else {
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
                    // DB stores relative path from public/shared/
                    $storedPath = 'public/shared/img/' . $safeName;
                }
            }
        } else {
            $errors['_general'][] = 'Image upload error.';
        }
    }

    if (!$errors) {
        try {
            $pdo = getConnection();
            $stmt = $pdo->prepare('INSERT INTO products (name, description, price, stock, category_id, image_url, requires_serial, status) VALUES (?,?,?,?,?,?,?,\'active\')');
            $ok = $stmt->execute([
                $name,
                $description,
                // Already normalised to a 2-decimal string by pv_price(), and
                // proven to fit DECIMAL(10,2) - so MySQL cannot clamp it.
                $checked['values']['price'],
                $checked['values']['stock'],
                $categoryId,
                $storedPath ? ($storedPath) : null,
                // Only the INTENTION to track is stored. A brand-new product
                // has no units, so tracking is not live and the stock figure
                // above keeps working exactly as it does for any other
                // product until serials are entered on the Unit Tracking
                // page. See config/product_units.php.
                !empty($_POST['requires_serial']) ? 1 : 0,
            ]);
            if ($ok) {
                $productId = (int)$pdo->lastInsertId();
                logAudit('create', 'products', $productId, null, ['name'=>$name,'price'=>$price]);

                // Tag it straight away so it can appear in "Similar Products"
                // without waiting for the next backfill. Never fatal: an
                // untagged product still shows up via the recommender's
                // price-nearest fallback.
                try {
                    require_once __DIR__ . '/../../../config/product_tagger.php';
                    $priceMap = pt_category_price_map($pdo);
                    $tags = pt_extract_tags([
                        'name'        => $name,
                        'description' => $description,
                        'price'       => (float)$price,
                        'category_id' => $categoryId,
                    ], $priceMap[(int)$categoryId] ?? []);
                    pt_store_tags($pdo, $productId, $tags);
                } catch (Throwable $e) {
                    error_log('Auto-tagging product ' . $productId . ' failed: ' . $e->getMessage());
                }

                $success = 'Product added successfully.';
                // Reset form
                $name = $description = '';
                $price = ''; $stock = '0'; $categoryId = 0;
                $warnings = [];
            } else { $errors['_general'][] = 'Failed to add product.'; }
        } catch (PDOException $e) {
            error_log('Add product error: ' . $e->getMessage());
            $errors['_general'][] = 'Server error. Try again.';
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
        <span class="adm-crumb-cur" aria-current="page">Add</span>
      </nav>
      <h1 class="adm-title">Add Product</h1>
    </div>
    <?php /* The submit lives outside its form now, so it carries form="..."
             to stay part of it. It has no name and no value, so the POST
             payload is identical either way. */ ?>
    <div class="adm-actions">
      <a href="product_management.php" class="ad-btn ad-btn--secondary">Cancel</a>
      <button type="submit" form="addProductForm" class="ad-btn ad-btn--primary">Add Product</button>
    </div>
  </div>
  <div class="ap-wrap">
    <?php
      /**
       * Errors are keyed by field now, so each one is printed under its own
       * input further down. Only the ones that belong to no single field -
       * CSRF, the image, a failed write - go in this banner. pv_field_error()
       * and pv_field_warn() render the per-field parts.
       */
      if (!function_exists('pv_field_error')) {
          function pv_field_error(array $errors, string $field): string {
              if (empty($errors[$field]) || is_array($errors[$field])) { return ''; }
              return '<p class="field-error"><i class="fi fi-rr-exclamation" aria-hidden="true"></i>'
                   . htmlspecialchars($errors[$field]) . '</p>';
          }
          /** The confirm box for a value that is allowed but looks like a typo. */
          function pv_field_warn(array $warnings, string $field, array $post): string {
              if (empty($warnings[$field])) { return ''; }
              $checked = !empty($post['confirm_' . $field]) ? ' checked' : '';
              return '<label class="field-warn"><input type="checkbox" name="confirm_' . $field . '" value="1"' . $checked . '>'
                   . '<span>' . htmlspecialchars($warnings[$field]) . ' <strong>Tick to confirm this is correct.</strong></span></label>';
          }
          /** Adds the red ring to the input the message belongs to. */
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
    <form method="post" enctype="multipart/form-data" class="ap-grid" id="addProductForm">
      <?= csrf_field() ?>

      <?php /* LEFT - the photo. The file input is unchanged (same name, id,
               accept and required); it is now stretched transparently over the
               drop zone, so the whole zone is the control. opacity:0 rather
               than display:none on purpose: a hidden `required` input cannot
               be focused, and Chrome then refuses to submit the form with
               "an invalid form control is not focusable" and no visible
               reason. This way it still focuses, still validates, still
               takes keyboard. */ ?>
      <div class="ad-card ap-col">
        <div class="ad-card-body">
          <span class="ad-label" id="pf_image_label">Photo <span style="color:var(--ad-primary);">*</span></span>
          <div class="ap-drop">
            <input type="file" name="image" accept="image/*" id="pf_image" required
                   aria-labelledby="pf_image_label">
            <img id="imagePreview" alt="">
            <span class="ap-drop-text">
              <strong>Drop an image or click to upload</strong>
              <small>JPG, PNG or WEBP</small>
            </span>
          </div>

          <div class="ap-photo-actions">
            <button type="button" id="openCameraBtn" class="ad-btn ad-btn--secondary">Take Photo</button>
            <?php /* Still the same wrapper the preview script toggles between
                     display:none and display:flex - the <img> above just moved
                     into the drop zone, and hides itself until the script
                     gives it a src. */ ?>
            <div id="imagePreviewWrap" style="display:none;align-items:center;gap:10px;">
              <?php if ($aiAvailable): ?>
                <button type="button" id="analyzeAiBtn" class="ad-btn ap-btn--ai">Analyze with AI</button>
                <span id="analyzeAiStatus" class="ad-hint" style="margin:0;"></span>
              <?php endif; ?>
            </div>
          </div>
          <p class="ad-hint">Required. JPG, PNG, WEBP, or snap one with your camera.</p>
        </div>
      </div>

      <?php /* RIGHT - the details. Same inputs, same names, same validation
               helpers; only the labels and layout changed. */ ?>
      <div class="ad-card ap-col">
        <div class="ad-card-body ad-field">
          <div class="ap-row">
            <label class="ad-label" for="pf_name">Name</label>
            <input class="input<?= pv_invalid($errors, 'name') ?>" type="text" name="name" id="pf_name"
                   maxlength="255" value="<?= htmlspecialchars($name ?? '') ?>">
            <?= pv_field_error($errors, 'name') ?>
            <?php if ($aiAvailable): ?>
              <p class="ad-hint">Optional &ndash; leave blank and use &ldquo;Analyze with AI&rdquo; to generate it from the photo.</p>
            <?php endif; ?>
          </div>

          <div class="ap-row">
            <label class="ad-label" for="pf_description">Description</label>
            <textarea class="textarea ap-desc" name="description" id="pf_description" rows="4"><?= htmlspecialchars($description ?? '') ?></textarea>
          </div>

          <div class="ap-row ap-row--3">
            <div>
              <label class="ad-label" for="pf_price">Price</label>
              <?php // No `max` attribute: the ceiling is a server rule that explains
                    // itself, not a browser one that silently refuses to increment.
                    // `required` stays - a price is always needed. ?>
              <div class="ap-prefix">
                <span aria-hidden="true">&#8369;</span>
                <input class="input<?= pv_invalid($errors, 'price') ?>" type="number" step="0.01" min="0.01"
                       name="price" id="pf_price" inputmode="decimal"
                       value="<?= htmlspecialchars((string)($price ?? '')) ?>" placeholder="e.g. 1250" required>
              </div>
              <?= pv_field_error($errors, 'price') ?>
              <?= pv_field_warn($warnings, 'price', $_POST) ?>
            </div>
            <div>
              <label class="ad-label" for="pf_stock">Stock <span style="color:var(--ad-muted);font-weight:400;">(optional)</span></label>
              <?php // Optional, defaulting to 0: a catalogue entry can exist before
                    // its stock arrives, and Restock handles the arrival. ?>
              <div class="ap-prefix ap-prefix--suffix">
                <input class="input<?= pv_invalid($errors, 'stock') ?>" type="number" min="0" step="1"
                       name="stock" id="pf_stock" inputmode="numeric"
                       value="<?= htmlspecialchars((string)($stock ?? '0')) ?>" placeholder="0">
                <span aria-hidden="true">units</span>
              </div>
              <?= pv_field_error($errors, 'stock') ?>
              <?= pv_field_warn($warnings, 'stock', $_POST) ?>
            </div>
            <div>
              <label class="ad-label" for="pf_category">Category</label>
              <select class="select<?= pv_invalid($errors, 'category_id') ?>" name="category_id" id="pf_category" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= (!empty($categoryId) && (int)$categoryId === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?= pv_field_error($errors, 'category_id') ?>
            </div>
          </div>
          <p class="ad-hint">Leave stock at 0 if it has not arrived yet &mdash; add it later with Restock.</p>

          <div class="ap-row">
            <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer;">
              <input type="checkbox" name="requires_serial" value="1" id="pf_requires_serial"
                     <?= !empty($_POST['requires_serial']) ? 'checked' : '' ?> style="margin-top:3px;">
              <span class="ad-label" style="margin:0;font-weight:700;">Track this product per unit (serial number required)
                <span style="display:block;font-weight:400;font-size:12px;color:var(--ad-muted);margin-top:2px;">
                  For gadgets with a serial number on the box &mdash; laptops, GPUs, motherboards, processors.
                  Leave off for accessories like cables and ink. After saving, enter the serials on the
                  Unit Tracking page; stock then becomes the number of units on hand.
                </span>
              </span>
            </label>
          </div>
        </div>
      </div>
    </form>
  </div>
</main>

<!-- Camera capture modal - same overlay/card pattern as the other admin
     modals (see categories.php's Tag modal). Captures a still frame from
     the device camera and feeds it into the existing #pf_image file input
     via a DataTransfer, so every downstream step (preview, Analyze with
     AI, form submit) is the exact same code path as picking a file. -->
<div id="cameraModal" style="display:none;position:fixed;inset:0;background:rgba(15,45,77,0.72);z-index:1000;">
  <div style="max-width:520px;margin:60px auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;">
    <div style="padding:20px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 style="margin:0;color:var(--admin-text);">📷 Take a Photo</h3>
      <button type="button" onclick="closeCameraModal()" style="background:transparent;color:var(--admin-muted);border:0;font-size:24px;cursor:pointer;">×</button>
    </div>
    <div style="padding:20px;">
      <div style="position:relative;background:#000;border-radius:8px;overflow:hidden;">
        <video id="cameraVideo" autoplay playsinline style="width:100%;display:block;"></video>
        <canvas id="cameraCanvas" style="display:none;"></canvas>
      </div>
      <p id="cameraError" style="color:#fca5a5;font-size:13px;margin:10px 0 0;display:none;"></p>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
        <button type="button" onclick="closeCameraModal()" class="btn btn-secondary">Cancel</button>
        <button type="button" id="captureBtn" class="btn btn-primary">Capture</button>
      </div>
    </div>
  </div>
</div>


<!-- Client-side background removal (@imgly/background-removal) - runs
     fully in the admin's browser (WASM), no server/API cost. Chosen over
     Gemini's image-editing models because those require a paid tier this
     project's API key doesn't have. Loaded as an ES module and bridged to
     the classic script below via window.__removeBackground; if it fails
     to load (offline, CDN issue, unsupported browser) the rest of the
     page degrades to using the original photo as-is - it's never a hard
     dependency for adding a product. -->
<script type="module">
  import { removeBackground } from 'https://esm.sh/@imgly/background-removal';
  window.__removeBackground = removeBackground;
</script>

<script>
(function(){
  var modal = document.getElementById('cameraModal');
  var video = document.getElementById('cameraVideo');
  var canvas = document.getElementById('cameraCanvas');
  var errorEl = document.getElementById('cameraError');
  var stream = null;

  document.getElementById('openCameraBtn').addEventListener('click', function(){
    errorEl.style.display = 'none';
    modal.style.display = 'block';

    // Checked BEFORE the call, not caught after it: where the API is missing
    // (any plain-http origin that is not localhost) reading
    // navigator.mediaDevices.getUserMedia throws a TypeError synchronously, so
    // the .catch() below never ran - this opened a black modal that said
    // nothing at all.
    var camIssue = window.TWCamera ? window.TWCamera.problem()
                 : ((!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)
                    ? { message: 'Camera capture is not available on this connection.' } : null);
    if (camIssue) {
      if (window.TWCamera) { window.TWCamera.render(errorEl); }
      else { errorEl.textContent = camIssue.message; }
      errorEl.style.display = 'block';
      return;
    }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      .then(function(s){ stream = s; video.srcObject = stream; })
      .catch(function(err){
        errorEl.textContent = "Could not access the camera (" + ((err && (err.message || err.name)) || 'permission denied') +
                              ") - check your browser's camera permission for this site.";
        errorEl.style.display = 'block';
      });
  });

  window.closeCameraModal = function(){
    if (stream) { stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
    modal.style.display = 'none';
  };

  document.getElementById('captureBtn').addEventListener('click', function(){
    if (!video.videoWidth) { return; }
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    canvas.toBlob(function(blob){
      if (!blob) { return; }
      var file = new File([blob], 'camera_' + Date.now() + '.jpg', { type: 'image/jpeg' });
      var dt = new DataTransfer();
      dt.items.add(file);
      var imageInput = document.getElementById('pf_image');
      imageInput.files = dt.files;
      imageInput.dispatchEvent(new Event('change'));
      closeCameraModal();
    }, 'image/jpeg', 0.92);
  });
})();
</script>

<script>
// Shared by both image sources (file picker and camera capture above) -
// whichever photo comes in, its background is replaced with a plain white
// one before it ever reaches the preview, the AI analyzer, or the saved
// product - "before it post in the customer/guest side" per the request
// that added this. Falls back to the original photo untouched if removal
// isn't available (library failed to load) or errors on this particular
// image, so a slow/unsupported browser never blocks adding a product.
(function(){
  var imageInput = document.getElementById('pf_image');
  var previewWrap = document.getElementById('imagePreviewWrap');
  var previewImg = document.getElementById('imagePreview');
  var analyzeStatus = document.getElementById('analyzeAiStatus');

  function setStatus(text) {
    if (analyzeStatus) { analyzeStatus.textContent = text; }
  }

  function replaceInputFile(file) {
    var dt = new DataTransfer();
    dt.items.add(file);
    imageInput.files = dt.files;
  }

  imageInput.addEventListener('change', function(){
    var file = imageInput.files && imageInput.files[0];
    if (!file) { previewWrap.style.display = 'none'; return; }

    previewImg.src = URL.createObjectURL(file);
    previewWrap.style.display = 'flex';

    if (typeof window.__removeBackground !== 'function') { return; }

    setStatus('Removing background…');
    window.__removeBackground(file)
      .then(function(blob){
        var cleaned = new File([blob], file.name.replace(/\.[^.]+$/, '') + '.png', { type: 'image/png' });
        replaceInputFile(cleaned);
        previewImg.src = URL.createObjectURL(cleaned);
        setStatus('');
      })
      .catch(function(err){
        console.warn('Background removal skipped:', err);
        setStatus('');
      });
  });
})();
</script>

<?php if ($aiAvailable): ?>
<!-- AI Add Product review modal - same overlay/card pattern as the Tag
     Management modal in categories.php, reused here rather than a new
     modal component. Two views inside one container: "review" (AI's
     result, read-only, with Confirm/Edit) and "edit" (the same three
     fields made editable, pre-filled with the AI's values). Nothing here
     saves a product - both paths just fill in the real form fields above
     and let the existing, unchanged submit button do the actual save. -->
<div id="aiResultModal" style="display:none;position:fixed;inset:0;background:rgba(15,45,77,0.72);z-index:1000;">
  <div style="max-width:560px;margin:60px auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;">
    <div style="padding:20px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 style="margin:0;color:var(--admin-text);">✨ AI Product Suggestion</h3>
      <button type="button" onclick="closeAiModal()" style="background:transparent;color:var(--admin-muted);border:0;font-size:24px;cursor:pointer;">×</button>
    </div>

    <!-- Review view -->
    <div id="aiReviewView" style="padding:20px;">
      <p style="color:var(--admin-muted);margin:0 0 16px;font-size:13px;">Review what the AI identified from the photo before it's used. Nothing is saved yet.</p>
      <div style="display:grid;gap:10px;margin-bottom:20px;">
        <div><strong style="color:var(--admin-text);">Name:</strong> <span id="aiReviewName" style="color:var(--admin-muted);"></span></div>
        <div><strong style="color:var(--admin-text);">Category:</strong> <span id="aiReviewCategory" style="color:var(--admin-muted);"></span></div>
        <div><strong style="color:var(--admin-text);">Description:</strong> <div id="aiReviewDescription" style="color:var(--admin-muted);margin-top:4px;"></div></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
        <button type="button" onclick="showAiEditView()" class="btn btn-secondary">✎ Correct / Edit Result</button>
        <button type="button" onclick="confirmAiResult()" class="btn btn-primary">✓ Confirm / Use AI Result</button>
      </div>
    </div>

    <!-- Edit view -->
    <div id="aiEditView" style="display:none;padding:20px;">
      <p style="color:var(--admin-muted);margin:0 0 16px;font-size:13px;">Correct anything the AI got wrong, then continue.</p>
      <div style="display:grid;gap:12px;margin-bottom:20px;">
        <div class="field">
          <label>Name</label>
          <input class="input" type="text" id="aiEditName">
        </div>
        <div class="field">
          <label>Description</label>
          <textarea class="textarea" id="aiEditDescription" rows="4"></textarea>
        </div>
        <div class="field">
          <label>Category</label>
          <select class="select" id="aiEditCategory">
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="showAiReviewView()" class="btn btn-secondary">Back</button>
        <button type="button" onclick="confirmAiEdit()" class="btn btn-primary">Save &amp; Continue</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var aiResult = null; // {name, description, category_id, category_name}

  // Image preview + background removal are wired once, shared with the
  // camera-capture flow, in the always-present script above (they don't
  // depend on AI being configured) - this block only adds the Analyze
  // button on top of that.
  var imageInput = document.getElementById('pf_image');
  var analyzeBtn = document.getElementById('analyzeAiBtn');
  var analyzeStatus = document.getElementById('analyzeAiStatus');

  if (analyzeBtn) {
    analyzeBtn.addEventListener('click', function(){
      var file = imageInput.files && imageInput.files[0];
      if (!file) { showNotification('Choose a product image first.', 'error'); return; }

      var fd = new FormData();
      fd.append('image', file);
      fd.append('name', document.getElementById('pf_name').value.trim());

      analyzeBtn.disabled = true;
      analyzeStatus.textContent = 'Analyzing photo… this can take up to a minute.';

      fetch('<?= WEB_ROOT ?>public/authenticated/api/ai_add_product.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data){
          analyzeBtn.disabled = false;
          analyzeStatus.textContent = '';
          if (!data.success) {
            showNotification(data.message || 'AI analysis failed. Please fill in the details manually.', 'error');
            return;
          }
          aiResult = data;
          document.getElementById('aiReviewName').textContent = data.name || '(none suggested)';
          document.getElementById('aiReviewCategory').textContent = data.category_name || '(no confident match)';
          document.getElementById('aiReviewDescription').textContent = data.description || '';
          showAiReviewView();
          document.getElementById('aiResultModal').style.display = 'block';
        })
        .catch(function(){
          analyzeBtn.disabled = false;
          analyzeStatus.textContent = '';
          showNotification('Could not reach the AI service. Please fill in the details manually.', 'error');
        });
    });
  }

  window.showAiReviewView = function(){
    document.getElementById('aiReviewView').style.display = 'block';
    document.getElementById('aiEditView').style.display = 'none';
  };

  window.showAiEditView = function(){
    document.getElementById('aiEditName').value = aiResult.name || '';
    document.getElementById('aiEditDescription').value = aiResult.description || '';
    if (aiResult.category_id) { document.getElementById('aiEditCategory').value = aiResult.category_id; }
    document.getElementById('aiReviewView').style.display = 'none';
    document.getElementById('aiEditView').style.display = 'block';
  };

  window.closeAiModal = function(){
    document.getElementById('aiResultModal').style.display = 'none';
  };

  // Applies {name, description, category_id} into the real form fields -
  // both "Confirm" and "Save & Continue" funnel through this, the only
  // difference being which values (AI's as-is, or the admin's edits) they
  // pass in. The actual Add Product submit button is untouched below this.
  function applyToForm(name, description, categoryId) {
    document.getElementById('pf_name').value = name || '';
    document.getElementById('pf_description').value = description || '';
    if (categoryId) { document.getElementById('pf_category').value = categoryId; }
    closeAiModal();
    showNotification('AI suggestion applied - review the form and click Add Product when ready.', 'success');
  }

  window.confirmAiResult = function(){
    applyToForm(aiResult.name, aiResult.description, aiResult.category_id);
  };

  window.confirmAiEdit = function(){
    applyToForm(
      document.getElementById('aiEditName').value.trim(),
      document.getElementById('aiEditDescription').value.trim(),
      document.getElementById('aiEditCategory').value
    );
  };
})();
</script>
<?php endif; ?>

<?php
// The receipt shown before a product is written to the catalogue. See the
// include for why validation alone is not enough to catch a mistyped price.
include_once __DIR__ . '/includes/product_confirm.php';
?>
<script>
(function () {
  var form = document.getElementById('addProductForm');
  if (!form) { return; }

  function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }

  pcGuardForm(form, function () {
    var name  = pcText(val('pf_name'));
    var price = val('pf_price');
    var stock = (val('pf_stock') || '0').trim();
    var catEl = document.getElementById('pf_category');
    var category = catEl && catEl.selectedIndex > 0 ? catEl.options[catEl.selectedIndex].text : '';
    var fileEl = document.getElementById('pf_image');
    var image  = fileEl && fileEl.files && fileEl.files[0] ? fileEl.files[0].name : '';

    return {
      title: 'Add this product?',
      message: 'It goes into the catalogue and is visible to customers straight away.',
      okText: 'Yes, add it',
      rows: [
        { label: 'Name',     value: name },
        { label: 'Price',    value: pcPeso(price) },
        // The one field that is legitimately often zero, so it says so in
        // words rather than showing a bare "0 units" that reads like a slip.
        { label: 'Stock',    value: pcUnits(stock),
          note: parseInt(stock, 10) === 0 ? 'Not in stock yet - add it later with Restock.' : '' },
        { label: 'Category', value: category },
        { label: 'Image',    value: image, emptyText: 'No file chosen' }
      ]
    };
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


