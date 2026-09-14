<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('user');

// This page only accepts POST from booking_history.php's cancel form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: booking_history.php');
    exit;
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);

if ($bookingId <= 0) {
    header('Location: booking_history.php');
    exit;
}

// Ownership + current-state check, straight from the DB. Also pulling
// court/user/end_time details here now so we have everything needed
// for the cancellation email without a second query later.
$stmt = mysqli_prepare(
    $conn,
    "SELECT b.booking_id, b.status, b.total_amount, b.booking_date,
            ts.start_time, ts.end_time, c.court_name,
            u.name AS user_name, u.email AS user_email
     FROM bookings b
     JOIN time_slots ts ON ts.slot_id = b.slot_id
     JOIN courts c ON c.court_id = b.court_id
     JOIN users u ON u.user_id = b.user_id
     WHERE b.booking_id = ? AND b.user_id = ?"
);
mysqli_stmt_bind_param($stmt, "ii", $bookingId, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$booking = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$booking) {
    $_SESSION['flash_error'] = 'Booking not found.';
    header('Location: booking_history.php');
    exit;
}

if (!in_array($booking['status'], ['Advance Paid', 'Completed'], true)) {
    $_SESSION['flash_error'] = 'This booking can no longer be cancelled.';
    header('Location: booking_history.php');
    exit;
}

// Cutoff: cannot cancel within 2 hours of the slot's start time.
$slotStart = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
$cutoff = $slotStart - (2 * 3600);
if (time() >= $cutoff) {
    $_SESSION['flash_error'] = 'This booking can no longer be cancelled - it starts within 2 hours (or has already started).';
    header('Location: booking_history.php');
    exit;
}

mysqli_begin_transaction($conn);
$success = false;
$wasAdvancePaid = ($booking['status'] === 'Advance Paid');
$refundAmountForEmail = 0.00; // stays 0.00 for the non-refundable Advance case

try {
    if ($wasAdvancePaid) {
        // The advance is explicitly non-refundable, so nothing changes on
        // the payments side - the existing Advance row stays 'Paid' with
        // refund_amount at its default 0.00. Only the booking's own
        // status moves to Cancelled.
        $newStatus = 'Cancelled';
        $stmt = mysqli_prepare($conn, "UPDATE bookings SET status = ? WHERE booking_id = ? AND status = 'Advance Paid'");
        mysqli_stmt_bind_param($stmt, "si", $newStatus, $bookingId);
        mysqli_stmt_execute($stmt);
        $updated = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($updated !== 1) {
            throw new Exception('Booking state changed before cancellation could be applied.');
        }

    } else {
        // 'Completed' booking: 5% is deducted for processing, so 95% of
        // total_amount is refunded. A Completed booking may have ONE
        // payment row (a single Full payment) or TWO (an Advance + a
        // later Remaining) - either way the 95% refund must be computed
        // ONCE off total_amount and applied ONCE in total, never per row.
        // Simplest correct way to write that: mark every 'Paid' row for
        // this booking as 'Refunded' (the money for all of them really
        // is being reversed), but only put the actual refund_amount on
        // the most recent paid row, leaving 0.00 on any earlier row(s) -
        // that way the sum of refund_amount across the booking's payment
        // rows always equals exactly 95% of total_amount, never double.
        $refundTotal = round((float) $booking['total_amount'] * 0.95, 2);
        $refundAmountForEmail = $refundTotal;

        $newStatus = 'Cancelled';
        $stmt = mysqli_prepare($conn, "UPDATE bookings SET status = ? WHERE booking_id = ? AND status = 'Completed'");
        mysqli_stmt_bind_param($stmt, "si", $newStatus, $bookingId);
        mysqli_stmt_execute($stmt);
        $updated = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($updated !== 1) {
            throw new Exception('Booking state changed before cancellation could be applied.');
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT payment_id FROM payments WHERE booking_id = ? AND payment_status = 'Paid' ORDER BY payment_id ASC"
        );
        mysqli_stmt_bind_param($stmt, "i", $bookingId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $paidPaymentIds = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $paidPaymentIds[] = (int) $row['payment_id'];
        }
        mysqli_stmt_close($stmt);

        if (empty($paidPaymentIds)) {
            throw new Exception('No paid payment record found to refund.');
        }

        $lastIndex = count($paidPaymentIds) - 1;
        foreach ($paidPaymentIds as $index => $paymentId) {
            $refundAmount = ($index === $lastIndex) ? $refundTotal : 0.00;
            $refundedStatus = 'Refunded';
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE payments SET payment_status = ?, refund_amount = ? WHERE payment_id = ?"
            );
            mysqli_stmt_bind_param($stmt, "sdi", $refundedStatus, $refundAmount, $paymentId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    mysqli_commit($conn);
    $success = true;
} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log('cancel_booking error: ' . $e->getMessage());
}

if ($success) {
    require_once __DIR__ . '/../includes/send_email.php';
    require_once __DIR__ . '/../includes/mail_config.php';

    $slotLabel = date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time']));
    $subject = 'Booking Cancelled - ' . $booking['court_name'];

    if ($wasAdvancePaid) {
        $userBody =
            "Hi {$booking['user_name']},\n\n" .
            "Your booking for {$booking['court_name']} on {$booking['booking_date']} ({$slotLabel}) has been " .
            "cancelled as requested. Your advance payment is non-refundable, so no refund applies.\n\n" .
            "- Futsal Booking System";
    } else {
        $userBody =
            "Hi {$booking['user_name']},\n\n" .
            "Your booking for {$booking['court_name']} on {$booking['booking_date']} ({$slotLabel}) has been " .
            "cancelled as requested. A refund of Rs. " . number_format($refundAmountForEmail, 2) .
            " (95% of the total, after a 5% processing fee) will be issued.\n\n- Futsal Booking System";
    }

    $adminBody =
        "Hi Admin,\n\n" .
        "{$booking['user_name']} has cancelled their booking for {$booking['court_name']} on " .
        "{$booking['booking_date']} ({$slotLabel})." .
        ($wasAdvancePaid
            ? ' The advance payment was non-refundable, so no refund applies.'
            : ' A refund of Rs. ' . number_format($refundAmountForEmail, 2) . ' will be issued.') .
        "\n\n- Futsal Booking System";

    sendEmail($booking['user_email'], $booking['user_name'], $subject, $userBody);
    sendEmail(ADMIN_NOTIFICATION_EMAIL, 'Admin', '[Admin Copy] ' . $subject, $adminBody);

    $_SESSION['flash_success'] = 'Booking cancelled successfully.';
} else {
    $_SESSION['flash_error'] = 'Could not cancel this booking. Please try again.';
}

header('Location: booking_history.php');
exit;
