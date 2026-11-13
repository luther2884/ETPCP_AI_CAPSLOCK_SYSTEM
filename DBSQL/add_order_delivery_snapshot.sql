USE `techworld_ecommerce`;

-- The address a customer picks at checkout was never persisted on the order
-- itself - every page that displays "the delivery address" (invoice,
-- track_order, admin delivery pages) re-queries user_addresses fresh for
-- whatever the customer's CURRENT default/first saved address happens to
-- be, completely disconnected from what was actually selected at checkout.
-- These columns snapshot the chosen recipient/phone/address at order time,
-- same idea as invoice_no/tracking_code already being generated once and
-- frozen on the order rather than recomputed later.
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `delivery_recipient_name` VARCHAR(150) NULL AFTER `assigned_driver_id`,
  ADD COLUMN IF NOT EXISTS `delivery_phone` VARCHAR(30) NULL AFTER `delivery_recipient_name`,
  ADD COLUMN IF NOT EXISTS `delivery_full_address` TEXT NULL AFTER `delivery_phone`;
