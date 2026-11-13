-- Promotional/sales carousel shown to customers (once per login) and guests
-- (once per site visit) on the homepage. Managed by admins from
-- public/authenticated/admin/promotional_carousel.php.
CREATE TABLE IF NOT EXISTS promotional_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    image_path VARCHAR(255) NOT NULL,
    alt_text VARCHAR(255),
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    is_archived BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Seed with the images that were previously hardcoded as the carousel's
-- fallback, so switching the homepage over to the DB-backed list doesn't
-- change what's currently shown.
INSERT INTO promotional_images (title, description, image_path, alt_text, display_order, is_active)
SELECT * FROM (SELECT
    'TechWorld Complete Solutions' AS title,
    'Your one-stop shop for all computer needs' AS description,
    'public/shared/img/bg.jpg' AS image_path,
    'TechWorld Computer Store - Complete Tech Solutions' AS alt_text,
    1 AS display_order,
    1 AS is_active
UNION ALL SELECT
    'Gigabyte AERO OC RTX 4060Ti 16GB',
    'High-performance graphics card with 16GB GDDR6 memory',
    'public/shared/img/promotional/gigabyte-aero-rtx4060ti-16gb-promo.jpg',
    'aero 4060ti 16gb',
    2, 1
UNION ALL SELECT
    'Gigabyte GeForce RTX 4060Ti 8GB',
    'Powerful gaming graphics card with 8GB GDDR6 memory',
    'public/shared/img/promotional/gigabyte-geforce-rtx4060ti-8gb-promo.jpg',
    'rtx 4060ti 8gb',
    3, 1
UNION ALL SELECT
    'Gigabyte GTX 1050Ti',
    'Entry-level gaming graphics card with great performance',
    'public/shared/img/promotional/gigabyte-gtx1050ti-promo.jpg',
    'Gigabyte RTX 4060Ti 8GB',
    4, 1
UNION ALL SELECT
    'Gigabyte RX 570',
    'Reliable graphics card for gaming and productivity',
    'public/shared/img/promotional/gigabyte-rx570-promo.jpg',
    'rx-570',
    5, 1
UNION ALL SELECT
    'TechWorld Complete Solutions (16GB)',
    'Your one-stop shop for all computer needs',
    'public/shared/img/promotional/techworld-complete-solutions-promo.jpg',
    'Gigabyte RTX 4060Ti 16GB',
    6, 1
) seed
WHERE NOT EXISTS (SELECT 1 FROM promotional_images);
