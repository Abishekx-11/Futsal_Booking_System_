<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/expire_bookings.php';

requireLogin('user');

// Opportunistic cleanup first, so a booking whose deadline just passed
// shows as 'Expired' (and its expiry email already sent) rather than a
// stale 'Advance Paid' row.
expireOverdueBookings($conn);

// Flash messages set by cancel_booking.php or khalti/verify.php (for a
// completed Remaining payment) survive exactly one redirect via session.
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Amounts are read directly from the bookings table columns (never
// joined against payments) so a booking with 2 payment rows (Advance +
// Remaining) still shows exactly one row here, not two.
$stmt = mysqli_prepare(
    $conn,
    "SELECT b.booking_id, b.booking_date, b.total_amount, b.amount_paid,
            b.remaining_amount, b.payment_deadline, b.status,
            c.court_name, ts.start_time, ts.end_time
     FROM bookings b
     JOIN courts c ON c.court_id = b.court_id
     JOIN time_slots ts ON ts.slot_id = b.slot_id
     WHERE b.user_id = ?
     ORDER BY b.created_at DESC"
);
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$bookings = [];
while ($row = mysqli_fetch_assoc($result)) {
    $bookings[] = $row;
}
mysqli_stmt_close($stmt);

function formatSlotTime($time) {
    return date('g:i A', strtotime($time));
}

// Same 2-hour cancellation cutoff rule enforced server-side in cancel_booking.php.
function canCancel($booking) {
    if (!in_array($booking['status'], ['Advance Paid', 'Completed'], true)) {
        return false;
    }
    $slotStart = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
    return time() < ($slotStart - 2 * 3600);
}

$page_title = 'My Bookings - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card form-card-wide form-card-table">
        <h1>My Bookings</h1>

        <?php if ($flashSuccess): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($flashError); ?></div>
        <?php endif; ?>

        <?php if (empty($bookings)): ?>
            <p>You haven't made any bookings yet.</p>
            <a href="book_court.php" class="btn btn-primary">Book a Court</a>
        <?php else: ?>
            <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>Court</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Remaining</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <?php
                            $statusClass = 'status-badge status-' . strtolower(str_replace(' ', '-', $booking['status']));
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($booking['court_name']); ?></td>
                            <td><?php echo htmlspecialchars($booking['booking_date']); ?></td>
                            <td><?php echo formatSlotTime($booking['start_time']) . ' - ' . formatSlotTime($booking['end_time']); ?></td>
                            <td>Rs. <?php echo number_format((float) $booking['total_amount'], 2); ?></td>
                            <td>Rs. <?php echo number_format((float) $booking['amount_paid'], 2); ?></td>
                            <td>Rs. <?php echo number_format((float) $booking['remaining_amount'], 2); ?></td>
                            <td><span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                            <td class="bookings-actions">
                                <?php if ($booking['status'] === 'Advance Paid'): ?>
                                    <a href="remaining_payment.php?booking_id=<?php echo (int) $booking['booking_id']; ?>" class="btn btn-secondary btn-small">Pay Remaining</a>
                                <?php endif; ?>

                                <?php if (canCancel($booking)): ?>
                                    <?php
                                        $confirmMessage = ($booking['status'] === 'Advance Paid')
                                            ? 'Cancel this booking? Your advance payment is non-refundable and will not be returned.'
                                            : 'Cancel this booking? 5% of the total amount will be deducted as a processing fee; the rest will be refunded.';
                                    ?>
                                    <form method="POST" action="cancel_booking.php" class="inline-form"
                                          onsubmit="return confirm('<?php echo htmlspecialchars($confirmMessage, ENT_QUOTES); ?>');">
                                        <input type="hidden" name="booking_id" value="<?php echo (int) $booking['booking_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-small">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
