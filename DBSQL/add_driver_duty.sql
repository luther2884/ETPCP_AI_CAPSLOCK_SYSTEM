-- Rider duty state + automatic delivery assignment
-- File: DBSQL/add_driver_duty.sql
--
-- Until now a delivery got its rider from a dropdown on
-- admin/Home_Delivery_Orders.php: office staff picked a name by hand, with
-- nothing in the system knowing whether that rider was working, already out on
-- a drop, or off shift entirely.
--
-- These two columns are what "on duty" means. They live on `users` rather than
-- in a new table because a rider is already a users row (role = 'driver') and
-- duty is a single current state, not history:
--
--   driver_on_duty    the rider's own toggle - they tapped "Go on duty"
--   driver_last_seen  heartbeat from the open dispatch board
--
-- A rider counts as ONLINE only when both agree: the toggle is on AND the
-- heartbeat is recent (see DRIVER_HEARTBEAT_TIMEOUT_MINUTES in
-- config/functions.php). The toggle alone is not enough - a rider who goes
-- home without tapping off would otherwise sit at the front of the queue all
-- night, holding orders nobody is delivering.
--
-- Availability ("not on a delivery") is NOT stored here. It is derived from
-- orders - a rider is busy while they have a shipped, assigned, not-yet-
-- completed delivery - because that is already the single source of truth and
-- a duplicate flag would only drift out of sync with it.

USE `techworld_ecommerce`;

ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `driver_on_duty` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Riders only: the rider''s own on/off duty toggle',
ADD COLUMN IF NOT EXISTS `driver_last_seen` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Riders only: last heartbeat from the dispatch board; stale = treated as offline';

-- The availability query filters on role + duty and orders by last seen.
ALTER TABLE `users`
ADD INDEX IF NOT EXISTS `idx_driver_duty` (`role`, `driver_on_duty`, `driver_last_seen`);

-- Assignment sweeps look for shipped deliveries with no rider yet.
ALTER TABLE `orders`
ADD INDEX IF NOT EXISTS `idx_unassigned_delivery` (`delivery_method`, `status`, `assigned_driver_id`);
