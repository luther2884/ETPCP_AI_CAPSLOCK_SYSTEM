-- Add GCash/Maya e-payment support to orders and payments
-- File: DBSQL/add_epayment_method.sql
--
-- orders.payment_method was a hard ENUM('cod','onsite') - widen it to add the
-- two e-payment channels selectable at checkout (Home Delivery -> E-Payment).
-- payments.payment_method already has room for 'online' in its own ENUM, so
-- no change is needed there; the new checkout flow writes payment_type =
-- 'online_payment' (already a valid payments.payment_type value).

USE `techworld_ecommerce`;

ALTER TABLE `orders`
MODIFY COLUMN `payment_method` ENUM('cod','onsite','gcash','paymaya') NOT NULL COMMENT 'cod/onsite = existing flows, gcash/paymaya = e-payment (Home Delivery -> E-Payment)';

-- Reference number the customer submits after paying via GCash/Maya, so
-- staff can match it against the transaction in their merchant dashboard.
ALTER TABLE `orders`
ADD COLUMN IF NOT EXISTS `payment_reference` VARCHAR(50) NULL DEFAULT NULL AFTER `payment_method_display`;

-- The live `payments` table's ENUMs are narrower than DBSQL/create_payments_table.sql
-- describes (payment_method only had 'cod','onsite'; payment_type only had
-- 'home_delivery','store_pickup') - widen both to add the e-payment values
-- this feature writes, without touching the existing cod/onsite values.
ALTER TABLE `payments`
MODIFY COLUMN `payment_method` ENUM('cod','onsite','online') NOT NULL;

ALTER TABLE `payments`
MODIFY COLUMN `payment_type` ENUM('home_delivery','store_pickup','online_payment') NOT NULL;
