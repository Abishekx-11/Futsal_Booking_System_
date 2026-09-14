<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/khalti_config.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/mail_config.php';

requireLogin('user');

if (!isset($_SESSION['booking_data']) || !isset($_SESSION['booking_data']['payment_type'])) {
    header('Location: ../user/book_court.php');
    exit;
}

// Khalti redirects back here with a pidx (and other params) in the query
// string. We NEVER trust the redirect alone - we make our own
// server-to-server call to Khalti's lookup endpoint to find out the
// real, authoritative status of the payment.
$pidx = $_GET['pidx'] ?? ($_SESSION['booking_data']['pidx'] ?? null);

if (!$pidx) {
    die('Missing payment reference. Please try the payment again.');
}

$ch = curl_init(KHALTI_BASE_URL . 'epayment/lookup/');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['pidx' => $pidx]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Key ' . KHALTI_SECRET_KEY,
    'Content-Type: application/json',
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    die('Could not verify payment with Khalti: ' . htmlspecialchars($curlError));
}

$lookup = json_decode($response, true);
$status = $lookup['status'] ?? null;

$booking = $_SESSION['booking_data'];
$paymentType = $booking['payment_type'];

$page_title = 'Payment Result - Futsal Booking System';
$base = '../';

//  a 'Remaining' payment tops up an EXISTING booking
// (an UPDATE) while Advance/Full create a brand new one (an INSERT), so
// they're handled in separate branches below rather than shoehorned into
// one code path. The shared part - the curl lookup call above, which is
// the one thing that actually needed to stay unduplicated - is already
// done by this point for all three payment types.
if ($status === 'Completed' && $paymentType === 'Remaining') {

    $bookingId = (int) ($booking['booking_id'] ?? 0);
    $remaining = 0.00;
    $emailInfo = null;
    $success = false;

    mysqli_begin_transaction($conn);
    try {
        // Lock and re-check the booking is still exactly where we left it -
        // it could have expired while the user was off on Khalti's site.
        $stmt = mysqli_prepare(
            $conn,
            "SELECT b.remaining_amount, u.email AS user_email, u.name AS user_name,
                    c.court_name, ts.start_time, ts.end_time, b.booking_date
             FROM bookings b
             JOIN users u ON u.user_id = b.user_id
             JOIN courts c ON c.court_id = b.court_id
             JOIN time_slots ts ON ts.slot_id = b.slot_id
             WHERE b.booking_id = ? AND b.user_id = ? AND b.status = 'Advance Paid'
             FOR UPDATE"
        );
        mysqli_stmt_bind_param($stmt, "ii", $bookingId, $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            throw new Exception('Booking is no longer awaiting a remaining payment.');
        }

        $remaining = (float) $row['remaining_amount'];
        $emailInfo = $row;

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE bookings
             SET amount_paid = amount_paid + ?, remaining_amount = 0.00,
                 payment_deadline = NULL, status = 'Completed'
             WHERE booking_id = ? AND status = 'Advance Paid'"
        );
        mysqli_stmt_bind_param($stmt, "di", $remaining, $bookingId);
        mysqli_stmt_execute($stmt);
        $updated = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($updated !== 1) {
            throw new Exception('Booking row could not be updated (already paid off?).');
        }

        $paymentStatus = 'Paid';
        $paymentTypeDb = 'Remaining';
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO payments (booking_id, payment_type, amount, payment_status, transaction_reference)
             VALUES (?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, "isdss", $bookingId, $paymentTypeDb, $remaining, $paymentStatus, $pidx);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        $success = true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log('Remaining payment DB error: ' . $e->getMessage());
    }

    if ($success) {
        $slotLabel = date('g:i A', strtotime($emailInfo['start_time'])) . ' - ' . date('g:i A', strtotime($emailInfo['end_time']));
        $subject = 'Booking Fully Paid - ' . $emailInfo['court_name'];
        $userBody =
            "Hi {$emailInfo['user_name']},\n\n" .
            "Your remaining balance of Rs. " . number_format($remaining, 2) . " for {$emailInfo['court_name']} " .
            "on {$emailInfo['booking_date']} ({$slotLabel}) has been received. Your booking is now fully paid " .
            "and marked Completed.\n\n- Futsal Booking System";
        $adminBody =
            "Hi Admin,\n\n" .
            "{$emailInfo['user_name']} has paid the remaining balance of Rs. " . number_format($remaining, 2) .
            " for {$emailInfo['court_name']} on {$emailInfo['booking_date']} ({$slotLabel}). Booking is now fully " .
            "paid and marked Completed.\n\n- Futsal Booking System";
        sendEmail($emailInfo['user_email'], $emailInfo['user_name'], $subject, $userBody);
        sendEmail(ADMIN_NOTIFICATION_EMAIL, 'Admin', '[Admin Copy] ' . $subject, $adminBody);

        unset($_SESSION['booking_data']);
        $_SESSION['flash_success'] = 'Remaining payment of Rs. ' . number_format($remaining, 2) . ' received. Your booking is now fully paid.';
        header('Location: ../user/booking_history.php');
        exit;
    } else {
        $_SESSION['flash_error'] = 'Your payment went through with Khalti, but we could not update your booking. Please contact support with your payment reference: ' . $pidx;
        header('Location: ../user/booking_history.php');
        exit;
    }

} elseif ($status === 'Completed') {

    // --- Final availability re-check, right before writing to the DB. ---
    // Someone else could have grabbed this exact slot while this user
    // was off on Khalti's site paying - or, if they lingered on Khalti's
    // page long enough, the slot's start time itself could have slipped
    // into the past. Either way, we shouldn't create a booking for it.
    $stmt = mysqli_prepare(
        $conn,
        "SELECT booking_id FROM bookings
         WHERE court_id = ? AND slot_id = ? AND booking_date = ?
           AND status IN ('Pending Payment', 'Advance Paid', 'Completed')"
    );
    mysqli_stmt_bind_param($stmt, "iis", $booking['court_id'], $booking['slot_id'], $booking['booking_date']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $slotNowTaken = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    $slotNowInPast = ($booking['booking_date'] === date('Y-m-d')) && ($booking['start_time'] <= date('H:i:s'));

    if ($slotNowTaken || $slotNowInPast) {
        // Payment succeeded but the slot is gone. We deliberately do NOT
        // create a booking row here. In a production system this would
        // trigger an automatic refund via Khalti's refund API; for this
        // college project we surface it clearly so the student can
        // manually follow up, and note that as a known limitation.
        $base = '../';
        require_once __DIR__ . '/../includes/header.php';
        ?>
        <div class="form-page">
            <div class="form-card">
                <h1>Slot No Longer Available</h1>
                <div class="alert alert-error">
                    Your payment went through, but this slot is no longer
                    available - either someone else booked it in the meantime,
                    or its start time has now passed. This is a known edge case
                    in this project - a real system would auto-refund via
                    Khalti's refund API here. Please contact support with
                    your payment reference: <strong><?php echo htmlspecialchars($pidx); ?></strong>
                </div>
                <a href="../user/book_court.php" class="btn btn-primary">Choose Another Slot</a>
            </div>
        </div>
        <?php
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }

    // --- Compute amounts based on payment_type ---
    $totalAmount = (float) $booking['price_per_hour'];

    if ($paymentType === 'Advance') {
        $amountPaid       = round($totalAmount * 0.30, 2);
        $remainingAmount  = round($totalAmount - $amountPaid, 2);
        $bookingStatus    = 'Advance Paid';

        // Deadline = 24 hours before the slot's start time on the booking date.
        $slotStartDateTime = $booking['booking_date'] . ' ' . $booking['start_time'];
        $paymentDeadline = date('Y-m-d H:i:s', strtotime($slotStartDateTime . ' -24 hours'));
    } else { // Full
        $amountPaid      = $totalAmount;
        $remainingAmount = 0.00;
        $bookingStatus   = 'Completed';
        $paymentDeadline = null;
    }

    // --- Insert the booking row (this is the ONLY place a booking is created) ---
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO bookings
            (user_id, court_id, slot_id, booking_date, total_amount, amount_paid, remaining_amount, payment_deadline, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param(
        $stmt,
        "iiisdddss",
        $_SESSION['user_id'],
        $booking['court_id'],
        $booking['slot_id'],
        $booking['booking_date'],
        $totalAmount,
        $amountPaid,
        $remainingAmount,
        $paymentDeadline,
        $bookingStatus
    );

    if (!mysqli_stmt_execute($stmt)) {
        // Most likely cause: the UNIQUE KEY (court_id, slot_id, booking_date)
        // caught a race condition our manual check above just missed.
        mysqli_stmt_close($stmt);
        $base = '../';
        require_once __DIR__ . '/../includes/header.php';
        ?>
        <div class="form-page">
            <div class="form-card">
                <h1>Booking Could Not Be Saved</h1>
                <div class="alert alert-error">
                    Your payment succeeded, but we couldn't save the booking
                    (the slot may have just been taken). Please contact
                    support with your payment reference: <strong><?php echo htmlspecialchars($pidx); ?></strong>
                </div>
            </div>
        </div>
        <?php
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }

    $newBookingId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // --- Insert the matching payment row ---
    $paymentStatus = 'Paid';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO payments (booking_id, payment_type, amount, payment_status, transaction_reference)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "isdss", $newBookingId, $paymentType, $amountPaid, $paymentStatus, $pidx);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // --- Send confirmation email to the user and a copy to the admin ---
    // A failed email is logged inside sendEmail() and never blocks the
    // booking, which is already safely committed to the database above.
    $stmt = mysqli_prepare($conn, "SELECT name, email FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $emailUser = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($emailUser) {
        $slotLabel = date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time']));
        $subject = ($paymentType === 'Full' ? 'Booking Confirmed' : 'Advance Payment Received') . ' - ' . $booking['court_name'];

        if ($paymentType === 'Full') {
            $userBody =
                "Hi {$emailUser['name']},\n\n" .
                "Your full payment of Rs. " . number_format($amountPaid, 2) . " for {$booking['court_name']} " .
                "on {$booking['booking_date']} ({$slotLabel}) was received. Your booking is Completed - nothing " .
                "further is due.\n\n- Futsal Booking System";
            $adminBody =
                "Hi Admin,\n\n" .
                "{$emailUser['name']} has fully paid for {$booking['court_name']} on {$booking['booking_date']} " .
                "({$slotLabel}). Amount received: Rs. " . number_format($amountPaid, 2) . ". Booking is Completed.\n\n" .
                "- Futsal Booking System";
        } else {
            $userBody =
                "Hi {$emailUser['name']},\n\n" .
                "Your advance payment of Rs. " . number_format($amountPaid, 2) . " for {$booking['court_name']} " .
                "on {$booking['booking_date']} ({$slotLabel}) was received. The remaining Rs. " .
                number_format($remainingAmount, 2) . " is due by " . date('M j, Y g:i A', strtotime($paymentDeadline)) .
                " (24 hours before your slot), or the booking will automatically expire.\n\n- Futsal Booking System";
            $adminBody =
                "Hi Admin,\n\n" .
                "{$emailUser['name']} has paid an advance of Rs. " . number_format($amountPaid, 2) . " for " .
                "{$booking['court_name']} on {$booking['booking_date']} ({$slotLabel}). Remaining balance: Rs. " .
                number_format($remainingAmount, 2) . ", due by " . date('M j, Y g:i A', strtotime($paymentDeadline)) . ".\n\n" .
                "- Futsal Booking System";
        }

        sendEmail($emailUser['email'], $emailUser['name'], $subject, $userBody);
        sendEmail(ADMIN_NOTIFICATION_EMAIL, 'Admin', '[Admin Copy] ' . $subject, $adminBody);
    }

    // Clear the in-progress booking data now that it's safely in the DB.
    unset($_SESSION['booking_data']);

    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="form-page">
        <div class="form-card">
            <h1>Payment Successful</h1>
            <div class="alert alert-success">
                Your <?php echo htmlspecialchars($paymentType); ?> payment of
                Rs. <?php echo number_format($amountPaid, 2); ?> was received and your booking is confirmed.
            </div>
            <a href="../user/booking_history.php" class="btn btn-primary btn-block">View My Bookings</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';

} else {
    // Payment not completed (Pending, Expired, User canceled, Refunded, etc).
    // Create/update nothing. Leave session data intact so the user can
    // retry without re-selecting everything.
    $isRemaining = ($paymentType === 'Remaining');
    $retryUrl = $isRemaining
        ? '../user/remaining_payment.php?booking_id=' . (int) ($booking['booking_id'] ?? 0)
        : '../user/payment_confirmation.php';

    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="form-page">
        <div class="form-card">
            <h1>Payment Failed</h1>
            <div class="alert alert-error">
                Your payment was not completed (status: <?php echo htmlspecialchars($status ?? 'Unknown'); ?>).
                <?php echo $isRemaining ? 'Your booking is unchanged and' : 'No booking was made and'; ?> no charge should apply.
            </div>
            <a href="<?php echo htmlspecialchars($retryUrl); ?>" class="btn btn-primary">Try Again</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
}
