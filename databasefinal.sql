-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 09, 2026 at 07:26 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `techworld_ecommerce`
--

-- --------------------------------------------------------

--
-- Table structure for table `delivery_tracking`
--

CREATE TABLE `delivery_tracking` (
  `tracking_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `accuracy` decimal(8,2) DEFAULT NULL,
  `is_tracking` tinyint(1) NOT NULL DEFAULT 1,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `delivery_tracking`
--

INSERT INTO `delivery_tracking` (`tracking_id`, `order_id`, `driver_id`, `latitude`, `longitude`, `accuracy`, `is_tracking`, `last_updated`, `created_at`) VALUES
(2, 7, 4, 14.6594246, 120.9688804, NULL, 0, '2026-09-02 22:20:32', '2026-09-02 15:22:22'),
(3, 8, 4, 14.6595090, 120.9688835, NULL, 0, '2026-09-02 15:55:39', '2026-09-02 15:53:21'),
(4, 9, 4, 14.6595693, 120.9688224, NULL, 0, '2026-09-02 22:15:57', '2026-09-02 16:28:14'),
(5, 12, 4, 14.6594246, 120.9688804, NULL, 0, '2026-09-02 21:11:35', '2026-09-02 21:09:52'),
(6, 14, 4, 14.6594246, 120.9688804, NULL, 0, '2026-09-02 22:43:14', '2026-09-02 22:42:43'),
(7, 19, 4, 14.6594246, 120.9688804, NULL, 0, '2026-09-03 01:20:59', '2026-09-03 01:19:52'),
(8, 21, 4, 14.6500000, 120.9880000, 10.00, 0, '2026-09-10 10:39:10', '2026-09-10 10:36:55'),
(9, 23, 4, 14.6500000, 120.9880000, 10.00, 1, '2026-09-10 10:48:03', '2026-09-10 10:48:03'),
(10, 24, 4, 14.6594130, 120.9688800, NULL, 1, '2026-09-10 11:01:26', '2026-09-10 11:01:26'),
(11, 25, 4, 14.6500000, 120.9880000, 10.00, 1, '2026-09-10 12:58:25', '2026-09-10 12:58:25'),
(12, 26, 4, 14.6594246, 120.9688804, NULL, 1, '2026-09-10 13:05:40', '2026-09-10 13:05:40'),
(13, 27, 4, 14.6595620, 120.9688339, NULL, 1, '2026-09-10 13:26:00', '2026-09-10 13:26:00'),
(14, 28, 4, 14.6595672, 120.9688342, NULL, 1, '2026-09-10 13:28:32', '2026-09-10 13:28:32'),
(15, 29, 4, 14.6595578, 120.9688249, NULL, 0, '2026-09-10 15:07:42', '2026-09-10 15:07:18'),
(16, 30, 4, 14.6594859, 120.9670172, NULL, 0, '2026-09-12 13:54:25', '2026-09-10 15:28:49'),
(17, 31, 4, 14.6595654, 120.9688236, NULL, 0, '2026-09-10 15:34:00', '2026-09-10 15:33:19'),
(18, 32, 4, 14.6595692, 120.9688302, NULL, 0, '2026-09-10 15:40:16', '2026-09-10 15:37:15'),
(19, 33, 4, 14.6595696, 120.9688305, NULL, 0, '2026-09-10 15:48:44', '2026-09-10 15:48:07'),
(20, 34, 4, 14.6595988, 120.9684514, NULL, 0, '2026-09-10 16:22:44', '2026-09-10 15:59:28'),
(21, 44, 4, 14.6500000, 120.9880000, 10.00, 0, '2026-09-23 11:37:52', '2026-09-16 18:27:37'),
(23, 83, 4, 14.6500000, 120.9880000, 10.00, 1, '2026-10-06 10:32:54', '2026-10-06 10:32:54'),
(24, 112, 4, 14.6500000, 120.9880000, 10.00, 1, '2026-10-06 11:11:38', '2026-10-06 11:11:38'),
(25, 111, 4, 14.6594854, 120.9688654, NULL, 1, '2026-10-06 13:43:55', '2026-10-06 13:43:55'),
(26, 122, 4, 14.6594526, 120.9688628, NULL, 1, '2026-10-08 10:50:45', '2026-10-08 10:50:45'),
(27, 123, 4, 14.6594526, 120.9688628, NULL, 0, '2026-10-08 11:07:08', '2026-10-08 11:00:44'),
(28, 125, 4, 14.6594975, 120.9688678, NULL, 1, '2026-10-08 16:22:35', '2026-10-08 16:22:35'),
(30, 127, 4, 14.5995000, 120.9842000, 12.50, 1, '2026-10-08 16:57:29', '2026-10-08 16:35:39'),
(31, 128, 4, 14.6594975, 120.9688678, NULL, 0, '2026-10-08 16:52:45', '2026-10-08 16:52:18'),
(33, 134, 18, 14.6595573, 120.9688233, 20.40, 1, '2026-10-08 20:25:47', '2026-10-08 20:23:41'),
(47, 138, 18, 14.6594287, 120.9688595, 124.00, 1, '2026-10-09 15:11:22', '2026-10-09 15:02:16');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  ADD PRIMARY KEY (`tracking_id`),
  ADD UNIQUE KEY `uniq_order_id` (`order_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_last_updated` (`last_updated`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  MODIFY `tracking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  ADD CONSTRAINT `fk_delivery_tracking_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_delivery_tracking_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
