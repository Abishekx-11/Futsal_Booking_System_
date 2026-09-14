<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('user');

if (!isset($_SESSION['booking_data'])) {
    header('Location: book_court.php');
    exit;
}

// This page only accepts POST from payment_selection.php's forms.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: payment_selection.php');
    exit;
}

$paymentType = $_POST['payment_type'] ?? '';

if (!in_array($paymentType, ['Advance', 'Full'], true)) {
    header('Location: payment_selection.php');
    exit;
}

// Store the chosen payment type alongside the rest of the booking data
// in session, so khalti/initiate.php and khalti/verify.php both see it.
$_SESSION['booking_data']['payment_type'] = $paymentType;

$booking = $_SESSION['booking_data'];
$totalAmount = (float) $booking['price_per_hour'];
$payableAmount = ($paymentType === 'Advance') ? round($totalAmount * 0.30, 2) : $totalAmount;

function formatSlotTime($time) {
    return date('g:i A', strtotime($time));
}

$page_title = 'Confirm Payment - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card form-card-wide">
        <h1>Review &amp; Pay</h1>

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
                <th>Payment Type</th>
                <td><?php echo htmlspecialchars($paymentType); ?><?php echo $paymentType === 'Advance' ? ' (30%)' : ' (100%)'; ?></td>
            </tr>
            <tr>
                <th>Amount Payable Now</th>
                <td><strong>Rs. <?php echo number_format($payableAmount, 2); ?></strong></td>
            </tr>
        </table>

        <?php if ($paymentType === 'Advance'): ?>
            <p class="payment-note">
                Remember: this advance is non-refundable, and the remaining
                70% must be paid at least 24 hours before your slot starts.
            </p>
        <?php endif; ?>

        <form method="POST" action="../khalti/initiate.php">
            <button type="submit" class="btn btn-primary btn-block">Proceed to Khalti Payment</button>
        </form>

        <p class="form-footer">
            <a href="payment_selection.php">&larr; Back to payment options</a>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
