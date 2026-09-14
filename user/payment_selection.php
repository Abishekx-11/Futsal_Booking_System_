<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('user');

// This page only makes sense if book_court.php -> confirm_booking.php
// already validated a selection and put it in the session.
if (!isset($_SESSION['booking_data'])) {
    header('Location: book_court.php');
    exit;
}

$booking = $_SESSION['booking_data'];

$totalAmount   = (float) $booking['price_per_hour']; // 1 hour per slot
$advanceAmount = round($totalAmount * 0.30, 2);

function formatSlotTime($time) {
    return date('g:i A', strtotime($time));
}

$page_title = 'Choose Payment Option - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card form-card-wide">
        <h1>Confirm Payment Option</h1>

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
                <th>Total Amount</th>
                <td>Rs. <?php echo number_format($totalAmount, 2); ?></td>
            </tr>
        </table>

        <div class="payment-options">
            <div class="payment-option">
                <h3>Pay 30% Advance</h3>
                <p class="payment-amount">Rs. <?php echo number_format($advanceAmount, 2); ?></p>
                <p class="payment-note">
                    This advance is <strong>non-refundable</strong>. The remaining
                    70% (Rs. <?php echo number_format($totalAmount - $advanceAmount, 2); ?>)
                    is due at least 24 hours before your slot's start time, or the
                    booking will automatically expire.
                </p>
                <form method="POST" action="payment_confirmation.php">
                    <input type="hidden" name="payment_type" value="Advance">
                    <button type="submit" class="btn btn-secondary btn-block">Pay Advance</button>
                </form>
            </div>

            <div class="payment-option">
                <h3>Pay Full Amount</h3>
                <p class="payment-amount">Rs. <?php echo number_format($totalAmount, 2); ?></p>
                <p class="payment-note">
                    Pay the full amount now. Your booking is marked Completed
                    immediately with nothing left to pay later.
                </p>
                <form method="POST" action="payment_confirmation.php">
                    <input type="hidden" name="payment_type" value="Full">
                    <button type="submit" class="btn btn-primary btn-block">Pay Full Amount</button>
                </form>
            </div>
        </div>

        <p class="form-footer">
            <a href="book_court.php?court_id=<?php echo (int) $booking['court_id']; ?>&booking_date=<?php echo urlencode($booking['booking_date']); ?>">
                &larr; Back to slot selection
            </a>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
