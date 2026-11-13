-- 73_seed_cctv_printer_ink_networking.sql
-- Adds four new product categories the store also offers: CCTV & Security,
-- Printers, Ink & Toner, and Networking. These are standalone catalog items,
-- not part of the AI Builder's PC-component roles (same treatment as
-- Speakers/Headsets/Keyboards & Combos already get).
-- Same staging-table pattern as 72_seed_storage_case_cooler.sql.
-- Run after 72_seed_storage_case_cooler.sql.

USE `techworld_ecommerce`;

INSERT IGNORE INTO `categories` (`name`) VALUES ('CCTV & Security'), ('Printers'), ('Ink & Toner'), ('Networking');

DROP TEMPORARY TABLE IF EXISTS `seed_products_v3`;
CREATE TEMPORARY TABLE `seed_products_v3` (
	`category_name` VARCHAR(100) NOT NULL,
	`product_name` VARCHAR(255) NOT NULL,
	`qty` INT NOT NULL,
	`price` DECIMAL(10,2) NOT NULL,
	`description` TEXT NOT NULL
);

INSERT INTO `seed_products_v3` (`category_name`, `product_name`, `qty`, `price`, `description`) VALUES
	('CCTV & Security','Dahua 4CH 2MP HDCVI Camera Kit (2 Bullet + 2 Turret + DVR)',3,8780,'Dahua 4-channel 2MP HDCVI camera kit: 4-channel DVR, 2 bullet cameras, 2 turret cameras. Complete plug-and-play CCTV kit, HDD sold separately.'),
	('CCTV & Security','Dahua 8CH 2MP HDCVI Camera Kit with 1TB HDD',2,15040,'Dahua 8-channel 2MP HDCVI camera kit with 8-channel DVR, 8 cameras, and a pre-installed 1TB surveillance hard drive.'),
	('CCTV & Security','Hikvision 4CH 2MP Lite ColorVu Camera Kit',2,13830,'Hikvision 4-channel 2MP Lite ColorVu camera kit: 4-channel DVR + 4 ColorVu cameras for full-color night vision.'),
	('CCTV & Security','Hikvision 8CH 2MP Lite ColorVu Camera Kit',2,24720,'Hikvision 8-channel 2MP Lite ColorVu camera kit: 8-channel DVR + 8 ColorVu cameras for full-color night vision.'),
	('CCTV & Security','Hikvision 2MP Turbo HD Bullet Camera',8,1200,'Hikvision 2MP Turbo HD outdoor bullet camera. Standalone camera, weatherproof housing, add-on for an existing DVR system.'),
	('CCTV & Security','Dahua 2MP HDCVI Dome Camera',8,1100,'Dahua 2MP HDCVI indoor dome camera. Standalone camera, add-on for an existing DVR system.'),
	('CCTV & Security','Hikvision 4CH Turbo HD DVR',4,2800,'Hikvision 4-channel Turbo HD digital video recorder. Standalone DVR, cameras sold separately, HDD sold separately.'),
	('CCTV & Security','Dahua 8CH XVR Digital Video Recorder',3,4200,'Dahua 8-channel XVR (Penta-brid) digital video recorder, supports HDCVI/analog/IP camera inputs. Standalone DVR, cameras sold separately.'),
	('CCTV & Security','WD Purple 3TB Surveillance HDD',5,3600,'Western Digital Purple 3TB 3.5" surveillance-rated HDD, built for 24/7 CCTV DVR/NVR recording.'),
	('CCTV & Security','CCTV Power Supply and Cable Kit',10,650,'CCTV 12V power supply adapter with BNC video and DC power cables - accessory kit for connecting an analog camera to a DVR.'),
	('Printers','Epson L121 Ink Tank Printer',5,5495,'Epson L121 ink tank printer (print only). Compact, refillable ink tank system for low cost-per-page printing.'),
	('Printers','Epson L1210 Ink Tank Printer',4,7945,'Epson L1210 ink tank printer (print only). Refillable ink tank system for low cost-per-page printing.'),
	('Printers','Epson L3210 Ink Tank All-in-One Printer',4,8795,'Epson L3210 ink tank all-in-one printer - print, scan, and copy. Refillable ink tank system.'),
	('Printers','Epson L1250 Wi-Fi Ink Tank Printer',3,8345,'Epson L1250 ink tank printer (print only) with built-in Wi-Fi for wireless printing.'),
	('Printers','Canon PIXMA G1010 Ink Tank Printer',4,4795,'Canon PIXMA G1010 ink tank printer (print only). Refillable ink tank system for low cost-per-page printing.'),
	('Printers','Canon PIXMA G2020 All-in-One Ink Tank Printer',4,6500,'Canon PIXMA G2020 ink tank all-in-one printer - print, scan, and copy. Refillable ink tank system.'),
	('Printers','Canon PIXMA G3730 Wi-Fi All-in-One Ink Tank Printer',3,9995,'Canon PIXMA G3730 ink tank all-in-one printer with built-in Wi-Fi - print, scan, copy, and mobile printing.'),
	('Printers','HP LaserJet M111w Wireless Laser Printer',3,6900,'HP LaserJet M111w monochrome laser printer (print only) with built-in Wi-Fi for wireless printing.'),
	('Printers','Brother HL-L2321D Laser Printer',3,7200,'Brother HL-L2321D monochrome laser printer (print only) with automatic duplex (double-sided) printing.'),
	('Ink & Toner','Epson 003 Black Ink Bottle',15,310,'Epson 003 genuine black ink bottle for Epson L-series ink tank printers.'),
	('Ink & Toner','Epson 003 Cyan Ink Bottle',12,320,'Epson 003 genuine cyan ink bottle for Epson L-series ink tank printers.'),
	('Ink & Toner','Epson 003 Magenta Ink Bottle',12,320,'Epson 003 genuine magenta ink bottle for Epson L-series ink tank printers.'),
	('Ink & Toner','Epson 003 Yellow Ink Bottle',12,320,'Epson 003 genuine yellow ink bottle for Epson L-series ink tank printers.'),
	('Ink & Toner','Epson 664 Black Ink Bottle 70ml',12,310,'Epson 664 genuine black ink bottle (70ml) for Epson L-series ink tank printers.'),
	('Ink & Toner','Canon PG-89 Black Ink Cartridge',10,450,'Canon PG-89 genuine black ink cartridge for Canon PIXMA printers.'),
	('Ink & Toner','Canon CL-746 Color Ink Cartridge',10,710,'Canon CL-746 genuine tri-color ink cartridge for Canon PIXMA printers.'),
	('Ink & Toner','HP 12A Black LaserJet Toner Cartridge',6,3200,'HP 12A (Q2612A) genuine black toner cartridge for HP LaserJet printers.'),
	('Ink & Toner','HP 85A Black LaserJet Toner Cartridge',6,3800,'HP 85A (CE285A) genuine black toner cartridge for HP LaserJet printers.'),
	('Ink & Toner','Brother TN-2321 Toner Cartridge',6,3500,'Brother TN-2321 genuine black toner cartridge for Brother HL-L2321D and compatible laser printers.'),
	('Networking','Tenda AC5 AC1200 Dual-Band WiFi Router',8,895,'Tenda AC5 AC1200 dual-band wireless router, WISP/access point/repeater modes supported.'),
	('Networking','Tenda AC6 AC1200 Dual-Band WiFi Router',6,1169,'Tenda AC6 v5.0 AC1200 dual-band wireless router with remote management via the Tenda app.'),
	('Networking','TP-Link Archer C54 AC1200 WiFi Router',8,1100,'TP-Link Archer C54 AC1200 dual-band wireless router.'),
	('Networking','TP-Link Deco E4 AC1200 Mesh WiFi System (1-Pack)',6,1650,'TP-Link Deco E4 AC1200 whole-home mesh Wi-Fi system, single unit - add more units to extend coverage.'),
	('Networking','TP-Link Deco E4 AC1200 Mesh WiFi System (2-Pack)',4,3150,'TP-Link Deco E4 AC1200 whole-home mesh Wi-Fi system, 2-unit pack for larger homes.'),
	('Networking','Tenda Nova MW6 AC1200 Mesh WiFi System (3-Pack)',3,2300,'Tenda Nova MW6 AC1200 whole-home mesh Wi-Fi system, 3-unit pack.'),
	('Networking','TP-Link TL-SF1008D 8-Port Fast Ethernet Switch',8,650,'TP-Link TL-SF1008D unmanaged 8-port 10/100Mbps Fast Ethernet switch.'),
	('Networking','TP-Link TL-WA850RE WiFi Range Extender',6,750,'TP-Link TL-WA850RE Wi-Fi range extender/repeater, N300.'),
	('Networking','Cat6 Ethernet Cable 305m Box',4,2800,'Cat6 UTP solid-core ethernet cable, 305-meter pull box - for structured network cabling runs.'),
	('Networking','RJ45 Connector Pack (100pcs)',10,250,'RJ45 Cat6 modular connector plugs, pack of 100 - for terminating ethernet cable runs.');

UPDATE `products` p
JOIN `seed_products_v3` sp ON sp.`product_name` = p.`name`
JOIN `categories` c ON c.`name` = sp.`category_name`
SET p.`stock` = p.`stock` + sp.`qty`,
    p.`price` = IF(p.`price` IS NULL OR p.`price` = 0, sp.`price`, p.`price`),
    p.`description` = sp.`description`,
    p.`status` = 'active',
    p.`category_id` = c.`id`,
    p.`image_url` = CONCAT('public/shared/img/products/', sp.`product_name`, '.png'),
    p.`updated_at` = NOW();

INSERT INTO `products` (`name`, `description`, `price`, `stock`, `category_id`, `image_url`, `status`, `created_at`)
SELECT sp.`product_name`, sp.`description`, sp.`price`, sp.`qty`, c.`id`,
       CONCAT('public/shared/img/products/', sp.`product_name`, '.png'), 'active', NOW()
FROM `seed_products_v3` sp
JOIN `categories` c ON c.`name` = sp.`category_name`
LEFT JOIN `products` p ON p.`name` = sp.`product_name`
WHERE p.`id` IS NULL;

