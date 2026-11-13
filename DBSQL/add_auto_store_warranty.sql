-- add_auto_store_warranty.sql
--
-- Makes warranty registration automatic: every purchased item gets a 7-day
-- store warranty row the moment the order is placed, with no form to fill in.
--
-- WHY THE COLUMNS BELOW HAVE TO CHANGE
--
-- warranty_registrations was built for a customer typing a manufacturer's
-- serial into a form. Three of its constraints make automatic registration
-- impossible as-is:
--
--  1. serial_number VARCHAR(100) NOT NULL UNIQUE
--     Most of this catalogue is not serialised (cables, ink, keyboards). There
--     is no serial to write for them, and NOT NULL leaves nothing honest to
--     put in the column. Made nullable; the UNIQUE index stays, because a real
--     serial must still be unique and MySQL allows repeated NULLs in one.
--
--  2. warranty_start_date / warranty_end_date DATE NOT NULL
--     The window is NOT known at purchase. A 7-day store warranty has to run
--     from when the customer actually has the item, and this shop's own data
--     says that is not purchase time: completed delivery orders average 28.9
--     hours from order to handover and the slowest took 421 hours - 17.5 days.
--     Anchoring the clock at checkout would have expired that warranty eight
--     days before the box arrived. So the row is created at purchase with the
--     window NULL - "not started yet" - and the dates are filled in when the
--     goods are handed over. Pickup orders average 0.1 hours, so for them the
--     two moments are the same anyway.
--
--  3. Nothing tied a registration to an order LINE or to a physical unit.
--     An order line for 2 laptops needs 2 warranties, one per serial, and
--     re-running registration must not duplicate them.
--
-- Additive: no column is dropped, no row is rewritten, and relaxing NOT NULL
-- cannot invalidate existing data. The table is empty on this database in any
-- case (the manual form has never created a row - it POSTs to
-- /TECHWRLD/api/warranty.php, which does not exist).

USE `techworld_ecommerce`;

-- ---------------------------------------------------------------------------
-- 1. Let the three columns hold "not known yet"
-- ---------------------------------------------------------------------------
-- MODIFY is idempotent by nature - re-running sets the same definition - so
-- these need no existence guard.
ALTER TABLE `warranty_registrations`
  MODIFY COLUMN `serial_number` VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Manufacturer/stock serial when the product is unit-tracked. NULL for anything not serialised.',
  MODIFY COLUMN `warranty_start_date` DATE NULL DEFAULT NULL
    COMMENT 'First day of cover. NULL until the goods are handed over - the clock must not run while the item is in transit.',
  MODIFY COLUMN `warranty_end_date` DATE NULL DEFAULT NULL
    COMMENT 'Last day of cover, = start + warranty_days. NULL until started.';

-- ---------------------------------------------------------------------------
-- 2. Link a registration to the line and the unit it covers
-- ---------------------------------------------------------------------------
SET @c1 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty_registrations'
              AND COLUMN_NAME = 'order_item_id');
SET @ddl := IF(@c1 = 0,
  'ALTER TABLE `warranty_registrations`
     ADD COLUMN `order_item_id` INT(11) DEFAULT NULL
       COMMENT ''The order line this covers. NULL on a manually registered item bought elsewhere.''
       AFTER `order_id`,
     ADD COLUMN `product_unit_id` INT(11) DEFAULT NULL
       COMMENT ''The exact physical unit, when the product is unit-tracked. One warranty per unit.''
       AFTER `order_item_id`,
     ADD COLUMN `warranty_days` INT(11) NOT NULL DEFAULT 7
       COMMENT ''Length of cover in days. Stored per row so the 7-day store warranty and a longer manufacturer one can coexist.''
       AFTER `warranty_end_date`,
     ADD COLUMN `source` ENUM(''auto_purchase'',''manual'') NOT NULL DEFAULT ''manual''
       COMMENT ''auto_purchase = created by checkout. manual = somebody filled in a form.''
       AFTER `warranty_days`,
     ADD KEY `idx_order_item_id` (`order_item_id`),
     /* One warranty per physical unit. This is the duplicate guard that
        matters: re-running registration for an order must not hand the same
        laptop two warranties. Repeated NULLs are allowed, which is what lets
        every non-serialised line through. */
     ADD UNIQUE KEY `uniq_product_unit` (`product_unit_id`),
     ADD CONSTRAINT `fk_warranty_reg_order_item`
       FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE,
     ADD CONSTRAINT `fk_warranty_reg_product_unit`
       FOREIGN KEY (`product_unit_id`) REFERENCES `product_units` (`unit_id`) ON DELETE SET NULL',
  'SELECT ''warranty_registrations already linked to order lines - skipped'' AS note'
);
PREPARE s FROM @ddl;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 3. What it looks like now
-- ---------------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warranty_registrations'
   AND COLUMN_NAME IN ('serial_number','warranty_start_date','warranty_end_date',
                       'order_item_id','product_unit_id','warranty_days','source')
 ORDER BY ORDINAL_POSITION;

SELECT COUNT(*) AS existing_registrations FROM `warranty_registrations`;
