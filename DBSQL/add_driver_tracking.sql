-- Add driver assignment to orders + live GPS location table for Home Delivery
-- orders. Supports the Live Delivery Tracking feature on the customer Order
-- Tracking page (public/authenticated/customer/track_order.php).
-- File: DBSQL/add_driver_tracking.sql

USE `techworld_ecommerce`;

-- Assign a Logistics/Driver (users.role = 'driver') to a Home Delivery order.
-- Nullable - most orders have no driver until an admin assigns one.
ALTER TABLE `orders`
ADD COLUMN IF NOT EXISTS `assigned_driver_id` INT(11) NULL AFTER `delivery_completed_by`,
ADD INDEX IF NOT EXISTS `idx_assigned_driver_id` (`assigned_driver_id`),
ADD CONSTRAINT `fk_orders_assigned_driver` FOREIGN KEY (`assigned_driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Single active live-location record per order (MVP: latest fix only, no
-- history table). Every write is an upsert via the UNIQUE key on order_id.
CREATE TABLE IF NOT EXISTS `delivery_tracking` (
  `tracking_id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `driver_id` INT(11) NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `accuracy` DECIMAL(8,2) NULL,
  `is_tracking` TINYINT(1) NOT NULL DEFAULT 1,
  `last_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tracking_id`),
  UNIQUE KEY `uniq_order_id` (`order_id`),
  KEY `idx_driver_id` (`driver_id`),
  KEY `idx_last_updated` (`last_updated`),
  CONSTRAINT `fk_delivery_tracking_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delivery_tracking_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
