<?php
/**
 * cron/run_daily_tasks.php
 * -----------------------------------------------------------------
 * THIS is what a real production deployment would run on a timer -
 * via Windows Task Scheduler or a Linux cron job - completely
 * independent of anyone visiting a page in a browser. It's the
 * proper fix for the limitation of the "opportunistic" pattern used
 * elsewhere in this project (expireOverdueBookings() / 
 * sendDailyReminders() currently only run when a page happens to load).
 *
 * Run it from a command line with:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\futsal_booking_v2\cron\run_daily_tasks.php
 *
 * To actually run this unattended every morning on Windows:
 *   1. Open "Task Scheduler" (search it in the Start menu).
 *   2. Create Basic Task -> name it "Futsal Booking Daily Tasks".
 *   3. Trigger: Daily, at whatever time you want (e.g. 7:00 AM).
 *   4. Action: "Start a program".
 *      Program/script:  C:\xampp\php\php.exe
 *      Add arguments:   C:\xampp\htdocs\futsal_booking_v2\cron\run_daily_tasks.php
 *   5. Finish. NOTE: this only runs if the laptop is ON and XAMPP's
 *      MySQL service is running at that time - Task Scheduler can't
 *      wake a powered-off computer, and no software can. A real
 *      always-on deployment (a VPS or hosting provider with cron
 *      support) is the actual fix for that, since the server there
 *      never gets shut down the way a personal laptop does.
 * -----------------------------------------------------------------
 */

// Guard against this ever being hit through a browser by accident -
// this script is meant to be run via the PHP CLI (command line) only.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script is meant to be run from the command line, not a browser.');
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/expire_bookings.php';
require_once __DIR__ . '/../includes/daily_reminders.php';

echo '[' . date('Y-m-d H:i:s') . "] Running daily tasks...\n";

expireOverdueBookings($conn);
echo "  - Checked for overdue bookings to expire.\n";

$remindersSent = sendDailyReminders($conn);
echo "  - Checked for daily reminders - {$remindersSent} booking(s) matched and were emailed.\n";

echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
