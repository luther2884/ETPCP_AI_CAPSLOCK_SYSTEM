-- add_product_reorder_level.sql
-- Adds the reorder_level column that public/authenticated/admin/inventory_control.php
-- has always queried (COALESCE(p.reorder_level, 5)) but which never existed on the
-- live products table - every query on that page was failing silently (caught by a
-- generic try/catch) and the whole page rendered as empty/zero regardless of real
-- stock or order data.
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `reorder_level` INT(11) NOT NULL DEFAULT 5 AFTER `stock`;

ALTER TABLE `products`
  ADD INDEX IF NOT EXISTS `idx_products_reorder_level` (`reorder_level`);
