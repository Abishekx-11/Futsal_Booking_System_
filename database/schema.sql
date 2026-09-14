-- Futsal Booking System - Database Schema
-- Import this file first in phpMyAdmin (or via `mysql -u root < schema.sql`)

CREATE DATABASE IF NOT EXISTS futsal_booking_system;
USE futsal_booking_system;

-- ---------------------------------------------------------------
-- users : customer accounts
-- ---------------------------------------------------------------
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    reset_code VARCHAR(6) DEFAULT NULL,
    reset_code_expiry DATETIME DEFAULT NULL,
    phone_number VARCHAR(20) NOT NULL,
    profile_picture VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------
-- admins : court admin accounts
-- ---------------------------------------------------------------
CREATE TABLE admins (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- NOTE ON THE SEED ADMIN ACCOUNT:
-- We deliberately do NOT hardcode a bcrypt hash for the admin's password here.
-- After importing this file, open database/seed_admin.php ONCE in your
-- browser (e.g. http://localhost/futsal_booking_v2/database/seed_admin.php).
-- It calls PHP's own password_hash() function to create a guaranteed-correct
-- admin row, then tells you to delete the file.

-- ---------------------------------------------------------------
-- courts
-- ---------------------------------------------------------------
CREATE TABLE courts (
    court_id INT AUTO_INCREMENT PRIMARY KEY,
    court_name VARCHAR(100) NOT NULL,
    price_per_hour DECIMAL(10,2) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO courts (court_name, price_per_hour, description) VALUES
    ('Court A', 1200.00, 'Our premium full-size futsal court, featuring a professional-grade synthetic turf surface and full floodlighting for comfortable evening play. It is enclosed with high rebound boards, making for fast, end-to-end games with fewer stoppages. Court A is our most popular pick for competitive 5-a-side matches and weekend tournaments.'),
    ('Court B', 1000.00, 'A slightly smaller, more relaxed court ideal for casual games, practice sessions, and beginner-friendly play. It has the same quality turf as Court A but at a lower hourly rate, making it a great choice for regular weekday bookings. Court B is well-lit and covered, so games can go ahead rain or shine.');

-- ---------------------------------------------------------------
-- court_images
-- ---------------------------------------------------------------
CREATE TABLE court_images (
    image_id INT AUTO_INCREMENT PRIMARY KEY,
    court_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (court_id) REFERENCES courts(court_id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------
-- time_slots
-- ---------------------------------------------------------------
CREATE TABLE time_slots (
    slot_id INT AUTO_INCREMENT PRIMARY KEY,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL
);

INSERT INTO time_slots (start_time, end_time) VALUES
    ('07:00:00', '08:00:00'),
    ('08:00:00', '09:00:00'),
    ('09:00:00', '10:00:00'),
    ('12:00:00', '13:00:00'),
    ('13:00:00', '14:00:00'),
    ('14:00:00', '15:00:00'),
    ('15:00:00', '16:00:00'),
    ('16:00:00', '17:00:00'),
    ('17:00:00', '18:00:00'),
    ('18:00:00', '19:00:00'),
    ('19:00:00', '20:00:00');

-- ---------------------------------------------------------------
-- bookings
-- ---------------------------------------------------------------
CREATE TABLE bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    court_id INT NOT NULL,
    slot_id INT NOT NULL,
    booking_date DATE NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_deadline DATETIME DEFAULT NULL,
    last_reminder_date DATE DEFAULT NULL,
    status ENUM('Pending Payment','Advance Paid','Completed','Cancelled','Expired') NOT NULL DEFAULT 'Pending Payment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (court_id) REFERENCES courts(court_id) ON DELETE CASCADE,
    FOREIGN KEY (slot_id) REFERENCES time_slots(slot_id) ON DELETE CASCADE,
    -- Critical double-booking protection: the same court cannot have the same
    -- slot on the same date booked twice while a row exists for it.
    UNIQUE KEY unique_active_slot (court_id, slot_id, booking_date)
);

-- ---------------------------------------------------------------
-- payments
-- ---------------------------------------------------------------
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    payment_type ENUM('Advance','Full','Remaining') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payment_status ENUM('Pending','Paid','Refunded') NOT NULL DEFAULT 'Pending',
    refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    transaction_reference VARCHAR(100) DEFAULT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE
);
