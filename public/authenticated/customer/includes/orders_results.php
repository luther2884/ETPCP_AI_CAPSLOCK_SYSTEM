<?php
// orders_results.php - the live region of My Orders: the status tab bar, the
// "N orders - Tab" line with its search box, and the results themselves
// (empty state, or the cards plus the pager).
//
// Lives in its own file so orders.php can render it two ways with identical
// markup, the same way products.php renders includes/products_results.php:
//   1. inline, as part of the full page
//   2. alone, for ?partial=1 XHR requests, so switching tab, paging or
//      searching swaps just this block instead of reloading the page.
//
// It reads what orders.php already built and computed: $ordTab, $ordQ,
// $ordPage, $ordPages, $ordPerPage, $ordOffset, $ordTotal, $ordCounts,
// $orders, $ordItems, $ordRated - plus ord_url() and ord_tab_for_order().
// Nothing is queried here.
?>
        <?php
          /* The tab bar renders whatever the result set looks like - an empty
             tab still needs its tabs, or the customer has no way back out of
             it. Counts come from the one GROUP BY above, so a badge can never
             disagree with what the tab actually opens. */
          $ordTabLabel = ORD_TAB_LABELS[$ordTab] ?? 'All';
        ?>
        <nav class="ord-tabs" role="tablist" aria-label="Order status">
            <?php foreach (ORD_TABS as $t): ?>
                <?php $isOn = ($t === $ordTab); $n = (int)($ordCounts[$t] ?? 0); ?>
                <a class="ord-tab<?= $isOn ? ' is-on' : '' ?>"
                   role="tab"
                   aria-selected="<?= $isOn ? 'true' : 'false' ?>"
                   href="<?= htmlspecialchars(ord_url(['tab' => $t, 'page' => 1])) ?>">
                    <?= htmlspecialchars(ORD_TAB_LABELS[$t]) ?>
                    <?php /* No badge at zero: a row of 0s reads as broken, not as
                             informative. Completed and Cancelled never carry one. */ ?>
                    <?php if (in_array($t, ORD_BADGE_TABS, true) && $n > 0): ?>
                        <span class="ord-tab-n<?= $t === 'to_pay' ? ' ord-tab-n--pay' : '' ?>"><?= $n ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ord-toolbar">
            <p class="ord-showing">
                <strong><?= (int)$ordTotal ?></strong> <?= $ordTotal === 1 ? 'order' : 'orders' ?>
                <span aria-hidden="true">&middot;</span> <?= htmlspecialchars($ordTabLabel) ?>
            </p>
            <?php /* GET so the search lands in the URL like the tab does, and
                     refresh/Back keep it. The tab rides along in a hidden field
                     so searching never silently throws you back to All. */ ?>
            <form class="ord-search" method="get" role="search">
                <?php if ($ordTab !== 'all'): ?>
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($ordTab) ?>">
                <?php endif; ?>
                <i class="fi fi-rr-search" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= htmlspecialchars($ordQ) ?>"
                       placeholder="Search order no. or product" aria-label="Search order no. or product">
                <?php if ($ordQ !== ''): ?>
                    <a class="ord-search-clear" href="<?= htmlspecialchars(ord_url(['q' => '', 'page' => 1])) ?>" aria-label="Clear search">&times;</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($orders)): ?>
            <div class="ord-empty">
                <div class="ord-empty-icon"><i class="fi fi-rs-shopping-bag"></i></div>
                <h2><?= $ordQ !== '' ? 'No orders match that search' : 'No orders in ' . htmlspecialchars($ordTabLabel) ?></h2>
                <p><?= $ordQ !== ''
                        ? 'Try a different order number or product name.'
                        : 'Orders will appear here as they reach this stage.' ?></p>
                <a href="<?= WEB_ROOT ?>public/authenticated/customer/products.php" class="btn-primary">Continue shopping</a>
            </div>
        <?php else: ?>
            <div class="ord-cards">
                <?php foreach ($orders as $i => $o): ?>
                <?php
                  // One source of truth for what this card is: the same function
                  // the SQL CASE mirrors. Every per-tab decision below reads this
                  // variable, so a card can never show another tab's buttons.
                  $cardTab = ord_tab_for_order($o);
                  $oid     = (int)$o['id'];
                  $items   = $ordItems[$oid] ?? [];
                  $isPickup = ($o['delivery_method'] ?? '') === 'pickup';

                  $needsOnlinePayment = in_array($o['payment_method'] ?? '', ORD_ONLINE_METHODS, true)
                      && ($o['payment_status'] ?? '') !== 'paid'
                      && !in_array($o['status'] ?? '', ['cancelled', 'completed'], true);
                  $payNowUrl = ($o['payment_method'] ?? '') === 'xendit'
                      ? 'xendit_payment.php?id=' . $oid
                      : 'epayment_instructions.php?id=' . $oid;
                  $payNowLabel = ($o['payment_method'] ?? '') === 'xendit' ? 'Pay with Xendit' : 'Pay now';

                  $viewUrl  = WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $oid . '&summary=1';
                  // The cancel flow needs a reason, so this goes to the order page
                  // where that form already lives rather than POSTing blind.
                  $cancelUrl = WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $oid . '&cancel=1';
                  $trackUrl = WEB_ROOT . 'public/authenticated/customer/track_order.php?id=' . $oid;

                  /* Cancel is offered on exactly the terms order_success.php
                     offers it: not already finished, and still inside the
                     30-minute window it computes from seconds_since_created.
                     Same numbers, same source - this does not re-decide when
                     an order may be cancelled, it mirrors that page. */
                  $ordCancelWindow = 30 * 60;
                  $ordElapsed      = max(0, (int)($o['seconds_since_created'] ?? 0));
                  $canCancel = !in_array($o['status'] ?? '', ['completed','cancelled'], true)
                      && ($ordCancelWindow - $ordElapsed) > 0;

                  /* The payment QR is for pay-at-store pickups. payment_qr.php
                     itself bounces anything else, so the button uses that exact
                     pair of columns. (The old check read $o['payment_code'],
                     which is not a column on `orders` - so this button never
                     actually appeared.) */
                  $qrEligible = ($o['payment_method'] ?? '') === 'onsite'
                      && ($o['delivery_method'] ?? '') === 'pickup'
                      && !in_array($o['status'] ?? '', ['completed','cancelled'], true);

                  /* Buy again re-adds through the existing add_to_cart.php,
                     which reads $_GET (id, qty, redirect) and takes one product
                     - so a single-item order can go straight there, and a
                     multi-item one opens the order instead. Hidden entirely
                     when the product can no longer be bought. */
                  $buyAgain = null;
                  if (count($items) === 1) {
                      $only = $items[0];
                      $buyable = (int)($only['stock'] ?? 0) > 0 && ($only['product_status'] ?? '') === 'active';
                      if ($buyable) {
                          $buyAgain = WEB_ROOT . 'public/authenticated/customer/add_to_cart.php?id=' . (int)$only['product_id']
                              . '&qty=' . max(1, (int)$only['quantity'])
                              . '&redirect=' . rawurlencode(WEB_ROOT . 'public/authenticated/customer/cart.php');
                      }
                  } elseif (count($items) > 1) {
                      $buyAgain = WEB_ROOT . 'public/authenticated/customer/order_success.php?id=' . $oid;
                  }

                  // Pill + short note, per tab.
                  $pillMap = [
                    'to_pay'        => ['To Pay',        'ordp--pay'],
                    'to_ship'       => ['To Ship',       'ordp--ship'],
                    'to_receive'    => [$isPickup ? 'Ready for pickup' : 'Shipped', 'ordp--shipped'],
                    'completed'     => ['Completed',     'ordp--done'],
                    'return_refund' => ['Return/Refund', 'ordp--return'],
                    'cancelled'     => ['Cancelled',     'ordp--cancel'],
                    'other'         => [os_label((string)$o['status'], (string)($o['delivery_method'] ?? 'delivery')), 'ordp--cancel'],
                  ];
                  [$pillText, $pillCls] = $pillMap[$cardTab] ?? $pillMap['other'];

                  $noteText = '';
                  if ($cardTab === 'to_pay')              { $noteText = ''; /* countdown fills this */ }
                  elseif ($cardTab === 'to_receive')      { $noteText = $isPickup ? 'Ready at the store' : 'Rider is on the way'; }
                  elseif ($cardTab === 'return_refund')   { $noteText = 'Refund under review'; }
                  elseif ($cardTab === 'to_ship')         { $noteText = 'Being prepared'; }
                ?>
                <article class="ordc" style="--i:<?= min($i, 9) ?>">
                    <header class="ordc-head">
                        <div class="ordc-head-l">
                            <span class="ordc-no">#<?= htmlspecialchars($o['invoice_no']) ?></span>
                            <span class="ordc-date"><?= date('M j, Y', strtotime($o['created_at'])) ?></span>
                            <span class="ordc-chip"><?= htmlspecialchars($o['payment_method_display'] ?: strtoupper((string)$o['payment_method'])) ?></span>
                        </div>
                        <div class="ordc-head-r">
                            <?php if ($cardTab === 'to_pay' && $needsOnlinePayment): ?>
                                <?php /* Unchanged hook: the existing .pay-countdown ticker
                                         still reads data-seconds-left and writes the text. */ ?>
                                <span class="ordc-note pay-countdown" data-seconds-left="<?= max(0, (int)$o['seconds_until_expiry']) ?>"></span>
                            <?php elseif ($noteText !== ''): ?>
                                <span class="ordc-note"><?= htmlspecialchars($noteText) ?></span>
                            <?php endif; ?>
                            <span class="ordp <?= $pillCls ?>"><?= htmlspecialchars($pillText) ?></span>
                        </div>
                    </header>

                    <div class="ordc-body">
                        <?php if (empty($items)): ?>
                            <p class="ordc-noitems">This order's items are no longer in the catalogue.</p>
                        <?php else: foreach ($items as $it): ?>
                            <?php $pid = (int)$it['product_id']; $rated = $ordRated[$pid] ?? null; ?>
                            <div class="ordc-item">
                                <span class="ordc-thumb">
                                    <?php if (!empty($it['image_url'])): ?>
                                        <img src="<?= WEB_ROOT . htmlspecialchars($it['image_url']) ?>" alt="" loading="lazy">
                                    <?php endif; ?>
                                </span>
                                <span class="ordc-item-main">
                                    <a class="ordc-item-name" href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $pid ?>"><?= htmlspecialchars($it['name']) ?></a>
                                    <span class="ordc-item-sub">Qty <?= (int)$it['quantity'] ?></span>
                                </span>
                                <span class="ordc-item-price"><?= formatCurrency((float)$it['price']) ?></span>
                                <?php /* Rating is per product, not per order - so it lives on
                                         the item, and only once the order is complete. */ ?>
                                <?php if ($cardTab === 'completed'): ?>
                                    <?php if ($rated !== null): ?>
                                        <span class="ordc-rated" title="You rated this <?= (int)$rated ?> out of 5">Rated &#9733;<?= (int)$rated ?></span>
                                    <?php else: ?>
                                        <a class="ordc-rate" href="<?= WEB_ROOT ?>public/authenticated/customer/product.php?id=<?= $pid ?>#reviews">Rate</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>

                        <?php /* Pickup orders are collected with this code, so it belongs
                                 on the card rather than one click away. */ ?>
                        <?php if ($cardTab === 'to_receive' && $isPickup && !empty($o['pickup_code'])): ?>
                            <p class="ordc-pickup">Pickup code <b><?= htmlspecialchars($o['pickup_code']) ?></b></p>
                        <?php endif; ?>
                    </div>

                    <footer class="ordc-foot">
                        <span class="ordc-total">
                            <span class="ordc-total-k">Order total</span>
                            <b><?= formatCurrency((float)$o['total_amount']) ?></b>
                        </span>
                        <span class="ordc-acts">
                            <?php /* Cancel appears only where order_success.php would
                                     show its own Cancel button - same status test, same
                                     30-minute window. &cancel=1 asks that page to open
                                     the reason modal it already has. */ ?>
                            <?php if ($cardTab === 'to_pay'): ?>
                                <?php if ($canCancel): ?>
                                    <a class="ordb" href="<?= htmlspecialchars($cancelUrl) ?>">Cancel order</a>
                                <?php endif; ?>
                                <?php if ($qrEligible): ?>
                                    <a class="ordb" href="<?= WEB_ROOT ?>public/authenticated/customer/payment_qr.php?order_id=<?= $oid ?>">Payment QR</a>
                                <?php endif; ?>
                                <a class="ordb ordb--on" href="<?= WEB_ROOT ?>public/authenticated/customer/<?= $payNowUrl ?>"><?= htmlspecialchars($payNowLabel) ?></a>
                            <?php elseif ($cardTab === 'to_ship'): ?>
                                <?php if ($canCancel): ?>
                                    <a class="ordb" href="<?= htmlspecialchars($cancelUrl) ?>">Cancel order</a>
                                <?php endif; ?>
                                <?php if ($qrEligible): ?>
                                    <a class="ordb" href="<?= WEB_ROOT ?>public/authenticated/customer/payment_qr.php?order_id=<?= $oid ?>">Payment QR</a>
                                <?php endif; ?>
                                <a class="ordb ordb--on" href="<?= htmlspecialchars($viewUrl) ?>">View details</a>
                            <?php elseif ($cardTab === 'to_receive'): ?>
                                <?php /* "Order received" is deliberately absent: no
                                         confirm-receipt handler exists, and receipt is
                                         written by the courier, not the customer. */ ?>
                                <?php if ($qrEligible): ?>
                                    <a class="ordb" href="<?= WEB_ROOT ?>public/authenticated/customer/payment_qr.php?order_id=<?= $oid ?>">Payment QR</a>
                                <?php endif; ?>
                                <a class="ordb ordb--on" href="<?= htmlspecialchars($trackUrl) ?>">Track order</a>
                            <?php elseif ($cardTab === 'completed'): ?>
                                <a class="ordb" href="<?= htmlspecialchars($viewUrl) ?>">View details</a>
                                <?php if ($buyAgain): ?>
                                    <a class="ordb ordb--on" href="<?= htmlspecialchars($buyAgain) ?>">Buy again</a>
                                <?php endif; ?>
                            <?php elseif ($cardTab === 'return_refund'): ?>
                                <a class="ordb" href="<?= WEB_ROOT ?>public/authenticated/customer/support.php">Contact support</a>
                                <a class="ordb ordb--on" href="<?= WEB_ROOT ?>public/authenticated/customer/returns.php">View request</a>
                            <?php else: ?>
                                <a class="ordb" href="<?= htmlspecialchars($viewUrl) ?>">View details</a>
                                <?php if ($buyAgain): ?>
                                    <a class="ordb ordb--on" href="<?= htmlspecialchars($buyAgain) ?>">Buy again</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </footer>
                </article>
                <?php endforeach; ?>
            </div>

            <?php if ($ordPages > 1): ?>
            <nav class="ord-pager" aria-label="Order pages">
                <a class="ordb<?= $ordPage <= 1 ? ' is-off' : '' ?>"
                   href="<?= $ordPage > 1 ? htmlspecialchars(ord_url(['page' => $ordPage - 1])) : '#' ?>">Previous</a>
                <span class="ord-pager-n">
                    Showing <?= (int)($ordOffset + 1) ?>&ndash;<?= (int)min($ordOffset + $ordPerPage, $ordTotal) ?> of <?= (int)$ordTotal ?>
                </span>
                <a class="ordb<?= $ordPage >= $ordPages ? ' is-off' : '' ?>"
                   href="<?= $ordPage < $ordPages ? htmlspecialchars(ord_url(['page' => $ordPage + 1])) : '#' ?>">Next</a>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
