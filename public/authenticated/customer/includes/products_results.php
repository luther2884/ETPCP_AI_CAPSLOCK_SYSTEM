<?php
// products_results.php - the results region of the customer products listing
// (heading + count + grid + pagination). Lives in its own file so it can be
// rendered two ways from products.php with identical markup:
//   1. inline, as part of the full page
//   2. alone, for ?partial=1 XHR requests, so filtering swaps just this block
//      instead of reloading the page.
// It reads the variables products.php already built: $products, $totalProducts,
// $totalPages, $page, $search, $searchType, $suggestions, $currentCategoryName.
?>
                <div class="products-header">
                    <!-- Numbered eyebrow, matching the landing sections. Its
                         own class rather than .tw-head__eyebrow because that
                         one centres itself; this heading is left-aligned. -->
                    <div class="pf-eyebrow"><span aria-hidden="true"></span>THE CATALOG</div>
                    <h1>
                        <?php if ($search): ?>
                            Search Results for "<?php echo htmlspecialchars($search); ?>"
                        <?php elseif ($currentCategoryName): ?>
                            <?php echo htmlspecialchars($currentCategoryName); ?>
                        <?php else: ?>
                            All Products
                        <?php endif; ?>
                    </h1>
                    <p class="results-count"><?php echo $totalProducts; ?> products found</p>
                    
                    <?php if ($search && isset($searchType)): ?>
                        <?php if ($searchType === 'fuzzy'): ?>
                            <div class="search-suggestion">
                                <p>🔍 <strong>Did you mean:</strong> We found similar results for your search.</p>
                            </div>
                        <?php elseif ($searchType === 'no_results' && !empty($suggestions)): ?>
                            <div class="search-suggestions">
                                <p>🔍 <strong>No exact matches found.</strong> Did you mean:</p>
                                <div class="suggestion-buttons">
                                    <?php foreach ($suggestions as $suggestion): ?>
                                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?q=<?= urlencode($suggestion['text']) ?>" 
                                           class="suggestion-btn">
                                            <?= htmlspecialchars($suggestion['text']) ?>
                                            <span class="suggestion-count">(<?= $suggestion['count'] ?>)</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (empty($products)): ?>
                    <div class="no-products">
                        <div class="no-products-icon">📦</div>
                        <h3>No products found</h3>
                        <p>Try adjusting your filters or search terms</p>
                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="btn-primary">View All Products</a>
                    </div>
                <?php else: ?>
                    <!-- Electric-border filter: displaces a hollow border with animated
                         turbulence noise so it crackles. Defined once; referenced by
                         .product-card:hover::after (see the stylesheet). -->
                    <svg class="eb-defs" aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute;pointer-events:none">
                      <defs>
                        <filter id="eb-turbulence" x="-30%" y="-30%" width="160%" height="160%">
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n1" seed="1"/>
                          <feOffset in="n1" dx="0" dy="0" result="o1">
                            <animate attributeName="dy" values="700; 0" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n2" seed="1"/>
                          <feOffset in="n2" dx="0" dy="0" result="o2">
                            <animate attributeName="dy" values="0; -700" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n3" seed="2"/>
                          <feOffset in="n3" dx="0" dy="0" result="o3">
                            <animate attributeName="dx" values="490; 0" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feTurbulence type="turbulence" baseFrequency="0.02" numOctaves="6" result="n4" seed="2"/>
                          <feOffset in="n4" dx="0" dy="0" result="o4">
                            <animate attributeName="dx" values="0; -490" dur="6s" repeatCount="indefinite" calcMode="linear"/>
                          </feOffset>
                          <feComposite in="o1" in2="o2" result="yMove"/>
                          <feComposite in="o3" in2="o4" result="xMove"/>
                          <feBlend in="yMove" in2="xMove" mode="color-dodge" result="blended"/>
                          <feDisplacementMap in="SourceGraphic" in2="blended" scale="12"/>
                        </filter>
                      </defs>
                    </svg>
                    <div class="products-grid">
                    <?php
                      // BEST VALUE = cheapest of the products already fetched
                      // for this page. A pass over $products, not another
                      // query - nothing here touches the database. Zero-priced
                      // rows are skipped so a missing price cannot win.
                      $pvBestId = null; $pvMin = null;
                      foreach ($products as $pvP) {
                          $pvPrice = (float)$pvP['price'];
                          if ($pvPrice > 0 && ($pvMin === null || $pvPrice < $pvMin)) {
                              $pvMin = $pvPrice; $pvBestId = (int)$pvP['id'];
                          }
                      }
                    ?>
                        <?php foreach ($products as $product): ?>
                            <div class="product-card" data-product-id="<?php echo $product['id']; ?>">
                                <?php
                                  // One badge per card, first match wins:
                                  // NEW > BEST VALUE > IN STOCK. Same rules the
                                  // homepage cards use. reorder_level comes from
                                  // an optional migration, hence the ?? 5.
                                  $pvStock   = (int)$product['stock'];
                                  $pvReorder = (int)($product['reorder_level'] ?? 5);
                                  $pvIsNew   = !empty($product['created_at']) && strtotime($product['created_at']) > strtotime('-30 days');
                                  $pvIsValue = $pvBestId !== null && (int)$product['id'] === $pvBestId;
                                  $pvInStock = $pvStock > $pvReorder * 2;
                                ?>
                                <a class="product-image"
                                   href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?php echo (int)$product['id']; ?>"
                                   aria-label="View <?php echo htmlspecialchars($product['name']); ?>">
                                    <?php if ($pvIsValue): ?>
                                      <span class="tw-badge tw-badge--value">BEST VALUE</span>
                                    <?php elseif ($pvInStock): ?>
                                      <span class="tw-badge tw-badge--stock">IN STOCK</span>
                                    <?php elseif ($pvIsNew): ?>
                                      <span class="tw-badge tw-badge--new">NEW</span>
                                    <?php endif; ?>
                                    <?php if ($product['image_url']): ?>
                                        <img src="<?php echo WEB_ROOT . htmlspecialchars($product['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                                             loading="lazy">
                                    <?php else: ?>
                                        <img src="<?php echo WEB_ROOT; ?>public/shared/img/products/placeholder.svg" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                                             loading="lazy">
                                    <?php endif; ?>
                                </a>

                                <div class="product-info">
                                    <div class="product-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></div>
                                    <h3 class="product-title">
                                        <a href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?php echo $product['id']; ?>">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </a>
                                    </h3>
                                    <div class="product-rating">
                                        <?php
                                            $reviewCount = (int)($product['review_count'] ?? 0);
                                            $avgRating = (float)($product['avg_rating'] ?? 0);
                                            $filledStars = $reviewCount > 0 ? (int)round($avgRating) : 0;
                                            for ($i = 1; $i <= 5; $i++) {
                                                echo $i <= $filledStars ? '<i class="fi fi-ss-star"></i>' : '<i class="fi fi-rs-star"></i>';
                                            }
                                            if ($reviewCount === 0) {
                                                echo '<span class="rating-text">No reviews yet</span>';
                                            } else {
                                                echo '<span class="rating-text">' . number_format($avgRating, 1) . ' (' . $reviewCount . ' ' . ($reviewCount === 1 ? 'review' : 'reviews') . ')</span>';
                                            }
                                        ?>
                                    </div>
                                    
                                    <div class="price-stock-section">
                                        <div class="product-price">
                                            <?php if (floatval($product['price']) > 0): ?>
                                                <?php
                                                  $priceText = preg_replace('/[^0-9]/', '', (string)round($product['price']));
                                                  $digits = strlen($priceText);
                                                  $priceClass = 'current-price';
                                                  if ($digits >= 9) { $priceClass .= ' xxs'; }
                                                  elseif ($digits >= 8) { $priceClass .= ' xs'; }
                                                  elseif ($digits >= 5) { $priceClass .= ' small'; }
                                                ?>
                                                <span class="<?= $priceClass ?>"><?php echo formatCurrency($product['price']); ?></span>
                                                <?php if (mt_rand(0, 1)): ?>
                                                    <span class="original-price"><?php echo formatCurrency($product['price'] * 1.2); ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="current-price">₱ —</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stock-indicator">
                                            <?php if ($product['stock'] > 0): ?>
                                                <?php if ($product['stock'] <= 5): ?>
                                                    <span class="stock-low">
                                                        <i class="fi fi-rr-exclamation-triangle"></i>
                                                        <?php echo $product['stock']; ?> left
                                                    </span>
                                                <?php else: ?>
                                                    <span class="stock-available">
                                                        <i class="fi fi-rr-check-circle"></i>
                                                        <?php echo $product['stock']; ?> AVAILABLE
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="stock-out">
                                                    <i class="fi fi-rr-cross-circle"></i>
                                                    Out of Stock
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="product-actions">
                                        <?php if ($product['stock'] > 0): ?>
                                            <a class="add-to-cart-btn" href="<?= WEB_ROOT ?>public/authenticated/customer/add_to_cart.php?id=<?php echo $product['id']; ?>&redirect=<?= urlencode(WEB_ROOT . 'public/authenticated/customer/products.php') ?>" onclick="event.preventDefault(); addToCart(<?php echo $product['id']; ?>);">
                                                <i class="fi fi-rs-shopping-cart-add"></i>
                                                Add to Cart
                                            </a>
                                            <button class="buy-now-btn" onclick="buyNow(<?php echo $product['id']; ?>)">
                                                <i class="fi fi-rr-shopping-bag"></i>
                                                Buy Now
                                            </button>
                                        <?php else: ?>
                                            <a class="wishlist-action-btn" href="<?= WEB_ROOT ?>public/authenticated/customer/add_to_cart.php?id=<?php echo $product['id']; ?>&redirect=<?= urlencode(WEB_ROOT . 'public/authenticated/customer/products.php') ?>" onclick="event.preventDefault(); addToCart(<?php echo $product['id']; ?>);">
                                                <i class="fi fi-rs-heart"></i>
                                                Add to Wishlist
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php
                            $currentUrl = $_SERVER['REQUEST_URI'];
                            $urlParts = parse_url($currentUrl);
                            parse_str($urlParts['query'] ?? '', $urlParams);
                            // Never leak the XHR flag into the page links -
                            // otherwise a page number clicked after an in-place
                            // filter would navigate to the bare results block.
                            unset($urlParams['partial']);
                            ?>

                            <?php if ($page > 1): ?>
                                <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?<?php echo http_build_query(array_merge($urlParams, ['page' => $page - 1])); ?>" class="pagination-btn">
                                    <i class="fi fi-rs-angle-left"></i> Previous
                                </a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?<?php echo http_build_query(array_merge($urlParams, ['page' => $i])); ?>" 
                                   class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php?<?php echo http_build_query(array_merge($urlParams, ['page' => $page + 1])); ?>" class="pagination-btn">
                                    Next <i class="fi fi-rs-angle-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
