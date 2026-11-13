USE `techworld_ecommerce`;

-- Map pin for a delivery address.
--
-- The address book is free text: "Blk 12 Lot 3 Sampaguita St." typed as
-- "Sampaguita" or "Samapguita" geocodes to the wrong street, or to nothing,
-- and the rider is left guessing. get_driver_location.php says as much in a
-- comment - it ships the address TEXT to the map because "nothing in the
-- schema stores lat/lng for an address" and the browser has to geocode the
-- typo'd string on the spot.
--
-- These columns let the customer drop the pin themselves. Coordinates beat
-- spelling: the rider navigates to the point, whatever the street line says.
-- NULL throughout means "no pin" - every existing address and order keeps
-- working exactly as before and falls back to the text path.
--
-- DECIMAL(10,7) is ~1.1 cm of precision at the equator, far finer than a
-- phone GPS fix, and stores an exact value rather than a float that drifts
-- on round-trip.
ALTER TABLE `user_addresses`
  ADD COLUMN IF NOT EXISTS `latitude`  DECIMAL(10,7) NULL DEFAULT NULL AFTER `street`,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(10,7) NULL DEFAULT NULL AFTER `latitude`;

-- Snapshotted onto the order for the same reason the recipient/phone/address
-- are (see add_order_delivery_snapshot.sql): the pin that was chosen for THIS
-- delivery must not change later because the customer edited or deleted that
-- saved address.
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `delivery_latitude`  DECIMAL(10,7) NULL DEFAULT NULL AFTER `delivery_full_address`,
  ADD COLUMN IF NOT EXISTS `delivery_longitude` DECIMAL(10,7) NULL DEFAULT NULL AFTER `delivery_latitude`;
