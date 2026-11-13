-- Product-level tags, for "Similar Products" recommendations.
--
-- The pre-existing `tags` / `category_tags` pair attaches tags to CATEGORIES,
-- which can only ever produce one feature vector per category - useless for
-- ranking products *within* a category (every motherboard looks identical).
-- This table attaches structured key/value tags to individual products, so
-- two motherboards can be compared on socket, chipset, RAM type, form factor,
-- brand and price tier.
--
-- Key/value rather than flat labels: 'socket=AM4' and 'ram_type=DDR4' are
-- comparable dimensions, so the recommender can weight a socket match higher
-- than a brand match instead of treating all tags as equal set membership.
--
-- confidence: 100 = entered/confirmed by an admin, <100 = derived by
-- execution/backfill_product_tags.php from the product's name/description.
-- A backfill only ever overwrites rows it derived itself (confidence < 100),
-- so admin corrections survive re-running it.

CREATE TABLE IF NOT EXISTS `product_tags` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `product_id` INT(11) NOT NULL,
  `tag_key`    VARCHAR(40) NOT NULL,
  `tag_value`  VARCHAR(80) NOT NULL,
  `confidence` TINYINT(4) NOT NULL DEFAULT 80,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_tag` (`product_id`, `tag_key`, `tag_value`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_lookup` (`tag_key`, `tag_value`),
  CONSTRAINT `fk_product_tags_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
