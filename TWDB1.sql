-- =====================================================
-- TECHWORLD ECOMMERCE DATABASE - COMPLETE STRUCTURE
-- =====================================================
-- This file contains the complete database structure for TechWorld E-commerce system
-- Import this file to create the entire database with all tables, data, and configurations
-- 
-- IMPORTANT NOTES:
-- 1. This file should be imported in a fresh database
-- 2. All dependencies are resolved and tables are created in proper order
-- 3. No duplicate definitions or conflicting structures
-- 4. All foreign key constraints are properly defined
-- =====================================================

-- Database Configuration
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `techworld_ecommerce`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `techworld_ecommerce`;

-- =====================================================
-- 1. CORE USER MANAGEMENT TABLES
-- =====================================================

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `firstname` VARCHAR(50) NOT NULL,
  `lastname` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('customer','driver','admin','super-admin') NOT NULL DEFAULT 'customer',
  `status` ENUM('active','inactive','pending','denied') NOT NULL DEFAULT 'active',
  `address` TEXT,
  `phone` VARCHAR(20),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User profile metadata (avatar path, bio)
CREATE TABLE IF NOT EXISTS `user_profiles` (
  `user_id` INT(11) NOT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
   `photo_blob` LONGBLOB,
  `photo_mime` VARCHAR(100) DEFAULT NULL,
  `photo_updated_at` TIMESTAMP NULL DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_photo_updated_at` (`photo_updated_at`),
  CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User delivery addresses
CREATE TABLE IF NOT EXISTS `user_addresses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `region` VARCHAR(255) NOT NULL,
  `postal` VARCHAR(20) DEFAULT NULL,
  `street` VARCHAR(255) NOT NULL,
  `label` VARCHAR(30) DEFAULT 'Home',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_addresses_user_id` (`user_id`),
  CONSTRAINT `fk_user_addresses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin approval requests
CREATE TABLE IF NOT EXISTS `admin_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `request_message` TEXT NOT NULL,
  `status` ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT(11),
  `review_notes` TEXT,
  `reviewed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_admin_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_requests_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manager invitations for 10-minute password setup
CREATE TABLE IF NOT EXISTS `manager_invitations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_manager_invitations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  -- A CATEGORY, not a severity. Every createNotification() call passes one of
  -- the first five ('order_status', 'security', 'system'); the four severity
  -- values are kept only so older rows that used them stay valid. Matches the
  -- ALTER in DBSQL/80_notifications.sql and notification_templates below.
  `type` ENUM('order_status','payment','promotional','security','system',
              'info','success','warning','error') NOT NULL DEFAULT 'order_status',
  `is_read` BOOLEAN NOT NULL DEFAULT FALSE,
  `data` JSON NULL,
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `expires_at` DATETIME NULL,
  `read_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11),
  `action` VARCHAR(100) NOT NULL,
  `table_name` VARCHAR(50),
  `record_id` INT(11),
  `old_values` JSON,
  `new_values` JSON,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_table_name` (`table_name`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset tokens
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `attempts` INT(11) NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. PRODUCT CATALOG TABLES
-- =====================================================

-- Categories
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `image_url` VARCHAR(255),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags table
CREATE TABLE IF NOT EXISTS `tags` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `description` TEXT,
  `color` VARCHAR(7) DEFAULT '#3b82f6',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction table for category-tag relationships
CREATE TABLE IF NOT EXISTS `category_tags` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `category_id` INT(11) NOT NULL,
  `tag_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_category_tag` (`category_id`, `tag_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_tag_id` (`tag_id`),
  CONSTRAINT `fk_category_tags_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_category_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock` INT(11) NOT NULL DEFAULT 0,
  `category_id` INT(11),
  `image_url` VARCHAR(255),
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `version` INT(11) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_stock` (`stock`),
  KEY `idx_products_stock_status` (`stock`, `status`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviews
CREATE TABLE IF NOT EXISTS `reviews` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `rating` TINYINT NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
  `title` VARCHAR(255) NULL,
  `comment` TEXT,
  `is_verified_purchase` BOOLEAN DEFAULT FALSE,
  `is_approved` BOOLEAN DEFAULT TRUE,
  `helpful_count` INT DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_rating` (`rating`),
  KEY `idx_is_approved` (`is_approved`),
  CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review helpfulness tracking
CREATE TABLE IF NOT EXISTS `review_helpfulness` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `review_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `is_helpful` BOOLEAN NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_user_review` (`user_id`, `review_id`),
  CONSTRAINT `fk_review_helpfulness_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_helpfulness_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wishlists
CREATE TABLE IF NOT EXISTS `wishlists` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_product` (`user_id`, `product_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_wishlists_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlists_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. SHOPPING CART TABLES
-- =====================================================

-- User shopping carts (persistent cart storage)
CREATE TABLE IF NOT EXISTS `user_carts` (
  `user_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `product_id`),
  KEY `idx_user_carts_user_id` (`user_id`),
  KEY `idx_user_carts_product_id` (`product_id`),
  KEY `idx_user_carts_updated_at` (`updated_at`),
  KEY `idx_user_carts_composite` (`user_id`, `updated_at` DESC),
  CONSTRAINT `fk_user_carts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_carts_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_user_carts_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. ORDER MANAGEMENT TABLES
-- =====================================================

-- Orders
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `invoice_no` VARCHAR(50) NOT NULL,
  `tracking_code` VARCHAR(50) NOT NULL,
  `pickup_code` VARCHAR(6) NOT NULL,
  `pickup_code_expires_at` TIMESTAMP NOT NULL,
  `pickup_verified_at` TIMESTAMP NULL,
  `pickup_verified_by` INT(11),
  `order_hash` VARCHAR(64) NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('cod','onsite') NOT NULL,
  `delivery_method` ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
  `payment_method_display` VARCHAR(50) NOT NULL DEFAULT 'In-Store Payment',
  `status` ENUM('preparing','packed','ready','shipped','completed','cancelled') NOT NULL DEFAULT 'preparing',
  `notes` TEXT,
  `delivery_completed_at` TIMESTAMP NULL,
  `delivery_completed_by` INT(11) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_no` (`invoice_no`),
  UNIQUE KEY `uniq_tracking_code` (`tracking_code`),
  UNIQUE KEY `uniq_order_hash` (`order_hash`),
  KEY `idx_order_hash` (`order_hash`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_pickup_code` (`pickup_code`),
  KEY `idx_pickup_verified_by` (`pickup_verified_by`),
  KEY `idx_delivery_method` (`delivery_method`),
  KEY `idx_payment_method_display` (`payment_method_display`),
  KEY `idx_delivery_completed_at` (`delivery_completed_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_orders_verified_by` FOREIGN KEY (`pickup_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_delivery_completed_by` FOREIGN KEY (`delivery_completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order items
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `total` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order cancellation requests
CREATE TABLE IF NOT EXISTS `order_cancellation_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `custom_reason` TEXT NULL,
  `additional_notes` TEXT NULL,
  `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  `admin_notes` TEXT NULL,
  `admin_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL,
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_order_cancellation_requests_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_cancellation_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_cancellation_requests_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. PAYMENT MANAGEMENT TABLES
-- =====================================================

-- Payment methods configuration table
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `display_name` VARCHAR(100) NOT NULL,
  `type` ENUM('cash') NOT NULL,
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

-- Payments table for detailed payment information
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `payment_method` ENUM('cod','onsite') NOT NULL,
  `payment_type` ENUM('home_delivery','store_pickup') NOT NULL,
  `payment_status` ENUM('pending','processing','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `amount` DECIMAL(10,2) NOT NULL,
  `transaction_id` VARCHAR(100) NULL,
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
  KEY `idx_payments_composite` (`order_id`, `payment_status`),
  KEY `idx_payments_date_status` (`payment_date`, `payment_status`),
  KEY `idx_payments_user_status` (`user_id`, `payment_status`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment transactions log for audit trail
CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `payment_id` INT(11) NOT NULL,
  `transaction_type` ENUM('payment','refund','partial_refund','chargeback','dispute') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
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

-- =====================================================
-- 6. INVENTORY MANAGEMENT TABLES
-- =====================================================

-- Stock movements
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `product_id` INT(11) NOT NULL,
  `movement_type` ENUM('in','out','adjustment') NOT NULL,
  `quantity` INT(11) NOT NULL,
  `reference_type` VARCHAR(50),
  `reference_id` INT(11),
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_movement_type` (`movement_type`),
  CONSTRAINT `fk_stock_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics tab uploads (Reports & Analytics > Analytics): catalogs .xlsx
-- spreadsheets admins upload for in-browser preview/comparison against the live
-- charts. Metadata + safe storage only - no live Power BI Service refresh is
-- performed or implied by this table. archived_at is set/cleared by the
-- Archive/Unarchive buttons - a reversible way to hide old uploads from the
-- main list without deleting the file or DB row.
CREATE TABLE IF NOT EXISTS `analytics_uploads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_type` ENUM('xlsx','pbix') NOT NULL,
  `file_size` INT(11) NOT NULL,
  `uploaded_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_analytics_uploads_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. SUPPORT AND SERVICE TABLES
-- =====================================================

-- Support tickets
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `assigned_to` INT(11),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_to` (`assigned_to`),
  CONSTRAINT `fk_support_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_tickets_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Support Ticket Messages
CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `message` TEXT NOT NULL,
  `is_internal` BOOLEAN NOT NULL DEFAULT FALSE,
  `attachments` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_support_messages_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warranty Claims
CREATE TABLE IF NOT EXISTS `warranty_claims` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `order_id` INT(11) NULL,
  `product_id` INT(11) NOT NULL,
  `claim_number` VARCHAR(20) NOT NULL UNIQUE,
  `issue_description` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected','in_progress','completed') NOT NULL DEFAULT 'pending',
  `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `assigned_to` INT(11) NULL,
  `resolution_notes` TEXT NULL,
  `resolution_type` ENUM('repair','replacement','refund','denied') NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL,
  `resolved_by` INT(11) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_claim_number` (`claim_number`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_warranty_claims_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_warranty_claims_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_warranty_claims_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_warranty_claims_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_warranty_claims_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warranty Registrations
CREATE TABLE IF NOT EXISTS `warranty_registrations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `order_id` INT(11) NULL,
  `product_id` INT(11) NOT NULL,
  `serial_number` VARCHAR(100) NOT NULL,
  `purchase_date` DATE NOT NULL,
  `registration_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `warranty_start_date` DATE NOT NULL,
  `warranty_end_date` DATE NOT NULL,
  `extended_warranty` BOOLEAN NOT NULL DEFAULT FALSE,
  `extended_years` INT(11) NULL,
  `status` ENUM('active','expired','voided') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_serial_number` (`serial_number`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_warranty_end_date` (`warranty_end_date`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_warranty_registrations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_warranty_registrations_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_warranty_registrations_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Returns
CREATE TABLE IF NOT EXISTS `return_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `order_id` INT(11) NOT NULL,
  `return_number` VARCHAR(20) NOT NULL UNIQUE,
  `reason` ENUM('defective','wrong_item','not_as_described','changed_mind','other') NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected','in_progress','completed') NOT NULL DEFAULT 'pending',
  `return_type` ENUM('refund','exchange') NOT NULL DEFAULT 'refund',
  `exchange_product_id` INT(11) NULL,
  `return_shipping_label` VARCHAR(255) NULL,
  `tracking_number` VARCHAR(100) NULL,
  `received_at` TIMESTAMP NULL,
  `processed_at` TIMESTAMP NULL,
  `refund_amount` DECIMAL(10,2) NULL,
  `refund_method` ENUM('original_payment','store_credit') NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `processed_by` INT(11) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_return_number` (`return_number`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_exchange_product_id` (`exchange_product_id`),
  KEY `idx_processed_by` (`processed_by`),
  CONSTRAINT `fk_return_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_requests_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_requests_exchange_product` FOREIGN KEY (`exchange_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_return_requests_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `return_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `return_id` INT(11) NOT NULL,
  `order_item_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL,
  `condition` ENUM('new','like_new','good','fair','poor') NOT NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_return_id` (`return_id`),
  KEY `idx_order_item_id` (`order_item_id`),
  CONSTRAINT `fk_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `return_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FAQ
CREATE TABLE IF NOT EXISTS `faq_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `faq_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `category_id` INT(11) NOT NULL,
  `question` TEXT NOT NULL,
  `answer` TEXT NOT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `view_count` INT(11) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_view_count` (`view_count`),
  CONSTRAINT `fk_faq_items_category` FOREIGN KEY (`category_id`) REFERENCES `faq_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Knowledge Base & Settings
CREATE TABLE IF NOT EXISTS `knowledge_base` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `tags` JSON NULL,
  `is_published` BOOLEAN NOT NULL DEFAULT FALSE,
  `view_count` INT(11) NOT NULL DEFAULT 0,
  `created_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_is_published` (`is_published`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_view_count` (`view_count`),
  CONSTRAINT `fk_knowledge_base_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NOT NULL,
  `description` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 8. DELIVERY AND LOGISTICS TABLES
-- =====================================================

-- Delivery proof photos table
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

-- =====================================================
-- 9. NOTIFICATION SYSTEM TABLES
-- =====================================================

-- Notification templates
CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type` ENUM('order_status', 'payment', 'promotional', 'security', 'system') NOT NULL,
  `event` VARCHAR(100) NOT NULL,
  `title_template` VARCHAR(255) NOT NULL,
  `message_template` TEXT NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_type_event` (`type`, `event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User notification preferences
CREATE TABLE IF NOT EXISTS `user_notification_preferences` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `email_notifications` BOOLEAN DEFAULT TRUE,
  `push_notifications` BOOLEAN DEFAULT TRUE,
  `sms_notifications` BOOLEAN DEFAULT FALSE,
  `order_updates` BOOLEAN DEFAULT TRUE,
  `payment_alerts` BOOLEAN DEFAULT TRUE,
  `promotional_offers` BOOLEAN DEFAULT TRUE,
  `security_alerts` BOOLEAN DEFAULT TRUE,
  `system_updates` BOOLEAN DEFAULT FALSE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_user_id` (`user_id`),
  CONSTRAINT `fk_user_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 10. RACE CONDITION PREVENTION TABLES
-- =====================================================

-- Pending orders table (prevents overselling)
CREATE TABLE IF NOT EXISTS `pending_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_product` (`user_id`, `product_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_pending_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pending_orders_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order processing locks table
CREATE TABLE IF NOT EXISTS `order_locks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `lock_key` VARCHAR(64) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lock_key` (`lock_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_order_locks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 11. STORED PROCEDURES AND FUNCTIONS
-- =====================================================

DELIMITER $$

-- Generate claim number trigger
CREATE TRIGGER `generate_claim_number`
BEFORE INSERT ON `warranty_claims`
FOR EACH ROW
BEGIN
    DECLARE next_num INT DEFAULT 1;
    SELECT COALESCE(MAX(CAST(SUBSTRING(claim_number, 3) AS UNSIGNED)), 0) + 1 
    INTO next_num 
    FROM warranty_claims 
    WHERE claim_number LIKE 'WC%';
    SET NEW.claim_number = CONCAT('WC', LPAD(next_num, 6, '0'));
END$$

-- Generate return number trigger
CREATE TRIGGER `generate_return_number`
BEFORE INSERT ON `return_requests`
FOR EACH ROW
BEGIN
    DECLARE next_num INT DEFAULT 1;
    SELECT COALESCE(MAX(CAST(SUBSTRING(return_number, 3) AS UNSIGNED)), 0) + 1 
    INTO next_num 
    FROM return_requests 
    WHERE return_number LIKE 'RT%';
    SET NEW.return_number = CONCAT('RT', LPAD(next_num, 6, '0'));
END$$

-- Atomic stock update procedure
CREATE PROCEDURE UpdateProductStock(
    IN p_product_id INT,
    IN p_quantity INT,
    IN p_operation VARCHAR(10),
    OUT p_success BOOLEAN,
    OUT p_new_stock INT,
    OUT p_message VARCHAR(255)
)
BEGIN
    DECLARE current_stock INT DEFAULT 0;
    DECLARE current_version INT DEFAULT 0;
    DECLARE new_stock INT DEFAULT 0;
    DECLARE rows_affected INT DEFAULT 0;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_success = FALSE;
        SET p_message = 'Database error occurred';
    END;
    
    START TRANSACTION;
    
    -- Lock the product row for update
    SELECT stock, version INTO current_stock, current_version
    FROM products 
    WHERE id = p_product_id AND status = 'active'
    FOR UPDATE;
    
    -- Check if product exists and is active
    IF current_stock IS NULL THEN
        SET p_success = FALSE;
        SET p_message = 'Product not found or inactive';
        ROLLBACK;
    ELSE
        -- Calculate new stock based on operation
        IF p_operation = 'decrease' THEN
            SET new_stock = current_stock - p_quantity;
            
            -- Check if we have enough stock
            IF new_stock < 0 THEN
                SET p_success = FALSE;
                SET p_message = CONCAT('Insufficient stock. Available: ', current_stock, ', Requested: ', p_quantity);
                ROLLBACK;
            ELSE
                -- Update stock and version atomically
                UPDATE products 
                SET stock = new_stock, 
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = p_product_id 
                AND version = current_version;
                
                SET rows_affected = ROW_COUNT();
                
                IF rows_affected = 0 THEN
                    SET p_success = FALSE;
                    SET p_message = 'Stock was modified by another process. Please try again.';
                    ROLLBACK;
                ELSE
                    SET p_success = TRUE;
                    SET p_new_stock = new_stock;
                    SET p_message = 'Stock updated successfully';
                    COMMIT;
                END IF;
            END IF;
        ELSEIF p_operation = 'increase' THEN
            SET new_stock = current_stock + p_quantity;
            
            -- Update stock and version atomically
            UPDATE products 
            SET stock = new_stock, 
                version = version + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = p_product_id 
            AND version = current_version;
            
            SET rows_affected = ROW_COUNT();
            
            IF rows_affected = 0 THEN
                SET p_success = FALSE;
                SET p_message = 'Stock was modified by another process. Please try again.';
                ROLLBACK;
            ELSE
                SET p_success = TRUE;
                SET p_new_stock = new_stock;
                SET p_message = 'Stock updated successfully';
                COMMIT;
            END IF;
        ELSE
            SET p_success = FALSE;
            SET p_message = 'Invalid operation';
            ROLLBACK;
        END IF;
    END IF;
    
END$$

-- Generate order hash function
CREATE FUNCTION GenerateOrderHash(
    p_user_id INT,
    p_cart_data TEXT,
    p_timestamp BIGINT
) RETURNS VARCHAR(64)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE hash_string VARCHAR(255);
    SET hash_string = CONCAT(p_user_id, ':', p_cart_data, ':', p_timestamp);
    RETURN SHA2(hash_string, 256);
END$$

-- Cleanup expired locks procedure
CREATE PROCEDURE CleanupExpiredLocks()
BEGIN
    DECLARE cleaned_locks_count INT DEFAULT 0;
    DECLARE cleaned_pending_count INT DEFAULT 0;
    
    -- Count and clean up expired order locks
    SELECT COUNT(*) INTO cleaned_locks_count FROM order_locks WHERE expires_at < NOW();
    DELETE FROM order_locks WHERE expires_at < NOW();
    
    -- Count and clean up expired pending orders
    SELECT COUNT(*) INTO cleaned_pending_count FROM pending_orders WHERE expires_at < NOW();
    DELETE FROM pending_orders WHERE expires_at < NOW();
    
    -- Log cleanup activity
    INSERT INTO audit_logs (action, table_name, record_id, old_values, new_values, created_at)
    VALUES ('cleanup_expired_locks', 'system', 0, 
            JSON_OBJECT('cleaned_locks', cleaned_locks_count),
            JSON_OBJECT('cleaned_pending', cleaned_pending_count),
            NOW());
END$$

DELIMITER ;

-- =====================================================
-- 12. EVENTS AND SCHEDULERS
-- =====================================================

-- Create cleanup event
CREATE EVENT IF NOT EXISTS cleanup_expired_locks_event
ON SCHEDULE EVERY 5 MINUTE
DO
  CALL CleanupExpiredLocks();

-- Enable event scheduler (wrapped so import doesn't abort if the DB user
-- lacks SUPER/SYSTEM_VARIABLES_ADMIN privilege, e.g. on shared hosting)
DELIMITER $$
CREATE PROCEDURE `EnableEventScheduler`()
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    SET GLOBAL event_scheduler = ON;
END$$
DELIMITER ;
CALL EnableEventScheduler();
DROP PROCEDURE `EnableEventScheduler`;

-- =====================================================
-- 13. SEED DATA
-- =====================================================

-- Default super admin (password: superadmin123)
INSERT INTO `users` (`firstname`, `lastname`, `email`, `password`, `role`, `status`) VALUES
('Super', 'Admin', 'superadmin@local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super-admin', 'active')
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- Default categories
INSERT INTO `categories` (`name`, `description`, `image_url`) VALUES
('Processors', 'CPU and processors for desktop computers', 'img/Processor.png'),
('Motherboards', 'Motherboards for various CPU sockets', 'img/Motherboard.png'),
('Graphics Cards', 'GPU and graphics cards for gaming and professional work', 'img/Graphics Card.png'),
('Memory', 'RAM modules for desktop and laptop computers', 'img/Memory.png'),
('Storage', 'SSD and HDD storage devices', 'img/SSD.png'),
('Power Supply', 'PSU units for desktop computers', 'img/PSU.png'),
('Desktops', 'Pre-built desktop computers', 'img/Desktop 1.png'),
('Laptops', 'Laptop computers for work and gaming', 'img/Lenovo.png'),
('Keyboards & Combos', 'Keyboards and keyboard-mouse combos', 'img/Keyboard.png'),
('Laptop Stands', 'Laptop stands and holders', 'img/Laptop Stand.png'),
('Speakers', 'Computer speakers and audio devices', 'img/Speaker.png'),
('Headsets', 'Gaming and professional headsets', 'img/Headset.png')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Sample tags
-- Colors are from the Mindful Palettes No.162 system (navy/mauve/muted
-- family), not an arbitrary rainbow - and each one is picked so white tag
-- text on it clears WCAG AA 4.5:1 (verified; the old rainbow hexes here
-- ranged ~2.1-3.8:1, well under that).
INSERT INTO `tags` (`name`, `description`, `color`) VALUES
('electronics', 'Electronic devices and components', '#0f2d4d'),
('computing', 'Computers, laptops, and computing devices', '#3d5875'),
('gaming', 'Gaming equipment and accessories', '#b45309'),
('office', 'Office equipment and supplies', '#7c3a56'),
('mobile', 'Mobile devices and accessories', '#6d6485'),
('networking', 'Network equipment and accessories', '#556575'),
('storage', 'Storage devices and solutions', '#3d5875'),
('accessories', 'Computer accessories and peripherals', '#174871'),
('premium', 'High-end and premium products', '#b91c1c'),
('budget', 'Budget-friendly and affordable products', '#047857'),
('new', 'Newly released products', '#99244d'),
('popular', 'Popular and trending products', '#b45309')
ON DUPLICATE KEY UPDATE name = VALUES(name), color = VALUES(color);

-- Default products
INSERT INTO `products` (`name`, `description`, `price`, `stock`, `category_id`, `image_url`) VALUES
('Ryzen 7 5700G Desktop PC', 'Complete desktop with Ryzen 7 5700G, A520 motherboard, 16GB DDR4 RAM', 14995.00, 5, 7, 'img/Desktop 1.png'),
('Ryzen 3 3200G Desktop PC', 'Budget desktop with Ryzen 3 3200G, A520 motherboard, 16GB DDR4 RAM', 10995.00, 8, 7, 'img/Desktop 2.png'),
('Lenovo V14 G4 ABP Laptop', '14" FHD laptop with AMD processor, perfect for business and productivity', 21765.00, 3, 8, 'img/Lenovo.png'),
('Acer Aspire 3 A325-42', '15.6" FHD laptop with modern features and performance', 29995.00, 4, 8, 'img/Acer.png')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Default payment methods
INSERT INTO `payment_methods` (`code`, `name`, `display_name`, `type`, `requires_processing`, `processing_fee`, `description`, `icon`, `sort_order`) VALUES
('cod_delivery', 'Cash on Delivery - Home Delivery', 'Home Delivery Payment', 'cash', FALSE, 0.00, 'Pay with cash when your order is delivered to your address', 'truck', 1),
('onsite_pickup', 'Pay at Store - Store Pickup', 'Store Pickup Payment', 'cash', FALSE, 0.00, 'Pay with cash when you pick up your order at our store', 'shop', 2)
ON DUPLICATE KEY UPDATE code = VALUES(code);

-- Default support settings
INSERT INTO `support_settings` (`setting_key`, `setting_value`, `description`) VALUES
('support_email', 'support@techworld.com', 'Main support email address'),
('warranty_email', 'warranty@techworld.com', 'Warranty support email address'),
('returns_email', 'returns@techworld.com', 'Returns support email address'),
('support_phone', '+63 2 1234-5678', 'Support phone number'),
('business_hours', 'Mon-Fri: 9AM-6PM, Sat: 9AM-2PM', 'Business hours'),
('auto_response_enabled', 'true', 'Enable automatic email responses'),
('ticket_auto_assign', 'true', 'Automatically assign tickets to available staff'),
('warranty_default_years', '3', 'Default warranty period in years'),
('return_window_days', '30', 'Default return window in days'),
('max_attachment_size', '10485760', 'Maximum attachment size in bytes (10MB)')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- Default FAQ categories
INSERT INTO `faq_categories` (`name`, `description`, `sort_order`) VALUES
('Orders & Shipping', 'Questions about orders, shipping, and delivery', 1),
('Products', 'Questions about our products and services', 2),
('Returns & Exchanges', 'Questions about returns and exchanges', 3),
('Warranty', 'Questions about warranty coverage and claims', 4),
('Technical Support', 'Technical questions and troubleshooting', 5)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Default FAQ items
INSERT INTO `faq_items` (`category_id`, `question`, `answer`, `sort_order`) VALUES
(1, 'How long does shipping take?', 'Standard shipping takes 3-5 business days within Metro Manila and 5-7 business days for provincial areas. Express shipping (1-2 days) is available for an additional fee.', 1),
(1, 'What are your shipping rates?', 'We offer free shipping for orders over ₱5,000 within Metro Manila. Standard shipping rates are ₱150 for Metro Manila and ₱250 for provincial areas.', 2),
(1, 'Can I track my order?', 'Yes! Once your order ships, you\'ll receive a tracking number via email and SMS. You can track your package using our website or the courier\'s tracking system.', 3),
(2, 'Are your products genuine and authentic?', 'Absolutely! All our products are 100% genuine and come directly from authorized distributors and manufacturers.', 4),
(2, 'Do you offer product recommendations?', 'Yes! Our technical experts can help you choose the right components for your needs. Contact our support team for personalized recommendations.', 5),
(3, 'What is your return policy?', 'We offer a 30-day return policy for most items. Items must be in original condition with all packaging and accessories.', 6),
(3, 'How do I return an item?', 'Contact our support team at returns@techworld.com with your order number and reason for return. We\'ll provide you with a prepaid return label.', 7),
(4, 'What warranty do you provide?', 'We provide manufacturer warranties ranging from 1-5 years depending on the product. All warranty terms are clearly stated on product pages.', 8),
(4, 'How do I claim warranty service?', 'Contact our warranty department at warranty@techworld.com with your order number, product details, and description of the issue.', 9),
(5, 'Do you provide technical support?', 'Yes! Our technical support team can help with installation, configuration, and troubleshooting via email, phone, and live chat.', 10)
ON DUPLICATE KEY UPDATE question = VALUES(question);

-- Default notification templates
INSERT INTO `notification_templates` (`type`, `event`, `title_template`, `message_template`) VALUES
-- Order Status Updates
('order_status', 'order_placed', 'Order Confirmed - #{order_id}', 'Your order #{order_id} has been placed successfully. Total: {total}'),
('order_status', 'order_processing', 'Order Processing - #{order_id}', 'Your order #{order_id} is now being processed.'),
('order_status', 'order_shipped', 'Order Shipped - #{order_id}', 'Your order #{order_id} has been shipped. Tracking: {tracking_number}'),
('order_status', 'order_delivered', 'Order Delivered - #{order_id}', 'Your order #{order_id} has been delivered successfully.'),
('order_status', 'order_cancelled', 'Order Cancelled - #{order_id}', 'Your order #{order_id} has been cancelled. Reason: {reason}'),

-- Payment & Invoice Alerts
('payment', 'payment_pending', 'Payment Pending - #{order_id}', 'Payment for order #{order_id} is pending. Amount: {amount}'),
('payment', 'payment_received', 'Payment Received - #{order_id}', 'Payment for order #{order_id} has been received. Amount: {amount}'),
('payment', 'payment_failed', 'Payment Failed - #{order_id}', 'Payment for order #{order_id} failed. Please try again.'),
('payment', 'invoice_generated', 'Invoice Generated - #{order_id}', 'Invoice for order #{order_id} has been generated.'),

-- Promotional Notifications
('promotional', 'new_offer', 'Special Offer Available!', 'Check out our latest offer: {offer_title}. Valid until {expiry_date}'),
('promotional', 'price_drop', 'Price Drop Alert', 'The price of {product_name} has dropped to {new_price}!'),
('promotional', 'back_in_stock', 'Back in Stock', '{product_name} is now back in stock!'),
('promotional', 'wishlist_sale', 'Wishlist Item on Sale', 'An item in your wishlist is now on sale: {product_name}'),

-- Security & Login Alerts
('security', 'login_success', 'Login Successful', 'You have successfully logged in from {device_info} at {login_time}.'),
('security', 'login_failed', 'Failed Login Attempt', 'A failed login attempt was made to your account from {device_info} at {login_time}.'),
('security', 'password_changed', 'Password Changed', 'Your password has been successfully changed at {change_time}.'),
('security', 'account_locked', 'Account Locked', 'Your account has been temporarily locked due to multiple failed login attempts.'),

-- System Updates
('system', 'maintenance', 'System Maintenance', 'Scheduled maintenance will occur on {maintenance_date} from {start_time} to {end_time}.'),
('system', 'feature_update', 'New Feature Available', 'A new feature has been added: {feature_name}. {description}'),
('system', 'policy_update', 'Policy Update', 'Our {policy_type} has been updated. Please review the changes.')
ON DUPLICATE KEY UPDATE type = VALUES(type), event = VALUES(event);

-- Sample review data
INSERT INTO `reviews` (`product_id`, `user_id`, `rating`, `title`, `comment`, `is_verified_purchase`, `is_approved`) VALUES
(1, 1, 5, 'Excellent product!', 'This product exceeded my expectations. Great quality and fast delivery.', TRUE, TRUE),
(1, 1, 4, 'Very good', 'Good product overall, minor issues but worth the price.', TRUE, TRUE),
(2, 1, 5, 'Amazing performance', 'Outstanding performance for the price. Highly recommended!', TRUE, TRUE),
(2, 1, 3, 'Average', 'Product is okay, nothing special but does the job.', FALSE, TRUE),
(3, 1, 5, 'Perfect!', 'Exactly what I needed. Great customer service too.', TRUE, TRUE)
ON DUPLICATE KEY UPDATE rating = VALUES(rating);

-- =====================================================
-- 14. FINAL COMMENTS AND DOCUMENTATION
-- =====================================================

-- Add comments to document key fields
ALTER TABLE `orders` 
MODIFY COLUMN `delivery_method` ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery' COMMENT 'Delivery method: delivery for home delivery, pickup for store pickup',
MODIFY COLUMN `payment_method_display` VARCHAR(50) NOT NULL DEFAULT 'In-Store Payment' COMMENT 'Display value for payment method in reports and UI';

ALTER TABLE `payments` 
MODIFY COLUMN `payment_method` ENUM('cod','onsite') NOT NULL COMMENT 'Technical payment method code',
MODIFY COLUMN `payment_type` ENUM('home_delivery','store_pickup') NOT NULL COMMENT 'Payment type for categorization',
MODIFY COLUMN `payment_status` ENUM('pending','processing','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'pending' COMMENT 'Current status of the payment';

-- =====================================================
-- DATABASE STRUCTURE COMPLETE
-- =====================================================
-- 
-- This file contains the complete TechWorld E-commerce database structure
-- with all tables, relationships, triggers, procedures, and seed data.
-- 
-- Key Features:
-- - Complete user management system
-- - Product catalog with categories and tags
-- - Shopping cart functionality
-- - Order management with multiple statuses
-- - Payment processing system
-- - Inventory management
-- - Support ticket system
-- - Warranty and return management
-- - FAQ and knowledge base
-- - Notification system
-- - Race condition prevention
-- - Delivery proof tracking
-- - Audit logging
-- 
-- All foreign key constraints are properly defined
-- All indexes are optimized for performance
-- All triggers and procedures are included
-- Seed data is provided for immediate use
-- 
-- Import this file to create a fully functional e-commerce database
-- =====================================================

-- =====================================================
-- 15. ADDITIONAL DATA SEEDING FROM LIST (from DBSQL/70_seed_products_from_list.sql)
-- =====================================================

USE `techworld_ecommerce`;

-- Temporary staging for seed rows
DROP TEMPORARY TABLE IF EXISTS `seed_products`;
CREATE TEMPORARY TABLE `seed_products` (
    `category_name` VARCHAR(100) NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `qty` INT NOT NULL,
    `price` DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `seed_products` (`category_name`, `product_name`, `qty`, `price`) VALUES
    -- PROCESSORS / MINI PCs
    ('Processors','INTEL NUC Celeron NUC7CJYSN Mini PC',2,6500),
    ('Processors','INTEL NUC CORE i5 10th Gen A Mini PC Kit',1,14500),
    ('Processors','INTEL NUC i5 Mini PC kit',1,11500),
    ('Processors','INTEL NUC i3 Mini PC kit',2,8500),
    ('Processors','INTEL CELERON G1840 PROCESSOR',3,900),
    ('Processors','INTEL PENTIUM G3250 PROCESSOR',3,1000),
    ('Processors','INTEL PENTIUM G3260 PROCESSOR',3,1050),
    ('Processors','INTEL CORE I3-12100 PROCESSOR',3,5500),
    ('Processors','INTEL CORE I3-10105 PROCESSOR',5,4200),
    ('Processors','INTEL CORE I5-11400F PROCESSOR',2,6800),
    ('Processors','INTEL CORE I5-12400F PROCESSOR',2,7500),
    ('Processors','INTEL CORE I5-12400 PROCESSOR',2,8500),
    ('Processors','INTEL CORE I5-9400F PROCESSOR',2,4500),
    ('Processors','INTEL CORE I5-13400 PROCESSOR',2,10500),
    ('Processors','Machenike Machreator Mini PC 2 i5-12450H',1,18500),
    ('Processors','Machenike Machreator Mini PC 2 i7-12650H',1,24500),
    ('Processors','AMD Ryzen 7 5700G PROCESSOR',3,9500),
    ('Processors','AMD Ryzen 5 5600G PROCESSOR',1,6800),
    ('Processors','AMD Ryzen 5 5500 PROCESSOR',2,4800),
    ('Processors','AMD Ryzen 5 3600 PROCESSOR',3,4200),
    ('Processors','AMD Ryzen 5 2600 PROCESSOR',3,3200),
    ('Processors','AMD Ryzen 3 3200G PROCESSOR',1,3895),
    ('Processors','AMD Ryzen 5 4600G PROCESSOR',5,6200),
    ('Processors','AMD Athlon 200GE PROCESSOR',1,1800),
    ('Processors','AMD Ryzen 3 PRO 4350G Tray type Processor',7,3500),

    -- MOTHERBOARDS
    ('Motherboards','Gigabyte B550M DS3H Motherboard',1,4200),
    ('Motherboards','Gigabyte B450M DS3H Motherboard',1,3200),
    ('Motherboards','Gigabyte B450M S2H V2 Motherboard',1,2900),
    ('Motherboards','Gigabyte AB350M-HD3 Motherboard',2,2800),
    ('Motherboards','Gigabyte A320M-S2H V2 Motherboard',1,2300),
    ('Motherboards','Gigabyte B760M H DDR4 Motherboard',5,6200),
    ('Motherboards','Gigabyte A320M-S2H Motherboard',5,2200),
    ('Motherboards','MSI B450M Mortar Max Motherboard',2,3600),
    ('Motherboards','Asus TUF B450M-Pro II Motherboard',1,3800),
    ('Motherboards','Aorus B450 AORUS M Motherboard',2,3700),
    ('Motherboards','Asus Prime B365M-A Motherboard',1,3200),
    ('Motherboards','Asus TUF Z370-PLUS Motherboard',2,3900),
    ('Motherboards','BIOSTAR A75MG Motherboard',2,1800),
    ('Motherboards','ASUS A68HM-K Motherboard',2,1900),
    ('Motherboards','MSI H110M PRO-VH Motherboard',4,2000),
    ('Motherboards','MSI H110M PRO-VD Motherboard',4,2000),
    ('Motherboards','GIGABYTE H110M - S2 Motherboard',1,1950),
    ('Motherboards','ASUS H110M-D Motherboard',2,2100),
    ('Motherboards','GIGABYTE H110M-S2 Motherboard',2,1950),
    ('Motherboards','GIGABYTE H110M-H Motherboard',2,2000),
    ('Motherboards','GIGABYTE B150M-D3H Motherboard',1,2400),
    ('Motherboards','DURATHON 2 H110M4-C2 SERIES Motherboard',2,1600),
    ('Motherboards','ASUS EZ-B150M-V5 Motherboard',2,2500),
    ('Motherboards','MSI B150M BAZOOKA Motherboard',2,2500),
    ('Motherboards','GIGABYTE Z270M-D3H Motherboard',1,3000),
    ('Motherboards','ASUS PRIME B250M-D Motherboard',2,2600),
    ('Motherboards','ASUS EX-H310M-V3 R2.0 Motherboard',5,2300),
    ('Motherboards','MSI H310M PRO-VDH PLUS Motherboard',5,2300),
    ('Motherboards','MSI A320M PRO-VD/S Motherboard',2,2200),
    ('Motherboards','GIGABYTE A320M-DS2 Motherboard',2,2200),
    ('Motherboards','PRIME A320M-K Motherboard',5,2300),
    ('Motherboards','MSI BAZOOKA B350M Motherboard',2,2900),
    ('Motherboards','MSI B350 PRO-VD PLUS Motherboard',4,2900),
    ('Motherboards','MSI TOMAWAK B350 Motherboard',1,3200),
    ('Motherboards','MSI B360M GAMING PLUS Motherboard',2,3200),
    ('Motherboards','MSI B360 GAMING PRO CARBON Motherboard',2,3800),
    ('Motherboards','ROG STRIX B360-F GAMING Motherboard',2,4200),
    ('Motherboards','TUF B360M-E GAMING Motherboard',2,3600),
    ('Motherboards','MSI Z370 GAMING PLUS Motherboard',2,3900),
    ('Motherboards','ASUS PRIME B365-A Motherboard',1,3300),
    ('Motherboards','ASUS TUF B365- PLUS GAMING Motherboard',2,3800),
    ('Motherboards','PRIME Z370-A Motherboard',2,3900),
    ('Motherboards','MSI B450M PRO -VDH PLUS Motherboard',1,3300),
    ('Motherboards','ASUS ROG STRIX Z370-G GAMING (WIFI) Motherboard',2,5200),
    ('Motherboards','ASUS ROG STRIX Z370-F GAMING Motherboard',1,4800),
    ('Motherboards','MSI B450M PRO-M2 V2 Motherboard',2,3000),
    ('Motherboards','ASUS TUF B450M-PLUS GAMING Motherboard',2,3700),
    ('Motherboards','ASUS TUF B450M-PRO GAMING Motherboard',2,3900),
    ('Motherboards','ASUS TUF GAMING B450M - PRO II Motherboard',1,4000),
    ('Motherboards','MSI B450 TOMAHAWK MAX Motherboard',1,3900),
    ('Motherboards','MSI B350 TOMAHAWK Motherboard',1,3300),
    ('Motherboards','ASUS EX-H510M-V3 Motherboard',1,3200),
    ('Motherboards','GIGABYTE B460M POWER Motherboard',2,3600),
    ('Motherboards','ASUS PRIME B450- A II Motherboard',1,3300),
    ('Motherboards','MSI PRO SERIES PRO H510M-B Motherboard',1,3000),
    ('Motherboards','ASUS PRIME A520M-K Motherboard',2,3200),
    ('Motherboards','MSI MAG B550M BAZOOKA Motherboard',2,4300),
    ('Motherboards','ASROCK B550M-HDV Motherboard',1,3900),
    ('Motherboards','ASUS PRIME A520M-K Motherboard',1,3200),
    ('Motherboards','ASUS PRIME H610M-K D4 Motherboard',5,5200),
    ('Motherboards','MSI PRO H610M-E DDR4 Motherboard',2,5000),
    ('Motherboards','GIGABYTE H610M K DDR4 Motherboard',4,5000),

    -- GRAPHICS CARDS
    ('Graphics Cards','Gigabyte RX570',1,4200),
    ('Graphics Cards','Gigabyte GTX1050Ti',3,3800),
    ('Graphics Cards','PowerColor RX 6700 XT 12GB GDDR6',1,14500),
    ('Graphics Cards','Asus TUF GTX 1660Ti 6GB OC GDDR6',2,7200),
    ('Graphics Cards','Asus TUF GTX 1650 4GB OC GDDR6',2,4800),
    ('Graphics Cards','ASUS Phoenix GTX 1660 6GB OC GDDR5',1,6500),
    ('Graphics Cards','MSI Ventus RTX 2060 6GB GDDR6',1,8200),
    ('Graphics Cards','Asus GTX 1050Ti 4GB DDR5',2,3700),
    ('Graphics Cards','INNO3D GTX 1050Ti 4GB DDR5',2,3600),
    ('Graphics Cards','INNO3D GTX 1660 6GB GDDR5',1,6200),
    ('Graphics Cards','INNO3D GTX 1660 Super 6GB GDDR6',1,6800),
    ('Graphics Cards','INNO3D GTX 1650 4GB GDDR6',1,4300),
    ('Graphics Cards','Gigabyte RTX 4070 12GB GDDR6X',1,32500),
    ('Graphics Cards','Gigabyte RTX 4060Ti 8GB GDDR6',1,21500),
    ('Graphics Cards','Gigabyte RTX 4060 8GB GDDR6',1,17500),
    ('Graphics Cards','Gigabyte RTX 4060Ti 16GB GDDR6',1,24500),
    ('Graphics Cards','MSI GT 710 2GB DDR3',2,1600),
    ('Graphics Cards','INNO3D GT 730 2GB SDDR3',4,1800),
    ('Graphics Cards','INNO3D GT 1030 2GB GDDR5',2,2800),
    ('Graphics Cards','PALIT GTX 1050Ti 4GB GDDR5',2,3700),
    ('Graphics Cards','Palit RTX 3050 8GB GDDR6',2,9200),
    ('Graphics Cards','Palit GTX 1050Ti 4GB DDR5',3,3700),
    ('Graphics Cards','Asus GTX 1050 2GB GDDR5',1,3200),
    ('Graphics Cards','Afox GT 730 4096MB DDR3',2,1900),
    ('Graphics Cards','Asus GT 710 2GB DDR3',2,1700),

    -- MEMORY (DIMM + SO-DIMM)
    ('Memory','Hp 8GB PC4 3200 U-Dimm Memory',2,1600),
    ('Memory','TeamGroup T.Force 8GB DDR4 3200 DIMM Memory',5,1700),
    ('Memory','Transcend 16GB DDR4 3200 U-DIMM Memory',5,2900),
    ('Memory','Transcend 4GB DDR4 2666 U-DIMM Memory',10,950),
    ('Memory','Transcend 4GB DDR4 3200 U-DIMM Memory',10,1000),
    ('Memory','Team Group Elite 8GB DDR4 2666 DIMM Memory',6,1500),
    ('Memory','G.Skill 16GBx2 DDR4 3600 DIMM Memory',3,5800),
    ('Memory','Kingston 8GB DDR4 3200 DIMM Memory',9,1700),
    ('Memory','Kingston Fury 8GB DDR4 2666 DIMM Memory',10,1600),
    ('Memory','Hyper X fury 16GB DDR4 3200 U-DIMM Memory',10,3100),
    ('Memory','Hyper X 16GB DDR4 2666 DIMM Memory',8,2900),
    ('Memory','Hyper X 16GB DDR4 2400 DIMM Memory',2,2800),
    ('Memory','Hyper X HX432C16FB4/16 16GB DDR4 3200 DIMM MEMORY',10,3100),
    ('Memory','Hyper X HX426C16FB3/8 8GB DDR4 2666 DIMM Memory',8,1600),
    ('Memory','GSkill Aegis 4GB DDR4 2400 DIMM Memory',10,900),
    ('Memory','ADATA 16GB DDR4 3200 SO-DIMM Memory',3,2900),
    ('Memory','ADATA 16GB DDR4 3200 U-DIMM Memory',2,2800),
    ('Memory','Dahua 8GB DDR4 3200 DIMM Memory',10,1400),
    ('Memory','Kingston 8GB DDR4 2400 SODIMM Memory',5,1500),
    ('Memory','Kingston 16GB DDR4 2666 SODIMM Memory',10,2900),
    ('Memory','Kingston 8GB DDR4 2666 SODIMM Memory',9,1600),
    ('Memory','Crucial 4GB DDR4 2400 SODIMM Memory',1,900),
    ('Memory','Hp 16GB DDR4 2666 SODIMM Memory',2,2700),
    ('Memory','Kingmax 4GB DDR3 1600 SODIMM Memory',2,700),
    ('Memory','Transcend 8GB DDR4 2666 SODIMM Memory',4,1600),
    ('Memory','Transcend 4GB DDR4 3200 SODIMM Memory',5,1000),
    ('Memory','Kingston 8GB DDR4 3200 SODIMM Memory',5,1700),
    ('Memory','Kingston 16GB DDR4 3200 SODIMM Memory',1,3000),
    ('Memory','Apacer 8GB DDR4 2400 SODIMM Memory',4,1400),
    ('Memory','Apacer 4GB DDR4 2400 SODIMM Memory',5,850),
    ('Memory','Apacer 4GB DDR4 2666 SODIMM Memory',10,900),

    -- POWER SUPPLY
    ('Power Supply','Antec ATOM B550 Bronze PSU',2,2200),
    ('Power Supply','Antec 550W 80PLUS PSU',3,2000),
    ('Power Supply','Antec NeoEco Ne 650 G.M psu',2,4300),
    ('Power Supply','Antec NeoEco Ne 750 G.M psu',2,5000),
    ('Power Supply','Antec NeoEco Ne 850 G.M psu',2,5800),
    ('Power Supply','Huntkey GS700 PSU',2,2600),
    ('Power Supply','Huntkey GS600 PSU',2,2200),
    ('Power Supply','Huntkey GS500 PSU',5,1800),
    ('Power Supply','Cooler Master 750 Bronze PSU',2,3600),
    ('Power Supply','Cooler Master 650 Bronze PSU',1,2900),
    ('Power Supply','Cooler Master 550 Bronze PSU',1,2300),
    ('Power Supply','Power logic ATX PM-600 PSU',3,1500),
    ('Power Supply','Corsair CV550 ATX PSU',1,2800),
    ('Power Supply','Orion ATX-700W PSU',3,1600),
    ('Power Supply','LC-8700BTXN Standard PSU',2,1200),

    -- KEYBOARDS & COMBOS
    ('Keyboards & Combos','Aula Wind F2088 pro Keyboard',2,1100),
    ('Keyboards & Combos','Machenike K500B-B87 Mechanical Keyboard',1,1800),
    ('Keyboards & Combos','Aula L2098 Mechanical Keyboard',3,950),
    ('Keyboards & Combos','Aula T640 Mechanical Keyboard',3,900),
    ('Keyboards & Combos','Aula F2058 Mechanical Keyboard',1,850),
    ('Keyboards & Combos','Aula F2066-II Mechanical Keyboard',1,900),
    ('Keyboards & Combos','Aula S2022 Mechanical Keyboard',2,900),
    ('Keyboards & Combos','Aula F3018 Mechanical Keyboard',2,950),
    ('Keyboards & Combos','Aula F3061 Mini Keyboard',1,850),
    ('Keyboards & Combos','Rapoo 8000M wireless combo keyboard/Mouse',2,1200),
    ('Keyboards & Combos','Rapoo X1800S wireless combo keyboard/Mouse',2,950),
    ('Keyboards & Combos','Rapoo NK2600 Wired keyboard',5,450),
    ('Keyboards & Combos','Rapoo NX1600 Wired Combo keyboard/Mouse',5,550),
    ('Keyboards & Combos','Rapoo NX1700 wired USB Combo keyboard/Mouse',4,600),
    ('Keyboards & Combos','Fantech Maxpower MK853 Mechanical Keyboard',2,1300),
    ('Keyboards & Combos','Redragon Lakshmi K606R Mechanical Keyboard',2,1600),
    ('Keyboards & Combos','Redragon KUMARA K552RGB-1 Mechanical Keyboard',1,1500),
    ('Keyboards & Combos','Asus RA04 TUF Gaming K1 Gaming Keyboard',3,1900),
    ('Keyboards & Combos','A4tech FKS11 FStyler wired Keyboard',5,450),
    ('Keyboards & Combos','A4tech FBK11 FStyler wireless Keyboard',4,700),
    ('Keyboards & Combos','Delux KM36U Mechanical Keyboard',1,1200),
    ('Keyboards & Combos','Intex IT-1017RB USB Keyboard',2,350),
    ('Keyboards & Combos','Aula AC203 Combo keyboard/Mouse',9,550),
    ('Keyboards & Combos','A4tech KRS-8572 Combo keyboard/Mouse',2,500),
    ('Keyboards & Combos','Aula AC101 Wired Combo keyboard/Mouse',8,500),
    ('Keyboards & Combos','Aula AK205 Wired Keyboard',3,600),
    ('Keyboards & Combos','Redragon Mitra K551-1 Mechanical Keyboard',2,1400),
    ('Keyboards & Combos','Redragon Mitra K551RGB-1 Mechanical Keyboard',2,1500),
    ('Keyboards & Combos','Redragon Dark Avenger K568RGB-1 Mechanical Keyboard',1,1700),
    ('Keyboards & Combos','Redragon Gaming essentials K551RGB-BA 2in1 keyboard/Mouse Combo',2,1600),
    ('Keyboards & Combos','Redragon S107 keyboard/Mouse Combo set',2,700),
    ('Keyboards & Combos','Redragon SHRAPNEL K589RGB Mechanical Keyboard',2,1900),
    ('Keyboards & Combos','Redragon Dyausz K509RGB Mechanical Keyboard',2,1800),
    ('Keyboards & Combos','A4tech KRS-85 Keyboard',6,350),
    ('Keyboards & Combos','Machenike CKM500 wireless Keyboard',4,1400),
    ('Keyboards & Combos','Machenike K520-B108 Mechanical Keyboard',5,2000),

    -- LAPTOP STANDS
    ('Laptop Stands','Aula Wind F61 Laptop Holder',3,450),

    -- SPEAKERS
    ('Speakers','Aula N-189 Speaker',5,500),
    ('Speakers','Aula N-107 Speaker',4,450),
    ('Speakers','Mini Speaker A10 wireless',10,350),
    ('Speakers','Doss DS-1209 BT Speaker',3,900),
    ('Speakers','Edifier G1000 BT Speaker',2,1600),
    ('Speakers','Edifier MP260 BT Speaker',2,1300),
    ('Speakers','Edifier R19U Multimedia Speaker',2,600),
    ('Speakers','Asimom DS-1198 Speaker',2,500),
    ('Speakers','Aula N521 Gaming Speaker',4,750),
    ('Speakers','Aula N69 Gaming Speaker',6,650),

    -- HEADSETS
    ('Headsets','Aula F606 Headset',5,550),
    ('Headsets','Aula S600 Headset',3,650),
    ('Headsets','Aula S605 Headset',2,700),
    ('Headsets','Rapoo H100 wired Headset',4,500),
    ('Headsets','Rapoo H150S Stereo Headset',3,750),
    ('Headsets','Rapoo H120 Stereo Headset',5,550),
    ('Headsets','AULA S503 Headset',2,600),
    ('Headsets','Aula S505 Headset',5,650),
    ('Headsets','HP H500GS Headset',2,700),
    ('Headsets','Edifier USB K800 Headset',2,900),
    ('Headsets','Redragon Ares H120 Headset',3,750),
    ('Headsets','Steelseries 61504 Arctis 5 Headset',2,4200),
    ('Headsets','Edifier K830 Headphone/Headset',2,1200),
    ('Headsets','Edifier G2II Headset',2,1500),
    ('Headsets','A4tech Bloody G520 Headset',2,900),
    ('Headsets','A4tech Bloody G500 Headset',3,850),
    ('Headsets','Redragon Lamia2 H320RGB-1 Headset',2,1300),
    ('Headsets','Edifier W800BT Plus Headset Black',2,1700),
    ('Headsets','Edifier W800BT Plus Headset White',2,1700),
    ('Headsets','Redragon Minos H210 Headset',2,700),
    ('Headsets','RAPOO Vpro VH200 Headset',2,650),
    ('Headsets','Redragon Mento H270-RGB Headset',1,1100),
    ('Headsets','Lenovo H300 Headset L02',2,450),
    ('Headsets','Logitech H151 Stereo Headset',4,650),
    ('Headsets','Logitech H150 Stereo Headset',4,600),
    ('Headsets','Logitech H111 Stereo Headset',4,550),
    ('Headsets','Plantronics Poly 3220 Headset',2,2200),
    ('Headsets','A4tech HS-7P Headset',10,350),
    ('Headsets','A4tech HS-50 Stereo Headset',5,400),
    ('Headsets','A4tech HU-30 USB Headset',5,450),
    ('Headsets','A4tech HS-5P Headset',10,350),
    ('Headsets','RAIKU S300 Standard Headset Brown Box',7,500);

-- Ensure categories exist
INSERT IGNORE INTO `categories` (`name`)
SELECT DISTINCT sp.`category_name`
FROM `seed_products` sp;

-- Step 1: Update existing products (match by name), set active, bump stock, update category, set image_url
-- price only backfills when the existing row has no real price yet (0 or NULL), so
-- re-running this seed never overwrites a price an admin already set in the catalog.
UPDATE `products` p
JOIN `seed_products` sp ON sp.`product_name` = p.`name`
JOIN `categories` c ON c.`name` = sp.`category_name`
SET p.`stock` = p.`stock` + sp.`qty`,
    p.`price` = IF(p.`price` IS NULL OR p.`price` = 0, sp.`price`, p.`price`),
    p.`status` = 'active',
    p.`category_id` = c.`id`,
    p.`image_url` = CONCAT('public/shared/img/products/', sp.`product_name`, '.png'),
    p.`updated_at` = NOW();

-- Step 2: Insert products that do not yet exist. Real per-item prices (see
-- the seed_products.price values above) instead of a 0.00 placeholder, so
-- every seeded product is priced and orderable (status='active') right away.
INSERT INTO `products` (`name`, `description`, `price`, `stock`, `category_id`, `image_url`, `status`, `created_at`)
SELECT sp.`product_name`, NULL, sp.`price`, sp.`qty`, c.`id`,
       CONCAT('public/shared/img/products/', sp.`product_name`, '.png'), 'active', NOW()
FROM `seed_products` sp
JOIN `categories` c ON c.`name` = sp.`category_name`
LEFT JOIN `products` p ON p.`name` = sp.`product_name`
WHERE p.`id` IS NULL;

-- =====================================================
-- 16. AI BUILDER SPEC DESCRIPTIONS (from DBSQL/71_ai_builder_spec_descriptions.sql)
-- =====================================================
-- Backfills products.description for CPUs/motherboards with the socket /
-- RAM-type / integrated-graphics facts the AI Builder's compatibility
-- engine (config/ai_builder_engine.php) parses via regex.

-- Processors
UPDATE `products` SET `description` = 'AMD Athlon 200GE, AM4 socket, with integrated Radeon Vega 3 graphics.' WHERE `name` = 'AMD Athlon 200GE PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 3 3200G, AM4 socket, with integrated Radeon Vega 8 graphics.' WHERE `name` = 'AMD Ryzen 3 3200G PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 3 PRO 4350G, AM4 socket, with integrated Radeon Vega 6 graphics.' WHERE `name` = 'AMD Ryzen 3 PRO 4350G Tray type Processor';
UPDATE `products` SET `description` = 'AMD Ryzen 5 2600, AM4 socket. No integrated graphics - requires a discrete GPU for video output.' WHERE `name` = 'AMD Ryzen 5 2600 PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 3600, AM4 socket. No integrated graphics - requires a discrete GPU for video output.' WHERE `name` = 'AMD Ryzen 5 3600 PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 4600G, AM4 socket, with integrated Radeon Vega 7 graphics.' WHERE `name` = 'AMD Ryzen 5 4600G PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 5500, AM4 socket. No integrated graphics - requires a discrete GPU for video output.' WHERE `name` = 'AMD Ryzen 5 5500 PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 5600G, AM4 socket, with integrated Radeon Vega 7 graphics.' WHERE `name` = 'AMD Ryzen 5 5600G PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 7 5700G, AM4 socket, with integrated Radeon Vega 8 graphics.' WHERE `name` = 'AMD Ryzen 7 5700G PROCESSOR';
UPDATE `products` SET `description` = 'Intel Celeron G1840 (Haswell), LGA1150 socket, with integrated Intel HD Graphics.' WHERE `name` = 'INTEL CELERON G1840 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i3-10105 (Comet Lake), LGA1200 socket, with integrated UHD 630 graphics.' WHERE `name` = 'INTEL CORE I3-10105 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i3-12100 (Alder Lake), LGA1700 socket, with integrated UHD 730 graphics.' WHERE `name` = 'INTEL CORE I3-12100 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-11400F (Rocket Lake), LGA1200 socket. F-series - no integrated graphics, requires a discrete GPU for video output.' WHERE `name` = 'INTEL CORE I5-11400F PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-12400 (Alder Lake), LGA1700 socket, with integrated UHD 730 graphics.' WHERE `name` = 'INTEL CORE I5-12400 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-12400F (Alder Lake), LGA1700 socket. F-series - no integrated graphics, requires a discrete GPU for video output.' WHERE `name` = 'INTEL CORE I5-12400F PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-13400 (Raptor Lake), LGA1700 socket, with integrated UHD 730 graphics.' WHERE `name` = 'INTEL CORE I5-13400 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-9400F (Coffee Lake, 8th Gen), LGA1151 socket - 300-series chipset motherboards only. F-series - no integrated graphics, requires a discrete GPU for video output.' WHERE `name` = 'INTEL CORE I5-9400F PROCESSOR';
UPDATE `products` SET `description` = 'Intel Pentium G3250 (Haswell), LGA1150 socket, with integrated Intel HD Graphics.' WHERE `name` = 'INTEL PENTIUM G3250 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Pentium G3260 (Haswell), LGA1150 socket, with integrated Intel HD Graphics.' WHERE `name` = 'INTEL PENTIUM G3260 PROCESSOR';

-- Motherboards
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Aorus B450 AORUS M Motherboard';
UPDATE `products` SET `description` = 'AMD B550 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASROCK B550M-HDV Motherboard';
UPDATE `products` SET `description` = 'AMD A68H chipset motherboard, FM2+ socket, DDR3 memory support.' WHERE `name` = 'ASUS A68HM-K Motherboard';
UPDATE `products` SET `description` = 'Intel H310 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS EX-H310M-V3 R2.0 Motherboard';
UPDATE `products` SET `description` = 'Intel H510 chipset motherboard, LGA1200 socket, DDR4 memory support.' WHERE `name` = 'ASUS EX-H510M-V3 Motherboard';
UPDATE `products` SET `description` = 'Intel B150 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'ASUS EZ-B150M-V5 Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'ASUS H110M-D Motherboard';
UPDATE `products` SET `description` = 'AMD A520 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS PRIME A520M-K Motherboard';
UPDATE `products` SET `description` = 'Intel B250 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'ASUS PRIME B250M-D Motherboard';
UPDATE `products` SET `description` = 'Intel B365 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS PRIME B365-A Motherboard';
UPDATE `products` SET `description` = 'Intel B365 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'Asus Prime B365M-A Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS PRIME B450- A II Motherboard';
UPDATE `products` SET `description` = 'Intel H610 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'ASUS PRIME H610M-K D4 Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS ROG STRIX Z370-F GAMING Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support, built-in Wi-Fi.' WHERE `name` = 'ASUS ROG STRIX Z370-G GAMING (WIFI) Motherboard';
UPDATE `products` SET `description` = 'Intel B365 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS TUF B365- PLUS GAMING Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS TUF B450M-PLUS GAMING Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS TUF B450M-PRO GAMING Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Asus TUF B450M-Pro II Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS TUF GAMING B450M - PRO II Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'Asus TUF Z370-PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD A75 chipset motherboard, FM1 socket, DDR3 memory support.' WHERE `name` = 'BIOSTAR A75MG Motherboard';
UPDATE `products` SET `description` = 'Intel H110-class chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'DURATHON 2 H110M4-C2 SERIES Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'GIGABYTE A320M-DS2 Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte A320M-S2H Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte A320M-S2H V2 Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte AB350M-HD3 Motherboard';
UPDATE `products` SET `description` = 'Intel B150 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE B150M-D3H Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B450M DS3H Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B450M S2H V2 Motherboard';
UPDATE `products` SET `description` = 'Intel B460 chipset motherboard, LGA1200 socket, DDR4 memory support.' WHERE `name` = 'GIGABYTE B460M POWER Motherboard';
UPDATE `products` SET `description` = 'AMD B550 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B550M DS3H Motherboard';
UPDATE `products` SET `description` = 'Intel B760 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B760M H DDR4 Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE H110M - S2 Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE H110M-H Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE H110M-S2 Motherboard';
UPDATE `products` SET `description` = 'Intel H610 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'GIGABYTE H610M K DDR4 Motherboard';
UPDATE `products` SET `description` = 'Intel Z270 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE Z270M-D3H Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI A320M PRO-VD/S Motherboard';
UPDATE `products` SET `description` = 'Intel B150 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'MSI B150M BAZOOKA Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B350 PRO-VD PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B350 TOMAHAWK Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI B360 GAMING PRO CARBON Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI B360M GAMING PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450 TOMAHAWK MAX Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450M Mortar Max Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450M PRO -VDH PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450M PRO-M2 V2 Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI BAZOOKA B350M Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'MSI H110M PRO-VD Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'MSI H110M PRO-VH Motherboard';
UPDATE `products` SET `description` = 'Intel H310 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI H310M PRO-VDH PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B550 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI MAG B550M BAZOOKA Motherboard';
UPDATE `products` SET `description` = 'Intel H610 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'MSI PRO H610M-E DDR4 Motherboard';
UPDATE `products` SET `description` = 'Intel H510 chipset motherboard, LGA1200 socket, DDR4 memory support.' WHERE `name` = 'MSI PRO SERIES PRO H510M-B Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI TOMAWAK B350 Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI Z370 GAMING PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'PRIME A320M-K Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'PRIME Z370-A Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ROG STRIX B360-F GAMING Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'TUF B360M-E GAMING Motherboard';

-- =====================================================
-- 17. STORAGE / PC CASE / CPU COOLER PRODUCTS (from DBSQL/72_seed_storage_case_cooler.sql)
-- =====================================================
-- Fills the three component roles the AI Builder previously could never
-- recommend at all (Storage had a category but zero listings; PC Case and
-- CPU Cooler had no category). Same staging-table pattern as section 15
-- above, with description carrying each item's specs (type/capacity for
-- storage, supported sockets + TDP rating for coolers) from the start.

INSERT IGNORE INTO `categories` (`name`) VALUES ('PC Case'), ('CPU Cooler');

DROP TEMPORARY TABLE IF EXISTS `seed_products_v2`;
CREATE TEMPORARY TABLE `seed_products_v2` (
    `category_name` VARCHAR(100) NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `qty` INT NOT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `description` TEXT NOT NULL
);

INSERT INTO `seed_products_v2` (`category_name`, `product_name`, `qty`, `price`, `description`) VALUES
    ('Storage','Kingston A400 240GB SATA SSD',6,1050,'Kingston A400 240GB 2.5" SATA III SSD. SATA SSD, 240GB capacity - fast, reliable boot/OS drive upgrade over a mechanical HDD.'),
    ('Storage','Kingston A400 480GB SATA SSD',6,1850,'Kingston A400 480GB 2.5" SATA III SSD. SATA SSD, 480GB capacity.'),
    ('Storage','WD Green 480GB SATA SSD',5,1900,'Western Digital Green 480GB 2.5" SATA III SSD. SATA SSD, 480GB capacity.'),
    ('Storage','WD Blue 1TB SATA SSD',4,2900,'Western Digital Blue 1TB 2.5" SATA III SSD. SATA SSD, 1TB capacity.'),
    ('Storage','Adata SU650 240GB SATA SSD',6,1000,'ADATA SU650 240GB 2.5" SATA III SSD. SATA SSD, 240GB capacity.'),
    ('Storage','Adata SU650 480GB SATA SSD',5,1800,'ADATA SU650 480GB 2.5" SATA III SSD. SATA SSD, 480GB capacity.'),
    ('Storage','Transcend SSD230S 512GB SATA SSD',4,1950,'Transcend SSD230S 512GB 2.5" SATA III SSD. SATA SSD, 512GB capacity.'),
    ('Storage','Team Group GX2 512GB SATA SSD',4,1750,'Team Group GX2 512GB 2.5" SATA III SSD. SATA SSD, 512GB capacity.'),
    ('Storage','Kingston NV2 500GB NVMe SSD',6,2100,'Kingston NV2 500GB M.2 PCIe NVMe SSD. NVMe SSD, 500GB capacity - far faster than SATA for OS/game load times.'),
    ('Storage','Kingston NV2 1TB NVMe SSD',4,3300,'Kingston NV2 1TB M.2 PCIe NVMe SSD. NVMe SSD, 1TB capacity.'),
    ('Storage','WD Blue SN580 500GB NVMe SSD',5,2500,'Western Digital Blue SN580 500GB M.2 PCIe NVMe SSD. NVMe SSD, 500GB capacity.'),
    ('Storage','Adata Legend 700 512GB NVMe SSD',5,2350,'ADATA Legend 700 512GB M.2 PCIe NVMe SSD. NVMe SSD, 512GB capacity.'),
    ('Storage','Team Group MP33 512GB NVMe SSD',4,2250,'Team Group MP33 512GB M.2 PCIe NVMe SSD. NVMe SSD, 512GB capacity.'),
    ('Storage','Crucial P3 1TB NVMe SSD',3,3400,'Crucial P3 1TB M.2 PCIe NVMe SSD. NVMe SSD, 1TB capacity.'),
    ('Storage','Seagate Barracuda 1TB HDD',6,2250,'Seagate BarraCuda 1TB 3.5" SATA desktop HDD. HDD, 1TB capacity - bulk storage for large files/media.'),
    ('Storage','Seagate Barracuda 2TB HDD',4,3150,'Seagate BarraCuda 2TB 3.5" SATA desktop HDD. HDD, 2TB capacity.'),
    ('Storage','WD Blue 1TB HDD',6,2200,'Western Digital Blue 1TB 3.5" SATA desktop HDD. HDD, 1TB capacity.'),
    ('Storage','WD Blue 2TB HDD',4,3050,'Western Digital Blue 2TB 3.5" SATA desktop HDD. HDD, 2TB capacity.'),
    ('Storage','Toshiba P300 1TB HDD',5,2150,'Toshiba P300 1TB 3.5" SATA desktop HDD. HDD, 1TB capacity.'),
    ('Storage','Toshiba P300 2TB HDD',3,3100,'Toshiba P300 2TB 3.5" SATA desktop HDD. HDD, 2TB capacity.'),
    ('PC Case','Value Top VT-B16 ATX Case',4,900,'Value Top VT-B16 ATX mid-tower case. Supports ATX/Micro-ATX/Mini-ITX motherboards. No-frills budget build.'),
    ('PC Case','Inplay M-ATX Desktop Case V300',5,1000,'Inplay V300 Micro-ATX desktop case. Supports Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','YGT M7 PC Gaming Case Tempered Glass',5,1100,'YGT M7 Micro-ATX gaming case with tempered glass side panel. Supports Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','Aigo Darkflash DLM21 Mesh Case',4,1750,'Aigo Darkflash DLM21 Micro-ATX mesh-front case for improved airflow. Supports Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','Segotep Halo 6 ARGB Case',4,1500,'Segotep Halo 6 Micro-ATX case with 3 pre-installed ARGB fans. Supports Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','Xigmatek Aquarius Plus RGB Case',3,1700,'Xigmatek Aquarius Plus ATX case with tempered glass and RGB fans. Supports ATX/Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','Cooler Master MasterBox Q300L Case',3,2400,'Cooler Master MasterBox Q300L Micro-ATX case with magnetic dust filter and see-through side panel. Supports ATX/Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','Antec NX310 ARGB Mid Tower Case',3,2600,'Antec NX310 ATX mid-tower case with tempered glass and ARGB fans. Supports ATX/Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','Coolman Spectra M-ATX Dual Chamber Case',3,2100,'Coolman Spectra Micro-ATX dual-chamber case for cleaner cable routing. Supports Micro-ATX/Mini-ITX motherboards.'),
    ('PC Case','DeepCool CH170 PLUS M-ATX Case',2,3200,'DeepCool CH170 PLUS Micro-ATX case with mesh front panel and pre-installed fans. Supports Micro-ATX/Mini-ITX motherboards.'),
    ('CPU Cooler','DeepCool AK400 CPU Air Cooler',6,1350,'DeepCool AK400 single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 220W TDP.'),
    ('CPU Cooler','DeepCool AG400 CPU Air Cooler',8,900,'DeepCool AG400 single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 180W TDP.'),
    ('CPU Cooler','Cooler Master Hyper 212 Black Edition CPU Cooler',6,1600,'Cooler Master Hyper 212 Black Edition air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 150W TDP.'),
    ('CPU Cooler','ID-Cooling SE-214-XT ARGB CPU Cooler',5,1200,'ID-Cooling SE-214-XT ARGB single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 180W TDP.'),
    ('CPU Cooler','DeepCool AK620 Dual Tower CPU Cooler',4,2900,'DeepCool AK620 dual-tower, dual-fan air cooler for higher-TDP CPUs. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 260W TDP.'),
    ('CPU Cooler','Xigmatek Windpower II 120 CPU Cooler',6,700,'Xigmatek Windpower II 120 single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 130W TDP.'),
    ('CPU Cooler','Intel Boxed CPU Cooler LGA1200/1151/1150',8,350,'Standard Intel boxed cooling fan. Supports Intel LGA1200/LGA1151/LGA1150 sockets only, rated up to 65W TDP.'),
    ('CPU Cooler','AMD Wraith Stealth CPU Cooler AM4',8,400,'AMD Wraith Stealth boxed cooling fan. Supports AMD AM4 socket only, rated up to 65W TDP.'),
    ('CPU Cooler','Value Top CP-9002 CPU Cooler',6,450,'Value Top CP-9002 budget air cooler. Supports Intel LGA1200/LGA1151/LGA1150 sockets only, rated up to 95W TDP.');

-- Step 1: update existing products (match by name) - bump stock, set
-- description/category/image, backfill price only if unpriced, always active.
UPDATE `products` p
JOIN `seed_products_v2` sp ON sp.`product_name` = p.`name`
JOIN `categories` c ON c.`name` = sp.`category_name`
SET p.`stock` = p.`stock` + sp.`qty`,
    p.`price` = IF(p.`price` IS NULL OR p.`price` = 0, sp.`price`, p.`price`),
    p.`description` = sp.`description`,
    p.`status` = 'active',
    p.`category_id` = c.`id`,
    p.`image_url` = CONCAT('public/shared/img/products/', sp.`product_name`, '.png'),
    p.`updated_at` = NOW();

-- Step 2: insert products that do not yet exist
INSERT INTO `products` (`name`, `description`, `price`, `stock`, `category_id`, `image_url`, `status`, `created_at`)
SELECT sp.`product_name`, sp.`description`, sp.`price`, sp.`qty`, c.`id`,
       CONCAT('public/shared/img/products/', sp.`product_name`, '.png'), 'active', NOW()
FROM `seed_products_v2` sp
JOIN `categories` c ON c.`name` = sp.`category_name`
LEFT JOIN `products` p ON p.`name` = sp.`product_name`
WHERE p.`id` IS NULL;

-- =====================================================
-- 18. CCTV / PRINTERS / INK & TONER / NETWORKING PRODUCTS (from DBSQL/73_seed_cctv_printer_ink_networking.sql)
-- =====================================================
-- Four more product categories the store also offers, on top of the PC
-- component catalog in sections 15-17 above. Standalone catalog items,
-- not part of the AI Builder's PC-component roles.

INSERT IGNORE INTO `categories` (`name`) VALUES ('CCTV & Security'), ('Printers'), ('Ink & Toner'), ('Networking');

DROP TEMPORARY TABLE IF EXISTS `seed_products_v3`;
CREATE TEMPORARY TABLE `seed_products_v3` (
    `category_name` VARCHAR(100) NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `qty` INT NOT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `description` TEXT NOT NULL
);

INSERT INTO `seed_products_v3` (`category_name`, `product_name`, `qty`, `price`, `description`) VALUES
    ('CCTV & Security','Dahua 4CH 2MP HDCVI Camera Kit (2 Bullet + 2 Turret + DVR)',3,8780,'Dahua 4-channel 2MP HDCVI camera kit: 4-channel DVR, 2 bullet cameras, 2 turret cameras. Complete plug-and-play CCTV kit, HDD sold separately.'),
    ('CCTV & Security','Dahua 8CH 2MP HDCVI Camera Kit with 1TB HDD',2,15040,'Dahua 8-channel 2MP HDCVI camera kit with 8-channel DVR, 8 cameras, and a pre-installed 1TB surveillance hard drive.'),
    ('CCTV & Security','Hikvision 4CH 2MP Lite ColorVu Camera Kit',2,13830,'Hikvision 4-channel 2MP Lite ColorVu camera kit: 4-channel DVR + 4 ColorVu cameras for full-color night vision.'),
    ('CCTV & Security','Hikvision 8CH 2MP Lite ColorVu Camera Kit',2,24720,'Hikvision 8-channel 2MP Lite ColorVu camera kit: 8-channel DVR + 8 ColorVu cameras for full-color night vision.'),
    ('CCTV & Security','Hikvision 2MP Turbo HD Bullet Camera',8,1200,'Hikvision 2MP Turbo HD outdoor bullet camera. Standalone camera, weatherproof housing, add-on for an existing DVR system.'),
    ('CCTV & Security','Dahua 2MP HDCVI Dome Camera',8,1100,'Dahua 2MP HDCVI indoor dome camera. Standalone camera, add-on for an existing DVR system.'),
    ('CCTV & Security','Hikvision 4CH Turbo HD DVR',4,2800,'Hikvision 4-channel Turbo HD digital video recorder. Standalone DVR, cameras sold separately, HDD sold separately.'),
    ('CCTV & Security','Dahua 8CH XVR Digital Video Recorder',3,4200,'Dahua 8-channel XVR (Penta-brid) digital video recorder, supports HDCVI/analog/IP camera inputs. Standalone DVR, cameras sold separately.'),
    ('CCTV & Security','WD Purple 3TB Surveillance HDD',5,3600,'Western Digital Purple 3TB 3.5" surveillance-rated HDD, built for 24/7 CCTV DVR/NVR recording.'),
    ('CCTV & Security','CCTV Power Supply and Cable Kit',10,650,'CCTV 12V power supply adapter with BNC video and DC power cables - accessory kit for connecting an analog camera to a DVR.'),
    ('Printers','Epson L121 Ink Tank Printer',5,5495,'Epson L121 ink tank printer (print only). Compact, refillable ink tank system for low cost-per-page printing.'),
    ('Printers','Epson L1210 Ink Tank Printer',4,7945,'Epson L1210 ink tank printer (print only). Refillable ink tank system for low cost-per-page printing.'),
    ('Printers','Epson L3210 Ink Tank All-in-One Printer',4,8795,'Epson L3210 ink tank all-in-one printer - print, scan, and copy. Refillable ink tank system.'),
    ('Printers','Epson L1250 Wi-Fi Ink Tank Printer',3,8345,'Epson L1250 ink tank printer (print only) with built-in Wi-Fi for wireless printing.'),
    ('Printers','Canon PIXMA G1010 Ink Tank Printer',4,4795,'Canon PIXMA G1010 ink tank printer (print only). Refillable ink tank system for low cost-per-page printing.'),
    ('Printers','Canon PIXMA G2020 All-in-One Ink Tank Printer',4,6500,'Canon PIXMA G2020 ink tank all-in-one printer - print, scan, and copy. Refillable ink tank system.'),
    ('Printers','Canon PIXMA G3730 Wi-Fi All-in-One Ink Tank Printer',3,9995,'Canon PIXMA G3730 ink tank all-in-one printer with built-in Wi-Fi - print, scan, copy, and mobile printing.'),
    ('Printers','HP LaserJet M111w Wireless Laser Printer',3,6900,'HP LaserJet M111w monochrome laser printer (print only) with built-in Wi-Fi for wireless printing.'),
    ('Printers','Brother HL-L2321D Laser Printer',3,7200,'Brother HL-L2321D monochrome laser printer (print only) with automatic duplex (double-sided) printing.'),
    ('Ink & Toner','Epson 003 Black Ink Bottle',15,310,'Epson 003 genuine black ink bottle for Epson L-series ink tank printers.'),
    ('Ink & Toner','Epson 003 Cyan Ink Bottle',12,320,'Epson 003 genuine cyan ink bottle for Epson L-series ink tank printers.'),
    ('Ink & Toner','Epson 003 Magenta Ink Bottle',12,320,'Epson 003 genuine magenta ink bottle for Epson L-series ink tank printers.'),
    ('Ink & Toner','Epson 003 Yellow Ink Bottle',12,320,'Epson 003 genuine yellow ink bottle for Epson L-series ink tank printers.'),
    ('Ink & Toner','Epson 664 Black Ink Bottle 70ml',12,310,'Epson 664 genuine black ink bottle (70ml) for Epson L-series ink tank printers.'),
    ('Ink & Toner','Canon PG-89 Black Ink Cartridge',10,450,'Canon PG-89 genuine black ink cartridge for Canon PIXMA printers.'),
    ('Ink & Toner','Canon CL-746 Color Ink Cartridge',10,710,'Canon CL-746 genuine tri-color ink cartridge for Canon PIXMA printers.'),
    ('Ink & Toner','HP 12A Black LaserJet Toner Cartridge',6,3200,'HP 12A (Q2612A) genuine black toner cartridge for HP LaserJet printers.'),
    ('Ink & Toner','HP 85A Black LaserJet Toner Cartridge',6,3800,'HP 85A (CE285A) genuine black toner cartridge for HP LaserJet printers.'),
    ('Ink & Toner','Brother TN-2321 Toner Cartridge',6,3500,'Brother TN-2321 genuine black toner cartridge for Brother HL-L2321D and compatible laser printers.'),
    ('Networking','Tenda AC5 AC1200 Dual-Band WiFi Router',8,895,'Tenda AC5 AC1200 dual-band wireless router, WISP/access point/repeater modes supported.'),
    ('Networking','Tenda AC6 AC1200 Dual-Band WiFi Router',6,1169,'Tenda AC6 v5.0 AC1200 dual-band wireless router with remote management via the Tenda app.'),
    ('Networking','TP-Link Archer C54 AC1200 WiFi Router',8,1100,'TP-Link Archer C54 AC1200 dual-band wireless router.'),
    ('Networking','TP-Link Deco E4 AC1200 Mesh WiFi System (1-Pack)',6,1650,'TP-Link Deco E4 AC1200 whole-home mesh Wi-Fi system, single unit - add more units to extend coverage.'),
    ('Networking','TP-Link Deco E4 AC1200 Mesh WiFi System (2-Pack)',4,3150,'TP-Link Deco E4 AC1200 whole-home mesh Wi-Fi system, 2-unit pack for larger homes.'),
    ('Networking','Tenda Nova MW6 AC1200 Mesh WiFi System (3-Pack)',3,2300,'Tenda Nova MW6 AC1200 whole-home mesh Wi-Fi system, 3-unit pack.'),
    ('Networking','TP-Link TL-SF1008D 8-Port Fast Ethernet Switch',8,650,'TP-Link TL-SF1008D unmanaged 8-port 10/100Mbps Fast Ethernet switch.'),
    ('Networking','TP-Link TL-WA850RE WiFi Range Extender',6,750,'TP-Link TL-WA850RE Wi-Fi range extender/repeater, N300.'),
    ('Networking','Cat6 Ethernet Cable 305m Box',4,2800,'Cat6 UTP solid-core ethernet cable, 305-meter pull box - for structured network cabling runs.'),
    ('Networking','RJ45 Connector Pack (100pcs)',10,250,'RJ45 Cat6 modular connector plugs, pack of 100 - for terminating ethernet cable runs.');

UPDATE `products` p
JOIN `seed_products_v3` sp ON sp.`product_name` = p.`name`
JOIN `categories` c ON c.`name` = sp.`category_name`
SET p.`stock` = p.`stock` + sp.`qty`,
    p.`price` = IF(p.`price` IS NULL OR p.`price` = 0, sp.`price`, p.`price`),
    p.`description` = sp.`description`,
    p.`status` = 'active',
    p.`category_id` = c.`id`,
    p.`image_url` = CONCAT('public/shared/img/products/', sp.`product_name`, '.png'),
    p.`updated_at` = NOW();

INSERT INTO `products` (`name`, `description`, `price`, `stock`, `category_id`, `image_url`, `status`, `created_at`)
SELECT sp.`product_name`, sp.`description`, sp.`price`, sp.`qty`, c.`id`,
       CONCAT('public/shared/img/products/', sp.`product_name`, '.png'), 'active', NOW()
FROM `seed_products_v3` sp
JOIN `categories` c ON c.`name` = sp.`category_name`
LEFT JOIN `products` p ON p.`name` = sp.`product_name`
WHERE p.`id` IS NULL;
