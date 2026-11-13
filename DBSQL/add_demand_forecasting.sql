-- Demand Forecasting & Smart Reorder Suggestions.
--
-- Two pieces:
--   1. inventory_settings - global, admin-tunable forecast parameters
--      (key/value, same shape as support_settings).
--   2. products.lead_time_days / max_stock_level - optional PER-PRODUCT
--      overrides. NULL means "use the global setting", so the defaults stay
--      in one place and only the exceptions carry their own value.
--
-- Nothing here stores a forecast. Forecasts are computed on read from
-- order_items, so they can never go stale or disagree with the order data
-- they came from.

CREATE TABLE IF NOT EXISTS `inventory_settings` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `inventory_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('forecast_window_days', '7',
   'Sales history window used to measure the average daily sales rate (7, 14 or 30).'),
  ('forecast_horizon_days', '7',
   'How far ahead demand is forecast. Usually the same as the window.'),
  ('safety_stock_days', '3',
   'Extra days of demand held as a buffer against a sales spike or a late delivery.'),
  ('default_lead_time_days', '7',
   'Days between placing a purchase order and the stock arriving. Used when a product has no lead time of its own.'),
  ('max_stock_days', '60',
   'Overstock ceiling: never suggest an order that would push stock past this many days of demand.'),
  ('min_order_qty', '1',
   'Suggestions below this are rounded up to it; a suggestion of 0 stays 0.'),
  ('trend_weighting', '1',
   'When 1, a rising or falling recent trend adjusts the forecast. When 0, the flat average is used.')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- Per-product overrides. NULL = fall back to the global setting above.
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `lead_time_days` INT(11) DEFAULT NULL
    COMMENT 'Supplier lead time in days. NULL uses inventory_settings.default_lead_time_days.',
  ADD COLUMN IF NOT EXISTS `max_stock_level` INT(11) DEFAULT NULL
    COMMENT 'Hard ceiling on units held. NULL derives one from max_stock_days.';
