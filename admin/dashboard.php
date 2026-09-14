<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/expire_bookings.php';

requireLogin('admin');

expireOverdueBookings($conn);

// --- Summary cards ---
$totalUsers = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users"))['cnt'];
$totalCourts = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM courts"))['cnt'];

$statusCounts = [];
$result = mysqli_query($conn, "SELECT status, COUNT(*) AS cnt FROM bookings GROUP BY status");
while ($row = mysqli_fetch_assoc($result)) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
}
$allStatuses = ['Pending Payment', 'Advance Paid', 'Completed', 'Cancelled', 'Expired'];
foreach ($allStatuses as $status) {
    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
}

$totalRevenueRow = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE payment_status = 'Paid'"
));
$totalRevenue = (float) $totalRevenueRow['total'];

// --- Recent Activity: last 5 bookings, most-recent-first ---
// ORDER BY created_at DESC LIMIT 5 follows the same last-in-first-out
// principle as a stack: the item that was pushed on most recently
// (the newest booking) is the first one shown/"popped" off the top,
// rather than the oldest one sitting at the bottom.
$recentResult = mysqli_query(
    $conn,
    "SELECT b.booking_id, b.status, b.total_amount, b.created_at,
            u.name AS user_name, c.court_name
     FROM bookings b
     JOIN users u ON u.user_id = b.user_id
     JOIN courts c ON c.court_id = b.court_id
     ORDER BY b.created_at DESC
     LIMIT 5"
);
$recentBookings = [];
while ($row = mysqli_fetch_assoc($recentResult)) {
    $recentBookings[] = $row;
}

// --- Same 24-hour reminder banner, but system-wide instead of per-user ---
$dueSoonResult = mysqli_query(
    $conn,
    "SELECT b.booking_id, b.remaining_amount, b.payment_deadline, c.court_name, u.name AS user_name
     FROM bookings b
     JOIN courts c ON c.court_id = b.court_id
     JOIN users u ON u.user_id = b.user_id
     WHERE b.status = 'Advance Paid'
       AND b.payment_deadline IS NOT NULL
       AND b.payment_deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
     ORDER BY b.payment_deadline ASC"
);
$dueSoon = [];
while ($row = mysqli_fetch_assoc($dueSoonResult)) {
    $dueSoon[] = $row;
}

$page_title = 'Admin Dashboard - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-page">
    <div class="dashboard-welcome">
        <h1>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></h1>
        <p>Here's what's happening across the system.</p>
    </div>

    <?php if (!empty($dueSoon)): ?>
        <div class="alert alert-warning">
            <strong>Reminder - remaining payments due within 24 hours:</strong>
            <?php foreach ($dueSoon as $item): ?>
                <?php echo htmlspecialchars($item['user_name']); ?> -
                <?php echo htmlspecialchars($item['court_name']); ?> -
                Rs. <?php echo number_format($item['remaining_amount'], 2); ?> due
                <?php echo date('M j, g:i A', strtotime($item['payment_deadline'])); ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="summary-cards">
        <div class="summary-card">
            <span class="summary-card-value"><?php echo $totalUsers; ?></span>
            <span class="summary-card-label">Total Users</span>
        </div>
        <div class="summary-card">
            <span class="summary-card-value"><?php echo $totalCourts; ?></span>
            <span class="summary-card-label">Total Courts</span>
        </div>
        <div class="summary-card">
            <span class="summary-card-value">Rs. <?php echo number_format($totalRevenue, 2); ?></span>
            <span class="summary-card-label">Total Revenue</span>
        </div>
    </div>

    <div class="summary-cards summary-cards-secondary">
        <?php foreach ($allStatuses as $status): ?>
            <div class="summary-card summary-card-small">
                <span class="summary-card-value"><?php echo $statusCounts[$status]; ?></span>
                <span class="summary-card-label"><?php echo htmlspecialchars($status); ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="dashboard-panel">
        <h2>Recent Activity</h2>
        <?php if (empty($recentBookings)): ?>
            <p>No bookings yet.</p>
        <?php else: ?>
            <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Court</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $booking): ?>
                        <?php $statusClass = 'status-badge status-' . strtolower(str_replace(' ', '-', $booking['status'])); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($booking['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($booking['court_name']); ?></td>
                            <td>Rs. <?php echo number_format($booking['total_amount'], 2); ?></td>
                            <td><span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                            <td><?php echo date('M j, g:i A', strtotime($booking['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
