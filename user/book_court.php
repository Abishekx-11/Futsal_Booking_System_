<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/expire_bookings.php';

requireLogin('user');

// Opportunistic cleanup: flip any overdue "Advance Paid" bookings to
// "Expired" before we check what's available, so a stale Advance Paid
// row doesn't wrongly block a slot that should now be free again.
expireOverdueBookings($conn);

// --- Date bounds: today .. today + 1 month, both client-side and here ---
$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+1 month'));

// Pre-fill support: payment_selection.php's "Back" link can send us
// back here with the same court/date already selected.
$selectedCourtId = isset($_GET['court_id']) ? (int) $_GET['court_id'] : 0;
$selectedDate    = $_GET['booking_date'] ?? $minDate;

// Re-validate the incoming date is actually within range; if not, fall back.
if ($selectedDate < $minDate || $selectedDate > $maxDate) {
    $selectedDate = $minDate;
}

// --- Load all courts for the dropdown ---
$courtsResult = mysqli_query($conn, "SELECT court_id, court_name, price_per_hour, description FROM courts ORDER BY court_name");
$courts = [];
while ($row = mysqli_fetch_assoc($courtsResult)) {
    $courts[] = $row;
}

// If nothing selected yet, default to the first court so slots show immediately.
if ($selectedCourtId === 0 && !empty($courts)) {
    $selectedCourtId = (int) $courts[0]['court_id'];
}

// --- Load all 11 time slots ---
$slotsResult = mysqli_query($conn, "SELECT slot_id, start_time, end_time FROM time_slots ORDER BY start_time");
$slots = [];
while ($row = mysqli_fetch_assoc($slotsResult)) {
    $slots[] = $row;
}

// --- Which slots are already taken for this court/date? ---
// A slot is unavailable if a booking exists for it with a status that
// still holds the slot: Pending Payment, Advance Paid, or Completed.
$bookedSlotIds = [];
if ($selectedCourtId > 0) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT slot_id FROM bookings
         WHERE court_id = ? AND booking_date = ?
           AND status IN ('Pending Payment', 'Advance Paid', 'Completed')"
    );
    mysqli_stmt_bind_param($stmt, "is", $selectedCourtId, $selectedDate);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $bookedSlotIds[] = (int) $row['slot_id'];
    }
    mysqli_stmt_close($stmt);
}

// Helper to format a TIME value like "17:00:00" as "5:00 PM".
function formatSlotTime($time) {
    return date('g:i A', strtotime($time));
}

// A slot on TODAY'S date whose start time has already passed can't be
// booked, even though the date itself is still within the allowed
// range - "today" spans a whole day, but only the part of it that
// hasn't happened yet is actually bookable.
$isToday = ($selectedDate === date('Y-m-d'));
$nowTime = date('H:i:s');

// A validation error from confirm_booking.php (e.g. slot taken in the meantime)
// travels back here via session so it survives the redirect.
$bookingError = $_SESSION['booking_error'] ?? '';
unset($_SESSION['booking_error']);

$page_title = 'Book a Court - Futsal Booking System';
$base = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="form-page">
    <div class="form-card form-card-wide">
        <h1>Book a Court</h1>
        <p class="form-subtitle">Choose a court, a date, and an available time slot.</p>

        <?php if ($bookingError): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($bookingError); ?></div>
        <?php endif; ?>

        <!-- Changing court or date reloads this page (GET) to refresh slot availability -->
        <form method="GET" action="book_court.php" id="filterForm" class="filter-form">
            <div class="form-group">
                <label for="court_id">Court</label>
                <select id="court_id" name="court_id" onchange="document.getElementById('filterForm').submit()">
                    <?php foreach ($courts as $court): ?>
                        <option value="<?php echo (int) $court['court_id']; ?>"
                            <?php echo ($court['court_id'] == $selectedCourtId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($court['court_name']); ?>
                            (Rs. <?php echo number_format($court['price_per_hour'], 2); ?>/hr)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="booking_date">Date</label>
                <input type="date" id="booking_date" name="booking_date"
                       min="<?php echo $minDate; ?>" max="<?php echo $maxDate; ?>"
                       value="<?php echo htmlspecialchars($selectedDate); ?>"
                       onchange="document.getElementById('filterForm').submit()">
            </div>
        </form>

        <?php
            // Find the currently selected court's description to show below the filters.
            $selectedCourtDescription = '';
            foreach ($courts as $court) {
                if ((int) $court['court_id'] === $selectedCourtId) {
                    $selectedCourtDescription = $court['description'] ?? '';
                    break;
                }
            }
        ?>
        <?php if ($selectedCourtDescription): ?>
            <p class="court-description-box"><?php echo htmlspecialchars($selectedCourtDescription); ?></p>
        <?php endif; ?>

        <?php if (empty($courts)): ?>
            <div class="alert alert-error">No courts are available right now.</div>
        <?php else: ?>
            <form method="POST" action="confirm_booking.php" id="slotForm">
                <input type="hidden" name="court_id" value="<?php echo (int) $selectedCourtId; ?>">
                <input type="hidden" name="booking_date" value="<?php echo htmlspecialchars($selectedDate); ?>">

                <fieldset class="slot-list">
                    <legend>Available Time Slots</legend>

                    <div class="slot-grid">
                        <?php foreach ($slots as $slot): ?>
                            <?php
                                $isPast = $isToday && ($slot['start_time'] <= $nowTime);
                                $isBooked = in_array((int) $slot['slot_id'], $bookedSlotIds, true);
                                $isUnavailable = $isBooked || $isPast;
                                $slotLabel = formatSlotTime($slot['start_time']) . ' - ' . formatSlotTime($slot['end_time']);
                            ?>
                            <label class="slot-card <?php echo $isUnavailable ? 'slot-card-disabled' : ''; ?>">
                                <input type="radio" name="slot_id" value="<?php echo (int) $slot['slot_id']; ?>"
                                    <?php echo $isUnavailable ? 'disabled' : ''; ?> required>
                                <span class="slot-card-time"><?php echo $slotLabel; ?></span>
                                <span class="slot-card-status">
                                    <?php echo $isPast ? 'Past' : ($isBooked ? 'Booked' : 'Available'); ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <p id="slotHint" class="slot-hint">Select a time slot above to continue.</p>
                <button type="submit" class="btn btn-primary btn-block">Continue</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
// Purely cosmetic: highlight the clicked slot card and update the hint
// text. The radio input itself (not this script) is what actually gets
// submitted, so the form still works fine even with JS disabled.
document.querySelectorAll('.slot-card:not(.slot-card-disabled)').forEach(function (card) {
    card.addEventListener('click', function () {
        document.querySelectorAll('.slot-card').forEach(function (c) {
            c.classList.remove('slot-card-selected');
        });
        card.classList.add('slot-card-selected');

        var timeText = card.querySelector('.slot-card-time').textContent;
        document.getElementById('slotHint').textContent = 'Selected: ' + timeText;
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
