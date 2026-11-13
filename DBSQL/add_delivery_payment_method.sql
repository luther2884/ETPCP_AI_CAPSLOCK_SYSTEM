-- Add delivery method and payment method display fields to orders table
-- This allows distinguishing between home delivery and store pickup payment methods
-- File: DBSQL/add_delivery_payment_method.sql

USE `techworld_ecommerce`;

-- Add delivery_method column to store the delivery type
ALTER TABLE `orders` 
ADD COLUMN IF NOT EXISTS `delivery_method` ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery' AFTER `payment_method`;

-- Add payment_method_display column to store the display value for reports
ALTER TABLE `orders` 
ADD COLUMN IF NOT EXISTS `payment_method_display` VARCHAR(50) NOT NULL DEFAULT 'In-Store Payment' AFTER `delivery_method`;

-- Add index for better query performance
ALTER TABLE `orders` 
ADD INDEX IF NOT EXISTS `idx_delivery_method` (`delivery_method`),
ADD INDEX IF NOT EXISTS `idx_payment_method_display` (`payment_method_display`);

-- Update existing records to set proper values based on current payment_method
UPDATE `orders` 
SET 
    `delivery_method` = CASE 
        WHEN `payment_method` = 'cod' THEN 'delivery'
        WHEN `payment_method` = 'onsite' THEN 'pickup'
        ELSE 'delivery'
    END,
    `payment_method_display` = CASE 
        WHEN `payment_method` = 'cod' THEN 'Home Delivery Payment'
        WHEN `payment_method` = 'onsite' THEN 'Store Pickup Payment'
        ELSE 'In-Store Payment'
    END
WHERE `delivery_method` = 'delivery' OR `payment_method_display` = 'In-Store Payment';

-- Add comments to document the new fields
ALTER TABLE `orders` 
MODIFY COLUMN `delivery_method` ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery' COMMENT 'Delivery method: delivery for home delivery, pickup for store pickup',
MODIFY COLUMN `payment_method_display` VARCHAR(50) NOT NULL DEFAULT 'In-Store Payment' COMMENT 'Display value for payment method in reports and UI';
