-- Create payments table for detailed payment tracking
-- File: DBSQL/create_payments_table.sql

USE `techworld_ecommerce`;

-- Payments table for detailed payment information
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `payment_method` ENUM('cod','onsite','online','bank_transfer','credit_card','debit_card','digital_wallet') NOT NULL,
  `payment_type` ENUM('home_delivery','store_pickup','online_payment') NOT NULL,
  `payment_status` ENUM('pending','processing','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'PHP',
  `transaction_id` VARCHAR(100) NULL,
  `gateway_reference` VARCHAR(100) NULL,
  `gateway_response` JSON NULL,
  `payment_date` TIMESTAMP NULL,
  `processed_by` INT(11) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_transaction_id` (`transaction_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_payment_method` (`payment_method`),
  KEY `idx_payment_type` (`payment_type`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_processed_by` (`processed_by`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment methods configuration table
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `display_name` VARCHAR(100) NOT NULL,
  `type` ENUM('cash','card','digital','bank') NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `requires_processing` BOOLEAN NOT NULL DEFAULT FALSE,
  `processing_fee` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `min_amount` DECIMAL(10,2) NULL,
  `max_amount` DECIMAL(10,2) NULL,
  `description` TEXT NULL,
  `icon` VARCHAR(255) NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_type` (`type`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default payment methods
INSERT IGNORE INTO `payment_methods` (`code`, `name`, `display_name`, `type`, `requires_processing`, `processing_fee`, `description`, `icon`, `sort_order`) VALUES
('cod_delivery', 'Cash on Delivery - Home Delivery', 'Home Delivery Payment', 'cash', FALSE, 0.00, 'Pay with cash when your order is delivered to your address', 'truck', 1),
('onsite_pickup', 'Pay at Store - Store Pickup', 'Store Pickup Payment', 'cash', FALSE, 0.00, 'Pay with cash when you pick up your order at our store', 'shop', 2),
('online_payment', 'Online Payment', 'Online Payment', 'digital', TRUE, 0.00, 'Pay online using credit card, debit card, or digital wallet', 'credit-card', 3),
('bank_transfer', 'Bank Transfer', 'Bank Transfer', 'bank', TRUE, 0.00, 'Transfer payment directly to our bank account', 'bank', 4),
('credit_card', 'Credit Card', 'Credit Card', 'card', TRUE, 2.50, 'Pay using your credit card', 'credit-card', 5),
('debit_card', 'Debit Card', 'Debit Card', 'card', TRUE, 1.50, 'Pay using your debit card', 'debit-card', 6),
('gcash', 'GCash', 'GCash', 'digital', TRUE, 0.00, 'Pay using your GCash wallet', 'mobile', 7),
('paymaya', 'PayMaya', 'PayMaya', 'digital', TRUE, 0.00, 'Pay using your PayMaya wallet', 'mobile', 8);

-- Payment transactions log for audit trail
CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `payment_id` INT(11) NOT NULL,
  `transaction_type` ENUM('payment','refund','partial_refund','chargeback','dispute') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'PHP',
  `gateway_transaction_id` VARCHAR(100) NULL,
  `gateway_response` JSON NULL,
  `status` ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `processed_at` TIMESTAMP NULL,
  `processed_by` INT(11) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_status` (`status`),
  KEY `idx_processed_by` (`processed_by`),
  CONSTRAINT `fk_payment_transactions_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_transactions_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comments to document the tables
ALTER TABLE `payments` 
MODIFY COLUMN `payment_method` ENUM('cod','onsite','online','bank_transfer','credit_card','debit_card','digital_wallet') NOT NULL COMMENT 'Technical payment method code',
MODIFY COLUMN `payment_type` ENUM('home_delivery','store_pickup','online_payment') NOT NULL COMMENT 'Payment type for categorization',
MODIFY COLUMN `payment_status` ENUM('pending','processing','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'pending' COMMENT 'Current status of the payment';

-- Create indexes for better performance
CREATE INDEX `idx_payments_composite` ON `payments` (`order_id`, `payment_status`);
CREATE INDEX `idx_payments_date_status` ON `payments` (`payment_date`, `payment_status`);
CREATE INDEX `idx_payments_user_status` ON `payments` (`user_id`, `payment_status`);
