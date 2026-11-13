-- File: DBSQL/add_support_ticket_fields.sql
--
-- The columns api/support.php has always INSERTed into, finally added.
--
-- createSupportTicket() writes `category`, `order_number`, `phone` and then
-- SELECTs `ticket_number` back out - but 40_support_and_service.sql creates
-- support_tickets with none of those four. So the INSERT was guaranteed to
-- throw "Unknown column" on any install, which is one of the three reasons the
-- customer contact form on customer/support.php could never send a message
-- (the other two: it POSTed to /api/support.php, which does not exist, and the
-- endpoint took the submitter's identity from the request body). The table has
-- held 0 rows since it was created, so nothing had ever exercised the path.
--
-- The customer form (customer/support.php) collects every one of these:
-- a topic dropdown, an optional order number, an optional phone.

USE `techworld_ecommerce`;

ALTER TABLE `support_tickets`
  -- The topic dropdown's value (product_inquiry, order_support, ...). The API
  -- validates it against a whitelist and derives the human `subject` line from
  -- it, so this stays a stable key that an admin filter can group on while
  -- `subject` stays readable.
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(40) NOT NULL DEFAULT 'other' AFTER `subject`,
  -- Free text, not an FK: the customer types what they have, which may be a
  -- mistyped or long-deleted order. Support has to be able to receive "my
  -- order 12345 never arrived" even when 12345 does not exist.
  ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(50) NULL AFTER `category`,
  -- Contact number for THIS ticket. Deliberately not read from users.phone:
  -- "call me on this one instead" is a normal thing to want, and overwriting
  -- the account's number because of one support request would be worse.
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(30) NULL AFTER `order_number`,
  -- The human reference the confirmation message promises the customer
  -- ("your ticket number is ..."). Nullable + UNIQUE rather than NOT NULL: it
  -- is derived from the row's own id, so it can only be written in a second
  -- statement after the INSERT, inside the same transaction.
  ADD COLUMN IF NOT EXISTS `ticket_number` VARCHAR(24) NULL AFTER `id`;

-- Added separately from the column: ADD INDEX has no IF NOT EXISTS in older
-- MariaDB, and a failure here must not take the columns above down with it.
-- Safe to see "Duplicate key name" on a re-run.
ALTER TABLE `support_tickets`
  ADD UNIQUE KEY `uniq_ticket_number` (`ticket_number`);

-- Backfill anything that predates this file, so the column can be relied on.
UPDATE `support_tickets`
   SET `ticket_number` = CONCAT('TW-', DATE_FORMAT(`created_at`, '%Y%m%d'), '-', LPAD(`id`, 5, '0'))
 WHERE `ticket_number` IS NULL;
