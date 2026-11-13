-- Xendit Payment Sessions integration
-- File: DBSQL/add_xendit_payment.sql
--
-- Adds an order-level, gateway-agnostic payment status (separate from the
-- existing `orders.status` fulfillment/delivery-stage column) plus Xendit
-- session/payment identifiers, and a dedicated webhook-event dedupe table so
-- repeated Xendit webhook deliveries can never double-process a payment.

USE `techworld_ecommerce`;

ALTER TABLE `orders`
MODIFY COLUMN `payment_method` ENUM('cod','onsite','gcash','paymaya','xendit') NOT NULL COMMENT 'xendit = Xendit Payment Sessions (hosted checkout)';

ALTER TABLE `orders`
ADD COLUMN IF NOT EXISTS `payment_status` ENUM('pending','paid','failed','cancelled','expired') NOT NULL DEFAULT 'pending' AFTER `payment_reference`,
ADD COLUMN IF NOT EXISTS `xendit_payment_session_id` VARCHAR(100) NULL DEFAULT NULL AFTER `payment_status`,
ADD COLUMN IF NOT EXISTS `xendit_payment_id` VARCHAR(100) NULL DEFAULT NULL AFTER `xendit_payment_session_id`,
ADD COLUMN IF NOT EXISTS `paid_at` TIMESTAMP NULL DEFAULT NULL AFTER `xendit_payment_id`;

ALTER TABLE `orders`
ADD INDEX IF NOT EXISTS `idx_payment_status` (`payment_status`),
ADD INDEX IF NOT EXISTS `idx_xendit_payment_session_id` (`xendit_payment_session_id`);

-- Widen the payments table's own ENUMs too (already required to be widened
-- for the gcash/paymaya flow in add_epayment_method.sql - 'online' already
-- covers Xendit as well, no further change needed there).

-- Idempotency ledger: one row per Xendit webhook event actually processed.
-- The UNIQUE key on event_id is the enforcement mechanism - the webhook
-- handler always attempts this INSERT first; a duplicate-key error means the
-- event was already handled, so processing is skipped (still returns 200).
CREATE TABLE IF NOT EXISTS `xendit_webhook_events` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `event_id` VARCHAR(150) NOT NULL,
    `payment_id` VARCHAR(150) NULL DEFAULT NULL,
    `order_id` INT(11) NULL DEFAULT NULL,
    `event_type` VARCHAR(50) NULL DEFAULT NULL,
    `raw_payload` JSON NULL DEFAULT NULL,
    `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_event_id` (`event_id`),
    KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
