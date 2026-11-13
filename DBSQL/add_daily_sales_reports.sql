-- End-of-day sales report submissions.
-- File: DBSQL/add_daily_sales_reports.sql
--
-- The sales report page (admin/sales_report.php) reads live order and payment
-- rows, so what it shows for a given day keeps changing as long as orders keep
-- arriving. That is right for analysis and wrong for a day's close: a closing
-- report is a statement someone signed off on at a point in time, and it has to
-- stay readable as that even after the underlying rows move.
--
-- So one row per business day, holding the figures AS SUBMITTED plus who closed
-- the day and when. report_date is UNIQUE - a day closes once. Late orders are
-- handled by re-submitting, which overwrites the snapshot and records the
-- amendment (amended_at / amended_by / amend_count) rather than quietly
-- replacing the original with no trace that it changed.
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS `daily_sales_reports` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `report_date` DATE NOT NULL,

  -- The figures as they stood at submission - deliberately a snapshot, not a
  -- view over orders/payments. Columns mirror the report's own summary row.
  `instore_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cod_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `online_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_sales` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `order_count` INT(11) NOT NULL DEFAULT 0,
  `refund_count` INT(11) NOT NULL DEFAULT 0,
  `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,

  -- Cash counted in the drawer at close, when the admin enters it. NULL means
  -- "not counted", which is not the same claim as 0.00 - hence nullable. The
  -- variance against in-store sales is derived on read, never stored, so it
  -- cannot fall out of step with the two numbers it comes from.
  `cash_counted` DECIMAL(12,2) NULL,
  `notes` TEXT NULL,

  `submitted_by` INT(11) NULL,
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `amended_by` INT(11) NULL,
  `amended_at` TIMESTAMP NULL,
  `amend_count` INT(11) NOT NULL DEFAULT 0,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_report_date` (`report_date`),
  KEY `idx_submitted_by` (`submitted_by`),
  KEY `idx_submitted_at` (`submitted_at`),
  CONSTRAINT `fk_dsr_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dsr_amended_by` FOREIGN KEY (`amended_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
