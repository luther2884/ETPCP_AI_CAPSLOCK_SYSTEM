<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_role(['admin']);
require_once __DIR__ . '/../../shared/classes/PromotionalImages.php';

$pageTitle = 'Promotional Carousel - TechWorld Admin';
$isAdminTheme = true;
$activeNav = 'promo_carousel';

$promotionalImages = new PromotionalImages();
$promotionalImages->createTable();

// The homepage carousel is capped at this many live images at once - keeps
// it from getting sluggish/cluttered. Archive one to make room for another.
const PROMO_CAROUSEL_LIMIT = 10;

$errors = [];
$success = '';

// Quick AJAX actions: archive / restore / reorder - no full page reload.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!check_csrf(false)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    try {
        if ($_POST['ajax'] === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            $existing = $promotionalImages->getImageById($id);
            if (!$existing) { echo json_encode(['success' => false, 'message' => 'Image not found.']); exit; }
            // Soft-delete: hide it from the carousel and main list, but keep
            // the row and file so it can be restored later.
            $promotionalImages->archiveImage($id);
            logAudit('update', 'promotional_images', $id, ['is_archived' => false], ['is_archived' => true, 'title' => $existing['title']]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($_POST['ajax'] === 'restore') {
            $id = (int)($_POST['id'] ?? 0);
            $existing = $promotionalImages->getImageById($id);
            if (!$existing) { echo json_encode(['success' => false, 'message' => 'Image not found.']); exit; }
            $promotionalImages->restoreImage($id);
            logAudit('update', 'promotional_images', $id, ['is_archived' => true], ['is_archived' => false, 'title' => $existing['title']]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($_POST['ajax'] === 'reorder') {
            $id = (int)($_POST['id'] ?? 0);
            $direction = $_POST['direction'] ?? '';
            $all = $promotionalImages->getAllImages();
            $ids = array_column($all, 'id');
            $pos = array_search($id, $ids, true);
            if ($pos === false) { echo json_encode(['success' => false, 'message' => 'Image not found.']); exit; }
            $swapWith = $direction === 'up' ? $pos - 1 : $pos + 1;
            if ($swapWith < 0 || $swapWith >= count($all)) { echo json_encode(['success' => true]); exit; } // already at an edge, no-op
            $a = $all[$pos]; $b = $all[$swapWith];
            $promotionalImages->updateImage((int)$a['id'], $a['title'], $a['description'], $a['image_path'], $a['alt_text'], (int)$b['display_order'], (bool)$a['is_active']);
            $promotionalImages->updateImage((int)$b['id'], $b['title'], $b['description'], $b['image_path'], $b['alt_text'], (int)$a['display_order'], (bool)$b['is_active']);
            echo json_encode(['success' => true]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    } catch (Exception $e) {
        error_log('Promotional carousel AJAX error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error.']);
        exit;
    }
}

// Add / edit an image (multipart form, full page submit so the file upload
// works without extra JS plumbing).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['ajax'])) {
    if (!check_csrf(false)) { $errors[] = 'Invalid security token.'; }

    $editId = (int)($_POST['edit_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $altText = trim($_POST['alt_text'] ?? '');
    $displayOrder = (int)($_POST['display_order'] ?? 0);
    // Every non-archived image is live in the carousel - there's no manual
    // active/inactive toggle any more, only archive/restore.
    $isActive = true;

    if ($title === '') { $errors[] = 'Title is required.'; }

    $existing = $editId > 0 ? $promotionalImages->getImageById($editId) : null;
    if ($editId > 0 && !$existing) { $errors[] = 'Image not found.'; }

    // The 5-image cap only applies to adding a new one - editing an
    // existing live image doesn't change how many are live.
    if (!$existing && count($promotionalImages->getAllImages()) >= PROMO_CAROUSEL_LIMIT) {
        $errors[] = "The carousel is limited to " . PROMO_CAROUSEL_LIMIT . " images. Archive one before adding another.";
    }

    // Image upload is required when adding; optional when editing (keeps
    // the current file if nothing new is chosen).
    $storedPath = $existing['image_path'] ?? null;
    if (!empty($_FILES['image']['name'])) {
        $file = $_FILES['image'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!isset($allowed[$mime])) {
                $errors[] = 'Unsupported image type. Use JPG, PNG, or WEBP.';
            } else {
                $ext = $allowed[$mime];
                $targetDir = __DIR__ . '/../../shared/img/promotional/';
                if (!is_dir($targetDir)) { @mkdir($targetDir, 0775, true); }
                $safeName = 'promo_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
                $absTarget = $targetDir . $safeName;
                if (!move_uploaded_file($file['tmp_name'], $absTarget)) {
                    $errors[] = 'Failed to save uploaded image.';
                } else {
                    $storedPath = 'public/shared/img/promotional/' . $safeName;
                }
            }
        } else {
            $errors[] = 'Image upload error.';
        }
    } elseif (!$existing) {
        $errors[] = 'Please choose an image to upload.';
    }

    if (!$errors) {
        try {
            if ($existing) {
                $old = $existing;
                $promotionalImages->updateImage($editId, $title, $description, $storedPath, $altText, $displayOrder, $isActive);
                logAudit('update', 'promotional_images', $editId, ['title' => $old['title']], ['title' => $title]);
                $success = 'Promotional image updated.';
            } else {
                $promotionalImages->addImage($title, $description, $storedPath, $altText, $displayOrder, $isActive);
                logAudit('create', 'promotional_images', null, null, ['title' => $title]);
                $success = 'Promotional image added.';
            }
        } catch (Exception $e) {
            error_log('Promotional carousel save error: ' . $e->getMessage());
            $errors[] = 'Server error. Try again.';
        }
    }
}

$images = [];
$archivedImages = [];
try {
    $images = $promotionalImages->getAllImages();
    $archivedImages = $promotionalImages->getArchivedImages();
} catch (Exception $e) {
    error_log('Promotional carousel list error: ' . $e->getMessage());
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <style>
    .promo-thumb{width:96px;height:60px;object-fit:cover;border-radius:8px;border:1px solid #374151;background:#111827}
    .promo-order-btns{display:flex;flex-direction:column;gap:2px}
    .promo-order-btns button{background:transparent;border:1px solid #374151;color:#cbd5e1;width:22px;height:20px;border-radius:4px;cursor:pointer;font-size:11px;line-height:1;display:flex;align-items:center;justify-content:center}
    .promo-order-btns button:hover{border-color:#174871;color:#fff}
    .promo-order-btns button:disabled{opacity:.3;cursor:not-allowed}
    .tw-archived-btn{display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 16px;border-radius:12px;background:linear-gradient(180deg,#111827 0%,#0b1220 100%);border:1px solid #243047;box-shadow:0 8px 22px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.04);transition:all .18s ease;color:#e5e7eb;text-decoration:none;font-size:14px;font-weight:600;cursor:pointer}
    .tw-archived-btn:hover{border-color:#174871;box-shadow:0 10px 26px rgba(23,72,113,.25),inset 0 1px 0 rgba(255,255,255,.06);transform:translateY(-1px)}
    .tw-archived-btn:active{transform:translateY(0)}
    .tw-archived-count{background:#374151;color:#e5e7eb;border-radius:999px;padding:1px 8px;font-size:12px;font-weight:700;min-width:20px;text-align:center}
  </style>
  <div style="margin:0 auto;max-width:1200px;padding:0 0 12px 0;display:flex;align-items:center;gap:12px;">
        <div class="adm-head">
      <div>
        <nav class="adm-crumb" aria-label="Breadcrumb">
          <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='dashboard.php'; }">Dashboard</a>
          <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
          <span class="adm-crumb-cur" aria-current="page">Promotional Carousel</span>
        </nav>
      </div>
    </div>
    <button type="button" class="tw-archived-btn" onclick="openArchivedModal()">
      <i class="fi fi-bs-archive"></i> Archived
      <span class="tw-archived-count" id="archivedCount"><?= count($archivedImages) ?></span>
    </button>
  </div>
  <div class="admin-dashboard card" style="margin:0 auto;max-width:1200px;">
    <h1 class="section-title">Promotional Carousel</h1>
    <p style="color:var(--admin-muted);margin:-10px 0 20px;">
      Manage the images shown in the homepage promo carousel (the pop-up customers see once per login, and guests see once per visit). Add a new image whenever you have a sales promotion to post, edit one, or archive it once it's out of date - archived images are hidden from the carousel but can be restored later from the Archived button above.
    </p>
    <?php if ($errors): ?>
      <div style="background:#3f1d1d;color:#fecaca;border:1px solid #7f1d1d;padding:10px;border-radius:8px;margin-bottom:12px;"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div style="background:#102a23;color:#bbf7d0;border:1px solid #065f46;padding:10px;border-radius:8px;margin-bottom:12px;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Add New Image -->
    <div style="background:var(--admin-surface);box-shadow:var(--neu-pressed);border-radius:var(--radius-md);padding:20px;margin-bottom:20px;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px;">
        <h3 style="margin:0;color:var(--admin-text);">Add New Promotional Image</h3>
        <span id="carouselLimitNote" style="font-size:13px;color:var(--admin-muted);"></span>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <fieldset id="addImageFieldset" style="border:0;margin:0;padding:0;display:grid;gap:12px;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="field">
              <label>Title</label>
              <input class="input" type="text" name="title" placeholder="e.g. August GPU Sale" required>
            </div>
            <div class="field">
              <label>Alt Text (for accessibility)</label>
              <input class="input" type="text" name="alt_text" placeholder="Short description of the image">
            </div>
          </div>
          <div class="field">
            <label>Description</label>
            <textarea class="textarea" name="description" rows="2" placeholder="Optional notes about this promo"></textarea>
          </div>
          <div style="display:grid;grid-template-columns:1fr 160px;gap:12px;align-items:end;">
            <div class="field">
              <label>Image (JPG, PNG, or WEBP)</label>
              <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <div class="field">
              <label>Display Order</label>
              <input class="input" type="number" name="display_order" value="<?= (int)(count($images) + 1) ?>" min="0">
            </div>
          </div>
          <div>
            <button type="submit" class="btn btn-accent" id="addImageBtn">Add Image</button>
          </div>
        </fieldset>
      </form>
    </div>

    <!-- Images Table -->
    <div class="table-responsive" style="overflow:auto;">
      <table class="table">
        <thead>
          <tr>
            <th>Order</th>
            <th>Preview</th>
            <th>Title</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="liveTbody">
          <?php foreach ($images as $i => $img): ?>
          <tr id="promoRow<?= (int)$img['id'] ?>" data-img='<?= json_encode($img, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
            <td style="padding:10px;border-bottom:1px solid var(--admin-border);">
              <div class="promo-order-btns">
                <button type="button" title="Move up" onclick="reorderImage(<?= (int)$img['id'] ?>, 'up', this)" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
                <button type="button" title="Move down" onclick="reorderImage(<?= (int)$img['id'] ?>, 'down', this)" <?= $i === count($images) - 1 ? 'disabled' : '' ?>>&darr;</button>
              </div>
            </td>
            <td style="padding:10px;border-bottom:1px solid var(--admin-border);">
              <img class="promo-thumb" src="<?= WEB_ROOT . htmlspecialchars($img['image_path']) ?>" alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>">
            </td>
            <td style="padding:10px;border-bottom:1px solid var(--admin-border);color:var(--admin-text);">
              <div style="font-weight:600;"><?= htmlspecialchars($img['title']) ?></div>
              <?php if (!empty($img['description'])): ?>
                <div style="color:var(--admin-muted);font-size:13px;max-width:420px;"><?= htmlspecialchars($img['description']) ?></div>
              <?php endif; ?>
            </td>
            <td style="padding:10px;border-bottom:1px solid var(--admin-border);white-space:nowrap;">
              <button class="btn btn-sm btn-secondary" style="padding:4px 10px;font-size:12px;" onclick='openEditModal(<?= json_encode($img, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
              <button class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:12px;" onclick="archiveImage(<?= (int)$img['id'] ?>, this)">Archive</button>
            </td>
          </tr>
          <?php endforeach; ?>
          <tr id="liveEmptyRow" style="<?= empty($images) ? '' : 'display:none;' ?>">
            <td colspan="4" style="padding:20px;text-align:center;color:var(--admin-muted);">No promotional images yet. Add one above.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- Archived Modal -->
<div id="archivedModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;">
  <div style="max-width:800px;margin:60px auto;background:#1f2937;border:1px solid #374151;border-radius:12px;overflow:hidden;max-height:calc(100vh - 120px);display:flex;flex-direction:column;">
    <div style="padding:20px;border-bottom:1px solid #374151;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
      <h3 style="margin:0;color:#e5e7eb;">Archived Images</h3>
      <button type="button" onclick="closeArchivedModal()" style="background:transparent;color:#9ca3af;border:0;font-size:24px;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:20px;overflow:auto;">
      <div class="table-responsive" style="overflow:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>Preview</th>
              <th>Title</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="archivedTbody">
            <?php foreach ($archivedImages as $img): ?>
            <tr id="archivedRow<?= (int)$img['id'] ?>" data-img='<?= json_encode($img, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
              <td style="padding:10px;border-bottom:1px solid #1f2937;">
                <img class="promo-thumb" style="opacity:.6;" src="<?= WEB_ROOT . htmlspecialchars($img['image_path']) ?>" alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>">
              </td>
              <td style="padding:10px;border-bottom:1px solid #1f2937;color:#9ca3af;">
                <?= htmlspecialchars($img['title']) ?>
              </td>
              <td style="padding:10px;border-bottom:1px solid #1f2937;white-space:nowrap;">
                <button class="btn btn-sm btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="restoreImage(<?= (int)$img['id'] ?>, this)">Restore</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p id="archivedEmptyNote" style="color:#94a3b8;text-align:center;padding:20px 0 0;<?= empty($archivedImages) ? '' : 'display:none;' ?>">No archived images.</p>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;">
  <div style="max-width:600px;margin:60px auto;background:#1f2937;border:1px solid #374151;border-radius:12px;overflow:hidden;">
    <div style="padding:20px;border-bottom:1px solid #374151;display:flex;justify-content:space-between;align-items:center;">
      <h3 style="margin:0;color:#e5e7eb;">Edit Promotional Image</h3>
      <button type="button" onclick="closeEditModal()" style="background:transparent;color:#9ca3af;border:0;font-size:24px;cursor:pointer;">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data" style="padding:20px;display:grid;gap:12px;">
      <?= csrf_field() ?>
      <input type="hidden" name="edit_id" id="editId">
      <img id="editPreview" src="" alt="" style="width:100%;max-height:180px;object-fit:cover;border-radius:8px;border:1px solid #374151;">
      <div class="field">
        <label>Title</label>
        <input class="input" type="text" name="title" id="editTitle" required>
      </div>
      <div class="field">
        <label>Alt Text</label>
        <input class="input" type="text" name="alt_text" id="editAltText">
      </div>
      <div class="field">
        <label>Description</label>
        <textarea class="textarea" name="description" id="editDescription" rows="2"></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 160px;gap:12px;align-items:end;">
        <div class="field">
          <label>Replace Image (optional)</label>
          <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="field">
          <label>Display Order</label>
          <input class="input" type="number" name="display_order" id="editDisplayOrder" min="0">
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
const WEB_ROOT = '<?= WEB_ROOT ?>';
const CSRF_TOKEN = '<?= htmlspecialchars(csrf_token()) ?>';
const CAROUSEL_LIMIT = <?= (int)PROMO_CAROUSEL_LIMIT ?>;

function openArchivedModal() {
  document.getElementById('archivedModal').style.display = 'block';
}
function closeArchivedModal() {
  document.getElementById('archivedModal').style.display = 'none';
}
document.getElementById('archivedModal').addEventListener('click', function(e) {
  if (e.target === this) { closeArchivedModal(); }
});

// Reflects the live/archived DOM counts into the "Archived" button badge,
// the empty-state note inside that modal, and the add-form's limit notice
// (disabling it once the carousel is full).
function updateCountsUI() {
  const liveCount = document.querySelectorAll('#liveTbody tr[data-img]').length;
  const archivedCount = document.querySelectorAll('#archivedTbody tr[data-img]').length;

  document.getElementById('archivedCount').textContent = archivedCount;
  document.getElementById('archivedEmptyNote').style.display = archivedCount === 0 ? '' : 'none';

  const note = document.getElementById('carouselLimitNote');
  const fieldset = document.getElementById('addImageFieldset');
  const atLimit = liveCount >= CAROUSEL_LIMIT;
  note.textContent = atLimit
    ? `${liveCount}/${CAROUSEL_LIMIT} used - archive one to add another`
    : `${liveCount}/${CAROUSEL_LIMIT} used`;
  note.style.color = atLimit ? 'var(--admin-text)' : 'var(--admin-muted)';
  note.style.fontWeight = atLimit ? '700' : '400';
  fieldset.disabled = atLimit;
  fieldset.style.opacity = atLimit ? '.5' : '1';
}

function openEditModalById(id) {
  const row = document.getElementById('promoRow' + id);
  if (!row) return;
  openEditModal(JSON.parse(row.dataset.img));
}

function openEditModal(img) {
  document.getElementById('editId').value = img.id;
  document.getElementById('editTitle').value = img.title || '';
  document.getElementById('editAltText').value = img.alt_text || '';
  document.getElementById('editDescription').value = img.description || '';
  document.getElementById('editDisplayOrder').value = img.display_order || 0;
  document.getElementById('editPreview').src = WEB_ROOT + img.image_path;
  document.getElementById('editModal').style.display = 'block';
}
function closeEditModal() {
  document.getElementById('editModal').style.display = 'none';
}
document.getElementById('editModal').addEventListener('click', function(e) {
  if (e.target === this) { closeEditModal(); }
});

function ajaxAction(payload, onDone) {
  const formData = new FormData();
  formData.append('ajax', payload.ajax);
  formData.append('csrf_token', CSRF_TOKEN);
  Object.keys(payload).forEach(k => { if (k !== 'ajax') formData.append(k, payload[k]); });
  fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(d => onDone(d))
    .catch(() => onDone({ success: false, message: 'Network error' }));
}

function escapeHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function buildLiveRowHtml(img) {
  const desc = img.description
    ? `<div style="color:var(--admin-muted);font-size:13px;max-width:420px;">${escapeHtml(img.description)}</div>`
    : '';
  return `
    <td style="padding:10px;border-bottom:1px solid var(--admin-border);">
      <div class="promo-order-btns">
        <button type="button" title="Move up" onclick="reorderImage(${img.id}, 'up', this)">&uarr;</button>
        <button type="button" title="Move down" onclick="reorderImage(${img.id}, 'down', this)">&darr;</button>
      </div>
    </td>
    <td style="padding:10px;border-bottom:1px solid var(--admin-border);">
      <img class="promo-thumb" src="${WEB_ROOT}${img.image_path}" alt="${escapeHtml(img.alt_text)}">
    </td>
    <td style="padding:10px;border-bottom:1px solid var(--admin-border);color:var(--admin-text);">
      <div style="font-weight:600;">${escapeHtml(img.title)}</div>
      ${desc}
    </td>
    <td style="padding:10px;border-bottom:1px solid var(--admin-border);white-space:nowrap;">
      <button class="btn btn-sm btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="openEditModalById(${img.id})">Edit</button>
      <button class="btn btn-sm btn-danger" style="padding:4px 10px;font-size:12px;" onclick="archiveImage(${img.id}, this)">Archive</button>
    </td>`;
}

function buildArchivedRowHtml(img) {
  return `
    <td style="padding:10px;border-bottom:1px solid #1f2937;">
      <img class="promo-thumb" style="opacity:.6;" src="${WEB_ROOT}${img.image_path}" alt="${escapeHtml(img.alt_text)}">
    </td>
    <td style="padding:10px;border-bottom:1px solid #1f2937;color:#9ca3af;">
      ${escapeHtml(img.title)}
    </td>
    <td style="padding:10px;border-bottom:1px solid #1f2937;white-space:nowrap;">
      <button class="btn btn-sm btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="restoreImage(${img.id}, this)">Restore</button>
    </td>`;
}

// Keeps the up/down arrows correctly disabled at the top/bottom of
// whatever rows are currently in the live table, after a row moves in/out.
function refreshOrderButtons() {
  const rows = document.querySelectorAll('#liveTbody tr[data-img]');
  rows.forEach((row, i) => {
    const up = row.querySelector('.promo-order-btns button:first-child');
    const down = row.querySelector('.promo-order-btns button:last-child');
    if (up) up.disabled = (i === 0);
    if (down) down.disabled = (i === rows.length - 1);
  });
}

async function archiveImage(id, btn) {
  if (!(await uiConfirm('Archive this promotional image? It will be hidden from the carousel, but you can restore it later.'))) return;
  btn.disabled = true;
  ajaxAction({ ajax: 'archive', id }, function(d) {
    if (d.success) {
      const row = document.getElementById('promoRow' + id);
      const img = row ? JSON.parse(row.dataset.img) : null;
      if (row) row.remove();

      const liveTbody = document.getElementById('liveTbody');
      if (!liveTbody.querySelector('tr[data-img]')) {
        document.getElementById('liveEmptyRow').style.display = '';
      }
      refreshOrderButtons();

      if (img) {
        const tr = document.createElement('tr');
        tr.id = 'archivedRow' + id;
        tr.dataset.img = JSON.stringify(img);
        tr.innerHTML = buildArchivedRowHtml(img);
        document.getElementById('archivedTbody').appendChild(tr);
      }
      updateCountsUI();
      showNotification('Image archived.', 'success');
    } else {
      btn.disabled = false;
      showNotification(d.message || 'Failed to archive image', 'error');
    }
  });
}

function restoreImage(id, btn) {
  btn.disabled = true;
  ajaxAction({ ajax: 'restore', id }, function(d) {
    if (d.success) {
      const row = document.getElementById('archivedRow' + id);
      const img = row ? JSON.parse(row.dataset.img) : null;
      if (row) row.remove();

      if (img) {
        document.getElementById('liveEmptyRow').style.display = 'none';
        const tr = document.createElement('tr');
        tr.id = 'promoRow' + id;
        tr.dataset.img = JSON.stringify(img);
        tr.innerHTML = buildLiveRowHtml(img);
        document.getElementById('liveTbody').appendChild(tr);
        refreshOrderButtons();
      }
      updateCountsUI();
      showNotification('Image restored.', 'success');
    } else {
      btn.disabled = false;
      showNotification(d.message || 'Failed to restore image', 'error');
    }
  });
}

function reorderImage(id, direction, btn) {
  btn.disabled = true;
  ajaxAction({ ajax: 'reorder', id, direction }, function(d) {
    if (d.success) {
      location.reload();
    } else {
      btn.disabled = false;
      showNotification(d.message || 'Failed to reorder', 'error');
    }
  });
}

function showNotification(message, type) {
  const n = document.createElement('div');
  n.style.cssText = `position:fixed;top:20px;right:20px;padding:12px 20px;border-radius:8px;color:#fff;font-weight:500;z-index:10000;background:${type === 'success' ? '#10b981' : '#ef4444'};border:1px solid ${type === 'success' ? '#059669' : '#dc2626'};`;
  n.textContent = message;
  document.body.appendChild(n);
  setTimeout(() => n.remove(), 3000);
}

updateCountsUI();
</script>
