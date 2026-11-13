-- add_product_unit_tracking.sql
--
-- Per-unit (serial number / IMEI) tracking, so a returned gadget can be proven
-- to be the exact physical unit this shop sold to that customer.
--
-- WHY A SECOND TABLE RATHER THAN COLUMNS ON `products`
--
-- `products` is the MODEL level and stays that way: one row per thing the shop
-- sells, with a price and a catalogue entry. A serial number belongs to one
-- physical object, and a model has many. `product_units` is that second level,
-- one row per object on the shelf.
--
-- Accessories (cables, ink, keyboards) are not worth serialising and keep the
-- existing quantity-based `products.stock`. The `requires_serial` flag below is
-- what separates the two, per product.
--
-- HOW `products.stock` AND THIS TABLE STAY IN AGREEMENT
--
-- `products.stock` is NOT replaced. Roughly twenty-five files read it directly
-- (the guest catalogue, the product page, the cart, the AI Builder, both admin
-- order queues, inventory_control.php, the reports and the Excel export), and
-- rewriting every one of them to COUNT() this table would be a large change
-- with nothing to show for it.
--
-- Instead, for a product whose tracking is live, `products.stock` becomes a
-- DERIVED MIRROR of this table:
--
--     products.stock = COUNT(product_units WHERE status = 'in_stock')
--
-- pu_recount_stock() in config/product_units.php is the only thing that writes
-- it for such a product, and it runs after every unit mutation. So the number
-- the storefront already displays IS the in-stock unit count, and every
-- existing reader keeps working untouched.
--
-- "TRACKING IS LIVE" IS NOT THE SAME AS `requires_serial = 1`
--
-- This migration turns the flag on for six whole categories - 144 products
-- holding 338 units of stock - but only a handful of those products have had
-- their serials typed in. If the flag alone switched the mirror on, 138
-- products would have reported `stock = 0` and gone out of stock on the
-- storefront the moment this file ran, and every checkout for them would have
-- failed with "no unit available".
--
-- So a product is tracked for real only once it HAS at least one unit row:
--
--     requires_serial = 1, 0 unit rows  -> "pending onboarding". Behaves
--                                          exactly as it does today, on
--                                          products.stock. Listed in the
--                                          admin onboarding banner.
--     requires_serial = 1, >= 1 unit    -> tracking live. Mirror, reservation
--                                          at checkout, serial required before
--                                          an order can be marked ready.
--
-- pu_tracking_live() in config/product_units.php is that rule, in one place.
-- Entering the first serial for a product is therefore the switch-over moment,
-- and the admin units page says so before it saves.
--
-- NOT `warranty_registrations`
--
-- That table already holds a UNIQUE `serial_number`, but it is a different
-- fact: a serial the CUSTOMER typed in to register a warranty, after the sale,
-- for a unit this shop never recorded. It is not inventory and it is not
-- authoritative. It is deliberately left alone here; the two can be
-- cross-referenced later if the panel wants warranty lookups by stock unit.
--
-- Additive and idempotent. Creates one table and one column, deletes nothing,
-- and can be run twice.

USE `techworld_ecommerce`;

-- ---------------------------------------------------------------------------
-- 1. The per-unit table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_units` (
  `unit_id` INT(11) NOT NULL AUTO_INCREMENT,
  `product_id` INT(11) NOT NULL COMMENT 'The model this physical unit is one of.',

  -- Required and globally unique. Globally rather than per-product on purpose:
  -- the returns desk is handed a serial off the back of a laptop with no idea
  -- which product it belongs to, so the lookup has to resolve from the serial
  -- alone. A serial that is already on file is therefore always a duplicate,
  -- never "a different product's".
  `serial_number` VARCHAR(100) NOT NULL,

  -- Cellular devices only. NULL for everything else, which in this catalogue
  -- is nearly everything - there is no phone or tablet category yet. MySQL
  -- permits repeated NULLs in a UNIQUE index, so "no IMEI" never collides.
  `imei` VARCHAR(20) DEFAULT NULL COMMENT 'Exactly 15 digits when present. Validated in PHP - see below.',

  `status` ENUM('in_stock','reserved','sold','returned','defective')
      NOT NULL DEFAULT 'in_stock'
      COMMENT 'in_stock = sellable. reserved = claimed by a paid-for order, not yet handed over. sold = gone. returned/defective = back in the building, NOT sellable.',

  -- Which order line this unit went out on. Many units to one line, because
  -- order_items.quantity can be greater than 1.
  --
  -- SET NULL rather than CASCADE: order_items cascades from orders, and if an
  -- order row is ever deleted the unit itself still exists physically. Losing
  -- the link is survivable; deleting the shop's record of the unit is not.
  `order_item_id` INT(11) DEFAULT NULL,

  `date_added` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_sold` TIMESTAMP NULL DEFAULT NULL COMMENT 'Set when the unit becomes sold. The "date sold" the returns desk reads.',

  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `uniq_serial_number` (`serial_number`),
  UNIQUE KEY `uniq_imei` (`imei`),
  -- The mirror's COUNT(*) WHERE product_id = ? AND status = 'in_stock' runs on
  -- every unit mutation and on every reservation, so it gets a covering index
  -- in that column order.
  KEY `idx_product_status` (`product_id`, `status`),
  KEY `idx_order_item_id` (`order_item_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_product_units_product`
      FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_units_order_item`
      FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. The per-product opt-in flag
-- ---------------------------------------------------------------------------
-- Guarded rather than plain ALTER so this file can be re-run. MySQL has no
-- ADD COLUMN IF NOT EXISTS before 8.0.29 / MariaDB 10.0, and XAMPP here is
-- MariaDB, so the check goes through INFORMATION_SCHEMA and a prepared
-- statement.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'requires_serial'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `products`
     ADD COLUMN `requires_serial` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = this model is tracked per physical unit. Tracking only goes LIVE once the product has unit rows - see pu_tracking_live().''
       AFTER `stock`,
     ADD KEY `idx_requires_serial` (`requires_serial`)',
  'SELECT ''products.requires_serial already present - skipped'' AS note'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Turn the flag on for the serialised categories
-- ---------------------------------------------------------------------------
-- Laptops, Desktops, Graphics Cards, Motherboards, Processors, Monitor.
-- Everything else (accessories, ink, printers, networking, peripherals) is
-- left at 0 and can be switched on one product at a time from the admin
-- Unit Tracking page.
--
-- Matched on category NAME, not a hardcoded id: the ids are stable in this
-- database but this file also has to be runnable against a fresh one built
-- from DBSQL/00..75, where they are not.
--
-- This changes NO stock number and takes NO product out of stock - see the
-- "tracking is live" note at the top. These 144 products stay on
-- products.stock until someone enters their first serial.
UPDATE `products` p
   JOIN `categories` c ON c.id = p.category_id
   SET p.requires_serial = 1
 WHERE c.name IN ('Laptops', 'Desktops', 'Graphics Cards', 'Motherboards', 'Processors', 'Monitor')
   AND p.requires_serial = 0;

-- ---------------------------------------------------------------------------
-- 4. Let stock_movements record a restock
-- ---------------------------------------------------------------------------
-- Pre-existing bug, fixed here because this file is already touching the
-- inventory schema. config/functions.php writes
-- `movement_type = 'restock'` into this ENUM, which only accepts in/out/
-- adjustment. The server runs without STRICT_TRANS_TABLES, so the INSERT
-- raised a warning rather than an error, and the surrounding try/catch
-- swallowed it - meaning every pickup-expiry rollback since that code was
-- written has silently failed to log its stock movement.
--
-- Widening the ENUM is the additive half of the fix; the caller is corrected
-- to write 'in' in config/functions.php. 'restock' is accepted too so that any
-- other caller added later cannot reintroduce the silent failure.
SET @mt := (
  SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_movements'
    AND COLUMN_NAME = 'movement_type'
);
SET @ddl2 := IF(@mt IS NOT NULL AND LOCATE('restock', @mt) = 0,
  'ALTER TABLE `stock_movements`
     MODIFY COLUMN `movement_type`
       ENUM(''in'',''out'',''adjustment'',''restock'') NOT NULL',
  'SELECT ''stock_movements.movement_type already accepts restock - skipped'' AS note'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
