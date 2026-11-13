<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_role(['admin']);

$pageTitle = 'Categories & Tags - TechWorld Admin';
$isAdminTheme = true;

$errors = [];$success='';
$action = $_GET['action'] ?? '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $pdo = getConnection();
        
        if ($_POST['ajax'] === 'add_tag') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $color = trim($_POST['color'] ?? '#174871');
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Tag name is required']);
                exit;
            }
            
            $stmt = $pdo->prepare('INSERT INTO tags (name, description, color) VALUES (?, ?, ?)');
            $ok = $stmt->execute([$name, $description, $color]);

            if ($ok) {
                $newTagId = (int)$pdo->lastInsertId();
                logAudit('create', 'tags', $newTagId, null, ['name' => $name, 'color' => $color]);
                echo json_encode(['success' => true, 'message' => 'Tag added successfully', 'id' => $newTagId]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to add tag']);
            }
            exit;
        }
        
        if ($_POST['ajax'] === 'assign_tags') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $tagIds = $_POST['tag_ids'] ?? [];
            
            if ($categoryId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid category']);
                exit;
            }
            
            // Remove existing tags for this category
            $pdo->prepare('DELETE FROM category_tags WHERE category_id = ?')->execute([$categoryId]);
            
            // Add new tags
            if (!empty($tagIds)) {
                $stmt = $pdo->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (?, ?)');
                foreach ($tagIds as $tagId) {
                    $stmt->execute([$categoryId, (int)$tagId]);
                }
            }

            logAudit('update', 'categories', $categoryId, null, ['tag_ids' => array_map('intval', $tagIds)]);
            echo json_encode(['success' => true, 'message' => 'Tags updated successfully']);
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!check_csrf(false)) { $errors[] = 'Invalid token'; }
  $name = trim($_POST['name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  if ($name === '') { $errors[] = 'Name is required.'; }
  if (!$errors) {
    try {
      $pdo = getConnection();
      $stmt = $pdo->prepare('INSERT INTO categories (name, description) VALUES (?, ?)');
      $ok = $stmt->execute([$name, $description]);
      if ($ok) {
        logAudit('create', 'categories', (int)$pdo->lastInsertId(), null, ['name' => $name]);
        $success = 'Category added.'; $name = $description = '';
      } else { $errors[] = 'Failed to add category.'; }
    } catch (PDOException $e) { $errors[] = 'Server error.'; }
  }
}

try {
  $pdo = getConnection();
  
  // Get categories with their tags
  $cats = $pdo->query('
    SELECT c.*, 
           GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ",") as tag_names,
           GROUP_CONCAT(t.color ORDER BY t.name SEPARATOR ",") as tag_colors,
           GROUP_CONCAT(t.id ORDER BY t.name SEPARATOR ",") as tag_ids
    FROM categories c 
    LEFT JOIN category_tags ct ON c.id = ct.category_id 
    LEFT JOIN tags t ON ct.tag_id = t.id 
    GROUP BY c.id 
    ORDER BY c.id DESC
  ')->fetchAll();
  
  // Get all available tags
  $allTags = $pdo->query('SELECT * FROM tags ORDER BY name ASC')->fetchAll();
} catch (PDOException $e) { 
  $cats = []; 
  $allTags = [];
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
        <span class="adm-crumb-cur" aria-current="page">Categories & Tags</span>
      </nav>
      <h1 class="adm-title">Categories &amp; Tags</h1>
    </div>
  </div>
  <div class="ct-wrap">
    <?php if ($errors): ?><div class="notification notification-error" style="display:block;margin-bottom:12px;"><?= htmlspecialchars(implode(' ',$errors)) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="notification notification-success" style="display:block;margin-bottom:12px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php /* The two creation forms side by side. Same method, same field
             names, same required flags - only the layout and the button
             styling changed. */ ?>
    <div class="ct-forms">
      <div class="ad-card">
        <div class="ad-card-head"><h2 class="ad-card-title">New category</h2></div>
        <div class="ad-card-body ad-field">
          <form method="post" class="ct-form">
            <?= csrf_field() ?>
            <div class="ct-row ct-row--2">
              <div>
                <label class="ad-label" for="ct_name">Name</label>
                <input class="input" id="ct_name" type="text" name="name" value="<?= htmlspecialchars($name ?? '') ?>" required>
              </div>
              <div>
                <label class="ad-label" for="ct_desc">Description</label>
                <textarea class="textarea ct-desc-input" id="ct_desc" name="description" rows="1"><?= htmlspecialchars($description ?? '') ?></textarea>
              </div>
            </div>
            <div class="ct-actions">
              <button type="submit" class="ad-btn ad-btn--primary">Add Category</button>
            </div>
          </form>
        </div>
      </div>

      <div class="ad-card">
        <div class="ad-card-head"><h2 class="ad-card-title">New tag</h2></div>
        <div class="ad-card-body ad-field">
          <form id="addTagForm" class="ct-form">
            <?= csrf_field() ?>
            <div class="ct-row ct-row--3">
              <div>
                <label class="ad-label" for="ct_tag_name">Tag name</label>
                <input class="input" id="ct_tag_name" type="text" name="tag_name" required>
              </div>
              <div>
                <label class="ad-label" for="ct_tag_desc">Description</label>
                <input class="input" id="ct_tag_desc" type="text" name="tag_description">
              </div>
              <div>
                <label class="ad-label" for="ct_tag_color">Colour</label>
                <input class="input ct-color" id="ct_tag_color" type="color" name="tag_color" value="#174871">
              </div>
            </div>
            <div class="ct-actions">
              <button type="submit" class="ad-btn ad-btn--dark">Add Tag</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="ad-card">
      <div class="ad-card-head">
        <h2 class="ad-card-title"><?= count($cats) ?> categor<?= count($cats) === 1 ? 'y' : 'ies' ?></h2>
      </div>
      <div class="ad-table-wrap">
        <table class="ad-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Category</th>
              <th>Description</th>
              <th>Tags</th>
              <th><span class="ad-sr">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cats as $c): ?>
            <tr>
              <td class="ct-id"><?= (int)$c['id'] ?></td>
              <td style="font-weight:700;color:var(--ad-ink);"><?= htmlspecialchars($c['name']) ?></td>
              <td class="ct-desc"><?= $c['description'] !== null && $c['description'] !== '' ? htmlspecialchars($c['description']) : '&mdash;' ?></td>
              <td>
                <div class="tags-container">
                  <?php
                  $tagNames = $c['tag_names'] ? explode(',', $c['tag_names']) : [];
                  $tagColors = $c['tag_colors'] ? explode(',', $c['tag_colors']) : [];
                  for ($i = 0; $i < count($tagNames); $i++):
                    $tagName = trim($tagNames[$i]);
                    $tagColor = isset($tagColors[$i]) ? trim($tagColors[$i]) : '#174871';
                  ?>
                    <span class="tag" style="background:<?= $tagColor ?>;">
                      <?= htmlspecialchars($tagName) ?>
                    </span>
                  <?php endfor; ?>
                  <?php if (empty($tagNames)): ?>
                    <span class="ct-notags">No tags</span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="text-align:right;">
                <button class="ad-btn ad-btn--secondary ct-manage" onclick="openTagModal(<?= $c['id'] ?>, '<?= htmlspecialchars($c['name']) ?>', <?= $c['tag_ids'] ? '[' . $c['tag_ids'] . ']' : '[]' ?>)">
                  Manage tags
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="ad-card">
      <div class="ad-card-head"><h2 class="ad-card-title">Available tags</h2></div>
      <div class="ad-card-body">
        <div class="tags-container">
          <?php foreach ($allTags as $tag): ?>
            <span class="tag tag--lg" style="background:<?= $tag['color'] ?>;">
              <?= htmlspecialchars($tag['name']) ?>
              <?php if ($tag['description']): ?>
                <span style="opacity:0.85;font-weight:400;"> &ndash; <?= htmlspecialchars($tag['description']) ?></span>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- Tag Management Modal -->
<div id="tagModal" style="display:none;position:fixed;inset:0;background:rgba(15,45,77,0.72);z-index:1000;">
  <div style="max-width:600px;margin:60px auto;background:var(--admin-surface-2);border-radius:var(--radius-lg);box-shadow:var(--neu-raised);overflow:hidden;">
    <div style="padding:20px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;align-items:center;">
      <h3 style="margin:0;color:var(--admin-text);" id="modalTitle">Manage Tags</h3>
      <button onclick="closeTagModal()" style="background:transparent;color:var(--admin-muted);border:0;font-size:24px;cursor:pointer;">×</button>
    </div>
    <div style="padding:20px;">
      <div id="tagCheckboxes" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:20px;">
        <!-- Tag checkboxes will be populated here -->
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button onclick="closeTagModal()" class="btn btn-secondary">Cancel</button>
        <button onclick="saveTags()" class="btn btn-primary">Save Tags</button>
      </div>
    </div>
  </div>
</div>

<script>
// Available tags data
const allTags = <?= json_encode($allTags) ?>;
let currentCategoryId = null;

// Add tag form submission
document.getElementById('addTagForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('ajax', 'add_tag');
    formData.append('name', formData.get('tag_name'));
    formData.append('description', formData.get('tag_description'));
    formData.append('color', formData.get('tag_color'));
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Tag added successfully!', 'success');
            this.reset();
            location.reload(); // Reload to show new tag
        } else {
            showNotification(data.message || 'Failed to add tag', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
});

// Open tag management modal
function openTagModal(categoryId, categoryName, currentTagIds) {
    currentCategoryId = categoryId;
    document.getElementById('modalTitle').textContent = `Manage Tags for "${categoryName}"`;
    
    const checkboxesContainer = document.getElementById('tagCheckboxes');
    checkboxesContainer.innerHTML = '';
    
    allTags.forEach(tag => {
        const isChecked = currentTagIds.includes(tag.id);
        const checkboxHtml = `
            <label style="display:flex;align-items:center;gap:8px;padding:8px;border-radius:var(--radius-sm);cursor:pointer;background:${isChecked ? 'var(--accent-cyan-bg)' : 'var(--admin-surface)'}">
                <input type="checkbox" value="${tag.id}" ${isChecked ? 'checked' : ''} style="margin:0;">
                <span style="background:${tag.color};color:white;padding:2px 8px;border-radius:12px;font-size:12px;">${tag.name}</span>
                <span style="color:var(--admin-muted);font-size:12px;">${tag.description || ''}</span>
            </label>
        `;
        checkboxesContainer.innerHTML += checkboxHtml;
    });
    
    document.getElementById('tagModal').style.display = 'block';
}

// Close tag management modal
function closeTagModal() {
    document.getElementById('tagModal').style.display = 'none';
    currentCategoryId = null;
}

// Save tags for category
function saveTags() {
    if (!currentCategoryId) return;
    
    const checkboxes = document.querySelectorAll('#tagCheckboxes input[type="checkbox"]:checked');
    const tagIds = Array.from(checkboxes).map(cb => cb.value);
    
    const formData = new FormData();
    formData.append('ajax', 'assign_tags');
    formData.append('category_id', currentCategoryId);
    tagIds.forEach(tagId => formData.append('tag_ids[]', tagId));
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Tags updated successfully!', 'success');
            closeTagModal();
            location.reload(); // Reload to show updated tags
        } else {
            showNotification(data.message || 'Failed to update tags', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

// Simple notification function
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        background: ${type === 'success' ? '#047857' : '#b91c1c'};
        border: 1px solid ${type === 'success' ? '#065f46' : '#991b1b'};
    `;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Close modal when clicking outside
document.getElementById('tagModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeTagModal();
    }
});
</script>

