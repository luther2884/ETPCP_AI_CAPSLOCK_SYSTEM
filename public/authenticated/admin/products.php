<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_role(['admin']);

$pageTitle = 'Products - TechWorld Admin';
$isAdminTheme = true;

// Search query (optional)
$searchQuery = trim((string)($_GET['q'] ?? ''));

// View-only page: no inline actions here

// Fetch products
try {
  $pdo = getConnection();
  if ($searchQuery !== '') {
    $like = '%' . $searchQuery . '%';
    $sql = "SELECT p.*, c.name AS category_name,
                   (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id) AS units_total,
                   (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id AND pu.status = 'in_stock') AS units_in_stock
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)
            ORDER BY p.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$like, $like, $like]);
    $products = $stmt->fetchAll();
  } else {
    $stmt = $pdo->query("SELECT p.*, c.name AS category_name"
      . ", (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id) AS units_total"
      . ", (SELECT COUNT(*) FROM product_units pu WHERE pu.product_id = p.id AND pu.status = 'in_stock') AS units_in_stock"
      . " FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC");
    $products = $stmt->fetchAll();
  }
} catch (PDOException $e) { $products = []; }

// If redirected here with message (e.g., after edit)
// No changes needed; view-only list now

// Categories list not required for view-only page

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>
<main class="container">
  <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="javascript:void(0)" onclick="if (history.length>1) { history.back(); } else { window.location.href='product_management.php'; }">Products</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Catalog</span>
      </nav>
      <h1 class="adm-title">Product Catalog <span class="pc-viewonly">VIEW ONLY</span></h1>
    </div>
    <div class="adm-actions">
      <a href="<?= WEB_ROOT ?>public/authenticated/admin/add_product.php" class="ad-btn ad-btn--primary">+ Add Product</a>
    </div>
  </div>

  <div class="ad-card">
    <?php /* No tabs here. This page reads exactly one parameter - `q` - so
             All / Active / Low stock / Out of stock have nothing to filter on,
             and a Category select has no parameter either. Left out rather
             than faked; see the note returned with this change. */ ?>
    <div class="ad-card-head">
      <h2 class="ad-card-title">
        <?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?>
        <?php if ($searchQuery !== ''): ?>
          <span class="ad-card-note">matching &ldquo;<?= htmlspecialchars($searchQuery) ?>&rdquo;</span>
        <?php endif; ?>
      </h2>
      <form id="productSearchForm" action="<?= WEB_ROOT ?>public/authenticated/admin/products.php" method="GET" class="ad-search">
        <input id="adminProductSearch" name="q" type="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search products..." autocomplete="off" spellcheck="false" aria-label="Search products">
        <div id="adminSearchSuggestions" class="dropdown-menu" style="display:none;position:absolute;top:42px;left:0;right:0;z-index:999;background:#fff;border:1px solid var(--ad-border);border-radius:10px;max-height:260px;overflow:auto;"></div>
      </form>
    </div>

    <div class="ad-table-wrap">
      <table class="ad-table pc-table">
        <thead>
          <tr>
            <th>ID</th>
            <th><span class="ad-sr">Image</span></th>
            <th>Product</th>
            <th>Category</th>
            <th class="ad-num">Price</th>
            <th class="ad-num">Stock</th>
            <th>Units</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$products): ?>
          <tr><td colspan="8" class="ad-table-empty">No products found.</td></tr>
          <?php else: foreach ($products as $p): ?>
          <?php
            /* Stock drives both its own colour and, at zero, the status chip -
               an "active" product with nothing on the shelf is out of stock,
               and saying so here saves cross-referencing two columns. The
               underlying $p['status'] value is not changed. */
            $pcStock  = (int)$p['stock'];
            $pcOut    = $pcStock === 0;
            $pcActive = $p['status'] === 'active';
            /* Per-unit tracking. Three states, and the middle one is the only
               one that needs acting on: flagged but with no serials entered,
               which still sells on the quantity figure in the Stock column.
               See config/product_units.php. */
            $pcFlagged = !empty($p['requires_serial']);
            $pcUnits   = (int)($p['units_total'] ?? 0);
            $pcLive    = $pcFlagged && $pcUnits > 0;
          ?>
          <tr>
            <td class="pc-id"><?= (int)$p['id'] ?></td>
            <td class="pc-thumb-cell">
              <?php if (!empty($p['image_url'])): ?>
                <img class="pc-thumb" src="<?= WEB_ROOT . htmlspecialchars($p['image_url']) ?>" alt="">
              <?php else: ?>
                <span class="pc-thumb pc-thumb--none" aria-hidden="true">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="pc-name"><?= htmlspecialchars($p['name']) ?></td>
            <td style="color:var(--ad-muted);"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
            <td class="ad-num" style="font-weight:700;color:var(--ad-ink);"><?= formatCurrency((float)$p['price']) ?></td>
            <td class="ad-num<?= $pcOut ? ' pc-stock--out' : ($pcStock <= 2 ? ' pc-stock--low' : '') ?>"><?= $pcStock ?></td>
            <td>
              <?php if ($pcLive): ?>
                <a class="ad-chip ad-chip--active" style="text-decoration:none;"
                   href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php?product_id=<?= (int)$p['id'] ?>"
                   title="<?= (int)($p['units_in_stock'] ?? 0) ?> in stock of <?= $pcUnits ?> on record">Tracked</a>
              <?php elseif ($pcFlagged): ?>
                <a class="ad-chip ad-chip--reorder" style="text-decoration:none;"
                   href="<?= WEB_ROOT ?>public/authenticated/admin/product_units.php?product_id=<?= (int)$p['id'] ?>"
                   title="Flagged for unit tracking, but no serials entered yet">No serials</a>
              <?php else: ?>
                <span style="color:var(--ad-muted);">&mdash;</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($pcOut): ?>
                <span class="ad-chip ad-chip--out">Out of stock</span>
              <?php else: ?>
                <span class="ad-chip <?= $pcActive ? 'ad-chip--active' : 'ad-chip--inactive' ?>"><?= htmlspecialchars(ucfirst($p['status'])) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<script>
(function(){
  var input = document.getElementById('adminProductSearch');
  var panel = document.getElementById('adminSearchSuggestions');
  if (!input || !panel) return;

  var MIN_CHARS = 2; // avoid noisy short queries
  var controller = null;

  function clearPanel(){
    panel.innerHTML = '';
    panel.style.display = 'none';
  }

  function renderSuggestions(items){
    if (!items || !items.length){
      clearPanel();
      return;
    }
    var html = '';
    for (var i=0;i<items.length;i++){
      var item = items[i];
      var label = item.name || item.text || '';
      var extra = item.category_name ? ('<span style="color:var(--admin-muted);margin-left:6px;">(' + item.category_name + ')</span>') : '';
      html += '<div class="sugg" data-value="' + encodeURIComponent(label) + '" style="padding:8px 10px;cursor:pointer;color:var(--admin-text);border-bottom:1px solid var(--admin-border);">' +
              '<span>' + escapeHtml(label) + '</span>' + extra + '</div>';
    }
    panel.innerHTML = html;
    panel.style.display = 'block';

    var children = panel.querySelectorAll('.sugg');
    for (var j=0;j<children.length;j++){
      children[j].addEventListener('click', function(e){
        var val = decodeURIComponent(this.getAttribute('data-value') || '');
        input.value = val;
        clearPanel();
        // submit the form to filter the table
        var form = document.getElementById('productSearchForm');
        if (form) form.submit();
      });
    }
  }

  function escapeHtml(str){
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showLoading(){
    panel.innerHTML = '<div style="padding:10px;color:var(--admin-muted);">Loading...</div>';
    panel.style.display = 'block';
  }

  function showMessage(msg){
    panel.innerHTML = '<div style="padding:10px;color:var(--admin-muted);">' + escapeHtml(msg) + '</div>';
    panel.style.display = 'block';
  }

  function requestJSON(url, opts){
    opts = opts || {};
    var timeoutMs = 5000;
    var toId = null;
    var ctrl = opts.signal ? { signal: opts.signal } : {};
    var fetchPromise = fetch(url, ctrl).then(function(r){
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    }).then(function(text){
      try { return JSON.parse(text); } catch(e){ throw new Error('Invalid JSON'); }
    });
    var timeoutPromise = new Promise(function(_, rej){ toId = setTimeout(function(){ try { if (controller && controller.abort) controller.abort(); } catch(_e){} rej(new Error('timeout')); }, timeoutMs); });
    return Promise.race([fetchPromise, timeoutPromise]).finally(function(){ if (toId) clearTimeout(toId); });
  }

  function tryEndpoints(paths, buildQuery){
    var idx = 0;
    function attempt(){
      if (idx >= paths.length) return Promise.reject(new Error('all_failed'));
      var url = paths[idx++] + buildQuery;
      return requestJSON(url, { signal: controller ? controller.signal : undefined })
        .catch(function(){ return attempt(); });
    }
    return attempt();
  }

  function fetchSuggestions(q){
    if (controller && controller.abort) controller.abort();
    if (!q || q.trim().length < MIN_CHARS){ clearPanel(); return; }

    try { controller = new AbortController(); } catch(e){ controller = null; }

    var base = '<?= rtrim(WEB_ROOT, "/") ?>';
    var buildQuery = '/public/authenticated/api/search.php?action=autocomplete&q=' + encodeURIComponent(q);
    var origins = [
      base,
      '',
      (window.location.origin || '') + base,
      (window.location.origin || '')
    ];
    showLoading();
    tryEndpoints(origins, buildQuery)
      .then(function(data){ var list = (data && data.suggestions) ? data.suggestions : []; renderSuggestions(list); })
      .catch(function(err){ try { console.error('Search suggestions error', err); } catch(_e) {} clearPanel(); });
  }

  // Debounce removed: fetch immediately on input-like events.
  // `input` alone - it already fires for typing, Backspace/Delete, paste, cut,
  // drag-drop and autofill. The `keyup` binding that used to sit alongside it
  // covered a strict subset of those and so fired a second, identical request
  // for every printable keystroke: a 10-character query cost 20 requests
  // instead of 10. Same behaviour, half the traffic.
  input.addEventListener('input', function(){ fetchSuggestions(input.value); });
  input.addEventListener('keydown', function(e){ if ((e.key || '') === 'Enter') { e.preventDefault(); var form = document.getElementById('productSearchForm'); if (form) form.submit(); }});
  input.addEventListener('focus', function(){ if (input.value && input.value.trim() !== '') { fetchSuggestions(input.value); } });

  // Hide suggestions on outside click
  document.addEventListener('click', function(e){
    if (!panel.contains(e.target) && e.target !== input){ clearPanel(); }
  });

  // If prefilled from server, fetch suggestions immediately
  if (input.value && input.value.trim() !== ''){ fetchSuggestions(input.value); }
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>


