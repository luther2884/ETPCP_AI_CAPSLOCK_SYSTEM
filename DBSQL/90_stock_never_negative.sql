-- ---------------------------------------------------------------------------
-- 90_stock_never_negative.sql
--
-- Database-level safety net for the checkout overselling bug.
--
-- The application fix lives in public/authenticated/customer/process_order.php:
-- the products rows are read with SELECT ... FOR UPDATE and deducted with
-- `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?` inside
-- the single order transaction. This file is the last line of defence: even a
-- future code path that forgets all of that cannot write a negative stock.
--
-- WHY A CHECK CONSTRAINT AND NOT AN UNSIGNED COLUMN.
-- This server runs with sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,
-- NO_ENGINE_SUBSTITUTION - no STRICT_TRANS_TABLES. An UNSIGNED column outside
-- strict mode does not reject an out-of-range write, it CLAMPS it to 0 with a
-- warning. That would hide overselling instead of stopping it: two racing
-- orders for one unit would both "succeed" and the shelf would read 0. A CHECK
-- constraint is enforced regardless of sql_mode and raises a real error, which
-- rolls the order transaction back. The column type is left exactly as it was.
--
-- Requires MariaDB >= 10.2.1 or MySQL >= 8.0.16 (enforced CHECK constraints).
-- Verified on MariaDB 10.4.32.
-- ---------------------------------------------------------------------------

-- Step 1: inspect. Any row listed here is a product that was oversold before
-- the fix and must be reconciled against the physical shelf.
SELECT id, name, stock, status
FROM products
WHERE stock < 0
ORDER BY id;

-- Step 2: repair. Floor the historical negatives at 0 - the conservative
-- reading, since the real count is whatever is physically on the shelf and the
-- database cannot know that. 0 means "do not sell any more until someone
-- counts"; it never invents inventory. Admin > Product Prices > Add stock is
-- the normal way to correct it upwards after a count.
UPDATE products SET stock = 0, updated_at = NOW() WHERE stock < 0;

-- Step 3: the constraint. Named so it can be dropped by name if ever needed:
--   ALTER TABLE products DROP CONSTRAINT chk_products_stock_non_negative;
ALTER TABLE products
  ADD CONSTRAINT chk_products_stock_non_negative CHECK (stock >= 0);
