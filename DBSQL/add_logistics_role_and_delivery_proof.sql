-- Logistics/Delivery Personnel role support
-- File: DBSQL/add_logistics_role_and_delivery_proof.sql
--
-- No change needed to users.role - 'driver' already exists there and is
-- being reused as the Logistics/Delivery Personnel role (see config/
-- functions.php's isDriver()/getUserHomepage()).
--
-- delivery_proof_photos.photo_type gets one new value: the old model
-- required 3 separately-categorized photos (item_received/payment/receipt)
-- before an order could auto-complete; the new model requires exactly one
-- mandatory delivery-proof photo, submitted together with the explicit
-- "mark as delivered" action. Old historical rows keep their original
-- categorized values - this only adds a new option for photos taken going
-- forward, it doesn't touch existing data.

USE `techworld_ecommerce`;

ALTER TABLE `delivery_proof_photos`
MODIFY COLUMN `photo_type` ENUM('delivery_location', 'customer_receipt', 'package_delivered', 'delivery_proof') NOT NULL;
