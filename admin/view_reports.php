<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin('admin');

$validStatuses = ['Pending Payment', 'Advance Paid', 'Completed', 'Cancelled', 'Expired'];
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '' && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

// Date-range filter, both optional. Basic format sanity check only -
// an invalid/garbled date just gets dropped rather than erroring out,
// since this is a GET filter a user could hand-edit in the URL.
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

// Build the WHERE clause and bind params dynamically based on which
// filters are actually set - still entirely through prepared statement
// placeholders, never string-concatenated values, regardless of which
// combination of filters is active.
$conditions = [];
$paramTypes = '';
$paramValues = [];

if ($statusFilter !== '') {
    $conditions[] = 'b.status = ?';
    $paramTypes .= 's';
    $paramValues[] = $statusFilter;
}
if ($dateFrom !== '') {
    $conditions[] = 'b.booking_date >= ?';
    $paramTypes .= 's';
    $paramValues[] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = 'b.booking_date <= ?';
    $paramTypes .= 's';
    $paramValues[] = $dateTo;
}

$whereClause = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

$sql = "SELECT b.booking_id, b.booking_date, b.total_amount, b.amount_paid, b.remaining_amount, b.status,
               u.name AS user_name, c.court_name, ts.start_time, ts.end_time
        FROM bookings b
        JOIN users u ON u.user_id = b.user_id
        JOIN courts c ON c.court_id = b.court_id
        JOIN time_slots ts ON ts.slot_id = b.slot_id
        $whereClause
        ORDER BY b.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);
if (!empty($paramValues)) {
    mysqli_stmt_bind_param($stmt, $paramTypes, ...$paramValues);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$bookings = [];
while ($row = mysqli_fetch_assoc($result)) {
    $bookings[] = $row;
}
mysqli_stmt_close($stmt);

// --- CSV export: same filtered result set, sent as a downloadable file ---
// Runs before any HTML output so the file-download headers stay clean.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="booking_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Customer', 'Court', 'Date', 'Time', 'Total (Rs.)', 'Paid (Rs.)', 'Remaining (Rs.)', 'Status']);

    foreach ($bookings as $booking) {
        $timeLabel = date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time']));
        fputcsv($output, [
            $booking['user_name'],
            $booking['court_name'],
            $booking['booking_date'],
            $timeLabel,
            number_format((float) $booking['total_amount'], 2, '.', ''),
            number_format((float) $booking['amount_paid'], 2, '.', ''),
            number_format((float) $booking['remaining_amount'], 2, '.', ''),
            $booking['status'],
        ]);
    }

    fclose($output);
    exit;
}

// --- Revenue summary (always system-wide, independent of the filters above) ---
$revenueRow = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT
        COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN amount ELSE 0 END), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN payment_status = 'Refunded' THEN refund_amount ELSE 0 END), 0) AS total_refunded
     FROM payments"
));
$totalPaid = (float) $revenueRow['total_paid'];
$totalRefunded = (float) $revenueRow['total_refunded'];
$netRevenue = $totalPaid - $totalRefunded;

// Used to build the CSV export link with the current filters attached,
// so "Download CSV" exports exactly what's currently on screen.
$exportQuery = http_build_query([
    'status'    => $statusFilter,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'export'    => 'csv',
]);

$page_title = 'Reports - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card form-card-wide form-card-table">
        <h1>Booking Reports</h1>

        <div class="summary-cards">
            <div class="summary-card">
                <span class="summary-card-value">Rs. <?php echo number_format($totalPaid, 2); ?></span>
                <span class="summary-card-label">Total Collected</span>
            </div>
            <div class="summary-card">
                <span class="summary-card-value">Rs. <?php echo number_format($totalRefunded, 2); ?></span>
                <span class="summary-card-label">Total Refunded</span>
            </div>
            <div class="summary-card">
                <span class="summary-card-value">Rs. <?php echo number_format($netRevenue, 2); ?></span>
                <span class="summary-card-label">Net Revenue</span>
            </div>
        </div>

        <form method="GET" action="view_reports.php" class="filter-form">
            <div class="form-group">
                <label for="status">Filter by Status</label>
                <select id="status" name="status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <?php foreach ($validStatuses as $status): ?>
                        <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($statusFilter === $status) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($status); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="date_from">From Date</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="form-group">
                <label for="date_to">To Date</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="form-group filter-form-actions">
                <button type="submit" class="btn btn-secondary">Apply Filters</button>
            </div>
        </form>

        <div class="report-toolbar">
            <a href="view_reports.php?<?php echo htmlspecialchars($exportQuery); ?>" class="btn btn-primary btn-small">Download CSV</a>
        </div>

        <?php if (empty($bookings)): ?>
            <p>No bookings match this filter.</p>
        <?php else: ?>
            <div class="table-responsive">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Court</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Remaining</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <?php $statusClass = 'status-badge status-' . strtolower(str_replace(' ', '-', $booking['status'])); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($booking['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($booking['court_name']); ?></td>
                            <td><?php echo htmlspecialchars($booking['booking_date']); ?></td>
                            <td><?php echo date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time'])); ?></td>
                            <td>Rs. <?php echo number_format($booking['total_amount'], 2); ?></td>
                            <td>Rs. <?php echo number_format($booking['amount_paid'], 2); ?></td>
                            <td>Rs. <?php echo number_format($booking['remaining_amount'], 2); ?></td>
                            <td><span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
