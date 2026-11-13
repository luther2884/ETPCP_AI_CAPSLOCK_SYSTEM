-- 71_ai_builder_spec_descriptions.sql
-- Backfills products.description for CPUs and motherboards with the
-- socket / RAM-type / integrated-graphics facts the AI Builder's
-- compatibility engine (config/ai_builder_engine.php) parses via regex.
-- Without this, every CPU/motherboard socket resolves to null and the
-- engine can never recommend a motherboard or RAM alongside a CPU.
-- Run after 70_seed_products_from_list.sql.

USE `techworld_ecommerce`;

-- Processors
UPDATE `products` SET `description` = 'AMD Athlon 200GE, AM4 socket, with integrated Radeon Vega 3 graphics.' WHERE `name` = 'AMD Athlon 200GE PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 3 3200G, AM4 socket, with integrated Radeon Vega 8 graphics.' WHERE `name` = 'AMD Ryzen 3 3200G PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 3 PRO 4350G, AM4 socket, with integrated Radeon Vega 6 graphics.' WHERE `name` = 'AMD Ryzen 3 PRO 4350G Tray type Processor';
UPDATE `products` SET `description` = 'AMD Ryzen 5 2600, AM4 socket. No integrated graphics - requires a discrete GPU for video output.' WHERE `name` = 'AMD Ryzen 5 2600 PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 3600, AM4 socket. No integrated graphics - requires a discrete GPU for video output.' WHERE `name` = 'AMD Ryzen 5 3600 PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 4600G, AM4 socket, with integrated Radeon Vega 7 graphics.' WHERE `name` = 'AMD Ryzen 5 4600G PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 5500, AM4 socket. No integrated graphics - requires a discrete GPU for video output.' WHERE `name` = 'AMD Ryzen 5 5500 PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 5 5600G, AM4 socket, with integrated Radeon Vega 7 graphics.' WHERE `name` = 'AMD Ryzen 5 5600G PROCESSOR';
UPDATE `products` SET `description` = 'AMD Ryzen 7 5700G, AM4 socket, with integrated Radeon Vega 8 graphics.' WHERE `name` = 'AMD Ryzen 7 5700G PROCESSOR';
UPDATE `products` SET `description` = 'Intel Celeron G1840 (Haswell), LGA1150 socket, with integrated Intel HD Graphics.' WHERE `name` = 'INTEL CELERON G1840 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i3-10105 (Comet Lake), LGA1200 socket, with integrated UHD 630 graphics.' WHERE `name` = 'INTEL CORE I3-10105 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i3-12100 (Alder Lake), LGA1700 socket, with integrated UHD 730 graphics.' WHERE `name` = 'INTEL CORE I3-12100 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-11400F (Rocket Lake), LGA1200 socket. F-series - no integrated graphics, requires a discrete GPU for video output.' WHERE `name` = 'INTEL CORE I5-11400F PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-12400 (Alder Lake), LGA1700 socket, with integrated UHD 730 graphics.' WHERE `name` = 'INTEL CORE I5-12400 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-12400F (Alder Lake), LGA1700 socket. F-series - no integrated graphics, requires a discrete GPU for video output.' WHERE `name` = 'INTEL CORE I5-12400F PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-13400 (Raptor Lake), LGA1700 socket, with integrated UHD 730 graphics.' WHERE `name` = 'INTEL CORE I5-13400 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Core i5-9400F (Coffee Lake, 8th Gen), LGA1151 socket - 300-series chipset motherboards only. F-series - no integrated graphics, requires a discrete GPU for video output.' WHERE `name` = 'INTEL CORE I5-9400F PROCESSOR';
UPDATE `products` SET `description` = 'Intel Pentium G3250 (Haswell), LGA1150 socket, with integrated Intel HD Graphics.' WHERE `name` = 'INTEL PENTIUM G3250 PROCESSOR';
UPDATE `products` SET `description` = 'Intel Pentium G3260 (Haswell), LGA1150 socket, with integrated Intel HD Graphics.' WHERE `name` = 'INTEL PENTIUM G3260 PROCESSOR';

-- Motherboards
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Aorus B450 AORUS M Motherboard';
UPDATE `products` SET `description` = 'AMD B550 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASROCK B550M-HDV Motherboard';
UPDATE `products` SET `description` = 'AMD A68H chipset motherboard, FM2+ socket, DDR3 memory support.' WHERE `name` = 'ASUS A68HM-K Motherboard';
UPDATE `products` SET `description` = 'Intel H310 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS EX-H310M-V3 R2.0 Motherboard';
UPDATE `products` SET `description` = 'Intel H510 chipset motherboard, LGA1200 socket, DDR4 memory support.' WHERE `name` = 'ASUS EX-H510M-V3 Motherboard';
UPDATE `products` SET `description` = 'Intel B150 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'ASUS EZ-B150M-V5 Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'ASUS H110M-D Motherboard';
UPDATE `products` SET `description` = 'AMD A520 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS PRIME A520M-K Motherboard';
UPDATE `products` SET `description` = 'Intel B250 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'ASUS PRIME B250M-D Motherboard';
UPDATE `products` SET `description` = 'Intel B365 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS PRIME B365-A Motherboard';
UPDATE `products` SET `description` = 'Intel B365 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'Asus Prime B365M-A Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS PRIME B450- A II Motherboard';
UPDATE `products` SET `description` = 'Intel H610 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'ASUS PRIME H610M-K D4 Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS ROG STRIX Z370-F GAMING Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support, built-in Wi-Fi.' WHERE `name` = 'ASUS ROG STRIX Z370-G GAMING (WIFI) Motherboard';
UPDATE `products` SET `description` = 'Intel B365 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ASUS TUF B365- PLUS GAMING Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS TUF B450M-PLUS GAMING Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS TUF B450M-PRO GAMING Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Asus TUF B450M-Pro II Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'ASUS TUF GAMING B450M - PRO II Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'Asus TUF Z370-PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD A75 chipset motherboard, FM1 socket, DDR3 memory support.' WHERE `name` = 'BIOSTAR A75MG Motherboard';
UPDATE `products` SET `description` = 'Intel H110-class chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'DURATHON 2 H110M4-C2 SERIES Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'GIGABYTE A320M-DS2 Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte A320M-S2H Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte A320M-S2H V2 Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte AB350M-HD3 Motherboard';
UPDATE `products` SET `description` = 'Intel B150 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE B150M-D3H Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B450M DS3H Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B450M S2H V2 Motherboard';
UPDATE `products` SET `description` = 'Intel B460 chipset motherboard, LGA1200 socket, DDR4 memory support.' WHERE `name` = 'GIGABYTE B460M POWER Motherboard';
UPDATE `products` SET `description` = 'AMD B550 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B550M DS3H Motherboard';
UPDATE `products` SET `description` = 'Intel B760 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'Gigabyte B760M H DDR4 Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE H110M - S2 Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE H110M-H Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE H110M-S2 Motherboard';
UPDATE `products` SET `description` = 'Intel H610 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'GIGABYTE H610M K DDR4 Motherboard';
UPDATE `products` SET `description` = 'Intel Z270 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'GIGABYTE Z270M-D3H Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI A320M PRO-VD/S Motherboard';
UPDATE `products` SET `description` = 'Intel B150 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'MSI B150M BAZOOKA Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B350 PRO-VD PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B350 TOMAHAWK Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI B360 GAMING PRO CARBON Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI B360M GAMING PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450 TOMAHAWK MAX Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450M Mortar Max Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450M PRO -VDH PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B450 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI B450M PRO-M2 V2 Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI BAZOOKA B350M Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'MSI H110M PRO-VD Motherboard';
UPDATE `products` SET `description` = 'Intel H110 chipset motherboard, LGA1151 socket (100/200-series, 6th/7th Gen), DDR4 memory support.' WHERE `name` = 'MSI H110M PRO-VH Motherboard';
UPDATE `products` SET `description` = 'Intel H310 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI H310M PRO-VDH PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD B550 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI MAG B550M BAZOOKA Motherboard';
UPDATE `products` SET `description` = 'Intel H610 chipset motherboard, LGA1700 socket, DDR4 memory support.' WHERE `name` = 'MSI PRO H610M-E DDR4 Motherboard';
UPDATE `products` SET `description` = 'Intel H510 chipset motherboard, LGA1200 socket, DDR4 memory support.' WHERE `name` = 'MSI PRO SERIES PRO H510M-B Motherboard';
UPDATE `products` SET `description` = 'AMD B350 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'MSI TOMAWAK B350 Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'MSI Z370 GAMING PLUS Motherboard';
UPDATE `products` SET `description` = 'AMD A320 chipset motherboard, AM4 socket, DDR4 memory support.' WHERE `name` = 'PRIME A320M-K Motherboard';
UPDATE `products` SET `description` = 'Intel Z370 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'PRIME Z370-A Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'ROG STRIX B360-F GAMING Motherboard';
UPDATE `products` SET `description` = 'Intel B360 chipset motherboard, LGA1151 socket (300-series, 8th/9th Gen), DDR4 memory support.' WHERE `name` = 'TUF B360M-E GAMING Motherboard';

