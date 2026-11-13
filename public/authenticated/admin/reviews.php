<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('TECHWRLD_ADM'); session_start(); }
require_once __DIR__ . '/../../../config/functions.php';
require_role(['admin']);

$pageTitle = 'Customer Feedback - TechWorld Admin';
$isAdminTheme = true;

// Save (or clear) an admin reply to a review. Replies are written by hand;
// the AI draft button that used to sit beside this form has been removed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_reply') {
    if (!check_csrf(false)) {
        $_SESSION['error_message'] = 'Invalid security token.';
    } else {
        $reviewId = (int)($_POST['review_id'] ?? 0);
        $replyText = trim($_POST['reply_text'] ?? '');
        if ($reviewId > 0) {
            try {
                $pdo = getConnection();
                if ($replyText === '') {
                    $upd = $pdo->prepare("UPDATE reviews SET admin_reply = NULL, admin_reply_at = NULL, admin_reply_by = NULL WHERE id = ?");
                    $upd->execute([$reviewId]);
                    $_SESSION['success_message'] = 'Reply removed.';
                } else {
                    $upd = $pdo->prepare("UPDATE reviews SET admin_reply = ?, admin_reply_at = NOW(), admin_reply_by = ? WHERE id = ?");
                    $upd->execute([$replyText, getUserId(), $reviewId]);
                    logAudit('review_reply_saved', 'reviews', $reviewId, null, ['reply_length' => mb_strlen($replyText)]);
                    $_SESSION['success_message'] = 'Reply saved.';
                }
            } catch (Throwable $e) {
                error_log('Save review reply error: ' . $e->getMessage());
                $_SESSION['error_message'] = 'Could not save the reply. Please try again.';
            }
        }
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: reviews.php' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}

$search = trim($_GET['search'] ?? '');
$productId = isset($_GET['product']) ? (int)$_GET['product'] : 0;
$ratingFilter = (int)($_GET['rating'] ?? 0);
if ($ratingFilter < 1 || $ratingFilter > 5) { $ratingFilter = 0; }
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Preserves the current product/search/rating filters on a link, letting you
// override any one of them (e.g. ['rating' => 3, 'page' => 1]).
function reviewsFilterUrl(array $overrides = []): string {
    global $productId, $search, $ratingFilter;
    $qs = array_merge([
        'product' => $productId ?: null,
        'search'  => $search !== '' ? $search : null,
        'rating'  => $ratingFilter ?: null,
    ], $overrides);
    if (isset($qs['page']) && (int)$qs['page'] <= 1) { $qs['page'] = null; }
    $qs = array_filter($qs, static fn($v) => $v !== null && $v !== '' && $v !== 0);
    return 'reviews.php' . ($qs ? ('?' . http_build_query($qs)) : '');
}

try {
    $pdo = getConnection();
    // Base filter = product + search only. The rating filter is layered on top
    // for the list itself, but the ratings breakdown panel stays computed
    // against the base so it always shows the full distribution.
    $where = [];
    $params = [];
    if ($search) { $where[] = '(p.name LIKE ? OR r.title LIKE ? OR r.comment LIKE ?)'; $like = "%$search%"; $params = array_merge($params, [$like,$like,$like]); }
    if ($productId > 0) { $where[] = 'r.product_id = ?'; $params[] = $productId; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $listWhere = $where;
    $listParams = $params;
    if ($ratingFilter > 0) { $listWhere[] = 'r.rating = ?'; $listParams[] = $ratingFilter; }
    $listWhereSql = $listWhere ? ('WHERE ' . implode(' AND ', $listWhere)) : '';

    $sql = "SELECT r.*, p.name as product_name, u.firstname, u.lastname
            FROM reviews r
            JOIN products p ON p.id = r.product_id
            JOIN users u ON u.id = r.user_id
            $listWhereSql
            ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($listParams);
    $reviews = $stmt->fetchAll();

    // Customer photos per review (multi-photo, see DBSQL/add_review_images_table.sql).
    // Falls back to the legacy reviews.image_path if the table isn't there yet.
    $reviewImages = [];
    if ($reviews) {
        $ids = array_map(static fn($r) => (int)$r['id'], $reviews);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $imgStmt = $pdo->prepare("SELECT review_id, image_path FROM review_images WHERE review_id IN ($ph) ORDER BY id ASC");
            $imgStmt->execute($ids);
            foreach ($imgStmt->fetchAll() as $img) {
                $reviewImages[(int)$img['review_id']][] = $img['image_path'];
            }
        } catch (Throwable $e) {
            foreach ($reviews as $r) {
                if (!empty($r['image_path'])) { $reviewImages[(int)$r['id']][] = $r['image_path']; }
            }
        }
    }

    // totals & aggregates
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM reviews r JOIN products p ON p.id=r.product_id JOIN users u ON u.id=r.user_id $listWhereSql");
    $cntStmt->execute($listParams);
    $totalReviews = (int)$cntStmt->fetchColumn();
    $totalPages = (int)ceil($totalReviews / $limit);

    $aggStmt = $pdo->prepare("SELECT AVG(rating) avg_rating, COUNT(*) cnt,
        SUM(rating=5) s5, SUM(rating=4) s4, SUM(rating=3) s3, SUM(rating=2) s2, SUM(rating=1) s1
        FROM reviews r JOIN products p ON p.id=r.product_id JOIN users u ON u.id=r.user_id $whereSql");
    $aggStmt->execute($params);
    $agg = $aggStmt->fetch() ?: ['avg_rating'=>0,'cnt'=>0,'s5'=>0,'s4'=>0,'s3'=>0,'s2'=>0,'s1'=>0];

    $products = $pdo->query('SELECT id, name FROM products ORDER BY name ASC')->fetchAll();
} catch (PDOException $e) {
    error_log('Reviews load error: ' . $e->getMessage());
    $reviews = [];
    $products = [];
    $totalReviews = 0;
    $totalPages = 0;
    $agg = ['avg_rating'=>0,'cnt'=>0,'s5'=>0,'s4'=>0,'s3'=>0,'s2'=>0,'s1'=>0];
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navigation.php';
?>

<main class="container">
    <div class="adm-head">
    <div>
      <nav class="adm-crumb" aria-label="Breadcrumb">
        <a href="javascript:void(0)" onclick="if(history.length>1){history.back();}else{window.location.href='CusMan.php';}" title="Back">Customers</a>
        <span class="adm-crumb-sep" aria-hidden="true">&rsaquo;</span>
        <span class="adm-crumb-cur" aria-current="page">Feedback</span>
      </nav>
    </div>
  </div>
  <div class="admin-dashboard" style="background:transparent;color:inherit;margin:0 auto;padding:30px;border-radius:12px;max-width:1400px;border:1px solid rgba(148,163,184,0.12);">
    <style>

      /* The card itself is the fixed box: its height comes from the viewport, never
         from how many reviews a rating filter returned. Everything inside is a
         flex column, and the review list is the only part that scrolls - so
         5-star (7 reviews) and 4-star (2 reviews) render the exact same frame. */
      .admin-dashboard{box-sizing:border-box;width:100%;display:flex;flex-direction:column;height:clamp(620px,calc(100vh - 150px),1000px);overflow:hidden;}
      .admin-dashboard > *{flex:0 0 auto;}
      .feedback-grid{display:grid;grid-template-columns:420px 1fr;gap:22px;align-content:stretch;flex:1 1 auto;min-height:0;}
      .feedback-grid > .overview{align-self:start;max-height:100%;overflow:auto;}
      .reviews-wrap{display:flex;flex-direction:column;gap:12px;height:100%;min-height:0;overflow-y:scroll;scrollbar-gutter:stable;overscroll-behavior:contain;padding-right:8px;}
      .reviews-wrap::-webkit-scrollbar{width:8px}
      .reviews-wrap::-webkit-scrollbar-track{background:transparent}
      .reviews-wrap::-webkit-scrollbar-thumb{background:rgba(148,163,184,.28);border-radius:999px}
      .reviews-wrap::-webkit-scrollbar-thumb:hover{background:rgba(148,163,184,.45)}
      .reviews-wrap > *{flex:0 0 auto;}
      /* Stacked layout has nothing to hold a fixed height against, so let it flow. */
      @media (max-width: 900px){
        .admin-dashboard{height:auto;display:block;overflow:visible;}
        .feedback-grid{grid-template-columns:1fr;height:auto;}
        .feedback-grid > .overview{max-height:none;overflow:visible;}
        .reviews-wrap{height:auto;overflow:visible;scrollbar-gutter:auto;padding-right:0;}
      }
    </style>
    <div class="welcome-message" style="text-align:center;margin-bottom:20px;padding:16px;background:var(--admin-primary-2);color:#fff;border-radius:10px;">
      <h1 style="margin-bottom:6px;color:#fff;">Customer Feedback</h1>
      <p style="color:#fff;opacity:.92;">Overall ratings and detailed reviews. Use filters below.</p>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
      <div style="background:rgba(95,168,138,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-success);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-check" style="font-size:16px;color:var(--admin-success);"></i>
          <?= htmlspecialchars($_SESSION['success_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
      <div style="background:rgba(194,104,111,0.14);border:1px solid var(--admin-border);border-left:4px solid var(--admin-danger);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;text-align:center;">
        <p style="color:var(--admin-text);margin:0;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;">
          <i class="fi fi-rr-exclamation-triangle" style="font-size:16px;color:var(--admin-danger);"></i>
          <?= htmlspecialchars($_SESSION['error_message']) ?>
        </p>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <form method="GET" style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
      <select name="product" style="padding:8px;border-radius:6px;border:1px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-text);">
        <option value="0">All Products</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $productId===(int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="search" placeholder="Search reviews..." value="<?= htmlspecialchars($search) ?>" style="padding:8px;border-radius:6px;border:1px solid #ccc;">
      <?php if ($ratingFilter > 0): ?><input type="hidden" name="rating" value="<?= $ratingFilter ?>"><?php endif; ?>
      <button type="submit" style="background:var(--accent-blue);color:#fff;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;">Search</button>
      <?php if ($search || $productId || $ratingFilter): ?>
        <a href="reviews.php" style="background:var(--admin-muted);color:#fff;padding:8px 12px;border-radius:6px;text-decoration:none;">Clear</a>
      <?php endif; ?>
    </form>

    <!-- Star rating quick filter -->
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
      <span style="opacity:.7;font-size:13px;">Filter by rating:</span>
      <a href="<?= htmlspecialchars(reviewsFilterUrl(['rating' => null, 'page' => 1])) ?>"
         style="padding:6px 12px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--admin-border);<?= $ratingFilter === 0 ? 'background:#a77693;color:#0b1220;' : 'background:var(--admin-surface);color:var(--admin-text);' ?>">All</a>
      <?php foreach ([5,4,3,2,1] as $s): $sc = (int)($agg['s'.$s] ?? 0); ?>
        <a href="<?= htmlspecialchars(reviewsFilterUrl(['rating' => $s, 'page' => 1])) ?>"
           style="padding:6px 12px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--admin-border);<?= $ratingFilter === $s ? 'background:#a77693;color:#0b1220;' : 'background:var(--admin-surface);color:var(--admin-text);' ?>">
          <?= $s ?> <span style="color:#fbbf24;">★</span> <span style="opacity:.65;">(<?= $sc ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="feedback-grid">
      <div class="overview" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);border-radius:12px;padding:20px;">
        <?php $avg = round((float)($agg['avg_rating'] ?? 0), 2); $cnt = (int)($agg['cnt'] ?? 0); ?>
        <div style="font-size:40px;font-weight:800;margin-bottom:4px;line-height:1;"><?= number_format($avg, 2) ?></div>
        <div style="color:#fbbf24;margin-bottom:6px;">
          <?php $full = (int)floor($avg); for ($i=1;$i<=5;$i++): ?>
            <?= $i <= $full ? '★' : '☆' ?>
          <?php endfor; ?>
        </div>
        <div style="opacity:.8;margin-bottom:14px;">based on <?= number_format($cnt) ?> ratings</div>
        <?php $starCounts = [5=>(int)$agg['s5'],4=>(int)$agg['s4'],3=>(int)$agg['s3'],2=>(int)$agg['s2'],1=>(int)$agg['s1']]; $maxCount = max(1, max($starCounts)); ?>
        <?php foreach ([5,4,3,2,1] as $s): $val=$starCounts[$s]; $pct = round(($val/$maxCount)*100); $isActive = $ratingFilter === $s; ?>
          <a href="<?= htmlspecialchars(reviewsFilterUrl(['rating' => $isActive ? null : $s, 'page' => 1])) ?>"
             title="<?= $isActive ? 'Clear rating filter' : 'Show only ' . $s . '-star reviews' ?>"
             style="display:flex;align-items:center;gap:10px;margin:6px 0;text-decoration:none;color:inherit;padding:2px 4px;border-radius:6px;<?= $isActive ? 'background:var(--admin-surface-2);' : '' ?>">
            <div style="width:60px;opacity:.8;"><?= $s ?> Stars</div>
            <div style="flex:1;background:var(--admin-surface-2);border:1px solid var(--admin-border);border-radius:999px;height:10px;overflow:hidden;">
              <div style="height:100%;width:<?= $pct ?>%;background:#a77693;"></div>
            </div>
            <div style="width:60px;text-align:right;opacity:.9;"><?= number_format($val) ?></div>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="reviews-wrap">
        <?php if (empty($reviews)): ?>
          <div style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:40px;border-radius:8px;text-align:center;"><?= $ratingFilter > 0 ? 'No ' . $ratingFilter . '-star reviews found.' : 'No reviews found.' ?></div>
        <?php else: ?>
          <?php $initialVisible = 4; ?>
          <?php foreach ($reviews as $i => $r): ?>
            <div class="review-card<?= $i >= $initialVisible ? ' review-card--collapsed' : '' ?>" style="background:var(--admin-surface);border:1px solid var(--admin-border);color:var(--admin-text);padding:16px;border-radius:10px;<?= $i >= $initialVisible ? 'display:none;' : '' ?>">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <div style="font-weight:700;color:#a77693;<?= $productId ? '' : 'max-width:60%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' ?>"><?= htmlspecialchars($r['product_name']) ?></div>
                <div style="opacity:.8;"><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></div>
              </div>
              <div style="margin-bottom:8px;color:#fbbf24;">★ <?= (int)$r['rating'] ?>/5 <span style="margin-left:8px;opacity:.8;color:var(--admin-muted);">by <?= htmlspecialchars($r['firstname'] . ' ' . $r['lastname']) ?></span></div>
              <?php if (!empty($r['title'])): ?><div style="font-weight:600;margin-bottom:4px;"><?= htmlspecialchars($r['title']) ?></div><?php endif; ?>
              <?php if (!empty($r['comment'])): ?><div style="opacity:.9;line-height:1.5;"><?= nl2br(htmlspecialchars($r['comment'])) ?></div><?php endif; ?>

              <?php $imgs = $reviewImages[(int)$r['id']] ?? []; ?>
              <?php if ($imgs): ?>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;">
                  <?php foreach ($imgs as $imgPath): ?>
                    <a href="<?= WEB_ROOT . htmlspecialchars($imgPath) ?>" target="_blank" rel="noopener">
                      <img src="<?= WEB_ROOT . htmlspecialchars($imgPath) ?>" alt="Customer photo" loading="lazy" style="width:120px;height:120px;border-radius:8px;border:1px solid var(--admin-border);object-fit:cover;">
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($r['admin_reply'])): ?>
                <div style="margin-top:12px;padding:12px;background:var(--admin-surface-2);border-left:3px solid #a77693;border-radius:6px;">
                  <div style="font-size:12px;font-weight:700;color:#a77693;margin-bottom:4px;">
                    <i class="fi fi-rr-store"></i> TechWorld Store Response
                    <?php if (!empty($r['admin_reply_at'])): ?>
                      <span style="font-weight:400;opacity:.7;">· <?= date('M j, Y g:i A', strtotime($r['admin_reply_at'])) ?></span>
                    <?php endif; ?>
                  </div>
                  <div style="opacity:.9;line-height:1.5;font-size:14px;"><?= nl2br(htmlspecialchars($r['admin_reply'])) ?></div>
                </div>
              <?php endif; ?>

              <div style="margin-top:10px;">
                <button type="button" onclick="toggleReplyForm(<?= (int)$r['id'] ?>)" style="background:none;border:1px solid var(--admin-border);color:var(--admin-text);padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;">
                  <i class="fi fi-rr-comment-alt"></i> <?= !empty($r['admin_reply']) ? 'Edit Reply' : 'Reply' ?>
                </button>
              </div>

              <div id="reply-form-<?= (int)$r['id'] ?>" style="display:none;margin-top:10px;">
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="save_reply">
                  <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                  <textarea name="reply_text" id="reply-text-<?= (int)$r['id'] ?>" rows="3" placeholder="Write a reply to this customer..." style="width:100%;box-sizing:border-box;padding:10px;border-radius:6px;border:1px solid var(--admin-border);background:var(--admin-surface-2);color:var(--admin-text);font-family:inherit;font-size:13px;resize:vertical;"><?= htmlspecialchars($r['admin_reply'] ?? '') ?></textarea>
                  <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <button type="submit" style="background:var(--admin-success);color:#f2f3f4;border:none;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">Save Reply</button>
                    <?php if (!empty($r['admin_reply'])): ?>
                      <button type="submit" onclick="document.getElementById('reply-text-<?= (int)$r['id'] ?>').value=''" style="background:var(--admin-danger);color:#f2f3f4;border:none;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">Remove Reply</button>
                    <?php endif; ?>
                    <button type="button" onclick="toggleReplyForm(<?= (int)$r['id'] ?>)" style="background:none;color:var(--admin-muted);border:1px solid var(--admin-border);padding:7px 14px;border-radius:6px;cursor:pointer;font-size:12px;">Cancel</button>
                  </div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if (count($reviews) > $initialVisible): ?>
            <div id="reviewsViewMoreWrap" style="display:flex;justify-content:center;margin-top:6px;">
              <button type="button" id="reviewsViewMoreBtn" onclick="toggleMoreReviews(this)"
                      data-hidden="<?= count($reviews) - $initialVisible ?>"
                      style="padding:9px 18px;background:var(--admin-surface-2);color:var(--admin-text);border:1px solid var(--admin-border);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">
                <i class="fi fi-rr-angle-small-down"></i> View <?= count($reviews) - $initialVisible ?> more on this page
              </button>
            </div>
          <?php endif; ?>

          <?php if ($totalPages > 1): ?>
            <div style="display:flex;justify-content:center;gap:10px;margin-top:10px;">
              <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(reviewsFilterUrl(['page' => $page - 1])) ?>" style="padding:8px 14px;background:var(--admin-surface-2);color:var(--admin-text);text-decoration:none;border-radius:8px;">Previous</a>
              <?php endif; ?>
              <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(reviewsFilterUrl(['page' => $page + 1])) ?>" style="padding:8px 14px;background:#a77693;color:#0b1220;text-decoration:none;border-radius:8px;">Load More Reviews</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script>
// Collapses the on-page review list to the first few cards; this button
// reveals/re-hides the rest. Server-side pagination (Load More Reviews /
// Previous, below) still handles going past this page's 15.
function toggleMoreReviews(btn) {
  var collapsed = document.querySelectorAll('.review-card--collapsed');
  var expanded = btn.getAttribute('data-expanded') === '1';
  collapsed.forEach(function (card) { card.style.display = expanded ? 'none' : ''; });
  btn.setAttribute('data-expanded', expanded ? '0' : '1');
  var n = btn.getAttribute('data-hidden');
  btn.innerHTML = expanded
    ? '<i class="fi fi-rr-angle-small-down"></i> View ' + n + ' more on this page'
    : '<i class="fi fi-rr-angle-small-up"></i> Show fewer';
  if (expanded) { btn.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
}

function toggleReplyForm(reviewId) {
  var form = document.getElementById('reply-form-' + reviewId);
  if (!form) return;
  form.style.display = (form.style.display === 'none' || !form.style.display) ? 'block' : 'none';
}

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


