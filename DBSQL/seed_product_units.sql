-- seed_product_units.sql
--
-- Test units for six real products, so every feature can be exercised without
-- typing twenty serials by hand. Run AFTER add_product_unit_tracking.sql.
--
-- WHAT THIS DOES TO THE STOREFRONT
--
-- These six products are the first to have tracking go LIVE (see the "tracking
-- is live" note in add_product_unit_tracking.sql), so from here on their
-- `products.stock` is a mirror of their in-stock unit count rather than a
-- number someone typed. Step 3 below sets that mirror once.
--
-- The serial counts were chosen to equal each product's stock as it stood when
-- this file was written, so the mirror lands on the SAME number the storefront
-- already showed and nothing appears to change:
--
--     515  Lenovo IdeaPad Slim 5 14ACH5 82N5001HPH   3
--     511  Lenovo IdeaPad Slim 3 15IAH8              5
--       3  Lenovo V14 G4 ABP                         3
--     481  Gigabyte GeForce RTX 3060 Gaming OC 8G    2
--     109  INNO3D GT 730 2GB SDDR3                   4
--      35  Gigabyte B760M H DDR4                     3
--
-- If stock has moved since (a test order, a restock), the mirror will land on
-- the unit count instead and the difference will show in step 4's output. That
-- is the feature working, not a fault: once a product is tracked, it can only
-- offer units it can identify. Add the missing serials on the admin Unit
-- Tracking page to bring it back up.
--
-- Serials are in each vendor's real format but are NOT real units. The
-- IdeaPad Slim 5 carries a test IMEI on one unit purely so the IMEI
-- validation, search and lookup paths have something to find - this catalogue
-- has no cellular category yet.
--
-- Deletes nothing. Re-runnable: INSERT IGNORE skips serials already on file.

USE `techworld_ecommerce`;

-- ---------------------------------------------------------------------------
-- 1. Guard: only seed products that are actually present
-- ---------------------------------------------------------------------------
-- Resolved by name, not by the hardcoded ids above, so this file also works
-- against a database built fresh from DBSQL/00..75 where the ids differ.
-- A product that is not found simply gets no units.

-- ---------------------------------------------------------------------------
-- 2. The units
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `product_units`
  (`product_id`, `serial_number`, `imei`, `status`, `order_item_id`, `date_added`)
SELECT p.id, u.serial_number, u.imei, 'in_stock', NULL, NOW()
  FROM `products` p
  JOIN (
    -- Lenovo IdeaPad Slim 5 14ACH5 82N5001HPH - the panel's worked example.
    -- One unit carries an IMEI (an LTE-equipped variant) to exercise that path.
    SELECT 'Lenovo IdeaPad Slim 5 14ACH5 82N5001HPH Laptop' AS pname, 'PF3K8L21' AS serial_number, '356938035643809' AS imei
    UNION ALL SELECT 'Lenovo IdeaPad Slim 5 14ACH5 82N5001HPH Laptop', 'PF3K8L22', NULL
    UNION ALL SELECT 'Lenovo IdeaPad Slim 5 14ACH5 82N5001HPH Laptop', 'PF3K8L23', NULL

    -- Lenovo IdeaPad Slim 3 15IAH8
    UNION ALL SELECT 'Lenovo IdeaPad Slim 3 15IAH8 Laptop', 'PF2M4N01', NULL
    UNION ALL SELECT 'Lenovo IdeaPad Slim 3 15IAH8 Laptop', 'PF2M4N02', NULL
    UNION ALL SELECT 'Lenovo IdeaPad Slim 3 15IAH8 Laptop', 'PF2M4N03', NULL
    UNION ALL SELECT 'Lenovo IdeaPad Slim 3 15IAH8 Laptop', 'PF2M4N04', NULL
    UNION ALL SELECT 'Lenovo IdeaPad Slim 3 15IAH8 Laptop', 'PF2M4N05', NULL

    -- Lenovo V14 G4 ABP
    UNION ALL SELECT 'Lenovo V14 G4 ABP Laptop', 'PF1V7G41', NULL
    UNION ALL SELECT 'Lenovo V14 G4 ABP Laptop', 'PF1V7G42', NULL
    UNION ALL SELECT 'Lenovo V14 G4 ABP Laptop', 'PF1V7G43', NULL

    -- Gigabyte RTX 3060 Gaming OC 8G
    UNION ALL SELECT 'Gigabyte GeForce RTX 3060 Gaming OC 8G', 'SN2130607001', NULL
    UNION ALL SELECT 'Gigabyte GeForce RTX 3060 Gaming OC 8G', 'SN2130607002', NULL

    -- INNO3D GT 730 2GB SDDR3
    UNION ALL SELECT 'INNO3D GT 730 2GB SDDR3', 'I3D73020A01', NULL
    UNION ALL SELECT 'INNO3D GT 730 2GB SDDR3', 'I3D73020A02', NULL
    UNION ALL SELECT 'INNO3D GT 730 2GB SDDR3', 'I3D73020A03', NULL
    UNION ALL SELECT 'INNO3D GT 730 2GB SDDR3', 'I3D73020A04', NULL

    -- Gigabyte B760M H DDR4
    UNION ALL SELECT 'Gigabyte B760M H DDR4 Motherboard', 'SN760MH0001', NULL
    UNION ALL SELECT 'Gigabyte B760M H DDR4 Motherboard', 'SN760MH0002', NULL
    UNION ALL SELECT 'Gigabyte B760M H DDR4 Motherboard', 'SN760MH0003', NULL
  ) u ON u.pname = p.name;

-- ---------------------------------------------------------------------------
-- 3. Switch the mirror on for exactly those products
-- ---------------------------------------------------------------------------
-- requires_serial is already 1 for all six (they are Laptops / Graphics Cards
-- / Motherboards, which add_product_unit_tracking.sql flagged), but set it
-- explicitly so this file does not depend on that having happened.
UPDATE `products` p
   SET p.requires_serial = 1
 WHERE EXISTS (SELECT 1 FROM `product_units` pu WHERE pu.product_id = p.id);

-- The mirror itself. Identical arithmetic to pu_recount_stock() in
-- config/product_units.php, which is what maintains it from here on.
UPDATE `products` p
   SET p.stock = (
         SELECT COUNT(*) FROM `product_units` pu
          WHERE pu.product_id = p.id AND pu.status = 'in_stock'
       ),
       p.updated_at = NOW()
 WHERE p.requires_serial = 1
   AND EXISTS (SELECT 1 FROM `product_units` pu WHERE pu.product_id = p.id);

-- ---------------------------------------------------------------------------
-- 4. What just happened
-- ---------------------------------------------------------------------------
-- Read this output. `stock` and `in_stock_units` must match on every row; if
-- a row's stock dropped, that product had sold or restocked since this file
-- was written and is now offering only the units it can identify.
SELECT p.id,
       p.name,
       p.stock                                    AS stock_now,
       SUM(pu.status = 'in_stock')                AS in_stock_units,
       SUM(pu.status = 'reserved')                AS reserved_units,
       SUM(pu.status = 'sold')                    AS sold_units,
       COUNT(pu.unit_id)                          AS total_units
  FROM `products` p
  JOIN `product_units` pu ON pu.product_id = p.id
 GROUP BY p.id, p.name, p.stock
 ORDER BY p.id;

-- How many products are flagged but not yet onboarded. These still run on
-- products.stock and are listed in the admin Unit Tracking banner.
SELECT COUNT(*) AS products_pending_onboarding
  FROM `products` p
 WHERE p.requires_serial = 1
   AND NOT EXISTS (SELECT 1 FROM `product_units` pu WHERE pu.product_id = p.id);
