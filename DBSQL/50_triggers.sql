-- 50_triggers.sql
USE `techworld_ecommerce`;

DELIMITER $$

CREATE TRIGGER `generate_claim_number`
BEFORE INSERT ON `warranty_claims`
FOR EACH ROW
BEGIN
    DECLARE next_num INT DEFAULT 1;
    SELECT COALESCE(MAX(CAST(SUBSTRING(claim_number, 3) AS UNSIGNED)), 0) + 1 
    INTO next_num 
    FROM warranty_claims 
    WHERE claim_number LIKE 'WC%';
    SET NEW.claim_number = CONCAT('WC', LPAD(next_num, 6, '0'));
END$$

CREATE TRIGGER `generate_return_number`
BEFORE INSERT ON `return_requests`
FOR EACH ROW
BEGIN
    DECLARE next_num INT DEFAULT 1;
    SELECT COALESCE(MAX(CAST(SUBSTRING(return_number, 3) AS UNSIGNED)), 0) + 1 
    INTO next_num 
    FROM return_requests 
    WHERE return_number LIKE 'RT%';
    SET NEW.return_number = CONCAT('RT', LPAD(next_num, 6, '0'));
END$$

DELIMITER ;


