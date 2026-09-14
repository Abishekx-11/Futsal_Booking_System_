<?php
/**
 * daily_reminders.php
 * -----------------------------------------------------------------
 * Same "opportunistic" execution model as includes/expire_bookings.php
 * - there's no real cron on local XAMPP, so this runs whenever any
 * page that already calls expireOverdueBookings() loads. Instead of
 * firing once (like expiry), this needs to fire once PER CALENDAR DAY
 * per booking, so we track that with the last_reminder_date column:
 * if it's already today's date, skip; otherwise send and stamp it.
 *
 * Covers both 'Advance Paid' bookings (remind about the remaining
 * balance + deadline) and 'Completed' bookings (remind that the slot
 * is coming up - nothing owed, just a heads-up). Stops entirely once
 * the booking's actual date+time has passed - reusing the same
 * "booking_date + start_time compared to NOW()" idea already used for
 * the past-slot booking guard elsewhere in the project.
 * -----------------------------------------------------------------
 */

require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/mail_config.php';

function sendDailyReminders($conn) {
    $sql = "SELECT b.booking_id, b.status, b.booking_date, b.remaining_amount,
                   b.payment_deadline, u.email AS user_email, u.name AS user_name,
                   c.court_name, ts.start_time, ts.end_time
            FROM bookings b
            JOIN users u ON u.user_id = b.user_id
            JOIN courts c ON c.court_id = b.court_id
            JOIN time_slots ts ON ts.slot_id = b.slot_id
            WHERE b.status IN ('Advance Paid', 'Completed')
              AND TIMESTAMP(b.booking_date, ts.start_time) > NOW()
              AND (b.last_reminder_date IS NULL OR b.last_reminder_date < CURDATE())";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log('sendDailyReminders: query failed - ' . mysqli_error($conn));
        return 0;
    }

    $remindedIds = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $remindedIds[] = (int) $row['booking_id'];

        $slotLabel = date('g:i A', strtotime($row['start_time'])) . ' - ' . date('g:i A', strtotime($row['end_time']));
        $subject   = 'Upcoming Booking Reminder - ' . $row['court_name'];

        if ($row['status'] === 'Advance Paid') {
            $userBody =
                "Hi {$row['user_name']},\n\n" .
                "Reminder: you have an upcoming booking for {$row['court_name']} on {$row['booking_date']} " .
                "({$slotLabel}). The remaining balance of Rs. " . number_format((float) $row['remaining_amount'], 2) .
                " is still due" . ($row['payment_deadline'] ? (' by ' . date('M j, Y g:i A', strtotime($row['payment_deadline']))) : '') .
                ", or the booking will automatically expire.\n\n- Futsal Booking System";
        } else {
            $userBody =
                "Hi {$row['user_name']},\n\n" .
                "Reminder: you have an upcoming booking for {$row['court_name']} on {$row['booking_date']} " .
                "({$slotLabel}). This booking is fully paid - see you on the court!\n\n- Futsal Booking System";
        }

        $adminBody =
            "Hi Admin,\n\n" .
            "Reminder: {$row['user_name']} has an upcoming booking for {$row['court_name']} on " .
            "{$row['booking_date']} ({$slotLabel}). Status: {$row['status']}.\n\n- Futsal Booking System";

        // A failed email is logged inside sendEmail() and never stops the
        // loop - we still stamp today's date below either way, so a
        // temporary SMTP outage doesn't cause a flood of retries once
        // it's back up (the next reminder will just be tomorrow's).
        sendEmail($row['user_email'], $row['user_name'], $subject, $userBody);
        sendEmail(ADMIN_NOTIFICATION_EMAIL, 'Admin', '[Admin Copy] ' . $subject, $adminBody);
    }

    if (!empty($remindedIds)) {
        $placeholders = implode(',', array_fill(0, count($remindedIds), '?'));
        $types = str_repeat('i', count($remindedIds));

        $stmt = mysqli_prepare($conn, "UPDATE bookings SET last_reminder_date = CURDATE() WHERE booking_id IN ($placeholders)");
        mysqli_stmt_bind_param($stmt, $types, ...$remindedIds);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    return count($remindedIds);
}
