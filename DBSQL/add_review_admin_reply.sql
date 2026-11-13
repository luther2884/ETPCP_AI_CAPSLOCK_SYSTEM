USE `techworld_ecommerce`;

-- Admin (store) replies to customer reviews - AI-drafted, admin-approved
-- (see api/ai_suggest_review_reply.php), shown to the customer on the
-- product page under their review once saved.
ALTER TABLE `reviews`
  ADD COLUMN IF NOT EXISTS `admin_reply` TEXT NULL AFTER `comment`,
  ADD COLUMN IF NOT EXISTS `admin_reply_at` TIMESTAMP NULL AFTER `admin_reply`,
  ADD COLUMN IF NOT EXISTS `admin_reply_by` INT(11) NULL AFTER `admin_reply_at`,
  ADD INDEX IF NOT EXISTS `idx_admin_reply_by` (`admin_reply_by`);
