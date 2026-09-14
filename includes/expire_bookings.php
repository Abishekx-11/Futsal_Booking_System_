<?php
/**
 * expire_bookings.php
 * -----------------------------------------------------------------
 * There's no real cron service available on a local XAMPP setup, so
 * instead of a scheduled task, we run this opportunistically at the
 * top of any page that displays or depends on booking/slot status
 * (book_court.php, booking_history.php, dashboards, etc). Every time
 * one of those pages loads, any booking whose 24-hour remaining-
 * payment deadline has passed gets flipped to 'Expired' right then.
 *
 * PART 3 UPDATE: before flipping status, we first SELECT the rows
 * that are about to expire (joined with the user + court + slot info
 * an email needs), send an expiry notice to the user and a copy to
 * the admin, and only then run the UPDATE. Doing it in this order
 * means the slot-releasing UPDATE always runs last, after the
 * notification attempt - never before.
 * -----------------------------------------------------------------
 */

require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/mail_config.php';

function expireOverdueBookings($conn) {
    $sql = "SELECT b.booking_id, b.remaining_amount, b.booking_date,
                   u.email AS user_email, u.name AS user_name,
                   c.court_name, ts.start_time, ts.end_time
            FROM bookings b
            JOIN users u ON u.user_id = b.user_id
            JOIN courts c ON c.court_id = b.court_id
            JOIN time_slots ts ON ts.slot_id = b.slot_id
            WHERE b.status = 'Advance Paid'
              AND b.payment_deadline IS NOT NULL
              AND b.payment_deadline < NOW()";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return;
    }

    $expiredIds = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $expiredIds[] = (int) $row['booking_id'];

        $slotLabel = date('g:i A', strtotime($row['start_time'])) . ' - ' . date('g:i A', strtotime($row['end_time']));
        $subject   = 'Booking Expired - ' . $row['court_name'];
        $userBody =
            "Hi {$row['user_name']},\n\n" .
            "Your booking for {$row['court_name']} on {$row['booking_date']} ({$slotLabel}) " .
            "has expired because the remaining balance of Rs. " . number_format((float) $row['remaining_amount'], 2) .
            " was not paid before the 24-hour deadline. The slot has been released and is now " .
            "available for other customers to book.\n\n" .
            "- Futsal Booking System";
        $adminBody =
            "Hi Admin,\n\n" .
            "{$row['user_name']}'s booking for {$row['court_name']} on {$row['booking_date']} ({$slotLabel}) " .
            "has expired - the remaining Rs. " . number_format((float) $row['remaining_amount'], 2) .
            " was not paid before the deadline. The slot has been released.\n\n" .
            "- Futsal Booking System";

        // A failed email is logged inside sendEmail() and never stops the
        // loop - the slot still needs to be released either way.
        sendEmail($row['user_email'], $row['user_name'], $subject, $userBody);
        sendEmail(ADMIN_NOTIFICATION_EMAIL, 'Admin', '[Admin Copy] ' . $subject, $adminBody);
    }

    if (!empty($expiredIds)) {
        $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));
        $types = str_repeat('i', count($expiredIds));

        $stmt = mysqli_prepare($conn, "UPDATE bookings SET status = 'Expired' WHERE booking_id IN ($placeholders)");
        mysqli_stmt_bind_param($stmt, $types, ...$expiredIds);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
