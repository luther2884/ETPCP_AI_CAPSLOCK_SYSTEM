<?php
require_once __DIR__ . '/../../../config/database.php';

class PromotionalImages {
    private $pdo;

    public function __construct() {
        $this->pdo = getConnection();
    }

    public function createTable() {
        $sql = "
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
        );";
        $this->pdo->exec($sql);

        // Installs created before is_archived existed won't have the
        // column yet - add it defensively.
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM promotional_images LIKE 'is_archived'")->fetch();
            if (!$col) {
                $this->pdo->exec("ALTER TABLE promotional_images ADD COLUMN is_archived BOOLEAN DEFAULT FALSE AFTER is_active");
            }
        } catch (Exception $e) { /* best-effort */ }
    }

    public function insertDefaultData() {
        $images = [
            [
                'title' => 'Gigabyte AERO OC RTX 4060Ti 16GB',
                'description' => 'High-performance graphics card with 16GB GDDR6 memory',
                'image_path' => 'public/shared/img/promotional/gigabyte-aero-rtx4060ti-16gb-promo.jpg',
                'alt_text' => 'Gigabyte AERO OC RTX 4060Ti 16GB Graphics Card',
                'display_order' => 1,
                'is_active' => 1
            ],
            [
                'title' => 'Gigabyte GeForce RTX 4060Ti 8GB',
                'description' => 'Powerful gaming graphics card with 8GB GDDR6 memory',
                'image_path' => 'public/shared/img/promotional/gigabyte-geforce-rtx4060ti-8gb-promo.jpg',
                'alt_text' => 'Gigabyte GeForce RTX 4060Ti 8GB Graphics Card',
                'display_order' => 2,
                'is_active' => 1
            ],
            [
                'title' => 'TechWorld Complete Solutions',
                'description' => 'Your one-stop shop for all computer needs',
                'image_path' => 'public/shared/img/promotional/techworld-complete-solutions-promo.jpg',
                'alt_text' => 'TechWorld Computer Store - Complete Tech Solutions',
                'display_order' => 3,
                'is_active' => 1
            ],
            [
                'title' => 'Gigabyte RX 570',
                'description' => 'Reliable graphics card for gaming and productivity',
                'image_path' => 'public/shared/img/promotional/gigabyte-rx570-promo.jpg',
                'alt_text' => 'Gigabyte RX 570 Graphics Card',
                'display_order' => 4,
                'is_active' => 1
            ],
            [
                'title' => 'Gigabyte GTX 1050Ti',
                'description' => 'Entry-level gaming graphics card with great performance',
                'image_path' => 'public/shared/img/promotional/gigabyte-gtx1050ti-promo.jpg',
                'alt_text' => 'Gigabyte GTX 1050Ti Graphics Card',
                'display_order' => 5,
                'is_active' => 1
            ]
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO promotional_images (title, description, image_path, alt_text, display_order, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($images as $image) {
            $stmt->execute([
                $image['title'],
                $image['description'],
                $image['image_path'],
                $image['alt_text'],
                $image['display_order'],
                $image['is_active']
            ]);
        }
    }

    public function addImage($title, $description, $imagePath, $altText, $displayOrder, $isActive) {
        $stmt = $this->pdo->prepare("
            INSERT INTO promotional_images (title, description, image_path, alt_text, display_order, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$title, $description, $imagePath, $altText, $displayOrder, $isActive]);
    }

    public function updateImage($id, $title, $description, $imagePath, $altText, $displayOrder, $isActive) {
        $stmt = $this->pdo->prepare("
            UPDATE promotional_images 
            SET title = ?, description = ?, image_path = ?, alt_text = ?, display_order = ?, is_active = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$title, $description, $imagePath, $altText, $displayOrder, $isActive, $id]);
    }

    public function deleteImage($id) {
        $stmt = $this->pdo->prepare("DELETE FROM promotional_images WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // Soft-delete: hides the image from the carousel and the main admin
    // list, but keeps the row (and its file) so it can be restored.
    public function archiveImage($id) {
        $stmt = $this->pdo->prepare("UPDATE promotional_images SET is_archived = TRUE, is_active = FALSE WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function restoreImage($id) {
        $stmt = $this->pdo->prepare("UPDATE promotional_images SET is_archived = FALSE, is_active = TRUE WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getActiveImages() {
        $stmt = $this->pdo->query("SELECT * FROM promotional_images WHERE is_active = TRUE AND is_archived = FALSE ORDER BY display_order ASC");
        return $stmt->fetchAll();
    }

    public function getImageById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM promotional_images WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    // Live (non-archived) images shown in the admin manager's main list.
    public function getAllImages() {
        $stmt = $this->pdo->query("SELECT * FROM promotional_images WHERE is_archived = FALSE ORDER BY display_order ASC");
        return $stmt->fetchAll();
    }

    public function getArchivedImages() {
        $stmt = $this->pdo->query("SELECT * FROM promotional_images WHERE is_archived = TRUE ORDER BY updated_at DESC");
        return $stmt->fetchAll();
    }

    public function toggleActiveStatus($id, $isActive) {
        $stmt = $this->pdo->prepare("UPDATE promotional_images SET is_active = ? WHERE id = ?");
        return $stmt->execute([$isActive, $id]);
    }
}
?>
