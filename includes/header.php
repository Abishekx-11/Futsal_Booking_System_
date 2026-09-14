<?php
/**
 * header.php
 * Shared top of every page: <head>, nav bar.
 *
 * CONVENTION USED ACROSS THE WHOLE PROJECT:
 * Before including this file, the including page must set a $base
 * variable holding the relative path back to the project root:
 *   - pages directly in the root (index.php)        -> $base = '';
 *   - pages one folder deep (auth/, user/, admin/... ) -> $base = '../';
 * This lets one header.php work correctly no matter which folder
 * included it, without hardcoding absolute paths.
 *
 * Also expects db_connect.php to have already been required (for the
 * session), and optionally a $page_title variable.
 */

if (!isset($base)) {
    $base = '';
}
if (!isset($page_title)) {
    $page_title = 'Futsal Booking System';
}

$role = $_SESSION['role'] ?? null;

// Which file is currently being viewed (e.g. "dashboard.php", "manage_courts.php"),
// used below to mark the matching nav link as active. basename() strips
// any folder path and query string variations, so this works the same
// whether the page is at the project root or one folder deep.
$currentPage = basename($_SERVER['PHP_SELF']);

/**
 * Returns ' active' (with a leading space, ready to drop into a class
 * attribute) if $file matches the page currently being viewed, else ''.
 */
function navActive($file, $currentPage) {
    return $file === $currentPage ? ' active' : '';
}

// For a logged-in user, fetch their current profile picture fresh from the
// DB on every page load (rather than caching it in session) so a change
// made on profile.php is reflected in the header avatar immediately,
// without needing to log out and back in.
$headerProfilePicture = null;
if ($role === 'user' && isset($_SESSION['user_id'])) {
    $stmt = mysqli_prepare($conn, "SELECT profile_picture FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    $headerProfilePicture = $row['profile_picture'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title); ?></title>
<link rel="stylesheet" href="<?php echo $base; ?>assets/css/style.css">
</head>
<body>

<header class="site-header">
    <nav class="navbar">
        <a href="<?php echo $base; ?>index.php" class="logo">Futsal<span>Booking</span></a>

        <ul class="nav-links">
            <li><a href="<?php echo $base; ?>index.php" data-nav="home" class="<?php echo trim(navActive('index.php', $currentPage)); ?>">Home</a></li>
            <li><a href="<?php echo $base; ?>index.php#courts" data-nav="courts">Courts</a></li>
            <li><a href="<?php echo $base; ?>index.php#about" data-nav="about">About</a></li>

            <?php if ($role === 'user'): ?>
                <li><a href="<?php echo $base; ?>user/dashboard.php" class="<?php echo trim(navActive('dashboard.php', $currentPage)); ?>">Dashboard</a></li>
                <li><a href="<?php echo $base; ?>user/book_court.php" class="<?php echo trim(navActive('book_court.php', $currentPage)); ?>">Book a Court</a></li>
                <li><a href="<?php echo $base; ?>user/booking_history.php" class="<?php echo trim(navActive('booking_history.php', $currentPage)); ?>">My Bookings</a></li>
                <li><a href="<?php echo $base; ?>user/gallery.php" class="<?php echo trim(navActive('gallery.php', $currentPage)); ?>">Gallery</a></li>
                <li><a href="<?php echo $base; ?>auth/logout.php">Logout</a></li>
                <li class="nav-avatar">
                    <a href="<?php echo $base; ?>user/profile.php" title="My Profile" class="<?php echo trim(navActive('profile.php', $currentPage)); ?>">
                        <?php if ($headerProfilePicture): ?>
                            <img src="<?php echo $base . htmlspecialchars($headerProfilePicture); ?>" alt="Profile" class="avatar-img">
                        <?php else: ?>
                            <span class="avatar-placeholder">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                                    <path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.2c-3.3 0-9.8 1.6-9.8 4.9v2.7h19.6v-2.7c0-3.3-6.5-4.9-9.8-4.9z"/>
                                </svg>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php elseif ($role === 'admin'): ?>
                <li><a href="<?php echo $base; ?>admin/dashboard.php" class="<?php echo trim(navActive('dashboard.php', $currentPage)); ?>">Admin Dashboard</a></li>
                <li><a href="<?php echo $base; ?>admin/manage_courts.php" class="<?php echo trim(navActive('manage_courts.php', $currentPage)); ?>">Manage Courts</a></li>
                <li><a href="<?php echo $base; ?>admin/manage_court_images.php" class="<?php echo trim(navActive('manage_court_images.php', $currentPage)); ?>">Manage Images</a></li>
                <li><a href="<?php echo $base; ?>admin/view_reports.php" class="<?php echo trim(navActive('view_reports.php', $currentPage)); ?>">Reports</a></li>
                <li><a href="<?php echo $base; ?>auth/logout.php">Logout</a></li>
            <?php endif; ?>
            <!-- Logged-out visitors don't get Login/Sign Up links here - those
                 live in the "I'm a Customer" / "Court Admin" cards on the
                 homepage instead, so they aren't duplicated in the nav. -->
        </ul>
    </nav>
</header>

<main class="site-main">
