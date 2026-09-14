<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/expire_bookings.php';

requireLogin('user');

// Opportunistic cleanup first, same as book_court.php - if this booking's
// deadline already passed, it will be flipped to 'Expired' right here,
// before we even try to show a "Pay Remaining" page for it.
expireOverdueBookings($conn);

$bookingId = (int) ($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);

if ($bookingId <= 0) {
    header('Location: booking_history.php');
    exit;
}

// Ownership + current-state check. Always re-fetch fresh from the DB -
// never trust anything the client sent beyond the booking_id itself.
$stmt = mysqli_prepare(
    $conn,
    "SELECT b.booking_id, b.remaining_amount, b.payment_deadline, b.status, b.booking_date,
            c.court_name, c.price_per_hour, ts.start_time, ts.end_time
     FROM bookings b
     JOIN courts c ON c.court_id = b.court_id
     JOIN time_slots ts ON ts.slot_id = b.slot_id
     WHERE b.booking_id = ? AND b.user_id = ?"
);
mysqli_stmt_bind_param($stmt, "ii", $bookingId, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$booking = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$booking) {
    $_SESSION['flash_error'] = 'Booking not found.';
    header('Location: booking_history.php');
    exit;
}

if ($booking['status'] !== 'Advance Paid') {
    $_SESSION['flash_error'] = 'This booking is not currently awaiting a remaining payment.';
    header('Location: booking_history.php');
    exit;
}

function formatSlotTime($time) {
    return date('g:i A', strtotime($time));
}

// --- Handle the "Pay Remaining" button submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store just what khalti/initiate.php and khalti/verify.php need.
    // Amount comes from remaining_amount, not price_per_hour - see the
    // Part 3 note in khalti/initiate.php.
    $_SESSION['booking_data'] = [
        'booking_id'      => (int) $booking['booking_id'],
        'court_name'      => $booking['court_name'],
        'booking_date'    => $booking['booking_date'],
        'start_time'      => $booking['start_time'],
        'end_time'        => $booking['end_time'],
        'remaining_amount'=> (float) $booking['remaining_amount'],
        'payment_type'    => 'Remaining',
    ];

    header('Location: ../khalti/initiate.php');
    exit;
}

$page_title = 'Pay Remaining Amount - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card">
        <h1>Pay Remaining Amount</h1>

        <table class="summary-table">
            <tr>
                <th>Court</th>
                <td><?php echo htmlspecialchars($booking['court_name']); ?></td>
            </tr>
            <tr>
                <th>Date</th>
                <td><?php echo htmlspecialchars($booking['booking_date']); ?></td>
            </tr>
            <tr>
                <th>Time</th>
                <td><?php echo formatSlotTime($booking['start_time']) . ' - ' . formatSlotTime($booking['end_time']); ?></td>
            </tr>
            <tr>
                <th>Remaining Amount</th>
                <td><strong>Rs. <?php echo number_format((float) $booking['remaining_amount'], 2); ?></strong></td>
            </tr>
            <tr>
                <th>Payment Deadline</th>
                <td><?php echo $booking['payment_deadline'] ? date('M j, Y g:i A', strtotime($booking['payment_deadline'])) : '—'; ?></td>
            </tr>
        </table>

        <p class="payment-note">
            If this isn't paid before the deadline above, the booking will automatically
            expire and the slot will be released.
        </p>

        <form method="POST" action="remaining_payment.php">
            <input type="hidden" name="booking_id" value="<?php echo (int) $booking['booking_id']; ?>">
            <button type="submit" class="btn btn-primary btn-block">Pay Remaining via Khalti</button>
        </form>

        <p class="form-footer">
            <a href="booking_history.php">&larr; Back to My Bookings</a>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
