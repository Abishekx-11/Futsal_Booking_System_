<?php
/**
 * db_connect.php
 * Opens the single mysqli connection used across the whole site.
 * Every page that needs the database should require_once this file.
 */

// Without this, PHP silently defaults to UTC (or the server OS's
// default) instead of Nepal time - every date()/time()/strtotime()
// call in the project (slot availability, 24-hour payment deadlines,
// booking timestamps) would then be off by Nepal's UTC+5:45 offset.
// Setting it here, once, applies it to every page that includes this
// file - which is every page in the project.
date_default_timezone_set('Asia/Kathmandu');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';          // default XAMPP MySQL password is empty
$db_name = 'futsal_booking_system';

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

// Make sure PHP and MySQL agree on character encoding.
mysqli_set_charset($conn, 'utf8mb4');

// Every page that includes this file needs session access,
// so start the session here in one central place.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


