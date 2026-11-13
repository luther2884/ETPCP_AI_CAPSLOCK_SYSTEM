-- File: DBSQL/add_review_images_table.sql
-- Multi-photo reviews: a review can now carry up to 5 customer photos.
-- Previously it was a single `reviews.image_path` column (one photo, one-time).
-- That column is kept for backward compatibility but is no longer written to;
-- reads come from this table. Existing single photos are migrated below.

USE `techworld_ecommerce`;

CREATE TABLE IF NOT EXISTS `review_images` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `review_id` INT(11) NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_id` (`review_id`),
  CONSTRAINT `fk_review_images_review`
    FOREIGN KEY (`review_id`) REFERENCES `reviews`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time migration of the legacy single photo into the new table.
INSERT INTO `review_images` (`review_id`, `image_path`)
SELECT r.`id`, r.`image_path`
FROM `reviews` r
WHERE r.`image_path` IS NOT NULL AND r.`image_path` <> ''
  AND NOT EXISTS (
    SELECT 1 FROM `review_images` ri
    WHERE ri.`review_id` = r.`id` AND ri.`image_path` = r.`image_path`
  );
