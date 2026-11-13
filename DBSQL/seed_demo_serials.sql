-- seed_demo_serials.sql
--
-- DEMO DATA. FAKE SERIAL NUMBERS. NOT REAL UNITS.
--
-- Fills in a serial for every unit of stock on every product that is flagged
-- for unit tracking but has none on file, so the whole packing / scanning /
-- returns flow can be demonstrated end to end without first typing hundreds of
-- numbers off real boxes.
--
-- Every serial this writes is prefixed `TW-` and matches
--
--     TW-<BRAND3>-<product_id>-<NNNN>        e.g.  TW-LNV-515-0001
--
-- which is deliberately nothing like any manufacturer's format, so a fake one
-- can never be mistaken for a real one on a screen, on an invoice, or at the
-- returns counter. `TW-` is also the handle the rollback at the bottom of this
-- file uses to remove every one of them again.
--
-- WHAT THIS IS NOT FOR
--
-- Once real stock is being serialised, these have to go: a fake serial on file
-- is worse than no serial at all, because the returns desk would match a unit
-- this shop cannot actually identify. Run the ROLLBACK section before going
-- live, then enter real serials through the admin Unit Tracking page.
--
-- STOREFRONT STOCK DOES NOT MOVE
--
-- Exactly `p.stock` serials are written per product, so when the mirror is
-- recomputed in step 4 it lands on the number that was already being
-- displayed. Step 5 proves it: it reports any product whose stock changed, and
-- should return no rows.
--
-- Products holding no stock get no serials and stay un-onboarded, which is
-- correct - there is no unit to put a number on. They onboard themselves when
-- stock arrives.
--
-- Re-runnable: INSERT IGNORE skips any serial already on file, and the target
-- list only ever contains products that have no units at all.

USE `techworld_ecommerce`;

-- ---------------------------------------------------------------------------
-- 1. Which products need serials, resolved BEFORE anything is written
-- ---------------------------------------------------------------------------
-- A temporary table rather than one big INSERT ... SELECT over product_units.
--
-- WHY: the eligibility test is "this product has no unit rows". In an
-- INSERT ... SELECT that reads the table it is writing to, MariaDB is not
-- obliged to evaluate that against a stable snapshot - so after the first
-- serial for a product lands, the product can stop qualifying and the
-- remaining rows for it are silently dropped. That failure mode produces one
-- serial per product instead of one per unit, and it looks like it worked.
-- Freezing the target list first removes the question.
DROP TEMPORARY TABLE IF EXISTS `tmp_demo_serial_targets`;
CREATE TEMPORARY TABLE `tmp_demo_serial_targets` (
  `product_id` INT(11) NOT NULL,
  `stock` INT(11) NOT NULL,
  `brand` VARCHAR(3) NOT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_demo_serial_targets` (`product_id`, `stock`, `brand`)
SELECT p.id,
       p.stock,
       -- Three-letter brand code, matched on the name rather than on its first
       -- word: plenty of these products lead with a product line instead of a
       -- maker ("ROG Strix...", "TUF Gaming...", "PRIME B450...", "Ryzen 5...").
       -- Taking the first word would file those under ROG/TUF/PRIME/Ryzen.
       -- Most specific patterns first; the final ELSE keeps anything new
       -- readable instead of dropping it.
       CASE
         WHEN p.name LIKE 'Lenovo%'      OR p.name LIKE '%IdeaPad%'
           OR p.name LIKE '%ThinkPad%'   OR p.name LIKE '%Yoga%'      THEN 'LNV'
         WHEN p.name LIKE 'Acer%'        OR p.name LIKE '%Nitro%'
           OR p.name LIKE '%Predator%'   OR p.name LIKE '%Aspire%'    THEN 'ACR'
         WHEN p.name LIKE 'Asus%'        OR p.name LIKE 'ROG%'
           OR p.name LIKE 'TUF%'         OR p.name LIKE 'PRIME%'      THEN 'ASU'
         WHEN p.name LIKE 'Aorus%'                                    THEN 'AOR'
         WHEN p.name LIKE 'Gigabyte%'                                 THEN 'GBT'
         WHEN p.name LIKE 'MSI%'         OR p.name LIKE '%Ventus%'    THEN 'MSI'
         WHEN p.name LIKE 'ASROCK%'                                   THEN 'ASR'
         WHEN p.name LIKE 'BIOSTAR%'                                  THEN 'BIO'
         WHEN p.name LIKE 'INNO3D%'                                   THEN 'IN3'
         WHEN p.name LIKE 'Palit%'                                    THEN 'PLT'
         WHEN p.name LIKE 'PowerColor%'                               THEN 'PWC'
         WHEN p.name LIKE 'Afox%'                                     THEN 'AFX'
         -- AMD before Intel, and Ryzen/Radeon count as AMD.
         WHEN p.name LIKE 'AMD%'         OR p.name LIKE '%Ryzen%'
           OR p.name LIKE '%Radeon%'                                  THEN 'AMD'
         WHEN p.name LIKE 'Intel%'       OR p.name LIKE '%Core i%'
           OR p.name LIKE '%Celeron%'    OR p.name LIKE '%Pentium%'   THEN 'INT'
         WHEN p.name LIKE 'HP%'                                       THEN 'HP'
         WHEN p.name LIKE 'Dell%'                                     THEN 'DEL'
         WHEN p.name LIKE 'JBL%'                                      THEN 'JBL'
         WHEN p.name LIKE 'ViewPlus%'                                 THEN 'VWP'
         WHEN p.name LIKE 'Machenike%'                                THEN 'MCH'
         WHEN p.name LIKE 'DURATHON%'                                 THEN 'DUR'
         ELSE UPPER(LPAD(LEFT(REGEXP_REPLACE(p.name, '[^A-Za-z]', ''), 3), 3, 'X'))
       END AS brand
  FROM `products` p
 WHERE p.requires_serial = 1
   AND p.stock > 0
   AND NOT EXISTS (SELECT 1 FROM `product_units` pu WHERE pu.product_id = p.id);

SELECT CONCAT('targets: ', COUNT(*), ' products, ', COALESCE(SUM(stock), 0), ' serials to write')
       AS plan
  FROM `tmp_demo_serial_targets`;

-- ---------------------------------------------------------------------------
-- 2. A tally of row numbers to multiply each product by
-- ---------------------------------------------------------------------------
-- 1..64 is far above the largest stock figure in this catalogue (7 at the time
-- of writing). The join below takes only as many rows per product as that
-- product actually holds, so the headroom costs nothing and keeps the file
-- working if stock grows before it is run.
DROP TEMPORARY TABLE IF EXISTS `tmp_demo_seq`;
CREATE TEMPORARY TABLE `tmp_demo_seq` (`seq` INT(11) NOT NULL, PRIMARY KEY (`seq`))
  ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_demo_seq` (`seq`)
SELECT a.d + b.d * 8 + 1
  FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) a
 CROSS JOIN
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) b;

-- ---------------------------------------------------------------------------
-- 3. The serials
-- ---------------------------------------------------------------------------
-- product_id is part of every serial, so `TW-ASU-123-0001` cannot collide with
-- `TW-ASU-124-0001` even though both are an Asus unit number one. IMEI is left
-- NULL throughout: there is no cellular product in this catalogue.
INSERT IGNORE INTO `product_units`
  (`product_id`, `serial_number`, `imei`, `status`, `order_item_id`, `date_added`)
SELECT t.product_id,
       CONCAT('TW-', t.brand, '-', t.product_id, '-', LPAD(n.seq, 4, '0')),
       NULL,
       'in_stock',
       NULL,
       NOW()
  FROM `tmp_demo_serial_targets` t
  JOIN `tmp_demo_seq` n ON n.seq <= t.stock
 ORDER BY t.product_id, n.seq;

-- ---------------------------------------------------------------------------
-- 4. Recompute the mirror
-- ---------------------------------------------------------------------------
-- Identical arithmetic to pu_recount_stock() in config/product_units.php,
-- which maintains it from here on. A no-op for these products by construction
-- (units written == stock), and run anyway so that if it were ever NOT a no-op
-- the next step would say so.
--
-- Scoped to products that have unit rows, so nothing quantity-tracked is
-- touched.
UPDATE `products` p
   SET p.stock = (
         SELECT COUNT(*) FROM `product_units` pu
          WHERE pu.product_id = p.id AND pu.status = 'in_stock'
       ),
       p.updated_at = NOW()
 WHERE p.requires_serial = 1
   AND EXISTS (SELECT 1 FROM `product_units` pu WHERE pu.product_id = p.id);

-- ---------------------------------------------------------------------------
-- 5. Proof that the storefront did not move
-- ---------------------------------------------------------------------------
-- MUST RETURN NO ROWS. Any row here is a product whose displayed stock no
-- longer equals the number of serials written for it.
SELECT t.product_id,
       p.name,
       t.stock AS stock_before,
       p.stock AS stock_after,
       (SELECT COUNT(*) FROM `product_units` pu
         WHERE pu.product_id = p.id AND pu.serial_number LIKE 'TW-%') AS demo_serials
  FROM `tmp_demo_serial_targets` t
  JOIN `products` p ON p.id = t.product_id
 WHERE p.stock <> t.stock;

-- Summary.
SELECT CONCAT('wrote ', COUNT(*), ' demo serials across ',
              COUNT(DISTINCT product_id), ' products') AS result
  FROM `product_units` WHERE `serial_number` LIKE 'TW-%';

SELECT CONCAT('still un-onboarded (no stock to serialise): ', COUNT(*)) AS remaining
  FROM `products` p
 WHERE p.requires_serial = 1
   AND NOT EXISTS (SELECT 1 FROM `product_units` pu WHERE pu.product_id = p.id);

-- A few to read out at the demo.
SELECT pu.serial_number, p.id AS product_id, p.name, p.stock
  FROM `product_units` pu JOIN `products` p ON p.id = pu.product_id
 WHERE pu.serial_number LIKE 'TW-%'
 ORDER BY p.stock DESC, pu.serial_number
 LIMIT 8;

DROP TEMPORARY TABLE IF EXISTS `tmp_demo_serial_targets`;
DROP TEMPORARY TABLE IF EXISTS `tmp_demo_seq`;

-- ===========================================================================
-- ROLLBACK - run this to remove every demo serial again
-- ===========================================================================
-- Uncomment the three statements below and run this file's rollback section on
-- its own. Do it BEFORE entering any real serials.
--
-- Cancel or delete any demo ORDERS first. A unit that a demo order reserved or
-- sold is linked to that order, and removing it leaves the order line with no
-- serial against it - harmless for throwaway data, confusing if left in place.
--
-- Step 1 restores each product's stock to the number of demo serials it was
-- given, which is the figure it held before this file ran (that is the whole
-- point of writing exactly `stock` of them). It has to run BEFORE the delete,
-- while the rows are still there to count.
--
-- UPDATE `products` p
--    SET p.stock = (SELECT COUNT(*) FROM `product_units` pu
--                    WHERE pu.product_id = p.id AND pu.serial_number LIKE 'TW-%'),
--        p.updated_at = NOW()
--  WHERE EXISTS (SELECT 1 FROM `product_units` pu
--                 WHERE pu.product_id = p.id AND pu.serial_number LIKE 'TW-%');
--
-- DELETE FROM `product_units` WHERE `serial_number` LIKE 'TW-%';
--
-- -- Must return no rows: every product that still has units agrees with its
-- -- stock figure, and every product that now has none is back on quantity
-- -- stock.
-- SELECT p.id, p.name, p.stock,
--        (SELECT COUNT(*) FROM `product_units` pu
--          WHERE pu.product_id = p.id AND pu.status = 'in_stock') AS in_stock_units
--   FROM `products` p
--  WHERE p.requires_serial = 1
--    AND EXISTS (SELECT 1 FROM `product_units` pu WHERE pu.product_id = p.id)
--    AND p.stock <> (SELECT COUNT(*) FROM `product_units` pu
--                     WHERE pu.product_id = p.id AND pu.status = 'in_stock');
