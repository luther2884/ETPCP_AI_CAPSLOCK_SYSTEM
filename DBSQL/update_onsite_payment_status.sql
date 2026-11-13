-- Update orders table to support different status options for In-Store Payment
-- File: DBSQL/update_onsite_payment_status.sql

USE `techworld_ecommerce`;

-- First, let's update any existing onsite orders to use only the allowed statuses
-- Change 'packed', 'ready', 'shipped' to 'preparing' for onsite payment orders
UPDATE `orders` 
SET `status` = 'preparing' 
WHERE `payment_method` = 'onsite' 
AND `status` IN ('packed', 'ready', 'shipped');

-- Update the status enum to include all possible statuses
-- We'll keep the full enum but handle the logic in the application
ALTER TABLE `orders` 
MODIFY COLUMN `status` ENUM('preparing','packed','ready','shipped','completed','cancelled') NOT NULL DEFAULT 'preparing';

-- Add a comment to document the status usage
ALTER TABLE `orders` 
MODIFY COLUMN `status` ENUM('preparing','packed','ready','shipped','completed','cancelled') NOT NULL DEFAULT 'preparing' 
COMMENT 'Status: preparing/preparing/preparing/completed/cancelled for onsite payment, full options for delivery';
