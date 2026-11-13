-- File: DBSQL/80_notifications.sql

USE `techworld_ecommerce`;

-- Align with existing notifications table by adding missing columns if needed
ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `data` JSON NULL,
  ADD COLUMN IF NOT EXISTS `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  ADD COLUMN IF NOT EXISTS `expires_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `read_at` DATETIME NULL;

-- Widen `type` to the set the application actually writes.
--
-- 10_users_and_auth.sql creates this column as ENUM('info','success',
-- 'warning','error') - a severity, not a category - but every createNotification()
-- call in the codebase passes a CATEGORY: 'order_status' (the order lifecycle,
-- ~130 rows live), 'system', and 'security' (password changes, from
-- api/change_password.php). None of those are members of the original enum, so
-- on a fresh install from DBSQL every notification insert writes '' under a
-- warning, or throws under STRICT_TRANS_TABLES - and the password-change
-- warning email's in-app twin would silently never arrive.
--
-- The live database was widened by hand at some point; this records that so a
-- rebuild matches it. The four severity values are kept, not dropped: removing
-- an enum member rewrites any existing row that uses it to ''. notification_templates
-- below already declares the five categories, so this also makes the two agree.
--
-- MODIFY is idempotent - re-running this file just restates the same definition.
ALTER TABLE `notifications`
  MODIFY COLUMN `type`
    ENUM('order_status','payment','promotional','security','system',
         'info','success','warning','error')
    NOT NULL DEFAULT 'order_status';

-- Notification templates
CREATE TABLE IF NOT EXISTS notification_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('order_status', 'payment', 'promotional', 'security', 'system') NOT NULL,
    event VARCHAR(100) NOT NULL,
    title_template VARCHAR(255) NOT NULL,
    message_template TEXT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_type_event (type, event)
);

-- User notification preferences
CREATE TABLE IF NOT EXISTS user_notification_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email_notifications BOOLEAN DEFAULT TRUE,
    push_notifications BOOLEAN DEFAULT TRUE,
    sms_notifications BOOLEAN DEFAULT FALSE,
    order_updates BOOLEAN DEFAULT TRUE,
    payment_alerts BOOLEAN DEFAULT TRUE,
    promotional_offers BOOLEAN DEFAULT TRUE,
    security_alerts BOOLEAN DEFAULT TRUE,
    system_updates BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert default notification templates
INSERT IGNORE INTO notification_templates (type, event, title_template, message_template) VALUES
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
('system', 'policy_update', 'Policy Update', 'Our {policy_type} has been updated. Please review the changes.');

-- Index creation on `notifications` skipped to remain idempotent with existing schema
