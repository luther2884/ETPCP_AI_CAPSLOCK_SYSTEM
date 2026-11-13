-- add_review_image.sql
-- One optional customer-uploaded photo per review. Set once (at submit or
-- via a one-time "Add a photo" action on an existing review) and never
-- changed afterwards - the API enforces `image_path IS NULL` before writing.
USE `techworld_ecommerce`;

ALTER TABLE `reviews`
  ADD COLUMN IF NOT EXISTS `image_path` VARCHAR(255) NULL AFTER `comment`;
