<?php
/**
 * footer.php
 * Shared bottom of every page. Relies on the same $base convention
 * described in header.php.
 */
if (!isset($base)) {
    $base = '';
}
?>
</main>

<footer class="site-footer">
    <div class="footer-content">
        <div class="footer-brand">
            <p>Futsal<span>Booking</span></p>
            <p class="footer-tagline">Book your futsal court in minutes.</p>
        </div>
        <div class="footer-links">
            <a href="<?php echo $base; ?>index.php">Home</a>
            <a href="<?php echo $base; ?>index.php#courts">Courts</a>
            <a href="<?php echo $base; ?>index.php#about">About</a>
        </div>
        <p class="footer-copy">&copy; <?php echo date('Y'); ?> Futsal Booking System</p>
    </div>
</footer>

<script src="<?php echo $base; ?>assets/js/main.js"></script>
</body>
</html>
