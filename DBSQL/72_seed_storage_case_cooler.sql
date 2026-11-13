-- 72_seed_storage_case_cooler.sql
-- Adds the Storage/PC Case/CPU Cooler product data the AI Builder needs to
-- recommend a genuinely complete PC build (see config/ai_builder_engine.php -
-- these three roles previously had zero or no matching category at all).
-- Same staging-table + update-existing/insert-new pattern as
-- 70_seed_products_from_list.sql, extended with a description column so these
-- new products carry their specs (type/capacity, socket support, TDP rating)
-- from the start, instead of needing a companion backfill file.
-- Run after 71_ai_builder_spec_descriptions.sql.

USE `techworld_ecommerce`;

INSERT IGNORE INTO `categories` (`name`) VALUES ('PC Case'), ('CPU Cooler');

DROP TEMPORARY TABLE IF EXISTS `seed_products_v2`;
CREATE TEMPORARY TABLE `seed_products_v2` (
	`category_name` VARCHAR(100) NOT NULL,
	`product_name` VARCHAR(255) NOT NULL,
	`qty` INT NOT NULL,
	`price` DECIMAL(10,2) NOT NULL,
	`description` TEXT NOT NULL
);

INSERT INTO `seed_products_v2` (`category_name`, `product_name`, `qty`, `price`, `description`) VALUES
	('Storage','Kingston A400 240GB SATA SSD',6,1050,'Kingston A400 240GB 2.5" SATA III SSD. SATA SSD, 240GB capacity - fast, reliable boot/OS drive upgrade over a mechanical HDD.'),
	('Storage','Kingston A400 480GB SATA SSD',6,1850,'Kingston A400 480GB 2.5" SATA III SSD. SATA SSD, 480GB capacity.'),
	('Storage','WD Green 480GB SATA SSD',5,1900,'Western Digital Green 480GB 2.5" SATA III SSD. SATA SSD, 480GB capacity.'),
	('Storage','WD Blue 1TB SATA SSD',4,2900,'Western Digital Blue 1TB 2.5" SATA III SSD. SATA SSD, 1TB capacity.'),
	('Storage','Adata SU650 240GB SATA SSD',6,1000,'ADATA SU650 240GB 2.5" SATA III SSD. SATA SSD, 240GB capacity.'),
	('Storage','Adata SU650 480GB SATA SSD',5,1800,'ADATA SU650 480GB 2.5" SATA III SSD. SATA SSD, 480GB capacity.'),
	('Storage','Transcend SSD230S 512GB SATA SSD',4,1950,'Transcend SSD230S 512GB 2.5" SATA III SSD. SATA SSD, 512GB capacity.'),
	('Storage','Team Group GX2 512GB SATA SSD',4,1750,'Team Group GX2 512GB 2.5" SATA III SSD. SATA SSD, 512GB capacity.'),
	('Storage','Kingston NV2 500GB NVMe SSD',6,2100,'Kingston NV2 500GB M.2 PCIe NVMe SSD. NVMe SSD, 500GB capacity - far faster than SATA for OS/game load times.'),
	('Storage','Kingston NV2 1TB NVMe SSD',4,3300,'Kingston NV2 1TB M.2 PCIe NVMe SSD. NVMe SSD, 1TB capacity.'),
	('Storage','WD Blue SN580 500GB NVMe SSD',5,2500,'Western Digital Blue SN580 500GB M.2 PCIe NVMe SSD. NVMe SSD, 500GB capacity.'),
	('Storage','Adata Legend 700 512GB NVMe SSD',5,2350,'ADATA Legend 700 512GB M.2 PCIe NVMe SSD. NVMe SSD, 512GB capacity.'),
	('Storage','Team Group MP33 512GB NVMe SSD',4,2250,'Team Group MP33 512GB M.2 PCIe NVMe SSD. NVMe SSD, 512GB capacity.'),
	('Storage','Crucial P3 1TB NVMe SSD',3,3400,'Crucial P3 1TB M.2 PCIe NVMe SSD. NVMe SSD, 1TB capacity.'),
	('Storage','Seagate Barracuda 1TB HDD',6,2250,'Seagate BarraCuda 1TB 3.5" SATA desktop HDD. HDD, 1TB capacity - bulk storage for large files/media.'),
	('Storage','Seagate Barracuda 2TB HDD',4,3150,'Seagate BarraCuda 2TB 3.5" SATA desktop HDD. HDD, 2TB capacity.'),
	('Storage','WD Blue 1TB HDD',6,2200,'Western Digital Blue 1TB 3.5" SATA desktop HDD. HDD, 1TB capacity.'),
	('Storage','WD Blue 2TB HDD',4,3050,'Western Digital Blue 2TB 3.5" SATA desktop HDD. HDD, 2TB capacity.'),
	('Storage','Toshiba P300 1TB HDD',5,2150,'Toshiba P300 1TB 3.5" SATA desktop HDD. HDD, 1TB capacity.'),
	('Storage','Toshiba P300 2TB HDD',3,3100,'Toshiba P300 2TB 3.5" SATA desktop HDD. HDD, 2TB capacity.'),
	('PC Case','Value Top VT-B16 ATX Case',4,900,'Value Top VT-B16 ATX mid-tower case. Supports ATX/Micro-ATX/Mini-ITX motherboards. No-frills budget build.'),
	('PC Case','Inplay M-ATX Desktop Case V300',5,1000,'Inplay V300 Micro-ATX desktop case. Supports Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','YGT M7 PC Gaming Case Tempered Glass',5,1100,'YGT M7 Micro-ATX gaming case with tempered glass side panel. Supports Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','Aigo Darkflash DLM21 Mesh Case',4,1750,'Aigo Darkflash DLM21 Micro-ATX mesh-front case for improved airflow. Supports Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','Segotep Halo 6 ARGB Case',4,1500,'Segotep Halo 6 Micro-ATX case with 3 pre-installed ARGB fans. Supports Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','Xigmatek Aquarius Plus RGB Case',3,1700,'Xigmatek Aquarius Plus ATX case with tempered glass and RGB fans. Supports ATX/Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','Cooler Master MasterBox Q300L Case',3,2400,'Cooler Master MasterBox Q300L Micro-ATX case with magnetic dust filter and see-through side panel. Supports ATX/Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','Antec NX310 ARGB Mid Tower Case',3,2600,'Antec NX310 ATX mid-tower case with tempered glass and ARGB fans. Supports ATX/Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','Coolman Spectra M-ATX Dual Chamber Case',3,2100,'Coolman Spectra Micro-ATX dual-chamber case for cleaner cable routing. Supports Micro-ATX/Mini-ITX motherboards.'),
	('PC Case','DeepCool CH170 PLUS M-ATX Case',2,3200,'DeepCool CH170 PLUS Micro-ATX case with mesh front panel and pre-installed fans. Supports Micro-ATX/Mini-ITX motherboards.'),
	('CPU Cooler','DeepCool AK400 CPU Air Cooler',6,1350,'DeepCool AK400 single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 220W TDP.'),
	('CPU Cooler','DeepCool AG400 CPU Air Cooler',8,900,'DeepCool AG400 single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 180W TDP.'),
	('CPU Cooler','Cooler Master Hyper 212 Black Edition CPU Cooler',6,1600,'Cooler Master Hyper 212 Black Edition air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 150W TDP.'),
	('CPU Cooler','ID-Cooling SE-214-XT ARGB CPU Cooler',5,1200,'ID-Cooling SE-214-XT ARGB single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 180W TDP.'),
	('CPU Cooler','DeepCool AK620 Dual Tower CPU Cooler',4,2900,'DeepCool AK620 dual-tower, dual-fan air cooler for higher-TDP CPUs. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 260W TDP.'),
	('CPU Cooler','Xigmatek Windpower II 120 CPU Cooler',6,700,'Xigmatek Windpower II 120 single-tower air cooler. Supports Intel LGA1700/LGA1200/LGA1151/LGA1150 and AMD AM4/AM5 sockets, rated up to 130W TDP.'),
	('CPU Cooler','Intel Boxed CPU Cooler LGA1200/1151/1150',8,350,'Standard Intel boxed cooling fan. Supports Intel LGA1200/LGA1151/LGA1150 sockets only, rated up to 65W TDP.'),
	('CPU Cooler','AMD Wraith Stealth CPU Cooler AM4',8,400,'AMD Wraith Stealth boxed cooling fan. Supports AMD AM4 socket only, rated up to 65W TDP.'),
	('CPU Cooler','Value Top CP-9002 CPU Cooler',6,450,'Value Top CP-9002 budget air cooler. Supports Intel LGA1200/LGA1151/LGA1150 sockets only, rated up to 95W TDP.');

-- Step 1: update existing products (match by name) - bump stock, set
-- description/category/image, backfill price only if unpriced, always active.
UPDATE `products` p
JOIN `seed_products_v2` sp ON sp.`product_name` = p.`name`
JOIN `categories` c ON c.`name` = sp.`category_name`
SET p.`stock` = p.`stock` + sp.`qty`,
    p.`price` = IF(p.`price` IS NULL OR p.`price` = 0, sp.`price`, p.`price`),
    p.`description` = sp.`description`,
    p.`status` = 'active',
    p.`category_id` = c.`id`,
    p.`image_url` = CONCAT('public/shared/img/products/', sp.`product_name`, '.png'),
    p.`updated_at` = NOW();

-- Step 2: insert products that do not yet exist
INSERT INTO `products` (`name`, `description`, `price`, `stock`, `category_id`, `image_url`, `status`, `created_at`)
SELECT sp.`product_name`, sp.`description`, sp.`price`, sp.`qty`, c.`id`,
       CONCAT('public/shared/img/products/', sp.`product_name`, '.png'), 'active', NOW()
FROM `seed_products_v2` sp
JOIN `categories` c ON c.`name` = sp.`category_name`
LEFT JOIN `products` p ON p.`name` = sp.`product_name`
WHERE p.`id` IS NULL;

