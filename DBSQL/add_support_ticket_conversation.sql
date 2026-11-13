-- DBSQL/add_support_ticket_conversation.sql
--
-- Two-way support ticket conversation between a customer and the admin.
-- Backs customer/support.php and admin/support_dashboard.php.
--
-- Nothing here drops or rewrites a ticket or a message. support_tickets and
-- support_ticket_messages already exist (DBSQL/40_support_and_service.sql) and
-- already hold live threads; this only widens the status set and adds the two
-- read markers the unread badges need.

USE `techworld_ecommerce`;

-- Status set.
--
-- The feature asks for Open / Pending / Resolved / Reopened. 'pending' and
-- 'reopened' are NEW; 'in_progress' and 'closed' are kept rather than renamed
-- because existing rows carry them and an ENUM that drops a value in use
-- silently turns those rows into ''. The UI maps the two legacy values onto
-- the four it shows (in_progress -> Pending, closed -> Resolved), so nothing
-- is lost and no row has to be rewritten.
ALTER TABLE `support_tickets`
  MODIFY COLUMN `status`
    ENUM('open','pending','in_progress','resolved','closed','reopened')
    NOT NULL DEFAULT 'open';

-- Read markers, one per side.
--
-- These hold the id of the last message that side has seen, NOT a timestamp.
--
-- A timestamp was the first attempt and it is quietly wrong: TIMESTAMP has
-- one-second resolution, so an admin reply written in the same second as the
-- customer's own last action compares as "not newer than last read" and the
-- customer's badge never lights. Two people typing at each other hit that
-- constantly. Message ids are monotonic and exact, so "newer than what I last
-- saw" is an integer comparison with no resolution to lose.
--
-- 0 means the side has never opened the thread, so every message counts as
-- unread - the right answer for a ticket nobody has looked at yet.
ALTER TABLE `support_tickets`
  ADD COLUMN IF NOT EXISTS `customer_last_read_id` INT(11) NOT NULL DEFAULT 0 AFTER `assigned_to`,
  ADD COLUMN IF NOT EXISTS `admin_last_read_id`    INT(11) NOT NULL DEFAULT 0 AFTER `customer_last_read_id`;

-- The timestamp pair from the first cut of this migration. Dropped rather than
-- left in place: nothing reads them, and two unused "last read" columns beside
-- the two real ones is an invitation to wire up the wrong pair later.
ALTER TABLE `support_tickets`
  DROP COLUMN IF EXISTS `customer_last_read_at`,
  DROP COLUMN IF EXISTS `admin_last_read_at`;

-- The unread count is "messages on this ticket with an id above the marker",
-- so the thread's messages are read in ticket + id order on every badge
-- refresh.
ALTER TABLE `support_ticket_messages`
  ADD INDEX IF NOT EXISTS `idx_ticket_id_msg` (`ticket_id`, `id`);
