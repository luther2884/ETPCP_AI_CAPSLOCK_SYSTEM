-- ############################################################################
-- SUPERSEDED - DO NOT RUN THIS FILE.
--
-- Kept only as a record of an earlier schema. Running it against the current
-- database would LOSE DATA. Line 14 below reads:
--
--     ALTER TABLE `orders` MODIFY COLUMN `payment_method`
--         ENUM('cod','onsite','Onsite') NOT NULL;
--
-- but the live column is
--     ENUM('cod','onsite','gcash','paymaya','xendit','store_credit')
-- and orders already exist with gcash, xendit and store_credit. That ALTER
-- would blank or reject every one of them.
--
-- The `order_code` / `expires_at` columns it adds are also dead: pickup
-- verification moved to `pickup_code` / `pickup_code_expires_at` (written by
-- generatePickupCode() in customer/process_order.php, rendered by
-- customer/payment_qr.php, checked by api/verify_pickup.php). The only code
-- that still referenced order_code was a broken block in process_order.php,
-- removed 2026-10-05 - see the note left in its place.
--
-- Migrations that ARE current live in DBSQL/.
-- ############################################################################

-- Add QR code columns to orders table
USE `techworld_ecommerce`;

-- Add order_code column for QR scanning
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `order_code` VARCHAR(20) NULL AFTER `tracking_code`;

-- Add expires_at column for QR code expiry
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `expires_at` TIMESTAMP NULL AFTER `order_code`;

-- Add index for order_code for faster lookups
ALTER TABLE `orders` ADD INDEX IF NOT EXISTS `idx_order_code` (`order_code`);

-- Update payment_method enum to include 'Onsite' (case-sensitive)
ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('cod','onsite','Onsite') NOT NULL;

-- Add delivery_method column if it doesn't exist
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `delivery_method` ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery' AFTER `payment_method`;

-- Add payment_method_display column if it doesn't exist
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `payment_method_display` VARCHAR(100) NULL AFTER `delivery_method`;

-- Add order_hash column if it doesn't exist
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `order_hash` VARCHAR(64) NULL AFTER `payment_method_display`;

-- Add full_name, email, phone, address columns if they don't exist (for legacy compatibility)
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(255) NULL AFTER `order_hash`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `full_name`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) NULL AFTER `email`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone`;

-- Add order_notes column if it doesn't exist
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `order_notes` TEXT NULL AFTER `address`;

-- Add subtotal, shipping, tax, total columns if they don't exist (for legacy compatibility)
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `subtotal` DECIMAL(10,2) NULL AFTER `order_notes`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `shipping` DECIMAL(10,2) NULL AFTER `subtotal`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `tax` DECIMAL(10,2) NULL AFTER `shipping`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `total` DECIMAL(10,2) NULL AFTER `tax`;

-- Add updated_at column if it doesn't exist
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
