-- add_inventory_stock_control.sql
--
-- Stock In (deliveries), Stock Out (defective / pull-out with Owner approval),
-- slow-moving flagging, and a complete movement history - all per PHYSICAL
-- UNIT, on top of the existing Inventory Control module.
--
-- WHAT THIS BUILDS ON RATHER THAN REPLACES
--
--   product_units        already exists (DBSQL/add_product_unit_tracking.sql).
--                        One row per physical gadget, with serial/IMEI. This
--                        file adds the two things it was missing for these
--                        features: which delivery it arrived on, and a
--                        'pulled_out' terminal state.
--   stock_movements      already exists but was thin - no user, no serial, no
--                        reason, and a 4-value movement_type. Widened here
--                        rather than replaced, so the 19 rows already in it
--                        keep working.
--   inventory_settings   already the key/value store the Inventory Control
--                        module reads (default_lead_time_days, max_stock_days,
--                        ...). The slow-moving threshold joins it there rather
--                        than becoming a new settings table.
--   admin_requests       the approval shape this shop already uses:
--                        pending/approved/denied + reviewed_by + review_notes
--                        + reviewed_at. pull_out_requests copies it so the
--                        Owner's approval screens read the same way.
--
-- WHY 'discontinued' GOES IN products.status
--
-- The brief says flagged items must not be suggested by the AI recommender or
-- the PC configurator. Both already filter `p.status = 'active'` - the
-- recommender at config/product_recommendations.php:87 and :123, the
-- configurator at config/ai_builder_engine.php:400, :435 and :569 - as does
-- every storefront listing. Adding a THIRD status value therefore excludes a
-- discontinued product from all of them at once, with no change to either
-- file and no new filter to keep in step. Verified: nothing in the codebase
-- filters `status != 'inactive'`, which would have defeated it.
--
-- Additive and idempotent. No column is dropped, no row is rewritten.

USE `techworld_ecommerce`;

-- ===========================================================================
-- 1. Suppliers
-- ===========================================================================
-- A real table rather than free text on the delivery, because two of the
-- required reports group BY supplier ("deliveries received per supplier",
-- "defective units by ... supplier") and free text does not group - "Asus",
-- "ASUS" and "Asus Phils" would be three suppliers.
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_supplier_name` (`name`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 2. Stock In - deliveries
-- ===========================================================================
-- Header + lines, because one delivery note covers many products and the
-- report has to be able to say "this DR, this supplier, this date, received by
-- this person" once rather than repeated on every line.
CREATE TABLE IF NOT EXISTS `stock_deliveries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` INT(11) DEFAULT NULL,
  `dr_number` VARCHAR(60) NOT NULL COMMENT 'Supplier delivery receipt number.',
  `delivery_date` DATE NOT NULL,
  `received_by` INT(11) DEFAULT NULL COMMENT 'The signed-in staff member who received it.',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One DR number per supplier. The same number from two different suppliers
  -- is perfectly normal, so this is NOT a global unique on dr_number.
  UNIQUE KEY `uniq_supplier_dr` (`supplier_id`, `dr_number`),
  KEY `idx_delivery_date` (`delivery_date`),
  KEY `idx_received_by` (`received_by`),
  CONSTRAINT `fk_deliveries_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_delivery_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `delivery_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_id` (`delivery_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_delivery_items_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `stock_deliveries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delivery_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 3. Pull-out requests - the Owner approval flow
-- ===========================================================================
-- Shaped on admin_requests (pending/approved/denied + reviewed_by +
-- review_notes + reviewed_at) so the Owner's screens read consistently.
--
-- The final action is on the REQUEST, not the unit: "Returned to Supplier"
-- happens to a batch on one RMA, and recording it per unit would invite three
-- units of one request to disagree about what happened to them.
CREATE TABLE IF NOT EXISTS `pull_out_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(30) NOT NULL COMMENT 'Human reference, PO-YYYYMMDD-XXXX.',
  `requested_by` INT(11) DEFAULT NULL COMMENT 'The Manager who raised it.',
  `source` ENUM('bad_order_delivery','found_in_stock','customer_return','slow_moving')
      NOT NULL COMMENT 'Where the defect came from. slow_moving is raised from the Flagged Items list.',
  `reason` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT(11) DEFAULT NULL COMMENT 'Super Admin (Owner) who approved or rejected.',
  `review_notes` TEXT DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `final_action` ENUM('returned_to_supplier','replaced','disposed') DEFAULT NULL
      COMMENT 'Recorded only after approval.',
  `final_action_by` INT(11) DEFAULT NULL,
  `final_action_at` TIMESTAMP NULL DEFAULT NULL,
  `final_action_notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pullout_ref` (`reference_no`),
  KEY `idx_status` (`status`),
  KEY `idx_source` (`source`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `idx_reviewed_by` (`reviewed_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_pullout_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pullout_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pullout_final_by` FOREIGN KEY (`final_action_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pull_out_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pull_out_id` INT(11) NOT NULL,
  `product_unit_id` INT(11) DEFAULT NULL COMMENT 'The physical unit. NULL only if the unit row is later deleted.',
  `product_id` INT(11) NOT NULL,
  -- Snapshotted at the time of the pull-out. The unit row can be deleted and
  -- the product renamed; a pull-out record that can no longer say WHICH serial
  -- left the building is worthless as a record.
  `serial_number` VARCHAR(100) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One unit cannot be on two pull-outs at once.
  UNIQUE KEY `uniq_unit_per_pullout` (`product_unit_id`),
  KEY `idx_pull_out_id` (`pull_out_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_pullout_items_request` FOREIGN KEY (`pull_out_id`) REFERENCES `pull_out_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pullout_items_unit` FOREIGN KEY (`product_unit_id`) REFERENCES `product_units` (`unit_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pullout_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
-- 4. products: brand, and the Discontinued status
-- ===========================================================================
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'brand');
SET @ddl := IF(@c = 0,
  'ALTER TABLE `products`
     ADD COLUMN `brand` VARCHAR(60) DEFAULT NULL
       COMMENT ''Maker. Every required report groups by it; backfilled from the product name below.''
       AFTER `name`,
     ADD COLUMN `discontinued_at` TIMESTAMP NULL DEFAULT NULL
       COMMENT ''When it was marked Discontinued. Drives the slow-moving flag with inventory_settings.slow_moving_days.''
       AFTER `status`,
     ADD KEY `idx_brand` (`brand`)',
  'SELECT ''products.brand already present - skipped'' AS note');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- The third status value. MODIFY is idempotent.
ALTER TABLE `products`
  MODIFY COLUMN `status` ENUM('active','inactive','discontinued') NOT NULL DEFAULT 'active'
    COMMENT 'discontinued is excluded from the storefront, the AI recommender and the configurator, all of which filter status = active.';

-- Backfill brand from the product name. Same mapping as
-- DBSQL/seed_demo_serials.sql, and matched on the whole name rather than its
-- first word for the same reason: plenty of these lead with a product line
-- ("ROG Strix...", "TUF Gaming...", "PRIME B450...", "Ryzen 5...") and taking
-- the first word would file those under ROG/TUF/PRIME/Ryzen. Anything
-- unmatched is left NULL for staff to fill in rather than guessed at.
UPDATE `products` SET `brand` = CASE
    WHEN `name` LIKE 'Lenovo%'   OR `name` LIKE '%IdeaPad%' OR `name` LIKE '%ThinkPad%'
      OR `name` LIKE '%Yoga%'                                              THEN 'Lenovo'
    WHEN `name` LIKE 'Acer%'     OR `name` LIKE '%Nitro%'   OR `name` LIKE '%Predator%'
      OR `name` LIKE '%Aspire%'  OR `name` LIKE '%TravelMate%'             THEN 'Acer'
    WHEN `name` LIKE 'Asus%'     OR `name` LIKE 'ROG%'      OR `name` LIKE 'TUF%'
      OR `name` LIKE 'PRIME%'                                              THEN 'Asus'
    WHEN `name` LIKE 'Aorus%'    OR `name` LIKE 'Gigabyte%'                THEN 'Gigabyte'
    WHEN `name` LIKE 'MSI%'      OR `name` LIKE '%Ventus%'                 THEN 'MSI'
    WHEN `name` LIKE 'ASROCK%'                                             THEN 'ASRock'
    WHEN `name` LIKE 'BIOSTAR%'                                            THEN 'Biostar'
    WHEN `name` LIKE 'INNO3D%'                                             THEN 'INNO3D'
    WHEN `name` LIKE 'Palit%'                                              THEN 'Palit'
    WHEN `name` LIKE 'PowerColor%'                                         THEN 'PowerColor'
    WHEN `name` LIKE 'Afox%'                                               THEN 'AFOX'
    WHEN `name` LIKE 'AMD%'      OR `name` LIKE '%Ryzen%'   OR `name` LIKE '%Radeon%' THEN 'AMD'
    WHEN `name` LIKE 'Intel%'    OR `name` LIKE '%Core i%'  OR `name` LIKE '%Celeron%'
      OR `name` LIKE '%Pentium%'                                           THEN 'Intel'
    WHEN `name` LIKE 'HP%'                                                 THEN 'HP'
    WHEN `name` LIKE 'Dell%'                                               THEN 'Dell'
    WHEN `name` LIKE 'JBL%'                                                THEN 'JBL'
    WHEN `name` LIKE 'A4tech%'                                             THEN 'A4tech'
    WHEN `name` LIKE 'Rapoo%'                                              THEN 'Rapoo'
    WHEN `name` LIKE 'Aula%'                                               THEN 'Aula'
    WHEN `name` LIKE 'Logitech%'                                           THEN 'Logitech'
    WHEN `name` LIKE 'Redragon%'                                           THEN 'Redragon'
    WHEN `name` LIKE 'ViewPlus%'                                           THEN 'ViewPlus'
    WHEN `name` LIKE 'Machenike%'                                          THEN 'Machenike'
    WHEN `name` LIKE 'TeamGroup%' OR `name` LIKE '%T.Force%'               THEN 'TeamGroup'
    WHEN `name` LIKE 'Kingston%'                                           THEN 'Kingston'
    WHEN `name` LIKE 'Seagate%'                                            THEN 'Seagate'
    WHEN `name` LIKE 'WD %'      OR `name` LIKE 'Western Digital%'         THEN 'Western Digital'
    WHEN `name` LIKE 'Hikvision%'                                          THEN 'Hikvision'
    WHEN `name` LIKE 'Dahua%'                                              THEN 'Dahua'
    WHEN `name` LIKE 'Epson%'                                              THEN 'Epson'
    WHEN `name` LIKE 'Canon%'                                              THEN 'Canon'
    WHEN `name` LIKE 'Brother%'                                            THEN 'Brother'
    WHEN `name` LIKE 'TP-Link%' OR `name` LIKE 'TPLink%'                   THEN 'TP-Link'
    ELSE `brand`
  END
WHERE `brand` IS NULL;

-- ===========================================================================
-- 5. product_units: where it came from, and the pulled-out state
-- ===========================================================================
SET @c2 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_units' AND COLUMN_NAME = 'delivery_id');
SET @ddl2 := IF(@c2 = 0,
  'ALTER TABLE `product_units`
     ADD COLUMN `delivery_id` INT(11) DEFAULT NULL
       COMMENT ''The delivery this unit arrived on. Without it the Defective report cannot group by supplier.''
       AFTER `order_item_id`,
     ADD KEY `idx_delivery_id` (`delivery_id`),
     ADD CONSTRAINT `fk_product_units_delivery`
       FOREIGN KEY (`delivery_id`) REFERENCES `stock_deliveries` (`id`) ON DELETE SET NULL',
  'SELECT ''product_units.delivery_id already present - skipped'' AS note');
PREPARE s2 FROM @ddl2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- 'pulled_out' is the terminal state after an approved pull-out's final action
-- is recorded. 'defective' stays what it means today: faulty and off the
-- shelf, but still physically here and not yet dealt with.
ALTER TABLE `product_units`
  MODIFY COLUMN `status` ENUM('in_stock','reserved','sold','returned','defective','pulled_out')
    NOT NULL DEFAULT 'in_stock'
    COMMENT 'in_stock = sellable. reserved = claimed by an order. sold = gone. returned/defective = back with us, not sellable. pulled_out = left the building on an approved pull-out.';

-- ===========================================================================
-- 6. stock_movements: the complete history the brief asks for
-- ===========================================================================
-- Widened, not replaced - the 19 rows already in it stay valid. The legacy
-- in/out/adjustment/restock values are kept alongside the seven the brief
-- names, so nothing that writes this table today breaks.
ALTER TABLE `stock_movements`
  MODIFY COLUMN `movement_type` ENUM(
      'in','out','adjustment','restock',
      'stock_in','sold','defective','pull_out','returned_to_supplier','disposed','customer_return'
    ) NOT NULL COMMENT 'The first four are legacy values already on live rows; the rest are the movement types the inventory module records.';

SET @c3 := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'user_id');
SET @ddl3 := IF(@c3 = 0,
  'ALTER TABLE `stock_movements`
     ADD COLUMN `product_unit_id` INT(11) DEFAULT NULL
       COMMENT ''The physical unit, when the movement is about one. NULL for a quantity-only movement.''
       AFTER `product_id`,
     ADD COLUMN `serial_number` VARCHAR(100) DEFAULT NULL
       COMMENT ''Snapshot. The history has to stay readable after a unit row is deleted.''
       AFTER `product_unit_id`,
     ADD COLUMN `user_id` INT(11) DEFAULT NULL
       COMMENT ''Who did it. NULL for a system sweep.''
       AFTER `quantity`,
     ADD COLUMN `reason` VARCHAR(255) DEFAULT NULL AFTER `user_id`,
     ADD COLUMN `reference_no` VARCHAR(60) DEFAULT NULL
       COMMENT ''DR number, pull-out reference, or invoice number.''
       AFTER `reason`,
     ADD KEY `idx_product_unit_id` (`product_unit_id`),
     ADD KEY `idx_user_id` (`user_id`),
     ADD KEY `idx_created_at` (`created_at`),
     ADD KEY `idx_type_created` (`movement_type`, `created_at`),
     ADD CONSTRAINT `fk_stock_movements_unit`
       FOREIGN KEY (`product_unit_id`) REFERENCES `product_units` (`unit_id`) ON DELETE SET NULL,
     ADD CONSTRAINT `fk_stock_movements_user`
       FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT ''stock_movements already widened - skipped'' AS note');
PREPARE s3 FROM @ddl3; EXECUTE s3; DEALLOCATE PREPARE s3;

-- ===========================================================================
-- 7. The slow-moving threshold, in the settings table that already exists
-- ===========================================================================
-- Only the Super Admin (Owner) may change this - enforced in PHP, see
-- inv_set_slow_moving_days() in config/inventory_stock.php.
INSERT INTO `inventory_settings` (`setting_key`, `setting_value`, `description`)
VALUES ('slow_moving_days', '90',
        'Days without a sale before a Discontinued product holding stock is flagged "Poorly Sold - For Pull-Out". Super Admin only.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ===========================================================================
-- 8. What it looks like now
-- ===========================================================================
SELECT 'suppliers' t, COUNT(*) rows_ FROM `suppliers`
UNION ALL SELECT 'stock_deliveries', COUNT(*) FROM `stock_deliveries`
UNION ALL SELECT 'stock_delivery_items', COUNT(*) FROM `stock_delivery_items`
UNION ALL SELECT 'pull_out_requests', COUNT(*) FROM `pull_out_requests`
UNION ALL SELECT 'pull_out_items', COUNT(*) FROM `pull_out_items`
UNION ALL SELECT 'stock_movements (existing kept)', COUNT(*) FROM `stock_movements`
UNION ALL SELECT 'products with a brand', COUNT(*) FROM `products` WHERE `brand` IS NOT NULL
UNION ALL SELECT 'products without a brand', COUNT(*) FROM `products` WHERE `brand` IS NULL;

SELECT `setting_key`, `setting_value` FROM `inventory_settings` WHERE `setting_key` = 'slow_moving_days';
