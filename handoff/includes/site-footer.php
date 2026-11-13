<?php
/* includes/site-footer.php
   Five-column footer with hover-slide links. Include at the end of
   <main class="tw-main">, then close main/body/html in your footer include. */
?>
<footer class="tw-footer">
  <div class="tw-footer__cols">
    <div>
      <h4 style="font-size:19px;font-weight:800">TechWorld Computer Store</h4>
      <p style="margin:14px 0 0;font-size:13.5px;line-height:1.65;color:#93a8c3;max-width:30ch">
        Your One-Stop Computer Shop. We offer a wide range of high-quality computers, printers, accessories, and more.
      </p>
    </div>

    <div>
      <h4>Categories</h4>
      <div class="tw-footer__list">
        <a href="category.php?c=cpu">Processors</a>
        <a href="category.php?c=gpu">Graphics Cards</a>
        <a href="category.php?c=mobo">Motherboards</a>
        <a href="category.php?c=ram">Memory</a>
        <a href="category.php?c=accessories">Accessories</a>
      </div>
    </div>

    <div>
      <h4>Support</h4>
      <div class="tw-footer__list">
        <a href="support.php">Customer Service</a>
        <a href="returns.php">Returns</a>
        <a href="warranty.php">Warranty</a>
        <a href="faq.php">FAQ</a>
      </div>
    </div>

    <div>
      <h4>Contact</h4>
      <div class="tw-footer__list">
        <span>techworldcomputerstore@gmail.com</span>
        <span>0995 586 7973</span>
        <a href="#top">Facebook</a>
        <a href="#top">TikTok</a>
      </div>
    </div>

    <div>
      <h4>Store Hours</h4>
      <div class="tw-footer__list">
        <span>Mon - Sat: 9:00 AM - 5:00 PM</span>
        <span>Sunday: Closed</span>
      </div>
    </div>
  </div>

  <div class="tw-footer__legal">
    <div>
      <div>Unit 48C, 5th Street, Brgy. 104, East Grace Park, Caloocan, Philippines</div>
      <div style="margin-top:4px">&copy; <?= date('Y') ?> TechWorld. All rights reserved.</div>
    </div>
    <div>Made with care for tech enthusiasts</div>
  </div>
</footer>
