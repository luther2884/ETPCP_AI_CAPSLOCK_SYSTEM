-- Race Condition Prevention Schema Updates
-- This file contains database schema improvements to prevent race conditions

USE `techworld_ecommerce`;

-- Add version column to products table for optimistic locking
ALTER TABLE `products` 
ADD COLUMN `version` INT(11) NOT NULL DEFAULT 1 AFTER `updated_at`;

-- Add unique constraint to prevent duplicate orders from same user at same time
-- This helps prevent duplicate orders when users click "Buy" multiple times
ALTER TABLE `orders` 
ADD COLUMN `order_hash` VARCHAR(64) NOT NULL DEFAULT '' AFTER `pickup_code_expires_at`,
ADD UNIQUE KEY `uniq_order_hash` (`order_hash`);

-- Create a table to track pending orders (prevents overselling)
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

-- Create a table to track order processing locks
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

-- Add indexes for better performance on stock checks
CREATE INDEX `idx_products_stock_status` ON `products` (`stock`, `status`);
CREATE INDEX `idx_products_id_stock` ON `products` (`id`, `stock`);

-- Create a stored procedure for atomic stock updates
DELIMITER //

CREATE PROCEDURE UpdateProductStock(
    IN p_product_id INT,
    IN p_quantity INT,
    IN p_operation ENUM('decrease', 'increase'),
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
    
END //

DELIMITER ;

-- Create a function to generate order hash for duplicate prevention
DELIMITER //

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
END //

DELIMITER ;

-- Create a cleanup procedure for expired locks and pending orders
DELIMITER //

CREATE PROCEDURE CleanupExpiredLocks()
BEGIN
    -- Clean up expired order locks
    DELETE FROM order_locks WHERE expires_at < NOW();
    
    -- Clean up expired pending orders
    DELETE FROM pending_orders WHERE expires_at < NOW();
    
    -- Log cleanup activity
    INSERT INTO audit_logs (action, table_name, record_id, old_values, new_values, created_at)
    VALUES ('cleanup_expired_locks', 'system', 0, 
            JSON_OBJECT('cleaned_locks', (SELECT COUNT(*) FROM order_locks WHERE expires_at < NOW())),
            JSON_OBJECT('cleaned_pending', (SELECT COUNT(*) FROM pending_orders WHERE expires_at < NOW())),
            NOW());
END //

DELIMITER ;

-- Create an event to run cleanup every 5 minutes
CREATE EVENT IF NOT EXISTS cleanup_expired_locks_event
ON SCHEDULE EVERY 5 MINUTE
DO
  CALL CleanupExpiredLocks();

-- Enable event scheduler
SET GLOBAL event_scheduler = ON;
