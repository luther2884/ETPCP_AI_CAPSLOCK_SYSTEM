-- Create delivery proof photos table
-- This table stores proof of delivery photos for home delivery orders
-- File: DBSQL/create_delivery_proof_table.sql

USE `techworld_ecommerce`;

-- Create delivery_proof_photos table
CREATE TABLE IF NOT EXISTS `delivery_proof_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `photo_path` VARCHAR(255) NOT NULL,
  `photo_type` ENUM('delivery_location', 'customer_receipt', 'package_delivered') NOT NULL,
  `uploaded_by` INT(11) NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_photo_type` (`photo_type`),
  CONSTRAINT `fk_delivery_proof_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delivery_proof_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add delivery_completed_at column to orders table to track when delivery was completed
ALTER TABLE `orders` 
ADD COLUMN IF NOT EXISTS `delivery_completed_at` TIMESTAMP NULL AFTER `updated_at`,
ADD COLUMN IF NOT EXISTS `delivery_completed_by` INT(11) NULL AFTER `delivery_completed_at`,
ADD INDEX IF NOT EXISTS `idx_delivery_completed_at` (`delivery_completed_at`),
ADD CONSTRAINT `fk_orders_delivery_completed_by` FOREIGN KEY (`delivery_completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
