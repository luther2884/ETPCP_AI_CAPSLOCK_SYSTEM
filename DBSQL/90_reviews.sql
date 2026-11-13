-- Reviews System
-- File: DBSQL/90_reviews.sql

USE `techworld_ecommerce`;

-- Product reviews table
-- Align with existing `reviews` table by adding extra columns if missing
ALTER TABLE `reviews`
  ADD COLUMN IF NOT EXISTS `title` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `comment` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `is_verified_purchase` BOOLEAN DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS `is_approved` BOOLEAN DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS `helpful_count` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Review helpfulness tracking
CREATE TABLE IF NOT EXISTS review_helpfulness (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    user_id INT NOT NULL,
    is_helpful BOOLEAN NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_review (user_id, review_id),
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert sample review data
INSERT IGNORE INTO reviews (product_id, user_id, rating, title, comment, is_verified_purchase, is_approved) VALUES
(1, 1, 5, 'Excellent product!', 'This product exceeded my expectations. Great quality and fast delivery.', TRUE, TRUE),
(1, 2, 4, 'Very good', 'Good product overall, minor issues but worth the price.', TRUE, TRUE),
(2, 1, 5, 'Amazing performance', 'Outstanding performance for the price. Highly recommended!', TRUE, TRUE),
(2, 3, 3, 'Average', 'Product is okay, nothing special but does the job.', FALSE, TRUE),
(3, 2, 5, 'Perfect!', 'Exactly what I needed. Great customer service too.', TRUE, TRUE);

-- Update helpful counts
UPDATE reviews SET helpful_count = (
    SELECT COUNT(*) FROM review_helpfulness 
    WHERE review_id = reviews.id AND is_helpful = TRUE
);
