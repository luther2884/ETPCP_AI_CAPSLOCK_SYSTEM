# Race Condition Prevention Guide

> ## STATUS: the design below was NEVER WIRED UP. See "What is actually live" at the end.
>
> Everything from "Overview" to "Conclusion" describes `process_order_race_safe.php`
> plus `DBSQL/race_condition_prevention.sql`. That endpoint has no callers -
> `checkout.php` has always posted to `process_order.php` - and the
> `UpdateProductStock` stored procedure it depends on was never created in the
> database, so the protection described here was not on the code path a real
> checkout takes. Two customers did oversell a 1-unit product (orders #136 and
> #137, 2026-10-09 04:58:37, both for product #210) while this document sat in
> the repo claiming the problem was solved.
>
> The working fix is much smaller and lives in `process_order.php`. Read
> **What is actually live** below before acting on anything in between.

This guide explains the comprehensive race condition prevention solution implemented in the TechWorld e-commerce system.

## Overview

The solution prevents three critical race conditions:
1. **Overselling Products** - Multiple users buying the same product simultaneously
2. **Duplicate Orders** - Users clicking "Buy" multiple times
3. **Inventory Inconsistencies** - Stock count becoming inaccurate during concurrent purchases

## Database Schema Updates

### New Tables

#### `pending_orders`
Tracks reserved stock during order processing to prevent overselling.

```sql
CREATE TABLE pending_orders (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  product_id INT(11) NOT NULL,
  quantity INT(11) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_product (user_id, product_id),
  KEY idx_expires_at (expires_at)
);
```

#### `order_locks`
Prevents concurrent order processing from the same user.

```sql
CREATE TABLE order_locks (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  lock_key VARCHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lock_key (lock_key),
  KEY idx_user_id (user_id),
  KEY idx_expires_at (expires_at)
);
```

### Modified Tables

#### `products`
Added version column for optimistic locking.

```sql
ALTER TABLE products 
ADD COLUMN version INT(11) NOT NULL DEFAULT 1 AFTER updated_at;
```

#### `orders`
Added order hash for duplicate prevention.

```sql
ALTER TABLE orders 
ADD COLUMN order_hash VARCHAR(64) NOT NULL DEFAULT '' AFTER pickup_code_expires_at,
ADD UNIQUE KEY uniq_order_hash (order_hash);
```

### Stored Procedures

#### `UpdateProductStock`
Atomically updates product stock with version control.

```sql
CALL UpdateProductStock(product_id, quantity, 'decrease', @success, @new_stock, @message);
```

## Implementation Details

### 1. Row-Level Locking

Products are locked using `FOR UPDATE` during stock validation:

```php
$stmt = $pdo->prepare("
    SELECT id, name, price, stock, version 
    FROM products 
    WHERE id IN ($placeholders) AND status = 'active'
    FOR UPDATE
");
```

### 2. Atomic Stock Updates

Stock updates use stored procedures with version control:

```php
$stmt = $pdo->prepare("CALL UpdateProductStock(?, ?, 'decrease', @success, @new_stock, @message)");
$stmt->execute([$productId, $quantity, 'decrease']);
```

### 3. Order Hash for Duplicate Prevention

Each order gets a unique hash based on user, cart data, and timestamp:

```php
$orderHash = hash('sha256', $userId . ':' . $cartString . ':' . $timestamp);
```

### 4. Processing Locks

Users can only process one order at a time:

```php
$lockKey = 'order_process_' . $userId . '_' . time();
$expiresAt = date('Y-m-d H:i:s', time() + $this->lockTimeout);
```

## Usage

### 1. Apply Database Schema

```bash
mysql -u root -p techworld_ecommerce < DBSQL/race_condition_prevention.sql
```

### 2. Use Race-Safe Order Processing

Replace the standard checkout with the race-safe version:

```php
// Instead of: process_order.php
// Use: process_order_race_safe.php
```

### 3. Test the Implementation

Run the test script:

```bash
php test_race_conditions.php
```

Or use the API endpoint:

```bash
curl -X POST http://localhost/TECHWRLD4.10/public/authenticated/api/test_race_conditions.php \
  -d "test_key=race_test_2024&product_id=1&quantity=1&user_id=1"
```

## Key Features

### 1. Database Transactions
All operations are wrapped in database transactions to ensure atomicity.

### 2. Row-Level Locking
Products are locked during stock checks to prevent concurrent modifications.

### 3. Optimistic Locking
Version control prevents lost updates when multiple processes modify the same record.

### 4. Duplicate Prevention
Order hashes prevent users from creating duplicate orders.

### 5. Processing Locks
Users can only process one order at a time, preventing race conditions.

### 6. Automatic Cleanup
Expired locks and pending orders are automatically cleaned up.

## Performance Considerations

### 1. Lock Timeout
Processing locks expire after 30 seconds to prevent deadlocks.

### 2. Pending Order Timeout
Reserved stock expires after 5 minutes to prevent indefinite holds.

### 3. Database Indexes
Optimized indexes for better performance on stock checks.

### 4. Event Scheduler
Automatic cleanup runs every 5 minutes to maintain system health.

## Monitoring

### 1. Audit Logs
All race condition prevention actions are logged for monitoring.

### 2. Stock Movements
Every stock change is tracked with reference to the order.

### 3. Error Logging
Failed operations are logged with detailed error messages.

## Testing

### 1. Concurrent Purchase Test
Simulates multiple users buying the same product simultaneously.

### 2. Duplicate Order Test
Tests prevention of duplicate orders from the same user.

### 3. Stock Consistency Test
Verifies inventory accuracy under high load.

## Error Handling

### 1. Insufficient Stock
Clear error messages when stock is not available.

### 2. Concurrent Processing
Friendly messages when another order is being processed.

### 3. Database Errors
Graceful handling of database connection issues.

## Security Considerations

### 1. CSRF Protection
All requests are protected with CSRF tokens.

### 2. Input Validation
All inputs are validated and sanitized.

### 3. SQL Injection Prevention
All queries use prepared statements.

## Maintenance

### 1. Regular Cleanup
The system automatically cleans up expired locks and pending orders.

### 2. Monitoring
Monitor the audit logs for any issues.

### 3. Performance Tuning
Adjust timeouts based on system performance.

## Troubleshooting

### 1. Deadlocks
If deadlocks occur, check for long-running transactions.

### 2. Stock Inconsistencies
Verify that all stock updates use the stored procedure.

### 3. Duplicate Orders
Check that order hashes are being generated correctly.

## Conclusion

This race condition prevention solution provides:
- **Reliability**: Prevents overselling and duplicate orders
- **Consistency**: Maintains accurate inventory counts
- **Performance**: Optimized for high-concurrency scenarios
- **Maintainability**: Clear code structure and comprehensive logging

The implementation is production-ready and handles all common race conditions in e-commerce systems.

---

## What is actually live (2026-10-09)

Overselling is prevented by three independent barriers, none of which needs a
stored procedure, a `pending_orders` reservation or a `version` column:

**1. Row locks on the products the order needs** -
`public/authenticated/customer/process_order.php` reads them with
`SELECT id, name, price, stock FROM products WHERE id IN (...) AND status = 'active' ORDER BY id ASC FOR UPDATE`
inside the transaction that was already being opened at the top of the file.
A second concurrent checkout for the same product blocks on that read until the
first commits, then sees the decremented stock and fails the availability check
honestly. `ORDER BY id ASC` fixes the lock order so two multi-item orders
sharing products cannot deadlock - the deduction loop is sorted the same way.

**2. An atomic, conditional deduction** -
`UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?`, then
`rowCount() === 0` aborts the whole order. The database re-proves availability
as part of the write, so this barrier does not depend on the read above being
fresh.

**3. `CHECK (stock >= 0)` on `products.stock`** -
`DBSQL/90_stock_never_negative.sql`. Constraint name
`chk_products_stock_non_negative`. A CHECK, not an UNSIGNED column: this server
runs without `STRICT_TRANS_TABLES`, where UNSIGNED silently clamps an
out-of-range write to 0 instead of raising an error - which would hide
overselling rather than stop it.

All three sit inside the single transaction that writes the order, the order
items, the payment row and the store-credit ledger entry, so a failure on any
item rolls the entire order back. There are no partial orders.

**"Oversold" is a derived report, not a stored status.** Both
`admin/inventory_control.php` and `config/demand_forecast.php` compute it as
`available < 0`, i.e. `stock < 0`. Nothing writes an "oversold" status
anywhere, so there is nothing to remove - once stock cannot go negative, new
orders cannot produce the state. The red chip stays, because a manual stock
edit below what open orders already promise can still legitimately raise it.

**Stock returns** are transactional in all four places that do them:
`cancelPickupOrderAndRestoreStock()` and `cancelExpiredOnlinePaymentOrders()`
in `config/functions.php`, `admin/Order_Cancel_Request.php` (approval), and
`guest/collect.php` (expired pickup QR). The last two also claim their row
conditionally (`AND status = 'pending'` / `AND status <> 'cancelled'`) so a
double submit cannot return the same units twice.

**Tests**: `php execution/test_checkout_race.php` - genuinely parallel
`curl_multi` POSTs against the real endpoint. Verified failing against the
pre-fix code (2 of 2 orders accepted on 1 unit, stock -1) and passing after.
