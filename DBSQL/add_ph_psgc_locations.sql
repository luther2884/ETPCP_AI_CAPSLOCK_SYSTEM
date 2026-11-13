-- add_ph_psgc_locations.sql
-- Full Philippine location reference data for the checkout address picker.
--
-- config/ph_locations.php shipped with a hand-curated list of ~55 cities, so
-- anything outside it (Bulacan, for one) simply could not be found in the
-- address search. These two tables hold the complete official PSGC set -
-- 1,634 cities/municipalities and ~42,000 barangays - which is far too much
-- to keep in a PHP array.
--
-- Populate with: php execution/import_ph_psgc.php
-- config/ph_locations.php falls back to its curated arrays when these tables
-- are missing or empty, so the site keeps working before the import is run.

CREATE TABLE IF NOT EXISTS `ph_cities` (
  `code`          VARCHAR(12)  NOT NULL,        -- PSGC code, e.g. 031410000
  `name`          VARCHAR(150) NOT NULL,        -- "City of Malolos"
  `province_name` VARCHAR(150) NOT NULL,        -- region name for NCR, which has no provinces
  `region_name`   VARCHAR(150) NOT NULL,
  `island_group`  VARCHAR(20)  NOT NULL DEFAULT '',
  `is_city`       TINYINT(1)   NOT NULL DEFAULT 0,
  -- Postal codes and coordinates are not part of PSGC. They are carried over
  -- from the curated dataset for the cities that had them, and stay NULL
  -- elsewhere: postal is then typed by the customer, and reverse geocoding
  -- only considers rows that actually have coordinates.
  `postal`        VARCHAR(20)  NULL DEFAULT NULL,
  `lat`           DECIMAL(9,6) NULL DEFAULT NULL,
  `lng`           DECIMAL(9,6) NULL DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `idx_ph_cities_name` (`name`),
  KEY `idx_ph_cities_province` (`province_name`),
  KEY `idx_ph_cities_region` (`region_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ph_barangays` (
  `code`      VARCHAR(12)  NOT NULL,
  `name`      VARCHAR(150) NOT NULL,
  `city_code` VARCHAR(12)  NOT NULL,
  PRIMARY KEY (`code`),
  KEY `idx_ph_barangays_city` (`city_code`),
  KEY `idx_ph_barangays_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
