-- Add "store credit" as a first-class payment method.
-- File: DBSQL/add_store_credit_payment_method.sql
--
-- Store credit could already be APPLIED at checkout (orders.credit_applied,
-- from add_store_credit_and_refund_choice.sql), but it could never BE the
-- payment method. An order whose credit covered the whole total was still
-- stamped 'cod' or 'onsite' and left payment_status 'pending', so the
-- customer was still made to pick how to pay, the rider/counter was told to
-- collect PHP 0.00 at handover, and the receipt claimed "Cash on Delivery"
-- for an order that was settled before it was even placed.
--
-- A 'store_credit' order is paid at creation: the ledger deduction IS the
-- payment, so process_order.php stamps payment_status = 'paid' and paid_at.
-- It is deliberately NOT added to OS_PREPAID_METHODS (config/order_status.php),
-- so it starts on the normal 'preparing' rung with no awaiting_payment hold
-- and no payment-expiry auto-cancel - there is nothing left to wait for.

USE `techworld_ecommerce`;

-- orders.payment_method: keep every value the column has picked up along the
-- way (add_epayment_method.sql, add_xendit_payment.sql) and append the new one.
ALTER TABLE `orders`
MODIFY COLUMN `payment_method`
  ENUM('cod','onsite','gcash','paymaya','xendit','store_credit') NOT NULL
  COMMENT 'store_credit = settled in full from the customer ledger at checkout';

-- payments.payment_method: the union of the values create_payments_table.sql
-- ships with and the narrower set some installs actually ended up with, so
-- this ALTER can never truncate an existing row whichever one you are on.
ALTER TABLE `payments`
MODIFY COLUMN `payment_method`
  ENUM('cod','onsite','online','bank_transfer','credit_card','debit_card','digital_wallet','store_credit') NOT NULL
  COMMENT 'Technical payment method code';

-- payment_methods is the display-name lookup getPaymentMethodLabel() prefers
-- before falling back to its hardcoded switch.
INSERT IGNORE INTO `payment_methods`
  (`code`, `name`, `display_name`, `type`, `requires_processing`, `processing_fee`, `description`, `icon`, `sort_order`)
VALUES
  ('store_credit', 'Store Credit', 'Store Credit', 'digital', FALSE, 0.00,
   'Paid in full using the store credit on your account', 'wallet', 9);
