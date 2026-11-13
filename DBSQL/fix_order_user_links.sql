-- Fix order user links - Update orders to link to correct user with address data
-- File: DBSQL/fix_order_user_links.sql

USE `techworld_ecommerce`;

-- Update orders #40 and #39 to link to user_id 5 (who has the address data)
-- This fixes the disconnect between orders and addresses

UPDATE `orders` 
SET `user_id` = 5 
WHERE `id` IN (40, 39);

-- Verify the changes
SELECT 
    o.id as order_id,
    o.user_id,
    u.firstname,
    u.lastname,
    u.email,
    COALESCE(
        o.address, 
        (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1),
        (SELECT CONCAT_WS(', ', street, region, postal) FROM user_addresses WHERE user_id = o.user_id LIMIT 1),
        u.address
    ) as delivery_address,
    (SELECT full_name FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1) as delivery_name,
    (SELECT phone FROM user_addresses WHERE user_id = o.user_id AND is_default = 1 LIMIT 1) as delivery_phone
FROM orders o 
LEFT JOIN users u ON o.user_id = u.id 
WHERE o.id IN (40, 39);

-- Show user_addresses for user_id 5 to confirm address data exists
SELECT 
    id,
    user_id,
    full_name,
    phone,
    street,
    region,
    postal,
    label,
    is_default,
    created_at
FROM user_addresses 
WHERE user_id = 5;
