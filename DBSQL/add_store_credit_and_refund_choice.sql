-- Store credit ledger + cancellation refund-choice tracking
-- File: DBSQL/add_store_credit_and_refund_choice.sql
--
-- Implements: cash refund to original payment method is the customer's
-- default right on a pre-fulfillment cancellation; store credit is an
-- opt-in, incentivized alternative - never mandatory. Both are booked as
-- liabilities (this ledger, or the refund-pending state below), never as
-- revenue, until the money is either paid out or actually redeemed
-- against a delivered order.

USE `techworld_ecommerce`;

-- One row per credit/debit event (never a single mutable balance column),
-- so the full history is always reconstructable and reconcilable - same
-- pattern the existing `payments` table already uses for payment events.
CREATE TABLE IF NOT EXISTS `store_credits` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `type` ENUM('credit','debit') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  -- Running balance immediately after this row, for fast statement reads
  -- without re-summing the whole history every time.
  `balance_after` DECIMAL(10,2) NOT NULL,
  `source` ENUM('cancellation_credit','redemption','admin_adjustment') NOT NULL,
  -- For a credit: the cancelled order that generated it. For a debit: the
  -- new order it was redeemed against.
  `order_id` INT(11) NULL,
  `cancellation_request_id` INT(11) NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_cancellation_request_id` (`cancellation_request_id`),
  CONSTRAINT `fk_store_credits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_store_credits_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_store_credits_cancel_req` FOREIGN KEY (`cancellation_request_id`) REFERENCES `order_cancellation_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer's choice, captured at cancellation-request time, plus whether
-- that choice has actually been fulfilled yet. refund_method is NULL for
-- COD/onsite orders (nothing was paid yet, so there's nothing to refund -
-- the existing stock-restore-on-approve behavior already covers those).
ALTER TABLE `order_cancellation_requests`
ADD COLUMN IF NOT EXISTS `refund_method` ENUM('original_payment','store_credit') NULL AFTER `additional_notes`,
ADD COLUMN IF NOT EXISTS `refund_status` ENUM('not_applicable','pending','completed') NOT NULL DEFAULT 'not_applicable' AFTER `refund_method`;

-- 'refunded' is the missing terminal state - today an order can reach
-- 'paid' but has no way to record that the money was later returned
-- (as cash or as credit) after a cancellation.
ALTER TABLE `orders`
MODIFY COLUMN `payment_status` ENUM('pending','paid','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'pending';

-- How much of this order's total was covered by redeeming store credit
-- (vs. the chosen payment method) - kept on the order itself for a clean
-- audit trail alongside the ledger rows.
ALTER TABLE `orders`
ADD COLUMN IF NOT EXISTS `credit_applied` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`;
