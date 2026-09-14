<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('user');

// This page only accepts POST from book_court.php's form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: book_court.php');
    exit;
}

$courtId  = (int) ($_POST['court_id'] ?? 0);
$slotId   = (int) ($_POST['slot_id'] ?? 0);
$bookingDate = $_POST['booking_date'] ?? '';

$errors = [];

// --- Re-validate date range server-side (never trust the client) ---
$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+1 month'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate) || $bookingDate < $minDate || $bookingDate > $maxDate) {
    $errors[] = 'Please choose a valid date within the next month.';
}

// --- Re-validate the court exists and get its price ---
$court = null;
if ($courtId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT court_id, court_name, price_per_hour FROM courts WHERE court_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $courtId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $court = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}
if (!$court) {
    $errors[] = 'Please select a valid court.';
}

// --- Re-validate the slot exists ---
$slot = null;
if ($slotId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT slot_id, start_time, end_time FROM time_slots WHERE slot_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $slotId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $slot = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}
if (!$slot) {
    $errors[] = 'Please select a valid time slot.';
}

// --- Re-validate the slot's start time hasn't already passed ---
// Only relevant when booking_date is today - a slot on any future date
// is fine regardless of its clock time. This is the actual guard that
// matters: the display on book_court.php only disables the button,
// which a direct POST could bypass.
if ($slot && $bookingDate === date('Y-m-d') && $slot['start_time'] <= date('H:i:s')) {
    $errors[] = 'That time slot has already started today. Please choose a later slot or a different date.';
}

// --- Re-check the slot is still available (someone else may have booked it since the page loaded) ---
if (empty($errors)) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT booking_id FROM bookings
         WHERE court_id = ? AND slot_id = ? AND booking_date = ?
           AND status IN ('Pending Payment', 'Advance Paid', 'Completed')"
    );
    mysqli_stmt_bind_param($stmt, "iis", $courtId, $slotId, $bookingDate);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    if (mysqli_stmt_num_rows($stmt) > 0) {
        $errors[] = 'Sorry, that slot was just booked by someone else. Please choose another.';
    }
    mysqli_stmt_close($stmt);
}

if (!empty($errors)) {
    // Send the user back to book_court.php with their court/date still
    // selected, plus the error shown via a query flag.
    $_SESSION['booking_error'] = implode(' ', $errors);
    header('Location: book_court.php?court_id=' . $courtId . '&booking_date=' . urlencode($bookingDate));
    exit;
}

// --- Everything checks out: store validated data in session, no DB write yet ---
// A booking row is only ever created once payment is confirmed (khalti/verify.php).
$_SESSION['booking_data'] = [
    'court_id'      => $courtId,
    'court_name'    => $court['court_name'],
    'price_per_hour'=> $court['price_per_hour'],
    'slot_id'       => $slotId,
    'start_time'    => $slot['start_time'],
    'end_time'      => $slot['end_time'],
    'booking_date'  => $bookingDate,
];

header('Location: payment_selection.php');
exit;
