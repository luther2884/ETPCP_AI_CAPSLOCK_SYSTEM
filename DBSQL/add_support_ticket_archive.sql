-- add_support_ticket_archive.sql
--
-- Lets staff archive a resolved support ticket: it leaves the inbox without
-- being deleted, and can be brought back.
--
-- WHY A FLAG AND NOT A STATUS
--
-- `support_tickets.status` already has six values and two of them
-- ('in_progress', 'closed') are legacy twins the UI displays as something else
-- (see SUPPORT_STATUS_GROUPS in public/authenticated/api/support.php). Adding
-- 'archived' to that enum would make archiving destroy information - a ticket
-- archived from 'resolved' and one archived from 'closed' would become
-- indistinguishable, and un-archiving could not know where to put it back.
-- Archive is a separate axis from state, so it gets its own column and the
-- status is left exactly as it was found.
--
-- `is_archived TINYINT(1) NOT NULL DEFAULT 0` follows the one precedent in
-- this schema: promotional_images.is_archived (DBSQL/add_promotional_images
-- _archived.sql). `users` has no such column - CusAccArc.php derives "archived"
-- from inactivity instead - so that page is not a precedent for a stored flag.
--
-- Additive and idempotent. Deletes nothing, changes no existing row's meaning,
-- and every ticket starts un-archived, so the inbox looks identical until
-- somebody presses the button.

USE `techworld_ecommerce`;

-- ---------------------------------------------------------------------------
-- 1. The flag and when it was set
-- ---------------------------------------------------------------------------
-- Guarded through INFORMATION_SCHEMA rather than ADD COLUMN IF NOT EXISTS:
-- XAMPP here is MariaDB, and the file has to be safe to re-run.
SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'support_tickets'
    AND COLUMN_NAME = 'is_archived'
);
SET @ddl := IF(@col = 0,
  'ALTER TABLE `support_tickets`
     ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = out of the active inbox. Only ever set on a resolved/closed ticket; reversible.''
       AFTER `priority`,
     ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL
       COMMENT ''When it was archived. Cleared on un-archive so the column never implies a state the flag contradicts.''
       AFTER `is_archived`',
  'SELECT ''support_tickets.is_archived already present - skipped'' AS note'
);
PREPARE s FROM @ddl;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 2. Index for the inbox's own filter
-- ---------------------------------------------------------------------------
-- Every list this feature draws is "un-archived, ordered by whether it still
-- needs work" - so the index leads with is_archived and carries status, which
-- is the other column in that WHERE/ORDER BY. idx_status already exists and is
-- left alone.
SET @idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'support_tickets'
    AND INDEX_NAME = 'idx_archived_status'
);
SET @ddl2 := IF(@idx = 0,
  'ALTER TABLE `support_tickets` ADD KEY `idx_archived_status` (`is_archived`, `status`)',
  'SELECT ''idx_archived_status already present - skipped'' AS note'
);
PREPARE s2 FROM @ddl2;
EXECUTE s2;
DEALLOCATE PREPARE s2;

-- ---------------------------------------------------------------------------
-- 3. What it looks like now
-- ---------------------------------------------------------------------------
-- Every existing ticket is un-archived, so the inbox is unchanged by this file.
SELECT `is_archived`, `status`, COUNT(*) AS tickets
  FROM `support_tickets`
 GROUP BY `is_archived`, `status`
 ORDER BY `is_archived`, `status`;
