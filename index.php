<?php
require_once __DIR__ . '/includes/db_connect.php';

// Each court paired with its most recently uploaded image (if any), for
// the Featured Courts section below. A court with no images yet just
// shows a plain placeholder instead of a broken image.
$courtsResult = mysqli_query(
    $conn,
    "SELECT c.court_id, c.court_name, c.price_per_hour, c.description,
            (SELECT ci.image_path FROM court_images ci
             WHERE ci.court_id = c.court_id
             ORDER BY ci.uploaded_at DESC LIMIT 1) AS image_path
     FROM courts c
     ORDER BY c.court_name"
);
$featuredCourts = [];
while ($row = mysqli_fetch_assoc($courtsResult)) {
    $featuredCourts[] = $row;
}

$page_title = 'Futsal Booking System';
$base = '';
require_once __DIR__ . '/includes/header.php';

$role = $_SESSION['role'] ?? null;
?>

<section id="home" class="hero">
    <div class="hero-content">
        <h1>Book Your Futsal Court in Minutes</h1>
        <p>Pick a court, choose a time slot, and pay online with Khalti - no phone calls, no waiting.</p>

        <?php if ($role === 'user'): ?>
            <a href="user/book_court.php" class="btn btn-primary btn-large">Book Now</a>
        <?php elseif ($role === 'admin'): ?>
            <a href="admin/dashboard.php" class="btn btn-primary btn-large">Go to Dashboard</a>
        <?php else: ?>
            <a href="auth/user_login.php" class="btn btn-primary btn-large">Book Now</a>
        <?php endif; ?>
    </div>
</section>

<?php if (!$role): ?>
    <!-- Only shown to a logged-out visitor - once someone is signed in as
         either a user or an admin, there's nothing for these cards to do,
         so they're skipped entirely rather than left sitting there
         pointing at login pages the person doesn't need anymore. -->
    <section class="role-entry">
        <div class="role-card">
            <h2>I'm a Customer</h2>
            <p>Browse courts, book a time slot, and manage your bookings.</p>
            <a href="auth/user_login.php" class="btn btn-secondary">Customer Login</a>
        </div>
        <div class="role-card">
            <h2>Court Admin</h2>
            <p>Manage courts, images, and view booking reports.</p>
            <a href="auth/admin_login.php" class="btn btn-secondary">Court Admin</a>
        </div>
    </section>
<?php endif; ?>

<section id="courts" class="featured-courts">
    <h2>Our Courts</h2>

    <?php if (empty($featuredCourts)): ?>
        <p class="section-note">No courts have been added yet.</p>
    <?php else: ?>
        <div class="court-grid">
            <?php foreach ($featuredCourts as $court): ?>
                <div class="court-card">
                    <?php if ($court['image_path']): ?>
                        <img src="<?php echo htmlspecialchars($court['image_path']); ?>" alt="<?php echo htmlspecialchars($court['court_name']); ?>" class="court-card-image">
                    <?php else: ?>
                        <div class="court-card-image court-card-image-placeholder">No photo yet</div>
                    <?php endif; ?>
                    <div class="court-card-body">
                        <h3><?php echo htmlspecialchars($court['court_name']); ?></h3>
                        <p class="court-card-price">Rs. <?php echo number_format($court['price_per_hour'], 2); ?> / hour</p>
                        <?php if (!empty($court['description'])): ?>
                            <p class="court-card-description"><?php echo htmlspecialchars($court['description']); ?></p>
                        <?php endif; ?>
                        <?php if ($role === 'user'): ?>
                            <a href="user/book_court.php?court_id=<?php echo (int) $court['court_id']; ?>" class="btn btn-secondary btn-small">Book This Court</a>
                        <?php elseif (!$role): ?>
                            <a href="auth/user_login.php" class="btn btn-secondary btn-small">Book This Court</a>
                        <?php endif; ?>
                        <!-- Admins see no booking CTA here - they manage courts from the admin panel, not book them. -->
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="about" class="why-choose-us">
    <h2>Why Choose Us</h2>
    <div class="why-grid">
        <div class="why-card">
            <h3>Instant Booking</h3>
            <p>See real-time slot availability and book right away, no back-and-forth needed.</p>
        </div>
        <div class="why-card">
            <h3>Secure Online Payment</h3>
            <p>Pay safely through Khalti with either a 30% advance or the full amount upfront.</p>
        </div>
        <div class="why-card">
            <h3>Flexible Slots</h3>
            <p>Eleven time slots throughout the day, from early morning to late evening.</p>
        </div>
        <div class="why-card">
            <h3>Easy Management</h3>
            <p>Track your bookings, pay remaining balances, and cancel when plans change.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
