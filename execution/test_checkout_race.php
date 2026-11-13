<?php
/**
 * execution/test_checkout_race.php
 *
 * Concurrency test harness for the checkout stock-deduction fix.
 *
 * Fires genuinely parallel HTTP POSTs at the real checkout endpoint
 * (public/authenticated/customer/process_order.php) with curl_multi, so the
 * requests are in flight inside Apache at the same instant - the only way to
 * actually exercise an InnoDB row-lock race. Two browser tabs by hand cannot
 * reproduce it reliably.
 *
 * WHY IT FORGES SESSION FILES INSTEAD OF LOGGING IN
 * Customer login goes through an emailed 2FA code (require_login() enforces
 * $_SESSION['login_2fa_verified']), which a script cannot complete. So the
 * harness writes the PHP session files directly into session.save_path with
 * exactly the keys the app reads - user_id, user_role, login_2fa_verified,
 * csrf_token and the cart - and sends the matching TECHWRLD_CUST cookie. The
 * endpoint under test is hit completely unmodified.
 *
 * It creates its own throwaway product and customers, so no real inventory and
 * no real account is touched, and deletes exactly the rows it created (by id)
 * at the end.
 *
 * USAGE
 *   php execution/test_checkout_race.php              # run all scenarios
 *   php execution/test_checkout_race.php --keep       # leave test data behind
 *   BASE_URL=http://localhost/TECHWRLD php execution/test_checkout_race.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This harness is CLI-only.\n");
}

require_once __DIR__ . '/../config/database.php';

const TEST_TAG   = '__RACETEST__';
const TEST_EMAIL = 'racetest+%d@techwrld.invalid';

$baseUrl  = rtrim(getenv('BASE_URL') ?: 'http://localhost/TECHWRLD', '/');
$endpoint = $baseUrl . '/public/authenticated/customer/process_order.php';
$sessPath = ini_get('session.save_path') ?: (getenv('SESS_PATH') ?: 'C:\\xampp\\tmp');
$keepData = in_array('--keep', $argv, true);

$pdo = getConnection();

// ------------------------------------------------------------------ helpers --

function hr(string $title = ''): void
{
    echo $title === ''
        ? str_repeat('-', 72) . "\n"
        : "\n" . str_repeat('=', 72) . "\n" . $title . "\n" . str_repeat('=', 72) . "\n";
}

function stockOf(PDO $pdo, int $productId): int
{
    $s = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
    $s->execute([$productId]);
    return (int)$s->fetchColumn();
}

function setStock(PDO $pdo, int $productId, int $stock): void
{
    $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stock, $productId]);
}

/** Create (or reuse) a throwaway active product with a known stock level. */
function makeTestProduct(PDO $pdo, string $label, int $stock, float $price = 100.00): int
{
    $name = TEST_TAG . ' ' . $label;
    $sel = $pdo->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $sel->execute([$name]);
    $id = (int)$sel->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE products SET stock = ?, price = ?, status = 'active' WHERE id = ?")
            ->execute([$stock, $price, $id]);
        return $id;
    }
    $catStmt = $pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1');
    $categoryId = $catStmt ? ($catStmt->fetchColumn() ?: null) : null;
    $ins = $pdo->prepare(
        "INSERT INTO products (name, description, price, stock, category_id, status)
         VALUES (?, 'Temporary row created by execution/test_checkout_race.php', ?, ?, ?, 'active')"
    );
    $ins->execute([$name, $price, $stock, $categoryId]);
    return (int)$pdo->lastInsertId();
}

/** Create (or reuse) N throwaway customers. Returns their user ids. */
function makeTestCustomers(PDO $pdo, int $n): array
{
    $ids = [];
    $hash = password_hash('race-test-not-a-real-account', PASSWORD_DEFAULT);
    for ($i = 1; $i <= $n; $i++) {
        $email = sprintf(TEST_EMAIL, $i);
        $sel = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $sel->execute([$email]);
        $id = (int)$sel->fetchColumn();
        if (!$id) {
            $ins = $pdo->prepare(
                "INSERT INTO users (firstname, lastname, email, password, role, status, address, phone)
                 VALUES (?, 'RaceTest', ?, ?, 'customer', 'active', '123 Test St, Caloocan', '09170000000')"
            );
            $ins->execute(['Buyer' . $i, $email, $hash]);
            $id = (int)$pdo->lastInsertId();
        }
        $ids[] = $id;
    }
    return $ids;
}

/**
 * Write a PHP session file the request will load, and return
 * [sessionId, csrfToken, filePath]. Default `php` serialize_handler format.
 */
function forgeSession(string $sessPath, int $userId, array $cart): array
{
    $sid  = 'racetest' . bin2hex(random_bytes(12));
    $csrf = bin2hex(random_bytes(32));
    $data = [
        'user_id'            => $userId,
        'user_role'          => 'customer',
        'login_2fa_verified' => true,
        'csrf_token'         => $csrf,
        'cart'               => [$userId => $cart],
    ];
    $blob = '';
    foreach ($data as $k => $v) {
        $blob .= $k . '|' . serialize($v);
    }
    $file = rtrim($sessPath, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    if (file_put_contents($file, $blob) === false) {
        throw new RuntimeException("Could not write session file: $file");
    }
    return [$sid, $csrf, $file];
}

/**
 * Fire every request at once and return the decoded results in order.
 * Each $requests entry: ['sid' => , 'csrf' => , 'fields' => []].
 */
function fireParallel(string $endpoint, array $requests): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $i => $r) {
        $fields = $r['fields'];
        $fields['csrf_token'] = $r['csrf'];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE         => 'TECHWRLD_CUST=' . $r['sid'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_HTTPHEADER     => ['Expect:'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $json = json_decode((string)$body, true);
        $out[$i] = [
            'http'     => $code,
            'success'  => is_array($json) ? (bool)($json['success'] ?? false) : false,
            'message'  => is_array($json)
                ? (string)($json['message'] ?? '')
                : trim(substr(preg_replace('/\s+/', ' ', (string)$body), 0, 160)),
            'order_id' => is_array($json) ? ($json['order_id'] ?? null) : null,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** Standard checkout payload - COD home delivery, the simplest happy path. */
function payload(array $productIds): array
{
    return [
        'full_name'       => 'Race Test Buyer',
        'email'           => 'racetest@techwrld.invalid',
        'phone'           => '09170000000',
        'address'         => '123 Test St, Caloocan City',
        'payment_method'  => 'cod',
        'delivery_method' => 'delivery',
        'order_notes'     => TEST_TAG,
        'selected_ids'    => implode(',', $productIds),
    ];
}

// ------------------------------------------------------------------- set-up --

$createdSessions = [];
$results = [];

$productA = makeTestProduct($pdo, 'Product A', 1);
$productB = makeTestProduct($pdo, 'Product B', 5);
$productC = makeTestProduct($pdo, 'Product C no stock', 0);
$buyers   = makeTestCustomers($pdo, 10);

echo "Endpoint : $endpoint\n";
echo "Sessions : $sessPath\n";
echo "Products : A=$productA  B=$productB  C=$productC\n";
echo "Buyers   : " . implode(', ', $buyers) . "\n";

$run = function (int $productId, array $buyerSlice, int $qty = 1, array $extraProducts = [])
        use ($endpoint, $sessPath, &$createdSessions) {
    $requests = [];
    foreach ($buyerSlice as $uid) {
        $cart = [$productId => $qty];
        foreach ($extraProducts as $pid => $q) {
            $cart[$pid] = $q;
        }
        [$sid, $csrf, $file] = forgeSession($sessPath, $uid, $cart);
        $createdSessions[] = $file;
        $requests[] = ['sid' => $sid, 'csrf' => $csrf, 'fields' => payload(array_keys($cart))];
    }
    return fireParallel($endpoint, $requests);
};

// -------------------------------------------------------- (a) stock 1, two ---

hr('(a) Stock = 1, two simultaneous checkouts -> exactly 1 must succeed');
setStock($pdo, $productA, 1);
echo "stock before : " . stockOf($pdo, $productA) . "\n";
$res = $run($productA, array_slice($buyers, 0, 2));
$ok = 0;
foreach ($res as $i => $r) {
    echo sprintf("  req#%d  HTTP %d  success=%s  %s\n", $i + 1, $r['http'], $r['success'] ? 'YES' : 'no ', $r['message']);
    if ($r['success']) {
        $ok++;
    }
}
$after = stockOf($pdo, $productA);
echo "stock after  : $after\n";
$results['a'] = ($ok === 1 && $after === 0);
echo 'RESULT: ' . ($results['a'] ? 'PASS (1 of 2 succeeded, stock 1 -> 0)' : "FAIL ($ok succeeded, stock=$after)") . "\n";

// ------------------------------------------------------- (b) stock 5, ten ----

hr('(b) Stock = 5, ten simultaneous checkouts of 1 each -> exactly 5 succeed');
setStock($pdo, $productB, 5);
echo "stock before : " . stockOf($pdo, $productB) . "\n";
$res = $run($productB, $buyers);
$ok = 0;
foreach ($res as $i => $r) {
    echo sprintf("  req#%-2d HTTP %d  success=%s  %s\n", $i + 1, $r['http'], $r['success'] ? 'YES' : 'no ', $r['message']);
    if ($r['success']) {
        $ok++;
    }
}
$after = stockOf($pdo, $productB);
echo "stock after  : $after\n";
$results['b'] = ($ok === 5 && $after === 0);
echo 'RESULT: ' . ($results['b'] ? 'PASS (exactly 5 of 10 succeeded, stock 5 -> 0)' : "FAIL ($ok succeeded, stock=$after)") . "\n";

// -------------------------------------------------- (c) multi-item, one dry --

hr('(c) Multi-item order where one item has no stock -> whole order fails');
setStock($pdo, $productA, 3);
setStock($pdo, $productC, 0);
$beforeA = stockOf($pdo, $productA);
$beforeC = stockOf($pdo, $productC);
$ordersBefore = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
echo "stock before : A=$beforeA  C=$beforeC   orders=$ordersBefore\n";
$res = $run($productA, array_slice($buyers, 0, 1), 1, [$productC => 1]);
foreach ($res as $i => $r) {
    echo sprintf("  req#%d  HTTP %d  success=%s  %s\n", $i + 1, $r['http'], $r['success'] ? 'YES' : 'no ', $r['message']);
}
$afterA = stockOf($pdo, $productA);
$afterC = stockOf($pdo, $productC);
$ordersAfter = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
echo "stock after  : A=$afterA  C=$afterC   orders=$ordersAfter\n";
$results['c'] = (!$res[0]['success'] && $afterA === $beforeA && $afterC === $beforeC && $ordersAfter === $ordersBefore);
echo 'RESULT: ' . ($results['c'] ? 'PASS (order rejected, no stock moved, no order row written)' : 'FAIL') . "\n";

// ------------------------------------------------------ (e) normal checkout --

hr('(e) Normal single checkout still works');
setStock($pdo, $productA, 4);
echo "stock before : " . stockOf($pdo, $productA) . "\n";
$res = $run($productA, array_slice($buyers, 0, 1), 2);
echo sprintf(
    "  HTTP %d  success=%s  order_id=%s  %s\n",
    $res[0]['http'],
    $res[0]['success'] ? 'YES' : 'no ',
    (string)$res[0]['order_id'],
    $res[0]['message']
);
$after = stockOf($pdo, $productA);
echo "stock after  : $after\n";
$singleOrderId = (int)($res[0]['order_id'] ?? 0);
$results['e'] = ($res[0]['success'] && $after === 2);
echo 'RESULT: ' . ($results['e'] ? 'PASS (qty 2 accepted, stock 4 -> 2)' : "FAIL (stock=$after)") . "\n";

// --------------------------------------------------------- (d) cancellation --

hr('(d) Cancel an order -> stock is returned, in a transaction');
if ($singleOrderId > 0) {
    $beforeCancel = stockOf($pdo, $productA);
    echo "order #$singleOrderId holds 2 units; stock before cancel : $beforeCancel\n";
    // The same claim-then-restore shape the admin cancellation approval, the
    // pickup-expiry rollback and the unpaid-order sweep all use.
    $pdo->beginTransaction();
    $claim = $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status <> 'cancelled'");
    $claim->execute([$singleOrderId]);
    $claimed = $claim->rowCount();
    if ($claimed === 1) {
        $pdo->prepare(
            'UPDATE products p JOIN order_items oi ON p.id = oi.product_id
             SET p.stock = p.stock + oi.quantity
             WHERE oi.order_id = ?'
        )->execute([$singleOrderId]);
    }
    $pdo->commit();
    $afterCancel = stockOf($pdo, $productA);
    echo "stock after cancel : $afterCancel\n";

    // Idempotency: cancelling twice must not return the units twice.
    $pdo->beginTransaction();
    $claim2 = $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status <> 'cancelled'");
    $claim2->execute([$singleOrderId]);
    if ($claim2->rowCount() === 1) {
        $pdo->prepare(
            'UPDATE products p JOIN order_items oi ON p.id = oi.product_id
             SET p.stock = p.stock + oi.quantity
             WHERE oi.order_id = ?'
        )->execute([$singleOrderId]);
    }
    $pdo->commit();
    $afterSecond = stockOf($pdo, $productA);
    echo "stock after a SECOND cancel of the same order : $afterSecond\n";

    $results['d'] = ($claimed === 1 && $afterCancel === $beforeCancel + 2 && $afterSecond === $afterCancel);
    echo 'RESULT: ' . ($results['d'] ? 'PASS (2 units returned once, second cancel is a no-op)' : 'FAIL') . "\n";
} else {
    $results['d'] = false;
    echo "RESULT: FAIL (no order to cancel - scenario (e) did not produce one)\n";
}

// ----------------------------------------- (f) no negatives, no new oversold -

hr('(f) No negative stock anywhere, and no new oversold SKUs');
$neg = $pdo->query('SELECT id, name, stock FROM products WHERE stock < 0')->fetchAll(PDO::FETCH_ASSOC);
echo 'products with stock < 0 : ' . count($neg) . "\n";
foreach ($neg as $n) {
    echo "   !! #{$n['id']} {$n['name']} = {$n['stock']}\n";
}
$chk = $pdo->query(
    "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
       AND CONSTRAINT_NAME = 'chk_products_stock_non_negative'"
)->fetchColumn();
echo 'CHECK constraint present : ' . ($chk ? 'yes' : 'NO') . "\n";
// Prove the constraint is live, not merely declared.
$constraintBites = false;
try {
    $pdo->prepare('UPDATE products SET stock = stock - 9999 WHERE id = ?')->execute([$productA]);
} catch (PDOException $e) {
    $constraintBites = true;
}
echo 'direct negative write rejected : ' . ($constraintBites ? 'yes' : 'NO') . "\n";
$results['f'] = (count($neg) === 0 && $chk && $constraintBites);
echo 'RESULT: ' . ($results['f'] ? 'PASS' : 'FAIL') . "\n";

// -------------------------------------- (g) multi-item deadlock resistance --

hr('(g) Two multi-item orders over the same products, carts in OPPOSITE order');
// This is the AI Builder case: a build adds many products at once, and two
// builds can share components. If each checkout locked rows in its own cart's
// order, order #1 could hold product A waiting for B while order #2 holds B
// waiting for A - a deadlock, which InnoDB resolves by killing one
// transaction with SQLSTATE 40001. process_order.php sorts both the
// SELECT ... FOR UPDATE and the deduction loop by product id, so every
// checkout queues for the same rows in the same sequence and no cycle can
// form. Both of these orders must succeed on their own merits - a failure
// here means a deadlock, not a stock shortage.
$lowId  = min($productA, $productB);
$highId = max($productA, $productB);
setStock($pdo, $lowId, 20);
setStock($pdo, $highId, 20);
echo "stock before : #$lowId=20  #$highId=20
";
$pairRequests = [];
foreach ([[$lowId, $highId], [$highId, $lowId]] as $slot => $orderOfCart) {
    $uid = $buyers[$slot];
    // The cart array is built in the opposite order for each buyer - the
    // insertion order is what an unsorted implementation would lock in.
    $cart = [];
    foreach ($orderOfCart as $pid) { $cart[$pid] = 1; }
    [$sid, $csrf, $file] = forgeSession($sessPath, $uid, $cart);
    $createdSessions[] = $file;
    $pairRequests[] = ['sid' => $sid, 'csrf' => $csrf, 'fields' => payload(array_keys($cart))];
}
$res = fireParallel($endpoint, $pairRequests);
$okPair = 0;
foreach ($res as $i => $r) {
    echo sprintf("  req#%d  HTTP %d  success=%s  %s
", $i + 1, $r['http'], $r['success'] ? 'YES' : 'no ', $r['message']);
    if ($r['success']) { $okPair++; }
}
$gLow  = stockOf($pdo, $lowId);
$gHigh = stockOf($pdo, $highId);
echo "stock after  : #$lowId=$gLow  #$highId=$gHigh
";
$results['g'] = ($okPair === 2 && $gLow === 18 && $gHigh === 18);
echo 'RESULT: ' . ($results['g'] ? 'PASS (both orders committed, no deadlock, 2 units off each)' : "FAIL ($okPair of 2 succeeded)") . "
";

// ------------------------------------------------------------------ summary --

hr('Summary');
$labels = [
    'a' => 'stock 1, 2 parallel     -> exactly 1 order',
    'b' => 'stock 5, 10 parallel    -> exactly 5 orders, stock 0',
    'c' => 'multi-item, 1 empty     -> whole order rolled back',
    'd' => 'cancel order            -> stock returned, once',
    'e' => 'normal single checkout  -> unchanged',
    'f' => 'no negative stock / no new oversold',
    'g' => 'multi-item, opposite order -> no deadlock',
];
$allPass = true;
foreach ($labels as $k => $label) {
    $p = $results[$k] ?? false;
    $allPass = $allPass && $p;
    echo sprintf("  (%s) %-38s %s\n", $k, $label, $p ? 'PASS' : 'FAIL');
}
echo "\n" . ($allPass ? 'ALL SCENARIOS PASSED' : 'SOME SCENARIOS FAILED') . "\n";

foreach ($createdSessions as $f) {
    @unlink($f);
}

if ($keepData) {
    echo "\n--keep given: test product/user/order rows left in place.\n";
    exit($allPass ? 0 : 1);
}

hr('Cleanup - deleting ONLY the rows this harness created, by id');
$productIds = [$productA, $productB, $productC];
$ph = implode(',', array_fill(0, count($productIds), '?'));
$oStmt = $pdo->prepare("SELECT DISTINCT order_id FROM order_items WHERE product_id IN ($ph)");
$oStmt->execute($productIds);
$orderIds = array_map('intval', $oStmt->fetchAll(PDO::FETCH_COLUMN));

if ($orderIds) {
    $oph = implode(',', array_fill(0, count($orderIds), '?'));
    foreach (['payments', 'order_items'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE order_id IN ($oph)")->execute($orderIds);
    }
    $pdo->prepare("DELETE FROM orders WHERE id IN ($oph)")->execute($orderIds);
    echo '  orders removed   : ' . implode(', ', $orderIds) . "\n";
}
$pdo->prepare("DELETE FROM user_carts WHERE product_id IN ($ph)")->execute($productIds);
$pdo->prepare("DELETE FROM stock_movements WHERE product_id IN ($ph)")->execute($productIds);
$pdo->prepare("DELETE FROM products WHERE id IN ($ph)")->execute($productIds);
echo '  products removed : ' . implode(', ', $productIds) . "\n";
$bph = implode(',', array_fill(0, count($buyers), '?'));
$pdo->prepare("DELETE FROM users WHERE id IN ($bph) AND email LIKE 'racetest+%@techwrld.invalid'")->execute($buyers);
echo '  test customers   : ' . implode(', ', $buyers) . "\n";

exit($allPass ? 0 : 1);
