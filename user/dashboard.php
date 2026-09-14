<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/expire_bookings.php';

requireLogin('user');

// Opportunistic cleanup, same as every other page that shows booking status.
expireOverdueBookings($conn);

$userId = $_SESSION['user_id'];

// --- Summary cards ---
// "Upcoming Bookings": still-active bookings (Advance Paid or Completed)
// whose date hasn't passed yet. Advance Paid / Completed counts below are
// simple totals across all of the user's bookings, not just upcoming ones -
// worth knowing the distinction if this comes up in your defense.
$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS cnt FROM bookings
     WHERE user_id = ? AND status IN ('Advance Paid', 'Completed') AND booking_date >= CURDATE()"
);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$upcomingCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM bookings WHERE user_id = ? AND status = 'Advance Paid'");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$advancePaidCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM bookings WHERE user_id = ? AND status = 'Completed'");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$completedCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];
mysqli_stmt_close($stmt);

// --- 24-hour reminder banner ---
// Any Advance Paid booking of this user whose payment_deadline falls
// within the next 24 hours (and hasn't already passed - that case is
// handled by expireOverdueBookings() above, not shown as a reminder).
$stmt = mysqli_prepare(
    $conn,
    "SELECT b.booking_id, b.remaining_amount, b.payment_deadline, c.court_name
     FROM bookings b
     JOIN courts c ON c.court_id = b.court_id
     WHERE b.user_id = ? AND b.status = 'Advance Paid'
       AND b.payment_deadline IS NOT NULL
       AND b.payment_deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
     ORDER BY b.payment_deadline ASC"
);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$dueSoon = [];
while ($row = mysqli_fetch_assoc($result)) {
    $dueSoon[] = $row;
}
mysqli_stmt_close($stmt);

// --- Profile picture for the welcome section (small, non-header use) ---
$stmt = mysqli_prepare($conn, "SELECT name, profile_picture FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$page_title = 'My Dashboard - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-page">
    <div class="dashboard-welcome">
        <h1>Welcome back, <?php echo htmlspecialchars($me['name']); ?>!</h1>
        <p>Here's a quick look at your bookings.</p>
    </div>

    <?php if (!empty($dueSoon)): ?>
        <div class="alert alert-warning">
            <strong>Reminder:</strong>
            <?php foreach ($dueSoon as $item): ?>
                Your remaining payment of Rs. <?php echo number_format($item['remaining_amount'], 2); ?>
                for <?php echo htmlspecialchars($item['court_name']); ?> is due by
                <?php echo date('M j, g:i A', strtotime($item['payment_deadline'])); ?>.
                <a href="remaining_payment.php?booking_id=<?php echo (int) $item['booking_id']; ?>">Pay now</a><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="summary-cards">
        <div class="summary-card">
            <span class="summary-card-value"><?php echo $upcomingCount; ?></span>
            <span class="summary-card-label">Upcoming Bookings</span>
        </div>
        <div class="summary-card">
            <span class="summary-card-value"><?php echo $advancePaidCount; ?></span>
            <span class="summary-card-label">Advance Paid</span>
        </div>
        <div class="summary-card">
            <span class="summary-card-value"><?php echo $completedCount; ?></span>
            <span class="summary-card-label">Completed</span>
        </div>
    </div>

    <div class="dashboard-quick-links">
        <a href="book_court.php" class="btn btn-primary">Book a Court</a>
        <a href="booking_history.php" class="btn btn-secondary">My Bookings</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
