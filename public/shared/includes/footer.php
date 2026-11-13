<?php
// Shared footer.php (minimal shared version)
require_once __DIR__ . '/../../../config/functions.php';
?>

<!-- Footer Section -->
<footer class="main-footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-column">
                <h3>TechWorld Computer Store</h3>
                <p>Your One-Stop Computer Shop. We offer a wide range of high-quality computers, printers, accessories, and more.</p>
                <p style="margin-top:8px;">Unit 48C, 5th Street, Brgy. 104, East Grace Park, Caloocan, Philippines</p>
            </div>
            <div class="footer-column">
                <h3>Categories</h3>
                <ul>
                    <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=Processors">Processors</a></li>
                    <li><a href="<?= WEB_ROOT ?>public/guest/products.php?category=Graphics Cards">Graphics Cards</a>
                    </li>
                </ul>
            </div>
            <div class="footer-column">
                <h3>Support</h3>
                <ul>
                    <li><a href="javascript:void(0);" onclick="openLoginModal(); return false;">Customer Service</a>
                    </li>
                    <li><a href="javascript:void(0);" onclick="openLoginModal(); return false;">Returns</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h3>Contact</h3>
                <ul class="contact-list">
                    <li><i class="fi fi-rr-envelope"></i><span>techworldcomputerstore@gmail.com</span></li>
                    <li><i class="fi fi-rr-phone-call"></i><span>0995 586 7973</span></li>
                    <li><i class="fi fi-rr-clock"></i><span>Mon - Sat: 9AM-5PM, Sun: Closed</span></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="footer-bottom-content">
                <p>&copy; 2025 TechWorld. All rights reserved.</p>
            </div>
        </div>
    </div>
</footer>

<?php include __DIR__ . '/../../shared/includes/auth-modal.php'; ?>

<style>
    .main-footer {
        background: var(--footer-bg);
        color: #e0e6ed;
        margin-top: 80px;
        border-top: 1px solid var(--border-color)
    }

    .footer-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 40px;
        padding: 60px 0 40px;
        border-bottom: 1px solid var(--border-color)
    }
</style>

<script>
    function openLoginModal() { const m = document.getElementById('loginModal'); if (m) { m.style.display = 'block'; } else { window.location.href = '<?= WEB_ROOT ?>public/guest/login.php'; } }
</script>