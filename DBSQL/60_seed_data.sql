-- 60_seed_data.sql
USE `techworld_ecommerce`;

-- Default super admin (password: superadmin123)
INSERT INTO `users` (`firstname`, `lastname`, `email`, `password`, `role`, `status`) VALUES
('Super', 'Admin', 'superadmin@local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super-admin', 'active')
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- Default categories
INSERT INTO `categories` (`name`, `description`, `image_url`) VALUES
('Processors', 'CPU and processors for desktop computers', 'img/Processor.png'),
('Motherboards', 'Motherboards for various CPU sockets', 'img/Motherboard.png'),
('Graphics Cards', 'GPU and graphics cards for gaming and professional work', 'img/Graphics Card.png'),
('Memory', 'RAM modules for desktop and laptop computers', 'img/Memory.png'),
('Storage', 'SSD and HDD storage devices', 'img/SSD.png'),
('Power Supply', 'PSU units for desktop computers', 'img/PSU.png'),
('Desktops', 'Pre-built desktop computers', 'img/Desktop 1.png'),
('Laptops', 'Laptop computers for work and gaming', 'img/Lenovo.png')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Default products
INSERT INTO `products` (`name`, `description`, `price`, `stock`, `category_id`, `image_url`) VALUES
('Ryzen 7 5700G Desktop PC', 'Complete desktop with Ryzen 7 5700G, A520 motherboard, 16GB DDR4 RAM', 14995.00, 5, 7, 'img/Desktop 1.png'),
('Ryzen 3 3200G Desktop PC', 'Budget desktop with Ryzen 3 3200G, A520 motherboard, 16GB DDR4 RAM', 10995.00, 8, 7, 'img/Desktop 2.png'),
('Lenovo V14 G4 ABP Laptop', '14" FHD laptop with AMD processor, perfect for business and productivity', 21765.00, 3, 8, 'img/Lenovo.png'),
('Acer Aspire 3 A325-42', '15.6" FHD laptop with modern features and performance', 29995.00, 4, 8, 'img/Acer.png');

-- Default support settings
INSERT INTO `support_settings` (`setting_key`, `setting_value`, `description`) VALUES
('support_email', 'support@techworld.com', 'Main support email address'),
('warranty_email', 'warranty@techworld.com', 'Warranty support email address'),
('returns_email', 'returns@techworld.com', 'Returns support email address'),
('support_phone', '+63 2 1234-5678', 'Support phone number'),
('business_hours', 'Mon-Fri: 9AM-6PM, Sat: 9AM-2PM', 'Business hours'),
('auto_response_enabled', 'true', 'Enable automatic email responses'),
('ticket_auto_assign', 'true', 'Automatically assign tickets to available staff'),
('warranty_default_years', '3', 'Default warranty period in years'),
('return_window_days', '30', 'Default return window in days'),
('max_attachment_size', '10485760', 'Maximum attachment size in bytes (10MB)')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- Default FAQ categories
INSERT INTO `faq_categories` (`name`, `description`, `sort_order`) VALUES
('Orders & Shipping', 'Questions about orders, shipping, and delivery', 1),
('Products', 'Questions about our products and services', 2),
('Returns & Exchanges', 'Questions about returns and exchanges', 3),
('Warranty', 'Questions about warranty coverage and claims', 4),
('Technical Support', 'Technical questions and troubleshooting', 5)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Default FAQ items
INSERT INTO `faq_items` (`category_id`, `question`, `answer`, `sort_order`) VALUES
(1, 'How long does shipping take?', 'Standard shipping takes 3-5 business days within Metro Manila and 5-7 business days for provincial areas. Express shipping (1-2 days) is available for an additional fee.', 1),
(1, 'What are your shipping rates?', 'We offer free shipping for orders over ₱5,000 within Metro Manila. Standard shipping rates are ₱150 for Metro Manila and ₱250 for provincial areas.', 2),
(1, 'Can I track my order?', 'Yes! Once your order ships, you\'ll receive a tracking number via email and SMS. You can track your package using our website or the courier\'s tracking system.', 3),
(2, 'Are your products genuine and authentic?', 'Absolutely! All our products are 100% genuine and come directly from authorized distributors and manufacturers.', 4),
(2, 'Do you offer product recommendations?', 'Yes! Our technical experts can help you choose the right components for your needs. Contact our support team for personalized recommendations.', 5),
(3, 'What is your return policy?', 'We offer a 30-day return policy for most items. Items must be in original condition with all packaging and accessories.', 6),
(3, 'How do I return an item?', 'Contact our support team at returns@techworld.com with your order number and reason for return. We\'ll provide you with a prepaid return label.', 7),
(4, 'What warranty do you provide?', 'We provide manufacturer warranties ranging from 1-5 years depending on the product. All warranty terms are clearly stated on product pages.', 8),
(4, 'How do I claim warranty service?', 'Contact our warranty department at warranty@techworld.com with your order number, product details, and description of the issue.', 9),
(5, 'Do you provide technical support?', 'Yes! Our technical support team can help with installation, configuration, and troubleshooting via email, phone, and live chat.', 10);


